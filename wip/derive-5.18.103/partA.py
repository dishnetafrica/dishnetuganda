# Part A — identity: the pin, the baseline, the chain of earlier state files, the three sha256 lists and the delta's
# counts, and the constants' comments that name a release. (Header prose is Part D's.)
from d103lib import sha_at, delta

EXTRA_KEPT = [  # read or declared kept by 5.18.103 (docs/72 §G.12, §G.4, §G.7), in neither of 5.18.102's lists
    "prices.php", "shop.php",              # §G.12: byte for byte
    "lib/ShopCatalogue.php",               # offered()'s kit/accessory grouping (accessoryNameKeys)
    "lib/CustomerSession.php",             # the catalogue screen's own-page check (crossSite)
    "lib/StockService.php",                # the stock deduction that reads a direct cart's titles (§G.7)
]


def sums(repo, commit, plugin, files):
    return "\n".join(f"{sha_at(repo, commit, plugin + '/' + f)}  {f}" for f in files)


def block(doc, name):
    """The file list of one SUMS heredoc in the 5.18.102 text, in order."""
    t = doc.t
    i = t.index(name + '="$(cat <<\'SUMS\'\n')
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


def apply(doc, ctx):
    repo, plugin, base, pin = ctx["repo"], ctx["plugin"], ctx["base_full"], ctx["pin_full"]
    added, changed = delta(repo, base, pin, plugin)
    d103 = set(added) | set(changed)
    kept102 = block(doc, "PREV_KEPT_SHA256S")
    pin102 = block(doc, "PIN_SHA256S")
    assert len(kept102) == 55 and len(pin102) == 69, (len(kept102), len(pin102))
    kept = [f for f in kept102 if f not in d103]
    from_102 = [f for f in pin102 if f not in d103 and f not in kept]
    extra = [f for f in EXTRA_KEPT if f not in d103 and f not in kept and f not in from_102]
    assert extra == EXTRA_KEPT, extra
    kept_all = kept + from_102 + extra
    # A0's own rule, asserted here first: a kept file is the same file in both release commits
    for f in kept_all:
        a, b = sha_at(repo, base, plugin + "/" + f), sha_at(repo, pin, plugin + "/" + f)
        assert a == b, f"kept file differs between 1a0ea5f and the pin: {f}"
    for f in changed:
        sha_at(repo, base, plugin + "/" + f)          # every changed file has a 5.18.102 form
    for f in added:
        try:
            sha_at(repo, base, plugin + "/" + f)
            raise SystemExit(f"an added file exists at 1a0ea5f: {f}")
        except Exception as e:
            if isinstance(e, SystemExit):
                raise
    ctx.update(added=added, changed=changed, kept=kept_all, n_kept_102=len(kept), n_from_102=len(from_102),
               dropped=[f for f in kept102 if f in d103])
    n_a, n_c, n_k = len(added), len(changed), len(kept_all)
    ctx.update(n_added=n_a, n_changed=n_c, n_kept=n_k, n_pin=n_a + n_c)

    # ── the three lists ──
    replace_block(doc, "PREV_KEPT_SHA256S", sums(repo, base, plugin, kept_all))
    replace_block(doc, "PREV_CHANGED_SHA256S", sums(repo, base, plugin, changed))
    replace_block(doc, "PIN_SHA256S", sums(repo, pin, plugin, sorted(changed) + sorted(added)))
    doc.sub("PREV_KEPT_N=55\n", f"PREV_KEPT_N={n_k}\n")
    doc.sub("PREV_CHANGED_N=38\n", f"PREV_CHANGED_N={n_c}\n")
    doc.sub("PIN_SHA_N=69\n", f"PIN_SHA_N={n_a + n_c}\n")
    doc.line('EXPECTED_ADDED="', 'EXPECTED_ADDED="' + " ".join(sorted(added)) + '"')
    doc.line('EXPECTED_CHANGED="', 'EXPECTED_CHANGED="' + " ".join(sorted(changed)) + '"')

    # ── the pin and the baseline ──
    doc.line('EXPECTED_PLUGIN_COMMIT="1a0ea5f"',
             f'EXPECTED_PLUGIN_COMMIT="{pin[:7]}"   # 5.18.103 (release) — on Uganda, with the uCRM catalogue switched on by an admin (the new uCRM catalogue screen, lib/UcrmCatalogue.php), the KYC form, Send Quotation and the lead quotes offer uCRM\'s own service plans and products by uCRM id, at uCRM\'s prices (UcrmQuoteLines::offered()), and the plugin\'s own plan and kit lists are hidden; a standard quotation in one click; switched off, as the deploy leaves it, 5.18.102 exactly (root docs/72 §G); all behind QuoteOrganization::setting()[\'on\']; no migration')
    doc.line('EXPECTED_PLUGIN_COMMIT_FULL="1a0ea5f103f237db0c7c103c9efd8d38408fbac9"',
             f'EXPECTED_PLUGIN_COMMIT_FULL="{pin}"   # the same commit in full: A0 refuses a short hash that names any other')
    doc.sub('EXPECTED_VERSION="5.18.102"\n', 'EXPECTED_VERSION="5.18.103"\n')
    doc.line('BASELINE_COMMIT="f2055ba"',
             'BASELINE_COMMIT="1a0ea5f"          # 5.18.102 (release, the KYC quotations\' lines from uCRM by link; the live plugin since 1 Nov 07:37 UTC, root docs/07) — what the server runs first, and the rollback; G3 and V3j require ECO Bank\'s switch ON and a statement kept on it, as 5.18.102\'s did')
    doc.sub('BASELINE_COMMIT_FULL="f2055baf97325cd912f70b6c6ed19d2dce5adad5"\n', f'BASELINE_COMMIT_FULL="{base}"\n')
    doc.sub('BASELINE_VERSION="5.18.101"\n', 'BASELINE_VERSION="5.18.102"\n')
    doc.sub("R5 checks every file from Release A through 5.18.101 is installed as pinned",
            "R5 checks every file from Release A through 5.18.102 is installed as pinned")
    doc.sub('RELEASE_BRANCH="release/5.18.102"  #', 'RELEASE_BRANCH="release/5.18.103"  #')
    doc.sub('OUT="${DNB_OUT:-/root/dnb-5.18.102}"', 'OUT="${DNB_OUT:-/root/dnb-5.18.103}"')
    doc.sub("a file 5.18.97 through 5.18.102 do not change (R6, R23.6, RB)",
            "a file 5.18.97 through 5.18.103 do not change (R6, R23.6, RB)")
    doc.sub("5.18.97's copy, and holding since then the statements the operator kept. 5.18.102 adds no migration and changes no bank",
            "5.18.97's copy, and holding since then the statements the operator kept. 5.18.103 adds no migration and changes no bank")
    doc.sub("as 5.18.97 reviewed them and 5.18.98 through 5.18.102 leave them, byte for byte (Q-G1, Q-CA1): in navigation.php and",
            "as 5.18.97 reviewed them and 5.18.98 through 5.18.103 leave them, byte for byte (Q-G1, Q-CA1): in navigation.php and")
    # BK3's nav block: 5.18.103 changes includes/navigation.php (the uCRM catalogue's entry) outside it — measured equal here
    doc.sub("column 0 (sha256 of its 9 and 6 lines, from \"<?php // 5.18.97\" to \"<?php endif; ?>\"); the phone's More menu 5.18.96's",
            "column 0 (sha256 of its 9 and 6 lines, from \"<?php // 5.18.97\" to \"<?php endif; ?>\" — 5.18.103 changes navigation.php\n"
            "# outside its block, which it leaves byte for byte); the phone's More menu 5.18.96's")

    # ── the kept list's prose ──
    drop = ", ".join(ctx["dropped"])
    doc.lines_between("# 5.18.102 (Q-G1, Q-CA1, RB) — the files of 5.18.101 this release must leave exactly as they are",
                      "# Q-G1 installed before anything changes; Q-CA1 installed after the copy; RB after a rollback.",
        "# 5.18.103 (Q-G1, Q-CA1, RB) — the files of 5.18.102 this release must leave exactly as they are, each with the sha256\n"
        "# release/5.18.102 (1a0ea5f) gives it, pinned here so that no other 5.18.102 can pass for it: the 55 that 5.18.102 kept\n"
        f"# of 5.18.101, but the {len(ctx['dropped'])} that 5.18.103 changes ({drop}) —\n"
        "# ECO Bank's module, its links, the More menu, the styles, the Cashbook, the quoting files 5.18.99 to 5.18.101 kept\n"
        "# (webhook.php, cron_quote_wa.php, QuoteOrganization, QuoteTaxLine, TenantProfile, QuotePdfSource, UcrmLeadSync,\n"
        "# api/index.php, tools/set_config.php, lib/PublicPriceFeed.php among them), 5.18.100's owner rule and the files it reads;\n"
        f"# then 5.18.102's own {ctx['n_from_102']} that 5.18.103 does not change — lib/UcrmQuoteLinks.php (the link store,\n"
        "# frozen while the uCRM catalogue is on and read by 5.18.102's applications), CrmApiClient, UcrmClientTarget,\n"
        "# PluginQuotePdf, DeliveryPdfService, NotificationService, the Settings page, 5.18.102's test and its fixtures;\n"
        "# then five that 5.18.103 reads or docs/72 §G.12 declares kept — prices.php and shop.php (the website's lists),\n"
        "# lib/ShopCatalogue.php (offered()'s kit and accessory groups), lib/CustomerSession.php (the catalogue screen's\n"
        "# own-page check) and lib/StockService.php (the stock deduction a direct cart's titles reach, docs/72 §G.7). A0\n"
        "# finds them in both release commits from Git; Q-G1 installed before anything changes; Q-CA1 installed after the\n"
        "# copy; RB after a rollback.", why="kept prose")
    doc.lines_between("# 5.18.102 (Q-G1, RB) — every file 5.18.102 changes, with the sha256 release/5.18.101 (f2055ba) gives it: what the live",
                      "# release commit from Git; the 31 files 5.18.102 adds have no 5.18.101 form (A0 refuses one listed here).",
        "# 5.18.103 (Q-G1, RB) — every file 5.18.103 changes, with the sha256 release/5.18.102 (1a0ea5f) gives it: what the live\n"
        "# plugin must hold before anything changes (Q-G1), and what a rollback must put back (RB). A0 finds them in 5.18.102's\n"
        f"# release commit from Git; the {n_a} files 5.18.103 adds have no 5.18.102 form (A0 refuses one listed here).", why="changed prose")
    doc.lines_between("# 5.18.102 — the release's own files, each with the sha256 release/5.18.102 (1a0ea5f) gives it: the delta,",
                      "# exactly, its 31 added files among them — A0 reads them in Git, R1 and Q-CA1 installed.",
        f"# 5.18.103 — the release's own files, each with the sha256 release/5.18.103 ({pin[:7]}) gives it: the delta,\n"
        f"# exactly, its {n_a} added files among them — A0 reads them in Git, R1 and Q-CA1 installed.", why="pin prose")
    doc.lines_between("# The release's own files, and nothing else: thirty-one added, thirty-eight changed — sixty-nine, each by its sha256",
                      "# holds a space or a glob character.",
        f"# The release's own files, and nothing else: {n_a} added, {n_c} changed — {n_a + n_c}, each by its sha256\n"
        "# (PIN_SHA256S). Anything outside this list in the pin is another build. A0 word-splits both lists: no path in them\n"
        "# holds a space or a glob character.", why="delta prose")

    # ── the earlier state files: one more release back, so 5.18.97's record stays read ──
    doc.lines_between("# 5.18.99 — and, since 5.18.102, 5.18.100's, 5.18.99's, 5.18.98's and 5.18.97's, two, three, four and five releases back (their R19 and RB",
                      'PREV5_STATE="${DNB_PREV5_OUT:-${DNB_RECORDS:-/root}/dnb-$PREV5_VERSION}/state-$PREV5_VERSION.env"',
        "# 5.18.99 — and, since 5.18.102, the releases back to 5.18.97 — since 5.18.103 5.18.101's, 5.18.100's, 5.18.99's,\n"
        "# 5.18.98's and 5.18.97's, two to six releases back (their R19 and RB write the same line): a failure any of them\n"
        "# recorded is not forgotten by deploying the releases after it and then this. Read, never written.\n"
        'PREV2_VERSION="5.18.101"\n'
        'PREV2_STATE="${DNB_PREV2_OUT:-${DNB_RECORDS:-/root}/dnb-$PREV2_VERSION}/state-$PREV2_VERSION.env"\n'
        'PREV3_VERSION="5.18.100"\n'
        'PREV3_STATE="${DNB_PREV3_OUT:-${DNB_RECORDS:-/root}/dnb-$PREV3_VERSION}/state-$PREV3_VERSION.env"\n'
        'PREV4_VERSION="5.18.99"\n'
        'PREV4_STATE="${DNB_PREV4_OUT:-${DNB_RECORDS:-/root}/dnb-$PREV4_VERSION}/state-$PREV4_VERSION.env"\n'
        'PREV5_VERSION="5.18.98"\n'
        'PREV5_STATE="${DNB_PREV5_OUT:-${DNB_RECORDS:-/root}/dnb-$PREV5_VERSION}/state-$PREV5_VERSION.env"\n'
        'PREV6_VERSION="5.18.97"\n'
        'PREV6_STATE="${DNB_PREV6_OUT:-${DNB_RECORDS:-/root}/dnb-$PREV6_VERSION}/state-$PREV6_VERSION.env"', why="prev chain")

    # ── constants' comments ──
    doc.subs([
        ("command with its own log: kept by 5.18.100 through 5.18.102, before the deploy, while the release before this one is live.",
         "command with its own log: kept by 5.18.100 through 5.18.103, before the deploy, while the release before this one is live."),
        ("# copied by the backup (5.18.95's rule, kept). 5.18.102 changes nothing of it and reads nothing of it.",
         "# copied by the backup (5.18.95's rule, kept). 5.18.103 changes nothing of it and reads nothing of it."),
        ("# V4: every fatal since the deploy started fails, as in 5.18.95 — none is known in 5.18.98 through 5.18.101.",
         "# V4: every fatal since the deploy started fails, as in 5.18.95 — none is known in 5.18.98 through 5.18.102."),
        ("# The copy's time is the time stamp_copy gives a file this release changes: lib/KycService.php (5.18.101 read it off\n"
         "# webhook.php, which 5.18.102 does not change: its time there would be 5.18.101's copy, not this one).",
         "# The copy's time is the time stamp_copy gives a file this release changes: lib/KycService.php, as 5.18.102 read it —\n"
         "# 5.18.103 changes it too."),
    ], why="constants comments")

    # ── the event processor, the signatures and the tests' comments ──
    doc.subs([
        ("# 5.18.102 changes lib/NotificationService.php, which the event processor loads and constructs: both signatures are\n"
         "# 5.18.101's values, carried — re-measured on the release commit by the rehearsal (A6, R13) before pinning (docs/71 §Q).",
         "# 5.18.103 changes none of the files the event processor loads (EventBus, the Splynx classes, NotificationService):\n"
         "# both signatures are 5.18.102's values, carried — re-measured on the release commit by the rehearsal (A6, R13) before\n"
         "# pinning (docs/72 §G.13)."),
        ("# 5.18.101's value, carried — re-measured on the release commit by the rehearsal before pinning (docs/71 §Q).\nB2_SIGNATURE=",
         "# 5.18.102's value, carried — re-measured on the release commit by the rehearsal before pinning (docs/72 §G.13).\nB2_SIGNATURE="),
        ("# the same. Both are 5.18.101's values, carried — re-measured on the release commit by the rehearsal before pinning\n# (docs/71 §Q).",
         "# the same. Both are 5.18.102's values, carried — re-measured on the release commit by the rehearsal before pinning\n# (docs/72 §G.13)."),
        ("# inert: nothing refused, nothing classified, no SQL added, the assistant never stopped. 5.18.101's value, carried —\n"
         "# re-measured on the release commit by the rehearsal before pinning (docs/71 §Q).",
         "# inert: nothing refused, nothing classified, no SQL added, the assistant never stopped. 5.18.102's value, carried —\n"
         "# re-measured on the release commit by the rehearsal before pinning (docs/72 §G.13)."),
        ("# 5.18.96 — the two of its tests the server runs under its own PHP, kept by 5.18.97 through 5.18.102 (which change",
         "# 5.18.96 — the two of its tests the server runs under its own PHP, kept by 5.18.97 through 5.18.103 (which change"),
        ("# browser check — with its fake uCRM, tests/fixtures/fake_ucrm_kyc.php) too — they build their books, serve the plugin",
         "# browser check — with its fake uCRM, tests/fixtures/fake_ucrm_kyc.php) and 5.18.103's (tests/test_catalogue_direct.php\n"
         "# and tests/catalogue_direct/ — its sections, their weakened copies and the form's browser check — on the same fake\n"
         "# uCRM) too — they build their books, serve the plugin"),
        ("# Until it is pinned here the script refuses to run (below) rather than accept any count. 5.18.102 changes neither T1 nor",
         "# Until it is pinned here the script refuses to run (below) rather than accept any count. 5.18.103 changes neither T1 nor"),
        ("# and StoreInterface outside the release delta, A0), so the count is 5.18.101's. The release suite's two runs of the",
         "# and StoreInterface outside the release delta, A0), so the count is 5.18.102's. The release suite's two runs of the"),
        ("# the tree judged (4), the allowed list against the SPEC (6). 5.18.102 changes seven tab pages — tabs/admin/settings.php\n"
         "# and, under tabs/sales/, applications, hardware, kyc_form, product_mapping, send_quote and subscription_plans — and the\n"
         "# D1 guard sweeps every tab page, those seven included: the count is 5.18.101's value, carried — re-measured on the\n"
         "# release commit by the rehearsal before pinning (docs/71 §Q).",
         "# the tree judged (4), the allowed list against the SPEC (6). 5.18.103 changes six tab pages — under tabs/sales/,\n"
         "# applications, hardware, kyc_form, product_mapping, send_quote and subscription_plans — and adds one,\n"
         "# tabs/sales/ucrm_catalogue.php; the D1 guard sweeps every tab page, those seven included: the count is 5.18.102's value,\n"
         "# carried — re-measured on the release commit by the rehearsal before pinning (docs/72 §G.13)."),
        ("# under D2 = A (moves=yes), so its equality with today's SQL is not vacuous.",
         "# under D2 = A (moves=yes), so its equality with today's SQL is not vacuous.", 1),
    ], why="signature and test comments")
    doc.sub("# Sudan; a South Sudan tenant without SSP; a Uganda tenant with SSP), then the Uganda book, the control. 5.18.101's code",
            "# Sudan; a South Sudan tenant without SSP; a Uganda tenant with SSP), then the Uganda book, the control. 5.18.102's code")
    doc.subs([
        ("# — a regression since 5.18.96; 5.18.102 changes none of their files and must leave every one of them in place: the photo",
         "# — a regression since 5.18.96; 5.18.103 changes one of their files, public.php — three lines of its tab tables, not the\n"
         "# repair page — and must leave every one of them in place: the photo"),
        ("# 5.18.96; 5.18.102 changes none of the files they live in and must keep every one. The server runs none of their tests: they serve the plugin over",
         "# 5.18.96; 5.18.103 changes one of the files they live in, public.php (its tab tables, not the repair page), and must keep every\n"
         "# one. The server runs none of their tests: they serve the plugin over"),
    ], why="5.18.95 fixes")
    # ── A14: one more release back ──
    doc.sub('"$PREV4_STATE" "$PREV4_STATE".prev-* "$PREV5_STATE" "$PREV5_STATE".prev-*; do',
            '"$PREV4_STATE" "$PREV4_STATE".prev-* "$PREV5_STATE" "$PREV5_STATE".prev-* "$PREV6_STATE" "$PREV6_STATE".prev-*; do')
    doc.sub("of $PREV3_VERSION's, of $PREV4_VERSION's or of $PREV5_VERSION's",
            "of $PREV3_VERSION's, of $PREV4_VERSION's, of $PREV5_VERSION's or of $PREV6_VERSION's", 3)
    # ── RB: the event processor after a rollback ──
    doc.sub("# lib/NotificationService.php, which 5.18.102 changes and the rollback puts back (RB_SHA below), so it must read\n"
            "# 5.18.101's signature again: UG_SIGNATURE and SS_SIGNATURE are 5.18.101's values, carried (docs/71 §Q, D-R14).",
            "# lib/NotificationService.php, which 5.18.103 does not change, so it must read 5.18.102's signature again:\n"
            "# UG_SIGNATURE and SS_SIGNATURE are 5.18.102's values, carried (docs/72 §G.13).")
