<?php
declare(strict_types=1);

/**
 * EfrisGoodsStore — the goods/services registry (URA checklist Q1) and the
 * stock movement log (Q2/Q7).
 *
 * efris_goods is the operator's product catalogue for EFRIS: one row per
 * goods/services item they deal in. `name` matches the uCRM invoice line
 * label (that is how invoice items find their commodity code), and the row
 * carries everything T130 registration needs. Registration status and the
 * EFRIS reference are copied VERBATIM from responses — same discipline as
 * efris_transactions.
 *
 * efris_stock_log is append-only: every T131 stock movement (or refused
 * attempt) is one row, so the local stock_qty mirror is always explainable.
 */
class EfrisGoodsStore
{
    public const ST_UNREGISTERED = 'UNREGISTERED';
    public const ST_REGISTERED   = 'REGISTERED';
    public const ST_ERROR        = 'ERROR';

    public const VAT_CATEGORIES = ['standard', 'zero_rated', 'exempt'];
    public const UNITS = ['each', 'month', 'kg', 'litre', 'metre', 'hour', 'service'];

    private \PDO $pdo;

    public function __construct(\PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->ensureSchema();
    }

    private function ensureSchema(): void
    {
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS efris_goods (
                id               INTEGER PRIMARY KEY AUTOINCREMENT,
                name             TEXT NOT NULL,
                goods_code       TEXT NOT NULL DEFAULT '',
                commodity_code   TEXT NOT NULL DEFAULT '',
                unit             TEXT NOT NULL DEFAULT 'each',
                unit_price       REAL,
                currency         TEXT NOT NULL DEFAULT 'UGX',
                vat_category     TEXT NOT NULL DEFAULT 'standard',
                stocked          INTEGER NOT NULL DEFAULT 0,
                stock_qty        REAL NOT NULL DEFAULT 0,
                status           TEXT NOT NULL DEFAULT 'UNREGISTERED',
                efris_reference  TEXT,
                response_message TEXT,
                registered_at    TEXT,
                created_at       TEXT NOT NULL DEFAULT (datetime('now')),
                updated_at       TEXT NOT NULL DEFAULT (datetime('now'))
            )
        ");
        $this->pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_efris_goods_name
                          ON efris_goods(name COLLATE NOCASE)");
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS efris_stock_log (
                id               INTEGER PRIMARY KEY AUTOINCREMENT,
                goods_id         INTEGER NOT NULL,
                op               TEXT NOT NULL,
                qty              REAL NOT NULL,
                reason           TEXT NOT NULL DEFAULT '',
                note             TEXT NOT NULL DEFAULT '',
                request_id       TEXT,
                status           TEXT NOT NULL DEFAULT 'OK',
                response_message TEXT,
                actor            TEXT NOT NULL DEFAULT '',
                created_at       TEXT NOT NULL DEFAULT (datetime('now'))
            )
        ");
        $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_efris_stock_goods
                          ON efris_stock_log(goods_id, created_at DESC)");
    }

    public function all(): array
    {
        return $this->pdo->query(
            "SELECT * FROM efris_goods ORDER BY stocked DESC, name COLLATE NOCASE"
        )->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    public function get(int $id): ?array
    {
        $st = $this->pdo->prepare("SELECT * FROM efris_goods WHERE id = ?");
        $st->execute([$id]);
        $row = $st->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function findByName(string $name): ?array
    {
        $st = $this->pdo->prepare("SELECT * FROM efris_goods WHERE name = ? COLLATE NOCASE");
        $st->execute([trim($name)]);
        $row = $st->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** Insert-or-update by (case-insensitive) name. Returns the row id. */
    public function upsert(array $f): int
    {
        $existing = $this->findByName((string)($f['name'] ?? ''));
        if ($existing) {
            $this->update((int)$existing['id'], $f);
            return (int)$existing['id'];
        }
        $st = $this->pdo->prepare("
            INSERT INTO efris_goods (name, goods_code, commodity_code, unit, unit_price,
                                     currency, vat_category, stocked)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $st->execute([
            trim((string)$f['name']),
            trim((string)($f['goods_code'] ?? '')),
            trim((string)($f['commodity_code'] ?? '')),
            trim((string)($f['unit'] ?? 'each')),
            isset($f['unit_price']) && $f['unit_price'] !== '' ? (float)$f['unit_price'] : null,
            trim((string)($f['currency'] ?? 'UGX')),
            trim((string)($f['vat_category'] ?? 'standard')),
            (int)!empty($f['stocked']),
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    public function update(int $id, array $f): void
    {
        static $allowed = ['name', 'goods_code', 'commodity_code', 'unit', 'unit_price',
                           'currency', 'vat_category', 'stocked', 'stock_qty',
                           'status', 'efris_reference', 'response_message', 'registered_at'];
        $sets = []; $vals = [];
        foreach ($f as $k => $v) {
            if (!in_array($k, $allowed, true)) continue;
            $sets[] = "[$k] = ?"; $vals[] = $v;
        }
        if (!$sets) return;
        $sets[] = "updated_at = datetime('now')";
        $vals[] = $id;
        $this->pdo->prepare(
            "UPDATE efris_goods SET " . implode(', ', $sets) . " WHERE id = ?")->execute($vals);
    }

    public function logStock(int $goodsId, string $op, float $qty, string $reason,
                             string $note, string $requestId, string $status,
                             string $responseMessage, string $actor): void
    {
        $this->pdo->prepare("
            INSERT INTO efris_stock_log
                (goods_id, op, qty, reason, note, request_id, status, response_message, actor)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)")
            ->execute([$goodsId, $op, $qty, $reason, $note, $requestId, $status, $responseMessage, $actor]);
    }

    public function stockLog(int $goodsId = 0, int $limit = 100): array
    {
        $sql = "SELECT l.*, g.name AS goods_name FROM efris_stock_log l
                LEFT JOIN efris_goods g ON g.id = l.goods_id";
        $vals = [];
        if ($goodsId > 0) { $sql .= " WHERE l.goods_id = ?"; $vals[] = $goodsId; }
        $sql .= " ORDER BY l.created_at DESC, l.id DESC LIMIT " . max(1, min(1000, $limit));
        $st = $this->pdo->prepare($sql);
        $st->execute($vals);
        return $st->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    public function counts(): array
    {
        $out = ['total' => 0, 'registered' => 0, 'stocked' => 0];
        foreach ($this->pdo->query("SELECT status, stocked, COUNT(*) c FROM efris_goods GROUP BY status, stocked") as $r) {
            $out['total'] += (int)$r['c'];
            if ($r['status'] === self::ST_REGISTERED) $out['registered'] += (int)$r['c'];
            if ((int)$r['stocked'] === 1)             $out['stocked'] += (int)$r['c'];
        }
        return $out;
    }
}
