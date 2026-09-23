<?php
declare(strict_types=1);
namespace Dn\Admin;

use Dn\Db\Database;

/**
 * DishNet staff lifecycle — the Admin-only acts (docs/114 D-AUTH-7, §N R-1).
 *
 * Every method is one SECURITY DEFINER function owned by dnb_def_staff and
 * EXECUTE-able by dnb_adminwrite only; each writes its own audit row inside
 * its own transaction (W-1), with the ACTOR PASSED IN from the identity
 * boundary — the route hands over $staff->subject, never anything a browser
 * sent. There is no way through this class to read a hash, a secret or a
 * session token, because no function it can reach returns one.
 *
 * A password is GENERATED here and returned once (§N R-13): an administrator
 * never chooses a colleague's password, and nothing logs or audits it.
 */
final class StaffAdmin
{
    private ?Database $db = null;

    /** @param \Closure(): Database $connect opens the Admin write connection on first use */
    public function __construct(private readonly \Closure $connect) {}

    public static function on(Database $db): self { return new self(static fn(): Database => $db); }

    /**
     * 24 characters from a 36-symbol alphabet in four groups: ~124 bits, and
     * typeable from a screen once. CSPRNG (random_int), never mt_rand.
     */
    public static function generatePassword(): string
    {
        $alphabet = 'abcdefghijkmnpqrstuvwxyz23456789';   // no 0/o/1/l ambiguity
        $groups = [];
        for ($g = 0; $g < 4; $g++) {
            $s = '';
            for ($i = 0; $i < 6; $i++) { $s .= $alphabet[random_int(0, strlen($alphabet) - 1)]; }
            $groups[] = $s;
        }
        return implode('-', $groups);
    }

    /** The first administrator. Refuses once anyone exists. @return array{id:string,password:string} */
    public function bootstrap(string $username, string $display, string $actor): array
    {
        $pw = self::generatePassword();
        $id = $this->call('SELECT mt_staff_bootstrap(?,?,?,?) AS id', [$username, $display, $pw, $actor])['id'];
        return ['id' => (string) $id, 'password' => $pw];
    }

    /** @return array{id:string,password:string} */
    public function create(string $username, string $display, StaffRole $role, string $actor): array
    {
        $pw = self::generatePassword();
        $id = $this->call('SELECT mt_staff_create(?,?,?,?,?) AS id',
                          [$username, $display, $role->value, $pw, $actor])['id'];
        return ['id' => (string) $id, 'password' => $pw];
    }

    public function disable(string $id, string $actor): bool
    {
        return (bool) $this->call('SELECT mt_staff_disable(?,?) AS r', [$id, $actor])['r'];
    }

    public function enable(string $id, string $actor): bool
    {
        return (bool) $this->call('SELECT mt_staff_enable(?,?) AS r', [$id, $actor])['r'];
    }

    public function setRole(string $id, StaffRole $role, string $actor): bool
    {
        return (bool) $this->call('SELECT mt_staff_set_role(?,?,?) AS r', [$id, $role->value, $actor])['r'];
    }

    /** A new generated password, returned once; null when no such person exists. */
    public function resetPassword(string $id, string $actor): ?string
    {
        $pw = self::generatePassword();
        $ok = (bool) $this->call('SELECT mt_staff_reset_password(?,?,?) AS r', [$id, $pw, $actor])['r'];
        return $ok ? $pw : null;
    }

    public function totpReset(string $id, string $actor): bool
    {
        return (bool) $this->call('SELECT mt_staff_totp_reset(?,?) AS r', [$id, $actor])['r'];
    }

    /**
     * A refusal the function raised on purpose — last admin, self-disable,
     * duplicate username, short password — surfaces as StaffRefused with the
     * function's own wording, which names no secret. Anything else propagates.
     */
    private function call(string $sql, array $args): array
    {
        try {
            // Through attempt(): outside a transaction a no-op; inside one, a
            // savepoint keeps a refused act from poisoning the caller's work.
            $db  = $this->db ??= ($this->connect)();
            $row = $db->attempt(static fn(Database $d) => $d->one($sql, $args));
        } catch (\PDOException $e) {
            $state = (string) ($e->errorInfo[0] ?? $e->getCode());
            if ($state === '23505') {
                // The constraint name would be a schema detail; the fact is enough.
                throw new StaffRefused('that username is already taken', $state, $e);
            }
            if (in_array($state, ['23514', '23502'], true)) {
                throw new StaffRefused(self::reason($e->getMessage()), $state, $e);
            }
            throw $e;
        }
        return $row ?? [];
    }

    private static function reason(string $driverMessage): string
    {
        if (preg_match('/ERROR:\s+(.+?)(\r?\n|$)/', $driverMessage, $m)) { return trim($m[1]); }
        return 'refused';
    }
}
