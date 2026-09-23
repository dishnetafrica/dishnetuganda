<?php
declare(strict_types=1);
namespace Dn\Admin;

/**
 * DishNet staff roles (docs/81 §8.3).
 *
 * A role is a set of CAPABILITIES. It never narrows WHICH CUSTOMERS a staff
 * member can see — only what they may do. That distinction is the whole point:
 * a role that scoped visibility to "own business" would be a second tenant
 * hierarchy, which is exactly what docs/81 §9 removed.
 *
 * There is deliberately no `reseller` case. docs/42 §4 carried one whose rows
 * described tenant-like scope; it is superseded (docs/81 §9) and
 * tests/test_admin_api.php asserts it has not crept back.
 */
enum StaffRole: string
{
    case Admin   = 'admin';
    case Noc     = 'noc';
    case Sales   = 'sales';
    case Support = 'support';

    /** @return list<string> */
    public function capabilities(): array
    {
        return match ($this) {
            self::Admin => Capability::ALL,

            // The provisioning and operations role — docs/42 §4's primary user
            // for Provisioning, Alerts and Intents.
            self::Noc => [
                Capability::HEALTH_READ,
                Capability::CUSTOMERS_READ, Capability::SERVICES_READ,
                Capability::SITES_READ,
                Capability::ROUTERS_READ, Capability::ROUTERS_REGISTER,
                Capability::ROUTERS_ASSIGN, Capability::ROUTERS_LIFECYCLE, Capability::ROUTERS_ACT,
                Capability::PLANS_READ, Capability::VOUCHERS_READ,
                Capability::SESSIONS_READ, Capability::SESSIONS_DISCONNECT,
                Capability::INTENTS_READ,
            ],

            // Commercial. No router action of any kind: docs/42 §4 gives
            // "Routers — register/edit" and "queue remote action" to Admin and
            // Operations only.
            self::Sales => [
                Capability::HEALTH_READ,
                Capability::CUSTOMERS_READ, Capability::CUSTOMERS_WRITE,
                Capability::SERVICES_READ, Capability::SERVICES_WRITE,
                Capability::SITES_READ, Capability::SITES_WRITE,
                Capability::ROUTERS_READ,
                Capability::PLANS_READ, Capability::PLANS_WRITE,
                Capability::VOUCHERS_READ, Capability::VOUCHERS_GENERATE,
            ],

            // Read-mostly. Can see enough to answer a customer, and cannot
            // change the estate.
            self::Support => [
                Capability::HEALTH_READ,
                Capability::CUSTOMERS_READ, Capability::SERVICES_READ,
                Capability::SITES_READ,
                Capability::ROUTERS_READ, Capability::PLANS_READ,
                Capability::VOUCHERS_READ, Capability::SESSIONS_READ,
                Capability::INTENTS_READ,
            ],
        };
    }

    public function can(string $capability): bool
    {
        return in_array($capability, $this->capabilities(), true);
    }
}
