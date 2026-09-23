<?php
declare(strict_types=1);
namespace Dn\Api;

use Dn\Auth\Authenticator;
use Dn\Auth\OpCapability as C;
use Dn\Db\Database;
use Dn\Http\Request;
use Dn\Http\Response;
use Dn\Http\Router;
use Dn\Http\Serializer\Projection as P;

/**
 * The operator plane: authentication and the /me surface.
 *
 * Note what is NOT here and cannot be added by accident: no route takes a
 * customer id. The grep in tests/test_frozen_guards.php enforces that.
 *
 * Since migration 027 EVERY /me route declares an operator-plane capability
 * (docs/116 §E.2) and runs only through $guard below. kind and capabilities
 * arrive with $who from mt_auth_resolve_token(), re-read live on every
 * request; nothing is read from the request to establish them. A 403 here
 * describes the caller's OWN role inside its OWN operator — the customer
 * plane's "404, never 403" rule protects RECORDS and is untouched: a foreign
 * id still matches nothing and answers 404, capability or not.
 */
final class Routes
{
    public static function build(Authenticator $auth): Router
    {
        $r = new Router();

        /**
         * The only way into a /me handler. The capability is a required
         * argument, so a route cannot be added without deciding who may reach
         * it. The database floor (mt_principal_require, 027) sits beneath it:
         * removing this guard could not reopen a write.
         */
        $guard = static function (string $capability, callable $handler): callable {
            return static function (Request $req, Database $db, ?array $who) use ($capability, $handler) {
                if ($who === null || !C::allows($who, $capability)) {
                    return new Response(403, ['error' => 'forbidden', 'capability' => $capability]);
                }
                return $handler($req, $db, $who);
            };
        };

        /** A refusal the identity functions raised on purpose, as an HTTP answer; null otherwise. */
        $refusal = static function (\PDOException $e): ?Response {
            $state = (string) ($e->errorInfo[0] ?? $e->getCode());
            if ($state === '23505') {
                // P-B: a phone is unique across the whole system and a duplicate
                // is a refusal, never an upsert. The wording says nothing about
                // WHERE the number is in use.
                return Response::conflict('phone unavailable');
            }
            if ($state === '23514') {
                $why = preg_match('/ERROR:\s+(.+?)(\r?\n|$)/', $e->getMessage(), $m) ? trim($m[1]) : 'refused';
                if (str_contains($why, 'violates check constraint')) { $why = 'refused'; }
                return Response::conflict($why);
            }
            return null;   // 42501 and everything else: the Kernel decides
        };

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
        $r->get('/api/v1/me', $guard(C::PROFILE_READ, function (Request $req, Database $db, array $who) {
            $c = $db->one('SELECT * FROM mt_customers WHERE id = ?', [$who['customer_id']]);
            $p = $db->one('SELECT * FROM mt_principals WHERE id = ?', [$who['principal_id']]);
            if ($c === null || $p === null) { return Response::notFound(); }
            return Response::ok([
                'customer'  => P::customer($c),
                'principal' => P::principal($p),
            ]);
        }));

        $r->get('/api/v1/me/services', $guard(C::LOCATIONS_READ, function (Request $req, Database $db) {
            $rows = $db->query('SELECT * FROM mt_services ORDER BY started_at');
            return Response::ok(['services' => P::many([P::class, 'service'], $rows)]);
        }));

        $r->get('/api/v1/me/sites', $guard(C::LOCATIONS_READ, function (Request $req, Database $db) {
            $rows = $db->query('SELECT * FROM mt_sites ORDER BY name');
            return Response::ok(['sites' => P::many([P::class, 'site'], $rows)]);
        }));

        $r->get('/api/v1/me/sites/{site_id}', $guard(C::LOCATIONS_READ, function (Request $req, Database $db) {
            $id = $req->params['site_id'] ?? '';
            if (!preg_match('/^[0-9a-f-]{36}$/i', $id)) { return Response::notFound(); }

            // The id FILTERS the already-derived set; it is not a lookup key.
            // RLS means a foreign id simply matches nothing (docs/45 §3.2a).
            $row = $db->one('SELECT * FROM mt_sites WHERE id = ?', [$id]);
            if ($row === null) { return Response::notFound(); }
            return Response::ok(['site' => P::site($row)]);
        }));

        $r->get('/api/v1/me/entitlements', $guard(C::BILLING_READ, function (Request $req, Database $db) {
            $rows = $db->query('SELECT * FROM mt_entitlements ORDER BY key');
            // Read-only, always. What they bought, never a gate they hit here.
            return Response::ok(['entitlements' => P::many([P::class, 'entitlement'], $rows)]);
        }));

        // ── retail plans: the customer's own products ───────────────────
        $r->get('/api/v1/me/plans', $guard(C::PLANS_READ, function (Request $req, Database $db) {
            $rows = (new \Dn\Policy\PlanRepository($db))->all();
            return Response::ok(['plans' => P::many([P::class, 'plan'], $rows)]);
        }));

        $r->post('/api/v1/me/plans', $guard(C::PLANS_WRITE, function (Request $req, Database $db, array $who) {
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
        }));

        $r->add('PATCH', '/api/v1/me/plans/{plan_id}', $guard(C::PLANS_WRITE, function (Request $req, Database $db, array $who) {
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
        }));

        $r->post('/api/v1/me/plans/{plan_id}/retire', $guard(C::PLANS_WRITE, function (Request $req, Database $db, array $who) {
            $id = $req->params['plan_id'] ?? '';
            if (!preg_match('/^[0-9a-f-]{36}$/i', $id)) { return Response::notFound(); }
            $plan = (new \Dn\Policy\PlanRepository($db))
                ->retire($id, $who['principal_id'], $req->ip);
            if ($plan === null) { return Response::notFound(); }
            return Response::ok(['plan' => P::plan($plan)]);
        }));

        // ── vouchers ────────────────────────────────────────────────────
        $r->get('/api/v1/me/vouchers', $guard(C::VOUCHERS_READ, function (Request $req, Database $db) {
            $state = $req->params['state'] ?? null;
            $rows = (new \Dn\Vouchers\VoucherService($db))->list();
            return Response::ok(['vouchers' => P::many([P::class, 'voucher'], $rows)]);
        }));

        $r->post('/api/v1/me/vouchers', $guard(C::VOUCHERS_ISSUE, function (Request $req, Database $db, array $who) {
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
        }));

        $r->post('/api/v1/me/vouchers/{voucher_id}/revoke', $guard(C::VOUCHERS_REVOKE, function (Request $req, Database $db, array $who) {
            $id = $req->params['voucher_id'] ?? '';
            if (!preg_match('/^[0-9a-f-]{36}$/i', $id)) { return Response::notFound(); }
            $out = (new \Dn\Vouchers\VoucherService($db))
                ->revoke($id, $who['principal_id'], $req->ip);
            if ($out === null) { return Response::notFound(); }

            return Response::accepted([
                'voucher'   => P::voucher($out['voucher']),
                'intent_id' => $out['intent_id'],
            ]);
        }));

        // ── sessions: connected devices ─────────────────────────────────
        $r->get('/api/v1/me/sessions', $guard(C::SESSIONS_READ, function (Request $req, Database $db) {
            $svc = new \Dn\Sessions\SessionService($db);
            $rows = ($req->body['all'] ?? false) ? $svc->all() : $svc->live();
            return Response::ok(['sessions' => P::many([P::class, 'session'], $rows)]);
        }));

        $r->get('/api/v1/me/usage', $guard(C::REPORTS_READ, function (Request $req, Database $db) {
            $u = (new \Dn\Sessions\SessionService($db))->usage();
            return Response::ok(['usage' => [
                'open_now'       => (int) ($u['open_now'] ?? 0),
                'sessions_total' => (int) ($u['sessions_total'] ?? 0),
                'bytes_in'       => (int) ($u['bytes_in'] ?? 0),
                'bytes_out'      => (int) ($u['bytes_out'] ?? 0),
            ]]);
        }));

        $r->post('/api/v1/me/sessions/{session_id}/disconnect', $guard(C::SESSIONS_DISCONNECT,
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
            }));

        // ── uplink: measured, shown, never acted on ─────────────────────
        $r->get('/api/v1/me/uplink', $guard(C::REPORTS_READ, function (Request $req, Database $db) {
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
        }));

        $r->get('/api/v1/me/intents', $guard(C::INTENTS_READ, function (Request $req, Database $db) {
            $rows = (new \Dn\Intents\IntentQueue($db))->forCustomer();
            // Deliberately says nothing about when queued work reaches a
            // router. B1 is unresolved (docs/49); neither push nor poll may
            // be asserted in a response, a label or a message.
            return Response::ok(['intents' => P::many([P::class, 'intent'], $rows)]);
        }));

        // ── staff: the operator's own people (migration 027, docs/116 §E.3) ─
        //
        // op.staff.manage on every one, which by constraint only an owner can
        // hold. The tenant is the context, the actor is $who — nothing about
        // WHO is doing this comes from the request. A principal id in the path
        // is a filter inside the caller's own operator: a foreign id matches
        // nothing and answers 404, exactly as a foreign site id does.
        $listing = static fn(Database $db, string $id): ?array =>
            $db->one('SELECT * FROM mt_principals WHERE id = ?', [$id]);
        $capsIn = static function (mixed $raw, string $kind): array|Response {
            $caps = $raw ?? [];
            if (!is_array($caps) || !C::known($caps)) {
                return Response::badRequest('capabilities must be a list of known op.* capabilities');
            }
            $caps = array_values(array_unique($caps));
            if ($kind === 'owner' && $caps !== []) {
                return Response::badRequest('an owner holds every capability; capabilities must be empty');
            }
            if (in_array(C::STAFF_MANAGE, $caps, true)) {
                return Response::badRequest('op.staff.manage cannot be granted; it belongs to owners');
            }
            return $caps;
        };
        $uuid = static fn(string $v): bool => (bool) preg_match('/^[0-9a-f-]{36}$/i', $v);

        $r->get('/api/v1/me/staff', $guard(C::STAFF_MANAGE, function (Request $req, Database $db) {
            $rows = $db->query('SELECT * FROM mt_principals ORDER BY kind, display_name');
            return Response::ok(['staff' => P::many([P::class, 'principalListing'], $rows)]);
        }));

        $r->post('/api/v1/me/staff', $guard(C::STAFF_MANAGE,
            function (Request $req, Database $db, array $who) use ($refusal, $listing, $capsIn) {
                $kind  = (string) ($req->body['kind'] ?? 'staff');
                $name  = trim((string) ($req->body['display_name'] ?? ''));
                $phone = $req->body['phone'] ?? null;
                if (!in_array($kind, ['owner', 'staff'], true)) { return Response::badRequest('kind must be owner or staff'); }
                if ($name === '') { return Response::badRequest('display_name required'); }
                if ($phone !== null && !is_string($phone)) { return Response::badRequest('phone must be a string'); }
                $caps = $capsIn($req->body['capabilities'] ?? [], $kind);
                if ($caps instanceof Response) { return $caps; }
                try {
                    $id = $db->attempt(fn($d) => $d->one(
                        'SELECT mt_principal_create(?,?,?,?::text[],?,?) AS id',
                        [$kind, $name, $phone, C::toPg($caps), $who['principal_id'], $req->ip]))['id'];
                } catch (\PDOException $e) {
                    if (($res = $refusal($e)) !== null) { return $res; }
                    throw $e;
                }
                return new Response(201, ['principal' => P::principalListing($listing($db, $id))]);
            }));

        $r->post('/api/v1/me/staff/{principal_id}/capabilities', $guard(C::STAFF_MANAGE,
            function (Request $req, Database $db, array $who) use ($refusal, $listing, $capsIn, $uuid) {
                $id = (string) ($req->params['principal_id'] ?? '');
                if (!$uuid($id)) { return Response::notFound(); }
                $caps = $capsIn($req->body['capabilities'] ?? [], 'staff');
                if ($caps instanceof Response) { return $caps; }
                try {
                    $changed = $db->attempt(fn($d) => $d->one(
                        'SELECT mt_principal_set_capabilities(?,?::text[],?,?) AS r',
                        [$id, C::toPg($caps), $who['principal_id'], $req->ip]))['r'];
                } catch (\PDOException $e) {
                    if (($res = $refusal($e)) !== null) { return $res; }
                    throw $e;
                }
                if ($changed === null) { return Response::notFound(); }
                return Response::ok(['changed' => (bool) $changed,
                                     'principal' => P::principalListing($listing($db, $id))]);
            }));

        $r->post('/api/v1/me/staff/{principal_id}/kind', $guard(C::STAFF_MANAGE,
            function (Request $req, Database $db, array $who) use ($refusal, $listing, $uuid) {
                $id   = (string) ($req->params['principal_id'] ?? '');
                $kind = (string) ($req->body['kind'] ?? '');
                if (!$uuid($id)) { return Response::notFound(); }
                if (!in_array($kind, ['owner', 'staff'], true)) { return Response::badRequest('kind must be owner or staff'); }
                try {
                    $changed = $db->attempt(fn($d) => $d->one(
                        'SELECT mt_principal_set_kind(?,?,?,?) AS r',
                        [$id, $kind, $who['principal_id'], $req->ip]))['r'];
                } catch (\PDOException $e) {
                    if (($res = $refusal($e)) !== null) { return $res; }
                    throw $e;
                }
                if ($changed === null) { return Response::notFound(); }
                return Response::ok(['changed' => (bool) $changed,
                                     'principal' => P::principalListing($listing($db, $id))]);
            }));

        $r->post('/api/v1/me/staff/{principal_id}/disable', $guard(C::STAFF_MANAGE,
            function (Request $req, Database $db, array $who) use ($refusal, $listing, $uuid) {
                $id = (string) ($req->params['principal_id'] ?? '');
                if (!$uuid($id)) { return Response::notFound(); }
                try {
                    $done = $db->attempt(fn($d) => $d->one(
                        'SELECT mt_principal_disable(?,?,?) AS r',
                        [$id, $who['principal_id'], $req->ip]))['r'];
                } catch (\PDOException $e) {
                    if (($res = $refusal($e)) !== null) { return $res; }
                    throw $e;
                }
                if ($done === null) { return Response::notFound(); }
                return Response::ok(['disabled' => (bool) $done,
                                     'principal' => P::principalListing($listing($db, $id))]);
            }));

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
