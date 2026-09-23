<?php
declare(strict_types=1);
namespace Dn\Api;

use Dn\Admin\AdminIdentityPort;
use Dn\Admin\AdminSession;
use Dn\Admin\Capability;
use Dn\Admin\Csrf;
use Dn\Admin\OnboardingAdmin;
use Dn\Admin\OnboardingRefused;
use Dn\Admin\RouterAdmin;
use Dn\Admin\RouterRefused;
use Dn\Admin\StaffAdmin;
use Dn\Admin\StaffIdentity;
use Dn\Admin\StaffRefused;
use Dn\Admin\StaffRole;
use Dn\Admin\StaffSessionPort;
use Dn\Devices\TunnelAddress;
use Dn\Http\Request;
use Dn\Http\Response;
use Dn\Http\Router;
use Dn\Http\Serializer\AdminProjection;
use Dn\Network\SignalReport;
use Dn\Vouchers\LifecycleReport;
use Dn\Runtime\Bindings;

/**
 * The DishNet staff surface — /api/v1/admin/* (docs/55 §J, docs/81 §8).
 *
 * Three rules hold everywhere in this file.
 *
 * 1. EVERY route declares a capability. `guard()` is the only way a handler
 *    runs, and it takes the capability as a required argument, so a route
 *    cannot be added without deciding who may reach it. There is no default
 *    allow.
 *
 * 2. NO ROUTE TOUCHES A ROUTER. Anything that would change a device is queued
 *    as an INTENT and delivered by Dn\Jobs — F2 as a code boundary, the same
 *    rule the customer surface obeys. tests/test_frozen_guards.php asserts
 *    nothing under Api/ can even reference the delivery port.
 *
 * 3. Authentication is whatever AdminIdentityPort was bound: DenyAllIdentity
 *    unless DN_STAFF_IDENTITY=dishnet selects the real provider (migration
 *    026, docs/114 G-B). The routes never decide WHO somebody is; they ask
 *    the port, and they never take a role, a customer or an operator from the
 *    request to establish staff authority.
 *
 * 4. Two kinds of write exist on this surface and they are kept apart. ESTATE
 *    writes are declared with their capabilities; SEVEN of them are bound —
 *    router register and assign (G-C, docs/118), router lifecycle and the
 *    push-configuration action (migration 028, docs/121), and creating an
 *    operator, its HotSpot service and its locations (migration 030,
 *    docs/125) — each one SECURITY DEFINER function on dnb_adminwrite with the
 *    authenticated subject as the actor — and the rest (plans, voucher
 *    batches, disconnect, principal creation) still answer 501. IDENTITY writes (a session row; the
 *    DishNet staff roster) are bound, each through one SECURITY DEFINER
 *    function that audits itself, and the manifest says so.
 *
 * A router write here is a ROW: a registry row, or an intent row that only the
 * worker turns into a connection. Nothing on this surface can reach a router:
 * rule 2 holds, and the action route queues an intent and nothing more.
 */
final class AdminRoutes
{
    /**
     * @param callable|null $reader fn(string $function, array $args = []): array
     *        Executes one of migration 019's projections. Null keeps the
     *        surface unreachable, which is what a process with no admin
     *        database connection should be.
     */
    public static function build(AdminIdentityPort $identity, Bindings $bindings,
                                 ?callable $reader = null,
                                 ?StaffSessionPort $issuer = null,
                                 ?StaffAdmin $staff = null,
                                 ?Csrf $csrf = null,
                                 ?RouterAdmin $routers = null,
                                 ?OnboardingAdmin $onboarding = null): Router
    {
        $r = new Router();
        // Cross-site protection for every mutating route, capability-gated or
        // not: SameSite=Strict on the cookie, an Origin that must match the
        // portal, and a JSON body (docs/114 §N R-4). Nothing here trusts a
        // browser about who it is; this only refuses a browser that was
        // tricked into asking.
        $csrf ??= Csrf::fromEnvironment();
        // No reader configured = no admin database connection in this process.
        // The honest answer is the same 501 the surface gave before the
        // boundary existed, NOT a fatal and NOT an empty list.
        $unconfigured = $reader === null;
        $reader ??= static fn(string $fn, array $args = []): array => [];

        /**
         * The only way into a handler.
         *
         * 401 when unauthenticated, 403 when authenticated without the
         * capability. 403 is correct HERE and wrong on the customer surface:
         * the customer rule is "404, never 403" because 403 confirms a record
         * exists to someone who should not know it. Staff already know the
         * estate exists; hiding a capability boundary from them would only
         * make a permission error look like a missing feature.
         */
        $guard = static function (string $capability, callable $handler) use ($identity, $csrf): callable {
            return static function (Request $req, ...$rest) use ($identity, $csrf, $capability, $handler) {
                $refusal = $csrf->check($req);
                if ($refusal !== null) { return $refusal; }
                $staff = $identity->identify($req);
                if ($staff === null) { return Response::unauthorized(); }
                // A password-only session under a provider that requires a
                // second factor may reach enrolment and nothing else
                // (docs/114 §N R-12). Distinct from 'forbidden' on purpose:
                // signing in again will not help, enrolling will.
                if ($staff->secondFactorPending) {
                    return new Response(403, ['error' => 'second_factor_required', 'capability' => $capability]);
                }
                if (!$staff->can($capability)) {
                    return new Response(403, ['error' => 'forbidden', 'capability' => $capability]);
                }
                return $handler($req, $staff, ...$rest);
            };
        };

        // ── health ──────────────────────────────────────────────────────
        // Reports which BINDINGS this process is running, so a screen can
        // never imply a router was contacted when a simulator answered.
        $r->get('/api/v1/admin/health', $guard(Capability::HEALTH_READ,
            static fn(Request $req, StaffIdentity $s) => Response::ok([
                'phase'    => $bindings->describe()['phase'],
                'bindings' => $bindings->describe(),
                'identity' => ['provider' => $identity->providerName(),
                               'role'     => $s->role->value],
            ])), auth: false);

        // Which router signals this system can actually produce, and which it
        // cannot. Declared server-side on purpose: a front-end change must not
        // be able to invent a green dot for a signal nothing measures.
        // No database read — this is structural truth about the build.
        $r->get('/api/v1/admin/network-signals', $guard(Capability::HEALTH_READ,
            static fn(Request $req, StaffIdentity $s) => Response::ok([
                'signals' => SignalReport::inventory(),
                'actions' => SignalReport::actions(),
                'summary' => SignalReport::summary(),
                // Which voucher states this system can actually reach. Declared
                // here so a screen cannot draw a lifecycle the product lacks.
                'voucher_lifecycle' => LifecycleReport::voucherStates(),
                'voucher_lifecycle_summary' => LifecycleReport::summary(),
            ])), auth: false);

        // ── estate reads ────────────────────────────────────────────────
        // Each goes through ONE controlled SECURITY DEFINER projection
        // (migration 019). The route never writes SQL against a base table:
        // dnb_admin holds EXECUTE and no table privilege whatsoever, so it
        // could not if it tried. RLS stays enabled and FORCED everywhere, and
        // no function reads a caller-supplied tenant context.
        $read = static function (string $cap, string $fn, string $kind) use ($guard, $reader, $unconfigured): array {
            return [$cap, $guard($cap, static function (Request $req) use ($fn, $kind, $reader, $unconfigured) {
                if ($unconfigured) { return self::estateReadNotAuthorized(); }
                $rows = $reader($fn);
                return Response::ok([$kind => AdminProjection::many($kind, $rows)]);
            })];
        };

        foreach ([
            ['/api/v1/admin/customers',       Capability::CUSTOMERS_READ, 'mt_admin_customers',       'customer'],
            ['/api/v1/admin/services',        Capability::SERVICES_READ,  'mt_admin_services',        'service'],
            ['/api/v1/admin/sites',           Capability::SITES_READ,     'mt_admin_sites',           'site'],
            ['/api/v1/admin/routers',         Capability::ROUTERS_READ,   'mt_admin_routers',         'router'],
            ['/api/v1/admin/plans',           Capability::PLANS_READ,     'mt_admin_plans',           'plan'],
            ['/api/v1/admin/vouchers',        Capability::VOUCHERS_READ,  'mt_admin_vouchers',        'voucher'],
            ['/api/v1/admin/voucher-batches', Capability::VOUCHERS_READ,  'mt_admin_voucher_batches', 'batch'],
            ['/api/v1/admin/sessions',        Capability::SESSIONS_READ,  'mt_admin_sessions',        'session'],
            ['/api/v1/admin/intents',         Capability::INTENTS_READ,   'mt_admin_intents',         'intent'],
            ['/api/v1/admin/audit',           Capability::AUDIT_READ,     'mt_admin_audit',           'audit'],
            // Migration 027: every operator's people. phone/email/credential withheld.
            ['/api/v1/admin/principals',      Capability::CUSTOMERS_READ, 'mt_admin_principals',      'principal'],
        ] as [$path, $cap, $fn, $kind]) {
            [, $handler] = $read($cap, $fn, $kind);
            $r->get($path, $handler, auth: false);
        }

        // Single-record reads. The id is a LOOKUP inside an estate the staff
        // member is already authorized for — it is not an authorization input,
        // and an unknown id simply returns nothing.
        $r->get('/api/v1/admin/customers/{customer_id}',
            $guard(Capability::CUSTOMERS_READ, static function (Request $req) use ($reader, $unconfigured) {
                if ($unconfigured) { return self::estateReadNotAuthorized(); }
                $rows = $reader('mt_admin_customer', [$req->params['customer_id'] ?? '']);
                return $rows === [] ? Response::notFound()
                                    : Response::ok(['customer' => AdminProjection::customer($rows[0])]);
            }), auth: false);

        $r->get('/api/v1/admin/routers/{device_id}',
            $guard(Capability::ROUTERS_READ, static function (Request $req) use ($reader, $unconfigured) {
                if ($unconfigured) { return self::estateReadNotAuthorized(); }
                $rows = $reader('mt_admin_router', [$req->params['device_id'] ?? '']);
                return $rows === [] ? Response::notFound()
                                    : Response::ok(['router' => AdminProjection::router($rows[0])]);
            }), auth: false);

        // Voucher detail returns EXACTLY the list's fields. Asking for one row
        // must not be a way to reach a column the list withholds — the code
        // above all, which stays out of every Admin path (Decision 3).
        $r->get('/api/v1/admin/vouchers/{voucher_id}',
            $guard(Capability::VOUCHERS_READ, static function (Request $req) use ($reader, $unconfigured) {
                if ($unconfigured) { return self::estateReadNotAuthorized(); }
                $rows = $reader('mt_admin_voucher', [$req->params['voucher_id'] ?? '']);
                return $rows === [] ? Response::notFound()
                                    : Response::ok(['voucher' => AdminProjection::voucherDetail($rows[0])]);
            }), auth: false);

        // ── estate writes: the four bound router writes (G-C, docs/121) ──
        self::routers($r, $guard, $routers);

        // ── estate writes: operator onboarding (migration 030, docs/125) ──
        self::onboarding($r, $guard, $onboarding);

        // ── estate writes still declared-unbound ────────────────────────
        // Declared with their capabilities so the matrix is complete and
        // testable. Each returns an honest 501: plans and voucher batches wait
        // for G-C2, disconnect for the replay fix (docs/108), principal
        // creation for its own instruction (docs/116 J-1). Sites are bound
        // since migration 030 (docs/125).
        foreach ([
            ['POST', '/api/v1/admin/plans',                        Capability::PLANS_WRITE],
            ['POST', '/api/v1/admin/voucher-batches',              Capability::VOUCHERS_GENERATE],
            ['POST', '/api/v1/admin/sessions/{session_id}/disconnect', Capability::SESSIONS_DISCONNECT],
            // Migration 027 built mt_admin_principal_create (target operator explicit);
            // binding this route is its own instruction (docs/116 §J J-1).
            ['POST', '/api/v1/admin/customers/{customer_id}/principals', Capability::CUSTOMERS_WRITE],
        ] as [$method, $path, $cap]) {
            $r->add($method, $path, $guard($cap, static fn() => self::estateReadNotAuthorized()), auth: false);
        }

        // ── session ─────────────────────────────────────────────────────
        // The login boundary. Six routes, NONE of them capability-gated,
        // because a capability is what you get BY authenticating -- gating the
        // login on one would be a lock whose key is behind the lock.
        //
        // $issuer is null unless StaffIdentityFactory bound a provider that can
        // start a session: the development identity inside its gate, or the
        // real provider under DN_STAFF_IDENTITY=dishnet. Bound to nothing, the
        // login route has nothing to mint with, and says so.
        self::session($r, $identity, $issuer, $csrf);

        // ── DishNet staff roster ────────────────────────────────────────
        // The one capability-gated WRITE surface: Admin only (staff.manage).
        // Every act is a definer function that audits itself; the actor is the
        // authenticated subject, never a field of the request.
        self::staff($r, $guard, $reader, $unconfigured, $staff);

        return $r;
    }

    /**
     * POST /routers                       register a router staged at the bench      201 | 400 | 409 | 501
     * POST /routers/{device_id}/assign    assign it to an operator (+ site, name)     200 | 400 | 404 | 409 | 501
     * POST /routers/{device_id}/state     record the lifecycle state a person saw   200 | 400 | 404 | 409 | 501
     * POST /routers/{device_id}/actions   queue an INTENT (push_config only)     202 | 200 | 400 | 404 | 409 | 501
     *
     * Bound only where the process holds an Admin write connection; otherwise
     * 501 says so. The actor is the authenticated subject: a body that tries
     * to supply one is refused rather than ignored, so nothing silently
     * accepts a forged attribution. A tunnel address must lie inside the
     * management network, so a row RestClient would refuse to talk to cannot
     * be registered (docs/118 D-6, D-11). Lifecycle legality is migration
     * 012's trigger's decision (409 with its reason); the action route queues
     * a row for the worker and touches no router (F2; docs/121 D-9, D-10).
     */
    /**
     * Operator onboarding (migration 030, docs/125): create an operator, start
     * its HotSpot service, add a location. Each is one SECURITY DEFINER
     * function on dnb_adminwrite with the authenticated subject as the actor.
     *
     * A body carrying a field the server derives is refused, never ignored. An
     * idempotency key is required: the same key for the same request answers
     * 200 with the first result and writes nothing (RULE I-1); a new act is 201.
     * A location carries NO operator — the function reads it from the service
     * row — so a customer_id in its body is a forgery attempt and is refused.
     */
    private static function onboarding(Router $r, callable $guard, ?OnboardingAdmin $onboarding): void
    {
        $off = static fn() => new Response(501, [
            'error'  => 'onboarding_writes_unavailable',
            'detail' => 'operator, service and location creation are bound only where this process '
                      . 'holds an Admin write connection (dnb_adminwrite); it holds none',
        ]);
        $uuid = '/^[0-9a-f]{8}(-[0-9a-f]{4}){3}-[0-9a-f]{12}$/i';
        $derived = static function (array $body, array $keys): ?Response {
            foreach ($keys as $k) {
                if (array_key_exists($k, $body)) {
                    return Response::badRequest("{$k} is not accepted: it is derived on the server, never from the request");
                }
            }
            return null;
        };
        $key = static function (array $body): string|Response {
            $k = $body['idempotency_key'] ?? null;
            if (!is_string($k) || !preg_match('/^[A-Za-z0-9._:-]{8,128}$/', trim($k))) {
                return Response::badRequest('idempotency_key is required: 8 to 128 letters, digits, dots, underscores, colons or dashes');
            }
            return trim($k);
        };
        $name = static function (array $body, string $what): string|Response {
            $n = is_string($body['name'] ?? null) ? trim($body['name']) : '';
            if ($n === '' || mb_strlen($n) > 120) { return Response::badRequest("name is required: the {$what}'s name, 1 to 120 characters"); }
            return $n;
        };
        $refused = static fn(OnboardingRefused $e) => new Response(409, ['error' => 'refused', 'detail' => $e->getMessage()]);

        $r->post('/api/v1/admin/customers', $guard(Capability::CUSTOMERS_WRITE,
            static function (Request $req, StaffIdentity $s) use ($onboarding, $off, $derived, $key, $name, $refused) {
                if ($onboarding === null) { return $off(); }
                $b = $req->body;
                if (($bad = $derived($b, ['id', 'actor', 'created_by', 'status', 'created_at', 'radius_ref', 'ucrm_client_id', 'customer_id'])) !== null) { return $bad; }
                if (($n = $name($b, 'operator')) instanceof Response) { return $n; }
                if (($k = $key($b)) instanceof Response) { return $k; }
                try {
                    $res = $onboarding->createOperator($n, $k, $s->subject);
                } catch (OnboardingRefused $e) { return $refused($e); }
                return new Response($res['replayed'] ? 200 : 201,
                    ['customer' => AdminProjection::customer($res['customer']), 'replayed' => $res['replayed']]);
            }), auth: false);

        $r->post('/api/v1/admin/customers/{customer_id}/services', $guard(Capability::SERVICES_WRITE,
            static function (Request $req, StaffIdentity $s) use ($onboarding, $off, $derived, $key, $uuid, $refused) {
                if ($onboarding === null) { return $off(); }
                $id = (string) ($req->params['customer_id'] ?? '');
                if (!preg_match($uuid, $id)) { return Response::notFound(); }
                $b = $req->body;
                if (($bad = $derived($b, ['id', 'actor', 'customer_id', 'status', 'started_at', 'ended_at'])) !== null) { return $bad; }
                if (($b['kind'] ?? 'mikrotik_hotspot') !== 'mikrotik_hotspot') {
                    return Response::badRequest('kind must be mikrotik_hotspot, the only service this platform provides');
                }
                if (($k = $key($b)) instanceof Response) { return $k; }
                try {
                    $res = $onboarding->startService($id, $k, $s->subject);
                } catch (OnboardingRefused $e) { return $refused($e); }
                if ($res === null) { return Response::notFound(); }
                return new Response($res['replayed'] ? 200 : 201,
                    ['service' => AdminProjection::service($res['service']), 'replayed' => $res['replayed']]);
            }), auth: false);

        $r->post('/api/v1/admin/sites', $guard(Capability::SITES_WRITE,
            static function (Request $req, StaffIdentity $s) use ($onboarding, $off, $derived, $key, $name, $uuid, $refused) {
                if ($onboarding === null) { return $off(); }
                $b = $req->body;
                // Derive, never accept (docs/105): the operator comes from the service row.
                if (($bad = $derived($b, ['id', 'actor', 'customer_id', 'operator', 'operator_id', 'created_at'])) !== null) { return $bad; }
                $svc = $b['service_id'] ?? null;
                if (!is_string($svc) || !preg_match($uuid, $svc)) { return Response::badRequest('service_id is required: the service the location belongs to'); }
                if (($n = $name($b, 'location')) instanceof Response) { return $n; }
                $loc = $b['location'] ?? null;
                if ($loc !== null && (!is_string($loc) || mb_strlen(trim($loc)) > 200)) {
                    return Response::badRequest('location must be a description of up to 200 characters');
                }
                if (($k = $key($b)) instanceof Response) { return $k; }
                try {
                    $res = $onboarding->addLocation($svc, $n, is_string($loc) ? trim($loc) : null, $k, $s->subject);
                } catch (OnboardingRefused $e) { return $refused($e); }
                if ($res === null) { return new Response(409, ['error' => 'refused', 'detail' => 'no such service']); }
                return new Response($res['replayed'] ? 200 : 201,
                    ['site' => AdminProjection::site($res['site']), 'replayed' => $res['replayed']]);
            }), auth: false);
    }

    private static function routers(Router $r, callable $guard, ?RouterAdmin $routers): void
    {
        $off = static fn() => new Response(501, [
            'error'  => 'router_writes_unavailable',
            'detail' => 'router registration and assignment are bound only where this process '
                      . 'holds an Admin write connection (dnb_adminwrite); it holds none',
        ]);
        $uuid = '/^[0-9a-f]{8}(-[0-9a-f]{4}){3}-[0-9a-f]{12}$/i';
        /** Fields the server derives. Present in a body, they are a forgery attempt, not a hint. */
        $derived = static function (array $body, array $keys): ?Response {
            foreach ($keys as $k) {
                if (array_key_exists($k, $body)) {
                    return Response::badRequest("{$k} is not accepted: it is derived on the server, never from the request");
                }
            }
            return null;
        };

        $r->post('/api/v1/admin/routers', $guard(Capability::ROUTERS_REGISTER,
            static function (Request $req, StaffIdentity $s) use ($routers, $off, $derived) {
                if ($routers === null) { return $off(); }
                $b = $req->body;
                if (($bad = $derived($b, ['staged_by', 'actor', 'state', 'customer_id', 'site_id', 'id'])) !== null) { return $bad; }
                $serial = is_string($b['serial'] ?? null) ? trim($b['serial']) : '';
                $model  = is_string($b['model'] ?? null) ? trim($b['model']) : '';
                if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{3,63}$/', $serial)) {
                    return Response::badRequest('serial is required: 4-64 letters, digits, dot, underscore or dash');
                }
                if ($model === '' || mb_strlen($model) > 64) { return Response::badRequest('model is required (up to 64 characters)'); }
                $ros = $b['ros_version'] ?? null;
                if ($ros !== null && (!is_string($ros) || trim($ros) === '' || mb_strlen($ros) > 32)) {
                    return Response::badRequest('ros_version must be a string of up to 32 characters');
                }
                $key = $b['wg_pubkey'] ?? null;
                if ($key !== null && (!is_string($key) || !preg_match('#^[A-Za-z0-9+/]{43}=$#', $key))) {
                    return Response::badRequest('wg_pubkey must be a WireGuard public key: 32 bytes, base64');
                }
                $tip = $b['tunnel_ip'] ?? null;
                if ($tip !== null && (!is_string($tip) || !TunnelAddress::isRegistrable($tip))) {
                    return Response::badRequest('tunnel_ip must be one address inside the management network ' . TunnelAddress::NETWORK);
                }
                try {
                    $row = $routers->register($serial, $model, $ros, $key, $tip, $s->subject);
                } catch (RouterRefused $e) {
                    return new Response(409, ['error' => 'refused', 'detail' => $e->getMessage()]);
                }
                return new Response(201, ['router' => AdminProjection::router($row)]);
            }), auth: false);

        $r->post('/api/v1/admin/routers/{device_id}/assign', $guard(Capability::ROUTERS_ASSIGN,
            static function (Request $req, StaffIdentity $s) use ($routers, $off, $derived, $uuid) {
                if ($routers === null) { return $off(); }
                $id = (string) ($req->params['device_id'] ?? '');
                if (!preg_match($uuid, $id)) { return Response::notFound(); }
                $b = $req->body;
                if (($bad = $derived($b, ['actor', 'staged_by', 'state', 'claimed_at'])) !== null) { return $bad; }
                $cust = $b['customer_id'] ?? null;
                if (!is_string($cust) || !preg_match($uuid, $cust)) {
                    return Response::badRequest('customer_id, the target operator, is required');
                }
                $site = $b['site_id'] ?? null;
                if ($site !== null && (!is_string($site) || !preg_match($uuid, $site))) { return Response::badRequest('site_id must be a uuid'); }
                $name = $b['name'] ?? null;
                if ($name !== null && (!is_string($name) || mb_strlen($name) > 64)) { return Response::badRequest('name must be up to 64 characters'); }
                try {
                    $row = $routers->assign($id, $cust, $site, $name, $s->subject);
                } catch (RouterRefused $e) {
                    return new Response(409, ['error' => 'refused', 'detail' => $e->getMessage()]);
                }
                if ($row === null) { return Response::notFound(); }
                return Response::ok(['router' => AdminProjection::router($row)]);
            }), auth: false);

        // ── lifecycle (docs/121 D-3, D-4, D-10) ──────────────────────────
        // Records what a person OBSERVED. Legality is migration 012's
        // trigger's decision and comes back as 409 with the trigger's own
        // reason; the 400 here covers only words staff may never record
        // (diverged is computed; registered is the start). A state the row
        // already holds is 200 with no audit row (RULE I-1). Nothing here
        // contacts a router.
        $r->post('/api/v1/admin/routers/{device_id}/state', $guard(Capability::ROUTERS_LIFECYCLE,
            static function (Request $req, StaffIdentity $s) use ($routers, $off, $derived, $uuid) {
                if ($routers === null) { return $off(); }
                $id = (string) ($req->params['device_id'] ?? '');
                if (!preg_match($uuid, $id)) { return Response::notFound(); }
                $b = $req->body;
                if (($bad = $derived($b, ['actor', 'staged_by', 'customer_id', 'site_id', 'claimed_at', 'last_seen_at'])) !== null) { return $bad; }
                $state = $b['state'] ?? null;
                if (!is_string($state) || !in_array($state, RouterAdmin::RECORDABLE_STATES, true)) {
                    return Response::badRequest('state must be one a staff member may record: '
                        . implode(', ', RouterAdmin::RECORDABLE_STATES));
                }
                try {
                    $row = $routers->setState($id, $state, $s->subject);
                } catch (RouterRefused $e) {
                    return new Response(409, ['error' => 'refused', 'detail' => $e->getMessage()]);
                }
                if ($row === null) { return Response::notFound(); }
                return Response::ok(['router' => AdminProjection::router($row)]);
            }), auth: false);

        // ── the action: an INTENT, never a command (F2; docs/121 D-9) ────
        // SignalReport::actions() is the one source of which actions exist
        // and which are available. Exactly one is executable here —
        // push_config, a device.provision intent for the worker — and it must
        // ALSO be marked available in the inventory; anything else answers 501
        // with the inventory's own reason. The idempotency key is required:
        // the same key on a retry returns the same job (202 new, 200 replay).
        $r->post('/api/v1/admin/routers/{device_id}/actions', $guard(Capability::ROUTERS_ACT,
            static function (Request $req, StaffIdentity $s) use ($routers, $off, $derived, $uuid) {
                if ($routers === null) { return $off(); }
                $id = (string) ($req->params['device_id'] ?? '');
                if (!preg_match($uuid, $id)) { return Response::notFound(); }
                $b = $req->body;
                if (($bad = $derived($b, ['actor', 'staged_by', 'customer_id', 'device_id', 'kind', 'payload', 'state', 'target_id'])) !== null) { return $bad; }
                $inventory = SignalReport::actions();
                $known = null;
                foreach ($inventory as $a) { if (($b['action'] ?? null) === $a['key']) { $known = $a; } }
                if ($known === null) {
                    return Response::badRequest('action must be one of: ' . implode(', ', array_column($inventory, 'key')));
                }
                if ($known['key'] !== 'push_config' || !$known['available']) {
                    return new Response(501, ['error' => 'router_action_not_available',
                        'action' => $known['key'], 'detail' => $known['reason']]);
                }
                $key = $b['idempotency_key'] ?? null;
                if (!is_string($key) || !preg_match('/^[A-Za-z0-9._:-]{8,128}$/', $key)) {
                    return Response::badRequest('idempotency_key is required: 8-128 letters, digits, dot, underscore, '
                        . 'colon or dash; the same key on a retry returns the same job');
                }
                try {
                    $out = $routers->requestProvision($id, $key, $s->subject);
                } catch (RouterRefused $e) {
                    return new Response(409, ['error' => 'refused', 'detail' => $e->getMessage()]);
                }
                if ($out === null) { return Response::notFound(); }
                return new Response($out['replayed'] ? 200 : 202,
                    ['intent' => AdminProjection::intent($out['intent']), 'replayed' => $out['replayed']]);
            }), auth: false);
    }

    /**
     * GET    /session               who am I              200 identity | 401
     * POST   /session               log in                200 + cookie | 400 | 401 | 403 | 501
     * DELETE /session               log out               204, always
     * POST   /session/password      change OWN password   200 | 401 | 403 | 409 | 501
     * POST   /session/totp          enrol OWN authenticator, key returned once
     * POST   /session/totp/confirm  prove the authenticator; the session becomes two-factor
     *
     * The last three are self-service: the caller is identified by ITS OWN
     * session cookie inside the provider, never by an id in the request.
     */
    private static function session(Router $r, AdminIdentityPort $identity,
                                    ?StaffSessionPort $issuer, Csrf $csrf): void
    {
        $r->get('/api/v1/admin/session', static function (Request $req) use ($identity, $issuer) {
            $staff = $identity->identify($req);
            if ($staff === null) {
                // The panel needs to know WHICH unauthenticated state to draw:
                // a role picker, a credential form, or "authentication
                // unavailable". That is a property of the deployment, not of
                // the visitor, so it is safe to state. It names no user, no
                // secret, and nothing about any account.
                return new Response(401, [
                    'error'            => 'unauthenticated',
                    'provider'         => $identity->providerName(),
                    'can_authenticate' => $issuer !== null,
                ] + ($issuer === null ? ['mode' => 'none', 'roles' => []] : $issuer->loginSurface()));
            }
            return Response::ok(['identity' => $staff->describe()]);
        }, auth: false);

        // A mutating session route: cross-site check first, then the provider.
        $mutating = static function (string $method, string $path, callable $h) use ($r, $csrf): void {
            $r->add($method, $path, static function (Request $req, ...$rest) use ($csrf, $h) {
                return $csrf->check($req) ?? $h($req, ...$rest);
            }, auth: false);
        };

        // NOT 401, and NOT a fallback. There is no identity provider that can
        // authenticate anybody in this deployment, which is a configuration
        // fact DishNet staff must see rather than a credential problem the
        // visitor could fix by trying again.
        $unavailable = static fn() => new Response(501, [
            'error'    => 'production_authentication_unavailable',
            'detail'   => 'no staff identity provider is configured; this deployment '
                        . 'authenticates nobody (W-4 posture: set DN_STAFF_IDENTITY=dishnet '
                        . 'to bind the migration 026 provider)',
            'provider' => $identity->providerName(),
        ]);

        $mutating('POST', '/api/v1/admin/session', static fn(Request $req) =>
            $issuer === null ? $unavailable() : $issuer->login($req));

        $mutating('DELETE', '/api/v1/admin/session', static function (Request $req) use ($issuer) {
            // Always 204, whether or not anything was signed in. A logout that
            // reported "you were not logged in" would answer a question the
            // caller has no business asking about somebody else's cookie.
            if ($issuer === null) {
                return new Response(204, [], ['Set-Cookie' => AdminSession::clearCookie(self::isHttps($req))]);
            }
            return $issuer->logout($req);
        });

        $mutating('POST', '/api/v1/admin/session/password', static fn(Request $req) =>
            $issuer === null ? $unavailable() : $issuer->changePassword($req));
        $mutating('POST', '/api/v1/admin/session/totp', static fn(Request $req) =>
            $issuer === null ? $unavailable() : $issuer->totpEnrol($req));
        $mutating('POST', '/api/v1/admin/session/totp/confirm', static fn(Request $req) =>
            $issuer === null ? $unavailable() : $issuer->totpConfirm($req));
    }

    /**
     * GET  /staff                            the roster (projection mt_admin_staff: no hash, no secret)
     * POST /staff                            create; the generated password is returned ONCE
     * POST /staff/{staff_id}/disable         revokes every live session in the same transaction
     * POST /staff/{staff_id}/enable
     * POST /staff/{staff_id}/role            {role}; revokes live sessions
     * POST /staff/{staff_id}/password        a NEW generated password, returned once; revokes live sessions
     * POST /staff/{staff_id}/totp-reset      clears the authenticator; revokes live sessions
     *
     * All Admin only (staff.manage). Bound only when the real provider is: a
     * development identity may look at the estate, not mint DishNet staff.
     */
    private static function staff(Router $r, callable $guard, callable $reader,
                                  bool $unconfigured, ?StaffAdmin $staff): void
    {
        $off = static fn() => new Response(501, [
            'error'  => 'staff_management_unavailable',
            'detail' => 'DishNet staff management is bound only under the real identity '
                      . 'provider (DN_STAFF_IDENTITY=dishnet); this process runs '
                      . ($staff === null ? 'without it' : 'without an Admin read connection'),
        ]);

        $r->get('/api/v1/admin/staff', $guard(Capability::STAFF_MANAGE,
            static function (Request $req) use ($reader, $unconfigured, $staff, $off) {
                if ($staff === null || $unconfigured) { return $off(); }
                $rows = [];
                foreach ($reader('mt_admin_staff') as $x) {
                    $rows[] = [
                        'id' => $x['id'], 'username' => $x['username'], 'display_name' => $x['display_name'],
                        'role' => $x['role'], 'status' => $x['status'],
                        'totp_enrolled' => (bool) $x['totp_enrolled'],
                        'created_at' => $x['created_at'], 'last_login_at' => $x['last_login_at'],
                        'disabled_at' => $x['disabled_at'],
                    ];
                }
                return Response::ok(['staff' => $rows]);
            }), auth: false);

        $r->post('/api/v1/admin/staff', $guard(Capability::STAFF_MANAGE,
            static function (Request $req, StaffIdentity $s) use ($staff, $off) {
                if ($staff === null) { return $off(); }
                foreach (['username', 'display_name', 'role'] as $k) {
                    if (!is_string($req->body[$k] ?? null) || trim($req->body[$k]) === '') {
                        return Response::badRequest('username, display_name and role are required');
                    }
                }
                $role = StaffRole::tryFrom($req->body['role']);
                if ($role === null) { return Response::badRequest('role must be one of: ' . implode(', ', self::roleNames())); }
                try {
                    $made = $staff->create($req->body['username'], $req->body['display_name'], $role, $s->subject);
                } catch (StaffRefused $e) {
                    return new Response(409, ['error' => 'refused', 'detail' => $e->getMessage()]);
                }
                return new Response(201, [
                    'staff'    => ['id' => $made['id'], 'username' => strtolower(trim($req->body['username'])),
                                   'role' => $role->value, 'status' => 'active'],
                    // Shown once. Never logged, never audited, never retrievable.
                    'password' => $made['password'],
                ]);
            }), auth: false);

        $act = static function (string $verb, callable $do) use ($r, $guard, $staff, $off): void {
            $r->post('/api/v1/admin/staff/{staff_id}/' . $verb, $guard(Capability::STAFF_MANAGE,
                static function (Request $req, StaffIdentity $s) use ($staff, $off, $do) {
                    if ($staff === null) { return $off(); }
                    $id = (string) ($req->params['staff_id'] ?? '');
                    if (!preg_match('/^[0-9a-f]{8}(-[0-9a-f]{4}){3}-[0-9a-f]{12}$/i', $id)) { return Response::notFound(); }
                    try {
                        return $do($staff, $id, $s, $req);
                    } catch (StaffRefused $e) {
                        return new Response(409, ['error' => 'refused', 'detail' => $e->getMessage()]);
                    }
                }), auth: false);
        };

        $act('disable', static fn(StaffAdmin $a, string $id, StaffIdentity $s) =>
            Response::ok(['staff_id' => $id, 'disabled' => $a->disable($id, $s->subject)]));
        $act('enable', static fn(StaffAdmin $a, string $id, StaffIdentity $s) =>
            Response::ok(['staff_id' => $id, 'enabled' => $a->enable($id, $s->subject)]));
        $act('role', static function (StaffAdmin $a, string $id, StaffIdentity $s, Request $req) {
            $role = StaffRole::tryFrom((string) ($req->body['role'] ?? ''));
            if ($role === null) { return Response::badRequest('role must be one of: ' . implode(', ', self::roleNames())); }
            return Response::ok(['staff_id' => $id, 'role' => $role->value, 'changed' => $a->setRole($id, $role, $s->subject)]);
        });
        $act('password', static function (StaffAdmin $a, string $id, StaffIdentity $s) {
            $pw = $a->resetPassword($id, $s->subject);
            return $pw === null ? Response::notFound() : Response::ok(['staff_id' => $id, 'password' => $pw]);
        });
        $act('totp-reset', static fn(StaffAdmin $a, string $id, StaffIdentity $s) =>
            Response::ok(['staff_id' => $id, 'reset' => $a->totpReset($id, $s->subject)]));
    }

    /** @return list<string> */
    private static function roleNames(): array
    {
        return array_map(static fn(StaffRole $r) => $r->value, StaffRole::cases());
    }

    /** For the no-provider DELETE only; each provider judges its own transport. */
    private static function isHttps(Request $req): bool
    {
        return $req->https || ($req->header('X-Forwarded-Proto') ?? '') === 'https';
    }

    /**
     * 501, with the reason named.
     *
     * Not 200-with-empty and not 500. A screen showing "no routers" when the
     * truth is "this process may not read across customers yet" is a lie the
     * UI would repeat confidently.
     */
    private static function estateReadNotAuthorized(): Response
    {
        return new Response(501, [
            'error'  => 'estate_access_not_authorized',
            'detail' => 'the cross-customer privilege path is a separate decision and has not been taken',
            'see'    => 'docs/81',
        ]);
    }

    /** Every capability this surface actually gates on — for the guard test. */
    public static function declaredCapabilities(): array
    {
        return [
            Capability::HEALTH_READ, Capability::CUSTOMERS_READ, Capability::CUSTOMERS_WRITE,
            Capability::SERVICES_WRITE, Capability::SITES_READ,
            Capability::SITES_WRITE, Capability::ROUTERS_READ, Capability::ROUTERS_REGISTER,
            Capability::ROUTERS_ASSIGN, Capability::ROUTERS_LIFECYCLE, Capability::ROUTERS_ACT, Capability::PLANS_READ,
            Capability::PLANS_WRITE, Capability::VOUCHERS_READ, Capability::VOUCHERS_GENERATE,
            Capability::SESSIONS_READ, Capability::SESSIONS_DISCONNECT,
            Capability::INTENTS_READ, Capability::AUDIT_READ,
            Capability::STAFF_MANAGE,
        ];
    }
}
