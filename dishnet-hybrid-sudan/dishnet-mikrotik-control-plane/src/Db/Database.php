<?php
declare(strict_types=1);
namespace Dn\Db;

use PDO;
use PDOException;

/**
 * The connection. Deliberately thin.
 *
 * Two connection roles exist and they are not interchangeable:
 *
 *   owner : runs migrations. Owns the tables. Subject to FORCE RLS but able to
 *           set app.customer_id, so it must never serve requests.
 *   app   : serves requests. Owns nothing, is not superuser, has no BYPASSRLS
 *           and no DDL. This is the role the isolation guarantee rests on.
 *
 * Connecting as the owner to serve a request is the failure that makes every
 * policy in 006 decorative, so the two are separate methods with separate
 * credentials rather than one method with a flag.
 */
final class Database
{
    private function __construct(private PDO $pdo, private string $role) {}

    public static function app(): self    { return self::connect('app'); }
    public static function owner(): self  { return self::connect('owner'); }

    private static function connect(string $role): self
    {
        $dsn  = getenv('DNB_DSN') ?: 'pgsql:host=/var/tmp;port=55432;dbname=dnb';
        $user = $role === 'owner' ? (getenv('DNB_OWNER_USER') ?: 'dnb')
                                  : (getenv('DNB_APP_USER')   ?: 'dnb_app');
        $pass = $role === 'owner' ? (getenv('DNB_OWNER_PASS') ?: '')
                                  : (getenv('DNB_APP_PASS')   ?: 'app-local-dev');
        try {
            $pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            throw new \RuntimeException("cannot connect as {$role}: " . $e->getMessage(), 0, $e);
        }
        return new self($pdo, $role);
    }

    public function pdo(): PDO      { return $this->pdo; }
    public function role(): string  { return $this->role; }

    public function query(string $sql, array $params = []): array
    {
        $st = $this->pdo->prepare($sql);
        $st->execute($params);
        return $st->fetchAll();
    }

    public function exec(string $sql, array $params = []): int
    {
        $st = $this->pdo->prepare($sql);
        $st->execute($params);
        return $st->rowCount();
    }

    public function one(string $sql, array $params = []): ?array
    {
        $rows = $this->query($sql, $params);
        return $rows[0] ?? null;
    }
}
