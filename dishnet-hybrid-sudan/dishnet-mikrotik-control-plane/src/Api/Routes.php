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

        // ── retail plans: the customer's own products ───────────────────
        $r->get('/api/v1/me/plans', function (Request $req, Database $db) {
            $rows = (new \Dn\Policy\PlanRepository($db))->all();
            return Response::ok(['plans' => P::many([P::class, 'plan'], $rows)]);
        });

        $r->post('/api/v1/me/plans', function (Request $req, Database $db, array $who) {
            // Checked for whether the values can be EXPRESSED, never for
            // whether the customer is permitted to sell them (F9).
            $errors = (new \Dn\Policy\PlanValidator())->check($req->body);
            if ($errors) { return new Response(422, ['error' => 'invalid_plan', 'reasons' => $errors]); }

            $site = $req->body['site_id'] ?? null;
            if ($site !== null) {
                // Filters the derived set; a foreign site id matches nothing.
                $ok = $db->one('SELECT id FROM mt_sites WHERE id = ?', [$site]);
                if ($ok === null) { return Response::notFound(); }
            }
            try {
                $plan = (new \Dn\Policy\PlanRepository($db))
                    ->create($who['customer_id'], $req->body, $who['principal_id'], $site);
            } catch (\PDOException $e) {
                if (($e->errorInfo[0] ?? '') === '23505') {
                    return Response::conflict('a plan with that name already exists');
                }
                throw $e;
            }
            (new \Dn\Audit\AuditLog($db))->record($who['customer_id'], $who['principal_id'],
                'principal', 'plan.created', 'plan', $plan['id'], $req->ip,
                ['name' => $plan['name']]);
            return new Response(201, ['plan' => P::plan($plan)]);
        });

        $r->add('PATCH', '/api/v1/me/plans/{plan_id}', function (Request $req, Database $db, array $who) {
            $id = $req->params['plan_id'] ?? '';
            if (!preg_match('/^[0-9a-f-]{36}$/i', $id)) { return Response::notFound(); }
            $repo = new \Dn\Policy\PlanRepository($db);
            $cur = $repo->find($id);
            if ($cur === null) { return Response::notFound(); }

            $merged = array_merge($cur, array_filter($req->body, fn($v) => $v !== null));
            $errors = (new \Dn\Policy\PlanValidator())->check($merged);
            if ($errors) { return new Response(422, ['error' => 'invalid_plan', 'reasons' => $errors]); }

            $plan = $repo->update($id, $req->body);
            (new \Dn\Audit\AuditLog($db))->record($who['customer_id'], $who['principal_id'],
                'principal', 'plan.updated', 'plan', $id, $req->ip);
            return Response::ok(['plan' => P::plan($plan)]);
        });

        $r->post('/api/v1/me/plans/{plan_id}/retire', function (Request $req, Database $db, array $who) {
            $id = $req->params['plan_id'] ?? '';
            if (!preg_match('/^[0-9a-f-]{36}$/i', $id)) { return Response::notFound(); }
            $plan = (new \Dn\Policy\PlanRepository($db))->retire($id);
            if ($plan === null) { return Response::notFound(); }
            (new \Dn\Audit\AuditLog($db))->record($who['customer_id'], $who['principal_id'],
                'principal', 'plan.retired', 'plan', $id, $req->ip);
            return Response::ok(['plan' => P::plan($plan)]);
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
