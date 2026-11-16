# Part L — 5.18.105's masking still holds (root docs/75 §L.6; LM keeps its codes, DL9). The derivation computes
# 5.18.105's hunks exactly as wip/derive-5.18.105/partL.py did (994d660 → 7835906, diff -U0, 5.18.105's runtime files,
# one entry per hunk whose text, its 5.18.105 marker lines aside, is not empty: file, label, line count and sha256 of the
# old and of the new block) and asserts the script's LM_HUNKS equal to it, byte for byte, with LM_N and LM_LN_N its counts
# and each block's runs on Git's trees (old once and new nowhere at 994d660, new once and old nowhere at 7835906). Then:
# none of 5.18.106's 23 files is one of LM_HUNKS' 21 files; no file of the delta outside tests/ holds one of its blocks at
# the pin, and a delta test that holds one holds it exactly as at 7835906 (tests/fixtures/r105_revert.php, 5.18.105's
# layer, holds post_cashbook.php's old and new block — LM reads only its 21 files, so it counts nothing there); all 21
# LM files and lib/SalesFollowupChecks.php are the same file at 7835906 and the pin, each kept by sha256; the on-chase
# precondition's two strings are re-read as lm_w2_strings reads them (5.18.105's assertion, at 7835906 and the pin).
# Edits, the LM region only:
#   - the header gains a paragraph (why the list is kept, what LM-G1 now requires); LM_LN_N's comment (S105:7228, "what a
#     rollback writes numbers in again") is reworded; LM_CONTROL_COMMIT is added (new name): 994d660's full hash, read off
#     Git as 7835906's parent and asserted equal to 994d660628cc5b1b42bee648b44b58e389f44bf2;
#   - lm_count gains a "control" mode (LM_CONTROL_COMMIT's files from Git); lm_expect's modes become "pin" (the masked
#     form) and "control" (994d660's form), any other word expecting a form no file reads;
#   - LM-G1: the control on LM_CONTROL_COMMIT, not $BASELINE_COMMIT (at 7835906 the masked lines already stand, so the
#     carried control would refuse); the masked form required at the pin, at $BASELINE_COMMIT and as installed;
#   - LM-RB1 inverted: after a rollback to 7835906 the masked form (lm_expect pin) must still be there; its capitals note
#     goes (S105:7471-7476); its comment keeps "after the put-back (RB105)" for partR's rename; its reading line carries a
#     trailing comment so that it stays distinct from LM-CA1's;
#   - LM-CA1, LM-CA2 and LM-ON carried; where they called the masked lines $EXPECTED_VERSION's they say 5.18.105's.
# Not touched (other parts'): the header's, rb_pre's, RB's and the summary's "numbers again" texts, rb105's call line of
# lm_rb1 ("the old lines back", S105:7856) and its comment (S105:7810), and the stage-A call site's comment of lm_stage.
import re, hashlib
from d106lib import git, sha_at, delta

LM_CONTROL_FULL = "994d660628cc5b1b42bee648b44b58e389f44bf2"   # 5.18.104's release commit (asserted: 7835906's parent)
N_LM_FILES = 21                                                 # §L.6 (asserted equal to what Git gives)
CHECKS = "lib/SalesFollowupChecks.php"
MARK = re.compile(r"5\.18\.105.{0,40}root docs/74 §")           # 5.18.105's marker, as its partL read it
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


def entries_105(repo, plugin, old, new, files):
    """5.18.105's partL computation, old → new: the LM_HUNKS lines and each entry's (file, label, old, new) blocks."""
    show = lambda c, p: git(repo, "show", f"{c}:{plugin}/{p}").decode()
    entries, blocks, labels = [], [], set()
    for f in sorted(files):
        d = git(repo, "diff", "-U0", "--no-renames", old, new, "--", f"{plugin}/{f}").decode()
        bt, pt = show(old, f).split("\n"), show(new, f).split("\n")
        seen = {}
        for h in hunks(d):
            nb = [l for l in h["n"] if not MARK.search(l)]
            ob = h["o"]
            if any(MARK.search(l) for l in ob):
                raise SystemExit(f"PART L: {f}: a removed line carries 5.18.105's marker")
            if not nb and not ob:
                continue
            if not ob:
                label = KIND.get(f)
            else:
                ids = re.findall(r"LN-\d\d", " ".join(l for l in h["n"] if MARK.search(l)))
                if not ids:
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
            c = (runs(bt, ob), runs(bt, nb), runs(pt, ob), runs(pt, nb))
            want = (1 if ob else -1, 0, 0 if ob else -1, 1)
            if c != want:
                raise SystemExit(f"PART L: {f} {label}: the blocks are not unique as LM-G1 counts them ({c}, want {want})")
            sha = lambda b: hashlib.sha256("\n".join(b).encode()).hexdigest() if b else "-"
            for t in (f, label):
                if "|" in t or " " in t or "=" in t:
                    raise SystemExit(f"PART L: {t!r} cannot travel in LM_HUNKS")
            entries.append(f"{f}|{label}|{len(ob)}|{sha(ob)}|{len(nb)}|{sha(nb)}")
            blocks.append((f, label, ob, nb))
    return entries, blocks


HDR_OLD = ("# Every count below was asserted on Git's two trees by the derivation. An entry that only inserts has no old block (0|-).\n"
           "LM_HUNKS=\"$(cat <<'HUNKS'\n")
STAGE_START = "# lm_stage — LM-G1 and LM-G2, stage A (a deploy and --check), before anything changes: refusals\n"
STAGE_END = "  w=\"$(lm_w2 pin)\"\n"
RB1_START = "# lm_rb1 — LM-RB1, after the put-back (RB105): the installed text holds each entry's old block once and its new block\n"
RB1_END = "  else bad \"LM-RB1 the installed files are not $BASELINE_VERSION's where $EXPECTED_VERSION masked its lines ($d; label(old/new runs))\"; fi\n}\n"
CA1_LINE = "  g=\"$(lm_count inst)\"; d=\"$(lm_diff \"$g\" \"$(lm_expect pin)\")\"\n"


def apply(doc, ctx):
    repo, plugin, base, pin, b104 = ctx["repo"], ctx["plugin"], ctx["base_full"], ctx["pin_full"], ctx["base104"]
    show = lambda c, p: git(repo, "show", f"{c}:{plugin}/{p}").decode()
    d106 = sorted(set(ctx["added"]) | set(ctx["changed"]))
    kept = ctx["kept"]
    before = set(doc.t.split("\n"))
    ctrl = git(repo, "rev-parse", base + "^").decode().strip()
    if ctrl != LM_CONTROL_FULL or b104 != LM_CONTROL_FULL:
        raise SystemExit(f"PART L: 7835906's parent is {ctrl}, not 994d660 ({LM_CONTROL_FULL}) — LM-G1's control would be wrong")

    # ── LM_HUNKS: 5.18.105's list, byte for byte, as Git's 994d660 → 7835906 diff gives it ──
    a105, c105 = delta(repo, ctrl, base, plugin)
    rt105 = [f for f in c105 if not f.startswith("tests/") and f not in ("SAFETY.md", "manifest.json")]
    entries, blocks = entries_105(repo, plugin, ctrl, base, rt105)
    head = "LM_HUNKS=\"$(cat <<'HUNKS'\n"
    if doc.t.count(head) != 1:
        raise SystemExit("PART L: LM_HUNKS' heredoc is not found once")
    i = doc.t.index(head) + len(head)
    j = doc.t.index("\nHUNKS\n)\"\n", i)
    if doc.t[i:j] != "\n".join(entries):
        raise SystemExit("PART L: the script's LM_HUNKS is not Git's 994d660 → 7835906 diff, entry for entry")
    ln_n = sum(1 for e in entries if e.split("|")[1].startswith("LN-") and e.split("|")[2] != "0")
    doc.must(f"\nHUNKS\n)\"\nLM_N={len(entries)}        # the entries above\n", 1, why="LM_N is the list's count")
    ln_old = f"LM_LN_N={ln_n}     # of them, the masked log lines (an LN, with an old block): what a rollback writes numbers in again\n"
    doc.must(ln_old, 1, why="LM_LN_N is the list's count of masked log lines")
    lns = sorted({x for e in entries for x in re.findall(r"LN-\d\d", e.split("|")[1])})
    if len(lns) < 30:
        raise SystemExit(f"PART L: only {len(lns)} LNs named by the hunks' markers")
    lmf = sorted({e.split("|")[0] for e in entries})
    if len(lmf) != N_LM_FILES or any(f not in rt105 for f in lmf):
        raise SystemExit(f"PART L: LM_HUNKS names {len(lmf)} files, not {N_LM_FILES} of 5.18.105's runtime files")
    # ── 5.18.106 leaves every one of them, and lib/SalesFollowupChecks.php, as 7835906 has it ──
    if set(lmf) & set(d106):
        raise SystemExit(f"PART L: 5.18.106 changes an LM file: {sorted(set(lmf) & set(d106))}")
    for f in lmf + [CHECKS]:
        if f not in kept or sha_at(repo, base, f"{plugin}/{f}") != sha_at(repo, pin, f"{plugin}/{f}"):
            raise SystemExit(f"PART L: {f} is not kept by sha256, or differs between 7835906 and the pin")
    held_in_tests = []
    for f in d106:
        pt = show(pin, f).split("\n")
        bt = show(base, f).split("\n") if f in ctx["changed"] else []
        for g, label, ob, nb in blocks:
            got = (runs(pt, ob) if ob else 0, runs(pt, nb))
            if got == (0, 0):
                continue
            if not f.startswith("tests/"):
                raise SystemExit(f"PART L: 5.18.106's {f} holds LM's {g} {label} block at the pin")
            was = (runs(bt, ob) if ob else 0, runs(bt, nb)) if bt else (0, 0)
            if got != was:
                raise SystemExit(f"PART L: 5.18.106's test {f} holds LM's {g} {label} block {got}, not as at 7835906 {was}")
            held_in_tests.append(f"{f}:{g}:{label}")
    ctx.update(lm_n=len(entries), lm_ln_n=ln_n, lm_files=lmf, lm_held_in_tests=held_in_tests,
               lm_control_commit=LM_CONTROL_FULL)
    # ── the on-chase precondition's two strings (5.18.105's assertion, at 7835906 and the pin) ──
    chk = show(pin, CHECKS)
    m = re.search(r"case 'chase':\n\s*\$src = .*?\n\s*if \(strpos\(\$src, \"(.*?)\"\) === false \|\| strpos\(\$src, \"(.*?)\"\) === false\)", chk)
    if not m:
        raise SystemExit("PART L: the on-chase precondition's strings were not found in lib/SalesFollowupChecks.php")
    w2 = [s.replace("\\$", "$") for s in (m.group(1), m.group(2))]
    for c in (base, pin):
        ev = show(c, "evo_webhook.php").split("\n")
        for s in w2:
            if sum(1 for l in ev if s in l) != 1:
                raise SystemExit(f"PART L: a W2 string is not on exactly one line of evo_webhook.php at {c[:7]}")
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
    if len(hits) != 1 or [s.replace("\\$", "$") for s in re.fullmatch(w2_line, hits[0]).groups()] != w2:
        raise SystemExit("PART L: lm_w2_strings' reading does not give the two strings the derivation read")
    for s in w2:
        doc.mustnot(s, why="W2 string carried")
    doc.must("lm_w2_strings() {\n", 1)
    doc.mustnot("LM_CONTROL_COMMIT", why="a new name")
    doc.must(CA1_LINE, 1, why="LM-CA1's reading line, before")

    # ── the header: why the list is kept, and what LM-G1 now requires ──
    doc.sub(HDR_OLD,
            "# Every count below was asserted on Git's two trees by the derivation. An entry that only inserts has no old block (0|-).\n"
            "# 5.18.106 (root docs/75 §L.6) — 5.18.105's masking still holds: the list is 5.18.105's, kept byte for byte, and\n"
            "# derive106.py asserts it equal to Git's 994d660 → 7835906 diff, recomputed as above (7835906 was 5.18.105's pin);\n"
            f"# none of 5.18.106's {len(d106)} files is one of its {len(lmf)} files, no runtime file among them holds one of its\n"
            f"# blocks, and each of the {len(lmf)}, lib/SalesFollowupChecks.php too, is the same file at 7835906 and at the pin\n"
            "# (PREV_KEPT holds them by sha256). LM-G1's control is fixed: 994d660 (LM_CONTROL_COMMIT, below), where each old\n"
            "# block reads once and each new block nowhere — at 7835906 the masked lines already stand, so a control on\n"
            "# $BASELINE_COMMIT would refuse. The masked form is required at the pin, at 7835906 and as installed (LM-G1), after\n"
            "# the copy (LM-CA1, LM-ON) and after a rollback to 7835906 (LM-RB1).\n"
            "LM_HUNKS=\"$(cat <<'HUNKS'\n", why="LM header")
    doc.sub(ln_old,
            f"LM_LN_N={ln_n}     # of them, the masked log lines (an LN, with an old block): masked since 5.18.105, kept so by 5.18.106\n"
            f"LM_CONTROL_COMMIT=\"{LM_CONTROL_FULL}\"   # LM-G1's control (5.18.106): 994d660, 5.18.104's release commit, fixed\n",
            why="LM_LN_N and LM_CONTROL_COMMIT")
    doc.sub("# exactly once. The derivation asserted that this reading gives the two strings it read itself (derive105.py, partL).\n",
            "# exactly once. Both derivations asserted that this reading gives the strings they read (derive105.py, derive106.py).\n",
            why="W2 comment")

    # ── lm_count: a control mode; lm_expect: pin (the masked form) and control (994d660's) ──
    doc.sub("# lm_count pin|base|inst — every entry counted in its file: the pin's or 994d660's from Git, or the installed one; prints\n"
            "# label=old/new words (-1 where an entry has no old block; a file that cannot be read counts as empty)\n",
            "# lm_count pin|base|control|inst — every entry counted in its file: the pin's, $BASELINE_COMMIT's (7835906) or the\n"
            "# control's (LM_CONTROL_COMMIT, 994d660) from Git, or the installed one; prints label=old/new words (-1 where an entry\n"
            "# has no old block; a file that cannot be read counts as empty)\n", why="lm_count comment")
    doc.insert_after("      base) git -C \"$REPO\" show \"$BASELINE_COMMIT:$PLUGIN/$f\" > \"$x\" 2>/dev/null || : > \"$x\" ;;\n",
                     "      control) git -C \"$REPO\" show \"$LM_CONTROL_COMMIT:$PLUGIN/$f\" > \"$x\" 2>/dev/null || : > \"$x\" ;;\n",
                     why="lm_count control")
    doc.sub("# lm_expect pin|base — what each entry must read: in the pin, its new block once and its old block nowhere (0/1, or\n"
            "# -1/1 for an insert); in 994d660, its old block once and its new block nowhere (1/0, or -1/0)\n",
            "# lm_expect pin|control — what each entry must read: \"pin\", the masked form — in the pin, in 7835906 and installed\n"
            "# since 5.18.105 — its new block once and its old block nowhere (0/1, or -1/1 for an insert); \"control\", 994d660's\n"
            "# form (LM_CONTROL_COMMIT), its old block once and its new block nowhere (1/0, or -1/0); any other word expects a form\n"
            "# no file reads, so it never passes\n", why="lm_expect comment")
    doc.sub("    else w=\"$w $id=$( [ \"$on\" = \"0\" ] && echo -1 || echo 1)/0\"; fi\n",
            "    elif [ \"$1\" = \"control\" ]; then w=\"$w $id=$( [ \"$on\" = \"0\" ] && echo -1 || echo 1)/0\"\n"
            "    else w=\"$w $id=no-such-form\"; fi\n", why="lm_expect modes")

    # ── LM-G1: the control fixed to 994d660; the masked form at the pin, at the baseline and installed ──
    doc.between(STAGE_START, STAGE_END,
        "# lm_stage — LM-G1 and LM-G2, stage A (a deploy and --check), before anything changes: refusals. LM-G1 since 5.18.106\n"
        "# (root docs/75 §L.6) — 5.18.105's masking still holds: the control first, LM_CONTROL_COMMIT (994d660, fixed), whose\n"
        "# files from Git hold each entry's old block once and its new block nowhere; then the masked form — each new block\n"
        "# once, each old block nowhere — in the pin's files from Git, in $BASELINE_COMMIT's from Git and in the installed files.\n"
        "lm_stage() {\n"
        "  local g b k n d w\n"
        "  hdr \"A. The masked lines (5.18.105's LM, kept by $EXPECTED_VERSION; read-only — ${LM_CONTROL_COMMIT:0:7}'s files, the pin's and $BASELINE_COMMIT's from Git and the installed ones, counted as blocks)\"\n"
        "  b=\"$(lm_count control)\"; d=\"$(lm_diff \"$b\" \"$(lm_expect control)\")\"\n"
        "  [ -z \"$d\" ] || stop \"NO-GO (LM-G1): the control did not hold — in ${LM_CONTROL_COMMIT:0:7}'s files from Git (5.18.104's release commit, before 5.18.105 masked the lines) each entry's old block must read once and its new block nowhere ($d; label(old/new runs)): the reader, or this list, cannot see the release. Nothing was changed. Send the log file\"\n"
        "  g=\"$(lm_count pin)\"; d=\"$(lm_diff \"$g\" \"$(lm_expect pin)\")\"\n"
        "  [ -z \"$d\" ] || stop \"NO-GO (LM-G1): the pin's files from Git do not hold 5.18.105's masked lines as the derivation pinned them ($d; label(old/new runs)) — this script is for another build. Nothing was changed. Send the log file\"\n"
        "  k=\"$(lm_count base)\"; d=\"$(lm_diff \"$k\" \"$(lm_expect pin)\")\"\n"
        "  [ -z \"$d\" ] || stop \"NO-GO (LM-G1): $BASELINE_COMMIT's files from Git do not hold 5.18.105's masked lines ($d; label(old/new runs)) — this script is for another baseline. Nothing was changed. Send the log file\"\n"
        "  n=\"$(lm_count inst)\"; d=\"$(lm_diff \"$n\" \"$(lm_expect pin)\")\"\n"
        "  [ -z \"$d\" ] || stop \"NO-GO (LM-G1): the installed files do not hold 5.18.105's masked lines ($d; label(old/new runs)) — the live plugin is not $BASELINE_VERSION where it masked them. Nothing was changed. Send the log file\"\n"
        "  ok \"LM-G1 5.18.105's masking holds: each of the $LM_N entries' new block reads once and its old block nowhere — $LM_LN_N masked log lines, the helper, the report's column fix and webhook.php's loader — in the pin's files from Git, in $BASELINE_COMMIT's from Git and in the installed files; in ${LM_CONTROL_COMMIT:0:7}'s (the control, 5.18.104's release commit) each old block once and each new block nowhere; counted as blocks, markers aside\"\n"
        + STAGE_END, keep_ends=False, why="LM-G1")

    # ── LM-CA1 / LM-ON / LM-CA2: carried; the masked lines are 5.18.105's ──
    doc.subs([
        ("— $EXPECTED_VERSION's masked lines in place, counted as blocks\"\n",
         "— 5.18.105's masked lines, which $EXPECTED_VERSION keeps byte for byte, in place, counted as blocks\"\n"),
        ("  else bad \"$c the installed files do not hold $EXPECTED_VERSION's masked lines ($d; label(old/new runs))\"; fi\n",
         "  else bad \"$c the installed files do not hold 5.18.105's masked lines, which $EXPECTED_VERSION keeps ($d; label(old/new runs))\"; fi\n"),
        ("  note \"$c the sinks $EXPECTED_VERSION's lines write, records stamped",
         "  note \"$c the sinks the masked lines write, records stamped"),
    ], why="LM-CA1 words")

    # ── LM-RB1 inverted: the masked form still there after a rollback to 7835906; the capitals note gone ──
    doc.between(RB1_START, RB1_END,
        "# lm_rb1 — LM-RB1, after the put-back (RB105): inverted since 5.18.106 (root docs/75 §L.6) — 5.18.105's masking still\n"
        "# holds: the installed text holds each entry's new block once and its old block nowhere, counted as blocks, since\n"
        "# 7835906 carries the masked lines too; a rollback to it brings no number back into a log line.\n"
        "lm_rb1() {\n"
        "  local g d\n"
        "  g=\"$(lm_count inst)\"; d=\"$(lm_diff \"$g\" \"$(lm_expect pin)\")\"   # the masked form, still: $BASELINE_COMMIT carries it\n"
        "  if [ -z \"$d\" ]; then ok \"LM-RB1 the installed files hold each of the $LM_N entries' new block once and its old block nowhere after the rollback — 5.18.105's masked lines still in place under $BASELINE_VERSION, counted as blocks\"\n"
        "  else bad \"LM-RB1 the installed files do not hold 5.18.105's masked lines after the rollback ($d; label(old/new runs)) — $BASELINE_COMMIT carries them, so this put-back is not $BASELINE_VERSION's where they stand\"; fi\n"
        "}\n", keep_ends=False, why="LM-RB1 inverted")

    # ── the result ──
    doc.must(head + "\n".join(entries) + "\nHUNKS\n)\"\n", 1, why="LM_HUNKS kept byte for byte")
    doc.must(f"LM_CONTROL_COMMIT=\"{LM_CONTROL_FULL}\"", 1)
    doc.must("show \"$LM_CONTROL_COMMIT:$PLUGIN/$f\"", 1, why="LM_CONTROL_COMMIT read by lm_count")
    doc.must("lm_expect base", 0)
    doc.must("$(lm_expect control)", 1)
    doc.must("$(lm_count control)", 1)
    doc.must("$(lm_count base)", 1)
    doc.must("$(lm_expect pin)", 5, why="the masked form: LM-G1 three times, LM-CA1/LM-ON, LM-RB1")
    doc.must(CA1_LINE, 1, why="LM-CA1's reading line stays unique")
    for s in ("LOG LINES WRITE PHONE NUMBERS AGAIN", "what a rollback writes numbers in again",
              "$BASELINE_VERSION's lines back, counted as blocks", "$BASELINE_VERSION's where $EXPECTED_VERSION masked its lines",
              "$EXPECTED_VERSION's masked lines", "the count of log lines that write a number again", "$EXPECTED_VERSION's lines write"):
        doc.mustnot(s, why="LM's 5.18.105 texts gone")
    a = doc.t.index("# ── 5.18.105: the masked lines (LM; root docs/74 §H.4.5)")
    b = doc.t.index("\n}\n", doc.t.index("lm_rb1() {\n")) + 3
    reg = doc.t[a:b]
    if re.search(r"numbers( in)? again", reg, re.I) or "WRITES PHONE" in reg:
        raise SystemExit("PART L: a 'numbers again' text is left in the LM region")
    if any(len(l) > 120 for l in reg.split("\n") if l.startswith("#") and not l.startswith("# ── ") and l not in before):
        raise SystemExit("PART L: a comment line partL wrote in the LM region is longer than 120 characters")
