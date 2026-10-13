<?php
/**
 * check.php — compares P0's printed output with expect.php's figures. One line per assertion ("ok …" / "FAIL …"); the
 * exit status is the number of failures (capped at 99).
 *
 *   php check.php <P0 output file> <expected.json> <adjustment identified: yes|no>
 */
declare(strict_types=1);
[$_, $outF, $expF, $adjOk] = $argv + [null, '', '', 'yes'];
$o = (string)file_get_contents($outF);
$e = json_decode((string)file_get_contents($expF), true);
$fails = 0;
function is_(bool $c, string $m, string $d = ''): void { global $fails; if ($c) echo "ok   $m\n"; else { $fails++; echo "FAIL $m" . ($d !== '' ? " — $d" : '') . "\n"; } }
function money(float $v): string { return number_format(round($v, 2), 2); }
function delta(float $v, float $t): string { $d = round($v - $t, 2); return $d == 0.0 ? 'same' : ($d > 0 ? '+' : '') . money($d); }

is_(preg_match('/^  book            base ' . preg_quote($e['base'], '/') . ' \(/m', $o) === 1, "base {$e['base']}, as cashInHand() decides it");
is_(preg_match('/; currencies ' . preg_quote(implode(',', $e['currencies']), '/') . ' \(/', $o) === 1, 'currencies ' . implode(',', $e['currencies']));
if ($e['row_base'] !== null) {
    $same = $e['row_base'] === $e['base'] && $e['row_currencies'] === $e['currencies'];
    is_(preg_match('/the pages\' settings row: base ' . preg_quote($e['row_base'], '/') . ', currencies ' . preg_quote(implode(',', $e['row_currencies']), '/')
        . ($same ? ' — the same' : ' — DIFFERENT') . '/', $o) === 1, 'the settings row: ' . $e['row_base'] . ' / ' . implode(',', $e['row_currencies']) . ($same ? ' (same)' : ' (different)'));
}
$labels = ['U' => 'unassigned (no account), without the adjustment', 'ADJ' => 'the 12 Sep adjustment', 'CASH' => 'cash accounts',
           'MOMO' => 'mobile-money accounts', 'BANK' => 'bank accounts', 'OTHER' => 'other accounts (inventory, asset, director ...)',
           'ORPHAN' => 'rows naming an account that does not exist'];
foreach ($e['per'] as $cur => $x) {
    $a = $x['all']; $d = $x['dishnet'];
    $sec = preg_match('/^' . preg_quote($cur, '/') . '  cash in hand today.*?(?=^[A-Z]{3}  cash in hand today|^  checks|\z)/ms', $o, $m) ? $m[0] : '';
    is_($sec !== '', "$cur: a section is printed");
    $shown = in_array($cur, $e['shown'], true);
    is_(preg_match('/cash in hand today, all projects — as the accounts dashboard and the evening WhatsApp show it'
        . ($shown ? '' : ' \(this currency is NOT listed on the pages\)') . '\s+' . preg_quote(money($a['today']), '/') . '$/m', $sec) === 1,
        "$cur: today " . money($a['today']) . ' = CashbookService::cashInHand()' . ($shown ? '' : ', marked as not listed on the pages'));
    foreach ($labels as $k => $l) {
        if ($k === 'ORPHAN' && $a['cls'][$k][0] === 0) { is_(strpos($sec, $l) === false, "$cur: no orphan line when there is none"); continue; }
        is_(preg_match('/^       ' . preg_quote($l, '/') . '\s+' . $a['cls'][$k][0] . '\s+' . preg_quote(money($a['cls'][$k][1]), '/') . '$/m', $sec) === 1,
            "$cur: $k {$a['cls'][$k][0]} rows, net " . money($a['cls'][$k][1]));
    }
    is_(strpos($sec, "the parts add up to today's figure: yes") !== false, "$cur: the parts add up");
    is_(preg_match('/pending approval ' . $a['pend']['pending_approval'][0] . ' \(net ' . preg_quote(money($a['pend']['pending_approval'][1]), '/') . '\) and rejected '
        . $a['pend']['rejected'][0] . ' \(net ' . preg_quote(money($a['pend']['rejected'][1]), '/') . '\)/', $sec) === 1, "$cur: pending and rejected counted");
    $pl = [];
    foreach (['A', 'B', 'C', 'D'] as $k) {
        if ($adjOk !== 'yes' && in_array($k, ['B', 'D'], true)) {
            is_(preg_match('/^       ' . $k . '  .*\(not computed: the adjustment was not identified\)$/m', $sec) === 1, "$cur: option $k not computed without the adjustment");
            $pl[] = "$k -"; continue;
        }
        is_(preg_match('/^       ' . $k . '  .*?\s' . preg_quote(money($a['opts'][$k]), '/') . '\s+' . preg_quote(delta($a['opts'][$k], $a['today']), '/') . '$/m', $sec) === 1,
            "$cur: option $k " . money($a['opts'][$k]) . ' (' . delta($a['opts'][$k], $a['today']) . ')');
        $pl[] = "$k " . money($d['opts'][$k]) . ' (' . delta($d['opts'][$k], $d['today']) . ')';
    }
    is_(strpos($sec, 'the Cashbook hero (project dishnet, the page\'s default) today ' . money($d['today']) . '; under each option: ' . implode(' · ', $pl)) !== false,
        "$cur: the Cashbook hero (dishnet) " . money($d['today']) . ' = cashInHand(cur, dishnet); ' . implode(' · ', $pl));
}
is_(preg_match('/^P0: DONE — read only; nothing was written\.$/m', $o) === 1, 'P0 says DONE');
is_(preg_match('/the connections changed 0 and 0 row\(s\); query_only=1/', $o) === 1, 'both connections changed 0 rows, query_only=1');
is_(preg_match('/read twice, immutable.*: the readings are identical; the database file did not move \(inode, size, times\); side files none, before or after$/m', $o) === 1,
    'read twice, immutable: identical readings, the file did not move, no side file');
exit(min($fails, 99));
