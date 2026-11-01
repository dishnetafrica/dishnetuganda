# Part F — the body's remaining version-dependent comments and message strings (root docs/72 §G.13): the live release,
# what the server runs first and the rollback target, is 5.18.102 (1a0ea5f) now, not 5.18.101 (f2055ba); the release
# being installed is 5.18.103. Each mention judged on its own: where a version names the origin of a check or a feature
# it stays; "releases that did not change X" lists grow by one only where 5.18.103 leaves X. No code is changed — every
# edit is inside a comment or a message string — and no off-limits region (Part D's) is touched. Counts come from the
# delta (ctx), never typed here.

ONES = "zero one two three four five six seven eight nine ten eleven twelve thirteen fourteen fifteen sixteen seventeen eighteen nineteen".split()
TENS = "_ _ twenty thirty forty fifty sixty seventy eighty ninety".split()


def words(n):
    assert 0 <= n < 100, n
    if n < 20:
        return ONES[n]
    return TENS[n // 10] + ("" if n % 10 == 0 else "-" + ONES[n % 10])


QUOTING_SEVEN = ["lib/KycService.php", "lib/UcrmClientTarget.php", "lib/KycCrmSync.php", "lib/UcrmLeadSync.php",
                 "lib/QuotationService.php", "lib/PluginQuotePdf.php", "includes/api/api_cron_debug.php"]   # RB_QO's loop, in its order
QUOTING_OTHER = ["includes/api/api_products_admin.php", "tabs/sales/kyc_form.php", "tabs/sales/applications.php"]


def apply(doc, ctx):
    added, changed, kept = ctx["added"], ctx["changed"], ctx["kept"]
    n_a, n_c, n_k, n_p = ctx["n_added"], ctx["n_changed"], ctx["n_kept"], ctx["n_pin"]
    # what the prose below says of the delta, asserted against it first
    non_test_added = sorted(f for f in added if not f.startswith("tests/"))
    assert non_test_added == ["lib/UcrmCatalogue.php", "tabs/sales/ucrm_catalogue.php"], non_test_added
    for f in ("includes/navigation.php", "public.php", "includes/post/post_leads.php", "includes/api/api_products_admin.php",
              "tabs/sales/kyc_form.php", "tabs/sales/send_quote.php", "tabs/sales/applications.php", "lib/KycService.php"):
        assert f in changed, f
    for f in ("webhook.php", "lib/SalesNumbersAdmin.php", "tabs/engage/wa_ai_setup.php", "lib/WhatsAppAccess.php",
              "tools/cash_in_hand.php", "lib/CashbookService.php", "tabs/admin/ceo_dashboard.php", "lib/BankGate.php",
              "lib/BankStatementStore.php", "lib/PluginConfig.php", "lib/UcrmQuoteLinks.php", "lib/QuoteOrganization.php",
              "lib/UcrmLeadSync.php", "profiles/uganda.json", "tools/set_config.php", "prices.php", "shop.php"):
        assert f in kept and f not in changed, f
    assert not any(f.startswith("migrations/") for f in added + changed), "5.18.103 adds no migration"
    q_changed = [f for f in QUOTING_SEVEN if f in changed]
    q_kept = [f for f in QUOTING_SEVEN if f not in changed]
    assert all(f in changed for f in QUOTING_OTHER)
    assert all(f in kept for f in q_kept), q_kept
    assert q_changed == ["lib/KycService.php", "lib/KycCrmSync.php", "lib/QuotationService.php", "includes/api/api_cron_debug.php"], q_changed

    # ── cih_run's header and its two copy-check comments: the baseline's code is 5.18.102's, the pin's 5.18.103's ──
    doc.subs([
        ("# pin's (D2), P0; ECO Bank through the live code's classes (G3) and the pin's (BK2); the copy checks C1–C5, 5.18.101's\n"
         "# code and 5.18.102's from Git; and the notes N1–N4. r23: 5.18.101's code from Git (TODAY) and the installed (D2), P0, the\n"
         "# installed tool; ECO Bank through the installed code (BA2: BK2 and G3 again); the copy checks with 5.18.101's code from\n",
         "# pin's (D2), P0; ECO Bank through the live code's classes (G3) and the pin's (BK2); the copy checks C1–C5, 5.18.102's\n"
         "# code and 5.18.103's from Git; and the notes N1–N4. r23: 5.18.102's code from Git (TODAY) and the installed (D2), P0, the\n"
         "# installed tool; ECO Bank through the installed code (BA2: BK2 and G3 again); the copy checks with 5.18.102's code from\n"),
        ("  # 5.18.101's and 5.18.102's code from Git, in R23 5.18.101's from Git and the installed. In R23 a fault is the copy checks'\n",
         "  # 5.18.102's and 5.18.103's code from Git, in R23 5.18.102's from Git and the installed. In R23 a fault is the copy checks'\n"),
    ], why="cih_run comments")
    # the live release "here": cih_run's ECO Bank reading, the move-list's mode, the rollback target's files
    doc.sub("(5.18.101 here)", "(5.18.102 here)", 3, why="the live release, here")
    # the cash sheet: this run's folder, and what the quoting checks put on it now
    doc.sub("# The run's cash sheet, on the host: /root/dnb-5.18.102/cash-<mode>-<stamp>.txt, mode 0600, in a folder of mode 700 — the\n"
            "# figures, and the catalogue's rows by name (QL-G1, QL-CA1, QL-N7: names, product ids, units and the resolver's reasons,\n"
            "# which can carry a price), for the operator to read on the server;",
            "# The run's cash sheet, on the host: /root/dnb-5.18.103/cash-<mode>-<stamp>.txt, mode 0600, in a folder of mode 700 — the\n"
            "# figures, and the catalogue's rows by name (QL-G1, QL-N7: names, product ids, units and the resolver's reasons, which\n"
            "# can carry a price; since 5.18.103 QC's: names, uCRM ids, prices and application ids), for the operator to read on the server;",
            why="sheet comment")

    # ── the quoting probe's comments (QO_PHP's text stays short: it is one argument to php -r) ──
    doc.sub("# set or unset — never a value. 5.18.102 (QL-G1, QL-CA1) copies no key of its own here: its catalogue's token client is\n"
            "# built from the same token copy, and its gate is Q-G3's setting (D7).\n",
            "# set or unset — never a value. 5.18.102 (QL-G1) copies no key of its own here: its catalogue's token client is\n"
            "# built from the same token copy, and its gate is Q-G3's setting (D7); 5.18.103 copies one, public_price_exclude (QC-N6).\n",
            why="QOP keys comment")
    doc.sub("        // copies (5.18.101's, which never holds it, even where a rolled-back run left the file on disk); after the copy the\n"
            "        // installed code root's own, the very file its UcrmQuoteLines requires by __DIR__, so require_once loads it once.\n"
            "        // It requires no sibling file (E-5), so it loads beside 5.18.101's store classes with no class declared twice. The\n",
            "        // copies (5.18.102's, whose own copy is the same file: 5.18.103 keeps it byte for byte); after the copy none (since\n"
            "        // 5.18.103 retired QL-CA1 and QL-LK1, which read the installed root's own; docs/72 §G.13).\n"
            "        // It requires no sibling file (E-5), so it loads beside 5.18.102's store classes with no class declared twice. The\n",
            why="probe links comment")
    doc.sub("        // out, not loaded: KycService cannot be loaded into stage A's reading beside 5.18.101's code root (D14 R-30).\n",
            "        // out, not loaded: KycService cannot be loaded into stage A's reading beside 5.18.102's code root (D14 R-30).\n",
            why="probe D11 comment")

    # ── ql_stage: QL-G1's premise was 5.18.101's (no link screen); under 5.18.103 the link store is 5.18.102's ──
    doc.sub("# copies, and uCRM with GETs only, in the probe whose requests Q-G4's refusal counted). 5.18.102 D14 (docs/71 §R.7.1):\n"
            "# before the copy nothing can be linked — 5.18.101 has no link screen, and only an earlier, rolled-back run of this\n"
            "# release can have left a link store on the book — so an item the form offers that does not resolve is no longer\n"
            "# refused: until an admin links it after the deploy every one is \"not linked\" (§R.6), and the count is a note. QL-G1\n",
            "# copies, and uCRM with GETs only, in the probe whose requests Q-G4's refusal counted). 5.18.102 D14 (docs/71 §R.7.1):\n"
            "# an item the form offers that does not resolve is not refused — before 5.18.102's copy nothing could be linked (5.18.101\n"
            "# had no link screen), and since it the link store is what 5.18.102's Plans and Hardware screens wrote (under D6 nothing\n"
            "# is linked, docs/72 G-10) — so every such item is \"not linked\" (§R.6), or broken, and the count is a note. QL-G1\n",
            why="ql_stage header")
    doc.sub("— a link an earlier, rolled-back run of $EXPECTED_VERSION left there would go unjudged. No flag lifts it.",
            "— a link made on $BASELINE_VERSION's Plans or Hardware screens would go unjudged. No flag lifts it.",
            why="QL-G1 link store refusal")
    doc.sub('note "QL-G1 item(s) the KYC form offers do not resolve yet — $(ql_unp_say "$c" "$r") — as expected before the copy: '
            'nothing can be linked before it ($BASELINE_VERSION has no link screen), so this is no longer a refusal (docs/71 §R.7.1). '
            'From the copy until an admin links them a Cash sale of one is refused and a Credit one registered with no quotation (P1): '
            'once this run has printed its last line, link each on the Plans or Hardware screen (Uganda), as an admin (docs/71 §M step 7), '
            'then run --after-only (step 8); a broken one\'s own reason, on the sheet, says its remedy — in uCRM, or linking it again"',
            'note "QL-G1 item(s) the KYC form offers do not resolve — $(ql_unp_say "$c" "$r") — not linked on $BASELINE_VERSION\'s '
            'Plans or Hardware screens (under D6 nothing is linked, docs/72 G-10), or linked and broken: a note, as in '
            '$BASELINE_VERSION\'s own run (docs/71 §R.7.1). Under $BASELINE_VERSION today, and under $EXPECTED_VERSION while the uCRM '
            'catalogue is off, as the deploy leaves it, a Cash sale of one is refused and a Credit one registered with no quotation '
            '(P1); once an admin turns the uCRM catalogue on, the forms offer uCRM\'s own items and no link decides anything '
            '(docs/72 §G.1, G-10); a broken one\'s own reason, on the sheet, says its remedy"',
            why="QL-G1 not-resolving note")
    doc.sub('absent) note "QL-G1 the link store on the copy: none — nothing was ever linked on this book ($BASELINE_VERSION has no '
            'link screen; only an earlier, rolled-back run of $EXPECTED_VERSION could have left one, docs/71 §R.7.1)" ;;',
            'absent) note "QL-G1 the link store on the copy: none — no link was ever recorded on this book on $BASELINE_VERSION\'s '
            'Plans and Hardware screens (under D6 nothing is linked, docs/72 G-10)" ;;',
            why="QL-G1 link store absent")
    doc.sub('active — left by an earlier, rolled-back run of $EXPECTED_VERSION (docs/71 §R.7.1); each active one is judged above',
            'active — the store $BASELINE_VERSION\'s Plans and Hardware screens write, which $EXPECTED_VERSION keeps, frozen while '
            'the uCRM catalogue is on (docs/72 G-11); each active one is judged above',
            why="QL-G1 link store counted")
    doc.sub("the release never writes two (§R.1.4): an admin links each again (a note here; an --after-only's QL-LK1 fails on it)\")\" ;;",
            "the release never writes two (§R.1.4): an admin links each again while the uCRM catalogue is off (a note; QL-LK1, which "
            "failed on it, is retired since $EXPECTED_VERSION)\")\" ;;",
            why="QL-G1 two records")
    doc.sub("  # the retry quotes them as 5.18.101 does (D7; E2-1, review 2).\n",
            "  # the retry quotes them as 5.18.102 does (D7; E2-1, review 2).\n", why="QL-N3 comment, gate off")

    # ── TN (5.18.100's owner rule): 5.18.103 changes neither of its files ──
    doc.subs([
        ("owners() where the code has it (5.18.100 through 5.18.102), salespeople() where it does not",
         "owners() where the code has it (5.18.100 through 5.18.103), salespeople() where it does not"),
        ("(5.18.100 through 5.18.102 do not change lib/WhatsAppAccess.php: Q-G1, Q-CA1).",
         "(5.18.100 through 5.18.103 do not change lib/WhatsAppAccess.php: Q-G1, Q-CA1)."),
        ("# 5.18.100's rule, as text — the pin's: 5.18.101 and 5.18.102 change neither file\n",
         "# 5.18.100's rule, as text — the pin's: 5.18.101 through 5.18.103 change neither file\n"),
        ("# the installed, 5.18.101's (5.18.100's rule), and the rollback's: the same rule\n",
         "# the installed, 5.18.102's (5.18.100's rule), and the rollback's: the same rule\n"),
        ("(a deploy and --check), carried: 5.18.101 and 5.18.102 change neither\n",
         "(a deploy and --check), carried: 5.18.101 through 5.18.103 change neither\n"),
        ("# tn_rb INST_ROOT — after a rollback: the owner list 5.18.101's (5.18.100's rule),",
         "# tn_rb INST_ROOT — after a rollback: the owner list 5.18.102's (5.18.100's rule),"),
    ], why="TN comments")

    # ── QS (5.18.101's sign-off): 5.18.102 and 5.18.103 keep webhook.php byte for byte ──
    doc.subs([
        ("(QS; read-only — webhook.php as text, never run), carried by 5.18.102 ─\n",
         "(QS; read-only — webhook.php as text, never run), carried by 5.18.102 and 5.18.103 ─\n"),
        ("# read both files from Git again and found these counts). 5.18.102 keeps webhook.php byte for byte (PREV_KEPT_SHA256S,\n"
         "# docs/71 §L.4): the pin's,",
         "# read both files from Git again and found these counts). 5.18.102 and 5.18.103 keep webhook.php byte for byte\n"
         "# (PREV_KEPT_SHA256S; docs/71 §L.4, docs/72 §G.12): the pin's,"),
        ("# 5.18.101's sign-off, as text — the pin's: 5.18.102 keeps webhook.php byte for byte\n",
         "# 5.18.101's sign-off, as text — the pin's: 5.18.102 and 5.18.103 keep webhook.php byte for byte\n"),
        ("# the installed, 5.18.101's, and the rollback's: the same line\n",
         "# the installed, 5.18.102's (5.18.101's line), and the rollback's: the same line\n"),
        ("the pin's webhook.php from Git signs off as reviewed, and the installed one is 5.18.101's,\n# the same line.",
         "the pin's webhook.php from Git signs off as reviewed, and the installed one is 5.18.102's,\n# the same line."),
        ("# qs_rb — after a rollback: the installed webhook.php signs off as 5.18.101 does —",
         "# qs_rb — after a rollback: the installed webhook.php signs off as 5.18.102 does (5.18.101's line) —"),
    ], why="QS comments")

    doc.sub("the ledger's last row the same (5.18.98 to 5.18.102 add no migration);",
            "the ledger's last row the same (5.18.98 to 5.18.103 add no migration);", why="db_judge")

    # ── A0's comment: the delta's counts and the baseline's release commit ──
    doc.sub("# 5.18.98: every file of the delta by the sha256 pinned in this script (PIN_SHA256S) — the list is the delta exactly, its\n"
            "# sixty-nine files and no other, the 31 it adds among them — and 5.18.101's files this release must leave as they are\n",
            "# 5.18.98: every file of the delta by the sha256 pinned in this script (PIN_SHA256S) — the list is the delta exactly, its\n"
            f"# {words(n_p)} files and no other, the {n_a} it adds among them — and 5.18.102's files this release must leave as they are\n",
            why="A0 counts")
    doc.sub("5.18.99: and every file it changes by the sha256 5.18.101's release commit gives it",
            "5.18.99: and every file it changes by the sha256 5.18.102's release commit gives it", why="A0 baseline")

    # ── the rollback target reads none of the files 5.18.103 adds ──
    doc.sub("# none of them — 5.18.101's code reads no file 5.18.102 adds (EXPECTED_ADDED: lib/UcrmQuoteLines.php, lib/UcrmQuoteLinks.php,\n"
            "# tests/test_quote_from_ucrm.php and tests/quote_from_ucrm/; QL-RB1 and QL-RB3 show it after a rollback), and BR1 counts\n",
            "# none of them — 5.18.102's code reads no file 5.18.103 adds (EXPECTED_ADDED: lib/UcrmCatalogue.php,\n"
            "# tabs/sales/ucrm_catalogue.php and 5.18.103's tests; QC-RB1 shows it after a rollback), and BR1 counts\n",
            why="BASE_DIFFERS comment")
    # ── A22L: 5.18.102 is live and carries 5.18.95's fixes ──
    doc.sub("read as A22 read the pin's (refusal): 5.18.101 is live and\n"
            "# carries them, so all seven checks must read it fixed — a live 5.18.101 without them",
            "read as A22 read the pin's (refusal): 5.18.102 is live and\n"
            "# carries them, so all seven checks must read it fixed — a live 5.18.102 without them", why="A22L comment")
    # ── BR1 ──
    doc.sub("# BR1 — since 5.18.97 (SPEC §2.10b R1): the installed plugin is live 5.18.101 — every file f2055ba tracks under the plugin,\n"
            "# data/ aside, byte for byte (sha256 on the host, from git archive into this run's folder, removed at once), not only the\n"
            "# files this release changes (the record and the manifest say 5.18.101 above; BASE_DIFFERS read this release's own).\n"
            "# Files on disk beyond them are not asked about: deploy-hybrid.sh never deletes, so an earlier 5.18.102 and its\n"
            "# rollback leave the 31 files it adds behind (docs/71 §Q, D-R1) — counted and said (BR1_LEFT, over EXPECTED_ADDED);\n"
            "# 5.18.101's code reads none of them (QL-RB1), and the copy replaces them.\n",
            "# BR1 — since 5.18.97 (SPEC §2.10b R1): the installed plugin is live 5.18.102 — every file 1a0ea5f tracks under the plugin,\n"
            "# data/ aside, byte for byte (sha256 on the host, from git archive into this run's folder, removed at once), not only the\n"
            "# files this release changes (the record and the manifest say 5.18.102 above; BASE_DIFFERS read this release's own).\n"
            "# Files on disk beyond them are not asked about: deploy-hybrid.sh never deletes, so an earlier 5.18.103 and its\n"
            f"# rollback leave the {n_a} files it adds behind (docs/72 §G.10) — counted and said (BR1_LEFT, over EXPECTED_ADDED);\n"
            "# 5.18.102's code reads none of them (QC-RB1), and the copy replaces them.\n",
            why="BR1 comment")
    # ── Q-G1 ──
    doc.sub("# Q-G1 — 5.18.99 (SPEC-quote-company §6; 5.18.98's G1, carried): the live plugin is 5.18.101 — the container's record and\n"
            "# the manifest say so above, and (a deploy) BR1 has read every file f2055ba tracks — and, by the sha256 THIS SCRIPT pins\n"
            "# for each (not only Git's), every file 5.18.102 changes is 5.18.101's as installed (PREV_CHANGED_SHA256S), and every file\n"
            "# 5.18.101 has that 5.18.102 must not touch (PREV_KEPT_SHA256S): ECO Bank's module, its two links, the More menu, the\n"
            "# styles, the Cashbook's post handler and its list split, ECO Bank's wiring, the cash-in-hand tool, the files the\n"
            "# quoting code reads and 5.18.99's quoting files that 5.18.102 keeps (cron_quote_wa.php, QuoteTaxLine, TenantProfile and\n"
            "# webhook.php; QuoteOrganization, QuotePdfSource and UcrmLeadSync), 5.18.100's owner rule and the three files it reads, and\n"
            "# the four 5.18.102 reads or docs/71 §L.4 declares kept (lib/PublicPriceFeed.php among them), unchanged. The 31 files\n"
            "# 5.18.102 adds are not asked about here: BR1 counts any an earlier rollback left. Run before a deploy and before the\n"
            "# move-list.\n",
            "# Q-G1 — 5.18.99 (SPEC-quote-company §6; 5.18.98's G1, carried): the live plugin is 5.18.102 — the container's record and\n"
            "# the manifest say so above, and (a deploy) BR1 has read every file 1a0ea5f tracks — and, by the sha256 THIS SCRIPT pins\n"
            "# for each (not only Git's), every file 5.18.103 changes is 5.18.102's as installed (PREV_CHANGED_SHA256S), and every file\n"
            "# 5.18.102 has that 5.18.103 must not touch (PREV_KEPT_SHA256S): ECO Bank's module, its link on the CEO page (BK3 reads the\n"
            "# one in navigation.php, which 5.18.103 changes outside it), the More menu, the\n"
            "# styles, the Cashbook's post handler and its list split, ECO Bank's wiring, the cash-in-hand tool, the files the\n"
            "# quoting code reads and 5.18.99's quoting files that 5.18.103 keeps (cron_quote_wa.php, QuoteTaxLine, TenantProfile and\n"
            "# webhook.php; QuoteOrganization, QuotePdfSource and UcrmLeadSync), 5.18.100's owner rule and the three files it reads,\n"
            "# the four 5.18.102 read or docs/71 §L.4 declared kept (lib/PublicPriceFeed.php among them), 5.18.102's own that 5.18.103\n"
            "# does not change (lib/UcrmQuoteLinks.php among them) and the five 5.18.103 reads or docs/72 §G.12 declares kept (prices.php\n"
            f"# and shop.php among them), unchanged. The {n_a} files\n"
            "# 5.18.103 adds are not asked about here: BR1 counts any an earlier rollback left. Run before a deploy and before the\n"
            "# move-list.\n",
            why="Q-G1 comment")
    # navigation.php is not among the files 5.18.103 must not touch (it changes it outside ECO Bank's block, BK3)
    doc.sub("and all $G1_N files it must not touch — ECO Bank's module, $BK_NAV, $BK_CEO, the More menu,",
            "and all $G1_N files it must not touch — ECO Bank's module, $BK_CEO, the More menu,", why="Q-G1 ok: navigation.php")
    doc.sub("all $CA1_K files of $BASELINE_VERSION it must not touch — ECO Bank's module, $BK_NAV, $BK_CEO, the Cashbook's",
            "all $CA1_K files of $BASELINE_VERSION it must not touch — ECO Bank's module, $BK_CEO, the Cashbook's", why="Q-CA1 ok: navigation.php")
    doc.sub("the QL key — the catalogue's item and link-record hashes;",
            "the QL key — the catalogue's item hashes (QC-G1, QC-CA1 since 5.18.103; 5.18.102's link-record hashes went with QL-CA1 and QL-LK1);",
            why="QL_KEY comment")
    doc.sub("removed with it, however the run ends: the pin's and 5.18.101's\n",
            "removed with it, however the run ends: the pin's and 5.18.102's\n", why="P3B comment")

    # ── A14: the earlier state files, one release further back ──
    doc.sub("# beside it — and in the previous release's own, 5.18.101's, and the four before, 5.18.100's, 5.18.99's, 5.18.98's and\n"
            "# 5.18.97's (their R19 and RB record the same line, in /root/dnb-5.18.101/state-5.18.101.env,\n"
            "# /root/dnb-5.18.100/state-5.18.100.env, /root/dnb-5.18.99/state-5.18.99.env, /root/dnb-5.18.98/state-5.18.98.env,\n"
            "# /root/dnb-5.18.97/state-5.18.97.env and the ones kept beside each) — is not\n",
            "# beside it — and in the previous release's own, 5.18.102's, and the five before, 5.18.101's, 5.18.100's, 5.18.99's,\n"
            "# 5.18.98's and 5.18.97's (their R19 and RB record the same line, in /root/dnb-5.18.102/state-5.18.102.env,\n"
            "# /root/dnb-5.18.101/state-5.18.101.env, /root/dnb-5.18.100/state-5.18.100.env, /root/dnb-5.18.99/state-5.18.99.env,\n"
            "# /root/dnb-5.18.98/state-5.18.98.env, /root/dnb-5.18.97/state-5.18.97.env and the ones kept beside each) — is not\n",
            why="A14 state files")
    doc.sub("its --check. 5.18.101's deploy was one,\n# so a deploy goes on only once a --check that began after 5.18.101's latest copy",
            "its --check. 5.18.102's deploy was one,\n# so a deploy goes on only once a --check that began after 5.18.102's latest copy", why="A14 previous copy")
    doc.sub("this script's own latest copy of the plugin — a deploy of 5.18.102 (COPY_AT in its",
            "this script's own latest copy of the plugin — a deploy of 5.18.103 (COPY_AT in its", why="A14 own copy")
    doc.sub("# or a deploy over a rolled-back 5.18.102, goes on only once", "# or a deploy over a rolled-back 5.18.103, goes on only once",
            why="A14 own rollback")

    # ── A23 and the C1–C5 copy checks ──
    doc.subs([
        ("#   A23.1 the LIVE code's figure — 5.18.101's cashInHand() (5.18.98's, unchanged since),",
         "#   A23.1 the LIVE code's figure — 5.18.102's cashInHand() (5.18.98's, unchanged since),"),
        ("every currency × project: 5.18.102 does not change cash in hand;", "every currency × project: 5.18.103 does not change cash in hand;"),
        ("# 5.18.101 classes (G3), the offered accounts (BN5) and the gate per switch state through the pin's (BK2); the copy checks\n"
         "# C1–C5, 5.18.101's code and 5.18.102's from Git on ONE copy of the master;",
         "# 5.18.102 classes (G3), the offered accounts (BN5) and the gate per switch state through the pin's (BK2); the copy checks\n"
         "# C1–C5, 5.18.102's code and 5.18.103's from Git on ONE copy of the master;"),
        ("  # The pin's code reads the cards as the live classes do — 5.18.102 changes no file of ECO Bank's (A0, Q-G1).\n",
         "  # The pin's code reads the cards as the live classes do — 5.18.103 changes none of the files they are read through (A0, Q-G1).\n"),
        ("carried: 5.18.101's code and 5.18.102's, both from Git, on ONE copy of the\n",
         "carried: 5.18.102's code and 5.18.103's, both from Git, on ONE copy of the\n"),
        ("  # both codes against each other: 5.18.102 changes no file of the Cashbook's, so they must be the same.\n",
         "  # both codes against each other: 5.18.103 changes no file of the Cashbook's, so they must be the same.\n"),
    ], why="A23 and C1–C5 comments")

    # ── stage A's quoting comments ──
    doc.sub("# carries 5.18.102's catalogue and its notes, judged after the sign-off below (QL-G1, QL-N1–QL-N7).\n",
            "# carries 5.18.102's catalogue and its notes (QL-G1, QL-N1–QL-N7) and 5.18.103's (QC-G1, QC-N1–QC-N7), judged after the\n"
            "# sign-off below.\n", why="stage A quoting comment")
    doc.sub("  # 5.18.102 keeping webhook.php byte for byte (docs/71 §Q D-R12). A refusal stops here.\n",
            "  # 5.18.102 and 5.18.103 keeping webhook.php byte for byte (docs/71 §Q D-R12, docs/72 §G.12). A refusal stops here.\n",
            why="QS-G1 call comment")
    doc.sub("  # QL-G1, QL-N1–QL-N7 — 5.18.102 (docs/71 §L.3,", "  # QL-G1, QL-N1–QL-N7 — 5.18.102's, carried (docs/71 §L.3,",
            why="QL-G1 call comment")

    # ── the copy's time ──
    doc.sub("adds too: after a rollback the 31 that 5.18.102 adds are left on disk (deploy-hybrid.sh never deletes; docs/71 §Q,\n# D-R1) and",
            f"adds too: after a rollback the {n_a} that 5.18.103 adds are left on disk (deploy-hybrid.sh never deletes; docs/72\n# §G.10) and",
            why="stamp_copy comment")
    doc.sub("as stamp_copy gave lib/KycService.php (COPY_MARK; 5.18.101 read it off\n  # webhook.php, 5.18.100 off",
            "as stamp_copy gave lib/KycService.php (COPY_MARK, as 5.18.102 read it; 5.18.101 off\n  # webhook.php, 5.18.100 off",
            why="rollback copy time comment")
    doc.sub("  # The copy's own time, as stamp_copy gave lib/KycService.php (COPY_MARK, a file 5.18.102 changes; 5.18.101 read it off\n"
            "  # webhook.php, which 5.18.102 does not change):\n",
            "  # The copy's own time, as stamp_copy gave lib/KycService.php (COPY_MARK, a file 5.18.103 changes, as 5.18.102 read it):\n",
            why="deploy copy time comment")

    # ── stage V's comments (outside the QL-CA3 block and the V notes) ──
    doc.sub("5.18.96 keeps it (and so does the rollback target, 5.18.101).", "5.18.96 keeps it (and so does the rollback target, 5.18.102).",
            why="V6 comment")
    doc.sub("One GET: nothing is posted, nothing pushed. 5.18.102 changes the file and keeps\n"
            "      # push_products_to_ucrm in the list:",
            "One GET: nothing is posted, nothing pushed. 5.18.102 and 5.18.103 change the file\n"
            "      # and keep push_products_to_ucrm in the list:", why="Q-CA4 comment")
    doc.sub("no page is rendered with a login. 5.18.102 changes three of them — the KYC form, Send\n"
            "      # Quotation and Orders (docs/71 §G, §J, §F.5) — and the sign-in comes first on each as before.\n",
            "no page is rendered with a login. 5.18.103 changes the three 5.18.102 changed — the KYC form,\n"
            "      # Send Quotation and Orders (docs/72 §G.5, §G.9, G-10) — and the sign-in comes first on each as before.\n",
            why="Q-CA5 comment")

    # ── stage R's comments ──
    doc.sub("runner; 5.18.98 through 5.18.102 add no migration and write none of its tables.",
            "runner; 5.18.98 through 5.18.103 add no migration and write none of its tables.", why="BA1 088 comment")
    doc.sub("# for each of its 69 (R1 compared them with Git), the 31 it adds among them; the 5.18.101 files this release must not\n"
            "# touch — ECO Bank's module, its links, the Cashbook's service, its page, its tab, its export and its routes,\n"
            "# api/index.php, cron_quote_wa.php, the quoting files 5.18.102 keeps (QuoteOrganization, QuoteTaxLine, TenantProfile,\n"
            "# PublicPriceFeed), lib/SalesNumbersAdmin.php and its page and the rest — are still 5.18.101's by theirs (webhook.php\n",
            f"# for each of its {n_p} (R1 compared them with Git), the {n_a} it adds among them; the 5.18.102 files this release must not\n"
            "# touch — ECO Bank's module, its link on the CEO page, the Cashbook's service, its page, its tab, its export and its routes,\n"
            "# api/index.php, cron_quote_wa.php, the quoting files 5.18.103 keeps (QuoteOrganization, QuoteTaxLine, TenantProfile,\n"
            "# PublicPriceFeed, UcrmQuoteLinks), lib/SalesNumbersAdmin.php and its page and the rest — are still 5.18.102's by theirs (webhook.php\n",
            why="Q-CA1 comment")
    doc.sub("# BA1 — since 5.18.97 (SPEC §2.10b A1): migration 088 as installed — 5.18.102 adds no migration and writes none of 088's\n",
            "# BA1 — since 5.18.97 (SPEC §2.10b A1): migration 088 as installed — 5.18.103 adds no migration and writes none of 088's\n",
            why="BA1 comment")
    doc.sub("# (check_088). Then ECO Bank's surface, as installed — unchanged by 5.18.102 (Q-G1, Q-CA1): its five files, the tab and its\n",
            "# (check_088). Then ECO Bank's surface, as installed — unchanged by 5.18.103 (Q-G1, Q-CA1) but for public.php, whose tab\n"
            "# tables gain the uCRM catalogue's lines while ECO Bank's stay as they were: its five files, the tab and its\n",
            why="BA1 surface comment")
    doc.sub("# R7 — the database (5.18.95 to 5.18.102): 5.18.102 adds no migration, and its Cashbook reads write nothing to\n",
            "# R7 — the database (5.18.95 to 5.18.103): 5.18.103 adds no migration, and its Cashbook reads write nothing to\n", why="R7 head")
    doc.sub("# application or flag on a lead, and 5.18.102's own keys on Uganda applications (quote_items, quote_items_total,\n"
            "# quote_lines_source 'ucrm-5.18.102', quote_lines_read_at, install_by, quote_sale_cart, quote_sale_kit_qty,\n"
            "# quote_readback, quote_mismatch, and the refusals P1–P3 in quote_refused: docs/71 §F.3, §F.5) — and nothing in this\n",
            "# application or flag on a lead, 5.18.102's own keys on Uganda applications (quote_items, quote_items_total,\n"
            "# quote_lines_source 'ucrm-5.18.102', quote_lines_read_at, install_by, quote_sale_cart, quote_sale_kit_qty,\n"
            "# quote_readback, quote_mismatch, and the refusals P1–P3 in quote_refused: docs/71 §F.3, §F.5), and 5.18.103's — a\n"
            "# web-direct application's quote_item_ids, quote_unpriced_why and quote_lines_source 'ucrm-direct-5.18.103' (docs/72\n"
            "# §G.7), and the uCRM catalogue screen's table ucrm_catalogue (§G.3) — and nothing in this\n",
            why="R7 keys")
    doc.sub("# 5.18.90's (and 5.18.101's code, $BASE_ROOT, run beside it, must read so",
            "# 5.18.90's (and 5.18.102's code, $BASE_ROOT, run beside it, must read so", why="R13 comment")
    doc.sub("# 5.18.101's prompt ($BASE_ROOT's, run beside it;", "# 5.18.102's prompt ($BASE_ROOT's, run beside it;", why="R16 comment")
    doc.sub("as A22 read the pin's — a regression: 5.18.102 changes none of\n# the files they live in, and must leave all seven in place.",
            "as A22 read the pin's — a regression: 5.18.103 changes one of\n# the files they live in, public.php (its tab tables, not the repair page), and must leave all seven in place.",
            why="R23a–R23g comment")
    doc.subs([
        ("# code (5.18.101's, from Git) on the same copy equals D2 = A's SQL too,", "# code (5.18.102's, from Git) on the same copy equals D2 = A's SQL too,"),
        ("# BA2) — 5.18.102 changes no figure;", "# BA2) — 5.18.103 changes no figure;"),
        ("# C1–C5 again, 5.18.101's code from Git and the installed (CA2);", "# C1–C5 again, 5.18.102's code from Git and the installed (CA2);"),
        ("(its banner 5.18.96's: 5.18.97 through 5.18.102 do not change it)", "(its banner 5.18.96's: 5.18.97 through 5.18.103 do not change it)"),
        ("C1–C5 again, on this NEW copy, with 5.18.101's code from Git and the\n", "C1–C5 again, on this NEW copy, with 5.18.102's code from Git and the\n"),
        ("as A24: the installed code against 5.18.101's (from Git), on the same\n", "as A24: the installed code against 5.18.102's (from Git), on the same\n"),
    ], why="R23, CA2 and R26 comments")
    doc.sub("# carries 5.18.102's catalogue through the installed resolver, judged below: QL-CA1, QL-CA2, QL-LK1). An --after-only run\n",
            "# carries the salesPerson attribute and, since 5.18.103, what the forms offer through the installed offered(), judged\n"
            "# below: QL-CA2, QC-CA1). An --after-only run\n", why="Q-CA2 comment")
    doc.sub("# QS-CA1 — 5.18.101's, carried, after the copy: the installed sign-off as text, which 5.18.102 does not change.\n",
            "# QS-CA1 — 5.18.101's, carried, after the copy: the installed sign-off as text, which 5.18.102 and 5.18.103 do not change.\n",
            why="QS-CA1 comment")

    # ── RB ──
    doc.sub("# 5.18.102's change is gone (below: 5.18.101's files by sha256 and the 55 it keeps by theirs; the 31 files it adds left\n"
            "# on disk and reached by nothing, QL-RB1; the uCRM links an admin made left in the store, counted, and named by no\n"
            "# installed file, and the applications 5.18.102 could not confirm a quotation for counted, QL-RB3 (D14 §R.7.4); the\n"
            "# applications waiting for uCRM with no plan price recorded counted again, QL-RB2; 5.18.101's sign-off, unchanged, its\n"
            "# owner rule and its quoting code in place, and cash in hand on a copy, D2 = A as before);\n",
            f"# 5.18.103's change is gone (below: 5.18.102's files by sha256 and the {n_k} it keeps by theirs; the {n_a} files it adds left\n"
            "# on disk and reached by nothing, QC-RB1; the uCRM catalogue's table left in the store, counted, and named by no\n"
            "# installed file, QC-RB1; the web-direct applications with no quotation counted again, QC-RB2, their ids on the sheet;\n"
            "# 5.18.101's sign-off, unchanged, 5.18.100's owner rule and 5.18.102's quoting code in place, and cash in hand on a\n"
            "# copy, D2 = A as before);\n",
            why="RB header")
    doc.sub("of the $N_ADDED files it adds are on disk (deploy-hybrid.sh never deletes), reached by nothing (QL-RB1, below)\"",
            "of the $N_ADDED files it adds are on disk (deploy-hybrid.sh never deletes), reached by nothing (QC-RB1, below)\"",
            why="RB files ok: the check's name")
    qc_txt = ", ".join(q_changed[:-1]) + " and " + q_changed[-1]
    qk_txt = ", ".join(["lib/QuoteOrganization.php"] + q_kept) + ", the Uganda profile and tools/set_config.php"
    n_q = len(q_changed) + len(QUOTING_OTHER)
    doc.sub("# 5.18.99's quoting code is in place: the rollback target has it. 5.18.102 changed nine of its files — six of the seven\n"
            "# below that reach uCRM (all but lib/UcrmLeadSync.php), includes/api/api_products_admin.php, tabs/sales/kyc_form.php\n"
            "# and tabs/sales/applications.php (docs/71 §P.3) — and each is 5.18.101's again by its pinned sha256 (above);\n"
            "# lib/QuoteOrganization.php, lib/UcrmLeadSync.php, the Uganda profile and tools/set_config.php it never changed, each\n"
            "# kept by its own (below).",
            f"# 5.18.99's quoting code is in place: the rollback target has it. 5.18.103 changed {words(n_q)} of its files — {words(len(q_changed))} of the\n"
            f"# seven below that reach uCRM ({qc_txt}),\n"
            "# includes/api/api_products_admin.php, tabs/sales/kyc_form.php and tabs/sales/applications.php (docs/72 §G.12) — and\n"
            "# each is 5.18.102's again by its pinned sha256 (above);\n"
            f"# {qk_txt} it never changed, each\n"
            "# kept by its own (below).",
            why="RB quoting code comment")
    doc.sub("# and after the rollback alike; 5.18.100 and 5.18.101 wrote none of their own. 5.18.102 writes its own on Uganda\n"
            "# applications (docs/71 §F.3, §F.5) — its refusals P1–P3 in the same quote_refused, counted here with 5.18.99's R1–R7,\n"
            "# and its lines, their source and who installs — and the rollback leaves every one as written; the applications\n"
            "# waiting for uCRM with no plan price recorded are QL-RB2's (below).",
            "# and after the rollback alike; 5.18.100 and 5.18.101 wrote none of their own. 5.18.102 writes its own on Uganda\n"
            "# applications (docs/71 §F.3, §F.5) — its refusals P1–P3 in the same quote_refused, counted here with 5.18.99's R1–R7,\n"
            "# and its lines, their source and who installs — and so does 5.18.103 on its web-direct ones (docs/72 §G.7); the\n"
            "# rollback leaves every one as written; the web-direct applications with no quotation are QC-RB2's (below).",
            why="RB quoting records comment")
    doc.sub("note \"RB the quoting records, as they are — 5.18.99's code's and $EXPECTED_VERSION's own (its refusals P1–P3 among them),",
            "note \"RB the quoting records, as they are — 5.18.99's code's, $BASELINE_VERSION's and $EXPECTED_VERSION's own (their refusals P1–P3 among them),",
            why="RB quoting records note")
    doc.sub("note \"RB the quoting records could not be counted ($RBQ_S) — $EXPECTED_VERSION's refusals P1–P3 among them; the rollback rewrites none\"",
            "note \"RB the quoting records could not be counted ($RBQ_S) — $BASELINE_VERSION's and $EXPECTED_VERSION's refusals P1–P3 among them; the rollback rewrites none\"",
            why="RB quoting records unread")
    doc.sub("# 5.18.102 never changed webhook.php (QS_TEXT_BASE = QS_TEXT_PIN), so the rollback leaves the line it found.\n",
            "# 5.18.102 and 5.18.103 never changed webhook.php (QS_TEXT_BASE = QS_TEXT_PIN), so the rollback leaves the line it found.\n",
            why="qs_rb call comment")
    doc.sub("# RB — 088 after the rollback: 5.18.102 added no migration and wrote none of 088's tables,",
            "# RB — 088 after the rollback: 5.18.103 added no migration and wrote none of 088's tables,", why="RB 088 comment")
    # (RB's last note, the line that begins 'note "RB the code is $BASELINE_VERSION again.', is Part D's: it replaces the line whole.)

    # ── the result, in the body Part F judges (from `set -uo pipefail` to the F summary; the header and F are Part D's) ──
    t = doc.t
    assert t.count("\nset -uo pipefail\n") == 1 and t.count('\nhdr "F. Summary"\n') == 1
    body = t[t.index("\nset -uo pipefail\n"): t.index('\nhdr "F. Summary"\n')]
    assert body.count("f2055ba") == 0, "the old baseline's commit is still named in the body"
    for s in ("(5.18.101 here)", "5.18.101's code from Git", "the installed, 5.18.101's", "live 5.18.101",
              "QL-RB1, below", "an --after-only's QL-LK1 fails on it", "$BASELINE_VERSION has no link screen",
              "rolled-back run of $EXPECTED_VERSION left there"):
        assert body.count(s) == 0, f"partF result: {s!r} still in the body"
    doc.must("QC-RB1, below", 1, why="partF result")
    doc.must("# 5.18.103's change is gone (below: 5.18.102's files by sha256", 1, why="partF result")
