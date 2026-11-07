<?php
declare(strict_types=1);

require_once __DIR__ . '/UtcClock.php';

/**
 * HumanTakeover — "a person took over" (5.18.104, root docs/73 §H.2.7.1; contract C7; rulings R3, R4, R18).
 *
 * The one predicate for "a person on our side acted for this customer since a moment": a fromMe message on the
 * conversation NOT sent by the plugin's own automation (known by the ledger, wa_auto_sends, and the automation labels),
 * or a hand-over set after the moment. Time-bounded, never the conversation's sticky state alone (R104-02), and never
 * ConversationService::humanRepliedSince(), which counts the engine's own 'DishNet' rows as a colleague.
 *
 *   since($pdo, $phone, $convIds, $sinceUtc)        P2 (every conversation of the phone but staff), P5 (one; since =
 *                                                   the voice note's received_at − 30 min, ruling R4)
 *   phoneSince($pdo, $phone, $since, $until)        P6's check, at a recipient's judged_at (ruling R18)
 *   phonesSince($pdo, $since, $until)               P6's audience: international digits => the first code
 *
 * The codes, in order: team_message, inbox_reply, handset_media, escalated; null = none. Every time argument is read
 * with UtcClock (a gmdate('c') 'T' never compares as text), and an unreadable one answers `unreadable_time` — from the
 * bulk form for every phone it covers — so a bad argument sends less, never more. A table 089 creates that is absent
 * reads as empty. Read-only; inert until a Uganda sender calls it. PHP 7.4.
 */
final class HumanTakeover
{
    /** The zone the Inbox's image and media rows are dated in (date() under dn_tz_apply() on Uganda). */
    public const TZ = 'Africa/Kampala';
    const INBOX_EVENTS = ['wa_staff_reply', 'wa_send_document'];   // the Inbox's own row, sent_at UTC (includes/api/api_whatsapp.php:249-253, :279-284)
    const LOCAL_EVENTS = ['wa_image_send', 'wa_media_send'];       // the Inbox's own row, sent_at by date(), Kampala (:1004-1009, :1040-1045)
    const AUTOMATION_LABELS = ['', 'DishNet', 'DishNet Plugin', 'DishNet AI', 'DishNet Bot'];   // plus ConversationService::AGENT_AUTO_REPLY

    /** The auto-reply label (lib/ConversationService.php:22), kept here so the read needs no ConversationService. */
    const AUTO_REPLY = 'WhatsApp auto-reply';

    public static function personEvents(): array { return array_merge(self::INBOX_EVENTS, self::LOCAL_EVENTS); }

    /** The first sign since $sinceUtc that a person on our side acted for this customer: a code, or null. */
    public static function since(\PDO $pdo, string $phone, array $convIds, string $sinceUtc): ?string
    {
        $since = self::norm($sinceUtc);
        if ($since === null) return 'unreadable_time';
        return self::judge($pdo, $phone, self::ids($convIds), $since, null);
    }

    /** since() over every conversation of $phone except category='staff', each clause also bounded by $untilUtc (ruling R3). */
    public static function phoneSince(\PDO $pdo, string $phone, string $sinceUtc, ?string $untilUtc = null): ?string
    {
        $since = self::norm($sinceUtc);
        $until = $untilUtc === null ? null : self::norm($untilUtc);
        if ($since === null || ($untilUtc !== null && $until === null)) return 'unreadable_time';
        $st = $pdo->prepare("SELECT id FROM wa_conversations WHERE phone = ? AND COALESCE(category, '') <> 'staff'");
        $st->execute([$phone]);
        return self::judge($pdo, $phone, self::ids($st->fetchAll(\PDO::FETCH_COLUMN)), $since, $until);
    }

    /**
     * The same for every phone at once: international digits => the first code; a phone with none is absent; an
     * unreadable stamp maps every covered phone (each phone of a conversation outside category='staff') to
     * 'unreadable_time' (ruling R3).
     *
     * @return array<string,string>
     */
    public static function phonesSince(\PDO $pdo, string $sinceUtc, ?string $untilUtc = null): array
    {
        $since = self::norm($sinceUtc);
        $until = $untilUtc === null ? null : self::norm($untilUtc);
        if ($since === null || ($untilUtc !== null && $until === null)) {
            $out = [];
            foreach ($pdo->query("SELECT DISTINCT phone FROM wa_conversations WHERE COALESCE(category, '') <> 'staff'")
                         ->fetchAll(\PDO::FETCH_COLUMN) as $p) {
                $out[(string)$p] = 'unreadable_time';
            }
            return $out;
        }
        [$hasLedger, $hasMarks] = self::tables($pdo);
        $notOurs = self::notOursMessage($hasLedger);
        $ub = $until === null ? '' : ' AND m.created_at <= :until';
        $args = [':since' => $since] + ($until === null ? [] : [':until' => $until]);
        $found = [];

        $q = function (string $sql, array $a) use ($pdo): array {
            $st = $pdo->prepare($sql);
            $st->execute($a);
            return array_map('strval', $st->fetchAll(\PDO::FETCH_COLUMN));
        };
        $staffOut = "COALESCE(c.category, '') <> 'staff'";
        $found['team_message'] = $q("SELECT DISTINCT c.phone FROM wa_messages m JOIN wa_conversations c ON c.id = m.conversation_id
                                      WHERE {$staffOut} AND m.direction = 'out' AND m.role = 'agent' AND m.agent_name = 'Team'
                                        AND m.sent_at > :since{$ub}{$notOurs}", $args);
        $found['inbox_reply'] = $q("SELECT DISTINCT c.phone FROM wa_messages m JOIN wa_conversations c ON c.id = m.conversation_id
                                     WHERE {$staffOut} AND m.direction = 'out' AND m.role = 'agent'
                                       AND ((m.event_key IN ('wa_staff_reply','wa_send_document') AND m.sent_at > :since)
                                         OR (m.event_key IN ('wa_image_send','wa_media_send') AND m.sent_at > :since_local))
                                       AND " . self::notAutomation('m') . "{$ub}{$notOurs}",
                                   $args + [':since_local' => self::kampala($since)]);
        $found['handset_media'] = [];
        if ($hasMarks) {
            $mub = $until === null ? '' : ' AND w.seen_at <= :until';
            $found['handset_media'] = $q("SELECT DISTINCT w.phone FROM wa_seen_marks w
                                           WHERE w.direction = 'out' AND w.seen_at > :since{$mub}
                                             AND EXISTS (SELECT 1 FROM wa_conversations c WHERE c.phone = w.phone
                                                          AND c.channel = w.channel AND {$staffOut})"
                                         . self::notOursMark($hasLedger), $args);
        }
        $eub = $until === null ? '' : ' AND e.created_at <= :until';
        $found['escalated'] = $q("SELECT DISTINCT c.phone FROM wa_conversations c
                                   WHERE {$staffOut} AND c.state = 'needs_human'
                                     AND EXISTS (SELECT 1 FROM events e WHERE e.event_type = 'wa.escalation'
                                                  AND e.entity_type = 'conversation' AND e.entity_id = c.id
                                                  AND e.created_at > :since{$eub})", $args);
        $out = [];
        foreach (['team_message', 'inbox_reply', 'handset_media', 'escalated'] as $code) {
            foreach ($found[$code] as $p) if (!isset($out[$p])) $out[$p] = $code;
        }
        return $out;
    }

    // ── the clauses ─────────────────────────────────────────────────────────────────────────────────────────────

    /** The four clauses in order over $convIds, the lower bound each clause's own, the upper on storage time. */
    private static function judge(\PDO $pdo, string $phone, array $convIds, string $since, ?string $until): ?string
    {
        if ($convIds === []) return null;
        [$hasLedger, $hasMarks] = self::tables($pdo);
        $in = implode(',', $convIds);
        $notOurs = self::notOursMessage($hasLedger);
        $ub = $until === null ? '' : ' AND m.created_at <= :until';
        $args = [':since' => $since] + ($until === null ? [] : [':until' => $until]);

        $hit = function (string $sql, array $a) use ($pdo): bool {
            $st = $pdo->prepare($sql . ' LIMIT 1');
            $st->execute($a);
            return $st->fetchColumn() !== false;
        };

        if ($hit("SELECT 1 FROM wa_messages m WHERE m.conversation_id IN ({$in}) AND m.direction = 'out' AND m.role = 'agent'
                   AND m.agent_name = 'Team' AND m.sent_at > :since{$ub}{$notOurs}", $args)) {
            return 'team_message';
        }
        if ($hit("SELECT 1 FROM wa_messages m WHERE m.conversation_id IN ({$in}) AND m.direction = 'out' AND m.role = 'agent'
                   AND ((m.event_key IN ('wa_staff_reply','wa_send_document') AND m.sent_at > :since)
                     OR (m.event_key IN ('wa_image_send','wa_media_send') AND m.sent_at > :since_local))
                   AND " . self::notAutomation('m') . "{$ub}{$notOurs}", $args + [':since_local' => self::kampala($since)])) {
            return 'inbox_reply';
        }
        if ($hasMarks) {
            $st = $pdo->query("SELECT DISTINCT channel FROM wa_conversations WHERE id IN ({$in})");
            $chans = array_map('strval', $st->fetchAll(\PDO::FETCH_COLUMN));
            if ($chans !== []) {
                $ph = [];
                $a = [':since' => $since, ':phone' => $phone] + ($until === null ? [] : [':until' => $until]);
                foreach ($chans as $i => $c) { $ph[] = ':c' . $i; $a[':c' . $i] = $c; }
                $mub = $until === null ? '' : ' AND w.seen_at <= :until';
                if ($hit("SELECT 1 FROM wa_seen_marks w WHERE w.direction = 'out' AND w.phone = :phone
                           AND w.channel IN (" . implode(',', $ph) . ") AND w.seen_at > :since{$mub}"
                         . self::notOursMark($hasLedger), $a)) {
                    return 'handset_media';
                }
            }
        }
        $eub = $until === null ? '' : ' AND e.created_at <= :until';
        if ($hit("SELECT 1 FROM wa_conversations c WHERE c.id IN ({$in}) AND c.state = 'needs_human'
                   AND EXISTS (SELECT 1 FROM events e WHERE e.event_type = 'wa.escalation' AND e.entity_type = 'conversation'
                                AND e.entity_id = c.id AND e.created_at > :since{$eub})", $args)) {
            return 'escalated';
        }
        return null;
    }

    /** "Not ours" for a wa_messages row, NULL-safe (S2-02, K2-12); no ledger table means no automation id exists. */
    private static function notOursMessage(bool $hasLedger): string
    {
        if (!$hasLedger) return '';
        return ' AND (m.wa_message_id IS NULL OR m.wa_message_id NOT IN'
             . ' (SELECT a.wa_message_id FROM wa_auto_sends a WHERE a.wa_message_id IS NOT NULL))';
    }

    /** "Not ours" for a mark: not a ledger id, and not the id of an assistant's or an automation-labelled row. */
    private static function notOursMark(bool $hasLedger): string
    {
        $sql = '';
        if ($hasLedger) {
            $sql .= ' AND w.wa_message_id NOT IN (SELECT a.wa_message_id FROM wa_auto_sends a WHERE a.wa_message_id IS NOT NULL)';
        }
        return $sql . ' AND NOT EXISTS (SELECT 1 FROM wa_messages x WHERE x.wa_message_id = w.wa_message_id'
             . " AND (x.role = 'assistant' OR " . self::isAutomation('x') . '))';
    }

    /** A row's label is not one of the automation labels (a NULL label is the empty one). */
    private static function notAutomation(string $alias): string
    {
        return 'NOT ' . self::isAutomation($alias);
    }

    private static function isAutomation(string $alias): string
    {
        $labels = array_merge(self::AUTOMATION_LABELS, [self::AUTO_REPLY]);
        $q = implode(',', array_map(function ($l) { return "'" . str_replace("'", "''", $l) . "'"; }, $labels));
        return "COALESCE({$alias}.agent_name, '') IN ({$q})";
    }

    /** [wa_auto_sends present, wa_seen_marks present]: 089's tables; an absent one reads as empty (A11-02). */
    private static function tables(\PDO $pdo): array
    {
        $names = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name IN ('wa_auto_sends','wa_seen_marks')")
                     ->fetchAll(\PDO::FETCH_COLUMN);
        return [in_array('wa_auto_sends', $names, true), in_array('wa_seen_marks', $names, true)];
    }

    /** A time argument in the storage form ('Y-m-d H:i:s' UTC), or null when it cannot be read. */
    private static function norm(string $s): ?string
    {
        $t = UtcClock::parse($s);
        return $t === 0 ? null : UtcClock::stamp($t);
    }

    /** The same moment as a Kampala wall-clock stamp: how the Inbox's image and media rows are dated (never "+3"). */
    private static function kampala(string $utcStamp): string
    {
        return (new \DateTime('@' . UtcClock::parse($utcStamp)))->setTimezone(new \DateTimeZone(self::TZ))->format('Y-m-d H:i:s');
    }

    /** @return int[] */
    private static function ids(array $ids): array
    {
        $out = [];
        foreach ($ids as $i) { $i = (int)$i; if ($i > 0) $out[$i] = $i; }
        return array_values($out);
    }
}
