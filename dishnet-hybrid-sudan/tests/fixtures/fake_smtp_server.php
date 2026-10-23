<?php
/**
 * fake_smtp_server.php — a relay that accepts everything and remembers it.
 *
 *   php tests/fixtures/fake_smtp_server.php <port> <transcript.json> [idle-seconds]
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
 * plugin actually say". A client that sends AUTH LOGIN unasked, as the
 * dunning ladder's own sender does, is accepted with any credentials; the
 * user name is recorded, the password is not.
 */
declare(strict_types=1);

$port = (int)($argv[1] ?? 0);
$file = (string)($argv[2] ?? '');
$idle = max(1, (int)($argv[3] ?? 30));   // how long without a connection before it stops; a long test asks for more
if ($port <= 0 || $file === '') { fwrite(STDERR, "usage: fake_smtp_server.php <port> <transcript> [idle-seconds]\n"); exit(1); }

$srv = @stream_socket_server("tcp://127.0.0.1:{$port}", $errno, $errstr);
if (!$srv) { fwrite(STDERR, "listen failed: {$errstr}\n"); exit(1); }

// The transcript is replaced whole, never rewritten in place: written to a
// file beside it and renamed over it, which a reader sees as one step. A test
// reads it without a lock the moment the plugin returns, and an in-place write
// (truncate, then write) could hand that reader an empty or half-written file,
// which decodes to no messages at all: a test that counted the messages before
// a send then counted 0 (test_install_authorisation, H3, 20 Oct).
$write = function (array $all) use ($file): void {
    $tmp = $file . '.part';
    if (@file_put_contents($tmp, json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) !== false) @rename($tmp, $file);
};

// $replace: this connection was already written down (at the end of its
// message), so its entry, the last one, is replaced rather than added again.
// One connection at a time, so the last entry is always this connection's.
$record = function (array $session, bool $replace) use ($file, $write): void {
    $all = is_file($file) ? (json_decode((string)@file_get_contents($file), true) ?: []) : [];
    if ($replace && $all !== []) array_pop($all);
    $all[] = $session;
    $write($all);
};

// Written only once a socket is actually listening, so a client that finds
// this file knows the port is up. Racing on "connect and retry" instead made
// the suite flaky on a loaded machine.
$write([]);

while (true) {
    $c = @stream_socket_accept($srv, $idle);
    if (!$c) break;   // idle that long: the test is over, or it died. Either way, stop.
    stream_set_timeout($c, 10);

    $session = ['ehlo' => '', 'mail_from' => '', 'rcpt_to' => [], 'data' => '', 'commands' => []];
    fwrite($c, "220 fake.smtp.test ESMTP ready\r\n");

    $inData = false; $body = ''; $auth = ''; $written = false;
    while (($line = fgets($c, 4096)) !== false) {
        if ($auth !== '') {
            // The two lines that answer an AUTH LOGIN prompt: kept out of the commands, so no password is recorded.
            if ($auth === 'user') {
                $session['auth_user'] = (string)base64_decode(rtrim($line, "\r\n"), true);
                $auth = 'pass';
                fwrite($c, "334 UGFzc3dvcmQ6\r\n");
            } else {
                $auth = '';
                fwrite($c, "235 2.7.0 Authentication successful\r\n");
            }
            continue;
        }
        if ($inData) {
            if (rtrim($line, "\r\n") === '.') {
                $inData = false;
                // Undo the dot-stuffing the sender applied, so the transcript
                // holds the message as it was composed.
                $session['data'] = preg_replace('/^\.\./m', '.', $body) ?? $body;
                $body = '';
                // Written down BEFORE the reply: a sender that hears "queued" and
                // returns may be read at once, so the message must already be there.
                $record($session, $written); $written = true;
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
            // The whole conversation, QUIT included, is written down before the
            // goodbye, for the same reason: the sender returns once it hears it.
            $record($session, $written); $written = true;
            fwrite($c, "221 2.0.0 Bye\r\n");
            break;
        } elseif ($verb === 'RSET') {
            fwrite($c, "250 2.0.0 Ok\r\n");
        } elseif ($verb === 'AUTH') {
            // AUTH LOGIN, which the dunning ladder's own sender always sends (lib/OverdueDunningHelpers.php _rawSmtp):
            // any credentials are accepted. Never advertised, so a client that authenticates only when offered still
            // goes straight to MAIL FROM.
            $session['auth'] = strtoupper(trim(substr($cmd, 5)));
            if ($session['auth'] === 'LOGIN') { $auth = 'user'; fwrite($c, "334 VXNlcm5hbWU6\r\n"); }
            else fwrite($c, "504 5.5.4 Unrecognized authentication type\r\n");
        } else {
            fwrite($c, "502 5.5.2 Not implemented\r\n");
        }
    }

    @fclose($c);
    // The conversation as it ended: the same entry the QUIT wrote, or, for a
    // sender that hung up without one, everything it said. The transcript ends
    // up exactly as it always did; only WHEN each entry appears has changed.
    $record($session, $written);
}

@fclose($srv);
