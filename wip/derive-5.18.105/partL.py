# Part L (new) — 5.18.105's masked lines, LM (root docs/74 §H.4.5): the line list from the build, never a fixed four.
# The derivation computes 5.18.105's hunks in its runtime files from Git (994d660 → the pin, diff -U0), one entry per hunk
# whose text, markers aside, is not empty: its file, its label, and the line count and sha256 of its old and of its new
# text, each one block (the lines joined by newlines, the marker lines left out — markers are never counted, and the
# script carries no source text). It asserts, on Git's two trees, every count LM-G1 makes: each new block once in the pin
# and nowhere in 994d660, each old block once in 994d660 and nowhere in the pin — a block that is not unique stops here.
# The script's functions (lm_funcs.sh.txt) go beside LK's; LM-G1/LM-G2 run in stage A after QF (refusals), LM-CA1/LM-CA2
# (or LM-ON) after the copy, LM-RB1 in RB105.
import os, re, hashlib
from d105lib import git

HERE = os.path.dirname(os.path.abspath(__file__))
MARK = re.compile(r"5\.18\.105.{0,40}root docs/74 §")
KIND = {"lib/PluginLog.php": "tail", "tools/sales_followup_report.php": "report", "webhook.php": "loader"}


def hunks(diff):
    out, cur = [], None
    for ln in diff.split("\n"):
        m = re.match(r"@@ -(\d+)(?:,(\d+))? \+(\d+)(?:,(\d+))? @@", ln)
        if m:
            cur = {"o": [], "n": [], "nstart": int(m.group(3))}
            out.append(cur)
            continue
        if cur is None or ln.startswith("+++") or ln.startswith("---"):
            continue
        if ln.startswith("-"):
            cur["o"].append(ln[1:])
        elif ln.startswith("+"):
            cur["n"].append(ln[1:])
        elif ln.startswith("\\"):
            raise SystemExit("PART L: a hunk at a file's end without its final newline")
    return out


def runs(lines, block):
    if not block:
        return -1
    n, t = len(block), "\n".join(block)
    return sum(1 for i in range(len(lines) - n + 1) if "\n".join(lines[i:i + n]) == t)


def apply(doc, ctx):
    repo, plugin, base, pin = ctx["repo"], ctx["plugin"], ctx["base_full"], ctx["pin_full"]
    show = lambda c, p: git(repo, "show", f"{c}:{plugin}/{p}").decode()
    entries, labels = [], set()
    for f in sorted(ctx["runtime_changed"]):
        d = git(repo, "diff", "-U0", "--no-renames", base, pin, "--", f"{plugin}/{f}").decode()
        bt, pt = show(base, f).split("\n"), show(pin, f).split("\n")
        seen = {}
        for h in hunks(d):
            new = [l for l in h["n"] if not MARK.search(l)]
            old = h["o"]
            if any(MARK.search(l) for l in old):
                raise SystemExit(f"PART L: {f}: a removed line carries 5.18.105's marker")
            if not new and not old:
                continue                      # a marker alone
            if not old:
                label = KIND.get(f)
            else:
                ids = re.findall(r"LN-\d\d", " ".join(l for l in h["n"] if MARK.search(l)))
                if not ids:                   # the marker in the 30 lines above (qf_n_hunks' window)
                    top = h["nstart"] - 1
                    for l in reversed(pt[max(0, top - 30):top]):
                        if MARK.search(l):
                            ids = re.findall(r"LN-\d\d", l)
                            break
                label = "+".join(ids) if ids else KIND.get(f)
            if not label:
                raise SystemExit(f"PART L: {f}:{h['nstart']}: a hunk with no LN and no declared kind")
            seen[label] = seen.get(label, 0) + 1
            if seen[label] > 1:
                label = f"{label}#{seen[label]}"
            if label in labels:
                raise SystemExit(f"PART L: the label {label} is used twice")
            labels.add(label)
            c = (runs(bt, old), runs(bt, new), runs(pt, old), runs(pt, new))
            want = (1 if old else -1, 0, 0 if old else -1, 1)
            if c != want:
                raise SystemExit(f"PART L: {f} {label}: the blocks are not unique as LM-G1 counts them (base old/new, pin old/new = {c}, want {want})")
            sha = lambda b: hashlib.sha256("\n".join(b).encode()).hexdigest() if b else "-"
            for t in (f, label):
                if "|" in t or " " in t or "=" in t:
                    raise SystemExit(f"PART L: {t!r} cannot travel in LM_HUNKS")
            entries.append(f"{f}|{label}|{len(old)}|{sha(old)}|{len(new)}|{sha(new)}")
    if not entries:
        raise SystemExit("PART L: no hunk found")
    ln_n = sum(1 for e in entries if e.split("|")[1].startswith("LN-") and e.split("|")[2] != "0")
    lns = sorted({x for e in entries for x in re.findall(r"LN-\d\d", e.split("|")[1])})
    if len(lns) < 30:
        raise SystemExit(f"PART L: only {len(lns)} LNs named by the hunks' markers")
    ctx.update(lm_n=len(entries), lm_ln_n=ln_n, lm_lns=lns, lm_entries=[tuple(e.split("|")[:2]) for e in entries])

    # the on-chase precondition's two strings, from the pin's lib/SalesFollowupChecks.php (held byte for byte)
    chk = show(pin, "lib/SalesFollowupChecks.php")
    m = re.search(r"case 'chase':\n\s*\$src = .*?\n\s*if \(strpos\(\$src, \"(.*?)\"\) === false \|\| strpos\(\$src, \"(.*?)\"\) === false\)", chk)
    if not m:
        raise SystemExit("PART L: the on-chase precondition's strings were not found in lib/SalesFollowupChecks.php")
    w2 = [s.replace("\\$", "$") for s in (m.group(1), m.group(2))]
    for c in (base, pin):
        ev = show(c, "evo_webhook.php").split("\n")
        for s in w2:
            if sum(1 for l in ev if s in l) != 1:
                raise SystemExit(f"PART L: a W2 string is not on exactly one line of evo_webhook.php at {c[:7]}")
    if any("\n" in s or s.strip() != s or "\\" in s for s in w2):
        raise SystemExit("PART L: a W2 string is not one line with no escape left in it")
    # review D6: the script carries no source text it can read from Git, so it does not carry these two strings —
    # lm_w2_strings reads them at run time from the pin's lib/SalesFollowupChecks.php. Its reading is emulated here, step
    # for step (sed's range over case 'chase', grep -E's one line, sed -E's two captures, \$ read as $), and must give
    # exactly the two strings read above.
    rng, on = [], False
    for ln in chk.split("\n"):
        if not on:
            if re.fullmatch(r" *case 'chase':", ln):
                on = True
                rng.append(ln)
        else:
            rng.append(ln)
            if re.match(r" *case '", ln):
                on = False
    w2_line = r' *if \(strpos\(\$src, "([^"]+)"\) === false \|\| strpos\(\$src, "([^"]+)"\) === false\) \{'
    hits = [ln for ln in rng if re.fullmatch(w2_line, ln)]
    if len(hits) != 1:
        raise SystemExit(f"PART L: lm_w2_strings' reading finds {len(hits)} precondition lines in case 'chase', not 1")
    got = [s.replace("\\$", "$") for s in re.fullmatch(w2_line, hits[0]).groups()]
    if got != w2:
        raise SystemExit("PART L: lm_w2_strings' reading does not give the two strings the derivation read")

    text = open(os.path.join(HERE, "lm_funcs.sh.txt"), encoding="utf-8").read()
    for k, v, n in (("@@LM_HUNKS@@", "\n".join(entries), 1), ("@@LM_N@@", str(len(entries)), 1),
                    ("@@LM_LN_N@@", str(ln_n), 1)):
        if text.count(k) != n:
            raise SystemExit(f"PART L: placeholder {k} found {text.count(k)} times")
        text = text.replace(k, v)
    if "@@" in text:
        raise SystemExit("PART L: a placeholder is left")
    # beside LK's functions (after lk_ao, before qf_stage's comment)
    doc.insert_before("# qf_stage — QF-G1–QF-G3, LK-G1 and QF-N1–QF-N7, stage A, before anything changes", text, why="LM functions")
    doc.must('LM_N=' + str(len(entries)) + "        # the entries above\n", 1)
    # review D6: neither string, nor a variable carrying them, is in the script; lm_w2 reads them through lm_w2_strings
    for s in w2:
        doc.mustnot(s, why="W2 string carried")
    doc.mustnot("LM_W2", why="W2 strings carried")
    doc.must('  w="$(lm_w2_strings)"\n', 1)
    doc.must("lm_w2_strings() {\n", 1)
    # the calls: stage A after QF; after the copy beside QF's; RB105 calls lm_rb1 itself
    doc.sub("  hdr \"A. The sales follow-up (5.18.104, Part F's QF; read-only, on copies through the pin's code)\"\n  qf_stage\nfi\n",
            "  hdr \"A. The sales follow-up (5.18.104, Part F's QF, kept live by 5.18.105's LK; read-only, on copies through the pin's code)\"\n  qf_stage\n"
            "  # LM-G1, LM-G2 — 5.18.105 (root docs/74 §H.4.5): the masked lines in the pin and in 994d660, from Git, counted as blocks;\n"
            "  # the on-chase precondition's two strings in the pin's evo_webhook.php. Refusals; --check runs them too.\n"
            "  lm_stage\nfi\n", why="LM stage A")
    doc.sub('qf_after "${ICODE_R:-}"\n',
            'qf_after "${ICODE_R:-}"\n'
            'hdr "R. The masked lines, installed ($EXPECTED_VERSION\'s LM; read-only — the installed files counted as blocks, the logs since the copy counted)"\n'
            'lm_after   # LM-CA1 and LM-CA2 (the deploy run) or LM-ON (--after-only) — root docs/74 §H.4.5\n', why="LM after")
    doc.must("  lm_stage\nfi\n", 1)
    doc.must("lm_after   # LM-CA1", 1)
    doc.must("  lm_rb1        # LM-RB1", 1)
