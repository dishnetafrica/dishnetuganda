#!/usr/bin/env python3
# derive105.py — scripts/deploy-5.18.105.sh from scripts/deploy-5.18.104.sh by asserted edits (root docs/74 §H.4, §H.5),
# and scripts/sales-followup-report.sh re-rendered for the release (§H.4.2); scripts/sales-followup.sh stays as it is.
# Usage: python3 -I derive105.py REPO RELEASE_REPO PIN_FULL [OUT]
#   REPO          the development work tree (the source is REPO/scripts/deploy-5.18.104.sh)
#   RELEASE_REPO  a repository holding the release commit PIN_FULL, cut on 5.18.104's release commit (BASE below)
#   PIN_FULL      the release commit, in full: a parameter — if the release suite forces a new commit, re-run with it
# Every pin, count, file list and sha256 is computed from Git (BASE → PIN_FULL), never typed. Every edit asserts its
# anchor, its count, its replacements and its result (d105lib, a copy of d104lib); any failure exits non-zero and
# writes nothing.
#
# The parts, in the order they run (each a module of this folder with apply(doc, ctx)):
#   partA   identity: the pin, the baseline (994d660, live), the earlier state files (PREV2–PREV8), the three sha256
#           lists — PREV_KEPT = (5.18.104's kept ∪ its pinned) − 5.18.105's delta, PREV_CHANGED and PIN_SHA from Git —
#           the counts, no migration
#   partB   the carried checks the delta re-points: A0 (no migration; 089–091 held), master's two pins (994d660's),
#           UG_SIGNATURE, COPY_MARK (evo_webhook.php), R7's ledger "the same", A15's and RB's words
#   partLK  the ladder rule (§H.4.3): LK-G1, LK-B1, LK-CA1, LK-CA2, LK-ON, LK-AO; QF made live-aware (§H.4.4): QF-G1
#           live only, QF-G2 none pending, QF-G4 and --clear-switches retired (refused at parse), QF-CA2/CA3/CA5 judged
#           against stage A, V3g's exception gone; the LK key file (mode 0600)
#   partR   the rollback (§H.5): LK-RB0 and the step-12 notice before ROLLBACK is typed (rb_pre105); 994d660 put back by
#           do_rollback alone, every switch kept; RB105 after it (master, LK-RB1, the rows, the counts, LM-RB1);
#           QF-RB1–QF-RB3, VN-RB1–VN-RB4, VN-RB6, OF-RB1 and AC-RB1 retired; QC-RB1 re-pointed to what 5.18.105 adds
#   partV   Part V's VN made live-aware: VN-G1 from LK-G1, VN-G2/G6/G7, VN-G4's fromMe whole, VN-CA1 against stage A,
#           VN-CA2 as its flag says, VN-CA4 a note
#   partO   Part O's OF made live-aware: OF-G1 from LK-G1 and the offers as 5.18.104 left them, OF-G2 none pending,
#           OF-CA1 against stage A, OF-CA3 "no table fell" a FAIL and the late counts a note
#   partP   Part P's AC: AC-L1's sums from the pin, AC-CA1's on() as quote_agent_cc_enabled says, AC-CA2 on 994d660
#   partL   the masked lines (§H.4.5): LM_HUNKS from Git (one per hunk, blocks by sha256, no source text), LM-G1, LM-G2,
#           LM-CA1, LM-CA2, LM-ON, LM-RB1
#   partH   what the operator reads (§H.4.7): the header, the options, the stages, the summary and its rollback block
#   partC   the carried texts that named the wrong release, each fact read off Git
#   partZ   the retired readings removed, and the rules asserted over the whole result
# Then keep_check (§H.4.1) over the result and four weakened copies, each refused for its own reason; folder_check and
# its three weakened copies; `bash -n` on the result and both wrappers. Nothing is run: no PHP, no shell beyond `bash -n`.
import sys, os, hashlib, subprocess, tempfile, re
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from d105lib import Doc, git, sha_at, folder_check

BASE_FULL = "994d660628cc5b1b42bee648b44b58e389f44bf2"   # release/5.18.104, the live release
PLUGIN = "dishnet-hybrid-sudan"
PARTS = ["partA", "partB", "partLK", "partR", "partV", "partO", "partP", "partL", "partH", "partC", "partZ"]
HERE = os.path.dirname(os.path.abspath(__file__))

if len(sys.argv) < 4:
    raise SystemExit("usage: derive105.py REPO RELEASE_REPO PIN_FULL [OUT]")
repo, rel, pin = sys.argv[1], sys.argv[2], sys.argv[3]
out = sys.argv[4] if len(sys.argv) > 4 else os.path.join(repo, "scripts/deploy-5.18.105.sh")
if len(pin) != 40 or any(ch not in "0123456789abcdef" for ch in pin):
    raise SystemExit(f"PIN_FULL must be a full 40-hex commit, got {pin!r}")
if git(rel, "rev-parse", pin).decode().strip() != pin:
    raise SystemExit("the pin does not name itself in RELEASE_REPO")
if git(rel, "rev-parse", BASE_FULL).decode().strip() != BASE_FULL:
    raise SystemExit("RELEASE_REPO does not hold 5.18.104's release commit")
# §H.4.1: the pin's parent is 994d660. A build commit that is not yet the squashed release commit is accepted only where
# 994d660 is its first-parent ancestor through ordinary commits — and said loudly: the script's own A0 refuses such a pin
# (PIN_PARENT), so it must be derived again on the release commit before it is used.
parent = git(rel, "rev-parse", pin + "^").decode().strip()
CUT_ON_BASE = parent == BASE_FULL
if not CUT_ON_BASE:
    chain = git(rel, "rev-list", "--first-parent", f"{BASE_FULL}..{pin}").decode().split()
    merges = git(rel, "rev-list", "--merges", f"{BASE_FULL}..{pin}").decode().split()
    r = subprocess.run(["git", "-C", rel, "merge-base", "--is-ancestor", BASE_FULL, pin])
    if r.returncode != 0 or merges or not chain:
        raise SystemExit(f"the pin's parent is {parent}, not 5.18.104's {BASE_FULL}, and 994d660 is not its linear ancestor")
    print(f"  WARNING: the pin's parent is {parent[:7]}, not 994d660: the pin is {len(chain)} commits on 994d660, not the squashed"
          f" release commit — the derived script's A0 refuses it (PIN_PARENT); derive again with the release commit")
manifest = git(rel, "show", f"{pin}:{PLUGIN}/manifest.json").decode()
if '"version": "5.18.105"' not in manifest:
    raise SystemExit("the pin's manifest.json does not say 5.18.105")
if '"version": "5.18.104"' not in git(rel, "show", f"{BASE_FULL}:{PLUGIN}/manifest.json").decode():
    raise SystemExit("994d660's manifest.json does not say 5.18.104")

src = os.path.join(repo, "scripts/deploy-5.18.104.sh")
src_text = open(src, encoding="utf-8").read()
doc = Doc(src_text)
ctx = {"repo": rel, "plugin": PLUGIN, "base_full": BASE_FULL, "pin_full": pin, "dev": repo,
       "src_sha": hashlib.sha256(src_text.encode()).hexdigest()}
parts_log = []
for name in PARTS:
    mod = __import__(name)
    before = len(doc.log)
    mod.apply(doc, ctx)
    parts_log.append((name, len(doc.log) - before))
    print(f"  {name}: {len(doc.log) - before} edits ({len(doc.log)} so far)")

# the result, as a whole: no 5.18.104 identity left where a 5.18.105 one belongs
doc.must('EXPECTED_VERSION="5.18.105"\n', 1)
doc.must('BASELINE_VERSION="5.18.104"\n', 1)
doc.must(f'EXPECTED_PLUGIN_COMMIT_FULL="{pin}"', 1)
doc.must(f'BASELINE_COMMIT_FULL="{BASE_FULL}"\n', 1)
doc.must('BASELINE_COMMIT="994d660"', 1)
doc.mustnot('EXPECTED_PLUGIN_COMMIT="994d660"')
doc.mustnot('OUT="${DNB_OUT:-/root/dnb-5.18.104}"')
doc.mustnot("@@")


# ── keep_check (§H.4.1): the ladder kept, judged over the whole text; a list of the reasons it fails, by tag ──
def keep_check(t):
    lines = t.split("\n")
    code = [(n, l) for n, l in enumerate(lines, 1) if not l.lstrip().startswith(("#", "//"))]
    bad = []
    # K3 — QF-RB1 is not called again, neither in V1's automatic rollback nor in --rollback, and is defined nowhere
    v1 = [l for _, l in code if "ROLLING BACK — the public address must never redirect" in l]
    if len(v1) != 1 or "do_rollback" not in v1[0]:
        bad.append(("K3", f"V1's automatic rollback line found {len(v1)} times"))
    elif re.search(r"\bqf_rb1\b|\bqf_tool\b|QF_WRAPPER|sales-followup\.sh", v1[0].split("do_rollback")[0]):
        bad.append(("K3", "V1's automatic rollback runs QF-RB1 (or the switch tool) before its put-back"))
    if any(re.search(r"\bqf_rb1\b", l) for _, l in code):
        bad.append(("K3", "qf_rb1 is in the code"))
    # K1 — no clear: no clear or by-hand put-back function, the switch tool only shown or checked, the wrapper printed
    for n, l in code:
        if re.search(r"\b(qf_clear_and_baseline|qf_byhand)\b", l):
            bad.append(("K1", f"line {n}: a clear call ({l.strip()[:80]})"))
        for m in re.finditer(r'qf_tool "\$[A-Za-z0-9_]+(?:/ss)?" ([A-Za-z-]+)', l):
            if m.group(1) not in ("show", "--check", "doctor"):
                bad.append(("K1", f"line {n}: the switch tool runs {m.group(1)}"))
        if "$QF_WRAPPER" in l and not l.lstrip().startswith("echo "):
            bad.append(("K1", f"line {n}: the wrapper is run"))
    # K2 — LK-CA1: defined with its verdicts, called once, from qf_after (the deploy run's after-copy checks)
    m = re.search(r"(?ms)^lk_ca1\(\) \{\n(.*?)^\}", t)
    if not m or 'ok "LK-CA1' not in m.group(1) or 'bad "LK-CA1' not in m.group(1) or '"$(lk_map "$LK_B1")"' not in m.group(1):
        bad.append(("K2", "lk_ca1 is not defined with its comparison and both verdicts"))
    qa = re.search(r"(?ms)^qf_after\(\) \{\n(.*?)^\}", t)
    calls = [l for _, l in code if re.match(r"\s+lk_ca1\b", l)]
    if not qa or len(calls) != 1 or not re.search(r"(?m)^\s+lk_ca1\b", qa.group(1)):
        bad.append(("K2", f"LK-CA1 is not called once from qf_after ({len(calls)} call(s))"))
    # K4 — quote_followup_state's "never fell" count is over its rows NOT LIKE 'list_last:%', and the whole count a note
    kept = [l for _, l in code if "'quote_followup_state_kept:'" in l]
    if len(kept) != 1 or "$cnt('quote_followup_state', \" WHERE k NOT LIKE 'list_last:%'\")" not in kept[0]:
        bad.append(("K4", "quote_followup_state's never-fell count is not taken over its rows NOT LIKE 'list_last:%'"))
    tl = re.search(r"foreach \(\['quote_tracker'.*?\] as \$t\)", t, re.S)
    if not tl or "'quote_followup_state'" in tl.group(0):
        bad.append(("K4", "quote_followup_state is counted whole among the tables that never fall"))
    return bad


kb = keep_check(doc.t)
if kb:
    raise SystemExit("KEEP CHECK: " + "; ".join(f"{a}: {b}" for a, b in kb))


def _kweak(old, new, tag, what):
    if doc.t.count(old) != 1:
        raise SystemExit(f"KEEP CHECK CONTROL ({what}): its anchor is found {doc.t.count(old)} times: {old[:100]!r}")
    got = keep_check(doc.t.replace(old, new))
    tags = sorted({a for a, _ in got})
    if tags != [tag]:
        raise SystemExit(f"KEEP CHECK CONTROL ({what}): refused for {tags or 'nothing'}, not for {tag} alone: {got[:3]}")


_kweak("  qf_keep_and_baseline\n", "  qf_clear_and_baseline\n  qf_keep_and_baseline\n", "K1", "the clear call re-inserted")
_ca1 = [l for l in doc.t.split("\n") if l.startswith("  lk_ca1 ")]
if len(_ca1) != 1:
    raise SystemExit(f"KEEP CHECK CONTROL: the LK-CA1 call line found {len(_ca1)} times")
_kweak(_ca1[0] + "\n", "", "K2", "LK-CA1 removed")
_kweak('echo "  ROLLING BACK — the public address must never redirect (every switch kept)"; if do_rollback',
       'echo "  ROLLING BACK — the public address must never redirect (every switch kept)"; qf_rb1; if do_rollback',
       "K3", "QF-RB1 called again in V1")
_kweak("$cnt('quote_followup_state', \" WHERE k NOT LIKE 'list_last:%'\")", "$cnt('quote_followup_state')", "K4",
       "quote_followup_state counted over every row")
print("  keep check: the ladder kept (K1–K4); four weakened copies, each refused for its own reason alone")

# ── scripts/sales-followup-report.sh, re-rendered (§H.4.2): 5.18.104's template, its words for 5.18.105 by asserted edits,
#    its pins from Git; QSE_SHA256 and the five installed sums asserted equal at 994d660 and the pin and equal to the
#    committed wrapper's, the cut keys equal to the committed wrapper's ──
rwd = Doc(open(os.path.join(HERE, "..", "derive-5.18.104", "report_wrapper.sh.txt"), encoding="utf-8").read())
rwd.lines_between("# sales-followup-report.sh — P1, 5.18.104's read-only sales follow-up report, run on the server by hand (Uganda; root",
                  "# the plugin's, nothing in uCRM. As root on the host:",
    "# sales-followup-report.sh — P1, the read-only sales follow-up report at 5.18.105 (5.18.104's, its BY NUMBER column\n"
    "# padded by characters: root docs/74 §H.4.2), run on the server by hand (Uganda; root docs/73 §H.2.8, §H.2.21 9.5).\n"
    "# READ-ONLY: it reads a COPY of the book and changes nothing — no table, no key, no file of the plugin's, nothing in\n"
    "# uCRM. As root on the host:", why="title")
rwd.lines_between("#   2. it takes the report, tools/sales_followup_report.php, and the code it reads — lib/QuoteSendEvidence.php and",
                  "#      the report runs on 5.18.103 as well);",
    "#   2. it takes the report, tools/sales_followup_report.php, and the code it reads — lib/QuoteSendEvidence.php,\n"
    "#      lib/HumanTakeover.php and the rest of the release's lib/ beside them (WaSendBudget and its readers, on a book that\n"
    "#      already holds 089's ledger) and profiles/ — from Git at the reviewed 5.18.105 commit (@@PIN@@), the report's and\n"
    "#      QuoteSendEvidence's sha256 checked; and it asserts that the INSTALLED PhoneNumber, TenantProfile, PluginConfig,\n"
    "#      ConfigVault and ContactOptOut equal that commit's bytes (they are 5.18.104's too, so the report runs on 5.18.104,\n"
    "#      before the deploy and after a rollback, as well);", why="step 2")
rwd.sub("# There is no rollback, because nothing changes. This file is written by wip/derive-5.18.104/derive104.py from the\n",
        "# There is no rollback, because nothing changes. This file is written by wip/derive-5.18.105/derive105.py from the\n",
        why="written by")
rwd.sub("# the installed files the report reads that 5.18.103 and 5.18.104 share byte for byte (§H.2.21 9.5 step 2)\n",
        "# the installed files the report reads that 5.18.104 and 5.18.105 share byte for byte (§H.2.21 9.5 step 2)\n",
        why="sums comment")
rwd.sub("the installed $N files it shares with 5.18.103 equal that commit's bytes", "the installed $N files it shares with 5.18.104 equal that commit's bytes",
        why="code line")
rwd.mustnot("5.18.103")
rw = rwd.t
kept5 = ["lib/PhoneNumber.php", "lib/TenantProfile.php", "lib/PluginConfig.php", "lib/ConfigVault.php", "lib/ContactOptOut.php"]
sums = []
for f in kept5:
    a, b = sha_at(rel, BASE_FULL, f"{PLUGIN}/{f}"), sha_at(rel, pin, f"{PLUGIN}/{f}")
    if a != b:
        raise SystemExit(f"REPORT WRAPPER: {f} differs between 5.18.104 and the pin — the report could not run on 5.18.104")
    sums.append(f"{b}  {f}")
qse = sha_at(rel, pin, f"{PLUGIN}/lib/QuoteSendEvidence.php")
if qse != sha_at(rel, BASE_FULL, f"{PLUGIN}/lib/QuoteSendEvidence.php"):
    raise SystemExit("REPORT WRAPPER: lib/QuoteSendEvidence.php differs between 5.18.104 and the pin (§H.4.2: it stays the same)")


def _src(f):
    return git(rel, "show", f"{pin}:{PLUGIN}/{f}").decode()


_read = re.compile(r"\$(?:this->)?(?:config|sfrConfig|cfg)\[\s*'([a-z_0-9]+)'\s*\]")
_set = re.compile(r"setting\(\$(?:this->)?(?:config|cfg), '([a-z_0-9]+)'\)")
_evo = ["evo_instance_sales", "evo_instance_support", "evo_instance_account"]
report_reads = set(_read.findall(_src("tools/sales_followup_report.php"))) | set(_read.findall(_src("lib/QuoteSendEvidence.php")))
if "'evo_instance_' ." in _src("tools/sales_followup_report.php"):
    report_reads |= set(_evo)
path_reads = set(report_reads)
for f in ["lib/WaSendBudget.php", "lib/ReminderDay.php"]:
    s = _src(f)
    path_reads |= set(_read.findall(s)) | set(_set.findall(s))
    if "'evo_instance_' ." in s:
        path_reads |= set(_evo)
path_reads |= set(re.findall(r"=> '(wa_voice_ack_[a-z_]+)'", _src("lib/VoiceAckLimits.php")))
cut = sorted(path_reads | {"tenant_profile", "timezone", "quote_tracking_since", "wa_send_ledger", "wa_send_ledger_since"} | set(_evo))
_sk = re.search(r"const SECRET_KEYS = \[(.*?)\];", _src("lib/PluginConfig.php"), re.S)
secrets = set(re.findall(r"'([a-z_0-9]+)'", _sk.group(1))) if _sk else set()
if not secrets or "openai_api_key" not in secrets:
    raise SystemExit("REPORT WRAPPER: PluginConfig::SECRET_KEYS could not be read")
if secrets & set(cut):
    raise SystemExit(f"REPORT WRAPPER: the cut would keep a secret: {sorted(secrets & set(cut))}")
if not report_reads <= set(cut) or "wa_force_accounts" not in cut or "quote_tracking_since" not in report_reads:
    raise SystemExit(f"REPORT WRAPPER: a key the report reads is not kept: {sorted(report_reads - set(cut))}")
if any(not re.fullmatch(r"[a-z_0-9]+", k) for k in cut):
    raise SystemExit("REPORT WRAPPER: a key cannot travel as a PHP string")
fills = {"@@PIN@@": (pin[:7], 2), "@@PIN_FULL@@": (pin, 1), "@@RELEASE_BRANCH@@": ("release/5.18.105", 1),
         "@@CUT_KEYS@@": (", ".join('"' + k + '"' for k in cut), 1),
         "@@TOOL_SHA@@": (sha_at(rel, pin, f"{PLUGIN}/tools/sales_followup_report.php"), 1),
         "@@QSE_SHA@@": (qse, 1), "@@KEPT_SUMS@@": ("\n".join(sums), 1)}
for k, (v, n) in fills.items():
    if rw.count(k) != n:
        raise SystemExit(f"REPORT WRAPPER: {k} found {rw.count(k)} times, expected {n}")
    rw = rw.replace(k, v)
if "@@" in rw:
    raise SystemExit("REPORT WRAPPER: a placeholder is left")
_a = rw.index("php -d display_errors=stderr -r '\n") + len("php -d display_errors=stderr -r '\n")
_b = rw.index("\n' \"$DB\" \"$W\" \"$IN_CONTAINER\" \"$PDD\"", _a)
if "'" in rw[_a:_b]:
    raise SystemExit("REPORT WRAPPER: an apostrophe inside the single-quoted PHP of the copy")
for s in [f'RELEASE_COMMIT_FULL="{pin}"', 'RELEASE_BRANCH="release/5.18.105"', "READ-ONLY — nothing was changed", '"wa_force_accounts"']:
    if s not in rw:
        raise SystemExit(f"REPORT WRAPPER: the result does not hold {s!r}")
rw_out = os.path.join(os.path.dirname(os.path.abspath(out)), "sales-followup-report.sh")
# against the committed wrapper (5.18.104's): QSE_SHA256, the five sums and the cut keys the same; the pins moved
committed = git(repo, "show", "HEAD:scripts/sales-followup-report.sh").decode()
for pat in (r'(?m)^QSE_SHA256="[0-9a-f]{64}"', r"(?s)INSTALLED_SUMS=\"\$\(cat <<'SUMS'\n.*?\nSUMS", r"(?m)^.*\"wa_force_accounts\".*$"):
    a, b = re.search(pat, committed), re.search(pat, rw)
    if not a or not b or a.group(0) != b.group(0):
        raise SystemExit(f"REPORT WRAPPER: {pat[:30]!r} is not the committed wrapper's")
if sha_at(rel, pin, f"{PLUGIN}/tools/sales_followup_report.php") == sha_at(rel, BASE_FULL, f"{PLUGIN}/tools/sales_followup_report.php"):
    raise SystemExit("REPORT WRAPPER: the report is 5.18.104's at the pin — §H.4.2 re-renders it for its column fix")
print(f"  report wrapper: pinned to {pin[:7]}, release/5.18.105, the report's sha256 from Git; QuoteSendEvidence, the five"
      f" installed sums and the {len(cut)} cut keys as the committed wrapper has them")

# ── the run's folder (DS3-fix1, carried): made in the main shell before any subshell reads it — and three weakened
#    copies, each refused for its own reason (rb_pre105 without its cih_main; vn_book_copy making the folder in its
#    $( … ); qf_after without its cih_main) ──
print("  folder check: " + folder_check(doc.t))


def _fweak(old, new, tag, name):
    if doc.t.count(old) != 1:
        raise SystemExit(f"FOLDER CHECK CONTROL: its anchor is found {doc.t.count(old)} times: {old[:100]!r}")
    try:
        folder_check(doc.t.replace(old, new))
    except SystemExit as e:
        if tag in str(e) and name in str(e):
            return
        raise SystemExit(f"FOLDER CHECK CONTROL: the weakened copy ({name}) was refused for another reason: {str(e)[:300]}")
    raise SystemExit(f"FOLDER CHECK CONTROL: a weakened copy passed ({tag}, {name})")


_rb0 = [l for l in doc.t.split("\n") if l.startswith("  cih_main || note \"LK-RB0 the run's folder")]
if len(_rb0) != 1:
    raise SystemExit(f"FOLDER CHECK CONTROL: rb_pre105's cih_main line found {len(_rb0)} times")
_fweak(_rb0[0] + "\n", "", "(B)", "rb_pre105")
_fweak('  [ -n "$CIH_BASE" ] || return 1   # never cih_init here: vn_book_copy runs in $( … ) (cih_main)\n', "  cih_init || return 1\n", "(A)", "vn_book_copy")
_qa = 'qf_after() {\n  local inst="$1" sub o n v bad j\n'
_qa_main = "  cih_main || true   # the run's folder, made in this shell before the readings on copies below, which run in $( … ) (DS3-fix1)\n"
_fweak(_qa + _qa_main, _qa, "(B)", "qf_after")
print("  folder check control: three weakened copies, each refused for its own reason")


# bash -n on the result and on both wrappers, before anything is written (the parser only: nothing is run)
def bash_n(text, what):
    with tempfile.NamedTemporaryFile("w", suffix=".sh", delete=False, encoding="utf-8") as f:
        f.write(text)
    try:
        r = subprocess.run(["bash", "-n", f.name], capture_output=True)
    finally:
        os.unlink(f.name)
    if r.returncode != 0:
        raise SystemExit(f"BASH -N: {what}: {r.stderr.decode()[:400]}")


bash_n(doc.t, "the deploy script")
bash_n(rw, "scripts/sales-followup-report.sh")
fw = open(os.path.join(repo, "scripts/sales-followup.sh"), encoding="utf-8").read()
if fw != git(repo, "show", "HEAD:scripts/sales-followup.sh").decode():
    raise SystemExit("scripts/sales-followup.sh is not the committed one — §H.4.7: it stays as it is")
bash_n(fw, "scripts/sales-followup.sh")

tmp = out + ".tmp"
open(tmp, "w", encoding="utf-8").write(doc.t)
os.chmod(tmp, 0o755)
os.replace(tmp, out)
open(rw_out + ".tmp", "w", encoding="utf-8").write(rw)
os.chmod(rw_out + ".tmp", 0o755)
os.replace(rw_out + ".tmp", rw_out)
print(f"wrote {rw_out}: {rw.count(chr(10))} lines, sha256 {hashlib.sha256(rw.encode()).hexdigest()}")
print(f"wrote {out}: {doc.t.count(chr(10))} lines; added {ctx['n_added']}, changed {ctx['n_changed']}, kept {ctx['n_kept']}; "
      f"LM {ctx['lm_n']} hunks ({ctx['lm_ln_n']} LN); source sha256 {ctx['src_sha'][:16]}…, result sha256 "
      f"{hashlib.sha256(doc.t.encode()).hexdigest()}")
print("parts: " + ", ".join(f"{n} {e}" for n, e in parts_log) + f"; total {len(doc.log)}")
if not CUT_ON_BASE:
    print("  WARNING (again): this pin is not cut on 994d660 — the script refuses it at A0 until derived on the release commit")
