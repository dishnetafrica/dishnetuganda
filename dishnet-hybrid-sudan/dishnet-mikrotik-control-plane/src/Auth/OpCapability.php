<?php
declare(strict_types=1);
namespace Dn\Auth;

/**
 * The operator-plane capability namespace (docs/116 §A).
 *
 * `op.*` on purpose, so an Operator Staff capability can never be confused
 * with a DishNet Staff `Capability`. THE CANONICAL LIST IS THE DATABASE —
 * mt_op_capabilities() in migration 027 — and tests/test_operator_staff.php
 * asserts ALL below is equal to it. This class exists so a route can name a
 * capability as a constant and so the guard can be evaluated without a
 * round-trip: kind and capabilities arrive with every request from
 * mt_auth_resolve_token(), re-read live.
 *
 * Owner is not a preset: kind = 'owner' implies every capability and stores
 * an empty list. Manager / Seller / Viewer are UI presets only (§A) — the row
 * stores the resolved list, so a preset can change without rewriting anyone.
 * op.staff.manage is never grantable to staff (a CHECK), which is what makes
 * "the actor is an owner" and "the actor holds op.staff.manage" one test.
 */
final class OpCapability
{
    public const PROFILE_READ        = 'op.profile.read';
    public const PROFILE_WRITE       = 'op.profile.write';
    public const LOCATIONS_READ      = 'op.locations.read';
    public const ROUTERS_READ        = 'op.routers.read';
    public const INTENTS_READ        = 'op.intents.read';
    public const PLANS_READ          = 'op.plans.read';
    public const PLANS_WRITE         = 'op.plans.write';
    public const VOUCHERS_READ       = 'op.vouchers.read';
    public const VOUCHERS_ISSUE      = 'op.vouchers.issue';
    public const VOUCHERS_REVOKE     = 'op.vouchers.revoke';
    public const SESSIONS_READ       = 'op.sessions.read';
    public const SESSIONS_DISCONNECT = 'op.sessions.disconnect';
    public const REPORTS_READ        = 'op.reports.read';
    public const SALES_READ          = 'op.sales.read';
    public const BILLING_READ        = 'op.billing.read';
    public const AUDIT_READ          = 'op.audit.read';
    public const STAFF_MANAGE        = 'op.staff.manage';

    /** Exactly mt_op_capabilities(), in its order. */
    public const ALL = [
        self::PROFILE_READ, self::PROFILE_WRITE,
        self::LOCATIONS_READ, self::ROUTERS_READ, self::INTENTS_READ,
        self::PLANS_READ, self::PLANS_WRITE,
        self::VOUCHERS_READ, self::VOUCHERS_ISSUE, self::VOUCHERS_REVOKE,
        self::SESSIONS_READ, self::SESSIONS_DISCONNECT,
        self::REPORTS_READ, self::SALES_READ, self::BILLING_READ, self::AUDIT_READ,
        self::STAFF_MANAGE,
    ];

    /**
     * The UI presets of docs/116 §A, resolved to lists. Stored nowhere: what
     * the row holds is the list, so these can change without a rewrite.
     */
    public const PRESETS = [
        'manager' => [
            self::PROFILE_READ, self::LOCATIONS_READ, self::ROUTERS_READ, self::INTENTS_READ,
            self::PLANS_READ, self::PLANS_WRITE,
            self::VOUCHERS_READ, self::VOUCHERS_ISSUE, self::VOUCHERS_REVOKE,
            self::SESSIONS_READ, self::SESSIONS_DISCONNECT,
            self::REPORTS_READ, self::SALES_READ,
        ],
        'seller' => [
            self::PROFILE_READ, self::LOCATIONS_READ, self::PLANS_READ,
            self::VOUCHERS_READ, self::VOUCHERS_ISSUE,
        ],
        'viewer' => [
            self::PROFILE_READ, self::LOCATIONS_READ, self::ROUTERS_READ, self::INTENTS_READ,
            self::PLANS_READ, self::VOUCHERS_READ, self::SESSIONS_READ, self::REPORTS_READ,
        ],
    ];

    /**
     * Does the resolved identity carry the capability?
     *
     * @param array{kind?:string,capabilities?:list<string>} $who what
     *        Authenticator::resolve() returned — never anything from the request
     */
    public static function allows(array $who, string $capability): bool
    {
        if (!in_array($capability, self::ALL, true)) { return false; }
        if (($who['kind'] ?? '') === 'owner') { return true; }
        return in_array($capability, $who['capabilities'] ?? [], true);
    }

    /** True when every name is a known capability. */
    public static function known(array $capabilities): bool
    {
        foreach ($capabilities as $c) {
            if (!is_string($c) || !in_array($c, self::ALL, true)) { return false; }
        }
        return true;
    }

    /**
     * A PostgreSQL text[] literal — `{op.a,op.b}` — as a PHP list. Capability
     * names contain only [a-z.], so no quoting or escaping can occur.
     *
     * @return list<string>
     */
    public static function fromPg(?string $literal): array
    {
        $t = trim((string) $literal);
        if ($t === '' || $t === '{}') { return []; }
        $t = trim($t, '{}');
        return array_values(array_filter(array_map('trim', explode(',', $t)), static fn($v) => $v !== ''));
    }

    /** The reverse, for a bound parameter. */
    public static function toPg(array $capabilities): string
    {
        return '{' . implode(',', array_map('strval', $capabilities)) . '}';
    }
}
