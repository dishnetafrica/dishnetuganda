<?php
declare(strict_types=1);
namespace Dn\Admin;

use Dn\Db\Database;

/**
 * THE ONE PLACE the staff identity provider is chosen (docs/114 §G.8, §N R-11).
 *
 *   DN_STAFF_IDENTITY unset      DenyAllIdentity — nobody can sign in (W-4 posture)
 *                                 …unless the development gate is set, which
 *                                 binds DevSessionIdentity exactly as before 026
 *   DN_STAFF_IDENTITY=dishnet    DishnetStaffIdentity — migration 026, the real one
 *   anything else                REFUSED: the process does not start
 *
 * Three things never happen here:
 *
 *   1. no fallback in either direction. A real provider that cannot connect
 *      throws, and plugin/public/api.php answers 500 to every request until it
 *      is fixed. It does not become DenyAllIdentity (which would look like
 *      "nobody may sign in", a policy) and it does not become the development
 *      identity (which would admit an invented person);
 *   2. the development identity and the real one never coexist. Asking for
 *      both is a configuration error, not a preference;
 *   3. the real provider is NOT gated on F6-B. It must work in a process
 *      authorized for real bindings — that is the whole point of having it —
 *      and a test proves it constructs and identifies under the gate that
 *      makes DevSessionIdentity throw.
 */
final class StaffIdentityFactory
{
    public const ENV      = 'DN_STAFF_IDENTITY';
    public const DISHNET  = 'dishnet';
    public const TOTP_ENV = 'DN_STAFF_REQUIRE_TOTP';

    /** @return array{0:AdminIdentityPort,1:?StaffSessionPort,2:?StaffAdmin} */
    public static function fromEnvironment(): array
    {
        $mode = trim(getenv('DN_STAFF_IDENTITY') ?: '');
        $dev  = (getenv(DevStaffIdentity::ENV) ?: '') === DevStaffIdentity::VALUE;

        if ($mode === '') {
            if ($dev) {
                // DevSessionIdentity enforces its own two gates and throws
                // rather than degrading; nothing here catches that.
                $d = new DevSessionIdentity(new AdminSession());
                return [$d, $d, null];
            }
            return [new DenyAllIdentity(), null, null];
        }
        if ($mode !== self::DISHNET) {
            throw new \RuntimeException(
                self::ENV . " must be unset or '" . self::DISHNET . "'; refusing to start with '{$mode}'");
        }
        if ($dev) {
            throw new \RuntimeException(
                DevStaffIdentity::ENV . ' and ' . self::ENV . '=' . self::DISHNET
                . ' refuse to coexist: a development identity must never share a process '
                . 'with the real staff identity provider');
        }
        $require = self::requireSecondFactor();

        // Eager: a provider that cannot authenticate anybody must fail NOW, at
        // construction, so the failure is one log line and a 500 on every
        // request rather than a quiet deny-all.
        $auth  = Database::staffAuth();
        $write = null;
        $writer = static function () use (&$write): Database {
            return $write ??= Database::adminWrite();
        };
        $provider = new DishnetStaffIdentity($auth, $writer, new StaffToken(),
                                             TransportPolicy::fromEnvironment(), $require);
        return [$provider, $provider, new StaffAdmin($writer)];
    }

    /**
     * Required by default (D-AUTH-5: mandatory before a public hostname).
     * Exactly 'no' relaxes it, and plugin.php doctor reports that as a blocker
     * outside a disposable environment. Any other value is a typo and refuses.
     */
    public static function requireSecondFactor(): bool
    {
        $v = strtolower(trim(getenv('DN_STAFF_REQUIRE_TOTP') ?: ''));
        return match ($v) {
            '', 'yes' => true,
            'no'      => false,
            default   => throw new \RuntimeException(
                self::TOTP_ENV . " must be unset, 'yes' or 'no'; refusing to start with '{$v}'"),
        };
    }
}
