# Part LK (5.18.104's part F, re-pointed: root docs/74 §H.4.3, §H.4.4) — the ladder rule, and the QF checks that assumed
# every switch off made live-aware. 5.18.105's deploy and its --after-only never run the switch tool: it keeps every key of
# the ladder exactly as found (LK), and judges the steps that are on rather than requiring them off.
#   LK-G1   stage A (and --check): the ladder's keys in the four places, read-only; NO-GO only for a place unread or an
#           allKeys() key held by a config.json; a note otherwise (in capitals for offers_live_enabled). Replaces QF-G4.
#   LK-B1   inside qf_keep_and_baseline (replacing qf_clear_and_baseline, which ran the tool): the same reading, equal key
#           by key and place by place, or NO-GO and nothing copied; then the 6 s pause and the baselines, always.
#   LK-CA1  the reading after the copy equal to LK-B1's (FAIL otherwise); LK-CA2 rows that never fall, _schema_checks,
#           master_schedule.json's four entries (QF-CA2's and QF-CA3's dark judgements move here); LK-ON each step on,
#           the installed tool's --check against the pin's at stage A; LK-AO in --after-only, against LK_MAP under the
#           kept key (LK_KEY_FILE, mode 0600, the QL_KEY_FILE precedent; the per-key map on the 0600 sheet).
#   QF-G1 accepts only "5.18.104's tables as it left them" (QF_G1=live); 088 last and a part-applied book are NO-GO.
#   QF-G2 (and the 071 note) keeps only the "none pending" form; QF-G3's counts are re-read from the pin here and asserted
#   equal to 994d660's; QF-CA1 reworded; QF-CA3's cron_status half reads each job OFF (switch) exactly when its flag is
#   unset on the copy; QF-CA5 compares the installed tool's show with stage A's, line for line.
#   --clear-switches is refused at parse; V3g has no excuse branch.
# QF_PHP gains the modes lk, lkrows, flags and rbn (lk_php.txt, lk_php_rows.txt); g4 and rb3 go with QF-G4 and QF-RB3.
import os, re
from d105lib import git

HERE = os.path.dirname(os.path.abspath(__file__))


def snippet(name):
    return open(os.path.join(HERE, name), encoding="utf-8").read()


def apply(doc, ctx):
    repo, plugin, base, pin = ctx["repo"], ctx["plugin"], ctx["base_full"], ctx["pin_full"]
    show = lambda c, p: git(repo, "show", f"{c}:{plugin}/{p}").decode()

    # ── QF-G3's counts, re-read from the pin and asserted equal to 994d660's (root docs/74 §H.4.4) ──
    m = re.search(r'\nQF_HOOKS="([^"]*)"', doc.t)
    if not m:
        raise SystemExit("PART LK: QF_HOOKS not found")
    fn_re = re.compile(r"^\s*((public|private|protected|static|final|abstract)\s+)*function\s+[A-Za-z_][A-Za-z0-9_]*\s*\(")
    def count(text, nd, meth):
        if meth == "-":
            return sum(1 for ln in text.split("\n") if nd in ln)   # grep -cF: the lines that hold it
        cur, n = "", 0
        for ln in text.split("\n"):
            if fn_re.match(ln):
                cur = re.sub(r"\s*\(.*", "", re.sub(r"^.*function\s+", "", ln))
            if nd in ln and cur == meth:
                n += 1
        return n
    for item in m.group(1).split():
        f, nd, want, meth = item.split("|")
        for c in (base, pin):
            got = count(show(c, f), nd, meth)
            if str(got) != want:
                raise SystemExit(f"PART LK: QF-G3's {f} {nd} {meth} reads {got} at {c[:7]}, QF_HOOKS says {want}")
    ev = re.search(r'\nQF_EVENTS="([^"]*)"', doc.t).group(1).split()
    for c in (base, pin):
        kinds = re.search(r"NOTIFY_KINDS\s*=\s*\[(.*?)\];", show(c, "lib/WaSendBudget.php"), re.S)
        if not kinds:
            raise SystemExit(f"PART LK: WaSendBudget::NOTIFY_KINDS not found at {c[:7]}")
        evs = re.findall(r"'([a-z_0-9]+)'\s*=>", kinds.group(1))
        if sorted(set(evs)) != sorted(ev):
            raise SystemExit(f"PART LK: NOTIFY_KINDS' events at {c[:7]} are not QF_EVENTS'")
    hk = re.search(r'\nQF_HK7_MIN="(\d+)"', doc.t).group(1)
    for c in (base, pin):
        if show(c, "tests/test_wa_send_budget.php") != show(base, "tests/test_wa_send_budget.php"):
            raise SystemExit(f"PART LK: tests/test_wa_send_budget.php (HK07's control) differs at {c[:7]}")
    ctx["qf_g3"] = (len(m.group(1).split()), len(ev), hk)

    # ── QF_PHP: the header, the vault rule, lk for g4, lkrows / flags / rbn for rb3 ──
    doc.sub('// container. Every mode but "g4", "base" and "rb3" reads only a COPY in a folder of this run\'s; those three open the live\n'
            "// book with SQLITE_OPEN_READONLY and read its files as text — counts and key names only, never a value. Output: one\n",
            '// container. Every mode but "lk", "base", "rbn" and (at the baseline) "lkrows" reads only a COPY in a folder of this\n'
            "// run's; those open the live book with SQLITE_OPEN_READONLY and read its files as text — counts, key names and keyed\n"
            "// hashes only, never a value (5.18.105, root docs/74 §H.4.3: \"g4\" and \"rb3\" went with QF-G4 and QF-RB3). Output: one\n")
    doc.sub("if (in_array($mode, ['g1', 'ca1', 'ca2', 'hk7'], true)) putenv(",
            "if (in_array($mode, ['g1', 'ca1', 'ca2', 'hk7', 'flags'], true)) putenv(")
    doc.between("    case 'g4':     // g4 ROOT LIVEROOT PDD — QF-G4's read of the live files and the store row (read-only)\n",
                "    case 'conf':", snippet("lk_php.txt") + "    case 'conf':", keep_ends=False, why="QF_PHP lk")
    doc.between("    case 'rb3':    // rb3 ROOT LIVEROOT PDD — QF-RB3's live reads after the put-back (read-only): 5.18.104 keys still set, open\n",
                "    default:\n        echo '?mode'; exit(2);", snippet("lk_php_rows.txt") + "    default:\n        echo '?mode'; exit(2);",
                keep_ends=False, why="QF_PHP lkrows, flags, rbn")
    doc.mustnot("    case 'g4':")
    doc.mustnot("    case 'rb3':")
    doc.must("    case 'lk':     // lk ROOT LIVEROOT PDD KEYFILE", 1)
    doc.must("    case 'lkrows': // lkrows DB MAX", 1)

    # ── the QF variables, and LK's functions beside them ──
    doc.sub('QF_G1=""; QF_G1_LINE=""; QF_G4_LINE=""; QF_G4_SET=0; QF_G4_NAMES=""; QF_CLEAR=""; QF_CLEARED=""; QF_BASE=""\n',
            'QF_G1=""; QF_G1_LINE=""; QF_BASE=""   # 5.18.105: QF-G4 (and with it QF_G4_*, QF_CLEAR, QF_CLEARED) retired — LK-G1 (root docs/74 §H.4.4)\n')
    doc.insert_after('qf_kv() { kv "$1" "$2"; }\n', snippet("lk_funcs.sh.txt"), why="LK functions")

    # ── qf_stage: QF-G1 live only; LK-G1 and stage A's show and checks on QF-G1's copy; QF-G2 none pending; QF-G4 gone ──
    doc.lines_between("# qf_stage — QF-G1–QF-G4 and QF-N1–QF-N7, stage A, before anything changes (a deploy and --check): on copies, through",
                      "# QF_G1 (first|redeploy), QF_G4_SET and QF_G4_NAMES (and QF_CLEAR when --clear-switches will clear them after DEPLOY).",
        "# qf_stage — QF-G1–QF-G3, LK-G1 and QF-N1–QF-N7, stage A, before anything changes (a deploy and --check): on copies,\n"
        "# through the PIN's code from Git (P3B_PIN: its lib/, tools/ and migrations/). A refusal stops here: nothing has changed.\n"
        "# Sets QF_G1 (live: 5.18.104's tables as it left them — the one form 5.18.105 accepts, root docs/74 §H.4.4) and LK-G1's\n"
        "# readings (LK_G1, LK_ON, LK_SKIP, LK_SET_NAMES), and takes QF-CA5's and LK-ON's stage-A readings on QF-G1's copy.",
        why="qf_stage comment")
    doc.lines_between('    "last=${MIG88#migrations/} mig=000 sc=000 tables=000 kind=0 control=1") QF_G1=first',
                      "  esac",
        '    "last="*" mig=111 sc=111 tables=111 kind=1 control=1") QF_G1=live\n'
        '      ok "QF-G1 the copy carries 089, 090 and 091, each in the migration ledger with its _schema_checks row at its count, and quote_tracker, wa_auto_sends, wa_seen_marks and followups.kind ($g1) — $BASELINE_VERSION\'s tables as it left them; wa_messages read (the positive control)" ;;\n'
        '    "last=${MIG88#migrations/} mig=000 sc=000 tables=000 kind=0 control=1")\n'
        '      cih_drop "$sub"; stop "NO-GO (QF-G1): the book\'s last migration is ${MIG88#migrations/}, with none of 089, 090 and 091 ($g1) — $BASELINE_VERSION is not live: deploy $BASELINE_VERSION first (scripts/deploy-$BASELINE_VERSION.sh). Nothing was changed. Send the log file" ;;\n'
        '    *) cih_drop "$sub"; stop "NO-GO (QF-G1): the book does not carry 089, 090 and 091 as $BASELINE_VERSION left them ($g1; mig, sc and tables per migration and per table): a part-applied book — a migration\'s _schema_checks row is the verdict, and one is missing. Nothing was changed. Send the log file" ;;\n'
        "  esac\n"
        "  lk_g1                # LK-G1 — the ladder's keys as found (root docs/74 §H.4.3): a refusal stops here, nothing changed\n"
        '  lk_stage_a "$sub"    # QF-CA5\'s and LK-ON\'s stage-A readings, through the pin\'s tool on this copy (root docs/74 §H.4.4)',
        why="QF-G1 live")
    doc.mustnot('QF_G1=first')
    doc.mustnot('QF_G1=redeploy')
    # QF-G2: the "none pending" form only
    doc.sub('  echo "  migration trial $o" | cut -c1-600\n',
            '  echo "  migration trial $o" | cut -c1-600\n'
            "  # 5.18.105 (root docs/74 §H.4.6): the trial's line names 071_dpo_payments.sql:checksum_mismatch, as 5.18.104's run's did —\n"
            "  # the book's history: 071 was edited on 13 Sep (df5a100, cd65458) after the server had applied it. A note, never judged;\n"
            "  # left for a later release.\n"
            '  case "$o" in *"071_dpo_payments.sql:checksum_mismatch"*) note "QF-G2 the trial reports 071_dpo_payments.sql:checksum_mismatch — the book\'s history (071 edited on 13 Sep after the server applied it; root docs/07, 5.18.104\'s entry), as every boot reports it: not judged, left for a later release" ;; esac\n',
            why="071 note")
    doc.sub('    if [ "$QF_G1" = "first" ]; then\n'
            '      [ "$st" = "ok" ] || bad="$bad ${v%%:*}:applied-${st:-not-at-all}"\n'
            '      case "$o" in *"log="*"${v%%:*}=OK"*) ;; *) bad="$bad ${v%%:*}:log-not-OK" ;; esac\n'
            '    else\n'
            '      [ -z "$st" ] || bad="$bad ${v%%:*}:on-a-redeploy-the-trial-found-it-$st"\n'
            '    fi\n',
            '    [ -z "$st" ] || bad="$bad ${v%%:*}:on-a-live-$BASELINE_VERSION-book-the-trial-found-it-$st"   # 5.18.105: none pending (root docs/74 §H.4.4)\n',
            why="QF-G2 none pending")
    doc.sub('  QF_G2_LIVELOG=""\n  if [ "$QF_G1" = "redeploy" ]; then\n',
            '  QF_G2_LIVELOG=""\n  if [ "$QF_G1" = "live" ]; then\n', why="QF-G2 live log")
    doc.sub('    note "QF-G2 a redeploy: 089, 090 and 091 are already in the copy\'s ledger, so the trial applied none of them and wrote no log line',
            '    note "QF-G2 $BASELINE_VERSION is live: 089, 090 and 091 are already in the copy\'s ledger, so the trial applied none of them and wrote no log line',
            why="QF-G2 note")
    doc.sub('$( [ "$QF_G1" = "first" ] && echo "each applied, each migration.log line OK, never PARTIAL, and" || echo "none pending on this redeploy\'s copy (each already in its ledger, none with other bytes), and" )',
            'none pending on the copy of this live $BASELINE_VERSION book (each already in its ledger, none with other bytes), and',
            why="QF-G2 ok")
    # QF-G4: retired; LK-G1 above replaces it
    doc.between("  # QF-G4 — the one stage-A rule for every part's keys (ruling R7): the pin's allKeys(), unset in the live files and the\n",
                "  vn_stage   # VN-G1–VN-G6",
                "  # (QF-G4, 5.18.104's \"every key unset\", is retired: 5.18.105 keeps every switch as found — LK-G1, above, reads them.)\n"
                "  vn_stage   # VN-G1–VN-G6", keep_ends=False, why="QF-G4 retired")
    doc.mustnot("QF-G4 none of the pin's")
    # qf_clear_and_baseline: gone; qf_keep_and_baseline (LK's functions) runs no tool
    doc.between("# qf_clear_and_baseline — after DEPLOY is typed, before the copy (stage B): QF-G4's --clear-switches, then on a redeploy\n",
                "# qf_after INST_ROOT — QF-CA1–QF-CA8", "# qf_after INST_ROOT — QF-CA1–QF-CA8", keep_ends=False, why="qf_clear_and_baseline gone")
    doc.mustnot("qf_clear_and_baseline() {")
    doc.mustnot("off all --rollback 2>&1 </dev/null | qf_safe)\"; rc=${PIPESTATUS[0]}\n    qf_show_tool \"$o\" 60")

    # ── qf_after: QF-CA1 reworded; QF-CA2/CA3's dark judgement → LK-CA2; QF-CA3's listing live-aware; QF-CA5; LK-CA1, LK-ON ──
    doc.lines_between("# qf_after INST_ROOT — QF-CA1–QF-CA8, after the copy (stage R; --after-only too, where QF-CA2, QF-CA3's dark half and",
                      "# INSTALLED code (ICODE_R). Reports; never stops.",
        "# qf_after INST_ROOT — QF-CA1–QF-CA8 and LK-CA1, LK-CA2, LK-ON, after the copy (stage R; --after-only too, where LK-CA1,\n"
        "# LK-CA2 and LK-ON judge the deploy run only, and QF-CA5 is a note): on fresh copies, through the INSTALLED code\n"
        "# (ICODE_R). Reports; never stops. The steps that are on are judged, never required off (root docs/74 §H.4.4).",
        why="qf_after comment")
    doc.sub('if [ -z "$bad" ]; then ok "QF-CA1 on a fresh copy after the copy: 089, 090 and 091 each in the migration ledger once, each _schema_checks row present at its expected count — the verdict ($QF_SC_EXPECT); migration.log: ${v:-not read}"',
            'if [ -z "$bad" ]; then ok "QF-CA1 on a fresh copy after the copy: 089–091 still applied as $BASELINE_VERSION left them — each in the migration ledger once, each _schema_checks row present at its expected count ($QF_SC_EXPECT); migration.log: ${v:-not read}"',
            why="QF-CA1 ok")
    doc.sub('  else bad "QF-CA1 089–091 are not applied as reviewed on the book after the copy:$bad ($o; migration.log: ${v:-not read}) — the plugin\'s runner applies them at its first boot after the copy: a book no request has opened since says so here, and --after-only judges it again"; fi',
            '  else bad "QF-CA1 089–091 are not applied as $BASELINE_VERSION left them on the book after the copy:$bad ($o; migration.log: ${v:-not read}) — $EXPECTED_VERSION adds no migration and removes none"; fi',
            why="QF-CA1 bad")
    doc.lines_between("  # QF-CA2, QF-CA3 — what the dark build could have written (the deploy run only; ruling R18)",
                      "  else note \"QF-CA2 and QF-CA3's schedule rows judge the dark build in the deploy run; here, now:",
        "  # QF-CA2's and QF-CA3's dark-build judgement is LK-CA2's since 5.18.105 (root docs/74 §H.4.3): no table's rows fell,\n"
        "  # _schema_checks unchanged, the four jobs' entries kept — against the baseline taken at the copy's start; the claims and\n"
        "  # the four jobs' runs after it are notes (the steps that are on run and claim)\n"
        '  lk_ca2 "$sub"', why="QF-CA2 → LK-CA2")
    doc.mustnot('[ "$QF_G1" = "first" ]; then\n    case "$o" in "rows=0,0,0,0 "*)')
    doc.lines_between('  o="$(docker exec -u "$DB_OWNER" -e DN_DATA_DIR="$sub" -e DN_VAULT_FILE="$sub/vault.json" "$CONTAINER" php -d log_errors=0 "$sub/code/tools/cron_status.php" --all 2>&1 </dev/null | qf_safe)"',
                      '  else bad "QF-CA3 the four jobs are not as reviewed in the installed master or cron_status\'s listing ($v; OFF (switch): $n of 4)"; fi',
        '  # 5.18.105 (root docs/74 §H.4.4): each of the four jobs reads OFF (switch) exactly when its flag is unset on the copy —\n'
        "  # the flags read as text from master's own 'flag' fields, each read from the copy's store row as cron_status reads it\n"
        '  o="$(docker exec -u "$DB_OWNER" -e DN_DATA_DIR="$sub" -e DN_VAULT_FILE="$sub/vault.json" "$CONTAINER" php -d log_errors=0 "$sub/code/tools/cron_status.php" --all 2>&1 </dev/null | qf_safe)"\n'
        '  local fl fo jw jg jl c3="" c3n=0 pr\n'
        '  fl="$(sed -nE "s/^[[:space:]]*\'($(printf \'%s\' "$QF_JOBS" | tr \' \' \'|\'))\'[[:space:]]*=>.*\'flag\'[[:space:]]*=>[[:space:]]*\'([a-z0-9_]+)\'.*/\\1=\\2/p" "$DEST/$MASTER" 2>/dev/null)"\n'
        '  fo="$(qf_php flags "$sub/plugin.sqlite3" "$(printf \'%s\\n\' $fl | sed \'s/^[^=]*=//\' | paste -sd, -)")"\n'
        '  for pr in $fl; do\n'
        '    c3n=$((c3n + 1)); jw="on"; case ",$(qf_kv "$fo" flags)," in *",${pr#*=}:0,"*) jw="off" ;; *",${pr#*=}:1,"*) ;; *) jw="?" ;; esac\n'
        '    jl="$(grep -F -- "${pr%%=*}" <<<"$o")"; jg="on"; case "$jl" in *"OFF (switch)"*) jg="off" ;; esac\n'
        '    [ "$jw" = "$jg" ] || c3="$c3 ${pr%%=*}(flag ${pr#*=} $jw, listed $( [ "$jg" = "off" ] && echo "OFF (switch)" || echo "not OFF (switch)"))"\n'
        '  done\n'
        '  if [ "$v" = "order=ok" ] && [ "$c3n" = "4" ] && [ -z "$c3" ]; then ok "QF-CA3 the installed master registers the four jobs after notify_retry, in ruling R1\'s order ($QF_JOBS), each with \'uganda\' => true and a \'flag\'; cron_status on a copy lists each OFF (switch) exactly when its flag is unset on that copy ($(for pr in $fl; do printf \'%s \' "${pr%%=*}:$(case ",$(qf_kv "$fo" flags)," in *",${pr#*=}:1,"*) echo on ;; *) echo off ;; esac)"; done))"\n'
        '  else bad "QF-CA3 the four jobs are not as reviewed in the installed master, or cron_status\'s listing does not follow their flags on the copy ($v; $c3n flag(s) read from master;${c3:- the flags could not be read: ${fo:-not read}})"; fi',
        why="QF-CA3 live-aware")
    doc.lines_between('  v="$(printf \'%s\\n\' "$o" | grep -cE \'^  (1b agent-cc|[0-9]+ [a-z-]+)( \\(optional\\))?[^:]*: off\')"',
                      '  else note "QF-CA5 the installed tool\'s show on a copy: $v of 13 steps off; the ledger',
        '  # 5.18.105 (root docs/74 §H.4.4): the deploy run requires it equal, line for line — every <label>: on|off|skipped line\n'
        "  # and the ledger's — to stage A's show through the pin's tool on QF-G1's copy (LK_SHOW_A); the log carries each label\n"
        "  # and its state word, never a date\n"
        '  v="$(lk_show_lines "$o")"\n'
        '  if [ "$MODE" = "deploy" ]; then\n'
        '    if [ -z "$LK_SHOW_A" ]; then bad "QF-CA5 stage A\'s show through the pin\'s tool was not read — the installed tool\'s show ($(lk_show_say "$v")) has nothing to be compared with"\n'
        '    elif [ "$n" = "0" ] && [ "$v" = "$LK_SHOW_A" ]; then ok "QF-CA5 the installed tool\'s show on a fresh copy equals, line for line, stage A\'s through the pin\'s tool on QF-G1\'s copy — $(lk_show_say "$v"); the OpenAI key $(printf \'%s\\n\' "$o" | sed -n \'s/^openai key: //p\' | head -1) (never its value)"\n'
        '    else qf_show_tool "$(lk_show_say "$v" | tr \';\' \'\\n\')" 20; bad "QF-CA5 the installed tool\'s show (exit $n) does not equal stage A\'s, line for line: $(lk_show_changed "$LK_SHOW_A" "$v") — the steps are not as the deploy found them"; fi\n'
        '  else note "QF-CA5 the installed tool\'s show on a copy: $(lk_show_say "$v") (an --after-only run: the operator\'s steps)"; fi',
        why="QF-CA5 equal to stage A")
    doc.mustnot("ok \"QF-CA5 the installed tool's show on a copy: all 13 steps off")
    doc.sub('  vn_after "$inst"   # VN-M1, VN-M2, VN-CA1–VN-CA4, VN-K1, VN-K2 — after the copy, through the installed code\n',
            '  lk_ca1             # LK-CA1 — the ladder\'s keys after the copy equal LK-B1\'s (the deploy run; root docs/74 §H.4.3)\n'
            '  lk_on "$inst"      # LK-ON — each step on, the installed tool\'s --check against the pin\'s at stage A (the deploy run)\n'
            '  vn_after "$inst"   # VN-M1, VN-M2, VN-CA1–VN-CA4, VN-K1, VN-K2 — after the copy, through the installed code\n',
            why="LK-CA1, LK-ON calls")
    # qf_on: LK-AO first
    doc.sub('  ac_on_pre "$inst"   # 5.18.104 Part P: AC-ON1\'s copy of quote_mail.log, taken before the book (ac_on puts it where the tool reads it)\n',
            '  lk_ao   # LK-AO — 5.18.105 (root docs/74 §H.4.3): the ladder\'s keys under the kept key against the deploy\'s LK_MAP, a note\n'
            '  ac_on_pre "$inst"   # 5.18.104 Part P: AC-ON1\'s copy of quote_mail.log, taken before the book (ac_on puts it where the tool reads it)\n',
            why="LK-AO call")

    # ── stage B: qf_keep_and_baseline and the state file ──
    doc.sub("  # 5.18.104 (root docs/73 §H.2.15.1): QF-G4's --clear-switches, then QF-G4 read again (every key unset: the moment it\n"
            "  # \"returns\"), and on a redeploy QF-G1's pause of busy_timeout plus 1 s and the redeploy baseline — the copy's start, in\n"
            "  # both its forms — before the checkout, so that a stop here leaves the checkout where it was. Kept in the state file.\n"
            "  qf_clear_and_baseline\n"
            "  printf 'QF_G1=%s\\nQF_G4_SET=%s\\nQF_BASE=%s\\nQF_COPY_AT=%s\\n' \"${QF_G1:-}\" \"${QF_G4_SET:-}\" \"${QF_BASE:-}\" \"${QF_COPY_AT:-}\" >> \"$STATE\"\n",
            "  # 5.18.105 (root docs/74 §H.4.4): LK-B1 — the ladder's keys exactly as LK-G1 read them, or nothing is copied — then the\n"
            "  # pause of busy_timeout plus 1 s and the baselines at the copy's start, before the checkout, so that a stop here leaves\n"
            "  # the checkout where it was. No tool runs. Kept in the state file: the moment, LK-B1's map (hashes under the kept LK key,\n"
            "  # never a value), the steps on and skipped.\n"
            "  qf_keep_and_baseline\n"
            "  printf 'QF_G1=%s\\nQF_BASE=%s\\nQF_COPY_AT=%s\\nLK_MAP=%s\\nLK_ON=%s\\nLK_SKIP=%s\\nLK_AT=%s\\n' \"${QF_G1:-}\" \"${QF_BASE:-}\" \"${QF_COPY_AT:-}\" \"$(lk_map \"${LK_B1:-}\")\" \"${LK_ON:-}\" \"${LK_SKIP:-}\" \"${LK_AT:-}\" >> \"$STATE\"\n",
            why="stage B")
    # the LK key, kept with the backup (deploy only, when it was made by this run)
    doc.sub("    else bad \"the QL key could not be kept in $QL_KEY_FILE — a later --after-only could not compare stage A's verdicts item by item\"; fi\n  fi\n",
            "    else bad \"the QL key could not be kept in $QL_KEY_FILE — a later --after-only could not compare stage A's verdicts item by item\"; fi\n  fi\n"
            "  # 5.18.105 (root docs/74 §H.4.3; gap 5): the LK key — LK-G1's per-key hashes, LK-B1's and LK-CA1's comparison, and a later\n"
            "  # --after-only's LK-AO and a rollback's LK-RB0 — in a file of its own, LK_KEY_FILE, mode 0600, beside the state file and\n"
            "  # outside its glob, as the QL key is; made only where this deploy made the key (none was kept); never printed, never in\n"
            "  # the state file or the sheet.\n"
            "  if [ \"$MODE\" = \"deploy\" ] && [ -n \"$LK_KEY_NEW\" ]; then\n"
            "    if ( umask 077; printf 'LK_KEY=%s\\n' \"$LK_KEY\" > \"$LK_KEY_FILE\" ) && chmod 600 \"$LK_KEY_FILE\"; then\n"
            "      ok \"the LK key kept in $LK_KEY_FILE (mode 0600, never printed) — a later --after-only (LK-AO) and a rollback (LK-RB0) read the ladder's keys under it\"\n"
            "    else bad \"the LK key could not be kept in $LK_KEY_FILE — a later --after-only could not compare the ladder's keys with the deploy's\"; fi\n"
            "  elif [ \"$MODE\" = \"deploy\" ]; then echo \"  the LK key        $LK_KEY_FILE kept from an earlier deploy run of $EXPECTED_VERSION (mode 0600, never printed): this run's hashes are under it\"; fi\n",
            why="LK key file")

    # ── --clear-switches: refused at parse, before anything runs ──
    doc.line('CLEAR_SWITCHES=""   # 5.18.104 (root docs/73 §H.2.15.1, QF-G4)',
             '# (5.18.104\'s CLEAR_SWITCHES is retired: 5.18.105 refuses --clear-switches at parse — root docs/74 §H.4.4)')
    doc.line("    --clear-switches) CLEAR_SWITCHES=1 ;;",
             "    --clear-switches) echo \"--clear-switches: $EXPECTED_VERSION never clears a ladder key; turn a step off yourself with scripts/sales-followup.sh off <step>, its own command (root docs/74 §H.4.4). Nothing was run\" >&2; exit 64 ;;")
    doc.lines_between("# 5.18.104 (root docs/73 §H.2.15.1): --clear-switches is QF-G4's — the switch and record keys of every part found set",
                      "{ [ -z \"$CLEAR_SWITCHES\" ] || [ \"$MODE\" = \"deploy\" ]; }",
        "# 5.18.105 (root docs/74 §H.4.4): --clear-switches is refused above, at parse; nothing in this script clears a ladder key.",
        why="clear-switches mode rule")
    doc.sub(" ${ACCEPT_UNPRICED:+--accept-unpriced} ${CLEAR_SWITCHES:+--clear-switches} \"$1\"; do",
            " ${ACCEPT_UNPRICED:+--accept-unpriced} \"$1\"; do", why="with_flags")
    doc.sub('$( [ -n "$ACCEPT_UNPRICED" ] && echo ", --accept-unpriced")$( [ -n "$CLEAR_SWITCHES" ] && echo ", --clear-switches"))',
            '$( [ -n "$ACCEPT_UNPRICED" ] && echo ", --accept-unpriced"))', why="hdr")
    doc.lines_between("      # 5.18.104: so is --clear-switches, QF-G4's — no deploy, so nothing is cleared; an operator's own off is the installed tool's",
                      "      if [ -n \"$CLEAR_SWITCHES\" ]; then CLEAR_SWITCHES=\"\";",
        "      # (5.18.104's --clear-switches line: 5.18.105 refuses the flag at parse.)", why="serving branch")
    doc.sub("without it, it is not needed, and the command below is printed without it. 5.18.104 (DS5): --clear-switches too, where\n"
            "# QF-G4 found keys to clear (QF_CLEAR) — without it the deploy would refuse at QF-G4 — and said typed and not needed\n"
            "# where QF-G4 found none.\n",
            "without it, it is not needed, and the command below is printed without it. (5.18.104's --clear-switches is refused at\n"
            "# parse since 5.18.105.)\n", why="summary flags comment")
    doc.sub('  [ -z "${QF_CLEAR:-}" ] || F_FLAGS="$F_FLAGS --clear-switches"\n', '', why="summary F_FLAGS")
    doc.sub('  { [ -z "$CLEAR_SWITCHES" ] || [ -n "${QF_CLEAR:-}" ]; } || F_TYPED="$F_TYPED --clear-switches"\n', '', why="summary F_TYPED")
    doc.mustnot("CLEAR_SWITCHES=")
    doc.mustnot("$CLEAR_SWITCHES")

    # ── V3g: no excuse branch — any change of an ai_, evo_, openai_ or claude_ key is a FAIL, named, never a value ──
    doc.between("    else\n      # 5.18.104 (DS5, S2): the sales follow-up's switch tool, where this run ran it — QF-G4's --clear-switches before the\n",
                "  *) bad \"V3g could not read the AI settings",
                "    else bad \"V3g the AI or WhatsApp settings CHANGED across this run: $(ai_changed \"$AIS_A\" \"$AIS_AFTER\")— $EXPECTED_VERSION's deploy, its --after-only and its rollback change none of them, and run no switch tool (names only, never a value; root docs/74 §H.4.3)\"\n"
                "    fi ;;\n"
                "  *) bad \"V3g could not read the AI settings", keep_ends=False, why="V3g")
    doc.mustnot("v3g_tool")
    doc.mustnot("v3g_own")

    # ── the deploy's opening line for admins ──
    doc.sub("and turn on no step of the sales follow-up (scripts/sales-followup.sh, root docs/73 §H.2.15) until then: then follow its summary's steps\"; fi",
            "and run no scripts/sales-followup.sh (on, off, skip or set) until then — LK-B1 and LK-CA1 would read the change (root docs/74 §H.4.7): then follow its summary's steps\"; fi",
            why="admins line")
