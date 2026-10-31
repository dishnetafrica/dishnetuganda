<?php
declare(strict_types=1);
/**
 * Shared by sections F and G of tests/test_quote_from_ucrm.php (5.18.102, docs/71 §J, §K). TEST ONLY.
 *
 * qfu_trees(): two writable plugin trees for one run — "new", a copy of the tree judged (--root, a weakened copy
 * included), and "old", 5.18.101's release commit f2055baf taken from Git (git archive, never the working tree). Every
 * gate-off comparison runs the same steps on both and must find them identical; the "old" tree is null when Git cannot
 * supply the commit, and the caller fails — never skips.
 *
 * It loads tests/fixtures/quote_company_journey.php (qcj_*: the fake servers, books, probes). Every person, number,
 * address and amount is invented (TEST names, example.test, +2567000001xx). Nothing leaves the machine.
 */
require_once __DIR__ . '/../fixtures/quote_company_journey.php';

const QFU_REL_COMMIT = 'f2055baf';

// qcj_php_raw() keeps its probes in <temp>/qcj_probe_<pid> and removes each file, not the folder; this process's own is
// removed when it ends (rmdir: only if empty), so a run leaves nothing behind in the temp directory.
register_shutdown_function(function () { @rmdir(sys_get_temp_dir() . '/qcj_probe_' . getmypid()); });

/** @return array{old:?string,new:string} */
function qfu_trees(QfuCtx $c): array
{
    static $memo = [];
    if (isset($memo[$c->root])) return $memo[$c->root];
    $base = $c->dir('trees');
    // Both trees carry the plugin's own directory name: a page prints it ("?plugin=" + its basename).
    $new  = $base . '/new/dishnet-hybrid-sudan';
    qcj_copy_tree($c->root, $new);
    $old = null;
    // The sections are always loaded from this checkout (the runner judges a copy with --root), so Git is asked here.
    $top = trim((string)shell_exec('git -C ' . escapeshellarg(__DIR__) . ' rev-parse --show-toplevel 2>/dev/null'));
    if ($top !== '') {
        $x = $base . '/archive';
        @mkdir($x, 0700, true);
        exec('git -C ' . escapeshellarg($top) . ' archive ' . QFU_REL_COMMIT . ' dishnet-hybrid-sudan | tar -x -C '
            . escapeshellarg($x) . ' 2>/dev/null');
        if (is_file($x . '/dishnet-hybrid-sudan/lib/QuotationService.php')) {
            $old = $base . '/old/dishnet-hybrid-sudan';
            qcj_copy_tree($x . '/dishnet-hybrid-sudan', $old);
        }
        exec('rm -rf ' . escapeshellarg($x));
    }
    return $memo[$c->root] = ['old' => $old, 'new' => $new];
}

/**
 * A value made comparable across two runs: its JSON with the run's own paths named, time stamps, ports and the
 * fake servers' tokens replaced. The same replacements on both sides, so a real difference survives.
 */
function qfu_norm($v, array $paths)
{
    $j = json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    uksort($paths, function ($a, $b) { return strlen($b) - strlen($a); });
    foreach ($paths as $p => $name) if ($p !== '') $j = str_replace([$p, str_replace('/', '\/', $p)], $name, $j);
    $j = preg_replace('/\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}(\.\d+)?([+-]\d{2}:?\d{2}|Z)?/', 'TS', $j);
    $j = preg_replace('/\[\d{2}-[A-Z][a-z]{2}-\d{4} \d{2}:\d{2}:\d{2}( [A-Za-z0-9\/_+-]+)?\]/', '[TS]', $j);   // PHP's own log stamp
    $j = preg_replace('/127\.0\.0\.1:\d+/', 'HOST', $j);
    return json_decode((string)$j, true);
}

/** The first difference between two normalised values, as a path — '' when they are equal. */
function qfu_first_diff($a, $b, string $at = ''): string
{
    if (is_array($a) && is_array($b)) {
        foreach (array_unique(array_merge(array_keys($a), array_keys($b))) as $k) {
            if (!array_key_exists($k, $a) || !array_key_exists($k, $b)) return $at . '/' . $k . ' (only on one side)';
            $d = qfu_first_diff($a[$k], $b[$k], $at . '/' . $k);
            if ($d !== '') return $d;
        }
        return '';
    }
    if ($a === $b) return '';
    $s = function ($x) { return substr(is_string($x) ? $x : json_encode($x, JSON_UNESCAPED_UNICODE), 0, 160); };
    if (is_string($a) && is_string($b)) {
        $n = 0; $m = min(strlen($a), strlen($b));
        while ($n < $m && $a[$n] === $b[$n]) $n++;
        return $at . " at byte {$n}: " . json_encode(substr($a, max(0, $n - 40), 120)) . ' vs ' . json_encode(substr($b, max(0, $n - 40), 120));
    }
    return $at . ': ' . $s($a) . ' vs ' . $s($b);
}

/** The fake uCRM (quote organization) and the fake Evolution, started once per run from the judged tree's fixtures. */
function qfu_servers(QfuCtx $c): array
{
    static $memo = [];
    if (isset($memo[$c->root])) return $memo[$c->root];
    [$fp, $fake] = qcj_boot_fake($c->root . '/tests/fixtures');
    [$ep, $evo]  = qcj_boot_evo($c->root . '/tests/fixtures');
    register_shutdown_function(function () use ($fp, $ep) { qcj_stop($fp); qcj_stop($ep); });
    return $memo[$c->root] = ['fake' => $fake, 'evo' => $evo, 'procs' => [$fp, $ep]];
}

/** Every request in the fake uCRM's log from $from on, as "METHOD /path[?query]". */
function qfu_reqs_since(string $fake, int $from): array
{
    return qcj_reqs(qcj_fake_log($fake), $from);
}
