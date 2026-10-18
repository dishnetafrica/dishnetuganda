<?php
declare(strict_types=1);
/**
 * test_bank_reader.php — T-B1 of the Bank screen v1 (5.18.97; SPEC-bank §f.2): lib/EcobankDetailsCsv.php, the strict
 * reader of Ecobank's "Account Details" CSV, and fit(), its overlap and continuity rule.
 *
 * Every statement here is GENERATED (tests/fixtures/bank_statement_gen.php) and invented: account 0000000001, holder
 * "RH-Account Holder One", counterparties "RH-CLIENT n", bank line numbers 0000000101…. A real statement is never a
 * fixture. The cases (A–D) are pure: no database, no file, no stream but the reader's own php://temp.
 *
 * In order:
 *   A  the prototype's 74 cases (scratch proto2/run2.php), each under its own name, against this tree's reader
 *   B  what §f.2 adds: each cap one byte or one line over (and the effective byte cap from PHP's limits); every column
 *      rule of §a.4; each warning, and all of them at once (warnings never refuse); the trimmed amount text; numbers
 *      by value; the facts; the line shape; fit()'s other branches; the fixed sentences of §a.9; B8 a cell that ends in a
 *      line feed, each refused by its own rule (BK97-R2); B9 the scan that bounds a record before fgetcsv() builds it,
 *      held equal to fgetcsv() over chosen shapes and a generated corpus, and its refusals in SPEC-bank §a.2's order
 *      (BK-SEC-3)
 *   C  the marker test: files whose every text cell carries RHMARKER, account 0000000001: each refusal the reader and
 *      fit() own, in turn — neither the marker, the account, the holder nor a counterparty may appear in the code,
 *      the parameter or the rendered sentence
 *   D  every parameter any case produced fits migration 088's CHECK; every code the reader owns was produced; facts
 *      and lines never carry the full account or the holder's name
 *   E  memory: the whole keep path (the file's bytes, read(), the store's two-column view of the kept lines, fit()) at
 *      the caps — 10,000 lines, and 10,000 lines near BYTES_MAX — in a child process under memory_limit=64M; and six
 *      files that are not statements, each at the byte cap (a row of commas, rows of 4,000 commas, rows of SCAN_FIELDS
 *      fields, blank lines, a quoted line end then commas, a first row of commas), each refused with its own code under
 *      the same budget (BK-SEC-3: the byte cap bounds the file, not the work of one record)
 *   F  weakened copies: the prototype's 20 (proto2/mutants2.php) and six more, each made by copying the reader, its
 *      generator and this test, with every anchor asserted to occur exactly once and every change asserted present;
 *      the copy's own cases (A–D) must then FAIL at the case named for it. An unchanged copy passes with the same tally.
 *      F2: three weakened copies of the bounded reading, each made the same way, must each break section E's budget
 *      at the files named for it; an unchanged copy reads every one of them within it
 *   G  the end check: nothing is left behind
 *
 * Arguments: --root=<tree> judges that tree's reader and generator (a weakened copy); --cases-only runs A–D only
 * (what a copy's child runs); --memory-child is section E's child, which reads its input on STDIN.
 *
 *   php test_bank_reader.php
 */

$root = dirname(__DIR__); $mode = '';
foreach (array_slice($argv, 1) as $a) {
    if (strncmp($a, '--root=', 7) === 0) $root = rtrim(substr($a, 7), '/');
    elseif ($a === '--cases-only' || $a === '--memory-child') $mode = $a;
    else { fwrite(STDERR, "test_bank_reader.php: unknown argument\n"); exit(2); }
}
$READER = $root . '/lib/EcobankDetailsCsv.php';
require_once $READER;

// ── Section E's child: the keep path on one file, nothing else loaded; its peak is the measurement ─────────────────
if ($mode === '--memory-child') {
    $in = (string)stream_get_contents(STDIN);
    $p = unserialize($in, ['allowed_classes' => false]);
    unset($in);
    if (!is_array($p)) { echo json_encode(['error' => 'no payload']), "\n"; exit(2); }
    $csv = (string)$p['csv']; $known = (array)$p['known']; $first = $p['first']; $last = $p['last'];
    unset($p);
    $r = EcobankDetailsCsv::read($csv, EcobankDetailsCsv::BYTES_MAX);
    $f = $r['ok'] ? EcobankDetailsCsv::fit($r['lines'], $known, $first, $last) : $r;
    echo json_encode(['code' => $f['ok'] ? 'ok' : $f['code'], 'param' => $f['ok'] ? '' : (string)($f['param'] ?? ''), 'new' => count($f['new'] ?? []), 'known' => $f['known'] ?? 0,
        'bytes' => strlen($csv), 'peak' => memory_get_peak_usage(), 'limit' => (string)ini_get('memory_limit')]), "\n";
    exit(0);
}

require_once $root . '/tests/fixtures/bank_statement_gen.php';

$pass = 0; $fail = 0; $PARAMS = [];
function t(string $name, $got, $want): void {
    global $pass, $fail;
    if ($got === $want) { $pass++; echo "  ok   {$name}\n"; }
    else { $fail++; echo "  FAIL {$name}\n       got  " . substr((string)json_encode($got), 0, 300) . "\n       want " . substr((string)json_encode($want), 0, 300) . "\n"; }
}
function is_(bool $c, string $name, string $d = ''): void {
    global $pass, $fail;
    if ($c) { $pass++; echo "  ok   {$name}\n"; } else { $fail++; echo "  FAIL {$name}" . ($d !== '' ? "\n       " . substr($d, 0, 600) : '') . "\n"; }
}
/** read(), never letting a throwable end the run: a weakened copy must fail a NAMED case, not crash the file. */
function R(string $csv, ?int $cap = null): array {
    global $PARAMS;
    try { $r = EcobankDetailsCsv::read($csv, $cap); }
    catch (\Throwable $e) { return ['ok' => false, 'code' => 'THROWS ' . get_class($e), 'param' => '']; }
    if (!$r['ok']) $PARAMS[] = [$r['code'], $r['param']];
    return $r;
}
function F(array $file, array $known, ?array $first, ?array $last): array {
    global $PARAMS;
    try { $r = EcobankDetailsCsv::fit($file, $known, $first, $last); }
    catch (\Throwable $e) { return ['ok' => false, 'code' => 'THROWS ' . get_class($e), 'param' => '']; }
    if (!$r['ok']) $PARAMS[] = [$r['code'], $r['param']];
    return $r;
}
function code(array $r): string { return $r['ok'] ? 'ok' : (string)$r['code']; }
/** What the store reads for fit(): cheque => digest of the kept lines inside the file's range, and the two ends. */
function bk_view(array $kept, array $fileLines): array {
    if (!$kept || !$fileLines) return [[], null, null];
    $fMin = $fileLines[0]['cheque_number']; $fMax = $fileLines[count($fileLines) - 1]['cheque_number'];
    $known = [];
    foreach ($kept as $k) {
        if (strcmp($k['cheque_number'], $fMin) >= 0 && strcmp($k['cheque_number'], $fMax) <= 0) $known[$k['cheque_number']] = $k['compare_digest'];
    }
    return [$known, bk_end($kept[0]), bk_end($kept[count($kept) - 1])];
}
function bk_end(array $k): array {
    return ['cheque_number' => $k['cheque_number'], 'posting_date' => $k['posting_date'], 'running_units' => $k['running_units'],
        'credit_units' => $k['credit_units'], 'debit_units' => $k['debit_units']];
}
function fitK(array $file, array $kept): array { [$k, $a, $b] = bk_view($kept, $file); return F($file, $k, $a, $b); }
/** A read that a case needs as its setting: a refusal here is a failure of its own, never an exit. */
function rd(string $csv, string $label, ?int $cap = null): array {
    $r = R($csv, $cap);
    if (!$r['ok']) { is_(false, "setup: {$label} reads", 'refused: ' . $r['code']); return ['ok' => false, 'lines' => [], 'facts' => [], 'warnings' => []]; }
    return $r;
}

echo "reader: " . (string)realpath($READER) . "\n";

// ═══════════════════════════════════════════════════════════════════════════
echo "\nA. The prototype's 74 cases\n";
$H = g_history(40, 3);
$clean = R(g_file($H));
t('clean file passes', code($clean), 'ok');
t('clean: 40 lines', $clean['facts']['line_count'] ?? null, 40);
t('clean: last four only', $clean['facts']['last4'] ?? null, '0001');
t('clean: no warnings', $clean['warnings'] ?? null, []);
$sh = R(g_file($H, 'UGX', ['shuffle' => true]));
t('file order differs: accepted, flagged, same lines', [code($sh), $sh['facts']['file_order_differs'] ?? null, array_column($sh['lines'] ?? [], 'cheque_number')],
    ['ok', true, array_column($clean['lines'] ?? [], 'cheque_number')]);
$r = R(g_summary(true));
t('account summary with header refused, no param', [code($r), $r['param'] ?? null], ['summary_export', '']);
t('account summary without header refused', code(R(g_summary(false))), 'summary_export');
t('missing column refused', code(R(g_file($H, 'UGX', ['header' => array_values(array_diff(EcobankDetailsCsv::HEADER, ['Cheque Number']))]))), 'header');
t('renamed column refused', code(R(str_replace('"Credit"', '"Credit Amount"', g_file($H)))), 'header');
t('empty file refused', code(R('')), 'empty');
$hdrOnly = implode(',', array_map(function ($v) { return '"' . $v . '"'; }, EcobankDetailsCsv::HEADER)) . "\n";
t('header only refused', code(R($hdrOnly)), 'empty');
t('one byte over the cap refused', code(R(str_repeat('a', EcobankDetailsCsv::BYTES_MAX + 1))), 'too_large');
$drop = function (int $at, bool $renumber) {
    return function ($L) use ($at, $renumber) {
        array_splice($L, $at, 1);
        if ($renumber) { foreach ($L as $i => &$l) $l['SEQUENCE_NMBR'] = (string)($i + 1); unset($l); }
        return $L;
    };
};
t('gap in SEQUENCE_NMBR refused', code(R(g_file($H, 'UGX', ['mutate' => $drop(10, false)]))), 'sequence');
t('interior line dropped, renumbered: chain', code(R(g_file($H, 'UGX', ['mutate' => $drop(10, true)]))), 'chain');
t('last line dropped: closing', code(R(g_file($H, 'UGX', ['mutate' => $drop(39, false)]))), 'closing');
$fd = R(g_file($H, 'UGX', ['mutate' => $drop(0, true)]));
t('first line dropped, renumbered: passes, opening_differs warned (KNOWN LIMIT)', [code($fd), in_array('opening_differs', $fd['warnings'] ?? [], true)], ['ok', true]);
$set = function (int $at, string $col, string $v) { return function ($L) use ($at, $col, $v) { $L[$at][$col] = $v; return $L; }; };
t('running-balance break refused', code(R(g_file($H, 'UGX', ['mutate' => $set(20, 'Running Balance', '        1.00')]))), 'chain');
t('both credit and debit refused', code(R(g_file($H, 'UGX', ['mutate' => function ($L) { $L[5]['Credit'] = '1'; $L[5]['Debit'] = '1'; return $L; }]))), 'amount_both_neither');
t('neither refused', code(R(g_file($H, 'UGX', ['mutate' => function ($L) { $L[5]['Credit'] = ''; $L[5]['Debit'] = ''; return $L; }]))), 'amount_both_neither');
foreach (['32-AUG-26', '29-FEB-27', '07-Aug-26', '2026-08-07', '7-AUG-26', ''] as $bad) {
    t("non-date '{$bad}' refused", code(R(g_file($H, 'UGX', ['mutate' => $set(3, 'Value Date', $bad)]))), 'date');
}
t('29-FEB-28 is a date', EcobankDetailsCsv::date('29-FEB-28'), '2028-02-29');
t('amount forms', [EcobankDetailsCsv::amount('.9'), EcobankDetailsCsv::amount('12.500'), EcobankDetailsCsv::amount('0.1255'), EcobankDetailsCsv::amount('1,000'),
    EcobankDetailsCsv::amount('-5'), EcobankDetailsCsv::amount('12.')], [90, 1250, 'scale', null, null, null]);
$side = function ($L, int $at, string $v) { $c = $L[$at]['Credit'] !== '' ? 'Credit' : 'Debit'; $L[$at][$c] = $v; return $L; };
t('amount_scale refused in a file', code(R(g_file($H, 'UGX', ['mutate' => function ($L) use ($side) { return $side($L, 5, '1.125'); }]))), 'amount_scale');
t('amount_form refused in a file', code(R(g_file($H, 'UGX', ['mutate' => function ($L) use ($side) { return $side($L, 5, '1,000'); }]))), 'amount_form');
t('balance forms', [EcobankDetailsCsv::balance('        1250.00'), EcobankDetailsCsv::balance('   .50'), EcobankDetailsCsv::balance('-.50'), EcobankDetailsCsv::balance('1250.0'),
    EcobankDetailsCsv::balance('.5'), EcobankDetailsCsv::balance('.'), EcobankDetailsCsv::balance('12')], [125000, 50, -50, null, null, null, null]);
t('ccy forms', [EcobankDetailsCsv::ccyAmount('UGX .00'), EcobankDetailsCsv::ccyAmount('UGX 0'), EcobankDetailsCsv::ccyAmount('USD -.5'), EcobankDetailsCsv::ccyAmount('UGX .'),
    EcobankDetailsCsv::ccyAmount('UGX '), EcobankDetailsCsv::ccyAmount('UGX 1,000.00')], [['UGX', 0], ['UGX', 0], ['USD', -50], null, null, null]);
t('a file whose balances drop the leading zero (UGX .00) passes', code(R(g_file($H, 'UGX', ['nolead' => true]))), 'ok');
t('balance_form refused in a file', code(R(g_file($H, 'UGX', ['mutate' => $set(7, 'Running Balance', '  12.5')]))), 'balance_form');
t('currency_form: unreadable Opening Available', code(R(g_file($H, 'UGX', ['mutate' => function ($L) { foreach ($L as &$l) $l['Opening Available'] = 'UGX'; unset($l); return $L; }]))), 'currency_form');
t('duplicate Cheque Number refused', code(R(g_file($H, 'UGX', ['mutate' => function ($L) { $L[8]['Cheque Number'] = $L[7]['Cheque Number']; return $L; }]))), 'lineid_duplicate');
t('Cheque Number out of order refused', code(R(g_file($H, 'UGX', ['mutate' => function ($L) {
    [$L[8]['Cheque Number'], $L[7]['Cheque Number']] = [$L[7]['Cheque Number'], $L[8]['Cheque Number']]; return $L; }]))), 'lineid_order');
t('Cheque Number malformed refused', code(R(g_file($H, 'UGX', ['mutate' => $set(8, 'Cheque Number', '12345')]))), 'lineid_form');
t('BOM and CRLF accepted', code(R(g_file($H, 'UGX', ['bom' => true, 'crlf' => true]))), 'ok');
t('NUL refused (BINARY)', [code($r = R(g_file($H) . "\0")), $r['param'] ?? null], ['not_csv', 'BINARY']);
t('PDF refused (PDF)', [code($r = R('%PDF-1.4 rh')), $r['param'] ?? null], ['not_csv', 'PDF']);
t('XLSX refused (EXCEL)', [code($r = R("PK\x03\x04rh")), $r['param'] ?? null], ['not_csv', 'EXCEL']);
t('bad UTF-8 refused (ENCODING)', [code($r = R("\"a\xff\"\n")), $r['param'] ?? null], ['not_csv', 'ENCODING']);
t('bare CR refused (LINE-ENDS)', [code($r = R(str_replace("\n", "\r", g_file($H)))), $r['param'] ?? null], ['not_csv', 'LINE-ENDS']);
t('a row of 22 columns refused', code(R(g_file($H) . "\"a\",\"b\"\n")), 'columns');
t('a field over FIELD_MAX refused', code(R(g_file($H, 'UGX', ['mutate' => function ($L) {
    $L[3]['Remarks'] = str_repeat('R', 1001); $L[3]['Transaction Reference'] = $L[3]['Remarks']; return $L; }]))), 'field_too_long');
t('a field ending in a backslash keeps 23 columns', code(R(g_file($H, 'UGX', ['mutate' => function ($L) {
    $L[4]['Remarks'] = 'RH-CLIENT ONE \\'; $L[4]['Transaction Reference'] = $L[4]['Remarks']; return $L; }]))), 'ok');
$ml = R(g_file($H, 'UGX', ['mutate' => function ($L) { $L[4]['Remarks'] = "RH LINE ONE\nRH LINE TWO"; $L[4]['Transaction Reference'] = $L[4]['Remarks']; return $L; }]));
t('a quoted field across two lines, verbatim', [code($ml), $ml['lines'][4]['remarks'] ?? null], ['ok', "RH LINE ONE\nRH LINE TWO"]);
t('two accounts in one file refused', code(R(g_file($H, 'UGX', ['mutate' => $set(9, 'Account', '0000000002')]))), 'account_mixed');
t('Closing Available differs between lines refused', code(R(g_file($H, 'UGX', ['mutate' => $set(9, 'Closing Available', 'UGX 1.00')]))), 'currency_form');
t('USD file reads USD', R(g_file($H, 'USD'))['facts']['currency'] ?? null, 'USD');
$padEmpty = R(g_file($H, 'UGX', ['mutate' => function ($L) { foreach ($L as &$l) { if ($l['Credit'] === '') $l['Credit'] = ' '; else $l['Debit'] = ' '; } unset($l); return $L; }]));
t('a space in the empty side passes and is stored empty', [code($padEmpty), $padEmpty['lines'][0]['debit_text'] ?? null,
    isset($padEmpty['lines'][1]) && ($padEmpty['lines'][1]['credit_text'] === '' || $padEmpty['lines'][1]['debit_text'] === '')], ['ok', '', true]);
t('every stored line has exactly one non-empty amount text', count(array_filter($padEmpty['lines'] ?? [null], function ($l) {
    return !is_array($l) || (($l['credit_text'] !== '') + ($l['debit_text'] !== '')) !== 1; })), 0);
t('compare digest recomputes from the stored values', count(array_filter($clean['lines'] ?? [], function ($l) {
    return EcobankDetailsCsv::compareDigest($l) !== $l['compare_digest']; })), 0);

// fit: overlap and continuity (joins proven by a shared bank line)
$H2 = g_history(70, 3);
$A = rd(g_file(array_slice($H2, 0, 40)), 'kept 1-40');
$B = rd(g_file(array_slice($H2, 0, 70)), 'from the opening 1-70');
$C = rd(g_file(array_slice($H2, 30, 40)), 'overlapping 31-70');
$D = rd(g_file(array_slice($H2, 45, 25)), 'after a gap 46-70');
$E = rd(g_file(array_slice($H2, 40, 30)), 'right after the last kept line 41-70');
$E2 = rd(g_file(array_slice($H2, 39, 31)), 'at the last kept line 40-70');
$k = $A['lines'];
$f = fitK($B['lines'], $k); t('1-70 over 1-40 adds 30, knows 40', code($f) . '/' . count($f['new'] ?? []) . '/' . ($f['known'] ?? 0), 'ok/30/40');
$f = fitK($C['lines'], $k); t('31-70 over 1-40 adds 30', code($f) . '/' . count($f['new'] ?? []), 'ok/30');
$f = fitK($E['lines'], $k);
t('41-70 after 1-40: no shared line, refused (the cost of issue 4)', [code($f), $f['param'] ?? ''], ['gap_after', $k[39]['posting_date'] ?? 'setup']);
$f = fitK($E2['lines'], $k); t('40-70 after 1-40: shares line 40, adds 30', code($f) . '/' . count($f['new'] ?? []), 'ok/30');
t('46-70 after 1-40 is a gap', code(fitK($D['lines'], $k)), 'gap_after');
t('the same lines again: nothing new', code(fitK($A['lines'], $k)), 'nothing_new');
$k2 = rd(g_file(array_slice($H2, 40, 30)), 'kept 41-70')['lines'];
t('1-40 in front of 41-70: no shared line, refused', code(fitK($A['lines'], $k2)), 'gap_before');
$f = fitK(rd(g_file(array_slice($H2, 0, 41)), '1-41')['lines'], $k2);
t('1-41 in front of 41-70: shares line 41, adds 40', code($f) . '/' . count($f['new'] ?? []) . '/' . ($f['known'] ?? 0), 'ok/40/1');
t('1-30 in front of 41-70 refused', code(fitK(rd(g_file(array_slice($H2, 0, 30)), '1-30')['lines'], $k2)), 'gap_before');
$k3 = rd(g_file(array_slice($H2, 30, 30)), 'kept 31-60')['lines'];
$f = fitK($B['lines'], $k3); t('a from-opening export over a ranged first file adds both ends', code($f) . '/' . count($f['new'] ?? []) . '/' . ($f['known'] ?? 0), 'ok/40/30');
t('an overlapping line that differs (Remarks) is refused', code(fitK(rd(g_file(array_slice($H2, 30, 40), 'UGX', ['mutate' => $set(2, 'Remarks', 'RH RESTATED')]), '31-70 restated')['lines'], $k)), 'overlap_differs');
t('  any compared text field', code(fitK(rd(g_file(array_slice($H2, 30, 40), 'UGX', ['mutate' => $set(3, 'Transaction Reference', 'RH OTHER')]), '31-70 other ref')['lines'], $k)), 'overlap_differs');
$kHole = $k; array_splice($kHole, 20, 1);
t('a new line inside the kept range is refused', code(fitK($B['lines'], $kHole)), 'overlap_interleave');
t('a later export missing a kept line: its own chain breaks', code(R(g_file(array_merge(array_slice($H2, 0, 20), array_slice($H2, 21, 49))))), 'chain');
$f = fitK(rd(g_file(array_slice($H2, 0, 70), 'UGX', ['pad' => 20]), 'padded 20')['lines'], $k);
t('re-padded export (20 over 18) adds only its new lines', code($f) . '/' . count($f['new'] ?? []) . '/' . ($f['known'] ?? 0), 'ok/30/40');
$f = fitK(rd(g_file(array_slice($H2, 0, 70), 'UGX', ['pad' => 12]), 'padded 12')['lines'], $k);
t('re-padded export (12 over 18) adds only its new lines', code($f) . '/' . count($f['new'] ?? []), 'ok/30');
$re0 = function ($L) { foreach ($L as &$l) foreach (['Credit', 'Debit'] as $c) if ($l[$c] !== '' && strpos($l[$c], '.') !== false) $l[$c] .= '0'; unset($l); return $L; };
$f = fitK(rd(g_file(array_slice($H2, 0, 70), 'UGX', ['mutate' => $re0]), 'amounts re-spelt')['lines'], $k);
t('amounts spelt 12.50 where the kept file wrote 12.5: adds only new', code($f) . '/' . count($f['new'] ?? []), 'ok/30');
$f = fitK(rd(g_file(array_slice($H2, 0, 70), 'UGX', ['mutate' => $set(5, 'Running Balance', str_pad(trim($H2[5]['Running Balance']), 25, ' ', STR_PAD_LEFT))]), 'one line re-padded')['lines'], $k);
t('one line re-padded alone: still the same line', code($f), 'ok');
// a zero-net run missing at a file boundary
$Z = $H2; $x = 12345;
$Z[40]['_credit'] = false; $Z[40]['_cents'] = $x;
$Z[41]['_credit'] = true;  $Z[41]['_cents'] = $x;
$Z = g_rechain($Z, 40);
$ZK = rd(g_file(array_slice($Z, 0, 40)), 'zero-net kept 1-40')['lines'];
$ZR = rd(g_file(array_slice($Z, 42, 28)), 'zero-net ranged 43-70')['lines'];
t('a ranged file after a missing zero-net pair is refused (no hole is ever kept)', code(fitK($ZR, $ZK)), 'gap_after');
$f = fitK(rd(g_file(array_slice($Z, 0, 70)), 'zero-net from the opening')['lines'], $ZK);
t('  and the from-opening export over the same kept lines is accepted', code($f) . '/' . count($f['new'] ?? []), 'ok/30');
$badA = [];
foreach ($PARAMS as [$c, $p]) if (!preg_match('/^[0-9A-Z|-]{0,24}$/D', (string)$p)) $badA[] = "{$c}:{$p}";
t('every refusal param fits 088 CHECK [0-9A-Z|-]{0,24}', $badA, []);

// ═══════════════════════════════════════════════════════════════════════════
echo "\nB1. The caps, each one byte or one line over\n";
$tbIni = function (string $v): int {                                        // php.ini quantities, read independently
    if (!preg_match('/^\s*(\d+)\s*([KMGkmg]?)\s*$/', $v, $m)) return 0;
    return (int)$m[1] * (['' => 1, 'K' => 1024, 'M' => 1048576, 'G' => 1073741824][strtoupper($m[2])]);
};
$capWant = EcobankDetailsCsv::BYTES_MAX;
foreach (['upload_max_filesize', 'post_max_size'] as $iniKey) { $b = $tbIni((string)ini_get($iniKey)); if ($b > 0 && $b < $capWant) $capWant = $b; }
$cap = EcobankDetailsCsv::effectiveCap();
t('the effective cap is the smallest of BYTES_MAX, upload_max_filesize and post_max_size', $cap, $capWant);
$r = R(str_repeat('a', $cap + 1));
t('with no cap given, one byte over the effective cap is refused, with the cap in MB', [code($r), $r['param'] ?? null], ['too_large', (string)intdiv($capWant, 1048576)]);
t('  and a file of exactly the effective cap is not too large (it fails later, at the header)', code(R(str_repeat('a', $cap))), 'header');
$r = R(str_repeat('a', EcobankDetailsCsv::BYTES_MAX + 1), EcobankDetailsCsv::BYTES_MAX * 2);
t('a cap given above BYTES_MAX cannot widen it', [code($r), $r['param'] ?? null], ['too_large', '4']);
$cleanCsv = g_file($H);
t('a cap given below the file narrows it (refused, rounded-down MB)', [code($r = R($cleanCsv, strlen($cleanCsv) - 1)), $r['param'] ?? null], ['too_large', '0']);
t('  and a cap of exactly the file passes', code(R($cleanCsv, strlen($cleanCsv))), 'ok');
t('capMb rounds down', [EcobankDetailsCsv::capMb(4194304), EcobankDetailsCsv::capMb(2097152), EcobankDetailsCsv::capMb(3670016), EcobankDetailsCsv::capMb(524288)], ['4', '2', '3', '0']);
t('iniBytes reads php.ini quantities', [EcobankDetailsCsv::iniBytes('2M'), EcobankDetailsCsv::iniBytes('512K'), EcobankDetailsCsv::iniBytes('1G'), EcobankDetailsCsv::iniBytes('8388608'),
    EcobankDetailsCsv::iniBytes(' 8m '), EcobankDetailsCsv::iniBytes('0'), EcobankDetailsCsv::iniBytes(''), EcobankDetailsCsv::iniBytes('lots')],
    [2097152, 524288, 1073741824, 8388608, 8388608, 0, 0, 0]);
$HL = g_history(EcobankDetailsCsv::LINES_MAX + 1, 5);
$r = R(g_file(array_slice($HL, 0, EcobankDetailsCsv::LINES_MAX)), EcobankDetailsCsv::BYTES_MAX);
t('exactly LINES_MAX (10,000) data rows pass', code($r) . '/' . ($r['facts']['line_count'] ?? 0), 'ok/10000');
unset($r);
$r = R(g_file($HL), EcobankDetailsCsv::BYTES_MAX);
t('one line over LINES_MAX is refused, with the cap', [code($r), $r['param'] ?? null], ['too_many_lines', '10000']);
unset($r);
$fieldAt = function (string $v) { return function ($L) use ($v) { $L[3]['Remarks'] = $v; $L[3]['Transaction Reference'] = $v; return $L; }; };
t('a field of exactly FIELD_MAX (1,000) characters passes', code(R(g_file($H, 'UGX', ['mutate' => $fieldAt(str_repeat('R', 1000))]))), 'ok');
t('a field of 1,001 characters is refused at its file row', [code($r = R(g_file($H, 'UGX', ['mutate' => $fieldAt(str_repeat('R', 1001))]))), $r['param'] ?? null], ['field_too_long', '5']);
t('a field of 1,000 two-byte characters is accepted', code(R(g_file($H, 'UGX', ['mutate' => $fieldAt(str_repeat("\u{00E9}", 1000))]))), 'ok');
t('a field of 1,001 two-byte characters is refused', code(R(g_file($H, 'UGX', ['mutate' => $fieldAt(str_repeat("\u{00E9}", 1001))]))), 'field_too_long');

echo "\nB2. Blank lines\n";
t('a blank line before the header is refused as header', code(R("\n" . $cleanCsv)), 'header');
t('blank lines at the end of the file are dropped', code(R($cleanCsv . "\n\n")), 'ok');
$mid = explode("\n", $cleanCsv); array_splice($mid, 6, 0, ['']);
t('a blank line between rows is refused, at its row', [code($r = R(implode("\n", $mid))), $r['param'] ?? null], ['columns', '7']);
t('only blank lines is empty', code(R("\n\n\n")), 'empty');

echo "\nB3. Column by column (SPEC-bank §a.4)\n";
$all = function (string $col, string $v) { return function ($L) use ($col, $v) { foreach ($L as &$l) $l[$col] = $v; unset($l); return $L; }; };
t('an account number of nine digits on every line is refused', code(R(g_file($H, 'UGX', ['mutate' => $all('Account', '000000001')]))), 'account_mixed');
t('an empty Account Name on every line is refused', code(R(g_file($H, 'UGX', ['mutate' => $all('Account Name', ' ')]))), 'account_mixed');
t('an Account Name that differs on one line is refused', code(R(g_file($H, 'UGX', ['mutate' => $set(12, 'Account Name', 'RH-Account Holder Two')]))), 'account_mixed');
t('an Opening Available that differs on one line is refused', code(R(g_file($H, 'UGX', ['mutate' => $set(12, 'Opening Available', 'UGX 1.00')]))), 'currency_form');
$closeUsd = R(g_file($H, 'UGX', ['mutate' => function ($L) { foreach ($L as &$l) $l['Closing Available'] = 'USD' . substr($l['Closing Available'], 3); unset($l); return $L; }]));
t('a Closing Available in another currency than the Opening is refused', code($closeUsd), 'currency_form');
t('a stated balance with a thousands separator is refused', code(R(g_file($H, 'UGX', ['mutate' => $all('Opening Available', 'UGX 1,000.00')]))), 'currency_form');
$spaced = R(g_file($H, 'UGX', ['mutate' => function ($L) { foreach ($L as &$l) { $l['Opening Available'] = ' ' . $l['Opening Available'] . ' '; } unset($l); return $L; }]));
t('a stated balance with spaces around it is read', [code($spaced), $spaced['facts']['opening_stated'] ?? null], ['ok', $clean['facts']['opening_stated'] ?? 'setup']);
t('SEQUENCE_NMBR 0 is refused', code(R(g_file($H, 'UGX', ['mutate' => $set(0, 'SEQUENCE_NMBR', '0')]))), 'sequence');
t('SEQUENCE_NMBR with a leading zero is refused', code(R(g_file($H, 'UGX', ['mutate' => $set(4, 'SEQUENCE_NMBR', '05')]))), 'sequence');
t('SEQUENCE_NMBR repeated is refused', code(R(g_file($H, 'UGX', ['mutate' => $set(5, 'SEQUENCE_NMBR', '5')]))), 'sequence');
$srno = R(g_file($H, 'UGX', ['mutate' => $set(6, 'Sr. No.', 'RH-ANY')]));
t('Sr. No. is not interpreted: kept as text', [code($srno), $srno['lines'][6]['sr_no'] ?? null], ['ok', 'RH-ANY']);
$tc = R(g_file($H, 'UGX', ['mutate' => function ($L) { $L[6]['Type Code'] = 'ZZ9'; $L[6]['Type Description'] = 'RH ANYTHING'; $L[6]['Transaction Code'] = 'Q-1'; return $L; }]));
t('type and transaction codes are not interpreted: kept as text', [code($tc), $tc['lines'][6]['type_code'] ?? null, $tc['lines'][6]['type_desc'] ?? null, $tc['lines'][6]['txn_code'] ?? null],
    ['ok', 'ZZ9', 'RH ANYTHING', 'Q-1']);
$vdiff = array_values(array_filter($clean['lines'] ?? [], function ($l) { return $l['value_date'] !== $l['posting_date']; }));
is_($vdiff !== [] && $vdiff[0]['value_raw'] !== $vdiff[0]['posting_raw'], 'a value date may differ from its posting date, and both are kept', (string)count($vdiff));
t('a Cheque Number of eleven digits is refused', code(R(g_file($H, 'UGX', ['mutate' => $set(8, 'Cheque Number', '00000001010')]))), 'lineid_form');
t('an empty Cheque Number is refused', code(R(g_file($H, 'UGX', ['mutate' => $set(8, 'Cheque Number', '')]))), 'lineid_form');
$cl = $clean['lines'][4] ?? ['credit_text' => '', 'debit_text' => ''];
$sideCol = $cl['credit_text'] !== '' ? 'Credit' : 'Debit';
$trimmed = R(g_file($H, 'UGX', ['mutate' => $set(4, $sideCol, '  ' . ($cl['credit_text'] !== '' ? $cl['credit_text'] : $cl['debit_text']) . ' ')]));
t('an amount with spaces around it is stored trimmed, same units', [code($trimmed), $trimmed['lines'][4]['credit_text'] ?? null, $trimmed['lines'][4]['debit_text'] ?? null,
    $trimmed['lines'][4]['credit_units'] ?? null, $trimmed['lines'][4]['debit_units'] ?? null],
    ['ok', $cl['credit_text'] ?? null, $cl['debit_text'] ?? null, $cl['credit_units'] ?? null, $cl['debit_units'] ?? null]);
foreach (['12.', '-5', '1e3', '+5', '1 000'] as $bad) {
    t("an amount '{$bad}' is refused", code(R(g_file($H, 'UGX', ['mutate' => function ($L) use ($side, $bad) { return $side($L, 5, $bad); }]))), 'amount_form');
}
$H3 = $H; $H3[5]['_credit'] = true; $H3[5]['_cents'] = 1250; $H3 = g_rechain($H3, 5);
$scale0 = R(g_file($H3, 'UGX', ['mutate' => $set(5, 'Credit', '12.500')]));
t('an amount 12.500 is 12.50 exactly (zeros beyond two decimals)', [code($scale0), $scale0['lines'][5]['credit_units'] ?? null, $scale0['lines'][5]['credit_text'] ?? null], ['ok', 1250, '12.500']);
t('a running balance with one decimal is refused', code(R(g_file($H, 'UGX', ['mutate' => $set(9, 'Running Balance', '  1250.0')]))), 'balance_form');
t('an empty running balance is refused', code(R(g_file($H, 'UGX', ['mutate' => $set(9, 'Running Balance', '')]))), 'balance_form');
$HO = g_history(30, 9, -100000000);
$od = R(g_file($HO));
t('an overdrawn account (negative balances) reads, its opening negative', [code($od), $od['facts']['opening_stated'] ?? null, $od['facts']['opening_derived'] ?? null], ['ok', -100000000, -100000000]);
t('  and the running balances are negative where the bank says so', ($od['lines'][0]['running_units'] ?? 0) < 0, true);

echo "\nB4. Warnings: each one, and all at once (they never refuse)\n";
$warnOf = function (callable $m) use ($H) { $r = R(g_file($H, 'UGX', ['mutate' => $m])); return [code($r), $r['warnings'] ?? null]; };
t('a Cust Ref that is not empty warns refs_not_empty', $warnOf($set(3, 'Cust Ref', 'RH1')), ['ok', ['refs_not_empty']]);
t('a Bank Ref that is not empty warns refs_not_empty', $warnOf($set(3, 'Bank Ref', 'RH1')), ['ok', ['refs_not_empty']]);
t('a Loan Outstanding Amount that is not 0 warns loan_total_not_zero', $warnOf($set(3, 'Loan Outstanding Amount', '5')), ['ok', ['loan_total_not_zero']]);
t('a Total Amount that is not 0 warns loan_total_not_zero', $warnOf($set(3, 'Total Amount', '5')), ['ok', ['loan_total_not_zero']]);
t('a Customer Reference unlike the Reference warns reference_differs', $warnOf($set(3, 'Customer Reference', 'RH999999')), ['ok', ['reference_differs']]);
t('a Transaction Reference unlike the Remarks warns remarks_differs', $warnOf($set(3, 'Transaction Reference', 'RH OTHER')), ['ok', ['remarks_differs']]);
t('an Opening Available unlike the derived opening warns opening_differs', $warnOf($all('Opening Available', 'UGX 1.00')), ['ok', ['opening_differs']]);
t('a posting date out of order warns dates_not_in_order', $warnOf($set(10, 'Posting Date', '28-DEC-26')), ['ok', ['dates_not_in_order']]);
$HZ = $H; $HZ[5]['_credit'] = true; $HZ[5]['_cents'] = 0; $HZ = g_rechain($HZ, 5);
$z0 = R(g_file($HZ));
t('a zero amount (0) is accepted and warns zero_amount', [code($z0), $z0['warnings'] ?? null, $z0['lines'][5]['credit_text'] ?? null, $z0['lines'][5]['credit_units'] ?? null],
    ['ok', ['zero_amount'], '0', 0]);
t('  and is in neither the credit nor the debit count', ($z0['facts']['credits'][0] ?? 0) + ($z0['facts']['debits'][0] ?? 0), 39);
t('a zero amount written .00 is the same', (function () use ($HZ, $set) { $r = R(g_file($HZ, 'UGX', ['mutate' => $set(5, 'Credit', '.00')])); return [code($r), $r['warnings'] ?? null]; })(),
    ['ok', ['zero_amount']]);
$every = R(g_file($HZ, 'UGX', ['mutate' => function ($L) {
    $L[3]['Cust Ref'] = 'RH1'; $L[4]['Total Amount'] = '5'; $L[6]['Customer Reference'] = 'RH999999';
    $L[7]['Transaction Reference'] = 'RH OTHER'; $L[10]['Posting Date'] = '28-DEC-26';
    foreach ($L as &$l) $l['Opening Available'] = 'UGX 1.00'; unset($l);
    return $L; }]));
t('every warning at once: still accepted, all seven listed in order', [code($every), $every['warnings'] ?? null], ['ok', EcobankDetailsCsv::WARNINGS]);
$wl = EcobankDetailsCsv::WARNINGS; $ws = $wl; sort($ws);
t('the warning list is sorted and has the seven of §a.4–§a.5', [$wl === $ws, count($wl)], [true, 7]);

echo "\nB5. Facts and lines\n";
$sumC = 0; $sumD = 0; $nC = 0; $nD = 0;
foreach ($H as $l) { if ($l['_credit']) { $nC++; $sumC += $l['_cents']; } else { $nD++; $sumD += $l['_cents']; } }
$fx = $clean['facts'] ?? [];
t('facts: exactly the keys designed', array_keys($fx), ['last4', 'currency', 'opening_stated', 'closing_stated', 'opening_derived', 'closing_derived', 'line_count',
    'period_from', 'period_to', 'file_order_differs', 'credits', 'debits', 'cheque_first', 'cheque_last']);
t('facts: credits and debits counted and summed in cents', [$fx['credits'] ?? null, $fx['debits'] ?? null], [[$nC, $sumC], [$nD, $sumD]]);
t('facts: the opening and closing, stated and derived, agree with the history', [$fx['opening_stated'] ?? null, $fx['opening_derived'] ?? null, $fx['closing_stated'] ?? null, $fx['closing_derived'] ?? null],
    [0, 0, $H[39]['_run'], $H[39]['_run']]);
t('facts: the period and the first and last bank line', [$fx['period_from'] ?? null, $fx['period_to'] ?? null, $fx['cheque_first'] ?? null, $fx['cheque_last'] ?? null],
    [EcobankDetailsCsv::date($H[0]['Posting Date']), EcobankDetailsCsv::date($H[39]['Posting Date']), $H[0]['Cheque Number'], $H[39]['Cheque Number']]);
t('facts: currency and file order', [$fx['currency'] ?? null, $fx['file_order_differs'] ?? null], ['UGX', false]);
$LINE_KEYS = ['seq', 'file_row', 'sr_no', 'posting_date', 'posting_raw', 'value_date', 'value_raw', 'type_code', 'type_desc', 'side', 'credit_text',
    'debit_text', 'credit_units', 'debit_units', 'running_text', 'running_units', 'cust_ref', 'bank_ref', 'cheque_number', 'remarks', 'txn_code',
    'loan_outstanding', 'total_amount', 'reference', 'customer_reference', 'transaction_reference', 'compare_digest'];
$shapeBad = [];
foreach (array_merge($clean['lines'] ?? [], $sh['lines'] ?? [], $padEmpty['lines'] ?? []) as $l) {
    if (array_keys($l) !== $LINE_KEYS || !is_int($l['credit_units']) || !is_int($l['debit_units']) || !is_int($l['running_units'])
        || $l['credit_units'] < 0 || $l['debit_units'] < 0 || ($l['credit_units'] && $l['debit_units'])
        || ($l['side'] === 'C') !== ($l['credit_text'] !== '') || !preg_match('/^[0-9a-f]{64}$/', $l['compare_digest'])
        || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $l['posting_date']) || !preg_match('/^\d{10}$/', $l['cheque_number'])) $shapeBad[] = $l['seq'];
}
t('every line has the designed keys and the shapes 088 CHECKs', $shapeBad, []);
$rowsSh = array_column($sh['lines'] ?? [], 'file_row'); sort($rowsSh);
t('the shuffled file keeps each line\'s own file row: rows 2 to 41, each once', $rowsSh, range(2, 41));
is_(($sh['lines'][0]['file_row'] ?? 2) !== 2 || ($sh['lines'][1]['file_row'] ?? 3) !== 3, 'the shuffled file\'s lines come in bank order, not file order');
$p1 = $clean['lines'][0] ?? null;
$stored = $p1 === null ? [] : array_merge($p1, ['credit_units' => (string)$p1['credit_units'], 'debit_units' => (string)$p1['debit_units'], 'running_units' => (string)$p1['running_units']]);
unset($stored['side']);
if ($stored) $stored['side'] = $stored['credit_text'] !== '' ? 'C' : 'D';
t('the digest recomputes from a stored row (units as digits, side from credit_text)', $stored ? EcobankDetailsCsv::compareDigest($stored) : null, $p1['compare_digest'] ?? 'setup');
$pd = R(g_file($H, 'UGX', ['pad' => 30, 'mutate' => $re0]));
t('padding and number spelling leave the digest unchanged', array_column($pd['lines'] ?? [], 'compare_digest'), array_column($clean['lines'] ?? [], 'compare_digest'));
$rs = R(g_file($H, 'UGX', ['mutate' => $set(2, 'Remarks', 'RH RESTATED')]));
t('a restated Remarks changes that line\'s digest, and only that one', count(array_diff_assoc(array_column($rs['lines'] ?? [], 'compare_digest'), array_column($clean['lines'] ?? [], 'compare_digest'))), 1);
$twin = $H; $twin[11]['_credit'] = $twin[10]['_credit']; $twin[11]['_cents'] = $twin[10]['_cents'];
foreach (['Posting Date', 'Value Date', 'Type Code', 'Type Description', 'Remarks', 'Transaction Code', 'Reference', 'Customer Reference', 'Transaction Reference'] as $c) $twin[11][$c] = $twin[10][$c];
$twin = g_rechain($twin, 10);
$tw = R(g_file($twin));
t('two identical payments on one day are two lines (their bank line numbers differ)', [code($tw), count($tw['lines'] ?? []),
    ($tw['lines'][10]['cheque_number'] ?? 'a') !== ($tw['lines'][11]['cheque_number'] ?? 'a')], ['ok', 40, true]);
$f = fitK($tw['lines'] ?? [], array_slice($tw['lines'] ?? [], 0, 10));
t('  and an overlapping file adds both of them', code($f) . '/' . count($f['new'] ?? []), 'ok/30');

echo "\nB6. fit(): the other branches\n";
$f = F($B['lines'], [], null, null);
t('nothing kept yet: every line is new', code($f) . '/' . count($f['new'] ?? []) . '/' . ($f['known'] ?? -1), 'ok/70/0');
[$kv, $ka, $kb] = bk_view($k, $B['lines']);
$inFileIds = array_flip(array_column($B['lines'], 'cheque_number'));
$gapNo = '';                                                                 // the first bank line number the file skips
for ($q = (int)($k[0]['cheque_number'] ?? 0) + 1; $q < (int)($k[39]['cheque_number'] ?? 0); $q++) {
    if (!isset($inFileIds[sprintf('%010d', $q)])) { $gapNo = sprintf('%010d', $q); break; }
}
$extra = $kv; if ($gapNo !== '') $extra[$gapNo] = str_repeat('a', 64);       // an invented kept line the file does not hold
ksort($extra, SORT_STRING);
t('a kept line missing from the file inside its range is refused (bank line no.)',
    $gapNo === '' ? 'setup: no gap in the kept range' : [code($f = F($B['lines'], $extra, $ka, $kb)), $f['param'] ?? null], ['overlap_interleave', $gapNo]);
$kbOff = $kb; if ($kbOff) $kbOff['running_units'] += 1;
t('the balance at the shared line is asserted equal, as the last guard', [code($f = F($B['lines'], $kv, $ka, $kbOff)), $f['param'] ?? null], ['overlap_differs', $kb['cheque_number'] ?? 'setup']);
$f = fitK($E['lines'], $k);
t('gap_after names the last kept posting date, ISO', $f['param'] ?? null, $k[39]['posting_date'] ?? 'setup');
$f = fitK($A['lines'], $k2);
t('gap_before names the first kept posting date, ISO', $f['param'] ?? null, $k2[0]['posting_date'] ?? 'setup');

echo "\nB7. The fixed sentences (SPEC-bank §a.9)\n";
$CODES_A9 = ['not_received', 'too_large', 'empty', 'not_csv', 'summary_export', 'header', 'columns', 'too_many_lines', 'field_too_long', 'account_mixed',
    'currency_form', 'currency_mismatch', 'account_other', 'account_linked_elsewhere', 'account_not_offered', 'sequence', 'date', 'amount_both_neither',
    'amount_form', 'amount_scale', 'balance_form', 'chain', 'closing', 'lineid_form', 'lineid_duplicate', 'lineid_order', 'overlap_differs',
    'overlap_interleave', 'gap_after', 'gap_before', 'already_kept', 'nothing_new', 'link_required', 'changed_since_check', 'check_expired'];
t('a sentence for exactly the codes of §a.9', array_keys(EcobankDetailsCsv::SENTENCES), $CODES_A9);
$SAMPLE = ['mb' => '4', 'n' => '10000', 'row' => '7', 'seq' => '17', 'chq' => '0000000107', 'last4' => '0002', 'date' => '2026-08-31', 'date?' => '2026-09-01',
    'pair' => 'USD|UGX', 'kind' => 'PDF', '' => ''];
$left = [];
foreach (EcobankDetailsCsv::SENTENCES as $c => $def) {
    $s = EcobankDetailsCsv::sentence($c, $SAMPLE[$def[0]]);
    if ($s === EcobankDetailsCsv::SENTENCE_FALLBACK || strpbrk($s, '{}«»') !== false || substr($s, -1) !== '.') $left[] = $c;
}
t('every code renders with its own kind of parameter, no placeholder left', $left, []);
t('summary_export', EcobankDetailsCsv::sentence('summary_export'), "This is Ecobank's account summary. It has balances but no transaction lines. Download Account Details for this account instead.");
t('currency_mismatch', EcobankDetailsCsv::sentence('currency_mismatch', 'USD|UGX'), 'This file is in USD; the chosen account books UGX. Choose the other account.');
t('account_other', EcobankDetailsCsv::sentence('account_other', '0002'), "This file is for a different account, ending \u{2022}\u{2022}\u{2022}\u{2022}0002. Choose the right account, or download that account's statement.");
t('sequence', EcobankDetailsCsv::sentence('sequence'), 'Line numbers are missing or repeated: the file is incomplete. Download the whole period again.');
t('chain', EcobankDetailsCsv::sentence('chain', '17'), 'The running balance does not add up at line 17: the file is not complete or not intact. Download the whole period again.');
t('closing', EcobankDetailsCsv::sentence('closing'), "The last running balance is not the file's Closing Available: the file stops early. Download the whole period again.");
t('gap_after', EcobankDetailsCsv::sentence('gap_after', '2026-08-31'),
    'This file does not reach back to the last kept line, dated 31 Aug 2026. Download Account Details from 31 Aug 2026 or earlier.');
t('overlap_differs', EcobankDetailsCsv::sentence('overlap_differs', '0000000107'),
    'This file disagrees with a statement already kept, at bank line 0000000107. Nothing was kept. Report it; do not edit the file.');
t('not_csv PDF / EXCEL / ENCODING', [EcobankDetailsCsv::sentence('not_csv', 'PDF'), EcobankDetailsCsv::sentence('not_csv', 'EXCEL'), EcobankDetailsCsv::sentence('not_csv', 'ENCODING')],
    ['This is a PDF. In Ecobank online banking, download Account Details as CSV.', 'This is an Excel file. In Ecobank online banking, download Account Details as CSV.',
     'This is not a CSV file. In Ecobank online banking, download Account Details as CSV.']);
t('already_kept with and without the date it was kept', [EcobankDetailsCsv::sentence('already_kept', '2026-09-01'), EcobankDetailsCsv::sentence('already_kept')],
    ['This exact file was kept on 01 Sep 2026. Nothing changed.', 'This exact file was kept. Nothing changed.']);
t('too_large', EcobankDetailsCsv::sentence('too_large', '4'), 'This file is larger than 4 MB; nothing was kept. Download a shorter period, if Ecobank allows it.');
$fb = EcobankDetailsCsv::SENTENCE_FALLBACK;
t('an unknown code gives the fallback', EcobankDetailsCsv::sentence('rh_unknown'), $fb);
t('a parameter outside 088\'s shape gives the fallback', EcobankDetailsCsv::sentence('chain', 'RH client 1'), $fb);
t('a sentence never prints a parameter of the wrong shape: overlap_differs with RHMARKER', EcobankDetailsCsv::sentence('overlap_differs', 'RHMARKER'), $fb);
t('  nor a date that is not a date', [EcobankDetailsCsv::sentence('gap_after', '17'), EcobankDetailsCsv::sentence('gap_after', '2026-02-30')], [$fb, $fb]);
t('  nor a currency pair that is not one', EcobankDetailsCsv::sentence('currency_mismatch', 'USD'), $fb);
t('  nor last four that are not four digits', EcobankDetailsCsv::sentence('account_other', '0000000001'), $fb);
t('  nor a not_csv kind it does not know', EcobankDetailsCsv::sentence('not_csv', 'RHMARKER'), $fb);
t('  nor anything at all for a code that carries nothing', EcobankDetailsCsv::sentence('summary_export', 'RHMARKER'), $fb);
is_(strpbrk($fb, '0123456789{}') === false, 'the fallback itself carries nothing', $fb);

echo "\nB8. A cell that ends in a line feed (BK97-R2): refused by its own rule, never read as the value before it\n";
// A bare $ also matches just before a final line feed, so "0000000107<LF>" inside its quotes would pass /^\d{10}$/ while
// 088's length() and GLOB CHECKs refuse it: a file shown as passed that can never be kept, or a refusal whose check row
// cannot be written. Each cell gets the line feed on every line, or on line 7 only (index 6).
$lfAt = function (string $col, ?int $at) { return function ($L) use ($col, $at) {
    foreach ($L as $i => &$l) if ($at === null || $i === $at) $l[$col] .= "\n";
    unset($l); return $L; }; };
foreach ([['Account', null, 'account_mixed', ''], ['Opening Available', null, 'currency_form', ''], ['Closing Available', null, 'currency_form', ''],
          ['SEQUENCE_NMBR', 6, 'sequence', ''], ['Cheque Number', 6, 'lineid_form', '7'], ['Posting Date', 6, 'date', '7'],
          ['Value Date', 6, 'date', '7'], ['Running Balance', 6, 'balance_form', '7']] as [$col, $at, $want, $wp]) {
    $r = R(g_file($H, 'UGX', ['mutate' => $lfAt($col, $at)]));
    t("the {$col} cell ending in a line feed is refused ({$want})", [code($r), $r['param'] ?? null], [$want, $wp]);
}
$r = R(g_file($H, 'UGX', ['mutate' => function ($L) { $c = $L[6]['Credit'] !== '' ? 'Credit' : 'Debit'; $L[6][$c] .= "\n"; return $L; }]));
t('the Credit or Debit cell ending in a line feed is refused (amount_form)', [code($r), $r['param'] ?? null], ['amount_form', '7']);
t('  the control: the same file without any line feed in a cell passes', code(R(g_file($H))), 'ok');
t('the value readers refuse a value that ends in a line feed', [EcobankDetailsCsv::date("18-AUG-26\n"), EcobankDetailsCsv::amount("12.50\n"),
    EcobankDetailsCsv::balance("1.00\n"), EcobankDetailsCsv::ccyAmount("UGX 1.00\n")], [null, null, null, null]);
t('  the control: the same values without it are read', [EcobankDetailsCsv::date('18-AUG-26'), EcobankDetailsCsv::amount('12.50'),
    EcobankDetailsCsv::balance('1.00'), EcobankDetailsCsv::ccyAmount('UGX 1.00')], ['2026-08-18', 1250, 100, ['UGX', 100]]);
t('paramFits refuses a parameter that ends in a line feed', [EcobankDetailsCsv::paramFits("002\n"), EcobankDetailsCsv::paramFits("0002\n"),
    EcobankDetailsCsv::paramFits('0002')], [false, false, true]);
t('a sentence never prints a parameter that ends in a line feed', [EcobankDetailsCsv::sentence('account_other', "0002\n"),
    EcobankDetailsCsv::sentence('chain', "17\n"), EcobankDetailsCsv::sentence('overlap_differs', "0000000107\n"),
    EcobankDetailsCsv::sentence('gap_after', "2026-08-31\n")], [$fb, $fb, $fb, $fb]);

echo "\nB9. The scan that bounds a record before fgetcsv() builds it (BK-SEC-3)\n";
$WM = new ReflectionMethod('EcobankDetailsCsv', 'width');
$WM->setAccessible(true);
/** Each record of $s: [width()'s fields and next offset, fgetcsv()'s count and ftell(), a blank line?, line feeds in it]. */
$scanVs = function (string $s) use ($WM): array {
    $out = []; $n = strlen($s);
    $fh = fopen('php://temp', 'w+'); fwrite($fh, $s); rewind($fh);
    while (($pos = (int)ftell($fh)) < $n) {
        $mine = $WM->invoke(null, $s, $pos, $n);
        $r = fgetcsv($fh, 0, ',', '"', '');
        if ($r === false) { $out[] = [$mine, 'false', false, 0]; break; }
        $at = (int)ftell($fh);
        $out[] = [$mine, [count($r), $at], $r === [null], substr_count(substr($s, $pos, $at - $pos), "\n")];
    }
    fclose($fh);
    return $out;
};
$exBad = [];
foreach ([[' "a,b" ,c' . "\n", 2], ['"a"",b",c' . "\n", 2], ['"line one' . "\n" . 'line two",x' . "\n", 2], ["a,b,\n", 3], ["\n", 1], ["\r\n", 1],
          ["\t\"x\"y,z\r\n", 2], [str_repeat(',', 5000) . "\n", 5001], ['"open' . "\n" . 'to the end', 1]] as [$s, $want]) {
    $v = $scanVs($s);
    if (($v[0][0][0] ?? null) !== $want || ($v[0][1][0] ?? null) !== $want || ($v[0][0][1] ?? null) !== ($v[0][1][1] ?? -1)) $exBad[] = json_encode([$s, $v[0] ?? null]);
}
t('the scan counts the shapes it must follow as fgetcsv() does (white space before a quote, "", a quoted line end, a trailing comma, a blank line, CRLF, 5,001 fields, a quote open to the end)',
    $exBad, []);
mt_srand(97001);
$alpha = [',', ',', ',', '"', '"', '""', ' ', "\t", 'a', 'RH', "\n", "\n", "\r\n", "\u{00E9}", "\x0B", "\x0C"];
$mis = 0; $nRec = 0; $multi = 0; $blankN = 0; $maxF = 0; $firstMis = '';
for ($c = 0; $c < 4000; $c++) {
    $s = ''; $len = mt_rand(0, 60);
    for ($i = 0; $i < $len; $i++) $s .= $alpha[mt_rand(0, count($alpha) - 1)];
    foreach ($scanVs($s) as $v) {
        if ($v[1] === 'false') continue;                                     // fgetcsv() ended the file there: nothing to compare
        $nRec++;
        if ($v[0] !== $v[1]) { $mis++; if ($firstMis === '') $firstMis = (string)json_encode([$s, $v]); }
        if ($v[3] > 1) $multi++;
        if ($v[2]) $blankN++;
        $maxF = max($maxF, $v[1][0]);
    }
}
t('the scan counts every record of a generated corpus as fgetcsv() does: the same fields, the same next record', [$mis, $firstMis], [0, '']);
is_($nRec > 5000 && $multi > 100 && $blankN > 100 && $maxF >= 10,
    "  the control: the corpus holds {$nRec} records, {$multi} carried across lines by a quote, {$blankN} blank, up to {$maxF} fields");
$rowsH = explode("\n", rtrim(g_file($H), "\n"));                              // the header, then 40 data rows
$withRow = function (int $at, string $row) use ($rowsH): string { $l = $rowsH; array_splice($l, $at, 0, [$row]); return implode("\n", $l) . "\n"; };
$r = R($withRow(5, str_repeat(',', EcobankDetailsCsv::SCAN_FIELDS)));
t('a data row too wide to parse (SCAN_FIELDS + 1 fields) is refused at its own row, as columns', [code($r), $r['param'] ?? null], ['columns', '6']);
$r = R($withRow(5, str_repeat(',', EcobankDetailsCsv::SCAN_FIELDS - 1)));
t('  and one of exactly SCAN_FIELDS fields, which fgetcsv() still parses, is refused the same way', [code($r), $r['param'] ?? null], ['columns', '6']);
$r = R($withRow(5, '"' . "\n" . '"' . str_repeat(',', 3000)));
t('  and one that a quoted line end carries into the next line', [code($r), $r['param'] ?? null], ['columns', '6']);
t('a first row too wide to parse is refused as header', code(R(str_repeat(',', 3000) . "\n" . implode("\n", array_slice($rowsH, 1)) . "\n")), 'header');
$bl = $rowsH; array_splice($bl, 3, 0, ['']); array_splice($bl, 8, 0, [str_repeat(',', 3000)]);
$r = R(implode("\n", $bl) . "\n");
t('the first row that breaks a rule decides: a blank line at row 4 before a row too wide at row 9', [code($r), $r['param'] ?? null], ['columns', '4']);
$HLx = explode("\n", rtrim(g_file($HL), "\n"));                               // LINES_MAX + 1 data rows, from B1
array_splice($HLx, 3, 1, [str_repeat(',', 1500)]);
$r = R(implode("\n", $HLx) . "\n", EcobankDetailsCsv::BYTES_MAX);
t('too many lines still comes first, with a row too wide to parse at row 4 (SPEC-bank §a.2\'s order)', [code($r), $r['param'] ?? null], ['too_many_lines', '10000']);
unset($HLx, $r);

// ═══════════════════════════════════════════════════════════════════════════
echo "\nC. The marker test: RHMARKER in every text cell, account 0000000001 — each refusal in turn\n";
$MK = 'RHMARKER';
$HM = g_history(70, 3);
$mf = function (array $lines, array $o = []) use ($MK) { return g_file($lines, 'UGX', $o + ['marker' => $MK]); };
$m40 = $mf(array_slice($HM, 0, 40));
$mclean = R($m40);
t('the marker file itself passes', code($mclean), 'ok');
$mut = function (callable $m) use ($mf, $HM) { return $mf(array_slice($HM, 0, 40), ['mutate' => $m]); };
$HLM = g_history(EcobankDetailsCsv::LINES_MAX + 1, 5);
$PRODUCERS = [
    ['empty', function () { return R(''); }],
    ['too_large', function () use ($m40) { return R($m40, strlen($m40) - 1); }],
    ['not_csv', function () use ($m40) { return R('%PDF-' . $m40); }],
    ['not_csv', function () use ($m40) { return R("PK\x03\x04" . $m40); }],
    ['not_csv', function () use ($m40) { return R($m40 . "\0"); }],
    ['not_csv', function () use ($mut) { return R($mut(function ($L) { $L[2]['Remarks'] .= "\xff"; return $L; })); }],
    ['not_csv', function () use ($m40) { return R(str_replace("\n", "\r", $m40)); }],
    ['summary_export', function () use ($MK) { return R(g_summary(true, 'UGX', $MK)); }, '(with its header)'],
    ['summary_export', function () use ($MK) { return R(g_summary(false, 'UGX', $MK)); }, '(without its header)'],
    ['header', function () use ($HM, $MK) { return R(g_file(array_slice($HM, 0, 40), 'UGX', ['marker' => $MK, 'header' => array_merge(['RHMARKER Account'], array_slice(EcobankDetailsCsv::HEADER, 1))])); }],
    ['columns', function () use ($m40) { return R($m40 . "\"RHMARKER\",\"0000000001\",\"RH-Account Holder One\"\n"); }],
    ['too_many_lines', function () use ($HLM, $MK) { return R(g_file($HLM, 'UGX', ['marker' => $MK]), EcobankDetailsCsv::BYTES_MAX); }],
    ['field_too_long', function () use ($mut) { return R($mut(function ($L) { $L[3]['Remarks'] = 'RHMARKER ' . str_repeat('R', 1001); return $L; })); }],
    ['account_mixed', function () use ($mut) { return R($mut(function ($L) { $L[9]['Account'] = '0000000002'; return $L; })); }],
    ['account_mixed', function () use ($mut) { return R($mut(function ($L) { $L[9]['Account Name'] = 'RHMARKER RH-Account Holder Two'; return $L; })); }],
    ['currency_form', function () use ($mut) { return R($mut(function ($L) { $L[9]['Closing Available'] = 'UGX 1.00'; return $L; })); }],
    ['sequence', function () use ($mut) { return R($mut(function ($L) { array_splice($L, 10, 1); return $L; })); }],
    ['date', function () use ($mut) { return R($mut(function ($L) { $L[3]['Value Date'] = '32-AUG-26'; return $L; })); }],
    ['amount_both_neither', function () use ($mut) { return R($mut(function ($L) { $L[5]['Credit'] = '1'; $L[5]['Debit'] = '1'; return $L; })); }],
    ['amount_form', function () use ($mut, $side) { return R($mut(function ($L) use ($side) { return $side($L, 5, '1,000'); })); }],
    ['amount_scale', function () use ($mut, $side) { return R($mut(function ($L) use ($side) { return $side($L, 5, '1.125'); })); }],
    ['balance_form', function () use ($mut) { return R($mut(function ($L) { $L[7]['Running Balance'] = '  12.5'; return $L; })); }],
    ['chain', function () use ($mut) { return R($mut(function ($L) { $L[20]['Running Balance'] = '        1.00'; return $L; })); }],
    ['closing', function () use ($mut) { return R($mut(function ($L) { array_pop($L); return $L; })); }],
    ['lineid_form', function () use ($mut) { return R($mut(function ($L) { $L[8]['Cheque Number'] = 'RHMARKER'; return $L; })); }],
    ['lineid_duplicate', function () use ($mut) { return R($mut(function ($L) { $L[8]['Cheque Number'] = $L[7]['Cheque Number']; return $L; })); }],
    ['lineid_order', function () use ($mut) { return R($mut(function ($L) { [$L[8]['Cheque Number'], $L[7]['Cheque Number']] = [$L[7]['Cheque Number'], $L[8]['Cheque Number']]; return $L; })); }],
];
$mk = function (array $lines, array $o = []) use ($mf) { $r = R($mf($lines, $o)); return $r['lines'] ?? []; };
$mK = $mk(array_slice($HM, 0, 40));
$PRODUCERS[] = ['overlap_differs', function () use ($mk, $HM, $mK) { return fitK($mk(array_slice($HM, 30, 40), ['mutate' => function ($L) { $L[2]['Remarks'] .= ' RESTATED'; return $L; }]), $mK); }];
$PRODUCERS[] = ['overlap_interleave', function () use ($mk, $HM, $mK) { $h = $mK; array_splice($h, 20, 1); return fitK($mk(array_slice($HM, 0, 70)), $h); }];
$PRODUCERS[] = ['gap_after', function () use ($mk, $HM, $mK) { return fitK($mk(array_slice($HM, 40, 30)), $mK); }];
$PRODUCERS[] = ['gap_before', function () use ($mk, $HM, $mK) { return fitK($mK, $mk(array_slice($HM, 40, 30))); }];
$PRODUCERS[] = ['nothing_new', function () use ($mK) { return fitK($mK, $mK); }];
$LEAKS = [$MK, BK_GEN_ACCOUNT, BK_GEN_HOLDER, 'RH-CLIENT', 'INV-0'];
$seenMarker = [];
foreach ($PRODUCERS as $i => $pr) {
    [$want, $make] = $pr;
    $r = $make();
    $c = code($r); $p = (string)($r['param'] ?? '');
    $text = $c . "\n" . $p . "\n" . EcobankDetailsCsv::sentence($c, $p);
    $leak = array_values(array_filter($LEAKS, function ($s) use ($text) { return strpos($text, $s) !== false; }));
    $label = 'marker: ' . $want . ' ' . ($pr[2] ?? '#' . ($i + 1)) . ' leaks nothing';
    t($label, [$c, $leak], [$want, []]);
    $seenMarker[$want] = true;
    unset($r);
}
unset($HLM);
$mcodes = array_keys($seenMarker); sort($mcodes);
$owned = array_merge(EcobankDetailsCsv::READER_CODES, EcobankDetailsCsv::FIT_CODES); sort($owned);
t('the marker test covers every code the reader and fit() own', $mcodes, $owned);

// ═══════════════════════════════════════════════════════════════════════════
echo "\nD. Every parameter fits 088; every owned code is produced; the account and the holder never leave\n";
$bad = []; $codesSeen = [];
foreach ($PARAMS as [$c, $p]) {
    $codesSeen[$c] = true;
    if (!preg_match('/^[0-9A-Z|-]{0,24}$/D', (string)$p) || !EcobankDetailsCsv::paramFits((string)$p)) $bad[] = "{$c}:{$p}";
    if (!preg_match('/^[a-z_]{1,32}$/', $c)) $bad[] = "code {$c}";
}
t('every refusal any case produced carries a param 088 accepts, and a code of [a-z_]', $bad, []);
$cs = array_keys($codesSeen); sort($cs);
t('the codes produced are exactly the codes the reader and fit() own', $cs, $owned);
$noLeak = function ($v): bool { $j = (string)json_encode($v, JSON_UNESCAPED_UNICODE); return strpos($j, BK_GEN_ACCOUNT) === false && strpos($j, BK_GEN_HOLDER) === false; };
t('facts carry the last four only, never the full account', [$clean['facts']['last4'] ?? null, $noLeak($clean['facts'] ?? null), $noLeak($mclean['facts'] ?? null)], ['0001', true, true]);
t('the lines carry neither the account nor the holder\'s name, even marked', [$noLeak($clean['lines'] ?? null), $noLeak($mclean['lines'] ?? null), count($mclean['lines'] ?? [])], [true, true, 40]);
t('nor do the warnings', [$noLeak($every['warnings'] ?? null), $noLeak($mclean['warnings'] ?? null)], [true, true]);
$casesTally = [$pass, $fail];
printf("\ncases: %d passed, %d failed\n", $pass, $fail);

if ($mode === '--cases-only') {
    printf("\n%d passed, %d failed\n", $pass, $fail);
    exit($fail === 0 ? 0 : 1);
}

// ═══════════════════════════════════════════════════════════════════════════
// From here on child processes run, so this run gets its own temporary folder, removed when it ends.
$own = rtrim(sys_get_temp_dir(), '/') . '/bk-tb1-' . getmypid() . '-' . bin2hex(random_bytes(3));
mkdir($own, 0700, true);
putenv('TMPDIR=' . $own);
register_shutdown_function(function () use ($own) { exec('rm -rf ' . escapeshellarg($own)); });
$self = __FILE__;

echo "\nE. Memory: the whole keep path at the caps, in a child under memory_limit=64M\n";
$BUDGET = 64 * 1048576;
function bk_mem_child(string $self, string $root, array $payload, string $limit = '64M'): array {
    $cmd = escapeshellarg(PHP_BINARY) . ' -d memory_limit=' . $limit . ' ' . escapeshellarg($self) . ' --memory-child --root=' . escapeshellarg($root);
    $pr = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($pr)) return [-1, null, 'proc_open failed'];
    @fwrite($pipes[0], serialize($payload)); fclose($pipes[0]);                // @: a child that dies at once (a control) closes its end
    $out = (string)stream_get_contents($pipes[1]); $err = (string)stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    $rc = proc_close($pr);
    return [$rc, json_decode(trim($out), true), trim($err)];
}
foreach ([['10,000 lines (the line cap)', 0], ['10,000 lines near BYTES_MAX', 96]] as [$label, $width]) {
    $HM2 = g_history(EcobankDetailsCsv::LINES_MAX, 5);
    if ($width) { foreach ($HM2 as &$l) { $l['Remarks'] = str_pad($l['Remarks'] . ' RH-INVENTED', $width, 'X'); $l['Transaction Reference'] = $l['Remarks']; } unset($l); }
    $keptFull = R(g_file(array_slice($HM2, 0, EcobankDetailsCsv::LINES_MAX - 200)), EcobankDetailsCsv::BYTES_MAX)['lines'] ?? [];
    $csv = g_file($HM2); unset($HM2);
    $known = [];
    foreach ($keptFull as $kl) $known[$kl['cheque_number']] = $kl['compare_digest'];
    $payload = ['csv' => $csv, 'known' => $known, 'first' => $keptFull ? bk_end($keptFull[0]) : null, 'last' => $keptFull ? bk_end($keptFull[count($keptFull) - 1]) : null];
    unset($keptFull);
    $twoCols = count(array_filter($known, function ($v, $q) { return preg_match('/^[0-9a-f]{64}$/', (string)$v) && preg_match('/^\d{10}$/', (string)$q); }, ARRAY_FILTER_USE_BOTH));
    is_($twoCols === EcobankDetailsCsv::LINES_MAX - 200 && array_keys((array)$payload['first']) === ['cheque_number', 'posting_date', 'running_units', 'credit_units', 'debit_units'],
        "{$label}: the store's view is two columns of the kept lines plus five fields of each end", (string)$twoCols);
    if ($width) is_(strlen($csv) >= (int)(0.9 * EcobankDetailsCsv::BYTES_MAX) && strlen($csv) <= EcobankDetailsCsv::BYTES_MAX,
        "{$label}: the file is within 10% under BYTES_MAX", sprintf('%.2f MB', strlen($csv) / 1048576));
    [$rc, $res, $err] = bk_mem_child($self, $root, $payload);
    if (!$width) {                                                            // the control on the control: the limit binds
        [$rc16, $res16] = bk_mem_child($self, $root, $payload, '16M');
        is_($rc16 !== 0 && (($res16['code'] ?? '') !== 'ok'), "{$label}: the control — the same child under memory_limit=16M does not complete", 'exit ' . $rc16 . ' ' . json_encode($res16));
    }
    unset($payload, $csv, $known);
    $peak = (int)($res['peak'] ?? 0);
    is_($rc === 0 && is_array($res) && ($res['code'] ?? '') === 'ok' && ($res['new'] ?? 0) === 200 && ($res['known'] ?? 0) === EcobankDetailsCsv::LINES_MAX - 200,
        "{$label}: read() and fit() complete under memory_limit=64M (200 new, " . (EcobankDetailsCsv::LINES_MAX - 200) . ' known)', 'exit ' . $rc . ' ' . json_encode($res) . ' ' . substr($err, 0, 300));
    is_($peak > 0 && $peak < $BUDGET, sprintf('%s: peak %.1f MB, under the 64 MB budget (file %.2f MB)', $label, $peak / 1048576, ($res['bytes'] ?? 0) / 1048576), (string)$peak);
}
// BK-SEC-3: files that are not statements, each at the byte cap. The cap bounds the file, not the work of one record: a
// row of commas is millions of fields, and a reader that parses everything before it checks a width dies inside
// fgetcsv(). Each must be refused with its own code, under the same budget. Made when used, never held all at once.
$hdrLine = implode(',', array_map(function ($v) { return '"' . $v . '"'; }, EcobankDetailsCsv::HEADER)) . "\n";
$capB = EcobankDetailsCsv::BYTES_MAX; $room = $capB - strlen($hdrLine);
$MAL = [
    'E1 one row of commas to BYTES_MAX' => [function () use ($hdrLine, $room) { return $hdrLine . str_repeat(',', $room - 1) . "\n"; }, 'columns', '2'],
    'E2 rows of 4,000 commas to BYTES_MAX' => [function () use ($hdrLine, $room) { return $hdrLine . str_repeat(str_repeat(',', 4000) . "\n", intdiv($room, 4001)); }, 'columns', '2'],
    'E3 rows of SCAN_FIELDS fields to BYTES_MAX (each still parsed)' => [function () use ($hdrLine, $room) {
        return $hdrLine . str_repeat(str_repeat(',', EcobankDetailsCsv::SCAN_FIELDS - 1) . "\n", intdiv($room, EcobankDetailsCsv::SCAN_FIELDS)); }, 'columns', '2'],
    'E4 blank lines to BYTES_MAX, then one record' => [function () use ($hdrLine, $room) { return $hdrLine . str_repeat("\n", $room - 2) . "x\n"; }, 'too_many_lines', '10000'],
    'E5 a quoted line end, then commas to BYTES_MAX' => [function () use ($hdrLine, $room) { return $hdrLine . "\"\n\"" . str_repeat(',', $room - 4) . "\n"; }, 'columns', '2'],
    'E6 a first row of commas to BYTES_MAX' => [function () use ($capB) { return str_repeat(',', $capB - 1) . "\n"; }, 'header', ''],
];
$malRun = function (string $tree, callable $make, string $limit = '64M') use ($self): array {
    return bk_mem_child($self, $tree, ['csv' => $make(), 'known' => [], 'first' => null, 'last' => null], $limit);
};
foreach ($MAL as $label => [$make, $wantCode, $wantParam]) {
    [$rc, $res, $err] = $malRun($root, $make);
    $peak = (int)($res['peak'] ?? 0); $bytes = (int)($res['bytes'] ?? 0);
    is_($rc === 0 && $bytes > (int)(0.99 * $capB) && $bytes <= $capB && ($res['code'] ?? '') === $wantCode && ($res['param'] ?? null) === $wantParam
        && $peak > 0 && $peak < $BUDGET,
        sprintf('%s: refused %s under memory_limit=64M, peak %.1f MB (file %.2f MB)', $label, $wantCode . ($wantParam !== '' ? ' ' . $wantParam : ''), $peak / 1048576, $bytes / 1048576),
        'exit ' . $rc . ' ' . json_encode($res) . ' ' . substr((string)$err, 0, 200));
}
[$rc8, $res8] = $malRun($root, $MAL['E1 one row of commas to BYTES_MAX'][0], '8M');
is_($rc8 !== 0 && !isset($res8['code']), 'E1: the control — the same child under memory_limit=8M does not complete (the limit binds)', 'exit ' . $rc8 . ' ' . json_encode($res8));

// ═══════════════════════════════════════════════════════════════════════════
echo "\nF. Weakened copies: each must fail its own cases, at the case named for it\n";
const TB1_FILES = ['lib/EcobankDetailsCsv.php', 'tests/test_bank_reader.php', 'tests/fixtures/bank_statement_gen.php'];
/**
 * A copy of the reader, its generator and this test, with $edits applied: each anchor must occur exactly once before
 * its edit, and afterwards the replacement must be present and the anchor must survive only where the replacement
 * carries it. The edited files must lint. Returns [the copy's folder, '' or why it could not be made].
 */
function bk_weakened_copy(string $root, string $own, array $edits): array {
    $tmp = $own . '/copy-' . bin2hex(random_bytes(4));
    foreach (TB1_FILES as $rel) {
        if (!is_dir(dirname($tmp . '/' . $rel))) mkdir(dirname($tmp . '/' . $rel), 0700, true);
        if (!copy($root . '/' . $rel, $tmp . '/' . $rel)) return [$tmp, "cannot copy {$rel}"];
    }
    foreach ($edits as [$rel, $old, $new]) {
        $src = (string)file_get_contents($tmp . '/' . $rel);
        $n = substr_count($src, $old);
        if ($n !== 1) return [$tmp, "anchor found {$n} times in {$rel}"];
        $out = str_replace($old, $new, $src, $cnt);
        if ($cnt !== 1 || ($new !== '' && strpos($out, $new) === false) || substr_count($out, $old) !== substr_count($new, $old)) return [$tmp, "edit not applied in {$rel}"];
        file_put_contents($tmp . '/' . $rel, $out);
    }
    foreach (array_unique(array_column($edits, 0)) as $rel) {
        $lint = []; exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($tmp . '/' . $rel) . ' 2>&1', $lint, $lrc);
        if ($lrc !== 0) return [$tmp, "the edited {$rel} does not lint"];
    }
    return [$tmp, ''];
}
/** The copy's own cases, judged in a child with a 1 MB upload limit (so the effective cap differs from BYTES_MAX). */
function bk_child(string $tree): array {
    $out = [];
    exec(escapeshellarg(PHP_BINARY) . ' -d upload_max_filesize=1M -d post_max_size=8M ' . escapeshellarg($tree . '/tests/test_bank_reader.php')
        . ' --root=' . escapeshellarg($tree) . ' --cases-only 2>&1', $out, $rc);
    $fails = array_values(array_filter($out, function ($l) { return strpos($l, '  FAIL ') === 0; }));
    $tally = preg_match('/^(\d+) passed, (\d+) failed$/', (string)end($out), $tm) ? [(int)$tm[1], (int)$tm[2]] : null;
    $reader = '';
    foreach ($out as $l) if (strncmp($l, 'reader: ', 8) === 0) { $reader = substr($l, 8); break; }
    return [$rc, $fails, $tally, $reader];
}
$LIB = 'lib/EcobankDetailsCsv.php';
[$tmp, $why] = bk_weakened_copy($root, $own, [[$LIB, 'final class EcobankDetailsCsv', 'final class EcobankDetailsCsv']]);
if ($why === '') {
    [$rc, $fails, $tally, $reader] = bk_child($tmp);
    is_($rc === 0 && $fails === [] && $tally === $casesTally && $reader === realpath($tmp . '/' . $LIB),
        'the control: an unchanged copy passes its own cases with the same tally (' . ($tally ? "{$tally[0]} passed, {$tally[1]} failed" : 'no tally') . ') and judges its own reader',
        'exit ' . $rc . ' tally ' . json_encode($tally) . ' reader ' . $reader . ' ' . ($fails[0] ?? ''));
} else {
    is_(false, 'the control: an unchanged copy can be made', $why);
}
exec('rm -rf ' . escapeshellarg($tmp));

// [label, edits, the FAIL line that must appear]. The first twenty are proto2/mutants2.php's, anchor for anchor.
$MUTANTS = [
    ['W1 chain in file order', [[$LIB, 'foreach ($bySeq as $s => $i) {', 'foreach (array_keys($data) as $i) { $s = (int)$data[$i][4];']],
        '/^  FAIL file order differs: accepted, flagged, same lines$/'],
    ['W2 no SEQUENCE gap check', [[$LIB, "if (isset(\$bySeq[\$s]) || \$s > count(\$data)) return self::no('sequence');", '']],
        '/^  FAIL gap in SEQUENCE_NMBR refused$/'],
    ['W3 no closing check', [[$LIB, "if (\$prevRun !== \$c[1]) return self::no('closing');", '']],
        '/^  FAIL last line dropped: closing$/'],
    ['W5 rounds the 3rd decimal', [[$LIB, "if (strlen(\$frac) > 2 && trim(substr(\$frac, 2), '0') !== '') return 'scale';", '']],
        '/^  FAIL amount forms$/'],
    ['W6 summary not recognised', [[$LIB, "if (count(\$head) === 10) return self::no('summary_export');", '']],
        '/^  FAIL account summary with header refused, no param$/'],
    ['W7 default CSV escape', [[$LIB, "fgetcsv(\$fh, 0, ',', '\"', '')", "fgetcsv(\$fh, 0, ',', '\"')"]],
        '/^  FAIL a field ending in a backslash keeps 23 columns$/'],
    ['W8 both credit and debit ok', [[$LIB, "if ((\$ct === '') === (\$dt === '')) return self::no('amount_both_neither', \$s);",
        "if (\$ct === '' && \$dt === '') return self::no('amount_both_neither', \$s);"]],
        '/^  FAIL both credit and debit refused$/'],
    ['W9 overlap compares amounts only', [[$LIB, "'cust_ref','bank_ref','remarks','txn_code','loan_outstanding','total_amount','reference','customer_reference','transaction_reference'];", '];']],
        '/^  FAIL an overlapping line that differs \(Remarks\) is refused$/'],
    ['W11 interleave allowed', [[$LIB, "return self::no('overlap_interleave', \$q);\n            } else {", "\$new[] = \$l;\n            } else {"]],
        '/^  FAIL a new line inside the kept range is refused$/'],
    ['W4 strtotime for dates', [[$LIB, "if (!preg_match('/^(\\d{2})-(JAN|FEB|MAR|APR|MAY|JUN|JUL|AUG|SEP|OCT|NOV|DEC)-(\\d{2})$/D', \$s, \$m)) return null;",
        "\$t = strtotime(\$s); return \$t ? date('Y-m-d', \$t) : null;"]],
        "/^  FAIL non-date '32-AUG-26' refused$/"],
    ['W12 header by alias (any order)', [[$LIB, "if (\$head !== self::HEADER) return self::no('header');",
        "if (array_diff(self::HEADER, \$head) && count(\$head) < 23) return self::no('header');"]],
        '/^  FAIL renamed column refused$/'],
    ['W13 lenient date case', [[$LIB, "(JAN|FEB|MAR|APR|MAY|JUN|JUL|AUG|SEP|OCT|NOV|DEC)-(\\d{2})$/D'", "(JAN|FEB|MAR|APR|MAY|JUN|JUL|AUG|SEP|OCT|NOV|DEC)-(\\d{2})$/Di'"],
        [$LIB, 'self::MONTHS[$m[2]]', 'self::MONTHS[strtoupper($m[2])]']],
        "/^  FAIL non-date '07-Aug-26' refused$/"],
    ['V1 overlap compares padded running text', [[$LIB, "'side','credit_units','debit_units','running_units',", "'side','credit_units','debit_units','running_text',"]],
        '/^  FAIL re-padded export \(20 over 18\) adds only its new lines$/'],
    ['V2 join by balance only (no shared line)', [[$LIB, "if (\$after && !isset(\$inFile[\$kMax])) return self::no('gap_after', \$last['posting_date']);",
        "if (\$after) { \$a = null; foreach (\$new as \$nl) if (strcmp(\$nl['cheque_number'], \$kMax) > 0) { \$a = \$nl; break; } if (\$a['running_units'] - \$a['credit_units'] + \$a['debit_units'] !== \$last['running_units']) return self::no('gap_after', \$last['posting_date']); }"]],
        '/^  FAIL 41-70 after 1-40: no shared line, refused \(the cost of issue 4\)$/'],
    ['V3 running balance needs a leading digit', [[$LIB, "if (!preg_match('/^(-?)(\\d{0,15})\\.(\\d{2})$/D', trim(\$s, ' '), \$m)) return null;",
        "if (!preg_match('/^(-?)(\\d{1,15})\\.(\\d{2})$/D', trim(\$s, ' '), \$m)) return null;"]],
        '/^  FAIL balance forms$/'],
    ['V4 ccy amount needs a leading digit', [[$LIB, "if (!preg_match('/^([A-Z]{3}) (-?)(\\d{0,15})(?:\\.(\\d{1,2}))?$/D', \$s, \$m)) return null;",
        "if (!preg_match('/^([A-Z]{3}) (-?)(\\d{1,15})(?:\\.(\\d{1,2}))?$/D', \$s, \$m)) return null;"]],
        '/^  FAIL ccy forms$/'],
    ['V5 lower-case not_csv param', [[$LIB, "return self::no('not_csv', 'PDF');", "return self::no('not_csv', 'pdf');"]],
        '/^  FAIL PDF refused \(PDF\)$/'],
    ['V6 amount text stored untrimmed', [[$LIB, "'credit_text'=>\$ct, 'debit_text'=>\$dt,", "'credit_text'=>\$r[9], 'debit_text'=>\$r[10],"]],
        '/^  FAIL a space in the empty side passes and is stored empty$/'],
    ['V7 no front-join shared line', [[$LIB, "if (\$before && !isset(\$inFile[\$kMin])) return self::no('gap_before', \$first['posting_date']);", '']],
        '/^  FAIL 1-40 in front of 41-70: no shared line, refused$/'],
    ['V8 gap param as a free date pair', [[$LIB, "return self::no('gap_after', \$last['posting_date']);", "return self::no('gap_after', \$last['posting_date'] . ' to later');"]],
        '/^  FAIL every refusal any case produced carries a param 088 accepts, and a code of \[a-z_\]$/'],
    // six more, for what this build adds to the prototype (BW14 and BW15 of SPEC-bank §f.4 among them)
    ['X1 (BW14) the summary refusal carries the first row', [[$LIB, "return self::no('summary_export');", "return self::no('summary_export', implode('|', \$first));"]],
        '/^  FAIL marker: summary_export \(without its header\) leaks nothing$/'],     // the header-less summary's first row is its data
    ['X2 (BW15) facts carry the full account', [[$LIB, "'last4'=>substr(\$acct, -4),", "'last4'=>substr(\$acct, -4), 'account'=>\$acct,"]],
        '/^  FAIL facts carry the last four only, never the full account$/'],
    ['X3 the effective cap ignored (BYTES_MAX always)', [[$LIB, '$cap = $cap === null ? self::effectiveCap() : min($cap, self::BYTES_MAX);', '$cap = self::BYTES_MAX;']],
        '/^  FAIL with no cap given, one byte over the effective cap is refused, with the cap in MB$/'],
    ['X4 FIELD_MAX counted in bytes', [[$LIB, 'if (strlen($f) > self::FIELD_MAX && self::chars($f) > self::FIELD_MAX)', 'if (strlen($f) > self::FIELD_MAX)']],
        '/^  FAIL a field of 1,000 two-byte characters is accepted$/'],
    ['X5 a sentence prints a bank line number of any shape', [[$LIB, "            if (!preg_match('/^\\d{10}\$/D', \$param)) return self::SENTENCE_FALLBACK;\n", '']],
        '/^  FAIL a sentence never prints a parameter of the wrong shape: overlap_differs with RHMARKER$/'],
    ['X6 a blank line before the header reaches count()', [[$LIB, "        if (\$head === null) return self::no('header');                         // a blank line before the header\n", '']],
        '/^  FAIL a blank line before the header is refused as header$/'],
    // BK97-R2 and BK-SEC-3
    ['X7 the D modifier dropped from every value rule', [
        [$LIB, "if (!preg_match('/^\\d{10}\$/D', \$acct)", "if (!preg_match('/^\\d{10}\$/', \$acct)"],
        [$LIB, "if (!preg_match('/^[1-9]\\d{0,6}\$/D', \$r[4]))", "if (!preg_match('/^[1-9]\\d{0,6}\$/', \$r[4]))"],
        [$LIB, "if (!preg_match('/^\\d{10}\$/D', \$r[15]))", "if (!preg_match('/^\\d{10}\$/', \$r[15]))"],
        [$LIB, "-(\\d{2})\$/D', \$s, \$m)) return null;", "-(\\d{2})\$/', \$s, \$m)) return null;"],
        [$LIB, "if (!preg_match('/^(\\d*)(?:\\.(\\d+))?\$/D', \$s, \$m)", "if (!preg_match('/^(\\d*)(?:\\.(\\d+))?\$/', \$s, \$m)"],
        [$LIB, "\\.(\\d{2})\$/D', trim(\$s, ' '), \$m)) return null;", "\\.(\\d{2})\$/', trim(\$s, ' '), \$m)) return null;"],
        [$LIB, "(?:\\.(\\d{1,2}))?\$/D', \$s, \$m)) return null;", "(?:\\.(\\d{1,2}))?\$/', \$s, \$m)) return null;"]],
        '/^  FAIL the Cheque Number cell ending in a line feed is refused \(lineid_form\)$/'],
    ['X8 PARAM_SHAPE without the D modifier', [[$LIB, "public const PARAM_SHAPE = '/^[0-9A-Z|-]{0,24}\$/D';", "public const PARAM_SHAPE = '/^[0-9A-Z|-]{0,24}\$/';"]],
        '/^  FAIL paramFits refuses a parameter that ends in a line feed$/'],
    ['X9 the scan does not skip white space before a quote', [[$LIB, '$q = $p + strspn($b, " \t\x0B\x0C\r", $p);', '$q = $p;']],
        '/^  FAIL the scan counts the shapes it must follow as fgetcsv\(\) does /'],
    ['X10 the scan takes a doubled quote for the closing one', [[$LIB, 'if ($e + 1 < $n && $b[$e + 1] === \'"\') { $p = $e + 2; continue; }', '']],
        '/^  FAIL the scan counts the shapes it must follow as fgetcsv\(\) does /'],
    ['X11 a record too wide to parse stands as the header', [[$LIB, '                $r = [];', '                $r = self::HEADER;']],
        '/^  FAIL a data row too wide to parse \(SCAN_FIELDS \+ 1 fields\) is refused at its own row, as columns$/'],
    ['X12 a later refusal overwrites the first', [[$LIB, '            if (!$keep || $bad !== null) continue;', '            if (!$keep) continue;']],
        '/^  FAIL the first row that breaks a rule decides: a blank line at row 4 before a row too wide at row 9$/'],
];
$caught = 0;
foreach ($MUTANTS as [$label, $edits, $catcher]) {
    [$tmp, $why] = bk_weakened_copy($root, $own, $edits);
    if ($why !== '') { is_(false, "weakened copy \"{$label}\" can be made", $why); exec('rm -rf ' . escapeshellarg($tmp)); continue; }
    [$rc, $fails, $tally, $reader] = bk_child($tmp);
    $named = array_values(array_filter($fails, function ($l) use ($catcher) { return preg_match($catcher, $l) === 1; }));
    $ok = $rc !== 0 && $fails !== [] && $named !== [] && $reader === realpath($tmp . '/' . $LIB);
    if ($ok) $caught++;
    is_($ok, "caught: {$label} — by its named case",
        'exit ' . $rc . ', ' . count($fails) . ' failure(s), ' . count($named) . ' at the named case; reader ' . $reader . '; first: ' . ($fails[0] ?? 'none'));
    echo '         ' . count($fails) . ' case(s) failed' . ($tally ? " ({$tally[0]} passed)" : ' (no tally)') . ($fails ? '; first: ' . trim(substr($fails[0], 7, 110)) : '') . "\n";
    exec('rm -rf ' . escapeshellarg($tmp));
}
t('every weakened copy was caught', $caught, count($MUTANTS));

echo "\nF2. Weakened copies of the bounded reading: each must break section E's budget at the files named for it\n";
$MEMMUT = [
    ['WM1 the scan ignored (every record handed to fgetcsv())', [[$LIB, '            if ($w > self::SCAN_FIELDS) {', '            if (false && $w > self::SCAN_FIELDS) {']],
        ['E1 one row of commas to BYTES_MAX', 'E5 a quoted line end, then commas to BYTES_MAX', 'E6 a first row of commas to BYTES_MAX']],
    ['WM2 the rows kept after the outcome is known (the keep-all loop)', [[$LIB, '            if (!$keep || $bad !== null) continue;', '            if (!$keep || $bad !== null) { $rows[] = $r; continue; }']],
        ['E3 rows of SCAN_FIELDS fields to BYTES_MAX (each still parsed)']],
    ['WM3 blank lines kept as entries', [[$LIB, '            if ($r === [null]) { if ($blank === null) $blank = $k; continue; }', '            if ($r === [null]) { $rows[] = null; if ($blank === null) $blank = $k; continue; }']],
        ['E4 blank lines to BYTES_MAX, then one record']],
];
[$tmp, $why] = bk_weakened_copy($root, $own, [[$LIB, 'final class EcobankDetailsCsv', 'final class EcobankDetailsCsv']]);
$ctl = [];
if ($why === '') foreach ($MAL as $label => [$make, $wc, $wp]) { [$rc, $res] = $malRun($tmp, $make); $ctl[] = ($rc === 0 && ($res['code'] ?? '') === $wc && ($res['param'] ?? null) === $wp) ? 'ok' : $label; }
t('the control: an unchanged copy reads every file of section E under 64M, with the same codes', [$why, $ctl], ['', array_fill(0, count($MAL), 'ok')]);
exec('rm -rf ' . escapeshellarg($tmp));
$mcaught = 0;
foreach ($MEMMUT as [$label, $edits, $files]) {
    [$tmp, $why] = bk_weakened_copy($root, $own, $edits);
    if ($why !== '') { is_(false, "weakened copy \"{$label}\" can be made", $why); exec('rm -rf ' . escapeshellarg($tmp)); continue; }
    $broke = [];
    foreach ($files as $fl) { [$rc, $res] = $malRun($tmp, $MAL[$fl][0]); if ($rc !== 0 && !isset($res['code'])) $broke[] = $fl; }
    $ok = $broke === $files;
    if ($ok) $mcaught++;
    is_($ok, "caught: {$label} — no longer completes under 64M: " . implode('; ', $files), 'broke the budget at: ' . json_encode($broke));
    exec('rm -rf ' . escapeshellarg($tmp));
}
t('every weakened copy of the bounded reading was caught', $mcaught, count($MEMMUT));

// ═══════════════════════════════════════════════════════════════════════════
echo "\nG. The end check\n";
$leftOver = array_values(array_diff(scandir($own) ?: [], ['.', '..']));
t('no copy is left in this run\'s own folder (the folder itself goes at exit)', $leftOver, []);
is_(strpos((string)getenv('TMPDIR'), $own) === 0, 'every child ran with this run\'s own TMPDIR', (string)getenv('TMPDIR'));

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
