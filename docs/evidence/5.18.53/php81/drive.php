<?php
// 5.18.53 under the server's PHP: the plugin-log helper, and the open read that loses a write — with and without
// closeCursor(). $argv[1] is a copy of the plugin root (with an empty data/), $argv[2] a scratch directory.
error_reporting(E_ALL);
// As the staff app's API does (public.php, page=api): an error counts unless @ silenced it.
set_error_handler(function ($no, $str, $file, $line) { if (!(error_reporting() & $no)) return false; echo "PHP-ERROR [{$no}] {$str} at ", basename($file), ":{$line}\n"; return true; });
[$_, $root, $tmp] = $argv;
require $root . '/lib/PluginLog.php';
echo 'PHP ', PHP_MAJOR_VERSION, '.', PHP_MINOR_VERSION, "\n";
echo 'path: ', PluginLog::path() === $root . '/data/plugin.log' ? 'data/plugin.log beside the code' : 'WRONG ' . PluginLog::path(), "\n";
echo 'mask: ', PluginLog::mask("SQLSTATE[23000]: 19 refused: +256 700 000 111 a.b@c.test\nsecond line \xff"), "\n";
PluginLog::notSaved('the Message Log row', 'notification_audit_log', 'event job_assigned', new PDOException('SQLSTATE[HY000]: General error: 5 database is locked'));
$line = trim((string)file_get_contents(PluginLog::path()));
echo 'line: ', preg_replace('/^\[\d{4}-\d\d-\d\d \d\d:\d\d:\d\d( UTC)?\]/', '[<time>]', $line), "\n";

// The open read: a claim in BEGIN IMMEDIATE … COMMIT whose SELECT keeps a fetched row, another connection's write,
// then this connection's next write.
foreach (['left open (5.18.52)' => false, 'closeCursor() (5.18.53)' => true] as $label => $close) {
    $f = $tmp . '/t-' . ($close ? 'closed' : 'open') . '.db';
    @unlink($f); @unlink($f . '-wal'); @unlink($f . '-shm');
    $o = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION];
    $a = new PDO('sqlite:' . $f, null, null, $o);
    $a->exec('PRAGMA busy_timeout = 5000');
    $mode = (string)$a->query('PRAGMA journal_mode = WAL')->fetchColumn();
    $a->exec('CREATE TABLE state (job_id INTEGER PRIMARY KEY, version INTEGER)');
    $a->exec('CREATE TABLE log (id INTEGER PRIMARY KEY, event TEXT)');
    $a->exec('INSERT INTO state VALUES (11, 1)');
    $a->exec('BEGIN IMMEDIATE');
    $st = $a->prepare('SELECT * FROM state WHERE job_id = ?');
    $st->execute([11]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if ($close) $st->closeCursor();
    $a->exec('UPDATE state SET version = version + 1 WHERE job_id = 11');
    $a->exec('COMMIT');
    $b = new PDO('sqlite:' . $f, null, null, $o);
    $b->exec('PRAGMA busy_timeout = 5000');
    $b->exec('UPDATE state SET version = version + 1 WHERE job_id = 11');   // the other process: the webhook
    $t = microtime(true);
    try { $a->exec("INSERT INTO log (event) VALUES ('ops_job_accepted_self')"); $r = 'saved'; }
    catch (PDOException $e) { $r = 'NOT saved: ' . $e->getMessage(); }
    printf("%-26s journal %s · the next write: %s (%s)\n", $label, $mode, $r, (microtime(true) - $t) < 1 ? 'at once' : 'after waiting');
    $st = null; $a = null; $b = null;
}
