<?php
declare(strict_types=1);
/**
 * test_notify_schedule_health.php — 5.18.54, docs/46 row 43 (N-16): the two places a person looks to see whether the
 * scheduled jobs run say what master.php recorded.
 *
 * Measured before the fix, in this suite's own sandbox:
 *   - System Health's "Scheduler (cron)" row reads the age of <data>/master_schedule.json. master.php saves its record
 *     through SqliteStore, which writes the database and no file, so the row said "has never run" while master ran;
 *   - tools/cron_status.php took its zone from a date_default_timezone_set('…') literal in master.php. master.php now
 *     calls dn_tz_apply(), so the headline came out in the tool's own zone, beside rows master stamped in its own;
 *   - the same tool lists every job registered in master.php, so a job gated to Uganda (customer_reminders,
 *     notify_retry, notify_watchdog) would appear on South Sudan, where nobody runs it — notify_retry as NEVER RUN.
 *
 *    1. System Health, Uganda: the scheduler row from the database, and the watchdog's conditions beside it
 *    2. System Health, South Sudan: the 5.18.53 row, verbatim, and no new row
 *    3. cron_status, Uganda: master's zone, and the gated jobs listed
 *    4. cron_status, South Sudan: the same jobs, in the same order, as the 5.18.53 tool listed from 5.18.53's master.php
 *    5. weakened copies, each caught
 *
 * The real page (through public.php, signed in as an administrator) and the real tool, in a sandbox; nothing leaves
 * the machine. `--no-mutants` skips 5.
 */
$root = dirname(__DIR__);
require_once __DIR__ . '/fixtures/staff_jobs_sandbox.php';
$withMutants = !in_array('--no-mutants', $argv, true);

$pass = 0; $fail = 0;
function is_(bool $c, string $m, string $d = ''): void {
    global $pass, $fail;
    if ($c) { $pass++; echo "  ok   {$m}\n"; }
    else    { $fail++; echo "  FAIL {$m}" . ($d !== '' ? "\n       {$d}" : '') . "\n"; }
}

/** The 5.18.53 release, by its commit: its cron_status.php and the master.php it read. */
const SH_5_18_53 = '6b71ea6';

/** master.php's record, as master writes it: job => ago (seconds) or 'never'. */
function sh_schedule(array $jobs): array
{
    $out = [];
    foreach ($jobs as $job => $ago) {
        $t = time() - (is_int($ago) ? $ago : 0);
        $out[$job] = ['last_run' => $ago === 'never' ? 1 : $t, 'last_run_at' => date('Y-m-d H:i:s', $t), 'duration_ms' => 1200];
    }
    return $out;
}

/** One System Health row, read from the page: [state, detail], or null when the page has no such row. */
function sh_row(string $html, string $name): ?array
{
    $re = '#<span class="n">' . preg_quote($name, '#') . '</span>\s*<span class="d">(.*?)</span>\s*<span class="sh-pill (sh-ok|sh-w|sh-b)">#s';
    if (!preg_match($re, $html, $m)) return null;
    return [['sh-ok' => 'ok', 'sh-w' => 'warn', 'sh-b' => 'bad'][$m[2]], html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5)];
}

function sh_show($v): string { return substr((string)json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE), 0, 700); }

/** The System Health page of one sandbox, for one schedule. */
function sh_page(SjSandbox $s, array $schedule): string
{
    $s->store()->save('master_schedule.json', $schedule);
    return $s->page('admin', 'page=dashboard&tab=system_health');
}

/** A sandbox for one country, an administrator signed in. */
function sh_sandbox(string $tree, string $tenant): SjSandbox
{
    $base = $tenant === 'uganda' ? ['tenant_profile' => 'uganda', 'timezone' => 'Africa/Kampala'] : [];
    $s = SjSandbox::start($tree, $base, 'sh' . substr($tenant, 0, 2));
    $s->staff('admin', ['name' => 'Sandbox Admin', 'email' => 'admin@example.test', 'role' => 'admin', 'is_admin' => true]);
    $s->login('admin', 'admin@example.test', 'sj-password-1');
    return $s;
}

/** The job names cron_status lists (with --all), in its order; and its headline zone. */
function sh_status(string $out): array
{
    preg_match_all('/^\s+(\d+)\s+([a-z0-9_]+)\s+\d+s\*?\s/m', $out, $m, PREG_SET_ORDER);
    $jobs = [];
    foreach ($m as $r) $jobs[(int)$r[1]] = $r[2];
    ksort($jobs);
    preg_match('/ago, ([A-Za-z_\/+-]+)\)/', $out, $z);
    return ['jobs' => array_values($jobs), 'zone' => $z[1] ?? ''];
}

// ── The cases, as functions of the plugin tree, so a weakened copy runs the very same ones ─────────────────────────

$healthUganda = function (string $tree): array {
    $s = sh_sandbox($tree, 'uganda');
    $ran    = sh_page($s, sh_schedule(['starlink_alive' => 60, 'event_processor' => 60, 'quote_wa' => 60]));
    $late   = sh_page($s, sh_schedule(['starlink_alive' => 60, 'event_processor' => 60, 'quote_wa' => 7200]));
    $never  = sh_page($s, []);
    $file   = is_file($s->data . '/master_schedule.json');
    $s->stop();
    return ['ran' => [sh_row($ran, 'Scheduler (cron)'), sh_row($ran, 'Notification jobs')],
            'late' => [sh_row($late, 'Scheduler (cron)'), sh_row($late, 'Notification jobs')],
            'never' => [sh_row($never, 'Scheduler (cron)'), sh_row($never, 'Notification jobs')], 'file' => $file];
};

$healthSouthSudan = function (string $tree): array {
    $s = sh_sandbox($tree, 'south-sudan');
    $ran = sh_page($s, sh_schedule(['starlink_alive' => 60, 'event_processor' => 60, 'quote_wa' => 7200]));
    $s->stop();
    return [sh_row($ran, 'Scheduler (cron)'), sh_row($ran, 'Notification jobs')];
};

$statusOf = function (string $tree, string $tenant, ?string $oldCommit = null): array {
    $s = sh_sandbox($tree, $tenant);
    $s->store()->save('master_schedule.json', sh_schedule(['starlink_alive' => 60, 'event_processor' => 60, 'quote_wa' => 60]));
    if ($oldCommit !== null) {
        // The 5.18.53 tool reading the 5.18.53 master.php, in this sandbox's own copy, against the same data.
        foreach (['tools/cron_status.php', 'cron/master.php'] as $rel) {
            $src = shell_exec('git -C ' . escapeshellarg(dirname(__DIR__)) . ' show ' . escapeshellarg($oldCommit . ':dishnet-hybrid-sudan/' . $rel) . ' 2>/dev/null');
            if (!is_string($src) || $src === '') { $s->stop(); return ['error' => "no {$rel} at {$oldCommit}"]; }
            file_put_contents($s->plug . '/' . $rel, $src);
        }
    }
    [$rc, $out] = $s->run('tools/cron_status.php', ['--all']);
    $s->stop();
    return ['rc' => $rc, 'out' => $out] + sh_status($out);
};

// ══════════════════════════════════════════════════════════════════════════════
$tree = $root;

echo "\n1. System Health, Uganda — the scheduler row from the database; the watchdog's conditions beside it\n";
$h = $healthUganda($tree);
is_($h['file'] === false, 'master.php\'s record is in the database: no master_schedule.json file exists (what the old row read)', sh_show($h));
is_(($h['ran'][0][0] ?? '') === 'ok' && strpos((string)($h['ran'][0][1] ?? ''), 'last ran under 2 minutes ago') !== false,
    'master ran a minute ago: "Scheduler (cron)" reads ok, last ran under 2 minutes ago', sh_show($h['ran']));
is_(($h['ran'][1][0] ?? '') === 'ok' && strpos((string)($h['ran'][1][1] ?? ''), 'none stopped or unfinished') !== false,
    'no job stopped: "Notification jobs" reads ok', sh_show($h['ran']));
is_(($h['late'][1][0] ?? '') === 'bad' && strpos((string)($h['late'][1][1] ?? ''), 'quote_wa (last ran 2 h ago)') !== false,
    'quote_wa silent for two hours: "Notification jobs" names it, as a problem', sh_show($h['late']));
is_(($h['late'][0][0] ?? '') === 'ok', 'while master itself still ran a minute ago', sh_show($h['late']));
is_(($h['never'][0][0] ?? '') === 'warn' && strpos((string)($h['never'][0][1] ?? ''), 'has never run — uCRM starts it') !== false,
    'no record at all: "has never run", and what starts it on a uCRM plugin', sh_show($h['never']));

echo "\n2. System Health, South Sudan — the 5.18.53 row, verbatim; no new row\n";
$ss = $healthSouthSudan($tree);
is_(($ss[0][0] ?? '') === 'warn' && ($ss[0][1] ?? '') === 'has never run — install the master.php crontab entry',
    'the 5.18.53 file check and its words, although master ran a minute ago (the defect stays there: docs/46 §E)', sh_show($ss));
is_($ss[1] === null, 'no "Notification jobs" row', sh_show($ss));

echo "\n3. cron_status, Uganda — master's zone, and the gated jobs listed\n";
$u = $statusOf($tree, 'uganda');
is_($u['rc'] === 0 && $u['zone'] === 'Africa/Kampala', 'the headline is in Africa/Kampala, the zone master stamped its rows in',
    sh_show(['rc' => $u['rc'], 'zone' => $u['zone'], 'out' => substr($u['out'], 0, 400)]));
foreach (['customer_reminders', 'notify_retry'] as $job) {
    is_(in_array($job, $u['jobs'], true), "{$job} is listed (it runs here)", sh_show($u['jobs']));
}

echo "\n4. cron_status, South Sudan — the jobs the 5.18.53 tool listed, no more\n";
$s53 = $statusOf($tree, 'south-sudan', SH_5_18_53);
$sNow = $statusOf($tree, 'south-sudan');
is_(!isset($s53['error']) && count($s53['jobs']) > 20, 'the 5.18.53 tool and master.php, from git, list the jobs', sh_show($s53['error'] ?? $s53['jobs']));
is_($sNow['jobs'] === $s53['jobs'], 'the same jobs in the same order: no job gated to Uganda appears',
    sh_show(['added' => array_values(array_diff($sNow['jobs'], $s53['jobs'] ?? [])), 'missing' => array_values(array_diff($s53['jobs'] ?? [], $sNow['jobs']))]));
is_($sNow['zone'] === $s53['zone'], 'and the headline zone is the one 5.18.53 printed (' . $s53['zone'] . ')', sh_show([$sNow['zone'], $s53['zone']]));

// ── 5. Weakened copies ─────────────────────────────────────────────────────────────────────────────────────────────
echo "\n5. Weakened copies, each caught\n";
$mutants = [
    'System Health reads the file again on Uganda' => [[['tabs/admin/system_health.php',
        "if (NotifyGate::applies(NotifyGate::WATCHDOG, \$_hCfg, \$GLOBALS['dataDir'] ?? null)) {\n", "if (false) {\n"]],
        fn(string $t) => ($healthUganda($t)['ran'][0][0] ?? '') !== 'ok', '"has never run" while master ran'],
    'the watchdog\'s conditions not shown' => [[['tabs/admin/system_health.php',
        "    \$_hHeld = NotifyWatchdog::check(\$_hSch, \$_hPdo, time(), (array)(\$store->load('kyc_config.json') ?? []), \$_hCfg);\n",
        "    \$_hHeld = [];\n"]],
        fn(string $t) => ($healthUganda($t)['late'][1][0] ?? '') !== 'bad', 'the silent quote_wa was not named'],
    'cron_status keeps its own zone on Uganda' => [[['tools/cron_status.php', "    dn_tz_apply();\n", ''],
        ['tools/cron_status.php', "    require_once \$root . '/lib/timezone.php';\n", '']],
        fn(string $t) => $statusOf($t, 'uganda')['zone'] !== 'Africa/Kampala', 'the headline was not in Kampala time'],
    'cron_status lists jobs gated off' => [[['tools/cron_status.php',
        "        if (preg_match(\"/'gate'\\s*=>\\s*'([a-z_]+)'/\", \$line, \$g) && !NotifyGate::applies(\$g[1], \$gateCfg, \$dataDir)) continue;\n", '']],
        fn(string $t) => in_array('notify_retry', $statusOf($t, 'south-sudan')['jobs'], true), 'South Sudan listed notify_retry'],
    'cron_status drops every gated job' => [[['tools/cron_status.php',
        "&& !NotifyGate::applies(\$g[1], \$gateCfg, \$dataDir)) continue;", ") continue;"]],
        fn(string $t) => !in_array('notify_retry', $statusOf($t, 'uganda')['jobs'], true), 'Uganda did not list notify_retry'],
];
foreach ($withMutants ? $mutants : [] as $name => [$edits, $caught, $why]) {
    [$t, $n] = sj_weakened_copy($root, $edits[0][0], $edits[0][1], $edits[0][2]);
    $okAnchors = $n === 1; $miss = $okAnchors ? '' : $edits[0][0];
    foreach (array_slice($edits, 1) as [$rel, $o_, $n_]) {
        $src = (string)file_get_contents($t . '/' . $rel);
        if (substr_count($src, $o_) !== 1) { $okAnchors = false; $miss = $rel; break; }
        file_put_contents($t . '/' . $rel, str_replace($o_, $n_, $src));
    }
    if (!$okAnchors) { is_(false, "caught: {$name}", "the anchor was not found exactly once in {$miss}"); exec('rm -rf ' . escapeshellarg($t)); continue; }
    $ok = (bool)$caught($t);
    is_($ok, "caught: {$name}" . ($ok ? " ({$why})" : ''));
    exec('rm -rf ' . escapeshellarg($t));
}

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail ? 1 : 0);
