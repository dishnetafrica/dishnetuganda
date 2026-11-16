# Part B — the carried checks re-asserted from Git (root docs/75 §L.2, §L.6): assertions first, few edits, each saying
# why. Modelled on wip/derive-5.18.105/partB.py, whose edits are spent (they turned 5.18.104's words into 5.18.105's);
# what is reused is its Git assertions. Runs after partA (it reads ctx's delta, kept list and 5.18.105's counts):
#   B1 the migrations: none in 5.18.106's delta, and A0's refusal of one carried; MIG104_SUMS still right — 089–091 the
#      same files at 7835906 and at the pin, by sha256 and md5, each kept by sha256 — and MIG104_N and MIG104_LAST its
#      count and last; 088 (sha256 and md5) and 087 the reviewed ones in both commits. Edits: A0's migration comment and
#      refusal name docs/75; A0's comment on the pinned lists (S105:10912-10916) carries 5.18.106's counts; A0's ok line
#      names 089–091 and the sales follow-up as 5.18.104's (the literal, by §L.6's rule: $BASELINE_VERSION is 5.18.105
#      now) and names 5.18.105's masked log lines among the files 5.18.106 keeps, and the follow-up jobs as what it changes.
#   B2 cron/master.php: one file at 994d660, 7835906 and the pin, equal to MASTER_PIN_SHA256 and MASTER_BASE_SHA256 as the
#      source carries them; the lock test, main.php (pin and base) and the auto-pull test the pinned ones in both commits.
#      Edits: three comments that said 5.18.105 keeps master say 5.18.105 and 5.18.106 do.
#   B3 the event processor: cron/event_processor.php and lib/NotificationService.php one file at 994d660, 7835906 and the
#      pin, outside the delta and kept by sha256; EP_PIN_LIST's line once in each commit's processor; UG_SIGNATURE,
#      UG_PIN_SIGNATURE, SS_SIGNATURE and EP_PIN_LIST as the source has them; RB_REF's grep on EP_PIN_LIST carried. Edits:
#      comments only.
#   B4 the ladder's three files: one file at 994d660, 7835906 and the pin, kept by sha256 — so LK's and LM's comments
#      ("994d660's bytes in both releases") are true as written. No edit.
#   B5 T1 and T4: their tests and every file T1 reads unchanged and outside the delta, no tab page in the delta, both
#      signatures carried. T1's count is a run count, confirmed by 5.18.106's two release-suite runs before the script is
#      pinned (§L.2) — not a fact of Git, so nothing here asserts its value. Edits: their comments name 5.18.106.
import re
from d106lib import git, sha_at, md5_at

MIGS = ["migrations/089_quote_followups.sql", "migrations/090_wa_voice.sql", "migrations/091_wa_offers.sql"]
LADDER = ["tools/sales_followup.php", "lib/SalesFollowupLadder.php", "lib/SalesFollowupChecks.php"]
EP_FILES = ["cron/event_processor.php", "lib/NotificationService.php"]
T1_READS_KEPT = ["lib/CashbookService.php", "lib/ConfigVault.php", "lib/TenantProfile.php", "lib/SqliteStore.php"]
T1_READS_OUT = ["lib/StoreInterface.php"]
MASKED_105 = ["webhook.php", "evo_webhook.php", "cron_quote_wa.php", "includes/post/post_cashbook.php", "lib/PluginLog.php",
              "tools/sales_followup_report.php"]
# 5.18.104's values, which 5.18.105 carried and 5.18.106 carries (asserted against the source and Git, never written)
EP_LINE = "$_epWorkerOwned = $_epUg ? ['ai.reply', 'ai.media', 'ai.voice_ack', 'crm.lead.sync'] : ['ai.reply'];"
UG_PIN = "crm.lead.sync=pending/0 ai.reply=pending/0 ai.media=pending/0 wa.escalation=done/0 install.ready=done/0+unknown"
SS = "crm.lead.sync=done/0+unknown ai.reply=pending/0 ai.media=done/0+unknown wa.escalation=done/0+unknown install.ready=done/0+unknown"


def const(doc, name):
    got = re.findall(rf'(?m)^{name}="([^"]*)"', doc.t)
    if len(got) != 1:
        raise SystemExit(f"PART B: {name} is assigned {len(got)} times")
    return got[0]


def one(repo, plugin, commits, f):
    """The one sha256 a file has in every commit named — or a refusal."""
    s = {sha_at(repo, c, f"{plugin}/{f}") for c in commits}
    if len(s) != 1:
        raise SystemExit(f"PART B: {f} differs between {', '.join(c[:7] for c in commits)}")
    return s.pop()


def apply(doc, ctx):
    repo, plugin, base, pin = ctx["repo"], ctx["plugin"], ctx["base_full"], ctx["pin_full"]
    added, changed, kept, base104 = ctx["added"], ctx["changed"], ctx["kept"], ctx["base104"]
    d106 = set(added) | set(changed)
    n_a, n_c, n_k, n_pin = ctx["n_added"], ctx["n_changed"], ctx["n_kept"], ctx["n_pin"]
    k105, p105, c105, a105 = ctx["n105"]
    if not base104.startswith("994d660"):
        raise SystemExit("PART B: the source's own baseline is not 994d660 — the carried comments name it")

    # ── B1 — the migrations: none added; 089–091, 088 and 087 the reviewed files in both release commits ──
    if any(f.startswith("migrations/") for f in d106):
        raise SystemExit("PART B: 5.18.106 carries a migration (root docs/75 §L.2)")
    doc.must('[ "$N_MIG" = "0" ] || stop "the pin carries $N_MIG migration(s)', 1, why="A0 refuses a pin that carries a migration")
    head = "MIG104_SUMS=\"$(cat <<'SUMS'\n"
    if doc.t.count(head) != 1:
        raise SystemExit("PART B: MIG104_SUMS is not found once")
    rows = doc.t[doc.t.index(head) + len(head):].split("\nSUMS\n", 1)[0].split("\n")
    seen = []
    for ln in rows:
        s, m5, f = ln.split()
        for c in (base, pin):
            if sha_at(repo, c, f"{plugin}/{f}") != s or md5_at(repo, c, f"{plugin}/{f}") != m5:
                raise SystemExit(f"PART B: {f} at {c[:7]} is not MIG104_SUMS' file (sha256 and md5)")
        if f not in kept:
            raise SystemExit(f"PART B: {f} is not in the kept list")
        seen.append((f, m5))
    last = re.findall(r'(?m)^MIG104_LAST="([^"]+)"; MIG104_LAST_MD5="([0-9a-f]{32})"$', doc.t)
    if [f for f, _ in seen] != MIGS or re.findall(r"(?m)^MIG104_N=([0-9]+)$", doc.t) != [str(len(seen))] \
            or last != [(seen[-1][0].split("/")[-1], seen[-1][1])]:
        raise SystemExit("PART B: MIG104_SUMS, MIG104_N or MIG104_LAST is not 089–091 as Git has them")
    m88, m88s, m88m = const(doc, "MIG88"), const(doc, "MIG88_SHA256"), const(doc, "MIG88_MD5")
    m87, m87s = const(doc, "MIG87"), const(doc, "MIG87_SHA256")
    for c in (base, pin):
        if sha_at(repo, c, f"{plugin}/{m88}") != m88s or md5_at(repo, c, f"{plugin}/{m88}") != m88m:
            raise SystemExit(f"PART B: {m88} at {c[:7]} is not the reviewed one")
        if sha_at(repo, c, f"{plugin}/{m87}") != m87s:
            raise SystemExit(f"PART B: {m87} at {c[:7]} is not the reviewed one")
    doc.sub("# 5.18.105 (root docs/74 §H.4.6): the pin adds no migration and changes none. 5.18.104's three, 089–091, are what the\n"
            "# live book must already carry (QF-G1 reads them applied); each is still the reviewed file at the pin, by its sha256 and\n"
            "# the md5 the runner records (MIG104_SUMS) — PREV_KEPT holds them by sha256 too.\n",
            "# 5.18.105 and 5.18.106 (root docs/74 §H.4.6, docs/75 §L.2): the pin adds no migration and changes none. 5.18.104's\n"
            "# three, 089–091, are what the live book must already carry (QF-G1 reads them applied); each is still the reviewed file\n"
            "# at the pin, by its sha256 and the md5 the runner records (MIG104_SUMS) — PREV_KEPT holds them by sha256 too.\n",
            why="A0 migrations comment")
    doc.sub("— $EXPECTED_VERSION adds none (root docs/74 §H.3.1): this script is for another build. Nothing was changed\"",
            "— $EXPECTED_VERSION adds none (root docs/75 §L.2): this script is for another build. Nothing was changed\"",
            why="A0 migrations refusal")
    doc.sub("# 5.18.98: every file of the delta by the sha256 pinned in this script (PIN_SHA256S) — the list is the delta exactly, its\n"
            f"# {p105} files and no other, the {a105} it adds among them — and the {k105} files of 5.18.104 this release must leave\n"
            "# as they are (PREV_KEPT_SHA256S) by theirs, in both release commits: what Q-G1 reads installed before anything changes,\n"
            f"# Q-CA1 after the copy and RB after a rollback. 5.18.99: and every file it changes ({c105}) by the sha256 5.18.104's\n"
            f"# release commit ({base104[:7]}) gives it (PREV_CHANGED_SHA256S; an added file has no such form, and is refused there) —\n",
            "# 5.18.98: every file of the delta by the sha256 pinned in this script (PIN_SHA256S) — the list is the delta exactly, its\n"
            f"# {n_pin} files and no other, the {n_a} it adds among them — and the {n_k} files of 5.18.105 this release must leave\n"
            "# as they are (PREV_KEPT_SHA256S) by theirs, in both release commits: what Q-G1 reads installed before anything changes,\n"
            f"# Q-CA1 after the copy and RB after a rollback. 5.18.99: and every file it changes ({n_c}) by the sha256 5.18.105's\n"
            f"# release commit ({base[:7]}) gives it (PREV_CHANGED_SHA256S; an added file has no such form, and is refused there) —\n",
            why="A0 lists comment")
    for f in MASKED_105:
        if f not in kept or f in d106:
            raise SystemExit(f"PART B: 5.18.105's {f} is not kept by 5.18.106 — A0's ok line would be wrong")
    doc.subs([
        ("— and no migration ($BASELINE_VERSION's $MIG104_N, 089–091, the reviewed files at the pin as at $BASELINE_COMMIT: sha256 and md5, MIG104_SUMS);",
         "— and no migration (5.18.104's $MIG104_N, 089–091, the reviewed files at the pin as at $BASELINE_COMMIT: sha256 and md5, MIG104_SUMS);"),
        ("and $BASELINE_VERSION's own sales follow-up (the switch tool, its ladder and checks, master, the event processor, NotificationService, 089–091) unchanged: the same pinned sha256 in both release commits; webhook.php, evo_webhook.php, cron_quote_wa.php, the Cashbook's post handler and the other files whose log lines $EXPECTED_VERSION masks are not among them (root docs/74 §H.1), each new form pinned with the delta, webhook.php's sign-off line still 5.18.101's (the QS checks);",
         "and 5.18.104's sales follow-up (the switch tool, its ladder and checks, master, the event processor, NotificationService, 089–091) and $BASELINE_VERSION's masked log lines (webhook.php, evo_webhook.php, cron_quote_wa.php, the Cashbook's post handler and the others, PluginLog and the report) unchanged: the same pinned sha256 in both release commits, webhook.php's sign-off line still 5.18.101's (the QS checks); the four follow-up jobs and lib/FollowUpService.php, which $EXPECTED_VERSION changes, are not among them (root docs/75 §C), each new form pinned with the delta;"),
    ], why="A0 ok line")

    # ── B2 — cron/master.php: 5.18.104's, which 5.18.105 and 5.18.106 keep ──
    master = const(doc, "MASTER")
    m = one(repo, plugin, (base104, base, pin), master)
    if master in d106 or master not in kept:
        raise SystemExit("PART B: cron/master.php is in the delta, or not kept by sha256")
    if const(doc, "MASTER_PIN_SHA256") != m or const(doc, "MASTER_BASE_SHA256") != m:
        raise SystemExit("PART B: MASTER_PIN_SHA256 or MASTER_BASE_SHA256 is not the master.php Git gives both commits")
    if one(repo, plugin, (base, pin), const(doc, "LOCK_TEST")) != const(doc, "LOCK_TEST_SHA256"):
        raise SystemExit("PART B: the lock test is not LOCK_TEST_SHA256 in both commits")
    mn = one(repo, plugin, (base, pin), const(doc, "MAIN"))
    if mn != const(doc, "MAIN_PIN_SHA256") or mn != const(doc, "MAIN_BASE_SHA256"):
        raise SystemExit("PART B: main.php is not MAIN_PIN_SHA256 and MAIN_BASE_SHA256 in both commits")
    if one(repo, plugin, (base, pin), const(doc, "AP_TEST")) != const(doc, "AP_TEST_SHA256"):
        raise SystemExit("PART B: the auto-pull test is not AP_TEST_SHA256 in both commits")
    doc.subs([
        ("# 5.18.104's master.php, which 5.18.105 keeps byte for byte:", "# 5.18.104's master.php, which 5.18.105 and 5.18.106 keep byte for byte:"),
        ("# cron/master.php — 5.18.105 keeps 5.18.104's byte for byte: 5.18.92's lock, ruling R1's four jobs last (A0) —\n"
         "# so this is the regression: master's lock code and",
         "# cron/master.php — 5.18.105 and 5.18.106 keep 5.18.104's byte for byte: 5.18.92's lock, ruling R1's four jobs last\n"
         "# (A0) — so this is the regression: master's lock code and"),
        ("# its auto-pull retired; cron/master.php is 5.18.104's (5.18.92's lock, ruling R1's four jobs last — 5.18.105 never\n"
         "# changed it),\n",
         "# its auto-pull retired; cron/master.php is 5.18.104's (5.18.92's lock, ruling R1's four jobs last — neither 5.18.105\n"
         "# nor 5.18.106 changed it),\n"),
    ], why="master comments")
    doc.must(f'MASTER_PIN_SHA256="{m}"   # 5.18.104\'s master.php, which 5.18.105 and 5.18.106 keep byte for byte:', 1)

    # ── B3 — the event processor and NotificationService: 5.18.104's, kept; the signatures as before ──
    for f in EP_FILES:
        if f in d106 or f not in kept:
            raise SystemExit(f"PART B: {f} is in the delta, or not kept by sha256")
        one(repo, plugin, (base104, base, pin), f)
    for c in (base, pin):
        if git(repo, "show", f"{c}:{plugin}/cron/event_processor.php").decode().split("\n").count(EP_LINE) != 1:
            raise SystemExit(f"PART B: {c[:7]}'s worker-owned list is not EP_PIN_LIST")
    doc.must(f'SS_SIGNATURE="{SS}"\n', 1)
    doc.must(f'UG_SIGNATURE="{UG_PIN}"\n', 1)
    doc.must(f'UG_PIN_SIGNATURE="{UG_PIN}"\n', 1)
    doc.must('EP_PIN_LIST="' + EP_LINE.replace("$", "\\$") + '"\n', 1)
    doc.must('grep -qxF -- "$EP_PIN_LIST" "$DEST/cron/event_processor.php" 2>/dev/null || RB_REF="$RB_REF batch1:event-processor"', 1)
    doc.lines_between("# 5.18.105 keeps cron/event_processor.php and lib/NotificationService.php, which it loads, byte for byte (R105_HELD):",
                      "# docs/74 §H.4.2). UG_PIN_SIGNATURE and EP_PIN_LIST are the same values, kept so A6 and R13 read as 5.18.104's did.",
        "# 5.18.105 and 5.18.106 keep cron/event_processor.php and lib/NotificationService.php, which it loads, byte for byte\n"
        "# (R105_HELD; root docs/75 §L.2): the live code, the pin and the rollback all read 5.18.104's signatures —\n"
        "# UG_SIGNATURE is 5.18.104's Uganda one (its pin signature, ai.media left to MediaWorker), SS_SIGNATURE its South Sudan\n"
        "# one; nothing is re-measured (root docs/74 §H.4.2). UG_PIN_SIGNATURE and EP_PIN_LIST are the same values, kept so A6\n"
        "# and R13 read as 5.18.104's did.", why="event processor comment")
    doc.subs([
        ("# 5.18.104 (root docs/73 §H.3.2), kept by 5.18.105: on Uganda the processor leaves ai.media (and ai.voice_ack) to their\n"
         "# workers too, so its Uganda run leaves the ai.media event pending, never logged unknown (A6, R13).\n",
         "# 5.18.104 (root docs/73 §H.3.2), kept by 5.18.105 and 5.18.106: on Uganda the processor leaves ai.media (and\n"
         "# ai.voice_ack) to their workers too, so its Uganda run leaves the ai.media event pending, never logged unknown\n"
         "# (A6, R13).\n"),
        ("# lib/NotificationService.php, both of which 5.18.105 keeps byte for byte, so after the rollback it reads 5.18.104's\n"
         "# signatures, as before the deploy: UG_SIGNATURE and SS_SIGNATURE (root docs/74 §H.4.2).\n",
         "# lib/NotificationService.php, both of which 5.18.105 and 5.18.106 keep byte for byte, so after the rollback it reads\n"
         "# 5.18.104's signatures, as before the deploy: UG_SIGNATURE and SS_SIGNATURE (root docs/74 §H.4.2, docs/75 §L.2).\n"),
    ], why="event processor comments")

    # ── B4 — the ladder's three files: 994d660's bytes in both release commits, as LK and LM say ──
    for f in LADDER:
        if f in d106 or f not in kept:
            raise SystemExit(f"PART B: {f} is in the delta, or not kept by sha256")
        one(repo, plugin, (base104, base, pin), f)
    doc.must("lib/SalesFollowupLadder.php being\n# 994d660's bytes in both releases (PREV_KEPT)", 1, why="LK's comment is true as written")
    doc.must("lib/SalesFollowupChecks.php (held by PREV_KEPT, 994d660's\n# bytes in both releases)", 1, why="LM's comment is true as written")

    # ── B5 — T1 and T4: their files unchanged (their counts are carried, T1's confirmed by the release suite) ──
    t1, t4 = const(doc, "T1_TEST"), const(doc, "T4_TEST")
    for f in [t1, t4] + T1_READS_KEPT + T1_READS_OUT:
        if f in d106:
            raise SystemExit(f"PART B: 5.18.106 changes {f}, which T1 or T4 runs or reads")
        one(repo, plugin, (base, pin), f)
    if [f for f in T1_READS_KEPT if f not in kept] or [f for f in T1_READS_OUT if f in kept]:
        raise SystemExit("PART B: the files T1 reads are not kept as its comment says")
    if any(f.startswith("tabs/") for f in d106):
        raise SystemExit("PART B: 5.18.106 changes a tab page — T4's carried count would need re-measuring")
    t1n = re.findall(r'(?m)^T1_SIGNATURE="([0-9]+) passed, 0 failed rc=0"$', doc.t)
    if len(t1n) != 1 or len(re.findall(r'(?m)^T4_SIGNATURE="[0-9]+ passed, 0 failed, 0 skipped rc=0"$', doc.t)) != 1:
        raise SystemExit("PART B: T1_SIGNATURE or T4_SIGNATURE is not the source's one form")
    u = sorted(f.split("/")[-1] for f in added if f.startswith("tests/quote_followup/"))
    if u != ["U_unjam.php", "mutants_U.php"] or "tests/fixtures/r106_revert.php" not in added:
        raise SystemExit(f"PART B: 5.18.106's tests are not the ones its comment names: {added}")
    doc.subs([
        ("# 5.18.96 — the two of its tests the server runs under its own PHP, kept by 5.18.97 through 5.18.105 (which change\n",
         "# 5.18.96 — the two of its tests the server runs under its own PHP, kept by 5.18.97 through 5.18.106 (which change\n"),
        ("# tests/log_mask/ with its two fakes, tests/fixtures/r105_revert.php) too — they build their books, serve the plugin\n"
         "# or a fake service on HTTP servers of their own, or start the relay on a port of their own.\n",
         "# tests/log_mask/ with its two fakes, tests/fixtures/r105_revert.php) and 5.18.106's (tests/quote_followup/U_unjam.php\n"
         "# and mutants_U.php, section U and its weakened copies, and tests/fixtures/r106_revert.php) too — they build their\n"
         "# books, serve the plugin or a fake service on HTTP servers of their own, or start the relay on a port of their own.\n"),
        ("# the tree judged (4), the allowed list against the SPEC (6). 5.18.105 changes no tab page: the count is 5.18.104's\n"
         "# value, carried — re-measured on the release commit by the rehearsal before pinning (root docs/74 §H.6).\n",
         "# the tree judged (4), the allowed list against the SPEC (6). 5.18.106 changes no tab page: the count is 5.18.105's\n"
         "# value, carried — re-measured on the release commit by the rehearsal before pinning (root docs/75 §L.10).\n"),
    ], why="T1 and T4 comments")
    doc.lines_between("# Until it is pinned here the script refuses to run (below) rather than accept any count. 5.18.105 changes neither T1 nor",
                      "# wherever this server's run does not.",
        "# Until it is pinned here the script refuses to run (below) rather than accept any count. 5.18.106 changes neither T1 nor\n"
        "# any file T1 reads (lib/CashbookService.php, ConfigVault, TenantProfile and SqliteStore, each kept by its sha256 above;\n"
        f"# StoreInterface outside the release delta, A0), so the count is 5.18.105's ({t1n[0]}, carried — 5.18.106 adds no test to\n"
        "# the set the server runs). It is a run count, not a fact of Git (root docs/75 §L.2): 5.18.106's two release-suite runs\n"
        "# of the release commit must say the same before the script is pinned and handed over, and A22-T1 refuses, before\n"
        "# anything changes, wherever this server's run does not.", why="T1 count comment")
    doc.must("wherever this server's run does not.\nT1_SIGNATURE=\"", 1, why="T1's comment ends on its signature")
    for s in ("which 5.18.105 keeps byte for byte:", "5.18.105 keeps cron/event_processor.php", "kept by 5.18.97 through 5.18.105",
              "kept by 5.18.105: on Uganda", "5.18.105 changes no tab page", "5.18.105 changes neither T1",
              "the 43 it adds among them", "(root docs/74 §H.3.1)", "files whose log lines $EXPECTED_VERSION masks"):
        doc.mustnot(s, why="no 5.18.105-only carried wording left in partB's places")
