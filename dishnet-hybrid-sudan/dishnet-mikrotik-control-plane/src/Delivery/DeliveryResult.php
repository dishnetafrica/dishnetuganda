<?php
declare(strict_types=1);
namespace Dn\Delivery;

final class DeliveryResult
{
    private function __construct(
        public readonly bool $accepted,
        public readonly ?string $error = null,
        public readonly bool $retryable = true,
    ) {}

    public static function accepted(): self { return new self(true); }

    /** A transport or timing problem: try again later. */
    public static function retryable(string $why): self { return new self(false, $why, true); }

    /** The request itself is wrong. Retrying it will never help. */
    public static function permanent(string $why): self { return new self(false, $why, false); }
}
