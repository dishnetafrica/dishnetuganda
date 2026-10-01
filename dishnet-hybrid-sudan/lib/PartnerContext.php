<?php
declare(strict_types=1);

/**
 * PartnerContext — the distributor-portal scope (WS-A P4a, docs/50 §D, docs/47 §10.3).
 *
 * A read-only value object carrying WHO the portal request acts for: the
 * distributor (`partner_id`), their portal role, and — reserved for the later
 * outlet roles — the set of outlet ids they may see. It is the ONLY source of
 * scope for every partner-portal read.
 *
 * THE ONE RULE (docs/47 §10.3, docs/50 §D): scope comes from the SESSION, never
 * from the request. A PartnerContext is built from a verified partner session
 * row (P4b) — never from a query string, a form field, a header or a cookie the
 * client controls. The scoped reader (DistributorPortalData) takes a context and
 * has no partner_id parameter of its own, so a request simply cannot name a
 * partner it does not own; an id outside the context resolves to nothing (a 404
 * at the API), and a probe learns neither "absent" nor "forbidden".
 *
 * Fail-closed: a context with a non-positive partner id is invalid and refused
 * at construction, so a half-built or anonymous context can never widen a query.
 *
 * P4a builds the object and the reader only. No session, no route, no account
 * yet; off by default, Uganda-gated, synthetic data — the standing WS-A rules.
 */
class PartnerContext
{
    /** Portal roles (docs/47 §10.1). The pilot issues only head_office; the rest
     *  are reserved so the shape does not change when outlet scoping lands (T-6). */
    public const ROLES = ['head_office', 'branch_manager', 'branch_clerk', 'read_delegate'];

    /** Roles whose visibility is the whole partner (no outlet narrowing). */
    public const PARTNER_WIDE_ROLES = ['head_office', 'read_delegate'];

    private int $partnerId;
    private string $role;
    /** @var array<int,int> outlet ids for an outlet-scoped role; [] otherwise */
    private array $outletIds;

    /**
     * @param int               $partnerId the distributor this request acts for (from the session row)
     * @param string            $role      one of self::ROLES
     * @param array<int,mixed>  $outletIds reserved for outlet roles; coerced to positive ints
     * @throws \InvalidArgumentException on a non-positive partner id or an unknown role
     */
    public function __construct(int $partnerId, string $role = 'head_office', array $outletIds = [])
    {
        if ($partnerId <= 0) {
            // Fail closed: an anonymous or half-built context must never reach a query.
            throw new \InvalidArgumentException('PartnerContext requires a positive partner id.');
        }
        if (!in_array($role, self::ROLES, true)) {
            throw new \InvalidArgumentException('Unknown partner role: ' . $role);
        }
        $this->partnerId = $partnerId;
        $this->role      = $role;
        $ids = [];
        foreach ($outletIds as $o) {
            $n = is_scalar($o) ? (int)$o : 0;
            if ($n > 0) $ids[$n] = $n; // dedupe, drop non-positive
        }
        $this->outletIds = array_values($ids);
    }

    public function partnerId(): int { return $this->partnerId; }

    public function role(): string { return $this->role; }

    /** @return array<int,int> */
    public function outletIds(): array { return $this->outletIds; }

    /** True for a role that sees the whole partner (no outlet narrowing). */
    public function isPartnerWide(): bool { return in_array($this->role, self::PARTNER_WIDE_ROLES, true); }
}
