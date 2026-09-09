<?php
declare(strict_types=1);

/**
 * StarlinkSessionStore — Uganda's Starlink session, and nobody else's.
 *
 * Holds one account's cookie, encrypted, plus the state that decides whether
 * it is still usable: when it was last checked, how many attempts have failed
 * in a row, and whether it has died and needs a person to import a fresh one.
 *
 * ─── The isolation rule ──────────────────────────────────────────────────
 * The MECHANISM here is adapted from the South Sudan implementation, which
 * has run against the live portal for months. The DATA never crosses. This
 * store lives in Hybrid's own data directory, and Hybrid reads no file
 * belonging to the fleet or Finance plugins — not dr_accounts.json, not
 * sl_kits.json, not dr_kit_registry.json. A test asserts that, because the
 * day two countries share one session is the day one account's activity is
 * attributed to the other.
 *
 * The encryption key derives from this plugin's own directory, so Uganda's
 * key differs from Sudan's by construction rather than by discipline.
 *
 * ─── What is never stored ────────────────────────────────────────────────
 * A Starlink password. There is none to store: a person signs in through a
 * browser and imports the resulting session cookie. The worst this file can
 * leak is a session that can be revoked by signing out.
 */
class StarlinkSessionStore
{
    const STATE_ACTIVE   = 'active';
    const STATE_STALE    = 'stale';      // refresh failing, not yet given up
    const STATE_DEAD     = 'dead';       // a person must import a new cookie
    const STATE_ABSENT   = 'absent';     // nothing imported yet

    /** Give up asking the refresh endpoints after this many consecutive failures. */
    const MAX_FAILURES = 5;

    /** @var string */ private $pluginRoot;
    /** @var string */ private $dataDir;
    /** @var string */ private $file;

    public function __construct(string $pluginRoot, string $dataDir)
    {
        $this->pluginRoot = rtrim($pluginRoot, '/');
        $this->dataDir    = rtrim($dataDir, '/');
        $this->file       = $this->dataDir . '/starlink_session.json';
    }

    public function path(): string { return $this->file; }

    /**
     * The encryption key.
     *
     * Adapted from smDeriveKey(): uCRM's own secret as base material where it
     * can be read, plus a salt generated once and kept beside the data. The
     * plugin directory is part of the derivation, which is what makes this
     * key different from the fleet plugin's without anyone having to remember
     * to make it different.
     */
    public function deriveKey(): string
    {
        $ucrmSecret = '';
        foreach ([dirname($this->pluginRoot, 2) . '/data/config.json',
                  $this->pluginRoot . '/../../data/config.json'] as $p) {
            if (!is_file($p)) continue;
            $cfg = json_decode((string)@file_get_contents($p), true);
            if (is_array($cfg)) {
                $ucrmSecret = (string)($cfg['parameters']['secret'] ?? $cfg['secret'] ?? '');
                if ($ucrmSecret !== '') break;
            }
        }

        $saltFile = $this->dataDir . '/.starlink_salt';
        if (!is_file($saltFile)) {
            if (!is_dir($this->dataDir)) @mkdir($this->dataDir, 0755, true);
            require_once __DIR__ . '/SecureFile.php';
            SecureFile::write($saltFile, bin2hex(random_bytes(32)));
        }
        $salt = trim((string)@file_get_contents($saltFile));

        return hash('sha256', $ucrmSecret . '|' . $salt . '|' . $this->pluginRoot, true);
    }

    public function encrypt(string $plain): string
    {
        $iv  = random_bytes(16);
        $enc = openssl_encrypt($plain, 'AES-256-CBC', $this->deriveKey(), OPENSSL_RAW_DATA, $iv);
        return base64_encode($iv . (string)$enc);
    }

    public function decrypt(string $cipher): string
    {
        $raw = base64_decode($cipher, true);
        if ($raw === false || strlen($raw) <= 16) return '';
        $iv   = substr($raw, 0, 16);
        $body = substr($raw, 16);
        $out  = openssl_decrypt($body, 'AES-256-CBC', $this->deriveKey(), OPENSSL_RAW_DATA, $iv);
        return $out === false ? '' : $out;
    }

    /** @return array the stored record, or the empty shape */
    public function load(): array
    {
        $empty = [
            'account_email'   => '', 'account_number' => '',
            'cookie_enc'      => '', 'imported_at'    => '', 'imported_by' => '',
            'last_ok_at'      => '', 'last_checked_at' => '',
            'consecutive_failures' => 0, 'state' => self::STATE_ABSENT,
            'last_error'      => '', 'throttled_until' => '',
        ];
        if (!is_file($this->file)) return $empty;
        $d = json_decode((string)@file_get_contents($this->file), true);
        return is_array($d) ? array_merge($empty, $d) : $empty;
    }

    /** Write through SecureFile: 0640, owned by whoever owns the data dir. */
    public function save(array $record): bool
    {
        require_once __DIR__ . '/SecureFile.php';
        $r = SecureFile::write($this->file,
            (string)json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        return !empty($r['ok']);
    }

    /** The cookie in the clear, for the connector only. */
    public function cookie(): string
    {
        $rec = $this->load();
        return $rec['cookie_enc'] !== '' ? $this->decrypt((string)$rec['cookie_enc']) : '';
    }

    /**
     * A person imports a session harvested from their browser.
     *
     * Importing always clears the failure counters and the dead state: the
     * whole point of a fresh cookie is that the old verdict no longer applies.
     */
    public function importCookie(string $cookie, string $who, string $accountEmail = '',
                                 string $accountNumber = ''): array
    {
        $cookie = trim($cookie);
        if ($cookie === '') return ['ok' => false, 'error' => 'nothing to import'];
        if (strpos($cookie, '=') === false) {
            return ['ok' => false, 'error' => 'that does not look like a cookie string '
                                            . '(expected name=value; name=value)'];
        }

        $rec = $this->load();
        $rec['cookie_enc']  = $this->encrypt($cookie);
        $rec['imported_at'] = gmdate('Y-m-d H:i:s');
        $rec['imported_by'] = $who;
        $rec['state']       = self::STATE_ACTIVE;
        $rec['consecutive_failures'] = 0;
        $rec['last_error']  = '';
        if ($accountEmail  !== '') $rec['account_email']  = $accountEmail;
        if ($accountNumber !== '') $rec['account_number'] = $accountNumber;

        return $this->save($rec)
            ? ['ok' => true, 'error' => '', 'names' => self::cookieNames($cookie)]
            : ['ok' => false, 'error' => 'could not write the session store'];
    }

    /** Replace the cookie after a refresh handed us new values. */
    public function updateCookie(string $cookie): bool
    {
        $rec = $this->load();
        $rec['cookie_enc'] = $this->encrypt($cookie);
        $rec['state']      = self::STATE_ACTIVE;
        return $this->save($rec);
    }

    public function markOk(): void
    {
        $rec = $this->load();
        $rec['last_ok_at']      = gmdate('Y-m-d H:i:s');
        $rec['last_checked_at'] = $rec['last_ok_at'];
        $rec['consecutive_failures'] = 0;
        $rec['state']      = self::STATE_ACTIVE;
        $rec['last_error'] = '';
        $this->save($rec);
    }

    public function markFailure(string $why): void
    {
        $rec = $this->load();
        $rec['last_checked_at'] = gmdate('Y-m-d H:i:s');
        $rec['consecutive_failures'] = (int)$rec['consecutive_failures'] + 1;
        $rec['last_error'] = mb_substr($why, 0, 300);
        $rec['state'] = $rec['consecutive_failures'] >= self::MAX_FAILURES
            ? self::STATE_DEAD : self::STATE_STALE;
        $this->save($rec);
    }

    /** True when a person has to go and fetch a new cookie. */
    public function needsReimport(): bool
    {
        return (string)$this->load()['state'] === self::STATE_DEAD;
    }

    // ── Throttle, kept beside the session it belongs to ───────────────────

    public function isThrottled(): bool
    {
        $until = (string)$this->load()['throttled_until'];
        return $until !== '' && strtotime($until) > time();
    }

    public function throttledUntil(): string
    {
        return (string)$this->load()['throttled_until'];
    }

    /**
     * Back off after a 429 or 503.
     *
     * Doubling, capped at an hour. The cost of waiting is a late sync; the
     * cost of not waiting is a session Starlink stops trusting.
     */
    public function recordThrottle(): void
    {
        $rec  = $this->load();
        $fails = max(1, (int)$rec['consecutive_failures']);
        $wait  = min(3600, 60 * (2 ** min($fails, 6)));
        $rec['throttled_until'] = gmdate('Y-m-d H:i:s', time() + $wait);
        $this->save($rec);
    }

    public function clearThrottle(): void
    {
        $rec = $this->load();
        if ((string)$rec['throttled_until'] === '') return;
        $rec['throttled_until'] = '';
        $this->save($rec);
    }

    /** Everything an operator needs, with nothing they must not see. */
    public function status(): array
    {
        $rec = $this->load();
        $cookie = $this->cookie();
        return [
            'state'          => (string)$rec['state'],
            'account_email'  => (string)$rec['account_email'],
            'account_number' => (string)$rec['account_number'],
            'cookie_present' => $cookie !== '',
            'cookie_names'   => $cookie !== '' ? self::cookieNames($cookie) : [],
            'imported_at'    => (string)$rec['imported_at'],
            'imported_by'    => (string)$rec['imported_by'],
            'last_ok_at'     => (string)$rec['last_ok_at'],
            'last_checked_at'=> (string)$rec['last_checked_at'],
            'failures'       => (int)$rec['consecutive_failures'],
            'last_error'     => (string)$rec['last_error'],
            'throttled_until'=> (string)$rec['throttled_until'],
            'file'           => $this->file,
        ];
    }

    /**
     * Name → value LENGTH. Never a value.
     *
     * Long enough to diagnose the failure that is otherwise invisible: a
     * cookie pasted into a terminal is truncated at the canonical input
     * buffer, usually 4096 bytes, and a Starlink session is far longer than
     * that. The names all arrive, the last value is cut mid-JWT, and Starlink
     * answers 401 to a session that looks complete in every listing.
     *
     * @return array{names:array<string,int>, total:int, suspect_truncated:bool}
     */
    public function shape(): array
    {
        $cookie = $this->cookie();
        $names  = [];
        foreach (explode(';', $cookie) as $part) {
            $part = trim($part);
            if ($part === '') continue;
            $eq = strpos($part, '=');
            if ($eq === false) { $names[$part] = 0; continue; }
            $names[substr($part, 0, $eq)] = strlen(substr($part, $eq + 1));
        }
        $total = strlen($cookie);

        // 4096 is the usual terminal line limit; allow for the trailing
        // newline and the "cookie: " prefix a paste may include.
        return ['names' => $names, 'total' => $total,
                'suspect_truncated' => $total >= 4000 && $total <= 4110];
    }

    /**
     * The NAMES of the cookies held, never the values.
     *
     * Enough for an operator to see that a session looks complete; useless to
     * anyone reading over their shoulder.
     */
    public static function cookieNames(string $cookie): array
    {
        $out = [];
        foreach (explode(';', $cookie) as $part) {
            $part = trim($part);
            if ($part === '') continue;
            $eq = strpos($part, '=');
            $out[] = $eq === false ? $part : substr($part, 0, $eq);
        }
        return $out;
    }
}
