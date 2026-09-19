<?php
declare(strict_types=1);
require_once __DIR__ . '/timezone.php';

require_once __DIR__ . '/ContactOptOut.php';
require_once __DIR__ . '/EmailReplyPolicy.php';

/**
 * FollowUpPolicy — may we follow this customer up, and what may we say?
 *
 * Pure decisions. Nothing here opens a connection, writes a row or calls a
 * model, so every rule can be tested against a plain array. That is the point:
 * these gates run BEFORE the AI is consulted, so a brain that is broken, slow
 * or expensive can never be the reason a customer is messaged wrongly.
 *
 * ── THE WORST FAILURE THIS SYSTEM CAN HAVE ──────────────────────────────
 *
 * Not a missed follow-up. Sending one customer's private information to
 * somebody else.
 *
 * A WhatsApp number matching a uCRM customer on its last nine digits is
 * evidence, not proof. It is good enough to answer whoever just wrote to us —
 * the person reading the reply is the person who sent the message. It is not
 * good enough to START a conversation, because then WE choose the recipient
 * and a wrong guess puts an account balance in a stranger's hand.
 *
 * So provenance decides content:
 *
 *   verified / manual / ai_identified   account facts are allowed
 *   phone_tail / bulk_rematch / none    the enquiry only — no account facts
 *   ambiguous                           no proactive contact at all
 */
final class FollowUpPolicy
{
    /** What a proactive message may contain. */
    public const CONTENT_ACCOUNT = 'account';   // invoices, service, kit, balance
    public const CONTENT_ENQUIRY = 'enquiry';   // only what they asked us about
    public const CONTENT_NONE    = 'none';      // nothing may be sent

    /** Link methods we trust enough to discuss somebody's account. */
    public const TRUSTED_LINKS = ['verified', 'manual', 'ai_identified'];

    /** The zone the 08:00–20:00 courtesy window is measured in.
     *
     *  Uganda and South Sudan are NOT the same offset, whatever the old
     *  comment here claimed: South Sudan left EAT on 31 January 2021 and
     *  Africa/Juba has been CAT (UTC+2) ever since, while Africa/Kampala
     *  is EAT (UTC+3). The window follows the install via dn_tz(); this
     *  constant is the Uganda value it resolves to there, kept because the
     *  design document names it. */
    public const TZ = 'Africa/Kampala';

    public const HOUR_OPEN  = 8;    // 08:00 — not before
    public const HOUR_CLOSE = 20;   // 20:00 — not after

    /** Hours after the customer's last message before attempt N may be asked. */
    public const SCHEDULE = [1 => 24, 2 => 72];

    /**
     * May this drafted follow-up go out without a person reading it?
     *
     * WhatsApp only, and deliberately so. Email keeps its own policy in
     * EmailReplyPolicy, which splits categories by what a wrong answer costs
     * and holds thirteen of them back unconditionally. A WhatsApp follow-up is
     * a different thing: one-to-one, short, to somebody who wrote to us first
     * about something they asked, and it can say nothing the enquiry did not
     * already put on the table.
     *
     * Four conditions, all required. The operator switch is last in intent and
     * first in code, because an unset key must mean today's behaviour on every
     * install that upgrades into this.
     *
     *   1. followup_auto_send is on           — absent means off, always
     *   2. the assistant said SEND            — WAIT, DO_NOT_SEND and
     *                                           ESCALATE_TO_HUMAN never auto-send
     *   3. there is a message                 — an empty body is a bug, not a send
     *   4. the content level is ENQUIRY       — see below
     *
     * ── WHY ACCOUNT CONTENT STILL NEEDS A PERSON ────────────────────────
     *
     * CONTENT_ACCOUNT means provenance is good enough to discuss the
     * customer's balance, invoices and service. That is the right bar for
     * ANSWERING somebody. It is not the right bar for a message we chose to
     * send, unread, about their money. If the identity is wrong, an enquiry
     * follow-up is a wasted message and an account follow-up is somebody
     * else's balance on a stranger's phone. Those are not the same mistake,
     * so they do not get the same gate.
     *
     * Escalation words are not re-checked here: gate 6 already CLOSES a
     * follow-up whose thread mentions one, so no draft can exist for it. The
     * BODY is scanned though — the assistant writing about a refund is a
     * different event from the customer mentioning one.
     *
     * @return array{auto:bool, reason:string}
     */
    public static function mayAutoSend(array $verdict, string $level, array $config,
                                      array $conv = []): array
    {
        $no = static fn(string $why): array => ['auto' => false, 'reason' => $why];

        if (!filter_var($config['followup_auto_send'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            return $no('followup_auto_send is off');
        }
        $v = strtoupper(trim((string)($verdict['verdict'] ?? '')));
        if ($v !== 'SEND') {
            return $no('the assistant said ' . ($v ?: 'nothing') . ', not SEND');
        }
        $body = trim((string)($verdict['message'] ?? ''));
        if ($body === '') {
            return $no('the draft has no message');
        }
        if ($level !== self::CONTENT_ENQUIRY) {
            return $no('content level is ' . $level . ' — only enquiry follow-ups may send themselves');
        }
        $esc = EmailReplyPolicy::scanForEscalation($body);
        if (!empty($esc['escalate'])) {
            return $no('the drafted message mentions "' . $esc['matched'] . '"');
        }

        // A conversation already flagged for a colleague. The gate chain tests
        // human_active — a colleague who is ALREADY TYPING — and never tested
        // this one, which is what the assistant sets when it hands over and
        // promises that a person will answer.
        //
        // On 19 September 2026 the first automatic message this plugin ever
        // sent went to a customer who had been told "let me confirm with our
        // team and come back to you today". Nobody had. Four days later the
        // follow-up asked THEM whether THEY had any questions, which inverts
        // who owes whom an answer in front of a buyer who is still waiting.
        //
        // The draft is still written. It is exactly what the colleague wants
        // waiting when they pick the conversation up. It may not send itself.
        $state = strtolower(trim((string)($conv['state'] ?? '')));
        if ($state === 'needs_human' || $state === 'human_active') {
            return $no('the conversation is ' . $state . ' — a person owes this customer a reply');
        }

        return ['auto' => true, 'reason' => 'enquiry follow-up, assistant said SEND'];
    }

    /**
     * What a proactive message to this conversation may contain.
     *
     * @param array $conv a wa_conversations row
     */
    public static function contentLevel(array $conv): string
    {
        $method = (string)($conv['crm_link_method'] ?? '');
        $client = (int)($conv['crm_client_id'] ?? 0);

        if ($method === 'ambiguous') return self::CONTENT_NONE;

        // No customer linked at all is a PROSPECT, which is normal and fine —
        // most sales enquiries are. There is simply no account to discuss.
        if ($client <= 0) return self::CONTENT_ENQUIRY;

        return in_array($method, self::TRUSTED_LINKS, true)
            ? self::CONTENT_ACCOUNT
            : self::CONTENT_ENQUIRY;
    }

    /**
     * Is this moment inside the sending window?
     *
     * Storage is UTC everywhere in this plugin and display localises, so the
     * conversion has to happen here. Comparing a UTC hour against 08:00–20:00
     * would silently shift the window by three hours and start messaging
     * customers at five in the morning.
     *
     * @return array{ok:bool, reason:string, next:string} next = UTC 'Y-m-d H:i:s'
     */
    /** The city the window is measured in — "Kampala", not "Africa/Kampala".
     *  Taken from the configured zone so the refusal reason cannot claim a
     *  city the clock is not actually set to. */
    public static function where(): string
    {
        $z = dn_tz();
        $p = strrchr($z, '/');
        return str_replace('_', ' ', $p === false ? $z : substr($p, 1));
    }

    public static function withinSendingWindow(string $utcNow): array
    {
        try {
            $t = new \DateTimeImmutable($utcNow, new \DateTimeZone('UTC'));
        } catch (\Throwable $e) {
            return ['ok' => false, 'reason' => 'unreadable time', 'next' => ''];
        }
        $local = $t->setTimezone(dn_tz_obj());
        $h     = (int)$local->format('G');
        $dow   = (int)$local->format('w');   // 0 = Sunday

        if ($dow === 0) {
            return ['ok' => false, 'reason' => 'Sunday',
                    'next' => self::nextWindow($local)];
        }
        if ($h < self::HOUR_OPEN) {
            return ['ok' => false, 'reason' => 'before ' . self::HOUR_OPEN . ':00 ' . self::where(),
                    'next' => self::nextWindow($local)];
        }
        if ($h >= self::HOUR_CLOSE) {
            return ['ok' => false, 'reason' => 'after ' . self::HOUR_CLOSE . ':00 ' . self::where(),
                    'next' => self::nextWindow($local)];
        }
        return ['ok' => true, 'reason' => '', 'next' => ''];
    }

    /** The next moment inside the window, as UTC. */
    private static function nextWindow(\DateTimeImmutable $local): string
    {
        $c = $local;
        // Step to the next opening time, then forward over any Sunday. At most
        // eight hops: a week of days plus the same-day case.
        for ($i = 0; $i < 8; $i++) {
            $open = $c->setTime(self::HOUR_OPEN, 0, 0);
            if ($c < $open && (int)$c->format('w') !== 0) {
                return $open->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            }
            $c = $c->modify('+1 day')->setTime(0, 0, 0);
            if ((int)$c->format('w') === 0) continue;   // skip Sunday
            return $c->setTime(self::HOUR_OPEN, 0, 0)
                     ->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        }
        return $local->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    /**
     * When attempt N becomes askable, from the customer's last message.
     * Returns '' when there is no attempt N — which is how the cadence ends.
     */
    public static function dueAt(string $lastCustomerUtc, int $attemptNumber): string
    {
        $hours = self::SCHEDULE[$attemptNumber] ?? 0;
        if ($hours <= 0) return '';
        try {
            $t = new \DateTimeImmutable($lastCustomerUtc, new \DateTimeZone('UTC'));
        } catch (\Throwable $e) { return ''; }
        return $t->modify('+' . $hours . ' hours')->format('Y-m-d H:i:s');
    }

    /**
     * When attempt N becomes askable, given that attempt N-1 has just gone out.
     *
     * dueAt() anchors to the customer's last message. That is right for the
     * FIRST attempt — the wait is measured from their silence. It is wrong for
     * every attempt after a LATE send, and this engine sent nothing for five
     * days in September 2026 because it was reading the wrong provider key.
     * When it restarted, every queued row had its attempt-2 date already in the
     * past: 110 of 276 open follow-ups. Attempt 2 would have become due the
     * instant attempt 1 was sent, and the customer would have received two
     * messages minutes apart.
     *
     * So the next attempt is never sooner than the gap the schedule already
     * intends between attempts, measured from when we ACTUALLY wrote. On a
     * punctual send the customer anchor is later anyway and nothing changes —
     * the floor only bites when we were late, which is exactly when it should.
     */
    public static function nextDueAfterSend(string $lastCustomerUtc, string $sentAtUtc,
                                            int $nextAttempt): string
    {
        $byCustomer = self::dueAt($lastCustomerUtc, $nextAttempt);
        if ($byCustomer === '') return '';   // no attempt N — the cadence ends here

        $gap = (int)(self::SCHEDULE[$nextAttempt] ?? 0)
             - (int)(self::SCHEDULE[$nextAttempt - 1] ?? 0);
        if ($gap <= 0) return $byCustomer;

        try {
            $floor = (new \DateTimeImmutable($sentAtUtc, new \DateTimeZone('UTC')))
                ->modify('+' . $gap . ' hours')->format('Y-m-d H:i:s');
        } catch (\Throwable $e) {
            return $byCustomer;              // an unreadable clock must not shorten the wait
        }
        return $floor > $byCustomer ? $floor : $byCustomer;
    }

    /**
     * Every gate that runs before the AI is asked.
     *
     * Ordered cheapest and most certain first, so the expensive question is
     * only ever reached by a row that has survived all of them.
     *
     * @param array $ctx conv, followup, now (UTC), enabled, opt_out (array|null),
     *                   sent_today, daily_cap, thread_text
     * @return array{allow:bool, action:string, gate:string, reason:string, defer_until:string}
     *         action: proceed | skip | defer | close
     */
    public static function gate(array $ctx): array
    {
        $conv = (array)($ctx['conv'] ?? []);
        $fu   = (array)($ctx['followup'] ?? []);
        $now  = (string)($ctx['now'] ?? gmdate('Y-m-d H:i:s'));

        $no = static fn(string $action, string $gate, string $reason, string $until = ''): array =>
            ['allow' => false, 'action' => $action, 'gate' => $gate,
             'reason' => $reason, 'defer_until' => $until];

        // 1. The master switch. Absent config means today's behaviour.
        if (empty($ctx['enabled'])) {
            return $no('skip', 'disabled', 'follow-ups are switched off');
        }

        // 2. Opted out. The only gate that can fire on a customer who never
        //    had a follow-up at all, which is why it is this early.
        $oo = $ctx['opt_out'] ?? null;
        if (is_array($oo) && !empty($oo['blocked'])) {
            return $no('close', 'opt_out', $oo['reason'] ?: 'the customer opted out');
        }

        // 3. They are talking to us right now. A follow-up would be absurd.
        $lastCust = (string)($conv['last_customer_at'] ?? '');
        $lastAgent = (string)($conv['last_agent_at'] ?? '');
        if ($lastCust !== '' && $lastCust > $lastAgent
            && self::hoursBetween($lastCust, $now) < 1.0) {
            return $no('skip', 'active_conversation', 'the customer wrote within the hour');
        }

        // 4. A colleague has the conversation.
        if ((string)($conv['state'] ?? '') === 'human_active') {
            return $no('close', 'human_active', 'a colleague is handling this');
        }

        // 5. Attempts. (Gate 5 in the specification — one open follow-up per
        //    conversation — is the database's unique index, not a branch here.
        //    It cannot be checked in a pure function and must not be: an
        //    application-level check would lose the race it exists to stop.)
        $attempts = (int)($fu['attempts'] ?? 0);
        $max      = (int)($fu['max_attempts'] ?? 2);
        if ($attempts >= $max) {
            return $no('close', 'exhausted', "{$attempts} of {$max} attempts used");
        }

        // 6. Words that mean this is not a sales conversation any more.
        $thread = (string)($ctx['thread_text'] ?? '');
        if ($thread !== '') {
            $esc = EmailReplyPolicy::scanForEscalation($thread);
            if (!empty($esc['escalate'])) {
                return $no('close', 'escalation',
                    'the thread mentions "' . $esc['matched'] . '" — a person must handle this');
            }
        }

        // 7. We do not know who this is well enough to start a conversation.
        if (self::contentLevel($conv) === self::CONTENT_NONE) {
            return $no('skip', 'identity_ambiguous',
                'several customers share this number — we would be guessing who we are writing to');
        }

        // 8. Too early. Not a refusal, just not yet.
        $due = (string)($fu['due_at'] ?? '');
        if ($due !== '' && $due > $now) {
            return $no('defer', 'not_due', 'not due until ' . $due, $due);
        }

        // 9. Quiet hours and Sunday.
        $win = self::withinSendingWindow($now);
        if (!$win['ok']) {
            return $no('defer', 'quiet_hours', $win['reason'], $win['next']);
        }

        // 10. Daily cap per channel, so a backlog cannot become a blast.
        $cap  = (int)($ctx['daily_cap'] ?? 0);
        $sent = (int)($ctx['sent_today'] ?? 0);
        if ($cap > 0 && $sent >= $cap) {
            return $no('defer', 'daily_cap', "{$sent} of {$cap} sent on this channel today");
        }

        // 11–12 are the AI and the human. Both are downstream of here.
        return ['allow' => true, 'action' => 'proceed', 'gate' => '',
                'reason' => 'every gate passed — ask the AI', 'defer_until' => ''];
    }

    /** Whole and fractional hours between two UTC stamps. */
    public static function hoursBetween(string $fromUtc, string $toUtc): float
    {
        $a = strtotime($fromUtc . ' UTC');
        $b = strtotime($toUtc . ' UTC');
        if ($a === false || $b === false) return 0.0;
        return ($b - $a) / 3600.0;
    }

    /**
     * Is this conversation quiet enough to be worth a follow-up at all?
     *
     * The customer must have spoken LAST. If we answered and they said nothing
     * more, the ball is in their court and a nudge is reasonable. If WE spoke
     * last and they have not replied... that is the same thing, so both count.
     * What does not count is a conversation where nobody has said anything for
     * a month — that is not a live enquiry, it is history.
     */
    public static function isFollowable(array $conv, string $now, float $maxAgeHours = 336.0): array
    {
        $lastCust = (string)($conv['last_customer_at'] ?? '');
        if ($lastCust === '') {
            return ['ok' => false, 'reason' => 'the customer has never written'];
        }
        $age = self::hoursBetween($lastCust, $now);
        if ($age < (float)(self::SCHEDULE[1] ?? 24)) {
            return ['ok' => false, 'reason' => 'not quiet long enough (' . round($age, 1) . 'h)'];
        }
        if ($age > $maxAgeHours) {
            return ['ok' => false, 'reason' => 'too old to be a live enquiry ('
                                             . round($age / 24, 1) . ' days)'];
        }
        if ((string)($conv['state'] ?? '') === 'human_active') {
            return ['ok' => false, 'reason' => 'a colleague is handling this'];
        }
        if ((string)($conv['status'] ?? 'active') !== 'active') {
            return ['ok' => false, 'reason' => 'the conversation is not active'];
        }
        return ['ok' => true, 'reason' => ''];
    }
}
