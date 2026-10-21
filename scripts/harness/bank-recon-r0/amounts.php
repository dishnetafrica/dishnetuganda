<?php
// amounts.php <data dir> — every amount the builder wrote (cb_ledger amount, ssp_amount, fx_amount; staff_ledger amount;
// every "amount" in a store record or a fin_audit snapshot), at least 1,000,000, one per line in three forms: plain
// (1234567.89), with separators (1,234,567.89) and as cents (123456789). The rehearsal searches R0's output for each, as
// a whole word: none may appear.
$p = new PDO('sqlite:file:' . $argv[1] . '/plugin.sqlite3?immutable=1', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY]);
$t = array_column($p->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_ASSOC), 'name');
$v = [];
foreach ($p->query('SELECT amount, ssp_amount, fx_amount FROM cb_ledger')->fetchAll(PDO::FETCH_NUM) as $r) foreach ($r as $x) $v[] = $x;
if (in_array('staff_ledger', $t, true)) foreach ($p->query('SELECT amount FROM staff_ledger')->fetchAll(PDO::FETCH_COLUMN) as $x) $v[] = $x;
$walk = static function ($d) use (&$walk, &$v): void { if (is_array($d)) foreach ($d as $k => $x) { if ($k === 'amount' && !is_array($x)) $v[] = $x; else $walk($x); } };
foreach (['payment_collections', 'cash_ins', 'cash_expenses', 'crm_payment_retry'] as $s) {
    if (!in_array($s, $t, true)) continue;
    $cols = array_column($p->query("PRAGMA table_info($s)")->fetchAll(PDO::FETCH_ASSOC), 'name');
    if (!in_array('data', $cols, true)) continue;
    foreach ($p->query("SELECT data FROM $s")->fetchAll(PDO::FETCH_COLUMN) as $j) $walk(json_decode((string)$j, true));
}
if (in_array('fin_audit', $t, true)) foreach ($p->query('SELECT before_json, after_json FROM fin_audit')->fetchAll(PDO::FETCH_NUM) as $r) foreach ($r as $j) $walk(json_decode((string)$j, true));
$out = [];
foreach ($v as $x) {
    if ($x === null || !is_numeric($x) || abs((float)$x) < 1000000) continue;
    $f = abs((float)$x);
    $out[number_format($f, 2, '.', '')] = 1; $out[number_format($f, 2)] = 1; $out[(string)(int)round($f * 100)] = 1;
}
ksort($out, SORT_STRING);
echo implode("\n", array_keys($out)), "\n";
