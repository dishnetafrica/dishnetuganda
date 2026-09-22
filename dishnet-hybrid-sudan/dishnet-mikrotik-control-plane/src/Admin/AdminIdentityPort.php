<?php
declare(strict_types=1);
namespace Dn\Admin;

use Dn\Http\Request;

/**
 * The staff-authentication boundary (docs/81 §8).
 *
 * The concrete provider is NOT decided. It may become an existing DishNet
 * identity system rather than another password database, so the application
 * depends on this interface and never on a credential store.
 *
 * The only binding shipped is DenyAllIdentity. That is not a placeholder to be
 * quietly replaced: the admin API is fully built and fully tested behind it,
 * and remains unreachable in any deployment until a provider is chosen, bound
 * deliberately, and reviewed.
 */
interface AdminIdentityPort
{
    /**
     * @return StaffIdentity|null null when the request carries no valid staff
     *         identity. Callers MUST treat null as 401 and must never fall
     *         back to a default staff member.
     */
    public function identify(Request $req): ?StaffIdentity;

    /** For /api/v1/admin/health and for audit. */
    public function providerName(): string;
}
