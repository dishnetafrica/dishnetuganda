# Part C — the carried texts DS5 corrects (root docs/73 §H.2.15, §H.2.16; coordinator item 20). Stage DS5. Registered after
# partP and before partZ, it makes one asserted edit per confirmed defect — or one per distinct text where several
# confirmed lines share it, its count the number of lines — and nothing else: the texts 5.18.103's script carried for the
# 5.18.102 → 5.18.103 step, which under EXPECTED_VERSION=5.18.104 and BASELINE_VERSION=5.18.103 name the wrong release
# (fixed releases are written as literals, never as EXPECTED_VERSION or BASELINE_VERSION, and each literal is read off Git
# here first: 221c9dc's manifest says 5.18.103, its parent's 5.18.102, the pin's 5.18.104); the mechanism defects of the
# same kind, each changed only to the verdict its verifiers named (QC-N1's capitals and QC-ON1–QC-ON3's FAIL for a switch
# an admin left off become notes; the summary's --check flags and migrations follow what this run did); and the
# rehearsal's four confirmed script findings, each the smallest change to its mechanism that keeps every other guarantee:
#   S1 qf_copy runs in $( … ), so the QF_ROOT_ERR it set was lost to its eleven callers: qf_copy_in is the body, and
#      qf_copy prints the reason on a failure, which each caller says as ${sub:-?};
#   S2 V3g failed on the keys the sales follow-up's switch tool clears by design: a key REMOVED that is one of the pin's
#      SalesFollowupLadder::allKeys(), where this run ran that tool (QF-G4's --clear-switches in a deploy, QF-RB1 in a
#      rollback), is named in a note; every other difference still FAILs;
#   S3 V1's automatic rollback skipped QF-RB3: it runs once the put-back verified, before the stop;
#   S4 QF-RB1's FAILED stop put prose after the tool's command on its line: the command alone, then a prose line, then
#      the rollback command alone, last.
# Every fact a new text states about the release is read off Git and asserted here: the delta's counts (partA's, from
# Git), the catalogue's files byte for byte at 1a0ea5f (5.18.102), 221c9dc and the pin, webhook.php's 18 added lines in
# whQuotationEmail only, public.php's tab tables, the brain's voice-only hunks, the cache shape, the test files named.
# DS5-fix1 (the review's R01–R04): RB's closing note and the rollback summary's "what it means" say QF-RB1 ran (and cite
# QF-RB1/QF-RB2) only where QF_RB1_START says this run ran it; --after-only's screens name the OpenAI key field (the pin's
# StaffJobsGate::applies, read off Git); A3's note, R4's ok, RB's master ok and the summary's "what did not" name 5.18.104
# as a literal; R23's banner comment is one asserted sub of its whole line (tools/cash_in_hand.php kept, read off Git).
import re
from d104lib import git, sha_at

V102, V103, V104 = "5.18.102", "5.18.103", "5.18.104"


def H(*lines, width=124):
    """Whole lines; a comment line written here is at most `width` characters."""
    for l in lines:
        if l.startswith("#") and len(l) > width:
            raise SystemExit(f"C: a comment line is {len(l)} characters (at most {width}): {l[:90]!r}")
    return "\n".join(lines)


def apply(doc, ctx):
    repo, plugin, base, pin = ctx["repo"], ctx["plugin"], ctx["base_full"], ctx["pin_full"]
    show = lambda c, p: git(repo, "show", f"{c}:{plugin}/{p}").decode()

    # ── the literals, from Git ──
    parent = git(repo, "rev-parse", f"{base}^1").decode().strip()
    for c, v in [(base, V103), (parent, V102), (pin, V104)]:
        m = re.search(r'^    "version": "([0-9.]+)",$', show(c, "manifest.json"), re.M)
        if not m or m.group(1) != v:
            raise SystemExit(f"C: manifest.json at {c[:7]} says {m.group(1) if m else '?'}, expected {v}")
    if parent[:7] != "1a0ea5f" or base[:7] != "221c9dc":
        raise SystemExit(f"C: 5.18.103's release commit or its parent is not the one the texts name ({base[:7]}, {parent[:7]})")
    n_add, n_chg, n_kept = ctx["n_added"], ctx["n_changed"], ctx["n_kept"]
    if (n_add, n_chg, n_kept) != (215, 45, 158):
        raise SystemExit(f"C: the delta is {n_add} added, {n_chg} changed, {n_kept} kept — the texts below say 215, 45, 158")
    doc.must("PREV_KEPT_N=158", 1, why="the kept set the texts count")
    ns = git(repo, "diff", "--name-status", "--no-renames", base, pin, "--", plugin).decode().splitlines()
    chg = {l.split("\t", 1)[1][len(plugin) + 1:] for l in ns if l.startswith("M\t")}
    # the catalogue and the files the web-direct applications are handled by: byte for byte at 221c9dc and the pin
    same = ["lib/UcrmCatalogue.php", "tabs/sales/ucrm_catalogue.php", "lib/UcrmQuoteLines.php", "lib/UcrmQuoteLinks.php",
            "lib/QuoteOrganization.php", "lib/KycService.php", "lib/KycCrmSync.php", "includes/post/post_kyc.php",
            "includes/post/post_sync.php", "lib/InboxReplyRoute.php", "tabs/sales/kyc_form.php", "tabs/sales/send_quote.php",
            "tabs/sales/applications.php", "lib/MigrationRunner.php", "includes/api/api_cron_debug.php",
            "includes/api/api_products_admin.php", "lib/UcrmClientTarget.php", "lib/UcrmLeadSync.php", "lib/PluginQuotePdf.php",
            "profiles/uganda.json", "tools/set_config.php", "tests/fixtures/fake_smtp_server.php"]
    for f in same:
        if f in chg or sha_at(repo, base, f"{plugin}/{f}") != sha_at(repo, pin, f"{plugin}/{f}"):
            raise SystemExit(f"C: {f} is not byte for byte the same at 5.18.103 and the pin")
        kept_block = doc.t[doc.t.index("PREV_KEPT_SHA256S="):doc.t.index("PREV_KEPT_N=158")]
        if not re.search(r"(?m)^[0-9a-f]{64}  " + re.escape(f) + r"$", kept_block):
            raise SystemExit(f"C: {f} is not in the script's PREV_KEPT_SHA256S")
    if sha_at(repo, parent, f"{plugin}/lib/UcrmQuoteLinks.php") != sha_at(repo, pin, f"{plugin}/lib/UcrmQuoteLinks.php"):
        raise SystemExit("C: lib/UcrmQuoteLinks.php is not the same file at 5.18.102, 5.18.103 and the pin")
    for f in ["webhook.php", "public.php", "includes/navigation.php", "includes/post_handlers.php", "lib/QuotationService.php",
              "lib/DishNetAiBrain.php", "lib/BrainContext.php", "cron/master.php", "tabs/engage/whatsapp.php"]:
        if f not in chg:
            raise SystemExit(f"C: {f} is not among the files 5.18.104 changes")
    # webhook.php: P7's 18 added lines, both hunks in whQuotationEmail, none the sign-off, its phrase or its gate
    if git(repo, "diff", "--numstat", base, pin, "--", f"{plugin}/webhook.php").decode().split()[:2] != ["18", "0"]:
        raise SystemExit("C: webhook.php's delta is not 18 lines added and none removed")
    wd = git(repo, "diff", "-U0", base, pin, "--", f"{plugin}/webhook.php").decode()
    heads = re.findall(r"^@@ [^@]* @@ (.*)$", wd, re.M)
    added = [l for l in wd.splitlines() if l.startswith("+") and not l.startswith("+++")]
    if len(heads) != 2 or any("function whQuotationEmail(" not in h for h in heads) or len(added) != 18 \
            or any(w in l for l in added for w in ("DishNet Internet Services", "ugPlans", "whQuoteIsUganda", "DishNet Uganda —")):
        raise SystemExit("C: webhook.php's two hunks are not P7's alone, in whQuotationEmail")
    # public.php: the tab tables alone (My quotes, the offers screen); the brain: a voice-note turn alone
    pd = git(repo, "diff", "-U0", base, pin, "--", f"{plugin}/public.php").decode()
    if not all(("$_tabFiles = [" in h or "$_tabPerms = [" in h or "if (isset($_tabFiles[$tab]))" in h)
               for h in re.findall(r"^@@ [^@]* @@ (.*)$", pd, re.M)) or "my_quotes" not in pd or "wa_offers" not in pd:
        raise SystemExit("C: public.php's delta is not its tab tables alone")
    bd = git(repo, "diff", "-U0", base, pin, "--", f"{plugin}/lib/DishNetAiBrain.php").decode()
    if [l for l in bd.splitlines() if l.startswith("-") and not l.startswith("---")] or "VOICE MESSAGE JUST RECEIVED" not in bd \
            or "if (is_array($voice)) {" not in bd:
        raise SystemExit("C: lib/DishNetAiBrain.php's delta is not the voice-note block alone")
    if git(repo, "diff", "--numstat", base, pin, "--", f"{plugin}/lib/BrainContext.php").decode().split()[:2] != ["13", "1"]:
        raise SystemExit("C: lib/BrainContext.php's delta is not the voice key alone")
    shape = "public const CACHE_SHAPE  = 'd103-1';"
    if shape not in show(base, "lib/UcrmQuoteLines.php") or shape not in show(pin, "lib/UcrmQuoteLines.php"):
        raise SystemExit("C: UcrmQuoteLines::CACHE_SHAPE is not d103-1 at both commits")
    tests = ["tests/test_quote_followup.php", "tests/test_wa_send_budget.php", "tests/test_sales_followup_tool.php",
             "tests/test_master_104.php", "tests/test_sales_followup_report.php", "tests/test_wa_offers.php",
             "tests/test_quote_agent_copy.php", "tests/test_voice_ack.php", "tests/quote_agent_copy/drive.php",
             "tests/fixtures/fake_smtp_server.php"]
    for t in tests:
        git(repo, "cat-file", "-e", f"{pin}:{plugin}/{t}")
    lad = show(pin, "lib/SalesFollowupLadder.php")
    for k in ["'sf_persona_confirmed'", "'quote_agent_cc_admins'", "public static function allKeys(): array"]:
        if k not in lad:
            raise SystemExit(f"C: the pin's ladder has no {k}")
    for n, v in [(2, V102), (3, "5.18.101"), (4, "5.18.100"), (5, "5.18.99"), (6, "5.18.98"), (7, "5.18.97")]:
        doc.must(f'PREV{n}_VERSION="{v}"', 1, why="A14's state files, by release")
    doc.must('ROLLBACK_CMD="cd $REPO && bash scripts/deploy-$EXPECTED_VERSION.sh --rollback $(LOGW rollback)"', 1)

    rep = lambda old, new, n=1, why="": doc.sub(old, new, n, why)

    # ════════════════ S1 — qf_copy's reason, lost in its callers' $( … ) ════════════════
    rep('qf_copy() {\n  local kind="$1" src="$2" sub o size free keep\n',
        'qf_copy_in() {\n  local kind="$1" src="$2" sub o size free keep\n', why="S1: the body is qf_copy_in")
    rep('  echo "$sub"\n}\n# qf_tool SUB ARG…',
        '  echo "$sub"\n}\n'
        + H("# qf_copy — qf_copy_in, as its callers run it, in $( … ) (DS5, S1): the folder on success; on a failure the reason,",
            "# QF_ROOT_ERR as qf_copy_in set it, and status 1 — a variable set in $( … ) is lost to the caller, so each caller",
            "# says ${sub:-?}, never the variable.")
        + '\nqf_copy() { qf_copy_in "$@" || { printf \'%s\' "${QF_ROOT_ERR:-?}"; return 1; }; }\n# qf_tool SUB ARG…',
        why="S1: qf_copy prints the reason on a failure")
    calls = [l for l in doc.t.split("\n") if 'sub="$(qf_copy ' in l]
    if len(calls) != 11:
        raise SystemExit(f"C: S1 expects eleven callers of qf_copy, found {len(calls)}")
    for l in calls:
        if l.startswith('  sub="$(qf_copy ac1 '):
            continue
        if l.count("$QF_ROOT_ERR") != 1:
            raise SystemExit(f"C: S1: a caller does not say $QF_ROOT_ERR once: {l[:100]!r}")
        rep(l, l.replace("$QF_ROOT_ERR", "${sub:-?}"), 1, why="S1: the caller says the reason qf_copy printed")
    rep('  sub="$(qf_copy ac1 "$inst")" || sub=""\n'
        '  if [ -z "$sub" ]; then bad "AC-CA1 a copy of the book could not be made — $QF_ROOT_ERR; AC-CA1 is not judged"\n',
        '  sub="$(qf_copy ac1 "$inst")" || { w="${sub:-?}"; sub=""; }   # w: the reason qf_copy printed (DS5, S1)\n'
        '  if [ -z "$sub" ]; then bad "AC-CA1 a copy of the book could not be made — ${w:-?}; AC-CA1 is not judged"\n',
        why="S1: AC-CA1 says the reason before sub is cleared")
    doc.mustnot("$QF_ROOT_ERR", why="S1: no caller reads the variable its $( … ) lost")
    doc.must('qf_copy() { qf_copy_in "$@" || { printf \'%s\' "${QF_ROOT_ERR:-?}"; return 1; }; }\n', 1)
    doc.must('qf_copy_in "$@"', 1, why="S1: only the wrapper calls the body")
    doc.must("\nqf_copy_in() {\n", 1, why="S1: the body, defined once")
    if len(re.findall(r"\bqf_copy_in\b", doc.t)) != 4:
        raise SystemExit("C: S1: qf_copy_in is named by its definition, the wrapper and the wrapper's comment, nowhere else")
    if len([l for l in doc.t.split("\n") if 'sub="$(qf_copy ' in l and "${sub:-?}" in l]) != 11:
        raise SystemExit("C: S1: every caller reads ${sub:-?} on a failure (AC-CA1's into w first)")

    # ════════════════ S4 — QF-RB1's FAILED stop: each command alone on its line, the rollback last ════════════════
    rep(' Once the cause is cleared, the same tool again — its own command, alone:$(printf \'\\n      %s\' "cd $REPO && bash $QF_WRAPPER off all --rollback") — or the by-hand put-back above; then this --rollback again"\n',
        ' Once the cause is cleared, the same tool again — its own command, alone (or, instead, the by-hand put-back above):$(printf \'\\n      %s\' "cd $REPO && bash $QF_WRAPPER off all --rollback")$(printf \'\\n  %s\' "once it has exited 0, or the by-hand put-back is done, the rollback, its own command, alone:")$(printf \'\\n      %s\' "$ROLLBACK_CMD")"\n',
        why="S4: the tool's command alone, a prose line, the rollback command alone and last")
    doc.mustnot("then this --rollback again", why="S4")

    # ════════════════ S3 — QF-RB3 in V1's automatic rollback, once verified ════════════════
    rep('        note "$QCV1_SAY"\n        if [ "$QCV1_RB" = "ok" ]; then stop "rolled back (serves $(live_commit)); $QCV1_SAY; ',
        '        note "$QCV1_SAY"\n        if [ "$QCV1_RB" = "ok" ]; then qf_rb3   # QF-RB3 — the put-back read, as RB reads it after a --rollback (DS5, S3)\n'
        '          stop "rolled back (serves $(live_commit)); QF-RB3 above reads the put-back; $QCV1_SAY; ',
        why="S3: V1 runs QF-RB3 once the put-back verified")
    doc.must("\nqf_rb3\n", 1, why="QF-RB3 in RB, partR's call, unchanged")
    if len([l for l in doc.t.split("\n") if re.search(r"\bqf_rb3\b(?!\(\))", l.split("#")[0])]) != 2:
        raise SystemExit("C: S3: QF-RB3 is called in RB and in V1, nowhere else")

    # ════════════════ S2 — V3g: the switch tool's own keys, removed where this run ran it, a note; anything else a FAIL ════════════════
    rep('''    if [ "$AIS_AFTER" = "$AIS_A" ]; then ok "V3g S6 the AI and WhatsApp settings are unchanged by this run (${AIS_A%%|*}; files/store — values never printed)"
    else bad "V3g the AI or WhatsApp settings CHANGED across this run: $(ai_changed "$AIS_A" "$AIS_AFTER")— this run must never touch them"; fi ;;
''', H('''    if [ "$AIS_AFTER" = "$AIS_A" ]; then ok "V3g S6 the AI and WhatsApp settings are unchanged by this run (${AIS_A%%|*}; files/store — values never printed)"''',
       "    else",
       "      # 5.18.104 (DS5, S2): the sales follow-up's switch tool, where this run ran it — QF-G4's --clear-switches before the",
       "      # copy (a deploy), QF-RB1's off all --rollback (a rollback) — clears 5.18.104's own keys, Part V's ai_ keys among",
       "      # them. Only such a key REMOVED, one of the pin's SalesFollowupLadder::allKeys(), is that tool's: named in a note,",
       "      # never a value. A value changed, a key added, a key not the ladder's, or the tool not run this run: a FAIL.",
       '''      v3g_tool=""; v3g_own=""; v3g_bad=""''',
       '''      { [ "$MODE" = "rollback" ] && [ -n "${QF_RB1_START:-}" ]; } && v3g_tool="QF-RB1's off all --rollback"''',
       '''      { [ "$MODE" = "deploy" ] && [ -n "${QF_CLEAR:-}" ]; } && v3g_tool="QF-G4's --clear-switches"''',
       '''      v3g_all=" $( [ -n "$v3g_tool" ] && qf_php keys "${P3B_PIN:-$IN_CONTAINER}" 2>/dev/null | sed -nE 's/.* allnames=([^ ]*).*/\\1/p' | tr ',' ' ') "''',
       '''      for v3g_k in $(ai_changed "$AIS_A" "$AIS_AFTER"); do''',
       '''        if [ -n "$v3g_tool" ] && case "$v3g_all" in *" ${v3g_k#*.} "*) true ;; *) false ;; esac \\''',
       '''           && grep -qF "\\"$v3g_k\\":" <<<"${AIS_A#*|}" && ! grep -qF "\\"$v3g_k\\":" <<<"${AIS_AFTER#*|}"; then v3g_own="$v3g_own $v3g_k"''',
       '''        else v3g_bad="$v3g_bad $v3g_k"; fi''',
       "      done",
       '''      if [ -n "$v3g_bad" ] || [ -z "$v3g_own" ]; then bad "V3g the AI or WhatsApp settings CHANGED across this run:${v3g_bad:- (the two readings differ)} — this run changes none of them but $EXPECTED_VERSION's own keys, and only through QF-G4's --clear-switches or QF-RB1"''',
       '''      else ok "V3g S6 the AI and WhatsApp settings are unchanged by this run but for $EXPECTED_VERSION's own keys its switch tool removed (${AIS_AFTER%%|*}; files/store — values never printed)"''',
       '''        note "V3g $v3g_tool removed $EXPECTED_VERSION's own key(s), by name, nothing else among the AI and WhatsApp settings:$v3g_own — the switch tool's keys (SalesFollowupLadder::allKeys()), cleared by design; no value"; fi''',
       "    fi ;;") + "\n",
        why="S2: V3g excuses only the switch tool's removals of its own keys, where this run ran it")
    doc.mustnot("this run must never touch them\"; fi ;;\n  *) bad \"V3g", why="S2")

    # ════════════════ QC-ON1–QC-ON3: the switch as found — off or never set a note, never a FAIL (mechanism) ════════════════
    rep('''  x="$( [ -n "$ok_r" ] && kv "$QO_L" qcsw)"
  case "$x" in
    on) ok "QC-ON1 the uCRM catalogue is switched ON (the copy's record): the KYC form, Send Quotation and the lead quotes offer uCRM's plans and hardware on Uganda, and the plugin's Plans and Hardware are hidden" ;;
    *) bad "QC-ON1 the uCRM catalogue is not switched on (${x:-not read}) — turn it on on the uCRM catalogue screen (Uganda), as an admin, then run --after-only again; until then $EXPECTED_VERSION behaves as $BASELINE_VERSION (a run before it is on fails here by design)" ;;
  esac
''', '''  x="$( [ -n "$ok_r" ] && kv "$QO_L" qcsw)"
  if [ "$x" = "off" ] || [ "$x" = "none" ]; then
    note "QC-ON1–QC-ON3 not judged: the uCRM catalogue is switched off$( [ "$x" = "none" ] && echo " (never set)") on the copy — an admin's choice on the uCRM catalogue screen (docs/72 §G.3); the forms quote through the plugin's links, as 5.18.103 does with it off$( [ "${QCSW_A:-}" = "on" ] && echo "; it read on at the deploy's stage A")"
  else
  case "$x" in
    on) ok "QC-ON1 the uCRM catalogue is switched ON (the copy's record): the KYC form, Send Quotation and the lead quotes offer uCRM's plans and hardware on Uganda, and the plugin's Plans and Hardware are hidden" ;;
    *) bad "QC-ON1 the uCRM catalogue's switch reads neither on nor off on the copy (${x:-not read}: two records in force, which the release reads as off and never writes, or a reading that failed) — an admin sets it again on the uCRM catalogue screen (Uganda); then run --after-only again" ;;
  esac
''', why="QC-ON1: off or never set is one note, QC-ON2 and QC-ON3 not judged")
    rep('''    *) if [ "$n" = "$u" ]; then ok "QC-ON3 every standard quotation ($n) is usable now: its plan, hardware and installation are all offered"
       else bad "QC-ON3 $((n - u)) of $n standard quotation(s) cannot be used now — an item in it is no longer offered; the uCRM catalogue screen, and the sheet, say which"; fi ;;
  esac
  fi
''', '''    *) if [ "$n" = "$u" ]; then ok "QC-ON3 every standard quotation ($n) is usable now: its plan, hardware and installation are all offered"
       else bad "QC-ON3 $((n - u)) of $n standard quotation(s) cannot be used now — an item in it is no longer offered; the uCRM catalogue screen, and the sheet, say which"; fi ;;
  esac
  fi
  fi
''', why="QC-ON2 and QC-ON3 judged only where the switch is not off")
    rep('''    direct:*,bad:0) ok "QC-ON4 every web-direct application on the copy ($(subkv "$g" direct)) keeps G-7: package_choice and device_id empty, and only id 0 in hw_cart_json and in quote_sale_cart, if any — nothing 5.18.102 would match to a plugin row after a rollback" ;;''',
        '''    direct:*,bad:0) ok "QC-ON4 every web-direct application on the copy ($(subkv "$g" direct)) keeps G-7: package_choice and device_id empty, and only id 0 in hw_cart_json and in quote_sale_cart, if any — nothing 5.18.102 would match to a plugin row, were 5.18.103 itself ever rolled back (G-7, docs/72 §G.2)" ;;''',
        why="QC-ON4: the rollback 5.18.102 would follow is 5.18.103's own")
    doc.mustnot("a run before it is on fails here by design")
    doc.mustnot("until then $EXPECTED_VERSION behaves as $BASELINE_VERSION (a run")

    # ════════════════ the summary: what this run did (mechanism: --check's flags, the migrations on a redeploy) ════════════════
    rep('''# (G3_NOTED, QOFF_NOTED, QG5_NOTED, QG6_NOTED, and 5.18.102's QLG1_NOTED — docs/71 §Q, D-R13): where a check held
# without it, it is not needed, and the command below is printed without it.
''', H("# (G3_NOTED, QOFF_NOTED, QG5_NOTED, QG6_NOTED, and 5.18.102's QLG1_NOTED — docs/71 §Q, D-R13): where a check held",
       "# without it, it is not needed, and the command below is printed without it. 5.18.104 (DS5): --clear-switches too, where",
       "# QF-G4 found keys to clear (QF_CLEAR) — without it the deploy would refuse at QF-G4 — and said typed and not needed",
       "# where QF-G4 found none.") + "\n", why="--check's printed deploy carries --clear-switches where QF-G4 needs it")
    rep('''  [ -z "${QLG1_NOTED:-}" ] || F_FLAGS="$F_FLAGS --accept-unpriced"
''', '''  [ -z "${QLG1_NOTED:-}" ] || F_FLAGS="$F_FLAGS --accept-unpriced"
  [ -z "${QF_CLEAR:-}" ] || F_FLAGS="$F_FLAGS --clear-switches"
''', why="--check: --clear-switches kept where QF-G4 needs it")
    rep('''  { [ -z "$ACCEPT_UNPRICED" ] || [ -n "${QLG1_NOTED:-}" ]; } || F_TYPED="$F_TYPED --accept-unpriced"
''', '''  { [ -z "$ACCEPT_UNPRICED" ] || [ -n "${QLG1_NOTED:-}" ]; } || F_TYPED="$F_TYPED --accept-unpriced"
  { [ -z "$CLEAR_SWITCHES" ] || [ -n "${QF_CLEAR:-}" ]; } || F_TYPED="$F_TYPED --clear-switches"
''', why="--check: --clear-switches typed and not needed, said")
    rep('''  F_QLNS=""
''', '''  F_QLNS=""
  # 5.18.104 (DS5): QF-G1's reading — this run's, or in an --after-only run the deploy's, from its state file — decides
  # what the summary says of 089–091: added at the first boot (first), or already in the ledger (redeploy)
  F_QG1="${QF_G1:-}"; [ -n "$F_QG1" ] || [ "$MODE" != "after" ] || F_QG1="$(sed -n 's/^QF_G1=//p' "$STATE" 2>/dev/null | tail -n1)"
''', why="the summary reads QF-G1's first or redeploy")
    rep('Migrations 089, 090 and 091 applied at the first boot, each verifying itself (QF-CA1);',
        '$( [ "$F_QG1" = redeploy ] && echo "Migrations 089, 090 and 091 were already in the ledger (QF-G1: a redeploy) — none applied by this deploy, each read by QF-CA1" || echo "Migrations 089, 090 and 091 applied at the first boot, each verifying itself (QF-CA1)");',
        why="what changed: 089–091 as QF-G1 found them")
    rep("the database — nothing written by this run but 089, 090 and 091 at the first boot (QF-CA1), and on a redeploy with --clear-switches the keys QF-G4 cleared, by name on the sheet (R7, BA1)",
        'the database — nothing written by this run$( [ "$F_QG1" = redeploy ] || echo " but 089, 090 and 091 at the first boot (QF-CA1)"), and on a redeploy with --clear-switches the keys QF-G4 cleared, by name on the sheet (R7, BA1)',
        why="what did not: no migration on a redeploy")
    rep('  echo "  migrations        089, 090 and 091 added (A0, QF-G2, QF-CA1, R7): each applied once at the first boot, each verifying itself into _schema_checks, the ledger otherwise the same before and after; 088',
        '  echo "  migrations        089, 090 and 091 $( [ "$F_QG1" = redeploy ] && echo "already in the ledger, applied by an earlier $EXPECTED_VERSION (QF-G1: a redeploy; A0, QF-CA1, R7) — none applied or added by this deploy, the ledger the same before and after" || echo "added (A0, QF-G2, QF-CA1, R7): each applied once at the first boot, each verifying itself into _schema_checks, the ledger otherwise the same before and after"); 088',
        why="migrations: 089–091 as QF-G1 found them")
    rep("what a live plugin writes at use is the plugin's own ($EXPECTED_VERSION's keys on Uganda applications among it: R7, QC-RB2)",
        "what a live plugin writes at use is the plugin's own (5.18.103's keys on Uganda applications — the uCRM catalogue's web-direct records, which 5.18.104 carries unchanged and adds none to — among it: R7, QC-RB2)",
        why="what did not: the web-direct records are 5.18.103's")
    rep("every switch and AI setting (V3–V3j);",
        'every switch and AI setting (V3–V3j$( [ -n "${QF_CLEAR:-}" ] && echo ", but $EXPECTED_VERSION\'s own keys QF-G4\'s --clear-switches removed, each named by V3g"));',
        why="S2: the summary's V3g exception, where the clear ran")

    # ════════════════ db_judge: RB's clause says what this run wrote (QF-RB1), or that nothing did ════════════════
    rep('''what a live plugin writes at use is the plugin's own, and nothing in this run writes it but the switch tool's clear where --clear-switches was given (QF-G4)"
''', '''what a live plugin writes at use is the plugin's own, and $( if [ "$1" != "RB" ]; then echo "nothing in this run writes it but the switch tool's clear where --clear-switches was given (QF-G4)"; elif [ -n "${QF_RB1_START:-}" ]; then echo "this run wrote it only through QF-RB1, before the put-back: the installed tool's off all --rollback cleared $EXPECTED_VERSION's keys in the store row, and its hooks closed or paused rows; it applied no migration and removed none (QF-RB2)"; else echo "nothing in this run wrote it: the container already served $BASELINE_VERSION, so QF-RB1 did not run"; fi )"
''', why="db_judge: RB's own clause")

    # ════════════════ V1's automatic rollback: QC-RB2 a record; the catalogue 5.18.103's, untouched ════════════════
    rep('''      # 5.18.103 (docs/72 §G.10): the deploy's own automatic rollback — the public address must never redirect, and putting
      # $BASELINE_VERSION back is what ends it — then QC-RB2 counted after it on a consistent copy of the book, read-only
      # (qcrb_count; counts in the log, ids on the sheet): going back to $BASELINE_VERSION quotes nothing at price 0, so
      # nothing here refuses. do_rollback's own answer decides the words: verified, no command is printed; not verified
      # — the checkout, deploy-hybrid.sh, or a record that did not turn — $EXPECTED_VERSION may still serve, and the stop
      # says the rollback did not complete and prints the by-hand put-back, alone on its own line.
''', H("      # 5.18.103 (docs/72 §G.10), carried; 5.18.104 (root docs/73 §H.2.16): the deploy's own automatic rollback — the",
       "      # public address must never redirect, and putting 5.18.103 back is what ends it — QF-RB1 first (a failure stops",
       "      # before the put-back), then QC-RB2 counted after it on a consistent copy of the book, read-only (qcrb_count;",
       "      # counts in the log, ids on the sheet), a record only: 5.18.103 quotes a web-direct application exactly as 5.18.104",
       "      # does, and nothing at price 0, so nothing here refuses. do_rollback's own answer decides the words: verified,",
       "      # QF-RB3 reads the put-back and no command is printed; not verified — the checkout, deploy-hybrid.sh, or a record",
       "      # that did not turn — $EXPECTED_VERSION may still serve, and the stop says the rollback did not complete and prints",
       "      # the by-hand put-back, alone on its own line.") + "\n", why="V1's comment: QF-RB1 first, QF-RB3 once verified, QC-RB2 a record")
    rep('''then QCV1_SAY="QC-RB2 counted after it, on a copy of the book: $(qcrb_say "$QC_RB") — each with no quotation is quoted in uCRM by hand ($QC_RB_IDS)"''',
        '''then QCV1_SAY="QC-RB2 counted after it, on a copy of the book, for the record only: $(qcrb_say "$QC_RB") ($QC_RB_IDS) — 5.18.104 changes nothing of how a web-direct application is quoted, so whichever release now serves handles each the same way; none is quoted in uCRM by hand because of this rollback"''',
        why="QCV1_SAY: a record, true whether or not the put-back verified")
    rep('''; $QCV1_SAY; the uCRM catalogue's settings stay in the store, read by nothing under $BASELINE_VERSION; a later deploy of $EXPECTED_VERSION finds them as they were (docs/72 §G.10); send the log file"; fi''',
        '''; $QCV1_SAY; on Uganda the uCRM catalogue — its screen, its settings, its switch and the direct quoting — is 5.18.103's and stays exactly as it was: 5.18.104 changes none of it and its rollback touches none of it (root docs/73 §H.2.1, §H.2.16); send the log file"; fi''',
        why="V1 verified: the catalogue 5.18.103's own, untouched")

    # ════════════════ the put-back's hazard: none — it copies 5.18.103's files, the catalogue untouched ════════════════
    rep('''# 5.18.103 (docs/72 §G.10): the put-back counts nothing, and every message that prints it says what that leaves, first, in
# one sentence; the command still stands alone on its own line after it.
PUTBACK_HAZARD="The put-back below counts nothing before it copies: the web-direct applications $EXPECTED_VERSION made are then left unquoted under $BASELINE_VERSION (P1, or P2 where cash was collected) — an admin quotes each in uCRM by hand; nothing is quoted at price 0."
''', H("# 5.18.103 (docs/72 §G.10), carried; 5.18.104 (DS5): the put-back counts nothing, and every message that prints it says",
       "# what that leaves, first — under 5.18.103, nothing to quote by hand: the catalogue's files are byte for byte the same",
       "# in both releases, and 5.18.103 handles a web-direct application as 5.18.104 does. The command still stands alone on",
       "# its own line after it. Literal releases: the sentence is the same wherever it is printed.")
        + '''\nPUTBACK_HAZARD="The put-back below copies 5.18.103's files and counts nothing. It changes nothing of the uCRM catalogue (its screen, its settings and its switch stay as they are; 5.18.104 changed none of it) and nothing of the web-direct applications: 5.18.103 quotes each itself, by its stored uCRM ids, and one it refuses says why on its Orders card. Quote none of them in uCRM by hand because of this put-back."\n''',
        why="PUTBACK_HAZARD: true at every place that prints it")
    if '$' in doc.t[doc.t.index('PUTBACK_HAZARD="'):].split("\n", 1)[0]:
        raise SystemExit("C: PUTBACK_HAZARD carries a variable")
    doc.must("$PUTBACK_HAZARD", 7, why="the seven messages that print it")

    # ════════════════ the readings' comments: cih_run, the link judge, the catalogue's reader, QC-N4's sheet line ════════════════
    rep("# pin's (D2), P0; ECO Bank through the live code's classes (G3) and the pin's (BK2); the copy checks C1–C5, 5.18.102's\n"
        "# code and 5.18.103's from Git; and the notes N1–N4. r23: 5.18.102's code from Git (TODAY) and the installed (D2), P0, the\n"
        "# installed tool; ECO Bank through the installed code (BA2: BK2 and G3 again); the copy checks with 5.18.102's code from\n",
        "# pin's (D2), P0; ECO Bank through the live code's classes (G3) and the pin's (BK2); the copy checks C1–C5, 5.18.103's\n"
        "# code and 5.18.104's from Git; and the notes N1–N4. r23: 5.18.103's code from Git (TODAY) and the installed (D2), P0, the\n"
        "# installed tool; ECO Bank through the installed code (BA2: BK2 and G3 again); the copy checks with 5.18.103's code from\n",
        why="cih_run: the baseline 5.18.103, the pin 5.18.104")
    rep("the installed baseline's (5.18.102 here)", "the installed baseline's (5.18.103 here)", why="cih_run")
    rep("  # 5.18.102's and 5.18.103's code from Git, in R23 5.18.102's from Git and the installed.",
        "  # 5.18.103's and 5.18.104's code from Git, in R23 5.18.103's from Git and the installed.", why="cih_run")
    rep("        // copies (5.18.102's, whose own copy is the same file: 5.18.103 keeps it byte for byte); after the copy none (since\n",
        "        // copies (5.18.103's, whose own copy is the same file: 5.18.104 keeps it byte for byte); after the copy none (since\n",
        why="the link judge: the code root copied is 5.18.103's")
    rep("so it loads beside 5.18.102's store classes with no class declared twice.",
        "so it loads beside 5.18.103's store classes with no class declared twice.", why="the link judge")
    rep("        // stage A the pin's from Git (5.18.102's root never holds it, even where a rolled-back run left the file on disk),\n",
        "        // stage A the pin's from Git (the release's reader by its own path, never the live root's: at 5.18.103's own deploy\n"
        "        // the live 5.18.102 root held none; at 5.18.104 the live 5.18.103 root holds its own, byte for byte the pin's),\n",
        why="the catalogue's reader: the live root holds its own now")
    rep("        // copy: the applications by quote_sale_entry (F11's measure, §G.11), 5.18.102's web applications refused P1 with no\n",
        "        // copy: the applications by quote_sale_entry (F11's measure, §G.11), the web applications (quote_sale_entry web: the\n"
        "        // KYC form under 5.18.102, or under 5.18.103 or 5.18.104 while the uCRM catalogue switch is off) refused P1 with no\n",
        why="web P1s: the KYC form's, under any release with the switch off")
    rep("            // the applications (Uganda: this server's book) by entry, 5.18.102's waiting web P1s, the web-direct records by G-7\n",
        "            // the applications (Uganda: this server's book) by entry, the waiting web P1s (the KYC form's, under 5.18.102 or\n"
        "            // with the uCRM catalogue switch off), the web-direct records by G-7\n",
        why="web P1s")
    rep('''$qcS[] = "  QC-N4 5.18.102's web applications refused P1 with no quotation: "''',
        '''$qcS[] = "  QC-N4 the web applications refused P1 with no quotation (quote_sale_entry web: the KYC form under 5.18.102, or under 5.18.103 or 5.18.104 while the uCRM catalogue switch is off): "''',
        why="the sheet's QC-N4 line")
    rep("KycService cannot be loaded into stage A's reading beside 5.18.102's code root (D14 R-30).",
        "KycService cannot be loaded into stage A's reading beside the live baseline's code root (D14 R-30).", why="QL-N3's probe")

    # ════════════════ RBQ_PHP and qcrb_count: QC-RB2 a record — 5.18.103 handles them as 5.18.104 does ════════════════
    rep('''# §G.10, §G.13), "qcrb2", the web-direct applications with no quotation made, by sync status, which 5.18.102 leaves
# unquoted (their ids to the ids file, its optional second argument under /tmp, for the sheet), and "qcrb1t", the uCRM
''', H('''# §G.10, §G.13), "qcrb2", the web-direct applications with no quotation made, by sync status — a record: 5.18.103, the''',
      "# rollback target, handles them exactly as 5.18.104 does (their ids to the ids file, its optional second argument under",
      '''# /tmp, for the sheet), and "qcrb1t", the uCRM''') + "\n", why="RBQ_PHP's comment")
    rep('''    // 5.18.102 reads each as 'app' (P1, or P2 where cash was collected) and never quotes it, so an admin quotes each in
    // uCRM by hand. Their ids go to the ids file (the second argument, under /tmp) for the sheet, never to stdout.
''', '''    // counted for the record: 5.18.103, the rollback target, retries and quotes each exactly as 5.18.104 does (the same
    // KycService and UcrmQuoteLines). Their ids go to the ids file (the second argument, under /tmp) for the sheet, never
    // to stdout.
''', why="RBQ_PHP's qcrb2 comment")
    rep('''# qcrb_count [WHEN] — QC-RB2, 5.18.103 (docs/72 §G.10, §G.13): the web-direct applications with no quotation made, which
# 5.18.102 leaves unquoted after a rollback (with its gate on it reads each as 'app': P1, or P2 where cash was collected,
# and never quotes one) — READ-ONLY on a consistent copy of the book — VACUUM INTO as the database's owner (CIHV_PHP, the
''', H("# qcrb_count [WHEN] — QC-RB2, 5.18.103 (docs/72 §G.10, §G.13), carried: the web-direct applications with no quotation",
       "# made, counted for the record — the rollback changes none of them: 5.18.103 handles each exactly as 5.18.104 does —",
       "# READ-ONLY on a consistent copy of the book — VACUUM INTO as the database's owner (CIHV_PHP, the") + "\n", why="qcrb_count's comment")
    rep("# refusal: going back to 5.18.102 quotes nothing at price 0 (§G.10). Never an id, a name or an amount in the log.\n",
        "# refusal: going back to 5.18.103 quotes nothing at price 0 (docs/72 §G.8). Never an id, a name or an amount in the log.\n",
        why="qcrb_count's comment")

    # ════════════════ Q-N7: Sync All refused under 5.18.103 as after the deploy ════════════════
    rep('''echo "each gets \\"Done… Created: 0\\" from Sync All under $BASELINE_VERSION (L1); after the deploy, with the quoting organization #$qorg in force, the three screens show the server's answer instead: a non-admin \\"Admin only.\\", an admin the plugin's own refusal \\"uCRM's product was not changed: on Uganda, prices are set in uCRM Products.\\" (D12)"''',
        '''echo "with the quoting organization #$qorg in force, the server refuses Sync All under 5.18.103 as after the deploy (since 5.18.102: D12, docs/71 §K.3, §P.1): a non-admin \\"Admin only.\\", an admin the plugin's own refusal \\"uCRM's product was not changed: on Uganda, prices are set in uCRM Products.\\", which UCRM Product Mapping shows; the Subscription Plans and Hardware Catalog screens draw no Sync All there (D14, docs/71 §R.2.2), and with the uCRM catalogue's switch on (5.18.103) they are out of the menu. 5.18.104 changes none of this"''',
        why="Q-N7: the gate is 5.18.102's, live since")

    # ════════════════ QL-G1 and QL-N3: the plugin's own catalogue — what the forms offer while the switch is off ════════════════
    rep("— a link made on $BASELINE_VERSION's Plans or Hardware screens would go unjudged.",
        "— a link made on the Plans or Hardware screens (5.18.102's, or a later release's while the uCRM catalogue's switch is off) would go unjudged.",
        why="QL-G1's store refusal")
    rep('''ok "QL-G1 the catalogue the KYC form offers is read and judged''',
        '''ok "QL-G1 the plugin's own catalogue — what the KYC form offers while the uCRM catalogue's switch is off (5.18.102's path, which 5.18.103 and 5.18.104 keep; QC-G1 below is what it offers with the switch on) — is read and judged''',
        why="QL-G1 ok: the switch-off path")
    rep('''; the form offers $(ql_rows_say "$y");''', '''; with the switch off the form offers $(ql_rows_say "$y");''', why="QL-G1 ok")
    rep('''— so after the deploy, while the uCRM catalogue is off (as the deploy leaves it), every item of that kind stays unpriced, whatever an admin links: a Cash sale of one is refused, and a Credit one registered with no quotation (P1), the agent told. Once this run has printed its last line, follow the summary's steps: on the uCRM catalogue screen an admin checks what it offers and turns it on — then the forms offer uCRM's own items and no link decides anything (docs/72 §G.1, G-10) — and --after-only follows. The catalogue read:''',
        '''— so while the uCRM catalogue's switch is off — under 5.18.103 today as after the deploy, which leaves the switch as it is stored — every item of that kind stays unpriced, whatever an admin links: a Cash sale of one is refused, and a Credit one registered with no quotation (P1), the agent told; while it is on, no link decides anything: the forms offer uCRM's own items (docs/72 §G.1, G-10). The catalogue read:''',
        why="QL-G1 --accept-unpriced: whatever the switch's state")
    rep('''— so after the deploy, while the uCRM catalogue is off (as the deploy leaves it), every item of that kind would stay unpriced, whatever an admin links: a Cash sale of one refused, a Credit one registered with no quotation (P1); QC-G1's sheet says what the forms would offer once it is on.''',
        '''— so while the uCRM catalogue's switch is off — under 5.18.103 today as after the deploy, which leaves the switch as it is stored — every item of that kind would stay unpriced, whatever an admin links: a Cash sale of one refused, a Credit one registered with no quotation (P1); while it is on, no link decides anything (the sheet's QC lines say what the forms would offer then).''',
        why="QL-G1's refusal: whatever the switch's state")
    rep('''note "QL-G1 item(s) the KYC form offers do not resolve — $(ql_unp_say "$c" "$r") — not linked on $BASELINE_VERSION's Plans or Hardware screens (under D6 nothing is linked, docs/72 G-10), or linked and broken: a note, as in $BASELINE_VERSION's own run (docs/71 §R.7.1). Under $BASELINE_VERSION today, and under $EXPECTED_VERSION while the uCRM catalogue is off, as the deploy leaves it, a Cash sale of one is refused and a Credit one registered with no quotation (P1); once an admin turns the uCRM catalogue on, the forms offer uCRM's own items and no link decides anything (docs/72 §G.1, G-10); a broken one's own reason, on the sheet, says its remedy"''',
        '''note "QL-G1 item(s) of the plugin's own catalogue — what the KYC form offers while the uCRM catalogue's switch is off — do not resolve — $(ql_unp_say "$c" "$r") — not linked on 5.18.102's Plans or Hardware screens (or a later release's while the switch was off; under D6 nothing is linked, docs/72 G-10), or linked and broken: a note, as in 5.18.102's and 5.18.103's runs (docs/71 §R.7.1). While the switch is on the forms offer uCRM's own items and no link decides anything (docs/72 §G.1, G-10); only with it off — under 5.18.103 as under 5.18.104, which leaves the switch as it stands (QC-N1 below says how) — is a Cash sale of one refused and a Credit one registered with no quotation (P1); a broken one's own reason, on the sheet, says its remedy"''',
        why="QL-G1's unresolved items: the switch-off path")
    rep("— the store $BASELINE_VERSION's Plans and Hardware screens write, which $EXPECTED_VERSION keeps, frozen while the uCRM catalogue is on (docs/72 G-11);",
        "— the store 5.18.102's Plans and Hardware screens wrote (5.18.103's write it only while the uCRM catalogue's switch is off), which 5.18.104 keeps as 5.18.103 does, frozen while the switch is on (docs/72 G-11);",
        why="QL-G1's link store")
    rep("(a note; QL-LK1, which failed on it, is retired since $EXPECTED_VERSION)", "(a note; QL-LK1, which failed on it, is retired since 5.18.103)",
        why="QL-LK1 retired by 5.18.103")
    rep('''  # web-direct — the probe's qln3d11, kept in QLN3_D11_A); since 5.18.103 (docs/72 §G.13) 5.18.102's mark is the live
  # code's and 5.18.103's own is counted apart, an earlier run's, rolled back; with none in force
  # the retry quotes them as 5.18.102 does (D7; E2-1, review 2).
''', H("  # web-direct — the probe's qln3d11, kept in QLN3_D11_A); since 5.18.103 (docs/72 §G.13) its own mark is counted",
       "  # apart from 5.18.102's — both are the live code's (5.18.103's writes 5.18.102's while the uCRM catalogue's switch is",
       "  # off, its own while it is on), neither a rolled-back run's; with none in force the retry quotes them as 5.18.102",
       "  # does (D7; E2-1, review 2).") + "\n", why="QL-N3's comment: both marks the live code's")
    rep('''$x carry $BASELINE_VERSION's mark (quote_lines_source 'ucrm-5.18.102': priced at its KYC form through a link; under D6 nothing is linked, docs/72 G-10); $( [ "$z" = "0" ] && echo "none carries $EXPECTED_VERSION's own (ucrm-direct-5.18.103): the expected reading before the deploy" || echo "$z carry $EXPECTED_VERSION's own (ucrm-direct-5.18.103): written by an earlier $EXPECTED_VERSION here, rolled back since")"''',
        '''$x carry 5.18.102's mark (quote_lines_source 'ucrm-5.18.102': priced at its KYC form through a link — 5.18.102's, or 5.18.103's with its uCRM catalogue's switch off; under D6 nothing is linked, docs/72 G-10); $z carry 5.18.103's own (ucrm-direct-5.18.103): priced direct sales, made with the uCRM catalogue's switch on"''',
        why="QL-N3: both marks the live code's")
    rep('''After the deploy their retry is $BASELINE_VERSION's for a web registration of its form (quote_sale_entry web: through the links; with the uCRM catalogue's switch on, one unpriced is quoted in uCRM by hand, QC-N4), by its uCRM ids for a direct sale an earlier $EXPECTED_VERSION wrote (web-direct), and from uCRM for none of the others:''',
        '''After the deploy 5.18.104 retries them exactly as 5.18.103 does: a web registration of 5.18.102's form (quote_sale_entry web) through the links — with the uCRM catalogue's switch on, one unpriced is quoted in uCRM by hand, QC-N4; a direct sale (web-direct, made with the switch on) by its stored uCRM ids; and none of the others from uCRM:''',
        why="QL-N3: the retry is 5.18.103's, unchanged")

    # ════════════════ QC-G1, QC-N1–QC-N4: the switch as an admin left it; a note either way ════════════════
    rep("# of what is offered is a note: nothing is offered from uCRM until an admin turns the switch on. Sets QC_A (QC-G1's counts\n",
        "# of what is offered is a note: 5.18.104 changes nothing of the catalogue, and QC-CA1 compares it after the copy.\n"
        "# Sets QC_A (QC-G1's counts\n", why="qc_stage's comment")
    rep('''note "QC-G1 no plan is offered from uCRM through the plugin's own client: the uCRM catalogue screen refuses to turn the switch on until one is (each service plan's reason is on the sheet) — after the copy $EXPECTED_VERSION behaves as $BASELINE_VERSION" ;;''',
        '''note "QC-G1 no plan is offered from uCRM through the plugin's own client: $( [ "$QCSW_A" = "on" ] && echo "the switch is on, so the forms that read uCRM through that client offer no plan from uCRM now, under 5.18.103$( [ "$t" = "unset" ] || echo " (the KYC form reads with the token client: its count is above)") — each service plan's reason is on the sheet; 5.18.104 changes nothing of the catalogue" || echo "the uCRM catalogue screen refuses to turn the switch on until one is (each service plan's reason is on the sheet); 5.18.104 changes nothing of the catalogue")" ;;''',
        why="QC-G1 no plan: by the switch as found")
    rep('''  # QC-N1 — the switch as stored on stage A's copy. ON only where an earlier, rolled-back run of this release left it on:
  # then agents see direct mode the moment the copy lands — said in capitals. With no quoting organization in force
''', H("  # QC-N1 — the switch as stored on stage A's copy, a note either way: on is an admin's choice on the uCRM catalogue",
       "  # screen (5.18.103's, kept byte for byte), and the copy changes nothing of it. With no quoting organization in force") + "\n",
        why="QC-N1's comment")
    rep('''    on) note "QC-N1 THE uCRM CATALOGUE IS SWITCHED ON in this book's store — an earlier, rolled-back run of $EXPECTED_VERSION left it on (docs/72 §G.10). FROM THE COPY, AGENTS SEE uCRM'S PLANS AND HARDWARE AT ONCE: the KYC form, Send Quotation and the lead quotes offer uCRM's items and the plugin's Plans and Hardware are hidden. If that is not wanted now, an admin turns it off on the uCRM catalogue screen (Uganda) right after the run's last line" ;;''',
        '''    on) note "QC-N1 the uCRM catalogue's switch on stage A's copy: on, as an admin set it on the uCRM catalogue screen (5.18.103's; docs/72 §G.3) — the KYC form, Send Quotation and the lead quotes already offer uCRM's plans and hardware, and the plugin's Plans and Hardware are hidden. 5.18.104 changes nothing of the catalogue, so after the copy agents see what they see now, and a rollback of 5.18.104 leaves the switch as it is" ;;''',
        why="QC-N1 on: an admin's choice, a note")
    rep('''— after the copy $EXPECTED_VERSION behaves as $BASELINE_VERSION until an admin turns it on on the uCRM catalogue screen (docs/72 §G.3)" ;;''',
        '''— the forms offer the plugin's own plans and kits, priced through the uCRM item linked to each, as 5.18.103 does with the switch off; 5.18.104 changes nothing of the catalogue, so turning the switch on, under either release, is an admin's step on the uCRM catalogue screen when it allows (docs/72 §G.3)" ;;''',
        why="QC-N1 off: as 5.18.103 with it off")
    doc.mustnot("SWITCHED ON in this book's store", why="QC-N1 never in capitals")
    rep('''note "QC-N3 the applications by entry (quote_sale_entry, which every application saved since $BASELINE_VERSION's copy carries): web $(subkv "$x" web) (the KYC form), app $(subkv "$x" app) (the retailer app, the mobile API, or a form opened before that copy), web-direct $(subkv "$x" direct) (an earlier, rolled-back run of $EXPECTED_VERSION), none $(subkv "$x" none) (saved before $BASELINE_VERSION)''',
        '''note "QC-N3 the applications by entry (quote_sale_entry, which every application saved since 5.18.102's copy carries): web $(subkv "$x" web) (the KYC form with the uCRM catalogue's switch off: 5.18.102, 5.18.103 or later), app $(subkv "$x" app) (the retailer app, the mobile API, a form opened before 5.18.102's copy, or a KYC form drawn with the switch off and sent once it was on: G-9), web-direct $(subkv "$x" direct) (the KYC form with the switch on, since 5.18.103), none $(subkv "$x" none) (saved before 5.18.102's copy, or while no quoting organization was in force)''',
        why="QC-N3: each entry as the live code writes it")
    rep('''note "QC-N4 $x web application(s) of $BASELINE_VERSION refused P1 with no quotation — under $EXPECTED_VERSION with the switch off, Make the quotation applies to them as before once their items are linked; with the switch on, each is quoted in uCRM by hand, as its Orders card then says (docs/72 G-10). Ids on the sheet" ;;''',
        '''note "QC-N4 $x web application(s) (quote_sale_entry web: the KYC form under 5.18.102, or under 5.18.103 or 5.18.104 with the uCRM catalogue switch off) refused P1 with no quotation — under $EXPECTED_VERSION, as under 5.18.103: with the switch off, Make the quotation applies to them as before once their items are linked; with the switch on, an admin quotes each in uCRM by hand, as its Orders card says (docs/72 G-10), but first checks in uCRM that the customer has no quotation yet. Ids on the sheet" ;;''',
        why="QC-N4: the KYC form's web P1s under any release")
    rep('''# answer, the settings unread, a request but the two lists or any request but a GET fails. Then, in an --after-only run
# only, run once an admin has turned the switch on (docs/72 §G.13): QC-ON1 the switch ON; QC-ON2 the installation product
# set and passing; QC-ON3 every standard quotation usable (none defined: a note); QC-ON4 G-7's invariant over the copy's
''', H("# answer, the settings unread, a request but the two lists or any request but a GET fails. Then, in an --after-only run",
       "# only, the switch as found (docs/72 §G.13): on, QC-ON1 says so and QC-ON2 the installation product set and passing and",
       "# QC-ON3 every standard quotation usable (none defined: a note) are judged; off or never set, an admin's choice, one note",
       "# instead (two records in force, or a switch not read, fails QC-ON1); either way QC-ON4 G-7's invariant over the copy's")
        + "\n", why="qc_after's comment")
    rep('''  # QC-ON1–QC-ON4 — an --after-only run, once an admin has turned the switch on (docs/72 §G.13). With no quoting
  # organization in force the switch, the installation and the standard quotations are not asked (D7): one note.
''', H("  # QC-ON1–QC-ON4 — an --after-only run, the switch as found (docs/72 §G.13; DS5): off or never set, QC-ON1–QC-ON3 are",
       "  # one note. With no quoting organization in force the switch, the installation and the standard quotations are not",
       "  # asked (D7): one note.") + "\n", why="qc_after's QC-ON comment")

    # ════════════════ TN and QS: the owner rule and the sign-off, 5.18.103 the baseline, webhook.php changed only by P7 ════════════════
    rep("owners() where the code has it (5.18.100 through 5.18.103), salespeople()", "owners() where the code has it (5.18.100 through 5.18.104), salespeople()",
        why="TN_PHP: 5.18.104 has owners() too")
    rep('''TN_TEXT_BASE="tech=1 decl=1 sales=1 kind=1 role=1 page=1 old=0"   # the installed, 5.18.102's (5.18.100's rule), and the rollback's: the same rule''',
        '''TN_TEXT_BASE="tech=1 decl=1 sales=1 kind=1 role=1 page=1 old=0"   # the installed, 5.18.103's (5.18.100's rule), and the rollback's: the same rule''',
        why="TN_TEXT_BASE: the installed is 5.18.103's")
    rep("# ── 5.18.101: the quotation summary's sign-off (QS; read-only — webhook.php as text, never run), carried by 5.18.102 and 5.18.103 ─",
        "# ── 5.18.101: the quotation summary's sign-off (QS; read-only — webhook.php as text, never run), carried by 5.18.102, 5.18.103 and 5.18.104 ─",
        why="QS: carried by 5.18.104 too")
    rep('''# read both files from Git again and found these counts). 5.18.102 and 5.18.103 keep webhook.php byte for byte
# (PREV_KEPT_SHA256S; docs/71 §L.4, docs/72 §G.12): the pin's, the installed one before the copy, after it and after a rollback all read 5.18.101's line.
''', H("# read both files from Git again and found these counts). 5.18.102 and 5.18.103 keep webhook.php byte for byte",
       "# (docs/71 §L.4, docs/72 §G.12), and 5.18.104 changes it only in whQuotationEmail (Part P, root docs/73 §H.5.8.2), never",
       "# these lines: the pin's, the installed one before the copy, after it and after a rollback all read 5.18.101's line.") + "\n",
        why="QS's comment: webhook.php is no longer kept byte for byte")
    rep('''QS_TEXT_PIN="new=1 old=0 phrase=1 gate=1"    # 5.18.101's sign-off, as text — the pin's: 5.18.102 and 5.18.103 keep webhook.php byte for byte''',
        '''QS_TEXT_PIN="new=1 old=0 phrase=1 gate=1"    # 5.18.101's sign-off, as text — the pin's: 5.18.102 and 5.18.103 keep webhook.php byte for byte, and 5.18.104 changes it only in whQuotationEmail (Part P, root docs/73 §H.5.8.2), never these lines''',
        why="QS_TEXT_PIN's comment")
    rep('''the installed $QS_FILE is $BASELINE_VERSION's, the same line ($ti) — $EXPECTED_VERSION keeps $QS_FILE byte for byte;''',
        '''the installed $QS_FILE is $BASELINE_VERSION's, the same line ($ti) — 5.18.104 changes $QS_FILE only inside whQuotationEmail (P7, the agent's Cc; root docs/73 §H.5.8.2), never the sign-off line or the gate line;''',
        why="QS-G1 ok: webhook.php changed by P7 alone")

    # ════════════════ A0: the delta's counts and the kept set ════════════════
    rep('''# 5.18.98: every file of the delta by the sha256 pinned in this script (PIN_SHA256S) — the list is the delta exactly, its
# fifty-nine files and no other, the 26 it adds among them — and 5.18.102's files this release must leave as they are
# (PREV_KEPT_SHA256S) by theirs, in both release commits: what Q-G1 reads installed before anything changes, Q-CA1 after
# the copy and RB after a rollback. 5.18.99: and every file it changes by the sha256 5.18.102's release commit gives it
# (PREV_CHANGED_SHA256S; an added file has no such form, and is refused there) —
''', H("# 5.18.98: every file of the delta by the sha256 pinned in this script (PIN_SHA256S) — the list is the delta exactly, its",
       f"# {n_add + n_chg} files and no other, the {n_add} it adds among them — and the {n_kept} files of 5.18.103 this release must leave",
       "# as they are (PREV_KEPT_SHA256S) by theirs, in both release commits: what Q-G1 reads installed before anything changes,",
       f"# Q-CA1 after the copy and RB after a rollback. 5.18.99: and every file it changes ({n_chg}) by the sha256 5.18.103's",
       "# release commit (221c9dc) gives it (PREV_CHANGED_SHA256S; an added file has no such form, and is refused there) —") + "\n",
        why="A0's comment: 260, 215, 158 and 45")
    rep('''the More menu, the styles, the Cashbook's post handler, ECO Bank's wiring, the cash-in-hand tool, 5.18.98's list split, the owner rule and the quoting files $EXPECTED_VERSION keeps — QuoteOrganization, QuoteTaxLine, TenantProfile, PublicPriceFeed and cron_quote_wa.php among them; webhook.php is $EXPECTED_VERSION's own since Part P's hunk (docs/73 §H.5.8.2) — unchanged: the same pinned sha256 in both release commits;''',
        '''the More menu, the styles, the Cashbook's post handler, the cash-in-hand tool, 5.18.98's list split, the owner rule and the quoting files $EXPECTED_VERSION keeps — QuoteOrganization, QuoteTaxLine, TenantProfile, PublicPriceFeed and cron_quote_wa.php among them — unchanged: the same pinned sha256 in both release commits; webhook.php is not among them: 5.18.104 changes it by Part P's two hunks in whQuotationEmail (root docs/73 §H.5.8.2), its new form pinned with the delta, its sign-off line still 5.18.101's (the QS checks);''',
        why="A0 ok: webhook.php and ECO Bank's wiring are among the changed")
    rep('''# 5.18.96; 5.18.103 changes one of the files they live in, public.php (its tab tables, not the repair page), and must keep every
''', '''# 5.18.96; 5.18.104 changes one of the files they live in, public.php (its tab tables — My quotes, the offers screen —
# not the repair page), and must keep every
''', why="A22's comment: 5.18.104 changes public.php's tab tables")
    rep('''# R23a–R23g — 5.18.95's fixes as INSTALLED, read as text, as A22 read the pin's — a regression: 5.18.103 changes one of
# the files they live in, public.php (its tab tables, not the repair page), and must leave all seven in place.''',
        '''# R23a–R23g — 5.18.95's fixes as INSTALLED, read as text, as A22 read the pin's — a regression: 5.18.104 changes one of
# the files they live in, public.php (its tab tables — My quotes, the offers screen — not the repair page), and must leave all seven in place.''',
        why="R23's comment")

    # ════════════════ the live release is 5.18.103: BASE_DIFFERS, A22L, BR1, Q-G1, the cash code ════════════════
    rep("  movelist)   # 5.18.99 (SPEC §6, OPS-10): before the deploy, while the release before this one is live (5.18.102 here) — Q-G1",
        "  movelist)   # 5.18.99 (SPEC §6, OPS-10): before the deploy, while the release before this one is live (5.18.103 here) — Q-G1",
        why="the move-list: 5.18.103 is live")
    rep('''# Since 5.18.91: with the container's record on the rollback target (5.18.102 here), every file this release changes
# that the target has must be the target's byte for byte. A copy that stopped part-way may have replaced some of them,
# manifest.json or not; the deploy and the rollback each refuse then, and print the put-back (the tenth and eleventh
# reviews). Files the release adds are not asked about: a rollback leaves them on disk, and the rollback target reads
# none of them — 5.18.102's code reads no file 5.18.103 adds (EXPECTED_ADDED: lib/UcrmCatalogue.php,
# tabs/sales/ucrm_catalogue.php and 5.18.103's tests; QC-RB1 shows it after a rollback), and BR1 counts
# the ones an earlier rollback left.
''', H("# Since 5.18.91: with the container's record on the rollback target (5.18.103 here), every file this release changes",
       "# that the target has must be the target's byte for byte. A copy that stopped part-way may have replaced some of them,",
       "# manifest.json or not; the deploy and the rollback each refuse then, and print the put-back (the tenth and eleventh",
       "# reviews). Files the release adds are not asked about: a rollback leaves them on disk, and no 5.18.103 file names any",
       f"# of them (EXPECTED_ADDED: 5.18.104's {n_add}; QC-RB1 shows it after a rollback) — its MigrationRunner alone reads a",
       "# directory, migrations/, where it finds 089–091 and passes over those already in its ledger — and BR1 counts the ones",
       "# an earlier rollback left.") + "\n", why="BASE_DIFFERS's comment")
    rep('''# A22L — 5.18.95's fixes in the LIVE code, the installed files, read as A22 read the pin's (refusal): 5.18.102 is live and
# carries them, so all seven checks must read it fixed — a live 5.18.102 without them is not the release this script was built against.''',
        '''# A22L — 5.18.95's fixes in the LIVE code, the installed files, read as A22 read the pin's (refusal): 5.18.103 is live and
# carries them, so all seven checks must read it fixed — a live 5.18.103 without them is not the release this script was built against.''',
        why="A22L: 5.18.103 is live")
    rep('''# BR1 — since 5.18.97 (SPEC §2.10b R1): the installed plugin is live 5.18.102 — every file 1a0ea5f tracks under the plugin,
# data/ aside, byte for byte (sha256 on the host, from git archive into this run's folder, removed at once), not only the
# files this release changes (the record and the manifest say 5.18.102 above; BASE_DIFFERS read this release's own).
# Files on disk beyond them are not asked about: deploy-hybrid.sh never deletes, so an earlier 5.18.103 and its
# rollback leave the 26 files it adds behind (docs/72 §G.10) — counted and said (BR1_LEFT, over EXPECTED_ADDED);
# 5.18.102's code reads none of them (QC-RB1), and the copy replaces them.
''', H("# BR1 — since 5.18.97 (SPEC §2.10b R1): the installed plugin is live 5.18.103 — every file 221c9dc tracks under the plugin,",
       "# data/ aside, byte for byte (sha256 on the host, from git archive into this run's folder, removed at once), not only the",
       "# files this release changes (the record and the manifest say 5.18.103 above; BASE_DIFFERS read this release's own).",
       "# Files on disk beyond them are not asked about: deploy-hybrid.sh never deletes, so an earlier 5.18.104 and its",
       f"# rollback leave the {n_add} files it adds behind (root docs/73 §H.2.16) — counted and said (BR1_LEFT, over EXPECTED_ADDED);",
       "# no 5.18.103 file names them (QC-RB1), and the copy replaces them. 5.18.103's MigrationRunner globs migrations/*.sql at",
       "# boot and passes over the files in its ledger: 089–091, applied at 5.18.104's first boot, are not run again (one left",
       "# on disk that never ran would run then).") + "\n", why="BR1's comment")
    rep('''# Q-G1 — 5.18.99 (SPEC-quote-company §6; 5.18.98's G1, carried): the live plugin is 5.18.102 — the container's record and
# the manifest say so above, and (a deploy) BR1 has read every file 1a0ea5f tracks — and, by the sha256 THIS SCRIPT pins
# for each (not only Git's), every file 5.18.103 changes is 5.18.102's as installed (PREV_CHANGED_SHA256S), and every file
# 5.18.102 has that 5.18.103 must not touch (PREV_KEPT_SHA256S): ECO Bank's module, its link on the CEO page (BK3 reads the
# one in navigation.php, which 5.18.103 changes outside it), the More menu, the
# styles, the Cashbook's post handler and its list split, ECO Bank's wiring, the cash-in-hand tool, the files the
# quoting code reads and 5.18.99's quoting files that 5.18.103 keeps (cron_quote_wa.php, QuoteTaxLine, TenantProfile and
# webhook.php; QuoteOrganization, QuotePdfSource and UcrmLeadSync), 5.18.100's owner rule and the three files it reads,
# the four 5.18.102 read or docs/71 §L.4 declared kept (lib/PublicPriceFeed.php among them), 5.18.102's own that 5.18.103
# does not change (lib/UcrmQuoteLinks.php among them) and the five 5.18.103 reads or docs/72 §G.12 declares kept (prices.php
# and shop.php among them), unchanged. The 26 files
# 5.18.103 adds are not asked about here: BR1 counts any an earlier rollback left. Run before a deploy and before the
# move-list.
''', H("# Q-G1 — 5.18.99 (SPEC-quote-company §6; 5.18.98's G1, carried): the live plugin is 5.18.103 — the container's record and",
       "# the manifest say so above, and (a deploy) BR1 has read every file 221c9dc tracks — and, by the sha256 THIS SCRIPT pins",
       f"# for each (not only Git's), every file 5.18.104 changes ({n_chg}: navigation.php and webhook.php among them) is 5.18.103's",
       f"# as installed (PREV_CHANGED_SHA256S), and every file 5.18.103 has that 5.18.104 must not touch ({n_kept}, PREV_KEPT_SHA256S):",
       "# ECO Bank's module, its link on the CEO page (BK3 reads the one in navigation.php, which 5.18.104 changes outside it),",
       "# the More menu, the styles, the Cashbook's post handler and its list split, the cash-in-hand tool, the files the quoting",
       "# code reads and 5.18.99's quoting files that 5.18.104 keeps (cron_quote_wa.php, QuoteTaxLine and TenantProfile;",
       "# QuoteOrganization, QuotePdfSource and UcrmLeadSync), 5.18.100's owner rule and the three files it reads, 5.18.102's own",
       "# (lib/PublicPriceFeed.php and lib/UcrmQuoteLinks.php among them), 5.18.103's own (lib/UcrmCatalogue.php and its screen,",
       "# prices.php and shop.php among them) and the files the follow-up's code reads (lib/StaffJobsGate.php and",
       f"# lib/MigrationRunner.php among them), unchanged. The {n_add} files 5.18.104 adds are not asked about here: BR1 counts any",
       "# an earlier rollback left. Run before a deploy and before the move-list.") + "\n", why="Q-G1's comment")
    rep("the More menu, the styles, the Cashbook's post handler and its list split, ECO Bank's wiring, the cash-in-hand tool, the quoting files $EXPECTED_VERSION keeps (QuoteOrganization, QuoteTaxLine, TenantProfile, PublicPriceFeed, cron_quote_wa.php and webhook.php among them), 5.18.100's owner rule",
        "the More menu, the styles, the Cashbook's post handler and its list split, the cash-in-hand tool, the quoting files $EXPECTED_VERSION keeps (QuoteOrganization, QuoteTaxLine, TenantProfile, PublicPriceFeed and cron_quote_wa.php among them), 5.18.100's owner rule",
        why="Q-G1 ok: webhook.php and ECO Bank's wiring are among the changed")
    rep("# container's /tmp beside the release code above and removed with it, however the run ends: the pin's and 5.18.102's\n",
        "# container's /tmp beside the release code above and removed with it, however the run ends: the pin's and 5.18.103's\n",
        why="the cash code: the baseline 5.18.103")

    # ════════════════ R4 and its neighbours: 5.18.104's own media layer is the delta's, pinned ════════════════
    rep("# changed, and every expected file present. This is what keeps the undeployed portal, CSRF and AI media-layer work out.\n",
        "# changed, and every expected file present. This is what keeps the undeployed portal and CSRF work, and the branch's\n"
        "# old media files, out (5.18.104's own media layer, Part V, is in the delta, pinned).\n",
        why="A0's delta comment: Part V is the delta's")
    rep('''# The development branch's master.php carries the undeployed AI media job; the pin's must be 5.18.92's byte for byte —
# 5.18.93 and 5.18.94 do not change it — and its lock test 5.18.92's; its main.php 5.18.93's byte for byte — the auto-pull
''', H("# The pin's master.php is the reviewed one — 5.18.92's lock with ruling R1's four jobs last (root docs/73 §H.2.13) —",
       "# and its lock test 5.18.92's; its main.php 5.18.93's byte for byte — the auto-pull") + "\n",
        why="A0's master comment: the pin's master carries the four jobs")
    rep('''# A6 — South Sudan (refusal 6). The pin's event processor — unchanged by 5.18.95 and 5.18.96 — must keep South Sudan's loop exactly:
# its list ['ai.reply'], no ai.media anywhere, the early return on an empty claim, the Uganda path behind the country gate
''', H("# A6 — South Sudan (refusal 6). The pin's event processor — 5.18.104 adds ai.media and ai.voice_ack to Uganda's list alone",
       "# — must keep South Sudan's loop exactly: its list ['ai.reply'], ai.media named only in Uganda's list (and the comment",
       "# above it), the early return on an empty claim, the Uganda path behind the country gate") + "\n",
        why="A6's comment: Uganda's list gains ai.media")
    rep('''# R4 — regression: the distributor pilot is installed as before, its two webhook hooks flag-gated, and the bound channel is
# the Null one (the Evolution adapter is defined but constructed nowhere): nothing can be sent. And none of the undeployed
# work on the branch — the partner portal, the CSRF guard, the AI media layer (migration 085, the media worker, voice,
# image and document paths) — is installed.
''', H("# R4 — regression: the distributor pilot is installed as before, its two webhook hooks flag-gated, and the bound channel is",
       "# the Null one (the Evolution adapter is defined but constructed nowhere): nothing can be sent. And none of the undeployed",
       "# work on the branch — the partner portal, the CSRF guard, migration 081, the old media layer's migration 085 and",
       "# lib/MediaWorker.php — is installed; 5.18.104's own media layer (Part V, behind switches that ship off) is installed",
       "# and pinned by sha256 (R1, Q-CA1).") + "\n", why="R4's comment")
    rep("the pilot tables read $DT; no partner-portal, CSRF or AI media-layer file is installed, and neither the event processor, the webhook nor the AI worker carries any of the media layer\"",
        "the pilot tables read $DT; no partner-portal or CSRF file, nor migration 081, 085 or lib/MediaWorker.php, is installed — 5.18.104's own media layer (Part V: workers/MediaWorker.php and its classes, behind switches that ship off) is installed and pinned by sha256 instead (R1, Q-CA1), the event processor's behaviour by its signatures (R13)\"",
        why="R4 ok: Part V's media layer is installed, pinned")
    rep("# BA1 — since 5.18.97 (SPEC §2.10b A1): migration 088 as installed — 5.18.103 adds no migration and writes none of 088's\n",
        "# BA1 — since 5.18.97 (SPEC §2.10b A1): migration 088 as installed — 5.18.104 adds 089–091 and writes none of 088's\n",
        why="BA1's comment")
    rep('''# (check_088). Then ECO Bank's surface, as installed — unchanged by 5.18.103 (Q-G1, Q-CA1) but for public.php, whose tab
# tables gain the uCRM catalogue's lines while ECO Bank's stay as they were: its five files, the tab and its
''', H("# (check_088). Then ECO Bank's surface, as installed — unchanged by 5.18.104 (Q-G1, Q-CA1) but for public.php, whose tab",
       "# tables gain My quotes and the offers screen, and includes/post_handlers.php, which requires post_offers.php, while ECO",
       "# Bank's lines stay as they were: its five files, the tab and its") + "\n", why="BA1's comment: the files 5.18.104 changes")
    rep("# runner; 5.18.98 through 5.18.103 add no migration and write none of its tables.",
        "# runner; 5.18.98 through 5.18.103 add no migration and write none of its tables, and 5.18.104's three (089–091) write none of its tables.",
        why="check_088's comment")

    # ════════════════ stage A notes: the Inbox, and a --rollback's QC-RB2 and its what-it-means ════════════════
    rep('''5.18.86's Inbox refuses a reply in a chat whose number it lacks. $EXPECTED_VERSION does not change the Inbox" ;;''',
        '''5.18.86's Inbox refuses a reply in a chat whose number it lacks. 5.18.104 does not change how the Inbox routes or refuses a reply (lib/InboxReplyRoute.php is kept byte for byte); its one Inbox change, on Uganda only, shows staff a voice note's machine transcript, and only once voice notes are switched on at R3 — it shows nothing until then" ;;''',
        why="A3: the Inbox's one change, behind a switch")
    rep('''  # QC-RB2 — 5.18.103 (docs/72 §G.10, §G.13), before anything changes: the web-direct applications with no quotation
  # made, which $BASELINE_VERSION will leave unquoted, counted read-only on a consistent copy of the book (qcrb_count) —
  # a note, never a refusal: going back quotes nothing at price 0. Their ids go to the sheet, to quote by hand.
''', H("  # QC-RB2 — 5.18.103 (docs/72 §G.10, §G.13), carried, before anything changes: the web-direct applications with no",
       "  # quotation made, counted read-only on a consistent copy of the book (qcrb_count) — a record: 5.18.103 handles each as",
       "  # 5.18.104 does, so the rollback leaves none to quote by hand; a note, never a refusal. Their ids go to the sheet.") + "\n",
        why="stage A's QC-RB2 comment")
    rep('''note "QC-RB2 before the rollback, on a copy of the book: $(qcrb_say "$QC_RB") — after it each with no quotation is quoted in uCRM by hand; $QC_RB_IDS"''',
        '''note "QC-RB2 before the rollback, on a copy of the book: $(qcrb_say "$QC_RB") — the rollback changes nothing for them: 5.18.103 retries and quotes each exactly as 5.18.104 does (the same KycService and UcrmQuoteLines), so none is to be quoted in uCRM by hand because of it; $QC_RB_IDS (for the record)"''',
        why="stage A's QC-RB2 note")
    i = doc.t.index('''  note "after the rollback the code is $BASELINE_VERSION's again. On Uganda the uCRM catalogue screen goes,''')
    j = doc.t.index(" The quotation summary's sign-off, the card's owner lists and every number,", i)
    if "\n" in doc.t[i:j] or doc.t.count('''  note "after the rollback the code is $BASELINE_VERSION's again.''') != 1:
        raise SystemExit("C: stage A's rollback note is not one line")
    doc.t = doc.t[:i] + '''  note "after the rollback the code is 5.18.103's again where 5.18.104 changed it. On Uganda the uCRM catalogue is 5.18.103's own and stays exactly as it is — 5.18.104 keeps every file of it byte for byte and the rollback touches none of it: its screen, its settings (the switch, the installation product, the products kept off, the standard quotations) and its switch stay as an admin left them, and the KYC form, Send Quotation and the lead quotes offer what they offer now. A web-direct application is handled by 5.18.103 exactly as 5.18.104 handles it — retried by its uCRM ids, Make the quotation on its Orders card where that applies — so the rollback adds none to quote in uCRM by hand (QC-RB2 counts them, for the record). Nothing is quoted at price 0: 5.18.103 prices from uCRM or refuses (docs/72 §G.8). Quotes already made in uCRM are not changed.''' + doc.t[j:]
    doc.log.append(("stage A's rollback note: the catalogue 5.18.103's, untouched", 1))
    doc.must("  note \"after the rollback the code is 5.18.103's again where 5.18.104 changed it.", 1)

    # ════════════════ A14: the previous release's state files are 5.18.103's and the six before it ════════════════
    rep('''# beside it — and in the previous release's own, 5.18.102's, and the five before, 5.18.101's, 5.18.100's, 5.18.99's,
# 5.18.98's and 5.18.97's (their R19 and RB record the same line, in /root/dnb-5.18.102/state-5.18.102.env,
# /root/dnb-5.18.101/state-5.18.101.env, /root/dnb-5.18.100/state-5.18.100.env, /root/dnb-5.18.99/state-5.18.99.env,
# /root/dnb-5.18.98/state-5.18.98.env, /root/dnb-5.18.97/state-5.18.97.env and the ones kept beside each) — is not
''', H("# beside it — and in the previous release's own, 5.18.103's, and the six before, 5.18.102's, 5.18.101's, 5.18.100's,",
       "# 5.18.99's, 5.18.98's and 5.18.97's (their R19 and RB record the same line, in /root/dnb-5.18.103/state-5.18.103.env,",
       "# /root/dnb-5.18.102/state-5.18.102.env, /root/dnb-5.18.101/state-5.18.101.env, /root/dnb-5.18.100/state-5.18.100.env,",
       "# /root/dnb-5.18.99/state-5.18.99.env, /root/dnb-5.18.98/state-5.18.98.env, /root/dnb-5.18.97/state-5.18.97.env and",
       "# the ones kept beside each) — is not") + "\n", why="A14's comment: 5.18.103 and the six before")
    rep('''# Since 5.18.95 — the quarantine's rule 3: after any plugin upload, rollback or restore, its --check. 5.18.102's deploy was one,
# so a deploy goes on only once a --check that began after 5.18.102's latest copy (COPY_AT in its state file, or in one a later
''', '''# Since 5.18.95 — the quarantine's rule 3: after any plugin upload, rollback or restore, its --check. 5.18.103's deploy was one,
# so a deploy goes on only once a --check that began after 5.18.103's latest copy (COPY_AT in its state file, or in one a later
''', why="A14: 5.18.103's copy")
    rep("# Since 5.18.97 (its review): and after this script's own latest copy of the plugin — a deploy of 5.18.103 (COPY_AT in its\n",
        "# Since 5.18.97 (its review): and after this script's own latest copy of the plugin — a deploy of 5.18.104 (COPY_AT in its\n",
        why="A14: this script's own copy is 5.18.104's")
    rep("# or a deploy over a rolled-back 5.18.103, goes on only once a --check that began after that rollback has PASSED.\n",
        "# or a deploy over a rolled-back 5.18.104, goes on only once a --check that began after that rollback has PASSED.\n",
        why="A14")

    # ════════════════ the cash checks: 5.18.103 live, 5.18.104 the pin ════════════════
    rep("#   A23.1 the LIVE code's figure — 5.18.102's cashInHand() (5.18.98's, unchanged since), installed, copied, its rule A\n",
        "#   A23.1 the LIVE code's figure — 5.18.103's cashInHand() (5.18.98's, unchanged since), installed, copied, its rule A\n", why="A23")
    rep("#   BK1   the two codes the same to the last digit in every currency × project: 5.18.103 does not change cash in hand;\n",
        "#   BK1   the two codes the same to the last digit in every currency × project: 5.18.104 does not change cash in hand;\n", why="BK1")
    rep('''# 5.18.102 classes (G3), the offered accounts (BN5) and the gate per switch state through the pin's (BK2); the copy checks
# C1–C5, 5.18.102's code and 5.18.103's from Git on ONE copy of the master; and the notes N1–N4, with SQL of this script's
''', '''# 5.18.103 classes (G3), the offered accounts (BN5) and the gate per switch state through the pin's (BK2); the copy checks
# C1–C5, 5.18.103's code and 5.18.104's from Git on ONE copy of the master; and the notes N1–N4, with SQL of this script's
''', why="A23's ECO Bank and copy checks")
    rep("  # The pin's code reads the cards as the live classes do — 5.18.103 changes none of the files they are read through (A0, Q-G1).",
        "  # The pin's code reads the cards as the live classes do — 5.18.104 changes none of the files they are read through (A0, Q-G1).", why="G3")
    rep('''  # C1–C5 — 5.18.98 (SPEC-separate-v2 §2.10), carried: 5.18.102's code and 5.18.103's, both from Git, on ONE copy of the
''', '''  # C1–C5 — 5.18.98 (SPEC-separate-v2 §2.10), carried: 5.18.103's code and 5.18.104's, both from Git, on ONE copy of the
''', why="C1–C5")
    rep("  # both codes against each other: 5.18.103 changes no file of the Cashbook's, so they must be the same.",
        "  # both codes against each other: 5.18.104 changes no file of the Cashbook's, so they must be the same.", why="C3")

    # ════════════════ stage A's QL header, stamp_copy, V6, Q-CA5, QL-CA3, V3 ════════════════
    rep('''  # catalogue the KYC form offers — its active plans and kits, and the installation — through the PIN's UcrmQuoteLines on
''', '''  # catalogue the KYC form offers while the uCRM catalogue's switch is off — its active plans and kits, and the
  # installation — through the PIN's UcrmQuoteLines on
''', why="QL-G1's comment: the switch-off path")
    rep('''  hdr "A. The catalogue the KYC form offers (5.18.102's check, carried; GETs only)"''',
        '''  hdr "A. The catalogue the KYC form offers while the uCRM catalogue is off (5.18.102's check, carried; GETs only)"''',
        why="QL-G1's header")
    rep('''# adds too: after a rollback the 26 that 5.18.103 adds are left on disk (deploy-hybrid.sh never deletes; docs/72
# §G.10) and are given the time as well, so the count then is every file of the delta found on disk, not the ones copied.
''', H(f"# adds too: after a rollback the {n_add} that 5.18.104 adds are left on disk (deploy-hybrid.sh never deletes; root docs/73",
       "# §H.2.16) and are given the time as well, so the count then is every file of the delta found on disk, not the ones",
       "# copied.") + "\n", why="stamp_copy's comment")
    rep("    # V6 — 5.18.73/5.18.74: the sign-in page allows pinch-zoom; 5.18.96 keeps it (and so does the rollback target, 5.18.102).",
        "    # V6 — 5.18.73/5.18.74: the sign-in page allows pinch-zoom; 5.18.96 keeps it (and so does the rollback target, 5.18.103).", why="V6")
    rep('''bad "Q-CA5 a page 5.18.99 changed or reaches (the KYC form, Send Quotation and Orders changed again by $EXPECTED_VERSION) does not''',
        '''bad "Q-CA5 a page 5.18.99 changed or reaches (the KYC form, Send Quotation and Orders, changed again by 5.18.102 and 5.18.103 and kept byte for byte by 5.18.104) does not''',
        why="Q-CA5: 5.18.104 keeps the three pages")
    rep('''      # QL-CA3 — 5.18.102 D14 (docs/71 §R.7.6; derivation M-7, S7): the Plans and Hardware screens — where an admin now links
      # each item to the uCRM product (and service plan) that decides what cash an agent collects — and the Orders card's
''', '''      # QL-CA3 — 5.18.102 D14 (docs/71 §R.7.6; derivation M-7, S7): the Plans and Hardware screens — where an admin links
      # each item, while the uCRM catalogue's switch is off, to the uCRM product (and service plan) that decides what cash an
      # agent collects — and the Orders card's
''', why="QL-CA3's comment: links only while the switch is off")
    rep('''# V3 — the distributor pilot's switch (distributors_enabled) is UNCHANGED by the deploy: whatever state the operator left it
# in, it stays. It is not the salesperson numbers' switches — those are V3f and V3h.
''', H("# V3 — the distributor pilot's switch (distributors_enabled) is UNCHANGED by this run (a deploy or a rollback): whatever",
       "# state the operator left it in, it stays. It is not the salesperson numbers' switches — those are V3f and V3h.") + "\n",
        why="V3's comment: any run")
    rep('''ok "V3 the distributor pilot's switch is unchanged by the deploy (before=$SW_BEFORE, after=$SW_AFTER) — $EXPECTED_VERSION changes no configuration value"''',
        '''ok "V3 the distributor pilot's switch is unchanged by this run (before=$SW_BEFORE, after=$SW_AFTER) — nothing this run does touches distributors_enabled; the sales follow-up's switch tool, where this run calls it (QF-G4's --clear-switches, QF-RB1's off all --rollback), clears only its own keys"''',
        why="V3 ok: true of a deploy and of a rollback")
    rep('''bad "V3 the distributor pilot's switch CHANGED across the deploy (before=${SW_BEFORE:-?}, after=$SW_AFTER) — $EXPECTED_VERSION must never touch it"''',
        '''bad "V3 the distributor pilot's switch CHANGED across this run (before=${SW_BEFORE:-?}, after=$SW_AFTER) — this run must never touch it"''',
        why="V3 bad")

    # ════════════════ Q-CA1: 260, 215, 158; webhook.php among the changed ════════════════
    rep('''# for each of its 59 (R1 compared them with Git), the 26 it adds among them; the 5.18.102 files this release must not
# touch — ECO Bank's module, its link on the CEO page, the Cashbook's service, its page, its tab, its export and its routes,
# api/index.php, cron_quote_wa.php, the quoting files 5.18.103 keeps (QuoteOrganization, QuoteTaxLine, TenantProfile,
# PublicPriceFeed, UcrmQuoteLinks), lib/SalesNumbersAdmin.php and its page and the rest — are still 5.18.102's by theirs (webhook.php
# among them — 5.18.101's sign-off, kept byte for byte), and master.php and main.php are the reviewed ones byte for
# byte; and php -l, under the
''', H(f"# for each of its {n_add + n_chg} (R1 compared them with Git), the {n_add} it adds among them and webhook.php among the {n_chg} it",
       f"# changes; the {n_kept} 5.18.103 files this release must not touch — ECO Bank's module, its link on the CEO page, the Cashbook's",
       "# service, its page, its tab, its export and its routes, api/index.php, cron_quote_wa.php, the quoting files 5.18.104",
       "# keeps (QuoteOrganization, QuoteTaxLine, TenantProfile, PublicPriceFeed, UcrmQuoteLinks, UcrmQuoteLines, UcrmCatalogue),",
       "# lib/SalesNumbersAdmin.php and its page and the rest — are still 5.18.103's by theirs, and master.php and main.php are",
       "# the reviewed ones byte for byte; and php -l, under the") + "\n", why="Q-CA1's comment")
    rep("the Cashbook's service, page, tab, export and routes, api/index.php, cron_quote_wa.php, webhook.php, the quoting files $EXPECTED_VERSION keeps",
        "the Cashbook's service, page, tab, export and routes, api/index.php, cron_quote_wa.php, the quoting files $EXPECTED_VERSION keeps",
        why="Q-CA1 ok: webhook.php is among the changed")

    # ════════════════ R13, R16, R23, CA2, R26: 5.18.103's code beside the installed ════════════════
    rep("# 5.18.90's (and 5.18.102's code, $BASE_ROOT, run beside it, must read so — the control; its cron/event_processor.php is\n",
        "# 5.18.90's (and 5.18.103's code, $BASE_ROOT, run beside it, must read so — the control; its cron/event_processor.php is\n", why="R13")
    rep('''# 5.18.102's prompt ($BASE_ROOT's, run beside it; lib/DishNetAiBrain.php is 5.18.90's, unchanged since) for all twelve
# conversations and for the control, an owner named, which differs from a department's.
''', H("# 5.18.103's prompt ($BASE_ROOT's, run beside it; lib/DishNetAiBrain.php is 5.18.90's through 5.18.103 — 5.18.104",
       "# changes it and lib/BrainContext.php only for a voice-note turn, which none of the twelve is) for all twelve",
       "# conversations and for the control, an owner named, which differs from a department's.") + "\n", why="R16's comment")
    rep('''# code (5.18.102's, from Git) on the same copy equals D2 = A's SQL too, and the installed figure to the last digit (BK1,
# BA2) — 5.18.103 changes no figure; then ECO Bank on the same copy through the installed code (BA2: BK2 and G3 again); the copy checks
# C1–C5 again, 5.18.102's code from Git and the installed (CA2); the parts add up; P0 again, for all projects and dishnet
''', '''# code (5.18.103's, from Git) on the same copy equals D2 = A's SQL too, and the installed figure to the last digit (BK1,
# BA2) — 5.18.104 changes no figure; then ECO Bank on the same copy through the installed code (BA2: BK2 and G3 again); the copy checks
# C1–C5 again, 5.18.103's code from Git and the installed (CA2); the parts add up; P0 again, for all projects and dishnet
''', why="R23's comment")
    rep("  # CA2 — 5.18.98 (SPEC-separate-v2 §2.10 A2), carried: C1–C5 again, on this NEW copy, with 5.18.102's code from Git and the\n",
        "  # CA2 — 5.18.98 (SPEC-separate-v2 §2.10 A2), carried: C1–C5 again, on this NEW copy, with 5.18.103's code from Git and the\n", why="CA2")
    rep("# R26 — South Sudan through the INSTALLED library, as A24: the installed code against 5.18.102's (from Git), on the same\n",
        "# R26 — South Sudan through the INSTALLED library, as A24: the installed code against 5.18.103's (from Git), on the same\n", why="R26")
    # R23's comment, the installed tool's banner (DS5-fix1, R04: one asserted sub of the whole line, never a conditional):
    # 5.18.104 keeps tools/cash_in_hand.php byte for byte, read off Git here, so its banner is still 5.18.96's
    if "tools/cash_in_hand.php" in chg or sha_at(repo, base, f"{plugin}/tools/cash_in_hand.php") != sha_at(repo, pin, f"{plugin}/tools/cash_in_hand.php"):
        raise SystemExit("C: tools/cash_in_hand.php is not byte for byte the same at 5.18.103 and the pin")
    rep("# the rule of 5.18.96 (its banner 5.18.96's: 5.18.97 through 5.18.103 do not change it) and figures equal to the installed\n",
        "# the rule of 5.18.96 (its banner 5.18.96's: 5.18.97 through 5.18.104 do not change it) and figures equal to the installed\n",
        why="R23's comment: the releases since 5.18.97 (DS5-fix1, R04)")
    doc.mustnot("5.18.97 through 5.18.103", why="R23's comment: no release range left ending at 5.18.103")

    # ════════════════ R and RB: QC-ON as found; 5.18.104's change gone, 5.18.103's catalogue its own ════════════════
    rep('''# (never printed); and, in an --after-only run once an admin has turned the switch on, QC-ON1–QC-ON4. Reports; never
# stops.
''', H("# (never printed); and, in an --after-only run, QC-ON1–QC-ON4 with the switch as found (QC-ON1–QC-ON3 judged while it is",
      "# on, one note while it is off). Reports; never stops.") + "\n", why="R's QC comment")
    rep('''# 5.18.103's change is gone (below: 5.18.102's files by sha256 and the 97 it keeps by theirs; the 26 files it adds left
# on disk and reached by nothing, QC-RB1; the uCRM catalogue's table left in the store, counted, and named by no
# installed file, QC-RB1; the web-direct applications with no quotation counted again, QC-RB2, their ids on the sheet;
# 5.18.101's sign-off, unchanged, 5.18.100's owner rule and 5.18.102's quoting code in place, and cash in hand on a
# copy, D2 = A as before);
''', H(f"# 5.18.104's change is gone (below: 5.18.103's files by sha256 where 5.18.104 changed them ({n_chg}) and the {n_kept} it keeps",
       f"# by theirs; the {n_add} files it adds left on disk and reached by nothing, QC-RB1; 089–091's tables left in place, QF-RB2;",
       "# the uCRM catalogue's table, 5.18.103's own, counted and read by it again, QC-RB1; the web-direct applications with no",
       "# quotation counted again, QC-RB2, their ids on the sheet, a record; 5.18.101's sign-off, unchanged, 5.18.100's owner",
       "# rule and 5.18.103's quoting code in place, and cash in hand on a copy, D2 = A as before);") + "\n", why="RB's comment")
    rep('''# main.php stays 5.18.93's, byte for byte,
# its auto-pull retired; cron/master.php stays
# 5.18.92's, its lock its own, and the retry job 5.18.91's, its gate in place, each byte for byte…
''', H("# main.php stays 5.18.93's, byte for byte,",
       "# its auto-pull retired; cron/master.php is 5.18.92's again (5.18.104's four jobs gone with the put-back's copy),",
       "# its lock its own, and the retry job 5.18.91's, its gate in place, each byte for byte…") + "\n", why="RB's master comment")
    rep("$MASTER stays $BASELINE_VERSION's, its own lock;",
        "$MASTER is $BASELINE_VERSION's — 5.18.92's byte for byte, its own lock, none of the four jobs 5.18.104 adds to it (a rollback's copy puts it back);",
        why="RB ok: master is put back, not kept")
    rep('''# 5.18.103's files are 5.18.102's again, each byte for byte — by Git's sha256 and by the one THIS SCRIPT pins for each
# (PREV_CHANGED_SHA256S, Q-G1's set read again: 33) — and the 26 files it added stay on disk
# (deploy-hybrid.sh never deletes, nor can the by-hand put-back). Git's loop below passes over them, since 5.18.102 has
# none of them; QC-RB1 (below) proves nothing in 5.18.102 reaches them. The files 5.18.103 adds, as they are on disk now —
''', H("# 5.18.104's files are 5.18.103's again, each byte for byte — by Git's sha256 and by the one THIS SCRIPT pins for each",
       f"# (PREV_CHANGED_SHA256S, Q-G1's set read again: {n_chg}) — and the {n_add} files it added stay on disk",
       "# (deploy-hybrid.sh never deletes, nor can the by-hand put-back). Git's loop below passes over them, since 5.18.103 has",
       "# none of them; QC-RB1 (below) proves nothing in 5.18.103 reaches them. The files 5.18.104 adds, as they are on disk now —")
        + "\n", why="RB's files comment")
    rep('''# 5.18.99's quoting code is in place: the rollback target has it. 5.18.103 changed seven of its files — four of the
# seven below that reach uCRM (lib/KycService.php, lib/KycCrmSync.php, lib/QuotationService.php and includes/api/api_cron_debug.php),
# includes/api/api_products_admin.php, tabs/sales/kyc_form.php and tabs/sales/applications.php (docs/72 §G.12) — and
# each is 5.18.102's again by its pinned sha256 (above);
# lib/QuoteOrganization.php, lib/UcrmClientTarget.php, lib/UcrmLeadSync.php, lib/PluginQuotePdf.php, the Uganda profile and tools/set_config.php it never changed, each
# kept by its own (below).''', H("# 5.18.99's quoting code is in place: the rollback target has it. 5.18.104 changed one of its files that reach uCRM,",
       "# lib/QuotationService.php (Part F's tracking and Part P's agent copy), and it is 5.18.103's again by its pinned sha256",
       "# (above, PREV_CHANGED_SHA256S); lib/KycService.php, lib/KycCrmSync.php, includes/api/api_cron_debug.php,",
       "# includes/api/api_products_admin.php, tabs/sales/kyc_form.php, tabs/sales/applications.php, lib/QuoteOrganization.php,",
       "# lib/UcrmClientTarget.php, lib/UcrmLeadSync.php, lib/PluginQuotePdf.php, the Uganda profile and tools/set_config.php",
       "# it never changed, each kept by its own (PREV_KEPT_SHA256S, below)."), why="RB's quoting comment: one file changed by 5.18.104")
    rep('''note "RB the quoting records, as they are — 5.18.99's code's, $BASELINE_VERSION's and $EXPECTED_VERSION's own (their refusals P1–P3 among them), left as written by the rollback:''',
        '''note "RB the quoting records, as they are — 5.18.99's code's, 5.18.102's and 5.18.103's own (5.18.102's refusals P1–P3 among them, which 5.18.103's and 5.18.104's code write too; 5.18.104 adds none of these kinds), left as written by the rollback:''',
        why="RB's quoting records")
    rep('''note "RB the quoting records could not be counted ($RBQ_S) — $BASELINE_VERSION's and $EXPECTED_VERSION's refusals P1–P3 among them; the rollback rewrites none" ;;''',
        '''note "RB the quoting records could not be counted ($RBQ_S) — 5.18.102's refusals P1–P3 and 5.18.103's on its web-direct applications among them; the rollback rewrites none" ;;''',
        why="RB's quoting records")
    rep('''# the sheet). Notes, never a failure: the rollback rewrites no application, and $BASELINE_VERSION quotes none of them —
# each is quoted in uCRM by hand. QCRB_SAY carries the reading to the summary.
''', H("# the sheet). Notes, never a failure: the rollback rewrites no application, and 5.18.103 keeps each on the direct rules",
       "# exactly as 5.18.104 did — none is left to quote by hand because of it. QCRB_SAY carries the reading to the summary.") + "\n",
        why="RB's QC-RB2 comment")
    rep('''— $BASELINE_VERSION reads each as one from the app (P1, or P2 where cash was collected) and never quotes it: an admin quotes each in uCRM by hand, after checking the customer has none ($QC_RB_IDS)"; fi''',
        '''— 5.18.103 keeps each on the direct rules exactly as 5.18.104 did (retried by its uCRM ids; Make the quotation on its Orders card once the reason is put right); the rollback changes none of them and leaves none to quote by hand — an admin quotes one in uCRM only where its Orders card says so, after checking the customer has none ($QC_RB_IDS)"; fi''',
        why="RB's QC-RB2 note")
    rep('''# RB — 088 after the rollback: 5.18.103 added no migration and wrote none of 088's tables, and the rollback restores code
# only, so 088 stays exactly as stage A read it before the rollback (every row compared under this run's key, never
''', H("# RB — 088 after the rollback: 5.18.104 added 089–091 (none a bank table) and wrote none of 088's tables, and the",
       "# rollback restores code only — its only writes are QF-RB1's, before the put-back, none in 088's tables — so 088 stays",
       "# exactly as stage A read it before the rollback (every row compared under this run's key, never") + "\n", why="RB's 088 comment")
    i = doc.t.index('''note "RB the code is $BASELINE_VERSION again. The rollback restores code only.''')
    j = doc.t.index(" A number given to a technician keeps its owner (RB above).", i)
    if "\n" in doc.t[i:j] or doc.t.count('''note "RB the code is $BASELINE_VERSION again.''') != 1:
        raise SystemExit("C: RB's closing note is not one line")
    # DS5-fix1 (R01): what QF-RB1 did is said only where this run ran it — QF_RB1_START, as db_judge's RB clause reads it;
    # a --rollback that finds 5.18.103 already serving runs no QF-RB1 (and so prints no QF-RB2), only QF-RB3
    doc.t = doc.t[:i] + f'''note "RB the code is 5.18.103's again where 5.18.104 changed it. $( [ -n "${{QF_RB1_START:-}}" ] && echo "The rollback restores code only; before it, QF-RB1 turned every 5.18.104 switch off under the installed code (its hooks closed or paused their rows), and QF-RB3 above reads what the put-back left." || echo "This run put nothing back and ran no QF-RB1 (the container already served 5.18.103); QF-RB3 above reads whether every 5.18.104 switch is off.") $ADDED_LEFT of the $N_ADDED files $EXPECTED_VERSION adds are on disk (deploy-hybrid.sh never deletes; counted above), and 089's, 090's and 091's tables stay in the book: 5.18.103 reads none of them (QC-RB1$( [ -n "${{QF_RB1_START:-}}" ] && echo ", QF-RB2")). On Uganda the uCRM catalogue is 5.18.103's own and is left exactly as it was — its screen, its settings and its switch as an admin left them; 5.18.104 changes no file of it, and its page cache and last good copy keep 5.18.103's shape (UcrmQuoteLines::CACHE_SHAPE d103-1 at both commits), so 5.18.103 reads them as before. The KYC form, Send Quotation and the lead quotes offer what they offered under 5.18.104. The web-direct applications are handled by 5.18.103 as 5.18.104 handled them — ${{QCRB_SAY:-not counted}} (QC-RB2): the rollback adds none to quote in uCRM by hand; nothing is quoted at price 0. Quotes already made in uCRM are not changed.''' + doc.t[j:]
    doc.log.append(("RB's closing note: the catalogue 5.18.103's own, untouched", 1))
    doc.must('''note "RB the code is 5.18.103's again where 5.18.104 changed it.''', 1)
    doc.mustnot("the page cache itself is $EXPECTED_VERSION's shape", why="RB: the cache shape is 5.18.103's at both commits")
    doc.must('''changed it. $( [ -n "${QF_RB1_START:-}" ] && echo "The rollback restores code only; before it, QF-RB1 turned every 5.18.104 switch off''', 1,
             why="R01: QF-RB1's act said only where this run ran it")
    doc.must('''|| echo "This run put nothing back and ran no QF-RB1 (the container already served 5.18.103); QF-RB3 above reads whether every 5.18.104 switch is off.") $ADDED_LEFT''', 1,
             why="R01: a --rollback over 5.18.103 already serving says so")
    doc.must('''5.18.103 reads none of them (QC-RB1$( [ -n "${QF_RB1_START:-}" ] && echo ", QF-RB2")). On Uganda''', 1,
             why="R01: QF-RB2 cited only where QF-RB1 printed it")
    doc.mustnot("changed it. The rollback restores code only; before it, QF-RB1", why="R01: no unconditional claim that QF-RB1 ran")
    doc.mustnot("5.18.103 reads none of them (QC-RB1, QF-RB2)", why="R01: no unconditional QF-RB2 citation")

    # ════════════════ the summary: the rollback's meaning, the comments, the sheet, check it, the screens, the day ════════════════
    rep('''  rollback) echo "  serving           ${LIVE_NOW:-?}  (plugin $BASELINE_VERSION)"
            echo "  what it means     on Uganda, where the quoting organization is in force, the uCRM catalogue screen is gone, the Plans and Hardware screens are back with their uCRM link pickers, and the KYC form, Send Quotation and the lead quotes offer the plugin's own plans and kits again, priced through the uCRM item linked to each (RB); the web-direct applications $EXPECTED_VERSION made are never quoted by $BASELINE_VERSION — ${QCRB_SAY:-not counted} (QC-RB2): an admin quotes each in uCRM by hand, after checking the customer has none, their ids on this run's sheet; nothing is quoted at price 0. The uCRM catalogue's settings stay in the store, read by nothing (QC-RB1); a later deploy of $EXPECTED_VERSION finds them as they were. The Cashbook, ECO Bank, every number and the quoting organization stay as they are (RB)" ;;''',
        '''  rollback) echo "  serving           ${LIVE_NOW:-?}  (plugin $BASELINE_VERSION)"
            echo "  what it means     on Uganda the uCRM catalogue is 5.18.103's own and the rollback leaves it exactly as it was — its screen, its settings and its switch as an admin left them, the KYC form, Send Quotation and the lead quotes offering what they offered (RB); the web-direct applications stay on 5.18.103's direct rules, as under 5.18.104 — ${QCRB_SAY:-not counted} (QC-RB2$( [ -n "$QC_RB" ] && echo "; $QC_RB_IDS")): retried by their uCRM ids, Make the quotation on an Orders card where it applies; the rollback adds none to quote by hand, and nothing is quoted at price 0. 5.18.104's parts are off, and its files and 089–091's tables stay, read by nothing ($( [ -n "${QF_RB1_START:-}" ] && echo "QF-RB1–QF-RB3" || echo "QF-RB3"), QC-RB1). The Cashbook, ECO Bank, every number and the quoting organization stay as they are (RB)" ;;''',
        why="the rollback's summary: the catalogue 5.18.103's, untouched")
    # DS5-fix1 (R01): QF-RB1 and QF-RB2 are cited only where this run ran QF-RB1 (QF_RB1_START), as RB's closing note
    doc.must('''read by nothing ($( [ -n "${QF_RB1_START:-}" ] && echo "QF-RB1–QF-RB3" || echo "QF-RB3"), QC-RB1).''', 1,
             why="R01: what it means cites QF-RB1–QF-RB3 only where QF-RB1 ran")
    doc.mustnot("read by nothing (QF-RB1–QF-RB3, QC-RB1)", why="R01: no unconditional QF-RB1 citation")
    rep("    on|stageA|unknown) F_QD9=\"\" ;;   # 5.18.103: D9 (no Delivery PDF on Uganda) was 5.18.102's change; this release changes nothing webhook.php or post_kyc.php does",
        "    on|stageA|unknown) F_QD9=\"\" ;;   # 5.18.103 and 5.18.104: D9 (no Delivery PDF on Uganda) was 5.18.102's change; neither changes it — post_kyc.php is unchanged and 5.18.104's webhook.php hunks are P7's agent copy only",
        why="F_QD9's comment")
    rep('''  # as 5.18.102 does with it off (D7); otherwise NOT ESTABLISHED, and said so. The uCRM catalogue's switch as this run's
  # reading after the copy found it (qcsw: on only where an earlier, rolled-back run left it on or, in an --after-only run,
  # once an admin turned it on) decides F_SWW; --accept-unpriced is said where it decided QL-G1 (this run's QLG1_NOTED, or
''', H("  # as 5.18.102 does with it off (D7); otherwise NOT ESTABLISHED, and said so. The uCRM catalogue's switch as this run's",
       "  # reading after the copy found it (qcsw: as an admin last left it on the uCRM catalogue screen; this script never sets",
       "  # it) decides F_SWW; --accept-unpriced is said where it decided QL-G1 (this run's QLG1_NOTED, or") + "\n",
        why="F_SWW's comment")
    rep('''  echo "                    uCRM catalogue's settings, the ids of the waiting web applications (QC-N4) and, after a"
  echo "                    rollback, of the web-direct ones to quote by hand (QC-RB2), and the lines of the physical count"
''', '''  echo "                    uCRM catalogue's settings, the ids of the waiting web applications (QC-N4) and, after a"
  echo "                    rollback, of the web-direct ones with no quotation yet — a record only: 5.18.103, put back,"
  echo "                    handles each as before the deploy, through its own retry and its Orders card (QC-RB2) — and"
  echo "                    the lines of the physical count"
''', why="the sheet's description: QC-RB2 a record")
    rep('''  echo "  check it          $( [ -n "$F_QORG" ] && echo "with 5.18.103's uCRM catalogue on (an --after-only while it is off fails QC-ON1 by design, as under 5.18.103), ")at least 20 minutes after the copy''',
        '''  echo "  check it          at least 20 minutes after the copy''', why="check it: no switch step")
    rep('''when the evening WhatsApp (18:00 Kampala) has run on the new code: each its own command, alone, with its own log and its own cash sheet:"''',
        '''when the evening WhatsApp (18:00 Kampala) has run on the new code$( [ -n "$F_QORG" ] && echo " — the uCRM catalogue's switch is said as found (QC-ON1: ok while it is on, a note while it is off)"): each its own command, alone, with its own log and its own cash sheet:"''',
        why="check it: QC-ON1 as found")
    rep('''  echo "  the screens       none changes now: every switch of $EXPECTED_VERSION's sales follow-up is off, so every screen"
  echo "                    is as under $BASELINE_VERSION — the uCRM catalogue as it was, the Salesperson numbers card,"
  echo "                    the Cashbook and ECO Bank as before; the staff page My quotes and the offers screen open"
  echo "                    only once their steps are on. No screen of uCRM's changes. Do NOT make a test quote for a"
  echo "                    real customer: the plugin WhatsApps every new quote"
''', '''  if [ "$MODE" = "after" ]; then
  echo "  the screens       as the steps now on make them — My quotations once list is on, WhatsApp offers once"
  echo "                    offers-test, the Inbox's voice transcript once voice-staff, the quotation chases once chase;"
  echo "                    on Uganda, whatever the steps, the WhatsApp AI screen's OpenAI key field, which says set or"
  echo "                    not set and never shows the key (VN-K2); every other screen as under $BASELINE_VERSION: the"
  echo "                    uCRM catalogue as it was, the Salesperson numbers card, the Cashbook and ECO Bank as before."
  echo "                    No screen of uCRM's changes. Do NOT make a test quote for a real customer: the plugin"
  echo "                    WhatsApps every new quote"
  else
  echo "  the screens       none changes now but one: every switch of $EXPECTED_VERSION's sales follow-up is off (QF-CA5),"
  echo "                    so every screen is as under $BASELINE_VERSION — the uCRM catalogue as it was, the Salesperson"
  echo "                    numbers card, the Cashbook and ECO Bank as before — but, on Uganda, the WhatsApp AI screen's"
  echo "                    OpenAI key field, which says set or not set and never shows the key (VN-K2); the staff page"
  echo "                    My quotes and the offers screen open only once their steps are on. No screen of uCRM's"
  echo "                    changes. Do NOT make a test quote for a real customer: the plugin WhatsApps every new quote"
  fi
''', why="the screens: by mode")
    # DS5-fix1 (R02): the OpenAI key field changes on Uganda from the copy whatever the switches (the pin's
    # tabs/engage/whatsapp.php gates it by StaffJobsGate::applies, the tenant alone), so both branches name it
    wa = show(pin, "tabs/engage/whatsapp.php")
    if "$_aiKeyUg = StaffJobsGate::applies(" not in wa or "$_aiKeyUg" in show(base, "tabs/engage/whatsapp.php") \
            or "public static function applies(array $config, ?string $dataDir): bool" not in show(pin, "lib/StaffJobsGate.php"):
        raise SystemExit("C: the pin's OpenAI key field is not gated by StaffJobsGate::applies alone, new in 5.18.104")
    doc.must('''  echo "                    on Uganda, whatever the steps, the WhatsApp AI screen's OpenAI key field, which says set or"''', 1,
             why="R02: --after-only's screens name the OpenAI key field")
    doc.must("OpenAI key field, which says set or not set and never shows the key (VN-K2)", 1, why="R02: the deploy branch's, unchanged")
    rep('''  echo "                    today's, the day of this deploy — if you ran the report on another day, put that day in"''',
        '''  echo "                    today's, the day of this run — if you ran the report on another day, put that day in"''',
        why="the report's day: this run's")
    rep('''# one. Then the rollback, last, in its own block: one step — 5.18.102 put back; ECO Bank and its switch stay as they are.
# 5.18.103 (docs/72 §G.10): the block says what going back means on Uganda — the uCRM catalogue gone, the plugin's lists
# priced by their links again, the web-direct applications never quoted by 5.18.102 (each quoted in uCRM by hand; the
# rollback counts them, QC-RB2), nothing quoted at price 0, the catalogue's settings left in the store (QC-RB1). It needs no
# explicit choice: going back to 5.18.102 has no refusal of its own. The by-hand put-back counts nothing, and says so.
''', H("# one. Then the rollback, last, in its own block: QF-RB1 first (the installed tool's off all --rollback; it stops before",
       "# the put-back unless every part reads clean), then 5.18.103 put back and checked (QF-RB3); ECO Bank and its switch stay",
       "# as they are. 5.18.104 (root docs/73 §H.2.16): the block says what going back means — the uCRM catalogue exactly as",
       "# 5.18.103 has it, 089–091 kept (QF-RB2). It needs no explicit choice: going back to 5.18.103 has no refusal of its own.",
       "# The by-hand path runs no QF-RB1, so the tool's off all --rollback is printed first, its own command; the put-back",
       "# counts nothing, and says so.") + "\n", why="the rollback block's comment")

    # ════════════════ the header ════════════════
    LB = lambda a, b, new, why: doc.lines_between(a, b, new, why=why)
    LN = lambda a, new, why: doc.line(a, new, 1, why=why)
    LB("#            (QC-N4) and, after a rollback, the web-direct applications' ids to quote by hand (QC-RB2). The terminal and",
       "#            the log carry its path, its line",
       H("#            (QC-N4) and, after a rollback, the web-direct applications with no quotation made, by id (QC-RB2: a record",
         "#            only, nothing for the rollback to quote by hand — 5.18.103, put back, handles each exactly as it did before",
         "#            5.18.104, docs/72 G-10, §G.8). The terminal and the log carry its path, its line"), "the sheet's QC-RB2: a record")
    LN("#            file's own path, never from the live code, which has none), the INSTALLED ones after it — on such a copy, with",
       H("#            file's own path, never the live root's copy — the same file at 5.18.102, 5.18.103 and the pin), the INSTALLED",
         "#            ones after it, where no link is read (UcrmQuoteLinks only empty, under offered(); QL-CA1 and QL-LK1 retired",
         "#            since 5.18.103, QC-CA1 instead) — on such a copy, with"), "QL-G1's reader: the live root holds its own now")
    LB("#            fixed why codes only in the log (root docs/72 §G.13). The switch is OFF after the copy until an admin turns it",
       "#            on, so nothing is offered from uCRM before then and every count of what is offered is a note. Refusal before",
       H("#            fixed why codes only in the log (root docs/72 §G.13). The switch is as an admin last left it on the uCRM",
         "#            catalogue screen (turned on in production on 4 Nov 2026, under 5.18.103; root docs/07), and the copy changes",
         "#            nothing of it: every count of what is offered is a note. Refusal before"), "the switch premise")
    LB("#            under the gate. Notes, counts only: QC-G1's items not offered, by why; QC-N1 the switch as stored — said in",
       "#            web applications refused P1 with no quotation, quoted in uCRM by hand once the switch is on (G-10), their ids on",
       H("#            under the gate. Notes, counts only: QC-G1's items not offered, by why; QC-N1 the switch as stored — a note",
         "#            either way, never in capitals: on, as an admin set it on the uCRM catalogue screen (5.18.103's, byte for byte",
         "#            in 5.18.104; Uganda since 4 Nov 2026), agents already see direct mode and the copy changes nothing of it; off",
         "#            or never set, the forms quote as 5.18.103 does with it off; QC-N2 the installation product and the standard",
         "#            quotations, defined and usable now; QC-N3 the applications by entry, quote_sale_entry web, app, web-direct or",
         "#            none — F11's measure (§G.11); QC-N4 the web applications refused P1 with no quotation (quote_sale_entry web:",
         "#            the KYC form under 5.18.102, or under 5.18.103 or 5.18.104 while the switch is off) — with the switch on each",
         "#            is quoted in uCRM by hand (G-10), with it off Make the quotation applies once their items are linked; their",
         "#            ids on"),
       "QC-N1 and QC-N4 in the header")
    LB("#            beside the pin's. In an --after-only run, once an admin has turned the switch on (each a FAIL otherwise):",
       "#            (none defined, a note); QC-ON4 G-7 over the copy's web-direct records — package_choice and device_id empty, only",
       H("#            beside the pin's. In an --after-only run, the switch as found: on, QC-ON1 says so and QC-ON2 the installation",
         "#            product set and passing and QC-ON3 every standard quotation usable (none defined, a note) are judged; off or",
         "#            never set — an admin's choice — one note says QC-ON1–QC-ON3 are not judged; two records in force, or a switch",
         "#            not read, fails QC-ON1. Either way QC-ON4 G-7 over the copy's web-direct records — package_choice and device_id",
         "#            empty, only"), "QC-ON in the header")
    LN("#            ids on the sheet (a note: 5.18.102 never quotes them, an admin does by hand).",
       H("#            ids on the sheet (a note: the rollback changes none of them — 5.18.103, put back, handles each exactly as",
         "#            5.18.104 does, with the same KycService and UcrmQuoteLines: retried by its uCRM ids, Make the quotation where",
         "#            its Orders card offers it; an admin quotes one in uCRM by hand only where its own card says so)."), "QC-RB2 in the header")
    LN("#   5.18.102'S CHECKS, CARRIED (QL, \"quote lines\"), against 5.18.103's code: with the switch off 5.18.103 quotes through",
       H("#   5.18.102'S CHECKS, CARRIED (QL, \"quote lines\"), against 5.18.104's code (its UcrmQuoteLines and UcrmQuoteLinks",
         "#            5.18.103's byte for byte): with the switch off 5.18.104 quotes, as 5.18.103 does, through"), "QL's header")
    LB("#   A ROLLBACK TO 5.18.102 MEETS NO PRICE-0 HAZARD; IT COUNTS WHAT IT LEAVES UNQUOTED (docs/72 §G.10).",
       "#            rollback did not complete — 5.18.103 may still serve — and prints the by-hand put-back, which counts nothing.",
       H("#   A ROLLBACK TO 5.18.103 LEAVES THE uCRM CATALOGUE AS IT IS; IT COUNTS THE WEB-DIRECT APPLICATIONS FOR THE RECORD",
         "#            (docs/72 §G.8, G-10). 5.18.104 keeps every file of the catalogue byte for byte — lib/UcrmCatalogue.php and its",
         "#            screen, UcrmQuoteLines, QuoteOrganization, KycService — so the put-back copies the same files; its settings",
         "#            and its switch stay in the store as an admin left them, read by 5.18.103 as before. A web-direct application is",
         "#            handled by 5.18.103's own rules — KycService retries it by its uCRM ids, its Orders card offers Make the",
         "#            quotation where that applies — exactly as by 5.18.104's, so the rollback leaves none to quote in uCRM by hand.",
         "#            --rollback counts those with no quotation in stage A and again after the rollback, on a copy of the book, read-",
         "#            only (QC-RB2: counts in the log, their ids on the sheet) — a note, never a refusal; so does the deploy's own",
         "#            automatic rollback (V1), after it. A direct form still open in a browser is 5.18.103's own: with the switch on",
         "#            it is handled as a direct sale, with it off it is refused before anything is written (G-9). When V1's",
         "#            rollback does not verify, its stop says the rollback did not complete — 5.18.104 may still serve, or some",
         "#            installed files may already be 5.18.103's — and prints 5.18.103's by-hand put-back, which counts nothing."),
       "the rollback paragraph")
    LN("#            and 5.18.103 keep webhook.php byte for byte (docs/71 §L.4, docs/72 §G.12), so every reading is 5.18.101's",
       H("#            and 5.18.103 keep webhook.php byte for byte (docs/71 §L.4, docs/72 §G.12), and 5.18.104 adds only P7's 18",
         "#            lines in whQuotationEmail (root docs/73 §H.5.8.2), none of them the sign-off line, its old phrase or its",
         "#            gate, so every reading is 5.18.101's"), "QS's header: P7's 18 lines")
    LB("#            (TenantProfile::current()->id()). By the sha256 pinned here, Q-CA1 reads every file 5.18.103 changes or adds",
       "#            form: BR1 counts any left on disk, and QC-RB1 shows that nothing in 5.18.102 reaches them.",
       H("#            (TenantProfile::current()->id()). By the sha256 pinned here, Q-CA1 reads every file 5.18.104 changes or adds",
         f"#            (its {n_add + n_chg}, as the pin has them); Q-G1 before the copy, and RB after a rollback, read every file it changes",
         f"#            ({n_chg}), as 5.18.103 has them; all three read the {n_kept} files of 5.18.103 it must not touch — cron_quote_wa.php,",
         "#            lib/QuoteOrganization.php, lib/QuoteTaxLine.php, lib/TenantProfile.php, lib/PublicPriceFeed.php,",
         "#            lib/UcrmQuoteLinks.php, lib/UcrmQuoteLines.php and lib/UcrmCatalogue.php with its screen among them (not",
         f"#            webhook.php, which 5.18.104 changes for P7). The {n_add} files it adds have no 5.18.103 form: BR1 counts any",
         "#            left on disk, and QC-RB1 shows that nothing in 5.18.103 reaches them."), "the file sets")
    rep("#   5.18.100'S CHECKS, CARRIED, AGAINST 5.18.102'S CODE (TN,", "#   5.18.100'S CHECKS, CARRIED, AGAINST 5.18.103'S CODE (TN,", why="TN's header")
    rep("#            run. After a rollback (RB): the owner rule 5.18.100's, as 5.18.102 installs it, as text and on a copy, and the\n"
        "#            numbers a technician owns counted — they keep their owner and work under 5.18.102 as before.\n",
        "#            run. After a rollback (RB): the owner rule 5.18.100's, as 5.18.103 installs it, as text and on a copy, and the\n"
        "#            numbers a technician owns counted — they keep their owner and work under 5.18.103 as before.\n", why="TN's header")
    LB("#   5.18.99'S CHECKS, CARRIED, AGAINST 5.18.102'S CODE.", "#            5.18.102 files 5.18.103 must not touch by theirs,",
       H("#   5.18.99'S CHECKS, CARRIED, AGAINST 5.18.103'S CODE. Its quoting checks keep their ids (SPEC-quote-company §6): Q-G1 the",
         f"#            live plugin is 5.18.103 — the version, every file 5.18.104 changes ({n_chg}) by 221c9dc's sha256 (pinned here),",
         f"#            and the 5.18.103 files 5.18.104 must not touch ({n_kept}) by theirs, the quoting files it keeps (QuoteOrganization,"),
       "Q's header")
    rep("#            pinned here, the 5.18.102 files it must not touch 5.18.102's, and the server's PHP accepts every PHP file it\n",
        "#            pinned here, the 5.18.103 files it must not touch 5.18.103's, and the server's PHP accepts every PHP file it\n", why="Q-CA1's header")
    LN("#            QuoteOrganization.php is kept byte for byte — while 5.18.103 changes the quoting code around it; with the",
       H("#            QuoteOrganization.php is kept byte for byte — while 5.18.102, 5.18.103 and 5.18.104 change the quoting code",
         "#            around it; with the"), "QuoteOrganization kept since 5.18.99")
    rep("#   5.18.98'S CHECKS, CARRIED, AGAINST 5.18.102'S CODE. Its copy checks C1–C5, on ONE VACUUM INTO copy, 5.18.102's code and\n"
        "#            5.18.103's from Git (A23), then 5.18.102's from Git and the installed (CA2): C1 cash in hand per currency ×\n",
        "#   5.18.98'S CHECKS, CARRIED, AGAINST 5.18.103'S CODE. Its copy checks C1–C5, on ONE VACUUM INTO copy, 5.18.103's code and\n"
        "#            5.18.104's from Git (A23), then 5.18.103's from Git and the installed (CA2): C1 cash in hand per currency ×\n",
        why="the copy checks' header")
    LN("#            nothing there; what 5.18.103 writes at use is its own keys on Uganda applications (R7). G2 (rule A in force",
       H("#            nothing there; what the live code writes at use is the plugin's own (R7) — 5.18.102's and 5.18.103's keys on",
         "#            Uganda applications, 5.18.103's table ucrm_catalogue, and 5.18.104's rows once one of its writing switches is",
         "#            on. G2 (rule A in force"), "what the live code writes")
    rep("#            5.18.102 (1a0ea5f), every file it tracks byte for byte;", "#            5.18.103 (221c9dc), every file it tracks byte for byte;", why="BR1's header")
    rep("BK1 cash in hand under the installed 5.18.102\n", "BK1 cash in hand under the installed 5.18.103\n", why="BK1's header")
    LB("#   NONE OF THE SANDBOX TESTS RUNS ON THE SERVER; NOTHING IT RUNS IN THE CONTAINER OPENS A SOCKET BUT THE QUOTING AND",
       "#            (A24, R26), now 5.18.102's code against 5.18.103's, both with the gate; and the cash in hand of this server's",
       H("#   NONE OF THE SANDBOX TESTS RUNS ON THE SERVER BUT AC-CA2'S TWO FILES; NOTHING IT RUNS IN THE CONTAINER OPENS A SOCKET",
         "#            BUT THE QUOTING AND CATALOGUE CHECKS' GETS TO UCRM AND AC-CA2'S LOOPBACK RELAY — the pin's",
         "#            tests/fixtures/fake_smtp_server.php on 127.0.0.1, which tests/quote_agent_copy/drive.php sends to, both by",
         "#            sha256: no e-mail leaves the container. As 5.18.103: T1 (in process, synthetic books) from a code copy (A22-T1,",
         "#            R25); T4 (static) against the installed files (R24); South Sudan unchanged through the library on synthetic",
         "#            books (A24, R26), 5.18.103's code against 5.18.104's, both with the gate; and the cash in hand of this server's"),
       "the sandbox paragraph: AC-CA2's relay")
    LB("#            R23a–R23g; after a rollback, RB). 5.18.103's own test (tests/test_catalogue_direct.php and",
       "#            only; so do 5.18.102's, 5.18.101's, 5.18.100's and 5.18.99's.",
       H("#            R23a–R23g; after a rollback, RB). 5.18.104's own tests — tests/test_quote_followup.php,",
         "#            test_wa_send_budget.php, test_sales_followup_tool.php, test_master_104.php, test_sales_followup_report.php,",
         "#            Part V's voice tests, test_wa_offers.php and test_quote_agent_copy.php — run in the suites only, but AC-CA2's",
         "#            two files above; so do 5.18.103's (tests/test_catalogue_direct.php and tests/catalogue_direct/, on 5.18.102's",
         "#            fake uCRM, tests/fixtures/fake_ucrm_kyc.php, served on an HTTP server of its own), 5.18.102's, 5.18.101's,",
         "#            5.18.100's and 5.18.99's."), "the sandbox paragraph: 5.18.104's own tests")
    rep("#            own configuration, as 5.18.102's code and 5.18.103's both read it, or nothing is deployed (A23, G2).\n",
        "#            own configuration, as 5.18.103's code and 5.18.104's both read it, or nothing is deployed (A23, G2).\n", why="P0's header")
    LN("#            bank_screen_enabled among them (V3j), which must also read ON; the channel registry's rows among them (R11).",
       H("#            bank_screen_enabled among them (V3j), which must also read ON; the channel registry's rows among them (R11).",
         "#            One exception, V3g's: the sales follow-up's switch tool, where this run runs it (QF-G4's --clear-switches,",
         "#            QF-RB1), clears 5.18.104's own keys, Part V's ai_ keys among them — V3g names each it removed, a note."),
       "S2: the switches' header names V3g's exception")
    LB("#            changes or removes a uCRM link, or saves a plan or a kit (the run's opening line says so): turn the uCRM",
       "#            the --after-only's reading once the switch is on (QC-ON1–QC-ON4); the next real KYC quotation is the second.",
       H("#            changes or removes a uCRM link, or saves a plan or a kit (the run's opening line says so). The uCRM catalogue",
         "#            needs no step: 5.18.104 changes nothing of it, and its switch stays as an admin left it — under 5.18.103 its",
         "#            --after-only read QC-ON1–QC-ON3 once the switch was turned on (root docs/07); QC-N1, QC-CA1 and QC-ON1–QC-ON4",
         "#            are carried. Make no KYC registration and no quote to try the release: the plugin WhatsApps every new quote",
         "#            to its customer."), "the runbook: the catalogue needs no step")
    LB("#                        direction), QC-CA2, QC-CA3 and QC-ON1–QC-ON4 (the switch ON, the installation product set and",
       "#                        after the copy, and once more the next day after 15:00 UTC",
       H("#                        direction), QC-CA2, QC-CA3 and QC-ON1–QC-ON4 (the switch as found: on, QC-ON1 ok and the",
         "#                        installation product set and passing and every standard quotation usable judged; off, one note;",
         "#                        G-7 over the web-direct records either way); 088 applied and complete, its rows none fewer than",
         "#                        the deploy's; R19 conclusive once master has run the retry job after the copy; its own cash",
         "#                        sheet. Run it at least 20 minutes after the copy, once more the next day after 15:00 UTC, and",
         "#                        after each step of the ladder"), "--after-only's option: QC-ON as found")
    rep("#   --move-list-only     read-only, before the deploy, while 5.18.102 is live:", "#   --move-list-only     read-only, before the deploy, while 5.18.103 is live:",
        why="--move-list-only")
    LN("#                        operator's explicit choice (Q-G3) — 5.18.103 then quotes as 5.18.102 does with it off (D7), and",
       H("#                        operator's explicit choice (Q-G3) — 5.18.104 then quotes as 5.18.103 does with it off (no quoting",
         "#                        organization, D7; the uCRM catalogue lives under it and offers nothing, docs/72 §G.10), and"),
       "--quote-company-off")
    LN("# clear or verify anything — bank_screen_enabled, ucrm_quote_organization_id and wa_inbox_roles included; add, give,",
       H("# clear or verify anything — bank_screen_enabled, ucrm_quote_organization_id and wa_inbox_roles included — but the",
         "# switch tool's off all --rollback (QF-G4's --clear-switches on a redeploy, after DEPLOY, with the pin's tool; QF-RB1",
         "# in --rollback and V1, with the installed tool), which clears 5.18.104's own keys only (never a setting such as",
         "# sf_persona_confirmed or quote_agent_cc_admins) and whose off-hooks write rows (follow-ups closed, drafts expired,",
         "# offers paused, recipients settled, audio rows settled); add, give,"), "what it never does: the switch tool's off")
    LB("# anything; call Evolution, Splynx or a model; open a socket in the container but the quoting and catalogue checks' GETs",
       "# to uCRM (its other requests are V's, from the host to the plugin's own public address, as in 5.18.102).",
       H("# anything but AC-CA2's drive to its own loopback relay; call Evolution, Splynx or a model; open a socket in the container",
         "# but the quoting and catalogue checks' GETs to uCRM and AC-CA2's loopback relay (the pin's",
         "# tests/fixtures/fake_smtp_server.php on 127.0.0.1: no e-mail leaves the container) — its other requests are V's, from",
         "# the host to the plugin's own public address, as in 5.18.103."), "what it never does: AC-CA2's relay")
    LB("# the state file, the cash sheets and the run's copies in the container's /tmp (removed), and the time of the copy on the",
       "# files this release changes or adds.",
       H("# the state file, the cash sheets and the run's copies in the container's /tmp (removed), the time of the copy on the",
         "# files this release changes or adds, and the switch tool's off all --rollback where QF-G4's --clear-switches or QF-RB1",
         "# runs it — 5.18.104's own keys cleared, its off-hooks' rows."), "the writes: the switch tool's")
    LB("# Stages:  A before-evidence: the release delta by its pinned sha256, no migration (A0, A7);",
       "#            version, every file of it (BR1), every file 5.18.103 changes and the 5.18.102 files it keeps by their pinned",
       H("# Stages:  A before-evidence: the release delta by its pinned sha256 and exactly its three migrations, 089, 090 and",
         "#            091, each added and the reviewed file by sha256 and md5 (A0); Domain B untouched (A7); 5.18.95's fixes in",
         "#            the pin and in the live code, as text, each with its control on 5.18.94's copy (A22a–A22g, A22L); the live",
         "#            commit and version, every file of it (BR1), every file 5.18.104 changes and the 5.18.103 files it keeps by",
         "#            their pinned"), "Stage A: the delta and its migrations")
    LN("#            copy's catalogue settings, GET-only (QC-G1), and its notes (QC-N1–QC-N7); the backup, the event queue's",
       H("#            copy's catalogue settings, GET-only (QC-G1), and its notes (QC-N1–QC-N7); 5.18.104's own — QF-G1–QF-G4,",
         "#            QF-N1–QF-N7, VN-G1–VN-G7, OF-G1, OF-G2, OF-N1–OF-N3, AC-L1, AC-N1 and AC-N2; the backup, the event queue's"),
       "Stage A: 5.18.104's own codes")
    LN("#          B the documented deploy (or, with --rollback, once ROLLBACK is typed, the documented deploy of 5.18.102); the",
       H("#          B the documented deploy (or, with --rollback, once ROLLBACK is typed, QF-RB1 — the installed tool's off all",
         "#            --rollback, every key read back, a failure stopping before the put-back — then the documented deploy of",
         "#            5.18.103); the"), "Stage B: QF-RB1 first")
    LN("#          V as 5.18.102: the public pages and the anonymous refusals (V1–V13, BA4, CA4) — a deploy whose public sign-in",
       H("#          V as 5.18.103 (V1's automatic rollback runs QF-RB1 first, and QF-RB3 once verified): the public pages and the",
         "#            anonymous refusals (V1–V13, BA4, CA4) — a deploy whose public sign-in"), "Stage V")
    LB("#          R 5.18.103 installed: R1 the files byte for byte and the version;",
       "#            beside 086 and 087: BA1, every row unchanged; R7 the database's ledger unchanged — no migration — and its size;",
       H("#          R 5.18.104 installed: R1 the files byte for byte and the version; Q-CA1 the release by its pinned sha256,",
         "#            5.18.103's kept files by theirs, php -l on every PHP file it changes or adds; R2–R22 as 5.18.103 but R7 and",
         "#            R13 (R3 with 088 beside 086 and 087: BA1, every row unchanged; R7 the database's ledger as stage A read it or,",
         "#            on the first boot after the copy, that with exactly 089, 090 and 091 added by the plugin's own runner, 091 last",
         "#            with its reviewed checksum (db_mig104; QF-CA1 judges each by its _schema_checks row), and its size; R13 the",
         "#            event processor at 5.18.104's own Uganda signature, ai.media among its workers' events;"), "Stage R")
    LB("#          RB after a rollback: 5.18.102's files by sha256, the files 5.18.103 adds on disk and the uCRM catalogue's table",
       "#            quarantine, the numbers a technician owns counted, and the earlier surfaces as before",
       H("#          RB after a rollback (QF-RB1, and QF-RB2's note, before the put-back): 5.18.103's files by sha256 where 5.18.104",
         f"#            changed them (Q-G1's set of {n_chg}; QF-RB3: master with none of the four jobs, no 5.18.104 key, no open quote",
         f"#            follow-up), the {n_add} files 5.18.104 adds on disk and 089–091's tables left in place, reached by nothing",
         "#            (QC-RB1, re-pointed; QF-RB2), the uCRM catalogue's table counted, 5.18.103's own screen's and read by it again",
         "#            (a note), the web-direct applications with no quotation counted again, ids on the sheet (QC-RB2, a record —",
         "#            none newly to quote by hand), its sign-off line, its owner rule (as text and on a copy) and its quoting code",
         "#            in place, the list's split and ECO Bank in place, D2 = A on a copy and both cards still with a figure, its",
         "#            fixes still in place, its documents, the database, 088's tables and kept rows untouched, the switch unchanged,",
         "#            the quarantine, the numbers a technician owns counted, and the earlier surfaces as before"), "Stage RB")
    LB("#          F summary: which of the checks each name is (5.18.103's own checks,", "#            block",
       H("#          F summary: which of the checks each name is (5.18.104's own first — QF-G1–QF-G4, QF-N1–QF-N7, QF-CA1–QF-CA8,",
         "#            QF-ON0–QF-ON5, QF-RB1–QF-RB3; VN and VA, Part V's; OF, Part O's; AC, Part P's — then 5.18.103's, carried,",
         "#            QC-G1, QC-N1–QC-N7, QC-CA1–QC-CA3, QC-ON1–QC-ON4, QC-RB1, QC-RB2; 5.18.102's, QL-G1, QL-N1–QL-N7, QL-CA2,",
         "#            QL-CA3; QS-G1, QS-CA1; TN-G1–TN-G3, TN-N1, TN-N2, TN-CA1–TN-CA3; Q-G1–Q-G6, Q-N1–Q-N7, Q-CA1–Q-CA6); the cash",
         "#            sheet's path; --after-only and the quarantine's --check, each its own command with its own log; the screens",
         "#            (none changes while every switch is off) and the operator's steps — the report",
         "#            (scripts/sales-followup-report.sh), then each step of the ladder through scripts/sales-followup.sh, its",
         "#            --check, then --after-only; the rollback last, after the verdict, its own block"), "Stage F")
    rep("# 5.18.103 (release, the uCRM catalogue; the live plugin since 4 Nov, root docs/07) —",
        "# 5.18.103 (release, the uCRM catalogue; the live plugin since 3 Nov 09:01:02 UTC, its catalogue switch turned ON by an admin on 4 Nov 04:13 UTC — root docs/07) —",
        why="BASELINE_COMMIT's comment: deployed 3 Nov, switched on 4 Nov")
    rep("# 5.18.103: the names of its own checks, said once — QC-G1 (a refusal before the copy), QC-N1–QC-N7 (notes), QC-CA1 (after\n"
        "# the copy), QC-CA2 and QC-CA3 (stage V), QC-ON1–QC-ON4 (an --after-only run once the switch is on), QC-RB1 and QC-RB2 (after\n",
        "# 5.18.103: the names of its own checks, said once — QC-G1 (a refusal before the copy), QC-N1–QC-N7 (notes), QC-CA1 (after\n"
        "# the copy), QC-CA2 and QC-CA3 (stage V), QC-ON1–QC-ON4 (an --after-only run, the switch as found), QC-RB1 and QC-RB2 (after\n",
        why="the names' comment: QC-ON as found")

    # ════════════════ DS5-fix1 (R03): fixed 5.18.104 facts are literals, never $EXPECTED_VERSION ════════════════
    # The four sentences below state what 5.18.104 itself does (its Inbox change, its media layer, its four master jobs,
    # the web-direct records it carries); the release they name is fixed, so each names it as a literal (V104, asserted
    # off the pin's manifest above) and none reads $EXPECTED_VERSION in the clause that states the fact.
    for new, old, why in [
        (f"{V104} does not change how the Inbox routes or refuses a reply (lib/InboxReplyRoute.php is kept byte for byte); its one Inbox change, on Uganda only,",
         "$EXPECTED_VERSION does not change how the Inbox routes", "A3's note"),
        (f"is installed — {V104}'s own media layer (Part V: workers/MediaWorker.php and its classes, behind switches that ship off) is installed and pinned by sha256",
         "$EXPECTED_VERSION's own media layer", "R4's ok"),
        (f"its own lock, none of the four jobs {V104} adds to it (a rollback's copy puts it back);",
         "none of the four jobs $EXPECTED_VERSION adds", "RB's master ok"),
        (f"the uCRM catalogue's web-direct records, which {V104} carries unchanged and adds none to — among it: R7, QC-RB2)",
         "which $EXPECTED_VERSION carries unchanged and adds none to", "the summary's what did not"),
    ]:
        doc.must(new, 1, why=f"R03: {why} names 5.18.104 as a literal")
        doc.mustnot(old, why=f"R03: {why} does not state a fixed 5.18.104 fact through $EXPECTED_VERSION")
