<?php
declare(strict_types=1);
/**
 * _lib.php — 5.18.105 (root docs/74 §H.1.0, §H.3.4): shared by tests/test_log_mask.php and its sections
 * (tests/log_mask/<Id>_*.php). TEST ONLY.
 *
 * The context is 5.18.104's QfCtx (tests/quote_followup/_lib.php): the tree judged (--root), the run's own folder, the
 * tally and the case filter, the code loaded from a COPY of the judged tree. On top of it, what every masked line's drive
 * asserts (§H.1.0 "Assertions for every LN"):
 *
 *   A1  the positive control: the line is written, found by its fixed words (LM_LINES 'fixed');
 *   A2  no form of the fixture number appears in it: not E.164, not the digits alone, not the national form, not the
 *       spaced form, and not its last nine digits as one run;
 *   A3  with the line's own timestamp prefix removed, nothing in it matches (?<![\d.])\d{9,}(?!\d) — the decimal guard
 *       because cron_leads.php prints {$hoursIdle} as a raw float.
 *
 * Fixture numbers are synthetic, +2567000001xx: LN-nn's own is +2567000001nn (lm_num()), a second one in the same drive
 * +2567000001(nn+50). Names are "Test …", staff appear by role, addresses are example.test. Nothing leaves 127.0.0.1.
 *
 * The weakened copies (lm_mutants): 5.18.104's qf_mutants() rule — every anchor found exactly once, or the copy is a
 * failure of this test, never a skip; the copy is linted, then judged by THIS file run again with --no-mutants
 * --root=<copy> --sections=<its sections> [--cases=<regex>] — with three additions §H.1.0 needs:
 *   patterns  each pattern must match some FAIL line (one copy, several LNs: 994d660's own file for each log file);
 *   base      files replaced whole by 994d660's, from Git (the control "on 994d660's own file");
 *   unmarked  for a copy that plants an unmarked edit: each file named is asserted, from the planted copy, to carry a
 *             hunk the layer leaves in place, at least 31 lines from every 5.18.105 marker and not one that only closes a
 *             block (r105_planted_outside, tests/fixtures/r105_revert.php) — else the copy FAILS as not planted, never
 *             skips (§H.1.0, §H.3.4 "Where each planted edit stands"). The copies "through the layer" are the re-pointed
 *             tests' own (tests/fixtures/r105_revert.php names them).
 */
if (!defined('QF_TZ')) throw new \LogicException('tests/test_log_mask.php names the zone (QF_TZ) before it loads log_mask/_lib.php');
require_once dirname(__DIR__) . '/quote_followup/_lib.php';
require_once __DIR__ . '/declared.php';
require_once __DIR__ . '/lines.php';

/** LN-nn's own fixture number (E.164): +2567000001nn; a drive's second number is lm_num($nn + 50). */
function lm_num(int $xx): string
{
    if ($xx < 0 || $xx > 99) throw new \InvalidArgumentException('lm_num: 00…99');
    return '+2567000001' . sprintf('%02d', $xx);
}

/** The four written forms of a +256 fixture number (§H.1.0 "Fixtures"): E.164, digits, national, spaced. */
function lm_forms(string $e164): array
{
    $d = (string)preg_replace('/\D+/', '', $e164);
    $nat = '0' . substr($d, 3);                                       // 256700000131 → 0700000131
    $sp  = substr($nat, 0, 4) . ' ' . substr($nat, 4, 3) . ' ' . substr($nat, 7);
    return ['+' . $d, $d, $nat, $sp];
}

/** A2: the forms of $numbers found in $text, and any number's last nine digits as one run; [] when none. */
function lm_number_hits(string $text, array $numbers): array
{
    $hits = [];
    foreach ($numbers as $n) {
        foreach (lm_forms($n) as $f) if (strpos($text, $f) !== false) $hits[] = $f;
        $nine = substr((string)preg_replace('/\D+/', '', $n), -9);
        if (strpos($text, $nine) !== false) $hits[] = 'last nine ' . $nine;
    }
    return array_values(array_unique($hits));
}

/** A3: every run of nine digits or more not part of a decimal; [] when none. */
function lm_long_runs(string $text): array
{
    preg_match_all('/(?<![\d.])\d{9,}(?!\d)/', $text, $m);
    return $m[0];
}

/** A line with its own time prefix(es) removed: "[09-Nov-2026 07:25:54 UTC] ", "[2026-11-09 07:25:54] ", "[07:25:54] ". */
function lm_unprefix(string $line): string
{
    while (preg_match('/^\s*\[[^\]\n]*\d{1,2}:\d{2}(?::\d{2})?[^\]\n]*\]\s?/', $line, $m)) $line = substr($line, strlen($m[0]));
    return $line;
}

/** The lines of a text, each unprefixed; empty lines dropped. */
function lm_lines(string $text): array
{
    $out = [];
    foreach (preg_split('/\r?\n/', $text) ?: [] as $l) if (trim($l) !== '') $out[] = lm_unprefix($l);
    return $out;
}

/**
 * A JSON log (whLog's webhook_log.json, qwa_log's quote_wa_log.json, logActivity's activity_log.json) as judged lines:
 * one per entry, the entry with its time keys left out (they are the line's own stamp), encoded as the file holds it.
 */
function lm_json_entries(string $file): array
{
    $j = json_decode((string)@file_get_contents($file), true);
    $out = [];
    foreach (is_array($j) ? $j : [] as $e) {
        if (!is_array($e)) continue;
        foreach (['time', 'ts', 'timestamp', 'at', 'received_at', 'created_at'] as $k) unset($e[$k]);
        $out[] = json_encode($e, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
    return $out;
}

/**
 * A1–A3 for one LN over the lines a sink holds: A1 finds the lines carrying $fixed (at least $min of them); A2 and A3
 * judge every one found. Returns the lines found. Each assertion is named "LN-nn A1 …", "LN-nn A2 …", "LN-nn A3 …", so a
 * weakened copy's pattern can name the assertion it must fail.
 */
function lm_judge(QfCtx $ctx, string $ln, array $lines, string $fixed, array $numbers, int $min = 1, string $where = ''): array
{
    $found = array_values(array_filter($lines, function ($l) use ($fixed) { return strpos($l, $fixed) !== false; }));
    $show = substr(implode(' | ', $found !== [] ? $found : array_slice($lines, -6)), 0, 900);
    $ctx->t(count($found) >= $min, "{$ln} A1 the line is written" . ($where !== '' ? " ({$where})" : '') . ': "' . $fixed . '" × ' . count($found), $show);
    $hits = [];
    $runs = [];
    foreach ($found as $l) { $hits = array_merge($hits, lm_number_hits($l, $numbers)); $runs = array_merge($runs, lm_long_runs($l)); }
    $ctx->eq(array_values(array_unique($hits)), [], "{$ln} A2 no form of the fixture number, nor its last nine digits, in the line");
    $ctx->eq(array_values(array_unique($runs)), [], "{$ln} A3 no run of nine digits or more in the line, its time prefix removed");
    return $found;
}

/** The new form's words in the lines found (each LN's extra assertion). */
function lm_form(QfCtx $ctx, string $ln, array $found, string $form, string $what = ''): void
{
    $n = count(array_filter($found, function ($l) use ($form) { return strpos($l, $form) !== false; }));
    $ctx->t($found !== [] && $n === count($found), "{$ln} the line reads its new form: \"{$form}\"" . ($what !== '' ? " — {$what}" : ''),
        substr(implode(' | ', $found), 0, 600));
}

/** One LN's judge with its LM_LINES fixed words and form, over $lines; returns the lines found. */
function lm_ln(QfCtx $ctx, string $ln, array $lines, array $numbers, int $min = 1, string $where = ''): array
{
    $found = lm_judge($ctx, $ln, $lines, LM_LINES[$ln]['fixed'], $numbers, $min, $where);
    lm_form($ctx, $ln, $found, LM_LINES[$ln]['form']);
    return $found;
}

// ── Running the code under test ─────────────────────────────────────────────────────────────────────────────────────

/**
 * A light sandbox for a CLI job: a copy of the judged tree of its own, its ucrm.json naming a data directory of its own,
 * the store made by the copy's migrations and the configuration written to the file and the store, as SjSandbox writes
 * them. [plug, data]. $src: another plugin tree to copy (994d660's, for section B); the judged tree by default. Removed
 * at exit with the run's folder.
 */
function lm_cli_box(QfCtx $ctx, array $cfg, string $tag, string $src = ''): array
{
    $box  = $ctx->dir('box-' . $tag);
    $plug = $box . '/plugin';
    $data = $plug . '/data';
    qf_copy_tree($src !== '' ? $src : $ctx->root, $plug);
    @mkdir($data, 0700, true);
    file_put_contents($plug . '/ucrm.json', json_encode(['pluginDataDir' => $data]));
    lm_store_classes($ctx->root);
    $store = \SqliteStore::create($data);
    $cfg = $cfg + ['data_dir' => $data, 'dry_run_mode' => false, 'customer_emails_enabled' => '0'];
    $store->save('kyc_config.json', $cfg);
    file_put_contents($data . '/kyc_config.json', json_encode($cfg));
    // never the piggyback cron, never a real host (staff_jobs_sandbox.php's reason)
    file_put_contents($data . '/piggyback_last_run.txt', (string)(time() + 10 * 365 * 86400));
    return [$plug, $data];
}

/**
 * The store classes, loaded in this process from the judged root and from nowhere else: NotifyHarness requires them from
 * the root it is given (by path, unguarded), so a second copy loaded from another path would be a redeclared class. For
 * the same reason no section here calls QfCtx::book(), which loads them from the tree copy; and every other class a
 * section loads in process comes from the judged root too (lm_lib()). The code each drive judges runs in a process of
 * its own — a served PHP, a cron run, tests/log_mask/side_direct.php — from a copy of the judged tree.
 */
function lm_store_classes(string $root): void
{
    if (!class_exists('SqliteStore')) {
        require_once $root . '/lib/StoreInterface.php';
        require_once $root . '/lib/SqliteStore.php';
    }
}

/** A library class of the judged root, loaded in this process (once; see lm_store_classes()). */
function lm_lib(QfCtx $ctx, string $class): void
{
    lm_store_classes($ctx->root);
    if (!class_exists($class)) require_once $ctx->root . '/lib/' . $class . '.php';
}

/** A PDO on a data directory's book, exceptions on. */
function lm_pdo(string $data): \PDO
{
    $p = new \PDO('sqlite:' . $data . '/plugin.sqlite3');
    $p->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    $p->setAttribute(\PDO::ATTR_TIMEOUT, 5);
    return $p;
}

/**
 * Run a plugin script inside a box, PHP's log to $errFile (-d error_log=FILE, §H.1.0 "The drive"). [exit code, stdout+stderr].
 */
function lm_cli_run(string $plug, string $data, string $script, string $errFile, array $args = [], int $timeout = 120): array
{
    $cmd = sprintf('cd %s && DN_DATA_DIR=%s DN_VAULT_FILE=%s timeout %d %s -d log_errors=1 -d display_errors=0 -d error_log=%s %s %s 2>&1',
        escapeshellarg($plug), escapeshellarg($data), escapeshellarg((string)getenv('DN_VAULT_FILE')), $timeout, escapeshellarg(PHP_BINARY),
        escapeshellarg($errFile), escapeshellarg($script), implode(' ', array_map('escapeshellarg', $args)));
    $out = [];
    exec($cmd, $out, $rc);
    return [$rc, implode("\n", $out)];
}

/**
 * Serve a document root with php -S, PHP's log going to $errFile; [base url, process]. The process is stopped at
 * shutdown. $probe(port) proves the server is this one. $ini: more -d settings for the served PHP (G01 passes
 * disable_functions=exec, as tests/fixtures/staff_jobs_sandbox.php does, so the webhook spawns no run_worker.php that
 * outlives the run).
 */
function lm_serve_root(string $docroot, string $errFile, callable $probe, int $base, array $env = [], array $ini = []): array
{
    $dIni = '';
    foreach ($ini as $k => $v) $dIni .= ' -d ' . escapeshellarg($k . '=' . $v);
    $e = array_filter(getenv(), function ($k) { return stripos((string)$k, 'proxy') === false; }, ARRAY_FILTER_USE_KEY);
    foreach (range(0, 19) as $slot) {
        $port = $base + ((getmypid() * 7 + $slot * 31) % 600);
        if (@fsockopen('127.0.0.1', $port, $en, $es, 0.2)) continue;
        $p = proc_open('exec ' . escapeshellarg(PHP_BINARY) . ' -d log_errors=1 -d display_errors=0 -d error_log=' . escapeshellarg($errFile) . $dIni
            . ' -S 127.0.0.1:' . $port . ' -t ' . escapeshellarg($docroot),
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, null, $env + $e);
        for ($i = 0; $i < 60; $i++) {
            usleep(100000);
            if (!proc_get_status($p)['running']) break;
            if ($probe($port)) {
                register_shutdown_function(function () use ($p) { if (is_resource($p)) { proc_terminate($p); proc_close($p); } });
                return ["http://127.0.0.1:{$port}", $p];
            }
        }
        if (is_resource($p)) { proc_terminate($p); proc_close($p); }
    }
    return ['', null];
}

/** One of the fakes under tests/log_mask/, served on a free port and proved by its marker; its base URL, or ''. */
function lm_fake(string $script, string $marker, int $base, array $env = []): string
{
    [$port] = qf_serve(escapeshellarg($script), function (int $p) use ($marker): bool {
        return strpos(qf_http('GET', "http://127.0.0.1:{$p}/__test/state", null, [], 2)[2], $marker) !== false;
    }, $base, $env);
    return $port === 0 ? '' : "http://127.0.0.1:{$port}";
}

/**
 * The older fault two drives work around (G09, G10), measured the same on 994d660's own files: a keyed state object
 * saved to a table that lib/SqliteStore.php's $FLAT_TABLES does not list is written as one row, but load() reads it back
 * as a one-element list, so its keys are never found — the store warns so at the save ("… is not in $FLAT_TABLES — its
 * keys will be stripped when it is read back. Add the table to that list."). The patch is exactly that entry, appended
 * to the list in a box's own copy of lib/SqliteStore.php: [anchor, replacement]. Nothing else in the store moves.
 */
function lm_flat_patch(string $table): array
{
    if (!preg_match('/^[a-z0-9_]+$/', $table)) throw new \InvalidArgumentException('lm_flat_patch: a table name');
    return ["        'hotspot_config',\n    ];",
            "        'hotspot_config',\n        '{$table}',   // TEST ONLY (tests/log_mask, lm_flat_patch()): the older fault\n    ];"];
}

/** lm_flat_patch($table) planted in a box's copy ($plug/lib/SqliteStore.php): the anchor found once, the result there once. */
function lm_plant_flat(string $plug, string $table): void
{
    [$old, $new] = lm_flat_patch($table);
    $f = $plug . '/lib/SqliteStore.php';
    $src = (string)file_get_contents($f);
    if (substr_count($src, $old) !== 1) throw new \LogicException("lm_plant_flat: the \$FLAT_TABLES anchor is found " . substr_count($src, $old) . " times in {$f}");
    file_put_contents($f, str_replace($old, $new, $src));
    if (substr_count((string)file_get_contents($f), $new) !== 1) throw new \LogicException("lm_plant_flat: '{$table}' was not planted in {$f}");
}
/** Run tests/log_mask/side_direct.php on a spec file: [the JSON object it printed last ([] when none), all its output]. */
function lm_side(string $spec): array
{
    $raw = (string)shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/side_direct.php') . ' ' . escapeshellarg($spec) . ' 2>&1');
    $lines = array_values(array_filter(array_map('trim', explode("\n", $raw)), 'strlen'));
    $j = $lines === [] ? null : json_decode((string)end($lines), true);
    return [is_array($j) ? $j : [], $raw];
}

/**
 * A fake uCRM for the two drives whose path asks uCRM for what tests/fixtures/fake_ucrm_staff_jobs.php does not answer
 * (LN-15: a payment's receipt PDF; LN-35: POST refunds): a router generated in the run's own folder — never a file of the
 * repository — that answers those two paths itself and hands every other request to fake_ucrm_staff_jobs.php unchanged
 * (its seed, its state, its marker). Its base URL, or ''. The design names the fake uCRM LN-35 needs (§H.1.19); LN-15's
 * receipt PDF is answered the same way (§H.1.10). Every value is fictitious.
 */
function lm_ucrm_wrapper(QfCtx $ctx, string $tag): string
{
    $dir = $ctx->dir($tag . '-ucrm-wrap');
    $router = $dir . '/router.php';
    $code = <<<'ROUTER'
<?php
// TEST ONLY — generated by tests/log_mask/_lib.php lm_ucrm_wrapper(): two uCRM answers, then the repository's fake uCRM.
$lmP = (string)preg_replace('#^/api/v[0-9.]+#', '', (string)(parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?: ''));
$lmM = (string)($_SERVER['REQUEST_METHOD'] ?? 'GET');
if ($lmP === '/__lm/ping') { echo 'LM-UCRM-WRAPPER'; exit; }
if ($lmM === 'POST' && $lmP === '/refunds') {
    http_response_code(201);
    header('Content-Type: application/json');
    echo json_encode(['id' => 7135]);
    exit;
}
if ($lmM === 'GET' && preg_match('#^/payments/(\d+)/pdf$#', $lmP, $lmX)) {
    header('Content-Type: application/pdf');
    echo "%PDF-1.4\n% LM-UCRM-WRAPPER receipt {$lmX[1]}\n" . str_repeat("0 0 obj << /Type /Fake >> endobj\n", 40) . "%%EOF\n";
    exit;
}
require __LM_FAKE__;
ROUTER;
    $fake = var_export($ctx->root . '/tests/fixtures/fake_ucrm_staff_jobs.php', true);
    if (substr_count($code, '__LM_FAKE__') !== 1) throw new \LogicException('lm_ucrm_wrapper: the template lost its placeholder');
    file_put_contents($router, str_replace('__LM_FAKE__', $fake, $code) . "\n");
    [$port] = qf_serve(escapeshellarg($router), function (int $p): bool {
        return trim(qf_http('GET', "http://127.0.0.1:{$p}/__lm/ping", null, [], 2)[2]) === 'LM-UCRM-WRAPPER';
    }, 14600, ['FAKE_UCRM_STATE' => $dir . '/state.json']);
    return $port === 0 ? '' : "http://127.0.0.1:{$port}";
}

/** Which assertions of a test's output FAILed: the FAIL lines. */
function lm_fail_lines(array $out): array
{
    return array_values(array_filter($out, function ($l) { return strpos($l, '  FAIL ') === 0 || strpos($l, 'FAIL ') === 0; }));
}

// ── The weakened copies ─────────────────────────────────────────────────────────────────────────────────────────────

/**
 * Each copy: label; edits [[file, old, new], …] (each old found exactly once, replaced once, the new then present); base
 * [file, …] (replaced whole by 994d660's); files [path => content] (added to the copy, never over a file it has);
 * unmarked [file, …] (each asserted to carry an edit the layer leaves in place,
 * ≥ 31 lines from every marker, not a closer); sections and cases (this file run on the copy); pattern or patterns (each
 * must match a FAIL line of the sections named). A copy is caught only when every section it names FAILs, with each
 * pattern matched.
 */
function lm_mutants(QfCtx $ctx, string $testFile, array $mutants, ?string $only): void
{
    require_once dirname(__DIR__) . '/fixtures/staff_jobs_sandbox.php';   // sj_weakened_copy()
    echo "\nW. Weakened copies (" . count($mutants) . ")\n";
    $base = null;
    foreach ($mutants as $m) {
        if ($only !== null && !preg_match('/' . $only . '/', $m['label'])) continue;
        $edits = $m['edits'] ?? [];
        $bad = '';
        if ($edits !== []) {
            [$copy, $n] = sj_weakened_copy($ctx->root, $edits[0][0], $edits[0][1], $edits[0][2]);
            if ($n !== 1) $bad = "anchor 1 found {$n} times in {$edits[0][0]}";
        } else {
            [$copy] = sj_weakened_copy($ctx->root, 'manifest.json', "\0never\0", '');
        }
        foreach (array_slice($edits, 1) as $i => $e) {
            if ($bad !== '') break;
            [$rel, $old, $new] = $e;
            $src = (string)@file_get_contents($copy . '/' . $rel);
            $k = substr_count($src, $old);
            if ($k !== 1) { $bad = 'anchor ' . ($i + 2) . " found {$k} times in {$rel}"; break; }
            file_put_contents($copy . '/' . $rel, str_replace($old, $new, $src));
        }
        foreach ($edits as $i => $e) {
            if ($bad !== '' || $e[2] === '') continue;
            $k = substr_count((string)@file_get_contents($copy . '/' . $e[0]), $e[2]);
            if ($k < 1) $bad = 'edit ' . ($i + 1) . " left no replacement in {$e[0]}";
        }
        if ($bad === '' && !empty($m['base'])) {
            if ($base === null) { [$base, $why] = r105_base($ctx->dir('mut-base')); if ($base === null) { $bad = $why; $base = null; } }
            foreach ((array)$m['base'] as $rel) {
                if ($bad !== '') break;
                if (!is_file($base . '/' . $rel)) { $bad = "994d660 has no {$rel}"; break; }
                copy($base . '/' . $rel, $copy . '/' . $rel);
            }
        }
        foreach ((array)($m['files'] ?? []) as $rel => $content) {   // a file the copy adds (a planted migration)
            if ($bad !== '') break;
            if (file_exists($copy . '/' . $rel)) { $bad = "{$rel} already exists in the copy"; break; }
            @mkdir(dirname($copy . '/' . $rel), 0777, true);
            if (file_put_contents($copy . '/' . $rel, (string)$content) !== strlen((string)$content)) $bad = "{$rel} was not written";
        }
        foreach ((array)($m['unmarked'] ?? []) as $rel) {
            if ($bad !== '') break;
            $why = r105_planted_outside($copy, $rel);
            if ($why !== '') $bad = 'not planted: ' . $why;
        }
        foreach (array_merge(array_column($edits, 0), (array)($m['base'] ?? [])) as $rel) {
            if ($bad === '' && substr($rel, -4) === '.php') {
                $lo = [];
                exec('php -l ' . escapeshellarg($copy . '/' . $rel) . ' 2>&1', $lo, $lrc);
                if ($lrc !== 0) $bad = "the copy's {$rel} does not lint";
            }
        }
        if ($bad !== '') { $ctx->t(false, "{$m['label']}: the copy was made", $bad); exec('rm -rf ' . escapeshellarg($copy)); continue; }

        $out = [];
        $want = array_values(array_filter(explode(',', $m['sections'])));
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($testFile) . ' --no-mutants --root=' . escapeshellarg($copy)
            . ' --sections=' . escapeshellarg(implode(',', $want))
            . (isset($m['cases']) ? ' --cases=' . escapeshellarg($m['cases']) : '') . ' 2>&1', $out);
        $pats = isset($m['patterns']) ? (array)$m['patterns'] : (isset($m['pattern']) ? [$m['pattern']] : []);
        $failedIn = []; $matched = []; $cur = '';
        foreach ($out as $l) {
            if (preg_match('/^([A-Z]{1,2})\. /', $l, $h)) { $cur = $h[1]; continue; }
            if (strpos($l, '  FAIL ') !== 0) continue;
            if ($pats === []) { $failedIn[$cur] = true; continue; }
            foreach ($pats as $i => $p) if (preg_match($p, $l)) { $failedIn[$cur] = true; $matched[$i] = true; }
        }
        $missed = array_values(array_filter($want, function ($s) use ($failedIn) { return empty($failedIn[$s]); }));
        $unmatched = array_values(array_diff_key($pats, $matched));
        $ctx->t($missed === [] && $unmatched === [], "{$m['label']} is caught by " . implode(', ', $want),
            ($missed !== [] ? 'not caught in: ' . implode(', ', $missed) . "\n" : '') . ($unmatched !== [] ? 'no FAIL line matched: ' . implode(' ', $unmatched) . "\n" : '')
            . implode("\n", array_slice($out, -25)));
        exec('rm -rf ' . escapeshellarg($copy));
    }
}

/** The weakened line copy of one LN (§H.1.0): its new text found once, replaced once by 994d660's; A2 must fail. */
function lm_line_copy(string $ln, string $cases): array
{
    $l = LM_LINES[$ln];
    return ['label' => "{$ln}-w {$l['file']}: 994d660's line put back", 'edits' => [[$l['file'], $l['new'], $l['old']]],
            'sections' => 'A', 'cases' => $cases, 'pattern' => '/' . preg_quote($ln, '/') . ' A2 /'];
}
