<?php
declare(strict_types=1);

require_once __DIR__ . '/WaSendBudget.php';

/**
 * OfferWindow — when offers may go (5.18.104 P6, root docs/73 §H.4.2 O-D9; ruling R8). Pure.
 *
 * One window for every proactive kind: the ledger's WaSendBudget::window($config) — by default 08:00–20:00, Monday to
 * Saturday, read in a literal Africa/Kampala (never dn_tz(), never a stored timezone; R-16). The owner may narrow it with
 * Part F's settings, never widen it. This is the sender's pre-filter and the source of its release times; every claim is
 * judged again by the ledger, against the moment its send can end. PHP 7.4.
 */
final class OfferWindow
{
    public const TZ = 'Africa/Kampala';

    /** Is $ts inside the window: not a Sunday, hour_from <= hour < hour_to, in Kampala? */
    public static function open(array $config, int $ts): bool
    {
        [$from, $to] = WaSendBudget::window($config);
        $l = self::local($ts);
        if ((int)$l->format('N') === 7) return false;
        $h = (int)$l->format('G');
        return $h >= $from && $h < $to;
    }

    /** The first moment at or after $ts at which the window is open ($ts itself when it is). */
    public static function nextOpen(array $config, int $ts): int
    {
        if (self::open($config, $ts)) return $ts;
        [$from] = WaSendBudget::window($config);
        $day = self::local($ts);
        $day->setTime(0, 0, 0);
        $midnight = $day->getTimestamp();
        for ($i = 0; $i <= 8; $i++) {
            $d = self::local($midnight + $i * 86400 + 3600);   // past any midnight edge
            $d->setTime($from, 0, 0);
            $at = $d->getTimestamp();
            if ($at >= $ts && self::open($config, $at)) return $at;
        }
        return $ts + 8 * 86400;   // a window that never opens (an unreadable setting): far off, never now
    }

    /** Midnight at the start of the Kampala day after $ts. */
    public static function nextDayStart(int $ts): int
    {
        $d = self::local($ts);
        $d->setTime(0, 0, 0);
        $n = self::local($d->getTimestamp() + 86400 + 3600);
        $n->setTime(0, 0, 0);
        return $n->getTimestamp();
    }

    /** Midnight at the start of $ts's Kampala day. */
    public static function dayStart(int $ts): int
    {
        $d = self::local($ts);
        $d->setTime(0, 0, 0);
        return $d->getTimestamp();
    }

    private static function local(int $t): \DateTime
    {
        return (new \DateTime('@' . $t))->setTimezone(new \DateTimeZone(self::TZ));
    }
}
