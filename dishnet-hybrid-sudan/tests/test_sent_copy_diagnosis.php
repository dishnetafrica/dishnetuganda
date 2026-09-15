<?php
declare(strict_types=1);
/**
 * test_sent_copy_diagnosis.php — the network and the certificate are
 * different faults, and SentCopy must say which one it hit.
 *
 * stream_socket_client reports a refused TCP connection and a failed TLS
 * handshake in the same words. SentCopy used to pass that on as "connect
 * failed on every route" and, when a route was configured, recommend
 * reattaching a docker network. On the live server the network was fine —
 * every TCP probe answered in a millisecond — and the mail server was
 * presenting an rcgen placeholder certificate. The advice sent the operator
 * the wrong way for an evening.
 *
 * A plain TCP probe after the failed handshake tells the two apart. These
 * tests stand up a listener that accepts TCP and then talks garbage, so the
 * handshake fails instantly and unambiguously, and check that the diagnosis
 * — and the tool's advice — follows the evidence.
 */
$pass = 0; $fail = 0;
function is_(bool $c, string $m): void { global $pass, $fail;
    if ($c) { $pass++; echo "  ok   $m\n"; } else { $fail++; echo "  FAIL $m\n"; } }
function t(string $n, $got, $want) { global $pass, $fail;
    if ($got === $want) { $pass++; printf("  ok   %s\n", $n); }
    else { $fail++; printf("  FAIL %s\n       got  %s\n       want %s\n", $n, var_export($got, true), var_export($want, true)); } }

$root = dirname(__DIR__);
require_once $root . '/lib/SentCopy.php';

// ── A listener that answers TCP and then ruins the handshake ────────────
// Accepts, writes a line that is not a TLS record, closes. OpenSSL fails
// with "wrong version number" on the first byte — no timeouts to wait out.
$server = <<<'SRV'
$s = stream_socket_server('tcp://127.0.0.1:0', $en, $es);
if (!$s) { fwrite(STDERR, "no listener: $es\n"); exit(1); }
echo parse_url(stream_socket_get_name($s, false), PHP_URL_PORT), "\n"; fflush(STDOUT);
while (true) {
    $c = @stream_socket_accept($s, 30);
    if ($c) { @fwrite($c, "HELLO NOT TLS\r\n"); @fclose($c); }
}
SRV;
$proc = proc_open('exec php -r ' . escapeshellarg($server),
                  [1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
$junkPort = (int)trim((string)fgets($pipes[1]));
is_($junkPort > 0, "a junk-TLS listener is up on 127.0.0.1:$junkPort");

// ── A port that is definitely closed ────────────────────────────────────
$tmpSock = stream_socket_server('tcp://127.0.0.1:0');
$closedPort = (int)parse_url(stream_socket_get_name($tmpSock, false), PHP_URL_PORT);
fclose($tmpSock);

$base = ['sent_copy_enabled' => true, 'sent_copy_user' => 'u@example.test',
         'sent_copy_pass' => 'p', 'sent_copy_folder' => 'Sent'];

// ════════════════════════════════════════════════════════════════════════
echo "\n1. The port answers, the handshake fails: that is the certificate\n";
// ════════════════════════════════════════════════════════════════════════
$r = SentCopy::append($base + ['sent_copy_host' => '127.0.0.1', 'sent_copy_port' => $junkPort], "x");
t('the copy was not filed',                      $r['ok'], false);
t('the route is classed as a TLS failure',       $r['tls_failed'], ['127.0.0.1']);
t('and not as unreachable',                      $r['unreachable'], []);
is_(stripos($r['error'], 'certificate') !== false,      'the error names the certificate');
is_(stripos($r['error'], 'not a network') !== false,    'and says plainly it is not the network');
is_(stripos($r['error'], 'docker network') === false || stripos($r['error'], 'Do not reattach') !== false,
    'it does not recommend reattaching a docker network');
is_(strpos((string)($r['tried'][0] ?? ''), 'port answers') !== false,
    'the per-route line says the port answered');

// ════════════════════════════════════════════════════════════════════════
echo "\n2. Nothing answers: that is the network\n";
// ════════════════════════════════════════════════════════════════════════
$r = SentCopy::append($base + ['sent_copy_host' => '127.0.0.1', 'sent_copy_port' => $closedPort], "x");
t('the copy was not filed',                      $r['ok'], false);
t('the route is classed as unreachable',         $r['unreachable'], ['127.0.0.1']);
t('and not as a TLS failure',                    $r['tls_failed'], []);
is_(stripos($r['error'], 'connect failed on every route') !== false, 'the error is the connectivity one');
is_(stripos($r['error'], 'certificate') === false, 'and it does not blame a certificate');

// ════════════════════════════════════════════════════════════════════════
echo "\n3. The live shape: a configured route that answers, a public name that does not\n";
// ════════════════════════════════════════════════════════════════════════
// sent_copy_hosts=<route> with a public sent_copy_host: SentCopy tries the
// route first with the certificate pinned to the public name, then the name.
// This is exactly what the server had — and exactly where the old message
// blamed the docker network.
$r = SentCopy::append($base + [
    'sent_copy_host'  => 'no-such-host.invalid',
    'sent_copy_hosts' => '127.0.0.1',
    'sent_copy_port'  => $junkPort,
], "x");
t('the configured route is a TLS failure',      $r['tls_failed'], ['127.0.0.1']);
t('the public name is unreachable',              $r['unreachable'], ['no-such-host.invalid']);
is_(stripos($r['error'], 'certificate') !== false, 'the diagnosis is the certificate, because a route answered');
is_(stripos($r['error'], 'valid for no-such-host.invalid') !== false,
    'and it says which name the certificate must be valid for');
is_(stripos($r['error'], 'docker network connect') === false,
    'the docker-network advice is NOT given — the misdiagnosis that cost an evening');

// ════════════════════════════════════════════════════════════════════════
echo "\n4. Both unreachable with a configured route: the network advice stays\n";
// ════════════════════════════════════════════════════════════════════════
$r = SentCopy::append($base + [
    'sent_copy_host'  => 'no-such-host.invalid',
    'sent_copy_hosts' => '127.0.0.1',
    'sent_copy_port'  => $closedPort,
], "x");
t('nothing answered',                            $r['tls_failed'], []);
t('both routes unreachable',                     $r['unreachable'], ['127.0.0.1', 'no-such-host.invalid']);
is_(stripos($r['error'], 'docker network connect') !== false,
    'so the docker-network advice is given — it is right in this case');

// ════════════════════════════════════════════════════════════════════════
echo "\n5. The tool gives the advice the diagnosis supports\n";
// ════════════════════════════════════════════════════════════════════════
$tool = (string)file_get_contents($root . '/tools/set_sent_copy.php');
$tlsAt = strpos($tool, "if (!empty(\$r['tls_failed']))");
$netAt = strpos($tool, "elseif (!empty(\$r['tried']))");
is_($tlsAt !== false, 'the tool checks for a TLS failure');
is_($netAt !== false && $tlsAt !== false && $tlsAt < $netAt,
    'and checks it BEFORE falling back to the network advice');
is_(strpos($tool, 'rcgen self signed cert') !== false,
    'it tells the operator what a placeholder certificate looks like');

proc_terminate($proc); proc_close($proc);
printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
