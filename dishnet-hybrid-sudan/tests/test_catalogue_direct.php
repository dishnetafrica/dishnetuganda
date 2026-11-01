<?php
declare(strict_types=1);
/**
 * test_catalogue_direct.php — 5.18.103 (Uganda, root docs/72 §G): the catalogue straight from uCRM ("direct mode"). The
 * uCRM catalogue's switch and settings (lib/UcrmCatalogue.php), what the forms offer (UcrmQuoteLines::offered()), a direct
 * sale priced, stored, retried and quoted, Send Quotation's picked lines, the standard quotations, the screen and its
 * guards, South Sudan and the switch off as before, the rollback reading, and the release's boundary from Git.
 *
 * The sections are the files tests/catalogue_direct/<Letter>_*.php, each registering one section in $CD_SECTIONS. They
 * run on tests/quote_from_ucrm/_lib.php's QfuCtx (the child processes, the fake uCRM). Every section starts with its
 * positive control. Then the weakened copies (tests/catalogue_direct/mutants*.php): each a copy of the plugin with one
 * edit, judged by THIS file run with --root=<the copy> --sections=<the letters it names>; a copy is caught only when every
 * section it names fails (and, where a pattern is given, a FAIL line matches it). A copy whose anchor is not found exactly
 * once is a failure of this test, never a skip.
 *
 *   cd tests && DN_VAULT_FILE=$(mktemp -d)/v.json php test_catalogue_direct.php [--root=DIR] [--sections=REGEX]
 *        [--no-mutants] [--mutants=REGEX]
 *
 * Development and release suites only. Every person, number, address and document is fictitious; nothing leaves the
 * machine.
 */
$opt  = getopt('', ['root:', 'sections:', 'no-mutants', 'mutants:']);
$ROOT = isset($opt['root']) ? rtrim((string)$opt['root'], '/') : dirname(__DIR__);
$SECT = isset($opt['sections']) ? (string)$opt['sections'] : '.';
$withMutants = !isset($opt['no-mutants']);
// The zone section F's book runs under (qcj_book() takes it from its caller). A test file may pin a zone; its section files
// may not: tests/test_timezone.php exempts the files named test_*, so the zone is named here and nowhere under quote_from_ucrm/.
if (!defined('QFU_TZ')) define('QFU_TZ', 'Africa/Kampala');

$own = rtrim((string)(getenv('TMPDIR') ?: sys_get_temp_dir()), '/') . '/cd-' . getmypid() . '-' . bin2hex(random_bytes(3));
mkdir($own, 0700, true);
putenv('TMPDIR=' . $own);
putenv('DN_VAULT_FILE=' . $own . '/vault.json');
register_shutdown_function(function () use ($own) { exec('rm -rf ' . escapeshellarg($own)); });

require_once __DIR__ . '/quote_from_ucrm/_lib.php';
require_once __DIR__ . '/catalogue_direct/_lib.php';
$CD_SECTIONS = [];
foreach (glob(__DIR__ . '/catalogue_direct/[A-Z]_*.php') ?: [] as $f) require $f;
ksort($CD_SECTIONS);

$ctx = new QfuCtx($ROOT, $own);
foreach ($CD_SECTIONS as $letter => $sec) {
    if (!preg_match('/' . $SECT . '/', $letter)) continue;
    echo "\n{$letter}. {$sec['title']}\n";
    try {
        ($sec['run'])($ctx);
    } catch (\Throwable $e) {
        $ctx->t(false, "{$letter} ran to its end", get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
    }
}
$ctx->stop();

// ── the weakened copies ──────────────────────────────────────────────────────────────────────────────────────────────
if ($withMutants && !isset($opt['root'])) {
    require_once __DIR__ . '/fixtures/staff_jobs_sandbox.php';   // sj_weakened_copy()
    $QFU_MUTANTS = [];
    // Each section's copies: tests/catalogue_direct/mutants_<Letter>.php appends to $QFU_MUTANTS.
    foreach (glob(__DIR__ . '/catalogue_direct/mutants_[A-Z].php') ?: [] as $f) require $f;
    $only = isset($opt['mutants']) ? (string)$opt['mutants'] : null;
    echo "\nW. Weakened copies (" . count($QFU_MUTANTS) . ")\n";
    foreach ($QFU_MUTANTS as $m) {
        if ($only !== null && !preg_match('/' . $only . '/', $m['label'])) continue;
        $edits = $m['edits'] ?? [[$m['file'], $m['old'], $m['new']]];
        [$copy, $n] = sj_weakened_copy($ROOT, $edits[0][0], $edits[0][1], $edits[0][2]);
        $bad = $n === 1 ? '' : "anchor 1 found {$n} times";
        foreach (array_slice($edits, 1) as $i => [$rel, $old, $new]) {
            if ($bad !== '') break;
            $src = (string)@file_get_contents($copy . '/' . $rel);
            $k = substr_count($src, $old);
            if ($k !== 1) { $bad = 'anchor ' . ($i + 2) . " found {$k} times"; break; }
            file_put_contents($copy . '/' . $rel, str_replace($old, $new, $src));
        }
        foreach ($edits as [$rel]) {
            if ($bad === '' && substr($rel, -4) === '.php') {
                exec('php -l ' . escapeshellarg($copy . '/' . $rel) . ' 2>&1', $lo, $lrc);
                if ($lrc !== 0) $bad = "the copy's {$rel} does not lint";
            }
        }
        if ($bad !== '') { $ctx->t(false, "{$m['label']}: the copy was made", $bad); exec('rm -rf ' . escapeshellarg($copy)); continue; }
        $out = [];
        exec('php ' . escapeshellarg(__FILE__) . ' --no-mutants --root=' . escapeshellarg($copy)
            . ' --sections=' . escapeshellarg('[' . $m['sections'] . ']') . ' 2>&1', $out);
        $failedIn = []; $cur = '';
        foreach ($out as $l) {
            if (preg_match('/^([A-Z])\. /', $l, $h)) { $cur = $h[1]; continue; }
            if (strpos($l, '  FAIL ') === 0 && (!isset($m['pattern']) || preg_match($m['pattern'], $l))) $failedIn[$cur] = true;
        }
        $want = str_split($m['sections']);
        $missed = array_values(array_filter($want, fn($s) => empty($failedIn[$s])));
        $ctx->t($missed === [], "{$m['label']} is caught by " . implode(', ', $want),
            'not caught in: ' . implode(', ', $missed) . "\n" . implode("\n", array_slice($out, -25)));
        exec('rm -rf ' . escapeshellarg($copy));
    }
}

printf("\n%d passed, %d failed\n", $ctx->pass, $ctx->fail);
exit($ctx->fail === 0 ? 0 : 1);
