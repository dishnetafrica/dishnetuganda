#!/usr/bin/env python3
# derive106.py — scripts/deploy-5.18.106.sh from scripts/deploy-5.18.105.sh by asserted edits (root docs/75 §L).
# scripts/sales-followup-report.sh and scripts/sales-followup.sh stay as they are (§L.2, DL7): asserted, never re-rendered.
# Usage: python3 -I derive106.py REPO RELEASE_REPO PIN_FULL [OUT]
#   REPO          the development work tree (the source is REPO/scripts/deploy-5.18.105.sh, as committed in 2a41c9d)
#   RELEASE_REPO  a repository holding the release commit PIN_FULL, cut on 5.18.105's release commit (BASE below)
#   PIN_FULL      the release commit, in full: a parameter — if the release suite forces a new commit, re-run with it
# Every pin, count, file list and sha256 is computed from Git (BASE → PIN_FULL), never typed. Every edit asserts its
# anchor, its count, its replacements and its result (d106lib, a copy of d105lib); any failure exits non-zero and
# writes nothing.
#
# The parts, in the order they run (each a module of this folder with apply(doc, ctx)), §L's sections in brackets:
#   partA   identity [L.2]: the pin, the baseline (7835906, live), PREV2–PREV9 (5.18.97 added), the three sha256 lists —
#           PREV_KEPT = (5.18.105's kept ∪ its pinned) − 5.18.106's delta, PREV_CHANGED and PIN_SHA from Git — the counts,
#           no migration, COPY_MARK (cron/followup_send.php), QC-RB1's words and control, the report wrapper's pins
#   partB   the carried checks re-asserted from Git [L.2, L.6]: 089–091, master's two pins, the signatures, T1's files
#   partCS  the claim switches [L.3]: CS-G1 (stage A) and CS-B1 (after DEPLOY), LK-G1's two notes removed
#   partUJ  the readings [L.4, L.5]: QF_PHP's uj mode (U1–U5), conf's three keys, UJ_A, UJ-CA, UJ-ON, the NOT YET state
#   partME  ME-CA1 [L.5]: master's errors from the straddling cycle
#   partL   the masking carried [L.6]: LM-G1's fixed control 994d660, LM-RB1 inverted, every "numbers again" text gone
#   partR   the rollback [L.7]: rb_pre106 and rb106, the one reader, AS-RB1 and AS-RB2, OWNER_AUTOSEND_CMD, the put-back
#   partT   the carried texts [L.6, L.7]: the literal 5.18.104 lines, the reworded "writes nothing" and "reached by
#           nothing" texts
#   partH   what the operator reads [L.8]: the header, the options, the stages, the summary (three states) and its blocks
#   partZ   the rules asserted over the whole result
# Then keep_check K1–K5 over the result, each with weakened copies refused for their own reason alone; folder_check and
# its weakened copies; `bash -n` on the result and both wrappers. Nothing is run: no PHP, no shell beyond `bash -n`.
import sys, os, hashlib, subprocess, tempfile, re
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from d106lib import Doc, git, sha_at, folder_check

BASE_FULL = "7835906a0e4af49557f7c0eb994cb9129637ca00"   # release/5.18.105, the live release when 5.18.106 is deployed
SRC_SHA256 = "c70820cd69af899b"                          # scripts/deploy-5.18.105.sh as committed in 2a41c9d (prefix)
PLUGIN = "dishnet-hybrid-sudan"
PARTS = ["partA", "partB", "partCS", "partUJ", "partME", "partL", "partR", "partT", "partH", "partZ"]
HERE = os.path.dirname(os.path.abspath(__file__))

if len(sys.argv) < 4:
    raise SystemExit("usage: derive106.py REPO RELEASE_REPO PIN_FULL [OUT]")
repo, rel, pin = sys.argv[1], sys.argv[2], sys.argv[3]
out = sys.argv[4] if len(sys.argv) > 4 else os.path.join(repo, "scripts/deploy-5.18.106.sh")
if len(pin) != 40 or any(ch not in "0123456789abcdef" for ch in pin):
    raise SystemExit(f"PIN_FULL must be a full 40-hex commit, got {pin!r}")
if git(rel, "rev-parse", pin).decode().strip() != pin:
    raise SystemExit("the pin does not name itself in RELEASE_REPO")
if git(rel, "rev-parse", BASE_FULL).decode().strip() != BASE_FULL:
    raise SystemExit("RELEASE_REPO does not hold 5.18.105's release commit")
parent = git(rel, "rev-parse", pin + "^").decode().strip()
if parent != BASE_FULL:
    raise SystemExit(f"the pin's parent is {parent}, not 5.18.105's {BASE_FULL}: §L.1 derives only on one commit on 7835906")
if '"version": "5.18.106"' not in git(rel, "show", f"{pin}:{PLUGIN}/manifest.json").decode():
    raise SystemExit("the pin's manifest.json does not say 5.18.106")
if '"version": "5.18.105"' not in git(rel, "show", f"{BASE_FULL}:{PLUGIN}/manifest.json").decode():
    raise SystemExit("7835906's manifest.json does not say 5.18.105")

src = os.path.join(repo, "scripts/deploy-5.18.105.sh")
src_text = open(src, encoding="utf-8").read()
src_sha = hashlib.sha256(src_text.encode()).hexdigest()
if not src_sha.startswith(SRC_SHA256):
    raise SystemExit(f"the source is not scripts/deploy-5.18.105.sh as committed in 2a41c9d (sha256 {src_sha[:16]}…)")
if src_text != git(repo, "show", "HEAD:scripts/deploy-5.18.105.sh").decode():
    raise SystemExit("scripts/deploy-5.18.105.sh differs from the committed one")
doc = Doc(src_text)
ctx = {"repo": rel, "plugin": PLUGIN, "base_full": BASE_FULL, "pin_full": pin, "dev": repo, "src_sha": src_sha}
parts_log = []
for name in PARTS:
    mod = __import__(name)
    before = len(doc.log)
    mod.apply(doc, ctx)
    parts_log.append((name, len(doc.log) - before))
    print(f"  {name}: {len(doc.log) - before} edits ({len(doc.log)} so far)")

# the result, as a whole: no 5.18.105 identity left where a 5.18.106 one belongs
doc.must('EXPECTED_VERSION="5.18.106"\n', 1)
doc.must('BASELINE_VERSION="5.18.105"\n', 1)
doc.must(f'EXPECTED_PLUGIN_COMMIT_FULL="{pin}"', 1)
doc.must(f'BASELINE_COMMIT_FULL="{BASE_FULL}"\n', 1)
doc.must('BASELINE_COMMIT="7835906"', 1)
doc.mustnot('EXPECTED_PLUGIN_COMMIT="7835906"')
doc.mustnot('OUT="${DNB_OUT:-/root/dnb-5.18.105}"')
doc.mustnot("@@")

V1_ECHO = ('echo "  ROLLING BACK — the public address must never redirect (every switch of the sales follow-up kept;'
           ' followup_auto_send set off first, AS-RB1)"')


# ── keep_check: K1–K4 carried from 5.18.105 (the ladder kept), K3 re-anchored on V1's new line, K5 new (§L.1, §L.7) ──
def _code_lines(t):
    return [(n, l) for n, l in enumerate(t.split("\n"), 1) if not l.lstrip().startswith(("#", "//"))]


DISPLAY = re.compile(r'^\s*(?:echo|ok|bad|note|stop|hdr)\b')


def _is_display(l):
    """display text (§L.1): echo/ok/bad/note/stop/hdr lines, a printf whose output is not piped or redirected into a
    command, and the one named assignment OWNER_AUTOSEND_CMD."""
    if DISPLAY.match(l):
        return True
    s = l.lstrip()
    if s.startswith("printf ") and not re.search(r"\|\s*(?!\s*tee\b)|>\s*\(|\$\(\s*printf", l):
        return True
    return False


def keep_check(t):
    code = _code_lines(t)
    bad = []
    # K3 — V1's automatic rollback runs no QF-RB1 and no switch tool before its put-back; qf_rb1 is defined nowhere
    v1 = [l for _, l in code if "ROLLING BACK — the public address must never redirect" in l]
    if len(v1) != 1 or "do_rollback" not in v1[0]:
        bad.append(("K3", f"V1's automatic rollback line found {len(v1)} times"))
    elif re.search(r"\bqf_rb1\b|\bqf_tool\b|QF_WRAPPER|sales-followup\.sh", v1[0].split("do_rollback")[0]):
        bad.append(("K3", "V1's automatic rollback runs QF-RB1 (or the switch tool) before its put-back"))
    if any(re.search(r"\bqf_rb1\b", l) for _, l in code):
        bad.append(("K3", "qf_rb1 is in the code"))
    # K1 — no clear or by-hand put-back function; the switch tool only shown or checked; the wrapper only printed
    for n, l in code:
        if re.search(r"\b(qf_clear_and_baseline|qf_byhand)\b", l):
            bad.append(("K1", f"line {n}: a clear call ({l.strip()[:80]})"))
        for m in re.finditer(r'qf_tool "\$[A-Za-z0-9_]+(?:/ss)?" ([A-Za-z-]+)', l):
            if m.group(1) not in ("show", "--check", "doctor"):
                bad.append(("K1", f"line {n}: the switch tool runs {m.group(1)}"))
        if "$QF_WRAPPER" in l and not l.lstrip().startswith("echo "):
            bad.append(("K1", f"line {n}: the wrapper is run"))
    # K2 — LK-CA1 defined with its verdicts, called once, from qf_after
    m = re.search(r"(?ms)^lk_ca1\(\) \{\n(.*?)^\}", t)
    if not m or 'ok "LK-CA1' not in m.group(1) or 'bad "LK-CA1' not in m.group(1) or '"$(lk_map "$LK_B1")"' not in m.group(1):
        bad.append(("K2", "lk_ca1 is not defined with its comparison and both verdicts"))
    qa = re.search(r"(?ms)^qf_after\(\) \{\n(.*?)^\}", t)
    calls = [l for _, l in code if re.match(r"\s+lk_ca1\b", l)]
    if not qa or len(calls) != 1 or not re.search(r"(?m)^\s+lk_ca1\b", qa.group(1)):
        bad.append(("K2", f"LK-CA1 is not called once from qf_after ({len(calls)} call(s))"))
    # K4 — quote_followup_state's never-fell count over its rows NOT LIKE 'list_last:%'
    kept = [l for _, l in code if "'quote_followup_state_kept:'" in l]
    if len(kept) != 1 or "$cnt('quote_followup_state', \" WHERE k NOT LIKE 'list_last:%'\")" not in kept[0]:
        bad.append(("K4", "quote_followup_state's never-fell count is not taken over its rows NOT LIKE 'list_last:%'"))
    tl = re.search(r"foreach \(\['quote_tracker'.*?\] as \$t\)", t, re.S)
    if not tl or "'quote_followup_state'" in tl.group(0):
        bad.append(("K4", "quote_followup_state is counted whole among the tables that never fall"))
    bad += k5(t)
    return bad


# ── K5 (§L.1, §L.7): exactly one writer of a key in the code — as_rb1's saveOverrides, as -u "$DB_OWNER", with the
#    installed tree as its code root, called from the typed rollback and from V1 only, before do_rollback; two named
#    exceptions (SLR_PHP's throwaway ->save, Q-CA6's throwaway set_config run); OWNER_AUTOSEND_CMD assigned once ──
WRITER = re.compile(r"saveOverrides\s*\(|writeKycFile\s*\(|->save\(\s*[\"']kyc_config\.json[\"']"
                    r"|\bphp\s+[\"']?[^\s\"']*tools/set_config\.php"
                    r"|(?:qf_tool\s+\"\$[A-Za-z0-9_]+\"|sales-followup\.sh)\s+(?:on|off|skip|set|erase)\b")


def k5(t):
    bad = []
    lines = t.split("\n")
    hits = []
    for n, l in enumerate(lines, 1):
        s = l.lstrip()
        if s.startswith(("#", "//")) or _is_display(l):
            continue
        if re.match(r"\s*OWNER_AUTOSEND_CMD=", l):
            continue
        if "set_config.php" in l and re.search(r"\bgrep\b", l) and not re.search(r"\bphp\s+[\"']?[^\s\"']*tools/set_config\.php", l):
            continue
        for m in WRITER.finditer(l):
            hits.append((n, l, m.group(0)))
    slr = [h for h in hits if '->save("kyc_config.json", ["tenant_profile" => $profile])' in h[1] and "$__live" in h[1]]
    qca6 = [h for h in hits if "tools/set_config.php" in h[2] and "ucrm_quote_organization_id" in h[1]
            and "--value disabled" in h[1] and 'DN_DATA_DIR="$d"' in h[1] and 'DN_VAULT_FILE="$d/vault.json"' in h[1]]
    rest = [h for h in hits if h not in slr and h not in qca6]
    if len(slr) != 1:
        bad.append(("K5", f"SLR_PHP's throwaway save found {len(slr)} times"))
    if len(qca6) != 1:
        bad.append(("K5", f"Q-CA6's throwaway set_config run found {len(qca6)} times"))
    else:
        # its data folder must be the run's /tmp one: $d made under /tmp just above
        qn = qca6[0][0]
        ctxl = "\n".join(lines[max(0, qn - 8):qn])
        if not re.search(r'd="\$\(docker exec[^\n]*mktemp -d /tmp/', ctxl) and "/tmp/" not in ctxl:
            bad.append(("K5", "Q-CA6's data folder is not shown to be under /tmp"))
    if len(rest) != 1:
        bad.append(("K5", f"{len(rest)} writer(s) besides the two exceptions, not exactly one: "
                          + "; ".join(f"line {n}: {w}" for n, _, w in rest[:4])))
    else:
        n, l, w = rest[0]
        if not w.startswith("saveOverrides"):
            bad.append(("K5", f"the one writer is not saveOverrides (line {n}: {w})"))
        if "'followup_auto_send' => '0'" not in l:
            bad.append(("K5", f"the one writer does not write followup_auto_send '0' alone (line {n})"))
        if re.search(r"saveOverrides\s*\([^)]*,\s*\[[^\]]*'[a-z_]+'\s*=>[^\]]*,", l):
            bad.append(("K5", f"the one writer writes more than one key (line {n})"))
        fm = re.search(r"(?ms)^as_rb1\(\) \{\n(.*?)^\}", t)
        if not fm or w not in fm.group(1) and l not in fm.group(1):
            bad.append(("K5", "the one writer is not inside as_rb1"))
        elif 'docker exec -u "$DB_OWNER"' not in fm.group(1) or '"$IN_CONTAINER"' not in fm.group(1):
            bad.append(("K5", "as_rb1 does not run as -u \"$DB_OWNER\" with the installed tree as its code root"))
    calls = [(n, l) for n, l in _code_lines(t) if re.search(r"(?<![\w-])as_rb1\b(?!\(\))", l)]
    sites = sorted({("v1" if "ROLLING BACK — the public address must never redirect" in l else "typed" if "as_rb1 typed" in l else "other")
                    for _, l in calls})
    if sites != ["typed", "v1"] or len(calls) != 2:
        bad.append(("K5", f"as_rb1 is called from {sites} ({len(calls)} call(s)), not from the typed rollback and V1 alone"))
    for n, l in calls:
        if "ROLLING BACK" in l and (l.find("as_rb1") > l.find("do_rollback") or "do_rollback" not in l):
            bad.append(("K5", f"line {n}: V1's as_rb1 is not before its do_rollback"))
    typed = [n for n, l in calls if "as_rb1 typed" in l]
    dr = [n for n, l in _code_lines(t) if re.search(r"^\s*if do_rollback\b|^\s*do_rollback\b", l) and "ROLLING BACK" not in l]
    if typed and dr and not any(d > typed[0] for d in dr):
        bad.append(("K5", "the typed rollback's as_rb1 is not before its do_rollback"))
    asg = [l for _, l in _code_lines(t) if re.match(r"\s*OWNER_AUTOSEND_CMD=", l)]
    if len(asg) != 1:
        bad.append(("K5", f"OWNER_AUTOSEND_CMD assigned {len(asg)} times"))
    elif not re.search(r"--key followup_auto_send --value \$\{?[A-Za-z_]+\}?|--key followup_auto_send --value [01]", asg[0]) \
            or "AI SETTINGS" not in asg[0]:
        bad.append(("K5", "OWNER_AUTOSEND_CMD does not hold the one key and the cut at the settings listing"))
    for n, l in _code_lines(t):
        if "OWNER_AUTOSEND_CMD" in l and not re.match(r"\s*OWNER_AUTOSEND_CMD=", l):
            if re.search(r"(^|[;&|]\s*)(eval|sh -c|bash -c)\b[^\n]*OWNER_AUTOSEND_CMD|^\s*\"?\$\{?OWNER_AUTOSEND_CMD", l):
                bad.append(("K5", f"line {n}: OWNER_AUTOSEND_CMD is run, not printed"))
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
_kweak(V1_ECHO + "; as_rb1 v1; if do_rollback", V1_ECHO + "; as_rb1 v1; qf_rb1; if do_rollback", "K3", "QF-RB1 called again in V1")
_kweak("$cnt('quote_followup_state', \" WHERE k NOT LIKE 'list_last:%'\")", "$cnt('quote_followup_state')", "K4",
       "quote_followup_state counted over every row")
# K5's eight weakened copies (§L.1), each refused by K5 alone
_w = re.search(r"saveOverrides\(\$dd, \['followup_auto_send' => '0'\]\)", doc.t)
if not _w or doc.t.count(_w.group(0)) != 1:
    raise SystemExit("KEEP CHECK CONTROL (K5): as_rb1's saveOverrides line is not found exactly once")
_save = _w.group(0)
_kweak("  as_rb1 typed\n", "  as_rb1 typed\n  as_rb1 typed\n", "K5", "a second call site")
_kweak(_save, "saveOverrides($dd, ['followup_auto_send' => '0', 'followup_enabled' => '0'])", "K5", "a second key")
_asr = re.search(r"(?ms)^as_rb1\(\) \{\n(.*?)^\}", doc.t).group(1)
if _asr.count('docker exec -u "$DB_OWNER"') != 1:
    raise SystemExit("KEEP CHECK CONTROL (K5): as_rb1 does not hold exactly one docker exec -u \"$DB_OWNER\"")
_kweak('docker exec -u "$DB_OWNER" -e AS_ROOT="$IN_CONTAINER"', 'docker exec -e AS_ROOT="$IN_CONTAINER"', "K5", "the write without -u")
_kweak('docker exec -u "$DB_OWNER" -e AS_ROOT="$IN_CONTAINER"', 'docker exec -u "$DB_OWNER" -e AS_ROOT="/tmp/elsewhere"', "K5",
       "the code loaded from elsewhere")
_kweak(V1_ECHO + "; as_rb1 v1; if do_rollback", V1_ECHO + "; if do_rollback; then as_rb1 v1; fi; if do_rollback", "K5",
       "the write after do_rollback")
_kweak("  as_rb1 typed\n", "  as_rb1 typed\n  printf '%s' \"<?php PluginConfig::saveOverrides(\\$d, ['followup_auto_send' => '0']);\" | docker exec -i \"$CONTAINER\" php\n",
       "K5", "a saveOverrides in a printf piped into docker exec php")
_kweak("  as_rb1 typed\n", "  as_rb1 typed\n  dxo php -r '$s = SqliteStore::create(\"'\"$PDD_IN\"'\"); $s->save(\"kyc_config.json\", []);'\n", "K5",
       "a ->save on the live data folder")
_q6 = [l for l in doc.t.split("\n") if "ucrm_quote_organization_id" in l and "--value disabled" in l and 'DN_DATA_DIR="$d"' in l]
if len(_q6) != 1:
    raise SystemExit(f"KEEP CHECK CONTROL (K5): Q-CA6's run found {len(_q6)} times")
_kweak(_q6[0], _q6[0].replace('DN_DATA_DIR="$d"', 'DN_DATA_DIR="$PDD_IN"'), "K5", "Q-CA6 pointed at $PDD_IN")
print("  keep check: K1–K5; twelve weakened copies (K1, K2, K3, K4 and K5's eight), each refused for its own reason alone")

# ── the two wrappers stay as committed (§L.2, DL7): scripts/sales-followup-report.sh is pinned to 7835906 and its seven
#    inputs are unchanged at the pin; scripts/sales-followup.sh is 5.18.104's ──
rw = open(os.path.join(repo, "scripts/sales-followup-report.sh"), encoding="utf-8").read()
if rw != git(repo, "show", "HEAD:scripts/sales-followup-report.sh").decode():
    raise SystemExit("scripts/sales-followup-report.sh is not the committed one — §L.2: it stays pinned to 7835906")
if f'RELEASE_COMMIT_FULL="{BASE_FULL}"' not in rw:
    raise SystemExit("scripts/sales-followup-report.sh is not pinned to 7835906")
for f in ["tools/sales_followup_report.php", "lib/QuoteSendEvidence.php", "lib/PhoneNumber.php", "lib/TenantProfile.php",
          "lib/PluginConfig.php", "lib/ConfigVault.php", "lib/ContactOptOut.php"]:
    if sha_at(rel, pin, f"{PLUGIN}/{f}") != sha_at(rel, BASE_FULL, f"{PLUGIN}/{f}"):
        raise SystemExit(f"REPORT WRAPPER: {f} differs between 7835906 and the pin — the pinned wrapper would no longer hold")
print("  report wrapper: as committed, pinned to 7835906; its seven inputs unchanged at the pin")

# ── the run's folder (DS3-fix1, carried): made in the main shell before any subshell reads it — weakened copies ──
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
    raise SystemExit(f"FOLDER CHECK CONTROL: rb_pre106's cih_main line found {len(_rb0)} times")
_fweak(_rb0[0] + "\n", "", "(B)", "rb_pre106")
_fweak('  [ -n "$CIH_BASE" ] || return 1   # never cih_init here: vn_book_copy runs in $( … ) (cih_main)\n', "  cih_init || return 1\n", "(A)", "vn_book_copy")
_qa = 'qf_after() {\n  local inst="$1" sub o n v bad j\n'
_qa_main = "  cih_main || true   # the run's folder, made in this shell before the readings on copies below, which run in $( … ) (DS3-fix1)\n"
_fweak(_qa + _qa_main, _qa, "(B)", "qf_after")
print("  folder check control: three weakened copies, each refused for its own reason")


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
    raise SystemExit("scripts/sales-followup.sh is not the committed one — it stays as it is")
bash_n(fw, "scripts/sales-followup.sh")

tmp = out + ".tmp"
open(tmp, "w", encoding="utf-8").write(doc.t)
os.chmod(tmp, 0o755)
os.replace(tmp, out)
print(f"wrote {out}: {doc.t.count(chr(10))} lines; added {ctx['n_added']}, changed {ctx['n_changed']}, kept {ctx['n_kept']}; "
      f"source sha256 {src_sha[:16]}…, result sha256 {hashlib.sha256(doc.t.encode()).hexdigest()}")
print("parts: " + ", ".join(f"{n} {e}" for n, e in parts_log) + f"; total {len(doc.log)}")
