<?php
declare(strict_types=1);
namespace Dn\Vouchers;

use Dn\Db\Database;
use Dn\Intents\IntentQueue;
use PDOException;

/** Customer-scoped except redeem(). Call inside TenantContext::run(). */
final class VoucherService
{
    private const MAX_CODE_RETRIES = 8;

    public function __construct(
        private Database $db,
        private CodeSource $codes = new CodeGenerator(),
    ) {}

    /**
     * Issue a batch.
     *
     * The codes exist immediately — they are rows, and a receptionist can
     * read one off a screen at once. Publishing them to AAA is an intent,
     * because that is the part that involves a router.
     *
     * @return array{batch:array,vouchers:list<array>,intent:array}
     */
    public function issueBatch(
        string $customerId, string $planId, int $count,
        ?string $siteId, ?string $principalId, ?string $idempotencyKey = null
    ): array {
        $plan = $this->db->one('SELECT * FROM mt_plans WHERE id = ? AND active', [$planId]);
        if ($plan === null) {
            throw new \InvalidArgumentException('plan not found or retired');
        }

        $batch = $this->db->one(
            'INSERT INTO mt_voucher_batches
               (customer_id, site_id, plan_id, requested_count, created_by)
             VALUES (?,?,?,?,?) RETURNING *',
            [$customerId, $siteId, $planId, $count, $principalId]
        );

        $vouchers = [];
        for ($i = 0; $i < $count; $i++) {
            $vouchers[] = $this->insertOne($customerId, $batch['id'], $plan, $siteId);
        }

        // The AAA row is written here, not by the intent.
        //
        // With RADIUS the credential lives in this database and FreeRADIUS
        // reads it; there is no per-voucher operation on a router. The intent
        // below records that publication was requested and is what step 7's
        // delivery will act on — which may well be a no-op confirm for this
        // kind, since the router needs no per-voucher change. Writing the row
        // here means a code works the moment it is handed over rather than
        // when a queue gets to it.
        $customer = $this->db->one('SELECT radius_ref FROM mt_customers WHERE id = ?', [$customerId]);
        foreach ($vouchers as $v) {
            $this->db->exec(
                'INSERT INTO mt_hotspot_users (voucher_id, customer_id, radius_username)
                 VALUES (?,?,?)',
                [$v['id'], $customerId, $this->radiusUsername($customer, $v['code'])]);
        }

        $this->db->exec(
            "UPDATE mt_voucher_batches
                SET issued_count = ?, state = 'issued', completed_at = now()
              WHERE id = ?", [count($vouchers), $batch['id']]);

        $intent = (new IntentQueue($this->db))->enqueue(
            $customerId, 'voucher.publish',
            ['batch_id' => $batch['id'], 'count' => count($vouchers)],
            $principalId, 'voucher_batch', $batch['id'], $idempotencyKey
        );

        return ['batch' => $this->db->one('SELECT * FROM mt_voucher_batches WHERE id = ?', [$batch['id']]),
                'vouchers' => $vouchers, 'intent' => $intent];
    }

    /**
     * Insert one voucher, retrying on a code collision.
     *
     * The retry is the other half of "uniqueness by index": the index rejects
     * a duplicate, and this generates another rather than failing the batch.
     * At 32^10 a collision is vanishingly rare, which is exactly why the path
     * needs a test rather than an assumption — see the suite.
     */
    private function insertOne(string $customerId, string $batchId, array $plan, ?string $siteId): array
    {
        for ($attempt = 0; $attempt < self::MAX_CODE_RETRIES; $attempt++) {
            try {
                // attempt() takes a savepoint: without it the first collision
                // aborts the transaction and the retry below cannot run.
                return $this->db->attempt(fn($db) => $db->one(
                    'INSERT INTO mt_vouchers
                       (customer_id, batch_id, plan_id, site_id, code,
                        price_minor, currency, duration_s)
                     VALUES (?,?,?,?,?,?,?,?) RETURNING *',
                    [$customerId, $batchId, $plan['id'], $siteId, $this->codes->generate(),
                     // Snapshot: what this voucher sold for, fixed at issue.
                     (int) $plan['price_minor'], $plan['currency'], (int) $plan['duration_s']]
                ));
            } catch (PDOException $e) {
                if (($e->errorInfo[0] ?? '') !== '23505') { throw $e; }
                // collision on code — generate another
            }
        }
        throw new \RuntimeException('could not allocate a unique voucher code');
    }

    /** @return list<array> */
    public function list(?string $state = null, int $limit = 200): array
    {
        return $state === null
            ? $this->db->query('SELECT * FROM mt_vouchers ORDER BY created_at DESC LIMIT ?', [$limit])
            : $this->db->query('SELECT * FROM mt_vouchers WHERE state = ? ORDER BY created_at DESC LIMIT ?',
                               [$state, $limit]);
    }

    public function find(string $id): ?array
    {
        return $this->db->one('SELECT * FROM mt_vouchers WHERE id = ?', [$id]);
    }

    /** Revoking an unused or active voucher. Never a delete. */
    public function revoke(string $id): ?array
    {
        return $this->db->one(
            "UPDATE mt_vouchers SET state = 'revoked', revoked_at = now()
              WHERE id = ? AND state IN ('unused','active') RETURNING *", [$id]);
    }

    /**
     * Redeem a code.
     *
     * NOT customer-scoped: redemption arrives from the network side, which
     * presents a code and nothing else. Which customer it belongs to is the
     * answer, not the input.
     *
     * Returns null for every failure — unknown code, already redeemed,
     * revoked, expired — so nothing distinguishes "wrong code" from
     * "someone else already used it".
     */
    public function redeem(string $code): ?array
    {
        $row = $this->db->one(
            'SELECT voucher_id, customer_id, duration_s, expires_at FROM mt_voucher_redeem(?)',
            [strtoupper(trim($code))]
        );
        return $row ?: null;
    }

    /** The AAA username for a voucher, namespaced so codes cannot cross customers. */
    public function radiusUsername(array $customer, string $code): string
    {
        return $customer['radius_ref'] . '-' . str_replace('-', '', $code);
    }
}
