<?php
declare(strict_types=1);
/**
 * A second connection to a book, in a process of its own (5.18.104, root docs/73 §H.4.16 K): TEST ONLY.
 *
 *   php bg_writer.php mark <book> <ready-file> <phone>    say ready, then store an 'in' seen mark (waiting on the book's lock)
 *   php bg_writer.php hold <book> <ready-file> <seconds>  take the book's write lock, say ready, hold it, release it
 */
[, $mode, $db, $ready, $arg] = $argv + [null, '', '', '', ''];
$p = new \PDO('sqlite:' . $db);
$p->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
$p->exec('PRAGMA busy_timeout = 8000');
if ($mode === 'mark') {
    touch($ready);
    $p->prepare("INSERT INTO wa_seen_marks (direction, phone, channel, instance, kind, wa_message_id) VALUES ('in', ?, 'sales', 'Sales-UG', 'ephemeralMessage', ?)")
      ->execute([(string)$arg, 'WO-BG-' . getmypid()]);
} elseif ($mode === 'hold') {
    $p->exec('BEGIN IMMEDIATE');
    touch($ready);
    sleep((int)$arg);
    $p->exec('COMMIT');
}
