#!/usr/bin/env bash
#
# Rehearse scripts/deploy-5.18.105.sh — on Uganda the phone numbers taken out of the plugin's log lines (root docs/74):
# 35 log lines masked in 21 runtime files, deployed over a LIVE sales follow-up ladder that 5.18.105 never writes — every
# ladder key, switch, record key and setting alike, kept exactly as found in its four places (LK), the masked lines read as
# blocks before and after the copy (LM), the rollback keeping every switch (RB105). The script under rehearsal is derived
# from 5.18.104's by asserted edits (wip/derive-5.18.105/), pinned to the release commit; every pin is read from it here and
# every expectation computed from Git or from the release's own code — never a commit, a count of files or a hash written.
#
# The sandbox is the previous release's rehearsal's, built by its own code: scripts/harness/deploy-5.18.104/rehearse.sh,
# read up to just before 104-10 (its sweeps) — the cut asserted (root docs/74 §H.6.2) — and run here as it is, which builds
# every earlier sandbox the same way. When this file's own sections begin, 5.18.104's 7c has ended with a rollback and the
# quarantine's --check: 5.18.103 serving, 5.18.104's added files on disk, no allKeys() key set, 089–091 in the ledger.
# 105-pre makes production's state: 5.18.104 deployed again through the real deploy-5.18.104.sh and the ladder walked
# through the real tool (L1: steps 1–5; L2: 1–6, a chase sent and a STOP; L3 and L4 for the deploy rule).
#
# The development runner: the block that sources the chain — from the one line that begins H105_PREFIX="$(mktemp)" to the
# one line that is exactly rm -f "$H105_PREFIX" — can be skipped by a runner that restores the chain's shell from a snapshot
# (devsnap105); everything after it works in such a shell and copies the committed script and both wrappers into the
# restored clone itself. REHEARSE_SHORTCUT105=1 (development only) replaces pre-d and pre-e by 5.18.104's own snapshots s2
# and mainend; the record run walks.
#
# What it proves is root docs/74 §H.6, section by section: the guards (§H.6.1); the cut (§H.6.2); 105-pre (§H.6.3); 105-0,
# 105-c, 105-1, 105-4 (gap 4 inside), 105-5 (the drives LN-01…LN-04, §H.6.6), 105-6, 105-6v, 105-7 (§H.6.4); 105-10's sweeps
# (§H.6.4, §H.6.5) — the plugin's logs after the copy chosen by their own stamps, never by byte offset.
#
# Everything runs in a sandbox; it never touches this checkout, the server or the network, and refuses to run where the
# server could be. Everything seeded is fictitious: every name and text an RH104SECRET- value, every phone a number of the
# rehearsal's own range (+2567001040NN), listed in the 0600 pattern files for the sweeps.
#
#   bash scripts/harness/deploy-5.18.105/rehearse.sh            REHEARSE_KEEP=<dir> keeps every run's full output
set -u
# ── §H.6.1 the guards ─────────────────────────────────────────────────────────────────────────────────────────────────
for f in /data/ucrm /opt/dishnet /var/run/docker.sock /run/docker.sock; do
  [ -e "$f" ] && { echo "refusing: $f exists — this looks like the server, and this rehearsal must never run there"; exit 2; }
done
H105_DIR="$(cd "$(dirname "$0")" && pwd)"
H105_ROOT="$(cd "$H105_DIR/../../.." && pwd)"
case "$H105_DIR/:$(pwd -P)/" in */data/ucrm/*|*/opt/dishnet/*|*/root/dnb-*) echo "refusing: a server path ($H105_DIR, $(pwd -P))"; exit 2 ;; esac
F105=scripts/deploy-5.18.105.sh; F104_105=scripts/deploy-5.18.104.sh
[ -f "$H105_ROOT/$F105" ] || { echo "refusing: the checkout has no $F105"; exit 2; }
sv105e() { sed -nE "s/^$2=\"([^\"]*)\".*/\1/p" "$1" 2>/dev/null | head -1; }   # sv105e FILE NAME — a quoted value the script states at a line's start
P105_EARLY="$(sv105e "$H105_ROOT/$F105" EXPECTED_PLUGIN_COMMIT)"; P105F_EARLY="$(sv105e "$H105_ROOT/$F105" EXPECTED_PLUGIN_COMMIT_FULL)"
T1_EARLY105="$(sv105e "$H105_ROOT/$F105" T1_SIGNATURE)"
case "$P105_EARLY" in __*|'') echo "refusing: $F105 is not pinned to a release commit (${P105_EARLY:-none})"; exit 2;; esac
case "$P105F_EARLY" in "$P105_EARLY"*) [ "${#P105F_EARLY}" = "40" ] || { echo "refusing: $F105 does not pin its release commit in full"; exit 2; } ;;
  *) echo "refusing: $F105's full pin does not begin with its short one"; exit 2 ;; esac
case "$T1_EARLY105" in [1-9]*' passed, 0 failed rc=0') ;; *) echo "refusing: $F105 does not pin T1's count (T1_SIGNATURE=${T1_EARLY105:-none})"; exit 2;; esac
for f105 in rh105q.py rh105-ref.php; do [ -f "$H105_DIR/$f105" ] || { echo "refusing: this rehearsal's helper $f105 is not beside it"; exit 2; }; done
python3 -I -c 'import ast, sys; ast.parse(open(sys.argv[1]).read())' "$H105_DIR/rh105q.py" 2>/dev/null || { echo "refusing: rh105q.py does not parse"; exit 2; }
[ ! -e "$H105_DIR/__pycache__" ] || { echo "refusing: a __pycache__ beside the helpers — remove it"; exit 2; }
# The static guard: the lk mode's one line, and no switch-tool verb invoked outside printed text — read by this
# rehearsal's own reader; its control a planted copy of each kind (a qf_tool verb, the wrapper, the tool by path), each
# of which must be refused.
VB105_EARLY="$(python3 -I "$H105_DIR/rh105q.py" verbs "$H105_ROOT/$F105")"
[ "$VB105_EARLY" = "lk=1 qftool=0 wrapper=0 verbs=0" ] || { echo "refusing: $F105's static guard reads $VB105_EARLY (want lk=1 qftool=0 wrapper=0 verbs=0)"; exit 2; }
VBD105="$(mktemp -d)"
VBC105="$(python3 -I - "$H105_ROOT/$F105" "$VBD105" "$H105_DIR/rh105q.py" <<'PY'
import subprocess, sys
src, d, q = sys.argv[1:4]
s = open(src, encoding='utf-8').read()
tail = '\nmain "$@"; exit $?\n'
assert s.count(tail) == 1 and s.endswith(tail), 'tail'
plants = {'qftool': '  qf_tool "$sub" off all --rollback >/dev/null 2>&1\n',
          'wrapper': '  bash "$REPO/$QF_WRAPPER" off all --rollback\n',
          'verbs': '  docker exec ucrm php "$IN_CONTAINER/$QF_TOOL" off all --rollback\n'}
out = []
for k, line in plants.items():
    t = s[:-len(tail)] + '\n' + line + tail.lstrip('\n')
    assert t.count(line) == 1 and t.endswith(tail) and len(t) == len(s) + len(line), k
    f = '%s/%s.sh' % (d, k)
    open(f, 'w', encoding='utf-8').write(t)
    r = subprocess.run([sys.executable, '-I', q, 'verbs', f], capture_output=True, text=True).stdout.strip()
    out.append('%s:%s' % (k, 'refused' if r != 'lk=1 qftool=0 wrapper=0 verbs=0' else 'passed'))
print(','.join(out))
PY
)"; rm -rf "${VBD105:?}"
[ "$VBC105" = "qftool:refused,wrapper:refused,verbs:refused" ] || { echo "refusing: the static guard's control did not hold ($VBC105)"; exit 2; }
# 5.18.104's script present and pinned to the commit 5.18.105 names as its baseline (each read, never written)
[ -f "$H105_ROOT/$F104_105" ] || { echo "refusing: the checkout has no $F104_105"; exit 2; }
B105_EARLY="$(sv105e "$H105_ROOT/$F105" BASELINE_COMMIT)"; B105F_EARLY="$(sv105e "$H105_ROOT/$F105" BASELINE_COMMIT_FULL)"
[ -n "$B105_EARLY" ] && [ "$(sv105e "$H105_ROOT/$F104_105" EXPECTED_PLUGIN_COMMIT)" = "$B105_EARLY" ] && [ "$(sv105e "$H105_ROOT/$F104_105" EXPECTED_PLUGIN_COMMIT_FULL)" = "$B105F_EARLY" ] \
  || { echo "refusing: $F104_105 is not pinned to the baseline $F105 names (${B105_EARLY:-none})"; exit 2; }
# Both wrappers the committed ones: tracked, and the working copy HEAD's.
for f105 in scripts/sales-followup.sh scripts/sales-followup-report.sh; do
  git -C "$H105_ROOT" ls-files --error-unmatch "$f105" >/dev/null 2>&1 || { echo "refusing: $f105 is not committed — commit it, then rehearse"; exit 2; }
  git -C "$H105_ROOT" diff --quiet HEAD -- "$f105" || { echo "refusing: $f105 differs from the committed one — commit it, then rehearse"; exit 2; }
done

# ── §H.6.2 the cut: 5.18.104's rehearsal, up to just before 104-10 ────────────────────────────────────────────────────────
H104_HARNESS105="$H105_DIR/../deploy-5.18.104/rehearse.sh"
[ -f "$H104_HARNESS105" ] || { echo "refusing: 5.18.104's rehearsal is not beside this one"; exit 2; }
H105_PREFIX="$(mktemp)"
python3 -I - "$H104_HARNESS105" "$H105_PREFIX" <<'PY' || { echo "refusing: the previous release's rehearsal could not be cut before its last section (its sweeps)"; rm -f "$H105_PREFIX"; exit 2; }
import sys
src, dst = sys.argv[1], sys.argv[2]
anchor = 'echo; echo "== 104-10. what the rehearsal left behind, and what it never reached =="'
lines = open(src, encoding='utf-8').read().split('\n')
at = [i for i, l in enumerate(lines) if l == anchor]
assert len(at) == 1, len(at)
assert at[0] > 900, at[0]
# the last section heading before the anchor is 104-7c's; its last non-empty line is exactly `fi   # LIVE104`, once
heads = [i for i in range(at[0]) if lines[i].startswith('echo; echo "== 104-')]
assert heads and lines[heads[-1]].startswith('echo; echo "== 104-7c. the controls of every part'), (lines[heads[-1]][:60] if heads else 'none')
body = lines[heads[-1]:at[0]]
nonempty = [l for l in body if l.strip()]
assert nonempty[-1] == 'fi   # LIVE104', nonempty[-1][:60]
assert sum(1 for l in lines[:at[0]] if l == 'fi   # LIVE104') == 1
# the last check line inside the block is 7c16's, and it occurs once in the cut
ck = [i for i, l in enumerate(body) if l.lstrip().startswith('check ')]
assert ck, 'no check'
win = '\n'.join(body[ck[-1]:ck[-1] + 4])
assert win.count('  "7c16 ') == 1 and '\n'.join(lines[:at[0]]).count('  "7c16 ') == 1, win[:120]
pre = '\n'.join(lines[:at[0]]) + '\n'
# The one edit: 5.18.104's rehearsal finds its helpers beside itself ($0's folder); sourced from here, $0 is this file,
# so its one line naming that folder points at its own folder instead — asserted: the anchor once (in its guards, the
# line after it H104_ROOT's), replaced once, the new line once and the old line nowhere.
old_dir = 'H104_DIR="$(cd "$(dirname "$0")" && pwd)"\n'
new_dir = 'H104_DIR="$(cd "$(dirname "$0")/../deploy-5.18.104" && pwd)"\n'
assert pre.count(old_dir) == 1, pre.count(old_dir)
i_old = pre.index(old_dir)
assert pre[i_old + len(old_dir):].startswith('H104_ROOT='), 'H104_ROOT does not follow'
assert pre[:i_old].count('\n') > 60, 'not in the guards'
pre2 = pre.replace(old_dir, new_dir, 1)
assert pre2.count(new_dir) == 1 and pre2.count(old_dir) == 0 and len(pre2) == len(pre) + len(new_dir) - len(old_dir)
for need in ('H103_HARNESS=', 'run104() {', 'run_line104() {', 'fresh104() {', 'installed104() {', 'left104() {', 'nogo104() {',
             'checkgo104() {', 'weak104() {', 'live104() {', 'sf104() {', 'tool104() {', 'clk_set104() {', 'clk_to104() {',
             'dsnap104() {', 'drest104() {', 'kv104() {', 'q104() {', 'aft104() {', 'onall104() {', 'expect104() {', 'master104() {',
             'idle104() {', 'rbx104() {', 'REL104=', 'OUT104="$SB/out104"; mkdir -p "$OUT104"; STATE104=', 'R104=', 'H104='):
    assert ('\n' + pre2).count('\n' + need) == 1, need   # each the start of exactly one line
open(dst, 'w', encoding='utf-8').write(pre2)
PY
# shellcheck disable=SC1090
. "$H105_PREFIX"
rm -f "$H105_PREFIX"
for f105 in run104 run_line104 fresh104 installed104 left104 nogo104 checkgo104 weak104 live104 sf104 tool104 clk_set104 clk_to104 \
            dsnap104 drest104 kv104 q104 aft104 onall104 expect104 master104 idle104 rbx104 \
            check has okline noteline failline fails printed live inst_ver data94 kept_state evo_calls vt_host qj n103 epoch99 \
            set_sched base_log sqx free_port qrun spent99 v1read102 pubget sf104c sfrc104 run104c qrun104c fnow104 \
            kat104 kday104 kdow104 kday_next104 pinroot104 treesum104 pause104 actor104 actwait104 golet104 wfile104 tl_arm104 tl_disarm104 \
            tlu104 sched104 ledger104 owner104 cron104_up cron104_down onst104 wason104 sv104 LOGW104; do
  declare -F "$f105" >/dev/null || { echo "refusing: the rehearsals this one runs did not define $f105()"; exit 2; }
done
for v105 in SB REPO R P PD DATA VAULT RECS PLUGIN_BASE WEB_OK AFTER94 SCAN96 U99 H99 PHPBIN IN_CONTAINER DATE_TS98 \
            H103_HARNESS REL104 DEPLOY104 OUT104 STATE104 R104 H104 P103 P104 P104F V104 V103 NA104 ALLCSV104 NAMES104 \
            SALES104 REPORT104 CLKPRE104 CLKF104 ACT104 PINT104 PDD104_IN CM104 RC104 ONP104 LIVE104 OPTOUT104; do
  [ -n "${!v105:-}" ] || { echo "refusing: the rehearsals this one runs did not set $v105"; exit 2; }
done
[ "$LIVE104" = yes ] || { echo "refusing: 5.18.104's rehearsal ran without its live cases (LIVE104=$LIVE104) — 5.18.105's states need them"; exit 2; }
FAIL_PRE105=$FAILN   # the earlier rehearsals' failures, before this release's own sections: the last line names them

# ══════════════════════════════════════════════════════════════════════════════════════
# 5.18.105's own rehearsal, on the sandbox built above.
# ══════════════════════════════════════════════════════════════════════════════════════
DEPLOY105="$REPO/$F105"
AM0_105="$(grep -c . "$SB/anchor-missing" 2>/dev/null)"; AM0_105="${AM0_105:-0}"; WF0_105="$(grep -c . "$R104/wait104.fail" 2>/dev/null)"; WF0_105="${WF0_105:-0}"
ST0_105="$(git -C "$R" status --porcelain 2>/dev/null | sha256sum | cut -c1-16)"   # this checkout as the rehearsal found it
# The script, and both wrappers, into the clone as committed (a development runner's restored clone included).
if git -C "$R" ls-files --error-unmatch "$F105" >/dev/null 2>&1; then
  git -C "$R" diff --quiet HEAD -- "$F105" || { echo "refusing: $F105 differs from the committed one — commit it, then rehearse"; exit 2; }
  cp "$R/$F105" "$DEPLOY105"; WHICH105="the committed script"
else cp "$R/$F105" "$DEPLOY105"; WHICH105="the working copy (not yet committed; untracked in the clone)"; fi
for f105 in scripts/sales-followup.sh scripts/sales-followup-report.sh; do cp "$R/$f105" "$REPO/$f105"; done
cp "$R/$F104_105" "$REPO/$F104_105"
sv105() { sed -nE "s/^$2=\"([^\"]*)\".*/\1/p; t; s/^$2=([^ \";]+).*/\1/p" "${1:-$DEPLOY105}" | head -1; }   # sv105 FILE NAME — a value the script states
P105="$(sv105 "" EXPECTED_PLUGIN_COMMIT)"; P105F="$(sv105 "" EXPECTED_PLUGIN_COMMIT_FULL)"; V105="$(sv105 "" EXPECTED_VERSION)"
B105="$(sv105 "" BASELINE_COMMIT)"; B105F="$(sv105 "" BASELINE_COMMIT_FULL)"; BV105="$(sv105 "" BASELINE_VERSION)"
REL105="$(sv105 "" RELEASE_BRANCH)"
git -C "$REPO" fetch -q origin "refs/heads/$REL105:refs/remotes/origin/$REL105" 2>/dev/null || true
if ! git -C "$REPO" cat-file -e "$P105F^{commit}" 2>/dev/null; then
  # the release branch not yet cut: the checkout's own branch that holds the pin (read from Git, never named here)
  for b105 in $(git -C "$R" for-each-ref --contains "$P105F" --format='%(refname:short)' refs/heads 2>/dev/null | head -3); do
    git -C "$REPO" fetch -q origin "refs/heads/$b105:refs/remotes/origin/$b105" 2>/dev/null && break
  done
fi
git -C "$REPO" cat-file -e "$P105F^{commit}" 2>/dev/null || { echo "refusing: the clone does not hold $V105's pin $P105F"; exit 2; }
check "$P105|$( [ "$(git -C "$REPO" rev-parse "$P105^{commit}")" = "$P105F" ] && echo full)|$B105|$( [ "$B105F" = "$P104F" ] && echo base104)|$BV105|$(git -C "$REPO" merge-base --is-ancestor "$B105F" "$P105F" && echo on104)" \
      "${P105F:0:${#P105}}|full|${P104F:0:${#B105}}|base104|$V104|on104" \
  "105 setup: $WHICH105 pinned to $P105 (its full form the clone's), cut on $B105 — 5.18.104's own pin, read from both scripts — $V105 after $BV105"
OUT105="$SB/out105"; mkdir -p "$OUT105"; STATE105="$OUT105/state-$V105.env"; LKF105="$OUT105/lk-key-$V105"
R105="$SB/r105"; mkdir -p "$R105/logs" "$R105/mutants" "$R105/sheets" "$R105/exec" "$R105/scan" "$R105/vault" "$R105/tok" "$R105/ucrm" "$R105/evo" "$R105/case" "$R105/drive" "$R105/pre"
H105="$SB/rh105"; mkdir -p "$H105/v" "$H105/ref"; cp "$H105_DIR/rh105q.py" "$H105_DIR/rh105-ref.php" "$H105/"; chmod 755 "$H105"; chmod 644 "$H105"/rh105*
chown 1000:1000 "$H105/v"; chmod 700 "$H105/v"
LOGW105() { printf '2>&1 | ( umask 077; exec tee %s/%s-%s.log )' "$OUT105" "$1" "$DATE_TS98"; }   # the script's LOGW, as it prints it
EXP_AFTER105="cd $REPO && bash $F105 --after-only $(LOGW105 after)"
EXP_ROLLBACK105="cd $REPO && bash $F105 --rollback $(LOGW105 rollback)"
EXP_REDEPLOY105="cd $REPO && bash $F105 --redeploy $(LOGW105 redeploy)"
q105() { python3 -I "$H105/rh105q.py" "$@"; }
kv105() { q105 kv "$1" "$2"; }
# The pin's ladder, read by the RELEASE's own code from Git (a copy in the rehearsal's own folder): allKeys(), settingKeys(),
# each step's key and rule, what `skip voice-dry-run` records — what rh105q.py reads the four places with.
git -C "$REPO" archive "$P105F" "$P/lib" "$P/tools" | tar -xf - -C "$H105/ref"; chown -R 1000:1000 "$H105/ref"
ref105() {   # ref105 MODE ROOT [DATA SPEC] — rh105-ref.php as the store's user, a vault copy of its own, under the clock
  local v="$H105/v/ref-vault.json" o
  rm -f "$v"; [ ! -f "$VAULT" ] || { cp "$VAULT" "$v"; chown 1000:1000 "$v"; chmod 600 "$v"; }
  [ -f "$v" ] || { printf '{}\n' > "$v"; chown 1000:1000 "$v"; chmod 600 "$v"; }
  o="$(cd "$H105/v" && setpriv --reuid 1000 --regid 1000 --clear-groups -- env DN_VAULT_FILE="$v" LD_PRELOAD="$CLKPRE104" RH104_CLOCK_FILE="$CLKF104" \
        http_proxy= https_proxy= HTTP_PROXY= HTTPS_PROXY= NO_PROXY='*' "$PHPBIN" -d log_errors=0 "$H105/rh105-ref.php" "$@" 2>"$H105/v/ref.err")"
  rm -f "$v"
  case "$o" in '{'*) printf '%s' "$o" ;; *) echo "none($(head -c 160 "$H105/v/ref.err"))" ;; esac
}
LAD105="$(ref105 ladder "$H105/ref/$P")"
jf105() { python3 -I -c 'import json, sys; v = json.loads(sys.argv[1])[sys.argv[2]]; print(",".join(v) if isinstance(v, list) else v)' "$1" "$2" 2>/dev/null; }
ALLCSV105="$(jf105 "$LAD105" all)"; SETCSV105="$(jf105 "$LAD105" settings)"; STEPMAP105="$(jf105 "$LAD105" stepmap)"; VDR105="$(jf105 "$LAD105" vdr)"
check "$( [ -n "$ALLCSV105" ] && [ -n "$SETCSV105" ] && echo read)|$(tr ',' '\n' <<<"$STEPMAP105" | head -1 | cut -d: -f1,3)|$(tr ',' '\n' <<<"$VDR105" | head -1)|$( [ "$ALLCSV105" = "$ALLCSV104" ] && echo same104)" \
      "read|report:r|voice-dry-run|same104" \
  "105 setup: the pin's ladder read by its own code from Git — allKeys() ($(tr ',' '\n' <<<"$ALLCSV105" | grep -c .) keys, 5.18.104's own list unchanged), settingKeys() ($(tr ',' '\n' <<<"$SETCSV105" | grep -c .)), the steps in order, report its record key; skip voice-dry-run records $(tr ',' ' ' <<<"$VDR105")"
lk105() {   # lk105 [MAPOUT] — this rehearsal's own reading of the ladder's keys in the four places (rh105q.py lk), read-only
  sqx "PRAGMA wal_checkpoint(TRUNCATE)" >/dev/null 2>&1
  q105 lk "$DATA/plugin.sqlite3" "$DATA/kyc_config.json" "$PD/data/config.json" "$DATA/config.json" "$ALLCSV105" "$SETCSV105" "$STEPMAP105" ${1:+"$1"}
}
lkmap105() { local f="$R105/logs/lkmap-$1"; lk105 "$f" >/dev/null; echo "$f"; }   # lkmap105 NAME — a 0600 map file of the reading, its path
counts105() { sqx "PRAGMA wal_checkpoint(TRUNCATE)" >/dev/null 2>&1; q105 counts "$DATA/plugin.sqlite3"; }
digest105() { kv105 "$(lk105)" digest; }
dsnap105() { dsnap104 "105-$1"; }                    # the data folder and the vault, as 5.18.104's own snapshot keeps them
drest105() { drest104 "105-$1"; }                    # …put back; prints same|same|same
# ── The runs ─────────────────────────────────────────────────────────────────────────────────────────────────────────
# run105 [--answer WORD] [--hold FILE] [--script FILE] [--env K=V]… OPTION… — as run104, this release's folders; with --hold
# the answer is typed only once FILE exists (the question waits meanwhile, bounded 300 s); R105_STREAM names a file the
# terminal is copied to as it is written; Evolution's calls during the run counted in evo/run-<n>.n
run105() {
  local answer="" script="$DEPLOY105" extra=() hold="" n tag o cmd e0 c0
  while :; do case "${1:-}" in --answer) answer="$2"; shift 2 ;; --hold) hold="$2"; shift 2 ;; --script) script="$2"; shift 2 ;; --env) extra+=("$2"); shift 2 ;; *) break ;; esac; done
  : > "$SB/docker.log"; : > "$U99/requests.log"; : > "$U99/limits.log"
  n=$(( $(cat "$SB/runs" 2>/dev/null || echo 0) + 1 )); echo "$n" > "$SB/runs"; tag="$(printf '%03d' "$n")"
  vt_host > "$R105/vault/run-$tag.before"; : > "$R105/exec/run-$tag.rec"; : > "$R105/scan/run-$tag.hits"; : > "$R105/tok/run-$tag.tok"; e0="$(evo_calls)"; c0="$(grep -c . "$R104/logs/cron104.runs" 2>/dev/null)"
  cmd="$(printf '%q ' env PATH="$SB/datebin:$SB/bin:$PATH" FAKE_TODAY="$AFTER94" DNB_OUT="$OUT105" DNB_RECORDS="$RECS" DNB_WEBSITE="$WEB_OK" GUARD_SECONDS=3 DN_DATA_DIR="$DATA" \
         DNB_SALES_LOG_DIR="$SALES104" DNB_REPORT_DIR="$REPORT104" \
         FAKE_EXEC_LOG="$R105/exec/run-$tag.rec" FAKE_TMP_SCAN="$SCAN96" FAKE_TMP_HITS="$R105/scan/run-$tag.hits" FAKE_TOK_HITS="$R105/tok/run-$tag.tok" \
         ${extra[@]+"${extra[@]}"} bash "$script" --plugin-base "$PLUGIN_BASE" "$@")"
  if [ -n "$hold" ]; then
    o="$( { i=0; while [ ! -e "$hold" ] && [ "$i" -lt 6000 ]; do sleep 0.05; i=$((i+1)); done; printf '%s\n' "$answer"; } \
          | SHELL=/bin/bash script -qec "$cmd" /dev/null 2>&1 | tee "${R105_STREAM:-/dev/null}" | tr -d '\r')"
  else o="$(printf '%s\n' "$answer" | SHELL=/bin/bash script -qec "$cmd" /dev/null 2>&1 | tee "${R105_STREAM:-/dev/null}" | tr -d '\r')"; fi
  echo "$(( $(evo_calls) - e0 )) $( [ "$(n103 "$(grep -c . "$R104/logs/cron104.runs" 2>/dev/null)")" -gt "$(n103 "$c0")" ] && echo cron || echo nocron)" > "$R105/evo/run-$tag.n"   # cron: the stand-in ran its worker during this run
  vt_host > "$R105/vault/run-$tag.after"; cp "$U99/requests.log" "$R105/ucrm/run-$tag.req"; cp "$U99/limits.log" "$R105/ucrm/run-$tag.lim"
  case "$o" in *"unknown option:"*) echo "run105-$n" >> "$SB/bad-runs" ;; esac
  if [ -n "${R105_MUTANT_OUT:-}" ]; then printf '%s\n' "$o" > "$R105/mutants/run-$tag.txt"; else printf '%s\n' "$o" > "$R105/run-$tag.txt"; fi
  [ -z "${REHEARSE_KEEP:-}" ] || printf '%s\n' "$o" > "$REHEARSE_KEEP/run105-$tag-${answer:-none}-$(basename "$script" .sh)$(printf '%s' "$*" | tr -c 'A-Za-z0-9-' '_').txt"
  printf '%s' "$o"
}
run105c() { run105 --env "LD_PRELOAD=$CLKPRE104" --env "RH104_CLOCK_FILE=$CLKF104" "$@"; }   # under the rehearsal's clock
run_line105c() {   # run_line105c WORD LINE — a command line exactly as a summary printed it, under the clock, WORD typed
  local n tag o cmd e0 c0
  : > "$SB/docker.log"; : > "$U99/requests.log"; : > "$U99/limits.log"
  n=$(( $(cat "$SB/runs" 2>/dev/null || echo 0) + 1 )); echo "$n" > "$SB/runs"; tag="$(printf '%03d' "$n")"
  vt_host > "$R105/vault/run-$tag.before"; : > "$R105/exec/run-$tag.rec"; : > "$R105/scan/run-$tag.hits"; : > "$R105/tok/run-$tag.tok"; e0="$(evo_calls)"; c0="$(grep -c . "$R104/logs/cron104.runs" 2>/dev/null)"
  cmd="$(printf '%q ' env PATH="$SB/datebin:$SB/bin:$PATH" FAKE_TODAY="$AFTER94" DNB_OUT="$OUT105" DNB_RECORDS="$RECS" DNB_WEBSITE="$WEB_OK" GUARD_SECONDS=3 DN_DATA_DIR="$DATA" PLUGIN_BASE="$PLUGIN_BASE" \
         DNB_SALES_LOG_DIR="$SALES104" DNB_REPORT_DIR="$REPORT104" LD_PRELOAD="$CLKPRE104" RH104_CLOCK_FILE="$CLKF104" \
         FAKE_EXEC_LOG="$R105/exec/run-$tag.rec" FAKE_TMP_SCAN="$SCAN96" FAKE_TMP_HITS="$R105/scan/run-$tag.hits" FAKE_TOK_HITS="$R105/tok/run-$tag.tok" bash -c "$2")"
  o="$(printf '%s\n' "$1" | SHELL=/bin/bash script -qec "$cmd" /dev/null 2>&1 | tr -d '\r')"
  echo "$(( $(evo_calls) - e0 )) $( [ "$(n103 "$(grep -c . "$R104/logs/cron104.runs" 2>/dev/null)")" -gt "$(n103 "$c0")" ] && echo cron || echo nocron)" > "$R105/evo/run-$tag.n"   # cron: the stand-in ran its worker during this run
  vt_host > "$R105/vault/run-$tag.after"; cp "$U99/requests.log" "$R105/ucrm/run-$tag.req"; cp "$U99/limits.log" "$R105/ucrm/run-$tag.lim"
  printf '%s\n' "$o" > "$R105/run-$tag.txt"
  printf '%s' "$o"
}
last105() { printf '%03d' "$(cat "$SB/runs")"; }
fresh105() {   # the run folder as a new run finds it: the state files, the key files and the row digests kept; every log and sheet moved aside
  find "$OUT105" -mindepth 1 -maxdepth 1 -name '*.log' -exec mv -t "$R105/logs/" {} + 2>/dev/null
  find "$OUT105" -mindepth 1 -maxdepth 1 -name 'cash-*.txt' -exec mv -t "$R105/sheets/" {} + 2>/dev/null
  find "$OUT105" -mindepth 1 -maxdepth 1 ! -name "state-$V105.env*" ! -name 'rollback-state-*' ! -name 'ql-key-*' ! -name 'cih-rows-*' ! -name "lk-key-$V105" -exec rm -rf {} +; base_log; }
tidy105() {   # tidy105 CASE — what a run that typed DEPLOY and stopped before the copy left (backup, state files, the LK key file)
              # moved into this rehearsal's own folder for the case, so the next deploy is a first deploy again
  local d="$R105/case/$1"; mkdir -p "$d"
  find "$OUT105" -mindepth 1 -maxdepth 1 \( -name 'backup-*' -o -name "state-$V105.env*" -o -name 'rollback-state-*' -o -name "lk-key-$V105" -o -name 'cih-rows-*' \) -exec mv -t "$d/" {} + 2>/dev/null; :
}
backups105() { ls -d "$OUT105"/backup-* 2>/dev/null | wc -l | tr -d ' '; }
states105() { ls -1 "$OUT105/state-$V105.env"* "$OUT105"/rollback-state-* 2>/dev/null | wc -l | tr -d ' '; }
keyf105() { [ -e "$LKF105" ] && echo key || echo nokey; }
went105() { echo "$(has "$1" 'GO — evidence recorded')$(has "$1" 'Type DEPLOY')"; }
quiet105() { echo "$(live)|$(backups105)|$(states105)|$(keyf105)|$(data94)"; }   # nothing deployed, backed up, written or changed
nogo105() {   # nogo105 LABEL EXPECTED-TEXT OUTPUT DATA-DIGEST LIVE — refused with that text and "Nothing was changed", never the
              # question, nothing deployed, backed up, written or changed: LIVE serving, the book's digest DATA-DIGEST (taken before the run)
  check "$(has "$3" "$2")$(has "$3" 'Nothing was changed. Send the log file.')$(went105 "$3")|$(quiet105)" "yesyesnono|$5|0|0|nokey|$4" "$1"
}
checkgo105() { echo "$(has "$1" "  $V105 (check): GO — stage A holds.")$(went105 "$1")$(fails "$1")|$(backups105)|$(states105)|$(keyf105)"; }   # GO: yesnono0|0|0|nokey
installed105() {   # installed105 COMMIT — the files $BV105 → $V105 touches that are NOT installed as COMMIT has them (one COMMIT lacks: not asked)
  local f rel bad=0
  for f in $(git -C "$REPO" diff --name-only --diff-filter=AM "$B105F" "$P105F" -- "$P" | grep -v "^$P/tests/"); do
    rel="${f#$P/}"
    if git -C "$REPO" cat-file -e "$1:$f" 2>/dev/null; then
      [ "$(git -C "$REPO" show "$1:$f" | sha256sum | cut -c1-64)" = "$(sha256sum "$PD/$rel" 2>/dev/null | cut -c1-64)" ] || bad=$((bad+1))
    fi
  done
  echo "$bad"
}
ADDED105="$(sed -n 's/^EXPECTED_ADDED="\(.*\)"$/\1/p' "$DEPLOY105")"; NA105=$(wc -w <<<"$ADDED105")
left105() { local f n=0; for f in $ADDED105; do [ -e "$PD/$f" ] && n=$((n+1)); done; echo "$n"; }
CM105="$(sv105 "" COPY_MARK)"
QFW105="$(sed -nE 's/.*\bQF_WRAPPER="([^"]*)".*/\1/p' "$DEPLOY105" | head -1)"   # the wrapper the script names (third on its line)
copy_back105() { local cm="$PD/$CM105" t; touch -d "-$1 minutes" "$cm"; t="$(stat -c %Y "$cm")"
  sed -i -e "s/^COPY_AT=.*/COPY_AT=$t/" -e "s/^DEPLOYED_AT=.*/DEPLOYED_AT=$(date -u -d "@$(( t - 60 ))" +%Y-%m-%dT%H:%M:%SZ)/" "$STATE105"; }
after_back105() { copy_back105 30; local c; c="$(sed -n 's/^COPY_AT=//p' "$STATE105" | tail -1)"; [ "$(epoch99 "$c")" = "epoch" ] && set_sched $(( c + 300 )); base_log; }
# The --after-only lines of each step, read from the script as 5.18.104's rehearsal reads its own.
ONP105="QF-ON0:ack QF-ON3:ledger $(grep -oE 'qf_on_one "\$(sub|1)" [A-Z]+-ON[0-9] [a-z-]+ ' "$DEPLOY105" | awk '{print $3 ":" $4}' | grep -v '^QF-ON0:' | sort -u | paste -sd' ' -) $(for p in $(sv105 "" VN_STEPS); do printf '%s:%s ' "${p##*:}" "${p%%:*}"; done)$(grep -q 'note "AC-ON1 OFF — not judged (agent-cc is off)"' "$DEPLOY105" && echo AC-ON1:agent-cc)"
onall105() { local p; for p in $ONP105; do printf '%s=%s ' "$p" "$(onst104 "$1" "${p%%:*}" "${p#*:}")"; done | sed 's/ $//'; }
expect105() {   # expect105 STATE STEP… [-- STATE STEP…] — every pair of the named steps STATE (the ledger's pair is its own state:
                # ledger=STATE), the rest off; STATE as rh105q.py stcmp reads it (judged, notyet, any, a|b alternatives)
  local p st s out="" e
  for p in $ONP105; do
    e=off; st=""
    for s in "$@"; do case "$s" in *=*) [ "${p#*:}" = "${s%%=*}" ] && e="${s#*=}" ;; esac; done
    out="$out $p=$e"
  done
  echo "${out# }"
}
on105() { local s r=""; for s in "$@"; do r="$r $s=judged"; done; echo "${r# }"; }   # on105 STEP… — each step judged (a PASS or a NOT_YET)
L1ON105="$(on105 ack voice-measure tracking list) ledger=pass|empty"
L2ON105="$L1ON105 $(on105 chase)"
aftx104() {   # aftx104 LABEL STEP=STATE… — 5.18.104's --after-only under the clock (its own script), every pair as named, the rest off
  local lab="$1" e0 o; shift; idle104; e0="$(evo_calls)"
  fresh104; o="$(run104c --after-only)"
  local e; e="$(for p in $ONP104; do st=off; for s in "$@"; do [ "${p#*:}" = "${s%%=*}" ] && st="${s#*=}"; done; printf '%s=%s ' "$p" "$st"; done | sed 's/ $//')"
  check "$(fails "$o")$(has "$o" "  $V104 (after): PASSED.")|$(q105 stcmp "$(onall104 "$o")" "$e")|$(( $(evo_calls) - e0 ))" "0yes|same|0" \
    "$lab $V104's --after-only under the clock ($(kday104 "$(fnow104)")): PASSED — each pair as the step stands ($*), the rest OFF — not judged; Evolution not called"
}
aft105() {   # aft105 LABEL STEP=STATE… — 5.18.105's --after-only under the clock, the copy half an hour back: PASSED, each pair as named
  local lab="$1" e0; shift; idle104; e0="$(evo_calls)"
  after_back105; fresh105; AFT105="$(run105c --after-only)"; AFTT105="$(last105)"
  check "$(fails "$AFT105")$(has "$AFT105" "  $V105 (after): PASSED.")|$(q105 stcmp "$(onall105 "$AFT105")" "$(expect105 "$@")")|$(( $(evo_calls) - e0 ))" "0yes|same|0" \
    "$lab $V105's --after-only under the clock ($(kday104 "$(fnow104)")): PASSED — each pair as the step stands, the rest OFF — not judged; Evolution not called"
}
rbx105() {   # rbx105 LABEL — back to 5.18.104 after a deploy of 5.18.105: ROLLBACK typed, under the clock, the cron stand-in on;
             # PASSED, $B105 serving, every file 5.18.105 changes $BV105's, the keys as before it; then the quarantine's --check
  local o k0
  k0="$(digest105)"; cron104_up; fresh105; o="$(run105c --answer ROLLBACK --rollback)"; cron104_down; RBX105_OUT="$o"
  check "$(fails "$o")$(has "$o" "  $V105 (rollback): PASSED.")|$(live)|$(installed105 "$B105F")|$( [ "$(digest105)" = "$k0" ] && echo kept)|$(wason104 "$o")" \
        "0yes|$B105|0|kept|0" "$1 the rollback, ROLLBACK typed: PASSED — $B105 serving, every file $V105 changes $BV105's again, every ladder key as before it (this rehearsal's reading), no tool line 'was on:'"
  o="$(qrun104c --check)"
  check "$(has "$o" 'stray-store quarantine (check): PASSED.')" "yes" "$1' …the quarantine's own --check after it: PASSED"
}
# ── The weakened copies: each anchor the script's own text (or 5.18.104's, for a block kept as it was), read here into a
# file of its own, its replacement beside it; each copy asserts its anchor once, its replacement once, and the result. ──
AN105="$SB/anchors105"; rm -rf "$AN105"; mkdir -p "$AN105"
anc105() { printf '%s' "$2" > "$AN105/$1.a"; printf '%s' "$3" > "$AN105/$1.b"; }   # anc105 KEY ANCHOR REPLACEMENT
blk105() {   # blk105 FILE START-LINE — the block from the one line exactly START-LINE through the first line `  fi` after it
  python3 -I - "$1" "$2" <<'PY'
import sys
s = open(sys.argv[1], encoding='utf-8').read().split('\n'); a = sys.argv[2]
i = [k for k, l in enumerate(s) if l == a]
assert len(i) == 1, len(i)
j = next(k for k in range(i[0] + 1, len(s)) if s[k] == '  fi')
sys.stdout.write('\n'.join(s[i[0]:j + 1]) + '\n')
PY
}
weak105() {   # weak105 NAME KEY… — a copy of the script in the clone's scripts/ with each KEY's anchor replaced, asserted
  local name="$1" k dst="$REPO/scripts/.$1.sh"; shift
  cp "$DEPLOY105" "$dst"
  for k in "$@"; do
    python3 -I - "$dst" "$AN105/$k.a" "$AN105/$k.b" <<'PY' || echo "weak105:$k" >> "$SB/anchor-missing"
import sys
f, fa, fb = sys.argv[1:4]
s = open(f, encoding='utf-8').read(); a = open(fa, encoding='utf-8').read(); b = open(fb, encoding='utf-8').read()
assert a and a != b and s.count(a) == 1, (a[:70], s.count(a))
s2 = s.replace(a, b, 1)
assert s2.count(b) == 1 + s.count(b) and len(s2) == len(s) - len(a) + len(b) and (a in b or s2.count(a) == 0)
open(f, 'w', encoding='utf-8').write(s2)
PY
  done
  bash -n "$dst" 2>/dev/null || echo "weak105:$name:syntax" >> "$SB/anchor-missing"
  echo "$dst"
}
OWN105="$(owner104)"
B104_104="$(sv105 "$DEPLOY104" BASELINE_COMMIT)"; B104F_104="$(sv105 "$DEPLOY104" BASELINE_COMMIT_FULL)"
anc105 base103 "BASELINE_COMMIT=\"$B105\"" "BASELINE_COMMIT=\"$B104_104\""
anc105 base103f "BASELINE_COMMIT_FULL=\"$B105F\"" "BASELINE_COMMIT_FULL=\"$B104F_104\""
anc105 held '  [ "$(qf_kv "$o" held)" = "0" ] || stop "NO-GO (LK-G1): ' '  true || stop "NO-GO (LK-G1): '
anc105 heldset "            if (isset(\$h['p']) || isset(\$h['d'])) { if (\$isAll) \$held[] = \$k; else \$heldS++; }" "            if (isset(\$h['p']) || isset(\$h['d'])) { \$held[] = \$k; }"
anc105 part '    *) cih_drop "$sub"; stop "NO-GO (QF-G1): the book does not carry 089, 090 and 091 as $BASELINE_VERSION left them' \
            '    *) QF_G1=live; : "NO-GO (QF-G1): the book does not carry 089, 090 and 091 as $BASELINE_VERSION left them'
anc105 lkb1 '  if [ "$(lk_map "$o")" != "$(lk_map "$LK_G1")" ]; then' '  if false; then'
CSL105="$(grep -m1 -E '^    --clear-switches\) echo ' "$DEPLOY105")"
anc105 clearsw "$CSL105" '    --clear-switches) : ;;'
anc105 lkrows "        \$r[] = 'quote_followup_state_kept:' . \$cnt('quote_followup_state', \" WHERE k NOT LIKE 'list_last:%'\");" \
              "        \$r[] = 'quote_followup_state_kept:' . \$cnt('quote_followup_state');"
MB104_105="$(sv105 "$DEPLOY104" MASTER_BASE_SHA256)"; UG104_105="$(sv105 "$DEPLOY104" UG_SIGNATURE)"
anc105 mbase "MASTER_BASE_SHA256=\"$(sv105 "" MASTER_BASE_SHA256)\"" "MASTER_BASE_SHA256=\"$MB104_105\""
anc105 ugsig "UG_SIGNATURE=\"$(sv105 "" UG_SIGNATURE)\"" "UG_SIGNATURE=\"$UG104_105\""
anc105 vng4 "$(blk105 "$DEPLOY105" "  # 5.18.105 (root docs/74 §H.4.4): the pin's whole fromMe branch is \$BASELINE_VERSION's byte for byte, W1 in both")" \
            "$(blk105 "$DEPLOY104" '  b="$(vn_base_text evo_webhook.php | vn_fromme)"; p="$(vn_pin evo_webhook.php | vn_fromme)"')"
LKB1L105="$(grep -m1 -E '^  LK_B1="\$o"; LK_AT=' "$DEPLOY105")"
CLR105="  docker exec -u $OWN105 -e \"DN_DATA_DIR=\$PDD_IN\" ucrm php -d log_errors=0 \"\$IN_CONTAINER/\$QF_TOOL\" off all --rollback >/dev/null 2>&1   # rh105: the clear re-inserted"
anc105 clear "$LKB1L105" "$LKB1L105
$CLR105"
anc105 noca1 "$(grep -m1 -E '^  lk_ca1 ' "$DEPLOY105")" '  :   # rh105: LK-CA1 removed'
anc105 qfca5 "$(python3 -I - "$DEPLOY105" <<'PY'
import sys
s = open(sys.argv[1], encoding='utf-8').read().split('\n')
a = [k for k, l in enumerate(s) if l.startswith('  # 5.18.105 (root docs/74 §H.4.4): the deploy run requires it equal, line for line')]
assert len(a) == 1
b = next(k for k in range(a[0], len(s)) if s[k].startswith('  else note "QF-CA5 the installed tool') and s[k].endswith('fi'))
sys.stdout.write('\n'.join(s[a[0]:b + 1]) + '\n')
PY
)" "$(python3 -I - "$DEPLOY104" <<'PY'
import sys
s = open(sys.argv[1], encoding='utf-8').read().split('\n')
a = [k for k, l in enumerate(s) if l.startswith('  v="$(printf ') and 'grep -cE' in l and k > 0 and s[k - 1].startswith('  o="$(qf_tool "$sub" show)"')]
assert len(a) == 1
b = next(k for k in range(a[0], len(s)) if s[k].startswith('  else note "QF-CA5 the installed tool') and s[k].endswith('fi'))
sys.stdout.write('\n'.join(s[a[0]:b + 1]) + '\n')
PY
)"
V1L105="$(grep -m1 -oE 'echo "  ROLLING BACK — the public address must never redirect \(every switch kept\)"; if do_rollback; then' "$DEPLOY105")"
anc105 v1clr "$V1L105" "echo \"  ROLLING BACK — the public address must never redirect (every switch kept)\"; docker exec -u $OWN105 -e \"DN_DATA_DIR=\$PDD_IN\" ucrm php -d log_errors=0 \"\$IN_CONTAINER/\$QF_TOOL\" off all --rollback >/dev/null 2>&1; if do_rollback; then"
KW105="$(grep -m1 -oE "printf 'LK_KEY=%s\\\\n' \"\\\$LK_KEY\" > \"\\\$LK_KEY_FILE\"" "$DEPLOY105")"
anc105 nokeep "$KW105" "printf 'LK_KEY=%s\\n' \"\$(printf '%s-rh105' \"\$LK_KEY\" | sha256sum | cut -c1-48)\" > \"\$LK_KEY_FILE\""
anc105 lmold '  g="$(lm_count inst)"; d="$(lm_diff "$g" "$(lm_expect pin)")"' '  g="$(lm_count inst)"; d="$(lm_diff "$g" "$(lm_expect base)")"'
# The control of the controls: each anchor the script's own text (5.18.104's for the blocks kept as they were), found in
# it exactly once, each replacement different — an anchor that matched nothing would plant nothing.
ANCC105="$(python3 -I - "$DEPLOY105" "$AN105" <<'PY'
import os, sys
s = open(sys.argv[1], encoding='utf-8').read(); d = sys.argv[2]
keys = sorted(f[:-2] for f in os.listdir(d) if f.endswith('.a'))
bad = [k for k in keys if (lambda a, b: not a or a == b or s.count(a) != 1)(open(os.path.join(d, k + '.a')).read(), open(os.path.join(d, k + '.b')).read())]
print('%d|%d|%s' % (len(bad), len(keys), ','.join(bad) or '-'))
PY
)"
check "$ANCC105" "0|$(ls "$AN105"/*.a | wc -l | tr -d ' ')|-" \
  "105 setup control: each of the $(ls "$AN105"/*.a | wc -l | tr -d ' ') anchors of the weakened copies is the script's own text, found in it exactly once, and each replacement differs (the VN-G4 and QF-CA5 blocks 5.18.104's own, read from its script)"
# ── The stand-in website: .redirect105 — the public sign-in page redirecting once 5.18.105's manifest is installed (V1,
# 105-6v); one asserted insertion before the same line 5.18.104's went before, its probe answered. ──
python3 -I - "$SB/web/public.php" "$V105" <<'PY' || echo "standin:triggers105" >> "$SB/anchor-missing"
import sys
p, v105 = sys.argv[1:3]
s = open(p).read()
old = "$page = $_GET['page'] ?? '';\n"
assert s.count(old) == 1 and s.count('redirect105') == 0 and s.count("'.redirect104'") == 1, (s.count(old), s.count('redirect105'))
new = ("if (($_GET['page'] ?? '') === 'rh105_probe') { echo 'rh105-triggers-ready'; exit; }\n"
       "if (($_GET['page'] ?? '') === 'customer_login' && !isset($_GET['rh105v1']) && strpos((string)@file_get_contents($root . '/manifest.json'), '\"" + v105 + "\"') !== false && $spend('.redirect105')) { header('Location: ?page=customer_login&rh105v1=1', true, 302); exit; }   // 5.18.105's rehearsal: V1 (105-6v)\n")
s2 = s.replace(old, new + old, 1)
assert s2.count("'.redirect105'") == 1 and s2.count('rh105_probe') == 1 and s2.count(old) == 1 and len(s2) == len(s) + len(new)
open(p, 'w').write(s2)
PY
for i in $(seq 1 60); do grep -q 'rh105-triggers-ready' <<<"$(pubget page=rh105_probe)" && break; sleep 0.1; done
printf 'x\n' > "$SB/web/.redirect105"; chmod 644 "$SB/web/.redirect105"
TR105="$(v1read102)|$( [ -e "$SB/web/.redirect105" ] && echo unspent || echo spent)"; rm -f "$SB/web/.redirect105"
check "$(pubget page=rh105_probe)|$(inst_ver)|$TR105" "rh105-triggers-ready|$V103|200:0|unspent" \
  "105 setup control: the stand-in carries .redirect105 — armed while $V103's manifest is installed it does not fire (200:0, unspent); it fires only under $V105's (105-6v)"
# ── This rehearsal's own numbers (the range rh104-live.php accepts; 5.18.104's rehearsal leaves these free) ──
PQ105=+256700104015; PSTOP1_105=+256700104094; PLN03_105=+256700104095; PLEAD105=+256700104096; PLN04_105=+256700104097; PSTOP2_105=+256700104098
for n105 in "$PQ105" "$PSTOP1_105" "$PLN03_105" "$PLEAD105" "$PLN04_105" "$PSTOP2_105"; do grep -qF -- "${n105#+}" "$NAMES104" || printf '%s\n' "$n105" >> "$NAMES104"; done
printf '%s\n' "$PQ105" "$PSTOP1_105" "$PLN03_105" "$PLEAD105" "$PLN04_105" "$PSTOP2_105" > "$R105/phones105.txt"
sed -n 's/^\(+\{0,1\}2567001040[0-9][0-9]\)$/\1/p' "$NAMES104" >> "$R105/phones105.txt"
grep -oE '\+?2567001040[0-9]{2}' "$NAMES104" | sed 's/^\([0-9]\)/+\1/' >> "$R105/phones105.txt"
sort -u -o "$R105/phones105.txt" "$R105/phones105.txt"
FORMS105="$R105/forms105.pat"; PH4_105="$(q105 phones4 "$R105/phones105.txt" "$FORMS105")"
check "$(kv105 "$PH4_105" n | awk '{print ($1 >= 6) ? "six+" : $1}')|$(stat -c %a "$FORMS105")|$(grep -c '^256700104094$' "$FORMS105")" "six+|600|1" \
  "105 setup: every fixture number of the chain's own range and this rehearsal's six, in its four written forms and its last nine digits, into a 0600 pattern file ($(kv105 "$PH4_105" n) numbers, $(kv105 "$PH4_105" forms) lines)"
EVO0_105="$(evo_calls)"

echo; echo "== 105-0. the script read against Git: pins, delta, no migration, COPY_MARK, PREV_KEPT/PREV_CHANGED, LM_HUNKS, header, retired codes, report wrapper =="
GA105="$(git -C "$REPO" diff --name-only --diff-filter=A "$B105F" "$P105F" -- "$P" | sed "s#^$P/##" | LC_ALL=C sort | paste -sd' ' -)"
GC105="$(git -C "$REPO" diff --name-only --diff-filter=M "$B105F" "$P105F" -- "$P" | sed "s#^$P/##" | LC_ALL=C sort | paste -sd' ' -)"
GO105="$(git -C "$REPO" diff --name-only --diff-filter=DRTUXB "$B105F" "$P105F" -- "$P" | grep -c .)"
SA105="$(tr ' ' '\n' <<<"$ADDED105" | grep . | LC_ALL=C sort | paste -sd' ' -)"
SC105="$(sed -n 's/^EXPECTED_CHANGED="\(.*\)"$/\1/p' "$DEPLOY105" | tr ' ' '\n' | grep . | LC_ALL=C sort | paste -sd' ' -)"
check "$( [ "$SA105" = "$GA105" ] && echo added)|$( [ "$SC105" = "$GC105" ] && echo changed)|$GO105|$(git -C "$REPO" diff --name-only "$B105F" "$P105F" -- "$P/migrations" | grep -c .)|$(tr ' ' '\n' <<<"$GC105" | grep -cxF -- "$CM105")" \
      "added|changed|0|0|1" \
  "105-0a the delta as the script lists it is Git's: $(wc -w <<<"$GA105") added, $(wc -w <<<"$GC105") changed, none deleted or renamed; no migration; COPY_MARK ($CM105) a file the release changes"
PV105="$(python3 -I - "$DEPLOY105" "$DEPLOY104" "$REPO" "$B105F" "$P105F" "$P" <<'PY'
import re, subprocess, sys, hashlib
f105, f104, repo, base, pin, plug = sys.argv[1:7]
def block(path, name):
    s = open(path, encoding='utf-8').read()
    m = re.search(r'\n' + name + r'="\$\(cat <<\'SUMS\'\n(.*?)\nSUMS\n\)"', s, re.S)
    return [l.split(None, 1) for l in m.group(1).split('\n') if l.strip()] if m else None
def num(path, name):
    m = re.search(r'\n' + name + r'=([0-9]+)', open(path, encoding='utf-8').read()); return int(m.group(1)) if m else -1
def sha(c, p):
    r = subprocess.run(['git', '-C', repo, 'show', '%s:%s/%s' % (c, plug, p)], capture_output=True)
    return hashlib.sha256(r.stdout).hexdigest() if r.returncode == 0 else 'absent'
delta = set(subprocess.run(['git', '-C', repo, 'diff', '--name-only', base, pin, '--', plug], capture_output=True, text=True).stdout.split())
delta = {d[len(plug) + 1:] for d in delta}
k, c, k104 = block(f105, 'PREV_KEPT_SHA256S'), block(f105, 'PREV_CHANGED_SHA256S'), block(f104, 'PREV_KEPT_SHA256S')
if k is None or c is None or k104 is None:
    print('unread'); sys.exit()
kb = sum(1 for s, p in k if p in delta or sha(base, p) != s or sha(pin, p) != s)
cb = sum(1 for s, p in c if p not in delta or sha(base, p) != s)
moved = sorted({p for _, p in k104} & {p for _, p in c})
print('kept=%d/%d bad=%d changed=%d/%d bad=%d moved=%d' % (len(k), num(f105, 'PREV_KEPT_N'), kb, len(c), num(f105, 'PREV_CHANGED_N'), cb, len(moved)))
PY
)"
check "$(sed -E 's/kept=([0-9]+)\/\1 /kept=n\/n /; s/changed=([0-9]+)\/\1 /changed=n\/n /; s/ moved=[0-9]+$//' <<<"$PV105")" "kept=n/n bad=0 changed=n/n bad=0" \
  "105-0b PREV_KEPT and PREV_CHANGED are Git's: each kept file outside the delta at the baseline's and the pin's sha256, each changed one in the delta at the baseline's, each list as long as its count ($PV105 — the files that moved from 5.18.104's kept list to the changed one, counted from both scripts)"
LMS105="$(q105 lmscript "$DEPLOY105" | awk -F'|' 'BEGIN { OFS = "|" } $2 !~ /^LN-/ { $2 = "*" } { print }' | LC_ALL=C sort)"; LMG105="$(q105 gitlm "$REPO" "$B105F" "$P105F" "$P" | LC_ALL=C sort)"
LML105="$(q105 lmscript "$DEPLOY105" | cut -d'|' -f2 | grep -c '^LN-')"; LMLG105="$(cut -d'|' -f2 <<<"$LMG105" | grep -c '^LN-')"
check "$( [ -n "$LMS105" ] && [ "$LMS105" = "$LMG105" ] && echo equal)|$(grep -c . <<<"$LMS105")|$LML105|$LMLG105" "equal|$(sv105 "" LM_N)|$(sv105 "" LM_LN_N)|$(sv105 "" LM_LN_N)" \
  "105-0c LM_HUNKS are Git's diff, entry for entry (file, label, old and new blocks by line count and sha256 — each LN label the one its 5.18.105 marker names in Git; the script's other labels, tail, report, report#2 and loader, read as Git's '*'), $(sv105 "" LM_N) entries, $(sv105 "" LM_LN_N) of them LN lines in the script and in Git alike — computed here from Git with the 5.18.105 marker lines left out"
HD105="$(q105 hdr "$DEPLOY105" "$V105")"; RT105="$(q105 retired "$DEPLOY105")"
check "$( [ "$(kv105 "$HD105" cmds)" -ge 2 ] 2>/dev/null && echo some)|$( [ "$(kv105 "$HD105" alone)" = "$(kv105 "$HD105" cmds)" ] && echo alone)|$(kv105 "$HD105" back)|$(kv105 "$HD105" outside)|$(kv105 "$RT105" printed)|$(kv105 "$RT105" opt)" \
      "some|alone|0|0|0|0" \
  "105-0d the header's commands between its two marker lines ($HD105), each alone, none a rollback, a put-back, a redeploy or a clear, none outside the markers; the retired codes silent in printed text ($RT105)"
# the retired reader's control: a copy with a bare line under a retired code, and a parenthesis the next line closes
# without 'retired)' — each counted (the script's F names paragraph closes its one such parenthesis 'retired);')
RTC105="$(mktemp)"; cp "$DEPLOY105" "$RTC105"; printf '  note "QL-CA1 the catalogue"\n  echo "  QL-LK1 (still"\n  echo "  read)"\n' >> "$RTC105"
RTK105="$(q105 retired "$RTC105")"; rm -f "$RTC105"
check "$(kv105 "$RTK105" printed)|$(kv105 "$RTK105" codes)" "2|QL-CA1,QL-LK1" \
  "105-0d' the retired reader's control: a copy of the script with a bare note under QL-CA1, and QL-LK1 in a parenthesis the next line closes without 'retired)', each counted ($RTK105)"
RW105="$REPO/scripts/sales-followup-report.sh"
rw105() { sed -nE "s/.*\bRELEASE_COMMIT$1=\"([^\"]*)\".*/\1/p" "$RW105" | head -1; }   # rw105 [_FULL] — the wrapper states its pin as the 1st and 2nd assignments on one line
RWR105="$(sed -n 's/^REPORT="\([^"]*\)".*/\1/p' "$RW105" | head -1)"; RWR105="${RWR105:-tools/sales_followup_report.php}"
check "$(rw105 "")|$(rw105 _FULL)|$( [ "$(sv105 "$RW105" REPORT_SHA256)" = "$(git -C "$REPO" show "$P105F:$P/$RWR105" | sha256sum | cut -c1-64)" ] && echo report)|$( [ "$(sv105 "$RW105" QSE_SHA256)" = "$(git -C "$REPO" show "$P105F:$P/lib/QuoteSendEvidence.php" | sha256sum | cut -c1-64)" ] && echo qse)" \
      "$P105|$P105F|report|qse" \
  "105-0e the report wrapper pinned to $V105's pin, short and full; REPORT_SHA256 and QSE_SHA256 the pin's files' from Git"

echo; echo "== 105-pre. a live $BV105, as production has it: $BV105 deployed again, the ladder walked through the real tool =="
# pre-a: the cut's state, read by this rehearsal's own readers
LKA105="$(lk105)"; CNA105="$(counts105)"
check "$(live)|$(inst_ver)|$(kv105 "$LKA105" setnames)|$(kv105 "$LKA105" on)|$(kv105 "$LKA105" held)|$(kv105 "$LKA105" unread)|$(left104)|$( [ "$(kv105 "$CNA105" wa_offers)" -ge 0 ] 2>/dev/null && echo tables)" \
      "$P103|$V103|-|-|0|-|$NA104|tables" \
  "pre-a the state at the cut, by this rehearsal's own reading: $P103 ($V103) serving, no ladder key in any of the four places, nothing on, nothing held, everything readable; 5.18.104's $NA104 added files on disk; 089–091's tables there"
# 105-1a, here because 5.18.103 serves: 5.18.105's --check refuses — deploy 5.18.104 first; its weakened copy, BASELINE left
# at 5.18.104's own baseline, does not say so (caught).
D0_105="$(data94)"; fresh105; OUT="$(run105c --check)"
nogo105 "105-1a --check while $V103 serves: NO-GO — $V105 was built against $B105 ($BV105): deploy $BV105 first, nothing changed" "deploy $BV105 first (scripts/deploy-$BV105.sh)" "$OUT" "$D0_105" "$P103"
M105="$(weak105 base103 base103 base103f)"; fresh105; OUT="$(R105_MUTANT_OUT=1 run105c --script "$M105" --check)"; rm -f "$M105"
check "$(has "$OUT" "deploy $BV105 first")|$(live)|$(backups105)|$(keyf105)" "no|$P103|0|nokey" \
  "105-1a' …its weakened copy, BASELINE left at $B104_104: the live-commit refusal is gone (caught by 105-1a's text) — nothing deployed, backed up or written"
# pre-b: 5.18.104 deployed again through the real deploy-5.18.104.sh — the runbook's form for a book it has run on
fresh104; OUT="$(run104c --answer DEPLOY --redeploy)"; PREB105="$OUT"
check "$(fails "$OUT")$(has "$OUT" "  $V104 (deploy): PASSED.")|$(live)|$(inst_ver)|$(grep -cx 'QF_G1=redeploy' "$STATE104")|$(grep -cE '^  (ok|note)  +QF-G4 ' <<<"$OUT" | awk '{print ($1 > 0) ? "g4" : "none"}')|$(kv105 "$(lk105)" setnames)" \
      "0yes|$P104|$V104|1|g4|-" \
  "pre-b $V104 deployed again, DEPLOY typed, --redeploy (the runbook's form for a book $V104 has run on): PASSED — $P104 serving, QF-G1 the redeploy, QF-G4 read with none of allKeys() set (this rehearsal's reading: 0 keys)"
after_back104; aftx104 "pre-b'"
# pre-c: the quarantine's --check; 5.18.104's records where 5.18.105's PREV_STATE and A14 read them
OUT="$(qrun104c --check)"; ln -sfn "$OUT104" "$RECS/dnb-$BV105"
check "$(has "$OUT" 'stray-store quarantine (check): PASSED.')|$( [ -f "$RECS/dnb-$BV105/state-$BV105.env" ] && grep -c '^COPY_AT=' "$RECS/dnb-$BV105/state-$BV105.env" | awk '{print ($1 > 0) ? "copyat" : "none"}')" "yes|copyat" \
  "pre-c the quarantine's --check PASSED; $RECS/dnb-$BV105 is 5.18.104's run folder, its state file carrying COPY_AT for $V105's PREV_STATE and A14"
if [ -n "${REHEARSE_SHORTCUT105:-}" ]; then
  echo "  note  RH-NOTE DEVELOPMENT SHORTCUT: pre-d and pre-e restored from 5.18.104's own snapshots s2 and mainend, the clock past their last stamp — the record run walks"
  check "$(drest104 s2)" "same|same|same" "pre-d (shortcut) 5.18.104's snapshot s2 (steps 1–5 on) put back"
  clk_to104 $(( $(fnow104) + 3600 )) || true
  # s2 and mainend hold agent-cc on (104-5c'); the walk's L1 and L2 do not — switched off through the installed tool
  ACB105="$(kv105 "$(lk105)" on | tr ',' '\n' | grep -cx agent-cc)"; OUT="$(sf104c off agent-cc)"
  check "$ACB105|$(sfrc104)|$(grep -cx 'OFF agent-cc' <<<"$OUT")|$(kv105 "$(lk105)" on | tr ',' '\n' | grep -cx agent-cc)" "1|0|1|0" \
    "pre-d' (shortcut) off agent-cc through the installed tool, as the walk's L1 has it: on by this rehearsal's own reading before (s2's, 104-5c'), OFF, off after"
  dsnap105 L1
  check "$(drest104 mainend)" "same|same|same" "pre-e (shortcut) 5.18.104's snapshot mainend (steps 1–6, the chase answered) put back"
  clk_to104 $(( $(fnow104) + 3600 )) || true
  ACB105="$(kv105 "$(lk105)" on | tr ',' '\n' | grep -cx agent-cc)"; OUT="$(sf104c off agent-cc)"
  check "$ACB105|$(sfrc104)|$(grep -cx 'OFF agent-cc' <<<"$OUT")|$(kv105 "$(lk105)" on | tr ',' '\n' | grep -cx agent-cc)" "1|0|1|0" \
    "pre-e' (shortcut) off agent-cc through the installed tool, as the walk's L2 has it: on by this rehearsal's own reading before (mainend's), OFF, off after"
else
  # pre-d: the ladder walk, 5.18.104-5's order and conditions, each step through the installed tool under the clock
  RPO105="$(cd "$REPO" && env PATH="$SB/bin:$PATH" DNB_REPORT_DIR="$REPORT104" LD_PRELOAD="$CLKPRE104" RH104_CLOCK_FILE="$CLKF104" bash scripts/sales-followup-report.sh 2>&1 </dev/null)"; RPR105=$?
  RDAY105="$(kday104 "$(fnow104)")"
  OUT="$(sf104c on report --ran "$RDAY105")"; R1R105="$(sfrc104)"
  OUT2="$(sf104c --check report)"; R1C105="$(sfrc104)"
  check "$RPR105|$(grep -c 'READ-ONLY — nothing was changed' <<<"$RPO105")|$R1R105|$(has "$OUT" 'ON report: sf_report_ran_at')|$R1C105|$(grep -cE '^ *report: PASS$' <<<"$OUT2" | awk '{print ($1 > 0) ? "pass" : "no"}')" \
        "0|1|0|yes|0|pass" \
    "pre-d1 the report wrapper (read-only), then on report --ran $RDAY105 through the installed tool (sf_report_ran_at), then --check report: PASS"
  OUT="$(sf104c on ack)"; check "$(sfrc104)" "0" "pre-d2 on ack: ON (P5's keys and the ledger's)"
  aftx104 "pre-d2'" "ack=judged" "ledger=pass|empty"
  clk_to104 $(( $(fnow104) + 25 * 3600 ))
  OUT="$(sf104c --check ack)"; check "$(sfrc104)|$(grep -cx '  L-ON: PASS' <<<"$OUT")" "0|1" "pre-d3 the clock 25 h on: the ledger's L-ON PASS"
  OUT="$(sf104c on tracking)"; check "$(sfrc104)|$(grep -c '^ON tracking: quote_tracking_enabled, quote_tracking_since$' <<<"$OUT")" "0|1" "pre-d3' on tracking, its need met: ON"
  aftx104 "pre-d3''" "ack=judged" "ledger=pass|empty" "tracking=judged"
  clk_to104 $(( $(fnow104) + 73 * 3600 ))
  OUT="$(sf104c on list --accept-not-yet "no quotation yet in the rehearsal")"; check "$(sfrc104)|$(grep -c '^ON list: ' <<<"$OUT")" "0|1" "pre-d4 the clock 73 h on: on list --accept-not-yet — ON"
  OUT="$(sf104c on voice-measure)"; check "$(sfrc104)|$(grep -c '^ON voice-measure: ai_media_enabled, ai_media_since$' <<<"$OUT")" "0|1" "pre-d5 on voice-measure: ON"
  aftx104 "pre-d5'" "ack=judged" "ledger=pass|empty" "tracking=judged" "list=judged" "voice-measure=judged"
  dsnap105 L1
  # pre-e: the chase test
  OUT="$(sf104c set persona-confirmed "$(kday104 "$(fnow104)")")"; check "$(sfrc104)" "0" "pre-e1 set persona-confirmed to the clock's Kampala day: saved (a setting)"
  OUT="$(sf104c --tty yes on chase)"; check "$(sfrc104)|$(has "$OUT" 'Type yes to switch the quote chase on:')" "0|yes" "pre-e2 on chase, yes typed at the terminal, tracking on 72 h or more: ON"
  F3_105="$(fnow104)"; QD105=""
  for d in 0 1 2 3 4 5 6 7; do
    t="$(kat104 "$(kday104 $(( F3_105 + 3600 + d * 86400 ))) 08:20")"
    [ "$t" -gt $(( F3_105 + 600 )) ] && [ "$(kdow104 "$t")" != "7" ] && [ "$(kdow104 $(( t + 86400 )))" != "7" ] && { QD105="$(kday104 "$t")"; break; }
  done
  QD1_105="$(kday104 $(( $(kat104 "$QD105 12:00") + 86400 )))"; QREF105="RH104SECRET-Q105-1"
  clk_to104 "$(kat104 "$QD105 08:20")"
  Q105="$(live104 quote "$PD" "$DATA" "{\"phone\":\"$PQ105\",\"channel\":\"sales\",\"name\":\"RH104SECRET-Nankya\",\"ref\":\"$QREF105\",\"in_text\":\"RH104SECRET-the price for the dish please\",\"gap_min\":5}")"
  clk_to104 "$(kat104 "$QD105 08:25")"; MA105="$(master104 105A quote_chase followup_send)"
  check "$(python3 -I -c 'import json, sys; d = json.loads(sys.argv[1]); print("ids" if d["conv"] > 0 and d["in"] > 0 and d["quote"] > 0 else "missing")' "$Q105" 2>/dev/null || echo "unread")|$MA105|$(kv104 "$(python3 "$H104/rh104q.py" tracker "$DATA/plugin.sqlite3" "ref:$QREF105")" status)" \
        "ids|0|open" \
    "pre-e3 a quotation to a fixture customer through the installed ConversationService ($QD105 08:20, after tracking came on); cycle A at 08:25: quote_chase tracks it (open)"
  RMS105="$(kat104 "$QD1_105 09:02")"; RMD105="$(kat104 "$QD1_105 09:09")"
  clk_to104 "$(kat104 "$QD1_105 08:25")"; MB105="$(master104 105B quote_chase followup_send quote_daily_list)"
  clk_to104 "$(kat104 "$QD1_105 09:11")"; EVE0_105="$(grep -c '/message/sendText/' "$SB/evo/requests.log" 2>/dev/null)"
  ME105="$(master104 105E --set "{\"customer_reminders\":{\"last_run\":$RMS105,\"duration_ms\":$(( (RMD105 - RMS105) * 1000 ))}}" followup_send quote_daily_list)"
  LE105="$(ledger104)"
  check "$MB105|$ME105|$(kv104 "$LE105" quote | cut -d/ -f1 | awk '{print ($1 >= 1) ? "claimed" : $1}')|$(( $(grep -c '/message/sendText/' "$SB/evo/requests.log" 2>/dev/null) - $(n103 "$EVE0_105") ))" \
        "0|0|claimed|1" \
    "pre-e4 day 1 ($QD1_105): cycle B drafts the chase; cycle E (09:11), the day's reminder run recorded finished: the chase claimed in the ledger and sent — one sendText at the stand-in Evolution"
  # pre-e5: a STOP from the fixture's number through the real webhook under 5.18.104 — one old-form opt-out line
  WHPP105="$(free_port)"; WHUP105="http://127.0.0.1:$WHPP105/evo_webhook.php"
  setpriv --reuid 1000 --regid 1000 --clear-groups -- env LD_PRELOAD="$CLKPRE104" RH104_CLOCK_FILE="$CLKF104" "$PHPBIN" -d disable_functions=exec -S "127.0.0.1:$WHPP105" -t "$PD" >"$R105/pre/webhook-104.log" 2>&1 & WHPIDP105=$!; PIDS+=("$WHPIDP105")
  for i in $(seq 1 50); do [ "$(curl -s --noproxy '*' -o /dev/null -w '%{http_code}' "$WHUP105")" = "405" ] && break; sleep 0.1; done
  clk_to104 $(( $(fnow104) + 600 ))
  WS105="$(live104 webhook "$PD" "$DATA" "$WHUP105" "{\"channel\":\"sales\",\"phone\":\"$PQ105\",\"name\":\"RH104SECRET-Nankya\",\"kind\":\"text\",\"text\":\"STOP\",\"id\":\"RH104STOP105A\"}")"
  sleep 1; kill "$WHPIDP105" 2>/dev/null; wait "$WHPIDP105" 2>/dev/null
  check "$(qj "$WS105" http 2>/dev/null)|$(grep -cE "$OPTOUT104" "$R105/pre/webhook-104.log")|$(kv105 "$(q105 optout "$DATA/plugin.sqlite3" "$PQ105")" n)" "200|1|1" \
    "pre-e5 a STOP from the fixture's number through the real webhook under $V104: recorded (one active opt-out) and ONE old-form opt-out line, the number in it — the line $V105 masks"
fi
LK2_105="$(lk105)"
check "$(kv105 "$LK2_105" on | tr ',' '\n' | grep -cxE 'report|ack|tracking|list|voice-measure|chase')|$(kv105 "$LK2_105" held)|$(live)" "6|0|$P104" \
  "pre L2: steps 1–6 on by this rehearsal's own reading ($(kv105 "$LK2_105" on)), nothing held, $P104 serving — the most likely state when $V105 is deployed"
dsnap105 L2
# L3, for the deploy rule only: agent-cc (plugin mail set first, if the book has none), skip voice-dry-run, on offers-test
if [ ! -f "$DATA/email_settings.json" ]; then
  env PATH="$SB/bin:$PATH" LD_PRELOAD="$CLKPRE104" RH104_CLOCK_FILE="$CLKF104" bash "$SB/bin/docker" exec -u "$(owner104)" -e "DN_DATA_DIR=$PDD104_IN" ucrm php -d log_errors=0 "$IN_CONTAINER/tools/email_setup.php" \
    --user RH104SECRET-mail@example.test --host 127.0.0.1 --port 2525 --enc none --pass RH104SECRET-smtp-not-a-password --no-quote </dev/null >/dev/null 2>&1
fi
OUT="$(sf104c on agent-cc)"; R3A105="$(sfrc104)"
OUT="$(sf104c skip voice-dry-run --reason "the rehearsal: offers before the voice rungs")"; R3B105="$(sfrc104)"
SK3_105="$(kv105 "$(lk105)" skipped)"
OUT="$(sf104c on offers-test)"; R3C105="$(sfrc104)"
check "$R3A105|$R3B105|$(for s in $(tr ',' ' ' <<<"$VDR105"); do tr ',' '\n' <<<"$SK3_105" | grep -qx "$s" && printf 'k'; done)|$R3C105|$(kv105 "$(lk105)" on | tr ',' '\n' | grep -cx 'offers-test')" \
      "0|0|$(for s in $(tr ',' ' ' <<<"$VDR105"); do printf 'k'; done)|0|1" \
  "pre L3: on agent-cc; skip voice-dry-run recording $(tr ',' ' ' <<<"$VDR105") skipped by its cascade (without it on offers-test is refused, order:voice-dry-run); on offers-test after its checks — ON"
dsnap105 L3
OP4_105="$(live104 offerprep "$PD" "$DATA" '{"title":"RH104SECRET-offer of 105-pre","w1":"RH104SECRET-first wording of the 105 offer, well over twenty characters","w2":"RH104SECRET-second wording of the 105 offer, unlike the first one","channel":"sales"}')"
OUT="$(sf104c --check offers-test)"; R4A105="$(sfrc104)"; C4A105="$(grep -cx 'offers-test: PASS' <<<"$OUT")"
OUT="$(sf104c on offers-live)"; R4B105="$(sfrc104)"
check "$(qj "$OP4_105" tests 2>/dev/null)|$R4A105|$C4A105|$R4B105|$(kv105 "$(lk105)" on | tr ',' '\n' | grep -cx 'offers-live')" "sent,sent|0|1|0|1" \
  "pre L4: an offer drafted and both wordings tested through the installed OfferStore and OfferSender; --check offers-test PASS; on offers-live — ON, the ruling broken before $V105"
dsnap105 L4
check "$(drest105 L2)" "same|same|same" "pre …the book put back at L2 for 5.18.105's sections"
echo "  note  RH-NOTE production's $V104 was a first deploy (DEPLOY typed); this rehearsal's is a redeploy over the book 5.18.104's rehearsal ran on"

echo; echo "== 105-c. --check over L2 and over L1: GO, LK-G1 naming the steps on, LM-G1 and LM-G2 ok, nothing changed =="
lkg1on105() { sed -n "s/^  note  LK-G1 the ladder's keys as found, kept exactly so by this run .*; on: \([^;]*\); skipped: .*/\1/p" <<<"$1" | head -1 | tr ',' '\n' | grep -v '^-$' | LC_ALL=C sort | paste -sd, -; }
srt105() { tr ',' '\n' <<<"$1" | grep -v '^-$' | grep . | LC_ALL=C sort | paste -sd, -; }
for L105 in L2 L1; do
  check "$(drest105 "$L105")" "same|same|same" "105-c ($L105) the book at $L105"
  DG0_105="$(digest105)"; D0_105="$(data94)"; VT0_105="$(vt_host)"; ON0_105="$(srt105 "$(kv105 "$(lk105)" on)")"
  fresh105; OUT="$(run105c --check)"
  check "$(checkgo105 "$OUT")|$(digest105)|$(data94)|$(vt_host)" "yesnono0|0|0|nokey|$DG0_105|$D0_105|$VT0_105" \
    "105-c1 ($L105) --check: GO — stage A holds; nothing asked, no backup, no state file, no key file; the ladder's keys byte-identical by this rehearsal's reading, the book's digest the same, the vault as it was"
  check "$(lkg1on105 "$OUT")|$(okline "$OUT" LM-G1 "in the pin's files from Git each of the")$(okline "$OUT" LM-G2 "the pin's evo_webhook.php holds each of the two strings")|$(wason104 "$OUT")" \
        "$ON0_105|yesyes|0" \
    "105-c2 ($L105) LK-G1 names exactly the steps this rehearsal's reader finds on (${ON0_105:-none}); LM-G1 and LM-G2 ok; no tool line 'was on:'"
done
check "$(drest105 L2)" "same|same|same" "105-c …the book back at L2"

echo; echo "== 105-1. each NO-GO before anything changes, its weakened copy caught =="
jset105() {   # jset105 FILE KEY JSON-VALUE — one key written into a config.json as an operator's hand would (raw; no writer of the release does)
  python3 -I - "$1" "$2" "$3" <<'PY'
import json, os, sys
f, k, v = sys.argv[1:4]
d = {}
if os.path.exists(f):
    d = json.load(open(f, encoding='utf-8'))
d[k] = json.loads(v)
open(f, 'w', encoding='utf-8').write(json.dumps(d, indent=2))
PY
  chown 1000:1000 "$1" 2>/dev/null; :
}
SW105="$(tr ',' '\n' <<<"$STEPMAP105" | awk -F: '$1 == "chase" {print $2}')"; ST105="$(tr ',' '\n' <<<"$SETCSV105" | grep -vxF -f <(tr ',' '\n' <<<"$ALLCSV105") | head -1)"
# 1a: an allKeys() key held by <data>/config.json — LK-G1 refuses; the weakened copy goes on
jset105 "$DATA/config.json" "$SW105" '"1"'; D1_105="$(data94)"
fresh105; OUT="$(run105c --check)"
nogo105 "105-1b LK-G1 refuses an allKeys() key ($SW105) held by <data>/config.json — the switch tool never writes it, so a later off would not take; nothing changed" \
        "NO-GO (LK-G1): 1 of the ladder's switch and record keys (SalesFollowupLadder::allKeys()) are held by" "$OUT" "$D1_105" "$P104"
M105="$(weak105 held held)"; fresh105; OUT="$(R105_MUTANT_OUT=1 run105c --script "$M105" --check)"; rm -f "$M105"
check "$(has "$OUT" 'NO-GO (LK-G1)')|$(went105 "$OUT")|$(live)|$(backups105)" "no|nono|$P104|0" "105-1b' …LK-G1's held refusal weakened: the run goes on past it (caught) — nothing deployed or backed up"
check "$(drest105 L2)" "same|same|same" "105-1b'' …the book back at L2"
# 1c: a setting held there — a note, never a NO-GO (gap 11); the copy that counts settings as held refuses (caught)
jset105 "$DATA/config.json" "$ST105" '"2026-11-01"'
fresh105; OUT="$(run105c --check)"
check "$(checkgo105 "$OUT")|$(noteline "$OUT" LK-G1 "a setting held by a config.json is a note")" "yesnono0|0|0|nokey|yes" \
  "105-1c a setting ($ST105) held by <data>/config.json: GO, LK-G1's note says it is compared after the copy — never a NO-GO (gap 11)"
M105="$(weak105 heldset heldset)"; fresh105; OUT="$(R105_MUTANT_OUT=1 run105c --script "$M105" --check)"; rm -f "$M105"
check "$(has "$OUT" 'NO-GO (LK-G1)')|$(went105 "$OUT")|$(live)" "yes|nono|$P104" "105-1c' …gap 11's control: a copy whose lk reading counts settings as held refuses here (caught by 105-1c's GO)"
check "$(drest105 L2)" "same|same|same" "105-1c'' …the book back at L2"
# 1d: a part-applied book — one of 091's tables gone (raw: the shape a part-applied 091 would leave)
T091_105="$(git -C "$REPO" show "$B105F:$P/migrations/$(sv105 "" MIG104_LAST)" 2>/dev/null | grep -oE 'CREATE TABLE (IF NOT EXISTS )?[a-z_]+' | awk '{print $NF}' | head -1)"
sqx "DROP TABLE IF EXISTS [$T091_105]" >/dev/null; D1_105="$(data94)"
fresh105; OUT="$(run105c --check)"
nogo105 "105-1d QF-G1 refuses a part-applied book (091's $T091_105 gone) — nothing changed" "NO-GO (QF-G1): the book does not carry 089, 090 and 091 as $BV105 left them" "$OUT" "$D1_105" "$P104"
M105="$(weak105 part part)"; fresh105; OUT="$(R105_MUTANT_OUT=1 run105c --script "$M105" --check)"; rm -f "$M105"
check "$(has "$OUT" 'NO-GO (QF-G1): the book does not carry')|$(went105 "$OUT")|$(live)" "no|nono|$P104" "105-1d' …QF-G1's part-applied refusal weakened: its words gone (caught)"
check "$(drest105 L2)" "same|same|same" "105-1d'' …the book back at L2"
# 1e: a ladder key changed through the tool while the DEPLOY question waits — a setting, then on agent-cc: LK-B1 refuses,
# nothing copied. The answer is typed only once the change is made (run105 --hold).
lkb1run105() {   # lkb1run105 LABEL SCRIPT [OPTION…] -- ACTION… — a deploy whose DEPLOY is typed after ACTION ran while the question waited;
                 # LKB1RC105 the tool's exit code for ACTION (sfrc104), LKB1N105 this rehearsal's lkdiff n across the run
  local lab="$1" scr="$2" st hf op m0 opts=()
  shift 2; while [ "$#" -gt 0 ] && [ "$1" != -- ]; do opts+=("$1"); shift; done; shift
  st="$R105/logs/stream-$lab.txt"; hf="$R105/logs/hold-$lab"; rm -f "$hf" "$R105/logs/act-$lab.rc"; : > "$st"
  m0="$(lkmap105 "b1-$lab-pre")"
  ( i=0; while ! grep -q 'Type DEPLOY' "$st" 2>/dev/null && [ "$i" -lt 6000 ]; do sleep 0.05; i=$((i+1)); done
    "$@" > "$R105/logs/act-$lab.txt" 2>&1; sfrc104 > "$R105/logs/act-$lab.rc"; : > "$hf" ) & op=$!
  fresh105; LKB1OUT105="$(R105_STREAM="$st" run105c --answer DEPLOY --hold "$hf" --script "$scr" ${opts[@]+"${opts[@]}"})"
  wait "$op" 2>/dev/null
  LKB1RC105="$(cat "$R105/logs/act-$lab.rc" 2>/dev/null)"; LKB1N105="$(kv105 "$(q105 lkdiff "$m0" "$(lkmap105 "b1-$lab-post")")" n)"
}
# The setting's new value: a Kampala date a year before the clock — persona-confirmed takes a date not after today (the
# ladder's 'past' rule), and the date the book holds was stored under this same clock, which only moves forward, by weeks.
lkb1run105 b1set "$DEPLOY105" -- sf104c set persona-confirmed "$(kday104 $(( $(fnow104) - 365 * 86400 )))"
check "$(has "$LKB1OUT105" 'NO-GO (LK-B1): a ladder key changed during this run')$(has "$LKB1OUT105" 'nothing was copied')|$(live)|$(installed105 "$B105F")|$(wason104 "$LKB1OUT105")|$LKB1RC105|$( [ "$LKB1N105" -gt 0 ] 2>/dev/null && echo moved)" "yesyes|$P104|0|0|0|moved" \
  "105-1e LK-B1: a setting changed through the tool (set persona-confirmed, a past date: saved, exit 0, the key moved by this rehearsal's own reading) while the DEPLOY question waited — refused after DEPLOY, before the copy: nothing copied, $P104 serving, every file $BV105's"
tidy105 b1set; check "$(drest105 L2)" "same|same|same" "105-1e' …the book back at L2 (the case's backup and state files moved aside)"
lkb1run105 b1cc "$DEPLOY105" -- sf104c on agent-cc
check "$(has "$LKB1OUT105" 'NO-GO (LK-B1): a ladder key changed during this run')|$(grep -c '^ON agent-cc' "$R105/logs/act-b1cc.txt")|$(live)|$(installed105 "$B105F")" "yes|1|$P104|0" \
  "105-1e'' LK-B1: on agent-cc through the tool while the question waited (saved) — refused before the copy, nothing copied"
tidy105 b1cc; check "$(drest105 L2)" "same|same|same" "105-1e''' …the book back at L2"
echo "  note  RH-NOTE 105-1e's weakened copy (LK-B1's comparison gone) deploys; it is run after 105-4's first deploy (105-4h), so that 105-4 is a first deploy as production's is"
# 1f: --clear-switches refused at parse; the weakened copy accepts it
D0_105="$(data94)"; fresh105; OUT="$(run105c --check --clear-switches)"
check "$(has "$OUT" "--clear-switches: $V105 never clears a ladder key")$(has "$OUT" 'Nothing was run')|$(went105 "$OUT")|$(has "$OUT" "$V105 (check)")|$(quiet105)" "yesyes|nono|no|$P104|0|0|nokey|$D0_105" \
  "105-1f --clear-switches refused at parse (exit 64), its own command named — nothing run"
M105="$(weak105 clearsw clearsw)"; fresh105; OUT="$(R105_MUTANT_OUT=1 run105c --script "$M105" --check --clear-switches)"; rm -f "$M105"
check "$(has "$OUT" "never clears a ladder key")|$(has "$OUT" "$V105 (check)")" "no|yes" "105-1f' …the copy that accepts it runs the check (caught)"

echo; echo "== 105-4. the deploy over L2, DEPLOY typed — gap 4's sweep inside it; the copies; the same deploy over L4 =="
# The actors' folders 104-7f made and 104-7c16 removed, made again as 104-7f made them — act104 the store's user's, pin104
# the earlier build's tree from Git (tests/ and data/ aside) — for gap 4's actor; removed again after 105-4k'.
ACT104="$H104/act104"; rm -rf "${ACT104:?}"; mkdir -p "$ACT104"; chown 1000:1000 "$ACT104"; chmod 755 "$ACT104"; ACTPIDS104=()
PINT104="$H104/pin104"; rm -rf "${PINT104:?}"; mkdir -p "$PINT104"
git -C "$REPO" archive "$B105F" "$P" | tar -xf - -C "$PINT104" --exclude="$P/tests" --exclude="$P/data"
check "$(stat -c '%u:%a' "$ACT104" 2>/dev/null)|$( [ -f "$PINT104/$P/cron/quote_daily_list.php" ] && [ -f "$PINT104/$P/lib/QuoteDailyList.php" ] && echo tree)|$( [ -e "$PINT104/$P/tests" ] && echo tests || echo notests)|$(treesum104 files)" \
      "1000:755|tree|notests|$(treesum104 git)" \
  "105-4 control: the actors' folder made again, the store's user's; the earlier build's code $B105's tree from Git, tests/ and data/ aside, every file byte for byte as 5.18.104's pin holds it (its digest from the files equal to the one computed from Git)"
DLA105="$(git -C "$REPO" show "$B105F:$P/lib/QuoteDailyList.php" | grep -F "DELETE FROM quote_followup_state WHERE k LIKE 'list_last:%'")"
check "$(grep -c . <<<"$DLA105")|$(git -C "$REPO" show "$P105F:$P/lib/QuoteDailyList.php" | grep -cxF -- "$DLA105")" "1|1" \
  "105-4 control: the daily list's sweep deletes its list_last:* rows in one line of lib/QuoteDailyList.php, read from Git ($B105's and the pin's alike) — the anchor of the actor's pause"
plantll105() {   # plantll105 DAY — two list_last:<DAY>:… rows, raw, as the store's user (a list run's per-owner code: no writer
                 # makes these two owner keys; the sweep removes every list_last row dated DAY or earlier)
  ( cd "$ACT104" && setpriv --reuid 1000 --regid 1000 --clear-groups -- python3 -I -c 'import sqlite3, sys
c = sqlite3.connect(sys.argv[1], timeout=15); c.execute("PRAGMA busy_timeout = 15000")
for o in ("rh105-g4a", "rh105-g4b"):
    c.execute("INSERT OR REPLACE INTO quote_followup_state (k, v, updated_at) VALUES (?, ?, datetime(\"now\"))", ("list_last:%s:%s" % (sys.argv[2], o), "not_sent"))
c.commit(); c.close()' "$DATA/plugin.sqlite3" "$1" ) 2>>"$R105/logs/plant105.err"
}
swept105() { python3 -I -c 'import sqlite3, sys
c = sqlite3.connect("file:" + sys.argv[1] + "?mode=ro", uri=True)
r = c.execute("SELECT v FROM quote_followup_state WHERE k = ?", ("list_swept",)).fetchone(); print(r[0] if r else "none")' "$DATA/plugin.sqlite3" 2>/dev/null; }
g4run105() {   # g4run105 LABEL [--script FILE] OPTION… — gap 4 (root docs/74 §B.1 G-4): a daily-list run of the earlier build (a COPY
               # with a pause before its sweep's DELETE, inside BEGIN IMMEDIATE) held there from before the deploy; the baseline
               # read (base.1) and LK-CA2's rows printed with it; then the sweep let go — after the copy's start, before stage R
  local lab="$1" root a st op; shift
  clk_to104 "$(kday_next104 11:05)"; G4DAY105="$(kday104 "$(fnow104)")"; plantll105 "$G4DAY105"
  G4LL0_105="$(kv105 "$(q105 listlast "$DATA/plugin.sqlite3" "$G4DAY105")" n)"; G4C0_105="$(counts105)"; G4M0_105="$(lkmap105 "g4-$lab-pre")"
  root="$(pinroot104 "g4$lab")"; pause104 "$root/lib/QuoteDailyList.php" "$DLA105"
  a="$ACT104/g4$lab.p"; rm -f "$a".*
  actor104 "g4$lab" "$a" "$root/cron/quote_daily_list.php"
  wfile104 "$a.ready" 120; G4READY105="$( [ -f "$a.ready" ] && echo ready || echo "not:$(tr -d '\n' < "$ACT104/g4$lab.out" 2>/dev/null | cut -c1-100)")"
  st="$R105/logs/stream-g4$lab.txt"; : > "$st"; rm -f "$R105/logs/g4$lab.order"
  tl_arm104 base.1.after
  ( wfile104 "$SB/.tl104/base.1.end" 900 || exit 0
    : > "$SB/.tl104/base.1.release"
    i=0; while ! grep -q 'QF-G1 the baseline, read-only from the live book just before the copy' "$st" 2>/dev/null && [ "$i" -lt 6000 ]; do sleep 0.05; i=$((i+1)); done
    fnow104 > "$a.letgo"; : > "$a.go"; wfile104 "$ACT104/g4$lab.rc" 120
    if grep -qE '^  (ok|FAIL)  +LK-CA1 ' "$st" 2>/dev/null; then echo late; else echo before; fi > "$R105/logs/g4$lab.order" ) & op=$!
  fresh105; G4OUT105="$(R105_STREAM="$st" run105c --answer DEPLOY "$@")"; G4T105="$(last105)"
  wait "$op" 2>/dev/null; golet104 "$a"; actwait104
  G4U105="$(tlu104 base.1)"; tl_disarm104 "g4$lab"
  G4LL1_105="$(kv105 "$(q105 listlast "$DATA/plugin.sqlite3" "$G4DAY105")" n)"; G4C1_105="$(counts105)"; G4M1_105="$(lkmap105 "g4-$lab-post")"
}
check "$(drest105 L2)" "same|same|same" "105-4 the book at L2"
dsnap105 g4pre
C4A0_105="$(fnow104)"; g4run105 a; C4A1_105="$(fnow104)"
OUT="$G4OUT105"; OUT4_105="$OUT"
# The first copy of 5.18.105 on the clock that stamps the plugin's and the servers' logs (the rehearsal's, root docs/74
# §H.6.5): the copy's start as its deploy recorded it — QF-G1's baseline call, time() under the clock (QF_BASE's unix=) —
# the sweeps' line. Never COPY_AT: the copied file's kernel time, which the clock never moves (wall clock, days behind).
# Its controls: the moments hook's own reading of that call (base.1), and the clock before and after 105-4a around it.
COPYU105="$(sed -n 's/^QF_BASE=.* unix=\([0-9][0-9]*\)$/\1/p' "$STATE105" | tail -1)"
COPYC105="$( [ -n "$COPYU105" ] && [ "$COPYU105" = "$G4U105" ] && echo base1)|$( [ "$(n103 "$C4A0_105")" -le "$(n103 "$COPYU105")" ] && [ "$(n103 "$COPYU105")" -le "$(n103 "$C4A1_105")" ] && echo inrun)"
check "$(fails "$OUT")$(has "$OUT" "  $V105 (deploy): PASSED.")|$(live)|$(installed105 "$P105F")|$(inst_ver)" "0yes|$P105|0|$V105" \
  "105-4a the deploy over L2 (DEPLOY typed, a first deploy): PASSED — $P105 serving, every file the pin's, $V105"
check "$(okline "$OUT" LK-CA1 "after the copy every ladder key, switch, record key and setting alike, reads in the four places exactly as at LK-B1")|$(kv105 "$(q105 lkdiff "$G4M0_105" "$G4M1_105")" n)|$(wason104 "$OUT")" "yes|0|0" \
  "105-4b LK-CA1 ok, and this rehearsal's own reading finds the four places' ladder subsets byte-identical before and after (lkdiff n=0); no tool line 'was on:'"
check "$(kv105 "$(q105 fell "$G4C0_105" "$G4C1_105")" fell)|$(kv105 "$G4C0_105" sc)" "-|$(kv105 "$G4C1_105" sc)" \
  "105-4c the 089–091 and P7 tables, and the quote follow-ups, never fell by this rehearsal's own count (quote_followup_state over its rows NOT LIKE 'list_last:%'); every _schema_checks row unchanged"
check "$(grep -cE '^  (ok|note) +LK-ON ' <<<"$OUT" | awk '{print ($1 > 0) ? "read" : "none"}')|$(grep -c '^  FAIL  LK-ON ' <<<"$OUT")|$(okline "$OUT" LM-CA1 "the installed files hold each of the")|$(grep -cE '^  (ok|note) +LM-CA2 ' <<<"$OUT" | awk '{print ($1 > 0) ? "read" : "none"}')|$(grep -cE '^  FAIL  LM-' <<<"$OUT")" \
      "read|0|yes|read|0" \
  "105-4d LK-ON read for each step on (none FAIL); LM-CA1 ok — the installed files hold each entry's new block once and its old block nowhere; LM-CA2's counts printed"
RBN105="$(grep -nxF "      $EXP_ROLLBACK105" <<<"$OUT" | cut -d: -f1)"; VDN105="$(grep -nF "  $V105 (deploy): PASSED." <<<"$OUT" | head -1 | cut -d: -f1)"
printf '%s\n' "$OUT" > "$R105/logs/deploy4a.txt"
check "$(grep -c . <<<"$RBN105")|$( [ "$(n103 "$RBN105")" -gt "$(n103 "$VDN105")" ] && echo after)|$(kv105 "$(q105 blocks "$R105/logs/deploy4a.txt" "$V105")" mixed)" "1|after|0" \
  "105-4e the rollback printed once, after the verdict, a block of its own (no copyable block mixes it with another command)"
check "$G4READY105|$(n103 "$G4LL0_105")|$G4LL1_105|$(swept105)|$(cat "$R105/logs/g4a.order" 2>/dev/null)|$( [ "$(n103 "$(cat "$ACT104/g4a.p.letgo" 2>/dev/null)")" -ge "$(n103 "$G4U105")" ] 2>/dev/null && echo aftercopy)" \
      "ready|2|0|$G4DAY105|before|aftercopy" \
  "105-4f gap 4: the daily-list sweep held before its DELETE (inside its BEGIN IMMEDIATE) from before the deploy, let go after the copy's start (the baseline's own second) and ended before stage R read — its two list_last:$G4DAY105 rows gone, list_swept the day"
LKC2_105="$(sed -n "s/^  note  LK-CA2 quote_followup_state, whole: \([0-9-]*\) → \([0-9-]*\) .*/\1 \2/p" <<<"$OUT" | head -1)"
check "$(okline "$OUT" LK-CA2 "no table's rows fell across the copy")|$(set -- $LKC2_105; [ "$#" = 2 ] && [ "$2" -lt "$1" ] 2>/dev/null && echo lower)" "yes|lower" \
  "105-4g LK-CA2 PASS across the copy (quote_followup_state over its rows NOT LIKE 'list_last:%'); its whole count lower in a note ($LKC2_105) — the sweep deleted inside its own transaction, after the baseline"
# one master cycle after the copy: each of the four jobs dispatched exactly as its flag reads
FL105="$(q105 flags "$PD/cron/master.php" "$DATA/plugin.sqlite3" "$(tr ' ' ',' <<<"$(sv105 "" QF_JOBS)")")"
clk_to104 $(( $(fnow104) + 300 )); MT0_105="$(fnow104)"
MM105="$(master104 105g4m $(sv105 "" QF_JOBS))"
RAN105="$(for j in $(sv105 "" QF_JOBS); do e="$(sched104 "$j")"; [ "$e" != none ] && [ "$(n103 "${e%%:*}")" -ge "$MT0_105" ] && printf '%s,' "$j"; done | sed 's/,$//')"
check "$MM105|${RAN105:--}" "0|$(kv105 "$FL105" want)" \
  "105-4h one master cycle after the copy (the installed master, under the clock): the jobs dispatched are exactly those whose flag reads on ($(kv105 "$FL105" flags))"
rbx105 "105-4i"
check "$(grep -c "STEP 12 (OFFERS-LIVE) IS ON" <<<"$RBX105_OUT")|$(grep -c "LM-RB1 $(sv105 "" LM_LN_N) LOG LINES WRITE PHONE NUMBERS AGAIN" <<<"$RBX105_OUT")" "0|1" \
  "105-4i'' …over L2 (step 12 off) no step-12 notice; LM-RB1's count in capitals"
# 1e's weakened copy (deferred here): LK-B1's comparison gone, the setting changed while the question waits — it copies
check "$(drest105 L2)" "same|same|same" "105-4j the book at L2"
M105="$(weak105 lkb1 lkb1)"; R105_MUTANT_OUT=1 lkb1run105 b1w "$M105" --redeploy -- sf104c set persona-confirmed "$(kday104 $(( $(fnow104) - 365 * 86400 )))"; rm -f "$M105"
check "$(has "$LKB1OUT105" 'NO-GO (LK-B1)')|$(live)|$LKB1RC105|$( [ "$LKB1N105" -gt 0 ] 2>/dev/null && echo moved)" "no|$P105|0|moved" "105-1e'''' (run here) LK-B1's comparison weakened: the key changed while the question waited (saved, exit 0, moved by this rehearsal's own reading), and the copy was made anyway (caught by 105-1e)"
rbx105 "105-4j'"
check "$(drest105 L2)" "same|same|same" "105-4k the book at L2"
# gap 4's weakened copy: lkrows without its list_last:% filter — LK-CA2 FAILs over the same sweep (caught)
M105="$(weak105 lkrows lkrows)"; R105_MUTANT_OUT=1 g4run105 w --script "$M105" --redeploy; rm -f "$M105"
check "$(grep -c '^  FAIL  LK-CA2 rows FELL across the copy:' <<<"$G4OUT105")|$(grep -c 'quote_followup_state_kept:' <<<"$(grep '^  FAIL  LK-CA2 ' <<<"$G4OUT105")")|$G4READY105|$(cat "$R105/logs/g4w.order" 2>/dev/null)" "1|1|ready|before" \
  "105-4k' gap 4's weakened copy (lkrows without the list_last:% filter): over the same held sweep LK-CA2 FAILs — quote_followup_state's rows fell across the copy (caught)"
rbx105 "105-4k''"
# What gap 4 leaves, as 104-7c16 left it: every actor ended (g4run105 waits for it), the moments hook inert, both actors'
# folders removed — no injected pause anywhere; the control both folders and the actor's pause there before the removal.
G4K0_105="$( [ -d "$ACT104/g4w" ] && [ -f "$PINT104/$P/lib/QuoteDailyList.php" ] && echo were)|$(grep -rl 'RH104_PAUSE' "$ACT104" 2>/dev/null | grep -c . | awk '{print ($1 > 0) ? "paused" : "none"}')"
rm -rf "${ACT104:?}" "${PINT104:?}"
check "$G4K0_105|$( [ -e "$SB/.tl104" ] && echo armed || echo inert)|$( [ -e "$ACT104" ] && echo actors || echo none)|$( [ -e "$PINT104" ] && echo pin || echo none)|$(grep -rl 'RH104_PAUSE' "$PD" "$H104" 2>/dev/null | grep -c .)" \
      "were|paused|inert|none|none|0" \
  "105-4k'''' gap 4 leaves nothing armed, as 104-7c16 left it: the moments hook inert, the actors' folder and the earlier build's tree removed, no injected pause in the installed plugin or the rehearsal's folders — both folders and the actor's pause there before the removal (the control)"
# the same deploy over L4: LK-G1's capital note, the keys unchanged
check "$(drest105 L4)" "same|same|same" "105-4l the book at L4 (offers-live on under $BV105)"
M0_105="$(lkmap105 l4-pre)"; fresh105; OUT="$(run105c --answer DEPLOY --redeploy)"; M1_105="$(lkmap105 l4-post)"
check "$(fails "$OUT")$(has "$OUT" "  $V105 (deploy): PASSED.")|$(noteline "$OUT" LK-G1 "OFFERS_LIVE_ENABLED IS SET — STEP 12 (OFFERS-LIVE) IS ALREADY ON UNDER $BV105")|$(kv105 "$(q105 lkdiff "$M0_105" "$M1_105")" n)|$(wason104 "$OUT")" "0yes|yes|0|0" \
  "105-4l' over L4: PASSED, LK-G1's capital note (step 12 already on under $BV105; this deploy the remedy, changing the key neither way) — every ladder key byte-identical after"
rbx105 "105-4l''"
check "$(grep -c "STEP 12 (OFFERS-LIVE) IS ON" <<<"$RBX105_OUT")|$(grep -c "^      cd $REPO && bash $QFW105 off offers-live\$" <<<"$RBX105_OUT")" "1|1" \
  "105-4l''' …its rollback with step 12 on: the step-12 notice in capitals, the off command printed alone (never run: every key kept)"
check "$(drest105 L2)" "same|same|same" "105-4m the book at L2"
fresh105; OUT="$(run105c --answer DEPLOY --redeploy)"
check "$(fails "$OUT")$(has "$OUT" "  $V105 (deploy): PASSED.")|$(live)|$(okline "$OUT" LK-CA1 "after the copy every ladder key")|$(keyf105)" "0yes|$P105|yes|key" \
  "105-4m' the redeploy over L2, DEPLOY typed: PASSED, $P105 serving, LK-CA1 ok, the LK key kept — the state 105-5 works on"

echo; echo "== 105-5. after the deploy: --after-only, the owner's next steps through the installed tool, the drives LN-01…LN-04 =="
aft105 "105-5a" $L2ON105
check "$(noteline "$AFT105" LK-AO "read exactly as the deploy left them")|$(okline "$AFT105" LM-ON "the installed files hold each of the")|$(grep -c '^  FAIL  LM-' <<<"$AFT105")" "yes|yes|0" \
  "105-5a' …LK-AO, read with the kept key, names no key; LM-ON passes; QF-ON judges steps 1–6 (above)"
M50_105="$(lkmap105 a5-pre)"
OUT="$(sf104c skip voice-dry-run --reason "the rehearsal: offers before the voice rungs")"; R5A105="$(sfrc104)"
SK5_105="$(kv105 "$(lk105)" skipped)"
OUT="$(sf104c on offers-test)"; R5B105="$(sfrc104)"
OP5_105="$(live104 offerprep "$PD" "$DATA" '{"title":"RH104SECRET-offer of 105-5","w1":"RH104SECRET-first wording after the masking deploy, over twenty characters","w2":"RH104SECRET-second wording after the masking deploy, unlike the first","channel":"sales"}')"
OUT="$(sf104c --check offers-test)"; R5C105="$(sfrc104)"; C5C105="$(grep -cx 'offers-test: PASS' <<<"$OUT")"
OUT="$(sf104c on offers-live)"; R5D105="$(sfrc104)"
M51_105="$(lkmap105 a5-post)"; LD5_105="$(q105 lkdiff "$M50_105" "$M51_105")"
check "$R5A105|$(for s in $(tr ',' ' ' <<<"$VDR105"); do tr ',' '\n' <<<"$SK5_105" | grep -qx "$s" && printf 'k'; done)|$R5B105|$(qj "$OP5_105" tests 2>/dev/null)|$R5C105$C5C105|$R5D105" \
      "0|$(for s in $(tr ',' ' ' <<<"$VDR105"); do printf 'k'; done)|0|sent,sent|01|0" \
  "105-5b through the installed tool: skip voice-dry-run (steps $(tr ',' ' ' <<<"$VDR105") recorded skipped by its cascade), on offers-test, both wordings tested, --check offers-test PASS, on offers-live — the owner's order"
aft105 "105-5c" $L2ON105 "offers-test=judged" "offers-live=judged" "voice-dry-run=any" "voice-staff=any" "voice-reply=any"
AO5_105="$(sed -n "s/^  note  LK-AO the ladder's keys changed since the deploy ([^)]*): \(.*\) — the operator's steps; now: .*/\1/p" <<<"$AFT105" | head -1 | tr ' ,' '\n\n' | sed 's/@.*//' | grep . | LC_ALL=C sort -u | paste -sd, -)"
check "$( [ -n "$AO5_105" ] && echo named)|$( [ "$AO5_105" = "$(kv105 "$LD5_105" keys)" ] && echo same)|$(for s in $(tr ',' ' ' <<<"$VDR105"); do tr ',' '\n' <<<"$AO5_105" | grep -qx "sf_skip_$s" && printf 'k'; done)" \
      "named|same|$(for s in $(tr ',' ' ' <<<"$VDR105"); do printf 'k'; done)" \
  "105-5c' LK-AO names exactly the keys those commands wrote, as this rehearsal's own reading finds them changed ($(kv105 "$LD5_105" keys)): the three sf_skip_* keys of steps 7–9 and the offers keys"
mv "$LKF105" "$R105/case/lk-key.aside"
after_back105; fresh105; OUT="$(run105c --after-only)"
mv "$R105/case/lk-key.aside" "$LKF105"
check "$(noteline "$OUT" LK-AO "is not there or holds no key")|$(has "$OUT" "cannot")|$(stat -c %a "$LKF105")" "yes|yes|600" \
  "105-5d gap 5's control: the same --after-only with the key file moved aside says the ladder's keys cannot be compared — nothing named; the key file put back, 0600"
# ── The drives (root docs/74 §H.6.6), through the installed 5.18.105, each in a log of its own ───────────────────────────
DRV105="$R105/drive"; INST105="$H105/inst"; rm -rf "$INST105"; mkdir -p "$INST105"; cp -a "$PD/." "$INST105/"; chown -R 1000:1000 "$INST105"
check "$( [ "$(sha256sum < "$INST105/$CM105")" = "$(git -C "$REPO" show "$P105F:$P/$CM105" | sha256sum)" ] && echo pin)|$(live)" "pin|$P105" \
  "105-5e control: the drives' code root is a copy of the installed plugin in the rehearsal's own folder — the pin's bytes ($CM105 read against Git), $P105 serving"
scratch105() {   # scratch105 NAME — a scratch copy of the book's data folder, marked, the store's user's; prints its path
  local d="$H105/scr-$1"; rm -rf "${d:?}"; cp -a "$DATA" "$d"; : > "$d/rh105-scratch"; chown -R 1000:1000 "$d"; echo "$d"
}
# LN-01: a STOP through the real webhook, the installed evo_webhook.php
WHP105="$(free_port)"; WHU105="http://127.0.0.1:$WHP105/evo_webhook.php"
setpriv --reuid 1000 --regid 1000 --clear-groups -- env LD_PRELOAD="$CLKPRE104" RH104_CLOCK_FILE="$CLKF104" "$PHPBIN" -d disable_functions=exec -S "127.0.0.1:$WHP105" -t "$PD" >"$DRV105/wh105-ln01.log" 2>&1 & WHPID105=$!; PIDS+=("$WHPID105")
for i in $(seq 1 50); do [ "$(curl -s --noproxy '*' -o /dev/null -w '%{http_code}' "$WHU105")" = "405" ] && break; sleep 0.1; done
clk_to104 $(( $(fnow104) + 120 )); EL1_105="$(evo_calls)"
WS1_105="$(live104 webhook "$PD" "$DATA" "$WHU105" "{\"channel\":\"sales\",\"phone\":\"$PSTOP1_105\",\"name\":\"RH104SECRET-Mukasa\",\"kind\":\"text\",\"text\":\"STOP\",\"id\":\"RH104STOP105B\"}")"
sleep 1; kill "$WHPID105" 2>/dev/null; wait "$WHPID105" 2>/dev/null; EL1_105="$(( $(evo_calls) - EL1_105 ))"
LN01RX105='\[evo_webhook\] opt-out recorded for conversation (#[0-9]+|\(not stored\)) \(matched "[a-z]+"\)'
check "$(qj "$WS1_105" http 2>/dev/null)|$(grep -cE "$LN01RX105" "$DRV105/wh105-ln01.log")|$(grep -cE "$OPTOUT104" "$DRV105/wh105-ln01.log")|$(grep -cF -f "$FORMS105" "$DRV105/wh105-ln01.log")|$(kv105 "$(q105 optout "$DATA/plugin.sqlite3" "$PSTOP1_105")" n)" \
      "200|1|0|0|1" \
  "105-5f LN-01 a STOP through the installed webhook: the opt-out recorded, its line in the masked form exactly once (the conversation, never the number), no old-form line, no fixture number in the log"
# LN-02: the staff SSP report, run once through the installed master on a scratch book where SSP is bookable. master reads
# no DN_DATA_DIR (getDataDir(): ucrm.json's pluginDataDir, else the plugins root's sibling — the LIVE book), so it runs from
# a code root of its own, a copy of the drives' root whose ucrm.json names the scratch (pinroot104's way); the schedule
# planted through that same root on that scratch, and master never run unless that root's getDataDir() answers the scratch
S2_105="$(scratch105 ln02)"; jset105 "$S2_105/config.json" cashbook_currencies '"UGX,USD,SSP"'
SP2_105="$(ref105 sspin "$INST105" "$S2_105" '{"name":"*","ssp":5000}')"
MR2_105="$H105/inst-ln02"; rm -rf "${MR2_105:?}"; mkdir -p "$MR2_105"; cp -a "$INST105/." "$MR2_105/"
printf '{"pluginDataDir":"%s"}\n' "$S2_105" > "$MR2_105/ucrm.json"; chown -R 1000:1000 "$MR2_105"
DD2_105="$(cd "$H105/v" && setpriv --reuid 1000 --regid 1000 --clear-groups -- "$PHPBIN" -d log_errors=0 \
      -r 'require $argv[1] . "/lib/bootstrap_data.php"; echo getDataDir($argv[1]);' "$MR2_105" 2>/dev/null)"
clk_to104 "$(kday_next104 18:05)"
SCH2_105="$(live104 schedule "$MR2_105" "$S2_105" '{"due":["staff_ssp_report"]}')"; EL2_105="$(evo_calls)"
v2_105="$H105/v/ln02-vault.json"; rm -f "$v2_105"; [ ! -f "$VAULT" ] || { cp "$VAULT" "$v2_105"; chown 1000:1000 "$v2_105"; chmod 600 "$v2_105"; }
case "$(head -c 9 <<<"$SCH2_105")|$DD2_105" in "{\"quiet\":|$S2_105")
  ( cd "$H105/v" && setpriv --reuid 1000 --regid 1000 --clear-groups -- env DN_DATA_DIR="$S2_105" DN_VAULT_FILE="$v2_105" LD_PRELOAD="$CLKPRE104" RH104_CLOCK_FILE="$CLKF104" \
      http_proxy= https_proxy= HTTP_PROXY= HTTPS_PROXY= NO_PROXY='*' "$PHPBIN" -d log_errors=0 "$MR2_105/cron/master.php" </dev/null > "$DRV105/master-ln02.out" 2>&1 ) ;;
  *) echo "plant or data folder refused: not run" > "$DRV105/master-ln02.out" ;;
esac
rm -f "$v2_105"; EL2_105="$(( $(evo_calls) - EL2_105 ))"
check "$(qj "$SP2_105" staff 2>/dev/null)|$( [ -n "$DD2_105" ] && [ "$DD2_105" = "$S2_105" ] && echo scratch)|$(grep -c '\[staff_ssp_report\] Sent to ' "$DRV105/master-ln02.out")|$(grep -cF -f "$FORMS105" "$DRV105/master-ln02.out")|$(grep -cE '[0-9]{9,}' "$DRV105/master-ln02.out" | awk '{print ($1 > 0) ? "runs" : "none"}')" \
      "found|scratch|1|0|none" \
  "105-5g LN-02 cron/staff_ssp_report.php run once through the installed master at 18:05 Kampala, from a copy of the installed plugin whose ucrm.json names a scratch book where SSP is bookable (its getDataDir() probed first: the scratch, never the live book; one SSP 'in' row through the release's StaffLedgerService): its line once — the staff member's name and the balances, no number, no run of nine digits"
# LN-03: a quotation WhatsApp whose send throws, the exception carrying the number in its four forms
S3_105="$(scratch105 ln03)"; EL3_105="$(evo_calls)"
: > "$DRV105/ln03.log"; chown 1000:1000 "$DRV105/ln03.log"; chmod 600 "$DRV105/ln03.log"   # PHP opens its error log as the store's user; the folder is root's
L3_105="$(ref105 ln03 "$INST105" "$S3_105" "{\"phone\":\"$PLN03_105\",\"text\":\"RH104SECRET-the quotation text of the drive\",\"log\":\"$DRV105/ln03.log\"}")"
EL3_105="$(( $(evo_calls) - EL3_105 ))"
check "$(qj "$L3_105" threw 2>/dev/null)|$(grep -c '\[QuotationService\] WA send failed (' "$DRV105/ln03.log" 2>/dev/null)|$(grep -cF -f "$FORMS105" "$DRV105/ln03.log" 2>/dev/null)|$EL3_105" "1|1|0|0" \
  "105-5h LN-03 QuotationService's WhatsApp send through a sender that throws with the number in its four forms: the line once, its message masked — no form of the number; Evolution not called"
# LN-04: the support leader's CRM job, against the rehearsal's own fake CRM on 127.0.0.1 (method and path logged, nothing else)
mkdir -p "$H105/crm"; cat > "$H105/crm/router.php" <<'CRM'
<?php
// 5.18.105's rehearsal: a fake CRM on 127.0.0.1 — each request's method and path (never a body), a job id for a POST
$p = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
file_put_contents(__DIR__ . '/requests.log', ($_SERVER['REQUEST_METHOD'] ?? '?') . ' ' . $p . "\n", FILE_APPEND);
header('Content-Type: application/json');
echo ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' ? '{"id":4711}' : '[]';
CRM
: > "$H105/crm/requests.log"; chown -R 1000:1000 "$H105/crm"
CRMP105="$(free_port)"
setpriv --reuid 1000 --regid 1000 --clear-groups -- "$PHPBIN" -S "127.0.0.1:$CRMP105" "$H105/crm/router.php" >"$DRV105/crm-server.log" 2>&1 & CRMPID105=$!; PIDS+=("$CRMPID105")
for i in $(seq 1 50); do [ "$(curl -s --noproxy '*' -o /dev/null -w '%{http_code}' "http://127.0.0.1:$CRMP105/rh105crm")" = "200" ] && break; sleep 0.1; done
: > "$H105/crm/requests.log"
S4_105="$(scratch105 ln04)"; EL4_105="$(evo_calls)"
: > "$DRV105/ln04.log"; chown 1000:1000 "$DRV105/ln04.log"; chmod 600 "$DRV105/ln04.log"   # as LN-03's: the store's user's, in root's folder
L4_105="$(ref105 ln04 "$INST105" "$S4_105" "{\"phone\":\"$PLN04_105\",\"name\":\"RH104SECRET-Kintu\",\"issue\":\"RH104SECRET-the dish lost its signal\",\"leader\":\"RH104SECRET-Bidal Okot\",\"leader_phone\":\"$PLEAD105\",\"crm\":\"http://127.0.0.1:$CRMP105/rh105crm\",\"log\":\"$DRV105/ln04.log\"}")"
EL4_105="$(( $(evo_calls) - EL4_105 ))"; kill "$CRMPID105" 2>/dev/null; wait "$CRMPID105" 2>/dev/null
check "$(grep -c '\[WaAutoReply\] CRM Job #[0-9]* created and assigned to Bidal (uid:' "$DRV105/ln04.log" 2>/dev/null)|$(grep -cF -f "$FORMS105" "$DRV105/ln04.log" 2>/dev/null)|$(grep -cx 'POST /rh105crm/scheduling/jobs' "$H105/crm/requests.log"),$(grep -cx 'POST /rh105crm/scheduling/jobs/4711/job-tasks' "$H105/crm/requests.log"),$(grep -c '^POST ' "$H105/crm/requests.log")|$(grep -vc '^\(GET\|POST\) /rh105crm' "$H105/crm/requests.log")|$EL4_105" \
      "1|0|1,1,2|0|0" \
  "105-5i LN-04 the support job created in the rehearsal's own fake CRM (createCrmJobForBidal's two POSTs under /rh105crm — the job, then its task on the fake's job 4711 — and no other POST, nothing else asked), its line once — the job and the leader's CRM id, no number; Evolution not called"
EVODRV105=$(( EL1_105 + EL2_105 + EL3_105 + EL4_105 ))

echo; echo "== 105-6. the rollback, ROLLBACK typed, with steps 1–6, 11 and 12 on and 7–9 recorded skipped =="
LK6_105="$(lk105)"; C60_105="$(counts105)"; K60_105="$(lkmap105 rb6-pre)"; SH60_105="$(sf104c show)"
check "$(srt105 "$(kv105 "$LK6_105" on)" | tr ',' '\n' | grep -cxE 'report|ack|tracking|list|voice-measure|chase|offers-test|offers-live')|$(for s in $(tr ',' ' ' <<<"$VDR105"); do tr ',' '\n' <<<"$(kv105 "$LK6_105" skipped)" | grep -qx "$s" && printf 'k'; done)" \
      "8|$(for s in $(tr ',' ' ' <<<"$VDR105"); do printf 'k'; done)" \
  "105-6 control: before the rollback, steps 1–6, 11 and 12 on and 7–9 recorded skipped, by this rehearsal's own reading"
RBL105="$(printed "$OUT4_105" "cd $REPO && bash $F105 --rollback")"
cron104_up; fresh105; OUT="$(run_line105c ROLLBACK "$RBL105")"; cron104_down; OUT6_105="$OUT"
C61_105="$(counts105)"; K61_105="$(lkmap105 rb6-post)"
check "$RBL105|$(fails "$OUT")$(has "$OUT" "  $V105 (rollback): PASSED.")|$(live)|$(installed105 "$B105F")|$(wason104 "$OUT")" "$EXP_ROLLBACK105|0yes|$B105|0|0" \
  "105-6a the rollback as 105-4's summary printed it, ROLLBACK typed: PASSED — $B105 back by sha, every file $V105 changes $BV105's; no tool ran (no 'was on:')"
check "$(q105 same "$C60_105" "$C61_105" followups_quote_closed,wa_offers_paused,wa_offer_recipients_settled)|$(kv105 "$(q105 lkdiff "$K60_105" "$K61_105")" n)|$( [ "$(sf104c show)" = "$SH60_105" ] && echo show)" \
      "same=yes|0|show" \
  "105-6b no tool ran: the closed quote follow-ups, the paused offers and the settled recipients unchanged by this rehearsal's count; every ladder key in every place byte-identical; the tool's show the same"
check "$(okline "$OUT" LK-RB1 "every ladder key, in every place, reads after the rollback exactly as before it")$(okline "$OUT" LK-RB1 "shows on a copy, line for line")|$(grep -c '^  FAIL  LK-RB1 ' <<<"$OUT")" "yesyes|0" \
  "105-6c LK-RB1: every ladder key as before the rollback, and $BV105's tool shows the same on a copy, line for line"
S12N105="$(grep -n "STEP 12 (OFFERS-LIVE) IS ON. $B105 WRITES PHONE NUMBERS AGAIN" <<<"$OUT" | head -1 | cut -d: -f1)"
check "$( [ -n "$S12N105" ] && echo notice)|$(sed -n "$(( $(n103 "$S12N105") + 1 )),$(( $(n103 "$S12N105") + 3 ))p" <<<"$OUT" | grep -cx "      cd $REPO && bash $QFW105 off offers-live")|$(sed -n "$(( $(n103 "$S12N105") + 1 ))p;$(( $(n103 "$S12N105") + 3 ))p" <<<"$OUT" | grep -c .)|$(kv105 "$(lk105)" on | tr ',' '\n' | grep -cx offers-live)" \
      "notice|1|0|1" \
  "105-6d the step-12 notice in capitals, the off command printed alone between blank lines — and never run: offers-live still on"
check "$(grep -c "LM-RB1 $(sv105 "" LM_LN_N) LOG LINES WRITE PHONE NUMBERS AGAIN UNDER $BV105" <<<"$OUT")|$(okline "$OUT" LM-RB1 "the installed files hold each of the")" "1|yes" \
  "105-6e LM-RB1: $BV105's old blocks back, and the count of log lines that write a number again printed in capitals"
check "$(okline "$OUT" V3g "S6 the AI and WhatsApp settings are unchanged by this run")|$(grep -c '^  note  V3g ' <<<"$OUT")" "yes|0" \
  "105-6f V3g ok with no excuse branch: the AI and WhatsApp settings unchanged, no key named as removed"
# LM-RB1's control: a STOP after the rollback writes the old form again
WHP6_105="$(free_port)"; WHU6_105="http://127.0.0.1:$WHP6_105/evo_webhook.php"
setpriv --reuid 1000 --regid 1000 --clear-groups -- env LD_PRELOAD="$CLKPRE104" RH104_CLOCK_FILE="$CLKF104" "$PHPBIN" -d disable_functions=exec -S "127.0.0.1:$WHP6_105" -t "$PD" >"$R105/pre/wh104-rb6.log" 2>&1 & WHPID6_105=$!; PIDS+=("$WHPID6_105")
for i in $(seq 1 50); do [ "$(curl -s --noproxy '*' -o /dev/null -w '%{http_code}' "$WHU6_105")" = "405" ] && break; sleep 0.1; done
clk_to104 $(( $(fnow104) + 120 ))
WS6_105="$(live104 webhook "$PD" "$DATA" "$WHU6_105" "{\"channel\":\"sales\",\"phone\":\"$PSTOP2_105\",\"name\":\"RH104SECRET-Wanyama\",\"kind\":\"text\",\"text\":\"STOP\",\"id\":\"RH104STOP105C\"}")"
sleep 1; kill "$WHPID6_105" 2>/dev/null; wait "$WHPID6_105" 2>/dev/null
check "$(qj "$WS6_105" http 2>/dev/null)|$(grep -cE "$OPTOUT104" "$R105/pre/wh104-rb6.log")|$(grep -cE "$LN01RX105" "$R105/pre/wh104-rb6.log")" "200|1|0" \
  "105-6g LM-RB1's control: a STOP after the rollback, through $BV105's webhook, writes the old form again — the number in the line (this log is $BV105's, outside N105)"
OUT="$(qrun104c --check)"; check "$(has "$OUT" 'stray-store quarantine (check): PASSED.')" "yes" "105-6h the quarantine's own --check after it: PASSED"

echo; echo "== 105-6v. V1's automatic rollback: a deploy whose sign-in page redirects rolls itself back, every key kept, no off run =="
K6V0_105="$(lkmap105 v1-pre)"; printf 'x\n' > "$SB/web/.redirect105"; chmod 644 "$SB/web/.redirect105"
cron104_up; fresh105; OUT="$(run105c --answer DEPLOY --redeploy)"; cron104_down
K6V1_105="$(lkmap105 v1-post)"
check "$(failline "$OUT" V1 'the sign-in page on the public address → 200 after 1 redirect(s)')$(has "$OUT" '  ROLLING BACK — the public address must never redirect (every switch kept)')|$(spent99 .redirect105)|$(live)|$(installed105 "$B105F")|$(kv105 "$(q105 lkdiff "$K6V0_105" "$K6V1_105")" n)|$(wason104 "$OUT")|$(kv105 "$(lk105)" on | tr ',' '\n' | grep -cx offers-live)" \
      "yesyes|spent|$B105|0|0|0|1" \
  "105-6v a redeploy whose public sign-in page redirects (planted, spent once): V1 FAILs and the deploy rolls itself back — $B105 serving, every file $BV105's, every ladder key byte-identical, no tool ran, offers-live still on (no off run)"
rm -f "$SB/web/.redirect105"
OUT="$(qrun104c --check)"; check "$(has "$OUT" 'stray-store quarantine (check): PASSED.')" "yes" "105-6v' the quarantine's --check after it: PASSED"

echo; echo "== 105-7. the weakened copies, each caught by the check it weakens; the redeploy =="
check "$(drest105 L2)" "same|same|same" "105-7 the book at L2, $(live) serving"
# 7a: --check copies over the live 5.18.104, each refused by the check it weakens
nogo7a105() {   # nogo7a105 LABEL KEY TEXT — a --check copy with KEY weakened: refused with TEXT, nothing asked, deployed or backed up
  local m o; m="$(weak105 "$2" "$2")"; fresh105; o="$(R105_MUTANT_OUT=1 run105c --script "$m" --check)"; rm -f "$m"
  check "$(has "$o" "$3")$(has "$o" 'Nothing was changed. Send the log file.')|$(went105 "$o")|$(live)|$(backups105)" "yesyes|nono|$B105|0" "$1"
}
nogo7a105 "105-7a1 MASTER_BASE left at $BV105's own baseline (${MB104_105:0:8}…): A15 refuses — the live master is $BV105's" mbase "NO-GO: the live $(sv105 "" MASTER) is not $BV105's"
nogo7a105 "105-7a2 UG_SIGNATURE left as $BV105's: A6 refuses — the live Uganda signature is not the one measured" ugsig "live-uganda-not-measured"
nogo7a105 "105-7a3 VN-G4's 'less W1' kept: VN-G4 refuses — the pin's fromMe branch is $BV105's, W1 in both" vng4 "NO-GO (VN-G4)"
# 7b: deploy-run copies, each followed by the rollback (where it deployed) and the book put back at L2
dcopy105() {   # dcopy105 LABEL KEY… — a deploy of a copy, DEPLOY typed, --redeploy, over L2; its output in DC105, the keys before and after
  local lab="$1" m; shift
  DCM0_105="$(lkmap105 "$lab-pre")"; m="$(weak105 "$lab" "$@")"; fresh105; DC105="$(R105_MUTANT_OUT=1 run105c --answer DEPLOY --script "$m" --redeploy)"; rm -f "$m"
  DCM1_105="$(lkmap105 "$lab-post")"
}
dcopy105 b7clrnoca1 clear noca1
check "$(grep -cE '^  (ok|FAIL)  +LK-CA1 ' <<<"$DC105")|$( [ "$(kv105 "$(q105 lkdiff "$DCM0_105" "$DCM1_105")" n)" -gt 0 ] 2>/dev/null && echo moved)|$(live)" "0|moved|$P105" \
  "105-7b1 the clear re-inserted with LK-CA1 removed: the run says nothing of LK-CA1 ($(grep -oE "  $V105 \(deploy\): [A-Z]+" <<<"$DC105" | head -1 | sed 's/^ *//')) while this rehearsal's own reading finds the ladder's keys moved — caught"
rbx105 "105-7b1'"; check "$(drest105 L2)" "same|same|same" "105-7b1'' the book back at L2"
dcopy105 b7clr clear
check "$(grep -c '^  FAIL  LK-CA1 a ladder key changed, was added or was removed across the copy' <<<"$DC105")|$( [ "$(kv105 "$(q105 lkdiff "$DCM0_105" "$DCM1_105")" n)" -gt 0 ] 2>/dev/null && echo moved)" "1|moved" \
  "105-7b2 the clear alone: LK-CA1 FAILs — a ladder key changed across the copy (caught)"
rbx105 "105-7b2'"; check "$(drest105 L2)" "same|same|same" "105-7b2'' the book back at L2"
dcopy105 b7qf5 qfca5
check "$(grep -c '^  FAIL  QF-CA5 ' <<<"$DC105")|$(kv105 "$(q105 lkdiff "$DCM0_105" "$DCM1_105")" n)" "1|0" \
  "105-7b3 QF-CA5's 5.18.104 form kept (every step off, 13 of 13): FAIL over a live ladder (caught) — the keys themselves untouched"
rbx105 "105-7b3'"; check "$(drest105 L2)" "same|same|same" "105-7b3'' the book back at L2"
printf 'x\n' > "$SB/web/.redirect105"; chmod 644 "$SB/web/.redirect105"
cron104_up; dcopy105 b7v1 v1clr; cron104_down; rm -f "$SB/web/.redirect105"
check "$(has "$DC105" '  ROLLING BACK — the public address must never redirect (every switch kept)')|$(live)|$(kv105 "$(lk105)" setnames)|$( [ "$(kv105 "$(q105 lkdiff "$DCM0_105" "$DCM1_105")" n)" -gt 0 ] 2>/dev/null && echo gone)" "yes|$B105|-|gone" \
  "105-7b4 QF-RB1's clear called again in V1's rollback: the rollback ran and every ladder key is gone by this rehearsal's own reading (caught — the real V1 keeps every switch, 105-6v)"
check "$(drest105 L2)" "same|same|same" "105-7b4' the book back at L2"
mv "$LKF105" "$R105/case/lk-key.kept"
dcopy105 b7nokeep nokeep
check "$(fails "$DC105")$(has "$DC105" "  $V105 (deploy): PASSED.")|$(live)" "0yes|$P105" "105-7b5 a copy that keeps a key other than the one it hashed with (MAP_SALT per run): the deploy itself PASSED"
OUT="$(sf104c set persona-confirmed "$(kday104 $(( $(fnow104) - 365 * 86400 )))")"; check "$(sfrc104)" "0" "105-7b5' one setting changed through the installed tool after it (a past date, as 105-1e's)"
after_back105; fresh105; OUT="$(run105c --after-only)"
AO7_105="$(sed -n "s/^  note  LK-AO the ladder's keys changed since the deploy ([^)]*): \(.*\) — the operator's steps; now: .*/\1/p" <<<"$OUT" | head -1 | tr ' ' '\n' | sed 's/@.*//' | grep . | LC_ALL=C sort -u | paste -sd, -)"
check "$( [ "$AO7_105" != "$(tr ',' '\n' <<<"$SETCSV105" | grep -x 'sf_persona_confirmed' | head -1)" ] && echo caught)|$(noteline "$OUT" LK-AO "read exactly as the deploy left them")" "caught|no" \
  "105-7b5'' …its --after-only cannot name the one key the operator changed (it names ${AO7_105:-none}, or cannot compare) — caught; the real deploy keeps its key (105-5c)"
rbx105 "105-7b5'''"; mv "$R105/case/lk-key.kept" "$LKF105"; check "$(drest105 L2)|$(stat -c %a "$LKF105")" "same|same|same|600" "105-7b5'''' the kept key and the book put back"
# 7c: --redeploy over the live ladder, BR1 counting 5.18.105's files on disk
fresh105; OUT="$(run105c --answer DEPLOY --redeploy)"
check "$(fails "$OUT")$(has "$OUT" "  $V105 (deploy): PASSED.")|$(grep -cE "^  ok    BR1 .* of the [0-9]+ files $V105 adds are already on disk" <<<"$OUT")|$(live)|$(okline "$OUT" LK-CA1 "after the copy every ladder key")" "0yes|1|$P105|yes" \
  "105-7c --redeploy of $V105 over the live ladder (L2), DEPLOY typed: PASSED — BR1 counts $V105's files already on disk; LK-CA1 ok"
# 7d: LM's after-the-copy reading made to read the old form, in an --after-only
M105="$(weak105 lmold lmold)"; after_back105; fresh105; OUT="$(R105_MUTANT_OUT=1 run105c --script "$M105" --after-only)"; rm -f "$M105"
check "$(grep -c "^  FAIL  LM-ON the installed files do not hold $V105's masked lines" <<<"$OUT")" "1" \
  "105-7d LM-ON made to expect $BV105's blocks: FAIL over the installed $V105 (caught)"
rbx105 "105-7e"

echo; echo "== 105-10. what the rehearsal left behind, over 104's records and 105's alike =="
# Evolution: no deploy, check, after-only or rollback run called it — every run summed, none set aside: the cron stand-in
# (cron104_up) runs its worker only while $B105 serves with the acknowledgement off, which no run of 105's reaches, and a run
# is tagged cron only when cron104.runs grew during it (none must be); every request since 105 began on the stand-in's own
# base path (its log records METHOD URI, 104's EVP104), outside every run, no fewer than the drives' own counted — the kinds
# of request are not classified here
EVP105="${EVO_URL:-}"; EVP105="/${EVP105#http://*/}"; EVA105=$(( $(evo_calls) - EVO0_105 ))
EVN105="$(cat "$R105"/evo/run-*.n 2>/dev/null | awk '{s += $1} $2 == "cron" {c++} END {printf "%d|%d\n", s, c}')"
check "$EVN105|$(tail -n +"$(( EVO0_105 + 1 ))" "$SB/evo/requests.log" 2>/dev/null | grep -cvE "^(GET|POST) $EVP105/")|$( [ "$EVA105" -ge "$EVODRV105" ] 2>/dev/null && echo ge)" "0|0|0|ge" \
  "105-10a Evolution: no run of the script called it ($(ls "$R105"/evo/run-*.n 2>/dev/null | wc -l | tr -d ' ') runs read, every one summed); the cron stand-in ran its worker during none of them; the stand-in asked only on its own base path ($EVP105), every request outside every run ($EVA105 since 105 began, $EVODRV105 of them by the drives)"
# …over 105's runs and 104's alike (104-10's own readings, cut with it): a request record counted when u99other names any
# request not a GET (it prints those requests, or -), a token record when tok99 finds a copy left at the next call (the
# observer writes a line at every call, so a record's lines are no finding) — each reading's control a planted record
uo105() { local f n=0; for f in "$@"; do [ -f "$f" ] || continue; [ "$(python3 "$H99/rh99.py" u99other "$f" 2>/dev/null)" = "-" ] || n=$((n+1)); done; echo "$n"; }
tl105() { local f n=0; for f in "$@"; do [ -f "$f" ] || continue; case "$(python3 "$H104/rh104.py" tok99 "$f" 2>/dev/null)" in *" left=0") ;; *) n=$((n+1)) ;; esac; done; echo "$n"; }
UO105="$(uo105 "$R105"/ucrm/run-*.req "$R104"/ucrm/run-*.req)"; TK105="$(tl105 "$R105"/tok/run-*.tok "$R104"/tok/run-*.tok)"
printf 'GET /organizations app-key\nPOST /clients app-key\n' > "$R105/zz-control.req"; printf '0 x\n1 remote\n1 y\n' > "$R105/zz-control.tok"
check "$UO105|$TK105|$(ls -d /tmp/dnb-$V105-* /tmp/dnb-$V104-* /tmp/dnb-report-* 2>/dev/null | wc -l | tr -d ' ')|$(ls "$R104"/ucrm/run-*.req 2>/dev/null | grep -c . | awk '{print ($1 > 0) ? "req104" : "none"}'),$(ls "$R104"/tok/run-*.tok 2>/dev/null | grep -c . | awk '{print ($1 > 0) ? "tok104" : "none"}')|$(uo105 "$R105/zz-control.req")$(tl105 "$R105/zz-control.tok")" "0|0|0|req104,tok104|11" \
  "105-10b uCRM asked GETs only in every run of $V105 and of $V104 (104's sections', 105-pre's and the after-onlys'); no token copy left at the next call by any of them; nothing of either left in /tmp; controls: a planted POST and a planted token copy left, each found"
rm -f "$R105/zz-control.req" "$R105/zz-control.tok"
# the deploy's own terminals, logs, sheets and state files: no seeded name or number — with a positive control; the case
# folders' backup-* (tidy105's, raw copies of the book by design, which 104-10 never read either) left out
SCN105="$R105/scan105"; mkdir -p "$SCN105"; NM105F="$SCN105/names.pat"; grep -v '^[+0-9 ]*$' "$NAMES104" | grep . > "$NM105F"; chmod 600 "$NM105F"
sweep105() { { ls "$R105"/run-*.txt "$R105"/mutants/*.txt "$R105"/sheets/* "$R105"/logs/*.log "$R105"/logs/stream-*.txt "$OUT105"/state-* "$OUT105"/rollback-state-* "$OUT105"/cih-rows-* "$OUT105"/*.log "$OUT105"/cash-*.txt; \
               find "$R105/case" -type f ! -path '*/backup-*/*' 2>/dev/null; } 2>/dev/null | grep -v '/lk-key' | sort -u; }
NH105="$(sweep105 | xargs -r grep -lF -f "$NM105F" 2>/dev/null | wc -l | tr -d ' ')"; FH105="$(sweep105 | xargs -r grep -lF -f "$FORMS105" 2>/dev/null | wc -l | tr -d ' ')"
printf 'RH104SECRET-control %s\n' "$(head -1 "$FORMS105")" > "$SCN105/control.txt"
# the list read (some files, some run terminals among them), and the control planted as a run terminal found through it
SWN105="$(sweep105 | grep -c .)"; SWR105="$(ls "$R105"/run-*.txt 2>/dev/null | grep -c .)"
cp "$SCN105/control.txt" "$R105/run-zz105ctl.txt"
SWC105="$(sweep105 | grep -F zz105ctl | xargs -r grep -lF -f "$NM105F" 2>/dev/null | wc -l | tr -d ' ')$(sweep105 | grep -F zz105ctl | xargs -r grep -lF -f "$FORMS105" 2>/dev/null | wc -l | tr -d ' ')"
rm -f "$R105/run-zz105ctl.txt"
check "$NH105|$FH105|$(grep -lF -f "$NM105F" "$SCN105/control.txt" | wc -l | tr -d ' ')$(grep -lF -f "$FORMS105" "$SCN105/control.txt" | wc -l | tr -d ' ')|$( [ "$SWN105" -gt 0 ] 2>/dev/null && [ "$SWR105" -gt 0 ] 2>/dev/null && echo listed)|$SWC105" "0|0|11|listed|11" \
  "105-10c no seeded name and no fixture number in any of $V105's terminals, logs, sheets or state files ($SWN105 files, $SWR105 run terminals; the case folders' backups left out); the control file holding one of each found by both scans, and again planted as a run terminal through the sweep's own list, then removed"
# …and in 5.18.104's records, by 104-10's own lists: every terminal and log of every run of its script — 104's sections',
# 105-pre's redeploy and the after-onlys among them — sf.txt, its state, rollback-state and row files, the wrappers' logs;
# its sheets (the last ones still in its run folder too); its request records. Each list read, and a control file planted
# where each list looks, found by both scans through that list, then removed. The three lists were cut with 104-10: their
# one-line definitions are read from 5.18.104's rehearsal — each the start of exactly one line, after 104-10's heading,
# ending its body — and sourced here unchanged (a miss recorded for 105-10i; the lists then read nothing, and this fails).
L104F_105="$(mktemp)"
python3 -I - "$H104_HARNESS105" "$L104F_105" <<'PY' || echo "rh105:lists104" >> "$SB/anchor-missing"
import sys
src, dst = sys.argv[1], sys.argv[2]
lines = open(src, encoding='utf-8').read().split('\n')
at = [i for i, l in enumerate(lines) if l == 'echo; echo "== 104-10. what the rehearsal left behind, and what it never reached =="']
assert len(at) == 1, len(at)
out = []
for name in ('sweep_files104', 'sheets104', 'reqs104'):
    k = [i for i, l in enumerate(lines) if l.startswith(name + '() { ')]
    assert len(k) == 1 and k[0] > at[0], (name, k)
    l = lines[k[0]]
    assert l.endswith('; }') and l.count('() {') == 1 and '$R104' in l, name
    out.append(l)
open(dst, 'w', encoding='utf-8').write('\n'.join(out) + '\n')
PY
# shellcheck disable=SC1090
. "$L104F_105"; rm -f "$L104F_105"
sweep104_105() { { sweep_files104; sheets104; ls -1 "$OUT104"/cash-*.txt 2>/dev/null; reqs104; } | sort -u; }
NH104_105="$(sweep104_105 | xargs -r grep -lF -f "$NM105F" 2>/dev/null | wc -l | tr -d ' ')"; FH104_105="$(sweep104_105 | xargs -r grep -lF -f "$FORMS105" 2>/dev/null | wc -l | tr -d ' ')"
NL104_105="$(for l in sweep_files104 sheets104 reqs104; do "$l" | grep -c . | awk '{printf "%s", ($1 > 0) ? "y" : "n"}'; done)"
mkdir -p "$R104/sheets" "$R104/ucrm"
for f in "$R104/run-zz105ctl.txt" "$R104/sheets/cash-zz105ctl.txt" "$R104/ucrm/run-zz105ctl.req"; do cp "$SCN105/control.txt" "$f"; done
CL104_105="$(for l in sweep_files104 sheets104 reqs104; do printf '%s%s ' "$("$l" | grep -F 'zz105ctl' | xargs -r grep -lF -f "$NM105F" 2>/dev/null | wc -l | tr -d ' ')" "$("$l" | grep -F 'zz105ctl' | xargs -r grep -lF -f "$FORMS105" 2>/dev/null | wc -l | tr -d ' ')"; done | sed 's/ $//')"
rm -f "$R104/run-zz105ctl.txt" "$R104/sheets/cash-zz105ctl.txt" "$R104/ucrm/run-zz105ctl.req"
check "$NH104_105|$FH104_105|$NL104_105|$CL104_105" "0|0|yyy|11 11 11" \
  "105-10c' …nor in any of $V104's: its runs' terminals and logs (104's sections', 105-pre's, the after-onlys'), sf.txt, its state, rollback-state and row files, the wrappers' logs, its sheets and its request records ($(sweep104_105 | grep -c .) files, by 104-10's own lists, each list read); the control file planted where each list looks found by both scans through it"
# the LK key file: 0600, its value nowhere
SK105="$(q105 secret "$LKF105" "$SCN105/key.pat")"
KH105="$( { sweep105; ls "$R104"/run-*.txt "$R104"/sf.txt 2>/dev/null; } | xargs -r grep -lF -f "$SCN105/key.pat" 2>/dev/null | wc -l | tr -d ' ')"
cp "$SCN105/key.pat" "$SCN105/key-control.txt"
check "$SK105|$KH105|$(grep -lF -f "$SCN105/key.pat" "$SCN105/key-control.txt" | wc -l | tr -d ' ')" "mode=600 key=read|0|1" \
  "105-10d the LK key file mode 0600; its value in no terminal, log, sheet or state file; the control (a copy of it) found"
rm -f "$SCN105/key.pat" "$SCN105/key-control.txt"
# N105 and NM105 (root docs/74 §H.6.5): the plugin's logs and sinks, every record stamped at or after 5.18.105's first copy,
# chosen by its own stamp, compared with the copy's moment on the clock that wrote it (COPYU105, 105-4a: the rehearsal's
# clock, as 105-10h's line too); the drives' own logs and the master cycles' captured output whole. $PD/data/plugin.log is
# PluginLog's (PluginLog::path()), whose writer fixes the zone — the plugin's, ' UTC' said when it falls back — so it is read
# as kampala! (zone certain): its naive lines in the three hours before the copy (the tool's on/skip/set lines of pre L3, pre
# L4 and 105-1e, on the full walk) are counted fixed=, never ambig= (105-10e')
printf 'ln02\t[staff_ssp_report] Sent to \n' > "$SCN105/excl.tsv"
printf 'ln01\t%s\nln02\t\\[staff_ssp_report\\] Sent to \nln03\t\\[QuotationService\\] WA send failed \\(\nln04\t\\[WaAutoReply\\] CRM Job #[0-9]+ created and assigned to Bidal\n' "$LN01RX105" > "$SCN105/ctrl.tsv"
SP105=()
for d in "$DATA" "$PD/data"; do
  SP105+=("$d/webhook_log.json:whlog:kampala:$(basename "$d")-webhook_log" "$d/quote_wa_log.json:qwa:kampala:$(basename "$d")-quote_wa_log" \
          "$d/activity_log.json:activity:kampala:$(basename "$d")-activity_log" "$d/evo_sync.log:lines:kampala:$(basename "$d")-evo_sync" \
          "$d/invoice_notify_cron.log:lines:kampala:$(basename "$d")-invoice_notify" "$d/plugin.log:lines:$( [ "$d" = "$PD/data" ] && echo 'kampala!' || echo kampala ):$(basename "$d")-plugin_log" \
          "$d/overdue_cron_status.json:overdue:kampala:$(basename "$d")-overdue")
done
SP105+=("$DATA/plugin.sqlite3:activitydb:kampala:activity_db" "$DRV105/wh105-ln01.log:server:utc:ln01" "$DRV105/master-ln02.out:whole:utc:ln02" \
        "$DRV105/ln03.log:phplog:kampala:ln03" "$DRV105/ln04.log:phplog:kampala:ln04" "$R104/logs/master-105g4m.out:whole:utc:master-105g4m")
N105="$(q105 logs "$(n103 "$COPYU105")" "$FORMS105" "$NM105F" "$SCN105/excl.tsv" "$SCN105/ctrl.tsv" after "${SP105[@]}")"
printf '%s\n' "$N105" > "$SCN105/n105.txt"; NT105="$(grep '^total ' <<<"$N105")"
check "$(epoch99 "$COPYU105")|$COPYC105|$( [ "$(kv105 "$NT105" sel)" -gt 0 ] 2>/dev/null && echo records)|$(kv105 "$NT105" num)|$(kv105 "$NT105" long)|$(kv105 "$NT105" ambig)" "epoch|base1|inrun|records|0|0|0" \
  "105-10e N105: every record of the plugin's logs and sinks stamped at or after $V105's first copy on the rehearsal's clock (QF-G1's copy's start, as the moments hook read it too, between the clock before and after 105-4a) ($(kv105 "$NT105" sel) records, by their own stamps, no exclusion): no fixture number in any written form, no run of nine digits or more, no record whose zone would choose it otherwise"
# 105-10e's zone control: a tool line as PluginLog writes it (the plugin's zone, no suffix), stamped an hour before the copy
printf '[%s] [sales_followup] zz105ctl on by cli\n' "$(TZ=Africa/Kampala date -d "@$(( $(n103 "$COPYU105") - 3600 ))" '+%Y-%m-%d %H:%M:%S')" > "$SCN105/zone-ctl.log"
ZC105="$(for z in kampala 'kampala!'; do t="$(q105 logs "$(n103 "$COPYU105")" "$FORMS105" "$NM105F" "$SCN105/excl.tsv" "$SCN105/ctrl.tsv" after "$SCN105/zone-ctl.log:lines:$z:zone-ctl" | grep '^total ')"; printf '%s/%s/%s ' "$(kv105 "$t" sel)" "$(kv105 "$t" ambig)" "$(kv105 "$t" fixed)"; done | sed 's/ $//')"
rm -f "$SCN105/zone-ctl.log"
check "$ZC105" "0/1/0 0/0/1" \
  "105-10e' N105's zone control: a pre-copy tool line stamped an hour before the copy in the plugin's zone, unsuffixed as PluginLog writes it — ambiguous where its zone is only assumed (kampala), counted fixed and never ambiguous in its writer's zone (kampala!, as $PD/data/plugin.log is read: $(kv105 "$NT105" fixed) of its records here), selected by neither (sel/ambig/fixed)"
CT105="$(kv105 "$NT105" ctrl)"
check "$(for l in ln01 ln02 ln03 ln04; do tr ',' '\n' <<<"$CT105" | grep -qE "^$l:[1-9]" && printf '%s ' "$l"; done | sed 's/ $//')" "ln01 ln02 ln03 ln04" \
  "105-10f N105's positive controls: each masked line the rehearsal drove is present in the records scanned — LN-01, LN-02, LN-03, LN-04 ($CT105)"
check "$(kv105 "$NT105" names)|$(tr ',' '\n' <<<"$(kv105 "$NT105" excl)" | grep -cE '^ln02:[1-9]')" "0|1" \
  "105-10g NM105: no seeded name in those records, minus the line the ruling keeps a name in (LN-02's, left out by its fixed words and counted: $(kv105 "$NT105" excl))"
# 5.18.104's logs written earlier in the chain: its old opt-out lines excluded, for the records stamped before the copy only
printf '%s\n' "$OPTOUT104" > "$SCN105/optout104.re"
SP104L=(); for f in "$R104/offers-webhook.log" "$R104/webhook-server.log" "$R105/pre/webhook-104.log"; do [ -f "$f" ] && SP104L+=("$f:server:utc:$(basename "$f")"); done
O104_105="$(q105 logs "$(n103 "$COPYU105")" "$FORMS105" "$NM105F" "$SCN105/excl.tsv" "$SCN105/ctrl.tsv" all --optout-before "$SCN105/optout104.re" ${SP104L[@]+"${SP104L[@]}"} | grep '^total ')"
check "$(kv105 "$O104_105" names)|$( [ "$(kv105 "$O104_105" optout)" -ge "$( [ -n "${REHEARSE_SHORTCUT105:-}" ] && echo 1 || echo 2)" ] 2>/dev/null && echo counted)" "0|counted" \
  "105-10h 5.18.104's logs from earlier in the chain: no seeded name outside its old opt-out lines, which are left out only for the records stamped before the copy and counted ($(kv105 "$O104_105" optout): 5o11's and pre-e's)"
AM1_105="$(grep -c . "$SB/anchor-missing" 2>/dev/null)"; WF1_105="$(grep -c . "$R104/wait104.fail" 2>/dev/null)"; BR105="$(grep -c '^run105-' "$SB/bad-runs" 2>/dev/null)"
check "$(( ${AM1_105:-0} - AM0_105 ))|${BR105:-0}|$(( ${WF1_105:-0} - WF0_105 ))|$(tail -n +$(( AM0_105 + 1 )) "$SB/anchor-missing" 2>/dev/null | paste -sd, -)" "0|0|0|" \
  "105-10i every asserted edit and anchor of the chain found, no run given an unknown option, no bounded wait timed out"
check "$(live)|$(installed105 "$B105F")|$(git -C "$R" status --porcelain 2>/dev/null | sha256sum | cut -c1-16)" "$B105|0|$ST0_105" \
  "105-10j the end state: $B105 serving after the last rollback, every file $BV105's; this checkout's status exactly as the rehearsal found it"
rm -rf "${SCN105:?}/excl.tsv" "$SCN105/ctrl.tsv"

echo
echo "RESULT: $PASS passed, $FAILN failed — of them $FAIL_PRE105 before $V105's own sections (the earlier rehearsals' chain)"
[ "$FAILN" = 0 ]
