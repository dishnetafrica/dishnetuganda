# Part H — what the operator reads (root docs/76 §T.9): the header (the title and 5.18.107's purpose, its own checks and
# 5.18.106's retired, 5.18.105's carried, the carried paragraphs' live release and counts, the operator's rules, the
# rollback, the options, what it never does and what it writes, the stages) and the summary (the names, what changed and
# what did not, the migrations, "check it", the screens, your steps, the enquiry report, the owner's steps of §K, the
# rollback's "deploy again"). Its rules for the operator, §T.9: 5.18.106 live and its --after-only PASSED, the
# quarantine's --check begun after 5.18.106's copy PASSED; K, Q and W.1 off (NS-G1 refuses otherwise; nothing in 5.18.106
# writes them); not between 14:55 and 15:10 UTC, not while the morning list is sent (05:00–08:00 UTC, Monday to
# Saturday); no scripts/sales-followup.sh and no set_config while the deploy, its --after-only or its rollback runs; §K's
# switch-off and drain before the rollback command, each alone; the deploy and its rollback never in one copyable block;
# every printed later command alone on its line with its own log. --after-only at least 20 minutes after the copy, and
# again after each §K step. After a PASSED --after-only alone, §K's steps 0–4, each command a named display variable
# (partDV: K_ON_CMD, Q_ON_CMD, W1_ON_CMD), printed and never run; v10 and the v9-restore are never in this script.
# 5.18.106's §F steps and as_back_on are gone. Nothing here is a check; every line is printed text or a comment but the
# owner's-steps block's guards. Every fact a new text states is read off Git first. The last UJ names partSA left for
# this part (uj_judgeable, uj_gate_a, UJ_ON_GATE, UJ_A) go with the summary's check-it block.
import re, textwrap
from d107lib import git, sha_at, delta

V106, V107 = "5.18.106", "5.18.107"
CONT = "#            "
N_TESTS, N_LIBS = 30, 9


def para(text, first="#   ", cont=CONT, width=120):
    """A header paragraph, wrapped to 120 characters, no word broken."""
    out = textwrap.wrap(text, width=width, initial_indent=first, subsequent_indent=cont, break_long_words=False,
                        break_on_hyphens=False)
    long = [l for l in out if len(l) > width]
    if long:
        raise SystemExit(f"PART H: a header line is longer than {width} characters: {long[0][:80]!r}")
    return "\n".join(out)


def opt(name, text):
    return para(text, first=f"#   {name:<21}", cont="#                        ")


def apply(doc, ctx):
    repo, plugin, base, pin = ctx["repo"], ctx["plugin"], ctx["base_full"], ctx["pin_full"]
    show = lambda c, p: git(repo, "show", f"{c}:{plugin}/{p}").decode()
    added, changed = delta(repo, base, pin, plugin)
    n_a, n_mod = len(added), len(changed)
    n_pin = n_a + n_mod
    kept_block = doc.t[doc.t.index('PREV_KEPT_SHA256S="$(cat <<\'SUMS\'\n'):]
    kept = set(re.findall(r"(?m)^[0-9a-f]{64}  (\S+)$", kept_block[:kept_block.index("\nSUMS\n)\"")]))
    n_k = len(kept)
    if (n_a, n_mod, n_k, n_pin) != (40, 70, 421, 110):
        raise SystemExit(f"PART H: {n_a} added, {n_mod} changed, {n_k} kept — not §T.2's 40, 70, 421")
    if (len([f for f in added if f.startswith("tests/")]), len([f for f in added if f.startswith("lib/")]),
            [f for f in added if f.startswith("migrations/")]) != (N_TESTS, N_LIBS, ["migrations/092_wa_no_ai.sql"]):
        raise SystemExit("PART H: the added files are not 30 tests, nine lib classes and 092")
    d107 = set(added) | set(changed)
    for f in ("lib/QuoteOrganization.php", "lib/TenantProfile.php", "lib/PublicPriceFeed.php", "lib/UcrmQuoteLinks.php",
              "lib/UcrmQuoteLines.php", "lib/UcrmCatalogue.php", "webhook.php", "cron_quote_wa.php",
              "includes/post/post_kyc.php", "lib/SalesFollowupChecks.php", "lib/QuotePdfSource.php", "lib/UcrmLeadSync.php",
              "profiles/uganda.json", "lib/SalesNumbersAdmin.php", "lib/KycService.php", "includes/api/api_retailer.php",
              "retailer/index.html", "lib/PluginConfig.php", "lib/ConfigVault.php", "lib/FollowUpService.php"):
        if f not in kept or f in d107 or sha_at(repo, base, f"{plugin}/{f}") != sha_at(repo, pin, f"{plugin}/{f}"):
            raise SystemExit(f"PART H: {f} is not a kept file, the same at bf29cbd and the pin")
    for f in ("lib/QuoteTaxLine.php", "tabs/sales/ucrm_catalogue.php", "lib/SalesFollowupLadder.php", "tools/sales_followup.php",
              "tools/set_config.php", "lib/DishNetAiBrain.php", "public.php", "tabs/sales/kyc_form.php",
              "tabs/engage/wa_ai_setup.php", "tabs/engage/wa_inbox.php", "tabs/admin/knowledge_base.php",
              "includes/navigation.php"):
        if f not in changed:
            raise SystemExit(f"PART H: {f} is not among the files 5.18.107 changes")
    sc = show(pin, "tools/set_config.php")
    for s in ("    'quoted_no_prices_enabled' => ['bool',", "    'connection_quote_enabled' => ['bool',",
              "    'ai_hardware_expert' => ['bool',", "else                 echo \"\\n  \" . $key . \" = \" . $new . \"\\n\";",
              "    echo \"\\n  AI SETTINGS\\n\\n\";"):
        if sc.count(s) != 1:
            raise SystemExit(f"PART H: set_config at the pin does not hold {s[:70]!r} once")
    lad = show(pin, "lib/SalesFollowupLadder.php")
    if "'tracking' => ['n' => '3'," not in lad or "'ai_burst_merge_enabled'" not in lad:
        raise SystemExit("PART H: the pin's ladder has no step 3 named tracking, or no ai_burst_merge_enabled setting")
    if "quoted_no_prices_enabled" in show(base, "tools/set_config.php"):
        raise SystemExit("PART H: bf29cbd's set_config already manages K")
    for v in ("K_ON_CMD", "Q_ON_CMD", "W1_ON_CMD"):
        if len(re.findall(rf"(?m)^\s*{v}=", doc.t)) != 1:
            raise SystemExit(f"PART H: {v} is not assigned once (partDV)")

    # ── H1: the title and 5.18.107's paragraph ──
    doc.lines_between("# deploy-5.18.106.sh — on Uganda, the enquiry follow-up sender unjammed:",
                      "#            present. Files, against 7835906: 20 changed, 3 added — 23 (the added ones are its tests).",
        para("deploy-5.18.107.sh — on Uganda, the quotation built in code, quoted customers, the numbers the assistant never "
             "answers, and the week's fixes — plugin 5.18.107, Uganda; root docs/76, 21–26 Nov — over 5.18.106 (live), every "
             "switch of the sales follow-up kept exactly as found and nothing written; or roll it back to 5.18.106, nothing "
             "written, after root docs/76 §K's switch-off and drain.", first="# ", cont="# ")
        + "\n#\n"
        + para("5.18.107 The quotation built in code, quoted customers, the \"assistant off\" list, and the week's fixes "
               "(Uganda; root docs/76 §A, §D, §E, §X, §W). In force from the copy: Part X, the numbers the assistant never "
               "answers — migration 092, the table wa_no_ai, created empty and managed on Engage → WhatsApp → AI setup and "
               "the Inbox, so nothing changes until staff list a number; a listed number gets no AI reply, follow-up, offer, "
               "chase or win-back, invoices and receipts still go, and its salesperson is alerted; while the list cannot be "
               "read no Uganda customer gets an AI reply; F-1 (an enquiry follow-up stored as the plugin, so it never makes "
               "the assistant drop a message), F-7 (a reply an opt-out blocks is a final, quiet outcome), F-8 (the "
               "assistant's photo and flyer go as replies) and F-11 (the latest invoice's number); W.2, rule 8, STAY ON "
               "DISHNET TOPICS, in the absolute rules; W.3, the office line on the customer-care hand-over; W.5, the AI "
               "Knowledge Base screen reachable; W.6, a plan whose two uCRM prices differ listed on the catalogue screen and "
               "the KYC form; O7, the tax lines without \"Nothing is added on top.\". Built and OFF, each its own switch, "
               "until the owner turns it on after a PASSED --after-only (root docs/76 §K): Part K (quoted_no_prices_enabled — "
               "a customer holding a live quotation gets no price from the assistant or its enquiry follow-ups), Part Q "
               "(connection_quote_enabled — the two-option connection quotation, built by code from uCRM) and W.1 "
               "(ai_burst_merge_enabled — one reply to a burst of messages). South Sudan unchanged. THIS SCRIPT WRITES "
               "NOTHING, AND NEITHER DOES ITS ROLLBACK: it keeps every key of the sales follow-up's ladder as found, runs "
               "neither the switch tool's on, off, skip, set or erase nor scripts/sales-followup.sh, and refuses (NS-G1) "
               "while K's, Q's or W.1's switch reads on. Files, against bf29cbd: "
               f"{n_mod} changed, {n_a} added — {n_pin} (the added ones {N_TESTS} tests, nine lib classes and 092)."),
        why="H1: the title")

    # ── H2: the cash sheet's QC-RB2; uCRM read with GETs: no key written ──
    doc.sub("only, nothing for the rollback to quote by hand — 5.18.105, put back, handles each exactly as 5.18.106\n#            does,",
            "only, nothing for the rollback to quote by hand — 5.18.106, put back, handles each exactly as 5.18.107\n#            does,",
            why="H2: the cash sheet's QC-RB2")
    doc.sub("#            ucrm_quote_organization_id and wa_inbox_roles included; the one key this script writes is a rollback's\n"
            "#            followup_auto_send '0' (AS-RB1, root docs/75 §L.7).\n",
            "#            ucrm_quote_organization_id and wa_inbox_roles included — and this script writes no key, nor does its\n"
            "#            rollback (root docs/76 §T.7).\n", why="H2: uCRM GETs: no key written")

    # ── H3: 5.18.107's own checks (5.18.106's retired); 5.18.105's, carried ──
    doc.lines_between("#   5.18.106'S OWN CHECKS (root docs/75 §L.3–§L.7).", "#            after a rollback to 7835906: the masked form still there.",
        para("5.18.107'S OWN CHECKS (root docs/76 §T.3–§T.7). MIGRATION 092 (MG): MG-G1, stage A (and so --check), on "
             "QF-G1's copy — 092 absent from the ledger (a first deploy) or present whole: its checksum, its _schema_checks "
             "row at 5 of 5 and its five objects as the pin's 092 makes them on :memory: (a redeploy); anything else NO-GO, "
             "nothing changed. QF-G2's one trial runs 092 too, applied with its 7 statements logged OK where MG-G1 read it "
             "absent, none pending where present; neg092, on a copy of its own, runs 092 alone over a wa_no_ai of another "
             "shape — partial, a PARTIAL line naming the failed CHECK, no 092 row — the control that QF-G2 sees what it "
             "judges. NA-G1 on QF-G2's trial copy through the pin's code, and NA-CA1 after the copy through the installed "
             "code: the list's reader, read-only, then its positive and negative controls on a throwaway copy in the run's "
             "/tmp, with two sentinel numbers nobody can hold. After the copy MG-CA1 waits up to 60 s for 092 in the live "
             "ledger, read-only, then reads it on a fresh copy; MG-CA2 reads 092's lines in migration.log — OK and PARTIAL "
             "judged, a checksum WARNING a note; R7 the ledger, 092 added once on a first deploy, the same on a redeploy. "
             "THE SWITCHES (NS): NS-G1, stage A (and so --check), on LK-G1's reading, and NS-B1 after DEPLOY is typed, on "
             "LK-B1's — quoted_no_prices_enabled (K), connection_quote_enabled (Q) and ai_burst_merge_enabled (W.1), in "
             "every place their readers read (<plugin>/data/config.json, <data>/config.json, <data>/kyc_config.json), each "
             "against the off values: NO-GO while one reads on, or where its positive control in the same reading fails; "
             "the store row reported, never judged; K's and Q's keys and texts join LK's map. THE READINGS BEFORE THE "
             "DEPLOY (SA; notes only, counts and fixed words, never a phone or a message): wa_no_ai's rows, the burst marks "
             "still waiting (the pin's --check burst-drain on the copy, kept for V1: BD_A), the settings text v9 or not "
             "(root docs/76 §N.1), OFFICE_LOCATION approved or not, StaffJobsGate as the workers read it. AFTER THE COPY: "
             "ME-CA1, master's own record (master_schedule in the live store, read-only, as the book's owner) — PASS when "
             "each of the four follow-up jobs started after the copy and returned to master; FAIL when one is still at its "
             "start and a later cycle has begun; in --after-only, a poll for master's next cycle, bounded by master's own "
             "budget; NOT YET otherwise — a note in the deploy run, and in --after-only a summary state of its own, exit "
             "status 3 (0 PASSED, 1 FAILED), which withholds PASSED and the owner's steps of root docs/76 §K and says when "
             "to run --after-only again; an unreadable schedule a NOT ESTABLISHED note, never a PASS; 5.18.106's "
             "plugin.log count carried as its caught-error half. ME-CA2, the seven other jobs whose code 5.18.107 changes, "
             "in the same read, FAIL-only. THE ROLLBACK (RB107): before ROLLBACK is typed, rb_pre107 — LK-RB0; "
             "ai_burst_merge_enabled through the pin's own reader and the drain (the pin's --check burst-drain on a copy), "
             "read again 125 s later where the switch reads off; in capitals, root docs/76 §K's switch-off and drain where "
             "they do not hold yet, and K's and Q's off commands where either reads on or cannot be read, each your own "
             "command, alone, never run by it; the settings text v10 or not; RB-X's counts (the list's rows, the alerts "
             "still queued). The rollback writes nothing: no switch tool, no off-hook, no key. After the put-back, RB107 — "
             "master, LK-RB1, LM-RB1 (5.18.105's masked lines still in place), RB-X again and the pin's drain once more on "
             "a copy, its conversation ids for staff, never \"nothing to drain\". 5.18.106'S OWN CHECKS, RETIRED (root "
             "docs/76 §T.4–§T.7, TD1): the claim switches' two refusals, the readings before the deploy and their one refusal, "
             "the reading after the copy, and the rollback's one write with its readings; ME-CA1 re-pointed (above).")
        + "\n#\n"
        + para("5.18.105'S CHECKS, CARRIED (root docs/74 §H.4, §H.5; root docs/75 §L.3, §L.6; root docs/76 §T.2, §T.4): THE "
               "LADDER KEPT AS FOUND (LK). LK-G1, stage A: the ladder's keys — SalesFollowupLadder::allKeys() and "
               "settingKeys(), and since 5.18.107 K's and Q's keys and texts — read read-only in four places "
               "(<data>/kyc_config.json, its store row, <plugin>/data/config.json, <data>/config.json): NO-GO only where a "
               "place cannot be read, or an allKeys() key is held by a config.json (the tool's off would read back FAIL "
               "key_outside_overrides); never for a key that is on — a note gives the steps on and skipped, the ledger, the "
               "counts per place, every key where file and store disagree (its two notes on offers_live_enabled and "
               "quote_chase_enabled went in 5.18.106); NS-G1 judges K's, Q's and W.1's switches on it. LK-B1, after DEPLOY "
               "and before the copy: the same reading, equal key by key and place by place, or NO-GO and nothing is copied "
               "(\"the switch tool was run meanwhile\"). LK-CA1 after the copy: equal to LK-B1's (FAIL otherwise); LK-CA2: no "
               "table's rows fell (quote_followup_state over its rows not list_last:%, wa_no_ai's among them), "
               "_schema_checks unchanged but for 092's one row on a first deploy, the four jobs' schedule entries kept; "
               "LK-ON: each step on, the installed tool's --check against the pin's at stage A (PASS then FAIL now is a "
               "FAIL); LK-AO in --after-only, against the deploy's map, a note. Each key's value is a 16-hex HMAC under a key "
               "kept in /root/dnb-5.18.107/lk-key-5.18.107 (mode 0600) — the map on the sheet and in the state file, never "
               "a value in the log. THE MASKED LINES (LM), 5.18.105's, still holding: LM-G1, stage A: in the pin's files "
               "from Git, in bf29cbd's and as installed, each of 5.18.105's blocks (one per hunk, from Git, markers aside; "
               "counted as blocks of lines by sha256 — this script carries no source text) once and its old block nowhere, "
               "the control fixed on 994d660 (5.18.104's release commit, before the lines were masked); LM-G2: the on-chase "
               "precondition's two strings once each in the pin's evo_webhook.php; LM-CA1 the same on the installed files; "
               "LM-CA2 the PHP log's old-form opt-out lines 60 s or more after the copy (0, else FAIL) and the sinks' records "
               "with a run of 9 or more digits since then (a note); LM-ON in --after-only; LM-RB1 after a rollback to "
               "bf29cbd: the masked form still there."), why="H3: 5.18.107's and 5.18.105's paragraphs")
    doc.sub("(\"they continue under 7835906\n#            as their switches say\").",
            "(\"they continue under bf29cbd\n#            as their switches say\").", why="H3: 5.18.104's carried: RB notes")

    # ── H4: QC-RB1 and QC-RB2 after a rollback ──
    doc.lines_between("#            id 0 in the cart. After a rollback: QC-RB1, re-pointed to 5.18.106 (root docs/75 §L.2): no file 7835906",
                      "#            so).",
        para("id 0 in the cart. After a rollback: QC-RB1, re-pointed to 5.18.107 (root docs/76 §T.2): no file bf29cbd tracks "
             f"names one of the {n_a} files 5.18.107 adds — their class and file basenames, read off Git, mutants_K and "
             "mutants_W qualified by their folders since each bare word names a file bf29cbd already has — every file "
             "bf29cbd tracks swept, data/ aside, beside its control on the pin (tests/fixtures/r107_revert.php); and what "
             "reaches them is said: 092 by bf29cbd's MigrationRunner glob, passed over once applied; the new test sections "
             "by bf29cbd's test globs, tests only; the nine lib classes required by nothing in bf29cbd; and the table "
             "ucrm_catalogue counted, 5.18.103's own; QC-RB2 the web-direct applications with no quotation, counted, their "
             "ids on the sheet (a note: the rollback changes none of them — 5.18.106, put back, handles each exactly as "
             "5.18.107 does, with the same KycService and UcrmQuoteLines: retried by its uCRM ids, Make the quotation where "
             "its Orders card offers it; an admin quotes one in uCRM by hand only where its own card says so).",
             first=CONT), why="H4: QC-RB1")

    # ── H5: QL, the catalogue's rollback, QS, TN, Q, 5.18.98, ECO Bank, the sandbox tests, P0 ──
    doc.sub("against 5.18.105's code (its UcrmQuoteLines and UcrmQuoteLinks\n#            5.18.103's byte for byte): with the switch off 5.18.105 quotes,",
            "against 5.18.106's code (its UcrmQuoteLines and UcrmQuoteLinks\n#            5.18.103's byte for byte): with the switch off 5.18.106 quotes,",
            why="H5: QL")
    doc.lines_between("#   A ROLLBACK TO 5.18.105 LEAVES THE uCRM CATALOGUE AS IT IS;", "#            carries your own command where it did not).",
        para("A ROLLBACK TO 5.18.106 LEAVES THE uCRM CATALOGUE AS IT IS; IT COUNTS THE WEB-DIRECT APPLICATIONS FOR THE RECORD "
             "(docs/72 §G.8, G-10). 5.18.107 keeps the catalogue's code byte for byte — lib/UcrmCatalogue.php, UcrmQuoteLines, "
             "QuoteOrganization, KycService — and adds to its screen only the two-prices warning, so the put-back copies the "
             "same code; its settings and its switch stay in the store as an admin left them, read by 5.18.106 as before. A "
             "web-direct application is handled by 5.18.106's own rules — KycService retries it by its uCRM ids, its Orders "
             "card offers Make the quotation where that applies — exactly as by 5.18.107's, so the rollback leaves none to "
             "quote in uCRM by hand. --rollback counts those with no quotation in stage A and again after the rollback, on a "
             "copy of the book, read-only (QC-RB2: counts in the log, their ids on the sheet) — a note, never a refusal; so "
             "does the deploy's own automatic rollback (V1), after it. When V1's rollback does not verify, its stop says the "
             "rollback did not complete — 5.18.107 may still serve, or some installed files may already be 5.18.106's — and "
             "prints the put-back paragraph and 5.18.106's by-hand put-back, which counts nothing, runs no switch tool and "
             "writes no setting."), why="H5: the catalogue's rollback")
    doc.sub("5.18.105 changes only its log lines (LM) and 5.18.106\n#            does not change it — none of them",
            "5.18.105 changes only its log lines (LM), and 5.18.106\n#            and 5.18.107 do not change it — none of them",
            why="H5: QS")
    doc.lines_between("#            (TenantProfile::current()->id()). By the sha256 pinned here, Q-CA1 reads every file 5.18.106 changes or",
                      "#            form: BR1 counts any left on disk, and QC-RB1 shows that no file 7835906 tracks names them.",
        para(f"(TenantProfile::current()->id()). By the sha256 pinned here, Q-CA1 reads every file 5.18.107 changes or adds "
             f"(its {n_pin}, as the pin has them); Q-G1 before the copy, and RB after a rollback, read every file it changes "
             f"({n_mod}), as 5.18.106 has them; all three read the {n_k} files of 5.18.106 it must not touch — "
             "lib/QuoteOrganization.php, lib/TenantProfile.php, lib/PublicPriceFeed.php, lib/UcrmQuoteLinks.php, "
             "lib/UcrmQuoteLines.php, lib/UcrmCatalogue.php, webhook.php, cron_quote_wa.php, post_kyc.php and "
             "lib/SalesFollowupChecks.php among them (lib/QuoteTaxLine.php, the uCRM catalogue screen, the ladder and its "
             f"tool are among the {n_mod} it changes). The {n_a} files it adds ({N_TESTS} tests, nine lib classes and 092) "
             "have no 5.18.106 form: BR1 counts any left on disk, and QC-RB1 shows that no file bf29cbd tracks names them.",
             first=CONT), why="H5: Q-CA1's and Q-G1's counts")
    doc.subs([
        ("#   5.18.100'S CHECKS, CARRIED, AGAINST 5.18.105'S CODE (TN, \"technician number\").",
         "#   5.18.100'S CHECKS, CARRIED, AGAINST 5.18.106'S CODE (TN, \"technician number\")."),
        ("5.18.100's rule, which 5.18.101 through 5.18.106 do not change:", "5.18.100's rule, which 5.18.101 through 5.18.107 do not change:"),
        ("the owner rule 5.18.100's, as 5.18.105 installs it, as text and on a copy, and the\n"
         "#            numbers a technician owns counted — they keep their owner and work under 5.18.105 as before.",
         "the owner rule 5.18.100's, as 5.18.106 installs it, as text and on a copy, and the\n"
         "#            numbers a technician owns counted — they keep their owner and work under 5.18.106 as before."),
        ("#   5.18.99'S CHECKS, CARRIED, AGAINST 5.18.105'S CODE.", "#   5.18.99'S CHECKS, CARRIED, AGAINST 5.18.106'S CODE."),
        ("live plugin is 5.18.105 — the version, every file 5.18.106 changes (20) by 7835906's sha256 (pinned here),\n"
         "#            and the 5.18.105 files 5.18.106 must not touch (455) by theirs, the quoting files it keeps (QuoteOrganization,\n"
         "#            QuotePdfSource, UcrmLeadSync, the Uganda profile, tools/set_config.php) and 5.18.100's owner\n",
         f"live plugin is 5.18.106 — the version, every file 5.18.107 changes ({n_mod}) by bf29cbd's sha256 (pinned here),\n"
         f"#            and the 5.18.106 files 5.18.107 must not touch ({n_k}) by theirs, the quoting files it keeps (QuoteOrganization,\n"
         "#            QuotePdfSource, UcrmLeadSync, the Uganda profile; tools/set_config.php is among the changed) and 5.18.100's owner\n"),
        ("Q-CA1 the installed files are release/5.18.106's by the sha256\n#            pinned here, the 5.18.105 files it must not touch 5.18.105's,",
         "Q-CA1 the installed files are release/5.18.107's by the sha256\n#            pinned here, the 5.18.106 files it must not touch 5.18.106's,"),
        ("#   5.18.98'S CHECKS, CARRIED, AGAINST 5.18.105'S CODE. Its copy checks C1–C5, on ONE VACUUM INTO copy, 5.18.105's code and\n"
         "#            5.18.106's from Git (A23), then 5.18.105's from Git and the installed (CA2):",
         "#   5.18.98'S CHECKS, CARRIED, AGAINST 5.18.106'S CODE. Its copy checks C1–C5, on ONE VACUUM INTO copy, 5.18.106's code and\n"
         "#            5.18.107's from Git (A23), then 5.18.106's from Git and the installed (CA2):"),
        ("#            5.18.105 (7835906), every file it tracks byte for byte;", "#            5.18.106 (bf29cbd), every file it tracks byte for byte;"),
        ("BK1 cash in hand under the installed 5.18.105\n#            and under the pin,", "BK1 cash in hand under the installed 5.18.106\n#            and under the pin,"),
        ("books (A24, R26), 5.18.105's code against 5.18.106's, both with the gate;", "books (A24, R26), 5.18.106's code against 5.18.107's, both with the gate;"),
        ("#            R23a–R23g; after a rollback, RB). 5.18.106's own tests (section U of tests/test_quote_followup.php,\n"
         "#            tests/quote_followup/U_unjam.php and mutants_U.php, and tests/fixtures/r106_revert.php) and 5.18.105's\n"
         "#            (tests/test_log_mask.php and its sections) run in the suites only;",
         "#            R23a–R23g; after a rollback, RB). 5.18.107's own tests (its sections under tests/quote_followup/ and\n"
         "#            tests/catalogue_direct/, their weakened copies and drivers, and tests/fixtures/r107_revert.php), 5.18.106's\n"
         "#            (section U of tests/test_quote_followup.php, tests/quote_followup/U_unjam.php and mutants_U.php, and\n"
         "#            tests/fixtures/r106_revert.php) and 5.18.105's (tests/test_log_mask.php and its sections) run in the suites only;"),
        ("own configuration, as 5.18.105's code and 5.18.106's both read it,", "own configuration, as 5.18.106's code and 5.18.107's both read it,"),
    ], why="H5: the carried paragraphs' live release and counts")

    # ── H6: the switches paragraph, the commit, the live release ──
    doc.sub("#            (R11). One exception, a rollback's: followup_auto_send '0', the one write of this script (AS-RB1, root\n"
            "#            docs/75 §L.7) — the deploy itself sets nothing. No switch tool runs,",
            "#            (R11). This script sets nothing, and neither does its rollback (root docs/76 §T.7). No switch tool runs,",
            why="H6: the switches paragraph: no write")
    doc.sub("The uCRM catalogue needs no step: 5.18.106 changes nothing of it, and its switch stays as an admin\n",
            "The uCRM catalogue needs no step: 5.18.107 changes nothing of how it is read, and its switch stays as an admin\n",
            why="H6: the catalogue needs no step")
    doc.lines_between("#   THE COMMIT IT INSTALLS IS A RELEASE COMMIT, NOT THE BRANCH TIP.",
                      "#            master.php and main.php are checked byte for byte (A0).",
        para("THE COMMIT IT INSTALLS IS A RELEASE COMMIT, NOT THE BRANCH TIP. The pin below, on release/5.18.107, is cut on "
             f"bf29cbd (5.18.106, live): {n_pin} files — {n_mod} changed, {n_a} added ({N_TESTS} tests, nine lib classes and "
             "092) — each file's sha256 pinned here. Stage A refuses a pin whose parent is not 5.18.106, whose short hash "
             "names another commit, whose delta is not exactly these files by these hashes, which carries any migration but "
             "092 (added, never modified, by its sha256 and md5; 089–091 held at the pin by theirs), and a checkout holding a "
             "file git does not track under the plugin folder outside data/. Its master.php and main.php are checked byte "
             "for byte (A0)."), why="H6: the commit")
    doc.sub("# 5.18.105 must be the live plugin: this script refuses any other live commit or version — deploy 5.18.105 first.",
            "# 5.18.106 must be the live plugin: this script refuses any other live commit or version — deploy 5.18.106 first.",
            why="H6: the live release")

    # ── H7: the operator's rules (§T.9) ──
    doc.lines_between("# Before the deploy (root docs/75 §L.8): 5.18.105 is live",
                      "# own line with its own log, and the deploy and its rollback are never in one copyable block (root docs/44 §16.9).",
        para("Before the deploy (root docs/76 §T.9): 5.18.106 is live and its --after-only has PASSED (22 Nov 18:26:41 and "
             "23 Nov 06:37:15 UTC; root docs/07), and the quarantine's --check begun after 5.18.106's copy has PASSED (23 Nov "
             "06:46:27 UTC) — A14 reads that record, and needs the quarantine's --check after any later copy or rollback. "
             "K's, Q's and W.1's switches are off — quoted_no_prices_enabled, connection_quote_enabled and "
             "ai_burst_merge_enabled, wherever their readers read them: NS-G1 refuses otherwise, and NS-B1 again after "
             "DEPLOY is typed; nothing in 5.18.106 writes them. The ladder is otherwise kept exactly as found: the steps that "
             "are on keep running (5.18.106's claim-switch refusals retired, root docs/76 §T.4). Do not run "
             "scripts/sales-followup.sh or tools/set_config.php while the deploy, its --after-only or its rollback runs — "
             "NS-B1, LK-B1 and LK-CA1 would read the change; root docs/76 §K's switch-off and drain come before the rollback "
             "command, each alone. A --check first is wise: MG-G1, NS-G1, LK-G1, LM-G1 and LM-G2 refuse or pass, QF-N1–QF-N7 "
             "and SA note, nothing changed. Every command this script prints for later stands alone on its own line with its "
             "own log, and the deploy and its rollback are never in one copyable block (root docs/44 §16.9).",
             first="# ", cont="# "), why="H7: before the deploy")
    doc.sub("# quarantine's --check has PASSED after 5.18.105's deploy;", "# quarantine's --check has PASSED after 5.18.106's deploy;",
            why="H7: the deploy")
    doc.lines_between("# Then --after-only, read-only, nothing deployed — ME-CA1, UJ-CA and UJ-ON;", "# prints it):",
        para("Then --after-only, read-only, nothing deployed — MG-CA1, MG-CA2, NA-CA1, ME-CA1 and ME-CA2; LM-ON, LK-AO; "
             "QF-CA1–QF-CA8, VN-M1–VN-K2, OF-M1–OF-CA5, AC-CA1, AC-CA2; QF-ON0–QF-ON5, VA-ON1, VN-ON1–VN-ON4, OF-ON1, OF-ON2 "
             "and AC-ON1 for each step that is on — each run its own command, alone, with its own log: at least 20 minutes "
             "after the copy (the summary prints the time; the 600-s jobs start within about 900 s of it, and until master's "
             "record shows each of the four follow-up jobs started and returned after the copy ME-CA1 reads NOT YET, exit "
             "status 3, and the summary says when to run it again), and again after each of root docs/76 §K's steps. Then the "
             "quarantine's own --check, its own (the summary prints it):", first="# ", cont="# "), why="H7: --after-only")
    doc.lines_between("# Then, after a PASSED --after-only and only then, the owner's own steps (root docs/75 §F)", "# log in /root/dnb-report:",
        para("Then, after a PASSED --after-only and only then, the owner's own steps (root docs/76 §K), each its own command, "
             "never run by this script — that --after-only's summary prints them, one per line, in this order: K "
             "(quoted_no_prices_enabled; it needs step 3, tracking, on), then Q (connection_quote_enabled, once "
             "ai_hardware_expert reads on and the settings text v10 is saved by its own command, handed over separately), "
             "then W.1 (ai_burst_merge_enabled) — each tested from a non-staff number, and --after-only again after each. X, "
             "O7, W.2, W.3, W.5 and W.6 need no switch. A NOT YET or a FAILED --after-only prints none of them. Every step of "
             "the ladder stays yours alone, one at a time and only in the ladder's order — this script turns none on or off. "
             "The report, read-only on a copy of the book (P1), its own command, its log in /root/dnb-report:",
             first="# ", cont="# "), why="H7: the owner's steps")
    doc.lines_between("# The rollback is a separate command, printed at the end of the deploy's log, after the verdict.",
                      "# followup_auto_send off.",
        para("The rollback is a separate command, printed at the end of the deploy's log, after the verdict. It is never "
             "pasted together with the ones above: pasted together, the shell runs both (root docs/44 §16.9). Before it, "
             "root docs/76 §K's two commands, each alone: ai_burst_merge_enabled off, then --check burst-drain until (a) and "
             "(b) both read 0 twice, the second at least 2 minutes after the switch-off (a reading whose control is 0 does "
             "not count; if the rollback cannot wait, roll back anyway and hand the conversation ids it lists to staff). "
             "Before ROLLBACK is typed it reads the ladder (LK-RB0), ai_burst_merge_enabled through the pin's own reader and "
             "the drain (again 125 s later where the switch reads off), K's and Q's switches and the settings text "
             "(rb_pre107), and says in capitals what to run first where §K's rule does not hold, with your own commands, each "
             "alone, never run by it — answer anything but ROLLBACK, run them, then the rollback again; it is never refused "
             "for it. Once ROLLBACK is typed it puts bf29cbd back — the documented deploy of 5.18.106 — and writes nothing: "
             "no switch tool, no off-hook, no key; every switch is kept and every step that is on keeps running; every close "
             "5.18.106 made stays, and Part X's and Part K's (nothing reopens one); 089–091, 092 and the assistant-off list "
             "stay — no 5.18.106 file reads the list, so listed numbers get AI replies again (RB-X counts them). Then RB107 "
             "reads the put-back: master with the four jobs last, every ladder key and the tool's show as before (LK-RB1), "
             "no table's rows fell, 5.18.105's masked lines still in place (LM-RB1), RB-X's counts, and the pin's drain once "
             "more on a copy, its conversation ids for staff. ECO Bank, every statement kept, its switch, the quoting "
             "organization, the uCRM catalogue's settings, the channel registry and every number's owner stay exactly as "
             f"they are; the {n_a} files 5.18.107 adds stay on disk, named by nothing bf29cbd tracks (QC-RB1). A later "
             "deploy of 5.18.107 goes with --redeploy, only with K, Q and W.1 off (NS-G1). The put-back by hand "
             "(PUTBACK_CMD) also writes no setting; every message that prints it prints the put-back paragraph first.",
             first="# ", cont="# "), why="H7: the rollback")

    # ── H8: the options ──
    doc.lines_between("#   --after-only         the deploy already happened: run the checks again — ME-CA1, UJ-CA and UJ-ON",
                      "#                        send window after 15:00 UTC, and after each step of the ladder",
        opt("--after-only",
            "the deploy already happened: run the checks again — MG-CA1, MG-CA2 and NA-CA1 (092 and the list's reader), "
            "ME-CA1 (master's own record: PASS, FAIL or NOT YET; NOT YET withholds PASSED and the owner's steps, exit status "
            "3) and ME-CA2, LM-ON and LK-AO (the ladder against the deploy's, a note), QF-CA1–QF-CA8, VN-M1, VN-M2, "
            "VN-CA1–VN-CA4, VN-K1, VN-K2, OF-M1, OF-M2, OF-CA1–OF-CA5, AC-CA1, AC-CA2, and QF-ON0–QF-ON5, VA-ON1, "
            "VN-ON1–VN-ON4, OF-ON1, OF-ON2 and AC-ON1 (each step that is on, through the installed tool's --check on a copy; a "
            "step that is off is not judged), R23, C1–C5 (CA2), BK1, BK2 and G3 on a new copy, the drift against the deploy's "
            "own reading (CA3), Q-CA2, Q-CA3 and Q-CA6 against stage A's readings recorded in the state file, TN-CA1–TN-CA3, "
            "QS-CA1, QL-CA2, QL-CA3 (the Plans and Hardware screens and the Orders card without a session; post_sync.php's "
            "link and quotation actions as text), QC-CA1 (what the forms offer, its item-by-item comparison with stage A's a "
            "note naming its direction), QC-CA2, QC-CA3 and QC-ON1–QC-ON4 (the switch as found: on, QC-ON1 ok and the "
            "installation product set and passing and every standard quotation usable judged; off, one note; G-7 over the "
            "web-direct records either way); 088 applied and complete, its rows none fewer than the deploy's; R19 conclusive "
            "once master has run the retry job after the copy; its own cash sheet. After a PASSED run its summary prints the "
            "owner's steps (root docs/76 §K). Run it at least 20 minutes after the copy, and after each of those steps"),
        why="H8: --after-only")
    doc.sub("#   --move-list-only     read-only, before the deploy, while 5.18.105 is live:",
            "#   --move-list-only     read-only, before the deploy, while 5.18.106 is live:", why="H8: --move-list-only")
    doc.lines_between("#   --redeploy           deploy 5.18.106 again after its own rollback:",
                      "#                        and its PASSED --after-only reads that rollback's record all the same (root docs/75 §L.8)",
        opt("--redeploy",
            "deploy 5.18.107 again after its own rollback: the files it adds, which the rollback leaves on disk, are expected "
            "there (BR1 counts them, and the copy replaces them); MG-G1 reads 092 present and whole, QF-G1 reads 5.18.104's "
            "tables as it left them, the ladder rule holds as on the first deploy (LK), and NS-G1 refuses while K's, Q's or "
            "W.1's switch reads on (root docs/76 §T.4). Without it the deploy is the same — BR1 notes the files"),
        why="H8: --redeploy")
    doc.sub("owner and sign-off readings, CS-G1, U1–U5 and FE-G1 — and stop before the backup:",
            "owner and sign-off readings, MG-G1, NS-G1 and SA's notes — and stop before the backup:", why="H8: --check")
    doc.sub("5.18.106 then quotes as 5.18.105 does with it off", "5.18.107 then quotes as 5.18.106 does with it off",
            why="H8: --quote-company-off")
    doc.lines_between("#   --clear-switches     REFUSED (root docs/75 §L.7; refused since 5.18.105)",
                      "#                        scripts/sales-followup.sh off <step>, its own command",
        opt("--clear-switches",
            "REFUSED (root docs/76 §T.7; refused since 5.18.105): 5.18.107 never clears a ladder key and writes no key, nor "
            "does its rollback; turn a step off yourself with scripts/sales-followup.sh off <step>, its own command"),
        why="H8: --clear-switches")
    doc.lines_between("#   --rollback           typed ROLLBACK, after LK-RB0 and AS-RB0", "#                        no setting",
        opt("--rollback",
            "typed ROLLBACK, after rb_pre107 (root docs/76 §T.7) — LK-RB0, ai_burst_merge_enabled and the drain, K's and Q's "
            "switches, the settings text, RB-X — each said, never a refusal: then 5.18.106 (bf29cbd) put back by the "
            "documented deploy, nothing written, every switch kept — no switch tool, no off-hook, no by-hand put-back of rows "
            "or keys — then checked: RB107 (master, LK-RB1, the rows, the open quote follow-ups and running offers counted, "
            "LM-RB1: 5.18.105's masked lines still in place, RB-X's counts, the pin's drain once more on a copy), 5.18.106's "
            "files by sha256, its sign-off line, owner rule and quoting code in place, the files 5.18.107 adds left on disk "
            "and named by nothing bf29cbd tracks (QC-RB1), the Cashbook's list and ECO Bank exactly as they were, D2 = A on "
            "a copy of the book (its own cash sheet), 5.18.95's security fixes, the documents, the database (the ledger the "
            "same, or 092 added only where it was absent at the rollback's stage A), and the numbers a technician owns "
            "counted; its record goes to a file of its own. It refuses a copy that stopped part-way, and prints the put-back "
            "paragraph and the by-hand put-back of 5.18.106's code, which writes no setting"),
        why="H8: --rollback")

    # ── H9: what it never does, and what it writes ──
    doc.subs([
        ("# the quarantine's record or folder; open the live book read-write but for the VACUUM INTO copy the backup makes too\n"
         "# and a rollback's one write (AS-RB1, through the plugin's own writer); run\n",
         "# the quarantine's record or folder; open the live book read-write but for the VACUUM INTO copy the backup makes too; run\n"),
        ("render a page or a CSV with a login; switch, set,\n# clear or verify anything but a rollback's followup_auto_send '0' (AS-RB1, root docs/75 §L.7) — bank_screen_enabled,\n"
         "# ucrm_quote_organization_id, wa_inbox_roles and every key of the sales follow-up's ladder included:",
         "render a page or a CSV with a login; switch, set,\n# clear or verify anything (root docs/76 §T.7) — bank_screen_enabled, ucrm_quote_organization_id, wa_inbox_roles,\n"
         "# K's, Q's and W.1's switches and every key of the sales follow-up's ladder included:"),
        ("# pair, verify or switch a number; change a staff row, a role or is_admin;",
         "# pair, verify or switch a number, or list or lift one on the assistant-off list (wa_no_ai is written only on a\n"
         "# throwaway copy: NA-G1, NA-CA1); change a staff row, a role or is_admin;"),
        ("# the time of the copy on the files this release changes or adds, and, in a rollback, followup_auto_send '0' in\n"
         "# <data>/kyc_config.json and its store row (AS-RB1, root docs/75 §L.7) — never a key of the ladder.",
         "# and the time of the copy on the files this release changes or adds — never a key, in any mode (root docs/76 §T.7)."),
    ], why="H9: never, and writes")

    # ── H10: the stages ──
    doc.subs([
        ("# Stages:  A before-evidence: the release delta by its pinned sha256, no migration, and 089–091 at the pin by sha256\n"
         "#            and md5 (A0);",
         "# Stages:  A before-evidence: the release delta by its pinned sha256, one migration added (092, by its sha256 and\n"
         "#            md5), and 089–091 at the pin by sha256 and md5 (A0);"),
        ("every file 5.18.106 changes and the 5.18.105 files it keeps by\n#            their pinned",
         "every file 5.18.107 changes and the 5.18.106 files it keeps by\n#            their pinned"),
        ("#            and AC-N2; 5.18.105's LM-G1 and LM-G2; 5.18.106's CS-G1 (a refusal), U1–U5 (notes; a deploy keeps\n"
         "#            U1–U4 in the state file, UJ_A) and FE-G1 (a refusal: U4 read followup_enabled off); the backup,\n",
         "#            and AC-N2; 5.18.105's LM-G1 and LM-G2; 5.18.107's MG-G1, 092 in QF-G2's trial, neg092 and NA-G1 (refusals),\n"
         "#            NS-G1 (a refusal: K's, Q's or W.1's switch reading on) and SA (notes; a deploy keeps the burst marks' reading\n"
         "#            in the state file, BD_A); the backup,\n"),
        ("#          B CS-B1, LK-B1 and the baselines, then the documented deploy (or, with --rollback, rb_pre106 — LK-RB0 and\n"
         "#            AS-RB0 — and, once ROLLBACK is typed, AS-RB1's one write, followup_auto_send '0', then the documented\n"
         "#            deploy of 5.18.105 — no switch tool); the copy's time on each file\n",
         "#          B NS-B1, LK-B1 and the baselines, then the documented deploy (or, with --rollback, rb_pre107 — LK-RB0,\n"
         "#            ai_burst_merge_enabled and the drain, K's and Q's switches, the settings text, RB-X — and, once ROLLBACK is\n"
         "#            typed, the documented deploy of 5.18.106 — nothing written, no switch tool); the copy's time on each file\n"),
        ("#          V as 5.18.105 (V1's automatic rollback sets followup_auto_send off first, AS-RB1, runs no switch tool, and\n"
         "#            RB106 once verified): the public pages and the\n",
         "#          V as 5.18.106 (V1's automatic rollback writes nothing, runs no switch tool, and RB107 once verified; the run\n"
         "#            wrote no burst mark, ai_burst_merge_enabled read off since stage A): the public pages and the\n"),
        ("#          R 5.18.106 installed: ME-CA1 and UJ-CA (UJ-ON in --after-only); LK-CA1, LK-CA2, LK-ON and LM-CA1, LM-CA2\n",
         "#          R 5.18.107 installed: MG-CA1, MG-CA2, NA-CA1, ME-CA1 and ME-CA2; LK-CA1, LK-CA2, LK-ON and LM-CA1, LM-CA2\n"),
        ("#            pinned sha256, 5.18.105's kept files by theirs,\n"
         "#            php -l on every PHP file it changes or adds; R7 the ledger the same (091 last); R2–R22 as 5.18.103 but R7 and\n"
         "#            R13 (R3 with 088 beside 086 and 087: BA1, every row unchanged; R7 the database's ledger exactly as stage A read\n"
         "#            it, 091 last — 5.18.106 adds no migration — and its size; R13 the event processor at 5.18.104's Uganda\n"
         "#            signature, which 5.18.105 and 5.18.106 keep, ai.media among its workers' events;\n",
         "#            pinned sha256, 5.18.106's kept files by theirs,\n"
         "#            php -l on every PHP file it changes or adds; R7 the ledger with 092 added (a first deploy) or the same (a\n"
         "#            redeploy); R2–R22 as 5.18.103 but R7 and\n"
         "#            R13 (R3 with 088 beside 086 and 087: BA1, every row unchanged; R7 the database's ledger as stage A read it,\n"
         "#            092 added once on a first deploy (db_mig107), and its size; R13 the event processor at its Uganda\n"
         "#            signature, ai.media among its workers' events, and the queued no-AI alert left for the worker;\n"),
        ("#          RB after a rollback (LK-RB0 and AS-RB0 before it, AS-RB1 between; RB106 after it — master, LK-RB1, the rows,\n"
         "#            LM-RB1, AS-RB2): 5.18.105's files by sha256 where 5.18.106 changed them (Q-G1's set of 20), every ladder\n"
         "#            key as before, followup_auto_send off, the 3 files 5.18.106 adds on disk, named by nothing 7835906\n"
         "#            tracks (QC-RB1, re-pointed), the uCRM catalogue's table counted, 5.18.103's own screen's and read by 5.18.105\n",
         "#          RB after a rollback (rb_pre107 before it; RB107 after it — master, LK-RB1, the rows, LM-RB1, RB-X, the pin's\n"
         f"#            drain on a copy): 5.18.106's files by sha256 where 5.18.107 changed them (Q-G1's set of {n_mod}), every ladder\n"
         f"#            key as before, the {n_a} files 5.18.107 adds on disk, named by nothing bf29cbd\n"
         "#            tracks (QC-RB1, re-pointed), the uCRM catalogue's table counted, 5.18.103's own screen's and read by 5.18.106\n"),
        ("#          F summary: which of the checks each name is (5.18.106's own first — CS-G1, CS-B1, U1–U5, FE-G1, UJ-CA, UJ-ON,\n"
         "#            ME-CA1, AS-RB0, AS-RB1, AS-RB2, RB106; then 5.18.105's, carried",
         "#          F summary: which of the checks each name is (5.18.107's own first — MG-G1, MG-CA1, MG-CA2, NA-G1, NA-CA1,\n"
         "#            NS-G1, NS-B1, SA, ME-CA1, ME-CA2, RB-X, RB107; then 5.18.105's, carried"),
        ("#            sheet's path; three states, FAIL first — PASSED, NOT YET (UJ-ON's, --after-only only, exit status 3) and\n"
         "#            FAILED; --after-only (20 minutes after the copy, after a close pass, the next day inside the send window) and\n"
         "#            the quarantine's --check, each its own command with its own log; the screens (none changes but the Follow-ups\n"
         "#            screen's closes; every switch kept by the deploy) and the operator's steps — where the ladder stands; after a\n"
         "#            PASSED --after-only alone, the owner's steps of root docs/75 §F (followup_daily_cap, then step 6 and its\n"
         "#            --check, later followup_run_limit), each its own command, and, after a deploy that followed a rollback\n"
         "#            which set followup_auto_send off, your own command to turn it back on; the rollback last, after the\n"
         "#            verdict, its own block, and the put-back by hand, which does not switch followup_auto_send off",
         "#            sheet's path; three states, FAIL first — PASSED, NOT YET (ME-CA1's, --after-only only, exit status 3) and\n"
         "#            FAILED; --after-only (20 minutes after the copy, and after each of root docs/76 §K's steps) and the\n"
         "#            quarantine's --check, each its own command with its own log; the screens (the assistant-off list and the\n"
         "#            Inbox's toggle, the AI Knowledge Base screen, the two-prices warning; every switch kept by the deploy) and\n"
         "#            the operator's steps — where the ladder stands; after a PASSED --after-only alone, the owner's steps of root\n"
         "#            docs/76 §K (K, then Q with the settings text v10, then W.1), each its own command; the rollback last, after\n"
         "#            the verdict, its own block — §K's switch-off and drain first, each alone — and the put-back by hand, after\n"
         "#            the put-back paragraph"),
    ], why="H10: the stages")

    # ── H11: the summary ──
    doc.lines_between('echo "  names             CS-G1, CS-B1, U1–U5, FE-G1, UJ-CA, UJ-ON, ME-CA1, AS-RB0, AS-RB1, AS-RB2 and RB106 are"',
                      'echo "                    docs/74 §H.4, §H.5), carried — LM-RB1 inverted, LK-G1\'s two notes gone (root docs/75 §L.3,"',
        'echo "  names             MG-G1, MG-CA1, MG-CA2, NA-G1, NA-CA1, NS-G1, NS-B1, SA, ME-CA1, ME-CA2, RB-X and RB107 are"\n'
        'echo "                    $EXPECTED_VERSION\'s own (root docs/76 §T.3–§T.7; ME-CA1 re-pointed, §W.7), 5.18.106\'s own"\n'
        'echo "                    retired with them (root docs/76 §T.4–§T.7, TD1); LK-G1, LK-B1, LK-CA1, LK-CA2, LK-ON,"\n'
        'echo "                    LK-AO, LK-RB0, LK-RB1, LM-G1, LM-G2, LM-CA1, LM-CA2, LM-ON and LM-RB1 are 5.18.105\'s (root"\n'
        'echo "                    docs/74 §H.4, §H.5), carried — LM-RB1 inverted, LK-G1\'s two notes gone (root docs/75 §L.3,"',
        why="H11: names")
    doc.line('  echo "  what changed      on this Uganda server: $EXPECTED_VERSION\'s enquiry follow-up sender',
        '  echo "  what changed      on this Uganda server, in force from the copy: Part X\'s assistant-off list (migration 092, created '
        'empty — no number is on it until staff list one; while it cannot be read, no Uganda customer gets an AI reply; root docs/76 §X), '
        'F-1, F-7, F-8 and F-11 fixed, rule 8 in the absolute rules (W.2), the office line on the customer-care hand-over (W.3), the AI '
        'Knowledge Base screen reachable (W.5), the two-prices warning (W.6) and the tax lines without \\"Nothing is added on top.\\" '
        '(O7); built and OFF, each its own switch until you turn it on (root docs/76 §K): K, Q and W.1. The sales follow-up\'s ladder '
        'is $BASELINE_VERSION\'s but for W.1\'s two settings$( if [ "$MODE" = "after" ]; then echo "; its keys as LK-AO above reads '
        'them against the deploy\'s: the steps on now ($( [ -n "$LK_AO_ON" ] && printf \'%s\' "$LK_AO_ON" | tr \',\' \' \' || printf '
        '\'not read — LK-AO above\'))"; else echo ", and every key of it is as this run found it (LK-CA1), K\'s, Q\'s and W.1\'s switches '
        'off (NS-B1): the steps that are on keep running ($(qf_kv "${LK_B1:-$LK_G1}" on | tr \',\' \' \'))"; fi)"',
        why="H11: what changed")
    doc.subs([
        ("QuoteOrganization.php, QuoteTaxLine.php, TenantProfile.php, PublicPriceFeed.php, includes/api/api_retailer.php,",
         "QuoteOrganization.php, TenantProfile.php, PublicPriceFeed.php, includes/api/api_retailer.php,"),
        ("the database — nothing written by this run and no migration added (R7, BA1);",
         "the database — nothing written by this run (BA1), 092 added by the plugin's own runner the only change of its ledger (R7, MG-CA1);"),
        ("(under $EXPECTED_VERSION the claim switches, steps 6, 11 and 12, may be on once its --after-only has PASSED; root docs/75 §L.3, §L.8, DL3)",
         "(K, Q and W.1 among them once you turn them on; root docs/76 §K)"),
        ("as this run found it (LK-B1, LK-CA1), none of the three claim switches present (CS-G1, CS-B1)",
         "as this run found it (LK-B1, LK-CA1), K's, Q's and W.1's switches off (NS-G1, NS-B1)"),
        ("which 5.18.104 through 5.18.106 carry unchanged and add none to", "which 5.18.104 through 5.18.107 carry unchanged and add none to"),
        ("the assistant's prompt (A10, R16);",
         "the assistant's prompt as A10 and R16 build it, rule 8 and K's and Q's per-turn facts left out (root docs/76 §T.14, DF-1);"),
        ('echo "  migrations        none added: 089, 090 and 091 as 5.18.104 left them (QF-G1, QF-CA1), the ledger the same before and after (R7)"',
         'echo "  migrations        092 (wa_no_ai, created empty), applied by the plugin\'s own runner at its first boot after a first deploy, or by an earlier deploy (MG-G1, MG-CA1, MG-CA2, R7 above); 089, 090 and 091 as 5.18.104 left them (QF-G1, QF-CA1)"'),
        ('echo "                    rollback, of the web-direct ones with no quotation yet — a record only: 5.18.105, put back,"',
         'echo "                    rollback, of the web-direct ones with no quotation yet — a record only: 5.18.106, put back,"'),
    ], why="H11: what did not, migrations, the cash sheet")
    doc.lines_between('  echo "  check it          --after-only, three runs, each its own command',
                      '  echo "                    and the next day, Monday to Saturday, after 15:00 UTC and before 17:00 UTC',
        '  echo "  check it          --after-only, each run its own command, alone, with its own log and its own cash sheet:"\n'
        '  echo "                    at least 20 minutes after the copy ($( [ -n "${R19_COPY_T:-}" ] && utc_of $(( R19_COPY_T + 1200 )) || echo "the copy\'s time is not recorded")) — the 600-s jobs start within about 900 s of it: ME-CA1 then reads master\'s own record (NOT YET, exit status 3, until each of the four follow-up jobs has started and returned after the copy, and it says when to run it again), and R19 whether the quarantine still holds, conclusively once master has run the retry job after the copy:"\n'
        '  echo "      $AFTER_CMD"\n'
        '  echo "                    and again after each of root docs/76 §K\'s steps (the owner\'s steps, printed after a PASSED run)$( [ -n "$F_QORG" ] && echo " — the uCRM catalogue\'s switch is said as found (QC-ON1: ok while it is on, a note while it is off)"):"',
        why="H11: check it")
    doc.lines_between('  echo "  the screens       as the steps now on make them — My quotations once list is on, WhatsApp offers once"',
                      '  echo "                    ECO Bank as before. No screen of uCRM\'s changes. Do NOT make a test quote for a real customer:"',
        '  echo "  the screens       as the steps now on make them — My quotations once list is on, WhatsApp offers once"\n'
        '  echo "                    offers-test, the Inbox\'s voice transcript once voice-staff, the quotation chases once chase;"\n'
        '  echo "                    and on Uganda, whatever the steps: the assistant-off list on Engage → WhatsApp → AI setup and"\n'
        '  echo "                    the Inbox\'s toggle, WhatsApp → AI Knowledge Base (admins), and the two-prices warning on the"\n'
        '  echo "                    uCRM catalogue screen and the KYC form (root docs/76 §X, §W.5, §W.6); the WhatsApp AI screen\'s"\n'
        '  echo "                    OpenAI key field says set or not set and never shows the key (VN-K2); every other screen as"\n'
        '  echo "                    under $BASELINE_VERSION: the Salesperson numbers card, the Cashbook and ECO Bank as before. No"\n'
        '  echo "                    screen of uCRM\'s changes. Do NOT make a test quote for a real customer:"',
        why="H11: the screens (after)")
    doc.lines_between('  echo "  the screens       no screen changes: every switch of the sales follow-up is as this run found it (LK-CA1, QF-CA5),"',
                      '  echo "                    close passes run. No screen of uCRM\'s changes. Do NOT make a test quote for a real customer:"',
        '  echo "  the screens       every switch of the sales follow-up is as this run found it (LK-CA1, QF-CA5), K, Q and W.1"\n'
        '  echo "                    off, so every screen is as under $BASELINE_VERSION with the steps that are on — but, on Uganda,"\n'
        '  echo "                    the assistant-off list on Engage → WhatsApp → AI setup and the Inbox\'s toggle, WhatsApp → AI"\n'
        '  echo "                    Knowledge Base (admins), and the two-prices warning on the uCRM catalogue screen and the KYC"\n'
        '  echo "                    form (root docs/76 §X, §W.5, §W.6). No screen of uCRM\'s changes. Do NOT make a test quote for"\n'
        '  echo "                    a real customer:"',
        why="H11: the screens (deploy)")
    doc.lines_between('  echo "  your steps        yours alone, one at a time and only in the ladder\'s order (root docs/73 §H.2.3, C6) — this"',
                      '  echo "                    the ladder stands, read-only:"',
        '  echo "  your steps        yours alone, one at a time and only in the ladder\'s order (root docs/73 §H.2.3, C6) — this"\n'
        '  echo "                    script does none of them, and kept every one as it found it. K, Q and W.1 only once"\n'
        '  echo "                    $EXPECTED_VERSION serves and its --after-only (above) has PASSED — that run prints the owner\'s"\n'
        '  echo "                    steps of root docs/76 §K, in their order. Each step: on, then its check, each its own command,"\n'
        '  echo "                    alone, its log in /root/dnb-sales; never while this script, its --after-only or its rollback"\n'
        '  echo "                    runs. Where the ladder stands, read-only:"',
        why="H11: your steps")
    doc.lines_between('  echo "                    The enquiry follow-up report (read-only on a copy of the book) still runs — its two pinned"',
                      '  echo "                    (root docs/75 §L.2). Its own command, alone:"',
        '  echo "                    The enquiry follow-up report (read-only on a copy of the book) still runs — its two pinned"\n'
        '  echo "                    files are unchanged — but its section E front mirrors 5.18.105\'s due(), which 5.18.106 changed"\n'
        '  echo "                    (F3; lib/FollowUpService.php is unchanged by $EXPECTED_VERSION), so it misreads until it is"\n'
        '  echo "                    updated. Its own command, alone:"',
        why="H11: the enquiry report")
    doc.sub('  echo "                    A step behaves under $EXPECTED_VERSION as under $BASELINE_VERSION but for the enquiry follow-ups (F1–F5, root docs/75 §C)."',
            '  echo "                    A step behaves under $EXPECTED_VERSION as under $BASELINE_VERSION, but a number on the assistant-off list gets no follow-up, offer, chase or win-back, and once K is on a customer holding a live quotation gets no AI-written enquiry follow-up (root docs/76 §X, §E.3)."',
            why="H11: a step behaves")
    doc.lines_between('  echo "  deploy again      only once the cause of the rollback is understood. First every claim switch off (CS-G1"',
                      '  echo "                    --after-only prints your own command to turn it back on:"',
        '  echo "  deploy again      only once the cause of the rollback is understood, with K\'s, Q\'s and W.1\'s switches off"\n'
        '  echo "                    (NS-G1 refuses otherwise; this rollback wrote nothing, so a switch you turned off before it"\n'
        '  echo "                    stays off until you turn it on). Then --redeploy, its own command, alone:"',
        why="H11: deploy again")
    doc.sub('  echo "                    The report, read-only on a copy of the book (P1; its wrapper pinned to $BASELINE_COMMIT, whose"\n'
            '  echo "                    report and inputs $EXPECTED_VERSION keeps byte for byte), its own command, alone:"',
            '  echo "                    The report, read-only on a copy of the book (P1; its wrapper pinned to 7835906, 5.18.105\'s"\n'
            '  echo "                    release commit, whose report and seven inputs $EXPECTED_VERSION keeps byte for byte), its own"\n'
            '  echo "                    command, alone:"', why="H11: the report wrapper's literal pin (root docs/76 §T.2)")
    doc.sub("The owner's steps (root docs/75 §F) wait for a PASSED --after-only.", "The owner's steps (root docs/76 §K) wait for a PASSED --after-only.",
            why="H11: NOT YET")
    doc.sub("which asks G3's question again and nothing\n# of F3's,", "which asks G3's question again and nothing\n# of ME-CA1's,",
            why="H11: ECO Bank's check comment")
    OWNER = (
        "# 5.18.107 (root docs/76 §K, §T.9): after a PASSED --after-only — no FAIL and no NOT YET — the owner's own steps, each\n"
        "# its own command on its own line with its own log, printed and never run by this script, in §K's order: 0 X and O7,\n"
        "# nothing to switch; 1 K — K_ON_CMD, set_config as the book's owner on the live data folder, its output cut where the\n"
        "# settings listing begins (it needs step 3, tracking, on: LK-AO's reading above says whether it is); 2 Q — Q_ON_CMD,\n"
        "# the same form, only once ai_hardware_expert reads on and the settings text v10 is saved by its own command, which\n"
        "# this script does not carry (handed over separately; the v9-restore in a block of its own, only if needed, asking for\n"
        "# a typed RESTORE); 3 W.1 — W1_ON_CMD, through the switch tool's wrapper; 4 W.2–W.6, tests only. Each command is a\n"
        "# named display variable (partDV; root docs/76 §T.8). A NOT YET or a FAILED run prints none of them (a NOT YET prints\n"
        "# the one --after-only to run again). 5.18.106's §F steps (the cap, the chase, the run limit) are gone.\n"
        "if [ \"$MODE\" = \"after\" ] && [ \"$FAIL\" = \"0\" ] && [ \"$NOT_YET\" = \"0\" ]; then\n"
        "  echo\n"
        "  echo \"  the owner's steps root docs/76 §K — $EXPECTED_VERSION serves and this --after-only PASSED, so its switches may\"\n"
        "  echo \"                    follow, yours alone, in this order, one at a time, each its own command, alone, with its own\"\n"
        "  echo \"                    log, each tested from a non-staff number, then --after-only again (check it, above); this\"\n"
        "  echo \"                    script runs none of them, and none while it runs.\"\n"
        "  echo \"                    0. X and O7: nothing to switch — the assistant-off list works as soon as a number is added.\"\n"
        "  echo \"                    The O7 test: the next Uganda quotation staff send carries the tax sentence without \\\"Nothing\"\n"
        "  echo \"                    is added on top.\\\"\"\n"
        "  echo \"                    1. K — a customer holding a live quotation gets no price from the assistant, only the pointer\"\n"
        "  case \",$LK_AO_ON,\" in\n"
        "    *,tracking,*)\n"
        "  echo \"                    to it. It needs step 3, tracking, on, and LK-AO above reads it on:\" ;;\n"
        "    ,,)\n"
        "  echo \"                    to it. It needs step 3, tracking, on; LK-AO above could not read the ladder's keys, so read\"\n"
        "  echo \"                    where the ladder stands first, its own command, alone, and go on only if show lists tracking\"\n"
        "  echo \"                    among the steps on:\"\n"
        "  echo \"      cd $REPO && bash $QF_WRAPPER show\" ;;\n"
        "    *)\n"
        "  echo \"                    to it. It needs step 3, tracking, on, and LK-AO above does NOT read it on: turn tracking on\"\n"
        "  echo \"                    first, in the ladder's order, then this:\" ;;\n"
        "  esac\n"
        "  if owner_cmds; then\n"
        "  echo \"      $K_ON_CMD\"\n"
        "  echo \"                    It prints the line \\\"  quoted_no_prices_enabled = 1\\\" once it has saved; without that line take\"\n"
        "  echo \"                    nothing as saved. Then root docs/76 §K's three tests, from a non-staff number holding a live\"\n"
        "  echo \"                    quotation: a mixed on-topic message, an off-topic one with \\\"how much\\\", and on the accounts\"\n"
        "  echo \"                    number \\\"how much do I owe?\\\".\"\n"
        "  else\n"
        "  echo \"                    No command is printed: the owner of plugin.sqlite3 (DB_OWNER) or the data folder is not known — a settings write runs as that user, never as root; send the log file.\"\n"
        "  fi\n"
        "  echo \"                    2. Q — the connection quotation, built in code. First check that ai_hardware_expert is on (the\"\n"
        "  echo \"                    production values read it on, root docs/76 §N.1) and keep v9 of the settings text saved. Then\"\n"
        "  echo \"                    save v10 with its own command, which this script does not carry: it is handed over separately,\"\n"
        "  echo \"                    refuses unless the saved text is v9, and keeps the v9 text it replaces; the v9-restore comes in\"\n"
        "  echo \"                    a block of its own, only if needed, and asks you to type RESTORE. Then, the same form:\"\n"
        "  if owner_cmds; then\n"
        "  echo \"      $Q_ON_CMD\"\n"
        "  echo \"                    It prints the line \\\"  connection_quote_enabled = 1\\\" once it has saved. Then §K's tests: the\"\n"
        "  echo \"                    sales number, the support number with sales-on-all on, and the website chat — the code-built\"\n"
        "  echo \"                    block with its TOTAL WITH THE FIRST MONTH, and no figure in the model's own text before it.\"\n"
        "  else\n"
        "  echo \"                    No command is printed: the owner of plugin.sqlite3 (DB_OWNER) or the data folder is not known — a settings write runs as that user, never as root; send the log file.\"\n"
        "  fi\n"
        "  echo \"                    3. W.1 — one reply to a burst of messages, through the switch tool's wrapper, its own log in\"\n"
        "  echo \"                    /root/dnb-sales:\"\n"
        "  echo \"      $W1_ON_CMD\"\n"
        "  echo \"                    The test: two short messages a few seconds apart get one reply that answers both.\"\n"
        "  echo \"                    4. W.2–W.6: nothing to switch. The tests: an off-topic question gets the one-sentence\"\n"
        "  echo \"                    redirect; \\\"customer care\\\" gets the hand-over, then the office line; WhatsApp → AI Knowledge\"\n"
        "  echo \"                    Base opens the knowledge base; the uCRM catalogue screen lists any plan whose two prices differ.\"\n"
        "fi\n")
    doc.between("# 5.18.106 (root docs/75 §F, §L.8, DL3): after a PASSED --after-only — no FAIL and no NOT YET — the owner's own steps,",
                "# 5.18.98 — after a PASSED deploy or --after-only whose G3",
                OWNER + "# 5.18.98 — after a PASSED deploy or --after-only whose G3", keep_ends=False, why="H11: the owner's steps")

    # ── H12: the FAILED summary's lead (root docs/76 §T.3 item 7; §T.14, review of the derived script) ──
    # mg_after sets MG_SAY (na, mg or log, NA-CA1 first) and nothing printed it: the lead stands before the verdict chain, and
    # the FAILED line keeps its words where MG_SAY is empty, and where it is set says that §M calls for the rollback — not
    # an automatic one (TD5): §K's two commands, then the rollback command, each alone, at the end of the summary.
    LEAD = (
        '# 5.18.107 (root docs/76 §T.3 item 7): a failure of 092\'s after the copy leads the FAILED summary — NA-CA1 first (in\n'
        '# capitals: no AI reply), then MG-CA1, then MG-CA2 alone (MG_SAY, set by mg_after); facts only, nothing run.\n'
        'if [ "$FAIL" != "0" ] && [ -n "${MG_SAY:-}" ]; then\n'
        '  echo\n'
        '  case "$MG_SAY" in\n'
        '    na) echo "  NO UGANDA CUSTOMER GETS AN AI REPLY: THE ASSISTANT-OFF LIST (wa_no_ai, MIGRATION 092) CANNOT BE READ THE WAY THE"\n'
        '        echo "  WORKER READS IT (NA-CA1, OR MG-CA1\'S WAIT ABOVE: NO BOOT HAS APPLIED 092) — EACH ai.reply DIES AFTER ABOUT 36 MINUTES"\n'
        '        echo "  OF BACKOFF, WITH A STAFF ALERT AND NO HAND-OVER." ;;\n'
        '    mg) echo "  092 is not shown whole in the live migration ledger (MG-CA1 above says why: its checksum, its _schema_checks row,"\n'
        '        echo "  its objects, or no copy to read them on). Where they are not whole, the migration runner never re-runs a file it has"\n'
        '        echo "  recorded, and after a rollback MG-G1 refuses every redeploy until 092\'s ledger row is repaired by hand."\n'
        '        if [ "${NA_CA1_R:-}" = "ok" ]; then echo "  The assistant-off list reads the way the worker reads it (NA-CA1): AI replies go on."\n'
        '        else echo "  NA-CA1 was not judged (no copy to read on): whether AI replies go on is NOT ESTABLISHED."; fi ;;\n'
        '    log) echo "  migration.log holds a PARTIAL line for 092 (MG-CA2 above), but the ledger is whole — 092\'s checksum"\n'
        '         echo "  ${MIG107_LAST_MD5:0:16}…, its _schema_checks row ${MIG107_SC##*:} of ${MIG107_SC##*:}, its five objects (MG-CA1) — so 092 needs"\n'
        '         echo "  no repair and MG-G1 would accept a redeploy. The assistant-off list reads the way the worker reads it (NA-CA1): AI"\n'
        '         echo "  replies go on." ;;\n'
        '  esac\n'
        'fi\n')
    doc.insert_before('if [ "$FAIL" = "0" ] && [ "$NOT_YET" = "0" ]; then echo; echo "  $EXPECTED_VERSION ($MODE): PASSED.', LEAD,
                      why="H12: the FAILED summary's lead (MG_SAY)")
    doc.sub('else echo; echo "  $EXPECTED_VERSION ($MODE): $FAIL FAILED — send the log file (never the cash sheet); do not roll back on your own unless staff or customers are affected."; fi\n',
            'else echo; echo "  $EXPECTED_VERSION ($MODE): $FAIL FAILED$( if [ -n "${MG_SAY:-}" ]; then echo " — a failure after the copy means the rollback (root docs/76 §M), never an automatic one: root docs/76 §K\'s two commands first, then the rollback command, each alone, at the end of this summary. Send this LOG FILE back (never the cash sheet)."; else echo " — send the log file (never the cash sheet); do not roll back on your own unless staff or customers are affected."; fi)"; fi\n',
            why="H12: the FAILED line where MG_SAY is set")
    if doc.t.count("MG_SAY") != 5 + 4:
        raise SystemExit(f"partH: MG_SAY named {doc.t.count('MG_SAY')} times, not 9 (its declaration and mg_after's four, the lead's three, the verdict's one)")

    # ── the result ──
    t = doc.t
    for s in ("uj_judgeable", "uj_gate_a", "UJ_ON_GATE", "UJ_A", "UJ-CA", "UJ-ON", "root docs/75 §F", "--key followup_daily_cap", "raise followup_daily_cap",
              "--key followup_run_limit", "<CAP>", "<LIMIT>", "as_back_on", "FE-G1 refuses", "CS-G1 refuses", "CS-B1 again",
              "followup_auto_send '0'", "AS-RB1,", "rb_pre106", "RB106 once", "7835906's code", "every claim switch off"):
        if s in t:
            i = t.index(s); ln = t.count("\n", 0, i) + 1
            raise SystemExit(f"PART H: {s!r} is still in the script (line {ln}: {t[max(0, i - 120):i + 60]!r})")
    for s in ("deploy-5.18.107.sh — on Uganda, the quotation built in code", "5.18.107'S OWN CHECKS (root docs/76 §T.3–§T.7)",
              "5.18.106'S OWN CHECKS, RETIRED", "the owner's steps root docs/76 §K", 'echo "      $K_ON_CMD"', 'echo "      $Q_ON_CMD"',
              'echo "      $W1_ON_CMD"', "#   --after-only         the deploy already happened: run the checks again — MG-CA1"):
        doc.must(s, 1, why="partH's result")
    if len(re.findall(r"(?m)^\s*if owner_cmds; then$", t)) != 4:
        raise SystemExit("PART H: owner_cmds is not the condition of exactly four sites")
    # the header's own width: partH's paragraphs are wrapped at 120 (para); the carried ones keep their lines, so the count
    # of longer lines may not grow beyond the source's (150 in 5.18.106's header)
    src = ctx["src_text"]
    n_src = sum(1 for l in src[:src.index("\nset -uo pipefail\n")].split("\n") if len(l) > 120)
    n_now = sum(1 for l in t[:t.index("\nset -uo pipefail\n")].split("\n") if len(l) > 120)
    if n_now > n_src:
        raise SystemExit(f"PART H: {n_now} header lines are longer than 120 characters, more than the source's {n_src}")
