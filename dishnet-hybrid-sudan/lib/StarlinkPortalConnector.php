<?php
declare(strict_types=1);

require_once __DIR__ . '/StarlinkConnector.php';
require_once __DIR__ . '/StarlinkSessionStore.php';

/**
 * StarlinkPortalConnector — Starlink over an imported browser session.
 *
 * Adapted from the South Sudan implementation (session_manager.php), which has
 * run against the live portal for months. What is inherited here is the part
 * that was expensive to learn: the exact header set Starlink accepts, how to
 * merge Set-Cookie without losing the rest of the jar, which refresh endpoints
 * exist and why they are alternated, and two decisions that look wrong until
 * you know why they are there.
 *
 * ─── Inherited decision 1: redirects are NOT followed ────────────────────
 * From their v2.8.8 note. With FOLLOWLOCATION on, some calls redirect to an
 * auth endpoint that answers token_expired, so a request that would have
 * returned 200 returns 401 instead. The redirect is the trap, not the fix.
 *
 * ─── Inherited decision 2: the header set is fixed ───────────────────────
 * From their v2.7.35 note. They tried rotating user agents and fingerprints as
 * anti-ban protection; it broke telemetry calls and was reverted to the simple
 * set that had worked for months. So this does NOT rotate anything. That is a
 * mistake somebody already made, and inheriting the fix means not repeating it.
 *
 * ─── What this class does not do ─────────────────────────────────────────
 * It does not log in — there is no password to log in with. A person imports a
 * session; this keeps it alive and uses it. When it can no longer be kept
 * alive the store is marked dead and a person imports another.
 */
class StarlinkPortalConnector implements StarlinkConnector
{
    const HOST     = 'https://starlink.com';
    const HOST_ALT = 'https://api.starlink.com';

    /** Verified cheaply, and it returns who we are — useful in itself. */
    const VERIFY_PATH = '/api/accounts/v3/accounts/contact';

    /**
     * The SSO layer, which is not the same layer as the data calls.
     *
     * Starlink authenticates in two tiers: a long-lived SSO session
     * (Starlink.Com.Sso) and a short-lived access token
     * (Starlink.Com.Access.V1). When the access token expires, every data call
     * answers 401 token_expired while THIS endpoint still answers 200 with the
     * account's identity.
     *
     * That distinction decides whether a session is recoverable. Treating an
     * expired token as a dead session would send somebody to fetch a cookie
     * they did not need.
     */
    const SSO_PATH = '/auth-rp/auth/user';

    /**
     * The service-line numbers, and nothing else.
     *
     * Worth knowing about because it answered 200 in the same run where the
     * full webagg service-lines call answered 500. When the rich endpoint is
     * having a bad minute, this still says which lines exist.
     */
    const LINES_LIGHT_PATH = '/api/accounts/v1/accounts/service-line-numbers';

    /** Everything about the service lines. Occasionally 500s; retry handles it. */
    const LINES_PATH = '/api/webagg/v2/accounts/service-lines'
                     . '?limit=100&page=0&isConverting=false&onlyActive=false';

    /** Alternated per run so neither is hammered. Their reasoning, kept. */
    const REFRESH_PATHS = [
        '/api/auth/v1/session/refresh',
        '/api/auth/v1/token/refresh',
    ];

    /** @var StarlinkSessionStore */ private $store;
    /** @var array */                private $config;
    /** @var callable|null */        private $http;
    /** @var string */               private $lastDetail = '';

    /**
     * @param callable|null $http test seam:
     *        fn(string $method, string $url, array $headers, ?string $body): array
     *        returning ['code'=>int, 'body'=>string, 'cookies'=>array, 'error'=>string]
     */
    public function __construct(StarlinkSessionStore $store, array $config, ?callable $http = null)
    {
        $this->store  = $store;
        $this->config = $config;
        $this->http   = $http;
    }

    public function describe(): string { return 'Starlink web session (imported cookie)'; }

    public function isConfigured(): bool { return $this->store->cookie() !== ''; }

    /**
     * The header set, exactly as the working implementation sends it.
     *
     * Nine headers, no rotation. See the class comment: rotation was tried and
     * reverted upstream.
     */
    public function headers(string $cookie): array
    {
        return [
            'accept: */*',
            'accept-language: en-US',
            'content-type: application/json',
            'cookie: ' . $cookie,
            'referer: https://starlink.com/',
            'user-agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
                . '(KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            'sec-fetch-dest: empty',
            'sec-fetch-mode: cors',
            'sec-fetch-site: same-origin',
        ];
    }

    /**
     * Merge Set-Cookie values into the jar we hold, preserving order and
     * everything not mentioned. Adapted from smMergeCookies().
     */
    public static function mergeCookies(string $existing, array $fresh): string
    {
        if ($fresh === []) return $existing;

        $map = []; $order = [];
        foreach (explode(';', $existing) as $part) {
            $part = trim($part);
            if ($part === '') continue;
            $eq = strpos($part, '=');
            if ($eq === false) { $map[$part] = ''; $order[] = $part; continue; }
            $k = trim(substr($part, 0, $eq));
            $map[$k] = trim(substr($part, $eq + 1));
            $order[] = $k;
        }

        $changed = false;
        foreach ($fresh as $name => $value) {
            $name = (string)$name;
            if (!in_array($name, $order, true)) $order[] = $name;
            if (!isset($map[$name]) || $map[$name] !== $value) {
                $map[$name] = (string)$value;
                $changed = true;
            }
        }
        if (!$changed) return $existing;

        $parts = [];
        foreach ($order as $k) {
            if (!array_key_exists($k, $map)) continue;
            $parts[] = $map[$k] !== '' ? $k . '=' . $map[$k] : $k;
        }
        return implode('; ', $parts);
    }

    /** Set-Cookie header lines → [name => value]. */
    public static function parseSetCookie(array $headerLines): array
    {
        $out = [];
        foreach ($headerLines as $line) {
            if (stripos((string)$line, 'set-cookie:') !== 0) continue;
            $pair = trim(substr((string)$line, strlen('set-cookie:')));
            $pair = explode(';', $pair)[0];
            $eq   = strpos($pair, '=');
            if ($eq === false) continue;
            $out[trim(substr($pair, 0, $eq))] = trim(substr($pair, $eq + 1));
        }
        return $out;
    }

    public function get(string $path): array
    {
        return $this->request('GET', $path);
    }

    /**
     * One request, with the session kept up to date as a side effect.
     *
     * A 401 is not treated as fatal here: it triggers one refresh and one
     * retry, because an expired token is the ordinary condition of a
     * long-lived session rather than an error.
     */
    public function request(string $method, string $path, bool $allowRefresh = true,
                            bool $allowRetry = true): array
    {
        $fail = function (string $err, int $code = 0, bool $retryable = false) {
            return ['ok' => false, 'code' => $code, 'data' => [],
                    'error' => $err, 'retryable' => $retryable];
        };

        $cookie = $this->store->cookie();
        if ($cookie === '') {
            return $fail('no Starlink session has been imported for this account');
        }
        if ($this->store->needsReimport()) {
            return $fail('the session is dead and needs a fresh cookie imported '
                       . '(php tools/starlink_session.php --import)');
        }
        if ($this->store->isThrottled()) {
            return $fail('backing off until ' . $this->store->throttledUntil()
                       . ' after Starlink asked us to slow down', 429, true);
        }

        $r = $this->send($method, self::HOST . $path, $this->headers($cookie));

        // Keep whatever the response handed back, even on a failure: a
        // rotated cookie arriving with a 401 is still the newer cookie.
        if (!empty($r['cookies'])) {
            $merged = self::mergeCookies($cookie, (array)$r['cookies']);
            if ($merged !== $cookie) { $this->store->updateCookie($merged); $cookie = $merged; }
        }

        $code = (int)($r['code'] ?? 0);

        if ($code === 429 || $code === 503) {
            $this->store->markFailure('Starlink returned ' . $code);
            $this->store->recordThrottle();
            return $fail('Starlink asked us to slow down (' . $code . ')', $code, true);
        }

        if (($code === 401 || $code === 403) && $allowRefresh) {
            $ref = $this->refresh();
            if (!empty($ref['ok'])) {
                return $this->request($method, $path, false);
            }
            // Before calling it dead: is the SSO session still up? An expired
            // access token and a revoked session look identical at the data
            // layer and are entirely different problems. Counting the first as
            // the second sends somebody to fetch a cookie they already have.
            $sso = $this->ssoAlive();
            if (!empty($sso['ok'])) {
                $this->store->markExpired('the access token expired; the SSO session for '
                    . ($sso['email'] !== '' ? $sso['email'] : 'this account') . ' is still valid');
                return $fail('the access token has expired. The SSO session is still alive, '
                           . 'so this is recoverable — but no endpoint we know of mints a new '
                           . 'token, so the cookie must be re-imported from the browser',
                           $code, false);
            }

            $this->store->markFailure('unauthorised, and the refresh failed: ' . (string)$ref['error']);
            return $fail('the session is no longer accepted and could not be refreshed: '
                       . (string)$ref['error'], $code, false);
        }

        if ($code >= 500) {
            // Their side, not ours. The same call with the same parameters
            // succeeded minutes earlier and 500ed on the next run, so this is
            // weather rather than a wrong request — and it must NOT count
            // against the session, which did nothing wrong. Counting it would
            // eventually declare a perfectly good cookie dead because Starlink
            // had a bad afternoon.
            if ($allowRetry) {
                sleep(2);
                return $this->request($method, $path, $allowRefresh, false);
            }
            return $fail('Starlink returned HTTP ' . $code
                       . ' — their side, and it cleared on a retry before now',
                         $code, true);
        }

        if ($code < 200 || $code >= 300) {
            $err = (string)($r['error'] ?? '') !== ''
                ? (string)$r['error'] : 'Starlink returned HTTP ' . $code;
            $this->store->markFailure($err);
            return $fail($err, $code, false);
        }

        $data = json_decode((string)($r['body'] ?? ''), true);
        if (!is_array($data)) {
            $this->store->markFailure('the response was not JSON');
            return $fail('the response was not JSON', $code, true);
        }

        $this->store->clearThrottle();
        $this->store->markOk();
        return ['ok' => true, 'code' => $code, 'data' => $data, 'error' => '', 'retryable' => false];
    }

    /**
     * Ask Starlink to extend the session.
     *
     * One endpoint per attempt, alternating by day so both stay exercised
     * without either being hit twice in a row — their reasoning, and it also
     * means a single broken endpoint does not take the session with it.
     */
    public function refresh(): array
    {
        $cookie = $this->store->cookie();
        if ($cookie === '') return ['ok' => false, 'error' => 'no session to refresh'];

        $path = self::REFRESH_PATHS[(int)gmdate('z') % count(self::REFRESH_PATHS)];
        $r    = $this->send('POST', self::HOST . $path,
                            array_merge($this->headers($cookie), ['content-length: 0']));

        $code = (int)($r['code'] ?? 0);
        if ($code !== 200 && $code !== 204) {
            return ['ok' => false, 'error' => 'refresh returned HTTP ' . $code];
        }

        $merged = self::mergeCookies($cookie, (array)($r['cookies'] ?? []));
        if ($merged === $cookie) {
            // Accepted, but nothing new. The old cookie is still the cookie.
            return ['ok' => true, 'error' => '', 'rotated' => false];
        }

        // Prove the new cookie works before trusting it — their heartbeat, and
        // the reason a refresh that "succeeds" cannot quietly break a session.
        $this->store->updateCookie($merged);
        $check = $this->send('GET', self::HOST . self::VERIFY_PATH, $this->headers($merged));
        if ((int)($check['code'] ?? 0) === 200) {
            $this->store->markOk();
            return ['ok' => true, 'error' => '', 'rotated' => true];
        }

        return ['ok' => false, 'error' => 'the refreshed session did not verify'];
    }

    /**
     * One raw request, with nothing helping it.
     *
     * No refresh on 401, no throttle check, no state written. The probe needs
     * to report what Starlink actually said, and the automatic refresh —
     * correct in normal use — replaced a 401 on the call we cared about with a
     * 404 from the refresh endpoint, which is a different question's answer.
     *
     * Reports which cookies the response SET, because that is the actual
     * question when hunting for whatever mints a fresh access token: a 200
     * that sets nothing has not renewed anything, and a 302 that sets
     * Starlink.Com.Access.V1 has.
     *
     * @return array{code:int, error:string, bytes:int, snippet:string, sets:array, location:string}
     */
    public function raw(string $method, string $path): array
    {
        $cookie = $this->store->cookie();
        if ($cookie === '') {
            return ['code' => 0, 'error' => 'no session', 'bytes' => 0,
                    'snippet' => '', 'sets' => [], 'location' => ''];
        }

        $headers = $this->headers($cookie);
        if ($method === 'POST') $headers[] = 'content-length: 0';

        $r    = $this->send($method, self::HOST . $path, $headers);
        $body = (string)($r['body'] ?? '');
        return [
            'code'     => (int)($r['code'] ?? 0),
            'error'    => (string)($r['error'] ?? ''),
            'bytes'    => strlen($body),
            'snippet'  => str_replace(["\n", "\r"], ' ', substr($body, 0, 120)),
            'sets'     => array_keys((array)($r['cookies'] ?? [])),
            'location' => (string)($r['location'] ?? ''),
        ];
    }

    /**
     * Is the SSO session still alive, whatever the access token is doing?
     *
     * @return array{ok:bool, email:string, code:int}
     */
    public function ssoAlive(): array
    {
        $cookie = $this->store->cookie();
        if ($cookie === '') return ['ok' => false, 'email' => '', 'code' => 0];

        $r    = $this->send('GET', self::HOST . self::SSO_PATH, $this->headers($cookie));
        $code = (int)($r['code'] ?? 0);
        if ($code !== 200) return ['ok' => false, 'email' => '', 'code' => $code];

        $d = json_decode((string)($r['body'] ?? ''), true);
        return ['ok' => true, 'code' => 200,
                'email' => is_array($d) ? (string)($d['email'] ?? '') : ''];
    }

    public function verify(): array
    {
        if (!$this->isConfigured()) {
            return ['ok' => false, 'error' => 'no session imported', 'detail' => ''];
        }
        $r = $this->get(self::VERIFY_PATH);
        if (empty($r['ok'])) {
            return ['ok' => false, 'error' => (string)$r['error'], 'detail' => $this->lastDetail];
        }
        $d = $r['data'];
        $who = (string)($d['email'] ?? $d['contactEmail'] ?? $d['accountNumber'] ?? '');
        return ['ok' => true, 'error' => '',
                'detail' => $who !== '' ? $who : 'session accepted'];
    }

    // ── transport ────────────────────────────────────────────────────────

    /**
     * @return array{code:int, body:string, cookies:array, error:string}
     */
    private function send(string $method, string $url, array $headers): array
    {
        if ($this->http !== null) {
            $r = ($this->http)($method, $url, $headers, null);
            return is_array($r) ? $r + ['code' => 0, 'body' => '', 'cookies' => [], 'error' => '']
                                : ['code' => 0, 'body' => '', 'cookies' => [], 'error' => 'no response'];
        }

        $lines = [];
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            // Inherited deliberately. See the class comment: following a
            // redirect lands on an auth endpoint that answers token_expired,
            // turning a 200 into a 401.
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_ENCODING       => '',
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$lines) {
                $lines[] = trim((string)$line);
                return strlen((string)$line);
            },
        ]);
        $body = (string)curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = (string)curl_error($ch);
        curl_close($ch);

        $this->lastDetail = $err !== '' ? $err : ('HTTP ' . $code);

        // Redirects are not followed (see the class comment), but where one
        // points is worth knowing: an OIDC re-auth is a redirect chain, and
        // the Location is the next step in it.
        $location = '';
        foreach ($lines as $line) {
            if (stripos((string)$line, 'location:') === 0) {
                $location = trim(substr((string)$line, strlen('location:')));
            }
        }

        return ['code' => $code, 'body' => $body, 'location' => $location,
                'cookies' => self::parseSetCookie($lines), 'error' => $err];
    }
}
