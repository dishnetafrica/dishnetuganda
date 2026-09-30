<?php
declare(strict_types=1);
/**
 * test_notify_watchdog.php — 5.18.54, docs/46 row 32: an administrator hears when a notification job stops, when a job
 * dies in the middle of a run, or when failed messages pile up — once per condition in six hours, by WhatsApp and by a
 * line in uCRM's log for the plugin.
 *
 * The real cron/notify_watchdog.php, from a copy of the plugin (tests/fixtures/notify_watchdog_probe.php), with master's
 * schedule record written as master writes it, against the socket-level fake Evolution (the administrator's WhatsApp):
 *
 *   1. a stopped notification job: one alert naming it; a job within its limit and a job never run are not named
 *   2. a job that started and never finished: one alert naming it; the watchdog's own running record is not
 *   3. failed messages piling up: 10 in 24 hours alerts, 9 does not; older, sent and dismissed rows do not count
 *   4. the cooldown: a second run is silent; six hours on, it alerts again
 *   5. all well: nothing sent, nothing logged
 *   6. the administrator's WhatsApp fails: the log line is still written
 *   7. South Sudan: nothing, as in 5.18.53
 *   8. master.php: registered second, gated, and every watched job is one master runs, with a limit it can meet
 *   9. weakened copies, each caught
 *
 * Nothing leaves the machine. `--no-mutants` skips 9.
 */
$root = dirname(__DIR__);
$withMutants = !in_array('--no-mutants', $argv, true);

$pass = 0; $fail = 0;
function is_(bool $c, string $m, string $d = ''): void {
    global $pass, $fail;
    if ($c) { $pass++; echo "  ok   {$m}\n"; }
    else    { $fail++; echo "  FAIL {$m}" . ($d !== '' ? "\n       {$d}" : '') . "\n"; }
}

/** A plain copy of the plugin: the job finds its data directory beside it, as on the server. */
function wd_tree(string $root): string
{
    $tmp = sys_get_temp_dir() . '/wd-tree-' . getmypid() . '-' . bin2hex(random_bytes(3));
    mkdir($tmp, 0700, true);
    foreach (scandir($root) ?: [] as $e) {
        if ($e === '.' || $e === '..' || in_array($e, ['docs', 'prototype', 'dishnet-mikrotik-control-plane', 'data', '.git'], true)) continue;
        exec('cp -R ' . escapeshellarg($root . '/' . $e) . ' ' . escapeshellarg($tmp . '/') . ' 2>/dev/null');
    }
    return $tmp;
}
function wd_data(string $tree): string { return dirname($tree) . '/.' . basename($tree) . '-data'; }
function wd_fresh(string $tree): void
{
    exec('rm -rf ' . escapeshellarg(wd_data($tree)) . ' ' . escapeshellarg($tree . '/data'));
    mkdir(wd_data($tree), 0700, true);
}
function wd_drop(string $tree): void { exec('rm -rf ' . escapeshellarg($tree) . ' ' . escapeshellarg(wd_data($tree))); }

/** The fake Evolution, answering each connection as the next mode says. */
function wd_fake(string $modes): array
{
    $dir = sys_get_temp_dir() . '/wd-f-' . getmypid() . '-' . bin2hex(random_bytes(3));
    mkdir($dir, 0700, true);
    $tr = $dir . '/transcript.json';
    foreach (range(0, 9) as $slot) {
        $p = 9500 + ((getmypid() + $slot * 7 + random_int(0, 6)) % 90);
        $proc = proc_open(sprintf('exec %s %s %d %s %s 60', escapeshellarg(PHP_BINARY),
                    escapeshellarg(dirname(__DIR__) . '/tests/fixtures/fake_evo_raw.php'), $p, escapeshellarg($tr), escapeshellarg($modes)),
                [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', $dir . '/fake.err', 'w']], $pipes);
        for ($i = 0; $i < 40 && !is_file($tr); $i++) usleep(50000);
        if (is_file($tr)) return ['proc' => $proc, 'tr' => $tr, 'dir' => $dir, 'url' => "http://127.0.0.1:{$p}"];
        proc_terminate($proc); proc_close($proc);
    }
    throw new \RuntimeException('the fake Evolution did not start');
}
/** The WhatsApp texts the fake received. */
function wd_texts(array $f): array
{
    $out = [];
    foreach (json_decode((string)@file_get_contents($f['tr']), true) ?: [] as $x) {
        if (!empty($x['http'])) $out[] = (string)(json_decode((string)($x['body'] ?? ''), true)['text'] ?? '');
    }
    return $out;
}
function wd_stop(array $f): void { @proc_terminate($f['proc']); @proc_close($f['proc']); exec('rm -rf ' . escapeshellarg($f['dir'])); }

/** One probe step, by the tree's own probe; its last line is its answer. */
function wd_probe(string $tree, string $tenant, string $step, string $url, string $copies = ''): array
{
    $cmd = sprintf('%s %s %s %s %s %s %s %s 2>&1', escapeshellarg(PHP_BINARY),
        escapeshellarg($tree . '/tests/fixtures/notify_watchdog_probe.php'),
        escapeshellarg($tree), escapeshellarg($tenant), escapeshellarg($step), escapeshellarg($url), escapeshellarg(wd_data($tree)),
        escapeshellarg($copies));
    $lines = [];
    exec($cmd, $lines);
    $last = '';
    foreach (array_reverse($lines) as $l) if (trim($l) !== '') { $last = $l; break; }
    return (json_decode($last, true) ?: []) + ['_raw' => mb_substr(implode("\n", $lines), -700)];
}
function wd_show(array $p): string { unset($p['_raw']); return json_encode($p, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); }

/** A scenario: the schedule, the queue, then $runs runs of the job. Returns the last run, and the texts sent. */
function wd_case(string $tree, string $tenant, array $schedule, array $queue = [], int $runs = 1, string $modes = 'ok', array $between = [],
                 string $copies = ''): array
{
    wd_fresh($tree);
    $f = wd_fake($modes);
    wd_probe($tree, $tenant, 'schedule:' . json_encode($schedule), $f['url'], $copies);
    if ($queue) wd_probe($tree, $tenant, 'seed:' . json_encode($queue), $f['url']);
    $r = null; $all = [];
    for ($i = 0; $i < $runs; $i++) {
        if (isset($between[$i])) wd_probe($tree, $tenant, $between[$i], $f['url']);
        $all[] = $r = wd_probe($tree, $tenant, 'run', $f['url']);
    }
    $texts = wd_texts($f);
    wd_stop($f);
    return ['last' => $r, 'runs' => $all, 'texts' => $texts, 'log' => $r['log'] ?? []];
}
function wd_has(array $lines, string $needle): int { return count(array_filter($lines, fn($l) => strpos($l, $needle) !== false)); }

$fresh = ['quote_wa' => ['ago' => 100], 'inv_notify' => ['ago' => 600], 'notify_watchdog' => ['ago' => 1, 'ms' => -1]];

// ── The cases, as functions of the plugin tree, so a weakened copy runs the very same ones ─────────────────────────
$caseStopped = function (string $tree) use ($fresh): array {
    $c = wd_case($tree, 'uganda', ['inv_notify' => ['ago' => 5 * 3600], 'staff_jobs' => ['ago' => 'never']] + $fresh);
    $t = $c['texts'];
    return [
        [count($t) === 1 && strpos($t[0] ?? '', 'inv_notify (last ran 5 h ago)') !== false && strpos($t[0], 'stopped running') !== false,
         'one WhatsApp to the administrator, naming inv_notify, 5 hours without a run', json_encode($t)],
        [wd_has($c['log'], 'inv_notify (last ran 5 h ago)') === 1, 'and one line in uCRM\'s log for the plugin', json_encode($c['log'])],
        [strpos($t[0] ?? '', 'quote_wa') === false, 'a job within its limit is not named', json_encode($t)],
        [strpos($t[0] ?? '', 'staff_jobs') === false, 'nor one never run (master seeds those at 1)', json_encode($t)],
    ];
};
$caseUnfinished = function (string $tree) use ($fresh): array {
    $c = wd_case($tree, 'uganda', ['gdrive_backup' => ['ago' => 3600, 'ms' => -1]] + $fresh);
    $t = $c['texts'];
    return [
        [count($t) === 1 && strpos($t[0] ?? '', 'started and never finished: gdrive_backup') !== false,
         'a job still at -1 an hour on: one alert, naming it', json_encode($t)],
        [strpos($t[0] ?? '', 'notify_watchdog') === false, 'the watchdog\'s own record, -1 while it runs, is not named', json_encode($t)],
        [wd_has($c['log'], 'gdrive_backup') === 1, 'one log line', json_encode($c['log'])],
    ];
};
$casePile = function (string $tree, int $n) use ($fresh): array {
    $q = array_fill(0, $n - 1, ['status' => 'failed']);
    $q[] = ['status' => 'exhausted'];
    $q[] = ['status' => 'failed', 'age' => 30 * 3600];      // older than 24 hours
    $q[] = ['status' => 'sent'];
    $q[] = ['status' => 'dismissed'];
    return wd_case($tree, 'uganda', $fresh, $q);
};
$caseCooldown = function (string $tree): array {
    $c = wd_case($tree, 'uganda', ['inv_notify' => ['ago' => 5 * 3600], 'notify_watchdog' => ['ago' => 1, 'ms' => -1]], [], 3, 'ok',
                 [2 => 'shift:' . (6 * 3600)]);
    return [
        [count($c['texts']) === 2, 'two runs in a row send one alert; six hours on, a second', json_encode($c['texts'])],
        [wd_has($c['log'], 'inv_notify') === 2, 'and write two log lines, not three', json_encode($c['log'])],
        [strpos((string)($c['runs'][1]['run'] ?? ''), 'already alerted: overdue') !== false, 'the silent run says it already alerted',
         wd_show($c['runs'][1] ?? [])],
    ];
};
$caseWell = function (string $tree) use ($fresh): array {
    $c = wd_case($tree, 'uganda', $fresh, [['status' => 'failed']]);
    return [
        [$c['texts'] === [] && $c['log'] === [] && strpos((string)($c['last']['run'] ?? ''), 'all well') !== false,
         'nothing sent, nothing logged, "all well"', wd_show($c['last'])],
    ];
};
$caseNoWhatsApp = function (string $tree): array {
    $c = wd_case($tree, 'uganda', ['inv_notify' => ['ago' => 5 * 3600], 'notify_watchdog' => ['ago' => 1, 'ms' => -1]], [], 1, 'refuse');
    return [
        [wd_has($c['log'], 'inv_notify') === 1, 'WhatsApp refused the alert: the log line is written all the same', json_encode($c['log'])],
    ];
};
$caseTransport = function (string $tree) use ($fresh): array {
    $c = wd_case($tree, 'uganda', $fresh, [], 1, 'ok', [], 'file-only');
    $t = $c['texts'];
    return [
        [count($t) === 1 && strpos($t[0] ?? '', 'scheduled jobs cannot send WhatsApp') !== false,
         'the database copy of the settings has no WhatsApp connection, the file has one: one alert', json_encode($t)],
        [wd_has($c['log'], 'scheduled jobs cannot send WhatsApp') === 1, 'and one log line', json_encode($c['log'])],
    ];
};
$caseTrace = function (string $tree, string $tenant): array {
    wd_fresh($tree);
    $p = wd_probe($tree, $tenant, 'bare-send', 'http://127.0.0.1:9');
    return ['wa_log' => $p['wa_log'] ?? [], 'probe' => $p];
};
$caseSouthSudan = function (string $tree): array {
    $c = wd_case($tree, 'south-sudan', ['inv_notify' => ['ago' => 5 * 3600], 'gdrive_backup' => ['ago' => 3600, 'ms' => -1]],
                 array_fill(0, 12, ['status' => 'failed']));
    return [
        [strpos((string)($c['last']['run'] ?? ''), 'Not this install') !== false && $c['texts'] === [] && $c['log'] === [],
         'the job says it is not this install\'s; nothing sent, nothing logged', wd_show($c['last'])],
    ];
};

// ══════════════════════════════════════════════════════════════════════════════
$tree = wd_tree($root);

echo "\n1. Uganda — a stopped notification job\n";
foreach ($caseStopped($tree) as [$ok, $m, $d]) is_($ok, $m, $d);

echo "\n2. Uganda — a job that started and never finished\n";
foreach ($caseUnfinished($tree) as [$ok, $m, $d]) is_($ok, $m, $d);

echo "\n3. Uganda — failed messages piling up\n";
$c10 = $casePile($tree, 10);
is_(count($c10['texts']) === 1 && strpos($c10['texts'][0] ?? '', '10 WhatsApp messages failed in the last 24 hours') !== false,
    'ten waiting (failed or exhausted) in 24 hours: one alert, with the count', json_encode($c10['texts']));
$c9 = $casePile($tree, 9);
is_($c9['texts'] === [] && $c9['log'] === [], 'nine: none (a row older than 24 hours, a sent one and a dismissed one do not count)', json_encode($c9['texts']));

echo "\n4. Uganda — once in six hours\n";
foreach ($caseCooldown($tree) as [$ok, $m, $d]) is_($ok, $m, $d);

echo "\n5. Uganda — all well\n";
foreach ($caseWell($tree) as [$ok, $m, $d]) is_($ok, $m, $d);

echo "\n6. Uganda — the WhatsApp itself fails\n";
foreach ($caseNoWhatsApp($tree) as [$ok, $m, $d]) is_($ok, $m, $d);

echo "\n6b. Uganda — the scheduled jobs' copy of the settings has no WhatsApp (docs/45 §2.3)\n";
foreach ($caseTransport($tree) as [$ok, $m, $d]) is_($ok, $m, $d);

$tu = $caseTrace($tree, 'uganda');
is_(count($tu['wa_log']) === 1 && strpos($tu['wa_log'][0], 'ops_payment_received') !== false
    && strpos($tu['wa_log'][0], 'no WhatsApp connection is set up for the accounts sender') !== false,
    'a receipt sent with no WhatsApp connection leaves one log line (two sends, one line a day), where it left nothing',
    wd_show($tu['probe']));
$ts = $caseTrace($tree, 'south-sudan');
is_($ts['wa_log'] === [], 'South Sudan: nothing, as in 5.18.53', wd_show($ts['probe']));

echo "\n7. South Sudan — nothing, as in 5.18.53\n";
foreach ($caseSouthSudan($tree) as [$ok, $m, $d]) is_($ok, $m, $d);
wd_drop($tree);

echo "\n8. master.php\n";
$master = (string)file_get_contents($root . '/cron/master.php');
$list = substr($master, (int)strpos($master, '$_m_jobs = ['));
preg_match_all("/^\s*'([a-z_]+)'\s*=>\s*\[\s*'interval'\s*=>\s*([^,]+),(.*)$/m", $list, $m, PREG_SET_ORDER);
$order = array_map(fn($x) => $x[1], $m);
$jobs = [];
foreach ($m as $x) $jobs[$x[1]] = ['interval' => trim($x[2]), 'rest' => $x[3]];
is_(array_slice($order, 0, 2) === ['starlink_alive', 'notify_watchdog'],
    'the watchdog is dispatched second, straight after the keep-alive: a job that spends the budget cannot starve it', json_encode(array_slice($order, 0, 3)));
is_(strpos($jobs['notify_watchdog']['rest'] ?? '', "'gate' => 'watchdog'") !== false
    && strpos($jobs['notify_watchdog']['rest'] ?? '', "__DIR__ . '/notify_watchdog.php'") !== false, 'gated to the watchdog fix', $jobs['notify_watchdog']['rest'] ?? '');
require_once $root . '/lib/NotifyGate.php';
require_once $root . '/lib/NotifyWatchdog.php';
is_(NotifyGate::WATCHDOG === 'watchdog' && NotifyWatchdog::SELF === 'notify_watchdog', 'the gate and the job name agree');
$missing = array_values(array_diff(array_keys(NotifyWatchdog::JOBS), $order));
is_($missing === [], 'every watched job is one master.php runs', implode(', ', $missing));
$tight = [];
foreach (NotifyWatchdog::JOBS as $job => $limit) {
    $iv = $jobs[$job]['interval'] ?? '';
    if (ctype_digit($iv) && (int)$iv < 86400 && strpos($jobs[$job]['rest'] ?? '', 'run_hour') === false && $limit < max(3 * (int)$iv, 1800)) $tight[] = $job;
    if (strpos($jobs[$job]['rest'] ?? '', 'run_hour') !== false && $limit < 26 * 3600) $tight[] = $job;
}
is_($tight === [], 'every limit leaves a job at least three of its own intervals (26 hours for a daily one) before it is overdue', implode(', ', $tight));

// ══════════════════════════════════════════════════════════════════════════════
echo "\n9. Weakened copies, each caught\n";
$failedWhere = function (array $triples, string $what): bool {
    foreach ($triples as [$ok, $m]) if (!$ok && strpos($m, $what) !== false) return true;
    return false;
};
$mutants = [
    'a stopped job not noticed' => [[['lib/NotifyWatchdog.php',
        "            if (\$now - \$last > \$limit) \$late[] =", "            if (false) \$late[] ="]],
        fn(string $t) => $failedWhere($caseStopped($t), 'naming inv_notify'), 'no alert for inv_notify'],
    'a job never run counted as stopped' => [[['lib/NotifyWatchdog.php',
        "            if (\$last <= 1) continue;", "            if (\$last < 0) continue;"]],
        fn(string $t) => $failedWhere($caseStopped($t), 'never run'), 'staff_jobs, never run, was named'],
    'the watchdog reports itself' => [[['lib/NotifyWatchdog.php',
        "            if (\$job === self::SELF || !is_array(\$rec)) continue;", "            if (!is_array(\$rec)) continue;"]],
        fn(string $t) => $failedWhere($caseWell($t), 'nothing sent'), 'an alert about its own running record'],
    'no cooldown' => [[['lib/NotifyWatchdog.php',
        "            if (!\$ns->dedupMark('WATCHDOG:' . \$c['key'] . ':' . intdiv(\$now, self::COOLDOWN_SEC))) {",
        "            if (false) {"]],
        fn(string $t) => $failedWhere($caseCooldown($t), 'send one alert'), 'every run alerted'],
    'no log line' => [[['lib/NotifyWatchdog.php', "            \\PluginLog::write('watchdog', \$text);\n", '']],
        fn(string $t) => $failedWhere($caseNoWhatsApp($t), 'log line'), 'nothing in the log when the WhatsApp failed'],
    'the pile threshold off by one' => [[['lib/NotifyWatchdog.php', "                if (\$n >= self::PILE) {", "                if (\$n > self::PILE) {"]],
        fn(string $t) => count($casePile($t, 10)['texts']) === 0, 'ten waiting raised nothing'],
    'the settings copies not compared' => [[['lib/NotifyWatchdog.php',
        "        if (self::hasTransport(\$fileConfig) && !self::hasTransport(\$dbConfig)) {", "        if (false) {"]],
        fn(string $t) => $failedWhere($caseTransport($t), 'one alert'), 'no alert for the empty database copy'],
    'a send with no connection silent again' => [[['lib/NotificationService.php',
        "        if (!\$this->enabled && !\$this->evoAvailable(\$sender)) { \$this->noTransport(\$sender, \$event); return; }\n\n        [\$to, \$asGiven]",
        "        if (!\$this->enabled && !\$this->evoAvailable(\$sender)) return;\n\n        [\$to, \$asGiven]"]],
        fn(string $t) => $caseTrace($t, 'uganda')['wa_log'] === [], 'no log line for the lost receipt'],
    'the no-connection trace written at every send' => [[['lib/NotificationService.php',
        "            if (!\$this->dedupMark('NOTRANSPORT:' . \$sender . ':' . date('Y-m-d'))) return;\n", '']],
        fn(string $t) => count($caseTrace($t, 'uganda')['wa_log']) === 2, 'two sends, two lines: both reached the trace'],
    'the job not gated' => [[['cron/notify_watchdog.php',
        "if (!NotifyGate::applies(NotifyGate::WATCHDOG, \$config, \$dataDir)) {\n", "if (false) {\n"]],
        fn(string $t) => $failedWhere($caseSouthSudan($t), 'not this install'), 'South Sudan was alerted'],
];
foreach ($withMutants ? $mutants : [] as $name => [$edits, $caught, $why]) {
    $t = wd_tree($root);
    $okAnchors = true; $miss = '';
    foreach ($edits as [$rel, $o_, $n_]) {
        $src = (string)file_get_contents($t . '/' . $rel);
        if (substr_count($src, $o_) !== 1) { $okAnchors = false; $miss = $rel; break; }
        file_put_contents($t . '/' . $rel, str_replace($o_, $n_, $src));
    }
    if (!$okAnchors) { is_(false, "caught: {$name}", "the anchor was not found exactly once in {$miss}"); wd_drop($t); continue; }
    $ok = (bool)$caught($t);
    is_($ok, "caught: {$name}" . ($ok ? " ({$why})" : ''));
    wd_drop($t);
}

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail ? 1 : 0);
