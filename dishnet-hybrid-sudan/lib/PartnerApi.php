<?php
declare(strict_types=1);

require_once __DIR__ . '/PartnerContext.php';
require_once __DIR__ . '/PartnerSession.php';
require_once __DIR__ . '/PartnerAuth.php';
require_once __DIR__ . '/DistributorPortalData.php';

/**
 * PartnerApi — the distributor portal's ONE API dispatcher (WS-A P4d, docs/50
 * §C, docs/47 §10.3). Deny-by-default and strictly separate from the staff API:
 *
 *   - ONE registry (self::ACTIONS). An undeclared action is 404; it NEVER falls
 *     through to the staff api_handlers.php (this is its own entry file,
 *     partner_api.php), so a partner request can reach only what is declared.
 *   - A staff token is refused here, and a partner token on the staff API:
 *     the only credential this dispatcher accepts is a dist_partner_sessions
 *     token (PartnerSession), which a staff cookie/bearer cannot satisfy.
 *   - Scope comes from the session, never the request (PartnerContext). Every
 *     read is routed through DistributorPortalData, so an id outside the
 *     caller's partner is 404 — a probe cannot tell "absent" from "not yours".
 *   - Answers are allow-listed (DistributorPortalData projects); no raw uCRM
 *     object, no foreign uCRM id, no app key ever leaves here.
 *   - Every write (POST) needs the custom header AND a same-site Origin, so a
 *     cross-origin page cannot drive it.
 *
 * handle() is PURE: it takes a request array and returns a response
 * {code, body, cookie} — the entry file (partner_api.php) applies the cookie,
 * status and JSON. That keeps the security logic unit-testable without HTTP.
 *
 * The pilot is READ-ONLY beyond sign-in: the only state it changes is the
 * caller's own authentication (codes, enrolment, session). It sends nothing —
 * the login code is produced and handed to an injected delivery seam that is
 * null in the pilot (no live transport).
 */
final class PartnerApi
{
    /** action => [httpMethod, auth: 'public'|'session']. The whole surface. */
    public const ACTIONS = [
        'auth.request_code'  => ['POST', 'public'],
        'auth.sign_in'       => ['POST', 'public'],
        'auth.enrol_begin'   => ['POST', 'public'],
        'auth.enrol_confirm' => ['POST', 'public'],
        'me.profile'         => ['GET',  'session'],
        'me.customers'       => ['GET',  'session'],
        'me.link'            => ['GET',  'session'],
        'me.logout'          => ['POST', 'session'],
    ];

    private static function r(int $code, array $body, $cookie = null): array
    {
        return ['code' => $code, 'body' => $body, 'cookie' => $cookie];
    }

    /**
     * @param array $req ['action','method','body','server','cookie','ip','ua']
     * @param callable|null $deliver function(array delivery): void — null = send nothing (the pilot)
     * @return array{code:int, body:array, cookie:?array}
     */
    public static function handle(\PDO $pdo, array $config, \TenantProfile $tp, array $req, ?callable $deliver = null): array
    {
        $action = (string)($req['action'] ?? '');
        if (!isset(self::ACTIONS[$action])) return self::r(404, ['error' => 'not_found']);
        [$want, $auth] = self::ACTIONS[$action];

        $method = strtoupper((string)($req['method'] ?? 'GET'));
        if ($method !== $want) return self::r(405, ['error' => 'method_not_allowed']);

        $server = is_array($req['server'] ?? null) ? $req['server'] : [];
        $cookie = is_array($req['cookie'] ?? null) ? $req['cookie'] : [];
        $body   = is_array($req['body'] ?? null) ? $req['body'] : [];
        $ip     = (string)($req['ip'] ?? '');
        $ua     = (string)($req['ua'] ?? '');

        // Every write needs the custom header and a same-site Origin.
        if ($want === 'POST') {
            if (trim((string)($server['HTTP_X_REQUESTED_WITH'] ?? '')) !== PartnerSession::REQUESTED_WITH) {
                return self::r(403, ['error' => 'forbidden']);
            }
            if (PartnerSession::crossSite($server)) return self::r(403, ['error' => 'cross_origin']);
        }

        $ctx = null;
        if ($auth === 'session') {
            try {
                $a = PartnerSession::authenticate($pdo, $config, $method, $server, $cookie);
                $ctx = $a['ctx'];
            } catch (\PartnerSessionException $e) {
                return self::r(401, ['error' => $e->reason()]);
            }
        }

        switch ($action) {
            case 'auth.request_code':
                $r = PartnerAuth::requestLoginCode($pdo, $config, (string)($body['phone'] ?? ''), $tp, $ip);
                if ($deliver !== null && !empty($r['delivery'])) { try { $deliver($r['delivery']); } catch (\Throwable $e) {} }
                return self::r(200, ['status' => 'sent']); // uniform — reveals nothing

            case 'auth.sign_in':
                $r = PartnerAuth::signIn($pdo, $config, (string)($body['phone'] ?? ''), (string)($body['code'] ?? ''), (string)($body['totp'] ?? ''), $tp, $ip);
                if (!empty($r['ok'])) {
                    $token = PartnerSession::issue($pdo, $config, (int)$r['user_id'], $ip, $ua);
                    return self::r(200, ['ok' => true], ['action' => 'set', 'token' => $token]);
                }
                if (isset($r['need'])) return self::r(200, ['ok' => false, 'need' => (string)$r['need']]);
                $out = ['ok' => false, 'reason' => (string)($r['reason'] ?? 'invalid')];
                if (isset($r['retry_in'])) $out['retry_in'] = (int)$r['retry_in'];
                return self::r(401, $out);

            case 'auth.enrol_begin':
                $uid = PartnerAuth::checkCode($pdo, $config, (string)($body['phone'] ?? ''), (string)($body['code'] ?? ''), $tp, $ip);
                if ($uid === null) return self::r(401, ['error' => 'invalid']);
                try { $e = PartnerAuth::beginEnrol($pdo, $uid); }
                catch (\Throwable $ex) { return self::r(409, ['error' => 'enrol_unavailable']); }
                return self::r(200, ['secret' => $e['secret'], 'uri' => $e['uri']]);

            case 'auth.enrol_confirm':
                $uid = PartnerAuth::checkCode($pdo, $config, (string)($body['phone'] ?? ''), (string)($body['code'] ?? ''), $tp, $ip);
                if ($uid === null) return self::r(401, ['error' => 'invalid']);
                $ok = PartnerAuth::confirmEnrol($pdo, $uid, (string)($body['totp'] ?? ''));
                return self::r(200, ['ok' => $ok]);

            case 'me.profile':
                return self::r(200, ['profile' => (new DistributorPortalData($pdo, $ctx))->myProfile()]);

            case 'me.customers':
                $d = new DistributorPortalData($pdo, $ctx);
                return self::r(200, ['counts' => $d->myCounts(), 'links' => $d->myLinks()]);

            case 'me.link':
                $id = (int)($body['id'] ?? ($server['__query_id'] ?? 0));
                $row = (new DistributorPortalData($pdo, $ctx))->myLink($id);
                return $row === null ? self::r(404, ['error' => 'not_found']) : self::r(200, ['link' => $row]);

            case 'me.logout':
                $src = PartnerSession::fromRequest($server, $cookie);
                if ($src['token'] !== '') PartnerSession::revokeByToken($pdo, $config, $src['token'], 'logout');
                return self::r(200, ['ok' => true], ['action' => 'clear']);
        }

        return self::r(404, ['error' => 'not_found']); // unreachable; deny by default
    }
}
