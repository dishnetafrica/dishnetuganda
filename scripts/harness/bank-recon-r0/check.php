<?php
/**
 * check.php — compares R0's printed report with expect.php's, line for line (from the `tables` line to the last line
 * before `checks`), then checks the planted truth (ids.json "truth", by regex) and R0's own closing lines. One line per
 * assertion ("ok …" / "FAIL …"); the exit status is the number of failures (capped at 99).
 *
 *   php check.php <R0 output> <expected.txt> <ids.json> <tool|cand> <variant>
 */
declare(strict_types=1);
[$_, $outF, $expF, $idsF, $mode, $variant] = $argv + [null, '', '', '', 'tool', ''];
$o = (string)file_get_contents($outF);
$exp = array_values(array_filter(explode("\n", (string)file_get_contents($expF)), static fn($l) => $l !== ''));
$ids = json_decode((string)file_get_contents($idsF), true) ?: [];
$fails = 0;
function is_(bool $c, string $m, string $d = ''): void { global $fails; if ($c) echo "ok   $m\n"; else { $fails++; echo "FAIL $m" . ($d !== '' ? " — $d" : '') . "\n"; } }
$lines = explode("\n", $o); $got = []; $on = false;
foreach ($lines as $l) {
    if (strpos($l, '  tables          ') === 0) $on = true;
    if (strpos($l, '  checks          ') === 0) break;
    if ($on && $l !== '') $got[] = $l;
}
$first = null;
for ($k = 0; $k < max(count($got), count($exp)); $k++) if (($got[$k] ?? null) !== ($exp[$k] ?? null)) { $first = $k; break; }
is_($first === null && $got, 'the report equals the independent count, line for line (' . count($got) . ' lines)',
    $first === null ? 'no report' : 'line ' . ($first + 1) . ": R0 «" . ($got[$first] ?? '(none)') . "» expected «" . ($exp[$first] ?? '(none)') . '»');
$tagOk = static function (string $tag) use ($mode, $variant): bool {
    if ($tag === 'tool') return $mode === 'tool';
    if ($tag === 'cand') return $mode === 'cand';
    if ($tag === 'ug') return in_array($variant, ['ug', 'ug-novoid', 'ug-mixed', 'kyc-first'], true);
    if ($tag === 'cashins') return $variant !== 'bare';
    return true;
};
foreach ($ids['truth'] ?? [] as $t) {
    if (!$tagOk((string)($t[2] ?? ''))) continue;
    is_(preg_match('/' . str_replace('/', '\/', (string)$t[0]) . '/mu', $o) === 1, 'planted: ' . (string)$t[1]);
}
is_(preg_match('/^  database        .* — read-only, immutable, query_only=1; JSON functions yes$/m', $o) === 1, 'the database line: read-only, immutable, query_only=1, JSON functions yes');
is_(preg_match('/^R0: DONE — read only; nothing was written\.$/m', $o) === 1, 'R0 says DONE');
is_(preg_match('/^  checks          the parts add up: ok \(.*\); the connections changed 0 and 0 row\(s\); query_only=1$/m', $o) === 1, 'the parts add up; both connections changed 0 rows, query_only=1');
is_(preg_match('/read twice, immutable.*: the readings are identical; the database file did not move \(inode, size, times\); side files none, before or after$/m', $o) === 1,
    'read twice, immutable: identical readings, the file did not move, no side file');
exit(min($fails, 99));
