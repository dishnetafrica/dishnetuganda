<?php
declare(strict_types=1);
namespace Dn\Audit;

use Dn\Db\Database;

/** Append-only. The database enforces it; this class only writes. */
final class AuditLog
{
    public function __construct(private Database $db) {}

    public function record(
        ?string $customerId, string $actor, string $actorKind, string $action,
        ?string $targetType = null, ?string $targetId = null,
        ?string $source = null, array $detail = []
    ): string {
        $row = $this->db->one(
            'INSERT INTO mt_audit_log
               (customer_id, actor, actor_kind, action, target_type, target_id, source, detail)
             VALUES (?,?,?,?,?,?,?,?::jsonb) RETURNING id',
            [$customerId, $actor, $actorKind, $action, $targetType, $targetId,
             $source, json_encode($detail, JSON_THROW_ON_ERROR)]
        );
        return $row['id'];
    }
}
