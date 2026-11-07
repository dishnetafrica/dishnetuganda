<?php
declare(strict_types=1);
/**
 * Helpers of tests/test_sales_followup_tool.php (5.18.104, root docs/73 §H.2.14 TL and CK). TEST ONLY. Every number,
 * name and message is fictitious. The zone is the test file's QF_TZ, never named here (tests/test_timezone.php).
 */

/** A Uganda configuration the tool reads from the data folder's kyc_config.json. */
const SFT_UG = ['tenant_profile' => 'uganda', 'timezone' => QF_TZ, 'evo_instance_support' => 'DishNet-UG', 'evo_instance_account' => 'DishNet-UG',
                'evo_instance_sales' => 'Sales-UG', 'ai_enabled' => true];

/** Load the judged tree's code the sections call in process. */
function sft_load(QfCtx $ctx): void
{
    $t = $ctx->tree();
    foreach (['StoreInterface', 'SqliteStore', 'timezone', 'UtcClock', 'PluginConfig', 'PluginLog', 'StaffJobsGate', 'SalesFollowupLadder',
              'SalesFollowupChecks', 'WaSendBudget', 'HumanTakeover', 'VoiceAckLimits', 'ReminderDay', 'FollowUpService', 'QuoteTracker',
              'QuoteChase', 'QuoteChaseText', 'QuoteSendEvidence', 'ContactOptOut', 'EvolutionApiService', 'NotificationService', 'AlertService',
              'VoiceSteps', 'OfferSwitch', 'QuoteAgentCopy', 'ChannelRegistry'] as $lib) {
        require_once $t . '/lib/' . $lib . '.php';
    }
}

/** A book whose data folder holds kyc_config.json with $cfg (the files the tool reads), Uganda by default. */
function sft_book(QfCtx $ctx, array $cfg = [], string $tag = 'sft'): array
{
    $b = $ctx->book($cfg + SFT_UG, $tag);
    sft_files($b, $cfg + SFT_UG);
    $b['cfg'] = $cfg + SFT_UG + ['_data_dir' => $b['data']];
    return $b;
}

/** Write the data folder's kyc_config.json as the tool would find it. */
function sft_files(array $b, array $cfg): void
{
    unset($cfg['_data_dir']);
    file_put_contents($b['data'] . '/kyc_config.json', json_encode($cfg, JSON_PRETTY_PRINT));
}

/** The data folder's kyc_config.json, as written. */
function sft_kyc(array $b): array
{
    $j = json_decode((string)@file_get_contents($b['data'] . '/kyc_config.json'), true);
    return is_array($j) ? $j : [];
}

/** The configuration the tool reads (the three files merged). */
function sft_read(QfCtx $ctx, array $b): array
{
    return PluginConfig::read($ctx->tree(), $b['data']);
}

/** Run the judged tree's tool against a book: [exit code, output]. $stdin is typed; $env adds to the environment. */
function sft_run(QfCtx $ctx, array $b, array $args, string $stdin = '', array $env = []): array
{
    $cmd = array_merge(['php', $ctx->tree() . '/tools/sales_followup.php'], $args);
    $e = array_filter(getenv(), function ($k) { return stripos((string)$k, 'proxy') === false; }, ARRAY_FILTER_USE_KEY);
    $e = $env + ['DN_DATA_DIR' => $b['data']] + $e;
    $p = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $e);
    fwrite($pipes[0], $stdin);
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    return [proc_close($p), (string)$out];
}

/** The tool's own read-only connection, as it opens one. */
function sft_ro(array $b): \PDO
{
    $ro = new \PDO('sqlite:' . $b['data'] . '/plugin.sqlite3', null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
    $ro->exec('PRAGMA busy_timeout = 5000');
    $ro->exec('PRAGMA query_only = ON');
    return $ro;
}

function sft_changes(\PDO $ro): int
{
    return (int)$ro->query('SELECT total_changes()')->fetchColumn();
}

/** A checker answering each id from $map (PASS for an id not named), as the engine is given one. */
function sft_checker(array $map): callable
{
    return function (string $id) use ($map): array {
        return ['status' => $map[$id] ?? 'PASS', 'counts' => [], 'notes' => []];
    };
}

/**
 * A configuration with every step up to and including $upto on (each since-stamp $ago seconds back), the steps
 * before it in order; the optional steps left off unless named in $also.
 */
function sft_on_upto(string $upto, int $now, int $ago = 864000, array $also = []): array
{
    $c = SFT_UG;
    foreach (SalesFollowupLadder::steps() as $s) {
        $row = SalesFollowupLadder::row($s);
        if (!$row['optional'] || in_array($s, $also, true)) {
            foreach ($row['switch'] as $k => $v) $c[$k] = $v;
            if ($row['stamp'] !== null) $c[$row['stamp']] = UtcClock::stamp($now - $ago);
            foreach ($row['options'] as $o) $c[$o['key']] = $o['type'] === 'channels' ? 'all' : SalesFollowupLadder::today($now - $ago);
            if ($row['ledger']) { $c['wa_send_ledger'] = 'on'; $c['wa_send_ledger_since'] = UtcClock::stamp($now - $ago); }
        }
        if ($s === $upto) break;
    }
    return $c;
}

/** The step whose name follows $s in the one order. */
function sft_next(string $s): string
{
    $all = SalesFollowupLadder::steps();
    return $all[array_search($s, $all, true) + 1];
}

/** The tree's data/plugin.log reset, with one dated line $daysBack days before now (it covers the days judged after it). */
function sft_log_reset(QfCtx $ctx, int $daysBack = 3): string
{
    $f = $ctx->tree() . '/data/plugin.log';
    $at = (new \DateTime('@' . (time() - $daysBack * 86400)))->setTimezone(new \DateTimeZone(QF_TZ))->format('Y-m-d H:i:s');
    file_put_contents($f, '[' . $at . '] [test] the log begins (fixture)' . "\n");
    return $f;
}

/** A conversation row; its id. */
function sft_conv(\PDO $p, string $phone, string $channel = 'support', string $state = 'bot_active'): int
{
    $p->prepare('INSERT INTO wa_conversations (phone, channel, state) VALUES (?, ?, ?)')->execute([$phone, $channel, $state]);
    return (int)$p->lastInsertId();
}

/** A stored message; its id. */
function sft_msg(\PDO $p, int $conv, array $m): int
{
    $m += ['direction' => 'out', 'role' => 'agent', 'body' => 'a message (test)', 'agent_name' => null, 'wa_message_id' => null,
           'event_key' => null, 'sent_at' => gmdate('Y-m-d H:i:s'), 'created_at' => gmdate('Y-m-d H:i:s')];
    $p->prepare('INSERT INTO wa_messages (conversation_id, direction, role, body, agent_name, wa_message_id, event_key, sent_at, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
      ->execute([$conv, $m['direction'], $m['role'], $m['body'], $m['agent_name'], $m['wa_message_id'], $m['event_key'], $m['sent_at'], $m['created_at']]);
    return (int)$p->lastInsertId();
}

/** The quotation text a confirming row carries (📋 marker, QuoteSendEvidence::MARKERS['ref']). */
function sft_ref_text(string $ref): string
{
    return "Your DishNet quotation (test)\n" . QuoteSendEvidence::MARKERS['ref'] . $ref . "\nValid until: 20 Nov 2026";
}

/**
 * A tracked quotation with its confirming row: [conv, msg, key]. $o: sent_at, created_at (the tracker row's), owner,
 * owner_source, status, backfilled, spoke (bool), channel.
 */
function sft_quote(\PDO $p, string $phone, string $ref, array $o = []): array
{
    $o += ['sent_at' => '2026-11-01 07:00:00', 'created_at' => null, 'owner' => null, 'owner_source' => 'none', 'status' => 'open',
           'backfilled' => 0, 'spoke' => true, 'channel' => 'support', 'valid_until' => '2026-11-20', 'conv' => null, 'track' => true];
    $conv = $o['conv'] ?? sft_conv($p, $phone, $o['channel']);
    if ($o['spoke']) sft_msg($p, $conv, ['direction' => 'in', 'role' => 'customer', 'body' => 'hello (test)', 'sent_at' => '2026-10-31 09:00:00', 'created_at' => '2026-10-31 09:00:00']);
    $msg = sft_msg($p, $conv, ['event_key' => 'quote_manual', 'body' => sft_ref_text($ref), 'sent_at' => $o['sent_at'], 'created_at' => $o['sent_at']]);
    $key = QuoteSendEvidence::key($ref);
    if ($o['track']) {
        $p->prepare('INSERT INTO quote_tracker (quote_key, quote_ref, phone, evidence_msg_id, evidence_event, made_by, sent_at, sent_conv_id,
                                                sent_channel, valid_until, owner_staff_id, owner_source, spoke_conv_id, spoke_channel, backfilled,
                                                status, created_at)
                     VALUES (?, ?, ?, ?, \'quote_manual\', \'manual\', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
          ->execute([$key, $ref, $phone, $msg, $o['sent_at'], $conv, $o['channel'], $o['valid_until'], $o['owner'], $o['owner_source'],
                     $o['spoke'] ? $conv : null, $o['spoke'] ? $o['channel'] : null, $o['backfilled'], $o['status'],
                     $o['created_at'] ?? $o['sent_at']]);
    }
    return ['conv' => $conv, 'msg' => $msg, 'key' => $key];
}

/** master_schedule.json saying the day's reminders ran at $at (the ledger's reminders_first). */
function sft_reminders_done(array $b, int $at): void
{
    $b['store']->save('master_schedule.json', ['customer_reminders' => ['last_run' => $at, 'last_run_at' => 'x', 'duration_ms' => 900]]);
}

/** A ledger on the book with the clock at $T (by reference) and the minimum gap drawn. */
function sft_ledger(array $b, int &$T, array $cfg = []): WaSendBudget
{
    return WaSendBudget::forInstall($cfg + $b['cfg'], $b['data'], $b['store'], null,
        function () use (&$T): int { return $T; }, function (int $a, int $c): int { return $a; });
}

/**
 * A chase as the quote pass leaves it: a tracked quotation, its quote follow-up and template draft, the ledger's claim at
 * $T settled sent with its id, and the followup_sends row. Returns [conv, fid, draft, key, claim, wa, body].
 */
function sft_chase(array $b, string $phone, string $ref, int $T, array $o = []): array
{
    $p = $b['pdo'];
    $o += ['step' => 1, 'variant' => 'A', 'persona' => null, 'channel' => 'support', 'attempt' => null, 'existing' => null, 'wa' => 'WA-CK-' . $ref . '-' . $T,
           'settle' => 'sent', 'cfg' => []];
    $svc = new FollowUpService($p);
    if ($o['existing'] === null) {
        $q = sft_quote($p, $phone, $ref, ['channel' => $o['channel']]);
        $fid = $svc->openQuote(['id' => $q['conv'], 'channel' => $o['channel'], 'phone' => $phone],
                               ['quote_key' => $q['key'], 'ref' => $ref, 'valid_until' => '2026-11-20', 'sent_at' => '2026-11-01 07:00:00', 'due_at' => '2026-11-02 07:00:00']);
        $p->prepare('UPDATE quote_tracker SET followup_id = ? WHERE quote_key = ?')->execute([$fid, $q['key']]);
    } else {
        [$q, $fid] = $o['existing'];
    }
    $attempt = $o['attempt'] ?? $o['step'];
    $body = (string)QuoteChaseText::render($o['step'], $o['variant'], $ref, '2026-11-20', $o['persona'], true);
    $d = $svc->draftTemplate($fid, $body, QuoteChaseText::templateId($o['step'], $o['variant'], $o['persona'] !== null));
    $did = (int)($d['id'] ?? 0);
    if ($did > 0) $svc->approve($did, 'auto:quote_chase');
    $L = sft_ledger($b, $T, $o['cfg']);
    $hs = $o['handset'] ?? (string)$b['cfg']['evo_instance_' . ($o['channel'] === 'account' ? 'account' : 'support')];
    $c = $L->claim($hs, 'quote', 'fu:' . $fid . ':' . $attempt, ['phone' => $phone]);
    $claim = (int)($c['claim'] ?? 0);
    if ($claim <= 0) throw new \RuntimeException('the fixture\'s claim was not ok: ' . (string)($c['defer'] ?? '?'));
    if ($claim > 0 && $o['settle'] !== null) $L->settle($claim, $o['settle'], ['wa_message_id' => $o['wa']]);
    if ($claim > 0 && $o['settle'] === 'sent') {
        $p->prepare('INSERT INTO followup_sends (followup_id, draft_id, attempt, sent_at, channel, phone, body, wa_message_id, decided_by)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, \'auto:quote_chase\')')
          ->execute([$fid, $did, $attempt, UtcClock::stamp($T), $o['channel'], $phone, $body, $o['wa']]);
    }
    return ['conv' => $q['conv'], 'fid' => $fid, 'draft' => $did, 'key' => $q['key'], 'claim' => $claim, 'wa' => $o['wa'], 'body' => $body,
            'q' => $q, 'code' => !empty($c['ok']) ? 'ok' : (string)($c['defer'] ?? '?')];
}

/** A check's status, and the result in a line a FAIL prints. */
function sft_st(array $r): string { return (string)($r['status'] ?? '?'); }
function sft_detail(array $r): string { return json_encode($r, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); }

/** The 221c9dc plugin from Git, extracted once (never the working tree); '' when Git cannot supply it. */
function sft_base(QfCtx $ctx): string
{
    static $dir = null;
    if ($dir !== null) return $dir;
    $top = trim((string)shell_exec('git -C ' . escapeshellarg(dirname(__DIR__, 2)) . ' rev-parse --show-toplevel 2>/dev/null'));
    $x = $ctx->dir('base221');
    if ($top === '') return $dir = '';
    exec('git -C ' . escapeshellarg($top) . ' archive 221c9dc dishnet-hybrid-sudan | tar -x -C ' . escapeshellarg($x) . ' 2>&1', $o, $rc);
    return $dir = ($rc === 0 && is_dir($x . '/dishnet-hybrid-sudan/lib')) ? $x . '/dishnet-hybrid-sudan' : '';
}

/**
 * TL14's one key scan (§H.2.12.4): every new or changed PHP file of the judged tree against 221c9dc from Git, outside
 * tests/; a new file scanned whole, a changed file in its added lines only. Each config read found as [file, key].
 *
 * 5.18.104 (root docs/73 §H.2.12.4, §H.2.14 N; ruling R10), stage B10 (notes/B9.md reading 3): the files are those of the
 * one merged declared list (tests/quote_followup/declared.php, which quote_followup N reads) — never a list of TL14's own;
 * TL14's control ties the list to Git's own new and changed files (sft_git_php()).
 */
require_once dirname(__DIR__) . '/quote_followup/declared.php';   // 5.18.104 (root docs/73 §H.2.14 N): the one merged list
function sft_key_reads(string $tree, string $base): array
{
    $out = [];
    $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($tree, \FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        $path = $f->getPathname();
        if (substr($path, -4) !== '.php') continue;
        $rel = substr($path, strlen($tree) + 1);
        if (strpos($rel, 'tests/') === 0 || strpos($rel, 'data/') === 0) continue;
        if (qf_declared_entry($rel) === null) continue;   // 5.18.104 (root docs/73 §H.2.14 N): the merged declared list's files
        $now = (string)file_get_contents($path);
        $old = is_file($base . '/' . $rel) ? (string)file_get_contents($base . '/' . $rel) : null;
        if ($old !== null && $old === $now) continue;
        if ($old === null) {
            $text = $now;
        } else {
            $a = tempnam(sys_get_temp_dir(), 'sfa'); $c = tempnam(sys_get_temp_dir(), 'sfc');
            file_put_contents($a, $old); file_put_contents($c, $now);
            $d = [];
            exec('diff -U0 ' . escapeshellarg($a) . ' ' . escapeshellarg($c), $d);
            @unlink($a); @unlink($c);
            $text = '';
            foreach ($d as $l) if (strpos($l, '+') === 0 && strpos($l, '+++') !== 0) $text .= substr($l, 1) . "\n";
        }
        if (preg_match_all('/\$(?:config|cfg|fc|lc|qc|fresh|storedConfig|this->config|this->evoConfig)\[\s*\'([a-z0-9_]+)\'\s*\]/', $text, $m)) {
            foreach ($m[1] as $k) $out[] = [$rel, $k];
        }
        // 5.18.104 (root docs/73 §H.2.12.4; §H.5.10.1 IP-4), B9-fix1: a read whose key comes from a literal list —
        // `foreach (['a', 'b'] as $k) … $config[$k]` (or array_key_exists($k, $config)) — reads each key the list names.
        foreach (sft_list_reads($text) as $k) $out[] = [$rel, $k];
    }
    return $out;
}

/** 5.18.104 (root docs/73 §H.2.14 N), stage B10: the PHP files outside tests/ that Git sees new or changed against $base. */
function sft_git_php(string $tree, string $base): array
{
    $out = [];
    foreach (qf_n_walk($tree) as $rel => $p) {
        if (substr($rel, -4) !== '.php' || strpos($rel, 'tests/') === 0) continue;
        if (!is_file($base . '/' . $rel) || hash_file('sha256', $p) !== hash_file('sha256', $base . '/' . $rel)) $out[] = $rel;
    }
    sort($out);
    return $out;
}

/**
 * 5.18.104 (root docs/73 §H.2.12.1; ruling R13), B9-fix1: "no store opened" made observable. A migration planted in the
 * judged tree's copy that any SqliteStore::create() on a book would apply — the tool's $ro and the preview never run
 * the migrations, so after a command that must open no store the probe table must be absent. sft_store_probe(true)
 * plants it (after the book is made), sft_store_probe(false) takes it out again.
 */
function sft_store_probe(QfCtx $ctx, bool $on): void
{
    $f = $ctx->tree() . '/migrations/999_sft_store_probe.sql';
    if ($on) file_put_contents($f, "CREATE TABLE IF NOT EXISTS sft_store_probe (x INTEGER);\n");
    elseif (is_file($f)) unlink($f);
}

/** Whether a store was opened on the book since the probe was planted (its table exists). */
function sft_store_opened(array $b): bool
{
    $p = new \PDO('sqlite:' . $b['data'] . '/plugin.sqlite3');
    return (int)$p->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'sft_store_probe'")->fetchColumn() > 0;
}

/** A warm customer, as ConversationService stores one: a message $agoS seconds back, answered a minute later; its id. */
function sft_warm(QfCtx $ctx, array $b, string $phone, string $channel, int $agoS): int
{
    if (!class_exists('ConversationService')) require_once $ctx->tree() . '/lib/ConversationService.php';
    $cs = new ConversationService($b['data'], $b['pdo']);
    $id = (int)$cs->ensureConversation($phone, $channel)['id'];
    $t = time() - $agoS;
    foreach ([['in', 'customer', 'Hello, what plans do you have? (test)', $t], ['out', 'agent', 'Here are our plans. (test)', $t + 60]] as [$dir, $role, $body, $at]) {
        $m = (int)$cs->storeMessage($id, ['direction' => $dir, 'role' => $role, 'body' => $body, 'agent_name' => $dir === 'out' ? 'DishNet AI' : null,
                                          'wa_message_id' => 'SFT-' . bin2hex(random_bytes(5)), 'sent_at' => UtcClock::stamp($at)]);
        $b['pdo']->prepare('UPDATE wa_messages SET created_at = ? WHERE id = ?')->execute([UtcClock::stamp($at), $m]);
    }
    return $id;
}

/** The store's retailers.json: an active admin with a phone (P-O1-7), and $more. */
function sft_admins(array $b, array $more = []): void
{
    $b['store']->save('retailers.json', array_merge([['id' => 7, 'name' => 'Sandbox Admin', 'role' => 'admin', 'is_admin' => true,
                                                      'is_active' => true, 'phone' => '0700990107']], $more));
}

/**
 * 5.18.104 (root docs/73 §H.2.12.4), B9-fix1: the keys of every `foreach ([<literals>] as $v)` whose body (to its closing
 * brace, or the one statement after it) reads a configuration through $v — `$config[$v]`, `$cfg[$v]` … or
 * array_key_exists($v, $config). TL14 then judges them as it judges a literal read.
 */
function sft_list_reads(string $text): array
{
    $out = [];
    $vars = '(?:config|cfg|fc|lc|qc|fresh|storedConfig|this->config|this->evoConfig)';
    if (!preg_match_all('/foreach\s*\(\s*\[([^\]]*)\]\s*as\s*(?:\$\w+\s*=>\s*)?\$(\w+)\s*\)/', $text, $m, PREG_OFFSET_CAPTURE)) return $out;
    foreach ($m[0] as $i => [$whole, $at]) {
        $v = $m[2][$i][0];
        $rest = substr($text, $at + strlen($whole));
        $body = '';
        if (preg_match('/^\s*\{/', $rest)) {
            $depth = 0;
            for ($j = 0, $n = strlen($rest); $j < $n; $j++) {
                if ($rest[$j] === '{') $depth++;
                elseif ($rest[$j] === '}') { $depth--; if ($depth === 0) { $body = substr($rest, 0, $j + 1); break; } }
            }
        } else {
            $e = strpos($rest, ';');
            $body = $e === false ? $rest : substr($rest, 0, $e + 1);
        }
        $reads = preg_match('/\$' . $vars . '\[\s*\$' . preg_quote($v, '/') . '\s*\]/', $body)
              || preg_match('/array_key_exists\(\s*\$' . preg_quote($v, '/') . '\s*,\s*\$' . $vars . '\s*\)/', $body);
        if (!$reads) continue;
        if (preg_match_all('/\'([a-z0-9_]+)\'/', $m[1][$i][0], $ks)) foreach ($ks[1] as $k) $out[] = $k;
    }
    return $out;
}
