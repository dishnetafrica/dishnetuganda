<?php
declare(strict_types=1);

/**
 * DistributorPortalData — the distributor-portal's read-only, partner-scoped data
 * layer (WS-A P4a, docs/50 §C/§D, docs/47 §10.3). This is the #1-risk floor: it
 * is the single place a distributor's own attributed customers/leads (and their
 * own partner profile) are read, and it is structurally incapable of returning
 * another distributor's.
 *
 * How the isolation holds (docs/47 §10.3, docs/50 §D):
 *   - SCOPE FROM THE SESSION, NEVER THE REQUEST. The constructor takes a
 *     PartnerContext (built from a verified session row, P4b). There is NO
 *     partner_id parameter on any read method, so a request cannot name a
 *     partner it does not own.
 *   - COMPOSITE-KEY SINGLE FETCHES. A by-id read is `WHERE id = ? AND
 *     partner_id = ?`, so a guessed/foreign id returns null — the API answers
 *     404, and a probe cannot tell "absent" from "not yours".
 *   - ALLOW-LISTED FIELDS ONLY. Rows are projected through an explicit field
 *     allow-list (LINK_PUBLIC / PROFILE_PUBLIC); a column added to a table later
 *     is withheld by default, never leaked. Staff identities (assigned_by),
 *     internal source tags, and billing linkage (ucrm_client_id, tin) are never
 *     returned to a partner. (The Domain-B projection lesson: a view that returns
 *     more is a way to reach a withheld field one row at a time.)
 *   - READ ONLY. No method writes. No uCRM call (P4a reads local link rows only;
 *     any uCRM detail is a later batch, server-side, for the OWN client id only).
 *
 * The partner scope lives in exactly ONE place, scopePartnerId(), so the
 * isolation test can prove it is load-bearing with a weakened copy that drops it
 * (docs/49 §13: "a deliberately-widened scope makes the test fail").
 *
 * P4a: this class and PartnerContext only — no session, no route, no account.
 * Off by default, Uganda-gated, synthetic data (the standing WS-A rules).
 */
class DistributorPortalData
{
    /** The only columns of a dist_customer_links row a partner may see. */
    public const LINK_PUBLIC = ['id', 'scope', 'entity_id', 'assigned_via', 'assigned_at', 'note', 'active'];

    /** The only columns of the partner's OWN dist_partners row a partner may see.
     *  Withheld deliberately: ucrm_client_id / tin / tin_norm (billing linkage),
     *  account_manager_staff_id, created_by, ucrm_linked_by (staff identities). */
    public const PROFILE_PUBLIC = ['partner_code', 'legal_name', 'trading_name', 'status', 'category', 'partner_type', 'trading_currency'];

    protected \PDO $db;
    protected PartnerContext $ctx;

    public function __construct(\PDO $db, PartnerContext $ctx)
    {
        $this->db  = $db;
        $this->ctx = $ctx;
    }

    public static function fromStore($store, PartnerContext $ctx): self
    {
        return new self($store->getPdo(), $ctx);
    }

    /** The PDO handle — protected accessor so a weakened test copy can reuse it. */
    protected function db(): \PDO { return $this->db; }

    /**
     * THE scope. The one place the partner boundary is applied. A weakened copy
     * that overrides or omits this is exactly the mutation the isolation test
     * proves is load-bearing — do not inline the id anywhere else.
     */
    protected function scopePartnerId(): int { return $this->ctx->partnerId(); }

    /** Project a raw row down to an allow-list; unknown/added columns are dropped. */
    public static function project(array $row, array $allow): array
    {
        $out = [];
        foreach ($allow as $k) {
            if (array_key_exists($k, $row)) $out[$k] = $row[$k];
        }
        return $out;
    }

    public static function projectLink(array $row): array { return self::project($row, self::LINK_PUBLIC); }

    // ── The partner's own attributed customers/leads ─────────────────────────

    /**
     * The distributor's own customer/lead links, newest first, allow-listed.
     * Scoped to this context's partner_id — never a parameter, never the request.
     * @return array<int,array>
     */
    public function myLinks(bool $activeOnly = true): array
    {
        $sql = "SELECT * FROM dist_customer_links WHERE partner_id = :pid"
             . ($activeOnly ? " AND active = 1" : "")
             . " ORDER BY id DESC";
        $st = $this->db->prepare($sql);
        $st->execute([':pid' => $this->scopePartnerId()]);
        return array_map([self::class, 'projectLink'], $st->fetchAll(\PDO::FETCH_ASSOC) ?: []);
    }

    /**
     * One own link by id, or null if it is not this partner's (the API 404).
     * Composite predicate: id AND partner_id — a foreign/guessed id resolves to
     * nothing, so a probe cannot distinguish "absent" from "not yours".
     */
    public function myLink(int $linkId): ?array
    {
        $st = $this->db->prepare("SELECT * FROM dist_customer_links WHERE id = :id AND partner_id = :pid");
        $st->execute([':id' => $linkId, ':pid' => $this->scopePartnerId()]);
        $row = $st->fetch(\PDO::FETCH_ASSOC);
        return $row ? self::projectLink($row) : null;
    }

    /** @return array{active:int,total:int,ucrm_client:int,lead:int} counts of own links */
    public function myCounts(): array
    {
        $pid = $this->scopePartnerId();
        $out = ['active' => 0, 'total' => 0, 'ucrm_client' => 0, 'lead' => 0];
        $st = $this->db->prepare(
            "SELECT scope, active, COUNT(*) c FROM dist_customer_links WHERE partner_id = :pid GROUP BY scope, active"
        );
        $st->execute([':pid' => $pid]);
        foreach ($st->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $r) {
            $c = (int)$r['c'];
            $out['total'] += $c;
            if ((int)$r['active'] === 1) {
                $out['active'] += $c;
                $scope = (string)$r['scope'];
                if (isset($out[$scope])) $out[$scope] += $c;
            }
        }
        return $out;
    }

    // ── The partner's own profile ────────────────────────────────────────────

    /**
     * The distributor's OWN partner row, allow-listed; null if it is gone.
     * Scoped by id = the context's partner_id, so there is no "other partner"
     * form of this read at all.
     */
    public function myProfile(): ?array
    {
        $st = $this->db->prepare("SELECT * FROM dist_partners WHERE id = :pid");
        $st->execute([':pid' => $this->scopePartnerId()]);
        $row = $st->fetch(\PDO::FETCH_ASSOC);
        return $row ? self::project($row, self::PROFILE_PUBLIC) : null;
    }
}
