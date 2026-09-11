<?php
/**
 * MailService — single source of truth for outbound email.
 *
 * v4.21.8 — replaces ad-hoc SMTP config reading scattered across
 * api_customer_app.php (OTP email), DailyReportService.php (daily reports),
 * cron_overdue_email.php (dunning), and public.php (one-off sends).
 *
 * Why a single service:
 *
 *   1. UCRM already has working SMTP configured (it sends invoice emails
 *      to customers daily). We should reuse that, not require admin to
 *      configure plugin-level smtp_* keys redundantly.
 *
 *   2. When SMTP password changes, admin updates it in UCRM once — every
 *      caller of MailService picks it up automatically. Previously each
 *      caller cached its own copy.
 *
 *   3. When troubleshooting "why aren't emails arriving", there's exactly
 *      one place to look. The smtp_diagnostic admin tab calls MailService
 *      so the diagnostic results match what real sends do.
 *
 * Source priority (highest → lowest):
 *   - UCRM /api/v1.0/settings (the mailerHost/mailerPort/etc. set in
 *     UCRM Admin > System > Settings > Mailer)
 *
 * That's the only source. No plugin config keys, no env vars, no hard-coded
 * defaults. If UCRM SMTP isn't configured, send fails with a clear error
 * pointing admin to UCRM settings — rather than silently using an empty
 * config that times out later.
 */
declare(strict_types=1);

class MailService
{
    /** Set when the settings file is present but unreadable by this process. */
    public $unreadableReason = '';

    /** @var array Last-resolved config, cached for the request */
    /**
     * The name this server introduces itself with at EHLO.
     *
     * gethostname() inside a container returns its hex id — "1c8f5997cf51" —
     * which is not a domain, and a strict server refuses it outright:
     *
     *     550 5.5.0 Invalid EHLO domain.
     *
     * That is correct of the server and wrong of us. The sender's own domain
     * is the honest answer: mail from no-reply@dishnetuganda.com is announced
     * as dishnetuganda.com, which resolves and matches the envelope.
     *
     * smtp_ehlo overrides it when an operator needs something specific.
     * gethostname() is kept as the last resort, but only when it contains a
     * dot — a bare container id never reaches the wire again.
     */
    /**
     * The address system mail is sent FROM, as configured, or '' when unset.
     *
     * '' is the answer for every install that has never set one, and '' means
     * "use the ordinary sender" everywhere it is consulted. Nothing downstream
     * has to know whether the key is absent, blank, or nonsense.
     */
    public function systemFrom(): string
    {
        $cfg = $this->getConfig();
        return (string)($cfg['system_from'] ?? '');
    }

    /**
     * Accept a From value, or reject it to ''.
     *
     * Keeps any display name: '"DishNet" <a@b.c>' comes back whole, because
     * that is what belongs in the header. What is validated is the address
     * inside it — a From that is not a deliverable address is a bounced mail
     * or, worse, a silent relay refusal at MAIL FROM time.
     */
    public static function normalizeFrom(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') return '';
        // A newline here would inject headers into every message this sends.
        if (preg_match('/[\r\n]/', $raw)) return '';
        return filter_var(self::bareAddress($raw), FILTER_VALIDATE_EMAIL) ? $raw : '';
    }

    /**
     * The bare address out of a From value — what SMTP's MAIL FROM takes.
     * Display names are a header courtesy; the envelope has no room for them.
     */
    public static function bareAddress(string $from): string
    {
        return preg_match('/<(.+?)>/', $from, $m) ? trim($m[1]) : trim($from);
    }

    public static function ehloName(array $cfg): string
    {
        $explicit = trim((string)($cfg['ehlo'] ?? $cfg['smtp_ehlo'] ?? ''));
        if ($explicit !== '') return $explicit;

        foreach (['from', 'user'] as $k) {
            $addr = trim((string)($cfg[$k] ?? ''));
            $at   = strrpos($addr, '@');
            if ($at === false) continue;
            $dom = trim(substr($addr, $at + 1));
            if ($dom !== '' && strpos($dom, '.') !== false) return $dom;
        }

        $hn = (string)gethostname();
        return (strpos($hn, '.') !== false) ? $hn : 'localhost';
    }

    private $cfg = null;
    private $cfgError = '';
    private $dataDir;

    /**
     * @param string $dataDir Plugin data dir (used to find ucrm.json one
     *                        or two levels up). Convention matches existing
     *                        code in DailyReportService and cron_overdue_email.
     */
    public function __construct(string $dataDir)
    {
        $this->dataDir = $dataDir;
    }

    /**
     * Returns ['host'=>, 'port'=>, 'user'=>, 'pass'=>, 'enc'=>, 'from'=>].
     * Cached per instance. On miss, queries UCRM API /settings.
     *
     * Returns empty array on failure; lastError() has the reason.
     */
    public function getConfig(): array
    {
        if ($this->cfg !== null) return $this->cfg;

        // v4.21.13: Honor use_ucrm_email toggle from email_settings.json.
        // Mirrors Starlink Finance flow:
        //   - toggle ON  → try UCRM API first, fall back to plugin SMTP
        //   - toggle OFF → use plugin SMTP directly
        $emailFile = $this->dataDir . '/email_settings.json';
        // The file existing but being unreadable is a completely different
        // problem from it being absent, and reported as "not configured" the
        // two are indistinguishable. That is what happened: written 0600 by
        // root, invisible to the web process, and the webhook said the mailer
        // was unconfigured while the CLI read it fine.
        if (is_file($emailFile) && !is_readable($emailFile)) {
            require_once __DIR__ . '/SecureFile.php';
            $this->unreadableReason = 'email_settings.json exists but this process cannot read it ('
                . SecureFile::auditReadability($emailFile)['why'] . ')';
            error_log('[MailService] ' . $this->unreadableReason);
        }
        $ec = file_exists($emailFile)
            ? (json_decode((string)@file_get_contents($emailFile), true) ?: [])
            : [];
        $useUcrm = !empty($ec['use_ucrm_email']);

        // v4.24: the address system mail (OTP codes, password resets) is sent
        // FROM. Distinct from 'from', which is the human mailbox staff reply
        // to. A customer must not be able to reply to an OTP, and an OTP must
        // not land in the sales inbox. Empty — Sudan's case — means every mail
        // keeps going out as 'from', exactly as before this key existed.
        $systemFrom = self::normalizeFrom((string)($ec['system_from'] ?? ''));

        // Build the plugin-SMTP config (if filled in) — used as primary or fallback
        $pluginCfg = null;
        if (!empty(trim($ec['smtp_host'] ?? ''))) {
            $pluginCfg = [
                'host' => trim($ec['smtp_host'] ?? ''),
                'port' => (int)($ec['smtp_port'] ?? 587) ?: 587,
                'user' => trim($ec['smtp_user'] ?? ''),
                'pass' => trim($ec['smtp_pass'] ?? ''),
                'enc'  => trim($ec['smtp_enc']  ?? 'tls'),
                'from' => trim($ec['smtp_from'] ?? '') ?: trim($ec['smtp_user'] ?? ''),
                'system_from' => $systemFrom,
                '_source' => 'plugin',
            ];
        }

        // Path A: toggle ON → try UCRM API first
        if ($useUcrm) {
            $ucrmCfg = $this->tryReadUcrmMailerSettings();
            if ($ucrmCfg !== null) {
                // UCRM API responded with mailer settings — use them.
                // system_from is ours, not UCRM's: carry it across, or an
                // install with the toggle ON would lose the system sender.
                $ucrmCfg['system_from'] = $systemFrom;
                $this->cfg = $ucrmCfg;
                return $ucrmCfg;
            }
            // UCRM API failed → fall back to plugin SMTP if configured
            if ($pluginCfg !== null) {
                $pluginCfg['_source'] = 'plugin (UCRM API fallback)';
                $this->cfg = $pluginCfg;
                return $pluginCfg;
            }
            // Neither worked
            $this->cfgError = 'UCRM API failed (' . ($this->cfgError ?: 'unknown') . ') AND no plugin SMTP fallback configured. Go to Settings → System → Email Settings.';
            $this->cfg = [];
            return [];
        }

        // Path B: toggle OFF → use plugin SMTP directly
        if ($pluginCfg !== null) {
            $this->cfg = $pluginCfg;
            return $pluginCfg;
        }

        $this->cfgError = 'No SMTP configured. Go to Settings → System → Email Settings and fill in the SMTP host/port/user/password.';
        $this->cfg = [];
        return [];
    }

    /**
     * Attempt to read SMTP settings from UCRM /api/v1.0/settings.
     * Returns config array on success, null on failure (sets cfgError).
     * Tries multiple URL patterns to handle different UCRM/UISP install variants.
     */
    private function tryReadUcrmMailerSettings(): ?array
    {
        $ucrmFile = dirname($this->dataDir) . '/ucrm.json';
        if (!file_exists($ucrmFile)) $ucrmFile = dirname(dirname($this->dataDir)) . '/ucrm.json';
        if (!file_exists($ucrmFile)) {
            $this->cfgError = 'ucrm.json not found';
            return null;
        }

        $ucrm = json_decode((string)@file_get_contents($ucrmFile), true) ?: [];
        $localUrl  = trim($ucrm['ucrmLocalUrl']  ?? '');
        $publicUrl = trim($ucrm['ucrmPublicUrl'] ?? '');
        $appKey    = $ucrm['pluginAppKey'] ?? '';

        if (!$appKey || (!$localUrl && !$publicUrl)) {
            $this->cfgError = 'ucrm.json missing pluginAppKey or URLs';
            return null;
        }

        $candidates = [];
        if ($localUrl)  $candidates[] = rtrim($localUrl, '/')  . '/api/v1.0/settings';
        if ($publicUrl) $candidates[] = rtrim($publicUrl, '/') . '/../api/v1.0/settings';
        if ($publicUrl) $candidates[] = rtrim($publicUrl, '/') . '/api/v1.0/settings';

        $resp = null; $httpCode = 0; $curlErr = ''; $triedUrl = '';
        foreach ($candidates as $url) {
            $triedUrl = $url;
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 10,
                CURLOPT_SSL_VERIFYPEER => (getenv('UCRM_SKIP_SSL_VERIFY') === '1' ? false : true),
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_HTTPHEADER     => ['X-Auth-App-Key: ' . $appKey],
            ]);
            $resp = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlErr = curl_error($ch);
            curl_close($ch);
            if ($httpCode === 200) break;
        }

        if ($curlErr) {
            $this->cfgError = "UCRM /settings curl error: {$curlErr}";
            return null;
        }
        if ($httpCode !== 200) {
            $this->cfgError = "UCRM /settings returned HTTP {$httpCode} (last URL: {$triedUrl})";
            return null;
        }

        $settings = json_decode((string)$resp, true);
        if (!is_array($settings)) {
            $this->cfgError = 'UCRM /settings returned non-JSON';
            return null;
        }

        // Normalize: UCRM returns either {key:value} or [{key,value}] depending on version
        $kv = [];
        if (isset($settings[0]) && is_array($settings[0]) && array_key_exists('key', $settings[0])) {
            foreach ($settings as $s) {
                if (isset($s['key'])) $kv[$s['key']] = $s['value'] ?? '';
            }
        } else {
            $kv = $settings;
        }

        $cfg = [
            'host' => trim((string)($kv['mailerHost'] ?? $kv['MAILER_HOST'] ?? '')),
            'port' => (int)($kv['mailerPort'] ?? $kv['MAILER_PORT'] ?? 587) ?: 587,
            'user' => trim((string)($kv['mailerUsername'] ?? $kv['MAILER_USERNAME'] ?? '')),
            'pass' => trim((string)($kv['mailerPassword'] ?? $kv['MAILER_PASSWORD'] ?? '')),
            'enc'  => trim((string)($kv['mailerEncryption'] ?? $kv['MAILER_ENCRYPTION'] ?? 'tls')),
            'from' => trim((string)($kv['mailerSenderAddress'] ?? $kv['MAILER_SENDER_ADDRESS'] ?? '')),
            '_source' => 'ucrm',
        ];
        if ($cfg['from'] === '') $cfg['from'] = $cfg['user'];

        if ($cfg['host'] === '') {
            $this->cfgError = 'UCRM /settings returned 200 but no mailerHost set';
            return null;
        }

        return $cfg;
    }

    public function lastError(): string
    {
        return $this->cfgError;
    }

    /**
     * Send a multipart (HTML + plain-text) email.
     *
     * @param string $toEmail   Recipient email (validated by caller).
     * @param string $toName    Display name for the To: header (optional).
     * @param string $subject   Subject line (raw, no encoding needed for ASCII).
     * @param string $htmlBody  Full HTML body (just the body content; we
     *                          wrap with proper email-safe doctype).
     * @param string $textBody  Plain-text fallback for clients that don't
     *                          render HTML. Required — if empty, we strip
     *                          tags from $htmlBody.
     * @param array  $extraHeaders  Optional extra MIME headers (e.g.
     *                              ['Reply-To' => 'support@dishnetafrica.com']).
     * @param array  $attachments   Optional files to attach, each entry
     *                              ['name' => 'Quotation-PF001.pdf',
     *                               'mime' => 'application/pdf',
     *                               'content' => raw bytes].
     *
     * @param string|null $fromOverride  Send as this address instead of the
     *   configured sender — header AND envelope, so the two never disagree.
     *   Pass systemFrom() for mail a customer must not reply to. Null, or an
     *   address that does not validate, sends as the configured sender.
     *
     * @return array{ok:bool, error?:string, log?:array}
     *   On success: ['ok' => true, 'log' => [...steps...]]
     *   On failure: ['ok' => false, 'error' => 'human readable', 'log' => [...]]
     */
    public function send(string $toEmail, string $toName, string $subject,
                         string $htmlBody, string $textBody = '',
                         array $extraHeaders = [], array $attachments = [],
                         ?string $fromOverride = null): array
    {
        $log = [];
        $cfg = $this->getConfig();
        if (empty($cfg) || $cfg['host'] === '') {
            return ['ok' => false, 'error' => $this->cfgError ?: 'No SMTP configured', 'log' => [
                ['step' => 'config', 'ok' => false, 'msg' => $this->cfgError]
            ]];
        }
        if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'error' => 'Invalid recipient email', 'log' => [
                ['step' => 'validate', 'ok' => false, 'msg' => 'Bad email format']
            ]];
        }

        if ($textBody === '') {
            $textBody = trim(html_entity_decode(strip_tags(
                preg_replace('/<br\s*\/?>/i', "\n", $htmlBody) ?? ''
            ), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }

        // Who this one message is from. Defaults to the configured sender, so
        // a caller that passes nothing sends exactly what it always sent.
        $sender = $cfg['from'];
        if ($fromOverride !== null && trim($fromOverride) !== '') {
            $accepted = self::normalizeFrom($fromOverride);
            if ($accepted !== '') {
                $sender = $accepted;
                $log[] = ['step' => 'from_override', 'ok' => true,
                          'msg' => 'sending as ' . self::bareAddress($sender)];
            } else {
                // Refusing it is the safe half; saying so is the other half.
                // A silent fallback here is a support ticket six weeks later
                // asking why the OTPs still come from the sales mailbox.
                $log[] = ['step' => 'from_override', 'ok' => false,
                          'msg' => 'not a valid sender address, using '
                                   . self::bareAddress($cfg['from']) . ' instead'];
            }
        }

        $fromHeader = $sender;
        // If sender doesn't already have a display name, add "DishNet Africa"
        if (strpos($fromHeader, '<') === false) {
            $fromHeader = 'DishNet Africa <' . $sender . '>';
        }
        $toHeader = $toName !== ''
            ? sprintf('"%s" <%s>', addslashes($toName), $toEmail)
            : $toEmail;

        [$headerStr, $mimeBody] = $this->composeMime(
            $fromHeader, $toHeader, $subject, $htmlBody, $textBody, $extraHeaders, $attachments
        );
        $rawMessage = $headerStr . "\r\n" . $mimeBody;

        // Open SMTP
        $errno = 0; $errstr = '';
        $transport = ($cfg['enc'] === 'ssl') ? 'ssl://' . $cfg['host'] : $cfg['host'];
        $started = microtime(true);
        $fp = @fsockopen($transport, $cfg['port'], $errno, $errstr, 15);
        $connectMs = (int)((microtime(true) - $started) * 1000);
        if (!$fp) {
            $log[] = ['step' => 'tcp_connect', 'ok' => false, 'msg' => "fail in {$connectMs}ms: {$errstr} ({$errno})"];
            return ['ok' => false, 'error' => "TCP connect failed: {$errstr}", 'log' => $log];
        }
        $log[] = ['step' => 'tcp_connect', 'ok' => true, 'msg' => "connected in {$connectMs}ms"];

        stream_set_timeout($fp, 15);
        $read = function() use ($fp) {
            $r = '';
            while (($l = fgets($fp, 515)) !== false) {
                $r .= $l;
                if (strlen($l) >= 4 && substr($l, 3, 1) === ' ') break;
            }
            return rtrim($r);
        };
        $write = function($c) use ($fp) { fputs($fp, $c . "\r\n"); };

        $expect = function($code, $stepName) use (&$log, $read, $fp) {
            $resp = $read();
            $ok = (substr($resp, 0, 3) === $code);
            $log[] = ['step' => $stepName, 'ok' => $ok, 'msg' => $resp];
            if (!$ok) @fclose($fp);
            return $ok;
        };

        if (!$expect('220', 'greeting')) return ['ok' => false, 'error' => 'Server greeting failed', 'log' => $log];

        $hn = self::ehloName($cfg);
        $write("EHLO {$hn}");
        if (!$expect('250', 'ehlo')) return ['ok' => false, 'error' => 'EHLO rejected', 'log' => $log];

        if ($cfg['enc'] === 'tls') {
            $write('STARTTLS');
            if (!$expect('220', 'starttls')) return ['ok' => false, 'error' => 'STARTTLS rejected', 'log' => $log];
            if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                $log[] = ['step' => 'tls_handshake', 'ok' => false, 'msg' => 'crypto enable failed'];
                @fclose($fp);
                return ['ok' => false, 'error' => 'TLS handshake failed', 'log' => $log];
            }
            $log[] = ['step' => 'tls_handshake', 'ok' => true, 'msg' => 'TLS established'];
            $write("EHLO {$hn}"); $read();
        }

        if ($cfg['user'] !== '' && $cfg['pass'] !== '') {
            $write('AUTH LOGIN');
            if (!$expect('334', 'auth_init')) return ['ok' => false, 'error' => 'AUTH LOGIN rejected', 'log' => $log];
            $write(base64_encode($cfg['user']));
            if (!$expect('334', 'auth_user')) return ['ok' => false, 'error' => 'Username rejected', 'log' => $log];
            $write(base64_encode($cfg['pass']));
            if (!$expect('235', 'auth_pass')) return ['ok' => false, 'error' => 'Password rejected — check UCRM mailer credentials', 'log' => $log];
        }

        // MAIL FROM — the same sender the header claims. A header saying
        // no-reply@ over an envelope saying accounts@ is what puts replies
        // and bounces back in the mailbox the header promised they would not.
        $envelopeFrom = self::bareAddress($sender);
        $write("MAIL FROM:<{$envelopeFrom}>");
        if (!$expect('250', 'mail_from')) return ['ok' => false, 'error' => 'MAIL FROM rejected', 'log' => $log];

        $write("RCPT TO:<{$toEmail}>");
        if (!$expect('250', 'rcpt_to')) return ['ok' => false, 'error' => 'RCPT TO rejected — recipient invalid or relay denied', 'log' => $log];

        $write('DATA');
        if (!$expect('354', 'data_init')) return ['ok' => false, 'error' => 'DATA rejected', 'log' => $log];

        // Dot-stuff lines starting with .
        $dotStuffed = preg_replace('/^\./m', '..', $rawMessage);
        $write($dotStuffed . "\r\n.");
        if (!$expect('250', 'data_accept')) return ['ok' => false, 'error' => 'Message body rejected', 'log' => $log];

        $write('QUIT');
        @$read();
        @fclose($fp);

        $log[] = ['step' => 'sent', 'ok' => true, 'msg' => "Email queued at SMTP server for {$toEmail}"];

        // File a copy in the Sent folder. The relay delivers without the
        // message passing through our own mail server, so without this the
        // operator's Sent folder never shows what the platform sent. Bookkeeping
        // only: a failure here is logged and never fails the send, because the
        // customer already has the email.
        $sentCopy = $this->sentCopy($rawMessage);
        if ($sentCopy !== null) $log[] = $sentCopy;

        return ['ok' => true, 'log' => $log];
    }

    /**
     * @return array|null a log step, or null when sent-copy is not configured
     */
    private function sentCopy(string $rawMessage): ?array
    {
        try {
            $file = $this->dataDir . '/email_settings.json';
            $es = is_file($file)
                ? (json_decode((string)@file_get_contents($file), true) ?: []) : [];
            if (empty($es['sent_copy_enabled'])) return null;

            require_once __DIR__ . '/SentCopy.php';
            $r = SentCopy::append($es, $rawMessage);
            return ['step' => 'sent_copy', 'ok' => (bool)$r['ok'],
                    'msg'  => $r['ok']
                        ? 'filed in "' . $r['folder'] . '"'
                          . (!empty($r['via']) ? ' via ' . $r['via'] : '')
                        : (string)$r['error']];
        } catch (\Throwable $e) {
            return ['step' => 'sent_copy', 'ok' => false, 'msg' => $e->getMessage()];
        }
    }

    /**
     * RFC 2047 encode a header value that may contain non-ASCII.
     *
     * A raw UTF-8 subject line is not legal in a header and each mail client
     * guesses the charset differently: Outlook read our em-dash correctly,
     * Roundcube rendered it as "â€"". Encoded words remove the guess.
     *
     * Folded into short encoded words so no header line exceeds the 76-column
     * limit, split on character boundaries so multi-byte characters survive.
     */
    public static function encodeHeaderText(string $v): string
    {
        if ($v === '' || !preg_match('/[\x80-\xFF]/', $v)) return $v;
        $words = [];
        $len   = mb_strlen($v, 'UTF-8');
        for ($i = 0; $i < $len; $i += 15) {          // 15 chars ≈ 60 base64 columns
            $words[] = '=?UTF-8?B?' . base64_encode(mb_substr($v, $i, 15, 'UTF-8')) . '?=';
        }
        return implode("\r\n ", $words);
    }

    /**
     * Encode only the display-name part of an address header, leaving the
     * address itself — which must stay literal — untouched.
     */
    public static function encodeHeaderName(string $v): string
    {
        if ($v === '' || !preg_match('/[\x80-\xFF]/', $v)) return $v;
        if (preg_match('/^\s*"?(.*?)"?\s*(<[^>]+>)\s*$/', $v, $m) && $m[1] !== '') {
            return self::encodeHeaderText($m[1]) . ' ' . $m[2];
        }
        return self::encodeHeaderText($v);
    }

    /**
     * Assemble the full MIME message (header block + body) without touching
     * the network — the seam the tests exercise. With no attachments this
     * reproduces the historical multipart/alternative message; attachments
     * wrap that part in multipart/mixed with each file base64-encoded.
     *
     * @return array{0:string,1:string} [header block ending in CRLF, body]
     */
    public function composeMime(string $fromHeader, string $toHeader, string $subject,
                                string $htmlBody, string $textBody,
                                array $extraHeaders = [], array $attachments = []): array
    {
        $altBoundary = '=_DishNet_' . bin2hex(random_bytes(8));

        $altBody =
            "--{$altBoundary}\r\n"
          . "Content-Type: text/plain; charset=UTF-8\r\n"
          . "Content-Transfer-Encoding: 8bit\r\n\r\n"
          . $textBody . "\r\n\r\n"
          . "--{$altBoundary}\r\n"
          . "Content-Type: text/html; charset=UTF-8\r\n"
          . "Content-Transfer-Encoding: 8bit\r\n\r\n"
          . $htmlBody . "\r\n\r\n"
          . "--{$altBoundary}--\r\n";

        if ($attachments) {
            $mixBoundary = '=_DishNetMix_' . bin2hex(random_bytes(8));
            $contentType = 'multipart/mixed; boundary="' . $mixBoundary . '"';
            $body =
                "--{$mixBoundary}\r\n"
              . "Content-Type: multipart/alternative; boundary=\"{$altBoundary}\"\r\n\r\n"
              . $altBody . "\r\n";
            foreach ($attachments as $a) {
                // Filenames go into a quoted header — keep them boring.
                $name = preg_replace('/[^A-Za-z0-9 ._()\-]/', '_', (string)($a['name'] ?? 'attachment'));
                if ($name === '' || $name === false) $name = 'attachment';
                $mime = (string)($a['mime'] ?? 'application/octet-stream');
                $body .=
                    "--{$mixBoundary}\r\n"
                  . "Content-Type: {$mime}; name=\"{$name}\"\r\n"
                  . "Content-Transfer-Encoding: base64\r\n"
                  . "Content-Disposition: attachment; filename=\"{$name}\"\r\n\r\n"
                  . chunk_split(base64_encode((string)($a['content'] ?? '')), 76, "\r\n")
                  . "\r\n";
            }
            $body .= "--{$mixBoundary}--\r\n";
        } else {
            $contentType = 'multipart/alternative; boundary="' . $altBoundary . '"';
            $body = $altBody;
        }

        $headers = [
            'From' => self::encodeHeaderName($fromHeader),
            'To' => self::encodeHeaderName($toHeader),
            'Subject' => self::encodeHeaderText($subject),
            'MIME-Version' => '1.0',
            'Content-Type' => $contentType,
            'Date' => date('r'),
            'Message-ID' => '<dn_' . bin2hex(random_bytes(8)) . '@' . (gethostname() ?: 'dishnetafrica.com') . '>',
            'X-Mailer' => 'DishNet-Hybrid/4.21.8',
        ];
        foreach ($extraHeaders as $k => $v) $headers[$k] = $v;

        $headerStr = '';
        foreach ($headers as $k => $v) $headerStr .= "{$k}: {$v}\r\n";

        return [$headerStr, $body];
    }
}
