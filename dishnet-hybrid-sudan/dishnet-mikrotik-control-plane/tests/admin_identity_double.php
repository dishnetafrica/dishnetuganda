<?php
declare(strict_types=1);
/** Shared test double. Lives in tests/, never in src/. */
final class FixedStaff implements \Dn\Admin\AdminIdentityPort
{
    public function __construct(private readonly ?\Dn\Admin\StaffIdentity $who) {}
    public function identify(\Dn\Http\Request $req): ?\Dn\Admin\StaffIdentity { return $this->who; }
    public function providerName(): string { return 'test-double'; }
}
