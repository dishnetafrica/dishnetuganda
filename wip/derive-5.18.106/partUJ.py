# Part UJ — the enquiry follow-ups, read before and after the copy (root docs/75 §L.4, §L.5; DL2, DL3, DL7). A new QF_PHP
# mode, uj, on the copy of the book QF already makes (VACUUM INTO, opened read-only), through the PIN's code (P3B_PIN),
# printing ONE line of counts, UTC times and generic channel labels (sales, support, account, retailer, their -N, else
# other) — never a phone, a message, an instance name or an event's detail — and calling neither expireStale() nor
# close(): it runs their SELECTs, expireStale's copied from the pin and asserted here against bf29cbd, line for line.
#   U1  F1's positive control — each channel's last outbound delivery (wa_messages out with WhatsApp's id, placed by
#       created_at as refusedWhileDelivering places it) and its count in 24 h, a channel with none in 30 days said so —
#       and the sender's ten places (approvedDrafts(10), the registry's held numbers left out as the sender leaves them),
#       in its order, classed as it would meet them: held, F5 expires, closes (gone, colleague, owned number, opted out,
#       taken over, replied), F2 expires, F1 closes or waits, with the ledger's mode (claim leaves F1 out);
#   U2  §A's counts; U3 the forecasts (expireStale's SELECT without its limit and with LIMIT 200; F5's follow-ups holding
#       two or more approved drafts); U4 the settings as the jobs read them — $config as followup_close reads it,
#       PluginConfig::load over the copy's settings and vault — and StaffJobsGate::applies.
#   conf's keep list gains followup_max_age_hours, followup_run_limit and followup_scan_limit (none a secret, none a vault
#   key, none kept before — asserted), or every reading of a copy would see their defaults. The uj mode also names DIR as
#   the data folder (DN_DATA_DIR; review UJ-1), so dn_tz() — the zone FollowUpPolicy::withinSendingWindow reads — comes
#   from the copy's own kyc_config.json, as the live sender's comes from its own; tz= says which zone it read.
#   Stage A (qf_stage, on QF-G1's copy, after QF-N1–QF-N7): U1–U4 as notes, before DEPLOY is asked; the deploy run keeps
#   the line as UJ_A in its state file, in the backup block beside 5.18.99's QG3_A (--check stops before that block and
#   writes nothing). Then FE-G1 (DL11, 18 Nov), right after uj_stage and so after CS-G1: NO-GO, nothing changed, when U4
#   read followup_enabled off as the jobs read it — UJ-ON reads stage A's value for the life of the deploy, so F3 could
#   never be judged — with the owner's own command to turn it on (set_config, --value 1, OWNER_AUTOSEND_CMD's form)
#   printed by echo, alone, never run: display text for the driver's K5 and partZ's Z2, as partH prints §F's set_config
#   commands. No refusal when U4 could not read it (DL2). U5 is NOT read here: the slot U5 is left in qf_stage right after
#   FE-G1's call, for partR (§L.7's one reader, on the live kyc_config.json and store row); partZ closes it.
#   After the copy (stage R, the deploy run and --after-only, after ME-CA1): UJ-CA — U1–U3 again on a fresh copy, beside
#   UJ_A, and the closes, expiries and sends since the copy — notes; --after-only: UJ-ON, judged exactly as §L.5 defines
#   it (uj_on): its gate measured false now, F3 has nothing to do; false only at stage A, F3 may run now but cannot be
#   judged for this deploy (review UJ-2). uj_gate_a and uj_judgeable answer that from UJ_A (for partH's check it). NOT YET
#   is a summary state of its own: the NOT_YET counter (notyet), the third verdict branch — FAIL first — with the
#   --after-only command alone on its line with its log, and exit status 3 (0 PASSED, 1 FAILED); UJ_NY_SAY carries its
#   counts (review UJ-3).
# Not here: ME-CA1 (partME), U5 and every rollback text (partR), the header, the §F steps a PASSED --after-only prints and
# a NOT YET withholds, the check-it sentence and the summary's other blocks (partH), the carried texts (partT).
import re, textwrap
from d106lib import git, sha_at, slot_line


def cw(text, lead="# ", cont=None):
    """A comment paragraph, wrapped to 120 characters, every line starting with lead (cont after the first)."""
    out = textwrap.wrap(text, width=120, initial_indent=lead, subsequent_indent=cont if cont is not None else lead,
                        break_long_words=False, break_on_hyphens=False)
    return "\n".join(out) + "\n"


def rule(title):
    head = "# ── " + title + " "
    return head + "─" * (120 - len(head))


KEEP3 = ["followup_max_age_hours", "followup_run_limit", "followup_scan_limit"]
UJ_LIBS = ["bootstrap_data", "timezone", "UtcClock", "PluginConfig", "TenantProfile", "StaffJobsGate", "StaffDirectory",
           "WaSendBudget", "ContactOptOut", "FollowUpPolicy", "FollowUpService", "ChannelRegistry", "OwnedNumberHold",
           "ColleagueNumbers", "AutomationPolicy", "EvolutionApiService"]
# the texts UJ counts by, each exactly as the pin's code writes it (asserted in apply): close() logs "<reason> — <detail>"
F3_DETAIL = "expired — the customer last wrote at "
F2_DETAIL = "expired — the customer last wrote more than "
F1_DETAIL = "send_failed — Evolution refused it three times (HTTP 400)"
F5_TAKEN = "draft expired: a message of this attempt was sent, or may have been"
F5_SIBS = "other approved drafts expired: "
REFUSAL = "detail LIKE '% [HTTP 400 on POST /message/sendText/%' AND detail NOT LIKE 'Got an HTML page%'"
DELIVERY = ["WHERE m.direction = 'out' AND m.sent_at > datetime(?, '-1 day') AND m.created_at > ?",
            "AND m.wa_message_id IS NOT NULL AND m.wa_message_id <> ''"]
SLOT_U5 = slot_line("U5", "partR: U5 (root docs/75 §L.4), the one reader of §L.7 on the live files, before DEPLOY is asked")
UJ_RULE = rule("5.18.106: the enquiry follow-ups, read before and after the copy (UJ; root docs/75 §L.4, §L.5)")
# FE-G1 (DL11, 18 Nov): the owner's own command to turn followup_enabled on, in OWNER_AUTOSEND_CMD's form — printed by
# echo, alone on its line, never run: display text, which the driver's K5 and partZ's Z2 pass over, as partH's §F lines
FE_CMD = (r'''    echo "      docker exec -u $DB_OWNER -e DN_DATA_DIR=$PDD_IN $CONTAINER php $IN_CONTAINER/tools/set_config.php'''
          r''' --key followup_enabled --value 1 2>&1 | sed '/^  AI SETTINGS\$/,\$d' $(LOGW followup-enabled-1)"'''
          r'''   # the owner's own command: printed, never run (FE-G1)''')
FE_STOP = ('  stop "NO-GO (FE-G1): followup_enabled reads off — the enquiry follow-ups are paused (the 18 Nov interim). UJ-ON '
           'reads this value from stage A for the life of this deploy, so F3 could never be judged and the claim steps (6, 11, '
           '12) would follow unproven (root docs/75 §L.5, DL3). Turn step 6 off first if it is on (CS-G1), then turn '
           'followup_enabled on, each its own command, alone, then run this again. Nothing was changed."')
FE_CALL = ("  uj_fe_g1   # FE-G1 — 5.18.106 (DL11): U4 read followup_enabled off — NO-GO, nothing changed; not read — no "
           "refusal (DL2)\n")
# UJ-1 (review): the uj mode names DIR as the data folder too, so dn_tz() reads the copy's own settings (QF_PHP's top)
DD = ("// 5.18.106 (root docs/75 §L.4; review UJ-1): uj names DIR as the data folder too, so dn_tz() — the sending\n"
      "// window's zone — reads the copy's own kyc_config.json, as the live sender reads its own; never a live folder.\n"
      "if ($mode === 'uj') putenv('DN_DATA_DIR=' . rtrim((string)($a[1] ?? ''), '/'));\n")

# ── QF_PHP's uj mode: ⟦LIBS⟧, ⟦REFUSAL⟧, ⟦DELIVERY0⟧ … and ⟦XS⟧ (expireStale's SELECT, from Git) are put in by apply() ──
UJ_PHP = r'''    case 'uj':     // uj ROOT DIR [COPYUNIX [AUNIX]] — 5.18.106 (root docs/75 §L.4, §L.5): the enquiry follow-ups on
                   // DIR's copy, opened read-only, through ROOT's code (the pin's, P3B_PIN's copy). U1 F1's positive
                   // control — each channel's last outbound delivery, placed by created_at as refusedWhileDelivering
                   // places it, and its count in 24 h — and the sender's ten places, in its order, as it would meet
                   // them; U2 §A's counts; U3 the forecasts, by expireStale's SELECT, copied from the pin (derive106.py
                   // asserts it against bf29cbd) and never run through expireStale() or close(); U4 the settings as the
                   // jobs read them — $config as followup_close reads it, PluginConfig::load over the copy's settings
                   // and vault (load()'s refresh writes only this reading's own vault copy, in DIR). With COPYUNIX
                   // (UJ-CA, UJ-ON): the closes, expiries and sends since the copy, followup_close's entry in the
                   // copy's master_schedule.json, and expireStale's SELECT at its last start T, at the pin's own
                   // FollowUpService::staleBefore($config, T); with AUNIX, the replied closes since stage A. One line:
                   // counts, UTC times and generic channel labels — never a phone, a message, an instance name or an
                   // event's detail. A part that cannot be read says unread in its own field.
        [$root, $dir] = $a;
        $cu = ctype_digit((string)($a[2] ?? '')) ? (int)$a[2] : null;
        $au = ctype_digit((string)($a[3] ?? '')) ? (int)$a[3] : null;
        @ini_set('error_log', '/dev/null');   // a plugin class's own error_log() line never reaches the live PHP log
        foreach (⟦LIBS⟧ as $l) require_once $root . '/lib/' . $l . '.php';
        $c = PluginConfig::load($root, $dir);   // as cron/followup_close.php reads it
        $p = $ro($dir . '/plugin.sqlite3');
        $miss = [];
        foreach (['followups', 'followup_drafts', 'followup_sends', 'followup_events', 'wa_conversations', 'wa_messages'] as $x) if (!$has($p, $x)) $miss[] = $x;
        if ($miss !== []) { echo 'uj=no-table:', implode(',', $miss); break; }
        $svc = new FollowUpService($p);
        $E = $svc->hasKind() ? " AND COALESCE(f.kind,'enquiry')='enquiry'" : '';   // enquiry rows, as approvedDrafts() names them
        $nowTs = time(); $now = gmdate('Y-m-d H:i:s', $nowTs);
        $ago = function (int $s) use ($nowTs): string { return gmdate('Y-m-d H:i:s', $nowTs - $s); };
        $iso = function ($t): string {
            $t = (string)$t;
            return preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $t) ? str_replace(' ', 'T', $t) . 'Z' : ($t === '' ? 'never' : 'unreadable');
        };
        $lab = function ($ch): string { $ch = (string)$ch; return preg_match('/^(sales|support|account|retailer)(-[0-9]{1,4})?$/', $ch) ? $ch : 'other'; };
        $cnt = function (string $sql, array $x = []) use ($p): int { $s = $p->prepare($sql); $s->execute($x); return (int)$s->fetchColumn(); };
        $ug = StaffJobsGate::applies($c, $dir);
        $stale = FollowUpService::staleBefore($c, $nowTs);
        $ledger = !WaSendBudget::on($c, $dir) ? 'off' : (WaSendBudget::enquiryClaims($c) ? 'claim' : 'record');
        // the sender's window, in the zone dn_tz() reads from DIR's kyc_config.json (DN_DATA_DIR, set for uj at
        // QF_PHP's top), as the live sender's own process reads its data folder's; tz= says which zone it read
        $win = FollowUpPolicy::withinSendingWindow($now);
        $f = ['uj=read', 'at=' . $nowTs, 'now=' . $iso($now), 'ug=' . ($ug ? '1' : '0'), 'en=' . (!empty($c['followup_enabled']) ? 'on' : 'off'),
              'hours=' . ($stale === null ? 'misread' : FollowUpService::staleHours($c)), 'cutoff=' . ($stale === null ? '-' : $iso($stale)),
              'ledger=' . $ledger, 'window=' . (!empty($win['ok']) ? 'in' : 'out'), 'tz=' . preg_replace('~[^A-Za-z0-9_/+-]~', '?', dn_tz())];
        // U4 — followup_auto_send as FollowUpPolicy::mayAutoSend reads it, and where it is present on this copy (k, s,
        // p, d); the numbers as cron/followup_run.php and cron/followup_scan.php cast them
        $ap = [];
        foreach (['k' => $readJson($dir . '/kyc_config.json'), 's' => $storeRow($p), 'p' => $readJson($root . '/data/config.json'), 'd' => $readJson($dir . '/config.json')] as $k => $d)
            if (is_array($d) && array_key_exists('followup_auto_send', $d)) $ap[] = $k;
        $f[] = 'auto=' . (filter_var($c['followup_auto_send'] ?? false, FILTER_VALIDATE_BOOLEAN) ? 'on' : 'off');
        $f[] = 'auto_places=' . ($ap === [] ? '-' : implode(',', $ap));
        $f[] = 'cap=' . (int)($c['followup_daily_cap'] ?? 30); $f[] = 'run=' . (int)($c['followup_run_limit'] ?? 5); $f[] = 'scan=' . (int)($c['followup_scan_limit'] ?? 25);
        // U1 — each channel's last outbound delivery and its count in 24 h (F1's positive control, placed as F1 places
        // it); the channels the sender may meet — the open enquiry follow-ups' and the registry's — each said
        try {
            $outs = [];
            $st = $p->prepare("SELECT c.channel, MAX(m.created_at), SUM(CASE WHEN m.created_at > ? THEN 1 ELSE 0 END)
                                 FROM wa_messages m JOIN wa_conversations c ON c.id = m.conversation_id
                                ⟦DELIVERY0⟧
                                  ⟦DELIVERY1⟧
                                GROUP BY c.channel");
            $st->execute([$ago(86400), $ago(30 * 86400), $ago(30 * 86400)]);
            foreach ($st->fetchAll(PDO::FETCH_NUM) as $r) {
                $l = $lab($r[0]); $o = $outs[$l] ?? ['', 0];
                $outs[$l] = [max($o[0], (string)$r[1]), $o[1] + (int)$r[2]];
            }
            $chs = $p->query("SELECT DISTINCT f.channel FROM followups f WHERE f.closed_at IS NULL" . $E)->fetchAll(PDO::FETCH_COLUMN);
            if ($has($p, 'wa_channels')) $chs = array_merge($chs, $p->query('SELECT channel_id FROM wa_channels')->fetchAll(PDO::FETCH_COLUMN));
            foreach ($chs as $ch) { $l = $lab($ch); if (!isset($outs[$l])) $outs[$l] = ['', 0]; }
            ksort($outs); $u1 = [];
            foreach ($outs as $l => $v) $u1[] = $l . '@' . ($v[0] === '' ? 'none' : $iso($v[0])) . '@' . (int)$v[1];
            $f[] = 'out=' . ($u1 === [] ? '-' : implode(';', $u1));
        } catch (Throwable $x) { $f[] = 'out=unread:' . get_class($x); }
        // U1 — the sender's ten places (cron/followup_send.php's own list and order: approvedDrafts(10), the registry's
        // held numbers left out), each classed by the sender's checks in its order, through the pin's code: F5's two
        // (Uganda), the empty draft, the conversation gone, a colleague, an owned number, an opt-out, taken over,
        // replied, F2 (Uganda), F1 (Uganda, outside claim mode). The window, the fence, the registry's pause, the
        // policy and a claim are not read here: what passes every check read "waits"
        try {
            $stub = new class($p, $flat, $docRows) {   // the plugin store's load(), read-only on this copy
                private $p; private $fl; private $dr;
                public function __construct($p, $fl, $dr) { $this->p = $p; $this->fl = $fl; $this->dr = $dr; }
                public function load(string $name) { $x = ($this->fl)($this->p, $name); if ($x !== null) return $x; $y = ($this->dr)($this->p, $name); return $y === null ? [] : $y; }
            };
            $held = ['', []]; $hs = 'none';
            try {
                $evo = EvolutionApiService::forStore($c, $p, $dir);
                $evo->useStaffStore($stub);
                $held = $evo->automationPolicy()->sqlHeld('f.channel');
                $hs = $held[0] === '' ? 'none' : (strpos($held[0], ' NOT IN ') !== false ? 'out:' : 'only:') . count($held[1]);
            } catch (Throwable $x) { $held = ['', []]; $hs = 'unread'; }
            $colg = ColleagueNumbers::forInstall($c, $dir, $stub);
            $own = OwnedNumberHold::forInstall($c, $dir, $p);
            $oo = new ContactOptOut($p);
            $failQ = $p->prepare("SELECT COUNT(*), COALESCE(SUM(CASE WHEN ⟦REFUSAL⟧ THEN 1 ELSE 0 END), 0)
                                    FROM followup_events WHERE followup_id = ? AND event = 'failed' AND created_at >= ?");
            $convQ = $p->prepare('SELECT * FROM wa_conversations WHERE id = ?');
            $cls = ['held' => 0, 'f5' => 0, 'closes' => 0, 'f2' => 0, 'f1' => 0, 'waits' => 0]; $seen = []; $pa = []; $i = 0;
            foreach ($svc->approvedDrafts(10, $held[0], $held[1]) as $d) {
                $i++; $fu = (int)$d['followup_id']; $ch = (string)$d['channel']; $ph = (string)$d['phone']; $dec = (string)($d['decided_at'] ?? '');
                $failQ->execute([$fu, $dec]); $fr = $failQ->fetch(PDO::FETCH_NUM) ?: [0, 0]; $failQ->closeCursor();
                $k = null;
                if ($ug) {   // F5: the follow-up's oldest draft only, this pass; never one whose attempt is taken
                    if (isset($seen[$fu])) $k = ['held', 'held'];
                    else { $seen[$fu] = true; if ($svc->attemptTaken($fu, (int)$d['id'], (int)($d['attempt'] ?? 0))) $k = ['f5', 'f5-expires']; }
                }
                $body = trim((string)($d['edited_body'] ?? '')) !== '' ? (string)$d['edited_body'] : (string)$d['body'];
                if ($k === null && trim($body) === '') $k = ['waits', 'waits'];   // recordFailure: it stays approved
                if ($k === null) {
                    $convQ->execute([(int)$d['conversation_id']]); $cv = $convQ->fetch(PDO::FETCH_ASSOC) ?: null; $convQ->closeCursor();
                    $lc = (string)($cv['last_customer_at'] ?? '');
                    if ($cv === null) $k = ['closes', 'closes-gone'];
                    elseif ($colg !== null && ($colg->isColleague($ph) || $colg->isColleagueConversation($cv))) $k = ['closes', 'closes-colleague'];
                    elseif ($own->holds($ch)) $k = ['closes', 'closes-owned'];
                    elseif (!empty($oo->blocks($ph, $ch, ContactOptOut::CLASS_PROACTIVE)['blocked'])) $k = ['closes', 'closes-optout'];
                    elseif ((string)($cv['state'] ?? '') === 'human_active') $k = ['closes', 'closes-takenover'];
                    elseif ($lc > $dec) $k = ['closes', 'closes-replied'];
                    elseif ($ug && $stale !== null && $lc < $stale) $k = ['f2', 'f2-expires'];
                    elseif ($ug && $ledger !== 'claim' && $svc->refusedWhileDelivering($fu, $ch, $dec, 3) >= 3) $k = ['f1', 'f1-closes'];
                    else $k = ['waits', 'waits'];
                }
                $cls[$k[0]]++;
                $pa[] = $i . '@' . $k[1] . '@' . $lab($ch) . '@' . (int)$fr[0] . '@' . (int)$fr[1];
            }
            $f[] = 'held=' . $hs; $f[] = 'places=' . $i; $f[] = 'place=' . ($pa === [] ? '-' : implode(';', $pa));
            $cc = []; foreach ($cls as $k => $v) $cc[] = $k . ':' . $v;
            $f[] = 'classes=' . implode(',', $cc);
        } catch (Throwable $x) { $f[] = 'places=unread:' . get_class($x); }
        // U2 — §A's counts: the last enquiry send and those in 7 days; the approved drafts on open enquiry follow-ups
        // and the follow-ups holding them, split by the conversation's customer against the age limit as F2 reads it
        // (gone: no conversation); failed sends in 7 days and of them F1's kind; the open enquiry follow-ups by the
        // row's customer; due now under 5.18.105's due() (the pin's without its new parameters) and the pin's run
        try {
            $f[] = 'last_send=' . $iso($p->query("SELECT MAX(s.sent_at) FROM followup_sends s JOIN followups f ON f.id = s.followup_id WHERE 1 = 1" . $E)->fetchColumn());
            $f[] = 'sends7=' . $cnt("SELECT COUNT(*) FROM followup_sends s JOIN followups f ON f.id = s.followup_id WHERE s.sent_at >= ?" . $E, [$ago(7 * 86400)]);
            $r = $p->query("SELECT COUNT(*), COUNT(DISTINCT d.followup_id) FROM followup_drafts d JOIN followups f ON f.id = d.followup_id
                             WHERE d.status = 'approved' AND f.closed_at IS NULL" . $E)->fetch(PDO::FETCH_NUM);
            $f[] = 'approved=' . (int)$r[0] . '/' . (int)$r[1];
            $sp = ['stale' => '0/0', 'fresh' => '0/0', 'gone' => '0/0'];
            $st = $p->prepare("SELECT CASE WHEN c.id IS NULL THEN 'gone' WHEN ? <> '' AND COALESCE(c.last_customer_at, '') < ? THEN 'stale' ELSE 'fresh' END,
                                      COUNT(*), COUNT(DISTINCT d.followup_id)
                                 FROM followup_drafts d JOIN followups f ON f.id = d.followup_id LEFT JOIN wa_conversations c ON c.id = f.conversation_id
                                WHERE d.status = 'approved' AND f.closed_at IS NULL" . $E . " GROUP BY 1");
            $st->execute([(string)$stale, (string)$stale]);
            foreach ($st->fetchAll(PDO::FETCH_NUM) as $r) $sp[(string)$r[0]] = (int)$r[1] . '/' . (int)$r[2];
            foreach ($sp as $k => $v) $f[] = 'approved_' . $k . '=' . $v;
            $st = $p->prepare("SELECT COUNT(*), COALESCE(SUM(CASE WHEN ⟦REFUSAL_E⟧ THEN 1 ELSE 0 END), 0)
                                 FROM followup_events e JOIN followups f ON f.id = e.followup_id WHERE e.event = 'failed' AND e.created_at >= ?" . $E);
            $st->execute([$ago(7 * 86400)]); $r = $st->fetch(PDO::FETCH_NUM);
            $f[] = 'failed7=' . (int)$r[0]; $f[] = 'failed7_f1=' . (int)$r[1];
            $d3 = $ago(3 * 86400); $d7 = $ago(7 * 86400); $d14 = $ago(14 * 86400);
            $st = $p->prepare("SELECT COUNT(*), COALESCE(SUM(CASE WHEN f.last_customer_at >= ? THEN 1 ELSE 0 END), 0),
                                      COALESCE(SUM(CASE WHEN f.last_customer_at < ? AND f.last_customer_at >= ? THEN 1 ELSE 0 END), 0),
                                      COALESCE(SUM(CASE WHEN f.last_customer_at < ? AND f.last_customer_at >= ? THEN 1 ELSE 0 END), 0),
                                      COALESCE(SUM(CASE WHEN f.last_customer_at < ? THEN 1 ELSE 0 END), 0)
                                 FROM followups f WHERE f.closed_at IS NULL" . $E);
            $st->execute([$d3, $d3, $d7, $d7, $d14, $d14]); $r = $st->fetch(PDO::FETCH_NUM);
            $f[] = 'open=' . (int)$r[0]; $f[] = 'open_age=lt3d:' . (int)$r[1] . ',3-7d:' . (int)$r[2] . ',7-14d:' . (int)$r[3] . ',gt14d:' . (int)$r[4];
            $f[] = 'due105=' . count($svc->due($now, -1));
            $f[] = 'due106=' . count($svc->due($now, -1, $ug ? $stale : null, $ug));   // as cron/followup_run.php calls it
        } catch (Throwable $x) { $f[] = 'u2=unread:' . get_class($x); }
        // U3 — expireStale's SELECT, copied from the pin (lib/FollowUpService.php, FollowUpService::expireStale;
        // derive106.py asserts these lines against bf29cbd), run as a SELECT only: never expireStale(), never close()
⟦KIND⟧
        $xsSql =
⟦XS⟧;
        $xs = function (string $cut, int $lim) use ($p, $xsSql): array { $s = $p->prepare($xsSql); $s->execute([$cut, $cut, $lim]); return $s->fetchAll(PDO::FETCH_ASSOC) ?: []; };
        try {
            if ($stale === null) { $f[] = 'xs_all=-'; $f[] = 'xs_passes=-'; $f[] = 'xs_first_expire=-'; $f[] = 'xs_first_cancel=-'; }
            else {
                $na = count($xs($stale, -1)); $fe = 0; $fg = 0;
                foreach ($xs($stale, 200) as $r) { if ($r['cid'] === null) $fg++; else $fe++; }
                $f[] = 'xs_all=' . $na; $f[] = 'xs_passes=' . (int)ceil($na / 200); $f[] = 'xs_first_expire=' . $fe; $f[] = 'xs_first_cancel=' . $fg;
            }
            $r = $p->query("SELECT COUNT(*), COALESCE(SUM(n), 0) FROM (SELECT COUNT(*) AS n FROM followup_drafts d JOIN followups f ON f.id = d.followup_id
                             WHERE d.status = 'approved' AND f.closed_at IS NULL" . $E . " GROUP BY d.followup_id HAVING COUNT(*) >= 2)")->fetch(PDO::FETCH_NUM);
            $f[] = 'f5_multi=' . (int)$r[0] . '/' . (int)$r[1];
        } catch (Throwable $x) { $f[] = 'u3=unread:' . get_class($x); }
        // UJ-CA, UJ-ON — since the copy (COPYUNIX): F3's closes by their event (close(): "closed", "<reason> —
        // <detail>", the actor closer), the closer's other closes, followup_close's schedule entry — the positive
        // control: started after the copy (last_run, a UTC epoch, the start of the cycle that ran it) and ended
        // (duration_ms at least 0), T its last_run — and expireStale's SELECT at T; the replied closes since stage A
        // (AUNIX); F2's, F5's and F1's own events, the sibling expiries and the sends since the copy; when to run again
        if ($cu !== null) {
            try {
                $ci = gmdate('Y-m-d H:i:s', $cu);
                $sst = $sched($p)[0];
                $ms = $flat($p, 'master_schedule.json');
                $fcE = (is_array($ms) && is_array($ms['followup_close'] ?? null)) ? $ms['followup_close'] : null;
                $lr = ($fcE !== null && is_numeric($fcE['last_run'] ?? null)) ? (int)$fcE['last_run'] : null;
                $du = ($fcE !== null && is_numeric($fcE['duration_ms'] ?? null)) ? (int)$fcE['duration_ms'] : null;
                $ctl = ($sst === 'unreadable' || ($fcE !== null && $lr === null)) ? 'unread' : (($lr !== null && $lr > $cu && $du !== null && $du >= 0) ? 'held' : 'not');
                $f[] = 'since=' . $iso($ci); $f[] = 'sched=' . $sst; $f[] = 'control=' . $ctl;
                $f[] = 'fc_last=' . ($lr === null ? 'never' : $iso(gmdate('Y-m-d H:i:s', $lr)));
                $f[] = 'fc_state=' . ($lr === null ? 'none' : (($du !== null && $du >= 0) ? 'ended' : 'running'));
                $f[] = 'again=' . $iso(gmdate('Y-m-d H:i:s', max($cu + 1200, (($lr !== null && $lr > $cu) ? $lr : $cu) + 900)));
                $ev = function (string $where, array $x) use ($p, $E): int {
                    $s = $p->prepare("SELECT COUNT(*) FROM followup_events e JOIN followups f ON f.id = e.followup_id WHERE " . $where . $E);
                    $s->execute($x); return (int)$s->fetchColumn();
                };
                $f[] = 'on_expired=' . $ev("e.event = 'closed' AND e.actor = 'closer' AND f.close_reason = 'expired' AND instr(e.detail, ?) = 1 AND e.created_at >= ?", ['⟦F3⟧', $ci]);
                $bc = [];
                foreach (['cancelled', 'human_closed', 'opted_out', 'exhausted'] as $why) {
                    $bc[] = $why . ':' . $ev("e.event IN ('closed', 'opted_out') AND e.actor = 'closer' AND f.close_reason = ? AND e.created_at >= ?", [$why, $ci]);
                }
                $f[] = 'by_closer=' . implode(',', $bc);
                if ($ctl === 'held') {
                    $t = gmdate('Y-m-d H:i:s', $lr);
                    $tc = FollowUpService::staleBefore($c, $lr);   // the pin's own cutoff at T, $config as followup_close reads it
                    $f[] = 't=' . $iso($t);
                    $f[] = 'on_cancelled_t=' . $ev("e.event = 'closed' AND e.actor = 'closer' AND f.close_reason = 'cancelled' AND e.created_at >= ?", [$t]);
                    if ($tc === null) { $f[] = 'on_stale_t=-'; $f[] = 'on_stale_t_n=-'; }
                    else {
                        $s = $p->prepare('SELECT 1 FROM (' . $xsSql . ') x WHERE x.cid IS NOT NULL LIMIT 1'); $s->execute([$tc, $tc, -1]);
                        $f[] = 'on_stale_t=' . ($s->fetchColumn() !== false ? '1' : '0');
                        $s = $p->prepare('SELECT COUNT(*) FROM (' . $xsSql . ') x WHERE x.cid IS NOT NULL'); $s->execute([$tc, $tc, -1]);
                        $f[] = 'on_stale_t_n=' . (int)$s->fetchColumn();
                    }
                }
                $f[] = 'replied_a=' . ($au === null ? '-' : $cnt("SELECT COUNT(*) FROM followups f WHERE f.close_reason = 'replied' AND f.closed_at >= ?" . $E, [gmdate('Y-m-d H:i:s', $au)]));
                $f[] = 'f2=' . $ev("e.event = 'closed' AND e.actor = 'sender' AND f.close_reason = 'expired' AND instr(e.detail, ?) = 1 AND e.created_at >= ?", ['⟦F2⟧', $ci]);
                $f[] = 'f1=' . $ev("e.event = 'closed' AND e.actor = 'sender' AND f.close_reason = 'send_failed' AND instr(e.detail, ?) = 1 AND e.created_at >= ?", ['⟦F1⟧', $ci]);
                $f[] = 'f5_taken=' . $ev("e.event = 'skipped' AND e.actor = 'sender' AND e.detail = ? AND e.created_at >= ?", ['⟦F5T⟧', $ci]);
                $sib = '⟦F5S⟧';
                $s = $p->prepare("SELECT COUNT(*), COALESCE(SUM(CAST(substr(e.detail, ?) AS INTEGER)), 0) FROM followup_events e JOIN followups f ON f.id = e.followup_id
                                   WHERE e.event = 'skipped' AND e.actor = 'sender' AND instr(e.detail, ?) = 1 AND e.created_at >= ?" . $E);
                $s->execute([strlen($sib) + 1, $sib, $ci]); $r = $s->fetch(PDO::FETCH_NUM);
                $f[] = 'siblings=' . (int)$r[0] . '/' . (int)$r[1];
                $f[] = 'sends=' . $cnt("SELECT COUNT(*) FROM followup_sends s JOIN followups f ON f.id = s.followup_id WHERE s.sent_at >= ?" . $E, [$ci]);
            } catch (Throwable $x) { $f[] = 'on=unread:' . get_class($x); }
        }
        echo implode(' ', $f);
        break;

'''

# ── the shell: comments wrapped here, code as written ──
UJ_FUNCS = (
    cw("One reading, QF_PHP uj, on a QF copy of the book opened read-only, through the PIN's code (P3B_PIN): its "
       "FollowUpService, StaffJobsGate, WaSendBudget and the sender's own checks; the copy's settings cut to conf's keep list, "
       "which carries followup_max_age_hours, followup_run_limit and followup_scan_limit since 5.18.106. Counts, UTC times "
       "and generic channel labels (sales, support, account, retailer, their -N, else other) — never a phone, a message, an "
       "instance name or an event's detail; never expireStale() or close(), only their SELECTs, expireStale's copied from the "
       "pin (derive106.py asserts it against bf29cbd). The readings never refuse (DL2): FE-G1, on U4's reading of "
       "followup_enabled, is the one refusal here (DL11).")
    + cw("Stage A (a deploy and --check), on QF-G1's copy: U1–U4 as notes, before DEPLOY is asked — U2 and U3 being the "
         "only record of the state before the first passes and of what they were expected to close, since a close is "
         "permanent (070's fu_closed_is_history). The deploy run keeps the line as UJ_A in its state file, in the backup "
         "block; --check stops before that block and writes nothing. U5 — whether a rollback could write followup_auto_send "
         "— is the one reader's of §L.7, on the live files, never a copy's.", "#   ")
    + cw("After the copy (the deploy run and --after-only): UJ-CA — U1–U3 again on a fresh copy, beside stage A's, and the "
         "closes, expiries and sends since the copy — notes. --after-only: UJ-ON, judged (uj_on): PASS, FAIL or NOT YET.", "#   ")
    + 'UJ_A=""; UJ_NY_AT=""; UJ_NY_SAY=""; UJ_ON_GATE=""\n'
    + cw("uj_read SUB [COPYUNIX [AUNIX]] — one reading on SUB's copy through SUB's code root (QF_PHP uj): its line, or "
         "\"uj=unread(<why>)\" and status 1. Runs in $( … ): it reads nothing under the run's folder but SUB.")
    + r'''uj_read() {
  local o
  o="$(qf_php uj "$1/code" "$1" "${2:-}" "${3:-}")"
  case "$o" in
    "uj=read "*) printf '%s' "$o" ;;
    *) printf 'uj=unread(%s)' "$(printf '%s' "${o:-no answer}" | tr -c 'A-Za-z0-9_=:.,?-' '_' | cut -c1-80)"; return 1 ;;
  esac
}
uj_ok() { case "$1" in "uj=read at="[0-9]*" ug="?*" en="?*" hours="?*) return 0 ;; esac; return 1; }   # a whole reading
'''
    + cw("uj_gate_ok LINE — the gate's three fields read: followup_enabled on or off, StaffJobsGate 1 or 0, the age limit a "
         "number or \"misread\" (not a positive number: staleBefore() answers null)")
    + r'''uj_gate_ok() {
  case "$(kv "$1" en):$(kv "$1" ug)" in on:[01]|off:[01]) ;; *) return 1 ;; esac
  case "$(kv "$1" hours)" in misread|[0-9]*) return 0 ;; esac
  return 1
}
uj_was() { uj_ok "$2" && printf ' (stage A: %s)' "$(kv "$2" "$1")"; return 0; }   # uj_was KEY A — stage A's value beside it
'''
    + cw("uj_gate_a A — UJ-ON's gate at stage A, on A (UJ_A): each condition that did not hold, as UJ-ON names it (\" … at "
         "stage A;\"), or nothing when each held or A is not a whole reading (UJ-ON then judges on its own run's values "
         "alone). uj_judgeable — status 0 when stage A lets UJ-ON judge F3 for this deploy, by UJ_A: partH's check it says "
         "so, or why not by uj_gate_a \"$UJ_A\" (review L8-1).")
    + r'''uj_gate_a() {
  uj_ok "$1" && uj_gate_ok "$1" || return 0
  [ "$(kv "$1" en)" = "on" ] || printf ' followup_enabled was off at stage A;'
  [ "$(kv "$1" ug)" = "1" ] || printf ' StaffJobsGate::applies did not read Uganda at stage A;'
  [ "$(kv "$1" hours)" != "misread" ] || printf ' followup_max_age_hours was not a positive number at stage A;'
  return 0
}
uj_judgeable() { [ -z "$(uj_gate_a "$UJ_A")" ]; }   # uj_judgeable — stage A lets UJ-ON judge F3 for this deploy (UJ_A)
'''
    + cw("uj_out LINE — U1's deliveries as the log may carry them: each channel label, its last delivery (UTC) and its count "
         "in 24 h")
    + r'''uj_out() {
  case "$(kv "$1" out)" in
    unread:*) printf 'not read (%s)' "$(kv "$1" out)" ;;
    -|'') printf 'no channel to read' ;;
    *) kv "$1" out | tr ';' '\n' | awk -F'@' 'NF == 3 { s = s (s == "" ? "" : "; ") $1 ($2 == "none" ? " none in 30 days" : " last " $2 ", " $3 " in 24 h") } END { printf "%s", s }' ;;
  esac
}
'''
    + cw("uj_places LINE — the sender's ten places: each place, its class, its channel label, its failures since approval and "
         "of them the 400s to sendText (F1's kind); then the count per class")
    + r'''uj_places() {
  case "$(kv "$1" places)" in
    unread:*) printf 'not read (%s)' "$(kv "$1" places)"; return 0 ;;
    0) printf 'none — no approved enquiry draft waits'; return 0 ;;
  esac
  kv "$1" place | tr ';' '\n' | awk -F'@' 'NF == 5 { s = s (s == "" ? "" : "; ") $1 " " $2 " (" $3 ", " $4 " failed since approval, " $5 " of them a 400 to sendText)" } END { printf "%s", s }'
  printf ' — %s' "$(kv "$1" classes | awk -F'[:,]' 'NF == 12 { printf "held (a second draft this pass) %s, F5 expires (attempt taken) %s, closes (gone, colleague, owned number, opted out, taken over or replied) %s, F2 expires (stale) %s, F1 closes %s, waits %s", $2, $4, $6, $8, $10, $12 }')"
}
'''
    + cw("uj_notes CODE LINE [A] — U1–U3 as notes (CODE \"U\" at stage A, \"UJ-CA\" after the copy), each count beside stage "
         "A's (A) where that is on record. Counts, UTC times and channel labels only.")
    + r'''uj_notes() {
  local c="$1" o="$2" a="${3:-}" p=""
  [ "$c" = "U" ] || p="$c "
  note "${p}U1 F1's positive control — each channel's last outbound delivery (wa_messages out with WhatsApp's id, placed by created_at as F1 places it) and its count in the last 24 h: $(uj_out "$o")$(uj_ok "$a" && printf ' — at stage A: %s' "$(uj_out "$a")")"
  note "${p}U1 the sender's ten places$( [ -z "$p" ] || echo " now"), in its own order, as it would meet them — the ledger $(kv "$o" ledger) (claim mode leaves F1 out), the registry's held numbers $(kv "$o" held), the sending window $( [ "$(kv "$o" window)" = "in" ] && echo open || echo closed) now (in $(kv "$o" tz), the zone the sender reads): $(uj_places "$o")"
  if [ -n "$(kv "$o" u2)" ]; then note "${p}U2 could not be read on the copy ($(kv "$o" u2))"
  else note "${p}U2 enquiry sends: the last $(kv "$o" last_send), $(kv "$o" sends7) in 7 days$(uj_was sends7 "$a"); approved drafts on open enquiry follow-ups $(kv "$o" approved) (drafts/follow-ups)$(uj_was approved "$a") — stale, the conversation's customer quiet past the limit (what F2 expires) $(kv "$o" approved_stale), fresh $(kv "$o" approved_fresh), gone $(kv "$o" approved_gone); failed sends in 7 days $(kv "$o" failed7), of them a 400 to sendText (F1's kind) $(kv "$o" failed7_f1); open enquiry follow-ups $(kv "$o" open)$(uj_was open "$a"), by the customer's last message $(kv "$o" open_age | tr ',' ' '); due now $(kv "$o" due105) under 5.18.105's due(), $(kv "$o" due106) under the pin's"; fi
  if [ -n "$(kv "$o" u3)" ]; then note "${p}U3 could not be read on the copy ($(kv "$o" u3))"
  else note "${p}U3 forecasts — expireStale's SELECT, the pin's, without its limit: $(kv "$o" xs_all) stale open enquiry follow-up(s)$(uj_was xs_all "$a"), $(kv "$o" xs_passes) close pass(es) at 200 a pass; its first pass (LIMIT 200): $(kv "$o" xs_first_expire) close expired, $(kv "$o" xs_first_cancel) cancelled (a gone conversation); F5: $(kv "$o" f5_multi) (follow-ups/drafts) hold two or more approved drafts$(uj_was f5_multi "$a")"; fi
}
'''
    + cw("uj_u4 LINE — U4, stage A: the settings as the jobs read them; StaffJobsGate::applies false (F1–F5 inert) in capitals; "
         "and, where stage A's reading keeps UJ-ON from judging F3 for the life of the deploy (StaffJobsGate::applies false, "
         "or the age limit not a positive number; followup_enabled off is FE-G1's, which stops), that in capitals before "
         "DEPLOY is asked — a note, never a refusal (DL2; review 2: L8-1 point 1)")
    + r'''uj_u4() {
  local o="$1" h
  h="$(kv "$o" hours)"
  note "U4 the settings as the jobs read them (PluginConfig::load over the copy's settings and vault, as followup_close reads them): followup_enabled $(kv "$o" en), followup_auto_send $(kv "$o" auto) (present in: $(kv "$o" auto_places) — k <data>/kyc_config.json, s its store row, p <plugin>/data/config.json, d <data>/config.json), followup_daily_cap $(kv "$o" cap), followup_run_limit $(kv "$o" run), followup_scan_limit $(kv "$o" scan), followup_max_age_hours $( [ "$h" = "misread" ] && echo "not a positive number — F2 and F3 inactive: nothing is expired on a misread" || echo "$h — F2 and F3 active, the cutoff now $(kv "$o" cutoff)"), the ledger $(kv "$o" ledger), StaffJobsGate::applies $( [ "$(kv "$o" ug)" = "1" ] && echo "Uganda" || echo "false")"
  [ "$(kv "$o" ug)" = "1" ] || note "U4 STAFFJOBSGATE::APPLIES IS FALSE ON THIS BOOK'S SETTINGS — F1 TO F5 ARE INERT HERE: $EXPECTED_VERSION CHANGES NONE OF THIS SERVER'S FOLLOW-UPS"
  [ "$(kv "$o" en)" = "off" ] || [ -z "$(uj_gate_a "$o")" ] || note "U4 UJ-ON WILL NOT JUDGE F3 FOR THIS DEPLOY:$(uj_gate_a "$o" | sed 's/;$//'). STAGE A'S READING IS KEPT IN THE STATE FILE FOR THE LIFE OF THE DEPLOY, SO F3 WOULD STAY UNPROVEN AND THE STEPS THAT WAIT FOR IT (6, 11, 12) WOULD FOLLOW WITHOUT THAT PROOF; AFTERWARDS A JUDGED UJ-ON NEEDS A ROLLBACK AND THEN A --redeploy. TO HAVE IT JUDGED, DO NOT DEPLOY ON THIS READING: PUT IT RIGHT$( [ -n "$CHECK_ONLY" ] || echo ", ANSWER ANYTHING BUT DEPLOY BELOW"), THEN RUN THIS AGAIN"
  [ "$(kv "$o" en)" = "on" ] || note "U4 followup_enabled is off — the four follow-up jobs do not run, so F1 to F5 act on nothing while it stays off"
}
'''
    + cw("uj_stage SUB — stage A (a deploy and --check), on QF-G1's copy (SUB) through the pin's code: U1–U4, notes; UJ_A "
         "the line, which the deploy run keeps in its state file (the backup block; --check stops before it, writing nothing)")
    + r'''uj_stage() {
  local o
  if o="$(uj_read "$1")"; then UJ_A="$o"; uj_notes U "$o"; uj_u4 "$o"
  else UJ_A=""; note "U1–U4 could not be read on the copy (${o#uj=}) — NOT ESTABLISHED; the deploy run keeps no stage-A reading for UJ-CA and UJ-ON (UJ-ON then judges on its own run's values, and says so)"; fi
}
'''
    + cw("uj_fe_g1 — FE-G1 (DL11, 18 Nov; root docs/75 §L.5, DL3), stage A — a deploy, --redeploy and --check — called in "
         "qf_stage right after uj_stage, so after CS-G1: when U4 read followup_enabled off as the jobs read it (UJ_A a whole "
         "reading, en=off; followup_close returns at once while it is empty), NO-GO before anything is changed — UJ-ON reads "
         "stage A's value from the state file for the life of the deploy, so F3 could never be judged, and the claim steps "
         "(6, 11, 12) would follow unproven. When U4 could not read it (UJ_A empty, or not a whole reading), no refusal: "
         "U4's note stands (DL2). The owner's own command to turn it on — set_config as the book's owner on the live data "
         "folder, --value 1, its output cut where the settings listing begins, its own log: the form of the owner's command "
         "for followup_auto_send (root docs/75 §L.7) — is printed by echo, alone on its line, never run (display text for "
         "the driver's K5 and partZ's Z2, as partH's §F commands are). No off command is printed here: CS-G1, on the same "
         "stage A just before, stops first while any claim switch is present, and prints it — so when FE-G1 runs, none is.")
    + r'''uj_fe_g1() {
  uj_ok "$UJ_A" && [ "$(kv "$UJ_A" en)" = "off" ] || return 0
  echo
  echo "  FE-G1 FOLLOWUP_ENABLED READS OFF AS THE JOBS READ IT (U4 above): THE ENQUIRY FOLLOW-UPS ARE PAUSED, AND UJ-ON WOULD READ THIS VALUE FROM STAGE A FOR THE LIFE OF THIS DEPLOY — F3 COULD NEVER BE JUDGED. CS-G1 above found none of the three claim switches present, so step 6 is off. Turn followup_enabled on yourself, its own command, alone, with its own log — this script never runs it:"
  if [ -n "$DB_OWNER" ]; then
''' + FE_CMD + r'''
    echo "  It prints the line \"  followup_enabled = 1\" once it has saved; without that line take nothing as saved (this script, run again, reads it first: U4, FE-G1)."
  else
    echo "  No command is printed: the owner of plugin.sqlite3 is not known (DB_OWNER) — a settings write runs as that user, never as root; find why first."
  fi
''' + FE_STOP + "\n}\n"
    + cw("uj_after — after the copy (stage R, the deploy run and --after-only): UJ-CA, U1–U3 again on a fresh copy through "
         "the pin's code (P3B_PIN), beside stage A's (UJ_A: this run's, or in --after-only the deploy's, from its state "
         "file), and the closes, expiries and sends since the copy — notes; then, in --after-only, UJ-ON (uj_on). Never stops.")
    + r'''uj_after() {
  local sub o cu au
  cih_main || true   # the run's folder, made in this shell before the reading on a copy below, which runs in $( … ) (DS3-fix1)
  cu="${COPY_AT:-}"
  if [ "$MODE" = "after" ]; then
    cu="$(sed -n 's/^COPY_AT=//p' "$STATE" 2>/dev/null | tail -n1)"
    UJ_A="$(sed -n 's/^UJ_A=//p' "$STATE" 2>/dev/null | tail -n1)"
  fi
  case "$cu" in ''|*[!0-9]*) cu="" ;; esac
  au=""; uj_ok "$UJ_A" && au="$(kv "$UJ_A" at)"
  if [ -z "${P3B_PIN:-}" ]; then o="uj=unread(the pin's code is not in the container's /tmp: ${P3B_WHY:-?})"
  elif ! sub="$(qf_copy uj "$P3B_PIN")"; then o="uj=unread(a copy of the book could not be made: ${sub:-?})"
  else o="$(uj_read "$sub" "$cu" "$au")"; cih_drop "$sub"; fi
  if uj_ok "$o"; then
    uj_notes UJ-CA "$o" "$UJ_A"
    uj_ok "$UJ_A" || note "UJ-CA stage A's reading is not on record (UJ_A$( [ "$MODE" = "after" ] && echo " in $STATE")) — U1–U3 stand alone"
    if [ -z "$cu" ]; then note "UJ-CA the copy's time is not known — the closes, expiries and sends since the copy are not counted"
    elif [ -n "$(kv "$o" on)" ]; then note "UJ-CA the closes, expiries and sends since the copy could not be read ($(kv "$o" on))"
    else note "UJ-CA since the copy ($(utc_of "$cu")): F2 closed $(kv "$o" f2) follow-up(s) whose customer was quiet past the limit (actor sender), F5 expired $(kv "$o" f5_taken) draft(s) whose attempt was taken, F1 closed $(kv "$o" f1) refused draft(s), the after-send sibling expiries $(kv "$o" siblings) (events/drafts), $(kv "$o" sends) enquiry follow-up(s) sent — F2's and F5's come at any hour but only for drafts in the sender's ten places; F1's, the siblings and the sends only inside the sender's window"; fi
  else note "UJ-CA U1–U3 could not be read after the copy (${o#uj=}) — NOT ESTABLISHED"; fi
  [ "$MODE" != "after" ] || uj_on "$o" "$cu"
}
'''
    + cw("uj_on LINE COPYUNIX — UJ-ON (root docs/75 §L.5, DL3), --after-only only: whether F3 works, on this run's reading "
         "(LINE) beside stage A's (UJ_A, the deploy's). Its gate first — at stage A and now, followup_enabled on, "
         "StaffJobsGate::applies reading Uganda, the age limit a positive number and the same at both: a condition measured "
         "false is a note naming it, no verdict, which never holds back PASSED — false now, F3 has nothing to do (or another "
         "limit to do it by); false only at stage A, F3 may run now but cannot be judged for this "
         "deploy, whose stage A is fixed in the state file (review UJ-2); stage A's gate holding and only the limit now "
         "another, it is not judged on this run, and is once the limit is set back and a close pass has run after it "
         "(review 2); a reading that could not be made is never "
         "the gate's note — this run's is a FAIL, stage A's absent or unread judges on this run's values alone and says so. "
         "With the gate holding, exactly one of PASS, FAIL and NOT YET; any other outcome is a FAIL. PASS: an enquiry "
         "follow-up closed expired by the closer since the copy, with F3's own detail; or, the positive control holding, "
         "expireStale's SELECT at T (the pin's staleBefore at T, a present conversation, LIMIT 1) selects no row — nothing "
         "stale for F3 to close. FAIL: the control holds, none closed expired, fewer than 200 closed cancelled by the closer "
         "at or after T (so the last pass's cap does not explain it; counted from T, so earlier passes' cancels cannot mask "
         "a broken F3), and that SELECT selects a row. NOT YET: every other case — without the control, or with it and 200 "
         "or more cancelled at or after T (the close passes' places went to gone conversations). The control: "
         "followup_close's entry in master_schedule.json (on this run's copy) started after the copy and ended — last_run, "
         "a UTC epoch, later than COPY_AT, and duration_ms at least 0 — and T is that last_run (never last_run_at, local "
         "time and overwritten with the job's end). NOT YET also sets UJ_NY_AT and UJ_NY_SAY — its counts with it (review "
         "UJ-3) — for the summary; the gate's note measured now, or on the limit alone, sets UJ_ON_GATE (now, limit), "
         "so the summary's check it says when the next run judges F3 (review 2).")
    + r'''uj_on() {
  local o="$1" cu="$2" a="$UJ_A" g="" ga="" gs="" lim="" alone="" ex ctl t cn st stn r cnt
  if ! uj_ok "$o" || ! uj_gate_ok "$o"; then bad "UJ-ON FAIL — this run's reading could not be made (${o#uj=}): whether F3 works is not shown"; return 0; fi
  uj_ok "$a" && uj_gate_ok "$a" || alone="; judged on this run's values alone — stage A's reading is not on record (UJ_A in $STATE)"
  [ "$(kv "$o" en)" = "on" ] || g="$g followup_enabled is off now;"
  [ "$(kv "$o" ug)" = "1" ] || g="$g StaffJobsGate::applies does not read Uganda now;"
  [ "$(kv "$o" hours)" != "misread" ] || g="$g followup_max_age_hours is not a positive number now;"
  if [ -z "$alone" ]; then
    gs="$(uj_gate_a "$a")"; ga="$gs"
    [ "$(kv "$a" hours)" = "$(kv "$o" hours)" ] || [ "$(kv "$a" hours)" = "misread" ] || [ "$(kv "$o" hours)" = "misread" ] \
      || lim=" the age limit differs — $(kv "$a" hours) h at stage A, $(kv "$o" hours) h now;"
  fi
  # a condition measured false now: F3 has nothing to do now, or another limit to do it by (review UJ-2)
  if [ -n "$g" ]; then g="$g$ga$lim"; UJ_ON_GATE="now"; note "UJ-ON not judged — its gate did not hold:${g%;}$alone. F3 then has nothing to do, or another limit to do it by; a note, which holds back nothing"; return 0; fi
  # false only at stage A: F3 may run now, but this deploy's stage A is fixed in the state file (a deploy run while
  # 5.18.106 serves is an --after-only of it): UJ-ON cannot judge F3 for it (review UJ-2)
  if [ -n "$ga" ]; then ga="$ga$lim"; note "UJ-ON not judged for this deploy — its gate did not hold at stage A:${ga%;}. F3 may be running now, but this deploy's stage A reading is fixed in $STATE (a deploy run while $EXPECTED_VERSION serves is an --after-only of this one), so UJ-ON cannot judge F3 for this deploy: F3 stays unproven, and the steps that wait for it (6, 11, 12) would follow without that proof. A judged UJ-ON needs a rollback and then a --redeploy, its stage A taken with the gate holding; a note, which holds back nothing"; return 0; fi
  # stage A's gate held, but the age limit is another now: UJ-ON judges F3 only by the limit stage A read — set
  # back, and a close pass run after that, the next --after-only judges F3 (review 2: UJ2-G3)
  if [ -n "$lim" ]; then UJ_ON_GATE="limit"; note "UJ-ON not judged on this run — stage A's gate held, but followup_max_age_hours is $(kv "$o" hours) h now and was $(kv "$a" hours) h at stage A, and UJ-ON judges F3 only by the limit stage A read: set it back to $(kv "$a" hours) h and, once a close pass has run after that (followup_close runs every 10 minutes), run --after-only again to have F3 judged — or, to keep the new limit, a rollback and then a --redeploy, whose stage A reads it. Until then F3 is unproven, and the steps that wait for it (6, 11, 12) would follow without that proof; a note, which holds back nothing"; return 0; fi
  [ -n "$cu" ] || { bad "UJ-ON FAIL — the copy's time is not on record (COPY_AT in $STATE), so nothing since the copy can be counted$alone"; return 0; }
  ex="$(kv "$o" on_expired)"; ctl="$(kv "$o" control)"; t="$(kv "$o" t)"; cn="$(kv "$o" on_cancelled_t)"; st="$(kv "$o" on_stale_t)"; stn="$(kv "$o" on_stale_t_n)"
  r="$(kv "$o" replied_a)"; [ "$r" != "-" ] || r="not counted (stage A's time is not on record)"
  cnt="closes by the closer since the copy: $(kv "$o" by_closer | tr ',:' '  '); closed replied since stage A: $r"
  case "$ex" in ''|*[!0-9]*) bad "UJ-ON FAIL — the closes since the copy could not be read on this run's copy ($(kv "$o" on))$alone"; return 0 ;; esac
  if [ "$ex" -gt 0 ]; then ok "UJ-ON PASS — F3 works: $ex enquiry follow-up(s) closed expired by the closer since the copy, each with F3's own detail (\"expired — the customer last wrote at …\"); $cnt$alone"; return 0; fi
  case "$ctl" in
    held)
      case "$cn" in ''|*[!0-9]*) cn="" ;; esac
      case "$st" in 0|1) ;; *) st="" ;; esac
      if [ -z "$cn" ] || [ -z "$st" ]; then bad "UJ-ON FAIL — followup_close started at $t, after the copy, and ended, but the cancels since then or expireStale's SELECT at that moment could not be read$alone"
      elif [ "$st" = "0" ]; then ok "UJ-ON PASS — nothing stale for F3 to close: followup_close started at $t, after the copy, and ended; expireStale's SELECT at that moment (the pin's staleBefore at $t, a present conversation, LIMIT 1) selects no row; none closed expired since the copy; $cnt$alone"
      elif [ "$cn" -ge 200 ]; then
        UJ_NY_AT="$(kv "$o" again)"
        UJ_NY_SAY="the close passes' places went to gone conversations — $cn closed cancelled by the closer since followup_close started at $t, none expired, $stn stale follow-up(s) with a present conversation left at $t; $cnt"
        notyet "UJ-ON NOT YET — $UJ_NY_SAY$alone; run it again after $UJ_NY_AT"
      else bad "UJ-ON FAIL — F3 did not expire: followup_close started at $t, after the copy, and ended; none closed expired by the closer since the copy, $cn closed cancelled at or after that start (fewer than 200, so a pass's cap does not explain it), and expireStale's SELECT at $t still selects $stn stale enquiry follow-up(s) with a present conversation; $cnt$alone"; fi ;;
    not)
      UJ_NY_AT="$(kv "$o" again)"
      UJ_NY_SAY="followup_close has not started and ended since the copy (its last start $(kv "$o" fc_last), $(kv "$o" fc_state)), and none closed expired yet; $cnt"
      notyet "UJ-ON NOT YET — $UJ_NY_SAY$alone; run it again after $UJ_NY_AT" ;;
    *) bad "UJ-ON FAIL — followup_close's entry in master_schedule.json could not be read on this run's copy (the schedule $(kv "$o" sched), the control ${ctl:-unread})$alone" ;;
  esac
  return 0
}
''')

STAGE_CALL = ("  uj_stage \"$sub\"   # U1–U4 — 5.18.106 (root docs/75 §L.4): on this copy, through the pin's code; notes; the deploy run keeps the line (UJ_A)\n"
              + FE_CALL
              + "  " + SLOT_U5 + "\n")
STATE_LINES = (cw("5.18.106 (root docs/75 §L.4): stage A's UJ reading (U1–U4 — counts, UTC times and generic channel labels, "
                  "never a phone, a message, an instance name or an event's detail), for UJ-CA and UJ-ON of a later --after-only "
                  "(uj_after). --check never reaches this block: it writes nothing.", "  # ")
               + "  [ \"$MODE\" = \"deploy\" ] && printf 'UJ_A=%s\\n' \"$(printf '%s' \"${UJ_A:-}\" | tr -d '\\n')\" >> \"$STATE_W\"\n")
R_CALL = (cw("UJ-CA and UJ-ON — 5.18.106 (root docs/75 §L.5), after the copy: U1–U3 again on a fresh copy through the pin's "
             "code, beside stage A's (notes); in --after-only, UJ-ON judges whether F3 works — PASS, FAIL or NOT YET (the "
             "summary's third state). Reports; never stops.")
          + "hdr \"R. The enquiry follow-ups after the copy (5.18.106, UJ-CA$( [ \"$MODE\" = \"after\" ] && echo \" and UJ-ON\"); read-only, on a copy through the pin's code)\"\n"
          + "uj_after   # UJ-CA (notes) and, in --after-only, UJ-ON (judged)\n")
NOTYET_DEF = ("notyet() { NOT_YET=$((NOT_YET+1)); printf '  wait  %s\\n' \"$*\"; }   # 5.18.106 (root docs/75 §L.5): UJ-ON's NOT YET — "
              "withholds PASSED, never a FAIL\n")
SUM_OLD = ('echo "  checks            $PASS ok, $FAIL failed, $NOTE notes"\n'
           'if [ "$FAIL" = "0" ]; then echo; echo "  $EXPECTED_VERSION ($MODE): PASSED. Send this LOG FILE back (not a copy of the terminal, and never the cash sheet)."\n'
           'else echo; echo "  $EXPECTED_VERSION ($MODE): $FAIL FAILED — send the log file (never the cash sheet); do not roll back on your own unless staff or customers are affected."; fi\n')
SUM_NEW = ('echo "  checks            $PASS ok, $FAIL failed, $NOTE notes$( [ "$NOT_YET" = "0" ] || echo ", $NOT_YET not yet")"\n'
           + cw("5.18.106 (root docs/75 §L.5, DL3): three states, FAIL first — PASSED; NOT YET (UJ-ON's, --after-only only: "
                "PASSED and the owner's steps wait for a later --after-only, its command alone on its line with its log; exit "
                "status 3); FAILED.")
           + 'if [ "$FAIL" = "0" ] && [ "$NOT_YET" = "0" ]; then echo; echo "  $EXPECTED_VERSION ($MODE): PASSED. Send this LOG FILE back (not a copy of the terminal, and never the cash sheet)."\n'
           'elif [ "$FAIL" = "0" ]; then\n'
           '  echo; echo "  $EXPECTED_VERSION (after-only): NOT YET — ${UJ_NY_SAY:-UJ-ON above}; run it again after ${UJ_NY_AT:-the next close pass}"\n'
           '  echo "  Send this LOG FILE back (not a copy of the terminal, and never the cash sheet). Then --after-only again, its own command, alone, with its own log:"\n'
           '  echo "      $AFTER_CMD"\n'
           'else echo; echo "  $EXPECTED_VERSION ($MODE): $FAIL FAILED — send the log file (never the cash sheet); do not roll back on your own unless staff or customers are affected."; fi\n')
EXIT_OLD = '[ "$FAIL" = "0" ]\n}\n\nmain "$@"; exit $?'
EXIT_NEW = ('[ "$FAIL" = "0" ] || return 1      # FAILED: exit status 1\n'
            '[ "$NOT_YET" = "0" ] || return 3   # 5.18.106 (root docs/75 §L.5): NOT YET, exit status 3; PASSED 0\n'
            '}\n\nmain "$@"; exit $?')


def need(text, s, what, n=1):
    c = text.count(s)
    if c != n:
        raise SystemExit(f"PART UJ: {what}: {s[:110]!r} found {c} times, expected {n}")


def in_order(text, items, what):
    at = -1
    for s in items:
        need(text, s, what)
        i = text.index(s)
        if i < at:
            raise SystemExit(f"PART UJ: {what}: {s[:90]!r} is not after the check before it")
        at = i


def block_between(text, head, end, what):
    if text.count(head) != 1:
        raise SystemExit(f"PART UJ: {what}: {head[:90]!r} found {text.count(head)} times")
    i = text.index(head)
    j = text.find(end, i)
    if j < 0:
        raise SystemExit(f"PART UJ: {what}: its end {end[:60]!r} not found")
    return text[i:j + len(end)]


def apply(doc, ctx):
    repo, plugin, base, pin = ctx["repo"], ctx["plugin"], ctx["base_full"], ctx["pin_full"]
    show = lambda c, p: git(repo, "show", f"{c}:{plugin}/{p}").decode()

    # ── Git: the pin's code UJ reads through, and every text UJ copies or counts by ──
    fus = show(pin, "lib/FollowUpService.php")
    xsf = block_between(fus, "    public function expireStale(string $staleBeforeUtc, int $limit, string $hours, string $by): int\n",
                        "\n        return $n;\n    }\n", "expireStale")
    km = "        $kind = $this->hasKind() ? \" AND COALESCE(f.kind,'enquiry')='enquiry'\" : '';\n"
    need(xsf, km, "expireStale's kind line")
    xm = re.search(r'(?m)^( *"SELECT f\.id, f\.last_customer_at, c\.id AS cid FROM followups f .*?LIMIT \?")', xsf, re.S)
    if not xm or xm.group(1).count("\n") != 3:
        raise SystemExit("PART UJ: expireStale's SELECT is not the four-line string derive106.py copies")
    XS = xm.group(1)
    need(fus, XS, "expireStale's SELECT, once in the pin's FollowUpService")
    for s in ("            $st->execute([$staleBeforeUtc, $staleBeforeUtc, $limit]);\n",
              "                ? $this->close((int)$r['id'], 'cancelled', 'the conversation no longer exists', $by)\n",
              "                : $this->close((int)$r['id'], 'expired', 'the customer last wrote at ' . (string)$r['last_customer_at']\n",
              "            $ok = $r['cid'] === null\n"):
        need(xsf, s, "expireStale")
    need(fus, "                $reason === 'opted_out' ? 'opted_out' : 'closed',\n", "close()'s event")
    need(fus, "                $reason . ($detail !== '' ? ' — ' . $detail : ''), $actor);\n", "close()'s detail")
    for s in ("    public static function staleBefore(array $config, int $nowTs): ?string\n",
              "        $hours = (float)($config['followup_max_age_hours'] ?? 336);\n        if (!($hours > 0)) return null;\n"
              "        return gmdate('Y-m-d H:i:s', $nowTs - (int)($hours * 3600));\n",
              "    public static function staleHours(array $config): string\n",
              "    public function due(string $nowUtc, int $limit = 25, ?string $staleBeforeUtc = null, bool $waitingOut = false): array\n",
              "        $more = '';   // 5.18.106 (root docs/75 §C F3, F5): nothing unless the caller asks\n",
              "    public function approvedDrafts(int $limit = 20, string $andSql = '', array $args = []): array\n",
              "    public function attemptTaken(int $followupId, int $draftId, int $attempt): bool\n",
              "    public function refusedWhileDelivering(int $followupId, string $channel, string $sinceUtc, int $enough = 3): int\n",
              "    public function hasKind(): bool\n",
              "                    AND " + REFUSAL + "\n",
              "            $this->log($followupId, null, 'skipped', '" + F5_TAKEN + "', $by);\n",
              "            if ($n > 0) $this->log($followupId, null, 'skipped', '" + F5_SIBS + "' . $n, $by);\n"):
        need(fus, s, "the pin's FollowUpService")
    for s in DELIVERY:
        need(fus, s, "refusedWhileDelivering's deliveries")
    # 5.18.105's due() is the pin's with its new parameters left out: the same SQL, the same arguments
    old = show(base, "lib/FollowUpService.php")
    q_old = ("            \"SELECT * FROM followups\n              WHERE closed_at IS NULL AND due_at IS NOT NULL AND due_at <= ?\" . $kind . \"\n"
             "              ORDER BY due_at LIMIT ?\");\n        $st->execute([$nowUtc, $limit]);\n")
    q_new = ("            \"SELECT * FROM followups\n              WHERE closed_at IS NULL AND due_at IS NOT NULL AND due_at <= ?\" . $kind . $more . \"\n"
             "              ORDER BY due_at LIMIT ?\");\n        $st->execute($args);\n")
    need(old, q_old, "7835906's due()")
    need(fus, q_new, "the pin's due()")
    need(fus, "        $args = [$nowUtc];\n", "the pin's due() arguments")
    fc = show(pin, "cron/followup_close.php")
    for s in ("$config  = PluginConfig::load($pluginRoot, $dataDir);\n", "if (StaffJobsGate::applies($config, $dataDir)) {\n",
              "    $fcStale = FollowUpService::staleBefore($config, time());\n",
              "    if ($fcStale !== null) $fcExpired = $svc->expireStale($fcStale, 200, FollowUpService::staleHours($config), 'closer');\n",
              "if (empty($config['followup_enabled'])) return;\n"):
        need(fc, s, "cron/followup_close.php")
    fs = show(pin, "cron/followup_send.php")
    for s in ("$config  = PluginConfig::load($pluginRoot, $dataDir);\n", "$evo->useStaffStore($store);",
              "$colleagues = ColleagueNumbers::forInstall($config, $dataDir, $store);\n",
              "$ownedHold = OwnedNumberHold::forInstall($config, $dataDir, $pdo);\n",
              "$fsUg     = StaffJobsGate::applies($config, $dataDir);\n",
              "$fsStale = $fsUg ? FollowUpService::staleBefore($config, $fsClock()) : null;\n",
              "[$heldSql, $heldArgs] = $evo->automationPolicy()->sqlHeld('f.channel');\n",
              "if ($enqOn) foreach ($svc->approvedDrafts(10, $heldSql, $heldArgs) as $d) {",
              "            if ($fcLedger) $fsMode = WaSendBudget::enquiryClaims($fc) ? 'claim' : 'record';\n",
              "'the customer last wrote more than ' . FollowUpService::staleHours($config) . ' hours ago', 'sender');",
              "$svc->close($fuId, 'send_failed', 'Evolution refused it three times (HTTP 400) while the number delivered other messages', 'sender');"):
        need(fs, s, "cron/followup_send.php")
    in_order(fs, ["        if (isset($fsSeen[$fuId])) { $held++; $fsCount('enquiry_second_draft'); continue; }\n",
                  "        if ($svc->attemptTaken($fuId, (int)$d['id'], (int)($d['attempt'] ?? 0))) {\n",
                  "    if (trim($body) === '') {\n", "    if ($conv === null) {\n",
                  "    if ($colleagues !== null && ($colleagues->isColleague($phone) || $colleagues->isColleagueConversation($conv))) {\n",
                  "    if ($ownedHold->holds($chan)) {\n", "    $v = $oo->blocks($phone, $chan, ContactOptOut::CLASS_PROACTIVE);\n",
                  "    if ($dKind === 'enquiry' && (string)($conv['state'] ?? '') === 'human_active') {\n",
                  "    if ($dKind === 'enquiry' && (string)($conv['last_customer_at'] ?? '') > (string)($d['decided_at'] ?? '')) {\n",
                  "    if ($fsStale !== null && $dKind === 'enquiry' && (string)($conv['last_customer_at'] ?? '') < $fsStale) {\n",
                  "    $win = FollowUpPolicy::withinSendingWindow($now);\n",
                  "    if ($fsUg && $dKind === 'enquiry' && $fsMode !== 'claim') {\n",
                  "        if ($svc->refusedWhileDelivering($fuId, $chan, (string)($d['decided_at'] ?? ''), 3) >= 3) {\n",
                  "    $res = $evo->sendText($chan, $phone, $body, ContactOptOut::CLASS_PROACTIVE);\n"], "the sender's checks, in order")
    fr = show(pin, "cron/followup_run.php")
    for s in ("$cap      = (int)($config['followup_daily_cap'] ?? 30);", "$perRun   = (int)($config['followup_run_limit'] ?? 5);",
              "foreach ($svc->due($now, $perRun, $ugRun ? FollowUpService::staleBefore($config, strtotime($now . ' UTC')) : null, $ugRun) as $fu) {\n"):
        need(fr, s, "cron/followup_run.php")
    fsc = show(pin, "cron/followup_scan.php")
    for s in ("$perScan = (int)($config['followup_scan_limit'] ?? 25);", "$maxAge  = (float)($config['followup_max_age_hours'] ?? 336);"):
        need(fsc, s, "cron/followup_scan.php")
    for f, s in (("lib/FollowUpPolicy.php", "        if (!filter_var($config['followup_auto_send'] ?? false, FILTER_VALIDATE_BOOLEAN)) {\n"),
                 ("lib/FollowUpPolicy.php", "    public static function withinSendingWindow(string $utcNow): array\n"),
                 ("lib/WaSendBudget.php", "    public static function on(array $config, ?string $dataDir): bool\n"),
                 ("lib/WaSendBudget.php", "    public static function enquiryClaims(array $config): bool\n"),
                 ("lib/StaffJobsGate.php", "    public static function applies(array $config, ?string $dataDir): bool\n"),
                 ("lib/PluginConfig.php", "    public static function load(string $pluginRoot, string $dataDir): array\n"),
                 ("lib/ColleagueNumbers.php", "    public static function forInstall(array $config, ?string $dataDir, $store): ?self\n"),
                 ("lib/ColleagueNumbers.php", "            $rows = $store->load('retailers.json');\n"),
                 ("lib/OwnedNumberHold.php", "    public static function forInstall(array $config, ?string $dataDir, ?\\PDO $pdo): self\n"),
                 ("lib/ContactOptOut.php", "    public function __construct(\\PDO $db) { $this->db = $db; }\n"),
                 ("lib/ContactOptOut.php", "    public function blocks(string $phone, string $channel, string $class = self::CLASS_PROACTIVE): array\n"),
                 ("lib/EvolutionApiService.php", "    public static function forStore(array $config, ?\\PDO $pdo, ?string $dataDir, int $timeout = 20): self\n"),
                 ("lib/EvolutionApiService.php", "    public function useStaffStore($store): void\n"),
                 ("lib/EvolutionApiService.php", "    public function automationPolicy(): \\AutomationPolicy\n"),
                 ("lib/AutomationPolicy.php", "    public function sqlHeld(string $column): array\n"),
                 ("migrations/070_followups.sql", "CREATE TRIGGER IF NOT EXISTS fu_closed_is_history\n")):
        need(show(pin, f), s, f)
    # FE-G1 (DL11) and UJ-1 stand on these, from Git. set_config — FE-G1's printed command runs the installed one, 7835906's
    # at stage A, the pin's the same — lists followup_enabled as a bool and names it nowhere else (no refusal, no warning, no
    # other handling), saves through saveOverrides, prints "  followup_enabled = 1" once saved and its listing after that
    # line (the cut); dn_tz() reads DN_DATA_DIR's kyc_config.json, and the sender's window reads dn_tz()
    for c in (base, pin):
        sc = show(c, "tools/set_config.php")
        for s in ("    'followup_enabled' => ['bool',\n", "$dataDir = cliDataDir($root);\n",
                  "list($ok, $err) = PluginConfig::saveOverrides($dataDir, [$key => $new]);\n",
                  'else                 echo "\\n  " . $key . " = " . $new . "\\n";\n$show();\nexit(0);\n',
                  '    echo "\\n  AI SETTINGS\\n\\n";\n', "followup_enabled"):
            need(sc, s, f"tools/set_config.php at {c[:7]} (FE-G1)")
    if sha_at(repo, base, f"{plugin}/tools/set_config.php") != sha_at(repo, pin, f"{plugin}/tools/set_config.php"):
        raise SystemExit("PART UJ: tools/set_config.php differs between 7835906 and the pin — FE-G1 prints a command for the installed one")
    tzf = show(pin, "lib/timezone.php")
    for s in ("    function dn_tz(?array $config = null): string\n", "        $dir = getenv('DN_DATA_DIR') ?: '';\n",
              "        $f = rtrim($dir, '/') . '/kyc_config.json';\n"):
        need(tzf, s, "lib/timezone.php at the pin (UJ-1)")
    need(show(pin, "lib/FollowUpPolicy.php"), "        $local = $t->setTimezone(dn_tz_obj());\n", "the sending window's zone (UJ-1)")
    for l in UJ_LIBS:
        git(repo, "cat-file", "-e", f"{pin}:{plugin}/lib/{l}.php")
    # the three keys conf keeps since 5.18.106: kept nowhere before, none a secret, none a vault key, none a ladder key
    lad = show(pin, "lib/SalesFollowupLadder.php")
    sec = re.search(r"const SECRET_KEYS = \[(.*?)\];", show(pin, "lib/PluginConfig.php"), re.S).group(1)
    vlt = re.search(r"const VAULT_KEYS = \[(.*?)\];", show(pin, "lib/ConfigVault.php"), re.S)
    if not vlt:
        raise SystemExit("PART UJ: ConfigVault::VAULT_KEYS not found at the pin")
    for k in KEEP3:
        for txt, what in ((lad, "the ladder"), (sec, "PluginConfig::SECRET_KEYS"), (vlt.group(1), "ConfigVault::VAULT_KEYS")):
            if f"'{k}'" in txt:
                raise SystemExit(f"PART UJ: {k} is named by {what} at the pin — §L.4's reason to add it to conf would not hold")
    if "'followup_enabled'" in sec:
        raise SystemExit("PART UJ: followup_enabled is a secret at the pin — conf would cut it, every copy would read it off, "
                         "and FE-G1 would refuse every deploy")

    # ── the source: what UJ stands on ──
    qf = block_between(doc.t, "QF_PHP=\"$(cat <<'PHP'\n", "\nPHP\n)\"\n", "QF_PHP")
    conf = block_between(qf, "    case 'conf':   // conf ROOT LIVEROOT PDD DEST", "        break;\n", "conf")
    for k in KEEP3:
        if f"'{k}'" in conf:
            raise SystemExit(f"PART UJ: conf already keeps {k}")
    need(conf, "        $n1 = ['followup_enabled', ", "conf keeps followup_enabled — U4 reads it on the copy, and FE-G1 refuses on it")
    # FE-G1 runs after CS-G1, which returns only when none of the three claim switches is present and otherwise stops
    csb = block_between(doc.t, "cs_check() {\n", "\n}\n", "cs_check")
    csl = csb.split("\n")
    if csb.count("return 0") != 1 or csb.count("  if [ -z \"$f\" ]; then\n") != 1 \
            or not csb.index("  if [ -z \"$f\" ]; then\n") < csb.index("    return 0\n") < csb.index("  fi\n") \
            or not csl[-3].startswith("  stop \"NO-GO (CS-G1): a claim switch is present ($f)"):
        raise SystemExit("PART UJ: cs_check does not stop whenever a claim switch is present — FE-G1, after it, must be reached "
                         "only with none")
    need(conf, "        $keep = array_values(array_diff(array_unique(array_merge($L['all'], $L['settings'], $L['pre'], $book, $n1)), $secret($root)));\n",
         "conf's keep list")
    for s in ("$flat = function (PDO $p, string $name) use ($has) {", "$docRows = function (PDO $p, string $name) use ($has) {",
              "$sched = function (PDO $p) use ($has, $JOBS): array {", "$readJson = function (string $f) {",
              "$storeRow = function (PDO $p) use ($has): array {", "$ro = function (string $db) {",
              "$has = function (PDO $p, string $t): bool {", "    case 'rbn':    // rbn PDD — "):
        need(qf, s, "QF_PHP, which uj runs in")
    for s in ("qf_php() { dxo php -d display_errors=stderr -d log_errors=0 -d opcache.enable_cli=0 -r \"$QF_PHP\" \"$@\"; }",
              "qf_copy() { qf_copy_in \"$@\" || { printf '%s' \"${QF_ROOT_ERR:-?}\"; return 1; }; }",
              "kv() { printf '%s' \"$1\" | sed -nE \"s/^(.* )?$2=([^ ]*).*\\$/\\2/p\" | head -1; }",
              "utc_of() { date -u -d \"@$1\" +%Y-%m-%dT%H:%M:%SZ 2>/dev/null || echo \"@$1\"; }",
              "P3B_HOST=\"$OUT/p3b-$TS-$$\"; P3B_OK=\"\"; P3B_WHY=\"\"; P3B_PIN=\"\";",
              "  COPY_AT=\"$(stat -c %Y \"$DEST/$COPY_MARK\" 2>/dev/null || true)\"\n  printf 'COPY_AT=%s\\n' \"$COPY_AT\" >> \"$STATE\"\n",
              "AFTER_CMD=\"cd $REPO && bash scripts/deploy-$EXPECTED_VERSION.sh --after-only $(LOGW after)\"\n",
              "PASS=0; FAIL=0; NOTE=0\n", "note() { NOTE=$((NOTE+1)); printf '  note  %s\\n' \"$*\"; }\n"):
        need(doc.t, s, "the source UJ reads")
    # (the word NOT_YET is the switch tool's status in the source, read into notes; the counter NOT_YET is new)
    for s in ("uj_", "UJ_A", "UJ-ON", "UJ-CA", "NOT_YET=", "$NOT_YET", "notyet", "case 'uj':", "SLOT U5", "FE-G1",
              "followup-enabled-1", "--key followup_enabled"):
        doc.mustnot(s, why="UJ's names are new")

    # ── QF_PHP: the vault rule names uj; conf keeps the three keys; the uj mode, before rbn ──
    doc.sub("if (in_array($mode, ['notes', 'ca4', 'ca6', 'ca8', 'on3', 'lon', 'cfgkey'], true)) putenv('DN_VAULT_FILE='",
            "if (in_array($mode, ['notes', 'ca4', 'ca6', 'ca8', 'on3', 'lon', 'cfgkey', 'uj'], true)) putenv('DN_VAULT_FILE='",
            why="QF_PHP's vault rule names uj")
    doc.insert_after("if (in_array($mode, ['notes', 'ca4', 'ca6', 'ca8', 'on3', 'lon', 'cfgkey', 'uj'], true)) "
                     "putenv('DN_VAULT_FILE=' . rtrim((string)($a[1] ?? ''), '/') . '/vault.json');\n", DD,
                     why="QF_PHP: uj's data folder too (review UJ-1)")
    doc.sub("               'alert_hours_from', 'alert_hours_to'];\n",
            "               'alert_hours_from', 'alert_hours_to',\n"
            "               // 5.18.106 (root docs/75 §L.4): UJ's readings — F2's and F3's age limit, the run's and the scan's places\n"
            "               '" + "', '".join(KEEP3) + "'];\n", why="conf keeps the three keys")
    php = UJ_PHP
    for k, v in (("⟦LIBS⟧", "['" + "', '".join(UJ_LIBS) + "']"), ("⟦REFUSAL⟧", REFUSAL), ("⟦REFUSAL_E⟧", REFUSAL.replace("detail", "e.detail")),
                 ("⟦DELIVERY0⟧", DELIVERY[0]), ("⟦DELIVERY1⟧", DELIVERY[1]), ("⟦KIND⟧", km.rstrip("\n").replace("$this->hasKind()", "$svc->hasKind()")),
                 ("⟦XS⟧", XS), ("⟦F3⟧", F3_DETAIL), ("⟦F2⟧", F2_DETAIL), ("⟦F1⟧", F1_DETAIL), ("⟦F5T⟧", F5_TAKEN), ("⟦F5S⟧", F5_SIBS)):
        if php.count(k) != 1:
            raise SystemExit(f"PART UJ: the uj mode's text holds {k} {php.count(k)} times")
        php = php.replace(k, v)
    if "⟦" in php or "\nPHP\n" in "\n" + php:
        raise SystemExit("PART UJ: the uj mode's text is not complete, or holds the heredoc's end")
    doc.insert_before("    case 'rbn':    // rbn PDD — ", php, why="QF_PHP uj")

    # ── the shell: UJ's functions before qf_stage; stage A's call (and the slot U5); UJ_A in the backup block ──
    funcs = UJ_RULE + "\n" + UJ_FUNCS
    doc.insert_before("# qf_stage — QF-G1–QF-G3, LK-G1 and QF-N1–QF-N7, stage A, before anything changes", funcs, why="UJ functions")
    doc.insert_before("  cih_drop \"$sub\"\n\n  # QF-G2 — the one stage-A migration trial for every part (ruling R10)", STAGE_CALL, why="U1–U4 at stage A")
    qg3 = [l for l in doc.t.split("\n") if l.startswith("  [ \"$MODE\" = \"deploy\" ] && printf 'QG3_A=%s")]
    if len(qg3) != 1 or not qg3[0].endswith(' >> "$STATE_W"'):
        raise SystemExit(f"PART UJ: the backup block's QG3_A line found {len(qg3)} times")
    doc.insert_after(qg3[0] + "\n", STATE_LINES, why="UJ_A in the state file")

    # ── stage R: UJ-CA and UJ-ON after LM's readings (partME puts ME-CA1 between them, §L.5's order) ──
    doc.insert_after("lm_after   # LM-CA1 and LM-CA2 (the deploy run) or LM-ON (--after-only) — root docs/74 §H.4.5\n", R_CALL,
                     why="UJ-CA and UJ-ON in stage R")

    # ── NOT YET: the counter, notyet, the third verdict branch, exit status 3 ──
    doc.sub("PASS=0; FAIL=0; NOTE=0\n", "PASS=0; FAIL=0; NOTE=0; NOT_YET=0\n", why="the NOT_YET counter")
    doc.insert_after("note() { NOTE=$((NOTE+1)); printf '  note  %s\\n' \"$*\"; }\n", NOTYET_DEF, why="notyet")
    doc.sub(SUM_OLD, SUM_NEW, why="the summary's third state")
    doc.sub(EXIT_OLD, EXIT_NEW, why="exit status 3")

    # ── the result ──
    for s, n in (("    case 'uj':     // uj ROOT DIR [COPYUNIX [AUNIX]]", 1), (XS, 1), ("$xsSql =\n" + XS + ";\n", 1),
                 ("        $kind = $svc->hasKind() ? \" AND COALESCE(f.kind,'enquiry')='enquiry'\" : '';\n", 1),
                 ("'cfgkey', 'uj'], true)) putenv(", 1), ("'" + "', '".join(KEEP3) + "'];\n", 1),
                 ("uj_read() {\n", 1), ("uj_ok() {", 1), ("uj_gate_ok() {\n", 1), ("uj_was() {", 1), ("uj_out() {\n", 1),
                 ("uj_places() {\n", 1), ("uj_notes() {\n", 1), ("uj_u4() {\n", 1), ("uj_stage() {\n", 1), ("uj_after() {\n", 1),
                 ("uj_on() {\n", 1), ("notyet() {", 1), (STAGE_CALL, 1), ("\n  " + SLOT_U5 + "\n  cih_drop \"$sub\"\n", 1),
                 ("printf 'UJ_A=%s\\n'", 1), ("\nuj_after   # ", 1), ("UJ-ON NOT YET — ", 2), ("UJ-ON PASS — ", 2),
                 ("NOT_YET=$((NOT_YET+1))", 1), ("return 3", 1), (DD, 1),
                 ("'tz=' . preg_replace('~[^A-Za-z0-9_/+-]~', '?', dn_tz())];\n", 1),
                 ("now (in $(kv \"$o\" tz), the zone the sender reads)", 1),
                 ("uj_gate_a() {\n", 1), ("uj_judgeable() {", 1), ("uj_fe_g1() {\n", 1), (FE_CALL, 1), (FE_CMD + "\n", 1),
                 (FE_STOP + "\n}\n", 1), ("  FE-G1 FOLLOWUP_ENABLED READS OFF AS THE JOBS READ IT (U4 above)", 1),
                 ("UJ-ON not judged — its gate did not hold:${g%;}$alone. F3 then has nothing to do, or another limit to do it by", 1),
                 ("UJ-ON not judged for this deploy — its gate did not hold at stage A:${ga%;}. F3 may be running now", 1),
                 ("UJ-ON not judged on this run — stage A's gate held, but followup_max_age_hours is ", 1),
                 ('UJ_ON_GATE="now"; note "UJ-ON not judged — its gate did not hold:', 1),
                 ('UJ_ON_GATE="limit"; note "UJ-ON not judged on this run', 1), ('UJ_ON_GATE=""\n', 1),
                 ("cannot judge F3 for this deploy", 1), ("set back to stage A's", 0),
                 ("U4 UJ-ON WILL NOT JUDGE F3 FOR THIS DEPLOY:", 1),
                 ("left at $t; $cnt\"\n", 1), ("and none closed expired yet; $cnt\"\n", 1),
                 ('notyet "UJ-ON NOT YET — $UJ_NY_SAY$alone; run it again after $UJ_NY_AT"', 2)):
        doc.must(s, n, why="UJ as written")
    for s in (F3_DETAIL, F2_DETAIL, F1_DETAIL, F5_TAKEN, F5_SIBS):
        doc.must("'" + s + "'", 1, why="UJ counts by the pin's own text")
    t = doc.t
    if not (t.index("qf_stage() {\n") < t.index(STAGE_CALL) < t.index("  sub=\"$(qf_copy g2 \"$P3B_PIN\")\"")):
        raise SystemExit("PART UJ: U1–U4 are not read in qf_stage, on QF-G1's copy, before QF-G2's")
    i = t.index("printf 'UJ_A=%s\\n'")
    if not (t.index('BK="$OUT/backup-$TS"; mkdir -p "$BK"; chmod 700 "$BK"') < i < t.index("# 5.18.97: --check stops here")):
        raise SystemExit("PART UJ: UJ_A is not written in the backup block, which --check never reaches")
    if not (t.index("\nlm_after   # LM-CA1") < t.index("\nuj_after   # ") < t.index("  qf_on \"${ICODE_R:-}\"\n")):
        raise SystemExit("PART UJ: uj_after is not called in stage R after lm_after and before qf_on")
    if not (t.index("QF_PHP=\"$(cat <<'PHP'") < t.index("    case 'uj':") < t.index("    case 'rbn':")):
        raise SystemExit("PART UJ: the uj mode is not inside QF_PHP, before rbn")
    if not (t.index("qf_stage() {\n") < t.index('  cs_check CS-G1 "$LK_G1"') < t.index(STAGE_CALL)):
        raise SystemExit("PART UJ: FE-G1 is not called in qf_stage after CS-G1 — it must be reached only with no claim switch "
                         "present")
    if [l for l in t.split("\n") if "--key followup_enabled" in l] != [FE_CMD] \
            or not re.match(r'\s+echo "      docker exec -u \$DB_OWNER ', FE_CMD):
        raise SystemExit("PART UJ: FE-G1's command is not printed once, on its own echo line, and run nowhere")
    fb = block_between(t, "uj_fe_g1() {\n", "\n}\n", "uj_fe_g1")
    if not fb.startswith("uj_fe_g1() {\n  uj_ok \"$UJ_A\" && [ \"$(kv \"$UJ_A\" en)\" = \"off\" ] || return 0\n") \
            or fb.count('\n  stop "') != 1 or not fb.endswith(FE_STOP + "\n}\n") or fb.index(FE_CMD) > fb.index(FE_STOP):
        raise SystemExit("PART UJ: uj_fe_g1 is not — followup_enabled read off at stage A, its command printed, then STOP")
    added = php + funcs + STAGE_CALL + STATE_LINES + R_CALL + NOTYET_DEF + SUM_NEW + EXIT_NEW + DD
    if added.split("\n").count(FE_CMD) != 1:
        raise SystemExit("PART UJ: FE-G1's command is not among UJ's lines exactly once")
    for l in added.split("\n"):   # FE-G1's command, printed by echo, is the one line here naming set_config
        s = l.lstrip()
        if l != FE_CMD and re.search(r"saveOverrides|writeKycFile|->save\(|set_config\.php|sales-followup\.sh|qf_tool|\$QF_WRAPPER|->close\(|->expireStale\(", l):
            raise SystemExit(f"PART UJ: a line UJ adds names a writer, the switch tool or a close: {s[:100]}")
        if s.startswith(("#", "//")) and not s.startswith(("# ── ", "# ── SLOT")) and len(l) > 120:
            raise SystemExit(f"PART UJ: a comment line UJ adds is longer than 120 characters: {l[:80]!r}")
    if len(UJ_RULE) != 120:
        raise SystemExit("PART UJ: the rule line is not 120 characters")
    ctx.update(uj_xs=XS, uj_slot="U5")
