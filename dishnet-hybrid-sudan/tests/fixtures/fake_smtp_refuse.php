<?php
/**
 * fake_smtp_refuse.php — 5.18.104 Part P (root docs/73 §H.5.6, §H.5.12 sections E and G): a loopback relay like
 * fake_smtp_server.php (which stays byte for byte) that, for listed addresses, refuses or stalls.
 *
 *   php tests/fixtures/fake_smtp_refuse.php <port> <transcript.json> <idle-seconds> <rules.json>
 *
 * rules.json, every key optional, every address compared case-insensitively:
 *   "refuse":  [addr, …]          RCPT TO answered "550 5.1.1 refused (test)"
 *   "delay":   {addr: seconds, …} RCPT TO answered "250" only after that many seconds
 *   "data554": [addr, …]          RCPT TO accepted, and the end of DATA answered "554 5.7.1 rejected (test)" whenever
 *                                 one of them was a recipient of the message
 *
 * The transcript is the same JSON array of sessions as fake_smtp_server.php writes (mail_from, rcpt_to — the addresses
 * the relay ACCEPTED — commands, data), plus "refused" (the RCPTs it refused) and "result" ('queued' | 'rejected' |
 * '' while the session is open). One connection at a time; plaintext; no AUTH offered. TEST ONLY: every address it ever
 * sees is a fixture's.
 */
declare(strict_types=1);

$port  = (int)($argv[1] ?? 0);
$file  = (string)($argv[2] ?? '');
$idle  = max(1, (int)($argv[3] ?? 30));
$rules = json_decode((string)@file_get_contents((string)($argv[4] ?? '')), true);
if ($port <= 0 || $file === '' || !is_array($rules)) { fwrite(STDERR, "usage: fake_smtp_refuse.php <port> <transcript> <idle> <rules.json>\n"); exit(1); }
$lc = function ($list): array { return array_map(function ($a) { return strtolower(trim((string)$a)); }, (array)$list); };
$refuse  = $lc($rules['refuse'] ?? []);
$data554 = $lc($rules['data554'] ?? []);
$delay   = [];
foreach ((array)($rules['delay'] ?? []) as $a => $s) $delay[strtolower(trim((string)$a))] = (int)$s;

$srv = @stream_socket_server("tcp://127.0.0.1:{$port}", $errno, $errstr);
if (!$srv) { fwrite(STDERR, "listen failed: {$errstr}\n"); exit(1); }

$write = function (array $all) use ($file): void {
    $tmp = $file . '.part';
    if (@file_put_contents($tmp, json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) !== false) @rename($tmp, $file);
};
$record = function (array $session, bool $replace) use ($file, $write): void {
    $all = is_file($file) ? (json_decode((string)@file_get_contents($file), true) ?: []) : [];
    if ($replace && $all !== []) array_pop($all);
    $all[] = $session;
    $write($all);
};
$write([]);   // listening: a client that finds the file knows the port is up

while (true) {
    $c = @stream_socket_accept($srv, $idle);
    if (!$c) break;
    stream_set_timeout($c, 40);
    $session = ['ehlo' => '', 'mail_from' => '', 'rcpt_to' => [], 'refused' => [], 'data' => '', 'commands' => [], 'result' => ''];
    fwrite($c, "220 fake.refuse.test ESMTP ready\r\n");
    $inData = false; $body = ''; $written = false;
    while (($line = fgets($c, 4096)) !== false) {
        if ($inData) {
            if (rtrim($line, "\r\n") === '.') {
                $inData = false;
                $session['data'] = preg_replace('/^\.\./m', '.', $body) ?? $body;
                $body = '';
                $hit = array_intersect(array_map('strtolower', $session['rcpt_to']), $data554) !== [];
                $session['result'] = $hit ? 'rejected' : 'queued';
                $record($session, $written); $written = true;
                fwrite($c, $hit ? "554 5.7.1 rejected (test)\r\n" : "250 2.0.0 Ok: queued as FAKE\r\n");
                continue;
            }
            $body .= $line;
            continue;
        }
        $cmd = rtrim($line, "\r\n");
        $session['commands'][] = $cmd;
        $verb = strtoupper(substr($cmd, 0, 4));
        if ($verb === 'EHLO' || $verb === 'HELO') {
            $session['ehlo'] = trim(substr($cmd, 5));
            fwrite($c, "250-fake.refuse.test\r\n250 8BITMIME\r\n");
        } elseif ($verb === 'MAIL') {
            $session['mail_from'] = preg_match('/<(.*)>/', $cmd, $m) ? $m[1] : trim(substr($cmd, 10));
            fwrite($c, "250 2.1.0 Ok\r\n");
        } elseif ($verb === 'RCPT') {
            $addr = preg_match('/<(.*)>/', $cmd, $m) ? $m[1] : trim(substr($cmd, 8));
            $k = strtolower($addr);
            if (in_array($k, $refuse, true)) {
                $session['refused'][] = $addr;
                fwrite($c, "550 5.1.1 refused (test)\r\n");
            } else {
                if (isset($delay[$k]) && $delay[$k] > 0) sleep($delay[$k]);
                $session['rcpt_to'][] = $addr;
                fwrite($c, "250 2.1.5 Ok\r\n");
            }
        } elseif ($verb === 'DATA') {
            $inData = true;
            fwrite($c, "354 End data with <CR><LF>.<CR><LF>\r\n");
        } elseif ($verb === 'QUIT') {
            $record($session, $written); $written = true;
            fwrite($c, "221 2.0.0 Bye\r\n");
            break;
        } elseif ($verb === 'RSET') {
            fwrite($c, "250 2.0.0 Ok\r\n");
        } else {
            fwrite($c, "502 5.5.2 Not implemented\r\n");
        }
    }
    @fclose($c);
    $record($session, $written);
}
@fclose($srv);
