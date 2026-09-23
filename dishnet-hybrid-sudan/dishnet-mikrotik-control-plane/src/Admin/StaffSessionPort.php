<?php
declare(strict_types=1);
namespace Dn\Admin;

use Dn\Http\Request;
use Dn\Http\Response;

/**
 * An identity provider that can also START and END a session — the half of
 * AdminIdentityPort a login screen needs.
 *
 * AdminIdentityPort answers "who is this request?". This port adds "sign this
 * request in", "sign it out", and the three self-service acts a signed-in
 * person may perform on their own credential. The split matters: DenyAllIdentity
 * implements only the first, which is exactly why a deployment bound to it has
 * nothing to mint with — the login route asks for this port and gets null.
 *
 * Two bindings exist. DevSessionIdentity (development only, gated) and
 * DishnetStaffIdentity (migration 026, the real one). StaffIdentityFactory is
 * the only place that decides between them, and it never falls back from one
 * to the other in either direction.
 */
interface StaffSessionPort extends AdminIdentityPort
{
    /**
     * What GET /session tells an UNAUTHENTICATED caller so the panel can draw
     * the right form. A deployment property, never a per-user one: it names
     * no account and says nothing about whether any account is enrolled.
     *
     * @return array<string,mixed> e.g. ['mode' => 'credentials', 'roles' => []]
     */
    public function loginSurface(): array;

    /** POST /session. The response carries the Set-Cookie on success. */
    public function login(Request $req): Response;

    /** DELETE /session. Always 204; revokes server-side where the provider can. */
    public function logout(Request $req): Response;

    /** POST /session/password — the caller changes its OWN password. */
    public function changePassword(Request $req): Response;

    /** POST /session/totp — start enrolling the caller's OWN second factor. */
    public function totpEnrol(Request $req): Response;

    /** POST /session/totp/confirm — prove possession of the authenticator. */
    public function totpConfirm(Request $req): Response;
}
