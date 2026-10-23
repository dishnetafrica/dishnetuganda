<?php
declare(strict_types=1);
/**
 * The fake SMTP relay the e-mail tests read (fixtures/fake_smtp_server.php): a message is in its transcript
 * BEFORE the relay answers "queued" or "bye", and the transcript is replaced whole, never rewritten in place.
 *
 * Why this exists: on 20 Oct test_install_authorisation (H3) counted the messages before a send and got 0,
 * with forty-one already sent. The relay used to write a conversation down only after it had said goodbye and
 * closed the connection, and it rewrote the transcript in place (truncate, then write). A test reads that file
 * without a lock the moment the plugin returns, so it could find the newest message missing, or the whole file
 * empty, which decodes to no messages at all. The 13 Oct failure recorded in docs/07 (5.18.94, Known limits 7)
 * was very likely the same thing; its output was not kept.
 *
 *   php test_fake_smtp_relay.php                 the fixture
 *   php test_fake_smtp_relay.php --relay=<path>  the same checks against another copy of the relay; 5.18.98's
 *                                                (git show ce7ac388:dishnet-hybrid-sudan/tests/fixtures/
 *                                                fake_smtp_server.php) must FAIL them
 */

$relay = dirname(__FILE__) . '/fixtures/fake_smtp_server.php';
foreach (array_slice($argv, 1) as $a) {
    if (strpos($a, '--relay=') === 0) $relay = substr($a, 8);
    else { fwrite(STDERR, "unknown option: {$a}\n"); exit(2); }
}
if (!is_file($relay)) { fwrite(STDERR, "no relay at {$relay}\n"); exit(2); }

$pass = 0; $fail = 0;
function ok(bool $c, string $n, string $why = ''): void { global $pass, $fail;
    if ($c) { $pass++; printf("  ok   %s\n", $n); }
    else { $fail++; printf("  FAIL %s\n", $n); if ($why !== '') printf("       %s\n", $why); } }

$tmp = sys_get_temp_dir() . '/dn_smtp_relay_' . getmypid() . '_' . bin2hex(random_bytes(4));
mkdir($tmp, 0700);
$transcript = $tmp . '/smtp.json';

// A free port: the kernel picks one, it is released, the relay takes it.
$probe = stream_socket_server('tcp://127.0.0.1:0', $e1, $e2);
$port = (int)substr((string)stream_socket_get_name($probe, false), strrpos((string)stream_socket_get_name($probe, false), ':') + 1);
fclose($probe);

$proc = proc_open(sprintf('exec %s %s %d %s 30', escapeshellarg(PHP_BINARY), escapeshellarg($relay), $port, escapeshellarg($transcript)),
    [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
$reader = null;
$cleanup = function () use (&$proc, &$reader, $tmp): void {
    foreach ([$reader, $proc] as $p) { if (is_resource($p)) { @proc_terminate($p); @proc_close($p); } }
    foreach ((array)glob($tmp . '/{,.}*', GLOB_BRACE) as $f) { if (is_file($f)) @unlink($f); }
    @rmdir($tmp);
};
register_shutdown_function($cleanup);

for ($i = 0; $i < 100 && !is_file($transcript); $i++) usleep(50000);
if (!is_file($transcript)) { echo "  FAIL the relay never started\n\n0 passed, 1 failed\n"; exit(1); }

/** The transcript as a test reads it: no lock, at once. null when the file does not decode (a torn read). */
$read = function () use ($transcript): ?array {
    $raw = (string)@file_get_contents($transcript);
    $d = json_decode($raw, true);
    return is_array($d) ? $d : null;
};
$messages = function (?array $all): array {
    return array_values(array_filter((array)$all, function ($m) { return trim((string)($m['data'] ?? '')) !== ''; }));
};
$line = function ($c): string { return (string)fgets($c, 4096); };
$say = function ($c, string $s) use ($line): string { fwrite($c, $s . "\r\n"); return $line($c); };

/** A message of about $kb kilobytes, carrying $marker, with a line the sender must dot-stuff. */
$compose = function (string $marker, int $kb): string {
    $l = [];
    $l[] = 'Subject: ' . $marker;
    $l[] = '';
    $l[] = 'Message ' . $marker;
    $l[] = '.a line that begins with a dot';
    $chunk = str_repeat('x', 76);
    for ($i = 0, $n = (int)ceil($kb * 1024 / 78); $i < $n; $i++) $l[] = $chunk;
    return implode("\r\n", $l) . "\r\n";
};

// ─────────────────────────────────────────────────────────────────────────────
echo "A. The relay is up and its transcript starts empty\n";
$start = $read();
ok($start === [], 'the transcript decodes to an empty list before any connection', json_encode($start));

// A reader that reads the transcript as fast as it can for the whole run, counting the reads that do not decode. It
// writes its count beside the stats file and renames it over, so that the test never reads a half-written count; and,
// like the relay, it stops by itself — after two minutes — so that a test killed before its cleanup leaves no reader.
$stats = $tmp . '/reader.json'; $stop = $tmp . '/stop';
$readerCode = '$t=$argv[1];$s=$argv[2];$st=$argv[3];$n=0;$bad=0;$end=time()+120;'
    . 'while(!is_file($st)&&time()<$end){$r=(string)@file_get_contents($t);$n++;if(!is_array(json_decode($r,true)))$bad++;}'
    . 'file_put_contents($s.".part",json_encode(["reads"=>$n,"torn"=>$bad]));rename($s.".part",$s);';
$reader = proc_open(sprintf('exec %s -r %s %s %s %s', escapeshellarg(PHP_BINARY), escapeshellarg($readerCode),
    escapeshellarg($transcript), escapeshellarg($stats), escapeshellarg($stop)),
    [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $rp);

// ─────────────────────────────────────────────────────────────────────────────
echo "\nB. Each message is in the transcript before the relay says \"queued\", and the whole conversation before it says \"bye\"\n";
$N = 30; $lateQueued = []; $lateBye = []; $tornHere = 0; $noQuit = []; $connections = 0;
for ($i = 1; $i <= $N; $i++) {
    $marker = sprintf('relay-check-%02d', $i);
    $c = @stream_socket_client("tcp://127.0.0.1:{$port}", $e1, $e2, 5);
    if (!$c) { ok(false, "connection {$i} opened", $e2); break; }
    $connections++;
    stream_set_timeout($c, 10);
    $line($c);
    $say($c, 'EHLO test.local');
    while (($l = $line($c)) !== '' && strpos($l, '250 ') !== 0) { /* the rest of the EHLO answer */ }
    $say($c, 'MAIL FROM:<sender@example.test>');
    $say($c, "RCPT TO:<to{$i}@example.test>");
    $say($c, 'DATA');
    $body = $compose($marker, 64);
    fwrite($c, preg_replace('/^\./m', '..', $body) . ".\r\n");
    $queued = $line($c);
    $now = $read();                                   // at once, the way a test reads after the plugin returns
    if ($now === null) $tornHere++;
    $m = $messages($now);
    if (strpos($queued, '250') !== 0 || count($m) !== $i || strpos((string)($m[$i - 1]['data'] ?? ''), 'Message ' . $marker) === false) $lateQueued[] = $i;
    $bye = $say($c, 'QUIT');
    $now = $read();
    if ($now === null) $tornHere++;
    $m = $messages($now);
    $cmds = (array)($m[$i - 1]['commands'] ?? []);
    if (strpos($bye, '221') !== 0 || count($m) !== $i || end($cmds) !== 'QUIT') $lateBye[] = $i;
    fclose($c);
}
ok($lateQueued === [], "all {$N} messages were in the transcript the moment the relay said \"queued\"",
    'missing at that moment: messages ' . implode(', ', $lateQueued));
ok($lateBye === [], "all {$N} conversations, QUIT included, were in it the moment the relay said \"bye\"",
    'missing or incomplete at that moment: ' . implode(', ', $lateBye));
ok($tornHere === 0, 'every one of those reads decoded (no empty or half-written transcript)', "{$tornHere} reads did not decode");

// ─────────────────────────────────────────────────────────────────────────────
echo "\nC. A sender that hangs up without QUIT, and the readiness probe (QUIT, no message)\n";
$c = stream_socket_client("tcp://127.0.0.1:{$port}", $e1, $e2, 5); $connections++;
stream_set_timeout($c, 10);
$line($c); $say($c, 'HELO test.local');
while (($l = $line($c)) !== '' && strpos($l, '250 ') !== 0) { /* the relay answers HELO like EHLO, on two lines */ }
$say($c, 'MAIL FROM:<sender@example.test>'); $say($c, 'RCPT TO:<hangup@example.test>'); $say($c, 'DATA');
fwrite($c, preg_replace('/^\./m', '..', $compose('relay-check-hangup', 4)) . ".\r\n");
$queued = $line($c);
$m = $messages($read());
ok(strpos($queued, '250') === 0 && count($m) === $N + 1 && strpos((string)($m[$N]['data'] ?? ''), 'relay-check-hangup') !== false,
    'its message is there the moment the relay says "queued"', 'messages seen: ' . count($m));
fclose($c);
$c = stream_socket_client("tcp://127.0.0.1:{$port}", $e1, $e2, 5); $connections++;
$line($c); fwrite($c, "QUIT\r\n"); fclose($c);    // the sandbox's probe: it does not wait for the answer
// The probe waits for no answer, so its entry exists only once the relay has read its QUIT: poll, with a deadline.
for ($i = 0, $all = $read(); $i < 100 && !(is_array($all) && count($all) >= $connections && (array)(end($all)['commands'] ?? []) === ['QUIT']); $i++) { usleep(50000); $all = $read(); }
ok(is_array($all) && count($all) === $connections, "one entry per connection, no more: {$connections} connections, " . count((array)$all) . ' entries',
    'a conversation written at the end of its message and again at QUIT must replace its own entry, not add a second');
ok(count($messages($all)) === $N + 1, 'the hang-up is still exactly one message after the relay saw the connection close');
$last = is_array($all) ? end($all) : [];
ok((string)($last['data'] ?? 'x') === '' && (array)($last['commands'] ?? []) === ['QUIT'], 'the probe is one entry with no message and the one command it sent', json_encode($last));

// ─────────────────────────────────────────────────────────────────────────────
echo "\nD. What is written down is what was said\n";
$m = $messages($all);
$first = $m[0] ?? [];
ok(($first['mail_from'] ?? '') === 'sender@example.test' && ($first['rcpt_to'] ?? []) === ['to1@example.test'] && ($first['ehlo'] ?? '') === 'test.local',
    'the envelope and the EHLO name are recorded', json_encode(array_intersect_key($first, ['mail_from' => 1, 'rcpt_to' => 1, 'ehlo' => 1])));
ok(str_replace("\r\n", "\n", (string)($first['data'] ?? '')) === str_replace("\r\n", "\n", $compose('relay-check-01', 64)),
    'the message is the one composed: the sender\'s dot-stuffing undone');
ok(($first['commands'] ?? []) === ['EHLO test.local', 'MAIL FROM:<sender@example.test>', 'RCPT TO:<to1@example.test>', 'DATA', 'QUIT'],
    'the commands are the conversation in order, QUIT last', json_encode($first['commands'] ?? null));
$hang = $m[$N] ?? [];
$hangCmds = (array)($hang['commands'] ?? []);
ok(end($hangCmds) === 'DATA', 'the hang-up\'s commands end where it stopped talking (DATA), with no QUIT', json_encode($hang['commands'] ?? null));

// ─────────────────────────────────────────────────────────────────────────────
echo "\nD2. The other clients the suite uses: a silent probe, AUTH LOGIN with two messages and RSET, a hang-up after a later command\n";
// The silent probe (notify_harness.php, test_invoice_email_attachment, test_lifecycle_email_wiring, test_system_sender,
// test_reminder_email_cc): it reads the greeting and closes, saying nothing.
$c = stream_socket_client("tcp://127.0.0.1:{$port}", $e1, $e2, 5); $connections++;
stream_set_timeout($c, 10);
$line($c); fclose($c);
// The dunning ladder's sender (lib/OverdueDunningHelpers.php _rawSmtp): AUTH LOGIN unasked, then two messages on one
// connection, an RSET between them.
$c = stream_socket_client("tcp://127.0.0.1:{$port}", $e1, $e2, 5); $connections++;
stream_set_timeout($c, 10);
$line($c); $say($c, 'EHLO test.local');
while (($l = $line($c)) !== '' && strpos($l, '250 ') !== 0) { /* the rest of the EHLO answer */ }
$authSaid = [$say($c, 'AUTH LOGIN'), $say($c, base64_encode('relay-user')), $say($c, base64_encode('relay-secret-password'))];
$say($c, 'MAIL FROM:<sender@example.test>'); $say($c, 'RCPT TO:<auth1@example.test>'); $say($c, 'DATA');
fwrite($c, preg_replace('/^\./m', '..', $compose('relay-check-auth-1', 2)) . ".\r\n"); $line($c);
$say($c, 'RSET');
$say($c, 'MAIL FROM:<sender@example.test>'); $say($c, 'RCPT TO:<auth2@example.test>'); $say($c, 'DATA');
fwrite($c, preg_replace('/^\./m', '..', $compose('relay-check-auth-2', 2)) . ".\r\n"); $line($c);
$authBye = $say($c, 'QUIT'); fclose($c);
// A sender that hangs up after a command that follows its message: only the record made at the close holds that command.
$c = stream_socket_client("tcp://127.0.0.1:{$port}", $e1, $e2, 5); $connections++;
stream_set_timeout($c, 10);
$line($c); $say($c, 'HELO test.local');
while (($l = $line($c)) !== '' && strpos($l, '250 ') !== 0) { /* the relay answers HELO like EHLO, on two lines */ }
$say($c, 'MAIL FROM:<sender@example.test>'); $say($c, 'RCPT TO:<late@example.test>'); $say($c, 'DATA');
fwrite($c, preg_replace('/^\./m', '..', $compose('relay-check-late', 2)) . ".\r\n"); $line($c);
$say($c, 'RSET'); fclose($c);
$lateCmds = ['HELO test.local', 'MAIL FROM:<sender@example.test>', 'RCPT TO:<late@example.test>', 'DATA', 'RSET'];
for ($i = 0, $all2 = $read(); $i < 100 && !(is_array($all2) && count($all2) >= $connections && (array)(end($all2)['commands'] ?? []) === $lateCmds); $i++) { usleep(50000); $all2 = $read(); }
[$sp, $sa, $sh] = array_slice(is_array($all2) ? array_values($all2) : [], -3) + [[], [], []];
ok(is_array($all2) && count($all2) === $connections, "still one entry per connection: {$connections} connections, " . count((array)$all2) . ' entries');
ok(($sp['commands'] ?? null) === [] && (string)($sp['data'] ?? 'x') === '' && ($sp['mail_from'] ?? 'x') === '', 'the silent probe is one entry, with no command and no message', json_encode($sp));
ok(strpos((string)$authSaid[0], '334') === 0 && strpos((string)$authSaid[2], '235') === 0 && ($sa['auth'] ?? '') === 'LOGIN' && ($sa['auth_user'] ?? '') === 'relay-user',
    'AUTH LOGIN is accepted and its user name recorded', json_encode(array_intersect_key($sa, ['auth' => 1, 'auth_user' => 1])));
$raw2 = (string)json_encode($all2);
ok(strpos($raw2, 'relay-secret-password') === false && strpos($raw2, base64_encode('relay-secret-password')) === false, 'the password is recorded nowhere, in clear or in base64');
ok(($sa['commands'] ?? null) === ['EHLO test.local', 'AUTH LOGIN', 'MAIL FROM:<sender@example.test>', 'RCPT TO:<auth1@example.test>', 'DATA', 'RSET',
        'MAIL FROM:<sender@example.test>', 'RCPT TO:<auth2@example.test>', 'DATA', 'QUIT'] && strpos((string)$authBye, '221') === 0,
    'two messages on one connection are one entry: every command in order, RSET among them, the AUTH answers not', json_encode($sa['commands'] ?? null));
ok(($sa['rcpt_to'] ?? null) === ['auth1@example.test', 'auth2@example.test'] && str_replace("\r\n", "\n", (string)($sa['data'] ?? '')) === str_replace("\r\n", "\n", $compose('relay-check-auth-2', 2)),
    'its recipients accumulate across the two messages, and its message is the last one', json_encode($sa['rcpt_to'] ?? null));
ok(($sh['commands'] ?? null) === $lateCmds && strpos((string)($sh['data'] ?? ''), 'relay-check-late') !== false,
    'a hang-up after a command that follows its message keeps that command (RSET): written when the connection closed', json_encode($sh['commands'] ?? null));

// ─────────────────────────────────────────────────────────────────────────────
echo "\nE. No reader, however fast, ever saw a torn transcript\n";
touch($stop);
for ($i = 0; $i < 100 && !is_file($stats); $i++) usleep(50000);
$st = json_decode((string)@file_get_contents($stats), true) ?: [];
ok((int)($st['reads'] ?? 0) > 100, 'the reader really read it, many times over: ' . (int)($st['reads'] ?? 0) . ' reads (the control: a count of torn reads means nothing without them)');
ok(isset($st['torn']) && (int)$st['torn'] === 0, 'none of them was empty or half-written', ((int)($st['torn'] ?? -1)) . ' torn reads');
ok(glob($tmp . '/smtp.json.*') === [], 'the relay leaves no file of its own beside the transcript');

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail ? 1 : 0);
