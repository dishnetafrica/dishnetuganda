<?php
declare(strict_types=1);
/**
 * test_sl_block_retry_scope.php — 5.18.91: on Uganda, where the plugin's data lives outside <plugin>/data and the gate can
 * decide, the Starlink retry job no longer touches <plugin>/data (what it cannot decide runs as before: sections 3 and 8).
 *
 * cron_starlink_block_retry.php names <plugin>/data as its data directory, opens and migrates the plugin.sqlite3
 * there, and leaves $dataDir, $config, $store and $pdo pointing at it in cron/master.php's scope. On the Uganda server
 * the plugin keeps its data in the sibling .<plugin>-data, so every run kept a second, stray database alive and
 * migrated (docs/07, 5.18.91). This test builds that layout — the live store beside the plugin, a stray store inside it,
 * ucrm.json without pluginDataDir — and proves:
 *   1. the gate is the job's first statement and runs in its own scope; below it the job is 5.18.90's, byte for byte;
 *      cron/master.php still schedules it unchanged; getDataDir() is the source the helper's resolver was written from;
 *   2. the helper's resolver chooses the directory getDataDir() chooses, in five layouts and with an unwritable plugins
 *      root, and deciding — explain() run to its end — creates and changes nothing;
 *   3. Uganda, through master's shared scope: the stray store keeps its checksum, size and time, its folder gains and
 *      loses nothing, $dataDir is still the live directory afterwards and the job leaves no variable behind — while
 *      the 5.18.90 job, in the same layout, migrates the stray store (the control: the observation can see a change).
 *      The stray store is one migration behind there, so an open that migrates it — as the job's does — shows in its
 *      ledger; any other open shows in its folder. The server's own is at every migration (087, last written 07 Oct).
 *      Built that way here, every recorded checksum matching its file, an
 *      open of it changes neither its database nor its migration.log — only its folder, where SQLite makes and removes
 *      the -wal and -shm. (On the server that log is no witness either way: MigrationRunner's default log is
 *      <plugin>/data/migration.log, so other jobs write it too, and a recorded checksum that no longer matches its file
 *      adds a warning to it on every open — docs/07, 5.18.91.) That store is built too (3b): the gate leaves its folder
 *      exactly as it is, and the 5.18.90 job changes the folder and nothing else — the change the deploy's A14 and R19
 *      read;
 *   4. Uganda with no stray store: none is created; the 5.18.90 job creates one;
 *   5. Uganda selected by the vault alone, run on its own: nothing in either folder changes, the live store's -wal and
 *      -shm included;
 *   6. South Sudan: exactly the 5.18.90 job's outcome — the same variables, the same database, the same files changed —
 *      and the gate itself creates no directory;
 *   7. Uganda where <plugin>/data IS the data directory: the job runs as before;
 *   8. anything unclear is South Sudan's answer — the helper missing, an unreadable store or vault, a pluginDataDir that
 *      is no path;
 *   9. weakened copies of the gate and the helper each fail this test, by what the code does, not by its text.
 * And, through master's scope (3, 6), the job never costs master a POSIX lock on the live database or on its -shm: read
 * from /proc/locks before and after. A descriptor of the database opened and closed outside SQLite would release
 * master's locks on it, and another process could then delete the -wal and -shm from under master; one of the -shm
 * would release the lock that tells another process the -shm is in use, and it could be rebuilt under master (the
 * reviews of 09 Oct; sqlite.org, "How To Corrupt", 2.2).
 *
 * The unwritable plugins root (2) is made by running as uid 65534 through setpriv when this runs as root, and by taking
 * the folder's write permission away otherwise. Every name here is fictitious; nothing is sent; nothing outside the
 * test's own temporary folder is touched.
 *
 *   php test_sl_block_retry_scope.php [--root=DIR] [--no-mutants] [--no-source]
 *
 * --no-source leaves out section 1's reading of the source, so that a weakened copy has to be caught by what the code
 * DOES; the weakened copies below are run that way.
 */
$opt  = getopt('', ['root:', 'no-mutants', 'no-source']);
$root = isset($opt['root']) ? rtrim((string)$opt['root'], '/') : dirname(__DIR__);
$withMutants = !isset($opt['no-mutants']);
$withSource  = !isset($opt['no-source']);

$pass = 0; $fail = 0; $PASSED = [];
function is_(bool $c, string $m, string $d = ''): void {
    global $pass, $fail, $PASSED;
    if ($c) { $pass++; $PASSED[] = $m; echo "  ok   {$m}\n"; }
    else    { $fail++; echo "  FAIL {$m}" . ($d !== '' ? "\n       {$d}" : '') . "\n"; }
}

const SLR_PLUGIN = 'dishnet-hybrid-sudan';
const SLR_JOB    = 'cron_starlink_block_retry.php';
// cron_starlink_block_retry.php as 5.18.90 (3d9cb5f) has it — identical since 5.18.87 at least.
const SLR_JOB_5_18_90_SHA256 = '9e31237237b5b3cde453e14aebb587baf6c3359ec92e93b979b49d0668a5d743';
// getDataDir() in lib/bootstrap_data.php, from "function getDataDir(" to its closing brace, as liveDataDir() mirrors it.
const SLR_GETDATADIR_SHA256  = 'c64d88983c166c9a75958ceb86ced39098bbbf9393f839ca0aefa3426f37c07c';
const SLR_GATE_START = '// ── 5.18.91: on Uganda, stop before <plugin>/data is touched';
const SLR_GATE_END   = "// ── Ensure we're in cron context";

$BASE = sys_get_temp_dir() . '/sl-scope-' . getmypid() . '-' . bin2hex(random_bytes(3));
@mkdir($BASE, 0755, true);
@chmod($BASE, 0755);
register_shutdown_function(function () use ($BASE) { exec('rm -rf ' . escapeshellarg($BASE)); });

// ── The runner: every plugin call happens in a process of its own ────────────
// So getDataDir()'s static cache and StaffJobsGate's memo never cross scenarios, and 'master' reproduces
// cron/master.php's shared scope: the same requires, the same getDataDir(), the live store opened, then the job
// included at that scope.
file_put_contents($BASE . '/runner.php', <<<'PHP'
<?php
$__mode = $argv[1];
$__root = realpath($argv[2]);
if ($__mode === 'seed') {                      // seed <root> <dir> <kyc json or ->
    require_once $__root . '/lib/SqliteStore.php';
    $__s = SqliteStore::create($argv[3]);
    if (($argv[4] ?? '-') !== '-') $__s->save('kyc_config.json', json_decode($argv[4], true));
    echo "JSON:" . json_encode(['ok' => true]) . "\n";
    exit(0);
}
if ($__mode === 'explain' || $__mode === 'explain-held') {
    if ($__mode === 'explain-held') {           // as inside cron/master.php: the live store already held open
        require_once $__root . '/lib/SqliteStore.php';
        require_once $__root . '/lib/bootstrap_data.php';
        $__held = SqliteStore::create(getDataDir($__root));
    }
    require_once $__root . '/lib/StarlinkRetryScope.php';
    echo "JSON:" . json_encode(StarlinkRetryScope::explain($__root)) . "\n";
    exit(0);
}
if ($__mode === 'resolve') {
    require_once $__root . '/lib/StarlinkRetryScope.php';
    $__a = StarlinkRetryScope::liveDataDir($__root);
    require_once $__root . '/lib/bootstrap_data.php';
    $__b = getDataDir($__root);
    echo "JSON:" . json_encode(['scope' => $__a, 'getDataDir' => $__b]) . "\n";
    exit(0);
}
if ($__mode === 'master') {
    // cron/master.php, lines 47-61, then its include of the job at the same scope.
    require_once $__root . '/lib/StoreInterface.php';
    require_once $__root . '/lib/JsonStore.php';
    require_once $__root . '/lib/SqliteStore.php';
    require_once $__root . '/lib/bootstrap_data.php';
    $pluginRoot = $__root;
    $dataDir    = getDataDir($pluginRoot);
    $_m_store   = SqliteStore::create($dataDir);
    $_m_config  = $_m_store->load('kyc_config.json') ?? [];
    $__live = $dataDir;
    $__cfg  = json_encode($_m_config);
    // This process's POSIX locks on a file, from /proc/locks — master's connection holds a READ lock on the live database
    // and one on its -shm for as long as it is open. -1: not measurable here.
    $__locks = function (string $db): int {
        $ino = @fileinode($db); $raw = @file_get_contents('/proc/locks');
        if (!$ino || !is_string($raw)) return -1;
        $n = 0;
        foreach (explode("\n", $raw) as $l) {
            $f = preg_split('/\s+/', trim($l));
            if (count($f) >= 8 && $f[1] === 'POSIX' && (int)$f[4] === getmypid() && preg_match('/:(\d+)$/', $f[5], $m) && (int)$m[1] === $ino) $n++;
        }
        return $n;
    };
    $__lb = [$__locks($__live . '/plugin.sqlite3'), $__locks($__live . '/plugin.sqlite3-shm')];
    $__before = array_keys(get_defined_vars());
    $__rv = include $__root . '/cron_starlink_block_retry.php';
    $__after = array_keys(get_defined_vars());
    $__la = [$__locks($__live . '/plugin.sqlite3'), $__locks($__live . '/plugin.sqlite3-shm')];
    $__db = null;
    if (isset($store) && is_object($store) && method_exists($store, 'getPdo')) {
        foreach ($store->getPdo()->query('PRAGMA database_list')->fetchAll(PDO::FETCH_ASSOC) as $__r) {
            if ($__r['name'] === 'main') $__db = $__r['file'];
        }
    }
    $__new = array_values(array_diff($__after, $__before, ['__before', '__rv', '__la']));
    sort($__new);
    echo "JSON:" . json_encode(['live' => $__live, 'dataDir' => $dataDir, 'new' => $__new,
        'config_same' => json_encode($_m_config) === $__cfg, 'store_db' => $__db, 'locks_before' => $__lb, 'locks_after' => $__la]) . "\n";
    exit(0);
}
fwrite(STDERR, "unknown mode\n");
exit(2);
PHP
);

/** Run the runner (or the job itself, mode 'alone'); return [rc, decoded JSON or null, output]. */
function slr_run(string $mode, string $pluginRoot, array $args = [], string $as = ''): array {
    global $BASE;
    $env = 'env -u DN_DATA_DIR DN_VAULT_FILE= ';   // the vault where production keeps it: beside the plugin
    $who = $as === 'nobody' ? 'setpriv --reuid=65534 --regid=65534 --clear-groups ' : '';
    $cmd = $mode === 'alone'
        ? $env . $who . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($pluginRoot . '/' . SLR_JOB)
        : $env . $who . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($BASE . '/runner.php') . ' ' . escapeshellarg($mode)
          . ' ' . escapeshellarg($pluginRoot) . ' ' . implode(' ', array_map('escapeshellarg', $args));
    $out = [];
    exec('cd ' . escapeshellarg($BASE) . ' && ' . $cmd . ' 2>&1', $out, $rc);
    $json = null;
    foreach ($out as $l) if (strpos($l, 'JSON:') === 0) $json = json_decode(substr($l, 5), true);
    return [$rc, $json, implode("\n", $out)];
}

/** Every entry under a folder, the folder itself included: size, time to the nanosecond, checksum. */
function slr_snap(string $dir): array {
    clearstatcache();
    if (!is_dir($dir)) return ['<absent>' => ''];
    $out = ['.' => 'dir ' . trim((string)shell_exec('stat -c %y ' . escapeshellarg($dir)))];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST);
    foreach ($it as $f) {
        $p = substr($f->getPathname(), strlen($dir) + 1);
        $t = trim((string)shell_exec('stat -c %y ' . escapeshellarg($f->getPathname())));
        $out[$p] = $f->isDir() ? 'dir ' . $t : filesize($f->getPathname()) . '|' . $t . '|' . hash_file('sha256', $f->getPathname());
    }
    ksort($out);
    return $out;
}

/** The paths that appeared, vanished or changed between two snapshots. */
function slr_changed(array $a, array $b): array {
    $k = array_unique(array_merge(array_keys($a), array_keys($b)));
    $c = array_values(array_filter($k, function ($p) use ($a, $b) { return ($a[$p] ?? null) !== ($b[$p] ?? null); }));
    sort($c);
    return $c;
}

/** How many migrations a store has recorded — read immutably, so the reading itself changes nothing. */
function slr_ledger(string $db): int {
    if (!is_file($db)) return -1;
    try {
        $p = new PDO('sqlite:file:' . $db . '?immutable=1', null, null,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY]);
        return (int)$p->query('SELECT COUNT(*) FROM _migrations')->fetchColumn();
    } catch (\Throwable $e) {
        return -2;
    }
}

// ── 1. The source ─────────────────────────────────────────────────────────────
$jobSrc = (string)@file_get_contents($root . '/' . SLR_JOB);
$gs = strpos($jobSrc, SLR_GATE_START);
$ge = strpos($jobSrc, SLR_GATE_END);
$gate    = ($gs !== false && $ge !== false) ? substr($jobSrc, $gs, $ge - $gs) : '';
$derived = ($gs !== false && $ge !== false) ? substr($jobSrc, 0, $gs) . substr($jobSrc, $ge) : '';
// 5.18.90's job, for the comparisons below: from Git (3d9cb5f) where this checkout can read it, else the job without its
// 5.18.91 block — in either case only when it is 5.18.90's byte for byte.
$fromGit = (string)shell_exec('git -C ' . escapeshellarg(__DIR__) . ' show 3d9cb5f:dishnet-hybrid-sudan/' . SLR_JOB . ' 2>/dev/null');
$old90 = hash('sha256', $fromGit) === SLR_JOB_5_18_90_SHA256 ? $fromGit
       : (hash('sha256', $derived) === SLR_JOB_5_18_90_SHA256 ? $derived : '');
if ($withSource) {
echo "1. The source: the gate, the job below it, master's schedule, getDataDir()\n";
is_($gs !== false && $ge !== false && $gs < $ge, 'the 5.18.91 block sits above "Ensure we\'re in cron context"');
is_(hash('sha256', $derived) === SLR_JOB_5_18_90_SHA256,
    'without the 5.18.91 block the job is 5.18.90\'s, byte for byte — nothing below the gate changed');
// The gate's statement comes before every statement the job had: no assignment, require, or store before it.
$toks = array_values(array_filter(token_get_all($jobSrc), function ($t) {
    return !(is_array($t) && in_array($t[0], [T_OPEN_TAG, T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true));
}));
$k = 0;
if (is_array($toks[0] ?? null) && $toks[0][0] === T_DECLARE) { while ($k < count($toks) && $toks[$k] !== ';') $k++; $k++; }
$firstCode = isset($toks[$k]) ? (is_array($toks[$k]) ? token_name($toks[$k][0]) : (string)$toks[$k]) : '';
is_($firstCode === 'T_IF', 'after declare(strict_types=1) the first statement is the gate\'s if', "first: {$firstCode}");
$gateVars = []; $gateExit = false;
foreach (token_get_all("<?php\n" . $gate) as $t) {
    if (is_array($t) && $t[0] === T_VARIABLE) $gateVars[$t[1]] = true;
    if (is_array($t) && $t[0] === T_EXIT) $gateExit = true;
}
is_(strpos($gate, '(static function (): bool {') !== false && array_keys($gateVars) === ['$scope'],
    'the gate is a static closure whose only variable is its own $scope — nothing assigned at the including scope',
    implode(' ', array_keys($gateVars)));
is_(strpos($gate, 'return StarlinkRetryScope::skip(__DIR__);') !== false && strpos($gate, "    return;\n}") !== false,
    'on skip() the job returns (SAFETY.md RULE 11b: return, never exit)');
is_($gate !== '' && !$gateExit, 'the gate never exits the including process');
$master = (string)@file_get_contents($root . '/cron/master.php');
is_(substr_count($master, "    'sl_block_retry' => ['interval' => 600,                'script' => dirname(__DIR__) . '/cron_starlink_block_retry.php'],") === 1,
    'cron/master.php still schedules sl_block_retry every 600 s, ungated, as before');
$boot = (string)@file_get_contents($root . '/lib/bootstrap_data.php');
$bs = strpos($boot, 'function getDataDir(');
$be = $bs === false ? false : strpos($boot, "\n}\n", $bs);
is_($bs !== false && $be !== false && hash('sha256', substr($boot, $bs, $be + 3 - $bs)) === SLR_GETDATADIR_SHA256,
    'getDataDir() is the source StarlinkRetryScope::liveDataDir() mirrors — if it changes, review the mirror');
$helper = (string)@file_get_contents($root . '/lib/StarlinkRetryScope.php');
$writes = [];
foreach (['getDataDir(', 'mkdir(', 'copy(', 'file_put_contents(', 'unlink(', 'rename(', 'touch(', 'SqliteStore', 'PluginConfig::load(',
          'ConfigVault::apply(', 'chmod(', 'chown(', '->exec(', 'INSERT', 'UPDATE', 'DELETE'] as $needle) {
    $code = preg_replace('~/\*.*?\*/|//[^\n]*~s', '', $helper);
    if (strpos((string)$code, $needle) !== false) $writes[] = $needle;
}
is_($helper !== '' && $writes === [], 'the helper\'s code names nothing that writes', implode(', ', $writes));
is_(strpos($helper, 'StaffJobsGate::applies($config, $live)') !== false, 'the helper decides by StaffJobsGate, the plugin\'s one Uganda switch, with the live directory');
$opens = [];
foreach (['fopen(', 'fread(', 'fclose(', 'hash_file(', 'md5_file(', 'sha1_file(', 'readfile(', 'SplFileObject', 'file_get_contents($db'] as $needle) {
    $code = preg_replace('~/\*.*?\*/|//[^\n]*~s', '', $helper);
    if (strpos((string)$code, $needle) !== false) $opens[] = $needle;
}
if (preg_match('~(?<![A-Za-z0-9_])file\(~', (string)preg_replace('~/\*.*?\*/|//[^\n]*~s', '', $helper))) $opens[] = 'file(';
is_($helper !== '' && $opens === [], 'the helper never opens a file descriptor of its own on the database or its side files — SQLite alone opens them', implode(', ', $opens));
}

is_($old90 !== '', '5.18.90\'s retry job is at hand byte for byte — from Git, or the job without its 5.18.91 block — for the controls below');

// ── Templates: the plugin, a live store, and an OLDER stray store ────────────
$tplRoot = $BASE . '/tpl/plugins/' . SLR_PLUGIN;
@mkdir($tplRoot, 0755, true);
foreach (scandir($root) ?: [] as $e) {
    // ucrm.json too: every scene writes its own, and the scenes share the template's files through hard links.
    if (in_array($e, ['.', '..', 'tests', 'docs', 'prototype', 'dishnet-mikrotik-control-plane', 'data', '.git', 'ucrm.json'], true)) continue;
    exec('cp -R ' . escapeshellarg($root . '/' . $e) . ' ' . escapeshellarg($tplRoot . '/'));
}
exec('chmod -R a+rX ' . escapeshellarg($BASE));
$sqls = glob($tplRoot . '/migrations/*.sql') ?: [];
sort($sqls);
$lastSql = end($sqls);
[$rc1] = slr_run('seed', $tplRoot, [$BASE . '/tpl/livedir', '-']);
@rename($lastSql, $BASE . '/tpl/last.sql.hold');
[$rc2] = slr_run('seed', $tplRoot, [$BASE . '/tpl/straydir', '-']);
@rename($BASE . '/tpl/last.sql.hold', $lastSql);
$T_LIVE  = $BASE . '/tpl/livedir/plugin.sqlite3';
$T_STRAY = $BASE . '/tpl/straydir/plugin.sqlite3';
$nAll = slr_ledger($T_LIVE);
$nOld = slr_ledger($T_STRAY);
is_($rc1 === 0 && $rc2 === 0 && $nAll === count($sqls) && $nOld === $nAll - 1,
    "templates: a live store at all {$nAll} migrations, a stray store one behind ({$nOld}), so that an open that migrates it shows in its ledger",
    "rc {$rc1}/{$rc2}, ledgers {$nAll}/{$nOld}, files " . count($sqls));
// A level stray store, as the server's is (docs/07, 5.18.91): at every migration, opened and settled. An open of it then —
// every recorded checksum matching its file, as built here — changes neither its database nor its migration.log; only its
// folder changes, where SQLite makes and removes the -wal and -shm. The template proves that on itself before any scene
// uses it, and that its log is there to compare: a missing log would compare equal to itself.
$T_SRVDIR = $BASE . '/tpl/serverdir';
slr_run('seed', $tplRoot, [$T_SRVDIR, '-']); slr_run('seed', $tplRoot, [$T_SRVDIR, '-']);
$srvId = function () use ($T_SRVDIR) {
    clearstatcache(); $f = $T_SRVDIR . '/plugin.sqlite3'; $l = $T_SRVDIR . '/migration.log';
    return ['db' => hash_file('sha256', $f) . filesize($f) . trim((string)shell_exec('stat -c %y ' . escapeshellarg($f))),
            'log' => is_file($l) ? hash_file('sha256', $l) . trim((string)shell_exec('stat -c %y ' . escapeshellarg($l))) : '',
            'loglines' => is_file($l) ? count(file($l)) : 0,
            'dir' => trim((string)shell_exec('stat -c %y ' . escapeshellarg($T_SRVDIR)))];
};
$id0 = $srvId(); usleep(20000);
slr_run('seed', $tplRoot, [$T_SRVDIR, '-']);
$id1 = $srvId();
is_(slr_ledger($T_SRVDIR . '/plugin.sqlite3') === $nAll && $id1['db'] === $id0['db'] && $id0['loglines'] > 0 && $id1['log'] === $id0['log'] && $id1['dir'] !== $id0['dir']
    && !file_exists($T_SRVDIR . '/plugin.sqlite3-wal') && !file_exists($T_SRVDIR . '/plugin.sqlite3-shm'),
    "templates: a level stray store as built here — at all {$nAll} migrations, every checksum matching: an open leaves its database and its migration.log exactly as they are, and changes its folder (the -wal and -shm made and removed)",
    json_encode(['ledger' => slr_ledger($T_SRVDIR . '/plugin.sqlite3'), 'db' => $id1['db'] === $id0['db'], 'loglines' => $id0['loglines'], 'log' => $id1['log'] === $id0['log'], 'dir' => $id1['dir'] !== $id0['dir']]));

/**
 * A plugins root laid out like production. $o: tenant (store kyc json or null), vault (array or null), stray (false, true
 * — one migration behind — or 'server', level as the server's is), layout ('sibling' | 'inside' | 'nolive'), job ('current' |
 * '5.18.90').
 */
function slr_scene(string $name, array $o): array {
    global $BASE, $tplRoot, $T_LIVE, $T_STRAY, $T_SRVDIR, $old90;
    $plugins = $BASE . '/' . $name . '/plugins';
    $pr = $plugins . '/' . SLR_PLUGIN;
    @mkdir($plugins, 0755, true);
    exec('cp -al ' . escapeshellarg($tplRoot) . ' ' . escapeshellarg($plugins . '/'));
    if (($o['job'] ?? 'current') === '5.18.90') {
        @unlink($pr . '/' . SLR_JOB);                       // a hard link: never write through it
        file_put_contents($pr . '/' . SLR_JOB, $old90);
    }
    @mkdir($pr . '/data', 0755);
    file_put_contents($pr . '/data/config.json', json_encode(['note' => 'the stray folder\'s own settings file']));
    if (($o['stray'] ?? false) === 'server') {
        foreach (scandir($T_SRVDIR) ?: [] as $e) if (is_file($T_SRVDIR . '/' . $e)) copy($T_SRVDIR . '/' . $e, $pr . '/data/' . $e);
    } elseif (!empty($o['stray'])) {
        copy($T_STRAY, $pr . '/data/plugin.sqlite3');
    }
    $layout = $o['layout'] ?? 'sibling';
    if ($layout === 'inside') {
        file_put_contents($pr . '/ucrm.json', json_encode(['ucrmPublicUrl' => 'https://crm.example.test/', 'pluginDataDir' => $pr . '/data']));
        $live = $pr . '/data';
        if (!is_file($live . '/plugin.sqlite3')) copy($T_LIVE, $live . '/plugin.sqlite3');
    } else {
        // Production's ucrm.json: no pluginDataDir, so getDataDir() takes the sibling of a writable plugins root.
        file_put_contents($pr . '/ucrm.json', json_encode(['ucrmPublicUrl' => 'https://crm.example.test/', 'pluginId' => 7]));
        $live = $plugins . '/.' . SLR_PLUGIN . '-data';
        if ($layout === 'sibling') { @mkdir($live, 0755); copy($T_LIVE, $live . '/plugin.sqlite3'); }
    }
    if (isset($o['vault'])) file_put_contents($plugins . '/.dishnet-sudan.vault.json', json_encode(['config' => $o['vault']]));
    if (array_key_exists('tenant', $o) && $o['tenant'] !== null && is_file($live . '/plugin.sqlite3')) {
        slr_run('seed', $pr, [$live, json_encode($o['tenant'])]);
    }
    exec('chmod -R a+rX ' . escapeshellarg($BASE . '/' . $name));
    return ['root' => $pr, 'live' => $live, 'data' => $pr . '/data', 'plugins' => $plugins];
}

// ── 2. The resolver ───────────────────────────────────────────────────────────
echo "\n2. StarlinkRetryScope::liveDataDir() chooses what getDataDir() chooses, without creating it\n";
$cases = [
    'ucrm.json names a directory'          => function (array $s) { file_put_contents($s['root'] . '/ucrm.json', json_encode(['pluginDataDir' => $s['plugins'] . '/elsewhere/'])); },
    'only data/ucrm.json names one'        => function (array $s) { file_put_contents($s['root'] . '/ucrm.json', json_encode(['pluginId' => 7]));
                                                                    file_put_contents($s['data'] . '/ucrm.json', json_encode(['pluginDataDir' => $s['plugins'] . '/viadata'])); },
    'production: no pluginDataDir, writable plugins root' => function (array $s) { },
    'pluginDataDir "/" — trims to nothing'  => function (array $s) { file_put_contents($s['root'] . '/ucrm.json', json_encode(['pluginDataDir' => '/'])); },
    'pluginDataDir "0/" — trims to "0"'     => function (array $s) { file_put_contents($s['root'] . '/ucrm.json', json_encode(['pluginDataDir' => '0/'])); },
];
$i = 0;
foreach ($cases as $label => $prep) {
    $s = slr_scene('resolve' . (++$i), ['layout' => 'nolive']);
    $prep($s);
    exec('chmod -R a+rX ' . escapeshellarg($s['plugins']));
    $before = slr_snap($s['plugins']);
    [$rcE, $scopeOnly] = slr_run('explain', $s['root']);
    $afterScope = slr_snap($s['plugins']);
    [$rc, $j] = slr_run('resolve', $s['root']);
    is_($rc === 0 && is_array($j) && $j['scope'] === $j['getDataDir'], "{$label}: the same directory", json_encode($j));
    is_($rcE === 0 && is_array($scopeOnly) && is_array($j) && $scopeOnly['live'] === $j['getDataDir'] && is_string($scopeOnly['tenant'])
        && $scopeOnly['tenant'] !== 'unreadable' && $scopeOnly['skip'] === false,
        "{$label}: explain() ran to its end — that directory, a country (" . ($scopeOnly['tenant'] ?? '?') . "), no skip", json_encode($scopeOnly));
    is_(slr_changed($before, $afterScope) === [], "{$label}: deciding created and changed nothing", implode(', ', slr_changed($before, $afterScope)));
}
// A plugins root this process cannot write, with a folder beside the plugin already there — so a resolver that preferred
// an existing sibling would show here.
$s = slr_scene('resolve-unwritable', ['layout' => 'nolive']);
@mkdir($s['live'], 0755);
$asRoot = function_exists('posix_geteuid') ? posix_geteuid() === 0 : trim((string)shell_exec('id -u')) === '0';
if ($asRoot) {
    $canDrop = trim((string)shell_exec('setpriv --reuid=65534 --regid=65534 --clear-groups ' . escapeshellarg(PHP_BINARY)
        . ' -r ' . escapeshellarg('echo is_dir($argv[1]) && !is_writable($argv[1]) ? "yes" : "no";') . ' ' . escapeshellarg($s['plugins']) . ' 2>/dev/null'));
    [$rc, $j] = $canDrop === 'yes' ? slr_run('resolve', $s['root'], [], 'nobody') : [-1, null];
} else {
    @chmod($s['plugins'], 0555); clearstatcache();
    $canDrop = (is_dir($s['plugins']) && !is_writable($s['plugins'])) ? 'yes' : 'no';
    [$rc, $j] = $canDrop === 'yes' ? slr_run('resolve', $s['root']) : [-1, null];
    @chmod($s['plugins'], 0755);
}
is_($canDrop === 'yes' && $rc === 0 && is_array($j) && $j['scope'] === $j['getDataDir'] && $j['scope'] === $s['root'] . '/data',
    'a plugins root this process cannot write, a folder beside the plugin already there: both still take <plugin>/data',
    ($canDrop === 'yes' ? '' : 'could not make the plugins root unwritable (' . ($asRoot ? 'setpriv' : 'chmod') . '), ') . json_encode($j));

// ── 3. Uganda, production layout, through master's shared scope ──────────────
echo "\n3. Uganda (the live store says so), through cron/master.php's shared scope, with an older stray store\n";
$ug = slr_scene('ug', ['tenant' => ['tenant_profile' => 'uganda'], 'stray' => true]);
$sBefore = slr_snap($ug['data']);
$strayDb = $ug['data'] . '/plugin.sqlite3';
$sha0 = hash_file('sha256', $strayDb); $size0 = filesize($strayDb); $mt0 = trim((string)shell_exec('stat -c %y ' . escapeshellarg($strayDb)));
[$rc, $r, $outText] = slr_run('master', $ug['root']);
$sAfter = slr_snap($ug['data']);
is_($rc === 0 && is_array($r), 'the job ran inside master\'s scope and returned', substr($outText, 0, 300));
is_(hash_file('sha256', $strayDb) === $sha0 && filesize($strayDb) === $size0
    && trim((string)shell_exec('stat -c %y ' . escapeshellarg($strayDb))) === $mt0,
    'the stray plugin.sqlite3 keeps its checksum, size and modification time');
is_(slr_changed($sBefore, $sAfter) === [], 'the stray folder gains, loses and changes nothing — no -wal, -shm or migration.log line',
    implode(', ', slr_changed($sBefore, $sAfter)));
is_(slr_ledger($strayDb) === $nAll - 1, 'the stray store is still one migration behind: it was not migrated');
is_(is_array($r) && $r['dataDir'] === $ug['live'] && $r['live'] === $ug['live'],
    '$dataDir in master\'s scope is still the live directory afterwards, the one getDataDir() chose', json_encode($r));
is_(is_array($r) && $r['new'] === [], 'the job left no variable behind in master\'s scope', json_encode($r['new'] ?? null));
is_(is_array($r) && $r['store_db'] === null && !in_array('config', $r['new'] ?? ['?'], true), 'no $store and no $config of the job\'s are left in master\'s scope');
is_(is_array($r) && ($r['locks_before'][0] ?? 0) > 0 && ($r['locks_before'][1] ?? 0) > 0 && $r['locks_after'] === $r['locks_before'],
    'master\'s POSIX locks on the live database and on its -shm survive the job — ' . json_encode($r['locks_before'] ?? null) . ' before, '
    . json_encode($r['locks_after'] ?? null) . ' after (/proc/locks): the gate read the live store through SQLite alone',
    json_encode([$r['locks_before'] ?? null, $r['locks_after'] ?? null]));
[, $x] = slr_run('explain-held', $ug['root']);
is_(is_array($x) && $x['skip'] === true && $x['tenant'] === 'uganda' && $x['inside'] === false && $x['store'] === 'read',
    'explain(), with the live store held open as master holds it: skip, tenant uganda, read from the live store', json_encode($x));
$lb = slr_snap($ug['live']);
[, $x] = slr_run('explain', $ug['root']);
is_(is_array($x) && $x['store'] === 'not-read:wal-files-missing' && $x['skip'] === false && slr_changed($lb, slr_snap($ug['live'])) === [],
    'the recorded limit: asked on its own, with nothing holding the live store open, it leaves the store unread rather '
    . 'than create its -wal and -shm — here, where only the store names Uganda, that is no skip, the job as before',
    json_encode($x));
// The control: the 5.18.90 job in the same layout DOES migrate the stray store.
$ug90 = slr_scene('ug90', ['tenant' => ['tenant_profile' => 'uganda'], 'stray' => true, 'job' => '5.18.90']);
$b90 = slr_snap($ug90['data']);
[$rc, $r90] = slr_run('master', $ug90['root']);
$a90 = slr_snap($ug90['data']);
is_(in_array('plugin.sqlite3', slr_changed($b90, $a90), true) && slr_ledger($ug90['data'] . '/plugin.sqlite3') === $nAll,
    'control: the 5.18.90 job, same layout, migrates the stray store — the observation above can see a change',
    implode(', ', slr_changed($b90, $a90)));
is_(is_array($r90) && $r90['dataDir'] === $ug90['data'] && $r90['store_db'] === $ug90['data'] . '/plugin.sqlite3'
    && in_array('store', $r90['new'], true) && in_array('config', $r90['new'], true),
    'control: and leaves $dataDir and $store on <plugin>/data in master\'s scope — the leak 5.18.91 stops', json_encode($r90));

// A level stray store, built as here with every recorded checksum matching its file: an open changes only its folder's time
// (the -wal and -shm made and removed) — what the deploy's A14 and R19 read; its database and its migration.log stay as
// they are. (On the server a recorded checksum that no longer matches may also add a warning to that log: the docblock.)
echo "\n3b. Uganda, a level stray store as built here — at every migration\n";
$us = slr_scene('ugsrv', ['tenant' => ['tenant_profile' => 'uganda'], 'stray' => 'server']);
$b = slr_snap($us['data']); [$rc, $r] = slr_run('master', $us['root']); $a = slr_snap($us['data']);
is_($rc === 0 && is_array($r) && $r['new'] === [] && $r['dataDir'] === $us['live'], 'the job ran inside master\'s scope and stopped at its gate', json_encode($r));
is_(slr_changed($b, $a) === [] && isset($b['plugin.sqlite3'], $b['migration.log']), 'the stray folder — the database, its migration.log, the folder itself — exactly as before',
    implode(', ', slr_changed($b, $a)) . ' | ' . implode(', ', array_keys($b)));
$us90 = slr_scene('ugsrv90', ['tenant' => ['tenant_profile' => 'uganda'], 'stray' => 'server', 'job' => '5.18.90']);
$b = slr_snap($us90['data']); slr_run('master', $us90['root']); $a = slr_snap($us90['data']);
$ch = slr_changed($b, $a);
is_($ch === ['.'] && isset($b['plugin.sqlite3'], $b['migration.log']) && slr_ledger($us90['data'] . '/plugin.sqlite3') === $nAll,
    'control: the 5.18.90 job, same store, changes the folder and nothing else — its database and its migration.log as they were, the -wal and -shm made and removed: the change the deploy\'s R19 watches for',
    implode(', ', $ch));

// ── 4. Uganda with no stray store ────────────────────────────────────────────
echo "\n4. Uganda with <plugin>/data present and no database in it\n";
$un = slr_scene('ugnone', ['tenant' => ['tenant_profile' => 'uganda'], 'stray' => false]);
$b = slr_snap($un['data']);
[$rc, $r] = slr_run('master', $un['root']);
$a = slr_snap($un['data']);
is_($rc === 0 && is_array($r) && $r['dataDir'] === $un['live'] && $r['new'] === [], 'the job ran inside master\'s scope and stopped at its gate', json_encode($r));
is_(!file_exists($un['data'] . '/plugin.sqlite3') && slr_changed($b, $a) === [],
    'no database is created in <plugin>/data, and nothing else either', implode(', ', slr_changed($b, $a)));
$un90 = slr_scene('ugnone90', ['tenant' => ['tenant_profile' => 'uganda'], 'stray' => false, 'job' => '5.18.90']);
slr_run('master', $un90['root']);
is_(is_file($un90['data'] . '/plugin.sqlite3'), 'control: the 5.18.90 job creates one there');

// ── 5. Uganda by the vault alone, run on its own ─────────────────────────────
echo "\n5. Uganda selected only by the vault (UGX), the job run on its own — no other connection to the live store\n";
$uv = slr_scene('ugvault', ['tenant' => null, 'vault' => ['currency_code' => 'UGX'], 'stray' => true]);
$bl = slr_snap($uv['live']); $bs = slr_snap($uv['data']);
[$rc, , $txt] = slr_run('alone', $uv['root']);
$al = slr_snap($uv['live']); $as = slr_snap($uv['data']);
is_($rc === 0, 'the job exits cleanly', substr($txt, 0, 300));
is_(slr_changed($bs, $as) === [], 'the stray folder is untouched', implode(', ', slr_changed($bs, $as)));
is_(slr_changed($bl, $al) === [], 'the live folder is untouched too — the decision opened nothing that would create -wal or -shm',
    implode(', ', slr_changed($bl, $al)));
[, $x] = slr_run('explain', $uv['root']);
is_(is_array($x) && $x['skip'] === true && $x['store'] === 'not-read:wal-files-missing' && $x['tenant'] === 'uganda',
    'explain(): skip, decided by the vault; the live store left unread because reading it would create files', json_encode($x));

// ── 6. South Sudan: exactly the 5.18.90 job ───────────────────────────────────
echo "\n6. South Sudan: the outcome of the 5.18.90 job, exactly\n";
foreach ([['the live store says south-sudan', ['tenant_profile' => 'south-sudan']], ['nothing says anything (the default)', null]] as [$label, $tenant]) {
    $tag = $tenant === null ? 'd' : 'e';
    $ss   = slr_scene('ss' . $tag,   ['tenant' => $tenant, 'stray' => true]);
    $ss90 = slr_scene('ss90' . $tag, ['tenant' => $tenant, 'stray' => true, 'job' => '5.18.90']);
    $b = slr_snap($ss['data']);   [$rcA, $ra] = slr_run('master', $ss['root']);   $a = slr_snap($ss['data']);
    $b9 = slr_snap($ss90['data']); [$rcB, $rb] = slr_run('master', $ss90['root']); $a9 = slr_snap($ss90['data']);
    $rel = function ($v, $s) { return is_string($v) ? str_replace($s['root'], '<plugin>', $v) : $v; };
    is_($rcA === 0 && $rcB === 0 && is_array($ra) && is_array($rb) && $ra['new'] === $rb['new'],
        "{$label}: the same variables left in master's scope as 5.18.90's job leaves", json_encode([$ra['new'] ?? null, $rb['new'] ?? null]));
    is_(is_array($ra) && is_array($rb) && $rel($ra['dataDir'], $ss) === '<plugin>/data' && $rel($rb['dataDir'], $ss90) === '<plugin>/data'
        && $rel($ra['store_db'], $ss) === $rel($rb['store_db'], $ss90),
        "{$label}: \$dataDir and \$store on <plugin>/data, as before");
    is_(slr_changed($b, $a) === slr_changed($b9, $a9) && slr_changed($b, $a) !== [],
        "{$label}: the same files in <plugin>/data changed: " . implode(', ', slr_changed($b, $a)),
        json_encode([slr_changed($b, $a), slr_changed($b9, $a9)]));
    is_(slr_ledger($ss['data'] . '/plugin.sqlite3') === $nAll && slr_ledger($ss90['data'] . '/plugin.sqlite3') === $nAll,
        "{$label}: the stray store migrated, as 5.18.90 migrates it");
    is_(is_array($ra) && ($ra['locks_before'][0] ?? 0) > 0 && ($ra['locks_before'][1] ?? 0) > 0 && $ra['locks_after'] === $ra['locks_before'],
        "{$label}: master's POSIX locks on the live database and its -shm survive the job here too — the gate read the live store through SQLite alone",
        json_encode([$ra['locks_before'] ?? null, $ra['locks_after'] ?? null]));
    [, $x] = slr_run('explain', $ss['root']);
    is_(is_array($x) && $x['skip'] === false && $x['tenant'] === 'south-sudan', "{$label}: explain(): no skip, south-sudan", json_encode($x));
}
$sn   = slr_scene('ssnone',   ['tenant' => null, 'stray' => false]);
$sn90 = slr_scene('ssnone90', ['tenant' => null, 'stray' => false, 'job' => '5.18.90']);
slr_run('master', $sn['root']); slr_run('master', $sn90['root']);
is_(is_file($sn['data'] . '/plugin.sqlite3') && is_file($sn90['data'] . '/plugin.sqlite3'),
    'no stray store: the job creates one in <plugin>/data, exactly as 5.18.90\'s does — unchanged, as approved');
$sx = slr_scene('ssnolive', ['tenant' => null, 'stray' => true, 'layout' => 'nolive']);
[$rc] = slr_run('alone', $sx['root']);
is_($rc === 0 && !file_exists($sx['live']), 'run on its own where the live directory does not exist yet: the gate does not create it');

// ── 7. Uganda where <plugin>/data is the data directory ──────────────────────
echo "\n7. Uganda where ucrm.json names <plugin>/data itself: the job is on the right database and runs as before\n";
$ui   = slr_scene('uginside',   ['tenant' => ['tenant_profile' => 'uganda'], 'layout' => 'inside']);
$ui90 = slr_scene('uginside90', ['tenant' => ['tenant_profile' => 'uganda'], 'layout' => 'inside', 'job' => '5.18.90']);
[, $ra] = slr_run('master', $ui['root']); [, $rb] = slr_run('master', $ui90['root']);
is_(is_array($ra) && is_array($rb) && $ra['new'] === $rb['new'] && $ra['store_db'] === $ui['data'] . '/plugin.sqlite3',
    'the same variables and the same database as 5.18.90\'s job', json_encode([$ra, $rb]));
[, $x] = slr_run('explain', $ui['root']);
is_(is_array($x) && $x['inside'] === true && $x['skip'] === false, 'explain(): inside, no skip', json_encode($x));

// ── 8. Anything unclear ──────────────────────────────────────────────────────
echo "\n8. Anything unclear is South Sudan's answer\n";
$uc = slr_scene('unclear', ['tenant' => null, 'stray' => true]);
file_put_contents($uc['live'] . '/plugin.sqlite3', 'not a database');
touch($uc['live'] . '/plugin.sqlite3-wal'); touch($uc['live'] . '/plugin.sqlite3-shm');   // so SQLite is asked, and fails
[, $x] = slr_run('explain', $uc['root']);
is_(is_array($x) && $x['skip'] === false && $x['store'] === 'error', 'an unreadable live store, nothing else: no skip', json_encode($x));
file_put_contents($uc['plugins'] . '/.dishnet-sudan.vault.json', json_encode(['config' => ['currency_code' => 'UGX']]));
[, $x] = slr_run('explain', $uc['root']);
is_(is_array($x) && $x['skip'] === true, 'the same, with UGX in the vault: Uganda, by the vault', json_encode($x));
file_put_contents($uc['plugins'] . '/.dishnet-sudan.vault.json', '{not json');
[, $x] = slr_run('explain', $uc['root']);
is_(is_array($x) && $x['skip'] === false, 'an unreadable vault and store: no skip', json_encode($x));
$um = slr_scene('nolib', ['tenant' => ['tenant_profile' => 'uganda'], 'stray' => true]);
@unlink($um['root'] . '/lib/StarlinkRetryScope.php');                        // a hard link: unlink, never write through it
[$rc, $r, $txt] = slr_run('master', $um['root']);
is_($rc === 0 && is_array($r) && $r['dataDir'] === $um['data'] && $r['store_db'] === $um['data'] . '/plugin.sqlite3'
    && strpos($txt, 'lib/StarlinkRetryScope.php missing — running as before 5.18.91') !== false,
    'the helper missing (a partial install): the job runs as 5.18.90\'s, says so in the log, and nothing fails', json_encode($r));
$up = slr_scene('notpath', ['tenant' => ['tenant_profile' => 'uganda'], 'stray' => true]);
file_put_contents($up['root'] . '/ucrm.json', json_encode(['pluginDataDir' => ['not', 'a', 'path']]));
[$rc, $x] = slr_run('explain', $up['root']);
is_($rc === 0 && is_array($x) && $x['skip'] === false && $x['live'] === null,
    'a pluginDataDir that is no path — getDataDir() cannot take it either: explain() catches it and does not skip', json_encode($x));

// ── 9. Weakened copies ────────────────────────────────────────────────────────
/** A copy of the plugin with each [file, old, new] applied once; null when an anchor is not found exactly once. */
function slr_weakened(string $root, array $edits): ?string {
    $tmp = sys_get_temp_dir() . '/sl-weak-' . getmypid() . '-' . bin2hex(random_bytes(3));
    mkdir($tmp, 0755, true);
    foreach (scandir($root) ?: [] as $e) {
        if (in_array($e, ['.', '..', 'tests', 'docs', 'prototype', 'dishnet-mikrotik-control-plane', 'data', '.git', 'ucrm.json'], true)) continue;
        exec('cp -R ' . escapeshellarg($root . '/' . $e) . ' ' . escapeshellarg($tmp . '/'));
    }
    foreach ($edits as [$rel, $old, $new]) {
        $src = (string)@file_get_contents($tmp . '/' . $rel);
        if (substr_count($src, $old) !== 1) { exec('rm -rf ' . escapeshellarg($tmp)); return null; }
        file_put_contents($tmp . '/' . $rel, str_replace($old, $new, $src));
    }
    return $tmp;
}
if ($withMutants) {
    echo "\n9. Weakened copies must each fail this test — run without section 1, so by what the code does\n";
    $J = SLR_JOB; $H = 'lib/StarlinkRetryScope.php';
    // Each names the failure it must cause, so that a FAIL from anything else — the environment, another check — is not
    // counted as catching it.
    $MUTANTS = [
        ['the gate removed', [[$J, $gate, '']], 'the stray plugin.sqlite3 keeps its checksum'],
        ['the gate inverted', [[$J, '    return StarlinkRetryScope::skip(__DIR__);', '    return !StarlinkRetryScope::skip(__DIR__);']], 'the stray plugin.sqlite3 keeps its checksum'],
        ['the gate after the store is opened', [[$J, $gate, ''], [$J, "\$pdo = \$store->getPdo();\n",
            "\$pdo = \$store->getPdo();\nrequire_once __DIR__ . '/lib/StarlinkRetryScope.php';\nif (StarlinkRetryScope::skip(__DIR__)) return;\n"]], 'the stray plugin.sqlite3 keeps its checksum'],
        ['the gate at the including scope, leaking a variable', [[$J, $gate,
            "\$scope = __DIR__ . '/lib/StarlinkRetryScope.php';\nrequire_once \$scope;\nif (StarlinkRetryScope::skip(__DIR__)) {\n    return;\n}\n\n"]], 'the job left no variable behind'],
        ['the helper ignores the live store', [[$H, '$config = $stored + PluginConfig::read($pluginRoot, $live);', '$config = PluginConfig::read($pluginRoot, $live);']], 'the stray plugin.sqlite3 keeps its checksum'],
        ['the helper skips where <plugin>/data is the data directory', [[$H, "if (\$out['inside']) return \$out;", 'if (false) return $out;']], 'the same variables and the same database as 5.18.90\'s job'],
        ['the helper opens a WAL store whose -wal and -shm are missing', [[$H, "if (!is_file(\$db . '-wal') || !is_file(\$db . '-shm')) return [[], 'not-read:wal-files-missing'];", '']], 'the live folder is untouched too'],
        ['the helper reads the database header with fopen() (09 Oct review)', [[$H, "            // Never fopen() the database file: see the class comment. is_file() opens nothing.\n",
            "            \$h = @fopen(\$db, 'rb'); \$head = \$h ? (string)fread(\$h, 100) : ''; if (\$h) fclose(\$h);\n"]], 'POSIX locks on the live database and on its -shm survive'],
        ['the helper reads the -shm with file() (09 Oct, second review)', [[$H, "            \$pdo = new \\PDO('sqlite:' . \$db, null, null, [",
            "            \$peek = @file(\$db . '-shm');\n            \$pdo = new \\PDO('sqlite:' . \$db, null, null, ["]], 'POSIX locks on the live database and on its -shm survive'],
        ['the resolver returns a pluginDataDir that trims to nothing', [[$H, '        if (!$dataDir) {', '        if ($dataDir === null) {']], 'pluginDataDir "/" — trims to nothing: the same directory'],
        ['the helper asks getDataDir(), which creates what it chooses', [[$H, '$live = self::liveDataDir($pluginRoot);',
            "require_once __DIR__ . '/bootstrap_data.php';\n            \$live = getDataDir(\$pluginRoot);"]], 'deciding created and changed nothing'],
        ['the resolver ignores an unwritable plugins root', [[$H, '(is_dir($parent) && is_writable($parent)) ?', '(is_dir($parent)) ?']], 'a plugins root this process cannot write'],
        ['the 5.18.90 job below the gate edited', [[$J, "\$dataDir = \$pluginDir . '/data';", "\$dataDir = \$pluginDir . '/data/';"]], '$dataDir and $store on <plugin>/data, as before'],
    ];
    foreach ($MUTANTS as [$label, $edits, $expect]) {
        $tmp = slr_weakened($root, $edits);
        if ($tmp === null) { is_(false, "weakened copy \"{$label}\": every anchor occurs exactly once"); continue; }
        $out = [];
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --root=' . escapeshellarg($tmp) . ' --no-mutants --no-source 2>&1', $out, $rc);
        $fails = array_values(array_filter($out, function ($l) { return strpos($l, '  FAIL ') === 0; }));
        $hit = array_values(array_filter($fails, function ($l) use ($expect) { return strpos($l, $expect) !== false; }));
        // The named check must have PASSED in this, the unweakened, run — or its failure in the copy proves nothing.
        $passedHere = array_filter($PASSED, function ($m) use ($expect) { return strpos($m, $expect) !== false; }) !== [];
        is_($passedHere && $rc !== 0 && $hit !== [], "caught: {$label} — by \"{$expect}\"", ($passedHere ? '' : 'cannot judge: that check does not pass unweakened here; ')
            . 'exit ' . $rc . ', ' . count($fails) . ' failure(s); first: ' . ($fails ? trim(substr($fails[0], 7, 140)) : '-'));
        if ($fails) echo '         first: ' . trim(substr($fails[0], 7, 110)) . "\n";
        exec('rm -rf ' . escapeshellarg($tmp));
    }
}

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
