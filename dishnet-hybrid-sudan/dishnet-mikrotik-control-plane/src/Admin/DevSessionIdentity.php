<?php
declare(strict_types=1);
namespace Dn\Admin;

use Dn\Http\Request;
use Dn\Http\Response;
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
 *   2. the process must NOT be authorized for real bindings (F6-B);
 *   3. and, since migration 026, it must not be asked for in the same process
 *      as the real provider — StaffIdentityFactory refuses that combination.
 *
 * It THROWS when either gate fails. It does not degrade to DenyAllIdentity: a
 * silent downgrade is how a caller ends up believing it authenticated
 * somebody. And DenyAllIdentity never upgrades to this — there is no fallback
 * in that direction anywhere, which tests/test_admin_login.php asserts by
 * execution rather than by reading this comment.
 *
 * WHAT "LOGGING IN" MEANS HERE. There is no credential, because a development
 * identity must never hold one. The environment gate IS the credential; the
 * form only selects which ROLE to work as, so the capability boundary can be
 * seen and exercised. That is honest for a development identity and would be
 * indefensible for a production one, which is why it cannot become one: the
 * real provider is DishnetStaffIdentity, a different class behind the same
 * port, and the self-service acts below answer 501 here because there is no
 * credential to change and no authenticator to enrol.
 */
final class DevSessionIdentity implements StaffSessionPort
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

    public function loginSurface(): array
    {
        return ['mode' => 'development', 'roles' => self::roleNames()];
    }

    public function login(Request $req): Response
    {
        $role = StaffRole::tryFrom((string) ($req->body['role'] ?? ''));
        if ($role === null) {
            return Response::badRequest('role must be one of: ' . implode(', ', self::roleNames()));
        }
        $token = $this->issue($role);
        $who   = new StaffIdentity('dev', $role, $this->providerName());
        return new Response(200,
            ['identity' => $who->describe() + ['expires_in' => AdminSession::TTL_SECONDS]],
            ['Set-Cookie' => AdminSession::setCookie($token, self::isHttps($req), AdminSession::TTL_SECONDS)]);
    }

    /**
     * Clears the cookie; it cannot revoke, because the token is stateless.
     * tests/test_admin_login.php asserts that limitation so it stays visible.
     */
    public function logout(Request $req): Response
    {
        return new Response(204, [], ['Set-Cookie' => AdminSession::clearCookie(self::isHttps($req))]);
    }

    public function changePassword(Request $req): Response { return self::notHere(); }
    public function totpEnrol(Request $req): Response      { return self::notHere(); }
    public function totpConfirm(Request $req): Response    { return self::notHere(); }

    private static function notHere(): Response
    {
        return new Response(501, [
            'error'  => 'not_available_for_this_provider',
            'detail' => 'the development identity holds no credential and no authenticator; '
                      . 'self-service exists only under DN_STAFF_IDENTITY=dishnet',
        ]);
    }

    /** @return list<string> */
    private static function roleNames(): array
    {
        return array_map(static fn(StaffRole $r) => $r->value, StaffRole::cases());
    }

    /**
     * The DEVELOPMENT cookie's Secure flag trusts any X-Forwarded-Proto. Left
     * as it was on purpose (docs/114 §N R-9): this class refuses to exist
     * alongside real bindings. The real provider uses TransportPolicy, which
     * trusts the header only from a configured proxy address.
     */
    private static function isHttps(Request $req): bool
    {
        return $req->https || ($req->header('X-Forwarded-Proto') ?? '') === 'https';
    }
}
