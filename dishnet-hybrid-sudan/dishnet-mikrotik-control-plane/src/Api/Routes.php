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
                // Savepointed so a duplicate name leaves the transaction
                // usable: the function writes its own audit row inside the
                // same transaction, so a refused create audits nothing.
                $plan = $db->attempt(fn($d) => (new \Dn\Policy\PlanRepository($d))
                    ->create($req->body, $who['principal_id'], $site, $req->ip));
            } catch (\PDOException $e) {
                if (($e->errorInfo[0] ?? '') === '23505') {
                    return Response::conflict('a plan with that name already exists');
                }
                throw $e;
            }
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

            $plan = $repo->update($id, $req->body, $who['principal_id'], $req->ip);
            if ($plan === null) { return Response::notFound(); }
            return Response::ok(['plan' => P::plan($plan)]);
        });

        $r->post('/api/v1/me/plans/{plan_id}/retire', function (Request $req, Database $db, array $who) {
            $id = $req->params['plan_id'] ?? '';
            if (!preg_match('/^[0-9a-f-]{36}$/i', $id)) { return Response::notFound(); }
            $plan = (new \Dn\Policy\PlanRepository($db))
                ->retire($id, $who['principal_id'], $req->ip);
            if ($plan === null) { return Response::notFound(); }
            return Response::ok(['plan' => P::plan($plan)]);
        });

        // ── vouchers ────────────────────────────────────────────────────
        $r->get('/api/v1/me/vouchers', function (Request $req, Database $db) {
            $state = $req->params['state'] ?? null;
            $rows = (new \Dn\Vouchers\VoucherService($db))->list();
            return Response::ok(['vouchers' => P::many([P::class, 'voucher'], $rows)]);
        });

        $r->post('/api/v1/me/vouchers', function (Request $req, Database $db, array $who) {
            $planId = (string) ($req->body['plan_id'] ?? '');
            $count  = (int) ($req->body['count'] ?? 1);
            $site   = $req->body['site_id'] ?? null;

            if (!preg_match('/^[0-9a-f-]{36}$/i', $planId)) { return Response::notFound(); }
            if ($count < 1 || $count > 500) {
                // Above this a batch is a job, not a request (docs/30
                // Artifact 9). The async path is step 5b; until it exists the
                // limit is stated rather than letting a request time out
                // halfway through writing rows.
                return Response::badRequest('count must be between 1 and 500');
            }
            if ($site !== null && $db->one('SELECT id FROM mt_sites WHERE id = ?', [$site]) === null) {
                return Response::notFound();
            }

            $key = $req->header('Idempotency-Key');
            try {
                $out = (new \Dn\Vouchers\VoucherService($db))->issueBatch(
                    $planId, $count, $site, $who['principal_id'], $key, $req->ip);
            } catch (\InvalidArgumentException) {
                return Response::notFound();   // unknown or retired plan
            }

            // 202, not 200: the codes exist, and the work of publishing them
            // is queued. Says nothing about how or when that reaches a router.
            return Response::accepted([
                'batch'     => P::batch($out['batch']),
                'vouchers'  => P::many([P::class, 'voucher'], $out['vouchers']),
                'intent_id' => $out['intent']['id'],
            ]);
        });

        $r->post('/api/v1/me/vouchers/{voucher_id}/revoke', function (Request $req, Database $db, array $who) {
            $id = $req->params['voucher_id'] ?? '';
            if (!preg_match('/^[0-9a-f-]{36}$/i', $id)) { return Response::notFound(); }
            $out = (new \Dn\Vouchers\VoucherService($db))
                ->revoke($id, $who['principal_id'], $req->ip);
            if ($out === null) { return Response::notFound(); }

            return Response::accepted([
                'voucher'   => P::voucher($out['voucher']),
                'intent_id' => $out['intent_id'],
            ]);
        });

        // ── sessions: connected devices ─────────────────────────────────
        $r->get('/api/v1/me/sessions', function (Request $req, Database $db) {
            $svc = new \Dn\Sessions\SessionService($db);
            $rows = ($req->body['all'] ?? false) ? $svc->all() : $svc->live();
            return Response::ok(['sessions' => P::many([P::class, 'session'], $rows)]);
        });

        $r->get('/api/v1/me/usage', function (Request $req, Database $db) {
            $u = (new \Dn\Sessions\SessionService($db))->usage();
            return Response::ok(['usage' => [
                'open_now'       => (int) ($u['open_now'] ?? 0),
                'sessions_total' => (int) ($u['sessions_total'] ?? 0),
                'bytes_in'       => (int) ($u['bytes_in'] ?? 0),
                'bytes_out'      => (int) ($u['bytes_out'] ?? 0),
            ]]);
        });

        $r->post('/api/v1/me/sessions/{session_id}/disconnect',
            function (Request $req, Database $db, array $who) {
                $id = $req->params['session_id'] ?? '';
                if (!preg_match('/^[0-9a-f-]{36}$/i', $id)) { return Response::notFound(); }
                // Disconnecting reaches a router, so it is an intent like
                // everything else that does. The intent and its audit row are
                // one operation inside the function; the session's ownership
                // is established there too, so no separate find() is needed.
                $intentId = (new \Dn\Sessions\SessionService($db))
                    ->requestDisconnect($id, $who['principal_id'], $req->ip);
                if ($intentId === null) { return Response::notFound(); }

                return Response::accepted(['intent_id' => $intentId]);
            });

        // ── uplink: measured, shown, never acted on ─────────────────────
        $r->get('/api/v1/me/uplink', function (Request $req, Database $db) {
            $repo = new \Dn\Telemetry\UplinkRepository($db);
            // Stored samples, never a live read: a slow or unreachable router
            // must make this page stale, not make it hang.
            return Response::ok([
                'uplink' => $repo->summary(),
                // Absolute figures only. A percentage would need a denominator
                // DishNet does not own — and with Starlink, one that varies by
                // the minute. See the README.
                'note'   => 'observed throughput on your own connection',
            ]);
        });

        $r->get('/api/v1/me/intents', function (Request $req, Database $db) {
            $rows = (new \Dn\Intents\IntentQueue($db))->forCustomer();
            // Deliberately says nothing about when queued work reaches a
            // router. B1 is unresolved (docs/49); neither push nor poll may
            // be asserted in a response, a label or a message.
            return Response::ok(['intents' => P::many([P::class, 'intent'], $rows)]);
        });

        // ── internal: RADIUS accounting ─────────────────────────────────
        //
        // Called by FreeRADIUS, not by a customer, so it does not use the
        // bearer-token path at all. It authenticates with a shared secret and
        // runs WITHOUT a tenant context: the username is the only identity
        // presented, and resolving it is how the customer is determined.
        $r->post('/internal/radius/accounting', function (Request $req, Database $db) {
            $expected = getenv('DNB_INTERNAL_TOKEN') ?: '';
            $given    = $req->header('X-Internal-Token') ?? '';
            if ($expected === '' || !hash_equals($expected, $given)) {
                return Response::unauthorized();
            }
            // A DIFFERENT DATABASE IDENTITY, not the request connection.
            //
            // Audit finding F1: while this ran as the request role, any
            // customer request — or anything injected into one — could reach
            // mt_session_account and write into another customer's sessions,
            // because the function resolves the owner from the username it is
            // given. The ingestion role holds EXECUTE on that one function and
            // no table privileges whatsoever (migration 018).
            $out = (new \Dn\Sessions\AccountingIngest(Database::radius()))->record($req->body);
            // 204 whatever the outcome: a NAS is not a client to be argued
            // with, and telling it whether a username exists would make this
            // endpoint a way to test usernames.
            return Response::noContent();
        }, auth: false);

        return $r;
    }
}
