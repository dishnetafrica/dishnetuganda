<?php
declare(strict_types=1);
namespace Dn\Delivery;

/**
 * Accepts nothing and confirms nothing.
 *
 * The default binding (Bindings::defaults(), and DN_DELIVERY unset). It exists
 * so the worker can run in a deployment where no delivery path is configured
 * without pretending work was done — an intent simply stays queued and
 * retries, which is the honest behaviour when there is nothing to deliver to.
 */
final class NullDelivery implements DeliveryPort
{
    public function deliver(\Dn\Db\Database $db, array $intent): DeliveryResult
    {
        return DeliveryResult::retryable('no delivery path is configured', simulated: true);
    }

    public function confirm(\Dn\Db\Database $db, array $intent): bool { return false; }

    public function bindingName(): string { return 'null'; }

    /** Not a router, so not evidence about one. */
    public function isSimulated(): bool { return true; }
}
