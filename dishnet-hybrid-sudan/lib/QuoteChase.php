<?php
declare(strict_types=1);

require_once __DIR__ . '/StaffJobsGate.php';
require_once __DIR__ . '/UtcClock.php';
require_once __DIR__ . '/JobBudget.php';
require_once __DIR__ . '/WaSendBudget.php';
require_once __DIR__ . '/QuoteSendEvidence.php';
require_once __DIR__ . '/QuoteTracker.php';
require_once __DIR__ . '/QuoteChasePolicy.php';
require_once __DIR__ . '/QuoteChaseText.php';
require_once __DIR__ . '/FollowUpService.php';

/**
 * QuoteChase — the quote chase, P2b (5.18.104, Uganda, root docs/73 §H.2.10; contract C3, C7, C9; rulings R3, R13, R18,
 * R19). Day 1, 3 and 7 after a quotation, in the approved wording (D9), from the number the customer spoke to.
 *
 *   on()            Uganda, quote_tracking_enabled, quote_chase_enabled, the ledger on, dn_tz() Africa/Kampala (§H.2.10.1)
 *   open()          cron/quote_chase.php: one chase per open tracker row, holds first, then in one BEGIN IMMEDIATE the
 *                   phone's open enquiries closed `superseded`, the follow-up opened, the tracker linked (§H.2.10.3)
 *   draftDue()      cron/quote_chase.php: stale drafts expired; each due row (inside the window, no draft pending) checked
 *                   in uCRM (3 GETs at most, 3 s / 5 s), drafted from the template and approved as quote_chase (§H.2.10.5)
 *   preSendCheck()  cron/followup_send.php's quote pass, item 2: local reads only; a failure closes or expires (§H.2.10.6)
 *   afterClaimStop() the same pass, item 4: the three customer stops read again after an ok claim (ruling R18; A14-08)
 *   demand()        the chases due today by handset: what `on chase` prints and the ledger reserves for (§H.2.5.4)
 *   offHook()       row 6's rollback hook, on the tool's $rw (ruling R13; §H.2.16 item 2)
 *   offerHolds()/offerHold()  "a quotation is in play": the one meaning Part O reads (§H.2.10.10; ruling R3)
 *
 * Inert unless on(); South Sudan never reaches any of it (StaffJobsGate, and the ledger cannot be on there). A read
 * failure throws: nothing here ever answers "no stop" or "no hold" because a read failed. No uCRM call but one GET per
 * drafted row (C9). Every Kampala date and hour is computed in a literal Africa/Kampala zone. PHP 7.4.
 */
final class QuoteChase
{
    /** The literal zone every Kampala date and hour here is computed in (R-16). */
    public const TZ = 'Africa/Kampala';

    /** uCRM status reads per quote_chase run (§H.2.10.5), each started only with this many seconds left. */
    const UCRM_GETS = 3;
    const UCRM_GET_S = 5;
    /** The fixed code a stale or edited quote draft is expired with (§H.2.10.6 item 2). */
    const NOT_TEMPLATE = 'quote_chase_not_template';
    /** How a chase is stored (A15, R-10): never 'DishNet', which humanRepliedSince counts as a colleague. */
    const AGENT = 'DishNet Plugin';
    /** The echo claim's event (§H.2.10.6 item 7). */
    const ECHO_SOURCE = 'quote.chase';
    /** Who approves a templated draft: quote_chase_enabled is the operator's approval (F8). */
    const DECIDED_BY = 'auto:quote_chase';
    /** A claim the stops ended before the send (ruling R18; A14-08). */
    const STOPPED_AFTER_CLAIM = 'stopped_after_claim';
    /** The escalation scan reads p's inbound rows from this many days before the quotation (F8). */
    const ESCALATION_DAYS = 30;
    /** The offers hold's two 48-hour clauses (§H.2.10.10). */
    const HOLD_S = 48 * 3600;
    /** The quote pass's lock, under the data directory (B11-02). */
    const LOCK_FILE = 'quote_send.lock';

    // ── the switch ─────────────────────────────────────────────────────────────────────────────────────────────

    /** §H.2.10.1: Uganda, tracking on, the chase on, the ledger on, and the install's zone Africa/Kampala. */
    public static function on(array $config, ?string $dataDir): bool
    {
        if (!self::truthy($config['quote_tracking_enabled'] ?? null) || !self::truthy($config['quote_chase_enabled'] ?? null)) return false;
        if (!StaffJobsGate::applies($config, $dataDir)) return false;
        if (!WaSendBudget::on($config, $dataDir)) return false;
        if (!function_exists('dn_tz')) require_once __DIR__ . '/timezone.php';
        return dn_tz($config) === self::TZ;
    }

    /** The cold chase (step 10): quote_chase_cold too. */
    public static function cold(array $config): bool
    {
        return self::truthy($config['quote_chase_cold'] ?? null);
    }

    // ── opening (§H.2.10.3) ────────────────────────────────────────────────────────────────────────────────────

    /**
     * Open a chase for each open tracker row sent at or after quote_chase_not_before, not backfilled, with no follow-up
     * and no hold. Holds are set first: colleague and ambiguous (QuoteTracker::openingHold), escalation (F8), before
     * switch-on, and what QuoteTracker::number() holds (owned_number, partner_number, never_wrote, channel_refused:…);
     * a transient refusal waits for a later run. Then, in one BEGIN IMMEDIATE: every open enquiry follow-up of the phone,
     * on every conversation, is closed `superseded` (R-06); openQuote(); quote_tracker.followup_id set.
     *
     * @return array<string,int> counts only
     */
    public static function open(\PDO $pdo, array $config, ?string $dataDir, $store, int $now, int $until): array
    {
        $c = ['opened' => 0, 'held' => 0, 'waiting' => 0, 'superseded' => 0, 'failed' => 0, 'cut' => 0];
        if (!self::on($config, $dataDir)) return $c;
        $notBefore = UtcClock::parse($config['quote_chase_not_before'] ?? '');
        if ($notBefore <= 0) return $c;   // the switch writes it; without it nothing is opened

        if (!class_exists('OwnedNumberHold')) require_once __DIR__ . '/OwnedNumberHold.php';
        if (!class_exists('AutomationPolicy')) require_once __DIR__ . '/AutomationPolicy.php';
        $owned = \OwnedNumberHold::forInstall($config, $dataDir, $pdo);
        $policy = \AutomationPolicy::forInstall($config, $dataDir, $pdo, $store);
        $window = WaSendBudget::window($config);
        $svc = new \FollowUpService($pdo);

        $rows = $pdo->query("SELECT * FROM quote_tracker WHERE status = 'open' AND followup_id IS NULL AND chase_hold IS NULL
                              ORDER BY sent_at, quote_key")->fetchAll(\PDO::FETCH_ASSOC);
        $hold = $pdo->prepare("UPDATE quote_tracker SET chase_hold = ? WHERE quote_key = ? AND status = 'open'
                                 AND chase_hold IS NULL AND followup_id IS NULL");
        foreach ($rows as $row) {
            if (time() >= $until) { $c['cut'] = 1; break; }
            $why = QuoteTracker::openingHold($pdo, $config, $dataDir, $row, $store);
            if ($why === null && self::escalationWord($pdo, $row)) $why = 'escalation';
            if ($why === null && ((int)$row['backfilled'] === 1 || UtcClock::parse($row['sent_at']) < $notBefore)) $why = 'before_switch_on';
            $n = null;
            if ($why === null) {
                $n = QuoteTracker::number($pdo, $config, $dataDir, $row, $owned, $policy);
                if ($n['hold'] !== null) {
                    $why = $n['hold'];
                } elseif ($n['wait'] !== null) {
                    $c['waiting']++;
                    continue;
                }
            }
            $due = null;
            if ($why === null) {
                $d = QuoteChasePolicy::next(1, UtcClock::parse($row['sent_at']), (string)$row['valid_until'], null, $window);
                if ($d['step'] === 1) $due = (int)$d['at'];
                else $why = 'past_validity';
            }
            if ($why !== null) {
                $hold->execute([$why, (string)$row['quote_key']]);
                if ($hold->rowCount() === 1) $c['held']++;
                continue;
            }
            $conv = self::conversation($pdo, (int)$n['conv_id']);
            if ($conv === null) { $c['failed']++; continue; }
            // The Evolution channel it leaves through: a conversation filed under `accounts` sends from `account`, never
            // `accounts`, which has no instance (§H.2.10.2; lib/NotificationService.php:127-129).
            $conv['channel'] = (string)$n['channel'] === 'accounts' ? 'account' : (string)$n['channel'];

            $pdo->exec('BEGIN IMMEDIATE');
            try {
                $c['superseded'] += self::supersede($pdo, $svc, $row);
                $fid = $svc->openQuote($conv, [
                    'quote_key' => (string)$row['quote_key'], 'ref' => (string)$row['quote_ref'],
                    'valid_until' => (string)$row['valid_until'], 'sent_at' => (string)$row['sent_at'],
                    'due_at' => UtcClock::stamp($due), 'crm_client_id' => $row['crm_client_id'],
                ]);
                $link = $pdo->prepare("UPDATE quote_tracker SET followup_id = ? WHERE quote_key = ? AND followup_id IS NULL AND status = 'open'");
                $link->execute([$fid, (string)$row['quote_key']]);
                if ($link->rowCount() !== 1) throw new \RuntimeException('the tracker row moved');
                $pdo->exec('COMMIT');
                $c['opened']++;
            } catch (\Throwable $e) {
                try { $pdo->exec('ROLLBACK'); } catch (\Throwable $e2) { /* nothing held */ }
                $c['failed']++;
            }
        }
        return $c;
    }

    /** Close every open enquiry follow-up of the quotation's phone, on every conversation of it, as superseded (R-06). */
    private static function supersede(\PDO $pdo, \FollowUpService $svc, array $row): int
    {
        $convs = QuoteTracker::convIds($pdo, (string)$row['phone'], (int)($row['sent_conv_id'] ?? 0));
        if ($convs === []) return 0;
        $kind = $svc->hasKind() ? " AND COALESCE(kind,'enquiry') = 'enquiry'" : '';
        $st = $pdo->query('SELECT id FROM followups WHERE closed_at IS NULL AND conversation_id IN (' . implode(',', $convs) . ')' . $kind);
        $n = 0;
        foreach ($st->fetchAll(\PDO::FETCH_COLUMN) as $id) {
            if ($svc->close((int)$id, 'superseded', 'a quotation was sent; its chase replaces this follow-up', 'quote_chase')) $n++;
        }
        return $n;
    }

    /** An escalation word in p's own inbound rows, on every conversation of p, from sent_at − 30 days (F8). */
    private static function escalationWord(\PDO $pdo, array $row): bool
    {
        $convs = QuoteTracker::convIds($pdo, (string)$row['phone'], (int)($row['sent_conv_id'] ?? 0));
        if ($convs === []) return false;
        if (!class_exists('EmailReplyPolicy')) require_once __DIR__ . '/EmailReplyPolicy.php';
        $from = UtcClock::stamp(UtcClock::parse($row['sent_at']) - self::ESCALATION_DAYS * 86400);
        $st = $pdo->prepare('SELECT body FROM wa_messages WHERE conversation_id IN (' . implode(',', $convs) . ")
                              AND direction = 'in' AND (sent_at > ? OR created_at > ?)");
        $st->execute([$from, $from]);
        foreach ($st->fetchAll(\PDO::FETCH_COLUMN) as $body) {
            if (\EmailReplyPolicy::scanForEscalation((string)$body)['escalate']) return true;
        }
        return false;
    }

    // ── drafting (§H.2.10.5) ───────────────────────────────────────────────────────────────────────────────────

    /**
     * Expire the stale drafts, then draft each due row: due_at reached, now inside the window, no pending or approved
     * draft, and the budget allowing. Per row: the uCRM status (one GET, at most UCRM_GETS a run, each only when
     * JobBudget::mayStart($until, 5); accepted or declined stops it); the template (the alternating variant on its
     * handset, the salesperson form where the line is one's); approve(draft, auto:quote_chase).
     *
     * @param object|null $crm a uCRM client (get(), withTimeouts()); null builds CrmApiClient::fromUcrm(). Only get() is
     *                         ever called on it (C9).
     * @return array<string,int> counts only
     */
    public static function draftDue(\PDO $pdo, array $config, ?string $dataDir, $store, int $now, int $until, $crm = null): array
    {
        $c = ['expired' => 0, 'drafted' => 0, 'approved' => 0, 'persona_held' => 0, 'skipped_floor' => 0, 'stopped' => 0,
              'ucrm_gets' => 0, 'ucrm_wait' => 0, 'outside_window' => 0, 'exhausted' => 0, 'cut' => 0];
        if (!self::on($config, $dataDir)) return $c;
        $svc = new \FollowUpService($pdo);
        $c['expired'] = $svc->expireQuoteDrafts(WaSendBudget::localDay($now));
        $window = WaSendBudget::window($config);
        if (!QuoteChasePolicy::inWindow($now, $window)) { $c['outside_window'] = 1; return $c; }

        $st = $pdo->prepare("SELECT f.id AS fid, f.channel, f.attempts, f.last_sent_at, f.conversation_id, t.*
                               FROM followups f JOIN quote_tracker t ON t.followup_id = f.id
                              WHERE f.kind = 'quote' AND f.closed_at IS NULL AND f.due_at IS NOT NULL AND f.due_at <= ?
                                AND t.status = 'open'
                                AND NOT EXISTS (SELECT 1 FROM followup_drafts d WHERE d.followup_id = f.id AND d.status IN ('pending','approved'))
                              ORDER BY f.due_at, f.id");
        $st->execute([UtcClock::stamp($now)]);
        $rows = $st->fetchAll(\PDO::FETCH_ASSOC);
        if ($rows === []) return $c;

        $client = null;
        $evo = self::evo($config, $dataDir, $pdo);
        $confirmed = QuoteChaseText::personaConfirmed($config);
        foreach ($rows as $r) {
            if (time() >= $until) { $c['cut'] = 1; break; }
            $fid = (int)$r['fid'];
            $step = (int)$r['attempts'] + 1;
            if ($step > 3) { $svc->close($fid, 'exhausted', 'the chase has no step left', 'quote_chase'); $c['exhausted']++; continue; }
            $cut = QuoteChasePolicy::validEnd((string)$r['valid_until']);
            if ($cut === null || $now >= $cut) continue;   // the tracker's expiry closes it

            // 1. The uCRM status: one GET, inside the run's budget (C9: get() only).
            if ($r['quote_id'] !== null && (int)$r['quote_id'] > 0) {
                if ($c['ucrm_gets'] >= self::UCRM_GETS || !JobBudget::mayStart($until, self::UCRM_GET_S)) { $c['ucrm_wait']++; continue; }
                if ($client === null) {
                    if ($crm === null) {
                        if (!class_exists('CrmApiClient')) require_once __DIR__ . '/CrmApiClient.php';
                        $crm = \CrmApiClient::fromUcrm(dirname(__DIR__), $config);
                    }
                    $client = $crm->withTimeouts(3, 5);
                }
                $c['ucrm_gets']++;
                $q = null;
                try { $q = $client->get('billing/quotes/' . (int)$r['quote_id']); } catch (\Throwable $e) { $q = null; }
                $s = QuoteTracker::ucrmStatus(is_array($q) ? ($q['status'] ?? null) : null);
                if ($s !== null) {
                    if (QuoteTracker::setStatus($pdo, (string)$r['quote_key'], $s, 'ucrm_status', $now, 'quote_chase')) $c['stopped']++;
                    continue;
                }
            }

            // 2. The template.
            $chan = (string)$r['channel'];
            $persona = self::persona($config, $dataDir, $pdo, $store, $chan, $evo);
            $variant = self::nextVariant($pdo, $config, $dataDir, $evo, $chan);
            $form = QuoteChaseText::form($step, $variant, $persona, $confirmed);
            if ($form['held'] !== null) {
                $c[self::skipStep($pdo, $svc, $r, $step, $form['held'], $now, $window)]++;
                continue;
            }
            $v = substr($form['key'], -1);
            $body = QuoteChaseText::render($step, $v, (string)$r['quote_ref'], (string)$r['valid_until'], $form['persona'] ? $persona : null, $confirmed);
            if ($body === null) { $c[self::skipStep($pdo, $svc, $r, $step, QuoteChaseText::PERSONA_PENDING, $now, $window)]++; continue; }
            $d = $svc->draftTemplate($fid, $body, QuoteChaseText::templateId($step, $v, $form['persona']), 'quotation chase step ' . $step);
            if (empty($d['ok'])) continue;
            $c['drafted']++;

            // 3. quote_chase_enabled is the operator's approval to send these templated messages unread (F8).
            $a = $svc->approve((int)$d['id'], self::DECIDED_BY, '', 'quote_chase_enabled');
            if (!empty($a['ok'])) $c['approved']++;
        }
        return $c;
    }

    /**
     * A step held on this line (the composed day-3 form before sf_persona_confirmed): it is passed over, logged with its
     * code (the salesperson's list shows it as theirs to call), and the next step armed from the previous send; with none
     * left the row closes as exhausted. Returns the count's key.
     */
    private static function skipStep(\PDO $pdo, \FollowUpService $svc, array $r, int $step, string $code, int $now, array $window): string
    {
        $fid = (int)$r['fid'];
        $prev = trim((string)($r['last_sent_at'] ?? '')) !== '' ? UtcClock::parse($r['last_sent_at']) : null;
        $n = QuoteChasePolicy::next($step + 1, UtcClock::parse($r['sent_at']), (string)$r['valid_until'], $prev, $window);
        $svc->log($fid, (int)$r['conversation_id'], 'skipped', 'step ' . $step . ' held: ' . $code, 'quote_chase');
        if ($n['step'] === null) {
            $pdo->prepare('UPDATE followups SET attempts = ?, due_at = NULL WHERE id = ? AND closed_at IS NULL')->execute([$step, $fid]);
            $svc->close($fid, 'exhausted', 'the chase has no step left', 'quote_chase');
            return 'exhausted';
        }
        $pdo->prepare('UPDATE followups SET attempts = ?, due_at = ? WHERE id = ? AND closed_at IS NULL')
            ->execute([(int)$n['step'] - 1, UtcClock::stamp((int)$n['at']), $fid]);
        return $code === QuoteChaseText::PERSONA_PENDING ? 'persona_held' : 'skipped_floor';
    }

    /**
     * The variant a step takes on a handset: the opposite of the latest quote draft on that handset, A when there is
     * none (§H.2.10.4). The handset is the Evolution instance the channel sends through, normalised by the ledger's rule.
     */
    public static function nextVariant(\PDO $pdo, array $config, ?string $dataDir, $evo, string $channel): string
    {
        $h = WaSendBudget::handset((string)$evo->instanceFor($channel));
        $st = $pdo->query("SELECT f.channel, d.model FROM followup_drafts d JOIN followups f ON f.id = d.followup_id
                            WHERE f.kind = 'quote' AND d.model LIKE 'template:%' ORDER BY d.id DESC LIMIT 500");
        $seen = [];
        foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $d) {
            $ch = (string)$d['channel'];
            if (!array_key_exists($ch, $seen)) $seen[$ch] = WaSendBudget::handset((string)$evo->instanceFor($ch));
            if ($seen[$ch] !== $h) continue;
            $p = QuoteChaseText::parseId((string)$d['model']);
            if ($p !== null) return $p[1] === 'A' ? 'B' : 'A';
        }
        return 'A';
    }

    /**
     * The persona of a channel: the owner's first name on a salesperson's own number (LineOwner::of); '' on such a
     * number with no usable first name (the department wording); null on a department or partner line.
     */
    public static function persona(array $config, ?string $dataDir, \PDO $pdo, $store, string $channel, $evo = null): ?string
    {
        try {
            if ($evo === null) $evo = self::evo($config, $dataDir, $pdo);
            if (!method_exists($evo, 'channelContext')) return null;
            $cx = $evo->channelContext($channel);
            if ($cx === null) return null;
            if (!class_exists('LineOwner')) require_once __DIR__ . '/LineOwner.php';
            $lo = \LineOwner::of($cx, $store, $config, $dataDir);
            if ($lo !== null) return $lo->firstName();
            return $cx->ownerType() === 'staff' ? '' : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    // ── sending: what cron/followup_send.php's quote pass asks (§H.2.10.6) ──────────────────────────────────────

    /**
     * Item 2, all local reads: the tracker still open; the draft decided today (Kampala); its body exactly the template
     * render() gives now; now before 20:00 on valid_until; §H.2.9.7's opted_out, answered (marks included) and taken
     * across all of p's conversations (a live opt-out on any channel among them); no escalation word in p's inbound rows.
     * A failure closes the follow-up and sets the tracker (setStatus), or expires the draft with NOT_TEMPLATE.
     *
     * @param array $draft an approvedQuoteDrafts() row
     * @return array{ok:bool, code:?string, cold:bool, step:int}
     */
    public static function preSendCheck(\PDO $pdo, array $config, ?string $dataDir, array $draft, int $now, $store = null): array
    {
        $fail = function (string $code): array { return ['ok' => false, 'code' => $code, 'cold' => false, 'step' => 0]; };
        $svc = new \FollowUpService($pdo);
        $fid = (int)$draft['followup_id'];
        $tr = self::trackerOf($pdo, (string)($draft['quote_key'] ?? ''));
        if ($tr === null || (string)$tr['status'] !== 'open') {
            $svc->close($fid, $tr === null ? 'cancelled' : (QuoteTracker::STATUS_TO_CLOSE[(string)$tr['status']] ?? 'cancelled'),
                        'the quotation is no longer open', 'sender');
            return $fail('tracker_closed');
        }
        $decided = UtcClock::parse($draft['decided_at'] ?? '');
        if ($decided <= 0 || WaSendBudget::localDay($decided) !== WaSendBudget::localDay($now)) {
            $svc->expireDraft((int)$draft['id'], self::NOT_TEMPLATE);
            return $fail(self::NOT_TEMPLATE);
        }
        $id = QuoteChaseText::parseId((string)($draft['model'] ?? ''));
        $body = trim((string)($draft['edited_body'] ?? '')) !== '' ? (string)$draft['edited_body'] : (string)$draft['body'];
        $persona = null;
        if ($id !== null && $id[2]) $persona = (string)self::persona($config, $dataDir, $pdo, $store, (string)$draft['channel']);
        if ($id === null || $id[0] !== (int)$draft['attempt'] || ($id[2] && $persona === '')
            || !QuoteChaseText::matches($body, $id[0], $id[1], (string)$tr['quote_ref'], (string)$tr['valid_until'], $persona,
                                        QuoteChaseText::personaConfirmed($config))) {
            $svc->expireDraft((int)$draft['id'], self::NOT_TEMPLATE);
            return $fail(self::NOT_TEMPLATE);
        }
        $cut = QuoteChasePolicy::validEnd((string)$tr['valid_until']);
        if ($cut === null || $now >= $cut) {
            QuoteTracker::setStatus($pdo, (string)$tr['quote_key'], 'expired', 'valid_until', $now, 'sender');
            return $fail('expired');
        }
        $s = QuoteTracker::customerStop($pdo, $config, $dataDir, $tr, $now);
        if ($s !== null) {
            QuoteTracker::setStatus($pdo, (string)$tr['quote_key'], $s[0], $s[1], $now, 'sender');
            return $fail($s[0]);
        }
        if (self::escalationWord($pdo, $tr)) {
            QuoteTracker::setStatus($pdo, (string)$tr['quote_key'], 'taken', 'escalation_word', $now, 'sender');
            return $fail('taken');
        }
        return ['ok' => true, 'code' => null, 'cold' => (int)($tr['spoke_conv_id'] ?? 0) <= 0, 'step' => $id[0]];
    }

    /** Item 4 (B11-02): the draft still approved and its follow-up still open, read again by id. */
    public static function stillApproved(\PDO $pdo, int $draftId, int $followupId): bool
    {
        $st = $pdo->prepare("SELECT 1 FROM followup_drafts d JOIN followups f ON f.id = d.followup_id
                              WHERE d.id = ? AND d.status = 'approved' AND f.id = ? AND f.closed_at IS NULL");
        $st->execute([$draftId, $followupId]);
        return $st->fetchColumn() !== false;
    }

    /**
     * Item 4, after an ok claim (ruling R18; A14-08): opted_out, answered (marks included) and taken read again on the
     * connection the claim committed on. One found closes the follow-up and sets the tracker as preSendCheck would, and
     * its code is returned (the caller settles the claim failed, detail STOPPED_AFTER_CLAIM, and sends nothing).
     */
    public static function afterClaimStop(\PDO $pdo, array $config, ?string $dataDir, array $draft, int $now): ?string
    {
        $tr = self::trackerOf($pdo, (string)($draft['quote_key'] ?? ''));
        if ($tr === null) return 'tracker_closed';
        if ((string)$tr['status'] !== 'open') return (string)$tr['status'];
        $s = QuoteTracker::customerStop($pdo, $config, $dataDir, $tr, $now);
        if ($s === null) return null;
        QuoteTracker::setStatus($pdo, (string)$tr['quote_key'], $s[0], $s[1], $now, 'sender');
        return $s[0];
    }

    /** What a sent chase is stored as (§H.2.10.6 item 10; R-10): role agent, 'DishNet Plugin', its metadata. */
    public static function storedRow(string $body, string $waId, int $followupId, array $draft): array
    {
        $id = QuoteChaseText::parseId((string)($draft['model'] ?? ''));
        return [
            'direction' => 'out', 'role' => 'agent', 'body' => $body,
            'agent_name' => self::AGENT, 'wa_message_id' => $waId !== '' ? $waId : null,
            'metadata' => ['source' => 'quote_chase', 'followup_id' => $followupId, 'step' => $id === null ? 0 : $id[0],
                           'template' => (string)($draft['model'] ?? '')],
        ];
    }

    /**
     * The oldest draft per handset, at most $max handsets (§H.2.10.6, the lists): one claim per handset per run, the
     * spacing would defer the rest anyway.
     *
     * @param callable $handsetOf channel => handset
     */
    public static function perHandset(array $drafts, callable $handsetOf, int $max): array
    {
        $out = []; $seen = [];
        foreach ($drafts as $d) {
            $h = WaSendBudget::handset((string)$handsetOf((string)$d['channel']));
            if (isset($seen[$h])) continue;
            $seen[$h] = true;
            $out[] = $d;
            if (count($out) >= $max) break;
        }
        return $out;
    }

    /** Take the quote pass's lock without waiting (LOCK_EX|LOCK_NB): a handle, or null while another holds it. */
    public static function passLock(string $dataDir, int $waitSeconds = 0)
    {
        $fp = @fopen(rtrim($dataDir, '/') . '/' . self::LOCK_FILE, 'c');
        if ($fp === false) return null;
        $until = time() + max(0, $waitSeconds);
        do {
            if (flock($fp, LOCK_EX | LOCK_NB)) return $fp;
            if ($waitSeconds <= 0) break;
            usleep(200000);
        } while (time() < $until);
        fclose($fp);
        return null;
    }

    /** Release the quote pass's lock. */
    public static function passUnlock($fp): void
    {
        if (is_resource($fp)) { @flock($fp, LOCK_UN); @fclose($fp); }
    }

    // ── what the tool, the checks and the ledger read ──────────────────────────────────────────────────────────

    /**
     * The chases due today by handset: open quote follow-ups whose due_at falls by the window's close today, keyed by the
     * handset their channel sends through (§H.2.5.4's quote demand; what `on chase` prints). Reads only.
     *
     * @return array<string,int>
     */
    public static function demand(\PDO $pdo, array $config, ?string $dataDir, int $now, $evo = null): array
    {
        if (!(new \FollowUpService($pdo))->hasKind()) return [];
        [, $to] = WaSendBudget::window($config);
        $close = (new \DateTime(WaSendBudget::localDay($now) . ' ' . sprintf('%02d', $to) . ':00:00', new \DateTimeZone(self::TZ)))->getTimestamp();
        $st = $pdo->prepare("SELECT channel, COUNT(*) AS n FROM followups WHERE closed_at IS NULL AND kind = 'quote'
                              AND due_at IS NOT NULL AND due_at <= ? GROUP BY channel");
        $st->execute([UtcClock::stamp($close)]);
        if ($evo === null) $evo = self::evo($config, $dataDir, $pdo);
        $out = [];
        foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $h = WaSendBudget::handset((string)$evo->instanceFor((string)$r['channel']));
            if ($h === '') continue;
            $out[$h] = ($out[$h] ?? 0) + (int)$r['n'];
        }
        ksort($out);
        return $out;
    }

    /**
     * Row 6's off-hook, its rollback form only (ruling R13; §H.2.16 item 2), on the tool's $rw and nothing else: every
     * open quote follow-up closed `rollback` and its pending or approved drafts expired. 5.18.103's followup_run has no
     * kind filter, so it would otherwise draft AI messages about quotations: it throws unless 0 remain. A plain
     * `off chase` (why = switch) runs no hook.
     *
     * @return array<string,int>
     */
    public static function offHook(\PDO $rw, array $config, string $dataDir, string $step, string $why): array
    {
        if ($step !== 'chase' || $why !== 'rollback') return [];
        $svc = new \FollowUpService($rw);
        if (!$svc->hasKind()) return ['closed' => 0, 'expired' => 0, 'remaining' => 0];
        $out = ['closed' => 0, 'expired' => 0, 'remaining' => 0];
        $ids = $rw->query("SELECT id FROM followups WHERE kind = 'quote' AND closed_at IS NULL ORDER BY id")->fetchAll(\PDO::FETCH_COLUMN);
        foreach ($ids as $id) {
            $st = $rw->prepare("SELECT id FROM followup_drafts WHERE followup_id = ? AND status IN ('pending','approved')");
            $st->execute([(int)$id]);
            foreach ($st->fetchAll(\PDO::FETCH_COLUMN) as $did) {
                if ($svc->expireDraft((int)$did, 'rollback')) $out['expired']++;
            }
            if ($svc->close((int)$id, 'rollback', 'rolled back', 'sales_followup')) $out['closed']++;
        }
        $out['remaining'] = (int)$rw->query("SELECT COUNT(*) FROM followups WHERE kind = 'quote' AND closed_at IS NULL")->fetchColumn();
        if ($out['remaining'] !== 0) throw new \RuntimeException('quote_rows_remain');
        return $out;
    }

    // ── the offers hold (§H.2.10.10; ruling R3) ────────────────────────────────────────────────────────────────

    /**
     * International digits ⇒ the reason an offer must not go to that phone at $nowUtc. Read-only. The reasons, in
     * order: quote_open, quote_followup_open, quote_sent_48h, quote_text_48h. An unreadable $nowUtc maps every phone of
     * every conversation outside category staff to 'unreadable_time'. A read failure throws, never "no hold".
     *
     * @return array<string,string>
     */
    public static function offerHolds(\PDO $pdo, string $nowUtc): array
    {
        $m = self::moment($nowUtc);
        if ($m === null) {
            $out = [];
            foreach ($pdo->query("SELECT DISTINCT phone FROM wa_conversations WHERE COALESCE(category, '') <> 'staff'")->fetchAll(\PDO::FETCH_COLUMN) as $p) {
                if ((string)$p !== '') $out[(string)$p] = 'unreadable_time';
            }
            return $out;
        }
        $found = self::holdSets($pdo, $m, null);
        $out = [];
        foreach (['quote_open', 'quote_followup_open', 'quote_sent_48h', 'quote_text_48h'] as $why) {
            foreach ($found[$why] as $p) if ($p !== '' && !isset($out[$p])) $out[$p] = $why;
        }
        ksort($out);
        return $out;
    }

    /** The same for one phone: the reason, or '' when there is none. */
    public static function offerHold(\PDO $pdo, string $phone, string $nowUtc): string
    {
        $m = self::moment($nowUtc);
        if ($m === null) return 'unreadable_time';
        $p = trim($phone);
        if ($p === '' || $p === 'erased') return '';
        $found = self::holdSets($pdo, $m, $p);
        foreach (['quote_open', 'quote_followup_open', 'quote_sent_48h', 'quote_text_48h'] as $why) {
            if (in_array($p, $found[$why], true)) return $why;
        }
        return '';
    }

    /**
     * The four clauses at the moment $m (storage form), each bounded on both sides by its source's storage time (ruling
     * R18): a tracker row by created_at and sent_at at or before $m and open then (status open, or status_at after $m);
     * a quote follow-up opened at or before $m and not closed by then; a quote claim within the 48 h before $m, unless
     * settled not_sent at or before $m; the quotation's own outbound row stored at or before $m and sent within the 48 h
     * before it. Matched on the conversation's phone exactly, never the last nine digits.
     *
     * @return array<string,string[]>
     */
    private static function holdSets(\PDO $pdo, string $m, ?string $phone): array
    {
        $since = UtcClock::stamp(UtcClock::parse($m) - self::HOLD_S);
        $one = $phone === null ? '' : ' AND %s = :phone';
        $a = [':m' => $m] + ($phone === null ? [] : [':phone' => $phone]);
        $q = function (string $sql, array $args) use ($pdo): array {
            $st = $pdo->prepare($sql);
            $st->execute($args);
            return array_map('strval', $st->fetchAll(\PDO::FETCH_COLUMN));
        };
        $staffOut = "COALESCE(c.category, '') <> 'staff'";
        $out = [];
        $out['quote_open'] = $q("SELECT DISTINCT t.phone FROM quote_tracker t
                                  WHERE t.created_at <= :m AND t.sent_at <= :m
                                    AND (t.status = 'open' OR (t.status_at IS NOT NULL AND t.status_at > :m))" . sprintf($one, 't.phone'), $a);
        $out['quote_followup_open'] = $q("SELECT DISTINCT c.phone FROM followups f JOIN wa_conversations c ON c.id = f.conversation_id
                                           WHERE f.kind = 'quote' AND {$staffOut} AND f.opened_at <= :m
                                             AND (f.closed_at IS NULL OR f.closed_at > :m)" . sprintf($one, 'c.phone'), $a);
        $out['quote_sent_48h'] = $q("SELECT DISTINCT w.phone FROM wa_auto_sends w
                                      WHERE w.kind = 'quote' AND w.phone IS NOT NULL AND w.claimed_at <= :m AND w.claimed_at > :since
                                        AND NOT (w.outcome = 'not_sent' AND w.settled_at IS NOT NULL AND w.settled_at <= :m)"
                                    . sprintf($one, 'w.phone'), $a + [':since' => $since]);
        $ev = [];
        foreach (QuoteSendEvidence::EVENTS as $i => $e) { $ev[] = ':e' . $i; $a[':e' . $i] = $e; }
        $st = $pdo->prepare("SELECT c.phone, m.direction, m.event_key, m.body FROM wa_messages m JOIN wa_conversations c ON c.id = m.conversation_id
                              WHERE m.direction = 'out' AND m.event_key IN (" . implode(',', $ev) . ") AND {$staffOut}
                                AND m.created_at <= :m AND m.sent_at <= :m AND m.sent_at > :since" . sprintf($one, 'c.phone'));
        $st->execute($a + [':since' => $since]);
        $out['quote_text_48h'] = [];
        foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            if (QuoteSendEvidence::read($r) !== null) $out['quote_text_48h'][(string)$r['phone']] = (string)$r['phone'];
        }
        $out['quote_text_48h'] = array_values($out['quote_text_48h']);
        return $out;
    }

    /** $nowUtc in the storage form, normalised as HumanTakeover normalises (§H.2.7.1); null when it cannot be read. */
    private static function moment(string $nowUtc): ?string
    {
        $t = UtcClock::parse($nowUtc);
        return $t === 0 ? null : UtcClock::stamp($t);
    }

    // ── reads ──────────────────────────────────────────────────────────────────────────────────────────────────

    private static function trackerOf(\PDO $pdo, string $quoteKey): ?array
    {
        if ($quoteKey === '') return null;
        $st = $pdo->prepare('SELECT * FROM quote_tracker WHERE quote_key = ?');
        $st->execute([$quoteKey]);
        $r = $st->fetch(\PDO::FETCH_ASSOC);
        return is_array($r) ? $r : null;
    }

    private static function conversation(\PDO $pdo, int $id): ?array
    {
        if ($id <= 0) return null;
        $st = $pdo->prepare('SELECT * FROM wa_conversations WHERE id = ?');
        $st->execute([$id]);
        $r = $st->fetch(\PDO::FETCH_ASSOC);
        return is_array($r) ? $r : null;
    }

    /** The install's Evolution service (the registry's channels when it is on), for channel → instance. */
    private static function evo(array $config, ?string $dataDir, \PDO $pdo)
    {
        if (!class_exists('EvolutionApiService')) require_once __DIR__ . '/EvolutionApiService.php';
        return \EvolutionApiService::forStore($config, $pdo, $dataDir);
    }

    private static function truthy($v): bool
    {
        if (is_bool($v)) return $v;
        if (is_int($v)) return $v === 1;
        return in_array(strtolower(trim((string)$v)), ['1', 'true', 'on', 'yes'], true);
    }
}
