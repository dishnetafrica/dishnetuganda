<?php
declare(strict_types=1);
namespace Dn\Radius;

/**
 * Publishes nothing and confirms nothing. THE DEFAULT.
 *
 * It exists so a deployment with no AAA path configured behaves honestly: the
 * activation stays unpublished and retries, rather than reporting success for
 * work nobody did. That is the current production truth — no voucher has ever
 * produced an AAA credential (docs/00 §525: radcheck at 0 rows) — so the
 * default binding tells that truth rather than hiding it.
 */
final class NullPublisher implements RadiusPublisherPort
{
    public function publish(\Dn\Db\Database $db, array $credential): PublishResult
    { return PublishResult::retryable('no AAA publication path is configured', true); }

    public function unpublish(\Dn\Db\Database $db, string $radiusUsername): PublishResult
    { return PublishResult::retryable('no AAA publication path is configured', true); }

    public function confirm(\Dn\Db\Database $db, string $radiusUsername): bool { return false; }

    public function bindingName(): string { return 'null'; }
    public function isSimulated(): bool { return true; }
}
