<?php
declare(strict_types=1);
namespace Dn\Radius;

/**
 * The boundary at which the platform touches AAA.
 *
 * Decision 1 (Model B, docs/66 §1): the AAA credential is published at
 * redemption/activation, never at voucher issue. Decision 3 (docs/67): what is
 * published is a GENERATED credential — the commercial voucher code never
 * enters this path, in any argument, ever.
 *
 * Decision 7 (docs/66 §2) puts publication behind a dedicated AAA Publisher
 * with its own privilege boundary. This interface is that boundary expressed
 * in code. The REAL binding writes to a separate PostgreSQL instance and is
 * F6-B: gated, unauthorized, and not selectable by default (Dn\Runtime\Bindings).
 *
 * Same containment rule as DeliveryPort: ONLY Dn\Jobs may call an
 * implementation. Nothing in Dn\Http or Dn\Api may reference it, so an HTTP
 * request cannot mint a credential within one process.
 */
interface RadiusPublisherPort
{
    /**
     * Publish a generated credential for an activated voucher.
     *
     * @param array{radius_username:string, secret:string, site_id:?string,
     *              expires_at:?string} $credential
     *        `secret` is GENERATED (docs/67). Passing a voucher code here is a
     *        contract violation and implementations must refuse it — the
     *        boundary is enforced, not documented.
     */
    public function publish(\Dn\Db\Database $db, array $credential): PublishResult;

    /** Withdraw a credential — revocation, expiry, or reconciliation repair. */
    public function unpublish(\Dn\Db\Database $db, string $radiusUsername): PublishResult;

    /**
     * Read back whether the credential is actually present and usable.
     *
     * A READ, never the return value of the write — same reasoning as
     * DeliveryPort::confirm. A publisher that accepts and does not apply is a
     * real failure mode.
     */
    public function confirm(\Dn\Db\Database $db, string $radiusUsername): bool;

    /** Human-readable name of this binding, for /health and for audit. */
    public function bindingName(): string;

    /** True when nothing outside this process was actually changed. */
    public function isSimulated(): bool;
}
