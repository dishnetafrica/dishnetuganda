<?php
declare(strict_types=1);
namespace Dn\Admin;

/**
 * An authenticated DishNet staff member, as the chosen identity provider
 * reports them.
 *
 * `$subject` is whatever the provider calls this person. For the real provider
 * (migration 026) it is the immutable username, which is also the audit actor
 * (D-AUTH-6). This class deliberately holds NO credential, no password hash
 * and no token: those belong to the provider, and keeping them out means
 * changing the provider later cannot be blocked by something stored here.
 *
 * It is written to mt_audit_log as actor_kind='staff' — DishNet staff only;
 * every operator person is `principal` (docs/116, docs/117).
 */
final class StaffIdentity
{
    public function __construct(
        public readonly string $subject,
        public readonly StaffRole $role,
        public readonly string $provider,
        /** True while a password-only session still owes its second factor
         *  (docs/114 D-AUTH-5): every capability answers false until enrolment,
         *  so nothing but enrolment is reachable. */
        public readonly bool $secondFactorPending = false,
        /** Whether this person has a confirmed authenticator. Informational: the
         *  provider, not this flag, decides whether a session is complete. */
        public readonly bool $secondFactorEnrolled = false,
    ) {}

    public function can(string $capability): bool
    {
        return !$this->secondFactorPending && $this->role->can($capability);
    }

    /** The shape every session response uses. One place, so the panel sees one contract. */
    public function describe(): array
    {
        return [
            'subject'       => $this->subject,
            'role'          => $this->role->value,
            'provider'      => $this->provider,
            'capabilities'  => $this->secondFactorPending ? [] : $this->role->capabilities(),
            'second_factor' => ['pending'  => $this->secondFactorPending,
                                'enrolled' => $this->secondFactorEnrolled],
        ];
    }
}
