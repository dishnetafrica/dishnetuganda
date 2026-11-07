<?php
declare(strict_types=1);

require_once __DIR__ . '/StoreInterface.php';
require_once __DIR__ . '/SqliteStore.php';
require_once __DIR__ . '/PluginConfig.php';
require_once __DIR__ . '/PluginLog.php';
require_once __DIR__ . '/UtcClock.php';
require_once __DIR__ . '/timezone.php';
require_once __DIR__ . '/TenantProfile.php';
require_once __DIR__ . '/StaffDirectory.php';
require_once __DIR__ . '/ContactOptOut.php';
require_once __DIR__ . '/ConversationService.php';
require_once __DIR__ . '/JobBudget.php';
require_once __DIR__ . '/ReminderDay.php';
require_once __DIR__ . '/WaSendBudget.php';
require_once __DIR__ . '/HumanTakeover.php';
require_once __DIR__ . '/QuoteChase.php';
require_once __DIR__ . '/OfferSwitch.php';
require_once __DIR__ . '/OfferText.php';
require_once __DIR__ . '/OfferImage.php';
require_once __DIR__ . '/OfferWindow.php';
require_once __DIR__ . '/OfferAudience.php';
require_once __DIR__ . '/OfferStore.php';
require_once __DIR__ . '/OfferSchema.php';
require_once __DIR__ . '/OfferGateway.php';
require_once __DIR__ . '/EvolutionOfferGateway.php';

/**
 * OfferSender — the one place an offer leaves (5.18.104 P6, root docs/73 §H.4.10; contract C3; rulings R2, R8, R18, R19).
 *
 *   runOnce($deadline)  master's offers_send job (cron/offers_send.php): at most 35 s of starting sends, never past
 *                       master's deadline, one recipient per handset per run, every claim decided on its own fresh read
 *   sendTest(...)       the screen's test to the admin's own phone of record: class staff, recorded as ledger kind
 *                       `other` after the send, never claimed
 *
 * Claim before the send, settle after (the contract): the recipient row's CAS, then the ledger claim under the stable ref
 * offer:<offer_id>:<recipient_id> — idempotent per (kind, ref), the floor beneath the row's CAS — recorded on the row
 * BEFORE the gateway is called, then the gateway (whose echo claim is inside it), then the ledger's settle, then the row.
 * A crash can lose one recipient but never send twice: a claimed row with a ledger claim is settled `uncertain` by the
 * next run's stale sweep and is never sent again; a may-have-been-sent is settled `failed` with maybe_sent, stored
 * `uncertain`, counted, never retried. The ledger's answers are mapped by CODE (§H.4.10's table, P6's column of Part F's
 * call contract); an unknown code ends the run (fail closed).
 *
 * Logging: counts and fixed codes only (§H.4.15) — never a phone, a name, a wording, a customer's text or transport text.
 * Test seams (forInstall's $seams; production passes none): clock, gateway, budget, store, wait, nolock. PHP 7.4.
 */
final class OfferSender
{
    public const TZ = 'Africa/Kampala';
    const LOCK_FILE = 'offers_send.lock';
    const SAID_FILE = 'offers_send_said.json';
    /** O-D8: the run's own budget, one send's client timeout, and the time below which a run only sweeps. */
    const RUN_S = 35;
    const SEND_S = 15;
    const SHORT_S = 20;
    /** O-D9: the longest a claimed send can take, claim to gateway's return (§H.4.10, the in-flight bound). */
    const IN_FLIGHT_S = 50;
    /** A claim this old is stale; the sweep settles or releases it (§H.4.10 step 4). */
    const STALE_S = 900;
    const RELEASE_S = 1800;
    const MARKS_PER_RUN = 500;
    const PAUSE_MIN_SENDS = 40;
    const TEST_BUSY_S = 60;

    /** The ledger's defers that carry a retry_at: release the row to it (§H.4.10's table). */
    const DEFER_CODES = ['in_flight', 'window', 'handset_busy', 'spacing', 'hour_cap', 'day_cap', 'reserved', 'reminders_first'];
    /** The ledger's final answers that settle the recipient skipped(<code>), the run going on. */
    const SKIP_CODES = ['tries_exhausted', 'not_claimable', 'no_phone'];

    /** @var string */ private $root;
    /** @var string */ private $dataDir;
    /** @var array */  private $config;
    /** @var \StoreInterface */ private $store;
    /** @var \PDO */ private $pdo;
    /** @var callable */ private $clock;
    /** @var callable */ private $gatewayOf;
    /** @var callable */ private $budgetOf;
    /** @var callable */ private $wait;
    /** @var bool */ private $nolock = false;
    /** @var array per run */ private $run = [];

    private function __construct() {}

    /**
     * The sender for an install. $config is the job's start-time configuration (it decides only whether the run begins);
     * every recipient is decided on a fresh read of the files (ruling R19).
     *
     * @param array $seams tests only: clock (fn(): int), gateway (fn(\PDO, array $f, ?string, $store): OfferGateway),
     *                     budget (fn(array $f, ?string, $store, callable $clock): object), store (StoreInterface),
     *                     wait (fn(int $realSecond): void), nolock (bool)
     */
    public static function forInstall(string $root, string $dataDir, array $config, array $seams = []): self
    {
        $s = new self();
        $s->root    = $root;
        $s->dataDir = $dataDir;
        $s->config  = $config;
        $s->store   = $seams['store'] ?? SqliteStore::create($dataDir);
        $s->pdo     = $s->store->getPdo();
        $s->clock   = $seams['clock'] ?? function (): int { return time(); };
        $s->gatewayOf = $seams['gateway'] ?? function (\PDO $pdo, array $f, ?string $dataDir, $store): OfferGateway {
            return new EvolutionOfferGateway($pdo, $f, $dataDir, $store);
        };
        $s->budgetOf = $seams['budget'] ?? function (array $f, ?string $dataDir, $store, callable $clock) {
            return WaSendBudget::forInstall($f, $dataDir, $store, null, $clock);
        };
        // Step 4's commit waits until the real clock has left the second the judgement was read in (ruling R18).
        $s->wait = $seams['wait'] ?? function (int $second): void { while (time() <= $second) usleep(20000); };
        $s->nolock = !empty($seams['nolock']);
        return $s;
    }

    private function now(): int
    {
        return (int)($this->clock)();
    }

    // ── the run ────────────────────────────────────────────────────────────────────────────────────────────────

    /** @return array{sent:int, uncertain:int, failed:int, skipped:int, deferred:int, handsets:int, code:string} */
    public function runOnce(?int $deadline): array
    {
        $this->run = ['sent' => 0, 'uncertain' => 0, 'failed' => 0, 'skipped' => 0, 'deferred' => 0, 'handsets' => 0, 'code' => ''];
        // 1. the budget: at most 35 s, never past master's deadline
        $t0 = $this->now();
        $until = $t0 + JobBudget::seconds(self::RUN_S, $deadline, $t0);
        // 2. preconditions, on the job's configuration
        $why = $this->precondition($this->config);
        if ($why !== '') return $this->end($why);
        // 3. the lock, then the configuration read again (IO-8)
        $fp = null;
        if (!$this->nolock) {
            $fp = @fopen(rtrim($this->dataDir, '/') . '/' . self::LOCK_FILE, 'c');
            if ($fp === false || !flock($fp, LOCK_EX | LOCK_NB)) {
                if (is_resource($fp)) fclose($fp);
                return $this->end('busy');
            }
        }
        try {
            $c = $this->read();
            if (!OfferSwitch::live($c, $this->dataDir)) return $this->end('offers_off');
            if (!WaSendBudget::on($c, $this->dataDir)) return $this->end('ledger_off');
            $budget = ($this->budgetOf)($c, $this->dataDir, $this->store, $this->clock);
            // 4. the stale sweep: a run that cannot complete it ends here
            try {
                $this->sweep($budget);
            } catch (\Throwable $e) {
                return $this->end('sweep_failed');
            }
            // 5. the live-since guard (O-D19)
            $this->liveSinceGuard($c);
            // 6. short on time: only the sweep and the guard
            if (JobBudget::seconds(self::RUN_S, $deadline, $this->now()) < self::SHORT_S) return $this->end('');
            // 7. the reply and STOP marks
            OfferStore::marksPass($this->pdo, \TenantProfile::current($c, $this->dataDir), $this->now(), self::MARKS_PER_RUN);
            // 8. expiry and completion; 9. the stop rate
            $this->expireAndComplete($c);
            $this->stopRate($c);
            // 10. the window (the run's pre-filter); 11. the reminder gate (ruling R8)
            if (!OfferWindow::open($c, $this->now())) return $this->end('');
            $schedule = $this->schedule();
            if (!ReminderDay::done($schedule, $c, $this->dataDir, $this->now())) return $this->end('reminders_pending');
            // 12. the handsets due, one recipient each while a send may still start
            $offer = OfferStore::live($this->pdo);
            if ($offer === null || (string)$offer['state'] !== 'running') return $this->end('');
            $image = null;
            if ((string)($offer['image_file'] ?? '') !== '') {
                $p = OfferImage::payload($this->dataDir, $offer);
                if ($p === null || $p === '') return $this->end('image_changed');
                $image = ['payload' => $p, 'mime' => (string)$offer['image_mime']];
            }
            foreach ($this->handsetsDue((int)$offer['id']) as $h) {
                if (!JobBudget::mayStart($until, self::SEND_S, $this->now())) break;
                $this->run['handsets']++;
                $stop = $this->sendNextOn($offer, $h, $schedule, $image);
                if ($stop !== null) return $this->end($stop);
            }
            return $this->end('');
        } catch (\Throwable $e) {
            return $this->end('error');
        } finally {
            if (is_resource($fp)) { flock($fp, LOCK_UN); fclose($fp); }
        }
    }

    /** Step 2: '' or the fixed code the run returns with. */
    private function precondition(array $config): string
    {
        if (!OfferSwitch::live($config, $this->dataDir)) return 'offers_off';
        if (dn_tz($config) !== self::TZ) return 'zone';
        if (!self::partF($this->pdo)) return 'partf_missing';
        foreach (OfferSchema::TABLES as $t) {
            $q = $this->pdo->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ?");
            $q->execute([$t]);
            if ($q->fetchColumn() === false) return 'schema_missing';
        }
        return '';
    }

    /** Part F's pieces (§H.4.14): loadable, with their tables. Nothing of Part V (ruling R3). */
    public static function partF(\PDO $pdo): bool
    {
        foreach ([['WaSendBudget', 'claim'], ['WaSendBudget', 'settle'], ['WaSendBudget', 'record'], ['WaSendBudget', 'handset'],
                  ['QuoteChase', 'offerHolds'], ['QuoteChase', 'offerHold'], ['HumanTakeover', 'phonesSince'],
                  ['HumanTakeover', 'phoneSince'], ['ReminderDay', 'done'], ['JobBudget', 'seconds'], ['JobBudget', 'mayStart']] as [$c, $m]) {
            if (!class_exists($c) || !method_exists($c, $m)) return false;
        }
        foreach (['wa_auto_sends', 'wa_seen_marks'] as $t) {
            $q = $pdo->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ?");
            $q->execute([$t]);
            if ($q->fetchColumn() === false) return false;
        }
        return true;
    }

    /** The files, read now: never the job's configuration, never the store's cached kyc_config.json (IO-8, ruling R19). */
    private function read(): array
    {
        return PluginConfig::read($this->root, $this->dataDir);
    }

    private function schedule(): array
    {
        try {
            return (array)($this->store->load('master_schedule.json') ?? []);
        } catch (\Throwable $e) {
            return [];   // unreadable: ReminderDay then holds offers until reminder_until_hour
        }
    }

    private function end(string $code): array
    {
        $this->run['code'] = $code;
        if ($code !== '') $this->sayOnceToday($code);
        return $this->run;
    }

    /** A fixed code into the plugin log, once per Kampala day. */
    private function sayOnceToday(string $code): void
    {
        try {
            $f = rtrim($this->dataDir, '/') . '/' . self::SAID_FILE;
            $day = WaSendBudget::localDay($this->now());
            $said = is_file($f) ? (json_decode((string)@file_get_contents($f), true) ?: []) : [];
            if (($said[$code] ?? '') === $day) return;
            $said[$code] = $day;
            @file_put_contents($f, json_encode($said), LOCK_EX);
            PluginLog::write('offers_send', $code);
        } catch (\Throwable $e) { /* a log line is never worth a send */ }
    }

    // ── steps 4, 5, 8 and 9 ────────────────────────────────────────────────────────────────────────────────────

    /** Step 4: claims older than 15 minutes, and tests left sending as long. */
    private function sweep($budget): void
    {
        $now = $this->now();
        $at = UtcClock::stamp($now);
        $stale = UtcClock::stamp($now - self::STALE_S);
        // the gateway was never called: back to queued
        $this->pdo->prepare("UPDATE wa_offer_recipients SET state = 'queued', not_before = :now
                              WHERE state = 'claimed' AND claimed_at < :stale AND ledger_claim IS NULL")
            ->execute([':now' => $at, ':stale' => $stale]);
        // the message may have gone: settled uncertain, never sent again (ruling R2)
        $st = $this->pdo->prepare("SELECT id, ledger_claim FROM wa_offer_recipients
                                    WHERE state = 'claimed' AND claimed_at < ? AND ledger_claim IS NOT NULL");
        $st->execute([$stale]);
        foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $budget->settle((int)$r['ledger_claim'], 'failed', ['maybe_sent' => true]);
            $this->pdo->prepare("UPDATE wa_offer_recipients SET state = 'uncertain', reason = 'stale_claim', sent_at = claimed_at,
                                        settled_at = ? WHERE id = ? AND state = 'claimed'")
                ->execute([$at, (int)$r['id']]);
        }
        $this->pdo->prepare("UPDATE wa_offer_tests SET state = 'uncertain', settled_at = ? WHERE state = 'sending' AND started_at < ?")
            ->execute([$at, $stale]);
    }

    /** Step 5: a running offer whose latest Start or Resume is not later than offers_live_since is paused (O-D19). */
    private function liveSinceGuard(array $c): void
    {
        $since = (int)OfferSwitch::liveSince($c);
        foreach ($this->pdo->query("SELECT id FROM wa_offers WHERE state = 'running'")->fetchAll(\PDO::FETCH_COLUMN) as $id) {
            if (OfferStore::lastStartOrResume($this->pdo, (int)$id) <= $since) {
                OfferStore::pause($this->pdo, (int)$id, null, 'system', $this->now(), 'paused_by_switch');
            }
        }
    }

    /** Step 8: past offers_max_days → done/expired; nothing queued or claimed left → done. */
    private function expireAndComplete(array $c): void
    {
        $max = (int)(OfferSwitch::settings($c)['offers_max_days'] ?? OfferSwitch::SETTINGS['offers_max_days'][0]);
        $now = $this->now();
        foreach ($this->pdo->query("SELECT id, state, started_at FROM wa_offers WHERE state IN ('running', 'paused')")->fetchAll(\PDO::FETCH_ASSOC) as $o) {
            if (UtcClock::parse((string)$o['started_at']) + $max * 86400 <= $now) {
                OfferStore::close($this->pdo, (int)$o['id'], 'expired', $now);
                continue;
            }
            if ((string)$o['state'] !== 'running') continue;
            $q = $this->pdo->prepare("SELECT COUNT(*) FROM wa_offer_recipients WHERE offer_id = ? AND state IN ('queued', 'claimed')");
            $q->execute([(int)$o['id']]);
            $left = (int)$q->fetchColumn();
            $q->closeCursor();                    // no read left open on this connection before it writes
            if ($left === 0) OfferStore::close($this->pdo, (int)$o['id'], 'done', $now);
        }
    }

    /** Step 9: with at least 40 sent or may-have-been-sent, STOP at or above offers_stop_pause_pct of them pauses the offer (O-D13). */
    private function stopRate(array $c): void
    {
        $pct = (int)(OfferSwitch::settings($c)['offers_stop_pause_pct'] ?? OfferSwitch::SETTINGS['offers_stop_pause_pct'][0]);
        foreach ($this->pdo->query("SELECT id FROM wa_offers WHERE state = 'running'")->fetchAll(\PDO::FETCH_COLUMN) as $id) {
            $q = $this->pdo->prepare("SELECT sum(state IN ('sent', 'uncertain')), sum(state IN ('sent', 'uncertain') AND stop_at IS NOT NULL)
                                        FROM wa_offer_recipients WHERE offer_id = ?");
            $q->execute([(int)$id]);
            [$sent, $stops] = array_map('intval', $q->fetch(\PDO::FETCH_NUM) ?: [0, 0]);
            $q->closeCursor();
            if ($sent >= self::PAUSE_MIN_SENDS && $stops * 100 >= $pct * $sent) {
                OfferStore::pause($this->pdo, (int)$id, null, 'system', $this->now(), 'paused_stop_rate', 'stops=' . $stops . ' sends=' . $sent);
            }
        }
    }

    /** Step 12: the handsets with a queued row due, oldest last offer send first. @return string[] */
    private function handsetsDue(int $offerId): array
    {
        $now = UtcClock::stamp($this->now());
        $st = $this->pdo->prepare("SELECT q.handset, (SELECT MAX(COALESCE(r.sent_at, r.claimed_at)) FROM wa_offer_recipients r
                                                        WHERE r.handset = q.handset AND r.state IN ('sent', 'uncertain', 'claimed')) AS last
                                     FROM wa_offer_recipients q
                                    WHERE q.offer_id = ? AND q.state = 'queued' AND (q.not_before IS NULL OR q.not_before <= ?)
                                    GROUP BY q.handset ORDER BY last IS NOT NULL, last, q.handset");
        $st->execute([$offerId, $now]);
        return array_map('strval', $st->fetchAll(\PDO::FETCH_COLUMN));
    }

    // ── one recipient ──────────────────────────────────────────────────────────────────────────────────────────

    /**
     * §H.4.10's sequence on one handset, ONCE: step 12 sends one recipient per handset per run (O-D8), and step 4's lock
     * is held once per handset per run (§H.4.21: under a second before each claim). A row judged away ends the handset's
     * turn as a send does: a skip is settled, a defer released to its not_before, a move re-pointed and released, and the
     * next run takes the handset's next row. Returns null to go on to the next handset, or the fixed code that ends the run.
     */
    private function sendNextOn(array $offer, string $handset, array $schedule, ?array $image): ?string
    {
        $r = $this->oneRecipient($offer, $handset, $schedule, $image);
        if ($r === 'next' || $r === 'turn') return null;   // the handset's one recipient this run: the next handset
        return $r;                                          // a code that ends the run ('' included)
    }

    /** @return string 'next' | 'turn' | an ending code ('' = end quietly) */
    private function oneRecipient(array $offer, string $handset, array $schedule, ?array $image): string
    {
        $pdo = $this->pdo;
        $offerId = (int)$offer['id'];
        // 1. the next row
        $st = $pdo->prepare("SELECT * FROM wa_offer_recipients WHERE offer_id = ? AND handset = ? AND state = 'queued'
                               AND (not_before IS NULL OR not_before <= ?) ORDER BY rank LIMIT 1");
        $st->execute([$offerId, $handset, UtcClock::stamp($this->now())]);
        $row = $st->fetch(\PDO::FETCH_ASSOC);
        $st->closeCursor();                       // a read left open would make the CAS fail once another process writes (WAL)
        if (!is_array($row)) return 'turn';
        $id = (int)$row['id'];
        // 2. the CAS claim, the per-recipient guard
        $token = bin2hex(random_bytes(8));
        $cas = $pdo->prepare("UPDATE wa_offer_recipients SET state = 'claimed', claim_token = ?, claimed_at = ?, attempt = attempt + 1
                               WHERE id = ? AND state = 'queued'");
        $cas->execute([$token, UtcClock::stamp($this->now()), $id]);
        if ($cas->rowCount() !== 1) return 'turn';
        // 3. the offer, read fresh
        $o = OfferStore::find($pdo, $offerId);
        if ($o === null || (string)$o['state'] !== 'running') {
            if ($o !== null && (string)$o['state'] === 'paused') { $this->release($id, $token, null); return ''; }
            $this->settleRow($id, $token, 'skipped', 'offer_stopped');
            return 'next';
        }
        // 4. the recipient's own read, then judge() at its moment (rulings R18, R19)
        $f = $this->read();
        if (!OfferSwitch::live($f, $this->dataDir)) { $this->release($id, $token, null); return 'offers_off'; }
        if (!WaSendBudget::on($f, $this->dataDir)) { $this->release($id, $token, null); return 'ledger_off'; }
        if (OfferStore::lastStartOrResume($pdo, $offerId) <= (int)OfferSwitch::liveSince($f)) { $this->release($id, $token, null); return 'offers_off'; }
        if (!OfferWindow::open($f, $this->now())) { $this->release($id, $token, null); return ''; }
        if (!ReminderDay::done($schedule, $f, $this->dataDir, $this->now())) { $this->release($id, $token, null); return 'reminders_pending'; }
        $fb = ($this->budgetOf)($f, $this->dataDir, $this->store, $this->clock);
        $gw = ($this->gatewayOf)($pdo, $f, $this->dataDir, $this->store);
        $judgeCfg = ['offers_warm_days' => (int)$o['warm_days'], 'offers_recent_days' => (int)$o['recent_days']] + $f;
        $dayMax = (int)(OfferSwitch::settings($f)['offers_daily_max_per_handset'] ?? 0);
        $j = $this->judgeAndWrite($row, $token, $judgeCfg, $gw, $dayMax);
        if ($j === null) return '';                               // CAS lost, or the book's lock not had: nothing claimed
        if ($j['outcome'] === 'skip') { $this->run['skipped']++; return 'next'; }
        if ($j['outcome'] === 'defer' || $j['outcome'] === 'move') { $this->run['deferred']++; return 'next'; }
        $row = array_merge($row, ['judged_at' => $j['judged_at'], 'day_max' => $dayMax]);

        // 5. the handset today: the offers ceiling, and three certain failures in a row
        $now = $this->now();
        $tomorrow = OfferWindow::nextOpen($f, OfferWindow::nextDayStart($now));
        if ($this->todayOnHandset($handset, $id, $now) >= $dayMax || $this->failingToday($handset, $now)) {
            $this->release($id, $token, $tomorrow);
            $this->run['deferred']++;
            return 'turn';
        }
        // 6. the ledger claim, on this recipient's own ledger (ruling R19): the stable ref, the phone, the in-flight bound
        $ref = 'offer:' . $offerId . ':' . $id;
        $a = $fb->claim((string)$row['handset'], 'offer', $ref, ['phone' => (string)$row['phone'], 'in_flight_s' => self::IN_FLIGHT_S]);
        if (empty($a['ok'])) return $this->onDefer($id, $token, $a, $f, $now);
        $h = (int)$a['claim'];
        // 7. the claim and the variant, recorded before the send
        $words = OfferStore::wordingsOf($o);
        $v = OfferText::variantFor(count($words), $this->priorOnHandset($offerId, $handset));
        $rec = $pdo->prepare("UPDATE wa_offer_recipients SET ledger_claim = ?, variant = ? WHERE id = ? AND state = 'claimed' AND claim_token = ?");
        $rec->bindValue(1, $h, \PDO::PARAM_INT);
        $rec->bindValue(2, $v, \PDO::PARAM_INT);
        $rec->bindValue(3, $id, \PDO::PARAM_INT);
        $rec->bindValue(4, $token);
        $rec->execute();
        if ($rec->rowCount() !== 1) {
            $fb->settle($h, 'failed');
            $this->release($id, $token, null);
            return 'turn';
        }
        // 8. the send; the echo is claimed inside the gateway, immediately after the POST returns
        $text = OfferText::render($words[$v], (string)$o['stop_line']);
        $res = $gw->send(new OfferMessage((string)$row['channel'], (string)$row['phone'], $text, $image, 'offer.send', ContactOptOut::CLASS_PROACTIVE));
        $sentAt = UtcClock::stamp($this->now());
        // 9. the ledger's settle (never throws into the sender)
        if ($res->outcome === OfferSendResult::SENT) {
            $fb->settle($h, 'sent', ['wa_message_id' => $res->messageId]);
        } elseif ($res->outcome === OfferSendResult::MAYBE_SENT) {
            $fb->settle($h, 'failed', ['maybe_sent' => true, 'wa_message_id' => $res->messageId]);
        } else {
            $fb->settle($h, 'failed');
        }
        // 10. the row
        $this->settleSend($id, $token, $res, $sentAt, $now);
        // 11. the message, in its conversation (SENT only): role agent, 'DishNet Plugin', event_key wa_offer (O-D12)
        if ($res->outcome === OfferSendResult::SENT) {
            try {
                (new ConversationService($this->dataDir, $pdo))->storeMessage((int)$row['conversation_id'], [
                    'direction' => 'out', 'role' => 'agent', 'body' => $text, 'media_type' => $image !== null ? 'image' : null,
                    'agent_name' => 'DishNet Plugin', 'event_key' => 'wa_offer', 'wa_message_id' => $res->messageId,
                    'sent_at' => $sentAt,
                    'metadata' => ['source' => 'offer', 'offer_id' => $offerId, 'recipient_id' => $id, 'variant' => $v],
                ]);
            } catch (\Throwable $e) { /* the customer has it; bookkeeping changes no outcome */ }
        }
        return 'turn';
    }

    /**
     * Step 4's transaction: judged at its moment under the book's write lock, the outcome written under the row's CAS,
     * committed only once the clock has left the judgement's second. Null when the CAS changed nothing or the lock was not
     * had: the row stays claimed with no ledger claim (the 2–6 crash window).
     */
    private function judgeAndWrite(array $row, string $token, array $cfg, OfferGateway $gw, int $dayMax): ?array
    {
        $pdo = $this->pdo;
        $id = (int)$row['id'];
        try {
            if (!OfferStore::begin($pdo)) return null;
        } catch (\Throwable $e) {
            return null;
        }
        try {
            $real = time();
            $judged = UtcClock::stamp($this->now());
            $stored = $this->store->load('retailers.json');
            $kyc = $this->store->load('kyc_config.json');
            $j = OfferAudience::judge($pdo, $cfg, $this->dataDir, (array)$stored, (array)$kyc, $row, $judged, $gw);
            if ($j['outcome'] === 'skip') {
                $st = $pdo->prepare("UPDATE wa_offer_recipients SET state = 'skipped', reason = ?, judged_at = ?, settled_at = ?
                                      WHERE id = ? AND state = 'claimed' AND claim_token = ?");
                $st->execute([$j['code'], $judged, $judged, $id, $token]);
            } elseif ($j['outcome'] === 'defer') {
                $st = $pdo->prepare("UPDATE wa_offer_recipients SET state = 'queued', not_before = ?, reason = ?, judged_at = ?
                                      WHERE id = ? AND state = 'claimed' AND claim_token = ?");
                $st->execute([UtcClock::stamp((int)$j['retry_at']), $j['code'], $judged, $id, $token]);
            } elseif ($j['outcome'] === 'move') {
                $st = $pdo->prepare("UPDATE wa_offer_recipients SET state = 'queued', not_before = NULL, conversation_id = ?, channel = ?,
                                            handset = ?, reason = 'moved', judged_at = ? WHERE id = ? AND state = 'claimed' AND claim_token = ?");
                $st->execute([(int)$j['conversation_id'], (string)$j['channel'], (string)$j['handset'], $judged, $id, $token]);
            } else {
                $st = $pdo->prepare("UPDATE wa_offer_recipients SET judged_at = ?, day_max = ?
                                      WHERE id = ? AND state = 'claimed' AND claim_token = ?");
                $st->execute([$judged, $dayMax, $id, $token]);
            }
            if ($st->rowCount() !== 1) { $pdo->exec('ROLLBACK'); return null; }
            ($this->wait)($real);                                     // every row stored in this second was in judge()'s read
            $pdo->exec('COMMIT');
            return $j + ['judged_at' => $judged];
        } catch (\Throwable $e) {
            try { $pdo->exec('ROLLBACK'); } catch (\Throwable $e2) { /* none open */ }
            if ($e instanceof \RuntimeException && strpos($e->getMessage(), 'audience_unreadable:') === 0) {
                $this->sayOnceToday('audience_unreadable');
            }
            return null;
        }
    }

    /** §H.4.10's table: the ledger's answer at step 6, by code. */
    private function onDefer(int $id, string $token, array $a, array $f, int $now): string
    {
        $code = (string)($a['defer'] ?? '');
        $retry = $a['retry_at'] ?? null;
        if (in_array($code, self::DEFER_CODES, true) && is_int($retry)) {
            $this->release($id, $token, $retry);
            $this->run['deferred']++;
            return 'turn';
        }
        if ($code === 'phone_today') {
            $this->release($id, $token, OfferWindow::nextOpen($f, OfferWindow::nextDayStart($now)));
            $this->run['deferred']++;
            return 'turn';
        }
        if ($code === 'already_sent') {
            $this->pdo->prepare("UPDATE wa_offer_recipients SET state = 'uncertain', reason = 'ledger_already_sent', sent_at = claimed_at,
                                        settled_at = ? WHERE id = ? AND state = 'claimed' AND claim_token = ?")
                ->execute([UtcClock::stamp($now), $id, $token]);
            $this->run['uncertain']++;
            return 'turn';
        }
        if (in_array($code, self::SKIP_CODES, true)) {
            $this->settleRow($id, $token, 'skipped', $code);
            $this->run['skipped']++;
            return 'turn';
        }
        // ledger_error, ledger_off, zone, no_instance, and any code not named: the row released unchanged, the run ends
        $this->release($id, $token, null);
        return preg_match('/^[a-z_]{1,40}$/', $code) && !in_array($code, ['next', 'turn'], true) ? $code : 'ledger_unknown';
    }

    /** Step 10: the row, by the gateway's answer, under its CAS. */
    private function settleSend(int $id, string $token, OfferSendResult $res, string $sentAt, int $now): void
    {
        $pdo = $this->pdo;
        $w = ' WHERE id = :id AND state = \'claimed\' AND claim_token = :t';
        $at = UtcClock::stamp($this->now());
        if ($res->outcome === OfferSendResult::SENT) {
            $pdo->prepare("UPDATE wa_offer_recipients SET state = 'sent', sent_at = :s, settled_at = :at, wa_message_id = :m, echo = :e" . $w)
                ->execute([':s' => $sentAt, ':at' => $at, ':m' => $res->messageId, ':e' => $res->echo, ':id' => $id, ':t' => $token]);
            $this->run['sent']++;
        } elseif ($res->outcome === OfferSendResult::MAYBE_SENT) {
            $pdo->prepare("UPDATE wa_offer_recipients SET state = 'uncertain', reason = :r, sent_at = :s, settled_at = :at,
                                  wa_message_id = :m, echo = :e" . $w)
                ->execute([':r' => $res->code, ':s' => $sentAt, ':at' => $at, ':m' => $res->messageId === '' ? null : $res->messageId,
                           ':e' => $res->echo, ':id' => $id, ':t' => $token]);
            $this->run['uncertain']++;
        } elseif ($res->outcome === OfferSendResult::OPTED_OUT) {
            $this->settleRow($id, $token, 'skipped', 'opted_out');
            $this->run['skipped']++;
        } elseif ($res->outcome === OfferSendResult::REFUSED && $res->transient) {
            $this->release($id, $token, $now + self::RELEASE_S);
            $this->run['deferred']++;
        } elseif ($res->outcome === OfferSendResult::REFUSED) {
            $this->settleRow($id, $token, 'skipped', 'refused:' . $res->code);
            $this->run['skipped']++;
        } else {
            $this->settleRow($id, $token, 'failed', $res->code === '' ? 'send_failed' : $res->code);   // no automatic retry
            $this->run['failed']++;
        }
    }

    private function settleRow(int $id, string $token, string $state, string $reason): void
    {
        $this->pdo->prepare("UPDATE wa_offer_recipients SET state = ?, reason = ?, settled_at = ? WHERE id = ? AND state = 'claimed' AND claim_token = ?")
            ->execute([$state, substr($reason, 0, 60), UtcClock::stamp($this->now()), $id, $token]);
    }

    /** Back to queued, due at $notBefore (null: due now). */
    private function release(int $id, string $token, ?int $notBefore): void
    {
        $this->pdo->prepare("UPDATE wa_offer_recipients SET state = 'queued', not_before = ? WHERE id = ? AND state = 'claimed' AND claim_token = ?")
            ->execute([$notBefore === null ? null : UtcClock::stamp($notBefore), $id, $token]);
    }

    /** The handset's offers today other than this row: sent, may have been sent, or in flight (step 5). */
    private function todayOnHandset(string $handset, int $exceptId, int $now): int
    {
        $st = $this->pdo->prepare("SELECT COUNT(*) FROM wa_offer_recipients WHERE handset = ? AND id <> ? AND state IN ('sent', 'uncertain', 'claimed')
                                     AND COALESCE(sent_at, claimed_at) >= ?");
        $st->execute([$handset, $exceptId, UtcClock::stamp(OfferWindow::dayStart($now))]);
        return (int)$st->fetchColumn();
    }

    /** Three certain failures in a row on the handset today: handset_failing. */
    private function failingToday(string $handset, int $now): bool
    {
        $st = $this->pdo->prepare("SELECT state FROM wa_offer_recipients WHERE handset = ? AND settled_at >= ?
                                     AND state IN ('sent', 'uncertain', 'failed') ORDER BY settled_at DESC, id DESC LIMIT 3");
        $st->execute([$handset, UtcClock::stamp(OfferWindow::dayStart($now))]);
        $s = $st->fetchAll(\PDO::FETCH_COLUMN);
        return count($s) === 3 && $s === ['failed', 'failed', 'failed'];
    }

    /** The offer's earlier sends (and claims) on this handset that already chose a wording: for variants in turn. */
    private function priorOnHandset(int $offerId, string $handset): int
    {
        $st = $this->pdo->prepare('SELECT COUNT(*) FROM wa_offer_recipients WHERE offer_id = ? AND handset = ? AND variant IS NOT NULL');
        $st->execute([$offerId, $handset]);
        return (int)$st->fetchColumn();
    }

    // ── the admin's test (O-D10) ───────────────────────────────────────────────────────────────────────────────

    /**
     * One test of one wording of a draft to the admin's own phone of record, never a typed number; class staff; recorded
     * after the send as ledger kind `other` (ref offer_test:<id>), never claimed. It decides on a fresh read taken
     * immediately before it, on which the screen and the ledger must both be on (ruling R19), and records that moment as
     * the test's started_at. Not stored in any conversation; no window or reminder gate (it goes to our own staff).
     *
     * @return array{ok:bool, code:string, test_id:int}
     */
    public function sendTest(int $offerId, int $variant, string $channel, array $adminRow): array
    {
        $no = function (string $code, int $tid = 0): array { return ['ok' => false, 'code' => $code, 'test_id' => $tid]; };
        $o = OfferStore::find($this->pdo, $offerId);
        if ($o === null || (string)$o['state'] !== 'draft') return $no('not_draft');
        $t0 = $this->now();
        $f = $this->read();
        if (!OfferSwitch::screen($f, $this->dataDir)) return $no('offers_off');
        if (!WaSendBudget::on($f, $this->dataDir)) return $no('ledger_off');
        $words = OfferStore::wordingsOf($o);
        if (!isset($words[$variant])) return $no('bad_variant');
        $tenant = \TenantProfile::current($f, $this->dataDir);
        $intl = \StaffDirectory::phoneOf($adminRow, $tenant);
        if ($intl === null) return $no('no_phone_of_record');
        $gw = ($this->gatewayOf)($this->pdo, $f, $this->dataDir, $this->store);
        $handset = $gw->handsetFor($channel);
        if ($handset === '') return $no('no_instance');
        $cr = $gw->refusal($channel, '');                       // the number itself may send; the recipient is our own staff
        if ($cr !== '') return $no('channel_refused:' . $cr);
        $image = null;
        if ((string)($o['image_file'] ?? '') !== '') {
            $p = OfferImage::payload($this->dataDir, $o);
            if ($p === null || $p === '') return $no('image_changed');
            $image = ['payload' => $p, 'mime' => (string)$o['image_mime']];
        }
        $at = UtcClock::stamp($this->now());
        $this->pdo->prepare("UPDATE wa_offer_tests SET state = 'uncertain', settled_at = ? WHERE offer_id = ? AND state = 'sending' AND started_at < ?")
            ->execute([$at, $offerId, UtcClock::stamp($this->now() - self::STALE_S)]);
        $q = $this->pdo->prepare("SELECT COUNT(*) FROM wa_offer_tests WHERE offer_id = ? AND state = 'sending' AND started_at >= ?");
        $q->execute([$offerId, UtcClock::stamp($this->now() - self::TEST_BUSY_S)]);
        $busy = (int)$q->fetchColumn();
        $q->closeCursor();
        if ($busy > 0) return $no('test_in_progress');
        $this->pdo->prepare("INSERT INTO wa_offer_tests (offer_id, content_hash, variant, staff_id, channel, handset, state, started_at)
                             VALUES (?, ?, ?, ?, ?, ?, 'sending', ?)")
            ->execute([$offerId, (string)$o['content_hash'], $variant, (int)($adminRow['id'] ?? 0), $channel, $handset, UtcClock::stamp($t0)]);
        $tid = (int)$this->pdo->lastInsertId();
        $res = $gw->send(new OfferMessage($channel, ltrim($intl, '+'), OfferText::render($words[$variant], (string)$o['stop_line']),
                                          $image, 'offer.test', ContactOptOut::CLASS_STAFF));
        $budget = ($this->budgetOf)($f, $this->dataDir, $this->store, $this->clock);
        $sent = $res->outcome === OfferSendResult::SENT;
        $maybe = $res->outcome === OfferSendResult::MAYBE_SENT;
        $ledger = $budget->record($handset, 'other', 'offer_test:' . $tid, $sent ? 'sent' : 'failed',
                                  ['maybe_sent' => $maybe, 'wa_message_id' => $res->messageId]);
        $state = $sent ? 'sent' : ($maybe ? 'uncertain' : 'failed');
        $reason = $sent ? null : ($res->outcome === OfferSendResult::REFUSED ? 'refused:' . $res->code : ($res->code === '' ? 'send_failed' : $res->code));
        $this->pdo->prepare("UPDATE wa_offer_tests SET state = ?, reason = ?, ledger = ?, echo = ?, wa_message_id = ?, settled_at = ?
                              WHERE id = ? AND state = 'sending'")
            ->execute([$state, $reason, (string)$ledger, $res->echo, $res->messageId === '' ? null : $res->messageId,
                       UtcClock::stamp($this->now()), $tid]);
        return ['ok' => $sent, 'code' => $sent ? '' : (string)$reason, 'test_id' => $tid];
    }
}
