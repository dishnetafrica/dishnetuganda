<?php
declare(strict_types=1);

require_once __DIR__ . '/StaffJobsGate.php';
require_once __DIR__ . '/UtcClock.php';
require_once __DIR__ . '/VoiceAckLimits.php';
require_once __DIR__ . '/MediaPolicy.php';
require_once __DIR__ . '/WaSendBudget.php';
require_once __DIR__ . '/HumanTakeover.php';
require_once __DIR__ . '/PluginConfig.php';
require_once __DIR__ . '/ContactOptOut.php';
require_once __DIR__ . '/QuoteChaseText.php';

/**
 * VoiceAck — P5, the instant acknowledgement of a customer's voice note (5.18.104, Uganda, root docs/73 §H.3.15–§H.3.17;
 * rulings R2, R4, R18, R19).
 *
 * The webhook queues one `ai.voice_ack` event per voice note (step 8c); the reply worker hands it here
 * (AiReplyWorker::handleVoiceAck). acknowledge() decides in §H.3.15's fifteen steps, in order, and is the ONLY method in
 * this file that sends (step 15, `evo->sendText`, class `reply`; Part F's HK7 names it). Every refusal writes one
 * `skipped` decision with a fixed reason (INSERT OR IGNORE on the note's id); nothing escalates on a refusal and P5 never
 * parks. A send claims the shared ledger first (kind `voice_ack`, ref `ack:<wa_message_id>`), on a ledger built from the
 * configuration read again from the files immediately before the claim, never the worker's start-time configuration
 * (B13-03, ruling R19), and maps every answer by code (§H.3.15's table; ruling R2). After a send that went, or may have
 * gone, a person is told at once through lib/Handover.php (created_by `voice_ack`), with no holding line.
 *
 * The wording is root docs/72 §H.1's, byte for byte (A and B, alternating per handset). On a salesperson's own number
 * the persona sentence is prefixed, and held until `sf_persona_confirmed` holds a date not after today (Q1 unanswered:
 * held); an owner with no usable first name is given A or B unprefixed once it holds (Part F's one rule).
 *
 * Inert unless enabled(): Uganda (StaffJobsGate, with the data directory), the assistant on, `wa_voice_ack_enabled`.
 * Logs carry counts and fixed codes only — never a phone, a name or the text. PHP 7.4.
 */
final class VoiceAck
{
    /** The literal zone the persona date is judged in (R-16). */
    public const TZ = 'Africa/Kampala';

    /** root docs/72 §H.1 (D9), byte for byte: ASCII apostrophe, em dash U+2014. */
    const TEXT = [
        'A' => "Thanks for your voice note. We can't listen to voice notes automatically yet \u{2014} could you type your question in a short message? A colleague will also listen and reply.",
        'B' => 'We got your voice note, thank you. To help you faster, please type your question here. A member of our team will also listen to it.',
    ];

    /** A voice note older than this when it comes up is not acknowledged (§H.3.15 step 6), measured from received_at. */
    const STALE_S = 600;
    /** C7 looks back this far before the note (ruling R4: received_at − 30 min). */
    const C7_BACK_S = 1800;
    /** The defer while the note's own claim is in flight (§H.3.15 step 13). */
    const IN_FLIGHT_DEFER_S = 15;
    /** The staff alert's reason (§H.3.15 step 16). */
    const ALERT_REASON = 'a voice note is waiting: the customer was asked to type; listen to it in WhatsApp';
    /** Who queued the hand-over event, and the stored row's source. */
    const SOURCE = 'voice_ack';

    /** The ledger's answers that end in a skip, and the decision's reason for each (§H.3.15 step 13; ruling R2). */
    const LEDGER_SKIP = [
        'ack_ceiling'  => 'number_cap',
        'window'       => 'quiet_hours',
        'zone'         => 'ledger_zone',
        'ledger_off'   => 'ledger_off',
        'ledger_error' => 'ledger_error',
        'no_instance'  => 'no_instance',
    ];
    /** The ledger's answers that defer while inside STALE_S of received_at. */
    const LEDGER_DEFER = ['in_flight', 'spacing', 'handset_busy'];

    /** @var \PDO */ private $pdo;
    /** @var EventBus */ private $bus;
    /** @var mixed */ private $store;
    /** @var array */ private $config;
    /** @var string */ private $dataDir;
    /** @var EvolutionApiService */ private $evo;
    /** @var ConversationService */ private $convSvc;
    /** @var callable */ private $log;
    /** @var callable */ private $echo;
    /** @var callable */ private $now;
    /** @var callable|null TEST SEAM: the clock every acknowledgement reads; null in production (time()). */
    private static $testClock = null;

    /**
     * @param callable $log  function (string $level, string $message): void — the worker's logger
     * @param callable $echo function (array $send, string $channel, string $source) — the worker's claimOwnEcho
     */
    public function __construct(\PDO $pdo, $bus, $store, array $config, string $dataDir, $evo, $convSvc,
                                callable $log, callable $echo)
    {
        $this->pdo     = $pdo;
        $this->bus     = $bus;
        $this->store   = $store;
        $this->config  = $config;
        $this->dataDir = $dataDir;
        $this->evo     = $evo;
        $this->convSvc = $convSvc;
        $this->log     = $log;
        $this->echo    = $echo;
        $this->now     = self::$testClock ?? function (): int { return time(); };
    }

    /** TEST SEAM: the clock every step reads (the ledger is given the same one); null restores time(). */
    public static function useClock(?callable $now): void
    {
        self::$testClock = $now;
    }

    /** P5 on: Uganda (StaffJobsGate, with the data directory), the assistant on, wa_voice_ack_enabled (§H.3.5). */
    public static function enabled(array $config, ?string $dataDir): bool
    {
        if (!self::flag($config['ai_enabled'] ?? null) || !self::flag($config['wa_voice_ack_enabled'] ?? null)) return false;
        return StaffJobsGate::applies($config, $dataDir);
    }

    /** sf_persona_confirmed holds a real date not after today, Kampala (ruling R5; §H.3.15). */
    public static function personaConfirmed(array $config, int $now): bool
    {
        if (!QuoteChaseText::personaConfirmed($config)) return false;
        $s = trim((string)($config['sf_persona_confirmed'] ?? ''));
        [$y, $m, $d] = array_map('intval', explode('-', $s));
        if (!checkdate($m, $d, $y)) return false;
        $today = (new \DateTime('@' . $now))->setTimezone(new \DateTimeZone(self::TZ))->format('Y-m-d');
        return strcmp($s, $today) <= 0;
    }

    /**
     * The decision, §H.3.15's steps 1–16 in order. Returns nothing; a defer throws WorkerDefer (no decision row), and a
     * failure before the send throws (the queue retries; nothing was sent).
     */
    public function acknowledge(array $event): void
    {
        $p       = is_array($event['_payload'] ?? null) ? $event['_payload'] : [];
        $convId  = (int)($event['entity_id'] ?? 0);
        $channel = trim((string)($p['channel'] ?? ''));
        $phone   = trim((string)($p['customer_phone'] ?? ''));
        $waId    = trim((string)($p['wa_message_id'] ?? ''));
        $inst    = trim((string)($p['whatsapp_instance'] ?? ''));
        $recv    = UtcClock::parse((string)($p['received_at'] ?? ''));

        // 1. A payload field is missing: drop, no row.
        if ($convId <= 0 || $channel === '' || $phone === '' || $waId === '' || $recv <= 0) {
            $this->say('warn', "conv {$convId}: voice acknowledgement event missing required fields — dropped");
            return;
        }
        $now     = (int)($this->now)();
        $handset = $this->handsetOf($channel);
        $d = ['wa' => $waId, 'conv' => $convId, 'channel' => $channel, 'handset' => $handset, 'now' => $now];

        // 2. P5's switch (tested again at step 13 on a fresh read).
        if (!self::enabled($this->config, $this->dataDirOrNull())) { $this->skip($d, 'switched_off'); return; }
        // 3. R4 answers this number: P5 is silent there.
        if (MediaPolicy::voiceMode($this->config, $this->dataDirOrNull(), $channel) === 'reply') { $this->skip($d, 'p4_answers'); return; }
        // 4. A colleague's number; one of DishNet's own (as AiReplyWorker::handle :181-197).
        if ($this->isStaffNumber($phone)) { $this->skip($d, 'staff_number'); return; }
        $reg = $this->evo->registryOn();
        if ($reg && $this->evo->automationPolicy()->senderClass($channel, $phone) !== '') { $this->skip($d, 'dishnet_number'); return; }
        // 5. The registry's route and the automated-send policy (registry_unreadable included; never retried).
        if ($reg) {
            $route = $this->evo->replyRoute($channel, $inst);
            if (empty($route['ok'])) { $this->skip($d, 'route:' . self::code((string)($route['reason'] ?? ''))); return; }
            $why = (string)$this->evo->automationPolicy()->refusal($channel, $phone);
            if ($why !== '') { $this->skip($d, 'policy:' . self::code($why)); return; }
        }
        // 6. Stale: more than 600 s since received_at.
        if ($now - $recv > self::STALE_S) { $this->skip($d, 'stale'); return; }
        // 7. The limits (the one holder, ruling R4), and the window, every day.
        $lim = VoiceAckLimits::of($this->config);
        if ($lim === null) { $this->skip($d, 'limits_invalid'); return; }
        if (!VoiceAckLimits::inWindow($lim, $now)) { $this->skip($d, 'quiet_hours'); return; }
        // 8. A colleague's pause is running (as the AI would park).
        if ($this->humanPauseRemaining($convId, $now) > 0) { $this->skip($d, 'human_active'); return; }
        // 9. C7, Part F's signature: since = received_at − 30 min (ruling R4); an unreadable stamp answers a code.
        if (HumanTakeover::since($this->pdo, $phone, [$convId], UtcClock::stamp($recv - self::C7_BACK_S)) !== null) {
            $this->skip($d, 'person_active'); return;
        }
        // 10. A hand-over holding line stored after the note.
        if ($this->handedOverSince($convId, $recv)) { $this->skip($d, 'handed_over'); return; }
        // 11. Once per period_hours per conversation (a sent or uncertain acknowledgement).
        $period = (int)$lim['period_hours'];
        if ($this->ackedWithin($convId, $now - $period * 3600)) { $this->skip($d, 'already_acked'); return; }
        // 12. Persona: an owner line waits for sf_persona_confirmed; an unnamed owner then gets the department wording.
        $persona = $this->persona($channel);
        if ($persona !== null && !self::personaConfirmed($this->config, $now)) { $this->skip($d, 'persona_unconfirmed'); return; }
        $variant = $this->nextVariant($handset);
        $text = ($persona !== null && $persona !== '')
              ? strtr(QuoteChaseText::PERSONA_SENTENCE, ['{first name}' => $persona]) . ' ' . self::TEXT[$variant]
              : self::TEXT[$variant];
        $personaFlag = ($persona !== null && $persona !== '') ? 1 : 0;

        // 13. The ledger claim, on a fresh read (B13-03, ruling R19).
        try {
            $fresh = PluginConfig::read(dirname(__DIR__), $this->dataDir);
        } catch (\Throwable $e) {
            $this->skip($d, 'switched_off'); return;
        }
        if (!self::enabled($fresh, $this->dataDirOrNull())) { $this->skip($d, 'switched_off'); return; }
        if (MediaPolicy::voiceMode($fresh, $this->dataDirOrNull(), $channel) === 'reply') { $this->skip($d, 'p4_answers'); return; }
        $freshLim = VoiceAckLimits::of($fresh);
        $gap = $freshLim !== null ? (int)$freshLim['gap_s'] : 0;
        $instance = (string)$this->evo->instanceFor($channel);
        $ledger = WaSendBudget::forInstall($fresh, $this->dataDirOrNull(), $this->store, null, $this->now);
        $ref = 'ack:' . $waId;
        $c = $ledger->claim($instance, 'voice_ack', $ref);
        if (empty($c['ok'])) {
            $code = (string)($c['defer'] ?? '');
            if ($code === 'already_sent') return;                                 // the first decision stands
            if (in_array($code, self::LEDGER_DEFER, true)) {
                $t = (int)($this->now)();
                if ($t - $recv > self::STALE_S) { $this->skip($d, 'stale'); return; }
                $secs = $code === 'in_flight' ? self::IN_FLIGHT_DEFER_S
                      : max(5, min((int)($c['retry_at'] ?? ($t + 5)) - $t, 60));
                throw new WorkerDefer($secs, 'voice_ack: ' . ($code === 'in_flight' ? 'claim in flight' : $code));
            }
            if (isset(self::LEDGER_SKIP[$code])) { $this->skip($d, self::LEDGER_SKIP[$code]); return; }
            $this->say('warn', "conv {$convId}: voice acknowledgement — the ledger answered " . self::code($code) . '; nothing sent');
            $this->skip($d, 'ledger_unknown');                                    // any answer not in the table sends nothing (A25)
            return;
        }
        $claim = (int)$c['claim'];

        // 14. The claimed decision, with the period and the gap it applied (ruling R18).
        $ins = $this->pdo->prepare("INSERT OR IGNORE INTO wa_voice_acks (wa_message_id, conversation_id, channel, handset, outcome,
                                        reason, variant, persona, ledger_ref, period_hours, gap_s, local_day, created_at, updated_at)
                                    VALUES (?, ?, ?, ?, 'claimed', NULL, ?, ?, ?, ?, ?, ?, ?, ?)");
        $ins->execute([$waId, $convId, $channel, $handset, $variant, $personaFlag, $ref, $period, $gap,
                       WaSendBudget::localDay($now), UtcClock::stamp($now), UtcClock::stamp($now)]);
        if ($ins->rowCount() !== 1) {
            $ledger->settle($claim, 'failed', ['maybe_sent' => false]);
            return;
        }
        $decision = (int)$this->pdo->lastInsertId();

        // 15. The send, and everything after it in try/catch: never throws.
        $outcome = 'failed';
        try {
            try {
                $send = $this->evo->sendText($channel, $phone, $text, ContactOptOut::CLASS_REPLY);
            } catch (\Throwable $e) {
                $send = ['ok' => false, 'error' => EvolutionApiService::MAYBE_SENT . get_class($e)];   // a throw: never re-sent
            }
            $sentId = (string)($send['data']['key']['id'] ?? '');
            if (EvolutionApiService::policyRefused($send)) {
                $ledger->settle($claim, 'failed', ['maybe_sent' => false]);
                $this->settleDecision($decision, 'skipped', 'policy_at_send');
                $outcome = 'skipped:policy_at_send';
            } elseif (!empty($send['suppressed'])) {
                $ledger->settle($claim, 'failed', ['maybe_sent' => false]);
                $this->settleDecision($decision, 'skipped', 'opted_out');
                $outcome = 'skipped:opted_out';
            } elseif (empty($send['ok'])) {
                if (EvolutionApiService::mayHaveBeenSent($send)) {
                    $ledger->settle($claim, 'failed', ['maybe_sent' => true, 'wa_message_id' => $sentId]);
                    $this->settleDecision($decision, 'uncertain', null);
                    $outcome = 'uncertain';
                } else {
                    $ledger->settle($claim, 'failed', ['maybe_sent' => false]);
                    $this->settleDecision($decision, 'failed', null);
                    $outcome = 'failed';
                }
            } else {
                // R-05: the echo claimed first, then the settle with the id, then the store, then the decision.
                ($this->echo)($send, $channel, 'ai.voice_ack');
                $ledger->settle($claim, 'sent', ['wa_message_id' => $sentId]);
                $outcome = 'sent';
                $this->convSvc->storeMessage($convId, [
                    'direction'     => 'out',
                    'role'          => 'assistant',
                    'body'          => $text,
                    'agent_name'    => 'DishNet AI',
                    'wa_message_id' => $sentId !== '' ? $sentId : null,
                    'metadata'      => json_encode(['channel' => $channel, 'voice_ack' => $variant, 'source' => self::SOURCE]),
                ]);
                $this->settleDecision($decision, 'sent', null, true);
            }
        } catch (\Throwable $e) {
            $this->say('error', "conv {$convId}: voice acknowledgement bookkeeping failed (" . get_class($e) . ')');
        }
        $this->say('info', "conv {$convId}: voice acknowledgement {$outcome} (variant {$variant}" . ($personaFlag ? ', persona' : '') . ')');

        // 16. Sent or uncertain: a person is told at once; no holding line.
        if ($outcome === 'sent' || $outcome === 'uncertain') {
            try {
                if (!class_exists('Handover')) require_once __DIR__ . '/Handover.php';
                Handover::escalate($this->pdo, $this->bus, $this->store, $this->config, $this->evo, $this->convSvc,
                    $convId, $channel, $phone, self::ALERT_REASON,
                    true, $this->log, self::SOURCE, $this->owner($channel));
            } catch (\Throwable $e) {
                $this->say('error', "conv {$convId}: voice acknowledgement hand-over failed (" . get_class($e) . ')');
            }
        }
    }

    // ── the steps' reads ────────────────────────────────────────────────────────────────────────────────────────

    /** Write the skipped decision (INSERT OR IGNORE: the first decision for the note stands). */
    private function skip(array $d, string $reason): void
    {
        $this->pdo->prepare("INSERT OR IGNORE INTO wa_voice_acks (wa_message_id, conversation_id, channel, handset, outcome, reason,
                                 local_day, created_at, updated_at)
                             VALUES (?, ?, ?, ?, 'skipped', ?, ?, ?, ?)")
                  ->execute([$d['wa'], $d['conv'], $d['channel'], $d['handset'], $reason, WaSendBudget::localDay($d['now']),
                             UtcClock::stamp($d['now']), UtcClock::stamp($d['now'])]);
        $this->say('info', "conv {$d['conv']}: voice acknowledgement skipped — {$reason}");
    }

    private function settleDecision(int $id, string $outcome, ?string $reason, bool $sent = false): void
    {
        $t = UtcClock::stamp((int)($this->now)());
        $this->pdo->prepare("UPDATE wa_voice_acks SET outcome = ?, reason = ?, sent_at = ?, updated_at = ? WHERE id = ? AND outcome = 'claimed'")
                  ->execute([$outcome, $reason, $sent ? $t : null, $t, $id]);
    }

    private function handsetOf(string $channel): string
    {
        try {
            return WaSendBudget::handset((string)$this->evo->instanceFor($channel));
        } catch (\Throwable $e) {
            return '';
        }
    }

    /** J8, as AiReplyWorker::isStaffNumber: the whole number of an ACTIVE DishNet staff account, on Uganda. */
    private function isStaffNumber(string $phone): bool
    {
        try {
            if (!StaffJobsGate::applies($this->config, $this->dataDirOrNull())) return false;
            if (!class_exists('StaffDirectory')) require_once __DIR__ . '/StaffDirectory.php';
            if (!class_exists('TenantProfile')) require_once __DIR__ . '/TenantProfile.php';
            return \StaffDirectory::activeStaffByPhone($this->store->load('retailers.json') ?? [], $phone,
                       \TenantProfile::current($this->config, $this->dataDirOrNull())) !== null;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** Seconds left on a colleague's pause, as AiReplyWorker::humanPauseRemaining (:1345-1365); 0 when none. */
    private function humanPauseRemaining(int $convId, int $now): int
    {
        try {
            $st = $this->pdo->prepare('SELECT state, last_human_reply_at FROM wa_conversations WHERE id = ?');
            $st->execute([$convId]);
            $row = $st->fetch(\PDO::FETCH_ASSOC);
            if (!$row || ($row['state'] ?? '') !== 'human_active') return 0;
            $mins = $this->config['wa_human_cooldown_minutes'] ?? null;
            $mins = ($mins === null || $mins === '' || !is_numeric($mins)) ? 1440 : max(0, (int)$mins);
            if ($mins === 0) return 0;
            $last = UtcClock::parse($row['last_human_reply_at'] ?? '');
            if ($last <= 0) return 0;
            $left = $last + $mins * 60 - $now;
            return $left > 0 ? $left : 0;
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /** A hand-over holding line (metadata handover:true) stored after the note. */
    private function handedOverSince(int $convId, int $recv): bool
    {
        $st = $this->pdo->prepare("SELECT 1 FROM wa_messages WHERE conversation_id = ? AND direction = 'out'
                                     AND " . self::metaHas('metadata', '"handover":true') . " AND created_at > ? LIMIT 1");
        $st->execute([$convId, UtcClock::stamp($recv)]);
        return $st->fetchColumn() !== false;
    }

    /** A sent or uncertain acknowledgement of this conversation stored after $sinceT. */
    private function ackedWithin(int $convId, int $sinceT): bool
    {
        $st = $this->pdo->prepare("SELECT 1 FROM wa_voice_acks WHERE conversation_id = ? AND outcome IN ('sent','uncertain')
                                     AND created_at > ? LIMIT 1");
        $st->execute([$convId, UtcClock::stamp($sinceT)]);
        return $st->fetchColumn() !== false;
    }

    /** The variant not used last by a sent or uncertain acknowledgement on this handset. */
    private function nextVariant(string $handset): string
    {
        $st = $this->pdo->prepare("SELECT variant FROM wa_voice_acks WHERE handset = ? AND outcome IN ('sent','uncertain')
                                     AND variant IS NOT NULL ORDER BY id DESC LIMIT 1");
        $st->execute([$handset]);
        return ((string)$st->fetchColumn()) === 'A' ? 'B' : 'A';
    }

    /**
     * The persona of a channel, Part F's one rule (QuoteChase::persona): the owner's first name on a salesperson's own
     * number (a resolved LineOwner); '' on such a number with no usable first name; null on a department or partner line,
     * and always with the registry off.
     */
    private function persona(string $channel): ?string
    {
        try {
            if (!$this->evo->registryOn()) return null;
            $cx = $this->evo->channelContext($channel);
            if ($cx === null) return null;
            if (!class_exists('LineOwner')) require_once __DIR__ . '/LineOwner.php';
            $lo = \LineOwner::of($cx, $this->store, $this->config, $this->dataDirOrNull());
            if ($lo !== null) return $lo->firstName();
            return $cx->ownerType() === 'staff' ? '' : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** The salesperson the alert also goes to (as MediaWorker::handover, §H.3.6 change 7), or null. */
    private function owner(string $channel): ?\LineOwner
    {
        try {
            if (!$this->evo->registryOn()) return null;
            $cx = $this->evo->channelContext($channel);
            if ($cx === null || $cx->handoverTo() !== 'owner') return null;
            if (!class_exists('LineOwner')) require_once __DIR__ . '/LineOwner.php';
            return \LineOwner::of($cx, $this->store, $this->config, $this->dataDirOrNull());
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function dataDirOrNull(): ?string
    {
        return $this->dataDir !== '' ? $this->dataDir : null;
    }

    private function say(string $level, string $message): void
    {
        try { ($this->log)($level, $message); } catch (\Throwable $e) { /* a log line is never worth a send */ }
    }

    /**
     * SQL: the metadata column holds this JSON fragment, however it was stored — storeMessage json_encodes what it is
     * given, so a caller's encoded string (the hand-over line's, this file's own row) is stored as a JSON string.
     */
    public static function metaHas(string $col, string $fragment): string
    {
        $q = function (string $s): string { return "'%" . str_replace("'", "''", $s) . "%'"; };
        return "({$col} LIKE " . $q($fragment) . " OR {$col} LIKE " . $q(str_replace('"', '\\"', $fragment)) . ')';
    }

    /** A reason or code as a fixed token: letters, digits, '_' and '-' only. */
    private static function code(string $s): string
    {
        $c = (string)preg_replace('/[^A-Za-z0-9_-]/', '', $s);
        return $c === '' ? 'unknown' : substr($c, 0, 40);
    }

    private static function flag($v): bool
    {
        if ($v === null || $v === '') return false;
        if (is_bool($v)) return $v;
        return in_array(strtolower(trim((string)$v)), ['1', 'true', 'on', 'yes'], true);
    }
}
