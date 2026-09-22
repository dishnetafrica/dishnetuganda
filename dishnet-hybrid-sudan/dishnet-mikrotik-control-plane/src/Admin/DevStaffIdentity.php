<?php
declare(strict_types=1);
namespace Dn\Admin;

use Dn\Http\Request;
use Dn\Runtime\Bindings;

/**
 * A DEVELOPMENT AND TEST identity. Never a production one.
 *
 * The Admin UI has to be exercised before a staff identity provider exists
 * (docs/81 §8.4). The danger is obvious: a development login that quietly
 * survives into a deployment is a staff account with no provider behind it.
 *
 * So this class cannot be constructed unless BOTH hold:
 *
 *   1. DN_DEV_STAFF_IDENTITY is set to an exact string that no default, no
 *      test fixture and no deployment sets;
 *   2. the process is NOT authorized for real bindings. A process running
 *      against real MikroTik or real FreeRADIUS (F6-B) must never accept a
 *      development staff member, and that is enforced rather than documented.
 *
 * It THROWS when those do not hold. It does not degrade to DenyAllIdentity —
 * a silent downgrade is how a caller ends up believing it authenticated
 * somebody. The caller asked for a development identity in a place that must
 * not have one, and is told so.
 */
final class DevStaffIdentity implements AdminIdentityPort
{
    public const ENV   = 'DN_DEV_STAFF_IDENTITY';
    public const VALUE = 'yes-development-only';

    public function __construct(private readonly StaffRole $role)
    {
        if ((getenv(self::ENV) ?: '') !== self::VALUE) {
            throw new \RuntimeException(
                'DevStaffIdentity is development-only: set ' . self::ENV
                . ' to enable it. It must never be bound in a deployment.');
        }
        if (Bindings::realBindingsAllowed()) {
            throw new \RuntimeException(
                'DevStaffIdentity refuses to run in a process authorized for real '
                . 'bindings (F6-B): a development staff member must not reach real '
                . 'hardware or real AAA.');
        }
    }

    public function identify(Request $req): ?StaffIdentity
    {
        return new StaffIdentity('dev', $this->role, $this->providerName());
    }

    /** Named so it is unmistakable in /health and in any audit row. */
    public function providerName(): string { return 'DEVELOPMENT-ONLY'; }
}
