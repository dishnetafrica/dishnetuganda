# Part H — what the operator reads (root docs/75 §L.8): the header (the title and 5.18.106's purpose, its own checks,
# 5.18.105's carried, the operator's rules, the rollback, the options, what it never does and what it writes, the
# stages), the option parser's --clear-switches refusal (split so that its echo starts a line of its own: K5 then reads
# it as display text, §L.1), and the summary — the names, what changed and what did not, the screens, "check it" (three
# --after-only runs), the owner's steps, the three states' blocks, the rollback's paragraph. Its rules for the operator:
# 5.18.105 live and its --after-only PASSED, the quarantine's --check begun after 5.18.105's copy; the three claim switches
# off (CS-G1 refuses otherwise); not between 14:55 and 15:10 UTC, not while the morning list is sent (05:00–08:00 UTC,
# Monday to Saturday); no scripts/sales-followup.sh while the deploy, its --after-only or its rollback runs; the deploy
# and its rollback never in one copyable block; every printed later command alone on its line with its own log. After a
# PASSED --after-only (MODE=after, FAIL=0, NOT_YET=0) — and only then — the owner's own steps of root docs/75 §F, each
# its own command, printed and never run: followup_daily_cap (set_config as the book's owner, the form of partR's
# OWNER_AUTOSEND_CMD, a placeholder the owner replaces — set_config refuses a value holding <...>, saving nothing), then
# step 6 and its --check, later followup_run_limit; then partR's as_back_on (§L.8's last-but-one rule). The rollback
# (RB106): followup_auto_send '0' first when §L.7's five conditions hold (AS-RB1), every other switch kept, AS-RB2 after;
# the put-back by hand writes no setting and does not switch it off. Nothing here is a check; every line is printed text
# or a comment, but the §F block's and the ECO Bank block's guards. Every fact a new text states is read off Git first.
# 18 Nov (the review of rev106-findings, and DL11): check it says UJ-ON judges F3 only where stage A's reading lets it
# (partUJ's uj_judgeable, review L8-1); "what changed", "what did not" and §F's step 2 go by the mode and LK-AO's
# reading (LK_AO_ON, defined here: its global and its one assignment in lk_ao; review L8-2); "what it means" by what
# AS-RB2 read (partR's AS_RB2_EFF, review L8-3); the header, §F's comment and BR1's note say "a deploy after a
# rollback", not "--redeploy" (review L8-4); FE-G1 (DL11) in the header's checks, rules, options and stages, the
# summary's names, and partT's stage-A heading and its two comments — with the interim's order: step 6 (and 11, 12)
# off first, THEN followup_enabled on, never the reverse.
import re, textwrap, os
from d106lib import git, sha_at

V105, V106 = "5.18.105", "5.18.106"
# 18 Nov (review L8-2): LK_AO_ON — the global, and its one assignment, in lk_ao, right after a whole reading
LKAO_DEF = ("# 5.18.106 (review L8-2): LK_AO_ON — the steps on as LK-AO reads them in --after-only (\"-\" for none), set by lk_ao\n"
            "# alone; empty while LK-AO has not read the ladder's keys. The summary reads it: \"what changed\" and §F's step 2.\n"
            "LK_AO_ON=\"\"\n")
LK_OK_LINE = "  lk_ok \"$o\" || { note \"LK-AO the ladder's keys could not be read (${o%% map=*})\"; return; }\n"
LKAO_SET = "  LK_AO_ON=\"$(qf_kv \"$o\" on)\"   # 5.18.106 (review L8-2): the steps on now, for the summary (LK_AO_ON above)\n"
# 18 Nov (review L8-4): BR1's note — a deploy after a rollback is one, --redeploy typed or not
BR1_NOTE = ('  [ "$BR1_LEFT" = "0" ] || [ -n "$REDEPLOY" ] || note "BR1 $BR1_LEFT of $EXPECTED_VERSION\'s own files are on disk although '
            '--redeploy was not asked for: an earlier $EXPECTED_VERSION was deployed here and rolled back. This run is a deploy after '
            'that rollback all the same: the copy replaces those files, and its PASSED --after-only reads the latest rollback\'s '
            'record, offering your own command to turn followup_auto_send back on where that rollback set it off (root docs/75 '
            '§L.8) — --redeploy changes only the run\'s name, its log\'s and the deploy command a --check prints"')
CONT = "#            "


def para(text, first="#   ", cont=CONT, width=120):
    """A header paragraph, wrapped to 120 characters, no word broken."""
    out = textwrap.wrap(text, width=width, initial_indent=first, subsequent_indent=cont, break_long_words=False,
                        break_on_hyphens=False)
    long = [l for l in out if len(l) > width]
    if long:
        raise SystemExit(f"PART H: a header line is longer than {width} characters: {long[0][:80]!r}")
    return "\n".join(out)


def apply(doc, ctx):
    repo, plugin, base, pin = ctx["repo"], ctx["plugin"], ctx["base_full"], ctx["pin_full"]
    n_a, n_c, n_k, n_pin = ctx["n_added"], ctx["n_changed"], ctx["n_kept"], ctx["n_pin"]
    n_rt = len(ctx["runtime_changed"])
    kept, d106 = set(ctx["kept"]), set(ctx["added"]) | set(ctx["changed"])
    show = lambda c, p: git(repo, "show", f"{c}:{plugin}/{p}").decode()
    doc.mustnot("LK_AO_ON", why="a new name (review L8-2): partH defines it, below")
    # ── the facts the new texts state, from Git ──
    if sorted(ctx["runtime_changed"]) != ["cron/followup_close.php", "cron/followup_run.php", "cron/followup_scan.php",
                                          "cron/followup_send.php", "lib/FollowUpService.php"]:
        raise SystemExit("PART H: 5.18.106's runtime files are not the four follow-up jobs and lib/FollowUpService.php")
    if any(f.startswith("migrations/") for f in d106) or any(f.startswith("tabs/") for f in d106):
        raise SystemExit("PART H: 5.18.106 carries a migration or a screen")
    words = ctx["qc_rb1_words"]
    if sorted(words) != sorted(["U_unjam", "mutants_U", "r106_revert"]) or ctx["qc_rb1_control"] != "tests/fixtures/r106_revert.php":
        raise SystemExit(f"PART H: QC-RB1's words or control are not §L.2's ({words}, {ctx.get('qc_rb1_control')})")
    for f in ("webhook.php", "cron_quote_wa.php", "includes/post/post_kyc.php", "lib/QuoteOrganization.php",
              "lib/QuoteTaxLine.php", "lib/TenantProfile.php", "lib/PublicPriceFeed.php", "lib/UcrmQuoteLinks.php",
              "lib/UcrmQuoteLines.php", "lib/UcrmCatalogue.php", "tabs/sales/ucrm_catalogue.php", "lib/KycService.php",
              "includes/api/api_retailer.php", "retailer/index.html", "lib/SalesNumbersAdmin.php", "tools/set_config.php",
              "lib/PluginConfig.php", "lib/ConfigVault.php", "tools/sales_followup.php", "lib/SalesFollowupLadder.php",
              "tools/sales_followup_report.php", "lib/FollowUpPolicy.php"):
        if f not in kept or f in d106 or sha_at(repo, base, f"{plugin}/{f}") != sha_at(repo, pin, f"{plugin}/{f}"):
            raise SystemExit(f"PART H: {f} is not a kept file, the same at 7835906 and the pin")
    for f in ("lib/PluginConfig.php", "lib/ConfigVault.php"):   # the enquiry report's two pinned files: 994d660's still
        if sha_at(repo, ctx["base104"], f"{plugin}/{f}") != sha_at(repo, base, f"{plugin}/{f}"):
            raise SystemExit(f"PART H: {f} differs between 994d660 and 7835906 — the enquiry report would not run")
    sc = show(pin, "tools/set_config.php")
    for s in ("    'followup_daily_cap' => ['number',", "    'followup_run_limit' => ['number',",
              "if (!$clear && preg_match('/<[A-Z][A-Z0-9 _-]{1,30}>/', $new, $ph)) {",
              "else                 echo \"\\n  \" . $key . \" = \" . $new . \"\\n\";\n$show();\n",
              "    echo \"\\n  AI SETTINGS\\n\\n\";"):
        if sc.count(s) != 1:
            raise SystemExit(f"PART H: set_config at the pin does not hold {s[:70]!r} once")
    for ph in ("<CAP>", "<LIMIT>"):   # the placeholders the owner replaces: set_config refuses each, saving nothing
        if not re.search(r"<[A-Z][A-Z0-9 _-]{1,30}>", ph):
            raise SystemExit(f"PART H: {ph} is not a placeholder set_config refuses")
    fp = show(pin, "lib/FollowUpPolicy.php")
    for s in ("    public const HOUR_OPEN  = 8;", "    public const HOUR_CLOSE = 20;", "        if ($dow === 0) {"):
        if fp.count(s) != 1:
            raise SystemExit(f"PART H: FollowUpPolicy at the pin does not hold the send window {s.strip()!r}")
    ew = git(ctx["dev"], "show", "HEAD:scripts/enquiry-followup-report.sh").decode()
    if "bash scripts/enquiry-followup-report.sh 2>&1 | ( umask 077; exec tee /root/dnb-report/enquiry-$(date -u +%Y%m%dT%H%M%SZ).log )" not in ew:
        raise SystemExit("PART H: scripts/enquiry-followup-report.sh's own command is not as the summary prints it")
    if git(repo, "cat-file", "-t", f"{base}:{plugin}/tests/test_log_mask.php").decode().strip() != "blob":
        raise SystemExit("PART H: 7835906 has no tests/test_log_mask.php")

    # ── the title and 5.18.106's paragraph ──
    doc.lines_between("# deploy-5.18.105.sh — on Uganda, no phone number in any log line:",
                      "#            (the added ones are its tests).",
        para("deploy-5.18.106.sh — on Uganda, the enquiry follow-up sender unjammed: a draft Evolution keeps refusing is "
             "closed, a stale draft is never sent, stale follow-ups are expired and no longer take the run's places, one "
             "follow-up per quiet spell and one approved draft per follow-up — plugin 5.18.106, Uganda; root docs/75, 15 Nov "
             "— over 5.18.105 (live), every switch of the sales follow-up kept exactly as found; or roll it back to "
             "5.18.105, followup_auto_send set off first and every other switch kept.", first="# ", cont="# ")
        + "\n#\n"
        + para("5.18.106 The enquiry follow-up sender, unjammed (Uganda; root docs/75 §A–§D): the owner's \"yes build it\" "
               "of 15 Nov, after the read-only report found the sender's ten places held by drafts Evolution refuses. Five "
               f"changes, F1–F5, in {n_rt} runtime files — the four follow-up jobs (cron/followup_close.php, "
               "cron/followup_run.php, cron/followup_scan.php, cron/followup_send.php) and lib/FollowUpService.php — each "
               "on Uganda only (StaffJobsGate::applies()): F1 a draft Evolution refused three times (HTTP 400 to sendText) "
               "while its number delivered other messages is closed send_failed, not tried for ever; F2 a draft whose "
               "customer last wrote longer ago than followup_max_age_hours is closed expired, never sent; F3 the close job "
               "expires up to 200 stale enquiry follow-ups a pass, and the run's due() leaves them out; F4 the scan opens "
               "one follow-up per quiet spell (a second only after a single send_failed); F5 the run judges no follow-up "
               "already holding an approved draft, and the sender sends one draft per follow-up and expires the rest. "
               "Closes are permanent: nothing reopens one. NO MIGRATION, no new setting, no new screen; South Sudan byte for "
               "byte unchanged. The sales follow-up's ladder is 5.18.104's, byte for byte, and THIS SCRIPT KEEPS EVERY ONE OF "
               "ITS KEYS AS FOUND: it never runs the switch tool's on, off, skip, set or erase, nor "
               "scripts/sales-followup.sh, before, during or after the copy, or in the rollback. ITS ONE WRITE is the "
               "rollback's: followup_auto_send '0', through the plugin's own writer, before the put-back, when it reads on "
               "and root docs/75 §L.7's five conditions hold (AS-RB1) — 5.18.105's run, put back, drafts a follow-up whose "
               "draft is waiting, and its sender sends both. Until 5.18.106 serves and its --after-only has PASSED, none of "
               "the three claim switches may come on — steps 6 (chase), 11 (offers-test) and 12 (offers-live) — and CS-G1 "
               f"refuses while one is present. Files, against 7835906: {n_c} changed, {n_a} added — {n_pin} (the added ones "
               "are its tests)."), why="title")

    # ── the cash sheet: QC-RB2's record; uCRM read with GETs: the one key this script writes ──
    doc.sub("only, nothing for the rollback to quote by hand — 5.18.103, put back, handles each exactly as it did before\n"
            "#            5.18.104, docs/72 G-10, §G.8).",
            "only, nothing for the rollback to quote by hand — 5.18.105, put back, handles each exactly as 5.18.106\n"
            "#            does, docs/72 G-10, §G.8).", why="cash sheet: QC-RB2")
    doc.sub("#            uCRM — no quote, no client, no product, no attribute. No key is ever set: ucrm_quote_organization_id and\n"
            "#            wa_inbox_roles included.\n",
            "#            uCRM — no quote, no client, no product, no attribute. No key is ever set by these checks —\n"
            "#            ucrm_quote_organization_id and wa_inbox_roles included; the one key this script writes is a rollback's\n"
            "#            followup_auto_send '0' (AS-RB1, root docs/75 §L.7).\n", why="uCRM GETs: the one write")

    # ── 5.18.106's own checks; 5.18.105's, carried ──
    doc.lines_between("#   5.18.105'S OWN CHECKS (root docs/74 §H.4, §H.5): THE LADDER KEPT AS FOUND (LK).",
                      "#            the rows as before), the open quote follow-ups and running offers counted, LM-RB1.",
        para("5.18.106'S OWN CHECKS (root docs/75 §L.3–§L.7). THE CLAIM SWITCHES (CS): CS-G1, stage A (and so --check), "
             "NO-GO if quote_chase_enabled (step 6), offers_test_enabled (step 11) or offers_live_enabled (step 12) is "
             "present, with any value, in <data>/kyc_config.json or its store row — read on LK-G1's own map, key names and "
             "places only, never a value — with one off command per present switch, each alone on its line, highest step "
             "first (in a config.json LK-G1's held rule refuses first); CS-B1 the same after DEPLOY is typed and before the "
             "copy. THE READINGS BEFORE THE DEPLOY (U1–U5: notes — FE-G1, below, is their one refusal; on QF's copy of the book through the pin's "
             "code — counts, UTC times and generic channel labels, never a phone, a message, an instance name or an event's "
             "detail): U1 F1's positive control, the last outbound delivery per channel, and the sender's ten places as the "
             "sender would meet them; U2 the counts root docs/75 §A measured; U3 the forecasts — expireStale's count and its "
             "first pass, F5's follow-ups; U4 the settings as the jobs read them, and whether F1–F5 apply here; U5 whether a "
             "rollback could write followup_auto_send (the one reader, on the live settings file and store row, read-only). "
             "FE-G1, stage A (and so --check; 18 Nov, DL11), right after CS-G1, is the readings' one refusal: NO-GO, "
             "nothing changed, when U4 reads followup_enabled off as the jobs read it — UJ-ON reads stage A's value for the "
             "life of the deploy, so F3 could never be judged and the claim steps 6, 11 and 12 would follow unproven — with "
             "your own command to turn it on, printed alone on its line, never run; when U4 cannot read it, no refusal. "
             "A deploy keeps U1–U4 in the state file (UJ_A) for --after-only to compare; --check prints them and writes "
             "nothing. AFTER THE COPY: ME-CA1, master's errors of the four follow-up jobs in <plugin>/data/plugin.log after "
             "the cycle the copy straddled — counts only; one after that cycle a FAIL, one inside it a note; NOT ESTABLISHED "
             "without its positive control, a note in capitals that never holds back PASSED; UJ-CA, U1–U3 again beside stage "
             "A's (notes); in --after-only, UJ-ON, F3 judged once its gate holds — followup_enabled on, StaffJobsGate reading Uganda, the age limit a positive number and the same, at stage A and now; otherwise a note and no verdict, and a gate that failed only at stage A leaves F3 unjudged for that deploy: PASS when an enquiry follow-up closed expired by the close job "
             "since the copy, or when nothing is stale for F3 to close; FAIL when followup_close has run since the copy, "
             "fewer than 200 closed cancelled, and a stale row is still there; NOT YET otherwise — a summary state of its "
             "own, exit status 3 (0 PASSED, 1 FAILED), which withholds PASSED and the owner's steps of root docs/75 §F and "
             "says when to run --after-only again. THE ROLLBACK (RB106): before ROLLBACK is typed, rb_pre106 — LK-RB0 and "
             "AS-RB0: followup_auto_send per place and as the jobs read it, the five conditions, F5's count, and in capitals "
             "a claim switch present (claim mode under 5.18.105) or a write the rollback cannot make, each with your own "
             "command where its cause allows one; after ROLLBACK is typed and before the put-back, AS-RB1, the one write — "
             "followup_auto_send '0', as the book's owner through the installed code, only when it reads on and the five "
             "conditions hold, read back, never a reason to stop the rollback; after the put-back, RB106 — master, LK-RB1, "
             "LM-RB1 (5.18.105's masked lines still in place) and AS-RB2 (followup_auto_send off through 7835906's code).")
        + "\n#\n"
        + para("5.18.105'S CHECKS, CARRIED (root docs/74 §H.4, §H.5; root docs/75 §L.3, §L.6): THE LADDER KEPT AS FOUND "
               "(LK). LK-G1, stage A: the ladder's keys — SalesFollowupLadder::allKeys() and settingKeys() — read read-only "
               "in four places (<data>/kyc_config.json, its store row, <plugin>/data/config.json, <data>/config.json): NO-GO "
               "only where a place cannot be read, or an allKeys() key is held by a config.json (the tool's off would read "
               "back FAIL key_outside_overrides); never for a key that is on — a note gives the steps on and skipped, the "
               "ledger, the counts per place, every key where file and store disagree (its two notes on offers_live_enabled "
               "and quote_chase_enabled went in 5.18.106: CS-G1 refuses either). LK-B1, after DEPLOY and before the copy: "
               "the same reading, equal key by key and place by place, or NO-GO and nothing is copied (\"the switch tool was "
               "run meanwhile\"). LK-CA1 after the copy: equal to LK-B1's (FAIL otherwise); LK-CA2: no table's rows fell "
               "(quote_followup_state over its rows not list_last:%), _schema_checks unchanged, the four jobs' schedule "
               "entries kept; LK-ON: each step on, the installed tool's --check against the pin's at stage A (PASS then FAIL "
               "now is a FAIL); LK-AO in --after-only, against the deploy's map, a note. Each key's value is a 16-hex HMAC "
               "under a key kept in /root/dnb-5.18.106/lk-key-5.18.106 (mode 0600) — the map on the sheet and in the state "
               "file, never a value in the log. THE MASKED LINES (LM), 5.18.105's, still holding: LM-G1, stage A: in the "
               "pin's files from Git, in 7835906's and as installed, each of 5.18.105's blocks (one per hunk, from Git, "
               "markers aside; counted as blocks of lines by sha256 — this script carries no source text) once and its old "
               "block nowhere, the control fixed on 994d660 (5.18.104's release commit, before the lines were masked); "
               "LM-G2: the on-chase precondition's two strings once each in the pin's evo_webhook.php; LM-CA1 the same on "
               "the installed files; LM-CA2 the PHP log's old-form opt-out lines 60 s or more after the copy (0, else FAIL) "
               "and the sinks' records with a run of 9 or more digits since then (a note); LM-ON in --after-only; LM-RB1 "
               "after a rollback to 7835906: the masked form still there."), why="5.18.106's and 5.18.105's paragraphs")
    doc.sub("(\"they continue under 994d660\n#            as their switches say\").",
            "(\"they continue under 7835906\n#            as their switches say\").", why="5.18.104's carried: RB notes")

    # ── QC-RB1, the catalogue's rollback, QL, QS, TN, Q, 5.18.98, ECO Bank, the sandbox tests, P0 ──
    doc.lines_between("#            id 0 in the cart. After a rollback: QC-RB1, re-pointed to 5.18.105 (root docs/74 §H.5.4): nothing in 5.18.104",
                      "#            hand only where its own card says so).",
        para("id 0 in the cart. After a rollback: QC-RB1, re-pointed to 5.18.106 (root docs/75 §L.2): no file 7835906 "
             f"tracks names a word 5.18.106 adds — {', '.join(words[:-1])} and {words[-1]}, its tests' own names, read from "
             "Git — every file 7835906 tracks swept, data/ aside, beside its control on the pin "
             f"({ctx['qc_rb1_control']}); 7835906's tests/test_quote_followup.php loads U_unjam.php and mutants_U.php by "
             "glob — tests only, which no runtime file and no server-run test reaches; and the table ucrm_catalogue counted, "
             "5.18.103's own; QC-RB2 the web-direct applications with no quotation, counted, their ids on the sheet (a note: "
             "the rollback changes none of them — 5.18.105, put back, handles each exactly as 5.18.106 does, with the same "
             "KycService and UcrmQuoteLines: retried by its uCRM ids, Make the quotation where its Orders card offers it; an "
             "admin quotes one in uCRM by hand only where its own card says so).", first=CONT), why="QC-RB1 paragraph")
    doc.sub("#   5.18.102'S CHECKS, CARRIED (QL, \"quote lines\"), against 5.18.104's code (its UcrmQuoteLines and UcrmQuoteLinks\n"
            "#            5.18.103's byte for byte): with the switch off 5.18.104 quotes, as 5.18.103 does, through",
            "#   5.18.102'S CHECKS, CARRIED (QL, \"quote lines\"), against 5.18.105's code (its UcrmQuoteLines and UcrmQuoteLinks\n"
            "#            5.18.103's byte for byte): with the switch off 5.18.105 quotes, as 5.18.103 does, through", why="QL")
    doc.lines_between("#   A ROLLBACK TO 5.18.104 LEAVES THE uCRM CATALOGUE AS IT IS;",
                      "#            by-hand put-back, which counts nothing and runs no switch tool.",
        para("A ROLLBACK TO 5.18.105 LEAVES THE uCRM CATALOGUE AS IT IS; IT COUNTS THE WEB-DIRECT APPLICATIONS FOR THE "
             "RECORD (docs/72 §G.8, G-10). 5.18.106 keeps every file of the catalogue byte for byte — lib/UcrmCatalogue.php "
             "and its screen, UcrmQuoteLines, QuoteOrganization, KycService — so the put-back copies the same files; its "
             "settings and its switch stay in the store as an admin left them, read by 5.18.105 as before. A web-direct "
             "application is handled by 5.18.105's own rules — KycService retries it by its uCRM ids, its Orders card offers "
             "Make the quotation where that applies — exactly as by 5.18.106's, so the rollback leaves none to quote in uCRM "
             "by hand. --rollback counts those with no quotation in stage A and again after the rollback, on a copy of the "
             "book, read-only (QC-RB2: counts in the log, their ids on the sheet) — a note, never a refusal; so does the "
             "deploy's own automatic rollback (V1), after it. When V1's rollback does not verify, its stop says the rollback "
             "did not complete — 5.18.106 may still serve, or some installed files may already be 5.18.105's — and prints "
             "5.18.105's by-hand put-back, which counts nothing, runs no switch tool and writes no setting: it does not "
             "switch followup_auto_send off (V1's AS-RB1 says whether it did, and the stop carries your own command where it "
             "did not)."), why="the catalogue's rollback paragraph")
    doc.sub("#            lines in whQuotationEmail (root docs/73 §H.5.8.2) and 5.18.105 changes only its log lines (LM), none of them\n"
            "#            the sign-off line, its old phrase or its gate, so every reading is 5.18.101's\n",
            "#            lines in whQuotationEmail (root docs/73 §H.5.8.2), 5.18.105 changes only its log lines (LM) and 5.18.106\n"
            "#            does not change it — none of them the sign-off line, its old phrase or its gate, so every reading is 5.18.101's\n",
            why="QS: webhook.php")
    doc.lines_between("#            (TenantProfile::current()->id()). By the sha256 pinned here, Q-CA1 reads every file 5.18.105 changes or adds",
                      "#            nothing in 5.18.104 reaches them.",
        para("(TenantProfile::current()->id()). By the sha256 pinned here, Q-CA1 reads every file 5.18.106 changes or adds "
             f"(its {n_pin}, as the pin has them); Q-G1 before the copy, and RB after a rollback, read every file it changes "
             f"({n_c}), as 5.18.105 has them; all three read the {n_k} files of 5.18.105 it must not touch — "
             "lib/QuoteOrganization.php, lib/QuoteTaxLine.php, lib/TenantProfile.php, lib/PublicPriceFeed.php, "
             "lib/UcrmQuoteLinks.php, lib/UcrmQuoteLines.php, lib/UcrmCatalogue.php with its screen, webhook.php, "
             "cron_quote_wa.php, post_kyc.php and the sales follow-up's ladder among them (5.18.106 changes only the four "
             f"follow-up jobs, lib/FollowUpService.php and its tests). The {n_a} files it adds (its tests) have no 5.18.105 "
             "form: BR1 counts any left on disk, and QC-RB1 shows that no file 7835906 tracks names them.", first=CONT),
        why="QS: the sha256 paragraph")
    doc.subs([
        ("#   5.18.100'S CHECKS, CARRIED, AGAINST 5.18.103'S CODE (TN, \"technician number\").",
         "#   5.18.100'S CHECKS, CARRIED, AGAINST 5.18.105'S CODE (TN, \"technician number\")."),
        ("5.18.100's rule, which 5.18.101 through 5.18.103 do not change:", "5.18.100's rule, which 5.18.101 through 5.18.106 do not change:"),
        ("#            run. After a rollback (RB): the owner rule 5.18.100's, as 5.18.104 installs it, as text and on a copy, and the\n"
         "#            numbers a technician owns counted — they keep their owner and work under 5.18.104 as before.",
         "#            run. After a rollback (RB): the owner rule 5.18.100's, as 5.18.105 installs it, as text and on a copy, and the\n"
         "#            numbers a technician owns counted — they keep their owner and work under 5.18.105 as before."),
        ("#   5.18.99'S CHECKS, CARRIED, AGAINST 5.18.104'S CODE.", "#   5.18.99'S CHECKS, CARRIED, AGAINST 5.18.105'S CODE."),
        ("#            live plugin is 5.18.104 — the version, every file 5.18.105 changes (46) by 994d660's sha256 (pinned here),\n"
         "#            and the 5.18.104 files 5.18.105 must not touch (386) by theirs,",
         f"#            live plugin is 5.18.105 — the version, every file 5.18.106 changes ({n_c}) by 7835906's sha256 (pinned here),\n"
         f"#            and the 5.18.105 files 5.18.106 must not touch ({n_k}) by theirs,"),
        ("Q-CA1 the installed files are release/5.18.105's by the sha256\n#            pinned here, the 5.18.104 files it must not touch 5.18.104's,",
         "Q-CA1 the installed files are release/5.18.106's by the sha256\n#            pinned here, the 5.18.105 files it must not touch 5.18.105's,"),
        ("#   5.18.98'S CHECKS, CARRIED, AGAINST 5.18.103'S CODE. Its copy checks C1–C5, on ONE VACUUM INTO copy, 5.18.103's code and\n"
         "#            5.18.104's from Git (A23), then 5.18.103's from Git and the installed (CA2):",
         "#   5.18.98'S CHECKS, CARRIED, AGAINST 5.18.105'S CODE. Its copy checks C1–C5, on ONE VACUUM INTO copy, 5.18.105's code and\n"
         "#            5.18.106's from Git (A23), then 5.18.105's from Git and the installed (CA2):"),
        ("#            5.18.104 (994d660), every file it tracks byte for byte;", "#            5.18.105 (7835906), every file it tracks byte for byte;"),
        ("BK1 cash in hand under the installed 5.18.104\n", "BK1 cash in hand under the installed 5.18.105\n"),
        ("books (A24, R26), 5.18.103's code against 5.18.104's, both with the gate;", "books (A24, R26), 5.18.105's code against 5.18.106's, both with the gate;"),
        ("#            R23a–R23g; after a rollback, RB). 5.18.104's own tests — tests/test_quote_followup.php,",
         "#            R23a–R23g; after a rollback, RB). 5.18.106's own tests (section U of tests/test_quote_followup.php,\n"
         "#            tests/quote_followup/U_unjam.php and mutants_U.php, and tests/fixtures/r106_revert.php) and 5.18.105's\n"
         "#            (tests/test_log_mask.php and its sections) run in the suites only; so do 5.18.104's — tests/test_quote_followup.php,"),
        ("#            own configuration, as 5.18.103's code and 5.18.104's both read it, or nothing is deployed (A23, G2).",
         "#            own configuration, as 5.18.105's code and 5.18.106's both read it, or nothing is deployed (A23, G2)."),
    ], why="the carried paragraphs' releases")

    # ── the pilot, the switches and the documents: one exception, the rollback's one write ──
    doc.lines_between("#   THE SALESPERSON PILOT, THE SWITCHES AND 5.18.94'S DOCUMENTS STAY EXACTLY AS THEY ARE",
                      "#            to its customer.",
        para("THE SALESPERSON PILOT, THE SWITCHES AND 5.18.94'S DOCUMENTS STAY EXACTLY AS THEY ARE — read as found, "
             "required unchanged by the run, never set (A5, A5b, A8–A11, A17, A18, A21, V3–V3j, R2–R22, RB), as in 5.18.102; "
             "bank_screen_enabled among them (V3j), which must also read ON; the channel registry's rows among them (R11). "
             "One exception, a rollback's: followup_auto_send '0', the one write of this script (AS-RB1, root docs/75 §L.7) "
             "— the deploy itself sets nothing. No switch tool runs, so V3g fails on any change of an ai_, evo_, openai_ or "
             "claude_ key, and the sales follow-up's keys are LK's — kept as found (LK-B1, LK-CA1, LK-RB1). Do not use the "
             "Salesperson numbers card, ECO Bank (keep no statement), Send Quotation, the KYC form, tools/set_config.php or "
             "Settings while this script runs, nor during its --after-only or its rollback: a change made then fails the "
             "checks that compare before and after. Do not change uCRM's Products, Service Plans or its client attributes "
             "while the deploy runs: what the forms offer after the copy must equal what they offered before it (QC-CA1). "
             "From the moment the deploy starts until it prints its last line, no admin touches the uCRM catalogue screen "
             "(the switch, the installation product, a product kept off, a standard quotation), makes, changes or removes a "
             "uCRM link, or saves a plan or a kit (the run's opening line says so). The uCRM catalogue needs no step: "
             "5.18.106 changes nothing of it, and its switch stays as an admin left it — under 5.18.103 its --after-only "
             "read QC-ON1–QC-ON3 once the switch was turned on (root docs/07); QC-N1, QC-CA1 and QC-ON1–QC-ON4 are carried. "
             "Make no KYC registration and no quote to try the release: the plugin WhatsApps every new quote to its "
             "customer."), why="pilot and switches")
    doc.lines_between("#   THE COMMIT IT INSTALLS IS A RELEASE COMMIT, NOT THE BRANCH TIP.",
                      "#            are checked byte for byte (A0).",
        para("THE COMMIT IT INSTALLS IS A RELEASE COMMIT, NOT THE BRANCH TIP. The pin below, on release/5.18.106, is cut on "
             f"7835906 (5.18.105, live): {n_pin} files — {n_c} changed, {n_a} added (its tests) — each file's sha256 pinned "
             "here. Stage A refuses a pin whose parent is not 5.18.105, whose short hash names another commit, whose delta "
             "is not exactly these files by these hashes, which carries a migration (089–091 held at the pin by their "
             "sha256 and md5), and a checkout holding a file git does not track under the plugin folder outside data/. Its "
             "master.php and main.php are checked byte for byte (A0)."), why="commit paragraph")

    # ── the operator's rules (§L.8): what must be live, before the deploy, its window ──
    doc.lines_between("# 5.18.104 must be the live plugin:",
                      "# Kampala), and not while the morning list is being sent (step 4; inferred):",
        "# 5.18.105 must be the live plugin: this script refuses any other live commit or version — deploy 5.18.105 first.\n"
        "#\n"
        + para("Before the deploy (root docs/75 §L.8): 5.18.105 is live and its --after-only has PASSED (root docs/07), and "
               "the quarantine's --check begun after 5.18.105's copy has PASSED — A14 reads that record, and needs the "
               "quarantine's --check after any later copy or rollback. The three claim switches are off — "
               "quote_chase_enabled (step 6), offers_test_enabled (step 11) and offers_live_enabled (step 12) absent from "
               "<data>/kyc_config.json and its store row: CS-G1 refuses otherwise, and CS-B1 again after DEPLOY is typed. "
               "followup_enabled is on, as the jobs read it: FE-G1 refuses otherwise (NO-GO, nothing changed; DL11). Under "
               "the 18 Nov interim — followup_enabled 0, step 6 on — turn step 6 off first, and steps 11 and 12 if they are "
               "on, THEN followup_enabled on: each its own command, alone, never the reverse — followup_enabled on while a "
               "claim switch is on is root docs/75 §F's hazard under 5.18.105 (claim mode closes the refused drafts after "
               "three tries each, then sends the stale backlog). CS-G1 prints the off commands and FE-G1 the one for "
               "followup_enabled; this script runs neither. "
               "The ladder is otherwise kept exactly as found: the steps that are on keep running. Do not run "
               "scripts/sales-followup.sh while the deploy, its --after-only or its rollback runs — LK-B1, CS-B1, LK-CA1 and "
               "AS-RB1 would read the change. A --check first is wise: CS-G1, FE-G1, LK-G1, LM-G1 and LM-G2 refuse or pass, "
               "QF-N1–QF-N7 and U1–U5 note, nothing changed. Every command this script prints for later stands alone on its "
               "own line with its own log, and the deploy and its rollback are never in one copyable block (root docs/44 "
               "§16.9).", first="# ", cont="# ")
        + "\n#\n"
        + para("The deploy. Run as root on the server, then send back THE LOG FILE (never a copy of the terminal) — only "
               "after the quarantine's --check has PASSED after 5.18.105's deploy; not between 14:55 and 15:10 UTC (the "
               "evening WhatsApp, 18:00 Kampala), and not while the morning list is being sent (05:00–08:00 UTC, Monday to "
               "Saturday):", first="# ", cont="# "), why="before the deploy, and its window")
    doc.lines_between("# Then, at least 20 minutes after the copy (the summary prints the time), and once more the next day after 15:00 UTC —",
                      "# on); then the quarantine's own --check, its own (the summary prints it):",
        para("Then --after-only, read-only, nothing deployed — ME-CA1, UJ-CA and UJ-ON; LM-ON, LK-AO; QF-CA1–QF-CA8, "
             "VN-M1–VN-K2, OF-M1–OF-CA5, AC-CA1, AC-CA2; QF-ON0–QF-ON5, VA-ON1, VN-ON1–VN-ON4, OF-ON1, OF-ON2 and AC-ON1 for "
             "each step that is on — each run its own command, alone, with its own log: at least 20 minutes after the copy "
             "(the summary prints the time); again after a close pass has run since the copy (UJ-ON needs one: until then "
             "it reads NOT YET, exit status 3, and the summary says when to run it again); and the next day inside the "
             "sender's window (Monday to Saturday, 08:00–20:00 Kampala), after 15:00 UTC, when the evening WhatsApp has run. "
             "Then the quarantine's own --check, its own (the summary prints it):", first="# ", cont="# "),
        why="after-only")
    doc.lines_between("# Then the steps, yours alone, one at a time and only in the ladder's order, as under 5.18.104",
                      "# copy of the book (P1), its own command, its log in /root/dnb-report:",
        para("Then, after a PASSED --after-only and only then, the owner's own steps (root docs/75 §F), each its own "
             "command, never run by this script — that --after-only's summary prints them, one per line: raise "
             "followup_daily_cap, the most follow-ups sent on one number in a day; then step 6 (on chase), which also puts "
             "the enquiry sends into the send ledger's claim mode; later, consider raising followup_run_limit. A NOT YET or "
             "a FAILED --after-only prints none of them. Every other step of the ladder stays yours alone, one at a time and "
             "only in the ladder's order, as under 5.18.105 — this script turns none on or off. The report, read-only on a "
             "copy of the book (P1), its own command, its log in /root/dnb-report:", first="# ", cont="# "), why="steps")

    # ── the rollback ──
    doc.lines_between("# The rollback is a separate command, printed at the end of the deploy's log, after the verdict.",
                      "# 5.18.105 goes with --redeploy.",
        para("The rollback is a separate command, printed at the end of the deploy's log, after the verdict. It is never "
             "pasted together with the ones above: pasted together, the shell runs both (root docs/44 §16.9). Before "
             "ROLLBACK is typed it reads the ladder (LK-RB0) and followup_auto_send (AS-RB0: per place and as the jobs read "
             "it, root docs/75 §L.7's five conditions, F5's count) and says what it will write; it says in capitals when a "
             "claim switch is present (under 5.18.105 that means claim mode for the enquiry sends) and when it cannot write "
             "followup_auto_send itself, each with your own command, alone, never run by it and never printed with this "
             "command — answer anything but ROLLBACK, run it, then the rollback again; it is never blocked by the ladder. "
             "Once ROLLBACK is typed it writes one key before the put-back, followup_auto_send '0', through the plugin's own "
             "writer as the book's owner, only when it reads on and the five conditions hold (AS-RB1, read back; a write it "
             "could not make is a FAIL in capitals that never stops the rollback, your own command printed after the "
             "verdict) — so that 5.18.105's run and sender, put back, approve nothing by themselves. It puts 7835906 back — "
             "the documented deploy of 5.18.105 — and runs no switch tool and no by-hand put-back of rows or keys: every "
             "other switch is kept, every step that is on keeps running, every close 5.18.106 made stays (nothing reopens "
             "one), 089–091 and P7's collections stay. Then RB106 reads the put-back: master with the four jobs last, every "
             "ladder key and the tool's show as before (LK-RB1), no table's rows fell, 5.18.105's masked lines still in "
             "place (LM-RB1), followup_auto_send off through 7835906's code (AS-RB2). ECO Bank, every statement kept, its "
             "switch, the quoting organization, the uCRM catalogue, the channel registry and every number's owner stay "
             f"exactly as they are; the {n_a} files 5.18.106 adds (its tests) stay on disk, named by nothing 7835906 tracks "
             "(QC-RB1). followup_auto_send stays off after the rollback: a later deploy of 5.18.106 goes with --redeploy, "
             "only with every claim switch off (CS-G1) and followup_enabled on (FE-G1); the PASSED --after-only of a deploy "
             "after that rollback — --redeploy typed or not — finding followup_auto_send off where the latest rollback set "
             "it so, prints your own command to turn it back on — your choice. The put-back by hand "
             "(PUTBACK_CMD) writes no setting: it does not switch followup_auto_send off.", first="# ", cont="# "),
        why="rollback paragraph")

    # ── the options ──
    doc.lines_between("#   --after-only         the deploy already happened: run the checks again — LM-ON and LK-AO (the ladder against the",
                      "#                        deploy's, a note), QF-CA1–QF-CA8, VN-M1, VN-M2, VN-CA1–VN-CA4,",
        "#   --after-only         the deploy already happened: run the checks again — ME-CA1, UJ-CA and UJ-ON (F3 judged: PASS,\n"
        "#                        FAIL or NOT YET; NOT YET withholds PASSED and the owner's steps, exit status 3), LM-ON and\n"
        "#                        LK-AO (the ladder against the deploy's, a note), QF-CA1–QF-CA8, VN-M1, VN-M2, VN-CA1–VN-CA4,",
        why="--after-only")
    doc.sub("#                        sheet. Run it at least 20 minutes after the copy, once more the next day after 15:00 UTC, and\n"
            "#                        after each step of the ladder\n",
            "#                        sheet. After a PASSED run its summary prints the owner's steps (root docs/75 §F). Run it at\n"
            "#                        least 20 minutes after the copy, again after a close pass has run, the next day inside the\n"
            "#                        send window after 15:00 UTC, and after each step of the ladder\n", why="--after-only: when")
    doc.sub("#   --move-list-only     read-only, before the deploy, while 5.18.104 is live:",
            "#   --move-list-only     read-only, before the deploy, while 5.18.105 is live:", why="--move-list-only")
    doc.lines_between("#   --redeploy           deploy 5.18.105 again after its own rollback",
                      "#                        5.18.104's tables as it left them, and the ladder rule holds as on the first deploy (LK)",
        "#   --redeploy           deploy 5.18.106 again after its own rollback: the files it adds (its tests), which the rollback\n"
        "#                        leaves on disk, are expected there (BR1 counts them, and the copy replaces them); QF-G1 reads\n"
        "#                        5.18.104's tables as it left them, the ladder rule holds as on the first deploy (LK), and\n"
        "#                        CS-G1 refuses while a claim switch is on (root docs/75 §L.9, DL8), FE-G1 while\n"
        "#                        followup_enabled reads off (DL11). Without it the deploy is the same — BR1 notes the files —\n"
        "#                        and its PASSED --after-only reads that rollback's record all the same (root docs/75 §L.8)",
        why="--redeploy")
    doc.sub("owner and sign-off readings — and stop before the backup:",
            "owner and sign-off readings, CS-G1, U1–U5 and FE-G1 — and stop before the backup:", why="--check")
    doc.sub("operator's explicit choice (Q-G3) — 5.18.104 then quotes as 5.18.103 does with it off (no quoting",
            "operator's explicit choice (Q-G3) — 5.18.106 then quotes as 5.18.105 does with it off (no quoting", why="--quote-company-off")
    doc.lines_between("#   --clear-switches     REFUSED (root docs/74 §H.4.4)",
                      "#                        with scripts/sales-followup.sh off <step>, its own command",
        "#   --clear-switches     REFUSED (root docs/75 §L.7; refused since 5.18.105): 5.18.106 never clears a ladder key — its\n"
        "#                        one write is a rollback's followup_auto_send '0' (AS-RB1); turn a step off yourself with\n"
        "#                        scripts/sales-followup.sh off <step>, its own command", why="--clear-switches (header)")
    doc.lines_between("#   --rollback           typed ROLLBACK, after LK-RB0 and the step-12 notice",
                      "#                        code",
        "#   --rollback           typed ROLLBACK, after LK-RB0 and AS-RB0 (rb_pre106; root docs/75 §L.7): followup_auto_send '0'\n"
        "#                        first (AS-RB1 — the one write, as the book's owner, only when it reads on and §L.7's five\n"
        "#                        conditions hold; never a reason to stop), then 5.18.105 (7835906) put back by the documented\n"
        "#                        deploy, every other switch kept — no switch tool, no off-hook, no by-hand put-back of rows or\n"
        "#                        keys — then checked: RB106 (master, LK-RB1, the rows, the open quote follow-ups and running\n"
        "#                        offers counted, LM-RB1: 5.18.105's masked lines still in place, AS-RB2: followup_auto_send\n"
        "#                        off), 5.18.105's files by sha256, its sign-off line, owner rule and quoting code in place,\n"
        "#                        the files 5.18.106 adds left on disk and named by nothing 7835906 tracks (QC-RB1), the\n"
        "#                        Cashbook's list and ECO Bank exactly as they were, D2 = A on a copy of the book (its own cash\n"
        "#                        sheet), 5.18.95's security fixes, the documents, the database (the ledger the same, 091 last),\n"
        "#                        and the numbers a technician owns counted; its record goes to a file of its own. It refuses a\n"
        "#                        copy that stopped part-way, and prints the by-hand put-back of 5.18.105's code, which writes\n"
        "#                        no setting", why="--rollback")
    # the parser: the refusal's echo on a line of its own (K5, §L.1), reworded for 5.18.106
    doc.sub("    --clear-switches) echo \"--clear-switches: $EXPECTED_VERSION never clears a ladder key; turn a step off yourself with scripts/sales-followup.sh off <step>, its own command (root docs/74 §H.4.4). Nothing was run\" >&2; exit 64 ;;\n",
            "    --clear-switches)   # refused since 5.18.105; 5.18.106: its one write is a rollback's followup_auto_send '0' (root docs/75 §L.7)\n"
            "      echo \"--clear-switches: refused — $EXPECTED_VERSION never clears a ladder key; its one write is a rollback's followup_auto_send '0' (AS-RB1, root docs/75 §L.7). Turn a step off yourself with scripts/sales-followup.sh off <step>, its own command, alone. Nothing was run\" >&2; exit 64 ;;\n",
            why="--clear-switches (the parser)")

    # ── what it never does, what it writes ──
    doc.sub("# the quarantine's record or folder; open the live book read-write but for the VACUUM INTO copy the backup makes too; run\n",
            "# the quarantine's record or folder; open the live book read-write but for the VACUUM INTO copy the backup makes too\n"
            "# and a rollback's one write (AS-RB1, through the plugin's own writer); run\n", why="never does: read-write")
    doc.sub("render a page or a CSV with a login; switch, set,\n"
            "# clear or verify anything — bank_screen_enabled, ucrm_quote_organization_id, wa_inbox_roles and every key of the sales\n"
            "# follow-up's ladder included: it never runs the switch tool's on, off, skip, set or erase, nor scripts/sales-followup.sh,\n"
            "# in any mode (root docs/74 §H.4.3); add, give,",
            "render a page or a CSV with a login; switch, set,\n"
            "# clear or verify anything but a rollback's followup_auto_send '0' (AS-RB1, root docs/75 §L.7) — bank_screen_enabled,\n"
            "# ucrm_quote_organization_id, wa_inbox_roles and every key of the sales follow-up's ladder included: it never runs the\n"
            "# switch tool's on, off, skip, set or erase, nor scripts/sales-followup.sh, in any mode (root docs/74 §H.4.3); add, give,",
            why="never does: switches")
    doc.sub("# the state file, the LK key file (mode 0600), the cash sheets and the run's copies in the container's /tmp (removed), and\n"
            "# the time of the copy on the files this release changes or adds — never a key of the ladder.\n",
            "# the state file, the LK key file (mode 0600), the cash sheets and the run's copies in the container's /tmp (removed),\n"
            "# the time of the copy on the files this release changes or adds, and, in a rollback, followup_auto_send '0' in\n"
            "# <data>/kyc_config.json and its store row (AS-RB1, root docs/75 §L.7) — never a key of the ladder.\n", why="writes")

    # ── the stages ──
    doc.subs([
        ("#            commit and version, every file of it (BR1), every file 5.18.105 changes and the 5.18.104 files it keeps by",
         "#            commit and version, every file of it (BR1), every file 5.18.106 changes and the 5.18.105 files it keeps by"),
        ("#            and AC-N2; 5.18.105's LM-G1 and LM-G2; the backup, the event queue's\n",
         "#            and AC-N2; 5.18.105's LM-G1 and LM-G2; 5.18.106's CS-G1 (a refusal), U1–U5 (notes; a deploy keeps\n"
         "#            U1–U4 in the state file, UJ_A) and FE-G1 (a refusal: U4 read followup_enabled off); the backup,\n"
         "#            the event queue's\n"),
        ("#          B LK-B1 and the baselines, then the documented deploy (or, with --rollback, LK-RB0 and, once ROLLBACK is\n"
         "#            typed, the documented deploy of 5.18.104 — no switch tool); the copy's time on each file",
         "#          B CS-B1, LK-B1 and the baselines, then the documented deploy (or, with --rollback, rb_pre106 — LK-RB0 and\n"
         "#            AS-RB0 — and, once ROLLBACK is typed, AS-RB1's one write, followup_auto_send '0', then the documented\n"
         "#            deploy of 5.18.105 — no switch tool); the copy's time on each file"),
        ("#          V as 5.18.104 (V1's automatic rollback runs no switch tool, and RB106 once verified): the public pages and the",
         "#          V as 5.18.105 (V1's automatic rollback sets followup_auto_send off first, AS-RB1, runs no switch tool, and\n"
         "#            RB106 once verified): the public pages and the"),
        ("#          R 5.18.105 installed: LK-CA1, LK-CA2, LK-ON and LM-CA1, LM-CA2 (LM-ON, LK-AO in --after-only); R1 the files\n"
         "#            byte for byte and the version; Q-CA1 the release by its pinned sha256, 5.18.104's kept files by theirs,",
         "#          R 5.18.106 installed: ME-CA1 and UJ-CA (UJ-ON in --after-only); LK-CA1, LK-CA2, LK-ON and LM-CA1, LM-CA2\n"
         "#            (LM-ON, LK-AO in --after-only); R1 the files byte for byte and the version; Q-CA1 the release by its\n"
         "#            pinned sha256, 5.18.105's kept files by theirs,"),
        ("#            it, 091 last — 5.18.105 adds no migration — and its size; R13 the event processor at 5.18.104's Uganda\n"
         "#            signature, which 5.18.105 keeps, ai.media among its workers' events;",
         "#            it, 091 last — 5.18.106 adds no migration — and its size; R13 the event processor at 5.18.104's Uganda\n"
         "#            signature, which 5.18.105 and 5.18.106 keep, ai.media among its workers' events;"),
    ], why="stages")
    doc.lines_between("#          RB after a rollback (LK-RB0 before it; RB106 after it — master, LK-RB1, the rows, LM-RB1): 5.18.104's files by",
                      "#            (a note), the web-direct applications with no quotation counted again, ids on the sheet (QC-RB2, a record —",
        "#          RB after a rollback (LK-RB0 and AS-RB0 before it, AS-RB1 between; RB106 after it — master, LK-RB1, the rows,\n"
        f"#            LM-RB1, AS-RB2): 5.18.105's files by sha256 where 5.18.106 changed them (Q-G1's set of {n_c}), every ladder\n"
        f"#            key as before, followup_auto_send off, the {n_a} files 5.18.106 adds on disk, named by nothing 7835906\n"
        "#            tracks (QC-RB1, re-pointed), the uCRM catalogue's table counted, 5.18.103's own screen's and read by 5.18.105\n"
        "#            again (a note), the web-direct applications with no quotation counted again, ids on the sheet (QC-RB2, a record —",
        why="stage RB")
    doc.lines_between("#          F summary: which of the checks each name is (5.18.105's own first",
                      "#            --after-only has passed; the rollback last, after the verdict, its own block",
        "#          F summary: which of the checks each name is (5.18.106's own first — CS-G1, CS-B1, U1–U5, FE-G1, UJ-CA, UJ-ON,\n"
        "#            ME-CA1, AS-RB0, AS-RB1, AS-RB2, RB106; then 5.18.105's, carried — LK-G1, LK-B1, LK-CA1, LK-CA2, LK-ON,\n"
        "#            LK-AO, LK-RB0, LK-RB1, LM-G1, LM-G2, LM-CA1, LM-CA2, LM-ON, LM-RB1; then 5.18.104's, QF-G1–QF-G3,\n"
        "#            QF-N1–QF-N7, QF-CA1–QF-CA8, QF-ON0–QF-ON5; VN and VA, Part V's; OF, Part O's; AC, Part P's — then\n"
        "#            5.18.103's, carried,\n"
        "#            QC-G1, QC-N1–QC-N7, QC-CA1–QC-CA3, QC-ON1–QC-ON4, QC-RB1, QC-RB2; 5.18.102's, QL-G1, QL-N1–QL-N7, QL-CA2,\n"
        "#            QL-CA3; QS-G1, QS-CA1; TN-G1–TN-G3, TN-N1, TN-N2, TN-CA1–TN-CA3; Q-G1–Q-G6, Q-N1–Q-N7, Q-CA1–Q-CA6); the cash\n"
        "#            sheet's path; three states, FAIL first — PASSED, NOT YET (UJ-ON's, --after-only only, exit status 3) and\n"
        "#            FAILED; --after-only (20 minutes after the copy, after a close pass, the next day inside the send window) and\n"
        "#            the quarantine's --check, each its own command with its own log; the screens (none changes but the Follow-ups\n"
        "#            screen's closes; every switch kept by the deploy) and the operator's steps — where the ladder stands; after a\n"
        "#            PASSED --after-only alone, the owner's steps of root docs/75 §F (followup_daily_cap, then step 6 and its\n"
        "#            --check, later followup_run_limit), each its own command, and, after a deploy that followed a rollback\n"
        "#            which set followup_auto_send off, your own command to turn it back on; the rollback last, after the\n"
        "#            verdict, its own block, and the put-back by hand, which does not switch followup_auto_send off", why="stage F")

    # ════ the summary ════
    doc.lines_between('echo "  names             LK-G1, LK-B1, LK-CA1, LK-CA2, LK-ON, LK-AO, LK-RB0, LK-RB1, LM-G1, LM-G2, LM-CA1, LM-CA2, LM-ON,"',
                      'echo "                    QF-CA1–QF-CA8 and QF-ON0–QF-ON5 are 5.18.104\'s (QF, Part F\'s; root docs/73 §H.2.15), carried and"',
        'echo "  names             CS-G1, CS-B1, U1–U5, FE-G1, UJ-CA, UJ-ON, ME-CA1, AS-RB0, AS-RB1, AS-RB2 and RB106 are"\n'
        'echo "                    $EXPECTED_VERSION\'s own (root docs/75 §L.3–§L.7, DL11); LK-G1, LK-B1, LK-CA1, LK-CA2, LK-ON,"\n'
        'echo "                    LK-AO, LK-RB0, LK-RB1, LM-G1, LM-G2, LM-CA1, LM-CA2, LM-ON and LM-RB1 are 5.18.105\'s (root"\n'
        'echo "                    docs/74 §H.4, §H.5), carried — LM-RB1 inverted, LK-G1\'s two notes gone (root docs/75 §L.3,"\n'
        'echo "                    §L.6); QF-G1–QF-G3, QF-N1–QF-N7,"\n'
        'echo "                    QF-CA1–QF-CA8 and QF-ON0–QF-ON5 are 5.18.104\'s (QF, Part F\'s; root docs/73 §H.2.15), carried and"',
        why="summary names")
    doc.line('            echo "  what it means     on Uganda the uCRM catalogue is 5.18.103\'s own and the rollback leaves it exactly as it was',
             '            echo "  what it means     the code is $BASELINE_VERSION\'s again where $EXPECTED_VERSION changed it — the four follow-up jobs and lib/FollowUpService.php (RB); followup_auto_send ${AS_RB1_SAY:-not written by this run} (AS-RB1; AS-RB2 above reads it under $BASELINE_VERSION) $( case "${AS_RB2_EFF:-}" in off) echo "— it reads off now, so a person approves each draft, and it stays off until you turn it on" ;; on) echo "— IT READS ON NOW (AS-RB2): UNDER $BASELINE_VERSION IT APPROVES AND SENDS AUTOMATICALLY WHILE IT DOES — $( if [ -n "$RB_NOTHING" ]; then echo "your own command, as the one reader allows it, is printed above (AS-RB2)"; elif [ "${AS_RB1:-}" = "written" ] || [ "${AS_RB1:-}" = "off" ]; then echo "AS-RB2\'s FAIL above says so"; else echo "your own command, where its cause allows one, is printed after the verdict"; fi)" ;; *) echo "— whether it reads off now is not shown (AS-RB2 above)" ;; esac ); every other switch of the sales follow-up is as it was (LK-RB1): the steps that were on keep running; 5.18.105\'s masked lines stay masked (LM-RB1); every close $EXPECTED_VERSION made stays (nothing reopens one), and so do the drafts it set expired. On Uganda the uCRM catalogue is 5.18.103\'s own and the rollback leaves it exactly as it was — its screen, its settings and its switch as an admin left them, the KYC form, Send Quotation and the lead quotes offering what they offered (RB); the web-direct applications stay on 5.18.103\'s direct rules, as under $EXPECTED_VERSION — ${QCRB_SAY:-not counted} (QC-RB2$( [ -n "$QC_RB" ] && echo "; $QC_RB_IDS")): retried by their uCRM ids, Make the quotation on an Orders card where it applies; the rollback adds none to quote by hand, and nothing is quoted at price 0. The files $EXPECTED_VERSION adds stay on disk, named by nothing $BASELINE_COMMIT tracks (QC-RB1). The Cashbook, ECO Bank, every number and the quoting organization stay as they are (RB)" ;;',
             why="summary: what it means")
    doc.line('  echo "  what changed      on this Uganda server: $EXPECTED_VERSION\'s log lines',
             '  echo "  what changed      on this Uganda server: $EXPECTED_VERSION\'s enquiry follow-up sender — ' + str(n_rt)
             + ' runtime files, the four follow-up jobs and lib/FollowUpService.php, F1–F5 (root docs/75 §C): a draft Evolution keeps refusing while its number delivers is closed send_failed (F1), a stale draft is closed expired and never sent (F2), the close job expires the stale follow-ups and the run\'s due() leaves them out (F3), one follow-up per quiet spell (F4), one approved draft per follow-up (F5); no message text, screen, setting or migration changes, and the closes it makes stay. The sales follow-up\'s ladder is $BASELINE_VERSION\'s$( if [ "$MODE" = "after" ]; then echo "; its keys as LK-AO above reads them against the deploy\'s, the claim switches among them — under $EXPECTED_VERSION steps 6, 11 and 12 may be on once its --after-only has PASSED (root docs/75 §L.3, §F): the steps on now ($( [ -n "$LK_AO_ON" ] && printf \'%s\' "$LK_AO_ON" | tr \',\' \' \' || printf \'not read — LK-AO above\'))"; else echo " and every key of it is as this run found it (LK-CA1), none of the three claim switches present (CS-B1): the steps that are on keep running ($(qf_kv "${LK_B1:-$LK_G1}" on | tr \',\' \' \'))"; fi)"',
             why="summary: what changed")
    doc.subs([
        ("(QS-G1, QS-CA1; webhook.php changes only in LM's masked log lines); QuoteOrganization.php, QuoteTaxLine.php, TenantProfile.php, PublicPriceFeed.php, includes/api/api_retailer.php and retailer/index.html, $BASELINE_VERSION's byte for byte (Q-G1, Q-CA1) — cron_quote_wa.php and includes/post/post_kyc.php change only in LM's masked log lines$F_QD9;",
         "(QS-G1, QS-CA1; webhook.php $BASELINE_VERSION's byte for byte); QuoteOrganization.php, QuoteTaxLine.php, TenantProfile.php, PublicPriceFeed.php, includes/api/api_retailer.php, retailer/index.html, cron_quote_wa.php and includes/post/post_kyc.php, $BASELINE_VERSION's byte for byte (Q-G1, Q-CA1)$F_QD9;"),
        ("every key of the sales follow-up's ladder, in every place, as this run found it (LK-B1, LK-CA1);",
         '$( if [ "$MODE" = "after" ]; then echo "the sales follow-up\'s ladder but for your own steps since the deploy — LK-AO above names every key changed since then (under $EXPECTED_VERSION the claim switches, steps 6, 11 and 12, may be on once its --after-only has PASSED; root docs/75 §L.3)"; else echo "every key of the sales follow-up\'s ladder, in every place, as this run found it (LK-B1, LK-CA1), none of the three claim switches present (CS-G1, CS-B1)"; fi);'),
        ("which 5.18.104 and 5.18.105 carry unchanged and add none to", "which 5.18.104 through 5.18.106 carry unchanged and add none to"),
        ("# 5.18.103 through 5.18.105: D9 (no Delivery PDF on Uganda) was 5.18.102's change; none changes it — 5.18.105's post_kyc.php and webhook.php hunks are LM's masked log lines only",
         "# 5.18.103 through 5.18.106: D9 (no Delivery PDF on Uganda) was 5.18.102's change; none changes it — 5.18.105's post_kyc.php and webhook.php hunks were LM's masked log lines only, and 5.18.106 changes neither"),
        ('  echo "                    rollback, of the web-direct ones with no quotation yet — a record only: 5.18.104, put back,"',
         '  echo "                    rollback, of the web-direct ones with no quotation yet — a record only: 5.18.105, put back,"'),
    ], why="summary: what did not")
    # check it: three --after-only runs (§L.8), each its own command with its own log, then the quarantine's --check
    doc.lines_between('  echo "  check it          at least 20 minutes after the copy', '  echo "      $QCHECK_CMD"',
        '  echo "  check it          --after-only, three runs, each its own command, alone, with its own log and its own cash sheet:"\n'
        '  echo "                    at least 20 minutes after the copy ($( [ -n "${R19_COPY_T:-}" ] && utc_of $(( R19_COPY_T + 1200 )) || echo "the copy\'s time is not recorded")) — R19 then says whether the quarantine still holds, conclusively once master has run the retry job after the copy; ME-CA1 reads the cycle the copy straddled:"\n'
        '  echo "      $AFTER_CMD"\n'
        '  if uj_judgeable; then   # 5.18.106 (review L8-1): stage A\'s reading (UJ_A) lets UJ-ON judge F3 for this deploy\n'
        '  echo "                    again once a close pass has run since the copy (followup_close runs every 10 minutes) — UJ-ON judges F3 on it, and until one has it reads NOT YET (exit status 3) and says when to run it again:"\n'
        '  else\n'
        '  echo "                    again once a close pass has run since the copy (followup_close runs every 10 minutes), to read the closes — but UJ-ON WILL NOT JUDGE F3 FOR THIS DEPLOY: its gate did not hold at stage A ($(uj_gate_a "$UJ_A" | sed -e \'s/^ //\' -e \'s/;$//\')), and stage A\'s reading is fixed in the deploy\'s state file, so F3 stays unproven and the steps that wait for it (6, 11, 12) would follow without that proof; a judged UJ-ON needs a rollback, then a --redeploy whose stage A holds the gate. This --after-only still reads the closes, its own command, alone:"\n'
        '  fi\n'
        '  echo "      $AFTER_CMD"\n'
        '  echo "                    and the next day, Monday to Saturday, after 15:00 UTC and before 17:00 UTC — inside the sender\'s window (08:00–20:00 Kampala), where F1\'s closes, the sends and the front moving show, and after the evening WhatsApp (18:00 Kampala) has run on the new code$( [ -n "$F_QORG" ] && echo " — the uCRM catalogue\'s switch is said as found (QC-ON1: ok while it is on, a note while it is off)"):"\n'
        '  echo "      $AFTER_CMD"\n'
        '  echo\n'
        '  echo "                    then the quarantine\'s own --check, its own command, alone:"\n'
        '  echo "      $QCHECK_CMD"', why="summary: check it")
    doc.lines_between('  echo "  the screens       as the steps now on make them — My quotations once list is on, WhatsApp offers once"',
                      '  echo "                    changes. Do NOT make a test quote for a real customer: the plugin WhatsApps every new quote"',
        '  echo "  the screens       as the steps now on make them — My quotations once list is on, WhatsApp offers once"\n'
        '  echo "                    offers-test, the Inbox\'s voice transcript once voice-staff, the quotation chases once chase;"\n'
        '  echo "                    on Uganda, whatever the steps, the WhatsApp AI screen\'s OpenAI key field, which says set or"\n'
        '  echo "                    not set and never shows the key (VN-K2), and the Follow-ups screen, which lists the closes"\n'
        '  echo "                    F1–F3 make (expired, send_failed; root docs/75 §E); every other screen as under"\n'
        '  echo "                    $BASELINE_VERSION: the uCRM catalogue as it was, the Salesperson numbers card, the Cashbook and"\n'
        '  echo "                    ECO Bank as before. No screen of uCRM\'s changes. Do NOT make a test quote for a real customer:"\n'
        '  echo "                    the plugin WhatsApps every new quote"\n'
        '  else\n'
        '  echo "  the screens       no screen changes: every switch of the sales follow-up is as this run found it (LK-CA1, QF-CA5),"\n'
        '  echo "                    so every screen is as under $BASELINE_VERSION with the steps that are on — but the Follow-ups"\n'
        '  echo "                    screen, which lists the closes F1–F3 make (expired, send_failed; root docs/75 §E) as the first"\n'
        '  echo "                    close passes run. No screen of uCRM\'s changes. Do NOT make a test quote for a real customer:"\n'
        '  echo "                    the plugin WhatsApps every new quote"', why="summary: the screens")
    doc.lines_between('  echo "  your steps        yours alone, one at a time and only in the ladder\'s order (root docs/73 §H.2.3, C6) — this"',
                      '  echo "                    A step behaves under $EXPECTED_VERSION as under $BASELINE_VERSION; only its log lines carry no number."',
        '  echo "  your steps        yours alone, one at a time and only in the ladder\'s order (root docs/73 §H.2.3, C6) — this"\n'
        '  echo "                    script does none of them, and kept every one as it found it. Steps 6 (chase), 11 (offers-test)"\n'
        '  echo "                    and 12 (offers-live), the three claim switches, only once $EXPECTED_VERSION serves and its"\n'
        '  echo "                    --after-only (above) has PASSED — that run prints the owner\'s steps of root docs/75 §F, step 6"\n'
        '  echo "                    among them, in their order. Each step: on, then its check, each its own command, alone, its"\n'
        '  echo "                    log in /root/dnb-sales; never while this script, its --after-only or its rollback runs. Where"\n'
        '  echo "                    the ladder stands, read-only:"\n'
        '  echo "      cd $REPO && bash $QF_WRAPPER show"\n'
        '  echo\n'
        '  echo "                    The report, read-only on a copy of the book (P1; its wrapper pinned to $BASELINE_COMMIT, whose"\n'
        '  echo "                    report and inputs $EXPECTED_VERSION keeps byte for byte), its own command, alone:"\n'
        '  echo "      $QF_REPORT_CMD"\n'
        '  echo\n'
        '  echo "                    The enquiry follow-up report (read-only on a copy of the book) still runs — its two pinned"\n'
        '  echo "                    files are unchanged — but its section E front mirrors $BASELINE_VERSION\'s due() and misreads"\n'
        '  echo "                    after the copy until it is updated: UJ-CA and UJ-ON above are the reading after the deploy"\n'
        '  echo "                    (root docs/75 §L.2). Its own command, alone:"\n'
        '  echo "      cd $REPO && mkdir -p /root/dnb-report && bash scripts/enquiry-followup-report.sh 2>&1 | ( umask 077; exec tee /root/dnb-report/enquiry-\\$(date -u +%Y%m%dT%H%M%SZ).log )"\n'
        '  echo\n'
        '  echo "                    After each step, --after-only (check it, above): LK-AO, LM-ON, QF-ON0–QF-ON5, VA-ON1, VN-ON1–VN-ON4, OF-ON1,"\n'
        '  echo "                    OF-ON2 and AC-ON1 judge each step that is on."\n'
        '  echo "                    A step behaves under $EXPECTED_VERSION as under $BASELINE_VERSION but for the enquiry follow-ups (F1–F5, root docs/75 §C)."',
        why="summary: your steps")
    doc.sub('  echo "  deploy again      only once the cause of the rollback is understood — with --redeploy, its own command, alone:"\n',
            '  echo "  deploy again      only once the cause of the rollback is understood. First every claim switch off (CS-G1"\n'
            '  echo "                    refuses otherwise), THEN followup_enabled on (FE-G1 refuses otherwise) — each its own"\n'
            '  echo "                    command, alone, never the reverse: under $BASELINE_VERSION, followup_enabled on while a"\n'
            '  echo "                    claim switch is on is root docs/75 §F\'s hazard (claim mode closes the refused drafts"\n'
            '  echo "                    after three tries each, then sends the stale backlog). Then --redeploy, its own command,"\n'
            '  echo "                    alone; where this rollback set followup_auto_send off it stays off, and that deploy\'s PASSED"\n'
            '  echo "                    --after-only prints your own command to turn it back on:"\n',
            why="summary: deploy again")
    da = doc.t[doc.t.index('  echo "  deploy again      '):]
    da = da[:da.index('  echo "      $REDEPLOY_CMD"')]
    if not (da.index("First every claim switch off") < da.index("THEN followup_enabled on") < da.index("never the reverse")
            < da.index("Then --redeploy")) or "set_config" in da or "sales-followup.sh" in da:
        raise SystemExit("PART H: deploy again does not say the order — claim switches off first, THEN followup_enabled on, "
                         "never the reverse, then --redeploy — or prints a switch command (review 2: FX1-3)")
    doc.sub('  echo "  Send this LOG FILE back (not a copy of the terminal, and never the cash sheet). Then --after-only again, its own command, alone, with its own log:"\n',
            '  echo "  Send this LOG FILE back (not a copy of the terminal, and never the cash sheet). The owner\'s steps (root docs/75 §F) wait for a PASSED --after-only. Then --after-only again, its own command, alone, with its own log:"\n',
            why="summary: NOT YET withholds the owner's steps")

    # the owner's steps of root docs/75 §F — after a PASSED --after-only alone — and as_back_on (§L.8)
    i = doc.t.index("as_after_verdict   # 5.18.106 (root docs/75 §L.7, DL6)")
    anchor = doc.t[i:doc.t.index("\n", i) + 1]
    doc.insert_after(anchor,
        "# 5.18.106 (root docs/75 §F, §L.8, DL3): after a PASSED --after-only — no FAIL and no NOT YET — the owner's own steps,\n"
        "# each its own command on its own line with its own log, printed and never run by this script, in §F's order: raise\n"
        "# followup_daily_cap (set_config as the book's owner on the live data folder, the form of the owner's command for\n"
        "# followup_auto_send, its output cut where the settings listing begins, with a placeholder the owner replaces —\n"
        "# set_config refuses a value that still holds <...> and saves nothing; never a number of this script's); then step 6\n"
        "# through the switch tool's wrapper (its own log in /root/dnb-sales; on chase asks for a typed yes, so a terminal) and\n"
        "# its check; later, followup_run_limit, the same form. A NOT YET or a FAILED run prints none of them (a NOT YET prints\n"
        "# the one --after-only to run again). Then as_back_on: the PASSED --after-only of a deploy that followed a rollback\n"
        "# which set followup_auto_send off (--redeploy typed or not) offers the owner's own command to turn it back on — it\n"
        "# prints nothing otherwise (partR's rules). Step 6 goes by LK-AO's reading above (LK_AO_ON, review L8-2).\n"
        "if [ \"$MODE\" = \"after\" ] && [ \"$FAIL\" = \"0\" ] && [ \"$NOT_YET\" = \"0\" ]; then\n"
        "  echo\n"
        "  echo \"  the owner's steps root docs/75 §F — $EXPECTED_VERSION serves and this --after-only PASSED, so the steps that\"\n"
        "  echo \"                    waited for it may follow, yours alone, in this order, each its own command, alone, with its own\"\n"
        "  echo \"                    log; this script runs none of them, and none while it runs.\"\n"
        "  echo \"                    1. Raise followup_daily_cap — the most follow-ups sent on one number in a day. Put the number\"\n"
        "  echo \"                    you choose in place of <CAP> before you run it (set_config refuses <CAP> itself, saving nothing):\"\n"
        "  if [ -n \"$DB_OWNER\" ] && [ -n \"$PDD_IN\" ]; then\n"
        "  echo \"      docker exec -u $DB_OWNER -e DN_DATA_DIR=$PDD_IN $CONTAINER php $IN_CONTAINER/tools/set_config.php --key followup_daily_cap --value '<CAP>' 2>&1 | sed '/^  AI SETTINGS\\$/,\\$d' $(LOGW daily-cap)\"\n"
        "  echo \"                    It prints the line \\\"  followup_daily_cap = <your number>\\\" once it has saved; without that line take nothing as saved.\"\n"
        "  else\n"
        "  echo \"                    No command is printed: the owner of plugin.sqlite3 (DB_OWNER) or the data folder is not known — a settings write runs as that user, never as root; send the log file.\"\n"
        "  fi\n"
        "  # step 6 by LK-AO's reading above (LK_AO_ON; review L8-2): already on — on chase is not printed (run again it\n"
        "  # would stamp quote_chase_not_before anew); not read — where the ladder stands, first; otherwise the command\n"
        "  case \",$LK_AO_ON,\" in\n"
        "    *,chase,*)\n"
        "  echo \"                    2. Step 6, chase, is already on: LK-AO above reads it on, so the enquiry sends are in the send\"\n"
        "  echo \"                    ledger's claim mode (root docs/75 §F). Do not run on chase again — it would stamp\"\n"
        "  echo \"                    quote_chase_not_before anew. Its check, its own command, alone:\" ;;\n"
        "    *)\n"
        "  echo \"                    2. Then step 6, chase — it turns quote_chase_enabled on, which also puts the enquiry sends into\"\n"
        "  echo \"                    the send ledger's claim mode (root docs/75 §F); it asks you to type yes, so run it in a terminal;\"\n"
        "  if [ -n \"$LK_AO_ON\" ]; then\n"
        "  echo \"                    the wrapper writes its own log in /root/dnb-sales:\"\n"
        "  else\n"
        "  echo \"                    the wrapper writes its own log in /root/dnb-sales. LK-AO above could not read the ladder's keys,\"\n"
        "  echo \"                    so whether step 6 is on already is not shown — read where the ladder stands first, its own\"\n"
        "  echo \"                    command, alone:\"\n"
        "  echo \"      cd $REPO && bash $QF_WRAPPER show\"\n"
        "  echo \"                    and run on chase only if show does not list chase among the steps on (run again, it would\"\n"
        "  echo \"                    stamp quote_chase_not_before anew):\"\n"
        "  fi\n"
        "  echo \"      cd $REPO && bash $QF_WRAPPER on chase\"\n"
        "  echo \"                    then its check, its own command, alone:\" ;;\n"
        "  esac\n"
        "  echo \"      cd $REPO && bash $QF_WRAPPER --check chase\"\n"
        "  echo \"                    and --after-only again (check it, above): LK-AO and QF-ON0–QF-ON5 judge the step now on.\"\n"
        "  echo \"                    3. Later, once sends flow, consider raising followup_run_limit — the follow-ups judged a run; the\"\n"
        "  echo \"                    same form, the number you choose in place of <LIMIT>:\"\n"
        "  if [ -n \"$DB_OWNER\" ] && [ -n \"$PDD_IN\" ]; then\n"
        "  echo \"      docker exec -u $DB_OWNER -e DN_DATA_DIR=$PDD_IN $CONTAINER php $IN_CONTAINER/tools/set_config.php --key followup_run_limit --value '<LIMIT>' 2>&1 | sed '/^  AI SETTINGS\\$/,\\$d' $(LOGW run-limit)\"\n"
        "  echo \"                    It prints the line \\\"  followup_run_limit = <your number>\\\" once it has saved; without that line take nothing as saved.\"\n"
        "  else\n"
        "  echo \"                    No command is printed: the owner of plugin.sqlite3 (DB_OWNER) or the data folder is not known — a settings write runs as that user, never as root; send the log file.\"\n"
        "  fi\n"
        "  as_back_on   # 5.18.106 (root docs/75 §L.8): followup_auto_send off since the latest rollback wrote it — the owner's own command to turn it back on, or nothing\n"
        "fi\n", why="summary: the owner's steps and as_back_on")
    # the ECO Bank block: after a PASSED run only — a NOT YET prints one next command, the --after-only to run again
    doc.lines_between("# 5.18.98 — after a PASSED deploy or --after-only whose G3 went to its note under --without-bank-balance (this run's, or",
                      "# code's put-back alone (PUTBACK_CMD); it counts nothing, and says so.",
        "# 5.18.98 — after a PASSED deploy or --after-only whose G3 went to its note under --without-bank-balance (this run's, or\n"
        "# the deploy's recorded), ECO Bank's own read-only check, alone in its own block with its own log: whether both cards\n"
        "# show a figure now. No switch command: the switch is the operator's, ON, and no mode of this script prints or runs\n"
        "# one. 5.18.106: after a PASSED run alone, never after a NOT YET — a NOT YET prints exactly one next command, the\n"
        "# --after-only to run again (root docs/75 §L.5), and this read-only check, which asks G3's question again and nothing\n"
        "# of F3's, loses nothing by waiting for that run's PASSED. Then the rollback, last, in its own block (root docs/75\n"
        "# §L.7): followup_auto_send '0' first when it reads on and the five conditions hold (AS-RB1), then 5.18.105 put back\n"
        "# by the documented deploy, every other switch kept — no switch tool, no off-hook — then RB106; ECO Bank and its switch\n"
        "# stay as they are. It needs no explicit choice: going back to 5.18.105 has no refusal of its own, and the ladder never\n"
        "# blocks it. The by-hand path is the code's put-back alone (PUTBACK_CMD); it counts nothing and writes no setting, and\n"
        "# says so.", why="summary: ECO Bank's block comment")
    doc.sub('if [ "$MODE" != "rollback" ] && [ "$FAIL" = "0" ] && [ -n "${V3J_WBB:-}" ]; then\n',
            'if [ "$MODE" != "rollback" ] && [ "$FAIL" = "0" ] && [ "$NOT_YET" = "0" ] && [ -n "${V3J_WBB:-}" ]; then\n',
            why="summary: ECO Bank's block after a PASSED run alone")
    # the rollback's paragraph (RB106)
    doc.lines_between('  echo "  Only if it is ever needed — the rollback, never pasted together with any other command (root docs/44 §16.9)."',
                      '  echo "      $ROLLBACK_CMD"',
        '  echo "  Only if it is ever needed — the rollback, never pasted together with any other command (root docs/44 §16.9)."\n'
        '  echo "  $BASELINE_VERSION ($BASELINE_COMMIT) back — it asks you to type ROLLBACK before it changes anything. Before you"\n'
        '  echo "  type it, it reads followup_auto_send and root docs/75 §L.7\'s five conditions (AS-RB0) and says what it will"\n'
        '  echo "  write; after it, before the put-back, it writes one key, followup_auto_send \'0\', through the plugin\'s own"\n'
        '  echo "  writer, when it reads on and the five conditions hold (AS-RB1), so that $BASELINE_VERSION approves no draft by"\n'
        '  echo "  itself — where it cannot, it says so in capitals before you type ROLLBACK, with your own command where the"\n'
        '  echo "  cause allows one. It keeps every other switch of the sales follow-up as it is — no switch tool, no off-hook:"\n'
        '  echo "  every step that is on keeps running. If a claim switch is present (step 6, 11 or 12: claim mode under"\n'
        '  echo "  $BASELINE_VERSION), it says so in capitals before you type ROLLBACK, with its off command; it never runs it."\n'
        '  echo "  Then $BASELINE_VERSION is put back and checked (RB106: LK-RB1, LM-RB1 — 5.18.105\'s masked lines still in"\n'
        '  echo "  place — and AS-RB2, followup_auto_send off). Every close $EXPECTED_VERSION made stays (nothing reopens one),"\n'
        '  echo "  and followup_auto_send stays off until you turn it on. The uCRM catalogue stays as it is. Quotes already made"\n'
        '  echo "  in uCRM are not changed, and the $N_ADDED files $EXPECTED_VERSION adds (its tests) stay on disk, named by"\n'
        '  echo "  nothing $BASELINE_COMMIT tracks. The summary\'s sign-off, the card\'s owner lists, every number and its owner,"\n'
        '  echo "  the quoting organization, the Cashbook, cash in hand, ECO Bank, its switch, 088\'s tables and every statement"\n'
        '  echo "  kept stay exactly as they are; 5.18.95\'s security fixes and 5.18.94\'s documents too. Then run the"\n'
        '  echo "  quarantine\'s own --check (A14 of any later deploy needs it)."\n'
        '  echo "      $ROLLBACK_CMD"', why="summary: the rollback")
    # the put-back by hand: partR's words, asserted (it writes no setting and does not switch followup_auto_send off)
    doc.must('  echo "  one above; it counts nothing, runs no switch tool and writes no setting — it does not switch followup_auto_send"\n'
             '  echo "  off, as the rollback above does first, AS-RB1; your own command for that follows, alone, where it is needed):"\n'
             '  echo "      $PUTBACK_CMD"\n'
             '  as_show "$(as_para sum)"', 1, why="the put-back by hand, as partR wrote it")

    # ── 18 Nov: FE-G1 (DL11) in partT's stage-A heading and comments; LK_AO_ON (review L8-2); BR1's note (L8-4) ──
    for s in ("uj_fe_g1() {\n", "uj_judgeable() {", "uj_gate_a() {\n", "  uj_fe_g1   # FE-G1 — 5.18.106 (DL11)",
              'AS_RB2_EFF="$(kv "$o" eff)"', "' on=', $on === [] ? '-' : implode(',', $on)", "as_back_on() {\n"):
        doc.must(s, 1, why="partUJ's, partR's and the source's texts partH's new texts read")
    lad = show(pin, "lib/SalesFollowupLadder.php")
    if "'chase' => ['n' => '6'," not in lad \
            or "'switch' => ['quote_chase_enabled' => '1'],\n            'stamp' => 'quote_chase_not_before'," not in lad:
        raise SystemExit("PART H: step 6 (chase) at the pin does not switch quote_chase_enabled and stamp quote_chase_not_before")
    sft = show(pin, "tools/sales_followup.php")
    son = sft[sft.index("function sf_on("):]
    son = son[:son.index("\nfunction ", 1)]
    if "    if ($row['stamp'] !== null) $changes[$row['stamp']] = UtcClock::stamp($now);\n" not in son \
            or "isOn($config, $step)" in son or "isOn($config, $step)" in lad[lad.index("public static function admit("):lad.index("public static function skipCascade(")]:
        raise SystemExit("PART H: on chase at the pin does not stamp quote_chase_not_before again over a step already on")
    doc.sub("5.18.106's CS-G1 and U1–U5; read-only, on copies through the pin's code)\"\n",
            "5.18.106's CS-G1, U1–U5 and FE-G1; read-only, on copies through the pin's code)\"\n", why="stage A's heading: FE-G1")
    doc.sub("# qf_stage — QF-G1–QF-G3, LK-G1, CS-G1, QF-N1–QF-N7 and U1–U5 (CS-G1 and U1–U5 since 5.18.106, root docs/75 §L.3,\n"
            "# §L.4), stage A, before anything changes (a deploy and --check): on copies,\n",
            "# qf_stage — QF-G1–QF-G3, LK-G1, CS-G1, QF-N1–QF-N7, U1–U5 and FE-G1 (CS-G1, U1–U5 and FE-G1 since 5.18.106, root\n"
            "# docs/75 §L.3, §L.4, DL11), stage A, before anything changes (a deploy and --check): on copies,\n", why="qf_stage's comment: FE-G1")
    doc.sub("  # §L.4): CS-G1, the three claim switches absent, a refusal; U1–U5, the readings before the deploy, notes. On copies,\n",
            "  # §L.4, DL11): CS-G1, the three claim switches absent, a refusal; U1–U5, the readings before the deploy, notes;\n"
            "  # FE-G1, their one refusal: U4 read followup_enabled off. On copies,\n", why="stage A's call: FE-G1")
    doc.insert_after('LK_G1=""; LK_B1=""; LK_ON=""; LK_SKIP=""; LK_SET_NAMES=""; LK_AT=""; LK_ROWS_B=""; LK_SHOW_A=""; LK_ON_A=""; '
                     'LK_RB0=""; LK_RB0_ROWS=""; LK_RB0_SHOW=""\n', LKAO_DEF, why="LK_AO_ON, the global (review L8-2)")
    doc.insert_after(LK_OK_LINE, LKAO_SET, why="LK_AO_ON, its one assignment, in lk_ao (review L8-2)")
    doc.line('  [ "$BR1_LEFT" = "0" ] || [ -n "$REDEPLOY" ] || note "BR1 $BR1_LEFT of $EXPECTED_VERSION\'s own files are on disk although --redeploy was not asked for:',
             BR1_NOTE, why="BR1's note: a deploy after a rollback (review L8-4)")

    # ── the result ──
    t = doc.t
    hdr_end = t.index("\nset -uo pipefail\n")
    head = t[:hdr_end]
    for s in ("deploy-5.18.105.sh —", "5.18.104 must be the live plugin", "(step 4; inferred)", "5.18.105'S OWN CHECKS",
              "A ROLLBACK TO 5.18.104", "the step-12 notice", "994d660 writes numbers", "release/5.18.105, is cut",
              "AGAINST 5.18.103'S CODE", "AGAINST 5.18.104'S CODE", "deploy 5.18.105 again", "5.18.105 never clears",
              "root docs/74 §H.4.4): 5.18.105", "the documented deploy of 5.18.104", "V as 5.18.104", "R 5.18.105 installed",
              "5.18.105's own first", "No key is ever set: ucrm_quote_organization_id", "while 5.18.104 is live",
              "5.18.104 then quotes as 5.18.103"):
        if s in head:
            raise SystemExit(f"PART H: the header still says {s!r}")
    for pat in (r"numbers( in)? again", "WRITES PHONE", "reached by nothing", "read by nothing"):
        m = re.search(pat, t, re.I)
        if m:
            raise SystemExit(f"PART H: {pat!r} survives: {t[max(0, m.start() - 80):m.end() + 40]!r}")
    for n, l in enumerate(t.split("\n"), 1):
        if re.search(r"keeps? every switch|every switch (still )?kept", l) and "every switch of the sales follow-up kept exactly as found" not in l \
                and "every switch kept by the deploy" not in l and "the deploy keeps every switch" not in l \
                and "every other switch" not in l and "followup_auto_send" not in l:
            raise SystemExit(f"PART H: line {n} says every switch is kept, unqualified: {l.strip()[:120]}")
    cs = [l for l in t.split("\n") if "--clear-switches" in l and not l.lstrip().startswith("#")]
    if len(cs) != 2 or not cs[0].startswith("    --clear-switches)   #") or not cs[1].startswith("      echo \"--clear-switches: refused") \
            or "exit 64" not in cs[1]:
        raise SystemExit(f"PART H: --clear-switches is not the parser's label and its echo on a line of its own ({len(cs)})")
    blk = t[t.index("if [ \"$MODE\" = \"after\" ] && [ \"$FAIL\" = \"0\" ] && [ \"$NOT_YET\" = \"0\" ]; then\n"):]
    blk = blk[:blk.index("\nfi\n")]
    i_cap, i_on, i_chk, i_lim, i_back = (blk.index("--key followup_daily_cap --value '<CAP>'"), blk.index("bash $QF_WRAPPER on chase\""),
                                          blk.index("bash $QF_WRAPPER --check chase\""), blk.index("--key followup_run_limit --value '<LIMIT>'"),
                                          blk.index("  as_back_on   #"))
    if not (i_cap < i_on < i_chk < i_lim < i_back):
        raise SystemExit("PART H: the owner's steps are not in root docs/75 §F's order, as_back_on last")
    for l in blk.split("\n"):
        if ("set_config.php" in l or "QF_WRAPPER" in l) and not l.lstrip().startswith("echo \""):
            raise SystemExit(f"PART H: a writer's command in the owner's steps is not an echo line: {l.strip()[:100]}")
        if "OWNER_AUTOSEND_CMD" in l:
            raise SystemExit("PART H: the owner's steps expand OWNER_AUTOSEND_CMD")
    if t.count("\n  as_back_on   #") != 1 or len(re.findall(r"(?m)^\s*as_back_on\b(?!\(\))", t)) != 1:
        raise SystemExit("PART H: as_back_on is not called once")
    if t.index("as_after_verdict   # 5.18.106") > t.index("if [ \"$MODE\" = \"after\" ] && [ \"$FAIL\" = \"0\" ] && [ \"$NOT_YET\" = \"0\" ]; then") \
            or t.index("  as_back_on   #") > t.index("if [ \"$MODE\" != \"rollback\" ] && [ \"$FAIL\" = \"0\" ] && [ \"$NOT_YET\" = \"0\" ] && [ -n \"${V3J_WBB:-}\" ]; then"):
        raise SystemExit("PART H: the owner's steps are not after the verdict and before ECO Bank's block")
    if t.count("$AFTER_CMD\"") < 5:
        raise SystemExit("PART H: --after-only is not printed three times in check it, beside the NOT YET branch and the runbook")
    # 18 Nov (rev106-findings, DL11): the review's partH items and FE-G1, asserted on the result
    for s, n in (("LK_AO_ON=\"\"\n", 1), (LK_OK_LINE + LKAO_SET, 1), (BR1_NOTE + "\n", 1),
                 ("  if uj_judgeable; then   # 5.18.106 (review L8-1)", 1), ("$(uj_gate_a \"$UJ_A\" | sed -e 's/^ //' -e 's/;$//')", 1),
                 ("$( case \"${AS_RB2_EFF:-}\" in off) echo \"— it reads off now, so a person approves each draft", 1),
                 ("$( if [ \"$MODE\" = \"after\" ]; then echo \"; its keys as LK-AO above reads them against the deploy's", 1),
                 ("$( if [ \"$MODE\" = \"after\" ]; then echo \"the sales follow-up's ladder but for your own steps since the deploy", 1),
                 ("  case \",$LK_AO_ON,\" in\n    *,chase,*)\n", 1), ("5.18.106's CS-G1, U1–U5 and FE-G1; read-only", 1),
                 ("# qf_stage — QF-G1–QF-G3, LK-G1, CS-G1, QF-N1–QF-N7, U1–U5 and FE-G1", 1)):
        doc.must(s, n, why="partH, the review of 18 Nov and DL11")
    for s in ("— with it off a person approves each draft", "a --redeploy's", "after a --redeploy whose rollback set",
              "5.18.106's CS-G1 and U1–U5;", "U1–U5: notes, never a refusal"):
        doc.mustnot(s, why="partH, the review of 18 Nov and DL11")
    if len(re.findall(r"(?m)^\s*LK_AO_ON=", t)) != 2 or (LK_OK_LINE + LKAO_SET) not in re.search(r"(?ms)^lk_ao\(\) \{\n(.*?)^\}", t).group(1):
        raise SystemExit("PART H: LK_AO_ON is not defined once and assigned once, in lk_ao, right after its whole reading")
    for w in ("FE-G1, stage A (and so --check", "FE-G1 refuses otherwise", "THEN followup_enabled on", "never the reverse",
              "CS-G1, FE-G1, LK-G1, LM-G1", "CS-G1, U1–U5 and FE-G1 — and stop", "and FE-G1 (a refusal: U4 read followup_enabled off)",
              "U1–U5, FE-G1, UJ-CA", "after a deploy that followed a rollback", "--redeploy typed or not", "FE-G1 while"):
        if w not in re.sub(r"\s*\n#\s+", " ", head):
            raise SystemExit(f"PART H: the header does not say {w!r}")
    ck = t[t.index('  echo "  check it          --after-only, three runs'):t.index('  echo "      $QCHECK_CMD"')]
    if not (ck.index("  if uj_judgeable; then") < ck.index("UJ-ON judges F3 on it") < ck.index("\n  else\n")
            < ck.index("UJ-ON WILL NOT JUDGE F3 FOR THIS DEPLOY") < ck.index("\n  fi\n") < ck.rindex('  echo "      $AFTER_CMD"')):
        raise SystemExit("PART H: check it does not say UJ-ON judges F3 only where stage A's reading lets it (uj_judgeable)")
    st2 = blk[blk.index('  case ",$LK_AO_ON," in\n'):blk.index("bash $QF_WRAPPER --check chase\"")]
    if not (st2.index("    *,chase,*)\n") < st2.index("Do not run on chase again") < st2.index("\n    *)\n")
            < st2.index('  if [ -n "$LK_AO_ON" ]; then\n') < st2.index("bash $QF_WRAPPER show\"") < st2.index("\n  fi\n")
            < st2.index("bash $QF_WRAPPER on chase\"") < st2.index("\n  esac\n")) or st2.count("bash $QF_WRAPPER on chase\"") != 1:
        raise SystemExit("PART H: §F's step 2 does not go by LK-AO's reading: already on, not read, the command")
    for p in ("#            U1–U4 in the state file, UJ_A) and FE-G1", "#          F summary: which of the checks", "#            ME-CA1, AS-RB0,",
              "#            LK-AO, LK-RB0, LK-RB1,", "#            QF-N1–QF-N7, QF-CA1", "#            --check, later followup_run_limit), each its own command, and, after a deploy",
              "#            which set followup_auto_send off,", "#            verdict, its own block,", "#                        CS-G1 refuses while a claim switch",
              "#                        followup_enabled reads off (DL11)", "#                        and its PASSED --after-only reads that rollback",
              "# qf_stage — QF-G1–QF-G3", "# docs/75 §L.3, §L.4, DL11), stage A", "  # §L.4, DL11): CS-G1", "  # FE-G1, their one refusal",
              "# 5.18.106 (review L8-2): LK_AO_ON", "# alone; empty while LK-AO", "  # step 6 by LK-AO's reading", "  # would stamp quote_chase"):
        ls = [l for l in t.split("\n") if l.startswith(p)]
        if len(ls) != 1 or len(ls[0]) > 120:
            raise SystemExit(f"PART H: a comment line it writes is not found once, or is longer than 120 characters: {p!r} {[len(x) for x in ls]}")
