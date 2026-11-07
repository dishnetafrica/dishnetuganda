<?php
declare(strict_types=1);

require_once __DIR__ . '/StaffJobsGate.php';
require_once __DIR__ . '/UtcClock.php';

/**
 * OfferSwitch — whether Part O's offers exist on this install, and the settings they run under (5.18.104 P6, root
 * docs/73 §H.4.6; contract C1; rulings R5, R6, R13).
 *
 * The pure readers the engine, the screen and the menu use (uganda, screen, live, settings, invalidSettings, liveSince,
 * declaredKeys), and the four callables P6's rows of the ladder dispatch to, with ruling R13's signatures:
 *
 *   preconditions($ro, $config, $dataDir, $step)  step 11 (offers-test): P-O1-1 and P-O1-3 … P-O1-9; step 12
 *                                                  (offers-live): Uganda and every setting in range. P-O1-2 (Needs and
 *                                                  ORDER) and step 12's Needs are the ladder engine's (ruling R6)
 *   checkTest($ro, $config, $dataDir, $now)       --check offers-test: C-O1-1 … C-O1-6
 *   checkLive($ro, $config, $dataDir, $now)       --check offers-live: C-O2-1 … C-O2-12
 *   offHook($rw, $config, $dataDir, $step, $why)   rows 11 and 12, before their keys clear (rulings R7, R12)
 *
 *   uganda()  StaffJobsGate::applies($config, $dataDir): false on any doubt (South Sudan, an unreadable profile)
 *   screen()  uganda() and offers_test_enabled
 *   live()    screen() and offers_live_enabled and offers_live_since a valid UTC stamp and every setting in range
 *
 * A stored setting out of range makes live() false, and the screen says so: it is never clamped and never quietly
 * replaced by its default. A precondition and a check read the book only through the tool's read-only $ro, and the hook
 * writes only through the tool's $rw, its ledger WaSendBudget::onConnection($rw, …); none opens a connection (ruling
 * R13). A check judges each past act at the moment it was decided, from what could be read then, under the limits
 * recorded with it, and prints what its maker could not know as a count, never a FAIL (ruling R18). Output is counts,
 * handset labels, ids and fixed codes; never a phone, a name or a wording. Only the readers are loaded with this file
 * (the menu requires it): the callables load what they use. PHP 7.4.
 */
final class OfferSwitch
{
    /** The two switches, written only by tools/sales_followup.php (steps 11 and 12, ruling R5). */
    const SWITCHES = ['offers_test_enabled', 'offers_live_enabled'];
    /** Step 12's stamp: the moment offers-live was last switched on (O-D19). */
    const LIVE_SINCE = 'offers_live_since';
    /** The six settings: key => [default, min, max] (§H.4.6). offers_daily_max_per_handset is also ≤ the ledger's daily cap. */
    const SETTINGS = [
        'offers_warm_days'             => [30, 7, 30],
        'offers_recent_days'           => [7, 7, 60],
        'offers_daily_max_per_handset' => [20, 1, 60],
        'offers_on_owned_numbers'      => [0, 0, 1],
        'offers_stop_pause_pct'        => [5, 1, 20],
        'offers_max_days'              => [14, 1, 30],
    ];
    /** Part F's daily cap per handset (C3), which offers' own ceiling may never exceed. */
    const LEDGER_DAILY_CAP_DEFAULT = 60;

    /** The literal zone every hour and day a check judges is read in (rulings R8, R18). */
    public const TZ = 'Africa/Kampala';
    /** The sender's lock, which the off-hook takes under $dataDir (§H.4.6). */
    const LOCK_FILE = 'offers_send.lock';
    /** TEST SEAM: how long the off-hook waits for the sender's lock (D: up to 70 s), and how often it tries. */
    public static $lockWaitS = 70;
    public static $lockPollUs = 200000;

    /** The constants of §H.4.6 the checks apply. */
    const STALE_S = 900;            // a claim, or a test left sending, this old is stale
    const IN_FLIGHT_S = 50;         // O-D9: the bound every offer claim carries
    const RUN_S = 35;               // a run's claims follow its completed sweep by at most this
    const TEST_VALID_S = 604800;    // a test proves its wording for 7 days (C-O1-1)
    const ECHO_S = 259200;          // evo_webhook_seen is pruned after 72 h
    const HOLD_48H_S = 172800;
    const PERSON_72H_S = 259200;
    const HUMAN_SPAN_S = 300;       // C-O2-8: a person's reply within 5 minutes after a send
    const ORPHAN_S = 900;           // Part F's orphan rule (§H.2.5.3 step 3)
    const MARKS_DAYS = 30;          // C-O1-5's look-back
    /** The ledger's details for a row its own sweep settled (Part F §H.2.5.3 steps 1 and 3a). */
    const SWEPT = ['stale_orphan', 'stale'];
    /** C-O2-12: the other customer-proactive kinds an offer is paired with. */
    const PAIRED_KINDS = ['quote', 'enquiry', 'reminder', 'winback'];

    public static function uganda(array $config, ?string $dataDir): bool
    {
        return StaffJobsGate::applies($config, $dataDir);
    }

    public static function screen(array $config, ?string $dataDir): bool
    {
        return self::uganda($config, $dataDir) && self::on($config['offers_test_enabled'] ?? null);
    }

    public static function live(array $config, ?string $dataDir): bool
    {
        return self::screen($config, $dataDir)
            && self::on($config['offers_live_enabled'] ?? null)
            && self::liveSince($config) !== null
            && self::invalidSettings($config) === [];
    }

    /** offers_live_since as a unix time, or null when it is absent or is not a readable UTC stamp. */
    public static function liveSince(array $config): ?int
    {
        $v = $config['offers_live_since'] ?? null;
        if (is_int($v)) return $v > 0 ? $v : null;
        if (!is_string($v) || trim($v) === '') return null;
        $s = trim($v);
        $t = preg_match('/^\d{9,11}$/', $s) ? (int)$s : UtcClock::parse($s);
        return $t > 0 ? $t : null;
    }

    /**
     * The six settings: key => the value in force, or null when the stored value is out of range or unreadable.
     *
     * @return array<string,int|null>
     */
    public static function settings(array $config): array
    {
        $out = [];
        $cap = self::intOf($config['wa_send_daily_cap'] ?? null, self::LEDGER_DAILY_CAP_DEFAULT);
        foreach (self::SETTINGS as $k => [$def, $min, $max]) {
            $v = self::intOf($config[$k] ?? null, $def);
            if ($v === null || $v < $min || $v > $max) { $out[$k] = null; continue; }
            if ($k === 'offers_daily_max_per_handset' && ($cap === null || $v > $cap)) { $out[$k] = null; continue; }
            $out[$k] = $v;
        }
        return $out;
    }

    /** The settings whose stored value is out of range ([] = every setting usable). @return string[] */
    public static function invalidSettings(array $config): array
    {
        return array_keys(array_filter(self::settings($config), function ($v) { return $v === null; }));
    }

    /** The two switches, the stamp and the six settings: P6's part of the ladder's one key table (ruling R5). */
    public static function declaredKeys(): array
    {
        return array_merge(self::SWITCHES, [self::LIVE_SINCE], array_keys(self::SETTINGS));
    }

    /** A switch reads on: true, 1, or '1' / 'true' / 'on' / 'yes' in any case. */
    public static function on($v): bool
    {
        if (is_bool($v)) return $v;
        if (is_int($v)) return $v === 1;
        return is_string($v) && in_array(strtolower(trim($v)), ['1', 'true', 'on', 'yes'], true);
    }

    // ── the ladder's callables (ruling R13) ────────────────────────────────────────────────────────────────────

    /**
     * Step 11's own precondition (P-O1-1, P-O1-3 … P-O1-9) or step 12's (Uganda and every setting in range). Reads the
     * book only through $ro; writes nothing. P-O1-2 — the ledger with L-ON, step 3 with Q1-ON, ORDER — and step 12's
     * Needs (step 11 on, --check offers-test PASS now) are the ladder engine's (ruling R6).
     *
     * @return array{ok:bool, code:?string, lines:list<string>}
     */
    public static function preconditions(\PDO $ro, array $config, string $dataDir, string $step): array
    {
        $no = function (string $code, array $lines): array { return ['ok' => false, 'code' => $code, 'lines' => $lines]; };
        if ($step !== 'offers-test' && $step !== 'offers-live') return $no('unknown_step', []);
        if (!self::uganda($config, $dataDir)) return $no('not_uganda', ['P-O1-1 Uganda: no']);
        $lines = ['P-O1-1 Uganda: yes'];
        if ($step === 'offers-live') {
            $bad = self::invalidSettings($config);
            if ($bad !== []) return $no('settings_out_of_range', array_merge($lines, ['settings out of range: ' . implode(', ', $bad)]));
            $lines[] = 'every setting in range';
            return ['ok' => true, 'code' => null, 'lines' => $lines];
        }
        self::loadEngine();
        $own = self::ownChecks($ro, $config, $dataDir);          // P-O1-3, P-O1-4, P-O1-5, P-O1-9
        $lines = array_merge($lines, $own['lines']);
        if ($own['code'] !== '') return $no($own['code'], $lines);
        $m = self::masterCheck(dirname(__DIR__));                // P-O1-6
        if ($m !== '') return $no('master_lines', array_merge($lines, ['P-O1-6 cron/master.php: ' . $m]));
        $lines[] = 'P-O1-6 cron/master.php: ruling R1\'s four lines, offers_send last';
        try {
            $rows = OfferAudience::storedRows($ro);              // P-O1-7, the read the preview is given
        } catch (\Throwable $e) {
            return $no('accounts_unreadable', array_merge($lines, ['P-O1-7 retailers.json: unreadable']));
        }
        $admins = self::adminsWithPhone($rows['retailers'], $config, $dataDir);
        if ($admins < 1) return $no('no_admin_phone', array_merge($lines, ['P-O1-7 active admins with a phone: 0']));
        $lines[] = 'P-O1-7 active admins with a phone: ' . $admins;
        $running = (int)self::one($ro, "SELECT COUNT(*) FROM wa_offers WHERE state = 'running'");   // P-O1-8
        if ($running > 0) return $no('offer_running', array_merge($lines, ['P-O1-8 running offers: ' . $running]));
        $lines[] = 'P-O1-8 running offers: 0';
        return ['ok' => true, 'code' => null, 'lines' => $lines];
    }

    /**
     * --check offers-test (C-O1-1 … C-O1-6): a recorded test of every wording, each test judged by what its own request
     * recorded, and — until the first Start — proof that the test step reached no customer.
     *
     * @return array{status:string, counts:array<string,int>, notes:list<string>}
     */
    public static function checkTest(\PDO $ro, array $config, string $dataDir, int $now): array
    {
        if (!self::uganda($config, $dataDir)) return self::res('FAIL not_uganda', [], []);
        self::loadEngine();
        $c = [];
        $notes = [];
        $fails = [];
        // C-O1-6: P-O1-3, P-O1-4, P-O1-5 and P-O1-9 again, on the same $ro
        $own = self::ownChecks($ro, $config, $dataDir);
        if ($own['code'] !== '') return self::res('FAIL ' . $own['code'], [], $own['lines']);
        try {
            $tests = self::all($ro, 'SELECT * FROM wa_offer_tests ORDER BY id');
            // C-O1-2: every sent test's echo claimed, never stored as Team, and seen by the webhook's guard within 72 h
            foreach ($tests as $t) {
                if ((string)$t['state'] !== 'sent') continue;
                self::inc($c, 'tests sent');
                $echo = (string)($t['echo'] ?? '');
                if ($echo === 'late') { self::inc($c, 'echo_late'); $fails[] = 'echo_late'; }
                elseif ($echo !== 'claimed') { self::inc($c, 'echo_unclaimed'); $fails[] = 'echo_unclaimed'; }
                $id = (string)($t['wa_message_id'] ?? '');
                if ($id !== '' && (int)self::one($ro, "SELECT COUNT(*) FROM wa_messages WHERE wa_message_id = ? AND agent_name = 'Team'", [$id]) > 0) {
                    self::inc($c, 'echo stored as Team'); $fails[] = 'echo_team';
                }
                if (UtcClock::parse((string)$t['started_at']) >= $now - self::ECHO_S) {
                    if ($id === '' || (int)self::one($ro, "SELECT COUNT(*) FROM evo_webhook_seen WHERE message_id = ? AND event = 'offer.test'", [$id]) === 0) {
                        self::inc($c, 'echo not seen'); $fails[] = 'echo_unseen';
                    }
                } else {
                    self::inc($c, 'tests older than 72 h — not checked');
                }
            }
            // C-O1-3: each sent/uncertain test by what its own request recorded (ruling R18)
            foreach ($tests as $t) {
                $st = (string)$t['state'];
                $id = (int)$t['id'];
                if ($st === 'sending') {
                    if (UtcClock::parse((string)$t['started_at']) < $now - self::STALE_S) self::inc($c, 'tests sending, older than 15 minutes, not yet swept');
                    continue;
                }
                if ($st !== 'sent' && $st !== 'uncertain') continue;
                $ledger = $t['ledger'];
                $rowN = (int)self::one($ro, "SELECT COUNT(*) FROM wa_auto_sends WHERE kind = 'other' AND ref = ?", ['offer_test:' . $id]);
                if ($ledger === null) {
                    if ($st === 'sent') { self::inc($c, 'sent tests with no ledger answer'); $fails[] = 'test_unrecorded'; continue; }
                    self::inc($c, $rowN > 0 ? 'swept tests, with their ledger row' : 'swept tests, with no ledger row');
                    continue;
                }
                if ((string)$ledger === 'error') { self::inc($c, 'tests whose record() answered error'); continue; }
                if ((string)$ledger !== 'recorded') { self::inc($c, 'tests recorded ' . self::token((string)$ledger)); $fails[] = 'test_not_recorded'; continue; }
                $mine = (int)self::one($ro, "SELECT COUNT(*) FROM wa_auto_sends WHERE kind = 'other' AND ref = ? AND handset = ?",
                                       ['offer_test:' . $id, WaSendBudget::handset((string)$t['handset'])]);
                if ($mine === 0) { self::inc($c, 'recorded tests with no ledger row on their handset'); $fails[] = 'test_ledger_missing'; continue; }
                self::inc($c, 'tests recorded');
            }
            // C-O1-4: the test step reached no customer — judged only until the first Start (ruling R5)
            $started = (int)self::one($ro, "SELECT COUNT(*) FROM wa_offer_events WHERE action = 'started'");
            $rec = (int)self::one($ro, 'SELECT COUNT(*) FROM wa_offer_recipients');
            $led = (int)self::one($ro, "SELECT COUNT(*) FROM wa_auto_sends WHERE kind = 'offer'");
            $seen = (int)self::one($ro, "SELECT COUNT(*) FROM evo_webhook_seen WHERE event = 'offer.send'");
            if ($started === 0) {
                if ($rec > 0) $fails[] = 'recipients_before_start';
                if ($led > 0) $fails[] = 'offer_ledger_before_start';
                if ($seen > 0) $fails[] = 'offer_echo_before_start';
                $c['recipient rows'] = $rec; $c['offer ledger rows'] = $led; $c['offer.send echoes'] = $seen;
            } else {
                $notes[] = 'C-O1-4 an offer has started: ' . $rec . ' recipient rows, ' . $led . ' offer ledger rows, ' . $seen
                         . ' offer.send echoes (customer sends are --check offers-live\'s)';
            }
            // C-O1-5 (a note): what the webhook saw and could not store, over 30 days
            $since = UtcClock::stamp($now - self::MARKS_DAYS * 86400);
            $notes[] = 'C-O1-5 seen marks in, last 30 days: ' . (int)self::one($ro, "SELECT COUNT(*) FROM wa_seen_marks WHERE direction = 'in' AND seen_at >= ?", [$since])
                     . ' over ' . (int)self::one($ro, "SELECT COUNT(DISTINCT phone) FROM wa_seen_marks WHERE direction = 'in' AND phone <> '' AND seen_at >= ?", [$since]) . ' phones';
            // C-O1-1: some offer has a recorded test of each wording on its current content, within 7 days
            $complete = 0;
            foreach (self::all($ro, 'SELECT * FROM wa_offers ORDER BY id') as $o) {
                if (OfferStore::testedVariants($ro, $o, $now) === count(OfferStore::wordingsOf($o))) $complete++;
            }
            $c['offers with every wording tested'] = $complete;
        } catch (\Throwable $e) {
            return self::res('FAIL read_error', $c, array_merge($notes, ['the check could not read the book (' . get_class($e) . ')']));
        }
        if ($fails !== []) return self::res('FAIL ' . $fails[0], $c, array_merge($notes, ['failed: ' . implode(', ', array_values(array_unique($fails)))]));
        if ($complete === 0) return self::res('NOT_YET no_test', $c, $notes);
        return self::res('PASS', $c, $notes);
    }

    /**
     * --check offers-live (C-O2-1 … C-O2-12): every past send judged three ways (ruling R18) — at its moment (the ledger
     * row's claimed_at for what the claim decided, the recipient's judged_at for what judge() decided), from what could
     * be read then (every source bounded by its storage time), under the limits it applied (the ledger row's window and
     * caps, each earlier row's gap_s, the row's day_max, the offer's warm_days and recent_days). What landed after the
     * moment is a printed count, never a FAIL.
     *
     * @return array{status:string, counts:array<string,int>, notes:list<string>}
     */
    public static function checkLive(\PDO $ro, array $config, string $dataDir, int $now): array
    {
        if (!self::uganda($config, $dataDir)) return self::res('FAIL not_uganda', [], []);
        self::loadEngine();
        $c = [];
        $notes = [];
        $fails = [];
        try {
            if (OfferSchema::check($ro) !== []) return self::res('FAIL schema', [], ['OfferSchema: ' . implode(', ', OfferSchema::check($ro))]);
            if ((int)self::one($ro, "SELECT COUNT(*) FROM wa_offer_events WHERE action = 'started'") === 0) return self::res('NOT_YET no_traffic', [], []);
            $offers = [];
            foreach (self::all($ro, 'SELECT * FROM wa_offers ORDER BY id') as $o) $offers[(int)$o['id']] = $o;
            $recips = self::all($ro, 'SELECT * FROM wa_offer_recipients ORDER BY id');
            $ledger = self::all($ro, 'SELECT * FROM wa_auto_sends ORDER BY id');
            $byRef = [];
            foreach ($ledger as $l) if ((string)$l['kind'] === 'offer') $byRef[(string)$l['ref']][] = $l;
            $done = array_values(array_filter($recips, function ($r) { return in_array((string)$r['state'], ['sent', 'uncertain'], true); }));

            self::c1Counts($ro, $config, $offers, $recips, $now, $c, $notes);
            self::c2Window($done, $byRef, $c, $fails);
            self::c3Recent($done, $offers, $c, $fails);
            self::c4Ledger($done, $recips, $ledger, $byRef, $now, $c, $fails);
            self::c5DayMax($done, $ledger, $c, $fails, $notes);
            self::c6Spacing($ledger, $c, $fails);
            self::c7Unswept($recips, $now, $c, $fails);
            self::c8Echo($ro, $done, $now, $c, $fails);
            self::c9Exclusions($ro, $config, $dataDir, $done, $offers, $c, $fails);
            self::c10Warm($ro, $config, $dataDir, $done, $offers, $c, $fails);
            self::c11StopRate($ro, $config, $offers, $c, $notes);
            self::c12OnePerDay($ledger, $c, $fails);
        } catch (\Throwable $e) {
            return self::res('FAIL read_error', $c, array_merge($notes, ['the check could not read the book (' . get_class($e) . ')']));
        }
        if ($fails !== []) {
            $u = array_values(array_unique($fails));
            return self::res('FAIL ' . $u[0], $c, array_merge($notes, ['failed: ' . implode(', ', $u)]));
        }
        return self::res('PASS', $c, $notes);
    }

    /**
     * Rows 11 and 12's off-hook (rulings R7, R12, R13): run before the step's keys clear, in a plain off and in a
     * rollback. Takes the sender's lock under $dataDir (waiting up to 70 s), then in one BEGIN IMMEDIATE on $rw: every
     * running offer paused (live_slot stays 1) with an event; every claimed recipient with a ledger claim settled on
     * Part F's ledger over $rw (failed with maybe_sent: stored uncertain) and made uncertain('stale_claim'), sent_at its
     * claimed_at; one with none back to queued, not_before now; every test left sending for 15 minutes uncertain.
     * Returns its counts; throws on failure, the transaction rolled back and nothing changed.
     *
     * @return array<string,int>
     */
    public static function offHook(\PDO $rw, array $config, string $dataDir, string $step, string $why): array
    {
        if ($step !== 'offers-test' && $step !== 'offers-live') throw new \InvalidArgumentException('offHook: unknown step');
        if ($why !== 'switch' && $why !== 'rollback') throw new \InvalidArgumentException('offHook: unknown reason');
        self::loadEngine();
        $out = ['paused' => 0, 'settled_uncertain' => 0, 'already_settled' => 0, 'requeued' => 0, 'tests_uncertain' => 0];
        foreach (OfferSchema::TABLES as $t) {
            if (!self::tableExists($rw, $t)) return $out;               // 091 not applied: nothing of P6 exists to settle
        }
        $fp = @fopen(rtrim($dataDir, '/') . '/' . self::LOCK_FILE, 'c');
        if ($fp === false) throw new \RuntimeException('offHook: the sender lock could not be opened');
        $until = microtime(true) + self::$lockWaitS;
        while (!flock($fp, LOCK_EX | LOCK_NB)) {
            if (microtime(true) >= $until) { fclose($fp); throw new \RuntimeException('offHook: the sender lock was not had'); }
            usleep(self::$lockPollUs);
        }
        try {
            $ledger = WaSendBudget::onConnection($rw, $config, $dataDir);
            $now = time();
            $at = UtcClock::stamp($now);
            if ($rw->exec('BEGIN IMMEDIATE') === false) throw new \RuntimeException('offHook: the book could not be locked');
            try {
                // 1. every running offer paused, live_slot kept
                $act = $why === 'rollback' ? 'paused_by_rollback' : 'paused_by_switch';
                foreach ($rw->query("SELECT id FROM wa_offers WHERE state = 'running' ORDER BY id")->fetchAll(\PDO::FETCH_COLUMN) as $id) {
                    $st = $rw->prepare("UPDATE wa_offers SET state = 'paused', updated_at = ? WHERE id = ? AND state = 'running'");
                    $st->execute([$at, (int)$id]);
                    if ($st->rowCount() !== 1) continue;
                    OfferStore::event($rw, (int)$id, $act, null, 'system', 'step=' . $step, $now);
                    $out['paused']++;
                }
                // 2. every claimed recipient
                $claimed = $rw->query("SELECT id, ledger_claim FROM wa_offer_recipients WHERE state = 'claimed' ORDER BY id")->fetchAll(\PDO::FETCH_ASSOC);
                foreach ($claimed as $r) {
                    if ($r['ledger_claim'] === null) {
                        $st = $rw->prepare("UPDATE wa_offer_recipients SET state = 'queued', not_before = ? WHERE id = ? AND state = 'claimed'");
                        $st->execute([$at, (int)$r['id']]);
                        $out['requeued'] += $st->rowCount();
                        continue;
                    }
                    $h = (int)$r['ledger_claim'];
                    $q = $rw->prepare('SELECT outcome FROM wa_auto_sends WHERE id = ?');
                    $q->execute([$h]);
                    $prior = $q->fetchColumn();
                    $q->closeCursor();
                    if ($prior !== false && $prior !== null) $out['already_settled']++;
                    $ledger->settle($h, 'failed', ['maybe_sent' => true]);
                    $st = $rw->prepare("UPDATE wa_offer_recipients SET state = 'uncertain', reason = 'stale_claim', sent_at = claimed_at,
                                               settled_at = ? WHERE id = ? AND state = 'claimed'");
                    $st->execute([$at, (int)$r['id']]);
                    $out['settled_uncertain'] += $st->rowCount();
                }
                // 3. every test left sending for 15 minutes
                $st = $rw->prepare("UPDATE wa_offer_tests SET state = 'uncertain', settled_at = ? WHERE state = 'sending' AND started_at < ?");
                $st->execute([$at, UtcClock::stamp($now - self::STALE_S)]);
                $out['tests_uncertain'] = $st->rowCount();
                if ($rw->exec('COMMIT') === false) throw new \RuntimeException('offHook: the commit failed');
            } catch (\Throwable $e) {
                try { $rw->exec('ROLLBACK'); } catch (\Throwable $e2) { /* none open */ }
                throw $e;
            }
            return $out;
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }

    // ── the precondition's pieces ──────────────────────────────────────────────────────────────────────────────

    /** The engine's classes, loaded when a callable runs (never by the menu). */
    private static function loadEngine(): void
    {
        foreach (['timezone.php', 'TenantProfile.php', 'PhoneNumber.php', 'StaffDirectory.php', 'OfferSchema.php', 'OfferText.php',
                  'OfferGateway.php', 'EvolutionOfferGateway.php', 'OfferAudience.php', 'OfferStore.php'] as $f) {
            require_once __DIR__ . '/' . $f;
        }
        // Part F's pieces (§H.4.14): loaded where present; P-O1-4 refuses when one is missing (fail closed).
        foreach (['WaSendBudget.php', 'QuoteChase.php', 'HumanTakeover.php', 'ReminderDay.php', 'JobBudget.php'] as $f) {
            if (is_file(__DIR__ . '/' . $f)) require_once __DIR__ . '/' . $f;
        }
    }

    /** P-O1-3, P-O1-4, P-O1-5 and P-O1-9, in that order; C-O1-6 runs them again. @return array{code:string, lines:list<string>} */
    private static function ownChecks(\PDO $ro, array $config, string $dataDir): array
    {
        $lines = [];
        $s = OfferSchema::check($ro);
        if ($s !== []) return ['code' => 'schema', 'lines' => ['P-O1-3 OfferSchema: ' . implode(', ', $s)]];
        $lines[] = 'P-O1-3 OfferSchema: sound';
        $p = self::partFProbe($ro, $config, $dataDir);
        if ($p !== '') return ['code' => 'partf_missing', 'lines' => array_merge($lines, ['P-O1-4 Part F: ' . $p])];
        $lines[] = 'P-O1-4 Part F: present';
        if (dn_tz($config) !== self::TZ) return ['code' => 'zone', 'lines' => array_merge($lines, ['P-O1-5 the zone is not Kampala\'s'])];
        $lines[] = 'P-O1-5 the zone: Kampala\'s';
        $k = self::keysDeclared();
        if ($k !== '') return ['code' => 'keys_undeclared', 'lines' => array_merge($lines, ['P-O1-9 ' . $k])];
        $lines[] = 'P-O1-9 every key declared in the ladder';
        return ['code' => '', 'lines' => $lines];
    }

    /**
     * P-O1-4: Part F's pieces present, by a probe that only reads. claim, settle and record are checked as present and
     * never called; the readers are given $ro, the pure ones no connection, with an invented number, instance and time.
     * '' when every piece answers, else what is missing.
     */
    private static function partFProbe(\PDO $ro, array $config, string $dataDir): string
    {
        foreach ([['WaSendBudget', 'claim'], ['WaSendBudget', 'settle'], ['WaSendBudget', 'record'], ['WaSendBudget', 'onConnection'],
                  ['WaSendBudget', 'handset'], ['QuoteChase', 'offerHolds'], ['QuoteChase', 'offerHold'], ['HumanTakeover', 'phonesSince'],
                  ['HumanTakeover', 'phoneSince'], ['ReminderDay', 'done'], ['JobBudget', 'seconds'], ['JobBudget', 'mayStart']] as [$cl, $m]) {
            if (!class_exists($cl) || !method_exists($cl, $m)) return $cl . '::' . $m;
        }
        foreach (['wa_auto_sends', 'wa_seen_marks'] as $t) {
            if (!self::tableExists($ro, $t)) return 'table ' . $t;
        }
        $t = '2000-01-04 07:00:00';                          // invented: before any row of any book
        $since = '2000-01-01 07:00:00';
        $ph = '256700000009';                                 // invented
        try {
            if (WaSendBudget::handset(' Sales-X ') !== WaSendBudget::handset('i:sales-x')) return 'WaSendBudget::handset';
            if (QuoteChase::offerHolds($ro, $t) !== []) return 'QuoteChase::offerHolds';
            if (QuoteChase::offerHold($ro, $ph, $t) !== '') return 'QuoteChase::offerHold';
            if (HumanTakeover::phonesSince($ro, $since, $t) !== []) return 'HumanTakeover::phonesSince';
            if (HumanTakeover::phoneSince($ro, $ph, $since, $t) !== null) return 'HumanTakeover::phoneSince';
            ReminderDay::done([], $config, $dataDir, UtcClock::parse($t));          // pure; its answer is not judged (ruling R8)
            $ts = UtcClock::parse($t);
            if (JobBudget::seconds(self::RUN_S, null, $ts) !== self::RUN_S || !JobBudget::mayStart($ts + self::RUN_S, 15, $ts)) return 'JobBudget';
        } catch (\Throwable $e) {
            return 'a reader threw';
        }
        return '';
    }

    /** P-O1-9: the switches and the stamp among the ladder's allKeys(), the six settings among its settingKeys(). '' = so. */
    private static function keysDeclared(): string
    {
        $f = __DIR__ . '/SalesFollowupLadder.php';
        if (!class_exists('SalesFollowupLadder') && is_file($f)) require_once $f;
        if (!class_exists('SalesFollowupLadder') || !method_exists('SalesFollowupLadder', 'allKeys') || !method_exists('SalesFollowupLadder', 'settingKeys')) {
            return 'the ladder (lib/SalesFollowupLadder.php) is missing';
        }
        $all = self::keyList(\SalesFollowupLadder::allKeys());
        $set = self::keyList(\SalesFollowupLadder::settingKeys());
        foreach (array_merge(self::SWITCHES, [self::LIVE_SINCE]) as $k) {
            if (!in_array($k, $all, true)) return 'not among allKeys(): ' . $k;
        }
        foreach (array_keys(self::SETTINGS) as $k) {
            if (!in_array($k, $set, true)) return 'not among settingKeys(): ' . $k;
        }
        return '';
    }

    /** A list of key names, or a map keyed by them, as key names. @return string[] */
    private static function keyList($x): array
    {
        $out = [];
        foreach ((array)$x as $k => $v) $out[] = is_string($k) ? $k : (string)(is_array($v) ? ($v['key'] ?? '') : $v);
        return $out;
    }

    /** Ruling R1's four lines, byte for byte, in its order. */
    public static function masterLines(): array
    {
        return [
            "'quote_chase'      => ['interval' => 540, 'uganda' => true, 'flag' => 'quote_tracking_enabled',   'script' => __DIR__ . '/quote_chase.php'],",
            "'quote_daily_list' => ['interval' => 240, 'uganda' => true, 'flag' => 'quote_daily_list_enabled', 'script' => __DIR__ . '/quote_daily_list.php'],",
            "'ai_media'         => ['interval' => 60,  'uganda' => true, 'flag' => 'ai_media_enabled',         'script' => dirname(__DIR__) . '/run_media_worker.php'],",
            "'offers_send'      => ['interval' => 240, 'uganda' => true, 'flag' => 'offers_live_enabled',      'script' => __DIR__ . '/offers_send.php'],",
        ];
    }

    /**
     * P-O1-6: cron/master.php, read as text, ends $_m_jobs with ruling R1's four lines, byte for byte and in its order
     * (each line compared without its indentation; comment lines skipped), and names 'offers_send' exactly once.
     * '' when it does, else what differs.
     */
    public static function masterCheck(string $pluginRoot): string
    {
        $src = @file_get_contents(rtrim($pluginRoot, '/') . '/cron/master.php');
        if (!is_string($src) || $src === '') return 'unreadable';
        $start = strpos($src, '$_m_jobs = [');
        $end = $start === false ? false : strpos($src, "\n];", $start);
        if ($start === false || $end === false) return 'no job list';
        $lines = [];
        foreach (explode("\n", substr($src, $start, $end - $start)) as $i => $l) {
            $t = trim($l);
            if ($i === 0 || $t === '' || strpos($t, '//') === 0 || strpos($t, '#') === 0 || strpos($t, '/*') === 0 || strpos($t, '*') === 0) continue;
            $lines[] = $t;
        }
        if (array_slice($lines, -4) !== self::masterLines()) return 'the last four jobs are not ruling R1\'s lines';
        if (substr_count($src, "'offers_send'") !== 1) return '\'offers_send\' is not named exactly once';
        return '';
    }

    /** P-O1-7: active admin accounts whose phone PhoneNumber::international accepts (a count only). */
    private static function adminsWithPhone(array $rows, array $config, string $dataDir): int
    {
        $t = \TenantProfile::current($config, $dataDir);
        $n = 0;
        foreach ($rows as $r) {
            if (!is_array($r) || empty($r['is_admin']) || !\StaffDirectory::isActive($r)) continue;
            if (\StaffDirectory::phoneOf($r, $t) !== null) $n++;
        }
        return $n;
    }

    // ── checkLive's clauses ────────────────────────────────────────────────────────────────────────────────────

    /** C-O2-1: per offer, §H.4.12's counts; per handset, today's offers against the ceiling each row recorded. */
    private static function c1Counts(\PDO $ro, array $config, array $offers, array $recips, int $now, array &$c, array &$notes): void
    {
        foreach ($offers as $id => $o) {
            if ((string)$o['started_at'] === '') continue;
            $k = OfferStore::counts($ro, (int)$id);
            $notes[] = 'C-O2-1 offer ' . $id . ' (' . $o['state'] . '): ' . self::kv($k);
        }
        $day = self::dayOf(UtcClock::stamp($now));
        $set = self::settings($config)['offers_daily_max_per_handset'];
        $per = [];
        foreach ($recips as $r) {
            if (!in_array((string)$r['state'], ['sent', 'uncertain', 'claimed'], true)) continue;
            $when = (string)($r['sent_at'] ?? '') !== '' ? (string)$r['sent_at'] : (string)$r['claimed_at'];
            if ($when === '' || self::dayOf($when) !== $day) continue;
            $h = (string)$r['handset'];
            $per[$h]['n'] = ($per[$h]['n'] ?? 0) + 1;
            if ($r['day_max'] !== null) $per[$h]['max'] = max((int)($per[$h]['max'] ?? 0), (int)$r['day_max']);
        }
        ksort($per);
        foreach ($per as $h => $p) {
            $notes[] = 'C-O2-1 handset ' . self::token($h) . ' today: ' . $p['n'] . ' offers (ceiling recorded ' . ($p['max'] ?? '—')
                     . ', setting now ' . ($set === null ? 'out of range' : $set) . ')';
        }
    }

    /** The ledger row a recipient's send went under: not not_sent, on its ref, its phone matched with IS (an erase). */
    private static function liveRows(array $r, array $byRef): array
    {
        $ref = 'offer:' . (int)$r['offer_id'] . ':' . (int)$r['id'];
        return array_values(array_filter($byRef[$ref] ?? [], function ($l) use ($r) {
            return self::same($l['phone'], $r['phone']) && $l['outcome'] !== 'not_sent';
        }));
    }

    /** C-O2-2: every send's claim inside the window that claim recorded, the in-flight bound included (rulings R8, R18). */
    private static function c2Window(array $done, array $byRef, array &$c, array &$fails): void
    {
        foreach ($done as $r) {
            $rows = self::liveRows($r, $byRef);
            if ($rows === []) {
                $all = $byRef['offer:' . (int)$r['offer_id'] . ':' . (int)$r['id']] ?? [];
                if ((string)$r['reason'] === 'stale_claim' && $all !== []) { self::inc($c, 'C-O2-2 stale claims certainly not sent, not judged'); continue; }
                self::inc($c, 'C-O2-2 sends with no ledger row (C-O2-4)');
                continue;
            }
            foreach ($rows as $l) {
                if (!self::inWindow((string)$l['claimed_at'], (int)$l['win_from'], (int)$l['win_to'], self::IN_FLIGHT_S)) {
                    self::inc($c, 'C-O2-2 claims outside their window'); $fails[] = 'outside_window';
                }
                if ((string)($r['sent_at'] ?? '') !== '' && UtcClock::parse((string)$r['sent_at']) > self::closeOf((string)$l['claimed_at'], (int)$l['win_to'])) {
                    self::inc($c, 'C-O2-2 returns after the close (the claim inside its bound)');
                }
            }
        }
    }

    /** C-O2-3: no two offers to one phone closer than the later one's recorded recent_days, at its judged_at. */
    private static function c3Recent(array $done, array $offers, array &$c, array &$fails): void
    {
        $byPhone = [];
        foreach ($done as $r) {
            if ($r['phone'] === null || (string)$r['phone'] === '') { self::inc($c, 'C-O2-3 erased rows, not grouped'); continue; }
            $byPhone[(string)$r['phone']][] = $r;
        }
        foreach ($byPhone as $rows) {
            usort($rows, function ($a, $b) { return [(string)$a['judged_at'], (int)$a['id']] <=> [(string)$b['judged_at'], (int)$b['id']]; });
            for ($i = 1; $i < count($rows); $i++) {
                $later = $rows[$i];
                $days = (int)($offers[(int)$later['offer_id']]['recent_days'] ?? 0);
                $m = UtcClock::parse((string)$later['judged_at']);
                for ($j = 0; $j < $i; $j++) {
                    if ($m - UtcClock::parse((string)$rows[$j]['sent_at']) <= $days * 86400) {
                        self::inc($c, 'C-O2-3 offers closer than their recent_days'); $fails[] = 'recent_offer'; break;
                    }
                }
            }
        }
    }

    /** C-O2-4: every send and its ledger row, by the two-part ref (rulings R2, R18). */
    private static function c4Ledger(array $done, array $recips, array $ledger, array $byRef, int $now, array &$c, array &$fails): void
    {
        foreach ($done as $r) {
            $ref = 'offer:' . (int)$r['offer_id'] . ':' . (int)$r['id'];
            $mine = array_values(array_filter($byRef[$ref] ?? [], function ($l) use ($r) { return self::same($l['phone'], $r['phone']); }));
            if ($mine === []) { self::inc($c, 'C-O2-4 sends with no ledger row'); $fails[] = 'ledger_missing'; continue; }
            $live = array_values(array_filter($mine, function ($l) { return $l['outcome'] !== 'not_sent'; }));
            $reason = (string)($r['reason'] ?? '');
            $state = (string)$r['state'];
            if ($reason === 'ledger_already_sent' && $state === 'uncertain') {
                $hit = array_values(array_filter($live, function ($l) { return in_array($l['outcome'], ['sent', 'uncertain'], true); }));
                if ($hit === []) { self::inc($c, 'C-O2-4 already-sent rows with no sent ledger row'); $fails[] = 'ledger_mismatch'; continue; }
                if (WaSendBudget::handset((string)$hit[0]['handset']) !== WaSendBudget::handset((string)$r['handset'])) self::inc($c, 'C-O2-4 already-sent rows on another handset');
                continue;
            }
            if ($live === []) {
                if ($reason === 'stale_claim') { self::inc($c, 'C-O2-4 stale claims whose ledger row reads not_sent'); continue; }
                self::inc($c, 'C-O2-4 sends whose ledger rows are all not_sent'); $fails[] = 'ledger_not_sent'; continue;
            }
            $l = end($live);
            if (WaSendBudget::handset((string)$l['handset']) !== WaSendBudget::handset((string)$r['handset'])) {
                self::inc($c, 'C-O2-4 sends whose ledger row is on another handset'); $fails[] = 'ledger_handset'; continue;
            }
            $out = $l['outcome'];
            if ($reason === 'stale_claim' && $state === 'uncertain') {
                if ($out === 'sent') self::inc($c, 'C-O2-4 stale claims whose ledger row reads sent');
                elseif ($out === null) self::inc($c, 'C-O2-4 stale claims whose ledger row is in flight');
                continue;
            }
            $want = $state === 'sent' ? 'sent' : 'uncertain';
            if ($out === $want) continue;
            if ($out === null || ($out === 'uncertain' && in_array((string)$l['detail'], self::SWEPT, true))) {
                self::inc($c, 'C-O2-4 settle_not_landed'); continue;
            }
            self::inc($c, 'C-O2-4 sends whose ledger outcome differs'); $fails[] = 'ledger_mismatch';
        }
        $ids = [];
        foreach ($recips as $r) $ids[(int)$r['id']] = (int)$r['offer_id'];
        foreach ($byRef as $ref => $rows) {
            if (!preg_match('/^offer:(\d+):(\d+)$/', (string)$ref, $m) || ($ids[(int)$m[2]] ?? -1) !== (int)$m[1]) {
                self::inc($c, 'C-O2-4 offer ledger rows mapping to no recipient'); $fails[] = 'ledger_unmapped'; continue;
            }
            $max = max(array_map(function ($l) { return (int)$l['try']; }, $rows));
            foreach ($rows as $l) {
                if ((int)$l['try'] < $max && $l['outcome'] === null) { self::inc($c, 'C-O2-4 earlier tries left in flight'); $fails[] = 'ledger_try_unsettled'; }
            }
        }
        // the orphan rule: one still in flight 900 s after a later claim on its handset is a fault; before that, a count
        foreach ($ledger as $l) {
            if ((string)$l['kind'] !== 'offer' || $l['outcome'] !== null) continue;
            $at = UtcClock::parse((string)$l['claimed_at']);
            $later = false;
            foreach ($ledger as $o) {
                if ((string)$o['mode'] === 'claim' && (string)$o['handset'] === (string)$l['handset'] && (int)$o['id'] !== (int)$l['id']
                    && UtcClock::parse((string)$o['claimed_at']) >= $at + self::ORPHAN_S) { $later = true; break; }
            }
            if ($later) { self::inc($c, 'C-O2-4 ledger_orphan'); $fails[] = 'ledger_orphan'; }
            elseif ($now - $at >= self::ORPHAN_S) self::inc($c, 'C-O2-4 ledger rows in flight, not yet swept');
        }
    }

    /** C-O2-5: per handset and Kampala day, each send within the day_max its own row recorded. */
    private static function c5DayMax(array $done, array $ledger, array &$c, array &$fails, array &$notes): void
    {
        $groups = [];
        foreach ($done as $r) {
            $when = (string)($r['sent_at'] ?? '') !== '' ? (string)$r['sent_at'] : (string)$r['claimed_at'];
            $groups[(string)$r['handset'] . '|' . self::dayOf($when)][] = $r;
        }
        foreach ($groups as $rows) {
            foreach ($rows as $r) {
                $before = 0;
                foreach ($rows as $o) {
                    if ((int)$o['id'] === (int)$r['id']) continue;
                    if ([(string)$o['judged_at'], (int)$o['id']] < [(string)$r['judged_at'], (int)$r['id']]) $before++;
                }
                if ($r['day_max'] === null || $before >= (int)$r['day_max']) { self::inc($c, 'C-O2-5 sends over their day_max'); $fails[] = 'day_max'; }
            }
        }
        $tot = [];
        foreach ($ledger as $l) {
            if ((int)$l['proactive'] !== 1 || $l['outcome'] === 'not_sent') continue;
            $k = self::token((string)$l['handset']) . ' ' . $l['local_day'];
            $tot[$k]['n'] = ($tot[$k]['n'] ?? 0) + 1;
            if ($l['cap_day'] !== null) $tot[$k]['cap'] = max((int)($tot[$k]['cap'] ?? 0), (int)$l['cap_day']);
        }
        ksort($tot);
        foreach ($tot as $k => $v) $notes[] = 'C-O2-5 proactive ' . $k . ': ' . $v['n'] . ' (cap recorded ' . ($v['cap'] ?? '—') . ')';
    }

    /** C-O2-6: every offer claim after claimed_at + gap_s of every earlier row on its handset not not_sent (each its own gap_s). */
    private static function c6Spacing(array $ledger, array &$c, array &$fails): void
    {
        $by = [];
        foreach ($ledger as $l) $by[(string)$l['handset']][] = $l;
        foreach ($by as $rows) {
            $until = null;
            foreach ($rows as $l) {
                $at = UtcClock::parse((string)$l['claimed_at']);
                if ((string)$l['kind'] === 'offer' && (string)$l['mode'] === 'claim' && $until !== null && $at < $until) {
                    self::inc($c, 'C-O2-6 offer claims inside an earlier row\'s spacing'); $fails[] = 'spacing';
                }
                if ($l['outcome'] !== 'not_sent') $until = max((int)$until, $at + (int)$l['gap_s']);
            }
        }
    }

    /** C-O2-7: a claimed row older than 15 minutes is FAIL only once a later run's sweep should have reached it. */
    private static function c7Unswept(array $recips, int $now, array &$c, array &$fails): void
    {
        $latest = 0;
        foreach ($recips as $r) if ((string)($r['claimed_at'] ?? '') !== '') $latest = max($latest, UtcClock::parse((string)$r['claimed_at']));
        foreach ($recips as $r) {
            if ((string)$r['state'] !== 'claimed') continue;
            $at = UtcClock::parse((string)$r['claimed_at']);
            if ($now - $at < self::STALE_S) continue;
            if ($latest > $at + self::STALE_S + self::RUN_S) { self::inc($c, 'C-O2-7 claim_unswept'); $fails[] = 'claim_unswept'; }
            else self::inc($c, 'C-O2-7 claimed, not yet swept');
        }
    }

    /** C-O2-8: the echo trap (R-05) judged by the act's identity; a colleague's reply after a send is a count. */
    private static function c8Echo(\PDO $ro, array $done, int $now, array &$c, array &$fails): void
    {
        foreach ($done as $r) {
            $id = (string)($r['wa_message_id'] ?? '');
            if ((string)$r['state'] === 'sent') {
                $e = (string)($r['echo'] ?? '');
                if ($e === 'late') { self::inc($c, 'C-O2-8 echo_late'); $fails[] = 'echo_late'; }
                elseif ($e !== 'claimed') { self::inc($c, 'C-O2-8 echoes not claimed'); $fails[] = 'echo_unclaimed'; }
                if ($id !== '' && UtcClock::parse((string)$r['sent_at']) >= $now - self::ECHO_S
                    && (int)self::one($ro, "SELECT COUNT(*) FROM evo_webhook_seen WHERE message_id = ? AND event = 'offer.send'", [$id]) === 0) {
                    self::inc($c, 'C-O2-8 echoes not seen'); $fails[] = 'echo_unseen';
                }
            }
            if ($id !== '' && (int)self::one($ro, "SELECT COUNT(*) FROM wa_messages WHERE wa_message_id = ? AND agent_name = 'Team'", [$id]) > 0) {
                self::inc($c, 'C-O2-8 sends stored as Team'); $fails[] = 'echo_team';
            }
            $sent = (string)($r['sent_at'] ?? '');
            if ($sent === '') continue;
            $to = UtcClock::stamp(UtcClock::parse($sent) + self::HUMAN_SPAN_S);
            $h = self::one($ro, 'SELECT last_human_reply_at FROM wa_conversations WHERE id = ?', [(int)$r['conversation_id']]);
            if ($h === null || $h === false || (string)$h < $sent || (string)$h > $to) continue;
            $team = (int)self::one($ro, "SELECT COUNT(*) FROM wa_messages WHERE conversation_id = ? AND direction = 'out' AND agent_name = 'Team'
                                          AND created_at >= ? AND created_at <= ?", [(int)$r['conversation_id'], $sent, $to]);
            $inbox = (int)self::one($ro, "SELECT COUNT(*) FROM wa_messages WHERE conversation_id = ? AND direction = 'out'
                                           AND event_key IN ('wa_staff_reply', 'wa_image_send') AND created_at >= ? AND created_at <= ?",
                                    [(int)$r['conversation_id'], $sent, $to]);
            self::inc($c, 'C-O2-8 a person within 5 minutes after a send: ' . ($team > 0 ? 'a Team row' : ($inbox > 0 ? 'an Inbox reply' : 'no person row')));
        }
    }

    /** C-O2-9: the exclusions re-judged at each recipient's judged_at, from rows stored by then, under the offer's limits. */
    private static function c9Exclusions(\PDO $ro, array $config, string $dataDir, array $done, array $offers, array &$c, array &$fails): void
    {
        $t = \TenantProfile::current($config, $dataDir);
        $stored = OfferAudience::storedRows($ro);
        $form = function ($raw) use ($t): ?string { return OfferAudience::digits((string)$raw, $t); };
        $optouts = self::all($ro, 'SELECT phone, active, created_at, lifted_at FROM contact_optouts');
        $fsends = self::all($ro, 'SELECT phone, sent_at FROM followup_sends');
        $fups = self::all($ro, 'SELECT phone, opened_at, closed_at FROM followups');
        foreach ($done as $r) {
            if ($r['phone'] === null || (string)$r['phone'] === '') { self::inc($c, 'C-O2-9 erased rows, not re-judged'); continue; }
            $p = (string)$r['phone'];
            $m = (string)$r['judged_at'];
            $mt = UtcClock::parse($m);
            $sent = (string)$r['sent_at'];
            $o = $offers[(int)$r['offer_id']] ?? [];
            $warm = (int)($o['warm_days'] ?? 0);
            $fail = function (string $code) use (&$c, &$fails): void { self::inc($c, 'C-O2-9 ' . $code); $fails[] = $code; };
            $after = function (string $what) use (&$c): void { self::inc($c, 'C-O2-9 stored after judged_at: ' . $what); };
            // an opt-out live then, any channel, scope or source
            foreach ($optouts as $x) {
                if ($form($x['phone']) !== $p) continue;
                $live = (string)$x['created_at'] <= $m && (($x['lifted_at'] === null && (int)$x['active'] === 1) || ($x['lifted_at'] !== null && (string)$x['lifted_at'] > $m));
                if ($live) { $fail('opted_out'); break; }
                if ((string)$x['created_at'] > $m && (string)$x['created_at'] <= $sent) $after('opt-out');
            }
            // the partner, staff and DishNet lists, through lists() at the moment (the partner list bounded by created_at)
            $Lm = OfferAudience::lists($ro, $config, $dataDir, $stored['retailers'], $stored['kyc_config'], $p, $m, $mt);
            if (self::onList($Lm, 'partner_contact', $p)) $fail('partner_contact');
            else {
                $Ln = OfferAudience::lists($ro, $config, $dataDir, $stored['retailers'], $stored['kyc_config'], $p, null, $mt);
                if (self::onList($Ln, 'partner_contact', $p)) self::inc($c, 'C-O2-9 partner contacts stored after judged_at');
            }
            if (self::onList($Lm, 'colleague', $p)) self::inc($c, 'C-O2-9 on the staff list today (no time recorded)');
            if ($Lm['internal'] !== null && $Lm['internal']->senderClass($p, false) !== '') self::inc($c, 'C-O2-9 a DishNet number today (no time recorded)');
            // a follow-up send within the 48 h before, from followup_sends or the ledger
            $since48 = UtcClock::stamp($mt - self::HOLD_48H_S);
            foreach ($fsends as $x) {
                if ($form($x['phone']) !== $p) continue;
                if ((string)$x['sent_at'] <= $m && (string)$x['sent_at'] > $since48) { $fail('followup_recent'); break; }
                if ((string)$x['sent_at'] > $m && (string)$x['sent_at'] <= $sent) $after('follow-up send');   // judge() could not read it
            }
            $n = (int)self::one($ro, "SELECT COUNT(*) FROM wa_auto_sends WHERE kind IN ('quote', 'enquiry') AND phone = ?
                                       AND (outcome IN ('sent', 'uncertain') OR outcome IS NULL) AND claimed_at <= ? AND claimed_at > ?", [$p, $m, $since48]);
            if ($n > 0) $fail('followup_recent');
            // a follow-up of any kind open at the moment, bounded on both sides
            foreach ($fups as $x) {
                if ($form($x['phone']) !== $p) continue;
                if ((string)$x['opened_at'] <= $m && ($x['closed_at'] === null || (string)$x['closed_at'] > $m)) { $fail('followup_open'); break; }
                if ((string)$x['opened_at'] > $m && (string)$x['opened_at'] <= $sent) $after('follow-up');
            }
            // Part F's hold, its past-moment form (ruling R3)
            if (QuoteChase::offerHold($ro, $p, $m) !== '') $fail('quote_hold');
            elseif ($sent !== '' && QuoteChase::offerHold($ro, $p, $sent) !== '') $after('quotation hold');
            // an inbound voice note stored by then, within the offer's warm_days
            $warmFrom = UtcClock::stamp($mt - $warm * 86400);
            [$before, $later] = self::inbound($ro, $t, $p, "m.media_type = 'audio'", $m, $warmFrom, $sent);
            if ($before > 0) $fail('recent_voice_note');
            if ($later > 0) $after('voice note');
            // an 'in' seen mark by then, within the warm window
            $marks = self::all($ro, "SELECT phone, seen_at FROM wa_seen_marks WHERE direction = 'in' AND phone <> '' AND seen_at >= ?", [$warmFrom]);
            foreach ($marks as $x) {
                if ($form($x['phone']) !== $p) continue;
                if ((string)$x['seen_at'] <= $m) { $fail('unread_inbound'); break; }
                if ((string)$x['seen_at'] <= $sent) $after('seen mark');
            }
            // a person on our side within the 72 h before, each source by its storage time (ruling R3)
            if (HumanTakeover::phoneSince($ro, $p, UtcClock::stamp($mt - self::PERSON_72H_S), $m) !== null) $fail('person_handling');
            elseif ($sent !== '' && HumanTakeover::phoneSince($ro, $p, $m, $sent) !== null) $after('a person');
        }
    }

    /** C-O2-10: every send from a warm conversation of the phone, re-judged at judged_at (ruling R18). */
    private static function c10Warm(\PDO $ro, array $config, string $dataDir, array $done, array $offers, array &$c, array &$fails): void
    {
        $t = \TenantProfile::current($config, $dataDir);
        $stored = OfferAudience::storedRows($ro);
        $gw = new EvolutionOfferGateway($ro, $config, $dataDir, OfferAudience::reader($ro, $stored['retailers'], $stored['kyc_config']));
        foreach ($done as $r) {
            if ($r['phone'] === null || (string)$r['phone'] === '') { self::inc($c, 'C-O2-10 erased rows'); continue; }
            $conv = self::one($ro, 'SELECT id FROM wa_conversations WHERE id = ?', [(int)$r['conversation_id']]);
            if ($conv === false || $conv === null) { self::inc($c, 'C-O2-10 conversations since deleted'); continue; }
            $m = (string)$r['judged_at'];
            $mt = UtcClock::parse($m);
            $warmFrom = UtcClock::stamp($mt - (int)($offers[(int)$r['offer_id']]['warm_days'] ?? 0) * 86400);
            $own = self::one($ro, "SELECT MAX(sent_at) FROM wa_messages WHERE conversation_id = ? AND direction = 'in' AND created_at <= ? AND sent_at >= ?",
                             [(int)$r['conversation_id'], $m, $warmFrom]);
            if ((int)self::one($ro, "SELECT COUNT(*) FROM wa_messages WHERE conversation_id = ? AND direction = 'in' AND created_at > ? AND created_at <= ?",
                               [(int)$r['conversation_id'], $m, (string)$r['sent_at']]) > 0) {
                self::inc($c, 'C-O2-10 inbound stored after judged_at');
            }
            if ($own === null || $own === false || (string)$own === '') {
                self::inc($c, 'C-O2-10 sends from a conversation not warm at judged_at'); $fails[] = 'not_warm'; continue;
            }
            foreach (self::all($ro, "SELECT c.id, c.channel, c.phone, MAX(m.sent_at) AS last_in FROM wa_conversations c JOIN wa_messages m ON m.conversation_id = c.id
                                       WHERE c.id <> ? AND m.direction = 'in' AND m.created_at <= ? AND substr(c.phone, -9) = ? GROUP BY c.id",
                                    [(int)$r['conversation_id'], $m, substr((string)$r['phone'], -9)]) as $x) {
                if (OfferAudience::digits((string)$x['phone'], $t) !== (string)$r['phone'] || (string)$x['last_in'] <= (string)$own) continue;
                if ($gw->handsetFor((string)$x['channel']) !== WaSendBudget::handset((string)$r['handset'])) {
                    self::inc($c, 'C-O2-10 a later inbound on a channel routed today to another handset');
                }
            }
        }
    }

    /** C-O2-11: the STOP rate per offer, and whether the auto-pause fired. */
    private static function c11StopRate(\PDO $ro, array $config, array $offers, array &$c, array &$notes): void
    {
        $pct = self::settings($config)['offers_stop_pause_pct'] ?? self::SETTINGS['offers_stop_pause_pct'][0];
        foreach ($offers as $id => $o) {
            if ((string)$o['started_at'] === '') continue;
            $k = OfferStore::counts($ro, (int)$id);
            $n = $k['sent'] + $k['maybe_sent'];
            $fired = (int)self::one($ro, "SELECT COUNT(*) FROM wa_offer_events WHERE offer_id = ? AND action = 'paused_stop_rate'", [(int)$id]) > 0;
            $line = 'C-O2-11 offer ' . $id . ': STOP ' . $k['stop_72h'] . ' of ' . $n . ', the auto-pause ' . ($fired ? 'fired' : 'did not fire');
            if ($n > 0 && $k['stop_72h'] * 100 > $pct * $n) $line .= ' — STOP RATE ABOVE ' . $pct . '%';
            $notes[] = $line;
        }
    }

    /** C-O2-12: one customer-proactive message per phone per Kampala day, judged on claims only (ruling R18). */
    private static function c12OnePerDay(array $ledger, array &$c, array &$fails): void
    {
        $offers = [];
        $others = [];
        foreach ($ledger as $l) {
            if ($l['phone'] === null || (string)$l['phone'] === '') continue;
            $k = (string)$l['phone'] . '|' . (string)$l['local_day'];
            if ((string)$l['kind'] === 'offer') $offers[$k][] = $l;
            elseif (in_array((string)$l['kind'], self::PAIRED_KINDS, true)) $others[$k][] = $l;
        }
        foreach ($offers as $k => $os) {
            foreach ($os as $o) {
                if ($o['outcome'] === 'not_sent') continue;
                foreach ($others[$k] ?? [] as $p) {
                    if ($p['outcome'] === 'not_sent') {
                        $flight = (int)$p['id'] < (int)$o['id'] && (string)$p['settled_at'] > (string)$o['claimed_at'];
                        if ($flight) self::inc($c, 'C-O2-12 earlier rows in flight at the offer\'s claim, since not sent');
                        continue;
                    }
                    if ((int)$p['id'] < (int)$o['id']) { self::inc($c, 'C-O2-12 offers claimed after another message that day'); $fails[] = 'phone_today'; continue; }
                    if ((string)$p['mode'] === 'claim') { self::inc($c, 'C-O2-12 claims after an offer that day'); $fails[] = 'phone_today'; continue; }
                    self::inc($c, 'C-O2-12 records after an offer that day (' . self::token((string)$p['kind']) . ')');
                }
            }
        }
    }

    // ── small helpers ──────────────────────────────────────────────────────────────────────────────────────────

    /** Inbound rows of the phone matching $where: stored at or before $m and sent within the window, and stored after $m up to $to. */
    private static function inbound(\PDO $ro, \TenantProfile $t, string $p, string $where, string $m, string $from, string $to): array
    {
        $before = 0; $later = 0;
        foreach (self::all($ro, "SELECT c.phone, m.created_at FROM wa_messages m JOIN wa_conversations c ON c.id = m.conversation_id
                                  WHERE m.direction = 'in' AND {$where} AND m.sent_at >= ? AND substr(c.phone, -9) = ?", [$from, substr($p, -9)]) as $x) {
            if (OfferAudience::digits((string)$x['phone'], $t) !== $p) continue;
            if ((string)$x['created_at'] <= $m) $before++;
            elseif ($to !== '' && (string)$x['created_at'] <= $to) $later++;
        }
        return [$before, $later];
    }

    /** Whether lists() put the phone on $code's list, exactly or on its last nine digits. */
    private static function onList(array $L, string $code, string $p): bool
    {
        return isset($L['sets'][$code][$p]) || isset($L['loose'][$code][substr($p, -9)]);
    }

    /** Whether a claim at $claimedAt, $bound seconds long, was inside its recorded window on a Kampala day other than Sunday. */
    private static function inWindow(string $claimedAt, int $from, int $to, int $bound): bool
    {
        $t = UtcClock::parse($claimedAt);
        $d = (new \DateTime('@' . $t))->setTimezone(new \DateTimeZone(self::TZ));
        if ((int)$d->format('w') === 0) return false;
        $open = (clone $d)->setTime($from, 0, 0)->getTimestamp();
        return $t >= $open && $t + $bound <= self::closeOf($claimedAt, $to);
    }

    /** The close ($to:00, Kampala) of the day a claim was made. */
    private static function closeOf(string $claimedAt, int $to): int
    {
        $d = (new \DateTime('@' . UtcClock::parse($claimedAt)))->setTimezone(new \DateTimeZone(self::TZ));
        return $d->setTime($to, 0, 0)->getTimestamp();
    }

    /** The Kampala date of a UTC stamp. */
    private static function dayOf(string $utc): string
    {
        return (new \DateTime('@' . UtcClock::parse($utc)))->setTimezone(new \DateTimeZone(self::TZ))->format('Y-m-d');
    }

    /** Two phone columns compared as SQL's IS compares them: both NULL (an erase) match. */
    private static function same($a, $b): bool
    {
        return ($a === null && $b === null) || ($a !== null && $b !== null && (string)$a === (string)$b);
    }

    private static function tableExists(\PDO $pdo, string $t): bool
    {
        return self::one($pdo, "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ?", [$t]) !== false;
    }

    /** One value; the cursor closed, so no read is left open on the connection (a WAL snapshot would outlive it). */
    private static function one(\PDO $pdo, string $sql, array $args = [])
    {
        $st = $pdo->prepare($sql);
        $st->execute($args);
        $v = $st->fetchColumn();
        $st->closeCursor();
        return $v;
    }

    private static function all(\PDO $pdo, string $sql, array $args = []): array
    {
        $st = $pdo->prepare($sql);
        $st->execute($args);
        $r = $st->fetchAll(\PDO::FETCH_ASSOC);
        $st->closeCursor();
        return $r;
    }

    private static function inc(array &$c, string $k): void
    {
        $c[$k] = ($c[$k] ?? 0) + 1;
    }

    /** A label or code as printed: no digits beyond a short run, nothing but word characters. */
    private static function token(string $s): string
    {
        return (string)preg_replace('/\d{6,}/', '…', (string)preg_replace('/[^A-Za-z0-9_:. -]/', '', $s));
    }

    private static function kv(array $k): string
    {
        $o = [];
        foreach ($k as $n => $v) $o[] = $n . ' ' . $v;
        return implode(', ', $o);
    }

    /** @return array{status:string, counts:array<string,int>, notes:list<string>} */
    private static function res(string $status, array $counts, array $notes): array
    {
        ksort($counts);
        return ['status' => $status, 'counts' => $counts, 'notes' => array_values($notes)];
    }

    /** A stored integer setting: the default when absent or blank, the integer, or null when it cannot be read. */
    private static function intOf($raw, int $default): ?int
    {
        if ($raw === null || (is_string($raw) && trim($raw) === '')) return $default;
        if (is_int($raw)) return $raw;
        if (is_string($raw) && preg_match('/^\s*\d{1,6}\s*$/', $raw)) return (int)trim($raw);
        return null;
    }
}
