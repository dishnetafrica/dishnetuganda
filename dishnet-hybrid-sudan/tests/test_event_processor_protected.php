<?php
declare(strict_types=1);
/**
 * test_event_processor_protected.php — 5.18.86 (docs/65, multi-number Batch 1, part C): cron/event_processor.php never
 * claims an event that a dedicated worker owns.
 *
 * event_processor is the one consumer that claims every type. Two ways it lost a worker's job:
 *   - crm.lead.sync was not on its list, so this 30-second loop could acknowledge a lead's sync as "unknown event type"
 *     before UcrmLeadWorker (which rides the AI worker's spawn) ever saw it: the lead stayed in leads.json only;
 *   - ai.reply was claimed and then released. A backlog of it sorts first (priority 3), so once more than 20 waited,
 *     every run claimed 20 of them, released them, and never reached the types it does handle.
 * Now: consume() excludes the worker-owned types in SQL; the release stays as a second line; wa.escalation — which the
 * hand-over emits after it has already acted and which nothing consumes — is acknowledged as a KNOWN type. Every other
 * type is handled exactly as before, efris.submit included (left as it was, recorded in docs/65). All of this is
 * Uganda's (docs/65 §Z.5): on every other install the 5.18.87 loop runs unchanged, and the ss_* scenarios prove it —
 * the defects included, recorded for their own decision.
 *
 * release/5.18.88: production has no AI media layer, so ai.media is not a type there. This copy of Batch 1's test proves
 * that neither country's list names it — South Sudan keeps its live list, ['ai.reply'] — and that an ai.media event is
 * acknowledged as unknown on both, exactly as on live 5.18.87. Two weakened copies add it back, one per country.
 *
 * Proved by behaviour, against the real EventBus, the real event_processor.php (included as master.php includes it) and
 * the real UcrmLeadWorker against the fake uCRM: success, a transient failure, its retry, and the dead letter. Each
 * weakened copy re-runs the same scenarios through this file in driver mode.
 *
 * Driver mode: php tests/test_event_processor_protected.php --driver <scenario>   (env DN_T_UCRM_PORT) → one JSON line.
 */
$root = dirname(__DIR__);
$isDriver = in_array('--driver', $argv ?? [], true);
date_default_timezone_set('UTC');

require_once $root . '/lib/bootstrap_data.php';
require_once $root . '/lib/StoreInterface.php';
require_once $root . '/lib/JsonStore.php';
require_once $root . '/lib/SqliteStore.php';
require_once $root . '/lib/EventBus.php';
require_once $root . '/lib/CrmApiClient.php';
require_once $root . '/lib/LeadMatcher.php';
require_once $root . '/lib/UcrmLeadSync.php';
require_once $root . '/workers/WorkerBase.php';
require_once $root . '/workers/UcrmLeadWorker.php';

function ep_hit(int $port, string $path): string {
    $ch = curl_init("http://127.0.0.1:{$port}{$path}");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 3, CURLOPT_PROXY => '']);
    $r = curl_exec($ch); curl_close($ch);
    return $r === false ? '' : (string)$r;
}

/** A fresh sandbox store; its events table comes from the plugin's own migrations. */
function ep_store(): array {
    $tmp = sys_get_temp_dir() . '/dn_ep_' . bin2hex(random_bytes(4));
    @mkdir($tmp, 0777, true);
    putenv('DN_DATA_DIR=' . $tmp);
    $store = SqliteStore::create($tmp);
    return [$tmp, $store, $store->getPdo(), new EventBus($store->getPdo())];
}

/** event_processor.php once, as cron/master.php includes it: $store and $config in scope, error_log captured. */
function ep_run(string $root, $store, array $config, string $logFile): void {
    $prev = ini_set('error_log', $logFile);
    (function () use ($root, $store, $config) {
        ob_start();
        try { include $root . '/cron/event_processor.php'; } finally { ob_end_clean(); }
    })();
    ini_set('error_log', (string)$prev);
}

function ep_row(PDO $pdo, int $id): array {
    $r = $pdo->query('SELECT status, attempts, locked_by, processed_at, error, next_retry_at FROM events WHERE id = ' . $id)
             ->fetch(PDO::FETCH_ASSOC);
    return is_array($r) ? $r : [];
}

/** Untouched = still pending, never tried, never locked, never processed. */
function ep_untouched(array $r): bool {
    return ($r['status'] ?? '') === 'pending' && (int)($r['attempts'] ?? -1) === 0
        && ($r['locked_by'] ?? null) === null && ($r['processed_at'] ?? null) === null;
}

/** The lead-sync worker as run_worker.php runs it, its log captured. */
function ep_worker(SqliteStore $store, array $cfg, string &$out): array {
    ob_start();
    try { $r = (new UcrmLeadWorker($store, $cfg, 30, 10))->run(); } finally { $out .= (string)ob_get_clean(); }
    return $r;
}

function ep_lead(SqliteStore $store): void {
    $store->save('leads.json', [[
        'id' => 1, 'phone' => '+256700000123', 'customer_name' => 'Test Lead', 'requirement' => 'Starlink at home',
        'location' => 'Test town', 'customer_type' => 'Residential', 'source' => 'whatsapp_ai', 'conversation_id' => 7,
    ]]);
}

function ep_scenario(string $root, string $name, int $ucrmPort): array {
    [$tmp, $store, $pdo, $bus] = ep_store();
    $log = $tmp . '/php_error.log';
    $f = ['scenario' => $name];
    // ss_<name>: the same scenario on a South Sudan install, where the 5.18.87 loop must run unchanged.
    $epCfg = ['tenant_profile' => strpos($name, 'ss_') === 0 ? 'south-sudan' : 'uganda'];
    $name  = strpos($name, 'ss_') === 0 ? substr($name, 3) : $name;
    $syncCfg = ['ai_crm_lead_sync' => '1', 'crm_auth_token' => 'test-key', 'crm_base_url' => "http://127.0.0.1:{$ucrmPort}"];
    $down    = ['crm_base_url' => 'http://127.0.0.1:1'] + $syncCfg;

    if ($name === 'claim') {
        $ids = [];
        foreach ([['crm.lead.sync', ['lead_id' => 1], 5], ['ai.reply', ['channel' => 'sales'], 3], ['ai.media', ['channel' => 'sales'], 3],
                  ['wa.send', ['phone' => '', 'message' => ''], 5], ['wa.escalation', ['channel' => 'sales', 'reason' => 'test'], 2],
                  ['install.ready', [], 5], ['efris.submit', ['invoice_id' => 1], 5]] as [$t, $p, $prio]) {
            $ids[$t] = $bus->emit($t, 'test', 1, $p, $prio, 'test');
        }
        ep_run($root, $store, $epCfg, $log);
        foreach ($ids as $t => $id) $f['row_' . $t] = ep_row($pdo, $id);
        foreach (['crm.lead.sync', 'ai.reply', 'ai.media'] as $t) $f['untouched_' . $t] = ep_untouched($f['row_' . $t]);
        $lg = (string)@file_get_contents($log);
        $f['log_unknown_escalation'] = strpos($lg, "unknown event type 'wa.escalation'") !== false;
        $f['log_unknown_install_ready'] = strpos($lg, "unknown event type 'install.ready'") !== false;
        $f['log_unknown_efris'] = strpos($lg, "unknown event type 'efris.submit'") !== false;
        $f['log_unknown_lead_sync'] = strpos($lg, "unknown event type 'crm.lead.sync'") !== false;
        $f['log_unknown_media'] = strpos($lg, "unknown event type 'ai.media'") !== false;
    }

    if ($name === 'starve') {
        for ($i = 0; $i < 25; $i++) $bus->emit('ai.reply', 'conversation', $i + 1, ['channel' => 'sales'], 3, 'test');
        for ($i = 0; $i < 25; $i++) $bus->emit('crm.lead.sync', 'lead', $i + 1, ['lead_id' => $i + 1], 5, 'test');
        $own = $bus->emit('install.rejected', 'ticket', 1, ['engineer_phone' => ''], 5, 'test');   // the processor's own type, newest
        ep_run($root, $store, $epCfg, $log);
        $f['own_status'] = (string)(ep_row($pdo, $own)['status'] ?? '');
        $f['worker_rows_untouched'] = (int)$pdo->query("SELECT COUNT(*) FROM events WHERE event_type IN ('ai.reply','crm.lead.sync')
            AND status = 'pending' AND attempts = 0 AND locked_by IS NULL AND processed_at IS NULL")->fetchColumn();
    }

    if ($name === 'flow') {
        ep_hit($ucrmPort, '/__test/reset'); ep_hit($ucrmPort, '/__test/scenario?name=fresh_install');
        ep_lead($store);
        $id = $bus->emit('crm.lead.sync', 'lead', 1, ['lead_id' => 1, 'conversation_id' => 7], 5, 'ai_reply_worker');
        ep_run($root, $store, $epCfg, $log);                 // the 30-second loop gets there first
        $f['after_processor'] = ep_row($pdo, $id);
        $out = '';
        $f['worker'] = ep_worker($store, $syncCfg, $out);
        $f['after_worker'] = ep_row($pdo, $id);
        $f['clients'] = count((json_decode(ep_hit($ucrmPort, '/__test/clients'), true) ?: [])['clients'] ?? []);
        $f['lead_crm_client'] = (int)((($store->load('leads.json') ?? [])[0] ?? [])['crm_client_id'] ?? 0);
    }

    if ($name === 'retry') {
        ep_hit($ucrmPort, '/__test/reset'); ep_hit($ucrmPort, '/__test/scenario?name=fresh_install');
        ep_lead($store);
        $id = $bus->emit('crm.lead.sync', 'lead', 1, ['lead_id' => 1, 'conversation_id' => 7], 5, 'ai_reply_worker');
        $out = '';
        ep_worker($store, $down, $out);                  // uCRM unreachable: a transient failure
        $f['after_failure'] = ep_row($pdo, $id);
        $f['retry_in_future'] = strtotime((string)$f['after_failure']['next_retry_at'] . ' UTC') > time();
        $pdo->exec("UPDATE events SET next_retry_at = datetime('now', '-1 minute') WHERE id = " . $id);   // the backoff passes
        ep_run($root, $store, $epCfg, $log);                 // the processor runs meanwhile: it must not take the retry
        $f['after_processor'] = ep_row($pdo, $id);
        ep_worker($store, $syncCfg, $out);               // uCRM back
        $f['after_retry'] = ep_row($pdo, $id);
        $f['clients'] = count((json_decode(ep_hit($ucrmPort, '/__test/clients'), true) ?: [])['clients'] ?? []);
    }

    if ($name === 'dead') {
        ep_lead($store);
        $id = $bus->emit('crm.lead.sync', 'lead', 1, ['lead_id' => 1, 'conversation_id' => 7], 5, 'ai_reply_worker');
        $pdo->exec('UPDATE events SET attempts = max_attempts - 1 WHERE id = ' . $id);   // four attempts already spent
        $out = '';
        ep_worker($store, $down, $out);
        $f['after_last_failure'] = ep_row($pdo, $id);
        $f['log_never_reached'] = strpos($out, 'NEVER reached uCRM after five attempts') !== false;
        ep_run($root, $store, $epCfg, $log);                 // the processor's dead-letter pass
        $f['after_processor'] = ep_row($pdo, $id);
    }

    exec('rm -rf ' . escapeshellarg($tmp));
    return $f;
}

// ── Driver mode: one JSON line, nothing else ───────────────────────────────────────────────────────
if ($isDriver) {
    $name = (string)($argv[array_search('--driver', $argv, true) + 1] ?? '');
    echo json_encode(ep_scenario($root, $name, (int)getenv('DN_T_UCRM_PORT'))), "\n";
    exit(0);
}

// ── Main ─────────────────────────────────────────────────────────────────────────────────────────────
require_once $root . '/tests/fixtures/staff_jobs_sandbox.php';   // sj_weakened_copy()
$pass = 0; $fail = 0;
function is_(bool $c, string $m, string $d = ''): void { global $pass, $fail;
    if ($c) { $pass++; echo "  ok   $m\n"; } else { $fail++; echo "  FAIL $m" . ($d !== '' ? "\n       " . substr($d, 0, 700) : '') . "\n"; } }

function ep_boot(string $router, int $base, string $sig): array {
    foreach (range(0, 11) as $slot) {
        $cand = $base + ((getmypid() + $slot * 13) % 70);
        $p = proc_open(sprintf('exec php -S 127.0.0.1:%d %s', $cand, escapeshellarg($router)), [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        for ($i = 0; $i < 40; $i++) {
            $r = ep_hit($cand, '/__test/state');
            if ($r !== '') { if (strpos($r, $sig) !== false) return [$p, $cand]; break; }
            usleep(100000);
        }
        if (is_resource($p)) { proc_terminate($p); proc_close($p); }
    }
    return [null, 0];
}
array_map('unlink', glob(sys_get_temp_dir() . '/fake_ucrm_*.json') ?: []);
[$ucrmSrv, $ucrmPort] = ep_boot($root . '/tests/fixtures/fake_ucrm_server.php', 9700, 'FAKE-UCRM-TEST');
register_shutdown_function(function () use (&$ucrmSrv) { if (is_resource($ucrmSrv)) { proc_terminate($ucrmSrv); proc_close($ucrmSrv); } });
if ($ucrmSrv === null) { echo "  FAIL could not start the fake uCRM server\n"; exit(1); }
putenv('DN_T_UCRM_PORT=' . $ucrmPort);

$drive = function (string $tree, string $name) use ($ucrmPort): array {
    $out = []; $rc = 0;
    exec('DN_T_UCRM_PORT=' . $ucrmPort . ' php ' . escapeshellarg($tree . '/tests/test_event_processor_protected.php')
        . ' --driver ' . escapeshellarg($name) . ' 2>&1', $out, $rc);
    $j = json_decode((string)end($out), true);
    return is_array($j) ? $j + ['_rc' => $rc] : ['_rc' => $rc, '_out' => substr(implode("\n", $out), -600)];
};

echo "A. The processor never claims a worker's event; every other type is handled exactly as before\n";
$c = $drive($root, 'claim'); $d = json_encode($c);
is_($c['untouched_crm.lead.sync'] === true, 'crm.lead.sync is left for UcrmLeadWorker: still pending, never tried, never locked', $d);
is_($c['untouched_ai.reply'] === true, 'ai.reply is left alone too — not even claimed and released', $d);
is_(($c['row_ai.media']['status'] ?? '') === 'done' && $c['log_unknown_media'] === true,
    'ai.media is on no list (no media layer in this release): acknowledged as an unknown type, exactly as on live 5.18.87', $d);
is_(($c['row_wa.escalation']['status'] ?? '') === 'done' && $c['log_unknown_escalation'] === false,
    'wa.escalation is acknowledged as a KNOWN type (the hand-over has already acted), no longer logged as "unknown"', $d);
is_(($c['row_wa.send']['status'] ?? '') === 'done', 'a type the processor handles (wa.send) is still processed', $d);
is_(($c['row_install.ready']['status'] ?? '') === 'done' && $c['log_unknown_install_ready'] === true,
    'an unrelated unknown type (install.ready) is still acknowledged and logged, exactly as before', $d);
is_(($c['row_efris.submit']['status'] ?? '') === 'done' && $c['log_unknown_efris'] === true,
    'efris.submit is handled exactly as before — deliberately unchanged here, recorded in docs/65 for its own decision', $d);

echo "\nB. A backlog of worker events no longer starves the processor's own work\n";
$s = $drive($root, 'starve'); $d = json_encode($s);
is_($s['own_status'] === 'done', 'with 50 worker events queued ahead of it, the processor still handles its own install.rejected event', $d);
is_($s['worker_rows_untouched'] === 50, 'and all 50 worker events stay untouched for their workers', $d);

echo "\nC. A lead's sync survives the processor running first, and reaches uCRM (success)\n";
$fl = $drive($root, 'flow'); $d = json_encode($fl);
is_(ep_untouched($fl['after_processor'] ?? []), 'after the processor\'s run the crm.lead.sync event is untouched', $d);
is_(($fl['after_worker']['status'] ?? '') === 'done' && $fl['clients'] === 1 && $fl['lead_crm_client'] > 0,
    'UcrmLeadWorker then processes it: one uCRM client created, the lead carries its id, the event is done', $d);

echo "\nD. A transient failure is retried by the worker, never taken by the processor\n";
$r = $drive($root, 'retry'); $d = json_encode($r);
is_(($r['after_failure']['status'] ?? '') === 'failed' && (int)($r['after_failure']['attempts'] ?? 0) === 1 && $r['retry_in_future'] === true,
    'uCRM unreachable: the event is failed, one attempt spent, its retry scheduled with backoff', $d);
is_(($r['after_processor']['status'] ?? '') === 'failed' && (int)($r['after_processor']['attempts'] ?? 0) === 1
    && ($r['after_processor']['locked_by'] ?? null) === null && ($r['after_processor']['processed_at'] ?? null) === null,
    'due for retry, it is still not claimed by the processor', $d);
is_(($r['after_retry']['status'] ?? '') === 'done' && $r['clients'] === 1, 'uCRM back: the worker\'s retry succeeds and the client is created', $d);

echo "\nE. The fifth failure makes it a dead letter, said loudly, and the processor's dead-letter pass sees it\n";
$x = $drive($root, 'dead'); $d = json_encode($x);
is_(($x['after_last_failure']['status'] ?? '') === 'dead' && $x['log_never_reached'] === true,
    'the last attempt makes the event dead, and the worker says the lead never reached uCRM', $d);
is_(strpos((string)($x['after_processor']['error'] ?? ''), '[admin alerted]') !== false && ($x['after_processor']['status'] ?? '') === 'dead',
    'the processor\'s dead-letter pass marks it alerted (once) and leaves it dead for a person', $d);

echo "\nS. South Sudan: the 5.18.87 loop, unchanged — defects included, each recorded for its own decision\n";
$sc = $drive($root, 'ss_claim'); $d = json_encode($sc);
is_(($sc['row_crm.lead.sync']['status'] ?? '') === 'done' && $sc['log_unknown_lead_sync'] === true,
    'crm.lead.sync is still acknowledged as an unknown type there, exactly as before (the race docs/65 §Z.5 records)', $d);
is_(($sc['row_ai.reply']['status'] ?? '') === 'pending' && array_key_exists('locked_by', (array)($sc['row_ai.reply'] ?? []))
    && $sc['row_ai.reply']['locked_by'] === null, 'ai.reply is still claimed and released there', $d);
is_(($sc['row_ai.media']['status'] ?? '') === 'done' && $sc['log_unknown_media'] === true,
    'South Sudan keeps its live list, [\'ai.reply\']: ai.media is not on it and is acknowledged as unknown, exactly as on live 5.18.87', $d);
is_(($sc['row_wa.escalation']['status'] ?? '') === 'done' && $sc['log_unknown_escalation'] === true, 'wa.escalation is still logged as unknown there', $d);
is_(($sc['row_wa.send']['status'] ?? '') === 'done' && ($sc['row_install.ready']['status'] ?? '') === 'done'
    && ($sc['row_efris.submit']['status'] ?? '') === 'done', 'every other type is handled as before there', $d);
$ss = $drive($root, 'ss_starve'); $d = json_encode($ss);
is_($ss['own_status'] === 'pending', 'a backlog of 25 ai.reply events still fills that run\'s batch there (the starvation, unchanged)', $d);
$sx = $drive($root, 'ss_dead'); $d = json_encode($sx);
is_(($sx['after_processor']['status'] ?? '') === 'dead' && strpos((string)($sx['after_processor']['error'] ?? ''), '[admin alerted]') === false,
    'a run that claims nothing still returns before the dead-letter pass there', $d);

echo "\nF. Weakened copies are caught\n";
$mutants = [
  ['the processor claims everything again (no exclusion at the claim)', 'cron/event_processor.php',
   "\$events = \$bus->consume(20, '', [], \$_epWorkerOwned);", "\$events = \$bus->consume(20);",
   function () use ($drive) { return [$drive($GLOBALS['__copy'], 'starve'), $drive($GLOBALS['__copy'], 'claim')]; },
   fn(array $m): bool => $m[0]['own_status'] === 'pending' && $m[1]['untouched_crm.lead.sync'] === true],
  ['crm.lead.sync dropped from the worker-owned list', 'cron/event_processor.php',
   "\$_epUg ? ['ai.reply', 'crm.lead.sync'] :", "\$_epUg ? ['ai.reply'] :",
   function () use ($drive) { return [$drive($GLOBALS['__copy'], 'claim'), $drive($GLOBALS['__copy'], 'flow')]; },
   fn(array $m): bool => ($m[0]['row_crm.lead.sync']['status'] ?? '') === 'done' && $m[1]['clients'] === 0],
  ['EventBus ignores the exclusion', 'lib/EventBus.php',
   "            if (\$skip !== []) {\n", "            if (false) {\n",
   function () use ($drive) { return [$drive($GLOBALS['__copy'], 'starve')]; },
   fn(array $m): bool => $m[0]['own_status'] === 'pending'],
  ['wa.escalation logged as unknown again', 'cron/event_processor.php',
   "                if (\$_epUg && \$type === 'wa.escalation') {\n                    \$bus->ack(\$eid);\n                    \$processed++;\n                    break;\n                }\n", "",
   function () use ($drive) { return [$drive($GLOBALS['__copy'], 'claim')]; },
   fn(array $m): bool => $m[0]['log_unknown_escalation'] === true],
  ['the early return is back: a run that claims nothing skips the dead-letter pass', 'cron/event_processor.php',
   "\$events = \$bus->consume(20, '', [], \$_epWorkerOwned);\n", "\$events = \$bus->consume(20, '', [], \$_epWorkerOwned);\nif (empty(\$events)) return;\n",
   function () use ($drive) { return [$drive($GLOBALS['__copy'], 'dead')]; },
   fn(array $m): bool => ($m[0]['after_processor']['status'] ?? '') === 'dead'
       && strpos((string)($m[0]['after_processor']['error'] ?? ''), '[admin alerted]') === false],
  ['the country gate ignored: South Sudan gets the Uganda loop', 'cron/event_processor.php',
   "    \$_epUg = StaffJobsGate::applies(is_array(\$config ?? null) ? \$config : [], \$_epDir);\n", "    \$_epUg = true;\n",
   function () use ($drive) { return [$drive($GLOBALS['__copy'], 'ss_claim'), $drive($GLOBALS['__copy'], 'ss_dead')]; },
   fn(array $m): bool => ($m[0]['row_crm.lead.sync']['status'] ?? '') === 'pending'
       || strpos((string)($m[1]['after_processor']['error'] ?? ''), '[admin alerted]') !== false],
  ['the country gate shut: Uganda loses the fix', 'cron/event_processor.php',
   "    \$_epUg = StaffJobsGate::applies(is_array(\$config ?? null) ? \$config : [], \$_epDir);\n", "    \$_epUg = false;\n",
   function () use ($drive) { return [$drive($GLOBALS['__copy'], 'claim')]; },
   fn(array $m): bool => ($m[0]['row_crm.lead.sync']['status'] ?? '') === 'done'],
  ['ai.media introduced into South Sudan\'s list', 'cron/event_processor.php',
   "\$_epWorkerOwned = \$_epUg ? ['ai.reply', 'crm.lead.sync'] : ['ai.reply'];", "\$_epWorkerOwned = \$_epUg ? ['ai.reply', 'crm.lead.sync'] : ['ai.reply', 'ai.media'];",
   function () use ($drive) { return [$drive($GLOBALS['__copy'], 'ss_claim')]; },
   fn(array $m): bool => ($m[0]['row_ai.media']['status'] ?? '') === 'pending' && $m[0]['log_unknown_media'] === false],
  ['ai.media introduced into Uganda\'s list', 'cron/event_processor.php',
   "\$_epWorkerOwned = \$_epUg ? ['ai.reply', 'crm.lead.sync'] : ['ai.reply'];", "\$_epWorkerOwned = \$_epUg ? ['ai.reply', 'ai.media', 'crm.lead.sync'] : ['ai.reply'];",
   function () use ($drive) { return [$drive($GLOBALS['__copy'], 'claim')]; },
   fn(array $m): bool => $m[0]['untouched_ai.media'] === true && $m[0]['log_unknown_media'] === false],
];
foreach ($mutants as $i => [$label, $rel, $old, $new, $runIt, $caught]) {
    [$copy, $n] = sj_weakened_copy($root, $rel, $old, $new);
    is_($n === 1, 'mutant ' . ($i + 1) . ": the anchor is unique in $rel (the copy is only weakened when it is)", "count $n");
    $GLOBALS['__copy'] = $copy;
    $m = $runIt();
    $ok = true; foreach ($m as $one) if (($one['_rc'] ?? 1) !== 0) $ok = false;
    is_($ok && $caught($m) === true, 'mutant ' . ($i + 1) . " is caught: $label", substr(json_encode($m), 0, 700));
    exec('rm -rf ' . escapeshellarg($copy));
}
echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
