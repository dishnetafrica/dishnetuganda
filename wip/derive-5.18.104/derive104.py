#!/usr/bin/env python3
# derive104.py — scripts/deploy-5.18.104.sh from scripts/deploy-5.18.103.sh by asserted edits (root docs/73 §H.2.15).
# Usage: python3 -I derive104.py REPO RELEASE_REPO PIN_FULL [OUT]
#   REPO          the development work tree (the source is REPO/scripts/deploy-5.18.103.sh)
#   RELEASE_REPO  a repository holding the release commit PIN_FULL, cut on 5.18.103's release commit (BASE below)
#   PIN_FULL      the release commit, in full: a parameter — if the release suite forces a new commit, re-run with it
# Every pin, count, file list and sha256 is computed from Git (BASE → PIN_FULL), never typed. Every edit asserts its
# anchor, its count, its replacements and its result (d104lib); any failure exits non-zero and writes nothing.
#
# The parts, in the order they run (each a module of this folder with apply(doc, ctx)):
#   partA  identity: the pin, the baseline, the earlier state files, the three sha256 lists, the counts (DS1)
#   partB  the carried 5.18.103 checks the delta re-points: A0's migrations, master's pin, A6/R13/RB's event processor,
#          the copy's time mark, R4's undeployed-work list, R7's migration ledger (DS1)
#   partF  Part F's QF checks: stage A (QF-G1–QF-G4, QF-N1–QF-N7), the redeploy baseline, --clear-switches, after the
#          copy (QF-CA1–QF-CA8), --after-only (QF-ON0–QF-ON5, AC-ON1's slot), and the slots DS2–DS4 fill (DS1)
#   partR  the one rollback sequence: QF-RB1 before the put-back, QF-RB2, QF-RB3 and the by-hand put-back (DS1); and
#          5.18.103's QC-RB1 re-pointed to what 5.18.104 adds, its words, marks and control from Git (DS1-fix1)
#   partH  what the operator reads: the header's title and description, the options, the summary (DS1)
#   partV  Part V's VN and VA: stage A (VN-G1–VN-G7), VN-CA4's baseline in QF-G1's own call, after the copy (VN-M1, VN-M2,
#          VN-CA1–VN-CA4, VN-K1, VN-K2), --after-only (VA-ON1, VN-ON1–VN-ON4), the rollback (VN-RB1–VN-RB6) (DS2)
#   partO  Part O's OF: stage A (OF-G1, OF-G2, OF-N1–OF-N3 on QF-G2's trial copy, its census bracketing the trial), OF-CA3's
#          baseline in QF-G1's own call, after the copy (OF-M1, OF-M2, OF-CA1–OF-CA5), --after-only (OF-ON1, OF-ON2), the
#          rollback (OF-RB1 before the put-back, OF-RB2 after it); Part O's by-hand statements (ruling R17) asserted equal to
#          D §H.4.19's and run against 091 in sqlite3 (DS3)
#          — and (DS3-fix1) each part's entry makes the run's folder in the main shell (cih_main), qf_copy and vn_book_copy
#          never make it in their $( … ), and OF-RB1's test clause is bounded by QF-RB1's start (QF_RB1_START)
#   partP  Part P's AC: stage A (AC-L1, a NO-GO before anything is copied; AC-N1, AC-N2 notes on a copy through the pin's
#          code), after the copy (AC-CA1; AC-CA2's positive and negative controls on a copy, made unable to reach a live system
#          first, through the installed code and 5.18.103's, uCRM the pin's recording fake, the pin's loopback relay),
#          --after-only (AC-ON1), the rollback (AC-RB1 after QF-RB1, before the put-back, with its before-reading of
#          quote_agent_cc_admins inside QF-RB1; AC-RB2 after it) (DS4)
#   partC  the carried texts the 5.18.103 → 5.18.104 step left naming the wrong release, the mechanism defects of the
#          same kind, and the rehearsal's four script findings S1–S4 (root docs/73 §H.2.15, §H.2.16; coordinator item 20)
#          (DS5)
#   partT  what the rehearsal's development trials found and the derivation never re-pointed: R6's two literals the
#          pin moves on purpose (T-D1), QF-N2's age limits compared as numbers (T-D2), and every reading of a copy naming
#          a vault of its own — the 28 that load no configuration one never made, in their own folder (T-D3)
#   partZ  the final consistency pass (DS4): every slot filled or deliberately closed, then closed (no marker survives);
#          every code the design names for the deploy script reported in its stage; none retired; the summary's steps and
#          the printed rollback block in the one order
# partV (DS2) also keeps three of DS1's readings' lines for its own reads, has QF-G1's baseline call take VN-CA4's in the same
# moment, and makes QF-RB3's VN-RB6 note its own instruction (partV.py's header). Before anything is written, the result and
# both wrappers pass `bash -n` (the parser only: nothing is run), and the whole result passes d104lib.folder_check (DS3-fix1:
# the run's folder made in the main shell before any subshell reads it), beside three weakened copies it must refuse.
import sys, os, hashlib, subprocess, tempfile
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from d104lib import Doc, git

BASE_FULL = "221c9dcf04f8281888d77761ae019f59cc1d9c0a"   # origin/release/5.18.103, the live release
PLUGIN = "dishnet-hybrid-sudan"
PARTS = ["partA", "partB", "partF", "partR", "partH", "partV", "partO", "partP", "partC", "partT", "partZ"]

if len(sys.argv) < 4:
    raise SystemExit("usage: derive104.py REPO RELEASE_REPO PIN_FULL [OUT]")
repo, rel, pin = sys.argv[1], sys.argv[2], sys.argv[3]
out = sys.argv[4] if len(sys.argv) > 4 else os.path.join(repo, "scripts/deploy-5.18.104.sh")
if len(pin) != 40 or any(ch not in "0123456789abcdef" for ch in pin):
    raise SystemExit(f"PIN_FULL must be a full 40-hex commit, got {pin!r}")
# the pin is cut on the live release, and is what RELEASE_REPO calls it
parent = git(rel, "rev-parse", pin + "^").decode().strip()
if parent != BASE_FULL:
    raise SystemExit(f"the pin's parent is {parent}, not 5.18.103's {BASE_FULL}")
if git(rel, "rev-parse", pin).decode().strip() != pin:
    raise SystemExit("the pin does not name itself in RELEASE_REPO")
manifest = git(rel, "show", f"{pin}:{PLUGIN}/manifest.json").decode()
if '"version": "5.18.104"' not in manifest:
    raise SystemExit("the pin's manifest.json does not say 5.18.104")

src = os.path.join(repo, "scripts/deploy-5.18.103.sh")
src_text = open(src, encoding="utf-8").read()
doc = Doc(src_text)
ctx = {"repo": rel, "plugin": PLUGIN, "base_full": BASE_FULL, "pin_full": pin, "dev": repo,
       "src_sha": hashlib.sha256(src_text.encode()).hexdigest()}
for name in PARTS:
    mod = __import__(name)
    before = len(doc.log)
    mod.apply(doc, ctx)
    print(f"  {name}: {len(doc.log) - before} edits ({len(doc.log)} so far)")

# the result, as a whole: no 5.18.103 identity left where a 5.18.104 one belongs
doc.must('EXPECTED_VERSION="5.18.104"\n', 1)
doc.must('BASELINE_VERSION="5.18.103"\n', 1)
doc.must(f'EXPECTED_PLUGIN_COMMIT_FULL="{pin}"', 1)
doc.must(f'BASELINE_COMMIT_FULL="{BASE_FULL}"\n', 1)
doc.mustnot('EXPECTED_PLUGIN_COMMIT="221c9dc"')
doc.mustnot('OUT="${DNB_OUT:-/root/dnb-5.18.103}"')
doc.mustnot("# ── SLOT ")
doc.mustnot("@@")
# scripts/sales-followup-report.sh, rendered from report_wrapper.sh.txt with its pins from Git (root docs/73 §H.2.21 9.5):
# the release commit, its branch, the report's and QuoteSendEvidence's sha256, and the five installed files the report
# reads that 5.18.103 and 5.18.104 share byte for byte (asserted equal at both commits here). Written beside OUT.
from d104lib import sha_at
rw = open(os.path.join(os.path.dirname(os.path.abspath(__file__)), "report_wrapper.sh.txt"), encoding="utf-8").read()
kept5 = ["lib/PhoneNumber.php", "lib/TenantProfile.php", "lib/PluginConfig.php", "lib/ConfigVault.php", "lib/ContactOptOut.php"]
sums = []
for f in kept5:
    a, b = sha_at(rel, BASE_FULL, f"{PLUGIN}/{f}"), sha_at(rel, pin, f"{PLUGIN}/{f}")
    if a != b:
        raise SystemExit(f"REPORT WRAPPER: {f} differs between 5.18.103 and the pin — the report could not run on 5.18.103")
    sums.append(f"{b}  {f}")
# The keys the wrapper keeps in the copies it cuts (the settings row, the three files' copies, the vault's): the keys the
# report and the code it calls read (tools/sales_followup_report.php, lib/QuoteSendEvidence.php, lib/WaSendBudget.php and
# what WaSendBudget::counts() reads through lib/ReminderDay.php and lib/VoiceAckLimits.php), by the reading forms those
# files use, plus the tenant keys and the stamps the wrapper always kept. Asserted: every key the report itself reads is
# kept, wa_force_accounts (QuoteSendEvidence::forceAccounts, "sent from") among them, and none is a secret.
import re
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
print(f"  report wrapper: keeps {len(cut)} keys besides TenantProfile::SELECTOR_SOURCES ({len(report_reads)} the report reads itself)")
fills = {"@@PIN@@": (pin[:7], 2), "@@PIN_FULL@@": (pin, 1), "@@RELEASE_BRANCH@@": ("release/5.18.104", 1),
         "@@CUT_KEYS@@": (", ".join('"' + k + '"' for k in cut), 1),
         "@@TOOL_SHA@@": (sha_at(rel, pin, f"{PLUGIN}/tools/sales_followup_report.php"), 1),
         "@@QSE_SHA@@": (sha_at(rel, pin, f"{PLUGIN}/lib/QuoteSendEvidence.php"), 1), "@@KEPT_SUMS@@": ("\n".join(sums), 1)}
for k, (v, n) in fills.items():
    if rw.count(k) != n:
        raise SystemExit(f"REPORT WRAPPER: {k} found {rw.count(k)} times, expected {n}")
    rw = rw.replace(k, v)
if "@@" in rw:
    raise SystemExit("REPORT WRAPPER: a placeholder is left")
# the copy's PHP is one single-quoted shell word: an apostrophe in it would end the word (\x27 stands for one)
_a = rw.index("php -d display_errors=stderr -r '\n") + len("php -d display_errors=stderr -r '\n")
_b = rw.index("\n' \"$DB\" \"$W\" \"$IN_CONTAINER\" \"$PDD\"", _a)
if "'" in rw[_a:_b]:
    raise SystemExit("REPORT WRAPPER: an apostrophe inside the single-quoted PHP of the copy")
for s in [f'RELEASE_COMMIT_FULL="{pin}"', 'RELEASE_BRANCH="release/5.18.104"', "READ-ONLY — nothing was changed", '"wa_force_accounts"']:
    if s not in rw:
        raise SystemExit(f"REPORT WRAPPER: the result does not hold {s!r}")
rw_out = os.path.join(os.path.dirname(os.path.abspath(out)), "sales-followup-report.sh")

# DS3-fix1: the run's folder in the container's /tmp is made in the main shell before any subshell reads it, on every
# call path and so in every mode (d104lib.folder_check) — and its control on the control: three weakened copies of the
# result, each refused for the reason it was weakened (QF-RB1 without its cih_main; vn_book_copy making the folder in its
# $( … ) again; qf_after without its cih_main, as an --after-only run would reach it)
from d104lib import folder_check
print("  folder check: " + folder_check(doc.t))
def _weakened(old, new, tag, name):
    if doc.t.count(old) != 1:
        raise SystemExit(f"FOLDER CHECK CONTROL: its anchor is found {doc.t.count(old)} times: {old[:100]!r}")
    try:
        folder_check(doc.t.replace(old, new))
    except SystemExit as e:
        if tag in str(e) and name in str(e):
            return
        raise SystemExit(f"FOLDER CHECK CONTROL: the weakened copy ({name}) was refused for another reason: {str(e)[:300]}")
    raise SystemExit(f"FOLDER CHECK CONTROL: a weakened copy passed ({tag}, {name})")
_rb1_line = [l for l in doc.t.split("\n") if l.startswith("  cih_main || note \"QF-RB1 the run's folder")]
if len(_rb1_line) != 1:
    raise SystemExit(f"FOLDER CHECK CONTROL: QF-RB1's cih_main line found {len(_rb1_line)} times")
_weakened(_rb1_line[0] + "\n", "", "(B)", "of_rb1")
_weakened('  [ -n "$CIH_BASE" ] || return 1   # never cih_init here: vn_book_copy runs in $( … ) (cih_main)\n', "  cih_init || return 1\n", "(A)", "vn_book_copy")
import partO
_weakened(partO.ENTRIES["qf_after"] + partO.CIH_MAIN_LINE, partO.ENTRIES["qf_after"], "(B)", "qf_after")
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
bash_n(open(os.path.join(repo, "scripts/sales-followup.sh"), encoding="utf-8").read(), "scripts/sales-followup.sh")

tmp = out + ".tmp"
open(tmp, "w", encoding="utf-8").write(doc.t)
os.chmod(tmp, 0o755)
os.replace(tmp, out)
open(rw_out + ".tmp", "w", encoding="utf-8").write(rw)
os.chmod(rw_out + ".tmp", 0o755)
os.replace(rw_out + ".tmp", rw_out)
print(f"wrote {rw_out}: {rw.count(chr(10))} lines, sha256 {hashlib.sha256(rw.encode()).hexdigest()[:16]}…")
print(f"wrote {out}: {doc.t.count(chr(10))} lines; added {ctx['n_added']}, changed {ctx['n_changed']}, kept {ctx['n_kept']}; "
      f"source sha256 {ctx['src_sha'][:16]}…, result sha256 {hashlib.sha256(doc.t.encode()).hexdigest()[:16]}…")
