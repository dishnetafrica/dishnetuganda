<?php
declare(strict_types=1);
/**
 * Shared by tests/test_quote_followup.php, its sections (tests/quote_followup/<Id>_*.php) and tests/test_wa_send_budget.php
 * (5.18.104, root docs/73 §H.2.14). TEST ONLY.
 *
 * QfCtx holds the tree judged (--root), the run's own temporary folder, the tally and the case filter. The code under
 * test is loaded from a COPY of the judged tree (qf_tree()), never from the checkout itself: PluginLog writes beside the
 * code (data/plugin.log), so a line a test provokes lands in the copy. Every book is a fresh SQLite file built by the
 * copy's own migrations. Every person, number and message here is fictitious; nothing leaves 127.0.0.1.
 *
 * The weakened copies: qf_mutants() makes each copy with sj_weakened_copy()'s rule (every anchor found exactly once, or
 * the copy is a failure of the test, never a skip), lints it, and runs the test file again on it with
 * --no-mutants --root=<copy> --sections=<its sections> [--cases=<regex>]. A copy is caught only when every section it
 * names prints a FAIL line (matching its pattern, where it gives one).
 */

/** The fixed week the ledger's cases run in: Monday 2 November 2026, Kampala (UTC+3, no daylight saving). */
const QF_MONDAY = '2026-11-02';
// The zone is named by the test files (QF_TZ), never here: tests/test_timezone.php exempts only files named test_*.
if (!defined('QF_TZ')) throw new \LogicException('the test file names the zone (QF_TZ) before it loads quote_followup/_lib.php');

/** A Uganda configuration with the ledger on: support and account share one handset, sales has its own. */
const QF_UG = [
    'tenant_profile'       => 'uganda',
    'timezone'             => QF_TZ,
    'wa_send_ledger'       => 'on',
    'evo_instance_support' => 'DishNet-UG',
    'evo_instance_account' => 'DishNet-UG',
    'evo_instance_sales'   => 'Sales-UG',
];

final class QfCtx
{
    public $root;
    public $tmp;
    public $tree = '';
    public $pass = 0;
    public $fail = 0;
    /** @var string|null a regex over case names; null runs every case */
    public $cases = null;
    private $n = 0;
    private $tpl = '';

    public function __construct(string $root, string $tmp)
    {
        $this->root = rtrim($root, '/');
        $this->tmp  = rtrim($tmp, '/');
    }

    public function t(bool $ok, string $msg, string $detail = ''): void
    {
        if ($ok) { $this->pass++; echo "  ok   {$msg}\n"; return; }
        $this->fail++;
        echo "  FAIL {$msg}" . ($detail !== '' ? "\n       " . substr($detail, 0, 1200) : '') . "\n";
    }

    public function eq($got, $want, string $msg): void
    {
        $this->t($got === $want, $msg, 'got ' . json_encode($got, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            . ' want ' . json_encode($want, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /** One named case: skipped when the filter does not match; a throwable is a FAIL, never an abort. */
    public function run(string $name, callable $fn): void
    {
        if ($this->cases !== null && !preg_match('/' . $this->cases . '/', $name)) return;
        echo "  -- {$name}\n";
        try {
            $fn();
        } catch (\Throwable $e) {
            $this->t(false, "{$name} ran to its end", get_class($e) . ': ' . $e->getMessage() . ' @ '
                . basename($e->getFile()) . ':' . $e->getLine());
        }
    }

    public function dir(string $what): string
    {
        $d = $this->tmp . '/' . $what . '-' . (++$this->n);
        @mkdir($d, 0777, true);
        return $d;
    }

    /** The copy of the judged tree the code is loaded from (made once per process). */
    public function tree(): string
    {
        if ($this->tree !== '') return $this->tree;
        $this->tree = $this->tmp . '/tree';
        qf_copy_tree($this->root, $this->tree);
        return $this->tree;
    }

    /** A fresh book (plugin.sqlite3) on the copy's migrations, in a data directory of its own. */
    public function book(array $cfg = [], string $tag = 'book'): array
    {
        $tree = $this->tree();
        require_once $tree . '/lib/StoreInterface.php';
        require_once $tree . '/lib/SqliteStore.php';
        if ($this->tpl === '') {
            $this->tpl = $this->dir('tpl');
            $s = SqliteStore::create($this->tpl);
            $s->getPdo()->exec('PRAGMA wal_checkpoint(TRUNCATE)');
            unset($s);
        }
        $d = $this->dir($tag);
        copy($this->tpl . '/plugin.sqlite3', $d . '/plugin.sqlite3');
        $store = SqliteStore::create($d);
        $cfg = $cfg + ['_data_dir' => $d];
        return ['store' => $store, 'pdo' => $store->getPdo(), 'data' => $d, 'cfg' => $cfg];
    }

    /** The lines of the copy's data/plugin.log that contain $needle. */
    public function logLines(string $needle): int
    {
        $f = $this->tree() . '/data/plugin.log';
        if (!is_file($f)) return 0;
        $n = 0;
        foreach (file($f) ?: [] as $l) if (strpos($l, $needle) !== false) $n++;
        return $n;
    }
}

/** Copy a plugin tree, leaving out what no section loads; data/ is made empty. */
function qf_copy_tree(string $root, string $dest): void
{
    @mkdir($dest, 0700, true);
    foreach (scandir($root) ?: [] as $e) {
        if ($e === '.' || $e === '..' || in_array($e, ['tests', 'docs', 'prototype', 'dishnet-mikrotik-control-plane', 'data', '.git'], true)) continue;
        exec('cp -R ' . escapeshellarg($root . '/' . $e) . ' ' . escapeshellarg($dest . '/') . ' 2>/dev/null');
    }
    @mkdir($dest . '/data', 0700, true);
}

/** Unix time of a Kampala wall-clock moment, e.g. qf_t('2026-11-02 08:30:00'). */
function qf_t(string $kampala): int
{
    return (new \DateTime($kampala, new \DateTimeZone(QF_TZ)))->getTimestamp();
}

/** A JSON HTTP call with no proxy. [code, body as array|null, raw]. */
function qf_http(string $method, string $url, $body = null, array $headers = [], int $timeout = 15): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 3,
                            CURLOPT_PROXY => '', CURLOPT_CUSTOMREQUEST => $method]);
    if ($body !== null) {
        curl_setopt_array($ch, [CURLOPT_POSTFIELDS => is_string($body) ? $body : json_encode($body),
                                CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json'], $headers)]);
    } elseif ($headers !== []) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    }
    $raw = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, $raw === false ? null : json_decode((string)$raw, true), $raw === false ? '' : (string)$raw];
}

/**
 * Serve a script or a document root with php -S on a free port, proved by $probe; [port, process]. The process is
 * stopped at shutdown.
 */
function qf_serve(string $args, callable $probe, int $base, array $env = []): array
{
    foreach (range(0, 19) as $slot) {
        $port = $base + ((getmypid() * 7 + $slot * 31) % 600);
        if (@fsockopen('127.0.0.1', $port, $en, $es, 0.2)) continue;
        $e = array_filter(getenv(), function ($k) { return stripos((string)$k, 'proxy') === false; }, ARRAY_FILTER_USE_KEY);
        $p = proc_open('exec php -S 127.0.0.1:' . $port . ' ' . $args,
            [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, null, $env + $e);
        for ($i = 0; $i < 60; $i++) {
            usleep(100000);
            if ($probe($port)) {
                register_shutdown_function(function () use ($p) { if (is_resource($p)) { proc_terminate($p); proc_close($p); } });
                return [$port, $p];
            }
        }
        if (is_resource($p)) { proc_terminate($p); proc_close($p); }
    }
    return [0, null];
}

/** The repository's fake Evolution (tests/fixtures/fake_evo_server.php), reset; its base URL, or '' when it would not start. */
function qf_fake_evo(): string
{
    static $base = null;
    if ($base !== null) { qf_http('GET', $base . '/__test/reset'); return $base; }
    [$port] = qf_serve(escapeshellarg(dirname(__DIR__) . '/fixtures/fake_evo_server.php'), function (int $p): bool {
        return strpos(qf_http('GET', "http://127.0.0.1:{$p}/__test/state", null, [], 2)[2], 'FAKE-EVO-TEST') !== false;
    }, 13100);
    $base = $port === 0 ? '' : "http://127.0.0.1:{$port}";
    if ($base !== '') qf_http('GET', $base . '/__test/reset');
    return $base;
}

/** Take the runner's options: --root, --sections (comma list), --cases (regex), --no-mutants, --mutants (regex). */
function qf_options(): array
{
    $opt = getopt('', ['root:', 'sections:', 'cases:', 'no-mutants', 'mutants:']);
    return [
        'root'     => isset($opt['root']) ? rtrim((string)$opt['root'], '/') : dirname(__DIR__, 2),
        'sections' => isset($opt['sections']) ? array_filter(explode(',', (string)$opt['sections'])) : null,
        'cases'    => isset($opt['cases']) ? (string)$opt['cases'] : null,
        'mutants'  => !isset($opt['no-mutants']) && !isset($opt['root']),
        'only'     => isset($opt['mutants']) ? (string)$opt['mutants'] : null,
    ];
}

/** A temporary folder of the run's own, removed at shutdown; TMPDIR and the vault point into it. */
function qf_own_tmp(string $tag): string
{
    $own = rtrim((string)(getenv('TMPDIR') ?: sys_get_temp_dir()), '/') . '/' . $tag . '-' . getmypid() . '-' . bin2hex(random_bytes(3));
    mkdir($own, 0700, true);
    putenv('TMPDIR=' . $own);
    putenv('DN_VAULT_FILE=' . $own . '/vault.json');
    foreach (['http_proxy', 'https_proxy', 'HTTP_PROXY', 'HTTPS_PROXY', 'ALL_PROXY', 'all_proxy'] as $v) putenv($v);
    register_shutdown_function(function () use ($own) { exec('rm -rf ' . escapeshellarg($own)); });
    return $own;
}

/** Run every section whose id is selected. $sections: id => ['title' => …, 'run' => fn(QfCtx)]. */
function qf_run_sections(QfCtx $ctx, array $sections, ?array $only): void
{
    foreach ($sections as $id => $sec) {
        if ($only !== null && !in_array($id, $only, true)) continue;
        echo "\n{$id}. {$sec['title']}\n";
        try {
            ($sec['run'])($ctx);
        } catch (\Throwable $e) {
            $ctx->t(false, "{$id} ran to its end", get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
        }
    }
}

/**
 * The weakened copies. Each: label, file/old/new or edits [[file, old, new], …], sections ('LG' or 'A,F'), optional
 * pattern (a FAIL line must match it), optional cases (a regex the child runs alone, to keep slow cases out).
 */
function qf_mutants(QfCtx $ctx, string $testFile, array $mutants, ?string $only): void
{
    require_once dirname(__DIR__) . '/fixtures/staff_jobs_sandbox.php';   // sj_weakened_copy()
    echo "\nW. Weakened copies (" . count($mutants) . ")\n";
    foreach ($mutants as $m) {
        if ($only !== null && !preg_match('/' . $only . '/', $m['label'])) continue;
        $edits = $m['edits'] ?? [[$m['file'], $m['old'], $m['new']]];
        [$copy, $n] = sj_weakened_copy($ctx->root, $edits[0][0], $edits[0][1], $edits[0][2]);
        $bad = $n === 1 ? '' : "anchor 1 found {$n} times";
        foreach (array_slice($edits, 1) as $i => $e) {
            if ($bad !== '') break;
            [$rel, $old, $new] = $e;
            $src = (string)@file_get_contents($copy . '/' . $rel);
            $k = substr_count($src, $old);
            if ($k !== 1) { $bad = 'anchor ' . ($i + 2) . " found {$k} times"; break; }
            file_put_contents($copy . '/' . $rel, str_replace($old, $new, $src));
        }
        foreach ($edits as $e) {
            if ($bad === '' && substr($e[0], -4) === '.php') {
                $lo = [];
                exec('php -l ' . escapeshellarg($copy . '/' . $e[0]) . ' 2>&1', $lo, $lrc);
                if ($lrc !== 0) $bad = "the copy's {$e[0]} does not lint";
            }
        }
        if ($bad !== '') { $ctx->t(false, "{$m['label']}: the copy was made", $bad); exec('rm -rf ' . escapeshellarg($copy)); continue; }
        $out = [];
        $want = array_values(array_filter(explode(',', $m['sections'])));
        exec('php ' . escapeshellarg($testFile) . ' --no-mutants --root=' . escapeshellarg($copy)
            . ' --sections=' . escapeshellarg(implode(',', $want))
            . (isset($m['cases']) ? ' --cases=' . escapeshellarg($m['cases']) : '') . ' 2>&1', $out);
        $failedIn = []; $cur = '';
        foreach ($out as $l) {
            if (preg_match('/^([A-Z]{1,2})\. /', $l, $h)) { $cur = $h[1]; continue; }
            if (strpos($l, '  FAIL ') === 0 && (!isset($m['pattern']) || preg_match($m['pattern'], $l))) $failedIn[$cur] = true;
        }
        $missed = array_values(array_filter($want, function ($s) use ($failedIn) { return empty($failedIn[$s]); }));
        $ctx->t($missed === [], "{$m['label']} is caught by " . implode(', ', $want),
            'not caught in: ' . implode(', ', $missed) . "\n" . implode("\n", array_slice($out, -25)));
        exec('rm -rf ' . escapeshellarg($copy));
    }
}
