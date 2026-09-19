<?php
declare(strict_types=1);
namespace Dn\Api;

use Dn\Auth\Authenticator;
use Dn\Db\Database;
use Dn\Http\Request;
use Dn\Http\Response;
use Dn\Http\Router;
use Dn\Http\Serializer\Projection as P;

/**
 * Step 2 surface: authentication and the /me reads.
 *
 * Note what is NOT here and cannot be added by accident: no route takes a
 * customer id. The grep in tests/test_frozen_guards.php enforces that.
 */
final class Routes
{
    public static function build(Authenticator $auth): Router
    {
        $r = new Router();

        // ── auth ────────────────────────────────────────────────────────
        $r->post('/api/v1/auth/request-code', function (Request $req) use ($auth) {
            $phone = trim((string) ($req->body['phone'] ?? ''));
            if ($phone === '') { return Response::badRequest('phone required'); }

            try {
                $code = $auth->issueCode($phone);
            } catch (\Dn\Auth\RateLimited) {
                // 429 and not 500: this is the client's behaviour, not a fault.
                // The message says nothing about whether the phone is known.
                return new Response(429, ['error' => 'too_many_requests']);
            }

            // ALWAYS 202, whether or not the phone is registered. Returning
            // 404 for an unknown number would turn this endpoint into a
            // directory of who holds a DishNet account.
            $body = ['status' => 'sent'];
            if (getenv('DNB_EXPOSE_OTP') === '1') {
                $body['dev_code'] = $code;   // tests and local only; never in production
            }
            return Response::accepted($body);
        }, auth: false);

        $r->post('/api/v1/auth/verify', function (Request $req) use ($auth) {
            $phone = trim((string) ($req->body['phone'] ?? ''));
            $code  = trim((string) ($req->body['code']  ?? ''));
            if ($phone === '' || $code === '') { return Response::badRequest('phone and code required'); }

            $s = $auth->verifyCode($phone, $code);
            // One failure shape for every reason: wrong code, expired,
            // already used, too many attempts, unknown phone.
            if ($s === null) { return Response::unauthorized(); }

            return Response::ok(['token' => $s['token']]);
        }, auth: false);

        $r->post('/api/v1/auth/logout', function (Request $req) use ($auth) {
            $auth->revoke($req->bearer());
            return Response::noContent();
        });

        // ── me ──────────────────────────────────────────────────────────
        $r->get('/api/v1/me', function (Request $req, Database $db, array $who) {
            $c = $db->one('SELECT * FROM mt_customers WHERE id = ?', [$who['customer_id']]);
            $p = $db->one('SELECT * FROM mt_principals WHERE id = ?', [$who['principal_id']]);
            if ($c === null || $p === null) { return Response::notFound(); }
            return Response::ok([
                'customer'  => P::customer($c),
                'principal' => P::principal($p),
            ]);
        });

        $r->get('/api/v1/me/services', function (Request $req, Database $db) {
            $rows = $db->query('SELECT * FROM mt_services ORDER BY started_at');
            return Response::ok(['services' => P::many([P::class, 'service'], $rows)]);
        });

        $r->get('/api/v1/me/sites', function (Request $req, Database $db) {
            $rows = $db->query('SELECT * FROM mt_sites ORDER BY name');
            return Response::ok(['sites' => P::many([P::class, 'site'], $rows)]);
        });

        $r->get('/api/v1/me/sites/{site_id}', function (Request $req, Database $db) {
            $id = $req->params['site_id'] ?? '';
            if (!preg_match('/^[0-9a-f-]{36}$/i', $id)) { return Response::notFound(); }

            // The id FILTERS the already-derived set; it is not a lookup key.
            // RLS means a foreign id simply matches nothing (docs/45 §3.2a).
            $row = $db->one('SELECT * FROM mt_sites WHERE id = ?', [$id]);
            if ($row === null) { return Response::notFound(); }
            return Response::ok(['site' => P::site($row)]);
        });

        $r->get('/api/v1/me/entitlements', function (Request $req, Database $db) {
            $rows = $db->query('SELECT * FROM mt_entitlements ORDER BY key');
            // Read-only, always. What they bought, never a gate they hit here.
            return Response::ok(['entitlements' => P::many([P::class, 'entitlement'], $rows)]);
        });

        $r->get('/api/v1/me/intents', function (Request $req, Database $db) {
            $rows = (new \Dn\Intents\IntentQueue($db))->forCustomer();
            // Deliberately says nothing about when queued work reaches a
            // router. B1 is unresolved (docs/49); neither push nor poll may
            // be asserted in a response, a label or a message.
            return Response::ok(['intents' => P::many([P::class, 'intent'], $rows)]);
        });

        return $r;
    }
}
