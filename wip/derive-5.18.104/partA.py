# Part A — identity: the pin, the baseline, the chain of earlier state files, the three sha256 lists and the delta's
# counts, and the constants' comments that name a release (root docs/73 §H.2.15: "the pins, counts and sha256 values are
# computed from Git"). Modelled on wip/derive-5.18.103/partA.py; every number here is read off Git (base → pin), never
# typed. The header's title and description are Part H's.
from d104lib import sha_at, delta, git

# Read or declared kept by 5.18.104 and in neither of 5.18.103's lists (docs/73 §H.2.3 "Held byte for byte by
# quote_followup N", §H.2.21 9.5 step 2, and the files the switch tool, the wrappers and master's new dispatch read):
# each must be the same file in both release commits (asserted below, as A0 asserts it at run time).
EXTRA_KEPT = [
    # §H.2.3, Part F's held list (those not already kept by 5.18.103's lists)
    "lib/ConversationService.php", "lib/AutomationPolicy.php", "lib/OwnedNumberHold.php", "lib/FollowUpPolicy.php",
    "lib/ContactOptOut.php", "lib/PhoneNumber.php", "lib/NotifyGate.php", "lib/LineOwner.php", "lib/LeadVisibility.php",
    "lib/InboxReplyRoute.php", "lib/MigrationRunner.php", "lib/JobWindow.php",
    "cron/followup_run.php", "cron/customer_reminders.php", "includes/api/api_followups.php", "tabs/engage/followups.php",
    # what master's new dispatch (StaffJobsGate), the switch tool ($rw: SqliteStore; cliDataDir: bootstrap_data; the
    # clock: UtcClock, timezone) and the report's wrapper (§H.2.21 9.5 step 2) read
    "lib/StaffJobsGate.php", "lib/SqliteStore.php", "lib/bootstrap_data.php", "lib/UtcClock.php", "lib/timezone.php",
]
N_KEPT_103, N_PIN_103 = 97, 59     # 5.18.103's own lists, as its derivation left them (asserted on the source text)
MIGRATIONS_104 = ["migrations/089_quote_followups.sql", "migrations/090_wa_voice.sql", "migrations/091_wa_offers.sql"]


def sums(repo, commit, plugin, files):
    return "\n".join(f"{sha_at(repo, commit, plugin + '/' + f)}  {f}" for f in files)


def block(doc, name):
    """The file list of one SUMS heredoc in the 5.18.103 text, in order."""
    t = doc.t
    head = name + '="$(cat <<\'SUMS\'\n'
    assert t.count(head) == 1, name
    i = t.index(head)
    j = t.index("\nSUMS\n)\"", i)
    body = t[i:j].split("\n")[1:]
    return [ln.split("  ", 1)[1] for ln in body]


def replace_block(doc, name, text):
    t = doc.t
    head = name + '="$(cat <<\'SUMS\'\n'
    assert t.count(head) == 1, name
    i = t.index(head) + len(head)
    j = t.index("\nSUMS\n)\"", i)
    doc.t = t[:i] + text + t[j:]
    # the result holds exactly the lines written
    k = doc.t.index(head) + len(head)
    assert doc.t[k:k + len(text)] == text and doc.t[k + len(text):].startswith("\nSUMS\n)\""), name
    doc.log.append((f"SUMS {name}", 1))


def apply(doc, ctx):
    repo, plugin, base, pin = ctx["repo"], ctx["plugin"], ctx["base_full"], ctx["pin_full"]
    added, changed = delta(repo, base, pin, plugin)
    d104 = set(added) | set(changed)
    kept103 = block(doc, "PREV_KEPT_SHA256S")
    pin103 = block(doc, "PIN_SHA256S")
    assert len(kept103) == N_KEPT_103 and len(pin103) == N_PIN_103, (len(kept103), len(pin103))
    kept = [f for f in kept103 if f not in d104]
    from_103 = [f for f in pin103 if f not in d104 and f not in kept]
    extra = [f for f in EXTRA_KEPT if f not in d104 and f not in kept and f not in from_103]
    assert extra == EXTRA_KEPT, sorted(set(EXTRA_KEPT) - set(extra))
    kept_all = kept + from_103 + extra
    assert len(set(kept_all)) == len(kept_all), "a kept file listed twice"
    # A0's own rule, asserted here first: a kept file is the same file in both release commits
    for f in kept_all:
        a, b = sha_at(repo, base, plugin + "/" + f), sha_at(repo, pin, plugin + "/" + f)
        assert a == b, f"kept file differs between {base[:7]} and the pin: {f}"
    for f in changed:
        sha_at(repo, base, plugin + "/" + f)          # every changed file has a 5.18.103 form
    for f in added:
        try:
            sha_at(repo, base, plugin + "/" + f)
            raise SystemExit(f"an added file exists at {base[:7]}: {f}")
        except SystemExit:
            raise
        except Exception:
            pass
    migs = sorted(f for f in added + changed if f.startswith("migrations/"))
    assert migs == MIGRATIONS_104 and all(m in added for m in migs), migs
    dropped_kept = [f for f in kept103 if f in d104]
    dropped_pin = [f for f in pin103 if f in d104]
    ctx.update(added=added, changed=changed, kept=kept_all, n_kept_103=len(kept), n_from_103=len(from_103),
               n_extra=len(extra), dropped=dropped_kept, dropped_pin=dropped_pin, migrations=migs)
    n_a, n_c, n_k = len(added), len(changed), len(kept_all)
    ctx.update(n_added=n_a, n_changed=n_c, n_kept=n_k, n_pin=n_a + n_c)

    # ── the three lists ──
    replace_block(doc, "PREV_KEPT_SHA256S", sums(repo, base, plugin, kept_all))
    replace_block(doc, "PREV_CHANGED_SHA256S", sums(repo, base, plugin, changed))
    replace_block(doc, "PIN_SHA256S", sums(repo, pin, plugin, sorted(changed) + sorted(added)))
    doc.sub(f"PREV_KEPT_N={N_KEPT_103}\n", f"PREV_KEPT_N={n_k}\n")
    doc.sub("PREV_CHANGED_N=33\n", f"PREV_CHANGED_N={n_c}\n")
    doc.sub(f"PIN_SHA_N={N_PIN_103}\n", f"PIN_SHA_N={n_a + n_c}\n")
    doc.line('EXPECTED_ADDED="', 'EXPECTED_ADDED="' + " ".join(sorted(added)) + '"')
    doc.line('EXPECTED_CHANGED="', 'EXPECTED_CHANGED="' + " ".join(sorted(changed)) + '"')

    # ── the pin and the baseline ──
    doc.line('EXPECTED_PLUGIN_COMMIT="221c9dc"',
             f'EXPECTED_PLUGIN_COMMIT="{pin[:7]}"   # 5.18.104 (release) — on Uganda, the sales follow-up (root docs/73): P1 a read-only report, P2 quote tracking and the day 1/3/7 chase, P3 the morning list and My quotes, P4 voice notes through OpenAI in four rungs, P5 the voice-note acknowledgement, P6 offers on the Evolution numbers, P7 the agent\'s Cc; one send ledger per handset; every switch OFF, reached only through tools/sales_followup.php in one order (C6); migrations 089, 090, 091, each verifying itself; South Sudan as 5.18.103')
    doc.line('EXPECTED_PLUGIN_COMMIT_FULL="221c9dcf04f8281888d77761ae019f59cc1d9c0a"',
             f'EXPECTED_PLUGIN_COMMIT_FULL="{pin}"   # the same commit in full: A0 refuses a short hash that names any other')
    doc.sub('EXPECTED_VERSION="5.18.103"\n', 'EXPECTED_VERSION="5.18.104"\n')
    doc.line('BASELINE_COMMIT="1a0ea5f"',
             f'BASELINE_COMMIT="{base[:7]}"          # 5.18.103 (release, the uCRM catalogue; the live plugin since 4 Nov, root docs/07) — what the server runs first, and the rollback; G3 and V3j require ECO Bank\'s switch ON and a statement kept on it, as 5.18.103\'s did')
    doc.sub('BASELINE_COMMIT_FULL="1a0ea5f103f237db0c7c103c9efd8d38408fbac9"\n', f'BASELINE_COMMIT_FULL="{base}"\n')
    doc.sub('BASELINE_VERSION="5.18.102"\n', 'BASELINE_VERSION="5.18.103"\n')
    doc.sub("R5 checks every file from Release A through 5.18.102 is installed as pinned",
            "R5 checks every file from Release A through 5.18.103 is installed as pinned")
    doc.sub('RELEASE_BRANCH="release/5.18.103"  #', 'RELEASE_BRANCH="release/5.18.104"  #')
    doc.sub('OUT="${DNB_OUT:-/root/dnb-5.18.103}"', 'OUT="${DNB_OUT:-/root/dnb-5.18.104}"')
    doc.sub("a file 5.18.97 through 5.18.103 do not change (R6, R23.6, RB)",
            "a file 5.18.97 through 5.18.104 do not change (R6, R23.6, RB)")
    # 088: 5.18.104 adds three migrations of its own (Part B's A0 and R7), none of which touches a bank table
    doc.sub("5.18.97's copy, and holding since then the statements the operator kept. 5.18.103 adds no migration and changes no bank\n"
            "# file: 088 must be applied and complete before the run and after it,",
            "5.18.97's copy, and holding since then the statements the operator kept. 5.18.104 adds three migrations of its own\n"
            "# (089, 090, 091 — the sales follow-up's tables, none of them a bank table; MIG104 below) and changes no bank\n"
            "# file: 088 must be applied and complete before the run and after it,")
    doc.sub("as 5.18.97 reviewed them and 5.18.98 through 5.18.103 leave them, byte for byte (Q-G1, Q-CA1): in navigation.php and",
            "as 5.18.97 reviewed them and 5.18.98 through 5.18.104 leave them, byte for byte (Q-G1, Q-CA1): in navigation.php and")
    # BK3's nav block: 5.18.104 changes includes/navigation.php (My quotes, the offers screen) outside it — measured
    # equal here, from Git, with BK3's own rule (from "<?php // 5.18.97 " to "<?php endif; ?>")
    nav_b = git(repo, "show", f"{base}:{plugin}/includes/navigation.php").decode()
    nav_p = git(repo, "show", f"{pin}:{plugin}/includes/navigation.php").decode()

    def nav_block(t):
        out, on = [], False
        for ln in t.split("\n"):
            if ln.startswith("<?php // 5.18.97 "):
                on = True
            if on:
                out.append(ln)
                if ln == "<?php endif; ?>":
                    break
        return "\n".join(out)
    assert nav_block(nav_b) and nav_block(nav_b) == nav_block(nav_p), "BK3's navigation block moved in 5.18.104"
    assert nav_p.count('href="?page=dashboard&tab=bank"') == 1 and nav_p.count("BankGate::showLink(") == 1
    doc.sub("column 0 (sha256 of its 9 and 6 lines, from \"<?php // 5.18.97\" to \"<?php endif; ?>\" — 5.18.103 changes navigation.php\n"
            "# outside its block, which it leaves byte for byte); the phone's More menu 5.18.96's",
            "column 0 (sha256 of its 9 and 6 lines, from \"<?php // 5.18.97\" to \"<?php endif; ?>\" — 5.18.103 and 5.18.104 change\n"
            "# navigation.php outside its block, which each leaves byte for byte); the phone's More menu 5.18.96's")

    # ── the kept list's prose ──
    drop = ", ".join(dropped_kept)
    doc.lines_between("# 5.18.103 (Q-G1, Q-CA1, RB) — the files of 5.18.102 this release must leave exactly as they are, each with the sha256",
                      "# copy; RB after a rollback.",
        "# 5.18.104 (Q-G1, Q-CA1, RB) — the files of 5.18.103 this release must leave exactly as they are, each with the sha256\n"
        f"# release/5.18.103 ({base[:7]}) gives it, pinned here so that no other 5.18.103 can pass for it: the {N_KEPT_103} that 5.18.103 kept\n"
        f"# of 5.18.102, but the {len(dropped_kept)} that 5.18.104 changes ({drop}) —\n"
        "# ECO Bank's module, its links, the More menu, the styles, the Cashbook, the quoting files 5.18.99 to 5.18.103 kept\n"
        "# (cron_quote_wa.php, QuoteOrganization, QuoteTaxLine, TenantProfile, QuotePdfSource, UcrmLeadSync, api/index.php,\n"
        "# tools/set_config.php, lib/PublicPriceFeed.php, lib/UcrmQuoteLinks.php among them), 5.18.100's owner rule and the files\n"
        f"# it reads; then 5.18.103's own {len(from_103)} that 5.18.104 does not change — lib/UcrmCatalogue.php and its screen,\n"
        "# UcrmQuoteLines, KycService and KycCrmSync, the Sales tabs 5.18.103 changed, its test and its fixtures; then the\n"
        f"# {len(extra)} that docs/73 §H.2.3 holds byte for byte or the switch tool, master's new dispatch and the wrappers read —\n"
        "# ConversationService, AutomationPolicy, OwnedNumberHold, FollowUpPolicy, ContactOptOut, PhoneNumber, NotifyGate,\n"
        "# LineOwner, LeadVisibility, InboxReplyRoute, MigrationRunner, JobWindow, cron/followup_run.php,\n"
        "# cron/customer_reminders.php, the follow-ups' API and tab, StaffJobsGate, SqliteStore, bootstrap_data, UtcClock and\n"
        "# timezone. A0 finds them in both release commits from Git; Q-G1 installed before anything changes; Q-CA1 installed\n"
        "# after the copy; RB after a rollback.", why="kept prose")
    doc.lines_between("# 5.18.103 (Q-G1, RB) — every file 5.18.103 changes, with the sha256 release/5.18.102 (1a0ea5f) gives it: what the live",
                      "# release commit from Git; the 26 files 5.18.103 adds have no 5.18.102 form (A0 refuses one listed here).",
        f"# 5.18.104 (Q-G1, RB) — every file 5.18.104 changes, with the sha256 release/5.18.103 ({base[:7]}) gives it: what the live\n"
        "# plugin must hold before anything changes (Q-G1), and what a rollback must put back (RB). A0 finds them in 5.18.103's\n"
        f"# release commit from Git; the {n_a} files 5.18.104 adds have no 5.18.103 form (A0 refuses one listed here).", why="changed prose")
    doc.lines_between("# 5.18.103 — the release's own files, each with the sha256 release/5.18.103 (221c9dc) gives it: the delta,",
                      "# exactly, its 26 added files among them — A0 reads them in Git, R1 and Q-CA1 installed.",
        f"# 5.18.104 — the release's own files, each with the sha256 release/5.18.104 ({pin[:7]}) gives it: the delta,\n"
        f"# exactly, its {n_a} added files among them — A0 reads them in Git, R1 and Q-CA1 installed.", why="pin prose")
    doc.lines_between("# The release's own files, and nothing else: 26 added, 33 changed — 59, each by its sha256",
                      "# holds a space or a glob character.",
        f"# The release's own files, and nothing else: {n_a} added, {n_c} changed — {n_a + n_c}, each by its sha256\n"
        "# (PIN_SHA256S). Anything outside this list in the pin is another build. A0 word-splits both lists: no path in them\n"
        "# holds a space or a glob character.", why="delta prose")

    # ── the earlier state files: one more release back, so 5.18.97's record stays read ──
    doc.lines_between("# 5.18.99 — and, since 5.18.102, the releases back to 5.18.97 — since 5.18.103 5.18.101's, 5.18.100's, 5.18.99's,",
                      'PREV6_STATE="${DNB_PREV6_OUT:-${DNB_RECORDS:-/root}/dnb-$PREV6_VERSION}/state-$PREV6_VERSION.env"',
        "# 5.18.99 — and, since 5.18.102, the releases back to 5.18.97 — since 5.18.104 5.18.102's, 5.18.101's, 5.18.100's,\n"
        "# 5.18.99's, 5.18.98's and 5.18.97's, two to seven releases back (their R19 and RB write the same line): a failure any\n"
        "# of them recorded is not forgotten by deploying the releases after it and then this. Read, never written.\n"
        'PREV2_VERSION="5.18.102"\n'
        'PREV2_STATE="${DNB_PREV2_OUT:-${DNB_RECORDS:-/root}/dnb-$PREV2_VERSION}/state-$PREV2_VERSION.env"\n'
        'PREV3_VERSION="5.18.101"\n'
        'PREV3_STATE="${DNB_PREV3_OUT:-${DNB_RECORDS:-/root}/dnb-$PREV3_VERSION}/state-$PREV3_VERSION.env"\n'
        'PREV4_VERSION="5.18.100"\n'
        'PREV4_STATE="${DNB_PREV4_OUT:-${DNB_RECORDS:-/root}/dnb-$PREV4_VERSION}/state-$PREV4_VERSION.env"\n'
        'PREV5_VERSION="5.18.99"\n'
        'PREV5_STATE="${DNB_PREV5_OUT:-${DNB_RECORDS:-/root}/dnb-$PREV5_VERSION}/state-$PREV5_VERSION.env"\n'
        'PREV6_VERSION="5.18.98"\n'
        'PREV6_STATE="${DNB_PREV6_OUT:-${DNB_RECORDS:-/root}/dnb-$PREV6_VERSION}/state-$PREV6_VERSION.env"\n'
        'PREV7_VERSION="5.18.97"\n'
        'PREV7_STATE="${DNB_PREV7_OUT:-${DNB_RECORDS:-/root}/dnb-$PREV7_VERSION}/state-$PREV7_VERSION.env"', why="prev chain")
    # A14: one more release back, in the loop and in the three messages that name the chain
    doc.sub('"$PREV5_STATE" "$PREV5_STATE".prev-* "$PREV6_STATE" "$PREV6_STATE".prev-*; do',
            '"$PREV5_STATE" "$PREV5_STATE".prev-* "$PREV6_STATE" "$PREV6_STATE".prev-* "$PREV7_STATE" "$PREV7_STATE".prev-*; do')
    doc.sub("of $PREV3_VERSION's, of $PREV4_VERSION's, of $PREV5_VERSION's or of $PREV6_VERSION's",
            "of $PREV3_VERSION's, of $PREV4_VERSION's, of $PREV5_VERSION's, of $PREV6_VERSION's or of $PREV7_VERSION's", 3)
    doc.must('"$PREV7_STATE" "$PREV7_STATE".prev-*', 1)

    # ── constants' comments that name the release ──
    doc.subs([
        ("command with its own log: kept by 5.18.100 through 5.18.103, before the deploy, while the release before this one is live.",
         "command with its own log: kept by 5.18.100 through 5.18.104, before the deploy, while the release before this one is live."),
        ("# copied by the backup (5.18.95's rule, kept). 5.18.103 changes nothing of it and reads nothing of it.",
         "# copied by the backup (5.18.95's rule, kept). 5.18.104 changes nothing of it and reads nothing of it."),
        ("# V4: every fatal since the deploy started fails, as in 5.18.95 — none is known in 5.18.98 through 5.18.102.",
         "# V4: every fatal since the deploy started fails, as in 5.18.95 — none is known in 5.18.98 through 5.18.103."),
        ("# 5.18.96 — the two of its tests the server runs under its own PHP, kept by 5.18.97 through 5.18.103 (which change",
         "# 5.18.96 — the two of its tests the server runs under its own PHP, kept by 5.18.97 through 5.18.104 (which change"),
        ("# and tests/catalogue_direct/ — its sections, their weakened copies and the form's browser check — on the same fake\n"
         "# uCRM) too — they build their books, serve the plugin",
         "# and tests/catalogue_direct/ — its sections, their weakened copies and the form's browser check — on the same fake\n"
         "# uCRM) and 5.18.104's (tests/test_quote_followup.php, tests/test_wa_send_budget.php, tests/test_sales_followup_tool.php,\n"
         "# tests/test_master_104.php, tests/test_sales_followup_report.php, Part V's voice tests, tests/test_wa_offers.php and\n"
         "# tests/test_quote_agent_copy.php, with their sections, weakened copies and fakes) too — they build their books, serve the plugin"),
        ("# Until it is pinned here the script refuses to run (below) rather than accept any count. 5.18.103 changes neither T1 nor",
         "# Until it is pinned here the script refuses to run (below) rather than accept any count. 5.18.104 changes neither T1 nor"),
        ("# and StoreInterface outside the release delta, A0), so the count is 5.18.102's. The release suite's two runs of the",
         "# and StoreInterface outside the release delta, A0), so the count is 5.18.103's. The release suite's two runs of the"),
        ("# the tree judged (4), the allowed list against the SPEC (6). 5.18.103 changes six tab pages — under tabs/sales/,\n"
         "# applications, hardware, kyc_form, product_mapping, send_quote and subscription_plans — and adds one,\n"
         "# tabs/sales/ucrm_catalogue.php; the D1 guard sweeps every tab page, those seven included: the count is 5.18.102's value,\n"
         "# carried — re-measured on the release commit by the rehearsal before pinning (docs/72 §G.13).",
         "# the tree judged (4), the allowed list against the SPEC (6). 5.18.104 changes two tab pages — tabs/engage/wa_inbox.php\n"
         "# and tabs/engage/whatsapp.php — and adds two, tabs/engage/wa_offers.php and tabs/sales/my_quotes.php; the D1 guard\n"
         "# sweeps every tab page, those four included: the count is 5.18.103's value, carried — re-measured on the release\n"
         "# commit by the rehearsal before pinning (docs/73 §H.2.15.4)."),
        ("# Sudan; a South Sudan tenant without SSP; a Uganda tenant with SSP), then the Uganda book, the control. 5.18.102's code",
         "# Sudan; a South Sudan tenant without SSP; a Uganda tenant with SSP), then the Uganda book, the control. 5.18.103's code"),
        ("# — a regression since 5.18.96; 5.18.103 changes one of their files, public.php — three lines of its tab tables, not the\n"
         "# repair page — and must leave every one of them in place: the photo",
         "# — a regression since 5.18.96; 5.18.104 changes one of their files, public.php — its tab tables (My quotes, the offers\n"
         "# screen), not the repair page — and must leave every one of them in place: the photo"),
    ], why="constants comments")
    # the signatures carried (5.18.103's values; the rehearsal re-measures each on the release commit, docs/73 §H.2.15.4)
    doc.subs([
        ("# 5.18.102's value, carried — re-measured on the release commit by the rehearsal before pinning (docs/72 §G.13).\nB2_SIGNATURE=",
         "# 5.18.103's value, carried — re-measured on the release commit by the rehearsal before pinning (docs/73 §H.2.15.4).\nB2_SIGNATURE="),
        ("# the same. Both are 5.18.102's values, carried — re-measured on the release commit by the rehearsal before pinning\n# (docs/72 §G.13).",
         "# the same. Both are 5.18.103's values, carried — re-measured on the release commit by the rehearsal before pinning\n"
         "# (docs/73 §H.2.15.4): 5.18.104 changes lib/BrainContext.php and lib/DishNetAiBrain.php (Part V's R3 and R4), each\n"
         "# behind a voice switch that ships off, so the prompts must still be equal."),
        ("# inert: nothing refused, nothing classified, no SQL added, the assistant never stopped. 5.18.102's value, carried —\n"
         "# re-measured on the release commit by the rehearsal before pinning (docs/72 §G.13).",
         "# inert: nothing refused, nothing classified, no SQL added, the assistant never stopped. 5.18.103's value, carried —\n"
         "# re-measured on the release commit by the rehearsal before pinning (docs/73 §H.2.15.4)."),
    ], why="signature comments")
    doc.mustnot("5.18.102's value, carried — re-measured")
