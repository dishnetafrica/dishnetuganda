<?php
declare(strict_types=1);

/**
 * QuoteChasePolicy — when each step of a quote chase is due (5.18.104, Uganda, root docs/73 §H.2.10.1). Pure.
 *
 * Three steps, 24, 72 and 168 hours from the quotation's own send (`sent_at`, never the customer's last message):
 *
 *   - steps 1 and 2 move FORWARD to the next opening of the proactive window (WaSendBudget::window(), default Monday to
 *     Saturday 08:00-20:00 Kampala; Sunday is never open);
 *   - floors: after a late send, step 2 is never sooner than 48 h after it, and step 3 never sooner than 96 h;
 *   - step 3 is T = min(sent_at + 168 h, 16:00 Kampala on valid_until). Out of hours, after 16:00 or on a Sunday, it
 *     moves BACK to 16:00 on the last open day at or before T; if that breaks the 96 h floor it is not sent
 *     (`skipped_floor`);
 *   - validity: nothing is drafted or sent after 20:00 Kampala on valid_until (`past_validity`).
 *
 * Every time computation uses a literal Africa/Kampala zone, never dn_tz() (R-16). A step-3 hour that a narrowed window
 * puts outside it is the window's last hour (min(16, hour_to - 1)), so the moved-back moment is always inside it.
 * PHP 7.4.
 */
final class QuoteChasePolicy
{
    /** The literal zone every computation here uses. */
    public const TZ = 'Africa/Kampala';

    /** Hours from sent_at, per step (D9's day 1, 3 and 7). */
    const SCHEDULE = [1 => 24, 2 => 72, 3 => 168];
    /** The day of each step, as the approved wording names it. */
    const DAY = [1 => 1, 2 => 3, 3 => 7];
    /** Never sooner than this after the previous step's own send. */
    const FLOOR_S = [2 => 48 * 3600, 3 => 96 * 3600];
    /** Step 3's hour on valid_until (before a narrowed window). */
    const STEP3_HOUR = 16;
    /** Nothing after this hour on valid_until. */
    const VALID_CUT_HOUR = 20;

    /**
     * When $step is due, or why it is not sent.
     *
     * @param int      $sentAt     the quotation's send, unix time
     * @param string   $validUntil the Kampala date Y-m-d
     * @param int|null $prevSentAt the previous step's own send (or claim, for an uncertain one); null for step 1
     * @param array    $window     [hour_from, hour_to] (WaSendBudget::window())
     * @return array{at:?int, code:?string} at = unix time; code = skipped_floor | past_validity | bad_step | bad_date
     */
    public static function dueAt(int $step, int $sentAt, string $validUntil, ?int $prevSentAt, array $window): array
    {
        if (!isset(self::SCHEDULE[$step])) return ['at' => null, 'code' => 'bad_step'];
        $cut = self::validEnd($validUntil);
        if ($cut === null) return ['at' => null, 'code' => 'bad_date'];
        [$from, $to] = self::hours($window);
        $floor = ($prevSentAt !== null && isset(self::FLOOR_S[$step])) ? $prevSentAt + self::FLOOR_S[$step] : null;
        $t = $sentAt + self::SCHEDULE[$step] * 3600;

        if ($step < 3) {
            if ($floor !== null && $t < $floor) $t = $floor;
            $t = self::forward($t, $from, $to);
            if ($t >= $cut) return ['at' => null, 'code' => 'past_validity'];
            return ['at' => $t, 'code' => null];
        }

        $h3 = min(self::STEP3_HOUR, $to - 1);
        $t = min($t, self::at($validUntil, $h3));
        $t = self::back($t, $from, $to, $h3);
        if ($floor !== null && $t < $floor) return ['at' => null, 'code' => 'skipped_floor'];
        if ($t >= $cut) return ['at' => null, 'code' => 'past_validity'];
        return ['at' => $t, 'code' => null];
    }

    /**
     * The first step from $step on that is due, with the codes of those passed over; null when none is left.
     *
     * @return array{step:?int, at:?int, skipped:array<int,string>}
     */
    public static function next(int $step, int $sentAt, string $validUntil, ?int $prevSentAt, array $window): array
    {
        $skipped = [];
        for ($s = $step; $s <= 3; $s++) {
            $d = self::dueAt($s, $sentAt, $validUntil, $prevSentAt, $window);
            if ($d['at'] !== null) return ['step' => $s, 'at' => $d['at'], 'skipped' => $skipped];
            $skipped[$s] = (string)$d['code'];
            if ($d['code'] === 'past_validity') {
                for ($r = $s + 1; $r <= 3; $r++) $skipped[$r] = 'past_validity';
                break;
            }
        }
        return ['step' => null, 'at' => null, 'skipped' => $skipped];
    }

    /** 20:00 Kampala on valid_until (unix), or null when the date cannot be read. */
    public static function validEnd(string $validUntil): ?int
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($validUntil))) return null;
        return self::at(trim($validUntil), self::VALID_CUT_HOUR);
    }

    /** Is $t inside the window: Monday to Saturday, hour_from <= hour < hour_to, Kampala? */
    public static function inWindow(int $t, array $window): bool
    {
        [$from, $to] = self::hours($window);
        $l = self::local($t);
        if ((int)$l->format('N') === 7) return false;
        $h = (int)$l->format('G');
        return $h >= $from && $h < $to;
    }

    // ── internals ──────────────────────────────────────────────────────────────────────────────────────────────

    /** [from, to] as ints; anything unreadable reads as the default 8-20 narrowed to nothing wider. */
    private static function hours(array $window): array
    {
        $from = isset($window[0]) ? (int)$window[0] : 8;
        $to   = isset($window[1]) ? (int)$window[1] : 20;
        if ($from < 0 || $from > 23) $from = 8;
        if ($to <= $from || $to > 24) $to = $from + 1;
        return [$from, $to];
    }

    /** $t, or the next opening of the window after it. */
    private static function forward(int $t, int $from, int $to): int
    {
        if (self::inWindow($t, [$from, $to])) return $t;
        $l = self::local($t);
        if ((int)$l->format('N') !== 7 && (int)$l->format('G') < $from) return self::dayAt($l, $from);
        for ($i = 1; $i <= 8; $i++) {
            $d = (clone $l)->modify('+' . $i . ' day');
            if ((int)$d->format('N') !== 7) return self::dayAt($d, $from);
        }
        return $t;
    }

    /** $t when it is in the window and at or before $h3:00; else $h3:00 on the last open day at or before $t. */
    private static function back(int $t, int $from, int $to, int $h3): int
    {
        $l = self::local($t);
        $dayH3 = self::dayAt($l, $h3);
        if ((int)$l->format('N') !== 7 && self::inWindow($t, [$from, $to]) && $t <= $dayH3) return $t;
        if ((int)$l->format('N') !== 7 && $t > $dayH3) return $dayH3;       // after 16:00 (or out of hours late): that day
        for ($i = 1; $i <= 8; $i++) {
            $d = (clone $l)->modify('-' . $i . ' day');
            if ((int)$d->format('N') !== 7) return self::dayAt($d, $h3);
        }
        return $t;
    }

    private static function local(int $t): \DateTime
    {
        return (new \DateTime('@' . $t))->setTimezone(new \DateTimeZone(self::TZ));
    }

    /** $hour:00 Kampala on the date of $l. */
    private static function dayAt(\DateTime $l, int $hour): int
    {
        return self::at($l->format('Y-m-d'), $hour);
    }

    /** $hour:00 Kampala on the date Y-m-d. */
    private static function at(string $ymd, int $hour): int
    {
        return (new \DateTime($ymd . ' ' . sprintf('%02d', $hour) . ':00:00', new \DateTimeZone(self::TZ)))->getTimestamp();
    }
}
