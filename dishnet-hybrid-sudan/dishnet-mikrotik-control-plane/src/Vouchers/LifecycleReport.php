<?php
declare(strict_types=1);
namespace Dn\Vouchers;

/**
 * Which voucher lifecycle transitions this system can actually perform.
 *
 * Declared server-side for the same reason SignalReport is: a screen that drew
 * the full commercial lifecycle would imply the product does things it cannot.
 * Measured (docs/92 §4.1) — the ONLY write of `mt_vouchers.state` anywhere in
 * the codebase is `VoucherService::revoke`. Nothing activates and nothing
 * expires.
 *
 * The honest screen therefore shows two reachable states and names the rest as
 * not yet reachable, with what is missing. It does not simulate them: a
 * prototype that fabricates an `active` voucher is less trustworthy than one
 * that says activation is not built.
 */
final class LifecycleReport
{
    public const REACHABLE   = 'reachable';
    public const UNREACHABLE = 'unreachable';

    /** @return list<array{state:string,status:string,via:?string,blocked_by:?string}> */
    public static function voucherStates(): array
    {
        return [
            [
                'state' => 'unused', 'status' => self::REACHABLE,
                'via' => 'VoucherService::issueBatch', 'blocked_by' => null,
            ],
            [
                'state' => 'revoked', 'status' => self::REACHABLE,
                'via' => 'VoucherService::revoke, bound to the customer API', 'blocked_by' => null,
            ],
            [
                'state' => 'activating', 'status' => self::UNREACHABLE,
                'via' => null,
                'blocked_by' => 'The state is not in the mt_vouchers CHECK constraint. Adding it is part of the F-7 remediation, which is not authorized.',
            ],
            [
                'state' => 'active', 'status' => self::UNREACHABLE,
                'via' => null,
                'blocked_by' => 'Redemption has no entry point and the AAA publisher has never been built (Decisions 1, 3 and 7).',
            ],
            [
                'state' => 'expired', 'status' => self::UNREACHABLE,
                'via' => null,
                'blocked_by' => 'Nothing anywhere writes it. Intents have an expiry sweep; vouchers have none.',
            ],
        ];
    }

    public static function summary(): array
    {
        $s = self::voucherStates();
        $ok = array_filter($s, static fn($x) => $x['status'] === self::REACHABLE);
        return [
            'total' => count($s), 'reachable' => count($ok),
            'unreachable' => count($s) - count($ok),
            'note' => 'Activation lifecycle unavailable — the redemption entry point and AAA publisher are not implemented.',
        ];
    }
}
