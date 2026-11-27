# Part B — the carried checks re-asserted from Git (root docs/76 §T.2): assertions first, few edits, each saying why.
# Modelled on wip/derive-5.18.106/partB.py, whose edits are spent (they turned 5.18.105's words into 5.18.106's); what is
# reused is its Git assertions, re-pointed. Runs after partA (it reads ctx's delta and kept list):
#   B1 the carried migrations: 089–091 (MIG104_SUMS) the same files at bf29cbd and at the pin, by sha256 and md5, each kept
#      by sha256; MIG104_N and MIG104_LAST their count and last; 088 (sha256 and md5) and 087 the reviewed ones in both
#      commits. 092 is partMG's. Edits: A0's comment on the pinned lists carries 5.18.107's counts; A0's ok line names what
#      5.18.107 keeps and what it changes (§T.2: the old one named QuoteTaxLine, the event processor and NotificationService
#      among the kept), and the one migration it adds.
#   B2 cron/master.php: one file at 7835906, bf29cbd and the pin, equal to MASTER_PIN_SHA256 and MASTER_BASE_SHA256; the
#      lock test, main.php and the auto-pull test the pinned ones in both commits. Edits: the comments that said 5.18.105 and
#      5.18.106 keep master add 5.18.107.
#   B3 the event processor (§T.2): cron/event_processor.php and lib/NotificationService.php are in the delta now.
#      EP_PIN_LIST is the pin's line and EP_BASE_LIST, new, bf29cbd's; A6 and R13 grep the pin's, RB the base's. UG_SIGNATURE
#      and SS_SIGNATURE stay (the live code and the rollback, bf29cbd's processor); UG_PIN_SIGNATURE is carried (its five
#      probe events exclude the new type) and re-measured on the release commit (MEASURED below). EP_PHP gains an optional
#      fourth argument, a type:priority list, and ep_run an optional third parameter passed only when set; the nine
#      existing ep_run calls on their five lines stay byte for byte; a probe run of its own for wa.no_ai_alert, with
#      EP_NOAI_PIN_UG and EP_NOAI_DONE, read by A6, R13 and RB. Found at derivation (§T.14 DF-4, DF-5): R6's text check and
#      VN-CA3's line carried the old list literally and would fail after every deploy — re-pointed to EP_PIN_LIST and to
#      the pin's line from Git.
#   B4 QF-G3's hooks (§T.2, CC-4): QF_HOOKS re-read at the pin, as QF-G3's own reader counts them; unchanged.
#   B5 the text pins in files 5.18.107 changes (§T.2): the bank menu block, OF_NAV_SHA, R6's N_IR, N_SW and N_PR, the TN
#      lines — each computed from the pin by the script's own reader, re-implemented here, and equal to the constant.
#   B6 T1 and T4: their tests and every file T1 reads unchanged and outside the delta. T1's count is the release suite's run
#      count on the release commit; T4's is re-measured on it (§T.2): MEASURED below, never read off Git. Edits: comments.
import re, hashlib
from d107lib import git, sha_at, md5_at

MIGS = ["migrations/089_quote_followups.sql", "migrations/090_wa_voice.sql", "migrations/091_wa_offers.sql"]
EP_FILES = ["cron/event_processor.php", "lib/NotificationService.php"]
T1_READS_KEPT = ["lib/CashbookService.php", "lib/ConfigVault.php", "lib/TenantProfile.php", "lib/SqliteStore.php"]
T1_READS_OUT = ["lib/StoreInterface.php"]
KEPT_NAMED = ["lib/QuoteOrganization.php", "lib/TenantProfile.php", "lib/PublicPriceFeed.php", "cron/master.php",
              "lib/SalesFollowupChecks.php", "lib/MigrationRunner.php", "webhook.php", "cron_quote_wa.php",
              "includes/post/post_cashbook.php", "lib/PluginLog.php", "tools/sales_followup_report.php"]
CHANGED_NAMED = ["cron/event_processor.php", "lib/NotificationService.php", "lib/SalesFollowupLadder.php",
                 "tools/sales_followup.php", "evo_webhook.php", "cron/wa_watchdog.php", "lib/QuoteTaxLine.php"]
EP_LINE_BASE = "$_epWorkerOwned = $_epUg ? ['ai.reply', 'ai.media', 'ai.voice_ack', 'crm.lead.sync'] : ['ai.reply'];"
UG = "crm.lead.sync=pending/0 ai.reply=pending/0 ai.media=pending/0 wa.escalation=done/0 install.ready=done/0+unknown"
SS = "crm.lead.sync=done/0+unknown ai.reply=pending/0 ai.media=done/0+unknown wa.escalation=done/0+unknown install.ready=done/0+unknown"
EP_DEFAULT = '[["crm.lead.sync", 5], ["ai.reply", 3], ["ai.media", 3], ["wa.escalation", 2], ["install.ready", 5]]'
# the nine ep_run calls on their five lines (S106:13114-13115, 15011-15012, 15502), byte for byte (§T.2)
EP_CALL_LINES = [
    ('    SS_LIVE="$(ep_run "$IN_CONTAINER" south-sudan)"; SS_PIN="$(ep_run "$PIN_ROOT" south-sudan)"', 2),
    ('    UG_LIVE="$(ep_run "$IN_CONTAINER" uganda)"; UG_PIN="$(ep_run "$PIN_ROOT" uganda)"; B2_PIN="$(b2_run "$PIN_ROOT")"; P90_PIN="$(p90_run "$PIN_ROOT")"', 2),
    ('UG_NOW="$(ep_run "$IN_CONTAINER" uganda)"; SS_NOW="$(ep_run "$IN_CONTAINER" south-sudan)"', 2),
    ('if [ -n "$CHK_OK" ]; then SS_BASE="$(ep_run "$BASE_ROOT" south-sudan)"; else SS_BASE="?nocode"; fi', 1),
    ('EP_RB_UG="$(ep_run "$IN_CONTAINER" uganda)"; EP_RB_SS="$(ep_run "$IN_CONTAINER" south-sudan)"', 2),
]
# Values measured on the release commit, never read off Git (§T.2, §T.12 step 2). Each carries its source; "expected" is
# a value §T predicts and the release suite or the rehearsal has still to show — the driver refuses to write the committed
# script while any is not "measured".
MEASURED = {
    "UG_PIN_SIGNATURE": (UG, "measured", "§T.15 M0: A6's own EP_PHP, taken from the script, run on 771a3c1 as Uganda "
                         "(five events) with PHP 8.3 CLI, 27 Nov — the PHP the rehearsal's stand-in container runs; "
                         "its A6 and R13 read it again"),
    "EP_NOAI_PIN_UG": ("wa.no_ai_alert=pending/0", "measured", "§T.15 M0: EP_PHP on 771a3c1 as Uganda with "
                       "wa.no_ai_alert:3, 27 Nov"),
    "EP_NOAI_DONE": ("wa.no_ai_alert=done/0+unknown", "measured", "§T.15 M0: EP_PHP with wa.no_ai_alert:3 on bf29cbd "
                     "as Uganda and as South Sudan, and on 771a3c1 as South Sudan, each this value, 27 Nov"),
    "T1_SIGNATURE": ("548 passed, 0 failed rc=0", "measured", "§T.15: T1 on 771a3c1, 27 Nov (as in suite pass D1 on the "
                     "first cut, 5b6245b, which the re-cut does not change for it)"),
    "T4_SIGNATURE": ("20 passed, 0 failed, 0 skipped rc=0", "measured", "§T.15: T4 with --no-mutants on 771a3c1 against "
                     "f4b4c96 (5.18.94), 27 Nov"),
}


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


def lines_of(text):
    ls = text.split("\n")
    return ls[:-1] if text.endswith("\n") else ls


def hooks_count(text, needle, method):
    """QF-G3's reader: a line naming a function opens that method; lines holding the needle are counted in it ('-': all)."""
    if method == "-":
        return sum(1 for l in lines_of(text) if needle in l)
    rx = re.compile(r"^\s*((public|private|protected|static|final|abstract)\s+)*function\s+[A-Za-z_][A-Za-z0-9_]*\s*\(")
    cur, n = None, 0
    for l in lines_of(text):
        if rx.match(l):
            cur = re.sub(r"\s*\(.*", "", re.sub(r"^.*function\s+", "", l))
        if needle in l and cur == method:
            n += 1
    return n


def bk_block(text):
    """bk3_check's block: from the line starting '<?php // 5.18.97 ' to the first '<?php endif; ?>' (both kept)."""
    out, on = [], False
    for l in lines_of(text):
        if not on and re.match(r"^<\?php // 5\.18\.97 ", l):
            on = True
        if on:
            out.append(l)
            if l == "<?php endif; ?>":
                break
    return out


def of_block(text):
    """of_nav_block: from '<?php /* 5.18.104 (root docs/73 …): WhatsApp offers, Uganda only' to its '<?php endif; ?>'."""
    out, on = [], False
    for l in lines_of(text):
        if not on and l.startswith("<?php /* 5.18.104 (root docs/73 ") and "): WhatsApp offers, Uganda only" in l:
            on = True
        if on:
            out.append(l)
            if l == "<?php endif; ?>":
                break
    return out


def apply(doc, ctx):
    repo, plugin, base, pin = ctx["repo"], ctx["plugin"], ctx["base_full"], ctx["pin_full"]
    added, changed, kept, base105 = ctx["added"], ctx["changed"], ctx["kept"], ctx["base105"]
    d107 = set(added) | set(changed)
    n_a, n_c, n_k, n_pin = ctx["n_added"], ctx["n_changed"], ctx["n_kept"], ctx["n_pin"]
    show = lambda c, f: git(repo, "show", f"{c}:{plugin}/{f}").decode()
    ctx.setdefault("measured", {}).update(MEASURED)

    # ── B1 — the carried migrations: 089–091, 088 and 087 the reviewed files in both release commits ──
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
    k106, p106, c106, a106 = ctx["n106"]
    doc.sub("# 5.18.98: every file of the delta by the sha256 pinned in this script (PIN_SHA256S) — the list is the delta exactly, its\n"
            f"# {p106} files and no other, the {a106} it adds among them — and the {k106} files of 5.18.105 this release must leave\n"
            "# as they are (PREV_KEPT_SHA256S) by theirs, in both release commits: what Q-G1 reads installed before anything changes,\n"
            f"# Q-CA1 after the copy and RB after a rollback. 5.18.99: and every file it changes ({c106}) by the sha256 5.18.105's\n"
            f"# release commit ({base105[:7]}) gives it (PREV_CHANGED_SHA256S; an added file has no such form, and is refused there) —\n",
            "# 5.18.98: every file of the delta by the sha256 pinned in this script (PIN_SHA256S) — the list is the delta exactly, its\n"
            f"# {n_pin} files and no other, the {n_a} it adds among them — and the {n_k} files of 5.18.106 this release must leave\n"
            "# as they are (PREV_KEPT_SHA256S) by theirs, in both release commits: what Q-G1 reads installed before anything changes,\n"
            f"# Q-CA1 after the copy and RB after a rollback. 5.18.99: and every file it changes ({n_c}) by the sha256 5.18.106's\n"
            f"# release commit ({base[:7]}) gives it (PREV_CHANGED_SHA256S; an added file has no such form, and is refused there) —\n",
            why="A0 lists comment")
    for f in KEPT_NAMED:
        if f not in kept or f in d107:
            raise SystemExit(f"PART B: {f} is not kept by 5.18.107 — A0's ok line would be wrong")
    for f in CHANGED_NAMED:
        if f not in changed:
            raise SystemExit(f"PART B: {f} is not changed by 5.18.107 — A0's ok line would be wrong")
    doc.sub("— and no migration (5.18.104's $MIG104_N, 089–091, the reviewed files at the pin as at $BASELINE_COMMIT: sha256 and md5, MIG104_SUMS);",
            "— and one migration added, ${MIG107_LAST} (MIG107_SUMS: its sha256 and md5, added and never modified, its _schema_checks statement 089's byte for byte), with 5.18.104's $MIG104_N, 089–091, the reviewed files at the pin as at $BASELINE_COMMIT: sha256 and md5, MIG104_SUMS);",
            why="A0 ok line: the migrations")
    doc.sub("the quoting files $EXPECTED_VERSION keeps — QuoteOrganization, QuoteTaxLine, TenantProfile and PublicPriceFeed among them — and 5.18.104's sales follow-up (the switch tool, its ladder and checks, master, the event processor, NotificationService, 089–091) and $BASELINE_VERSION's masked log lines (webhook.php, evo_webhook.php, cron_quote_wa.php, the Cashbook's post handler and the others, PluginLog and the report) unchanged: the same pinned sha256 in both release commits, webhook.php's sign-off line still 5.18.101's (the QS checks); the four follow-up jobs and lib/FollowUpService.php, which $EXPECTED_VERSION changes, are not among them (root docs/75 §C), each new form pinned with the delta;",
            "the quoting files $EXPECTED_VERSION keeps — QuoteOrganization, TenantProfile and PublicPriceFeed among them — master, the switch checks (lib/SalesFollowupChecks.php), the migration runner, 089–091, and 5.18.105's masked log lines that $EXPECTED_VERSION keeps (webhook.php, cron_quote_wa.php, the Cashbook's post handler and the others, PluginLog and the report) unchanged: the same pinned sha256 in both release commits, webhook.php's sign-off line still 5.18.101's (the QS checks); the event processor, NotificationService, the switch tool and its ladder, evo_webhook.php, the watchdog and QuoteTaxLine, which $EXPECTED_VERSION changes, are not among them (root docs/76 §T.2), each new form pinned with the delta;",
            why="A0 ok line: what is kept")

    # ── B2 — cron/master.php: 5.18.104's, which 5.18.105, 5.18.106 and 5.18.107 keep ──
    master = const(doc, "MASTER")
    m = one(repo, plugin, (base105, base, pin), master)
    if master in d107 or master not in kept:
        raise SystemExit("PART B: cron/master.php is in the delta, or not kept by sha256")
    if const(doc, "MASTER_PIN_SHA256") != m or const(doc, "MASTER_BASE_SHA256") != m or not m.startswith("b59e7ce5"):
        raise SystemExit("PART B: MASTER_PIN_SHA256 or MASTER_BASE_SHA256 is not the master.php Git gives both commits (b59e7ce5…)")
    if one(repo, plugin, (base, pin), const(doc, "LOCK_TEST")) != const(doc, "LOCK_TEST_SHA256"):
        raise SystemExit("PART B: the lock test is not LOCK_TEST_SHA256 in both commits")
    mn = one(repo, plugin, (base, pin), const(doc, "MAIN"))
    if mn != const(doc, "MAIN_PIN_SHA256") or mn != const(doc, "MAIN_BASE_SHA256"):
        raise SystemExit("PART B: main.php is not MAIN_PIN_SHA256 and MAIN_BASE_SHA256 in both commits")
    if one(repo, plugin, (base, pin), const(doc, "AP_TEST")) != const(doc, "AP_TEST_SHA256"):
        raise SystemExit("PART B: the auto-pull test is not AP_TEST_SHA256 in both commits")
    doc.subs([
        ("# 5.18.104's master.php, which 5.18.105 and 5.18.106 keep byte for byte:",
         "# 5.18.104's master.php, which 5.18.105, 5.18.106 and 5.18.107 keep byte for byte:"),
        ("# cron/master.php — 5.18.105 and 5.18.106 keep 5.18.104's byte for byte: 5.18.92's lock, ruling R1's four jobs last\n"
         "# (A0) — so this is the regression: master's lock code and",
         "# cron/master.php — 5.18.105, 5.18.106 and 5.18.107 keep 5.18.104's byte for byte: 5.18.92's lock, ruling R1's four\n"
         "# jobs last (A0) — so this is the regression: master's lock code and"),
        ("# its auto-pull retired; cron/master.php is 5.18.104's (5.18.92's lock, ruling R1's four jobs last — neither 5.18.105\n"
         "# nor 5.18.106 changed it),\n",
         "# its auto-pull retired; cron/master.php is 5.18.104's (5.18.92's lock, ruling R1's four jobs last — none of 5.18.105,\n"
         "# 5.18.106 and 5.18.107 changed it),\n"),
    ], why="master comments")
    doc.must(f'MASTER_PIN_SHA256="{m}"   # 5.18.104\'s master.php, which 5.18.105, 5.18.106 and 5.18.107 keep byte for byte:', 1)

    # ── B3 — the event processor: the pin's list and bf29cbd's; the signatures; the wa.no_ai_alert probe ──
    for f in EP_FILES:
        if f not in changed:
            raise SystemExit(f"PART B: {f} is not a file 5.18.107 changes (§T.2 says it leaves the kept list)")
    pin_ep, base_ep = show(pin, "cron/event_processor.php"), show(base, "cron/event_processor.php")
    pin_lines = [l for l in lines_of(pin_ep) if l.startswith("$_epWorkerOwned = ")]
    base_lines = [l for l in lines_of(base_ep) if l.startswith("$_epWorkerOwned = ")]
    if base_lines != [EP_LINE_BASE] or len(pin_lines) != 1:
        raise SystemExit(f"PART B: the worker-owned lists are not one line each (base {base_lines}, pin {pin_lines})")
    ep_pin = pin_lines[0]
    if ep_pin != EP_LINE_BASE.replace("'crm.lead.sync']", "'crm.lead.sync', 'wa.no_ai_alert']"):
        raise SystemExit(f"PART B: the pin's list is not bf29cbd's with wa.no_ai_alert added on Uganda's side alone: {ep_pin}")
    for c, txt in ((base, base_ep), (pin, pin_ep)):          # A6's text needles, as A6 counts them on the pin's file
        n_media = sum(1 for l in lines_of(txt) if "ai.media" in l)
        if n_media != 2:
            raise SystemExit(f"PART B: {c[:7]}'s event processor names ai.media on {n_media} lines, not A6's 2")
    if sum(1 for l in lines_of(pin_ep) if "    $events = $bus->consume(20);" in l) != 1 \
            or sum(1 for l in lines_of(pin_ep) if "    if (empty($events)) return; // nothing to process" in l) != 1 \
            or sum(1 for l in lines_of(pin_ep) if "    $_epUg = StaffJobsGate::applies(" in l) != 1:
        raise SystemExit("PART B: the pin's event processor is not as A6's early-return and country-gate counts read it")
    doc.must(f'SS_SIGNATURE="{SS}"\n', 1)
    doc.must(f'UG_SIGNATURE="{UG}"\n', 1)
    doc.must(f'UG_PIN_SIGNATURE="{UG}"\n', 1)
    if MEASURED["UG_PIN_SIGNATURE"][0] != UG:
        raise SystemExit("PART B: UG_PIN_SIGNATURE's measured value is not the carried one — §T.2 expects it unchanged; "
                         "a change needs its own design note before it is written")
    esc = lambda s: s.replace("$", "\\$")
    doc.sub('EP_PIN_LIST="' + esc(EP_LINE_BASE) + '"\n',
            'EP_PIN_LIST="' + esc(ep_pin) + '"   # the pin\'s line (from Git): A6 and R13, and R6 and VN-CA3 after the copy\n'
            'EP_BASE_LIST="' + esc(EP_LINE_BASE) + '"   # bf29cbd\'s line (from Git): RB after a rollback\n'
            '# 5.18.107 (root docs/76 §T.2): one probe run of its own for wa.no_ai_alert (EP_PHP\'s fourth argument), in the\n'
            '# signatures\' own form — the pin on Uganda leaves the alert for its worker; bf29cbd on both profiles, and the pin\n'
            '# on South Sudan, settle it done as unknown (what a rollback does to a queued alert). Read by A6, R13 and RB.\n'
            f'EP_NOAI_PIN_UG="{MEASURED["EP_NOAI_PIN_UG"][0]}"\n'
            f'EP_NOAI_DONE="{MEASURED["EP_NOAI_DONE"][0]}"\n',
            why="EP_PIN_LIST split from EP_BASE_LIST; the no-AI probe's constants")
    doc.lines_between("# 5.18.105 and 5.18.106 keep cron/event_processor.php and lib/NotificationService.php, which it loads, byte for byte",
                      "# and R13 read as 5.18.104's did.",
        "# 5.18.105 and 5.18.106 kept cron/event_processor.php and lib/NotificationService.php byte for byte; 5.18.107 changes\n"
        "# both (root docs/76 §T.2): wa.no_ai_alert joins Uganda's worker-owned list, and sendImage takes the image's class\n"
        "# (F-8). The live code and the rollback are bf29cbd's processor, and read 5.18.104's signatures — UG_SIGNATURE, its\n"
        "# Uganda one, and SS_SIGNATURE, its South Sudan one. UG_PIN_SIGNATURE, the pin's Uganda run, is the same value: its\n"
        "# five probe events exclude the new type (re-measured on the release commit, §T.12). EP_PIN_LIST is the pin's line and\n"
        "# EP_BASE_LIST bf29cbd's: R13 greps the first after the copy, RB the second after a rollback.", why="event processor comment")
    doc.subs([
        ("# 5.18.104 (root docs/73 §H.3.2), kept by 5.18.105 and 5.18.106: on Uganda the processor leaves ai.media (and\n",
         "# 5.18.104 (root docs/73 §H.3.2), kept by 5.18.105, 5.18.106 and 5.18.107: on Uganda the processor leaves ai.media (and\n"),
        ("# lib/NotificationService.php, both of which 5.18.105 and 5.18.106 keep byte for byte, so after the rollback it reads\n"
         "# 5.18.104's signatures, as before the deploy: UG_SIGNATURE and SS_SIGNATURE (root docs/74 §H.4.2, docs/75 §L.2).\n",
         "# lib/NotificationService.php, which a rollback puts back as bf29cbd has them, so after the rollback it reads 5.18.104's\n"
         "# signatures, as before the deploy: UG_SIGNATURE and SS_SIGNATURE — and settles a queued wa.no_ai_alert done as unknown\n"
         "# on both profiles (EP_NOAI_DONE; root docs/76 §T.2, §T.7).\n"),
    ], why="event processor comments")
    # EP_PHP: an optional fourth argument, a comma-separated type:priority list; absent or empty, the five pairs exactly
    if doc.t.count(EP_DEFAULT) != 1:
        raise SystemExit("PART B: EP_PHP's default list is not S106:1963's five pairs, once")
    doc.sub('[$root, $tmp, $profile] = array_slice($argv, 1, 3);\nif (!is_dir($tmp) || count(scandir($tmp)) !== 2) { echo "?not-empty"; exit(2); }\n',
            '[$root, $tmp, $profile] = array_slice($argv, 1, 3);\n'
            '// 5.18.107 (root docs/76 §T.2): an optional fourth argument, a comma-separated type:priority list; absent or empty,\n'
            '// the five events below exactly\n'
            '$evs = ' + EP_DEFAULT + ';\n'
            '$want = (string)($argv[4] ?? "");\n'
            'if ($want !== "") {\n'
            '    $evs = [];\n'
            '    foreach (explode(",", $want) as $pair) {\n'
            '        if (!preg_match(\'/^([a-z][a-z0-9_.]*):([0-9]+)$/\', $pair, $mm)) { echo "?events"; exit(2); }\n'
            '        $evs[] = [$mm[1], (int)$mm[2]];\n'
            '    }\n'
            '}\n'
            'if (!is_dir($tmp) || count(scandir($tmp)) !== 2) { echo "?not-empty"; exit(2); }\n', why="EP_PHP's fourth argument")
    doc.sub('    foreach (' + EP_DEFAULT + ' as [$t, $prio]) {\n', '    foreach ($evs as [$t, $prio]) {\n', why="EP_PHP seeds the list")
    doc.must(EP_DEFAULT, 1, why="the default list is S106:1963's five pairs, unchanged, once")
    doc.sub("# the vault are the throwaway one's. Prints the signature (SS_SIGNATURE / UG_SIGNATURE above).\n",
            "# the vault are the throwaway one's. Prints the signature (SS_SIGNATURE / UG_SIGNATURE above). 5.18.107: given a\n"
            "# fourth argument, a type:priority list, it seeds exactly those events instead (EP_NOAI_PIN_UG / EP_NOAI_DONE).\n",
            why="EP_PHP comment")
    doc.sub('ep_run() {   # ep_run CODE_ROOT uganda|south-sudan — the signature; the throwaway directory is removed again\n',
            'ep_run() {   # ep_run CODE_ROOT uganda|south-sudan [TYPE:PRIORITY,…] — the signature; the throwaway directory is removed again\n',
            why="ep_run's third parameter")
    doc.sub('  o="$(docker exec -u "$DB_OWNER" "$CONTAINER" php -d display_errors=stderr -r "$EP_PHP" "$1" "$d" "$2" 2>/dev/null </dev/null)"\n',
            '  o="$(docker exec -u "$DB_OWNER" "$CONTAINER" php -d display_errors=stderr -r "$EP_PHP" "$1" "$d" "$2" ${3:+"$3"} 2>/dev/null </dev/null)"   # the third only when set\n',
            why="ep_run passes it only when set")
    # the nine existing calls stay byte for byte, none with a third argument; a line holding two cannot pass on its first
    for l, k in EP_CALL_LINES:
        doc.must(l + "\n", 1, why="an existing ep_run line, byte for byte")
        calls = re.findall(r'ep_run "\$[A-Z_]+" (?:uganda|south-sudan)\)', l)
        if len(calls) != k or l.count("ep_run ") != k:
            raise SystemExit(f"PART B: an existing ep_run line does not hold exactly {k} call(s) without a third argument: {l[:80]}")
    if sum(k for _, k in EP_CALL_LINES) != 9:
        raise SystemExit("PART B: the existing ep_run calls are not nine")
    # A6: the pin's and the live code's runs of the alert, beside the five-event runs
    doc.sub('  else SS_LIVE="?nocode"; SS_PIN="?nocode"; UG_LIVE="?nocode"; UG_PIN="?nocode"; B2_PIN="?nocode"; P90_PIN="?nocode"; fi\n',
            '  else SS_LIVE="?nocode"; SS_PIN="?nocode"; UG_LIVE="?nocode"; UG_PIN="?nocode"; B2_PIN="?nocode"; P90_PIN="?nocode"; fi\n'
            '  # 5.18.107 (root docs/76 §T.2): the assistant-off alert, a run of its own — the live code settles it done as unknown on\n'
            '  # both profiles; the pin leaves it for its worker on Uganda and settles it so on South Sudan\n'
            '  if [ -n "$CHK_OK" ]; then\n'
            '    NA_LIVE_UG="$(ep_run "$IN_CONTAINER" uganda wa.no_ai_alert:3)"; NA_LIVE_SS="$(ep_run "$IN_CONTAINER" south-sudan wa.no_ai_alert:3)"\n'
            '    NA_PIN_UG="$(ep_run "$PIN_ROOT" uganda wa.no_ai_alert:3)"; NA_PIN_SS="$(ep_run "$PIN_ROOT" south-sudan wa.no_ai_alert:3)"\n'
            '  else NA_LIVE_UG="?nocode"; NA_LIVE_SS="?nocode"; NA_PIN_UG="?nocode"; NA_PIN_SS="?nocode"; fi\n',
            why="A6 runs the alert's probe")
    doc.sub('  echo "  send policy     pin  $EXPECTED_VERSION: $P90_PIN"\n',
            '  echo "  send policy     pin  $EXPECTED_VERSION: $P90_PIN"\n'
            '  echo "  no-AI alert     live $BASELINE_VERSION: $NA_LIVE_UG (Uganda), $NA_LIVE_SS (South Sudan)"\n'
            '  echo "                  pin  $EXPECTED_VERSION: $NA_PIN_UG (Uganda), $NA_PIN_SS (South Sudan)"\n',
            why="A6 shows the alert's probe")
    doc.sub('  [ "$P90_PIN" = "$P90_SIGNATURE" ] || A6_BAD="$A6_BAD send-policy"\n',
            '  [ "$P90_PIN" = "$P90_SIGNATURE" ] || A6_BAD="$A6_BAD send-policy"\n'
            '  [ "$NA_LIVE_UG|$NA_LIVE_SS" = "$EP_NOAI_DONE|$EP_NOAI_DONE" ] || A6_BAD="$A6_BAD live-no-ai-alert-not-measured"\n'
            '  [ "$NA_PIN_SS" = "$NA_LIVE_SS" ] || A6_BAD="$A6_BAD south-sudan-no-ai-alert-differs"\n'
            '  [ "$NA_PIN_UG" = "$EP_NOAI_PIN_UG" ] || A6_BAD="$A6_BAD uganda-no-ai-alert-not-$EXPECTED_VERSION\'s"\n',
            why="A6 judges the alert's probe")
    doc.sub("ok \"A6 South Sudan stays exactly as it is: the pin's event processor leaves five events as the live one does as a South Sudan install (its list ['ai.reply'], no ai.media); as a Uganda one it leaves the workers' events to them, ai.media now among them ($UG_PIN, UG_PIN_SIGNATURE);",
            "ok \"A6 South Sudan stays exactly as it is: the pin's event processor leaves five events as the live one does as a South Sudan install (its list ['ai.reply'], no ai.media), and settles the assistant-off alert done as unknown as the live one does ($NA_PIN_SS); as a Uganda one it leaves the workers' events to them, ai.media among them ($UG_PIN, UG_PIN_SIGNATURE), and the assistant-off alert to its worker ($NA_PIN_UG; the live code acks it as unknown);",
            why="A6's ok line")
    # R13: the installed processor's run of the alert
    doc.sub('[ "$SS_BASE" = "$SS_SIGNATURE" ] || R13_BAD="$R13_BAD control($BASELINE_VERSION):$SS_BASE"\n',
            '[ "$SS_BASE" = "$SS_SIGNATURE" ] || R13_BAD="$R13_BAD control($BASELINE_VERSION):$SS_BASE"\n'
            'NA_NOW_UG="$(ep_run "$IN_CONTAINER" uganda wa.no_ai_alert:3)"; NA_NOW_SS="$(ep_run "$IN_CONTAINER" south-sudan wa.no_ai_alert:3)"   # 5.18.107 (root docs/76 §T.2)\n'
            '[ "$NA_NOW_UG" = "$EP_NOAI_PIN_UG" ] || R13_BAD="$R13_BAD uganda-no-ai-alert:$NA_NOW_UG"\n'
            '[ "$NA_NOW_SS" = "$EP_NOAI_DONE" ] || R13_BAD="$R13_BAD south-sudan-no-ai-alert:$NA_NOW_SS"\n',
            why="R13 runs and judges the alert's probe")
    doc.sub("ok \"R13 S8 the installed event processor, on throwaway databases: on Uganda it leaves ai.reply, ai.media and crm.lead.sync to their workers and settles wa.escalation as known ($UG_NOW);",
            "ok \"R13 S8 the installed event processor, on throwaway databases: on Uganda it leaves ai.reply, ai.media and crm.lead.sync to their workers and settles wa.escalation as known ($UG_NOW), and leaves the assistant-off alert to its worker ($NA_NOW_UG);",
            why="R13's ok line")
    # RB: bf29cbd's list after a rollback, and its run of the alert
    doc.sub('grep -qxF -- "$EP_PIN_LIST" "$DEST/cron/event_processor.php" 2>/dev/null || RB_REF="$RB_REF batch1:event-processor"   # 5.18.105 and 5.18.106: the rollback leaves 5.18.104\'s list, which 7835906 and the pin both carry (EP_PIN_LIST)',
            'grep -qxF -- "$EP_BASE_LIST" "$DEST/cron/event_processor.php" 2>/dev/null || RB_REF="$RB_REF batch1:event-processor"   # 5.18.107 (root docs/76 §T.2): the rollback puts back bf29cbd\'s list (EP_BASE_LIST), without wa.no_ai_alert',
            why="RB greps bf29cbd's list")
    rb_ep = 'EP_RB_UG="$(ep_run "$IN_CONTAINER" uganda)"; EP_RB_SS="$(ep_run "$IN_CONTAINER" south-sudan)"\n'
    doc.sub(rb_ep, rb_ep + 'NA_RB_UG="$(ep_run "$IN_CONTAINER" uganda wa.no_ai_alert:3)"; NA_RB_SS="$(ep_run "$IN_CONTAINER" south-sudan wa.no_ai_alert:3)"   # 5.18.107 (root docs/76 §T.2, §T.7)\n',
            why="RB runs the alert's probe")
    doc.sub('{ [ "$EP_RB_UG" = "$UG_SIGNATURE" ] && [ "$EP_RB_SS" = "$SS_SIGNATURE" ]; } && ok "RB the event processor is $BASELINE_VERSION\'s: on Uganda it leaves the workers\' events to them ($EP_RB_UG), on South Sudan the loop as ever ($EP_RB_SS)" || bad "RB the installed event processor is not $BASELINE_VERSION\'s: Uganda $EP_RB_UG; South Sudan $EP_RB_SS"',
            '{ [ "$EP_RB_UG" = "$UG_SIGNATURE" ] && [ "$EP_RB_SS" = "$SS_SIGNATURE" ] && [ "$NA_RB_UG|$NA_RB_SS" = "$EP_NOAI_DONE|$EP_NOAI_DONE" ]; } && ok "RB the event processor is $BASELINE_VERSION\'s: on Uganda it leaves the workers\' events to them ($EP_RB_UG), on South Sudan the loop as ever ($EP_RB_SS); a queued assistant-off alert is settled done as unknown on both ($NA_RB_UG) — what a rollback does to one that was waiting" || bad "RB the installed event processor is not $BASELINE_VERSION\'s: Uganda $EP_RB_UG; South Sudan $EP_RB_SS; the assistant-off alert $NA_RB_UG / $NA_RB_SS (expected $EP_NOAI_DONE on both)"',
            why="RB judges the alert's probe")
    # §T.14 DF-4: R6's text check of the processor read the old list literally — the pin's now (EP_PIN_LIST)
    doc.sub('grep -qF "\\$_epWorkerOwned = \\$_epUg ? [\'ai.reply\', \'ai.media\', \'ai.voice_ack\', \'crm.lead.sync\'] : [\'ai.reply\'];" "$DEST/cron/event_processor.php" 2>/dev/null || R6_BAD="$R6_BAD event-processor:lists"',
            'grep -qxF -- "$EP_PIN_LIST" "$DEST/cron/event_processor.php" 2>/dev/null || R6_BAD="$R6_BAD event-processor:lists"   # 5.18.107 (root docs/76 §T.14 DF-4): the pin\'s line, wa.no_ai_alert among Uganda\'s',
            why="R6 reads the pin's list (DF-4)")
    # §T.14 DF-5: VN-CA3's line is "the pin's (from Git)" — re-read off the release commit
    doc.sub("VN_EP_LINE=\"$(cat <<'TXT'\n" + EP_LINE_BASE + "\nTXT\n)\"   # cron/event_processor.php's two lists, as the pin has them (from Git): Uganda's names ai.media and ai.voice_ack\n",
            "VN_EP_LINE=\"$(cat <<'TXT'\n" + ep_pin + "\nTXT\n)\"   # cron/event_processor.php's two lists, as the pin has them (from Git): Uganda's names ai.media and ai.voice_ack, and since 5.18.107 wa.no_ai_alert (root docs/76 §T.14 DF-5)\n",
            why="VN-CA3 reads the pin's line (DF-5)")
    doc.must("EP_PIN_LIST", 6, why="the pin's list: its assignment, A6, R13, R6 and the two comments that name it")
    doc.must('"$EP_BASE_LIST"', 1, why="bf29cbd's list: RB alone")

    # ── B4 — QF-G3's hooks, re-read at the pin (§T.2, CC-4) ──
    hv = const(doc, "QF_HOOKS")
    for e in hv.split():
        f, nd, want, meth = e.split("|")
        got = hooks_count(show(pin, f), nd, meth)
        if got != int(want):
            raise SystemExit(f"PART B: QF_HOOKS' {f}:{meth}:{nd} reads {got} at the pin, not {want}")

    # ── B5 — the text pins in files 5.18.107 changes, computed from the pin by the script's own readers ──
    for c in (base, pin):
        nav = show(c, const(doc, "BK_NAV"))
        blk = bk_block(nav)
        if len(blk) != 9 or hashlib.sha256(("\n".join(blk) + "\n").encode()).hexdigest() != const(doc, "BK_NAV_BLOCK_SHA256"):
            raise SystemExit(f"PART B: {c[:7]}'s bank menu block in navigation.php is not BK_NAV_BLOCK_SHA256's 9 lines")
        href = const(doc, "BK_HREF") if False else 'href="?page=dashboard&tab=bank"'
        nl = lines_of(nav)
        hi = [i for i, l in enumerate(nl) if href in l]
        if len(hi) != 1 or sum(1 for l in nl if "BankGate::showLink(" in l) != 1 \
                or sum(1 for l in nl if re.match(r"^<\?php // 5\.18\.97 ", l)) != 1:
            raise SystemExit(f"PART B: {c[:7]}'s navigation.php does not hold the bank link, its gate and its block once each")
        ob = of_block(nav)
        if hashlib.sha256(("\n".join(ob) + "\n").encode()).hexdigest() != const(doc, "OF_NAV_SHA") or c == pin and not ob:
            if c == pin:
                raise SystemExit("PART B: the pin's offers block in navigation.php is not OF_NAV_SHA's")
        api = show(c, "includes/api/api_whatsapp.php")
        n_ir = sum(1 for l in lines_of(api) if "InboxReplyRoute::decide((string)($conv['channel'] ?? ''), (array)$config, $dataDir, $store->getPdo());" in l)
        sc = show(c, "tools/set_config.php")
        n_sw = sum(1 for l in lines_of(sc) if re.match(r"^    '(sales_own_leads_only|wa_handover_copy_central|wa_followups_on_owned_numbers)' => \['bool',$", l))
        ev = show(c, "lib/EvolutionApiService.php")
        n_pr = sum(1 for l in lines_of(ev) if "        $refusal = $this->policyRefusal($channel, $phone, $class);   // 5.18.90 (docs/65 §AD)" in l)
        if (n_ir, n_sw, n_pr) != (4, 3, 2):
            raise SystemExit(f"PART B: {c[:7]}'s R6 counts are N_IR {n_ir}, N_SW {n_sw}, N_PR {n_pr}, not 4, 3 and 2")
    for k in ("OL_KEY", "HC_KEY", "FH_KEY"):
        if const(doc, k) not in ("sales_own_leads_only", "wa_handover_copy_central", "wa_followups_on_owned_numbers"):
            raise SystemExit(f"PART B: {k} is not one of the three switches R6's N_SW counts")
    tn = {k: const(doc, k).replace("\\$", "$").replace("\\\\", "\\") for k in ("TN_TECH_LINE", "TN_SALES_LINE", "TN_KIND_LINE", "TN_ROLE_LINE")}
    tn_page = re.findall(r"(?m)^TN_PAGE_LINE='([^']*)'$", doc.t)
    if len(tn_page) != 1:
        raise SystemExit("PART B: TN_PAGE_LINE is not assigned once")
    sna = re.findall(r'(?m)^SNA_LIB="([^"]+)"; SNA_PAGE="([^"]+)"$', doc.t)
    if len(sna) != 1:
        raise SystemExit("PART B: SNA_LIB and SNA_PAGE are not assigned once, on their one line")
    sna = sna[0]
    for c in (base, pin):
        lib, page = show(c, sna[0]), show(c, sna[1])
        got = "tech=%d decl=%d sales=%d kind=%d role=%d page=%d old=%d" % (
            lines_of(lib).count(tn["TN_TECH_LINE"]), sum(1 for l in lines_of(lib) if "const TECHNICIAN_ROLES" in l),
            lines_of(lib).count(tn["TN_SALES_LINE"]), lines_of(lib).count(tn["TN_KIND_LINE"]),
            lines_of(lib).count(tn["TN_ROLE_LINE"]), lines_of(page).count(tn_page[0]),
            sum(1 for l in lines_of(lib) + lines_of(page) if "salespeople()" in l))
        if got != const(doc, "TN_TEXT_PIN") or got != const(doc, "TN_TEXT_BASE"):
            raise SystemExit(f"PART B: {c[:7]}'s TN text is {got}, not TN_TEXT_PIN / TN_TEXT_BASE")

    # ── B6 — T1 and T4: their files unchanged; their counts measured on the release commit ──
    t1, t4 = const(doc, "T1_TEST"), const(doc, "T4_TEST")
    for f in [t1, t4] + T1_READS_KEPT + T1_READS_OUT:
        if f in d107:
            raise SystemExit(f"PART B: 5.18.107 changes {f}, which T1 or T4 runs or reads")
        one(repo, plugin, (base, pin), f)
    if [f for f in T1_READS_KEPT if f not in kept] or [f for f in T1_READS_OUT if f in kept]:
        raise SystemExit("PART B: the files T1 reads are not kept as its comment says")
    tabs = sorted(f for f in d107 if f.startswith("tabs/"))
    libs_added = sorted(f for f in added if f.startswith("lib/"))
    if len(tabs) != 5 or len(libs_added) != 9:
        raise SystemExit(f"PART B: 5.18.107 changes {len(tabs)} tab pages and adds {len(libs_added)} lib classes, not §T.2's 5 and 9")
    t1v, t4v = MEASURED["T1_SIGNATURE"][0], MEASURED["T4_SIGNATURE"][0]
    if not re.fullmatch(r"[0-9]+ passed, 0 failed rc=0", t1v) or not re.fullmatch(r"[0-9]+ passed, 0 failed, 0 skipped rc=0", t4v):
        raise SystemExit("PART B: a measured T1 or T4 value is not in its signature's form")
    t1_old = re.findall(r'(?m)^T1_SIGNATURE="([0-9]+ passed, 0 failed rc=0)"$', doc.t)
    t4_old = re.findall(r'(?m)^T4_SIGNATURE="([0-9]+ passed, 0 failed, 0 skipped rc=0)"$', doc.t)
    if len(t1_old) != 1 or len(t4_old) != 1:
        raise SystemExit("PART B: T1_SIGNATURE or T4_SIGNATURE is not the source's one form")
    if t1_old[0] != t1v:
        doc.sub(f'T1_SIGNATURE="{t1_old[0]}"\n', f'T1_SIGNATURE="{t1v}"\n', why="T1's measured count")
    if t4_old[0] != t4v:
        doc.sub(f'T4_SIGNATURE="{t4_old[0]}"\n', f'T4_SIGNATURE="{t4v}"\n', why="T4's measured count")
    doc.subs([
        ("# 5.18.96 — the two of its tests the server runs under its own PHP, kept by 5.18.97 through 5.18.106 (which change\n",
         "# 5.18.96 — the two of its tests the server runs under its own PHP, kept by 5.18.97 through 5.18.107 (which change\n"),
        ("# and mutants_U.php, section U and its weakened copies, and tests/fixtures/r106_revert.php) too — they build their\n"
         "# books, serve the plugin or a fake service on HTTP servers of their own, or start the relay on a port of their own.\n",
         "# and mutants_U.php, section U and its weakened copies, and tests/fixtures/r106_revert.php) and 5.18.107's (its\n"
         "# sections under tests/quote_followup/ and tests/catalogue_direct/, their weakened copies and drivers, and\n"
         "# tests/fixtures/r107_revert.php) too — they build their books, serve the plugin or a fake service on HTTP servers of\n"
         "# their own, or start the relay on a port of their own.\n"),
        ("# the tree judged (4), the allowed list against the SPEC (6). 5.18.106 changes no tab page: the count is 5.18.105's\n"
         "# value, carried — re-measured on the release commit by the rehearsal before pinning (root docs/75 §L.10).\n",
         f"# the tree judged (4), the allowed list against the SPEC (6). 5.18.107 changes {len(tabs)} tab pages and adds "
         f"{len(libs_added)} lib\n# classes, all of which T4 scans: the count is re-measured on the release commit (root "
         "docs/76 §T.2).\n"),
    ], why="T1 and T4 comments")
    t1n = t1v.split(" ")[0]
    doc.lines_between("# Until it is pinned here the script refuses to run (below) rather than accept any count. 5.18.106 changes neither T1 nor",
                      "# anything changes, wherever this server's run does not.",
        "# Until it is pinned here the script refuses to run (below) rather than accept any count. 5.18.107 changes neither T1 nor\n"
        "# any file T1 reads (lib/CashbookService.php, ConfigVault, TenantProfile and SqliteStore, each kept by its sha256 above;\n"
        "# StoreInterface outside the release delta, A0); T1's books are made by SqliteStore::create, so 092 now applies on them\n"
        f"# too. The count ({t1n}) is the release suite's own run of the release commit: a run count, not a fact of Git (root\n"
        "# docs/76 §T.2) — 5.18.107's two release-suite runs say it before the script is pinned and handed over, and A22-T1\n"
        "# refuses, before anything changes, wherever this server's run does not.", why="T1 count comment")
    doc.must("wherever this server's run does not.\nT1_SIGNATURE=\"", 1, why="T1's comment ends on its signature")
    # ── B7 — Q-G1's and Q-CA1's lists (§T.2, §T.6): the stale clause dropped (the Cashbook's post handler and
    #    cron_quote_wa.php are among the kept files, measured: both were in 5.18.106's kept list and stay in 5.18.107's), the
    #    quoting files named as kept without QuoteTaxLine, Q-CA1's counts 5.18.107's, and the catalogue a runtime input that
    #    must decode under the container's PHP (php -l does not read it; Part Q builds from it) ──
    for f in ("includes/post/post_cashbook.php", "cron_quote_wa.php", "lib/UcrmQuoteLinks.php", "lib/UcrmQuoteLines.php",
              "lib/UcrmCatalogue.php", sna[0]):
        if f not in kept or f in d107:
            raise SystemExit(f"PART B: {f} is not among the files 5.18.107 keeps — Q-G1's and Q-CA1's texts would be wrong")
    if sna[1] not in changed or "assets/shop/catalogue.json" not in changed:
        raise SystemExit("PART B: SNA_PAGE or assets/shop/catalogue.json is not a file 5.18.107 changes")
    doc.sub("the cash-in-hand tool, the quoting files $EXPECTED_VERSION keeps (QuoteOrganization, QuoteTaxLine, TenantProfile and PublicPriceFeed among them), 5.18.100's owner rule and the three files it reads — carry theirs; the Cashbook's post handler and cron_quote_wa.php are not among them: they are among the $G1_C it changes, only in the log lines it masks\"",
            "the cash-in-hand tool, the quoting files $EXPECTED_VERSION keeps (QuoteOrganization, TenantProfile and PublicPriceFeed among them), the Cashbook's post handler and cron_quote_wa.php, master, the switch checks and the migration runner, 5.18.100's owner rule and the three files it reads — carry theirs\"",
            why="Q-G1's ok line (§T.2)")
    doc.lines_between("# Q-CA1 — 5.18.99 (SPEC-quote-company §6), carried: the installed files are this release's by the sha256 THIS SCRIPT pins",
                      "# run on the server). 5.18.98's CA1 is this check, carried.",
        "# Q-CA1 — 5.18.99 (SPEC-quote-company §6), carried: the installed files are this release's by the sha256 THIS SCRIPT pins\n"
        f"# for each of its {n_pin} (R1 compared them with Git), the {n_a} it adds among them (nine lib classes, migrations/092 and its\n"
        f"# tests) and the {n_c} it changes; the {n_k} 5.18.106 files this release must not touch — ECO Bank's module, its link on the\n"
        "# CEO page, the Cashbook's service, its page, its tab, its export, its routes and its post handler, api/index.php,\n"
        "# cron_quote_wa.php, the quoting files 5.18.107 keeps (QuoteOrganization, TenantProfile, PublicPriceFeed,\n"
        "# UcrmQuoteLinks, UcrmQuoteLines, UcrmCatalogue), lib/SalesNumbersAdmin.php, master, the switch checks, the migration\n"
        "# runner and the rest — are still 5.18.106's by theirs, and master.php and main.php are the reviewed ones byte for byte;\n"
        "# php -l, under the container's own PHP, passes on every changed or new PHP file as installed (the test files said\n"
        "# apart: copied, never run on the server); and the installed assets/shop/catalogue.json, which php -l does not read and\n"
        "# Part Q builds from, decodes to an array under the container's PHP (root docs/76 §T.2). 5.18.98's CA1 is this check,\n"
        "# carried.", why="Q-CA1's comment")
    doc.sub('  else case "$ca1_rel" in tests/*) CA1_T="$CA1_T $ca1_rel" ;; *) CA1_BAD="$CA1_BAD php-l:$ca1_rel" ;; esac; fi\ndone\n',
            '  else case "$ca1_rel" in tests/*) CA1_T="$CA1_T $ca1_rel" ;; *) CA1_BAD="$CA1_BAD php-l:$ca1_rel" ;; esac; fi\ndone\n'
            '# 5.18.107 (root docs/76 §T.2): the shop catalogue decodes to an array under the container\'s PHP — its count, never its content\n'
            'CA1_CAT="$(docker exec -i "$CONTAINER" php -r \'$d = json_decode((string)stream_get_contents(STDIN), true); echo is_array($d) ? "array:" . count($d) : "not-an-array";\' < "$DEST/assets/shop/catalogue.json" 2>/dev/null)"\n'
            'case "$CA1_CAT" in array:[0-9]*) ;; *) CA1_BAD="$CA1_BAD assets/shop/catalogue.json:${CA1_CAT:-unread}" ;; esac\n',
            why="Q-CA1 decodes the catalogue")
    doc.sub("the quoting files $EXPECTED_VERSION keeps (QuoteOrganization, QuoteTaxLine, TenantProfile, PublicPriceFeed), $SNA_LIB, its page and its readers and the rest — still $BASELINE_VERSION's by theirs (the Cashbook's post handler and cron_quote_wa.php are not among them: $EXPECTED_VERSION changes them only in the log lines it masks, each by the sha256 this script pins, and LM-CA1 counts those lines); $MASTER and $MAIN the reviewed ones byte for byte; PHP $PHPV in the container accepts $CA1_L of its changed or new PHP files as installed\"",
            "the quoting files $EXPECTED_VERSION keeps (QuoteOrganization, TenantProfile, PublicPriceFeed), $SNA_LIB and its readers, the Cashbook's post handler and cron_quote_wa.php, the switch checks, the migration runner and the rest — still $BASELINE_VERSION's by theirs; $MASTER and $MAIN the reviewed ones byte for byte; PHP $PHPV in the container accepts $CA1_L of its changed or new PHP files as installed; assets/shop/catalogue.json decodes ($CA1_CAT)\"",
            why="Q-CA1's ok line")
    for s in ("which 5.18.105 and 5.18.106 keep byte for byte:", "kept by 5.18.97 through 5.18.106", "kept by 5.18.105 and 5.18.106: on Uganda",
              "5.18.106 changes no tab page", "5.18.106 changes neither T1", "QuoteOrganization, QuoteTaxLine, TenantProfile",
              "the four follow-up jobs and lib/FollowUpService.php, which $EXPECTED_VERSION changes", "— and no migration (5.18.104's"):
        doc.mustnot(s, why="no 5.18.106-only carried wording left in partB's places")
