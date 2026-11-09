# Part H — what the operator reads (root docs/74 §H.4.7): the header's title and description, its 5.18.105 paragraphs,
# the options, the stages and the summary. They say: 5.18.104 must be live; the ladder is kept exactly as found and the
# script never runs the switch tool's on, off, skip, set or erase; step 12 may follow once 5.18.105 serves and its
# --after-only has passed; the rollback keeps every step running, and the masked lines then write numbers again; A14 needs
# the quarantine's --check after any copy or rollback later than 04:39:36 UTC on 9 Nov; do not run scripts/sales-followup.sh
# while the deploy, its --after-only or its rollback runs (LK-B1 and LK-CA1 would read the change); the deploy window is
# outside 14:55–15:10 UTC and outside the morning list's send (inferred); the deploy and its rollback are never in one
# copyable block (root docs/44 §16.9). Nothing here is a check: every line is printed text or a comment.


def apply(doc, ctx):
    n_a, n_c, n_lm, n_ln = ctx["n_added"], ctx["n_changed"], ctx["lm_n"], ctx["lm_ln_n"]
    n_rt = len(ctx["runtime_changed"])

    # ── the title and the release's paragraph ──
    doc.lines_between("# deploy-5.18.104.sh — on Uganda the sales follow-up: a read-only report, quote tracking and the day 1/3/7 chase, the",
                      "#            are the added ones).",
        "# deploy-5.18.105.sh — on Uganda, no phone number in any log line: the log statements that could carry a customer's or a\n"
        "# staff member's number masked, PluginLog::tail() for the three that keep two digits, and the report's BY NUMBER column\n"
        "# padded by characters — plugin 5.18.105, Uganda; root docs/74, 9 Nov — over 5.18.104 (live), every switch of the sales\n"
        "# follow-up kept exactly as found; or roll it back to 5.18.104, every switch still kept.\n"
        "#\n"
        "#   5.18.105 No phone number in a log line (Uganda; root docs/74 §A, §H.1): the owner's rulings of 8 and 9 Nov — option\n"
        f"#            B, every log line that can carry a number on Uganda. {n_rt} runtime files change, {n_ln} masked log lines among\n"
        "#            them (LN-01…LN-35, 37 statements): where the line already names its record, the number goes; where it named\n"
        "#            nothing else, a record id takes its place; the webhook log's three phone fields keep •••• and the last two\n"
        "#            digits (PluginLog::tail()). The report's BY NUMBER table pads by characters, not bytes. Nothing else changes:\n"
        "#            no message, send, screen or stored record; no new setting; NO MIGRATION. The sales follow-up's ladder is\n"
        "#            5.18.104's, byte for byte (its tool, its two classes, master, the event processor, NotificationService and\n"
        "#            089–091 held by their sha256), and THIS SCRIPT KEEPS EVERY ONE OF ITS KEYS AS FOUND: it never runs the switch\n"
        "#            tool's on, off, skip, set or erase, nor scripts/sales-followup.sh, before, during or after the copy, or in\n"
        "#            the rollback — the steps that are on (1 report and 2 ack since 9 Nov; 3–5 planned for 10 Nov; 6 chase from\n"
        "#            about 13 Nov) keep running through the deploy, and are judged, never required off. Step 12 (offers-live)\n"
        "#            waits until 5.18.105 serves and its --after-only has passed. South Sudan: the lines it shares with Uganda\n"
        f"#            lose their numbers too, nothing else changes. Files, against 994d660: {n_c} changed, {n_a} added — {n_a + n_c}\n"
        "#            (the added ones are its tests).", why="title")

    # ── 5.18.105's own checks, and how 5.18.104's are carried ──
    doc.insert_before("#   5.18.104'S OWN CHECKS, Part F's (QF, \"follow-up\"; root docs/73 §H.2.15):",
        "#   5.18.105'S OWN CHECKS (root docs/74 §H.4, §H.5): THE LADDER KEPT AS FOUND (LK). LK-G1, stage A: the ladder's keys —\n"
        "#            SalesFollowupLadder::allKeys() and settingKeys() — read read-only in four places (<data>/kyc_config.json,\n"
        "#            its store row, <plugin>/data/config.json, <data>/config.json): NO-GO only where a place cannot be read, or an\n"
        "#            allKeys() key is held by a config.json (the tool's off would read back FAIL key_outside_overrides); never\n"
        "#            for a key that is on — a note gives the steps on and skipped, the ledger, the counts per place, every key\n"
        "#            where file and store disagree; A NOTE IN CAPITALS when offers_live_enabled is set. LK-B1, after DEPLOY and\n"
        "#            before the copy: the same reading, equal key by key and place by place, or NO-GO and nothing is copied\n"
        "#            (\"the switch tool was run meanwhile\"). LK-CA1 after the copy: equal to LK-B1's (FAIL otherwise); LK-CA2:\n"
        "#            no table's rows fell (quote_followup_state over its rows not list_last:%), _schema_checks unchanged, the\n"
        "#            four jobs' schedule entries kept; LK-ON: each step on, the installed tool's --check against the pin's at\n"
        "#            stage A (PASS then FAIL now is a FAIL); LK-AO in --after-only, against the deploy's map, a note. Each key's\n"
        "#            value is a 16-hex HMAC under a key kept in /root/dnb-5.18.105/lk-key-5.18.105 (mode 0600) — the map on the\n"
        "#            sheet and in the state file, never a value in the log. THE MASKED LINES (LM). LM-G1, stage A: in the pin's\n"
        "#            files from Git each of the build's blocks (one per hunk, from Git, markers aside; counted as blocks of\n"
        "#            lines by sha256 — this script carries no source text) once and its old block nowhere, the control on\n"
        "#            994d660; LM-G2: the on-chase precondition's two strings once each in the pin's evo_webhook.php; LM-CA1 the\n"
        "#            same on the installed files; LM-CA2 the PHP log's old-form opt-out lines 60 s or more after the copy (0,\n"
        "#            else FAIL) and the sinks' records with a run of 9 or more digits since then (a note); LM-ON in\n"
        "#            --after-only; LM-RB1 after a rollback: the old blocks back. THE ROLLBACK (RB105): LK-RB0 before ROLLBACK is\n"
        "#            typed; after the put-back master and the four jobs, LK-RB1 (every key in every place, the tool's show and\n"
        "#            the rows as before), the open quote follow-ups and running offers counted, LM-RB1.\n"
        "#\n"
        "#   5.18.104'S CHECKS, CARRIED AND MADE LIVE-AWARE (root docs/74 §H.4.4). The paragraphs below are 5.18.104's, and\n"
        "#            stand as amended here: QF-G1 accepts only 5.18.104's tables as it left them (088 last is \"deploy\n"
        "#            5.18.104 first\"); QF-G2, VN-G7 and OF-G2 only \"none pending\"; QF-G4 is retired (LK-G1), with\n"
        "#            --clear-switches, which is refused; QF-CA2's and QF-CA3's dark-build judgements are LK-CA2's; QF-CA3 lists\n"
        "#            each job OFF (switch) exactly when its flag is unset; QF-CA5, VN-CA1 and OF-CA1 equal stage A's readings\n"
        "#            through the pin's code; VN-CA2's ai_media as its flag says; VN-CA4's keys are LK-CA1's, its rows a note;\n"
        "#            OF-CA3's late counts a note; AC-CA1's on() as its key says. RETIRED with the off-all rollback: QF-RB1–QF-RB3,\n"
        "#            VN-RB1–VN-RB4 and VN-RB6, OF-RB1, AC-RB1; VN-RB5, OF-RB2 and AC-RB2 are notes (\"they continue under 994d660\n"
        "#            as their switches say\").\n"
        "#\n", why="5.18.105 paragraphs")

    # ── the pilot and switches paragraph's exception ──
    doc.lines_between("#            One exception, V3g's: the sales follow-up's switch tool, where this run runs it (QF-G4's --clear-switches,",
                      "#            QF-RB1), clears 5.18.104's own keys, Part V's ai_ keys among them — V3g names each it removed, a note.",
        "#            No exception: this run runs no switch tool, so V3g fails on any change of an ai_, evo_, openai_ or claude_\n"
        "#            key, and the sales follow-up's keys are LK's — kept as found (LK-B1, LK-CA1, LK-RB1).", why="pilot exception")

    # ── what must be live; before the deploy; the deploy's window ──
    doc.lines_between("# 5.18.103 must be the live plugin: this script refuses any other live commit or version — deploy 5.18.103 first.",
                      "# once DEPLOY is typed (Options).",
        "# 5.18.104 must be the live plugin: this script refuses any other live commit or version — deploy 5.18.104 first.\n"
        "#\n"
        "# Before the deploy (root docs/74 §H.7): 5.18.104's deployment is recorded in root docs/07 — its copy (9 Nov 03:50:25\n"
        "# UTC), its --after-only and the quarantine's --check begun after it (04:39:36 UTC, PASSED), whose record A14 reads; A14\n"
        "# needs the quarantine's --check after any later copy or rollback. The ladder is kept exactly as found: the steps that\n"
        "# are on keep running. Do not run scripts/sales-followup.sh while the deploy, its --after-only or its rollback runs —\n"
        "# LK-B1 and LK-CA1 would read the change. A --check first is wise: LK-G1, LM-G1 and LM-G2 refuse or pass, QF-N1–QF-N7\n"
        "# note, nothing changed.", why="before the deploy")
    doc.sub("# quarantine's --check has PASSED after 5.18.103's deploy; not between 14:55 and 15:10 UTC (the evening WhatsApp, 18:00\n"
            "# Kampala):\n",
            "# quarantine's --check has PASSED after 5.18.104's deploy; not between 14:55 and 15:10 UTC (the evening WhatsApp, 18:00\n"
            "# Kampala), and not while the morning list is being sent (step 4; inferred):\n", why="deploy window")
    doc.sub("# its own command, with its own log, read-only, nothing deployed (QF-CA1–QF-CA8, VN-M1–VN-K2, OF-M1–OF-CA5, AC-CA1,\n"
            "# AC-CA2; QF-ON0–QF-ON5, VA-ON1, VN-ON1–VN-ON4, OF-ON1, OF-ON2 and AC-ON1 for each step that is on); then the quarantine's own --check, its own (the summary prints it):",
            "# its own command, with its own log, read-only, nothing deployed (LM-ON, LK-AO; QF-CA1–QF-CA8, VN-M1–VN-K2,\n"
            "# OF-M1–OF-CA5, AC-CA1, AC-CA2; QF-ON0–QF-ON5, VA-ON1, VN-ON1–VN-ON4, OF-ON1, OF-ON2 and AC-ON1 for each step that is\n"
            "# on); then the quarantine's own --check, its own (the summary prints it):", why="after-only words")
    doc.lines_between("# Then the steps, yours alone, one at a time and only in the ladder's order: the tool refuses a step whose steps before",
                      "# a copy of the book (P1), its own command, its log in /root/dnb-report:",
        "# Then the steps, yours alone, one at a time and only in the ladder's order, as under 5.18.104 — this script turns none\n"
        "# on or off. Step 12 (offers-live) only once 5.18.105 serves and its --after-only has passed. The report, read-only on a\n"
        "# copy of the book (P1), its own command, its log in /root/dnb-report:", why="steps")
    doc.lines_between("# The rollback is a separate command, printed at the end of the deploy's log, after the verdict. It is never pasted",
                      "# uCRM are not changed, and the 215 files 5.18.104 adds stay on disk, reached by nothing.",
        "# The rollback is a separate command, printed at the end of the deploy's log, after the verdict. It is never pasted\n"
        "# together with the ones above: pasted together, the shell runs both (root docs/44 §16.9). Before ROLLBACK is typed it\n"
        "# reads the ladder (LK-RB0) and, if step 12 is on, says in capitals that 994d660 writes numbers again in the masked\n"
        "# lines while offers run, naming your own alternative (scripts/sales-followup.sh off offers-live), never run by it and\n"
        "# never printed with this command; it is never blocked by the ladder. It puts 994d660 back — the documented deploy of\n"
        "# 5.18.104 — and runs no switch tool and no by-hand put-back of rows or keys: every step keeps running as its switch\n"
        "# says, the open quote follow-ups and running offers carry on, 089–091 and P7's collections stay. Then RB105 reads\n"
        "# the put-back: master with the four jobs last, every ladder key and the tool's show as before (LK-RB1), no table's\n"
        "# rows fell, and the old lines back (LM-RB1) — the masked lines write numbers again. ECO Bank, every statement kept,\n"
        f"# its switch, the quoting organization, the uCRM catalogue, the channel registry and every number's owner stay exactly\n"
        f"# as they are; the {n_a} files 5.18.105 adds (its tests) stay on disk, reached by nothing (QC-RB1). A later deploy of\n"
        "# 5.18.105 goes with --redeploy.", why="rollback paragraph")

    # ── the options ──
    doc.lines_between("#   --after-only         the deploy already happened: run the checks again — QF-CA1–QF-CA8, VN-M1, VN-M2, VN-CA1–VN-CA4,",
                      "#                        VN-K1, VN-K2, OF-M1, OF-M2, OF-CA1–OF-CA5, AC-CA1, AC-CA2, and QF-ON0–QF-ON5, VA-ON1,",
        "#   --after-only         the deploy already happened: run the checks again — LM-ON and LK-AO (the ladder against the\n"
        "#                        deploy's, a note), QF-CA1–QF-CA8, VN-M1, VN-M2, VN-CA1–VN-CA4,\n"
        "#                        VN-K1, VN-K2, OF-M1, OF-M2, OF-CA1–OF-CA5, AC-CA1, AC-CA2, and QF-ON0–QF-ON5, VA-ON1,", why="--after-only")
    doc.lines_between("#   --redeploy           deploy 5.18.104 again after its own rollback: the files it adds, which the rollback leaves on",
                      "#                        copy. The uCRM catalogue's settings are read as 5.18.103 left them (QC-G1, QC-N1)",
        "#   --redeploy           deploy 5.18.105 again after its own rollback: the files it adds (its tests), which the rollback\n"
        "#                        leaves on disk, are expected there (BR1 counts them, and the copy replaces them); QF-G1 reads\n"
        "#                        5.18.104's tables as it left them, and the ladder rule holds as on the first deploy (LK)", why="--redeploy")
    doc.lines_between("#   --clear-switches     with a deploy (a redeploy) or --check, the operator's explicit choice, printed as such (root",
                      "#                        serving, it is cleared and said",
        "#   --clear-switches     REFUSED (root docs/74 §H.4.4): 5.18.105 never clears a ladder key; turn a step off yourself\n"
        "#                        with scripts/sales-followup.sh off <step>, its own command", why="--clear-switches")
    doc.lines_between("#   --rollback           typed ROLLBACK: first QF-RB1 — the installed scripts/sales-followup.sh off all --rollback, the",
                      "#                        refuses a copy that stopped part-way, and prints the by-hand put-back of 5.18.103's code",
        "#   --rollback           typed ROLLBACK, after LK-RB0 and the step-12 notice (root docs/74 §H.5.3): 5.18.104 (994d660) put\n"
        "#                        back by the documented deploy, every switch kept — no switch tool, no off-hook, no by-hand put-back\n"
        "#                        of rows or keys — then checked: RB105 (master, LK-RB1, the rows, the open quote follow-ups and\n"
        "#                        running offers counted, LM-RB1: the masked lines write numbers again), 5.18.104's files by sha256,\n"
        "#                        its sign-off line, owner rule and quoting code in place, the files 5.18.105 adds left on disk and\n"
        "#                        reached by nothing (QC-RB1), the Cashbook's list and ECO Bank exactly as they were, D2 = A on a\n"
        "#                        copy of the book (its own cash sheet), 5.18.95's security fixes, the documents, the database (the\n"
        "#                        ledger the same, 091 last), and the numbers a technician owns counted; its record goes to a file\n"
        "#                        of its own. It refuses a copy that stopped part-way, and prints the by-hand put-back of 5.18.104's\n"
        "#                        code", why="--rollback")

    # ── what it never does; what it writes ──
    doc.lines_between("# clear or verify anything — bank_screen_enabled, ucrm_quote_organization_id and wa_inbox_roles included — but the",
                      "# offers paused, recipients settled, audio rows settled); add, give,",
        "# clear or verify anything — bank_screen_enabled, ucrm_quote_organization_id, wa_inbox_roles and every key of the sales\n"
        "# follow-up's ladder included: it never runs the switch tool's on, off, skip, set or erase, nor scripts/sales-followup.sh,\n"
        "# in any mode (root docs/74 §H.4.3); add, give,", why="never does")
    doc.lines_between("# so; it is not a run on a copy. The writes are the documented deploy (or rollback), a backup under /root/dnb-5.18.104/,",
                      "# runs it — 5.18.104's own keys cleared, its off-hooks' rows.",
        "# so; it is not a run on a copy. The writes are the documented deploy (or rollback), a backup under /root/dnb-5.18.105/,\n"
        "# the state file, the LK key file (mode 0600), the cash sheets and the run's copies in the container's /tmp (removed), and\n"
        "# the time of the copy on the files this release changes or adds — never a key of the ladder.", why="writes")

    # ── the stages ──
    doc.sub("#            copy's catalogue settings, GET-only (QC-G1), and its notes (QC-N1–QC-N7); 5.18.104's own — QF-G1–QF-G4,\n"
            "#            QF-N1–QF-N7, VN-G1–VN-G7, OF-G1, OF-G2, OF-N1–OF-N3, AC-L1, AC-N1 and AC-N2; the backup, the event queue's\n",
            "#            copy's catalogue settings, GET-only (QC-G1), and its notes (QC-N1–QC-N7); 5.18.104's, live-aware — QF-G1–\n"
            "#            QF-G3, LK-G1 (5.18.105's, in QF-G4's place), QF-N1–QF-N7, VN-G1–VN-G7, OF-G1, OF-G2, OF-N1–OF-N3, AC-L1, AC-N1\n"
            "#            and AC-N2; 5.18.105's LM-G1 and LM-G2; the backup, the event queue's\n", why="stage A")
    doc.lines_between("#          B the documented deploy (or, with --rollback, once ROLLBACK is typed, QF-RB1 — the installed tool's off all",
                      "#            copy's time on each file",
        "#          B LK-B1 and the baselines, then the documented deploy (or, with --rollback, LK-RB0 and, once ROLLBACK is\n"
        "#            typed, the documented deploy of 5.18.104 — no switch tool); the copy's time on each file", why="stage B")
    doc.sub("#          V as 5.18.103 (V1's automatic rollback runs QF-RB1 first, and QF-RB3 once verified): the public pages and the",
            "#          V as 5.18.104 (V1's automatic rollback runs no switch tool, and RB105 once verified): the public pages and the", why="stage V")
    doc.sub("#          R 5.18.104 installed: R1 the files byte for byte and the version; Q-CA1 the release by its pinned sha256,\n"
            "#            5.18.103's kept files by theirs, php -l on every PHP file it changes or adds; R2–R22 as 5.18.103 but R7 and\n",
            "#          R 5.18.105 installed: LK-CA1, LK-CA2, LK-ON and LM-CA1, LM-CA2 (LM-ON, LK-AO in --after-only); R1 the files\n"
            "#            byte for byte and the version; Q-CA1 the release by its pinned sha256, 5.18.104's kept files by theirs,\n"
            "#            php -l on every PHP file it changes or adds; R7 the ledger the same (091 last); R2–R22 as 5.18.103 but R7 and\n",
            why="stage R")
    doc.lines_between("#          RB after a rollback (QF-RB1, and QF-RB2's note, before the put-back): 5.18.103's files by sha256 where 5.18.104",
                      "#            follow-up), the 215 files 5.18.104 adds on disk and 089–091's tables left in place, reached by nothing",
        "#          RB after a rollback (LK-RB0 before it; RB105 after it — master, LK-RB1, the rows, LM-RB1): 5.18.104's files by\n"
        f"#            sha256 where 5.18.105 changed them (Q-G1's set of {n_c}), every ladder key as before, the {n_a} files 5.18.105\n"
        "#            adds on disk, reached by nothing", why="stage RB")
    doc.lines_between("#          F summary: which of the checks each name is (5.18.104's own first — QF-G1–QF-G4, QF-N1–QF-N7, QF-CA1–QF-CA8,",
                      "#            QF-ON0–QF-ON5, QF-RB1–QF-RB3; VN and VA, Part V's; OF, Part O's; AC, Part P's — then 5.18.103's, carried,",
        "#          F summary: which of the checks each name is (5.18.105's own first — LK-G1, LK-B1, LK-CA1, LK-CA2, LK-ON, LK-AO,\n"
        "#            LK-RB0, LK-RB1, LM-G1, LM-G2, LM-CA1, LM-CA2, LM-ON, LM-RB1, RB105; then 5.18.104's, QF-G1–QF-G3, QF-N1–QF-N7,\n"
        "#            QF-CA1–QF-CA8, QF-ON0–QF-ON5; VN and VA, Part V's; OF, Part O's; AC, Part P's — then 5.18.103's, carried,",
        why="stage F")
    doc.sub("#            (none changes while every switch is off) and the operator's steps — the report\n"
            "#            (scripts/sales-followup-report.sh), then each step of the ladder through scripts/sales-followup.sh, its\n"
            "#            --check, then --after-only; the rollback last, after the verdict, its own block\n",
            "#            (none changes: every switch kept) and the operator's steps — where the ladder stands, then each step\n"
            "#            through scripts/sales-followup.sh, its --check, then --after-only, step 12 only once 5.18.105's\n"
            "#            --after-only has passed; the rollback last, after the verdict, its own block\n", why="stage F 2")

    # ── the summary ──
    doc.lines_between('echo "  names             QF-G1–QF-G4, QF-N1–QF-N7, QF-CA1–QF-CA8, QF-ON0–QF-ON5 and QF-RB1–QF-RB3 are 5.18.104\'s own"',
                      'echo "                    AC-CA2, AC-ON1, AC-RB1 and AC-RB2 are Part P\'s (AC; §H.5.14); QC-G1, QC-N1–QC-N7, QC-CA1–QC-CA3, QC-ON1–QC-ON4,"',
        'echo "  names             LK-G1, LK-B1, LK-CA1, LK-CA2, LK-ON, LK-AO, LK-RB0, LK-RB1, LM-G1, LM-G2, LM-CA1, LM-CA2, LM-ON,"\n'
        'echo "                    LM-RB1 and RB105 are $EXPECTED_VERSION\'s own (root docs/74 §H.4, §H.5); QF-G1–QF-G3, QF-N1–QF-N7,"\n'
        'echo "                    QF-CA1–QF-CA8 and QF-ON0–QF-ON5 are 5.18.104\'s (QF, Part F\'s; root docs/73 §H.2.15), carried and"\n'
        'echo "                    live-aware (its QF-G4 and QF-RB1–QF-RB3 retired); VN-G1–VN-G7, VN-M1, VN-M2, VN-CA1–VN-CA4, VN-K1,"\n'
        'echo "                    VN-K2, VN-ON1–VN-ON4, VN-RB5 and VA-ON1 are Part V\'s (VN, VA; §H.3.25); OF-G1, OF-G2, OF-N1–OF-N3,"\n'
        'echo "                    OF-M1, OF-M2, OF-CA1–OF-CA5, OF-ON1, OF-ON2 and OF-RB2 are Part O\'s (OF; §H.4.18); AC-L1, AC-N1,"\n'
        'echo "                    AC-N2, AC-CA1, AC-CA2, AC-ON1 and AC-RB2 are Part P\'s (AC; §H.5.14); QC-G1, QC-N1–QC-N7, QC-CA1–QC-CA3, QC-ON1–QC-ON4,"',
        why="summary names")
    doc.sub('  # 5.18.104 (DS5): QF-G1\'s reading — this run\'s, or in an --after-only run the deploy\'s, from its state file — decides\n'
            '  # what the summary says of 089–091: added at the first boot (first), or already in the ledger (redeploy)\n',
            '  # 5.18.105: QF-G1 reads 089–091 as 5.18.104 left them (live) — the only form it accepts — and this run adds none\n',
            why="summary QF_G1 comment")
    doc.line('  echo "  what changed      on this Uganda server: $EXPECTED_VERSION\'s sales follow-up',
             f'  echo "  what changed      on this Uganda server: $EXPECTED_VERSION\'s log lines — {n_rt} runtime files, {n_ln} masked log lines (LM-CA1): no phone number in a log line any more, the webhook log\'s three phone fields as •••• and two digits, the report\'s BY NUMBER column padded by characters; no message, send, screen, setting or stored record changes, and no migration. The sales follow-up\'s ladder is $BASELINE_VERSION\'s and every key of it is as this run found it (LK-CA1): the steps that are on keep running ($(qf_kv "${{LK_B1:-$LK_G1}}" on | tr \',\' \' \'))"',
             why="summary what changed")
    doc.line('  echo "  migrations        089, 090 and 091 $( [ "$F_QG1" = redeploy ]',
             '  echo "  migrations        none added: 089, 090 and 091 as $BASELINE_VERSION left them (QF-G1, QF-CA1), the ledger the same before and after (R7)"',
             why="summary migrations")
    doc.lines_between('  echo "  the screens       none changes now but one: every switch of $EXPECTED_VERSION\'s sales follow-up is off (QF-CA5),"',
                      '  echo "                    changes. Do NOT make a test quote for a real customer: the plugin WhatsApps every new quote"',
        '  echo "  the screens       none changes: every switch of the sales follow-up is as this run found it (LK-CA1, QF-CA5), so"\n'
        '  echo "                    every screen is as under $BASELINE_VERSION with the steps that are on. No screen of uCRM\'s"\n'
        '  echo "                    changes. Do NOT make a test quote for a real customer: the plugin WhatsApps every new quote"',
        why="summary screens")
    doc.lines_between('  echo "  your steps        yours alone, one at a time and only in the ladder\'s order (root docs/73 §H.2.3, C6) — this"',
                      '  echo "      cd $REPO && bash $QF_WRAPPER --check report"',
        '  echo "  your steps        yours alone, one at a time and only in the ladder\'s order (root docs/73 §H.2.3, C6) — this"\n'
        '  echo "                    script does none of them, and kept every one as it found it. Step 12 (offers-live) only"\n'
        '  echo "                    once $EXPECTED_VERSION serves and its --after-only (above) has PASSED (root docs/74 §A). Each"\n'
        '  echo "                    step: on, then its check, each its own command, alone, its log in /root/dnb-sales; never"\n'
        '  echo "                    while this script runs. Where the ladder stands, read-only:"\n'
        '  echo "      cd $REPO && bash $QF_WRAPPER show"\n'
        '  echo\n'
        '  echo "                    The report, read-only on a copy of the book (P1; its wrapper pinned to $EXPECTED_VERSION), its own"\n'
        '  echo "                    command, alone:"\n'
        '  echo "      $QF_REPORT_CMD"', why="summary steps")
    doc.sub('  echo "                    After each step, --after-only (check it, above): QF-ON0–QF-ON5, VA-ON1, VN-ON1–VN-ON4, OF-ON1,"\n',
            '  echo "                    After each step, --after-only (check it, above): LK-AO, LM-ON, QF-ON0–QF-ON5, VA-ON1, VN-ON1–VN-ON4, OF-ON1,"\n',
            why="summary after-only")
    doc.sub('  echo "                    Until a step is on, $EXPECTED_VERSION behaves as $BASELINE_VERSION."\n',
            '  echo "                    A step behaves under $EXPECTED_VERSION as under $BASELINE_VERSION; only its log lines carry no number."\n',
            why="summary until")
    doc.lines_between("# one. Then the rollback, last, in its own block: QF-RB1 first (the installed tool's off all --rollback; it stops before",
                      "# counts nothing, and says so.",
        "# one. Then the rollback, last, in its own block (root docs/74 §H.5): 5.18.104 put back by the documented deploy, every\n"
        "# switch kept — no switch tool, no off-hook — then RB105; ECO Bank and its switch stay as they are. It needs no explicit\n"
        "# choice: going back to 5.18.104 has no refusal of its own, and the ladder never blocks it. The by-hand path is the\n"
        "# code's put-back alone (PUTBACK_CMD); it counts nothing, and says so.", why="summary rollback comment")
    doc.lines_between('  echo "  $BASELINE_VERSION ($BASELINE_COMMIT) back — it asks you to type ROLLBACK before it changes anything,"',
                      '  echo "      $PUTBACK_CMD"',
        '  echo "  $BASELINE_VERSION ($BASELINE_COMMIT) back — it asks you to type ROLLBACK before it changes anything. It"\n'
        '  echo "  keeps every switch of the sales follow-up as it is — no switch tool, no off-hook: every step that is on keeps"\n'
        '  echo "  running, open quote follow-ups and running offers carry on — and the lines $EXPECTED_VERSION masked write phone"\n'
        '  echo "  numbers again. If step 12 is on, it says so in capitals before you type ROLLBACK and names your own"\n'
        '  echo "  alternative; it never runs it. Then $BASELINE_VERSION is put back and checked (RB105, LM-RB1). The uCRM"\n'
        '  echo "  catalogue stays as it is. Quotes already made in uCRM are not changed, and the $N_ADDED files $EXPECTED_VERSION"\n'
        '  echo "  adds (its tests) stay on disk, reached by nothing. The summary\'s sign-off, the card\'s owner lists, every number"\n'
        '  echo "  and its owner, the quoting organization, the Cashbook, cash in hand, ECO Bank, its switch, 088\'s tables and every"\n'
        '  echo "  statement kept stay exactly as they are; 5.18.95\'s security fixes, 5.18.94\'s documents and every other switch"\n'
        '  echo "  stay as they are. Then run the quarantine\'s own --check (A14 of any later deploy needs it)."\n'
        '  echo "      $ROLLBACK_CMD"\n'
        '  echo\n'
        '  echo "  Only if that script cannot run — the same put-back of the code by hand, its own command, alone (never with the"\n'
        '  echo "  one above; it counts nothing and runs no switch tool):"\n'
        '  echo "      $PUTBACK_CMD"', why="summary rollback block")
    doc.mustnot('bash $QF_WRAPPER off all --rollback')
    doc.mustnot('bash $QF_WRAPPER on report --ran')

    # ── the header's operational paragraphs: what is live, what the pin is cut on, the commands, the paths ──
    n_k, n_pin = ctx["n_kept"], ctx["n_pin"]
    doc.sub("#            /root/dnb-5.18.104/cash-<mode>-<UTC stamp>.txt, mode 0600, in a folder of mode 700. It holds the book's cash in\n",
            "#            /root/dnb-5.18.105/cash-<mode>-<UTC stamp>.txt, mode 0600, in a folder of mode 700. It holds the book's cash in\n",
            why="cash sheet path")
    doc.sub("# The run's cash sheet, on the host: /root/dnb-5.18.104/cash-<mode>-<stamp>.txt, mode 0600, in a folder of mode 700 — the\n",
            "# The run's cash sheet, on the host: /root/dnb-5.18.105/cash-<mode>-<stamp>.txt, mode 0600, in a folder of mode 700 — the\n",
            why="cash sheet path 2")
    doc.lines_between("#            id 0 in the cart. After a rollback: QC-RB1, re-pointed to 5.18.104 (5.18.103's catalogue is installed code",
                      "#            its Orders card offers it; an admin quotes one in uCRM by hand only where its own card says so).",
        "#            id 0 in the cart. After a rollback: QC-RB1, re-pointed to 5.18.105 (root docs/74 §H.5.4): nothing in 5.18.104\n"
        "#            names a word 5.18.105 adds — its tests' own names, read from Git — every file 994d660 tracks swept, data/ aside,\n"
        "#            beside its control on the pin; and the table ucrm_catalogue counted, 5.18.103's own; QC-RB2 the web-direct\n"
        "#            applications with no quotation, counted, their ids on the sheet (a note: the rollback changes none of them —\n"
        "#            5.18.104, put back, handles each exactly as 5.18.105 does, with the same KycService and UcrmQuoteLines:\n"
        "#            retried by its uCRM ids, Make the quotation where its Orders card offers it; an admin quotes one in uCRM by\n"
        "#            hand only where its own card says so).", why="QC-RB1 paragraph")
    doc.lines_between("#   A ROLLBACK TO 5.18.103 LEAVES THE uCRM CATALOGUE AS IT IS; IT COUNTS THE WEB-DIRECT APPLICATIONS FOR THE RECORD",
                      "#            installed files may already be 5.18.103's — and prints 5.18.103's by-hand put-back, which counts nothing.",
        "#   A ROLLBACK TO 5.18.104 LEAVES THE uCRM CATALOGUE AS IT IS; IT COUNTS THE WEB-DIRECT APPLICATIONS FOR THE RECORD\n"
        "#            (docs/72 §G.8, G-10). 5.18.105 keeps every file of the catalogue byte for byte — lib/UcrmCatalogue.php and its\n"
        "#            screen, UcrmQuoteLines, QuoteOrganization, KycService — so the put-back copies the same files; its settings\n"
        "#            and its switch stay in the store as an admin left them, read by 5.18.104 as before. A web-direct application is\n"
        "#            handled by 5.18.104's own rules — KycService retries it by its uCRM ids, its Orders card offers Make the\n"
        "#            quotation where that applies — exactly as by 5.18.105's, so the rollback leaves none to quote in uCRM by hand.\n"
        "#            --rollback counts those with no quotation in stage A and again after the rollback, on a copy of the book, read-\n"
        "#            only (QC-RB2: counts in the log, their ids on the sheet) — a note, never a refusal; so does the deploy's own\n"
        "#            automatic rollback (V1), after it. When V1's rollback does not verify, its stop says the rollback did not\n"
        "#            complete — 5.18.105 may still serve, or some installed files may already be 5.18.104's — and prints 5.18.104's\n"
        "#            by-hand put-back, which counts nothing and runs no switch tool.", why="rollback catalogue paragraph")
    doc.lines_between("#            and 5.18.103 keep webhook.php byte for byte (docs/71 §L.4, docs/72 §G.12), and 5.18.104 adds only P7's 18",
                      "#            gate, so every reading is 5.18.101's",
        "#            and 5.18.103 keep webhook.php byte for byte (docs/71 §L.4, docs/72 §G.12), 5.18.104 adds only P7's 18\n"
        "#            lines in whQuotationEmail (root docs/73 §H.5.8.2) and 5.18.105 changes only its log lines (LM), none of them\n"
        "#            the sign-off line, its old phrase or its gate, so every reading is 5.18.101's", why="QS paragraph")
    doc.lines_between("#            (TenantProfile::current()->id()). By the sha256 pinned here, Q-CA1 reads every file 5.18.104 changes or adds",
                      "#            left on disk, and QC-RB1 shows that nothing in 5.18.103 reaches them.",
        f"#            (TenantProfile::current()->id()). By the sha256 pinned here, Q-CA1 reads every file 5.18.105 changes or adds\n"
        f"#            (its {n_pin}, as the pin has them); Q-G1 before the copy, and RB after a rollback, read every file it changes\n"
        f"#            ({n_c}), as 5.18.104 has them; all three read the {n_k} files of 5.18.104 it must not touch —\n"
        "#            lib/QuoteOrganization.php, lib/QuoteTaxLine.php, lib/TenantProfile.php, lib/PublicPriceFeed.php,\n"
        "#            lib/UcrmQuoteLinks.php, lib/UcrmQuoteLines.php, lib/UcrmCatalogue.php with its screen and the sales follow-up's\n"
        "#            ladder among them (not webhook.php, cron_quote_wa.php or post_kyc.php, whose log lines 5.18.105 masks). The\n"
        f"#            {n_a} files it adds (its tests) have no 5.18.104 form: BR1 counts any left on disk, and QC-RB1 shows that\n"
        "#            nothing in 5.18.104 reaches them.", why="sha paragraph")
    doc.sub("#            run. After a rollback (RB): the owner rule 5.18.100's, as 5.18.103 installs it, as text and on a copy, and the\n"
            "#            numbers a technician owns counted — they keep their owner and work under 5.18.103 as before.\n",
            "#            run. After a rollback (RB): the owner rule 5.18.100's, as 5.18.104 installs it, as text and on a copy, and the\n"
            "#            numbers a technician owns counted — they keep their owner and work under 5.18.104 as before.\n",
            why="owner rule after rollback")
    doc.sub("#   5.18.99'S CHECKS, CARRIED, AGAINST 5.18.103'S CODE. Its quoting checks keep their ids (SPEC-quote-company §6): Q-G1 the\n"
            "#            live plugin is 5.18.103 — the version, every file 5.18.104 changes (45) by 221c9dc's sha256 (pinned here),\n"
            "#            and the 5.18.103 files 5.18.104 must not touch (158) by theirs, the quoting files it keeps (QuoteOrganization,\n"
            "#            QuotePdfSource, UcrmLeadSync, post_kyc.php, the Uganda profile, tools/set_config.php) and 5.18.100's owner\n",
            "#   5.18.99'S CHECKS, CARRIED, AGAINST 5.18.104'S CODE. Its quoting checks keep their ids (SPEC-quote-company §6): Q-G1 the\n"
            f"#            live plugin is 5.18.104 — the version, every file 5.18.105 changes ({n_c}) by 994d660's sha256 (pinned here),\n"
            f"#            and the 5.18.104 files 5.18.105 must not touch ({n_k}) by theirs, the quoting files it keeps (QuoteOrganization,\n"
            "#            QuotePdfSource, UcrmLeadSync, the Uganda profile, tools/set_config.php) and 5.18.100's owner\n",
            why="Q-G1 paragraph")
    doc.sub("#            them (Q-N4 extended by QL-N3). After the copy: Q-CA1 the installed files are release/5.18.104's by the sha256\n"
            "#            pinned here, the 5.18.103 files it must not touch 5.18.103's, and the server's PHP accepts every PHP file it\n",
            "#            them (Q-N4 extended by QL-N3). After the copy: Q-CA1 the installed files are release/5.18.105's by the sha256\n"
            "#            pinned here, the 5.18.104 files it must not touch 5.18.104's, and the server's PHP accepts every PHP file it\n",
            why="Q-CA1 paragraph")
    doc.sub("#   ECO BANK STAYS EXACTLY AS IT IS. 5.18.97's and 5.18.98's checks of it, carried: BR1 the installed plugin is live\n"
            "#            5.18.103 (221c9dc), every file it tracks byte for byte; BR2 088 applied on the copy exactly as its file makes\n",
            "#   ECO BANK STAYS EXACTLY AS IT IS. 5.18.97's and 5.18.98's checks of it, carried: BR1 the installed plugin is live\n"
            "#            5.18.104 (994d660), every file it tracks byte for byte; BR2 088 applied on the copy exactly as its file makes\n",
            why="BR1 paragraph")
    doc.sub("#            live files; BN5 the offered accounts per currency, counts only; BK1 cash in hand under the installed 5.18.103\n",
            "#            live files; BN5 the offered accounts per currency, counts only; BK1 cash in hand under the installed 5.18.104\n",
            why="BK1 paragraph")
    doc.sub("#            after 5.18.103's own copy (COPY_AT in its state files: the quarantine's rule 3) and after this script's own\n"
            "#            latest copy — a deploy of 5.18.104 (COPY_AT) or a rollback of it (RB_COPY_AT, in its rollback-state-*.env), so\n",
            "#            after 5.18.104's own copy (COPY_AT in its state files: the quarantine's rule 3) and after this script's own\n"
            "#            latest copy — a deploy of 5.18.105 (COPY_AT) or a rollback of it (RB_COPY_AT, in its rollback-state-*.env), so\n",
            why="A14 paragraph")
    doc.sub("#            needs no step: 5.18.104 changes nothing of it, and its switch stays as an admin left it — under 5.18.103 its\n",
            "#            needs no step: 5.18.105 changes nothing of it, and its switch stays as an admin left it — under 5.18.103 its\n",
            why="catalogue no step")
    doc.lines_between("#   THE COMMIT IT INSTALLS IS A RELEASE COMMIT, NOT THE BRANCH TIP. The pin below, on release/5.18.104, is cut on 221c9dc",
                      "#            for byte (A0).",
        "#   THE COMMIT IT INSTALLS IS A RELEASE COMMIT, NOT THE BRANCH TIP. The pin below, on release/5.18.105, is cut on 994d660\n"
        f"#            (5.18.104, live): {n_pin} files — {n_c} changed, {n_a} added (its tests) — each file's sha256 pinned here. Stage\n"
        "#            A refuses a pin whose parent is not 5.18.104, whose short hash names another commit, whose delta is not exactly\n"
        "#            these files by these hashes, which carries a migration (089–091 held at the pin by their sha256 and md5), and\n"
        "#            a checkout holding a file git does not track under the plugin folder outside data/. Its master.php and main.php\n"
        "#            are checked byte for byte (A0).", why="commit paragraph")
    doc.line("#   cd /opt/dishnet && git pull origin claude/study-this-jhe2eg && git fetch origin release/5.18.104 && mkdir -p /root/dnb-5.18.104 && bash scripts/deploy-5.18.104.sh 2>&1",
             "#   cd /opt/dishnet && git pull origin claude/study-this-jhe2eg && git fetch origin release/5.18.105 && mkdir -p /root/dnb-5.18.105 && bash scripts/deploy-5.18.105.sh 2>&1 | ( umask 077; exec tee /root/dnb-5.18.105/deploy-$(date -u +%Y%m%dT%H%M%SZ).log )",
             why="deploy command")
    doc.line("#   cd /opt/dishnet && bash scripts/deploy-5.18.104.sh --after-only 2>&1",
             "#   cd /opt/dishnet && bash scripts/deploy-5.18.105.sh --after-only 2>&1 | ( umask 077; exec tee /root/dnb-5.18.105/after-$(date -u +%Y%m%dT%H%M%SZ).log )",
             why="after-only command")
    doc.sub("#   --move-list-only     read-only, before the deploy, while 5.18.103 is live: Q-G1, Q-G2, Q-G3, Q-G4 and Q-N2 alone\n",
            "#   --move-list-only     read-only, before the deploy, while 5.18.104 is live: Q-G1, Q-G2, Q-G3, Q-G4 and Q-N2 alone\n",
            why="--move-list-only")
    doc.lines_between("# Stages:  A before-evidence: the release delta by its pinned sha256 and exactly its three migrations, 089, 090 and",
                      "#            their pinned",
        "# Stages:  A before-evidence: the release delta by its pinned sha256, no migration, and 089–091 at the pin by sha256\n"
        "#            and md5 (A0); Domain B untouched (A7); 5.18.95's fixes in\n"
        "#            the pin and in the live code, as text, each with its control on 5.18.94's copy (A22a–A22g, A22L); the live\n"
        "#            commit and version, every file of it (BR1), every file 5.18.105 changes and the 5.18.104 files it keeps by\n"
        "#            their pinned", why="stage A head")
    doc.lines_between("#            adds on disk, reached by nothing",
                      "#            (a note), the web-direct applications with no quotation counted again, ids on the sheet (QC-RB2, a record —",
        "#            adds on disk, reached by nothing\n"
        "#            (QC-RB1, re-pointed), the uCRM catalogue's table counted, 5.18.103's own screen's and read by 5.18.104 again\n"
        "#            (a note), the web-direct applications with no quotation counted again, ids on the sheet (QC-RB2, a record —",
        why="stage RB tail")
    for old in ("deploy-5.18.104.sh", "/root/dnb-5.18.104/cash", "release/5.18.104, is cut", "A ROLLBACK TO 5.18.103",
                "QF-RB2), the uCRM catalogue"):
        doc.mustnot(old)
