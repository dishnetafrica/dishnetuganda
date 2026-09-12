<?php
declare(strict_types=1);

require_once __DIR__ . '/FollowUpPolicy.php';
require_once __DIR__ . '/ContactOptOut.php';

/**
 * FollowUpService — the follow-up state machine, and its audit trail.
 *
 * Every transition writes a followup_events row. That is not bookkeeping for
 * its own sake: this system decides, on its own, whether to message a
 * customer, and months later somebody will ask why a particular person got a
 * particular message — or why another got none. Without the trail the answer
 * is "the AI thought so", which is not an answer.
 *
 * The vocabulary is fixed by the specification:
 *   created evaluated skipped drafted approved rejected sent failed cancelled
 *   opted_out closed
 */
final class FollowUpService
{
    /** Attempts are hard-stopped here regardless of configuration. */
    public const MAX_ATTEMPTS = 2;

    private \PDO $db;

    public function __construct(\PDO $db) { $this->db = $db; }

    public static function fromStore($store): self { return new self($store->getPdo()); }

    // ── Audit ───────────────────────────────────────────────────────────────

    public function log(?int $followupId, ?int $convId, string $event,
                        string $detail = '', string $actor = 'system'): void
    {
        try {
            $this->db->prepare(
                "INSERT INTO followup_events (followup_id, conversation_id, event, detail, actor)
                 VALUES (?, ?, ?, ?, ?)")
                ->execute([$followupId ?: null, $convId ?: null, $event,
                           mb_substr($detail, 0, 1000), $actor]);
        } catch (\Throwable $e) {
            error_log('[followup] audit write failed: ' . $e->getMessage());
        }
    }

    /** @return array<int,array<string,mixed>> */
    public function events(int $followupId, int $limit = 100): array
    {
        $st = $this->db->prepare(
            "SELECT * FROM followup_events WHERE followup_id = ? ORDER BY id LIMIT ?");
        $st->execute([$followupId, $limit]);
        return $st->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    // ── Opening ─────────────────────────────────────────────────────────────

    /**
     * Open a follow-up for a conversation.
     *
     * The unique index is the concurrency control, not a SELECT beforehand.
     * Two scans racing on the same quiet customer both reach the INSERT; one
     * wins and the other is refused by the database. Catching that refusal and
     * returning the existing row is the whole of the locking strategy, and it
     * is correct in a way an application-level check cannot be.
     *
     * @return array{ok:bool, id:int, created:bool, error?:string}
     */
    public function open(array $conv, array $ctx = []): array
    {
        $convId = (int)($conv['id'] ?? 0);
        if ($convId <= 0) return ['ok' => false, 'id' => 0, 'created' => false,
                                  'error' => 'no conversation id'];

        $lastCust = (string)($conv['last_customer_at'] ?? '');
        if ($lastCust === '') return ['ok' => false, 'id' => 0, 'created' => false,
                                      'error' => 'the customer has never written'];

        $due = FollowUpPolicy::dueAt($lastCust, 1);

        try {
            $st = $this->db->prepare(
                "INSERT INTO followups
                    (conversation_id, channel, phone, crm_client_id, crm_link_method, lead_id,
                     topic, product, sales_stage, last_customer_at, due_at, max_attempts, opened_by)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)");
            $st->execute([
                $convId,
                (string)($conv['channel'] ?? 'sales'),
                ContactOptOut::normalise((string)($conv['phone'] ?? '')),
                ((int)($conv['crm_client_id'] ?? 0)) ?: null,
                ($conv['crm_link_method'] ?? null) ?: null,
                ((int)($conv['lead_id'] ?? 0)) ?: null,
                (string)($ctx['topic'] ?? 'unknown'),
                ($ctx['product'] ?? null) ?: null,
                (string)($ctx['sales_stage'] ?? 'unknown'),
                $lastCust,
                $due,
                self::MAX_ATTEMPTS,
                (string)($ctx['opened_by'] ?? 'scan'),
            ]);
            $id = (int)$this->db->lastInsertId();
            $this->log($id, $convId, 'created',
                'quiet since ' . $lastCust . ', first evaluation due ' . $due,
                (string)($ctx['opened_by'] ?? 'scan'));
            return ['ok' => true, 'id' => $id, 'created' => true];
        } catch (\Throwable $e) {
            // The index refused it: somebody else got there first. That is the
            // designed outcome of a race, not a failure.
            $open = $this->openFor($convId);
            if ($open !== null) {
                return ['ok' => true, 'id' => (int)$open['id'], 'created' => false];
            }
            return ['ok' => false, 'id' => 0, 'created' => false, 'error' => $e->getMessage()];
        }
    }

    // ── Reading ─────────────────────────────────────────────────────────────

    public function get(int $id): ?array
    {
        $st = $this->db->prepare("SELECT * FROM followups WHERE id = ?");
        $st->execute([$id]);
        return $st->fetch(\PDO::FETCH_ASSOC) ?: null;
    }

    /** The open follow-up for a conversation, or null. */
    public function openFor(int $convId): ?array
    {
        $st = $this->db->prepare(
            "SELECT * FROM followups WHERE conversation_id = ? AND closed_at IS NULL");
        $st->execute([$convId]);
        return $st->fetch(\PDO::FETCH_ASSOC) ?: null;
    }

    /** Open follow-ups whose due_at has arrived. @return array<int,array<string,mixed>> */
    public function due(string $nowUtc, int $limit = 25): array
    {
        $st = $this->db->prepare(
            "SELECT * FROM followups
              WHERE closed_at IS NULL AND due_at IS NOT NULL AND due_at <= ?
              ORDER BY due_at LIMIT ?");
        $st->execute([$nowUtc, $limit]);
        return $st->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    /** @return array<int,array<string,mixed>> */
    public function allOpen(int $limit = 200): array
    {
        $st = $this->db->prepare(
            "SELECT * FROM followups WHERE closed_at IS NULL ORDER BY due_at LIMIT ?");
        $st->execute([$limit]);
        return $st->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    // ── Moving ──────────────────────────────────────────────────────────────

    /** Push the next evaluation out, without spending an attempt. */
    public function defer(int $id, string $untilUtc, string $why, string $actor = 'system'): bool
    {
        try {
            $this->db->prepare(
                "UPDATE followups SET due_at = ? WHERE id = ? AND closed_at IS NULL")
                ->execute([$untilUtc, $id]);
            $this->log($id, null, 'skipped', $why . ' — next look ' . $untilUtc, $actor);
            return true;
        } catch (\Throwable $e) { return false; }
    }

    /** Record the enquiry context the AI worked out from the thread. */
    public function setContext(int $id, array $c, string $actor = 'ai'): bool
    {
        $fields = []; $vals = [];
        foreach (['topic', 'product', 'sales_stage', 'objection', 'context_summary'] as $k) {
            if (array_key_exists($k, $c) && $c[$k] !== null && $c[$k] !== '') {
                $fields[] = "$k = ?"; $vals[] = (string)$c[$k];
            }
        }
        if ($fields === []) return false;
        $vals[] = $id;
        try {
            $this->db->prepare("UPDATE followups SET " . implode(', ', $fields)
                             . " WHERE id = ? AND closed_at IS NULL")->execute($vals);
            return true;
        } catch (\Throwable $e) { return false; }
    }

    /**
     * Close a follow-up. Terminal — the trigger refuses any later update.
     *
     * Closing an already-closed row is not an error: two workers can both
     * conclude the customer replied, and the second must not throw.
     */
    public function close(int $id, string $reason, string $detail = '', string $actor = 'system'): bool
    {
        $row = $this->get($id);
        if ($row === null) return false;
        if (($row['closed_at'] ?? null) !== null) return true;   // already closed
        try {
            $this->db->prepare(
                "UPDATE followups SET closed_at = datetime('now'), close_reason = ?
                  WHERE id = ? AND closed_at IS NULL")->execute([$reason, $id]);
            $this->log($id, (int)$row['conversation_id'],
                $reason === 'opted_out' ? 'opted_out' : 'closed',
                $reason . ($detail !== '' ? ' — ' . $detail : ''), $actor);
            return true;
        } catch (\Throwable $e) {
            error_log('[followup] close failed: ' . $e->getMessage());
            return false;
        }
    }

    // ── Drafts ──────────────────────────────────────────────────────────────

    /**
     * Record what the AI decided.
     *
     * A verdict is stored whatever it is, including the ones that send
     * nothing. A DO_NOT_SEND that leaves no trace is indistinguishable from an
     * evaluation that never ran, and those are the two states an operator most
     * needs to tell apart.
     *
     * @return array{ok:bool, id:int, error?:string}
     */
    public function draft(int $followupId, array $verdict, string $triggerNote = ''): array
    {
        $fu = $this->get($followupId);
        if ($fu === null) return ['ok' => false, 'id' => 0, 'error' => 'no such follow-up'];

        $v    = strtoupper((string)($verdict['verdict'] ?? 'DO_NOT_SEND'));
        $body = (string)($verdict['message'] ?? '');
        try {
            $st = $this->db->prepare(
                "INSERT INTO followup_drafts
                    (followup_id, attempt, verdict, reason, body, trigger_note, model, status)
                 VALUES (?,?,?,?,?,?,?, 'pending')");
            $st->execute([
                $followupId,
                (int)$fu['attempts'] + 1,
                $v,
                (string)($verdict['reason'] ?? ''),
                $v === 'SEND' ? $body : null,
                $triggerNote,
                (string)($verdict['model'] ?? ''),
            ]);
            $id = (int)$this->db->lastInsertId();
            $this->log($followupId, (int)$fu['conversation_id'], 'drafted',
                $v . ' — ' . (string)($verdict['reason'] ?? ''), 'ai');
            return ['ok' => true, 'id' => $id];
        } catch (\Throwable $e) {
            return ['ok' => false, 'id' => 0, 'error' => $e->getMessage()];
        }
    }

    public function pendingDraft(int $followupId): ?array
    {
        $st = $this->db->prepare(
            "SELECT * FROM followup_drafts WHERE followup_id = ? AND status = 'pending'");
        $st->execute([$followupId]);
        return $st->fetch(\PDO::FETCH_ASSOC) ?: null;
    }

    /** @return array<int,array<string,mixed>> */
    public function pendingDrafts(int $limit = 100): array
    {
        $st = $this->db->prepare(
            "SELECT d.*, f.conversation_id, f.channel, f.phone, f.crm_client_id,
                    f.crm_link_method, f.topic, f.product, f.sales_stage, f.objection,
                    f.context_summary, f.due_at, f.last_customer_at, f.attempts, f.max_attempts
               FROM followup_drafts d
               JOIN followups f ON f.id = d.followup_id
              WHERE d.status = 'pending'
              ORDER BY d.created_at LIMIT ?");
        $st->execute([$limit]);
        return $st->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    public function getDraft(int $id): ?array
    {
        $st = $this->db->prepare("SELECT * FROM followup_drafts WHERE id = ?");
        $st->execute([$id]);
        return $st->fetch(\PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * A person approves the draft. It is not sent here — the sender picks it
     * up — so approving and sending stay separate events in the trail.
     */
    public function approve(int $draftId, string $by, string $editedBody = '', string $note = ''): array
    {
        $d = $this->getDraft($draftId);
        if ($d === null)                  return ['ok' => false, 'error' => 'no such draft'];
        if ($d['status'] !== 'pending')   return ['ok' => false, 'error' => 'already ' . $d['status']];
        if ($d['verdict'] !== 'SEND')     return ['ok' => false,
            'error' => 'this draft proposes ' . $d['verdict'] . ', not a message to send'];

        $final = trim($editedBody) !== '' ? trim($editedBody) : (string)$d['body'];
        if (trim($final) === '')          return ['ok' => false, 'error' => 'there is no message to send'];

        try {
            $this->db->prepare(
                "UPDATE followup_drafts
                    SET status = 'approved', decided_at = datetime('now'), decided_by = ?,
                        decided_note = ?, edited_body = ?
                  WHERE id = ? AND status = 'pending'")
                ->execute([$by, $note, trim($editedBody) !== '' ? trim($editedBody) : null, $draftId]);
            $this->log((int)$d['followup_id'], null, 'approved',
                trim($editedBody) !== '' ? 'approved with edits' : 'approved as written', $by);
            return ['ok' => true, 'body' => $final];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    public function reject(int $draftId, string $by, string $note = ''): array
    {
        $d = $this->getDraft($draftId);
        if ($d === null)                return ['ok' => false, 'error' => 'no such draft'];
        if ($d['status'] !== 'pending') return ['ok' => false, 'error' => 'already ' . $d['status']];
        try {
            $this->db->prepare(
                "UPDATE followup_drafts SET status = 'rejected', decided_at = datetime('now'),
                        decided_by = ?, decided_note = ? WHERE id = ? AND status = 'pending'")
                ->execute([$by, $note, $draftId]);
            $this->log((int)$d['followup_id'], null, 'rejected', $note, $by);
            return ['ok' => true];
        } catch (\Throwable $e) { return ['ok' => false, 'error' => $e->getMessage()]; }
    }

    /** Approved drafts waiting for the sender. @return array<int,array<string,mixed>> */
    public function approvedDrafts(int $limit = 20): array
    {
        $st = $this->db->prepare(
            "SELECT d.*, f.channel, f.phone, f.conversation_id, f.attempts
               FROM followup_drafts d JOIN followups f ON f.id = d.followup_id
              WHERE d.status = 'approved' AND f.closed_at IS NULL
              ORDER BY d.decided_at LIMIT ?");
        $st->execute([$limit]);
        return $st->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    // ── Sending ─────────────────────────────────────────────────────────────

    /**
     * Record a send that has already happened.
     *
     * Called AFTER the message is away, and never throws for that reason: the
     * customer has it, and a bookkeeping failure must not cause a retry that
     * sends it twice.
     */
    public function recordSend(int $followupId, int $draftId, string $body,
                               string $waMessageId, string $by): void
    {
        $fu = $this->get($followupId);
        if ($fu === null) return;
        $attempt = (int)$fu['attempts'] + 1;
        try {
            $this->db->prepare(
                "INSERT INTO followup_sends
                    (followup_id, draft_id, attempt, channel, phone, body, wa_message_id, decided_by)
                 VALUES (?,?,?,?,?,?,?,?)")
                ->execute([$followupId, $draftId ?: null, $attempt, (string)$fu['channel'],
                           (string)$fu['phone'], $body, $waMessageId ?: null, $by]);

            $this->db->prepare(
                "UPDATE followup_drafts SET status = 'sent' WHERE id = ?")->execute([$draftId]);

            // Spend the attempt and arm the next one. When there is no next
            // attempt, dueAt returns '' and the row is closed as exhausted by
            // the gate on its next look — the cadence ends by arithmetic
            // rather than by a special case.
            $next = FollowUpPolicy::dueAt((string)$fu['last_customer_at'], $attempt + 1);
            $this->db->prepare(
                "UPDATE followups SET attempts = ?, last_sent_at = datetime('now'), due_at = ?
                  WHERE id = ? AND closed_at IS NULL")
                ->execute([$attempt, $next ?: null, $followupId]);

            $this->log($followupId, (int)$fu['conversation_id'], 'sent',
                'attempt ' . $attempt . ' of ' . (int)$fu['max_attempts']
                . ($next ? ', next due ' . $next : ', no further attempts'), $by);
        } catch (\Throwable $e) {
            error_log('[followup] recordSend bookkeeping failed: ' . $e->getMessage());
        }
    }

    public function recordFailure(int $followupId, int $draftId, string $why, string $by): void
    {
        $this->log($followupId, null, 'failed', $why, $by);
        // The draft stays approved so the sender retries it; a transport
        // failure is not a decision to abandon the message.
    }

    /** Follow-ups sent today on a channel — for the daily cap. */
    public function sentTodayOn(string $channel, string $nowUtc): int
    {
        $day = substr($nowUtc, 0, 10);
        $st = $this->db->prepare(
            "SELECT COUNT(*) FROM followup_sends WHERE channel = ? AND sent_at >= ?");
        $st->execute([$channel, $day . ' 00:00:00']);
        return (int)$st->fetchColumn();
    }
}
