# Part H — what the operator reads, for 5.18.104 (root docs/73 §H.2.15, §H.2.16): the header's title and description, the
# cash sheet's folder, 5.18.104's own checks (QF) beside 5.18.103's (QC, now carried), the quarantine paragraph's state
# files, the commands (the deploy, the --after-only, the report and the switch tool, each alone; the rollback described,
# never printed there), the options (--after-only, --redeploy, --clear-switches, --rollback), the deploy's opening line for
# admins, and the summary — the names, what changed, what did not, the migrations, check it, the screens and your steps,
# and the rollback block (QF-RB1 first; the by-hand put-back after the tool's own off). Every block is replaced by an
# asserted edit; the carried paragraphs' remaining comparisons of 5.18.103 with 5.18.102 are left (DS1's not_done).


def apply(doc, ctx):
    na, nc = ctx["n_added"], ctx["n_changed"]
    nt = na + nc

    # ── the title ──
    doc.lines_between("# deploy-5.18.103.sh — on Uganda the uCRM catalogue: a new screen",
                      "# one click — plugin 5.18.103, Uganda; root docs/72 §G, 1 Nov — over 5.18.102; or roll it back to 5.18.102.",
                      "# deploy-5.18.104.sh — on Uganda the sales follow-up: a read-only report, quote tracking and the day 1/3/7 chase, the\n"
                      "# morning list and My quotes, voice notes through OpenAI in four rungs, the voice-note acknowledgement, offers on the\n"
                      "# Evolution numbers and the agent's Cc on the quotation e-mail — every switch OFF, reached only through\n"
                      "# tools/sales_followup.php in one order — plugin 5.18.104, Uganda; root docs/73, 4 Nov — over 5.18.103; or roll it\n"
                      "# back to 5.18.103.", why="title")
    doc.must("# deploy-5.18.104.sh — on Uganda the sales follow-up:", 1)

    # ── what 5.18.104 does ──
    doc.lines_between("#   5.18.103 A new screen, the uCRM catalogue (Uganda;",
                      "#            with its directory tests/catalogue_direct/, are the added ones).",
                      "#   5.18.104 The sales follow-up (Uganda; root docs/73 §H): seven parts behind one ladder of thirteen steps, each OFF\n"
                      "#            until the operator turns it on with scripts/sales-followup.sh (the installed tools/sales_followup.php,\n"
                      "#            run as the book's owner), one step at a time in one order (C6), each only once the steps before it are\n"
                      "#            on (or skipped) and their checks allow it: P1 a read-only report (scripts/sales-followup-report.sh, on a\n"
                      "#            copy of the book); P2 quote tracking and the day 1, 3 and 7 chase of an open quotation, through the\n"
                      "#            follow-up tables with kind 'quote'; P3 the morning list and the staff page My quotes; P4 voice notes\n"
                      "#            understood through OpenAI in four rungs (measure, dry run, staff, reply); P5 the voice-note\n"
                      "#            acknowledgement; P6 offers on the Evolution numbers (a test, then live); P7 the agent copied on the\n"
                      "#            quotation e-mail. One send ledger per handset (wa_auto_sends) holds every proactive WhatsApp the plugin\n"
                      "#            sends once its key is on — its record-only hooks in NotificationService, AlertService and\n"
                      "#            StarlinkMailWorker write nothing until then — and content-free \"seen\" marks (wa_seen_marks) say\n"
                      "#            whether a person already wrote. Migrations 089, 090 and 091 add the tables and columns, each verifying\n"
                      "#            itself into _schema_checks (its CHECK the verdict, never the log line); master registers four jobs —\n"
                      "#            quote_chase, quote_daily_list, ai_media and offers_send — last, each dispatched only on Uganda and only\n"
                      "#            with its switch on. With every switch off, as the deploy leaves it, 5.18.104 sends, writes and shows\n"
                      "#            nothing 5.18.103 does not, but for the three migrations' empty tables and columns. South Sudan: never\n"
                      "#            dispatched or listed, every hook and pass behind its key, the tool and the report refusing (\"not this\n"
                      "#            install\"), 089 applied there too — 5.18.103's behaviour (root docs/73 §H.2.17). The uCRM catalogue\n"
                      "#            (5.18.103), the quoting organization, ECO Bank and the Cashbook as they are. Files, against 221c9dc:\n"
                      f"#            {nc} changed, {na} added — {nt} (the sales follow-up's classes, jobs, tool, screens, migrations and tests\n"
                      "#            are the added ones).", why="what 5.18.104 does")
    doc.must(f"#            {nc} changed, {na} added — {nt} (the sales follow-up's", 1)

    # ── the cash sheet's folder ──
    doc.sub("#            /root/dnb-5.18.103/cash-<mode>-<UTC stamp>.txt, mode 0600,", "#            /root/dnb-5.18.104/cash-<mode>-<UTC stamp>.txt, mode 0600,", 1, why="sheet folder")

    # ── 5.18.104's own checks, then 5.18.103's carried ──
    doc.sub("#   5.18.103'S OWN CHECKS, their ids new so that none reads as an earlier release's (QC, \"quote catalogue\"), counts and\n",
            "#   5.18.104'S OWN CHECKS, Part F's (QF, \"follow-up\"; root docs/73 §H.2.15): counts, fixed codes and key names only in\n"
            "#            the log, the report's owners on the sheet. Refusals before the copy, each on a copy of the book through the\n"
            "#            PIN's code from Git: QF-G1 the copy's shape — 088 last and none of 089–091 (a first deploy), or all three\n"
            "#            applied (a redeploy after its rollback); anything else NO-GO; QF-G2 the one migration trial for every part\n"
            "#            (ruling R10) — 089 → 090 → 091 through the pin's MigrationRunner, each _schema_checks row at its count (on a\n"
            "#            first deploy each applied and its migration.log line OK; on a redeploy none pending, the rows already there\n"
            "#            the verdict and the live log's lines a note), the pin's doctor PASS for all three, and Part V's negative\n"
            "#            control (an 085-shaped wa_media: PARTIAL, no row); QF-G3 the pin's record-only hooks, each counted in its\n"
            "#            method (NotificationService's in sendVia, sendDocument and sendImage), W1–W3 and NOTIFY_KINDS' events, as\n"
            "#            text, and HK7, the pin's send-site inventory, by its own scan and class table on its own tree;\n"
            "#            QF-G4 the one rule for every part's keys (ruling R7) — every key of SalesFollowupLadder::allKeys() unset in\n"
            "#            the live files and the store row: a key set is NO-GO unless, on a redeploy, --clear-switches is given; a key\n"
            "#            held by data/config.json or <data>/config.json is NO-GO always. Notes: QF-N1–QF-N7 (the stored values and\n"
            "#            dn_tz(), the enquiry follow-ups, the pin's report as the baseline, the Message Log's sends per handset and\n"
            "#            Kampala day against the daily cap,\n"
            "#            the master log against reminder_hour, the PHP log, the daily list's size). After the copy, on fresh copies\n"
            "#            through the INSTALLED code: QF-CA1 089–091 applied at the first boot; QF-CA2 and QF-CA3 what the dark build\n"
            "#            could have written — no ledger row and no run of the four jobs after the copy (judged in the deploy run; on\n"
            "#            a redeploy against the baseline taken just before the copy; the schedule read, with other jobs' entries in\n"
            "#            it, the control); QF-CA3 the four jobs registered and listed OFF (switch); QF-CA4 the F1 filters, with an\n"
            "#            enquiry row returned as the control; QF-CA5 the tool's show — all thirteen steps off, the ledger off — and\n"
            "#            \"not this install\" as South Sudan; QF-CA6 the gates off, and as South Sudan, beside the control (every key\n"
            "#            on Uganda: true, one mark); QF-CA7 a note; QF-CA8 the recorders with their controls — each reading of a\n"
            "#            copy with the copy's own vault. In an --after-only run, QF-ON0–QF-ON5 through the installed tool's --check\n"
            "#            on a copy (L-ON through the installed ladder where `skip ack` left the ledger on and step 2 off; QF-ON3's\n"
            "#            spacing as Q3-ON judges it), each judging only a step that is on. After ROLLBACK is typed, before the\n"
            "#            put-back: QF-RB1; then QF-RB2 (a note)\n"
            "#            and, after the put-back, QF-RB3 (the rollback paragraph below).\n"
            "#\n"
            "#   5.18.103'S CHECKS, CARRIED (QC, \"quote catalogue\"), their ids kept so that none reads as another release's, counts and\n",
            1, why="QF paragraph; QC carried")
    doc.must("#   5.18.104'S OWN CHECKS, Part F's (QF,", 1)
    doc.sub("#            id 0 in the cart. After a rollback: QC-RB1 nothing in 5.18.102 names UcrmCatalogue or ucrm_catalogue — every\n"
            "#            file 5.18.102 tracks swept, each file 5.18.103 changes read beside its control on the pin — and the table\n"
            "#            ucrm_catalogue left on the book, counted;",
            "#            id 0 in the cart. After a rollback: QC-RB1, re-pointed to 5.18.104 (5.18.103's catalogue is installed code\n"
            "#            again, read by it): nothing in 5.18.103 names a word 5.18.104 adds — its classes, jobs, screens, tools and\n"
            "#            089–091's tables; every file 5.18.103 tracks swept, each file 5.18.104 changes read beside its control on\n"
            "#            the pin — and the table ucrm_catalogue counted, 5.18.103's own again;",
            1, why="the QC paragraph's QC-RB1, re-pointed")

    # ── the quarantine paragraph: this script's and the earlier releases' state files ──
    doc.sub("#   AFTER THE QUARANTINE, NEVER BEFORE. As 5.18.102: stage A refuses", "#   AFTER THE QUARANTINE, NEVER BEFORE. As 5.18.103: stage A refuses", 1, why="quarantine: as 5.18.103")
    doc.lines_between("#            any run of that script holds its folder's lock; while a failure recorded by this script's runs, 5.18.102's,",
                      "#            latest copy — a deploy of 5.18.103 (COPY_AT) or a rollback of it (RB_COPY_AT, in its rollback-state-*.env), so",
                      "#            any run of that script holds its folder's lock; while a failure recorded by this script's runs, 5.18.103's,\n"
                      "#            5.18.102's, 5.18.101's, 5.18.100's, 5.18.99's, 5.18.98's or 5.18.97's (STRAY_Q_FAILED in\n"
                      "#            /root/dnb-5.18.104/state-5.18.104.env*, /root/dnb-5.18.103/state-5.18.103.env*,\n"
                      "#            /root/dnb-5.18.102/state-5.18.102.env*, /root/dnb-5.18.101/state-5.18.101.env*,\n"
                      "#            /root/dnb-5.18.100/state-5.18.100.env*, /root/dnb-5.18.99/state-5.18.99.env*, /root/dnb-5.18.98/state-5.18.98.env*\n"
                      "#            or /root/dnb-5.18.97/state-5.18.97.env*) is newer than the newest passing --check; and unless that --check began\n"
                      "#            after 5.18.103's own copy (COPY_AT in its state files: the quarantine's rule 3) and after this script's own\n"
                      "#            latest copy — a deploy of 5.18.104 (COPY_AT) or a rollback of it (RB_COPY_AT, in its rollback-state-*.env), so",
                      why="quarantine: the state files")
    doc.must("#            /root/dnb-5.18.104/state-5.18.104.env*, /root/dnb-5.18.103/state-5.18.103.env*,", 1)

    # ── the pin, the live release, before the deploy, the commands, the rollback ──
    doc.lines_between("#   THE COMMIT IT INSTALLS IS A RELEASE COMMIT, NOT THE BRANCH TIP.",
                      "# 5.18.103 adds stay on disk, reached by nothing (QC-RB1). The by-hand put-back counts nothing.",
                      "#   THE COMMIT IT INSTALLS IS A RELEASE COMMIT, NOT THE BRANCH TIP. The pin below, on release/5.18.104, is cut on 221c9dc\n"
                      f"#            (5.18.103, live): {nt} files — {nc} changed, {na} added — each file's sha256 pinned here. Stage A refuses a pin\n"
                      "#            whose parent is not 5.18.103, whose short hash names another commit, whose delta is not exactly these files by\n"
                      "#            these hashes, whose migrations are not exactly 089, 090 and 091 by their sha256 and md5, and a checkout holding\n"
                      "#            a file git does not track under the plugin folder outside data/. Its master.php and main.php are checked byte\n"
                      "#            for byte (A0).\n"
                      "#\n"
                      "# 5.18.103 must be the live plugin: this script refuses any other live commit or version — deploy 5.18.103 first.\n"
                      "#\n"
                      "# Before the deploy (root docs/73 §H.2.15): 5.18.103's deployment is recorded in root docs/07 — its copy time and\n"
                      "# counts, its --after-only, and the quarantine's --check begun after that copy, whose record A14 reads; the agents and\n"
                      "# every admin are told that nothing changes for them at the deploy — every switch of the sales follow-up is off, and\n"
                      "# 5.18.104 sends, writes and shows nothing 5.18.103 does not — and that no one turns a step on until the deploy has\n"
                      "# printed its last line. A --check first is wise: QF-G1–QF-G4 refuse or pass and QF-N1–QF-N7 note, nothing changed.\n"
                      "# On a redeploy after a rollback that left keys set, QF-G4 refuses; --clear-switches clears them with the pin's tool,\n"
                      "# once DEPLOY is typed (Options).\n"
                      "#\n"
                      "# The deploy. Run as root on the server, then send back THE LOG FILE (never a copy of the terminal) — only after the\n"
                      "# quarantine's --check has PASSED after 5.18.103's deploy; not between 14:55 and 15:10 UTC (the evening WhatsApp, 18:00\n"
                      "# Kampala):\n"
                      "#\n"
                      "#   cd /opt/dishnet && git pull origin claude/study-this-jhe2eg && git fetch origin release/5.18.104 && mkdir -p /root/dnb-5.18.104 && bash scripts/deploy-5.18.104.sh 2>&1 | ( umask 077; exec tee /root/dnb-5.18.104/deploy-$(date -u +%Y%m%dT%H%M%SZ).log )\n"
                      "#\n"
                      "# Then, at least 20 minutes after the copy (the summary prints the time), and once more the next day after 15:00 UTC —\n"
                      "# its own command, with its own log, read-only, nothing deployed (QF-CA1–QF-CA8; QF-ON0–QF-ON5 for each step that is\n"
                      "# on); then the quarantine's own --check, its own (the summary prints it):\n"
                      "#\n"
                      "#   cd /opt/dishnet && bash scripts/deploy-5.18.104.sh --after-only 2>&1 | ( umask 077; exec tee /root/dnb-5.18.104/after-$(date -u +%Y%m%dT%H%M%SZ).log )\n"
                      "#\n"
                      "# Then the steps, yours alone, one at a time and only in the ladder's order: the tool refuses a step whose steps before\n"
                      "# it are not on (or skipped) or whose checks do not allow it, and saves nothing. The first is the report, read-only on\n"
                      "# a copy of the book (P1), its own command, its log in /root/dnb-report:\n"
                      "#\n"
                      "#   cd /opt/dishnet && git pull origin claude/study-this-jhe2eg && mkdir -p /root/dnb-report && bash scripts/sales-followup-report.sh 2>&1 | ( umask 077; exec tee /root/dnb-report/report-$(date -u +%Y%m%dT%H%M%SZ).log )\n"
                      "#\n"
                      "# and then each step through the switch tool's wrapper (show, --check <step>, on <step>), each its own command, its log\n"
                      "# in /root/dnb-sales — for example, where the ladder stands, read-only:\n"
                      "#\n"
                      "#   cd /opt/dishnet && bash scripts/sales-followup.sh show\n"
                      "#\n"
                      "# The rollback is a separate command, printed at the end of the deploy's log, after the verdict. It is never pasted\n"
                      "# together with the ones above: pasted together, the shell runs both (root docs/44 §16.9). Once ROLLBACK is typed it\n"
                      "# first runs QF-RB1 — scripts/sales-followup.sh off all --rollback under 5.18.104's installed code: every part's switches\n"
                      "# and hooks in the one sequence, O (12, 11) → F (10, 6) → V (9, 8, 7, 5) → 4, 3 → 2 → the record keys and Part P's two,\n"
                      "# each end state asserted and every key read back — and STOPS before the put-back unless every part reads clean,\n"
                      "# printing a by-hand put-back of open rows and key names, its own block. Then 5.18.103 is put back, and QF-RB3 reads\n"
                      "# 5.18.103's files where 5.18.104 changed them, master with none of the four jobs, no 5.18.104 key and no open quote\n"
                      "# follow-up (and VN-RB6's instruction to rotate the OpenAI key whenever OpenAI was called). 089's, 090's and 091's\n"
                      "# tables, columns, ledger and marks stay, read by nothing under 5.18.103 (QF-RB2); a later deploy of 5.18.104 goes\n"
                      "# with --redeploy and finds every switch off. ECO Bank, every statement kept, its switch, the quoting organization,\n"
                      "# the uCRM catalogue, the channel registry and every number's owner stay exactly as they are. Quotes already made in\n"
                      f"# uCRM are not changed, and the {na} files 5.18.104 adds stay on disk, reached by nothing.",
                      why="the pin, the commands, the rollback")
    doc.must("bash scripts/deploy-5.18.104.sh 2>&1 | ( umask 077; exec tee /root/dnb-5.18.104/deploy-", 1)
    doc.must("bash scripts/deploy-5.18.104.sh --after-only 2>&1 | ( umask 077; exec tee /root/dnb-5.18.104/after-", 1)
    doc.mustnot("bash scripts/deploy-5.18.103.sh 2>&1")
    # no command line of the header names the rollback: it is printed at the end of the log, alone
    for l in doc.t.split("\n")[:600]:
        if l.startswith("#   cd ") and "--rollback" in l:
            raise SystemExit("HEADER: a command line names --rollback")

    # ── options ──
    doc.sub("#   --after-only         the deploy already happened: run the checks again — R23, C1–C5 (CA2), BK1, BK2 and G3 on a new\n",
            "#   --after-only         the deploy already happened: run the checks again — QF-CA1–QF-CA8 and QF-ON0–QF-ON5 (each step\n"
            "#                        that is on, through the installed tool's --check on a copy; a step that is off is not judged),\n"
            "#                        R23, C1–C5 (CA2), BK1, BK2 and G3 on a new\n", 1, why="--after-only names QF")
    doc.lines_between("#   --redeploy           deploy 5.18.103 again after its own rollback:",
                      "#                        switch in capitals if it was left on)",
                      "#   --redeploy           deploy 5.18.104 again after its own rollback: the files it adds, which the rollback leaves on\n"
                      "#                        disk, are expected there (BR1 counts them, and the copy replaces them), and so are 089's, 090's\n"
                      "#                        and 091's tables, columns, ledger and marks (QF-G1 reads all three applied; QF-RB2); every\n"
                      "#                        5.18.104 key must read unset (QF-G4) — or, with --clear-switches, is cleared after DEPLOY is\n"
                      "#                        typed — and QF-CA2 and QF-CA3 judge the dark build against the baseline taken just before the\n"
                      "#                        copy. The uCRM catalogue's settings are read as 5.18.103 left them (QC-G1, QC-N1)",
                      why="--redeploy")
    doc.must("#   --redeploy           deploy 5.18.104 again after its own rollback:", 1)
    doc.insert_before("#   --check-bank         read-only, after the deploy:",
                      "#   --clear-switches     with a deploy (a redeploy) or --check, the operator's explicit choice, printed as such (root\n"
                      "#                        docs/73 §H.2.15.1, QF-G4): 5.18.104's switch and record keys found set — left by an earlier\n"
                      "#                        5.18.104 rolled back without its off — are cleared once DEPLOY is typed and before the copy, by\n"
                      "#                        the pin's tools/sales_followup.php off all --rollback as the book's owner, every part's hooks;\n"
                      "#                        the keys it cleared go on the sheet, and QF-G4 then reads every key unset, or nothing is copied.\n"
                      "#                        Refused on a book 5.18.104 never ran on (its keys were set by hand) and for a key held by\n"
                      "#                        data/config.json or <data>/config.json, which the tool never writes; with --check it clears\n"
                      "#                        nothing and says what the deploy would clear; with a deploy that finds 5.18.104 already\n"
                      "#                        serving, it is cleared and said\n", 1, why="--clear-switches")
    doc.lines_between("#   --rollback           put 5.18.102 back (typed ROLLBACK), then check it",
                      "#                        nothing",
                      "#   --rollback           typed ROLLBACK: first QF-RB1 — the installed scripts/sales-followup.sh off all --rollback, the\n"
                      "#                        one sequence of every part's switches and hooks, each end state and every key read back; any\n"
                      "#                        failure STOPS before the put-back, puts nothing back, and prints the by-hand put-back (open rows\n"
                      "#                        and key names, its own block) — then 5.18.103 put back and checked: QF-RB3 (the files 5.18.104\n"
                      "#                        changed, 5.18.103's by sha256; master without the four jobs; no 5.18.104 key; no open quote\n"
                      "#                        follow-up; VN-RB6's key rotation where OpenAI was ever called), 089–091 left in place and read\n"
                      "#                        by nothing (QF-RB2), 5.18.103's files by sha256, its sign-off line, owner rule and quoting code\n"
                      "#                        in place, the files 5.18.104 adds left on disk, the Cashbook's list and ECO Bank exactly as they\n"
                      "#                        were (its files, its switch, 088 and every kept row, both cards on a copy), D2 = A on a copy of\n"
                      "#                        the book (its own cash sheet), 5.18.95's security fixes still in place, the documents, the\n"
                      "#                        database, and the numbers a technician owns counted; its record goes to a file of its own. It\n"
                      "#                        refuses a copy that stopped part-way, and prints the by-hand put-back of 5.18.103's code",
                      why="--rollback")
    doc.must("#   --rollback           typed ROLLBACK: first QF-RB1", 1)

    # ── three paths in the carried prose that name this script's own folder or release ──
    doc.sub("#            them (Q-N4 extended by QL-N3). After the copy: Q-CA1 the installed files are release/5.18.103's by the sha256\n",
            "#            them (Q-N4 extended by QL-N3). After the copy: Q-CA1 the installed files are release/5.18.104's by the sha256\n",
            1, why="Q-CA1's release")
    doc.sub("The writes are the documented deploy (or rollback), a backup under /root/dnb-5.18.103/,",
            "The writes are the documented deploy (or rollback), a backup under /root/dnb-5.18.104/,", 1, why="backup folder")
    doc.sub("# The run's cash sheet, on the host: /root/dnb-5.18.103/cash-<mode>-<stamp>.txt,",
            "# The run's cash sheet, on the host: /root/dnb-5.18.104/cash-<mode>-<stamp>.txt,", 1, why="sheet folder (comment)")
    doc.mustnot("/root/dnb-5.18.103/cash-")
    doc.mustnot("a backup under /root/dnb-5.18.103/")

    # ── the deploy's opening line for admins ──
    doc.sub("until this run prints its last line (docs/72 §G.13): then turn the uCRM catalogue on, as its summary's steps say\"; fi",
            "until this run prints its last line (docs/72 §G.13), and turn on no step of the sales follow-up (scripts/sales-followup.sh, root docs/73 §H.2.15) until then: then follow its summary's steps\"; fi",
            1, why="admins' opening line")

    # ── the summary: the report's command, beside the others ──
    doc.insert_after('CHECKBANK_CMD="cd $REPO && bash scripts/deploy-$EXPECTED_VERSION.sh --check-bank $(LOGW check-bank)"\n',
                     'QF_REPORT_CMD="cd $REPO && mkdir -p /root/dnb-report && bash scripts/sales-followup-report.sh 2>&1 | ( umask 077; exec tee /root/dnb-report/report-\\$(date -u +%Y%m%dT%H%M%SZ).log )"   # 5.18.104 (root docs/73 §H.2.21 9.5): P1, read-only on a copy\n',
                     1, why="QF_REPORT_CMD")
    # the names
    doc.sub('echo "  names             QC-G1, QC-N1–QC-N7, QC-CA1–QC-CA3, QC-ON1–QC-ON4, QC-RB1 and QC-RB2 are 5.18.103\'s own checks"\n'
            'echo "                    (QC, \\"quote catalogue\\"); QL-G1,',
            'echo "  names             QF-G1–QF-G4, QF-N1–QF-N7, QF-CA1–QF-CA8, QF-ON0–QF-ON5 and QF-RB1–QF-RB3 are 5.18.104\'s own"\n'
            'echo "                    checks (QF, Part F\'s; root docs/73 §H.2.15); QC-G1, QC-N1–QC-N7, QC-CA1–QC-CA3, QC-ON1–QC-ON4,"\n'
            'echo "                    QC-RB1 and QC-RB2 are 5.18.103\'s (QC, \\"quote catalogue\\"), carried; QL-G1,',
            1, why="names")
    # what changed
    doc.between('  if [ -n "$F_QORG" ]; then\n    echo "  what changed      on this Uganda server (Q-G2)',
                '(R7, R11, TN-N1)"\n  fi\n',
                '  echo "  what changed      on this Uganda server: $EXPECTED_VERSION\'s sales follow-up — P1 the report, P2 quote tracking and the day 1/3/7 chase, P3 the morning list and My quotes, P4 voice notes through OpenAI in four rungs, P5 the voice-note acknowledgement, P6 offers on the Evolution numbers, P7 the agent\'s Cc on the quotation e-mail — installed with every switch OFF$( [ "$MODE" = "deploy" ] && echo " (QF-CA5: all 13 steps off and the ledger off after the copy)" || echo " at the deploy; the steps the operator has turned on since are judged above (QF-ON0–QF-ON5)"); each is turned on only with scripts/sales-followup.sh, one step at a time in the ladder\'s order (your steps, below). Migrations 089, 090 and 091 applied at the first boot, each verifying itself (QF-CA1); master registers quote_chase, quote_daily_list, ai_media and offers_send and dispatches none while their switches are off (QF-CA3). With every switch off $EXPECTED_VERSION sends, writes and shows nothing $BASELINE_VERSION does not. The uCRM catalogue (5.18.103) as it was: its switch $F_SWW.$F_QLNS"\n',
                keep_ends=False, why="what changed")
    doc.must('  echo "  what changed      on this Uganda server: $EXPECTED_VERSION\'s sales follow-up', 1)
    doc.mustnot('echo "  what changed      on this Uganda server (Q-G2)')
    # what did not
    doc.sub("the quotation summary's sign-off, $BASELINE_VERSION's byte for byte (QS-G1, QS-CA1); webhook.php, QuoteOrganization.php,",
            "the quotation summary's sign-off, $BASELINE_VERSION's line as text (QS-G1, QS-CA1; webhook.php gains only P7's copy, behind its switch); QuoteOrganization.php,",
            1, why="what did not: webhook.php changes")
    doc.sub("the database — no migration added and nothing written by this run (R7, BA1)",
            "the database — nothing written by this run but 089, 090 and 091 at the first boot (QF-CA1), and on a redeploy with --clear-switches the keys QF-G4 cleared, by name on the sheet (R7, BA1)",
            1, why="what did not: the database")
    doc.sub('  echo "  migrations        none added (A0, R7): the ledger the same before and after;',
            '  echo "  migrations        089, 090 and 091 added (A0, QF-G2, QF-CA1, R7): each applied once at the first boot, each verifying itself into _schema_checks, the ledger otherwise the same before and after;',
            1, why="migrations")
    # check it
    doc.sub('$( [ -n "$F_QORG" ] && echo "once an admin has turned the uCRM catalogue on (your steps, 3, below) — an --after-only before that fails QC-ON1 by design — and ")at least 20 minutes after the copy',
            '$( [ -n "$F_QORG" ] && echo "with 5.18.103\'s uCRM catalogue on (an --after-only while it is off fails QC-ON1 by design, as under 5.18.103), ")at least 20 minutes after the copy',
            1, why="check it")
    # the screens and your steps
    doc.between('  if [ -n "$F_QORG" ]; then\n    [ "$F_QST" != "stageA" ] || echo "  quoting org.',
                '    echo "                    is on (QC-ON1–QC-ON4); with it 0/off, nothing"\n  fi\n',
                '  echo "  the screens       none changes now: every switch of $EXPECTED_VERSION\'s sales follow-up is off, so every screen"\n'
                '  echo "                    is as under $BASELINE_VERSION — the uCRM catalogue as it was, the Salesperson numbers card,"\n'
                '  echo "                    the Cashbook and ECO Bank as before; the staff page My quotes and the offers screen open"\n'
                '  echo "                    only once their steps are on. No screen of uCRM\'s changes. Do NOT make a test quote for a"\n'
                '  echo "                    real customer: the plugin WhatsApps every new quote"\n'
                '  echo\n'
                '  echo "  your steps        yours alone, one at a time and only in the ladder\'s order (root docs/73 §H.2.3, C6) — this"\n'
                '  echo "                    script does none of them. First the report, read-only on a copy of the book (P1), its own"\n'
                '  echo "                    command, alone, its log in /root/dnb-report:"\n'
                '  echo "      $QF_REPORT_CMD"\n'
                '  echo\n'
                '  echo "                    then, for each step in turn — report, agent-cc (optional), ack, tracking, list,"\n'
                '  echo "                    voice-measure, chase, voice-dry-run, voice-staff, voice-reply, cold (optional), offers-test,"\n'
                '  echo "                    offers-live — on, then that step\'s check, each its own command, alone, its log in"\n'
                '  echo "                    /root/dnb-sales. A step that is off is not judged: its --check says OFF — not judged, so"\n'
                '  echo "                    a step is switched on first and checked after. Its on judges the step before it and"\n'
                '  echo "                    refuses, saving nothing, while that one reads FAIL or out of order; on chase and on cold"\n'
                '  echo "                    ask at the terminal. Where the ladder stands, read-only:"\n'
                '  echo "      cd $REPO && bash $QF_WRAPPER show"\n'
                '  echo\n'
                '  echo "                    then the first step, once you have read the report: on report takes --ran and the day you"\n'
                '  echo "                    ran it, as a date (year-month-day, Kampala; never a later day). The one below carries"\n'
                '  echo "                    today\'s, the day of this deploy — if you ran the report on another day, put that day in"\n'
                '  echo "                    its place:"\n'
                '  echo "      cd $REPO && bash $QF_WRAPPER on report --ran $(TZ=Africa/Kampala date +%Y-%m-%d)"\n'
                '  echo\n'
                '  echo "                    then its check, read-only (PASS once the report is recorded as run), its own command:"\n'
                '  echo "      cd $REPO && bash $QF_WRAPPER --check report"\n'
                '  echo\n'
                '  echo "                    After each step, --after-only (check it, above): QF-ON0–QF-ON5 judge each step that is on."\n'
                '  echo "                    Until a step is on, $EXPECTED_VERSION behaves as $BASELINE_VERSION."\n',
                keep_ends=False, why="the screens and your steps")
    doc.must('  echo "      cd $REPO && bash $QF_WRAPPER on report --ran $(TZ=Africa/Kampala date +%Y-%m-%d)"\n', 1)
    doc.mustnot('  echo "      cd $REPO && bash $QF_WRAPPER on report"\n', why="on report never without --ran: the tool refuses it (option_missing)")
    if doc.t.index('bash $QF_WRAPPER on report --ran') > doc.t.index('  echo "      cd $REPO && bash $QF_WRAPPER --check report"\n'):
        raise SystemExit("the summary prints --check report before on report: a step that is off reads OFF — not judged")
    doc.mustnot('your steps, 3, below')
    # the rollback block, printed last
    doc.sub('  echo "  One step: $BASELINE_VERSION ($BASELINE_COMMIT) back — it asks you to type ROLLBACK before it changes anything,"\n',
            '  echo "  $BASELINE_VERSION ($BASELINE_COMMIT) back — it asks you to type ROLLBACK before it changes anything,"\n',
            1, why="the rollback is no longer one step")
    doc.lines_between('  echo "  On Uganda, with the quoting organization in force, the uCRM catalogue screen then goes, the Plans and Hardware"',
                      '  echo "  are not changed, and the $N_ADDED files $EXPECTED_VERSION adds stay on disk, reached by nothing."',
                      '  echo "  but first, under $EXPECTED_VERSION\'s installed code, turns every switch of the sales follow-up off in the one"\n'
                      '  echo "  sequence (QF-RB1: scripts/sales-followup.sh off all --rollback, every part\'s hooks and end states); if any"\n'
                      '  echo "  part does not read clean it stops there, puts nothing back, and prints the by-hand put-back, its own block."\n'
                      '  echo "  Then $BASELINE_VERSION is put back and checked (QF-RB3). 089\'s, 090\'s and 091\'s tables, columns, ledger and"\n'
                      '  echo "  marks stay, read by nothing under $BASELINE_VERSION (QF-RB2); a later deploy of $EXPECTED_VERSION goes with"\n'
                      '  echo "  --redeploy and finds every switch off. Open quote follow-ups are closed, never resumed; running offers are"\n'
                      '  echo "  paused, resumed only by an admin. The uCRM catalogue stays as it is. Quotes already made in uCRM are not"\n'
                      '  echo "  changed, and the $N_ADDED files $EXPECTED_VERSION adds stay on disk, reached by nothing."',
                      why="the rollback block")
    doc.sub('  echo "  Only if that script cannot run — the same put-back by hand, its own command, alone (never with the one above). It"\n'
            '  echo "  counts nothing: the web-direct applications are then left unquoted, as above, and not counted:"\n'
            '  echo "      $PUTBACK_CMD"\n',
            '  echo "  Only if that script cannot run — the same put-back by hand, its own command, alone (never with the one above). It"\n'
            '  echo "  runs no QF-RB1, so first every switch off with the installed tool, its own command, alone:"\n'
            '  echo "      cd $REPO && bash $QF_WRAPPER off all --rollback"\n'
            '  echo "  and only once that has exited 0, the put-back, its own command (it counts nothing):"\n'
            '  echo "      $PUTBACK_CMD"\n',
            1, why="the by-hand put-back after the tool's off")
    doc.must('  echo "  but first, under $EXPECTED_VERSION\'s installed code', 1)
