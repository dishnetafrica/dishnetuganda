<?php
declare(strict_types=1);
/**
 * fake_evo_raw.php — TEST ONLY (5.18.54, docs/46 rows 30 and 31). A socket-level stand-in for Evolution.
 *
 *   php tests/fixtures/fake_evo_raw.php <port> <transcript.json> <mode[,mode...]> [idle-seconds]
 *
 * It answers one connection at a time, each as the next mode in the list says (the last one repeats), and appends
 * every connection to the transcript: its number, its mode, whether an HTTP request arrived, and the request's method,
 * path and body. Nothing leaves the machine.
 *
 * `php -S` cannot do what these tests need: take a request and never answer, close without a word, or speak plain text
 * to a client that expects TLS. Those are the ways a send fails after the request has left, or before.
 *
 *   ok       201, a message id — Evolution took it
 *   refuse   400 with a JSON reason — Evolution answered and did not take it
 *   hang     read the request, answer nothing for 3 s, close: the request left, and no answer came back
 *   close    read the request, close at once: the same, sooner
 *   tls      a TLS client gets plain text, so its handshake fails before any request is sent
 *   text500  500 with a plain-text body
 */
$port  = (int)($argv[1] ?? 0);
$file  = (string)($argv[2] ?? '');
$modes = array_values(array_filter(explode(',', (string)($argv[3] ?? 'ok'))));
$idle  = max(1, (int)($argv[4] ?? 30));
if ($port <= 0 || $file === '' || !$modes) { fwrite(STDERR, "usage: fake_evo_raw.php <port> <transcript> <modes> [idle]\n"); exit(1); }

$srv = @stream_socket_server("tcp://127.0.0.1:{$port}", $errno, $errstr);
if (!$srv) { fwrite(STDERR, "cannot listen on {$port}: {$errstr}\n"); exit(1); }
file_put_contents($file, json_encode([]), LOCK_EX);

$record = function (array $entry) use ($file): void {
    $all = json_decode((string)@file_get_contents($file), true) ?: [];
    $all[] = $entry;
    file_put_contents($file, json_encode($all, JSON_UNESCAPED_SLASHES), LOCK_EX);
};
$answer = function ($c, int $code, string $reason, string $type, string $body): void {
    @fwrite($c, "HTTP/1.1 {$code} {$reason}\r\nContent-Type: {$type}\r\nContent-Length: " . strlen($body)
               . "\r\nConnection: close\r\n\r\n" . $body);
};

for ($n = 1; ; $n++) {
    $c = @stream_socket_accept($srv, $idle);
    if (!$c) break;                               // idle that long: the test is over, or it died
    stream_set_timeout($c, 5);
    $mode = $modes[min($n - 1, count($modes) - 1)];

    if ($mode === 'tls') {
        @fread($c, 1024);                          // the client's hello, whatever it holds
        $record(['n' => $n, 'mode' => $mode, 'http' => false]);
        @fwrite($c, "HTTP/1.1 400 Bad Request\r\nConnection: close\r\n\r\n");
        @fclose($c);
        continue;
    }

    // An HTTP request: the head, then as much body as it declares.
    $head = '';
    while (strpos($head, "\r\n\r\n") === false && ($chunk = fread($c, 1)) !== false && $chunk !== '') $head .= $chunk;
    $lines = explode("\r\n", $head);
    $first = explode(' ', (string)($lines[0] ?? ''));
    $len = 0;
    foreach ($lines as $l) if (stripos($l, 'content-length:') === 0) $len = (int)trim(substr($l, 15));
    $body = '';
    while (strlen($body) < $len && ($chunk = fread($c, $len - strlen($body))) !== false && $chunk !== '') $body .= $chunk;
    $record(['n' => $n, 'mode' => $mode, 'http' => ($first[0] ?? '') !== '',
             'method' => (string)($first[0] ?? ''), 'path' => (string)($first[1] ?? ''), 'body' => $body]);

    switch ($mode) {
        case 'ok':
            $answer($c, 201, 'Created', 'application/json',
                    json_encode(['key' => ['id' => 'FAKE-RAW-' . $n], 'status' => 'PENDING']));
            break;
        case 'refuse':
            $answer($c, 400, 'Bad Request', 'application/json',
                    json_encode(['message' => 'FAKE-RAW refused: the instance is not connected']));
            break;
        case 'hang':
            sleep(3);
            break;
        case 'close':
            break;
        case 'text500':
            $answer($c, 500, 'Internal Server Error', 'text/plain', 'upstream error (FAKE-RAW)');
            break;
    }
    @fclose($c);
}
@fclose($srv);
