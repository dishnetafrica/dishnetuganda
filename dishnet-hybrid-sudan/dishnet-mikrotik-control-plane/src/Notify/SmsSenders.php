<?php
declare(strict_types=1);
namespace Dn\Notify;

use Dn\Db\Database;

/**
 * Which SMS sender the WORKER runs (docs/127 S-5; docs/128 SS-9) — the
 * DN_DELIVERY rule of docs/118 D-7, applied to messages:
 *
 *   DN_SMS unset     → PanelSms           the Admin panel's settings decide
 *                                         (migration 033): none until an Admin
 *                                         sets them, so nothing is sent and
 *                                         queued codes expire — phase 2's truth
 *   null             → NullSms            nothing is sent, whatever the panel says
 *   africastalking   → AfricasTalkingSms  refuses to start without its
 *                                         username and key
 *   anything else    → refuses to start
 *
 * Where DN_SMS is set the environment wins and the panel's settings are
 * ignored; the worker reports that, so the panel says so (EnvironmentSms).
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

    /** Whether DN_SMS is set at all. Unset, the Admin panel's settings decide (docs/128 SS-9). */
    public static function isSetInEnvironment(): bool
    {
        return trim(getenv('DN_SMS') ?: '') !== '';
    }

    /**
     * The sender the WORKER runs: the environment's when DN_SMS is set (and it
     * still refuses to start on an incomplete one), otherwise the Admin panel's,
     * followed every tick without a restart.
     */
    public static function forWorker(Database $db): SmsSender
    {
        return self::isSetInEnvironment()
            ? new EnvironmentSms(self::fromEnvironment(), $db)
            : new PanelSms($db);
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
