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

    /** 5.18.104 (root docs/73 §H.2.10.8): what approve() answers to an edited quotation reminder (the screen's 409). */
    public const QUOTE_WORDING_ONLY = 'A quotation reminder is sent only in the approved wording; reject it and write to the customer from the Inbox.';
    /** 5.18.104 (root docs/73 §H.2.10.3): a quote chase has three steps, day 1, 3 and 7. */
    public const QUOTE_ATTEMPTS = 3;

    private \PDO $db;

    /** 5.18.104 (root docs/73 §H.2.3 (a)): whether followups has 089's `kind` column, asked once. */
    private ?bool $hasKind = null;

    public function __construct(\PDO $db) { $this->db = $db; }

    /**
     * 5.18.104 (root docs/73 §H.2.3 (a), S2-19): whether 089's `kind` column is there — a memoised PRAGMA table_info. A
     * partial 089 (its ALTER lost to a lock at first boot) is marked applied and never retried, so every 5.18.104 reader
     * of followups.kind asks here first, and a book without the column runs 5.18.103's SQL.
     */
    public function hasKind(): bool
    {
        if ($this->hasKind === null) {
            $this->hasKind = false;
            try {
                foreach ($this->db->query('PRAGMA table_info(followups)')->fetchAll(\PDO::FETCH_ASSOC) as $c) {
                    if ((string)($c['name'] ?? '') === 'kind') $this->hasKind = true;
                }
            } catch (\Throwable $e) { $this->hasKind = false; }
        }
        return $this->hasKind;
    }

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
        // 5.18.104 (root docs/73 §H.2.3 (b), R-11, F1): enquiry rows only, never a quote's — unaliased, as the query is.
        $kind = $this->hasKind() ? " AND COALESCE(kind,'enquiry')='enquiry'" : '';
        $st = $this->db->prepare(
            "SELECT * FROM followups
              WHERE closed_at IS NULL AND due_at IS NOT NULL AND due_at <= ?" . $kind . "
              ORDER BY due_at LIMIT ?");
        $st->execute([$nowUtc, $limit]);
        return $st->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * @param string $kind 5.18.104 (root docs/73 §H.2.3 (g)): '' every row, as before; 'enquiry' or 'quote' that kind
     *                     only, where hasKind() (without the column, every row is an enquiry: 5.18.103's query)
     * @return array<int,array<string,mixed>>
     */
    public function allOpen(int $limit = 200, string $kind = ''): array
    {
        $and = ($kind !== '' && $this->hasKind()) ? " AND COALESCE(kind,'enquiry') = " . $this->db->quote($kind) : '';
        $st = $this->db->prepare(
            "SELECT * FROM followups WHERE closed_at IS NULL" . $and . " ORDER BY due_at LIMIT ?");
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

        // 5.18.104 (root docs/73 §H.2.10.8, K2-21, R104-16): a quotation reminder goes only in its approved wording. The
        // screen always posts the textarea's text, so it is compared first (\r\n as \n, both trimmed): the same text is
        // approved unedited, so the sender's body check matches; any change is refused, and nothing reads "Queued".
        if ($this->isQuoteDraft($d)) {
            if (trim($editedBody) !== '' && trim(str_replace("\r\n", "\n", $editedBody)) !== trim(str_replace("\r\n", "\n", (string)$d['body']))) {
                return ['ok' => false, 'error' => self::QUOTE_WORDING_ONLY];
            }
            $editedBody = '';
        }

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
    /**
     * @param string $andSql 5.18.90 (docs/65 §AD): an extra " AND …" on f.channel with ? placeholders, from
     *                       AutomationPolicy::sqlHeld only — drafts held on a channel that may not send now take none of
     *                       the sender's places. '' leaves the query exactly as it was.
     */
    public function approvedDrafts(int $limit = 20, string $andSql = '', array $args = []): array
    {
        // 5.18.104 (root docs/73 §H.2.3 (b), R-11): enquiry drafts only — a quote's draft is the quote pass's.
        $kind = $this->hasKind() ? " AND COALESCE(f.kind,'enquiry')='enquiry'" : '';
        $st = $this->db->prepare(
            "SELECT d.*, f.channel, f.phone, f.conversation_id, f.attempts
               FROM followup_drafts d JOIN followups f ON f.id = d.followup_id
              WHERE d.status = 'approved' AND f.closed_at IS NULL" . $kind . $andSql . "
              ORDER BY d.decided_at LIMIT ?");
        $st->execute(array_merge($args, [$limit]));
        return $st->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    // ── 5.18.104 (root docs/73 §H.2.3 (c)-(e), §H.2.10): quote chase rows, kind='quote' ─────────────────────────────

    /** A followups row of kind quote (only where hasKind()). */
    private function isQuoteRow(array $fu): bool
    {
        return $this->hasKind() && (string)($fu['kind'] ?? 'enquiry') === 'quote';
    }

    /** A draft whose follow-up is of kind quote. */
    private function isQuoteDraft(array $d): bool
    {
        if (!$this->hasKind()) return false;
        $fu = $this->get((int)($d['followup_id'] ?? 0));
        return $fu !== null && $this->isQuoteRow($fu);
    }

    /**
     * Open the quote chase's follow-up (root docs/73 §H.2.10.3 step 2): kind quote, its quote_key (idx_fu_quote: one
     * chase per quotation, ever), three attempts, the quotation as context, anchored on the quotation's own send (never
     * the customer's last message), step 1 due. The caller holds the transaction and has closed every open enquiry of
     * the phone first (one open follow-up per conversation). Throws when the database refuses it.
     *
     * @param array $conv the conversation the chase leaves on (id, channel, phone, crm fields)
     * @param array $q    quote_key, ref, valid_until, sent_at (UTC), due_at (UTC), crm_client_id
     */
    public function openQuote(array $conv, array $q): int
    {
        $convId = (int)($conv['id'] ?? 0);
        $phone = ContactOptOut::normalise((string)($conv['phone'] ?? ''));
        if ($convId <= 0 || $phone === '') throw new \RuntimeException('no conversation to chase on');
        $st = $this->db->prepare(
            "INSERT INTO followups
                (conversation_id, channel, phone, crm_client_id, crm_link_method, lead_id, topic, product, sales_stage,
                 context_summary, last_customer_at, due_at, max_attempts, opened_by, kind, quote_key)
             VALUES (?,?,?,?,?,?,'quote',?,'quoted',?,?,?,?,'quote_chase','quote',?)");
        $st->execute([
            $convId, (string)($conv['channel'] ?? ''), $phone,
            ((int)($q['crm_client_id'] ?? 0)) ?: (((int)($conv['crm_client_id'] ?? 0)) ?: null),
            ($conv['crm_link_method'] ?? null) ?: null,
            ((int)($conv['lead_id'] ?? 0)) ?: null,
            (string)$q['ref'],
            'quotation ' . (string)$q['ref'] . ', valid until ' . (string)$q['valid_until'],
            (string)$q['sent_at'], (string)$q['due_at'], self::QUOTE_ATTEMPTS, (string)$q['quote_key'],
        ]);
        $id = (int)$this->db->lastInsertId();
        $this->log($id, $convId, 'created', 'quote chase opened; step 1 due ' . (string)$q['due_at'], 'quote_chase');
        return $id;
    }

    /**
     * Approved quote drafts waiting for the quote pass, oldest decision first (root docs/73 §H.2.10.6).
     *
     * @param string $andSql an extra " AND …" on f.channel, from AutomationPolicy::sqlHeld only
     * @return array<int,array<string,mixed>>
     */
    public function approvedQuoteDrafts(int $limit = 50, string $andSql = '', array $args = []): array
    {
        if (!$this->hasKind()) return [];
        $st = $this->db->prepare(
            "SELECT d.*, f.channel, f.phone, f.conversation_id, f.attempts, f.quote_key, f.max_attempts
               FROM followup_drafts d JOIN followups f ON f.id = d.followup_id
              WHERE d.status = 'approved' AND f.closed_at IS NULL AND f.kind = 'quote'" . $andSql . "
              ORDER BY d.decided_at, d.id LIMIT ?");
        $st->execute(array_merge($args, [$limit]));
        return $st->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * A templated draft (root docs/73 §H.2.10.5 step 2): the approved wording, its template id in `model`, logged with
     * actor quote_chase, never ai. One pending draft per follow-up (idx_draft_pending).
     *
     * @return array{ok:bool, id:int, error?:string}
     */
    public function draftTemplate(int $followupId, string $body, string $templateId, string $note = ''): array
    {
        $fu = $this->get($followupId);
        if ($fu === null) return ['ok' => false, 'id' => 0, 'error' => 'no such follow-up'];
        try {
            $this->db->prepare(
                "INSERT INTO followup_drafts (followup_id, attempt, verdict, reason, body, trigger_note, model, status)
                 VALUES (?, ?, 'SEND', 'the approved quotation reminder', ?, ?, ?, 'pending')")
                ->execute([$followupId, (int)$fu['attempts'] + 1, $body, $note, $templateId]);
            $id = (int)$this->db->lastInsertId();
            $this->log($followupId, (int)$fu['conversation_id'], 'drafted', 'SEND — ' . $templateId, 'quote_chase');
            return ['ok' => true, 'id' => $id];
        } catch (\Throwable $e) {
            return ['ok' => false, 'id' => 0, 'error' => $e->getMessage()];
        }
    }

    /**
     * Expire every pending or approved quote draft not decided on the Kampala date $kampalaToday (Y-m-d): the one
     * expiry at the start of each quote_chase run (root docs/73 §H.2.10.5, K2-21). The day's bounds are computed in a
     * literal Africa/Kampala zone. Returns how many.
     */
    public function expireQuoteDrafts(string $kampalaToday): int
    {
        if (!$this->hasKind() || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $kampalaToday)) return 0;
        if (!class_exists('QuoteChasePolicy')) require_once __DIR__ . '/QuoteChasePolicy.php';
        $tz = new \DateTimeZone(\QuoteChasePolicy::TZ);   // the literal Kampala zone, held once there
        $from = (new \DateTime($kampalaToday . ' 00:00:00', $tz))->getTimestamp();
        $to = $from + 86400;
        $st = $this->db->prepare(
            "SELECT d.id FROM followup_drafts d JOIN followups f ON f.id = d.followup_id
              WHERE f.kind = 'quote' AND (d.status = 'pending'
                 OR (d.status = 'approved' AND (d.decided_at IS NULL OR d.decided_at < ? OR d.decided_at >= ?)))");
        $st->execute([gmdate('Y-m-d H:i:s', $from), gmdate('Y-m-d H:i:s', $to)]);
        $n = 0;
        foreach ($st->fetchAll(\PDO::FETCH_COLUMN) as $id) {
            if ($this->expireDraft((int)$id, 'quote_draft_stale')) $n++;
        }
        return $n;
    }

    /** Expire one pending or approved draft with a fixed code. True when this call expired it. */
    public function expireDraft(int $draftId, string $code): bool
    {
        $d = $this->getDraft($draftId);
        if ($d === null) return false;
        $st = $this->db->prepare("UPDATE followup_drafts SET status = 'expired' WHERE id = ? AND status IN ('pending','approved')");
        $st->execute([$draftId]);
        if ($st->rowCount() !== 1) return false;
        $this->log((int)$d['followup_id'], null, 'skipped', 'draft expired: ' . $code, 'quote_chase');
        return true;
    }

    /**
     * A quote step that may have reached the customer (root docs/73 §H.2.10.6, "Uncertain"): the draft is set aside as
     * uncertain and the step counted as taken, the next step armed from the claim's moment, never retried. No
     * followup_sends row is written (nothing is known to have been sent); the ledger's `uncertain` row is the record.
     *
     * @param array $chase window ([from, to]) and at (the claim's moment, unix)
     */
    public function armAfterUncertain(int $followupId, int $draftId, string $why, string $by, array $chase): void
    {
        $this->db->prepare("UPDATE followup_drafts SET status = 'uncertain' WHERE id = ? AND status = 'approved'")
                 ->execute([$draftId]);
        $fu = $this->get($followupId);
        if ($fu === null || ($fu['closed_at'] ?? null) !== null) return;
        $attempt = (int)$fu['attempts'] + 1;
        $this->armNext($fu, $attempt, (int)($chase['at'] ?? time()), $chase, 'may have been sent, so not sent again: ' . $why, $by, 'uncertain');
    }

    /** recordSend's quote form: the sends row, the draft sent, then the next step armed (or the row exhausted). */
    private function recordQuoteSend(array $fu, int $draftId, string $body, string $waMessageId, string $by, array $chase): void
    {
        $attempt = (int)$fu['attempts'] + 1;
        $at = (int)($chase['at'] ?? time());
        try {
            $this->db->prepare(
                "INSERT INTO followup_sends
                    (followup_id, draft_id, attempt, channel, phone, body, wa_message_id, decided_by)
                 VALUES (?,?,?,?,?,?,?,?)")
                ->execute([(int)$fu['id'], $draftId ?: null, $attempt, (string)$fu['channel'],
                           (string)$fu['phone'], $body, $waMessageId ?: null, $by]);
            $this->db->prepare("UPDATE followup_drafts SET status = 'sent' WHERE id = ?")->execute([$draftId]);
            $this->armNext($fu, $attempt, $at, $chase, 'attempt ' . $attempt . ' of ' . (int)$fu['max_attempts'], $by, 'sent');
        } catch (\Throwable $e) {
            error_log('[followup] recordSend bookkeeping failed: ' . $e->getMessage());
        }
    }

    /** Spend attempt $attempt at $at and arm the next step from QuoteChasePolicy; with none left, close exhausted. */
    private function armNext(array $fu, int $attempt, int $at, array $chase, string $what, string $by, string $event): void
    {
        if (!class_exists('QuoteChasePolicy')) require_once __DIR__ . '/QuoteChasePolicy.php';
        $t = $this->db->prepare('SELECT sent_at, valid_until FROM quote_tracker WHERE quote_key = ?');
        $t->execute([(string)($fu['quote_key'] ?? '')]);
        $tr = $t->fetch(\PDO::FETCH_ASSOC) ?: null;
        $next = null;
        if (is_array($tr) && $attempt < self::QUOTE_ATTEMPTS) {
            $sent = (int)strtotime((string)$tr['sent_at'] . ' UTC');
            $n = \QuoteChasePolicy::next($attempt + 1, $sent, (string)$tr['valid_until'], $at, (array)($chase['window'] ?? [8, 20]));
            if ($n['at'] !== null) $next = ['step' => (int)$n['step'], 'at' => (int)$n['at']];
        }
        $attempts = $next === null ? $attempt : max($attempt, $next['step'] - 1);
        $this->db->prepare(
            "UPDATE followups SET attempts = ?, last_sent_at = ?, due_at = ? WHERE id = ? AND closed_at IS NULL")
            ->execute([$attempts, gmdate('Y-m-d H:i:s', $at), $next === null ? null : gmdate('Y-m-d H:i:s', $next['at']), (int)$fu['id']]);
        $this->log((int)$fu['id'], (int)$fu['conversation_id'], $event,
            $what . ($next !== null ? ', next step ' . $next['step'] . ' due ' . gmdate('Y-m-d H:i:s', $next['at']) : ', no further step'), $by);
        if ($next === null) $this->close((int)$fu['id'], 'exhausted', 'the chase has no step left', $by);
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
                               string $waMessageId, string $by, ?array $chase = null): void   // 5.18.104 (root docs/73 §H.2.3 (e)): $chase
    {
        $fu = $this->get($followupId);
        if ($fu === null) return;
        $attempt = (int)$fu['attempts'] + 1;
        // 5.18.104 (root docs/73 §H.2.3 (e), §H.2.10.6 item 11): a quote row's next step is QuoteChasePolicy's, armed from
        // this send; with none left it closes as exhausted, and the tracker stays open until a stop or expiry.
        if ($this->isQuoteRow($fu)) { $this->recordQuoteSend($fu, $draftId, $body, $waMessageId, $by, $chase ?? []); return; }
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
            // attempt, this returns '' and the row is closed as exhausted by
            // the gate on its next look — the cadence ends by arithmetic
            // rather than by a special case.
            //
            // Anchored to the send, not only to the customer's last message:
            // a late attempt 1 used to leave attempt 2 already overdue, so the
            // two went out minutes apart. See FollowUpPolicy::nextDueAfterSend.
            $next = FollowUpPolicy::nextDueAfterSend(
                (string)$fu['last_customer_at'], gmdate('Y-m-d H:i:s'), $attempt + 1);
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

    /**
     * 5.18.54 (docs/46 row 31): a send that may have reached the customer. WhatsApp took the request and no answer came
     * back. The draft leaves the approved list as 'uncertain', so it is not sent again, and the log says why; a person
     * can look at the chat. The follow-up itself stays open: its next draft, if any, still needs approval.
     */
    public function recordUncertain(int $followupId, int $draftId, string $why, string $by): void
    {
        $this->db->prepare("UPDATE followup_drafts SET status = 'uncertain' WHERE id = ? AND status = 'approved'")
                 ->execute([$draftId]);
        $this->log($followupId, null, 'uncertain', 'may have been sent, so not sent again: ' . $why, $by);
    }

    /** Follow-ups sent today on a channel — for the daily cap. */
    public function sentTodayOn(string $channel, string $nowUtc): int
    {
        $day = substr($nowUtc, 0, 10);
        // 5.18.104 (root docs/73 §H.2.10.7, K2-22): enquiry sends only, so a chase never takes a place under
        // followup_daily_cap. With no quote rows the count is unchanged; without the column, 5.18.103's query.
        if ($this->hasKind()) {
            $st = $this->db->prepare(
                "SELECT COUNT(*) FROM followup_sends s JOIN followups f ON f.id = s.followup_id
                  WHERE s.channel = ? AND s.sent_at >= ? AND COALESCE(f.kind,'enquiry')='enquiry'");
            $st->execute([$channel, $day . ' 00:00:00']);
            return (int)$st->fetchColumn();
        }
        $st = $this->db->prepare(
            "SELECT COUNT(*) FROM followup_sends WHERE channel = ? AND sent_at >= ?");
        $st->execute([$channel, $day . ' 00:00:00']);
        return (int)$st->fetchColumn();
    }
}
