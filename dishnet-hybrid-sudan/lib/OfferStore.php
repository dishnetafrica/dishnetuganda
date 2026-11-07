<?php
declare(strict_types=1);

require_once __DIR__ . '/UtcClock.php';
require_once __DIR__ . '/OfferText.php';
require_once __DIR__ . '/OfferSwitch.php';
require_once __DIR__ . '/OfferAudience.php';

/**
 * OfferStore — the offer rows of migration 091 and their trail (5.18.104 P6, root docs/73 §H.4.5, §H.4.7, §H.4.12).
 *
 * create, edit and setImage change a draft only, and each recomputes content_hash, so tests of the old content no longer
 * count; start freezes the audience as a snapshot in one BEGIN IMMEDIATE (O-D2), only with every wording tested and
 * recorded in the ledger on the current content within 7 days (C-O1-1's count), and rolls the whole start back when nobody
 * is eligible; pause and resume change the state only; stop skips the queued rows. Every act writes one wa_offer_events
 * row carrying counts and fixed codes only, never a wording or a number. 091's triggers are the floor beneath each rule:
 * an offer is never deleted, what it says is fixed once started, a closed offer is final. Every time is the caller's
 * clock in UTC storage form. PHP 7.4.
 */
final class OfferStore
{
    /** A test proves its wording for this long before a Start (§H.4.7). */
    const TEST_VALID_S = 604800;
    /** The reply and STOP marks: within 72 h of the send (§H.4.12). */
    const MARK_WINDOW_S = 259200;

    // ── reads ──────────────────────────────────────────────────────────────────────────────────────────────────

    public static function find(\PDO $pdo, int $id): ?array
    {
        $st = $pdo->prepare('SELECT * FROM wa_offers WHERE id = ?');
        $st->execute([$id]);
        $r = $st->fetch(\PDO::FETCH_ASSOC);
        return is_array($r) ? $r : null;
    }

    /** The one live offer (running or paused), or null (O-D1). */
    public static function live(\PDO $pdo): ?array
    {
        $r = $pdo->query('SELECT * FROM wa_offers WHERE live_slot = 1 LIMIT 1')->fetch(\PDO::FETCH_ASSOC);
        return is_array($r) ? $r : null;
    }

    /** The offer's wordings, 1-based. @return array<int,string> */
    public static function wordingsOf(array $offer): array
    {
        $w = [1 => (string)$offer['wording_1'], 2 => (string)$offer['wording_2']];
        if ((string)($offer['wording_3'] ?? '') !== '') $w[3] = (string)$offer['wording_3'];
        return $w;
    }

    /** How many of the offer's wordings have a sent test, recorded in the ledger, on the current content, within 7 days. */
    public static function testedVariants(\PDO $pdo, array $offer, int $now): int
    {
        $st = $pdo->prepare("SELECT count(DISTINCT variant) FROM wa_offer_tests WHERE offer_id = ? AND state = 'sent' AND ledger = 'recorded'
                               AND content_hash = ? AND started_at >= ?");
        $st->execute([(int)$offer['id'], (string)$offer['content_hash'], UtcClock::stamp($now - self::TEST_VALID_S)]);
        return (int)$st->fetchColumn();
    }

    // ── a draft ────────────────────────────────────────────────────────────────────────────────────────────────

    /** @return array{ok:bool, id:int, errors:string[]} */
    public static function create(\PDO $pdo, string $title, array $wordings, string $size, int $byId, string $byName, int $now): array
    {
        $bad = OfferText::validate($title, $wordings);
        if (!array_key_exists($size, OfferAudience::SIZES)) $bad[] = 'size';
        if ($bad !== []) return ['ok' => false, 'id' => 0, 'errors' => $bad];
        $w = OfferText::wordings($wordings);
        $id = 0;
        self::tx($pdo, function () use ($pdo, $title, $w, $size, $byId, $byName, $now, &$id) {
            $at = UtcClock::stamp($now);
            $pdo->prepare('INSERT INTO wa_offers (title, wording_1, wording_2, wording_3, stop_line, content_hash, audience_size,
                                                  created_by_id, created_by, created_at, updated_at)
                           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([trim($title), $w[0], $w[1], $w[2] ?? null, OfferText::STOP_LINE,
                           OfferText::contentHash($w, OfferText::STOP_LINE, null), $size, $byId, $byName, $at, $at]);
            $id = (int)$pdo->lastInsertId();
            self::event($pdo, $id, 'created', $byId, $byName, 'wordings=' . count($w), $now);
        });
        return ['ok' => true, 'id' => $id, 'errors' => []];
    }

    /** A draft's title, wordings and size; the image is kept. @return array{ok:bool, errors:string[]} */
    public static function edit(\PDO $pdo, int $id, string $title, array $wordings, string $size, int $byId, string $byName, int $now): array
    {
        $bad = OfferText::validate($title, $wordings);
        if (!array_key_exists($size, OfferAudience::SIZES)) $bad[] = 'size';
        if ($bad !== []) return ['ok' => false, 'errors' => $bad];
        $w = OfferText::wordings($wordings);
        $ok = false;
        self::tx($pdo, function () use ($pdo, $id, $title, $w, $size, $byId, $byName, $now, &$ok) {
            $o = self::find($pdo, $id);
            if ($o === null || (string)$o['state'] !== 'draft') return;
            $st = $pdo->prepare("UPDATE wa_offers SET title = ?, wording_1 = ?, wording_2 = ?, wording_3 = ?, content_hash = ?,
                                        audience_size = ?, updated_at = ? WHERE id = ? AND state = 'draft'");
            $st->execute([trim($title), $w[0], $w[1], $w[2] ?? null,
                          OfferText::contentHash($w, (string)$o['stop_line'], $o['image_sha256'] === null ? null : (string)$o['image_sha256']),
                          $size, UtcClock::stamp($now), $id]);
            $ok = $st->rowCount() === 1;
            if ($ok) self::event($pdo, $id, 'edited', $byId, $byName, 'wordings=' . count($w), $now);
        });
        return ['ok' => $ok, 'errors' => $ok ? [] : ['not_draft']];
    }

    /** Set (OfferImage::accept()'s answer) or clear (null) a draft's image. */
    public static function setImage(\PDO $pdo, int $id, ?array $img, int $byId, string $byName, int $now): bool
    {
        $ok = false;
        self::tx($pdo, function () use ($pdo, $id, $img, $byId, $byName, $now, &$ok) {
            $o = self::find($pdo, $id);
            if ($o === null || (string)$o['state'] !== 'draft') return;
            $sha = $img === null ? null : (string)$img['sha256'];
            $st = $pdo->prepare("UPDATE wa_offers SET image_file = ?, image_mime = ?, image_bytes = ?, image_sha256 = ?, content_hash = ?,
                                        updated_at = ? WHERE id = ? AND state = 'draft'");
            $st->execute([$img === null ? null : (string)$img['file'], $img === null ? null : (string)$img['mime'],
                          $img === null ? null : (int)$img['bytes'], $sha,
                          OfferText::contentHash(array_values(self::wordingsOf($o)), (string)$o['stop_line'], $sha),
                          UtcClock::stamp($now), $id]);
            $ok = $st->rowCount() === 1;
            if ($ok) self::event($pdo, $id, 'edited', $byId, $byName, $img === null ? 'image=0' : 'image=1', $now);
        });
        return $ok;
    }

    /** A draft is closed as discarded: kept as history, never deleted. */
    public static function discard(\PDO $pdo, int $id, int $byId, string $byName, int $now): bool
    {
        $ok = false;
        self::tx($pdo, function () use ($pdo, $id, $byId, $byName, $now, &$ok) {
            $at = UtcClock::stamp($now);
            $st = $pdo->prepare("UPDATE wa_offers SET state = 'stopped', close_reason = 'discarded', closed_at = ?, closed_by = ?,
                                        updated_at = ? WHERE id = ? AND state = 'draft'");
            $st->execute([$at, $byName, $at, $id]);
            $ok = $st->rowCount() === 1;
            if ($ok) self::event($pdo, $id, 'discarded', $byId, $byName, null, $now);
        });
        return $ok;
    }

    // ── start, pause, resume, stop ─────────────────────────────────────────────────────────────────────────────

    /**
     * Start a draft whose content is still $contentHash (what the admin confirmed), in one BEGIN IMMEDIATE: the state, the
     * settings in force recorded (warm_days, recent_days), every wording tested, the audience built again inside the
     * transaction and its first `size` eligible rows inserted, rank 1…n. Nothing is written when any of it fails.
     *
     * @return array{ok:bool, code:string, queued:int}
     *         code: not_draft | settings_out_of_range | another_live | untested | nobody_eligible | audience_unreadable:<list>
     */
    public static function start(\PDO $pdo, array $config, ?string $dataDir, array $retailerRows, array $storedConfig, int $id,
                                 string $contentHash, int $byId, string $byName, int $now, ?OfferGateway $gw = null): array
    {
        $set = OfferSwitch::settings($config);
        if (in_array(null, $set, true)) return ['ok' => false, 'code' => 'settings_out_of_range', 'queued' => 0];
        $own = self::begin($pdo);
        $fail = function (string $code) use ($pdo, $own): array {
            if ($own) $pdo->exec('ROLLBACK');
            return ['ok' => false, 'code' => $code, 'queued' => 0];
        };
        try {
            $at = UtcClock::stamp($now);
            try {
                $st = $pdo->prepare("UPDATE wa_offers SET state = 'running', live_slot = 1, started_at = ?, started_by = ?, warm_days = ?,
                                            recent_days = ?, updated_at = ? WHERE id = ? AND state = 'draft' AND content_hash = ?");
                $st->execute([$at, $byName, (int)$set['offers_warm_days'], (int)$set['offers_recent_days'], $at, $id, $contentHash]);
            } catch (\PDOException $e) {
                if (stripos($e->getMessage(), 'UNIQUE') !== false) return $fail('another_live');
                throw $e;
            }
            if ($st->rowCount() !== 1) return $fail('not_draft');
            $o = self::find($pdo, $id);
            if ($o === null) return $fail('not_draft');
            if (self::testedVariants($pdo, $o, $now) !== count(self::wordingsOf($o))) return $fail('untested');
            try {
                $a = OfferAudience::build($pdo, $config, $dataDir, $retailerRows, $storedConfig, (string)$o['audience_size'], $now, $gw);
            } catch (\RuntimeException $e) {
                return $fail(strpos($e->getMessage(), 'audience_unreadable:') === 0 ? $e->getMessage() : 'audience_unreadable:build');
            }
            if ($a['eligible'] === []) return $fail('nobody_eligible');
            $ins = $pdo->prepare("INSERT INTO wa_offer_recipients (offer_id, phone, rank, conversation_id, channel, handset, last_in_at, state, created_at)
                                  VALUES (?, ?, ?, ?, ?, ?, ?, 'queued', ?)");
            foreach ($a['eligible'] as $i => $e) {
                $ins->execute([$id, $e['phone'], $i + 1, $e['conversation_id'], $e['channel'], $e['handset'], $e['last_in_at'], $at]);
            }
            $pdo->prepare('UPDATE wa_offers SET queued_n = ? WHERE id = ?')->execute([count($a['eligible']), $id]);
            self::event($pdo, $id, 'started', $byId, $byName, 'queued=' . count($a['eligible']) . ' handsets=' . count($a['by_handset']), $now);
            if ($own) $pdo->exec('COMMIT');
            return ['ok' => true, 'code' => '', 'queued' => count($a['eligible'])];
        } catch (\Throwable $e) {
            if ($own) { try { $pdo->exec('ROLLBACK'); } catch (\Throwable $e2) { /* none open */ } }
            throw $e;
        }
    }

    /** running → paused, with an event ('paused', or the system's paused_by_switch / paused_by_rollback / paused_stop_rate). */
    public static function pause(\PDO $pdo, int $id, ?int $byId, string $byName, int $now, string $action = 'paused', ?string $detail = null): bool
    {
        return self::move($pdo, $id, 'running', 'paused', $action, $byId, $byName, $now, $detail);
    }

    public static function resume(\PDO $pdo, int $id, int $byId, string $byName, int $now): bool
    {
        return self::move($pdo, $id, 'paused', 'running', 'resumed', $byId, $byName, $now, null);
    }

    /** A live offer stops for good: its queued rows are skipped('offer_stopped'); claimed rows finish their own send. */
    public static function stop(\PDO $pdo, int $id, int $byId, string $byName, int $now): bool
    {
        $ok = false;
        self::tx($pdo, function () use ($pdo, $id, $byId, $byName, $now, &$ok) {
            $at = UtcClock::stamp($now);
            $st = $pdo->prepare("UPDATE wa_offers SET state = 'stopped', live_slot = NULL, close_reason = 'stopped', closed_at = ?,
                                        closed_by = ?, updated_at = ? WHERE id = ? AND state IN ('running', 'paused')");
            $st->execute([$at, $byName, $at, $id]);
            $ok = $st->rowCount() === 1;
            if (!$ok) return;
            $q = $pdo->prepare("UPDATE wa_offer_recipients SET state = 'skipped', reason = 'offer_stopped', settled_at = ?
                                 WHERE offer_id = ? AND state = 'queued'");
            $q->execute([$at, $id]);
            self::event($pdo, $id, 'stopped', $byId, $byName, 'skipped=' . $q->rowCount(), $now);
        });
        return $ok;
    }

    /** The system closes a live offer: done (nothing left) or expired (offers_max_days), the queued rows skipped. */
    public static function close(\PDO $pdo, int $id, string $why, int $now): bool
    {
        $ok = false;
        self::tx($pdo, function () use ($pdo, $id, $why, $now, &$ok) {
            $at = UtcClock::stamp($now);
            $skipped = 0;
            if ($why === 'expired') {
                $q = $pdo->prepare("UPDATE wa_offer_recipients SET state = 'skipped', reason = 'offer_expired', settled_at = ?
                                     WHERE offer_id = ? AND state = 'queued'");
                $q->execute([$at, $id]);
                $skipped = $q->rowCount();
            }
            $st = $pdo->prepare("UPDATE wa_offers SET state = 'done', live_slot = NULL, close_reason = ?, closed_at = ?, closed_by = 'system',
                                        updated_at = ? WHERE id = ? AND state IN ('running', 'paused')");
            $st->execute([$why, $at, $at, $id]);
            $ok = $st->rowCount() === 1;
            if ($ok) self::event($pdo, $id, $why === 'expired' ? 'expired' : 'done', null, 'system', 'skipped=' . $skipped, $now);
        });
        return $ok;
    }

    private static function move(\PDO $pdo, int $id, string $from, string $to, string $action, ?int $byId, string $byName, int $now, ?string $detail): bool
    {
        $ok = false;
        self::tx($pdo, function () use ($pdo, $id, $from, $to, $action, $byId, $byName, $now, $detail, &$ok) {
            $st = $pdo->prepare('UPDATE wa_offers SET state = ?, updated_at = ? WHERE id = ? AND state = ?');
            $st->execute([$to, UtcClock::stamp($now), $id, $from]);
            $ok = $st->rowCount() === 1;
            if ($ok) self::event($pdo, $id, $action, $byId, $byName, $detail, $now);
        });
        return $ok;
    }

    // ── the trail ──────────────────────────────────────────────────────────────────────────────────────────────

    /** One event: counts and fixed codes only in $detail, never a wording, a name of a customer or a number. */
    public static function event(\PDO $pdo, int $offerId, string $action, ?int $byId, string $byName, ?string $detail, int $now): void
    {
        $d = $detail === null ? null : substr((string)preg_replace('/[^A-Za-z0-9_=:., -]/', '', $detail), 0, 200);
        $pdo->prepare('INSERT INTO wa_offer_events (offer_id, action, by_id, by_name, detail, at) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$offerId, $action, $byId, $byId === null ? 'system' : $byName, $d, UtcClock::stamp($now)]);
    }

    /** The moment of the offer's latest Start or Resume (O-D19), or 0. */
    public static function lastStartOrResume(\PDO $pdo, int $offerId): int
    {
        $st = $pdo->prepare("SELECT MAX(at) FROM wa_offer_events WHERE offer_id = ? AND action IN ('started', 'resumed')");
        $st->execute([$offerId]);
        return UtcClock::parse((string)$st->fetchColumn());
    }

    // ── counts ─────────────────────────────────────────────────────────────────────────────────────────────────

    /** §H.4.12: queued, sent, maybe_sent, failed, skipped, replied_72h, stop_72h, echo_late. @return array<string,int> */
    public static function counts(\PDO $pdo, int $offerId): array
    {
        $st = $pdo->prepare("SELECT sum(state IN ('queued', 'claimed'))                                       AS queued,
                                    sum(state = 'sent')                                                       AS sent,
                                    sum(state = 'uncertain')                                                  AS maybe_sent,
                                    sum(state = 'failed')                                                     AS failed,
                                    sum(state = 'skipped')                                                    AS skipped,
                                    sum(state IN ('sent', 'uncertain') AND replied_at IS NOT NULL AND stop_at IS NULL) AS replied_72h,
                                    sum(state IN ('sent', 'uncertain') AND stop_at IS NOT NULL)               AS stop_72h,
                                    sum(echo = 'late')                                                        AS echo_late
                               FROM wa_offer_recipients WHERE offer_id = ?");
        $st->execute([$offerId]);
        $r = $st->fetch(\PDO::FETCH_ASSOC) ?: [];
        $out = [];
        foreach (['queued', 'sent', 'maybe_sent', 'failed', 'skipped', 'replied_72h', 'stop_72h', 'echo_late'] as $k) $out[$k] = (int)($r[$k] ?? 0);
        return $out;
    }

    /** Skipped rows by their fixed reason. @return array<string,int> */
    public static function skippedByReason(\PDO $pdo, int $offerId): array
    {
        $st = $pdo->prepare("SELECT reason, COUNT(*) FROM wa_offer_recipients WHERE offer_id = ? AND state = 'skipped' GROUP BY reason ORDER BY reason");
        $st->execute([$offerId]);
        $out = [];
        foreach ($st->fetchAll(\PDO::FETCH_NUM) as $r) $out[(string)$r[0]] = (int)$r[1];
        return $out;
    }

    /** Today's offers (sent, may have been sent, or in flight) per handset, by the Kampala day of $now. @return array<string,int> */
    public static function todayPerHandset(\PDO $pdo, int $dayStart): array
    {
        $st = $pdo->prepare("SELECT handset, COUNT(*) FROM wa_offer_recipients WHERE state IN ('sent', 'uncertain', 'claimed')
                               AND COALESCE(sent_at, claimed_at) >= ? GROUP BY handset ORDER BY handset");
        $st->execute([UtcClock::stamp($dayStart)]);
        $out = [];
        foreach ($st->fetchAll(\PDO::FETCH_NUM) as $r) $out[(string)$r[0]] = (int)$r[1];
        return $out;
    }

    // ── the reply and STOP marks (§H.4.12) ─────────────────────────────────────────────────────────────────────

    /**
     * Up to $limit rows sent or may-have-been-sent in the last 73 h with a mark still empty: replied_at is the first inbound
     * from the phone, on any conversation, in (sent_at, sent_at + 72 h]; stop_at the first opt-out created for the phone in
     * that window (any channel, scope or source, in the one form) or the first inbound there that stopWordIn() matches.
     * Each mark is written once (wor_marks_once). Returns the number of marks written.
     */
    public static function marksPass(\PDO $pdo, \TenantProfile $t, int $now, int $limit = 500): int
    {
        $st = $pdo->prepare("SELECT id, phone, sent_at, replied_at, stop_at FROM wa_offer_recipients
                              WHERE state IN ('sent', 'uncertain') AND phone IS NOT NULL AND sent_at >= ?
                                AND (replied_at IS NULL OR stop_at IS NULL) ORDER BY id LIMIT " . (int)$limit);
        $st->execute([UtcClock::stamp($now - self::MARK_WINDOW_S - 3600)]);
        $rows = $st->fetchAll(\PDO::FETCH_ASSOC);
        if ($rows === []) return 0;
        $optouts = [];
        foreach ($pdo->query('SELECT phone, created_at FROM contact_optouts')->fetchAll(\PDO::FETCH_ASSOC) as $o) {
            $d = OfferAudience::digits((string)$o['phone'], $t);
            if ($d !== null) $optouts[$d][] = (string)$o['created_at'];
        }
        $in = $pdo->prepare("SELECT m.body, m.sent_at FROM wa_messages m JOIN wa_conversations c ON c.id = m.conversation_id
                              WHERE c.phone = ? AND m.direction = 'in' AND m.sent_at > ? AND m.sent_at <= ? ORDER BY m.sent_at, m.id");
        $upd = $pdo->prepare('UPDATE wa_offer_recipients SET replied_at = COALESCE(replied_at, ?), stop_at = COALESCE(stop_at, ?) WHERE id = ?');
        $n = 0;
        foreach ($rows as $r) {
            $from = (string)$r['sent_at'];
            $to = UtcClock::stamp(UtcClock::parse($from) + self::MARK_WINDOW_S);
            $in->execute([(string)$r['phone'], $from, $to]);
            $reply = null; $stop = null;
            foreach ($in->fetchAll(\PDO::FETCH_ASSOC) as $m) {
                if ($reply === null) $reply = (string)$m['sent_at'];
                if ($stop === null && OfferText::stopWordIn((string)($m['body'] ?? '')) !== '') $stop = (string)$m['sent_at'];
            }
            foreach ($optouts[(string)$r['phone']] ?? [] as $c) {
                if ($c > $from && $c <= $to && ($stop === null || $c < $stop)) $stop = $c;
            }
            $reply = $r['replied_at'] !== null ? null : $reply;
            $stop = $r['stop_at'] !== null ? null : $stop;
            if ($reply === null && $stop === null) continue;
            $upd->execute([$reply, $stop, (int)$r['id']]);
            $n += ($reply !== null ? 1 : 0) + ($stop !== null ? 1 : 0);
        }
        return $n;
    }

    /** Run $fn in one BEGIN IMMEDIATE, or inside the caller's transaction when one is open. */
    private static function tx(\PDO $pdo, callable $fn): void
    {
        $own = self::begin($pdo);
        try {
            $fn();
            if ($own) $pdo->exec('COMMIT');
        } catch (\Throwable $e) {
            if ($own) { try { $pdo->exec('ROLLBACK'); } catch (\Throwable $e2) { /* none open */ } }
            throw $e;
        }
    }

    /**
     * BEGIN IMMEDIATE, true; or false when the caller already holds a transaction on this connection (SQLite refuses a
     * nested BEGIN; PDO::inTransaction() does not see a BEGIN made through exec()).
     */
    public static function begin(\PDO $pdo): bool
    {
        try {
            if ($pdo->exec('BEGIN IMMEDIATE') !== false) return true;
            $msg = (string)($pdo->errorInfo()[2] ?? '');
        } catch (\PDOException $e) {
            $msg = $e->getMessage();
        }
        if (stripos($msg, 'within a transaction') !== false) return false;
        throw new \RuntimeException('offers: the book could not be locked');
    }
}
