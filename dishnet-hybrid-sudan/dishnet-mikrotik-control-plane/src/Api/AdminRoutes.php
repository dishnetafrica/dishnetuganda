<?php
declare(strict_types=1);
namespace Dn\Api;

use Dn\Admin\AdminIdentityPort;
use Dn\Admin\Capability;
use Dn\Admin\StaffIdentity;
use Dn\Http\Request;
use Dn\Http\Response;
use Dn\Http\Router;
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
 * 3. Authentication is DenyAllIdentity until a staff identity provider is
 *    selected (docs/81 §8.4). Every route below therefore answers 401 in every
 *    deployment today. That is the intended state, not an unfinished one.
 */
final class AdminRoutes
{
    public static function build(AdminIdentityPort $identity, Bindings $bindings): Router
    {
        $r = new Router();

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
        $guard = static function (string $capability, callable $handler) use ($identity): callable {
            return static function (Request $req, ...$rest) use ($identity, $capability, $handler) {
                $staff = $identity->identify($req);
                if ($staff === null) { return Response::unauthorized(); }
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

        // ── estate reads ────────────────────────────────────────────────
        // Each is declared, capability-gated, and currently blocked at the
        // DATABASE by design: staff read across customers, every tenant table
        // has FORCE ROW LEVEL SECURITY, and the privilege path for a
        // cross-customer read is a separate decision that has NOT been taken
        // (docs/81, "the estate-read privilege"). These routes therefore
        // return an explicit, honest 501 rather than an empty list — an empty
        // list would look like "no customers exist", which is exactly the kind
        // of plausible-looking wrong answer this project keeps finding.
        foreach ([
            ['/api/v1/admin/customers',       Capability::CUSTOMERS_READ],
            ['/api/v1/admin/customers/{customer_id}', Capability::CUSTOMERS_READ],
            ['/api/v1/admin/sites',           Capability::SITES_READ],
            ['/api/v1/admin/routers',         Capability::ROUTERS_READ],
            ['/api/v1/admin/routers/{device_id}', Capability::ROUTERS_READ],
            ['/api/v1/admin/plans',           Capability::PLANS_READ],
            ['/api/v1/admin/vouchers',        Capability::VOUCHERS_READ],
            ['/api/v1/admin/voucher-batches', Capability::VOUCHERS_READ],
            ['/api/v1/admin/sessions',        Capability::SESSIONS_READ],
            ['/api/v1/admin/intents',         Capability::INTENTS_READ],
            ['/api/v1/admin/audit',           Capability::AUDIT_READ],
        ] as [$path, $cap]) {
            $r->get($path, $guard($cap, static fn() => self::estateReadNotAuthorized()), auth: false);
        }

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
        ] as [$method, $path, $cap]) {
            $r->add($method, $path, $guard($cap, static fn() => self::estateReadNotAuthorized()), auth: false);
        }

        return $r;
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
        ];
    }
}
