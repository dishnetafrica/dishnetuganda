<?php
// 5.18.104's rehearsal: the RELEASE's own reading, as a reference the script's stage-A lines are set against — never the
// script's probe. The code root given is the PIN's from Git (lib/, profiles/, tools/, migrations/ archived into a folder
// of the rehearsal's), or, once 5.18.104 serves, a copy of the installed one. The store and the settings are read on a
// SCRATCH copy the rehearsal makes (the book by SQLite's backup, the three settings files and the vault copied beside
// it); nothing live is opened. Synthetic: run by the rehearsal as root, read-only, with a vault of its own:
//   php rh104-ref.php keys  <code root>
//        — SalesFollowupLadder's counts: allKeys() and settingKeys(), and, by the ladder's own 'part' column, Part V's and
//          Part O's switch and record keys (their rows' rowKeys() and their sf_skip_/sf_override_ keys, as allKeys() has
//          them) and their settings — what QF-G4, VN-G1 and OF-G1 count
//   php rh104-ref.php conf  <code root> <scratch folder>
//        — PluginConfig::read(<code root>, <scratch folder>) (the code root's data/config.json the scratch's root.json):
//          QF-N1's stored values in its own words, dn_tz(), WASender configured or not, the daily cap the copy reads,
//          the OpenAI key set or not (never its value), ai_provider, how many of Part V's settings are set, and remh=
//          the reminder hours as the code root's ReminderDay::hours() reads those settings (<from>-<until>, or - when the
//          code root has no ReminderDay) — the hours the chase's hold is judged in (104-5's day-1 cycles); win= the
//          proactive window the code root's WaSendBudget::window() reads from those settings (<from>-<to>, or - when it
//          has none) — the hours 104-5o's week of offers cycles is placed in
//   php rh104-ref.php staff <code root> <scratch folder>
//        — the active staff accounts, those without a phone of record (StaffDirectory::phoneOf on the Uganda profile)
//          and the active admins with one: OF-N3's three counts
//   php rh104-ref.php ladder <code root>
//        — SalesFollowupLadder's one order and its rules as 104-4 and 104-5 read them: steps= (steps(), in order),
//          optional= (the rows ORDER passes whether on or off), labels= (each row's number as `show` prints it, n:step),
//          skip_<step>= (skipCascade() of tracking, voice-measure and offers-test), off_<step>= (offCascade() of the same),
//          sequence= (SEQUENCE), the ack limits' gap default and range (VoiceAckLimits), the window's defaults
//          (WaSendBudget::DEFAULTS' hour_from and hour_to), and on_cold= the keys `on cold`'s ON line names, in the order
//          tools/sales_followup.php's sf_on() writes them when no option is given and its need is met: the statement it
//          asks for first (the cold row's record key), then the row's switch keys, then its stamp when it has one;
//          on_voice-dry-run=, on_voice-staff=, on_voice-reply= the keys their ON lines name in the same order: the row's
//          switch keys, its stamp, then its options' keys in the row's own order (each option given, as 104-5v gives them);
//          and, for the rollback (104-6): hooks= each SEQUENCE step whose off-hook runs in a rollback, with its place
//          against the clearing of its keys (step:before|after, in SEQUENCE's order), vkeys= and okeys= Part V's and
//          Part O's switch and record keys by the ladder's own 'part' column (their rows' rowKeys() and sf_skip_/
//          sf_override_ keys, as allKeys() has them), and lastkeys= lastKeys() — the record keys and Part P's two
// Prints one line of key=value words: counts, fixed codes and key names — never a value of a setting, a name, a phone,
// an e-mail, a staff id or a key. Refuses a code root without the release's files and a run without a vault of its own.
declare(strict_types=1);
ini_set('display_errors', 'stderr');
[$_, $mode, $root, $dir] = $argv + [null, '', '', ''];
if ((string)getenv('DN_VAULT_FILE') === '') { fwrite(STDERR, "refusing: DN_VAULT_FILE must point into the sandbox\n"); exit(2); }
foreach (['lib/SalesFollowupLadder.php', 'lib/PluginConfig.php', 'lib/StaffDirectory.php', 'lib/TenantProfile.php', 'profiles/uganda.json'] as $f) {
    if (!is_file($root . '/' . $f)) { fwrite(STDERR, "refusing: the code root holds no $f\n"); exit(2); }
}
if ($mode !== 'keys' && $mode !== 'ladder' && !is_file($dir . '/rh104-scratch')) { fwrite(STDERR, "refusing: the folder is not a scratch copy of the rehearsal's (no rh104-scratch mark)\n"); exit(2); }
$setNow = function (array $c, string $k): bool { return array_key_exists($k, $c) && $c[$k] !== null && $c[$k] !== '' && $c[$k] !== false; };
$tok = function ($v): string { return (string)preg_replace('/[^A-Za-z0-9_.:\/+-]/', '', (string)$v); };
try {
    if ($mode === 'keys') {
        require_once $root . '/lib/UtcClock.php';
        require_once $root . '/lib/SalesFollowupLadder.php';
        $all = SalesFollowupLadder::allKeys(); $set = SalesFollowupLadder::settingKeys();
        $part = function (string $p) use ($all, $set): array {
            $sw = []; $st = [];
            foreach (SalesFollowupLadder::STEPS as $s => $row) {
                if ((string)($row['part'] ?? '') !== $p) continue;
                foreach (SalesFollowupLadder::rowKeys($s) as $k) $sw[] = $k;
                $sw[] = 'sf_skip_' . $s; $sw[] = 'sf_override_' . $s;
            }
            foreach (SalesFollowupLadder::SETTINGS as $k => $d) if ((string)($d['part'] ?? '') === $p && in_array($k, $set, true)) $st[] = $k;
            return [count(array_intersect(array_values(array_unique($sw)), $all)), count($st)];
        };
        [$vs, $vt] = $part('V'); [$os, $ot] = $part('O');
        // onkeys: allKeys() less the skip and override markers — the switches, stamps, options, ledger and record keys a step
        // turned on sets; a skip or override marker cannot sit beside its own step's switch (104-7f1, trial 3)
        $onk = count(array_filter($all, function ($k) { return !preg_match('/^sf_(skip|override)_/', (string)$k); }));
        echo 'all=', count($all), ' onkeys=', $onk, ' settings=', count($set), ' vswitch=', $vs, ' vsettings=', $vt, ' oswitch=', $os, ' osettings=', $ot,
             ' steps=', count(SalesFollowupLadder::STEPS), "\n";
        exit(0);
    }
    if ($mode === 'ladder') {
        require_once $root . '/lib/UtcClock.php';
        require_once $root . '/lib/SalesFollowupLadder.php';
        require_once $root . '/lib/WaSendBudget.php';
        $st = SalesFollowupLadder::steps();
        $opt = []; $lab = [];
        foreach ($st as $x) { $r = SalesFollowupLadder::row($x); if (!empty($r['optional'])) $opt[] = $x; $lab[] = $r['n'] . ':' . $x; }
        $w = ['steps=' . implode(',', $st), 'optional=' . implode(',', $opt), 'labels=' . implode('|', $lab)];
        foreach (['tracking', 'voice-measure', 'offers-test'] as $x) {
            $w[] = 'skip_' . $x . '=' . (implode(',', SalesFollowupLadder::skipCascade($x)) ?: '-');
            $w[] = 'off_' . $x . '=' . (implode(',', SalesFollowupLadder::offCascade($x)) ?: '-');
        }
        $w[] = 'sequence=' . implode(',', SalesFollowupLadder::SEQUENCE);
        $w[] = 'ack_gap=' . (int)VoiceAckLimits::DEFAULTS['gap_s'] . ':' . (int)VoiceAckLimits::RANGES['gap_s'][0] . '-' . (int)VoiceAckLimits::RANGES['gap_s'][1];
        $w[] = 'window=' . (int)WaSendBudget::DEFAULTS['wa_send_hour_from'] . '-' . (int)WaSendBudget::DEFAULTS['wa_send_hour_to'];
        $cr = SalesFollowupLadder::row('cold');
        $ck = array_merge(array_map('strval', (array)($cr['record'] ?? [])), array_map('strval', array_keys((array)($cr['switch'] ?? []))));
        if (($cr['stamp'] ?? null) !== null) $ck[] = (string)$cr['stamp'];
        $w[] = 'on_cold=' . (implode(',', $ck) ?: '-');
        foreach (['voice-dry-run', 'voice-staff', 'voice-reply'] as $x) {
            $vr = SalesFollowupLadder::row($x);
            $vk = array_map('strval', array_keys((array)($vr['switch'] ?? [])));
            if (($vr['stamp'] ?? null) !== null) $vk[] = (string)$vr['stamp'];
            foreach ((array)($vr['options'] ?? []) as $od) $vk[] = (string)($od['key'] ?? '');
            $w[] = 'on_' . $x . '=' . (implode(',', $vk) ?: '-');
        }
        $hk = [];
        foreach (SalesFollowupLadder::SEQUENCE as $x) {
            $h = SalesFollowupLadder::hookOf($x);
            if ($h !== null && in_array($h[2], ['always', 'rollback'], true)) $hk[] = $x . ':' . $h[1];
        }
        $w[] = 'hooks=' . (implode(',', $hk) ?: '-');
        $allk = SalesFollowupLadder::allKeys();
        foreach (['V' => 'vkeys', 'O' => 'okeys'] as $p => $nm) {
            $pk = [];
            foreach (SalesFollowupLadder::STEPS as $s => $row) {
                if ((string)($row['part'] ?? '') !== $p) continue;
                foreach (SalesFollowupLadder::rowKeys($s) as $k) $pk[] = $k;
                $pk[] = 'sf_skip_' . $s; $pk[] = 'sf_override_' . $s;
            }
            $w[] = $nm . '=' . (implode(',', array_values(array_intersect(array_values(array_unique($pk)), $allk))) ?: '-');
        }
        $w[] = 'lastkeys=' . (implode(',', SalesFollowupLadder::lastKeys()) ?: '-');
        echo implode(' ', $w), "\n";
        exit(0);
    }
    foreach (['bootstrap_data', 'timezone', 'UtcClock', 'PluginConfig', 'TenantProfile', 'StaffDirectory', 'SalesFollowupLadder'] as $l) require_once $root . '/lib/' . $l . '.php';
    if (!is_dir($dir) || !is_file($dir . '/plugin.sqlite3')) { fwrite(STDERR, "refusing: no scratch book\n"); exit(2); }
    if ($mode === 'conf') {
        $c = PluginConfig::read($root, $dir);
        $w = function ($k) use ($c, $setNow, $tok) { if (!$setNow($c, $k)) return 'unset'; $v = $c[$k]; return is_bool($v) ? ($v ? 'on' : 'off') : (is_scalar($v) ? $tok($v) : 'set'); };
        $n1 = [];
        foreach (['followup_enabled', 'followup_auto_send', 'followup_daily_cap', 'followup_not_before', 'multi_number_channels_enabled',
                  'wa_followups_on_owned_numbers', 'wa_force_accounts', 'kyc_messages_like_crm', 'kyc_quote_validity_days',
                  'reminder_hour', 'reminder_until_hour'] as $k) $n1[] = $k . '=' . $w($k);
        $capRaw = $c['wa_send_daily_cap'] ?? null;
        require_once $root . '/lib/WaSendBudget.php';
        $cap = (is_int($capRaw) || (is_string($capRaw) && preg_match('/^\s*\d{1,6}\s*$/', $capRaw))) ? (int)trim((string)$capRaw) : (int)WaSendBudget::DEFAULTS['wa_send_daily_cap'];
        $vset = 0; foreach (SalesFollowupLadder::SETTINGS as $k => $d) if ((string)($d['part'] ?? '') === 'V' && $setNow($c, $k)) $vset++;
        $pv = strtolower(trim((string)($c['ai_provider'] ?? ''))); $pv = $pv === '' ? 'unset' : (in_array($pv, ['claude', 'openai'], true) ? $pv : 'other');
        $remh = '-';
        if (is_file($root . '/lib/ReminderDay.php')) { require_once $root . '/lib/ReminderDay.php'; [$rf, $ru] = ReminderDay::hours($c); $remh = (int)$rf . '-' . (int)$ru; }
        $win = '-';
        if (method_exists('WaSendBudget', 'window')) { [$wf, $wt] = WaSendBudget::window($c); $win = (int)$wf . '-' . (int)$wt; }
        echo 'n1=', implode(',', $n1), ' tz=', $tok(dn_tz($c)),
             ' wasender=', ($setNow($c, 'wasender_api_url') || $setNow($c, 'wasender_enabled')) ? 'configured' : 'not-configured',
             ' cap=', $cap, ' key=', trim((string)($c['openai_api_key'] ?? '')) !== '' ? 'set' : 'not-set', ' provider=', $pv,
             ' vset=', $vset, ' tenant=', $tok(TenantProfile::current($c, $dir)->id()), ' remh=', $remh, ' win=', $win, "\n";
        exit(0);
    }
    if ($mode === 'staff') {
        $p = new PDO('sqlite:' . $dir . '/plugin.sqlite3', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY]);
        $p->exec('PRAGMA query_only = ON');
        $t = TenantProfile::load('uganda');
        $act = 0; $nop = 0; $adm = 0;
        foreach ($p->query('SELECT data FROM retailers ORDER BY id ASC')->fetchAll(PDO::FETCH_COLUMN) as $j) {
            $r = json_decode((string)$j, true);
            if (!is_array($r) || !StaffDirectory::isActive($r)) continue;
            $act++;
            $ph = StaffDirectory::phoneOf($r, $t);
            if ($ph === null) $nop++;
            elseif (!empty($r['is_admin'])) $adm++;
        }
        echo 'staff_active=', $act, ' staff_no_phone=', $nop, ' admins_with_phone=', $adm, "\n";
        exit(0);
    }
    fwrite(STDERR, "refusing: a mode this helper does not know\n"); exit(2);
} catch (Throwable $e) { fwrite(STDERR, 'refused: ' . get_class($e) . "\n"); exit(3); }
