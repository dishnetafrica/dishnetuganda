<?php
declare(strict_types=1);
namespace Dn\Policy;

/**
 * What a particular RouterOS unit can be told, and where that claim came from.
 *
 * Audit findings R2 and R3 (docs/57 §1). Two numbers lived in `const`s:
 *
 *     const MAX_RATE_BPS = 4294967295;   // Mikrotik-Rate-Limit
 *     const MAX_DEVICES  = 65535;        // hotspot shared-users
 *
 * A `const` is how PHP spells "this is a fact". Neither had been read off a
 * MikroTik. The test that appeared to check them asserted that OUR validator
 * rejected a value above OUR constant — true, circular, and silent about the
 * device. Worse, these are not protocol constants at all: they are properties
 * of a firmware on a model, so a single number could not be right for a fleet
 * even if someone had measured one unit.
 *
 * So the number now travels with its provenance, and `$verified` says plainly
 * whether a physical device ever produced it. Nothing in this class makes an
 * unverified bound behave like a verified one; the difference is carried into
 * the validator's message so that whoever reads it knows which kind of "no"
 * they are being told.
 *
 * WHAT A VERIFIED LIMIT REQUIRES. Not a datasheet and not documentation — a
 * bisection on a unit of that model and firmware: set the attribute, apply it,
 * read it back, and find the largest value the device accepts and honours.
 * Accepting a value is not honouring it, and the read-back is the test.
 *
 * Protocol facts do NOT belong here. Session-Timeout is 32-bit because RFC 2865
 * says so, and that stays a const in PlanValidator: it is true of the wire
 * format regardless of what hardware is on the end of it. This class is for
 * facts about devices.
 */
final class RouterOsLimits
{
    public function __construct(
        public readonly int $maxRateBps,
        public readonly int $maxSharedUsers,
        /** Where these numbers came from, in words, for the error message. */
        public readonly string $provenance,
        /** True only if measured on a physical unit of this model and firmware. */
        public readonly bool $verified = false,
    ) {}

    /**
     * The bound used when nothing has been measured for a device.
     *
     * Chosen to be structurally implausible to reach rather than to be correct:
     * 2^32-1 bps is ~4.29 Gbps and 65535 shared users is far beyond a hotspot,
     * so neither can act as a commercial ceiling by accident (F8/F9 — DishNet
     * does not ration the customer's uplink, and a validator that quietly did
     * so would break that freeze).
     *
     * It is a GUARD RAIL, not a measurement, and it says so. The moment a real
     * bisection exists for a model, it belongs in the source below and this
     * stops being consulted for that model.
     */
    public static function unverified(): self
    {
        return new self(
            maxRateBps:     4294967295,
            maxSharedUsers: 65535,
            provenance:     'provisional bound, never measured on hardware (docs/57 R2, R3)',
            verified:       false,
        );
    }
}
