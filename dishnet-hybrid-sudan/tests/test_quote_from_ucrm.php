<?php
declare(strict_types=1);
/**
 * test_quote_from_ucrm.php — 5.18.102 (Uganda, docs/71 §L.1): the plugin's quotations take their lines and prices from
 * uCRM's Products, and a Cash sale collects exactly the quotation's total.
 *
 * The sections are the files tests/quote_from_ucrm/<Letter>_*.php, each registering one section in $QFU_SECTIONS:
 *   A  the resolver (UcrmQuoteLines): the read, the rows that count, keys, plan / kit / installation lines, every line,
 *      Fiber, reasons, the total and the posted total
 *   B  the KYC submission (KycService::process): the cash table of §H on both handlers, the order of §F.2, the stored
 *      lines of §F.3, the posted total of §G, nothing written on a refusal
 *   C  the retries (KycCrmSync, retry_quote): §F.4 — never re-priced for cash already collected
 *   D  "Prepared by" (§I): the salesPerson PATCH
 *   E  the read-back of the created quote (§F.6)
 *   F… the sections the other parts of the release add (Send Quotation, the PDFs, the screens, the form, the boundary)
 *
 * Every section starts with its positive control. Then the weakened copies (tests/quote_from_ucrm/mutants.php, and a later
 * section's own mutants_<Letter>.php): each is a
 * copy of the plugin with one edit, judged by THIS file run with --root=<the copy> --sections=<the letters it names>; a
 * copy is caught only when every section it names fails (and, where a pattern is given, a FAIL line of that section
 * matches it). A copy whose anchor is not found exactly once is a failure of this test, never a skip.
 *
 *   cd tests && DN_VAULT_FILE=$(mktemp -d)/v.json php test_quote_from_ucrm.php [--root=DIR] [--sections=REGEX]
 *        [--no-mutants] [--mutants=REGEX]
 *
 * Development and release suites only: it copies the plugin and runs child processes and fake uCRMs on 127.0.0.1.
 * Every person, number, address and document is fictitious; nothing leaves the machine.
 */
$opt  = getopt('', ['root:', 'sections:', 'no-mutants', 'mutants:']);
$ROOT = isset($opt['root']) ? rtrim((string)$opt['root'], '/') : dirname(__DIR__);
$SECT = isset($opt['sections']) ? (string)$opt['sections'] : '.';
$withMutants = !isset($opt['no-mutants']);
// The zone section F's book runs under (qcj_book() takes it from its caller). A test file may pin a zone; its section files
// may not: tests/test_timezone.php exempts the files named test_*, so the zone is named here and nowhere under quote_from_ucrm/.
const QFU_TZ = 'Africa/Kampala';

$own = rtrim((string)(getenv('TMPDIR') ?: sys_get_temp_dir()), '/') . '/qfu-' . getmypid() . '-' . bin2hex(random_bytes(3));
mkdir($own, 0700, true);
putenv('TMPDIR=' . $own);
putenv('DN_VAULT_FILE=' . $own . '/vault.json');
register_shutdown_function(function () use ($own) { exec('rm -rf ' . escapeshellarg($own)); });

require_once __DIR__ . '/quote_from_ucrm/_lib.php';
$QFU_SECTIONS = [];
foreach (glob(__DIR__ . '/quote_from_ucrm/[A-Z]_*.php') ?: [] as $f) require $f;
ksort($QFU_SECTIONS);

$ctx = new QfuCtx($ROOT, $own);
foreach ($QFU_SECTIONS as $letter => $sec) {
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
    if (is_file(__DIR__ . '/quote_from_ucrm/mutants.php')) require __DIR__ . '/quote_from_ucrm/mutants.php';
    // A section added later brings its own copies: mutants_<Letter>.php appends to $QFU_MUTANTS.
    foreach (glob(__DIR__ . '/quote_from_ucrm/mutants_[A-Z].php') ?: [] as $f) require $f;
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
