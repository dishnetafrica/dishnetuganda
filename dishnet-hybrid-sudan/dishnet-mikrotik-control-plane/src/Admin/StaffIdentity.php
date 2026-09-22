<?php
declare(strict_types=1);
namespace Dn\Admin;

/**
 * An authenticated DishNet staff member, as the chosen identity provider
 * reports them.
 *
 * `$subject` is whatever the provider calls this person — a directory id, an
 * SSO subject, an employee number. This class deliberately holds NO
 * credential, no password hash and no token: those belong to the provider, and
 * keeping them out means selecting a provider later cannot be blocked by
 * something already stored here.
 *
 * It is written to mt_audit_log as actor_kind='staff' (migration 004 already
 * allows it; nothing had ever produced one until now).
 */
final class StaffIdentity
{
    public function __construct(
        public readonly string $subject,
        public readonly StaffRole $role,
        public readonly string $provider,
    ) {}

    public function can(string $capability): bool { return $this->role->can($capability); }
}
