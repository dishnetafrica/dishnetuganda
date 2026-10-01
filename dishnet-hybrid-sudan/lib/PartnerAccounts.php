<?php
declare(strict_types=1);

require_once __DIR__ . '/PartnerContext.php';
require_once __DIR__ . '/PartnerSession.php';

/**
 * PartnerAccounts — the partner-portal account manager (WS-A P4b, docs/50 §C,
 * docs/47 §10.3, migration 081 table dist_partner_users).
 *
 * A STAFF-side writer: a DishNet admin / channel manager creates, disables or
 * re-roles a distributor's portal login. The actor is passed in from the
 * identity boundary (never a request field), exactly as the rest of the
 * distributor writers do. The account record grants nothing by itself — the
 * distributor still cannot sign in until the OTP+TOTP path (P4c) is built and
 * the portal is enabled.
 *
 * Rules baked in:
 *   - ONE ACCOUNT PER CANONICAL PHONE. The sign-in phone is the resolution key,
 *     so a duplicate phone is a REFUSAL, never an upsert (the Domain-B P-B
 *     discipline; a duplicate would let a one-time code bind to an arbitrary
 *     account). The partial unique index is the floor beneath this check.
 *   - DISABLE AND RE-ROLE REVOKE LIVE SESSIONS in the same transaction, so a
 *     removed account or a changed role cannot keep acting on an old cookie
 *     (docs/50 §B). authenticate() also re-reads status live, so this is
 *     belt-and-suspenders, not the only guard.
 *   - kind/role is STORED, validated against PartnerContext::ROLES, and never
 *     inferred from the request.
 */
class PartnerAccounts
{
    public const STATUSES = ['active', 'disabled'];

    private \PDO $db;

    public function __construct(\PDO $db) { $this->db = $db; }

    public static function fromStore($store): self { return new self($store->getPdo()); }

    public function getDb(): \PDO { return $this->db; }

    private static function scrub($v, int $max): string
    {
        $v = is_scalar($v) ? (string)$v : '';
        $v = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $v) ?? '';
        return mb_substr(trim($v), 0, $max);
    }

    private function partnerExists(int $partnerId): bool
    {
        $st = $this->db->prepare("SELECT 1 FROM dist_partners WHERE id = ?");
        $st->execute([$partnerId]);
        return $st->fetchColumn() !== false;
    }

    /**
     * Create one portal account for a partner. $fields keys: role, display_name,
     * phone (ALREADY canonical — the caller/sign-in path canonicalises), contact_id.
     * There is no status parameter (an account starts active) and no partner in
     * the fields — the partner is the explicit first argument.
     *
     * @return array{id:int}
     * @throws \RuntimeException on a bad partner, unknown role, or a duplicate phone.
     */
    public function create(int $partnerId, array $fields, string $actor): array
    {
        if (!$this->partnerExists($partnerId)) throw new \RuntimeException('Partner not found.');
        $role = self::scrub($fields['role'] ?? 'head_office', 40);
        if (!in_array($role, PartnerContext::ROLES, true)) throw new \RuntimeException('Unknown portal role.');
        $phone = self::scrub($fields['phone'] ?? '', 32);
        if ($phone !== '' && $this->findByPhone($phone) !== null) {
            throw new \RuntimeException('A portal account with this phone already exists.');
        }
        $contactId = isset($fields['contact_id']) && $fields['contact_id'] !== '' ? (int)$fields['contact_id'] : null;

        try {
            $st = $this->db->prepare(
                "INSERT INTO dist_partner_users (partner_id, role, status, display_name, phone, contact_id, created_by, created_at, updated_at)
                 VALUES (?,?, 'active', ?,?,?,?, datetime('now'), datetime('now'))"
            );
            $st->execute([$partnerId, $role, self::scrub($fields['display_name'] ?? '', 160), $phone, $contactId, self::scrub($actor, 160)]);
        } catch (\PDOException $e) {
            if (stripos($e->getMessage(), 'unique') !== false) throw new \RuntimeException('A portal account with this phone already exists.');
            throw $e;
        }
        return ['id' => (int)$this->db->lastInsertId()];
    }

    public function get(int $id): ?array
    {
        $st = $this->db->prepare("SELECT * FROM dist_partner_users WHERE id = ?");
        $st->execute([$id]);
        return $st->fetch(\PDO::FETCH_ASSOC) ?: null;
    }

    /** Resolve an ACTIVE account by its canonical phone (the sign-in lookup). */
    public function findByPhone(string $phone): ?array
    {
        $phone = trim($phone);
        if ($phone === '') return null;
        $st = $this->db->prepare("SELECT * FROM dist_partner_users WHERE phone = ?");
        $st->execute([$phone]);
        return $st->fetch(\PDO::FETCH_ASSOC) ?: null;
    }

    /** @return array<int,array> accounts of one partner, newest first */
    public function listForPartner(int $partnerId): array
    {
        $st = $this->db->prepare("SELECT * FROM dist_partner_users WHERE partner_id = ? ORDER BY id DESC");
        $st->execute([$partnerId]);
        return $st->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Disable an account and revoke its live sessions in one transaction. An
     * already-disabled account is an idempotent no-op (and still revokes any
     * stragglers). @return bool whether the row existed.
     */
    public function disable(int $userId, string $actor): bool
    {
        $own = !$this->db->inTransaction();
        if ($own) $this->db->beginTransaction();
        try {
            $st = $this->db->prepare(
                "UPDATE dist_partner_users SET status = 'disabled', disabled_at = datetime('now'), disabled_by = ?, updated_at = datetime('now') WHERE id = ?"
            );
            $st->execute([self::scrub($actor, 160), $userId]);
            $existed = $st->rowCount() > 0 || $this->get($userId) !== null;
            PartnerSession::revokeAllForUser($this->db, $userId, 'disabled:' . self::scrub($actor, 48));
            if ($own) $this->db->commit();
            return $existed;
        } catch (\Throwable $e) {
            if ($own && $this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Change an account's role and revoke its live sessions in one transaction
     * (a role change invalidates every session so the new scope is re-proven).
     * @return bool whether a change was made.
     */
    public function setRole(int $userId, string $role, string $actor): bool
    {
        if (!in_array($role, PartnerContext::ROLES, true)) throw new \RuntimeException('Unknown portal role.');
        $own = !$this->db->inTransaction();
        if ($own) $this->db->beginTransaction();
        try {
            $st = $this->db->prepare("UPDATE dist_partner_users SET role = ?, updated_at = datetime('now') WHERE id = ?");
            $st->execute([$role, $userId]);
            $changed = $st->rowCount() > 0;
            PartnerSession::revokeAllForUser($this->db, $userId, 'role_change:' . self::scrub($actor, 48));
            if ($own) $this->db->commit();
            return $changed;
        } catch (\Throwable $e) {
            if ($own && $this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }
}
