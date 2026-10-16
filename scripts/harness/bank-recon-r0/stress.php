<?php
// stress.php <db> <seconds> <ready file> — the plugin at its busiest, as the review measured it: for <seconds>, open the
// book read-write in WAL mode, write a burst of rows, delete them, and CLOSE — each close copies the -wal back into the
// book while a reader may be inside a query. Touches <ready> after the first burst. Prints the number of bursts.
[$_, $db, $secs, $ready] = $argv + [null, '', '10', ''];
$end = microtime(true) + (float)$secs; $n = 0;
while (microtime(true) < $end) {
    $p = new PDO('sqlite:' . $db); $p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $p->exec('PRAGMA busy_timeout = 5000'); $p->exec('PRAGMA journal_mode = WAL');
    $p->exec('BEGIN');
    $st = $p->prepare("INSERT INTO cb_ledger (sr, project, date, direction, amount, currency, category, status, created_at, updated_at)
                       VALUES ('STRESS', 'dishnet', '2026-10-03', 'in', 1, 'UGX', 'Receipt', 'approved', 'x', 'x')");
    for ($i = 0; $i < 1500; $i++) $st->execute();
    $p->exec('COMMIT');
    $p->exec("DELETE FROM cb_ledger WHERE sr = 'STRESS'");
    $st = null; $p = null;                       // the close: the -wal is copied back and removed
    if ($n++ === 0) touch($ready);
    usleep(random_int(50000, 250000));
}
echo $n, "\n";
