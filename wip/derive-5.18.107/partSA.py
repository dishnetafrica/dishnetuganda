# Part SA — the readings before the deploy (root docs/76 §T.5), and the rest of 5.18.106's UJ machinery with them:
#   U1–U5 forecast and recorded F1–F5, 5.18.106's own; FE-G1 was their one refusal (DL11); UJ-CA and UJ-ON (§T.6) were
#   their reading after the copy. They are one block of the source — the uj mode, the UJ functions, stage A's uj_stage and
#   uj_fe_g1, the UJ_A state line and stage R's uj_after — so they retire together here; partME takes ME-CA1, ME-CA2 and
#   the NOT YET state, and partH the header and summary texts that still name them (uj_judgeable, UJ_ON_GATE, UJ_NY_SAY and
#   UJ_NY_AT are left, counted, for those two). as_u5 (U5, AS-RB1's precondition) goes with partR (§T.7). The three
#   keep-list keys only UJ read go too.
#   New at stage A, on QF-G1's copy through the pin's code, notes only — counts and fixed words, never a refusal (SA):
#   wa_no_ai's rows (absent on a first deploy); the burst marks still waiting — the pin's own --check burst-drain on the
#   copy, its counts only, kept in the state file for V1 (BD_A, §T.7); the settings text against v9's recorded sha256
#   prefix, v9 or not v9 (§N.1); OFFICE_LOCATION approved or not and its wa_answer's length, as the worker's officeLine()
#   reads it (W.3); StaffJobsGate::applies as the workers read it, false in capitals.
import re
from d107lib import git

UJ_HEAD = "# ── 5.18.106: the enquiry follow-ups, read before and after the copy (UJ; root docs/75 §L.4, §L.5) ──"
ME_HEAD = "# ── 5.18.106: master's errors from the cycle the copy straddled (ME-CA1; root docs/75 §L.5, DL10) ──"
UJ_FUNCS = {"uj_read", "uj_ok", "uj_gate_ok", "uj_was", "uj_gate_a", "uj_judgeable", "uj_out", "uj_places", "uj_notes",
            "uj_u4", "uj_stage", "uj_fe_g1", "uj_after", "uj_on"}
V9 = "fca936af7760"   # the settings text v9's sha256 prefix, recorded on the live install 26 Nov (root docs/76 §N.1)

# the pin's --check burst-drain lines SA parses (tools/sales_followup.php, sf_burst_drain), asserted from Git below
BD_LINES = [
    "sf_out('burst-drain: (a) ' . count($a) . ' mark(s) naming an event that has not run yet');",
    "sf_out('burst-drain: (a) counted apart: ' . count($apart) . ' — the named event was answered by a colleague, so it never runs');",
    "sf_out('burst-drain: (b) ' . count($b) . ' marked event(s) not yet settled');",
    "sf_out('burst-drain: control: ' . $control . ' typed message(s) in the last day');",
    "sf_out('burst-drain: INDETERMINATE — the events could not be read (' . get_class($e) . ')');",
    "if ((string)($pos[0] ?? '') === 'burst-drain') return sf_burst_drain($ro, $root, $config, $now);",
]

SA_MODE = r"""    case 'sa':     // sa ROOT DIR V9 — 5.18.107 (root docs/76 §T.5): stage A's notes on DIR's copy, opened read-only, through
                   // ROOT's code (the pin's, P3B_PIN's copy): wa_no_ai's rows (nai=absent on a first deploy, else active/all);
                   // the settings text bot_custom_instructions, trimmed, its sha256 prefix compared with V9 (the recorded v9,
                   // §N.1) — v9, other, empty or unset, never the text and never a hash — in the workers' view
                   // (PluginConfig::read, txt=) and the copy's store row (txtrow=); OFFICE_LOCATION as AiReplyWorker::
                   // officeLine() meets it, through ROOT's KnowledgeBase::load (approved rows only, the exact key, the first
                   // one) — ol=approved, not-approved, none or notable, olwa= its wa_answer's length, trimmed, never its
                   // content; StaffJobsGate::applies on the workers' view (ug=). Counts and fixed words only.
        [$root, $dir, $v9] = [(string)($a[0] ?? ''), rtrim((string)($a[1] ?? ''), '/'), (string)($a[2] ?? '')];
        @ini_set('error_log', '/dev/null');   // a plugin class's own error_log() line never reaches the live PHP log
        foreach (['bootstrap_data', 'timezone', 'UtcClock', 'PluginConfig', 'TenantProfile', 'StaffJobsGate', 'KnowledgeBase'] as $l) require_once $root . '/lib/' . $l . '.php';
        if (!preg_match('/^[0-9a-f]{12}$/', $v9)) { echo '?v9'; exit(2); }
        $p = $ro($dir . '/plugin.sqlite3');
        $o = ['sa=read'];
        try { $o[] = 'nai=' . ($has($p, 'wa_no_ai') ? (int)$one($p, 'SELECT COUNT(*) FROM wa_no_ai WHERE active = 1') . '/' . (int)$one($p, 'SELECT COUNT(*) FROM wa_no_ai') : 'absent'); }
        catch (Throwable $x) { $o[] = 'nai=?' . get_class($x); }
        $txt = function ($v) use ($v9): string {
            if ($v === null) return 'unset';
            if (!is_scalar($v)) return 'other';
            $t = trim((string)$v);
            if ($t === '') return 'empty';
            return substr(hash('sha256', $t), 0, 12) === $v9 ? 'v9' : 'other';
        };
        try { $c = PluginConfig::read($root, $dir); $o[] = 'txt=' . $txt($c['bot_custom_instructions'] ?? null); $o[] = 'ug=' . (StaffJobsGate::applies($c, $dir) ? 1 : 0); }
        catch (Throwable $x) { $o[] = 'txt=?' . get_class($x); $o[] = 'ug=?'; }
        try { $r = $has($p, 'kyc_config') ? $storeRow($p) : null; $o[] = 'txtrow=' . ($r === null ? 'norow' : $txt($r['bot_custom_instructions'] ?? null)); }
        catch (Throwable $x) { $o[] = 'txtrow=?' . get_class($x); }
        try {
            if (!$has($p, 'knowledge_items')) { $o[] = 'ol=notable'; $o[] = 'olwa=0'; }
            else {
                $ol = null;
                foreach (KnowledgeBase::load($p) as $rows) {
                    foreach ((array)$rows as $kr) if ($ol === null && (string)($kr['item_key'] ?? '') === 'OFFICE_LOCATION') $ol = $kr;
                }
                if ($ol !== null) { $o[] = 'ol=approved'; $o[] = 'olwa=' . mb_strlen(trim((string)($ol['wa_answer'] ?? ''))); }
                else { $o[] = 'ol=' . ((int)$one($p, "SELECT COUNT(*) FROM knowledge_items WHERE item_key = 'OFFICE_LOCATION'") > 0 ? 'not-approved' : 'none'); $o[] = 'olwa=0'; }
            }
        } catch (Throwable $x) { $o[] = 'ol=?' . get_class($x); $o[] = 'olwa=0'; }
        echo implode(' ', $o);
        break;

"""

SA_BLOCK = r"""# ── 5.18.107: stage A's notes (SA; root docs/76 §T.5) ───────────────────────────────────────────────────────────────────
# Read-only, at stage A (a deploy and --check), on QF-G1's copy through the PIN's code (P3B_PIN); notes only — counts and
# fixed words, never a refusal (DL2). 5.18.106's readings before and after the copy retired with F1–F5, 5.18.106's own (F3 is
# proven on production, root docs/75 §S). What SA notes:
#   - wa_no_ai: absent on a first deploy (092 makes it, empty, at the first boot after the copy); on a redeploy, its rows;
#   - the typed ai.reply events that carry a burst mark and still wait — the pin's own --check burst-drain on the copy
#     (expected 0 on a first deploy; on a redeploy, what an earlier 5.18.107 left): its counts only — the conversation and
#     event ids the tool lists are read here and never logged. The deploy run keeps the counts in its state file (BD_A, in
#     the backup block), where V1 reads them (§T.7); --check stops before that block and writes nothing;
#   - the settings text: bot_custom_instructions, trimmed, its sha256 prefix against v9's recorded one (SA_TXT_V9, §N.1) —
#     v9 or not v9, in the workers' view and the store row, never the text and never a hash. §K step 2 saves v10 only
#     after the deploy;
#   - OFFICE_LOCATION as the worker's officeLine() meets it: approved or not, and its wa_answer's length, never its
#     content. W.3 appends it to the fixed hand-over from the first hand-over after the copy;
#   - StaffJobsGate::applies as the workers read it (PluginConfig::read), the gate every 5.18.107 part needs — false is a
#     note in capitals, as 5.18.106's U4 said it.
SA_TXT_V9="__V9__"; BD_A=""
# sa_bd SUB [ids] — the pin's --check burst-drain on SUB's copy (qf_tool): "bd=read a=N apart=N b=N control=N", or
# "bd=unread(<why>)" and status 1. Its lines are matched whole, each once. The id lines beneath them leave here only with
# "ids": " convs=<the conversation ids the reading lists, sorted, comma-separated>" and " events=<the event ids behind
# them, each marked event and each event it waits for, the same way>" ("-" for none) — rb_pre107's two readings and
# rb107's, which print them and hand them to staff (root docs/76 §T.7 step 2); stage A never asks for them.
sa_bd() {
  local o rc a ap b c v cv ev
  o="$(qf_tool "$1" --check burst-drain)"; rc=$?
  [ "$rc" -eq 0 ] || { printf 'bd=unread(exit-%s)' "$rc"; return 1; }
  if grep -q '^burst-drain: INDETERMINATE — the events could not be read' <<<"$o"; then printf 'bd=unread(events)'; return 1; fi
  a="$(sed -n 's/^burst-drain: (a) \([0-9][0-9]*\) mark(s) naming an event that has not run yet$/\1/p' <<<"$o")"
  ap="$(sed -n 's/^burst-drain: (a) counted apart: \([0-9][0-9]*\) — the named event was answered by a colleague, so it never runs$/\1/p' <<<"$o")"
  b="$(sed -n 's/^burst-drain: (b) \([0-9][0-9]*\) marked event(s) not yet settled$/\1/p' <<<"$o")"
  c="$(sed -n 's/^burst-drain: control: \([0-9][0-9]*\) typed message(s) in the last day$/\1/p' <<<"$o")"
  for v in "$a" "$ap" "$b" "$c"; do case "$v" in ''|*[!0-9]*) printf 'bd=unread(lines)'; return 1 ;; esac; done
  printf 'bd=read a=%s apart=%s b=%s control=%s' "$a" "$ap" "$b" "$c"
  [ "${2:-}" = "ids" ] || return 0
  cv="$(sed -n 's/^  conv \([0-9][0-9]*\): .*/\1/p' <<<"$o" | sort -un | paste -sd, -)"
  printf ' convs=%s' "${cv:--}"
  ev="$(sed -n 's/^  conv [0-9][0-9]*: #\([0-9][0-9]*\)\( waits for #\([0-9][0-9]*\)\)\{0,1\}$/\1 \3/p' <<<"$o" | tr ' ' '\n' | grep -v '^$' | sort -un | paste -sd, -)"
  printf ' events=%s' "${ev:--}"
}
# sa_stage SUB — stage A (a deploy and --check), on QF-G1's copy (SUB) through the pin's code: SA's notes; BD_A the drain's
# counts (or its unread reason) for the state file. Never stops.
sa_stage() {
  local o s r first="" bd a b c ap
  [ "${MG_G1:-}" != "absent" ] || first=1
  o="$(qf_php sa "$1/code" "$1" "$SA_TXT_V9")"
  case "$o" in
    "sa=read "*)
      s="$(kv "$o" nai)"
      case "$s" in
        absent) note "SA wa_no_ai is absent on the copy — a first deploy: 092 makes it, empty, at the first boot after the copy" ;;
        [0-9]*/[0-9]*) note "SA wa_no_ai holds ${s%%/*} active row(s), ${s#*/} in all, on the copy — kept as they are: a redeploy carries an earlier $EXPECTED_VERSION's list" ;;
        *) note "SA wa_no_ai could not be read on the copy ($s) — NOT ESTABLISHED" ;;
      esac
      s="$(kv "$o" txt)"; r="$(kv "$o" txtrow)"
      note "SA the settings text (bot_custom_instructions, trimmed; never the text, never a hash): $( case "$s" in v9) echo "v9 as the workers read it — its sha256 prefix is the recorded $SA_TXT_V9 (root docs/76 §N.1)" ;; other) echo "NOT v9 as the workers read it — its sha256 prefix is not the recorded $SA_TXT_V9 (root docs/76 §N.1)" ;; empty) echo "empty as the workers read it — not v9" ;; unset) echo "not set as the workers read it — not v9" ;; *) echo "not read ($s) — NOT ESTABLISHED" ;; esac ); the store row: $( case "$r" in v9) echo "v9" ;; other) echo "not v9" ;; empty) echo "empty" ;; unset) echo "not set" ;; norow) echo "no row" ;; *) echo "not read ($r)" ;; esac ). §K step 2 saves v10 only after the deploy, and only over v9"
      s="$(kv "$o" ol)"
      case "$s" in
        approved) if [ "$(kv "$o" olwa)" = "0" ]; then note "SA OFFICE_LOCATION is approved, but its wa_answer is empty — W.3 appends no office line while it is"
                  else note "SA OFFICE_LOCATION is approved, its wa_answer $(kv "$o" olwa) character(s), never its content — W.3 appends it to the fixed hand-over from the first hand-over after the copy (cut at 400)"; fi ;;
        not-approved) note "SA OFFICE_LOCATION is not approved — W.3 appends no office line until it is" ;;
        none) note "SA no OFFICE_LOCATION row in the knowledge base — W.3 appends no office line" ;;
        notable) note "SA the knowledge base has no table on the copy — W.3 appends no office line" ;;
        *) note "SA OFFICE_LOCATION could not be read on the copy ($s) — NOT ESTABLISHED" ;;
      esac
      case "$(kv "$o" ug)" in
        1) note "SA StaffJobsGate::applies reads Uganda as the workers read it (PluginConfig::read) — the gate every $EXPECTED_VERSION part needs" ;;
        0) note "SA STAFFJOBSGATE::APPLIES IS FALSE ON THIS BOOK'S SETTINGS, AS THE WORKERS READ THEM — EVERY $EXPECTED_VERSION PART IS GATED ON IT: NONE OF THEM ACTS ON THIS SERVER WHILE IT READS FALSE" ;;
        *) note "SA StaffJobsGate::applies could not be read on the copy — NOT ESTABLISHED" ;;
      esac ;;
    *) note "SA wa_no_ai, the settings text, OFFICE_LOCATION and StaffJobsGate could not be read on the copy ($(printf '%s' "${o:-no answer}" | tr -c 'A-Za-z0-9_=:.,?-' '_' | cut -c1-80)) — NOT ESTABLISHED" ;;
  esac
  if bd="$(sa_bd "$1")"; then
    BD_A="$bd"; a="$(kv "$bd" a)"; ap="$(kv "$bd" apart)"; b="$(kv "$bd" b)"; c="$(kv "$bd" control)"
    if [ "$c" = "0" ]; then note "SA burst marks still waiting (the pin's --check burst-drain on the copy): (a) $a, counted apart $ap, (b) $b — INDETERMINATE: no typed message in the last day, so this reading cannot tell nothing waiting from a read that saw nothing"
    elif [ "$a" = "0" ] && [ "$b" = "0" ]; then note "SA burst marks still waiting (the pin's --check burst-drain on the copy): none — (a) 0, counted apart $ap, (b) 0, the control $c typed message(s) in the last day$( [ -n "$first" ] && echo " — as a first deploy expects: only $EXPECTED_VERSION writes the mark" || echo " — nothing an earlier $EXPECTED_VERSION left")"
    else note "SA burst marks still waiting (the pin's --check burst-drain on the copy): (a) $a, counted apart $ap, (b) $b, the control $c typed message(s) in the last day — $( [ -n "$first" ] && echo "UNEXPECTED ON A FIRST DEPLOY (only $EXPECTED_VERSION writes the mark)" || echo "what an earlier $EXPECTED_VERSION left")$( [ "$MODE" = "deploy" ] && [ -z "$CHECK_ONLY" ] && echo "; kept in the state file for a rollback to say (V1)"). Counts only: the conversation ids are not logged"; fi
  else BD_A="$bd"; note "SA burst marks still waiting could not be read on the copy (${bd#bd=}) — NOT ESTABLISHED$( [ "$MODE" = "deploy" ] && [ -z "$CHECK_ONLY" ] && echo "; a rollback says so (V1)")"; fi
  return 0
}
"""


def apply(doc, ctx):
    repo, pin, plugin = ctx["repo"], ctx["pin_full"], ctx["plugin"]
    tool = git(repo, "show", f"{pin}:{plugin}/tools/sales_followup.php").decode()
    for ln in BD_LINES:
        if tool.count(ln) != 1:
            raise SystemExit(f"partSA: the pin's tools/sales_followup.php does not carry, once: {ln[:100]!r}")
    ladder = git(repo, "show", f"{pin}:{plugin}/lib/SalesFollowupLadder.php").decode()
    pre = ladder[ladder.index("const PREEXISTING = ["):]
    pre = pre[:pre.index("];")]
    if "'wa_human_cooldown_minutes'" not in pre:
        raise SystemExit("partSA: wa_human_cooldown_minutes is not in the pin's SalesFollowupLadder::PREEXISTING — §T.7's "
                         "keep-list addition would then be needed")
    worker = git(repo, "show", f"{pin}:{plugin}/workers/AiReplyWorker.php").decode()
    for s in ["foreach (\\KnowledgeBase::load($this->pdo) as $rows) {",
              "if ((string)($r['item_key'] ?? '') !== 'OFFICE_LOCATION') continue;",
              "$w = trim((string)($r['wa_answer'] ?? ''));",
              "return $w === '' ? '' : mb_substr($w, 0, 400);"]:
        if worker.count(s) != 1:
            raise SystemExit(f"partSA: the pin's AiReplyWorker::officeLine() is not as SA reads it: {s!r}")
    kb = git(repo, "show", f"{pin}:{plugin}/lib/KnowledgeBase.php").decode()
    if "WHERE status='approved' ORDER BY kind, id" not in kb or "public static function load(\\PDO $pdo): array" not in kb:
        raise SystemExit("partSA: the pin's KnowledgeBase::load is not as SA reads it")
    for f in ["lib/StaffJobsGate.php", "lib/KnowledgeBase.php", "lib/PluginConfig.php", "lib/TenantProfile.php",
              "lib/bootstrap_data.php", "lib/timezone.php", "lib/UtcClock.php"]:
        git(repo, "cat-file", "-e", f"{pin}:{plugin}/{f}")
    if "public static function read(string $pluginRoot, string $dataDir): array" not in \
            git(repo, "show", f"{pin}:{plugin}/lib/PluginConfig.php").decode():
        raise SystemExit("partSA: the pin's PluginConfig::read is not read(string, string): array")

    # ── the UJ block: every function in it is a UJ one, then it goes; SA's block takes its place ──
    i = doc.t.index(UJ_HEAD); j = doc.t.index(ME_HEAD)
    if doc.t.count(UJ_HEAD) != 1 or doc.t.count(ME_HEAD) != 1 or not i < j:
        raise SystemExit("partSA: the UJ block's two headers are not each once, in order")
    funcs = set(re.findall(r"^([a-z_0-9]+)\(\) \{", doc.t[i:j], re.M))
    if funcs != UJ_FUNCS:
        raise SystemExit(f"partSA: the UJ block defines {sorted(funcs)}, not the fourteen UJ functions")
    doc.between(UJ_HEAD, ME_HEAD, SA_BLOCK.replace("__V9__", V9) + ME_HEAD, keep_ends=False, why="UJ block → SA (§T.5)")

    # ── the uj mode → the sa mode ──
    a = "    case 'uj':     // uj ROOT DIR [COPYUNIX [AUNIX]] — 5.18.106"
    b = "    case 'rbn':    // rbn PDD — RB106's counts after the put-back"
    i = doc.t.index(a); j = doc.t.index(b)
    if doc.t.count(a) != 1 or doc.t.count(b) != 1 or not i < j:
        raise SystemExit("partSA: the uj mode's bounds are not each once, in order")
    if [m for m in re.findall(r"^    case '([a-z0-9]+)':", doc.t[i:j], re.M)] != ["uj"]:
        raise SystemExit("partSA: the uj mode's slice holds another mode")
    if not doc.t[i:j].endswith("        echo implode(' ', $f);\n        break;\n\n"):
        raise SystemExit("partSA: the uj mode does not end as expected")
    doc.between(a, b, SA_MODE + b, keep_ends=False, why="uj mode → sa mode (§T.5)")
    doc.sub("'on3', 'lon', 'cfgkey', 'uj', 'na'], true)) putenv('DN_VAULT_FILE=",
            "'on3', 'lon', 'cfgkey', 'sa', 'na'], true)) putenv('DN_VAULT_FILE=", why="sa reads the copy's vault (uj retired)")
    doc.sub("// 5.18.106 (root docs/75 §L.4; review UJ-1): uj names DIR as the data folder too, so dn_tz() — the sending\n"
            "// window's zone — reads the copy's own kyc_config.json, as the live sender reads its own; never a live folder.\n"
            "if ($mode === 'uj') putenv('DN_DATA_DIR=' . rtrim((string)($a[1] ?? ''), '/'));\n", "",
            why="the uj mode's data folder goes with it")

    # ── the copies' keep list: UJ's three keys out, SA's settings text in ──
    doc.sub("               // 5.18.106 (root docs/75 §L.4): UJ's readings — F2's and F3's age limit, the run's and the scan's places\n"
            "               'followup_max_age_hours', 'followup_run_limit', 'followup_scan_limit'];",
            "               // 5.18.107 (root docs/76 §T.5): SA's settings text, compared on the copy with v9's recorded prefix and\n"
            "               // never printed (5.18.106's three UJ keys went with U1–U5). The drain's wa_human_cooldown_minutes\n"
            "               // (§T.7) is a ladder key already (SalesFollowupLadder::PREEXISTING), so every copy carries it\n"
            "               'bot_custom_instructions'];", why="conf keep list (§T.5, §T.7)")

    # ── stage A: SA in place of U1–U4 and FE-G1 (as_u5 is partR's) ──
    doc.sub('  uj_stage "$sub"   # U1–U4 — 5.18.106 (root docs/75 §L.4): on this copy, through the pin\'s code; notes; the deploy run keeps the line (UJ_A)\n'
            "  uj_fe_g1   # FE-G1 — 5.18.106 (DL11): U4 read followup_enabled off — NO-GO, nothing changed; not read — no refusal (DL2)\n",
            '  sa_stage "$sub"   # SA — 5.18.107 (root docs/76 §T.5): stage A\'s notes on this copy, through the pin\'s code; the deploy run keeps the drain\'s counts (BD_A)\n',
            why="qf_stage: SA in place of U1–U4 and FE-G1")
    doc.sub("# qf_stage — QF-G1–QF-G3, LK-G1, CS-G1, QF-N1–QF-N7, U1–U5 and FE-G1 (CS-G1, U1–U5 and FE-G1 since 5.18.106, root\n"
            "# docs/75 §L.3, §L.4, DL11), stage A, before anything changes (a deploy and --check): on copies,\n",
            "# qf_stage — QF-G1–QF-G3, MG-G1, LK-G1, NS-G1, QF-N1–QF-N7 and SA (MG-G1, NS-G1 and SA since 5.18.107, root docs/76\n"
            "# §T.3–§T.5), stage A, before anything changes (a deploy and --check): on copies,\n", why="qf_stage's comment")
    doc.sub("  # every part's keys, retired since 5.18.105: LK-G1 reads them as found); then the notes. 5.18.106 (root docs/75 §L.3,\n"
            "  # §L.4, DL11): CS-G1, the three claim switches absent, a refusal; U1–U5, the readings before the deploy, notes;\n"
            "  # FE-G1, their one refusal: U4 read followup_enabled off. On copies,\n",
            "  # every part's keys, retired since 5.18.105: LK-G1 reads them as found); then the notes. 5.18.107 (root docs/76\n"
            "  # §T.3–§T.5): MG-G1 and NS-G1, refusals; SA, stage A's notes — wa_no_ai, the burst marks still waiting, the\n"
            "  # settings text, OFFICE_LOCATION and StaffJobsGate — never a refusal (5.18.106's claim-switch checks and readings retired). On copies,\n",
            why="stage A's comment")
    doc.sub("kept live by 5.18.105's LK; 5.18.106's CS-G1, U1–U5 and FE-G1; read-only, on copies through the pin's code)\"",
            "kept live by 5.18.105's LK; 5.18.107's MG-G1, NS-G1 and SA; read-only, on copies through the pin's code)\"",
            why="stage A's heading")

    # ── the state file: BD_A in place of UJ_A ──
    doc.sub("  # 5.18.106 (root docs/75 §L.4): stage A's UJ reading (U1–U4 — counts, UTC times and generic channel labels, never a\n"
            "  # phone, a message, an instance name or an event's detail), for UJ-CA and UJ-ON of a later --after-only (uj_after).\n"
            "  # --check never reaches this block: it writes nothing.\n"
            "  [ \"$MODE\" = \"deploy\" ] && printf 'UJ_A=%s\\n' \"$(printf '%s' \"${UJ_A:-}\" | tr -d '\\n')\" >> \"$STATE_W\"\n",
            "  # 5.18.107 (root docs/76 §T.5, §T.7): stage A's drain counts (BD_A — the pin's --check burst-drain on QF-G1's copy,\n"
            "  # counts only, never a conversation or an event id, or its unread reason), which V1 reads from here. --check never\n"
            "  # reaches this block: it writes nothing.\n"
            "  [ \"$MODE\" = \"deploy\" ] && printf 'BD_A=%s\\n' \"$(printf '%s' \"${BD_A:-}\" | tr -d '\\n')\" >> \"$STATE_W\"\n",
            why="state file: BD_A (§T.5)")

    # ── stage R: UJ-CA and UJ-ON retire (§T.6) ──
    doc.lines_between("# UJ-CA and UJ-ON — 5.18.106 (root docs/75 §L.5), after the copy: U1–U3 again on a fresh copy through the pin's code,",
                      "uj_after   # UJ-CA (notes) and, in --after-only, UJ-ON (judged)", "", why="stage R: UJ-CA and UJ-ON retire")

    # ── results ──
    doc.must("sa_stage() {", 1); doc.must('sa_stage "$sub"', 1); doc.must("sa_bd() {", 1); doc.must('if bd="$(sa_bd "$1")"; then', 1)
    doc.must('o="$(qf_php sa "$1/code" "$1" "$SA_TXT_V9")"', 1); doc.must(f'SA_TXT_V9="{V9}"; BD_A=""', 1)
    doc.must("    case 'sa':     // sa ROOT DIR V9", 1); doc.must("printf 'BD_A=%s\\n'", 1)
    doc.mustnot("case 'uj'"); doc.mustnot("'uj'"); doc.mustnot("qf_php uj"); doc.mustnot("uj=read"); doc.mustnot("uj=unread")
    doc.mustnot("followup_max_age_hours'"); doc.mustnot("'followup_scan_limit'"); doc.mustnot("'followup_run_limit'")
    for f in sorted(UJ_FUNCS):
        doc.mustnot(f + "() {", why=f"{f} is no longer defined")
    # what partME and partH take: every UJ name left is in the header's comment or in the summary — its "check it" lines
    # (uj_judgeable, uj_gate_a, UJ_A, UJ_ON_GATE) and its NOT YET state (UJ_NY_SAY, UJ_NY_AT) — and nowhere else
    lines = doc.t.split("\n")
    head_end = next(k for k, l in enumerate(lines) if k > 0 and l and not l.startswith("#"))
    summ = [k for k, l in enumerate(lines) if l.startswith('  echo "  check it          --after-only, three runs,')]
    if len(summ) != 1:
        raise SystemExit("partSA: the summary's \"check it\" line is not there once")
    names, stray = set(), []
    for k, l in enumerate(lines):
        for m in re.findall(r"\buj_[a-z_]+|\bUJ_[A-Z_]+", l):
            names.add(m)
            if not (k < head_end or k > summ[0]):
                stray.append(f"{k + 1}: {m}")
    if stray:
        raise SystemExit(f"partSA: UJ names left outside the header and the summary: {stray[:6]}")
    if sorted(names) != ["UJ_A", "UJ_NY_AT", "UJ_NY_SAY", "UJ_ON_GATE", "uj_gate_a", "uj_judgeable"]:
        raise SystemExit(f"partSA: the UJ names left are {sorted(names)}, not the six partME and partH take")
