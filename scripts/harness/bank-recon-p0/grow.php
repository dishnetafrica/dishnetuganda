<?php
// grow.php <db> <rows> — make the book big enough for a reading to take a while: <rows> plain unassigned UGX receipts.
$p = new PDO('sqlite:' . $argv[1]); $p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$p->exec('PRAGMA journal_mode = WAL');
$p->exec("WITH RECURSIVE n(i) AS (SELECT 1 UNION ALL SELECT i + 1 FROM n WHERE i < " . (int)$argv[2] . ")
          INSERT INTO cb_ledger (sr, project, date, direction, amount, currency, category, status, created_at, updated_at)
          SELECT 'BIG', 'dishnet', '2026-09-20', 'in', 1, 'UGX', 'Receipt', 'approved', 'x', 'x' FROM n");
