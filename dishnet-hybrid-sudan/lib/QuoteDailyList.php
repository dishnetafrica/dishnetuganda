<?php
declare(strict_types=1);

require_once __DIR__ . '/StaffJobsGate.php';
require_once __DIR__ . '/UtcClock.php';
require_once __DIR__ . '/JobBudget.php';
require_once __DIR__ . '/StaffDirectory.php';
require_once __DIR__ . '/QuoteTracker.php';

/**
 * QuoteDailyList — the daily list and the staff page, P3 (5.18.104, Uganda, root docs/73 §H.2.11; contract C3, C8;
 * rulings R18, R19).
 *
 * Every Monday to Saturday between 08:00 and 11:00 Kampala, each salesperson who owns open quotations, or quotations
 * answered this week, gets one WhatsApp list of them from the sales number: quote numbers and counts only, never a name,
 * a phone or an amount (C8). What a salesperson cannot be sent — quotations with no owner, an owner made inactive, one
 * with no phone, one outside the pilot ids — goes on the central list, to alert_whatsapp. The names are on the staff
 * page, tabs/sales/my_quotes.php, own quotations only (D13).
 *
 *   on()            Uganda (StaffJobsGate), quote_daily_list_enabled and quote_tracking_enabled
 *   pageOpen()      Uganda and the list on: the page's route and its one menu entry (§H.2.11.5)
 *   pendingToday()  the owner keys still to be sent today — due, and no row today other than not_sent; the ledger's
 *                   staff_list reservation asks it too (WaSendBudget, §H.2.5.4; coordinator.md item 3)
 *   build()         one owner's list, read at one moment: its text, its sections (count and listed keys) and its refs
 *   digitRunFree()  the privacy guard: with the listed refs taken out, no run of 9 or more digits (§H.2.11.3 step 0)
 *   run()           one run of cron/quote_daily_list.php: the 11:00 sweep, or at most one list sent (§H.2.11.1, §H.2.11.3)
 *   sweep()         the once-a-day sweep that writes the owners still due `missed`, with their last code
 *   pageRows()      the staff page's rows, with the customer's name, for one salesperson or (seesAll) for everyone
 *
 * A list is built under BEGIN IMMEDIATE on the store's connection, writing nothing there: its moment, $built, is taken
 * once the lock is held, and becomes the row's created_at (ruling R18). The claim is decided on a fresh read of the
 * files taken just before it (ruling R19). Every Kampala date and hour is computed in a literal Africa/Kampala zone.
 * Inert unless on(); South Sudan never reaches any of it. Read failures throw: the job then sends nothing. PHP 7.4.
 */
final class QuoteDailyList
{
    /** The literal zone every Kampala date and hour here is computed in (R-16). */
    public const TZ = 'Africa/Kampala';

    /** The sections, in the text's order (§H.2.11.4); `quote_list_sends.sections` records them in this order. */
    const SECTIONS = ['due', 'call', 'replied', 'stop', 'expiring', 'maybe_sent'];
    const LABELS = [
        'due'        => 'Reminder due today (sent automatically)',
        'call'       => 'Yours to call (no automatic reminder)',
        'replied'    => 'Replied, not yet paid — call them',
        'stop'       => 'Asked us to stop — do not message',
        'expiring'   => 'Expiring today',
        'maybe_sent' => 'May have been sent — check the chat',
    ];
    /** The words a ref carries in brackets in the text (§H.2.11.4); fixed, never data. */
    const NOTES = [
        'never_wrote' => 'never wrote',
        'own_number'  => 'your own number',
        'chase_off'   => 'chase is off',
        'unread'      => 'open WhatsApp — the reply could not be read here',
    ];
    /** The central list's totals line: one short word per section. */
    const TOTAL_WORDS = ['due' => 'due', 'call' => 'to call', 'replied' => 'replied', 'stop' => 'stop', 'expiring' => 'expiring',
                         'maybe_sent' => 'may have gone'];
    /** At most this many refs per section, then "and n more" (§H.2.11.4). */
    const MAX_REFS = 15;
    /** The window: Monday to Saturday, from 08:00 to 11:00 Kampala (§H.2.11.1). */
    const HOUR_FROM = 8;
    const HOUR_TO   = 11;
    /** One send: started only with this many seconds left (§H.2.13.4); the job's own budget is one more. */
    const SEND_S = 20;
    const OWN_S  = 21;
    /** AlertService's cooldown on a list's key: one a day (§H.2.11.3 step 2). */
    const COOLDOWN_MIN = 1440;
    /** The central list's owner key. */
    const CENTRAL = 'central';
    /** "This week": today and the six Kampala days before it. */
    const WEEK_DAYS = 7;
    /** The skipped step the chase records for a held persona form (lib/QuoteChase.php skipStep): theirs to call. */
    const PERSONA_HELD = 'persona_wording_pending';
    /**
     * The ledger's final answers that bind one owner's list alone (its ref's tries spent, §H.2.5.3 step 1): kept as that
     * owner's list_last code, after which run() goes on to the next owner still due and leaves this one to the sweep,
     * which writes it `missed` with this code, the true one (§H.2.11.1, §H.2.11.3; as Part O's sender goes on past a
     * spent row, OM84). `already_sent` writes its own row. The other final codes (ledger_off, not_claimable, no_instance,
     * zone) are the same for every owner on the handset.
     */
    const OWNER_FINAL = ['tries_exhausted'];

    // ── the switches ────────────────────────────────────────────────────────────────────────────────────────────

    /** Uganda, the list on and tracking on (§H.2.11.1). */
    public static function on(array $config, ?string $dataDir): bool
    {
        return self::truthy($config['quote_daily_list_enabled'] ?? null) && self::truthy($config['quote_tracking_enabled'] ?? null)
            && StaffJobsGate::applies($config, $dataDir);
    }

    /** The staff page and its menu entry: Uganda and the list on (§H.2.11.5). */
    public static function pageOpen(array $config, ?string $dataDir): bool
    {
        return self::truthy($config['quote_daily_list_enabled'] ?? null) && StaffJobsGate::applies($config, $dataDir);
    }

    /** Monday to Saturday, 08:00 <= hour < 11:00 Kampala. */
    public static function inWindow(int $t): bool
    {
        $l = self::local($t);
        if ((int)$l->format('N') === 7) return false;
        $h = (int)$l->format('G');
        return $h >= self::HOUR_FROM && $h < self::HOUR_TO;
    }

    /** The sweep's moment: Monday to Saturday, at or after 11:00 Kampala, and not yet swept today (§H.2.11.1). */
    public static function sweepDue(\PDO $pdo, int $t): bool
    {
        $l = self::local($t);
        if ((int)$l->format('N') === 7 || (int)$l->format('G') < self::HOUR_TO) return false;
        return self::state($pdo, 'list_swept') !== self::localDay($t);
    }

    // ── who is due, and with what ───────────────────────────────────────────────────────────────────────────────

    /**
     * The owner keys still to be sent today: those with something to list, and no `quote_list_sends` row today other than
     * not_sent — the central list included. Staff keys by id, the central list last. Reads only; the ledger's claim asks
     * it inside its own transaction.
     *
     * @return string[]
     */
    public static function pendingToday(\PDO $pdo, array $config, ?string $dataDir, int $now): array
    {
        if (!self::hasTable($pdo, 'quote_tracker') || !self::hasTable($pdo, 'quote_list_sends')) return [];
        $st = $pdo->prepare("SELECT DISTINCT owner_key FROM quote_list_sends WHERE list_date = ? AND outcome <> 'not_sent'");
        $st->execute([self::localDay($now)]);
        $done = array_flip(array_map('strval', $st->fetchAll(\PDO::FETCH_COLUMN)));
        $out = [];
        foreach (self::dueKeys($pdo, $config, $dataDir, $now) as $k) {
            if (!isset($done[$k])) $out[] = $k;
        }
        return $out;
    }

    /** The owner keys with at least one quotation to list at $at, staff by id, the central list last. */
    private static function dueKeys(\PDO $pdo, array $config, ?string $dataDir, int $at): array
    {
        $all = self::scope($pdo, $config, $dataDir, $at);
        $keys = [];
        foreach ($all['keys'] as $k => $rows) {
            $listable = array_filter($rows, function ($r) { return !empty($r['_listable']); });
            if ($listable === []) continue;
            $c = self::classify($pdo, $config, $dataDir, array_values($listable), $at);
            if ($c['keys'] !== []) $keys[] = $k;
        }
        usort($keys, function ($a, $b) {
            if ($a === self::CENTRAL) return 1;
            if ($b === self::CENTRAL) return -1;
            return (int)substr($a, 6) <=> (int)substr($b, 6);
        });
        return $keys;
    }

    /**
     * The rows each owner key holds at $at: the tracker rows that are open, or that left open this week (for the week's
     * counts and the answered and opted-out sections), each assigned to `staff:<id>` when its owner is active, has a
     * phone and is inside the pilot ids (quote_daily_list_staff, when set), else to the central list. A row whose owner
     * is still `pending` (under 1 h, §H.2.9.4) is on no list yet.
     *
     * @return array{keys: array<string,array>, phones: array<string,string>}
     */
    private static function scope(\PDO $pdo, array $config, ?string $dataDir, int $at): array
    {
        $ws = UtcClock::stamp(self::weekStart($at));
        $st = $pdo->prepare("SELECT * FROM quote_tracker WHERE (status = 'open' OR sent_at >= ? OR status_at >= ?)
                                AND owner_source <> 'pending' ORDER BY sent_at, quote_key");
        $st->execute([$ws, $ws]);
        $rows = $st->fetchAll(\PDO::FETCH_ASSOC);
        $staff = self::retailers($pdo);
        $pilot = self::pilotIds($config);
        $tenant = null;
        try {
            if (!class_exists('TenantProfile')) require_once __DIR__ . '/TenantProfile.php';
            $tenant = \TenantProfile::current($config, $dataDir);
        } catch (\Throwable $e) { $tenant = null; }
        $keys = []; $phones = [];
        foreach ($rows as $r) {
            $status = (string)$r['status'];
            $r['_listable'] = $status === 'open'
                || (in_array($status, ['answered', 'opted_out'], true) && (string)($r['status_at'] ?? '') >= $ws);
            $k = self::CENTRAL;
            $id = $r['owner_staff_id'] === null ? 0 : (int)$r['owner_staff_id'];
            if ($id > 0 && isset($staff[$id]) && StaffDirectory::isActive($staff[$id]) && ($pilot === null || in_array($id, $pilot, true))) {
                $ph = $tenant === null ? null : StaffDirectory::phoneOf($staff[$id], $tenant);
                if ($ph !== null && $ph !== '') { $k = 'staff:' . $id; $phones[$k] = $ph; }
            }
            $keys[$k][] = $r;
        }
        return ['keys' => $keys, 'phones' => $phones];
    }

    /**
     * Each listed row's sections at $at (§H.2.11.4). A row whose phone has a live opt-out (any channel, any scope), or
     * that is opted_out, goes under `stop` and nowhere else (S2-20). Otherwise: answered → replied; open with its chase
     * due today → due; open with no automatic reminder (no chase or a held one, the chase off, a held persona step) → call;
     * and, besides, open and valid only until today → expiring; open with a chase step that may have gone this week →
     * maybe_sent. An open row whose chase sends its next step on a later day is on no section.
     *
     * @return array{sections: array<string,string[]>, notes: array<string,string>, refs: array<string,string>, keys: array<string,bool>}
     */
    private static function classify(\PDO $pdo, array $config, ?string $dataDir, array $rows, int $at): array
    {
        if (!class_exists('QuoteChase')) require_once __DIR__ . '/QuoteChase.php';
        $chaseOn = \QuoteChase::on($config, $dataDir);
        $cold = $chaseOn && \QuoteChase::cold($config);
        $today = self::localDay($at);
        $tomorrow = UtcClock::stamp(self::dayStart($at) + 86400);
        $wsDay = self::localDay(self::weekStart($at));
        $hasLedger = self::hasTable($pdo, 'wa_auto_sends');
        $hasOpt = self::hasTable($pdo, 'contact_optouts');
        $out = ['sections' => array_fill_keys(self::SECTIONS, []), 'notes' => [], 'refs' => [], 'keys' => []];
        $fu = $pdo->prepare('SELECT closed_at, due_at FROM followups WHERE id = ?');
        $held = $pdo->prepare("SELECT 1 FROM followup_events WHERE followup_id = ? AND event = 'skipped' AND detail LIKE ? LIMIT 1");
        $maybe = $hasLedger ? $pdo->prepare("SELECT 1 FROM wa_auto_sends WHERE kind = 'quote' AND ref LIKE ? AND outcome = 'uncertain'
                                                AND local_day >= ? LIMIT 1") : null;
        foreach ($rows as $r) {
            $key = (string)$r['quote_key'];
            $status = (string)$r['status'];
            $put = function (string $sec) use (&$out, $key): void { $out['sections'][$sec][] = $key; };
            $out['refs'][$key] = (string)$r['quote_ref'];
            $out['keys'][$key] = true;
            if ($status === 'opted_out' || ($hasOpt && self::optedOut($pdo, $r))) { $put('stop'); continue; }
            if ($status === 'answered') {
                $put('replied');
                if ((string)($r['status_reason'] ?? '') === 'unread_inbound') $out['notes'][$key] = 'unread';
                continue;
            }
            if ($status !== 'open') { unset($out['keys'][$key]); continue; }
            $fid = (int)($r['followup_id'] ?? 0);
            $f = null;
            if ($fid > 0) { $fu->execute([$fid]); $f = $fu->fetch(\PDO::FETCH_ASSOC) ?: null; }
            $chased = $chaseOn && $f !== null && $f['closed_at'] === null;
            if ($chased && $f['due_at'] !== null && (string)$f['due_at'] < $tomorrow) {
                $put('due');
            } elseif ($chased) {
                $held->execute([$fid, '%' . self::PERSONA_HELD . '%']);
                if ($held->fetchColumn() !== false) $put('call');
            } else {
                $put('call');
                $hold = (string)($r['chase_hold'] ?? '');
                if ($hold === 'never_wrote' || ((int)($r['spoke_conv_id'] ?? 0) <= 0 && !$cold)) $out['notes'][$key] = 'never_wrote';
                elseif ($hold === 'owned_number') $out['notes'][$key] = 'own_number';
                elseif (!$chaseOn) $out['notes'][$key] = 'chase_off';
            }
            if ((string)$r['valid_until'] === $today) $put('expiring');
            if ($maybe !== null && $fid > 0) {
                $maybe->execute(['fu:' . $fid . ':%', $wsDay]);
                if ($maybe->fetchColumn() !== false) $put('maybe_sent');
            }
            $listed = false;
            foreach (self::SECTIONS as $s) if (in_array($key, $out['sections'][$s], true)) $listed = true;
            if (!$listed) unset($out['keys'][$key]);
        }
        return $out;
    }

    /** A live opt-out (active = 1, any channel, any scope) for the row's phone, matched as the tracker matches it. */
    private static function optedOut(\PDO $pdo, array $r): bool
    {
        $p = (string)$r['phone'];
        if ($p === '' || $p === 'erased') return false;
        $phones = [$p => $p];
        foreach (QuoteTracker::convIds($pdo, $p, (int)($r['sent_conv_id'] ?? 0)) as $id) {
            $st = $pdo->prepare('SELECT phone FROM wa_conversations WHERE id = ?');
            $st->execute([$id]);
            $d = (string)preg_replace('/\D+/', '', (string)$st->fetchColumn());
            if ($d !== '') $phones[$d] = $d;
        }
        $in = implode(',', array_fill(0, count($phones), '?'));
        $st = $pdo->prepare("SELECT 1 FROM contact_optouts WHERE active = 1 AND phone IN ({$in}) LIMIT 1");
        $st->execute(array_values($phones));
        return $st->fetchColumn() !== false;
    }

    /** The week's counts over $rows: sent, replied, reminded (chase sends that went or may have) and paid (§H.2.11.4). */
    private static function week(\PDO $pdo, array $rows, int $at): array
    {
        $ws = UtcClock::stamp(self::weekStart($at));
        $wsDay = self::localDay(self::weekStart($at));
        $n = ['sent' => 0, 'replied' => 0, 'reminded' => 0, 'paid' => 0];
        $st = self::hasTable($pdo, 'wa_auto_sends')
            ? $pdo->prepare("SELECT COUNT(*) FROM wa_auto_sends WHERE kind = 'quote' AND ref LIKE ? AND outcome IN ('sent','uncertain')
                               AND local_day >= ?") : null;
        foreach ($rows as $r) {
            if ((string)$r['sent_at'] >= $ws) $n['sent']++;
            $sa = (string)($r['status_at'] ?? '');
            if ((string)$r['status'] === 'answered' && $sa >= $ws) $n['replied']++;
            if ((string)$r['status'] === 'paid' && $sa >= $ws) $n['paid']++;
            if ($st !== null && (int)($r['followup_id'] ?? 0) > 0) {
                $st->execute(['fu:' . (int)$r['followup_id'] . ':%', $wsDay]);
                $n['reminded'] += (int)$st->fetchColumn();
            }
        }
        return $n;
    }

    // ── one owner's list ────────────────────────────────────────────────────────────────────────────────────────

    /**
     * One owner's list at $built (the caller holds BEGIN IMMEDIATE and took $built once it held it).
     *
     * @return array{text:string, sections:array, items:int, refs:string[], phone:?string}
     *   sections: one entry per section in SECTIONS' order — ['section' => …, 'n' => count, 'keys' => the listed quote_keys,
     *   at most 15]; no name, phone or text (ruling R18)
     */
    public static function build(\PDO $pdo, array $config, ?string $dataDir, string $ownerKey, int $built): array
    {
        $all = self::scope($pdo, $config, $dataDir, $built);
        $rows = $all['keys'][$ownerKey] ?? [];
        $listable = array_values(array_filter($rows, function ($r) { return !empty($r['_listable']); }));
        $c = self::classify($pdo, $config, $dataDir, $listable, $built);
        $sections = []; $refs = [];
        foreach (self::SECTIONS as $s) {
            $keys = $c['sections'][$s];
            $shown = array_slice($keys, 0, self::MAX_REFS);
            $sections[] = ['section' => $s, 'n' => count($keys), 'keys' => $shown];
            foreach ($shown as $k) $refs[$k] = substr($k, 4);   // the listed refs: each key's own number ('ref:' + number)
        }
        $team = null; $weekRows = $rows;
        if ($ownerKey === self::CENTRAL) {
            $team = array_fill_keys(self::SECTIONS, 0);
            $weekRows = [];
            foreach ($all['keys'] as $rs) {
                $weekRows = array_merge($weekRows, $rs);
                $l = array_values(array_filter($rs, function ($r) { return !empty($r['_listable']); }));
                if ($l === []) continue;
                $tc = self::classify($pdo, $config, $dataDir, $l, $built);
                foreach (self::SECTIONS as $s) $team[$s] += count($tc['sections'][$s]);
            }
        }
        $text = self::render($ownerKey, $c, $sections, self::week($pdo, $weekRows, $built), $team, $built);
        return ['text' => $text, 'sections' => $sections, 'items' => count($c['keys']), 'refs' => array_values($refs),
                'phone' => $all['phones'][$ownerKey] ?? null];
    }

    /** The text (§H.2.11.4): quote numbers and counts only — no customer name, phone or amount, and no staff name. */
    private static function render(string $ownerKey, array $c, array $sections, array $week, ?array $team, int $at): string
    {
        $date = self::local($at)->format('l j F');
        $lines = [$ownerKey === self::CENTRAL
            ? '📋 DishNet — open quotations with no salesperson to list them, ' . $date
            : '📋 DishNet — your open quotations, ' . $date];
        foreach ($sections as $sec) {
            if ($sec['n'] === 0) continue;
            $sep = in_array($sec['section'], ['call', 'replied'], true) ? ' · ' : ', ';
            $items = [];
            foreach ($sec['keys'] as $k) {
                $ref = (string)($c['refs'][$k] ?? substr($k, 4));
                $note = in_array($sec['section'], ['call', 'replied'], true) ? ($c['notes'][$k] ?? '') : '';
                $items[] = $note !== '' ? $ref . ' (' . self::NOTES[$note] . ')' : $ref;
            }
            $more = $sec['n'] > count($sec['keys']) ? ' and ' . ($sec['n'] - count($sec['keys'])) . ' more' : '';
            $lines[] = self::LABELS[$sec['section']] . ': ' . $sec['n'] . ' — ' . implode($sep, $items) . $more;
        }
        if ($team !== null) {
            $t = [];
            foreach (self::SECTIONS as $s) $t[] = self::TOTAL_WORDS[$s] . ' ' . (int)$team[$s];
            $lines[] = 'Team totals: ' . implode(' · ', $t);
        }
        $lines[] = ($team !== null ? 'This week (everyone): ' : 'This week: ') . 'sent ' . $week['sent'] . ' · replied ' . $week['replied']
                 . ' · reminded ' . $week['reminded'] . ' · paid ' . $week['paid'];
        $lines[] = 'Names and details: Sales → My quotations';
        return implode("\n", $lines);
    }

    /**
     * The privacy guard (C8; A11-03): with each listed ref taken out where it stands as a whole token, the text holds no
     * run of 9 or more digits. Pure.
     *
     * @param string[] $refs
     */
    public static function digitRunFree(string $text, array $refs): bool
    {
        usort($refs, function ($a, $b) { return strlen((string)$b) <=> strlen((string)$a); });
        foreach ($refs as $ref) {
            $ref = (string)$ref;
            if ($ref === '') continue;
            $text = preg_replace('/(?<![^\s])' . preg_quote($ref, '/') . '(?![^\s,(])/u', ' ', $text);
            if (!is_string($text)) return false;   // a text the guard cannot read is never sent
        }
        return !preg_match('/\d{9,}/', $text);
    }

    // ── one run of the job ──────────────────────────────────────────────────────────────────────────────────────

    /**
     * One run of cron/quote_daily_list.php. The sweep comes first, as its own branch ahead of the window's return
     * (A13-03); otherwise, inside the window, at most one list: the next owner still due, passing over any whose list
     * the ledger refused for good today (OWNER_FINAL, counted as tries_spent), built under the lock, guarded, claimed on
     * the fresh read, sent, settled, recorded (§H.2.11.3). $clock is the run's clock (time(), or a test's).
     *
     * @return array<string,int> counts only
     */
    public static function run(\PDO $pdo, array $config, string $dataDir, $store, string $pluginRoot, callable $clock, int $until): array
    {
        $c = ['swept' => 0, 'missed' => 0, 'outside_window' => 0, 'none_due' => 0, 'built' => 0, 'digit_run' => 0,
              'held_fresh_read' => 0, 'deferred' => 0, 'already_sent' => 0, 'sent' => 0, 'uncertain' => 0, 'not_sent' => 0,
              'row_lost' => 0, 'cut' => 0, 'tries_spent' => 0];
        $now = (int)$clock();
        if (self::sweepDue($pdo, $now)) {
            if (!JobBudget::mayStart($until, self::SEND_S, $now)) { $c['cut'] = 1; return $c; }
            $s = self::sweep($pdo, $config, $dataDir, $now);
            $c['swept'] = 1; $c['missed'] = $s['missed'];
            return $c;
        }
        if (!self::inWindow($now)) { $c['outside_window'] = 1; return $c; }
        if (!JobBudget::mayStart($until, self::SEND_S, $now)) { $c['cut'] = 1; return $c; }
        $pending = self::pendingToday($pdo, $config, $dataDir, $now);
        $day = self::localDay($now);
        // The next owner still due whose list the ledger has not refused for good (OWNER_FINAL): an owner whose tries are
        // spent stays due, so the sweep writes it missed with that code, but never holds back the owners after it.
        $key = null;
        foreach ($pending as $k) {
            if (in_array(self::state($pdo, 'list_last:' . $day . ':' . $k), self::OWNER_FINAL, true)) { $c['tries_spent']++; continue; }
            $key = $k;
            break;
        }
        if ($key === null) { $c['none_due'] = $pending === [] ? 1 : 0; return $c; }

        // The build (ruling R18): under BEGIN IMMEDIATE, writing nothing; its moment taken once the lock is held.
        $pdo->exec('BEGIN IMMEDIATE');
        try {
            $built = (int)$clock();
            $b = self::build($pdo, $config, $dataDir, $key, $built);
            $pdo->exec('COMMIT');
        } catch (\Throwable $e) {
            try { $pdo->exec('ROLLBACK'); } catch (\Throwable $e2) { /* nothing held */ }
            throw $e;
        }
        $c['built'] = 1;
        if ($b['items'] === 0) { $c['none_due'] = 1; return $c; }
        $createdAt = UtcClock::stamp($built);
        $sections = json_encode($b['sections'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        // 0. The privacy guard, before the claim: a text that fails is never claimed or sent, and is missed at once.
        if (!self::digitRunFree($b['text'], $b['refs'])) {
            if (self::writeRow($pdo, $day, $key, 'missed', 0, 'digit_run', null, UtcClock::stamp($now))) $c['digit_run'] = 1;
            else $c['row_lost'] = 1;
            return $c;
        }

        // 1. The fresh read the claim decides on (ruling R19), and the claim.
        if (!JobBudget::mayStart($until, self::SEND_S, (int)$clock())) { $c['cut'] = 1; return $c; }
        if (!class_exists('PluginConfig')) require_once __DIR__ . '/PluginConfig.php';
        $lc = \PluginConfig::read($pluginRoot, $dataDir);
        if (!class_exists('WaSendBudget')) require_once __DIR__ . '/WaSendBudget.php';
        if (!self::truthy($lc['quote_daily_list_enabled'] ?? null) || !self::truthy($lc['quote_tracking_enabled'] ?? null)
            || !\WaSendBudget::on($lc, $dataDir)) {
            $c['held_fresh_read'] = 1;
            return $c;
        }
        if (!class_exists('EvolutionApiService')) require_once __DIR__ . '/EvolutionApiService.php';
        $inst = (string)(new \EvolutionApiService($lc))->instanceFor('sales');
        $ledger = \WaSendBudget::forInstall($lc, $dataDir, $store, null, $clock);
        $ref = 'list:' . $day . ':' . $key;
        $cl = $ledger->claim($inst, 'staff_list', $ref);
        if (empty($cl['ok'])) {
            $code = (string)($cl['defer'] ?? 'ledger_unknown');
            if ($code === 'already_sent') {
                // A try of this list today went or may have gone: recorded uncertain with this run's build, never again.
                if (self::writeRow($pdo, $day, $key, 'uncertain', $b['items'], 'already_sent', $sections, $createdAt)) $c['already_sent'] = 1;
                else $c['row_lost'] = 1;
                return $c;
            }
            self::setState($pdo, 'list_last:' . $day . ':' . $key, $code);
            $c['deferred'] = 1;
            return $c;
        }
        $claim = (int)$cl['claim'];

        // 2. The send, on the job's own AlertService; the raw answer read back.
        if (!class_exists('AlertService')) require_once __DIR__ . '/AlertService.php';
        $al = new \AlertService($store, $lc);
        $alertKey = 'quote_list:' . $day . ':' . ($key === self::CENTRAL ? self::CENTRAL : substr($key, 6));
        $res = $key === self::CENTRAL ? $al->notify($alertKey, $b['text'], self::COOLDOWN_MIN)
                                      : $al->notifyTo((string)$b['phone'], $alertKey, $b['text'], self::COOLDOWN_MIN);
        $r = $al->lastResult();

        // 3. The settle, from the raw answer (A11-01), then the list's row with the outcome the settle asked for.
        if ($r !== null) {
            $waId = (string)($r['data']['key']['id'] ?? ($r['key']['id'] ?? ''));
            if (!empty($r['ok'])) {
                $ledger->settle($claim, 'sent', ['wa_message_id' => $waId]);
                $outcome = 'sent'; $detail = null;
            } else {
                $maybe = \EvolutionApiService::mayHaveBeenSent($r);
                $ledger->settle($claim, 'failed', ['maybe_sent' => $maybe, 'wa_message_id' => $waId, 'detail' => $maybe ? 'maybe_sent' : 'send_failed']);
                $outcome = $maybe ? 'uncertain' : 'not_sent'; $detail = $maybe ? 'maybe_sent' : 'send_failed';
            }
        } elseif ((string)($res['reason'] ?? '') === 'cooldown') {
            $ledger->settle($claim, 'failed', ['maybe_sent' => true, 'detail' => 'alert_cooldown']);
            $outcome = 'uncertain'; $detail = 'alert_cooldown';
        } else {
            $ledger->settle($claim, 'failed', ['detail' => 'no_answer']);
            $outcome = 'not_sent'; $detail = 'no_answer';
        }
        if (self::writeRow($pdo, $day, $key, $outcome, $b['items'], $detail, $sections, $createdAt)) $c[$outcome] = 1;
        else $c['row_lost'] = 1;
        if ($outcome === 'not_sent') self::setState($pdo, 'list_last:' . $day . ':' . $key, 'not_sent');
        return $c;
    }

    /**
     * The once-a-day sweep (§H.2.11.3, "Missed lists"; A13-03): in one transaction, every owner still due today is written
     * `missed` with its last code (list_last:<date>:<owner_key>) or no_attempt; list_swept is set to today and every
     * list_last key dated today or earlier is removed. An owner whose ref holds a staff_list ledger row in flight, sent or
     * uncertain is left out: that try may have gone (Q18-F03). A list switched on after today's window wrote no list today
     * and sweeps nothing.
     *
     * @return array{missed:int, left_out:int}
     */
    public static function sweep(\PDO $pdo, array $config, ?string $dataDir, int $now): array
    {
        $out = ['missed' => 0, 'left_out' => 0];
        $day = self::localDay($now);
        $since = UtcClock::parse($config['quote_daily_list_since'] ?? '');
        $lateStart = $since > 0 && $since >= self::dayStart($now) + self::HOUR_TO * 3600;
        $pdo->exec('BEGIN IMMEDIATE');
        try {
            if (!$lateStart) {
                $hasLedger = self::hasTable($pdo, 'wa_auto_sends');
                $tried = $hasLedger ? $pdo->prepare("SELECT 1 FROM wa_auto_sends WHERE kind = 'staff_list' AND ref = ?
                                                       AND (outcome IS NULL OR outcome IN ('sent','uncertain')) LIMIT 1") : null;
                foreach (self::pendingToday($pdo, $config, $dataDir, $now) as $key) {
                    if ($tried !== null) {
                        $tried->execute(['list:' . $day . ':' . $key]);
                        if ($tried->fetchColumn() !== false) { $out['left_out']++; continue; }
                    }
                    $code = self::state($pdo, 'list_last:' . $day . ':' . $key);
                    $ins = $pdo->prepare("INSERT INTO quote_list_sends (list_date, owner_key, outcome, item_count, detail, sections, created_at)
                                          VALUES (?, ?, 'missed', 0, ?, NULL, ?)");
                    $ins->execute([$day, $key, ($code === null || $code === '') ? 'no_attempt' : $code, UtcClock::stamp($now)]);
                    $out['missed']++;
                }
            }
            $pdo->prepare("INSERT OR REPLACE INTO quote_followup_state (k, v, updated_at) VALUES ('list_swept', ?, datetime('now'))")
                ->execute([$day]);
            $pdo->prepare("DELETE FROM quote_followup_state WHERE k LIKE 'list_last:%' AND substr(k, 11, 10) <= ?")->execute([$day]);
            $pdo->exec('COMMIT');
        } catch (\Throwable $e) {
            try { $pdo->exec('ROLLBACK'); } catch (\Throwable $e2) { /* nothing was committed */ }
            throw $e;
        }
        return $out;
    }

    /** A quote_list_sends row; false when the store refused it (the day's row exists already). */
    private static function writeRow(\PDO $pdo, string $day, string $key, string $outcome, int $items, ?string $detail, ?string $sections, string $createdAt): bool
    {
        try {
            $pdo->prepare('INSERT INTO quote_list_sends (list_date, owner_key, outcome, item_count, detail, sections, created_at)
                           VALUES (?, ?, ?, ?, ?, ?, ?)')
                ->execute([$day, $key, $outcome, $items, $detail, $sections, $createdAt]);
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    // ── the staff page (§H.2.11.5) ──────────────────────────────────────────────────────────────────────────────

    /**
     * The page's rows: every quotation the list reads (open, or answered or opted out this week), for one salesperson
     * ($ownerId: rows whose owner_staff_id is theirs) or for everyone ($ownerId null: seesAll). Each row: key, ref, owner
     * (staff id or null), conv (the conversation the call is made on), name (the customer's), section (the first of
     * due, call, replied, stop, or 'scheduled'), stop, expiring, maybe_sent, next (the next step's moment, unix, or null)
     * and hold (a fixed code). The phone is never returned.
     *
     * @return array<int,array>
     */
    public static function pageRows(\PDO $pdo, array $config, ?string $dataDir, ?int $ownerId, int $now): array
    {
        if (!self::hasTable($pdo, 'quote_tracker')) return [];
        $ws = UtcClock::stamp(self::weekStart($now));
        $sql = "SELECT * FROM quote_tracker WHERE (status = 'open' OR (status IN ('answered','opted_out') AND status_at >= ?))";
        $args = [$ws];
        if ($ownerId !== null) { $sql .= ' AND owner_staff_id = ?'; $args[] = $ownerId; }
        $st = $pdo->prepare($sql . ' ORDER BY sent_at DESC, quote_key');
        $st->execute($args);
        $rows = $st->fetchAll(\PDO::FETCH_ASSOC);
        if (!class_exists('QuoteChase')) require_once __DIR__ . '/QuoteChase.php';
        $chaseOn = \QuoteChase::on($config, $dataDir);
        $out = [];
        $fu = $pdo->prepare('SELECT closed_at, close_reason, due_at FROM followups WHERE id = ?');
        foreach ($rows as $r) {
            $c = self::classify($pdo, $config, $dataDir, [$r], $now);
            $key = (string)$r['quote_key'];
            $section = 'scheduled';
            foreach (['due', 'call', 'replied', 'stop'] as $s) {
                if (in_array($key, $c['sections'][$s], true)) { $section = $s; break; }
            }
            $next = null; $hold = null;
            $f = null;
            if ((int)($r['followup_id'] ?? 0) > 0) { $fu->execute([(int)$r['followup_id']]); $f = $fu->fetch(\PDO::FETCH_ASSOC) ?: null; }
            if ($f !== null && $f['closed_at'] === null && $chaseOn && $f['due_at'] !== null) $next = UtcClock::parse((string)$f['due_at']);
            if (in_array($section, ['call', 'scheduled'], true) && ($section === 'call' || $next === null)) {
                if (!$chaseOn) $hold = 'chase_off';
                elseif (trim((string)($r['chase_hold'] ?? '')) !== '') $hold = (string)$r['chase_hold'];
                elseif ($f !== null && $f['closed_at'] !== null) $hold = 'closed:' . (string)($f['close_reason'] ?? '');
                elseif ($f !== null && $section === 'call') $hold = self::PERSONA_HELD;
                elseif ($f === null) $hold = 'not_opened';
            }
            $conv = (int)($r['spoke_conv_id'] ?? 0) > 0 ? (int)$r['spoke_conv_id'] : (int)($r['sent_conv_id'] ?? 0);
            $out[] = [
                'key' => $key, 'ref' => (string)$r['quote_ref'], 'owner' => $r['owner_staff_id'] === null ? null : (int)$r['owner_staff_id'],
                'conv' => $conv, 'name' => self::customerName($pdo, $r, $conv), 'section' => $section, 'stop' => $section === 'stop',
                'expiring' => in_array($key, $c['sections']['expiring'], true), 'maybe_sent' => in_array($key, $c['sections']['maybe_sent'], true),
                'unread' => ($c['notes'][$key] ?? '') === 'unread', 'note' => (string)($c['notes'][$key] ?? ''),
                'next' => $section === 'stop' ? null : $next, 'hold' => $section === 'stop' ? null : $hold, 'status' => (string)$r['status'],
            ];
        }
        return $out;
    }

    /** The page's section words (§H.2.11.5): the list's, and 'scheduled' for a chase whose next step is on a later day. */
    const PAGE_SECTIONS = [
        'due'       => 'Reminder due today (sent automatically)',
        'call'      => 'Yours to call (no automatic reminder)',
        'replied'   => 'Replied, not yet paid — call them',
        'stop'      => 'Asked us to stop — do not message',
        'scheduled' => 'Automatic reminder later',
    ];
    /** A hold in plain words (the page's "or the hold in plain words"); fixed text, never data. */
    const HOLD_WORDS = [
        'chase_off'        => 'automatic reminders are off',
        'never_wrote'      => 'the customer never wrote to us: no automatic reminder',
        'owned_number'     => 'on your own WhatsApp number: no automatic reminder',
        'partner_number'   => "on a partner's number: no automatic reminder",
        'colleague'        => "one of DishNet's own numbers",
        'ambiguous'        => 'the chat does not say what they asked about: no automatic reminder',
        'escalation'       => 'handed to a person: no automatic reminder',
        'before_switch_on' => 'sent before automatic reminders began',
        'past_validity'    => 'too close to its expiry for a reminder',
        'not_opened'       => 'reminder not set up yet',
        self::PERSONA_HELD => "this step's reminder is held: yours to call",
        'closed:exhausted' => 'all three reminders were sent',
    ];

    /** A hold code in plain words; an unknown code reads as "no automatic reminder". */
    public static function holdWords(?string $hold): string
    {
        if ($hold === null || $hold === '') return '';
        if (isset(self::HOLD_WORDS[$hold])) return self::HOLD_WORDS[$hold];
        if (strpos($hold, 'channel_refused:') === 0) return 'the number may not send automatic messages';
        return 'no automatic reminder';
    }

    /** A moment as the page prints it: Kampala, e.g. "Wed 4 Nov, 10:00". */
    public static function when(int $t): string
    {
        return self::local($t)->format('D j M, H:i');
    }

    /** The customer's name: the conversation's display_name or crm_client_name, else the KYC application's, else the lead's. */
    private static function customerName(\PDO $pdo, array $r, int $conv): string
    {
        if ($conv > 0) {
            $st = $pdo->prepare('SELECT display_name, crm_client_name FROM wa_conversations WHERE id = ?');
            $st->execute([$conv]);
            $c = $st->fetch(\PDO::FETCH_ASSOC);
            if (is_array($c)) {
                foreach (['display_name', 'crm_client_name'] as $f) {
                    $v = trim((string)($c[$f] ?? ''));
                    if ($v !== '') return $v;
                }
            }
        }
        $qid = $r['quote_id'] === null ? '' : (string)(int)$r['quote_id'];
        $ref = (string)$r['quote_ref'];
        foreach ([['kyc_applications', 'quote_id', $qid], ['kyc_applications', 'quote_ref', $ref]] as [$t, $f, $v]) {
            foreach (self::blobWhere($pdo, $t, $f, $v) as $a) {
                $n = trim(trim((string)($a['firstname'] ?? '')) . ' ' . trim((string)($a['lastname'] ?? '')));
                if ($n !== '') return $n;
            }
        }
        foreach ([['leads', 'crm_quote_id', $qid], ['leads', 'quote_ref', $ref]] as [$t, $f, $v]) {
            foreach (self::blobWhere($pdo, $t, $f, $v) as $l) {
                $n = trim((string)($l['customer_name'] ?? ''));
                if ($n !== '') return $n;
            }
        }
        return '';
    }

    // ── small things ────────────────────────────────────────────────────────────────────────────────────────────

    /** quote_daily_list_staff: the pilot ids, or null when unset (no limit). A list, or digits separated by anything. */
    private static function pilotIds(array $config): ?array
    {
        $raw = $config['quote_daily_list_staff'] ?? null;
        if ($raw === null || $raw === '' || $raw === []) return null;
        $parts = is_array($raw) ? $raw : (preg_split('/[^0-9]+/', (string)$raw) ?: []);
        $ids = [];
        foreach ($parts as $p) { $p = trim((string)$p); if ($p !== '' && ctype_digit($p) && (int)$p > 0) $ids[] = (int)$p; }
        return $ids;
    }

    /** The retailers rows (the store's JSON collection), by id. */
    private static function retailers(\PDO $pdo): array
    {
        $out = [];
        if (!self::hasTable($pdo, 'retailers')) return $out;
        foreach ($pdo->query('SELECT id, data FROM retailers ORDER BY id')->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $d = json_decode((string)$r['data'], true);
            if (!is_array($d)) continue;
            $d['id'] = (int)$r['id'];
            $out[(int)$r['id']] = $d;
        }
        return $out;
    }

    /** The staff names the page shows managers, by id ('' when the account has none). */
    public static function staffNames(\PDO $pdo): array
    {
        $out = [];
        foreach (self::retailers($pdo) as $id => $r) $out[$id] = trim((string)($r['name'] ?? ''));
        return $out;
    }

    /** The rows of a JSON collection whose $.field equals $value (as text, trimmed). An absent table is empty. */
    private static function blobWhere(\PDO $pdo, string $table, string $field, string $value): array
    {
        if (trim($value) === '' || !self::hasTable($pdo, $table)) return [];
        $st = $pdo->prepare("SELECT id, data FROM [{$table}] WHERE TRIM(CAST(json_extract(data, '$.{$field}') AS TEXT)) = ? ORDER BY id");
        $st->execute([trim($value)]);
        $out = [];
        foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $d = json_decode((string)$r['data'], true);
            if (is_array($d)) $out[] = $d;
        }
        return $out;
    }

    private static function state(\PDO $pdo, string $k): ?string
    {
        if (!self::hasTable($pdo, 'quote_followup_state')) return null;
        $st = $pdo->prepare('SELECT v FROM quote_followup_state WHERE k = ?');
        $st->execute([$k]);
        $v = $st->fetchColumn();
        return $v === false ? null : (string)$v;
    }

    private static function setState(\PDO $pdo, string $k, string $v): void
    {
        $pdo->prepare("INSERT OR REPLACE INTO quote_followup_state (k, v, updated_at) VALUES (?, ?, datetime('now'))")->execute([$k, $v]);
    }

    private static function hasTable(\PDO $pdo, string $name): bool
    {
        $st = $pdo->prepare("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = ?");
        $st->execute([$name]);
        return (int)$st->fetchColumn() > 0;
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

    /** Kampala midnight of $t's day, as unix time. */
    private static function dayStart(int $t): int
    {
        return (new \DateTime(self::localDay($t) . ' 00:00:00', new \DateTimeZone(self::TZ)))->getTimestamp();
    }

    /** The start of "this week": Kampala midnight six days before today's. */
    private static function weekStart(int $t): int
    {
        return self::dayStart(self::dayStart($t) - (self::WEEK_DAYS - 1) * 86400 + 3600);
    }

    /** The master flag rule: 1, true, on, yes. */
    private static function truthy($v): bool
    {
        if ($v === true) return true;
        return in_array(strtolower(trim((string)($v ?? ''))), ['1', 'true', 'on', 'yes'], true);
    }
}
