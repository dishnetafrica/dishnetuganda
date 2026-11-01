# Part H2 — the header's carried paragraphs (QS, TN), version shifts. The baseline is 5.18.102; the release 5.18.103.


def apply(doc, ctx):
    na, nc, nk, nt = ctx["n_added"], ctx["n_changed"], ctx["n_kept"], ctx["n_added"] + ctx["n_changed"]
    doc.subs([
        ("its own, the old phrase anywhere, and the line that sets the gate ($ugPlans from whQuoteIsUganda()). 5.18.102\n"
         "#            keeps webhook.php byte for byte (docs/71 §L.4), so every reading is 5.18.101's line. Refusal before the copy:",
         "its own, the old phrase anywhere, and the line that sets the gate ($ugPlans from whQuoteIsUganda()). 5.18.102\n"
         "#            and 5.18.103 keep webhook.php byte for byte (docs/71 §L.4, docs/72 §G.12), so every reading is 5.18.101's\n"
         "#            line. Refusal before the copy:"),
        ("(TenantProfile::current()->id()). By the sha256 pinned here, Q-CA1 reads every file 5.18.102 changes or adds\n"
         "#            (its 69, as the pin has them); Q-G1 before the copy, and RB after a rollback, read every file it changes\n"
         "#            (38), as 5.18.101 has them; all three read the 55 files of 5.18.101 it must not touch — webhook.php,\n"
         "#            cron_quote_wa.php, lib/QuoteOrganization.php, lib/QuoteTaxLine.php, lib/TenantProfile.php and\n"
         "#            lib/PublicPriceFeed.php among them. The 31 files it adds have no 5.18.101 form: BR1 counts any left on disk,\n"
         "#            and QL-RB1 shows that nothing in 5.18.101 reaches them; QL-RB3, that nothing in it names the uCRM link store.",
         "(TenantProfile::current()->id()). By the sha256 pinned here, Q-CA1 reads every file 5.18.103 changes or adds\n"
         f"#            (its {nt}, as the pin has them); Q-G1 before the copy, and RB after a rollback, read every file it changes\n"
         f"#            ({nc}), as 5.18.102 has them; all three read the {nk} files of 5.18.102 it must not touch — webhook.php,\n"
         "#            cron_quote_wa.php, lib/QuoteOrganization.php, lib/QuoteTaxLine.php, lib/TenantProfile.php,\n"
         "#            lib/PublicPriceFeed.php and lib/UcrmQuoteLinks.php among them. The "
         f"{na} files it adds have no 5.18.102\n"
         "#            form: BR1 counts any left on disk, and QC-RB1 shows that nothing in 5.18.102 reaches them."),
        ("#   5.18.100'S CHECKS, CARRIED, AGAINST 5.18.101'S CODE (TN, \"technician number\"). Refusals before the copy: TN-G1 the",
         "#   5.18.100'S CHECKS, CARRIED, AGAINST 5.18.102'S CODE (TN, \"technician number\"). Refusals before the copy: TN-G1 the"),
        ("#            5.18.100's rule, which 5.18.101 and 5.18.102 do not change: the technician roles' line once, exactly support,",
         "#            5.18.100's rule, which 5.18.101 through 5.18.103 do not change: the technician roles' line once, exactly support,"),
        ("#            run. After a rollback (RB): the owner rule 5.18.100's, as 5.18.101 installs it, as text and on a copy, and the\n"
         "#            numbers a technician owns counted — they keep their owner and work under 5.18.101 as before.",
         "#            run. After a rollback (RB): the owner rule 5.18.100's, as 5.18.102 installs it, as text and on a copy, and the\n"
         "#            numbers a technician owns counted — they keep their owner and work under 5.18.102 as before."),
    ], why="H2 QS TN")
    # 5.18.99's
    doc.subs([
        ("#   5.18.99'S CHECKS, CARRIED, AGAINST 5.18.101'S CODE. Its quoting checks keep their ids (SPEC-quote-company §6): Q-G1 the\n"
         "#            live plugin is 5.18.101 — the version, every file 5.18.102 changes by f2055ba's sha256 (pinned here), and the\n"
         "#            5.18.101 files 5.18.102 must not touch by theirs,",
         "#   5.18.99'S CHECKS, CARRIED, AGAINST 5.18.102'S CODE. Its quoting checks keep their ids (SPEC-quote-company §6): Q-G1 the\n"
         "#            live plugin is 5.18.102 — the version, every file 5.18.103 changes by 1a0ea5f's sha256 (pinned here), and the\n"
         "#            5.18.102 files 5.18.103 must not touch by theirs,"),
        ("#            disagree (it is also 5.18.102's gate, D7); Q-G4 uCRM's organizations,",
         "#            disagree (it is also 5.18.102's and 5.18.103's gate, D7); Q-G4 uCRM's organizations,"),
        ("#            them (Q-N4 extended by QL-N3). After the copy: Q-CA1 the installed files are release/5.18.102's by the sha256\n"
         "#            pinned here, the 5.18.101 files it must not touch 5.18.101's, and the server's PHP accepts every PHP file it",
         "#            them (Q-N4 extended by QL-N3). After the copy: Q-CA1 the installed files are release/5.18.103's by the sha256\n"
         "#            pinned here, the 5.18.102 files it must not touch 5.18.102's, and the server's PHP accepts every PHP file it"),
        ("#            pages refuse a visitor with no session (5.18.102 changes three of them). Q-CA6 the installed profile,",
         "#            pages refuse a visitor with no session (5.18.103 changes three of them). Q-CA6 the installed profile,"),
        ("#            QuoteOrganization.php is kept byte for byte — while 5.18.102 changes the quoting code around it; with the\n"
         "#            setting off, Uganda quotes as 5.18.101 does.",
         "#            QuoteOrganization.php is kept byte for byte — while 5.18.103 changes the quoting code around it; with the\n"
         "#            setting off, Uganda quotes as 5.18.101 did, as under 5.18.102."),
    ], why="H2 Q")
    # 5.18.98's, ECO Bank, the sandbox tests, P0
    doc.subs([
        ("#   5.18.98'S CHECKS, CARRIED, AGAINST 5.18.101'S CODE. Its copy checks C1–C5, on ONE VACUUM INTO copy, 5.18.101's code and\n"
         "#            5.18.102's from Git (A23), then 5.18.101's from Git and the installed (CA2):",
         "#   5.18.98'S CHECKS, CARRIED, AGAINST 5.18.102'S CODE. Its copy checks C1–C5, on ONE VACUUM INTO copy, 5.18.102's code and\n"
         "#            5.18.103's from Git (A23), then 5.18.102's from Git and the installed (CA2):"),
        ("#            nothing there; what 5.18.102 writes at use is its own keys on Uganda applications (R7).",
         "#            nothing there; what 5.18.103 writes at use is its own keys on Uganda applications (R7)."),
        ("#            5.18.101 (f2055ba), every file it tracks byte for byte;", "#            5.18.102 (1a0ea5f), every file it tracks byte for byte;"),
        ("BK1 cash in hand under the installed 5.18.101\n#            and under the pin,", "BK1 cash in hand under the installed 5.18.102\n#            and under the pin,"),
        ("#            CATALOGUE CHECKS' GETS TO UCRM. As 5.18.101: T1 (in process, synthetic books)",
         "#            CATALOGUE CHECKS' GETS TO UCRM. As 5.18.102: T1 (in process, synthetic books)"),
        ("#            (A24, R26), now 5.18.101's code against 5.18.102's, both with the gate;",
         "#            (A24, R26), now 5.18.102's code against 5.18.103's, both with the gate;"),
        ("#            R23a–R23g; after a rollback, RB). 5.18.102's own test (tests/test_quote_from_ucrm.php and\n"
         "#            tests/quote_from_ucrm/ — its sections, their weakened copies and the form's browser check — with its fake\n"
         "#            uCRM, tests/fixtures/fake_ucrm_kyc.php) serves a fake uCRM on an HTTP server of its own and runs in the suites\n"
         "#            only; so do 5.18.101's, 5.18.100's and 5.18.99's.",
         "#            R23a–R23g; after a rollback, RB). 5.18.103's own test (tests/test_catalogue_direct.php and\n"
         "#            tests/catalogue_direct/ — its sections, their weakened copies and the two browser checks — on 5.18.102's fake\n"
         "#            uCRM, tests/fixtures/fake_ucrm_kyc.php) serves a fake uCRM on an HTTP server of its own and runs in the suites\n"
         "#            only; so do 5.18.102's, 5.18.101's, 5.18.100's and 5.18.99's."),
        ("#            own configuration, as 5.18.101's code and 5.18.102's both read it, or nothing is deployed (A23, G2).",
         "#            own configuration, as 5.18.102's code and 5.18.103's both read it, or nothing is deployed (A23, G2)."),
    ], why="H2 98 bank tests P0")
    # the quarantine
    doc.lines_between("#   AFTER THE QUARANTINE, NEVER BEFORE. As 5.18.101: stage A refuses (A14) unless the quarantine's record",
                      "#            this script's state file (STRAY_Q_FAILED).",
        "#   AFTER THE QUARANTINE, NEVER BEFORE. As 5.18.102: stage A refuses (A14) unless the quarantine's record\n"
        "#            (/root/dnb-stray-quarantine/quarantine.env, read, never written) says the move is done, with no failure and no\n"
        "#            put-back, and a --check that began at least 20 minutes after the move PASSED with \"the quarantine holds\"; while\n"
        "#            any run of that script holds its folder's lock; while a failure recorded by this script's runs, 5.18.102's,\n"
        "#            5.18.101's, 5.18.100's, 5.18.99's, 5.18.98's or 5.18.97's (STRAY_Q_FAILED in /root/dnb-5.18.103/state-5.18.103.env*,\n"
        "#            /root/dnb-5.18.102/state-5.18.102.env*, /root/dnb-5.18.101/state-5.18.101.env*,\n"
        "#            /root/dnb-5.18.100/state-5.18.100.env*, /root/dnb-5.18.99/state-5.18.99.env*, /root/dnb-5.18.98/state-5.18.98.env*\n"
        "#            or /root/dnb-5.18.97/state-5.18.97.env*) is newer than the newest passing --check; and unless that --check began\n"
        "#            after 5.18.102's own copy (COPY_AT in its state files: the quarantine's rule 3) and after this script's own\n"
        "#            latest copy — a deploy of 5.18.103 (COPY_AT) or a rollback of it (RB_COPY_AT, in its rollback-state-*.env), so\n"
        "#            that a --redeploy after a rollback waits for a --check after it. Nothing here opens, moves, copies or writes\n"
        "#            the kept file or anything at the old name. R19 after the copy, RB after a rollback; a failure is recorded in\n"
        "#            this script's state file (STRAY_Q_FAILED).", why="H2 quarantine")
    # the pilot and the switches: what not to touch while it runs
    doc.lines_between("#            unchanged by the run, never set (A5, A5b, A8–A11, A17, A18, A21, V3–V3j, R2–R22, RB), as in 5.18.101;",
                      "#            form offers is linked; the next real KYC quotation is the second (docs/71 §M steps 8 and 10).",
        "#            unchanged by the run, never set (A5, A5b, A8–A11, A17, A18, A21, V3–V3j, R2–R22, RB), as in 5.18.102;\n"
        "#            bank_screen_enabled among them (V3j), which must also read ON; the channel registry's rows among them (R11).\n"
        "#            Do not use the Salesperson numbers card, ECO Bank (keep no statement), Send Quotation, the KYC form,\n"
        "#            tools/set_config.php or Settings while this script runs, nor during its --after-only or its rollback: a change\n"
        "#            made then fails the checks that compare before and after. Do not change uCRM's Products, Service Plans or its\n"
        "#            client attributes while the deploy runs: what the forms offer after the copy must equal what they offered\n"
        "#            before it (QC-CA1). From the moment the deploy starts until it prints its last line, no admin touches the uCRM\n"
        "#            catalogue screen (the switch, the installation product, a product kept off, a standard quotation), makes,\n"
        "#            changes or removes a uCRM link, or saves a plan or a kit (the run's opening line says so): turn the uCRM\n"
        "#            catalogue on once it has printed its last line, then run --after-only. Make no KYC registration and no quote\n"
        "#            to try the release: the plugin WhatsApps every new quote to its customer. The first proof of direct mode is\n"
        "#            the --after-only's reading once the switch is on (QC-ON1–QC-ON4); the next real KYC quotation is the second.",
        why="H2 pilot")
    doc.lines_between("#   THE COMMIT IT INSTALLS IS A RELEASE COMMIT, NOT THE BRANCH TIP. The pin below, on release/5.18.102, is cut on f2055ba",
                      "#            outside data/. Its master.php and main.php are checked byte for byte (A0).",
        "#   THE COMMIT IT INSTALLS IS A RELEASE COMMIT, NOT THE BRANCH TIP. The pin below, on release/5.18.103, is cut on 1a0ea5f\n"
        f"#            (5.18.102, live): {nt} files — {nc} changed, {na} added — each file's sha256 pinned here. Stage A refuses a pin whose\n"
        "#            parent is not 5.18.102, whose short hash names another commit, whose delta is not exactly these files by these\n"
        "#            hashes, that carries a migration, and a checkout holding a file git does not track under the plugin folder\n"
        "#            outside data/. Its master.php and main.php are checked byte for byte (A0).", why="H2 commit")
    doc.sub("# 5.18.101 must be the live plugin: this script refuses any other live commit or version — deploy 5.18.101 first.",
            "# 5.18.102 must be the live plugin: this script refuses any other live commit or version — deploy 5.18.102 first.")
