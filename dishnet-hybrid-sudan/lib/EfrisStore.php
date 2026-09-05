<?php
declare(strict_types=1);

/**
 * EfrisStore — the efris_transactions table: one row per fiscal act
 * (invoice, credit note, debit note, cancellation) against a uCRM invoice.
 *
 * This table is the AUDIT TRAIL and the IDEMPOTENCY GUARD in one:
 * UNIQUE(ucrm_invoice_id, kind) makes duplicate fiscalisation impossible at
 * the database level, and request/response payloads are stored verbatim so
 * every value shown anywhere (FDN, verification code, QR data) traces back
 * to what EFRIS actually returned. Nothing in here is ever synthesised.
 *
 * The EFRIS status lives ONLY here — uCRM's own invoice status (PAID etc.)
 * is never written by this layer. PAID and FISCALISED are separate facts.
 */
class EfrisStore
{
    public const KIND_INVOICE     = 'invoice';
    public const KIND_CREDIT_NOTE = 'credit_note';
    public const KIND_DEBIT_NOTE  = 'debit_note';
    public const KIND_CANCEL      = 'cancel';

    private \PDO $pdo;

    public function __construct(\PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->ensureSchema();
    }

    private function ensureSchema(): void
    {
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS efris_transactions (
                id               INTEGER PRIMARY KEY AUTOINCREMENT,
                ucrm_invoice_id  INTEGER NOT NULL,
                invoice_number   TEXT    NOT NULL DEFAULT '',
                kind             TEXT    NOT NULL DEFAULT 'invoice',
                environment      TEXT    NOT NULL DEFAULT 'test',
                client_id        INTEGER,
                client_name      TEXT,
                amount           REAL,
                currency         TEXT,
                request_id       TEXT,
                status           TEXT    NOT NULL DEFAULT 'PENDING',
                fdn              TEXT,
                verification_code TEXT,
                qr_data          TEXT,
                efris_reference  TEXT,
                submitted_at     TEXT,
                fiscalised_at    TEXT,
                response_code    TEXT,
                response_message TEXT,
                request_payload  TEXT,
                response_payload TEXT,
                retry_count      INTEGER NOT NULL DEFAULT 0,
                linked_invoice_id INTEGER,
                created_at       TEXT NOT NULL DEFAULT (datetime('now')),
                updated_at       TEXT NOT NULL DEFAULT (datetime('now'))
            )
        ");
        $this->pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_efris_inv_kind
                          ON efris_transactions(ucrm_invoice_id, kind)");
        $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_efris_status
                          ON efris_transactions(status, created_at DESC)");
    }

    /**
     * Claim the (invoice, kind) slot. Returns [row, createdNow]. If another
     * process claimed it first, the existing row comes back with
     * createdNow=false — the caller then decides based on its status,
     * which is what makes double submission impossible rather than impolite.
     */
    public function beginSubmission(
        int $ucrmInvoiceId,
        string $invoiceNumber,
        string $environment,
        array $extra = [],
        string $kind = self::KIND_INVOICE
    ): array {
        $st = $this->pdo->prepare("
            INSERT OR IGNORE INTO efris_transactions
                (ucrm_invoice_id, invoice_number, kind, environment,
                 client_id, client_name, amount, currency, request_id, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'PENDING')
        ");
        $st->execute([
            $ucrmInvoiceId, $invoiceNumber, $kind, $environment,
            $extra['client_id'] ?? null, $extra['client_name'] ?? null,
            $extra['amount'] ?? null, $extra['currency'] ?? null,
            $extra['request_id'] ?? null,
        ]);
        $createdNow = $st->rowCount() > 0;
        return [$this->find($ucrmInvoiceId, $kind), $createdNow];
    }

    public function find(int $ucrmInvoiceId, string $kind = self::KIND_INVOICE): ?array
    {
        $st = $this->pdo->prepare(
            "SELECT * FROM efris_transactions WHERE ucrm_invoice_id = ? AND kind = ?");
        $st->execute([$ucrmInvoiceId, $kind]);
        $row = $st->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function get(int $id): ?array
    {
        $st = $this->pdo->prepare("SELECT * FROM efris_transactions WHERE id = ?");
        $st->execute([$id]);
        $row = $st->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** Whitelisted column update; updated_at maintained here. */
    public function update(int $id, array $fields): void
    {
        static $allowed = [
            'status', 'fdn', 'verification_code', 'qr_data', 'efris_reference',
            'submitted_at', 'fiscalised_at', 'response_code', 'response_message',
            'request_payload', 'response_payload', 'request_id', 'retry_count',
            'linked_invoice_id', 'environment', 'client_id', 'client_name',
            'amount', 'currency', 'invoice_number',
        ];
        $sets = []; $vals = [];
        foreach ($fields as $k => $v) {
            if (!in_array($k, $allowed, true)) continue;
            $sets[] = "[$k] = ?"; $vals[] = $v;
        }
        if (!$sets) return;
        $sets[] = "updated_at = datetime('now')";
        $vals[] = $id;
        $this->pdo->prepare(
            "UPDATE efris_transactions SET " . implode(', ', $sets) . " WHERE id = ?"
        )->execute($vals);
    }

    public function bumpRetry(int $id): void
    {
        $this->pdo->prepare("
            UPDATE efris_transactions
               SET retry_count = retry_count + 1, updated_at = datetime('now')
             WHERE id = ?")->execute([$id]);
    }

    /** Admin listing with optional filters: status, kind, from, to, q (number/client). */
    public function recent(array $f = [], int $limit = 200): array
    {
        $where = []; $vals = [];
        if (!empty($f['status'])) { $where[] = 'status = ?';        $vals[] = $f['status']; }
        if (!empty($f['kind']))   { $where[] = 'kind = ?';          $vals[] = $f['kind']; }
        if (!empty($f['from']))   { $where[] = "created_at >= ?";   $vals[] = $f['from'] . ' 00:00:00'; }
        if (!empty($f['to']))     { $where[] = "created_at <= ?";   $vals[] = $f['to'] . ' 23:59:59'; }
        if (!empty($f['q'])) {
            $where[] = '(invoice_number LIKE ? OR client_name LIKE ?)';
            $vals[] = '%' . $f['q'] . '%'; $vals[] = '%' . $f['q'] . '%';
        }
        $sql = 'SELECT * FROM efris_transactions'
             . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
             . ' ORDER BY created_at DESC LIMIT ' . max(1, min(1000, $limit));
        $st = $this->pdo->prepare($sql);
        $st->execute($vals);
        return $st->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    /** Report counters: rows per status and per kind. */
    public function counts(): array
    {
        $out = ['by_status' => [], 'by_kind' => [], 'total' => 0];
        foreach ($this->pdo->query(
            "SELECT status, COUNT(*) c FROM efris_transactions GROUP BY status") as $r) {
            $out['by_status'][$r['status']] = (int)$r['c'];
            $out['total'] += (int)$r['c'];
        }
        foreach ($this->pdo->query(
            "SELECT kind, COUNT(*) c FROM efris_transactions GROUP BY kind") as $r) {
            $out['by_kind'][$r['kind']] = (int)$r['c'];
        }
        return $out;
    }
}
