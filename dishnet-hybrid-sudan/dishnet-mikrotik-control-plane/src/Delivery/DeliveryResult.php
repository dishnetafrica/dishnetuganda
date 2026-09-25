<?php
declare(strict_types=1);
namespace Dn\Delivery;

/**
 * What one delivery attempt came to.
 *
 * `simulated` is carried on every result a double produces, so that no
 * caller can mistake a simulator's acceptance for a router's (docs/80 §4:
 * "every simulated response is tagged at the port boundary"). The real
 * adapter never sets it.
 */
final class DeliveryResult
{
    private function __construct(
        public readonly bool $accepted,
        public readonly ?string $error = null,
        public readonly bool $retryable = true,
        public readonly bool $simulated = false,
    ) {}

    public static function accepted(bool $simulated = false): self
    {
        return new self(true, null, true, $simulated);
    }

    /** A transport or timing problem: try again later. */
    public static function retryable(string $why, bool $simulated = false): self
    {
        return new self(false, $why, true, $simulated);
    }

    /** The request itself is wrong. Retrying it will never help. */
    public static function permanent(string $why, bool $simulated = false): self
    {
        return new self(false, $why, false, $simulated);
    }
}
