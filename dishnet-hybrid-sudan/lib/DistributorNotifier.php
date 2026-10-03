<?php
declare(strict_types=1);

require_once __DIR__ . '/WhatsAppChannel.php';
require_once __DIR__ . '/ReplyPrivacyGuard.php';

/**
 * DistributorNotifier — the distributor-notification pilot core (migration 080,
 * WS-A P3, docs/49 §7). Builds a DRAFT alert when something happens to one of a
 * distributor's own customers/leads; a human approves it; the approved alert is
 * QUEUED, not sent (no live transport is bound in the pilot — docs/49 §3.3,
 * Bhavin B-4). Local only; it reads no uCRM and connects to no real number.
 *
 * The three pilot events (docs/49 §7, Bhavin B-5):
 *   lead_attributed      a lead attributed to this distributor (scope lead)
 *   payment_received     a payment from this distributor's customer (ucrm_client)
 *   customer_activated   a new customer activated for this distributor (ucrm_client)
 *
 * Hard rules, each proven in test_distributor_notify.php:
 *   - DRAFT -> APPROVE, never auto-send; approve() routes through a WhatsAppChannel
 *     PORT whose bound adapter in the pilot is NullWhatsAppChannel (sends nothing).
 *   - PRIVACY: every draft body is passed through ReplyPrivacyGuard::check() with
 *     an allow-list of ONLY the owning customer's own identifiers/amount, so a
 *     foreign customer's value can never ride along (docs/49 §11). A blocked body
 *     is never queued.
 *   - CONSENT (CLASS_DISTRIBUTOR): a customer's opt-out never suppresses a
 *     distributor alert; only the distributor's OWN mute does.
 *   - EXACTLY ONCE: dedup_key is UNIQUE, so a replay/double-submit never creates
 *     a second draft (the row's own key is the claim-before-build floor).
 *   - VERIFIED recipient only: a number is a destination only when verified = 1;
 *     0 or >1 verified resolves to none (ambiguous, fail safe).
 *   - NEVER by phone for ownership: the owner comes from dist_customer_links (079);
 *     a phone here is only a verified destination.
 */
class DistributorNotifier
{
    public const EVENTS = ['lead_attributed', 'payment_received', 'customer_activated'];
    public const EVENT_SCOPE = [
        'lead_attributed'    => 'lead',
        'payment_received'   => 'ucrm_client',
        'customer_activated' => 'ucrm_client',
    ];
    public const STATUSES = ['draft', 'approved', 'sent', 'rejected', 'suppressed', 'blocked'];
    public const ROLES = ['owner', 'ops'];

    private \PDO $db;
    private WhatsAppChannel $channel;

    public function __construct(\PDO $db, ?WhatsAppChannel $channel = null)
    {
        $this->db = $db;
        $this->channel = $channel ?? new NullWhatsAppChannel();
    }

    public static function fromStore($store, ?WhatsAppChannel $channel = null): self
    {
        return new self($store->getPdo(), $channel);
    }

    public function channelName(): string { return $this->channel->name(); }
    public function channelIsLive(): bool { return $this->channel->isLive(); }

    private static function scrub($v, int $max): string
    {
        $v = is_scalar($v) ? (string)$v : '';
        $v = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $v) ?? '';
        return mb_substr(trim($v), 0, $max);
    }

    /** A destination only — never an ownership key. Keep a leading +, digits only otherwise. */
    private static function normPhone(string $s): string
    {
        $s = trim($s);
        $plus = (strpos($s, '+') === 0) ? '+' : '';
        return $plus . (preg_replace('/\D+/', '', $s) ?? '');
    }

    private function partnerExists(int $partnerId): bool
    {
        $st = $this->db->prepare("SELECT 1 FROM dist_partners WHERE id = ?");
        $st->execute([$partnerId]);
        return $st->fetchColumn() !== false;
    }

    // ── Contacts — the VERIFIED destination(s) ───────────────────────────────

    /** Add an UNVERIFIED contact number. It is never a destination until verified. */
    public function addContact(int $partnerId, string $phone, string $role, string $actor): array
    {
        if (!$this->partnerExists($partnerId)) throw new \RuntimeException('Partner not found.');
        $phone = self::normPhone($phone);
        if (strlen(preg_replace('/\D/', '', $phone) ?? '') < 7) throw new \RuntimeException('A valid international phone number is required.');
        $role = in_array($role, self::ROLES, true) ? $role : 'owner';
        try {
            $this->db->prepare(
                "INSERT INTO dist_contacts (partner_id, phone, role, verified, created_by, created_at)
                 VALUES (?,?,?,0,?, datetime('now'))"
            )->execute([$partnerId, $phone, $role, self::scrub($actor, 160)]);
        } catch (\PDOException $e) {
            if (stripos($e->getMessage(), 'unique') !== false) throw new \RuntimeException('That number is already recorded for this distributor.');
            throw $e;
        }
        return ['id' => (int)$this->db->lastInsertId(), 'phone' => $phone, 'verified' => false];
    }

    /**
     * Mark a contact verified — a DELIBERATE staff action (docs/49 §9 step 5). A
     * true send-a-code handshake is a later step; for the pilot an admin confirms
     * the number out of band and records it here, as appointment is confirmed.
     */
    public function verifyContact(int $contactId, string $actor): array
    {
        $st = $this->db->prepare("SELECT id FROM dist_contacts WHERE id = ?");
        $st->execute([$contactId]);
        if ($st->fetchColumn() === false) throw new \RuntimeException('Contact not found.');
        $this->db->prepare("UPDATE dist_contacts SET verified = 1, verified_at = datetime('now'), verified_by = ? WHERE id = ?")
                 ->execute([self::scrub($actor, 160), $contactId]);
        return ['id' => $contactId, 'verified' => true];
    }

    /** @return array<int,array> a partner's contacts, newest first */
    public function contactsFor(int $partnerId): array
    {
        $st = $this->db->prepare("SELECT * FROM dist_contacts WHERE partner_id = ? ORDER BY id DESC");
        $st->execute([$partnerId]);
        return $st->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * The single VERIFIED destination for a partner, or null. 0 verified -> null;
     * >1 verified -> null (ambiguous, fail safe — the JobNotifier discipline,
     * docs/49 §5). The pilot keeps exactly one verified number per distributor.
     */
    public function recipientFor(int $partnerId): ?array
    {
        $st = $this->db->prepare("SELECT * FROM dist_contacts WHERE partner_id = ? AND verified = 1 ORDER BY id");
        $st->execute([$partnerId]);
        $rows = $st->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        return count($rows) === 1 ? $rows[0] : null;
    }

    // ── Consent (CLASS_DISTRIBUTOR) ──────────────────────────────────────────

    public function isMuted(int $partnerId): bool
    {
        $st = $this->db->prepare("SELECT muted FROM dist_notify_consent WHERE partner_id = ?");
        $st->execute([$partnerId]);
        return (int)$st->fetchColumn() === 1;
    }

    public function setMuted(int $partnerId, bool $muted, string $actor): void
    {
        $has = $this->db->prepare("SELECT 1 FROM dist_notify_consent WHERE partner_id = ?");
        $has->execute([$partnerId]);
        if ($has->fetchColumn() !== false) {
            $this->db->prepare("UPDATE dist_notify_consent SET muted = ?, updated_by = ?, updated_at = datetime('now') WHERE partner_id = ?")
                     ->execute([$muted ? 1 : 0, self::scrub($actor, 160), $partnerId]);
        } else {
            $this->db->prepare("INSERT INTO dist_notify_consent (partner_id, muted, updated_by, updated_at) VALUES (?,?,?, datetime('now'))")
                     ->execute([$partnerId, $muted ? 1 : 0, self::scrub($actor, 160)]);
        }
    }

    // ── The event -> a draft ─────────────────────────────────────────────────

    private function dedupKey(int $partnerId, string $event, string $scope, string $entityId): string
    {
        return 'DIST:' . $partnerId . ':' . $event . ':' . $scope . ':' . $entityId;
    }

    /**
     * The message a distributor sees. Contains ONLY the owning customer's own
     * data. $entityId is the event's natural/dedup id (lead_id for a lead,
     * payment_id for a payment, client_id for an activation); the displayed
     * client number for a payment comes from ctx['client_id'], because a
     * payment is deduped by payment_id but is about a client.
     */
    private function buildBody(string $event, string $entityId, array $ctx): string
    {
        $name   = self::scrub($ctx['customer_name'] ?? '', 120);
        $amount = self::scrub($ctx['amount_display'] ?? '', 40);
        $client = self::scrub($ctx['client_id'] ?? $entityId, 120);
        switch ($event) {
            case 'lead_attributed':
                $b = 'New lead for you' . ($name !== '' ? ': ' . $name : '') . ' (lead #' . $entityId . '). Please follow up with your customer.';
                break;
            case 'payment_received':
                $b = 'Payment received from your customer' . ($name !== '' ? ' ' . $name : '') . ($amount !== '' ? ': ' . $amount : '') . ' (client #' . $client . ').';
                break;
            case 'customer_activated':
                $b = 'New customer activated in your area' . ($name !== '' ? ': ' . $name : '') . ' (client #' . $entityId . ').';
                break;
            default:
                $b = '';
        }
        return mb_substr($b, 0, 1500);
    }

    /** The allow-list of the owning customer's OWN values, for the privacy guard. */
    private static function permittedFor(string $entityId, array $ctx): array
    {
        $values = array_merge(
            [$entityId, (string)($ctx['client_id'] ?? ''), (string)($ctx['amount_raw'] ?? '')],
            array_map('strval', (array)($ctx['allow'] ?? []))
        );
        $values = array_values(array_filter($values, static fn($v) => $v !== ''));
        return ['values' => $values, 'plain_amounts' => true];
    }

    /**
     * Produce a distributor alert for an event about one of its customers/leads.
     * Returns the outcome; a draft is created only when consent and the privacy
     * guard allow it, and the dedup key is free.
     *
     * @param array $ctx customer_name?, amount_display?, amount_raw?, allow?[]
     * @return array{created:bool, status:string, id?:int, reason?:string, categories?:array, to_phone?:string}
     */
    public function notify(int $partnerId, string $event, string $entityId, array $ctx, string $actor): array
    {
        if (!in_array($event, self::EVENTS, true)) throw new \RuntimeException('Unknown distributor event.');
        if (!$this->partnerExists($partnerId)) throw new \RuntimeException('Partner not found.');
        $scope = self::EVENT_SCOPE[$event];
        $entityId = self::scrub($entityId, 120);
        if ($entityId === '') throw new \RuntimeException('An entity id is required.');

        $key = $this->dedupKey($partnerId, $event, $scope, $entityId);
        $existing = $this->db->prepare("SELECT id, status FROM dist_notify_log WHERE dedup_key = ?");
        $existing->execute([$key]);
        if ($row = $existing->fetch(\PDO::FETCH_ASSOC)) {
            return ['created' => false, 'status' => (string)$row['status'], 'id' => (int)$row['id'], 'reason' => 'duplicate'];
        }

        // The distributor's own mute (never a customer's opt-out). Claim the key with
        // a suppressed audit row and build/send nothing.
        if ($this->isMuted($partnerId)) {
            return $this->insertLog($partnerId, $event, $scope, $entityId, $key, '', '', 'suppressed', 'the distributor muted their own alerts', $ctx, $actor);
        }

        $body = $this->buildBody($event, $entityId, $ctx);
        $guard = ReplyPrivacyGuard::check($body, self::permittedFor($entityId, $ctx));
        if (!$guard['safe']) {
            $res = $this->insertLog($partnerId, $event, $scope, $entityId, $key, '', '', 'blocked', 'privacy guard: ' . implode(',', $guard['categories']), $ctx, $actor);
            $res['categories'] = $guard['categories'];
            return $res;
        }

        $rc = $this->recipientFor($partnerId);
        $toPhone = $rc ? (string)$rc['phone'] : '';
        return $this->insertLog($partnerId, $event, $scope, $entityId, $key, $toPhone, $body, 'draft', $rc ? '' : 'no verified number for this distributor yet', $ctx, $actor);
    }

    private function insertLog(int $partnerId, string $event, string $scope, string $entityId, string $key, string $toPhone, string $body, string $status, string $reason, array $ctx, string $actor): array
    {
        $store = json_encode([
            'customer_name'  => self::scrub($ctx['customer_name']  ?? '', 120),
            'amount_display' => self::scrub($ctx['amount_display'] ?? '', 40),
            'amount_raw'     => self::scrub($ctx['amount_raw']     ?? '', 40),
            'client_id'      => self::scrub($ctx['client_id']      ?? '', 120),
        ], JSON_UNESCAPED_UNICODE) ?: '';
        try {
            $this->db->prepare(
                "INSERT INTO dist_notify_log (partner_id, event, scope, entity_id, dedup_key, to_phone, body, status, reason, context_json, created_by, created_at)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?, datetime('now'))"
            )->execute([$partnerId, $event, $scope, $entityId, $key, $toPhone, $body, $status, self::scrub($reason, 300), $store, self::scrub($actor, 160)]);
        } catch (\PDOException $e) {
            if (stripos($e->getMessage(), 'unique') !== false) {   // a racing caller claimed it first
                $ex = $this->db->prepare("SELECT id, status FROM dist_notify_log WHERE dedup_key = ?");
                $ex->execute([$key]);
                $row = $ex->fetch(\PDO::FETCH_ASSOC) ?: [];
                return ['created' => false, 'status' => (string)($row['status'] ?? ''), 'id' => (int)($row['id'] ?? 0), 'reason' => 'duplicate'];
            }
            throw $e;
        }
        return ['created' => ($status === 'draft'), 'status' => $status, 'id' => (int)$this->db->lastInsertId(), 'to_phone' => $toPhone];
    }

    // ── Draft -> approve ─────────────────────────────────────────────────────

    /** @return array<int,array> drafts awaiting a decision, newest first */
    public function pendingDrafts(int $limit = 100): array
    {
        $st = $this->db->prepare("SELECT * FROM dist_notify_log WHERE status = 'draft' ORDER BY id DESC LIMIT ?");
        $st->bindValue(1, max(1, $limit), \PDO::PARAM_INT);
        $st->execute();
        return $st->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    /** @return array<int,array> recent activity across every status, newest first */
    public function recent(int $limit = 100): array
    {
        $st = $this->db->prepare("SELECT * FROM dist_notify_log ORDER BY id DESC LIMIT ?");
        $st->bindValue(1, max(1, $limit), \PDO::PARAM_INT);
        $st->execute();
        return $st->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    public function get(int $id): ?array
    {
        $st = $this->db->prepare("SELECT * FROM dist_notify_log WHERE id = ?");
        $st->execute([$id]);
        return $st->fetch(\PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Approve a draft and hand it to the channel. In the pilot the channel is
     * NullWhatsAppChannel, so the row becomes 'approved' (queued) and NOTHING is
     * sent. The body may be edited first; the edit is re-checked by the privacy
     * guard against the owning customer's own values, so an edit cannot smuggle a
     * foreign value past the guard.
     *
     * @return array{ok:bool, status?:string, detail?:string, reason?:string}
     */
    public function approve(int $id, string $actor, ?string $editedBody = null): array
    {
        $row = $this->get($id);
        if (!$row) throw new \RuntimeException('Alert not found.');
        if ($row['status'] !== 'draft') return ['ok' => false, 'reason' => 'not a draft (' . $row['status'] . ')'];

        $body = ($editedBody !== null && trim($editedBody) !== '') ? self::scrub($editedBody, 1500) : (string)$row['body'];
        $ctx  = json_decode((string)$row['context_json'], true) ?: [];
        $guard = ReplyPrivacyGuard::check($body, self::permittedFor((string)$row['entity_id'], $ctx));
        if (!$guard['safe']) return ['ok' => false, 'reason' => 'privacy guard: ' . implode(',', $guard['categories'])];

        $toPhone = (string)$row['to_phone'];
        if ($toPhone === '') return ['ok' => false, 'reason' => 'no verified number for this distributor yet — verify one first'];

        $result = $this->channel->send($toPhone, $body);
        $sent = !empty($result['sent']);
        $this->db->prepare(
            "UPDATE dist_notify_log SET status = ?, body = ?, reason = ?, decided_by = ?, decided_at = datetime('now'), sent_at = ? WHERE id = ?"
        )->execute([
            $sent ? 'sent' : 'approved',
            $body,
            self::scrub((string)($result['detail'] ?? ''), 300),
            self::scrub($actor, 160),
            $sent ? date('Y-m-d H:i:s') : '',
            $id,
        ]);
        return ['ok' => true, 'status' => $sent ? 'sent' : 'approved', 'detail' => (string)($result['detail'] ?? '')];
    }

    public function reject(int $id, string $actor, string $note = ''): array
    {
        $row = $this->get($id);
        if (!$row) throw new \RuntimeException('Alert not found.');
        if ($row['status'] !== 'draft') return ['ok' => false, 'reason' => 'not a draft (' . $row['status'] . ')'];
        $this->db->prepare("UPDATE dist_notify_log SET status = 'rejected', reason = ?, decided_by = ?, decided_at = datetime('now') WHERE id = ?")
                 ->execute([self::scrub($note, 300), self::scrub($actor, 160), $id]);
        return ['ok' => true, 'status' => 'rejected'];
    }

    /** @return array<string,int> counts by status, for the admin header */
    public function counts(): array
    {
        $out = ['draft' => 0, 'approved' => 0, 'sent' => 0, 'rejected' => 0, 'suppressed' => 0, 'blocked' => 0];
        foreach ($this->db->query("SELECT status, COUNT(*) c FROM dist_notify_log GROUP BY status")->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $r) {
            $out[(string)$r['status']] = (int)$r['c'];
        }
        return $out;
    }
}
