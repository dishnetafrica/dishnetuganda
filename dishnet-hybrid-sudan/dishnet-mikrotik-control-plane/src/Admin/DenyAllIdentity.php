<?php
declare(strict_types=1);
namespace Dn\Admin;

use Dn\Http\Request;

/**
 * Authenticates nobody. THE DEFAULT, and the only binding in F6-A.
 *
 * Decision 8 records that the DishNet staff identity provider is not selected.
 * Until it is, the honest behaviour is to admit no one — not to admit a
 * development user, and not to fall back to the customer authenticator, which
 * would put staff and customers on one identity path and undo the separation
 * the decision exists to create.
 */
final class DenyAllIdentity implements AdminIdentityPort
{
    public function identify(Request $req): ?StaffIdentity { return null; }
    public function providerName(): string { return 'deny-all'; }
}
