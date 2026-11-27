# Part L — 5.18.105's masking still holds (root docs/76 §T.2, LM; root docs/75 §L.6 before it). LM_HUNKS is kept byte for
# byte, with LM_CONTROL_COMMIT 994d660: the derivation recomputes 5.18.105's hunks exactly as wip/derive-5.18.106/partL.py
# did (994d660 → 7835906, diff -U0, 5.18.105's runtime files, one entry per hunk whose text, its 5.18.105 marker lines
# aside, is not empty) and asserts the script's LM_HUNKS equal to it, with LM_N and LM_LN_N its counts.
# "The same file at baseline and pin" becomes (§T.2): two of LM_HUNKS' 21 files are in 5.18.107's delta —
# evo_webhook.php (LN-01) and cron/wa_watchdog.php (LN-08), exactly those two — and each holds every one of its entries'
# masked block once and its old block nowhere, at the pin as at bf29cbd; the other 19, and lib/SalesFollowupChecks.php,
# are the same file at bf29cbd and the pin, each kept by sha256; no runtime file of the delta holds an old block, and no
# runtime file of the delta but those two holds a masked one; a delta test that holds a block holds it exactly as at
# bf29cbd (an added test holds none). The on-chase precondition's two strings are re-read as lm_w2_strings reads them, at
# bf29cbd and the pin (evo_webhook.php changes in 5.18.107, so this matters now). LM-RB1 is as in 5.18.106: the masked
# form after a rollback to bf29cbd, which carries it.
# Edits, the LM region only: the header's 5.18.106 paragraph becomes 5.18.107's; LM_LN_N's comment; the W2 comment's
# derivations; lm_count's and lm_expect's names of the baseline; LM-RB1's comment. Not touched: lm_rb1's "(RB106)" name
# (partR renames the rollback), the stage-A call site's comment of lm_stage (partT).
import re, hashlib
from d107lib import git, sha_at, delta

LM_CONTROL_FULL = "994d660628cc5b1b42bee648b44b58e389f44bf2"   # 5.18.104's release commit
B105_FULL = "7835906a0e4af49557f7c0eb994cb9129637ca00"          # 5.18.105's release commit (its parent is 994d660)
N_LM_FILES = 21
LM_CHANGED = {"evo_webhook.php": ["LN-01"], "cron/wa_watchdog.php": ["LN-08"]}   # §T.2: the two LM files 5.18.107 changes
CHECKS = "lib/SalesFollowupChecks.php"
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


def entries_105(repo, plugin, old, new, files):
    """5.18.105's partL computation, old → new (wip/derive-5.18.106/partL.py's, unchanged)."""
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
            entries.append(f"{f}|{label}|{len(ob)}|{sha(ob)}|{len(nb)}|{sha(nb)}")
            blocks.append((f, label, ob, nb))
    return entries, blocks


def apply(doc, ctx):
    repo, plugin, base, pin = ctx["repo"], ctx["plugin"], ctx["base_full"], ctx["pin_full"]
    show = lambda c, p: git(repo, "show", f"{c}:{plugin}/{p}").decode()
    added, changed, kept = ctx["added"], ctx["changed"], ctx["kept"]
    d107 = sorted(set(added) | set(changed))
    if git(repo, "rev-parse", B105_FULL + "^").decode().strip() != LM_CONTROL_FULL:
        raise SystemExit("PART L: 7835906's parent is not 994d660 — LM-G1's control would be wrong")

    # ── LM_HUNKS: 5.18.105's list, byte for byte, as Git's 994d660 → 7835906 diff gives it ──
    a105, c105 = delta(repo, LM_CONTROL_FULL, B105_FULL, plugin)
    rt105 = [f for f in c105 if not f.startswith("tests/") and f not in ("SAFETY.md", "manifest.json")]
    entries, blocks = entries_105(repo, plugin, LM_CONTROL_FULL, B105_FULL, rt105)
    head = "LM_HUNKS=\"$(cat <<'HUNKS'\n"
    if doc.t.count(head) != 1:
        raise SystemExit("PART L: LM_HUNKS' heredoc is not found once")
    i = doc.t.index(head) + len(head)
    j = doc.t.index("\nHUNKS\n)\"\n", i)
    if doc.t[i:j] != "\n".join(entries):
        raise SystemExit("PART L: the script's LM_HUNKS is not Git's 994d660 → 7835906 diff, entry for entry")
    ln_n = sum(1 for e in entries if e.split("|")[1].startswith("LN-") and e.split("|")[2] != "0")
    doc.must(f"\nHUNKS\n)\"\nLM_N={len(entries)}        # the entries above\n", 1, why="LM_N is the list's count")
    lmf = sorted({e.split("|")[0] for e in entries})
    if len(lmf) != N_LM_FILES or any(f not in rt105 for f in lmf):
        raise SystemExit(f"PART L: LM_HUNKS names {len(lmf)} files, not {N_LM_FILES} of 5.18.105's runtime files")

    # ── §T.2: exactly two LM files change, each masked at the pin as at bf29cbd; the other 19 and CHECKS kept ──
    in_delta = sorted(set(lmf) & set(d107))
    if in_delta != sorted(LM_CHANGED) or any(f not in changed for f in in_delta):
        raise SystemExit(f"PART L: 5.18.107 changes LM files {in_delta}, not exactly {sorted(LM_CHANGED)} (changed)")
    for f, lns in LM_CHANGED.items():
        mine = [(g, label, ob, nb) for g, label, ob, nb in blocks if g == f]
        if sorted(x[1] for x in mine) != sorted(lns):
            raise SystemExit(f"PART L: {f}'s LM entries are {[x[1] for x in mine]}, not {lns}")
        for c in (base, pin):
            t = show(c, f).split("\n")
            for g, label, ob, nb in mine:
                got = (runs(t, ob), runs(t, nb))
                if got != ((0 if ob else -1), 1):
                    raise SystemExit(f"PART L: {f} {label} at {c[:7]}: old/new runs {got}, not the masked form")
    for f in [x for x in lmf if x not in LM_CHANGED] + [CHECKS]:
        if f not in kept or sha_at(repo, base, f"{plugin}/{f}") != sha_at(repo, pin, f"{plugin}/{f}"):
            raise SystemExit(f"PART L: {f} is not kept by sha256, or differs between bf29cbd and the pin")
    held_in_tests = []
    for f in d107:
        pt = show(pin, f).split("\n")
        bt = show(base, f).split("\n") if f in changed else []
        for g, label, ob, nb in blocks:
            got = (runs(pt, ob) if ob else 0, runs(pt, nb))
            if got == (0, 0):
                continue
            if not f.startswith("tests/"):
                if got[0] != 0:
                    raise SystemExit(f"PART L: 5.18.107's {f} holds LM's old {g} {label} block at the pin")
                if f != g:
                    raise SystemExit(f"PART L: 5.18.107's {f} holds LM's masked {g} {label} block, outside its own file")
                continue
            was = (runs(bt, ob) if ob else 0, runs(bt, nb)) if bt else (0, 0)
            if got != was:
                raise SystemExit(f"PART L: 5.18.107's test {f} holds LM's {g} {label} block {got}, not as at bf29cbd {was}")
            held_in_tests.append(f"{f}:{g}:{label}")
    ctx.update(lm_n=len(entries), lm_ln_n=ln_n, lm_files=lmf, lm_held_in_tests=held_in_tests)

    # ── the on-chase precondition's two strings, at bf29cbd and the pin ──
    chk = show(pin, CHECKS)
    w2_line = r' *if \(strpos\(\$src, "([^"]+)"\) === false \|\| strpos\(\$src, "([^"]+)"\) === false\) \{'
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
    hits = [ln for ln in rng if re.fullmatch(w2_line, ln)]
    if len(hits) != 1:
        raise SystemExit("PART L: the on-chase precondition's line is not found once in lib/SalesFollowupChecks.php")
    w2 = [s.replace("\\$", "$") for s in re.fullmatch(w2_line, hits[0]).groups()]
    for c in (base, pin):
        ev = show(c, "evo_webhook.php").split("\n")
        for s in w2:
            if sum(1 for l in ev if s in l) != 1:
                raise SystemExit(f"PART L: a W2 string is not on exactly one line of evo_webhook.php at {c[:7]}")
    doc.must("lm_w2_strings() {\n", 1)

    # ── the texts ──
    doc.sub("# 5.18.106 (root docs/75 §L.6) — 5.18.105's masking still holds: the list is 5.18.105's, kept byte for byte, and\n"
            "# derive106.py asserts it equal to Git's 994d660 → 7835906 diff, recomputed as above (7835906 was 5.18.105's pin);\n"
            "# none of 5.18.106's 23 files is one of its 21 files, no runtime file among them holds one of its\n"
            "# blocks, and each of the 21, lib/SalesFollowupChecks.php too, is the same file at 7835906 and at the pin\n"
            "# (PREV_KEPT holds them by sha256). LM-G1's control is fixed: 994d660 (LM_CONTROL_COMMIT, below), where each old\n"
            "# block reads once and each new block nowhere — at 7835906 the masked lines already stand, so a control on\n"
            "# $BASELINE_COMMIT would refuse. The masked form is required at the pin, at 7835906 and as installed (LM-G1), after\n"
            "# the copy (LM-CA1, LM-ON) and after a rollback to 7835906 (LM-RB1).\n",
            "# 5.18.107 (root docs/76 §T.2; 5.18.106's root docs/75 §L.6 before it) — 5.18.105's masking still holds: the list is\n"
            "# 5.18.105's, kept byte for byte, and derive107.py asserts it equal to Git's 994d660 → 7835906 diff, recomputed as\n"
            f"# above (7835906 was 5.18.105's pin). Two of its {len(lmf)} files are in 5.18.107's delta — evo_webhook.php (LN-01) and\n"
            "# cron/wa_watchdog.php (LN-08) — each with every one of its entries' masked block once and its old block nowhere, at\n"
            f"# the pin as at bf29cbd; the other {len(lmf) - len(LM_CHANGED)}, lib/SalesFollowupChecks.php too, are the same file at bf29cbd and at the\n"
            "# pin (PREV_KEPT holds them by sha256); no runtime file of the delta holds an old block. LM-G1's control is fixed:\n"
            "# 994d660 (LM_CONTROL_COMMIT, below), where each old block reads once and each new block nowhere — since 5.18.105 the\n"
            "# masked lines stand in every release, so a control on $BASELINE_COMMIT would refuse. The masked form is required at\n"
            "# the pin, at $BASELINE_COMMIT and as installed (LM-G1), after the copy (LM-CA1, LM-ON) and after a rollback to\n"
            "# $BASELINE_COMMIT (LM-RB1).\n", why="LM header (§T.2)")
    doc.sub(f"LM_LN_N={ln_n}     # of them, the masked log lines (an LN, with an old block): masked since 5.18.105, kept so by 5.18.106\n",
            f"LM_LN_N={ln_n}     # of them, the masked log lines (an LN, with an old block): masked since 5.18.105, kept so by 5.18.106 and 5.18.107\n",
            why="LM_LN_N's comment")
    doc.sub("# exactly once. Both derivations asserted that this reading gives the strings they read (derive105.py, derive106.py).\n",
            "# exactly once. Each derivation asserted that this reading gives the strings it read (derive105.py, derive106.py,\n"
            "# derive107.py — at bf29cbd and the pin: 5.18.107 changes evo_webhook.php).\n", why="W2 comment")
    doc.sub("# lm_count pin|base|control|inst — every entry counted in its file: the pin's, $BASELINE_COMMIT's (7835906) or the\n",
            "# lm_count pin|base|control|inst — every entry counted in its file: the pin's, $BASELINE_COMMIT's (bf29cbd) or the\n",
            why="lm_count's baseline")
    doc.sub("# lm_expect pin|control — what each entry must read: \"pin\", the masked form — in the pin, in 7835906 and installed\n"
            "# since 5.18.105 — its new block once",
            "# lm_expect pin|control — what each entry must read: \"pin\", the masked form — in the pin, in bf29cbd and installed,\n"
            "# as in every release since 5.18.105 — its new block once", why="lm_expect's baseline")
    doc.sub("# lm_rb1 — LM-RB1, after the put-back (RB106): inverted since 5.18.106 (root docs/75 §L.6) — 5.18.105's masking still\n"
            "# holds: the installed text holds each entry's new block once and its old block nowhere, counted as blocks, since\n"
            "# 7835906 carries the masked lines too; a rollback to it brings no number back into a log line.\n",
            "# lm_rb1 — LM-RB1, after the put-back (RB106): inverted since 5.18.106 (root docs/75 §L.6), as in 5.18.106 (root docs/76\n"
            "# §T.2) — 5.18.105's masking still holds: the installed text holds each entry's new block once and its old block\n"
            "# nowhere, counted as blocks, since bf29cbd carries the masked lines too; a rollback to it brings no number back\n"
            "# into a log line.\n", why="LM-RB1's comment")

    # ── the result ──
    doc.must(head + "\n".join(entries) + "\nHUNKS\n)\"\n", 1, why="LM_HUNKS kept byte for byte")
    doc.must(f"LM_CONTROL_COMMIT=\"{LM_CONTROL_FULL}\"", 1)
    doc.must("$(lm_expect pin)", 5, why="the masked form: LM-G1 three times, LM-CA1/LM-ON, LM-RB1")
    a = doc.t.index("# ── 5.18.105: the masked lines (LM; root docs/74 §H.4.5)")
    b = doc.t.index("\n}\n", doc.t.index("lm_rb1() {\n")) + 3
    reg = doc.t[a:b]
    for s in ("derive106.py asserts it equal", "same file at 7835906", "(7835906) or the", "in 7835906 and installed",
              "7835906 carries the masked lines", "none of 5.18.106's"):
        if s in reg:
            raise SystemExit(f"PART L: {s!r} is left in the LM region")
