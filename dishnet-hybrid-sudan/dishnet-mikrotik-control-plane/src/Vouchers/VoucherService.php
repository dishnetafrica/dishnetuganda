<?php
declare(strict_types=1);
namespace Dn\Vouchers;

use Dn\Db\Database;
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
        string $planId, int $count, ?string $siteId,
        ?string $principalId, ?string $idempotencyKey = null, ?string $source = null
    ): array {
        // Codes are still drawn HERE, from the injectable CodeSource, so the
        // collision path stays testable. The function consumes them in order
        // and skips any that the unique index rejects, which is why spares are
        // sent: uniqueness is still decided by the index, and a collision is
        // still retried rather than fatal.
        $codes = [];
        for ($i = 0; $i < $count + self::MAX_CODE_RETRIES; $i++) {
            $codes[] = $this->codes->generate();
        }

        try {
            $out = $this->db->one(
                'SELECT * FROM mt_voucher_batch_issue(?,?,?,?,?::text[],?,?)',
                [$planId, $count, $siteId, $principalId,
                 self::textArray($codes), $idempotencyKey, $source]);
        } catch (PDOException $e) {
            // P0002 is the function's "plan not found or retired", which the
            // route already turns into a 404.
            if (($e->errorInfo[0] ?? '') === 'P0002') {
                throw new \InvalidArgumentException('plan not found or retired');
            }
            throw $e;
        }

        $ids = self::uuidArray((string) $out['out_vouchers']);
        $rows = $this->db->query(
            'SELECT * FROM mt_vouchers WHERE id IN ('
            . implode(',', array_fill(0, count($ids), '?')) . ')', $ids);
        $byId = array_column($rows, null, 'id');

        return [
            'batch'    => $this->db->one(
                'SELECT * FROM mt_voucher_batches WHERE id = ?', [$out['out_batch']]),
            // Issue order, not storage order: created_at is transaction time,
            // so every voucher in a batch shares it and cannot order them.
            'vouchers' => array_values(array_map(fn(string $id) => $byId[$id], $ids)),
            'intent'   => $this->db->one(
                'SELECT * FROM mt_intents WHERE id = ?', [$out['out_intent']]),
        ];
    }

    /** PHP list to a PostgreSQL text[] literal, quoted so no code can break out. */
    private static function textArray(array $values): string
    {
        return '{' . implode(',', array_map(
            fn(string $v) => '"' . addcslashes($v, '"\\') . '"', $values)) . '}';
    }

    /** PostgreSQL uuid[] output, {a,b,c}, back to a PHP list. */
    private static function uuidArray(string $literal): array
    {
        $inner = trim($literal, '{}');
        return $inner === '' ? [] : explode(',', $inner);
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

    /**
     * Revoke an unused or active voucher. Never a delete.
     *
     * The state guard, the intent and the audit row are now one operation in
     * mt_voucher_revoke(). docs/108 measured that this path was retry-safe
     * only because of where the statements happened to sit in the handler;
     * with the guard ahead of both inside the function, a replay returns null
     * and writes nothing -- which is what RULE I-1 asks for.
     *
     * @return array{voucher:array,intent_id:string}|null null when the voucher
     *         is not this customer's, or is not in a revocable state.
     */
    public function revoke(string $id, ?string $actor, ?string $source = null): ?array
    {
        $row = $this->db->one(
            'SELECT mt_voucher_revoke(?,?,?) AS intent_id', [$id, $actor, $source]);
        if (($row['intent_id'] ?? null) === null) { return null; }
        return ['voucher' => $this->find($id), 'intent_id' => $row['intent_id']];
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
