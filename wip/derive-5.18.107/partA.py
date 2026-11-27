# Part A — identity (root docs/76 §T.2): the pin (one commit on bf29cbd, 5.18.107, release/5.18.107), the baseline
# (bf29cbd, 5.18.106, live since 22 Nov), the run's folder (/root/dnb-5.18.107: OUT and every literal path that means
# it), the three sha256 lists and the delta's counts, the chain of earlier state files shifted by one and gaining a tenth
# entry (PREV_STATE moves by itself; PREV2 = 5.18.105 … PREV9 = 5.18.98, and PREV10 = 5.18.97 added so that 5.18.97's
# STRAY_Q_FAILED record is not dropped — the reason 5.18.106 added PREV9), A14's loop, its three messages, its ok line
# (which now names the whole chain), its comments and the header's state-file paths following, COPY_MARK kept on
# cron/followup_send.php (in 5.18.107's delta and at bf29cbd), QC-RB1's words and control, and the constants' comments
# that name a release. Modelled on wip/derive-5.18.106/partA.py. Every list, count and sha256 is read off Git (bf29cbd →
# the pin) and off the source's own lists, never typed; the numbers below are §T.2's, asserted equal to what Git gives,
# never written from them:
#   PREV_KEPT_SHA256S    (5.18.106's kept ∪ its pinned) − 5.18.107's delta, at bf29cbd's sha, asserted identical at the
#                        pin: 416 — 5.18.106's 455 kept less the 46 that 5.18.107 changes, its 23 pinned less the 16
#   PREV_CHANGED_SHA256S exactly 5.18.107's 75 changed files, at bf29cbd's sha (Q-G1 reads them as live 5.18.106; RB puts
#                        them back): 46 of 5.18.106's kept, 16 of its delta, 13 in neither (§T.15: the re-cut adds 5 kept)
#   PIN_SHA256S          5.18.107's 115 at the pin: 75 changed, 40 added (30 tests, nine lib classes and migrations/092 —
#                        asserted)
# The source's own lists are asserted first — 455, 23 and 20, each file at the sha Git gives it (7835906's for the kept
# and the changed, bf29cbd's for the pinned), its EXPECTED_ADDED and EXPECTED_CHANGED Git's 7835906 → bf29cbd delta — so
# a different source is refused.
import re, subprocess, textwrap
from d107lib import sha_at, delta, git

N_KEPT_106, N_PIN_106, N_CHANGED_106 = 455, 23, 20      # 5.18.106's own lists, as its derivation left them (asserted)
N_ADDED_106_SRC = 3                                     # 5.18.106's own added files (tests only)
N_FROM_KEPT, N_FROM_PIN, N_NEITHER, N_KEPT_107 = 46, 16, 13, 416   # §T.2, §T.15: the 75 changed are 46 kept, 16 delta, 13 neither
N_ADDED_107, N_CHANGED_107 = 40, 75                     # §T.2, §T.15: the delta at the pin
N_RT_CHANGED, N_RT_ADDED = 36, 10                       # §T.2: 46 of the 115 are runtime files (the re-cut changed tests only)
VERSIONS = ("5.18.105", "5.18.106", "5.18.107")         # the source's baseline, the source, the pin (asserted)
# §T.2: the nine lib classes and the one migration 5.18.107 adds; every other added file is a test
LIB_ADDED = ["lib/BurstMerge.php", "lib/ConnectionQuote.php", "lib/NoAiAlert.php", "lib/NoAiGuard.php", "lib/NoAiList.php",
             "lib/QuoteInPlay.php", "lib/QuotedAlert.php", "lib/QuotedFloor.php", "lib/TopicRule.php"]
MIG_ADDED = "migrations/092_wa_no_ai.sql"
COPY_MARK_107 = "cron/followup_send.php"                # §T.2: kept — in 5.18.107's delta and at bf29cbd
# §T.2: QC-RB1's words are the 40 basenames, read off Git; two are qualified by their folder so that no word is part of
# another and none names a bf29cbd file: mutants_K (as a bare word it names five bf29cbd files under tests/wa_offers, §T.2)
# and mutants_W (catalogue_direct's, part of quote_followup's mutants_WB — found at derivation, root docs/76 §T.14 DF-3)
QC_QUALIFY = {"mutants_K": "quote_followup/mutants_K", "mutants_W": "catalogue_direct/mutants_W"}
QC_CONTROL = "tests/fixtures/r107_revert.php"
# the loaders at bf29cbd that reach the added files by glob, never by name: the two test runners and the migration runner
QC_TEST_GLOBS = {
    "tests/test_quote_followup.php": [
        "foreach (glob(__DIR__ . '/quote_followup/*.php') ?: [] as $f) {",
        "    if (preg_match('/^[A-Z]{1,2}_[a-z0-9_]+\\.php$/', basename($f))) require $f;",
        "    foreach (glob(__DIR__ . '/quote_followup/mutants_*.php') ?: [] as $f) {",
        "        if (preg_match('/^mutants_[A-Z]{1,2}\\.php$/', basename($f))) require $f;"],
    "tests/test_catalogue_direct.php": [
        "foreach (glob(__DIR__ . '/catalogue_direct/[A-Z]_*.php') ?: [] as $f) require $f;",
        "    foreach (glob(__DIR__ . '/catalogue_direct/mutants_[A-Z].php') ?: [] as $f) require $f;"],
}
QC_MIG_GLOB = ("lib/MigrationRunner.php", "        $files = glob($this->migrationsDir . '/*.sql');")
# what stays held by sha256 in the new kept list (each at bf29cbd's sha, identical at the pin) — §T.2's "unchanged by
# 5.18.107, re-asserted from Git": master, main, MigrationRunner and SqliteStore, SalesFollowupChecks (lm_w2_strings reads
# it), 089–091, the report wrapper's seven inputs, T1's files, the loaders above, QC-RB1's control's predecessor, and
# QF-G3's send-site table (tests/test_wa_send_budget.php)
HELD = ["cron/master.php", "lib/MigrationRunner.php", "lib/SqliteStore.php", "lib/SalesFollowupChecks.php",
        "migrations/089_quote_followups.sql", "migrations/090_wa_voice.sql", "migrations/091_wa_offers.sql",
        "lib/QuoteDailyList.php", "tools/sales_followup_report.php", "lib/QuoteSendEvidence.php", "lib/PhoneNumber.php",
        "lib/TenantProfile.php", "lib/PluginConfig.php", "lib/ConfigVault.php", "lib/ContactOptOut.php",
        "lib/CashbookService.php", "tests/test_quote_followup.php",
        "tests/test_catalogue_direct.php", "tests/test_wa_send_budget.php", "webhook.php", "lib/PluginLog.php",
        "cron/followup_close.php", "cron/followup_run.php", "lib/FollowUpService.php", "lib/FollowUpPolicy.php"]
# held by constants of their own, not by the kept list (MAIN_PIN_SHA256, T1's own file): never in the delta either
HELD_ELSEWHERE = ["main.php", "tests/test_cash_in_hand_p3b.php"]
# what leaves the kept list (§T.2: changed by 5.18.107, so re-pointed): each must be in 5.18.107's delta
LEAVES = ["lib/QuoteTaxLine.php", "cron/event_processor.php", "lib/NotificationService.php", "lib/SalesFollowupLadder.php",
          "tools/sales_followup.php", "evo_webhook.php", "cron/wa_watchdog.php"]
# the files the carried comments below speak for as unchanged (BK3's CEO page and More menu, the cash-in-hand tool, the
# Cashbook, master): none may be in 5.18.107's delta
NOT_IN_DELTA = ["tabs/admin/ceo_dashboard.php", "tabs/sales/more_menu.php", "tools/cash_in_hand.php", "lib/CashbookService.php",
                "cron/master.php"]
NUM = {6: "six", 7: "seven", 8: "eight", 9: "nine", 10: "ten"}


def const(doc, name):
    got = re.findall(rf'(?m)^{name}="([^"]*)"', doc.t)
    if len(got) != 1:
        raise SystemExit(f"PART A: {name} is assigned {len(got)} times in the source")
    return got[0]


def count_const(doc, name):
    got = re.findall(rf"(?m)^{name}=([0-9]+)$", doc.t)
    if len(got) != 1:
        raise SystemExit(f"PART A: {name} is assigned {len(got)} times in the source")
    return int(got[0])


def block(doc, name):
    """The (sha256, file) pairs of one SUMS heredoc, in order; every line must be '<sha256>  <file>'."""
    t = doc.t
    head = name + '="$(cat <<\'SUMS\'\n'
    if t.count(head) != 1:
        raise SystemExit(f"PART A: {name}'s heredoc found {t.count(head)} times")
    i = t.index(head) + len(head)
    j = t.index("\nSUMS\n)\"", i)
    out = []
    for ln in t[i:j].split("\n"):
        s, sep, f = ln.partition("  ")
        if not sep or not re.fullmatch(r"[0-9a-f]{64}", s) or not f or " " in f:
            raise SystemExit(f"PART A: {name} holds a line that is not '<sha256>  <file>': {ln[:100]!r}")
        out.append((s, f))
    return out


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


def sums(repo, commit, plugin, files):
    return "\n".join(f"{sha_at(repo, commit, plugin + '/' + f)}  {f}" for f in files)


def wrap(text, lead):
    """Comment lines of at most 120 characters, each starting with lead; no word is broken."""
    out = textwrap.wrap(text, width=120, initial_indent=lead, subsequent_indent=lead, break_long_words=False,
                        break_on_hyphens=False)
    if any(len(ln) > 120 for ln in out):
        raise SystemExit(f"PART A: a comment line is longer than 120 characters: {[l for l in out if len(l) > 120][0]!r}")
    return "\n".join(out)


def flat(t):
    """The text with every comment line's continuation joined to the line before it, as one reads a paragraph."""
    return re.sub(r"\n#\s+", " ", t)


def enum(items, last):
    return ", ".join(items[:-1]) + f" {last} " + items[-1] if len(items) > 1 else items[0]


def is_runtime(f):
    return not f.startswith("tests/") and f not in ("SAFETY.md", "manifest.json")


def apply(doc, ctx):
    repo, plugin, base, pin = ctx["repo"], ctx["plugin"], ctx["base_full"], ctx["pin_full"]

    # ── the source: 5.18.106's script, pinned to bf29cbd, cut on its own baseline ──
    v_older, v_old = const(doc, "BASELINE_VERSION"), const(doc, "EXPECTED_VERSION")
    vs = re.findall(r'"version":\s*"(5\.[0-9.]+)"', git(repo, "show", f"{pin}:{plugin}/manifest.json").decode())
    if len(vs) != 1:
        raise SystemExit(f"PART A: the pin's manifest.json names {len(vs)} release versions")
    v_new = vs[0]
    if (v_older, v_old, v_new) != VERSIONS:
        raise SystemExit(f"PART A: the versions are {v_older}/{v_old}/{v_new}, not {'/'.join(VERSIONS)}")
    if const(doc, "EXPECTED_PLUGIN_COMMIT_FULL") != base or const(doc, "EXPECTED_PLUGIN_COMMIT") != base[:7]:
        raise SystemExit("PART A: the source is not pinned to bf29cbd — it is not 5.18.106's script")
    base105 = const(doc, "BASELINE_COMMIT_FULL")
    if git(repo, "rev-parse", base + "^").decode().strip() != base105 or const(doc, "BASELINE_COMMIT") != base105[:7]:
        raise SystemExit("PART A: bf29cbd's parent is not the source's own baseline")
    if git(repo, "rev-parse", pin + "^").decode().strip() != base:
        raise SystemExit("PART A: the pin is not one commit on bf29cbd")
    for c in (base, pin):          # the short hashes the script carries name these commits and no other
        r = subprocess.run(["git", "-C", repo, "rev-parse", "-q", "--verify", c[:7] + "^{commit}"], capture_output=True)
        if r.returncode != 0 or r.stdout.decode().strip() != c:
            raise SystemExit(f"PART A: {c[:7]} does not name {c} alone")
    if const(doc, "RELEASE_BRANCH") != f"release/{v_old}":
        raise SystemExit("PART A: the source's RELEASE_BRANCH is not release/5.18.106")

    # ── the source's own lists: 455 kept, 23 pinned, 20 changed, each as Git gives it ──
    kept106, pin106, chg106 = block(doc, "PREV_KEPT_SHA256S"), block(doc, "PIN_SHA256S"), block(doc, "PREV_CHANGED_SHA256S")
    got = (len(kept106), len(pin106), len(chg106))
    if got != (count_const(doc, "PREV_KEPT_N"), count_const(doc, "PIN_SHA_N"), count_const(doc, "PREV_CHANGED_N")) \
            or got != (N_KEPT_106, N_PIN_106, N_CHANGED_106):
        raise SystemExit(f"PART A: 5.18.106's lists are {got[0]}/{got[1]}/{got[2]}, not {N_KEPT_106}/{N_PIN_106}/{N_CHANGED_106} "
                         "(or its counts constants say otherwise)")
    a106, c106 = delta(repo, base105, base, plugin)
    if (len(a106), len(c106)) != (N_ADDED_106_SRC, N_CHANGED_106):
        raise SystemExit(f"PART A: Git's 7835906 → bf29cbd delta is {len(a106)} added and {len(c106)} changed, not 3 and 20")
    kf, pf, cf = [f for _, f in kept106], [f for _, f in pin106], [f for _, f in chg106]
    if pf != sorted(c106) + sorted(a106) or sorted(cf) != sorted(c106) \
            or const(doc, "EXPECTED_ADDED").split() != sorted(a106) or const(doc, "EXPECTED_CHANGED").split() != sorted(c106):
        raise SystemExit("PART A: the source's PIN_SHA256S, PREV_CHANGED_SHA256S, EXPECTED_ADDED or EXPECTED_CHANGED is not "
                         "Git's 7835906 → bf29cbd delta")
    for s, f in pin106:
        if sha_at(repo, base, f"{plugin}/{f}") != s:
            raise SystemExit(f"PART A: the source pins {f} at a sha bf29cbd does not give it")
    for s, f in chg106:
        if sha_at(repo, base105, f"{plugin}/{f}") != s:
            raise SystemExit(f"PART A: the source's PREV_CHANGED holds {f} at a sha 7835906 does not give it")
    for s, f in kept106:
        if sha_at(repo, base105, f"{plugin}/{f}") != s:
            raise SystemExit(f"PART A: the source's PREV_KEPT holds {f} at a sha 7835906 does not give it")
    if set(kf) & set(pf):
        raise SystemExit(f"PART A: a file is both kept and pinned in the source: {sorted(set(kf) & set(pf))[:3]}")

    # ── 5.18.107's delta, from Git ──
    added, changed = delta(repo, base, pin, plugin)
    d107 = set(added) | set(changed)
    if (len(added), len(changed)) != (N_ADDED_107, N_CHANGED_107):
        raise SystemExit(f"PART A: Git reports {len(added)} added and {len(changed)} changed, not {N_ADDED_107} and {N_CHANGED_107}")
    if sorted(f for f in added if not f.startswith("tests/")) != sorted(LIB_ADDED + [MIG_ADDED]):
        raise SystemExit(f"PART A: 5.18.107's added files outside tests/ are not §T.2's nine lib classes and 092: "
                         f"{sorted(f for f in added if not f.startswith('tests/'))}")
    if [f for f in changed if f.startswith("migrations/")]:
        raise SystemExit("PART A: 5.18.107 changes a migration (root docs/76 §T.3: 092 is added, never modified; none changed)")
    rt_c, rt_a = [f for f in changed if is_runtime(f)], [f for f in added if is_runtime(f)]
    if (len(rt_c), len(rt_a)) != (N_RT_CHANGED, N_RT_ADDED):
        raise SystemExit(f"PART A: 5.18.107's runtime files are {len(rt_c)} changed and {len(rt_a)} added, not "
                         f"{N_RT_CHANGED} and {N_RT_ADDED}")
    for f in NOT_IN_DELTA + HELD + HELD_ELSEWHERE:
        if f in d107:
            raise SystemExit(f"PART A: 5.18.107 changes {f} — a carried comment or a held file would be wrong")
    for f in LEAVES:
        if f not in changed:
            raise SystemExit(f"PART A: {f} is not a file 5.18.107 changes (root docs/76 §T.2 says it leaves the kept list)")
    from_kept = [f for f in kf if f in d107]
    from_pin = [f for f in pf if f in d107]
    neither = [f for f in changed if f not in kf and f not in pf]
    if (len(from_kept), len(from_pin), len(neither)) != (N_FROM_KEPT, N_FROM_PIN, N_NEITHER) \
            or sorted(from_kept + from_pin + neither) != sorted(changed):
        raise SystemExit(f"PART A: the changed files are {len(from_kept)} of 5.18.106's kept, {len(from_pin)} of its delta and "
                         f"{len(neither)} in neither (not {N_FROM_KEPT}, {N_FROM_PIN} and {N_NEITHER}), or one is counted twice")
    if any(f in kf or f in pf for f in added):
        raise SystemExit("PART A: an added file is listed by 5.18.106")
    kept_a = [f for f in kf if f not in d107]
    kept_b = [f for f in pf if f not in d107]
    kept_all = kept_a + kept_b
    if len(kept_all) != N_KEPT_107 or len(set(kept_all)) != len(kept_all) \
            or len(kept_b) != N_PIN_106 - N_FROM_PIN or len(kept_a) != N_KEPT_106 - N_FROM_KEPT:
        raise SystemExit(f"PART A: the new kept list holds {len(kept_all)} files ({len(set(kept_all))} distinct), not "
                         f"({N_KEPT_106} − {N_FROM_KEPT}) + ({N_PIN_106} − {N_FROM_PIN}) = {N_KEPT_107}")
    for f in HELD:
        if f not in kept_all:
            raise SystemExit(f"PART A: {f} is not in the new kept list (root docs/76 §T.2)")
    # A0's own rule, asserted here first: a kept file is the same file in both release commits
    for f in kept_all:
        if sha_at(repo, base, f"{plugin}/{f}") != sha_at(repo, pin, f"{plugin}/{f}"):
            raise SystemExit(f"PART A: kept file differs between {base[:7]} and the pin: {f}")
    for f in kept_a:               # 5.18.106 kept them too: 7835906's bytes still
        if sha_at(repo, base105, f"{plugin}/{f}") != sha_at(repo, base, f"{plugin}/{f}"):
            raise SystemExit(f"PART A: a file of 5.18.106's kept list differs between 7835906 and bf29cbd: {f}")
    for f in changed:
        if sha_at(repo, base, f"{plugin}/{f}") == sha_at(repo, pin, f"{plugin}/{f}"):
            raise SystemExit(f"PART A: Git calls {f} changed, but its bytes are the same")
    for f in added:
        r = subprocess.run(["git", "-C", repo, "cat-file", "-e", f"{base}:{plugin}/{f}"], capture_output=True)
        if r.returncode == 0:
            raise SystemExit(f"PART A: an added file exists at {base[:7]}: {f}")
    for f in kept_all + changed + added:
        if any(ch in f for ch in " *?[]"):
            raise SystemExit(f"PART A: a path A0 would word-split wrongly: {f!r}")
    if COPY_MARK_107 not in changed:
        raise SystemExit(f"PART A: {COPY_MARK_107} is not a file 5.18.107 changes")
    n_a, n_c, n_k = len(added), len(changed), len(kept_all)
    ctx.update(added=added, changed=changed, kept=kept_all, n_added=n_a, n_changed=n_c, n_kept=n_k, n_pin=n_a + n_c,
               from_kept=from_kept, from_pin=from_pin, neither=neither, runtime_changed=rt_c, runtime_added=rt_a,
               base105=base105, v_older=v_older, v_old=v_old, v_new=v_new,
               n106=(len(kf), len(pf), len(cf), len(a106)))

    # ── the three lists and their counts ──
    replace_block(doc, "PREV_KEPT_SHA256S", sums(repo, base, plugin, kept_all))
    replace_block(doc, "PREV_CHANGED_SHA256S", sums(repo, base, plugin, sorted(changed)))
    replace_block(doc, "PIN_SHA256S", sums(repo, pin, plugin, sorted(changed) + sorted(added)))
    doc.sub(f"PREV_KEPT_N={N_KEPT_106}\n", f"PREV_KEPT_N={n_k}\n", why="PREV_KEPT_N")
    doc.sub(f"PREV_CHANGED_N={N_CHANGED_106}\n", f"PREV_CHANGED_N={n_c}\n", why="PREV_CHANGED_N")
    doc.sub(f"PIN_SHA_N={N_PIN_106}\n", f"PIN_SHA_N={n_a + n_c}\n", why="PIN_SHA_N")
    doc.line('EXPECTED_ADDED="', 'EXPECTED_ADDED="' + " ".join(sorted(added)) + '"', why="EXPECTED_ADDED")
    doc.line('EXPECTED_CHANGED="', 'EXPECTED_CHANGED="' + " ".join(sorted(changed)) + '"', why="EXPECTED_CHANGED")

    # ── the pin and the baseline ──
    doc.line(f'EXPECTED_PLUGIN_COMMIT="{base[:7]}"',
             f'EXPECTED_PLUGIN_COMMIT="{pin[:7]}"   # 5.18.107 (release) — on Uganda, the quotation quoted, no AI where asked, the burst merged (root docs/76): {len(rt_c)} runtime files changed and {len(rt_a)} added (nine lib classes and migrations/092_wa_no_ai.sql, the assistant-off list, applied at the first boot); three new switches (K, Q, W.1), off at the deploy (NS-G1, NS-B1) and turned on one at a time by the owner; the deploy and its rollback write no setting',
             why="EXPECTED_PLUGIN_COMMIT")
    doc.line(f'EXPECTED_PLUGIN_COMMIT_FULL="{base}"',
             f'EXPECTED_PLUGIN_COMMIT_FULL="{pin}"   # the same commit in full: A0 refuses a short hash that names any other',
             why="EXPECTED_PLUGIN_COMMIT_FULL")
    doc.sub(f'EXPECTED_VERSION="{v_old}"\n', f'EXPECTED_VERSION="{v_new}"\n', why="EXPECTED_VERSION")
    doc.line(f'BASELINE_COMMIT="{base105[:7]}"',
             f'BASELINE_COMMIT="{base[:7]}"          # 5.18.106 (release, the enquiry follow-up sender unjammed; live since 22 Nov 05:48:21 UTC, its --after-only PASSED on 22 and 23 Nov — root docs/75 §S) — what the server runs first, and the rollback (every switch kept; the rollback writes nothing); G3 and V3j require ECO Bank\'s switch ON and a statement kept on it, as 5.18.106\'s did',
             why="BASELINE_COMMIT")
    doc.sub(f'BASELINE_COMMIT_FULL="{base105}"\n', f'BASELINE_COMMIT_FULL="{base}"\n', why="BASELINE_COMMIT_FULL")
    doc.sub(f'BASELINE_VERSION="{v_older}"\n', f'BASELINE_VERSION="{v_old}"\n', why="BASELINE_VERSION")
    doc.sub("R5 checks every file from Release A through 5.18.105 is installed as pinned",
            "R5 checks every file from Release A through 5.18.106 is installed as pinned", why="RELEASE_A_BASE")
    doc.sub('RELEASE_BRANCH="release/5.18.106"  #', 'RELEASE_BRANCH="release/5.18.107"  #', why="RELEASE_BRANCH")

    # ── the run's folder: OUT, and every literal path that means it ──
    doc.sub('OUT="${DNB_OUT:-/root/dnb-5.18.106}"', 'OUT="${DNB_OUT:-/root/dnb-5.18.107}"', why="OUT")
    doc.subs([
        ("#            /root/dnb-5.18.106/cash-<mode>-<UTC stamp>.txt, mode 0600, in a folder of mode 700. It holds the book's cash in\n",
         "#            /root/dnb-5.18.107/cash-<mode>-<UTC stamp>.txt, mode 0600, in a folder of mode 700. It holds the book's cash in\n"),
        ("a key kept in /root/dnb-5.18.106/lk-key-5.18.106 (mode 0600)", "a key kept in /root/dnb-5.18.107/lk-key-5.18.107 (mode 0600)"),
        ("#   cd /opt/dishnet && git pull origin claude/study-this-jhe2eg && git fetch origin release/5.18.106 && mkdir -p /root/dnb-5.18.106 && bash scripts/deploy-5.18.106.sh 2>&1 | ( umask 077; exec tee /root/dnb-5.18.106/deploy-$(date -u +%Y%m%dT%H%M%SZ).log )\n",
         "#   cd /opt/dishnet && git pull origin claude/study-this-jhe2eg && git fetch origin release/5.18.107 && mkdir -p /root/dnb-5.18.107 && bash scripts/deploy-5.18.107.sh 2>&1 | ( umask 077; exec tee /root/dnb-5.18.107/deploy-$(date -u +%Y%m%dT%H%M%SZ).log )\n"),
        ("#   cd /opt/dishnet && bash scripts/deploy-5.18.106.sh --after-only 2>&1 | ( umask 077; exec tee /root/dnb-5.18.106/after-$(date -u +%Y%m%dT%H%M%SZ).log )\n",
         "#   cd /opt/dishnet && bash scripts/deploy-5.18.107.sh --after-only 2>&1 | ( umask 077; exec tee /root/dnb-5.18.107/after-$(date -u +%Y%m%dT%H%M%SZ).log )\n"),
        ("a backup under /root/dnb-5.18.106/,", "a backup under /root/dnb-5.18.107/,"),
        ("# The run's cash sheet, on the host: /root/dnb-5.18.106/cash-<mode>-<stamp>.txt, mode 0600, in a folder of mode 700 — the\n",
         "# The run's cash sheet, on the host: /root/dnb-5.18.107/cash-<mode>-<stamp>.txt, mode 0600, in a folder of mode 700 — the\n"),
    ], why="the run's folder")

    # ── the kept list's prose, and the other lists' ──
    kept_prose = "\n".join([
        "# 5.18.107 (Q-G1, Q-CA1, RB) — the files of 5.18.106 this release must leave exactly as they are, each with the sha256",
        f"# release/5.18.106 ({base[:7]}) gives it, pinned here so that no other 5.18.106 can pass for it (root docs/76 §T.2):",
        wrap(f"the {len(kept_a)} of 5.18.106's own kept list that 5.18.107 does not change — all but the {len(from_kept)} it does, "
             "lib/QuoteTaxLine.php, cron/event_processor.php, lib/NotificationService.php, lib/SalesFollowupLadder.php, "
             "tools/sales_followup.php, evo_webhook.php and cron/wa_watchdog.php among them — then the "
             f"{len(kept_b)} of 5.18.106's own release files that 5.18.107 does not change — all but the {len(from_pin)} it "
             f"does ({', '.join(from_pin)}): cron/followup_close.php, cron/followup_run.php and lib/FollowUpService.php "
             "among them, so that what 5.18.106 put live is held by its sha256 and not only by the delta's; cron/master.php, "
             "lib/SalesFollowupChecks.php, lib/MigrationRunner.php, migrations 089–091, webhook.php and the report's seven "
             "inputs stay among the first. A0 finds them in both release commits from Git; Q-G1 installed before anything "
             "changes; Q-CA1 installed after the copy; RB after a rollback.", "# ")])
    doc.lines_between("# 5.18.106 (Q-G1, Q-CA1, RB) — the files of 5.18.105 this release must leave exactly as they are, each with the sha256",
                      "# the copy; RB after a rollback.", kept_prose, why="kept prose")
    doc.must("rollback.\nPREV_KEPT_SHA256S=\"$(cat <<'SUMS'\n", 1, why="kept prose ends on the list")
    if flat(doc.t).count("Q-CA1 installed after the copy; RB after a rollback.\nPREV_KEPT_SHA256S=") != 1:
        raise SystemExit("PART A: the kept list's prose does not end on its last sentence")
    doc.lines_between("# 5.18.106 (Q-G1, RB) — every file 5.18.106 changes, with the sha256 release/5.18.105 (7835906) gives it: what the live",
                      "# listed here).",
        wrap(f"5.18.107 (Q-G1, RB) — every file 5.18.107 changes, with the sha256 release/5.18.106 ({base[:7]}) gives it: what "
             "the live plugin must hold before anything changes (Q-G1: live 5.18.106), and what a rollback must put back (RB). "
             f"A0 finds them in 5.18.106's release commit from Git: {len(from_kept)} of them were in 5.18.106's kept list, "
             f"{len(from_pin)} in its delta and {len(neither)} in neither. The {n_a} files 5.18.107 adds ({n_a - len(LIB_ADDED) - 1} tests, nine lib "
             "classes and migrations/092_wa_no_ai.sql) have no 5.18.106 form (A0 refuses one listed here).", "# "),
        why="changed prose")
    doc.must("listed here).\nPREV_CHANGED_SHA256S=\"$(cat <<'SUMS'\n", 1, why="changed prose ends on the list")
    doc.lines_between(f"# 5.18.106 — the release's own files, each with the sha256 the release commit ({base[:7]}) gives it: the delta,",
                      f"# exactly, its {len(a106)} added files (tests only) among them — A0 reads them in Git, R1 and Q-CA1 installed.",
        f"# 5.18.107 — the release's own files, each with the sha256 the release commit ({pin[:7]}) gives it: the delta,\n"
        f"# exactly, its {n_a} added files (nine lib classes, migrations/092_wa_no_ai.sql and {n_a - len(LIB_ADDED) - 1} tests) among them — A0 reads\n"
        "# them in Git, R1 and Q-CA1 installed.", why="pin prose")
    doc.must("R1 and Q-CA1 installed.\nPIN_SHA256S=\"$(cat <<'SUMS'\n", 1, why="pin prose ends on the list")
    doc.lines_between(f"# The release's own files, and nothing else: {len(a106)} added, {len(c106)} changed — {len(a106) + len(c106)}, each by its sha256",
                      "# holds a space or a glob character.",
        f"# The release's own files, and nothing else: {n_a} added, {n_c} changed — {n_a + n_c}, each by its sha256\n"
        "# (PIN_SHA256S). Anything outside this list in the pin is another build. A0 word-splits both lists: no path in them\n"
        "# holds a space or a glob character.", why="delta prose")
    doc.must("# holds a space or a glob character.\nEXPECTED_ADDED=\"", 1, why="delta prose ends on the lists")

    # ── the earlier state files: PREV_STATE is $BASELINE_VERSION's by itself; one more release back ──
    doc.must('PREV_STATE="${DNB_PREV_OUT:-${DNB_RECORDS:-/root}/dnb-$BASELINE_VERSION}/state-$BASELINE_VERSION.env"\n', 1)
    old = re.findall(r'(?m)^PREV([0-9]+)_VERSION="([^"]+)"$', doc.t)
    if [int(n) for n, _ in old] != list(range(2, 10)) or old[-1][1] != "5.18.97" or old[0][1] != "5.18.104":
        raise SystemExit(f"PART A: the source's chain is {old}, not PREV2 = 5.18.104 … PREV9 = 5.18.97")
    for n, v in old:
        if doc.t.count(f'PREV{n}_STATE="${{DNB_PREV{n}_OUT:-${{DNB_RECORDS:-/root}}/dnb-$PREV{n}_VERSION}}/state-$PREV{n}_VERSION.env"\n') != 1:
            raise SystemExit(f"PART A: PREV{n}_STATE is not the source's one form")
    chain = [v_older] + [v for _, v in old]           # PREV2 … PREV10: 5.18.105 … 5.18.97
    if chain[-1] != "5.18.97" or len(chain) != 9:
        raise SystemExit(f"PART A: the new chain is {chain}")
    ctx.update(prev_chain=chain)
    chain_text = "\n".join(f'PREV{i}_VERSION="{v}"\nPREV{i}_STATE="${{DNB_PREV{i}_OUT:-${{DNB_RECORDS:-/root}}/dnb-$PREV{i}_VERSION}}/state-$PREV{i}_VERSION.env"'
                           for i, v in enumerate(chain, 2))
    doc.lines_between("# 5.18.99 — and, since 5.18.102, the releases back to 5.18.97 — since 5.18.106 5.18.104's, 5.18.103's, 5.18.102's,",
                      'PREV9_STATE="${DNB_PREV9_OUT:-${DNB_RECORDS:-/root}/dnb-$PREV9_VERSION}/state-$PREV9_VERSION.env"',
        wrap("5.18.99 — and, since 5.18.102, the releases back to 5.18.97 — since 5.18.107 "
             + enum([v + "'s" for v in chain], "and")
             + f", two to {NUM[len(chain) + 1]} releases back (their R19 and RB write the same line; PREV{len(chain) + 1} keeps "
             "5.18.97's STRAY_Q_FAILED record in reach, as PREV9 did in 5.18.106 — root docs/76 §T.2): a failure any of them "
             "recorded is not forgotten by deploying the releases after it and then this. Read, never written. PREV_STATE, "
             "above, is 5.18.106's (production's COPY_AT of 22 Nov 05:48:21 UTC among its lines).", "# ")
        + "\n" + chain_text, why="prev chain")
    # A14: one more release back, in the loop, in the three messages that name the chain, and in the ok line
    doc.sub('"$PREV8_STATE" "$PREV8_STATE".prev-* "$PREV9_STATE" "$PREV9_STATE".prev-*; do',
            '"$PREV8_STATE" "$PREV8_STATE".prev-* "$PREV9_STATE" "$PREV9_STATE".prev-* "$PREV10_STATE" "$PREV10_STATE".prev-*; do',
            why="A14 loop")
    doc.sub("of $PREV7_VERSION's, of $PREV8_VERSION's or of $PREV9_VERSION's",
            "of $PREV7_VERSION's, of $PREV8_VERSION's, of $PREV9_VERSION's or of $PREV10_VERSION's", 3, why="A14 messages")
    # the ok line named the chain only up to PREV5 while the loop read PREV9 (S106:13216, §T.2): it names all of it now
    doc.sub("no failure recorded by this script's runs, $BASELINE_VERSION's, $PREV2_VERSION's, $PREV3_VERSION's, $PREV4_VERSION's or $PREV5_VERSION's after it",
            "no failure recorded by this script's runs, " + enum(["$BASELINE_VERSION's"] + [f"$PREV{i}_VERSION's" for i in range(2, 11)], "or")
            + " after it", why="A14 ok line names the whole chain")
    doc.must('"$PREV10_STATE" "$PREV10_STATE".prev-*', 1)
    doc.must('PREV10_VERSION="5.18.97"\n', 1)
    doc.mustnot('PREV9_VERSION="5.18.97"')
    for i in range(2, 11):
        doc.must(f'"$PREV{i}_STATE" "$PREV{i}_STATE".prev-*', 1, why="A14 loop reads every link")
    a14_texts = [l for l in doc.t.split("\n") if "NO-GO (A14): a run of this script, of $BASELINE_VERSION's" in l
                 or l.startswith('  [ -z "$Q_FAIL_IN" ] || note "A14 a run of this script') or l.startswith('  ok "A14 the stray store')]
    if len(a14_texts) != 4:
        raise SystemExit(f"PART A: A14's texts that name the chain found {len(a14_texts)} times, not 4")
    for l in a14_texts:            # every A14 text names the same chain the loop reads (§T.2, partZ repeats it)
        for i in range(2, 11):
            if f"$PREV{i}_VERSION's" not in l:
                raise SystemExit(f"PART A: an A14 text does not name $PREV{i}_VERSION: {l[:120]!r}")
    # what follows by itself (PREV_STATE is 5.18.106's): the copy loop and its note
    doc.must('for f in "$PREV_STATE" "$PREV_STATE".prev-*; do\n', 1, why="A14 PREV_COPY loop")
    doc.must('  else note "A14 no copy time of $BASELINE_VERSION\'s deploy is recorded in $PREV_STATE or beside it:', 1)
    # A14's comments: the chain, and the copies the quarantine's rule 3 asks about
    a14_paths = [f"/root/dnb-{v}/state-{v}.env" for v in [v_old] + chain]
    doc.lines_between("# beside it — and in the previous release's own, 5.18.105's, and the eight before, 5.18.104's, 5.18.103's, 5.18.102's,",
                      "# ones kept beside each) — is not",
        wrap(f"beside it — and in the previous release's own, {v_old}'s, and the {NUM[len(chain)]} before, "
             + enum([v + "'s" for v in chain], "and")
             + " (their R19 and RB record the same line, in " + ", ".join(a14_paths)
             + " and the ones kept beside each) — is not", "# "), why="A14 chain comment")
    doc.must("— is not\n# forgotten by deploying again: what showed it may be gone by now,\n", 1)
    if flat(doc.t).count(f"{a14_paths[-1]} and the ones kept beside each) — is not forgotten by deploying again") != 1:
        raise SystemExit("PART A: A14's chain comment does not read on into its next line")
    doc.subs([
        ("# Since 5.18.95 — the quarantine's rule 3: after any plugin upload, rollback or restore, its --check. 5.18.105's deploy is one,\n"
         "# so a deploy goes on only once a --check that began after 5.18.105's latest copy (COPY_AT in its state file, or in one a later\n",
         "# Since 5.18.95 — the quarantine's rule 3: after any plugin upload, rollback or restore, its --check. 5.18.106's deploy is one,\n"
         "# so a deploy goes on only once a --check that began after 5.18.106's latest copy (COPY_AT in its state file, or in one a later\n"),
    ], why="A14 copy comments")
    doc.subs([
        ("# Since 5.18.97 (its review): and after this script's own latest copy of the plugin — a deploy of 5.18.106 (COPY_AT in its\n",
         "# Since 5.18.97 (its review): and after this script's own latest copy of the plugin — a deploy of 5.18.107 (COPY_AT in its\n"),
        ("# or a deploy over a rolled-back 5.18.106, goes on only once a --check that began after that rollback has PASSED — and\n"
         "# 5.18.105's own copy must be followed by a --check begun after it that PASSED (PREV_COPY, above; root docs/75 §L.8).\n",
         "# or a deploy over a rolled-back 5.18.107, goes on only once a --check that began after that rollback has PASSED — and\n"
         "# 5.18.106's own copy (22 Nov 05:48:21 UTC) is followed by the --check begun on 23 Nov at 06:46:27 UTC, which PASSED\n"
         "# (PREV_COPY, above; root docs/75 §S).\n"),
    ], why="A14 own-copy comment")
    # the header's paragraph: the same chain, the same files, rewrapped up to the line that names this script's state file
    doc.lines_between("#            any run of that script holds its folder's lock; while a failure recorded by this script's runs, 5.18.105's,",
                      "#            rollback; a failure is recorded in",
        wrap("any run of that script holds its folder's lock; while a failure recorded by this script's runs, "
             + enum([v + "'s" for v in [v_old] + chain], "or") + " (STRAY_Q_FAILED in "
             + enum([p + "*" for p in a14_paths], "or")
             + ") is newer than the newest passing --check; and unless that --check began after 5.18.106's own copy (COPY_AT in "
             "its state files: the quarantine's rule 3) and after this script's own latest copy — a deploy of 5.18.107 (COPY_AT) "
             "or a rollback of it (RB_COPY_AT, in its rollback-state-*.env), so that a --redeploy after a rollback waits for a "
             "--check after it. Nothing here opens, moves, copies or writes the kept file or anything at the old name. R19 after "
             "the copy, RB after a rollback; a failure is recorded in", "#            "), why="header A14 paths")
    doc.must("recorded in\n#            this script's state file (STRAY_Q_FAILED).\n", 1, why="the header's paragraph goes on into its next line")
    if flat(doc.t).count("a failure is recorded in this script's state file (STRAY_Q_FAILED).") != 1:
        raise SystemExit("PART A: the header's A14 paragraph does not read on into its next line")
    for v in [v_old] + chain:
        doc.must(f"/root/dnb-{v}/state-{v}.env*", 1, why="the header names each state file")
        doc.must(f"/root/dnb-{v}/state-{v}.env,", 1 if v != chain[-1] else 0, why="the A14 comment names each state file")
    # no literal of the source's own folder is left but the state file A14 reads
    for m in re.finditer(r"/root/dnb-5\.18\.106", doc.t):
        if not doc.t.startswith("/root/dnb-5.18.106/state-5.18.106.env", m.start()):
            raise SystemExit(f"PART A: a literal of 5.18.106's folder is left: {doc.t[m.start():m.start() + 60]!r}")
    doc.mustnot("dnb-5.18.106/lk-key")

    # ── the copy's time mark: kept on cron/followup_send.php, a file 5.18.107 changes too ──
    doc.subs([
        ("# The copy's time is the time stamp_copy gives a file this release changes: cron/followup_send.php since 5.18.106 (root\n"
         "# docs/75 §L.2; evo_webhook.php, 5.18.105's mark, is a file 5.18.106 keeps byte for byte, so stamp_copy never touches\n"
         "# it) — COPY_AT, R19, V4 and A14 read the right moment.\n",
         "# The copy's time is the time stamp_copy gives a file this release changes: cron/followup_send.php since 5.18.106, kept\n"
         "# by 5.18.107, whose delta holds it too (root docs/76 §T.2; stamp_copy stamps only the delta) — COPY_AT, R19, V4 and A14\n"
         "# read the right moment.\n"),
        ("(COPY_MARK, a file 5.18.106 changes)", "(COPY_MARK, a file 5.18.107 changes)"),
        ("after a rollback the 3 that 5.18.106 adds are left on disk", f"after a rollback the {n_a} that 5.18.107 adds are left on disk"),
    ], why="copy mark")
    doc.must(f'COPY_MARK="{COPY_MARK_107}"\n', 1)
    doc.mustnot("evo_webhook.php, 5.18.105's mark")

    # ── QC-RB1 (§T.2): the words are the basenames of the files 5.18.107 adds; the control is the pin's layer file ──
    bases = sorted(re.sub(r"\.(php|sql)$", "", f.split("/")[-1]) for f in added)
    if len(set(bases)) != len(bases):
        raise SystemExit("PART A: two added files share a basename")
    words = sorted(QC_QUALIFY.get(b, b) for b in bases)
    for b, q in QC_QUALIFY.items():
        if b not in bases or not any(f.endswith("/" + q + ".php") for f in added):
            raise SystemExit(f"PART A: QC-RB1's qualified word {q} is not the folder and basename of an added file")
    if len(words) != N_ADDED_107 or any(not re.fullmatch(r"[A-Za-z0-9_]+(?:/[A-Za-z0-9_]+)?", w) for w in words) \
            or any(o != w and o in w for o in words for w in words):
        raise SystemExit(f"PART A: QC-RB1's words are not 40 distinct names, none part of another: {words}")
    args = ["git", "-C", repo, "grep", "-l", "-F"]
    for w in words:
        args += ["-e", w]
    r = subprocess.run(args + [base, "--", plugin], capture_output=True)
    if r.returncode != 1 or r.stdout.strip():
        raise SystemExit(f"PART A: bf29cbd already names a word of QC-RB1's, or git grep failed: {r.stdout.decode()[:200]}")
    for b in QC_QUALIFY:           # the reason each is qualified: as a bare word it names a bf29cbd file, or is part of another word
        bare_hit = subprocess.run(["git", "-C", repo, "grep", "-l", "-F", "-e", b, base, "--", plugin], capture_output=True)
        if bare_hit.returncode == 1 and not any(b != w and b in w for w in bases):
            raise SystemExit(f"PART A: {b} needs no qualifying — it names no bf29cbd file and is part of no other word")
    ctl_text = git(repo, "show", f"{pin}:{plugin}/{QC_CONTROL}").decode()
    ctl_n = sum(1 for ln in ctl_text.split("\n") if any(w in ln for w in words))
    if ctl_n < len(words) or any(w not in ctl_text for w in words):
        raise SystemExit("PART A: QC-RB1's control does not name each of its words")
    # what reaches the added files at bf29cbd — naming is not reaching: the two test runners load the new sections by glob
    # (tests only), bf29cbd's MigrationRunner globs migrations/*.sql (092 is applied once, then passed over), and no bf29cbd
    # file requires one of the nine lib classes (the sweep above: no bf29cbd file names one)
    for f, globs in QC_TEST_GLOBS.items():
        gl = git(repo, "show", f"{base}:{plugin}/{f}").decode().split("\n")
        if any(gl.count(g) != 1 for g in globs) or f not in kept_all:
            raise SystemExit(f"PART A: bf29cbd's {f} does not load its section folder by the expected globs, or is not kept")
    mr = git(repo, "show", f"{base}:{plugin}/{QC_MIG_GLOB[0]}").decode().split("\n")
    if mr.count(QC_MIG_GLOB[1]) != 1 or QC_MIG_GLOB[0] not in kept_all:
        raise SystemExit("PART A: bf29cbd's MigrationRunner does not glob migrations/*.sql, or is not kept")
    test_added = [f for f in added if f.startswith("tests/")]
    r = subprocess.run(["git", "-C", repo, "grep", "-l", "-E", "-e", "quote_followup/|catalogue_direct/", base, "--",
                        f"{plugin}/*.php", f":(exclude){plugin}/tests"], capture_output=True)
    if r.returncode != 1 or r.stdout.strip():
        raise SystemExit(f"PART A: a runtime file of bf29cbd names a test section folder: {r.stdout.decode()[:200]}")
    for f in (const(doc, "T1_TEST"), const(doc, "T4_TEST")):
        t1 = git(repo, "show", f"{base}:{plugin}/{f}").decode()
        if "quote_followup" in t1 or "catalogue_direct" in t1 or "r107_revert" in t1:
            raise SystemExit(f"PART A: the server-run test {f} names a 5.18.107 test folder or file")
    ctx.update(qc_rb1_words=words, qc_rb1_ctl=ctl_n, qc_rb1_control=QC_CONTROL)
    new_block = (
        wrap(f"QC-RB1 — carried from 5.18.103, re-pointed to 5.18.107 (root docs/76 §T.2): no file {base[:7]} tracks names "
             f"what 5.18.107 adds and leaves. 5.18.107 adds {n_a} files — {len(test_added)} tests, the nine lib classes and "
             "migrations/092_wa_no_ai.sql — and they stay on disk (deploy-hybrid.sh never deletes; counted above). The words "
             "are their basenames, read off Git by the derivation (derive107.py, partA), two qualified by their folder "
             "(quote_followup/mutants_K, catalogue_direct/mutants_W) so that no word names a bf29cbd file or is part of "
             f"another; the derivation also asserts that no file {base[:7]} tracks names one. One reader for every file "
             f"(qc_rb1_n, as text, from its input): every file {base[:7]} tracks under the plugin, data/ aside, as installed, "
             "must name none — a tracked file missing on disk fails, so the sweep never passes over a file it did not read — "
             f"beside its control, the pin's {QC_CONTROL} from Git, which names them on its pinned count of lines "
             f"({ctl_n}). Fixed words and counts only. Naming is not reaching: 092 is reached by {base[:7]}'s MigrationRunner "
             "glob (migrations/*.sql) and passed over once applied; the new test sections are reached by "
             f"{base[:7]}'s test globs (tests/test_quote_followup.php and tests/test_catalogue_direct.php) — tests only; "
             f"the nine lib classes are required by no file of {base[:7]}'s (the sweep shows none names one); no server-run "
             "test (T1, T4) names a 5.18.107 test file (the derivation asserts these from Git), and the ok line says so.", "# ") + "\n"
        "QC_RB1_WORDS=\"$(cat <<'WORDS'\n" + "\n".join(words) + "\nWORDS\n)\"\n"
        "QC_RB1_E=(); while IFS= read -r qr1_w; do [ -n \"$qr1_w\" ] && QC_RB1_E+=(-e \"$qr1_w\"); done <<< \"$QC_RB1_WORDS\"\n"
        "qc_rb1_n() { grep -cF \"${QC_RB1_E[@]}\" 2>/dev/null || true; }   # qc_rb1_n <INPUT — the lines of INPUT naming any of the words, or nothing when INPUT could not be read: one reader, pin and installed\n"
        "QCRB1_BAD=\"\"; QCRB1_SW_N=0; QCRB1_SW_HIT=0; QCRB1_SW_MISS=0\n"
        f"[ \"${{#QC_RB1_E[@]}}\" = \"{2 * len(words)}\" ] || QCRB1_BAD=\"$QCRB1_BAD words:$(( ${{#QC_RB1_E[@]}} / 2 ))/{len(words)}\"\n"
        f"qr1_ctl=\"$(git -C \"$REPO\" show \"$EXPECTED_PLUGIN_COMMIT:$PLUGIN/{QC_CONTROL}\" 2>/dev/null | qc_rb1_n)\"\n"
        "case \"$qr1_ctl\" in\n"
        f"  {ctl_n})\n"
        "    while IFS= read -r -d '' qr1_rel; do\n"
        "      qr1_rel=\"${qr1_rel#\"$PLUGIN\"/}\"\n"
        "      case \"$qr1_rel\" in data/*) continue ;; esac\n"
        "      QCRB1_SW_N=$((QCRB1_SW_N + 1))\n"
        "      if [ ! -f \"$DEST/$qr1_rel\" ]; then QCRB1_SW_MISS=$((QCRB1_SW_MISS + 1)); [ \"$QCRB1_SW_MISS\" -gt 5 ] || QCRB1_BAD=\"$QCRB1_BAD $qr1_rel:missing\"; continue; fi\n"
        "      qr1_in=\"$(qc_rb1_n <\"$DEST/$qr1_rel\")\"\n"
        "      [ \"$qr1_in\" = \"0\" ] || { QCRB1_SW_HIT=$((QCRB1_SW_HIT + 1)); [ \"$QCRB1_SW_HIT\" -gt 5 ] || QCRB1_BAD=\"$QCRB1_BAD $qr1_rel:names-a-word×${qr1_in:-?}\"; }\n"
        "    done < <(git -C \"$REPO\" ls-tree -r -z --name-only \"$BASELINE_COMMIT\" -- \"$PLUGIN\" 2>/dev/null)\n"
        "    [ \"$QCRB1_SW_N\" -gt 0 ] || QCRB1_BAD=\"$QCRB1_BAD sweep:no-file-of-$BASELINE_COMMIT-listed\"\n"
        "    [ \"$QCRB1_SW_HIT\" -le 5 ] || QCRB1_BAD=\"$QCRB1_BAD sweep:$QCRB1_SW_HIT-files-name-one-in-all\"\n"
        "    [ \"$QCRB1_SW_MISS\" -le 5 ] || QCRB1_BAD=\"$QCRB1_BAD sweep:$QCRB1_SW_MISS-files-missing-in-all\" ;;\n"
        f"  *) QCRB1_BAD=\"$QCRB1_BAD sweep(the-control:the-pin-{QC_CONTROL}-reads-${{qr1_ctl:-?}}-not-{ctl_n})\" ;;\n"
        "esac\n"
        "if [ -z \"$QCRB1_BAD\" ]; then ok \"QC-RB1 no file $BASELINE_COMMIT tracks names one of the files $EXPECTED_VERSION adds and leaves on disk: none of the $QCRB1_SW_N files $BASELINE_COMMIT tracks, as installed, names one of its $(printf '%s\\n' \"$QC_RB1_WORDS\" | grep -c .) words (the basenames of its nine lib classes, migrations/092_wa_no_ai.sql and its tests), each read as text — beside the control, the pin's "
        f"{QC_CONTROL}, which names them on its pinned count of lines. A note: $BASELINE_COMMIT's MigrationRunner reaches 092 by its glob, migrations/*.sql, and passes over it once applied; its test runners (tests/test_quote_followup.php, tests/test_catalogue_direct.php) load the new test sections by glob — tests only; the nine lib classes are required by no file of $BASELINE_COMMIT's, and no server-run test (T1, T4) names a 5.18.107 test file\"\n"
        "else bad \"QC-RB1 $BASELINE_VERSION's installed code names what $EXPECTED_VERSION leaves, or its readers could not see the release:$QCRB1_BAD — the rollback leaves the files $EXPECTED_VERSION adds in place, so nothing installed may name them. Send the log file\"; fi\n")
    doc.between("# QC-RB1 — carried from 5.18.103, re-pointed to 5.18.106 (root docs/75 §L.2): no file 7835906 tracks names what 5.18.106\n",
                "# The quoting records, as they are: quote_refused* on applications,",
                new_block + "# The quoting records, as they are: quote_refused* on applications,", keep_ends=False, why="QC-RB1")
    doc.must(new_block, 1, why="QC-RB1 as written")
    doc.mustnot("tests/fixtures/r106_revert.php, which names them")
    doc.mustnot("U_unjam\nmutants_U\nr106_revert\nWORDS")

    # ── the constants' comments that name a release ──
    doc.subs([
        ("a file 5.18.97 through 5.18.106 do not change (R6, R23.6, RB)", "a file 5.18.97 through 5.18.107 do not change (R6, R23.6, RB)"),
        ("command with its own log: kept by 5.18.100 through 5.18.106, before the deploy, while the release before this one is live.",
         "command with its own log: kept by 5.18.100 through 5.18.107, before the deploy, while the release before this one is live."),
        ("# copied by the backup (5.18.95's rule, kept). 5.18.106 changes nothing of it and reads nothing of it.",
         "# copied by the backup (5.18.95's rule, kept). 5.18.107 changes nothing of it and reads nothing of it."),
        ("# V4: every fatal since the deploy started fails, as in 5.18.95 — none is known in 5.18.98 through 5.18.105.",
         "# V4: every fatal since the deploy started fails, as in 5.18.95 — none is known in 5.18.98 through 5.18.106."),
        # 5.18.95's four fixes: public.php is now in the delta (W.5's tab key), outside their lines (§T.14 DF-2)
        ("# — a regression since 5.18.96; 5.18.106 changes none of their files (public.php is 5.18.104's, kept by its sha256)\n",
         "# — a regression since 5.18.96; 5.18.107 changes public.php only in its tab and permission maps (the AI Knowledge Base\n"
         "# screen's key, root docs/76 §W.5), none of the lines these read, and changes none of their other files —\n"),
    ], why="constants comments")
    doc.must("# screen's key, root docs/76 §W.5), none of the lines these read, and changes none of their other files —\n"
             "# and must leave every one of them in place: the photo\n", 1, why="A22's comment reads on")
    doc.mustnot("5.18.106 changes none of their files")
