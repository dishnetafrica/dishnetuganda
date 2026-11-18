# Part Z — rules over the whole result, last of the parts (root docs/75 §L; 5.18.105's partZ carried, re-pointed). One
# edit: partUJ's slot U5 closed (partR filled it; ctx["as_slot_before"] above it). Then Z1–Z17 over the whole text, every
# failure reported together, and weakened copies of the result, each refused by its own rule alone (every rule has one).
# Z1 the switch tool: show/--check/doctor only; the wrapper and the tool's verbs only echoed. Z2 set_config --key in
# code: Q-CA6's throwaway run and OWNER_AUTOSEND_CMD's text alone. Z3 the rollback to 7835906: rb_pre106 just before
# ROLLBACK, the prompt saying what AS-RB1 writes, rb106 from V1 and RB alone, review D1 (RB_NOTHING). Z4 the one write
# (§L.7): as_rb1's saveOverrides, followup_auto_send '0' alone, -u "$DB_OWNER", the installed tree; as_rb1 typed and in
# V1, each before do_rollback; OWNER_AUTOSEND_CMD assigned once, in as_owner_cmd, and only printed. Z5 retired names
# (rb_pre105, rb105, RB105: DL9). Z6 --clear-switches refused on its own echo line. Z7 retired codes silent; new codes
# printed. Z8 replaces 5.18.105's D3 rule (§L.6): no "numbers again", "WRITES PHONE" or step-12 notice. Z9 the pipe rule
# (carried). Z10 no slot left. Z11 NOT YET (§L.5, DL3): UJ-ON's alone, three states, exit 3. Z12 ME-CA1's NOT
# ESTABLISHED four notes (DL10). Z13 stage R's order. Z14 no 5.18.105 identity; 994d660 only as LM-G1's control. Z15
# printed commands never adjacent; the rollback's after the verdict (root docs/44 §16.9). Z16 the summary (§L.8): §F's
# steps, ECO Bank's guard, check it. Z17 partT's and partH's texts. Retired: 5.18.105's edits (in the source now; Z9
# asserts its here-strings) and its D3 rule (Z8).
import re
from d106lib import close

SLOT = "as_u5   # U5"
V1 = ('echo "  ROLLING BACK — the public address must never redirect (every switch of the sales follow-up kept;'
      ' followup_auto_send set off first, AS-RB1)"; as_rb1 v1; if do_rollback')
WRITE = "saveOverrides($dd, ['followup_auto_send' => '0'])"
AS_EXEC = 'docker exec -u "$DB_OWNER" -e AS_ROOT="$IN_CONTAINER"'
DISPLAY = re.compile(r'^\s*(?:echo|ok|bad|note|stop|hdr|notyet)\b')
SAYS = re.compile(r'\b(?:ok|bad|note|stop|echo|warn|notyet)\s+"')
CMD = re.compile(r'\becho "      \S')       # a printed command: six spaces, then the command
TXT = re.compile(r'\becho "\s*[^\s"]')       # a printed line with text
HCMD = re.compile(r"#   (?:cd |\( umask )")  # a command in the header
QUIET = re.compile(r"(?<!\|)\|\s*grep\s+(?:-[A-Za-z]+\s+)*-[A-Za-z]*q")
PIPES = [("  if printf '%s\\n' \"$o\" | grep -q '^== sales follow-up report (READ-ONLY)'; then",
          "  if grep -q '^== sales follow-up report (READ-ONLY)' <<<\"$o\"; then"),
         ("  for v in $QF_SC_EXPECT; do printf '%s\\n' \"$o\" | grep -qx \"PASS ${v%%:*}\" || bad=\"$bad doctor:${v%%:*}\"; done",
          "  for v in $QF_SC_EXPECT; do grep -qx \"PASS ${v%%:*}\" <<<\"$o\" || bad=\"$bad doctor:${v%%:*}\"; done"),
         ("  printf '%s\\n' \"$o\" | grep -qE '^089 names: 38 of 38 present$' || bad=\"$bad doctor:089-names\"",
          "  grep -qE '^089 names: 38 of 38 present$' <<<\"$o\" || bad=\"$bad doctor:089-names\""),
         ("  if printf '%s\\n' \"$v\" | grep -qx \"090_wa_voice.sql=$VN_090_SC/$VN_090_SC/$VN_090_SC\" \\",
          "  if grep -qx \"090_wa_voice.sql=$VN_090_SC/$VN_090_SC/$VN_090_SC\" <<<\"$v\" \\")]
PASSED = 'if [ "$FAIL" = "0" ] && [ "$NOT_YET" = "0" ]; then echo; echo "  $EXPECTED_VERSION ($MODE): PASSED.'
NY_ELIF = '\nelif [ "$FAIL" = "0" ]; then\n'
FAILED = '\nelse echo; echo "  $EXPECTED_VERSION ($MODE): $FAIL FAILED'
OWN_IF = 'if [ "$MODE" = "after" ] && [ "$FAIL" = "0" ] && [ "$NOT_YET" = "0" ]; then\n'
ECO_IF = 'if [ "$MODE" != "rollback" ] && [ "$FAIL" = "0" ] && [ "$NOT_YET" = "0" ] && [ -n "${V3J_WBB:-}" ]; then\n'
AFTER = '  echo "      $AFTER_CMD"'
ROLLBACK = '  echo "      $ROLLBACK_CMD"'
PUTBACK = '  echo "      $PUTBACK_CMD"\n  as_show "$(as_para sum)"'
STAGE_R = ("\nlm_after   # LM-CA1", "\nme_ca1\n", "\nuj_after   # UJ-CA (notes) and, in --after-only, UJ-ON (judged)\n",
           '\n  qf_on "${ICODE_R:-}"\n')
STEPS = ("--key followup_daily_cap --value '<CAP>'", 'bash $QF_WRAPPER on chase"', 'bash $QF_WRAPPER --check chase"',
         "--key followup_run_limit --value '<LIMIT>'", "\n  as_back_on   #")
CODE_RETIRED = ("qf_rb1", "qf_clear_and_baseline", "qf_byhand", "CLEAR_SWITCHES", "QF_RB1_START", "db_mig104", "QF_G4",
                "QF_CLEAR", "ac_rb_pre", "ac_rb1", "of_rb1", "vn_rb1", "AC_RB_ADMINS", "F_QG1", "off all")
RETIRED_CODES = ("QF-G4", "QF-RB1", "QF-RB2", "QF-RB3", "VN-RB1", "VN-RB2", "VN-RB3", "VN-RB4", "VN-RB6", "OF-RB1", "AC-RB1")
NEW_CODES = ("LK-G1 LK-B1 LK-CA1 LK-CA2 LK-ON LK-AO LK-RB0 LK-RB1 LM-G1 LM-G2 LM-CA1 LM-CA2 LM-ON LM-RB1 RB106 CS-G1 CS-B1 "
             "U1 U2 U3 U4 U5 UJ-CA UJ-ON ME-CA1 AS-RB0 AS-RB1 AS-RB2").split()
ONCE = {   # found exactly once
    "Z3": ('BASELINE_COMMIT="7835906"', "rb_pre106() {\n", "rb106() {\n"),
    "Z4": (WRITE, AS_EXEC, '\n    as_rb1 typed\n    if do_rollback; then ok "container serves $BASELINE_COMMIT ($BASELINE_VERSION)"'),
    "Z9": tuple(p[1] for p in PIPES),
    "Z11": ("notyet() { NOT_YET=$((NOT_YET+1)); printf '  wait  %s\\n' \"$*\"; }", "notyet() {", "return 3",
            "PASS=0; FAIL=0; NOTE=0; NOT_YET=0", '$( [ "$NOT_YET" = "0" ] || echo ", $NOT_YET not yet")', PASSED, NY_ELIF,
            'echo; echo "  $EXPECTED_VERSION (after-only): NOT YET — ', FAILED,
            '[ "$FAIL" = "0" ] || return 1      # FAILED: exit status 1\n[ "$NOT_YET" = "0" ] || return 3   # 5.18.106 '
            '(root docs/75 §L.5): NOT YET, exit status 3; PASSED 0\n}\n\nmain "$@"; exit $?'),
    "Z14": ('RELEASE_BRANCH="release/5.18.106"', 'OUT="${DNB_OUT:-/root/dnb-5.18.106}"',
            "bash scripts/deploy-5.18.106.sh 2>&1 | ( umask 077; exec tee /root/dnb-5.18.106/deploy-",
            "bash scripts/deploy-5.18.106.sh --after-only 2>&1 | ( umask 077; exec tee /root/dnb-5.18.106/after-"),
    "Z15": (ROLLBACK, 'ROLLBACK_CMD="cd $REPO && bash scripts/deploy-$EXPECTED_VERSION.sh --rollback $(LOGW rollback)"'),
    "Z16": (OWN_IF, ECO_IF, STEPS[0], STEPS[3]),
    "Z17": ("# ── 5.18.105: the ladder rule (LK; root docs/74 §H.4.3) — every ladder switch kept exactly as found ",
            "  hdr \"A. The sales follow-up (5.18.104, Part F's QF, kept live by 5.18.105's LK; 5.18.106's CS-G1 and U1–U5; "
            "read-only, on copies through the pin's code)\"\n", "hdr \"R. The masked lines, installed (5.18.105's LM, kept by "
            "$EXPECTED_VERSION;"),
}
NONE = {   # found nowhere
    "Z5": ("rb_pre105", "rb105", "RB105", "old lines back"),
    "Z9": tuple(p[0] for p in PIPES),
    "Z10": ("# ── SLOT ", "SLOT U5"),
    "Z14": ("deploy-5.18.105.sh", "/root/dnb-5.18.105/lk-key", "release/5.18.105, is cut", "5.18.104 must be the live plugin",
            "deploy 5.18.105 again", "while 5.18.104 is live"),
    "Z15": ("deploy-5.18.106.sh --rollback",),
    "Z17": ("5.18.103's code and 5.18.104's", "5.18.103 classes (G3)", "rollback target (5.18.104 here)",
            "the installed plugin is live 5.18.104", "the live plugin is 5.18.104", "every file 994d660 tracks",
            "continue under 994d660", "994d660's own, which carry on", "5.18.105 adds no migration",
            "5.18.104 does not change cash in hand", "5.18.104 changes no figure", "rollback target, 5.18.104",
            "which this rollback leaves", "5.18.105's files are 5.18.104's again", "5.18.105 never runs the switch tool",
            "$EXPECTED_VERSION's LM", "5.18.105 keeps every switch as found", "writes nothing to the book",
            "reached by nothing", "read by nothing", "5.18.105'S OWN CHECKS", "AGAINST 5.18.103'S CODE",
            "AGAINST 5.18.104'S CODE", "the documented deploy of 5.18.104", "5.18.105's own first"),
}
COUNTS = [("Z12", "ME-CA1 NOT ESTABLISHED — ", 4), ("Z16", '[ "$NOT_YET" = "0" ]', 5), ("Z16", AFTER, 4)]


def _code(t):
    return [(n, l) for n, l in enumerate(t.split("\n"), 1) if not l.lstrip().startswith(("#", "//"))]


def _body(t, name):
    m = re.search(r"(?ms)^" + re.escape(name) + r"\(\) \{\n(.*?)^\}", t)
    return m.group(1) if m else ""


def _ordered(t, marks, start=0):
    p = [t.find(m, start) for m in marks]
    return min(p) >= 0 and p == sorted(p)


def rules(t):
    """Every rule over the text t: [(rule, why)], empty when all hold."""
    bad = []
    B = lambda z, m: bad.append((z, m))
    L, CL = t.split("\n"), _code(t)
    for z, ss in ONCE.items():
        for s in ss:
            if t.count(s) != 1:
                B(z, f"{s.strip()[:60]!r} found {t.count(s)} times, not once")
    for z, ss in NONE.items():
        for s in ss:
            if s in t:
                B(z, f"{s.strip()[:60]!r} is in the text")
    for z, s, n in COUNTS:
        if t.count(s) != n:
            B(z, f"{s.strip()[:60]!r} found {t.count(s)} times, not {n}")
    # Z1 the switch tool
    calls = [(n, l) for n, l in CL if "qf_tool " in l and "qf_tool()" not in l]
    if len(calls) < 8:
        B("Z1", f"{len(calls)} qf_tool calls")
    for n, l in calls:
        v = re.findall(r'qf_tool "\$[A-Za-z0-9_]+(?:/ss)?" ([A-Za-z-]+)', l)
        if not v or set(v) - {"show", "--check", "doctor"}:
            B("Z1", f"line {n}: qf_tool {v or l.strip()[:60]}")
    for n, l in CL:
        if not l.lstrip().startswith("echo ") and ("$QF_WRAPPER" in l or re.search(
                r"sales[-_]followup(\.php|\.sh)?\"? (on|off|skip|set|erase)\b", l)):
            B("Z1", f"line {n}: the wrapper or the switch tool's verb outside printed text")
    # Z2 set_config with --key
    runs = [l for _, l in CL if re.search(r'set_config\.php"?\s+--key\b', l) and not DISPLAY.match(l)]
    q6 = [l for l in runs if "--key ucrm_quote_organization_id --value disabled" in l and 'DN_DATA_DIR="$d"' in l]
    oc = [l for l in runs if re.match(r"\s*OWNER_AUTOSEND_CMD=", l) and "--key followup_auto_send --value $v 2>&1" in l]
    if len(runs) != 2 or len(q6) != 1 or len(oc) != 1:
        B("Z2", f"{len(runs)} set_config runs with --key in code, not Q-CA6's and OWNER_AUTOSEND_CMD's alone")
    # Z3 the rollback
    pre = [m.start() for m in re.finditer(r"(?m)^\s+rb_pre106\b(?!\(\))", t)]
    if len(pre) != 1 or not 0 < t.find("Type ROLLBACK", pre[0]) - pre[0] < 400:
        B("Z3", "rb_pre106 is not called once, just before ROLLBACK is typed")
    pr = [l for _, l in CL if l.lstrip().startswith("printf '  Type ROLLBACK to put")]
    if len(pr) != 1 or "AS_RB0_SAY" not in pr[0]:
        B("Z3", "the ROLLBACK prompt does not say what AS-RB1 will write")
    if len(re.findall(r"(?m)^(?:rb106[ \t]*$|.*then rb106 )", t)) != 2:
        B("Z3", "rb106 is not called from V1 and RB alone")
    body = t[t.find("rb106() {\n"):]
    body = body[:body.find("\n}\n")]
    a = body.find('\n  if [ -n "$RB_NOTHING" ]; then\n')
    e = body.find("\n  else\n", a)
    f = body.find('\n  fi\n  o="$(qf_php rbn "$PDD_IN")"\n', e)
    if min(a, e, f) < 0 or body.count("RB_NOTHING") != 1 \
            or 'note "LK-RB1 not judged: this run rolled nothing back' not in body[a:e] \
            or re.search(r'\b(ok|bad) "LK-RB1', body[a:e]) \
            or len(re.findall(r'\b(?:ok|bad) "LK-RB1', body)) != len(re.findall(r'\b(?:ok|bad) "LK-RB1', body[e:f])) \
            or len(re.findall(r'\bbad "LK-RB1', body[e:f])) != 7:
        B("Z3", "review D1: rb106 does not branch once on RB_NOTHING, a plain note, before its seven LK-RB1 verdicts")
    sets = [l.strip() for _, l in CL if "RB_NOTHING=" in l]
    if len(sets) != 2 or sets[0] != 'RB_NOTHING=""' or not sets[1].startswith("else RB_NOTHING=1; DEPLOY_STARTED="):
        B("Z3", "RB_NOTHING is not initialised once and set once, on the nothing-to-roll-back branch")
    rbm = t.find('elif [ "$MODE" = "rollback" ]; then\n  hdr "B. The rollback')
    if rbm < 0 or not _ordered(t, ("    rb_pre106   #", "\n    as_rb1 typed\n", '    if do_rollback; then ok "container serves',
                                   "  else RB_NOTHING=1;", "\nelse\n"), rbm):
        B("Z3", "--rollback is not rb_pre106, ROLLBACK, AS-RB1, do_rollback, the nothing-to-roll-back branch, in order")
    # Z4 the one write
    w = [l for _, l in CL if re.search(r"saveOverrides\s*\(", l) and not DISPLAY.match(l)]
    rb1 = _body(t, "as_rb1")
    if len(w) != 1 or WRITE not in w[0] or WRITE not in rb1 or rb1.count(AS_EXEC) != 1:
        B("Z4", f"{len(w)} saveOverrides in code; the one write is not as_rb1's, as -u \"$DB_OWNER\" with the installed tree")
    ac = [l for _, l in CL if re.search(r"(?<![\w-])as_rb1\b(?!\(\))", l)]
    if len(ac) != 2 or "    as_rb1 typed" not in ac or not any(V1 in l for l in ac):
        B("Z4", f"as_rb1 called {len(ac)} times, not typed and by V1 alone, each before its do_rollback")
    asg = [l for _, l in CL if re.match(r"\s*OWNER_AUTOSEND_CMD=", l)]
    use = [l for _, l in CL if "OWNER_AUTOSEND_CMD" in l and l not in asg]
    if len(asg) != 1 or asg[0] not in _body(t, "as_owner_cmd") or len(use) != 1 \
            or not use[0].lstrip().startswith("printf ' Your own command, alone, with its own log:") \
            or '"$OWNER_AUTOSEND_CMD"' not in use[0] or re.search(r"\beval\b|\bsh -c\b|\|", use[0]):
        B("Z4", "OWNER_AUTOSEND_CMD is not assigned once, in as_owner_cmd, and expanded once, as as_owner_text's printf argument")
    # Z5 retired names in code
    for s in CODE_RETIRED:
        n = [k for k, l in CL if s in l]
        if n:
            B("Z5", f"the retired {s} in code, line(s) {n[:4]}")
    # Z6 --clear-switches
    cs = [(n, l) for n, l in CL if "--clear-switches" in l]
    if len(cs) != 2 or not cs[0][1].startswith("    --clear-switches)   #") or cs[1][0] != cs[0][0] + 1 \
            or not cs[1][1].startswith('      echo "--clear-switches: refused') or not cs[1][1].endswith(">&2; exit 64 ;;"):
        B("Z6", "--clear-switches in code is not the parser's label and its refusal on the next line, exit 64")
    # Z7 the codes
    for c in RETIRED_CODES:
        n = [k for k, l in CL if c in l and SAYS.search(l) and "retire" not in l]
        if n:
            B("Z7", f"the retired {c} printed, line(s) {n[:4]}")
    for c in NEW_CODES:
        if not any(c in l and SAYS.search(l) for _, l in CL):
            B("Z7", f"{c} is printed by no check")
    # Z8 no text says the masked lines write numbers again
    for p in (r"numbers( in)? again", "WRITES PHONE", "step-12 notice"):
        if re.search(p, t, re.I):
            B("Z8", f"{p!r} survives")
    # Z9 pipelines
    q = [n for n, l in CL if QUIET.search(l)]
    if q or not any(re.search(r"\|\s*drained\s+grep\s+-[A-Za-z]*q", l) for _, l in CL) \
            or sum(1 for _, l in CL if re.search(r"grep\s+-[A-Za-z]*q[^|]*<<<", l)) < 28:
        B("Z9", f"a quiet grep reads from a pipe (lines {q[:6]}), or the greps the rule allows are not found")
    # Z10 U5 where its slot stood
    u5 = [k for k, l in enumerate(L) if l.startswith("  " + SLOT)]
    if len(u5) != 1 or L[u5[0] + 1:u5[0] + 2] != ['  cih_drop "$sub"']:
        B("Z10", "U5's reading is not where its slot stood, right before qf_stage's cih_drop")
    # Z11 NOT YET
    ny = [l for _, l in CL if re.search(r'(?<![\w-])notyet\s+"', l)]
    nyu = [l for l in _body(t, "uj_on").split("\n") if not l.lstrip().startswith("#") and re.search(r'(?<![\w-])notyet\s+"', l)]
    if not ny or len(ny) != len(nyu) or not re.search(r"(?m)^notyet\(\) \{", t):
        B("Z11", f"notyet called {len(ny)} times, {len(nyu)} by UJ-ON: NOT YET is UJ-ON's alone")
    i, j = t.find(NY_ELIF), t.find(FAILED)
    br = t[i:j] if 0 <= i < j else ""
    if not _ordered(t, (PASSED, NY_ELIF, FAILED)) or [l for l in br.split("\n") if CMD.search(l)] != [AFTER] \
            or re.search(r"QF_WRAPPER|set_config|ROLLBACK_CMD|PUTBACK_CMD|OWNER_AUTOSEND|as_back_on", br):
        B("Z11", "the summary is not PASSED, NOT YET, FAILED in order, the NOT YET branch printing --after-only alone")
    # Z12 ME-CA1
    for n, l in enumerate(L, 1):
        if "ME-CA1 NOT ESTABLISHED" in l and not l.lstrip().startswith("#") and 'note "ME-CA1 NOT ESTABLISHED — ' not in l:
            B("Z12", f"line {n}: ME-CA1's NOT ESTABLISHED is not a note")
    if not re.search(r'\bbad "ME-CA1 ', t):
        B("Z12", "ME-CA1 has no FAIL")
    # Z13 stage R
    if not _ordered(t, STAGE_R) or any(t.count(s) != 1 for s in STAGE_R) or any(
            sum(1 for _, l in CL if re.match(r"\s*" + f + r"\b(?!\(\))", l)) != 1 for f in ("lm_after", "me_ca1", "uj_after", "qf_on")):
        B("Z13", "stage R is not lm_after, me_ca1, uj_after, qf_on, in order, each called once")
    # Z14 identity
    for m in re.finditer(r"/root/dnb-5\.18\.105|release/5\.18\.105", t):
        if not (t.startswith("/root/dnb-5.18.105/state-5.18.105.env", m.start()) or t.startswith("release/5.18.105 (7835906)", m.start())):
            B("Z14", f"a 5.18.105 identity: {t[m.start():m.start() + 50]!r}")
    c994 = [l for _, l in CL if "994d660" in l]
    if not L[2].startswith("# deploy-5.18.106.sh — ") or len(c994) != 1 \
            or not c994[0].startswith('LM_CONTROL_COMMIT="994d660628cc5b1b42bee648b44b58e389f44bf2"'):
        B("Z14", "the title is not 5.18.106's, or 994d660 is in code beyond LM-G1's control")
    # Z15 printed commands: never two with no text between; the rollback's after the verdict
    def adj(k, lo, hi, is_text, is_cmd):
        for d in (-1, 1):
            q = k + d
            while lo <= q < hi and not is_text(L[q]):
                q += d
            if lo <= q < hi and is_cmd(L[q]):
                B("Z15", f"lines {k + 1} and {q + 1}: two printed commands with no text between")
    st, he = t.find("# Every command printed here stands alone on its line"), t.find("\nset -uo pipefail\n")
    s0, h0 = t.count("\n", 0, st), t.count("\n", 0, he)
    sc = [k for k in range(s0, len(L)) if re.match(r'\s*echo "      \S', L[k])] if st > 0 else []
    hc = [k for k in range(h0) if HCMD.match(L[k])] if he > 0 else []
    for k in sc:
        adj(k, s0, len(L), lambda l: not l.lstrip().startswith("#") and TXT.search(l), lambda l: CMD.search(l))
    for k in hc:
        adj(k, 0, h0, lambda l: re.match(r"#\s*\S", l), lambda l: HCMD.match(l))
    if len(sc) < 10 or len(hc) < 2 or not _ordered(t, (FAILED, ROLLBACK, PUTBACK)):
        B("Z15", f"{len(sc)} printed commands in the summary, {len(hc)} in the header, or the rollback's not after the verdict")
    # Z16 the summary's obligations
    i = t.find(OWN_IF)
    blk = t[i:t.find("\nfi\n", i)] if i >= 0 else ""
    if not _ordered(blk, STEPS) or any(blk.count(s) != 1 for s in STEPS) \
            or any(("set_config.php" in l or "QF_WRAPPER" in l) and not l.lstrip().startswith('echo "') for l in blk.split("\n")) \
            or sum(1 for _, l in CL if re.match(r"\s*as_back_on\b(?!\(\))", l)) != 1:
        B("Z16", "§F's steps are not printed once, in order, after a PASSED --after-only alone, as_back_on last")
    if not _ordered(t, (FAILED, "\nas_after_verdict   # 5.18.106", OWN_IF, ECO_IF, PUTBACK, '\nmain "$@"; exit $?')):
        B("Z16", "after the verdict: not as_after_verdict, §F's steps, ECO Bank's block, the put-back by hand, in order")
    ck = t.find('  echo "  check it          --after-only, three runs')
    if ck < 0 or t[ck:t.find('  echo "      $QCHECK_CMD"', ck)].count(AFTER) != 3:
        B("Z16", "check it does not print --after-only three times before the quarantine's --check")
    return bad


def _rep(t, old, new):
    if t.count(old) != 1:
        raise SystemExit(f"PART Z CONTROL: its anchor is found {t.count(old)} times: {old[:80]!r}")
    return t.replace(old, new)


def _at(t, test, f):
    L = t.split("\n")
    k = [i for i, l in enumerate(L) if test(l)]
    if len(k) != 1:
        raise SystemExit(f"PART Z CONTROL: its line is found {len(k)} times")
    return "\n".join(L[:k[0]] + f(L[k[0]]) + L[k[0] + 1:])


ME = "\nme_ca1() {\n"
ins = lambda s: (lambda t: _rep(t, ME, ME + s + "\n"))
CONTROLS = [   # (rule, what, the weakened copy)
    ("Z1", "the switch tool's on in code", ins('  qf_tool "$sub" on chase')),
    ("Z2", "set_config with another key",
     ins('  docker exec -u "$DB_OWNER" "$CONTAINER" php "$IN_CONTAINER/tools/set_config.php" --key followup_enabled --value 0')),
    ("Z3", "rb_pre106 not called", lambda t: _at(t, lambda l: l.startswith("    rb_pre106   # "), lambda l: [])),
    ("Z4", "a second saveOverrides", ins("  dxo php -r 'PluginConfig::saveOverrides(getenv(\"DN_DATA_DIR\"), [\"followup_enabled\" => \"0\"]);'")),
    ("Z4", "the write without -u", lambda t: _rep(t, AS_EXEC, 'docker exec -e AS_ROOT="$IN_CONTAINER"')),
    ("Z4", "V1's write after its do_rollback",
     lambda t: _rep(t, V1, V1.replace("; as_rb1 v1; if do_rollback", "; if do_rollback; then as_rb1 v1; fi; if do_rollback"))),
    ("Z4", "OWNER_AUTOSEND_CMD run",
     lambda t: _at(t, lambda l: re.match(r"\s*OWNER_AUTOSEND_CMD=", l), lambda l: [l, '  eval "$OWNER_AUTOSEND_CMD"'])),
    ("Z5", "rb_pre105 named", lambda t: _rep(t, "\nrb_pre106() {\n", "\n# rb_pre105\nrb_pre106() {\n")),
    ("Z6", "the refusal goes on",
     lambda t: _at(t, lambda l: l.startswith('      echo "--clear-switches: refused'), lambda l: [l.replace("; exit 64 ;;", " ;;")])),
    ("Z7", "CS-B1 printed by nothing", lambda t: t.replace("CS-B1", "CS-BX")),
    ("Z8", "numbers again", ins('  note "LM-RB1 THE MASKED LINES WRITE PHONE NUMBERS AGAIN"')),
    ("Z9", "a judged grep from a pipe", lambda t: _rep(t, PIPES[0][1], PIPES[0][0])),
    ("Z10", "slot U5 left open", lambda t: _at(t, lambda l: l.startswith("  " + SLOT), lambda l: [l, "  # ── SLOT U5 ── open ──"])),
    ("Z11", "NOT YET exits 0", lambda t: _rep(t, '[ "$NOT_YET" = "0" ] || return 3', '[ "$NOT_YET" = "0" ] || return 0')),
    ("Z11", "notyet outside UJ-ON", ins('  notyet "ME-CA1 waits"')),
    ("Z11", "an owner's step in the NOT YET branch",
     lambda t: _rep(t, NY_ELIF, NY_ELIF + '  echo "      cd $REPO && bash $QF_WRAPPER on chase"\n')),
    ("Z12", "a NOT ESTABLISHED made a FAIL",
     lambda t: _rep(t, 'note "ME-CA1 NOT ESTABLISHED — THE POSITIVE', 'bad "ME-CA1 NOT ESTABLISHED — THE POSITIVE')),
    ("Z13", "ME-CA1 after UJ-CA", lambda t: _rep(_rep(t, STAGE_R[1], "\n"), STAGE_R[2], STAGE_R[2] + "me_ca1\n")),
    ("Z13", "ME-CA1 twice", lambda t: _rep(t, STAGE_R[1], STAGE_R[1] + "me_ca1\n")),
    ("Z14", "the run's folder 5.18.105's",
     lambda t: _rep(t, 'OUT="${DNB_OUT:-/root/dnb-5.18.106}"', 'OUT="${DNB_OUT:-/root/dnb-5.18.105}"')),
    ("Z14", "994d660 the baseline in code",
     lambda t: _rep(t, 'BASELINE_COMMIT_FULL="7835906a0e4af49557f7c0eb994cb9129637ca00"',
                    'BASELINE_COMMIT_FULL="994d660628cc5b1b42bee648b44b58e389f44bf2"')),
    ("Z15", "the rollback next to --after-only", lambda t: _rep(t, AFTER + "\n  echo\n", AFTER + "\n" + ROLLBACK + "\n  echo\n")),
    ("Z16", "ECO Bank's block after a NOT YET", lambda t: _rep(t, ECO_IF, ECO_IF.replace(' && [ "$NOT_YET" = "0" ]', ""))),
    ("Z16", "§F's steps out of order",
     lambda t: _rep(_rep(_rep(t, STEPS[0], "\0"), STEPS[3], STEPS[0].replace("CAP", "LIMIT")), "\0", STEPS[3].replace("LIMIT", "CAP"))),
    ("Z17", "a re-pointed text back", lambda t: _rep(t, "\nrb_pre106() {\n", "\n# the live plugin is 5.18.104\nrb_pre106() {\n")),
]


def apply(doc, ctx):
    if ctx.get("as_slot_before") != SLOT:
        raise SystemExit(f"PART Z: partR's slot line is {ctx.get('as_slot_before')!r}, not {SLOT!r}")
    close(doc, "U5", SLOT, why="slot U5 closed (partR's U5 above it)")
    bad = rules(doc.t)
    if bad:
        raise SystemExit(f"PART Z: {len(bad)} refusal(s): " + "; ".join(f"{z}: {m}" for z, m in bad[:30]))
    for z, what, weak in CONTROLS:
        got = rules(weak(doc.t))
        if sorted({g for g, _ in got}) != [z]:
            raise SystemExit(f"PART Z CONTROL ({what}): refused for {sorted({g for g, _ in got}) or 'nothing'}, not {z} alone: {got[:3]}")
    if sorted({c[0] for c in CONTROLS}) != sorted(f"Z{k}" for k in range(1, 18)):
        raise SystemExit("PART Z: a rule has no weakened copy")
    ctx.update(z_rules=17, z_controls=len(CONTROLS))
