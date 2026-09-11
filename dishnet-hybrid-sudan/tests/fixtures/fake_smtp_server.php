<?php
/**
 * fake_smtp_server.php — a relay that accepts everything and remembers it.
 *
 *   php tests/fixtures/fake_smtp_server.php <port> <transcript.json>
 *
 * The envelope sender is the one part of an email no test could see before
 * this existed. It is not in the message — it is a line of the SMTP
 * conversation, spoken and then discarded — which is exactly why a header
 * saying no-reply@ over an envelope still saying accounts@ went unnoticed:
 * every assertion available was looking at the message.
 *
 * So this is the relay's side of the conversation. It writes down what it
 * was actually told: MAIL FROM, RCPT TO, EHLO, and the message. One
 * connection at a time, appended to the transcript as a JSON array.
 *
 * Plaintext only, no AUTH offered, accepts every sender. A real relay
 * refusing a sender is a different test; this one answers "what did the
 * plugin actually say".
 */
declare(strict_types=1);

$port = (int)($argv[1] ?? 0);
$file = (string)($argv[2] ?? '');
if ($port <= 0 || $file === '') { fwrite(STDERR, "usage: fake_smtp_server.php <port> <transcript>\n"); exit(1); }

$srv = @stream_socket_server("tcp://127.0.0.1:{$port}", $errno, $errstr);
if (!$srv) { fwrite(STDERR, "listen failed: {$errstr}\n"); exit(1); }

$record = function (array $session) use ($file): void {
    $all = is_file($file) ? (json_decode((string)@file_get_contents($file), true) ?: []) : [];
    $all[] = $session;
    @file_put_contents($file, json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
};

// Written only once a socket is actually listening, so a client that finds
// this file knows the port is up. Racing on "connect and retry" instead made
// the suite flaky on a loaded machine.
@file_put_contents($file, json_encode([], JSON_PRETTY_PRINT));

while (true) {
    $c = @stream_socket_accept($srv, 30);
    if (!$c) break;   // 30s idle: the test is over, or it died. Either way, stop.
    stream_set_timeout($c, 10);

    $session = ['ehlo' => '', 'mail_from' => '', 'rcpt_to' => [], 'data' => '', 'commands' => []];
    fwrite($c, "220 fake.smtp.test ESMTP ready\r\n");

    $inData = false; $body = '';
    while (($line = fgets($c, 4096)) !== false) {
        if ($inData) {
            if (rtrim($line, "\r\n") === '.') {
                $inData = false;
                // Undo the dot-stuffing the sender applied, so the transcript
                // holds the message as it was composed.
                $session['data'] = preg_replace('/^\.\./m', '.', $body) ?? $body;
                $body = '';
                fwrite($c, "250 2.0.0 Ok: queued as FAKE\r\n");
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
            // No AUTH advertised and no STARTTLS: a client configured for
            // enc='' and no credentials goes straight to MAIL FROM.
            fwrite($c, "250-fake.smtp.test\r\n250 8BITMIME\r\n");
        } elseif ($verb === 'MAIL') {
            $session['mail_from'] = preg_match('/<(.*)>/', $cmd, $m) ? $m[1] : trim(substr($cmd, 10));
            fwrite($c, "250 2.1.0 Ok\r\n");
        } elseif ($verb === 'RCPT') {
            $session['rcpt_to'][] = preg_match('/<(.*)>/', $cmd, $m) ? $m[1] : trim(substr($cmd, 8));
            fwrite($c, "250 2.1.5 Ok\r\n");
        } elseif ($verb === 'DATA') {
            $inData = true;
            fwrite($c, "354 End data with <CR><LF>.<CR><LF>\r\n");
        } elseif ($verb === 'QUIT') {
            fwrite($c, "221 2.0.0 Bye\r\n");
            break;
        } elseif ($verb === 'RSET') {
            fwrite($c, "250 2.0.0 Ok\r\n");
        } else {
            fwrite($c, "502 5.5.2 Not implemented\r\n");
        }
    }

    @fclose($c);
    $record($session);
}

@fclose($srv);
