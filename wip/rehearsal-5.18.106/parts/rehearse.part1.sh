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
