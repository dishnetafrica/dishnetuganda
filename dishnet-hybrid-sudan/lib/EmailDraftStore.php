<?php
/**
 * EmailDraftStore — the queue of AI-written replies waiting for a person.
 *
 * Nothing here sends. This holds what the AI read, what it made of it, what
 * it proposes to say, and what a human decided — so that every reply that
 * eventually reaches a customer can be traced back to the message that
 * prompted it and the person who approved it.
 *
 * The audit trail is the point. "The AI sent it" is not an answer anyone can
 * give a customer or a regulator; "Bhavin approved this wording at 14:32 in
 * response to that message" is.
 *
 * PHP 7.4 compatible.
 */
declare(strict_types=1);

class EmailDraftStore
{
    /** A draft's life. Terminal states are sent, rejected and ignored. */
    const PENDING   = 'pending';    // waiting for a human
    const APPROVED  = 'approved';   // approved, not yet sent
    const SENT      = 'sent';       // delivered to the customer
    const REJECTED  = 'rejected';   // a person said no
    const IGNORED   = 'ignored';    // the filter refused it; kept for visibility
    const FAILED    = 'failed';     // approved, but the send failed

    /** @var PDO */ private $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->migrate();
    }

    private function migrate(): void
    {
        $this->pdo->exec(
            "CREATE TABLE IF NOT EXISTS email_drafts (
                id             INTEGER PRIMARY KEY AUTOINCREMENT,
                message_id     TEXT    NOT NULL UNIQUE,
                thread_id      TEXT    NOT NULL DEFAULT '',
                from_addr      TEXT    NOT NULL,
                from_name      TEXT    NOT NULL DEFAULT '',
                subject        TEXT    NOT NULL DEFAULT '',
                body_excerpt   TEXT    NOT NULL DEFAULT '',
                received_at    TEXT    NOT NULL DEFAULT '',
                crm_client_id  INTEGER NOT NULL DEFAULT 0,
                customer_note  TEXT    NOT NULL DEFAULT '',
                category       TEXT    NOT NULL DEFAULT 'unclear',
                confidence     REAL    NOT NULL DEFAULT 0,
                escalation     TEXT    NOT NULL DEFAULT '',
                draft_subject  TEXT    NOT NULL DEFAULT '',
                draft_body     TEXT    NOT NULL DEFAULT '',
                status         TEXT    NOT NULL DEFAULT 'pending',
                decided_by     TEXT    NOT NULL DEFAULT '',
                decided_at     TEXT    NOT NULL DEFAULT '',
                sent_body      TEXT    NOT NULL DEFAULT '',
                error          TEXT    NOT NULL DEFAULT '',
                created_at     TEXT    NOT NULL DEFAULT (datetime('now'))
             )");
        foreach ([
            'CREATE INDEX IF NOT EXISTS idx_ed_status  ON email_drafts(status, created_at)',
            'CREATE INDEX IF NOT EXISTS idx_ed_from    ON email_drafts(from_addr, created_at)',
            'CREATE INDEX IF NOT EXISTS idx_ed_thread  ON email_drafts(thread_id)',
        ] as $sql) {
            try { $this->pdo->exec($sql); } catch (\Throwable $e) {}
        }
    }

    /**
     * Record a draft, or do nothing if this message was already handled.
     *
     * The mailbox is re-read on every run, so the same message arrives many
     * times; message_id is unique and INSERT OR IGNORE makes a re-read free.
     *
     * @return int the draft id, or 0 if it already existed
     */
    public function add(array $d): int
    {
        $st = $this->pdo->prepare(
            'INSERT OR IGNORE INTO email_drafts
             (message_id, thread_id, from_addr, from_name, subject, body_excerpt,
              received_at, crm_client_id, customer_note, category, confidence,
              escalation, draft_subject, draft_body, status)
             VALUES (:mid,:tid,:from,:fname,:subj,:body,:recv,:cid,:note,:cat,:conf,:esc,:dsubj,:dbody,:status)');
        $st->execute([
            ':mid'   => (string)($d['message_id'] ?? ''),
            ':tid'   => (string)($d['thread_id'] ?? ''),
            ':from'  => (string)($d['from_addr'] ?? ''),
            ':fname' => (string)($d['from_name'] ?? ''),
            ':subj'  => (string)($d['subject'] ?? ''),
            ':body'  => mb_substr((string)($d['body_excerpt'] ?? ''), 0, 4000),
            ':recv'  => (string)($d['received_at'] ?? ''),
            ':cid'   => (int)($d['crm_client_id'] ?? 0),
            ':note'  => (string)($d['customer_note'] ?? ''),
            ':cat'   => (string)($d['category'] ?? 'unclear'),
            ':conf'  => (float)($d['confidence'] ?? 0),
            ':esc'   => (string)($d['escalation'] ?? ''),
            ':dsubj' => (string)($d['draft_subject'] ?? ''),
            ':dbody' => (string)($d['draft_body'] ?? ''),
            ':status'=> (string)($d['status'] ?? self::PENDING),
        ]);
        return $st->rowCount() > 0 ? (int)$this->pdo->lastInsertId() : 0;
    }

    public function seen(string $messageId): bool
    {
        $st = $this->pdo->prepare('SELECT 1 FROM email_drafts WHERE message_id = ?');
        $st->execute([$messageId]);
        return (bool)$st->fetchColumn();
    }

    public function get(int $id): ?array
    {
        $st = $this->pdo->prepare('SELECT * FROM email_drafts WHERE id = ?');
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** @return array<int,array> newest first */
    public function listByStatus(string $status, int $limit = 50): array
    {
        $st = $this->pdo->prepare(
            'SELECT * FROM email_drafts WHERE status = ? ORDER BY created_at DESC, id DESC LIMIT ?');
        $st->bindValue(1, $status);
        $st->bindValue(2, max(1, $limit), PDO::PARAM_INT);
        $st->execute();
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function counts(): array
    {
        $out = [];
        foreach ($this->pdo->query('SELECT status, COUNT(*) n FROM email_drafts GROUP BY status') as $r) {
            $out[(string)$r['status']] = (int)$r['n'];
        }
        return $out;
    }

    /**
     * How many replies have actually gone to this address recently.
     *
     * Feeds InboundMailFilter::rateLimit. Counts sends, not drafts: a hundred
     * drafts nobody approved are not a loop.
     */
    public function repliesSentTo(string $address, int $windowHours = 24): int
    {
        $st = $this->pdo->prepare(
            "SELECT COUNT(*) FROM email_drafts
             WHERE from_addr = ? AND status = ?
               AND decided_at >= datetime('now', ?)");
        $st->execute([$address, self::SENT, '-' . max(1, $windowHours) . ' hours']);
        return (int)$st->fetchColumn();
    }

    /**
     * Record a human's decision. The body is stored as approved, which may
     * differ from what the AI wrote — an edited reply is the common case and
     * the record must show what actually went out.
     */
    public function decide(int $id, string $status, string $who, string $body = '', string $error = ''): bool
    {
        if (!in_array($status, [self::APPROVED, self::SENT, self::REJECTED, self::FAILED], true)) {
            return false;
        }
        $st = $this->pdo->prepare(
            "UPDATE email_drafts
                SET status = ?, decided_by = ?, decided_at = datetime('now'),
                    sent_body = CASE WHEN ? <> '' THEN ? ELSE sent_body END,
                    error = ?
              WHERE id = ?");
        $st->execute([$status, $who, $body, $body, $error, $id]);
        return $st->rowCount() > 0;
    }

    /** Drop decided rows older than the retention window. */
    public function prune(int $days = 90): int
    {
        $st = $this->pdo->prepare(
            "DELETE FROM email_drafts
              WHERE status IN ('sent','rejected','ignored','failed')
                AND created_at < datetime('now', ?)");
        $st->execute(['-' . max(1, $days) . ' days']);
        return $st->rowCount();
    }
}
