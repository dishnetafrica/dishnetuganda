<?php
declare(strict_types=1);

/**
 * VoiceAckLimits — the one holder of the voice-note acknowledgement's limits (5.18.104, root docs/73 §H.3.15; ruling R4).
 *
 * Part V's file, built with the ledger because WaSendBudget::ackPolicy() is a thin delegate to of(): P5, the ledger's
 * voice_ack branch and the switch tool's `set` all read these keys, defaults and ranges here and nowhere else, so they
 * can never disagree. Pure: no store, no clock of its own, no I/O.
 *
 * The hours only narrow D15's every-day window (hour_from 7-20, hour_to from+1-21); a wider one is an owner answer,
 * never a setting. of() answers null on any stored value it cannot use — unparseable, out of range, or from >= to —
 * and every reader then sends nothing (the ledger answers `window`, final). Inert until something on Uganda calls it.
 * PHP 7.4.
 */
final class VoiceAckLimits
{
    /** The literal zone D15's hours are judged in, never dn_tz(). */
    public const TZ = 'Africa/Kampala';

    /** The limit's name => the configuration key that holds it. */
    const KEYS = [
        'hour_from'    => 'wa_voice_ack_hour_from',
        'hour_to'      => 'wa_voice_ack_hour_to',
        'gap_s'        => 'wa_voice_ack_gap_s',
        'daily_max'    => 'wa_voice_ack_daily_max',
        'period_hours' => 'wa_voice_ack_period_hours',
    ];

    /** D15 (07:00-21:00 every day), the gap between two acknowledgements on a number, its daily ceiling, the period. */
    const DEFAULTS = [
        'hour_from'    => 7,
        'hour_to'      => 21,
        'gap_s'        => 20,
        'daily_max'    => 25,
        'period_hours' => 24,
    ];

    /** Inclusive [min, max]. hour_to is further bounded below by hour_from + 1 (narrow only, ruling R4). */
    const RANGES = [
        'hour_from'    => [7, 20],
        'hour_to'      => [8, 21],
        'gap_s'        => [5, 120],
        'daily_max'    => [1, 60],
        'period_hours' => [1, 168],
    ];

    /**
     * The five limits, a default where a key is absent; null when any stored value is unparseable or out of its range,
     * or when hour_from >= hour_to.
     *
     * @return array{hour_from:int,hour_to:int,gap_s:int,daily_max:int,period_hours:int}|null
     */
    public static function of(array $config): ?array
    {
        $out = [];
        foreach (self::KEYS as $name => $key) {
            $raw = array_key_exists($key, $config) ? $config[$key] : null;
            if ($raw === null || (is_string($raw) && trim($raw) === '')) {
                $v = self::DEFAULTS[$name];
            } elseif (is_int($raw)) {
                $v = $raw;
            } elseif (is_string($raw) && preg_match('/^\s*\d{1,6}\s*$/', $raw)) {
                $v = (int)trim($raw);
            } else {
                return null;
            }
            [$lo, $hi] = self::RANGES[$name];
            if ($v < $lo || $v > $hi) return null;
            $out[$name] = $v;
        }
        if ($out['hour_from'] >= $out['hour_to']) return null;
        return $out;
    }

    /** Is $nowUtc inside [hour_from, hour_to) in a literal Africa/Kampala, any day of the week? */
    public static function inWindow(array $limits, int $nowUtc): bool
    {
        $h = (int)(new \DateTime('@' . $nowUtc))->setTimezone(new \DateTimeZone(self::TZ))->format('G');
        return $h >= (int)($limits['hour_from'] ?? 24) && $h < (int)($limits['hour_to'] ?? 0);
    }
}
