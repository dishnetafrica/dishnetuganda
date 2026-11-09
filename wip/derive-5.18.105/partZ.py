# Part Z — the retirements' last traces and the rules of root docs/74 §H.4.3 and §H.5, asserted over the whole result:
#   the readings that served only the retired checks (VN_PHP's vkeys and rb, OF_PHP's rb, AC_PHP's admins and rb1 — the
#   off-all rollback's, QF-RB1's and VN-RB1's) go, each case block whole, from its label to the next, and the two vault
#   lists lose 'rb'; one stop text still naming QF-G4 is re-pointed;
#   then: the deploy never runs the switch tool's on, off, skip, set or erase (every qf_tool call is show, --check or
#   doctor, on a copy; the wrapper only ever printed); no set_config call but Q-CA6's throwaway one; the rollback goes to
#   994d660 with rb_pre105 before ROLLBACK is typed and rb105 after; no retired name in code; the retired codes silent in
#   printed text but where it says they are retired; every LK, LM and RB105 code named in code (a check's text or its
#   code variable).
import re


def drop_case(doc, label_line, why):
    """Remove one PHP case block: from the line that starts with label_line to the next '    case ' or '    default'."""
    lines = doc.t.split("\n")
    hits = [i for i, l in enumerate(lines) if l.startswith(label_line)]
    if len(hits) != 1:
        raise SystemExit(f"Z: {why}: the case label is found {len(hits)} times")
    i = hits[0]
    j = i + 1
    while j < len(lines) and not re.match(r"^    (case '|default\b)", lines[j]):
        if re.match(r"^(PHP|[A-Z_]+)$", lines[j]) or lines[j].startswith(")\""):
            raise SystemExit(f"Z: {why}: the block runs past its heredoc")
        j += 1
    block = lines[i:j]
    if j - i > 40 or not any(l.strip() == "break;" for l in block[-3:]):
        raise SystemExit(f"Z: {why}: the block does not end with its own break ({j - i} lines)")
    doc.t = "\n".join(lines[:i] + lines[j:])
    doc.log.append((why, 1))


def code_lines(t):
    for n, l in enumerate(t.split("\n"), 1):
        s = l.lstrip()
        if s.startswith("#") or s.startswith("//"):
            continue
        yield n, l


def apply(doc, ctx):
    # ── the readings the retired checks used ──
    drop_case(doc, "    case 'vkeys':    // vkeys ROOT — Part V's switch and record keys and its settings, by name (the rollback's VN-RB1)", "VN_PHP vkeys")
    drop_case(doc, "    case 'rb':       // rb DB IDS — VN-RB1b–VN-RB4 on a copy taken after QF-RB1", "VN_PHP rb")
    drop_case(doc, "    case 'rb':       // rb DB IDS START — OF-RB1 on a copy taken after QF-RB1", "OF_PHP rb")
    drop_case(doc, "    case 'admins':   // admins LIVEROOT PDD — AC-RB1's reading of quote_agent_cc_admins", "AC_PHP admins")
    drop_case(doc, "    case 'rb1':      // rb1 LIVEROOT PDD — AC-RB1's read-back", "AC_PHP rb1")
    doc.sub("if (in_array($mode, ['objects', 'events', 'ca4', 'rb', 'rb5'], true)) putenv(",
            "if (in_array($mode, ['objects', 'events', 'ca4', 'rb5'], true)) putenv(", why="VN vault list")
    doc.sub("if (in_array($mode, ['pre', 'm1', 'ca2', 'ca3', 'ca5', 'rb', 'rb2'], true)) putenv(",
            "if (in_array($mode, ['pre', 'm1', 'ca2', 'ca3', 'ca5', 'rb2'], true)) putenv(", why="OF vault list")
    for f, m in (("vn_php", "vkeys"), ("vn_php", "rb"), ("of_php", "rb"), ("ac_php", "admins"), ("ac_php", "rb1")):
        if re.search(rf"\b{f} {m}\b", doc.t):
            raise SystemExit(f"Z: {f} {m} is still called")
    doc.sub("— QF-G1 to QF-G4 read the book through it. Nothing was changed.",
            "— QF-G1 to QF-G3 and LK-G1 read the book through it. Nothing was changed.", why="stop text QF-G4")

    doc.sub('QF_G1=""; QF_G1_LINE=""; QF_BASE=""   # 5.18.105: QF-G4 (and with it QF_G4_*, QF_CLEAR, QF_CLEARED) retired — LK-G1 (root docs/74 §H.4.4)',
            'QF_G1=""; QF_G1_LINE=""; QF_BASE=""   # 5.18.105: QF-G4 and its variables retired — LK-G1 (root docs/74 §H.4.4)', why="vars comment")
    t = doc.t
    # ── the switch tool: show, --check or doctor, on a copy; the wrapper only printed ──
    calls = [(n, l) for n, l in code_lines(t) if "qf_tool " in l and "qf_tool()" not in l]
    if len(calls) < 8:
        raise SystemExit(f"Z: only {len(calls)} qf_tool calls found")
    for n, l in calls:
        for m in re.finditer(r'qf_tool "\$[A-Za-z0-9_]+(?:/ss)?" ([A-Za-z-]+)', l):
            if m.group(1) not in ("show", "--check", "doctor"):
                raise SystemExit(f"Z: line {n}: qf_tool runs {m.group(1)!r} — the deploy never runs on, off, skip, set or erase")
        if not re.search(r'qf_tool "\$[A-Za-z0-9_]+(?:/ss)?" (show|--check|doctor)\b', l):
            raise SystemExit(f"Z: line {n}: a qf_tool call not of the three forms: {l.strip()[:120]}")
    for n, l in code_lines(t):
        if "$QF_WRAPPER" in l and not l.lstrip().startswith("echo "):
            raise SystemExit(f"Z: line {n}: the wrapper is used, not printed")
        if "tools/set_config.php\" --key" in l and "--key ucrm_quote_organization_id --value disabled" not in l:
            raise SystemExit(f"Z: line {n}: set_config is run with another key")
        if re.search(r"sales[-_]followup(\.php|\.sh)?\"? (on|off|skip|set|erase)\b", l) and not l.lstrip().startswith("echo ") \
                and "--clear-switches)" not in l:
            raise SystemExit(f"Z: line {n}: the switch tool's verb outside printed text")
    # ── the rollback: 994d660, every switch kept ──
    doc.must('BASELINE_COMMIT="994d660"', 1)
    pre = [m.start() for m in re.finditer(r"(?m)^\s+rb_pre105\b(?!\(\))", t)]
    if len(pre) != 1 or not (0 < t.index("Type ROLLBACK", pre[0]) - pre[0] < 400):
        raise SystemExit("Z: rb_pre105 is not called once, just before ROLLBACK is typed")
    calls = re.findall(r"(?m)^(?:rb105[ \t]*$|.*then rb105 )", t)
    if len(calls) != 2:
        raise SystemExit(f"Z: rb105 is not called exactly from V1's automatic rollback and RB ({len(calls)})")
    # review D1 — the chosen form (root docs/74 §H.5.3–§H.5.4): with nothing rolled back by this run, LK-RB1 is a plain
    # note, never judged; RB_NOTHING is set once, on --rollback's nothing-to-roll-back branch (after the branch that types
    # ROLLBACK and puts 994d660 back), and every LK-RB1 verdict of rb105 lies in the branch that has a before to compare
    i = t.index("rb105() {\n")
    body = t[i:t.index("\n}\n", i)]
    a = body.find('\n  if [ -n "$RB_NOTHING" ]; then\n')
    e = body.find("\n  else\n", a)
    f = body.find('\n  fi\n  o="$(qf_php rbn "$PDD_IN")"\n', e)
    if a < 0 or e < 0 or f < 0 or body.count("RB_NOTHING") != 1:
        raise SystemExit("Z: rb105 does not branch once on RB_NOTHING before its LK-RB1 comparisons")
    none_branch, judged = body[a:e], body[e:f]
    if 'note "LK-RB1 not judged: this run rolled nothing back' not in none_branch or re.search(r'\b(ok|bad) "LK-RB1', none_branch):
        raise SystemExit("Z: rb105's nothing-rolled-back branch is not a plain note")
    if len(re.findall(r'\b(?:ok|bad) "LK-RB1', body)) != len(re.findall(r'\b(?:ok|bad) "LK-RB1', judged)) or \
            len(re.findall(r'\bbad "LK-RB1', judged)) != 7:
        raise SystemExit("Z: an LK-RB1 verdict of rb105 lies outside the branch that has a before to compare")
    sets = [(n, l) for n, l in code_lines(t) if "RB_NOTHING=" in l]
    if len(sets) != 2 or sets[0][1].strip() != 'RB_NOTHING=""' or "  else RB_NOTHING=1; DEPLOY_STARTED=" not in sets[1][1]:
        raise SystemExit(f"Z: RB_NOTHING is not initialised once and set once, on the nothing-to-roll-back branch ({[n for n, _ in sets]})")
    rbm = t.index('elif [ "$MODE" = "rollback" ]; then\n  hdr "B. The rollback')
    if not (rbm < t.index("    rb_pre105   #", rbm) < t.index("  if do_rollback; then ok \"container serves $BASELINE_COMMIT", rbm)
            < t.index("  else RB_NOTHING=1;", rbm) < t.index("\nelse\n", rbm)):
        raise SystemExit("Z: RB_NOTHING=1 is not on --rollback's nothing-to-roll-back branch")
    # review D3 — the step-12 notice and LM-RB1 count the masked log lines alike (LM_LN_N), never every entry (LM_N)
    if t.count("THE $LM_LN_N LOG LINES $EXPECTED_VERSION MASKED") != 1 or t.count("LM-RB1 $LM_LN_N LOG LINES WRITE PHONE NUMBERS AGAIN") != 1 \
            or "$LM_N LOG LINES" in t:
        raise SystemExit("Z: the step-12 notice and LM-RB1 do not both count the masked log lines as LM_LN_N")
    # ── retired names: nowhere in code ──
    for name in ("qf_rb1", "qf_clear_and_baseline", "qf_byhand", "CLEAR_SWITCHES", "QF_RB1_START", "db_mig104", "QF_G4",
                 "QF_CLEAR", "ac_rb_pre", "ac_rb1", "of_rb1", "vn_rb1", "AC_RB_ADMINS", "F_QG1", "off all"):
        hits = [n for n, l in code_lines(t) if name in l]
        if hits:
            raise SystemExit(f"Z: the retired {name} is still in code, line(s) {hits[:5]}")
    cs = [n for n, l in code_lines(t) if "--clear-switches" in l]
    if len(cs) != 1 or "exit 64" not in t.split("\n")[cs[0] - 1]:
        raise SystemExit(f"Z: --clear-switches is in code beyond its refusal at parse ({cs})")
    # ── retired codes silent in printed text but where it says they are retired ──
    printed = re.compile(r'\b(ok|bad|note|stop|echo|warn)\s+"')
    for code in ("QF-G4", "QF-RB1", "QF-RB2", "QF-RB3", "VN-RB1", "VN-RB2", "VN-RB3", "VN-RB4", "VN-RB6", "OF-RB1", "AC-RB1"):
        for n, l in code_lines(t):
            if code in l and printed.search(l) and "retire" not in l:
                raise SystemExit(f"Z: line {n}: the retired {code} in printed text")
    # ── every new code printed by a check ──
    for code in ("LK-G1", "LK-B1", "LK-CA1", "LK-CA2", "LK-ON", "LK-AO", "LK-RB0", "LK-RB1", "LM-G1", "LM-G2", "LM-CA1",
                 "LM-CA2", "LM-ON", "LM-RB1", "RB105"):
        if not any(code in l for _, l in code_lines(t)):
            raise SystemExit(f"Z: {code} is named by no line of code (a check's text or its code variable)")
    doc.mustnot("bash $QF_WRAPPER off all")
    doc.mustnot("5.18.104's own checks first")
