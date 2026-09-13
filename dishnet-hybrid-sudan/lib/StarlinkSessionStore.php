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
    const STATE_EXPIRED  = 'expired';    // access token gone, SSO still alive
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

    // ── MANY ACCOUNTS, ONE ACTIVE ─────────────────────────────────────────
    //
    // Starlink lets one login switch between accounts, and DishNet Uganda has
    // several: the account a cookie was taken from turned out to have no
    // active kit, while the customer we have bound sits on another one. A store
    // that holds a single session can only ever see whichever account somebody
    // last happened to be looking at.
    //
    // So the file holds a map of accounts and a note of which is active, and
    // load()/save() address the active one. Every other method in this class
    // goes through those two, which is why none of them had to change: markOk,
    // markFailure, the throttle and the rest act on whichever account is
    // selected, exactly as they always acted on the only one there was.
    //
    // dishnet-data-report solved the same problem the same way — "per-account
    // session keep-alive", its sessions keyed by account in dr_accounts.json.
    // This is not a new idea, it is the one we were missing.

    /** A session that has never been imported. */
    public static function emptyRecord(): array
    {
        return [
            'account_email'   => '', 'account_number' => '',
            'cookie_enc'      => '', 'imported_at'    => '', 'imported_by' => '',
            'last_ok_at'      => '', 'last_checked_at' => '',
            'consecutive_failures' => 0, 'state' => self::STATE_ABSENT,
            'last_error'      => '', 'throttled_until' => '',
        ];
    }

    /** One spelling for an account key. Blank becomes a named placeholder. */
    public static function keyFor(string $account): string
    {
        $a = strtoupper(trim($account));
        return $a === '' ? self::KEY_UNKNOWN : $a;
    }

    /** Used when a cookie carries no account number to key itself by. */
    const KEY_UNKNOWN = 'UNKNOWN';

    /**
     * The whole file: every account, and which one is active.
     *
     * Reads the single-session shape too. A file written before this existed
     * is one account whose key is its own account_number — migrated on read,
     * never rewritten until something saves, so a rollback loses nothing.
     *
     * @return array{accounts:array<string,array>, active:string}
     */
    public function readAll(): array
    {
        if (!is_file($this->file)) return ['accounts' => [], 'active' => ''];
        $d = json_decode((string)@file_get_contents($this->file), true);
        if (!is_array($d)) return ['accounts' => [], 'active' => ''];

        if (isset($d['accounts']) && is_array($d['accounts'])) {
            $out = [];
            foreach ($d['accounts'] as $k => $rec) {
                if (is_array($rec)) $out[self::keyFor((string)$k)] = array_merge(self::emptyRecord(), $rec);
            }
            $active = self::keyFor((string)($d['active'] ?? ''));
            if (!isset($out[$active])) $active = $out === [] ? '' : (string)array_key_first($out);
            return ['accounts' => $out, 'active' => $active];
        }

        // The old shape: one flat record.
        $rec = array_merge(self::emptyRecord(), $d);
        $key = self::keyFor((string)$rec['account_number']);
        return ['accounts' => [$key => $rec], 'active' => $key];
    }

    /** @param array{accounts:array<string,array>, active:string} $all */
    public function writeAll(array $all): bool
    {
        require_once __DIR__ . '/SecureFile.php';
        $r = SecureFile::write($this->file, (string)json_encode([
            'schema'   => 2,
            'active'   => (string)($all['active'] ?? ''),
            'accounts' => (array)($all['accounts'] ?? []),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        return !empty($r['ok']);
    }

    /** Account keys held, in a stable order. @return array<int,string> */
    public function accounts(): array
    {
        $k = array_keys($this->readAll()['accounts']);
        sort($k);
        return $k;
    }

    /** Which account every other method in this class is talking about. */
    public function active(): string
    {
        return (string)$this->readAll()['active'];
    }

    /**
     * Point this store at another account it already holds.
     *
     * Refuses an account it does not hold rather than quietly creating a blank
     * one: "switched to an account with no cookie" and "switched to an account
     * that does not exist" must not look the same to a caller.
     */
    public function useAccount(string $account): bool
    {
        $all = $this->readAll();
        $key = self::keyFor($account);
        if (!isset($all['accounts'][$key])) return false;
        if ($all['active'] === $key) return true;
        $all['active'] = $key;
        return $this->writeAll($all);
    }

    /** Drop one account's session. Returns false if it was not held. */
    public function forgetAccount(string $account): bool
    {
        $all = $this->readAll();
        $key = self::keyFor($account);
        if (!isset($all['accounts'][$key])) return false;
        unset($all['accounts'][$key]);
        if ($all['active'] === $key) {
            $all['active'] = $all['accounts'] === [] ? '' : (string)array_key_first($all['accounts']);
        }
        return $this->writeAll($all);
    }

    /** @return array the ACTIVE account's record, or the empty shape */
    public function load(): array
    {
        $all = $this->readAll();
        $key = $all['active'];
        return $key !== '' && isset($all['accounts'][$key])
            ? $all['accounts'][$key] : self::emptyRecord();
    }

    /** Write the ACTIVE account's record. 0640, owned by the data dir's owner. */
    public function save(array $record): bool
    {
        $all = $this->readAll();
        $key = $all['active'];
        if ($key === '') $key = self::keyFor((string)($record['account_number'] ?? ''));
        $all['accounts'][$key] = array_merge(self::emptyRecord(), $record);
        $all['active'] = $key;
        return $this->writeAll($all);
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

        // WHICH account this cookie belongs to is decided before anything is
        // loaded, because it decides WHAT to load. The browser puts the account
        // in the jar, so a cookie taken after switching accounts in Starlink
        // identifies itself — and importing it adds that account rather than
        // overwriting whichever one happened to be selected here.
        //
        // That overwriting is the bug this fixes. Every second account imported
        // used to silently replace the first, so a box with four Starlink
        // accounts could only ever hold the most recently pasted one.
        $acct = $accountNumber !== ''
            ? $accountNumber
            : self::cookieValue($cookie, 'starlink.com.account_number');
        $key  = self::keyFor($acct);

        $all = $this->readAll();
        $rec = $all['accounts'][$key] ?? self::emptyRecord();

        $rec['cookie_enc']  = $this->encrypt($cookie);
        $rec['imported_at'] = gmdate('Y-m-d H:i:s');
        $rec['imported_by'] = $who;
        $rec['state']       = self::STATE_ACTIVE;
        $rec['consecutive_failures'] = 0;
        $rec['last_error']  = '';
        $rec['throttled_until'] = '';
        if ($accountEmail !== '') $rec['account_email'] = $accountEmail;
        if ($acct !== '')         $rec['account_number'] = strtoupper(trim($acct));

        $all['accounts'][$key] = $rec;
        // The one just imported becomes active: it is the freshest session on
        // the box and the reason somebody went to get it.
        $all['active'] = $key;

        return $this->writeAll($all)
            ? ['ok' => true, 'error' => '', 'names' => self::cookieNames($cookie),
               'account' => $key, 'accounts_held' => count($all['accounts'])]
            : ['ok' => false, 'error' => 'could not write the session store'];
    }

    /**
     * Record whose account this session is, learned rather than typed.
     *
     * Only fills a blank. An operator who set the account deliberately is not
     * overruled by something read off a response, and a session that reports
     * an account nobody entered is exactly the thing worth checking against
     * the account they meant to use.
     */
    public function rememberAccount(string $email, string $number): void
    {
        $rec     = $this->load();
        $changed = false;
        if ($email !== '' && trim((string)$rec['account_email']) === '') {
            $rec['account_email'] = $email; $changed = true;
        }
        if ($number !== '' && trim((string)$rec['account_number']) === '') {
            $rec['account_number'] = $number; $changed = true;
        }
        if ($changed) $this->save($rec);
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

    /**
     * The access token expired while the SSO session is still valid.
     *
     * Deliberately NOT a failure. Failures accumulate towards declaring a
     * session dead, and an expired token is the ordinary end of a working
     * session's day — counting it as a fault would eventually condemn a
     * session that never did anything wrong.
     */
    public function markExpired(string $why): void
    {
        $rec = $this->load();
        $rec['last_checked_at'] = gmdate('Y-m-d H:i:s');
        $rec['state']      = self::STATE_EXPIRED;
        $rec['last_error'] = mb_substr($why, 0, 300);
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

    /**
     * True when a person has to go and fetch a new cookie.
     *
     * Expired counts. Until an endpoint is found that mints a fresh access
     * token from the SSO session, a re-import is the only way back — the
     * difference from dead is that the account is fine and the trip is short.
     */
    public function needsReimport(): bool
    {
        $state = (string)$this->load()['state'];
        return $state === self::STATE_DEAD || $state === self::STATE_EXPIRED;
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

        // Two independent signals, because either alone can be fooled.
        //
        // Size: 4096 is the usual terminal line limit. A session landing there
        // was almost certainly cut.
        //
        // Content: a session with no Starlink token in it is not a session,
        // whatever else it contains. That one is certain.
        //
        // What is NOT certain — and was briefly treated as if it were — is
        // cookie ORDER. A jar ending on a session token looked like a severed
        // tail, so it was reported as one; then a 5,238-byte jar ending
        // exactly that way was accepted by Starlink on the first try. Browsers
        // do not promise an order. The observation is kept as a hint, never as
        // a verdict, and it no longer makes a session "suspect" on its own.
        $keys = array_keys($names);
        $last = $keys === [] ? '' : (string)end($keys);

        return [
            'names' => $names,
            'total' => $total,
            'has_session_tokens'    => isset($names['Starlink.Com.Access.V1'])
                                    || isset($names['Starlink.Com.Sso']),
            'ends_on_session_token' => in_array($last, ['Starlink.Com.Access.V1',
                                                        'Starlink.Com.Sso'], true),
            // Size alone. It is the signal that was actually right: 4,095
            // bytes is where a terminal cuts, and nothing else lands there.
            'suspect_truncated'     => $total >= 4000 && $total <= 4110,
        ];
    }

    /**
     * One cookie's value, by name.
     *
     * Used for the account number only — a business identifier the browser
     * puts in the jar in plain sight. Not a general accessor for session
     * tokens: those are read once, by the connector, and never by anything
     * that might print.
     */
    public static function cookieValue(string $cookie, string $want): string
    {
        foreach (explode(';', $cookie) as $part) {
            $part = trim($part);
            $eq   = strpos($part, '=');
            if ($eq === false) continue;
            if (substr($part, 0, $eq) === $want) return trim(substr($part, $eq + 1));
        }
        return '';
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
