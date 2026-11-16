# Part A — identity (root docs/75 §L.2): the pin (bf29cbd, 5.18.106, release/5.18.106), the baseline (7835906, 5.18.105,
# live), the run's folder (/root/dnb-5.18.106: OUT and every literal path that means it), the three sha256 lists and the
# delta's counts, no migration, the chain of earlier state files shifted by one (PREV_STATE moves by itself; PREV2 =
# 5.18.104 … PREV8 = 5.18.98, and PREV9 = 5.18.97 added so that 5.18.97's STRAY_Q_FAILED record is not dropped — the
# reason 5.18.105 added PREV8), A14's loop, its three messages, its comments and the header's state-file paths following,
# COPY_MARK moved to cron/followup_send.php (stamp_copy stamps only the delta, and evo_webhook.php is not in it), QC-RB1's
# words and control (§L.2), and the constants' comments that name a release. Modelled on wip/derive-5.18.105/partA.py
# (QC-RB1 on its partR.py). Every list, count and sha256 is read off Git (7835906 → the pin) and off the source's own
# lists, never typed; the numbers below are §L.2's, asserted equal to what Git gives, never written from them:
#   PREV_KEPT_SHA256S    (5.18.105's kept ∪ its pinned) − 5.18.106's delta, at 7835906's sha, asserted identical at the
#                        pin: 455 — 5.18.105's 386 kept less the 7 that 5.18.106 changes, its 89 pinned less the 13
#   PREV_CHANGED_SHA256S exactly 5.18.106's 20 changed files, at 7835906's sha (Q-G1 reads them as live 5.18.105; RB
#                        puts them back)
#   PIN_SHA256S          5.18.106's 23 at the pin: 20 changed, 3 added (the added files are tests only — asserted)
# The source's own lists are asserted first — 386, 89 and 46, each file at the sha Git gives it (994d660's for the kept
# and the changed, 7835906's for the pinned), its EXPECTED_ADDED and EXPECTED_CHANGED Git's 994d660 → 7835906 delta — so
# a different source is refused.
import re, subprocess, textwrap
from d106lib import sha_at, delta, git

N_KEPT_105, N_PIN_105, N_CHANGED_105 = 386, 89, 46      # 5.18.105's own lists, as its derivation left them (asserted)
N_FROM_KEPT, N_FROM_PIN, N_KEPT_106 = 7, 13, 455        # §L.2: the 20 changed are 7 of its kept and 13 of its delta
N_ADDED_106, N_CHANGED_106 = 3, 20                      # §L.2: the delta at the pin
VERSIONS = ("5.18.104", "5.18.105", "5.18.106")         # the source's baseline, the source, the pin (asserted)
# docs/75 §C: F1–F5 live in these five runtime files; every other changed file is a test, SAFETY.md or manifest.json
RUNTIME_106 = ["cron/followup_close.php", "cron/followup_run.php", "cron/followup_scan.php", "cron/followup_send.php",
               "lib/FollowUpService.php"]
COPY_MARK_106 = "cron/followup_send.php"                # §L.2: a file of the delta that exists at 7835906 too
# §L.2: QC-RB1's words (asserted equal to the names of the files 5.18.106 adds), its control, and the loader at 7835906
# that reaches section U's files by glob, never by name
QC_WORDS = ["U_unjam", "mutants_U", "r106_revert"]
QC_CONTROL = "tests/fixtures/r106_revert.php"
QC_GLOB_FILE = "tests/test_quote_followup.php"
QC_GLOBS = ["foreach (glob(__DIR__ . '/quote_followup/*.php') ?: [] as $f) {",
            "    if (preg_match('/^[A-Z]{1,2}_[a-z0-9_]+\\.php$/', basename($f))) require $f;",
            "    foreach (glob(__DIR__ . '/quote_followup/mutants_*.php') ?: [] as $f) {",
            "        if (preg_match('/^mutants_[A-Z]{1,2}\\.php$/', basename($f))) require $f;"]
# what stays held by sha256 in the new kept list (each at 7835906's sha, identical at the pin): 5.18.104's sales
# follow-up, which 5.18.105 moved under sha, section U's loader, and the report wrapper's seven inputs (§L.2, DL7)
HELD = ["tools/sales_followup.php", "lib/SalesFollowupLadder.php", "lib/SalesFollowupChecks.php", "cron/master.php",
        "cron/event_processor.php", "lib/NotificationService.php", "migrations/089_quote_followups.sql",
        "migrations/090_wa_voice.sql", "migrations/091_wa_offers.sql", "lib/QuoteDailyList.php", QC_GLOB_FILE,
        "tools/sales_followup_report.php", "lib/QuoteSendEvidence.php", "lib/PhoneNumber.php", "lib/TenantProfile.php",
        "lib/PluginConfig.php", "lib/ConfigVault.php", "lib/ContactOptOut.php"]
# the files the carried comments below speak for (BK3's links, the More menu, A22's public.php, the cash-in-hand tool,
# A24's library, the brain, 5.18.105's copy mark): none may be in 5.18.106's delta
NOT_IN_DELTA = ["includes/navigation.php", "tabs/admin/ceo_dashboard.php", "tabs/sales/more_menu.php", "public.php",
                "tools/cash_in_hand.php", "lib/CashbookService.php", "lib/BrainContext.php", "lib/DishNetAiBrain.php",
                "evo_webhook.php"]
NUM = {6: "six", 7: "seven", 8: "eight", 9: "nine"}


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


def apply(doc, ctx):
    repo, plugin, base, pin = ctx["repo"], ctx["plugin"], ctx["base_full"], ctx["pin_full"]

    # ── the source: 5.18.105's script, pinned to 7835906, cut on its own baseline ──
    v_older, v_old = const(doc, "BASELINE_VERSION"), const(doc, "EXPECTED_VERSION")
    vs = re.findall(r'"version":\s*"(5\.[0-9.]+)"', git(repo, "show", f"{pin}:{plugin}/manifest.json").decode())
    if len(vs) != 1:
        raise SystemExit(f"PART A: the pin's manifest.json names {len(vs)} release versions")
    v_new = vs[0]
    if (v_older, v_old, v_new) != VERSIONS:
        raise SystemExit(f"PART A: the versions are {v_older}/{v_old}/{v_new}, not {'/'.join(VERSIONS)}")
    if const(doc, "EXPECTED_PLUGIN_COMMIT_FULL") != base or const(doc, "EXPECTED_PLUGIN_COMMIT") != base[:7]:
        raise SystemExit("PART A: the source is not pinned to 7835906 — it is not 5.18.105's script")
    base104 = const(doc, "BASELINE_COMMIT_FULL")
    if git(repo, "rev-parse", base + "^").decode().strip() != base104 or const(doc, "BASELINE_COMMIT") != base104[:7]:
        raise SystemExit("PART A: 7835906's parent is not the source's own baseline")
    if git(repo, "rev-parse", pin + "^").decode().strip() != base:
        raise SystemExit("PART A: the pin is not one commit on 7835906")
    for c in (base, pin):          # the short hashes the script carries name these commits and no other
        r = subprocess.run(["git", "-C", repo, "rev-parse", "-q", "--verify", c[:7] + "^{commit}"], capture_output=True)
        if r.returncode != 0 or r.stdout.decode().strip() != c:
            raise SystemExit(f"PART A: {c[:7]} does not name {c} alone")
    if const(doc, "RELEASE_BRANCH") != f"release/{v_old}":
        raise SystemExit("PART A: the source's RELEASE_BRANCH is not release/5.18.105")

    # ── the source's own lists: 386 kept, 89 pinned, 46 changed, each as Git gives it ──
    kept105, pin105, chg105 = block(doc, "PREV_KEPT_SHA256S"), block(doc, "PIN_SHA256S"), block(doc, "PREV_CHANGED_SHA256S")
    got = (len(kept105), len(pin105), len(chg105))
    if got != (count_const(doc, "PREV_KEPT_N"), count_const(doc, "PIN_SHA_N"), count_const(doc, "PREV_CHANGED_N")) \
            or got != (N_KEPT_105, N_PIN_105, N_CHANGED_105):
        raise SystemExit(f"PART A: 5.18.105's lists are {got[0]}/{got[1]}/{got[2]}, not {N_KEPT_105}/{N_PIN_105}/{N_CHANGED_105} "
                         "(or its counts constants say otherwise)")
    a105, c105 = delta(repo, base104, base, plugin)
    kf, pf, cf = [f for _, f in kept105], [f for _, f in pin105], [f for _, f in chg105]
    if pf != sorted(c105) + sorted(a105) or sorted(cf) != sorted(c105) \
            or const(doc, "EXPECTED_ADDED").split() != sorted(a105) or const(doc, "EXPECTED_CHANGED").split() != sorted(c105):
        raise SystemExit("PART A: the source's PIN_SHA256S, PREV_CHANGED_SHA256S, EXPECTED_ADDED or EXPECTED_CHANGED is not "
                         "Git's 994d660 → 7835906 delta")
    for s, f in pin105:
        if sha_at(repo, base, f"{plugin}/{f}") != s:
            raise SystemExit(f"PART A: the source pins {f} at a sha 7835906 does not give it")
    for s, f in chg105:
        if sha_at(repo, base104, f"{plugin}/{f}") != s:
            raise SystemExit(f"PART A: the source's PREV_CHANGED holds {f} at a sha 994d660 does not give it")
    for s, f in kept105:
        if sha_at(repo, base104, f"{plugin}/{f}") != s:
            raise SystemExit(f"PART A: the source's PREV_KEPT holds {f} at a sha 994d660 does not give it")
    if set(kf) & set(pf):
        raise SystemExit(f"PART A: a file is both kept and pinned in the source: {sorted(set(kf) & set(pf))[:3]}")

    # ── 5.18.106's delta, from Git ──
    added, changed = delta(repo, base, pin, plugin)
    d106 = set(added) | set(changed)
    if (len(added), len(changed)) != (N_ADDED_106, N_CHANGED_106):
        raise SystemExit(f"PART A: Git reports {len(added)} added and {len(changed)} changed, not {N_ADDED_106} and {N_CHANGED_106}")
    if any(not f.startswith("tests/") for f in added):
        raise SystemExit(f"PART A: 5.18.106 adds a file outside tests/: {[f for f in added if not f.startswith('tests/')]}")
    if any(f.startswith("migrations/") for f in d106):
        raise SystemExit("PART A: 5.18.106 carries a migration (root docs/75 §L.2: no migration)")
    runtime = [f for f in changed if not f.startswith("tests/") and f not in ("SAFETY.md", "manifest.json")]
    if sorted(runtime) != sorted(RUNTIME_106):
        raise SystemExit(f"PART A: 5.18.106's runtime files are {runtime}, not docs/75 §C's five")
    for f in NOT_IN_DELTA:
        if f in d106:
            raise SystemExit(f"PART A: 5.18.106 changes {f} — a carried comment's words would be wrong")
    from_kept = [f for f in kf if f in d106]
    from_pin = [f for f in pf if f in d106]
    if (len(from_kept), len(from_pin)) != (N_FROM_KEPT, N_FROM_PIN) or sorted(from_kept + from_pin) != sorted(changed):
        raise SystemExit(f"PART A: the changed files are {len(from_kept)} of 5.18.105's kept and {len(from_pin)} of its delta "
                         f"(not {N_FROM_KEPT} and {N_FROM_PIN}), or one is neither")
    if any(f in kf or f in pf for f in added):
        raise SystemExit("PART A: an added file is listed by 5.18.105")
    kept_a = [f for f in kf if f not in d106]
    kept_b = [f for f in pf if f not in d106]
    kept_all = kept_a + kept_b
    if len(kept_all) != N_KEPT_106 or len(set(kept_all)) != len(kept_all):
        raise SystemExit(f"PART A: the new kept list holds {len(kept_all)} files ({len(set(kept_all))} distinct), not {N_KEPT_106}")
    for f in HELD:
        if f not in kept_all:
            raise SystemExit(f"PART A: {f} is not in the new kept list (root docs/75 §L.2)")
    rt105 = [f for f in c105 if not f.startswith("tests/") and f not in ("SAFETY.md", "manifest.json")]
    if any(f not in kept_b for f in rt105):
        raise SystemExit(f"PART A: 5.18.106 changes a runtime file of 5.18.105's: {[f for f in rt105 if f not in kept_b]}")
    # A0's own rule, asserted here first: a kept file is the same file in both release commits
    for f in kept_all:
        if sha_at(repo, base, f"{plugin}/{f}") != sha_at(repo, pin, f"{plugin}/{f}"):
            raise SystemExit(f"PART A: kept file differs between {base[:7]} and the pin: {f}")
    for f in kept_a:               # 5.18.105 kept them too: 994d660's bytes still
        if sha_at(repo, base104, f"{plugin}/{f}") != sha_at(repo, base, f"{plugin}/{f}"):
            raise SystemExit(f"PART A: a file of 5.18.105's kept list differs between 994d660 and 7835906: {f}")
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
    if COPY_MARK_106 not in changed:
        raise SystemExit(f"PART A: {COPY_MARK_106} is not a file 5.18.106 changes")
    n_a, n_c, n_k = len(added), len(changed), len(kept_all)
    ctx.update(added=added, changed=changed, kept=kept_all, n_added=n_a, n_changed=n_c, n_kept=n_k, n_pin=n_a + n_c,
               from_kept=from_kept, from_pin=from_pin, runtime_changed=runtime, base104=base104,
               v_older=v_older, v_old=v_old, v_new=v_new, n105=(len(kf), len(pf), len(cf), len(a105)))

    # ── the three lists and their counts ──
    replace_block(doc, "PREV_KEPT_SHA256S", sums(repo, base, plugin, kept_all))
    replace_block(doc, "PREV_CHANGED_SHA256S", sums(repo, base, plugin, sorted(changed)))
    replace_block(doc, "PIN_SHA256S", sums(repo, pin, plugin, sorted(changed) + sorted(added)))
    doc.sub(f"PREV_KEPT_N={N_KEPT_105}\n", f"PREV_KEPT_N={n_k}\n", why="PREV_KEPT_N")
    doc.sub(f"PREV_CHANGED_N={N_CHANGED_105}\n", f"PREV_CHANGED_N={n_c}\n", why="PREV_CHANGED_N")
    doc.sub(f"PIN_SHA_N={N_PIN_105}\n", f"PIN_SHA_N={n_a + n_c}\n", why="PIN_SHA_N")
    doc.line('EXPECTED_ADDED="', 'EXPECTED_ADDED="' + " ".join(sorted(added)) + '"', why="EXPECTED_ADDED")
    doc.line('EXPECTED_CHANGED="', 'EXPECTED_CHANGED="' + " ".join(sorted(changed)) + '"', why="EXPECTED_CHANGED")

    # ── the pin and the baseline ──
    doc.line(f'EXPECTED_PLUGIN_COMMIT="{base[:7]}"',
             f'EXPECTED_PLUGIN_COMMIT="{pin[:7]}"   # 5.18.106 (release) — on Uganda, the enquiry follow-up sender unjammed (root docs/75): F1–F5 in {len(runtime)} runtime files, the four follow-up jobs (cron/followup_close, _run, _scan and _send) and lib/FollowUpService.php; no migration, no new setting; the deploy keeps every switch of the sales follow-up exactly as found, and its rollback sets followup_auto_send off first (AS-RB1) and keeps every other',
             why="EXPECTED_PLUGIN_COMMIT")
    doc.line(f'EXPECTED_PLUGIN_COMMIT_FULL="{base}"',
             f'EXPECTED_PLUGIN_COMMIT_FULL="{pin}"   # the same commit in full: A0 refuses a short hash that names any other',
             why="EXPECTED_PLUGIN_COMMIT_FULL")
    doc.sub(f'EXPECTED_VERSION="{v_old}"\n', f'EXPECTED_VERSION="{v_new}"\n', why="EXPECTED_VERSION")
    doc.line(f'BASELINE_COMMIT="{base104[:7]}"',
             f'BASELINE_COMMIT="{base[:7]}"          # 5.18.105 (release, no phone number in any log line; live once its own deploy and --after-only have PASSED — root docs/07, docs/75 §L.8) — what the server runs first, and the rollback (every switch of the sales follow-up kept; followup_auto_send set off first, AS-RB1); G3 and V3j require ECO Bank\'s switch ON and a statement kept on it, as 5.18.105\'s did',
             why="BASELINE_COMMIT")
    doc.sub(f'BASELINE_COMMIT_FULL="{base104}"\n', f'BASELINE_COMMIT_FULL="{base}"\n', why="BASELINE_COMMIT_FULL")
    doc.sub(f'BASELINE_VERSION="{v_older}"\n', f'BASELINE_VERSION="{v_old}"\n', why="BASELINE_VERSION")
    doc.sub("R5 checks every file from Release A through 5.18.104 is installed as pinned",
            "R5 checks every file from Release A through 5.18.105 is installed as pinned", why="RELEASE_A_BASE")
    doc.sub('RELEASE_BRANCH="release/5.18.105"  #', 'RELEASE_BRANCH="release/5.18.106"  #', why="RELEASE_BRANCH")

    # ── the run's folder: OUT, and every literal path that means it ──
    doc.sub('OUT="${DNB_OUT:-/root/dnb-5.18.105}"', 'OUT="${DNB_OUT:-/root/dnb-5.18.106}"', why="OUT")
    doc.subs([
        ("#            /root/dnb-5.18.105/cash-<mode>-<UTC stamp>.txt, mode 0600, in a folder of mode 700. It holds the book's cash in\n",
         "#            /root/dnb-5.18.106/cash-<mode>-<UTC stamp>.txt, mode 0600, in a folder of mode 700. It holds the book's cash in\n"),
        ("a key kept in /root/dnb-5.18.105/lk-key-5.18.105 (mode 0600)", "a key kept in /root/dnb-5.18.106/lk-key-5.18.106 (mode 0600)"),
        ("#   cd /opt/dishnet && git pull origin claude/study-this-jhe2eg && git fetch origin release/5.18.105 && mkdir -p /root/dnb-5.18.105 && bash scripts/deploy-5.18.105.sh 2>&1 | ( umask 077; exec tee /root/dnb-5.18.105/deploy-$(date -u +%Y%m%dT%H%M%SZ).log )\n",
         "#   cd /opt/dishnet && git pull origin claude/study-this-jhe2eg && git fetch origin release/5.18.106 && mkdir -p /root/dnb-5.18.106 && bash scripts/deploy-5.18.106.sh 2>&1 | ( umask 077; exec tee /root/dnb-5.18.106/deploy-$(date -u +%Y%m%dT%H%M%SZ).log )\n"),
        ("#   cd /opt/dishnet && bash scripts/deploy-5.18.105.sh --after-only 2>&1 | ( umask 077; exec tee /root/dnb-5.18.105/after-$(date -u +%Y%m%dT%H%M%SZ).log )\n",
         "#   cd /opt/dishnet && bash scripts/deploy-5.18.106.sh --after-only 2>&1 | ( umask 077; exec tee /root/dnb-5.18.106/after-$(date -u +%Y%m%dT%H%M%SZ).log )\n"),
        ("a backup under /root/dnb-5.18.105/,", "a backup under /root/dnb-5.18.106/,"),
        ("# The run's cash sheet, on the host: /root/dnb-5.18.105/cash-<mode>-<stamp>.txt, mode 0600, in a folder of mode 700 — the\n",
         "# The run's cash sheet, on the host: /root/dnb-5.18.106/cash-<mode>-<stamp>.txt, mode 0600, in a folder of mode 700 — the\n"),
    ], why="the run's folder")

    # ── the kept list's prose, and the other lists' ──
    rt_kept = [f for f in rt105 if f in ("webhook.php", "evo_webhook.php", "cron_quote_wa.php", "lib/PluginLog.php",
                                         "tools/sales_followup_report.php")]
    if len(rt_kept) != 5:
        raise SystemExit("PART A: 5.18.105's webhook.php, evo_webhook.php, cron_quote_wa.php, PluginLog or report is not in its delta")
    kept_prose = "\n".join([
        "# 5.18.106 (Q-G1, Q-CA1, RB) — the files of 5.18.105 this release must leave exactly as they are, each with the sha256",
        f"# release/5.18.105 ({base[:7]}) gives it, pinned here so that no other 5.18.105 can pass for it (root docs/75 §L.2):",
        wrap(f"the {len(kept_a)} of 5.18.105's own kept list that 5.18.106 does not change — all but the {len(from_kept)} it does "
             f"({', '.join(from_kept)}) — then the {len(kept_b)} of 5.18.105's own release files that 5.18.106 does not change — "
             f"all but the {len(from_pin)} it does ({', '.join(from_pin)}): its masked log lines (webhook.php, evo_webhook.php, "
             "cron_quote_wa.php and the others), lib/PluginLog.php and the report among them, so that what 5.18.105 put live is "
             "held by its sha256 and not only by the delta's; 5.18.104's own sales follow-up (the ladder trio, cron/master.php, "
             "cron/event_processor.php, lib/NotificationService.php, migrations 089–091, lib/QuoteDailyList.php) stays among the "
             "first. A0 finds them in both release commits from Git; Q-G1 installed before anything changes; Q-CA1 installed "
             "after the copy; RB after a rollback.", "# ")])
    doc.lines_between("# 5.18.105 (Q-G1, Q-CA1, RB) — the files of 5.18.104 this release must leave exactly as they are, each with the sha256",
                      "# commits from Git; Q-G1 installed before anything changes; Q-CA1 installed after the copy; RB after a rollback.",
                      kept_prose, why="kept prose")
    doc.must("RB after a rollback.\nPREV_KEPT_SHA256S=\"$(cat <<'SUMS'\n", 1, why="kept prose ends on the list")
    doc.lines_between("# 5.18.105 (Q-G1, RB) — every file 5.18.105 changes, with the sha256 release/5.18.104 (994d660) gives it: what the live",
                      "# listed here).",
        f"# 5.18.106 (Q-G1, RB) — every file 5.18.106 changes, with the sha256 release/5.18.105 ({base[:7]}) gives it: what the live\n"
        "# plugin must hold before anything changes (Q-G1: live 5.18.105), and what a rollback must put back (RB). A0 finds them in\n"
        f"# 5.18.105's release commit from Git; the {n_a} files 5.18.106 adds (tests only) have no 5.18.105 form (A0 refuses one\n"
        "# listed here).", why="changed prose")
    doc.must("# listed here).\nPREV_CHANGED_SHA256S=\"$(cat <<'SUMS'\n", 1, why="changed prose ends on the list")
    doc.lines_between(f"# 5.18.105 — the release's own files, each with the sha256 the release commit ({base[:7]}) gives it: the delta,",
                      f"# exactly, its {len(a105)} added files (tests only) among them — A0 reads them in Git, R1 and Q-CA1 installed.",
        f"# 5.18.106 — the release's own files, each with the sha256 the release commit ({pin[:7]}) gives it: the delta,\n"
        f"# exactly, its {n_a} added files (tests only) among them — A0 reads them in Git, R1 and Q-CA1 installed.", why="pin prose")
    doc.must("R1 and Q-CA1 installed.\nPIN_SHA256S=\"$(cat <<'SUMS'\n", 1, why="pin prose ends on the list")
    doc.lines_between(f"# The release's own files, and nothing else: {len(a105)} added, {len(c105)} changed — {len(a105) + len(c105)}, each by its sha256",
                      "# holds a space or a glob character.",
        f"# The release's own files, and nothing else: {n_a} added, {n_c} changed — {n_a + n_c}, each by its sha256\n"
        "# (PIN_SHA256S). Anything outside this list in the pin is another build. A0 word-splits both lists: no path in them\n"
        "# holds a space or a glob character.", why="delta prose")
    doc.must("# holds a space or a glob character.\nEXPECTED_ADDED=\"", 1, why="delta prose ends on the lists")

    # ── the earlier state files: PREV_STATE is $BASELINE_VERSION's by itself; one more release back ──
    doc.must('PREV_STATE="${DNB_PREV_OUT:-${DNB_RECORDS:-/root}/dnb-$BASELINE_VERSION}/state-$BASELINE_VERSION.env"\n', 1)
    old = re.findall(r'(?m)^PREV([0-9])_VERSION="([^"]+)"$', doc.t)
    if [int(n) for n, _ in old] != list(range(2, 9)) or old[-1][1] != "5.18.97" or old[0][1] != "5.18.103":
        raise SystemExit(f"PART A: the source's chain is {old}, not PREV2 = 5.18.103 … PREV8 = 5.18.97")
    for n, v in old:
        if doc.t.count(f'PREV{n}_STATE="${{DNB_PREV{n}_OUT:-${{DNB_RECORDS:-/root}}/dnb-$PREV{n}_VERSION}}/state-$PREV{n}_VERSION.env"\n') != 1:
            raise SystemExit(f"PART A: PREV{n}_STATE is not the source's one form")
    chain = [v_older] + [v for _, v in old]           # PREV2 … PREV9: 5.18.104 … 5.18.97
    if chain[-1] != "5.18.97" or len(chain) != 8:
        raise SystemExit(f"PART A: the new chain is {chain}")
    ctx.update(prev_chain=chain)
    chain_text = "\n".join(f'PREV{i}_VERSION="{v}"\nPREV{i}_STATE="${{DNB_PREV{i}_OUT:-${{DNB_RECORDS:-/root}}/dnb-$PREV{i}_VERSION}}/state-$PREV{i}_VERSION.env"'
                           for i, v in enumerate(chain, 2))
    doc.lines_between("# 5.18.99 — and, since 5.18.102, the releases back to 5.18.97 — since 5.18.105 5.18.103's, 5.18.102's, 5.18.101's,",
                      'PREV8_STATE="${DNB_PREV8_OUT:-${DNB_RECORDS:-/root}/dnb-$PREV8_VERSION}/state-$PREV8_VERSION.env"',
        wrap("5.18.99 — and, since 5.18.102, the releases back to 5.18.97 — since 5.18.106 "
             + enum([v + "'s" for v in chain], "and")
             + f", two to {NUM[len(chain) + 1]} releases back (their R19 and RB write the same line; PREV{len(chain) + 1} keeps "
             "5.18.97's STRAY_Q_FAILED record in reach, as PREV8 did in 5.18.105 — root docs/75 §L.2): a failure any of them "
             "recorded is not forgotten by deploying the releases after it and then this. Read, never written. PREV_STATE, "
             "above, is 5.18.105's (production's COPY_AT among its lines, once its deploy is on record).", "# ")
        + "\n" + chain_text, why="prev chain")
    # A14: one more release back, in the loop and in the three messages that name the chain
    doc.sub('"$PREV7_STATE" "$PREV7_STATE".prev-* "$PREV8_STATE" "$PREV8_STATE".prev-*; do',
            '"$PREV7_STATE" "$PREV7_STATE".prev-* "$PREV8_STATE" "$PREV8_STATE".prev-* "$PREV9_STATE" "$PREV9_STATE".prev-*; do',
            why="A14 loop")
    doc.sub("of $PREV6_VERSION's, of $PREV7_VERSION's or of $PREV8_VERSION's",
            "of $PREV6_VERSION's, of $PREV7_VERSION's, of $PREV8_VERSION's or of $PREV9_VERSION's", 3, why="A14 messages")
    doc.must('"$PREV9_STATE" "$PREV9_STATE".prev-*', 1)
    doc.must('PREV9_VERSION="5.18.97"\n', 1)
    doc.mustnot('PREV8_VERSION="5.18.97"')
    for i in range(2, 10):
        doc.must(f'"$PREV{i}_STATE" "$PREV{i}_STATE".prev-*', 1, why="A14 loop reads every link")
    # what follows by itself (PREV_STATE is 5.18.105's): the copy loop and its note
    doc.must('for f in "$PREV_STATE" "$PREV_STATE".prev-*; do\n', 1, why="A14 PREV_COPY loop")
    doc.must('  else note "A14 no copy time of $BASELINE_VERSION\'s deploy is recorded in $PREV_STATE or beside it:', 1)
    # A14's comments: the chain, and the copies the quarantine's rule 3 asks about
    a14_paths = [f"/root/dnb-{v}/state-{v}.env" for v in [v_old] + chain]
    doc.lines_between("# beside it — and in the previous release's own, 5.18.103's, and the six before, 5.18.102's, 5.18.101's, 5.18.100's,",
                      "# the ones kept beside each) — is not",
        wrap(f"beside it — and in the previous release's own, {v_old}'s, and the {NUM[len(chain)]} before, "
             + enum([v + "'s" for v in chain], "and")
             + " (their R19 and RB record the same line, in " + ", ".join(a14_paths)
             + " and the ones kept beside each) — is not", "# "), why="A14 chain comment")
    doc.must("— is not\n# forgotten by deploying again: what showed it may be gone by now,\n", 1)
    if flat(doc.t).count(f"{a14_paths[-1]} and the ones kept beside each) — is not forgotten by deploying again") != 1:
        raise SystemExit("PART A: A14's chain comment does not read on into its next line")
    doc.subs([
        ("# Since 5.18.95 — the quarantine's rule 3: after any plugin upload, rollback or restore, its --check. 5.18.103's deploy was one,\n"
         "# so a deploy goes on only once a --check that began after 5.18.103's latest copy (COPY_AT in its state file, or in one a later\n",
         "# Since 5.18.95 — the quarantine's rule 3: after any plugin upload, rollback or restore, its --check. 5.18.105's deploy is one,\n"
         "# so a deploy goes on only once a --check that began after 5.18.105's latest copy (COPY_AT in its state file, or in one a later\n"),
        ("# Since 5.18.97 (its review): and after this script's own latest copy of the plugin — a deploy of 5.18.105 (COPY_AT in its\n",
         "# Since 5.18.97 (its review): and after this script's own latest copy of the plugin — a deploy of 5.18.106 (COPY_AT in its\n"),
        ("# or a deploy over a rolled-back 5.18.105, goes on only once a --check that began after that rollback has PASSED — and\n"
         "# 5.18.104's own copy (9 Nov 03:50:25 UTC) is followed by the --check begun at 04:39:36 UTC, which PASSED (root docs/07).\n",
         "# or a deploy over a rolled-back 5.18.106, goes on only once a --check that began after that rollback has PASSED — and\n"
         "# 5.18.105's own copy must be followed by a --check begun after it that PASSED (PREV_COPY, above; root docs/75 §L.8).\n"),
    ], why="A14 copy comments")
    # the header's paragraph: the same chain, the same files, rewrapped up to the line that names this script's state file
    doc.lines_between("#            any run of that script holds its folder's lock; while a failure recorded by this script's runs, 5.18.103's,",
                      "#            the kept file or anything at the old name. R19 after the copy, RB after a rollback; a failure is recorded in",
        wrap("any run of that script holds its folder's lock; while a failure recorded by this script's runs, "
             + enum([v + "'s" for v in [v_old] + chain], "or") + " (STRAY_Q_FAILED in "
             + enum([p + "*" for p in a14_paths], "or")
             + ") is newer than the newest passing --check; and unless that --check began after 5.18.105's own copy (COPY_AT in "
             "its state files: the quarantine's rule 3) and after this script's own latest copy — a deploy of 5.18.106 (COPY_AT) "
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
    for m in re.finditer(r"/root/dnb-5\.18\.105", doc.t):
        if not doc.t.startswith("/root/dnb-5.18.105/state-5.18.105.env", m.start()):
            raise SystemExit(f"PART A: a literal of 5.18.105's folder is left: {doc.t[m.start():m.start() + 60]!r}")
    doc.mustnot("dnb-5.18.105/lk-key")

    # ── the copy's time mark: a file 5.18.106 changes (stamp_copy stamps only the delta) ──
    doc.subs([
        ("# The copy's time is the time stamp_copy gives a file this release changes: evo_webhook.php since 5.18.105 (root docs/74\n"
         "# §H.4.2; lib/NotificationService.php, 5.18.104's mark, is a file 5.18.105 keeps byte for byte, so stamp_copy never\n"
         "# touches it) — COPY_AT, R19, V4 and A14 read the right moment.\nCOPY_MARK=\"evo_webhook.php\"",
         f"# The copy's time is the time stamp_copy gives a file this release changes: {COPY_MARK_106} since 5.18.106 (root\n"
         "# docs/75 §L.2; evo_webhook.php, 5.18.105's mark, is a file 5.18.106 keeps byte for byte, so stamp_copy never touches\n"
         f"# it) — COPY_AT, R19, V4 and A14 read the right moment.\nCOPY_MARK=\"{COPY_MARK_106}\""),
        ("as stamp_copy gave evo_webhook.php (COPY_MARK since 5.18.105; 5.18.104 off lib/NotificationService.php,",
         f"as stamp_copy gave {COPY_MARK_106} (COPY_MARK since 5.18.106; 5.18.105 off evo_webhook.php, 5.18.104 off lib/NotificationService.php,"),
        ("  # The copy's own time, as stamp_copy gave evo_webhook.php (COPY_MARK, a file 5.18.105 changes):\n",
         f"  # The copy's own time, as stamp_copy gave {COPY_MARK_106} (COPY_MARK, a file 5.18.106 changes):\n"),
        (f"# adds too: after a rollback the {len(a105)} that 5.18.105 adds are left on disk (deploy-hybrid.sh never deletes) and are given\n",
         f"# adds too: after a rollback the {n_a} that 5.18.106 adds are left on disk (deploy-hybrid.sh never deletes) and are given\n"),
    ], why="copy mark")
    doc.must(f'COPY_MARK="{COPY_MARK_106}"\n', 1)
    doc.mustnot("evo_webhook.php since 5.18.105")

    # ── QC-RB1 (§L.2): the words are the names of the files 5.18.106 adds; the control is the pin's layer file ──
    words = sorted(re.sub(r"\.php$", "", f.split("/")[-1]) for f in added)
    if words != sorted(QC_WORDS) or any(not re.fullmatch(r"[A-Za-z0-9_]+", w) for w in words) \
            or any(o != w and o in w for o in words for w in words):
        raise SystemExit(f"PART A: QC-RB1's words are {words}, not §L.2's {sorted(QC_WORDS)}")
    args = ["git", "-C", repo, "grep", "-l", "-F"]
    for w in words:
        args += ["-e", w]
    r = subprocess.run(args + [base, "--", plugin], capture_output=True)
    if r.returncode != 1 or r.stdout.strip():
        raise SystemExit(f"PART A: 7835906 already names a word of QC-RB1's, or git grep failed: {r.stdout.decode()[:200]}")
    u_files = sorted(f.split("/")[-1] for f in added if f.startswith("tests/quote_followup/"))
    if sorted(added) != sorted([QC_CONTROL] + ["tests/quote_followup/" + f for f in u_files]) \
            or [re.sub(r"\.php$", "", f) for f in u_files] != sorted(w for w in words if f"/{w}.php" not in QC_CONTROL):
        raise SystemExit(f"PART A: the added files are not QC-RB1's control and section U's two files: {added}")
    ctl_text = git(repo, "show", f"{pin}:{plugin}/{QC_CONTROL}").decode()
    ctl_n = sum(1 for ln in ctl_text.split("\n") if any(w in ln for w in words))
    if ctl_n < len(words) or any(w not in ctl_text for w in words):
        raise SystemExit("PART A: QC-RB1's control does not name each of its words")
    # the glob note: 7835906's loader reaches section U by glob, never by name; it is a test, kept by its sha256, and no
    # runtime PHP file and neither server-run test names tests/quote_followup/
    gl = git(repo, "show", f"{base}:{plugin}/{QC_GLOB_FILE}").decode().split("\n")
    if any(gl.count(g) != 1 for g in QC_GLOBS) or QC_GLOB_FILE not in kept_all:
        raise SystemExit(f"PART A: 7835906's {QC_GLOB_FILE} does not load tests/quote_followup/ by the two globs, or is not kept")
    r = subprocess.run(["git", "-C", repo, "grep", "-l", "-F", "quote_followup/", base, "--", f"{plugin}/*.php",
                        f":(exclude){plugin}/tests"], capture_output=True)
    if r.returncode != 1 or r.stdout.strip():
        raise SystemExit(f"PART A: a runtime file of 7835906 names tests/quote_followup/: {r.stdout.decode()[:200]}")
    for f in (const(doc, "T1_TEST"), const(doc, "T4_TEST")):
        if "quote_followup" in git(repo, "show", f"{base}:{plugin}/{f}").decode():
            raise SystemExit(f"PART A: the server-run test {f} names quote_followup")
    ctx.update(qc_rb1_words=words, qc_rb1_ctl=ctl_n, qc_rb1_control=QC_CONTROL)
    u_say = " and ".join(u_files)
    new_block = (
        wrap(f"QC-RB1 — carried from 5.18.103, re-pointed to 5.18.106 (root docs/75 §L.2): no file {base[:7]} tracks names what "
             f"5.18.106 adds and leaves. 5.18.106 adds tests only — tests/quote_followup/{u_say} (section U and its weakened "
             f"copies) and {QC_CONTROL} — and they stay on disk (deploy-hybrid.sh never deletes; counted above). The words are "
             f"their names, read off Git by the derivation (derive106.py, partA), which also asserts that no file {base[:7]} "
             "tracks names one. One reader for every file (qc_rb1_n, as text, from its input): every file "
             f"{base[:7]} tracks under the plugin, data/ aside, as installed, must name none — a tracked file missing on disk "
             "fails, so the sweep never passes over a file it did not read — beside its control, the pin's "
             f"{QC_CONTROL} from Git, which names them on its pinned count of lines ({ctl_n}). Fixed words and counts only. "
             f"Naming is not reaching: {base[:7]}'s {QC_GLOB_FILE} loads every tests/quote_followup/ section and weakened copy "
             "by glob, section U's two files among them — tests only; no runtime file and no server-run test (T1, T4) reaches "
             "them (the derivation asserts both from Git), and the ok line says so.", "# ") + "\n"
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
        "if [ -z \"$QCRB1_BAD\" ]; then ok \"QC-RB1 no file $BASELINE_COMMIT tracks names one of the files $EXPECTED_VERSION adds and leaves on disk (its tests): none of the $QCRB1_SW_N files $BASELINE_COMMIT tracks, as installed, names one of its $(printf '%s\\n' \"$QC_RB1_WORDS\" | grep -c .) words ($(printf '%s' \"$QC_RB1_WORDS\" | paste -sd' ' -)), each read as text — beside the control, the pin's "
        f"{QC_CONTROL}, which names them on its pinned count of lines. A note: $BASELINE_COMMIT's {QC_GLOB_FILE} loads {u_say} by glob — tests only; no runtime file and no server-run test (T1, T4) reaches them\"\n"
        "else bad \"QC-RB1 $BASELINE_VERSION's installed code names what $EXPECTED_VERSION leaves, or its readers could not see the release:$QCRB1_BAD — the rollback leaves the files $EXPECTED_VERSION adds in place, so nothing installed may name them. Send the log file\"; fi\n")
    doc.between("# QC-RB1 — carried from 5.18.103, re-pointed to 5.18.105 (root docs/74 §H.5.4): nothing of 994d660 reaches what 5.18.105\n",
                "# The quoting records, as they are: quote_refused* on applications,",
                new_block + "# The quoting records, as they are: quote_refused* on applications,", keep_ends=False, why="QC-RB1")
    doc.must(new_block, 1, why="QC-RB1 as written")
    doc.mustnot("tests/test_log_mask.php, which names them")
    doc.mustnot("log_mask\nr105_revert\nWORDS")

    # ── the constants' comments that name a release ──
    doc.subs([
        ("a file 5.18.97 through 5.18.105 do not change (R6, R23.6, RB)", "a file 5.18.97 through 5.18.106 do not change (R6, R23.6, RB)"),
        ("as 5.18.97 reviewed them and 5.18.98 through 5.18.105 leave them, byte for byte (Q-G1, Q-CA1): in navigation.php and",
         "as 5.18.97 reviewed them and 5.18.98 through 5.18.106 leave them, byte for byte (Q-G1, Q-CA1): in navigation.php and"),
        ("command with its own log: kept by 5.18.100 through 5.18.105, before the deploy, while the release before this one is live.",
         "command with its own log: kept by 5.18.100 through 5.18.106, before the deploy, while the release before this one is live."),
        ("# — a regression since 5.18.96; 5.18.105 changes none of their files (public.php is 5.18.104's, kept by its sha256)\n",
         "# — a regression since 5.18.96; 5.18.106 changes none of their files (public.php is 5.18.104's, kept by its sha256)\n"),
        ("# copied by the backup (5.18.95's rule, kept). 5.18.105 changes nothing of it and reads nothing of it.",
         "# copied by the backup (5.18.95's rule, kept). 5.18.106 changes nothing of it and reads nothing of it."),
        ("# V4: every fatal since the deploy started fails, as in 5.18.95 — none is known in 5.18.98 through 5.18.104.",
         "# V4: every fatal since the deploy started fails, as in 5.18.95 — none is known in 5.18.98 through 5.18.105."),
        ("# 5.18.104's value, carried — re-measured on the release commit by the rehearsal before pinning (root docs/74 §H.6).\nB2_SIGNATURE=",
         "# 5.18.105's value, carried — re-measured on the release commit by the rehearsal before pinning (root docs/75 §L.10).\nB2_SIGNATURE="),
        ("# the same. Both are 5.18.104's values, carried — re-measured on the release commit by the rehearsal before pinning\n"
         "# (root docs/74 §H.6): 5.18.105 changes neither lib/BrainContext.php nor lib/DishNetAiBrain.php.",
         "# the same. Both are 5.18.105's values, carried — re-measured on the release commit by the rehearsal before pinning\n"
         "# (root docs/75 §L.10): 5.18.106 changes neither lib/BrainContext.php nor lib/DishNetAiBrain.php."),
        ("# inert: nothing refused, nothing classified, no SQL added, the assistant never stopped. 5.18.104's value, carried —\n"
         "# re-measured on the release commit by the rehearsal before pinning (root docs/74 §H.6).",
         "# inert: nothing refused, nothing classified, no SQL added, the assistant never stopped. 5.18.105's value, carried —\n"
         "# re-measured on the release commit by the rehearsal before pinning (root docs/75 §L.10)."),
        ("then the Uganda book, the control. 5.18.104's code\n# and the pin's carry the same gate and the same CashbookService",
         "then the Uganda book, the control. 5.18.105's code\n# and the pin's carry the same gate and the same CashbookService"),
    ], why="constants comments")
    doc.mustnot("5.18.104's value, carried — re-measured")
    doc.mustnot("5.18.104's values, carried — re-measured")
