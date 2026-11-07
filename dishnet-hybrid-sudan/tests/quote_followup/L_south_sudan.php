<?php
declare(strict_types=1);
/**
 * Section L of tests/test_quote_followup.php — South Sudan unchanged, and how that is proved (5.18.104, root docs/73
 * §H.2.17.2; ruling R1). TEST ONLY. Two trees: 221c9dc (5.18.103) from this checkout's Git, and the judged tree.
 *
 *   L01 (item 1) a South Sudan book, followup_enabled and followup_auto_send on, a fake AI (lib/ClaudeWaClient.php the same
 *       stand-in in both trees), the fake Evolution: followup_close, followup_scan, followup_run and followup_send from each
 *       tree, each on its own book holding the same rows — followups (bar 089's two columns, which read enquiry and NULL),
 *       followup_drafts, followup_sends, followup_events, the files the jobs leave in the data directory, the sends the
 *       fake received and stdout are equal, moments masked;
 *   L02 (item 2) the same with every 5.18.104 key of every part set ON in South Sudan's configuration, written directly
 *       (the tool refuses): equal again, and no 5.18.104 table gains a row — the gate stops them, not the switches;
 *   L03 (item 3) the same on a book whose 089 ALTER failed (the two columns absent, the file marked applied): the four jobs
 *       complete and match 221c9dc;
 *   L04 (item 4) NotificationService::sendVia (a reminder), paymentReceived (a receipt), sendDocument (an invoice) and
 *       AlertService::handover() — with the alert number, without it (notify's no_alert_number), for a salesperson's line
 *       (a LineOwner: notifyTo to the owner, then the central copy), and a repeat in its cooldown — and notifyTo, each from
 *       both trees on South Sudan with the ledger key on: the same outbound calls, Message Log rows, answers and alert
 *       cooldowns, and 0 ledger rows or seen marks; the webhook's half — a fromMe audio (W1), a wrapped inbound (W2) and
 *       an @lid message with no number (W3), each also driven on a Uganda control where it does leave its mark — is
 *       tests/test_voice_south_sudan.php A (A04) and B (B02, B03), the differential against 221c9dc's evo_webhook.php, run
 *       here on the judged tree as L04b;
 *   L05 (item 5) master on South Sudan: no schedule row for the four jobs, cron_status does not list them — Part F's MS
 *       (tests/test_master_104.php B and D), run here on the judged tree;
 *   L06 the tool refuses on South Sudan: `on tracking` exits non-zero and writes nothing.
 * Item 6 — QM10, QM60, WB23, HT8, MS1, MS8 and TL8 each fail one of these — is mutants_L.php; B10-fix1 adds HT8w and HT8l
 * (the marks' gate gone from W2's path alone and from W3's: only the wrapped inbound and the @lid message can catch them),
 * WB-A (a hand-over alert recorded: only handover() reaches recordAlert) and AL-N (notify()'s 5.18.104 hunk changing the
 * no-number answer). Every person, number and message is fictitious; nothing leaves 127.0.0.1.
 */
require_once __DIR__ . '/declared.php';
require_once __DIR__ . '/master_lib.php';

/** The fake AI both trees load in place of lib/ClaudeWaClient.php: one fixed SEND verdict, no network. */
const QL_FAKE_AI = <<<'PHP'
<?php
// TEST ONLY (tests/quote_followup/L_south_sudan.php): the same stand-in in both trees; no network, one fixed verdict.
class ClaudeWaClient
{
    public function __construct(string $apiKey, \PDO $pdo) {}
    public function getReply(string $m, array $c = [], string $ch = 'support', string $h = '', string $i = '', string $mode = 'append', $tools = null): ?string
    {
        return json_encode(['verdict' => 'SEND', 'reason' => 'fixture', 'message' => 'Hello again, are you still looking at a connection? (fixture)',
                            'sales_stage' => 'considering', 'product' => '', 'objection' => '', 'next_due_hours' => 48]);
    }
    public function lastSystemPrompt(): string { return ''; }
}
PHP;

/** A copy of $tree for L's runs, with the fake AI; [copy, '']. */
function ql_tree(QfCtx $ctx, string $tree, string $tag): string
{
    $d = $ctx->dir($tag);
    qf_copy_tree($tree, $d);
    file_put_contents($d . '/lib/ClaudeWaClient.php', QL_FAKE_AI);
    return $d;
}

/** South Sudan's configuration for L, the fake Evolution named; $extra on top. */
function ql_cfg(string $evo, array $extra = []): array
{
    return $extra + ['tenant_profile' => 'south-sudan', 'timezone' => implode('/', ['Africa', 'Juba']), 'followup_enabled' => '1', 'followup_auto_send' => '1',
                     'claude_api_key' => 'sk-ant-fixture-not-a-key', 'ai_provider' => 'claude', 'evo_api_url' => $evo, 'evo_api_key' => 'fake-key-ql',
                     'evo_instance_support' => 'ql-support', 'evo_instance_account' => 'ql-account', 'evo_instance_sales' => 'ql-sales',
                     'followup_send_hour_from' => 0, 'followup_send_hour_to' => 24, 'dry_run_mode' => false];
}

/** Every 5.18.104 key of every part, ON (ruling R5's table): switches and record keys set, since-stamps a moment, the ledger on. */
function ql_all_on(QfCtx $ctx): array
{
    require_once $ctx->tree() . '/lib/SalesFollowupLadder.php';
    $on = [];
    foreach (SalesFollowupLadder::allKeys() as $k) {
        $on[$k] = (substr($k, -6) === '_since' || substr($k, -4) === '_at' || strpos($k, 'not_before') !== false) ? '2026-11-01 00:00:00' : '1';
    }
    $on['wa_send_ledger'] = 'on';
    $on['ai_transcription_provider'] = 'openai';
    $on['ai_media_voice_reply_channels'] = 'all';
    return $on;
}

/**
 * A book of $tree's own migrations in a fresh data directory, holding $cfg and the same fixture rows on either tree: three
 * conversations whose customer went quiet 30 h ago (one per channel), one 3 h ago (not yet), one 20 days ago (too old).
 */
function ql_book(QfCtx $ctx, string $tree, array $cfg, ?callable $after = null): string
{
    $data = $ctx->dir('qlbook');
    $code = 'require ' . var_export($tree . '/lib/StoreInterface.php', true) . '; require ' . var_export($tree . '/lib/JsonStore.php', true)
          . '; require ' . var_export($tree . '/lib/SqliteStore.php', true) . '; $s = SqliteStore::create(' . var_export($data, true) . '); '
          . '$s->save("kyc_config.json", ' . var_export($cfg, true) . '); $s->getPdo()->exec("PRAGMA wal_checkpoint(TRUNCATE)");';
    exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code) . ' 2>&1');
    // after the store's first boot, which moves every JSON file of the directory into the book: what PluginConfig reads
    file_put_contents($data . '/kyc_config.json', json_encode($cfg));
    $p = new \PDO('sqlite:' . $data . '/plugin.sqlite3');
    $p->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    $now = time();
    foreach ([['211912401001', 'support', 30], ['211912401002', 'sales', 30], ['211912401003', 'account', 30], ['211912401004', 'support', 3],
              ['211912401005', 'sales', 480]] as $i => [$phone, $chan, $h]) {
        $at = gmdate('Y-m-d H:i:s', $now - $h * 3600);
        $p->prepare("INSERT INTO wa_conversations (phone, channel, state, status, last_customer_at, last_message_at) VALUES (?, ?, 'bot_active', 'active', ?, ?)")
          ->execute([$phone, $chan, $at, $at]);
        $c = (int)$p->lastInsertId();
        $p->prepare("INSERT INTO wa_messages (conversation_id, direction, role, body, sent_at, created_at) VALUES (?, 'out', 'agent', ?, ?, ?)")
          ->execute([$c, 'Here is the price list you asked for (fixture ' . $i . ')', gmdate('Y-m-d H:i:s', $now - $h * 3600 - 600), gmdate('Y-m-d H:i:s', $now - $h * 3600 - 600)]);
        $p->prepare("INSERT INTO wa_messages (conversation_id, direction, role, body, sent_at, created_at) VALUES (?, 'in', 'customer', ?, ?, ?)")
          ->execute([$c, 'Thanks, I will think about it (fixture ' . $i . ')', $at, $at]);
    }
    if ($after !== null) $after($p);
    unset($p);
    return $data;
}

/** One cron of $tree on $data, as master includes it, run alone in a process; its stdout. */
function ql_cron(string $tree, string $data, string $script): string
{
    file_put_contents($tree . '/ucrm.json', json_encode(['pluginDataDir' => $data]));
    $out = [];
    exec('cd ' . escapeshellarg($tree . '/cron') . ' && DN_DATA_DIR=' . escapeshellarg($data) . ' DN_VAULT_FILE=' . escapeshellarg($data . '/no-vault.json')
        . ' ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/run_cron.php') . ' ' . escapeshellarg($tree . '/' . $script) . " '{}' 2>&1", $out, $rc);
    return 'rc=' . $rc . "\n" . implode("\n", $out);
}

/** Every moment, id-like stamp and run-dependent number in a text masked, so two runs a second apart compare. */
function ql_mask(string $s): string
{
    $s = preg_replace('/\d{4}-\d\d-\d\d[ T]\d\d:\d\d:\d\d(?:\.\d+)?(?:Z|[+-]\d\d:?\d\d)?/', '<t>', $s);
    return preg_replace('/\b\d+(?:\.\d+)?\s?(?:ms|s)\b/', '<d>', (string)$s);
}

/** The follow-up tables of a book, rows in id order, 089's two columns left out, every moment masked; and the 104 tables' counts. */
function ql_tables(string $data): array
{
    $p = new \PDO('sqlite:' . $data . '/plugin.sqlite3');
    $out = [];
    foreach (['followups', 'followup_drafts', 'followup_sends', 'followup_events'] as $t) {
        $has = (int)$p->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = '{$t}'")->fetchColumn();
        $rows = $has ? $p->query("SELECT * FROM [{$t}] ORDER BY id")->fetchAll(\PDO::FETCH_ASSOC) : [];
        foreach ($rows as &$r) {
            unset($r['kind'], $r['quote_key']);
            foreach ($r as $k => $v) if (is_string($v)) $r[$k] = ql_mask($v);
        }
        unset($r);
        $out[$t] = $rows;
    }
    return $out;
}

/** The rows 089, 090 and 091's tables hold (a table that does not exist counts -1). */
function ql_new_rows(string $data): array
{
    $p = new \PDO('sqlite:' . $data . '/plugin.sqlite3');
    $n = [];
    foreach (['quote_tracker', 'wa_auto_sends', 'quote_list_sends', 'quote_followup_state', 'wa_seen_marks', 'wa_media', 'wa_voice_calls', 'wa_voice_acks',
              'wa_offers', 'wa_offer_recipients', 'wa_offer_tests', 'wa_offer_events'] as $t) {
        $has = (int)$p->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = '{$t}'")->fetchColumn();
        $n[$t] = $has ? (int)$p->query("SELECT COUNT(*) FROM [{$t}]")->fetchColumn() : -1;
    }
    return $n;
}

/** The four follow-up jobs of $tree, in master's order, on a book; [tables, sends the fake saw, stdout masked]. */
function ql_run4(string $tree, string $data, string $evo): array
{
    qf_http('GET', $evo . '/__test/reset');
    $out = '';
    foreach (['cron/followup_close.php', 'cron/followup_scan.php', 'cron/followup_run.php', 'cron/followup_send.php'] as $s) {
        $out .= "== {$s}\n" . ql_cron($tree, $data, $s) . "\n";
        // the clock moves on after the scan: what it opened falls due for the run, the same in both trees' books
        if ($s === 'cron/followup_scan.php') (new \PDO('sqlite:' . $data . '/plugin.sqlite3'))->exec("UPDATE followups SET due_at = '2026-01-01 00:00:00' WHERE closed_at IS NULL");
    }
    $sends = array_map(function ($c) { return [(string)($c['instance'] ?? ''), (string)($c['number'] ?? ''), (string)($c['text'] ?? '')]; }, qf_texts($evo));
    // and the data directory's own files (a lock or a state file one of the jobs left), the book and its journals aside
    $files = array_values(array_filter(array_map('basename', glob($data . '/*') ?: []), function ($f) { return strpos($f, 'plugin.sqlite3') !== 0; }));
    sort($files);
    return [ql_tables($data) + ['files in the data directory' => $files], $sends, ql_mask($out)];
}

$QF_SECTIONS['L'] = ['title' => 'South Sudan unchanged, proved against 221c9dc (§H.2.17.2)', 'run' => function (QfCtx $ctx) {
    [$B, $why] = qf_n_base($ctx->tmp . '/base221');
    $ctx->t($B !== null, 'L00 control: Git supplies ' . QF_N_BASE . ' — without it nothing below is measured: a failure, never a skip', $why);
    if ($B === null) return;
    $evo = qf_fake_evo();
    $ctx->t($evo !== '', 'L00 control: the fake Evolution answers');
    if ($evo === '') return;
    $old = ql_tree($ctx, $B, 'ql-221');
    $new = ql_tree($ctx, $ctx->root, 'ql-104');

    $ctx->run('L01 item 1: the four follow-up jobs on South Sudan, followup_enabled and followup_auto_send on, equal to 221c9dc\'s', function () use ($ctx, $old, $new, $evo) {
        $cfg = ql_cfg($evo);
        $a = ql_run4($old, ql_book($ctx, $old, $cfg), $evo);
        $dataNew = ql_book($ctx, $new, $cfg);
        $b = ql_run4($new, $dataNew, $evo);
        // the run reaches every follow-up the scan opened: drafted (and approved, and sent) by day, put off by its quiet hours at
        // night — both trees at the same moment, so either way the two runs must agree
        $evaluated = count(array_filter($a[0]['followup_events'], function ($e) { return $e['event'] === 'evaluated'; }));
        $ctx->t(count($a[0]['followups']) >= 3 && $evaluated >= 3, 'L01 control: 221c9dc\'s run opened follow-ups (' . count($a[0]['followups'])
                . '), evaluated each (' . $evaluated . '), with ' . count($a[0]['followup_drafts']) . ' drafts and ' . count($a[1]) . ' sends at this hour', substr($a[2], 0, 900));
        $ctx->eq($b[0], $a[0], 'L01 followups (bar 089\'s two columns), followup_drafts, followup_sends and followup_events equal');
        $ctx->eq([$b[1], $b[2]], [$a[1], $a[2]], 'L01 the sends the fake Evolution received, and stdout, equal');
        $p = new \PDO('sqlite:' . $dataNew . '/plugin.sqlite3');
        $ctx->eq($p->query("SELECT DISTINCT kind || '|' || COALESCE(quote_key, 'NULL') FROM followups")->fetchAll(\PDO::FETCH_COLUMN), ['enquiry|NULL'],
                 'L01 … and 089\'s two columns read enquiry and NULL on every row');
    });

    $ctx->run('L02 item 2: every 5.18.104 key of every part ON in South Sudan\'s configuration: equal, and no 5.18.104 table gains a row', function () use ($ctx, $old, $new, $evo) {
        $on = ql_all_on($ctx);
        $ctx->t(count($on) > 20 && isset($on['quote_chase_enabled'], $on['offers_live_enabled'], $on['wa_voice_ack_enabled'], $on['quote_agent_cc_enabled']),
                'L02 control: ' . count($on) . ' keys set, the chase, offers, the acknowledgement and the agent copy among them');
        $a = ql_run4($old, ql_book($ctx, $old, ql_cfg($evo)), $evo);
        $dataNew = ql_book($ctx, $new, ql_cfg($evo, $on));
        $b = ql_run4($new, $dataNew, $evo);
        $ctx->eq([$b[0], $b[1], $b[2]], [$a[0], $a[1], $a[2]], 'L02 the tables, the sends and stdout equal 221c9dc\'s with every key off there');
        $n = ql_new_rows($dataNew);
        $ctx->eq(array_filter($n, function ($v) { return $v !== 0; }), [], 'L02 the 12 tables of 089, 090 and 091 hold no row');
    });

    $ctx->run('L03 item 3: a South Sudan book whose 089 ALTER failed — the four jobs complete and match 221c9dc', function () use ($ctx, $old, $new, $evo) {
        $cfg = ql_cfg($evo);
        $a = ql_run4($old, ql_book($ctx, $old, $cfg), $evo);
        $dataNew = ql_book($ctx, $new, $cfg, function (\PDO $p) {
            // the columns removed, the file still marked applied; SQLite checks the whole schema on DROP COLUMN, so views go first
            foreach ($p->query("SELECT name FROM sqlite_master WHERE type = 'view'")->fetchAll(\PDO::FETCH_COLUMN) as $v) $p->exec('DROP VIEW [' . $v . ']');
            $p->exec('DROP INDEX IF EXISTS idx_fu_quote');
            $p->exec('ALTER TABLE followups DROP COLUMN quote_key');
            $p->exec('ALTER TABLE followups DROP COLUMN kind');
        });
        $p = new \PDO('sqlite:' . $dataNew . '/plugin.sqlite3');
        $cols = array_column($p->query('PRAGMA table_info(followups)')->fetchAll(\PDO::FETCH_ASSOC), 'name');
        $ctx->eq([in_array('kind', $cols, true), (int)$p->query("SELECT COUNT(*) FROM _migrations WHERE filename = '089_quote_followups.sql'")->fetchColumn()],
                 [false, 1], 'L03 control: kind is gone and 089 is marked applied');
        unset($p);
        $b = ql_run4($new, $dataNew, $evo);
        $ctx->t(strpos($b[2], 'rc=0') !== false && substr_count($b[2], 'rc=0') === 4, 'L03 the four jobs complete (exit 0 each)', substr($b[2], 0, 900));
        $ctx->eq([$b[0], $b[1], $b[2]], [$a[0], $a[1], $a[2]], 'L03 the tables, the sends and stdout equal 221c9dc\'s');
    });

    $ctx->run('L04 item 4: the record-only hooks and the marks on South Sudan — the same sends and Message Log rows, 0 ledger rows or marks', function () use ($ctx, $old, $new, $evo) {
        // B10-fix1 (B10-R03): AlertService::handover() itself, as D 3363 names it (221c9dc's lib/AlertService.php:82, unchanged):
        // with the alert number (notify → target → sendTo, key escalate:conv:<id>), without it (notify's no_alert_number record),
        // and for a salesperson's line (a LineOwner: notifyTo to the owner, then the central copy) — beside the sends.
        $probe = function (string $tree, string $data) use ($evo): array {
            qf_http('GET', $evo . '/__test/reset');
            $code = 'chdir(' . var_export($tree, true) . '); foreach (["StoreInterface","JsonStore","SqliteStore","NotificationService","AlertService","LineOwner"] as $l) require_once ' . var_export($tree . '/lib/', true) . ' . $l . ".php";'
                . ' $s = SqliteStore::create(' . var_export($data, true) . '); $c = $s->load("kyc_config.json"); $r = [];'
                . ' $ns = new NotificationService($s, $c);'
                . ' $ns->sendVia(NotificationService::ACCOUNTS, "211912402001", "FIXTURE reminder: your invoice is due tomorrow", "ops_pre_due_d1");'
                . ' $ns->paymentReceived("211912402002", "Fixture Customer", 5000, "PAY-QL1");'
                . ' if (method_exists($ns, "sendDocument")) { $ns->sendDocument(NotificationService::ACCOUNTS, "211912402003", "https://127.0.0.1:9/inv.pdf", "invoice.pdf", "FIXTURE invoice", "invoice_issued"); }'
                . ' $a = new AlertService($s, $c);'
                . ' $r["central"] = $a->handover(7, "211912402004", "support", "fixture");'
                . ' $c2 = $c; unset($c2["alert_whatsapp"]);'
                . ' $r["no_number"] = (new AlertService($s, $c2))->handover(8, "211912402005", "sales", "fixture");'
                . ' $own = \Closure::bind(function () { return new LineOwner(41, "Fixture Seller", "Fixture", "211912402099"); }, null, LineOwner::class)();'
                . ' $r["owner"] = $a->handover(9, "211912402006", "sales", "fixture", $own);'
                . ' $r["again"] = $a->handover(7, "211912402004", "support", "fixture");'
                . ' $r["notifyTo"] = $a->notifyTo("211912402007", "handover:ql-1", "FIXTURE hand-over: a customer asked for a person");'
                . ' echo json_encode($r);';
            $o = []; exec('DN_DATA_DIR=' . escapeshellarg($data) . ' DN_VAULT_FILE=' . escapeshellarg($data . '/no-vault.json') . ' '
                . escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code) . ' 2>&1', $o, $rc);
            $p = new \PDO('sqlite:' . $data . '/plugin.sqlite3');
            $log = [];
            foreach (['notification_audit_log', 'notification_queue'] as $t) {
                if ((int)$p->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = '{$t}'")->fetchColumn()) {
                    foreach ($p->query("SELECT * FROM [{$t}] ORDER BY rowid")->fetchAll(\PDO::FETCH_ASSOC) as $r) { unset($r['id']); foreach ($r as $k => $v) if (is_string($v)) $r[$k] = ql_mask($v); $log[$t][] = $r; }
                }
            }
            $sends = array_map(function ($c) { return [(string)($c['instance'] ?? ''), (string)($c['number'] ?? ''), (string)($c['text'] ?? '')]; }, qf_texts($evo));
            // the alert cooldowns the store holds (AlertService::LOCK_FILE, alert_locks.json, a table of the book): the keys, and
            // whether each holds a moment (0 = released after a send that certainly did not leave)
            $locks = [];
            if ((int)$p->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'alert_locks'")->fetchColumn()) {
                foreach ($p->query('SELECT data FROM alert_locks ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN) as $j) {
                    $j = json_decode((string)$j, true);
                    if (is_array($j)) $locks[] = (string)($j['key'] ?? '') . '|' . ((int)($j['ts'] ?? 0) > 0 ? 'held' : 'released');
                }
            }
            return [$rc, $sends, $log, ql_new_rows($data), ql_mask(implode("\n", $o)), $locks];
        };
        $cfgOld = ql_cfg($evo, ['alert_whatsapp' => '211912402090']);
        $cfgNew = ql_cfg($evo, ['alert_whatsapp' => '211912402090', 'wa_send_ledger' => 'on', 'wa_send_ledger_since' => '2026-11-01 00:00:00']);
        $a = $probe($old, ql_book($ctx, $old, $cfgOld));
        $b = $probe($new, ql_book($ctx, $new, $cfgNew));
        $ra = json_decode((string)preg_replace('/^.*?(\{.*\})\s*$/s', '$1', $a[4]), true) ?: [];
        $toAlert = count(array_filter($a[1], function ($x) { return $x[1] === '211912402090'; }));
        $toOwner = count(array_filter($a[1], function ($x) { return $x[1] === '211912402099'; }));
        $ctx->t($a[0] === 0 && count($a[1]) >= 2 && $a[2] !== [], 'L04 control: 221c9dc\'s sends went to the fake (' . count($a[1]) . ') and were logged', json_encode([$a[0], $a[4]]));
        $ctx->eq([$ra['central']['central']['reason'] ?? null, $ra['no_number']['central']['reason'] ?? null, $ra['owner']['owner']['reason'] ?? null,
                  $ra['owner']['central']['reason'] ?? null, $ra['again']['central']['reason'] ?? null, $toAlert, $toOwner, $a[5]],
                 ['sent', 'no_alert_number', 'sent', 'sent', 'cooldown', 2, 1, ['escalate:conv:7|held', 'escalate:conv:9:owner|held', 'escalate:conv:9|held', 'handover:ql-1|held']],
                 'L04 control: 221c9dc\'s handover() — to the alert number, no_alert_number without one, the owner\'s phone and the central copy for a '
                 . 'salesperson\'s line, the cooldown on a repeat; each key\'s cooldown held in the store', json_encode([$a[4], $a[5]]));
        $ctx->eq([$b[0], $b[1], $b[2], $b[4], $b[5]], [$a[0], $a[1], $a[2], $a[4], $a[5]],
                 'L04 the same outbound calls, Message Log rows, answers and alert cooldowns, with the ledger key on');
        $ctx->eq([$b[3]['wa_auto_sends'], $b[3]['wa_seen_marks']], [0, 0], 'L04 … and 0 ledger rows, 0 seen marks');
        // B10-fix1 (B10-R03): nor any other 5.18.104 row — a hand-over alert with no handset is counted (noinstance:) on Uganda only
        $ctx->eq(array_filter($b[3], function ($v) { return $v !== 0; }), [], 'L04 … and no row in any of the 12 tables of 089, 090 and 091 (no ledger count either)');
    });

    $ctx->run('L04b item 4\'s webhook half: tests/test_voice_south_sudan.php A and B (the differential against 221c9dc\'s evo_webhook.php), on the judged tree', function () use ($ctx) {
        $o = []; exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/test_voice_south_sudan.php') . ' --no-mutants --root=' . escapeshellarg($ctx->root)
            . ' --sections=A,B 2>&1', $o, $rc);
        $ok = count(array_filter($o, function ($l) { return strpos($l, '  ok   ') === 0; }));
        $ctx->eq([$rc, $ok > 5, array_values(array_filter($o, function ($l) { return strpos($l, '  FAIL ') === 0; }))], [0, true, []],
                 'L04b the webhook on South Sudan, every voice key on, is 221c9dc\'s (' . $ok . ' ok)', implode("\n", array_slice($o, -12)));
    });

    $ctx->run('L05 item 5: master on South Sudan keeps no schedule row for the four, and cron_status does not list them (MS B, D)', function () use ($ctx) {
        $o = []; exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/test_master_104.php') . ' --no-mutants --root=' . escapeshellarg($ctx->root)
            . ' --sections=B,D 2>&1', $o, $rc);
        $need = array_values(array_filter($o, function ($l) { return preg_match('/^  ok   (B03 none of the four ran|D03 --all: none of the four)/', $l) === 1; }));
        $ctx->eq([$rc, count($need), array_values(array_filter($o, function ($l) { return strpos($l, '  FAIL ') === 0; }))], [0, 2, []],
                 'L05 MS B and D pass on the judged tree, B03 and D03 among them', implode("\n", array_slice($o, -12)));
    });

    $ctx->run('L06 the tool refuses on South Sudan: `on tracking` exits non-zero, nothing is written', function () use ($ctx, $new, $evo) {
        $data = ql_book($ctx, $new, ql_cfg($evo));
        $before = (string)@file_get_contents($data . '/kyc_config.json');
        $hash = sha1_file($data . '/plugin.sqlite3');
        $o = []; exec('DN_DATA_DIR=' . escapeshellarg($data) . ' DN_VAULT_FILE=' . escapeshellarg($data . '/no-vault.json') . ' '
            . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($new . '/tools/sales_followup.php') . ' on tracking 2>&1', $o, $rc);
        $ctx->eq([$rc !== 0, (string)@file_get_contents($data . '/kyc_config.json') === $before, sha1_file($data . '/plugin.sqlite3') === $hash], [true, true, true],
                 'L06 refused (exit ' . $rc . '), the configuration and the book unchanged', implode("\n", $o));
        $ctx->t(strpos(implode("\n", $o), 'not_uganda') !== false || stripos(implode("\n", $o), 'not this install') !== false, 'L06 … and it says why: not this install', implode("\n", $o));
    });
}];
