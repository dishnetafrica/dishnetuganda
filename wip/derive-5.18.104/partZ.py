# partZ — DS4's final consistency pass over the whole derived script (root docs/73 §H.2.15, §H.2.16, §H.3.25, §H.4.18,
# §H.4.19, §H.5.14, §H.5.15; rulings R7, R11). Registered last, it changes only what it finds:
#   1. every insertion point DS1 left is filled or deliberately closed, and then closed: each slot line removed by
#      d104lib.close(), asserting the line above it is its filling call or the comment that says why it stays empty;
#      no marker survives;
#   2. every code the design names for the deploy script — Part F's QF (§H.2.15, §H.2.16), Part V's VN and VA (§H.3.25),
#      Part O's OF (§H.4.18, §H.4.19), Part P's AC (§H.5.14) — is reported where its stage puts it: a G, N or L code in stage
#      A (qf_stage, or qf_clear_and_baseline for the clear and the baselines taken at QF-G1's one moment), an M, CA or K code
#      after the copy (qf_after), an ON code in --after-only (qf_on), an RB code in the rollback (qf_rb1, qf_byhand, qf_rb3),
#      read off the functions each of those calls; and no code the design or 5.18.103 retired reports a line;
#   3. the summary's "your steps" names the ladder's steps in its one order (SalesFollowupLadder::STEPS, from Git), and the
#      printed rollback block names the parts in the one sequence (SEQUENCE, then the ledger, then the record keys and
#      agent-cc, last; rulings R7, R11), as every statement of that sequence's step numbers in the script does.
import re
from d104lib import git, close

D_NAME = "docs/73-uganda-sales-follow-up-5-18-104-design-2026-11-04.md"
# slot → the line just above it, by its start: the call that filled it, or the comment that says why it stays empty
SLOTS = [
    ("VN-G7", "vn_g7   # VN-G7"), ("VN-G stage A", "vn_stage   # VN-G1"),
    ("VA-G stage A", "# VN-G5's baseline, its events VN-G6's; VN-G7 covers 090, whose wa_voice_acks P5 writes.)"),
    ("OF-G stage A", "of_stage   # OF-G1"), ("AC stage A", "ac_stage   # AC-L1"), ("baseline", "of_baseline   # OF-G1"),
    ("VN-CA", 'vn_after "$inst"   # VN-M1'), ("VA-CA", "# and decisions; VA-ON1 judges them once step 2 is on.)"),
    ("OF-CA", 'of_after "$inst"   # OF-M1'), ("AC-CA", 'ac_after "$inst"   # AC-CA1'),
    ("VN-ON", 'vn_on "$sub"   # VA-ON1'), ("OF-ON", 'of_on "$sub"   # OF-ON1'), ("AC-ON1", 'ac_on "$sub"   # AC-ON1'),
    ("VN-RB by hand", "# its keys are in the list above.)"),
    ("OF-RB1", "of_rb1   # OF-RB1"), ("VN-RB", "vn_rb1   # VN-RB1"), ("AC-RB1", "ac_rb1   # AC-RB1"),
    ("VN-RB3", 'vn_rb_after "$QF_RB3_VC"   # VN-RB5'), ("OF-RB2", "of_rb_after   # OF-RB2"), ("AC-RB2", "ac_rb_after   # AC-RB2"),
]
# the design's sections that name each part's deploy codes (headings, so that the line numbers may move)
SECTIONS = [("QF", "#### H.2.15 ", "#### H.2.17 "), ("VN|VA", "#### H.3.25 ", "#### H.3.26 "), ("OF", "#### H.4.18 ", "#### H.4.20 "),
            ("AC", "#### H.5.14 ", "#### H.5.16 ")]
STAGE_OF = {"G": "A", "N": "A", "L": "A", "M": "R", "CA": "R", "K": "R", "ON": "O", "RB": "B"}
ROOTS = {"A": ["qf_stage", "qf_clear_and_baseline"], "R": ["qf_after"], "O": ["qf_on"], "B": ["qf_rb1", "qf_byhand", "qf_rb3"]}
# retired, never to report a line: 5.18.102's QL-CA1, QL-LK1 and QL-RB1–QL-RB3 (docs/72 §G.13, retired since 5.18.103);
# the design retires none of 5.18.103's checks for 5.18.104 (its §H.2.15 keeps every one), and none of its own codes
RETIRED = ["QL-CA1", "QL-LK1", "QL-RB1", "QL-RB2", "QL-RB3"]


def design_codes(dtext):
    out = {}
    for pfx, a, b in SECTIONS:
        s = dtext[dtext.index(a):dtext.index(b)]
        got = set()
        # a code, or a range: "VN-RB1–VN-RB6", "VN-ON1…VN-ON4", and the design's shorthand "VN-ON1–4"
        for m in re.finditer(r"\b(" + pfx + r")-([A-Z]+)(\d+)(?:\s*(?:[–…]|-(?=(?:" + pfx + r")-))\s*(?:(" + pfx + r")-([A-Z]+))?(\d+))?", s):
            p, k, n = m.group(1), m.group(2), int(m.group(3))
            if m.group(6) and (m.group(5) is None or m.group(5) == k):
                got |= {f"{p}-{k}{i}" for i in range(n, int(m.group(6)) + 1)}
            else:
                got.add(f"{p}-{k}{n}")
        out[pfx] = sorted(got)
    return out


def functions(text):
    """name → its body's non-comment lines, for every function defined at column 0 (one-liners included)."""
    lines = text.split("\n")
    out, i = {}, 0
    head = re.compile(r"^([a-z_][a-z0-9_]*)\(\) \{(.*)$")
    while i < len(lines):
        m = head.match(lines[i])
        if not m:
            i += 1
            continue
        name, rest = m.group(1), m.group(2)
        if re.search(r"\}\s*(#.*)?$", rest) and rest.count("{") < rest.count("}"):
            body = [rest]
            i += 1
        else:
            j = i + 1
            while j < len(lines) and lines[j] != "}" and not head.match(lines[j]):
                j += 1
            if j == len(lines):
                raise SystemExit(f"Z: function {name} has no closing brace at column 0")
            if lines[j] != "}":          # a function that holds others (main): its own lines are not one body
                i += 1
                continue
            body = lines[i + 1:j]
            i = j + 1
        if name in out:
            raise SystemExit(f"Z: function {name} is defined twice")
        # its code lines: comment lines dropped, and a call line's trailing comment ("   # …", three spaces or more) cut off
        out[name] = [re.sub(r"\s{3,}#\s.*$", "", l) for l in body if not l.lstrip().startswith("#")]
    return out


def apply(doc, ctx):
    repo, plugin, pin = ctx["repo"], ctx["plugin"], ctx["pin_full"]
    lad = git(repo, "show", f"{pin}:{plugin}/lib/SalesFollowupLadder.php").decode()

    # ── 1. every slot filled or deliberately closed, then closed ──
    found = sorted(re.findall(r"^\s*# ── SLOT (.+?) ── ", doc.t, re.M))
    if found != sorted(n for n, _ in SLOTS):
        raise SystemExit(f"Z: the slots in the script are not DS1's twenty: {sorted(set(found) ^ set(n for n, _ in SLOTS))}")
    for name, before in SLOTS:
        close(doc, name, before, why=f"close slot {name}")
    doc.mustnot("# ── SLOT ")
    doc.mustnot("SLOT ")

    # ── 2. every code the design names, where its stage puts it; none retired reports a line ──
    dtext = open(f"{ctx['dev']}/{D_NAME}", encoding="utf-8").read()
    codes = design_codes(dtext)
    want = {"QF": 28, "VN|VA": 26, "OF": 16, "AC": 8}
    for k, n in want.items():
        if len(codes[k]) != n:
            raise SystemExit(f"Z: the design names {len(codes[k])} {k} codes for the deploy script, the stages built {n}: {codes[k]}")
    fn = functions(doc.t)
    # top-level assignments that carry a code as data (VN_STEPS's step:check pairs), and the functions that read them
    tops = {}
    for m in re.finditer(r'^([A-Z][A-Z0-9_]*)="([^"\n]*)"', doc.t, re.M):
        tops[m.group(1)] = m.group(2)
    names = set(fn)
    calls = {f: {w for l in body for w in re.findall(r"\b[a-z_][a-z0-9_]*\b", l) if w in names and w != f} for f, body in fn.items()}

    def closure(roots):
        seen, todo = set(), list(roots)
        while todo:
            f = todo.pop()
            if f in seen:
                continue
            seen.add(f)
            todo += sorted(calls.get(f, ()))
        return seen
    stages = {s: closure(r) for s, r in ROOTS.items()}
    placed = {}
    for k in codes:
        for code in codes[k]:
            kind = re.match(r"^[A-Z]+-([A-Z]+)\d+$", code).group(1)
            st = STAGE_OF.get(kind)
            if st is None:
                raise SystemExit(f"Z: {code}: no stage for its kind {kind}")
            pat = re.compile(r"(?<![A-Z0-9-])" + re.escape(code) + r"(?![0-9])")
            where = sorted(f for f in stages[st] if any(pat.search(l) for l in fn[f]))
            if not where:
                carried = [v for v, val in tops.items() if pat.search(val)]
                where = sorted(f for f in stages[st] for v in carried if any(("$" + v) in l or ("${" + v) in l for l in fn[f]))
            if not where:
                raise SystemExit(f"Z: {code} is not reported where its stage ({st}) puts it")
            placed[code] = (st, where[0])
    for code in RETIRED:
        if re.search(r'(?:ok|bad|note|stop) "(?:NO-GO \()?' + re.escape(code) + r'[ :)]', doc.t):
            raise SystemExit(f"Z: {code}, retired, still reports a line")
    ctx["z_placed"] = placed

    # ── 3. the summary's steps and the printed rollback block, in the one order (from Git) ──
    rows = re.findall(r"^        '([a-z-]+)' => \['n' => '([0-9]+b?)', 'part' => '([A-Z])', 'optional' => (true|false),", lad, re.M)
    steps = [r[0] for r in rows]
    if len(steps) != 13 or steps[0] != "report" or steps[1] != "agent-cc":
        raise SystemExit(f"Z: the ladder's STEPS are not the thirteen rows ({steps})")
    say = ", ".join(s + (" (optional)" if o == "true" else "") for s, n, p, o in rows)
    m = re.search(r'  echo "                    then, for each step in turn — (.*?) — on, then that step\'s check, each its own command, alone, its log in',
                  re.sub(r'"\n  echo "                    ', " ", doc.t), re.S)
    if not m or re.sub(r"\s+", " ", m.group(1)) != say:
        raise SystemExit(f"Z: the summary's steps are not the ladder's one order ({m.group(1) if m else 'not found'!r} vs {say!r})")
    doc.must(f'QF_STEPS="{" ".join(steps)}"', 1, why="QF_STEPS is the ladder's order")
    seq = ctx["seq"]
    num = {s: n for s, n, p, o in rows}
    nums = [int(num[s]) for s in seq]
    for mm in re.finditer(r"O \((12), (11)\)(?: →|,) F \((10), (6)\)(?: →|,) V \((9), (8), (7), (5)\)(?: →|,) (4), (3)(?: →|,) (2)\b", doc.t):
        if [int(x) for x in mm.groups()] != nums:
            raise SystemExit("Z: a statement of the one sequence's step numbers is not SEQUENCE's")
    n_stated = len(re.findall(r"O \(12, 11\)(?: →|,) F \(10, 6\)(?: →|,) V \(9, 8, 7, 5\)(?: →|,) 4, 3(?: →|,) 2\b", doc.t))
    # stated on one line twice: the header's rollback paragraph and QF-RB1's ok line (the qf_rb1 comment breaks it over lines)
    doc.must("O (12, 11), F (10, 6), V (9, 8, 7, 5), 4, 3, 2 (P5's keys, then the ledger last), then the record keys and Part P's two", 1)
    if n_stated < 2 or nums != [12, 11, 10, 6, 9, 8, 7, 5, 4, 3, 2]:
        raise SystemExit(f"Z: the one sequence is stated {n_stated} times, its numbers {nums}")
    old = ('  echo "  but first, under $EXPECTED_VERSION\'s installed code, turns every switch of the sales follow-up off in the one"\n'
           '  echo "  sequence (QF-RB1: scripts/sales-followup.sh off all --rollback, every part\'s hooks and end states); if any"\n')
    order = ", ".join(seq) + ", the ledger, then the record keys and agent-cc, last"
    text = ("sequence — " + order + " (rulings R7, R11; QF-RB1: scripts/sales-followup.sh off all --rollback, every part's"
            " hooks and end states); if any")
    out, cur = [], ""
    for w in text.split(" "):
        if cur and len(cur) + 1 + len(w) > 110:
            out.append(cur)
            cur = w
        else:
            cur = (cur + " " + w) if cur else w
    out.append(cur)
    if any(c in text for c in '$`"\\'):
        raise SystemExit("Z: the sequence's words cannot travel in an echo")
    new = ('  echo "  but first, under $EXPECTED_VERSION\'s installed code, turns every switch of the sales follow-up off in the one"\n'
           + "".join(f'  echo "  {l}"\n' for l in out))
    doc.sub(old, new, 1, why="the printed rollback block names the one sequence")
    blk = doc.t[doc.t.index('  echo "  but first, under $EXPECTED_VERSION\'s installed code'):doc.t.index('  echo "  part does not read clean it stops there')]
    flat = re.sub(r'"\n  echo "  ', " ", blk)
    mm = re.search(r"sequence — (.*?) \(rulings R7, R11;", flat)
    if not mm or [x.strip() for x in mm.group(1).split(",")][:len(seq)] != seq or not mm.group(1).endswith("the ledger, then the record keys and agent-cc, last"):
        raise SystemExit("Z: the printed rollback block does not name the one sequence")
    if any("$ROLLBACK_CMD" in l and ("$AFTER_CMD" in l or "$QCHECK_CMD" in l or "$REDEPLOY_CMD" in l) for l in doc.t.split("\n")):
        raise SystemExit("Z: the rollback shares a line with another command")
    print(f"  Z: {len(SLOTS)} slots closed; {sum(len(v) for v in codes.values())} design codes placed by stage "
          f"({', '.join(f'{k} {len(v)}' for k, v in codes.items())}); {len(RETIRED)} retired codes silent; the steps and the sequence in their order")
