<?php
declare(strict_types=1);
namespace Dn\Admin;

use Dn\Http\Request;
use Dn\Runtime\Bindings;

/**
 * A DEVELOPMENT staff identity that requires an actual LOGIN.
 *
 * DevStaffIdentity authenticates every request that reaches it, which is what
 * a UI-only development gate needs and is useless for exercising a login
 * screen: there is no unauthenticated state, no expiry and no logout. This
 * class keeps that gate and adds the missing half — it identifies a request
 * ONLY from a signed session token, so the panel has a real
 *
 *     unauthenticated -> login -> authenticated -> expired -> unauthenticated
 *
 * cycle to render.
 *
 * IT IS STILL DEVELOPMENT ONLY, under exactly the conditions DevStaffIdentity
 * imposes, and for the same reasons:
 *
 *   1. DN_DEV_STAFF_IDENTITY must equal an exact string no default, fixture or
 *      deployment sets;
 *   2. the process must NOT be authorized for real bindings (F6-B).
 *
 * It THROWS when either fails. It does not degrade to DenyAllIdentity: a
 * silent downgrade is how a caller ends up believing it authenticated
 * somebody. And DenyAllIdentity never upgrades to this — there is no fallback
 * in that direction anywhere, which tests/test_admin_login.php asserts by
 * execution rather than by reading this comment.
 *
 * WHAT "LOGGING IN" MEANS HERE. There is no password, because there is no
 * credential store and deliberately will not be one until a provider is
 * chosen (AdminIdentityPort). The environment gate IS the credential; the form
 * only selects which ROLE to work as, so the capability boundary can be seen
 * and exercised. That is honest for a development identity and would be
 * indefensible for a production one, which is why it cannot become one.
 */
final class DevSessionIdentity implements AdminIdentityPort
{
    public function __construct(private readonly AdminSession $sessions)
    {
        if ((getenv(DevStaffIdentity::ENV) ?: '') !== DevStaffIdentity::VALUE) {
            throw new \RuntimeException(
                'DevSessionIdentity is development-only: set ' . DevStaffIdentity::ENV
                . ' to enable it. It must never be bound in a deployment.');
        }
        if (Bindings::realBindingsAllowed()) {
            throw new \RuntimeException(
                'DevSessionIdentity refuses to run in a process authorized for real '
                . 'bindings (F6-B): a development staff member must not reach real '
                . 'hardware or real AAA.');
        }
    }

    public function identify(Request $req): ?StaffIdentity
    {
        $token = AdminSession::fromRequest($req);
        if ($token === '') { return null; }

        $claims = $this->sessions->verify($token);
        if ($claims === null) { return null; }   // malformed, forged or expired

        return new StaffIdentity($claims['sub'], $claims['role'], $this->providerName());
    }

    /** Named so it is unmistakable in /health, in the panel and in any audit row. */
    public function providerName(): string { return 'DEVELOPMENT-ONLY'; }

    /**
     * Mint a session for a role. Only a process that constructed this class —
     * which means it passed both gates above — can reach it, so the login
     * route has nothing to mint with in production.
     */
    public function issue(StaffRole $role): string
    {
        return $this->sessions->mint('dev', $role);
    }
}
