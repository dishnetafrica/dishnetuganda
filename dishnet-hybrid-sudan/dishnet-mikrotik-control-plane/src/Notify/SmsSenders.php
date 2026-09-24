<?php
declare(strict_types=1);
namespace Dn\Notify;

/**
 * Which SMS sender the WORKER runs, from its environment (docs/127 S-5) — the
 * DN_DELIVERY rule of docs/118 D-7, applied to messages:
 *
 *   unset / null     → NullSms            nothing is sent; queued codes expire
 *   africastalking   → AfricasTalkingSms  refuses to start without its
 *                                         username and key
 *   anything else    → refuses to start
 *
 * There is no fallback in any direction: a sender that silently sent nothing
 * would leave operators waiting for codes that never come, and one that
 * silently fell back to another provider would send codes somewhere nobody
 * chose. No reason this class gives contains a secret.
 */
final class SmsSenders
{
    public const ENV   = 'DN_SMS';
    public const MODES = ['null', 'africastalking'];

    /** What DN_SMS asks for, normalised; 'null' when unset. Constructs nothing. */
    public static function configuredName(): string
    {
        $v = strtolower(trim(getenv('DN_SMS') ?: ''));   // literal: the manifest sweep reads it
        return $v === '' ? 'null' : $v;
    }

    public static function fromEnvironment(): SmsSender
    {
        $mode = self::configuredName();
        return match ($mode) {
            'null'           => new NullSms(),
            'africastalking' => self::africasTalking(),
            default          => throw new \RuntimeException(
                self::ENV . "='{$mode}' is not an SMS binding; one of: " . implode(', ', self::MODES)),
        };
    }

    private static function africasTalking(): AfricasTalkingSms
    {
        $user   = trim(getenv('DNB_SMS_USERNAME') ?: '');
        $key    = trim(getenv('DNB_SMS_API_KEY') ?: '');
        $sender = trim(getenv('DNB_SMS_SENDER') ?: '');
        $missing = array_keys(array_filter(['DNB_SMS_USERNAME' => $user, 'DNB_SMS_API_KEY' => $key],
                                           static fn($v) => $v === ''));
        if ($missing !== []) {
            throw new \RuntimeException(
                'DN_SMS=africastalking needs ' . implode(' and ', $missing)
                . '; refusing to start rather than send nothing');
        }
        return new AfricasTalkingSms($user, $key, $sender === '' ? null : $sender);
    }
}
