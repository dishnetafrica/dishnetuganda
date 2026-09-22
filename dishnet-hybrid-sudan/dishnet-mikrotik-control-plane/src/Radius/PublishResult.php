<?php
declare(strict_types=1);
namespace Dn\Radius;

/**
 * The outcome of a publication attempt, and WHICH BINDING PRODUCED IT.
 *
 * `$simulated` is not decoration. A simulator that returns the same shape as
 * the real publisher is indistinguishable from it at the call site, and this
 * project has already found three controls that were accepted-but-inert
 * because nobody could tell. Every result therefore says which world it came
 * from, the flag is carried all the way to /api/v1/admin/health, and a guard
 * test asserts no caller drops it.
 */
final class PublishResult
{
    private function __construct(
        public readonly bool $published,
        public readonly bool $simulated,
        public readonly ?string $error = null,
        public readonly bool $retryable = true,
    ) {}

    public static function published(bool $simulated): self { return new self(true, $simulated); }
    public static function retryable(string $why, bool $simulated): self
    { return new self(false, $simulated, $why, true); }
    /** The request itself is wrong — retrying never helps. */
    public static function permanent(string $why, bool $simulated): self
    { return new self(false, $simulated, $why, false); }
}
