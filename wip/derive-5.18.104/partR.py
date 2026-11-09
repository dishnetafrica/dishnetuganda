# Part R — the one rollback sequence (root docs/73 §H.2.16; rulings R7, R11, R12, R14, R17): qf_byhand, qf_rb1 (QF-RB1,
# with QF-RB2's note) and qf_rb3 (QF-RB3), inserted after partF's functions; QF-RB1 called after ROLLBACK is typed and
# before do_rollback (the --rollback run), and before the deploy's own automatic rollback (V1); QF-RB3 called in RB
# before its closing note. The put-back of 5.18.103 itself (do_rollback) is 5.18.103's, unchanged: QF-RB1 runs first,
# and a failure stops before it. QC-RB1 (5.18.103's) is re-pointed here to 5.18.104 — qcrb1() below (DS1-fix1, R02).
import os, re, subprocess
from d104lib import git

HERE = os.path.dirname(os.path.abspath(__file__))
TN_ANCHOR = "# ── 5.18.100: a technician may own a number (TN; read-only — as text, on a copy of the book, and the live store row) ──────\n"


def apply(doc, ctx):
    rb = open(os.path.join(HERE, "qf_rb.sh.txt"), encoding="utf-8").read()
    if "@@" in rb:
        raise SystemExit("QF-RB: a placeholder in qf_rb.sh.txt")
    # after partF's functions, which end just before the same anchor
    doc.must("\nqf_on() {", 1, why="partF ran first")
    doc.insert_before(TN_ANCHOR, rb + "\n", 1, why="QF-RB functions")
    for fn in ("qf_byhand", "qf_rb1", "qf_rb3"):
        doc.must(f"\n{fn}() {{", 1, why=f"{fn} defined once")
    if doc.t.index("\nqf_on() {") > doc.t.index("\nqf_byhand() {") or doc.t.index("\nqf_rb3() {") > doc.t.index(TN_ANCHOR):
        raise SystemExit("QF-RB: the functions are not between partF's and the TN section")

    # QF-RB1 in the --rollback run: after ROLLBACK is typed (TOUCHED set), before the put-back
    doc.insert_before('    if do_rollback; then ok "container serves $BASELINE_COMMIT ($BASELINE_VERSION)"; else stop "the rollback did not verify',
                      '    # 5.18.104 (root docs/73 §H.2.16): QF-RB1 first — every 5.18.104 switch off in the one sequence, under the installed\n'
                      '    # code, its hooks and end states asserted; it stops here, before the put-back, unless every part reads clean.\n'
                      '    qf_rb1\n', 1, why="QF-RB1 before the --rollback's put-back")
    doc.must('    [ "$ANSWER" = "ROLLBACK" ] || stop "not confirmed"\n    TOUCHED=1\n    DEPLOY_STARTED="$(date -u +%Y-%m-%dT%H:%M:%SZ)"\n'
             '    # 5.18.104 (root docs/73 §H.2.16): QF-RB1 first', 1, why="QF-RB1 after ROLLBACK is typed")
    # QF-RB1 before the deploy's own automatic rollback (V1): the same one sequence, then 5.18.103 put back
    doc.sub('echo "  ROLLING BACK — the public address must never redirect"; if do_rollback; then QCV1_RB=ok; else QCV1_RB=failed; fi',
            'echo "  ROLLING BACK — the public address must never redirect"; qf_rb1; if do_rollback; then QCV1_RB=ok; else QCV1_RB=failed; fi',
            1, why="QF-RB1 before V1's automatic rollback")
    doc.must("\n    qf_rb1\n", 1, why="called in the --rollback run")
    doc.must("; qf_rb1; if do_rollback; then QCV1_RB=ok", 1, why="called in V1's automatic rollback")
    calls = [l for l in doc.t.split("\n") if "qf_rb1" in l and not l.lstrip().startswith("#") and "qf_rb1() {" not in l]
    if len(calls) != 2:
        raise SystemExit(f"QF-RB1: {len(calls)} call lines, expected 2")
    # QF-RB3 in RB, before its closing note
    doc.insert_before('note "RB the code is $BASELINE_VERSION again. The rollback restores code only.',
                      '# QF-RB3 — 5.18.104 (root docs/73 §H.2.16): the files 5.18.104 changed are 5.18.103\'s again, master registers none of\n'
                      '# the four jobs, no 5.18.104 key set, 0 open quote follow-ups; VN-RB6\'s instruction when OpenAI was ever called. Read-only.\n'
                      'qf_rb3\n', 1, why="QF-RB3 in RB")
    doc.must("\nqf_rb3\n", 1)

    # ── QC-RB1, re-pointed to 5.18.104 (DS1-fix1, R02) — as 5.18.103 re-pointed 5.18.102's QL-RB1 and QL-RB3 to its own ──
    qcrb1(doc, ctx)


# QC-RB1 asserted that nothing 5.18.102 installs names 5.18.103's catalogue (UcrmCatalogue, ucrm_catalogue). Carried as it
# stood it cannot pass in a 5.18.104 rollback: the code put back is 5.18.103's, which IS the catalogue's
# (includes/navigation.php names UcrmCatalogue twice, lib/UcrmCatalogue.php four times), and its pin-side controls now read
# 5.18.104's changed files. Its question stays — nothing the rollback installs reaches what the release leaves on disk —
# so it is kept, not retired, and asked of 5.18.104: the words 5.18.104 adds (the class of every PHP file it adds under
# lib/ and workers/, the job, screen, tool and handler each added file is, and every table 089–091 create), less any
# 5.18.103 already names anywhere (Handover and MediaWorker among them; dropped and printed by the derivation, never
# typed). Its marks, its counts and its control are computed here from Git, base to pin, with the reader the script
# applies (lines naming any of the words). It is also what QF-RB2's note rests on: 5.18.103 reads none of them.
def qcrb1(doc, ctx):
    repo, plugin, base, pin = ctx["repo"], ctx["plugin"], ctx["base_full"], ctx["pin_full"]
    show = lambda c, f: git(repo, "show", f"{c}:{plugin}/{f}")
    cand = set()
    for rel in ctx["added"]:
        d, b = os.path.split(rel)
        if rel.startswith("tests/") or not b.endswith(".php"):
            continue
        if d in ("lib", "workers", "cron", "tools", "tabs/sales", "tabs/engage", "includes/post", ""):
            cand.add(b[:-4])
    tables = set()
    for m in ctx["migrations"]:
        tables |= set(re.findall(r"CREATE TABLE IF NOT EXISTS ([a-z_]+)", show(pin, m).decode()))
    cand |= tables
    kept, dropped = [], []
    for w in sorted(cand):
        r = subprocess.run(["git", "-C", repo, "grep", "-lF", w, base, "--", plugin], capture_output=True)
        if r.returncode not in (0, 1):
            raise SystemExit(f"QC-RB1: git grep failed for {w}")
        (dropped if r.stdout.strip() else kept).append(w)
    must_keep = {"WaSendBudget", "QuoteChase", "QuoteTracker", "SalesFollowupLadder", "WaSeenMarks", "VoiceAck", "OfferSwitch",
                 "QuoteAgentCopy", "quote_chase", "quote_daily_list", "offers_send", "sales_followup", "my_quotes",
                 "quote_tracker", "wa_auto_sends", "wa_seen_marks", "wa_voice_calls", "wa_offer_recipients"}
    if not must_keep <= set(kept):
        raise SystemExit(f"QC-RB1: 5.18.103 already names {sorted(must_keep - set(kept))} — the re-pointed marks would not hold")
    if any(any(ch in w for ch in " |'\"\\$*?[") for w in kept):
        raise SystemExit("QC-RB1: a word cannot travel in the heredoc as it is")
    kb = [w.encode() for w in kept]
    nlines = lambda data: sum(1 for ln in data.split(b"\n") if any(w in ln for w in kb))   # as grep -cF -e … counts: lines
    marks = []
    for f in ctx["changed"]:
        n = nlines(show(pin, f))
        if n:
            if nlines(show(base, f)) != 0:
                raise SystemExit(f"QC-RB1: {f} at 5.18.103 names a mark")
            marks.append(f"{f}|{n}")
    ctl = nlines(show(pin, "lib/WaSendBudget.php"))
    if not marks or ctl < 1:
        raise SystemExit("QC-RB1: no changed file of the pin names a mark, or the control reads none")
    print(f"  QC-RB1: {len(kept)} words ({len(dropped)} named at 5.18.103 and dropped: {', '.join(dropped)}); "
          f"{len(marks)} changed files name them; control lib/WaSendBudget.php ×{ctl}")
    words = "\n".join(kept)
    QCRB1 = (
        "# QC-RB1 — carried from 5.18.103 and re-pointed to 5.18.104 (root docs/73 §H.2.16, QF-RB2; as 5.18.103 re-pointed\n"
        "# 5.18.102's QL-RB1 and QL-RB3 to its own): nothing in 5.18.103 reaches what 5.18.104 adds and leaves. 5.18.103's\n"
        "# subject — its uCRM catalogue — is installed code again after this rollback and read by it, so that question has no\n"
        "# subject here; the check's own question does: no installed file names a word 5.18.104 adds — the class of each PHP\n"
        "# file it adds under lib/ and workers/, the job, screen, tool and handler each added file is, and every table 089–091\n"
        f"# create — less those 5.18.103 already names anywhere ({', '.join(dropped) or 'none'}; the derivation drops them).\n"
        "# The files 5.18.104 adds stay on disk (deploy-hybrid.sh never deletes; counted above), and so do 089–091's tables\n"
        "# (QF-RB2), each reached by nothing. One reader for every file, the pin's and the installed alike (qc_rb1_n: the\n"
        "# lines naming any of the words, as text, from its input — never from a variable, which would drop a binary file's\n"
        "# NUL bytes): (1) each file 5.18.104 changes that names one in the release, by the count pinned here — the pin's file\n"
        "# from Git must read exactly that count, the control: a reader, a path or a word that could not see the release would\n"
        "# pass any installed file — and the installed one 0; (2) every file 5.18.103 tracks under the plugin (git ls-tree\n"
        "# $BASELINE_COMMIT, data/ aside), as installed, 0, beside its control, the pin's lib/WaSendBudget.php, which names\n"
        "# them on its pinned count of lines. A tracked file missing on disk fails, so the sweep never passes over a file it\n"
        "# did not read. Words, marks and counts are the release commit's, read with this reader by the derivation\n"
        "# (derive104.py, partR), which also asserts 5.18.103 names none of the words anywhere. Fixed words and counts only.\n"
        "QC_RB1_WORDS=\"$(cat <<'WORDS'\n" + words + "\nWORDS\n)\"\n"
        "QC_RB1_E=(); while IFS= read -r qr1_w; do [ -n \"$qr1_w\" ] && QC_RB1_E+=(-e \"$qr1_w\"); done <<< \"$QC_RB1_WORDS\"\n"
        "QC_RB1_MARKS=\"$(cat <<'MARKS'\n" + "\n".join(marks) + "\nMARKS\n)\"\n"
        "qc_rb1_n() { grep -cF \"${QC_RB1_E[@]}\" 2>/dev/null || true; }   # qc_rb1_n <INPUT — the lines of INPUT naming any of the words, or nothing when INPUT could not be read: one reader, pin and installed\n"
        "QCRB1_BAD=\"\"; QCRB1_N=0; QCRB1_SW_N=0; QCRB1_SW_HIT=0; QCRB1_SW_MISS=0\n"
        f"[ \"${{#QC_RB1_E[@]}}\" = \"{2 * len(kept)}\" ] || QCRB1_BAD=\"$QCRB1_BAD words:$(( ${{#QC_RB1_E[@]}} / 2 ))/{len(kept)}\"\n"
        "while IFS='|' read -r qr1_rel qr1_pin; do\n"
        "  [ -n \"$qr1_rel\" ] || continue\n"
        "  QCRB1_N=$((QCRB1_N + 1))\n"
        "  qr1_pn=\"$(git -C \"$REPO\" show \"$EXPECTED_PLUGIN_COMMIT:$PLUGIN/$qr1_rel\" 2>/dev/null | qc_rb1_n)\"\n"
        "  [ \"$qr1_pn\" = \"$qr1_pin\" ] || { QCRB1_BAD=\"$QCRB1_BAD $qr1_rel(the-control:the-pin-reads-${qr1_pn:-?}-not-$qr1_pin)\"; continue; }\n"
        "  [ -f \"$DEST/$qr1_rel\" ] || { QCRB1_BAD=\"$QCRB1_BAD $qr1_rel:missing\"; continue; }\n"
        "  qr1_in=\"$(qc_rb1_n <\"$DEST/$qr1_rel\")\"\n"
        "  [ \"$qr1_in\" = \"0\" ] || QCRB1_BAD=\"$QCRB1_BAD $qr1_rel:names-a-word×${qr1_in:-?}\"\n"
        "done <<< \"$QC_RB1_MARKS\"\n"
        f"[ \"$QCRB1_N\" = \"{len(marks)}\" ] || QCRB1_BAD=\"$QCRB1_BAD count:$QCRB1_N/{len(marks)}\"\n"
        "qr1_ctl=\"$(git -C \"$REPO\" show \"$EXPECTED_PLUGIN_COMMIT:$PLUGIN/lib/WaSendBudget.php\" 2>/dev/null | qc_rb1_n)\"\n"
        "case \"$qr1_ctl\" in\n"
        f"  {ctl})\n"
        "    while IFS= read -r -d '' qr1_rel; do\n"
        "      qr1_rel=\"${qr1_rel#\"$PLUGIN\"/}\"\n"
        "      case \"$qr1_rel\" in data/*) continue ;; esac\n"
        "      QCRB1_SW_N=$((QCRB1_SW_N + 1))\n"
        "      if [ ! -f \"$DEST/$qr1_rel\" ]; then QCRB1_SW_MISS=$((QCRB1_SW_MISS + 1)); [ \"$QCRB1_SW_MISS\" -gt 5 ] || QCRB1_BAD=\"$QCRB1_BAD $qr1_rel:missing\"; continue; fi\n"
        "      qr1_in=\"$(qc_rb1_n <\"$DEST/$qr1_rel\")\"\n"
        "      [ \"$qr1_in\" = \"0\" ] || { QCRB1_SW_HIT=$((QCRB1_SW_HIT + 1)); [ \"$QCRB1_SW_HIT\" -gt 5 ] || QCRB1_BAD=\"$QCRB1_BAD $qr1_rel:names-a-word×${qr1_in:-?}\"; }\n"
        "    done < <(git -C \"$REPO\" ls-tree -r -z --name-only \"$BASELINE_COMMIT\" -- \"$PLUGIN\" 2>/dev/null)\n"
        "    [ \"$QCRB1_SW_N\" -gt 0 ] || QCRB1_BAD=\"$QCRB1_BAD sweep:no-file-of-$BASELINE_COMMIT-listed\"\n"
        "    [ \"$QCRB1_SW_HIT\" -le 5 ] || QCRB1_BAD=\"$QCRB1_BAD sweep:$QCRB1_SW_HIT-files-name-one-in-all\"\n"
        "    [ \"$QCRB1_SW_MISS\" -le 5 ] || QCRB1_BAD=\"$QCRB1_BAD sweep:$QCRB1_SW_MISS-files-missing-in-all\" ;;\n"
        f"  *) QCRB1_BAD=\"$QCRB1_BAD sweep(the-control:the-pin-lib/WaSendBudget.php-reads-${{qr1_ctl:-?}}-not-{ctl})\" ;;\n"
        "esac\n"
        f"if [ -z \"$QCRB1_BAD\" ]; then ok \"QC-RB1 nothing in $BASELINE_VERSION reaches what $EXPECTED_VERSION adds and leaves: none of the $QCRB1_N installed files $EXPECTED_VERSION changes that name one of its {len(kept)} words in it (its classes, jobs, screens, tools and 089–091's tables) names one now, and none of the $QCRB1_SW_N files $BASELINE_COMMIT tracks, as installed, names one — each read as text beside its control, the pin's files from Git, which name them exactly as pinned; $ADDED_LEFT of the $N_ADDED files $EXPECTED_VERSION adds are on disk, reached by nothing (QF-RB2)\"\n"
        "else bad \"QC-RB1 $BASELINE_VERSION's installed code names what $EXPECTED_VERSION leaves, or its readers could not see the release:$QCRB1_BAD — the rollback leaves the files $EXPECTED_VERSION adds and 089–091's tables in place, so nothing installed may name them. Send the log file\"; fi")
    doc.lines_between("# QC-RB1 — 5.18.103 (docs/72 §G.10, §G.13): nothing in 5.18.102 reaches what 5.18.103 adds and leaves: no installed file",
                      "else bad \"QC-RB1 $BASELINE_VERSION's installed code names the uCRM catalogue $EXPECTED_VERSION leaves,",
                      QCRB1, why="QC-RB1 re-pointed to 5.18.104")
    doc.mustnot("QC_RB1_MARKS=\"$(cat <<'MARKS'\nSAFETY.md|UcrmCatalogue|1", why="5.18.103's marks gone")
    doc.must("qc_rb1_n() { grep -cF \"${QC_RB1_E[@]}\"", 1)
    doc.must(f"  {ctl})\n    while IFS= read -r -d '' qr1_rel; do\n", 1)
    # the uCRM catalogue's table, after a 5.18.104 rollback: 5.18.103's own, read by it again — no longer "read by nothing"
    doc.sub("  records:*) note \"QC-RB1 the uCRM catalogue's table stays on the book, as the rollback leaves it: $(subkv \"$(kv \"$RBQ_S\" qcrb1t)\" records) record(s) — read by nothing under $BASELINE_VERSION; a later deploy of $EXPECTED_VERSION finds the switch, the installation product, the products kept off and the standard quotations as they were\" ;;\n",
            "  records:*) note \"QC-RB1 the uCRM catalogue's table stays on the book: $(subkv \"$(kv \"$RBQ_S\" qcrb1t)\" records) record(s) — $BASELINE_VERSION's own screen, read by it again after this rollback, as $EXPECTED_VERSION left it\" ;;\n",
            1, why="the catalogue's table note: 5.18.103's own after this rollback")
    doc.sub("# QC-RB1's table and QC-RB2 — 5.18.103 (docs/72 §G.10, §G.13), with $BASELINE_VERSION in place: the uCRM catalogue's\n"
            "# table, which the rollback leaves as written (RBQ_S's qcrb1t: absent, or its records), and the web-direct applications\n",
            "# QC-RB1's table and QC-RB2 — 5.18.103 (docs/72 §G.10, §G.13), with $BASELINE_VERSION in place: the uCRM catalogue's\n"
            "# table (RBQ_S's qcrb1t: absent, or its records) — 5.18.103's own screen's, read by it again after a 5.18.104\n"
            "# rollback, so counted and said, never \"read by nothing\" — and the web-direct applications\n",
            1, why="the catalogue's table comment")
