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
    public static function worker(): self { return self::connect('worker'); }
    public static function admin(): self  { return self::connect('admin'); }

    /**
     * RADIUS accounting ingestion. Holds EXECUTE on one function and nothing
     * else — no table privileges at all (migration 018, audit finding F1).
     *
     * Separate from app() because a NAS reporting usage for the whole fleet and
     * a customer's HTTP request are different trust contexts. While they shared
     * an identity, a customer who knew another customer's RADIUS username could
     * fabricate sessions and inflate byte counters in that customer's records.
     */
    public static function radius(): self { return self::connect('radius'); }
    public static function owner(): self  { return self::connect('owner'); }

    /**
     * TEST FIXTURE IDENTITY. Never use this in application code.
     *
     * Since migration 017 the database owner is not a superuser and is subject
     * to FORCE row-level security like every other role, which is the point of
     * finding F2. That leaves the test harness without a way to plant or
     * inspect state that no tenant can see — an unassigned device, another
     * customer's row, an OTP attempt counter — and there is deliberately no
     * application path for most of those.
     *
     * So observation and fixture-poking use this identity, and CREATION does
     * not: seed_two_customers() builds customers through mt_customer_create()
     * and tenant contexts, because a suite that created its fixtures with a
     * superuser could not show that the ordinary paths work. That distinction
     * is the whole reason F2 went unnoticed.
     *
     * tests/test_definer_roles.php asserts that nothing under src/ or bin/
     * mentions this method.
     */
    public static function inspector(): self { return self::connect('inspector'); }

    /**
     * Four identities, because four execution contexts exist.
     *
     * Audit findings S1 and S2 (docs/57) were both possible because requests,
     * background jobs and provisioning all connected as one role, so every
     * privilege any of them needed, all of them had. The only thing separating
     * a customer request from a worker was which PHP function the process
     * happened to call — and that is not an authorization boundary.
     *
     * None of the three application roles is a member of another, so none can
     * SET ROLE into another, and none may grant itself anything.
     */
    private static function connect(string $role): self
    {
        $dsn = getenv('DNB_DSN') ?: 'pgsql:host=/var/tmp;port=55432;dbname=dnb';
        [$user, $pass] = match ($role) {
            'owner'  => [getenv('DNB_OWNER_USER')  ?: 'dnb',        getenv('DNB_OWNER_PASS')  ?: ''],
            'inspector' => [getenv('DNB_INSPECT_USER') ?: 'postgres', getenv('DNB_INSPECT_PASS') ?: ''],
            'worker' => [getenv('DNB_WORKER_USER') ?: 'dnb_worker', getenv('DNB_WORKER_PASS') ?: 'worker-local-dev'],
            'admin'  => [getenv('DNB_ADMIN_USER')  ?: 'dnb_admin',  getenv('DNB_ADMIN_PASS')  ?: 'admin-local-dev'],
            'radius' => [getenv('DNB_RADIUS_USER') ?: 'dnb_radius', getenv('DNB_RADIUS_PASS') ?: 'radius-local-dev'],
            default  => [getenv('DNB_APP_USER')    ?: 'dnb_app',    getenv('DNB_APP_PASS')    ?: 'app-local-dev'],
        };
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

    /**
     * Run something that may violate a constraint, WITHOUT poisoning the
     * surrounding transaction.
     *
     * In PostgreSQL a failed statement aborts the whole transaction: every
     * statement after it raises 25P02 until rollback. So the familiar
     * "try the insert, catch the unique violation, try again" pattern does
     * not work inside a transaction — the retry cannot run, and neither can
     * anything else. It looks correct, passes review, and fails the first
     * time the collision it exists for actually happens.
     *
     * A savepoint confines the abort. Take one before the attempt, release it
     * on success, roll back to it on failure, and the transaction survives.
     *
     * Outside a transaction this is a no-op, so callers do not have to know
     * which case they are in.
     */
    public function attempt(callable $fn): mixed
    {
        if (!$this->pdo->inTransaction()) { return $fn($this); }

        $sp = 'sp_' . bin2hex(random_bytes(6));
        $this->pdo->exec("SAVEPOINT {$sp}");
        try {
            $out = $fn($this);
            $this->pdo->exec("RELEASE SAVEPOINT {$sp}");
            return $out;
        } catch (\Throwable $e) {
            $this->pdo->exec("ROLLBACK TO SAVEPOINT {$sp}");
            throw $e;
        }
    }
}
