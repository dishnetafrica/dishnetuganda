<?php
declare(strict_types=1);

require_once __DIR__ . '/StaffJobsGate.php';
require_once __DIR__ . '/UtcClock.php';
require_once __DIR__ . '/PluginLog.php';
require_once __DIR__ . '/VoiceAckLimits.php';
require_once __DIR__ . '/ReminderDay.php';

/**
 * WaSendBudget — the one send ledger, one budget per HANDSET (5.18.104, root docs/73 §H.2.5; contract C3; rulings R2,
 * R4, R18).
 *
 * A handset is an Evolution instance: support and account share one, and a channel id is never a handset. Every
 * automated send that leaves a handset through the plugin goes into `wa_auto_sends` (migration 089) — except AI replies
 * (D12: replies are not counted). The senders 5.18.104 adds CLAIM before they send and SETTLE after; the existing
 * automated senders (the reminders, win-back, staff briefs, alerts, transactional messages) RECORD after they send:
 * counted, never deferred, never refused (the hooks below).
 *
 *   claim(handset, kind, ref, o)  → ['ok'=>true,'claim'=>id] | ['ok'=>false,'defer'=>code,'retry_at'=>?int,'final'=>bool]
 *   settle(claim, 'sent'|'failed', o)   — o: maybe_sent, wa_message_id, detail; never throws
 *   record(handset, kind, ref, 'sent'|'failed', o) → recorded | ledger_off | not_uganda | no_table | error; never throws
 *   counts(handset)               — for the tool, the checks and the report; needs no store
 *
 * One claim runs in one BEGIN IMMEDIATE, reads its clock once after taking the lock, and writes that moment as the
 * row's claimed_at with the window and caps it applied (ruling R18). It fails closed: any exception is `ledger_error`
 * and nothing is sent. Every handset argument is normalised here (handset()), every phone too (international digits,
 * no '+', the tenant's rule; NULL when unreadable), so all callers land on the same rows.
 *
 * Inert unless on() — Uganda (StaffJobsGate, with the data directory) and `wa_send_ledger` = on. South Sudan never sets
 * the key: every hook tests it first and does nothing at all. PHP 7.4.
 */
final class WaSendBudget
{
    /** The literal zone every window and day is computed in (R-16); dn_tz() must agree or a claim answers `zone`. */
    public const TZ = 'Africa/Kampala';
    /** The ten kinds, as 089's CHECK lists them. */
    const KINDS = ['quote', 'enquiry', 'staff_list', 'offer', 'reminder', 'winback', 'brief', 'voice_ack', 'handover_alert', 'other'];
    /** Counted against the daily and hourly caps, and kept apart by the spacing. */
    const PROACTIVE = ['quote', 'enquiry', 'staff_list', 'offer', 'reminder', 'winback', 'brief'];
    /** What may be claimed; everything else is recorded after the send. */
    const CLAIMABLE = ['quote', 'enquiry', 'staff_list', 'offer', 'voice_ack'];
    /** A claim of these needs the customer's phone (ruling R2: no_phone, final). */
    const PHONE_KINDS = ['quote', 'enquiry', 'offer'];
    /** The kinds the one-message-per-person-per-Kampala-day rule compares (R-06, S2-08). */
    const PERSON_KINDS = ['quote', 'enquiry', 'offer', 'reminder', 'winback'];
    /** C3's priority when the day's budget is short: quote > staff_list > enquiry > reminder/winback/brief > offer. */
    const PRIORITY = ['quote' => 1, 'staff_list' => 2, 'enquiry' => 3, 'reminder' => 4, 'winback' => 4, 'brief' => 4, 'offer' => 5];
    /** A claim in flight this long is an orphan: settled `uncertain`, counted as sent, never re-sent (F3, S2-17). */
    const IN_FLIGHT_S = 900;
    /** The proactive settings and their defaults (D12, D3); the tool's `set` holds their ranges (§H.2.5.6). */
    const DEFAULTS = [
        'wa_send_daily_cap'  => 60,
        'wa_send_hourly_cap' => 15,
        'wa_send_gap_min_s'  => 60,
        'wa_send_gap_max_s'  => 180,
        'wa_send_hour_from'  => 8,
        'wa_send_hour_to'    => 20,
    ];
    /** The test seam for the channel-to-handset map (ruling R2: a callable is accepted for tests only). */
    const TEST_SEAM_ENV = 'DN_WA_LEDGER_TEST_HANDSETS';

    /**
     * NotificationService events => the kind the record-only hook files them as (§H.2.6.1). Every other event is
     * `other`: receipts, quotations, invoice, quotation and delivery PDFs, welcomes, admins' alerts, login codes,
     * wa_media_ack. A test asserts each event below occurs as a literal at a send call in the tree.
     */
    const NOTIFY_KINDS = [
        'ops_pre_due_d7'         => 'reminder',
        'ops_pre_due_d3'         => 'reminder',
        'ops_pre_due_d1'         => 'reminder',
        'ops_overdue_d1'         => 'reminder',
        'ops_overdue_d3'         => 'reminder',
        'ops_overdue_d5'         => 'reminder',
        'ops_low_balance'        => 'reminder',
        'ops_win_back'           => 'winback',
        'staff_jobs_summary'     => 'brief',
        'staff_daily_report'     => 'brief',
        'bidal_morning_summary'  => 'brief',
        'cashbook_daily_summary' => 'brief',
        'cash_carry_reminder'    => 'brief',
    ];

    /** @var \PDO|null */ private $pdo;
    /** @var array */     private $config;
    /** @var string|null */ private $dataDir;
    /** @var object|null */ private $store;
    /** @var callable|null */ private $handsetOf;
    /** @var callable */  private $now;
    /** @var callable */  private $rand;
    /** @var bool */      private $connOnly = false;
    /** @var array<string,string> */ private $chanMemo = [];
    /** @var object|null */ private $evoMemo = null;
    /** @var bool|null */ private $hasKindMemo = null;

    private function __construct() {}

    // ── construction ───────────────────────────────────────────────────────────────────────────────────────────

    /** Uganda (StaffJobsGate, with the data directory) and `wa_send_ledger` = on. */
    public static function on(array $config, ?string $dataDir): bool
    {
        return (string)($config['wa_send_ledger'] ?? '') === 'on' && StaffJobsGate::applies($config, $dataDir);
    }

    /** Idempotent: strip a leading 'i:', strtolower(trim()), re-prefix 'i:'; '' when blank. Never a channel id. */
    public static function handset(string $instance): string
    {
        $s = strtolower(trim($instance));
        if (strpos($s, 'i:') === 0) $s = trim(substr($s, 2));
        return $s === '' ? '' : 'i:' . $s;
    }

    /**
     * The ledger for an install: its store's connection, its master_schedule.json for ReminderDay, and the
     * channel-to-handset map it derives itself (handset(EvolutionApiService::forStore(...)->instanceFor($ch))).
     * $handsetOf, $now and $rand are test seams; $handsetOf is honoured only under TEST_SEAM_ENV (ruling R2).
     */
    public static function forInstall(array $config, ?string $dataDir, StoreInterface $store, ?callable $handsetOf = null,
                                      ?callable $now = null, ?callable $rand = null): self
    {
        $b = new self();
        $b->config  = $config;
        $b->dataDir = ($dataDir !== null && $dataDir !== '') ? $dataDir : null;
        $b->store   = $store;
        $b->pdo     = method_exists($store, 'getPdo') ? $store->getPdo() : null;
        $b->handsetOf = (getenv(self::TEST_SEAM_ENV) === '1') ? $handsetOf : null;
        $b->now  = $now  ?? function (): int { return time(); };
        $b->rand = $rand ?? function (int $a, int $b): int { return random_int($a, $b); };
        return $b;
    }

    /**
     * Over a connection the caller already holds, for a caller with no store (the tool's $ro and $rw, the report, the
     * Starlink hook). counts(), settle() and record() work on it; claim() answers not_claimable, final, and inserts
     * nothing — it holds no store for ReminderDay or the reservation.
     */
    public static function onConnection(\PDO $pdo, array $config, ?string $dataDir): self
    {
        $b = new self();
        $b->config   = $config;
        $b->dataDir  = ($dataDir !== null && $dataDir !== '') ? $dataDir : null;
        $b->pdo      = $pdo;
        $b->connOnly = true;
        $b->now  = function (): int { return time(); };
        $b->rand = function (int $a, int $b): int { return random_int($a, $b); };
        return $b;
    }

    // ── settings ───────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * The proactive window: [hour_from, hour_to] in Kampala, Monday to Saturday (Sunday is never open, D3). The values
     * stored are returned as they are; 089's CHECK holds them to A19's narrow-only ranges (8-19, from+1-20), so a
     * widened setting fails the claim's insert (`ledger_error`) and nothing is sent. An unreadable value reads 0.
     *
     * @return array{0:int,1:int}
     */
    public static function window(array $config): array
    {
        return [self::setting($config, 'wa_send_hour_from'), self::setting($config, 'wa_send_hour_to')];
    }

    /** voice_ack's window, gap and ceiling: a thin delegate to the one holder (ruling R4). Null = invalid. */
    public static function ackPolicy(array $config): ?array
    {
        return VoiceAckLimits::of($config);
    }

    /** Whether enquiry follow-ups claim (the chase or offers is on) or record (§H.2.6.4). */
    public static function enquiryClaims(array $config): bool
    {
        foreach (['quote_chase_enabled', 'offers_test_enabled', 'offers_live_enabled'] as $k) {
            if (in_array(strtolower(trim((string)($config[$k] ?? ''))), ['1', 'true', 'on', 'yes'], true)) return true;
        }
        return false;
    }

    /** A proactive setting: its default when absent, the stored integer, or 0 when it cannot be read (fails closed). */
    private static function setting(array $config, string $key): int
    {
        $raw = $config[$key] ?? null;
        if ($raw === null || (is_string($raw) && trim($raw) === '')) return self::DEFAULTS[$key];
        if (is_int($raw)) return $raw;
        if (is_string($raw) && preg_match('/^\s*\d{1,6}\s*$/', $raw)) return (int)trim($raw);
        return 0;
    }

    // ── claim ──────────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * Ask before a send. Idempotent per (kind, ref). §H.2.5.3's steps, in one BEGIN IMMEDIATE.
     *
     * @param array $o phone (quote|enquiry|offer, required), in_flight_s (int 0-900, proactive kinds only)
     * @return array ['ok'=>true,'claim'=>int] | ['ok'=>false,'defer'=>string,'retry_at'=>?int,'final'=>bool]
     */
    public function claim(string $handset, string $kind, string $ref, array $o = []): array
    {
        // 0. Refusals before anything else.
        if ($this->connOnly) return self::no('not_claimable', null, true);
        if (!self::on($this->config, $this->dataDir)) return self::no('ledger_off', null, true);
        if (!in_array($kind, self::CLAIMABLE, true) || $ref === '') return self::no('not_claimable', null, true);
        $inflight = 0;
        if (array_key_exists('in_flight_s', $o)) {
            $v = $o['in_flight_s'];
            if ($kind === 'voice_ack' || !is_int($v) || $v < 0 || $v > self::IN_FLIGHT_S) return self::no('not_claimable', null, true);
            $inflight = $v;
        }
        $h = self::handset($handset);
        if ($h === '') return self::no('no_instance', null, true);
        if (!function_exists('dn_tz')) require_once __DIR__ . '/timezone.php';
        if (dn_tz($this->config) !== self::TZ) return self::no('zone', null, true);
        $phone = null;
        if (in_array($kind, self::PHONE_KINDS, true)) {
            $phone = $this->phone((string)($o['phone'] ?? ''));
            if ($phone === null) return self::no('no_phone', null, true);
        }
        if ($this->pdo === null) return self::no('ledger_error', time() + 300, false);

        $pdo = $this->pdo;
        $tx = false;
        $now = time();
        try {
            $schedule = [];
            if ($this->store !== null && in_array($kind, self::PHONE_KINDS, true)) {
                $schedule = (array)($this->store->load('master_schedule.json') ?? []);
            }
            $this->txBegin(); $tx = true;
            $now = (int)($this->now)();           // the claim's moment, read once with the lock held (ruling R18)
            $day = self::localDay($now);
            $proactive = in_array($kind, self::PROACTIVE, true);

            // 1. Idempotency on the rows for (kind, ref).
            $st = $pdo->prepare('SELECT id, outcome, claimed_at FROM wa_auto_sends WHERE kind = ? AND ref = ? ORDER BY try');
            $st->execute([$kind, $ref]);
            $notSent = 0;
            foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $r) {
                $out = $r['outcome'];
                if ($out === 'sent' || $out === 'uncertain') return $this->end(self::no('already_sent', null, true));
                if ($out === null) {
                    $at = UtcClock::parse($r['claimed_at']);
                    if ($now - $at < self::IN_FLIGHT_S) return $this->end(self::no('in_flight', $at + self::IN_FLIGHT_S, false));
                    $pdo->prepare("UPDATE wa_auto_sends SET outcome = 'uncertain', settled_at = ?, detail = 'stale'
                                    WHERE id = ? AND outcome IS NULL")->execute([UtcClock::stamp($now), (int)$r['id']]);
                    $this->txCommit(); $tx = false;
                    return self::no('already_sent', null, true);
                }
                $notSent++;
            }
            $try = 1 + $notSent;
            if ($try > 3) return $this->end(self::no('tries_exhausted', null, true));

            // 2. The window, in a literal Africa/Kampala.
            $pol = null;
            if ($proactive) {
                [$from, $to] = self::window($this->config);
                $close = ReminderDay::todayAt($now, $to);
                if (!self::inWindow($now, $from, $to) || $now + $inflight > $close) {
                    return $this->end(self::no('window', self::nextOpening($now, $from), false));
                }
            } else {
                $pol = self::ackPolicy($this->config);
                if ($pol === null || !VoiceAckLimits::inWindow($pol, $now)) return $this->end(self::no('window', null, true));
                [$from, $to] = [$pol['hour_from'], $pol['hour_to']];
            }

            // 3. Orphans on the handset, whatever their kind and ref; then one claim at a time.
            $pdo->prepare("UPDATE wa_auto_sends SET outcome = 'uncertain', settled_at = ?, detail = 'stale_orphan'
                            WHERE handset = ? AND outcome IS NULL AND claimed_at <= ?")
                ->execute([UtcClock::stamp($now), $h, UtcClock::stamp($now - self::IN_FLIGHT_S)]);
            $st = $pdo->prepare('SELECT COUNT(*) FROM wa_auto_sends WHERE handset = ? AND outcome IS NULL');
            $st->execute([$h]);
            if ((int)$st->fetchColumn() > 0) return $this->end(self::no('handset_busy', $now + 60, false));

            // 4. Spacing, over every row on the handset of any kind and mode that is not `not_sent`.
            if ($proactive) {
                $st = $pdo->prepare("SELECT MAX(CAST(strftime('%s', claimed_at) AS INTEGER) + gap_s) FROM wa_auto_sends
                                      WHERE handset = ? AND (outcome IS NULL OR outcome <> 'not_sent')");
                $st->execute([$h]);
                $until = (int)$st->fetchColumn();
            } else {
                $st = $pdo->prepare("SELECT MAX(CAST(strftime('%s', claimed_at) AS INTEGER)) FROM wa_auto_sends
                                      WHERE handset = ? AND (outcome IS NULL OR outcome <> 'not_sent')");
                $st->execute([$h]);
                $last = $st->fetchColumn();
                $until = $last === null || $last === false ? 0 : (int)$last + (int)$pol['gap_s'];
            }
            if ($now < $until) return $this->end(self::no('spacing', $until, false));

            $capDay = null; $capHour = null;
            if ($kind === 'voice_ack') {
                // 5. voice_ack's own ceiling; the proactive caps, the reservation and the per-person rule do not apply.
                $capDay = (int)$pol['daily_max'];
                $st = $pdo->prepare("SELECT COUNT(*) FROM wa_auto_sends WHERE handset = ? AND kind = 'voice_ack'
                                      AND local_day = ? AND (outcome IS NULL OR outcome <> 'not_sent')");
                $st->execute([$h, $day]);
                if ((int)$st->fetchColumn() >= $capDay) return $this->end(self::no('ack_ceiling', null, true));
            } else {
                // 6. The hourly cap: proactive rows of any mode in the last 3,600 s.
                $capHour = self::setting($this->config, 'wa_send_hourly_cap');
                $st = $pdo->prepare("SELECT COUNT(*), MIN(CAST(strftime('%s', claimed_at) AS INTEGER)) FROM wa_auto_sends
                                      WHERE handset = ? AND proactive = 1 AND claimed_at > ?
                                        AND (outcome IS NULL OR outcome <> 'not_sent')");
                $st->execute([$h, UtcClock::stamp($now - 3600)]);
                $hr = $st->fetch(\PDO::FETCH_NUM);
                if ((int)$hr[0] >= $capHour) return $this->end(self::no('hour_cap', (int)$hr[1] + 3600, false));

                // 7. The daily cap, with the priority: what the kinds above this one still need today is reserved.
                $capDay = self::setting($this->config, 'wa_send_daily_cap');
                $st = $pdo->prepare("SELECT COUNT(*) FROM wa_auto_sends WHERE handset = ? AND proactive = 1 AND local_day = ?
                                       AND (outcome IS NULL OR outcome <> 'not_sent')");
                $st->execute([$h, $day]);
                $used = (int)$st->fetchColumn();
                $reserve = $this->reserve($kind, $h, $now, $day);
                if ($used + $reserve + 1 > $capDay) {
                    return $this->end(self::no($reserve > 0 ? 'reserved' : 'day_cap', self::nextDayOpening($now, $from), false));
                }

                // 8. One automated customer message per person per Kampala day, both ways.
                if (in_array($kind, self::PHONE_KINDS, true)) {
                    $st = $pdo->prepare("SELECT COUNT(*) FROM wa_auto_sends WHERE phone = ? AND local_day = ?
                                           AND kind IN ('quote','enquiry','offer','reminder','winback')
                                           AND (outcome IS NULL OR outcome <> 'not_sent')
                                           AND NOT (kind = ? AND ref = ?)");
                    $st->execute([$phone, $day, $kind, $ref]);
                    if ((int)$st->fetchColumn() > 0) return $this->end(self::no('phone_today', self::nextDayOpening($now, $from), false));
                    if (!ReminderDay::done($schedule, $this->config, $this->dataDir, $now)) {
                        [, $until2] = ReminderDay::hours($this->config);
                        return $this->end(self::no('reminders_first', min($now + 600, ReminderDay::todayAt($now, $until2)), false));
                    }
                }
            }

            // 9. The row: the claim's moment and the limits it applied (ruling R18).
            $gapMin = self::setting($this->config, 'wa_send_gap_min_s');
            $gapMax = self::setting($this->config, 'wa_send_gap_max_s');
            if ($gapMin < 30 || $gapMin > 600 || $gapMax < $gapMin || $gapMax > 900) {
                return $this->end(self::no('ledger_error', $now + 300, false));   // spacing narrower than A19 allows
            }
            $gap = $proactive ? (int)($this->rand)($gapMin, $gapMax) : $gapMin;
            try {
                $pdo->prepare('INSERT INTO wa_auto_sends (handset, kind, ref, try, mode, proactive, phone, local_day, gap_s,
                                                          claimed_at, win_from, win_to, cap_day, cap_hour)
                               VALUES (?, ?, ?, ?, \'claim\', ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                    ->execute([$h, $kind, $ref, $try, $proactive ? 1 : 0, $phone, $day, $gap, UtcClock::stamp($now),
                               $from, $to, $capDay, $capHour]);
            } catch (\PDOException $e) {
                if (stripos($e->getMessage(), 'UNIQUE constraint') !== false) return $this->end(self::no('in_flight', $now + 60, false));
                throw $e;
            }
            $id = (int)$pdo->lastInsertId();
            $this->txCommit(); $tx = false;
            return ['ok' => true, 'claim' => $id];
        } catch (\Throwable $e) {
            if ($tx) { try { $pdo->exec('ROLLBACK'); } catch (\Throwable $e2) { /* nothing held */ } }
            $this->inTx = false;
            return self::no('ledger_error', $now + 300, false);
        }
    }

    /** @var bool */ private $inTx = false;

    private function txBegin(): void
    {
        $this->pdo->exec('BEGIN IMMEDIATE'); $this->inTx = true;
    }

    private function txCommit(): void
    {
        if ($this->inTx) { $this->inTx = false; $this->pdo->exec('COMMIT'); }
    }

    /** Leave the transaction without writing, and answer. */
    private function end(array $answer): array
    {
        if ($this->inTx) { $this->inTx = false; $this->pdo->exec('ROLLBACK'); }
        return $answer;
    }

    private static function no(string $code, ?int $retryAt, bool $final): array
    {
        return ['ok' => false, 'defer' => $code, 'retry_at' => $final ? null : $retryAt, 'final' => $final];
    }

    // ── settle and record ──────────────────────────────────────────────────────────────────────────────────────

    /**
     * After the send. 'sent' → sent; 'failed' with maybe_sent → uncertain (counted, never retried; ruling R2);
     * 'failed' → not_sent (the next claim of the ref takes try + 1). A row already settled — by the orphan sweep —
     * matches nothing: one `late_settle` line, and the earlier `uncertain` stands. Never throws into the sender.
     */
    public function settle(int $claim, string $result, array $o = []): void
    {
        $n = -1;
        try {
            if ($this->pdo === null) return;
            $outcome = self::outcomeOf($result, $o);
            $now = (int)($this->now)();
            $st = $this->pdo->prepare('UPDATE wa_auto_sends SET outcome = ?, settled_at = ?, wa_message_id = ?, detail = ?
                                        WHERE id = ? AND outcome IS NULL');
            $st->execute([$outcome, UtcClock::stamp($now), self::waId($o), self::detail($o), $claim]);
            $n = $st->rowCount();
        } catch (\Throwable $e) {
            PluginLog::write('wa_send_ledger', 'settle_lost');
            return;
        }
        if ($n === 0) PluginLog::write('wa_send_ledger', 'late_settle');   // already settled (the sweep's uncertain stands)
    }

    /**
     * After a send the hook did not claim: an INSERT OR IGNORE of a settled row, mode `record`, none of the four limits.
     * Takes $result and $o exactly as settle() does, plus $o['phone'] (normalised here). Never throws, never refuses.
     *
     * @return string recorded | ledger_off | not_uganda | no_table | error
     */
    public function record(string $handset, string $kind, string $ref, string $result, array $o = []): string
    {
        $lineKind = in_array($kind, self::KINDS, true) ? $kind : 'other';
        try {
            if ((string)($this->config['wa_send_ledger'] ?? '') !== 'on') return 'ledger_off';
            if (!StaffJobsGate::applies($this->config, $this->dataDir)) return 'not_uganda';
            if ($this->pdo === null || !self::hasTable($this->pdo, 'wa_auto_sends')) return 'no_table';
            $h = self::handset($handset);
            if ($h === '' || $lineKind !== $kind || $ref === '') return 'error';
            $now = (int)($this->now)();
            $phone = isset($o['phone']) ? $this->phone((string)$o['phone']) : null;
            $gap = self::setting($this->config, 'wa_send_gap_min_s');
            if ($gap < 0 || $gap > self::IN_FLIGHT_S) $gap = self::DEFAULTS['wa_send_gap_min_s'];
            $st = $this->pdo->prepare('INSERT OR IGNORE INTO wa_auto_sends (handset, kind, ref, try, mode, proactive, phone,
                                          local_day, gap_s, claimed_at, outcome, settled_at, wa_message_id, detail)
                                       VALUES (?, ?, ?, 1, \'record\', ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $st->execute([$h, $kind, $ref, in_array($kind, self::PROACTIVE, true) ? 1 : 0, $phone, self::localDay($now), $gap,
                          UtcClock::stamp($now), self::outcomeOf($result, $o), UtcClock::stamp($now), self::waId($o), self::detail($o)]);
            if ($st->rowCount() === 1) return 'recorded';
            $chk = $this->pdo->prepare('SELECT COUNT(*) FROM wa_auto_sends WHERE kind = ? AND ref = ? AND handset = ?');
            $chk->execute([$kind, $ref, $h]);
            if ((int)$chk->fetchColumn() > 0) return 'recorded';   // the same message, recorded once already
            PluginLog::write('wa_send_ledger', 'hook_write_lost ' . $lineKind);
            return 'error';
        } catch (\Throwable $e) {
            PluginLog::write('wa_send_ledger', 'hook_write_lost ' . $lineKind);
            return 'error';
        }
    }

    private static function outcomeOf(string $result, array $o): string
    {
        if ($result === 'sent') return 'sent';
        if ($result === 'failed') return !empty($o['maybe_sent']) ? 'uncertain' : 'not_sent';
        return 'uncertain';   // an unknown result is never read as not sent
    }

    private static function waId(array $o): ?string
    {
        $id = trim((string)($o['wa_message_id'] ?? ''));
        return $id === '' ? null : mb_substr($id, 0, 200);
    }

    private static function detail(array $o): ?string
    {
        $d = (string)preg_replace('/[^A-Za-z0-9_:.-]/', '', (string)($o['detail'] ?? ''));
        return $d === '' ? null : substr($d, 0, 60);
    }

    // ── counts ─────────────────────────────────────────────────────────────────────────────────────────────────

    /** Per handset: what §H.2.5.7 lists. Reads only; needs no store. */
    public function counts(string $handset): array
    {
        $h = self::handset($handset);
        $now = (int)($this->now)();
        $day = self::localDay($now);
        [$from, $to] = self::window($this->config);
        $pol = self::ackPolicy($this->config);
        $out = [
            'table' => 0, 'proactive_today' => 0, 'cap_day' => self::setting($this->config, 'wa_send_daily_cap'),
            'proactive_hour' => 0, 'cap_hour' => self::setting($this->config, 'wa_send_hourly_cap'),
            'record_proactive_today' => 0, 'voice_ack_today' => 0, 'voice_ack_ceiling' => $pol === null ? 0 : (int)$pol['daily_max'],
            'internal_today' => 0, 'total_today' => 0, 'in_flight' => 0, 'orphans_today' => 0, 'next_proactive_at' => 0,
        ];
        if ($this->pdo === null || $h === '' || !self::hasTable($this->pdo, 'wa_auto_sends')) return $out;
        $out['table'] = 1;
        $live = "(outcome IS NULL OR outcome <> 'not_sent')";
        $one = function (string $sql, array $args) {
            $st = $this->pdo->prepare($sql);
            $st->execute($args);
            return $st->fetchColumn();
        };
        $out['proactive_today'] = (int)$one("SELECT COUNT(*) FROM wa_auto_sends WHERE handset = ? AND proactive = 1 AND local_day = ? AND {$live}", [$h, $day]);
        $out['proactive_hour']  = (int)$one("SELECT COUNT(*) FROM wa_auto_sends WHERE handset = ? AND proactive = 1 AND claimed_at > ? AND {$live}", [$h, UtcClock::stamp($now - 3600)]);
        $out['record_proactive_today'] = (int)$one("SELECT COUNT(*) FROM wa_auto_sends WHERE handset = ? AND proactive = 1 AND mode = 'record' AND local_day = ? AND {$live}", [$h, $day]);
        $out['voice_ack_today'] = (int)$one("SELECT COUNT(*) FROM wa_auto_sends WHERE handset = ? AND kind = 'voice_ack' AND local_day = ? AND {$live}", [$h, $day]);
        $out['internal_today']  = (int)$one("SELECT COUNT(*) FROM wa_auto_sends WHERE handset = ? AND kind IN ('handover_alert','other') AND local_day = ? AND {$live}", [$h, $day]);
        $out['total_today']     = (int)$one("SELECT COUNT(*) FROM wa_auto_sends WHERE handset = ? AND local_day = ? AND {$live}", [$h, $day]);
        $out['in_flight']       = (int)$one('SELECT COUNT(*) FROM wa_auto_sends WHERE handset = ? AND outcome IS NULL', [$h]);
        $out['orphans_today']   = (int)$one("SELECT COUNT(*) FROM wa_auto_sends WHERE handset = ? AND detail = 'stale_orphan' AND settled_at >= ?", [$h, UtcClock::stamp(ReminderDay::todayAt($now, 0))]);
        // The next allowed proactive moment: the window, the spacing and the two caps.
        $next = $now;
        $space = (int)$one("SELECT MAX(CAST(strftime('%s', claimed_at) AS INTEGER) + gap_s) FROM wa_auto_sends WHERE handset = ? AND {$live}", [$h]);
        $next = max($next, $space);
        if ($out['proactive_hour'] >= $out['cap_hour']) {
            $oldest = (int)$one("SELECT MIN(CAST(strftime('%s', claimed_at) AS INTEGER)) FROM wa_auto_sends WHERE handset = ? AND proactive = 1 AND claimed_at > ? AND {$live}", [$h, UtcClock::stamp($now - 3600)]);
            $next = max($next, $oldest + 3600);
        }
        if ($out['proactive_today'] >= $out['cap_day']) $next = max($next, self::nextDayOpening($now, $from));
        if (!self::inWindow($next, $from, $to)) $next = self::nextOpening($next, $from);
        $out['next_proactive_at'] = $next;
        return $out;
    }

    // ── the priority (C3, §H.2.5.4) ────────────────────────────────────────────────────────────────────────────

    /** The demand, on this handset today, of every kind above $kind. */
    private function reserve(string $kind, string $h, int $now, string $day): int
    {
        $p = self::PRIORITY[$kind] ?? 0;
        $sum = 0;
        if ($p > 1) $sum += $this->demandQuote($h, $now);
        if ($p > 2) $sum += $this->demandList($h, $now);
        if ($p > 3) $sum += $this->demandEnquiry($h);
        if ($p > 4) $sum += $this->demandRecorded($h, $now, $day);
        return $sum;
    }

    /** Open quote follow-ups due by the window's close today, on a channel of this handset. */
    private function demandQuote(string $h, int $now): int
    {
        if (!$this->hasKind()) return 0;
        [, $to] = self::window($this->config);
        $st = $this->pdo->prepare("SELECT channel, COUNT(*) AS n FROM followups
                                    WHERE closed_at IS NULL AND kind = 'quote' AND due_at IS NOT NULL AND due_at <= ?
                                    GROUP BY channel");
        $st->execute([UtcClock::stamp(ReminderDay::todayAt($now, $to))]);
        $n = 0;
        foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            if ($this->handsetOfChannel((string)$r['channel']) === $h) $n += (int)$r['n'];
        }
        return $n;
    }

    /**
     * On the sales handset, before 11:00 with the list on: the owners still to be sent today. Asked of
     * QuoteDailyList::pendingToday(\PDO, array $config, ?string $dataDir, int $now): array, once that class exists.
     */
    private function demandList(string $h, int $now): int
    {
        if ($this->handsetOfChannel('sales') !== $h) return 0;
        if (!in_array(strtolower(trim((string)($this->config['quote_daily_list_enabled'] ?? ''))), ['1', 'true', 'on', 'yes'], true)) return 0;
        if ((int)self::local($now)->format('G') >= 11) return 0;
        if (!class_exists('QuoteDailyList')) {
            if (!is_file(__DIR__ . '/QuoteDailyList.php')) return 0;
            require_once __DIR__ . '/QuoteDailyList.php';
        }
        if (!method_exists('QuoteDailyList', 'pendingToday')) return 0;
        $p = \QuoteDailyList::pendingToday($this->pdo, $this->config, $this->dataDir, $now);
        return is_array($p) ? count($p) : max(0, (int)$p);
    }

    /** Approved enquiry drafts on this handset, while enquiry claims. */
    private function demandEnquiry(string $h): int
    {
        if (!self::enquiryClaims($this->config)) return 0;
        $kind = $this->hasKind() ? " AND COALESCE(f.kind,'enquiry') = 'enquiry'" : '';
        $st = $this->pdo->query("SELECT f.channel, COUNT(*) AS n FROM followup_drafts d JOIN followups f ON f.id = d.followup_id
                                  WHERE d.status = 'approved' AND f.closed_at IS NULL{$kind} GROUP BY f.channel");
        $n = 0;
        foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            if ($this->handsetOfChannel((string)$r['channel']) === $h) $n += (int)$r['n'];
        }
        return $n;
    }

    /**
     * Reserved for offers only: before reminder_until_hour, and with none of reminder, winback or brief recorded today on
     * this handset, the largest daily count of them over the previous 7 Kampala days; otherwise 0.
     */
    private function demandRecorded(string $h, int $now, string $day): int
    {
        [, $until] = ReminderDay::hours($this->config);
        if ((int)self::local($now)->format('G') >= $until) return 0;
        $st = $this->pdo->prepare("SELECT COUNT(*) FROM wa_auto_sends WHERE handset = ? AND local_day = ?
                                     AND kind IN ('reminder','winback','brief')");
        $st->execute([$h, $day]);
        if ((int)$st->fetchColumn() > 0) return 0;
        $days = [];
        for ($i = 1; $i <= 7; $i++) $days[] = self::localDay($now - $i * 86400);
        $in = implode(',', array_fill(0, count($days), '?'));
        $st = $this->pdo->prepare("SELECT MAX(n) FROM (SELECT local_day, COUNT(*) AS n FROM wa_auto_sends
                                     WHERE handset = ? AND kind IN ('reminder','winback','brief') AND local_day IN ({$in})
                                       AND (outcome IS NULL OR outcome <> 'not_sent')
                                     GROUP BY local_day)");
        $st->execute(array_merge([$h], $days));
        return (int)$st->fetchColumn();
    }

    /** The handset a channel's sends leave from: derived here, never taken from a caller outside tests (ruling R2). */
    private function handsetOfChannel(string $channel): string
    {
        if (isset($this->chanMemo[$channel])) return $this->chanMemo[$channel];
        if ($this->handsetOf !== null) {
            return $this->chanMemo[$channel] = self::handset((string)($this->handsetOf)($channel));
        }
        if ($this->evoMemo === null) {
            if (!class_exists('EvolutionApiService')) require_once __DIR__ . '/EvolutionApiService.php';
            $this->evoMemo = \EvolutionApiService::forStore($this->config, $this->pdo, $this->dataDir);
        }
        return $this->chanMemo[$channel] = self::handset((string)$this->evoMemo->instanceFor($channel));
    }

    private function hasKind(): bool
    {
        if ($this->hasKindMemo === null) {
            $this->hasKindMemo = false;
            foreach ($this->pdo->query('PRAGMA table_info(followups)')->fetchAll(\PDO::FETCH_ASSOC) as $c) {
                if (($c['name'] ?? '') === 'kind') $this->hasKindMemo = true;
            }
        }
        return $this->hasKindMemo;
    }

    // ── the phone, the day, the window ─────────────────────────────────────────────────────────────────────────

    /** International digits, no '+', by the tenant's rule (PhoneNumber::international); null when it does not normalise. */
    private function phone(string $raw): ?string
    {
        if (trim($raw) === '') return null;
        try {
            if (!class_exists('PhoneNumber')) require_once __DIR__ . '/PhoneNumber.php';
            if (!class_exists('TenantProfile')) require_once __DIR__ . '/TenantProfile.php';
            $intl = \PhoneNumber::international($raw, \TenantProfile::current($this->config, $this->dataDir));
            return $intl === null ? null : ltrim($intl, '+');
        } catch (\Throwable $e) {
            return null;
        }
    }

    private static function local(int $t): \DateTime
    {
        return (new \DateTime('@' . $t))->setTimezone(new \DateTimeZone(self::TZ));
    }

    /** The Kampala date of $t. */
    public static function localDay(int $t): string
    {
        return self::local($t)->format('Y-m-d');
    }

    /** Monday to Saturday, from <= hour < to, in a literal Africa/Kampala. */
    private static function inWindow(int $t, int $from, int $to): bool
    {
        $l = self::local($t);
        if ((int)$l->format('N') === 7) return false;
        $hour = (int)$l->format('G');
        return $hour >= $from && $hour < $to;
    }

    /** The next opening: today's hour_from if it is still to come on an open day, else the next open day's. */
    private static function nextOpening(int $t, int $from): int
    {
        $l = self::local($t);
        if ((int)$l->format('N') !== 7 && (int)$l->format('G') < $from) return ReminderDay::todayAt($t, $from);
        return self::nextDayOpening($t, $from);
    }

    /** hour_from on the next open day after today (Sunday is never open). */
    private static function nextDayOpening(int $t, int $from): int
    {
        $d = ReminderDay::todayAt($t, 0);
        for ($i = 1; $i <= 8; $i++) {
            $day = $d + $i * 86400;
            if ((int)self::local($day + 3600)->format('N') !== 7) return $day + $from * 3600;
        }
        return $d + 86400 + $from * 3600;
    }

    private static function hasTable(\PDO $pdo, string $name): bool
    {
        $st = $pdo->prepare("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = ?");
        $st->execute([$name]);
        return (int)$st->fetchColumn() > 0;
    }

    // ── the record-only hooks (§H.2.6) ─────────────────────────────────────────────────────────────────────────

    /**
     * NotificationService's three hooks, after an Evolution send. A person's event (the Inbox's) returns at once: a
     * person's send is never filed as automation. The handset is evo_instance_<channel>, the one the send used; the
     * phone is kept for reminder and winback only. record()'s answer is ignored; a lost write leaves one line.
     */
    public static function recordNotify($store, array $config, string $channel, string $event, string $to, bool $success,
                                        string $error, string $raw): void
    {
        if (!class_exists('HumanTakeover')) require_once __DIR__ . '/HumanTakeover.php';
        if (in_array($event, HumanTakeover::personEvents(), true)) return;
        $kind = self::NOTIFY_KINDS[$event] ?? 'other';
        try {
            if ((string)($config['wa_send_ledger'] ?? '') !== 'on') return;
            $dataDir = (is_object($store) && method_exists($store, 'getDataDir')) ? (string)$store->getDataDir() : null;
            if (!StaffJobsGate::applies($config, $dataDir)) return;
            $res = json_decode($raw, true);
            $waId = is_array($res) ? (string)($res['data']['key']['id'] ?? ($res['key']['id'] ?? '')) : '';
            $ref = $waId !== '' ? 'n:' . $waId : 'n:x' . sha1($event . '|' . $to . '|' . microtime(true) . '|' . bin2hex(random_bytes(8)));
            if (!class_exists('EvolutionApiService')) require_once __DIR__ . '/EvolutionApiService.php';
            $o = ['wa_message_id' => $waId, 'maybe_sent' => !$success && \EvolutionApiService::mayHaveBeenSent($error)];
            if ($kind === 'reminder' || $kind === 'winback') $o['phone'] = $to;
            $b = self::onConnection($store->getPdo(), $config, $dataDir);
            $b->record((string)($config['evo_instance_' . $channel] ?? ''), $kind, $ref, $success ? 'sent' : 'failed', $o);
        } catch (\Throwable $e) {
            PluginLog::write('wa_send_ledger', 'hook_write_lost ' . $kind);
        }
    }

    /**
     * A send WASender carried (no handset, so no ledger row): one more on the day's content-free count
     * `wasender:<local_day>:<kind>` in quote_followup_state, in one statement, for L-ON to tell the transports apart.
     */
    public static function countWasender($store, array $config, string $event): void
    {
        if (!class_exists('HumanTakeover')) require_once __DIR__ . '/HumanTakeover.php';
        if (in_array($event, HumanTakeover::personEvents(), true)) return;
        $kind = self::NOTIFY_KINDS[$event] ?? 'other';
        try {
            if ((string)($config['wa_send_ledger'] ?? '') !== 'on') return;
            $dataDir = (is_object($store) && method_exists($store, 'getDataDir')) ? (string)$store->getDataDir() : null;
            if (!StaffJobsGate::applies($config, $dataDir)) return;
            self::bump($store->getPdo(), 'wasender:' . self::localDay(time()) . ':' . $kind);
        } catch (\Throwable $e) {
            PluginLog::write('wa_send_ledger', 'hook_write_lost ' . $kind);
        }
    }

    /**
     * AlertService's hook, after sendText on `sales`. handover_alert for an `escalate:` key, other for every other. No
     * phone is kept. A blank sales instance cannot hold a row (089's CHECK): then no record(), and, when the answer
     * carries `http`, one more on `noinstance:<local_day>:<kind>`.
     */
    public static function recordAlert($store, array $config, $evo, string $key, $r): void
    {
        $kind = strpos($key, 'escalate:') === 0 ? 'handover_alert' : 'other';
        try {
            if ((string)($config['wa_send_ledger'] ?? '') !== 'on') return;
            $dataDir = (is_object($store) && method_exists($store, 'getDataDir')) ? (string)$store->getDataDir() : null;
            if (!StaffJobsGate::applies($config, $dataDir)) return;
            $inst = (is_object($evo) && method_exists($evo, 'instanceFor')) ? (string)$evo->instanceFor('sales') : '';
            if (self::handset($inst) === '') {
                if (is_array($r) && array_key_exists('http', $r)) self::bump($store->getPdo(), 'noinstance:' . self::localDay(time()) . ':' . $kind);
                return;
            }
            $waId = is_array($r) ? (string)($r['data']['key']['id'] ?? ($r['key']['id'] ?? '')) : '';
            $ref = $waId !== '' ? 'a:' . $waId : 'a:x' . sha1($key . '|' . microtime(true) . '|' . bin2hex(random_bytes(8)));
            $ok = is_array($r) && !empty($r['ok']);
            if (!class_exists('EvolutionApiService')) require_once __DIR__ . '/EvolutionApiService.php';
            $o = ['wa_message_id' => $waId, 'maybe_sent' => !$ok && is_array($r) && \EvolutionApiService::mayHaveBeenSent($r)];
            self::onConnection($store->getPdo(), $config, $dataDir)->record($inst, $kind, $ref, $ok ? 'sent' : 'failed', $o);
        } catch (\Throwable $e) {
            PluginLog::write('wa_send_ledger', 'hook_write_lost ' . $kind);
        }
    }

    /**
     * A sender that holds no store and sends with $evo->sendText itself (StarlinkMailWorker): the ledger over its own
     * PDO, record() only. $dataDir is how the sender finds it; StaffJobsGate needs it to read the tenant.
     */
    public static function recordDirect(\PDO $pdo, array $config, ?string $dataDir, $evo, string $channel, string $kind,
                                        string $prefix, $r): void
    {
        $lineKind = in_array($kind, self::KINDS, true) ? $kind : 'other';
        try {
            if ((string)($config['wa_send_ledger'] ?? '') !== 'on') return;
            if (!StaffJobsGate::applies($config, $dataDir)) return;
            $inst = (is_object($evo) && method_exists($evo, 'instanceFor')) ? (string)$evo->instanceFor($channel) : '';
            $waId = is_array($r) ? (string)($r['data']['key']['id'] ?? ($r['key']['id'] ?? '')) : '';
            $ref = $prefix . ':' . ($waId !== '' ? $waId : 'x' . sha1($channel . '|' . microtime(true) . '|' . bin2hex(random_bytes(8))));
            $ok = is_array($r) ? (!empty($r['ok']) || (!isset($r['ok']) && empty($r['error']) && empty($r['suppressed']))) : (bool)$r;
            if (!class_exists('EvolutionApiService')) require_once __DIR__ . '/EvolutionApiService.php';
            $o = ['wa_message_id' => $waId, 'maybe_sent' => !$ok && is_array($r) && \EvolutionApiService::mayHaveBeenSent($r)];
            self::onConnection($pdo, $config, $dataDir)->record($inst, $kind, $ref, $ok ? 'sent' : 'failed', $o);
        } catch (\Throwable $e) {
            PluginLog::write('wa_send_ledger', 'hook_write_lost ' . $lineKind);
        }
    }

    /** One more on a content-free count in quote_followup_state, in one statement (two processes never lose one). */
    private static function bump(\PDO $pdo, string $k): void
    {
        if (!self::hasTable($pdo, 'quote_followup_state')) return;
        $pdo->prepare("INSERT INTO quote_followup_state (k, v) VALUES (?, '1')
                       ON CONFLICT(k) DO UPDATE SET v = CAST(CAST(v AS INTEGER) + 1 AS TEXT), updated_at = datetime('now')")
            ->execute([$k]);
    }
}
