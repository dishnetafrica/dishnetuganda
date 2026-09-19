<?php
declare(strict_types=1);

/**
 * DpoPaymentStore — the dpo_payments rows, and the claim that stops two
 * settlements happening at once.
 *
 * Plain SQL over the plugin's SQLite handle. It holds no opinion about what a
 * DPO result means; it records what the service decided and refuses writes the
 * schema forbids.
 *
 * ── THE CLAIM ───────────────────────────────────────────────────────────
 *
 * The browser return, DPO's server push and the reconcile cron can all arrive
 * within the same second for one payment. Each would verify with DPO, each
 * would get 000, and each would post a payment to uCRM. Only the second row
 * update would be refused by idx_dpo_crm_payment — by which time a second
 * uCRM payment already exists.
 *
 * So settlement is claimed first, with a conditional UPDATE whose row count is
 * the answer: exactly one caller gets the claim, the others are told to stand
 * down. It is a LEASE, not a lock — held for a bounded time, so a process that
 * dies between claiming and settling does not strand the row forever. The
 * unique index remains the backstop underneath it.
 */
final class DpoPaymentStore
{
    /** The states an attempt can still be worked on in. */
    const OPEN = ['CREATED', 'PENDING', 'REDIRECTED'];

    /** How long one settlement attempt may hold the claim. */
    const CLAIM_SECONDS = 120;

    private \PDO $db;

    public function __construct(\PDO $db) { $this->db = $db; }

    public static function fromStore($store): self { return new self($store->getPdo()); }

    // ── Writing ─────────────────────────────────────────────────────────

    /** @return int the new row id */
    public function create(array $r): int
    {
        $st = $this->db->prepare(
            'INSERT INTO dpo_payments
             (reference, crm_client_id, crm_invoice_id, invoice_number, amount, currency,
              status, environment, attempt_expires_at, created_by)
             VALUES (?,?,?,?,?,?,?,?,?,?)');
        $st->execute([
            $r['reference'], (int)$r['crm_client_id'], (int)$r['crm_invoice_id'],
            (string)($r['invoice_number'] ?? ''), (float)$r['amount'], (string)$r['currency'],
            (string)($r['status'] ?? 'CREATED'), (string)$r['environment'],
            $r['attempt_expires_at'] ?? null, (string)($r['created_by'] ?? 'portal'),
        ]);
        return (int)$this->db->lastInsertId();
    }

    public function byReference(string $reference): ?array
    {
        $st = $this->db->prepare('SELECT * FROM dpo_payments WHERE reference = ?');
        $st->execute([$reference]);
        $row = $st->fetch(\PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    public function byToken(string $token): ?array
    {
        if ($token === '') return null;
        $st = $this->db->prepare('SELECT * FROM dpo_payments WHERE dpo_trans_token = ?');
        $st->execute([$token]);
        $row = $st->fetch(\PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /** The one attempt that may still be paid for this invoice, if any. */
    public function openForInvoice(int $invoiceId): ?array
    {
        $in = implode(',', array_fill(0, count(self::OPEN), '?'));
        $st = $this->db->prepare(
            "SELECT * FROM dpo_payments WHERE crm_invoice_id = ? AND status IN ($in)");
        $st->execute(array_merge([$invoiceId], self::OPEN));
        $row = $st->fetch(\PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /** Fields the schema allows to change. Amount, currency and environment are not among them. */
    public function update(string $reference, array $fields): bool
    {
        $allowed = ['status', 'dpo_trans_token', 'dpo_trans_ref', 'dpo_result', 'dpo_result_text',
                    'payment_method', 'checkout_url', 'crm_payment_id', 'settled_at',
                    'verified_at', 'callback_at', 'failure_reason', 'attempt_expires_at',
                    'settle_claim_at'];
        $set = []; $vals = [];
        foreach ($fields as $k => $v) {
            if (!in_array($k, $allowed, true)) continue;
            $set[] = "$k = ?"; $vals[] = $v;
        }
        if ($set === []) return false;
        $set[] = "updated_at = datetime('now')";
        $vals[] = $reference;
        $st = $this->db->prepare('UPDATE dpo_payments SET ' . implode(', ', $set)
                               . ' WHERE reference = ?');
        return $st->execute($vals);
    }

    /**
     * Take the settlement claim, or find out somebody else holds it.
     *
     * One conditional UPDATE. Its row count is the whole answer: there is no
     * read-then-write window for a second caller to slip through.
     */
    public function claimForSettle(string $reference): bool
    {
        $st = $this->db->prepare(
            "UPDATE dpo_payments
                SET settle_claim_at = datetime('now'), updated_at = datetime('now')
              WHERE reference = ?
                AND status NOT IN ('SUCCESS','REFUNDED')
                AND (settle_claim_at IS NULL
                     OR settle_claim_at < datetime('now', ?))");
        $st->execute([$reference, '-' . self::CLAIM_SECONDS . ' seconds']);
        return $st->rowCount() === 1;
    }

    public function releaseClaim(string $reference): void
    {
        $st = $this->db->prepare(
            "UPDATE dpo_payments SET settle_claim_at = NULL, updated_at = datetime('now')
              WHERE reference = ?");
        $st->execute([$reference]);
    }

    /**
     * Record the settlement. Returns false if the unique index refused it,
     * which means another settlement got there first — not an error to report
     * to a customer, a race we already lost safely.
     */
    public function markSettled(string $reference, int $crmPaymentId, string $method): bool
    {
        try {
            $st = $this->db->prepare(
                "UPDATE dpo_payments
                    SET status = 'SUCCESS', crm_payment_id = ?, payment_method = ?,
                        settled_at = datetime('now'), settle_claim_at = NULL,
                        failure_reason = NULL, updated_at = datetime('now')
                  WHERE reference = ? AND status <> 'SUCCESS'");
            $st->execute([$crmPaymentId, $method, $reference]);
            return $st->rowCount() === 1;
        } catch (\PDOException $e) {
            return false;
        }
    }

    // ── Events ──────────────────────────────────────────────────────────

    /** Append-only. Never a company token, never card data. */
    public function event(int $paymentId, string $event, string $detail = '', string $actor = 'system'): void
    {
        $st = $this->db->prepare(
            'INSERT INTO dpo_payment_events (dpo_payment_id, event, detail, actor) VALUES (?,?,?,?)');
        $st->execute([$paymentId, $event, $detail !== '' ? $detail : null, $actor]);
    }

    public function events(int $paymentId): array
    {
        $st = $this->db->prepare(
            'SELECT * FROM dpo_payment_events WHERE dpo_payment_id = ? ORDER BY id');
        $st->execute([$paymentId]);
        return $st->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    // ── Reading for the cron and the admin screen ───────────────────────

    /**
     * The reconcile cron's working set: attempts still open, left alone for a
     * grace period so the customer's own return gets first refusal.
     *
     * The comparison is <=, not <: a zero grace period has to mean every open
     * row, and a row created in this same second is not strictly less than
     * now.
     */
    public function openOlderThan(int $seconds, int $limit = 50): array
    {
        $in = implode(',', array_fill(0, count(self::OPEN), '?'));
        $st = $this->db->prepare(
            "SELECT * FROM dpo_payments
              WHERE status IN ($in) AND created_at <= datetime('now', ?)
              ORDER BY created_at LIMIT " . max(1, $limit));
        $st->execute(array_merge(self::OPEN, ['-' . max(0, $seconds) . ' seconds']));
        return $st->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    /** @param array $f status, environment, client, invoice, reference, from, to */
    public function search(array $f = [], int $limit = 200): array
    {
        $w = []; $v = [];
        if (!empty($f['status']))      { $w[] = 'status = ?';         $v[] = $f['status']; }
        if (!empty($f['environment'])) { $w[] = 'environment = ?';    $v[] = $f['environment']; }
        if (!empty($f['client']))      { $w[] = 'crm_client_id = ?';  $v[] = (int)$f['client']; }
        if (!empty($f['invoice']))     { $w[] = 'crm_invoice_id = ?'; $v[] = (int)$f['invoice']; }
        if (!empty($f['reference']))   { $w[] = '(reference LIKE ? OR dpo_trans_ref LIKE ?)';
                                         $v[] = '%' . $f['reference'] . '%'; $v[] = '%' . $f['reference'] . '%'; }
        if (!empty($f['from']))        { $w[] = 'created_at >= ?';    $v[] = $f['from']; }
        if (!empty($f['to']))          { $w[] = 'created_at <= ?';    $v[] = $f['to']; }
        $sql = 'SELECT * FROM dpo_payments'
             . ($w ? ' WHERE ' . implode(' AND ', $w) : '')
             . ' ORDER BY created_at DESC LIMIT ' . max(1, $limit);
        $st = $this->db->prepare($sql);
        $st->execute($v);
        return $st->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    /** Counts by status, for the admin header. Test rows are counted apart. */
    public function summary(): array
    {
        $out = ['by_status' => [], 'test_rows' => 0, 'settled_value' => []];
        foreach ($this->db->query('SELECT status, COUNT(*) n FROM dpo_payments GROUP BY status') as $r) {
            $out['by_status'][(string)$r['status']] = (int)$r['n'];
        }
        $out['test_rows'] = (int)$this->db->query(
            "SELECT COUNT(*) FROM dpo_payments WHERE environment = 'test'")->fetchColumn();
        // Revenue is LIVE rows only. A test transaction that reached a revenue
        // figure would be the whole reason environment is stamped per row.
        foreach ($this->db->query(
            "SELECT currency, SUM(amount) s FROM dpo_payments
              WHERE status = 'SUCCESS' AND environment = 'live' GROUP BY currency") as $r) {
            $out['settled_value'][(string)$r['currency']] = (float)$r['s'];
        }
        return $out;
    }
}
