<?php
declare(strict_types=1);

/**
 * SentCopy — file a copy of an outgoing message into the Sent folder.
 *
 * WHY: the platform sends through a relay (Brevo), which delivers to the
 * recipient without the message ever passing through our own mail server. The
 * relay has a log, but the operator's Sent folder — the place a human actually
 * looks — stays empty. Mail clients solve this by appending the sent message to
 * the mailbox over IMAP; this does the same for the platform's automated mail.
 *
 * Config (email_settings.json, alongside the SMTP keys):
 *   sent_copy_enabled  true
 *   sent_copy_host     mail.dishnetuganda.com
 *   sent_copy_port     993
 *   sent_copy_user     accounts@dishnetuganda.com
 *   sent_copy_pass     the mailbox password
 *   sent_copy_folder   Sent            (optional; INBOX.Sent is tried too)
 *
 * Unconfigured means disabled — no behaviour change anywhere.
 *
 * NEVER THROWS. A copy that cannot be filed must never fail a customer send:
 * the customer already has the email; our archive is the lesser concern.
 *
 * PHP 7.4 compatible, raw IMAP over TLS — no ext-imap required.
 */
class SentCopy
{
    /**
     * Addresses worth trying when the mail server's public name is
     * unreachable from inside a container.
     *
     * The container's own default gateway is the host, and the mail ports are
     * published there. /proc/net/route holds it in little-endian hex, which is
     * the only place a container can learn it without extra tooling.
     *
     * An operator can override the whole guessing game with sent_copy_hosts,
     * a comma-separated list tried in order.
     */
    /** Routes the operator configured explicitly, in the order given. */
    private static function manualRoutes(array $settings): array
    {
        $raw = trim((string)($settings['sent_copy_hosts'] ?? ''));
        if ($raw === '') return [];
        return array_values(array_filter(array_map('trim', explode(',', $raw))));
    }

    private static function routes(array $settings): array
    {
        $manual = self::manualRoutes($settings);
        if ($manual) return $manual;
        $out = [];
        $raw = @file_get_contents('/proc/net/route');
        if (is_string($raw)) {
            foreach (explode("\n", $raw) as $i => $line) {
                if ($i === 0 || trim($line) === '') continue;
                $f = preg_split('/\s+/', trim($line));
                // Destination 00000000 marks the default route; field 2 is the
                // gateway, hex, least significant byte first.
                if (count($f) < 3 || $f[1] !== '00000000') continue;
                $hex = $f[2];
                if (!preg_match('/^[0-9A-Fa-f]{8}$/', $hex)) continue;
                $ip = implode('.', array_map('hexdec', array_reverse(str_split($hex, 2))));
                if ($ip !== '0.0.0.0') $out[] = $ip;
            }
        }
        // The default-bridge gateway, in case the routing table said nothing.
        $out[] = '172.17.0.1';
        return array_values(array_unique($out));
    }

    /** @return array{ok:bool,error:string,folder:string} */
    public static function append(array $settings, string $rawMessage): array
    {
        $out = ['ok' => false, 'error' => '', 'folder' => '', 'listed' => [], 'created' => '',
                'via' => '', 'tried' => []];
        if (empty($settings['sent_copy_enabled'])) {
            $out['error'] = 'disabled';
            return $out;
        }
        $host = trim((string)($settings['sent_copy_host'] ?? ''));
        $user = trim((string)($settings['sent_copy_user'] ?? ''));
        $pass = (string)($settings['sent_copy_pass'] ?? '');
        $port = (int)($settings['sent_copy_port'] ?? 993) ?: 993;
        if ($host === '' || $user === '' || $pass === '') {
            $out['error'] = 'sent-copy is enabled but host/user/password are incomplete';
            return $out;
        }
        // Folder candidates: what the operator configured, then the two
        // spellings every IMAP server in practice uses.
        $folders = array_values(array_unique(array_filter([
            trim((string)($settings['sent_copy_folder'] ?? '')), 'Sent', 'INBOX.Sent',
        ])));

        $fp = null;
        try {
            // The uCRM container often cannot reach the mail server by its
            // public name: the DNS answer is the host's own public address, and
            // a container connecting back to that address depends on NAT
            // hairpinning that many hosts do not do. The mail ports ARE
            // published on the docker bridge gateway, so try that next.
            //
            // The bridge attempts keep certificate verification honest by
            // pinning peer_name to the real hostname: we connect to an address
            // but still require the certificate to be the one issued for
            // mail.dishnetuganda.com. That is stricter than the name attempt,
            // not looser.
            $verify   = (bool)($settings['sent_copy_verify'] ?? false);
            // Order matters: this runs on EVERY outgoing email, so a route
            // known to fail must not be tried first each time.
            //
            // An explicitly configured route (sent_copy_hosts) is the
            // operator saying "this is how you reach it here", so it goes
            // first and the public name becomes the fallback. Without one,
            // the public name leads and the discovered gateway follows.
            //
            // Do not GUESS the bridge address: 172.17.0.1 is the gateway for
            // containers on the DEFAULT bridge only, and a container on a
            // compose network has a different one.
            $manual   = self::manualRoutes($settings);
            $attempts = [];
            if ($manual && filter_var($host, FILTER_VALIDATE_IP) === false) {
                foreach ($manual as $alt) $attempts[] = [$alt, $host];
                $attempts[] = [$host, null];
            } else {
                $attempts[] = [$host, null];
                if (filter_var($host, FILTER_VALIDATE_IP) === false) {
                    foreach (self::routes($settings) as $alt) $attempts[] = [$alt, $host];
                }
            }

            $fp = null; $tried = [];
            foreach ($attempts as [$connectHost, $certName]) {
                $sslOpts = [
                    'verify_peer'       => $certName !== null ? true : $verify,
                    'verify_peer_name'  => $certName !== null ? true : $verify,
                    'allow_self_signed' => $certName === null,
                ];
                if ($certName !== null) $sslOpts['peer_name'] = $certName;
                $ctx = stream_context_create(['ssl' => $sslOpts]);

                // stream_socket_client reports a failed TLS handshake in a
                // WARNING, not in $errstr — which is exactly what the previous
                // "@" suppressed, leaving "connect failed: " with no reason.
                $warn = '';
                set_error_handler(function ($no, $str) use (&$warn) { $warn = $str; return true; });
                $errno = 0; $errstr = '';
                $fp = stream_socket_client("ssl://{$connectHost}:{$port}", $errno, $errstr, 8,
                                           STREAM_CLIENT_CONNECT, $ctx);
                restore_error_handler();
                if ($fp) { $out['via'] = $connectHost; break; }

                $why = trim($errstr) !== '' ? trim($errstr) : '';
                if ($why === '' && $warn !== '') {
                    // Trim PHP's function-name prefix, keep the reason.
                    $why = trim(preg_replace('/^stream_socket_client\(\):\s*/', '', $warn));
                }
                if ($why === '') $why = $errno ? "errno {$errno}" : 'no reason reported';
                $ip  = filter_var($connectHost, FILTER_VALIDATE_IP) ? $connectHost
                     : (gethostbyname($connectHost) ?: '');
                $tried[] = $connectHost . ($ip && $ip !== $connectHost ? " ({$ip})" : '') . ': ' . $why;
            }
            if (!$fp) {
                $out['error'] = 'connect failed on every route — ' . implode('; ', $tried)
                    . ($manual ? '. A configured route stopped working: if the container was '
                               . 'recreated it will have lost the docker network it was attached to '
                               . '(docker network connect <mail network> ucrm).' : '');
                $out['tried'] = $tried;
                return $out;
            }
            stream_set_timeout($fp, 15);

            $readLine = function () use ($fp) { return (string)fgets($fp, 8192); };
            $greeting = $readLine();
            if (strpos($greeting, 'OK') === false) {
                $out['error'] = 'unexpected greeting: ' . trim($greeting);
                return $out;
            }

            // Read until the tagged response for $tag arrives.
            $readUntil = function (string $tag) use ($fp) {
                $buf = '';
                while (($l = fgets($fp, 8192)) !== false) {
                    $buf .= $l;
                    if (strncmp($l, $tag . ' ', strlen($tag) + 1) === 0) break;
                }
                return $buf;
            };
            $q = fn(string $s) => '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $s) . '"';

            fwrite($fp, 'a1 LOGIN ' . $q($user) . ' ' . $q($pass) . "\r\n");
            $resp = $readUntil('a1');
            if (strpos($resp, 'a1 OK') === false) {
                $out['error'] = 'login rejected';   // never echo the server's line: it can quote the password
                return $out;
            }

            // Normalise line endings: IMAP literals are counted in CRLF bytes.
            $msg = preg_replace("/\r\n|\r|\n/", "\r\n", $rawMessage);
            $len = strlen($msg);

            // Ask the server which folders exist, and prefer the one it marks
            // \Sent — that is authoritative and survives any naming scheme
            // (Sent, Sent Items, INBOX.Sent, localised names).
            fwrite($fp, "a2 LIST \"\" \"*\"\r\n");
            $listing = $readUntil('a2');
            $special = '';
            $exists  = [];
            foreach (preg_split('/\r?\n/', $listing) as $ln) {
                if (!preg_match('/^\* LIST \(([^)]*)\)\s+\S+\s+(.+)$/', trim($ln), $m2)) continue;
                $name = trim($m2[2], '"');
                $exists[] = $name;
                if (stripos($m2[1], '\\Sent') !== false) $special = $name;
            }
            if ($special !== '') array_unshift($folders, $special);
            $folders = array_values(array_unique($folders));
            $out['listed'] = $exists;

            $n = 1;
            $tryAppend = function (string $folder) use ($fp, $q, $msg, $len, &$n, $readLine, $readUntil) {
                $tag = 'b' . $n++;
                fwrite($fp, $tag . ' APPEND ' . $q($folder) . ' (\\Seen) {' . $len . "}\r\n");
                $cont = $readLine();
                if (strncmp(ltrim($cont), '+', 1) !== 0) {
                    $rest = strpos($cont, $tag) === false ? $readUntil($tag) : $cont;
                    return [false, trim($cont . ' ' . $rest)];
                }
                fwrite($fp, $msg . "\r\n");
                $resp = $readUntil($tag);
                return [strpos($resp, $tag . ' OK') !== false, trim($resp)];
            };

            foreach ($folders as $folder) {
                [$okA, $why] = $tryAppend($folder);
                if ($okA) { $out['ok'] = true; $out['folder'] = $folder; break; }

                // The mailbox is new and has no Sent folder yet — a mail client
                // would create it, so we do too, then append again.
                if (stripos($why, 'TRYCREATE') !== false || !in_array($folder, $exists, true)) {
                    $tag = 'c' . $n++;
                    fwrite($fp, $tag . ' CREATE ' . $q($folder) . "\r\n");
                    $cr = $readUntil($tag);
                    if (strpos($cr, $tag . ' OK') !== false) {
                        $out['created'] = $folder;
                        [$okA, $why] = $tryAppend($folder);
                        if ($okA) { $out['ok'] = true; $out['folder'] = $folder; break; }
                    }
                }
                $out['error'] = 'APPEND to "' . $folder . '" refused: ' . mb_substr($why, 0, 160);
            }
            if (!$out['ok'] && $out['error'] === '') {
                $out['error'] = 'no Sent folder accepted the message (tried: ' . implode(', ', $folders) . ')';
            }
            fwrite($fp, "z9 LOGOUT\r\n");
        } catch (\Throwable $e) {
            $out['error'] = $e->getMessage();
        } finally {
            if (is_resource($fp)) @fclose($fp);
        }
        return $out;
    }
}
