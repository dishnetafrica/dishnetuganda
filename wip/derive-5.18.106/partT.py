# Part T — the carried texts (root docs/75 §L.6, §L.7): what 5.18.105's script said of the release it deploys or rolls
# back, re-pointed now that 7835906 (5.18.105) is live and the rollback target and bf29cbd (5.18.106) the release.
#   T1 §L.6's literal 5.18.104: where a carried text uses $BASELINE_VERSION for the origin of 089–091 it becomes the
#      literal 5.18.104 — exactly S105:7492, 7495, 7625, 7626, 8527, 9185 (its second use), 9229, 9236, 10902 (its second
#      use), 10903 and 14638 (partB re-pointed S105:10965, asserted here and not touched again); where it means the live
#      release (S105:7567, 8472, 8492, 9163, 9164, 9181, 9185's first use, 10902's first use) it stays — asserted unchanged.
#      Each line is read off the source by its number (the source is the one ctx["src_sha"] names) and must stand in the
#      result exactly once, untouched by an earlier part, before it is changed.
#   T2 the carried texts outside partR's regions, the header and the summary that named 5.18.103, 5.18.104, 5.18.105 or
#      994d660 as the live release, the release deployed or the rollback target (wave2-notes' lists for partT: db_judge,
#      vn_rb_after, of_rb_after, ac_rb_after, the rbn reading, qf_stage's comment, the two stage headings — and a sweep of
#      the rest): BASE_DIFFERS, BR1, Q-G1, Q-CA1, BA1, R7, R23, the cash readings' "old code and new", QC-RB2 before and
#      after and in V1, QS, TN, A22, V6, QF-G4's retirement note, the LK rule's title. Comments and printed words only —
#      no check, no variable and no logic changes here. Every fact a new text states is read off Git first (each file it
#      names kept, the same at 7835906 and the pin, outside 5.18.106's delta) or taken from partA's counts.
import re, os, hashlib
from d106lib import git, sha_at

V104, V105, V106 = "5.18.104", "5.18.105", "5.18.106"
# §L.6 (S105 line, the use of $BASELINE_VERSION to make literal (1-based), the uses the line holds)
LITERAL = [(7492, 1, 1), (7495, 1, 1), (7625, 1, 1), (7626, 1, 1), (8527, 1, 1), (9185, 2, 2), (9229, 1, 1), (9236, 1, 1),
           (10902, 2, 2), (10903, 1, 1), (14638, 1, 1)]
LIVE = [7567, 8472, 8492, 9163, 9164, 9181]          # $BASELINE_VERSION means the live release: unchanged
DONE_BY_B = 10965                                    # partB's A0 line (asserted changed, not touched here)
# files the new texts call kept, unchanged by 5.18.106 (each asserted in the kept list and the same at 7835906 and the pin)
KEPT_NAMED = ["lib/UcrmCatalogue.php", "tabs/sales/ucrm_catalogue.php", "lib/UcrmQuoteLines.php", "lib/UcrmQuoteLinks.php",
              "lib/KycService.php", "lib/KycCrmSync.php", "lib/QuotationService.php", "webhook.php", "cron_quote_wa.php",
              "includes/post/post_kyc.php", "lib/SalesNumbersAdmin.php", "public.php", "includes/post_handlers.php",
              "tools/cash_in_hand.php", "lib/CashbookService.php", "lib/QuoteAgentCopy.php", "tabs/sales/kyc_form.php",
              "tabs/sales/send_quote.php", "tabs/sales/applications.php", "api/index.php", "lib/QuoteOrganization.php",
              "lib/QuoteTaxLine.php", "lib/TenantProfile.php", "lib/PublicPriceFeed.php", "lib/UcrmClientTarget.php",
              "lib/UcrmLeadSync.php", "lib/PluginQuotePdf.php", "includes/api/api_cron_debug.php",
              "includes/api/api_products_admin.php", "tools/set_config.php", "cron/event_processor.php",
              "lib/StaffJobsGate.php", "lib/MigrationRunner.php"]


def _rule(title, width):
    head = "# ── " + title + " "
    if len(head) >= width:
        raise SystemExit(f"PART T: the rule title is too long for its line: {title[:80]!r}")
    return head + "─" * (width - len(head))


def apply(doc, ctx):
    repo, plugin, base, pin = ctx["repo"], ctx["plugin"], ctx["base_full"], ctx["pin_full"]
    n_a, n_c, n_k, n_pin = ctx["n_added"], ctx["n_changed"], ctx["n_kept"], ctx["n_pin"]
    kept, d106 = set(ctx["kept"]), set(ctx["added"]) | set(ctx["changed"])
    show = lambda c, p: git(repo, "show", f"{c}:{plugin}/{p}").decode()
    for c, v in ((base, V105), (pin, V106)):
        m = re.search(r'^    "version": "([0-9.]+)",$', show(c, "manifest.json"), re.M)
        if not m or m.group(1) != v:
            raise SystemExit(f"PART T: manifest.json at {c[:7]} says {m.group(1) if m else '?'}, expected {v}")
    for f in KEPT_NAMED:
        if f not in kept or f in d106 or sha_at(repo, base, f"{plugin}/{f}") != sha_at(repo, pin, f"{plugin}/{f}"):
            raise SystemExit(f"PART T: {f} is not a kept file, the same at 7835906 and the pin")
    if any(f.startswith("tabs/") for f in d106) or any("ashbook" in f for f in d106 if not f.startswith("tests/")):
        raise SystemExit("PART T: 5.18.106 changes a screen or a Cashbook file — the texts below could not say it changes none")
    if any(f.startswith("migrations/") for f in d106):
        raise SystemExit("PART T: 5.18.106 carries a migration")
    if (n_a, n_c) != (len(ctx["added"]), len(ctx["changed"])) or n_pin != n_a + n_c:
        raise SystemExit("PART T: partA's counts do not add up")

    # ── T1: the source's own lines, by number (§L.6) ──
    src_path = os.path.join(ctx["dev"], "scripts/deploy-5.18.105.sh")
    src = open(src_path, encoding="utf-8").read()
    if hashlib.sha256(src.encode()).hexdigest() != ctx["src_sha"]:
        raise SystemExit("PART T: the source read for §L.6's line numbers is not the one the derivation runs on")
    S = src.split("\n")
    BV = "$BASELINE_VERSION"
    for n, use, uses in LITERAL:
        old = S[n - 1]
        if old.count(BV) != uses:
            raise SystemExit(f"PART T: S105:{n} holds $BASELINE_VERSION {old.count(BV)} times, not {uses}")
        if not re.search(r"089|090|091|migration", old):
            raise SystemExit(f"PART T: S105:{n} does not speak of 089–091 or a migration: {old[:100]!r}")
        if doc.t.count("\n" + old + "\n") != 1 or doc.t.count(old) != 1:
            raise SystemExit(f"PART T: S105:{n} does not stand in the result exactly once, untouched: {old[:100]!r}")
        parts = old.split(BV)
        new = BV.join(parts[:use]) + V104 + BV.join(parts[use:])
        if new.count(BV) != uses - 1 or new.count(V104) != old.count(V104) + 1:
            raise SystemExit(f"PART T: S105:{n}'s rewrite is not one use made literal")
        doc.sub(old, new, 1, why=f"§L.6 literal 5.18.104 (S105:{n})")
    if doc.t.count(S[DONE_BY_B - 1]) != 0 or "— and no migration (5.18.104's $MIG104_N, 089–091," not in doc.t:
        raise SystemExit("PART T: S105:10965 is not as partB left it")
    for n in LIVE:
        if S[n - 1].count(BV) < 1 or doc.t.count(S[n - 1]) != 1:
            raise SystemExit(f"PART T: S105:{n} (the live release) is not in the result unchanged")
    for n, keep in ((9185, "(a copy of this live $BASELINE_VERSION book, 091 on it as 5.18.104 left it;"),
                    (10902, "none added since $BASELINE_VERSION; 5.18.104's own, which the live book carries:")):
        doc.must(keep, 1, why=f"S105:{n}'s first use stays the live release")

    # ── T2: the carried texts ──
    E = [
        # the cash readings: the old code (the baseline's, from Git) and the new (the pin's)
        ("# pin's (D2), P0; ECO Bank through the live code's classes (G3) and the pin's (BK2); the copy checks C1–C5, 5.18.103's\n"
         "# code and 5.18.104's from Git; and the notes N1–N4. r23: 5.18.103's code from Git (TODAY) and the installed (D2), P0, the\n"
         "# installed tool; ECO Bank through the installed code (BA2: BK2 and G3 again); the copy checks with 5.18.103's code from\n",
         "# pin's (D2), P0; ECO Bank through the live code's classes (G3) and the pin's (BK2); the copy checks C1–C5, 5.18.105's\n"
         "# code and 5.18.106's from Git; and the notes N1–N4. r23: 5.18.105's code from Git (TODAY) and the installed (D2), P0, the\n"
         "# installed tool; ECO Bank through the installed code (BA2: BK2 and G3 again); the copy checks with 5.18.105's code from\n"),
        ("  # 5.18.103's and 5.18.104's code from Git, in R23 5.18.103's from Git and the installed.",
         "  # 5.18.105's and 5.18.106's code from Git, in R23 5.18.105's from Git and the installed."),
        ("# Then, on the same copy, ECO Bank: 088 as the copy holds it (BR2), the switch (BR3), both cards through the LIVE\n"
         "# 5.18.103 classes (G3), the offered accounts (BN5) and the gate per switch state through the pin's (BK2); the copy checks\n"
         "# C1–C5, 5.18.103's code and 5.18.104's from Git on ONE copy of the master;",
         "# Then, on the same copy, ECO Bank: 088 as the copy holds it (BR2), the switch (BR3), both cards through the LIVE\n"
         "# 5.18.105 classes (G3), the offered accounts (BN5) and the gate per switch state through the pin's (BK2); the copy checks\n"
         "# C1–C5, 5.18.105's code and 5.18.106's from Git on ONE copy of the master;"),
        ("project: 5.18.104 does not change cash in hand;", "project: 5.18.106 does not change cash in hand;"),
        ("  # The pin's code reads the cards as the live classes do — 5.18.104 changes none of the files they are read through (A0, Q-G1).",
         "  # The pin's code reads the cards as the live classes do — 5.18.106 changes none of the files they are read through (A0, Q-G1)."),
        ("carried: 5.18.103's code and 5.18.104's, both from Git, on ONE copy of the",
         "carried: 5.18.105's code and 5.18.106's, both from Git, on ONE copy of the"),
        ("  # both codes against each other: 5.18.104 changes no file of the Cashbook's, so they must be the same.",
         "  # both codes against each other: 5.18.106 changes no file of the Cashbook's, so they must be the same."),
        ("# code (5.18.103's, from Git) on the same copy equals D2 = A's SQL too,", "# code (5.18.105's, from Git) on the same copy equals D2 = A's SQL too,"),
        ("# BA2) — 5.18.104 changes no figure;", "# BA2) — 5.18.106 changes no figure;"),
        ("# C1–C5 again, 5.18.103's code from Git and the installed (CA2);", "# C1–C5 again, 5.18.105's code from Git and the installed (CA2);"),
        ("(its banner 5.18.96's: 5.18.97 through 5.18.104 do not change it)", "(its banner 5.18.96's: 5.18.97 through 5.18.106 do not change it)"),
        # QC-RB2 — the rollback target handles the web-direct applications as the release does
        ("a record: 5.18.103, the\n# rollback target, handles them exactly as 5.18.104 does",
         "a record: 5.18.105, the\n# rollback target, handles them exactly as 5.18.106 does"),
        ("    // counted for the record: 5.18.103, the rollback target, retries and quotes each exactly as 5.18.104 does (the same",
         "    // counted for the record: 5.18.105, the rollback target, retries and quotes each exactly as 5.18.106 does (the same"),
        ("# made, counted for the record — the rollback changes none of them: 5.18.103 handles each exactly as 5.18.104 does —",
         "# made, counted for the record — the rollback changes none of them: 5.18.105 handles each exactly as 5.18.106 does —"),
        ("a record: 5.18.104 handles each as\n  # 5.18.105 does, so the rollback leaves none to quote by hand;",
         "a record: 5.18.105 handles each as\n  # 5.18.106 does, so the rollback leaves none to quote by hand;"),
        ("the rollback changes nothing for them: 5.18.104 retries and quotes each exactly as 5.18.105 does (the same KycService and UcrmQuoteLines)",
         "the rollback changes nothing for them: 5.18.105 retries and quotes each exactly as 5.18.106 does (the same KycService and UcrmQuoteLines)"),
        ("— 5.18.105 changes nothing of how a web-direct application is quoted, so whichever release now serves",
         "— 5.18.106 changes nothing of how a web-direct application is quoted, so whichever release now serves"),
        ("read by 5.18.104 again after a 5.18.105\n# rollback,", "read by 5.18.105 again after a 5.18.106\n# rollback,"),
        ("the rollback rewrites no application, and 5.18.104 keeps each on the direct rules\n# exactly as 5.18.105 did",
         "the rollback rewrites no application, and 5.18.105 keeps each on the direct rules\n# exactly as 5.18.106 did"),
        ("— 5.18.104 keeps each on the direct rules exactly as 5.18.105 did (retried by its uCRM ids;",
         "— 5.18.105 keeps each on the direct rules exactly as 5.18.106 did (retried by its uCRM ids;"),
        ("which 5.18.103's and 5.18.104's code write too; 5.18.104 adds none of these kinds)",
         "which 5.18.103's through 5.18.106's code write too; none of 5.18.104, 5.18.105 and 5.18.106 adds any of these kinds)"),
        ("# of what is offered is a note: 5.18.105 changes nothing of the catalogue,", "# of what is offered is a note: 5.18.106 changes nothing of the catalogue,"),
        # QF / LK / LM — what this script writes, and the stage headings (partUJ's and partL's notes for partT)
        ("Nothing here writes the live book: 5.18.105 never runs the switch tool (root docs/74 §H.4.3),",
         "Nothing here writes the live book: this script never runs the switch tool (root docs/74 §H.4.3), and its one write — a\n"
         "# rollback's followup_auto_send '0', AS-RB1 — is not QF's (root docs/75 §L.7),"),
        ("— 994d660's own, which carry on —", "— 7835906's own, which carry on —"),
        ("# qf_stage — QF-G1–QF-G3, LK-G1 and QF-N1–QF-N7, stage A, before anything changes (a deploy and --check): on copies,\n",
         "# qf_stage — QF-G1–QF-G3, LK-G1, CS-G1, QF-N1–QF-N7 and U1–U5 (CS-G1 and U1–U5 since 5.18.106, root docs/75 §L.3,\n"
         "# §L.4), stage A, before anything changes (a deploy and --check): on copies,\n"),
        ("the one form 5.18.105 accepts, root docs/74 §H.4.4)", "the one form 5.18.105 and 5.18.106 accept, root docs/74 §H.4.4)"),
        ("  # (QF-G4, 5.18.104's \"every key unset\", is retired: 5.18.105 keeps every switch as found — LK-G1, above, reads them.)\n",
         "  # (QF-G4, 5.18.104's \"every key unset\", is retired: since 5.18.105 the deploy keeps every switch as found — LK-G1, above,\n"
         "  # reads them — and since 5.18.106 CS-G1 refuses a claim switch present, root docs/75 §L.3.)\n"),
        ("  # 5.18.105: 5.18.104 is live, so 090 is in the copy's ledger already", "  # 5.18.105 and 5.18.106: 090 is 5.18.104's, in the live book's ledger, so in the copy's already"),
        ("hdr \"R. The masked lines, installed ($EXPECTED_VERSION's LM;", "hdr \"R. The masked lines, installed (5.18.105's LM, kept by $EXPECTED_VERSION;"),
        # the RB notes of Part V, Part O and Part P: they continue under 7835906
        ("# under 994d660 as Part V's switches say. Read on a copy. 5.18.105 retires VN-RB1–VN-RB4",
         "# under 7835906 as Part V's switches say. Read on a copy. 5.18.105 retired VN-RB1–VN-RB4"),
        ("# 994d660 as Part O's switches say — an offer running goes on,", "# 7835906 as Part O's switches say — an offer running goes on,"),
        ("# Read on a copy of the book. 5.18.105 retires OF-RB1,", "# Read on a copy of the book. 5.18.105 retired OF-RB1,"),
        ("continue under 994d660 as its\n# switch (agent-cc) says. 5.18.105 retires ac_rb_pre and AC-RB1",
         "continue under 7835906 as its\n# switch (agent-cc) says. 5.18.105 retired ac_rb_pre and AC-RB1"),
        ("        // 5.18.105 (root docs/74 §H.4.4): the baseline is 5.18.104, which carries P7 as the pin does — both trees must hold it",
         "        // 5.18.105 (root docs/74 §H.4.4), carried: the baseline — 5.18.105 since 5.18.106 — carries P7 as the pin does: both trees must hold it"),
        ("(5.18.104, which this rollback leaves, reads both collections as its switch says)",
         "(5.18.105, which this rollback puts back, reads both collections as its switch says)"),
        ("# 5.18.105: the rollback leaves 5.18.104's list, which is the pin's (EP_PIN_LIST)",
         "# 5.18.105 and 5.18.106: the rollback leaves 5.18.104's list, which 7835906 and the pin both carry (EP_PIN_LIST)"),
        # TN, QS, A22, V6, Q-CA5
        ("owners() where the code has it (5.18.100 through 5.18.104)", "owners() where the code has it (5.18.100 through 5.18.106)"),
        ("never run), carried by 5.18.102, 5.18.103 and 5.18.104 ─", "never run), carried by 5.18.102 through 5.18.106 ─"),
        ("(Part P, root docs/73 §H.5.8.2), never\n# these lines:",
         "(Part P, root docs/73 §H.5.8.2), 5.18.105\n# only in its log lines (LM) and 5.18.106 not at all — never these lines:"),
        ("(Part P, root docs/73 §H.5.8.2), never these lines\n",
         "(Part P, root docs/73 §H.5.8.2), 5.18.105 only in its log lines and 5.18.106 not at all, never these lines\n"),
        ("# 5.18.96; 5.18.104 changes one of the files they live in, public.php (its tab tables — My quotes, the offers screen —\n"
         "# not the repair page), and must keep every",
         "# 5.18.96; 5.18.104 changed one of the files they live in, public.php (its tab tables — My quotes, the offers screen —\n"
         "# not the repair page), which 5.18.105 and 5.18.106 keep byte for byte; each must keep every"),
        ("a regression: 5.18.104 changes one of\n# the files they live in, public.php (its tab tables — My quotes, the offers screen — not the repair page), and must leave all seven in place.",
         "a regression: 5.18.104 changed one of\n# the files they live in, public.php (its tab tables — My quotes, the offers screen — not the repair page), which 5.18.105 and 5.18.106 keep byte for byte; each must leave all seven in place."),
        ("(and so does the rollback target, 5.18.104).", "(and so does the rollback target, 5.18.105)."),
        ("changed again by 5.18.102 and 5.18.103 and kept byte for byte by 5.18.104)",
         "changed again by 5.18.102 and 5.18.103 and kept byte for byte by 5.18.104 through 5.18.106)"),
        # the database: 5.18.106 adds no migration
        ("(5.18.105 adds no migration: 091 last before and after, root docs/74 §H.4.6)",
         "(5.18.106 adds no migration: 091 last before and after, root docs/75 §L.2)"),
        ("5.18.104 adds three migrations of its own\n# (089, 090, 091 — the sales follow-up's tables, none of them a bank table; MIG104 below) and changes no bank\n# file:",
         "5.18.104 added three migrations of its own\n# (089, 090, 091 — the sales follow-up's tables, none of them a bank table; MIG104 below) and changed no bank\n# file, and 5.18.105 and 5.18.106 add none:"),
        ("and 5.18.104's three (089–091) write none of its tables.", "and 5.18.104's three (089–091) write none of its tables; 5.18.105 and 5.18.106 add none."),
        ("# BA1 — since 5.18.97 (SPEC §2.10b A1): migration 088 as installed — 5.18.104 adds 089–091 and writes none of 088's\n"
         "# tables: applied before this run (BR2),",
         "# BA1 — since 5.18.97 (SPEC §2.10b A1): migration 088 as installed — 5.18.104 added 089–091 and wrote none of 088's\n"
         "# tables, and 5.18.105 and 5.18.106 add none: applied before this run (BR2),"),
        ("# (check_088). Then ECO Bank's surface, as installed — unchanged by 5.18.104 (Q-G1, Q-CA1) but for public.php, whose tab\n"
         "# tables gain My quotes and the offers screen, and includes/post_handlers.php, which requires post_offers.php, while ECO\n"
         "# Bank's lines stay as they were:",
         "# (check_088). Then ECO Bank's surface, as installed — unchanged by 5.18.106 (Q-G1, Q-CA1); 5.18.104 changed public.php,\n"
         "# whose tab tables gained My quotes and the offers screen, and includes/post_handlers.php, which requires\n"
         "# post_offers.php, while ECO Bank's lines stayed as they were:"),
        ("# R7 — the database (5.18.95 to 5.18.105): 5.18.105 adds no migration — the ledger reads 091 last before and after (root\n"
         "# docs/74 §H.4.6); 5.18.104 added 089–091,",
         "# R7 — the database (5.18.95 to 5.18.106): 5.18.106 adds no migration — the ledger reads 091 last before and after (root\n"
         "# docs/75 §L.2; a rollback writes one settings key, AS-RB1, and R7 is read right after it); 5.18.104 added 089–091,"),
        # BASE_DIFFERS, BR1, Q-G1, Q-CA1: the live release is 5.18.105 (7835906), the release 5.18.106
        ("# Since 5.18.91: with the container's record on the rollback target (5.18.104 here), every file this release changes\n",
         "# Since 5.18.91: with the container's record on the rollback target (5.18.105 here), every file this release changes\n"),
        ("and no 5.18.104 file names any\n# of them (EXPECTED_ADDED: 5.18.105's 43, its tests;",
         f"and no 5.18.105 file names any\n# of them (EXPECTED_ADDED: 5.18.106's {n_a}, its tests;"),
        ("# BR1 — since 5.18.97 (SPEC §2.10b R1): the installed plugin is live 5.18.104 — every file 994d660 tracks under the plugin,\n"
         "# data/ aside, byte for byte (sha256 on the host, from git archive into this run's folder, removed at once), not only the\n"
         "# files this release changes (the record and the manifest say 5.18.104 above; BASE_DIFFERS read this release's own).\n"
         "# Files on disk beyond them are not asked about: deploy-hybrid.sh never deletes, so an earlier 5.18.105 and its\n"
         "# rollback leave the 43 files it adds (its tests) behind — counted and said (BR1_LEFT, over EXPECTED_ADDED);\n"
         "# no 5.18.104 file names them (QC-RB1), and the copy replaces them. 5.18.105 adds no migration: 089–091, applied at\n"
         "# 5.18.104's first boot, are in the ledger and are not run again.\n",
         "# BR1 — since 5.18.97 (SPEC §2.10b R1): the installed plugin is live 5.18.105 — every file 7835906 tracks under the plugin,\n"
         "# data/ aside, byte for byte (sha256 on the host, from git archive into this run's folder, removed at once), not only the\n"
         "# files this release changes (the record and the manifest say 5.18.105 above; BASE_DIFFERS read this release's own).\n"
         "# Files on disk beyond them are not asked about: deploy-hybrid.sh never deletes, so an earlier 5.18.106 and its\n"
         f"# rollback leave the {n_a} files it adds (its tests) behind — counted and said (BR1_LEFT, over EXPECTED_ADDED);\n"
         "# no 5.18.105 file names them (QC-RB1), and the copy replaces them. 5.18.106 adds no migration: 089–091, applied at\n"
         "# 5.18.104's first boot, are in the ledger and are not run again.\n"),
        ("the live plugin is 5.18.104 — the container's record and\n# the manifest say so above, and (a deploy) BR1 has read every file 994d660 tracks",
         "the live plugin is 5.18.105 — the container's record and\n# the manifest say so above, and (a deploy) BR1 has read every file 7835906 tracks"),
        ("every file 5.18.105 changes (46: webhook.php and evo_webhook.php among them) is 5.18.104's\n"
         "# as installed (PREV_CHANGED_SHA256S), and every file 5.18.104 has that 5.18.105 must not touch (386, PREV_KEPT_SHA256S):",
         f"every file 5.18.106 changes ({n_c}: the four follow-up jobs and lib/FollowUpService.php among them) is\n"
         f"# 5.18.105's as installed (PREV_CHANGED_SHA256S), and every file 5.18.105 has that 5.18.106 must not touch ({n_k},\n"
         "# PREV_KEPT_SHA256S):"),
        ("code reads and 5.18.99's quoting files that 5.18.105 keeps (QuoteTaxLine and TenantProfile;",
         "code reads and 5.18.99's quoting files that 5.18.106 keeps (QuoteTaxLine and TenantProfile;"),
        ("lib/MigrationRunner.php among them; 5.18.104's ladder trio, held by sha256), unchanged. The 43 files 5.18.105 adds",
         f"lib/MigrationRunner.php among them; 5.18.104's ladder trio, held by sha256), unchanged. The {n_a} files 5.18.106 adds"),
        ("# for each of its 260 (R1 compared them with Git), the 215 it adds among them and webhook.php among the 45 it\n"
         "# changes; the 158 5.18.103 files this release must not touch — ECO Bank's module, its link on the CEO page, the Cashbook's\n"
         "# service, its page, its tab, its export and its routes, api/index.php, cron_quote_wa.php, the quoting files 5.18.104\n"
         "# keeps (QuoteOrganization, QuoteTaxLine, TenantProfile, PublicPriceFeed, UcrmQuoteLinks, UcrmQuoteLines, UcrmCatalogue),\n"
         "# lib/SalesNumbersAdmin.php and its page and the rest — are still 5.18.103's by theirs,",
         f"# for each of its {n_pin} (R1 compared them with Git), the {n_a} it adds among them (its tests) and the four follow-up\n"
         f"# jobs and lib/FollowUpService.php among the {n_c} it changes; the {n_k} 5.18.105 files this release must not touch — ECO\n"
         "# Bank's module, its link on the CEO page, the Cashbook's service, its page, its tab, its export and its routes,\n"
         "# api/index.php, cron_quote_wa.php, the quoting files 5.18.106 keeps (QuoteOrganization, QuoteTaxLine, TenantProfile,\n"
         "# PublicPriceFeed, UcrmQuoteLinks, UcrmQuoteLines, UcrmCatalogue), lib/SalesNumbersAdmin.php and its page and the rest\n"
         "# — are still 5.18.105's by theirs,"),
        # RB: the files put back, and the quoting code in place
        ("# 5.18.105's files are 5.18.104's again, each byte for byte — by Git's sha256 and by the one THIS SCRIPT pins for each\n"
         "# (PREV_CHANGED_SHA256S, Q-G1's set read again: 46) — and the 43 files it added stay on disk\n"
         "# (deploy-hybrid.sh never deletes, nor can the by-hand put-back). Git's loop below passes over them, since 5.18.104 has\n"
         "# none of them; QC-RB1 (below) proves nothing in 5.18.104 reaches them. The files 5.18.105 adds, as they are on disk now —\n",
         "# 5.18.106's files are 5.18.105's again, each byte for byte — by Git's sha256 and by the one THIS SCRIPT pins for each\n"
         f"# (PREV_CHANGED_SHA256S, Q-G1's set read again: {n_c}) — and the {n_a} files it added (its tests) stay on disk\n"
         "# (deploy-hybrid.sh never deletes, nor can the by-hand put-back). Git's loop below passes over them, since 7835906 has\n"
         "# none of them; QC-RB1 (below) shows that no file 7835906 tracks names them. The files 5.18.106 adds, as they are on\n"
         "# disk now —\n"),
        ("# 5.18.99's quoting code is in place: the rollback target has it. 5.18.105 changed one of its files that reach uCRM,\n"
         "# lib/QuotationService.php (its log lines only, LM), and it is 5.18.104's again by its pinned sha256\n"
         "# (above, PREV_CHANGED_SHA256S); lib/KycService.php,",
         "# 5.18.99's quoting code is in place: the rollback target has it. 5.18.106 changes none of its files that reach uCRM:\n"
         "# lib/QuotationService.php (5.18.105's, its log lines masked), lib/KycService.php,"),
        ("# it never changed, each kept by its own (PREV_KEPT_SHA256S, below).", "# — each kept by its own sha256 (PREV_KEPT_SHA256S, below)."),
    ]
    for old, new in E:
        doc.sub(old, new, 1, why="carried text: " + old.strip()[:50])

    # the stage-A call site of qf_stage and lm_stage (partL left its comment; partUJ the heading)
    doc.lines_between("  # QF-G1–QF-G4, QF-N1–QF-N7 — 5.18.104 (root docs/73 §H.2.15.1), before anything changes: the copy's shape, the one",
                      "  # the on-chase precondition's two strings in the pin's evo_webhook.php. Refusals; --check runs them too.",
        "  # QF-G1–QF-G3, QF-N1–QF-N7 — 5.18.104 (root docs/73 §H.2.15.1), before anything changes: the copy's shape, the one\n"
        "  # migration trial for every part (ruling R10) and the pin's hooks and events as text (QF-G4, the one stage-A rule for\n"
        "  # every part's keys, retired since 5.18.105: LK-G1 reads them as found); then the notes. 5.18.106 (root docs/75 §L.3,\n"
        "  # §L.4): CS-G1, the three claim switches absent, a refusal; U1–U5, the readings before the deploy, notes. On copies,\n"
        "  # through the PIN's code from Git (U5 on the live settings file and store row, read-only). A refusal stops here:\n"
        "  # nothing has changed. Counts, codes and key names in the log; the report's owners on the sheet. --check runs it too.\n"
        "  hdr \"A. The sales follow-up (5.18.104, Part F's QF, kept live by 5.18.105's LK; 5.18.106's CS-G1 and U1–U5; read-only, on copies through the pin's code)\"\n"
        "  qf_stage\n"
        "  # LM-G1, LM-G2 — 5.18.105 (root docs/74 §H.4.5), carried (root docs/75 §L.6): the masked lines in the pin, in 7835906\n"
        "  # and as installed, the control on 994d660, from Git, counted as blocks; the on-chase precondition's two strings in the\n"
        "  # pin's evo_webhook.php. Refusals; --check runs them too.", why="stage A: the sales follow-up's call site")

    # the LK rule's title: the deploy keeps every LADDER switch (the rollback's one write is not a ladder key)
    t_old = [l for l in doc.t.split("\n") if l.startswith("# ── 5.18.105: the ladder rule (LK; root docs/74 §H.4.3) — every switch kept exactly as found ")]
    if len(t_old) != 1:
        raise SystemExit(f"PART T: the LK rule's title found {len(t_old)} times")
    doc.line("# ── 5.18.105: the ladder rule (LK; root docs/74 §H.4.3) — every switch kept exactly as found ",
             _rule("5.18.105: the ladder rule (LK; root docs/74 §H.4.3) — every ladder switch kept exactly as found", len(t_old[0])),
             why="the LK rule's title")

    # ── the result: no carried text outside the header, partR's regions and the summary still names the old releases
    #    where the new ones are meant ──
    t = doc.t
    hdr_end = t.index("\nset -uo pipefail\n")
    summ = t.index("# Every command printed here stands alone on its line")
    body = t[hdr_end:summ]
    for s in ("5.18.103's code and 5.18.104's", "5.18.103 classes (G3)", "rollback target, handles them exactly as 5.18.104",
              "exactly as 5.18.104 does", "5.18.104 handles each as", "5.18.104 keeps each on the direct rules",
              "rollback target (5.18.104 here)", "the installed plugin is live 5.18.104", "the live plugin is 5.18.104",
              "every file 994d660 tracks", "continue under 994d660", "994d660's own, which carry on",
              "5.18.105 changes nothing of", "5.18.105 adds no migration", "5.18.104 does not change cash in hand",
              "5.18.104 changes no figure", "5.18.104 changes no file of the Cashbook's", "rollback target, 5.18.104",
              "which this rollback leaves", "5.18.105's files are 5.18.104's again", "5.18.105 never runs the switch tool",
              "$EXPECTED_VERSION's LM", "in the pin and in 994d660", "5.18.105 keeps every switch as found",
              "reached by nothing", "read by nothing", "writes nothing to the book"):
        if s in body:
            raise SystemExit(f"PART T: the body still says {s!r}")
    for n, use, uses in LITERAL:
        old = S[n - 1]
        parts = old.split(BV)
        doc.must(BV.join(parts[:use]) + V104 + BV.join(parts[use:]), 1, why=f"S105:{n} as §L.6 makes it")
