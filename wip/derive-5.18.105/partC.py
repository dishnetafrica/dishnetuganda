# Part C — the carried texts that, under EXPECTED_VERSION=5.18.105 and BASELINE_VERSION=5.18.104, name the wrong release
# (root docs/74 §H.4.7): the rollback's words (5.18.104 is put back now, not 5.18.103) and the comments that say which
# release is live, what the rollback restores and what Q-G1, BR1, A14 and stamp_copy read. Fixed releases are written as
# literals, never as EXPECTED_VERSION or BASELINE_VERSION, and each literal is read off Git here first: 994d660's manifest
# says 5.18.104, the pin's 5.18.105. Every fact a new text states is read off Git and asserted: the uCRM catalogue's and
# the quoting files 5.18.105 keeps byte for byte, post_kyc.php's and webhook.php's hunks all LM's masked lines (partL),
# the counts (partA's). Nothing here is a check; each edit changes printed words or a comment only.
# The review's D4 and D5 (at the end): A0's, Q-G1's and Q-CA1's kept enumerations no longer name the two files 5.18.105
# changes (the Cashbook's post handler, cron_quote_wa.php); the stage-A texts that called 5.18.103 live, or 5.18.104 the
# release, say $BASELINE_VERSION and $EXPECTED_VERSION — printed lines name the run's own releases through the variables,
# as their neighbours do; a comment keeps a literal.
import re
from d105lib import git, sha_at

V104, V105 = "5.18.104", "5.18.105"


def apply(doc, ctx):
    repo, plugin, base, pin = ctx["repo"], ctx["plugin"], ctx["base_full"], ctx["pin_full"]
    show = lambda c, p: git(repo, "show", f"{c}:{plugin}/{p}").decode()
    for c, v in [(base, V104), (pin, V105)]:
        m = re.search(r'^    "version": "([0-9.]+)",$', show(c, "manifest.json"), re.M)
        if not m or m.group(1) != v:
            raise SystemExit(f"C: manifest.json at {c[:7]} says {m.group(1) if m else '?'}, expected {v}")
    n_add, n_chg = ctx["n_added"], ctx["n_changed"]
    chg = set(ctx["changed"])
    # the uCRM catalogue and the files a web-direct application is quoted through: byte for byte at 994d660 and the pin
    same = ["lib/UcrmCatalogue.php", "tabs/sales/ucrm_catalogue.php", "lib/UcrmQuoteLines.php", "lib/UcrmQuoteLinks.php",
            "lib/QuoteOrganization.php", "lib/KycService.php", "lib/KycCrmSync.php", "tabs/sales/kyc_form.php",
            "tabs/sales/send_quote.php", "tabs/sales/applications.php", "lib/UcrmLeadSync.php", "lib/PluginQuotePdf.php"]
    for f in same:
        if f in chg or sha_at(repo, base, f"{plugin}/{f}") != sha_at(repo, pin, f"{plugin}/{f}"):
            raise SystemExit(f"C: {f} is not byte for byte the same at {V104} and the pin")
    # post_kyc.php and webhook.php: every hunk is one of LM's masked lines (partL labelled each from its marker)
    for f in ("includes/post/post_kyc.php", "webhook.php", "lib/QuotationService.php"):
        if f not in chg:
            raise SystemExit(f"C: {f} is not among the files {V105} changes")
        labels = [l for (ff, l) in ctx["lm_entries"] if ff == f]
        if not labels or not all(l.startswith("LN-") or l.startswith("loader") for l in labels):
            raise SystemExit(f"C: {f}'s hunks are not all LM's masked lines ({labels})")

    # ── the put-back's hazard text: 5.18.104's files now ──
    doc.line('PUTBACK_HAZARD="The put-back below copies 5.18.103\'s files and counts nothing.',
             f'PUTBACK_HAZARD="The put-back below copies {V104}\'s files and counts nothing. It changes nothing of the uCRM catalogue (its screen, its settings and its switch stay as they are; {V105} changed none of it) and nothing of the web-direct applications: {V104} quotes each itself, by its stored uCRM ids, and one it refuses says why on its Orders card. Quote none of them in uCRM by hand because of this put-back. It runs no switch tool: every step of the sales follow-up keeps running as its switch says."',
             why="PUTBACK_HAZARD")

    # ── the rollback's stage-A notes (QC-RB2 before, and what the rollback means) ──
    doc.sub("  # QC-RB2 — 5.18.103 (docs/72 §G.10, §G.13), carried, before anything changes: the web-direct applications with no\n"
            "  # quotation made, counted read-only on a consistent copy of the book (qcrb_count) — a record: 5.18.103 handles each as\n"
            "  # 5.18.104 does, so the rollback leaves none to quote by hand; a note, never a refusal. Their ids go to the sheet.\n",
            "  # QC-RB2 — 5.18.103 (docs/72 §G.10, §G.13), carried, before anything changes: the web-direct applications with no\n"
            f"  # quotation made, counted read-only on a consistent copy of the book (qcrb_count) — a record: {V104} handles each as\n"
            f"  # {V105} does, so the rollback leaves none to quote by hand; a note, never a refusal. Their ids go to the sheet.\n",
            why="QC-RB2 before comment")
    doc.sub("— the rollback changes nothing for them: 5.18.103 retries and quotes each exactly as 5.18.104 does (the same KycService and UcrmQuoteLines)",
            f"— the rollback changes nothing for them: {V104} retries and quotes each exactly as {V105} does (the same KycService and UcrmQuoteLines)",
            why="QC-RB2 before")
    doc.line('  note "after the rollback the code is 5.18.103\'s again where 5.18.104 changed it.',
             f'  note "after the rollback the code is {V104}\'s again where {V105} changed it: the lines {V105} masked write phone numbers again (LM-RB1), and every switch of the sales follow-up stays as it is — no switch tool runs, the steps that are on keep running (LK-RB0 above, LK-RB1 after). On Uganda the uCRM catalogue is 5.18.103\'s own and stays exactly as it is — {V104} and {V105} keep every file of it byte for byte and the rollback touches none of it: its screen, its settings (the switch, the installation product, the products kept off, the standard quotations) and its switch stay as an admin left them, and the KYC form, Send Quotation and the lead quotes offer what they offer now. A web-direct application is handled by {V104} exactly as {V105} handles it — retried by its uCRM ids, Make the quotation on its Orders card where that applies — so the rollback adds none to quote in uCRM by hand (QC-RB2 counts them, for the record). Nothing is quoted at price 0: {V104} prices from uCRM or refuses (docs/72 §G.8). Quotes already made in uCRM are not changed. The quotation summary\'s sign-off, the card\'s owner lists and every number, the quoting organization, the Cashbook, cash in hand and ECO Bank stay exactly as they are: $EXPECTED_VERSION changes none of them. A later deploy of $EXPECTED_VERSION goes with --redeploy. 5.18.95\'s security fixes stay; 5.18.94\'s documents too; master keeps 5.18.92\'s lock, main.php 5.18.93\'s change and the retry job 5.18.91\'s gate"',
             why="rollback means")

    # ── V1's automatic rollback, QC-RB2 ──
    doc.sub("— 5.18.104 changes nothing of how a web-direct application is quoted, so whichever release now serves handles each the same way",
            f"— {V105} changes nothing of how a web-direct application is quoted, so whichever release now serves handles each the same way",
            why="V1 QC-RB2")

    # ── RB: QC-RB2 after, and its comment ──
    doc.sub("# the sheet). Notes, never a failure: the rollback rewrites no application, and 5.18.103 keeps each on the direct rules\n"
            "# exactly as 5.18.104 did — none is left to quote by hand because of it. QCRB_SAY carries the reading to the summary.\n",
            f"# the sheet). Notes, never a failure: the rollback rewrites no application, and {V104} keeps each on the direct rules\n"
            f"# exactly as {V105} did — none is left to quote by hand because of it. QCRB_SAY carries the reading to the summary.\n",
            why="QC-RB2 after comment")
    doc.sub("# table (RBQ_S's qcrb1t: absent, or its records) — 5.18.103's own screen's, read by it again after a 5.18.104\n",
            f"# table (RBQ_S's qcrb1t: absent, or its records) — 5.18.103's own screen's, read by {V104} again after a {V105}\n",
            why="QC-RB1 table comment")
    doc.sub("— 5.18.103 keeps each on the direct rules exactly as 5.18.104 did (retried by its uCRM ids;",
            f"— {V104} keeps each on the direct rules exactly as {V105} did (retried by its uCRM ids;", why="QC-RB2 after")

    # ── the rollback summary ──
    i = doc.t.index('            echo "  what it means     on Uganda the uCRM catalogue is 5.18.103\'s own and the rollback leaves it exactly as it was')
    j = doc.t.index("\n", i)
    doc.sub(doc.t[i:j],
            '            echo "  what it means     on Uganda the uCRM catalogue is 5.18.103\'s own and the rollback leaves it exactly as it was — its screen, its settings and its switch as an admin left them, the KYC form, Send Quotation and the lead quotes offering what they offered (RB); the web-direct applications stay on 5.18.103\'s direct rules, as under '
            + V105 + ' — ${QCRB_SAY:-not counted} (QC-RB2$( [ -n "$QC_RB" ] && echo "; $QC_RB_IDS")): retried by their uCRM ids, Make the quotation on an Orders card where it applies; the rollback adds none to quote by hand, and nothing is quoted at price 0. Every switch of the sales follow-up is as it was (LK-RB1): the steps that were on keep running, and the lines '
            + V105 + ' masked write phone numbers again (LM-RB1). The files ' + V105 + ' adds stay on disk, read by nothing (QC-RB1). The Cashbook, ECO Bank, every number and the quoting organization stay as they are (RB)" ;;',
            why="summary what it means")
    doc.sub('  echo "                    rollback, of the web-direct ones with no quotation yet — a record only: 5.18.103, put back,"\n',
            f'  echo "                    rollback, of the web-direct ones with no quotation yet — a record only: {V104}, put back,"\n',
            why="summary sheet words")
    doc.sub("    on|stageA|unknown) F_QD9=\"\" ;;   # 5.18.103 and 5.18.104: D9 (no Delivery PDF on Uganda) was 5.18.102's change; neither changes it — post_kyc.php is unchanged and 5.18.104's webhook.php hunks are P7's agent copy only\n",
            "    on|stageA|unknown) F_QD9=\"\" ;;   # 5.18.103 through 5.18.105: D9 (no Delivery PDF on Uganda) was 5.18.102's change; none changes it — 5.18.105's post_kyc.php and webhook.php hunks are LM's masked log lines only\n",
            why="F_QD9 comment")

    # ── comments that say what is live and what the rollback restores ──
    doc.sub("# Since 5.18.91: with the container's record on the rollback target (5.18.103 here), every file this release changes\n",
            f"# Since 5.18.91: with the container's record on the rollback target ({V104} here), every file this release changes\n",
            why="BASE_DIFFERS comment")
    doc.sub("# reviews). Files the release adds are not asked about: a rollback leaves them on disk, and no 5.18.103 file names any\n"
            "# of them (EXPECTED_ADDED: 5.18.104's 215; QC-RB1 shows it after a rollback) — its MigrationRunner alone reads a\n"
            "# directory, migrations/, where it finds 089–091 and passes over those already in its ledger — and BR1 counts the ones\n"
            "# an earlier rollback left.\n",
            f"# reviews). Files the release adds are not asked about: a rollback leaves them on disk, and no {V104} file names any\n"
            f"# of them (EXPECTED_ADDED: {V105}'s {n_add}, its tests; QC-RB1 shows it after a rollback) — and BR1 counts the ones\n"
            "# an earlier rollback left.\n",
            why="BASE_DIFFERS comment 2")
    doc.sub("# BR1 — since 5.18.97 (SPEC §2.10b R1): the installed plugin is live 5.18.103 — every file 221c9dc tracks under the plugin,\n"
            "# data/ aside, byte for byte (sha256 on the host, from git archive into this run's folder, removed at once), not only the\n"
            "# files this release changes (the record and the manifest say 5.18.103 above; BASE_DIFFERS read this release's own).\n"
            "# Files on disk beyond them are not asked about: deploy-hybrid.sh never deletes, so an earlier 5.18.104 and its\n"
            "# rollback leave the 215 files it adds behind (root docs/73 §H.2.16) — counted and said (BR1_LEFT, over EXPECTED_ADDED);\n"
            "# no 5.18.103 file names them (QC-RB1), and the copy replaces them. 5.18.103's MigrationRunner globs migrations/*.sql at\n"
            "# boot and passes over the files in its ledger: 089–091, applied at 5.18.104's first boot, are not run again (one left\n"
            "# on disk that never ran would run then).\n",
            f"# BR1 — since 5.18.97 (SPEC §2.10b R1): the installed plugin is live {V104} — every file 994d660 tracks under the plugin,\n"
            "# data/ aside, byte for byte (sha256 on the host, from git archive into this run's folder, removed at once), not only the\n"
            f"# files this release changes (the record and the manifest say {V104} above; BASE_DIFFERS read this release's own).\n"
            f"# Files on disk beyond them are not asked about: deploy-hybrid.sh never deletes, so an earlier {V105} and its\n"
            f"# rollback leave the {n_add} files it adds (its tests) behind — counted and said (BR1_LEFT, over EXPECTED_ADDED);\n"
            f"# no {V104} file names them (QC-RB1), and the copy replaces them. {V105} adds no migration: 089–091, applied at\n"
            f"# {V104}'s first boot, are in the ledger and are not run again.\n",
            why="BR1 comment")
    doc.sub("# Q-G1 — 5.18.99 (SPEC-quote-company §6; 5.18.98's G1, carried): the live plugin is 5.18.103 — the container's record and\n"
            "# the manifest say so above, and (a deploy) BR1 has read every file 221c9dc tracks — and, by the sha256 THIS SCRIPT pins\n"
            "# for each (not only Git's), every file 5.18.104 changes (45: navigation.php and webhook.php among them) is 5.18.103's\n"
            "# as installed (PREV_CHANGED_SHA256S), and every file 5.18.103 has that 5.18.104 must not touch (158, PREV_KEPT_SHA256S):\n"
            "# ECO Bank's module, its link on the CEO page (BK3 reads the one in navigation.php, which 5.18.104 changes outside it),\n",
            f"# Q-G1 — 5.18.99 (SPEC-quote-company §6; 5.18.98's G1, carried): the live plugin is {V104} — the container's record and\n"
            "# the manifest say so above, and (a deploy) BR1 has read every file 994d660 tracks — and, by the sha256 THIS SCRIPT pins\n"
            f"# for each (not only Git's), every file {V105} changes ({n_chg}: webhook.php and evo_webhook.php among them) is {V104}'s\n"
            f"# as installed (PREV_CHANGED_SHA256S), and every file {V104} has that {V105} must not touch ({ctx['n_kept']}, PREV_KEPT_SHA256S):\n"
            "# ECO Bank's module, its link on the CEO page (BK3 reads the one in navigation.php),\n",
            why="Q-G1 comment")
    doc.sub("# code reads and 5.18.99's quoting files that 5.18.104 keeps (cron_quote_wa.php, QuoteTaxLine and TenantProfile;\n",
            f"# code reads and 5.18.99's quoting files that {V105} keeps (QuoteTaxLine and TenantProfile;\n", why="Q-G1 comment 2")
    doc.sub("# lib/MigrationRunner.php among them), unchanged. The 215 files 5.18.104 adds are not asked about here: BR1 counts any\n",
            f"# lib/MigrationRunner.php among them; 5.18.104's ladder trio, held by sha256), unchanged. The {n_add} files {V105} adds\n"
            "# (its tests) are not asked about here: BR1 counts any\n", why="Q-G1 comment 3")
    doc.sub("# 260 files and no other, the 215 it adds among them — and the 158 files of 5.18.103 this release must leave\n"
            "# as they are (PREV_KEPT_SHA256S) by theirs, in both release commits: what Q-G1 reads installed before anything changes,\n"
            "# Q-CA1 after the copy and RB after a rollback. 5.18.99: and every file it changes (45) by the sha256 5.18.103's\n"
            "# release commit (221c9dc) gives it (PREV_CHANGED_SHA256S; an added file has no such form, and is refused there) —\n",
            f"# {n_add + n_chg} files and no other, the {n_add} it adds among them — and the {ctx['n_kept']} files of {V104} this release must leave\n"
            "# as they are (PREV_KEPT_SHA256S) by theirs, in both release commits: what Q-G1 reads installed before anything changes,\n"
            f"# Q-CA1 after the copy and RB after a rollback. 5.18.99: and every file it changes ({n_chg}) by the sha256 {V104}'s\n"
            "# release commit (994d660) gives it (PREV_CHANGED_SHA256S; an added file has no such form, and is refused there) —\n",
            why="PIN_SHA comment")
    doc.sub("# Since 5.18.97 (its review): and after this script's own latest copy of the plugin — a deploy of 5.18.104 (COPY_AT in its\n",
            f"# Since 5.18.97 (its review): and after this script's own latest copy of the plugin — a deploy of {V105} (COPY_AT in its\n",
            why="A14 comment")
    doc.sub("# or a deploy over a rolled-back 5.18.104, goes on only once a --check that began after that rollback has PASSED.\n",
            f"# or a deploy over a rolled-back {V105}, goes on only once a --check that began after that rollback has PASSED — and\n"
            f"# {V104}'s own copy (9 Nov 03:50:25 UTC) is followed by the --check begun at 04:39:36 UTC, which PASSED (root docs/07).\n",
            why="A14 comment 2")
    doc.sub("# adds too: after a rollback the 215 that 5.18.104 adds are left on disk (deploy-hybrid.sh never deletes; root docs/73\n"
            "# §H.2.16) and are given the time as well, so the count then is every file of the delta found on disk, not the ones\n"
            "# copied.\n",
            f"# adds too: after a rollback the {n_add} that {V105} adds are left on disk (deploy-hybrid.sh never deletes) and are given\n"
            "# the time as well, so the count then is every file of the delta found on disk, not the ones copied.\n",
            why="stamp_copy comment")
    doc.sub("# 5.18.104's change is gone (below: 5.18.103's files by sha256 where 5.18.104 changed them (45) and the 158 it keeps\n"
            "# by theirs; the 215 files it adds left on disk and reached by nothing, QC-RB1; 089–091's tables left in place, QF-RB2;\n",
            f"# {V105}'s change is gone (below: {V104}'s files by sha256 where {V105} changed them ({n_chg}) and the {ctx['n_kept']} it keeps\n"
            f"# by theirs; the {n_add} files it adds left on disk and reached by nothing, QC-RB1; every ladder key as before, RB105;\n",
            why="RB comment")
    doc.sub("# 5.18.104's files are 5.18.103's again, each byte for byte — by Git's sha256 and by the one THIS SCRIPT pins for each\n"
            "# (PREV_CHANGED_SHA256S, Q-G1's set read again: 45) — and the 215 files it added stay on disk\n"
            "# (deploy-hybrid.sh never deletes, nor can the by-hand put-back). Git's loop below passes over them, since 5.18.103 has\n"
            "# none of them; QC-RB1 (below) proves nothing in 5.18.103 reaches them. The files 5.18.104 adds, as they are on disk now —\n",
            f"# {V105}'s files are {V104}'s again, each byte for byte — by Git's sha256 and by the one THIS SCRIPT pins for each\n"
            f"# (PREV_CHANGED_SHA256S, Q-G1's set read again: {n_chg}) — and the {n_add} files it added stay on disk\n"
            f"# (deploy-hybrid.sh never deletes, nor can the by-hand put-back). Git's loop below passes over them, since {V104} has\n"
            f"# none of them; QC-RB1 (below) proves nothing in {V104} reaches them. The files {V105} adds, as they are on disk now —\n",
            why="RB files comment")
    doc.sub("# 5.18.99's quoting code is in place: the rollback target has it. 5.18.104 changed one of its files that reach uCRM,\n"
            "# lib/QuotationService.php (Part F's tracking and Part P's agent copy), and it is 5.18.103's again by its pinned sha256\n",
            f"# 5.18.99's quoting code is in place: the rollback target has it. {V105} changed one of its files that reach uCRM,\n"
            f"# lib/QuotationService.php (its log lines only, LM), and it is {V104}'s again by its pinned sha256\n",
            why="RB quoting comment")
    # ── the last texts that named the switch tool this run used to run (QF-G4, QF-RB1): V3, QF_PHP's comment, the
    #    summary's "what did not", the header's R7/R13 ──
    for f in ("lib/QuoteOrganization.php", "lib/QuoteTaxLine.php", "lib/TenantProfile.php", "lib/PublicPriceFeed.php",
              "includes/api/api_retailer.php", "retailer/index.html"):
        if f in chg or sha_at(repo, base, f"{plugin}/{f}") != sha_at(repo, pin, f"{plugin}/{f}"):
            raise SystemExit(f"C: {f} is not byte for byte the same at {V104} and the pin")
    for f in ("cron_quote_wa.php", "includes/post/post_kyc.php"):
        labels = [l for (ff, l) in ctx["lm_entries"] if ff == f]
        if f not in chg or not labels or not all(l.startswith("LN-") for l in labels):
            raise SystemExit(f"C: {f}'s hunks are not all LM's masked lines ({labels})")
    doc.sub("— nothing this run does touches distributors_enabled; the sales follow-up's switch tool, where this run calls it (QF-G4's --clear-switches, QF-RB1's off all --rollback), clears only its own keys\"",
            "— nothing this run does touches distributors_enabled, and this run never calls the sales follow-up's switch tool (LK-CA1)\"",
            why="V3 ok")
    doc.sub("# never the plugin folder. Three readings open the live book itself, read-only (SQLITE_OPEN_READONLY, query_only): QF-G4's\n"
            "# keys (the files and the store row), the redeploy baseline (QF-G1) and QF-RB3's keys after a rollback. Nothing here\n"
            "# writes the live book but the switch tool, run as the book's owner where docs/73 says so: QF-G4's --clear-switches\n"
            "# (the PIN's tool, `off all --rollback`, after DEPLOY is typed) and QF-RB1 (the INSTALLED tool, through\n"
            "# scripts/sales-followup.sh, after ROLLBACK is typed). The log carries counts, fixed codes, key names, PASS/FAIL/NOTE and\n",
            "# never the plugin folder. LK's readings open the live book itself, read-only (SQLITE_OPEN_READONLY, query_only): the\n"
            "# ladder's keys (the files and the store row: LK-G1, LK-B1, LK-CA1, LK-AO, LK-RB0, LK-RB1), the baseline (QF-G1) and\n"
            f"# the rows (LK-CA2, LK-RB1). Nothing here writes the live book: {V105} never runs the switch tool (root docs/74 §H.4.3),\n"
            "# so the tool's lines below pass only through its --check and show. The log carries counts, fixed codes, key names, PASS/FAIL/NOTE and\n",
            why="QF_PHP comment")
    doc.sub("(QS-G1, QS-CA1; webhook.php gains only P7's copy, behind its switch); QuoteOrganization.php, QuoteTaxLine.php, TenantProfile.php, PublicPriceFeed.php, cron_quote_wa.php, includes/post/post_kyc.php, includes/api/api_retailer.php and retailer/index.html, $BASELINE_VERSION's byte for byte (Q-G1, Q-CA1)$F_QD9;",
            "(QS-G1, QS-CA1; webhook.php changes only in LM's masked log lines); QuoteOrganization.php, QuoteTaxLine.php, TenantProfile.php, PublicPriceFeed.php, includes/api/api_retailer.php and retailer/index.html, $BASELINE_VERSION's byte for byte (Q-G1, Q-CA1) — cron_quote_wa.php and includes/post/post_kyc.php change only in LM's masked log lines$F_QD9;",
            why="what did not: quoting files")
    doc.sub("the database — nothing written by this run$( [ \"$F_QG1\" = redeploy ] || echo \" but 089, 090 and 091 at the first boot (QF-CA1)\"), and on a redeploy with --clear-switches the keys QF-G4 cleared, by name on the sheet (R7, BA1);",
            "the database — nothing written by this run and no migration added (R7, BA1); every key of the sales follow-up's ladder, in every place, as this run found it (LK-B1, LK-CA1);",
            why="what did not: database")
    doc.sub("which 5.18.104 carries unchanged and adds none to — among it: R7, QC-RB2)",
            f"which {V104} and {V105} carry unchanged and add none to — among it: R7, QC-RB2)", why="what did not: web-direct")
    doc.sub("every switch and AI setting (V3–V3j$( [ -n \"${QF_CLEAR:-}\" ] && echo \", but $EXPECTED_VERSION's own keys QF-G4's --clear-switches removed, each named by V3g\"));",
            "every switch and AI setting (V3–V3j), the sales follow-up's among them (LK-CA1);", why="what did not: switches")
    doc.sub("  # 5.18.105: QF-G1 reads 089–091 as 5.18.104 left them (live) — the only form it accepts — and this run adds none\n"
            "  F_QG1=\"${QF_G1:-}\"; [ -n \"$F_QG1\" ] || [ \"$MODE\" != \"after\" ] || F_QG1=\"$(sed -n 's/^QF_G1=//p' \"$STATE\" 2>/dev/null | tail -n1)\"\n",
            "", why="F_QG1 retired")
    doc.lines_between("#            R13 (R3 with 088 beside 086 and 087: BA1, every row unchanged; R7 the database's ledger as stage A read it or,",
                      "#            event processor at 5.18.104's own Uganda signature, ai.media among its workers' events;",
        "#            R13 (R3 with 088 beside 086 and 087: BA1, every row unchanged; R7 the database's ledger exactly as stage A read\n"
        f"#            it, 091 last — {V105} adds no migration — and its size; R13 the event processor at {V104}'s Uganda\n"
        f"#            signature, which {V105} keeps, ai.media among its workers' events;", why="header R7 R13")
    for old in ("5.18.103 retries and quotes each exactly as 5.18.104", "5.18.103 keeps each on the direct rules exactly as 5.18.104",
                "the code is 5.18.103's again where 5.18.104 changed it", "copies 5.18.103's files", "a record only: 5.18.103, put back",
                "every file 221c9dc tracks", "the installed plugin is live 5.18.103"):
        doc.mustnot(old)

    # ── review D4: the printed kept enumerations of A0, Q-G1 and Q-CA1 (and Q-G1's comment) named two files 5.18.105
    #    changes — includes/post/post_cashbook.php (LN-35) and cron_quote_wa.php (LN-11 … LN-15) — as files it keeps by
    #    5.18.104's sha256. Each fact read off Git first: both changed, both out of the kept list, every hunk of each an LN
    #    masked line; and every file the re-pointed texts still name as kept is in the kept list. ──
    kept_set = set(ctx["kept"])
    for f in ("includes/post/post_cashbook.php", "cron_quote_wa.php"):
        labels = [l for (ff, l) in ctx["lm_entries"] if ff == f]
        if f not in chg or f in kept_set or not labels or not all(l.startswith("LN-") for l in labels):
            raise SystemExit(f"C: {f} is not a changed, unkept file whose hunks are all LM's masked lines ({labels})")
    for f in ("tabs/sales/more_menu.php", "includes/styles.php", "tools/cash_in_hand.php", "tabs/accounts/cashbook_noncash.php",
              "lib/CashbookNonCashView.php", "includes/cashbook_noncash_csv.php", "lib/CashbookService.php",
              "tabs/accounts/cashbook.php", "includes/post_handlers.php", "api/index.php", "lib/QuoteOrganization.php",
              "lib/QuoteTaxLine.php", "lib/TenantProfile.php", "lib/PublicPriceFeed.php"):
        if f not in kept_set or f in chg:
            raise SystemExit(f"C: {f}, named as kept by A0, Q-G1 or Q-CA1, is not in the kept list")
    doc.subs([
        ("the More menu, the styles, the Cashbook's post handler, the cash-in-hand tool, 5.18.98's list split, the owner rule,",
         "the More menu, the styles, the cash-in-hand tool, 5.18.98's list split, the owner rule,"),
        ("webhook.php, evo_webhook.php, cron_quote_wa.php and the other files whose log lines $EXPECTED_VERSION masks are not among them",
         "webhook.php, evo_webhook.php, cron_quote_wa.php, the Cashbook's post handler and the other files whose log lines $EXPECTED_VERSION masks are not among them"),
    ], why="A0 ok: the kept enumeration")
    doc.sub("the More menu, the styles, the Cashbook's post handler and its list split, the cash-in-hand tool, the quoting files $EXPECTED_VERSION keeps (QuoteOrganization, QuoteTaxLine, TenantProfile, PublicPriceFeed and cron_quote_wa.php among them), 5.18.100's owner rule and the three files it reads — carry theirs\"",
            "the More menu, the styles, the Cashbook's list split, the cash-in-hand tool, the quoting files $EXPECTED_VERSION keeps (QuoteOrganization, QuoteTaxLine, TenantProfile and PublicPriceFeed among them), 5.18.100's owner rule and the three files it reads — carry theirs; the Cashbook's post handler and cron_quote_wa.php are not among them: they are among the $G1_C it changes, only in the log lines it masks\"",
            why="Q-G1 ok: the kept enumeration")
    doc.sub("# the More menu, the styles, the Cashbook's post handler and its list split, the cash-in-hand tool, the files the quoting\n",
            "# the More menu, the styles, the Cashbook's list split, the cash-in-hand tool, the files the quoting\n",
            why="Q-G1 comment: the kept enumeration")
    doc.sub("the Cashbook's service, page, tab, export and routes, api/index.php, cron_quote_wa.php, the quoting files $EXPECTED_VERSION keeps (QuoteOrganization, QuoteTaxLine, TenantProfile, PublicPriceFeed), $SNA_LIB, its page and its readers and the rest — still $BASELINE_VERSION's by theirs;",
            "the Cashbook's service, page, tab, export and routes, api/index.php, the quoting files $EXPECTED_VERSION keeps (QuoteOrganization, QuoteTaxLine, TenantProfile, PublicPriceFeed), $SNA_LIB, its page and its readers and the rest — still $BASELINE_VERSION's by theirs (the Cashbook's post handler and cron_quote_wa.php are not among them: $EXPECTED_VERSION changes them only in the log lines it masks, each by the sha256 this script pins, and LM-CA1 counts those lines);",
            why="Q-CA1 ok: the kept enumeration")
    d4_wrong = ("the styles, the Cashbook's post handler", "PublicPriceFeed and cron_quote_wa.php among them",
                "api/index.php, cron_quote_wa.php, the quoting files")   # asserted absent from printed lines below

    # ── review D5: stage-A texts carried from 5.18.104 that call 5.18.103 the live release, or 5.18.104 the release this
    #    run deploys (Q-N7, QL-G1 ×4, QL-N3, QC-G1, QC-N1 ×2, QC-N4, QS-G1, A3) — re-pointed to $BASELINE_VERSION (live)
    #    and $EXPECTED_VERSION (the release). Each fact a new text states is read off Git first: no screen (tabs/) and no
    #    Sync All handler, KYC retry job or Inbox file changes; the catalogue files are byte for byte the same (above);
    #    webhook.php's hunks are LM's lines and its loader only (above), its sign-off and gate lines the same. ──
    if any(f.startswith("tabs/") for f in chg):
        raise SystemExit("C: 5.18.105 changes a screen under tabs/ — Q-N7 and QC-N1 could not say it changes none of this")
    for f in ("includes/post/post_sync.php", "cron/kyc_crm_sync.php", "lib/InboxReplyRoute.php", "tabs/engage/wa_inbox.php"):
        if f in chg or sha_at(repo, base, f"{plugin}/{f}") != sha_at(repo, pin, f"{plugin}/{f}"):
            raise SystemExit(f"C: {f} is not byte for byte the same at {V104} and the pin")
    if [f for f in chg if "inbox" in f.lower() and not f.startswith("tests/")]:
        raise SystemExit("C: 5.18.105 changes an Inbox file — A3 could not say it changes none")
    qs = lambda c: sorted(l for l in show(c, "webhook.php").split("\n") if "ugPlans" in l or "installation & support" in l)
    if not qs(pin) or qs(base) != qs(pin):
        raise SystemExit("C: webhook.php's sign-off and gate lines are not the same at 5.18.104 and the pin")
    doc.sub("the server refuses Sync All under 5.18.103 as after the deploy (since 5.18.102:",
            "the server refuses Sync All under $BASELINE_VERSION as after the deploy (since 5.18.102:", why="Q-N7 live")
    doc.sub("they are out of the menu. 5.18.104 changes none of this\"",
            "they are out of the menu. $EXPECTED_VERSION changes none of this\"", why="Q-N7 release")
    doc.sub("— under 5.18.103 today as after the deploy, which leaves the switch as it is stored —",
            "— under $BASELINE_VERSION today as after the deploy, which leaves the switch as it is stored —", 2,
            why="QL-G1 note and NO-GO")
    doc.sub("only with it off — under 5.18.103 as under 5.18.104, which leaves the switch as it stands",
            "only with it off — under $BASELINE_VERSION as under $EXPECTED_VERSION, which leaves the switch as it stands",
            why="QL-G1 unresolved note")
    doc.sub("which 5.18.104 keeps as 5.18.103 does, frozen while the switch is on",
            "which $EXPECTED_VERSION keeps as $BASELINE_VERSION does, frozen while the switch is on", why="QL-G1 link store")
    doc.sub("After the deploy 5.18.104 retries them exactly as 5.18.103 does:",
            "After the deploy $EXPECTED_VERSION retries them exactly as $BASELINE_VERSION does:", why="QL-N3")
    doc.sub("offer no plan from uCRM now, under 5.18.103$(", "offer no plan from uCRM now, under $BASELINE_VERSION$(", why="QC-G1 live")
    doc.sub("# of what is offered is a note: 5.18.104 changes nothing of the catalogue, and QC-CA1 compares it after the copy.\n",
            f"# of what is offered is a note: {V105} changes nothing of the catalogue, and QC-CA1 compares it after the copy.\n",
            why="QC-G1 comment")
    doc.sub("5.18.104 changes nothing of the catalogue", "$EXPECTED_VERSION changes nothing of the catalogue", 4,
            why="QC-G1, QC-N1")
    doc.sub("and a rollback of 5.18.104 leaves the switch as it is\"", "and a rollback of $EXPECTED_VERSION leaves the switch as it is\"",
            why="QC-N1 on")
    doc.sub("as 5.18.103 does with the switch off; ", "as $BASELINE_VERSION does with the switch off; ", why="QC-N1 off")
    doc.sub("— under $EXPECTED_VERSION, as under 5.18.103: with the switch off",
            "— under $EXPECTED_VERSION, as under $BASELINE_VERSION: with the switch off", why="QC-N4")
    doc.sub("— 5.18.104 changes $QS_FILE only inside whQuotationEmail (P7, the agent's Cc; root docs/73 §H.5.8.2), never the sign-off line or the gate line;",
            "— $EXPECTED_VERSION changes $QS_FILE only in the log lines it masks and their loader (LM; root docs/74 §H.1.11), never the sign-off line or the gate line;",
            why="QS-G1")
    doc.sub("5.18.104 does not change how the Inbox routes or refuses a reply (lib/InboxReplyRoute.php is kept byte for byte); its one Inbox change, on Uganda only, shows staff a voice note's machine transcript, and only once voice notes are switched on at R3 — it shows nothing until then\"",
            "$EXPECTED_VERSION changes no Inbox file, nor how the Inbox routes or refuses a reply (lib/InboxReplyRoute.php is kept byte for byte); $BASELINE_VERSION's one Inbox change, on Uganda only — a voice note's machine transcript shown to staff once voice notes are switched on — stays as its switch says\"",
            why="A3")
    # the recheck's leftovers: RB's master words, and QC-ON's note, both carried from 5.18.104 (MASTER_BASE is 994d660's master, partB)
    doc.sub("$MASTER is $BASELINE_VERSION's — 5.18.92's byte for byte, its own lock, none of the four jobs 5.18.104 adds to it (a rollback's copy puts it back)",
            "$MASTER is $BASELINE_VERSION's byte for byte — 5.18.92's lock and the four jobs 5.18.104 added to it, each dispatched as its switch reads (a rollback's copy puts it back)",
            why="RB master")
    doc.sub("the forms quote through the plugin's links, as 5.18.103 does with it off", "the forms quote through the plugin's links, as $BASELINE_VERSION does with it off",
            why="QC-ON note")
    # no printed line (ok/bad/note/stop/echo/warn) says any of them again, nor names D4's two files as kept
    printed = re.compile(r'\b(ok|bad|note|stop|echo|warn)\s+"')
    wrong = ("under 5.18.103 today", "under 5.18.103 as after the deploy", "5.18.104 changes nothing of the catalogue",
             "under 5.18.103 as under 5.18.104", "5.18.104 keeps as 5.18.103 does", "deploy 5.18.104 retries",
             "as under 5.18.103:", "rollback of 5.18.104", "as 5.18.103 does with the switch off", "5.18.104 changes none of this",
             "from uCRM now, under 5.18.103", "5.18.104 changes $QS_FILE", "5.18.104 does not change how the Inbox", "none of the four jobs 5.18.104 adds to it", "as 5.18.103 does with it off") + d4_wrong
    for n, l in enumerate(doc.t.split("\n"), 1):
        if l.lstrip().startswith("#") or not printed.search(l):
            continue
        for s in wrong:
            if s in l:
                raise SystemExit(f"C: line {n}: a printed line still says {s!r}")
    # ── Sweep 1 (13 Nov, after development trial 1's two defects of the same kind; two readers, each finding checked by a
    #    third): carried lines that still spoke as if 089–091 came with THIS deploy — true when the baseline was 5.18.103,
    #    false now that it is 5.18.104 — in notes every run prints and in branches the trials do not take. Printed words
    #    only, but for OF-RB2's arm, which was a false pass on a damaged book.
    # OF-RB2: "no offer tables" was a harmless end when the rollback removed the release that brought 091. Under 5.18.104,
    # put back, the four tables are its own: any reading but 4/4 is a FAIL.
    doc.sub('''    "tables=0/4 "*) note "OF-RB2 the book holds no offer tables (091 never applied): nothing of Part O stays" ;;
    "tables="*) bad "OF-RB2 the offer tables are not as the rollback must leave them (${o%% offers=*}) — a rollback removes nothing" ;;
''',
            '''    "tables="*) bad "OF-RB2 the offer tables are not as the rollback must leave them (${o%% offers=*}) — a rollback removes nothing, and $BASELINE_VERSION reads all four as Part O's switches say" ;;
''', why="sweep 1: OF-RB2 0/4")
    doc.mustnot("091 never applied")
    # QF-G1's 088 arm: the record, the manifest and every file are 5.18.104's by then (step 0, BR1), so "deploy 5.18.104
    # first" would send the operator to a script that skips its deploy. What is missing is 5.18.104's first boot on this book.
    doc.sub('''with none of 089, 090 and 091 ($g1) — $BASELINE_VERSION is not live: deploy $BASELINE_VERSION first (scripts/deploy-$BASELINE_VERSION.sh). Nothing was changed. Send the log file" ;;''',
            '''with none of 089, 090 and 091 ($g1), although $BASELINE_VERSION is live by its record, its manifest and its files (BR1): its code has not opened this book yet, and its runner applies the three the first time it does. Open the plugin in uCRM once, then run this again. Nothing was changed. Send the log file" ;;''',
            why="sweep 1: QF-G1 088 arm")
    doc.sub('''QF-G1 accepts only 5.18.104's tables as it left them (088 last is "deploy
#            5.18.104 first"); QF-G2,''',
            '''QF-G1 accepts only 5.18.104's tables as it left them (088 last is "open the
#            plugin once"); QF-G2,''', why="sweep 1: header, QF-G1")
    # OF-N1, QF-N4, QF-CA8: notes and an ok line every run prints
    doc.sub("on QF-G2's trial copy (091 applied on that copy alone; read-only, total_changes() ",
            "on QF-G2's trial copy (a copy of this live $BASELINE_VERSION book, 091 on it as $BASELINE_VERSION left it; read-only, total_changes() ",
            why="sweep 1: OF-N1")
    doc.sub("placed by this 088-shaped copy's configuration as it reads now", "placed by this copy's configuration as it reads now", why="sweep 1: QF-N4")
    doc.sub("with wa_send_ledger=on in the copy's configuration alone:",
            "with wa_send_ledger=on in this reading's own configuration of the copy (set in memory, whatever the key reads there):",
            why="sweep 1: QF-CA8")
    # VN-M1, OF-M1, OF-M2: no boot of 5.18.105 applies 090 or 091, and waiting changes nothing
    doc.sub('''    else bad "VN-M1 090 is not on the book as the pin makes it after the copy ($o) — the plugin's runner applies it at its first boot; waited up to 60 s for the ledger row; --after-only judges it again"; fi
''',
            '''    else bad "VN-M1 090 is not on the book as the pin makes it after the copy ($o) — $BASELINE_VERSION's first boot applied it and the deploy's stage A read it applied (QF-G1, VN-G2); $EXPECTED_VERSION adds no migration, so no boot applies it and waiting changes nothing: the book's 090 changed since then, or this reading failed. Send the log file"; fi
''', why="sweep 1: VN-M1")
    doc.sub('''    else bad "OF-M1 091 is not on the book as the pin makes it after the copy ($o) — the plugin's runner applies it at its first boot; waited up to 60 s for the ledger row; --after-only judges it again"; fi
''',
            '''    else bad "OF-M1 091 is not on the book as the pin makes it after the copy ($o) — $BASELINE_VERSION's first boot applied it and the deploy's stage A read it applied (QF-G1, OF-G2); $EXPECTED_VERSION adds no migration, so no boot applies it and waiting changes nothing: the book's 091 changed since then, or this reading failed. Send the log file"; fi
''', why="sweep 1: OF-M1")
    doc.sub("line for 091 (none: trimmed, or the first boot has not run) — OF-M1's _schema_checks row is the verdict",
            "line for 091 (none: trimmed since $BASELINE_VERSION's first boot applied 091 — the runner halves the log past 256 KiB and logs nothing for a migration already in its ledger; $EXPECTED_VERSION adds none) — OF-M1's _schema_checks row is the verdict",
            why="sweep 1: OF-M2")
    for s in ("the plugin's runner applies it at its first boot", "or the first boot has not run", "088-shaped", "applied on that copy alone", "is not live: deploy"):
        for n, l in enumerate(doc.t.split("\n"), 1):
            if not l.lstrip().startswith("#") and printed.search(l) and s in l:
                raise SystemExit(f"C: line {n}: a printed line still says {s!r} (sweep 1)")
    # the header's ladder dates (written on 9 Nov as a plan): what is on is read by LK-G1 at each run, never assumed
    doc.sub('''the steps that are on (1 report and 2 ack since 9 Nov; 3–5 planned for 10 Nov; 6 chase from
#            about 13 Nov) keep running through the deploy,''',
            '''the steps that are on when it runs (on 13 Nov: 1 report, 2 ack and 3 tracking; 4–6 follow
#            in the ladder's order) keep running through the deploy,''', why="header: the ladder's dates")
    # ── two carried comments (found by the change-control entry's checker, 13 Nov): never printed, but they name the
    #    wrong rollback target and a rollback write that 5.18.105 does not make ──
    doc.sub("# V6 — 5.18.73/5.18.74: the sign-in page allows pinch-zoom; 5.18.96 keeps it (and so does the rollback target, 5.18.103).",
            "# V6 — 5.18.73/5.18.74: the sign-in page allows pinch-zoom; 5.18.96 keeps it (and so does the rollback target, 5.18.104).",
            why="comment: V6's rollback target")
    doc.sub("# rollback restores code only — its only writes are QF-RB1's, before the put-back, none in 088's tables — so 088 stays\n",
            "# rollback restores code only — 5.18.105's rollback writes nothing to the book (QF-RB1 is retired) — so 088 stays\n",
            why="comment: RB's 088 words")
