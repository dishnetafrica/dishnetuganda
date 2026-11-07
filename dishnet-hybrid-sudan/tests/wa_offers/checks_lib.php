<?php
declare(strict_types=1);
/**
 * Shared by sections B (the switch's callables) and K's --check half (tests/wa_offers/k_checks.php), and by C (the
 * screen): the tool's two connections as ruling R13 opens them, the ladder's key table where lib/SalesFollowupLadder.php is
 * not yet in the tree (stage B9 builds it; the stub stands in for it, and steps aside the moment the file exists), ruling
 * R1's four master lines planted in the tree copy while P-O1-6 is judged (stage B10 writes them; nothing is planted once
 * they are there), and planted live books: an offer started, recipients and ledger rows written in the shapes the sender
 * and Part F's ledger write them. 5.18.104, root docs/73 §H.4.6, §H.4.16. TEST ONLY. Every number and name is fictitious.
 */

/** The tool's read-only check connection on a book (PRAGMA query_only=ON, never SqliteStore::create; ruling R13). */
function wo_ro(array $b): \PDO
{
    $ro = new \PDO('sqlite:' . rtrim($b['data'], '/') . '/plugin.sqlite3');
    $ro->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    $ro->exec('PRAGMA busy_timeout = 5000');
    $ro->exec('PRAGMA query_only = ON');
    return $ro;
}

function wo_changes(\PDO $pdo): int
{
    return (int)$pdo->query('SELECT total_changes()')->fetchColumn();
}

/**
 * Part F's key table where the tree has none yet: allKeys() holds P6's switches and stamp among the others, settingKeys()
 * the six settings among the ledger's. $drop takes a key out (P-O1-9's refusal).
 */
function wo_ladder_stub(QfCtx $ctx): void
{
    // 5.18.104 (root docs/73 §H.2.12.4): the tree's own ladder once it exists (stage B9), loaded here so that B02's
    // keys_undeclared cases run whether or not a precondition has loaded it first (OM-B3's copy never does).
    if (class_exists('SalesFollowupLadder', false)) return;
    if (is_file($ctx->tree() . '/lib/SalesFollowupLadder.php')) { require_once $ctx->tree() . '/lib/SalesFollowupLadder.php'; return; }
    eval('final class SalesFollowupLadder {
        public static $drop = [];
        public static function allKeys(): array {
            return array_values(array_diff(["wa_send_ledger", "wa_send_ledger_since", "quote_tracking_enabled", "quote_daily_list_enabled",
                "quote_chase_enabled", "offers_test_enabled", "offers_live_enabled", "offers_live_since"], self::$drop));
        }
        public static function settingKeys(): array {
            return array_values(array_diff(["wa_send_daily_cap", "wa_send_hourly_cap", "wa_send_gap_min_s", "wa_send_gap_max_s",
                "wa_send_hour_from", "wa_send_hour_to", "offers_warm_days", "offers_recent_days", "offers_daily_max_per_handset",
                "offers_on_owned_numbers", "offers_stop_pause_pct", "offers_max_days"], self::$drop));
        }
    }');
}

/**
 * Ruling R1's four lines in the tree copy's cron/master.php, last in $_m_jobs (P-O1-6 reads them); $on false puts back what
 * was there. Where the tree already holds them (stage B10), nothing is planted and nothing is removed.
 * 5.18.104 (root docs/73 §H.4.6 P-O1-6), stage B10: on such a tree B02's master_lines case takes them out with
 * wo_master_absent(), and either call here puts the copy back as it was.
 */
function wo_master_plant(QfCtx $ctx, bool $on): void
{
    static $orig = null;
    $f = $ctx->tree() . '/cron/master.php';
    if (isset($GLOBALS['wo_master_cut'])) { file_put_contents($f, $GLOBALS['wo_master_cut']); unset($GLOBALS['wo_master_cut']); return; }   // 5.18.104 (root docs/73 §H.4.6)
    if (!$on) { if ($orig !== null) { file_put_contents($f, $orig); $orig = null; } return; }
    $src = (string)file_get_contents($f);
    if (OfferSwitch::masterCheck($ctx->tree()) === '') return;
    if ($orig === null) $orig = $src;
    $start = strpos($src, '$_m_jobs = [');
    $end = strpos($src, "\n];", (int)$start);
    if ($start === false || $end === false) throw new \RuntimeException('wo_master_plant: no job list');
    $lines = "\n    // 5.18.104 (planted by the test: ruling R1's four lines)\n    " . implode("\n    ", OfferSwitch::masterLines());
    file_put_contents($f, substr($src, 0, $end) . $lines . substr($src, $end));
}

/**
 * 5.18.104 (root docs/73 §H.4.6 P-O1-6), stage B10: the tree copy's cron/master.php without ruling R1's four lines, each
 * found exactly once — for the case that needs them absent on a tree that holds them; wo_master_plant() puts it back.
 * On a tree without them (planted by the test), the same as wo_master_plant($ctx, false).
 */
function wo_master_absent(QfCtx $ctx): void
{
    if (OfferSwitch::masterCheck($ctx->tree()) !== '' || isset($GLOBALS['wo_master_cut'])) { wo_master_plant($ctx, false); return; }
    $f = $ctx->tree() . '/cron/master.php';
    $src = (string)file_get_contents($f);
    $cut = $src;
    foreach (OfferSwitch::masterLines() as $l) {
        if (substr_count($cut, '    ' . $l . "\n") !== 1) throw new \RuntimeException('wo_master_absent: a line of ruling R1 not found exactly once');
        $cut = str_replace('    ' . $l . "\n", '', $cut);
    }
    $GLOBALS['wo_master_cut'] = $src;
    file_put_contents($f, $cut);
}

/** The store's retailers.json: an active admin with a phone (id 7, the sandbox admin of the offers' rows), and $more. */
function wo_admins(array $b, array $more = [], string $phone = '0700990107'): void
{
    $b['store']->save('retailers.json', array_merge([['id' => 7, 'name' => 'Sandbox Admin', 'role' => 'admin', 'is_admin' => true,
                                                      'is_active' => true, 'phone' => $phone]], $more));
}

/** An offer made and set running at $at with a 'started' event, recording warm_days and recent_days; its id. */
function wo_live_offer(array $b, int $at, array $o = []): int
{
    $id = wo_offer($b, $at - 120, $o);
    $s = UtcClock::stamp($at);
    $b['pdo']->prepare("UPDATE wa_offers SET state = 'running', live_slot = 1, started_at = ?, started_by = 'Sandbox Admin', warm_days = ?,
                               recent_days = ?, updated_at = ? WHERE id = ?")
        ->execute([$s, $o['warm_days'] ?? 30, $o['recent_days'] ?? 7, $s, $id]);
    OfferStore::event($b['pdo'], $id, 'started', 7, 'Sandbox Admin', 'queued=0', $at);
    if (!empty($o['state']) && $o['state'] !== 'running') {
        $b['pdo']->prepare("UPDATE wa_offers SET state = ?, live_slot = ? WHERE id = ?")
            ->execute([$o['state'], in_array($o['state'], ['paused'], true) ? 1 : null, $id]);
    }
    return $id;
}

/** A recipient row, every column given or defaulted as the sender writes it; its id. */
function wo_precip(array $b, int $offerId, array $r): int
{
    static $rank = 0;
    $d = ['phone' => null, 'rank' => ++$rank, 'conversation_id' => 0, 'channel' => 'sales', 'handset' => 'i:sales-ug',
          'last_in_at' => '2026-11-01 07:00:00', 'state' => 'queued', 'not_before' => null, 'attempt' => 0, 'claim_token' => null,
          'claimed_at' => null, 'judged_at' => null, 'day_max' => null, 'ledger_claim' => null, 'variant' => null, 'wa_message_id' => null,
          'echo' => null, 'sent_at' => null, 'settled_at' => null, 'reason' => null];
    $r = $r + $d;
    $cols = array_keys($d);
    $b['pdo']->prepare('INSERT INTO wa_offer_recipients (offer_id, ' . implode(', ', $cols) . ') VALUES (?' . str_repeat(', ?', count($cols)) . ')')
        ->execute(array_merge([$offerId], array_map(function ($k) use ($r) { return $r[$k]; }, $cols)));
    return (int)$b['pdo']->lastInsertId();
}

/** A ledger row in the shape Part F's claim (mode claim, the limits it applied) or record writes it; its id. */
function wo_pledger(array $b, array $l): int
{
    $t = (int)$l['at'];
    $mode = $l['mode'] ?? 'claim';
    $kind = $l['kind'] ?? 'offer';
    $claim = $mode === 'claim';
    $va = $kind === 'voice_ack';
    $out = array_key_exists('outcome', $l) ? $l['outcome'] : 'sent';
    $b['pdo']->prepare('INSERT INTO wa_auto_sends (handset, kind, ref, try, mode, proactive, phone, local_day, gap_s, claimed_at, outcome,
                                                   settled_at, wa_message_id, detail, win_from, win_to, cap_day, cap_hour)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$l['handset'] ?? 'i:sales-ug', $kind, $l['ref'], $l['try'] ?? 1, $mode, in_array($kind, WaSendBudget::PROACTIVE, true) ? 1 : 0,
                   array_key_exists('phone', $l) ? $l['phone'] : null, WaSendBudget::localDay($t), $l['gap_s'] ?? 60, UtcClock::stamp($t), $out,
                   array_key_exists('settled_at', $l) ? $l['settled_at'] : ($out === null ? null : UtcClock::stamp($t + 3)),
                   $l['wa_message_id'] ?? null, $l['detail'] ?? null,
                   $claim ? ($l['win_from'] ?? ($va ? 7 : 8)) : null, $claim ? ($l['win_to'] ?? ($va ? 21 : 20)) : null,
                   $claim ? ($l['cap_day'] ?? 60) : null, $claim && !$va ? ($l['cap_hour'] ?? 15) : null]);
    return (int)$b['pdo']->lastInsertId();
}

/**
 * One correct offer send of a live offer, as the sender leaves it: a warm conversation (the customer's message a day before,
 * answered), the recipient claimed at $judged − 1, judged at $judged (day_max 20), its ledger claim at $judged + 1 and
 * the gateway's return at $judged + 3 ('sent', echo claimed, the echo seen by the webhook's guard). [recipient id, conv id,
 * ledger id]. $o: phone channel handset state reason outcome detail ledger (false: none) echo seen (false: no row)
 * conv (an existing conversation) day_max claimed_at sent_at wa_message_id.
 */
function wo_good_send(array $b, int $offerId, string $phone, int $judged, array $o = []): array
{
    static $n = 0; $n++;
    $ch = $o['channel'] ?? 'sales';
    $conv = $o['conv'] ?? wo_warm($b, $phone, $judged, 86400, $ch);
    $state = $o['state'] ?? 'sent';
    $id = 'WO-CK-' . $offerId . '-' . $n;
    $sent = $o['sent_at'] ?? ($judged + 3);
    $rid = wo_precip($b, $offerId, ['phone' => $phone, 'conversation_id' => $conv, 'channel' => $ch, 'handset' => $o['handset'] ?? 'i:sales-ug',
        'state' => 'claimed', 'attempt' => 1, 'claim_token' => 'ck' . $n, 'claimed_at' => UtcClock::stamp($o['claimed_at'] ?? ($judged - 1)),
        'judged_at' => UtcClock::stamp($judged), 'day_max' => $o['day_max'] ?? 20, 'variant' => 1,
        'wa_message_id' => array_key_exists('wa_message_id', $o) ? $o['wa_message_id'] : $id, 'echo' => $o['echo'] ?? ($state === 'sent' ? 'claimed' : null),
        'sent_at' => UtcClock::stamp($sent), 'settled_at' => UtcClock::stamp($sent + 1), 'reason' => $o['reason'] ?? null]);
    $lid = 0;
    if (($o['ledger'] ?? true) !== false) {
        $lid = wo_pledger($b, ['ref' => 'offer:' . $offerId . ':' . $rid, 'phone' => $phone, 'at' => $o['ledger_at'] ?? ($judged + 1),
                               'handset' => $o['ledger_handset'] ?? ($o['handset'] ?? 'i:sales-ug'),
                               'outcome' => array_key_exists('outcome', $o) ? $o['outcome'] : ($state === 'sent' ? 'sent' : 'uncertain'),
                               'detail' => $o['detail'] ?? null, 'wa_message_id' => $id, 'gap_s' => $o['gap_s'] ?? 60]);
        $b['pdo']->prepare("UPDATE wa_offer_recipients SET ledger_claim = ? WHERE id = ? AND state = 'claimed'")->execute([$lid, $rid]);
    }
    if ($state !== 'claimed') $b['pdo']->prepare("UPDATE wa_offer_recipients SET state = ? WHERE id = ? AND state = 'claimed'")->execute([$state, $rid]);
    if (($o['seen'] ?? true) !== false && $state === 'sent') {
        $b['pdo']->prepare("INSERT INTO evo_webhook_seen (message_id, instance, event) VALUES (?, 'Sales-UG', 'offer.send')")->execute([$id]);
    }
    return [$rid, $conv, $lid];
}

/** A test of a wording as sendTest() leaves it; its id. $o: state ledger echo started_at handset wa_message_id seen content_hash. */
function wo_ptest(array $b, int $offerId, int $variant, int $at, array $o = []): int
{
    static $n = 0; $n++;
    $off = OfferStore::find($b['pdo'], $offerId);
    $state = $o['state'] ?? 'sent';
    $id = array_key_exists('wa_message_id', $o) ? $o['wa_message_id'] : ('WO-CT-' . $offerId . '-' . $n);
    $b['pdo']->prepare("INSERT INTO wa_offer_tests (offer_id, content_hash, variant, staff_id, channel, handset, state, reason, wa_message_id, echo,
                                                    ledger, started_at, settled_at)
                        VALUES (?, ?, ?, 7, 'sales', ?, ?, NULL, ?, ?, ?, ?, ?)")
        ->execute([$offerId, $o['content_hash'] ?? $off['content_hash'], $variant, $o['handset'] ?? 'i:sales-ug', $state, $id,
                   array_key_exists('echo', $o) ? $o['echo'] : ($state === 'sent' ? 'claimed' : null),
                   array_key_exists('ledger', $o) ? $o['ledger'] : 'recorded', UtcClock::stamp($o['started_at'] ?? $at),
                   $state === 'sending' ? null : UtcClock::stamp(($o['started_at'] ?? $at) + 2)]);
    $tid = (int)$b['pdo']->lastInsertId();
    if (($o['ledger_row'] ?? false) === true) {
        wo_pledger($b, ['ref' => 'offer_test:' . $tid, 'kind' => 'other', 'mode' => 'record', 'handset' => $o['handset'] ?? 'i:sales-ug',
                        'at' => ($o['started_at'] ?? $at) + 1, 'outcome' => $state === 'sent' ? 'sent' : 'uncertain']);
    }
    if (($o['seen'] ?? true) && $id !== null && $state === 'sent') {
        $b['pdo']->prepare("INSERT INTO evo_webhook_seen (message_id, instance, event) VALUES (?, 'Sales-UG', 'offer.test')")->execute([$id]);
    }
    return $tid;
}

/** The status of a check, and its counts as one string the failure message can carry. */
function wo_st(array $r): string
{
    return (string)$r['status'];
}

function wo_detail(array $r): string
{
    return json_encode(['status' => $r['status'], 'counts' => $r['counts'], 'notes' => array_slice($r['notes'], 0, 12)], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

/** Whether a check's counts hold a key containing $needle (a printed count). */
function wo_counted(array $r, string $needle): int
{
    $n = 0;
    foreach ($r['counts'] as $k => $v) if (strpos((string)$k, $needle) !== false) $n += (int)$v;
    return $n;
}
