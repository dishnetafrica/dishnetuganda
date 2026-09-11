<?php
declare(strict_types=1);

/**
 * FinAudit — the record of what happened to a money record.
 *
 * The question this exists to answer is not "what does the ledger say now".
 * It is "what did it say before, who changed it, when, and why" — and that
 * question had no answer. A cashbook row could be edited to a different
 * amount, or deleted outright, and afterwards there was nothing: no actor,
 * no previous value, no trace that the row had ever existed. The activity
 * log is a 500-entry ring with no actor field, so it could not answer it
 * either.
 *
 * Append-only. Nothing in this codebase updates or deletes a row here, and
 * the test suite asserts that no such statement exists. A record outlives
 * the thing it describes, which is the point: a deleted ledger entry is
 * still readable, in full, out of before_json.
 *
 * Failure is swallowed on purpose. An audit write must never be the reason
 * a payment fails to record — a missing audit row is a gap in the history,
 * while a refused payment is a customer standing at a counter. Every
 * failure goes to error_log so the gap is visible.
 */
final class FinAudit
{
    public const ACTIONS = ['create', 'update', 'void', 'delete'];

    public static function ensureTable(\PDO $pdo): void
    {
        $pdo->exec("CREATE TABLE IF NOT EXISTS fin_audit (
            id            INTEGER PRIMARY KEY AUTOINCREMENT,
            record_type   TEXT    NOT NULL,
            record_id     INTEGER NOT NULL,
            action        TEXT    NOT NULL,
            actor_id      INTEGER,
            actor_name    TEXT    NOT NULL DEFAULT '',
            reason        TEXT    NOT NULL DEFAULT '',
            before_json   TEXT,
            after_json    TEXT,
            changed_keys  TEXT    NOT NULL DEFAULT '',
            created_at    TEXT    NOT NULL
        )");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_fa_record ON fin_audit(record_type, record_id, id)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_fa_date   ON fin_audit(created_at)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_fa_actor  ON fin_audit(actor_id, created_at)");
    }

    /**
     * Write one audit row.
     *
     * @param array|null $before  the record as it was; null when being created
     * @param array|null $after   the record as it became; null when deleted
     * @param array      $actor   a staff row — 'id' and 'name' are read if present
     * @return int  the audit row id, or 0 if the write failed (already logged)
     */
    public static function record(\PDO $pdo, string $recordType, int $recordId, string $action,
                                  array $actor = [], ?array $before = null, ?array $after = null,
                                  string $reason = ''): int
    {
        try {
            if (!in_array($action, self::ACTIONS, true)) {
                throw new \InvalidArgumentException("unknown audit action '{$action}'");
            }
            self::ensureTable($pdo);

            $st = $pdo->prepare("INSERT INTO fin_audit
                (record_type, record_id, action, actor_id, actor_name, reason,
                 before_json, after_json, changed_keys, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $st->execute([
                $recordType,
                $recordId,
                $action,
                isset($actor['id']) ? (int)$actor['id'] : null,
                trim((string)($actor['name'] ?? $actor['full_name'] ?? '')),
                trim($reason),
                $before === null ? null : self::encode($before),
                $after  === null ? null : self::encode($after),
                implode(',', self::changedKeys($before, $after)),
                date('Y-m-d H:i:s'),
            ]);
            return (int)$pdo->lastInsertId();
        } catch (\Throwable $e) {
            // Never the reason a financial operation fails. Visible, though.
            error_log('[FinAudit] could not record ' . $recordType . '#' . $recordId
                      . ' ' . $action . ': ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Everything that ever happened to one record, oldest first.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function history(\PDO $pdo, string $recordType, int $recordId): array
    {
        try {
            self::ensureTable($pdo);
            $st = $pdo->prepare("SELECT * FROM fin_audit
                WHERE record_type = ? AND record_id = ? ORDER BY id ASC");
            $st->execute([$recordType, $recordId]);
            return $st->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            error_log('[FinAudit] history failed: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Which fields differ between two versions of a record.
     *
     * Compared as strings: SQLite hands back '1500' where PHP wrote 1500, and
     * a strict comparison would report every untouched numeric column as
     * changed, burying the one field that really moved.
     *
     * @return string[]
     */
    public static function changedKeys(?array $before, ?array $after): array
    {
        if ($before === null || $after === null) return [];
        $keys = array_unique(array_merge(array_keys($before), array_keys($after)));
        sort($keys);
        $out = [];
        foreach ($keys as $k) {
            $b = array_key_exists($k, $before) ? $before[$k] : null;
            $a = array_key_exists($k, $after)  ? $after[$k]  : null;
            if (is_array($b) || is_array($a)) {
                if (self::encode((array)$b) !== self::encode((array)$a)) $out[] = (string)$k;
                continue;
            }
            // 'updated_at' moves on every write and says nothing about intent.
            if ($k === 'updated_at') continue;
            if ((string)$b !== (string)$a) $out[] = (string)$k;
        }
        return $out;
    }

    /** JSON that survives a Ugandan address and never throws. */
    private static function encode(array $row): string
    {
        $j = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                              | JSON_PARTIAL_OUTPUT_ON_ERROR);
        return $j === false ? '{}' : $j;
    }
}
