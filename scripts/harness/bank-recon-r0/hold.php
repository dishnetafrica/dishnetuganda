<?php
// hold.php <db> <ready file> <seconds> [keep|close|other|text] [go file] — P0's writer, copied for R0, with one more mode
// (text). The plugin, as the rehearsal plays it: open the book
// read-write in WAL mode with automatic checkpoints off, write one committed row, then hold the book open.
//   (default)  write a cb_ledger row at once, touch <ready>, hold for <seconds>; the row stays in the -wal until the close
//   writer modes, used between P0's two readings: wait for <ready> (P0 paused), then
//     close  add a cb_ledger row and close;   keep  add one and hold <seconds>;   other  write another table only and close;
//     text   change one description in place to a text of the same length, and close — no count R0 prints moves
//   and touch <go>.
[$_, $db, $ready, $secs] = $argv + [null, '', '', '0'];
$mode = $argv[4] ?? 'hold'; $go = $argv[5] ?? '';
if ($mode !== 'hold') { for ($i = 0; $i < 200 && !is_file($ready); $i++) usleep(50000); usleep(1100000); }
$p = new PDO('sqlite:' . $db); $p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$p->exec('PRAGMA busy_timeout = 5000'); $p->exec('PRAGMA journal_mode = WAL'); $p->exec('PRAGMA wal_autocheckpoint = 0');
$row = "INSERT INTO cb_ledger (sr, project, date, direction, amount, currency, category, status, created_at, updated_at)
        VALUES ('HOLD-1', 'dishnet', '2026-10-01', 'in', 4321, 'UGX', 'Receipt', 'approved', '2026-10-01', '2026-10-01')";
if ($mode === 'other') { $p->exec('CREATE TABLE IF NOT EXISTS rh_other (x)'); $p->exec('INSERT INTO rh_other VALUES (1)'); }
elseif ($mode === 'text') { if ($p->exec("UPDATE cb_ledger SET description = 'RH-MARK boda onf' WHERE description = 'RH-MARK boda one'") !== 1) { fwrite(STDERR, "hold.php text: the row was not found\n"); exit(3); } }
else $p->exec($row);
if ($mode === 'hold') { touch($ready); sleep((int)$secs); exit(0); }
touch($go);
if ($mode === 'keep') sleep((int)$secs);
