<?php
declare(strict_types=1);
namespace Dn\Vouchers;

/**
 * Where a voucher code comes from.
 *
 * An interface rather than a concrete dependency so the collision-retry path
 * can be driven deliberately in a test. At 32^10 a natural collision would
 * never occur in a test run, so without a seam that recovery code would ship
 * having never executed once.
 */
interface CodeSource
{
    public function generate(): string;
}
