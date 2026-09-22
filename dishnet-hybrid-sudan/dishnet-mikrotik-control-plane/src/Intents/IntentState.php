<?php
declare(strict_types=1);
namespace Dn\Intents;

/**
 * docs/42 §0, frozen.
 *
 * Note what these names do NOT say: nothing about how or when an intent
 * reaches a router. B1 is unresolved (docs/49), so neither push nor poll may
 * be implied — here, in a message, or in anything rendered to a customer.
 */
final class IntentState
{
    public const QUEUED    = 'queued';
    public const SENT      = 'sent';
    public const CONFIRMED = 'confirmed';
    public const FAILED    = 'failed';
    public const EXPIRED   = 'expired';

    public const TERMINAL = [self::CONFIRMED, self::FAILED, self::EXPIRED];

    public static function isTerminal(string $s): bool
    {
        return in_array($s, self::TERMINAL, true);
    }
}
