<?php
declare(strict_types=1);
/**
 * tests/test_main_auto_pull_retired.php — 5.18.93
 *
 * main.php, the five-minute tick uCRM runs, carried a nightly "auto-pull" of every
 * uCRM client (ucrm_auto_pull_hour, default 03:00). It never completed. It called
 * $crmGet, which only the inline wallet sync defines, and only on a run whose interval
 * has passed; on every other run in the pull hour the closure was null, and the
 * server's log showed "Uncaught Error: Value of type null is not callable … main.php:484"
 * 12 times on the night of 10–11 Oct — once per five-minute tick of the pull hour,
 * because the run that dies never records that it ran. On a run where the wallet sync had defined it, the pull fetched the
 * clients and died at line 509 creating \DishNet\SqliteStore, a class that does not
 * exist (SqliteStore is global). 5.18.93 retires the block; nothing else replaces it.
 *
 * This test runs the REAL main.php in a throwaway copy at 03:xx, the pull hour:
 *   - cron/master.php is removed from the copy — the dispatcher has its own suites;
 *   - the child's clock is set with php -d date.timezone;
 *   - uCRM is a stream wrapper (dnfakecrm://) loaded with auto_prepend_file: no
 *     network and no socket. It answers the wallet sync's client read and records
 *     every path it is asked to open, so "the tick made no list request" is measured,
 *     with the wallet sync's own request as the positive control.
 * Two runs: (A) the wallet sync skipped, as on most ticks; (B) the wallet sync run.
 * The control puts the 5.18.92 block back where it stood — verbatim to the line where
 * it died, then closed — and watches it die at 484 and at 509 as the server did.
 */
$pass = 0; $fail = 0;
function is_(bool $ok, string $what, string $detail = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ok   $what\n"; }
    else     { $fail++; echo "  FAIL $what" . ($detail !== '' ? " — $detail" : '') . "\n"; }
}
function t(string $what, $got, $want): void { is_($got === $want, $what, 'got ' . var_export($got, true) . ', want ' . var_export($want, true)); }

$vault = null;
if (!getenv('DN_VAULT_FILE')) { $vault = tempnam(sys_get_temp_dir(), 'dn-vault-'); putenv('DN_VAULT_FILE=' . $vault); }

// main.php 5.18.92, lines 431–514: the block's header to its "done" line, five lines after the store it could not create.
// Everything after 514 is left out: line 509 throws whenever it is reached, so no run ever passed it.
$OLD = <<<'OLD_5_18_92'
// ══════════════════════════════════════════════════════════════════════════════
// AUTO-PULL UCRM CLIENTS CACHE  (daily — default 03:00 server time)
// Fetches all clients from UCRM org 2 and refreshes ucrm_clients_cache.json
// Also rebuilds sales_person_index.json — maps each retailer name → client IDs
// Runs automatically via UCRM's main.php scheduler. No crontab needed.
// Control: kyc_config.json → ucrm_auto_pull_hour (0-23, default 3)
//          ucrm_auto_pull_enabled (true/false, default true)
// ══════════════════════════════════════════════════════════════════════════════

$autoPullEnabled = ($kycConfig['ucrm_auto_pull_enabled'] ?? true) !== false;
$autoPullHour    = (int)($kycConfig['ucrm_auto_pull_hour'] ?? 3);
$pullLastFile    = $dataDir . '/ucrm_pull_last_run.json';
$pullMeta        = file_exists($pullLastFile) ? (json_decode(file_get_contents($pullLastFile), true) ?? []) : [];
$pullLastDay     = $pullMeta['day'] ?? '';
$pullLastHour    = (int)($pullMeta['hour'] ?? -1);
$currentHour     = (int)date('H');
$currentDay      = date('Y-m-d');

$shouldAutoPull  = $autoPullEnabled
    && $currentHour === $autoPullHour
    && ($pullLastDay !== $currentDay || $pullLastHour !== $currentHour);

if (!$autoPullEnabled) {
    mainLog('UCRM auto-pull: disabled in config.');
} elseif (!$shouldAutoPull) {
    $nextPull = date('Y-m-d') . ' ' . str_pad((string)$autoPullHour, 2, '0', STR_PAD_LEFT) . ':00';
    if ($currentHour >= $autoPullHour) $nextPull = date('Y-m-d', strtotime('+1 day')) . ' ' . str_pad((string)$autoPullHour, 2, '0', STR_PAD_LEFT) . ':00';
    mainLog("UCRM auto-pull: scheduled for {$nextPull}.");
} elseif ($crmBase === '' || $crmToken === '') {
    mainLog('UCRM auto-pull: CRM not configured — skipping.');
} else {
    mainLog('UCRM auto-pull: starting full clients pull from UCRM Org 2...');

    $limit      = 100;
    $offset     = 0;
    $totalPulled = 0;
    $existingCache = [];
    $cacheFile  = $dataDir . '/ucrm_clients_cache.json';

    // Load existing cache as map
    if (file_exists($cacheFile)) {
        $existing = json_decode(file_get_contents($cacheFile), true) ?? [];
        foreach ($existing as $ec) {
            if (!empty($ec['id'])) $existingCache[(int)$ec['id']] = $ec;
        }
    }

    $newMap = $existingCache; // start with existing, overwrite with fresh data
    $pages  = 0;
    $errors = 0;
    $maxPages = 200; // safety cap ~20,000 clients

    while ($pages < $maxPages) {
        $data = $crmGet("clients?limit={$limit}&offset={$offset}");
        if ($data === null) {
            $errors++;
            mainLog("  ⚠ Page " . ($pages+1) . " fetch failed.");
            break;
        }
        $items = is_array($data) ? array_filter($data, 'is_array') : [];
        $count = count($items);
        if ($count === 0) break;

        foreach ($items as $c) {
            if (!empty($c['id'])) $newMap[(int)$c['id']] = $c;
        }
        $totalPulled += $count;
        $pages++;
        $offset += $limit;

        if ($count < $limit) break; // last page
    }

    // Save updated clients cache via SqliteStore — MUST use $dataDir (persistent),
    // NOT __DIR__/data which is the installation folder and gets wiped on every update.
    if (file_exists(__DIR__ . '/lib/SqliteStore.php')) {
        require_once __DIR__ . '/lib/SqliteStore.php';
        require_once __DIR__ . '/lib/StoreInterface.php';
        $mainStore = \DishNet\SqliteStore::create($dataDir);
        $mainStore->save('ucrm_clients_cache.json', array_values($newMap));
    } else {
        file_put_contents($cacheFile, json_encode(array_values($newMap), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
    mainLog("UCRM auto-pull: done — {$totalPulled} clients pulled ({$pages} pages), {$errors} errors. Total cached: " . count($newMap));
OLD_5_18_92;
$OLD_SHA = 'aff6b23517204e79cee29173638caed98567e3f093d3a106281d30fcb2c96b2a';

$src     = dirname(__DIR__);
$root    = sys_get_temp_dir() . '/dn_autopull_' . getmypid();
$plugin  = $root . '/plugin';
$dataDir = $root . '/.' . basename($plugin) . '-data';            // getDataDir(): the sibling data directory
$hbFile  = $dataDir . '/heartbeat.log';
$crmLog  = $root . '/fakecrm.log';
$prepend = $root . '/fakecrm.php';
// The tree is copied and removed with PHP's own functions — no exec(), no shell: the deploy runs this test inside the uCRM
// container (5.18.93's A16 and R21), where only what 5.18.92's A15 proved is known to work: proc_open and the file
// functions.
function rm_tree(string $p): void {
    if (is_link($p) || is_file($p)) { @unlink($p); return; }
    if (!is_dir($p)) return;
    foreach ((array)scandir($p) as $e) { if ($e !== '.' && $e !== '..') rm_tree($p . '/' . $e); }
    @rmdir($p);
}
function copy_tree(string $from, string $to, array $skip = []): void {
    if (is_link($from)) { @symlink((string)readlink($from), $to); return; }
    if (is_file($from)) { copy($from, $to); return; }
    if (!is_dir($from)) return;
    @mkdir($to, 0700, true);
    foreach ((array)scandir($from) as $e) {
        if ($e === '.' || $e === '..' || in_array($e, $skip, true)) continue;
        copy_tree($from . '/' . $e, $to . '/' . $e);
    }
}
rm_tree($root); @mkdir($root, 0700, true);
copy_tree($src, $plugin, ['data']);                                    // the plugin, without its data/ — as before
is_(is_file($plugin . '/main.php') && !file_exists($plugin . '/data'), 'the copy carries main.php and no data/ folder');
@unlink($plugin . '/ucrm.json');
$master = $plugin . '/cron/master.php';
is_(is_file($master), 'the copy carries the master cron dispatcher (removed below, on purpose)');
@unlink($master);
is_(!is_file($master), 'cron/master.php removed from the copy: the tail of the tick is what runs here');

// uCRM, as the tick sees it: ucrm.json names dnfakecrm://ucrm/, so the wallet sync's base is dnfakecrm://ucrm/api/v2.1.
file_put_contents($plugin . '/ucrm.json', json_encode(['ucrmLocalUrl' => 'dnfakecrm://ucrm/', 'pluginAppKey' => 'test-app-key']));
file_put_contents($prepend, <<<'FAKE'
<?php
// The fake uCRM of tests/test_main_auto_pull_retired.php. It records every path it is asked to open.
final class DnFakeCrm {
    public $context;
    private $body = '';
    private $pos = 0;
    public function stream_open($path, $mode, $options, &$opened) {
        file_put_contents((string)getenv('DN_FAKECRM_LOG'), $path . "\n", FILE_APPEND);
        $u = parse_url($path); $p = isset($u['path']) ? $u['path'] : ''; $q = [];
        parse_str(isset($u['query']) ? $u['query'] : '', $q);
        if ($p === '/api/v2.1/clients/7') { $this->body = json_encode(['id' => 7, 'accountBalance' => -12.5]); return true; }
        if ($p === '/api/v2.1/clients' && isset($q['limit'])) {
            $this->body = (int)($q['offset'] ?? 0) === 0
                ? json_encode([['id' => 7, 'firstName' => 'Test', 'lastName' => 'Client'], ['id' => 8, 'companyName' => 'Test Company']])
                : '[]';
            return true;
        }
        return false;
    }
    public function stream_read($n) { $r = (string)substr($this->body, $this->pos, $n); $this->pos += strlen($r); return $r; }
    public function stream_eof() { return $this->pos >= strlen($this->body); }
    public function stream_stat() { return ['size' => strlen($this->body)]; }
    public function stream_set_option($o, $a1, $a2) { return false; }
    public function stream_close() {}
}
stream_wrapper_register('dnfakecrm', 'DnFakeCrm');
FAKE
);
putenv('DN_FAKECRM_LOG=' . $crmLog);

// A child PHP, as the server runs this file: the same binary, no shell and no timeout(1) — the deploy runs this test inside
// the uCRM container (5.18.93's A16 and R21), where neither is promised. A run that outlives its limit is stopped: 124.
// Each tick runs with error_log empty, so the plugin's handler writes to the child's stderr, where this test reads it,
// whatever the container's php.ini names.
function run_child(array $cmd, string $cwd, int $limit = 90): array {
    $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd);
    if (!is_resource($p)) return [127, '', 'proc_open failed'];
    stream_set_blocking($pipes[1], false); stream_set_blocking($pipes[2], false);
    $out = ''; $err = ''; $end = time() + $limit; $code = -1;
    while (true) {
        $out .= (string)stream_get_contents($pipes[1]); $err .= (string)stream_get_contents($pipes[2]);
        $st = proc_get_status($p);
        if (!$st['running']) { $code = (int)$st['exitcode']; break; }
        if (time() > $end) { proc_terminate($p, 9); $code = 124; break; }
        usleep(20000);
    }
    $out .= (string)stream_get_contents($pipes[1]); $err .= (string)stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]); proc_close($p);
    return [$code, $out, $err];
}

// A zone in which it is now 03:xx — chosen just before each run, never across an hour's last seconds.
function zone_at(int $hour): string {
    if ((int)gmdate('i') === 59 && (int)gmdate('s') >= 45) sleep(16);
    $u = (int)gmdate('G'); $o = (($hour - $u) % 24 + 24) % 24; if ($o > 12) $o -= 24;
    return $o === 0 ? 'UTC' : ($o > 0 ? "Etc/GMT-$o" : 'Etc/GMT+' . (-$o));     // Etc/GMT-N is UTC+N
}
// Each run starts from an empty data directory and an empty request log; $sync says whether the wallet sync is due.
$run = function (bool $sync, bool $fresh = true) use ($plugin, $dataDir, $crmLog, $prepend): array {
    if ($fresh) {
        rm_tree($dataDir); @mkdir($dataDir, 0700, true);
        file_put_contents($dataDir . '/kyc_config.json', json_encode(['ucrm_auto_pull_enabled' => true, 'ucrm_auto_pull_hour' => 3]));
        file_put_contents($dataDir . '/retailers.json', json_encode([['id' => 1, 'name' => 'Test Retailer', 'ftth_crm_client_id' => 7, 'wallet' => 0]]));
        if (!$sync) file_put_contents($dataDir . '/wallet_sync_last_run.txt', (string)time());
    }
    file_put_contents($crmLog, '');
    $tz  = zone_at(3);
    [$code, $out, $err] = run_child([PHP_BINARY, '-d', 'error_log=', '-d', 'date.timezone=' . $tz, '-d', 'auto_prepend_file=' . $prepend, 'main.php'], $plugin);
    $hb  = is_file($dataDir . '/heartbeat.log') ? (string)file_get_contents($dataDir . '/heartbeat.log') : '';
    $req = array_values(array_filter(explode("\n", (string)@file_get_contents($crmLog)), 'strlen'));
    return ['code' => $code, 'err' => $err, 'hb' => $hb, 'req' => $req, 'tz' => $tz];
};
$listed = function (array $req): int { return count(array_filter($req, function ($r) { return strpos($r, '/clients?') !== false; })); };
$pullFiles = ['ucrm_clients_cache.json', 'client_search_index.json', 'sp_client_index.json', 'sp_summary.json', 'ucrm_pull_last_run.json', 'plugin.sqlite3'];
$wrote = function () use ($dataDir, $pullFiles): array { return array_values(array_filter($pullFiles, function ($f) use ($dataDir) { return file_exists($dataDir . '/' . $f); })); };

echo "\nThe clock\n";
$tz = zone_at(3);
t("the chosen zone ($tz) reads 03 now — the default pull hour", (int)(new DateTime('now', new DateTimeZone($tz)))->format('G'), 3);

echo "\nA — the tick as shipped, at 03:xx, the wallet sync not due (most ticks)\n";
$a = $run(false);
is_($a['hb'] !== '', 'the tick wrote its heartbeat log at the sibling data directory', $hbFile);
is_(strpos($a['err'], 'UNCAUGHT') === false && strpos($a['err'], 'FATAL') === false, 'nothing uncaught on stderr', trim(substr($a['err'], 0, 300)));
t('exit status 0', $a['code'], 0);
is_(strpos($a['hb'], 'Heartbeat complete.') !== false, 'the heartbeat ran');
is_(strpos($a['hb'], 'Wallet sync: skipping') !== false, 'the wallet sync was not due, as on most ticks', trim(substr($a['hb'], -300)));
is_(strpos($a['hb'], 'main.php total execution:') !== false, 'the tick reached its last line', trim(substr($a['hb'], -300)));
t('no auto-pull line of any kind', substr_count($a['hb'], 'auto-pull'), 0);
t('uCRM was asked for nothing', $a['req'], []);
t('and nothing the pull would have written is there', $wrote(), []);

echo "\nB — the tick as shipped, at 03:xx, the wallet sync due\n";
$b = $run(true);
is_(strpos($b['err'], 'UNCAUGHT') === false && strpos($b['err'], 'FATAL') === false, 'nothing uncaught on stderr', trim(substr($b['err'], 0, 300)));
t('exit status 0', $b['code'], 0);
is_(strpos($b['hb'], 'Wallet sync done — updated: 1, unchanged: 0, failed: 0.') !== false, 'the wallet sync ran against the fake uCRM', trim(substr($b['hb'], -400)));
t("the positive control: the fake uCRM saw the wallet sync's one read", $b['req'], ['dnfakecrm://ucrm/api/v2.1/clients/7']);
t('and no list of clients was asked for', $listed($b['req']), 0);
is_(strpos($b['hb'], 'main.php total execution:') !== false, 'the tick reached its last line', trim(substr($b['hb'], -300)));
t('no auto-pull line of any kind', substr_count($b['hb'], 'auto-pull'), 0);
t('and nothing the pull would have written is there', $wrote(), []);
t('the wallet sync recorded its run, as before', is_file($dataDir . '/wallet_sync_last_run.txt'), true);

echo "\nThe control: the 5.18.92 block put back where it stood\n";
t('the embedded block is the 5.18.92 code, byte for byte', hash('sha256', $OLD), $OLD_SHA);
$main = $plugin . '/main.php'; $s = (string)file_get_contents($main);
$hdr = "// AUTO-PULL UCRM CLIENTS CACHE — RETIRED (5.18.93)\n";
t('the copy carries the retired marker once', substr_count($s, $hdr), 1);
$i = strpos($s, $hdr);
$ctl = false;
if ($i !== false) {
    $start = (int)strrpos(substr($s, 0, $i - 1), "\n") + 1;
    $bar   = substr($s, $start, $i - $start);
    $end   = strpos($s, $bar, $i);
    is_(strpos($bar, '// ══') === 0 && $end !== false, 'the retired block is bounded by its two rule lines');
    if (strpos($bar, '// ══') === 0 && $end !== false) {
        $back = $OLD . "\n    // (the rest of 5.18.92's block followed here: no run ever passed line 509)\n}\n";
        file_put_contents($main, substr($s, 0, $start) . $back . substr($s, $end + strlen($bar)));
        $lines = file($main);
        $at = function (string $needle) use ($lines): int { foreach ($lines as $n => $l) if (strpos($l, $needle) !== false) return $n + 1; return 0; };
        t('the list call is back at line 484, the line the server log names', $at('$data = $crmGet("clients?limit='), 484);
        t('and the store it could not create at line 509', $at('$mainStore = \DishNet\SqliteStore::create($dataDir);'), 509);
        [$lintRc] = run_child([PHP_BINARY, '-l', $main], $plugin);
        t('the copy with the old block parses', $lintRc, 0);
        $ctl = $lintRc === 0;
    }
}
if ($ctl) {
    $ca = $run(false);
    is_(strpos($ca['err'], '[DishNet UNCAUGHT] Value of type null is not callable in ') !== false && strpos($ca['err'], '/main.php:484') !== false,
        'A: the old block dies with the production error at main.php:484', trim(substr($ca['err'], 0, 400)));
    t("A: …yet the exit status is 0 — the handler swallows it, so only the container log shows it", $ca['code'], 0);
    is_(strpos($ca['hb'], 'Heartbeat complete.') !== false && strpos($ca['hb'], 'main.php total execution:') === false,
        'A: the heartbeat ran and the last line was never written');
    t('A: it died before asking uCRM for anything', $ca['req'], []);
    t('A: and recorded nothing, so the next tick in the hour tries again', $wrote(), []);
    $ca2 = $run(false, false);
    is_(strpos($ca2['err'], 'Value of type null is not callable') !== false && strpos($ca2['err'], '/main.php:484') !== false,
        'A: the next tick in the same hour dies the same way — once per tick, twelve in the hour at five minutes', trim(substr($ca2['err'], 0, 300)));

    $cb = $run(true);
    is_(strpos($cb['err'], '[DishNet UNCAUGHT] Class "DishNet\SqliteStore" not found in ') !== false && strpos($cb['err'], '/main.php:509') !== false,
        'B: with the wallet sync due, the old block dies at main.php:509 on the class that does not exist', trim(substr($cb['err'], 0, 400)));
    t('B: the instrument sees the list request it made — after the wallet sync\'s read', $cb['req'],
        ['dnfakecrm://ucrm/api/v2.1/clients/7', 'dnfakecrm://ucrm/api/v2.1/clients?limit=100&offset=0']);
    is_(strpos($cb['hb'], 'UCRM auto-pull: starting full clients pull') !== false && strpos($cb['hb'], 'UCRM auto-pull: done') === false,
        'B: it started the pull and never finished it');
    t('B: and wrote no cache, no index and no record of the run', $wrote(), []);
}

echo "\nThe repository pin\n";
$repo = (string)file_get_contents($src . '/main.php');
t('main.php carries the retired marker once', substr_count($repo, $hdr), 1);
foreach (['$crmGet("clients?limit', 'SqliteStore::create', '$autoPullEnabled', '$autoPullHour', '$pullLastFile', 'ucrm_pull_last_run', 'UCRM auto-pull:'] as $gone) {
    t("no '$gone' remains in main.php", substr_count($repo, $gone), 0);
}

rm_tree($root);
if ($vault !== null) @unlink($vault);
t('the test leaves nothing behind in the temporary directory', file_exists($root) || ($vault !== null && file_exists($vault)), false);
echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
