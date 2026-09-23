<?php
declare(strict_types=1);
namespace Dn\Admin;

/**
 * Every distinct thing a staff member may do.
 *
 * Named, enumerated and asserted — so a new admin route cannot be added
 * without deciding which roles may reach it. A route that forgot to declare a
 * capability is denied by AdminRoutes, not allowed by default.
 */
final class Capability
{
    public const HEALTH_READ         = 'health.read';

    public const CUSTOMERS_READ      = 'customers.read';
    public const CUSTOMERS_WRITE     = 'customers.write';

    public const SERVICES_READ       = 'services.read';
    public const SITES_READ          = 'sites.read';
    public const SITES_WRITE         = 'sites.write';

    public const ROUTERS_READ        = 'routers.read';
    public const ROUTERS_REGISTER    = 'routers.register';
    public const ROUTERS_ASSIGN      = 'routers.assign';
    /** Record where a router is in its lifecycle: a registry row a person observed, never a command (docs/121 D-2). */
    public const ROUTERS_LIFECYCLE   = 'routers.lifecycle';
    /** Queue an intent against a router. Never a direct command — F2. */
    public const ROUTERS_ACT         = 'routers.act';

    public const PLANS_READ          = 'plans.read';
    public const PLANS_WRITE         = 'plans.write';
    public const PROFILES_READ       = 'profiles.read';
    public const PROFILES_WRITE      = 'profiles.write';

    public const VOUCHERS_READ       = 'vouchers.read';
    public const VOUCHERS_GENERATE   = 'vouchers.generate';
    public const VOUCHERS_REVOKE     = 'vouchers.revoke';

    public const SESSIONS_READ       = 'sessions.read';
    public const SESSIONS_DISCONNECT = 'sessions.disconnect';

    public const INTENTS_READ        = 'intents.read';
    public const AUDIT_READ          = 'audit.read';
    /** Create, disable, re-enable, re-role or reset a DishNet staff member. Admin only (docs/114 D-AUTH-7). */
    public const STAFF_MANAGE        = 'staff.manage';

    /** @var list<string> */
    public const ALL = [
        self::HEALTH_READ,
        self::CUSTOMERS_READ, self::CUSTOMERS_WRITE,
        self::SERVICES_READ,
        self::SITES_READ, self::SITES_WRITE,
        self::ROUTERS_READ, self::ROUTERS_REGISTER, self::ROUTERS_ASSIGN, self::ROUTERS_LIFECYCLE, self::ROUTERS_ACT,
        self::PLANS_READ, self::PLANS_WRITE, self::PROFILES_READ, self::PROFILES_WRITE,
        self::VOUCHERS_READ, self::VOUCHERS_GENERATE, self::VOUCHERS_REVOKE,
        self::SESSIONS_READ, self::SESSIONS_DISCONNECT,
        self::INTENTS_READ, self::AUDIT_READ,
        self::STAFF_MANAGE,
    ];
}
