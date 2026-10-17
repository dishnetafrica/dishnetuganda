<?php
// grow-r0.php <db> <rows> — R0's own growth, for timing and memory (the specification's §5.1 "big"): <rows> varied ledger
// rows written by SQL in the sandbox — 300 clients (half the rows) and 60 persons, a dozen amounts (at least 7 digits,
// non-zero cents), dates over two weeks, both directions, 5 % voided with a plain stamp, some PAY- and COL- references,
// and a description of about 2 KB each, so every bucket rule has work and a whole-table fetch would not fit the budget.
// Fictitious only. Deterministic (a fixed seed).
$p = new PDO('sqlite:' . $argv[1]); $p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$p->exec('PRAGMA journal_mode = WAL');
mt_srand(20261016);
$amounts = [];
for ($k = 0; $k < 12; $k++) $amounts[] = round(3000000 + $k * 123457 + (13 + $k) / 100, 2);
$pad = str_repeat('RH-MARK filler text for the description of a grown row ', 36);   // ~2 KB
$st = $p->prepare("INSERT INTO cb_ledger (sr, project, date, direction, amount, currency, category, person, description, validation_ref, status, source,
                     crm_payment_id, crm_client_id, account_id, txn_type, created_at, updated_at)
                   VALUES (?, 'dishnet', ?, ?, ?, 'UGX', ?, ?, ?, ?, ?, ?, ?, ?, 0, '', ?, ?)");
$p->exec('BEGIN');
for ($i = 1; $i <= (int)$argv[2]; $i++) {
    $day = sprintf('2026-09-%02d', 1 + mt_rand(0, 13));
    $dir = mt_rand(0, 3) === 0 ? 'out' : 'in';
    $amt = $amounts[mt_rand(0, 11)];
    $cli = mt_rand(0, 1) ? 5100 + mt_rand(1, 300) : 0;
    $person = $cli ? '' : 'RH-MARK Grown ' . mt_rand(1, 60);
    $voided = mt_rand(1, 100) <= 5;
    $r = mt_rand(1, 10);
    $ref = $r === 1 ? 'PAY-' . (100000 + mt_rand(1, 20000)) : ($r === 2 ? 'COL-' . (50000 + mt_rand(1, 5000)) : '');
    $pay = $r === 1 ? (int)substr($ref, 4) : 0;
    $desc = 'RH-MARK grown ' . $i . ' ' . $pad . ($voided ? ' [VOIDED: RH-MARK grown reason]' : '');
    $st->execute(['BIG-' . $i, $day, $dir, $amt, $dir === 'in' ? 'Receipt' : 'Transport', $person, $desc, $ref, $voided ? 'voided' : 'approved',
                  $r === 1 ? 'crm_webhook' : 'manual', $pay, $cli, "$day 10:00:00", "$day 10:00:00"]);
}
$p->exec('COMMIT');
$p->exec('PRAGMA wal_checkpoint(TRUNCATE)');
