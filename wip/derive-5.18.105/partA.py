# Part A — identity (root docs/74 §H.4.2): the pin, the baseline (221c9dc → 994d660), the chain of earlier state files
# (PREV_STATE moves by itself; PREV2 = 5.18.103 … PREV7 = 5.18.98, and PREV8 = 5.18.97 added so that 5.18.97's
# STRAY_Q_FAILED record is not dropped), the three sha256 lists and the delta's counts, and the constants' comments that
# name a release. Modelled on wip/derive-5.18.104/partA.py; every list, count and sha256 is read off Git (994d660 → the
# pin), never typed. The header's title and description are Part H's.
#   PREV_KEPT_SHA256S   (5.18.104's kept ∪ its pinned) − 5.18.105's delta, at 994d660's sha, asserted identical at the
#                       pin: the ladder trio, cron/master.php, cron/event_processor.php, lib/NotificationService.php,
#                       089–091 and lib/QuoteDailyList.php come under sha (they were only in PIN_SHA256S); the files
#                       5.18.105 changes leave it (measured: cron_quote_wa.php, lib/PluginLog.php,
#                       includes/post/post_kyc.php and includes/post/post_cashbook.php of the runtime files, and the
#                       tests the r105 layer re-points)
#   PREV_CHANGED_SHA256S exactly 5.18.105's changed files, at 994d660's sha (Q-G1 reads them as live 5.18.104; RB back)
#   PIN_SHA256S         5.18.105's delta at the pin (the added files are tests only — asserted)
from d105lib import sha_at, delta, git

N_KEPT_104, N_PIN_104, N_CHANGED_104 = 158, 260, 45     # 5.18.104's own lists, as its derivation left them (asserted)
# what §H.4.2 names as coming under sha in PREV_KEPT (each must be there, at 994d660's sha, identical at the pin)
UNDER_SHA = ["tools/sales_followup.php", "lib/SalesFollowupLadder.php", "lib/SalesFollowupChecks.php", "cron/master.php",
             "cron/event_processor.php", "lib/NotificationService.php", "migrations/089_quote_followups.sql",
             "migrations/090_wa_voice.sql", "migrations/091_wa_offers.sql", "lib/QuoteDailyList.php"]
# the four runtime files §H.4.2 names as leaving the kept list because 5.18.105 changes them (measured against both lists)
LEAVE_RUNTIME = ["cron_quote_wa.php", "lib/PluginLog.php", "includes/post/post_kyc.php", "includes/post/post_cashbook.php"]
# R105_HELD (root docs/74 §H.3.2): the files 5.18.105 must leave byte for byte — each must be kept here
HELD = ["tools/sales_followup.php", "lib/SalesFollowupLadder.php", "lib/SalesFollowupChecks.php", "lib/PhoneNumber.php",
        "lib/TenantProfile.php", "lib/PluginConfig.php", "lib/ConfigVault.php", "lib/ContactOptOut.php", "cron/master.php",
        "cron/event_processor.php", "lib/NotificationService.php", "lib/AlertService.php", "workers/StarlinkMailWorker.php"]


def sums(repo, commit, plugin, files):
    return "\n".join(f"{sha_at(repo, commit, plugin + '/' + f)}  {f}" for f in files)


def block(doc, name):
    """The file list of one SUMS heredoc in the 5.18.104 text, in order."""
    t = doc.t
    head = name + '="$(cat <<\'SUMS\'\n'
    if t.count(head) != 1:
        raise SystemExit(f"PART A: {name}'s heredoc found {t.count(head)} times")
    i = t.index(head)
    j = t.index("\nSUMS\n)\"", i)
    body = t[i:j].split("\n")[1:]
    return [ln.split("  ", 1)[1] for ln in body]


def replace_block(doc, name, text):
    t = doc.t
    head = name + '="$(cat <<\'SUMS\'\n'
    if t.count(head) != 1:
        raise SystemExit(f"PART A: {name}'s heredoc found {t.count(head)} times")
    i = t.index(head) + len(head)
    j = t.index("\nSUMS\n)\"", i)
    doc.t = t[:i] + text + t[j:]
    k = doc.t.index(head) + len(head)
    if not (doc.t[k:k + len(text)] == text and doc.t[k + len(text):].startswith("\nSUMS\n)\"")):
        raise SystemExit(f"PART A: {name}'s heredoc does not hold exactly the lines written")
    doc.log.append((f"SUMS {name}", 1))


def apply(doc, ctx):
    repo, plugin, base, pin = ctx["repo"], ctx["plugin"], ctx["base_full"], ctx["pin_full"]
    added, changed = delta(repo, base, pin, plugin)
    d105 = set(added) | set(changed)
    if not added or not changed:
        raise SystemExit("PART A: Git reports an empty side of the delta")
    if any(not f.startswith("tests/") for f in added):
        raise SystemExit(f"PART A: 5.18.105 adds a file outside tests/: {[f for f in added if not f.startswith('tests/')]}")
    if any(f.startswith("migrations/") for f in d105):
        raise SystemExit("PART A: 5.18.105 carries a migration (root docs/74 §H.3.1: no migration)")
    kept104 = block(doc, "PREV_KEPT_SHA256S")
    pin104 = block(doc, "PIN_SHA256S")
    chg104 = block(doc, "PREV_CHANGED_SHA256S")
    if (len(kept104), len(pin104), len(chg104)) != (N_KEPT_104, N_PIN_104, N_CHANGED_104):
        raise SystemExit(f"PART A: 5.18.104's lists are {len(kept104)}/{len(pin104)}/{len(chg104)}, not {N_KEPT_104}/{N_PIN_104}/{N_CHANGED_104}")
    kept = [f for f in kept104 if f not in d105]
    from_pin = [f for f in pin104 if f not in d105 and f not in kept]
    kept_all = kept + from_pin
    if len(set(kept_all)) != len(kept_all):
        raise SystemExit("PART A: a kept file listed twice")
    for f in UNDER_SHA + HELD:
        if f not in kept_all:
            raise SystemExit(f"PART A: {f} is not in the new kept list (root docs/74 §H.4.2, R105_HELD)")
    left = [f for f in kept104 if f in d105]
    if [f for f in left if not f.startswith("tests/")] != [f for f in kept104 if f in LEAVE_RUNTIME] or \
            sorted(f for f in left if not f.startswith("tests/")) != sorted(LEAVE_RUNTIME):
        raise SystemExit(f"PART A: the runtime files leaving the kept list are {[f for f in left if not f.startswith('tests/')]}, not §H.4.2's four")
    # A0's own rule, asserted here first: a kept file is the same file in both release commits
    for f in kept_all:
        a, b = sha_at(repo, base, plugin + "/" + f), sha_at(repo, pin, plugin + "/" + f)
        if a != b:
            raise SystemExit(f"PART A: kept file differs between {base[:7]} and the pin: {f}")
    for f in changed:
        sha_at(repo, base, plugin + "/" + f)          # every changed file has a 5.18.104 form
    for f in added:
        r = __import__("subprocess").run(["git", "-C", repo, "cat-file", "-e", f"{base}:{plugin}/{f}"], capture_output=True)
        if r.returncode == 0:
            raise SystemExit(f"PART A: an added file exists at {base[:7]}: {f}")
    n_a, n_c, n_k = len(added), len(changed), len(kept_all)
    runtime_changed = [f for f in changed if not f.startswith("tests/") and f not in ("SAFETY.md", "manifest.json")]
    ctx.update(added=added, changed=changed, kept=kept_all, n_added=n_a, n_changed=n_c, n_kept=n_k, n_pin=n_a + n_c,
               left_kept=left, n_from_pin=len(from_pin), runtime_changed=runtime_changed)

    # ── the three lists ──
    replace_block(doc, "PREV_KEPT_SHA256S", sums(repo, base, plugin, kept_all))
    replace_block(doc, "PREV_CHANGED_SHA256S", sums(repo, base, plugin, changed))
    replace_block(doc, "PIN_SHA256S", sums(repo, pin, plugin, sorted(changed) + sorted(added)))
    doc.sub(f"PREV_KEPT_N={N_KEPT_104}\n", f"PREV_KEPT_N={n_k}\n")
    doc.sub(f"PREV_CHANGED_N={N_CHANGED_104}\n", f"PREV_CHANGED_N={n_c}\n")
    doc.sub(f"PIN_SHA_N={N_PIN_104}\n", f"PIN_SHA_N={n_a + n_c}\n")
    doc.line('EXPECTED_ADDED="', 'EXPECTED_ADDED="' + " ".join(sorted(added)) + '"')
    doc.line('EXPECTED_CHANGED="', 'EXPECTED_CHANGED="' + " ".join(sorted(changed)) + '"')

    # ── the pin and the baseline ──
    doc.line('EXPECTED_PLUGIN_COMMIT="994d660"',
             f'EXPECTED_PLUGIN_COMMIT="{pin[:7]}"   # 5.18.105 (release) — on Uganda, no phone number in any log line (root docs/74): {len(runtime_changed)} runtime files, the log statements that could carry a number masked, PluginLog::tail() and the report\'s column fix; no migration, no new setting; the deploy and its rollback keep every switch of the sales follow-up exactly as found')
    doc.line('EXPECTED_PLUGIN_COMMIT_FULL="994d660628cc5b1b42bee648b44b58e389f44bf2"',
             f'EXPECTED_PLUGIN_COMMIT_FULL="{pin}"   # the same commit in full: A0 refuses a short hash that names any other')
    doc.sub('EXPECTED_VERSION="5.18.104"\n', 'EXPECTED_VERSION="5.18.105"\n')
    doc.line('BASELINE_COMMIT="221c9dc"',
             f'BASELINE_COMMIT="{base[:7]}"          # 5.18.104 (release, the sales follow-up; the live plugin since the copy of 9 Nov 03:50:25 UTC, its steps 1 (report) and 2 (ack) on from 04:26:06 UTC — root docs/07) — what the server runs first, and the rollback (every switch kept); G3 and V3j require ECO Bank\'s switch ON and a statement kept on it, as 5.18.104\'s did')
    doc.sub('BASELINE_COMMIT_FULL="221c9dcf04f8281888d77761ae019f59cc1d9c0a"\n', f'BASELINE_COMMIT_FULL="{base}"\n')
    doc.sub('BASELINE_VERSION="5.18.103"\n', 'BASELINE_VERSION="5.18.104"\n')
    doc.sub("R5 checks every file from Release A through 5.18.103 is installed as pinned",
            "R5 checks every file from Release A through 5.18.104 is installed as pinned")
    doc.sub('RELEASE_BRANCH="release/5.18.104"  #', 'RELEASE_BRANCH="release/5.18.105"  #')
    doc.sub('OUT="${DNB_OUT:-/root/dnb-5.18.104}"', 'OUT="${DNB_OUT:-/root/dnb-5.18.105}"')
    doc.sub("a file 5.18.97 through 5.18.104 do not change (R6, R23.6, RB)",
            "a file 5.18.97 through 5.18.105 do not change (R6, R23.6, RB)")
    doc.sub("as 5.18.97 reviewed them and 5.18.98 through 5.18.104 leave them, byte for byte (Q-G1, Q-CA1): in navigation.php and",
            "as 5.18.97 reviewed them and 5.18.98 through 5.18.105 leave them, byte for byte (Q-G1, Q-CA1): in navigation.php and")
    # BK3's nav block and the CEO page: 5.18.105 changes neither file (asserted from Git), so the block is the same
    for f in ("includes/navigation.php", "tabs/admin/ceo_dashboard.php", "tabs/sales/more_menu.php", "public.php"):
        if f in d105:
            raise SystemExit(f"PART A: 5.18.105 changes {f} — BK3's or A22's carried words would be wrong")

    # ── the kept list's prose ──
    doc.lines_between("# 5.18.104 (Q-G1, Q-CA1, RB) — the files of 5.18.103 this release must leave exactly as they are, each with the sha256",
                      "# after the copy; RB after a rollback.",
        "# 5.18.105 (Q-G1, Q-CA1, RB) — the files of 5.18.104 this release must leave exactly as they are, each with the sha256\n"
        f"# release/5.18.104 ({base[:7]}) gives it, pinned here so that no other 5.18.104 can pass for it (root docs/74 §H.4.2):\n"
        f"# the {len(kept)} of 5.18.104's own kept list that 5.18.105 does not change — all but the {len(left)} it does\n"
        f"# ({', '.join(left)}) —\n"
        f"# then the {len(from_pin)} of 5.18.104's own release files that 5.18.105 does not change: the ladder trio\n"
        "# (tools/sales_followup.php, lib/SalesFollowupLadder.php, lib/SalesFollowupChecks.php), cron/master.php,\n"
        "# cron/event_processor.php, lib/NotificationService.php, migrations 089–091 and lib/QuoteDailyList.php among them, so\n"
        "# that what 5.18.104 put live is held by its sha256 and not only by the delta's. A0 finds them in both release\n"
        "# commits from Git; Q-G1 installed before anything changes; Q-CA1 installed after the copy; RB after a rollback.",
        why="kept prose")
    doc.must("RB after a rollback.\nPREV_KEPT_SHA256S=\"$(cat <<'SUMS'\n", 1, why="kept prose ends on the list")
    doc.lines_between("# 5.18.104 (Q-G1, RB) — every file 5.18.104 changes, with the sha256 release/5.18.103 (221c9dc) gives it: what the live",
                      "# release commit from Git; the 215 files 5.18.104 adds have no 5.18.103 form (A0 refuses one listed here).",
        f"# 5.18.105 (Q-G1, RB) — every file 5.18.105 changes, with the sha256 release/5.18.104 ({base[:7]}) gives it: what the live\n"
        "# plugin must hold before anything changes (Q-G1: live 5.18.104), and what a rollback must put back (RB). A0 finds them in\n"
        f"# 5.18.104's release commit from Git; the {n_a} files 5.18.105 adds (tests only) have no 5.18.104 form (A0 refuses one\n"
        "# listed here).", why="changed prose")
    doc.lines_between("# 5.18.104 — the release's own files, each with the sha256 release/5.18.104 (994d660) gives it: the delta,",
                      "# exactly, its 215 added files among them — A0 reads them in Git, R1 and Q-CA1 installed.",
        f"# 5.18.105 — the release's own files, each with the sha256 the release commit ({pin[:7]}) gives it: the delta,\n"
        f"# exactly, its {n_a} added files (tests only) among them — A0 reads them in Git, R1 and Q-CA1 installed.", why="pin prose")
    doc.lines_between("# The release's own files, and nothing else: 215 added, 45 changed — 260, each by its sha256",
                      "# holds a space or a glob character.",
        f"# The release's own files, and nothing else: {n_a} added, {n_c} changed — {n_a + n_c}, each by its sha256\n"
        "# (PIN_SHA256S). Anything outside this list in the pin is another build. A0 word-splits both lists: no path in them\n"
        "# holds a space or a glob character.", why="delta prose")
    for f in kept_all + changed + added:
        if any(ch in f for ch in " *?[]"):
            raise SystemExit(f"PART A: a path A0 would word-split wrongly: {f!r}")

    # ── the earlier state files: PREV_STATE is $BASELINE_VERSION's by itself; one more release back ──
    doc.must('PREV_STATE="${DNB_PREV_OUT:-${DNB_RECORDS:-/root}/dnb-$BASELINE_VERSION}/state-$BASELINE_VERSION.env"\n', 1)
    doc.lines_between("# 5.18.99 — and, since 5.18.102, the releases back to 5.18.97 — since 5.18.104 5.18.102's, 5.18.101's, 5.18.100's,",
                      'PREV7_STATE="${DNB_PREV7_OUT:-${DNB_RECORDS:-/root}/dnb-$PREV7_VERSION}/state-$PREV7_VERSION.env"',
        "# 5.18.99 — and, since 5.18.102, the releases back to 5.18.97 — since 5.18.105 5.18.103's, 5.18.102's, 5.18.101's,\n"
        "# 5.18.100's, 5.18.99's, 5.18.98's and 5.18.97's, two to eight releases back (their R19 and RB write the same line;\n"
        "# PREV8 keeps 5.18.97's STRAY_Q_FAILED record in reach — root docs/74 §H.4.2): a failure any of them recorded is not\n"
        "# forgotten by deploying the releases after it and then this. Read, never written. PREV_STATE, above, is 5.18.104's\n"
        "# (production's COPY_AT among its lines).\n"
        'PREV2_VERSION="5.18.103"\n'
        'PREV2_STATE="${DNB_PREV2_OUT:-${DNB_RECORDS:-/root}/dnb-$PREV2_VERSION}/state-$PREV2_VERSION.env"\n'
        'PREV3_VERSION="5.18.102"\n'
        'PREV3_STATE="${DNB_PREV3_OUT:-${DNB_RECORDS:-/root}/dnb-$PREV3_VERSION}/state-$PREV3_VERSION.env"\n'
        'PREV4_VERSION="5.18.101"\n'
        'PREV4_STATE="${DNB_PREV4_OUT:-${DNB_RECORDS:-/root}/dnb-$PREV4_VERSION}/state-$PREV4_VERSION.env"\n'
        'PREV5_VERSION="5.18.100"\n'
        'PREV5_STATE="${DNB_PREV5_OUT:-${DNB_RECORDS:-/root}/dnb-$PREV5_VERSION}/state-$PREV5_VERSION.env"\n'
        'PREV6_VERSION="5.18.99"\n'
        'PREV6_STATE="${DNB_PREV6_OUT:-${DNB_RECORDS:-/root}/dnb-$PREV6_VERSION}/state-$PREV6_VERSION.env"\n'
        'PREV7_VERSION="5.18.98"\n'
        'PREV7_STATE="${DNB_PREV7_OUT:-${DNB_RECORDS:-/root}/dnb-$PREV7_VERSION}/state-$PREV7_VERSION.env"\n'
        'PREV8_VERSION="5.18.97"\n'
        'PREV8_STATE="${DNB_PREV8_OUT:-${DNB_RECORDS:-/root}/dnb-$PREV8_VERSION}/state-$PREV8_VERSION.env"', why="prev chain")
    # A14: one more release back, in the loop and in the three messages that name the chain
    doc.sub('"$PREV6_STATE" "$PREV6_STATE".prev-* "$PREV7_STATE" "$PREV7_STATE".prev-*; do',
            '"$PREV6_STATE" "$PREV6_STATE".prev-* "$PREV7_STATE" "$PREV7_STATE".prev-* "$PREV8_STATE" "$PREV8_STATE".prev-*; do')
    doc.sub("of $PREV4_VERSION's, of $PREV5_VERSION's, of $PREV6_VERSION's or of $PREV7_VERSION's",
            "of $PREV4_VERSION's, of $PREV5_VERSION's, of $PREV6_VERSION's, of $PREV7_VERSION's or of $PREV8_VERSION's", 3)
    doc.must('"$PREV8_STATE" "$PREV8_STATE".prev-*', 1)
    doc.must('PREV8_VERSION="5.18.97"\n', 1)
    doc.mustnot('PREV7_VERSION="5.18.97"')

    # ── constants' comments that name the release ──
    doc.subs([
        ("command with its own log: kept by 5.18.100 through 5.18.104, before the deploy, while the release before this one is live.",
         "command with its own log: kept by 5.18.100 through 5.18.105, before the deploy, while the release before this one is live."),
        ("# copied by the backup (5.18.95's rule, kept). 5.18.104 changes nothing of it and reads nothing of it.",
         "# copied by the backup (5.18.95's rule, kept). 5.18.105 changes nothing of it and reads nothing of it."),
        ("# V4: every fatal since the deploy started fails, as in 5.18.95 — none is known in 5.18.98 through 5.18.103.",
         "# V4: every fatal since the deploy started fails, as in 5.18.95 — none is known in 5.18.98 through 5.18.104."),
        ("# 5.18.96 — the two of its tests the server runs under its own PHP, kept by 5.18.97 through 5.18.104 (which change",
         "# 5.18.96 — the two of its tests the server runs under its own PHP, kept by 5.18.97 through 5.18.105 (which change"),
        ("# tests/test_quote_agent_copy.php, with their sections, weakened copies and fakes) too — they build their books, serve the plugin",
         "# tests/test_quote_agent_copy.php, with their sections, weakened copies and fakes) and 5.18.105's (tests/test_log_mask.php,\n"
         "# tests/log_mask/ with its two fakes, tests/fixtures/r105_revert.php) too — they build their books, serve the plugin"),
        ("# Until it is pinned here the script refuses to run (below) rather than accept any count. 5.18.104 changes neither T1 nor",
         "# Until it is pinned here the script refuses to run (below) rather than accept any count. 5.18.105 changes neither T1 nor"),
        ("# and StoreInterface outside the release delta, A0), so the count is 5.18.103's. The release suite's two runs of the",
         "# and StoreInterface outside the release delta, A0), so the count is 5.18.104's (root docs/74 §H.3.2: 548, carried —\n"
         "# 5.18.105 adds no test to the set the server runs). The release suite's two runs of the"),
        ("# the tree judged (4), the allowed list against the SPEC (6). 5.18.104 changes two tab pages — tabs/engage/wa_inbox.php\n"
         "# and tabs/engage/whatsapp.php — and adds two, tabs/engage/wa_offers.php and tabs/sales/my_quotes.php; the D1 guard\n"
         "# sweeps every tab page, those four included: the count is 5.18.103's value, carried — re-measured on the release\n"
         "# commit by the rehearsal before pinning (docs/73 §H.2.15.4).",
         "# the tree judged (4), the allowed list against the SPEC (6). 5.18.105 changes no tab page: the count is 5.18.104's\n"
         "# value, carried — re-measured on the release commit by the rehearsal before pinning (root docs/74 §H.6)."),
        ("# Sudan; a South Sudan tenant without SSP; a Uganda tenant with SSP), then the Uganda book, the control. 5.18.103's code",
         "# Sudan; a South Sudan tenant without SSP; a Uganda tenant with SSP), then the Uganda book, the control. 5.18.104's code"),
        ("# — a regression since 5.18.96; 5.18.104 changes one of their files, public.php — its tab tables (My quotes, the offers\n"
         "# screen), not the repair page — and must leave every one of them in place: the photo",
         "# — a regression since 5.18.96; 5.18.105 changes none of their files (public.php is 5.18.104's, kept by its sha256)\n"
         "# and must leave every one of them in place: the photo"),
    ], why="constants comments")
    for f in ("tabs/", "lib/CashbookService.php", "lib/BrainContext.php", "lib/DishNetAiBrain.php"):
        if any(x.startswith(f) for x in d105):
            raise SystemExit(f"PART A: 5.18.105 changes {f} — a carried signature's words would be wrong")
    # the signatures carried (5.18.104's values; the rehearsal re-measures each on the release commit, root docs/74 §H.6)
    doc.subs([
        ("# 5.18.103's value, carried — re-measured on the release commit by the rehearsal before pinning (docs/73 §H.2.15.4).\nB2_SIGNATURE=",
         "# 5.18.104's value, carried — re-measured on the release commit by the rehearsal before pinning (root docs/74 §H.6).\nB2_SIGNATURE="),
        ("# the same. Both are 5.18.103's values, carried — re-measured on the release commit by the rehearsal before pinning\n"
         "# (docs/73 §H.2.15.4): 5.18.104 changes lib/BrainContext.php and lib/DishNetAiBrain.php (Part V's R3 and R4), each\n"
         "# behind a voice switch that ships off, so the prompts must still be equal.",
         "# the same. Both are 5.18.104's values, carried — re-measured on the release commit by the rehearsal before pinning\n"
         "# (root docs/74 §H.6): 5.18.105 changes neither lib/BrainContext.php nor lib/DishNetAiBrain.php."),
        ("# inert: nothing refused, nothing classified, no SQL added, the assistant never stopped. 5.18.103's value, carried —\n"
         "# re-measured on the release commit by the rehearsal before pinning (docs/73 §H.2.15.4).",
         "# inert: nothing refused, nothing classified, no SQL added, the assistant never stopped. 5.18.104's value, carried —\n"
         "# re-measured on the release commit by the rehearsal before pinning (root docs/74 §H.6)."),
    ], why="signature comments")
    doc.mustnot("5.18.103's value, carried — re-measured")
