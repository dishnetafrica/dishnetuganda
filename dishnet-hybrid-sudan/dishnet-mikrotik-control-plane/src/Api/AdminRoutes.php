<?php
declare(strict_types=1);
namespace Dn\Api;

use Dn\Admin\AdminIdentityPort;
use Dn\Admin\AdminSession;
use Dn\Admin\Capability;
use Dn\Admin\Csrf;
use Dn\Admin\StaffAdmin;
use Dn\Admin\StaffIdentity;
use Dn\Admin\StaffRefused;
use Dn\Admin\StaffRole;
use Dn\Admin\StaffSessionPort;
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
 *    writes (routers, sites, plans, vouchers, disconnect) are declared and
 *    every one answers 501 — binding them is a separate gate. IDENTITY writes
 *    (a session row; the DishNet staff roster) are bound, each through one
 *    SECURITY DEFINER function that audits itself, and the manifest says so.
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
                                 ?Csrf $csrf = null): Router
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

        // ── mutations ───────────────────────────────────────────────────
        // Declared with their capabilities so the matrix is complete and
        // testable. Each returns the same honest 501: the write paths exist as
        // SECURITY DEFINER functions reachable by dnb_admin, but binding an
        // HTTP route to them requires the staff identity provider first —
        // otherwise the audit row has no actor to name (docs/72 §A.4 found
        // mt_device_assign writes no audit row at all).
        foreach ([
            ['POST', '/api/v1/admin/routers',                      Capability::ROUTERS_REGISTER],
            ['POST', '/api/v1/admin/routers/{device_id}/assign',   Capability::ROUTERS_ASSIGN],
            ['POST', '/api/v1/admin/routers/{device_id}/actions',  Capability::ROUTERS_ACT],
            ['POST', '/api/v1/admin/sites',                        Capability::SITES_WRITE],
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
            Capability::HEALTH_READ, Capability::CUSTOMERS_READ, Capability::SITES_READ,
            Capability::SITES_WRITE, Capability::ROUTERS_READ, Capability::ROUTERS_REGISTER,
            Capability::ROUTERS_ASSIGN, Capability::ROUTERS_ACT, Capability::PLANS_READ,
            Capability::PLANS_WRITE, Capability::VOUCHERS_READ, Capability::VOUCHERS_GENERATE,
            Capability::SESSIONS_READ, Capability::SESSIONS_DISCONNECT,
            Capability::INTENTS_READ, Capability::AUDIT_READ,
            Capability::STAFF_MANAGE,
        ];
    }
}
