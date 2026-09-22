<?php
declare(strict_types=1);
namespace Dn\Radius;

/**
 * A deterministic AAA publisher that touches NOTHING outside this process.
 *
 * It exists so the activation path can be built and tested before the real
 * FreeRADIUS binding is authorized (F6-B). It keeps its state in memory,
 * deliberately: a simulator that wrote to a database would leave rows an
 * operator could later mistake for published credentials.
 *
 * It enforces the contracts it stands in for rather than merely accepting
 * calls, because a permissive double teaches the caller nothing:
 *
 *   - Decision 3 — a voucher code must never reach this path. A secret that
 *     looks like a voucher code is REFUSED, permanently.
 *   - Decision 2b — a credential with no site is refused. docs/70 S5 measured
 *     that such a credential authenticates nowhere, so accepting it here would
 *     simulate a success the real system cannot produce.
 *
 * isSimulated() is true and every PublishResult says so.
 */
final class SimulatedPublisher implements RadiusPublisherPort
{
    /** @var array<string, array{site_id:string, expires_at:?string}> */
    private array $published = [];

    /** The shape CodeGenerator emits: groups of A-Z2-9 separated by hyphens. */
    private const VOUCHER_CODE_SHAPE = '/^[A-Z2-9]{4,6}(-[A-Z2-9]{4,6})+$/';

    public function publish(\Dn\Db\Database $db, array $credential): PublishResult
    {
        $user   = (string) ($credential['radius_username'] ?? '');
        $secret = (string) ($credential['secret'] ?? '');
        $site   = $credential['site_id'] ?? null;

        if ($user === '' || $secret === '') {
            return PublishResult::permanent('username and secret are both required', true);
        }
        // Decision 3, enforced rather than trusted. If a caller ever passes the
        // commercial code as the secret this fails loudly at the boundary
        // instead of quietly publishing it into AAA.
        if (preg_match(self::VOUCHER_CODE_SHAPE, $secret) === 1) {
            return PublishResult::permanent(
                'the secret has the shape of a voucher code — Decision 3 forbids it', true);
        }
        // Decision 2b + docs/70 S5: no site means it could authenticate nowhere.
        if ($site === null || $site === '') {
            return PublishResult::permanent(
                'a credential with no site cannot be published under site-bound policy', true);
        }

        $this->published[$user] = [
            'site_id'    => (string) $site,
            'expires_at' => $credential['expires_at'] ?? null,
        ];
        return PublishResult::published(true);
    }

    public function unpublish(\Dn\Db\Database $db, string $radiusUsername): PublishResult
    {
        // Idempotent on purpose: withdrawing something already absent is the
        // desired end state, not an error. Reconciliation depends on that.
        unset($this->published[$radiusUsername]);
        return PublishResult::published(true);
    }

    public function confirm(\Dn\Db\Database $db, string $radiusUsername): bool
    {
        return isset($this->published[$radiusUsername]);
    }

    public function bindingName(): string { return 'simulated'; }
    public function isSimulated(): bool { return true; }

    /** Test/inspection helper. Not part of the port. */
    public function siteOf(string $radiusUsername): ?string
    {
        return $this->published[$radiusUsername]['site_id'] ?? null;
    }
}
