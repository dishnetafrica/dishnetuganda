<?php
declare(strict_types=1);

require_once __DIR__ . '/UtcClock.php';
require_once __DIR__ . '/PluginLog.php';
require_once __DIR__ . '/WaSendBudget.php';
require_once __DIR__ . '/HumanTakeover.php';
require_once __DIR__ . '/QuoteSendEvidence.php';
require_once __DIR__ . '/QuoteChaseText.php';

/**
 * SalesFollowupChecks — Part F's callables in the ladder (5.18.104, Uganda, root docs/73 §H.2.12.5; ruling R13's row F).
 *
 *   preconditions($ro, $config, $dataDir, $step)   rows 1 (report: nothing to judge), 2 (Part F's half: 089's and 090's
 *                                                   _schema_checks rows, dn_tz()), 6 (chase: W2 and wa_seen_marks, and
 *                                                   what `on chase` prints) and 10 (cold: what `on cold` prints)
 *   check($ro, $config, $dataDir, $id, $now)       L-ON, Q1-ON, Q2-ON, Q3-ON, Q5-ON
 *
 * Every read goes through the tool's read-only $ro; nothing here opens a connection or writes (ruling R13). A check
 * answers PASS, FAIL <code> or NOT_YET too_soon|no_traffic|no_test with counts and notes (ruling R6), computed in a
 * literal Africa/Kampala. A clause that re-judges a past act judges it at the moment it was decided, from what could be
 * read then, under the limits recorded with it; what its maker could not know is a count, never a FAIL (ruling R18).
 * Output is counts, fixed codes, dates and handset labels; never a phone, a name or a message. PHP 7.4.
 */
final class SalesFollowupChecks
{
    /** The literal zone every day and hour is judged in. */
    public const TZ = 'Africa/Kampala';

    const DAY_S = 86400;
    /** The ledger's in-flight bound: a later claim settles every row in flight this long (§H.2.5.3 step 3a). */
    const ORPHAN_S = 900;
    /** The hour past the switch L-ON never judges (Q16-F02). */
    const MARGIN_S = 3600;
    /** A tracker row is pending for its first hour (§H.2.9.4). */
    const PENDING_S = 3600;
    /** The ledger's details for a row its own sweep settled. */
    const SWEPT = ['stale_orphan', 'stale'];
    /** The list's section a STOP is listed under (§H.2.11.4). */
    const STOP_SECTION = 'stop';
    /** At most this many keys a section lists (§H.2.11.3). */
    const MAX_REFS = 15;
    /** How far back a check reads (the ledger keeps 400 days; a check judges at most this window). */
    const WINDOW_DAYS = 60;

    private function __construct() {}

    // ── preconditions (ruling R13, row F) ──────────────────────────────────────────────────────────────────────

    /** @return array{ok:bool, code:?string, lines:list<string>} */
    public static function preconditions(\PDO $ro, array $config, string $dataDir, string $step): array
    {
        $ok = function (array $lines): array { return ['ok' => true, 'code' => null, 'lines' => $lines]; };
        $no = function (string $code, array $lines): array { return ['ok' => false, 'code' => $code, 'lines' => $lines]; };
        switch ($step) {
            case 'report':
                return $ok([]);   // its date is an option the tool validates (A12-03)
            case 'ack':
                $lines = [];
                foreach (['089_quote_followups.sql', '090_wa_voice.sql'] as $f) {
                    if (!self::schemaRow($ro, $f)) return $no('schema_check_missing:' . $f, array_merge($lines, ['_schema_checks ' . $f . ': absent']));
                    $lines[] = '_schema_checks ' . $f . ': present';
                }
                if (!function_exists('dn_tz')) require_once __DIR__ . '/timezone.php';
                if (dn_tz($config) !== self::TZ) return $no('zone', array_merge($lines, ['dn_tz(): not Africa/Kampala — every claim would answer zone']));
                $lines[] = 'dn_tz(): Africa/Kampala';
                return $ok($lines);
            case 'chase':
                $src = (string)@file_get_contents(dirname(__DIR__) . '/evo_webhook.php');
                if (strpos($src, "WaSeenMarks::note(\$pdo, \$config, \$dataDir, 'in', \$phone,") === false || strpos($src, "\$convId === null && \$text === ''") === false) {
                    return $no('w2_missing', ['the W2 hunk of evo_webhook.php is not installed']);
                }
                if (!self::hasTable($ro, 'wa_seen_marks')) return $no('marks_missing', ['wa_seen_marks: absent']);
                return $ok(array_merge(['W2: installed', 'wa_seen_marks: present'], self::chaseLines($ro, $config, $dataDir)));
            case 'cold':
                return $ok(self::coldLines($ro, $config, $dataDir));
        }
        return $no('unknown_step', []);
    }

    /** What `on chase` prints: R-01's measurement, the would-be chases by handset, the holds, the persona, the paced drafts. */
    private static function chaseLines(\PDO $ro, array $config, string $dataDir): array
    {
        $lines = [];
        $since = UtcClock::stamp((int)(self::parseTime($config['quote_tracking_since'] ?? null) ?? (time() - 30 * self::DAY_S)));
        foreach (self::all($ro, "SELECT substr(seen_at, 1, 10) AS d, direction, kind, COUNT(*) AS n FROM wa_seen_marks WHERE seen_at >= ?
                                  GROUP BY d, direction, kind = 'lid_no_number' ORDER BY d", [$since]) as $r) {
            $lines[] = 'marks ' . $r['d'] . ' ' . ($r['kind'] === 'lid_no_number' ? 'lid_no_number' : $r['direction']) . '=' . (int)$r['n'];
        }
        if (self::hasTable($ro, 'quote_tracker')) {
            $lines[] = 'tracked quotes with an inbound mark after the send: ' . (int)self::one($ro,
                "SELECT COUNT(*) FROM quote_tracker t WHERE EXISTS (SELECT 1 FROM wa_seen_marks m WHERE m.direction = 'in' AND m.phone = t.phone AND m.seen_at > t.sent_at)");
            foreach (self::all($ro, "SELECT COALESCE(chase_hold, 'none') AS h, COUNT(*) AS n FROM quote_tracker WHERE status = 'open' GROUP BY h ORDER BY h") as $r) {
                $lines[] = 'open quotes held ' . $r['h'] . '=' . (int)$r['n'];
            }
        }
        try {
            if (!class_exists('QuoteChase')) require_once __DIR__ . '/QuoteChase.php';
            $labels = self::handsetLabels($config);
            foreach (\QuoteChase::demand($ro, $config, $dataDir, time()) as $h => $n) {
                $lines[] = 'would-be chases today on ' . ($labels[$h] ?? 'another handset') . ': ' . (int)$n;
            }
        } catch (\Throwable $e) {
            $lines[] = 'would-be chases: not read';
        }
        $lines[] = 'persona: ' . (QuoteChaseText::personaConfirmed($config) ? 'confirmed (sf_persona_confirmed set)' : 'held (sf_persona_confirmed unset): the composed forms stay held');
        if (self::hasTable($ro, 'followup_drafts')) {
            $kind = self::hasColumn($ro, 'followups', 'kind') ? " AND COALESCE(f.kind, 'enquiry') = 'enquiry'" : '';
            $lines[] = 'approved enquiry drafts that become paced: ' . (int)self::one($ro,
                "SELECT COUNT(*) FROM followup_drafts d JOIN followups f ON f.id = d.followup_id WHERE d.status = 'approved' AND f.closed_at IS NULL{$kind}");
        }
        return $lines;
    }

    /** What `on cold` prints: the cold quotes chasable and refused by reason, and the role statement (§H.2.10.9). */
    private static function coldLines(\PDO $ro, array $config, string $dataDir): array
    {
        $lines = [];
        if (self::hasTable($ro, 'quote_tracker')) {
            $by = [];
            try {
                if (!class_exists('AutomationPolicy')) require_once __DIR__ . '/AutomationPolicy.php';
                if (!class_exists('OwnedNumberHold')) require_once __DIR__ . '/OwnedNumberHold.php';
                $pol = \AutomationPolicy::forInstall($config, $dataDir, $ro);
                $own = \OwnedNumberHold::forInstall($config, $dataDir, $ro);
                foreach (self::all($ro, "SELECT phone, sent_channel FROM quote_tracker WHERE status = 'open' AND COALESCE(spoke_conv_id, 0) <= 0") as $r) {
                    $ch = (string)$r['sent_channel'];
                    $why = $own->holds($ch) ? 'owned_number' : $pol->refusal($ch, (string)$r['phone'], false);
                    $by[$why === '' ? 'chasable' : $why] = ($by[$why === '' ? 'chasable' : $why] ?? 0) + 1;
                }
            } catch (\Throwable $e) {
                $by = ['not_read' => 1];
            }
            ksort($by);
            foreach ($by as $k => $n) $lines[] = 'cold quotes ' . $k . '=' . $n;
        }
        $lines[] = 'a cold chase leaves from the quote\'s own number (support or account); a reply is answered there in that role, not the sales role';
        return $lines;
    }

    // ── checks ─────────────────────────────────────────────────────────────────────────────────────────────────

    /** @return array{status:string, counts:array<string,int>, notes:list<string>} */
    public static function check(\PDO $ro, array $config, string $dataDir, string $id, int $now): array
    {
        try {
            switch ($id) {
                case 'L-ON':  return self::lOn($ro, $config, $dataDir, $now);
                case 'Q1-ON': return self::q1On($ro, $config, $dataDir, $now);
                case 'Q2-ON': return self::q2On($ro, $config, $dataDir, $now);
                case 'Q3-ON': return self::q3On($ro, $config, $dataDir, $now, false);
                case 'Q5-ON': return self::q3On($ro, $config, $dataDir, $now, true);
            }
        } catch (\Throwable $e) {
            return self::res('FAIL read_error', [], ['the check could not read the book (' . get_class($e) . ')']);
        }
        return self::res('FAIL unknown_check', [], []);
    }

    // ── L-ON (§H.2.12.5) ───────────────────────────────────────────────────────────────────────────────────────

    private static function lOn(\PDO $ro, array $config, string $dataDir, int $now): array
    {
        $since = self::parseTime($config['wa_send_ledger_since'] ?? null);
        if ($since === null) return self::res('FAIL since_unreadable', [], ['wa_send_ledger_since: unset or unreadable']);
        $notes = ['wa_send_ledger_since: ' . UtcClock::stamp($since)];
        if ($now < $since + self::DAY_S) return self::res('NOT_YET too_soon', [], $notes);
        if (!self::hasTable($ro, 'wa_auto_sends')) return self::res('FAIL schema', [], array_merge($notes, ['wa_auto_sends: absent']));
        $c = ['rows' => 0, 'inside_switch_margin' => 0, 'hook_write_lost' => 0, 'wasender' => 0, 'noinstance' => 0,
              'orphans_settled' => 0, 'orphans_unswept' => 0, 'log_rows_matched' => 0];
        $fail = [];
        $labels = self::handsetLabels($config);
        $registered = $labels;   // the registry as read: label() adds every other line to $labels (§H.2.12.5; ruling R6)
        $from = UtcClock::stamp(max($since - self::DAY_S, $now - self::WINDOW_DAYS * self::DAY_S));

        // The ledger: rows by kind per handset per day, and the true total.
        foreach (self::all($ro, "SELECT local_day, handset, kind, COUNT(*) AS n FROM wa_auto_sends WHERE claimed_at >= ?
                                  GROUP BY local_day, handset, kind ORDER BY local_day, handset, kind", [$from]) as $r) {
            $c['rows'] += (int)$r['n'];
            $notes[] = 'ledger ' . $r['local_day'] . ' ' . self::label($labels, (string)$r['handset']) . ' ' . $r['kind'] . '=' . (int)$r['n'];
        }
        foreach (self::all($ro, "SELECT handset, local_day, COUNT(*) AS n, MAX(cap_day) AS cap FROM wa_auto_sends
                                  WHERE claimed_at >= ? AND proactive = 1 AND (outcome IS NULL OR outcome <> 'not_sent')
                                  GROUP BY handset, local_day ORDER BY local_day", [$from]) as $r) {
            $notes[] = 'proactive ' . $r['local_day'] . ' ' . self::label($labels, (string)$r['handset']) . ': ' . (int)$r['n']
                     . ' of ' . ($r['cap'] === null ? 'no cap recorded' : (int)$r['cap']);
        }

        // The positive control on the hooks (K2-23, Q19-F02): per Kampala day and kind, the Message Log's rows to match.
        $start = max(self::nextMidnight($since), $since + self::MARGIN_S);
        $log = []; $pre = [];
        if (self::hasTable($ro, 'notification_audit_log')) {
            $person = HumanTakeover::personEvents();
            $st = $ro->prepare('SELECT event, success, http_code, sent_at FROM notification_audit_log WHERE sent_at >= ? ORDER BY id');
            $st->execute([self::local($since - self::DAY_S)->format('Y-m-d H:i:s')]);
            foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $r) {
                $ev = (string)$r['event'];
                if ($ev === '' || in_array($ev, $person, true)) continue;
                if (substr($ev, -18) === '_suppressed_optout') continue;                   // written before any send
                if ((int)$r['success'] === 0 && $r['http_code'] === null) continue;        // written before any send
                $t = self::fromLocal((string)$r['sent_at']);
                if ($t === null || $t > $now) continue;
                $kind = self::kindOfEvent($ev);
                if ($t < $start) { $pre[$kind] = ($pre[$kind] ?? 0) + 1; continue; }
                $day = self::local($t)->format('Y-m-d');
                $log[$day][$kind] = ($log[$day][$kind] ?? 0) + 1;
            }
        }
        $startS = UtcClock::stamp($start);
        foreach ($pre as $kind => $n) {
            $had = (int)self::one($ro, 'SELECT COUNT(*) FROM wa_auto_sends WHERE kind = ? AND claimed_at >= ? AND claimed_at < ?',
                                  [$kind, UtcClock::stamp($since), $startS]);
            $c['inside_switch_margin'] += max(0, $n - $had);
        }
        $ledger = [];
        foreach (self::all($ro, 'SELECT local_day, kind, COUNT(*) AS n FROM wa_auto_sends WHERE claimed_at >= ? GROUP BY local_day, kind', [$startS]) as $r) {
            $ledger[(string)$r['local_day']][(string)$r['kind']] = (int)$r['n'];
        }
        $counts = self::stateCounts($ro);
        $lines = self::lostLines($config);
        $usable = self::logUsable();
        foreach ($log as $day => $kinds) {
            ksort($kinds);
            foreach ($kinds as $kind => $n) {
                $was = (int)($counts['wasender'][$day][$kind] ?? 0);
                $noi = (int)($counts['noinstance'][$day][$kind] ?? 0);
                $have = (int)($ledger[$day][$kind] ?? 0);
                $lost = (int)($lines[$day][$kind] ?? 0);
                $c['wasender'] += $was;
                $c['noinstance'] += $noi;
                $need = max(0, $n - $was - $noi);
                $short = max(0, $need - $have);
                $c['log_rows_matched'] += min($need, $have);
                if ($was > 0) $notes[] = 'wasender ' . $day . ' ' . $kind . '=' . $was;
                if ($noi > 0) $notes[] = 'noinstance ' . $day . ' ' . $kind . '=' . $noi;
                if ($lost > 0) $notes[] = 'hook_write_lost ' . $day . ' ' . $kind . ' lines=' . $lost;
                if ($short === 0) continue;
                $trace = $have > 0 || $was > 0 || $noi > 0 || $lost > 0;
                if (!$trace && $usable !== null && strcmp($usable, $day) < 0) {
                    $fail[] = 'hook_silent';
                    $notes[] = 'hook_silent ' . $day . ' ' . $kind . '=' . $short;
                    continue;
                }
                $c['hook_write_lost'] += $short;
                if (!$trace) $notes[] = 'plugin_log_not_read';
            }
        }

        // Orphans: a claim still in flight more than 900 s after a later claim on its handset.
        foreach (self::all($ro, "SELECT a.id, a.handset, a.claimed_at FROM wa_auto_sends a WHERE a.outcome IS NULL AND a.claimed_at >= ?", [$from]) as $r) {
            $later = self::one($ro, "SELECT 1 FROM wa_auto_sends b WHERE b.handset = ? AND b.mode = 'claim' AND b.id > ?
                                     AND CAST(strftime('%s', b.claimed_at) AS INTEGER) >= CAST(strftime('%s', ?) AS INTEGER) + ? LIMIT 1",
                                [(string)$r['handset'], (int)$r['id'], (string)$r['claimed_at'], self::ORPHAN_S]);
            if ($later !== false && $later !== null) { $fail[] = 'ledger_orphan'; continue; }
            if ($now - UtcClock::parse((string)$r['claimed_at']) >= self::ORPHAN_S) $c['orphans_unswept']++;
        }
        foreach (self::all($ro, "SELECT local_day, COUNT(*) AS n FROM wa_auto_sends WHERE detail = 'stale_orphan' AND claimed_at >= ? GROUP BY local_day", [$from]) as $r) {
            $c['orphans_settled'] += (int)$r['n'];
            $notes[] = 'stale_orphan ' . $r['local_day'] . '=' . (int)$r['n'];
        }
        if ((int)self::one($ro, "SELECT COUNT(*) FROM wa_auto_sends WHERE handset NOT GLOB 'i:?*'") > 0) $fail[] = 'handset_malformed';
        foreach (self::all($ro, 'SELECT DISTINCT handset FROM wa_auto_sends WHERE claimed_at >= ?', [$from]) as $r) {
            if (!isset($registered[(string)$r['handset']])) $notes[] = 'a handset not in the registry: ' . self::label($labels, (string)$r['handset']);
        }
        if (!$usable) $notes[] = 'data/plugin.log: not readable or not writable here';
        $notes = array_values(array_unique($notes));
        if ($fail !== []) return self::res('FAIL ' . $fail[0], $c, array_merge($notes, ['failed: ' . implode(', ', array_unique($fail))]));
        return self::res('PASS', $c, $notes);
    }

    /** A Message Log event's kind, as the hooks file it: AlertService's by its event (A11-04), the rest by NOTIFY_KINDS. */
    public static function kindOfEvent(string $event): string
    {
        if (strpos($event, 'ops_alert_') === 0) {
            if ($event === 'ops_alert_escalate') return 'handover_alert';
            if ($event === 'ops_alert_quote_list') return 'staff_list';
            return 'other';
        }
        return WaSendBudget::NOTIFY_KINDS[$event] ?? 'other';
    }

    /** quote_followup_state's wasender:<day>:<kind> and noinstance:<day>:<kind> counts. */
    private static function stateCounts(\PDO $ro): array
    {
        $out = ['wasender' => [], 'noinstance' => []];
        if (!self::hasTable($ro, 'quote_followup_state')) return $out;
        foreach (self::all($ro, "SELECT k, v FROM quote_followup_state WHERE k LIKE 'wasender:%' OR k LIKE 'noinstance:%'") as $r) {
            $p = explode(':', (string)$r['k']);
            if (count($p) !== 3) continue;
            $out[$p[0]][$p[1]][$p[2]] = (int)$r['v'];
        }
        return $out;
    }

    /**
     * data/plugin.log's `hook_write_lost <kind>` lines by Kampala day and kind, each placed from the zone PluginLog wrote
     * it in (UTC where the stamp says so); a line in a day's last minute counts for the next day too.
     */
    private static function lostLines(array $config): array
    {
        $out = [];
        $f = PluginLog::path();
        if (!is_file($f) || !is_readable($f)) return $out;
        if (!function_exists('dn_tz_obj')) require_once __DIR__ . '/timezone.php';
        $zone = dn_tz_obj($config);
        $h = @fopen($f, 'r');
        if ($h === false) return $out;
        while (($l = fgets($h)) !== false) {
            if (strpos($l, 'hook_write_lost ') === false) continue;
            if (!preg_match('/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})( UTC)?\] \[wa_send_ledger\] hook_write_lost ([a-z_]+)/', $l, $m)) continue;
            try {
                $t = (new \DateTime($m[1], $m[2] !== '' ? new \DateTimeZone('UTC') : $zone))->getTimestamp();
            } catch (\Throwable $e) { continue; }
            $kind = $m[3];
            $loc = self::local($t);
            $day = $loc->format('Y-m-d');
            $out[$day][$kind] = ($out[$day][$kind] ?? 0) + 1;
            if ($loc->format('H:i') === '23:59') {
                $next = self::local($t + 60)->format('Y-m-d');
                $out[$next][$kind] = ($out[$next][$kind] ?? 0) + 1;
            }
        }
        fclose($h);
        return $out;
    }

    /**
     * Whether data/plugin.log could take a line and can be read: PluginLog's own test (:41) and readable. Returns the
     * Kampala date of its earliest dated line (a judged day must come after it), or null when it is not usable.
     */
    private static function logUsable(): ?string
    {
        $f = PluginLog::path();
        $dir = dirname($f);
        if (!is_dir($dir) || !is_file($f) || !is_readable($f) || !is_writable($f)) return null;
        $h = @fopen($f, 'r');
        if ($h === false) return null;
        $first = null;
        while (($l = fgets($h)) !== false) {
            if (preg_match('/^\[(\d{4}-\d{2}-\d{2}) \d{2}:\d{2}:\d{2}/', $l, $m)) { $first = $m[1]; break; }
        }
        fclose($h);
        return $first;
    }

    // ── Q1-ON (§H.2.12.5) ──────────────────────────────────────────────────────────────────────────────────────

    private static function q1On(\PDO $ro, array $config, string $dataDir, int $now): array
    {
        $since = self::parseTime($config['quote_tracking_since'] ?? null);
        if ($since === null) return self::res('FAIL since_unreadable', [], ['quote_tracking_since: unset or unreadable']);
        $notes = ['quote_tracking_since: ' . UtcClock::stamp($since)];
        if (!self::hasTable($ro, 'quote_tracker')) return self::res('FAIL schema', [], array_merge($notes, ['quote_tracker: absent']));
        $sinceS = UtcClock::stamp($since);
        $c = ['confirming' => 0, 'tracked' => 0, 'awaiting_run' => 0, 'evidence_gone' => 0, 'ref_conflict' => 0, 'no_phone' => 0,
              'pending' => 0, 'pending_awaiting_run' => 0, 'followups_opened' => 0, 'quote_claims' => 0, 'sticky' => 0];
        $fail = [];
        $events = QuoteSendEvidence::EVENTS;
        $in = implode(',', array_fill(0, count($events), '?'));
        $rows = self::all($ro, "SELECT m.id, m.conversation_id, m.direction, m.event_key, m.body, m.sent_at FROM wa_messages m
                                 WHERE m.direction = 'out' AND m.event_key IN ({$in}) AND m.sent_at >= ? ORDER BY m.id",
                           array_merge($events, [$sinceS]));
        $c['tracked'] = (int)self::one($ro, 'SELECT COUNT(*) FROM quote_tracker WHERE sent_at >= ?', [$sinceS]);
        $confirming = [];
        foreach ($rows as $m) {
            $ev = QuoteSendEvidence::read($m);
            if ($ev === null) continue;
            $confirming[] = [$m, QuoteSendEvidence::key((string)$ev[1])];
        }
        $c['confirming'] = count($confirming);
        if ($now < $since + self::DAY_S || ($c['confirming'] === 0 && $c['tracked'] === 0)) return self::res('NOT_YET no_traffic', $c, $notes);

        // Parity, at the tracker's reach and by key (ruling R18 point 1; Q17-F04).
        $cursor = self::state($ro, 'tracker_cursor');
        $cur = ($cursor === null || !ctype_digit($cursor)) ? 0 : (int)$cursor;
        foreach ($confirming as [$m, $key]) {
            if ((int)$m['id'] > $cur) { $c['awaiting_run']++; continue; }
            if (self::one($ro, 'SELECT 1 FROM quote_tracker WHERE quote_key = ?', [$key]) !== false) continue;
            if (self::state($ro, 'ref_conflict:' . $key) !== null) continue;
            $phone = self::one($ro, 'SELECT phone FROM wa_conversations WHERE id = ?', [(int)$m['conversation_id']]);
            if ($phone === false || preg_replace('/\D+/', '', (string)$phone) === '') { $c['no_phone']++; continue; }
            $fail[] = 'tracker_parity';
        }
        foreach (self::all($ro, 'SELECT quote_key, evidence_msg_id FROM quote_tracker WHERE backfilled = 0 AND sent_at >= ?', [$sinceS]) as $t) {
            $m = self::row($ro, 'SELECT id, direction, event_key, body FROM wa_messages WHERE id = ?', [(int)$t['evidence_msg_id']]);
            if ($m === null) { $c['evidence_gone']++; continue; }
            $ev = QuoteSendEvidence::read($m);
            if ($ev === null || QuoteSendEvidence::key((string)$ev[1]) !== (string)$t['quote_key']) $fail[] = 'tracker_parity';
        }
        if ($c['awaiting_run'] > 0) $notes[] = 'confirming rows past the tracker\'s cursor (awaiting its run): ' . $c['awaiting_run'];

        // pending, judged at the tracker's own moment (Q16-F04).
        $ran = self::parseTime(self::state($ro, 'tracker_ran'));
        foreach (self::all($ro, "SELECT sent_at FROM quote_tracker WHERE owner_source = 'pending'") as $r) {
            $c['pending']++;
            $sent = UtcClock::parse((string)$r['sent_at']);
            if ($ran !== null && $ran - $sent >= self::PENDING_S) { $fail[] = 'pending_past_1h'; continue; }
            if ($now - $sent >= self::PENDING_S) $c['pending_awaiting_run']++;
        }
        $c['ref_conflict'] = (int)self::one($ro, "SELECT COUNT(*) FROM quote_followup_state WHERE k LIKE 'ref_conflict:%'");
        foreach (self::all($ro, 'SELECT owner_source, COUNT(*) AS n FROM quote_tracker GROUP BY owner_source ORDER BY owner_source') as $r) {
            $notes[] = 'owner source ' . $r['owner_source'] . '=' . (int)$r['n'];
        }
        $like = self::truthy($config['kyc_messages_like_crm'] ?? null);
        $notes[] = 'kyc_messages_like_crm: ' . ($like ? 'on' : 'off');
        if (!$like) $notes[] = 'with kyc_messages_like_crm off, a KYC quotation to a client with an earlier application is owned by that application (§H.2.9.4)';
        if (self::hasColumn($ro, 'wa_conversations', 'state')) {
            $c['sticky'] = (int)self::one($ro, "SELECT COUNT(*) FROM quote_tracker t JOIN wa_conversations w ON w.id = t.sent_conv_id
                                                 WHERE t.status = 'open' AND w.state = 'human_active'");
        }
        if (self::hasTable($ro, 'wa_seen_marks')) {
            foreach (self::all($ro, "SELECT substr(seen_at, 1, 10) AS d, direction, kind = 'lid_no_number' AS lid, COUNT(*) AS n FROM wa_seen_marks
                                      WHERE seen_at >= ? GROUP BY d, direction, lid ORDER BY d", [$sinceS]) as $r) {
                $notes[] = 'marks ' . $r['d'] . ' ' . ((int)$r['lid'] === 1 ? 'lid_no_number' : $r['direction']) . '=' . (int)$r['n'];
            }
        }
        // Until the chase first comes on: nothing opened, nothing claimed (step 6's own work once it has).
        if (self::hasColumn($ro, 'followups', 'kind')) {
            $c['followups_opened'] = (int)self::one($ro, "SELECT COUNT(*) FROM followups WHERE kind = 'quote' AND opened_at >= ?", [$sinceS]);
        }
        if (self::hasTable($ro, 'wa_auto_sends')) {
            $c['quote_claims'] = (int)self::one($ro, "SELECT COUNT(*) FROM wa_auto_sends WHERE kind = 'quote' AND claimed_at >= ?", [$sinceS]);
        }
        if (trim((string)($config['sf_chase_first_on'] ?? '')) === '') {
            if ($c['followups_opened'] > 0) $fail[] = 'chase_before_switch';
            if ($c['quote_claims'] > 0) $fail[] = 'chase_before_switch';
        } else {
            $notes[] = 'since the chase first came on: ' . $c['followups_opened'] . ' quote follow-ups opened, ' . $c['quote_claims'] . ' quote claims (step 6\'s, judged by Q3-ON)';
        }
        if ($fail !== []) return self::res('FAIL ' . $fail[0], $c, array_merge($notes, ['failed: ' . implode(', ', array_unique($fail))]));
        return self::res('PASS', $c, $notes);
    }

    // ── Q2-ON (§H.2.12.5) ──────────────────────────────────────────────────────────────────────────────────────

    private static function q2On(\PDO $ro, array $config, string $dataDir, int $now): array
    {
        $since = self::parseTime($config['quote_daily_list_since'] ?? null);
        if ($since === null) return self::res('FAIL since_unreadable', [], ['quote_daily_list_since: unset or unreadable']);
        $notes = ['quote_daily_list_since: ' . UtcClock::stamp($since)];
        if (!self::hasTable($ro, 'quote_list_sends')) return self::res('FAIL schema', [], array_merge($notes, ['quote_list_sends: absent']));
        $swept = (string)(self::state($ro, 'list_swept') ?? '');
        $days = [];
        for ($d = self::dayStart($since); $d <= $now; $d += self::DAY_S) {
            $day = self::local($d + 3600)->format('Y-m-d');
            $eleven = self::at($day, 11);
            if ((int)self::local($eleven)->format('N') === 7) continue;
            if ($eleven <= $since || $eleven > $now) continue;
            if ($swept === '' || strcmp($day, $swept) > 0) continue;
            $days[] = $day;
        }
        if ($days === []) return self::res('NOT_YET too_soon', [], $notes);
        $c = ['lists_sent' => 0, 'lists_uncertain' => 0, 'missed' => 0, 'no_longer_due' => 0, 'settle_not_landed' => 0,
              'handset_moved' => 0, 'optout_after_build' => 0, 'central_keys' => 0, 'sunday' => 0];
        $fail = [];
        $sales = self::salesHandset($config);
        $hasLedger = self::hasTable($ro, 'wa_auto_sends');
        $owners = [];   // owner_key => 'owner n', this run's index (§H.2.8.2)
        $c['sunday'] = 0;
        foreach (self::all($ro, "SELECT list_date FROM quote_list_sends WHERE outcome IN ('sent','uncertain')") as $r) {
            if ((int)self::local(self::at((string)$r['list_date'], 12))->format('N') === 7) $c['sunday']++;
        }
        if ($c['sunday'] > 0) $fail[] = 'sunday_list';
        foreach ($days as $day) {
            $byOwner = [];
            foreach (self::all($ro, 'SELECT * FROM quote_list_sends WHERE list_date = ? ORDER BY id', [$day]) as $r) $byOwner[(string)$r['owner_key']][] = $r;
            // A try that may have gone with no list row at all (a death after its claim, which the sweep leaves out; Q18-F03).
            if ($hasLedger) {
                $seen = [];
                foreach (self::all($ro, "SELECT ref FROM wa_auto_sends WHERE kind = 'staff_list' AND ref LIKE ? AND (outcome IS NULL OR outcome IN ('sent','uncertain'))",
                                   ['list:' . $day . ':%']) as $l) {
                    $owner = substr((string)$l['ref'], strlen('list:' . $day . ':'));
                    if (!isset($byOwner[$owner]) && !isset($seen[$owner])) { $seen[$owner] = true; $c['settle_not_landed']++; }
                }
            }
            foreach ($byOwner as $owner => $rs) {
                $ref = 'list:' . $day . ':' . $owner;
                $ledger = $hasLedger ? self::all($ro, "SELECT * FROM wa_auto_sends WHERE kind = 'staff_list' AND ref = ? ORDER BY id", [$ref]) : [];
                $done = null; $missed = null;
                foreach ($rs as $r) {
                    if (in_array($r['outcome'], ['sent', 'uncertain'], true)) $done = $r;
                    if ($r['outcome'] === 'missed') $missed = $r;
                }
                if ($done === null && $missed !== null) {
                    $c['missed']++;
                    $fail[] = (string)$missed['detail'] === 'digit_run' ? 'digit_run' : 'missed';
                    $notes[] = 'missed ' . $day . ' ' . self::ownerLabel($owners, $owner) . ': ' . (string)$missed['detail'];
                    continue;
                }
                if ($done === null) {
                    $tried = false;
                    foreach ($ledger as $l) if ($l['outcome'] === null || in_array($l['outcome'], ['sent', 'uncertain'], true)) $tried = true;
                    if ($tried) $c['settle_not_landed']++; else $c['no_longer_due']++;
                    continue;
                }
                $c[$done['outcome'] === 'sent' ? 'lists_sent' : 'lists_uncertain']++;
                // In the ledger, by kind and ref alone (Q17-F03, Q18-F03).
                if ($ledger === []) { $fail[] = 'list_ledger_missing'; }
                else {
                    $want = $done['outcome'] === 'sent' ? ['sent'] : ['sent', 'uncertain'];
                    $match = false; $landing = false; $live = false;
                    foreach ($ledger as $l) {
                        if ($l['outcome'] !== 'not_sent') $live = true;
                        if (in_array((string)$l['outcome'], $want, true) && !($done['outcome'] === 'sent' && in_array((string)$l['detail'], self::SWEPT, true))) $match = true;
                        if ($l['outcome'] === null || ($l['outcome'] === 'uncertain' && in_array((string)$l['detail'], self::SWEPT, true))) $landing = true;
                        if ($sales !== '' && (string)$l['handset'] !== $sales) $c['handset_moved']++;
                    }
                    if (!$live) $fail[] = 'list_ledger_not_sent';
                    elseif (!$match && $landing) $c['settle_not_landed']++;
                    elseif (!$match) $fail[] = 'list_ledger_mismatch';
                }
                // The sections as the list recorded them when it was built (A14-02).
                $built = (string)$done['created_at'];
                $sections = json_decode((string)$done['sections'], true);
                if (!is_array($sections)) { $fail[] = 'sections_unreadable'; continue; }
                foreach ($sections as $sec) {
                    $keys = (array)($sec['keys'] ?? []);
                    if (count($keys) !== min((int)($sec['n'] ?? -1), self::MAX_REFS)) $fail[] = 'sections_inconsistent';
                    foreach ($keys as $k) {
                        $t = self::row($ro, 'SELECT * FROM quote_tracker WHERE quote_key = ?', [(string)$k]);
                        if ($t === null || strcmp((string)$t['created_at'], $built) > 0) { $fail[] = 'list_key_unknown'; continue; }
                        if ($owner === 'central') { $c['central_keys']++; }
                        elseif ('staff:' . (string)$t['owner_staff_id'] !== $owner) { $fail[] = 'list_owner'; }
                        if ((string)($sec['section'] ?? '') === self::STOP_SECTION) continue;
                        $o = self::optoutAt($ro, $t, $built);
                        if ($o === 'before') $fail[] = 'listed_after_optout';
                        elseif ($o === 'later') $c['optout_after_build']++;
                    }
                }
                $openNow = $owner === 'central' ? null : (int)self::one($ro, "SELECT COUNT(*) FROM quote_tracker WHERE status = 'open' AND owner_staff_id = ?",
                                                                       [(int)substr($owner, 6)]);
                if ($openNow !== null) $notes[] = 'open now ' . self::ownerLabel($owners, $owner) . ': ' . $openNow . ' (the page, not compared)';
            }
        }
        $notes = array_values(array_unique($notes));
        if ($fail !== []) return self::res('FAIL ' . $fail[0], $c, array_merge($notes, ['failed: ' . implode(', ', array_unique($fail))]));
        return self::res('PASS', $c, $notes);
    }

    /** A live opt-out for the tracker row's phone, matched as the list matches it: 'before' the build's second, 'later', or ''. */
    private static function optoutAt(\PDO $ro, array $t, string $built): string
    {
        if (!self::hasTable($ro, 'contact_optouts')) return '';
        $p = (string)$t['phone'];
        if ($p === '' || $p === 'erased') return '';
        $phones = [$p => $p];
        if (!class_exists('QuoteTracker')) require_once __DIR__ . '/QuoteTracker.php';
        foreach (\QuoteTracker::convIds($ro, $p, (int)($t['sent_conv_id'] ?? 0)) as $id) {
            $d = (string)preg_replace('/\D+/', '', (string)self::one($ro, 'SELECT phone FROM wa_conversations WHERE id = ?', [$id]));
            if ($d !== '') $phones[$d] = $d;
        }
        $in = implode(',', array_fill(0, count($phones), '?'));
        $out = '';
        foreach (self::all($ro, "SELECT created_at, active, lifted_at FROM contact_optouts WHERE phone IN ({$in})", array_values($phones)) as $o) {
            // In force at the build: active and not lifted by then, or lifted (re-consent) only after it.
            $liftedAfter = $o['lifted_at'] !== null && strcmp((string)$o['lifted_at'], $built) > 0;
            $inForce = ((int)$o['active'] === 1 && ($o['lifted_at'] === null || $liftedAfter)) || ((int)$o['active'] === 0 && $liftedAfter);
            if (strcmp((string)$o['created_at'], $built) < 0) { if ($inForce) return 'before'; continue; }
            $out = 'later';
        }
        return $out;
    }

    // ── Q3-ON and Q5-ON (§H.2.12.5) ────────────────────────────────────────────────────────────────────────────

    private static function q3On(\PDO $ro, array $config, string $dataDir, int $now, bool $cold): array
    {
        $since = self::parseTime($config['quote_chase_not_before'] ?? null);
        if ($since === null) return self::res('FAIL since_unreadable', [], ['quote_chase_not_before: unset or unreadable']);
        $notes = ['quote_chase_not_before: ' . UtcClock::stamp($since)];
        if (!self::hasTable($ro, 'wa_auto_sends') || !self::hasTable($ro, 'quote_tracker') || !self::hasColumn($ro, 'followups', 'kind')) {
            return self::res('FAIL schema', [], array_merge($notes, ['the ledger, the tracker or followups.kind: absent']));
        }
        $sinceS = UtcClock::stamp($since);
        $trackSince = UtcClock::stamp((int)(self::parseTime($config['quote_tracking_since'] ?? null) ?? $since));
        $c = ['claims' => 0, 'sent' => 0, 'uncertain' => 0, 'not_sent' => 0, 'in_flight' => 0, 'reply_after_claim' => 0,
              'person_after_claim' => 0, 'reminder_after_claim' => 0, 'settle_not_landed' => 0, 'persona_renamed' => 0,
              'chase_sends' => 0, 'chased_quotations' => 0];
        $fail = [];
        $labels = self::handsetLabels($config);
        $claims = self::all($ro, "SELECT * FROM wa_auto_sends WHERE mode = 'claim' AND kind IN ('quote','enquiry') AND claimed_at >= ? ORDER BY id", [$sinceS]);
        $quoteOf = function (array $a) use ($ro): ?array {
            if (!preg_match('/^fu:(\d+):(\d+)$/', (string)$a['ref'], $m)) return null;
            return self::row($ro, "SELECT f.id AS fid, f.channel, f.quote_key, t.* FROM followups f JOIN quote_tracker t ON t.quote_key = f.quote_key
                                    WHERE f.id = ? AND f.kind = 'quote'", [(int)$m[1]]);
        };
        $judged = 0;
        foreach ($claims as $a) {
            $q = $a['kind'] === 'quote' ? $quoteOf($a) : null;
            if ($cold) {
                if ($q === null || (int)($q['spoke_conv_id'] ?? 0) > 0) continue;
                if ((string)$q['channel'] === 'account') $fail[] = 'cold_on_accounts';
            }
            $judged++;
            $c['claims']++;
            $c[$a['outcome'] === null ? 'in_flight' : (string)$a['outcome']]++;
            $t = UtcClock::parse((string)$a['claimed_at']);
            $loc = self::local($t);
            // The window the claim recorded (ruling R18 point 3).
            $h = (int)$loc->format('G');
            if ((int)$loc->format('N') === 7 || $a['win_from'] === null || $h < (int)$a['win_from'] || $h >= (int)$a['win_to']) $fail[] = 'outside_window';
            // Never after 20:00 on valid_until.
            if ($q !== null) {
                $cut = self::at((string)$q['valid_until'], 20);
                if ($cut === null || $t >= $cut) $fail[] = 'after_valid_until';
            }
            // The caps it recorded, over what it could count (a smaller id, the same handset and day, not not_sent).
            $live = "(outcome IS NULL OR outcome <> 'not_sent')";
            $day = (int)self::one($ro, "SELECT COUNT(*) FROM wa_auto_sends WHERE handset = ? AND local_day = ? AND proactive = 1 AND id < ? AND {$live}",
                                  [(string)$a['handset'], (string)$a['local_day'], (int)$a['id']]);
            if ($a['cap_day'] === null || $day + 1 > (int)$a['cap_day']) $fail[] = 'cap_day';
            $hour = (int)self::one($ro, "SELECT COUNT(*) FROM wa_auto_sends WHERE handset = ? AND proactive = 1 AND id < ? AND {$live}
                                         AND CAST(strftime('%s', claimed_at) AS INTEGER) > ?", [(string)$a['handset'], (int)$a['id'], $t - 3600]);
            if ($a['cap_hour'] === null || $hour + 1 > (int)$a['cap_hour']) $fail[] = 'cap_hour';
            // The spacing each earlier row asked for, by its own gap_s.
            $gap = self::one($ro, "SELECT MAX(CAST(strftime('%s', claimed_at) AS INTEGER) + gap_s) FROM wa_auto_sends WHERE handset = ? AND id < ? AND {$live}",
                             [(string)$a['handset'], (int)$a['id']]);
            if ($gap !== null && $gap !== false && $t < (int)$gap) $fail[] = 'spacing';
            // One customer message a person a Kampala day, in id order (S2-08); the other direction is a count (Q15-F01).
            if ((string)$a['phone'] !== '') {
                $pk = "'" . implode("','", WaSendBudget::PERSON_KINDS) . "'";
                if (self::one($ro, "SELECT 1 FROM wa_auto_sends WHERE phone = ? AND local_day = ? AND id < ? AND kind IN ({$pk}) AND {$live} LIMIT 1",
                              [(string)$a['phone'], (string)$a['local_day'], (int)$a['id']]) !== false) $fail[] = 'phone_today';
                $c['reminder_after_claim'] += (int)self::one($ro, "SELECT COUNT(*) FROM wa_auto_sends WHERE phone = ? AND local_day = ? AND id > ?
                                                                    AND kind IN ('reminder','winback') AND {$live}",
                                                              [(string)$a['phone'], (string)$a['local_day'], (int)$a['id']]);
            }
            if ($a['kind'] !== 'quote' || $q === null || !in_array((string)$a['outcome'], ['sent', 'uncertain'], true)) continue;
            // No chase claimed after the customer answered or a person took over, from what the sender could read (A14-08).
            $phone = (string)$q['phone'];
            $convs = self::convIds($ro, $phone, (int)$q['sent_conv_id']);
            $qsent = (string)$q['sent_at'];
            $claimS = (string)$a['claimed_at'];
            if ($convs !== []) {
                $inIds = implode(',', $convs);
                if (self::one($ro, "SELECT 1 FROM wa_messages WHERE conversation_id IN ({$inIds}) AND direction = 'in'
                                    AND (sent_at > ? OR created_at > ?) AND created_at < ? LIMIT 1", [$qsent, $qsent, $claimS]) !== false) $fail[] = 'chase_after_reply';
                $c['reply_after_claim'] += (int)self::one($ro, "SELECT COUNT(*) FROM wa_messages WHERE conversation_id IN ({$inIds}) AND direction = 'in'
                                                               AND created_at >= ?", [$claimS]);
            }
            if (self::hasTable($ro, 'wa_seen_marks') && $phone !== 'erased'
                && self::one($ro, "SELECT 1 FROM wa_seen_marks WHERE direction = 'in' AND phone = ? AND seen_at > ? AND seen_at < ? LIMIT 1",
                             [$phone, $qsent, $claimS]) !== false) $fail[] = 'chase_after_reply';
            if ($phone !== 'erased') {
                $who = HumanTakeover::phoneSince($ro, $phone, $qsent, UtcClock::stamp($t - 1));
                if ($who !== null) $fail[] = 'chase_after_person';
                elseif (HumanTakeover::phoneSince($ro, $phone, $claimS) !== null) $c['person_after_claim']++;
            }
            // Not from a partner number.
            if (self::hasTable($ro, 'wa_channels') && (string)self::one($ro, 'SELECT owner_type FROM wa_channels WHERE channel_id = ?', [(string)$q['channel']]) === 'partner') {
                $fail[] = 'partner_chase';
            }
            // The R-05 trap, by identity: no Team row carrying this chase's own sent id.
            $wa = (string)$a['wa_message_id'];
            if ($wa !== '' && self::one($ro, "SELECT 1 FROM wa_messages WHERE wa_message_id = ? AND agent_name = 'Team' LIMIT 1", [$wa]) !== false) $fail[] = 'echo_team';
        }
        if ($judged === 0 && !self::anyDue($ro, $since, $now, $cold)) return self::res('NOT_YET no_traffic', $c, $notes);

        // Each chase sent is in the ledger, act by act (Q16-F01), and is the template its draft records.
        $chased = [];
        $sends = self::all($ro, "SELECT s.*, f.quote_key, f.channel AS fchannel FROM followup_sends s JOIN followups f ON f.id = s.followup_id
                                  WHERE f.kind = 'quote' AND s.sent_at >= ? ORDER BY s.id", [$trackSince]);
        foreach ($sends as $s) {
            $q = self::row($ro, 'SELECT * FROM quote_tracker WHERE quote_key = ?', [(string)$s['quote_key']]);
            if ($cold && ($q === null || (int)($q['spoke_conv_id'] ?? 0) > 0)) continue;
            $c['chase_sends']++;
            $chased[(string)$s['quote_key']] = true;
            $rows = self::all($ro, "SELECT * FROM wa_auto_sends WHERE kind = 'quote' AND ref = ? ORDER BY id", ['fu:' . (int)$s['followup_id'] . ':' . (int)$s['attempt']]);
            $sentId = (string)($s['wa_message_id'] ?? '');
            $match = false; $landing = false; $live = false;
            foreach ($rows as $r) {
                if ($r['outcome'] !== 'not_sent') $live = true;
                if ($r['outcome'] === 'sent' && (string)($r['wa_message_id'] ?? '') === $sentId) $match = true;
                if ($r['outcome'] === null || ($r['outcome'] === 'uncertain' && in_array((string)$r['detail'], self::SWEPT, true))) $landing = true;
            }
            if ($rows === [] || !$live) $fail[] = 'chase_ledger_mismatch';
            elseif (!$match && $landing) $c['settle_not_landed']++;
            elseif (!$match) $fail[] = 'chase_ledger_mismatch';
            // The body: render() of the step, variant and form its draft's template id records, with the first name it carries.
            $model = (string)self::one($ro, 'SELECT model FROM followup_drafts WHERE id = ?', [(int)($s['draft_id'] ?? 0)]);
            $id = QuoteChaseText::parseId($model);
            if ($id === null || $q === null) { $fail[] = 'body_not_template'; continue; }
            $name = null;
            if ($id[2]) {
                $name = preg_match("/^Hello, this is (.+?)'s assistant at DishNet/u", trim((string)$s['body']), $mm) ? $mm[1] : '';
                if ($name === '') { $fail[] = 'body_not_template'; continue; }
            }
            if (!QuoteChaseText::matches((string)$s['body'], $id[0], $id[1], (string)$q['quote_ref'], (string)$q['valid_until'], $name, true)) {
                $fail[] = 'body_not_template';
                continue;
            }
            if ($name !== null) {
                try {
                    if (!class_exists('QuoteChase')) require_once __DIR__ . '/QuoteChase.php';
                    $today = \QuoteChase::persona($config, $dataDir, $ro, self::accounts($ro), (string)$s['fchannel']);
                    if ($today !== null && $today !== $name) $c['persona_renamed']++;
                } catch (\Throwable $e) { /* not read: nothing counted */ }
            }
        }
        $c['chased_quotations'] = count($chased);
        $notes[] = 'chase sends ' . $c['chase_sends'] . ', quotations chased ' . $c['chased_quotations'] . ' (never compared)';
        foreach (self::all($ro, "SELECT handset, COALESCE(outcome, 'in_flight') AS o, COUNT(*) AS n FROM wa_auto_sends WHERE kind = 'quote' AND mode = 'claim'
                                  AND claimed_at >= ? GROUP BY handset, o ORDER BY handset, o", [$sinceS]) as $r) {
            $notes[] = 'quote claims ' . self::label($labels, (string)$r['handset']) . ' ' . $r['o'] . '=' . (int)$r['n'];
        }
        if ($cold) {
            foreach (self::all($ro, "SELECT f.close_reason AS r, COUNT(*) AS n FROM followups f JOIN quote_tracker t ON t.quote_key = f.quote_key
                                      WHERE f.kind = 'quote' AND f.closed_at IS NOT NULL AND COALESCE(t.spoke_conv_id, 0) <= 0
                                      AND f.opened_at >= ? GROUP BY f.close_reason ORDER BY f.close_reason", [$sinceS]) as $r) {
                $notes[] = 'cold follow-ups closed ' . (string)$r['r'] . '=' . (int)$r['n'];
            }
        }
        $notes = array_values(array_unique($notes));
        if ($fail !== []) return self::res('FAIL ' . $fail[0], $c, array_merge($notes, ['failed: ' . implode(', ', array_unique($fail))]));
        return self::res('PASS', $c, $notes);
    }

    /** At least one quote follow-up came due on an open day before today, since the chase came on (Q3-ON's traffic). */
    private static function anyDue(\PDO $ro, int $since, int $now, bool $cold): bool
    {
        $join = $cold ? ' JOIN quote_tracker t ON t.quote_key = f.quote_key AND COALESCE(t.spoke_conv_id, 0) <= 0' : '';
        foreach (self::all($ro, "SELECT f.due_at FROM followups f{$join} WHERE f.kind = 'quote' AND f.due_at IS NOT NULL AND f.due_at >= ? AND f.due_at < ?",
                           [UtcClock::stamp($since), UtcClock::stamp(self::dayStart($now))]) as $r) {
            if ((int)self::local(UtcClock::parse((string)$r['due_at']))->format('N') !== 7) return true;
        }
        return false;
    }

    // ── shared reads ───────────────────────────────────────────────────────────────────────────────────────────

    /** Handset => a label by role and index (no instance name, no person): `h1 (sales)`. */
    public static function handsetLabels(array $config): array
    {
        $out = [];
        $i = 0;
        foreach (['sales', 'support', 'account'] as $role) {
            $h = WaSendBudget::handset((string)($config['evo_instance_' . $role] ?? ''));
            if ($h === '') continue;
            if (!isset($out[$h])) $out[$h] = 'h' . (++$i) . ' (' . $role . ')';
            elseif (strpos($out[$h], $role) === false) $out[$h] = substr($out[$h], 0, -1) . ', ' . $role . ')';
        }
        return $out;
    }

    /**
     * A handset by role and index (§H.2.5.7): a department's from handsetLabels(); any other line is given the next index
     * of this run, in the order it is first named — never its instance name, a staff id or a number.
     */
    private static function label(array &$labels, string $h): string
    {
        if (!isset($labels[$h])) $labels[$h] = 'h' . (count($labels) + 1) . ' (another line)';
        return $labels[$h];
    }

    /** An owner by index within this run (`owner 1…n`, §H.2.8.2), in the order first named; never the staff id. */
    private static function ownerLabel(array &$owners, string $owner): string
    {
        if ($owner === 'central') return 'the central list';
        if (!isset($owners[$owner])) $owners[$owner] = 'owner ' . (count($owners) + 1);
        return $owners[$owner];
    }

    /** A read-only stand-in for the store's retailers.json, over $ro (what LineOwner::of reads for today's first name). */
    private static function accounts(\PDO $ro)
    {
        $rows = [];
        if (self::hasTable($ro, 'retailers')) {
            foreach ($ro->query('SELECT data FROM [retailers] ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN) as $j) {
                $r = json_decode((string)$j, true);
                if (is_array($r)) $rows[] = $r;
            }
        }
        return new class($rows) {
            private $rows;
            public function __construct(array $rows) { $this->rows = $rows; }
            public function load(string $file): array { return $file === 'retailers.json' ? $this->rows : []; }
            public function findOne(string $file, string $field, $value): ?array
            {
                if ($file !== 'retailers.json') return null;
                foreach ($this->rows as $r) if ((string)($r[$field] ?? '') === (string)$value) return $r;
                return null;
            }
        };
    }

    private static function salesHandset(array $config): string
    {
        return WaSendBudget::handset((string)($config['evo_instance_sales'] ?? ''));
    }

    private static function convIds(\PDO $ro, string $phone, int $sentConvId): array
    {
        if (!class_exists('QuoteTracker')) require_once __DIR__ . '/QuoteTracker.php';
        return \QuoteTracker::convIds($ro, $phone, $sentConvId);
    }

    private static function schemaRow(\PDO $ro, string $migration): bool
    {
        if (!self::hasTable($ro, '_schema_checks')) return false;
        return (int)self::one($ro, 'SELECT COUNT(*) FROM _schema_checks WHERE migration = ? AND found = expected', [$migration]) > 0;
    }

    private static function state(\PDO $ro, string $k): ?string
    {
        if (!self::hasTable($ro, 'quote_followup_state')) return null;
        $v = self::one($ro, 'SELECT v FROM quote_followup_state WHERE k = ?', [$k]);
        return ($v === false || $v === null) ? null : (string)$v;
    }

    private static function parseTime($v): ?int
    {
        if (is_int($v)) return $v > 0 ? $v : null;
        if (!is_string($v) || trim($v) === '') return null;
        $s = trim($v);
        $t = ctype_digit($s) ? (int)$s : UtcClock::parse($s);
        return $t > 0 ? $t : null;
    }

    private static function truthy($v): bool
    {
        if (is_bool($v)) return $v;
        if (is_int($v)) return $v === 1;
        return is_string($v) && in_array(strtolower(trim($v)), ['1', 'true', 'on', 'yes'], true);
    }

    private static function local(int $t): \DateTime
    {
        return (new \DateTime('@' . $t))->setTimezone(new \DateTimeZone(self::TZ));
    }

    /** A Message Log stamp, written with date() in the install's zone (Kampala), as a unix time. */
    private static function fromLocal(string $s): ?int
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', trim($s))) return null;
        try { return (new \DateTime(trim($s), new \DateTimeZone(self::TZ)))->getTimestamp(); } catch (\Throwable $e) { return null; }
    }

    /** Kampala midnight of $t's day. */
    private static function dayStart(int $t): int
    {
        return (new \DateTime(self::local($t)->format('Y-m-d') . ' 00:00:00', new \DateTimeZone(self::TZ)))->getTimestamp();
    }

    /** The first Kampala midnight after $t. */
    private static function nextMidnight(int $t): int
    {
        return self::dayStart(self::dayStart($t) + self::DAY_S + 3600);
    }

    /** $hour:00 Kampala on a Y-m-d date; null when the date cannot be read. */
    private static function at(string $ymd, int $hour): ?int
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($ymd))) return null;
        try { return (new \DateTime(trim($ymd) . sprintf(' %02d:00:00', $hour), new \DateTimeZone(self::TZ)))->getTimestamp(); } catch (\Throwable $e) { return null; }
    }

    private static function res(string $status, array $counts, array $notes): array
    {
        return ['status' => $status, 'counts' => array_map('intval', $counts), 'notes' => array_values(array_map('strval', $notes))];
    }

    private static function hasTable(\PDO $pdo, string $name): bool
    {
        $st = $pdo->prepare("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = ?");
        $st->execute([$name]);
        return (int)$st->fetchColumn() > 0;
    }

    private static function hasColumn(\PDO $pdo, string $table, string $col): bool
    {
        if (!self::hasTable($pdo, $table)) return false;
        foreach ($pdo->query('PRAGMA table_info(' . $table . ')')->fetchAll(\PDO::FETCH_ASSOC) as $c) {
            if (($c['name'] ?? '') === $col) return true;
        }
        return false;
    }

    /** @return mixed the first column of the first row, or false */
    private static function one(\PDO $pdo, string $sql, array $args = [])
    {
        $st = $pdo->prepare($sql);
        $st->execute($args);
        return $st->fetchColumn();
    }

    private static function row(\PDO $pdo, string $sql, array $args = []): ?array
    {
        $st = $pdo->prepare($sql);
        $st->execute($args);
        $r = $st->fetch(\PDO::FETCH_ASSOC);
        return is_array($r) ? $r : null;
    }

    private static function all(\PDO $pdo, string $sql, array $args = []): array
    {
        $st = $pdo->prepare($sql);
        $st->execute($args);
        return $st->fetchAll(\PDO::FETCH_ASSOC);
    }
}
