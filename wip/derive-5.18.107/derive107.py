#!/usr/bin/env python3
# derive107.py — scripts/deploy-5.18.107.sh from scripts/deploy-5.18.106.sh by asserted edits (root docs/76 §T).
# scripts/sales-followup-report.sh and scripts/sales-followup.sh stay as they are (§T.2): asserted, never re-rendered.
# Usage: python3 -I derive107.py REPO RELEASE_REPO PIN_FULL [OUT]
#   REPO          the development work tree (the source is REPO/scripts/deploy-5.18.106.sh, as committed in 745e668)
#   RELEASE_REPO  a repository holding the release commit PIN_FULL, cut on 5.18.106's release commit (BASE below)
#   PIN_FULL      the release commit, in full: a parameter — if the release suite forces a new commit, re-run with it
# Every pin, count, file list and sha256 is computed from Git (BASE → PIN_FULL), never typed. Every edit asserts its
# anchor, its count, its replacements and its result (d107lib, a copy of d106lib); any failure exits non-zero and
# writes nothing. 5.18.106's edits are not re-run (§T.1): they transformed 5.18.105's text, whose anchors are gone; its
# Git assertions are reused, re-pointed.
#
# The parts, in the order they run (each a module of this folder with apply(doc, ctx)), §T's sections in brackets:
#   partA   identity [T.2]: the pin, the baseline (bf29cbd, live), PREV2–PREV10 (5.18.97 kept in reach), the three sha256
#           lists — PREV_KEPT = (5.18.106's kept ∪ its pinned) − 5.18.107's delta, PREV_CHANGED and PIN_SHA from Git — the
#           counts, COPY_MARK (cron/followup_send.php, kept), QC-RB1's words and control
#   partB   the carried checks re-asserted from Git [T.2]: master, the signatures, the event processor's two lists and
#           its probe for wa.no_ai_alert, QF_HOOKS, T1, T4, the text pins in files 5.18.107 changes, the reports
#   partMG  migration 092 [T.3]: A0's rule, MG-G1, QF-G2 extended to 092, neg092, NA-G1 and NA-CA1, MG-CA1 and MG-CA2,
#           R7 (db_mig107), LK-CA2's and LK-RB1's _schema_checks rows, the state lines MG_G1, QF_COPY_UNIX, MG_CA2_SEEN
#   partNS  the switches [T.4]: CS-G1, CS-B1 and FE-G1 retired; NS-G1 and NS-B1 (the lk mode's per-place verdicts and
#           their positive control); the lk key list; lk_read on the pin alone
#   partSA  the readings before the deploy [T.5]: U1–U5 and the uj mode retired; stage A's notes
#   partME  after the copy [T.6]: UJ-CA and UJ-ON retired; ME-CA1 on master's own record and its poll; ME-CA2; NOT YET
#   partL   the masking carried [T.2]: LM's same-file rule for the two changed files
#   partR   the rollback [T.7]: AS-RB1 and its family retired; rb_pre107 and rb107; V1; RB_NOTHING; the put-back's tools
#           reading and its commands
#   partT   the carried texts [T.2]: the texts already stale at 5.18.106, and the files 5.18.107 now changes
#   partH   what the operator reads [T.9]: the header, the options, the summary and its owner steps
#   partZ   the rules asserted over the whole result [T.8]
# Then keep_check K1–K6 over the result, each with weakened copies refused for their own reason alone; folder_check and
# its weakened copies; `bash -n` on the result and both wrappers. Nothing is run: no PHP, no shell beyond `bash -n`.
# DERIVE_PARTS (development only): a comma-separated prefix of PARTS; the result is then written only to OUT, which must
# be given and must not be the committed script's path, and the whole-text checks are not run.
import sys, os, hashlib, subprocess, tempfile, re
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from d107lib import Doc, git, sha_at, folder_check

BASE_FULL = "bf29cbd7171639f93e674ab174a6d09948b68932"   # release/5.18.106, the live release when 5.18.107 is deployed
SRC_COMMIT = "745e668"                                   # scripts/deploy-5.18.106.sh as committed
SRC_SHA256 = "3189cee22818fb3a"                          # its sha256 (prefix)
REPORT_PIN = "7835906a0e4af49557f7c0eb994cb9129637ca00"  # scripts/sales-followup-report.sh stays pinned to 5.18.105's commit
PLUGIN = "dishnet-hybrid-sudan"
PARTS = ["partA", "partB", "partMG", "partNS", "partSA", "partME", "partL", "partR", "partT", "partH", "partZ"]
HERE = os.path.dirname(os.path.abspath(__file__))

if len(sys.argv) < 4:
    raise SystemExit("usage: derive107.py REPO RELEASE_REPO PIN_FULL [OUT]")
repo, rel, pin = sys.argv[1], sys.argv[2], sys.argv[3]
committed_out = os.path.join(repo, "scripts/deploy-5.18.107.sh")
out = sys.argv[4] if len(sys.argv) > 4 else committed_out
dev_parts = os.environ.get("DERIVE_PARTS", "")
if dev_parts:
    want = dev_parts.split(",")
    if want != PARTS[:len(want)]:
        raise SystemExit(f"DERIVE_PARTS must be a prefix of {PARTS}")
    if len(sys.argv) <= 4 or os.path.abspath(out) == os.path.abspath(committed_out):
        raise SystemExit("DERIVE_PARTS writes only to an OUT given on the command line, never the committed script's path")
    PARTS = want

# ── the pin, refused unless it is what §T.1 says ──
if len(pin) != 40 or any(ch not in "0123456789abcdef" for ch in pin):
    raise SystemExit(f"PIN_FULL must be a full 40-hex commit, got {pin!r}")
if git(rel, "rev-parse", pin).decode().strip() != pin:
    raise SystemExit("the pin does not name itself in RELEASE_REPO")
if git(rel, "rev-parse", BASE_FULL).decode().strip() != BASE_FULL:
    raise SystemExit("RELEASE_REPO does not hold 5.18.106's release commit")
parent = git(rel, "rev-parse", pin + "^").decode().strip()
if parent != BASE_FULL:
    raise SystemExit(f"the pin's parent is {parent}, not 5.18.106's {BASE_FULL}: §T.1 derives only on one commit on bf29cbd")
if len(git(rel, "log", "--format=%P", "-1", pin).decode().split()) != 1:
    raise SystemExit("the pin is a merge commit")
if '"version": "5.18.107"' not in git(rel, "show", f"{pin}:{PLUGIN}/manifest.json").decode():
    raise SystemExit("the pin's manifest.json does not say 5.18.107")
if '"version": "5.18.106"' not in git(rel, "show", f"{BASE_FULL}:{PLUGIN}/manifest.json").decode():
    raise SystemExit("bf29cbd's manifest.json does not say 5.18.106")
abbrev = git(rel, "log", "-1", "--format=%h", pin, "--", PLUGIN).decode().strip()
if len(abbrev) != 7 or not pin.startswith(abbrev):
    raise SystemExit(f"the pin's short form, as A0 reads it (git log -1 --format=%h <pin> -- {PLUGIN}), is {abbrev!r}, "
                     "not 7 characters of the pin")
outside = [p for p in git(rel, "diff", "--name-only", BASE_FULL, pin).decode().split("\n") if p and not p.startswith(PLUGIN + "/")]
if outside:
    raise SystemExit(f"the pin touches a path outside {PLUGIN}/ (A7 would refuse it; §T.2: the release carries the plugin "
                     f"tree only): {outside[:3]}")

src = os.path.join(repo, "scripts/deploy-5.18.106.sh")
src_text = open(src, encoding="utf-8").read()
src_sha = hashlib.sha256(src_text.encode()).hexdigest()
if not src_sha.startswith(SRC_SHA256):
    raise SystemExit(f"the source is not scripts/deploy-5.18.106.sh as committed in {SRC_COMMIT} (sha256 {src_sha[:16]}…)")
if src_text != git(repo, "show", f"{SRC_COMMIT}:scripts/deploy-5.18.106.sh").decode():
    raise SystemExit(f"scripts/deploy-5.18.106.sh differs from the one committed in {SRC_COMMIT}")
doc = Doc(src_text)
ctx = {"repo": rel, "plugin": PLUGIN, "base_full": BASE_FULL, "pin_full": pin, "dev": repo, "src_sha": src_sha,
       "src_text": src_text, "report_pin": REPORT_PIN}
parts_log = []
for name in PARTS:
    mod = __import__(name)
    before = len(doc.log)
    mod.apply(doc, ctx)
    parts_log.append((name, len(doc.log) - before))
    print(f"  {name}: {len(doc.log) - before} edits ({len(doc.log)} so far)")


def bash_n(text, what):
    with tempfile.NamedTemporaryFile("w", suffix=".sh", delete=False, encoding="utf-8") as f:
        f.write(text)
    try:
        r = subprocess.run(["bash", "-n", f.name], capture_output=True)
    finally:
        os.unlink(f.name)
    if r.returncode != 0:
        raise SystemExit(f"BASH -N: {what}: {r.stderr.decode()[:400]}")


def write_out(text):
    tmp = out + ".tmp"
    open(tmp, "w", encoding="utf-8").write(text)
    os.chmod(tmp, 0o755)
    os.replace(tmp, out)


if dev_parts:
    bash_n(doc.t, "the partial result")
    write_out(doc.t)
    print(f"DEVELOPMENT ONLY ({','.join(PARTS)}): wrote {out}: {doc.t.count(chr(10))} lines; the whole-text checks were not run")
    raise SystemExit(0)

import keepcheck107
keepcheck107.run(doc, ctx)

# ── the two wrappers stay as committed (§T.2): scripts/sales-followup-report.sh is pinned to 7835906 and its seven inputs
#    are unchanged at the pin; scripts/sales-followup.sh is 5.18.104's ──
rw = open(os.path.join(repo, "scripts/sales-followup-report.sh"), encoding="utf-8").read()
if rw != git(repo, "show", "HEAD:scripts/sales-followup-report.sh").decode():
    raise SystemExit("scripts/sales-followup-report.sh is not the committed one — §T.2: it stays pinned to 7835906")
if f'RELEASE_COMMIT_FULL="{REPORT_PIN}"' not in rw:
    raise SystemExit("scripts/sales-followup-report.sh is not pinned to 7835906")
for f in ["tools/sales_followup_report.php", "lib/QuoteSendEvidence.php", "lib/PhoneNumber.php", "lib/TenantProfile.php",
          "lib/PluginConfig.php", "lib/ConfigVault.php", "lib/ContactOptOut.php"]:
    if sha_at(rel, pin, f"{PLUGIN}/{f}") != sha_at(rel, REPORT_PIN, f"{PLUGIN}/{f}"):
        raise SystemExit(f"REPORT WRAPPER: {f} differs between 7835906 and the pin — the pinned wrapper would no longer hold")
print("  report wrapper: as committed, pinned to 7835906; its seven inputs unchanged at the pin")
bash_n(doc.t, "the deploy script")
bash_n(rw, "scripts/sales-followup-report.sh")
fw = open(os.path.join(repo, "scripts/sales-followup.sh"), encoding="utf-8").read()
if fw != git(repo, "show", "HEAD:scripts/sales-followup.sh").decode():
    raise SystemExit("scripts/sales-followup.sh is not the committed one — it stays as it is")
bash_n(fw, "scripts/sales-followup.sh")

write_out(doc.t)
print(f"wrote {out}: {doc.t.count(chr(10))} lines; added {ctx['n_added']}, changed {ctx['n_changed']}, kept {ctx['n_kept']}; "
      f"source sha256 {src_sha[:16]}…, result sha256 {hashlib.sha256(doc.t.encode()).hexdigest()}")
print("parts: " + ", ".join(f"{n} {e}" for n, e in parts_log) + f"; total {len(doc.log)}")
