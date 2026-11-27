# Part Z — rules over the whole result, last of the parts (root docs/76 §T.8; 5.18.106's partZ carried where its subject
# survives, re-pointed). No edit: Z1–Z20 over the whole text, every failure reported together, then weakened copies of the
# result, each refused by its own rule alone (every rule has at least one).
#   Z1  the switch tool: show, --check and doctor only through qf_tool; $QF_WRAPPER and the tool's verbs only on echo lines
#       and in the three top-level display variables (BD_OFF_CMD, BD_DRAIN_CMD, W1_ON_CMD).
#   Z2  set_config with --key in code: Q-CA6's throwaway run and owner_cmds' four assignments alone (§T.8).
#   Z3  the rollback: rb_pre107 called once, just before ROLLBACK is typed; the prompt says nothing is written; rb107 from V1
#       and RB alone; RB_NOTHING initialised once and set once; --rollback in its order.
#   Z4  no writer (§T.7): no saveOverrides or writeKycFile in code; the one ->save( is SLR_PHP's throwaway store.
#   Z5  retired names in code (5.18.104's, 5.18.105's and 5.18.106's machinery).
#   Z6  --clear-switches: the parser's label and its refusal on the next line, exit 64.
#   Z7  every code of 5.18.107's own and of 5.18.105's carried is printed by some check; 5.18.104's retired ones are not.
#   Z8  no text says the masked lines write numbers again (5.18.105's D3, carried).
#   Z9  no judged grep reads a pipe; the here-strings 5.18.105 introduced stay (31 at 5.18.107).
#   Z10 no slot left.
#   Z11 NOT YET: ME-CA1's alone (notyet called once, in me_say); the summary PASSED, NOT YET, FAILED in order, the NOT YET
#       branch printing --after-only alone; exit status 3.
#   Z12 ME-CA1's NOT ESTABLISHED a note (twice); ME-CA1 has a FAIL.
#   Z13 stage R: mg_after, qf_after, lm_after, me_ca1, qf_on, in order, each called once.
#   Z14 identity (§T.8): the title 5.18.107's, its branch and folder; 994d660 in code only as LM_CONTROL_COMMIT; 7835906
#       nowhere in code, and in printed text only as the report wrapper's pin; no 5.18.106 identity but its state files and
#       its release commit's sha256 lists.
#   Z15 printed commands: never two with no text between, in the header and the summary; the rollback's after the verdict.
#   Z16 the summary's obligations: the owner's steps (§K: K, Q, W.1) once each, in order, after a PASSED --after-only alone;
#       after the verdict, the owner's steps, ECO Bank's block, the rollback, the put-back by hand, in order; check it
#       prints --after-only twice before the quarantine's --check.
#   Z17 the parts' texts that pin 5.18.107's reading of the carried ones (partT's and partH's), and none of the old.
#   Z18 §T.8's names that do not survive, over the text with every echo continuation joined, so a phrase split across two
#       echo lines is seen whole.
#   Z19 an apostrophe in a ${NAME:-word} default only paired and with no $ between a pair (bash 5.2 reads a quote there).
#   Z20 the named display variables (§T.8; partDV): each assigned once, and expanded only on a line that prints it.
import re

DISPLAY = re.compile(r'^\s*(?:echo|ok|bad|note|stop|hdr|notyet)\b')
SAYS = re.compile(r'\b(?:ok|bad|note|stop|echo|warn|notyet)\s+"')
CMD = re.compile(r'\becho "      \S')       # a printed command: six spaces, then the command
TXT = re.compile(r'\becho "\s*[^\s"]')       # a printed line with text
HCMD = re.compile(r"#   (?:cd |\( umask )")  # a command in the header
QUIET = re.compile(r"(?<!\|)\|\s*grep\s+(?:-[A-Za-z]+\s+)*-[A-Za-z]*q")
HERE = "  if grep -q '^== sales follow-up report (READ-ONLY)' <<<\"$o\"; then"
PIPE = "  if printf '%s\\n' \"$o\" | grep -q '^== sales follow-up report (READ-ONLY)'; then"
PASSED = 'if [ "$FAIL" = "0" ] && [ "$NOT_YET" = "0" ]; then echo; echo "  $EXPECTED_VERSION ($MODE): PASSED.'
NY_ELIF = '\nelif [ "$FAIL" = "0" ]; then\n'
FAILED = '\nelse echo; echo "  $EXPECTED_VERSION ($MODE): $FAIL FAILED'
OWN_IF = 'if [ "$MODE" = "after" ] && [ "$FAIL" = "0" ] && [ "$NOT_YET" = "0" ]; then\n'
ECO_IF = 'if [ "$MODE" != "rollback" ] && [ "$FAIL" = "0" ] && [ "$NOT_YET" = "0" ] && [ -n "${V3J_WBB:-}" ]; then\n'
AFTER = '  echo "      $AFTER_CMD"'
QCHECK = '  echo "      $QCHECK_CMD"'
ROLLBACK = '  echo "      $ROLLBACK_CMD"'
PUTBACK = '  printf \'%s\\n\' "$(pb_para sum)"\n  echo "      $PUTBACK_CMD"'
STAGE_R = ('\nmg_after "$(icode_build icode-mg "$EXPECTED_PLUGIN_COMMIT" 2>/dev/null || true)"   #', '\nqf_after "${ICODE_R:-}"\n',
           "\nlm_after   # LM-CA1", "\nme_ca1\n", '\n  qf_on "${ICODE_R:-}"\n')
STEPS = ('  echo "      $K_ON_CMD"', '  echo "      $Q_ON_CMD"', '  echo "      $W1_ON_CMD"')
NOTYET_CALL = 'notyet "ME-CA1 NOT YET — $why$w; $c; run it again after $ME_NY_AT"'
V1_RB = '        if [ "$QCV1_RB" = "ok" ]; then rb107   # RB107'
DISPLAY_VARS = ("BD_OFF_CMD", "BD_DRAIN_CMD", "W1_ON_CMD", "K_OFF_CMD", "Q_OFF_CMD", "K_ON_CMD", "Q_ON_CMD")
TOP_DV = ('BD_OFF_CMD="cd $REPO && bash $QF_WRAPPER set ai_burst_merge_enabled 0"',
          'BD_DRAIN_CMD="cd $REPO && bash $QF_WRAPPER --check burst-drain"',
          'W1_ON_CMD="cd $REPO && bash $QF_WRAPPER set ai_burst_merge_enabled 1"')
CODE_RETIRED = ("qf_rb1", "qf_clear_and_baseline", "qf_byhand", "CLEAR_SWITCHES", "QF_RB1_START", "db_mig104", "QF_G4",
                "QF_CLEAR", "ac_rb_pre", "ac_rb1", "of_rb1", "vn_rb1", "AC_RB_ADMINS", "F_QG1", "off all", "owner_set_cmd",
                "as_rb1", "as_owner_cmd", "as_owner_text", "as_back_on", "as_after_verdict", "as_para", "as_show", "as_read",
                "as_u5", "uj_stage", "uj_after", "uj_on", "uj_fe_g1", "uj_judgeable", "uj_gate_a", "uj_u4", "cs_check",
                "cs_found", "cs_has", "UJ_A", "UJ_ON_GATE", "UJ_NY_SAY", "UJ_NY_AT", "AS_RB0_SAY", "AS_RB2_EFF", "rb_pre105",
                "rb105")
RETIRED_CODES = ("QF-G4", "QF-RB1", "QF-RB2", "QF-RB3", "VN-RB1", "VN-RB2", "VN-RB3", "VN-RB4", "VN-RB6", "OF-RB1", "AC-RB1")
NEW_CODES = ("MG-G1 MG-CA1 MG-CA2 NA-G1 NA-CA1 NS-G1 NS-B1 ME-CA1 ME-CA2 RB-X RB107 LK-G1 LK-B1 LK-CA1 LK-CA2 LK-ON LK-AO "
             "LK-RB0 LK-RB1 LM-G1 LM-G2 LM-CA1 LM-CA2 LM-ON LM-RB1").split()
T8_GONE = ("CS-G1", "CS-B1", "FE-G1", "UJ-", "AS-RB", "OWNER_AUTOSEND_CMD", "rb_pre106", "rb106", "RB106", "adds no migration",
           "applies or removes no migration", "(the event processor, NotificationService", "reached by nothing",
           "read by nothing")
ONCE = {   # found exactly once
    "Z3": ('BASELINE_COMMIT="bf29cbd"', "rb_pre107() {\n", "rb107() {\n", 'RB_NOTHING=""\n'),
    "Z9": (HERE,),
    "Z11": ("notyet() { NOT_YET=$((NOT_YET+1)); printf '  wait  %s\\n' \"$*\"; }", "notyet() {", "return 3",
            "PASS=0; FAIL=0; NOTE=0; NOT_YET=0", '$( [ "$NOT_YET" = "0" ] || echo ", $NOT_YET not yet")', PASSED, NY_ELIF,
            'echo; echo "  $EXPECTED_VERSION (after-only): NOT YET — ${ME_NY_SAY:-ME-CA1 above}; run it again after '
            '${ME_NY_AT:-the next master cycle}"', FAILED,
            '[ "$FAIL" = "0" ] || return 1      # FAILED: exit status 1\n[ "$NOT_YET" = "0" ] || return 3   # 5.18.106 '
            '(root docs/75 §L.5): NOT YET, exit status 3; PASSED 0\n}\n\nmain "$@"; exit $?'),
    "Z14": ('RELEASE_BRANCH="release/5.18.107"', 'OUT="${DNB_OUT:-/root/dnb-5.18.107}"',
            "bash scripts/deploy-5.18.107.sh 2>&1 | ( umask 077; exec tee /root/dnb-5.18.107/deploy-",
            "bash scripts/deploy-5.18.107.sh --after-only 2>&1 | ( umask 077; exec tee /root/dnb-5.18.107/after-",
            "(P1; its wrapper pinned to 7835906, 5.18.105's"),
    "Z15": (ROLLBACK, 'ROLLBACK_CMD="cd $REPO && bash scripts/deploy-$EXPECTED_VERSION.sh --rollback $(LOGW rollback)"'),
    "Z16": (OWN_IF, ECO_IF) + STEPS,
    "Z17": ("# BR1 — since 5.18.97 (SPEC §2.10b R1): the installed plugin is live 5.18.106 — every file bf29cbd tracks",
            "# Q-G1 — 5.18.99 (SPEC-quote-company §6; 5.18.98's G1, carried): the live plugin is 5.18.106 —",
            "#   5.18.107'S OWN CHECKS (root docs/76 §T.3–§T.7).", "deploy-5.18.107.sh — on Uganda, the quotation built in code",
            "PERSONA_PHP does neither (root docs/76 §T.14, DF-1)", "  echo \"  the owner's steps root docs/76 §K — "),
}
NONE = {   # found nowhere
    "Z9": (PIPE,),
    "Z10": ("# ── SLOT ",),
    "Z14": ("deploy-5.18.106.sh", "/root/dnb-5.18.106/lk-key", "release/5.18.106, is cut", "5.18.105 must be the live plugin",
            "deploy 5.18.106 again", "while 5.18.105 is live", "/root/dnb-5.18.106/deploy-", "/root/dnb-5.18.106/after-"),
    "Z15": ("deploy-5.18.107.sh --rollback 2>&1",),
    "Z17": ("the installed plugin is live 5.18.105", "the live plugin is 5.18.105", "every file 7835906 tracks",
            "continue under 7835906", "rollback target (5.18.105 here)", "rollback target, 5.18.105", "5.18.106 changes nothing of",
            "5.18.106 does not change cash in hand", "5.18.106 changes no figure", "5.18.106 changes no file of the Cashbook's",
            "5.18.106'S OWN CHECKS (root docs/75", "AGAINST 5.18.105'S CODE", "the documented deploy of 5.18.105",
            "5.18.106's own first", "root docs/75 §F", "<CAP>", "<LIMIT>", "which 5.18.105 and 5.18.106 keep byte for byte",
            "copies 5.18.105's files", "5.18.106 changes neither lib/BrainContext.php"),
}
COUNTS = [("Z12", "ME-CA1 NOT ESTABLISHED — ", 2), ("Z16", '[ "$NOT_YET" = "0" ]', 5), ("Z16", AFTER, 3)]
NRULES = 20


def _code(t):
    return [(n, l) for n, l in enumerate(t.split("\n"), 1) if not l.lstrip().startswith(("#", "//"))]


def _body(t, name):
    m = re.search(r"(?ms)^" + re.escape(name) + r"\(\) \{\n(.*?)^\}", t)
    return m.group(1) if m else ""


def _ordered(t, marks, start=0):
    p = [t.find(m, start) for m in marks]
    return min(p) >= 0 and p == sorted(p)


def _joined(t):
    """the text with every echo continuation joined: a line ending `"` then a line `echo "  …` read as one"""
    return re.sub(r'"\n[ \t]*echo "[ \t]*', " ", t)


def _defaults(l):
    """the words of the ${NAME:-word} (and :=, :+, :?) expansions on a line, one level of nesting read through"""
    return [m.group(1) for m in re.finditer(r"\$\{[A-Za-z_][A-Za-z0-9_]*:[-=+?]((?:[^{}]|\{[^{}]*\})*)\}", l)]


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
        if l.strip() in TOP_DV or l.lstrip().startswith("echo "):
            continue
        if "$QF_WRAPPER" in l or re.search(r"sales[-_]followup(\.php|\.sh)?\"? (on|off|skip|set|erase)\b", l):
            B("Z1", f"line {n}: the wrapper or the switch tool's verb outside printed text and the display variables")
    # Z2 set_config with --key
    oc = _body(t, "owner_cmds")
    runs = [l for _, l in CL if re.search(r'set_config\.php"?\s+--key\b', l) and not DISPLAY.match(l)]
    q6 = [l for l in runs if "--key ucrm_quote_organization_id --value disabled" in l and 'DN_DATA_DIR="$d"' in l]
    dv = [l for l in runs if re.match(r"\s*(K_OFF_CMD|Q_OFF_CMD|K_ON_CMD|Q_ON_CMD)=\"docker exec -u \$DB_OWNER ", l) and l in oc]
    if len(runs) != 5 or len(q6) != 1 or len(dv) != 4:
        B("Z2", f"{len(runs)} set_config runs with --key in code, not Q-CA6's and owner_cmds' four alone")
    # Z3 the rollback
    pre = [m.start() for m in re.finditer(r"(?m)^\s+rb_pre107\b(?!\(\))", t)]
    if len(pre) != 1 or not 0 < t.find("Type ROLLBACK", pre[0]) - pre[0] < 400:
        B("Z3", "rb_pre107 is not called once, just before ROLLBACK is typed")
    pr = [l for _, l in CL if l.lstrip().startswith("printf '  Type ROLLBACK to put")]
    if len(pr) != 1 or "nothing written, every switch of the sales follow-up kept as it is" not in pr[0]:
        B("Z3", "the ROLLBACK prompt does not say that nothing is written")
    if len(re.findall(r"(?m)^(?:rb107[ \t]*$|.*then rb107 )", t)) != 2 or t.count(V1_RB) != 1:
        B("Z3", "rb107 is not called from V1 and RB alone")
    sets = [l.strip() for _, l in CL if "RB_NOTHING=" in l]
    if len(sets) != 2 or sets[0] != 'RB_NOTHING=""' or not sets[1].startswith("else RB_NOTHING=1; DEPLOY_STARTED="):
        B("Z3", "RB_NOTHING is not initialised once and set once, on the nothing-to-roll-back branch")
    rbm = t.find('elif [ "$MODE" = "rollback" ]; then\n  hdr "B. The rollback')
    if rbm < 0 or not _ordered(t, ("    rb_pre107   #", "printf '  Type ROLLBACK to put", '    if do_rollback; then ok "container serves',
                                   "  else RB_NOTHING=1;", "\nelse\n"), rbm):
        B("Z3", "--rollback is not rb_pre107, ROLLBACK, do_rollback, the nothing-to-roll-back branch, in order")
    # Z4 no writer
    w = [(n, l) for n, l in CL if re.search(r"saveOverrides\s*\(|writeKycFile\s*\(", l) and not DISPLAY.match(l)]
    sv = [(n, l) for n, l in CL if "->save(" in l and not DISPLAY.match(l)]
    if w or len(sv) != 1 or "$s = SqliteStore::create($__live); $s->save(\"kyc_config.json\"" not in sv[0][1]:
        B("Z4", f"a writer in code (lines {[n for n, _ in w + sv][:4]}): no saveOverrides, no writeKycFile, one ->save( (SLR_PHP's)")
    # Z5 retired names in code
    for s in CODE_RETIRED:
        n = [k for k, l in CL if re.search(r"(?<![\w-])" + re.escape(s) + r"(?![\w])", l)]
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
    hs = sum(1 for _, l in CL if re.search(r"grep\s+-[A-Za-z]*q[^|]*<<<", l))
    if q or not any(re.search(r"\|\s*drained\s+grep\s+-[A-Za-z]*q", l) for _, l in CL) or hs < 31:
        B("Z9", f"a quiet grep reads from a pipe (lines {q[:6]}), or {hs} here-strings, not 31 or more")
    # Z11 NOT YET
    ny = [l.strip() for _, l in CL if re.search(r'(?<![\w-])notyet\s+"', l)]
    if ny != [NOTYET_CALL] or NOTYET_CALL not in _body(t, "me_say"):
        B("Z11", f"notyet called {len(ny)} time(s), not once in me_say: NOT YET is ME-CA1's alone")
    i, j = t.find(NY_ELIF), t.find(FAILED)
    br = t[i:j] if 0 <= i < j else ""
    if not _ordered(t, (PASSED, NY_ELIF, FAILED)) or [l for l in br.split("\n") if CMD.search(l)] != [AFTER] \
            or re.search(r"QF_WRAPPER|set_config|ROLLBACK_CMD|PUTBACK_CMD|_ON_CMD|_OFF_CMD|BD_DRAIN_CMD", br):
        B("Z11", "the summary is not PASSED, NOT YET, FAILED in order, the NOT YET branch printing --after-only alone")
    # Z12 ME-CA1
    for n, l in enumerate(L, 1):
        if "ME-CA1 NOT ESTABLISHED" in l and not l.lstrip().startswith("#") and 'note "ME-CA1 NOT ESTABLISHED — ' not in l:
            B("Z12", f"line {n}: ME-CA1's NOT ESTABLISHED is not a note")
    if not re.search(r'\bbad "ME-CA1 ', t):
        B("Z12", "ME-CA1 has no FAIL")
    # Z13 stage R
    if not _ordered(t, STAGE_R) or any(t.count(s) != 1 for s in STAGE_R) or any(
            sum(1 for _, l in CL if re.match(r"\s*" + f + r"\b(?!\(\))", l)) != 1
            for f in ("mg_after", "qf_after", "lm_after", "me_ca1", "qf_on")):
        B("Z13", "stage R is not mg_after, qf_after, lm_after, me_ca1, qf_on, in order, each called once")
    # Z14 identity
    for m in re.finditer(r"/root/dnb-5\.18\.106|release/5\.18\.106", t):
        if not (t.startswith("/root/dnb-5.18.106/state-5.18.106.env", m.start()) or t.startswith("release/5.18.106 (bf29cbd)", m.start())):
            B("Z14", f"a 5.18.106 identity: {t[m.start():m.start() + 50]!r}")
    c994 = [l for _, l in CL if "994d660" in l]
    c783 = [l for _, l in CL if "7835906" in l and not l.lstrip().startswith("echo ")]
    p783 = [l for _, l in CL if "7835906" in l and l.lstrip().startswith("echo ")]
    if not L[2].startswith("# deploy-5.18.107.sh — ") or len(c994) != 1 \
            or not c994[0].startswith('LM_CONTROL_COMMIT="994d660628cc5b1b42bee648b44b58e389f44bf2"') or c783 \
            or len(p783) != 1 or "its wrapper pinned to 7835906" not in p783[0]:
        B("Z14", "the title is not 5.18.107's, 994d660 is in code beyond LM-G1's control, or 7835906 beyond the report's pin")
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
        adj(k, s0, len(L), lambda l: not l.lstrip().startswith("#") and (TXT.search(l) or "printf '%s\\n' \"$(pb_para" in l),
            lambda l: CMD.search(l))
    for k in hc:
        adj(k, 0, h0, lambda l: re.match(r"#\s*\S", l), lambda l: HCMD.match(l))
    if len(sc) < 10 or len(hc) < 2 or not _ordered(t, (FAILED, ROLLBACK, PUTBACK)):
        B("Z15", f"{len(sc)} printed commands in the summary, {len(hc)} in the header, or the rollback's not after the verdict")
    # Z16 the summary's obligations
    i = t.find(OWN_IF)
    blk = t[i:t.find("\nfi\n", i)] if i >= 0 else ""
    if not _ordered(blk, STEPS) or any(blk.count(s) != 1 for s in STEPS) \
            or any(("set_config.php" in l or "QF_WRAPPER" in l) and not l.lstrip().startswith('echo "') for l in blk.split("\n")):
        B("Z16", "§K's steps are not printed once each, in order, after a PASSED --after-only alone")
    if not _ordered(t, (FAILED, OWN_IF, ECO_IF, ROLLBACK, PUTBACK, '\nmain "$@"; exit $?')):
        B("Z16", "after the verdict: not the owner's steps, ECO Bank's block, the rollback, the put-back by hand, in order")
    ck = t.find('  echo "  check it          --after-only, each run its own command')
    if ck < 0 or t[ck:t.find(QCHECK, ck)].count(AFTER) != 2:
        B("Z16", "check it does not print --after-only twice before the quarantine's --check")
    # Z18 §T.8's names
    jt = _joined(t)
    for s in T8_GONE:
        if s in jt:
            B("Z18", f"{s!r} survives (echo continuations joined)")
    # Z19 apostrophes in a default word
    for n, l in CL:
        for word in _defaults(l):
            k = word.count("'")
            if k and (k % 2 or re.search(r"'[^']*\$[^']*'", word)):
                B("Z19", f"line {n}: an apostrophe bash 5.2 reads as a quote in ${{…:-{word[:40]}…}}")
    # Z20 the display variables
    for v in DISPLAY_VARS:
        a = [l for _, l in CL if re.match(rf"\s*{v}=", l)]
        u = [l for _, l in CL if re.search(rf"\${{?{v}\b", l)]
        if len(a) != 1:
            B("Z20", f"{v} is assigned {len(a)} times, not once")
        for l in u:
            s = l.lstrip()
            if not (re.search(r'\becho "      \$' + v + r'"', l) or re.search(r"\bprintf '[^']*' .*\"\$" + v + r"\"", l)) \
                    or re.search(r"\beval\b|\b(?:ba)?sh -c\b", l) or s.startswith("$" + v) or s.startswith('"$' + v):
                B("Z20", f"{v} expanded outside a line that prints it: {s[:80]!r}")
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
    ("Z1", "the wrapper run, not printed", ins("  bash $QF_WRAPPER set ai_burst_merge_enabled 0")),
    ("Z2", "set_config with another key in code",
     ins('  docker exec -u "$DB_OWNER" "$CONTAINER" php "$IN_CONTAINER/tools/set_config.php" --key followup_enabled --value 0')),
    ("Z3", "rb_pre107 not called", lambda t: _at(t, lambda l: l.startswith("    rb_pre107   # "), lambda l: [])),
    ("Z3", "rb107 a third time", lambda t: _rep(t, STAGE_R[3], STAGE_R[3] + "rb107\n")),
    ("Z4", "a writer", ins("  dxo php -r 'PluginConfig::saveOverrides(getenv(\"DN_DATA_DIR\"), [\"ai_burst_merge_enabled\" => \"0\"]);'")),
    ("Z5", "owner_set_cmd back", lambda t: _rep(t, "\nowner_cmds() {\n", "\nowner_set_cmd() { :; }\nowner_cmds() {\n")),
    ("Z6", "the refusal goes on",
     lambda t: _at(t, lambda l: l.startswith('      echo "--clear-switches: refused'), lambda l: [l.replace("; exit 64 ;;", " ;;")])),
    ("Z7", "NS-B1 printed by nothing", lambda t: t.replace("NS-B1", "NS-BX")),
    ("Z8", "numbers again", ins('  note "LM-RB1 THE MASKED LINES WRITE PHONE NUMBERS AGAIN"')),
    ("Z9", "a judged grep from a pipe", lambda t: _rep(t, HERE, PIPE)),
    ("Z10", "a slot left open", ins("  # ── SLOT X ── open ──")),
    ("Z11", "NOT YET exits 0", lambda t: _rep(t, '[ "$NOT_YET" = "0" ] || return 3', '[ "$NOT_YET" = "0" ] || return 0')),
    ("Z11", "notyet outside ME-CA1", lambda t: _rep(t, "\nlk_ao() {\n", '\nlk_ao() {\n  notyet "LK-AO waits"\n')),
    ("Z11", "an owner's step in the NOT YET branch", lambda t: _rep(t, NY_ELIF, NY_ELIF + '  echo "      cd $REPO && bash $QF_WRAPPER show"\n')),
    ("Z12", "a NOT ESTABLISHED made a pass",
     lambda t: t.replace('note "ME-CA1 NOT ESTABLISHED — ', 'ok "ME-CA1 NOT ESTABLISHED — ', 1)),
    ("Z13", "ME-CA1 after the steps", lambda t: _rep(_rep(t, STAGE_R[3], "\n"), STAGE_R[4], STAGE_R[4] + "me_ca1\n")),
    ("Z14", "the run's folder 5.18.106's",
     lambda t: _rep(t, 'OUT="${DNB_OUT:-/root/dnb-5.18.107}"', 'OUT="${DNB_OUT:-/root/dnb-5.18.106}"')),
    ("Z14", "7835906 in code", ins('  BASELINE_X="7835906"')),
    ("Z15", "the switch-off and the drain reading with no text between",
     lambda t: _rep(t, '  echo "  then the drain reading, its own command, alone:"\n', "  echo\n")),
    ("Z16", "ECO Bank's block after a NOT YET", lambda t: _rep(t, ECO_IF, ECO_IF.replace(' && [ "$NOT_YET" = "0" ]', ""))),
    ("Z16", "§K's steps out of order", lambda t: _rep(_rep(_rep(t, STEPS[0], "\0"), STEPS[1], STEPS[0]), "\0", STEPS[1])),
    ("Z17", "a re-pointed text back", lambda t: _rep(t, "\nrb_pre107() {\n", "\n# the live plugin is 5.18.105\nrb_pre107() {\n")),
    ("Z18", "a retired name split across two echo lines", ins('  echo "  the list stays, read by"\n  echo "  nothing, as before"')),
    ("Z18", "a retired check named", lambda t: _rep(t, "\nrb_pre107() {\n", "\n# CS-G1 refused\nrb_pre107() {\n")),
    ("Z19", "an odd apostrophe in a default word", ins('  note "ME-CA1 ${o:-master\'s record unread}"')),
    ("Z19", "a $ between two apostrophes in a default word", ins('  note "ME-CA1 ${o:-the pin\'s $EXPECTED_VERSION\'s record}"')),
    ("Z20", "a display variable run as a command", lambda t: _rep(t, TOP_DV[2] + "\n", TOP_DV[2] + '\n"$W1_ON_CMD"\n')),
    ("Z20", "a display variable assigned twice", lambda t: _rep(t, TOP_DV[1] + "\n", TOP_DV[1] + "\n" + TOP_DV[1] + "\n")),
    ("Z20", "a display variable in eval", ins('  eval "$K_ON_CMD"')),
]


def apply(doc, ctx):
    bad = rules(doc.t)
    if bad:
        raise SystemExit(f"PART Z: {len(bad)} refusal(s): " + "; ".join(f"{z}: {m}" for z, m in bad[:30]))
    for z, what, weak in CONTROLS:
        got = rules(weak(doc.t))
        if sorted({g for g, _ in got}) != [z]:
            raise SystemExit(f"PART Z CONTROL ({what}): refused for {sorted({g for g, _ in got}) or 'nothing'}, not {z} alone: {got[:3]}")
    if sorted({c[0] for c in CONTROLS}) != sorted(f"Z{k}" for k in range(1, NRULES + 1)):
        raise SystemExit("PART Z: a rule has no weakened copy")
    ctx.update(z_rules=NRULES, z_controls=len(CONTROLS))
