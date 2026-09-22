<?php
declare(strict_types=1);
namespace Dn\Tenancy;

use Dn\Db\Database;
use Throwable;

/**
 * The isolation boundary, expressed as the only way to touch customer data.
 *
 * Every customer-scoped read or write happens inside run(), which opens a
 * transaction and sets app.customer_id for its duration. SET LOCAL is used
 * rather than SET so the value cannot leak to the next request on a pooled
 * connection — a leaked tenant id is a cross-customer read, and the difference
 * between SET and SET LOCAL is the whole guarantee.
 *
 * The customer id passed here is DERIVED from the authenticated principal.
 * It is never taken from a request body, query string, header or path (F4).
 * Nothing in this class validates that, because nothing in this class can;
 * the caller's job is to have derived it, and Auth is the only thing that may.
 */
final class TenantContext
{
    public function __construct(private Database $db) {}

    /**
     * @template T
     * @param  callable(Database): T $fn
     * @return T
     */
    public function run(string $customerId, callable $fn)
    {
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $customerId)) {
            // A non-uuid would make set_config throw mid-transaction at an
            // awkward moment. Rejecting early keeps the failure legible.
            throw new \InvalidArgumentException('customer id must be a uuid');
        }
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $st = $pdo->prepare("SELECT set_config('app.customer_id', ?, true)");
            $st->execute([$customerId]);
            $out = $fn($this->db);
            $pdo->commit();
            return $out;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            throw $e;
        }
    }

    /**
     * Run with NO customer context. Every customer-scoped table then matches
     * nothing, because `customer_id = NULL` is NULL rather than TRUE.
     *
     * This exists so the fail-closed property can be tested directly rather
     * than argued about.
     */
    public function runUnscoped(callable $fn)
    {
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $pdo->prepare("SELECT set_config('app.customer_id', '', true)")->execute();
            $out = $fn($this->db);
            $pdo->commit();
            return $out;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            throw $e;
        }
    }
}
