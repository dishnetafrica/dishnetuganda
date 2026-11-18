#!/usr/bin/env bash
#
# Rehearse scripts/deploy-5.18.106.sh — on Uganda the enquiry follow-up sender unjammed (root docs/75): F1–F5 in five runtime
# files (lib/FollowUpService.php and the four follow-up jobs), deployed over production's ladder (steps 1–5 on, step 6 off)
# with followup_enabled on. Its new gates: the three claim switches off (CS-G1 at stage A, CS-B1 once DEPLOY is typed) and
# followup_enabled on (FE-G1); its readings before the deploy (U1–U5, U4's capitals note), after the copy (UJ-CA, ME-CA1) and
# in --after-only (UJ-ON: PASS, NOT YET with exit status 3, or FAIL; its three gate notes and the summary's "check it"); the
# rollback's one write (AS-RB0–AS-RB2: followup_auto_send set off before the put-back, or the owner's own command where it
# cannot be) and the offer to turn it back on after a later deploy. The script under rehearsal is derived from 5.18.105's by
# asserted edits (wip/derive-5.18.106/), pinned to the release commit; every pin is read from it here and every expectation
# computed from Git or from the release's own code — never a commit, a count of files or a hash written.
#
# The sandbox is the previous release's rehearsal's, built by its own code: scripts/harness/deploy-5.18.105/rehearse.sh, read
# up to just before 105-10 (its sweeps) — the cut asserted — and run here as it is, which builds every earlier sandbox the same
# way (5.18.104's rehearsal cut before 104-10, and so down to the quarantine's, the root of the chain). When this file's own
# sections begin, 5.18.105's 105-7e has ended with a rollback and the quarantine's --check: 994d660 (5.18.104) serving, the
# ladder at L2 (steps 1–6 on, offers off), 5.18.105's files on disk, every followup_* setting unset. 106-pre makes
# production's state through the real writers: 5.18.105 deployed again by the real deploy-5.18.105.sh, step 6 off through
# the real tool, production's followup_* settings through the real set_config; then the jam (J1, approved drafts Evolution
# refuses with 400 while the number delivers others; J2, stale follow-ups, the 400 oldest of them gone conversations; J3, one
# follow-up holding two approved drafts) and the owner's interim of 18 Nov (followup_enabled off, step 6 on).
#
# What it proves is root docs/75 §L.10, section by section, as the rehearsal's design (18 Nov, v2) refines it: the guards
# (106-0); the cut (106-cut); 106-infra (the runners, the numbers, four asserted edits of the stand-ins, each with its
# control); 106-pre; 106-1 (the owner's order before the deploy, through the script's own refusals — CS-G1, FE-G1, U4's
# capitals note, CS-B1 — each with its weakened copy caught); 106-4 (the deploy PASSED, U1–U5 equal to this rehearsal's own
# reading); 106-5 (--after-only: NOT YET then PASS, ME-CA1, UJ_A or COPY_AT absent, the reading failing, UJ-ON's limit-only
# and 'now' notes; and on copies of their own the deterministic FAIL, the 'now' run before its pass, nothing stale, F3
# removed from the installed close job, the stage-A-only note); 106-6 (the typed rollback's cases); 106-7 (redeploys and
# the turn-back-on offer, V1 with its put-back made to fail); 106-10 (the sweeps). Refined, each said in root docs/75: 400
# gone rows, not 200 (UJ-ON's NOT YET needs both of the close job's 200-row lists spent on gone rows — the state a prune
# leaves; wa_prune_history.php is never run here); an undecodable kyc_config.json gives cause 1's capitals notice only with
# followup_auto_send read on from a config.json (a fixture); K5's eight weakened copies are the derivation's check, not the
# script's; one known limit pinned for the owner's decision (a V1 that read the key off and put back successfully, then a
# --redeploy, offers nothing — §L.8's base rule). Left out, each needing fault injection beyond the uj hook: U4's
# StaffJobsGate-false branch; AS-RB1's written-check, refused and unknown branches.
#
# Two sandbox mechanics every section leans on. The copy's time: stamp_copy's `touch -c` takes the kernel's time, which no
# LD_PRELOAD moves, while every row and schedule entry UJ-ON and ME-CA1 compare COPY_AT with is stamped by the rehearsal's
# clock — so for 5.18.106's own runs a touch in the date folder gives that touch the clock's time (A1), COPY_AT and
# RB_COPY_AT alike, and no state line is ever rewritten. Master: only master106 and sender106 run it; once a 5.18.106 copy
# is installed followup_close is DUE in every cycle, a real pass, never planted (UJ-ON's control is followup_close's own
# record), and nothing writes the whole schedule.
#
# The development runner: the block that sources the chain — from the one line that begins H106_PREFIX="$(mktemp)" to the
# one line that is exactly rm -f "$H106_PREFIX" — can be skipped by a runner that restores the chain's shell from a snapshot
# (devsnap106, taken over 5.18.105's rehearsal cut the same way); everything after it works in such a shell and copies the
# committed script and both wrappers into the restored clone itself. There is no development shortcut for 106's own sections.
#
# Everything runs in a sandbox; it never touches this checkout, the server or the network, and refuses to run where the
# server could be. Everything seeded is fictitious: every name and text an RH106SECRET- value (the chain's RH104SECRET- ones
# beside), every phone a number of the rehearsal's own ranges — 106's own, +2567001060NN, proved here to be in no harness,
# test or fixture, beside the chain's +2567001040NN — listed in the 0600 pattern files for the sweeps.
#
#   bash scripts/harness/deploy-5.18.106/rehearse.sh            REHEARSE_KEEP=<dir> keeps every run's full output
set -u
# ── 106-0 the guards ──────────────────────────────────────────────────────────────────────────────────────────────────────
for f in /data/ucrm /opt/dishnet /var/run/docker.sock /run/docker.sock; do
  [ -e "$f" ] && { echo "refusing: $f exists — this looks like the server, and this rehearsal must never run there"; exit 2; }
done
H106_DIR="$(cd "$(dirname "$0")" && pwd)"
H106_ROOT="$(cd "$H106_DIR/../../.." && pwd)"
case "$H106_DIR/:$(pwd -P)/" in */data/ucrm/*|*/opt/dishnet/*|*/root/dnb-*) echo "refusing: a server path ($H106_DIR, $(pwd -P))"; exit 2 ;; esac
F106=scripts/deploy-5.18.106.sh; F105_106=scripts/deploy-5.18.105.sh
[ -f "$H106_ROOT/$F106" ] || { echo "refusing: the checkout has no $F106"; exit 2; }
sv106e() { sed -nE "s/^$2=\"([^\"]*)\".*/\1/p" "$1" 2>/dev/null | head -1; }   # sv106e FILE NAME — a quoted value the script states at a line's start
P106_EARLY="$(sv106e "$H106_ROOT/$F106" EXPECTED_PLUGIN_COMMIT)"; P106F_EARLY="$(sv106e "$H106_ROOT/$F106" EXPECTED_PLUGIN_COMMIT_FULL)"
T1_EARLY106="$(sv106e "$H106_ROOT/$F106" T1_SIGNATURE)"
case "$P106_EARLY" in __*|'') echo "refusing: $F106 is not pinned to a release commit (${P106_EARLY:-none})"; exit 2;; esac
case "$P106F_EARLY" in "$P106_EARLY"*) [ "${#P106F_EARLY}" = "40" ] || { echo "refusing: $F106 does not pin its release commit in full"; exit 2; } ;;
  *) echo "refusing: $F106's full pin does not begin with its short one"; exit 2 ;; esac
case "$T1_EARLY106" in [1-9]*' passed, 0 failed rc=0') ;; *) echo "refusing: $F106 does not pin T1's count (T1_SIGNATURE=${T1_EARLY106:-none})"; exit 2;; esac
# The two helpers beside this file (rh106q.py, its readers; rh106-seed.php, its plants through the release's own classes),
# each parsing; no bytecode cache beside them (a stale one could stand in for the reader read here).
for f106 in rh106q.py rh106-seed.php; do [ -f "$H106_DIR/$f106" ] || { echo "refusing: this rehearsal's helper $f106 is not beside it"; exit 2; }; done
python3 -I -c 'import ast, sys; ast.parse(open(sys.argv[1]).read())' "$H106_DIR/rh106q.py" 2>/dev/null || { echo "refusing: rh106q.py does not parse"; exit 2; }
php -l "$H106_DIR/rh106-seed.php" >/dev/null 2>&1 || { echo "refusing: rh106-seed.php does not parse"; exit 2; }
[ ! -e "$H106_DIR/__pycache__" ] || { echo "refusing: a __pycache__ beside the helpers — remove it"; exit 2; }
# The static guard: the lk mode's one line, and no switch-tool verb invoked outside printed text — read by this rehearsal's own
# reader (rh106q.py verbs, 5.18.105's contract: lk=<n> qftool=<n> wrapper=<n> verbs=<n>), and by 5.18.105's reader alike; its
# control a planted copy of each kind (a qf_tool verb, the wrapper, the tool by path), each of which must be refused. 5.18.106
# adds no verb of its own: it prints the off commands and the owner's set_config commands, and runs none of them.
VB106_EARLY="$(python3 -I "$H106_DIR/rh106q.py" verbs "$H106_ROOT/$F106" 2>/dev/null)"
[ "$VB106_EARLY" = "lk=1 qftool=0 wrapper=0 verbs=0" ] || { echo "refusing: $F106's static guard reads $VB106_EARLY (want lk=1 qftool=0 wrapper=0 verbs=0)"; exit 2; }
[ -f "$H106_DIR/../deploy-5.18.105/rh105q.py" ] || { echo "refusing: 5.18.105's rehearsal's reader is not beside this one"; exit 2; }
VBX106_EARLY="$(python3 -I "$H106_DIR/../deploy-5.18.105/rh105q.py" verbs "$H106_ROOT/$F106" 2>/dev/null)"
[ "$VBX106_EARLY" = "$VB106_EARLY" ] || { echo "refusing: 5.18.105's reader reads $F106's static guard as $VBX106_EARLY, this one's as $VB106_EARLY"; exit 2; }
VBD106="$(mktemp -d)"
VBC106="$(python3 -I - "$H106_ROOT/$F106" "$VBD106" "$H106_DIR/rh106q.py" <<'PY'
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
)"; rm -rf "${VBD106:?}"
[ "$VBC106" = "qftool:refused,wrapper:refused,verbs:refused" ] || { echo "refusing: the static guard's control did not hold ($VBC106)"; exit 2; }
# 5.18.105's script present and pinned to the commit 5.18.106 names as its baseline, its version the baseline's (each read, never written)
[ -f "$H106_ROOT/$F105_106" ] || { echo "refusing: the checkout has no $F105_106"; exit 2; }
B106_EARLY="$(sv106e "$H106_ROOT/$F106" BASELINE_COMMIT)"; B106F_EARLY="$(sv106e "$H106_ROOT/$F106" BASELINE_COMMIT_FULL)"
[ -n "$B106_EARLY" ] && [ "$(sv106e "$H106_ROOT/$F105_106" EXPECTED_PLUGIN_COMMIT)" = "$B106_EARLY" ] && [ "$(sv106e "$H106_ROOT/$F105_106" EXPECTED_PLUGIN_COMMIT_FULL)" = "$B106F_EARLY" ] \
  && [ "$(sv106e "$H106_ROOT/$F106" BASELINE_VERSION)" = "$(sv106e "$H106_ROOT/$F105_106" EXPECTED_VERSION)" ] \
  || { echo "refusing: $F105_106 is not pinned to the baseline $F106 names (${B106_EARLY:-none})"; exit 2; }
# Both wrappers the committed ones: tracked, and the working copy HEAD's.
for f106 in scripts/sales-followup.sh scripts/sales-followup-report.sh; do
  git -C "$H106_ROOT" ls-files --error-unmatch "$f106" >/dev/null 2>&1 || { echo "refusing: $f106 is not committed — commit it, then rehearse"; exit 2; }
  git -C "$H106_ROOT" diff --quiet HEAD -- "$f106" || { echo "refusing: $f106 differs from the committed one — commit it, then rehearse"; exit 2; }
done

# ── 106-cut: 5.18.105's rehearsal, up to just before 105-10 ──────────────────────────────────────────────────────────────
H105_HARNESS106="$H106_DIR/../deploy-5.18.105/rehearse.sh"
[ -f "$H105_HARNESS106" ] || { echo "refusing: 5.18.105's rehearsal is not beside this one"; exit 2; }
H106_PREFIX="$(mktemp)"
python3 -I - "$H105_HARNESS106" "$H106_PREFIX" <<'PY' || { echo "refusing: the previous release's rehearsal could not be cut before its last section (its sweeps)"; rm -f "$H106_PREFIX"; exit 2; }
import sys
src, dst = sys.argv[1], sys.argv[2]
anchor = 'echo; echo "== 105-10. what the rehearsal left behind, over 104\'s records and 105\'s alike =="'
lines = open(src, encoding='utf-8').read().split('\n')
at = [i for i, l in enumerate(lines) if l == anchor]
assert len(at) == 1, len(at)
assert at[0] > 900, at[0]
# the last section heading before the anchor is 105-7's; its last non-empty line is exactly `rbx105 "105-7e"`, once in the cut
# (105-7 has no `fi   # LIVE…` line, so 5.18.104's assertion of that line is not carried)
heads = [i for i in range(at[0]) if lines[i].startswith('echo; echo "== 105-')]
assert heads and lines[heads[-1]] == 'echo; echo "== 105-7. the weakened copies, each caught by the check it weakens; the redeploy =="', (lines[heads[-1]][:60] if heads else 'none')
body = lines[heads[-1]:at[0]]
nonempty = [l for l in body if l.strip()]
assert nonempty[-1] == 'rbx105 "105-7e"', nonempty[-1][:60]
assert sum(1 for l in lines[:at[0]] if l == 'rbx105 "105-7e"') == 1
# the last check line inside the block is 105-7d's, and its label occurs once in the cut
ck = [i for i, l in enumerate(body) if l.lstrip().startswith('check ')]
assert ck, 'no check'
win = '\n'.join(body[ck[-1]:ck[-1] + 3])
cut = '\n'.join(lines[:at[0]])
assert win.count('  "105-7d LM-ON made to expect ') == 1 and cut.count('  "105-7d LM-ON made to expect ') == 1, win[:120]
pre = cut + '\n'
# The one edit: 5.18.105's rehearsal finds its helpers beside itself ($0's folder); sourced from here, $0 is this file, so its
# one line naming that folder points at its own folder instead — asserted: the anchor once, the line after it H105_ROOT's,
# in its guards (measured: 36 lines before it, so the bound 5.18.105 set for 5.18.104's, more than 60, would not hold; at least
# 30, and before the line naming 5.18.104's rehearsal, its cut), replaced once, the new line once and the old line nowhere.
old_dir = 'H105_DIR="$(cd "$(dirname "$0")" && pwd)"\n'
new_dir = 'H105_DIR="$(cd "$(dirname "$0")/../deploy-5.18.105" && pwd)"\n'
assert pre.count(old_dir) == 1, pre.count(old_dir)
i_old = pre.index(old_dir)
assert pre[i_old + len(old_dir):].startswith('H105_ROOT='), 'H105_ROOT does not follow'
assert pre[:i_old].count('\n') >= 30, pre[:i_old].count('\n')
assert pre.count('\nH104_HARNESS105=') == 1 and i_old < pre.index('\nH104_HARNESS105='), 'not in the guards'
pre2 = pre.replace(old_dir, new_dir, 1)
assert pre2.count(new_dir) == 1 and pre2.count(old_dir) == 0 and len(pre2) == len(pre) + len(new_dir) - len(old_dir)
for need in ('H104_HARNESS105=', 'run105() {', 'run105c() {', 'run_line105c() {', 'fresh105() {', 'tidy105() {', 'installed105() {',
             'left105() {', 'nogo105() {', 'checkgo105() {', 'weak105() {', 'anc105() {', 'blk105() {', 'aftx104() {', 'aft105() {',
             'rbx105() {', 'expect105() {', 'onall105() {', 'on105() {', 'lk105() {', 'lkmap105() {', 'counts105() {', 'digest105() {',
             'dsnap105() {', 'drest105() {', 'q105() {', 'kv105() {', 'ref105() {', 'jf105() {', 'copy_back105() {', 'after_back105() {',
             'last105() {', 'jset105() {', 'scratch105() {', 'shl105() {', 'DEPLOY105=', 'OUT105="$SB/out105"; mkdir -p "$OUT105"; STATE105=',
             'R105=', 'H105='):
    assert ('\n' + pre2).count('\n' + need) == 1, need   # each the start of exactly one line
open(dst, 'w', encoding='utf-8').write(pre2)
PY
# shellcheck disable=SC1090
. "$H106_PREFIX"
rm -f "$H106_PREFIX"
for f106 in run104 run_line104 fresh104 installed104 left104 nogo104 checkgo104 weak104 live104 sf104 tool104 clk_set104 clk_to104 \
            dsnap104 drest104 kv104 q104 aft104 onall104 expect104 master104 idle104 rbx104 dk104 \
            check has okline noteline failline fails printed live inst_ver data94 kept_state evo_calls vt_host qj n103 epoch99 \
            set_sched base_log sqx free_port qrun spent99 v1read102 pubget sf104c sfrc104 run104c qrun104c fnow104 \
            kat104 kday104 kdow104 kday_next104 pinroot104 treesum104 pause104 actor104 actwait104 golet104 wfile104 tl_arm104 tl_disarm104 \
            tlu104 sched104 ledger104 owner104 cron104_up cron104_down onst104 wason104 sv104 LOGW104 \
            run105 run105c run_line105c fresh105 tidy105 installed105 left105 nogo105 checkgo105 weak105 anc105 blk105 aftx104 aft105 \
            rbx105 expect105 onall105 on105 lk105 lkmap105 counts105 digest105 dsnap105 drest105 q105 kv105 ref105 jf105 \
            copy_back105 after_back105 last105 jset105 scratch105 shl105 sv105 went105 quiet105 backups105 states105 keyf105 LOGW105; do
  declare -F "$f106" >/dev/null || { echo "refusing: the rehearsals this one runs did not define $f106()"; exit 2; }
done
for v106 in SB REPO R P PD DATA VAULT RECS PLUGIN_BASE WEB_OK AFTER94 SCAN96 U99 H99 PHPBIN IN_CONTAINER DATE_TS98 EVO_URL \
            H103_HARNESS REL104 DEPLOY104 OUT104 STATE104 R104 H104 P103 P104 P104F V104 V103 NA104 ALLCSV104 NAMES104 \
            SALES104 REPORT104 CLKPRE104 CLKF104 ACT104 PINT104 PDD104_IN CM104 RC104 ONP104 LIVE104 OPTOUT104 \
            H104_HARNESS105 DEPLOY105 OUT105 STATE105 LKF105 R105 H105 P105 P105F V105 B105 B105F BV105 REL105 \
            ALLCSV105 SETCSV105 STEPMAP105 VDR105 CM105 ONP105 L1ON105 L2ON105 FORMS105 OWN105 ADDED105 AN105 PQ105 EVO0_105; do
  [ -n "${!v106:-}" ] || { echo "refusing: the rehearsals this one runs did not set $v106"; exit 2; }
done
[ "$LIVE104" = yes ] || { echo "refusing: 5.18.104's rehearsal ran without its live cases (LIVE104=$LIVE104) — 5.18.106's jam needs its stand-in Evolution's sends"; exit 2; }
FAIL_PRE106=$FAILN   # the earlier rehearsals' failures, before this release's own sections: the last line names them

# ══════════════════════════════════════════════════════════════════════════════════════
# 5.18.106's own rehearsal, on the sandbox built above.
# ══════════════════════════════════════════════════════════════════════════════════════
echo; echo "== 106-infra. 5.18.106's own: the script in the clone, its folders and runners, its own numbers, the stand-ins taught four things =="
DEPLOY106="$REPO/$F106"
AM0_106="$(grep -c . "$SB/anchor-missing" 2>/dev/null)"; AM0_106="${AM0_106:-0}"; WF0_106="$(grep -c . "$R104/wait104.fail" 2>/dev/null)"; WF0_106="${WF0_106:-0}"
BR0_106="$(grep -c '^run106-' "$SB/bad-runs" 2>/dev/null)"; BR0_106="${BR0_106:-0}"
ST0_106="$(git -C "$R" status --porcelain 2>/dev/null | sha256sum | cut -c1-16)"   # this checkout as the rehearsal found it
# The script, both wrappers and 5.18.105's script into the clone as committed (a development runner's restored clone included).
if git -C "$R" ls-files --error-unmatch "$F106" >/dev/null 2>&1; then
  git -C "$R" diff --quiet HEAD -- "$F106" || { echo "refusing: $F106 differs from the committed one — commit it, then rehearse"; exit 2; }
  cp "$R/$F106" "$DEPLOY106"; WHICH106="the committed script"
else cp "$R/$F106" "$DEPLOY106"; WHICH106="the working copy (not yet committed; untracked in the clone)"; fi
for f106 in scripts/sales-followup.sh scripts/sales-followup-report.sh; do cp "$R/$f106" "$REPO/$f106"; done
cp "$R/$F105_106" "$REPO/$F105_106"
sv106() { sed -nE "s/^$2=\"([^\"]*)\".*/\1/p; t; s/^$2=([^ \";]+).*/\1/p" "${1:-$DEPLOY106}" | head -1; }   # sv106 FILE NAME — a value the script states
P106="$(sv106 "" EXPECTED_PLUGIN_COMMIT)"; P106F="$(sv106 "" EXPECTED_PLUGIN_COMMIT_FULL)"; V106="$(sv106 "" EXPECTED_VERSION)"
B106="$(sv106 "" BASELINE_COMMIT)"; B106F="$(sv106 "" BASELINE_COMMIT_FULL)"; BV106="$(sv106 "" BASELINE_VERSION)"
REL106="$(sv106 "" RELEASE_BRANCH)"
git -C "$REPO" fetch -q origin "refs/heads/$REL106:refs/remotes/origin/$REL106" 2>/dev/null || true
if ! git -C "$REPO" cat-file -e "$P106F^{commit}" 2>/dev/null; then
  # the release branch not yet cut: the checkout's own branch that holds the pin (read from Git, never named here)
  for b106 in $(git -C "$R" for-each-ref --contains "$P106F" --format='%(refname:short)' refs/heads 2>/dev/null | head -3); do
    git -C "$REPO" fetch -q origin "refs/heads/$b106:refs/remotes/origin/$b106" 2>/dev/null && break
  done
fi
git -C "$REPO" cat-file -e "$P106F^{commit}" 2>/dev/null || { echo "refusing: the clone does not hold $V106's pin $P106F"; exit 2; }
check "$P106|$( [ "$(git -C "$REPO" rev-parse "$P106^{commit}")" = "$P106F" ] && echo full)|$B106|$( [ "$B106F" = "$P105F" ] && echo base105)|$BV106|$(git -C "$REPO" merge-base --is-ancestor "$B106F" "$P106F" && echo on105)" \
      "${P106F:0:${#P106}}|full|${P105F:0:${#B106}}|base105|$V105|on105" \
  "106-infra a $WHICH106 pinned to $P106 (its full form the clone's), cut on $B106 — 5.18.105's own pin, read from both scripts — $V106 after $BV106"
OUT106="$SB/out106"; mkdir -p "$OUT106"; STATE106="$OUT106/state-$V106.env"; LKF106="$OUT106/lk-key-$V106"
R106="$SB/r106"; mkdir -p "$R106/logs" "$R106/mutants" "$R106/sheets" "$R106/exec" "$R106/scan" "$R106/vault" "$R106/tok" "$R106/ucrm" "$R106/evo" "$R106/case" "$R106/rc" "$R106/kept" "$R106/pre"
H106="$SB/rh106"; mkdir -p "$H106/v" "$H106/ref"; cp "$H106_DIR/rh106q.py" "$H106_DIR/rh106-seed.php" "$H106/"; chmod 755 "$H106"; chmod 644 "$H106"/rh106*
chown 1000:1000 "$H106/v"; chmod 700 "$H106/v"
LOGW106() { printf '2>&1 | ( umask 077; exec tee %s/%s-%s.log )' "$OUT106" "$1" "$DATE_TS98"; }   # the script's LOGW, as it prints it
EXP_AFTER106="cd $REPO && bash $F106 --after-only $(LOGW106 after)"
EXP_ROLLBACK106="cd $REPO && bash $F106 --rollback $(LOGW106 rollback)"
EXP_REDEPLOY106="cd $REPO && bash $F106 --redeploy $(LOGW106 redeploy)"
q106() { python3 -I "$H106/rh106q.py" "$@"; }        # this rehearsal's own readers (rh106q.py)
kv106() { q105 kv "$1" "$2"; }                        # one key=value field of a line, by the chain's reader
# The pin's ladder, read by the RELEASE's own code from Git (a copy in this rehearsal's own folder), set against 5.18.105's:
# 5.18.106 changes neither the ladder nor the switch tool, so 5.18.105's reader of the four places (lk105, digest105) reads it.
git -C "$REPO" archive "$P106F" "$P/lib" "$P/tools" | tar -xf - -C "$H106/ref"; chown -R 1000:1000 "$H106/ref"
LAD106="$(ref105 ladder "$H106/ref/$P")"
check "$(jf105 "$LAD106" all)|$(jf105 "$LAD106" settings)|$(jf105 "$LAD106" stepmap)|$(jf105 "$LAD106" vdr)|$(git -C "$REPO" diff --quiet "$B106F" "$P106F" -- "$P/lib/SalesFollowupLadder.php" "$P/tools/sales_followup.php" && echo same)" \
      "$ALLCSV105|$SETCSV105|$STEPMAP105|$VDR105|same" \
  "106-infra b the pin's ladder read by its own code from Git — allKeys() ($(tr ',' '\n' <<<"$ALLCSV105" | grep -c .) keys), settingKeys(), the steps in order, what skip voice-dry-run records — 5.18.105's exactly, its two files unchanged in Git: this rehearsal's reading of the four places (lk105) reads $V106's ladder"
CM106="$(sv106 "" COPY_MARK)"
ADDED106="$(sed -n 's/^EXPECTED_ADDED="\(.*\)"$/\1/p' "$DEPLOY106")"; NA106=$(wc -w <<<"$ADDED106")
left106() { local f n=0; for f in $ADDED106; do [ -e "$PD/$f" ] && n=$((n+1)); done; echo "$n"; }   # 5.18.106's added files on disk
installed106() {   # installed106 COMMIT — the files $BV106 → $V106 touches that are NOT installed as COMMIT has them (one COMMIT lacks: not asked)
  local f rel bad=0
  for f in $(git -C "$REPO" diff --name-only --diff-filter=AM "$B106F" "$P106F" -- "$P" | grep -v "^$P/tests/"); do
    rel="${f#$P/}"
    if git -C "$REPO" cat-file -e "$1:$f" 2>/dev/null; then
      [ "$(git -C "$REPO" show "$1:$f" | sha256sum | cut -c1-64)" = "$(sha256sum "$PD/$rel" 2>/dev/null | cut -c1-64)" ] || bad=$((bad+1))
    fi
  done
  echo "$bad"
}
# The --after-only lines of each step, read from the script as 5.18.105's rehearsal reads its own; UJ-ON is judged beside them.
ONP106="QF-ON0:ack QF-ON3:ledger $(grep -oE 'qf_on_one "\$(sub|1)" [A-Z]+-ON[0-9] [a-z-]+ ' "$DEPLOY106" | awk '{print $3 ":" $4}' | grep -v '^QF-ON0:' | sort -u | paste -sd' ' -) $(for p in $(sv106 "" VN_STEPS); do printf '%s:%s ' "${p##*:}" "${p%%:*}"; done)$(grep -q 'note "AC-ON1 OFF — not judged (agent-cc is off)"' "$DEPLOY106" && echo AC-ON1:agent-cc)"
check "$ONP106" "$ONP105" \
  "106-infra c the --after-only's step pairs, read from the script as 5.18.105's rehearsal reads its own ($(wc -w <<<"$ONP106") pairs): 5.18.105's exactly"
onall106() { local p; for p in $ONP106; do printf '%s=%s ' "$p" "$(onst104 "$1" "${p%%:*}" "${p#*:}")"; done | sed 's/ $//'; }   # onall106 OUTPUT — every pair's state, in the script's order
expect106() {   # expect106 STEP=STATE… — the line onall106 must read: every pair off but the steps named (the ledger's pair is ledger=STATE);
                # STATE as rh105q.py stcmp reads it (judged, notyet, any, a|b alternatives)
  local p s out="" e
  for p in $ONP106; do e=off; for s in "$@"; do case "$s" in *=*) [ "${p#*:}" = "${s%%=*}" ] && e="${s#*=}" ;; esac; done; out="$out $p=$e"; done
  echo "${out# }"
}
L1ON106="$(on105 ack voice-measure tracking list) ledger=pass|empty"   # steps 1–5 on, step 6 off: production's state before the deploy
L2ON106="$L1ON106 $(on105 chase)"                                       # steps 1–6 on (the interim, and 106-6f)
# ── The runs. run106 [--answer WORD] [--hold FILE] [--script FILE] [--env K=V]… OPTION… — as run105, this release's folders,
# and three things more: the script's exit status kept ($R106/rc/run-<n>.rc, read by rc106 — PASSED 0, FAILED or NO-GO 1, NOT
# YET 3), the run's state before it (its folder's backups, state files and LK key, read by quiet106), and RH106_TOUCH set, so
# the date folder's touch gives stamp_copy the clock's time (A1); a hold that times out is recorded in wait104.fail. No runner
# here moves a copy back, writes master's schedule or starts the cron stand-in (it works only while $P104 serves). ──
keysum106() { if [ -e "$LKF106" ]; then sha256sum < "$LKF106" | cut -c1-16; else echo nokey; fi; }
backups106() { ls -d "$OUT106"/backup-* 2>/dev/null | wc -l | tr -d ' '; }
states106() { ls -1 "$OUT106/state-$V106.env"* "$OUT106"/rollback-state-* 2>/dev/null | wc -l | tr -d ' '; }
keyf106() { [ -e "$LKF106" ] && echo key || echo nokey; }
run106() {
  local answer="" script="$DEPLOY106" extra=() hold="" n tag o cmd e0 c0
  while :; do case "${1:-}" in --answer) answer="$2"; shift 2 ;; --hold) hold="$2"; shift 2 ;; --script) script="$2"; shift 2 ;; --env) extra+=("$2"); shift 2 ;; *) break ;; esac; done
  : > "$SB/docker.log"; : > "$U99/requests.log"; : > "$U99/limits.log"
  n=$(( $(cat "$SB/runs" 2>/dev/null || echo 0) + 1 )); echo "$n" > "$SB/runs"; tag="$(printf '%03d' "$n")"; echo "$tag" > "$R106/rc/last"
  printf '%s|%s|%s\n' "$(backups106)" "$(states106)" "$(keysum106)" > "$R106/rc/run-$tag.pre"
  vt_host > "$R106/vault/run-$tag.before"; : > "$R106/exec/run-$tag.rec"; : > "$R106/scan/run-$tag.hits"; : > "$R106/tok/run-$tag.tok"; e0="$(evo_calls)"; c0="$(grep -c . "$R104/logs/cron104.runs" 2>/dev/null)"
  cmd="$(printf '%q ' env PATH="$SB/datebin:$SB/bin:$PATH" FAKE_TODAY="$AFTER94" DNB_OUT="$OUT106" DNB_RECORDS="$RECS" DNB_WEBSITE="$WEB_OK" GUARD_SECONDS=3 DN_DATA_DIR="$DATA" \
         DNB_SALES_LOG_DIR="$SALES104" DNB_REPORT_DIR="$REPORT104" RH106_TOUCH=1 \
         FAKE_EXEC_LOG="$R106/exec/run-$tag.rec" FAKE_TMP_SCAN="$SCAN96" FAKE_TMP_HITS="$R106/scan/run-$tag.hits" FAKE_TOK_HITS="$R106/tok/run-$tag.tok" \
         ${extra[@]+"${extra[@]}"} bash "$script" --plugin-base "$PLUGIN_BASE" "$@")"
  if [ -n "$hold" ]; then
    o="$( { i=0; while [ ! -e "$hold" ] && [ "$i" -lt 6000 ]; do sleep 0.05; i=$((i+1)); done; [ -e "$hold" ] || echo "run106-hold:$tag" >> "$R104/wait104.fail"; printf '%s\n' "$answer"; } \
          | SHELL=/bin/bash script -qec "$cmd" /dev/null 2>&1 | tee "${R106_STREAM:-/dev/null}" | tr -d '\r'; echo "${PIPESTATUS[1]}" > "$R106/rc/run-$tag.rc")"
  else o="$(printf '%s\n' "$answer" | SHELL=/bin/bash script -qec "$cmd" /dev/null 2>&1 | tee "${R106_STREAM:-/dev/null}" | tr -d '\r'; echo "${PIPESTATUS[1]}" > "$R106/rc/run-$tag.rc")"; fi
  echo "$(( $(evo_calls) - e0 )) $( [ "$(n103 "$(grep -c . "$R104/logs/cron104.runs" 2>/dev/null)")" -gt "$(n103 "$c0")" ] && echo cron || echo nocron)" > "$R106/evo/run-$tag.n"   # cron: the stand-in ran its worker during this run (none must)
  vt_host > "$R106/vault/run-$tag.after"; cp "$U99/requests.log" "$R106/ucrm/run-$tag.req"; cp "$U99/limits.log" "$R106/ucrm/run-$tag.lim"
  case "$o" in *"unknown option:"*) echo "run106-$n" >> "$SB/bad-runs" ;; esac
  if [ -n "${R106_MUTANT_OUT:-}" ]; then printf '%s\n' "$o" > "$R106/mutants/run-$tag.txt"; else printf '%s\n' "$o" > "$R106/run-$tag.txt"; fi
  [ -z "${REHEARSE_KEEP:-}" ] || printf '%s\n' "$o" > "$REHEARSE_KEEP/run106-$tag-${answer:-none}-$(basename "$script" .sh)$(printf '%s' "$*" | tr -c 'A-Za-z0-9-' '_').txt"
  printf '%s' "$o"
}
run106c() { run106 --env "LD_PRELOAD=$CLKPRE104" --env "RH104_CLOCK_FILE=$CLKF104" "$@"; }   # under the rehearsal's clock
run_line106c() {   # run_line106c WORD LINE — a command line exactly as a summary printed it, under the clock, WORD typed; its exit
                   # status (the line's own, as a shell reports it: a pipeline's last command) kept as run106's
  local n tag o cmd e0 c0
  : > "$SB/docker.log"; : > "$U99/requests.log"; : > "$U99/limits.log"
  n=$(( $(cat "$SB/runs" 2>/dev/null || echo 0) + 1 )); echo "$n" > "$SB/runs"; tag="$(printf '%03d' "$n")"; echo "$tag" > "$R106/rc/last"
  printf '%s|%s|%s\n' "$(backups106)" "$(states106)" "$(keysum106)" > "$R106/rc/run-$tag.pre"
  vt_host > "$R106/vault/run-$tag.before"; : > "$R106/exec/run-$tag.rec"; : > "$R106/scan/run-$tag.hits"; : > "$R106/tok/run-$tag.tok"; e0="$(evo_calls)"; c0="$(grep -c . "$R104/logs/cron104.runs" 2>/dev/null)"
  cmd="$(printf '%q ' env PATH="$SB/datebin:$SB/bin:$PATH" FAKE_TODAY="$AFTER94" DNB_OUT="$OUT106" DNB_RECORDS="$RECS" DNB_WEBSITE="$WEB_OK" GUARD_SECONDS=3 DN_DATA_DIR="$DATA" PLUGIN_BASE="$PLUGIN_BASE" \
         DNB_SALES_LOG_DIR="$SALES104" DNB_REPORT_DIR="$REPORT104" LD_PRELOAD="$CLKPRE104" RH104_CLOCK_FILE="$CLKF104" RH106_TOUCH=1 \
         FAKE_EXEC_LOG="$R106/exec/run-$tag.rec" FAKE_TMP_SCAN="$SCAN96" FAKE_TMP_HITS="$R106/scan/run-$tag.hits" FAKE_TOK_HITS="$R106/tok/run-$tag.tok" bash -c "$2")"
  o="$(printf '%s\n' "$1" | SHELL=/bin/bash script -qec "$cmd" /dev/null 2>&1 | tr -d '\r'; echo "${PIPESTATUS[1]}" > "$R106/rc/run-$tag.rc")"
  echo "$(( $(evo_calls) - e0 )) $( [ "$(n103 "$(grep -c . "$R104/logs/cron104.runs" 2>/dev/null)")" -gt "$(n103 "$c0")" ] && echo cron || echo nocron)" > "$R106/evo/run-$tag.n"
  vt_host > "$R106/vault/run-$tag.after"; cp "$U99/requests.log" "$R106/ucrm/run-$tag.req"; cp "$U99/limits.log" "$R106/ucrm/run-$tag.lim"
  case "$o" in *"unknown option:"*) echo "run106-$n" >> "$SB/bad-runs" ;; esac
  printf '%s\n' "$o" > "$R106/run-$tag.txt"
  printf '%s' "$o"
}
last106() { cat "$R106/rc/last" 2>/dev/null || echo none; }                    # the tag of the latest run106 / run_line106c
rc106() { cat "$R106/rc/run-$(last106).rc" 2>/dev/null || echo none; }         # its exit status
fresh106() {   # the run folder as a new run finds it: the state files, the key files and the row digests kept; every log and sheet moved aside
  find "$OUT106" -mindepth 1 -maxdepth 1 -name '*.log' -exec mv -t "$R106/logs/" {} + 2>/dev/null
  find "$OUT106" -mindepth 1 -maxdepth 1 -name 'cash-*.txt' -exec mv -t "$R106/sheets/" {} + 2>/dev/null
  find "$OUT106" -mindepth 1 -maxdepth 1 ! -name "state-$V106.env*" ! -name 'rollback-state-*' ! -name 'ql-key-*' ! -name 'cih-rows-*' ! -name "lk-key-$V106" -exec rm -rf {} +; base_log; }
tidy106() {   # tidy106 CASE — what a run that typed DEPLOY and stopped before the copy left (backup, state files, the LK key file)
              # moved into this rehearsal's own folder for the case, so the next deploy is a first deploy again
  local d="$R106/case/$1"; mkdir -p "$d"
  find "$OUT106" -mindepth 1 -maxdepth 1 \( -name 'backup-*' -o -name "state-$V106.env*" -o -name 'rollback-state-*' -o -name "lk-key-$V106" -o -name 'cih-rows-*' \) -exec mv -t "$d/" {} + 2>/dev/null; :
}
went106() { echo "$(has "$1" 'GO — evidence recorded')$(has "$1" 'Type DEPLOY')"; }   # the run reached the question
quiet106() {   # quiet106 — what the latest run changed: LIVE|backups added|state files added|samekey or keymoved|the book's digest
  local p; p="$(cat "$R106/rc/run-$(last106).pre" 2>/dev/null)"
  echo "$(live)|$(( $(backups106) - $(n103 "$(cut -d'|' -f1 <<<"$p")") ))|$(( $(states106) - $(n103 "$(cut -d'|' -f2 <<<"$p")") ))|$( [ -n "$p" ] && [ "$(keysum106)" = "$(cut -d'|' -f3 <<<"$p")" ] && echo samekey || echo keymoved)|$(data94)"
}
nogo106() {   # nogo106 LABEL EXPECTED-TEXT OUTPUT DATA-DIGEST LIVE — refused with that text, "Nothing was changed", exit status 1, never the
              # question; nothing deployed, backed up, written or changed: LIVE serving, the book's digest DATA-DIGEST (taken before the run)
  check "$(has "$3" "$2")$(has "$3" 'Nothing was changed. Send the log file.')$(went106 "$3")|$(rc106)|$(quiet106)" "yesyesnono|1|$5|0|0|samekey|$4" "$1"
}
checkgo106() { echo "$(has "$1" "  $V106 (check): GO — stage A holds.")$(went106 "$1")$(fails "$1")|$(cut -d'|' -f2-4 <<<"$(quiet106)")|$(rc106)"; }   # GO: yesnono0|0|0|samekey|0
sget106() { sed -n "s/^$2=//p" "$1" 2>/dev/null | tail -1; }                   # sget106 FILE KEY — the last value a state file records for KEY
copyok106() {   # copyok106 [STATE] — COPY_AT against QF_COPY_AT in a state file (A1): near (within 60 s: one clock), off:<s>, or unread
  local f="${1:-$STATE106}" c q d; c="$(sget106 "$f" COPY_AT)"; q="$(sget106 "$f" QF_COPY_AT)"
  case "$c" in ''|*[!0-9]*) echo unread; return ;; esac
  q="$(date -u -d "$q" +%s 2>/dev/null)" || { echo unread; return; }
  d=$(( c - q )); if [ "$d" -ge -60 ] && [ "$d" -le 60 ]; then echo near; else echo "off:$d"; fi
}
ctl106() {   # ctl106 — UJ-ON's control as this rehearsal reads it, followup_close's record against this deploy's copy (COPY_AT in
             # STATE106): after (a pass started after the copy and ended), running, before (A2's positive control for NOT YET:
             # no pass yet), none, nocopy or unread
  local c e lr d; c="$(sget106 "$STATE106" COPY_AT)"; case "$c" in ''|*[!0-9]*) echo nocopy; return ;; esac
  e="$(sched104 followup_close)"; [ "$e" = none ] && { echo none; return; }
  lr="${e%%:*}"; d="${e#*:}"; case "$lr" in ''|*[!0-9]*) echo unread; return ;; esac
  if [ "$lr" -le "$c" ]; then echo before; else case "$d" in -*) echo running ;; [0-9]*) echo after ;; *) echo unread ;; esac; fi
}
pig106() {   # pig106 — the installed public.php's piggyback record (both places it could be), as a digest: unchanged across a plant-to-copy span (A2)
  local f; for f in "$DATA/piggyback_last_run.txt" "$PD/data/piggyback_last_run.txt"; do
    if [ -f "$f" ]; then printf '%s:%s\n' "$(cat "$f")" "$(stat -c %Y "$f")"; else echo absent; fi
  done | sha256sum | cut -c1-16
}
aft106() {   # aft106 LABEL VERDICT [STEP=STATE…] — 5.18.106's --after-only under the clock, the copy as the deploy recorded it (never moved
             # back) and no schedule written: VERDICT PASSED (exit 0, no FAIL), NOTYET (exit 3, no FAIL, one UJ-ON NOT YET line, no
             # PASSED) or FAILED (exit 1, a FAIL, neither PASSED nor NOT YET); each pair as named, the rest OFF; Evolution not called.
             # A2: the caller runs master106 first, unless its case is "before any pass". Its output in AFT106, its run's tag in
             # AFTT106, its exit status in AFTRC106 — call it directly, never in $( )
  local lab="$1" want="$2" e0 f p y x got exp; shift 2; idle104; e0="$(evo_calls)"
  fresh106; AFT106="$(run106c --after-only)"; AFTT106="$(last106)"; AFTRC106="$(rc106)"
  f="$(fails "$AFT106")"; p="$(has "$AFT106" "  $V106 (after): PASSED.")"; y="$(has "$AFT106" "  $V106 (after-only): NOT YET — ")"
  x="$(grep -E "^  ${V106//./\\.} \(after\): [0-9]+ FAILED — " <<<"$AFT106" | grep -c .)"
  case "$want" in
    PASSED) got="$f|$p$y$x|$AFTRC106"; exp="0|yesno0|0" ;;
    NOTYET) got="$f|$p$y$x|$AFTRC106|$(grep -c '^  wait  UJ-ON NOT YET — ' <<<"$AFT106")"; exp="0|noyes0|3|1" ;;
    FAILED) got="$( [ "$(n103 "$f")" -gt 0 ] && echo some || echo none)|$p$y$x|$AFTRC106"; exp="some|nono1|1" ;;
    *) got="verdict:$want"; exp="PASSED|NOTYET|FAILED" ;;
  esac
  check "$got|$(q105 stcmp "$(onall106 "$AFT106")" "$(expect106 "$@")")|$(( $(evo_calls) - e0 ))" "$exp|same|0" \
    "$lab $V106's --after-only under the clock ($(kday104 "$(fnow104)"), followup_close $(ctl106) the copy): $want (exit status $AFTRC106) — each pair as the step stands$( [ $# -gt 0 ] && echo " ($*)"), the rest OFF — not judged; Evolution not called"
}
qcheck106() {   # qcheck106 LABEL — the quarantine's own --check, under the clock: PASSED. Once a 5.18.106 copy's time is on the clock
                # (A1), A14 of a later deploy needs one begun after every copy of this script, deploy or rollback alike
  local o; o="$(qrun104c --check)"
  check "$(has "$o" 'stray-store quarantine (check): PASSED.')" "yes" "$1 the quarantine's own --check: PASSED"
}
rbx106() {   # rbx106 LABEL — back to 5.18.105 after a deploy of 5.18.106: ROLLBACK typed, under the clock, no cron stand-in (it works
             # only while $P104 serves); PASSED, exit 0, $B106 serving, every file 5.18.106 changes $BV106's, every ladder key as
             # before it (followup_auto_send is not one: AS-RB1 may write it); then qcheck106. Its output in RBX106_OUT, its run's
             # tag in RBX106_T, the record it wrote in RBX106_REC (rollback-state-*.env, or none) — call it directly
  local o k0 r0
  k0="$(digest105)"; r0="$(ls -1 "$OUT106"/rollback-state-*.env 2>/dev/null)"
  fresh106; o="$(run106c --answer ROLLBACK --rollback)"; RBX106_OUT="$o"; RBX106_T="$(last106)"
  RBX106_REC="$(ls -1 "$OUT106"/rollback-state-*.env 2>/dev/null | grep -vxF -f <(printf '%s\n' "$r0") | tail -1)"; RBX106_REC="${RBX106_REC:-none}"
  check "$(fails "$o")$(has "$o" "  $V106 (rollback): PASSED.")|$(rc106)|$(live)|$(installed106 "$B106F")|$( [ "$(digest105)" = "$k0" ] && echo kept)|$(wason104 "$o")|$( [ "$RBX106_REC" != none ] && echo record)" \
        "0yes|0|$B106|0|kept|0|record" "$1 the rollback, ROLLBACK typed, under the clock: PASSED (exit 0) — $B106 serving, every file $V106 changes $BV106's again, every ladder key as before it (this rehearsal's reading), no tool line 'was on:', its record written ($(basename "$RBX106_REC"))"
  qcheck106 "$1'"
}
# ── Master, only through these two (A2, A4). Each moves the clock first, so the job is due by master's own interval (600 s
# followup_close, 300 s followup_send: master skips a job whose interval has not passed, silently), and reads afterwards that it
# ran: RUN in master's output, and its entry in master_schedule.json started within the pass on the clock and ended. Once a
# 5.18.106 copy is installed, followup_close is DUE in every cycle, so it is a real pass or none at all — master104 would
# otherwise record it as run now with duration 0, which is UJ-ON's control written by the rehearsal. Each sets its pass's
# clock time and master's output file in globals (MS106_AT/MS106_OUT, SS106_AT/SS106_OUT/SS106_CLOSE): call them directly. ──
tag106() { printf '%s' "$1" | tr -c 'A-Za-z0-9-' '_' | cut -c1-48; }         # a label as a file name
lrin106() {   # lrin106 ENTRY T0 T1 — a schedule entry (sched104's last_run:duration_ms) against a pass that ran from T0 to T1 on the
              # clock: in (started within it, a second's slack before, and ended), running (duration -1), out:<s>, none or unread
  local lr="${1%%:*}" d="${1#*:}"
  [ "$1" = none ] && { echo none; return; }
  case "$lr" in ''|*[!0-9]*) echo unread; return ;; esac
  if [ "$lr" -lt $(( $2 - 1 )) ] || [ "$lr" -gt "$3" ]; then echo "out:$(( lr - $2 ))"; return; fi
  case "$d" in -*) echo running ;; [0-9]*) echo in ;; *) echo unread ;; esac
}
due106() {   # due106 ENTRY T SECS — whether master's own interval lets the job run at T: due (no entry, or its last_run SECS or more before), or notdue:<s>
  local lr="${1%%:*}"; [ "$1" = none ] && { echo due; return; }
  case "$lr" in ''|*[!0-9]*) echo unread; return ;; esac
  if [ "$lr" -le $(( $2 - $3 )) ]; then echo due; else echo "notdue:$(( $2 - lr ))"; fi
}
master106() {   # master106 LABEL [DUE…] — one close pass (A2): the clock 601 s on, followup_close DUE (and any DUE named), the rest
                # recorded quiet by master104; checks that the pass was real
  local lab="$1" tag t0 t1 r f; shift; tag="106m-$(tag106 "$lab")"
  clk_to104 $(( $(fnow104) + 601 )) || true
  t0="$(fnow104)"; r="$(master104 "$tag" followup_close "$@")"; t1="$(fnow104)"; f="$R104/logs/master-$tag.out"
  MS106_AT="$t0"; MS106_OUT="$f"
  check "$r|$(lrin106 "$(sched104 followup_close)" "$t0" "$t1")|$(grep -c '\] \[master\] RUN followup_close$' "$f" 2>/dev/null)" "0|in|1" \
    "$lab a close pass ($(kday104 "$t0") $(TZ=Africa/Kampala date -d "@$t0" +%H:%M) Kampala, the clock 601 s on): master ran followup_close (RUN in its output), its entry started within the pass on the clock and ended — a real pass, never a plant"
}
kwin106() {   # kwin106 — 09:00 Kampala on the next date after the clock's (Kampala) date that is not a Sunday: an epoch (A4)
  local n i t; n="$(fnow104)"
  for i in 1 2 3; do t="$(kat104 "$(kday104 $(( n + i * 86400 ))) 09:00")"; [ "$(kdow104 "$t")" != 7 ] && { echo "$t"; return 0; }; done
  echo 0; return 1
}
closeok106() {   # closeok106 CLOSE ENTRY0 ENTRY1 T0 T1 OUT — followup_close in a sender pass: - (not DUE: 5.18.106 not installed), ran (a
                 # real pass), skipped (its interval not yet passed: no RUN, its entry as it was); anything else is what it was
  [ -z "$1" ] && { echo -; return; }
  if grep -q '\] \[master\] RUN followup_close$' "$6" 2>/dev/null; then
    if [ "$(lrin106 "$3" "$4" "$5")" = in ]; then echo ran; else echo "bad:$(lrin106 "$3" "$4" "$5")"; fi
  elif [ "$3" = "$2" ]; then echo skipped; else echo "moved:$3"; fi
}
sender106() {   # sender106 LABEL [--window] [--step SECS] [DUE…] — one sender pass (A4): --window, the clock to 09:00 Kampala on the next
                # date that is not a Sunday (kwin106); else the clock SECS on (default 301; A5's round is +60, a delivery, then +241);
                # followup_send DUE (followup_close too while 5.18.106 is installed). Asserted before the pass: not a Sunday, the hour
                # 8–19 Kampala, followup_send due by master's interval; after it: followup_send ran within the pass and ended, and
                # followup_close ran for real, was skipped, or was not asked — never planted after a 5.18.106 copy
  local lab="$1" win="" step=301 tag t0 t1 r f close="" lr0 lc0 dow hh c; shift
  while :; do case "${1:-}" in --window) win=1; shift ;; --step) step="$2"; shift 2 ;; *) break ;; esac; done
  tag="106s-$(tag106 "$lab")"
  if [ -n "$win" ]; then clk_to104 "$(kwin106)" || true; else clk_to104 $(( $(fnow104) + step )) || true; fi
  [ "$(inst_ver)" = "$V106" ] && close=followup_close
  t0="$(fnow104)"; dow="$(kdow104 "$t0")"; hh="$(TZ=Africa/Kampala date -d "@$t0" +%H)"; lr0="$(sched104 followup_send)"; lc0="$(sched104 followup_close)"
  r="$(master104 "$tag" followup_send ${close:+"$close"} "$@")"; t1="$(fnow104)"; f="$R104/logs/master-$tag.out"
  c="$(closeok106 "$close" "$lc0" "$(sched104 followup_close)" "$t0" "$t1" "$f")"
  SS106_AT="$t0"; SS106_OUT="$f"; SS106_CLOSE="$c"
  check "$( [ "$dow" != 7 ] && echo weekday || echo sunday)|$( [ "$((10#$hh))" -ge 8 ] && [ "$((10#$hh))" -le 19 ] && echo window || echo "hour$hh")|$(due106 "$lr0" "$t0" 300)|$r|$(lrin106 "$(sched104 followup_send)" "$t0" "$t1")|$(grep -c '\] \[master\] RUN followup_send$' "$f" 2>/dev/null)|$(case "$c" in -|ran|skipped) echo ok ;; *) echo "$c" ;; esac)" \
        "weekday|window|due|0|in|1|ok" \
    "$lab a sender pass ($(kday104 "$t0") $(TZ=Africa/Kampala date -d "@$t0" +%H:%M) Kampala$( [ -n "$win" ] && echo ', the next window' || echo ", the clock $step s on")): inside the sending window (not a Sunday, 08:00–20:00), followup_send due and run (RUN in master's output, its entry within the pass and ended); followup_close $c"
}
# ── The plants and the settings: rh106-seed.php through the INSTALLED release's own classes (never raw SQL), and set_config as
# the book's owner — each under the clock. ──
seed106() {   # seed106 CODE-ROOT DATA OPS-JSON [ZONE] — rh106-seed.php as the store's user, with a copy of the live vault of its own
              # (removed after), under the clock, and RH106_RANGE — this rehearsal's ten digits, read from PR106, which the helper
              # requires — given to that one run, never exported; the arguments passed as given (rh106-seed.php's own argv: the
              # code root, the data folder, the ops, the zone); one line of JSON, or none(<why>)
  local o v="$H106/v/seed-vault.json"
  rm -f "$v"; [ ! -f "$VAULT" ] || { cp "$VAULT" "$v"; chown 1000:1000 "$v"; chmod 600 "$v"; }
  [ -f "$v" ] || { printf '{}\n' > "$v"; chown 1000:1000 "$v"; chmod 600 "$v"; }
  o="$(cd "$H106/v" && setpriv --reuid 1000 --regid 1000 --clear-groups -- env DN_VAULT_FILE="$v" RH106_RANGE="${PR106#+}" LD_PRELOAD="$CLKPRE104" RH104_CLOCK_FILE="$CLKF104" \
        http_proxy= https_proxy= HTTP_PROXY= HTTPS_PROXY= NO_PROXY='*' "$PHPBIN" -d log_errors=0 "$H106/rh106-seed.php" "$@" 2>"$H106/v/seed.err")"
  rm -f "$v"
  case "$o" in '{'*) printf '%s' "$o" ;; *) echo "none($(head -c 160 "$H106/v/seed.err"))" ;; esac
}
setcfg106() {   # setcfg106 KEY VALUE — the INSTALLED tools/set_config.php as the book's owner on the live data folder, under the clock:
                # the form of the owner's own command the script prints (FE-G1, AS-RB1), its output cut where the settings listing
                # begins; its output (kept in $R106/setcfg.txt, which no sweep reads); its exit status read by setrc106
  local o
  o="$(env PATH="$SB/bin:$PATH" LD_PRELOAD="$CLKPRE104" RH104_CLOCK_FILE="$CLKF104" bash "$SB/bin/docker" exec -u "$(owner104)" -e "DN_DATA_DIR=$PDD104_IN" ucrm \
        php "$IN_CONTAINER/tools/set_config.php" --key "$1" --value "$2" 2>&1 </dev/null | sed '/^  AI SETTINGS$/,$d'; exit "${PIPESTATUS[0]}")"
  echo "$?" >> "$R106/setcfg.rc"; printf '%s\n' "$o" >> "$R106/setcfg.txt"; printf '%s' "$o"
}
setrc106() { tail -n1 "$R106/setcfg.rc" 2>/dev/null || echo none; }
# ── Every fixture has its undo (A9): a file set aside and put back byte for byte, or an append-only file cut back to its mark,
# each asserted by sha256. ──
keep106() {   # keep106 NAME FILE — FILE set aside as NAME (cp -p: bytes, owner, mode, time), its sha256 kept; prints its first 16 hex, or absent
  local d="$R106/kept/$1"; rm -rf "$d"; mkdir -p "$d"; printf '%s\n' "$2" > "$d/path"
  if [ -e "$2" ]; then cp -p "$2" "$d/file"; sha256sum < "$2" | cut -c1-64 > "$d/sha"; else echo absent > "$d/sha"; fi
  cut -c1-16 "$d/sha"
}
back106() {   # back106 NAME — the file put back as keep106 found it (or removed, when it was absent); prints same, or what differs
  local d="$R106/kept/$1" f; [ -f "$d/path" ] || { echo "nokeep:$1"; return 1; }; f="$(cat "$d/path")"
  if [ "$(cat "$d/sha")" = absent ]; then rm -f "$f"; if [ -e "$f" ]; then echo present; else echo same; fi; return 0; fi
  cp -p "$d/file" "$f"; if [ "$(sha256sum < "$f" | cut -c1-64)" = "$(cat "$d/sha")" ]; then echo same; else echo differs; fi
}
mark106() { local s; [ -f "$1" ] || { echo absent; return; }; s="$(stat -c %s "$1")"; echo "$s:$(head -c "$s" "$1" | sha256sum | cut -c1-16)"; }   # mark106 FILE — SIZE:sha16
cut106() {   # cut106 FILE MARK — an append-only file (plugin.log) put back to mark106's MARK: its first SIZE bytes still MARK's, then
             # truncated to SIZE (removed, MARK absent); prints same, or why not (moved: written over, not appended to)
  local s="${2%%:*}" h="${2#*:}"
  if [ "$2" = absent ]; then rm -f "$1"; if [ -e "$1" ]; then echo present; else echo same; fi; return 0; fi
  [ -f "$1" ] || { echo gone; return 0; }
  [ "$(head -c "$s" "$1" | sha256sum | cut -c1-16)" = "$h" ] || { echo moved; return 0; }
  truncate -s "$s" "$1" && [ "$(mark106 "$1")" = "$2" ] && echo same || echo differs
}
# ── The weakened copies: each anchor the script's own text, read here into a file of its own, its replacement beside it; each copy
# asserts its anchor once, its replacement once, and the result; ancc106 is the control of the controls over every anchor set. ──
AN106="$SB/anchors106"; rm -rf "$AN106"; mkdir -p "$AN106"
anc106() { printf '%s' "$2" > "$AN106/$1.a"; printf '%s' "$3" > "$AN106/$1.b"; }   # anc106 KEY ANCHOR REPLACEMENT
weak106() {   # weak106 NAME KEY… — a copy of the script in the clone's scripts/ with each KEY's anchor replaced, asserted; prints its path
  local name="$1" k dst="$REPO/scripts/.$1.sh"; shift
  cp "$DEPLOY106" "$dst"
  for k in "$@"; do
    python3 -I - "$dst" "$AN106/$k.a" "$AN106/$k.b" <<'PY' || echo "weak106:$k" >> "$SB/anchor-missing"
import sys
f, fa, fb = sys.argv[1:4]
s = open(f, encoding='utf-8').read(); a = open(fa, encoding='utf-8').read(); b = open(fb, encoding='utf-8').read()
assert a and a != b and s.count(a) == 1, (a[:70], s.count(a))
s2 = s.replace(a, b, 1)
assert s2.count(b) == 1 + s.count(b) and len(s2) == len(s) - len(a) + len(b) and (a in b or s2.count(a) == 0)
open(f, 'w', encoding='utf-8').write(s2)
PY
  done
  bash -n "$dst" 2>/dev/null || echo "weak106:$name:syntax" >> "$SB/anchor-missing"
  echo "$dst"
}
ancc106() {   # ancc106 — bad|n|keys over every anchor anc106 has set: each the script's own text, found in it once, its replacement different
  python3 -I - "$DEPLOY106" "$AN106" <<'PY'
import os, sys
s = open(sys.argv[1], encoding='utf-8').read(); d = sys.argv[2]
keys = sorted(f[:-2] for f in os.listdir(d) if f.endswith('.a'))
bad = [k for k in keys if (lambda a, b: not a or a == b or s.count(a) != 1)(open(os.path.join(d, k + '.a'), encoding='utf-8').read(), open(os.path.join(d, k + '.b'), encoding='utf-8').read())]
print('%d|%d|%s' % (len(bad), len(keys), ','.join(bad) or '-'))
PY
}
# ── 105-10's one-liners this release reads again (cut with 105-10): the request and token records' readers, and the lists of
# files a sweep reads — this release's own (sweep106), and 5.18.105's (sweep105), read from 5.18.105's rehearsal after
# 105-10's heading, its two lines, and sourced unchanged (a miss recorded in anchor-missing; the list then reads nothing). ──
uo106() { local f n=0; for f in "$@"; do [ -f "$f" ] || continue; [ "$(python3 "$H99/rh99.py" u99other "$f" 2>/dev/null)" = "-" ] || n=$((n+1)); done; echo "$n"; }   # request records naming any request not a GET
tl106() { local f n=0; for f in "$@"; do [ -f "$f" ] || continue; case "$(python3 "$H104/rh104.py" tok99 "$f" 2>/dev/null)" in *" left=0") ;; *) n=$((n+1)) ;; esac; done; echo "$n"; }   # token records with a copy left
sweep106() { { ls "$R106"/run-*.txt "$R106"/mutants/*.txt "$R106"/sheets/* "$R106"/logs/*.log "$R106"/logs/stream-*.txt "$OUT106"/state-* "$OUT106"/rollback-state-* "$OUT106"/cih-rows-* "$OUT106"/*.log "$OUT106"/cash-*.txt; \
               find "$R106/case" -type f ! -path '*/backup-*/*' 2>/dev/null; } 2>/dev/null | grep -v '/lk-key' | sort -u; }
L105F_106="$(mktemp)"
python3 -I - "$H105_HARNESS106" "$L105F_106" <<'PY' || echo "rh106:lists105" >> "$SB/anchor-missing"
import sys
src, dst = sys.argv[1], sys.argv[2]
lines = open(src, encoding='utf-8').read().split('\n')
at = [i for i, l in enumerate(lines) if l == 'echo; echo "== 105-10. what the rehearsal left behind, over 104\'s records and 105\'s alike =="']
assert len(at) == 1, len(at)
k = [i for i, l in enumerate(lines) if l.startswith('sweep105() { ')]
assert len(k) == 1 and k[0] > at[0], k
two = lines[k[0]:k[0] + 2]
assert two[0].count('() {') == 1 and two[0].endswith('; \\') and '$R105' in two[0] and '$OUT105' in two[0], two[0][:60]
assert two[1].lstrip().startswith('find "$R105/case"') and two[1].endswith('| sort -u; }'), two[1][-40:]
open(dst, 'w', encoding='utf-8').write('\n'.join(two) + '\n')
PY
# shellcheck disable=SC1090
. "$L105F_106"; rm -f "$L105F_106"
check "$(declare -F sweep105 >/dev/null && echo read)|$(sweep105 | grep -c "^$R105/run-" | awk '{print ($1 > 0) ? "runs105" : "none"}')|$(declare -F sweep106 uo106 tl106 | grep -c .)" "read|runs105|3" \
  "106-infra d 105-10's own list of 5.18.105's records (sweep105) read from 5.18.105's rehearsal after its heading and sourced unchanged — it lists 5.18.105's run terminals; this release's own list and the two records' readers (sweep106, uo106, tl106) beside it"

# ── 106's own numbers: +2567001060NN, the chain's own +2567001040NN beside them. Proved free here, never assumed: no harness of
# this checkout (5.18.106's own folder aside), no test or fixture of this checkout, of the pin's tree or the baseline's in Git,
# no file of the sandbox's data folders or records, and neither pattern file holds any of them in any written form (a number
# of the range not inside a longer run of digits, or its spaced national form) — each reading's control the chain's own range,
# or the pin's own F1 function, found by the same reading. ──
PR106='+2567001060'
RX106='(^|[^0-9])(\+?256|0)?7001060[0-9]{2}([^0-9]|$)|0700 106 0[0-9]{2}'; RXC106='(^|[^0-9])(\+?256|0)?7001040[0-9]{2}([^0-9]|$)'
ph106() { case "${1:-}" in [0-9][0-9]) printf '%s%s\n' "$PR106" "$1" ;; *) echo "ph106:${1:-}" >> "$SB/anchor-missing"; return 1 ;; esac; }   # ph106 NN — that number of the range
sqx "PRAGMA wal_checkpoint(TRUNCATE)" >/dev/null 2>&1
NH106="$(grep -rlE --exclude-dir=deploy-5.18.106 "$RX106" "$H106_ROOT/scripts/harness" 2>/dev/null | wc -l | tr -d ' ')"
NHC106="$(grep -rlE --exclude-dir=deploy-5.18.106 "$RXC106" "$H106_ROOT/scripts/harness" 2>/dev/null | wc -l | tr -d ' ')"
NT106="$(if [ -d "$H106_ROOT/$P/tests" ]; then grep -rlE "$RX106" "$H106_ROOT/$P/tests" 2>/dev/null | wc -l | tr -d ' '; else echo notests; fi)"
NG106="$(for c106 in "$P106F" "$B106F"; do git -C "$REPO" grep -lE "$RX106" "$c106" -- "$P/tests" 2>/dev/null | wc -l | tr -d ' '; done | paste -sd, -)"
NGC106="$(git -C "$REPO" grep -l 'refusedWhileDelivering' "$P106F" -- "$P/tests" 2>/dev/null | wc -l | tr -d ' ')"
ND106="$(grep -rlaE "$RX106" "$DATA" "$PD/data" "$RECS" 2>/dev/null | wc -l | tr -d ' ')"
NDC106="$(grep -rlaE "$RXC106" "$DATA" 2>/dev/null | wc -l | tr -d ' ')"
NP106="$(cat "$NAMES104" "$FORMS105" 2>/dev/null | grep -cE "$RX106")"
check "$NH106|$( [ "$(n103 "$NHC106")" -ge 1 ] && echo read)|$NT106|$NG106|$( [ "$(n103 "$NGC106")" -ge 1 ] && echo read)|$ND106|$( [ "$(n103 "$NDC106")" -ge 1 ] && echo read)|$NP106" "0|read|0|0,0|read|0|read|0" \
  "106-infra e 106's own numbers ($PR106<NN>) proved free: in no harness ($NHC106 file(s) of the chain's range found by the same reading), no test or fixture of this checkout, none of the pin's or the baseline's tests in Git ($NGC106 of the pin's naming refusedWhileDelivering: the reading reads), nowhere in the sandbox's data folders or records ($NDC106 file(s) of the chain's range there), in neither pattern file"
for i106 in $(seq -w 0 99); do n106="$(ph106 "$i106")"; grep -qxF -- "$n106" "$NAMES104" || printf '%s\n' "$n106" >> "$NAMES104"; done
{ cat "$R105/phones105.txt" 2>/dev/null; for i106 in $(seq -w 0 99); do ph106 "$i106"; done; } | grep . | sort -u > "$R106/phones106.txt"
FORMS106="$R106/forms106.pat"; PH4_106="$(q105 phones4 "$R106/phones106.txt" "$FORMS106")"
NM106F="$R106/names106.pat"; { grep -v '^[+0-9 ]*$' "$NAMES104" | grep .; echo 'RH106SECRET-'; } > "$NM106F"; chmod 600 "$NM106F"
N105P106="$(tr -cd '0-9\n' < "$R105/phones105.txt" 2>/dev/null | grep -E '^256[0-9]{9}$' | sort -u | grep -c .)"   # 5.18.105's numbers, each once whatever its written form
check "$(( $(n103 "$(kv106 "$PH4_106" n)") - $(n103 "$N105P106") ))|$( [ "$(kv106 "$PH4_106" forms)" = "$(( 5 * $(n103 "$(kv106 "$PH4_106" n)") ))" ] && echo five)|$(stat -c %a "$FORMS106")|$(grep -cxF '0700 106 000' "$FORMS106")$(grep -cxF '700106099' "$FORMS106")$(grep -cxF '+256700106050' "$FORMS106")|$(grep -cE "^\+?$(tr -d '+' <<<"$PR106")[0-9]{2}$" "$NAMES104")|$(stat -c %a "$NM106F")|$(grep -cxF 'RH106SECRET-' "$NM106F")" \
      "100|five|600|111|100|600|1" \
  "106-infra f the 100 numbers of 106's range beside the chain's and 5.18.105's ($(kv106 "$PH4_106" n) in all), each in its four written forms and its last nine digits, into a 0600 pattern file ($(kv106 "$PH4_106" forms) lines); the range added to the chain's NAMES104; the names file (the chain's names and the RH106SECRET- marker), 0600"

# ── A1 — the copy's time on the rehearsal's clock. stamp_copy runs `touch -c FILE` and the kernel stamps the file with its own
# time, which LD_PRELOAD does not move: COPY_AT and RB_COPY_AT (stat %Y of COPY_MARK) would be the wall clock's, some forty
# days before every row and schedule entry UJ-ON and ME-CA1 compare them with. A touch in the date folder — first on every run's
# PATH — gives a `touch -c FILE…` with no other option the time `date` reads (the clock's, under LD_PRELOAD), and only for
# 5.18.106's own runs (RH106_TOUCH, set by run106 and run_line106c: 5.18.105's runs in 106-pre stay as 5.18.105's rehearsal ran
# them); every other use passes straight through. No state line is ever rewritten. ──
TOUCH106="$(command -v touch)"; DATE106="$(command -v date)"
case "$TOUCH106|$DATE106" in /*'|'/*) case "$TOUCH106|$DATE106" in "$SB"/*|*"|$SB"/*) TP106="insb" ;; *) TP106="real" ;; esac ;; *) TP106="unfound" ;; esac
[ ! -e "$SB/datebin/touch" ] || { echo "datebin:touch106:present" >> "$SB/anchor-missing"; TP106="$TP106,present"; }
cat > "$SB/datebin/touch" <<SH
#!/usr/bin/env bash
# 5.18.106's rehearsal (A1): for 5.18.106's own runs (RH106_TOUCH), "touch -c FILE…" with no other option — stamp_copy's —
# given the time date reads (the rehearsal's clock under LD_PRELOAD); every other use passes straight through.
if [ -n "\${RH106_TOUCH:-}" ] && [ "\${1:-}" = -c ] && [ "\$#" -ge 2 ]; then
  for a in "\${@:2}"; do case "\$a" in -*) exec "$TOUCH106" "\$@" ;; esac; done
  exec "$TOUCH106" -c -d "@\$("$DATE106" +%s)" "\${@:2}"
fi
exec "$TOUCH106" "\$@"
SH
chmod +x "$SB/datebin/touch"
nr106() { local d=$(( $(n103 "$1") - $2 )); if [ "$d" -ge -2 ] && [ "$d" -le 2 ]; then echo near; else echo "off:$d"; fi; }   # nr106 A B — within 2 s
TCF106="$H106/touch.ctl"; : > "$TCF106"; rm -f "$H106/touch.none"
tst106() { stat -c %Y "$TCF106" 2>/dev/null || echo none; }
TO106=$(( $(fnow104) - $(date -u +%s) ))
env LD_PRELOAD="$CLKPRE104" RH104_CLOCK_FILE="$CLKF104" "$TOUCH106" -c "$TCF106"; A1A_106="$(nr106 "$(tst106)" "$(date -u +%s)")"
env PATH="$SB/datebin:$PATH" RH106_TOUCH=1 LD_PRELOAD="$CLKPRE104" RH104_CLOCK_FILE="$CLKF104" touch -c "$TCF106"; A1B_106="$(nr106 "$(tst106)" "$(fnow104)")"
env PATH="$SB/datebin:$PATH" RH106_TOUCH=1 touch -c "$TCF106"; A1C_106="$(nr106 "$(tst106)" "$(date -u +%s)")"
env PATH="$SB/datebin:$PATH" LD_PRELOAD="$CLKPRE104" RH104_CLOCK_FILE="$CLKF104" touch -c "$TCF106"; A1D_106="$(nr106 "$(tst106)" "$(date -u +%s)")"
env PATH="$SB/datebin:$PATH" RH106_TOUCH=1 LD_PRELOAD="$CLKPRE104" RH104_CLOCK_FILE="$CLKF104" touch -c -d @86400 "$TCF106"; A1E_106="$(tst106)"
env PATH="$SB/datebin:$PATH" RH106_TOUCH=1 LD_PRELOAD="$CLKPRE104" RH104_CLOCK_FILE="$CLKF104" touch -c "$H106/touch.none"; A1F_106="$( [ -e "$H106/touch.none" ] && echo made || echo none)"
A1G_106="$(env PATH="$SB/datebin:$SB/bin:$PATH" bash -c 'command -v touch')"
rm -f "$TCF106" "$H106/touch.none"
check "$TP106|$( [ "$TO106" -ge 3600 ] && echo ahead || echo "offset:$TO106")|$A1A_106|$A1B_106|$A1C_106|$A1D_106|$A1E_106|$A1F_106|$( [ "$A1G_106" = "$SB/datebin/touch" ] && echo first)" \
      "real|ahead|near|near|near|near|86400|none|first" \
  "106-infra g A1 the copy's time on the clock: the clock $(( TO106 / 86400 )) day(s) ahead; the real touch under LD_PRELOAD stamps the kernel's (wall) time — the reason for the shim; the date folder's touch, for 5.18.106's runs, stamps the clock's under it and the wall clock's without it; not asked (RH106_TOUCH unset), with a time of its own, or on a missing file (-c) it passes straight through; first on a run's PATH"

# ── A6 — Evolution's 400 for a listed number (F1's refusals, J1). The stand-in's router as the chain leaves it (RQ's base, then
# 5.18.104's three asserted edits): rh104-slow's block, then the requests.log line, then rh104-media.b64's block, then
# rh104-send.on's block, which answers every /message/send(Text|Media)/ with a key.id and returns, then the `[]` line. One
# asserted insertion before the line that begins
#   if (is_file(__DIR__ . '/rh104-send.on') && preg_match('#/message/send(Text|Media)/#'
# — after the requests.log line, so a refusal is still recorded by method and path — answering HTTP 400 with Evolution's own JSON
# (status 400, "Bad Request", its message in response.message, so 5.18.105's requestUg records "Bad Request [HTTP 400 on POST
# /message/sendText/<instance>]", which F1's LIKE reads), only on /message/sendText/, only for a number whose digits are a line
# of rh106-refuse.list beside the router, never echoing the number; each refusal counted in rh106-refused.n. ──
refuse106() {   # refuse106 [NUMBER…] — the stand-in's refusal list: exactly these numbers' digits, one a line (0644); none: removed. Prints how many it lists
  local f="$SB/evo/rh106-refuse.list" n
  if [ $# -eq 0 ]; then rm -f "$f"; echo 0; return 0; fi
  for n in "$@"; do tr -cd '0-9' <<<"$n"; echo; done > "$f"; chmod 644 "$f"; grep -c . "$f"
}
refused106() { n103 "$(cat "$SB/evo/rh106-refused.n" 2>/dev/null)"; }   # how many sends the stand-in has refused
evopost106() {   # evopost106 PATH NUMBER — one send to the stand-in Evolution as the release sends one (POST, JSON, the number's digits in the
                 # body; PATH sendText or sendMedia): id (2xx with a key.id), refused (HTTP 400, Evolution's JSON, no number in it), or <code>:other
  local r; r="$(curl -s --noproxy '*' -w '\n%{http_code}' -X POST -H 'Content-Type: application/json' \
                 -d "{\"number\":\"$(tr -cd '0-9' <<<"$2")\",\"text\":\"RH106SECRET-control\"}" "$EVO_URL/message/$1/rh-sales" 2>/dev/null)"
  python3 -I -c 'import json, sys
raw, num = sys.argv[1], sys.argv[2]
body, _, code = raw.rpartition("\n")
try:
    d = json.loads(body)
except ValueError:
    d = None
if code.startswith("2") and isinstance(d, dict) and str((d.get("key") or {}).get("id", "")).startswith("RH104EVO"):
    print("id")
elif code == "400" and isinstance(d, dict) and d.get("status") == 400 and d.get("error") == "Bad Request" and "message" not in d and num not in body:
    print("refused")
else:
    print("%s:other" % code)' "$r" "$(tr -cd '0-9' <<<"$2")"
}
EV0_106="$(evo_calls)"; RF0_106="$(refused106)"; PA106="$(ph106 98)"; PB106="$(ph106 99)"
refuse106 "$PA106" >/dev/null; C0_106="$(evopost106 sendText "$PA106")"   # before the edit: the list alone refuses nothing (the control on the control)
python3 -I - "$SB/evo/router.php" <<'PY' || echo "evo:refuse106" >> "$SB/anchor-missing"
import sys
p = sys.argv[1]; s = open(p).read()
lines = s.split('\n')
anc = "if (is_file(__DIR__ . '/rh104-send.on') && preg_match('#/message/send(Text|Media)/#'"
log = "file_put_contents(__DIR__ . '/requests.log',"
empty = "header('Content-Type: application/json'); echo '[]';"
at = [i for i, l in enumerate(lines) if l.startswith(anc)]
assert len(at) == 1, len(at)
assert s.count('rh106') == 0 and sum(1 for l in lines if l == empty) == 1 and sum(1 for l in lines if l.startswith(log)) == 1, (s.count('rh106'), s.count(empty))
block = [
    "if (is_file(__DIR__ . '/rh106-refuse.list') && strpos($_SERVER['REQUEST_URI'], '/message/sendText/') !== false) {   // 5.18.106's rehearsal (A6): a listed number refused, as Evolution refuses one",
    "    $b106 = json_decode((string)file_get_contents('php://input'), true); $n106 = preg_replace('/\\D/', '', (string)(is_array($b106) ? ($b106['number'] ?? '') : ''));",
    "    $l106 = array_map('trim', (array)@file(__DIR__ . '/rh106-refuse.list'));",
    "    if ($n106 !== '' && in_array($n106, $l106, true)) {",
    "        file_put_contents(__DIR__ . '/rh106-refused.n', (string)((int)@file_get_contents(__DIR__ . '/rh106-refused.n') + 1));",
    "        http_response_code(400); header('Content-Type: application/json'); echo json_encode(['status' => 400, 'error' => 'Bad Request', 'response' => ['message' => ['rejected']]]); return;",
    "    }",
    "}",
]
lines[at[0]:at[0]] = block
s2 = '\n'.join(lines)
assert len(s2) == len(s) + len('\n'.join(block)) + 1
assert s2.count("'/rh106-refuse.list'") == 2 and s2.count("'/rh106-refused.n'") == 2 and s2.count("'Bad Request'") == 1
assert sum(1 for l in s2.split('\n') if l.startswith(anc)) == 1 and sum(1 for l in s2.split('\n') if l == empty) == 1
assert s2.index(log) < s2.index('rh106') < s2.index(anc), 'order'
open(p, 'w').write(s2)
PY
refuse106 >/dev/null; C1_106="$(evopost106 sendText "$PA106")"   # list absent: a key.id
refuse106 "$PA106" >/dev/null
C2_106="$(evopost106 sendText "$PA106")|$(evopost106 sendText "$PB106")|$(evopost106 sendMedia "$PA106")"   # listed: 400; another: a key.id; a media send: a key.id
refuse106 >/dev/null
check "$( [ -f "$SB/evo/rh104-send.on" ] && echo sendon)|$C0_106|$C1_106|$C2_106|$(( $(evo_calls) - EV0_106 ))|$(( $(refused106) - RF0_106 ))|$(tail -n 5 "$SB/evo/requests.log" 2>/dev/null | grep -c "$(tr -cd '0-9' <<<"$PA106")\|RH106SECRET")|$( [ -e "$SB/evo/rh106-refuse.list" ] && echo listed || echo unlisted)" \
      "sendon|id|id|refused|id|id|5|1|0|unlisted" \
  "106-infra h A6 the stand-in Evolution's 400: rh104-send.on there (sends answered with a key.id); before the edit the list refuses nothing; after it, the list absent a key.id; listed, a sendText for that number answered HTTP 400 with Evolution's JSON (no number in it), another number's and a sendMedia answered with a key.id; all five requests recorded by method and path alone, one refusal counted; the list removed (J1 writes its own)"

# ── A7 — .redirect106 and its index lock (106-7). The stand-in public.php as the chain leaves it: RQ's base, then the triggers each
# release inserted before the line `$page = $_GET['page'] ?? '';` — 5.18.105's .redirect105 the last of them. 5.18.105's branch
# fires only while the manifest says 5.18.105, so one asserted insertion of this release's own, before the same line: a probe
# page, and a 302 on the sign-in page only while the installed manifest says 5.18.106 and .redirect106 can be spent — after the
# deploy's copy and its checkout back, since V1 probes later. With .redirect106lock beside it, the same spend creates the
# clone's .git/index.lock, so V1's do_rollback finds `git checkout <baseline>` refused before it copies anything. ──
arm106() { printf 'x\n' > "$SB/web/.redirect106"; chmod 644 "$SB/web/.redirect106"; [ "${1:-}" != lock ] || { printf 'x\n' > "$SB/web/.redirect106lock"; chmod 644 "$SB/web/.redirect106lock"; }; }   # arm106 [lock]
lock106() { [ -e "$REPO/.git/index.lock" ] && echo present || echo absent; }                  # the clone's index lock
unlock106() { rm -f "$REPO/.git/index.lock"; lock106; }                                        # removed; prints absent
PB0_106="$(pubget page=rh106_probe | grep -c 'rh106-triggers-ready')"   # before the edit: no such page (the control on the control)
python3 -I - "$SB/web/public.php" "$V106" "$REPO" <<'PY' || echo "standin:triggers106" >> "$SB/anchor-missing"
import sys
p, v106, repo = sys.argv[1:4]
assert v106 and repo.startswith('/') and "'" not in repo and '\\' not in repo, (v106, repo)
s = open(p).read()
old = "$page = $_GET['page'] ?? '';\n"
assert s.count(old) == 1 and s.count('redirect106') == 0 and s.count("'.redirect105'") == 1 and s.count('rh105_probe') == 1, (s.count(old), s.count('redirect106'), s.count("'.redirect105'"), s.count('rh105_probe'))
new = ("if (($_GET['page'] ?? '') === 'rh106_probe') { echo 'rh106-triggers-ready'; exit; }\n"
       "if (($_GET['page'] ?? '') === 'customer_login' && !isset($_GET['rh106v1']) && strpos((string)@file_get_contents($root . '/manifest.json'), '\"" + v106 + "\"') !== false && $spend('.redirect106')) { if ($spend('.redirect106lock')) @touch('" + repo + "/.git/index.lock'); header('Location: ?page=customer_login&rh106v1=1', true, 302); exit; }   // 5.18.106's rehearsal: V1 (106-7), with .redirect106lock the clone's index lock (A7)\n")
s2 = s.replace(old, new + old, 1)
assert s2.count("'.redirect106'") == 1 and s2.count("'.redirect106lock'") == 1 and s2.count('rh106_probe') == 1 and s2.count(old) == 1 and len(s2) == len(s) + len(new)
assert s2.count(repo + "/.git/index.lock") == 1 and s2.index("'.redirect105'") < s2.index("'.redirect106'") < s2.index(old), 'order'
open(p, 'w').write(s2)
PY
for i in $(seq 1 60); do grep -q 'rh106-triggers-ready' <<<"$(pubget page=rh106_probe)" && break; sleep 0.1; done   # PHP re-reads the edited file
LK0_106="$(lock106)"; arm106 lock
TR106="$(v1read102)|$( [ -e "$SB/web/.redirect106" ] && echo unspent || echo spent)|$( [ -e "$SB/web/.redirect106lock" ] && echo unspent || echo spent)|$(lock106)"
rm -f "$SB/web/.redirect106" "$SB/web/.redirect106lock"
check "$PB0_106|$(pubget page=rh106_probe)|$( [ -d "$REPO/.git" ] && echo gitdir)|$( [ "$(inst_ver)" != "$V106" ] && echo not106)|$LK0_106|$TR106" "0|rh106-triggers-ready|gitdir|not106|absent|200:0|unspent|unspent|absent" \
  "106-infra i A7 the stand-in carries .redirect106 and .redirect106lock (its probe answers, as it did not before the edit): armed both while $(inst_ver)'s manifest is installed, the sign-in page does not redirect (200:0), neither trigger is spent and no index lock appears — they fire only under $V106's (106-7)"

# ── A8 — the uj hook (106-1 "U4 unreadable", 106-5 "this run's reading fails"). The stand-in docker as the chain leaves it: RQ's
# strict stub, 5.18.94–5.18.104's asserted edits, 5.18.104's .rbfail104 trigger and its four faults inserted before the line that
# begins `row() { grep "^$1|" `. One asserted insertion before the same line, after those: while $SB/.ujfail106 exists, an exec
# whose source is QF_PHP's (the argument after -r begins "// QF_PHP") and whose mode word (the next) is uj exits 1 with no
# output — the script's `qf_php uj` reading, and nothing else; each refusal counted in ujfail106.fired; not spent, it holds until
# the file is removed. ──
ujcall106() {   # ujcall106 SOURCE MODE — one call of QF_PHP's shape through the stand-in docker, as the book's owner: <output|empty>:<exit status>
  local o rc; o="$(dk104 exec -u "$OWN105" ucrm php -d display_errors=stderr -d log_errors=0 -d opcache.enable_cli=0 -r "$1" "$2" /data/rh106-none)"; rc=$?
  echo "${o:-empty}:$rc"
}
UJS106=$'// QF_PHP control of 5.18.106\'s rehearsal (A8)\necho "uj=control";'; UJN106=$'// another reading\necho "uj=control";'
: > "$SB/ujfail106.fired"; : > "$SB/.ujfail106"
U0_106="$(ujcall106 "$UJS106" uj)"   # before the edit, the trigger planted: read (the control on the control)
cat > "$SB/docker106.hook" <<'HOOK'
# ── 5.18.106's rehearsal (A8): QF_PHP's uj reading refused while @SB@/.ujfail106 exists — exit 1, no output, until it is removed ──
if [ "${1:-}" = exec ] && [ -f "@SB@/.ujfail106" ]; then
  _rh_r6=0; _rh_j6=0
  for _rh_a6 in "$@"; do _rh_j6=$((_rh_j6 + 1)); if [ "$_rh_r6" = 0 ] && [ "$_rh_a6" = "-r" ]; then _rh_r6=$_rh_j6; fi; done
  if [ "$_rh_r6" -gt 0 ] && [ $((_rh_r6 + 2)) -le "$#" ]; then
    _rh_s6=$((_rh_r6 + 1)); _rh_m6=$((_rh_r6 + 2))
    case "${!_rh_s6}" in "// QF_PHP"*) if [ "${!_rh_m6}" = uj ]; then echo uj >> "@SB@/ujfail106.fired"; exit 1; fi ;; esac
  fi
fi
HOOK
python3 -I - "$SB/bin/docker" "$SB/docker106.hook" "$SB" <<'PY' || echo "fakedocker:ujfail106" >> "$SB/anchor-missing"
import sys
p, hook, sb = sys.argv[1:4]
assert sb.startswith('/') and '"' not in sb, sb
s = open(p).read()
h = open(hook).read().replace('@SB@', sb)
assert '@SB@' not in h and h.count('.ujfail106') == 2 and h.count('ujfail106.fired') == 1, (h.count('.ujfail106'), h.count('ujfail106.fired'))
lines = s.split('\n')
at = [i for i, l in enumerate(lines) if l.startswith('row() { grep "^$1|" ')]
assert len(at) == 1, len(at)
assert s.count('.ujfail106') == 0 and s.count('_rh_r6') == 0 and s.count('_rh_f4') > 0 and s.count('_rh_r4') == 3, (s.count('.ujfail106'), s.count('_rh_f4'), s.count('_rh_r4'))
lines[at[0]:at[0]] = h.rstrip('\n').split('\n')
s2 = '\n'.join(lines)
assert len(s2) == len(s) + len(h.rstrip('\n')) + 1
assert s2.count('.ujfail106') == 2 and s2.count('ujfail106.fired') == 1 and s2.count('_rh_r4') == 3 and s2.count('row() { grep "^$1|" ') == 1
assert s2.rindex('_rh_f4') < s2.index('.ujfail106') < s2.index('row() { grep "^$1|" '), 'order'
open(p, 'w').write(s2)
PY
rm -f "$SB/docker106.hook"
U1_106="$(ujcall106 "$UJS106" uj)"; U2_106="$(ujcall106 "$UJS106" lk)"; U3_106="$(ujcall106 "$UJN106" uj)"
rm -f "$SB/.ujfail106"; U4_106="$(ujcall106 "$UJS106" uj)"
check "$(bash -n "$SB/bin/docker" && echo parses)|$U0_106|$U1_106|$U2_106|$U3_106|$U4_106|$(grep -c '^uj$' "$SB/ujfail106.fired")|$( [ -e "$SB/.ujfail106" ] && echo armed || echo unarmed)" \
      "parses|uj=control:0|empty:1|uj=control:0|uj=control:0|uj=control:0|1|unarmed" \
  "106-infra j A8 the stand-in docker's uj hook: before the edit the trigger planted changes nothing; after it, armed, QF_PHP's uj call exits 1 with no output (counted once), its other modes and another source's uj answered as asked; removed, the uj call answered again"
: > "$SB/ujfail106.fired"

# ── The EXIT trap: this release's triggers and the clone's index lock removed first, then the chain's own cleanup (the trap as
# the chain set it, or as a development runner restored it). Its control: a lock planted in the clone makes the checkout V1's
# put-back runs (`git checkout <baseline>`) refuse, the clone's HEAD unmoved — A7's mechanism — and the trap's own function removes it. ──
cleanup106() { [ -n "${REPO:-}" ] && rm -f "$REPO/.git/index.lock"; [ -n "${SB:-}" ] && rm -f "$SB/.ujfail106" "$SB/web/.redirect106" "$SB/web/.redirect106lock" "$SB/evo/rh106-refuse.list"; return 0; }
TRAP0_106="$(trap -p EXIT | sed -E "s/^trap -- '(.*)' EXIT\$/\1/")"
case "$TRAP0_106" in ''|trap*|*"'"*) echo "trap106:unread" >> "$SB/anchor-missing" ;; *) trap -- "cleanup106; $TRAP0_106" EXIT ;; esac
REF0_106="$(git -C "$REPO" symbolic-ref -q --short HEAD 2>/dev/null || git -C "$REPO" rev-parse HEAD)"; HD0_106="$(git -C "$REPO" rev-parse HEAD)"
LKA106="$(lock106)"; : > "$REPO/.git/index.lock"
git -C "$REPO" checkout -q "$B106F" >/dev/null 2>&1; GLK106=$?
if [ "$GLK106" = 0 ]; then rm -f "$REPO/.git/index.lock"; git -C "$REPO" checkout -q "$REF0_106" >/dev/null 2>&1; echo "rh106:lock-did-not-stop-checkout" >> "$SB/anchor-missing"; fi
HD1_106="$(git -C "$REPO" rev-parse HEAD)"; LKB106="$(lock106)"; cleanup106
check "$( [ -n "$TRAP0_106" ] && trap -p EXIT | grep -cF -- "cleanup106; $TRAP0_106")|$LKA106|$LKB106|$( [ "$GLK106" != 0 ] && echo refused || echo "rc:$GLK106")|$( [ "$HD1_106" = "$HD0_106" ] && echo same)|$(lock106)" \
      "1|absent|present|refused|same|absent" \
  "106-infra k the EXIT trap runs cleanup106 first, then the chain's own (${TRAP0_106:-unread}); the clone's index lock, planted, makes git checkout of the baseline refuse with the clone's HEAD unmoved — what A7 gives V1's put-back — and cleanup106 removes it"
EVO0_106="$(evo_calls)"   # Evolution's requests before 106-pre: every later one is the jam's, the sender's, or a run's (none must be)

# ══════════════════════════════════════════════════════════════════════════════════════
# 106-pre — production's state before the deploy, made through the real writers (design-v2 §C 106-pre; A3–A6, A11)
# ══════════════════════════════════════════════════════════════════════════════════════
echo; echo "== 106-pre. production's state before the deploy: $BV106 deployed again, step 6 off, followup_* as production has them, the jam (J1–J3), the owner's interim of 18 Nov =="
# This part's numbers of 106's range (ph106 NN; 106-infra keeps 98 and 99 for A6's control), each in NAMES104 and FORMS106
# since 106-infra f, so every sweep reads them: 00 and 01 J1's fresh refused customers; 02 J1's stale refused one (the set
# F2 expires); 03 the number the deliveries between J1's refusals are stored on; 04 and 05 J2's present stale customers; 07
# the one number of J2's 400 gone rows; 08 J3's customer. 06 and 09 are this part's and unused. 10–97 are left to the later
# sections, a fresh number for every re-plant: a conversation's last_customer_at never moves back, and rh106-seed.php refuses
# a plant older than the customer's last message.
# ── This part's helpers, beside 106-infra's (none of them is defined there; none redefines one of 106-infra's). ──
plant106() {   # plant106 OPS-JSON — rh106-seed.php's book ops (the jam: enquiry, pair, delivery, gone; and the store-only claim-key
               # fixture) through the PIN's own classes ($H106/ref: bf29cbd's lib/ from Git), as the book's owner, under the clock, in
               # Kampala's zone (106-infra's seed106: a vault copy of its own, removed after; RH106_RANGE, this rehearsal's ten
               # digits, which the helper requires, given to that one run by seed106 itself, never exported). Its JSON line, or
               # none(<why>)
  seed106 "$H106/ref/$P" "$DATA" "$1" Africa/Kampala
}
seed106r() {   # seed106r OPS-JSON — the same helper as root, for the settings-file fixtures alone (fx_file, fx_file_back: a file root
               # or another user may own; rh106-seed.php keeps its owner and mode and puts both back) — a book op refuses any user but
               # the book's owner, so none runs this way. Under the clock. Its JSON line, or none(<why>)
  local o
  o="$(cd "$H106/v" && env DN_VAULT_FILE="$H106/v/seedr-vault.none" RH106_RANGE="${PR106#+}" LD_PRELOAD="$CLKPRE104" RH104_CLOCK_FILE="$CLKF104" \
        http_proxy= https_proxy= HTTP_PROXY= HTTPS_PROXY= NO_PROXY='*' "$PHPBIN" -d log_errors=0 "$H106/rh106-seed.php" "$H106/ref/$P" "$DATA" "$1" Africa/Kampala 2>"$H106/v/seedr.err")"
  case "$o" in '{'*) printf '%s' "$o" ;; *) echo "none($(head -c 160 "$H106/v/seedr.err"))" ;; esac
}
sd106() {   # sd106 SEED-LINE N FIELD — FIELD of the Nth entry (from 0) of a seed line's "done" (a list joined by commas), or unread
  python3 -I -c 'import json, sys
try:
    v = json.loads(sys.argv[1])["done"][int(sys.argv[2])][sys.argv[3]]
    print(",".join(str(x) for x in v) if isinstance(v, list) else (("true" if v else "false") if isinstance(v, bool) else v))
except Exception:
    print("unread")' "$1" "$2" "$3"
}
ag106() {   # ag106 SEED-LINE N SECONDS — the Nth entry's age_s (time() less its lca, the helper's own) against the plant's lca_ago_s:
            # SECONDS when within 5 s of it (a second may tick inside the op), else off:<age>
  local a; a="$(sd106 "$1" "$2" age_s)"
  case "$a" in ''|*[!0-9]*) echo unread ;; *) if [ "$a" -ge "$3" ] && [ "$a" -le $(( $3 + 5 )) ]; then echo "$3"; else echo "off:$a"; fi ;; esac
}
rwdk106() { tr ' ;=' '\n\n\n' <<<"$1" | awk -F@ -v i="$2" 'NF == 5 && $1 == i { printf "%s/%s/%s/%s", $2, $3, $4, $5; f = 1 } END { if (!f) printf "none" }'; }   # rwdk106 RWD-LINE ID — n3/nall/refusals/deliveries of one follow-up, from rh106q.py rwd
fsl106() { grep -oE '\[followup_send\] sent=[0-9]+ held=[0-9]+ failed=[0-9]+' "$1" 2>/dev/null | tail -n1 | sed -E 's/.*sent=([0-9]+) held=([0-9]+) failed=([0-9]+)$/\1:\2:\3/'; }   # fsl106 MASTER-OUT — the sender's own summary in a pass (7835906 cron/followup_send.php:362): sent:held:failed
f5d106() { echo "+$(( $(n103 "${2%%/*}") - $(n103 "${1%%/*}") ))/+$(( $(n103 "${2#*/}") - $(n103 "${1#*/}") ))"; }   # f5d106 BEFORE AFTER — U3's F5 count (follow-ups/drafts) moved by: +f/+d
apd106() {   # apd106 — the approved enquiry drafts on open follow-ups in the sender's order (approvedDrafts' decided_at, then the
             # draft's id): n=<count> fu=<each draft's follow-up id, comma-separated, or -> — read-only
  sqx "PRAGMA wal_checkpoint(TRUNCATE)" >/dev/null 2>&1
  python3 -I - "$DATA/plugin.sqlite3" <<'PY'
import sqlite3, sys
c = sqlite3.connect('file:' + sys.argv[1] + '?mode=ro', uri=True)
try:
    k = [r[1] for r in c.execute('PRAGMA table_info(followups)')]
    e = " AND COALESCE(f.kind,'enquiry')='enquiry'" if 'kind' in k else ''
    r = c.execute("SELECT d.followup_id FROM followup_drafts d JOIN followups f ON f.id = d.followup_id"
                  " WHERE d.status = 'approved' AND f.closed_at IS NULL" + e + " ORDER BY d.decided_at, d.id").fetchall()
    print('n=%d fu=%s' % (len(r), ','.join(str(x[0]) for x in r) or '-'))
except sqlite3.Error as x:
    print('unread:%s' % type(x).__name__)
finally:
    c.close()
PY
}
outsum106() { ( cd "$OUT106" 2>/dev/null && find . -type f ! -name '*.log' ! -name 'cash-*.txt' ! -name 'cih-rows-*' -print0 | LC_ALL=C sort -z | xargs -0 -r sha256sum ) | sha256sum | cut -c1-16; }   # outsum106 — the run folder's records (state, rollback-state, key files, backups) as bytes, a run's own readings aside (its log, its cash sheet, its row digests)
still106() { sqx "PRAGMA wal_checkpoint(TRUNCATE)" >/dev/null 2>&1; echo "$(data94)|$(kv106 "$(q106 cfgsha "$PD" "$DATA")" all)|$(outsum106)"; }   # still106 — the book's digest, the four settings places by sha256 (rh106q.py cfgsha), the run folder's records: nothing changed when it reads the same
order106() {   # order106 OUTPUT PREFIX… — ordered when each PREFIX begins exactly one line of OUTPUT and those lines come in the order
               # given; else the first PREFIX found other than once (count:N:…) or the order read (order:…). OUTPUT on stdin (a run's
               # terminal can pass the size an argument may have), compared as bytes
  python3 -I -c 'import os, sys
lines = sys.stdin.buffer.read().split(b"\n"); at = []
for a in sys.argv[1:]:
    p = os.fsencode(a)
    h = [i for i, l in enumerate(lines) if l.startswith(p)]
    if len(h) != 1:
        print("count:%d:%s" % (len(h), a.strip()[:48].encode("ascii", "replace").decode().replace(" ", "_"))); sys.exit(0)
    at.append(h[0])
print("ordered" if at == sorted(at) else "order:" + ",".join(str(x) for x in at))' "${@:2}" <<<"$1"
}
cond106() {   # cond106 LABEL SERVES KEY CHASE EN [RECORD] — a case's preconditions, asserted first (design-v2 §D), one check: SERVES
              # the live commit; KEY followup_auto_send and EN followup_enabled, on or off as the jobs read them (rh106q.py kycplaces'
              # eff: PluginConfig::read's three files, FILTER_VALIDATE_BOOLEAN); CHASE step 6 on or off by this rehearsal's ladder
              # reading (lk105's steps on); RECORD the latest rollback record as as_rb_last's mirror reads it over $OUT106 (rh106q.py
              # rblast, "last records"; default "- 0": no rollback of 5.18.106 on record)
  local k r c
  sqx "PRAGMA wal_checkpoint(TRUNCATE)" >/dev/null 2>&1
  k="$(q106 kycplaces "$PD" "$DATA" followup_auto_send,followup_enabled)"; r="$(q106 rblast "$OUT106" "$STATE106")"
  c="$(kv105 "$(lk105)" on | tr ',' '\n' | grep -cx chase)"
  check "$(live)|$(kv106 "$k" eff)|$( [ "$(n103 "$c")" -ge 1 ] && echo on || echo off)|$(kv106 "$r" last) $(kv106 "$r" records)" \
        "$2|followup_auto_send:$3;followup_enabled:$5|$4|${6:-- 0}" \
    "$1 the case's preconditions (design-v2 §D): $2 serving; followup_auto_send $3 and followup_enabled $5 as the jobs read them; step 6 (chase) $4 by this rehearsal's ladder reading; the latest rollback record '${6:-- 0}' (last, records) by as_rb_last's mirror"
}
srtcsv106() { tr ',' '\n' <<<"$1" | grep -v '^-$' | grep . | LC_ALL=C sort | paste -sd, -; }   # srtcsv106 CSV — sorted, '-' and blanks dropped
dlv106() {   # dlv106 TAG — one delivery on J1's channel (sales), stored now through the pin's ConversationService::storeMessage as the
             # sender stores what it delivered: outbound, WhatsApp's id, sent_at and created_at the clock's (design-v2 §A5) —
             # planted, not a fixture: storeMessage is the sender's own writer. Prints the message's id, or unread
  sd106 "$(plant106 "[{\"op\":\"delivery\",\"phone\":\"$J1D106\",\"channel\":\"sales\",\"name\":\"RH106SECRET-J1D\",\"text\":\"RH106SECRET-J1 delivery $1\"}]")" 0 message
}
csb1run106() {   # csb1run106 LABEL SCRIPT -- ACTION… — 5.18.105's lkb1run105 on this release's runner: a deploy whose DEPLOY is typed
                 # only once ACTION has run while the question waited (run106c --hold, the terminal streamed to a file of its own,
                 # swept with the others); CSB1OUT106 the run's output, CSB1RC106 the tool's exit code for ACTION — call it directly
  local lab="$1" scr="$2" st hf op
  shift 2; [ "${1:-}" = -- ] && shift
  st="$R106/logs/stream-$lab.txt"; hf="$R106/logs/hold-$lab"; rm -f "$hf" "$R106/logs/act-$lab.rc"; : > "$st"
  ( i=0; while ! grep -q 'Type DEPLOY' "$st" 2>/dev/null && [ "$i" -lt 6000 ]; do sleep 0.05; i=$((i+1)); done
    "$@" > "$R106/logs/act-$lab.txt" 2>&1; sfrc104 > "$R106/logs/act-$lab.rc"; : > "$hf" ) & op=$!
  fresh106; CSB1OUT106="$(R106_STREAM="$st" run106c --answer DEPLOY --hold "$hf" --script "$scr")"
  wait "$op" 2>/dev/null
  CSB1RC106="$(cat "$R106/logs/act-$lab.rc" 2>/dev/null)"
}

# ── 106-pre1: 5.18.105 deployed again, through the real deploy-5.18.105.sh, as production's is when 5.18.106 comes (105-7e
# ended with a rollback: 994d660 serving, the ladder at L2, 5.18.105's files on disk). Run as 5.18.105's rehearsal ran it —
# run105c, no RH106_TOUCH (A1's shim is 5.18.106's runs' alone), so its COPY_AT is the kernel's, as there. ──
cond106 "106-pre1 cond" "$P104" off on off
fresh105; OUT="$(run105c --answer DEPLOY --redeploy)"
# deploy-5.18.105.sh:14708 the verdict ("  $EXPECTED_VERSION ($MODE): PASSED."), :7099 LK-CA1's ok line
check "$(fails "$OUT")$(has "$OUT" "  $V105 (deploy): PASSED.")|$(live)|$(okline "$OUT" LK-CA1 "after the copy every ladder key")|$(keyf105)" "0yes|$P105|yes|key" \
  "106-pre1a $BV106 deployed again through the real deploy-$BV106.sh, DEPLOY typed, --redeploy (its files on disk after 105-7e's rollback): PASSED, $P105 serving, LK-CA1 ok (every ladder key as found), its LK key kept"
# Its --after-only, the copy where the deploy recorded it — never moved back: A1 rewrites no state line in 106, so neither
# copy_back105 nor set_sched runs (aft105 calls both). R19 is then conclusive or a note, and PASSED either way
# (deploy-5.18.105.sh:13761-13764); every other "since the copy" reading of it only counts fewer lines with the later copy time.
idle104; EA106="$(evo_calls)"; fresh105; OUT="$(run105c --after-only)"
# deploy-5.18.105.sh:14708
check "$(fails "$OUT")$(has "$OUT" "  $V105 (after): PASSED.")|$(q105 stcmp "$(onall105 "$OUT")" "$(expect105 $L2ON105)")|$(( $(evo_calls) - EA106 ))" "0yes|same|0" \
  "106-pre1b $BV106's --after-only under the clock, the copy as its deploy recorded it: PASSED — steps 1–6 judged, the rest OFF — not judged; Evolution not called"
qcheck106 "106-pre1c"
# 5.18.106's PREV_STATE and A14 read the baseline's records at <records>/dnb-5.18.105 (deploy-5.18.106.sh:1538): 5.18.105's
# run folder, as 5.18.105's rehearsal linked 5.18.104's (its pre-c).
if [ -e "$RECS/dnb-$BV106" ] && [ ! -L "$RECS/dnb-$BV106" ]; then echo "rh106:records-dnb-$BV106-not-a-link" >> "$SB/anchor-missing"; else ln -sfn "$OUT105" "$RECS/dnb-$BV106"; fi
check "$(readlink "$RECS/dnb-$BV106")|$(grep -c '^COPY_AT=[0-9]' "$RECS/dnb-$BV106/state-$BV106.env" 2>/dev/null | awk '{print ($1 > 0) ? "copyat" : "none"}')" "$OUT105|copyat" \
  "106-pre1d $RECS/dnb-$BV106 is 5.18.105's run folder, its state file carrying the copy's COPY_AT — where $V106's PREV_STATE and A14 read their baseline's records"

# ── 106-pre2: step 6 off through the installed tool — production's ladder before the deploy is steps 1–5 on, step 6 off (A11). ──
cond106 "106-pre2 cond" "$B106" off on off
OUT="$(sf104c off chase)"; LK2_106="$(lk105)"
# 7835906 tools/sales_followup.php:484 ("OFF <step>")
check "$(sfrc104)|$(grep -cx 'OFF chase' <<<"$OUT")|$(srtcsv106 "$(kv105 "$LK2_106" on)")|$(kv105 "$LK2_106" held)" "0|1|ack,list,report,tracking,voice-measure|0" \
  "106-pre2a off chase through the installed tool, as production's step 6 is off: OFF chase, exit 0; steps 1–5 on by this rehearsal's ladder reading (report, ack, tracking, list, voice-measure), nothing held"

# ── 106-pre3: production's followup_* settings (A11), through the installed set_config.php as the book's owner, under the
# clock: followup_enabled 1, followup_auto_send 1, followup_daily_cap 3, followup_run_limit 2 — each by its own line and then
# by U4's reading on a copy (a 5.18.106 --check, read-only: GO, stage A holding with step 6 off and followup_enabled on). ──
cond106 "106-pre3 cond" "$B106" off off off
for kv3_106 in followup_enabled=1 followup_auto_send=1 followup_daily_cap=3 followup_run_limit=2; do
  O3_106="$(setcfg106 "${kv3_106%%=*}" "${kv3_106#*=}")"
  # tools/set_config.php:670 (bf29cbd's, 7835906's alike): "  <key> = <value>" once it has saved
  check "$(setrc106)|$(grep -cxF "  ${kv3_106%%=*} = ${kv3_106#*=}" <<<"$O3_106")" "0|1" \
    "106-pre3a ${kv3_106%%=*} set to ${kv3_106#*=} (production's, A11) through the installed set_config.php as the book's owner, under the clock: its line '  ${kv3_106%%=*} = ${kv3_106#*=}', exit 0"
done
HRS106="$(kv106 "$(q106 u2u3 "$DATA/plugin.sqlite3" "$PD" "$(fnow104)")" hours)"   # followup_max_age_hours as the jobs read it (unset: the release's default)
S3_106="$(still106)"; fresh106; OUT="$(run106c --check)"
# deploy-5.18.106.sh:15907 GO; :7409 CS-G1's ok; :7981 U4's note; :8006 FE-G1's headline
check "$(checkgo106 "$OUT")|$(okline "$OUT" CS-G1 'none of the three claim switches')|$(noteline "$OUT" U4 'followup_enabled on, followup_auto_send on (present in: k,s — ' 'followup_daily_cap 3, followup_run_limit 2, followup_scan_limit ' "followup_max_age_hours $HRS106 — F2 and F3 active" 'the ledger record, StaffJobsGate::applies Uganda')|$(has "$OUT" 'FE-G1 FOLLOWUP_ENABLED READS OFF')|$(still106)" \
      "yesnono0|0|0|samekey|0|yes|yes|no|$S3_106" \
  "106-pre3b U4's reading on a copy (a $V106 --check, read-only): GO — followup_enabled on, followup_auto_send on in the file and its store row (k,s), followup_daily_cap 3, followup_run_limit 2, followup_max_age_hours $HRS106 (unset: the default) — F2 and F3 active, the ledger recording (step 6 off); CS-G1 ok, no FE-G1; the book, the four settings places and the run folder unchanged"
cond106 "106-pre3c" "$B106" on off on

# ── 106-pre4: J1 — approved drafts Evolution refuses (HTTP 400 to sendText) while the number delivers others (A4, A5, A6). Two
# for fresh customers (U1 classes them "F1 closes" at stage A) and one for a stale one (F2's), each opened, drafted and
# approved through the pin's FollowUpService, their numbers in the stand-in's refusal list; five passes of 5.18.105's own
# sender (master, followup_send DUE) in one sending window (A4), a delivery stored before each (A5: the clock +60 s, the
# delivery, the clock +241 s, the pass), so that every refusal falls after a delivery and F1 counts it. The chain's own
# approved draft of 9 Nov (decided first) heads pass 1 and is counted. ──
cond106 "106-pre4 cond" "$B106" on off on
J1A106="$(ph106 00)"; J1B106="$(ph106 01)"; J1S106="$(ph106 02)"; J1D106="$(ph106 03)"
J1OPS106="$(python3 -I - "$J1A106" "$J1B106" "$J1S106" <<'PY'
import json, sys
def enq(ph, t, ago):
    return {"op": "enquiry", "phone": ph, "channel": "sales", "name": "RH106SECRET-J1" + t, "text": "RH106SECRET-J1" + t + " asks what the dish costs",
            "lca_ago_s": ago, "upto": "approve", "body": "RH106SECRET-J1" + t + " follow-up", "by": "RH106SECRET-approver"}
print(json.dumps([enq(sys.argv[1], "A", 3600), enq(sys.argv[2], "B", 3600), enq(sys.argv[3], "S", 420 * 3600)]))
PY
)"
AP0_106="$(apd106)"; C106="$(n103 "$(kv106 "$AP0_106" n)")"   # the chain's approved enquiry drafts, before J1's
J1P106="$(plant106 "$J1OPS106")"
J1FU106="$(for i106 in 0 1 2; do sd106 "$J1P106" "$i106" id; done | paste -sd' ' -)"
AP1_106="$(apd106)"
check "$(jf105 "$J1P106" ops)|$(jf105 "$J1P106" uid)|$(sd106 "$J1P106" 0 approved)$(sd106 "$J1P106" 1 approved)$(sd106 "$J1P106" 2 approved)|$(ag106 "$J1P106" 0 3600),$(ag106 "$J1P106" 1 3600),$(ag106 "$J1P106" 2 1512000)|$(tr ' ' '\n' <<<"$J1FU106" | grep -cE '^[0-9]+$')" \
      "3|1000|111|3600,3600,1512000|3" \
  "106-pre4a J1's three follow-ups planted through the pin's FollowUpService as the book's owner (the customer's message stored, opened, one SEND draft each, approved): two fresh (the customer last wrote 1 h before), one stale (420 h, past the limit: F2's) — planted, not fixtures"
RL106="$(refuse106 "$J1A106" "$J1B106" "$J1S106")"   # the stand-in's refusal list (A6): J1's three numbers; kept for 106-6's 5.18.106 sender pass (F1 closes there), removed by cleanup106
check "$( [ "$C106" -ge 1 ] && echo chain || echo "chain:$C106")|$(kv106 "$AP1_106" n)|$(kv106 "$AP1_106" fu | tr ',' '\n' | tail -n 3 | paste -sd' ' -)|$(kv106 "$AP1_106" fu | cut -d, -f1 | grep -cxF -f <(tr ' ' '\n' <<<"$J1FU106"))|$RL106|$(stat -c %a "$SB/evo/rh106-refuse.list" 2>/dev/null)" \
      "chain|$(( C106 + 3 ))|$J1FU106|0|3|644" \
  "106-pre4b the sender's order before pass 1 (approvedDrafts by decided_at, this rehearsal's reading): the chain's own approved draft(s) first ($C106: the 9 Nov one among them), then J1's three; the stand-in Evolution lists J1's three numbers for refusal"
RF4_106="$(refused106)"
for k106 in 1 2 3 4 5; do
  clk_to104 $(( $(fnow104) + 60 )) || true   # 60 s after the plant, or after the pass before (A5)
  DMK106="$(dlv106 "$k106")"
  RFK106="$(refused106)"
  if [ "$k106" = 1 ]; then sender106 "106-pre4c-p1 J1's pass 1 of 5, in the next sending window" --window; KWD106="$(kday104 "$SS106_AT")"
  else sender106 "106-pre4c-p$k106 J1's pass $k106 of 5" --step 241; fi
  RWK106="$(q106 rwd "$DATA/plugin.sqlite3" $J1FU106)"; FSK106="$(fsl106 "$SS106_OUT")"
  G106=""; W106=""; m106=$(( k106 < 3 ? k106 : 3 ))
  for f106 in $J1FU106; do
    r106="$(rwdk106 "$RWK106" "$f106")"
    G106="$G106 $(cut -d/ -f1-3 <<<"$r106")/$( [ "$(n103 "$(cut -d/ -f4 <<<"$r106")")" -ge "$k106" ] && echo dl || echo "dl:$(cut -d/ -f4 <<<"$r106")")"
    W106="$W106 $m106/$k106/$k106/dl"
  done
  if [ "$k106" = 1 ]; then
    FSG106="$(( $(n103 "$(cut -d: -f1 <<<"$FSK106")") + $(n103 "$(cut -d: -f2 <<<"$FSK106")") )):$(cut -d: -f3 <<<"$FSK106")|$(apd106)"; FSW106="$C106:3|n=3 fu=$(tr ' ' ',' <<<"$J1FU106")"
  else FSG106="$FSK106"; FSW106="0:0:3"; fi
  check "$(case "$DMK106" in ''|*[!0-9]*) echo "unread:$DMK106" ;; *) echo stored ;; esac)|${G106# }|$(( $(refused106) - RFK106 ))|$FSG106" \
        "stored|${W106# }|3|$FSW106" \
    "106-pre4d-p$k106 J1's pass $k106 of 5: $( [ "$k106" = 1 ] && echo 'a delivery stored 60 s after the plant' || echo 'a delivery stored 60 s after the pass before'), then each of J1's three refused once more (HTTP 400, Evolution's JSON) — by this rehearsal's mirror of refusedWhileDelivering n=$m106 (to 3), $k106 refusals each counted after a delivery, $k106 failed events whose detail matches F1's LIKE (A6's pass-level control: 7835906's request() records 'Bad Request [HTTP 400 on POST /message/sendText/…]', lib/EvolutionApiService.php:898-904), a delivery in each interval; the sender's own summary $( [ "$k106" = 1 ] && echo "sent or closed the chain's $C106 and failed J1's 3, leaving J1's three alone approved" || echo "sent=0 held=0 failed=3")"
done
RW5_106="$RWK106"; SCH5_106="$(sched104 followup_send)|$(sched104 followup_close)"   # J1's last pass: no master runs after it until the deploy
check "$(kv106 "$RW5_106" min)|$(kv106 "$RW5_106" ge3)|$(for f106 in $J1FU106; do rwdk106 "$RW5_106" "$f106" | cut -d/ -f3; done | paste -sd, -)|$(( $(refused106) - RF4_106 ))|$(grep -c . "$SB/evo/rh106-refuse.list" 2>/dev/null)" \
      "3|3/3|5,5,5|15|3" \
  "106-pre4e J1's positive control after the jam: each refused draft has 5 failed 400 events (4 or more), and this rehearsal's mirror of refusedWhileDelivering reads 3 or more for each of the three (F1's own count, counted to 3); the stand-in refused 15 sends, its list still J1's three"

# ── 106-pre5: J2 (A3) — present stale rows and the 400 gone rows, the order asserted. The gone rows: FollowUpService::open on
# conversation ids 9 000 001–9 000 400, which no conversation holds (asserted before and after) — the state a prune leaves;
# wa_prune_history.php is never run (F14). One number for all of them; their last_customer_at older than every open enquiry
# follow-up's in the book — the chain's 9 Nov row, J1's stale one and J2's present ones included — so the close job's first
# pass spends both of its 200-row lists on gone rows and expires nothing (UJ-ON's NOT YET, 106-5f). Follow-ups can never be
# deleted (fu_never_deleted): these are undone by 5.18.106's own close job, F3, in 106-5 — never by this rehearsal. Planted
# with master idle: nothing runs master between here and 106-4's copy (A2, pig106). ──
cond106 "106-pre5 cond" "$B106" on off on
sqx "PRAGMA wal_checkpoint(TRUNCATE)" >/dev/null 2>&1
GL106="$(python3 -I - "$DATA/plugin.sqlite3" "$(fnow104)" <<'PY'
import calendar, sqlite3, sys, time
c = sqlite3.connect('file:' + sys.argv[1] + '?mode=ro', uri=True)
try:
    k = [r[1] for r in c.execute('PRAGMA table_info(followups)')]
    e = " AND COALESCE(kind,'enquiry')='enquiry'" if 'kind' in k else ''
    m = c.execute("SELECT MIN(last_customer_at) FROM followups WHERE closed_at IS NULL AND COALESCE(last_customer_at,'') <> ''" + e).fetchone()[0]
    t = int(sys.argv[2]) - 180 * 86400
    if m:
        t = min(t, calendar.timegm(time.strptime(str(m)[:19], '%Y-%m-%d %H:%M:%S')) - 86400)
    print(time.strftime('%Y-%m-%d %H:%M:%S', time.gmtime(t)))
except (sqlite3.Error, ValueError) as x:
    print('unread:%s' % type(x).__name__)
finally:
    c.close()
PY
)"   # the gone rows' last_customer_at: a day before the oldest open enquiry follow-up's, and at least 180 days before the clock
SO0_106="$(q106 staleorder "$DATA/plugin.sqlite3" "$HRS106" "$(fnow104)" 9000001 9000400)"
J2A106="$(ph106 04)"; J2B106="$(ph106 05)"; J2G106="$(ph106 07)"
J2OPS106="$(python3 -I - "$J2A106" "$J2B106" "$J2G106" "$GL106" <<'PY'
import json, sys
a, b, g, gl = sys.argv[1:5]
def old(ph, t, ago):
    return {"op": "enquiry", "phone": ph, "channel": "sales", "name": "RH106SECRET-J2" + t, "text": "RH106SECRET-J2" + t + " asked once",
            "lca_ago_s": ago, "upto": "open"}
print(json.dumps([old(a, "A", 380 * 3600), old(b, "B", 400 * 3600),
                  {"op": "gone", "from": 9000001, "count": 400, "phone": g, "channel": "sales", "lca": gl}]))
PY
)"
J2P106="$(plant106 "$J2OPS106")"
J2FU106="$(for i106 in 0 1; do sd106 "$J2P106" "$i106" id; done | paste -sd' ' -)"
SO1_106="$(q106 staleorder "$DATA/plugin.sqlite3" "$HRS106" "$(fnow104)" 9000001 9000400)"
check "$(grep -cE '^[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}$' <<<"$GL106")|$(kv106 "$SO0_106" planted_open)/$(kv106 "$SO0_106" planted_conv)|$(jf105 "$J2P106" ops)|$(ag106 "$J2P106" 0 1368000),$(ag106 "$J2P106" 1 1440000)|$(sd106 "$J2P106" 2 opened)|$(sd106 "$J2P106" 2 absent_after)" \
      "1|0/0|3|1368000,1440000|400|400" \
  "106-pre5a J2 planted as the book's owner through the pin's classes: before it, no follow-up and no conversation on ids 9 000 001–9 000 400; two present stale follow-ups (customers quiet 380 h and 400 h: past the limit, younger than every gone row), then 400 follow-ups opened in one transaction on ids no conversation holds, older than every open enquiry follow-up — 400 ids still holding no conversation after"
check "$(kv106 "$SO1_106" first400_gone)|$(kv106 "$SO1_106" gone_first)|$(kv106 "$SO1_106" gone_older)|$(kv106 "$SO1_106" planted_open)|$(kv106 "$SO1_106" planted_conv)|$(kv106 "$SO1_106" xs_first_cancel)|$(kv106 "$SO1_106" xs_first_expire)|$( [ "$(n103 "$(kv106 "$SO1_106" xs_all)")" -ge 401 ] && echo 401+ || echo "$(kv106 "$SO1_106" xs_all)")|$( [ "$(n103 "$(kv106 "$SO1_106" stale_present)")" -ge 3 ] && echo 3+ || echo "$(kv106 "$SO1_106" stale_present)")|$( [ "$(n103 "$(kv106 "$SO1_106" p1_cn)")" -ge 200 ] && echo 200+ || echo "$(kv106 "$SO1_106" p1_cn)")|$(kv106 "$SO1_106" p1_ex)" \
      "400/400|yes|yes|400|0|200|0|401+|3+|200+|0" \
  "106-pre5b the stale and gone order (A3), by this rehearsal's own reading at followup_max_age_hours $HRS106: the 400 oldest stale rows all gone, every stale gone row before every stale present row, every open gone row older than every open present one; the 400 planted rows open, no conversation on their ids; U3's forecast (expireStale alone): its first pass cancels 200, expires 0, $(kv106 "$SO1_106" xs_all) stale in all (401 or more); the close job's first pass, its main loop then expireStale: 200 or more cancelled, none expired (106-5f's NOT YET)"
PIG0_106="$(pig106)"   # the installed public.php's piggyback record at the start of the plant-to-copy span (A2): unchanged at 106-4's copy

# ── 106-pre6: J3 (RH106-12) — one fresh follow-up holding two approved drafts of the same attempt (FollowUpService::draft and
# ::approve twice), planted with master idle and kept fresh: nothing sends until 106-6's sender pass, so stage A's U1 classes
# its second draft "held" and U3 counts it in F5, and the rollback's AS-RB0 finds the pair (F5's capitals line). ──
cond106 "106-pre6 cond" "$B106" on off on
F5B106="$(kv106 "$(q106 u2u3 "$DATA/plugin.sqlite3" "$PD" "$(fnow104)")" f5_multi)"
J3PH106="$(ph106 08)"
J3P106="$(plant106 "[{\"op\":\"pair\",\"phone\":\"$J3PH106\",\"channel\":\"sales\",\"name\":\"RH106SECRET-J3\",\"text\":\"RH106SECRET-J3 asks about the install\",\"lca_ago_s\":3600,\"body\":\"RH106SECRET-J3 first follow-up\",\"body2\":\"RH106SECRET-J3 second follow-up\",\"by\":\"RH106SECRET-approver\"}]")"
J3FU106="$(sd106 "$J3P106" 0 id)"; J3DR106="$(sd106 "$J3P106" 0 drafts)"
F5A106="$(kv106 "$(q106 u2u3 "$DATA/plugin.sqlite3" "$PD" "$(fnow104)")" f5_multi)"
check "$(jf105 "$J3P106" ops)|$(sd106 "$J3P106" 0 approved)|$(tr ',' '\n' <<<"$J3DR106" | grep -cE '^[0-9]+$')|$(ag106 "$J3P106" 0 3600)|$(f5d106 "$F5B106" "$F5A106")|$(sched104 followup_send)|$(sched104 followup_close)|$(pig106)" \
      "1|2|2|3600|+1/+2|$SCH5_106|$PIG0_106" \
  "106-pre6a J3: one fresh follow-up (the customer last wrote 1 h before) with two SEND drafts of the same attempt, both approved, through the pin's FollowUpService — U3's F5 count up by one follow-up and two drafts ($F5B106 → $F5A106); master idle since J1's last pass (followup_send's and followup_close's entries as that pass left them) and the piggyback record unchanged"

# ── 106-pre7: the interim of 18 Nov, as the owner ran it: followup_enabled 0 (set_config, as the book's owner), then on chase
# (yes typed at the terminal), then --check chase. ──
cond106 "106-pre7 cond" "$B106" on off on
O7_106="$(setcfg106 followup_enabled 0)"
# tools/set_config.php:670
check "$(setrc106)|$(grep -cxF '  followup_enabled = 0' <<<"$O7_106")" "0|1" \
  "106-pre7a the interim, first: followup_enabled 0 through the installed set_config.php as the book's owner — its line '  followup_enabled = 0', exit 0"
O7_106="$(sf104c --tty yes on chase)"
# 7835906 tools/sales_followup.php:224 (the question), :261 ("ON <step>: <keys>")
check "$(sfrc104)|$(has "$O7_106" 'Type yes to switch the quote chase on:')|$(grep -c '^ON chase: ' <<<"$O7_106")" "0|yes|1" \
  "106-pre7b the interim, then: on chase through the installed tool, yes typed at the terminal — ON, exit 0"
O7_106="$(sf104c --check chase)"
# 7835906 tools/sales_followup.php:171 ("<step>: <status>"), :180 (exit 3 only on FAIL)
check "$(sfrc104)|$(grep -cE '^ *chase: (PASS|NOT_YET)' <<<"$O7_106")" "0|1" \
  "106-pre7c the interim, last: --check chase — judged (PASS or NOT_YET), exit 0"
cond106 "106-pre7d" "$B106" on on off
check "$(srtcsv106 "$(kv105 "$(lk105)" on)")|$(kv105 "$(lk105)" held)" "ack,chase,list,report,tracking,voice-measure|0" \
  "106-pre7e the interim's state: steps 1–6 on by this rehearsal's ladder reading (chase among them), nothing held — what the owner's order before the deploy starts from"

# ══════════════════════════════════════════════════════════════════════════════════════
# 106-1 — the owner's order before the deploy, through the script's own refusals (design-v2 §C 106-1)
# ══════════════════════════════════════════════════════════════════════════════════════
echo; echo "== 106-1. the owner's order before the deploy, through $V106's own refusals — CS-G1, FE-G1, U4's capitals note, CS-B1 — each with its weakened copy caught =="
# The weakened copies, each anchored on the script's own text (106-infra's anc106/weak106; ancc106 the control of the
# controls): CS1 cs_check returning at once (deploy-5.18.106.sh:7405-7406); FE1 uj_fe_g1 returning at once (:8003-8004); U41
# U4's capitals note's line removed (:7983, read from the script); CSB the CS-B1 call removed (:7465, read from the script).
anc106 cs1 'cs_check() {
  local c="$1" f
' 'cs_check() {
  return 0   # rh106 CS1: cs_check returns at once
  local c="$1" f
'
anc106 fe1 'uj_fe_g1() {
  uj_ok "$UJ_A" && [ "$(kv "$UJ_A" en)" = "off" ] || return 0
' 'uj_fe_g1() {
  return 0   # rh106 FE1: uj_fe_g1 returns at once
  uj_ok "$UJ_A" && [ "$(kv "$UJ_A" en)" = "off" ] || return 0
'
U41L106="$(grep -m1 -F '  [ "$(kv "$o" en)" = "off" ] || [ -z "$(uj_gate_a "$o")" ] || note "U4 UJ-ON WILL NOT JUDGE F3 FOR THIS DEPLOY:' "$DEPLOY106")"
anc106 u41 "$U41L106" "  :   # rh106 U41: U4's capitals note removed"
CSBL106="$(grep -m1 -F '  cs_check CS-B1 "$o"   # CS-B1' "$DEPLOY106")"
anc106 csb "$CSBL106" "  :   # rh106 CSB: CS-B1's call removed (LK-B1 judges alone)"
check "$(ancc106)|$(grep -c . <<<"$U41L106")$(grep -c . <<<"$CSBL106")" "0|$(ls "$AN106"/*.a | wc -l | tr -d ' ')|-|11" \
  "106-1 anchors: each anchor of the weakened copies so far (CS1, FE1, U41, CSB among them) is the script's own text, found in it exactly once, each replacement different; U41's and CSB's lines read from the script"
# U4's capitals note (deploy-5.18.106.sh:7983), its clause ", ANSWER ANYTHING BUT DEPLOY BELOW" in a deploy only; uj_gate_a (:7935-7941) names the age limit
U4C106="U4 UJ-ON WILL NOT JUDGE F3 FOR THIS DEPLOY: followup_max_age_hours was not a positive number at stage A. STAGE A'S READING IS KEPT IN THE STATE FILE FOR THE LIFE OF THE DEPLOY, SO F3 WOULD STAY UNPROVEN AND THE STEPS THAT WAIT FOR IT (6, 11, 12) WOULD FOLLOW WITHOUT THAT PROOF; AFTERWARDS A JUDGED UJ-ON NEEDS A ROLLBACK AND THEN A --redeploy. TO HAVE IT JUDGED, DO NOT DEPLOY ON THIS READING: PUT IT RIGHT"
# CS-G1's and CS-B1's printed commands: the off command (deploy-5.18.106.sh:7418, :7423, :7428, $QF_WRAPPER at :7205) and the
# --check again (:7432 through cs_check_cmd, :7399, and LOGW, :1563)
OFFW106="      cd $REPO && bash scripts/sales-followup.sh off"; CHKL106="      cd $REPO && bash $F106 --check $(LOGW106 check)"

# ── 106-1a: --check over the interim: CS-G1 NO-GO, quote_chase_enabled present in the file and its store row (k,s); the off
# command alone; nothing changed (the book, the four settings places and the run folder by sha256). ──
cond106 "106-1a cond" "$B106" on on off
D1A_106="$(data94)"; S1A_106="$(still106)"; fresh106; OUT1A_106="$(run106c --check)"; OUT="$OUT1A_106"
# deploy-5.18.106.sh:7434 (the refusal), :1689-1690 (stop's lines)
nogo106 "106-1a CS-G1 --check over the interim: NO-GO — a claim switch is present (quote_chase_enabled@k,s), exit 1, nothing asked, nothing changed" \
        "NO-GO (CS-G1): a claim switch is present (quote_chase_enabled@k,s) — until $V106 serves none may be, with any value" "$OUT" "$D1A_106" "$B106"
# deploy-5.18.106.sh:7413, :7414, :7427-7428, :7431-7432, :7434, :1690
check "$(order106 "$OUT" "  CS-G1 A CLAIM SWITCH IS PRESENT: quote_chase_enabled@k,s (key@place — k <data>/kyc_config.json, s its store row; no value is read out). " "  Turn each one off yourself, highest step first, each its own command, alone, " "  step 6 (chase) — off chase also turns step 10 (cold) off:" "$OFFW106 chase" "  Then run --check again, its own command, alone:" "      cd $REPO && bash $F106 --check " "  STOP: NO-GO (CS-G1): " "  Nothing was changed. Send the log file.")|$(grep -cxF "$OFFW106 chase" <<<"$OUT")|$(grep -cxF "$CHKL106" <<<"$OUT")|$(grep -cE '^      cd .* && bash scripts/sales-followup\.sh off offers-(live|test)$' <<<"$OUT")|$(noteline "$OUT" LK-G1 "the ladder's keys as found")|$(has "$OUT" 'NO-GO (FE-G1)')|$(still106)" \
      "ordered|1|1|0|yes|no|$S1A_106" \
  "106-1a' CS-G1's refusal in order: the headline naming quote_chase_enabled@k,s, step 6's off command alone on its line, then --check again with its own log, alone — no step 11 or 12 command; LK-G1 read first (a note); FE-G1 not reached; the book, the four settings places and the run folder byte for byte as before"
MW106="$(weak106 cs1 cs1)"; fresh106; OUT="$(R106_MUTANT_OUT=1 run106c --script "$MW106" --check)"; rm -f "$MW106"
check "$(has "$OUT" 'A CLAIM SWITCH IS PRESENT')$(has "$OUT" 'NO-GO (CS-G1)')|$(has "$OUT" 'NO-GO (FE-G1): followup_enabled reads off')|$(went106 "$OUT")|$(live)|$(still106)" \
      "nono|yes|nono|$B106|$S1A_106" \
  "106-1a'' CS1, the copy whose cs_check returns at once, on the k,s state: no claim switch found and no CS-G1 refusal — the run goes on past it to the next gate, FE-G1's refusal (followup_enabled is off in the interim) (caught by 106-1a's CS-G1 text); nothing changed"

# ── 106-1b: CS-G1's off command run as printed (the owner's order: step 6 off first). It runs before the variants (design-v2
# 106-1 steps 2–3): a store-only quote_chase_enabled (chase@s) cannot be planted while the store row holds the key. ──
cond106 "106-1b cond" "$B106" on on off
OFFL106="$(printed "$OUT1A_106" "cd $REPO && bash scripts/sales-followup.sh off chase")"
O1B_106="$(run_line106c "" "$OFFL106")"
check "$( [ "      $OFFL106" = "$OFFW106 chase" ] && echo printed)|$(rc106)|$(grep -cx 'OFF chase' <<<"$O1B_106")|$(srtcsv106 "$(kv105 "$(lk105)" on)")" \
      "printed|0|1|ack,list,report,tracking,voice-measure" \
  "106-1b CS-G1's off command run exactly as printed, under the clock: OFF chase, exit 0 — steps 1–5 on by this rehearsal's ladder reading"
cond106 "106-1b1" "$B106" on off off

# ── 106-1c: the store-only claim keys (a FIXTURE: no production writer leaves a claim key in the store row alone) —
# offers_live_enabled, offers_test_enabled and quote_chase_enabled put into the store row alone through the store's own
# load() and save() (rh106-seed.php fx_store_keys); kyc_config.json untouched. CS-G1 reads s too: three commands, highest
# step first, each alone. CS1 on this s-only state. Then undone (fx_store_keys_undo), and LK and CS-G1 read clean. ──
cond106 "106-1c cond" "$B106" on off off
DG1C_106="$(digest105)"; CF1C_106="$(q106 cfgsha "$PD" "$DATA")"
X1C_106="$(plant106 '[{"op":"fx_store_keys","keys":{"offers_live_enabled":"1","offers_test_enabled":"1","quote_chase_enabled":"1"}}]')"
KP1C_106="$(q106 kycplaces "$PD" "$DATA" offers_live_enabled,offers_test_enabled,quote_chase_enabled)"
F1C_106="$(tr '+' ' ' <<<"$(kv106 "$KP1C_106" cs)")"
check "$(jf105 "$X1C_106" ops)|$(sd106 "$X1C_106" 0 fixture)|$(sd106 "$X1C_106" 0 file)|$(kv106 "$KP1C_106" cs)|$(kv106 "$(q106 cfgsha "$PD" "$DATA")" k)" \
      "1|true|unchanged|offers_live_enabled@s+offers_test_enabled@s+quote_chase_enabled@s|$(kv106 "$CF1C_106" k)" \
  "106-1c the fixture: the three claim switches in the store row alone (this rehearsal's reading of cs_found: each @s), kyc_config.json byte for byte as before"
D1C_106="$(data94)"; S1C_106="$(still106)"; fresh106; OUT="$(run106c --check)"
# deploy-5.18.106.sh:7434
nogo106 "106-1c1 CS-G1 --check over the store-only keys: NO-GO — the three present in the store row (s), exit 1, nothing changed" \
        "NO-GO (CS-G1): a claim switch is present ($F1C_106) — until $V106 serves none may be, with any value" "$OUT" "$D1C_106" "$B106"
# deploy-5.18.106.sh:7413, :7417-7418, :7422-7423, :7427-7428, :7431-7432, :7434
check "$(order106 "$OUT" "  CS-G1 A CLAIM SWITCH IS PRESENT: $F1C_106 (key@place — " "  step 12 (offers-live) — off offers-live also pauses a running offer:" "$OFFW106 offers-live" "  step 11 (offers-test) — off offers-test also turns offers-live off and pauses a running offer:" "$OFFW106 offers-test" "  step 6 (chase) — off chase also turns step 10 (cold) off:" "$OFFW106 chase" "  Then run --check again, its own command, alone:" "      cd $REPO && bash $F106 --check " "  STOP: NO-GO (CS-G1): ")|$(for w106 in offers-live offers-test chase; do grep -cxF "$OFFW106 $w106" <<<"$OUT"; done | paste -sd, -)|$(grep -cxF "$CHKL106" <<<"$OUT")|$(still106)" \
      "ordered|1,1,1|1|$S1C_106" \
  "106-1c2 CS-G1 names what this rehearsal reads ($F1C_106) and prints three commands, highest step first — offers-live, offers-test, chase — each alone on its line with what its off also does, then --check again; the book, the four places and the run folder unchanged"
MW106="$(weak106 cs1 cs1)"; fresh106; OUT="$(R106_MUTANT_OUT=1 run106c --script "$MW106" --check)"; rm -f "$MW106"
check "$(has "$OUT" 'A CLAIM SWITCH IS PRESENT')$(has "$OUT" 'NO-GO (CS-G1)')|$(has "$OUT" 'NO-GO (FE-G1): followup_enabled reads off')|$(went106 "$OUT")|$(live)|$(still106)" \
      "nono|yes|nono|$B106|$S1C_106" \
  "106-1c3 CS1 on this s-only state: no claim switch found, no CS-G1 refusal — the run goes on to FE-G1's refusal (caught by 106-1c1's text); nothing changed"
X1CU_106="$(plant106 '[{"op":"fx_store_keys_undo","keys":["offers_live_enabled","offers_test_enabled","quote_chase_enabled"]}]')"
check "$(jf105 "$X1CU_106" ops)|$(sd106 "$X1CU_106" 0 file)|$(kv106 "$(q106 kycplaces "$PD" "$DATA" offers_live_enabled,offers_test_enabled,quote_chase_enabled)" cs)|$(digest105)|$(kv106 "$(q106 cfgsha "$PD" "$DATA")" k)" \
      "1|unchanged|-|$DG1C_106|$(kv106 "$CF1C_106" k)" \
  "106-1c4 the fixture undone (A9) through the store's own load() and save(): no claim switch in k or s by this rehearsal's reading, every ladder key as before the fixture (LK's digest), kyc_config.json byte for byte — CS-G1 reads clean again in 106-1f"

# ── 106-1d, 106-1e: LK-G1 refuses first — quote_chase_enabled in <plugin>/data/config.json, then in <data>/config.json (each a
# FIXTURE: no production writer writes either file, LK-G1's own remedy text says so), put there by rh106-seed.php fx_file as
# root (the file's owner and mode kept), the original kept in $R106/kept/lkg1; each undone by fx_file_back, by sha256. ──
mkdir -p "$R106/kept/lkg1"; chmod 700 "$R106/kept/lkg1"
for pl106 in p d; do
  c106="$( [ "$pl106" = p ] && echo 106-1d || echo 106-1e )"; w106="$( [ "$pl106" = p ] && echo '<plugin>/data/config.json' || echo '<data>/config.json' )"
  cond106 "$c106 cond" "$B106" on off off
  CF0_106="$(q106 cfgsha "$PD" "$DATA")"
  X1D_106="$(seed106r "[{\"op\":\"fx_file\",\"place\":\"$pl106\",\"keep\":\"$R106/kept/lkg1\",\"tag\":\"lkg1-$pl106\",\"set\":{\"quote_chase_enabled\":\"1\"},\"plugin\":\"$PD\"}]")"
  check "$(jf105 "$X1D_106" ops)|$(sd106 "$X1D_106" 0 fixture)|$(sd106 "$X1D_106" 0 keys)|$(kv106 "$(q106 kycplaces "$PD" "$DATA" quote_chase_enabled)" pd)|$(kv106 "$(q106 kycplaces "$PD" "$DATA" quote_chase_enabled)" cs)" \
        "1|true|quote_chase_enabled|quote_chase_enabled@$pl106|-" \
    "$c106 the fixture: quote_chase_enabled in $w106 alone (this rehearsal's reading: held there, in neither k nor s)"
  D1D_106="$(data94)"; S1D_106="$(still106)"; fresh106; OUT="$(run106c --check)"
  # deploy-5.18.106.sh:7368
  nogo106 "$c106 LK-G1 refuses first: one of allKeys() held by $w106 (quote_chase_enabled), exit 1, nothing changed" \
          "NO-GO (LK-G1): 1 of the ladder's switch and record keys (SalesFollowupLadder::allKeys()) are held by <plugin>/data/config.json or <data>/config.json (quote_chase_enabled)" "$OUT" "$D1D_106" "$B106"
  check "$(has "$OUT" 'A CLAIM SWITCH IS PRESENT')|$(okline "$OUT" CS-G1 'none of the three claim switches')|$(has "$OUT" 'NO-GO (FE-G1)')|$(still106)" "no|no|no|$S1D_106" \
    "${c106}1 …LK-G1's held rule refuses before CS-G1 is reached (neither its refusal nor its ok line) and before FE-G1; the book, the four places and the run folder unchanged"
  X1DB_106="$(seed106r "[{\"op\":\"fx_file_back\",\"place\":\"$pl106\",\"keep\":\"$R106/kept/lkg1\",\"tag\":\"lkg1-$pl106\",\"plugin\":\"$PD\"}]")"
  check "$(jf105 "$X1DB_106" ops)|$(sd106 "$X1DB_106" 0 fixture)|$(q106 cfgsha "$PD" "$DATA")|$(kv105 "$(lk105)" held)|$(ls -A "$R106/kept/lkg1" | grep -c "^lkg1-$pl106\.")" \
        "1|true|$CF0_106|0|0" \
    "${c106}2 the fixture undone (A9): $w106 put back by fx_file_back — the four places byte for byte as before (sha256), nothing held, nothing left kept"
done

# ── 106-1f: the clean --check (step 6 off, followup_enabled still off): CS-G1 ok, FE-G1 NO-GO — its command alone, the owner's
# own (set_config as the book's owner, --value 1, its output cut, its own log), nothing changed. FE1 caught. ──
cond106 "106-1f cond" "$B106" on off off
FEX106="docker exec -u $(owner104) -e DN_DATA_DIR=$PDD104_IN ucrm php $IN_CONTAINER/tools/set_config.php --key followup_enabled --value 1 2>&1 | sed '/^  AI SETTINGS\$/,\$d' $(LOGW106 followup-enabled-1)"   # deploy-5.18.106.sh:8008, as the script prints it
D1F_106="$(data94)"; S1F_106="$(still106)"; fresh106; OUTFE106="$(run106c --check)"; OUT="$OUTFE106"
# deploy-5.18.106.sh:8013
nogo106 "106-1f FE-G1 --check with step 6 off and followup_enabled off: NO-GO (FE-G1), exit 1, nothing changed" \
        "NO-GO (FE-G1): followup_enabled reads off — the enquiry follow-ups are paused (the 18 Nov interim)." "$OUT" "$D1F_106" "$B106"
# deploy-5.18.106.sh:7409, :7370, :7981, :8006, :8008, :8009, :8013, :1690
check "$(okline "$OUT" CS-G1 'none of the three claim switches')|$(noteline "$OUT" LK-G1 "the ladder's keys as found")|$(noteline "$OUT" U4 'followup_enabled off, followup_auto_send on')|$(order106 "$OUT" "  FE-G1 FOLLOWUP_ENABLED READS OFF AS THE JOBS READ IT (U4 above): " "      docker exec -u " "  It prints the line \"  followup_enabled = 1\" once it has saved; " "  STOP: NO-GO (FE-G1): " "  Nothing was changed. Send the log file.")|$( [ "$(printed "$OUT" "docker exec -u ")" = "$FEX106" ] && echo printed)|$(grep -cxF "      $FEX106" <<<"$OUT")|$(has "$OUT" 'A CLAIM SWITCH IS PRESENT')|$(still106)" \
      "yes|yes|yes|ordered|printed|1|no|$S1F_106" \
  "106-1f1 CS-G1 reads clean after the fixtures (its ok line; LK-G1's note), U4 reads followup_enabled off; FE-G1's headline, then its command alone — set_config as the book's owner on the live data folder, --value 1, its output cut where the settings listing begins, its own log — then the line it prints once it has saved, then the stop; the book, the four places and the run folder unchanged"
MW106="$(weak106 fe1 fe1)"; fresh106; OUT="$(R106_MUTANT_OUT=1 run106c --script "$MW106" --check)"; rm -f "$MW106"
# deploy-5.18.106.sh:15907 GO; :7984 U4's off note
check "$(has "$OUT" 'FE-G1 FOLLOWUP_ENABLED READS OFF')$(has "$OUT" 'NO-GO (FE-G1)')|$(has "$OUT" "  $V106 (check): GO — stage A holds.")|$(noteline "$OUT" U4 'followup_enabled is off — the four follow-up jobs do not run')|$(live)|$(still106)" \
      "nono|yes|yes|$B106|$S1F_106" \
  "106-1f2 FE1, the copy whose uj_fe_g1 returns at once: U4 still reads followup_enabled off, yet no FE-G1 and GO (caught by 106-1f's refusal); nothing changed"

# ── 106-1g: U4 unreadable (A8's uj hook, FAULT INJECTION: QF_PHP's uj reading refused while $SB/.ujfail106 exists), followup_enabled
# still off, so "no refusal" discriminates: no FE-G1, GO, U1–U4's NOT ESTABLISHED note. The trigger armed for this one run
# and removed after it, asserted gone. ──
cond106 "106-1g cond" "$B106" on off off
UJF0_106="$(grep -c '^uj$' "$SB/ujfail106.fired" 2>/dev/null)"; S1G_106="$(still106)"
: > "$SB/.ujfail106"
fresh106; OUT="$(run106c --check)"
rm -f "$SB/.ujfail106"
# deploy-5.18.106.sh:7991 (uj_stage's note; uj_read :7914-7921 makes "unread(no_answer)" of an empty answer), :8004, :15907
check "$(checkgo106 "$OUT")|$(noteline "$OUT" 'U1–U4' 'could not be read on the copy (unread(no_answer))' 'NOT ESTABLISHED')|$(has "$OUT" 'FE-G1 FOLLOWUP_ENABLED READS OFF')$(has "$OUT" 'NO-GO (FE-G1)')|$(grep -c '^  note  U4 ' <<<"$OUT")|$(( $(n103 "$(grep -c '^uj$' "$SB/ujfail106.fired" 2>/dev/null)") - $(n103 "$UJF0_106") ))|$( [ -e "$SB/.ujfail106" ] && echo armed || echo removed)|$(still106)" \
      "yesnono0|0|0|samekey|0|yes|nono|0|1|removed|$S1G_106" \
  "106-1g U4 unreadable (the uj hook, armed for this run alone): GO — U1–U4's NOT ESTABLISHED note, no U4 line, no FE-G1 refusal although followup_enabled is off (DL2: not read, no refusal); the hook refused the one uj reading and is removed; nothing changed"

# ── 106-1h: FE-G1's command run exactly as printed (the owner's: followup_enabled back on); its own log holds the saved line and
# nothing of the settings listing. Then --check: GO. ──
cond106 "106-1h cond" "$B106" on off off
FEL106="$(printed "$OUTFE106" "docker exec -u ")"
fresh106   # the run folder's logs moved aside first, so that the command's own log is the one there
O1H_106="$(run_line106c "" "$FEL106")"; RC1H_106="$(rc106)"
FEG106="$(ls -1 "$OUT106"/followup-enabled-1-*.log 2>/dev/null)"
# tools/set_config.php:670; deploy-5.18.106.sh:8009 (the line it prints once it has saved), :1563 (LOGW: umask 077)
check "$( [ "$FEL106" = "$FEX106" ] && echo printed)|$RC1H_106|$(grep -c . <<<"$FEG106")|$(grep -cxF '  followup_enabled = 1' "$FEG106" 2>/dev/null)|$(grep -c 'AI SETTINGS' "$FEG106" 2>/dev/null)|$(stat -c %a "$FEG106" 2>/dev/null)|$(grep -cxF '  followup_enabled = 1' <<<"$O1H_106")" \
      "printed|0|1|1|0|600|1" \
  "106-1h FE-G1's command run exactly as printed, under the clock: exit 0, its own log ($(basename "${FEG106:-none}"), mode 0600) holds '  followup_enabled = 1' and nothing from '  AI SETTINGS' on"
cond106 "106-1h1" "$B106" on off on
S1H_106="$(still106)"; fresh106; OUT="$(run106c --check)"
check "$(checkgo106 "$OUT")|$(okline "$OUT" CS-G1 'none of the three claim switches')|$(noteline "$OUT" U4 'followup_enabled on, followup_auto_send on (present in: k,s — ')|$(has "$OUT" 'FE-G1 FOLLOWUP_ENABLED READS OFF')|$(grep -cF 'U4 UJ-ON WILL NOT JUDGE F3' <<<"$OUT")|$(still106)" \
      "yesnono0|0|0|samekey|0|yes|yes|no|0|$S1H_106" \
  "106-1h2 --check after the owner's two commands: GO — CS-G1 ok, U4 reads followup_enabled on (followup_auto_send on, k,s), no FE-G1, no capitals note; nothing changed"

# ── 106-1i: U4's capitals note — followup_max_age_hours 0 (not a positive number: F2 and F3 inactive), through set_config as
# the book's owner. --check: the note without its DEPLOY clause, then GO; a deploy answered no: the note with the clause,
# before the question, then "not confirmed", nothing changed. U41 caught. Then the limit back at 336. ──
cond106 "106-1i cond" "$B106" on off on
O1I_106="$(setcfg106 followup_max_age_hours 0)"
check "$(setrc106)|$(grep -cxF '  followup_max_age_hours = 0' <<<"$O1I_106")|$(kv106 "$(q106 u2u3 "$DATA/plugin.sqlite3" "$PD" "$(fnow104)")" hours)" "0|1|misread" \
  "106-1i1 followup_max_age_hours 0 through the installed set_config.php as the book's owner — its line, exit 0; this rehearsal's reading: not a positive number (staleBefore null)"
S1I_106="$(still106)"; fresh106; OUT="$(run106c --check)"
# deploy-5.18.106.sh:7983 (the note, CHECK_ONLY: no DEPLOY clause), :7981, :15907
check "$(checkgo106 "$OUT")|$(grep -cxF "  note  $U4C106, THEN RUN THIS AGAIN" <<<"$OUT")|$(grep -c 'ANSWER ANYTHING BUT DEPLOY BELOW' <<<"$OUT")|$(noteline "$OUT" U4 'followup_max_age_hours not a positive number — F2 and F3 inactive: nothing is expired on a misread')|$(still106)" \
      "yesnono0|0|0|samekey|0|1|0|yes|$S1I_106" \
  "106-1i2 --check with the limit 0: U4's capitals note — UJ-ON WILL NOT JUDGE F3 FOR THIS DEPLOY, the age limit named — without its DEPLOY clause, a note: GO; nothing changed"
D1I_106="$(data94)"; C1I_106="$(kv106 "$(q106 cfgsha "$PD" "$DATA")" all)"; fresh106; OUT="$(run106c --answer no)"
# deploy-5.18.106.sh:7983 (with ", ANSWER ANYTHING BUT DEPLOY BELOW"), :13806, :13838, :13840, :1690
check "$(grep -cxF "  note  $U4C106, ANSWER ANYTHING BUT DEPLOY BELOW, THEN RUN THIS AGAIN" <<<"$OUT")|$(order106 "$OUT" "  note  $U4C106, ANSWER ANYTHING BUT DEPLOY BELOW" "  GO — evidence recorded, backup in " "  Type DEPLOY to deploy $V106 ($P106) over live " "  STOP: not confirmed" "  Nothing was changed. Send the log file.")|$(fails "$OUT")|$(rc106)|$(live)|$(installed106 "$B106F")|$(data94)|$(kv106 "$(q106 cfgsha "$PD" "$DATA")" all)" \
      "1|ordered|0|1|$B106|0|$D1I_106|$C1I_106" \
  "106-1i3 a deploy with the limit 0, answered no: U4's capitals note with its DEPLOY clause before the question, then 'not confirmed', exit 1 — nothing changed: $B106 serving, every file $V106 changes still $BV106's, the book and the four settings places as before (the backup, the state file and the LK key it wrote are its own records)"
tidy106 "106-1i-no"
check "$(backups106)|$(states106)|$(keyf106)" "0|0|nokey" "106-1i4 …the declined deploy's backup, state file and LK key moved into the case's folder: the next deploy is a first deploy again"
MW106="$(weak106 u41 u41)"; fresh106; OUT="$(R106_MUTANT_OUT=1 run106c --script "$MW106" --check)"; rm -f "$MW106"
check "$(grep -cF 'U4 UJ-ON WILL NOT JUDGE F3' <<<"$OUT")|$(noteline "$OUT" U4 'followup_max_age_hours not a positive number')|$(has "$OUT" "  $V106 (check): GO — stage A holds.")" "0|yes|yes" \
  "106-1i5 U41, the copy without the capitals note's line: U4 still reads the limit not a positive number, GO, and no capitals note (caught by 106-1i2's note)"
O1I_106="$(setcfg106 followup_max_age_hours 336)"
check "$(setrc106)|$(grep -cxF '  followup_max_age_hours = 336' <<<"$O1I_106")|$(kv106 "$(q106 u2u3 "$DATA/plugin.sqlite3" "$PD" "$(fnow104)")" hours)" "0|1|336" \
  "106-1i6 followup_max_age_hours set to 336 through the installed set_config.php as the book's owner — its line, exit 0; this rehearsal's reading 336"

# ── 106-1j: CS-B1 (RH106-6) — a deploy held at "Type DEPLOY"; on chase (yes typed) run while it waits; then DEPLOY typed. CS-B1
# refuses on LK-B1's reading before LK-B1's comparison: its own text, the off and --check commands each alone, nothing copied.
# CSB caught by LK-B1's generic refusal. Step 6 off again after each. ──
cond106 "106-1j cond" "$B106" on off on
WF0J_106="$(grep -c . "$R104/wait104.fail" 2>/dev/null)"
csb1run106 csb1 "$DEPLOY106" -- sf104c --tty yes on chase
OUT="$CSB1OUT106"
# deploy-5.18.106.sh:7409 (CS-G1 at stage A), :13806, :13838
check "$CSB1RC106|$(grep -c '^ON chase: ' "$R106/logs/act-csb1.txt" 2>/dev/null)|$(okline "$OUT" CS-G1 'none of the three claim switches')|$(grep -cF 'U4 UJ-ON WILL NOT JUDGE F3' <<<"$OUT")|$(went106 "$OUT")|$(( $(n103 "$(grep -c . "$R104/wait104.fail" 2>/dev/null)") - $(n103 "$WF0J_106") ))" \
      "0|1|yes|0|yesyes|0" \
  "106-1j1 the deploy held at its question: stage A held (CS-G1 ok, no capitals note), its evidence recorded, the question asked; on chase run through the installed tool while it waited (yes typed: ON, exit 0); DEPLOY typed once it had"
# deploy-5.18.106.sh:13838, :7413, :7427-7428, :7431-7432, :7433, :1691; :7469 (LK-B1, not reached); :13868-13870 (the copy, not reached)
check "$(grep -cF '  CS-B1 A CLAIM SWITCH IS PRESENT: quote_chase_enabled@k,s (key@place — k <data>/kyc_config.json, s its store row; no value is read out). ' <<<"$OUT")|$(order106 "$OUT" "  Type DEPLOY to deploy $V106 ($P106) over live " "  CS-B1 A CLAIM SWITCH IS PRESENT: quote_chase_enabled@k,s " "  step 6 (chase) — off chase also turns step 10 (cold) off:" "$OFFW106 chase" "  Then run --check again, its own command, alone:" "      cd $REPO && bash $F106 --check " "  STOP: NO-GO (CS-B1): a claim switch was turned on during this run, after stage A (quote_chase_enabled@k,s) — nothing was copied." "  Nothing further was done. Send the log file.")|$(grep -cxF "$OFFW106 chase" <<<"$OUT")|$(grep -cxF "$CHKL106" <<<"$OUT")|$(has "$OUT" 'NO-GO (LK-B1)')|$(rc106)|$(live)|$(installed106 "$B106F")|$(has "$OUT" '  copy            at ')" \
      "1|ordered|1|1|no|1|$B106|0|no" \
  "106-1j2 CS-B1 after DEPLOY: '  CS-B1 A CLAIM SWITCH IS PRESENT: quote_chase_enabled@k,s', the off command and --check again each alone, then 'NO-GO (CS-B1): a claim switch was turned on during this run, after stage A' — no LK-B1 refusal; exit 1; nothing copied: $B106 serving, every file $V106 changes still $BV106's"
tidy106 "106-1j-csb1"
O1J_106="$(sf104c off chase)"
check "$(sfrc104)|$(grep -cx 'OFF chase' <<<"$O1J_106")|$(kv105 "$(lk105)" on | tr ',' '\n' | grep -cx chase)|$(backups106)|$(states106)|$(keyf106)" "0|1|0|0|0|nokey" \
  "106-1j3 …step 6 off again through the installed tool, and the held deploy's backup, state file and LK key moved into the case's folder"
cond106 "106-1j4 cond" "$B106" on off on
WF0K_106="$(grep -c . "$R104/wait104.fail" 2>/dev/null)"
MW106="$(weak106 csb csb)"; R106_MUTANT_OUT=1 csb1run106 csb "$MW106" -- sf104c --tty yes on chase; rm -f "$MW106"
OUT="$CSB1OUT106"
# deploy-5.18.106.sh:7469 (LK-B1's refusal)
check "$CSB1RC106|$(went106 "$OUT")|$(has "$OUT" 'A CLAIM SWITCH IS PRESENT')|$(has "$OUT" 'NO-GO (LK-B1): a ladder key changed during this run')$(has "$OUT" 'nothing was copied')|$(rc106)|$(live)|$(installed106 "$B106F")|$(( $(n103 "$(grep -c . "$R104/wait104.fail" 2>/dev/null)") - $(n103 "$WF0K_106") ))" \
      "0|yesyes|no|yesyes|1|$B106|0|0" \
  "106-1j5 CSB, the copy without the CS-B1 call, the same held deploy with on chase while it waited: no CS-B1 text — LK-B1's generic refusal instead (caught by 106-1j2's text); nothing copied"
tidy106 "106-1j-csb"
O1J_106="$(sf104c off chase)"
check "$(sfrc104)|$(grep -cx 'OFF chase' <<<"$O1J_106")|$(kv105 "$(lk105)" on | tr ',' '\n' | grep -cx chase)|$(backups106)|$(states106)|$(keyf106)" "0|1|0|0|0|nokey" \
  "106-1j6 …step 6 off again, the copy's backup, state file and LK key moved aside"

# ── 106-1k: the state handed to 106-4 — production's before the deploy: 5.18.105 serving, steps 1–5 on, step 6 off,
# followup_enabled on, followup_auto_send on, followup_max_age_hours 336; J1–J3 in the book as planted; master idle since J1's
# last pass; no weakened copy left; this part's fixtures undone (the refusal list stays for 106-6: a stand-in setting). ──
cond106 "106-1k cond" "$B106" on off on
U1M106="$(q106 u1mirror "$DATA/plugin.sqlite3" "$PD" "$(fnow104)")"
check "$(kv106 "$U1M106" en)|$(kv106 "$U1M106" hours)|$(kv106 "$U1M106" ledger)|$(kv106 "$U1M106" places)|$(kv106 "$U1M106" classes)" \
      "on|336|record|5|held:1,f5:0,closes:0,f2:1,f1:2,waits:1" \
  "106-1k1 U1's ten places by this rehearsal's own mirror now (what stage A will read): followup_enabled on, the limit 336, the ledger recording — five approved drafts: J1's two fresh F1 closes, J1's stale one F2 expires, J3's first waits and its second is held"
SOK106="$(q106 staleorder "$DATA/plugin.sqlite3" 336 "$(fnow104)" 9000001 9000400)"
check "$(srtcsv106 "$(kv105 "$(lk105)" on)")|$(apd106)|$(kv106 "$SOK106" planted_open)|$(kv106 "$SOK106" first400_gone)|$(q106 rwd "$DATA/plugin.sqlite3" $J1FU106)|$(kv106 "$(q106 u2u3 "$DATA/plugin.sqlite3" "$PD" "$(fnow104)")" f5_multi)|$(sched104 followup_send)|$(sched104 followup_close)|$(pig106)|$(grep -c . "$SB/evo/rh106-refuse.list" 2>/dev/null)|$(ls "$REPO"/scripts/.cs1.sh "$REPO"/scripts/.fe1.sh "$REPO"/scripts/.u41.sh "$REPO"/scripts/.csb.sh 2>/dev/null | grep -c .)|$(backups106)|$(states106)|$(keyf106)" \
      "ack,list,report,tracking,voice-measure|n=5 fu=$(tr ' ' ',' <<<"$J1FU106"),$J3FU106,$J3FU106|400|400/400|$RW5_106|$F5A106|$SCH5_106|$PIG0_106|3|0|0|0|nokey" \
  "106-1k2 handed to 106-4: steps 1–5 on; the approved drafts J1's three then J3's two, nothing else; the 400 gone rows open and still the 400 oldest stale; J1's refusals as the jam left them; F5's count as J3 left it; master idle since J1's last pass and the piggyback record unchanged (A2); the refusal list J1's three; no weakened copy left; no backup, state file or LK key in $V106's run folder"
CLK3_106="$(fnow104)"
HELD106=-   # the registry's held channels (AutomationPolicy::sqlHeld) as this rehearsal states them to 106-4d's mirror: none — 106-1k1's mirror read the five places with none held; a registry holding a channel shows there as a held= mismatch, visibly
echo "  note  RH-NOTE 106-pre and 106-1 hand 106-4: $B106 ($BV106) serving; steps 1–5 on, step 6 off; followup_enabled on, followup_auto_send on, followup_daily_cap 3, followup_run_limit 2, followup_max_age_hours 336; J1 (follow-ups $J1FU106, its passes on Kampala $KWD106 from 09:00), J2 (follow-ups $J2FU106 and the 400 gone rows on ids 9 000 001–9 000 400), J3 (follow-up $J3FU106) in the book; no rollback of $V106 on record; the clock $(kday104 "$CLK3_106") $(TZ=Africa/Kampala date -d "@$CLK3_106" +%H:%M) Kampala"
# ══════════════════════════════════════════════════════════════════════════════════════
# 5.18.106's rehearsal, part 4 — 106-4 (the deploy, production's: D1) and 106-5 (--after-only on D1, then the copies of
# their own, D2–D6), as the rehearsal's design (18 Nov, v2) §C and §D set them. It takes the state 106-1 leaves: 5.18.105
# (7835906) serving, steps 1–5 on, step 6 (chase) off, followup_enabled on, followup_max_age_hours 336, followup_auto_send
# on, followup_daily_cap 3, followup_run_limit 2 (A11), the jam planted (J1–J3). It leaves 5.18.106 (bf29cbd) serving from
# D6's --redeploy — its stage A read with the age limit 0, so UJ-ON will not judge F3 for that deploy — followup_auto_send
# on, chase off, followup_enabled on, the limit 336, J3's pair still waiting, and D6's typed rollback the latest rollback
# record (AS_RB1=written). Every run is a 106 runner's, every rollback rbx106; no run here moves a copy back or writes
# master's schedule; master runs only through master106 (A2), and never the sender: J3 sends nothing until 106-6.
#   The numbers of 106's range this part plants — fictitious, +2567001060NN, proved free in 106-infra e and in the pattern
# files — each a conversation of its own, never used before: a conversation's last_customer_at never moves back, so every
# re-plant needs fresh numbers; a follow-up is never deleted, only closed (here by F3 itself, D2, D3 and D5's passes).
#     60, 61 (D2)   62, 63 (D3)   64, 65 (D5)   66–69 this part's, unused   — 98 and 99 are 106-infra's (A6's control)
# ══════════════════════════════════════════════════════════════════════════════════════
# rh106-seed.php refuses unless RH106_RANGE names 106's own ten digits: 106-infra's seed106 gives it to each run, read from
# PR106 — nothing here exports it (106-10f asserts the rehearsal's own environment never carries it).
LIM106=336                                                                   # followup_max_age_hours at the deploy (A11; 106-1 step 5)
PLOG106="$PD/data/plugin.log"; PLOGW106="$IN_CONTAINER/data/plugin.log"     # ME-CA1's log on the host, and as the script names it (S:8188)
dwal106() { sqx "PRAGMA wal_checkpoint(TRUNCATE)" >/dev/null 2>&1; }        # the book's write-ahead log folded in before a read-only reading (as lk105 does)
dclose106() { dwal106; q106 closer "$DATA/plugin.sqlite3" "$(sget106 "$STATE106" COPY_AT)"; }   # the closer since this deploy's copy, as UJ-ON counts it (S:7139-7171)
dsto106() { dwal106; q106 staleorder "$DATA/plugin.sqlite3" "$@"; }        # dsto106 HOURS EPOCH [LO HI] — the stale and gone order at EPOCH (rh106q.py staleorder)
dnow106() { dwal106; q106 u1mirror "$DATA/plugin.sqlite3" "$PD" "$(fnow104)"; }   # the gate's fields as the jobs read them now (en=, hours=)
dnooffer106() {   # dnooffer106 — none when the --after-only in AFT106 offers no turn-back-on (as_back_on, S:8839): neither its line nor a --value 1 command
  [ "$(has "$AFT106" 'followup_auto_send reads off: the latest rollback set it off')$(grep -c -- '--key followup_auto_send --value 1 ' <<<"$AFT106")" = "no0" ] && echo none || echo offered
}
dpre106() {   # dpre106 LABEL SERVES KEY CLAIMS EN:HOURS LAST:WOULD — design-v2 §D, before a case, one check: the commit serving;
              # followup_auto_send as the jobs read it (rh106q.py kycplaces eff=); the claim switches present in
              # <data>/kyc_config.json or its store row by cs_found's own rule ('-' none: step 6 off); followup_enabled and the age
              # limit as the jobs read them now (rh106q.py u1mirror en= hours=); and the latest rollback record as as_rb_last's
              # mirror reads it, with what as_back_on would do were the key off (rh106q.py rblast last= would=)
  local k m r
  dwal106; k="$(q106 kycplaces "$PD" "$DATA" followup_auto_send)"; m="$(dnow106)"; r="$(q106 rblast "$OUT106" "$STATE106")"
  check "$(live)|$(kv106 "$k" eff)|$(kv106 "$k" cs)|$(kv106 "$m" en):$(kv106 "$m" hours)|$(kv106 "$r" last):$(kv106 "$r" would)" \
        "$2|followup_auto_send:$3|$4|$5|$6" \
    "$1 the case's preconditions (design-v2 §D): $2 serving; followup_auto_send $3 as the jobs read it; the claim switches present: $4; followup_enabled:limit $5; the latest rollback record $6 (rh106q.py rblast — its AS_RB1, and what as_back_on would do with the key off)"
}
dredeploy106() {   # dredeploy106 LABEL — a --redeploy of 5.18.106 over 7835906 after its own typed rollback, DEPLOY typed, under the
                   # clock (design-v2 §C: the gates hold at each redeploy). The gates first, by this rehearsal's own readings: no claim
                   # switch (CS-G1's), followup_enabled on (FE-G1's), every settings place decodable. Then PASSED (exit 0), bf29cbd
                   # serving and installed, CS-G1 and LK-CA1 ok, no FE-G1 refusal, the earlier state file kept beside the new one
                   # (S:13737), COPY_AT on the clock within 60 s of QF_COPY_AT (A1), followup_close not run since the copy (A2's
                   # positive control), Evolution not called. Its output in DRD106_OUT — call it directly
  local k p0 o
  dwal106; k="$(q106 kycplaces "$PD" "$DATA" followup_auto_send)"
  check "$(live)|$(kv106 "$k" cs)|$(kv106 "$(dnow106)" en)|$(kv106 "$k" file):$(kv106 "$k" row)|$(case "$(kv106 "$k" pcfg):$(kv106 "$k" dcfg)" in absent:absent|absent:decoded|decoded:absent|decoded:decoded) echo decodes ;; *) echo "$(kv106 "$k" pcfg):$(kv106 "$k" dcfg)" ;; esac)" \
        "$B106|-|on|decoded:decoded|decodes" \
    "$1 the gates before the --redeploy hold, by this rehearsal's own reading: $B106 serving after the typed rollback, no claim switch in <data>/kyc_config.json or its store row, followup_enabled on, every settings place decodable"
  p0="$(ls -1 "$STATE106".prev-* 2>/dev/null | grep -c .)"
  fresh106; o="$(run106c --answer DEPLOY --redeploy)"; DRD106_OUT="$o"
  check "$(fails "$o")$(has "$o" "  $V106 (deploy): PASSED.")|$(rc106)|$(went106 "$o")|$(live)|$(installed106 "$P106F")|$(okline "$o" CS-G1 'none of the three claim switches')$(okline "$o" LK-CA1 'after the copy every ladder key')$(has "$o" 'FE-G1 FOLLOWUP_ENABLED READS OFF')|$(( $(ls -1 "$STATE106".prev-* 2>/dev/null | grep -c .) - p0 ))|$(copyok106)|$(ctl106)|$(cat "$R106/evo/run-$(last106).n" 2>/dev/null)" \
        "0yes|0|yesyes|$P106|0|yesyesno|1|near|before|0 nocron" \
    "$1' the --redeploy, DEPLOY typed, under the clock: PASSED (exit 0), $P106 serving and every file it changes installed as it has them, CS-G1 none (S:7409) and LK-CA1 ok (S:7494), no FE-G1 refusal; the earlier state file kept beside the new one; COPY_AT on the clock within 60 s of QF_COPY_AT (A1); followup_close not run since the copy (A2's positive control); Evolution not called"
}
doffer106() {   # doffer106 LABEL — the PASSED --after-only in AFT106 offers the owner's own command to turn followup_auto_send back on
                # (as_back_on, S:8826-8841; as_owner_text 1, S:8735): the latest rollback wrote it off (AS_RB1=written, its copy
                # before this deploy's) and it reads off — by this rehearsal's own reading too (rblast). The command, alone on its
                # line, is then run exactly as printed (run_line106c, under the clock, nothing typed): it prints
                # "  followup_auto_send = 1" and nothing from the settings listing on, its own log holds that line, exit status 0;
                # the key reads on again in the file and its store row, and every ladder key is as it was
  local r c o lg k k0
  r="$(q106 rblast "$OUT106" "$STATE106")"
  c="$(grep -m1 -E '^      docker exec .* --key followup_auto_send --value 1 2>&1 \| sed ' <<<"$AFT106" | cut -c7-)"
  check "$(kv106 "$r" last):$(kv106 "$r" before):$(kv106 "$r" would)|$(has "$AFT106" "  followup_auto_send reads off: the latest rollback set it off (AS_RB1=written in its record; its copy at ")$(has "$AFT106" "and it stays off until you turn it on. Turning it back on — automatic approval, as before that rollback — is your choice:")|$(grep -cE '^      docker exec .* --key followup_auto_send --value 1 ' <<<"$AFT106")|$(case "$c" in "docker exec -u $(owner104) -e DN_DATA_DIR="*" ucrm php $IN_CONTAINER/tools/set_config.php --key followup_auto_send --value 1 2>&1 | sed '/^  AI SETTINGS\$/,\$d' $(LOGW106 autosend-1)") echo form ;; *) echo other ;; esac)|$(has "$AFT106" '  It prints the line "  followup_auto_send = 1" once it has saved;')" \
        "written:yes:offer-if-off|yesyes|1|form|yes" \
    "$1 as_back_on offers the owner's own command to turn followup_auto_send back on (S:8839, S:8735): the latest rollback wrote it off, its copy before this deploy's, and it reads off — this rehearsal's own reading agrees (rblast: offer-if-off); the command alone on its line, in the owner's form (set_config as the book's owner, its output cut where the settings listing begins, its own log)"
  k0="$(digest105)"
  o="$(run_line106c "" "$c")"
  lg="$(ls -1t "$OUT106"/autosend-1-*.log 2>/dev/null | head -1)"
  dwal106; k="$(q106 kycplaces "$PD" "$DATA" followup_auto_send)"
  check "$(grep -cx '  followup_auto_send = 1' <<<"$o")|$(grep -c '^  AI SETTINGS' <<<"$o")|$(rc106)|$(grep -cx '  followup_auto_send = 1' "${lg:-/nonexistent}" 2>/dev/null)|$(kv106 "$k" eff)|$(kv106 "$k" asplaces)|$( [ "$(digest105)" = "$k0" ] && echo kept)" \
        "1|0|0|1|followup_auto_send:on|k:on,s:on,p:-,d:-|kept" \
    "$1' the offered command run exactly as printed (run_line106c, under the clock): it prints \"  followup_auto_send = 1\" and nothing from \"  AI SETTINGS\" on, exit status 0, its own log ($(basename "${lg:-none}")) holds the line; followup_auto_send reads on again in <data>/kyc_config.json and its store row; every ladder key as before"
}
dstale106() {   # dstale106 LABEL NN… — present stale enquiry follow-ups (design-v2 §C: D2, D3 and D5's re-plants), one for each number
                # NN of 106's range, through the pin's own classes (rh106-seed.php's enquiry op, upto open: the customer's message
                # stored with last_customer_at 420 h before the clock's now, then FollowUpService::open — never raw SQL), each a
                # conversation of its own. Asserted: every op done, each aged 420 h by the clock; by this rehearsal's own reading
                # (staleorder, now) exactly these are stale with a present conversation under 336 h and none under 504 h — aged
                # between the two limits — and fewer than 200 gone rows are open. Their ids in DST106; the installed public.php's
                # piggyback record then in PIGP106 (A2: it must not move before the case's last run)
  local lab="$1" ops o r s3 s5 n; shift; n=$#
  ops="$(python3 -I -c 'import json, sys
print(json.dumps([{"op": "enquiry", "phone": p, "name": "RH106SECRET-quiet-" + p[-2:], "text": "RH106SECRET-an enquiry gone quiet " + p[-2:], "lca_ago_s": 1512000, "upto": "open"} for p in sys.argv[1:]]))' $(for x in "$@"; do ph106 "$x"; done))"
  o="$(plant106 "$ops")"
  r="$(python3 -I -c 'import json, sys
try:
    d = json.loads(sys.argv[1]); dn = d["done"]
    ok = d["ops"] == len(dn) == int(sys.argv[2]) and all(x["op"] == "enquiry" and x["approved"] == 0 and x["drafts"] == [] and 1512000 <= x["age_s"] <= 1512005 for x in dn)
    print(("planted" if ok else "bad") + " " + (",".join(str(x["id"]) for x in dn) or "-"))
except (ValueError, KeyError, TypeError) as e:
    print("unread:" + type(e).__name__ + " -")' "$o" "$n")"
  DST106="${r#* }"; PIGP106="$(pig106)"
  s3="$(dsto106 "$LIM106" "$(fnow104)")"; s5="$(dsto106 504 "$(fnow104)")"
  check "${r%% *}|$(kv106 "$s3" stale_present)|$(kv106 "$s5" stale_present)|$( [ "$(n103 "$(kv106 "$s3" open_gone)")" -lt 200 ] && echo lt200 || echo "gone:$(kv106 "$s3" open_gone)")" \
        "planted|$n|0|lt200" \
    "$lab $n present stale enquiry follow-up(s) planted through the pin's own classes (rh106-seed.php enquiry, upto open — numbers $*, ids $DST106), each aged 420 h by the clock: by this rehearsal's own reading exactly these are stale with a present conversation under $LIM106 h and none under 504 h; fewer than 200 gone rows open ($(kv106 "$s3" open_gone))"
}
dstdrop106() {   # dstdrop106 KEY — this deploy's state file with its one line beginning KEY= taken out, every other byte as it was: an
                 # asserted edit in place (inode, owner and mode kept); prints removed, or miss (recorded in anchor-missing). A FIXTURE:
                 # no writer of the script's ever takes a line out (design-v2 A9) — keep106 before it, back106 after, both asserted
  python3 -I - "$STATE106" "$1" <<'PY' || { echo "rh106:stdrop:$1" >> "$SB/anchor-missing"; echo miss; }
import sys
p, k = sys.argv[1], sys.argv[2].encode() + b'='
b = open(p, 'rb').read()
ls = b.splitlines(keepends=True)
drop = [l for l in ls if l.startswith(k)]
assert len(drop) == 1, len(drop)
nb = b''.join(l for l in ls if not l.startswith(k))
assert len(nb) == len(b) - len(drop[0]) and not any(l.startswith(k) for l in nb.splitlines(keepends=True))
with open(p, 'r+b') as f:
    f.write(nb)
    f.truncate()
print('removed')
PY
}
dmets106() {   # dmets106 COPYUNIX OFF… — the plugin's zone as ME_PHP reads it (S:8129-8130): the INSTALLED lib/timezone.php's dn_tz(),
               # called with no config, in the container as the book's owner, with the data folder the script's runs carry (run106's
               # DN_DATA_DIR) — never assumed Kampala. Prints "tz=<zone> off=<its UTC offset at COPYUNIX, s> at=<stamp>,…", each stamp
               # COPYUNIX+OFF in that zone as master_log writes it (date('Y-m-d H:i:s'), its space as _); OFF is p<s> or m<s>, never a
               # leading dash (php would read one as its own option)
  local cu="$1"; shift
  env DN_DATA_DIR="$DATA" PATH="$SB/bin:$PATH" bash "$SB/bin/docker" exec -u "$(owner104)" ucrm php -d display_errors=stderr -d log_errors=0 -d opcache.enable_cli=0 -r '
$r = (string)$argv[1]; $cu = (int)$argv[2];
require_once $r . "/lib/timezone.php";
$tz = dn_tz(); $z = new DateTimeZone($tz);
$d = new DateTime("@" . $cu); $d->setTimezone($z);
$s = [];
for ($i = 3; $i < count($argv); $i++) {
    $a = (string)$argv[$i]; $n = (int)substr($a, 1); if ($a[0] === "m") $n = -$n;
    $e = new DateTime("@" . ($cu + $n)); $e->setTimezone($z); $s[] = $e->format("Y-m-d_H:i:s");
}
echo "tz=", $tz, " off=", $d->getOffset(), " at=", implode(",", $s);' "$IN_CONTAINER" "$cu" "$@" 2>/dev/null </dev/null
}
dmecase106() {   # dmecase106 LABEL VERDICT LINE… — ME-CA1 on planted lines (design-v2 §C 106-5b; RH106-14, F9). A FIXTURE: in the
                 # sandbox master's output goes to master-*.out (master104), never to plugin.log, so every [master] line there is this
                 # rehearsal's. plugin.log marked (mark106: its size and checksum); each LINE, "OFF|TEXT", appended as master_log writes
                 # it ("[Y-m-d H:i:s] [master] TEXT", bf29cbd cron/master.php:440-443), stamped COPY_AT+OFF in the installed dn_tz()
                 # (dmets106); the --after-only (aft106 LABEL VERDICT, steps 1–5 judged); then plugin.log cut back to its mark (cut106)
                 # — the undo, asserted (A9). The run's output stays in AFT106, the zone's reading in MET106
  local lab="$1" want="$2" cu m l offs="" i=0 c0 c1 cb at; shift 2
  cu="$(sget106 "$STATE106" COPY_AT)"
  for l in "$@"; do offs="$offs ${l%%|*}"; done
  MET106="$(dmets106 "$cu" $offs)"; at="$(kv106 "$MET106" at)"
  m="$(mark106 "$PLOG106")"; c0="$(grep -c '\] \[master\] ' "$PLOG106" 2>/dev/null)"
  for l in "$@"; do i=$((i+1)); printf '[%s] [master] %s\n' "$(cut -d, -f"$i" <<<"$at" | tr '_' ' ')" "${l#*|}" >> "$PLOG106"; done
  c1="$(grep -c '\] \[master\] ' "$PLOG106" 2>/dev/null)"
  aft106 "$lab" "$want" $L1ON106
  cb="$(cut106 "$PLOG106" "$m")"
  check "$(n103 "$c0")|$(( $(n103 "$c1") - $(n103 "$c0") ))|$(tr ',' '\n' <<<"$at" | grep -c '^[0-9]\{4\}-[0-9][0-9]-[0-9][0-9]_[0-9][0-9]:[0-9][0-9]:[0-9][0-9]$')|$cb|$(mark106 "$PLOG106")" \
        "0|$#|$#|same|$m" \
    "$lab' the fixture undone (A9): plugin.log held no [master] line before; the $# planted lines, each stamped in the installed dn_tz() ($(kv106 "$MET106" tz)), were appended; afterwards it is cut back to its recorded size and checksum ($m)"
}
dround106() {   # dround106 D RBWANT [LIMIT HOURS] — a copy of its own (design-v2 §C, 106-5 D2–D6): its preconditions (bf29cbd
                # serving, the key on, no claim switch, followup_enabled on at 336 h, the latest rollback record RBWANT); the typed
                # rollback (rbx106), which writes followup_auto_send off (AS-RB1: the key read on and §L.7's five conditions hold), its
                # record then the latest by this rehearsal's own reading; with LIMIT, followup_max_age_hours set to it through the real
                # set_config before the --redeploy (D6: HOURS as the jobs will read it); the --redeploy with the gates holding
                # (dredeploy106); then the new deploy's preconditions — the key off, the latest record written and before its copy,
                # so that its first PASSED --after-only offers the turn-back-on
  local d="$1" want="$2" k r o
  dpre106 "106-5${d}a" "$P106" on - "on:$LIM106" "$want"
  rbx106 "106-5${d}b"
  dwal106; k="$(q106 kycplaces "$PD" "$DATA" followup_auto_send)"; r="$(q106 rblast "$OUT106" "$STATE106")"
  check "$(sget106 "$RBX106_REC" AS_RB1)|$(okline "$RBX106_OUT" AS-RB1 "followup_auto_send set off before the put-back (typed)")|$(kv106 "$k" eff)|$(kv106 "$k" asplaces)|$(kv106 "$r" last):$(kv106 "$r" file)|$(kv106 "$r" before):$(kv106 "$r" would)" \
        "written|yes|followup_auto_send:off|k:off,s:off,p:-,d:-|written:$(basename "$RBX106_REC")|no:nothing:not-before" \
    "106-5${d}c the typed rollback wrote followup_auto_send off before the put-back (AS-RB1, S:9031; AS_RB1=written in its record), read off now in the file and its store row; its record the latest by this rehearsal's own reading (rblast), its RB_COPY_AT — on the clock (A1) — after the rolled-back deploy's COPY_AT"
  if [ -n "${3:-}" ]; then
    o="$(setcfg106 followup_max_age_hours "$3")"
    check "$(grep -cx "  followup_max_age_hours = $3" <<<"$o")|$(setrc106)|$(kv106 "$(dnow106)" hours)" "1|0|$4" \
      "106-5${d}c2 followup_max_age_hours set to $3 through the installed set_config as the book's owner, under the clock (it prints \"  followup_max_age_hours = $3\"); this rehearsal reads it as the jobs do: $4"
  fi
  dredeploy106 "106-5${d}d"
  dpre106 "106-5${d}e" "$P106" off - "on:${4:-$LIM106}" "written:offer-if-off"
}
dfailchk106() {   # dfailchk106 LABEL N — UJ-ON's deterministic FAIL in AFT106 (S:8096), against this rehearsal's own readings since the
                  # copy: the control held (followup_close started after the copy and ended), nothing closed expired, its start T the
                  # close job's last run, the cancels at or after T, and expireStale's SELECT at T under the limit stage A read — N
                  # stale rows with a present conversation (staleorder); no UJ-ON PASS or NOT YET line
  local c s f
  c="$(dclose106)"; s="$(dsto106 "$LIM106" "$(kv106 "$c" fc_last)")"
  f="$(sed -nE 's/^  FAIL  UJ-ON FAIL — F3 did not expire: followup_close started at ([^,]+), after the copy, and ended; none closed expired by the closer since the copy, ([0-9]+) closed cancelled at or after that start \(fewer than 200, so a pass.s cap does not explain it\), and expireStale.s SELECT at [^ ]+ still selects ([0-9]+) stale enquiry follow-up\(s\) with a present conversation; .*/\1 \2 \3/p' <<<"$AFT106")"
  check "$(grep -c '^  FAIL  UJ-ON FAIL — F3 did not expire: ' <<<"$AFT106")|${f:-none}|$(kv106 "$c" control):$(kv106 "$c" expired)|$(kv106 "$s" stale_present)|$(grep -cE '^  (ok    UJ-ON PASS|wait  UJ-ON NOT YET)' <<<"$AFT106")|$(ctl106)" \
        "1|$(kv106 "$c" t) $(kv106 "$c" on_cancelled_t) $2|held:0|$2|0|after" \
    "$1 UJ-ON FAIL — F3 did not expire (S:8096): its start and its cancels as this rehearsal reads them (followup_close's last run $(kv106 "$c" t), $(kv106 "$c" on_cancelled_t) cancelled at or after it), nothing closed expired since the copy, and expireStale's SELECT at that start still selecting $2 stale row(s) with a present conversation by this rehearsal's own reading; no UJ-ON PASS or NOT YET line"
}
dpasschk106() {   # dpasschk106 LABEL N — UJ-ON's PASS in AFT106 (S:8085): N closed expired by the closer since the copy, as this
                  # rehearsal's own reading counts them (rh106q.py closer on_expired, F3's own detail), the control held
  local c n; c="$(dclose106)"
  n="$(sed -nE 's/^  ok    UJ-ON PASS — F3 works: ([0-9]+) enquiry follow-up\(s\) closed expired by the closer since the copy, each with F3.s own detail .*/\1/p' <<<"$AFT106")"
  check "${n:-none}|$(kv106 "$c" on_expired)|$(kv106 "$c" control)|$(grep -c '^  wait  UJ-ON ' <<<"$AFT106")" "$2|$2|held|0" \
    "$1 UJ-ON PASS — F3 works (S:8085): $2 enquiry follow-up(s) closed expired by the closer since the copy, with F3's own detail — as many as this rehearsal's own reading counts (rh106q.py closer), the control held"
}
WILLNOT106="but UJ-ON WILL NOT JUDGE F3 FOR THIS DEPLOY: its gate did not hold at stage A (followup_max_age_hours was not a positive number at stage A), and stage A's reading is fixed in the deploy's state file, so F3 stays unproven and the steps that wait for it (6, 11, 12) would follow without that proof; a judged UJ-ON needs a rollback, then a --redeploy whose stage A holds the gate."   # check it's arm when stage A's gate did not hold (S:16019)
NOWARM106="UJ-ON judges F3 on it only once its gate holds now (UJ-ON above names what did not) and a close pass has run after that (followup_close runs every 10 minutes) — run it only then: a run before that pass judges F3 by passes made while the gate did not hold, and can read FAIL; while it does not hold it gives that note, no verdict, and the run reads PASSED without that proof"   # check it's 'now' arm (S:16017)
NOWEN106="  note  UJ-ON not judged — its gate did not hold: followup_enabled is off now. F3 then has nothing to do, or another limit to do it by. Once it holds again, run --after-only only after a close pass has run under it (followup_close runs every 10 minutes): a run before that judges F3 by passes made while it did not hold, and can read FAIL; a note, which holds back nothing"   # UJ-ON's 'now' note, followup_enabled off now (S:8073)

echo; echo "== 106-4. the deploy, production's (D1): $V106 over $BV106 with the jam planted — PASSED, U1–U5 as this rehearsal reads them =="
# What 106-1's deploys that typed DEPLOY and then stopped (CS-B1), or were answered otherwise, may have left in the run folder — a
# backup, the state file, the LK key — moved into this rehearsal's own folder first, so that D1 is a first deploy, as production's is.
tidy106 "106-4-pre"
dpre106 "106-4a" "$B106" on - "on:$LIM106" "-:nothing:no-copy-at"
check "$(states106)|$(backups106)|$(keyf106)|$(left106)|$(inst_ver)" "0|0|nokey|0|$BV106" \
  "106-4a' a first deploy: no state file or rollback record of $V106's in its run folder, no backup, no LK key; none of the $NA106 files $V106 adds on disk; $BV106's manifest installed"
PIG4_106="$(pig106)"
fresh106; O4_106="$(run106c --answer DEPLOY)"; T4_106="$(last106)"
check "$(fails "$O4_106")$(has "$O4_106" "  $V106 (deploy): PASSED.")|$(rc106)|$(went106 "$O4_106")|$(live)|$(installed106 "$P106F")|$(copyok106)|$(ctl106)|$( [ "$(pig106)" = "$PIG4_106" ] && [ "$PIG4_106" = "${PIG0_106:-unset}" ] && echo kept)|$(cat "$R106/evo/run-$T4_106.n" 2>/dev/null)" \
      "0yes|0|yesyes|$P106|0|near|before|kept|0 nocron" \
  "106-4b D1, DEPLOY typed, under the clock, no flag (none of $V106's files on disk): PASSED (exit 0), $P106 serving and every file it changes installed as it has them; COPY_AT on the clock within 60 s of QF_COPY_AT (A1); followup_close not run since the copy (A2's positive control); the installed public.php's piggyback record unchanged from J2's plant (106-pre5's PIG0_106) through the run — A2's plant-to-copy span; Evolution not called, no cron stand-in"
check "$(okline "$O4_106" CS-G1 'none of the three claim switches')$(okline "$O4_106" LK-CA1 'after the copy every ladder key')$(okline "$O4_106" R1 all "files $V106 changes are installed exactly as $P106 has them")$(okline "$O4_106" R1 "the installed manifest says $V106")$(okline "$O4_106" Q-CA1 "installed = $V106's release")|$(has "$O4_106" 'U4 UJ-ON WILL NOT JUDGE F3')$(has "$O4_106" 'U4 STAFFJOBSGATE::APPLIES IS FALSE')$(has "$O4_106" 'U4 followup_enabled is off')$(has "$O4_106" 'FE-G1 FOLLOWUP_ENABLED READS OFF')|$(has "$O4_106" 'UJ-ON judges F3 on it, and until one has it reads NOT YET (exit status 3) and says when to run it again')" \
      "yesyesyesyesyes|nononono|yes" \
  "106-4c …CS-G1 none (S:7409), LK-CA1 (S:7494), R1 (S:14400, S:14402) and Q-CA1 (S:14432) ok; no U4 capitals note, no StaffJobsGate note, no followup_enabled note, no FE-G1 refusal (S:7983, S:7982, S:7984, S:8006); check it says UJ-ON judges F3 (S:16017's last arm: stage A's gate held)"
# U1–U5 against this rehearsal's own readings, read at once (nothing writes the book in between), at stage A's own moment (UJ_A's
# at=, the deploy's raw stage-A line, S:13776), so the 24 h and 30-day windows are the deploy's
UJA4_106="$(sget106 "$STATE106" UJ_A)"; AT4_106="$(kv106 "$UJA4_106" at)"
case "$(kv106 "$UJA4_106" held)" in none) HS4_106=- ;; *) HS4_106="${HELD106:-unstated}" ;; esac   # the registry's held channels: none, or as 106-pre states them (HELD106)
dwal106
M14_106="$(q106 u1mirror "$DATA/plugin.sqlite3" "$PD" "$AT4_106" "$HS4_106" 1)"
M24_106="$(q106 u2u3 "$DATA/plugin.sqlite3" "$PD" "$AT4_106" 1)"
SO4_106="$(q106 staleorder "$DATA/plugin.sqlite3" "$LIM106" "$AT4_106" 9000001 9000400)"
K4_106="$(q106 kycplaces "$PD" "$DATA" followup_auto_send)"
CF4_106="$(kv106 "$UJA4_106" classes | awk -F'[:,]' 'NF == 12 { printf "held (a second draft this pass) %s, F5 expires (attempt taken) %s, closes (gone, colleague, owned number, opted out, taken over or replied) %s, F2 expires (stale) %s, F1 closes %s, waits %s", $2, $4, $6, $8, $10, $12 }')"   # uj_places' own sentence (S:7960)
PL4_106="$(kv106 "$K4_106" asplaces | sed -e 's/^k:/kyc_config.json /' -e 's/,s:/, its store row /' -e 's#,p:#, <plugin>/data/config.json #' -e 's#,d:#, <data>/config.json #')"   # as_places_say's own words (S:8679)
check "$(case "$UJA4_106" in "uj=read at="[0-9]*) echo whole ;; *) echo "${UJA4_106:0:24}" ;; esac)|$(q105 same "$UJA4_106" "$M14_106" out,held,places,place,classes,en,hours,cutoff,ledger,ug)|$( [ "$(n103 "$(sed -n 's/.*,f1:\([0-9]*\),.*/\1/p' <<<"$(kv106 "$UJA4_106" classes)")")" -ge 1 ] && echo f1)|$( [ "$(n103 "$(sed -n 's/^held:\([0-9]*\),.*/\1/p' <<<"$(kv106 "$UJA4_106" classes)")")" -ge 1 ] && echo held)|$( [ -n "$CF4_106" ] && noteline "$O4_106" U1 "the sender's ten places" "$CF4_106")" \
      "whole|same=yes|f1|held|yes" \
  "106-4d U1 as this rehearsal reads it (rh106q.py u1mirror at stage A's own moment, $AT4_106; the registry's held channels $HS4_106): each channel's deliveries, the sender's ten places in its order and each place's class equal the deploy's stage-A reading (UJ_A in its state file); J1's refused drafts class F1 closes and J3's second draft held ($(kv106 "$UJA4_106" classes)); U1's note carries those counts (S:7968)"
check "$(q105 same "$UJA4_106" "$M24_106" hours,cutoff,ug,last_send,sends7,approved,approved_stale,approved_fresh,approved_gone,failed7,failed7_f1,open,open_age,due105,due106,xs_all,xs_passes,xs_first_expire,xs_first_cancel,f5_multi)|$(kv106 "$UJA4_106" xs_first_cancel):$(kv106 "$UJA4_106" xs_first_expire):$( [ "$(n103 "$(kv106 "$UJA4_106" xs_all)")" -ge 401 ] && echo ge401)|$(kv106 "$UJA4_106" f5_multi)|$(kv106 "$SO4_106" first400_gone):$(kv106 "$SO4_106" gone_first):$(kv106 "$SO4_106" gone_older):$(kv106 "$SO4_106" planted_open):$(kv106 "$SO4_106" planted_conv)|$(noteline "$O4_106" U3 "its first pass (LIMIT 200): 0 close expired, 200 cancelled (a gone conversation); F5: 1/2 (follow-ups/drafts) hold two or more approved drafts")" \
      "same=yes|200:0:ge401|1/2|400/400:yes:yes:400:0|yes" \
  "106-4e U2 and U3 as this rehearsal reads them (rh106q.py u2u3 at stage A's moment) equal the deploy's — the sends, the approved drafts by stale, fresh and gone, the 400s, the open rows by age, due() under each release, expireStale's forecast; A3 by this rehearsal's own order (staleorder): the 400 oldest stale rows all gone conversations, every gone row older than every present one, the 400 on conversation ids 9000001–9000400 open with no conversation there — so U3's first pass cancels 200 and expires none ($(kv106 "$UJA4_106" xs_all) stale in all, S:7972); J3 the one follow-up holding two approved drafts (F5 1/2)"
check "$(kv106 "$UJA4_106" en):$(kv106 "$UJA4_106" auto):$(kv106 "$UJA4_106" auto_places):$(kv106 "$UJA4_106" cap):$(kv106 "$UJA4_106" run):$(kv106 "$UJA4_106" hours):$(kv106 "$UJA4_106" ug)|$(noteline "$O4_106" U4 'the settings as the jobs read them' 'followup_enabled on, followup_auto_send on (present in: k,s' 'followup_daily_cap 3, followup_run_limit 2' "followup_max_age_hours $LIM106 — F2 and F3 active" 'StaffJobsGate::applies Uganda')|$(kv106 "$K4_106" eff):$(kv106 "$K4_106" pre13)|$(noteline "$O4_106" U5 'whether a rollback could write followup_auto_send' "it reads on as the jobs read it (per place: $PL4_106; - absent, ? unread)" "§L.7's five conditions hold — a rollback would set it off itself before the put-back (AS-RB1), recomputing them just before")" \
      "on:on:k,s:3:2:$LIM106:1|yes|followup_auto_send:on:ok|yes" \
  "106-4f U4 on the copy reads production's settings (A11): followup_enabled on, followup_auto_send on (the file and its store row), followup_daily_cap 3, followup_run_limit 2, the limit $LIM106 h, StaffJobsGate Uganda — its note says so (S:7981); U5 reads on … hold (S:8773), its places as this rehearsal reads them (kycplaces: on, §L.7's conditions 1–3 holding)"

echo; echo "== 106-5. --after-only on D1: NOT YET before any pass, ME-CA1's cases, stage A's reading and the copy's time out of the record, the reading failing; pass 1 NOT YET, the limit and 'now' notes, pass 2 PASS =="
# 5a — before any close pass (A2's positive control: followup_close's last run at or before this deploy's copy)
dpre106 "106-5a0" "$P106" on - "on:$LIM106" "-:nothing:no-record"
CL5A106="$(dclose106)"
check "$(ctl106)|$(kv106 "$CL5A106" control):$(kv106 "$CL5A106" expired):$(kv106 "$CL5A106" cancelled)" "before|not:0:0" \
  "106-5a1 A2's positive control for NOT YET: followup_close's last run (master_schedule.json) is at or before this deploy's COPY_AT — no close pass since the copy; nothing closed expired or cancelled since it (rh106q.py closer)"
aft106 "106-5a" NOTYET $L1ON106
AFT5A106="$AFT106"; NYT106="The owner's steps (root docs/75 §F) wait for a PASSED --after-only. Then --after-only again, its own command, alone, with its own log:"
check "$(grep -c '^  wait  UJ-ON NOT YET — followup_close has not started and ended since the copy (its last start ' <<<"$AFT106")|$(grep -cE '^  checks +[0-9]+ ok, 0 failed, [0-9]+ notes, 1 not yet$' <<<"$AFT106")|$(awk -v t="$NYT106" 'f { print; exit } index($0, t) { f = 1 }' <<<"$AFT106")|$(has "$AFT106" "  the owner's steps root docs/75 §F")$(has "$AFT106" "  ECO Bank's own check, read-only")|$(dnooffer106)" \
      "1|1|      $EXP_AFTER106|nono|none" \
  "106-5a' UJ-ON NOT YET — followup_close has not started and ended since the copy (S:8099-8100); the count line ends \", 1 not yet\" (S:16081); the NOT YET block's one next command, the --after-only alone (S:16086-16088); the owner's steps (S:16103) and ECO Bank's check (S:16162) absent; as_back_on silent"
# 5b — ME-CA1 (S:8186-8206), each case on plugin.log lines this rehearsal plants and cuts away again (RH106-14, F9); UJ-ON reads
# NOT YET throughout (no close pass yet), so the run's exit status is 3, or 1 where ME-CA1 FAILs
dpre106 "106-5b" "$P106" on - "on:$LIM106" "-:nothing:no-record"
CU5B106="$(sget106 "$STATE106" COPY_AT)"; MZ5B106="$(dmets106 "$CU5B106" p0)"; MZ106="$(kv106 "$MZ5B106" tz)"
check "$( [ -f "$PLOG106" ] && echo present)|$(n103 "$(grep -c '\] \[master\] ' "$PLOG106" 2>/dev/null)")|$( [ ! -s "$PLOG106" ] || [ "$(tail -c1 "$PLOG106" | od -An -tx1 | tr -d ' \n')" = "0a" ] && echo whole-lines)|$( [ -n "$MZ106" ] && echo zone)|$(ctl106)|$(grep -cxF "  note  ME-CA1 NOT ESTABLISHED — THE POSITIVE CONTROL DID NOT HOLD IN $PLOGW106, WHERE MASTER'S OUTPUT WAS LOOKED FOR: it needs a [master] line stamped at or before the copy (0 read) and a [master] RUN followup_ line stamped after it (0 read), the stamps read in the plugin's zone ($MZ106); master's errors after the copy are not judged here — run --after-only again once master has run a follow-up job since the copy; a note, which holds back nothing" <<<"$AFT5A106")" \
      "present|0|whole-lines|zone|before|1" \
  "106-5b0 ME-CA1 with no [master] line in plugin.log (106-5a's run, S:8200): NOT ESTABLISHED, a note — in the sandbox master's output goes to master-*.out, so plugin.log holds none (measured: 0; the file ends a line, so a planted line starts its own); the plugin's zone read through the installed lib/timezone.php's dn_tz() as ME_PHP calls it ($MZ106), never assumed; still no close pass since the copy"
dmecase106 "106-5b1" NOTYET "m60|RUN followup_scan" "p30|ERROR followup_send: RH106SECRET-me fixture inside the straddle" "p40|Cycle complete in 100s. Jobs run: followup_scan, followup_send" "p700|RUN followup_close"
check "$(grep -cxF "  note  ME-CA1 1 ERROR line(s) of followup_close, followup_scan, followup_run or followup_send stamped after the copy inside the cycle it straddled (3 [master] line(s), up to its own Cycle complete) — a job of that cycle met the old class beside the new; a note" <<<"$AFT106")|$(grep -cxF "  ok    ME-CA1 no ERROR line of followup_close, followup_scan, followup_run or followup_send in $PLOGW106 after the cycle the copy straddled (4 [master] lines read, stamped in the plugin's zone $MZ106; the control: 1 at or before the copy, 1 RUN followup_ after it)" <<<"$AFT106")" "1|1" \
  "106-5b1'' an ERROR of followup_send stamped after the copy inside the cycle the copy straddled (a RUN before the copy, then the error, then that cycle's Cycle complete): a note naming it, 3 lines up to its own Cycle complete (S:8203); none after the straddle (S:8204)"
dmecase106 "106-5b2" FAILED "m60|RUN followup_scan" "p30|Cycle complete in 90s. Jobs run: followup_scan" "p700|RUN followup_close" "p710|ERROR followup_close: RH106SECRET-me fixture after the straddle"
check "$(grep -c '^  FAIL  ' <<<"$AFT106")|$(grep -cxF "  FAIL  ME-CA1 1 ERROR line(s) of the four follow-up jobs in $PLOGW106 after the cycle the copy straddled (by job: close 1 scan 0 run 0 send 0) — in the 24 h before the copy, for comparison: 0; counts only, the lines are in that file" <<<"$AFT106")|$(grep -c '^  note  ME-CA1 [0-9]* ERROR line' <<<"$AFT106")|$(grep -c '^  wait  UJ-ON NOT YET — ' <<<"$AFT106")" "1|1|0|1" \
  "106-5b2'' an ERROR of followup_close stamped after the cycle the copy straddled (that cycle ended by its own Cycle complete before it): ME-CA1's FAIL by job (S:8205), its only FAIL; no note of one inside the straddle; UJ-ON still NOT YET — ME-CA1 decides the exit status, 1"
OFF5B106="$(n103 "$(kv106 "$MZ5B106" off)")"; DM5B106=$(( OFF5B106 / 2 ))   # the mirror stamp: half the zone's offset before the copy
check "$( [ "$OFF5B106" -ge 2 ] && echo east || echo "off:$OFF5B106")" "east" \
  "106-5b3a the mirror stamp can be built: the plugin's zone ($MZ106) is $OFF5B106 s east of UTC at the copy — a stamp $DM5B106 s before the copy in that zone reads, taken as UTC, after it (F9)"
dmecase106 "106-5b3" NOTYET "m60000|RUN followup_scan" "m59990|Cycle complete in 10s. Jobs run: followup_scan" "m$DM5B106|ERROR followup_send: RH106SECRET-me mirror fixture" "p700|RUN followup_close"
ST5B3_106="$(cut -d, -f3 <<<"$(kv106 "$MET106" at)" | tr '_' ' ')"
check "$(grep -cxF "  ok    ME-CA1 no ERROR line of followup_close, followup_scan, followup_run or followup_send in $PLOGW106 after the cycle the copy straddled (4 [master] lines read, stamped in the plugin's zone $MZ106; the control: 3 at or before the copy, 1 RUN followup_ after it)" <<<"$AFT106")|$(grep -cE '^  (FAIL|note)  ME-CA1 [0-9]+ ERROR line' <<<"$AFT106")|$( [ "$(n103 "$(TZ=UTC date -d "$ST5B3_106" +%s 2>/dev/null)")" -gt "$CU5B106" ] && echo utc-after)" "1|0|utc-after" \
  "106-5b3'' the mirror (F9): an ERROR stamped $DM5B106 s before the copy in the plugin's zone ($ST5B3_106), which read as UTC would fall after the copy, is not counted — 3 [master] lines at or before the copy, no ERROR note or FAIL (S:8204)"
dmecase106 "106-5b4" NOTYET "m60|RUN followup_scan" "p20|ERROR followup_scan: RH106SECRET-me fixture before the next cycle" "p600|RUN followup_scan" "p610|RUN followup_close"
check "$(grep -cxF "  note  ME-CA1 1 ERROR line(s) of followup_close, followup_scan, followup_run or followup_send stamped after the copy inside the cycle it straddled (2 [master] line(s), up to the next cycle's first RUN of a job it had run) — a job of that cycle met the old class beside the new; a note" <<<"$AFT106")|$(grep -cxF "  ok    ME-CA1 no ERROR line of followup_close, followup_scan, followup_run or followup_send in $PLOGW106 after the cycle the copy straddled (4 [master] lines read, stamped in the plugin's zone $MZ106; the control: 1 at or before the copy, 2 RUN followup_ after it)" <<<"$AFT106")" "1|1" \
  "106-5b4'' a straddle with no Cycle complete: it ends at the next RUN of a job it had already run (S:8158-8167) — the ERROR before that inside it, a note (S:8203); none after"
dmecase106 "106-5b5" FAILED "m120|RUN followup_scan" "m1|Cycle complete in 119s. Jobs run: followup_scan" "p20|ERROR followup_send: RH106SECRET-me fixture with no straddle" "p700|RUN followup_close"
check "$(grep -c '^  FAIL  ' <<<"$AFT106")|$(grep -cxF "  FAIL  ME-CA1 1 ERROR line(s) of the four follow-up jobs in $PLOGW106 after the cycle the copy straddled (by job: close 0 scan 0 run 0 send 1) — in the 24 h before the copy, for comparison: 0; counts only, the lines are in that file" <<<"$AFT106")|$(grep -c '^  note  ME-CA1 [0-9]* ERROR line' <<<"$AFT106")|$(grep -c '^  wait  UJ-ON NOT YET — ' <<<"$AFT106")" "1|1|0|1" \
  "106-5b5'' a copy no cycle straddled (a Cycle complete stamped a second before it): an ERROR of followup_send 20 s after the copy is after the straddle — ME-CA1's FAIL (S:8205), its only FAIL; UJ-ON still NOT YET"
KP5B7_106="$(keep106 p4-plog5b7 "$PLOG106")"; rm -f "$PLOG106"   # a FIXTURE (A9): plugin.log taken away for this one run, put back byte for byte after it
aft106 "106-5b7" NOTYET $L1ON106
check "$(grep -cxF "  note  ME-CA1 NOT ESTABLISHED — MASTER'S OUTPUT WAS LOOKED FOR IN $PLOGW106 AND COULD NOT BE READ THERE (log=absent tz=$MZ106): a host whose master writes elsewhere is not judged here; a note, which holds back nothing" <<<"$AFT106")|$(back106 p4-plog5b7)|$(sha256sum < "$PLOG106" 2>/dev/null | cut -c1-16)" "1|same|$KP5B7_106" \
  "106-5b7' the log absent: ME-CA1 NOT ESTABLISHED — COULD NOT BE READ THERE (log=absent, S:8132, S:8194), a note; plugin.log then put back byte for byte, with its owner, mode and time (A9)"
# 5c — stage A's reading out of the record: a FIXTURE (A9), the state file's UJ_A line taken out, put back byte for byte after
dpre106 "106-5c" "$P106" on - "on:$LIM106" "-:nothing:no-record"
UJ5C106="$(sget106 "$STATE106" UJ_A)"; KP5C106="$(keep106 p4-st5c "$STATE106")"
check "$( [ -n "$UJ5C106" ] && echo read)|$(dstdrop106 UJ_A)|$(grep -c '^UJ_A=' "$STATE106")" "read|removed|0" \
  "106-5c0 a FIXTURE (A9): this deploy's state file with its one UJ_A line — stage A's reading, S:13776 — taken out, every other byte as it was; the file kept aside first ($KP5C106)"
aft106 "106-5c1" NOTYET $L1ON106
check "$(grep -c "; judged on this run's values alone — stage A's reading is not on record (UJ_A in $STATE106); run it again after " <<<"$AFT106")|$(noteline "$AFT106" UJ-CA "stage A's reading is not on record (UJ_A in $STATE106) — U1–U3 stand alone")|$(back106 p4-st5c)|$( [ "$(sget106 "$STATE106" UJ_A)" = "$UJ5C106" ] && echo back)" "1|yes|same|back" \
  "106-5c' UJ_A out of the record: UJ-ON's NOT YET says it judged on this run's values alone (S:8063), UJ-CA says stage A's reading is not on record (S:8033); the state file then put back byte for byte, its UJ_A line as before"
# 5d — the copy's time out of the record: a FIXTURE (A9), the state file's COPY_AT line taken out (QF_COPY_AT kept), put back after
dpre106 "106-5d" "$P106" on - "on:$LIM106" "-:nothing:no-record"
CA5D106="$(sget106 "$STATE106" COPY_AT)"; KP5D106="$(keep106 p4-st5d "$STATE106")"
check "$( [ -n "$CA5D106" ] && echo read)|$(dstdrop106 COPY_AT)|$(grep -c '^COPY_AT=' "$STATE106")|$(grep -c '^QF_COPY_AT=' "$STATE106")" "read|removed|0|1" \
  "106-5d0 a FIXTURE (A9): this deploy's state file with its one COPY_AT line (S:13869) taken out — QF_COPY_AT kept — every other byte as it was; the file kept aside first ($KP5D106)"
aft106 "106-5d1" FAILED $L1ON106
check "$(grep -c '^  FAIL  ' <<<"$AFT106")|$(grep -cxF "  FAIL  UJ-ON FAIL — the copy's time is not on record (COPY_AT in $STATE106), so nothing since the copy can be counted" <<<"$AFT106")|$(noteline "$AFT106" UJ-CA "the copy's time is not known — the closes, expiries and sends since the copy are not counted")|$(noteline "$AFT106" ME-CA1 "NOT ESTABLISHED — THE COPY'S TIME IS NOT KNOWN (unset)")|$(grep -c '^  wait  UJ-ON ' <<<"$AFT106")|$(back106 p4-st5d)|$( [ "$(sget106 "$STATE106" COPY_AT)" = "$CA5D106" ] && echo back)|$(copyok106)" \
      "1|1|yes|yes|0|same|back|near" \
  "106-5d' COPY_AT out of the record: UJ-ON's one FAIL, the copy's time is not on record (S:8080); UJ-CA's note (S:8034) and ME-CA1's (S:8190); no NOT YET; the state file then put back byte for byte, COPY_AT again within 60 s of QF_COPY_AT"
# 5e — this run's reading fails: the uj hook (A8), a FIXTURE armed just before the run and removed just after (it is not one-shot)
dpre106 "106-5e" "$P106" on - "on:$LIM106" "-:nothing:no-record"
F5E0_106="$(grep -c '^uj$' "$SB/ujfail106.fired" 2>/dev/null)"
check "$( [ -e "$SB/.ujfail106" ] && echo armed || echo unarmed)" "unarmed" "106-5e0 the uj hook's trigger is absent before it is armed"
: > "$SB/.ujfail106"
aft106 "106-5e1" FAILED $L1ON106
rm -f "$SB/.ujfail106"
check "$(grep -c '^  FAIL  ' <<<"$AFT106")|$(grep -c "^  FAIL  UJ-ON FAIL — this run's reading could not be made (unread(.*)): whether F3 works is not shown$" <<<"$AFT106")|$(noteline "$AFT106" UJ-CA "U1–U3 could not be read after the copy (unread(" "— NOT ESTABLISHED")|$(( $(n103 "$(grep -c '^uj$' "$SB/ujfail106.fired" 2>/dev/null)") - $(n103 "$F5E0_106") ))|$( [ -e "$SB/.ujfail106" ] && echo armed || echo removed)" \
      "1|1|yes|1|removed" \
  "106-5e' this run's reading fails (the uj hook refused QF_PHP's uj call once): UJ-ON FAIL — this run's reading could not be made (S:8062), its one FAIL, exit 1; UJ-CA NOT ESTABLISHED (S:8037); the trigger removed and gone (A9)"
# 5f — pass 1 (A3): the main loop's 200 places and expireStale's 200 all go to gone conversations, nothing expires: NOT YET
dpre106 "106-5f" "$P106" on - "on:$LIM106" "-:nothing:no-record"
SO5F106="$(dsto106 "$LIM106" $(( $(fnow104) + 601 )) 9000001 9000400)"   # the first close pass as this rehearsal forecasts it (p1_*), at the moment it starts
master106 "106-5f0"
CL5F106="$(dclose106)"
check "$( [ "$(n103 "$(kv106 "$SO5F106" p1_cn)")" -ge 200 ] && echo ge200):$(kv106 "$SO5F106" p1_ex)|$(kv106 "$CL5F106" control):$(kv106 "$CL5F106" expired)|$( [ "$(kv106 "$CL5F106" cancelled)" = "$(kv106 "$SO5F106" p1_cn)" ] && echo forecast)|$(ctl106)" \
      "ge200:0|held:0|forecast|after" \
  "106-5f1 pass 1 as this rehearsal forecast it (staleorder: $(kv106 "$SO5F106" p1_main), then $(kv106 "$SO5F106" p1_xs)): it cancelled $(kv106 "$CL5F106" cancelled) — 200 or more — and expired none (rh106q.py closer); followup_close ran after the copy and ended — the control holds"
aft106 "106-5f2" NOTYET $L1ON106
NY5F106="$(sed -nE 's/^  wait  UJ-ON NOT YET — the close passes. places went to gone conversations — ([0-9]+) closed cancelled by the closer since followup_close started at ([^,]+), none expired, ([0-9]+) stale follow-up\(s\) with a present conversation left at .*/\1 \2 \3/p' <<<"$AFT106")"
ST5F106="$(dsto106 "$LIM106" "$(kv106 "$CL5F106" fc_last)")"
check "${NY5F106:-none}|$( [ "$(n103 "${NY5F106%% *}")" -ge 200 ] && echo ge200)" "$(kv106 "$CL5F106" on_cancelled_t) $(kv106 "$CL5F106" t) $(kv106 "$ST5F106" stale_present)|ge200" \
  "106-5f' UJ-ON NOT YET — the close passes' places went to gone conversations (S:8094-8095): its cancels at or after the pass's start (200 or more, ex 0), that start, and the stale rows with a present conversation left then — each as this rehearsal reads them (closer, staleorder)"
# 5g — the limit-only note (S:8079) and check it's limit arm (S:16017): another limit now than stage A's — PASSED, and the owner's
# steps printed (RH106-15); then the limit set back
dpre106 "106-5g" "$P106" on - "on:$LIM106" "-:nothing:no-record"
O5G106="$(setcfg106 followup_max_age_hours 504)"
check "$(grep -cx '  followup_max_age_hours = 504' <<<"$O5G106")|$(setrc106)|$(kv106 "$(dnow106)" hours)" "1|0|504" \
  "106-5g0 followup_max_age_hours set to 504 through the installed set_config as the book's owner, under the clock (it prints \"  followup_max_age_hours = 504\"); this rehearsal reads 504"
aft106 "106-5g1" PASSED $L1ON106
check "$(grep -cxF "  note  UJ-ON not judged on this run — stage A's gate held, but followup_max_age_hours is 504 h now and was $LIM106 h at stage A, and UJ-ON judges F3 only by the limit stage A read: set it back to $LIM106 h and, once a close pass has run after that (followup_close runs every 10 minutes), run --after-only again to have F3 judged — or, to keep the new limit, a rollback and then a --redeploy, whose stage A reads it. Until then F3 is unproven, and the steps that wait for it (6, 11, 12) would follow without that proof; a note, which holds back nothing" <<<"$AFT106")|$(has "$AFT106" "UJ-ON judges F3 on it only once followup_max_age_hours is back at stage A's $LIM106 h and a close pass has run after that (UJ-ON above) — run it only then: a run before that pass judges F3 by passes made under the other limit, and can read FAIL; while the limit differs it gives that note, no verdict, and the run reads PASSED without that proof")|$(grep -c '^  wait  UJ-ON ' <<<"$AFT106")|$(dnooffer106)" \
      "1|yes|0|none" \
  "106-5g1' the limit-only note (S:8079): stage A's gate held, the limit 504 h now against $LIM106 h then — a note, no verdict; check it's limit arm (S:16017); the run PASSED; as_back_on silent (no rollback on record)"
check "$(has "$AFT106" "  the owner's steps root docs/75 §F — $V106 serves and this --after-only PASSED, so the steps that")|$(grep -F -- "--key followup_daily_cap --value '<CAP>' 2>&1 | sed '/^  AI SETTINGS\$/,\$d' $(LOGW106 daily-cap)" <<<"$AFT106" | grep -c "^      docker exec -u $(owner104) -e DN_DATA_DIR=")|$(grep -cxF "      cd $REPO && bash scripts/sales-followup.sh on chase" <<<"$AFT106")|$(grep -cxF "      cd $REPO && bash scripts/sales-followup.sh --check chase" <<<"$AFT106")|$(grep -F -- "--key followup_run_limit --value '<LIMIT>' 2>&1 | sed '/^  AI SETTINGS\$/,\$d' $(LOGW106 run-limit)" <<<"$AFT106" | grep -c "^      docker exec -u $(owner104) -e DN_DATA_DIR=")" \
      "yes|1|1|1|1" \
  "106-5g2 a PASSED --after-only prints the owner's steps (S:16103-16143, RH106-15): followup_daily_cap's command with <CAP> (S:16109); step 6 — chase is off, so on chase (S:16134), then its check (S:16137); followup_run_limit's with <LIMIT> (S:16142) — each alone on its line"
O5G106="$(setcfg106 followup_max_age_hours "$LIM106")"
check "$(grep -cx "  followup_max_age_hours = $LIM106" <<<"$O5G106")|$(setrc106)|$(kv106 "$(dnow106)" hours)" "1|0|$LIM106" \
  "106-5g3 the limit set back to $LIM106 through the installed set_config (it prints \"  followup_max_age_hours = $LIM106\")"
# 5h — the 'now' note (S:8073) with its wait clause, and check it's 'now' arm (S:16017): followup_enabled off now; then on again
dpre106 "106-5h" "$P106" on - "on:$LIM106" "-:nothing:no-record"
O5H106="$(setcfg106 followup_enabled 0)"
check "$(grep -cx '  followup_enabled = 0' <<<"$O5H106")|$(setrc106)|$(kv106 "$(dnow106)" en)" "1|0|off" \
  "106-5h0 followup_enabled set to 0 through the installed set_config (it prints \"  followup_enabled = 0\"); read off as the jobs read it"
aft106 "106-5h1" PASSED $L1ON106
check "$(grep -cxF "$NOWEN106" <<<"$AFT106")|$(has "$AFT106" "$NOWARM106")|$(grep -c '^  wait  UJ-ON ' <<<"$AFT106")|$(dnooffer106)" "1|yes|0|none" \
  "106-5h1' the 'now' note (S:8073): followup_enabled is off now — with its wait clause (run --after-only only after a close pass under the gate; a run before that can read FAIL); check it's 'now' arm (S:16017); the run PASSED; as_back_on silent"
O5H106="$(setcfg106 followup_enabled 1)"
check "$(grep -cx '  followup_enabled = 1' <<<"$O5H106")|$(setrc106)|$(kv106 "$(dnow106)" en)" "1|0|on" \
  "106-5h2 followup_enabled set back to 1 through the installed set_config (it prints \"  followup_enabled = 1\")"
# 5i — pass 2: the gone rows spent, F3 expires the present stale rows — PASS, F3 works; as_back_on offers nothing: no rollback yet
dpre106 "106-5i" "$P106" on - "on:$LIM106" "-:nothing:no-record"
SO5I106="$(dsto106 "$LIM106" $(( $(fnow104) + 601 )))"
master106 "106-5i0"
aft106 "106-5i1" PASSED $L1ON106
dpasschk106 "106-5i2" "$(kv106 "$SO5I106" p1_ex)"
check "$( [ "$(n103 "$(kv106 "$SO5I106" p1_ex)")" -ge 1 ] && echo some)|$(dnooffer106)|$(kv106 "$(q106 rblast "$OUT106" "$STATE106")" records)" "some|none|0" \
  "106-5i3 pass 2 expired what this rehearsal forecast ($(kv106 "$SO5I106" p1_xs), staleorder before the pass) — at least one; no rollback of $V106 is on record, so as_back_on offers nothing"

echo; echo "== 106-5D2. a copy of its own (F7): the limit note, a pass under the larger limit, the deterministic FAIL before a pass, then PASS; the turn-back-on offer first seen =="
dround106 D2 "-:nothing:no-record"
dstale106 "106-5D2f" 60 61
O5D2_106="$(setcfg106 followup_max_age_hours 504)"
check "$(grep -cx '  followup_max_age_hours = 504' <<<"$O5D2_106")|$(setrc106)|$(kv106 "$(dnow106)" hours)" "1|0|504" "106-5D2g the limit set to 504 through the installed set_config"
aft106 "106-5D2h" PASSED $L1ON106
check "$(noteline "$AFT106" UJ-ON "not judged on this run — stage A's gate held, but followup_max_age_hours is 504 h now and was $LIM106 h at stage A")|$(has "$AFT106" "UJ-ON judges F3 on it only once followup_max_age_hours is back at stage A's $LIM106 h")|$(grep -c '^  wait  UJ-ON ' <<<"$AFT106")" "yes|yes|0" \
  "106-5D2h' the limit note on a fresh copy (S:8079) and check it's limit arm (S:16017), before any pass: PASSED"
doffer106 "106-5D2i"
master106 "106-5D2j"
CL5D2_106="$(dclose106)"; T5D2_106="$(kv106 "$CL5D2_106" fc_last)"
check "$(kv106 "$CL5D2_106" control):$(kv106 "$CL5D2_106" expired)|$(kv106 "$(dsto106 504 "$T5D2_106")" stale_present)|$(kv106 "$(dsto106 "$LIM106" "$T5D2_106")" stale_present)" "held:0|0|2" \
  "106-5D2k a close pass under 504 h expired nothing: the control holds, nothing closed expired since the copy; at its start nothing is stale with a present conversation under 504 h, the two planted are under $LIM106 h (staleorder)"
O5D2_106="$(setcfg106 followup_max_age_hours "$LIM106")"
check "$(grep -cx "  followup_max_age_hours = $LIM106" <<<"$O5D2_106")|$(setrc106)|$(kv106 "$(dnow106)" hours)" "1|0|$LIM106" "106-5D2l the limit set back to $LIM106 — no close pass after it"
aft106 "106-5D2m" FAILED $L1ON106
check "$(grep -c '^  FAIL  ' <<<"$AFT106")|$( [ "$(pig106)" = "$PIGP106" ] && echo kept)" "1|kept" \
  "106-5D2m' the --after-only before any pass under $LIM106 h: one FAIL, exit 1 (above), deterministic — the piggyback record unchanged since the plant (A2)"
dfailchk106 "106-5D2m''" 2
master106 "106-5D2n"
aft106 "106-5D2o" PASSED $L1ON106
dpasschk106 "106-5D2o'" 2
check "$(dnooffer106)" "none" "106-5D2o'' the key on again (106-5D2i'): as_back_on offers nothing"

echo; echo "== 106-5D3. a copy of its own (RH106-8): followup_enabled off, a pass, the 'now' note; on again, the FAIL before a pass, then PASS =="
dround106 D3 "written:offer-if-off"
dstale106 "106-5D3f" 62 63
O5D3_106="$(setcfg106 followup_enabled 0)"
check "$(grep -cx '  followup_enabled = 0' <<<"$O5D3_106")|$(setrc106)|$(kv106 "$(dnow106)" en)" "1|0|off" "106-5D3g followup_enabled set to 0 through the installed set_config"
master106 "106-5D3h"
CL5D3_106="$(dclose106)"
check "$(kv106 "$CL5D3_106" control):$(kv106 "$CL5D3_106" expired):$(kv106 "$CL5D3_106" cancelled)|$(ctl106)" "held:0:0|after" \
  "106-5D3i a close pass with followup_enabled off: followup_close started after the copy and ended (its last run moved — the control holds) and closed nothing: the job returns at once while followup_enabled is empty (bf29cbd cron/followup_close.php:33)"
aft106 "106-5D3j" PASSED $L1ON106
check "$(grep -cxF "$NOWEN106" <<<"$AFT106")|$(has "$AFT106" "$NOWARM106")|$(grep -c '^  wait  UJ-ON ' <<<"$AFT106")" "1|yes|0" \
  "106-5D3j' the 'now' note with its wait (S:8073) and check it's 'now' arm (S:16017), after a pass made while the gate did not hold: PASSED"
doffer106 "106-5D3k"
O5D3_106="$(setcfg106 followup_enabled 1)"
check "$(grep -cx '  followup_enabled = 1' <<<"$O5D3_106")|$(setrc106)|$(kv106 "$(dnow106)" en)" "1|0|on" "106-5D3l followup_enabled set back to 1 — no close pass after it"
aft106 "106-5D3m" FAILED $L1ON106
check "$(grep -c '^  FAIL  ' <<<"$AFT106")|$( [ "$(pig106)" = "$PIGP106" ] && echo kept)" "1|kept" \
  "106-5D3m' the --after-only after followup_enabled went back on, before any pass under it: one FAIL, exit 1 — the 'now' note's warning made true; the piggyback record unchanged since the plant (A2)"
dfailchk106 "106-5D3m''" 2
master106 "106-5D3n"
aft106 "106-5D3o" PASSED $L1ON106
dpasschk106 "106-5D3o'" 2
check "$(dnooffer106)" "none" "106-5D3o'' the key on again (106-5D3k'): as_back_on offers nothing"

echo; echo "== 106-5D4. a copy of its own: nothing stale for F3 to close — one pass, then PASS =="
dround106 D4 "written:offer-if-off"
SO5D4_106="$(dsto106 "$LIM106" "$(fnow104)")"
check "$(kv106 "$SO5D4_106" stale_present)|$( [ "$(n103 "$(kv106 "$SO5D4_106" open_gone)")" -lt 200 ] && echo lt200)" "0|lt200" \
  "106-5D4f nothing stale with a present conversation under $LIM106 h by this rehearsal's own reading, and fewer than 200 gone rows open ($(kv106 "$SO5D4_106" open_gone))"
master106 "106-5D4g"
aft106 "106-5D4h" PASSED $L1ON106
CL5D4_106="$(dclose106)"; ST5D4_106="$(dsto106 "$LIM106" "$(kv106 "$CL5D4_106" fc_last)")"
check "$(sed -nE 's/^  ok    UJ-ON PASS — nothing stale for F3 to close: followup_close started at ([^,]+), after the copy, and ended; .*/\1/p' <<<"$AFT106")|$(kv106 "$CL5D4_106" control):$(kv106 "$CL5D4_106" on_expired)|$(kv106 "$ST5D4_106" stale_present)" "$(kv106 "$CL5D4_106" t)|held:0|0" \
  "106-5D4h' UJ-ON PASS — nothing stale for F3 to close (S:8091): its start the close job's last run, nothing closed expired since the copy, expireStale's SELECT at that start selecting nothing with a present conversation — each by this rehearsal's own reading"
doffer106 "106-5D4i"

echo; echo "== 106-5D5. a copy of its own (S2, RH106-3): F3 taken out of the installed close job — UJ-ON's FAIL and R1's; put back, PASS =="
dround106 D5 "written:offer-if-off"
dstale106 "106-5D5f" 64 65
FC106="$PD/cron/followup_close.php"; FCG106="$(git -C "$REPO" show "$P106F:$P/cron/followup_close.php" | sha256sum | cut -c1-64)"
FCS5_106="$(stat -c '%u:%g:%a' "$FC106" 2>/dev/null)"; FCK5_106="$(keep106 p4-fc5 "$FC106")"
# A FIXTURE (A9), a weakened copy of the release: bf29cbd's cron/followup_close.php lines 84–93 — F3's comment and its block, the
# StaffJobsGate require and the expireStale() call — replaced by one line keeping $fcExpired 0, so line 95's echo still reads it
FCX5_106="$(python3 -I - "$FC106" "$FCG106" <<'PY' || { echo "rh106:f3cut" >> "$SB/anchor-missing"; echo miss; }
import sys, hashlib
p, want = sys.argv[1], sys.argv[2]
b = open(p, 'rb').read()
assert hashlib.sha256(b).hexdigest() == want, 'the installed file is not the pin\'s'
s = b.decode('utf-8')
block = '\n'.join([
    "// 5.18.106 (root docs/75 \u00a7C F3; G1, G4, G5): on Uganda, after the cases above (a reply closes 'replied' first), up to 200",
    "// open enquiry follow-ups whose customer \u2014 by the row and by the conversation \u2014 has been quiet longer than",
    "// followup_max_age_hours close 'expired', oldest first, with no model call; their waiting drafts leave every list with",
    "// them. Its own clock: this file sets no $now. Elsewhere nothing here runs.",
    "require_once $pluginRoot . '/lib/StaffJobsGate.php';",
    "$fcExpired = 0;",
    "if (StaffJobsGate::applies($config, $dataDir)) {",
    "    $fcStale = FollowUpService::staleBefore($config, time());",
    "    if ($fcStale !== null) $fcExpired = $svc->expireStale($fcStale, 200, FollowUpService::staleHours($config), 'closer');",
    "}",
]) + '\n'
assert s.count(block) == 1, s.count(block)
assert s[:s.index(block)].count('\n') == 83, s[:s.index(block)].count('\n')   # it begins on line 84
repl = "$fcExpired = 0;   // 5.18.106's rehearsal (D5): F3's block, lines 84-93, taken out of the installed copy - put back byte for byte after\n"
s2 = s.replace(block, repl, 1)
assert s.count('expireStale(') == 1 and s2.count('expireStale(') == 0 and s2.count('$fcExpired = 0;') == 1 and len(s2) == len(s) - len(block) + len(repl)
assert s2.count("if ($closed || $fcExpired) echo") == 1
with open(p, 'r+b') as f:
    f.write(s2.encode('utf-8'))
    f.truncate()
print('cut')
PY
)"
check "$FCX5_106|$(sha256sum < "$FC106" | cut -c1-64 | grep -cx "$FCG106")|$(grep -c 'expireStale(' "$FC106")|$("$PHPBIN" -l "$FC106" 2>&1 | grep -c '^No syntax errors detected')|$(stat -c '%u:%g:%a' "$FC106")" "cut|0|0|1|$FCS5_106" \
  "106-5D5g a weakened copy of the RELEASE, installed (S2): F3's block — bf29cbd's cron/followup_close.php lines 84–93 — taken out of the installed close job by an asserted edit (the pin's bytes first; the block once, at line 84; \$fcExpired kept 0), still PHP (php -l), its owner and mode kept; the original kept aside ($FCK5_106)"
master106 "106-5D5h"
CL5D5_106="$(dclose106)"
check "$(kv106 "$CL5D5_106" control):$(kv106 "$CL5D5_106" expired)|$(kv106 "$(dsto106 "$LIM106" "$(kv106 "$CL5D5_106" fc_last)")" stale_present)" "held:0|2" \
  "106-5D5i a close pass with F3 taken out: it ran after the copy and ended, and expired nothing — the two planted still stale with a present conversation at its start (staleorder)"
aft106 "106-5D5j" FAILED $L1ON106
check "$(grep -cxF '  FAIL  R1 installed files that differ: cron/followup_close.php' <<<"$AFT106")|$(grep '^  FAIL  ' <<<"$AFT106" | grep -v '^  FAIL  UJ-ON FAIL — F3 did not expire: ' | grep -cvF 'cron/followup_close.php')|$( [ "$(grep -c '^  FAIL  ' <<<"$AFT106")" -ge 2 ] && echo some)|$( [ "$(pig106)" = "$PIGP106" ] && echo kept)" "1|0|some|kept" \
  "106-5D5j' R1 FAILs naming cron/followup_close.php (S:14400); every other FAIL is UJ-ON's or names that one file — nothing else of the release is touched; exit 1; the piggyback record unchanged since the plant (A2)"
dfailchk106 "106-5D5j''" 2
check "$(back106 p4-fc5)|$(sha256sum < "$FC106" | cut -c1-64 | grep -cx "$FCG106")|$(stat -c '%u:%g:%a' "$FC106")|$(grep -c 'expireStale(' "$FC106")" "same|1|$FCS5_106|1" \
  "106-5D5k the close job put back (A9): byte-equal to bf29cbd's (sha256 against Git), its owner and mode as they were, F3's expireStale() call there again"
master106 "106-5D5l"
aft106 "106-5D5m" PASSED $L1ON106
dpasschk106 "106-5D5m'" 2
check "$(okline "$AFT106" R1 all "files $V106 changes are installed exactly as $P106 has them")" "yes" "106-5D5m'' R1 ok again (S:14400): every file $V106 changes installed as $P106 has it"
doffer106 "106-5D5n"

echo; echo "== 106-5D6. a copy of its own (RH106-7): the --redeploy taken on a limit of 0 — U4's capitals note before DEPLOY; the 'now' note with stage A's; set back, a pass, UJ-ON will not judge; weakened copy UJG =="
dround106 D6 "written:offer-if-off" 0 misread
U4N6_106="  note  U4 UJ-ON WILL NOT JUDGE F3 FOR THIS DEPLOY: followup_max_age_hours was not a positive number at stage A. STAGE A'S READING IS KEPT IN THE STATE FILE FOR THE LIFE OF THE DEPLOY, SO F3 WOULD STAY UNPROVEN AND THE STEPS THAT WAIT FOR IT (6, 11, 12) WOULD FOLLOW WITHOUT THAT PROOF; AFTERWARDS A JUDGED UJ-ON NEEDS A ROLLBACK AND THEN A --redeploy. TO HAVE IT JUDGED, DO NOT DEPLOY ON THIS READING: PUT IT RIGHT, ANSWER ANYTHING BUT DEPLOY BELOW, THEN RUN THIS AGAIN"
LU4_106="$(grep -nxF "$U4N6_106" <<<"$DRD106_OUT" | head -1 | cut -d: -f1)"; LTD_106="$(grep -n '^  Type DEPLOY to deploy ' <<<"$DRD106_OUT" | head -1 | cut -d: -f1)"
check "$(grep -cxF "$U4N6_106" <<<"$DRD106_OUT")|$( [ "$(n103 "$LU4_106")" -gt 0 ] && [ "$(n103 "$LU4_106")" -lt "$(n103 "$LTD_106")" ] && echo before)|$(noteline "$DRD106_OUT" U4 'the settings as the jobs read them' 'followup_max_age_hours not a positive number — F2 and F3 inactive: nothing is expired on a misread')|$(kv106 "$(sget106 "$STATE106" UJ_A)" hours)|$(has "$DRD106_OUT" "$WILLNOT106")" \
      "1|before|yes|misread|yes" \
  "106-5D6f U4's capitals note with its DEPLOY clause (S:7983) printed before \"  Type DEPLOY to deploy\" (S:13838), U4 reading the limit not a positive number (S:7981); DEPLOY typed all the same — stage A's reading kept (hours misread); the deploy's check it says UJ-ON will not judge F3 for it (S:16019)"
aft106 "106-5D6g" PASSED $L1ON106
check "$(grep -cxF "  note  UJ-ON not judged — its gate did not hold: followup_max_age_hours is not a positive number now; followup_max_age_hours was not a positive number at stage A. F3 then has nothing to do, or another limit to do it by. Stage A's gate did not hold either, so UJ-ON will not judge F3 for this deploy whatever is put right now (a judged UJ-ON needs a rollback and then a --redeploy); a note, which holds back nothing" <<<"$AFT106")|$(has "$AFT106" "$WILLNOT106")|$(grep -c '^  wait  UJ-ON ' <<<"$AFT106")" "1|yes|0" \
  "106-5D6g' before the limit is set back: the 'now' note naming both, with \"Stage A's gate did not hold either\" (S:8073); check it's will-not-judge arm (S:16019); PASSED"
doffer106 "106-5D6h"
O5D6_106="$(setcfg106 followup_max_age_hours "$LIM106")"
check "$(grep -cx "  followup_max_age_hours = $LIM106" <<<"$O5D6_106")|$(setrc106)|$(kv106 "$(dnow106)" hours)" "1|0|$LIM106" "106-5D6i the limit set back to $LIM106 through the installed set_config"
master106 "106-5D6j"
aft106 "106-5D6k" PASSED $L1ON106
check "$(grep -cxF "  note  UJ-ON not judged for this deploy — its gate did not hold at stage A: followup_max_age_hours was not a positive number at stage A. F3 may be running now, but this deploy's stage A reading is fixed in $STATE106 (a deploy run while $V106 serves is an --after-only of this one), so UJ-ON cannot judge F3 for this deploy: F3 stays unproven, and the steps that wait for it (6, 11, 12) would follow without that proof. A judged UJ-ON needs a rollback and then a --redeploy, its stage A taken with the gate holding; a note, which holds back nothing" <<<"$AFT106")|$(has "$AFT106" "$WILLNOT106")|$(grep -c '^  wait  UJ-ON ' <<<"$AFT106")|$(dnooffer106)" "1|yes|0|none" \
  "106-5D6k' the limit set back and a close pass run: UJ-ON not judged for this deploy — its gate did not hold at stage A (S:8076); check it's will-not-judge arm (S:16019); PASSED; the key on again, as_back_on offers nothing"
# Weakened copy UJG — UJ-ON's stage-A-only branch merged into the 'now' branch (S:8073's condition widened to stage A's gate), on
# this same state: the stage-A note above must be gone, the 'now' note in its place — caught
anc106 UJG '  if [ -n "$g" ]; then g="$g$ga$lim"; UJ_ON_GATE="now"; note "UJ-ON not judged — its gate did not hold:' \
           '  if [ -n "$g$ga" ]; then g="$g$ga$lim"; UJ_ON_GATE="now"; note "UJ-ON not judged — its gate did not hold:'
AMU6_106="$(grep -c '^weak106:UJG' "$SB/anchor-missing" 2>/dev/null)"
MUJG106="$(weak106 UJG UJG)"; fresh106; OUJG106="$(R106_MUTANT_OUT=1 run106c --script "$MUJG106" --after-only)"; rm -f "$MUJG106"
check "$(grep -cF -- "$(cat "$AN106/UJG.a")" "$DEPLOY106")|$(( $(n103 "$(grep -c '^weak106:UJG' "$SB/anchor-missing" 2>/dev/null)") - $(n103 "$AMU6_106") ))|$(has "$OUJG106" "UJ-ON not judged for this deploy — its gate did not hold at stage A")|$(noteline "$OUJG106" UJ-ON "not judged — its gate did not hold: followup_max_age_hours was not a positive number at stage A." "Stage A's gate did not hold either")|$( [ -e "$MUJG106" ] && echo left || echo removed)" \
      "1|0|no|yes|removed" \
  "106-5D6l weakened copy UJG — UJ-ON's stage-A-only branch merged into the 'now' branch (its condition \$g made \$g\$ga, the anchor once in the script) on this same state: the stage-A note 106-5D6k' read is gone and the 'now' note stands in its place — caught; the copy removed"
# What 106-6 begins from
dpre106 "106-5z0" "$P106" on - "on:$LIM106" "written:offer-if-off"
dwal106; UZ5_106="$(q106 u2u3 "$DATA/plugin.sqlite3" "$PD" "$(fnow104)")"; CLK5Z106="$(fnow104)"
check "$(kv106 "$UZ5_106" f5_multi)|$(kv106 "$(sget106 "$STATE106" UJ_A)" hours)|$(sget106 "$RBX106_REC" AS_RB1)|$( [ -e "$SB/.ujfail106" ] && echo armed || echo none)|$(lock106)|$( [ "$(sha256sum < "$FC106" | cut -c1-64)" = "$FCG106" ] && echo pin)|$(n103 "$(grep -c '\] \[master\] ' "$PLOG106" 2>/dev/null)")|$( [ -e "$REPO/scripts/.UJG.sh" ] && echo left || echo none)" \
      "1/2|misread|written|none|absent|pin|0|none" \
  "106-5z the state 106-6 begins from: $P106 serving (D6's --redeploy, its stage A read on a limit of 0), followup_auto_send on, no claim switch, followup_enabled on, the limit $LIM106 h; J3's pair still waiting (F5 1/2, nothing sent); the latest rollback record D6's typed one, AS_RB1=written ($(basename "$RBX106_REC")); no uj trigger, no index lock, the close job the pin's, plugin.log without a [master] line, no weakened copy left; the clock at $(kday104 "$CLK5Z106") $(TZ=Africa/Kampala date -d "@$CLK5Z106" +%H:%M) Kampala"
# ══════════════════════════════════════════════════════════════════════════════════════
# 5.18.106's rehearsal, part 5 — 106-6 (the typed rollback's cases) and 106-7 (redeploys, V1's automatic rollback with its
# put-back made to fail, and the offer to turn followup_auto_send back on), as the rehearsal's design (18 Nov, v2) §C and §D
# set them. It takes the state 106-5 leaves (106-5z): 5.18.106 (bf29cbd) serving from D6's --redeploy, followup_auto_send
# on, no claim switch, followup_enabled on, followup_max_age_hours 336, J3's pair waiting, J1's two fresh refused drafts
# approved, the latest rollback record D6's typed one (AS_RB1=written).
#   Every typed rollback that is to read PASSED is rbx106 (its quarantine --check inside). Every other rollback — a weakened
# copy's, one that FAILs by design, V1's own, --rollback with nothing to roll back — is followed by qcheck106, and so is every
# 5.18.106 deploy whose copy no rollback followed (V1 with its put-back made to fail): A14 of the next deploy needs a --check
# begun after every copy of this script, now that COPY_AT and RB_COPY_AT are on the clock (A1; Part 1's open point 1).
# Master runs only through master106 and, once, sender106 (106-6s): 5.18.106's sender in the window, after 6a's rollback
# has read J3's pair and its redeploy has copied 5.18.106 again.
#   The production writers this part runs, each as the book's owner through the stand-in docker, under the clock: set_config
# (setcfg106; setclr106 for --clear), set_evolution (setevo106 — --clear-key and --key -, a key the store row holds and the
# file lacks, 6a; --url, a key the file holds and the row lacks, 6b, 7d, 7f), the switch tool (on chase, 6f; CS-G1's off
# command as printed, 7a). The FIXTURES — states no production writer makes — each said so where it is planted, and undone
# with its undo asserted: '3' against 3 (6c); an undecodable kyc_config.json and followup_auto_send in <data>/config.json
# (6d); the store-only offers keys (6f); and, where a production writer's side effect has to be taken back, kyc_config.json
# and the vault put back byte for byte from a copy kept first (keep106/back106).
#   This part's number of 106's range: +256700106020 (ph106 20), the planted quote_company_phone (6b) — in NAMES104 and
# FORMS106 since 106-infra f, so every sweep reads it; 21–59 are this part's and unused. Its one planted text, the
# quote_company_email RH106SECRET-quote@example.test, carries NM106F's marker. Neither may reach a log a sweep reads: 6b
# asserts it of the owner's command's own log; set_config's and set_evolution's outputs go to $R106/setcfg.txt and
# $R106/setevo.txt, which no sweep reads.
#   Order: 106-6 runs 6a, the sender (6s), 6b, 6e (6b's last step — the rollback run again from a redeploy — IS 6e's case),
# 6c, 6d, 6f; 106-7 runs 7a–7d, 7h, then 7e–7g and 7i — 7h's PASSED deploy with the key on is the redeploy 7e begins from,
# which saves a rollback and a redeploy. The design's "typed rollback → --redeploy → --value 1 → V1" (7e, 7f) is run as
# typed rollback → the owner's --value 1 → the V1 deploy: a deploy while 5.18.106 serves is an --after-only (S:12365), so V1
# can fire only on a deploy over 7835906, and only a key on before it lets AS-RB1 write. 6c and 6d answer the rollback's
# question no: the write they show cannot happen is AS-RB0's to say before ROLLBACK, and a declined rollback changes nothing,
# so no redeploy follows either.
# ══════════════════════════════════════════════════════════════════════════════════════
echo; echo "== 106-6. the typed rollback's cases: a key only the store row holds (AS-RB1 writes), one only the file holds (it cannot: the owner's command; AC3 caught), already off (RBN caught), '3' against 3, an undecodable file, step 6 on with the offers keys — and between them 5.18.106's sender in the window =="
# ── This part's helpers (none is defined in Parts 1–4; none redefines one of theirs). ──
pls106() { sed -e 's/^k:/kyc_config.json /' -e 's/,s:/, its store row /' -e 's#,p:#, <plugin>/data/config.json #' -e 's#,d:#, <data>/config.json #' <<<"$(kv106 "$1" asplaces)"; }   # pls106 KYCPLACES-LINE — asplaces= in as_places_say's own words (deploy-5.18.106.sh:8679)
ascmd106() {   # ascmd106 0|1 COMMAND — form when COMMAND (a printed line, its six spaces off) is the owner's own command for
               # followup_auto_send as as_owner_cmd builds it (deploy-5.18.106.sh:12333-12337; LOGW :1563): set_config as the book's
               # owner on the data folder (its container path not assumed, p4-open 11), --value V, the output cut where the
               # settings listing begins, its own log; else other
  case "$2" in "docker exec -u $(owner104) -e DN_DATA_DIR="*" ucrm php $IN_CONTAINER/tools/set_config.php --key followup_auto_send --value $1 2>&1 | sed '/^  AI SETTINGS\$/,\$d' $(LOGW106 "autosend-$1")") echo form ;; *) echo other ;; esac
}
half106() {   # half106 OUTPUT before|after PREFIX — the lines before the first one beginning PREFIX, or from it on (bytes)
  python3 -I -c 'import os, sys
w, p = sys.argv[1], os.fsencode(sys.argv[2]); ls = sys.stdin.buffer.read().split(b"\n")
i = next((k for k, l in enumerate(ls) if l.startswith(p)), len(ls))
sys.stdout.buffer.write(b"\n".join(ls[:i] if w == "before" else ls[i:]))' "$2" "$3" <<<"$1"
}
rd106() {   # rd106 close ID… | drafts FU | sends FU — read-only, the book's WAL folded in first: close → ID:<close_reason|open|nofu>,…;
            # drafts → the follow-up's drafts by status, sorted, comma-joined; sends → its followup_sends rows
  dwal106
  python3 -I - "$DATA/plugin.sqlite3" "$@" <<'PY'
import sqlite3, sys
c = sqlite3.connect('file:' + sys.argv[1] + '?mode=ro', uri=True)
try:
    m, a = sys.argv[2], sys.argv[3:]
    if m == 'close':
        o = []
        for x in a:
            r = c.execute('SELECT closed_at, close_reason FROM followups WHERE id = ?', (int(x),)).fetchone()
            o.append('%s:%s' % (x, 'nofu' if r is None else ('open' if r[0] is None else (r[1] or '-'))))
        print(','.join(o) or '-')
    elif m == 'drafts':
        print(','.join(str(x[0]) for x in c.execute('SELECT status FROM followup_drafts WHERE followup_id = ? ORDER BY status', (int(a[0]),)).fetchall()) or '-')
    elif m == 'sends':
        print(c.execute('SELECT COUNT(*) FROM followup_sends WHERE followup_id = ?', (int(a[0]),)).fetchone()[0])
    else:
        print('unread:mode')
except (sqlite3.Error, ValueError, IndexError) as x:
    print('unread:%s' % type(x).__name__)
finally:
    c.close()
PY
}
kyceq106() {   # kyceq106 KEPT — <data>/kyc_config.json against a copy kept of it, key by key and by type, followup_auto_send aside
               # (AS-RB1's one write): same, or differs:<key names> — never a value
  python3 -I - "$DATA/kyc_config.json" "$1" <<'PY'
import json, sys
try:
    a = json.load(open(sys.argv[1], encoding='utf-8')); b = json.load(open(sys.argv[2], encoding='utf-8'))
    d = sorted(k for k in set(a) | set(b) if k != 'followup_auto_send' and (k not in a or k not in b or type(a[k]) is not type(b[k]) or a[k] != b[k]))
    print('same' if not d else 'differs:' + ','.join(d)[:200])
except (OSError, ValueError, TypeError, AttributeError) as x:
    print('unread:%s' % type(x).__name__)
PY
}
setevo106() {   # setevo106 [--key-from KEPT] ARG… — the INSTALLED tools/set_evolution.php as the book's owner on the live data folder,
                # under the clock, no proxy (its status asks the stand-in Evolution what instances it has: loopback only) — the
                # form of setcfg106; with --key-from, the evo_api_key a kept copy of kyc_config.json holds piped to its stdin (--key -:
                # never an argument, never printed, never in a variable). Its output kept in $R106/setevo.txt (no sweep reads it),
                # its exit status in $R106/setevo.rc (sevrc106)
  local kf="" o
  if [ "${1:-}" = --key-from ]; then kf="$2"; shift 2; fi
  if [ -n "$kf" ]; then
    o="$(python3 -I -c 'import json, sys; sys.stdout.write(str(json.load(open(sys.argv[1], encoding="utf-8")).get("evo_api_key", "")))' "$kf" \
          | env PATH="$SB/bin:$PATH" LD_PRELOAD="$CLKPRE104" RH104_CLOCK_FILE="$CLKF104" http_proxy= https_proxy= HTTP_PROXY= HTTPS_PROXY= NO_PROXY='*' \
              bash "$SB/bin/docker" exec -i -u "$(owner104)" -e "DN_DATA_DIR=$PDD104_IN" ucrm php "$IN_CONTAINER/tools/set_evolution.php" "$@" 2>&1; exit "${PIPESTATUS[1]}")"
  else
    o="$(env PATH="$SB/bin:$PATH" LD_PRELOAD="$CLKPRE104" RH104_CLOCK_FILE="$CLKF104" http_proxy= https_proxy= HTTP_PROXY= HTTPS_PROXY= NO_PROXY='*' \
          bash "$SB/bin/docker" exec -u "$(owner104)" -e "DN_DATA_DIR=$PDD104_IN" ucrm php "$IN_CONTAINER/tools/set_evolution.php" "$@" 2>&1 </dev/null)"
  fi
  echo "$?" >> "$R106/setevo.rc"; printf '%s\n' "$o" >> "$R106/setevo.txt"; printf '%s' "$o"
}
sevrc106() { tail -n1 "$R106/setevo.rc" 2>/dev/null || echo none; }
setclr106() {   # setclr106 KEY — set_config --key KEY --clear (the key out of the file and the store row: saveOverrides with '',
                # tools/set_config.php:375, :414), as setcfg106 runs --value; its output and exit status kept beside setcfg106's
  local o
  o="$(env PATH="$SB/bin:$PATH" LD_PRELOAD="$CLKPRE104" RH104_CLOCK_FILE="$CLKF104" bash "$SB/bin/docker" exec -u "$(owner104)" -e "DN_DATA_DIR=$PDD104_IN" ucrm \
        php "$IN_CONTAINER/tools/set_config.php" --key "$1" --clear 2>&1 </dev/null | sed '/^  AI SETTINGS$/,$d'; exit "${PIPESTATUS[0]}")"
  echo "$?" >> "$R106/setcfg.rc"; printf '%s\n' "$o" >> "$R106/setcfg.txt"; printf '%s' "$o"
}
rsl106() { ls -1 "$OUT106"/rollback-state-*.env 2>/dev/null; }   # rsl106 — the rollback records in the run folder, one a line
newrec106() { local r; r="$(rsl106 | grep -vxF -f <(printf '%s\n' "$1") | tail -1)"; echo "${r:-none}"; }   # newrec106 LIST — the record a run wrote beside LIST (rsl106 before it), or none
v1run106() {   # v1run106 LABEL [lock] — V1's automatic rollback (design-v2 A7): .redirect106 armed (with "lock", .redirect106lock beside
               # it: spent by the same request, it creates the clone's .git/index.lock, after the deploy's own copy and checkout
               # back), then 5.18.106 deployed over 7835906, DEPLOY typed, under the clock (--redeploy: its added files are on disk).
               # One check: V1's FAIL on the one redirect (deploy-5.18.106.sh:13911), ROLLING BACK (:13923), exit status 1 (:1688),
               # .redirect106 spent, .redirect106lock spent exactly when armed, the index lock there exactly when armed; armed, the
               # lock then removed (unlock106; cleanup106 also removes it at exit) and asserted gone. Output in V1OUT106 — call it
               # directly. The quarantine's --check after it is the caller's, once the case's own readings are taken
  local lab="$1" lk="${2:-}" o
  arm106 $lk
  fresh106; o="$(run106c --answer DEPLOY --redeploy)"; V1OUT106="$o"; V1RC106="$(rc106)"
  check "$(failline "$o" V1 'the sign-in page on the public address → 200 after 1 redirect(s)')$(has "$o" '  ROLLING BACK — the public address must never redirect (every switch of the sales follow-up kept; followup_auto_send set off first, AS-RB1)')|$V1RC106|$(spent99 .redirect106)|$( [ -n "$lk" ] && spent99 .redirect106lock || echo -)|$(lock106)" \
        "yesyes|1|spent|$( [ -n "$lk" ] && echo spent || echo -)|$( [ -n "$lk" ] && echo present || echo absent)" \
    "$lab a deploy whose public sign-in page redirects once (the stand-in's $( [ -n "$lk" ] && echo '.redirect106 with .redirect106lock' || echo '.redirect106'), planted, spent once): V1 FAILs on the one redirect (S:13911) and the deploy rolls itself back, AS-RB1 first (S:13923); exit status 1$( [ -n "$lk" ] && echo "; the same request made the clone's index lock (A7), so the put-back's own checkout refuses")"
  if [ -n "$lk" ]; then check "$(unlock106)" "absent" "$lab' the clone's index lock removed (unlock106; cleanup106 removes it at exit too) — gone"; fi
  return 0
}
AS0OFF106="  note  AS-RB0 followup_auto_send already reads off: the rollback writes nothing (AS-RB1 reads it again, and writes only a key that reads on), whatever §L.7's five conditions say"   # deploy-5.18.106.sh:8925
AS0OK106="  note  AS-RB0 followup_auto_send reads on and §L.7's five conditions hold: after ROLLBACK is typed, before the put-back, the rollback sets it off — '0' in <data>/kyc_config.json through the plugin's own writer, the five conditions recomputed in the same process just before; every other key of the file and of the store row kept (AS-RB1)"   # :8927
F500_106="  note  AS-RB0 no open enquiry follow-up holds two or more approved drafts (F5's count)"   # :8885
TRB106="  Type ROLLBACK to put $BV106 ($B106) back over live $P106 — "   # :13881, the prompt; what AS-RB0 says it will write follows
OFFER106="  followup_auto_send reads off: the latest rollback set it off (AS_RB1=written in its record; its copy at "   # :8839, as_back_on's offer
EVS106=0   # the stand-in Evolution's requests made by set_evolution's own status in this part (fetchInstances), for 106-10
EVF106="${EVO_URL%/*}/rh106-file-only"   # the file-only key's value: a base URL on the stand-in Evolution itself, never another host

# ── 106-6pre: what 106-5z hands over, asserted again before anything here moves it ──
dpre106 "106-6pre0" "$P106" on - "on:$LIM106" "written:offer-if-off"
J1A6_106="$(cut -d' ' -f1 <<<"$J1FU106")"; J1B6_106="$(cut -d' ' -f2 <<<"$J1FU106")"
dwal106
check "$(apd106)|$(kv106 "$(q106 u2u3 "$DATA/plugin.sqlite3" "$PD" "$(fnow104)")" f5_multi)|$(rd106 close "$J1A6_106" "$J1B6_106" "$J3FU106")|$(grep -c . "$SB/evo/rh106-refuse.list" 2>/dev/null)|$( [ -e "$SB/.ujfail106" ] && echo armed || echo none)|$(lock106)|$(ls -A "$SB/web" | grep -c '^\.redirect106')|$(sget106 "$RBX106_REC" AS_RB1)|$(case "$EVF106" in http://127.0.0.1:*/rh106-file-only) [ "$EVF106" != "$EVO_URL" ] && echo loopback ;; *) echo other ;; esac)" \
      "n=4 fu=$J1A6_106,$J1B6_106,$J3FU106,$J3FU106|1/2|$J1A6_106:open,$J1B6_106:open,$J3FU106:open|3|none|absent|0|written|loopback" \
  "106-6pre1 the state 106-5 hands over (106-5z): the approved drafts in the sender's order are J1's two fresh refused ones, then J3's pair — nothing sent since the jam (F5's count 1/2); J1's two and J3 open; the stand-in's refusal list still J1's three; no uj trigger, no index lock, no V1 trigger; D6's typed rollback the latest record (AS_RB1=written); the file-only key's URL this part plants is the stand-in Evolution's own host"
# The weakened copies of this part, each anchored on the script's own text (106-infra's anc106/weak106; ancc106 the control of the
# controls): AC3 — AS_PHP's condition 3 taken out (deploy-5.18.106.sh:8608, the line read from the script), at 6b; RBN — the RB
# note's AS-RB1 case made "written" whatever AS-RB1 recorded (:15803), at 6e; BOV — as_back_on's V1 guard removed (:8829, the line
# read from the script), at 7e and 7f.
AC3L106="$(grep -m1 -F "if (!array_key_exists(\$k, \$row) || \$row[\$k] !== \$v) \$r['differ'][] = \$asName(\$k);" "$DEPLOY106")"
anc106 ac3 "$AC3L106" "            // rh106 AC3: condition 3 removed — the file's keys never compared with the store row's"
anc106 rbn '$( case "${AS_RB1:-}" in written) echo "nothing else moved but followup_auto_send:' '$( case "written" in written) echo "nothing else moved but followup_auto_send:'
BOVL106="$(grep -m1 -F "# this deploy's V1 wrote it, may have, or read it on (review 2)" "$DEPLOY106")"
anc106 bov "$BOVL106" "  :   # rh106 BOV: as_back_on's V1 guard removed"
check "$(ancc106)|$(grep -c . <<<"$AC3L106")$(grep -c . <<<"$BOVL106")" "0|$(ls "$AN106"/*.a | wc -l | tr -d ' ')|-|11" \
  "106-6anc the anchors of this part's weakened copies (AC3, RBN, BOV), with every earlier one: each the script's own text, found in it exactly once, each replacement different; AC3's and BOV's lines read from the script"

echo; echo "== 106-6a. a key the store row holds and kyc_config.json lacks (set_evolution --clear-key's state): J3's pair in F5's capitals; AS-RB1 writes all the same =="
dpre106 "106-6a0" "$P106" on - "on:$LIM106" "written:offer-if-off"
# The writer: tools/set_evolution.php --clear-key, the INSTALLED tool (bf29cbd tools/set_evolution.php:144-169) —
# PluginConfig::clearEvolutionKey (lib/PluginConfig.php:257-266) takes evo_api_key out of <data>/kyc_config.json alone,
# through writeKycFile, and copies nothing into the store row: saveOverrides' mirrorToStore (:308-358) is the one writer that
# copies the file into the row, and the tools that set a key in the file alone (saveEvolutionCredentials, saveAiSettings) leave
# the row lacking one (6b's case), never holding one. So the key the row holds stays there alone — root docs/75:727's
# "production's normal state". Chosen over the other production writer of that state, a settings screen's own
# $store->save('kyc_config.json', …) (includes/post/post_admin.php:749): that needs a signed-in staff POST to the INSTALLED
# public.php, which no server serves in 106 (A2: master's piggyback rides on it). Its side effect: ConfigVault::store with
# an empty value forgets the vault's copy too (tools/set_evolution.php:148-160). The undo, after the rollback (so AS-RB1's
# write stays): the key put back through the same tool's own writer (--key -, the value from the copy kept first on its stdin,
# never an argument and never printed; --force-short, the chain's key being shorter than the tool's 16), and the vault byte for
# byte from its copy — both asserted. Its status at the end asks the stand-in Evolution for its instances (none come back:
# exit status 1, set_evolution.php:209 or :219), counted in EVS106.
K6A0_106="$(q106 kycplaces "$PD" "$DATA" evo_api_key)"; CF6A0_106="$(q106 cfgsha "$PD" "$DATA")"
KK6A106="$(keep106 p5-k6a "$DATA/kyc_config.json")"; KV6A106="$(keep106 p5-v6a "$VAULT")"
E6A0_106="$(evo_calls)"; O6A_106="$(setevo106 --clear-key)"; RC6A_106="$(sevrc106)"; E6A1_106=$(( $(evo_calls) - E6A0_106 )); EVS106=$(( EVS106 + E6A1_106 ))
K6A1_106="$(q106 kycplaces "$PD" "$DATA" evo_api_key,followup_auto_send)"
# tools/set_evolution.php:165 (removed, the vault's copy with it), :156 (removed, the vault's copy could not be forgotten)
check "$(kv106 "$K6A0_106" keys)|$(kv106 "$K6A0_106" pre13)|$( [ "$KK6A106" != absent ] && echo kept)|$(( $(grep -cxF "  Key override removed, and the vault's copy with it." <<<"$O6A_106") + $(grep -cxF "  The override was removed but the vault still holds a copy:" <<<"$O6A_106") ))|$RC6A_106|$(kv106 "$K6A1_106" keys)|$(kv106 "$K6A1_106" pre13)|$(kv106 "$K6A1_106" asplaces)|$( [ "$(kv106 "$(q106 cfgsha "$PD" "$DATA")" s)" = "$(kv106 "$CF6A0_106" s)" ] && echo row-same)|$( [ "$E6A1_106" -le 1 ] && echo le1)" \
      "evo_api_key@ks,ss|ok|kept|1|1|evo_api_key@ss;followup_auto_send@ks,ss|ok|k:on,s:on,p:-,d:-|row-same|le1" \
  "106-6a1 the state planted through the installed set_evolution.php --clear-key as the book's owner (the file and the vault kept first, $KK6A106/$KV6A106): before, evo_api_key in the file and its store row, agreeing; after, in the store row alone — the store row's bytes as they were (no mirror), §L.7's condition 3 still holding (it compares the file's keys with the row, never the row's with the file), followup_auto_send on in both; exit status 1 (the stand-in Evolution lists no instance)"
F56A106="$(kv106 "$(q106 u2u3 "$DATA/plugin.sqlite3" "$PD" "$(fnow104)")" f5_multi)"
rbx106 "106-6a2"
O="$RBX106_OUT"; K6A2_106="$(q106 kycplaces "$PD" "$DATA" evo_api_key,followup_auto_send)"
# deploy-5.18.106.sh:8886 (F5's capitals), :8927 (on, the five conditions holding), :13881 (the prompt), :9032 (AS-RB1),
# :9048 (the row reads '0'), :9137 (AS-RB2), :7892 (LM-RB1), :15803 (RB's note, its "written" case)
check "$F56A106|$(grep -cxF "  note  AS-RB0 ${F56A106%/*} OPEN ENQUIRY FOLLOW-UP(S) HOLD TWO OR MORE APPROVED DRAFTS (${F56A106#*/} IN ALL): $BV106'S SENDER WILL SEND EACH OF THOSE FOLLOW-UPS' APPROVED DRAFTS IN ONE PASS — $V106 SENDS ONE AND EXPIRES THE REST (F5). WITH followup_auto_send OFF NO NEW DRAFT IS APPROVED, BUT THESE ARE APPROVED ALREADY" <<<"$O")|$(grep -cxF "$AS0OK106" <<<"$O")|$(has "$O" "${TRB106}followup_auto_send set off first (AS-RB1), every other switch of the sales follow-up kept as it is — anything else to stop: ")|$(okline "$O" AS-RB1 'followup_auto_send set off before the put-back (typed)' 'every other key of the file and of the settings row as it was')$(noteline "$O" AS-RB1 "the settings row reads '0' too")$(okline "$O" AS-RB2 "followup_auto_send reads off through $BV106's code ($B106, installed)" '; AS-RB1 set it before the put-back')|$(okline "$O" LM-RB1 "5.18.105's masked lines still in place under $BV106, counted as blocks")|$(noteline "$O" RB "the code is $BV106's again where $V106 changed it — the four follow-up jobs and lib/FollowUpService.php — and nothing else moved but followup_auto_send: the rollback ran no switch tool and no off-hook, applied or removed no migration, and wrote one key before the put-back, followup_auto_send '0' (AS-RB1: set off first, AS-RB1; root docs/75 §L.7)")|$(sget106 "$RBX106_REC" AS_RB1)|$(kv106 "$K6A2_106" keys)|$(kv106 "$K6A2_106" asplaces)" \
      "1/2|1|1|yes|yesyesyes|yes|yes|written|evo_api_key@ss;followup_auto_send@ks,ss|k:off,s:off,p:-,d:-" \
  "106-6a2'' the typed rollback over it: J3's pair named in AS-RB0's capitals with F5's count (1 follow-up, 2 drafts, S:8886); AS-RB0 reads on and the five conditions holding (S:8927), and the prompt says so; AS-RB1 wrote followup_auto_send '0' (S:9032) — the key the row alone holds did not stop it — the row reading '0' too (S:9048); AS-RB2 off through $BV106's code (S:9137); LM-RB1 masked (S:7892); RB: nothing else moved but followup_auto_send (S:15803); its record AS_RB1=written; evo_api_key still in the row alone"
# The undo (A9): the key back in the file through the tool's own writer, the vault byte for byte — 7835906 serving, its tool
E6A2_106="$(evo_calls)"; O6AU_106="$(setevo106 --key-from "$R106/kept/p5-k6a/file" --key - --force-short)"; RC6AU_106="$(sevrc106)"; EVS106=$(( EVS106 + $(evo_calls) - E6A2_106 ))
B6AV_106="$(back106 p5-v6a)"; K6AU_106="$(q106 kycplaces "$PD" "$DATA" evo_api_key,followup_auto_send)"
# tools/set_evolution.php:139 (the key stored, its length only), :188 (SAVED)
check "$(grep -cxF '  SAVED' <<<"$O6AU_106")$(grep -c '^    API key  stored, [0-9][0-9]* characters (not shown)$' <<<"$O6AU_106")|$RC6AU_106|$B6AV_106|$(kv106 "$K6AU_106" keys)|$(kv106 "$K6AU_106" pre13)|$(kyceq106 "$R106/kept/p5-k6a/file")|$(kv106 "$K6AU_106" asplaces)|$(live)" \
      "11|1|same|evo_api_key@ks,ss;followup_auto_send@ks,ss|ok|same|k:off,s:off,p:-,d:-|$B106" \
  "106-6a3 the state undone (A9): evo_api_key back in <data>/kyc_config.json through set_evolution.php --key - (its value from the copy kept first, on stdin, never shown), every key of the file as it was before the plant but followup_auto_send (AS-RB1's '0', kept), the file and the row agreeing again; the vault put back byte for byte"
dredeploy106 "106-6a4"
dpre106 "106-6a5" "$P106" off - "on:$LIM106" "written:offer-if-off"

echo; echo "== 106-6s. between the cases, once: 5.18.106's sender in the window — J3's first draft sent, its second expired (F5); J1's refused drafts closed send_failed (F1), never sent again =="
AP6S_106="$(apd106)"; RF6S_106="$(refused106)"; E6S_106="$(evo_calls)"
sender106 "106-6s0 5.18.106's sender, once, in the next sending window" --window
FS6S_106="$(fsl106 "$SS106_OUT")"
# bf29cbd cron/followup_send.php:131 (F5: the follow-up's oldest draft only), :303-309 (F1 closes, nothing sent), :385-389 (the
# send, then expireOtherApproved), :398-406 (its two lines); lib/FollowUpService.php:331-344 (expireOtherApproved)
check "$AP6S_106|$FS6S_106|$(grep -cE '\[followup_send\] enquiry_second_draft=1 enquiry_tries_exhausted=2$' "$SS106_OUT")|$(( $(evo_calls) - E6S_106 ))|$(( $(refused106) - RF6S_106 ))|$(rd106 close "$J1A6_106" "$J1B6_106" "$J3FU106")|$(rd106 drafts "$J3FU106")|$(rd106 sends "$J3FU106")|$(apd106)|$(kv106 "$(q106 u2u3 "$DATA/plugin.sqlite3" "$PD" "$(fnow104)")" f5_multi)" \
      "n=4 fu=$J1A6_106,$J1B6_106,$J3FU106,$J3FU106|1:3:0|1|1|0|$J1A6_106:send_failed,$J1B6_106:send_failed,$J3FU106:open|expired,sent|1|n=0 fu=-|0/0" \
  "106-6s1 the pass over the four approved drafts: sent=1 held=3 failed=0, the second draft held (enquiry_second_draft=1) and J1's two closed by F1 (enquiry_tries_exhausted=2) — J1's two closed send_failed with no send (the stand-in refused nothing more though their numbers are still listed: F1, not a refusal, closed them); one sendText for J3, its follow-up open, one draft sent and the other expired, one send recorded; no approved draft left, F5's count 0/0"
check "$(refuse106)|$( [ -e "$SB/evo/rh106-refuse.list" ] && echo present || echo gone)" "0|gone" \
  "106-6s2 the stand-in's refusal list (J1's three numbers, a stand-in setting the jam needed) removed now its last use is past (A9) — gone"
master106 "106-6s3"
aft106 "106-6s4" PASSED $L1ON106
# deploy-5.18.106.sh:8036 (UJ-CA since the copy)
check "$(noteline "$AFT106" UJ-CA "F2 closed 0 follow-up(s) whose customer was quiet past the limit (actor sender), F5 expired 0 draft(s) whose attempt was taken, F1 closed 2 refused draft(s), the after-send sibling expiries 1/1 (events/drafts), 1 enquiry follow-up(s) sent")" "yes" \
  "106-6s4' UJ-CA reads the pass since this copy (S:8036): F1 closed 2 refused drafts, the after-send sibling expiry 1/1 (J3's second), 1 enquiry follow-up sent"
doffer106 "106-6s5"

echo; echo "== 106-6b. a key kyc_config.json holds and the store row lacks (set_evolution --url's state): AC3 caught; no write — the capitals notice and the owner's command before ROLLBACK, again after the verdict; run as printed =="
dpre106 "106-6b0" "$P106" on - "on:$LIM106" "written:offer-if-off"
# The company's quotation phone and reply address, planted through set_config (both places) so the owner's command's own log
# can be shown to hold neither (it is cut where the settings listing begins, which prints both)
PH6B106="$(ph106 20)"; EM6B106='RH106SECRET-quote@example.test'
K6B0_106="$(q106 kycplaces "$PD" "$DATA" quote_company_phone,quote_company_email)"
O6B1_106="$(setcfg106 quote_company_phone "$PH6B106")"; R6B1_106="$(setrc106)"; O6B2_106="$(setcfg106 quote_company_email "$EM6B106")"; R6B2_106="$(setrc106)"
K6B1_106="$(q106 kycplaces "$PD" "$DATA" quote_company_phone,quote_company_email)"
# tools/set_config.php:185, :187 (the two keys), :670 ("  <key> = <value>")
check "$(kv106 "$K6B0_106" keys)|$R6B1_106$R6B2_106|$(grep -cxF "  quote_company_phone = $PH6B106" <<<"$O6B1_106")$(grep -cxF "  quote_company_email = $EM6B106" <<<"$O6B2_106")|$(kv106 "$K6B1_106" keys)|$(kv106 "$K6B1_106" pre13)" \
      "quote_company_phone@-;quote_company_email@-|00|11|quote_company_phone@ks,ss;quote_company_email@ks,ss|ok" \
  "106-6b1 quote_company_phone (a number of 106's range) and quote_company_email (a marked address), in neither place before, set through the installed set_config.php as the book's owner: in the file and its store row, agreeing"
# The writer: tools/set_evolution.php --url (bf29cbd :78-99) — PluginConfig::saveEvolutionCredentials (lib/PluginConfig.php:215-237)
# writes evo_api_url into <data>/kyc_config.json alone (writeKycFile, no mirror): the file then holds a value the row lacks.
# Its URL the stand-in's own host on a path of this rehearsal's (http, so --allow-insecure, :88). The file and the vault kept
# first; undone byte for byte from them where nothing writes either between the plant and the undo.
KK6B106="$(keep106 p5-k6b "$DATA/kyc_config.json")"; KV6B106="$(keep106 p5-v6b "$VAULT")"; CF6B0_106="$(q106 cfgsha "$PD" "$DATA")"
E6B3_106="$(evo_calls)"; O6B3_106="$(setevo106 --url "$EVF106" --allow-insecure)"; R6B3_106="$(sevrc106)"; EVS106=$(( EVS106 + $(evo_calls) - E6B3_106 ))
K6B3_106="$(q106 kycplaces "$PD" "$DATA" evo_api_url,followup_auto_send)"
# tools/set_evolution.php:98 ("API URL  <url>"), :188 (SAVED), :219 (no instance came back)
check "$(grep -cxF '  SAVED' <<<"$O6B3_106")$(grep -cxF "    API URL  $EVF106" <<<"$O6B3_106")|$R6B3_106|$(kv106 "$K6B3_106" keys)|$(kv106 "$K6B3_106" pre13):$(kv106 "$K6B3_106" differ)|$( [ "$(kv106 "$(q106 cfgsha "$PD" "$DATA")" s)" = "$(kv106 "$CF6B0_106" s)" ] && echo row-same)|$(kv106 "$K6B3_106" asplaces)" \
      "11|1|evo_api_url@ks,ss;followup_auto_send@ks,ss|differ:evo_api_url|row-same|k:on,s:on,p:-,d:-" \
  "106-6b2 the state planted through the installed set_evolution.php --url as the book's owner (the file and the vault kept first, $KK6B106/$KV6B106): evo_api_url in the file now differs from the store row's, which is as it was — §L.7's condition 3 fails on evo_api_url; followup_auto_send on in both"
dpre106 "106-6b3" "$P106" on - "on:$LIM106" "written:offer-if-off"
# AC3 — the weakened copy without condition 3, ROLLBACK typed, on this state: the rollback it runs is real (it puts 7835906
# back), and its AS-RB1 writes where the script's must not — mirroring the whole file into the store row, so the row's
# evo_api_url becomes the file's: its own read-back refuses that (row-others). Caught by 106-6b9 (the capitals notice, no write)
RR6B4_106="$(rsl106)"; MAC3_106="$(weak106 ac3 ac3)"
fresh106; OAC3_106="$(R106_MUTANT_OUT=1 run106c --answer ROLLBACK --script "$MAC3_106" --rollback)"; RCAC3_106="$(rc106)"; rm -f "$MAC3_106"
RAC3_106="$(newrec106 "$RR6B4_106")"; KAC3_106="$(q106 kycplaces "$PD" "$DATA" followup_auto_send,evo_api_url)"
# deploy-5.18.106.sh:8927 (the five conditions read holding), :9034 (AS-RB1's read-back FAIL), :1683
check "$(has "$OAC3_106" 'THE ROLLBACK CANNOT SET followup_auto_send OFF ITSELF (condition 3')|$(grep -cxF "$AS0OK106" <<<"$OAC3_106")|$(grep -cxF "  FAIL  AS-RB1 FOLLOWUP_AUTO_SEND WAS WRITTEN, BUT ITS READ-BACK DID NOT HOLD (row-others) — the rollback goes on (it is never blocked by this); AS-RB2 reads it again under $BV106" <<<"$OAC3_106")|$(fails "$OAC3_106")|$RCAC3_106|$(sget106 "$RAC3_106" AS_RB1)|$(kv106 "$KAC3_106" asplaces)|$(kv106 "$KAC3_106" pre13)|$(live)|$( [ -e "$MAC3_106" ] && echo left || echo removed)" \
      "no|1|1|1|1|written-check:row-others|k:off,s:off,p:-,d:-|ok|$B106|removed" \
  "106-6b4 AC3, the copy without §L.7's condition 3 (S:8608), ROLLBACK typed over a key the file holds and the row lacks: no capitals notice, AS-RB0 reads the five conditions holding (S:8927), and its AS-RB1 writes followup_auto_send '0' — the writer mirroring the whole file into the store row, so the row's evo_api_url is now the file's (they agree: the row was changed) — its own read-back FAILs, row-others (S:9034), record written-check:row-others: what condition 3 is there to prevent (caught by 106-6b9); $B106 serving; the copy removed"
qcheck106 "106-6b4'"
# The write undone (A9): kyc_config.json put back from the copy kept before the URL's plant (AC3's '0' and the URL both taken
# back), then followup_auto_send 1 through set_config as the book's owner — the file as it is, mirrored whole into the store row
# (lib/PluginConfig.php:356), which takes back what AC3's write copied there (the planted URL, '0'); the vault byte for byte
B6B5_106="$(back106 p5-k6b)"; O6B5_106="$(setcfg106 followup_auto_send 1)"; R6B5_106="$(setrc106)"; B6B5V_106="$(back106 p5-v6b)"
K6B5_106="$(q106 kycplaces "$PD" "$DATA" followup_auto_send,evo_api_url,quote_company_phone,quote_company_email)"
check "$B6B5_106|$(grep -cxF '  followup_auto_send = 1' <<<"$O6B5_106")|$R6B5_106|$B6B5V_106|$(kv106 "$K6B5_106" pre13)|$(kv106 "$K6B5_106" asplaces)|$(kyceq106 "$R106/kept/p5-k6b/file")" \
      "same|1|0|same|ok|k:on,s:on,p:-,d:-|same" \
  "106-6b5 AC3's write undone (A9): the file put back from its copy, followup_auto_send set to 1 through set_config as the book's owner (the file mirrored into the row: the row's URL the file's again), the vault byte for byte — every key of the file as before the plant, followup_auto_send on in both"
dredeploy106 "106-6b6"
# the file-only key planted again, on this deploy (the same writer; the file and the vault kept again first)
KQ6B_106="$(kyceq106 "$R106/kept/p5-k6b/file")"
KK6B2_106="$(keep106 p5-k6b2 "$DATA/kyc_config.json")"; KV6B2_106="$(keep106 p5-v6b2 "$VAULT")"
E6B7_106="$(evo_calls)"; O6B7_106="$(setevo106 --url "$EVF106" --allow-insecure)"; R6B7_106="$(sevrc106)"; EVS106=$(( EVS106 + $(evo_calls) - E6B7_106 ))
K6B7_106="$(q106 kycplaces "$PD" "$DATA" evo_api_url,followup_auto_send)"
check "$KQ6B_106|$(grep -cxF "    API URL  $EVF106" <<<"$O6B7_106")|$R6B7_106|$(kv106 "$K6B7_106" pre13):$(kv106 "$K6B7_106" differ)|$(kv106 "$K6B7_106" asplaces)" \
      "same|1|1|differ:evo_api_url|k:on,s:on,p:-,d:-" \
  "106-6b7 the file-only key planted again after the redeploy (the file kept first, $KK6B2_106 — every key as the copy 106-6b2 kept, so nothing else moved it): condition 3 fails on evo_api_url again"
dpre106 "106-6b8" "$P106" on - "on:$LIM106" "written-check:row-others:nothing:not-written"
PL6B_106="$(pls106 "$(q106 kycplaces "$PD" "$DATA" followup_auto_send)")"; KD6B_106="$(digest105)"; RR6B9_106="$(rsl106)"
fresh106; O6B_106="$(run106c --answer ROLLBACK --rollback)"; RC6B_106="$(rc106)"
R6B_106="$(newrec106 "$RR6B9_106")"; C6B_106="$(printed "$O6B_106" "docker exec -u ")"
B6BH_106="$(half106 "$O6B_106" before "  $V106 (rollback): ")"; A6BH_106="$(half106 "$O6B_106" after "  $V106 (rollback): ")"
CA6B_106="condition 3: the file and the store row disagree on evo_api_url"
# deploy-5.18.106.sh:8882 (AS-RB0's reading), :8885 (F5 0/0 after the sender's pass), :8934 (the capitals notice, a command
# allowed), :8724 and :8735 (the owner's text: differ, the command), :13881 (the prompt, AS_RB0_SAY :8930), :9038 (AS-RB1's
# FAIL), :9144 (AS-RB2's note), :16089 (the verdict), :8795 (as_after_verdict), :15803 (RB, its last case)
check "$(order106 "$B6BH_106" "  note  AS-RB0 followup_auto_send before the rollback, through the installed code (PluginConfig::read, as FollowUpPolicy::mayAutoSend reads it): on — per place: " "$F500_106" "  note  THE ROLLBACK CANNOT SET followup_auto_send OFF ITSELF (" "  It reads on (per place: " "      docker exec -u " "  It prints the line \"  followup_auto_send = 0\" once it has saved; " "$TRB106" "  FAIL  AS-RB1 " "  note  AS-RB2 ")|$(grep -cxF "  note  AS-RB0 followup_auto_send before the rollback, through the installed code (PluginConfig::read, as FollowUpPolicy::mayAutoSend reads it): on — per place: $PL6B_106; - absent, ? unread; §L.7's five conditions do not hold: $CA6B_106" <<<"$O6B_106")|$(grep -cxF "  note  THE ROLLBACK CANNOT SET followup_auto_send OFF ITSELF ($CA6B_106): $BV106 WILL THEN APPROVE AND SEND AUTOMATICALLY. TO AVOID THAT, ANSWER ANYTHING BUT ROLLBACK BELOW — NOTHING HAS BEEN CHANGED — RUN YOUR OWN COMMAND BELOW, ALONE, THEN RUN THE ROLLBACK AGAIN:" <<<"$O6B_106")|$(grep -cxF "  It reads on (per place: $PL6B_106; - absent, ? unread). The command below also copies <data>/kyc_config.json, whole, into the settings row, so evo_api_url take the file's values there (missing or different in the row now), and it refreshes the vault (a later run's Z1 reads that) — your decision. Your own command, alone, with its own log:" <<<"$O6B_106")|$(has "$O6B_106" "${TRB106}followup_auto_send NOT set off — the rollback cannot write it (above), every other switch of the sales follow-up kept as it is — anything else to stop: ")|$(grep -cxF "  FAIL  AS-RB1 FOLLOWUP_AUTO_SEND READS ON AND WAS NOT SET OFF: §L.7'S FIVE CONDITIONS DO NOT HOLD ($CA6B_106) — $BV106 WILL APPROVE AND SEND AUTOMATICALLY UNTIL IT IS OFF; the rollback goes on (it is never blocked by this) — your own command, where the cause allows one, is printed after the verdict" <<<"$O6B_106")|$(grep -cxF "  note  AS-RB2 followup_auto_send reads on under $BV106 — AS-RB1 did not write it (not written: $CA6B_106; its FAIL above): your own command, where its cause allows one, is printed after the verdict" <<<"$O6B_106")" \
      "ordered|1|1|2|yes|1|1" \
  "106-6b9 the rollback over a key the file holds and the row lacks, ROLLBACK typed once — before the question (S:8882-8936): AS-RB0 reads it on and condition 3 failing on evo_api_url, F5's count 0/0 now (the sender's pass sent one of J3's pair and expired the other), then the capitals notice that the rollback cannot set it off, with the owner's own text and command (S:8724, S:8735), then the prompt saying it will not be set off (S:13881); then AS-RB1's FAIL (S:9038) and AS-RB2's note (S:9144)"
check "$(order106 "$A6BH_106" "  $V106 (rollback): 1 FAILED — " "  AS-RB1 DID NOT SET followup_auto_send OFF (not written: $CA6B_106) — UNDER $BV106 IT APPROVES AND SENDS AUTOMATICALLY WHILE IT READS ON:" "  It reads on (per place: " "      docker exec -u " "  It prints the line \"  followup_auto_send = 0\" once it has saved; ")|$(grep -cxF "      $C6B_106" <<<"$O6B_106")|$(ascmd106 0 "$C6B_106")|$(fails "$O6B_106")|$RC6B_106|$(live)|$(installed106 "$B106F")|$(sget106 "$R6B_106" AS_RB1)|$(grep -c '^RB_COPY_AT=[0-9]' "$R6B_106" 2>/dev/null)|$( [ "$(digest105)" = "$KD6B_106" ] && echo kept)|$(kv106 "$(q106 kycplaces "$PD" "$DATA" followup_auto_send)" asplaces)|$(noteline "$O6B_106" RB "and wrote no key (AS-RB1: not written: $CA6B_106; root docs/75 §L.7)")" \
      "ordered|2|form|1|1|$B106|0|not-written:differ|1|kept|k:on,s:on,p:-,d:-|yes" \
  "106-6b9' after the verdict — 1 FAILED, AS-RB1's (S:16089) — as_after_verdict's headline and the same command again, alone (S:8795; RH106-15); that command exactly as as_owner_cmd builds it (S:12333-12337); exit status 1, $B106 serving, every file $V106 changes $BV106's, the record AS_RB1=not-written:differ with the rollback's copy time, every ladder key kept, followup_auto_send still on in both places; RB says no key was written (S:15803)"
qcheck106 "106-6b9''"
# With a wrong container name in place of the printed one, docker's own error reaches the command's log (its 2>&1 before sed)
W6B_106="${C6B_106/ ucrm php / rh106-nosuch php }"
fresh106; O6BW_106="$(run_line106c "" "$W6B_106")"; RC6BW_106="$(rc106)"; LG6BW_106="$(ls -1t "$OUT106"/autosend-0-*.log 2>/dev/null | head -1)"
# the stand-in docker's own answer (scripts/harness/quarantine-stray-store/rehearse.sh:468)
check "$(grep -o ' ucrm php ' <<<"$C6B_106" | grep -c .)|$(grep -cxF 'Error: No such container: rh106-nosuch' "${LG6BW_106:-/nonexistent}" 2>/dev/null)|$(grep -c '^  followup_auto_send = ' "${LG6BW_106:-/nonexistent}" 2>/dev/null)|$RC6BW_106|$(kv106 "$(q106 kycplaces "$PD" "$DATA" followup_auto_send)" asplaces)" \
      "1|1|0|0|k:on,s:on,p:-,d:-" \
  "106-6b10 the printed command with a wrong container name (the one container name in it replaced): docker's error reaches the command's own log through its 2>&1 ($(basename "${LG6BW_106:-none}")), no saved line, the pipeline's exit status tee's; followup_auto_send still on"
# The file-only key undone (A9) before the owner's command: nothing wrote the file or the vault since the plant (AS-RB1 wrote nothing)
check "$(back106 p5-k6b2)|$(back106 p5-v6b2)|$(kv106 "$(q106 kycplaces "$PD" "$DATA" evo_api_url)" pre13)" "same|same|ok" \
  "106-6b11 the file-only key undone (A9): kyc_config.json and the vault put back byte for byte from their copies — condition 3 holds again"
# The control on the control: set_config's own output, UNCUT, on a throwaway copy of the data folder (kyc_config.json alone, no
# book, so nothing is mirrored; a vault of its own) shows the planted phone and address — the cut is what keeps them out of the
# owner's log. Held in memory only, never written; the throwaway folder removed
TW6B106="$H106/v/tw6b"; rm -rf "$TW6B106"; mkdir -p "$TW6B106"; cp -p "$DATA/kyc_config.json" "$TW6B106/kyc_config.json"; chown -R 1000:1000 "$TW6B106"; chmod 700 "$TW6B106"
OTW6B106="$(env PATH="$SB/bin:$PATH" LD_PRELOAD="$CLKPRE104" RH104_CLOCK_FILE="$CLKF104" bash "$SB/bin/docker" exec -u "$(owner104)" -e "DN_DATA_DIR=$TW6B106" -e "DN_VAULT_FILE=$TW6B106/vault.json" ucrm \
             php "$IN_CONTAINER/tools/set_config.php" --key followup_auto_send --value 0 2>&1 </dev/null)"
rm -rf "$TW6B106"
# tools/set_config.php:670 (the saved line), :267 (the listing's heading), :333 (a text value shown in quotes)
check "$(grep -cxF '  followup_auto_send = 0' <<<"$OTW6B106")|$(grep -cxF '  AI SETTINGS' <<<"$OTW6B106")|$( [ "$(grep -cF -- "\"$PH6B106\"" <<<"$OTW6B106")" -ge 1 ] && echo phone)|$( [ "$(grep -cF -- "\"$EM6B106\"" <<<"$OTW6B106")" -ge 1 ] && echo address)|$( [ -e "$TW6B106" ] && echo left || echo gone)|$(cmp -s "$DATA/kyc_config.json" "$R106/kept/p5-k6b2/file" && echo live-same)" \
      "1|1|phone|address|gone|live-same" \
  "106-6b12 the control on the control: set_config's output uncut, on a throwaway copy of kyc_config.json, prints the settings listing with the planted phone and address in it — so the owner's cut is what keeps them out of its log; the throwaway removed, the live file untouched"
fresh106; O6BR_106="$(run_line106c "" "$C6B_106")"; RC6BR_106="$(rc106)"; LG6BR_106="$(ls -1t "$OUT106"/autosend-0-*.log 2>/dev/null | head -1)"
K6BR_106="$(q106 kycplaces "$PD" "$DATA" followup_auto_send)"
check "$(grep -cxF '  followup_auto_send = 0' <<<"$O6BR_106")|$(grep -c 'AI SETTINGS' <<<"$O6BR_106")|$RC6BR_106|$(grep -cxF '  followup_auto_send = 0' "${LG6BR_106:-/nonexistent}" 2>/dev/null)|$(grep -c 'AI SETTINGS' "${LG6BR_106:-/nonexistent}" 2>/dev/null)|$(grep -cF -e "${PH6B106: -9}" -e "$EM6B106" -e 'RH106SECRET-' "${LG6BR_106:-/nonexistent}" 2>/dev/null)|$(grep -cF -e "${PH6B106: -9}" -e "$EM6B106" <<<"$O6BR_106")|$(stat -c %a "${LG6BR_106:-/nonexistent}" 2>/dev/null)|$(kv106 "$K6BR_106" asplaces)" \
      "1|0|0|1|0|0|0|600|k:off,s:off,p:-,d:-" \
  "106-6b13 the owner's command run exactly as printed (run_line106c, under the clock): \"  followup_auto_send = 0\" and nothing from \"  AI SETTINGS\" on, exit status 0; its own log ($(basename "${LG6BR_106:-none}"), mode 0600) the same, holding neither the planted phone nor the address nor any marked text; followup_auto_send off in the file and its store row"
O6BC1_106="$(setclr106 quote_company_phone)"; R6BC1_106="$(setrc106)"; O6BC2_106="$(setclr106 quote_company_email)"; R6BC2_106="$(setrc106)"
# tools/set_config.php:668 ("<key> cleared — back to the default")
check "$R6BC1_106$R6BC2_106|$(grep -cxF '  quote_company_phone cleared — back to the default.' <<<"$O6BC1_106")$(grep -cxF '  quote_company_email cleared — back to the default.' <<<"$O6BC2_106")|$(kv106 "$(q106 kycplaces "$PD" "$DATA" quote_company_phone,quote_company_email)" keys)" \
      "00|11|quote_company_phone@-;quote_company_email@-" \
  "106-6b14 the company's phone and address cleared through set_config --clear as the book's owner (A9): in neither place again"

echo; echo "== 106-6e. the rollback run again from a redeploy (6b's last step): followup_auto_send already off — the plain note, AS_RB1=off; RBN caught on the same state =="
dpre106 "106-6e0" "$B106" off - "on:$LIM106" "not-written:differ:nothing:not-before"
dredeploy106 "106-6e1"
dpre106 "106-6e2" "$P106" off - "on:$LIM106" "not-written:differ:nothing:not-written"
rbx106 "106-6e3"
O="$RBX106_OUT"
# deploy-5.18.106.sh:8925 (already off), :8924's AS_RB0_SAY in the prompt (:13881), :9036 (AS-RB1), :9137 (AS-RB2), :15803 (RB)
check "$(grep -cxF "$AS0OFF106" <<<"$O")|$(has "$O" "${TRB106}followup_auto_send already off, nothing written, every other switch of the sales follow-up kept as it is — anything else to stop: ")|$(noteline "$O" AS-RB1 'followup_auto_send already reads off — nothing was written')|$(okline "$O" AS-RB2 "followup_auto_send reads off through $BV106's code ($B106, installed)")$(has "$O" '; AS-RB1 set it before the put-back')|$(has "$O" 'THE ROLLBACK CANNOT SET')|$(noteline "$O" RB "and wrote no key (AS-RB1: already off, AS-RB1 wrote nothing; root docs/75 §L.7)")|$(has "$O" 'and wrote one key before the put-back')|$(sget106 "$RBX106_REC" AS_RB1)" \
      "1|yes|yes|yesno|no|yes|no|off" \
  "106-6e3'' already off: AS-RB0's plain note, nothing will be written (S:8925), and the prompt says so; AS-RB1 wrote nothing (S:9036); AS-RB2 off, without \"AS-RB1 set it\" (S:9137); no capitals; RB: wrote no key (S:15803); its record AS_RB1=off"
dredeploy106 "106-6e4"
dpre106 "106-6e5" "$P106" off - "on:$LIM106" "off:nothing:not-written"
# RBN — the weakened copy whose RB note says "wrote one key" whatever AS-RB1 recorded, ROLLBACK typed on this already-off state: a
# real rollback (it puts 7835906 back), whose note claims a write its own record denies — caught by 106-6e3's "wrote no key"
RR6E6_106="$(rsl106)"; MRBN106="$(weak106 rbn rbn)"
fresh106; ORBN106="$(R106_MUTANT_OUT=1 run106c --answer ROLLBACK --script "$MRBN106" --rollback)"; RCRBN106="$(rc106)"; rm -f "$MRBN106"
RRBN106="$(newrec106 "$RR6E6_106")"
check "$(noteline "$ORBN106" AS-RB1 'followup_auto_send already reads off — nothing was written')|$(sget106 "$RRBN106" AS_RB1)|$(noteline "$ORBN106" RB "and wrote one key before the put-back, followup_auto_send '0' (AS-RB1: already off, AS-RB1 wrote nothing; root docs/75 §L.7)")|$RCRBN106|$(live)|$( [ -e "$MRBN106" ] && echo left || echo removed)" \
      "yes|off|yes|0|$B106|removed" \
  "106-6e6 RBN, the copy whose RB note is the written case's whatever AS-RB1 recorded (S:15803), ROLLBACK typed with the key already off: its AS-RB1 wrote nothing and its record says off, yet its note says it wrote one key — caught by 106-6e3''; $B106 serving; the copy removed"
qcheck106 "106-6e6'"
dredeploy106 "106-6e7"
O6E8_106="$(setcfg106 followup_auto_send 1)"
check "$(grep -cxF '  followup_auto_send = 1' <<<"$O6E8_106")|$(setrc106)|$(kv106 "$(q106 kycplaces "$PD" "$DATA" followup_auto_send)" asplaces)" "1|0|k:on,s:on,p:-,d:-" \
  "106-6e8 followup_auto_send on again through set_config as the book's owner — the owner's own command, where no --after-only offers it (the latest rollback wrote nothing)"
dpre106 "106-6e9" "$P106" on - "on:$LIM106" "off:nothing:not-written"

echo; echo "== 106-6c. '3' against 3 (a FIXTURE): condition 3 on a number the store row holds as a number — the rollback cannot write; answered no, nothing changed =="
dpre106 "106-6c0" "$P106" on - "on:$LIM106" "off:nothing:not-written"
# A FIXTURE: no production writer leaves a number typed differently in the file and the row (set_config writes strings to both);
# rh106-seed.php fx_type3 makes the row's followup_daily_cap the number the file holds as a string, through the store's own load()
# and save(), and fx_type3_undo makes it the string again
X6C_106="$(plant106 '[{"op":"fx_type3","key":"followup_daily_cap"}]')"; K6C_106="$(q106 kycplaces "$PD" "$DATA" followup_daily_cap)"
check "$(jf105 "$X6C_106" ops)|$(sd106 "$X6C_106" 0 fixture)|$(sd106 "$X6C_106" 0 row)|$(sd106 "$X6C_106" 0 file)|$(kv106 "$K6C_106" keys)|$(kv106 "$K6C_106" pre13):$(kv106 "$K6C_106" differ)" \
      "1|true|number|unchanged|followup_daily_cap@ks,si|differ:followup_daily_cap" \
  "106-6c1 the fixture: followup_daily_cap a string in kyc_config.json and a number in its store row (this rehearsal's reading, ===: §L.7's condition 3 fails on it)"
D6C_106="$(data94)"; CF6C_106="$(kv106 "$(q106 cfgsha "$PD" "$DATA")" all)"; RB6C_106="$(kv106 "$(q106 rblast "$OUT106" "$STATE106")" records)"
fresh106; O6C_106="$(run106c --answer no --rollback)"; RC6C_106="$(rc106)"
# deploy-5.18.106.sh:8934 (the notice), :8735 (the command), :13881 (the prompt), :13883 and :1689 (not confirmed, nothing changed)
check "$(grep -cxF "  note  THE ROLLBACK CANNOT SET followup_auto_send OFF ITSELF (condition 3: the file and the store row disagree on followup_daily_cap): $BV106 WILL THEN APPROVE AND SEND AUTOMATICALLY. TO AVOID THAT, ANSWER ANYTHING BUT ROLLBACK BELOW — NOTHING HAS BEEN CHANGED — RUN YOUR OWN COMMAND BELOW, ALONE, THEN RUN THE ROLLBACK AGAIN:" <<<"$O6C_106")|$(order106 "$O6C_106" "  note  THE ROLLBACK CANNOT SET followup_auto_send OFF ITSELF (" "      docker exec -u " "${TRB106}followup_auto_send NOT set off — the rollback cannot write it (above), every other switch" "  STOP: not confirmed" "  Nothing was changed. Send the log file.")|$(ascmd106 0 "$(printed "$O6C_106" "docker exec -u ")")|$(grep -cE '^  (ok    |FAIL  |note  )AS-RB1 ' <<<"$O6C_106")|$RC6C_106|$(live)|$(data94)|$(kv106 "$(q106 cfgsha "$PD" "$DATA")" all)|$(kv106 "$(q106 rblast "$OUT106" "$STATE106")" records)" \
      "1|ordered|form|0|1|$P106|$D6C_106|$CF6C_106|$RB6C_106" \
  "106-6c2 the rollback over '3' against 3, answered no: the capitals notice naming condition 3 on followup_daily_cap (S:8934), the owner's command alone, the prompt saying it will not be set off, then \"not confirmed\" and \"Nothing was changed\" — no AS-RB1 at all; $P106 serving, the book and the four settings places byte for byte as before, no rollback record with a copy time"
X6CU_106="$(plant106 '[{"op":"fx_type3_undo","key":"followup_daily_cap"}]')"; K6CU_106="$(q106 kycplaces "$PD" "$DATA" followup_daily_cap)"
check "$(sd106 "$X6CU_106" 0 row)|$(kv106 "$K6CU_106" keys)|$(kv106 "$K6CU_106" pre13)" "string|followup_daily_cap@ks,ss|ok" \
  "106-6c3 the fixture undone (A9) through the store's own load() and save(): followup_daily_cap a string in both places, condition 3 holding"

echo; echo "== 106-6d. an undecodable kyc_config.json (a FIXTURE): AS-RB0's plain note naming condition 1, no command; with followup_auto_send on from <data>/config.json (a FIXTURE): DOES NOT DECODE … NO COMMAND IS PRINTED; each answered no; undone by sha256 =="
dpre106 "106-6d0" "$P106" on - "on:$LIM106" "off:nothing:not-written"
# FIXTURES, both by rh106-seed.php as root (each file's owner and mode kept, its original kept in $R106/kept/p5-6d): kyc_config.json
# replaced by marked text that does not decode (writeKycFile always writes JSON), then followup_auto_send '1' in <data>/config.json
# (uCRM's file, which no production writer of the plugin writes); both put back by fx_file_back, by sha256
mkdir -p "$R106/kept/p5-6d"; chmod 700 "$R106/kept/p5-6d"
CF6D0_106="$(q106 cfgsha "$PD" "$DATA")"; D6D_106="$(data94)"; RB6D_106="$(kv106 "$(q106 rblast "$OUT106" "$STATE106")" records)"
WD6D_106="$( [ -e "$DATA/config.json" ] && echo present || echo absent)"
X6D1_106="$(seed106r '[{"op":"fx_file","place":"k","keep":"'"$R106/kept/p5-6d"'","tag":"p5-6d-k","raw":"{\"RH106SECRET-undecodable\":"}]')"
K6D1_106="$(q106 kycplaces "$PD" "$DATA" followup_auto_send)"; PL6D1_106="$(pls106 "$K6D1_106")"; CF6D1_106="$(kv106 "$(q106 cfgsha "$PD" "$DATA")" all)"
check "$(jf105 "$X6D1_106" ops)|$(sd106 "$X6D1_106" 0 fixture)|$(sd106 "$X6D1_106" 0 what)|$(kv106 "$K6D1_106" file)|$(kv106 "$K6D1_106" eff)|$(kv106 "$K6D1_106" asplaces)|$(kv106 "$K6D1_106" pre13)" \
      "1|true|a file that does not decode|undecodable|followup_auto_send:off|k:?,s:on,p:-,d:-|file" \
  "106-6d1 the fixture: kyc_config.json replaced by marked text that does not decode — the jobs ignore it, so followup_auto_send reads off as they read it, though its store row says on; §L.7's condition 1 fails"
fresh106; O6D2_106="$(run106c --answer no --rollback)"; RC6D2_106="$(rc106)"
# deploy-5.18.106.sh:8882 (AS-RB0, condition 1 named), :8925 (already off), :13881 (the prompt), :1689
check "$(grep -cxF "  note  AS-RB0 followup_auto_send before the rollback, through the installed code (PluginConfig::read, as FollowUpPolicy::mayAutoSend reads it): off — per place: $PL6D1_106; - absent, ? unread; §L.7's five conditions do not hold: condition 1: <data>/kyc_config.json is undecodable" <<<"$O6D2_106")|$(grep -cxF "$AS0OFF106" <<<"$O6D2_106")|$(has "$O6D2_106" 'THE ROLLBACK CANNOT SET')|$(grep -c '^      docker exec ' <<<"$O6D2_106")|$(has "$O6D2_106" "${TRB106}followup_auto_send already off, nothing written, every other switch")|$(has "$O6D2_106" '  STOP: not confirmed')|$RC6D2_106|$(live)|$(data94)|$(kv106 "$(q106 cfgsha "$PD" "$DATA")" all)|$(kv106 "$(q106 rblast "$OUT106" "$STATE106")" records)" \
      "1|1|no|0|yes|yes|1|$P106|$D6D_106|$CF6D1_106|$RB6D_106" \
  "106-6d2 the rollback over it, answered no: AS-RB0's plain note naming condition 1, the file undecodable (S:8882), then \"already reads off\" (S:8925) — no capitals and no command; the prompt says nothing will be written; not confirmed, exit status 1; the file and the book unchanged"
X6D3_106="$(seed106r '[{"op":"fx_file","place":"d","keep":"'"$R106/kept/p5-6d"'","tag":"p5-6d-d","set":{"followup_auto_send":"1"}}]')"
K6D3_106="$(q106 kycplaces "$PD" "$DATA" followup_auto_send)"; PL6D3_106="$(pls106 "$K6D3_106")"; CF6D3_106="$(kv106 "$(q106 cfgsha "$PD" "$DATA")" all)"
check "$(jf105 "$X6D3_106" ops)|$(sd106 "$X6D3_106" 0 fixture)|$(sd106 "$X6D3_106" 0 keys)|$(kv106 "$K6D3_106" eff)|$(kv106 "$K6D3_106" asplaces)|$(kv106 "$K6D3_106" pd)" \
      "1|true|followup_auto_send|followup_auto_send:on|k:?,s:on,p:-,d:on|followup_auto_send@d" \
  "106-6d3 the sub-case's fixture: followup_auto_send '1' in <data>/config.json, beside the undecodable file — it reads on as the jobs read it"
fresh106; O6D4_106="$(run106c --answer no --rollback)"; RC6D4_106="$(rc106)"
# deploy-5.18.106.sh:8936 (the capitals, no command allowed), :8722 (DOES NOT DECODE … NO COMMAND IS PRINTED), :13881, :1689
check "$(grep -cxF "  note  THE ROLLBACK CANNOT SET followup_auto_send OFF ITSELF (condition 1: <data>/kyc_config.json is undecodable): $BV106 WILL THEN APPROVE AND SEND AUTOMATICALLY WHILE IT READS ON. TO AVOID THAT, ANSWER ANYTHING BUT ROLLBACK BELOW — NOTHING HAS BEEN CHANGED — AND ACT ON WHAT FOLLOWS (NO COMMAND CAN BE PRINTED FOR THIS CAUSE), THEN RUN THE ROLLBACK AGAIN:" <<<"$O6D4_106")|$(grep -cxF "  It reads on (per place: $PL6D3_106; - absent, ? unread). <data>/KYC_CONFIG.JSON DOES NOT DECODE (IT IS EMPTY OR NOT JSON): THE PLUGIN IGNORES THAT FILE TODAY, SO ITS VALUES ARE NOT IN EFFECT, AND set_config — OR ANY SETTINGS SAVE — WOULD REPLACE IT WITH A ONE-KEY FILE. NO COMMAND IS PRINTED: REPAIR IT BY HAND FIRST, THEN run the rollback again." <<<"$O6D4_106")|$(grep -c '^      docker exec ' <<<"$O6D4_106")|$(has "$O6D4_106" "${TRB106}followup_auto_send NOT set off — the rollback cannot write it (above), every other switch")|$(has "$O6D4_106" '  STOP: not confirmed')|$RC6D4_106|$(live)|$(data94)|$(kv106 "$(q106 cfgsha "$PD" "$DATA")" all)" \
      "1|1|0|yes|yes|1|$P106|$D6D_106|$CF6D3_106" \
  "106-6d4 the same rollback with followup_auto_send on from <data>/config.json, answered no: the capitals notice that the rollback cannot set it off, no command allowed for this cause (S:8936), and the owner's text: DOES NOT DECODE … NO COMMAND IS PRINTED (S:8722); no command line; not confirmed; every file and the book unchanged"
X6D5_106="$(seed106r '[{"op":"fx_file_back","place":"d","keep":"'"$R106/kept/p5-6d"'","tag":"p5-6d-d"},{"op":"fx_file_back","place":"k","keep":"'"$R106/kept/p5-6d"'","tag":"p5-6d-k"}]')"
K6D5_106="$(q106 kycplaces "$PD" "$DATA" followup_auto_send)"
check "$(jf105 "$X6D5_106" ops)|$(sd106 "$X6D5_106" 0 was)$(sd106 "$X6D5_106" 1 was)|$(kv106 "$(q106 cfgsha "$PD" "$DATA")" all)|$(kv106 "$K6D5_106" file):$(kv106 "$K6D5_106" pre13):$(kv106 "$K6D5_106" asplaces)|$(ls -A "$R106/kept/p5-6d" | grep -c .)" \
      "2|${WD6D_106}present|$(kv106 "$CF6D0_106" all)|decoded:ok:k:on,s:on,p:-,d:-|0" \
  "106-6d5 both fixtures undone (A9) before the next redeploy: <data>/config.json put back as it was ($WD6D_106 before), kyc_config.json put back — the four settings places byte for byte as before 106-6d1 (sha256), the file decoding, the key on; nothing left kept"

echo; echo "== 106-6f. step 6 on at the rollback, with the offers keys in the store row alone (a FIXTURE): STEP 12, 11 and 6, highest first, each its command alone; the key on, so it writes (for 7a) =="
dpre106 "106-6f0" "$P106" on - "on:$LIM106" "off:nothing:not-written"
O6F1_106="$(sf104c --tty yes on chase)"
# tools/sales_followup.php:224 (the question), :261 ("ON <step>: <keys>") — 7835906's and bf29cbd's alike (106-infra b)
check "$(sfrc104)|$(has "$O6F1_106" 'Type yes to switch the quote chase on:')|$(grep -c '^ON chase: ' <<<"$O6F1_106")" "0|yes|1" \
  "106-6f1 on chase through the installed tool while $V106 serves, yes typed at the terminal — ON, exit 0"
DG6F_106="$(digest105)"
# A FIXTURE: no production writer leaves an offers switch in the store row alone; rh106-seed.php fx_store_keys puts
# offers_live_enabled and offers_test_enabled there through the store's own load() and save(), kyc_config.json untouched
X6F2_106="$(plant106 '[{"op":"fx_store_keys","keys":{"offers_live_enabled":"1","offers_test_enabled":"1"}}]')"
check "$(jf105 "$X6F2_106" ops)|$(sd106 "$X6F2_106" 0 fixture)|$(sd106 "$X6F2_106" 0 file)" "1|true|unchanged" \
  "106-6f2 the fixture: offers_live_enabled and offers_test_enabled in the store row alone, kyc_config.json untouched"
dpre106 "106-6f3" "$P106" on "offers_live_enabled@s+offers_test_enabled@s+quote_chase_enabled@k,s" "on:$LIM106" "off:nothing:not-written"
rbx106 "106-6f4"
O="$RBX106_OUT"
# deploy-5.18.106.sh:8897/:8899 (step 12), :8903/:8905 (step 11), :8909/:8911 (step 6), :13881 (the prompt), :9032 (AS-RB1)
check "$(order106 "$O" "  note  STEP 12 (OFFERS-LIVE) IS ON: offers_live_enabled IS PRESENT IN <data>/kyc_config.json OR ITS STORE ROW. UNDER $BV106 THIS MEANS CLAIM MODE FOR THE ENQUIRY SENDS. CHANGE NO SWITCH WHILE THIS SCRIPT WAITS." "$OFFW106 offers-live" "  note  STEP 11 (OFFERS-TEST) IS ON: offers_test_enabled IS PRESENT IN <data>/kyc_config.json OR ITS STORE ROW. UNDER $BV106 THIS MEANS CLAIM MODE FOR THE ENQUIRY SENDS. CHANGE NO SWITCH WHILE THIS SCRIPT WAITS." "$OFFW106 offers-test" "  note  STEP 6 (CHASE) IS ON: quote_chase_enabled IS PRESENT IN <data>/kyc_config.json OR ITS STORE ROW. UNDER $BV106 THIS MEANS CLAIM MODE FOR THE ENQUIRY SENDS. CHANGE NO SWITCH WHILE THIS SCRIPT WAITS." "$OFFW106 chase" "$TRB106")|$(for w106 in offers-live offers-test chase; do grep -cxF "$OFFW106 $w106" <<<"$O"; done | paste -sd, -)|$(okline "$O" AS-RB1 'followup_auto_send set off before the put-back (typed)')|$(sget106 "$RBX106_REC" AS_RB1)|$(kv106 "$(q106 kycplaces "$PD" "$DATA" offers_live_enabled,offers_test_enabled,quote_chase_enabled)" cs)" \
      "ordered|1,1,1|yes|written|offers_live_enabled@s+offers_test_enabled@s+quote_chase_enabled@k,s" \
  "106-6f4'' step 6 on and the offers keys in the store row at the rollback: AS-RB0's three notices in capitals, highest step first — STEP 12, STEP 11, STEP 6 — each followed by its off command alone on its line (S:8897-8911), before the question; never run; the key on and the five conditions holding, AS-RB1 wrote (its record AS_RB1=written); the three claim switches still where 106-6f3 found them (every ladder key across the rollback is rbx106's own check, against its k0 taken with the fixture in place)"
X6F5_106="$(plant106 '[{"op":"fx_store_keys_undo","keys":["offers_live_enabled","offers_test_enabled"]}]')"
check "$(jf105 "$X6F5_106" ops)|$(sd106 "$X6F5_106" 0 file)|$(kv106 "$(q106 kycplaces "$PD" "$DATA" offers_live_enabled,offers_test_enabled)" cs)|$( [ "$(digest105)" = "$DG6F_106" ] && echo before-fixture)" \
      "1|unchanged|quote_chase_enabled@k,s|before-fixture" \
  "106-6f5 the fixture undone (A9) through the store's own load() and save(): no offers key in either place, step 6 still on (for 7a), every ladder key as after on chase"

echo; echo "== 106-7. redeploys and the offer to turn followup_auto_send back on; V1's automatic rollback, its put-back made to fail (A7) =="
echo; echo "== 106-7a. after 6f, step 6 on: --redeploy refused by CS-G1; its off command as printed; --redeploy PASSED; its --after-only offers 6f's command, run =="
dpre106 "106-7a0" "$B106" off "quote_chase_enabled@k,s" "on:$LIM106" "written:nothing:not-before"
D7A_106="$(data94)"; fresh106; O7A_106="$(run106c --answer DEPLOY --redeploy)"
# deploy-5.18.106.sh:7434 (CS-G1's refusal), :1689
nogo106 "106-7a1 CS-G1 on the --redeploy with step 6 on: NO-GO — quote_chase_enabled present (k,s), exit 1, never the question, nothing changed" \
        "NO-GO (CS-G1): a claim switch is present (quote_chase_enabled@k,s) — until $V106 serves none may be, with any value" "$O7A_106" "$D7A_106" "$B106"
OFF7A_106="$(printed "$O7A_106" "cd $REPO && bash scripts/sales-followup.sh off chase")"; O7A2_106="$(run_line106c "" "$OFF7A_106")"
check "$( [ "      $OFF7A_106" = "$OFFW106 chase" ] && echo printed)|$(rc106)|$(grep -cx 'OFF chase' <<<"$O7A2_106")|$(kv105 "$(lk105)" on | tr ',' '\n' | grep -cx chase)" "printed|0|1|0" \
  "106-7a2 CS-G1's off command run exactly as printed (S:7428), under the clock: OFF chase, exit 0 — step 6 off by this rehearsal's ladder reading"
dredeploy106 "106-7a3"
dpre106 "106-7a4" "$P106" off - "on:$LIM106" "written:offer-if-off"
master106 "106-7a5"
aft106 "106-7a6" PASSED $L1ON106
doffer106 "106-7a7"

echo; echo "== 106-7b. a typed rollback that writes, then a plain deploy (no flag): BR1's note, PASSED; its --after-only offers the rollback's command, run =="
dpre106 "106-7b0" "$P106" on - "on:$LIM106" "written:offer-if-off"
rbx106 "106-7b1"
check "$(sget106 "$RBX106_REC" AS_RB1)|$(kv106 "$(q106 rblast "$OUT106" "$STATE106")" file)" "written|$(basename "$RBX106_REC")" \
  "106-7b1'' the rollback wrote followup_auto_send off (AS_RB1=written); its record the latest by this rehearsal's own reading (rblast)"
dpre106 "106-7b2" "$B106" off - "on:$LIM106" "written:nothing:not-before"
K7B_106="$(q106 kycplaces "$PD" "$DATA" followup_auto_send)"
check "$(live)|$(kv106 "$K7B_106" cs)|$(kv106 "$(dnow106)" en)|$(kv106 "$K7B_106" file):$(kv106 "$K7B_106" row)|$(left106)" "$B106|-|on|decoded:decoded|$NA106" \
  "106-7b3 the gates before the plain deploy hold, by this rehearsal's own reading: $B106 serving, no claim switch, followup_enabled on, both places decodable; all $NA106 of $V106's own files still on disk (the rollback deletes none)"
P7B_106="$(ls -1 "$STATE106".prev-* 2>/dev/null | grep -c .)"
fresh106; O7B_106="$(run106c --answer DEPLOY)"; T7B_106="$(last106)"
# deploy-5.18.106.sh:12451 (BR1's note), :16084 (PASSED), :7409 (CS-G1), :7494 (LK-CA1), :13737 (the earlier state file kept)
check "$(fails "$O7B_106")$(has "$O7B_106" "  $V106 (deploy): PASSED.")|$(rc106)|$(went106 "$O7B_106")|$(noteline "$O7B_106" BR1 "$NA106 of $V106's own files are on disk although --redeploy was not asked for: an earlier $V106 was deployed here and rolled back" "offering your own command to turn followup_auto_send back on where that rollback set it off")|$(live)|$(installed106 "$P106F")|$(okline "$O7B_106" CS-G1 'none of the three claim switches')$(okline "$O7B_106" LK-CA1 'after the copy every ladder key')|$(( $(ls -1 "$STATE106".prev-* 2>/dev/null | grep -c .) - P7B_106 ))|$(copyok106)|$(ctl106)|$(cat "$R106/evo/run-$T7B_106.n" 2>/dev/null)" \
      "0yes|0|yesyes|yes|$P106|0|yesyes|1|near|before|0 nocron" \
  "106-7b4 the plain deploy after the rollback, no --redeploy, DEPLOY typed: BR1's note — $V106's own files on disk, a deploy after that rollback all the same, its --after-only to offer the turn-back-on (S:12451) — PASSED, $P106 serving and installed, CS-G1 and LK-CA1 ok, the earlier state file kept beside the new one (S:13737), COPY_AT on the clock (A1), followup_close not run since the copy (A2), Evolution not called"
dpre106 "106-7b5" "$P106" off - "on:$LIM106" "written:offer-if-off"
master106 "106-7b6"
aft106 "106-7b7" PASSED $L1ON106
doffer106 "106-7b8"

echo; echo "== 106-7c. V1 with its precondition holding: it writes, the put-back succeeds; --redeploy; its --after-only offers V1's command (its record kept beside the state file), run =="
dpre106 "106-7c0" "$P106" on - "on:$LIM106" "written:offer-if-off"
rbx106 "106-7c1"
O7C2_106="$(setcfg106 followup_auto_send 1)"
check "$(grep -cxF '  followup_auto_send = 1' <<<"$O7C2_106")|$(setrc106)" "1|0" "106-7c2 the owner's --value 1 through set_config as the book's owner, $BV106 serving: \"  followup_auto_send = 1\", exit 0"
dpre106 "106-7c3" "$B106" on - "on:$LIM106" "written:nothing:not-before"
v1run106 "106-7c4"
O="$V1OUT106"
# deploy-5.18.106.sh:9032 (AS-RB1, v1), :13828 (the put-back verified), :13928 (the stop; as_para v1:written prints nothing, :8752)
check "$(okline "$O" AS-RB1 'followup_auto_send set off before the put-back (v1)')|$(has "$O" "  the container now serves $B106; the checkout is back on ")|$(has "$O" "  STOP: rolled back (serves $B106); RB106 above reads the put-back — every switch of the sales follow-up kept as it was but followup_auto_send (set off first, AS-RB1), 5.18.105's masked lines still masked under $BV106 (LM-RB1); ")|$(grep -cE -- '--key followup_auto_send --value [01] ' <<<"$O")|$(live)|$(installed106 "$B106F")|$(sget106 "$STATE106" AS_RB1)|$(grep -c '^RB_COPY_AT=[0-9]' "$STATE106")|$(kv106 "$(q106 kycplaces "$PD" "$DATA" followup_auto_send)" asplaces)" \
      "yes|yes|yes|0|$B106|0|written|1|k:off,s:off,p:-,d:-" \
  "106-7c4' V1's AS-RB1 set followup_auto_send off (S:9032) and the put-back verified (S:13828): the stop says rolled back, every switch kept but followup_auto_send (S:13928), with no command (V1 wrote it, :8752); $B106 serving, every file $V106 changes $BV106's; this deploy's state file records AS_RB1=written and the put-back's copy time"
qcheck106 "106-7c5"
dpre106 "106-7c6" "$B106" off - "on:$LIM106" "written:nothing:v1guard"
dredeploy106 "106-7c7"; DRD7C_106="$DRD106_OUT"; PL7C_106="$(pls106 "$(q106 kycplaces "$PD" "$DATA" followup_auto_send)")"
R7C_106="$(q106 rblast "$OUT106" "$STATE106")"
check "$(kv106 "$R7C_106" last):$(case "$(kv106 "$R7C_106" file)" in "state-$V106.env.prev-"*) echo prev ;; *) echo other ;; esac):$(kv106 "$R7C_106" before):$(kv106 "$R7C_106" v1guard):$(kv106 "$R7C_106" would)" "written:prev:yes:pass:offer-if-off" \
  "106-7c8 this rehearsal's own reading after the --redeploy (rblast, as_rb_last's mirror): the latest rollback record is V1's, in the state file the --redeploy kept beside its own (S:13737), written, its copy before this deploy's — as_back_on would offer were the key off"
master106 "106-7c9"
aft106 "106-7c10" PASSED $L1ON106
doffer106 "106-7c11"

echo; echo "== 106-7d. V1 with its precondition failing (a key the file holds and the row lacks): its stop carries the owner's command; then --rollback with nothing to roll back: AS-RB2's capitals and the command =="
dpre106 "106-7d0" "$P106" on - "on:$LIM106" "written:offer-if-off"
rbx106 "106-7d1"
O7D2_106="$(setcfg106 followup_auto_send 1)"
check "$(grep -cxF '  followup_auto_send = 1' <<<"$O7D2_106")|$(setrc106)" "1|0" "106-7d2 the owner's --value 1 through set_config as the book's owner, $BV106 serving"
# the file-only key, as in 106-6b: set_evolution.php --url, the file and the vault kept first, undone byte for byte at 106-7d10
KK7D106="$(keep106 p5-k7d "$DATA/kyc_config.json")"; KV7D106="$(keep106 p5-v7d "$VAULT")"; CF7D0_106="$(q106 cfgsha "$PD" "$DATA")"
E7D3_106="$(evo_calls)"; O7D3_106="$(setevo106 --url "$EVF106" --allow-insecure)"; R7D3_106="$(sevrc106)"; EVS106=$(( EVS106 + $(evo_calls) - E7D3_106 ))
check "$(grep -cxF "    API URL  $EVF106" <<<"$O7D3_106")|$R7D3_106|$(kv106 "$(q106 kycplaces "$PD" "$DATA" evo_api_url)" pre13):$(kv106 "$(q106 kycplaces "$PD" "$DATA" evo_api_url)" differ)" "1|1|differ:evo_api_url" \
  "106-7d3 the file-only key planted through set_evolution.php --url as the book's owner (the file and the vault kept first, $KK7D106/$KV7D106): condition 3 fails on evo_api_url"
dpre106 "106-7d4" "$B106" on - "on:$LIM106" "written:nothing:not-before"
PL7D_106="$(pls106 "$(q106 kycplaces "$PD" "$DATA" followup_auto_send)")"; CA7D_106="condition 3: the file and the store row disagree on evo_api_url"
v1run106 "106-7d5"
O="$V1OUT106"; C7D_106="$(printed "$O" "docker exec -u ")"
# deploy-5.18.106.sh:9038 (AS-RB1's FAIL, v1), :9144 (AS-RB2), :13928 (the stop), :8755 (as_para v1, not written), :8724, :8735
check "$(grep -cxF "  FAIL  AS-RB1 FOLLOWUP_AUTO_SEND READS ON AND WAS NOT SET OFF: §L.7'S FIVE CONDITIONS DO NOT HOLD ($CA7D_106) — $BV106 WILL APPROVE AND SEND AUTOMATICALLY UNTIL IT IS OFF; the rollback goes on (it is never blocked by this) — your own command, where the cause allows one, is printed in the stop message below" <<<"$O")|$(grep -cxF "  note  AS-RB2 followup_auto_send reads on under $BV106 — AS-RB1 did not write it (not written: $CA7D_106; its FAIL above): your own command, where its cause allows one, is printed in the stop message below" <<<"$O")|$(order106 "$O" "  STOP: rolled back (serves $B106); RB106 above reads the put-back — every switch of the sales follow-up kept as it was but followup_auto_send (not written: $CA7D_106), " "  AS-RB1 DID NOT SET followup_auto_send OFF (not written: $CA7D_106). It reads on (per place: $PL7D_106; - absent, ? unread). The command below also copies <data>/kyc_config.json, whole, into the settings row" "      docker exec -u " "  It prints the line \"  followup_auto_send = 0\" once it has saved; " "  Nothing further was done. Send the log file.")|$(grep -cxF "      $C7D_106" <<<"$O")|$(ascmd106 0 "$C7D_106")|$(live)|$(sget106 "$STATE106" AS_RB1)|$(kv106 "$(q106 kycplaces "$PD" "$DATA" followup_auto_send)" asplaces)" \
      "1|1|ordered|1|form|$B106|not-written:differ|k:on,s:on,p:-,d:-" \
  "106-7d5' V1 with condition 3 failing: AS-RB1's FAIL in capitals, its command promised in the stop message (S:9038), AS-RB2's note (S:9144); the put-back verified and the stop says rolled back (S:13928), then as_para v1 — AS-RB1 did not set it off, the owner's text and command, alone (S:8755, S:8735); $B106 serving, the record AS_RB1=not-written:differ, followup_auto_send still on"
qcheck106 "106-7d6"
dpre106 "106-7d7" "$B106" on - "on:$LIM106" "written:nothing:v1guard"
RB7D8_106="$(kv106 "$(q106 rblast "$OUT106" "$STATE106")" records)"
fresh106; O7DN_106="$(run106c --rollback)"; RC7DN_106="$(rc106)"; C7DN_106="$(printed "$O7DN_106" "docker exec -u ")"
# deploy-5.18.106.sh:12381 (nothing to roll back), :9094 (LK-RB1 not judged), :9140 (AS-RB2's capitals under RB_NOTHING), :8735, :16084
check "$(noteline "$O7DN_106" the "container already serves $B106 ($BV106) — nothing to roll back; running the rollback checks")|$(noteline "$O7DN_106" LK-RB1 'not judged: this run rolled nothing back')|$(order106 "$O7DN_106" "  note  AS-RB2 FOLLOWUP_AUTO_SEND READS ON UNDER $BV106, WHICH APPROVES AND SENDS AUTOMATICALLY WHILE IT DOES (per place: $PL7D_106; - absent, ? unread) — this run rolled nothing back, so it wrote nothing (AS-RB1 runs only in a rollback that puts $BV106 back); your own command, as the one reader allows it:" "  It reads on (per place: " "      docker exec -u ")|$(ascmd106 0 "$C7DN_106")|$(has "$O7DN_106" '  Type ROLLBACK to put ')|$(fails "$O7DN_106")$(has "$O7DN_106" "  $V106 (rollback): PASSED.")|$RC7DN_106|$(live)|$(kv106 "$(q106 rblast "$OUT106" "$STATE106")" records)|$(kv106 "$(q106 kycplaces "$PD" "$DATA" followup_auto_send)" asplaces)" \
      "yes|yes|ordered|form|no|0yes|0|$B106|$RB7D8_106|k:on,s:on,p:-,d:-" \
  "106-7d8 --rollback with $BV106 already serving and the key on (RH106-15): nothing to roll back (S:12381), LK-RB1 not judged (S:9094), no question; AS-RB2's capitals and the owner's command (S:9140) — nothing written; PASSED, no rollback record with a copy time"
qcheck106 "106-7d9"
check "$(back106 p5-k7d)|$(back106 p5-v7d)|$(kv106 "$(q106 cfgsha "$PD" "$DATA")" all)|$(kv106 "$(q106 kycplaces "$PD" "$DATA" evo_api_url)" pre13)" "same|same|$(kv106 "$CF7D0_106" all)|ok" \
  "106-7d10 the file-only key undone (A9): kyc_config.json and the vault put back byte for byte (nothing wrote either since the plant) — the four settings places as before 106-7d3"

echo; echo "== 106-7h. a PASSED deploy with followup_auto_send on: the by-hand paragraph prints the owner's whole command (with it off, 7c's redeploy, nothing to run) =="
dpre106 "106-7h0" "$B106" on - "on:$LIM106" "written:nothing:v1guard"
PL7H_106="$(pls106 "$(q106 kycplaces "$PD" "$DATA" followup_auto_send)")"
dredeploy106 "106-7h1"
O="$DRD106_OUT"; C7H_106="$(printed "$O" "docker exec -u ")"
# deploy-5.18.106.sh:16191 (as_para sum after the put-back by hand), :8752 (its sentence), :8735 (the command), :8715 (already off)
check "$(order106 "$O" "      ( cd $REPO && git checkout $B106 && { bash scripts/deploy-hybrid.sh; git checkout -; } ) " "  followup_auto_send before a put-back by hand, which writes no setting (the rollback above sets it off first, AS-RB1): It reads on (per place: $PL7H_106; - absent, ? unread). Your own command, alone, with its own log:" "      docker exec -u " "  It prints the line \"  followup_auto_send = 0\" once it has saved; without that line take nothing as saved (run the rollback again: it reads the setting first).")|$(ascmd106 0 "$C7H_106")|$(grep -cxF "  followup_auto_send before a put-back by hand, which writes no setting (the rollback above sets it off first, AS-RB1): It already reads off (per place: $PL7C_106; - absent, ? unread): nothing to run." <<<"$DRD7C_106")|$(grep -c '^      docker exec .* --key followup_auto_send ' <<<"$DRD7C_106")" \
      "ordered|form|1|0" \
  "106-7h1'' the PASSED --redeploy with followup_auto_send on: after the put-back by hand, the by-hand paragraph (S:16191) — it reads on, and the owner's whole command to set it off, alone, with the line it prints (S:8752, S:8735); its control, 106-7c7's PASSED --redeploy with the key off: \"It already reads off … nothing to run.\" (S:8715), no command"

echo; echo "== 106-7e. a typed rollback that writes → the owner's --value 1 → V1 written, its put-back made to fail (.redirect106lock): $V106 serves; its --after-only offers nothing; BOV caught =="
dpre106 "106-7e0" "$P106" on - "on:$LIM106" "not-written:differ:nothing:not-written"
rbx106 "106-7e1"; RB7E_106="$RBX106_REC"
O7E2_106="$(setcfg106 followup_auto_send 1)"
check "$(grep -cxF '  followup_auto_send = 1' <<<"$O7E2_106")|$(setrc106)" "1|0" "106-7e2 the owner's --value 1 through set_config as the book's owner, $BV106 serving"
dpre106 "106-7e3" "$B106" on - "on:$LIM106" "written:nothing:not-before"
v1run106 "106-7e4" lock
O="$V1OUT106"
# deploy-5.18.106.sh:13863 (the deploy's checkout back), :13923, :9032 (AS-RB1, v1), :13818 (the put-back's checkout refused),
# :13929 (the stop), :8748 (as_para rb:written)
check "$(okline "$O" AS-RB1 'followup_auto_send set off before the put-back (v1)')|$(order106 "$O" "  the checkout is back on " "  ROLLING BACK — the public address must never redirect" "  ok    AS-RB1 followup_auto_send set off before the put-back (v1)" "  FAIL  git checkout $B106 failed — nothing was deployed" "  STOP: the automatic rollback did not complete — it did not verify (live=$P106): ")|$(has "$O" " followup_auto_send was set off before the put-back was tried (AS-RB1): $V106 keeps serving with automatic approval off, and a put-back by hand leaves it off — a person approves each draft.")|$(grep -cE -- '--key followup_auto_send --value [01] ' <<<"$O")|$(live)|$(installed106 "$P106F")|$(sget106 "$STATE106" AS_RB1)|$(grep -c '^RB_COPY_AT=' "$STATE106")|$(kv106 "$(q106 kycplaces "$PD" "$DATA" followup_auto_send)" asplaces)" \
      "yes|ordered|yes|0|$P106|0|written|0|k:off,s:off,p:-,d:-" \
  "106-7e4'' V1 written, its put-back made to fail: the deploy's own checkout back first (S:13863), then ROLLING BACK, AS-RB1's write (S:9032), then \"git checkout $B106 failed — nothing was deployed\" (S:13818) and the stop: the automatic rollback did not complete (S:13929), followup_auto_send set off before the put-back was tried (S:8748), no command; $P106 still serving and installed; this deploy's state file AS_RB1=written, no put-back copy time"
qcheck106 "106-7e5"
R7E_106="$(q106 rblast "$OUT106" "$STATE106")"
check "$(kv106 "$R7E_106" last):$(kv106 "$R7E_106" file):$(kv106 "$R7E_106" before):$(kv106 "$R7E_106" v1):$(kv106 "$R7E_106" v1guard):$(kv106 "$R7E_106" would)|$( [ "$(kv106 "$R7E_106" t)" -lt "$(kv106 "$R7E_106" copy_at)" ] 2>/dev/null && echo earlier)" \
      "written:$(basename "$RB7E_106"):yes:written:stop:nothing:v1guard|earlier" \
  "106-7e6 this rehearsal's own reading before the --after-only (rblast): the latest rollback record 106-7e1's typed one, written, its copy time before this deploy's COPY_AT; this deploy's own AS_RB1=written stops as_back_on (the V1 guard, S:8829) — without it, the key reading off, it would offer"
dpre106 "106-7e7" "$P106" off - "on:$LIM106" "written:nothing:v1guard"
master106 "106-7e8"
aft106 "106-7e9" PASSED $L1ON106
check "$(dnooffer106)" "none" "106-7e9' its PASSED --after-only offers nothing (S:8829): V1 of this deploy wrote followup_auto_send, and its own stop said what it left"
MB7E_106="$(weak106 bov bov)"; fresh106; OB7E_106="$(R106_MUTANT_OUT=1 run106c --script "$MB7E_106" --after-only)"; rm -f "$MB7E_106"
check "$(has "$OB7E_106" "$OFFER106")|$(grep -cE '^      docker exec .* --key followup_auto_send --value 1 ' <<<"$OB7E_106")|$(has "$OB7E_106" "  $V106 (after): PASSED.")|$( [ -e "$MB7E_106" ] && echo left || echo removed)" "yes|1|yes|removed" \
  "106-7e10 BOV, the copy without as_back_on's V1 guard, on this same state: it offers the turn-back-on command — caught by 106-7e9'; the copy removed"

echo; echo "== 106-7f. the same, V1 reading the key on with condition 3 failing (not-written:differ), its put-back made to fail: its printed --value 0 run; then the --after-only offers nothing; BOV again caught =="
dpre106 "106-7f0" "$P106" off - "on:$LIM106" "written:nothing:v1guard"
O7F1_106="$(setcfg106 followup_auto_send 1)"
check "$(grep -cxF '  followup_auto_send = 1' <<<"$O7F1_106")|$(setrc106)" "1|0" "106-7f1 followup_auto_send on through set_config as the book's owner, $V106 serving — the owner's own, where no --after-only offers it"
rbx106 "106-7f2"; RB7F_106="$RBX106_REC"
O7F3_106="$(setcfg106 followup_auto_send 1)"
check "$(grep -cxF '  followup_auto_send = 1' <<<"$O7F3_106")|$(setrc106)" "1|0" "106-7f3 the owner's --value 1 through set_config as the book's owner, $BV106 serving"
KK7F106="$(keep106 p5-k7f "$DATA/kyc_config.json")"; KV7F106="$(keep106 p5-v7f "$VAULT")"; CF7F0_106="$(q106 cfgsha "$PD" "$DATA")"
E7F4_106="$(evo_calls)"; O7F4_106="$(setevo106 --url "$EVF106" --allow-insecure)"; R7F4_106="$(sevrc106)"; EVS106=$(( EVS106 + $(evo_calls) - E7F4_106 ))
check "$(grep -cxF "    API URL  $EVF106" <<<"$O7F4_106")|$R7F4_106|$(kv106 "$(q106 kycplaces "$PD" "$DATA" evo_api_url)" pre13):$(kv106 "$(q106 kycplaces "$PD" "$DATA" evo_api_url)" differ)" "1|1|differ:evo_api_url" \
  "106-7f4 the file-only key planted through set_evolution.php --url as the book's owner (the file and the vault kept first, $KK7F106/$KV7F106): condition 3 fails on evo_api_url"
dpre106 "106-7f5" "$B106" on - "on:$LIM106" "written:nothing:v1guard"   # STATE106 still 106-7e4's V1 deploy (AS_RB1=written): the guard first (S:8829); a typed rollback never writes STATE (review r2 F1)
PL7F_106="$(pls106 "$(q106 kycplaces "$PD" "$DATA" followup_auto_send)")"; CA7F_106="condition 3: the file and the store row disagree on evo_api_url"
v1run106 "106-7f6" lock
O="$V1OUT106"; C7F_106="$(printed "$O" "docker exec -u ")"
# deploy-5.18.106.sh:9038 (AS-RB1's FAIL, v1), :13818, :13929, :8755 (as_para rb, not written), :8724, :8735
check "$(grep -cxF "  FAIL  AS-RB1 FOLLOWUP_AUTO_SEND READS ON AND WAS NOT SET OFF: §L.7'S FIVE CONDITIONS DO NOT HOLD ($CA7F_106) — $BV106 WILL APPROVE AND SEND AUTOMATICALLY UNTIL IT IS OFF; the rollback goes on (it is never blocked by this) — your own command, where the cause allows one, is printed in the stop message below" <<<"$O")|$(order106 "$O" "  the checkout is back on " "  FAIL  AS-RB1 FOLLOWUP_AUTO_SEND READS ON AND WAS NOT SET OFF: " "  FAIL  git checkout $B106 failed — nothing was deployed" "  STOP: the automatic rollback did not complete — it did not verify (live=$P106): " "  AS-RB1 DID NOT SET followup_auto_send OFF (not written: $CA7F_106), and the put-back by hand does not either. It reads on (per place: $PL7F_106; - absent, ? unread). The command below also copies <data>/kyc_config.json, whole, into the settings row" "      docker exec -u " "  It prints the line \"  followup_auto_send = 0\" once it has saved; ")|$(grep -cxF "      $C7F_106" <<<"$O")|$(ascmd106 0 "$C7F_106")|$(live)|$(sget106 "$STATE106" AS_RB1)|$(kv106 "$(q106 kycplaces "$PD" "$DATA" followup_auto_send)" asplaces)" \
      "1|ordered|1|form|$P106|not-written:differ|k:on,s:on,p:-,d:-" \
  "106-7f6'' V1 reading the key on with condition 3 failing, its put-back made to fail: AS-RB1's FAIL (S:9038), \"git checkout $B106 failed\" (S:13818), the stop (S:13929) and as_para rb — AS-RB1 did not set it off, nor does a put-back by hand — with the owner's text and command, alone (S:8755, S:8735); $P106 still serving; this deploy's AS_RB1=not-written:differ; the key still on"
qcheck106 "106-7f7"
check "$(back106 p5-k7f)|$(back106 p5-v7f)|$(kv106 "$(q106 cfgsha "$PD" "$DATA")" all)|$(kv106 "$(q106 kycplaces "$PD" "$DATA" evo_api_url)" pre13)" "same|same|$(kv106 "$CF7F0_106" all)|ok" \
  "106-7f8 the file-only key undone (A9) before the owner's command: kyc_config.json and the vault byte for byte (nothing wrote either since the plant) — the four settings places as before 106-7f4"
fresh106; O7F9_106="$(run_line106c "" "$C7F_106")"; RC7F9_106="$(rc106)"; LG7F9_106="$(ls -1t "$OUT106"/autosend-0-*.log 2>/dev/null | head -1)"
check "$(grep -cxF '  followup_auto_send = 0' <<<"$O7F9_106")|$(grep -c 'AI SETTINGS' <<<"$O7F9_106")|$RC7F9_106|$(grep -cxF '  followup_auto_send = 0' "${LG7F9_106:-/nonexistent}" 2>/dev/null)|$(kv106 "$(q106 kycplaces "$PD" "$DATA" followup_auto_send)" asplaces)" \
      "1|0|0|1|k:off,s:off,p:-,d:-" \
  "106-7f9 the stop's printed --value 0 run exactly as printed (run_line106c, under the clock): \"  followup_auto_send = 0\", nothing of the listing, exit 0, its own log holding the line; the key off in the file and its store row"
R7F_106="$(q106 rblast "$OUT106" "$STATE106")"
check "$(kv106 "$R7F_106" last):$(kv106 "$R7F_106" file):$(kv106 "$R7F_106" before):$(kv106 "$R7F_106" v1):$(kv106 "$R7F_106" v1guard):$(kv106 "$R7F_106" would)" \
      "written:$(basename "$RB7F_106"):yes:not-written:differ:stop:nothing:v1guard" \
  "106-7f10 this rehearsal's own reading before the --after-only (rblast): the latest record 106-7f2's typed one, written, before this deploy's copy; the key off; this deploy's own AS_RB1=not-written:differ stops as_back_on (S:8829)"
dpre106 "106-7f11" "$P106" off - "on:$LIM106" "written:nothing:v1guard"
master106 "106-7f12"
aft106 "106-7f13" PASSED $L1ON106
check "$(dnooffer106)" "none" "106-7f13' its PASSED --after-only offers nothing (S:8829): V1 of this deploy read the key on, and its own stop carried the owner's command"
MB7F_106="$(weak106 bov bov)"; fresh106; OB7F_106="$(R106_MUTANT_OUT=1 run106c --script "$MB7F_106" --after-only)"; rm -f "$MB7F_106"
check "$(has "$OB7F_106" "$OFFER106")|$(grep -cE '^      docker exec .* --key followup_auto_send --value 1 ' <<<"$OB7F_106")|$(has "$OB7F_106" "  $V106 (after): PASSED.")|$( [ -e "$MB7F_106" ] && echo left || echo removed)" "yes|1|yes|removed" \
  "106-7f14 BOV again, on this state: it offers — caught by 106-7f13'; the copy removed"

echo; echo "== 106-7g. a typed rollback that writes → V1 finding the key already off (no --value 1), its put-back made to fail: the --after-only offers the typed rollback's command, run =="
dpre106 "106-7g0" "$P106" off - "on:$LIM106" "written:nothing:v1guard"
O7G1_106="$(setcfg106 followup_auto_send 1)"
check "$(grep -cxF '  followup_auto_send = 1' <<<"$O7G1_106")|$(setrc106)" "1|0" "106-7g1 followup_auto_send on through set_config as the book's owner, $V106 serving, so the typed rollback below writes"
rbx106 "106-7g2"; RB7G_106="$RBX106_REC"
dpre106 "106-7g3" "$B106" off - "on:$LIM106" "written:nothing:v1guard"   # STATE106 still 106-7f6's V1 deploy (AS_RB1=not-written:differ): the guard first (S:8829) (review r2 F2)
v1run106 "106-7g4" lock
O="$V1OUT106"
# deploy-5.18.106.sh:9036 (AS-RB1: already off), :13818, :13929, :8749 (as_para rb:off)
check "$(noteline "$O" AS-RB1 'followup_auto_send already reads off — nothing was written')|$(order106 "$O" "  ROLLING BACK — the public address must never redirect" "  note  AS-RB1 followup_auto_send already reads off — nothing was written" "  FAIL  git checkout $B106 failed — nothing was deployed" "  STOP: the automatic rollback did not complete — it did not verify (live=$P106): ")|$(has "$O" " followup_auto_send read off before the put-back was tried (AS-RB1 wrote nothing), and a put-back by hand leaves it so.")|$(grep -cE -- '--key followup_auto_send --value [01] ' <<<"$O")|$(live)|$(sget106 "$STATE106" AS_RB1)" \
      "yes|ordered|yes|0|$P106|off" \
  "106-7g4'' V1 finding followup_auto_send already off: AS-RB1 wrote nothing (S:9036), the put-back's checkout refused (S:13818), the stop (S:13929): it read off before the put-back was tried (S:8749), no command; $P106 still serving; this deploy's AS_RB1=off"
qcheck106 "106-7g5"
R7G_106="$(q106 rblast "$OUT106" "$STATE106")"
check "$(kv106 "$R7G_106" last):$(kv106 "$R7G_106" file):$(kv106 "$R7G_106" before):$(kv106 "$R7G_106" v1):$(kv106 "$R7G_106" v1guard):$(kv106 "$R7G_106" would)" \
      "written:$(basename "$RB7G_106"):yes:off:pass:offer-if-off" \
  "106-7g6 this rehearsal's own reading before the --after-only (rblast): the latest record 106-7g2's typed one, written, before this deploy's copy; this deploy's AS_RB1=off passes the V1 guard (S:8829) — the key off, as_back_on would offer"
dpre106 "106-7g7" "$P106" off - "on:$LIM106" "written:offer-if-off"
master106 "106-7g8"
aft106 "106-7g9" PASSED $L1ON106
doffer106 "106-7g10"

echo; echo "== 106-7i. the known limit (design-v2 A10; root docs/75 §L.8's base rule — THE OWNER'S DECISION): V1 finding the key off after a typed rollback that wrote, its put-back succeeding, then --redeploy — its --after-only offers nothing =="
dpre106 "106-7i0" "$P106" on - "on:$LIM106" "written:offer-if-off"
rbx106 "106-7i1"; RB7I_106="$RBX106_REC"
dpre106 "106-7i2" "$B106" off - "on:$LIM106" "written:nothing:not-before"
v1run106 "106-7i3"
O="$V1OUT106"
# deploy-5.18.106.sh:9036, :13828, :13928 (the stop; as_para v1:off prints nothing, :8752)
check "$(noteline "$O" AS-RB1 'followup_auto_send already reads off — nothing was written')|$(has "$O" "  the container now serves $B106; the checkout is back on ")|$(has "$O" "  STOP: rolled back (serves $B106); RB106 above reads the put-back — every switch of the sales follow-up kept as it was but followup_auto_send (already off, AS-RB1 wrote nothing), ")|$(grep -cE -- '--key followup_auto_send --value [01] ' <<<"$O")|$(live)|$(sget106 "$STATE106" AS_RB1)|$(grep -c '^RB_COPY_AT=[0-9]' "$STATE106")" \
      "yes|yes|yes|0|$B106|off|1" \
  "106-7i3' V1 finding followup_auto_send off, its put-back verified: AS-RB1 wrote nothing (S:9036), the stop says rolled back (S:13928), no command; $B106 serving; this deploy's state file AS_RB1=off with the put-back's copy time"
qcheck106 "106-7i4"
dpre106 "106-7i5" "$B106" off - "on:$LIM106" "written:offer-if-off"
dredeploy106 "106-7i6"
R7I_106="$(q106 rblast "$OUT106" "$STATE106")"
check "$(kv106 "$R7I_106" last):$(case "$(kv106 "$R7I_106" file)" in "state-$V106.env.prev-"*) echo prev ;; *) echo other ;; esac):$(kv106 "$R7I_106" would)|$(sget106 "$RB7I_106" AS_RB1)|$( [ "$(sget106 "$RB7I_106" RB_COPY_AT)" -lt "$(kv106 "$R7I_106" t)" ] 2>/dev/null && echo earlier)" \
      "off:prev:nothing:not-written|written|earlier" \
  "106-7i7 this rehearsal's own reading after the --redeploy (rblast): the latest record is V1's (off), kept beside the state file — the typed rollback's (written) is older, and as_rb_last reads only the newest (S:8805-8818)"
dpre106 "106-7i8" "$P106" off - "on:$LIM106" "off:nothing:not-written"
master106 "106-7i9"
aft106 "106-7i10" PASSED $L1ON106
check "$(dnooffer106)|$(kv106 "$(q106 kycplaces "$PD" "$DATA" followup_auto_send)" asplaces)" "none|k:off,s:off,p:-,d:-" \
  "106-7i11 THE KNOWN LIMIT, PINNED — THE OWNER'S DECISION (design-v2 A10, S6; root docs/75 §L.8's base rule): followup_auto_send reads off, set off by 106-7i1's typed rollback (AS_RB1=written in its record), yet this PASSED --after-only offers nothing, because V1 of the deploy before read it off, put back successfully and became the latest record (S:8805-8818, S:8835). Pinned so that a change of this behaviour is seen here"
PL7Z_106="$(q106 kycplaces "$PD" "$DATA" followup_auto_send,evo_api_key,evo_api_url,quote_company_phone,quote_company_email,followup_daily_cap)"
check "$(live)|$(kv106 "$PL7Z_106" cs)|$(kv106 "$(dnow106)" en):$(kv106 "$(dnow106)" hours)|$(kv106 "$PL7Z_106" keys)|$(kv106 "$PL7Z_106" pre13)|$(kv106 "$PL7Z_106" asplaces)|$(lock106)|$(ls -A "$SB/web" | grep -c '^\.redirect106')|$( [ -e "$SB/evo/rh106-refuse.list" ] && echo listed || echo none)|$( [ -e "$SB/.ujfail106" ] && echo armed || echo none)|$(ls "$REPO"/scripts/.ac3.sh "$REPO"/scripts/.rbn.sh "$REPO"/scripts/.bov.sh 2>/dev/null | grep -c .)" \
      "$P106|-|on:$LIM106|followup_auto_send@ks,ss;evo_api_key@ks,ss;evo_api_url@ks,ss;quote_company_phone@-;quote_company_email@-;followup_daily_cap@ks,ss|ok|k:off,s:off,p:-,d:-|absent|0|none|none|0" \
  "106-7z what 106-10 begins from: $P106 serving (106-7i6's --redeploy), no claim switch, followup_enabled on at $LIM106 h, followup_auto_send off; the Evolution keys in the file and its store row again, agreeing, the company's phone and address in neither, followup_daily_cap a string in both; no index lock, no V1 trigger, no refusal list, no uj trigger, no weakened copy left"
CLK7Z_106="$(fnow104)"
echo "  note  RH-NOTE 106-6 and 106-7 hand 106-10: $P106 ($V106) serving from 106-7i6's --redeploy; followup_auto_send off; the latest rollback record V1's (off) kept beside the state file; set_evolution's status asked the stand-in Evolution $EVS106 time(s), the sender once (J3); the clock $(kday104 "$CLK7Z_106") $(TZ=Africa/Kampala date -d "@$CLK7Z_106" +%H:%M) Kampala"

# ══════════════════════════════════════════════════════════════════════════════════════
# 106-10 — what the rehearsal left behind (design-v2 §C 106-10; A9)
# ══════════════════════════════════════════════════════════════════════════════════════
echo; echo "== 106-10. what the rehearsal left behind, over 104's, 105's and 106's records alike: the sweeps, the fixtures' leftovers, the chain's failure files =="
# 5.18.105's 105-10 is cut with its rehearsal (106-cut), so what it judged is judged here again, over 105's records and 106's
# alike, by this file's own lines: none of R5's past the cut is read here (106-infra d read sweep105's two lines; 104-10's
# three one-liners are read from 5.18.104's rehearsal, as R5's 105-10c' read them). Evolution and uCRM asked only as each may
# be; no planted name (NM106F: the chain's names and the RH106SECRET- marker), no fixture number (FORMS106: every number of the
# chain's, 105's and 106's ranges in its four written forms and its last nine digits) and no LK key in any terminal, log,
# sheet, state file, master pass or request record of 106's runs, 105's (106-pre's among them) or 104's; 106's own numbers
# and marker nowhere in the plugin's data folders or the records but the book; every fixture's leftover gone (A9);
# anchor-missing, wait104.fail and bad-runs against their baselines — 105's span (R5's 105-10i, cut with 105-10) and 106's
# own. Not swept, by design: $R106/setcfg.txt (set_config's own output, 106-infra: a planted setting's value may reach it),
# the case folders' backup-* (raw copies of the book) and the stand-in docker's exec records. Every file this section plants is
# a CONTROL of a reading (zz106ctl, zz-control, key-control, .zz106ctl.sh), said so where it is planted, removed at once, and
# asserted gone in 106-10f. No number of 106's range is planted or newly used here.
# The case's preconditions — the state 106-7i leaves by design-v2 (§C 106-7i, A10's known limit): 5.18.106 serving after the
# last --redeploy; followup_auto_send off (V1 found it off and wrote nothing: AS_RB1=off); no claim switch; followup_enabled
# on, the limit 336 h; the latest rollback record V1's, so as_back_on would offer nothing (rblast: off, nothing:not-written).
dpre106 "106-10a0" "$P106" off - "on:${LIM106:-unset}" "off:nothing:not-written"
# 106-10a — Evolution (R5's 105-10a): no run of 5.18.106's script or 5.18.105's called it, every run summed — 106's own and
# 105's, 106-pre's redeploy and --after-only among them; the cron stand-in ran its worker during none of them (a run is
# tagged cron only when cron104.runs grew during it); every request since 105 began on the stand-in's own base path; outside
# every run, since 106-pre (EVO0_106) no fewer than the sends the stand-in refused for J1's numbers (A6: 15 in J1's five
# passes, more if 106-6's sender pass met them), and in 105's sections and 106-infra no fewer than 105's drives' own counted
EVP106="${EVO_URL:-}"; EVP106="/${EVP106#http://*/}"
EVQ106="${EVF106:-}"; EVQ106="/${EVQ106#http://*/}"   # Part 5's file-only base path (106-6b2, 6b7, 7d3, 7f4: set_evolution's status asks it) (review r2 F4)
EVA106=$(( $(evo_calls) - EVO0_106 )); EVA5_106=$(( EVO0_106 - EVO0_105 )); RFJ106=$(( $(refused106) - $(n103 "${RF4_106:-}") ))
EVN106="$(cat "$R106"/evo/run-*.n "$R105"/evo/run-*.n 2>/dev/null | awk '{s += $1} $2 == "cron" {c++} END {printf "%d|%d\n", s, c}')"
check "$EVN106|$(tail -n +"$(( EVO0_105 + 1 ))" "$SB/evo/requests.log" 2>/dev/null | grep -cvE "^(GET|POST) ($EVP106|$EVQ106)/")|$( [ -n "${RF4_106:-}" ] && [ "$RFJ106" -ge 15 ] && [ "$EVA106" -ge "$RFJ106" ] && echo ge)|$( [ -n "${EVODRV105:-}" ] && [ "$EVA5_106" -ge "$(n103 "${EVODRV105:-}")" ] && echo ge)" "0|0|0|ge|ge" \
  "106-10a Evolution: no run of $V106's script or $V105's called it ($(ls "$R106"/evo/run-*.n "$R105"/evo/run-*.n 2>/dev/null | wc -l | tr -d ' ') runs read, every one summed — 106-pre's among $V105's); the cron stand-in ran its worker during none of them; the stand-in asked only on its own base path ($EVP106), or on the file-only path this rehearsal planted ($EVQ106), since $V105's sections began; every request outside every run — $EVA106 since 106-pre, no fewer than the $RFJ106 sends it refused for J1's numbers (15 or more), and $EVA5_106 in $V105's sections and 106-infra, no fewer than $V105's drives' ${EVODRV105:-?}"
# 106-10b — uCRM (R5's 105-10b): every run's request record names GETs only (uo106: u99other prints the requests that are not
# a GET, or -), no token record finds a copy left at the next call (tl106: tok99), over 106's runs, 105's and 104's alike;
# nothing of any of the three left in /tmp. Each reading's CONTROL a planted record in this section's own folder, removed
SCN106="$R106/scan106"; mkdir -p "$SCN106"; chmod 700 "$SCN106"
UO106="$(uo106 "$R106"/ucrm/run-*.req "$R105"/ucrm/run-*.req "$R104"/ucrm/run-*.req)"; TK106="$(tl106 "$R106"/tok/run-*.tok "$R105"/tok/run-*.tok "$R104"/tok/run-*.tok)"
printf 'GET /organizations app-key\nPOST /clients app-key\n' > "$SCN106/zz-control.req"; printf '0 x\n1 remote\n1 y\n' > "$SCN106/zz-control.tok"
check "$UO106|$TK106|$(ls -d /tmp/dnb-$V106-* /tmp/dnb-$V105-* /tmp/dnb-$V104-* /tmp/dnb-report-* 2>/dev/null | wc -l | tr -d ' ')|$(for d106 in "$R106" "$R105" "$R104"; do ls "$d106"/ucrm/run-*.req 2>/dev/null | grep -c . | awk '{printf "%s", ($1 > 0) ? "r" : "-"}'; ls "$d106"/tok/run-*.tok 2>/dev/null | grep -c . | awk '{printf "%s", ($1 > 0) ? "t" : "-"}'; done)|$(uo106 "$SCN106/zz-control.req")$(tl106 "$SCN106/zz-control.tok")" "0|0|0|rtrtrt|11" \
  "106-10b uCRM asked GETs only in every run of $V106, $V105 and $V104 (106's sections', 105's — 106-pre's among them — and 104's: R5's 105-10b, cut, judged again with 106's); no token copy left at the next call by any of them; nothing of any of them left in /tmp; controls: a planted POST and a planted token copy left, each found"
rm -f "$SCN106/zz-control.req" "$SCN106/zz-control.tok"
# 106-10c — 106's own records (R5's 105-10c, over sweep106, 106-infra's list): every terminal, mutant terminal, sheet, log,
# stream, state and rollback record, row digest and case file (backup-* aside) of 106's runs; and besides them what else
# 106's runs wrote (sweepx106): its master passes' output (master106, sender106: master-106m-*, master-106s-*), the tool's
# output while a deploy waited (csb1run106) and its uCRM request records. Scanned with both pattern files; the list read (run
# terminals and master passes among it); a CONTROL file holding one of each found by both scans, then planted through the
# list as a run terminal and as a master pass, found again, and removed
sweepx106() { ls -1 "$R104"/logs/master-106m-*.out "$R104"/logs/master-106s-*.out "$R106"/logs/act-*.txt "$R106"/ucrm/run-*.req 2>/dev/null; }   # what else 106's runs wrote
sweepall106() { { sweep106; sweepx106; } | sort -u; }
printf 'RH106SECRET-control %s\n' "$(grep -m1 -F '7001060' "$FORMS106")" > "$SCN106/control.txt"; chmod 600 "$SCN106/control.txt"
NHS106="$(sweepall106 | xargs -r grep -lF -f "$NM106F" 2>/dev/null | wc -l | tr -d ' ')"; FHS106="$(sweepall106 | xargs -r grep -lF -f "$FORMS106" 2>/dev/null | wc -l | tr -d ' ')"
SWN106="$(sweepall106 | grep -c .)"; SWR106="$(ls "$R106"/run-*.txt 2>/dev/null | grep -c .)"; SWM106="$(sweepx106 | grep -c '/master-106[ms]-')"
cp "$SCN106/control.txt" "$R106/run-zz106ctl.txt"; cp "$SCN106/control.txt" "$R104/logs/master-106m-zz106ctl.out"
SWC106="$(for p106 in run-zz106ctl.txt master-106m-zz106ctl.out; do printf '%s%s ' "$(sweepall106 | grep -F "/$p106" | xargs -r grep -lF -f "$NM106F" 2>/dev/null | wc -l | tr -d ' ')" "$(sweepall106 | grep -F "/$p106" | xargs -r grep -lF -f "$FORMS106" 2>/dev/null | wc -l | tr -d ' ')"; done | sed 's/ $//')"
rm -f "$R106/run-zz106ctl.txt" "$R104/logs/master-106m-zz106ctl.out"
check "$NHS106|$FHS106|$(grep -lF -f "$NM106F" "$SCN106/control.txt" | wc -l | tr -d ' ')$(grep -lF -f "$FORMS106" "$SCN106/control.txt" | wc -l | tr -d ' ')|$( [ "$SWR106" -gt 0 ] && [ "$SWM106" -gt 0 ] && echo listed)|$SWC106" "0|0|11|listed|11 11" \
  "106-10c no planted name (the chain's names, the RH106SECRET- marker) and no fixture number (the chain's, $V105's and $V106's, each in its four written forms and its last nine digits) in any of $V106's terminals, logs, sheets, streams, state and rollback records, row digests, case files (their backups aside), master passes, the tool's output beside a held deploy or uCRM request records ($SWN106 files: $SWR106 run terminals, $SWM106 master passes); the control file holding one of each found by both scans, and again through the list as a run terminal and as a master pass, then removed"
# 106-10c' — 5.18.105's records by 105-10's own list (sweep105, read in 106-infra d): 105's sections' terminals, logs, sheets,
# streams and state files, and 106-pre's (5.18.105 deployed again, its --after-only); the CONTROL planted as a run terminal of
# 105's, found by both scans through that list, then removed
NH5_106="$(sweep105 | xargs -r grep -lF -f "$NM106F" 2>/dev/null | wc -l | tr -d ' ')"; FH5_106="$(sweep105 | xargs -r grep -lF -f "$FORMS106" 2>/dev/null | wc -l | tr -d ' ')"
SWN5_106="$(sweep105 | grep -c .)"
cp "$SCN106/control.txt" "$R105/run-zz106ctl.txt"
SWC5_106="$(sweep105 | grep -F '/run-zz106ctl.txt' | xargs -r grep -lF -f "$NM106F" 2>/dev/null | wc -l | tr -d ' ')$(sweep105 | grep -F '/run-zz106ctl.txt' | xargs -r grep -lF -f "$FORMS106" 2>/dev/null | wc -l | tr -d ' ')"
rm -f "$R105/run-zz106ctl.txt"
check "$NH5_106|$FH5_106|$( [ "$SWN5_106" -gt 0 ] && echo listed)|$SWC5_106" "0|0|listed|11" \
  "106-10c' …nor in any of $V105's records by 105-10's own list (sweep105): its terminals, logs, sheets, streams and state files — 105's sections' and 106-pre's ($SWN5_106 files); the control file planted as one of its run terminals found by both scans through that list, then removed"
# 106-10c'' — 5.18.104's records by 104-10's own lists, which 106's runs write too (sf104c's sf.txt, the wrappers' logs of the
# commands run as printed, 104's and the quarantine's terminals): the three one-line definitions read from 5.18.104's
# rehearsal — each the start of exactly one line, after 104-10's heading, ending its body — and sourced unchanged (as R5's
# 105-10c' read them; a miss recorded in anchor-missing, and the lists then read nothing, which fails here); each list read;
# a CONTROL file planted where each looks, found by both scans through it, then removed
L104F_106="$(mktemp)"
python3 -I - "$H104_HARNESS105" "$L104F_106" <<'PY' || echo "rh106:lists104" >> "$SB/anchor-missing"
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
. "$L104F_106"; rm -f "$L104F_106"
sweep104_106() { { sweep_files104; sheets104; ls -1 "$OUT104"/cash-*.txt 2>/dev/null; reqs104; } | sort -u; }
NH4_106="$(sweep104_106 | xargs -r grep -lF -f "$NM106F" 2>/dev/null | wc -l | tr -d ' ')"; FH4_106="$(sweep104_106 | xargs -r grep -lF -f "$FORMS106" 2>/dev/null | wc -l | tr -d ' ')"
NL4_106="$(for l106 in sweep_files104 sheets104 reqs104; do "$l106" 2>/dev/null | grep -c . | awk '{printf "%s", ($1 > 0) ? "y" : "n"}'; done)"
mkdir -p "$R104/sheets" "$R104/ucrm"
for f106 in "$R104/run-zz106ctl.txt" "$R104/sheets/cash-zz106ctl.txt" "$R104/ucrm/run-zz106ctl.req"; do cp "$SCN106/control.txt" "$f106"; done
CL4_106="$(for l106 in sweep_files104 sheets104 reqs104; do printf '%s%s ' "$("$l106" 2>/dev/null | grep -F 'zz106ctl' | xargs -r grep -lF -f "$NM106F" 2>/dev/null | wc -l | tr -d ' ')" "$("$l106" 2>/dev/null | grep -F 'zz106ctl' | xargs -r grep -lF -f "$FORMS106" 2>/dev/null | wc -l | tr -d ' ')"; done | sed 's/ $//')"
rm -f "$R104/run-zz106ctl.txt" "$R104/sheets/cash-zz106ctl.txt" "$R104/ucrm/run-zz106ctl.req" "$SCN106/control.txt"
check "$NH4_106|$FH4_106|$NL4_106|$CL4_106" "0|0|yyy|11 11 11" \
  "106-10c'' …nor in any of $V104's records, which 106's runs write too: its runs' terminals and logs, sf.txt (every tool run of 106's), its state, rollback-state and row files, the wrappers' logs (every command run as printed), its sheets and its request records ($(sweep104_106 | grep -c .) files, by 104-10's own lists, each list read); the control file planted where each list looks found by both scans through it, then removed"
# 106-10d — the LK key files 5.18.106 wrote (R5's 105-10d): its run folder's and those tidy106 moved into the case folders,
# each mode 0600 and read; no key's value in any file the three sweeps above read; the CONTROL a copy of the keys, found
KL106="$( { [ -e "$LKF106" ] && echo "$LKF106"; find "$R106/case" -type f -name "lk-key-$V106" 2>/dev/null; } | sort -u)"
: > "$SCN106/key.pat"; chmod 600 "$SCN106/key.pat"; SK106=""
for f106 in $KL106; do SK106="$SK106 $(q105 secret "$f106" "$SCN106/key1.pat" | tr ' ' '/')"; [ -f "$SCN106/key1.pat" ] && cat "$SCN106/key1.pat" >> "$SCN106/key.pat"; rm -f "$SCN106/key1.pat"; done
SK106="${SK106# }"
KH106="$( { sweepall106; sweep105; sweep104_106; } 2>/dev/null | sort -u | xargs -r grep -lF -f "$SCN106/key.pat" 2>/dev/null | wc -l | tr -d ' ')"
cp "$SCN106/key.pat" "$SCN106/key-control.txt"
check "$( [ "$(grep -c . <<<"$KL106")" -ge 1 ] && echo some)|$(tr ' ' '\n' <<<"$SK106" | grep -vcx 'mode=600/key=read')|$KH106|$(grep -lF -f "$SCN106/key.pat" "$SCN106/key-control.txt" 2>/dev/null | wc -l | tr -d ' ')" "some|0|0|1" \
  "106-10d every LK key file $V106 wrote — its run folder's and the case folders' ($(grep -c . <<<"$KL106") file(s), $(sort -u "$SCN106/key.pat" | grep -c .) key value(s)) — mode 0600 and read; no key's value in any terminal, log, sheet, state file, master pass or request record of 106's, 105's or 104's; the control (a copy of the keys) found"
rm -f "$SCN106/key.pat" "$SCN106/key-control.txt"
# 106-10e — 106's own numbers and marker in the plugin's data folders and the records: the reading of 106-infra e (the range
# in any written form, not inside a longer run of digits, or its spaced national form), which found none there before
# 106-pre, and the RH106SECRET- marker — in no file but the book (plugin.sqlite3 and its -wal and -shm), where J1–J3 and the
# re-plants put both: a fixture left in a settings file (A9), or a log line carrying either, is found here. The control: the
# book, its write-ahead log folded in, holds each
sqx "PRAGMA wal_checkpoint(TRUNCATE)" >/dev/null 2>&1
ND1_106="$( { grep -rlaE --exclude='plugin.sqlite3*' "$RX106" "$DATA" "$PD/data" "$RECS"; grep -rlaF --exclude='plugin.sqlite3*' 'RH106SECRET-' "$DATA" "$PD/data" "$RECS"; } 2>/dev/null | sort -u)"
check "$(grep -c . <<<"$ND1_106")|$( [ "$(n103 "$(grep -caE "$RX106" "$DATA/plugin.sqlite3" 2>/dev/null)")" -ge 1 ] && echo numbers)|$( [ "$(n103 "$(grep -caF 'RH106SECRET-' "$DATA/plugin.sqlite3" 2>/dev/null)")" -ge 1 ] && echo marker)" "0|numbers|marker" \
  "106-10e $V106's own numbers ($PR106<NN>, in any written form: 106-infra e's reading) and its RH106SECRET- marker in no file of the plugin's data folders or the records — the book aside ($(grep -c . <<<"$ND1_106") file(s) found$( [ -n "$ND1_106" ] && printf ': %s' "$(xargs -r -n1 basename <<<"$ND1_106" | paste -sd, -)")); the control: the book holds both, where J1–J3 and the re-plants put them"
# 106-10f — the fixtures' leftovers (A9), each asserted gone: the uj hook's trigger (A8); the clone's index lock and the two V1
# triggers (A7); the stand-in's refusal list (A6, kept for 106-6's sender pass: p3-open 9) — removed here, the stand-in then
# answering a send for J1's first number with a key.id and refusing nothing more; the weakened copies (every $REPO/scripts/
# .NAME.sh newer than this release's script in the clone; the CONTROL one planted, counted and removed); the keep folders
# (keep106's and the settings-file fixtures': no fixture's kept copy left in one — fx_file_back takes its own — then removed);
# no [master] line left in plugin.log (106-5b's plants cut back); this section's own controls gone; neither RH106_TOUCH nor
# RH106_RANGE in the rehearsal's own environment (each given to its child alone: A1's shim stays 5.18.106's runs' own, the
# range seed106's)
KD106="$R106/kept"
KN106="$(find "$KD106" -mindepth 1 -maxdepth 1 -type d 2>/dev/null | grep -c .)"; KP106="$(find "$KD106" -mindepth 2 -maxdepth 2 -type f -name path 2>/dev/null | grep -c .)"
KF106="$(for d106 in "$KD106"/*/; do [ -d "$d106" ] && [ ! -f "$d106/path" ] && find "$d106" -type f; done 2>/dev/null | grep -c .)"
rm -rf "${KD106:?}"
WC0_106="$(find "$REPO/scripts" -maxdepth 1 -name '.*.sh' -newer "$DEPLOY106" 2>/dev/null | grep -c .)"
cp "$DEPLOY106" "$REPO/scripts/.zz106ctl.sh"; WC1_106="$(find "$REPO/scripts" -maxdepth 1 -name '.*.sh' -newer "$DEPLOY106" 2>/dev/null | grep -c .)"; rm -f "$REPO/scripts/.zz106ctl.sh"
RLB106="$(if [ -e "$SB/evo/rh106-refuse.list" ]; then grep -c . "$SB/evo/rh106-refuse.list"; else echo absent; fi)"
RLA106="$(refuse106)"; RFB106="$(refused106)"; RQ106="$(evopost106 sendText "${J1A106:-}")"
check "$( [ -e "$SB/.ujfail106" ] && echo armed || echo none)|$(lock106)|$(ls -A "$SB/web" 2>/dev/null | grep -c '^\.redirect106')|$RLA106:$( [ -e "$SB/evo/rh106-refuse.list" ] && echo listed || echo unlisted):$RQ106:$(( $(refused106) - RFB106 ))|$WC0_106:$WC1_106:$(find "$REPO/scripts" -maxdepth 1 -name '.*.sh' -newer "$DEPLOY106" 2>/dev/null | grep -c .)|$( [ "$KN106" -ge 1 ] && echo kept):$KF106:$( [ -e "$KD106" ] && echo left || echo gone)|$(n103 "$(grep -c '\] \[master\] ' "${PLOG106:-$PD/data/plugin.log}" 2>/dev/null)")|$(find "$R104" "$R105" "$R106" "$REPO/scripts" -name '*zz106ctl*' 2>/dev/null | grep -c .):$(ls -A "$SCN106" 2>/dev/null | grep -c .)|$(printenv RH106_TOUCH >/dev/null && echo touch)$(printenv RH106_RANGE >/dev/null && echo range)" \
      "none|absent|0|0:unlisted:id:0|0:1:0|kept:0:gone|0|0:0|" \
  "106-10f the fixtures' leftovers gone (A9): no uj trigger; no index lock in the clone and neither V1 trigger in the stand-in's folder; the refusal list (it listed $RLB106) removed — a send for J1's first number answered with a key.id, nothing more refused; no weakened copy of the script left in the clone (the control: one planted, counted, removed); the keep folders ($KN106, $KP106 of them keep106's) holding no settings-file fixture's kept copy, then removed; plugin.log without a [master] line; none of this section's controls left; the rehearsal's own environment carrying neither RH106_TOUCH nor RH106_RANGE"
# 106-10g — 5.18.105's sections' failure files (R5's 105-10i, cut with 105-10): anchor-missing and wait104.fail from 105's
# baselines (AM0_105, WF0_105, R5's) to 106's (AM0_106, WF0_106, 106-infra's), and no run of 105's runner — 105's sections'
# and 106-pre's — given an unknown option
AMS5_106=$(( AM0_106 - $(n103 "${AM0_105:-}") )); WFS5_106=$(( WF0_106 - $(n103 "${WF0_105:-}") ))
check "$( [ -n "${AM0_105:-}" ] && [ -n "${WF0_105:-}" ] && echo set || echo unset)|$AMS5_106|$(n103 "$(grep -c '^run105-' "$SB/bad-runs" 2>/dev/null)")|$WFS5_106|$( [ "$AMS5_106" -gt 0 ] && tail -n +$(( $(n103 "${AM0_105:-}") + 1 )) "$SB/anchor-missing" 2>/dev/null | head -n "$AMS5_106" | paste -sd, -)" "set|0|0|0|" \
  "106-10g $V105's sections (R5's 105-10i, cut with 105-10, judged here): every asserted edit and anchor of theirs found, no run of $V105's runner given an unknown option (106-pre's among them), no bounded wait timed out"
# 106-10h — 106's own: anchor-missing, wait104.fail and bad-runs against 106-infra's baselines; and the control of the controls
# over every anchor 106's weakened copies set (ancc106: each the script's own text, once, its replacement different)
AM1_106="$(grep -c . "$SB/anchor-missing" 2>/dev/null)"; AM1_106="${AM1_106:-0}"; WF1_106="$(grep -c . "$R104/wait104.fail" 2>/dev/null)"; WF1_106="${WF1_106:-0}"
BR1_106="$(grep -c '^run106-' "$SB/bad-runs" 2>/dev/null)"; AC106="$(ancc106)"
check "$(( AM1_106 - AM0_106 ))|$(( $(n103 "$BR1_106") - BR0_106 ))|$(( WF1_106 - WF0_106 ))|$(tail -n +$(( AM0_106 + 1 )) "$SB/anchor-missing" 2>/dev/null | paste -sd, -)|$(cut -d'|' -f1,3 <<<"$AC106")" "0|0|0||0|-" \
  "106-10h $V106's own sections: every asserted edit, anchor and plant of theirs found, no run of 106's runners given an unknown option, no bounded wait timed out; every anchor its weakened copies set ($(cut -d'|' -f2 <<<"$AC106")) still the script's own text, found in it once, its replacement different"
# 106-10i — the end state (R5's 105-10j): what 106-7 left serving, every file 5.18.106 changes from 5.18.105 as that commit has
# it (installed106: a file the commit lacks is not asked); this checkout's status exactly as the rehearsal found it
LV106="$(live)"; LVF106="$(git -C "$REPO" rev-parse -q --verify "$LV106^{commit}" 2>/dev/null)"
check "$(case "$LV106" in "$P106"|"$B106") echo known ;; *) echo "live:$LV106" ;; esac)|$( [ -n "$LVF106" ] && installed106 "$LVF106" || echo unread)|$(git -C "$R" status --porcelain 2>/dev/null | sha256sum | cut -c1-16)" "known|0|$ST0_106" \
  "106-10i the end state: $LV106 ($(inst_ver)) serving after 106-7, every file $V106 changes from $BV106 as that commit has it; this checkout's status exactly as the rehearsal found it"

echo
echo "RESULT: $PASS passed, $FAILN failed — of them $FAIL_PRE106 before $V106's own sections (the earlier rehearsals' chain)"
[ "$FAILN" = 0 ]
