# Part R — the rollback (root docs/74 §H.5): to BASELINE_COMMIT=994d660 through do_rollback, keeping every switch.
#   R1 5.18.104's one rollback sequence (qf_byhand, qf_rb1, qf_rb3) goes; rb_pre105 (LK-RB0, the step-12 notice, before
#      ROLLBACK is typed) and rb105 (RB105, LK-RB1, LM-RB1, after the put-back) take its place (rb105.sh.txt).
#   R2 Part V's VN-RB1–VN-RB4 (vn_rb1) and VN-RB6's instruction to rotate the OpenAI key go — the voice steps keep running;
#      VN-RB5 stays, reworded. Part O's OF-RB1 (of_rb1) goes; OF-RB2 reworded. Part P's ac_rb_pre and AC-RB1 (ac_rb1) go;
#      AC-RB2 reworded: "they continue under 994d660 as their switches say".
#   R3 the call sites: --rollback (rb_pre105 before ROLLBACK is typed; no tool), V1's automatic rollback (no tool; rb105
#      once verified, which compares with the deploy's LK-B1), the RB section (rb105).
#   R4 QC-RB1 re-pointed: the files 5.18.105 adds (tests only) stay on disk, and no file 994d660 tracks names one — its
#      words, the pin's control and its count read off Git here.
import os, re
from d105lib import git

HERE = os.path.dirname(os.path.abspath(__file__))


def snippet(name):
    return open(os.path.join(HERE, name), encoding="utf-8").read()


def apply(doc, ctx):
    repo, plugin, base, pin, added = ctx["repo"], ctx["plugin"], ctx["base_full"], ctx["pin_full"], ctx["added"]

    # ── R1 — the sequence goes; rb_pre105 and rb105 ──
    doc.sub("# entry (qf_stage, qf_after, qf_on, qf_rb1, qf_rb3) before its readings on copies. Those readings run inside $( … )",
            "# entry (qf_stage, qf_after, qf_on, rb_pre105, rb105 and LK's) before its readings on copies. Those readings run inside $( … )",
            why="cih_main comment")
    doc.between("# ── 5.18.104: the one rollback sequence (QF-RB1–QF-RB3; root docs/73 §H.2.16, rulings R7, R11, R12, R14, R17) ──────────────\n",
                "# ── 5.18.104: Part V's checks — the voice notes (VN) and the acknowledgement (VA)",
                snippet("rb105.sh.txt") + "# ── 5.18.104: Part V's checks — the voice notes (VN) and the acknowledgement (VA)",
                keep_ends=False, why="rb105")
    for f in ("qf_byhand() {", "qf_rb1() {", "qf_rb3() {"):
        doc.mustnot(f)
    doc.must("rb_pre105() {\n", 1)
    doc.must("rb105() {\n", 1)
    # the step-12 notice (review D2, D3): the masked log lines counted as LM_LN_N (LM-RB1's count), never LM_N (every
    # entry, the helper, the report's fix and the loader among them); the operator is told to answer anything but
    # ROLLBACK, turn offers-live off alone, then run the rollback again — never to change a switch while this script waits;
    # the command printed alone on its own line, a blank line before and after it, and never run
    pre = doc.t[doc.t.index("rb_pre105() {\n"):doc.t.index("\n}\n", doc.t.index("rb_pre105() {\n"))]
    notice = [l for l in pre.split("\n") if 'note "STEP 12 (OFFERS-LIVE) IS ON.' in l]
    if len(notice) != 1:
        raise SystemExit(f"PART R: the step-12 notice found {len(notice)} times in rb_pre105")
    for s in ("THE $LM_LN_N LOG LINES $EXPECTED_VERSION MASKED", "CHANGE NO SWITCH WHILE THIS SCRIPT WAITS",
              "ANSWER ANYTHING BUT ROLLBACK BELOW — NOTHING HAS BEEN CHANGED", "THEN TURN OFFERS-LIVE OFF WITH ITS OWN COMMAND, ALONE",
              "THIS SCRIPT NEVER RUNS IT", "AND THEN RUN THE ROLLBACK AGAIN"):
        if s not in notice[0]:
            raise SystemExit(f"PART R: the step-12 notice does not say {s!r}")
    for s in ("$LM_N ", "BEFORE YOU TYPE ROLLBACK", "RUN BEFORE"):
        if s in notice[0]:
            raise SystemExit(f"PART R: the step-12 notice still says {s!r}")
    alone = '    echo\n    echo "      cd $REPO && bash $QF_WRAPPER off offers-live"\n    echo ;;\n'
    if pre.count(alone) != 1 or doc.t.count("off offers-live\"") != 1:
        raise SystemExit("PART R: the step-12 alternative is not printed once, alone on its own line, blank lines around it")
    if "ROLLBACK_CMD" in pre or "PUTBACK" in pre or "--rollback" in pre:
        raise SystemExit("PART R: rb_pre105 prints a rollback command beside the step-12 alternative")
    # (LM's functions, LM_LN_N among them, are inserted by partL, later; partZ asserts the notice and LM-RB1 share it)

    # ── R2 — Part V: vn_rb1 goes; vn_rb_after is VN-RB5 alone ──
    doc.between("# vn_rb1 — VN-RB1–VN-RB4, Part V's reads of QF-RB1's result, after the installed tool's off all --rollback exited 0 and\n",
                "# ── 5.18.104: Part O's checks — the offers (OF;",
                "# vn_rb_after — VN-RB5 (a note), after the put-back (RB): 090's three tables and its _schema_checks row stay, and continue\n"
                "# under 994d660 as Part V's switches say. Read on a copy. 5.18.105 retires VN-RB1–VN-RB4 (they read 5.18.104's off-all,\n"
                "# which no longer runs) and VN-RB6's instruction to rotate the OpenAI key: the voice steps keep running (root docs/74\n"
                "# §H.5.2).\n"
                "vn_rb_after() {\n"
                "  local d o=\"\"\n"
                "  d=\"$(vn_book_copy rb5)\" || d=\"\"\n"
                "  if [ -n \"$d\" ]; then o=\"$(vn_php rb5 \"$d/plugin.sqlite3\" \"$d/first.txt\")\"; cih_drop \"$d\"; fi\n"
                "  case \"$o\" in\n"
                "    \"tables=\"*) [ \"$(qf_kv \"$o\" tables)|$(qf_kv \"$o\" sc)\" = \"3/3|present\" ] \\\n"
                "        && note \"VN-RB5 090's three tables and its _schema_checks row stay, and continue under $BASELINE_VERSION as Part V's switches say ($(qf_kv \"$o\" calls) wa_voice_calls row(s))\" \\\n"
                "        || bad \"VN-RB5 090's tables or its _schema_checks row are not as the rollback must leave them (${o%% calls=*}) — a rollback removes nothing\" ;;\n"
                "    *) note \"VN-RB5 could not be read on a copy (${o:-no copy}) — 090's tables stay as they were, under $BASELINE_VERSION\" ;;\n"
                "  esac\n"
                "}\n\n"
                "# ── 5.18.104: Part O's checks — the offers (OF;", keep_ends=False, why="VN-RB1-4, VN-RB6")
    doc.mustnot("vn_rb1() {")
    doc.mustnot("INSTRUCTION TO THE OWNER (VN-RB6)")
    doc.sub('QF_G2_TRIAL=""; QF_G2_NEG=""; QF_RB1_OUT=""; QF_RB3_VC=""\n', 'QF_G2_TRIAL=""; QF_G2_NEG=""\n', why="VN vars")

    # ── R2 — Part O: of_rb1 goes; OF-RB2 reworded ──
    doc.between("# of_rb1 — OF-RB1, Part O's read of QF-RB1's result, after the installed tool's off all --rollback exited 0 and before the\n",
                "# ── 5.18.104: Part P's checks — the agent's visible copy",
                "# of_rb_after — OF-RB2 (a note), after the put-back (RB): the four offer tables and 091's row stay, and continue under\n"
                "# 994d660 as Part O's switches say — an offer running goes on, and a paused one resumes only by an admin's Resume.\n"
                "# Read on a copy of the book. 5.18.105 retires OF-RB1, which read 5.18.104's off-all (root docs/74 §H.5.2).\n"
                "of_rb_after() {\n"
                "  local d o=\"\"\n"
                "  d=\"$(vn_book_copy ofrb2)\" || d=\"\"\n"
                "  if [ -n \"$d\" ]; then o=\"$(of_php rb2 \"$d/plugin.sqlite3\")\"; cih_drop \"$d\"; fi\n"
                "  case \"$o\" in\n"
                "    \"tables=4/4 \"*) note \"OF-RB2 the four offer tables and 091's _schema_checks row ($(qf_kv \"$o\" sc)) stay, and continue under $BASELINE_VERSION as Part O's switches say: offers by state $(qf_kv \"$o\" offers) — $(qf_kv \"$o\" running) running, $(qf_kv \"$o\" paused) paused (a paused one resumes only by an admin's Resume)\" ;;\n"
                "    \"tables=0/4 \"*) note \"OF-RB2 the book holds no offer tables (091 never applied): nothing of Part O stays\" ;;\n"
                "    \"tables=\"*) bad \"OF-RB2 the offer tables are not as the rollback must leave them (${o%% offers=*}) — a rollback removes nothing\" ;;\n"
                "    *) note \"OF-RB2 could not be read on a copy of the book (${o:-no copy}) — the four offer tables stay as they were, under $BASELINE_VERSION as its switches say\" ;;\n"
                "  esac\n"
                "}\n\n"
                "# ── 5.18.104: Part P's checks — the agent's visible copy", keep_ends=False, why="OF-RB1, OF-RB2")
    doc.mustnot("of_rb1() {")
    doc.line('QF_RB1_START=""   # QF-RB1\'s start (UTC seconds)', '# (QF_RB1_START went with QF-RB1: 5.18.105 runs no switch tool in a rollback — root docs/74 §H.5.2)')
    doc.mustnot("QF_RB1_START=")

    # ── R2 — Part P: ac_rb_pre and ac_rb1 go; AC-RB2 reworded ──
    doc.between("# ac_rb_pre — in QF-RB1, before the installed tool's off all --rollback: quote_agent_cc_admins as it reads now, through the\n",
                "# ── 5.18.100: a technician may own a number (TN;",
                "# ac_rb_after — AC-RB2 (a note), after the put-back (RB): P7's two collections stay, and continue under 994d660 as its\n"
                "# switch (agent-cc) says. 5.18.105 retires ac_rb_pre and AC-RB1, which read 5.18.104's off-all (root docs/74 §H.5.2).\n"
                "ac_rb_after() {\n"
                "  local d o=\"\"\n"
                "  d=\"$(vn_book_copy acrb2)\" || d=\"\"\n"
                "  if [ -n \"$d\" ]; then o=\"$(ac_php rb2 \"$d/plugin.sqlite3\")\"; cih_drop \"$d\"; fi\n"
                "  case \"$o\" in\n"
                "    makers=*) note \"AC-RB2 P7's two collections stay — quote_agent_makers $(qf_kv \"$o\" makers), quote_agent_copies $(qf_kv \"$o\" copies) — and continue under $BASELINE_VERSION as its switch (agent-cc) says\" ;;\n"
                "    *) note \"AC-RB2 could not be read on a copy of the book (${o:-no copy}) — P7's collections stay as they were, under $BASELINE_VERSION as its switch says\" ;;\n"
                "  esac\n"
                "}\n\n"
                "# ── 5.18.100: a technician may own a number (TN;", keep_ends=False, why="AC-RB1, AC-RB2")
    doc.line('AC_RB_ADMINS=""', '# (AC_RB_ADMINS went with AC-RB1: 5.18.105 runs no switch tool in a rollback — root docs/74 §H.5.2)', why="AC_RB_ADMINS")
    for f in ("ac_rb_pre() {", "ac_rb1() {", "  ac_rb_pre   #", "AC_RB_ADMINS="):
        doc.mustnot(f)

    # ── R3 — the call sites ──
    doc.sub("    printf '  Type ROLLBACK to put %s (%s) back over live %s, anything else to stop: ' \"$BASELINE_VERSION\" \"$BASELINE_COMMIT\" \"$LIVE_BEFORE\"\n",
            "    rb_pre105   # 5.18.105 (root docs/74 §H.5.3): LK-RB0 and the step-12 notice, before ROLLBACK is typed — never a refusal\n"
            "    printf '  Type ROLLBACK to put %s (%s) back over live %s — every switch of the sales follow-up kept as it is — anything else to stop: ' \"$BASELINE_VERSION\" \"$BASELINE_COMMIT\" \"$LIVE_BEFORE\"\n",
            why="rb_pre105 call")
    # --rollback with nothing to roll back (994d660 already served as the run began): rb105 is told so and says LK-RB1 is
    # not judged — no ROLLBACK typed, no LK-RB0, no put-back to compare across (root docs/74 §H.5.3, §H.5.4; review D1)
    doc.sub("  else DEPLOY_STARTED=\"$(date -u -d '-10 minutes' +%Y-%m-%dT%H:%M:%SZ 2>/dev/null || date -u +%Y-%m-%dT%H:%M:%SZ)\"; fi\n",
            "  else RB_NOTHING=1; DEPLOY_STARTED=\"$(date -u -d '-10 minutes' +%Y-%m-%dT%H:%M:%SZ 2>/dev/null || date -u +%Y-%m-%dT%H:%M:%SZ)\"; fi   # nothing to roll back: rb105 judges no LK-RB1 (root docs/74 §H.5.3)\n",
            why="RB_NOTHING")
    doc.must("RB_NOTHING=1;", 1)
    doc.must('RB_NOTHING=""\n', 1)
    doc.sub("    # 5.18.104 (root docs/73 §H.2.16): QF-RB1 first — every 5.18.104 switch off in the one sequence, under the installed\n"
            "    # code, its hooks and end states asserted; it stops here, before the put-back, unless every part reads clean.\n"
            "    qf_rb1\n",
            "    # 5.18.105 (root docs/74 §H.5.2): no switch tool and no by-hand put-back of rows or keys — do_rollback alone.\n",
            why="--rollback call site")
    doc.lines_between("      # 5.18.103 (docs/72 §G.10), carried; 5.18.104 (root docs/73 §H.2.16): the deploy's own automatic rollback — the",
                      "      # the by-hand put-back, alone on its own line.",
        "      # 5.18.103 (docs/72 §G.10), carried; 5.18.105 (root docs/74 §H.5): the deploy's own automatic rollback — the public\n"
        "      # address must never redirect, and putting $BASELINE_VERSION back is what ends it — do_rollback alone, no switch tool,\n"
        "      # every switch kept; then QC-RB2 counted after it on a consistent copy of the book, read-only (qcrb_count; counts in\n"
        "      # the log, ids on the sheet), a record only. It always proceeds, whatever step is on. do_rollback's own answer decides\n"
        "      # the words: verified, RB105 reads the put-back (against the deploy's LK-B1) and no command is printed; not verified —\n"
        "      # the checkout, deploy-hybrid.sh, or a record that did not turn — $EXPECTED_VERSION may still serve, and the stop says\n"
        "      # the rollback did not complete and prints the by-hand put-back of the code, alone on its own line.",
        why="V1 comment")
    doc.sub('echo "  ROLLING BACK — the public address must never redirect"; qf_rb1; if do_rollback; then',
            'echo "  ROLLING BACK — the public address must never redirect (every switch kept)"; if do_rollback; then', why="V1 no tool")
    doc.sub('        if [ "$QCV1_RB" = "ok" ]; then qf_rb3   # QF-RB3 — the put-back read, as RB reads it after a --rollback (DS5, S3)\n',
            '        if [ "$QCV1_RB" = "ok" ]; then rb105   # RB105 — the put-back read, as RB reads it after a --rollback (root docs/74 §H.5.4)\n',
            why="V1 rb105")
    m = re.search(r'\n          stop "rolled back \(serves \$\(live_commit\)\); QF-RB3 above reads the put-back; \$QCV1_SAY; [^\n]*\n', doc.t)
    if not m:
        raise SystemExit("PART R: V1's verified stop line not found")
    doc.sub(m.group(0),
            '\n          stop "rolled back (serves $(live_commit)); RB105 above reads the put-back — every switch of the sales follow-up kept as it was, the lines $EXPECTED_VERSION masked writing numbers again (LM-RB1); $QCV1_SAY; on Uganda the uCRM catalogue is left exactly as it was (root docs/74 §H.5); send the log file"; fi\n',
            why="V1 stop")
    doc.mustnot("qf_rb1")
    doc.sub("# QF-RB3 — 5.18.104 (root docs/73 §H.2.16): the files 5.18.104 changed are 5.18.103's again, master registers none of\n"
            "# the four jobs, no 5.18.104 key set, 0 open quote follow-ups; VN-RB6's instruction when OpenAI was ever called. Read-only.\n"
            "qf_rb3\n",
            "# RB105 — 5.18.105 (root docs/74 §H.5.4): master as 5.18.104 left it, the ladder's keys and the tool's show as before the\n"
            "# rollback (LK-RB1), no table's rows fell, the open quote follow-ups and running offers counted, the old lines back\n"
            "# (LM-RB1). Read-only. When this run rolled nothing back (RB_NOTHING), LK-RB1 is a note: not judged, nothing to compare.\n"
            "rb105\n", why="RB call")
    doc.line('note "RB the code is 5.18.103\'s again where 5.18.104 changed it.',
             'note "RB the code is $BASELINE_VERSION\'s again where $EXPECTED_VERSION changed it, and nothing else moved: the rollback ran no switch tool and no off-hook and applied or removed no migration (root docs/74 §H.5.2) — every step of the sales follow-up keeps running as its switch says, and the log lines $EXPECTED_VERSION masked write numbers again (LM-RB1). $ADDED_LEFT of the $N_ADDED files $EXPECTED_VERSION adds (its tests) are on disk (deploy-hybrid.sh never deletes; counted above), reached by nothing of $BASELINE_VERSION (QC-RB1). ECO Bank, the uCRM catalogue, the channel registry and every number\'s owner stay as they were (RB above). A14 of any later deploy needs the quarantine\'s --check begun after this rollback\'s copy (RB_COPY_AT), and a later deploy of $EXPECTED_VERSION goes with --redeploy"',
             why="RB note")
    doc.mustnot("qf_rb3")

    # ── R4 — QC-RB1 re-pointed to 5.18.105's added files (tests only) ──
    words = set()
    for f in added:
        parts = f.split("/")
        if parts[0] != "tests":
            raise SystemExit(f"PART R: an added file outside tests/: {f}")
        if len(parts) > 2 and parts[1] != "fixtures":
            words.add(parts[1])
        else:
            words.add(re.sub(r"\.php$", "", parts[-1]))
    words = sorted(w for w in words if not any(o != w and o in w for o in words))
    if not words or any(not re.fullmatch(r"[A-Za-z0-9_]+", w) for w in words):
        raise SystemExit(f"PART R: QC-RB1's words are {words}")
    import subprocess
    args = ["git", "-C", repo, "grep", "-l", "-F"]
    for w in words:
        args += ["-e", w]
    r = subprocess.run(args + [base, "--", plugin], capture_output=True)
    if r.returncode not in (0, 1):
        raise SystemExit(f"PART R: git grep failed: {r.stderr.decode()[:200]}")
    hits = [h for h in r.stdout.decode().split("\n") if h]
    if hits:
        raise SystemExit(f"PART R: 994d660 already names a word of QC-RB1's: {hits[:5]}")
    ctl_file = "tests/test_log_mask.php"
    if ctl_file not in added:
        raise SystemExit("PART R: QC-RB1's control, tests/test_log_mask.php, is not one of 5.18.105's added files")
    ctl_text = git(repo, "show", f"{pin}:{plugin}/{ctl_file}").decode()
    ctl_n = sum(1 for ln in ctl_text.split("\n") if any(w in ln for w in words))
    if ctl_n < 1 or "log_mask/" not in ctl_text:
        raise SystemExit("PART R: QC-RB1's control does not name tests/log_mask/")
    ctx.update(qc_rb1_words=words, qc_rb1_ctl=ctl_n)
    new_block = (
        "# QC-RB1 — carried from 5.18.103, re-pointed to 5.18.105 (root docs/74 §H.5.4): nothing of 994d660 reaches what 5.18.105\n"
        "# adds and leaves. 5.18.105 adds tests only — tests/test_log_mask.php, tests/log_mask/ with its two fakes,\n"
        "# tests/fixtures/r105_revert.php — and they stay on disk (deploy-hybrid.sh never deletes; counted above). The words are\n"
        "# their names, read off Git by the derivation (derive105.py, partR), which also asserts that no file 994d660 tracks\n"
        "# names one. One reader for every file (qc_rb1_n, as text, from its input): every file 994d660 tracks under the\n"
        "# plugin, data/ aside, as installed, must name none — a tracked file missing on disk fails, so the sweep never passes\n"
        "# over a file it did not read — beside its control, the pin's tests/test_log_mask.php from Git, which names\n"
        f"# tests/log_mask/ on its pinned count of lines ({ctl_n}). Fixed words and counts only.\n"
        "QC_RB1_WORDS=\"$(cat <<'WORDS'\n" + "\n".join(words) + "\nWORDS\n)\"\n"
        "QC_RB1_E=(); while IFS= read -r qr1_w; do [ -n \"$qr1_w\" ] && QC_RB1_E+=(-e \"$qr1_w\"); done <<< \"$QC_RB1_WORDS\"\n"
        "qc_rb1_n() { grep -cF \"${QC_RB1_E[@]}\" 2>/dev/null || true; }   # qc_rb1_n <INPUT — the lines of INPUT naming any of the words, or nothing when INPUT could not be read: one reader, pin and installed\n"
        "QCRB1_BAD=\"\"; QCRB1_SW_N=0; QCRB1_SW_HIT=0; QCRB1_SW_MISS=0\n"
        f"[ \"${{#QC_RB1_E[@]}}\" = \"{2 * len(words)}\" ] || QCRB1_BAD=\"$QCRB1_BAD words:$(( ${{#QC_RB1_E[@]}} / 2 ))/{len(words)}\"\n"
        f"qr1_ctl=\"$(git -C \"$REPO\" show \"$EXPECTED_PLUGIN_COMMIT:$PLUGIN/{ctl_file}\" 2>/dev/null | qc_rb1_n)\"\n"
        "case \"$qr1_ctl\" in\n"
        f"  {ctl_n})\n"
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
        f"  *) QCRB1_BAD=\"$QCRB1_BAD sweep(the-control:the-pin-{ctl_file}-reads-${{qr1_ctl:-?}}-not-{ctl_n})\" ;;\n"
        "esac\n"
        "if [ -z \"$QCRB1_BAD\" ]; then ok \"QC-RB1 nothing of $BASELINE_VERSION reaches what $EXPECTED_VERSION adds and leaves on disk (its tests): none of the $QCRB1_SW_N files $BASELINE_COMMIT tracks, as installed, names one of its $(printf '%s\\n' \"$QC_RB1_WORDS\" | grep -c .) words ($(printf '%s' \"$QC_RB1_WORDS\" | paste -sd' ' -)), each read as text — beside the control, the pin's tests/test_log_mask.php, which names them on its pinned count of lines\"\n"
        "else bad \"QC-RB1 $BASELINE_VERSION's installed code names what $EXPECTED_VERSION leaves, or its readers could not see the release:$QCRB1_BAD — the rollback leaves the files $EXPECTED_VERSION adds in place, so nothing installed may name them. Send the log file\"; fi\n")
    doc.between("# QC-RB1 — carried from 5.18.103 and re-pointed to 5.18.104 (root docs/73 §H.2.16, QF-RB2; as 5.18.103 re-pointed\n",
                "# The quoting records, as they are: quote_refused* on applications,",
                new_block + "# The quoting records, as they are: quote_refused* on applications,", keep_ends=False, why="QC-RB1")
    doc.mustnot("QC_RB1_MARKS")
