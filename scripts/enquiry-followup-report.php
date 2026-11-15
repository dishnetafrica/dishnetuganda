<?php
declare(strict_types=1);

/**
 * enquiry-followup-report.php — READ-ONLY: why Uganda's enquiry follow-ups pile up (15 Nov 2026; root docs/07).
 *
 * Run by scripts/enquiry-followup-report.sh inside the uCRM container, as the book's owner, on a COPY of the book
 * (VACUUM INTO) with copies of the settings cut to the keys it reads. It opens that copy read-only (SQLITE_OPEN_READONLY
 * and query_only), writes nothing anywhere, and prints COUNTS ONLY: no phone number, no name, no message text, no
 * Evolution instance — a number is named by its registry id (sales, support, account, sales-001 …) and its kind
 * (department or seller), and anything else that could carry a person's data is counted, never printed.
 *
 *   php enquiry-followup-report.php --db=<copy.sqlite3> --root=<a code root holding lib/> --data=<the settings copies>
 *                                    [--now='YYYY-MM-DD HH:MM:SS' (UTC; tests only)]
 *
 * What it reads, each the way the live 5.18.104 code (994d660) reads it:
 *   - the follow-up jobs' settings (PluginConfig: the files, then the vault), each key with the job's own expression —
 *     followup_enabled by empty(), the switches by FILTER_VALIDATE_BOOLEAN, numbers by (int)(value ?? default), the
 *     send ledger by === 'on' — and the book's own settings row (kyc_config), which master and the Follow-ups screen read;
 *   - master's schedule (master_schedule, the flat row): when each follow-up job last ran and whether it finished;
 *   - wa_conversations: quiet conversations that have had no follow-up since the customer's last message, and why;
 *   - followups (enquiry rows), followup_drafts, followup_sends, followup_events, wa_channels, and the send ledger
 *     (wa_auto_sends) where it exists.
 * The run's front is read as FollowUpService::due() reads it (open enquiry rows, due_at <= now, ORDER BY due_at, LIMIT
 * followup_run_limit): a row there whose draft waits for a person is skipped without moving, so when the run's every
 * place holds one, followup_run.php judges no other follow-up. The Follow-ups screen lists pendingDrafts(50): the 50
 * oldest waiting drafts of every kind, whether or not their follow-up is still open — and only a person's Reject clears a
 * waiting draft whose follow-up has closed.
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$opt = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--(db|root|data|now)=(.*)$/s', $a, $m)) $opt[$m[1]] = $m[2];
    else { fwrite(STDERR, "unknown argument\n"); exit(2); }
}
foreach (['db', 'root', 'data'] as $k) if (!isset($opt[$k]) || $opt[$k] === '') { fwrite(STDERR, "--$k is required\n"); exit(2); }
if (!is_file($opt['db'])) { fwrite(STDERR, "no copy at --db\n"); exit(2); }
if (!is_file($opt['root'] . '/lib/PluginConfig.php')) { fwrite(STDERR, "no lib/PluginConfig.php under --root\n"); exit(2); }

$nowTs = isset($opt['now']) ? strtotime($opt['now'] . ' UTC') : time();
if ($nowTs === false) { fwrite(STDERR, "--now is not a time\n"); exit(2); }
$now = gmdate('Y-m-d H:i:s', $nowTs);

// ── the copy, read-only ─────────────────────────────────────────────────────────────────────────────────────────
try {
    $db = new PDO('sqlite:' . $opt['db'], null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY,
    ]);
    $db->exec('PRAGMA query_only = 1');
} catch (Throwable $e) {
    fwrite(STDERR, "the copy could not be opened read-only\n"); exit(2);
}

$out = [];
$say = function (string $s) use (&$out): void { $out[] = $s; };
$q1 = function (string $sql, array $a = []) use ($db) { $st = $db->prepare($sql); $st->execute($a); return $st->fetchColumn(); };
$qa = function (string $sql, array $a = []) use ($db): array { $st = $db->prepare($sql); $st->execute($a); return $st->fetchAll(); };
$has = function (string $t) use ($db): bool {
    $st = $db->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ?"); $st->execute([$t]); return $st->fetchColumn() !== false;
};
$cols = function (string $t) use ($db): array {
    $o = []; foreach ($db->query('PRAGMA table_info(' . $t . ')')->fetchAll() as $c) $o[] = (string)$c['name']; return $o;
};
// a word the code wrote (an event, a close reason, a gate): printed only when it is one; otherwise counted as "other"
$word = function ($s): string { $s = (string)$s; return preg_match('/^[a-z][a-z0-9_.:\/-]{0,40}$/', $s) ? $s : 'other'; };
$ago = function (?string $t) use ($nowTs): string {
    if ($t === null || $t === '') return 'never';
    $ts = strtotime($t . ' UTC'); if ($ts === false) return 'unreadable';
    $s = $nowTs - $ts; if ($s < 0) return 'in the future';
    if ($s < 5400) return (int)round($s / 60) . ' min ago';
    if ($s < 172800) return round($s / 3600, 1) . ' h ago';
    return round($s / 86400, 1) . ' days ago';
};
$joinMap = function (array $m): string { $p = []; foreach ($m as $k => $c) $p[] = "$k $c"; return $p === [] ? 'none' : implode(' · ', $p); };

foreach (['followups', 'followup_drafts', 'followup_sends', 'followup_events'] as $t) {
    if (!$has($t)) { echo "  STOP the copy has no $t table — not a book with enquiry follow-ups\n"; exit(3); }
}
$kindCol = in_array('kind', $cols('followups'), true);
$ENQ = $kindCol ? " AND COALESCE(f.kind,'enquiry')='enquiry'" : '';
$ENQu = $kindCol ? " AND COALESCE(kind,'enquiry')='enquiry'" : '';

// the numbers: registry id (generic) and kind; never an instance, a display name or an owner
$chanKind = []; $chanOwner = []; $chanStatus = []; $handsetChan = [];
if ($has('wa_channels')) {
    foreach ($qa('SELECT channel_id, evo_instance, owner_type, status FROM wa_channels ORDER BY channel_id') as $r) {
        $id = (string)$r['channel_id'];
        $chanOwner[$id] = (string)$r['owner_type'];
        $chanStatus[$id] = (string)$r['status'];
        $chanKind[$id] = ((string)$r['owner_type'] === 'staff' ? 'seller' : ((string)$r['owner_type'] === 'partner' ? 'partner' : 'dept'))
            . ((string)$r['status'] !== 'active' ? ', ' . $word($r['status']) : '');
        $inst = strtolower(trim((string)($r['evo_instance'] ?? '')));
        if (strpos($inst, 'i:') === 0) $inst = trim(substr($inst, 2));
        if ($inst !== '') $handsetChan['i:' . $inst][] = $id;   // a handset is matched here, never printed
    }
}
$label = function ($ch) use ($chanKind): string {
    $ch = (string)$ch;
    if (!preg_match('/^(sales|support|account|retailer)(-[0-9]{1,4})?$/', $ch)) return 'other';
    return $ch . (isset($chanKind[$ch]) ? ' (' . $chanKind[$ch] . ')' : '');
};
$byLabel = function (array $rows, string $key = 'channel', string $n = 'n') use ($label, $joinMap): string {
    $m = [];
    foreach ($rows as $r) { $l = $label($r[$key]); $m[$l] = ($m[$l] ?? 0) + (int)$r[$n]; }
    ksort($m);
    return $joinMap($m);
};
$bucket = function (string $col, array $edges) use ($now): string {
    // "<col>" is a UTC text; age in hours, buckets by the upper edges given (hours)
    $parts = []; $lo = null;
    foreach ($edges as $name => $hi) {
        $c = "(julianday('$now') - julianday($col)) * 24";
        $parts[] = "SUM(CASE WHEN $c " . ($lo === null ? '>= 0' : ">= $lo") . ($hi === null ? '' : " AND $c < $hi") . " THEN 1 ELSE 0 END) AS \"$name\"";
        $lo = $hi;
    }
    return implode(', ', $parts);
};
$fmt = function (array $row): string { $p = []; foreach ($row as $k => $v) $p[] = $k . ':' . (int)$v; return implode(' ', $p); };
// the assistant's DO_NOT_SEND reasons that are FollowUpEvaluator::refuse()'s own (994d660) — matched, never printed
$aiClass = "CASE WHEN d.reason LIKE 'the assistant could not be reached:%' THEN 'ai_unreachable'
                 WHEN d.reason = 'the assistant returned nothing' THEN 'ai_no_answer'
                 WHEN d.reason IN ('the assistant did not return JSON', 'the assistant''s JSON could not be read',
                                   'the assistant chose to send but wrote no message')
                      OR d.reason LIKE 'unrecognised verdict \"%' THEN 'ai_bad_answer'
                 WHEN d.reason = 'the identity behind this number is not certain enough to write to' THEN 'identity_uncertain'
                 ELSE 'declined' END";
$aiFail = "(d.reason LIKE 'the assistant could not be reached:%' OR d.reason = 'the assistant returned nothing'
            OR d.reason IN ('the assistant did not return JSON', 'the assistant''s JSON could not be read',
                            'the assistant chose to send but wrote no message') OR d.reason LIKE 'unrecognised verdict \"%')";

// ── A. settings, as each job reads them ─────────────────────────────────────────────────────────────────────────
require_once $opt['root'] . '/lib/PluginConfig.php';
$cfg = PluginConfig::read($opt['root'], $opt['data']);
$bool = function (string $k) use ($cfg): bool { return filter_var($cfg[$k] ?? false, FILTER_VALIDATE_BOOLEAN); };
$num = function (string $k, $default) use ($cfg) { return $cfg[$k] ?? $default; };   // the jobs' (int)($config[k] ?? d)
$mark = function (string $k) use ($cfg): string {
    if (!array_key_exists($k, $cfg) || $cfg[$k] === null) return ' (default)';
    return (is_string($cfg[$k]) && trim($cfg[$k]) === '') ? ' (set but empty: the job reads 0)' : '';
};
$jobsRun = !empty($cfg['followup_enabled'] ?? null);                     // followup_scan/run/close/send: empty()
$enWord = is_string($cfg['followup_enabled'] ?? null) ? strtolower(trim((string)$cfg['followup_enabled'])) : '';
$autoSend = $bool('followup_auto_send');                                 // FollowUpPolicy::mayAutoSend
$ownedAllowed = $bool('wa_followups_on_owned_numbers');                  // OwnedNumberHold::FLAG
$registry = $bool('multi_number_channels_enabled');                      // ChannelRegistry::FLAG (Uganda)
$heldOwned = $registry && !$ownedAllowed && $has('wa_channels');
$cap = (int)$num('followup_daily_cap', 30);
$runLimit = (int)$num('followup_run_limit', 5);       // SQLite: LIMIT 0 takes nothing, a negative LIMIT takes every row
$unlimited = $runLimit < 0;
$scanLimit = (int)$num('followup_scan_limit', 25);
$maxAge = (float)$num('followup_max_age_hours', 336);
$notBefore = trim((string)($cfg['followup_not_before'] ?? ''));
$ledgerOn = (string)($cfg['wa_send_ledger'] ?? '') === 'on';             // WaSendBudget::on (Uganda)
$claims = false;                                                         // WaSendBudget::enquiryClaims
foreach (['quote_chase_enabled', 'offers_test_enabled', 'offers_live_enabled'] as $k) {
    if (in_array(strtolower(trim((string)($cfg[$k] ?? ''))), ['1', 'true', 'on', 'yes'], true)) $claims = true;
}
$ledgerMode = !$ledgerOn ? 'off' : ($claims ? 'claim' : 'record');
$lset = function (string $k, int $default) use ($cfg): int {             // WaSendBudget::setting
    $raw = $cfg[$k] ?? null;
    if ($raw === null || (is_string($raw) && trim($raw) === '')) return $default;
    if (is_int($raw)) return $raw;
    if (is_string($raw) && preg_match('/^\s*\d{1,6}\s*$/', $raw)) return (int)trim($raw);
    return 0;
};
$ledgerDayCap = $lset('wa_send_daily_cap', 60);
$ledgerHourCap = $lset('wa_send_hourly_cap', 15);
$ledgerFrom = $lset('wa_send_hour_from', 8);
$ledgerTo = $lset('wa_send_hour_to', 20);
// the book's own settings row: master reads master_budget_seconds there, the Follow-ups screen followup_enabled
$book = null;
if ($has('kyc_config')) {
    $raw = $q1('SELECT data FROM kyc_config WHERE id = 0');
    if (is_string($raw)) { $d = json_decode($raw, true); if (is_array($d)) $book = $d; }
}
$budget = (int)(($book ?? [])['master_budget_seconds'] ?? 300);
$bookOn = $book !== null && !empty($book['followup_enabled'] ?? null);
// the sending window, as FollowUpPolicy::withinSendingWindow reads it: 08:00-20:00 Kampala, never a Sunday
$kla = new DateTimeImmutable('@' . $nowTs); $kla = $kla->setTimezone(new DateTimeZone('Africa/Kampala'));
$klaH = (int)$kla->format('G'); $klaDow = (int)$kla->format('w');
$inWindow = $klaDow !== 0 && $klaH >= 8 && $klaH < 20;
$inLedgerWindow = $klaDow !== 0 && $klaH >= $ledgerFrom && $klaH < $ledgerTo;          // WaSendBudget::inWindow
if ($ledgerMode === 'claim') $inWindow = $inWindow && $inLedgerWindow;
$windowSay = 'now ' . $kla->format('D H:i') . ' Kampala: ' . ($inWindow ? 'INSIDE' : 'OUTSIDE') . ' the sending window (08:00-20:00, Mon-Sat'
    . ($ledgerMode === 'claim' ? sprintf('; while the ledger claims, also its own %02d:00-%02d:00', $ledgerFrom, $ledgerTo) : '') . ')'
    . ($inWindow ? '' : ' — every approved follow-up waits for it');
// followup_not_before as followup_scan.php applies it: any non-empty value compared AS TEXT with the max-age bound, the
// later of the two being the floor; a value that is not a date (it starts with a letter, or uses '/') compares later than
// every time stamp, so the scan then opens nothing at all
$ageFloor = gmdate('Y-m-d H:i:s', $nowTs - (int)round($maxAge * 3600));
$floorEff = ($notBefore !== '' && $notBefore > $ageFloor) ? $notBefore : null;
$floorIsDate = (bool)preg_match('/^\d{4}-\d{2}-\d{2}[ T]?\d{0,2}:?\d{0,2}:?\d{0,2}$/', $notBefore);

$say('== A. The settings, as each follow-up job reads them ==');
$say('  followup_enabled: the four follow-up jobs ' . ($jobsRun ? 'RUN' : 'DO NOT RUN')
    . ($jobsRun && in_array($enWord, ['off', 'false', 'no'], true) ? ' (it is stored as a word that looks like off, which the jobs read as on)' : '')
    . ' · followup_auto_send ' . ($autoSend ? 'on' : 'off') . ' — a SEND draft is approved '
    . ($autoSend ? 'by the run itself when the customer is enquiry-level, the message carries no escalation word, no person is awaited on the chat and the number may send (otherwise a person decides)' : 'only by a person'));
$say('  salesperson numbers: ' . (!$registry ? 'the channel registry is off (multi_number_channels_enabled), so nothing is held'
    : ($ownedAllowed ? 'followed up (wa_followups_on_owned_numbers on)'
                     : 'HELD — wa_followups_on_owned_numbers is off, so the scan never opens a follow-up on a salesperson\'s own number')));
$say("  followup_daily_cap $cap" . $mark('followup_daily_cap') . ' — ' . ($cap > 0 ? 'once this many were SENT on a number today (UTC) the run judges none of its follow-ups (it puts each back for the next run); the sender itself has no cap' : 'no cap')
    . " · followup_run_limit $runLimit" . $mark('followup_run_limit') . ' — ' . ($unlimited ? 'below 0: the run takes EVERY due follow-up each pass (every 10 min)' : ($runLimit === 0 ? 'the run takes NO follow-up' : 'follow-ups judged per run (every 10 min)')));
$say("  followup_scan_limit $scanLimit" . $mark('followup_scan_limit') . ' — opened per scan (every 10 min) · followup_max_age_hours ' . (0 + $maxAge) . $mark('followup_max_age_hours')
    . ' — older quiet conversations are never opened · followup_not_before '
    . ($floorIsDate ? $notBefore : ($notBefore === '' ? 'unset' : 'set (unreadable)'))
    . ($floorEff !== null && !$floorIsDate ? ' — the scan compares it as text, so it opens NO conversation at all' : ''));
$say('  the send ledger: ' . ($ledgerMode === 'off' ? 'off — enquiry sends are neither recorded nor paced'
    : ($ledgerMode === 'record' ? 'on, RECORD — each enquiry send is written after it goes; not paced'
    : "on, CLAIM — each enquiry send asks first: per number at most $ledgerDayCap proactive automated messages a Kampala day (quotes and staff lists reserve their share first) and $ledgerHourCap an hour, spaced, one per customer a day, inside " . sprintf('%02d:00-%02d:00', $ledgerFrom, $ledgerTo))));
$say('  the book\'s own settings row (master and the Follow-ups screen read it): '
    . ($book === null ? 'not readable — master uses master_budget_seconds 300'
       : "master_budget_seconds $budget" . (array_key_exists('master_budget_seconds', $book) ? '' : ' (default)')
         . ' · followup_enabled ' . ($bookOn ? 'on' : 'off') . ($bookOn !== $jobsRun ? ' — DIFFERS from what the jobs read: the screen says follow-ups are ' . ($bookOn ? 'on' : 'off') : '')));

// ── B. the jobs: when each last ran ─────────────────────────────────────────────────────────────────────────────
$say('');
$say('== B. The follow-up jobs in master\'s schedule (UTC) ==');
$sched = null;
if ($has('master_schedule')) {
    $raw = $q1('SELECT data FROM master_schedule WHERE id = 0');
    if (is_string($raw)) { $d = json_decode($raw, true); if (is_array($d)) $sched = $d; }
}
if ($sched === null) {
    $say('  master_schedule could not be read on the copy — NOT ESTABLISHED');
} else {
    $newest = 0; $unfinished = 0;
    foreach ($sched as $job => $r) {
        if (!is_array($r)) continue;
        $newest = max($newest, (int)($r['last_run'] ?? 0));
        if ((int)($r['duration_ms'] ?? 0) === -1) $unfinished++;
    }
    $say('  master: the newest job start ' . ($newest > 0 ? $ago(gmdate('Y-m-d H:i:s', $newest)) : 'never') . " · jobs whose last start did not finish (duration -1): $unfinished · budget $budget s a cycle");
    foreach (['followup_close', 'followup_scan', 'followup_run', 'followup_send'] as $job) {
        $r = is_array($sched[$job] ?? null) ? $sched[$job] : null;
        if ($r === null) { $say("  $job: no row — never run"); continue; }
        $lr = (int)($r['last_run'] ?? 0); $dur = (int)($r['duration_ms'] ?? 0);
        $say("  $job: last start " . ($lr > 0 ? $ago(gmdate('Y-m-d H:i:s', $lr)) : 'never') . ' · ' . ($dur === -1 ? 'DID NOT FINISH' : "took {$dur} ms"));
    }
}

// ── C. quiet conversations that never got a follow-up ───────────────────────────────────────────────────────────
$say('');
$say('== C. Quiet conversations with no open follow-up (the customer\'s last message 1-30 days ago), as the scan sees them ==');
$never = ['total' => 0];
if (!$has('wa_conversations')) {
    $say('  the copy has no wa_conversations table — NOT ESTABLISHED');
} else {
    $cc = $cols('wa_conversations');
    $cat = in_array('category', $cc, true) ? "LOWER(TRIM(COALESCE(c.category, '')))" : "''";
    $stt = in_array('state', $cc, true) ? "COALESCE(c.state, '')" : "''";
    $sts = in_array('status', $cc, true) ? "COALESCE(c.status, 'active')" : "'active'";
    $floorSql = $floorEff !== null ? $db->quote($floorEff) : 'NULL';
    $sellers = []; foreach ($chanOwner as $id => $o) if ($o === 'staff') $sellers[] = $db->quote($id);
    $sellerSql = ($heldOwned && $sellers !== []) ? 'c.channel IN (' . implode(',', $sellers) . ')' : '0';
    // the latest ENQUIRY follow-up closed during this quiet spell (opened after the customer's last message): the scan
    // does not look at it and opens a new one — unless the customer opted out (checked on that follow-up's own phone)
    $prev = "(SELECT f.id FROM followups f WHERE f.conversation_id = c.id AND f.closed_at IS NOT NULL AND f.opened_at >= c.last_customer_at$ENQ ORDER BY f.id DESC LIMIT 1)";
    $optSql = $has('contact_optouts')
        ? "EXISTS (SELECT 1 FROM contact_optouts o JOIN followups pf ON pf.id = $prev WHERE o.active = 1 AND o.phone = pf.phone AND (o.channel = '*' OR o.channel = c.channel))" : '0';
    $quoteSql = $kindCol ? "EXISTS (SELECT 1 FROM followups q WHERE q.kind = 'quote' AND q.phone = c.phone AND (q.closed_at IS NULL OR q.closed_at > c.last_customer_at))" : '0';
    $why = "CASE WHEN $cat = 'staff' THEN 'colleague'
                 WHEN $sts <> 'active' THEN 'not_active'
                 WHEN $stt = 'human_active' THEN 'person_has_it'
                 WHEN $sellerSql THEN 'salesperson_number_held'
                 WHEN c.last_customer_at < " . $db->quote($ageFloor) . " THEN 'older_than_max_age'
                 WHEN $floorSql IS NOT NULL AND c.last_customer_at < $floorSql THEN 'before_not_before'
                 WHEN $quoteSql THEN 'quotation_chased'
                 WHEN $prev IS NOT NULL AND $optSql THEN 'opted_out'
                 WHEN $prev IS NOT NULL THEN 'reopened_each_scan'
                 ELSE 'eligible_not_opened' END";
    // followup_scan.php's own rule: every conversation with no OPEN follow-up is a candidate
    $base = "FROM wa_conversations c
             WHERE c.last_customer_at IS NOT NULL AND c.last_customer_at <= datetime(?, '-24 hours') AND c.last_customer_at >= datetime(?, '-30 days')
               AND NOT EXISTS (SELECT 1 FROM followups f WHERE f.conversation_id = c.id AND f.closed_at IS NULL)";
    $order = ['eligible_not_opened', 'reopened_each_scan', 'salesperson_number_held', 'before_not_before', 'older_than_max_age', 'quotation_chased', 'opted_out', 'person_has_it', 'not_active', 'colleague'];
    $byWhy = array_fill_keys($order, 0);
    foreach ($qa("SELECT $why AS why, COUNT(*) n $base GROUP BY why", [$now, $now]) as $x) {
        $w = (string)$x['why']; if (isset($byWhy[$w])) $byWhy[$w] += (int)$x['n']; $never['total'] += (int)$x['n'];
    }
    $never += $byWhy;
    $say('  ' . $never['total'] . ' in all — ' . $joinMap(array_filter($byWhy)));
    $say('  eligible but not opened, by number: ' . $byLabel($qa("SELECT c.channel, COUNT(*) n $base AND ($why) = 'eligible_not_opened' GROUP BY c.channel", [$now, $now])));
    $r = $qa("SELECT " . $bucket('c.last_customer_at', ['1-3d' => 72, '3-7d' => 168, '7-14d' => 336, '14-30d' => null]) . " $base AND ($why) = 'eligible_not_opened'", [$now, $now])[0];
    $say('  eligible but not opened, by time since the customer\'s last message: ' . $fmt($r));
    $r = $qa("SELECT (SELECT f.close_reason FROM followups f WHERE f.id = $prev) AS cr, COUNT(*) n $base AND ($why) = 'reopened_each_scan' GROUP BY cr ORDER BY n DESC, cr", [$now, $now]);
    $m = []; foreach ($r as $x) { $w = $word($x['cr']); $m[$w] = ($m[$w] ?? 0) + (int)$x['n']; }
    $say('  followed up and closed during this quiet spell, so the scan opens them AGAIN (due at once, at the front of the run): by how the last one closed: ' . $joinMap($m));
    if ($heldOwned) {
        $say('  held on a salesperson\'s own number, by number: ' . $byLabel($qa("SELECT c.channel, COUNT(*) n $base AND ($why) = 'salesperson_number_held' GROUP BY c.channel", [$now, $now])));
    }
    $say('  (by these rules; the scan also leaves out an opted-out customer never followed up before, a colleague\'s or DishNet\'s own number, a number that may send nothing automated, and several customers on one number)');
}

// ── D. the open enquiry follow-ups ──────────────────────────────────────────────────────────────────────────────
$say('');
$say('== D. Open enquiry follow-ups ==');
$open = (int)$q1("SELECT COUNT(*) FROM followups WHERE closed_at IS NULL$ENQu");
$dueNow = (int)$q1("SELECT COUNT(*) FROM followups WHERE closed_at IS NULL AND due_at IS NOT NULL AND due_at <= ?$ENQu", [$now]);
$say("  open $open · due now $dueNow · not yet due " . ($open - $dueNow));
$say('  open by number: ' . $byLabel($qa("SELECT channel, COUNT(*) n FROM followups WHERE closed_at IS NULL$ENQu GROUP BY channel")));
$r = $qa("SELECT " . $bucket('opened_at', ['<1d' => 24, '1-3d' => 72, '3-7d' => 168, '>7d' => null]) . " FROM followups WHERE closed_at IS NULL$ENQu")[0];
$say('  open, by time since opened: ' . $fmt($r));
$r = $qa("SELECT " . $bucket('last_customer_at', ['<3d' => 72, '3-7d' => 168, '7-14d' => 336, '>14d' => null]) . " FROM followups WHERE closed_at IS NULL$ENQu")[0];
$say('  open, by time since the customer\'s last message: ' . $fmt($r));
$r = $qa("SELECT " . $bucket('due_at', ['<1h' => 1, '1h-1d' => 24, '1-3d' => 72, '>3d' => null]) . " FROM followups WHERE closed_at IS NULL AND due_at IS NOT NULL AND due_at <= ?$ENQu", [$now])[0];
$say('  due now, by time since due_at was last set (the run moves it overnight, at the cap and on a WAIT — not the customer\'s wait): ' . $fmt($r));
$r = $qa("SELECT attempts, COUNT(*) n FROM followups WHERE closed_at IS NULL$ENQu GROUP BY attempts ORDER BY attempts");
$p = []; foreach ($r as $x) $p[] = 'attempts ' . (int)$x['attempts'] . ': ' . (int)$x['n']; $say('  ' . ($p === [] ? 'none' : implode(' · ', $p)));
$wPend = (int)$q1("SELECT COUNT(DISTINCT f.id) FROM followups f JOIN followup_drafts d ON d.followup_id = f.id AND d.status = 'pending' WHERE f.closed_at IS NULL$ENQ");
$wAppr = (int)$q1("SELECT COUNT(DISTINCT f.id) FROM followups f JOIN followup_drafts d ON d.followup_id = f.id AND d.status = 'approved' WHERE f.closed_at IS NULL$ENQ");
$say("  open with a draft waiting for a person: $wPend · open with an approved draft not yet sent: $wAppr (one that is due is judged again on each pass)"
    . ($open > 200 ? " · followup_close looks at only the 200 earliest-due each run, so a reply to one of the other " . ($open - 200) . ' closes it only when the run reaches it' : ''));

// ── E. the run's front, and the Follow-ups screen ───────────────────────────────────────────────────────────────
$say('');
$say('== E. The run\'s front (what followup_run.php takes each run) and the Follow-ups screen ==');
$convOk = $has('wa_conversations') ? "EXISTS (SELECT 1 FROM wa_conversations c WHERE c.id = f.conversation_id)" : '1';
$front = $qa("SELECT f.id, f.channel, $convOk AS conv_ok,
                     (SELECT MIN(d.created_at) FROM followup_drafts d WHERE d.followup_id = f.id AND d.status = 'pending') AS pend_at,
                     (SELECT COUNT(*) FROM followup_drafts d WHERE d.followup_id = f.id AND d.status = 'approved') AS appr
                FROM followups f WHERE f.closed_at IS NULL AND f.due_at IS NOT NULL AND f.due_at <= ?$ENQ ORDER BY f.due_at LIMIT ?", [$now, $runLimit]);
$frontN = count($front); $frontPend = 0; $frontAppr = 0; $frontClosing = 0; $pendAts = [];
foreach ($front as $x) {
    // followup_run.php closes these before it looks for a waiting draft: the conversation gone, a held salesperson number
    if ((int)$x['conv_ok'] === 0 || ($heldOwned && ($chanOwner[(string)$x['channel']] ?? '') === 'staff')) { $frontClosing++; continue; }
    if ($x['pend_at'] !== null) { $frontPend++; $pendAts[] = (string)$x['pend_at']; }
    if ((int)$x['appr'] > 0) $frontAppr++;
}
$wide = $qa("SELECT (SELECT COUNT(*) FROM followup_drafts d WHERE d.followup_id = f.id AND d.status = 'pending') AS pend
               FROM followups f WHERE f.closed_at IS NULL AND f.due_at IS NOT NULL AND f.due_at <= ?$ENQ ORDER BY f.due_at LIMIT 50", [$now]);
$widePend = 0; foreach ($wide as $x) if ((int)$x['pend'] > 0) $widePend++;
if ($runLimit === 0) $jam = 'NOTHING JUDGED — followup_run_limit is 0, so the run takes no follow-up';
elseif ($frontN === 0) $jam = 'nothing due';
elseif ($frontPend === $frontN && !$unlimited && $frontN >= $runLimit) $jam = 'JAMMED — every place the run takes holds a draft waiting for a person, so it judges no other follow-up until each is moved on: Approve (the sender sends it) or Not interested (an opt-out); Reject alone leaves it at the front, and a closed one is opened again by the scan';
elseif ($frontPend === $frontN) $jam = 'not jammed — every due one waits for a person, but the run takes more than are due, so a newly due one is still judged';
elseif ($frontPend > 0) $jam = 'partly blocked';
else $jam = 'clear';
$say("  the first $frontN due (followup_run_limit $runLimit" . ($unlimited ? ': every due one' : '') . "): $frontPend wait for a person · $frontAppr hold an approved, unsent draft"
    . ($frontClosing > 0 ? " · $frontClosing the run closes first (the conversation gone, or a held salesperson number)" : '') . " — $jam");
$say("  the first " . count($wide) . " due: $widePend wait for a person");
$kindExpr = $kindCol ? "COALESCE(f.kind,'enquiry')" : "'enquiry'";
$pendAll = (int)$q1("SELECT COUNT(*) FROM followup_drafts d JOIN followups f ON f.id = d.followup_id WHERE d.status = 'pending'");
$screen = $qa("SELECT CASE WHEN f.closed_at IS NULL THEN 0 ELSE 1 END AS closed, $kindExpr AS kind
                 FROM followup_drafts d JOIN followups f ON f.id = d.followup_id WHERE d.status = 'pending' ORDER BY d.created_at LIMIT 50");
$scrClosed = 0; $scrQuote = 0; foreach ($screen as $x) { if ((int)$x['closed'] === 1) $scrClosed++; if ((string)$x['kind'] === 'quote') $scrQuote++; }
$hidden = 0;
foreach ($pendAts as $at) {
    if ((int)$q1("SELECT COUNT(*) FROM followup_drafts d JOIN followups f ON f.id = d.followup_id WHERE d.status = 'pending' AND d.created_at < ?", [$at]) >= 50) $hidden++;
}
$say("  the Follow-ups screen shows the 50 oldest waiting drafts of every kind, open or closed: $pendAll wait in all · on its " . count($screen)
    . " cards: $scrClosed on a CLOSED follow-up (nothing to send — Reject clears each), $scrQuote quotation drafts · of the front's $frontPend waiting drafts: $hidden beyond those 50 — "
    . ($hidden > 0 ? 'NOT ON THE SCREEN' : 'all on the screen'));
$r = $qa("SELECT d.verdict, COUNT(*) n, MIN(d.created_at) oldest FROM followup_drafts d JOIN followups f ON f.id = d.followup_id
           WHERE d.status = 'pending' AND f.closed_at IS NOT NULL GROUP BY d.verdict ORDER BY d.verdict");
$m = []; $pcN = 0; $pcOld = null;
foreach ($r as $x) {
    $w = in_array((string)$x['verdict'], ['SEND', 'DO_NOT_SEND', 'WAIT', 'ESCALATE_TO_HUMAN'], true) ? (string)$x['verdict'] : 'other';
    $m[$w] = ($m[$w] ?? 0) + (int)$x['n']; $pcN += (int)$x['n'];
    if ($pcOld === null || (string)$x['oldest'] < $pcOld) $pcOld = (string)$x['oldest'];
}
$say("  waiting drafts on a CLOSED follow-up, every kind: $pcN" . ($pcN > 0 ? ' (the oldest written ' . $ago($pcOld) . ') — ' . $joinMap($m) : ''));

// ── F. the drafts ───────────────────────────────────────────────────────────────────────────────────────────────
$say('');
$say('== F. Drafts (the messages the assistant wrote) ==');
$r = $qa("SELECT d.status, COUNT(*) n FROM followup_drafts d JOIN followups f ON f.id = d.followup_id WHERE 1=1$ENQ GROUP BY d.status ORDER BY d.status");
$p = []; foreach ($r as $x) $p[] = $word($x['status']) . ' ' . (int)$x['n']; $say('  all time, by status: ' . ($p === [] ? 'none' : implode(' · ', $p)));
$apOpen = (int)$q1("SELECT COUNT(*) FROM followup_drafts d JOIN followups f ON f.id = d.followup_id WHERE d.status = 'approved' AND f.closed_at IS NULL$ENQ");
$apClosed = $qa("SELECT f.close_reason, COUNT(*) n FROM followup_drafts d JOIN followups f ON f.id = d.followup_id
                  WHERE d.status = 'approved' AND f.closed_at IS NOT NULL$ENQ GROUP BY f.close_reason ORDER BY n DESC, f.close_reason");
$m = []; $apDead = 0; foreach ($apClosed as $x) { $w = $word($x['close_reason']); $m[$w] = ($m[$w] ?? 0) + (int)$x['n']; $apDead += (int)$x['n']; }
$say("  approved, not sent: $apOpen on an OPEN follow-up (the real queue the sender reads) · $apDead never sent because the follow-up closed first — by reason: " . $joinMap($m));
$r = $qa("SELECT " . $bucket('d.decided_at', ['<1h' => 1, '1h-1d' => 24, '1-7d' => 168, '>7d' => null]) . " FROM followup_drafts d JOIN followups f ON f.id = d.followup_id WHERE d.status = 'approved' AND f.closed_at IS NULL$ENQ")[0];
$say('  the real queue, by time since approval: ' . $fmt($r));
$say('  the real queue by number: ' . $byLabel($qa("SELECT f.channel, COUNT(*) n FROM followup_drafts d JOIN followups f ON f.id = d.followup_id WHERE d.status = 'approved' AND f.closed_at IS NULL$ENQ GROUP BY f.channel")));
$r = $qa("SELECT SUM(CASE WHEN d.decided_by = 'auto' THEN 1 ELSE 0 END) auto, SUM(CASE WHEN d.decided_by LIKE 'staff:%' THEN 1 ELSE 0 END) staff,
                 SUM(CASE WHEN d.decided_by IS NULL OR (d.decided_by <> 'auto' AND d.decided_by NOT LIKE 'staff:%') THEN 1 ELSE 0 END) other
            FROM followup_drafts d JOIN followups f ON f.id = d.followup_id WHERE d.status = 'approved' AND f.closed_at IS NULL$ENQ")[0];
$say('  the real queue, approved by: ' . $fmt($r));
$failedSql = "(SELECT COUNT(*) FROM followup_events e WHERE e.followup_id = f.id AND e.event = 'failed' AND e.created_at >= d.decided_at)";
$apFailed = (int)$q1("SELECT COUNT(*) FROM followup_drafts d JOIN followups f ON f.id = d.followup_id WHERE d.status = 'approved' AND f.closed_at IS NULL$ENQ AND $failedSql > 0");
// the sender's list leaves out drafts on a number held for now (AutomationPolicy::sqlHeld, with the registry on): a paused
// one is measured here; an unverified number, a changed instance or missing department numbers are not
$paused = []; if ($registry) foreach ($chanStatus as $id => $st) if ($st === 'paused') $paused[] = $db->quote($id);
$notPaused = $paused !== [] ? ' AND f.channel NOT IN (' . implode(',', $paused) . ')' : '';
$ten = $qa("SELECT f.channel, $failedSql AS fails FROM followup_drafts d JOIN followups f ON f.id = d.followup_id
             WHERE d.status = 'approved' AND f.closed_at IS NULL$ENQ$notPaused ORDER BY d.decided_at LIMIT 10");
$tenFailed = 0; $tenNums = []; $tenOff = 0;
foreach ($ten as $x) {
    if ((int)$x['fails'] > 0) $tenFailed++;
    $tenNums[(string)$x['channel']] = true;
    if (in_array($chanStatus[(string)$x['channel']] ?? 'active', ['disabled', 'retired'], true)) $tenOff++;
}
$onPaused = $paused !== [] ? (int)$q1("SELECT COUNT(*) FROM followup_drafts d JOIN followups f ON f.id = d.followup_id WHERE d.status = 'approved' AND f.closed_at IS NULL$ENQ AND f.channel IN (" . implode(',', $paused) . ')') : 0;
$say("  the real queue, failed at least once since approval: $apFailed (a failed send stays approved and goes again first) · the sender's 10 places (the 10 oldest approved, a paused number's left out): "
    . count($ten) . " taken, $tenFailed failed before, " . count($tenNums) . " numbers, $tenOff closed when reached (number disabled or retired) · on a paused number, taking no place: $onPaused"
    . ($ledgerMode === 'claim' ? ' (while the ledger claims, only the oldest per number is tried each run)' : ''));
if ($has('wa_conversations')) {
    $cc = $cols('wa_conversations');
    // in the sender's order (followup_send.php): no conversation, then a person has the chat, then the customer wrote
    $ha = in_array('state', $cc, true) ? "c.state = 'human_active'" : '0';
    $r = $qa("SELECT SUM(CASE WHEN c.id IS NOT NULL AND NOT ($ha) AND c.last_customer_at IS NOT NULL AND c.last_customer_at > d.decided_at THEN 1 ELSE 0 END) replied,
                     SUM(CASE WHEN c.id IS NOT NULL AND $ha THEN 1 ELSE 0 END) human_closed,
                     SUM(CASE WHEN c.id IS NULL THEN 1 ELSE 0 END) cancelled
                FROM followup_drafts d JOIN followups f ON f.id = d.followup_id LEFT JOIN wa_conversations c ON c.id = f.conversation_id
               WHERE d.status = 'approved' AND f.closed_at IS NULL$ENQ")[0];
    $say('  in the real queue, the sender will CLOSE these, not send them: ' . $fmt($r) . ' (replied: the customer wrote after approval; human_closed: a person has the chat; cancelled: no conversation)');
}
$say("  $windowSay");
$r = $qa("SELECT " . $bucket('d.created_at', ['<1d' => 24, '1-7d' => 168, '>7d' => null]) . ",
                 SUM(CASE WHEN d.verdict = 'SEND' THEN 1 ELSE 0 END) AS verdict_send,
                 SUM(CASE WHEN d.verdict <> 'SEND' THEN 1 ELSE 0 END) AS verdict_other,
                 SUM(CASE WHEN d.body IS NULL OR trim(d.body) = '' THEN 1 ELSE 0 END) AS no_message
            FROM followup_drafts d JOIN followups f ON f.id = d.followup_id WHERE d.status = 'pending' AND f.closed_at IS NULL$ENQ")[0];
$say('  waiting for a person (pending, follow-up open): ' . $fmt($r) . ' (no_message: nothing to approve — Reject clears it)');

// ── G. what was sent, and the send ledger ───────────────────────────────────────────────────────────────────────
$say('');
$say('== G. Enquiry follow-ups sent, and the send ledger ==');
$last = $q1("SELECT MAX(s.sent_at) FROM followup_sends s JOIN followups f ON f.id = s.followup_id WHERE 1=1$ENQ");
$s24 = (int)$q1("SELECT COUNT(*) FROM followup_sends s JOIN followups f ON f.id = s.followup_id WHERE s.sent_at >= datetime(?, '-1 day')$ENQ", [$now]);
$s7 = (int)$q1("SELECT COUNT(*) FROM followup_sends s JOIN followups f ON f.id = s.followup_id WHERE s.sent_at >= datetime(?, '-7 days')$ENQ", [$now]);
$say('  the last one sent ' . $ago(is_string($last) ? $last : null) . " · in the last 24 h: $s24 · in the last 7 days: $s7");
$r = $qa("SELECT substr(s.sent_at, 1, 10) day, COUNT(*) n FROM followup_sends s JOIN followups f ON f.id = s.followup_id
           WHERE s.sent_at >= datetime(?, '-14 days')$ENQ GROUP BY day ORDER BY day", [$now]);
$p = []; foreach ($r as $x) $p[] = substr((string)$x['day'], 5) . ':' . (int)$x['n']; $say('  per UTC day, 14 days: ' . ($p === [] ? 'none' : implode(' ', $p)));
$say('  per number, 7 days: ' . $byLabel($qa("SELECT s.channel, COUNT(*) n FROM followup_sends s JOIN followups f ON f.id = s.followup_id WHERE s.sent_at >= datetime(?, '-7 days')$ENQ GROUP BY s.channel", [$now])));
$day = substr($now, 0, 10) . ' 00:00:00';
$r = $qa("SELECT s.channel, COUNT(*) n FROM followup_sends s JOIN followups f ON f.id = s.followup_id WHERE s.sent_at >= ?$ENQ GROUP BY s.channel", [$day]);
$full = 0; if ($cap > 0) foreach ($r as $x) if ((int)$x['n'] >= $cap) $full++;
$say('  today (UTC) per number, against followup_daily_cap ' . $cap . ': ' . $byLabel($r) . ' · numbers at the cap: ' . ($cap > 0 ? $full : 'none (no cap)'));
$ledgerFull = null;
if (!$has('wa_auto_sends')) {
    $say('  the send ledger table is not in the copy');
} else {
    $r = $qa("SELECT mode, COALESCE(outcome, 'in_flight') outcome, COUNT(*) n FROM wa_auto_sends
               WHERE kind = 'enquiry' AND claimed_at >= datetime(?, '-7 days') GROUP BY mode, outcome ORDER BY mode, outcome", [$now]);
    $m = []; foreach ($r as $x) $m[$word($x['mode']) . ' ' . $word($x['outcome'])] = (int)$x['n'];
    $say('  the ledger, enquiry rows in 7 days: ' . $joinMap($m));
    $klaDay = $kla->format('Y-m-d');
    $rows = $qa("SELECT handset, COUNT(*) used FROM wa_auto_sends WHERE proactive = 1 AND local_day = ?
                  AND (outcome IS NULL OR outcome <> 'not_sent') GROUP BY handset ORDER BY handset", [$klaDay]);
    $hourRows = $qa("SELECT handset, COUNT(*) n FROM wa_auto_sends WHERE proactive = 1 AND claimed_at > datetime(?, '-1 hour')
                      AND (outcome IS NULL OR outcome <> 'not_sent') GROUP BY handset", [$now]);
    $hour = []; foreach ($hourRows as $x) $hour[(string)$x['handset']] = (int)$x['n'];
    $p = []; $ledgerFull = 0; $hourFull = 0; $k = 0;
    foreach ($rows as $x) {
        $h = (string)$x['handset'];
        $l = isset($handsetChan[$h]) ? implode('+', array_map($label, $handsetChan[$h])) : 'a department number (or a moved one) #' . (++$k);
        $c = $ledgerDayCap;                                    // WaSendBudget::claim compares with the CURRENT setting
        $p[] = "$l " . (int)$x['used'] . '/' . $c;
        if ((int)$x['used'] >= $c) $ledgerFull++;
        if (($hour[$h] ?? 0) >= $ledgerHourCap) $hourFull++;
    }
    $say("  the ledger today (Kampala day $klaDay), proactive automated messages per number against wa_send_daily_cap: " . ($p === [] ? 'none' : implode(' · ', $p))
        . " · at the daily cap: $ledgerFull · at the hourly cap now: $hourFull");
}

// ── H. what the jobs did, 7 days ────────────────────────────────────────────────────────────────────────────────
$say('');
$say('== H. What the jobs did, 7 days ==');
$evKind = $kindCol ? " AND (f.id IS NULL OR COALESCE(f.kind,'enquiry')='enquiry')" : '';
$r = $qa("SELECT e.event, COUNT(*) n FROM followup_events e LEFT JOIN followups f ON f.id = e.followup_id
           WHERE e.created_at >= datetime(?, '-7 days')$evKind GROUP BY e.event ORDER BY n DESC, e.event", [$now]);
$m = []; foreach ($r as $x) { $w = $word($x['event']); $m[$w] = ($m[$w] ?? 0) + (int)$x['n']; }
$say('  events: ' . $joinMap($m));
$m = [];
foreach ($qa("SELECT e.detail FROM followup_events e LEFT JOIN followups f ON f.id = e.followup_id
               WHERE e.event = 'evaluated' AND e.created_at >= datetime(?, '-7 days')$evKind ORDER BY e.id", [$now]) as $x) {
    $head = explode(' — ', (string)$x['detail'], 2)[0];
    $head = preg_match('/^[a-z_]+( at [a-z0-9_]+)?$/', $head) ? $head : 'other';
    $m[$head] = ($m[$head] ?? 0) + 1;
}
arsort($m);
$say('  the gate\'s answers ("evaluated"): ' . $joinMap($m));
$r = $qa("SELECT d.verdict, $aiClass AS cls, COUNT(*) n FROM followup_drafts d JOIN followups f ON f.id = d.followup_id
           WHERE d.created_at >= datetime(?, '-7 days')$ENQ GROUP BY d.verdict, cls ORDER BY d.verdict, cls", [$now]);
$v = ['SEND' => 0, 'DO_NOT_SEND' => 0, 'ESCALATE_TO_HUMAN' => 0, 'WAIT' => 0, 'other' => 0];
$dn = ['ai_unreachable' => 0, 'ai_no_answer' => 0, 'ai_bad_answer' => 0, 'identity_uncertain' => 0, 'declined' => 0];
foreach ($r as $x) {
    $vv = isset($v[(string)$x['verdict']]) ? (string)$x['verdict'] : 'other'; $v[$vv] += (int)$x['n'];
    if ($vv === 'DO_NOT_SEND' && isset($dn[(string)$x['cls']])) $dn[(string)$x['cls']] += (int)$x['n'];
}
$aiFail7 = $dn['ai_unreachable'] + $dn['ai_no_answer'] + $dn['ai_bad_answer'];
$say('  the assistant\'s verdicts (drafts written; a WAIT writes none): SEND ' . $v['SEND'] . ' · DO_NOT_SEND ' . $v['DO_NOT_SEND'] . ' (' . $fmt($dn) . ') · ESCALATE_TO_HUMAN '
    . $v['ESCALATE_TO_HUMAN'] . ($v['WAIT'] > 0 ? ' · WAIT ' . $v['WAIT'] : '') . ($v['other'] > 0 ? ' · other ' . $v['other'] : '') . ' — an AI failure closes the follow-up as not_interested');
$per = [];
foreach ($qa("SELECT substr(e.created_at, 1, 10) day, e.event, COUNT(*) n FROM followup_events e LEFT JOIN followups f ON f.id = e.followup_id
               WHERE e.created_at >= datetime(?, '-7 days') AND e.event IN ('created', 'evaluated', 'drafted', 'sent')$evKind
               GROUP BY day, e.event ORDER BY day", [$now]) as $x) {
    $per[substr((string)$x['day'], 5)][(string)$x['event']] = (int)$x['n'];
}
$p = []; foreach ($per as $d => $e) $p[] = $d . ':' . ($e['created'] ?? 0) . '/' . ($e['evaluated'] ?? 0) . '/' . ($e['drafted'] ?? 0) . '/' . ($e['sent'] ?? 0);
$say('  per UTC day, opened/judged/drafted/sent: ' . ($p === [] ? 'none' : implode(' ', $p)));
// a REOPEN: a follow-up opened for the same quiet spell (the same last customer message) as an earlier one that closed
$prevOf = "(SELECT p.id FROM followups p WHERE p.conversation_id = f.conversation_id AND p.id < f.id AND p.last_customer_at = f.last_customer_at
             AND p.closed_at IS NOT NULL ORDER BY p.id DESC LIMIT 1)";
$opened7 = (int)$q1("SELECT COUNT(*) FROM followups f WHERE f.opened_at >= datetime(?, '-7 days')$ENQ", [$now]);
$r = $qa("SELECT (SELECT p.close_reason FROM followups p WHERE p.id = $prevOf) AS cr, COUNT(*) n FROM followups f
           WHERE f.opened_at >= datetime(?, '-7 days')$ENQ AND $prevOf IS NOT NULL GROUP BY cr ORDER BY n DESC, cr", [$now]);
$m = []; $reopen7 = 0; foreach ($r as $x) { $w = $word($x['cr']); $m[$w] = ($m[$w] ?? 0) + (int)$x['n']; $reopen7 += (int)$x['n']; }
$spells3 = (int)$q1("SELECT COUNT(*) FROM (SELECT f.conversation_id FROM followups f WHERE f.opened_at >= datetime(?, '-7 days')$ENQ
                     GROUP BY f.conversation_id, f.last_customer_at HAVING COUNT(*) >= 3)", [$now]);
$evReopen = (int)$q1("SELECT COUNT(*) FROM followup_events e JOIN followups f ON f.id = e.followup_id WHERE e.event = 'evaluated'
                       AND e.created_at >= datetime(?, '-7 days')$ENQ AND $prevOf IS NOT NULL", [$now]);
$evAll = (int)$q1("SELECT COUNT(*) FROM followup_events e LEFT JOIN followups f ON f.id = e.followup_id WHERE e.event = 'evaluated'
                    AND e.created_at >= datetime(?, '-7 days')$evKind", [$now]);
$say("  opened in 7 days: $opened7, of which REOPENED for the same quiet spell after an earlier one closed: $reopen7 — by how the earlier one closed: " . $joinMap($m)
    . " · opened 3 or more times for one quiet spell: $spells3 · the gate's answers spent on reopened ones: $evReopen of $evAll");
$ev24 = (int)$q1("SELECT COUNT(*) FROM followup_events e WHERE e.event = 'evaluated' AND e.created_at > datetime(?, '-1 day') AND e.created_at <= ?", [$now, $now]);
if ($jobsRun && $dueNow > 0 && $ev24 === 0) {
    $say("  THE RUN JUDGED NOTHING in the last 24 h while $dueNow are due — its log says why (a missing AI key ends it before any judging)");
}

// ── I. opened and closed, 14 days ───────────────────────────────────────────────────────────────────────────────
$say('');
$say('== I. Opened and closed, 14 days ==');
$r = $qa("SELECT substr(opened_at, 1, 10) day, COUNT(*) n FROM followups WHERE opened_at >= datetime(?, '-14 days')$ENQu GROUP BY day ORDER BY day", [$now]);
$p = []; foreach ($r as $x) $p[] = substr((string)$x['day'], 5) . ':' . (int)$x['n']; $say('  opened per UTC day: ' . ($p === [] ? 'none' : implode(' ', $p)));
$r = $qa("SELECT close_reason, COUNT(*) n FROM followups WHERE closed_at IS NOT NULL AND closed_at >= datetime(?, '-14 days')$ENQu GROUP BY close_reason ORDER BY n DESC, close_reason", [$now]);
$m = []; foreach ($r as $x) { $w = $word($x['close_reason']); $m[$w] = ($m[$w] ?? 0) + (int)$x['n']; }
$say('  closed, by reason: ' . $joinMap($m));
$niFail = (int)$q1("SELECT COUNT(*) FROM followups f WHERE f.closed_at IS NOT NULL AND f.closed_at >= datetime(?, '-14 days') AND f.close_reason = 'not_interested'$ENQ
                     AND EXISTS (SELECT 1 FROM followup_drafts d WHERE d.followup_id = f.id AND d.verdict = 'DO_NOT_SEND' AND $aiFail)", [$now]);
$say("  of those closed not_interested, after an AI failure (the assistant unreachable, silent or unreadable): $niFail — those customers were not declined by anyone");

// ── the summary first ───────────────────────────────────────────────────────────────────────────────────────────
$sum = [];
$sum[] = '== Summary ==';
$sum[] = '  the follow-up jobs ' . ($jobsRun ? 'run' : 'DO NOT RUN') . ' · automatic approval ' . ($autoSend ? 'on' : 'off')
       . ' · salesperson numbers ' . ($heldOwned ? 'HELD (never followed up automatically)' : 'not held');
$sum[] = '  quiet conversations with no open follow-up (1-30 days): ' . $never['total']
       . ($never['total'] > 0 ? ' — never followed up this quiet spell ' . ($never['eligible_not_opened'] ?? 0) . ' · opened AGAIN by every scan ' . ($never['reopened_each_scan'] ?? 0)
           . ' · on a held salesperson number ' . ($never['salesperson_number_held'] ?? 0)
           . ' · before followup_not_before ' . ($never['before_not_before'] ?? 0) . ' · older than followup_max_age_hours ' . ($never['older_than_max_age'] ?? 0) : '');
$sum[] = "  reopened by the scan for the same quiet spell, 7 days: $reopen7 of $opened7 opened · the gate's answers spent on them: $evReopen of $evAll";
$sum[] = "  open $open, due now $dueNow · the run's front: $jam";
$sum[] = "  the Follow-ups screen: $scrClosed of its " . count($screen) . ' cards are on a closed follow-up · the front\'s waiting drafts not on the screen: ' . $hidden;
$sum[] = "  approved and waiting to send: $apOpen (failed at least once: $apFailed) · " . ($inWindow ? 'inside' : 'OUTSIDE') . ' the sending window now';
$sum[] = '  the last enquiry follow-up sent: ' . $ago(is_string($last) ? $last : null) . " · last 7 days: $s7";
$sum[] = '  the assistant, 7 days: SEND ' . $v['SEND'] . ' · DO_NOT_SEND ' . $v['DO_NOT_SEND'] . ", of which AI failures $aiFail7 · closed not_interested after an AI failure in 14 days: $niFail";
$sum[] = "  limits: followup_daily_cap $cap " . ($cap > 0 ? "(after $cap sends on a number today, UTC, the run judges none of its follow-ups)" : '(no cap)')
       . " · followup_run_limit $runLimit (" . ($unlimited ? 'every due one' : ($runLimit === 0 ? 'none' : 'judged')) . " per run, every 10 min) · the ledger "
       . ($ledgerMode === 'off' ? 'off' : $ledgerMode . ($ledgerMode === 'claim' ? " ($ledgerDayCap proactive a number a day; at the cap today: " . ($ledgerFull ?? 0) . ')' : ''));
$sum[] = '';

// nothing on the copy changed: the connection made no change at all
$changes = (int)$db->query('SELECT total_changes()')->fetchColumn();
$all = array_merge($sum, $out, ['', "  changes on the copy: $changes"]);
$text = implode("\n", $all) . "\n";
// a last guard: no run of nine digits leaves this report, whatever a value held
echo preg_replace('/[0-9]{9,}/', '<digits>', $text);
exit($changes === 0 ? 0 : 3);
