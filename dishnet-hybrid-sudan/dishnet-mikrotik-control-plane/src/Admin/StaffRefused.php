<?php
declare(strict_types=1);
namespace Dn\Admin;

/** A staff lifecycle act the database refused on purpose (409, never 500). */
final class StaffRefused extends \RuntimeException
{
    public function __construct(string $reason, public readonly string $sqlstate, ?\Throwable $prev = null)
    {
        parent::__construct($reason, 0, $prev);
    }
}
