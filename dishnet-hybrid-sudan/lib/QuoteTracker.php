<?php
declare(strict_types=1);

require_once __DIR__ . '/StaffJobsGate.php';
require_once __DIR__ . '/UtcClock.php';
require_once __DIR__ . '/QuoteSendEvidence.php';
require_once __DIR__ . '/HumanTakeover.php';

/**
 * QuoteTracker — quote tracking, P2a (5.18.104, Uganda, root docs/73 §H.2.9; contract C7, C9; rulings R3, R18).
 *
 * Tracking records; it sends nothing. One run, from cron/quote_chase.php (master's `quote_chase` line, behind
 * `quote_tracking_enabled`), at ONE moment taken when it begins:
 *
 *   1. derive    up to 500 wa_messages ids past the cursor (`quote_followup_state` tracker_cursor). Each confirming row
 *                (QuoteSendEvidence) gives one quote_tracker row per quote_key, the earliest winning (INSERT OR IGNORE);
 *                a clash on idx_qt_quote_id is recorded once as ref_conflict:<quote_key>. The rows, those records and
 *                the cursor are written in ONE transaction, never the cursor before them (§H.2.9.2, Q17-F04). The
 *                statuses set at creation (colleague, paid) and backfilled (sent before quote_tracking_since, R-17)
 *                are written with the row (§H.2.9.5).
 *   2. owners    rules 1–7 (F4, §H.2.9.4). With none yielding a row under 1 h old is `pending` and retried; at 1 h it is
 *                final (`none`). A run whose owner pass reached every pending row ends by writing `tracker_ran`, its
 *                moment (ruling R18; Q16-F04); a run its budget cut short writes none.
 *   3. stops     §H.2.9.7, in order, on every open row; the first that holds sets the status once (qt_status_once) and
 *                closes the linked follow-up with the same reason (STATUS_TO_CLOSE).
 *   4. mapping   a linked follow-up closed by another job maps back (§H.2.10.8, CLOSE_TO_STATUS); a close that keeps the
 *                quotation open sets its chase_hold to the code.
 *
 * What the chase (stage B2b: lib/QuoteChase.php, cron/followup_send.php) reads from here: stop() and customerStop() —
 * the same reads preSendCheck and the after-claim re-read make (§H.2.10.6 items 2 and 4); setStatus(); convIds();
 * number() — which number a chase leaves from (§H.2.10.2); openingHold() — the holds an opening sets first (ambiguous,
 * colleague; §H.2.10.3); ucrmStatus() — the uCRM status stop's mapping; the two close-reason tables.
 *
 * Inert unless on(): Uganda (StaffJobsGate) and quote_tracking_enabled. Every Kampala date and hour is computed in a
 * literal Africa/Kampala zone. A table this reads that is absent reads as empty; any other read failure throws, so a
 * run that cannot read a stop stops, and never reads "no stop". PHP 7.4.
 */
final class QuoteTracker
{
    /** The literal zone every Kampala date and hour here is computed in (R-16). */
    public const TZ = 'Africa/Kampala';

    /** 089's CHECK on quote_tracker.status. */
    const STATUSES = ['open', 'answered', 'opted_out', 'taken', 'paid', 'registered', 'accepted', 'declined',
                      'superseded', 'expired', 'colleague'];

    /** wa_messages ids a run reads past the cursor (§H.2.9.2). */
    const BATCH = 500;
    /** An owner not found is retried while the row is under this old; then it is final (§H.2.9.4). */
    const PENDING_S = 3600;
    /** A quotation expires after 20:00 Kampala on valid_until (§H.2.9.7). */
    const EXPIRY_HOUR = 20;
    /** The first run reads back this far (§H.2.9.5: "the first run's 30 days"). */
    const FIRST_RUN_S = 30 * 86400;

    /** §H.2.9.7's stops the tracker reads every run, in order. The uCRM status (accepted/declined) is read by the
     *  chase before a step is drafted (one GET), through ucrmStatus(). */
    const STOPS = ['opted_out', 'answered', 'taken', 'paid', 'registered', 'accepted', 'superseded', 'expired'];
    /** The three a customer or a colleague can cause in the seconds before a send (§H.2.10.6 item 4, ruling R18). */
    const CUSTOMER_STOPS = ['opted_out', 'answered', 'taken'];

    /** A stop's status → the reason the linked follow-up is closed with ("closes the linked follow-up with the same reason"). */
    const STATUS_TO_CLOSE = [
        'opted_out' => 'opted_out', 'answered' => 'replied', 'taken' => 'human_closed', 'paid' => 'converted',
        'registered' => 'converted', 'accepted' => 'converted', 'declined' => 'not_interested',
        'superseded' => 'superseded', 'expired' => 'expired', 'colleague' => 'staff',
    ];
    /** A follow-up's close reason → the tracker's status (§H.2.10.8). */
    const CLOSE_TO_STATUS = [
        'replied' => 'answered', 'opted_out' => 'opted_out', 'human_closed' => 'taken', 'staff_cancelled' => 'taken',
        'escalated' => 'taken', 'converted' => 'accepted', 'not_interested' => 'declined', 'staff' => 'colleague',
    ];
    /** A close that leaves the quotation open with no hold: the chase ran out of steps; a stop or expiry ends it. */
    const CLOSE_STAYS_OPEN = ['exhausted'];
    /** Every other close reason (send_failed, cancelled, a channel refusal's code, rollback …) leaves the quotation open
     *  with chase_hold set to the code. These two are the tracker's own closes, written after its status. */
    const CLOSE_OWN = ['superseded', 'expired'];

    /** The ops events that say a payment arrived (lib/NotificationService.php:799,749). */
    const PAYMENT_EVENTS = ['ops_payment_received', 'ops_invoice_auto_paid'];

    // ── the switch ─────────────────────────────────────────────────────────────────────────────────────────────

    /** Uganda (StaffJobsGate, with the data directory) and quote_tracking_enabled (1, true, on, yes). */
    public static function on(array $config, ?string $dataDir): bool
    {
        return self::truthy($config['quote_tracking_enabled'] ?? null) && StaffJobsGate::applies($config, $dataDir);
    }

    // ── one run ────────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * One tracker run at $moment (unix time, the run's one moment), working while time() < $until. $store is the
     * plugin store (ColleagueNumbers, AutomationPolicy read the accounts through it); null leaves those checks out.
     *
     * @return array<string,int> counts and fixed flags only — never an id: read, confirming, inserted, kept_earliest,
     *                           ref_conflict, no_phone, advanced (1 when the cursor moved; the cursor itself is a
     *                           wa_messages.id and is never returned), owners_found, owners_final, owners_pending, ran
     *                           (1 when tracker_ran was written), stopped, mapped, held, cut (1 when the budget ended
     *                           the run early)
     */
    public static function run(\PDO $pdo, array $config, ?string $dataDir, $store, int $moment, int $until): array
    {
        $ctx = self::context($pdo, $config, $dataDir, $store);
        $c = ['read' => 0, 'confirming' => 0, 'inserted' => 0, 'kept_earliest' => 0, 'ref_conflict' => 0, 'no_phone' => 0,
              'advanced' => 0, 'owners_found' => 0, 'owners_final' => 0, 'owners_pending' => 0, 'ran' => 0, 'stopped' => 0,
              'mapped' => 0, 'held' => 0, 'cut' => 0];

        foreach (self::derive($pdo, $ctx, $moment) as $k => $v) $c[$k] = $v;

        $reached = true;
        foreach (self::pendingRows($pdo) as $row) {
            if (time() >= $until) { $reached = false; break; }
            $r = self::resolveOwner($pdo, $ctx, $row, $moment);
            $c[$r]++;
        }
        if ($reached) {
            $pdo->prepare("INSERT OR REPLACE INTO quote_followup_state (k, v, updated_at) VALUES ('tracker_ran', ?, datetime('now'))")
                ->execute([UtcClock::stamp($moment)]);
            $c['ran'] = 1;
        } else {
            $c['cut'] = 1;
        }

        foreach (self::openRows($pdo) as $row) {
            if (time() >= $until) { $c['cut'] = 1; break; }
            $s = self::stopWith($pdo, $ctx, $row, $moment, null);
            if ($s !== null && self::setStatus($pdo, (string)$row['quote_key'], $s[0], $s[1], $moment, 'quote_tracker')) $c['stopped']++;
        }

        if (time() < $until) {
            foreach (self::mapClosed($pdo, $moment) as $k => $v) $c[$k] += $v;
        } else {
            $c['cut'] = 1;
        }
        return $c;
    }

    // ── 1. derive (§H.2.9.1–§H.2.9.3, §H.2.9.5) ──────────────────────────────────────────────────────────────────

    /**
     * Read up to BATCH ids past the cursor; insert the tracker rows, the conflicts and the cursor in one transaction.
     * A row's created_at is its insert's own storage time (089's DEFAULT), never the run's moment: the offers hold's
     * past-moment form bounds a tracker row by it (ruling R18), so it must not read earlier than the write. A status set
     * at creation is stamped with the run's moment, which is at or before the write: the safe side of that bound.
     */
    private static function derive(\PDO $pdo, array $ctx, int $moment): array
    {
        $out = ['read' => 0, 'confirming' => 0, 'inserted' => 0, 'kept_earliest' => 0, 'ref_conflict' => 0, 'no_phone' => 0, 'advanced' => 0];
        $cursor = self::cursor($pdo, $moment);
        $st = $pdo->prepare('SELECT id, conversation_id, direction, event_key, body, sent_at, created_at FROM wa_messages
                              WHERE id > ? ORDER BY id LIMIT ' . self::BATCH);
        $st->execute([$cursor]);
        $msgs = $st->fetchAll(\PDO::FETCH_ASSOC);
        if ($msgs === []) return $out;
        $out['read'] = count($msgs);
        $last = (int)$msgs[count($msgs) - 1]['id'];

        $plans = [];
        foreach ($msgs as $m) {
            $ev = QuoteSendEvidence::read($m);
            if ($ev === null) continue;
            $out['confirming']++;
            $plan = self::plan($pdo, $ctx, $m, $ev, $moment);
            if ($plan === null) { $out['no_phone']++; continue; }
            $plans[] = $plan;
        }

        $ins = $pdo->prepare('INSERT OR IGNORE INTO quote_tracker (quote_key, quote_ref, quote_id, crm_client_id, phone,
                                evidence_msg_id, evidence_event, made_by, sent_at, sent_conv_id, sent_channel, valid_until,
                                owner_staff_id, owner_source, spoke_conv_id, spoke_channel, backfilled, status, status_at,
                                status_reason)
                              VALUES (:quote_key, :quote_ref, :quote_id, :crm_client_id, :phone, :evidence_msg_id,
                                :evidence_event, :made_by, :sent_at, :sent_conv_id, :sent_channel, :valid_until,
                                :owner_staff_id, :owner_source, :spoke_conv_id, :spoke_channel, :backfilled, :status,
                                :status_at, :status_reason)');
        $has = $pdo->prepare('SELECT 1 FROM quote_tracker WHERE quote_key = ?');
        $conflict = $pdo->prepare("INSERT OR IGNORE INTO quote_followup_state (k, v) VALUES (?, ?)");
        $pdo->exec('BEGIN IMMEDIATE');
        try {
            foreach ($plans as $p) {
                $ins->execute($p);
                if ($ins->rowCount() === 1) { $out['inserted']++; continue; }
                $has->execute([$p[':quote_key']]);
                if ($has->fetchColumn() !== false) { $out['kept_earliest']++; continue; }
                // ignored, and no row of that key: the uCRM id is another quotation's (idx_qt_quote_id; Q17-F04)
                $conflict->execute(['ref_conflict:' . $p[':quote_key'], (string)$p[':quote_id']]);
                $out['ref_conflict']++;
            }
            $pdo->prepare("INSERT OR REPLACE INTO quote_followup_state (k, v, updated_at) VALUES ('tracker_cursor', ?, datetime('now'))")
                ->execute([(string)$last]);
            $pdo->exec('COMMIT');
        } catch (\Throwable $e) {
            try { $pdo->exec('ROLLBACK'); } catch (\Throwable $e2) { /* nothing was committed */ }
            throw $e;
        }
        $out['advanced'] = 1;   // a flag: the cursor (an id) stays in quote_followup_state, never in a count or a log
        return $out;
    }

    /** The cursor; on the very first run, just before the earliest outbound row of the last 30 days. */
    private static function cursor(\PDO $pdo, int $moment): int
    {
        $st = $pdo->query("SELECT v FROM quote_followup_state WHERE k = 'tracker_cursor'");
        $v = $st->fetchColumn();
        if ($v !== false) return max(0, (int)$v);
        $first = $pdo->prepare("SELECT MIN(id) FROM wa_messages WHERE direction = 'out' AND sent_at >= ?");
        $first->execute([UtcClock::stamp($moment - self::FIRST_RUN_S)]);
        $min = $first->fetchColumn();
        if ($min !== false && $min !== null) return max(0, (int)$min - 1);
        return (int)$pdo->query('SELECT COALESCE(MAX(id), 0) FROM wa_messages')->fetchColumn();
    }

    /** The values of one tracker row, from reads only; null when the conversation is gone or has no number. */
    private static function plan(\PDO $pdo, array $ctx, array $m, array $ev, int $moment): ?array
    {
        [$marker, $number] = $ev;
        $conv = self::conversation($pdo, (int)$m['conversation_id']);
        if ($conv === null) return null;
        $phone = self::phoneOf((string)$conv['phone'], $ctx);
        if ($phone === '') return null;
        $sentAt = self::norm((string)$m['sent_at']);
        if ($sentAt === null) return null;
        $key = QuoteSendEvidence::key($number);

        $quoteId = self::quoteIdOf($pdo, $ctx, $number);
        $kycApp  = self::kycByQuote($pdo, $ctx, $quoteId, $number);
        $log     = self::logOf($pdo, $ctx, $quoteId, $number);
        $client  = self::clientOf($kycApp, $log, $conv);
        $spoke   = self::spokeOf($pdo, $ctx, $phone, $sentAt);
        $own     = self::ownerOf($pdo, $ctx, $quoteId, $number, $kycApp, $log, $client, $spoke, $sentAt);
        if ($own['id'] === null) $own['source'] = ($moment - UtcClock::parse($sentAt) < self::PENDING_S) ? 'pending' : 'none';

        $status = 'open'; $reason = null;
        if (self::isColleague($ctx, $phone, QuoteSendEvidence::sentChannel((string)$conv['channel'], $ctx['force_accounts']))) {
            $status = 'colleague'; $reason = 'colleague_number';
        } elseif ($quoteId !== null && self::cashPaid($pdo, $ctx, $quoteId)) {
            $status = 'paid'; $reason = 'cash_kyc';
        }
        $since = $ctx['since'];
        $backfilled = ($since === null || UtcClock::parse($sentAt) < $since) ? 1 : 0;

        return [
            ':quote_key' => $key, ':quote_ref' => $number, ':quote_id' => $quoteId, ':crm_client_id' => $client,
            ':phone' => $phone, ':evidence_msg_id' => (int)$m['id'], ':evidence_event' => (string)$m['event_key'],
            ':made_by' => QuoteSendEvidence::madeBy((string)$m['event_key'], $kycApp, $log),
            ':sent_at' => $sentAt, ':sent_conv_id' => (int)$conv['id'],
            ':sent_channel' => QuoteSendEvidence::sentChannel((string)$conv['channel'], $ctx['force_accounts']),
            ':valid_until' => QuoteSendEvidence::validUntil($marker, (string)$m['body'], $sentAt, $quoteId !== null),
            ':owner_staff_id' => $own['id'], ':owner_source' => $own['source'],
            ':spoke_conv_id' => $spoke !== null ? (int)$spoke['id'] : null,
            ':spoke_channel' => $spoke !== null ? (string)$spoke['channel'] : null,
            ':backfilled' => $backfilled, ':status' => $status,
            ':status_at' => $status === 'open' ? null : UtcClock::stamp($moment), ':status_reason' => $reason,
        ];
    }

    // ── 2. owners (F4, §H.2.9.4) ────────────────────────────────────────────────────────────────────────────────

    private static function pendingRows(\PDO $pdo): array
    {
        return $pdo->query("SELECT * FROM quote_tracker WHERE owner_source = 'pending' ORDER BY sent_at, quote_key")->fetchAll(\PDO::FETCH_ASSOC);
    }

    /** One pending row, judged at the run's moment: found, final (none) or still pending. */
    private static function resolveOwner(\PDO $pdo, array $ctx, array $row, int $moment): string
    {
        $quoteId = $row['quote_id'] === null ? null : (int)$row['quote_id'];
        $number  = (string)$row['quote_ref'];
        $kycApp  = self::kycByQuote($pdo, $ctx, $quoteId, $number);
        $log     = self::logOf($pdo, $ctx, $quoteId, $number);
        $spoke   = (int)($row['spoke_conv_id'] ?? 0) > 0 ? self::conversation($pdo, (int)$row['spoke_conv_id']) : null;
        $client  = $row['crm_client_id'] === null ? null : (int)$row['crm_client_id'];
        $own = self::ownerOf($pdo, $ctx, $quoteId, $number, $kycApp, $log, $client, $spoke, (string)$row['sent_at']);
        if ($own['id'] !== null) {
            $pdo->prepare("UPDATE quote_tracker SET owner_staff_id = ?, owner_source = ? WHERE quote_key = ? AND owner_source = 'pending'")
                ->execute([$own['id'], $own['source'], (string)$row['quote_key']]);
            return 'owners_found';
        }
        if ($moment - UtcClock::parse((string)$row['sent_at']) >= self::PENDING_S) {
            $pdo->prepare("UPDATE quote_tracker SET owner_source = 'none' WHERE quote_key = ? AND owner_source = 'pending'")
                ->execute([(string)$row['quote_key']]);
            return 'owners_final';
        }
        return 'owners_pending';
    }

    /** Rules 1–7 over what the book holds for this quotation (QuoteSendEvidence::owner). */
    private static function ownerOf(\PDO $pdo, array $ctx, ?int $quoteId, string $number, ?array $kycApp, ?array $log,
                                    ?int $client, ?array $spoke, string $sentAt): array
    {
        $leadLog = $log !== null && (string)($log['type'] ?? '') === 'lead' ? $log : self::leadLogOf($pdo, $ctx, $quoteId, $number);
        $facts = [
            'kyc_app'    => $kycApp,
            'lead_log'   => $leadLog !== null,
            'lead'       => $leadLog !== null ? self::leadOf($pdo, $ctx, $quoteId, $number) : null,
            'log'        => $log,
            'spoke_chan' => $spoke !== null ? self::channelRow($pdo, $ctx, (string)$spoke['channel']) : null,
            'conv_lead'  => $spoke !== null && $ctx['conv_lead_id'] && (int)($spoke['lead_id'] ?? 0) > 0
                            ? self::blobOne($pdo, $ctx, 'leads', 'id', (string)(int)$spoke['lead_id']) : null,
            'client_app' => $client !== null ? self::kycOfClientBefore($pdo, $ctx, $client, $sentAt) : null,
        ];
        return QuoteSendEvidence::owner($facts, $ctx['retailers']);
    }

    // ── 3. stops (§H.2.9.7) ─────────────────────────────────────────────────────────────────────────────────────

    private static function openRows(\PDO $pdo): array
    {
        return $pdo->query("SELECT * FROM quote_tracker WHERE status = 'open' ORDER BY sent_at, quote_key")->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * The first of §H.2.9.7's stops that holds for this tracker row at $now: [status, a fixed reason code], or null.
     * $only limits it to some stops, in the same order (customerStop() passes CUSTOMER_STOPS). Reads only.
     *
     * @return array{0:string,1:string}|null
     */
    public static function stop(\PDO $pdo, array $config, ?string $dataDir, array $row, int $now, ?array $only = null): ?array
    {
        return self::stopWith($pdo, self::tenantContext($config, $dataDir), $row, $now, $only);
    }

    private static function stopWith(\PDO $pdo, array $ctx, array $row, int $now, ?array $only): ?array
    {
        $p = (string)$row['phone'];
        if ($p === '' || $p === 'erased') return null;
        $sent = (string)$row['sent_at'];
        $convs = self::convIds($pdo, $p, (int)($row['sent_conv_id'] ?? 0));
        foreach (self::STOPS as $stop) {
            if ($only !== null && !in_array($stop, $only, true)) continue;
            $r = null;
            switch ($stop) {
                case 'opted_out':  $r = self::optedOut($pdo, $p, $convs, $sent); break;
                case 'answered':   $r = self::answered($pdo, $p, $convs, $sent); break;
                case 'taken':      $r = HumanTakeover::since($pdo, $p, $convs, $sent); break;
                case 'paid':       $r = self::paid($pdo, $ctx, $row, $convs); break;
                case 'registered': $r = self::registered($pdo, $ctx, $row); break;
                case 'accepted':   $r = self::approved($pdo, $convs, $row); break;
                case 'superseded': $r = self::superseded($pdo, $row); break;
                case 'expired':    $r = self::expired($row, $now); break;
            }
            if ($r !== null) return [$stop, $r];
        }
        return null;
    }

    /** The stops a customer or a colleague can cause (opted_out, answered with marks, taken): preSendCheck's and the
     *  after-claim re-read's (§H.2.10.6 items 2 and 4; ruling R18). */
    public static function customerStop(\PDO $pdo, array $config, ?string $dataDir, array $row, int $now): ?array
    {
        return self::stop($pdo, $config, $dataDir, $row, $now, self::CUSTOMER_STOPS);
    }

    /** A live opt-out for p (any channel, any scope), or an inbound since the send that ContactOptOut::detect reads as STOP. */
    private static function optedOut(\PDO $pdo, string $p, array $convs, string $sent): ?string
    {
        if (self::hasTable($pdo, 'contact_optouts')) {
            $phones = self::convPhones($pdo, $convs, $p);
            $in = implode(',', array_fill(0, count($phones), '?'));
            $st = $pdo->prepare("SELECT 1 FROM contact_optouts WHERE active = 1 AND phone IN ({$in}) LIMIT 1");
            $st->execute($phones);
            if ($st->fetchColumn() !== false) return 'optout_row';
        }
        if ($convs === []) return null;
        if (!class_exists('ContactOptOut')) require_once __DIR__ . '/ContactOptOut.php';
        $st = $pdo->prepare('SELECT body FROM wa_messages WHERE conversation_id IN (' . implode(',', $convs) . ")
                              AND direction = 'in' AND (sent_at > ? OR created_at > ?) ORDER BY id");
        $st->execute([$sent, $sent]);
        foreach ($st->fetchAll(\PDO::FETCH_COLUMN) as $body) {
            if (\ContactOptOut::detect((string)$body)['stop']) return 'stop_message';
        }
        return null;
    }

    /** An inbound row from p after the send, on any of p's conversations ([AUDIO] included); or an inbound mark (R-01). */
    private static function answered(\PDO $pdo, string $p, array $convs, string $sent): ?string
    {
        if ($convs !== []) {
            $st = $pdo->prepare('SELECT 1 FROM wa_messages WHERE conversation_id IN (' . implode(',', $convs) . ")
                                  AND direction = 'in' AND (sent_at > ? OR created_at > ?) LIMIT 1");
            $st->execute([$sent, $sent]);
            if ($st->fetchColumn() !== false) return 'reply';
        }
        if (self::hasTable($pdo, 'wa_seen_marks')) {
            $st = $pdo->prepare("SELECT 1 FROM wa_seen_marks WHERE direction = 'in' AND phone = ? AND seen_at > ? LIMIT 1");
            $st->execute([$p, $sent]);
            if ($st->fetchColumn() !== false) return 'unread_inbound';
        }
        return null;
    }

    /** A payment after the send: a receipt to p, a cashbook receipt for the client, a collection, a Cash KYC. */
    private static function paid(\PDO $pdo, array $ctx, array $row, array $convs): ?string
    {
        $sent = (string)$row['sent_at'];
        $local = self::kampalaStamp($sent);
        if ($convs !== []) {
            $ev = implode(',', array_fill(0, count(self::PAYMENT_EVENTS), '?'));
            $st = $pdo->prepare('SELECT 1 FROM wa_messages WHERE conversation_id IN (' . implode(',', $convs) . ")
                                  AND direction = 'out' AND event_key IN ({$ev}) AND sent_at > ? LIMIT 1");
            $st->execute(array_merge(self::PAYMENT_EVENTS, [$sent]));
            if ($st->fetchColumn() !== false) return 'payment_message';
        }
        $client = $row['crm_client_id'] === null ? 0 : (int)$row['crm_client_id'];
        if ($client <= 0) return null;
        if (self::hasColumns($pdo, 'cb_ledger', ['direction', 'crm_payment_id', 'crm_client_id', 'created_at'])) {
            $st = $pdo->prepare("SELECT 1 FROM cb_ledger WHERE direction = 'in' AND crm_payment_id > 0 AND crm_client_id = ?
                                   AND created_at > ? LIMIT 1");
            $st->execute([$client, $local]);
            if ($st->fetchColumn() !== false) return 'cashbook';
        }
        if (self::hasColumns($pdo, 'payment_collections', ['data'])) {
            $st = $pdo->prepare("SELECT 1 FROM payment_collections
                                  WHERE CAST(json_extract(data, '$.crm_customer_id') AS TEXT) = ?
                                    AND COALESCE(json_extract(data, '$.created_at'), '') > ? LIMIT 1");
            $st->execute([(string)$client, $local]);
            if ($st->fetchColumn() !== false) return 'collection';
        }
        foreach (self::kycOfClient($pdo, $ctx, $client) as $a) {
            if (self::isCashPaid($a) && (string)($a['submitted_at'] ?? '') > $local) return 'cash_kyc';
        }
        return null;
    }

    /** Non-KYC quotations: a KYC after the send with the client, or with p as its canonical mobile or wa_quote_phone. */
    private static function registered(\PDO $pdo, array $ctx, array $row): ?string
    {
        if ((string)$row['made_by'] === 'kyc') return null;
        $local = self::kampalaStamp((string)$row['sent_at']);
        $client = $row['crm_client_id'] === null ? 0 : (int)$row['crm_client_id'];
        if ($client > 0) {
            foreach (self::kycOfClient($pdo, $ctx, $client) as $a) {
                if ((string)($a['submitted_at'] ?? '') > $local) return 'kyc_client';
            }
        }
        if (!self::hasColumns($pdo, 'kyc_applications', ['data'])) return null;
        $st = $pdo->prepare("SELECT id, data FROM kyc_applications WHERE COALESCE(json_extract(data, '$.submitted_at'), '') > ?");
        $st->execute([$local]);
        $p = (string)$row['phone'];
        foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $a = json_decode((string)$r['data'], true);
            if (!is_array($a)) continue;
            foreach (['mobile', 'wa_quote_phone'] as $f) {
                if (self::phoneOf((string)($a[$f] ?? ''), $ctx) === $p) return 'kyc_phone';
            }
        }
        return null;
    }

    /** ops_quote_approved to p after the send whose body holds #<ref> (webhook.php:2527-2563). */
    private static function approved(\PDO $pdo, array $convs, array $row): ?string
    {
        if ($convs === []) return null;
        $st = $pdo->prepare('SELECT body FROM wa_messages WHERE conversation_id IN (' . implode(',', $convs) . ")
                              AND direction = 'out' AND event_key = 'ops_quote_approved' AND sent_at > ?");
        $st->execute([(string)$row['sent_at']]);
        $re = '/#' . preg_quote((string)$row['quote_ref'], '/') . '(?![A-Za-z0-9])/u';
        foreach ($st->fetchAll(\PDO::FETCH_COLUMN) as $body) {
            if (preg_match($re, (string)$body)) return 'quote_approved';
        }
        return null;
    }

    /** A newer quotation to p: another tracked quotation sent later. */
    private static function superseded(\PDO $pdo, array $row): ?string
    {
        $st = $pdo->prepare('SELECT 1 FROM quote_tracker WHERE phone = ? AND quote_key <> ? AND sent_at > ? LIMIT 1');
        $st->execute([(string)$row['phone'], (string)$row['quote_key'], (string)$row['sent_at']]);
        return $st->fetchColumn() !== false ? 'newer_quote' : null;
    }

    /** After 20:00 Kampala on valid_until. */
    private static function expired(array $row, int $now): ?string
    {
        $vu = trim((string)($row['valid_until'] ?? ''));
        if ($vu === '') return null;
        try {
            $end = new \DateTime($vu . ' ' . sprintf('%02d', self::EXPIRY_HOUR) . ':00:00', new \DateTimeZone(self::TZ));
        } catch (\Throwable $e) {
            return null;
        }
        return $now > $end->getTimestamp() ? 'valid_until' : null;
    }

    /**
     * The uCRM quote status the chase reads with one GET before a step is drafted: 2 → accepted, 3 or 4 → declined
     * (cron_quote_wa.php:277, unverified on 4.5.33); any other value, or a failed read (null), does not stop.
     */
    public static function ucrmStatus($status): ?string
    {
        if ($status === null || !is_numeric($status)) return null;
        $s = (int)$status;
        if ($s === 2) return 'accepted';
        if ($s === 3 || $s === 4) return 'declined';
        return null;
    }

    /**
     * A status leaves `open` once (qt_status_once): true when this call moved it. The linked follow-up, if open, is
     * closed with the same reason (STATUS_TO_CLOSE), by FollowUpService::close().
     */
    public static function setStatus(\PDO $pdo, string $quoteKey, string $status, ?string $reason, int $now, string $actor = 'quote_tracker'): bool
    {
        if ($status === 'open' || !in_array($status, self::STATUSES, true)) return false;
        $st = $pdo->prepare("UPDATE quote_tracker SET status = ?, status_at = ?, status_reason = ? WHERE quote_key = ? AND status = 'open'");
        $st->execute([$status, UtcClock::stamp($now), $reason, $quoteKey]);
        if ($st->rowCount() !== 1) return false;
        $fu = $pdo->prepare('SELECT followup_id FROM quote_tracker WHERE quote_key = ?');
        $fu->execute([$quoteKey]);
        $fid = (int)$fu->fetchColumn();
        if ($fid > 0 && isset(self::STATUS_TO_CLOSE[$status])) {
            if (!class_exists('FollowUpService')) require_once __DIR__ . '/FollowUpService.php';
            (new \FollowUpService($pdo))->close($fid, self::STATUS_TO_CLOSE[$status], (string)$reason, $actor);
        }
        return true;
    }

    // ── 4. a linked follow-up closed elsewhere (§H.2.10.8) ───────────────────────────────────────────────────────

    /** @return array{mapped:int, held:int} */
    private static function mapClosed(\PDO $pdo, int $now): array
    {
        $out = ['mapped' => 0, 'held' => 0];
        $rows = $pdo->query("SELECT t.quote_key, t.chase_hold, f.close_reason FROM quote_tracker t JOIN followups f ON f.id = t.followup_id
                              WHERE t.status = 'open' AND f.closed_at IS NOT NULL ORDER BY t.sent_at, t.quote_key")->fetchAll(\PDO::FETCH_ASSOC);
        $hold = $pdo->prepare("UPDATE quote_tracker SET chase_hold = ? WHERE quote_key = ? AND status = 'open' AND chase_hold IS NULL");
        foreach ($rows as $r) {
            $reason = (string)($r['close_reason'] ?? '');
            $m = self::closeOutcome($reason);
            if ($m['status'] !== null) {
                if (self::setStatus($pdo, (string)$r['quote_key'], $m['status'], 'followup:' . $reason, $now)) $out['mapped']++;
            } elseif ($m['hold'] !== null && $r['chase_hold'] === null) {
                $hold->execute([$m['hold'], (string)$r['quote_key']]);
                if ($hold->rowCount() === 1) $out['held']++;
            }
        }
        return $out;
    }

    /**
     * What a follow-up's close reason does to its quotation: a status (CLOSE_TO_STATUS), nothing (exhausted, or the
     * tracker's own closes), or a chase_hold carrying the code (every other reason).
     *
     * @return array{status:?string, hold:?string}
     */
    public static function closeOutcome(string $reason): array
    {
        if (isset(self::CLOSE_TO_STATUS[$reason])) return ['status' => self::CLOSE_TO_STATUS[$reason], 'hold' => null];
        if ($reason === '' || in_array($reason, self::CLOSE_STAYS_OPEN, true) || in_array($reason, self::CLOSE_OWN, true)) {
            return ['status' => null, 'hold' => null];
        }
        return ['status' => null, 'hold' => $reason];
    }

    // ── the number a chase leaves from (§H.2.10.2, F5, A13) and the opening holds (§H.2.10.3) ─────────────────────

    /**
     * Which conversation and channel a chase of this tracker row leaves from, or why none. Reads only.
     *   - the customer spoke to us: that conversation and its channel; a salesperson's own number held by
     *     OwnedNumberHold → owned_number; a partner's number → partner_number;
     *   - never wrote: quote_chase_cold off → never_wrote; on → the quotation's own conversation, on its Evolution
     *     channel (sent_channel: account for accounts, never accounts);
     *   - AutomationPolicy::refusal(channel, phone): a final reason → channel_refused:<code>; a transient one → wait.
     *
     * @return array{conv_id:?int, channel:?string, hold:?string, wait:?string, cold:bool}
     */
    public static function number(\PDO $pdo, array $config, ?string $dataDir, array $row, $owned = null, $policy = null): array
    {
        $none = function (string $hold, bool $cold = false): array {
            return ['conv_id' => null, 'channel' => null, 'hold' => $hold, 'wait' => null, 'cold' => $cold];
        };
        if ($owned === null) {
            if (!class_exists('OwnedNumberHold')) require_once __DIR__ . '/OwnedNumberHold.php';
            $owned = \OwnedNumberHold::forInstall($config, $dataDir, $pdo);
        }
        $spoke = (int)($row['spoke_conv_id'] ?? 0);
        if ($spoke > 0) {
            $conv = $spoke;
            $chan = (string)($row['spoke_channel'] ?? '');
            $cold = false;
            if ($owned->holds($chan)) return $none('owned_number');
            $ch = self::channelRow($pdo, ['wa_channels' => self::hasTable($pdo, 'wa_channels')], $chan);
            if ($ch !== null && (string)($ch['owner_type'] ?? '') === 'partner') return $none('partner_number');
        } else {
            if (!self::truthy($config['quote_chase_cold'] ?? null)) return $none('never_wrote', true);
            $conv = (int)($row['sent_conv_id'] ?? 0);
            $chan = (string)($row['sent_channel'] ?? 'support');
            $cold = true;
        }
        if ($policy === null) {
            if (!class_exists('AutomationPolicy')) require_once __DIR__ . '/AutomationPolicy.php';
            $policy = \AutomationPolicy::forInstall($config, $dataDir, $pdo);
        }
        $why = (string)$policy->refusal($chan, (string)($row['phone'] ?? ''));
        if ($why !== '') {
            if (\AutomationPolicy::transient($why)) return ['conv_id' => $conv, 'channel' => $chan, 'hold' => null, 'wait' => $why, 'cold' => $cold];
            return $none('channel_refused:' . $why, $cold);
        }
        return ['conv_id' => $conv, 'channel' => $chan, 'hold' => null, 'wait' => null, 'cold' => $cold];
    }

    /**
     * The holds an opening sets first (§H.2.10.3): `colleague` (the row's status, or the number DishNet's own now) and
     * `ambiguous` (FollowUpPolicy::contentLevel on the conversation the chase would use). Null = neither.
     */
    public static function openingHold(\PDO $pdo, array $config, ?string $dataDir, array $row, $store = null): ?string
    {
        $ctx = self::context($pdo, $config, $dataDir, $store);
        if ((string)($row['status'] ?? '') === 'colleague') return 'colleague';
        if (self::isColleague($ctx, (string)$row['phone'], (string)($row['sent_channel'] ?? 'support'))) return 'colleague';
        $convId = (int)($row['spoke_conv_id'] ?? 0) > 0 ? (int)$row['spoke_conv_id'] : (int)($row['sent_conv_id'] ?? 0);
        $conv = $convId > 0 ? self::conversation($pdo, $convId) : null;
        if ($conv !== null) {
            if (!class_exists('FollowUpPolicy')) require_once __DIR__ . '/FollowUpPolicy.php';
            if (\FollowUpPolicy::contentLevel($conv) === \FollowUpPolicy::CONTENT_NONE) return 'ambiguous';
        }
        return null;
    }

    /** Every conversation of p (phone exactly p, never the last nine digits) except category 'staff', with the
     *  quotation's own conversation. */
    public static function convIds(\PDO $pdo, string $phone, int $sentConvId = 0): array
    {
        $st = $pdo->prepare("SELECT id FROM wa_conversations WHERE phone = ? AND COALESCE(category, '') <> 'staff'");
        $st->execute([$phone]);
        $ids = [];
        foreach ($st->fetchAll(\PDO::FETCH_COLUMN) as $i) $ids[(int)$i] = (int)$i;
        if ($sentConvId > 0) {
            $s2 = $pdo->prepare("SELECT id FROM wa_conversations WHERE id = ? AND COALESCE(category, '') <> 'staff'");
            $s2->execute([$sentConvId]);
            $i = $s2->fetchColumn();
            if ($i !== false) $ids[(int)$i] = (int)$i;
        }
        ksort($ids);
        return array_values($ids);
    }

    // ── what the book holds about a quotation (reads) ────────────────────────────────────────────────────────────

    /** The tenant alone: what the stops need to read a number (phoneOf). */
    private static function tenantContext(array $config, ?string $dataDir): array
    {
        if (!class_exists('PhoneNumber')) require_once __DIR__ . '/PhoneNumber.php';
        if (!class_exists('TenantProfile')) require_once __DIR__ . '/TenantProfile.php';
        try { $tenant = \TenantProfile::current($config, $dataDir); } catch (\Throwable $e) { $tenant = null; }
        return ['tenant' => $tenant];
    }

    /** What a run reads once: the tenant, the accounts, the switches and the tables present. */
    private static function context(\PDO $pdo, array $config, ?string $dataDir, $store): array
    {
        if (!class_exists('PhoneNumber')) require_once __DIR__ . '/PhoneNumber.php';
        if (!class_exists('TenantProfile')) require_once __DIR__ . '/TenantProfile.php';
        $tenant = null;
        try { $tenant = \TenantProfile::current($config, $dataDir); } catch (\Throwable $e) { $tenant = null; }
        $since = trim((string)($config['quote_tracking_since'] ?? ''));
        $sinceT = $since === '' ? null : (ctype_digit($since) ? (int)$since : UtcClock::parse($since));
        $ctx = [
            'config' => $config, 'data' => $dataDir, 'tenant' => $tenant, 'store' => $store,
            'since' => ($sinceT === null || $sinceT <= 0) ? null : $sinceT,
            'force_accounts' => QuoteSendEvidence::forceAccounts($config),
            'wa_channels' => self::hasTable($pdo, 'wa_channels'),
            'conv_lead_id' => self::hasColumns($pdo, 'wa_conversations', ['lead_id']),
            'colleagues' => null, 'policy' => null, 'retailers' => self::blobAll($pdo, 'retailers'),
        ];
        if ($store !== null) {
            try {
                if (!class_exists('ColleagueNumbers')) require_once __DIR__ . '/ColleagueNumbers.php';
                $ctx['colleagues'] = \ColleagueNumbers::forInstall($config, $dataDir, $store);
            } catch (\Throwable $e) { $ctx['colleagues'] = null; }
            try {
                if (!class_exists('AutomationPolicy')) require_once __DIR__ . '/AutomationPolicy.php';
                $ctx['policy'] = \AutomationPolicy::forInstall($config, $dataDir, $pdo, $store);
            } catch (\Throwable $e) { $ctx['policy'] = null; }
        }
        return $ctx;
    }

    /** ColleagueNumbers, or AutomationPolicy::senderClass, says the number is DishNet's (§H.2.9.5). */
    private static function isColleague(array $ctx, string $phone, string $channel): bool
    {
        if ($ctx['colleagues'] !== null && $ctx['colleagues']->isColleague($phone)) return true;
        if ($ctx['policy'] !== null && (string)$ctx['policy']->senderClass($channel, $phone) !== '') return true;
        return false;
    }

    /** International digits, no '+', by the tenant's rule; the raw digits when the number does not normalise. */
    private static function phoneOf(string $raw, array $ctx): string
    {
        $digits = (string)preg_replace('/\D+/', '', $raw);
        if ($digits === '' || $ctx['tenant'] === null) return $digits;
        $intl = \PhoneNumber::international($raw, $ctx['tenant']);
        return $intl === null ? $digits : ltrim($intl, '+');
    }

    private static function conversation(\PDO $pdo, int $id): ?array
    {
        $st = $pdo->prepare('SELECT * FROM wa_conversations WHERE id = ?');
        $st->execute([$id]);
        $r = $st->fetch(\PDO::FETCH_ASSOC);
        return is_array($r) ? $r : null;
    }

    /** The uCRM quote id: wa_sent_quotes, then quotes_log, then kyc_applications ('PENDING' ignored) (§H.2.9.2). */
    private static function quoteIdOf(\PDO $pdo, array $ctx, string $number): ?int
    {
        if (self::hasTable($pdo, 'wa_sent_quotes')) {
            $st = $pdo->prepare('SELECT quote_id FROM wa_sent_quotes WHERE TRIM(quote_ref) = ? ORDER BY sent_at, quote_id LIMIT 1');
            $st->execute([$number]);
            $v = $st->fetchColumn();
            if ($v !== false && (int)$v > 0) return (int)$v;
        }
        $log = self::blobWhere($pdo, 'quotes_log', 'quote_ref', $number);
        foreach ($log as $r) if ((int)($r['crm_quote_id'] ?? 0) > 0) return (int)$r['crm_quote_id'];
        foreach (self::blobWhere($pdo, 'kyc_applications', 'quote_ref', $number) as $a) {
            $q = trim((string)($a['quote_id'] ?? ''));
            if ($q !== '' && strtoupper($q) !== 'PENDING' && ctype_digit($q) && (int)$q > 0) return (int)$q;
        }
        return null;
    }

    /** The KYC application this quotation is: by its uCRM id, else by its number ('PENDING' never). */
    private static function kycByQuote(\PDO $pdo, array $ctx, ?int $quoteId, string $number): ?array
    {
        if ($quoteId !== null) {
            foreach (self::blobWhere($pdo, 'kyc_applications', 'quote_id', (string)$quoteId) as $a) return $a;
        }
        if (strtoupper(trim($number)) === 'PENDING') return null;
        foreach (self::blobWhere($pdo, 'kyc_applications', 'quote_ref', $number) as $a) return $a;
        return null;
    }

    /** The plugin's own quote log row: by its uCRM id, else by its number. */
    private static function logOf(\PDO $pdo, array $ctx, ?int $quoteId, string $number): ?array
    {
        if ($quoteId !== null) {
            foreach (self::blobWhere($pdo, 'quotes_log', 'crm_quote_id', (string)$quoteId) as $r) return $r;
        }
        foreach (self::blobWhere($pdo, 'quotes_log', 'quote_ref', $number) as $r) return $r;
        return null;
    }

    /** A quotes_log row of type lead joined by crm_quote_id or quote_ref. */
    private static function leadLogOf(\PDO $pdo, array $ctx, ?int $quoteId, string $number): ?array
    {
        $rows = $quoteId !== null ? self::blobWhere($pdo, 'quotes_log', 'crm_quote_id', (string)$quoteId) : [];
        foreach (array_merge($rows, self::blobWhere($pdo, 'quotes_log', 'quote_ref', $number)) as $r) {
            if ((string)($r['type'] ?? '') === 'lead') return $r;
        }
        return null;
    }

    /** The lead a lead quotation was made for: leads.json stamps quote_ref and crm_quote_id (lib/QuotationService.php:237-249). */
    private static function leadOf(\PDO $pdo, array $ctx, ?int $quoteId, string $number): ?array
    {
        if ($quoteId !== null) {
            foreach (self::blobWhere($pdo, 'leads', 'crm_quote_id', (string)$quoteId) as $l) return $l;
        }
        foreach (self::blobWhere($pdo, 'leads', 'quote_ref', $number) as $l) return $l;
        return null;
    }

    /** The client: from the KYC application or the quote log, else the conversation's only when its link is trusted. */
    private static function clientOf(?array $kycApp, ?array $log, array $conv): ?int
    {
        foreach ([$kycApp, $log] as $r) {
            if ($r !== null && (int)($r['crm_client_id'] ?? 0) > 0) return (int)$r['crm_client_id'];
        }
        if ((int)($conv['crm_client_id'] ?? 0) > 0) {
            if (!class_exists('FollowUpPolicy')) require_once __DIR__ . '/FollowUpPolicy.php';
            if (in_array((string)($conv['crm_link_method'] ?? ''), \FollowUpPolicy::TRUSTED_LINKS, true)) return (int)$conv['crm_client_id'];
        }
        return null;
    }

    /** Spoke-to (§H.2.9.5): the conversations with phone exactly p, each with its latest inbound row at or before the send. */
    private static function spokeOf(\PDO $pdo, array $ctx, string $p, string $sentAt): ?array
    {
        $st = $pdo->prepare("SELECT c.*, (SELECT MAX(m.sent_at) FROM wa_messages m WHERE m.conversation_id = c.id
                                              AND m.direction = 'in' AND m.sent_at <= ?) AS last_in
                               FROM wa_conversations c WHERE c.phone = ?");
        $st->execute([$sentAt, $p]);
        $cands = [];
        foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $c) {
            $ch = self::channelRow($pdo, $ctx, (string)$c['channel']);
            $c['role'] = $ch !== null ? (string)($ch['role'] ?? '') : ((string)$c['channel'] === 'accounts' ? 'account' : (string)$c['channel']);
            $cands[] = $c;
        }
        return QuoteSendEvidence::spokeTo($cands, $sentAt);
    }

    private static function channelRow(\PDO $pdo, array $ctx, string $channel): ?array
    {
        if (empty($ctx['wa_channels']) || $channel === '') return null;
        $st = $pdo->prepare('SELECT * FROM wa_channels WHERE channel_id = ?');
        $st->execute([$channel]);
        $r = $st->fetch(\PDO::FETCH_ASSOC);
        return is_array($r) ? $r : null;
    }

    /** Every KYC application of the client. */
    private static function kycOfClient(\PDO $pdo, array $ctx, int $client): array
    {
        return self::blobWhere($pdo, 'kyc_applications', 'crm_client_id', (string)$client);
    }

    /** The latest KYC application of the client submitted before the send (rule 6); submitted_at is date(), Kampala. */
    private static function kycOfClientBefore(\PDO $pdo, array $ctx, int $client, string $sentAt): ?array
    {
        $local = self::kampalaStamp($sentAt);
        $best = null;
        foreach (self::kycOfClient($pdo, $ctx, $client) as $a) {
            $s = (string)($a['submitted_at'] ?? '');
            if ($s === '' || $s >= $local) continue;
            if ($best === null || [$s, (int)($a['id'] ?? 0)] > [(string)$best['submitted_at'], (int)($best['id'] ?? 0)]) $best = $a;
        }
        return $best;
    }

    /** A Cash KYC with amount_charged > 0 for this uCRM quote id (lib/KycService.php:1198). */
    private static function cashPaid(\PDO $pdo, array $ctx, int $quoteId): bool
    {
        foreach (self::blobWhere($pdo, 'kyc_applications', 'quote_id', (string)$quoteId) as $a) {
            if (self::isCashPaid($a)) return true;
        }
        return false;
    }

    private static function isCashPaid(array $a): bool
    {
        return strtolower(trim((string)($a['sales_type'] ?? ''))) === 'cash' && (float)($a['amount_charged'] ?? 0) > 0;
    }

    /** The raw phones of p's conversations, with p itself: opt-outs are stored as the digits they were typed with. */
    private static function convPhones(\PDO $pdo, array $convs, string $p): array
    {
        $out = [$p => $p];
        if ($convs !== []) {
            foreach ($pdo->query('SELECT phone FROM wa_conversations WHERE id IN (' . implode(',', $convs) . ')')->fetchAll(\PDO::FETCH_COLUMN) as $ph) {
                $d = (string)preg_replace('/\D+/', '', (string)$ph);
                if ($d !== '') $out[$d] = $d;
            }
        }
        return array_values($out);
    }

    // ── the store's JSON collections (blob tables: id, data) ─────────────────────────────────────────────────────

    /** Every row of a collection, each with its id. An absent table is empty. */
    private static function blobAll(\PDO $pdo, string $table): array
    {
        if (!self::hasColumns($pdo, $table, ['data'])) return [];
        $out = [];
        foreach ($pdo->query("SELECT id, data FROM [{$table}] ORDER BY id")->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $d = json_decode((string)$r['data'], true);
            if (!is_array($d)) continue;
            $d['id'] = (int)$r['id'];
            $out[] = $d;
        }
        return $out;
    }

    /** The rows whose $.field equals $value (compared as text, trimmed), lowest id first. An absent table is empty. */
    private static function blobWhere(\PDO $pdo, string $table, string $field, string $value): array
    {
        if (trim($value) === '' || !self::hasColumns($pdo, $table, ['data'])) return [];
        if (!preg_match('/^[a-z_][a-z0-9_]*$/', $field)) throw new \InvalidArgumentException('not a field name');
        $st = $pdo->prepare("SELECT id, data FROM [{$table}] WHERE TRIM(CAST(json_extract(data, '$.{$field}') AS TEXT)) = ? ORDER BY id");
        $st->execute([trim($value)]);
        $out = [];
        foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $d = json_decode((string)$r['data'], true);
            if (!is_array($d)) continue;
            $d['id'] = (int)$r['id'];
            $out[] = $d;
        }
        return $out;
    }

    private static function blobOne(\PDO $pdo, array $ctx, string $table, string $field, string $value): ?array
    {
        if ($field === 'id') {
            if (!self::hasColumns($pdo, $table, ['data'])) return null;
            $st = $pdo->prepare("SELECT id, data FROM [{$table}] WHERE id = ?");
            $st->execute([(int)$value]);
            $r = $st->fetch(\PDO::FETCH_ASSOC);
            if (!is_array($r)) return null;
            $d = json_decode((string)$r['data'], true);
            if (!is_array($d)) return null;
            $d['id'] = (int)$r['id'];
            return $d;
        }
        $rows = self::blobWhere($pdo, $table, $field, $value);
        return $rows[0] ?? null;
    }

    // ── small things ─────────────────────────────────────────────────────────────────────────────────────────────

    private static function hasTable(\PDO $pdo, string $name): bool
    {
        $st = $pdo->prepare("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = ?");
        $st->execute([$name]);
        return (int)$st->fetchColumn() > 0;
    }

    /** The table exists and has every one of these columns. */
    private static function hasColumns(\PDO $pdo, string $table, array $cols): bool
    {
        if (!preg_match('/^[a-z_][a-z0-9_]*$/', $table) || !self::hasTable($pdo, $table)) return false;
        $have = [];
        foreach ($pdo->query("PRAGMA table_info([{$table}])")->fetchAll(\PDO::FETCH_ASSOC) as $c) $have[(string)$c['name']] = true;
        foreach ($cols as $c) if (!isset($have[$c])) return false;
        return true;
    }

    /** A stored stamp in the storage form ('Y-m-d H:i:s' UTC), or null when it cannot be read. */
    private static function norm(string $s): ?string
    {
        $t = UtcClock::parse($s);
        return $t === 0 ? null : UtcClock::stamp($t);
    }

    /** The same moment as a Kampala wall-clock stamp: how date() dates the KYC, cashbook and collection rows here. */
    private static function kampalaStamp(string $utcStamp): string
    {
        return (new \DateTime('@' . UtcClock::parse($utcStamp)))->setTimezone(new \DateTimeZone(self::TZ))->format('Y-m-d H:i:s');
    }

    /** The master flag rule: 1, true, on, yes. */
    private static function truthy($v): bool
    {
        if ($v === true) return true;
        return in_array(strtolower(trim((string)($v ?? ''))), ['1', 'true', 'on', 'yes'], true);
    }
}
