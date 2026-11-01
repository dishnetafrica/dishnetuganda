# Part H3 — the header, second half: before the deploy, the commands, the rollback paragraph, the options, what it never
# does, and the stages. Root docs/72 §G.13. The two commands stay alone on their own lines, neither a rollback.


def apply(doc, ctx):
    na, nt = ctx["n_added"], ctx["n_added"] + ctx["n_changed"]
    doc.lines_between("# Before the deploy (docs/71 §M, as §R.6 amends it): 5.18.101's deployment is recorded in root docs/07 — its copy time",
                      "# put-back counts nothing.",
        "# Before the deploy (docs/72 §G.13): 5.18.102's deployment is recorded in root docs/07 — its copy time and counts, its\n"
        "# --after-only, and the quarantine's --check begun after that copy, whose record A14 reads; the agents and every admin\n"
        "# are told that nothing changes for them at the deploy — with the uCRM catalogue's switch off, 5.18.103 quotes exactly\n"
        "# as 5.18.102 — and every admin that no one opens the uCRM catalogue screen, links, unlinks, or saves a plan or a kit\n"
        "# from the moment the deploy starts until it prints its last line. A --check first is wise: its sheet lists what the\n"
        "# forms would offer from uCRM, every other item with its reason (QC-G1), and the hardware with no stock category of its\n"
        "# title (QC-N5). The owner's choices of docs/72 §G.14 — who presses a standard quotation, what the first one holds,\n"
        "# which products to keep off — are made on that screen after the deploy, not before.\n"
        "#\n"
        "# The deploy. Run as root on the server, then send back THE LOG FILE (never a copy of the terminal) — only after the\n"
        "# quarantine's --check has PASSED after 5.18.102's deploy; not between 14:55 and 15:10 UTC (the evening WhatsApp, 18:00\n"
        "# Kampala):\n"
        "#\n"
        "#   cd /opt/dishnet && git pull origin claude/study-this-jhe2eg && git fetch origin release/5.18.103 && mkdir -p /root/dnb-5.18.103 && bash scripts/deploy-5.18.103.sh 2>&1 | ( umask 077; exec tee /root/dnb-5.18.103/deploy-$(date -u +%Y%m%dT%H%M%SZ).log )\n"
        "#\n"
        "# It carries no --accept-unpriced: a run that QL-G1 refuses changes nothing. QL-G1 — 5.18.102's, carried, since with the\n"
        "# switch off 5.18.103 prices through the links — refuses where uCRM offers nothing an admin could link for a kind of item;\n"
        "# its sheet lists, per item, the uCRM items with the same spelling, a hint only. Only as the operator's explicit choice,\n"
        "# add that flag (Options).\n"
        "#\n"
        "# Then, once the deploy has printed its last line, an admin opens the uCRM catalogue screen (Uganda): checks what it\n"
        "# offers, keeps off what is not to be sold, sets the installation product, saves the standard quotations, has the stock\n"
        "# categories QC-N5 counted titled as uCRM names the hardware, and turns the switch on (the summary prints these steps).\n"
        "# Then, at least 20 minutes after the copy (the summary prints the time), and once more the next day after 15:00 UTC —\n"
        "# its own command, with its own log, read-only, nothing deployed (QC-ON1–QC-ON4 judge the switch, the installation\n"
        "# product, the standard quotations and the first direct sales: a run before the switch is on fails QC-ON1 by design);\n"
        "# then the quarantine's own --check, its own (the summary prints it):\n"
        "#\n"
        "#   cd /opt/dishnet && bash scripts/deploy-5.18.103.sh --after-only 2>&1 | ( umask 077; exec tee /root/dnb-5.18.103/after-$(date -u +%Y%m%dT%H%M%SZ).log )\n"
        "#\n"
        "# Once the switch is on, the agents are told that the KYC form, Send Quotation and the lead quotes offer uCRM's plans and\n"
        "# hardware at uCRM's prices, and an admin quotes in uCRM by hand each web registration of 5.18.102 left unpriced (QC-N4\n"
        "# lists their ids), after checking that the customer has none.\n"
        "#\n"
        "# The rollback is a separate command, printed at the end of the deploy's log, after the verdict. It is never pasted\n"
        "# together with the ones above: pasted together, the shell runs both (root docs/44 §16.9). It is one step — 5.18.102 put\n"
        "# back — and leaves ECO Bank, every statement kept, its switch, the quoting organization, the channel registry and every\n"
        "# number's owner exactly as they are. After it, on Uganda, where the quoting organization is in force, the uCRM catalogue\n"
        "# screen is gone, the Plans and Hardware screens are back with their uCRM link pickers, and the KYC form, Send Quotation\n"
        "# and the lead quotes offer the plugin's own plans and kits again, priced through the uCRM item linked to each — one not\n"
        "# linked unpriced, as under 5.18.102. A web-direct application stays as written and 5.18.102 never quotes it (P1, or P2\n"
        "# where cash was collected): an admin quotes each in uCRM by hand — the rollback counts them and puts their ids on its\n"
        "# sheet (QC-RB2). Nothing is quoted at price 0. The uCRM catalogue's settings stay in the store, read by nothing under\n"
        "# 5.18.102 (QC-RB1); a later deploy of 5.18.103 goes with --redeploy and finds them as they were — with the switch left\n"
        "# on, direct mode comes back the moment its copy lands (its QC-N1 says so in capitals). The quotation summary's sign-off\n"
        "# stays 5.18.101's: neither release changes webhook.php. Quotes already made in uCRM are not changed, and the files\n"
        "# 5.18.103 adds stay on disk, reached by nothing (QC-RB1). The by-hand put-back counts nothing.", why="H3 before/commands/rollback")
    doc.lines_between("#   --after-only         the deploy already happened: run the checks again — R23, C1–C5 (CA2), BK1, BK2 and G3 on a new",
                      "#                        once more the next day after 15:00 UTC",
        "#   --after-only         the deploy already happened: run the checks again — R23, C1–C5 (CA2), BK1, BK2 and G3 on a new\n"
        "#                        copy, the drift against the deploy's own reading (CA3), Q-CA2, Q-CA3 and Q-CA6 against stage A's\n"
        "#                        readings recorded in the state file, TN-CA1–TN-CA3, QS-CA1, QL-CA2, QL-CA3 (the Plans and Hardware\n"
        "#                        screens and the Orders card without a session; post_sync.php's link and quotation actions as\n"
        "#                        text), QC-CA1 (what the forms offer, its item-by-item comparison with stage A's a note naming its\n"
        "#                        direction), QC-CA2, QC-CA3 and QC-ON1–QC-ON4 (the switch ON, the installation product set and\n"
        "#                        passing, every standard quotation usable, G-7 over the web-direct records: each a FAIL until the\n"
        "#                        switch is on); 088 applied and complete, its rows none fewer than the deploy's; R19 conclusive\n"
        "#                        once master has run the retry job after the copy; its own cash sheet. Run it once an admin has\n"
        "#                        turned the uCRM catalogue on — one run before that fails QC-ON1 by design — at least 20 minutes\n"
        "#                        after the copy, and once more the next day after 15:00 UTC", why="H3 after-only")
    doc.sub("#   --move-list-only     read-only, before the deploy, while 5.18.101 is live:", "#   --move-list-only     read-only, before the deploy, while 5.18.102 is live:")
    doc.lines_between("#   --redeploy           deploy 5.18.102 again after its own rollback: the 31 files it adds, which the rollback leaves on",
                      "#                        links an admin made, which it leaves in the store: stage A reads and judges each (QL-G1)",
        "#   --redeploy           deploy 5.18.103 again after its own rollback: the files it adds, which the rollback leaves on\n"
        "#                        disk, are expected there (BR1 counts them, and the copy replaces them), and so are the uCRM\n"
        "#                        catalogue's settings, which it leaves in the store: stage A reads them (QC-G1; QC-N1 says the\n"
        "#                        switch in capitals if it was left on)", why="H3 redeploy")
    doc.sub("#                        operator's explicit choice (Q-G3) — 5.18.102 then quotes as 5.18.101 does with it off (D7), and\n"
            "#                        QL-G1 is a note; said as such",
            "#                        operator's explicit choice (Q-G3) — 5.18.103 then quotes as 5.18.102 does with it off (D7), and\n"
            "#                        QL-G1 and QC-G1 are notes; said as such")
    doc.lines_between("#   --accept-unpriced    two meanings, each the operator's explicit choice, printed as such (docs/71 §R.7.3, §R.7.7 item",
                      "#                        QL-G1, is cleared there and said (QL-LK1 judges as without it)",
        "#   --accept-unpriced    with a deploy or --check, the operator's explicit choice, printed as such (docs/71 §R.7.1): QL-G1's\n"
        "#                        refusal of a kind of item for which uCRM offers nothing an admin could link (plans; hardware and\n"
        "#                        the installation) becomes a note — with the uCRM catalogue off, every item of that kind is then\n"
        "#                        unpriced on Uganda whatever an admin links: a Cash sale of one is refused, a Credit one is\n"
        "#                        registered with no quotation (P1) and the agent is told; with it on, links decide nothing. It\n"
        "#                        never lifts an unreadable catalogue or a reason the script cannot classify. Typed where every kind\n"
        "#                        has a candidate it changes nothing — nor with a deploy that finds this release already serving:\n"
        "#                        that run makes an --after-only's checks, and the flag is cleared there and said. (Its --after-only\n"
        "#                        meaning went with QL-LK1, retired since 5.18.103: docs/72 §G.13.)", why="H3 accept-unpriced")
    doc.lines_between("#   --rollback           put 5.18.101 back (typed ROLLBACK), then check it — 5.18.101's files by sha256, its sign-off line",
                      "#                        5.18.102 first — they retry on the cron — or do not roll back",
        "#   --rollback           put 5.18.102 back (typed ROLLBACK), then check it — 5.18.102's files by sha256, its sign-off line\n"
        "#                        (unchanged), its owner rule and its quoting code in place, the files 5.18.103 adds left on disk\n"
        "#                        and the uCRM catalogue's table left in place, reached by nothing (QC-RB1), the web-direct\n"
        "#                        applications with no quotation counted, their ids on its sheet to quote by hand (QC-RB2 — in its\n"
        "#                        stage A too, a note, never a refusal: 5.18.102 quotes nothing at price 0), the Cashbook's list and\n"
        "#                        ECO Bank exactly as they were (its files, its switch, 088 and every kept row, both cards on a copy),\n"
        "#                        D2 = A on a copy of the book (its own cash sheet), 5.18.95's security fixes still in place, the\n"
        "#                        documents, the database, and the numbers a technician owns counted; its record goes to a file of\n"
        "#                        its own. It refuses a copy that stopped part-way, and prints the by-hand put-back, which counts\n"
        "#                        nothing", why="H3 rollback option")
    # what it never does, what it keeps
    doc.sub("# vault of its own, so no run on a copy can write the live vault; write UcrmQuoteLines' page cache\n"
            "# (ucrm_quote_catalogue_cache.json) anywhere, or call UcrmQuoteLines::quoteClient() or forPage(); run any of webhook.php;",
            "# vault of its own, so no run on a copy can write the live vault; write UcrmQuoteLines' page cache\n"
            "# (ucrm_quote_catalogue_cache.json) or its last good copy anywhere, or call UcrmQuoteLines::quoteClient(), forPage(),\n"
            "# forDirectPage() or forDirectUse(); turn the uCRM catalogue on or off, or change any of its settings; run any of webhook.php;")
    doc.sub("# to uCRM (its other requests are V's, from the host to the plugin's own public address, as in 5.18.101).\n"
            "# What it keeps from 5.18.101, read-only:",
            "# to uCRM (its other requests are V's, from the host to the plugin's own public address, as in 5.18.102).\n"
            "# What it keeps from 5.18.102, read-only:")
    doc.sub("# so; it is not a run on a copy. The writes are the documented deploy (or rollback), a backup under /root/dnb-5.18.102/,",
            "# so; it is not a run on a copy. The writes are the documented deploy (or rollback), a backup under /root/dnb-5.18.103/,")
