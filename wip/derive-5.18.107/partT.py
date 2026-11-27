# Part T — the carried texts (root docs/76 §T.2): what 5.18.106's script said of the release it deploys or rolls back,
# re-pointed now that bf29cbd (5.18.106) is live and the rollback target and 771a3c1 (5.18.107, §T.15) the release.
#   T1 the carried texts outside the parts' own regions, the header and the summary that named 5.18.105, 5.18.106 or
#      7835906 as the live release, the release deployed or the rollback target: 088's and BA1's migrations, BK3's
#      links, PERSONA's comment (DF-1), the cash probes' "5.18.105's code and the pin's", V4, the cash readings' old
#      code and new, QC-RB2 before and after and in V1, the catalogue's note, QF-G1's form, QF-G4's retirement note,
#      Part V's, Part O's and Part P's RB notes, TN, QS, A22 and R23 (public.php, which 5.18.107 changes again), the
#      put-back's sentence, BASE_DIFFERS, BR1 (and its after-a-rollback note, DF-9), Q-G1, LM's call site, CS-B1's
#      retirement at LK-B1, V6, Q-CA5, R7, RB's quoting code (§T.2: kyc_form.php and set_config.php are among the 75)
#      and the RB quoting records. Comments and printed words only — no check, no variable and no logic changes here.
#   T2 every fact a new text states is read off Git first: each file it calls kept is in PREV_KEPT_SHA256S, outside
#      5.18.107's delta and the same at bf29cbd and the pin; each file it calls changed is in the delta; the one
#      migration added is 092; no Cashbook, cash or bank file changes; public.php changes only in its tab tables and
#      navigation.php outside the bank block; the web-direct quoting files name no QuoteTaxLine; PERSONA_PHP builds no
#      rule 8, quotation or turn.
import re
from d107lib import git, sha_at, delta

V106, V107 = "5.18.106", "5.18.107"
# files the new texts call kept, unchanged by 5.18.107 (each asserted in PREV_KEPT_SHA256S, outside the delta, and the same
# at bf29cbd and the pin)
KEPT_NAMED = ["lib/KycService.php", "lib/UcrmQuoteLines.php", "lib/KycCrmSync.php", "lib/QuotationService.php", "webhook.php",
              "cron_quote_wa.php", "lib/SalesNumbersAdmin.php", "lib/CashbookService.php", "tools/cash_in_hand.php",
              "lib/UcrmCatalogue.php", "lib/QuoteAgentCopy.php", "includes/api/api_cron_debug.php",
              "includes/api/api_products_admin.php", "tabs/sales/applications.php", "tabs/sales/send_quote.php",
              "lib/QuoteOrganization.php", "lib/UcrmClientTarget.php", "lib/UcrmLeadSync.php", "lib/PluginQuotePdf.php",
              "profiles/uganda.json", "lib/TenantProfile.php", "lib/QuotePdfSource.php", "lib/PublicPriceFeed.php",
              "lib/UcrmQuoteLinks.php", "prices.php", "shop.php", "lib/StaffJobsGate.php", "lib/MigrationRunner.php",
              "lib/SalesFollowupChecks.php"]
# files the new texts call changed by 5.18.107
CHANGED_NAMED = ["public.php", "includes/navigation.php", "tabs/sales/kyc_form.php", "tools/set_config.php",
                 "tabs/sales/ucrm_catalogue.php", "lib/QuoteTaxLine.php", "lib/DishNetAiBrain.php", "lib/BrainContext.php",
                 "lib/SalesFollowupLadder.php", "tools/sales_followup.php"]
N_TESTS, N_LIBS = 30, 9                      # §T.2: the 40 added are 30 tests, nine lib classes and 092
MIG = "migrations/092_wa_no_ai.sql"
BANK_BLOCK = ("<?php // 5.18.97", "<?php endif; ?>")   # BK3's block in navigation.php, as the carried comment names it


def _kept(doc):
    head = 'PREV_KEPT_SHA256S="$(cat <<\'SUMS\'\n'
    if doc.t.count(head) != 1:
        raise SystemExit("PART T: PREV_KEPT_SHA256S's heredoc not found once")
    i = doc.t.index(head) + len(head)
    j = doc.t.index("\nSUMS\n)\"", i)
    out = set()
    for ln in doc.t[i:j].split("\n"):
        m = re.fullmatch(r"[0-9a-f]{64}  (\S+)", ln)
        if not m:
            raise SystemExit(f"PART T: a PREV_KEPT_SHA256S line is not '<sha256>  <file>': {ln[:80]!r}")
        out.add(m.group(1))
    return out


def _bank_block(text):
    i = text.find(BANK_BLOCK[0])
    if i < 0 or text.count(BANK_BLOCK[0]) != 1:
        raise SystemExit("PART T: navigation.php's bank block start is not there exactly once")
    j = text.find(BANK_BLOCK[1], i)
    if j < 0:
        raise SystemExit("PART T: navigation.php's bank block has no end")
    return text[i:j + len(BANK_BLOCK[1])]


def apply(doc, ctx):
    repo, plugin, base, pin = ctx["repo"], ctx["plugin"], ctx["base_full"], ctx["pin_full"]
    show = lambda c, p: git(repo, "show", f"{c}:{plugin}/{p}").decode()
    for c, v in ((base, V106), (pin, V107)):
        m = re.search(r'^    "version": "([0-9.]+)",$', show(c, "manifest.json"), re.M)
        if not m or m.group(1) != v:
            raise SystemExit(f"PART T: manifest.json at {c[:7]} says {m.group(1) if m else '?'}, expected {v}")
    added, changed = delta(repo, base, pin, plugin)
    d107 = set(added) | set(changed)
    kept = _kept(doc)
    n_a, n_mod, n_k = len(added), len(changed), len(kept)
    if (n_a, n_mod, n_k) != (40, 75, 416):
        raise SystemExit(f"PART T: added {n_a}, changed {n_mod}, kept {n_k} — not §T.15's 40, 75 and 416")
    tests = [f for f in added if f.startswith("tests/")]
    libs = [f for f in added if f.startswith("lib/")]
    migs = [f for f in added if f.startswith("migrations/")]
    if (len(tests), len(libs), migs) != (N_TESTS, N_LIBS, [MIG]) or len(tests) + len(libs) + len(migs) != n_a:
        raise SystemExit(f"PART T: the added files are {len(tests)} tests, {len(libs)} lib classes and {migs} — not "
                         f"{N_TESTS}, {N_LIBS} and {MIG} alone")
    if any(f.startswith("migrations/") for f in changed):
        raise SystemExit("PART T: 5.18.107 modifies a migration")
    for f in KEPT_NAMED:
        if f not in kept or f in d107 or sha_at(repo, base, f"{plugin}/{f}") != sha_at(repo, pin, f"{plugin}/{f}"):
            raise SystemExit(f"PART T: {f} is not a kept file, the same at bf29cbd and the pin")
    for f in CHANGED_NAMED:
        if f not in changed:
            raise SystemExit(f"PART T: {f} is not among the files 5.18.107 changes")
    if any(re.search(r"ashbook|cash|[Bb]ank", f) for f in d107 if not f.startswith("tests/")):
        raise SystemExit("PART T: 5.18.107 changes a Cashbook, cash or bank file — the texts below could not say it changes none")
    # public.php: every hunk in its tab tables; navigation.php: the bank block byte for byte; no bank line in either, nor in
    # set_config.php's change
    pd = git(repo, "diff", base, pin, "--", f"{plugin}/public.php").decode()
    hunks = re.findall(r"(?m)^@@ [^@]+ @@(.*)$", pd)
    if not hunks or any(h.strip() not in ("$_tabFiles = [", "$_tabPerms = [") for h in hunks):
        raise SystemExit(f"PART T: public.php changes outside its tab tables: {hunks}")
    if _bank_block(show(base, "includes/navigation.php")) != _bank_block(show(pin, "includes/navigation.php")):
        raise SystemExit("PART T: navigation.php's bank block differs between bf29cbd and the pin")
    for f in ("public.php", "includes/navigation.php", "tools/set_config.php"):
        dl = [l for l in git(repo, "diff", base, pin, "--", f"{plugin}/{f}").decode().split("\n")
              if l[:1] in "+-" and not l.startswith(("+++", "---"))]
        if any(re.search(r"bank", l, re.I) for l in dl):
            raise SystemExit(f"PART T: {f}'s change touches a bank line")
    if re.search(r"bank|cb_", show(pin, MIG), re.I):
        raise SystemExit("PART T: 092 names a bank table")
    for f in ("lib/KycService.php", "lib/UcrmQuoteLines.php", "lib/KycCrmSync.php"):
        if "QuoteTaxLine" in show(pin, f):
            raise SystemExit(f"PART T: {f} reaches QuoteTaxLine — the web-direct quoting would not be handled exactly alike")
    # DF-1: PERSONA_PHP builds no rule 8, no quotation and no turn, so the brain's 5.18.107 changes are not in its prompts
    ph = 'PERSONA_PHP="$(cat <<\'PHP\'\n'
    if doc.t.count(ph) != 1:
        raise SystemExit("PART T: PERSONA_PHP's heredoc not found once")
    pi = doc.t.index(ph)
    body = doc.t[pi:doc.t.index("\nPHP\n)\"", pi)]
    if "promptPreview" not in body or any(s in body for s in ("setTopicRule", "'quotation'", "'turn'")):
        raise SystemExit("PART T: PERSONA_PHP calls setTopicRule or sets a quotation or a turn — DF-1's wording would be false")
    brain = show(pin, "lib/DishNetAiBrain.php")
    if "public function setTopicRule(bool $on)" not in brain or "public function promptPreview(array $context)" not in brain:
        raise SystemExit("PART T: the pin's brain has no setTopicRule or promptPreview as DF-1 reads them")

    E = [
        # 088 and the bank links (A0, BR2, BA1, RB, CB; BK3)
        ("# file, and 5.18.105 and 5.18.106 add none: 088 must be applied",
         "# file, and 5.18.105 and 5.18.106 add none, and 5.18.107's one, 092 (wa_no_ai), is not a bank table either: 088 must be applied"),
        ("as 5.18.97 reviewed them and 5.18.98 through 5.18.106 leave them, byte for byte (Q-G1, Q-CA1)",
         "as 5.18.97 reviewed them and 5.18.98 through 5.18.107 leave them, byte for byte (Q-G1, Q-CA1)"),
        ("— 5.18.103 and 5.18.104 change\n# navigation.php outside its block, which each leaves byte for byte)",
         "— 5.18.103, 5.18.104 and 5.18.107\n# change navigation.php outside its block, which each leaves byte for byte)"),
        # PERSONA (DF-1)
        ("# (root docs/75 §L.10): 5.18.106 changes neither lib/BrainContext.php nor lib/DishNetAiBrain.php.\n",
         "# (root docs/75 §L.10). 5.18.107 changes lib/BrainContext.php and lib/DishNetAiBrain.php, but nothing these prompts\n"
         "# build: rule 8 prints only after setTopicRule(true), and the quotation and the turn travel only when a caller sets\n"
         "# them — PERSONA_PHP does neither (root docs/76 §T.14, DF-1).\n"),
        # A24/R26, V4
        ("the control. 5.18.105's code\n# and the pin's carry the same gate", "the control. 5.18.106's code\n# and the pin's carry the same gate"),
        ("none is known in 5.18.98 through 5.18.106.", "none is known in 5.18.98 through 5.18.107."),
        # the cash readings: the old code (the baseline's, from Git) and the new (the pin's)
        ("the copy checks C1–C5, 5.18.105's\n# code and 5.18.106's from Git; and the notes N1–N4. r23: 5.18.105's code from Git (TODAY) and the installed (D2), P0, the\n"
         "# installed tool; ECO Bank through the installed code (BA2: BK2 and G3 again); the copy checks with 5.18.105's code from\n",
         "the copy checks C1–C5, 5.18.106's\n# code and 5.18.107's from Git; and the notes N1–N4. r23: 5.18.106's code from Git (TODAY) and the installed (D2), P0, the\n"
         "# installed tool; ECO Bank through the installed code (BA2: BK2 and G3 again); the copy checks with 5.18.106's code from\n"),
        ("  # 5.18.105's and 5.18.106's code from Git, in R23 5.18.105's from Git and the installed.",
         "  # 5.18.106's and 5.18.107's code from Git, in R23 5.18.106's from Git and the installed."),
        ("project: 5.18.106 does not change cash in hand;", "project: 5.18.107 does not change cash in hand;"),
        ("# 5.18.105 classes (G3), the offered accounts (BN5) and the gate per switch state through the pin's (BK2); the copy checks\n"
         "# C1–C5, 5.18.105's code and 5.18.106's from Git on ONE copy of the master;",
         "# 5.18.106 classes (G3), the offered accounts (BN5) and the gate per switch state through the pin's (BK2); the copy checks\n"
         "# C1–C5, 5.18.106's code and 5.18.107's from Git on ONE copy of the master;"),
        ("  # The pin's code reads the cards as the live classes do — 5.18.106 changes none of the files they are read through (A0, Q-G1).",
         "  # The pin's code reads the cards as the live classes do — 5.18.107 changes none of the files they are read through (A0, Q-G1)."),
        ("carried: 5.18.105's code and 5.18.106's, both from Git, on ONE copy of the",
         "carried: 5.18.106's code and 5.18.107's, both from Git, on ONE copy of the"),
        ("  # both codes against each other: 5.18.106 changes no file of the Cashbook's, so they must be the same.",
         "  # both codes against each other: 5.18.107 changes no file of the Cashbook's, so they must be the same."),
        ("# code (5.18.105's, from Git) on the same copy equals D2 = A's SQL too,", "# code (5.18.106's, from Git) on the same copy equals D2 = A's SQL too,"),
        ("# BA2) — 5.18.106 changes no figure;", "# BA2) — 5.18.107 changes no figure;"),
        ("# C1–C5 again, 5.18.105's code from Git and the installed (CA2);", "# C1–C5 again, 5.18.106's code from Git and the installed (CA2);"),
        ("(its banner 5.18.96's: 5.18.97 through 5.18.106 do not change it)", "(its banner 5.18.96's: 5.18.97 through 5.18.107 do not change it)"),
        # QC-RB2 — the rollback target handles the web-direct applications as the release does (KycService, UcrmQuoteLines)
        ("a record: 5.18.105, the\n# rollback target, handles them exactly as 5.18.106 does",
         "a record: 5.18.106, the\n# rollback target, handles them exactly as 5.18.107 does"),
        ("    // counted for the record: 5.18.105, the rollback target, retries and quotes each exactly as 5.18.106 does (the same",
         "    // counted for the record: 5.18.106, the rollback target, retries and quotes each exactly as 5.18.107 does (the same"),
        ("# made, counted for the record — the rollback changes none of them: 5.18.105 handles each exactly as 5.18.106 does —",
         "# made, counted for the record — the rollback changes none of them: 5.18.106 handles each exactly as 5.18.107 does —"),
        ("a record: 5.18.105 handles each as\n  # 5.18.106 does, so the rollback leaves none to quote by hand;",
         "a record: 5.18.106 handles each as\n  # 5.18.107 does, so the rollback leaves none to quote by hand;"),
        ("the rollback changes nothing for them: 5.18.105 retries and quotes each exactly as 5.18.106 does (the same KycService and UcrmQuoteLines)",
         "the rollback changes nothing for them: 5.18.106 retries and quotes each exactly as 5.18.107 does (the same KycService and UcrmQuoteLines)"),
        ("— 5.18.106 changes nothing of how a web-direct application is quoted, so whichever release now serves",
         "— 5.18.107 changes nothing of how a web-direct application is quoted, so whichever release now serves"),
        ("read by 5.18.105 again after a 5.18.106\n# rollback,", "read by 5.18.106 again after a 5.18.107\n# rollback,"),
        ("the rollback rewrites no application, and 5.18.105 keeps each on the direct rules\n# exactly as 5.18.106 did",
         "the rollback rewrites no application, and 5.18.106 keeps each on the direct rules\n# exactly as 5.18.107 did"),
        ("— 5.18.105 keeps each on the direct rules exactly as 5.18.106 did (retried by its uCRM ids;",
         "— 5.18.106 keeps each on the direct rules exactly as 5.18.107 did (retried by its uCRM ids;"),
        ("which 5.18.103's through 5.18.106's code write too; none of 5.18.104, 5.18.105 and 5.18.106 adds any of these kinds)",
         "which 5.18.103's through 5.18.107's code write too; none of 5.18.104 through 5.18.107 adds any of these kinds)"),
        # the uCRM catalogue (QC-G1)
        ("# of what is offered is a note: 5.18.106 changes nothing of the catalogue, and QC-CA1 compares it after the copy.\n",
         "# of what is offered is a note: 5.18.107 changes nothing of how the catalogue is read (lib/UcrmCatalogue.php is\n"
         "# bf29cbd's; its screen gains only the two-prices warning), and QC-CA1 compares it after the copy.\n"),
        # QF
        ("the one form 5.18.105 and 5.18.106 accept, root docs/74 §H.4.4)", "the one form 5.18.105 through 5.18.107 accept, root docs/74 §H.4.4)"),
        ("  # reads them — and since 5.18.106 CS-G1 refuses a claim switch present, root docs/75 §L.3.)\n",
         "  # reads them — and 5.18.106's claim-switch refusal retired (root docs/76 §T.4, TD1): the claim switches stay as the\n"
         "  # owner has them.)\n"),
        ("  # 5.18.105 and 5.18.106: 090 is 5.18.104's, in the live book's ledger", "  # 5.18.105 through 5.18.107: 090 is 5.18.104's, in the live book's ledger"),
        # the RB notes of Part V, Part O and Part P: they continue under bf29cbd
        ("# under 7835906 as Part V's switches say. Read on a copy.", "# under bf29cbd as Part V's switches say. Read on a copy."),
        ("# 7835906 as Part O's switches say — an offer running goes on,", "# bf29cbd as Part O's switches say — an offer running goes on,"),
        ("continue under 7835906 as its\n# switch (agent-cc) says.", "continue under bf29cbd as its\n# switch (agent-cc) says."),
        ("carried: the baseline — 5.18.105 since 5.18.106 — carries P7 as the pin does: both trees must hold it",
         "carried: the baseline — 5.18.106 since 5.18.107 — carries P7 as the pin does: both trees must hold it"),
        ("(5.18.105, which this rollback puts back, reads both collections as its switch says)",
         "(5.18.106, which this rollback puts back, reads both collections as its switch says)"),
        # TN, QS
        ("owners() where the code has it (5.18.100 through 5.18.106)", "owners() where the code has it (5.18.100 through 5.18.107)"),
        ("never run), carried by 5.18.102 through 5.18.106 ─", "never run), carried by 5.18.102 through 5.18.107 ─"),
        ("# only in its log lines (LM) and 5.18.106 not at all — never these lines:",
         "# only in its log lines (LM), and 5.18.106 and 5.18.107 not at all — never these lines:"),
        ("5.18.105 only in its log lines and 5.18.106 not at all, never these lines",
         "5.18.105 only in its log lines, and 5.18.106 and 5.18.107 not at all, never these lines"),
        # A22 and R23: public.php, changed again by 5.18.107 in its tab tables
        ("# not the repair page), which 5.18.105 and 5.18.106 keep byte for byte; each must keep every\n",
         "# not the repair page), and 5.18.107 changes it again (its tab tables — the AI Knowledge Base screen's own key — not\n"
         "# the repair page); each must keep every\n"),
        ("(its tab tables — My quotes, the offers screen — not the repair page), which 5.18.105 and 5.18.106 keep byte for byte; each must leave all seven in place.",
         "(its tab tables — My quotes, the offers screen — not the repair page), and 5.18.107 changes it again (its tab tables — the AI Knowledge Base screen's own key — not the repair page); each must leave all seven in place."),
        # the put-back's sentence
        ("# what that leaves, first — nothing to quote by hand: the catalogue's files are byte for byte the same in both releases,\n"
         "# and 5.18.105 handles a web-direct application as 5.18.106 does.",
         "# what that leaves, first — nothing to quote by hand: the catalogue is read the same way in both releases\n"
         "# (lib/UcrmCatalogue.php byte for byte; 5.18.107 adds only the screen's two-prices warning), and 5.18.106 handles a\n"
         "# web-direct application as 5.18.107 does."),
        ("PUTBACK_HAZARD=\"The put-back below copies 5.18.105's files and counts nothing. It changes nothing of the uCRM catalogue "
         "(its screen, its settings and its switch stay as they are; 5.18.106 changed none of it) and nothing of the web-direct "
         "applications: 5.18.105 quotes each itself,",
         "PUTBACK_HAZARD=\"The put-back below copies 5.18.106's files and counts nothing. It changes nothing of the uCRM catalogue's "
         "settings or switch (they stay as an admin left them; its screen loses only 5.18.107's two-prices warning) and nothing of "
         "the web-direct applications: 5.18.106 quotes each itself,"),
        # BASE_DIFFERS
        ("# Since 5.18.91: with the container's record on the rollback target (5.18.105 here), every file this release changes\n",
         "# Since 5.18.91: with the container's record on the rollback target (5.18.106 here), every file this release changes\n"),
        ("and no 5.18.105 file names any\n# of them (EXPECTED_ADDED: 5.18.106's 3, its tests; QC-RB1 shows it after a rollback)",
         f"and no 5.18.106 file names any\n# of them (EXPECTED_ADDED: 5.18.107's {n_a} — {N_TESTS} tests, nine lib classes and 092, which bf29cbd's runner\n"
         "# reaches by its glob alone; QC-RB1 shows it after a rollback)"),
        # BR1's after-a-rollback note (DF-9): a 5.18.107 rollback writes no setting, so there is no command to offer back
        ("the copy replaces those files, and its PASSED --after-only reads the latest rollback's record, offering your own command "
         "to turn followup_auto_send back on where that rollback set it off (root docs/75 §L.8) — --redeploy changes only",
         "the copy replaces those files; that rollback wrote no setting, and a switch turned off before it by your own command "
         "comes back on only by yours (root docs/76 §T.7) — --redeploy changes only"),
        # LM's call site at stage A
        ("  # LM-G1, LM-G2 — 5.18.105 (root docs/74 §H.4.5), carried (root docs/75 §L.6): the masked lines in the pin, in 7835906\n",
         "  # LM-G1, LM-G2 — 5.18.105 (root docs/74 §H.4.5), carried (root docs/76 §T.2): the masked lines in the pin, in bf29cbd\n"),
        # LK-B1: CS-B1 retired with CS-G1
        ("never a value), the steps on and skipped. 5.18.106 (root docs/75 §L.3): CS-B1 first, on LK-B1's reading — none of\n"
         "  # the three claim switches present in <data>/kyc_config.json or its store row, or nothing is copied; its remedies are\n"
         "  # printed, never run.\n",
         "never a value), the steps on and skipped. 5.18.106's claim-switch refusals retired (root docs/76 §T.4, TD1): the\n"
         "  # claim switches are copied as the owner has them.\n"),
        # V6, Q-CA5
        ("(and so does the rollback target, 5.18.105).", "(and so does the rollback target, 5.18.106)."),
        ("changed again by 5.18.102 and 5.18.103 and kept byte for byte by 5.18.104 through 5.18.106)",
         "changed again by 5.18.102 and 5.18.103 and kept byte for byte by 5.18.104 through 5.18.106; 5.18.107 adds the KYC "
         "form's two-prices hint)"),
        # BA1 (twice) — 092 writes none of 088's tables
        ("and 5.18.104's three (089–091) write none of its tables; 5.18.105 and 5.18.106 add none.",
         "and 5.18.104's three (089–091) write none of its tables; 5.18.105 and 5.18.106 add none, and 5.18.107's one, 092 "
         "(wa_no_ai), writes none of them."),
        ("# tables, and 5.18.105 and 5.18.106 add none: applied before this run (BR2),",
         "# tables, 5.18.105 and 5.18.106 add none, and 5.18.107's 092 writes none of them: applied before this run (BR2),"),
        ("# (check_088). Then ECO Bank's surface, as installed — unchanged by 5.18.106 (Q-G1, Q-CA1); 5.18.104 changed public.php,\n",
         "# (check_088). Then ECO Bank's surface, as installed — unchanged by 5.18.107 (Q-G1, Q-CA1), which changes public.php,\n"
         "# includes/navigation.php and tools/set_config.php outside ECO Bank's lines; 5.18.104 changed public.php,\n"),
        # R7
        ("# R7 — the database (5.18.95 to 5.18.106): 5.18.106 adds no migration — the ledger reads 091 last before and after (root\n"
         "# docs/75 §L.2; since 5.18.107 a rollback writes nothing, root docs/76 §T.7); 5.18.104 added 089–091,",
         "# R7 — the database (5.18.95 to 5.18.107): 5.18.107 adds one migration, 092 — on a first deploy the ledger reads 091\n"
         "# last before and 092 after, on a redeploy (MG-G1 read 092 present) 092 before and after (root docs/76 §T.3 item 8; a\n"
         "# rollback writes nothing, root docs/76 §T.7); 5.18.104 added 089–091,"),
        ("plugin.sqlite3's size, read as stage A read them: the ledger must be the same, the file the same size or larger",
         "plugin.sqlite3's size, read as stage A read them: the ledger must be the same (on a first deploy, 092's row added and\n"
         "# nothing else), the file the same size or larger"),
        # 5.18.107 adds 092: the texts that said it adds no migration (VN-M1, OF-M1, QF-CA1) and 5.18.98's R7 history
        ("$EXPECTED_VERSION adds no migration, so no boot applies it and waiting changes nothing: the book's 090",
         "$EXPECTED_VERSION adds only 092, so no boot applies 090 and waiting changes nothing: the book's 090"),
        ("$EXPECTED_VERSION adds no migration, so no boot applies it and waiting changes nothing: the book's 091",
         "$EXPECTED_VERSION adds only 092, so no boot applies 091 and waiting changes nothing: the book's 091"),
        ("— $EXPECTED_VERSION adds no migration and removes none\"", "— $EXPECTED_VERSION adds only 092 and removes none\""),
        ("which its runner added; 5.18.98 adds no migration, so the\n# ledger must not move at all — db_judge.)",
         "which its runner added; 5.18.98 added none, so the\n# ledger was not to move at all — db_judge; 5.18.107 allows exactly 092's row where MG-G1 read it absent, db_mig107.)"),
        # the summary's D9 note: post_kyc.php and webhook.php are kept by 5.18.107 too (partH asserts both)
        ("# 5.18.103 through 5.18.106: D9 (no Delivery PDF on Uganda) was 5.18.102's change; none changes it — 5.18.105's post_kyc.php and webhook.php hunks were LM's masked log lines only, and 5.18.106 changes neither",
         "# 5.18.103 through 5.18.107: D9 (no Delivery PDF on Uganda) was 5.18.102's change; none changes it — 5.18.105's post_kyc.php and webhook.php hunks were LM's masked log lines only, and 5.18.106 and 5.18.107 change neither"),
        # RB: the quoting code in place (§T.2: kyc_form.php and set_config.php are among the 75)
        ("# 5.18.99's quoting code is in place: the rollback target has it. 5.18.106 changes none of its files that reach uCRM:\n"
         "# lib/QuotationService.php (5.18.105's, its log lines masked), lib/KycService.php, lib/KycCrmSync.php, includes/api/api_cron_debug.php,\n"
         "# includes/api/api_products_admin.php, tabs/sales/kyc_form.php, tabs/sales/applications.php, lib/QuoteOrganization.php,\n"
         "# lib/UcrmClientTarget.php, lib/UcrmLeadSync.php, lib/PluginQuotePdf.php, the Uganda profile and tools/set_config.php\n"
         "# — each kept by its own sha256 (PREV_KEPT_SHA256S, below).",
         "# 5.18.99's quoting code is in place: the rollback target has it. 5.18.107 changes two of its files, neither where it\n"
         "# reaches uCRM — tabs/sales/kyc_form.php (the two-prices hint) and tools/set_config.php (Part K's and Part Q's keys),\n"
         f"# each among the {n_mod} it changes and 5.18.106's again by Q-G1's set (above) — and none of the rest:\n"
         "# lib/QuotationService.php (5.18.105's, its log lines masked), lib/KycService.php, lib/KycCrmSync.php,\n"
         "# includes/api/api_cron_debug.php, includes/api/api_products_admin.php, tabs/sales/applications.php,\n"
         "# lib/QuoteOrganization.php, lib/UcrmClientTarget.php, lib/UcrmLeadSync.php, lib/PluginQuotePdf.php and the Uganda\n"
         "# profile — each kept by its own sha256 (PREV_KEPT_SHA256S, below)."),
    ]
    for old, new in E:
        doc.sub(old, new, 1, why="carried text: " + old.strip()[:50])

    # BR1's comment: the live release is 5.18.106 (bf29cbd); an earlier 5.18.107 and its rollback leave 40 files and 092
    doc.lines_between(
        "# BR1 — since 5.18.97 (SPEC §2.10b R1): the installed plugin is live 5.18.105 — every file 7835906 tracks under the plugin,",
        "# 5.18.104's first boot, are in the ledger and are not run again.",
        "# BR1 — since 5.18.97 (SPEC §2.10b R1): the installed plugin is live 5.18.106 — every file bf29cbd tracks under the plugin,\n"
        "# data/ aside, byte for byte (sha256 on the host, from git archive into this run's folder, removed at once), not only the\n"
        "# files this release changes (the record and the manifest say 5.18.106 above; BASE_DIFFERS read this release's own).\n"
        "# Files on disk beyond them are not asked about: deploy-hybrid.sh never deletes, so an earlier 5.18.107 and its\n"
        f"# rollback leave the {n_a} files it adds behind — {N_TESTS} tests, nine lib classes and 092 — counted and said (BR1_LEFT,\n"
        "# over EXPECTED_ADDED); no 5.18.106 file names them (QC-RB1), nothing in bf29cbd requires the nine classes, and the\n"
        "# copy replaces them. 5.18.107 adds one migration, 092: after an earlier 5.18.107 and its rollback it is in the\n"
        "# ledger already (bf29cbd's runner globs it and passes over it once applied), and MG-G1 reads it present — a\n"
        "# redeploy. 089–091, applied at 5.18.104's first boot, are in the ledger and are not run again.",
        why="BR1's comment")

    # Q-G1's comment: the live release is 5.18.106; 75 changed, 416 kept, 40 added; what of the named files 5.18.107 changes
    doc.lines_between(
        "# Q-G1 — 5.18.99 (SPEC-quote-company §6; 5.18.98's G1, carried): the live plugin is 5.18.105 — the container's record and",
        "# an earlier rollback left. Run before a deploy and before the move-list.",
        "# Q-G1 — 5.18.99 (SPEC-quote-company §6; 5.18.98's G1, carried): the live plugin is 5.18.106 — the container's record and\n"
        "# the manifest say so above, and (a deploy) BR1 has read every file bf29cbd tracks — and, by the sha256 THIS SCRIPT pins\n"
        f"# for each (not only Git's), every file 5.18.107 changes ({n_mod}: lib/DishNetAiBrain.php, public.php and\n"
        "# tools/set_config.php among them) is 5.18.106's as installed (PREV_CHANGED_SHA256S), and every file 5.18.106 has that\n"
        f"# 5.18.107 must not touch ({n_k}, PREV_KEPT_SHA256S):\n"
        "# ECO Bank's module, its link on the CEO page (BK3 reads the one in navigation.php, whose bank block 5.18.107 leaves\n"
        "# byte for byte), the More menu, the styles, the Cashbook's list split, the cash-in-hand tool, the files the quoting\n"
        "# code reads and 5.18.99's quoting files that 5.18.107 keeps (TenantProfile; QuoteOrganization, QuotePdfSource and\n"
        "# UcrmLeadSync — QuoteTaxLine is among the changed), 5.18.100's owner rule and the three files it reads, 5.18.102's own\n"
        "# (lib/PublicPriceFeed.php and lib/UcrmQuoteLinks.php among them), 5.18.103's own (lib/UcrmCatalogue.php, prices.php\n"
        "# and shop.php among them; its screen is among the changed) and the files the follow-up's code reads\n"
        "# (lib/StaffJobsGate.php and lib/MigrationRunner.php among them; of 5.18.104's ladder trio, lib/SalesFollowupChecks.php,\n"
        "# held by sha256 — the ladder and its tool are among the changed), unchanged. The "
        f"{n_a} files 5.18.107 adds ({N_TESTS} tests,\n"
        "# nine lib classes and 092) are not asked about here: BR1 counts any an earlier rollback left. Run before a deploy\n"
        "# and before the move-list.",
        why="Q-G1's comment")

    # ── the result: no carried text outside the header and the summary still names the old releases where the new ones
    #    are meant ──
    t = doc.t
    hdr_end = t.index("\nset -uo pipefail\n")
    summ = t.index("# Every command printed here stands alone on its line")
    body = t[hdr_end:summ]
    for s in ("5.18.105's code and 5.18.106's", "5.18.105 classes (G3)", "rollback target, handles them exactly as 5.18.106",
              "exactly as 5.18.106 does", "5.18.105 handles each as", "5.18.105 keeps each on the direct rules",
              "rollback target (5.18.105 here)", "the installed plugin is live 5.18.105", "the live plugin is 5.18.105",
              "every file 7835906 tracks", "continue under 7835906", "under 7835906 as", "7835906 as Part O's",
              "5.18.106 changes nothing of", "5.18.106 adds no migration", "5.18.106 does not change cash in hand",
              "5.18.106 changes no figure", "5.18.106 changes no file of the Cashbook's", "rollback target, 5.18.105",
              "which 5.18.105 and 5.18.106 keep byte for byte", "5.18.105 and 5.18.106 add none:",
              "5.18.105 and 5.18.106 add none.", "copies 5.18.105's files", "5.18.105 quotes each itself",
              "since 5.18.106 CS-G1 refuses", "CS-B1 first", "followup_auto_send back on", "root docs/75 §L.8",
              "5.18.106 changes neither lib/BrainContext.php", "5.18.106 changes none of its files that reach uCRM",
              "the one form 5.18.105 and 5.18.106 accept", "5.18.105 since 5.18.106", "(5.18.100 through 5.18.106)",
              "5.18.102 through 5.18.106 ─", "and 5.18.106 not at all", "in the pin, in 7835906",
              "5.18.98 through 5.18.106 leave them", "none is known in 5.18.98 through 5.18.106",
              "5.18.97 through 5.18.106 do not change it", "unchanged by 5.18.106 (Q-G1", "5.18.105 and 5.18.106: 090",
              "reached by nothing", "read by nothing", "writes nothing to the book"):
        if s in body:
            raise SystemExit(f"PART T: the body still says {s!r}")
    for s in ("5.18.107's one, 092 (wa_no_ai), is not a bank table either", "PERSONA_PHP does neither (root docs/76 §T.14, DF-1)",
              "5.18.107 changes it again (its tab tables — the AI Knowledge Base screen's own key — not", "the installed plugin is live 5.18.106",
              "the live plugin is 5.18.106", "comes back on only by yours (root docs/76 §T.7)", "5.18.106's claim-switch refusals retired (root docs/76 §T.4, TD1)"):
        doc.must(s, why="partT's result")
