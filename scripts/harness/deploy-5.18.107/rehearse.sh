#!/usr/bin/env bash
#
# Rehearse scripts/deploy-5.18.107.sh — on Uganda the connection quotation built in code, quoted customers, the numbers the
# assistant never answers, and the week's fixes (root docs/76), deployed over 5.18.106 as production runs it (steps 1–6 on,
# the chase in claim mode). Its new gates and readings: migration 092 and its ledger (MG-G1 and QF-G2's trial with neg092 and
# NA-G1 before the copy; MG-CA1, MG-CA2 and NA-CA1 after it), the three switches §K keeps off at a deploy (NS-G1 at stage A,
# NS-B1 once DEPLOY is typed), stage A's notes, master's own record after the copy (ME-CA1, ME-CA2), and the rollback that
# writes nothing (rb_pre107's readings, step 5's K and Q, the put-back paragraph and its tools reading, V1). The script under
# rehearsal is derived from 5.18.106's by asserted edits (wip/derive-5.18.107/), pinned to the release commit; every pin is
# read from it here and every expectation computed from Git or from the release's own code — never a commit, a count of files
# or a hash written.
#
# The design is wip/rehearsal-5.18.107/: design-v2.md and design-v3.md (two reviews, 63 findings, all taken), refining root
# docs/76 §T.11; the maps beside them. Where §T.11 and the script differ the script is right and v2 §A11–A14 and §C say how
# each is read; §C's differences are the owner's to decide.
#
# The sandbox is the previous release's rehearsal's, built by its own code: scripts/harness/deploy-5.18.106/rehearse.sh, read
# up to just before 106-10 (its sweeps) — the cut asserted — and run here as it is, which builds every earlier sandbox the same
# way (5.18.105's cut before 105-10, and so down to the quarantine's, the root of the chain). When this file's own sections
# begin, 106-7i's --redeploy has left 5.18.106 serving, steps 1–5 on and step 6 off, followup_enabled on, followup_auto_send
# off. 107-pre makes production's state: the chase on in claim mode, 5.18.106's --after-only PASSED, the quarantine's --check
# PASSED (v2 A15).
#
# Two sandbox mechanics every 107 section leans on (v2 A1, A4; v3). The stand-in web server is relaunched on the rehearsal's
# clock, so 092's applied_at and the migration.log stamps a boot writes are on the same clock as COPY_AT and QF_COPY_UNIX —
# the kernel's time never enters a comparison. And the store is booted only where a case means it to be: V1's first request
# after a copy, or a named boot (master106, a stand-in request, a hook), each asserted.
#
# The development runner: the block that sources the chain — from the one line that begins H107_PREFIX="$(mktemp)" to the
# one line that is exactly rm -f "$H107_PREFIX" — can be skipped by a runner that restores the chain's shell from a snapshot
# (devsnap107, taken over 5.18.106's rehearsal cut the same way); everything after it works in either shell, re-applies W.8
# where a restored shell lacks it (v2 A16) and copies the committed script and both wrappers into the restored clone itself.
#
# Everything runs in a sandbox; it never touches this checkout, the server or the network, and refuses to run where the server
# could be. Everything seeded is fictitious: every text an RH107SECRET- value, every phone a number of this rehearsal's own
# range (+2567001070NN, proved free here), beside the chain's; the one exception is NA-CA1's sentinel, the script's own
# constant, swept and removed (v2 A18).
#
#   bash scripts/harness/deploy-5.18.107/rehearse.sh            REHEARSE_KEEP=<dir> keeps every run's full output
set -u
# ── 107-0 the guards ──────────────────────────────────────────────────────────────────────────────────────────────────────
for f in /data/ucrm /opt/dishnet /var/run/docker.sock /run/docker.sock; do
  [ -e "$f" ] && { echo "refusing: $f exists — this looks like the server, and this rehearsal must never run there"; exit 2; }
done
H107_DIR="$(cd "$(dirname "$0")" && pwd)"
H107_ROOT="$(cd "$H107_DIR/../../.." && pwd)"
case "$H107_DIR/:$(pwd -P)/" in */data/ucrm/*|*/opt/dishnet/*|*/root/dnb-*) echo "refusing: a server path ($H107_DIR, $(pwd -P))"; exit 2 ;; esac
F107=scripts/deploy-5.18.107.sh; F106_107=scripts/deploy-5.18.106.sh
[ -f "$H107_ROOT/$F107" ] || { echo "refusing: the checkout has no $F107"; exit 2; }
sv107e() { sed -nE "s/^$2=\"([^\"]*)\".*/\1/p" "$1" 2>/dev/null | head -1; }   # sv107e FILE NAME — a quoted value the script states at a line's start
P107_EARLY="$(sv107e "$H107_ROOT/$F107" EXPECTED_PLUGIN_COMMIT)"; P107F_EARLY="$(sv107e "$H107_ROOT/$F107" EXPECTED_PLUGIN_COMMIT_FULL)"
T1_EARLY107="$(sv107e "$H107_ROOT/$F107" T1_SIGNATURE)"
case "$P107_EARLY" in __*|'') echo "refusing: $F107 is not pinned to a release commit (${P107_EARLY:-none})"; exit 2;; esac
case "$P107F_EARLY" in "$P107_EARLY"*) [ "${#P107F_EARLY}" = "40" ] || { echo "refusing: $F107 does not pin its release commit in full"; exit 2; } ;;
  *) echo "refusing: $F107's full pin does not begin with its short one"; exit 2 ;; esac
case "$T1_EARLY107" in [1-9]*' passed, 0 failed rc=0') ;; *) echo "refusing: $F107 does not pin T1's count (T1_SIGNATURE=${T1_EARLY107:-none})"; exit 2;; esac
# The derivation's measured values (root docs/76 §T.15, M0): the driver refuses to write while any is expected, so a script
# committed from it carries none; read here from the derivation's own table so a rehearsal never runs over an interim build.
MSR107_EARLY="$(python3 -I - "$H107_ROOT/wip/derive-5.18.107/partB.py" <<'PY' 2>/dev/null
import ast, sys
src = open(sys.argv[1], encoding='utf-8').read()
tree = ast.parse(src)
for node in tree.body:
    if isinstance(node, ast.Assign) and any(getattr(t, 'id', '') == 'MEASURED' for t in node.targets):
        names = [k.value for k in node.value.keys]
        states = [v.elts[1].value for v in node.value.values]
        bad = [n for n, s in zip(names, states) if s != 'measured']
        print('measured=%d expected=%s' % (len(names) - len(bad), ','.join(bad) or '-'))
        break
PY
)"
case "$MSR107_EARLY" in "measured="[1-9]*" expected=-") ;; *) echo "refusing: the derivation's MEASURED table reads ${MSR107_EARLY:-unread} — an interim build is never rehearsed"; exit 2 ;; esac
# The two helpers beside this file (rh107q.py, its readers; rh107-seed.php, its plants through the release's own classes),
# each parsing; no bytecode cache beside them.
for f107 in rh107q.py rh107-seed.php; do [ -f "$H107_DIR/$f107" ] || { echo "refusing: this rehearsal's helper $f107 is not beside it"; exit 2; }; done
python3 -I -c 'import ast, sys; ast.parse(open(sys.argv[1]).read())' "$H107_DIR/rh107q.py" 2>/dev/null || { echo "refusing: rh107q.py does not parse"; exit 2; }
php -l "$H107_DIR/rh107-seed.php" >/dev/null 2>&1 || { echo "refusing: rh107-seed.php does not parse"; exit 2; }
[ ! -e "$H107_DIR/__pycache__" ] || { echo "refusing: a __pycache__ beside the helpers — remove it"; exit 2; }
# The static guard. 5.18.106's reader (rh106q.py verbs, 5.18.105's rules unchanged) reads 5.18.107's script as
# lk=1 qftool=0 wrapper=3 verbs=0: its three wrapper lines are the assignments of the three commands the script PRINTS for the
# operator (BD_OFF_CMD, BD_DRAIN_CMD, W1_ON_CMD; S:7330–7332), never runs. This rehearsal's reader (rh107q.py verbs107) reads
# the same counts and, beyond them, that the wrapper lines are exactly those three assignments and that each of the three
# variables is used only in a line that prints it (echo or printf); its control a planted copy of each kind — a qf_tool verb,
# the wrapper run, the tool by path, and one of the three commands run (eval, or bare) — each of which must be refused.
VB107_EARLY="$(python3 -I "$H107_DIR/rh107q.py" verbs107 "$H107_ROOT/$F107" 2>/dev/null)"
[ "$VB107_EARLY" = "lk=1 qftool=0 wrapper=3 verbs=0 cmds=BD_OFF_CMD,BD_DRAIN_CMD,W1_ON_CMD printed-only=3" ] \
  || { echo "refusing: $F107's static guard reads $VB107_EARLY (want lk=1 qftool=0 wrapper=3 verbs=0 cmds=BD_OFF_CMD,BD_DRAIN_CMD,W1_ON_CMD printed-only=3)"; exit 2; }
[ -f "$H107_DIR/../deploy-5.18.106/rh106q.py" ] || { echo "refusing: 5.18.106's rehearsal's reader is not beside this one"; exit 2; }
VBX107_EARLY="$(python3 -I "$H107_DIR/../deploy-5.18.106/rh106q.py" verbs "$H107_ROOT/$F107" 2>/dev/null)"
[ "$VBX107_EARLY" = "lk=1 qftool=0 wrapper=3 verbs=0" ] || { echo "refusing: 5.18.106's reader reads $F107's static guard as $VBX107_EARLY (want lk=1 qftool=0 wrapper=3 verbs=0)"; exit 2; }
VBD107="$(mktemp -d)"
VBC107="$(python3 -I - "$H107_ROOT/$F107" "$VBD107" "$H107_DIR/rh107q.py" <<'PY'
import subprocess, sys
src, d, q = sys.argv[1:4]
s = open(src, encoding='utf-8').read()
tail = '\nmain "$@"; exit $?\n'
assert s.count(tail) == 1 and s.endswith(tail), 'tail'
want = 'lk=1 qftool=0 wrapper=3 verbs=0 cmds=BD_OFF_CMD,BD_DRAIN_CMD,W1_ON_CMD printed-only=3'
plants = {'qftool': '  qf_tool "$sub" off all --rollback >/dev/null 2>&1\n',
          'wrapper': '  bash "$REPO/$QF_WRAPPER" off all --rollback\n',
          'verbs': '  docker exec ucrm php "$IN_CONTAINER/$QF_TOOL" off all --rollback\n',
          'evalcmd': '  eval "$BD_OFF_CMD"\n',
          'barecmd': '  $W1_ON_CMD\n',
          'shcmd': '  sh -c "$BD_DRAIN_CMD"\n'}
out = []
for k, line in plants.items():
    t = s[:-len(tail)] + '\n' + line + tail.lstrip('\n')
    assert t.count(line) == 1 and t.endswith(tail) and len(t) == len(s) + len(line), k
    f = '%s/%s.sh' % (d, k)
    open(f, 'w', encoding='utf-8').write(t)
    r = subprocess.run([sys.executable, '-I', q, 'verbs107', f], capture_output=True, text=True).stdout.strip()
    out.append('%s:%s' % (k, 'refused' if r != want else 'passed'))
print(','.join(out))
PY
)"; rm -rf "${VBD107:?}"
[ "$VBC107" = "qftool:refused,wrapper:refused,verbs:refused,evalcmd:refused,barecmd:refused,shcmd:refused" ] || { echo "refusing: the static guard's control did not hold ($VBC107)"; exit 2; }
# 5.18.106's script present and pinned to the commit 5.18.107 names as its baseline, its version the baseline's (each read, never written)
[ -f "$H107_ROOT/$F106_107" ] || { echo "refusing: the checkout has no $F106_107"; exit 2; }
B107_EARLY="$(sv107e "$H107_ROOT/$F107" BASELINE_COMMIT)"; B107F_EARLY="$(sv107e "$H107_ROOT/$F107" BASELINE_COMMIT_FULL)"
[ -n "$B107_EARLY" ] && [ "$(sv107e "$H107_ROOT/$F106_107" EXPECTED_PLUGIN_COMMIT)" = "$B107_EARLY" ] && [ "$(sv107e "$H107_ROOT/$F106_107" EXPECTED_PLUGIN_COMMIT_FULL)" = "$B107F_EARLY" ] \
  && [ "$(sv107e "$H107_ROOT/$F107" BASELINE_VERSION)" = "$(sv107e "$H107_ROOT/$F106_107" EXPECTED_VERSION)" ] \
  || { echo "refusing: $F106_107 is not pinned to the baseline $F107 names (${B107_EARLY:-none})"; exit 2; }
# Both wrappers the committed ones: tracked, and the working copy HEAD's.
for f107 in scripts/sales-followup.sh scripts/sales-followup-report.sh; do
  git -C "$H107_ROOT" ls-files --error-unmatch "$f107" >/dev/null 2>&1 || { echo "refusing: $f107 is not committed — commit it, then rehearse"; exit 2; }
  git -C "$H107_ROOT" diff --quiet HEAD -- "$f107" || { echo "refusing: $f107 differs from the committed one — commit it, then rehearse"; exit 2; }
done
# W.8 (cc6a714) in the checkout's root rehearsal: the record run builds its sandbox from it, so the fix must be there (v2 A16)
W8ROOT107="$H107_DIR/../quarantine-stray-store/rehearse.sh"
# the generator's own line (root:475), exactly once, in its fixed form; the old form nowhere as a pre=( line (the old text
# occurs once elsewhere, in root's own control-on-the-control sed, which is not a pre=( line)
[ "$(grep -cE '^if \[ -n "\\\$user" \] && \[ -f "\$SB/\.server-owner" \]; then pre=\(setpriv .* --clear-groups -- env PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin\); fi$' "$W8ROOT107")" = "1" ] \
  && [ "$(grep -cE 'pre=\(setpriv .* --clear-groups --\); fi$' "$W8ROOT107")" = "0" ] \
  || { echo "refusing: the root rehearsal does not carry W.8 (cc6a714) — the stand-in docker's -u would run without the container's PATH"; exit 2; }

# ── 107-cut: 5.18.106's rehearsal, up to just before 106-10 ──────────────────────────────────────────────────────────────
H106_HARNESS107="$H107_DIR/../deploy-5.18.106/rehearse.sh"
[ -f "$H106_HARNESS107" ] || { echo "refusing: 5.18.106's rehearsal is not beside this one"; exit 2; }
H107_PREFIX="$(mktemp)"
python3 -I - "$H106_HARNESS107" "$H107_PREFIX" <<'PY' || { echo "refusing: the previous release's rehearsal could not be cut before its last section (its sweeps)"; rm -f "$H107_PREFIX"; exit 2; }
import sys
src, dst = sys.argv[1], sys.argv[2]
anchor = 'echo; echo "== 106-10. what the rehearsal left behind, over 104\'s, 105\'s and 106\'s records alike: the sweeps, the fixtures\' leftovers, the chain\'s failure files =="'
lines = open(src, encoding='utf-8').read().split('\n')
at = [i for i, l in enumerate(lines) if l == anchor]
assert len(at) == 1, len(at)
assert at[0] > 2000, at[0]
# the last section heading before the anchor is 106-7i's; the last code line before the anchor's box comment is 106-7z's
# RH-NOTE echo, once in the cut; the last check is 106-7z's, its label once in the cut
heads = [i for i in range(at[0]) if lines[i].startswith('echo; echo "== 106-')]
assert heads and lines[heads[-1]].startswith('echo; echo "== 106-7i. the known limit'), (lines[heads[-1]][:60] if heads else 'none')
body = lines[heads[-1]:at[0]]
code = [l for l in body if l.strip() and not l.lstrip().startswith('#')]
assert code[-1].startswith('echo "  note  RH-NOTE 106-6 and 106-7 hand 106-10: '), code[-1][:60]
assert sum(1 for l in lines[:at[0]] if l.startswith('echo "  note  RH-NOTE 106-6 and 106-7 hand 106-10: ')) == 1
cut = '\n'.join(lines[:at[0]])
assert cut.count('  "106-7z what 106-10 begins from: ') == 1
# the box comment between the RH-NOTE echo and the anchor: comment lines only
tail_i = max(i for i, l in enumerate(lines[:at[0]]) if l.startswith('echo "  note  RH-NOTE 106-6 and 106-7 hand 106-10: '))
assert all((not l.strip()) or l.lstrip().startswith('#') for l in lines[tail_i + 1:at[0]]), 'code between the last note and the anchor'
pre = cut + '\n'
# The one edit: 5.18.106's rehearsal finds its helpers beside itself ($0's folder); sourced from here, $0 is this file, so its
# one line naming that folder points at its own folder instead — asserted: the anchor once, the line after it H106_ROOT's,
# in its guards (at least 50 lines before it, and before the line naming 5.18.105's rehearsal, its cut), replaced once, the
# new line once and the old line nowhere.
old_dir = 'H106_DIR="$(cd "$(dirname "$0")" && pwd)"\n'
new_dir = 'H106_DIR="$(cd "$(dirname "$0")/../deploy-5.18.106" && pwd)"\n'
assert pre.count(old_dir) == 1, pre.count(old_dir)
i_old = pre.index(old_dir)
assert pre[i_old + len(old_dir):].startswith('H106_ROOT='), 'H106_ROOT does not follow'
assert pre[:i_old].count('\n') >= 50, pre[:i_old].count('\n')
assert pre.count('\nH105_HARNESS106=') == 1 and i_old < pre.index('\nH105_HARNESS106='), 'not in the guards'
pre2 = pre.replace(old_dir, new_dir, 1)
assert pre2.count(new_dir) == 1 and pre2.count(old_dir) == 0 and len(pre2) == len(pre) + len(new_dir) - len(old_dir)
for need in ('H105_HARNESS106=', 'run106() {', 'run106c() {', 'run_line106c() {', 'fresh106() {', 'tidy106() {', 'installed106() {',
             'left106() {', 'nogo106() {', 'checkgo106() {', 'weak106() {', 'anc106() {', 'ancc106() {', 'keep106() {', 'back106() {',
             'mark106() {', 'cut106() {', 'aft106() {', 'rbx106() {', 'qcheck106() {', 'dredeploy106() {', 'v1run106() {',
             'csb1run106() {', 'master106() {', 'sender106() {', 'seed106() {', 'seed106r() {', 'plant106() {', 'setcfg106() {',
             'setrc106() {', 'sget106() {', 'q106() {', 'kv106() {', 'cond106() {', 'dpre106() {', 'dstdrop106() {', 'cleanup106() {',
             'expect106() {', 'onall106() {', 'quiet106() {', 'went106() {', 'last106() {', 'rc106() {', 'sv106() {', 'sweep106() {',
             'nr106() {', 'tst106() {', 'lock106() {', 'unlock106() {', 'refuse106() {', 'backups106() {', 'states106() {',
             'keyf106() {', 'keysum106() {', 'DEPLOY106=', 'OUT106="$SB/out106"; mkdir -p "$OUT106"; STATE106=', 'R106=', 'H106=',
             'L1ON106=', 'L2ON106=', 'ONP106=', 'CM106=', 'ADDED106='):
    assert ('\n' + pre2).count('\n' + need) == 1, need   # each the start of exactly one line
open(dst, 'w', encoding='utf-8').write(pre2)
PY
# shellcheck disable=SC1090
. "$H107_PREFIX"
rm -f "$H107_PREFIX"
for f107 in run106 run106c run_line106c fresh106 tidy106 installed106 left106 nogo106 checkgo106 weak106 anc106 ancc106 keep106 back106 \
            mark106 cut106 aft106 rbx106 qcheck106 dredeploy106 v1run106 csb1run106 master106 sender106 seed106 seed106r plant106 \
            setcfg106 setrc106 sget106 q106 kv106 cond106 dpre106 dstdrop106 cleanup106 expect106 onall106 quiet106 went106 last106 \
            rc106 sv106 sweep106 nr106 tst106 lock106 unlock106 refuse106 refused106 evopost106 uo106 tl106 sweep105 spent99 backups106 states106 keyf106 keysum106 \
            run104 fresh104 weak104 live104 sf104 sf104c sfrc104 clk_set104 clk_to104 fnow104 kat104 kday104 kdow104 dsnap104 drest104 \
            kv104 q104 master104 sched104 ledger104 owner104 sv104 install_commit idle104 base_log onst104 dk104 pubget v1read102 \
            check has okline noteline failline fails printed live inst_ver data94 kept_state evo_calls vt_host qj n103 \
            run105 run105c fresh105 tidy105 weak105 anc105 aft105 rbx105 onall105 on105 lk105 lkmap105 counts105 digest105 q105 kv105 \
            ref105 jf105 sv105; do
  declare -F "$f107" >/dev/null || { echo "refusing: the rehearsals this one runs did not define $f107()"; exit 2; }
done
for v107 in SB REPO R P PD DATA VAULT RECS PLUGIN_BASE WEB_OK AFTER94 SCAN96 U99 PHPBIN IN_CONTAINER DATE_TS98 EVO_URL \
            CLKPRE104 CLKF104 R104 SALES104 REPORT104 \
            DEPLOY106 OUT106 STATE106 LKF106 R106 H106 P106 P106F V106 B106 B106F BV106 REL106 CM106 ADDED106 ONP106 L1ON106 L2ON106 \
            LIM106 PR106 FORMS106 NM106F EVO0_106 AM0_106 RF4_106 J1A106; do
  [ -n "${!v107:-}" ] || { echo "refusing: the rehearsals this one runs did not set $v107"; exit 2; }
done
FAIL_PRE107=$FAILN   # the earlier rehearsals' failures, before this release's own sections: the last line names them

# ══════════════════════════════════════════════════════════════════════════════════════
# 5.18.107's own rehearsal, on the sandbox built above.
# ══════════════════════════════════════════════════════════════════════════════════════
echo; echo "== 107-infra. 5.18.107's own: the script in the clone, its folders and runners, the stand-in web server on the clock, W.8, the hooks, its own numbers =="
DEPLOY107="$REPO/$F107"
AM0_107="$(grep -c . "$SB/anchor-missing" 2>/dev/null)"; AM0_107="${AM0_107:-0}"; WF0_107="$(grep -c . "$R104/wait104.fail" 2>/dev/null)"; WF0_107="${WF0_107:-0}"
BR0_107="$(grep -c '^run107-' "$SB/bad-runs" 2>/dev/null)"; BR0_107="${BR0_107:-0}"
ST0_107="$(git -C "$R" status --porcelain 2>/dev/null | sha256sum | cut -c1-16)"   # this checkout as the rehearsal found it
# The script, both wrappers and 5.18.106's script into the clone as committed (a development runner's restored clone included).
git -C "$R" ls-files --error-unmatch "$F107" >/dev/null 2>&1 || { echo "refusing: $F107 is not committed — commit it, then rehearse"; exit 2; }
git -C "$R" diff --quiet HEAD -- "$F107" || { echo "refusing: $F107 differs from the committed one — commit it, then rehearse"; exit 2; }
cp "$R/$F107" "$DEPLOY107"
for f107 in scripts/sales-followup.sh scripts/sales-followup-report.sh; do cp "$R/$f107" "$REPO/$f107"; done
cp "$R/$F106_107" "$REPO/$F106_107"
sv107() { sed -nE "s/^$2=\"([^\"]*)\".*/\1/p; t; s/^$2=([^ \";]+).*/\1/p" "${1:-$DEPLOY107}" | head -1; }   # sv107 FILE NAME — a value the script states
P107="$(sv107 "" EXPECTED_PLUGIN_COMMIT)"; P107F="$(sv107 "" EXPECTED_PLUGIN_COMMIT_FULL)"; V107="$(sv107 "" EXPECTED_VERSION)"
B107="$(sv107 "" BASELINE_COMMIT)"; B107F="$(sv107 "" BASELINE_COMMIT_FULL)"; BV107="$(sv107 "" BASELINE_VERSION)"
REL107="$(sv107 "" RELEASE_BRANCH)"
git -C "$REPO" fetch -q origin "refs/heads/$REL107:refs/remotes/origin/$REL107" 2>/dev/null || true
if ! git -C "$REPO" cat-file -e "$P107F^{commit}" 2>/dev/null; then
  # the release branch not pushed: the checkout's own branch that holds the pin (read from Git, never named here)
  for b107 in $(git -C "$R" for-each-ref --contains "$P107F" --format='%(refname:short)' refs/heads 2>/dev/null | head -3); do
    git -C "$REPO" fetch -q origin "refs/heads/$b107:refs/remotes/origin/$b107" 2>/dev/null && break
  done
fi
git -C "$REPO" cat-file -e "$P107F^{commit}" 2>/dev/null || { echo "refusing: the clone does not hold $V107's pin $P107F"; exit 2; }
# 5.18.107's baseline is the chain's P106 — never B106, which is 5.18.105 in R6's shell (v3 A9, review 2 TC1/FM2)
check "$P107|$( [ "$(git -C "$REPO" rev-parse "$P107^{commit}")" = "$P107F" ] && echo full)|$B107|$( [ "$B107F" = "$P106F" ] && echo base106)|$BV107|$( [ "$(git -C "$REPO" rev-parse "$P107F^")" = "$B107F" ] && echo on106)|$(inst_ver)" \
      "${P107F:0:${#P107}}|full|${P106F:0:${#B107}}|base106|$V106|on106|$V106" \
  "107-infra a the committed script pinned to $P107 (its full form the clone's), one commit on $B107 — 5.18.106's own pin, read from both scripts — $V107 after $BV107; $(inst_ver) serving (106-7i6's --redeploy)"
OUT107="$SB/out107"; mkdir -p "$OUT107"; STATE107="$OUT107/state-$V107.env"; LKF107="$OUT107/lk-key-$V107"
R107="$SB/r107"; mkdir -p "$R107/logs" "$R107/mutants" "$R107/sheets" "$R107/exec" "$R107/scan" "$R107/vault" "$R107/tok" "$R107/ucrm" "$R107/evo" "$R107/case" "$R107/rc" "$R107/kept" "$R107/pre" "$R107/boot" "$R107/snap"
H107="$SB/rh107"; mkdir -p "$H107/v" "$H107/ref"; cp "$H107_DIR/rh107q.py" "$H107_DIR/rh107-seed.php" "$H107/"; chmod 755 "$H107"; chmod 644 "$H107"/rh107*
chown 1000:1000 "$H107/v" "$R107/boot"; chmod 700 "$H107/v"
LOGW107() { printf '2>&1 | ( umask 077; exec tee %s/%s-%s.log )' "$OUT107" "$1" "$DATE_TS98"; }   # the script's LOGW, as it prints it
EXP_AFTER107="cd $REPO && bash $F107 --after-only $(LOGW107 after)"
EXP_ROLLBACK107="cd $REPO && bash $F107 --rollback $(LOGW107 rollback)"
EXP_REDEPLOY107="cd $REPO && bash $F107 --redeploy $(LOGW107 redeploy)"
q107() { python3 -I "$H107/rh107q.py" "$@"; }        # this rehearsal's own readers (rh107q.py)
kv107() { q105 kv "$1" "$2"; }                        # one key=value field of a line, by the chain's reader
# The pin's ladder, read by the RELEASE's own code from Git (a copy in this rehearsal's own folder), set against 5.18.106's:
# 5.18.107 changes the ladder (SalesFollowupLadder: W.1's key and its burst-drain step, root docs/76 §W.1), so its keys are
# read here, never assumed equal.
git -C "$REPO" archive "$P107F" "$P/lib" "$P/tools" | tar -xf - -C "$H107/ref"; chown -R 1000:1000 "$H107/ref"
LAD107="$(ref105 ladder "$H107/ref/$P")"
ALLCSV107="$(jf105 "$LAD107" all)"; SETCSV107="$(jf105 "$LAD107" settings)"; STEPMAP107="$(jf105 "$LAD107" stepmap)"
check "$( [ -n "$ALLCSV107" ] && echo read)|$( tr ',' '\n' <<<"$ALLCSV107" | grep -cx 'ai_burst_merge_enabled')|$(git -C "$REPO" diff --quiet "$B107F" "$P107F" -- "$P/lib/SalesFollowupLadder.php" && echo same || echo changed)" \
      "read|1|changed" \
  "107-infra b the pin's ladder read by its own code from Git: allKeys() ($(tr ',' '\n' <<<"$ALLCSV107" | grep -c .) keys) holds ai_burst_merge_enabled (W.1); SalesFollowupLadder.php is among the files $V107 changes"
CM107="$(sv107 "" COPY_MARK)"
ADDED107="$(sed -n 's/^EXPECTED_ADDED="\(.*\)"$/\1/p' "$DEPLOY107")"; NA107=$(wc -w <<<"$ADDED107")
CHANGED107="$(sed -n 's/^EXPECTED_CHANGED="\(.*\)"$/\1/p' "$DEPLOY107")"; NC107=$(wc -w <<<"$CHANGED107")
check "$NA107|$NC107|$(git -C "$REPO" diff --name-only --diff-filter=A "$B107F" "$P107F" -- "$P" | grep -c .)|$(git -C "$REPO" diff --name-only --diff-filter=M "$B107F" "$P107F" -- "$P" | grep -c .)|$CM107" \
      "$(git -C "$REPO" diff --name-only --diff-filter=A "$B107F" "$P107F" -- "$P" | grep -c .)|$(git -C "$REPO" diff --name-only --diff-filter=M "$B107F" "$P107F" -- "$P" | grep -c .)|$(git -C "$REPO" diff --name-only --diff-filter=A "$B107F" "$P107F" -- "$P" | grep -c .)|$(git -C "$REPO" diff --name-only --diff-filter=M "$B107F" "$P107F" -- "$P" | grep -c .)|cron/followup_send.php" \
  "107-infra c the script's lists are Git's delta: $NA107 added, $NC107 changed (root docs/76 §T.2 and §T.15); COPY_MARK cron/followup_send.php"
left107() { local f n=0; for f in $ADDED107; do [ -e "$PD/$f" ] && n=$((n+1)); done; echo "$n"; }   # 5.18.107's added files on disk
installed107() {   # installed107 COMMIT — the files $BV107 → $V107 touches outside tests/ that are NOT installed as COMMIT has them
  local f rel bad=0
  for f in $(git -C "$REPO" diff --name-only --diff-filter=AM "$B107F" "$P107F" -- "$P" | grep -v "^$P/tests/"); do
    rel="${f#$P/}"
    if git -C "$REPO" cat-file -e "$1:$f" 2>/dev/null; then
      [ "$(git -C "$REPO" show "$1:$f" | sha256sum | cut -c1-64)" = "$(sha256sum "$PD/$rel" 2>/dev/null | cut -c1-64)" ] || bad=$((bad+1))
    fi
  done
  echo "$bad"
}
# The --after-only lines of each step, read from the script as 5.18.106's rehearsal reads its own (onst104's reader).
ONP107="QF-ON0:ack QF-ON3:ledger $(grep -oE 'qf_on_one "\$(sub|1)" [A-Z]+-ON[0-9] [a-z-]+ ' "$DEPLOY107" | awk '{print $3 ":" $4}' | grep -v '^QF-ON0:' | sort -u | paste -sd' ' -)"
check "$( [ "$(wc -w <<<"$ONP107")" -ge "$(wc -w <<<"$ONP106")" ] && echo some)|$(for p in $ONP106; do grep -qwF -- "$p" <<<"$ONP107" || echo "lost:$p"; done | paste -sd, -)" "some|" \
  "107-infra d the --after-only's step pairs read from the script ($(wc -w <<<"$ONP107") pairs): every one of 5.18.106's kept"
onall107() { local p; for p in $ONP107; do printf '%s=%s ' "$p" "$(onst104 "$1" "${p%%:*}" "${p#*:}")"; done | sed 's/ $//'; }
expect107() {   # expect107 STEP=STATE… — the line onall107 must read: every pair off but the steps named
  local p s out="" e
  for p in $ONP107; do e=off; for s in "$@"; do case "$s" in *=*) [ "${p#*:}" = "${s%%=*}" ] && e="${s#*=}" ;; esac; done; out="$out $p=$e"; done
  echo "${out# }"
}
L2ON107="$L2ON106"   # steps 1–6 on: production's state before 5.18.107 (v2 A15) — the chase in claim mode

# ── The runs. run107 [--answer WORD] [--hold FILE] [--script FILE] [--env K=V]… OPTION… — run106's form on this release's
# folders: the script's exit status kept ($R107/rc/run-<n>.rc, read by rc107 — PASSED 0, FAILED or NO-GO 1, NOT YET 3), the
# run's state before it (backups, state files, LK key, read by quiet107), and RH106_TOUCH set, so the date folder's touch
# gives stamp_copy the clock's time (5.18.106's A1, which 5.18.107's copies share). ──
keysum107() { if [ -e "$LKF107" ]; then sha256sum < "$LKF107" | cut -c1-16; else echo nokey; fi; }
backups107() { ls -d "$OUT107"/backup-* 2>/dev/null | wc -l | tr -d ' '; }
states107() { ls -1 "$OUT107/state-$V107.env"* "$OUT107"/rollback-state-* 2>/dev/null | wc -l | tr -d ' '; }
run107() {
  local answer="" script="$DEPLOY107" extra=() hold="" n tag o cmd e0 c0
  while :; do case "${1:-}" in --answer) answer="$2"; shift 2 ;; --hold) hold="$2"; shift 2 ;; --script) script="$2"; shift 2 ;; --env) extra+=("$2"); shift 2 ;; *) break ;; esac; done
  : > "$SB/docker.log"; : > "$U99/requests.log"; : > "$U99/limits.log"
  n=$(( $(cat "$SB/runs" 2>/dev/null || echo 0) + 1 )); echo "$n" > "$SB/runs"; tag="$(printf '%03d' "$n")"; echo "$tag" > "$R107/rc/last"
  printf '%s|%s|%s\n' "$(backups107)" "$(states107)" "$(keysum107)" > "$R107/rc/run-$tag.pre"
  date -u +%s > "$R107/rc/run-$tag.t0"
  vt_host > "$R107/vault/run-$tag.before"; : > "$R107/exec/run-$tag.rec"; : > "$R107/scan/run-$tag.hits"; : > "$R107/tok/run-$tag.tok"; e0="$(evo_calls)"; c0="$(grep -c . "$R104/logs/cron104.runs" 2>/dev/null)"
  cmd="$(printf '%q ' env PATH="$SB/datebin:$SB/bin:$PATH" FAKE_TODAY="$AFTER94" DNB_OUT="$OUT107" DNB_RECORDS="$RECS" DNB_WEBSITE="$WEB_OK" GUARD_SECONDS=3 DN_DATA_DIR="$DATA" \
         DNB_SALES_LOG_DIR="$SALES104" DNB_REPORT_DIR="$REPORT104" RH106_TOUCH=1 \
         FAKE_EXEC_LOG="$R107/exec/run-$tag.rec" FAKE_TMP_SCAN="$SCAN96" FAKE_TMP_HITS="$R107/scan/run-$tag.hits" FAKE_TOK_HITS="$R107/tok/run-$tag.tok" \
         ${extra[@]+"${extra[@]}"} bash "$script" --plugin-base "$PLUGIN_BASE" "$@")"
  if [ -n "$hold" ]; then
    o="$( { i=0; while [ ! -e "$hold" ] && [ "$i" -lt 6000 ]; do sleep 0.05; i=$((i+1)); done; [ -e "$hold" ] || echo "run107-hold:$tag" >> "$R104/wait104.fail"; printf '%s\n' "$answer"; } \
          | SHELL=/bin/bash script -qec "$cmd" /dev/null 2>&1 | tee "${R107_STREAM:-/dev/null}" | tr -d '\r'; echo "${PIPESTATUS[1]}" > "$R107/rc/run-$tag.rc")"
  else o="$(printf '%s\n' "$answer" | SHELL=/bin/bash script -qec "$cmd" /dev/null 2>&1 | tee "${R107_STREAM:-/dev/null}" | tr -d '\r'; echo "${PIPESTATUS[1]}" > "$R107/rc/run-$tag.rc")"; fi
  date -u +%s > "$R107/rc/run-$tag.t1"
  echo "$(( $(evo_calls) - e0 )) $( [ "$(n103 "$(grep -c . "$R104/logs/cron104.runs" 2>/dev/null)")" -gt "$(n103 "$c0")" ] && echo cron || echo nocron)" > "$R107/evo/run-$tag.n"
  vt_host > "$R107/vault/run-$tag.after"; cp "$U99/requests.log" "$R107/ucrm/run-$tag.req"; cp "$U99/limits.log" "$R107/ucrm/run-$tag.lim"
  case "$o" in *"unknown option:"*) echo "run107-$n" >> "$SB/bad-runs" ;; esac
  if [ -n "${R107_MUTANT_OUT:-}" ]; then printf '%s\n' "$o" > "$R107/mutants/run-$tag.txt"; else printf '%s\n' "$o" > "$R107/run-$tag.txt"; fi
  [ -z "${REHEARSE_KEEP:-}" ] || printf '%s\n' "$o" > "$REHEARSE_KEEP/run107-$tag-${answer:-none}-$(basename "$script" .sh)$(printf '%s' "$*" | tr -c 'A-Za-z0-9-' '_').txt"
  printf '%s' "$o"
}
run107c() { run107 --env "LD_PRELOAD=$CLKPRE104" --env "RH104_CLOCK_FILE=$CLKF104" "$@"; }   # under the rehearsal's clock
run_line107c() {   # run_line107c WORD LINE — a command line exactly as a summary printed it, under the clock, WORD typed; its exit status kept as run107's
  local n tag o cmd e0 c0
  : > "$SB/docker.log"; : > "$U99/requests.log"; : > "$U99/limits.log"
  n=$(( $(cat "$SB/runs" 2>/dev/null || echo 0) + 1 )); echo "$n" > "$SB/runs"; tag="$(printf '%03d' "$n")"; echo "$tag" > "$R107/rc/last"
  printf '%s|%s|%s\n' "$(backups107)" "$(states107)" "$(keysum107)" > "$R107/rc/run-$tag.pre"
  vt_host > "$R107/vault/run-$tag.before"; : > "$R107/exec/run-$tag.rec"; : > "$R107/scan/run-$tag.hits"; : > "$R107/tok/run-$tag.tok"; e0="$(evo_calls)"; c0="$(grep -c . "$R104/logs/cron104.runs" 2>/dev/null)"
  cmd="$(printf '%q ' env PATH="$SB/datebin:$SB/bin:$PATH" FAKE_TODAY="$AFTER94" DNB_OUT="$OUT107" DNB_RECORDS="$RECS" DNB_WEBSITE="$WEB_OK" GUARD_SECONDS=3 DN_DATA_DIR="$DATA" PLUGIN_BASE="$PLUGIN_BASE" \
         DNB_SALES_LOG_DIR="$SALES104" DNB_REPORT_DIR="$REPORT104" LD_PRELOAD="$CLKPRE104" RH104_CLOCK_FILE="$CLKF104" RH106_TOUCH=1 \
         FAKE_EXEC_LOG="$R107/exec/run-$tag.rec" FAKE_TMP_SCAN="$SCAN96" FAKE_TMP_HITS="$R107/scan/run-$tag.hits" FAKE_TOK_HITS="$R107/tok/run-$tag.tok" bash -c "$2")"
  o="$(printf '%s\n' "$1" | SHELL=/bin/bash script -qec "$cmd" /dev/null 2>&1 | tr -d '\r'; echo "${PIPESTATUS[1]}" > "$R107/rc/run-$tag.rc")"
  echo "$(( $(evo_calls) - e0 )) $( [ "$(n103 "$(grep -c . "$R104/logs/cron104.runs" 2>/dev/null)")" -gt "$(n103 "$c0")" ] && echo cron || echo nocron)" > "$R107/evo/run-$tag.n"
  vt_host > "$R107/vault/run-$tag.after"; cp "$U99/requests.log" "$R107/ucrm/run-$tag.req"; cp "$U99/limits.log" "$R107/ucrm/run-$tag.lim"
  case "$o" in *"unknown option:"*) echo "run107-$n" >> "$SB/bad-runs" ;; esac
  printf '%s\n' "$o" > "$R107/run-$tag.txt"
  printf '%s' "$o"
}
last107() { cat "$R107/rc/last" 2>/dev/null || echo none; }                    # the tag of the latest run107 / run_line107c
rc107() { cat "$R107/rc/run-$(last107).rc" 2>/dev/null || echo none; }         # its exit status
secs107() { local t="$(last107)"; echo $(( $(cat "$R107/rc/run-$t.t1" 2>/dev/null || echo 0) - $(cat "$R107/rc/run-$t.t0" 2>/dev/null || echo 0) )); }   # its real duration
fresh107() {   # the run folder as a new run finds it: the state files, the key files and the row digests kept; every log and sheet moved aside
  find "$OUT107" -mindepth 1 -maxdepth 1 -name '*.log' -exec mv -t "$R107/logs/" {} + 2>/dev/null
  find "$OUT107" -mindepth 1 -maxdepth 1 -name 'cash-*.txt' -exec mv -t "$R107/sheets/" {} + 2>/dev/null
  find "$OUT107" -mindepth 1 -maxdepth 1 ! -name "state-$V107.env*" ! -name 'rollback-state-*' ! -name 'ql-key-*' ! -name 'cih-rows-*' ! -name "lk-key-$V107" -exec rm -rf {} +; base_log; }
tidy107() {   # tidy107 CASE — a deploy's backup, state files and LK key moved into the case's own folder, so the next deploy is a first deploy again (v3 A9 step 4)
  local d="$R107/case/$1"; mkdir -p "$d"
  find "$OUT107" -mindepth 1 -maxdepth 1 \( -name 'backup-*' -o -name "state-$V107.env*" -o -name 'rollback-state-*' -o -name "lk-key-$V107" -o -name 'cih-rows-*' \) -exec mv -t "$d/" {} + 2>/dev/null; :
}
went107() { echo "$(has "$1" 'GO — evidence recorded')$(has "$1" 'Type DEPLOY')"; }   # the run reached the question
quiet107() {   # quiet107 — what the latest run changed: LIVE|backups added|state files added|samekey or keymoved
  local p; p="$(cat "$R107/rc/run-$(last107).pre" 2>/dev/null)"
  echo "$(live)|$(( $(backups107) - $(n103 "$(cut -d'|' -f1 <<<"$p")") ))|$(( $(states107) - $(n103 "$(cut -d'|' -f2 <<<"$p")") ))|$( [ -n "$p" ] && [ "$(keysum107)" = "$(cut -d'|' -f3 <<<"$p")" ] && echo samekey || echo keymoved)|$(data94)"
}
nogo107() {   # nogo107 LABEL EXPECTED-TEXT OUTPUT DATA-DIGEST LIVE — refused with that text, "Nothing was changed", exit 1, never the question; nothing changed
  check "$(has "$3" "$2")$(has "$3" 'Nothing was changed. Send the log file.')$(went107 "$3")|$(rc107)|$(quiet107)" "yesyesnono|1|$5|0|0|samekey|$4" "$1"
}
checkgo107() { echo "$(has "$1" "  $V107 (check): GO — stage A holds.")$(went107 "$1")$(fails "$1")|$(cut -d'|' -f2-4 <<<"$(quiet107)")|$(rc107)"; }   # GO: yesnono0|0|0|samekey|0
sget107() { sed -n "s/^$2=//p" "$1" 2>/dev/null | tail -1; }                   # sget107 FILE KEY — the last value a state file records for KEY
aft107() {   # aft107 LABEL VERDICT [STEP=STATE…] — 5.18.107's --after-only under the clock (the copy as the deploy recorded it, never
             # moved back; no schedule written): PASSED (exit 0, no FAIL), NOTYET (exit 3, no FAIL, the NOT YET line, no PASSED) or
             # FAILED (exit 1, a FAIL); each pair as named, the rest OFF; Evolution not called. The caller runs master106 first
             # unless its case is "before any pass". AFT107 its output, AFTT107 its tag, AFTRC107 its exit status — call directly
  local lab="$1" want="$2" e0 f p y x got exp; shift 2; idle104; e0="$(evo_calls)"
  fresh107; AFT107="$(run107c --after-only)"; AFTT107="$(last107)"; AFTRC107="$(rc107)"
  f="$(fails "$AFT107")"; p="$(has "$AFT107" "  $V107 (after): PASSED.")"; y="$(has "$AFT107" "  $V107 (after-only): NOT YET — ")"
  x="$(grep -E "^  ${V107//./\\.} \(after\): [0-9]+ FAILED — " <<<"$AFT107" | grep -c .)"
  case "$want" in
    PASSED) got="$f|$p$y$x|$AFTRC107"; exp="0|yesno0|0" ;;
    NOTYET) got="$f|$p$y$x|$AFTRC107"; exp="0|noyes0|3" ;;
    FAILED) got="$( [ "$(n103 "$f")" -gt 0 ] && echo some || echo none)|$p$y$x|$AFTRC107"; exp="some|nono1|1" ;;
    *) got="verdict:$want"; exp="PASSED|NOTYET|FAILED" ;;
  esac
  check "$got|$(q105 stcmp "$(onall107 "$AFT107")" "$(expect107 "$@")")|$(( $(evo_calls) - e0 ))" "$exp|same|0" \
    "$lab $V107's --after-only under the clock ($(kday104 "$(fnow104)")): $want (exit status $AFTRC107) — each pair as the step stands$( [ $# -gt 0 ] && echo " ($*)"), the rest OFF — Evolution not called"
}
rbx107() {   # rbx107 LABEL — back to 5.18.106 after a deploy of 5.18.107: ROLLBACK typed, under the clock; PASSED, exit 0, $B107 serving,
             # every file 5.18.107 changes $BV107's, every ladder key as before it; then qcheck106. RBX107_OUT its output, RBX107_T
             # its tag, RBX107_REC the record it wrote (or none) — call it directly
  local o k0 r0
  k0="$(digest105)"; r0="$(ls -1 "$OUT107"/rollback-state-*.env 2>/dev/null)"
  fresh107; o="$(run107c --answer ROLLBACK --rollback)"; RBX107_OUT="$o"; RBX107_T="$(last107)"
  RBX107_REC="$(ls -1 "$OUT107"/rollback-state-*.env 2>/dev/null | grep -vxF -f <(printf '%s\n' "$r0") | tail -1)"; RBX107_REC="${RBX107_REC:-none}"
  check "$(fails "$o")$(has "$o" "  $V107 (rollback): PASSED.")|$(rc107)|$(live)|$(installed107 "$B107F")|$( [ "$(digest105)" = "$k0" ] && echo kept)|$( [ "$RBX107_REC" != none ] && echo record)" \
        "0yes|0|$B107|0|kept|record" "$1 the rollback, ROLLBACK typed, under the clock: PASSED (exit 0) — $B107 serving, every file $V107 changes $BV107's again, every ladder key as before it (this rehearsal's reading)"
  qcheck106 "$1'"
}
# The held runs (v2 A10, v3): the script waits at a question (or a line) while an ACTION runs. csb1run107 holds at "Type DEPLOY"
# and records the ACTION's own exit status; rbhold107 at "Type ROLLBACK"; pollrun107 at ME-CA1's poll line, in --after-only,
# acting 1 s after it (so the poll's t0, S:8381, is taken first) and never acting when the line does not come.
hold107() {   # hold107 LABEL KEY ANSWER [--script S] [--opt OPTION] -- ACTION… — the shared shape; HOLDOUT107 the output, HOLDRC107 ACTION's exit status, HOLDSAW107 yes|no
  local lab="$1" key="$2" ans="$3" scr="$DEPLOY107" opt="" st hf op; shift 3
  while :; do case "${1:-}" in --script) scr="$2"; shift 2 ;; --opt) opt="$2"; shift 2 ;; --) shift; break ;; *) break ;; esac; done
  st="$R107/logs/stream-$lab.txt"; hf="$R107/logs/hold-$lab"; rm -f "$hf" "$R107/logs/act-$lab.rc" "$R107/logs/saw-$lab"; : > "$st"
  ( i=0; while ! grep -q -- "$key" "$st" 2>/dev/null && [ "$i" -lt 6000 ]; do sleep 0.05; i=$((i+1)); done
    if grep -q -- "$key" "$st" 2>/dev/null; then : > "$R107/logs/saw-$lab"; [ "$key" != 'ME-CA1 waiting up to' ] || sleep 1
      "$@" > "$R107/logs/act-$lab.txt" 2>&1; echo "$?" > "$R107/logs/act-$lab.rc"
    else echo "hold107:$lab:noline" >> "$R104/wait104.fail"; echo noline > "$R107/logs/act-$lab.rc"; fi
    : > "$hf" ) & op=$!
  fresh107
  if [ -n "$opt" ]; then HOLDOUT107="$(R107_STREAM="$st" run107c --answer "$ans" --hold "$hf" --script "$scr" "$opt")"
  else HOLDOUT107="$(R107_STREAM="$st" run107c --answer "$ans" --hold "$hf" --script "$scr")"; fi
  wait "$op" 2>/dev/null
  HOLDRC107="$(cat "$R107/logs/act-$lab.rc" 2>/dev/null)"; HOLDSAW107="$( [ -e "$R107/logs/saw-$lab" ] && echo yes || echo no)"
}
csb1run107() { local lab="$1" scr="$2"; shift 2; [ "${1:-}" = -- ] && shift; hold107 "$lab" 'Type DEPLOY' DEPLOY --script "$scr" -- "$@"; }       # csb1run107 LABEL SCRIPT -- ACTION…
rbhold107() { local lab="$1" ans="$2"; shift 2; [ "${1:-}" = -- ] && shift; hold107 "$lab" 'Type ROLLBACK' "$ans" --opt --rollback -- "$@"; }     # rbhold107 LABEL ANSWER -- ACTION…
pollrun107() { local lab="$1" scr="$2"; shift 2; [ "${1:-}" = -- ] && shift; hold107 "$lab" 'ME-CA1 waiting up to' '' --script "$scr" --opt --after-only -- "$@"; }   # pollrun107 LABEL SCRIPT -- ACTION…
# ── 107-infra e — what the chain leaves behind, before 107 touches a stand-in (v3, review 2 FM8): 106-10f's leftover reading,
# run here as well as at 107-10, over every trigger the chain arms — none armed, no index lock, no weakened copy newer than
# the script it weakened. ──
LEFT107() {   # LEFT107 — the chain's and this release's triggers still armed, comma-joined, or none
  local f out=""
  for f in "$SB/.ujfail106" "$SB/web/.redirect106" "$SB/web/.redirect106lock" "$SB/evo/rh106-refuse.list" "$SB/.rbfail104" "$SB/.rbfail103" \
           "$SB/web/.redirect105" "$SB/web/.redirect104" "$SB/web/.left104m" \
           "$SB/web/.noboot107" "$SB/.plant092touch107" "$SB/.alter092touch107" "$SB/.boot107" "$SB/.rbfail107" "$SB/web/.redirect107" "$SB/web/.redirect107lock"; do
    [ -e "$f" ] && out="$out,${f#$SB/}"
  done
  [ -e "$REPO/.git/index.lock" ] && out="$out,index.lock"
  echo "${out#,}" | sed 's/^$/none/'
}
# 5.18.106's refusal list stays from 106-6 (its sender pass) until 106-10f, which removes it — and 106-10 is cut with the
# chain, so its removal is carried here, as 106-10f made it: removed, then a send for J1's first number answered with a key.id
# and nothing more refused (one call to the stand-in Evolution, outside every run, as 106-10f's).
RLB107="$(if [ -e "$SB/evo/rh106-refuse.list" ]; then grep -c . "$SB/evo/rh106-refuse.list"; else echo absent; fi)"; RFB107="$(refused106)"
RLA107="$(refuse106)"; RQ107="$(evopost106 sendText "${J1A106:-}")"
check "$RLA107:$( [ -e "$SB/evo/rh106-refuse.list" ] && echo listed || echo unlisted):$RQ107:$(( $(refused106) - RFB107 ))" "0:unlisted:id:0" \
  "107-infra e0 $V106's refusal list (it listed $RLB107; 106-10f removes it, cut with 106-10) removed here as 106-10f did: a send for J1's first number answered with a key.id, nothing more refused"
check "$(LEFT107)|$(find "$REPO/scripts" -maxdepth 1 -name '.*.sh' -newer "$DEPLOY106" 2>/dev/null | grep -c .)" "none|0" \
  "107-infra e nothing the chain armed is left armed (106-10f's reading, run before 107 touches a stand-in): no trigger, no index lock, no weakened copy of 5.18.106's script left in the clone"

# ── 107-infra f — A1: the stand-in web server on the rehearsal's clock (v2 A1, v3). The chain starts it without LD_PRELOAD
# (root:285, :930), so a boot through it stamped 092's applied_at (SQLite's datetime('now')) and migration.log's lines with
# the kernel's time, forty days behind COPY_AT and QF_COPY_UNIX. One asserted edit of the stand-in public.php, before its
# boot (each probe answered without booting the live store — v3 FM9): a clock probe (rh107c_probe: PHP time() and SQLite's
# strftime('%s','now')), a boot-hooks probe (rh107b_probe), and H1 (.noboot107: a 503 with a fixed body, no boot, while the
# file exists — never spent). Then the server relaunched as uid 1000 under the clock, appending to web.log as root:930 does;
# idempotent, so a restored shell's old server is replaced too. ──
WL0_107="$(grep -c . "$SB/web.log" 2>/dev/null)"; WF0X_107="$(grep -cE 'Fatal error|Parse error' "$SB/web.log" 2>/dev/null)"
python3 -I - "$SB/web/public.php" <<'PY' || echo "standin:boot107" >> "$SB/anchor-missing"
import sys
p = sys.argv[1]
s = open(p).read()
head = "[$root, $dataDir, $clog, $web] = array_map('trim', array_slice(explode(\"\\n\", (string)@file_get_contents(__DIR__ . '/.installed_plugin')), 0, 4));\n"
boot = "foreach (['bootstrap_data', 'StoreInterface', 'JsonStore', 'SqliteStore'] as $l) require_once $root . \"/lib/$l.php\";\n$store = SqliteStore::create($dataDir);\n"
assert s.count(head) == 1 and s.count(boot) == 1 and s.index(head) < s.index(boot), (s.count(head), s.count(boot))
assert s[s.index(head) + len(head):].startswith(boot), 'the boot does not follow the head'
assert s.count('rh107') == 0 and s.count('.noboot107') == 0, 'already edited'
new = ("if (($_GET['page'] ?? '') === 'rh107c_probe') { $q = (new PDO('sqlite::memory:'))->query(\"SELECT strftime('%s','now')\")->fetchColumn(); echo 'rh107c ' . time() . ' ' . $q; exit; }\n"
       "if (($_GET['page'] ?? '') === 'rh107b_probe') { echo 'rh107-boot-hooks-ready'; exit; }\n"
       "if (is_file($web . '/.noboot107')) { http_response_code(503); echo 'rh107: the container is not answering (no boot)'; exit; }\n")
i = s.index(boot)
s2 = s[:i] + new + s[i:]
assert len(s2) == len(s) + len(new) and s2.count(boot) == 1 and s2.index('rh107c_probe') < s2.index('rh107b_probe') < s2.index('.noboot107') < s2.index(boot)
open(p, 'w').write(s2)
PY
for i in $(seq 1 60); do grep -q 'rh107-boot-hooks-ready' <<<"$(pubget page=rh107b_probe)" && break; sleep 0.1; done   # PHP re-reads the edited file
cpr107() { local o; o="$(pubget page=rh107c_probe)"; case "$o" in "rh107c "*) echo "${o#rh107c }" ;; *) echo "unread unread" ;; esac; }   # cpr107 — the server's PHP time and SQLite time
C0_107="$(cpr107)"; W0_107="$(date -u +%s)"
kill "$WEB_PID" 2>/dev/null; wait "$WEB_PID" 2>/dev/null
pkill -f -- "-S 127.0.0.1:$WPORT -t $SB/web" 2>/dev/null   # a restored shell's WEB_PID may name a process it did not start: the server found by its own command line
for i in $(seq 1 50); do curl -s --noproxy '*' -o /dev/null "$PLUGIN_BASE?page=rh107b_probe" 2>/dev/null || break; sleep 0.1; done   # the old server gone
setpriv --reuid 1000 --regid 1000 --clear-groups -- env LD_PRELOAD="$CLKPRE104" RH104_CLOCK_FILE="$CLKF104" "$PHPBIN" -S "127.0.0.1:$WPORT" -t "$SB/web" >>"$SB/web.log" 2>&1 & WEB_PID=$!; PIDS+=("$WEB_PID")
for i in $(seq 1 100); do grep -q 'rh107-boot-hooks-ready' <<<"$(pubget page=rh107b_probe)" && break; sleep 0.1; done
C1_107="$(cpr107)"; F1_107="$(fnow104)"
check "$(nr106 "${C0_107%% *}" "$W0_107")|$(nr106 "${C0_107##* }" "$W0_107")|$( [ $(( F1_107 - W0_107 )) -ge 3600 ] && echo ahead)|$(nr106 "${C1_107%% *}" "$F1_107")|$(nr106 "${C1_107##* }" "$F1_107")|$(pubget page=rh107b_probe)|$(standin_page customer_login)" \
      "near|near|ahead|near|near|rh107-boot-hooks-ready|200" \
  "107-infra f A1 the stand-in web server relaunched on the rehearsal's clock: before, its PHP time and SQLite time were the wall clock's (the control on the control); after, both within 2 s of the clock, $(( (F1_107 - W0_107) / 86400 )) day(s) ahead — so 092's applied_at and migration.log's stamps share COPY_AT's clock; the sign-in page still answers 200"
: > "$SB/web/.noboot107"; chmod 644 "$SB/web/.noboot107"
H1A_107="$(curl -s --noproxy '*' -o /dev/null -w '%{http_code}' "$PLUGIN_BASE?page=customer_login")|$(pubget page=customer_login | grep -c 'rh107: the container is not answering')|$(pubget page=rh107c_probe | grep -c '^rh107c ')"
rm -f "$SB/web/.noboot107"
check "$H1A_107|$(standin_page customer_login)" "503|1|1|200" \
  "107-infra f H1 .noboot107 armed: the sign-in page answers 503 with the fixed body and no boot, the clock probe still answers (it sits before H1); removed: 200 again"

# ── 107-infra g — W.8 in either shell (v2 A16). A record run's sandbox carries cc6a714's line from the root; a development
# runner's restored one (a snapshot older than 26 Nov) may not: the one line re-applied here, asserted (the fixed line once,
# or the old line once and replaced), then root:949–965's four controls, carried. ──
W8P107="/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin"
W8S107="$(python3 -I - "$SB/bin/docker" "$W8P107" <<'PY'
import sys
p, path = sys.argv[1], sys.argv[2]
s = open(p).read()
new = '--clear-groups -- env PATH=%s); fi' % path
old = '--clear-groups --); fi'
if s.count(new) == 1 and s.count(old) == 0:
    print('carried')
elif s.count(old) == 1 and s.count(new) == 0:
    s2 = s.replace(old, new, 1)
    assert s2.count(new) == 1 and s2.count(old) == 0 and len(s2) == len(s) + len(new) - len(old)
    open(p, 'w').write(s2)
    print('applied')
else:
    print('unread:%d:%d' % (s.count(new), s.count(old)))
PY
)"
W8A107="$SB/u/w8-107-1"; W8B107="$SB/u/w8-107-2"; : > "$W8A107"; : > "$W8B107"; chown 1000:1000 "$W8A107" "$W8B107"
W8C107="$(env PATH="$SB/bin:$PATH" bash "$SB/bin/docker" exec -u 1000:1000 ucrm printenv PATH)|$(env PATH="$SB/bin:$PATH" bash "$SB/bin/docker" exec -u 1000:1000 ucrm sh -c 'command -v rm')|$(env PATH="$SB/bin:$PATH" bash "$SB/bin/docker" exec -u 1000:1000 ucrm rm "$W8A107" >/dev/null 2>&1; echo $?)|$([ -e "$W8A107" ] && echo kept || echo gone)"
W8O107="$SB/w8-docker-old-107"; sed "s#--clear-groups -- env PATH=$W8P107); fi#--clear-groups --); fi#" "$SB/bin/docker" > "$W8O107"
W8D107="$(grep -cF -- "env PATH=$W8P107" "$W8O107")|$(env PATH="$SB/bin:$PATH" bash "$W8O107" exec -u 1000:1000 ucrm printenv PATH)"
rm -f "$W8O107" "$W8A107" "$W8B107"
check "$( case "$W8S107" in carried|applied) echo ok ;; *) echo "$W8S107" ;; esac)|$W8C107|$W8D107" "ok|$W8P107|/usr/bin/rm|0|gone|0|$SB/bin:$PATH" \
  "107-infra g W.8 in this shell ($W8S107): -u runs with the container's PATH, rm is /usr/bin/rm, a file of the server's owner deleted as that user (exit 0); the control on the control, W.8 undone in a copy, runs -u with the caller's PATH"

# ── 107-infra h — the boots and the hooks (v2 A5, v3). boot107 is the one way this rehearsal boots the live store on purpose:
# the INSTALLED code's SqliteStore::create over the live data folder, as uid 1000 under the clock — the stand-in's own boot,
# from a CLI — its output to a file of its own (v3 FM7), never to a stream. ──
cat > "$H107/boot.php" <<'PHP'
<?php
// 5.18.107's rehearsal: one boot of a code root's store over a data folder, as the stand-in public.php boots it
// (SqliteStore::create runs the runner over migrations/*.sql); prints booted, or why not
[$root, $data] = array_slice($argv, 1, 2);
foreach (['bootstrap_data', 'StoreInterface', 'JsonStore', 'SqliteStore'] as $l) require_once $root . "/lib/$l.php";
SqliteStore::create($data);
echo "booted\n";
PHP
chmod 644 "$H107/boot.php"
BOOTN107=0
boot107() {   # boot107 LABEL — one boot of the installed store, under the clock; prints booted or the reason; its output kept in $R107/boot/
  local o; BOOTN107=$((BOOTN107 + 1))
  o="$(cd "$H107/v" && setpriv --reuid 1000 --regid 1000 --clear-groups -- env LD_PRELOAD="$CLKPRE104" RH104_CLOCK_FILE="$CLKF104" "$PHPBIN" -d log_errors=0 "$H107/boot.php" "$PD" "$DATA" 2>&1)"
  printf '%s\n' "$o" > "$R107/boot/boot107-$BOOTN107-$(tag106 "$1").out"
  case "$o" in booted) echo booted ;; *) echo "notbooted:$(head -c 80 <<<"$o" | tr -c 'A-Za-z0-9 :._-' '_')" ;; esac
}
# H2 — the plant before stamp_copy (v2 A6, v3): the date folder's touch, inside its RH106_TOUCH branch, one boot while
# $SB/.plant092touch107 exists (consumed), the clock 3 s on (the offset file's integer, forward only, its mode kept; never
# clk_to104 and never date inside the shim), counted; then it stamps as before.
cat > "$SB/touch107.hook" <<'HOOK'
  if [ -f "@SB@/.plant092touch107" ]; then
    rm -f "@SB@/.plant092touch107"
    ( cd "@H107@/v" && setpriv --reuid 1000 --regid 1000 --clear-groups -- env LD_PRELOAD="@CLKPRE@" RH104_CLOCK_FILE="@CLKF@" "@PHP@" -d log_errors=0 "@H107@/boot.php" "@PD@" "@DATA@" ) >> "@R107@/boot/h2.out" 2>&1
    _rh_o7="$(head -n1 "@CLKF@" 2>/dev/null)"
    case "$_rh_o7" in ''|*[!0-9-]*) echo unread >> "@SB@/plant092touch107.refused" ;; *) printf '%s\n' "$((_rh_o7 + 3))" > "@CLKF@.h2" && cat "@CLKF@.h2" > "@CLKF@" && rm -f "@CLKF@.h2" ;; esac
    echo fired >> "@SB@/plant092touch107.fired"
  fi
  if [ -f "@SB@/.alter092touch107" ]; then
    rm -f "@SB@/.alter092touch107"
    if [ -f "@PD@/migrations/092_wa_no_ai.sql" ]; then printf '%s\n' '-- RH107SECRET- a byte the pin does not have (H2, at stamp_copy)' >> "@PD@/migrations/092_wa_no_ai.sql"; echo fired >> "@SB@/alter092touch107.fired"
    else echo nofile >> "@SB@/alter092touch107.fired"; fi
  fi
HOOK
python3 -I - "$SB/datebin/touch" "$SB/touch107.hook" "$SB" "$H107" "$CLKPRE104" "$CLKF104" "$PHPBIN" "$PD" "$DATA" "$R107" <<'PY' || echo "datebin:touch107" >> "$SB/anchor-missing"
import sys
p, hook, sb, h107, clkpre, clkf, php, pd, data, r107 = sys.argv[1:11]
for v in (sb, h107, clkpre, clkf, php, pd, data, r107):
    assert v.startswith('/') and '"' not in v and '@' not in v, v
s = open(p).read()
h = (open(hook).read().replace('@SB@', sb).replace('@H107@', h107).replace('@CLKPRE@', clkpre).replace('@CLKF@', clkf)
     .replace('@PHP@', php).replace('@PD@', pd).replace('@DATA@', data).replace('@R107@', r107))
assert '@' not in h, 'a placeholder left'
anchor = 'if [ -n "${RH106_TOUCH:-}" ] && [ "${1:-}" = -c ] && [ "$#" -ge 2 ]; then\n'
assert s.count(anchor) == 1 and s.count('.plant092touch107') == 0 and s.count('.alter092touch107') == 0, (s.count(anchor), s.count('.plant092touch107'))
i = s.index(anchor) + len(anchor)
s2 = s[:i] + h + s[i:]
assert len(s2) == len(s) + len(h) and s2.count('.plant092touch107') == 2 and s2.count('.alter092touch107') == 2 and s2.index(anchor) < s2.index('.plant092touch107') < s2.index('.alter092touch107') < s2.index('for a in')
open(p, 'w').write(s2)
PY
rm -f "$SB/touch107.hook"; : > "$SB/plant092touch107.fired"; : > "$SB/plant092touch107.refused"; : > "$SB/alter092touch107.fired"
TCF107="$H107/touch.ctl"; : > "$TCF107"
O0_107="$(head -n1 "$CLKF104")"
env PATH="$SB/datebin:$PATH" RH106_TOUCH=1 LD_PRELOAD="$CLKPRE104" RH104_CLOCK_FILE="$CLKF104" touch -c "$TCF107"; H2A_107="$(grep -c . "$SB/plant092touch107.fired")|$(head -n1 "$CLKF104")"
: > "$SB/.plant092touch107"
env PATH="$SB/datebin:$PATH" RH106_TOUCH=1 LD_PRELOAD="$CLKPRE104" RH104_CLOCK_FILE="$CLKF104" touch -c "$TCF107"; H2B_107="$(grep -c . "$SB/plant092touch107.fired")|$(head -n1 "$CLKF104")|$( [ -e "$SB/.plant092touch107" ] && echo unspent || echo spent)|$(nr106 "$(stat -c %Y "$TCF107")" "$(fnow104)")"
check "$(bash -n "$SB/datebin/touch" && echo parses)|$H2A_107|$H2B_107|$(grep -c . "$SB/plant092touch107.refused")|$(grep -c '^booted$' "$R107/boot/h2.out" 2>/dev/null)|$(stat -c %a "$CLKF104")" \
      "parses|0|$O0_107|1|$(( O0_107 + 3 ))|spent|near|0|1|644" \
  "107-infra h H2 the date folder's touch: unarmed it stamps as before (no boot, the clock unmoved); armed, it boots the installed store once (as uid 1000, under the clock), moves the clock 3 s on (the offset file, mode kept), consumes the trigger, then stamps at the clock's time"
: > "$SB/plant092touch107.fired"; : > "$R107/boot/h2.out"
# H2's second trigger (v3 §A, §T.11:3487 — D8): .alter092touch107 appends one comment line to the INSTALLED 092 at the same touch,
# so V1's boot applies other bytes; only where the file is on disk. Its control here, 5.18.106 serving: nothing created, spent.
: > "$SB/.alter092touch107"
env PATH="$SB/datebin:$PATH" RH106_TOUCH=1 LD_PRELOAD="$CLKPRE104" RH104_CLOCK_FILE="$CLKF104" touch -c "$TCF107"
check "$(cat "$SB/alter092touch107.fired")|$( [ -e "$PD/migrations/092_wa_no_ai.sql" ] && echo made || echo none)|$( [ -e "$SB/.alter092touch107" ] && echo unspent || echo spent)|$(grep -c . "$SB/plant092touch107.fired")" "nofile|none|spent|0" \
  "107-infra h H2's second trigger (.alter092touch107) with no 092 on disk ($V106 serving): it creates no file, records nofile, is spent, and boots nothing"
: > "$SB/alter092touch107.fired"; rm -f "$TCF107"
# H4 — .boot107 (v2 A8, v3 review 2 TC2/FM1): one boot at deploy-hybrid's read of .deployed-commit while it names 5.18.106 — the
# exact call, and only when the docker's parent command line names deploy-hybrid.sh (stage A's LIVE_BEFORE makes the same call
# from the script itself). Armed only by csb1run107's action at Type DEPLOY; consumed; counted; its output to a file.
cat > "$SB/docker107.hook" <<'HOOK'
# ── 5.18.107's rehearsal: H4 (.boot107, one boot at deploy-hybrid's record read) and H6 (.rbfail107, a rollback's record read wrong once) ──
if [ "$*" = "exec ucrm cat /data/ucrm/data/plugins/dishnet-hybrid-sudan/.deployed-commit" ]; then
  _rh_r7="$(tail -n1 "@PD@/.deployed-commit" 2>/dev/null | tr -cd '0-9a-f')"
  if [ -f "@SB@/.boot107" ] && [ "$_rh_r7" = "@P106@" ]; then
    case "$(ps -o args= -p "$PPID" 2>/dev/null)" in
      *deploy-hybrid.sh*) rm -f "@SB@/.boot107"
        ( cd "@H107@/v" && setpriv --reuid 1000 --regid 1000 --clear-groups -- env LD_PRELOAD="@CLKPRE@" RH104_CLOCK_FILE="@CLKF@" "@PHP@" -d log_errors=0 "@H107@/boot.php" "@PD@" "@DATA@" ) >> "@R107@/boot/h4.out" 2>&1
        echo fired >> "@SB@/boot107.fired" ;;
      *) echo "notdh" >> "@SB@/boot107.passed" ;;
    esac
  fi
  if [ -f "@SB@/.rbfail107" ]; then
    if [ "$_rh_r7" = "@P107@" ]; then : > "@SB@/.rbfail107.seen"
    elif [ "$_rh_r7" = "@P106@" ] && [ -f "@SB@/.rbfail107.seen" ]; then rm -f "@SB@/.rbfail107" "@SB@/.rbfail107.seen"; echo fired >> "@SB@/rbfail107.fired"; echo 0000000; exit 0; fi
  fi
fi
HOOK
python3 -I - "$SB/bin/docker" "$SB/docker107.hook" "$SB" "$PD" "$P106" "$P107" "$H107" "$CLKPRE104" "$CLKF104" "$PHPBIN" "$DATA" "$R107" <<'PY' || echo "fakedocker:hook107" >> "$SB/anchor-missing"
import sys
p, hook, sb, pd, p106, p107, h107, clkpre, clkf, php, data, r107 = sys.argv[1:13]
assert p106 and p107 and p106 != p107 and all(c in '0123456789abcdef' for c in p106 + p107), (p106, p107)
s = open(p).read()
h = (open(hook).read().replace('@SB@', sb).replace('@PD@', pd).replace('@P106@', p106).replace('@P107@', p107).replace('@H107@', h107)
     .replace('@CLKPRE@', clkpre).replace('@CLKF@', clkf).replace('@PHP@', php).replace('@DATA@', data).replace('@R107@', r107))
assert '@' not in h, 'a placeholder left'
lines = s.split('\n')
at = [i for i, l in enumerate(lines) if l.startswith('row() { grep "^$1|" ')]
assert len(at) == 1, len(at)
assert s.count('.boot107') == 0 and s.count('.rbfail107') == 0 and s.count('.ujfail106') == 2 and s.count('.rbfail104') == 5, (s.count('.ujfail106'), s.count('.rbfail104'))
lines[at[0]:at[0]] = h.rstrip('\n').split('\n')
s2 = '\n'.join(lines)
assert len(s2) == len(s) + len(h.rstrip('\n')) + 1
assert s2.count('.boot107') == 2 and s2.count('.rbfail107') == 5 and s2.count('row() { grep "^$1|" ') == 1
assert s2.rindex('.ujfail106') < s2.index('.boot107') < s2.index('row() { grep "^$1|" '), 'order'
open(p, 'w').write(s2)
PY
rm -f "$SB/docker107.hook"; : > "$SB/boot107.fired"; : > "$SB/boot107.passed"; : > "$SB/rbfail107.fired"
DC107="/data/ucrm/data/plugins/dishnet-hybrid-sudan/.deployed-commit"
cp -p "$PD/.deployed-commit" "$SB/dc107.kept"
: > "$SB/.boot107"
H4A_107="$(dk104 exec ucrm cat "$DC107" | tail -n1)|$(grep -c . "$SB/boot107.fired")|$(grep -c . "$SB/boot107.passed")|$( [ -e "$SB/.boot107" ] && echo unspent || echo spent)"   # the rehearsal's own call: not deploy-hybrid
H4B_107="$(bash -c 'exec -a "bash scripts/deploy-hybrid.sh" bash -c "env PATH=\"$1/bin:\$PATH\" bash \"$1/bin/docker\" exec ucrm cat \"$2\" | tail -n1"' _ "$SB" "$DC107")|$(grep -c . "$SB/boot107.fired")|$( [ -e "$SB/.boot107" ] && echo unspent || echo spent)|$(grep -c '^booted$' "$R107/boot/h4.out" 2>/dev/null)"
rm -f "$SB/.boot107"
: > "$SB/.rbfail107"; printf '%s\n' "$P107" > "$PD/.deployed-commit"
H6A_107="$(dk104 exec ucrm cat "$DC107" | tail -n1):$( [ -f "$SB/.rbfail107.seen" ] && echo seen)"
printf '%s\n' "$P106" > "$PD/.deployed-commit"; H6B_107="$(dk104 exec ucrm cat "$DC107" | tail -n1)"; H6C_107="$(dk104 exec ucrm cat "$DC107" | tail -n1)"
cp -p "$SB/dc107.kept" "$PD/.deployed-commit"; rm -f "$SB/dc107.kept" "$SB/.rbfail107" "$SB/.rbfail107.seen"
check "$(bash -n "$SB/bin/docker" && echo parses)|$H4A_107|$H4B_107|$H6A_107|$H6B_107|$H6C_107|$(grep -c . "$SB/rbfail107.fired")|$(live)" \
      "parses|$P106|0|1|unspent|$P106|1|spent|1|$P107:seen|0000000|$P106|1|$P106" \
  "107-infra h H4 and H6 in the stand-in docker: .boot107 armed, the record read from anywhere but deploy-hybrid passes through (counted as passed, still armed); from deploy-hybrid it boots once and is spent; .rbfail107 armed, a read naming $P107 then one naming $P106 answers 0000000 once, then the record again; the record put back"
: > "$SB/boot107.fired"; : > "$SB/boot107.passed"; : > "$SB/rbfail107.fired"; : > "$R107/boot/h4.out"
# H5 — .redirect107 and .redirect107lock (v2 A5, v3): R6's A7 shape for this release — a probe page, and a 302 on the sign-in
# page only while the installed manifest says 5.18.107 and the trigger can be spent; with the lock beside it, the same spend
# creates the clone's .git/index.lock. Inserted after .redirect106's line, before `$page = …`.
arm107() { printf 'x\n' > "$SB/web/.redirect107"; chmod 644 "$SB/web/.redirect107"; [ "${1:-}" != lock ] || { printf 'x\n' > "$SB/web/.redirect107lock"; chmod 644 "$SB/web/.redirect107lock"; }; }   # arm107 [lock]
PB0_107="$(pubget page=rh107_probe | grep -c 'rh107-triggers-ready')"
python3 -I - "$SB/web/public.php" "$V107" "$REPO" <<'PY' || echo "standin:triggers107" >> "$SB/anchor-missing"
import sys
p, v107, repo = sys.argv[1:4]
assert v107 and repo.startswith('/') and "'" not in repo and '\\' not in repo, (v107, repo)
s = open(p).read()
old = "$page = $_GET['page'] ?? '';\n"
assert s.count(old) == 1 and s.count('redirect107') == 0 and s.count("'.redirect106'") == 1 and s.count('rh106_probe') == 1, (s.count(old), s.count('redirect107'))
new = ("if (($_GET['page'] ?? '') === 'rh107_probe') { echo 'rh107-triggers-ready'; exit; }\n"
       "if (($_GET['page'] ?? '') === 'customer_login' && !isset($_GET['rh107v1']) && strpos((string)@file_get_contents($root . '/manifest.json'), '\"" + v107 + "\"') !== false && $spend('.redirect107')) { if ($spend('.redirect107lock')) { @touch('" + repo + "/.git/index.lock'); } header('Location: ?page=customer_login&rh107v1=1', true, 302); exit; }\n")
s2 = s.replace(old, new + old, 1)
assert s2.count("'.redirect107'") == 1 and s2.count("'.redirect107lock'") == 1 and s2.count('rh107_probe') == 1 and s2.count(old) == 1 and len(s2) == len(s) + len(new)
assert s2.count(repo + "/.git/index.lock") == 2 and s2.index("'.redirect106'") < s2.index("'.redirect107'") < s2.index(old), 'order'
open(p, 'w').write(s2)
PY
for i in $(seq 1 60); do grep -q 'rh107-triggers-ready' <<<"$(pubget page=rh107_probe)" && break; sleep 0.1; done
LK0_107="$(lock106)"; arm107 lock
TR107="$(v1read102)|$( [ -e "$SB/web/.redirect107" ] && echo unspent || echo spent)|$( [ -e "$SB/web/.redirect107lock" ] && echo unspent || echo spent)|$(lock106)"
rm -f "$SB/web/.redirect107" "$SB/web/.redirect107lock"
check "$PB0_107|$(pubget page=rh107_probe)|$( [ "$(inst_ver)" != "$V107" ] && echo not107)|$LK0_107|$TR107" "0|rh107-triggers-ready|not107|absent|200:0|unspent|unspent|absent" \
  "107-infra h H5 the stand-in carries .redirect107 and .redirect107lock (its probe answers, as it did not before the edit): armed both while $(inst_ver)'s manifest is installed, the sign-in page does not redirect (200:0), neither is spent, no index lock — they fire only once $V107 is installed"
# ── 107-infra i — the weakened copies of 5.18.107's script (R6's mechanics on this release's script): each anchor the script's
# own text, read here into a file of its own, its replacement beside it; each copy asserts its anchor once, its replacement
# once, and the result; ancc107 is the control of the controls over every anchor set. ──
AN107="$SB/anchors107"; rm -rf "$AN107"; mkdir -p "$AN107"
anc107() { printf '%s' "$2" > "$AN107/$1.a"; printf '%s' "$3" > "$AN107/$1.b"; }   # anc107 KEY ANCHOR REPLACEMENT
weak107() {   # weak107 NAME KEY… — a copy of the script in the clone's scripts/ with each KEY's anchor replaced, asserted; prints its path
  local name="$1" k dst="$REPO/scripts/.$1.sh"; shift
  cp "$DEPLOY107" "$dst"
  for k in "$@"; do
    python3 -I - "$dst" "$AN107/$k.a" "$AN107/$k.b" <<'PY' || echo "weak107:$k" >> "$SB/anchor-missing"
import sys
f, fa, fb = sys.argv[1:4]
s = open(f, encoding='utf-8').read(); a = open(fa, encoding='utf-8').read(); b = open(fb, encoding='utf-8').read()
assert a and a != b and s.count(a) == 1, (a[:70], s.count(a))
s2 = s.replace(a, b, 1)
assert s2.count(b) == 1 + s.count(b) and len(s2) == len(s) - len(a) + len(b) and (a in b or s2.count(a) == 0)
open(f, 'w', encoding='utf-8').write(s2)
PY
  done
  bash -n "$dst" 2>/dev/null || echo "weak107:$name:syntax" >> "$SB/anchor-missing"
  echo "$dst"
}
ancc107() {   # ancc107 — bad|n|keys over every anchor anc107 has set: each the script's own text, found in it once, its replacement different
  python3 -I - "$DEPLOY107" "$AN107" <<'PY'
import os, sys
s = open(sys.argv[1], encoding='utf-8').read(); d = sys.argv[2]
keys = sorted(f[:-2] for f in os.listdir(d) if f.endswith('.a'))
bad = [k for k in keys if (lambda a, b: not a or a == b or s.count(a) != 1)(open(os.path.join(d, k + '.a'), encoding='utf-8').read(), open(os.path.join(d, k + '.b'), encoding='utf-8').read())]
print('%d|%d|%s' % (len(bad), len(keys), ','.join(bad) or '-'))
PY
}
mutant107() {   # mutant107 NAME KEY… -- RUNNER-ARGS… — a weakened copy run once by run107c (or a held runner named first), its output
                # in MUT107 (kept apart in mutants/), the copy removed after; the caller asserts its case's own observable
  local name="$1" k=() mw; shift
  while [ $# -gt 0 ] && [ "$1" != -- ]; do k+=("$1"); shift; done; [ "${1:-}" = -- ] && shift
  mw="$(weak107 "$name" "${k[@]}")"
  MUT107="$(R107_MUTANT_OUT=1 run107c --script "$mw" "$@")"; MUTT107="$(last107)"; MUTRC107="$(rc107)"
  rm -f "$mw"
}
# ── Every fixture has its undo (R6's keep106/back106/mark106/cut106, reused on this release's files; v3 A9's snapshot). ──
SNAP107="$R107/snap/pre"   # the 107-pre snapshot A9 restores: the book (by 104's dsnap104), migration.log and the config files, each by sha256
snap107() {   # snap107 — taken once, at the end of 107-pre
  rm -rf "$SNAP107"; mkdir -p "$SNAP107"; dsnap104 pre107 >/dev/null 2>&1
  local f; for f in "$DATA/migration.log" "$DATA/config.json" "$DATA/kyc_config.json" "$PD/data/config.json"; do
    printf '%s\n' "$f" >> "$SNAP107/paths"
    if [ -e "$f" ]; then cp -p "$f" "$SNAP107/$(sha256sum <<<"$f" | cut -c1-16)"; sha256sum < "$f" | cut -c1-64 >> "$SNAP107/shas"; else echo absent >> "$SNAP107/shas"; fi
  done
}
snapeq107() {   # snapeq107 — same, or the files that differ from the snapshot (by sha256)
  local i=0 f w out=""
  while IFS= read -r f; do i=$((i+1)); w="$(sed -n "${i}p" "$SNAP107/shas")"
    if [ "$w" = absent ]; then [ -e "$f" ] && out="$out,${f##*/}:present"; else [ "$(sha256sum < "$f" 2>/dev/null | cut -c1-64)" = "$w" ] || out="$out,${f##*/}"; fi
  done < "$SNAP107/paths"
  echo "${out#,}" | sed 's/^$/same/'
}
reset107() {   # reset107 LABEL — v3 A9: 5.18.106 installed as Git has it (P106, asserted the script's baseline), the 107-pre snapshot back
               # (the book by drest104, the files by sha256), the deploy's records moved aside, the quarantine's --check; then asserted:
               # bf29cbd's record, no 092 file on disk, the snapshot's files, MG-G1 absent by a --check of the script
  local lab="$1" i f o dr
  [ "$P106" = "$B107" ] || { check "P106:$P106" "B107:$B107" "$lab A9 the reset installs the script's baseline"; return 1; }
  install_commit "$P106" >/dev/null 2>&1; chown -R 1000:1000 "$PD" 2>/dev/null   # the short form: deploy-hybrid writes %h, and the script reads the record against it
  dr="$(drest104 pre107 2>/dev/null)"
  i=0; while IFS= read -r f; do i=$((i+1))
    if [ "$(sed -n "${i}p" "$SNAP107/shas")" = absent ]; then rm -f "$f"; else cp -p "$SNAP107/$(sha256sum <<<"$f" | cut -c1-16)" "$f"; fi
  done < "$SNAP107/paths"
  tidy107 "$lab"; qcheck106 "$lab q"
  fresh107; o="$(run107c --check)"
  check "$(tail -n1 "$PD/.deployed-commit" | tr -cd '0-9a-f')|$dr|$( [ -e "$PD/migrations/092_wa_no_ai.sql" ] && echo 092-on-disk || echo none)|$(snapeq107)|$(has "$o" "  ok    MG-G1 the copy's migration ledger has no 092")|$(checkgo107 "$o")" \
        "$P106|same|same|same|none|same|yes|yesnono0|0|0|samekey|0" \
    "$lab A9 the reset: $P106 installed as Git has it (its record), no 092 on disk, the 107-pre snapshot's book and files back (by sha256), and a --check of the script reads MG-G1 absent and GO with nothing changed"
}
# ── The plants: rh107-seed.php through the INSTALLED release's own classes where one exists, a fixture writer where none does
# (said so in each case) — as the store's user, under the clock, with RH107_RANGE given to that run alone. ──
seed107() {   # seed107 CODE-ROOT OPS-JSON — rh107-seed.php over the live data folder; one line of JSON, or none(<why>)
  local o v="$H107/v/seed-vault.json"
  rm -f "$v"; [ ! -f "$VAULT" ] || { cp "$VAULT" "$v"; chown 1000:1000 "$v"; chmod 600 "$v"; }
  [ -f "$v" ] || { printf '{}\n' > "$v"; chown 1000:1000 "$v"; chmod 600 "$v"; }
  o="$(cd "$H107/v" && setpriv --reuid 1000 --regid 1000 --clear-groups -- env DN_VAULT_FILE="$v" RH107_RANGE="${PR107#+}" LD_PRELOAD="$CLKPRE104" RH104_CLOCK_FILE="$CLKF104" \
        http_proxy= https_proxy= HTTP_PROXY= HTTPS_PROXY= NO_PROXY='*' "$PHPBIN" -d log_errors=0 "$H107/rh107-seed.php" "$1" "$DATA" "$2" 2>"$H107/v/seed.err")"
  rm -f "$v"
  case "$o" in '{'*) printf '%s' "$o" ;; *) echo "none($(head -c 160 "$H107/v/seed.err" | tr -c 'A-Za-z0-9 :._(),-' '_'))" ;; esac
}
sd107() { python3 -I -c 'import json,sys; d=json.loads(sys.argv[1]); print(d.get(sys.argv[2], ""))' "$1" "$2" 2>/dev/null; }   # sd107 JSON KEY — one field of a seed's answer
pinroot107() { echo "$H107/pin/$P"; }   # the pin's code root (Git's tree, in this rehearsal's folder): the seed's writer where the live code is 5.18.106's
mkdir -p "$H107/pin"; git -C "$REPO" archive "$P107F" "$P" | tar -xf - -C "$H107/pin"; chown -R 1000:1000 "$H107/pin"
# ── 107's own numbers: +2567001070NN, beside the chain's 104 and 106 ranges. Proved free here, never assumed (R6's reading on
# this range): no harness of this checkout (5.18.107's own folder aside), no test or fixture of this checkout, of the pin's
# tree or the baseline's in Git, no file of the sandbox's data folders or records, and neither pattern file holds any of
# them — each reading's control the chain's own range, found by the same reading. ──
PR107='+2567001070'
RX107='(^|[^0-9])(\+?256|0)?7001070[0-9]{2}([^0-9]|$)|0700 107 0[0-9]{2}'; RXC107='(^|[^0-9])(\+?256|0)?7001060[0-9]{2}([^0-9]|$)'
ph107() { case "${1:-}" in [0-9][0-9]) printf '%s%s\n' "$PR107" "$1" ;; *) echo "ph107:${1:-}" >> "$SB/anchor-missing"; return 1 ;; esac; }   # ph107 NN
sqx "PRAGMA wal_checkpoint(TRUNCATE)" >/dev/null 2>&1
NH107="$(grep -rlE --exclude-dir=deploy-5.18.107 "$RX107" "$H107_ROOT/scripts/harness" 2>/dev/null | wc -l | tr -d ' ')"
NHC107="$(grep -rlE --exclude-dir=deploy-5.18.107 "$RXC107" "$H107_ROOT/scripts/harness" 2>/dev/null | wc -l | tr -d ' ')"
NT107="$(if [ -d "$H107_ROOT/$P/tests" ]; then grep -rlE "$RX107" "$H107_ROOT/$P/tests" 2>/dev/null | wc -l | tr -d ' '; else echo notests; fi)"
NG107="$(for c107 in "$P107F" "$B107F"; do git -C "$REPO" grep -lE "$RX107" "$c107" -- "$P/tests" 2>/dev/null | wc -l | tr -d ' '; done | paste -sd, -)"
ND107="$(grep -rlaE "$RX107" "$DATA" "$PD/data" "$RECS" 2>/dev/null | wc -l | tr -d ' ')"
NDC107="$(grep -rlaE "$RXC107" "$DATA" 2>/dev/null | wc -l | tr -d ' ')"
NP107="$(cat "$NAMES104" "$FORMS106" 2>/dev/null | grep -cE "$RX107")"
check "$NH107|$( [ "$(n103 "$NHC107")" -ge 1 ] && echo read)|$NT107|$NG107|$ND107|$( [ "$(n103 "$NDC107")" -ge 1 ] && echo read)|$NP107" "0|read|0|0,0|0|read|0" \
  "107-infra j 107's own numbers ($PR107<NN>) proved free: in no harness ($NHC107 file(s) of 106's range found by the same reading), no test or fixture of this checkout, none of the pin's or the baseline's in Git, no file of the sandbox's data or records ($NDC107 of 106's range found there), and in neither pattern file"
for i107 in $(seq -w 0 99); do n107="$(ph107 "$i107")"; grep -qxF -- "$n107" "$NAMES104" || printf '%s\n' "$n107" >> "$NAMES104"; done
{ cat "$R106/phones106.txt" 2>/dev/null; for i107 in $(seq -w 0 99); do ph107 "$i107"; done; } | grep . | sort -u > "$R107/phones107.txt"
FORMS107="$R107/forms107.pat"; PH4_107="$(q105 phones4 "$R107/phones107.txt" "$FORMS107")"
NM107F="$R107/names107.pat"; { cat "$NM106F" 2>/dev/null; echo 'RH107SECRET-'; } | grep . | sort -u > "$NM107F"; chmod 600 "$NM107F"
check "$(kv107 "$PH4_107" n)|$( [ "$(kv107 "$PH4_107" forms)" = "$(( 5 * $(n103 "$(kv107 "$PH4_107" n)") ))" ] && echo five)|$(stat -c %a "$FORMS107")|$(grep -cxF 'RH107SECRET-' "$NM107F")|$(grep -cxF 'RH106SECRET-' "$NM107F")" \
      "$(( $(grep -c . "$R106/phones106.txt" 2>/dev/null) + 100 ))|five|600|1|1" \
  "107-infra k the 100 numbers of 107's range beside 106's and the chain's, each in its four written forms and its last nine digits, into a 0600 pattern file; RH107SECRET- added to the names pattern beside RH106SECRET- (v2 A18)"
# NA-CA1's sentinel (NA_SENTINEL_1, S:861) is the one seeded value outside the range: the script's own constant, read here,
# never typed; 107-6 plants a row on it and 107-10 asserts it gone (v2 A18).
NAS107="$(sv107 "" NA_SENTINEL_1)"
check "$( case "$NAS107" in +256[0-9]*|256[0-9]*) echo read ;; *) echo "unread:${#NAS107}" ;; esac)" "read" "107-infra l NA-CA1's sentinel read from the script (the one planted value outside 107's range; swept at 107-10)"

# ── The EXIT trap: this release's triggers first, then the chain's own cleanup (the trap as the chain set it, or as a
# development runner restored it). ──
cleanup107() {
  [ -n "${SB:-}" ] && rm -f "$SB/web/.noboot107" "$SB/.plant092touch107" "$SB/.alter092touch107" "$SB/.boot107" "$SB/.rbfail107" "$SB/.rbfail107.seen" "$SB/web/.redirect107" "$SB/web/.redirect107lock"
  [ -n "${DATA:-}" ] && [ -f "$DATA/migration.log" ] && chmod u+w "$DATA/migration.log" 2>/dev/null
  return 0
}
TRAP0_107="$(trap -p EXIT | sed -E "s/^trap -- '(.*)' EXIT\$/\1/")"
case "$TRAP0_107" in ''|trap*|*"'"*) echo "trap107:unread" >> "$SB/anchor-missing" ;; *) trap -- "cleanup107; $TRAP0_107" EXIT ;; esac
check "$( [ -n "$TRAP0_107" ] && trap -p EXIT | grep -cF -- "cleanup107; $TRAP0_107")|$(LEFT107)|$( [ "$(grep -c . "$SB/anchor-missing" 2>/dev/null)" = "$AM0_107" ] && echo noanchormiss)" "1|none|noanchormiss" \
  "107-infra m the EXIT trap runs cleanup107 first, then the chain's (${TRAP0_107:-unread}); every 107 hook inserted with its anchor found (no anchor-missing line since 107-infra began); nothing armed"
EVO0_107="$(evo_calls)"   # Evolution's requests before 107-pre: every later one is a run's or a worker's, each asserted where it is meant
# ── 107's readers of the live book and its log (rh107q.py, read-only: mode=ro, query_only) and the keep folder every plant
# keeps what it changes in (rh107-seed.php refuses an undo whose keep is not there). The book is checkpointed first, so a
# reader sees what the WAL holds. ──
ledger107() { sqx "PRAGMA wal_checkpoint(TRUNCATE)" >/dev/null 2>&1; q107 ledger "$DATA/plugin.sqlite3" 2>/dev/null || echo unread; }    # mig092=N wa_no_ai=table|renamed|none
ledgerx107() { sqx "PRAGMA wal_checkpoint(TRUNCATE)" >/dev/null 2>&1; q107 ledgerx "$DATA/plugin.sqlite3" 2>/dev/null || echo unread; }  # … cks092= at092= sc092= cks071= sentinel= rows=
log107() { q107 log092 "$DATA/migration.log" 2>/dev/null || echo unread; }                                                               # MG-CA2's reading of migration.log
bookdig107() { sqx "PRAGMA wal_checkpoint(TRUNCATE)" >/dev/null 2>&1; q107 bookdig "$DATA/plugin.sqlite3" 2>/dev/null || echo unread; }  # the book's logical digest
ms107() { sqx "PRAGMA wal_checkpoint(TRUNCATE)" >/dev/null 2>&1; q107 sched "$DATA/plugin.sqlite3" 2>/dev/null || echo unread; }   # budget=… entries=… (master's own record)
logsha107() { sha256sum < "$DATA/migration.log" 2>/dev/null | cut -c1-16; }
KEEP107="$R107/kept"; mkdir -p "$KEEP107"; chown 1000:1000 "$KEEP107"; chmod 700 "$KEEP107"
kept107() { find "$KEEP107" -mindepth 1 2>/dev/null | wc -l | tr -d ' '; }   # what is still kept: 0 once every plant is undone
# seedk107 ROOT OP TAG [JSON-FIELDS] — one op of rh107-seed.php with this rehearsal's keep folder and TAG; its line
seedk107() { seed107 "$1" "[{\"op\":\"$2\",\"keep\":\"$KEEP107\",\"tag\":\"$3\"${4:+,$4}}]"; }
B0X_107="$(bookdig107)"; L0X_107="$(logsha107)"
check "$( [ "${#B0X_107}" = 16 ] && echo read)|$( [ "${#L0X_107}" = 16 ] && echo read)|$(kept107)|$(ledger107)" "read|read|0|mig092=0 wa_no_ai=none" \
  "107-infra n this rehearsal's own readers answer on the live book and its log (the book's digest, migration.log's sha256), the keep folder is empty, and the ledger holds neither 092 nor wa_no_ai"
# ════════════════════════════════════════════════════════════════════════════
# 107-pre — production's state before 5.18.107 (v2 A15, v3), through the real writers
# ════════════════════════════════════════════════════════════════════════════
echo; echo "== 107-pre. production's state before the deploy: $V106 serving, the chase on in claim mode, its --after-only PASSED, the quarantine's --check PASSED; the settings text planted; A6's values read again =="
check "$(live)|$(inst_ver)|$(ledger107)" "$P106|$V106|mig092=0 wa_no_ai=none" \
  "107-pre0 what 106-7z handed over: $P106 ($V106) serving, and the book holds neither 092 nor wa_no_ai (this rehearsal's own reading of the ledger and the tables)"
OPRE107="$(sf104c --tty yes on chase)"
# bf29cbd tools/sales_followup.php (the question, and "ON <step>: <keys>")
check "$(sfrc104)|$(has "$OPRE107" 'Type yes to switch the quote chase on:')|$(grep -c '^ON chase: ' <<<"$OPRE107")|$(srtcsv106 "$(kv105 "$(lk105)" on)")|$(kv105 "$(lk105)" held)" \
      "0|yes|1|ack,chase,list,report,tracking,voice-measure|0" \
  "107-pre1 on chase through the installed tool, yes typed at the terminal (§F step 2, as the owner took it on 22 Nov): ON, exit 0; steps 1–6 on by this rehearsal's ladder reading, nothing held — sends in claim mode"
master106 "107-pre2"
aft106 "107-pre3" PASSED $L2ON107
qcheck106 "107-pre4"
SETTXT107="$(seed107 "$PD" '[{"op":"settext","text":"RH107SECRET- the rehearsal'"'"'s settings text: stay on the plugin'"'"'s own subjects."}]')"
check "$(sd107 "$SETTXT107" op)|$(sd107 "$SETTXT107" fixture)|$(sd107 "$SETTXT107" places)" "settext|1|k,s" \
  "107-pre5 the settings text: an RH107SECRET- text written by a fixture through the release's own PluginConfig::saveOverrides (said so: v9's bytes are production's and are never planted, v2 A3) — in kyc_config.json and the store row"
# A2 — A6's gated values, read again in the sandbox: one --check, GO, its four lines parsed and set against the script's literals
# (M0 measured them first, outside the rehearsal; the derivation records M0's).
fresh107; OA2_107="$(run107c --check)"
A2UG107="$(sed -n 's/^  Uganda          live [0-9.]*: //p' <<<"$OA2_107" | head -1)|$(sed -n '/^  Uganda          live /{n;s/^                  pin  [0-9.]*: //p;}' <<<"$OA2_107" | head -1)"
A2NA107="$(sed -n 's/^  no-AI alert     live [0-9.]*: \(.*\) (Uganda), \(.*\) (South Sudan)$/\1|\2/p' <<<"$OA2_107" | head -1)|$(sed -n '/^  no-AI alert     live /{n;s/^                  pin  [0-9.]*: \(.*\) (Uganda), \(.*\) (South Sudan)$/\1|\2/p;}' <<<"$OA2_107" | head -1)"
check "$(checkgo107 "$OA2_107")|$A2UG107|$A2NA107|$(has "$OA2_107" '  ok    A6 South Sudan stays exactly as it is')" \
      "yesnono0|0|0|samekey|0|$(sv107 "" UG_SIGNATURE)|$(sv107 "" UG_PIN_SIGNATURE)|$(sv107 "" EP_NOAI_DONE)|$(sv107 "" EP_NOAI_DONE)|$(sv107 "" EP_NOAI_PIN_UG)|$(sv107 "" EP_NOAI_DONE)|yes" \
  "107-pre6 A2: a --check under the clock, GO with nothing changed; A6's lines read the pin's five-event Uganda run as UG_PIN_SIGNATURE, the live one as UG_SIGNATURE, the assistant-off alert done as unknown on 5.18.106 (both profiles) and on the pin as South Sudan, and left pending by the pin as Uganda — the values M0 measured (root docs/76 §T.15)"
echo "  note  RH-NOTE A2 (for the derivation's record): in this sandbox A6 read UG_PIN=$(cut -d'|' -f2 <<<"$A2UG107"); no-AI alert live $(cut -d'|' -f1-2 <<<"$A2NA107" | tr '|' ' '), pin $(cut -d'|' -f3-4 <<<"$A2NA107" | tr '|' ' ')"
snap107
check "$(snapeq107)|$(grep -c . "$SNAP107/paths")|$( [ -d "$R104/dsnap/pre107/data" ] && echo snap)" "same|4|snap" \
  "107-pre7 the snapshot every first-deploy reset restores (v3 A9): the book and the data folder by 104's dsnap104, migration.log and the three settings files by sha256"

# ════════════════════════════════════════════════════════════════════════════
# 107-1 — a plain 5.18.106 tree, and the mixed trees, at BASE_DIFFERS (S:12399): the tools reading and the put-back paragraph
# ════════════════════════════════════════════════════════════════════════════
echo; echo "== 107-1. BASE_DIFFERS and the put-back paragraph's tools reading, each tree planted and put back (root docs/76 §T.11; v2 A11 S5, S6) =="
# Each case is a --check (MODE deploy, CHECK_ONLY): BASE_DIFFERS stops it in stage A before A6, before the pin is staged and
# before any question, nothing changed. Every tree is planted from Git (the pin's file, or bf29cbd's) or by a one-byte edit,
# said so, kept first and put back after, checked put back by sha256.
BW107="${B107:0:7}'s"
TOOLS107="lib/SalesFollowupLadder.php tools/sales_followup.php lib/BurstMerge.php tools/set_config.php"
pinf107() { git -C "$REPO" show "$P107F:$P/$1" > "$PD/$1" && chown 1000:1000 "$PD/$1"; }   # pinf107 REL — the pin's file installed over 5.18.106's
edit107() { printf '\n// RH107SECRET- an edit by hand\n' >> "$PD/$1"; }                         # edit107 REL — one line appended: neither the pin's nor bf29cbd's
keepall107() { local f; for f in "$@"; do keep106 "t107-$(tag106 "$f")" "$PD/$f" >/dev/null; done; }
backall107() { local f out=""; for f in "$@"; do out="$out$(back106 "t107-$(tag106 "$f")")"; done; echo "$out"; }   # sames, one per file
bd107() {   # bd107 LABEL OUTPUT — the BASE_DIFFERS stop: its text, Nothing was changed, exit 1, never the question, nothing changed
  check "$(has "$2" "  STOP: NO-GO: the container's record says $B107 ($BV107), but these installed files are not $BV107's:")$(has "$2" 'Nothing was changed.')$(went107 "$2")|$(rc107)|$(quiet107)" \
        "yesyesnono|1|$P106|0|0|samekey|$D0_107" "$1 the --check stopped at BASE_DIFFERS (S:12399): NO-GO naming the files, Nothing was changed, exit 1, never the question; nothing changed"
}
pbt107() { echo "lib/SalesFollowupLadder.php $1, tools/sales_followup.php $2, lib/BurstMerge.php $3, tools/set_config.php $4."; }   # the tools reading as pb_para says it
D0_107="$(data94)"
# 1a — the plain 5.18.106 tree: one changed file outside the tools edited (lib/DishNetAiBrain.php, in $V107's delta and bf29cbd's tree)
check "$(grep -cw 'dishnet-hybrid-sudan/lib/DishNetAiBrain.php' <<<"$CHANGED107")|$(git -C "$REPO" cat-file -e "$B107F:$P/lib/DishNetAiBrain.php" && echo base)" "1|base" "107-1a0 lib/DishNetAiBrain.php is one of the files $V107 changes, and bf29cbd has it"
keepall107 lib/DishNetAiBrain.php; edit107 lib/DishNetAiBrain.php
fresh107; O1A107="$(run107c --check)"; bd107 "107-1a1" "$O1A107"
check "$(has "$O1A107" "$(pbt107 "$BW107" "$BW107" absent "$BW107")")|$(has "$O1A107" '  The switch-off — it is refused (undeclared):')|$(has "$O1A107" '  The drain reading — it is refused (an unknown step):')|$(has "$O1A107" ' A refusal changes nothing and saves nothing.')|$(grep -cF "it leaves '0'" <<<"$O1A107")|$(grep -c '^  quoted_no_prices_enabled cannot be read (the run.s latest reading of the ladder.s keys): .* it is refused (unmanaged):$' <<<"$O1A107")|$(grep -c '^  connection_quote_enabled cannot be read (the run.s latest reading of the ladder.s keys): .* it is refused (unmanaged):$' <<<"$O1A107")|$(has "$O1A107" 'switch NOT READ (the pin is not yet staged)')|$(has "$O1A107" '(Stage A refused before the pin was staged:')" \
      "yes|yes|yes|yes|0|1|1|yes|yes" \
  "107-1a2 the plain $BV107 tree: the tools reading names the ladder, the tool and set_config $BW107 and BurstMerge absent; the switch-off refused (undeclared), the drain refused (an unknown step), a refusal changes nothing and saves nothing, it leaves '0' never said; K and Q cannot be read, each line refused (unmanaged); NOT READ, the pin not yet staged"
check "$(backall107 lib/DishNetAiBrain.php)|$(installed107 "$B107F")" "same|0" "107-1a3 the edited file put back, by sha256: $BV107's tree whole again"
# 1b — only lib/SalesFollowupLadder.php the pin's
keepall107 lib/SalesFollowupLadder.php; pinf107 lib/SalesFollowupLadder.php
fresh107; O1B107="$(run107c --check)"; bd107 "107-1b1" "$O1B107"
check "$(has "$O1B107" "$(pbt107 "the pin's" "$BW107" absent "$BW107")")|$(has "$O1B107" "  The switch-off — it runs, and leaves '0':")|$(has "$O1B107" 'Where the switch-off does not run')|$(has "$O1B107" '  The drain reading — it is refused (an unknown step):')|$(has "$O1B107" ' A refusal changes nothing and saves nothing.')|$(grep -c ' it is refused (unmanaged):$' <<<"$O1B107")" \
      "yes|yes|no|yes|yes|2" \
  "107-1b2 only the ladder the pin's: the switch-off worded as running, leaving '0', with no repair sentence beside it; the drain refused; the refusal sentence on §K's rule line because the drain is refused; K and Q refused (unmanaged)"
check "$(backall107 lib/SalesFollowupLadder.php)|$(installed107 "$B107F")" "same|0" "107-1b3 put back"
# 1c — tools/set_config.php the pin's, the ladder and the tool bf29cbd's; and its two weakened copies
keepall107 tools/set_config.php; pinf107 tools/set_config.php
fresh107; O1C107="$(run107c --check)"; bd107 "107-1c1" "$O1C107"
check "$(has "$O1C107" "$(pbt107 "$BW107" "$BW107" absent "the pin's")")|$(grep -c ' it runs:$' <<<"$O1C107")|$(grep -c 'take nothing as saved\. A refusal changes nothing' <<<"$O1C107")|$(has "$O1C107" '  The switch-off — it is refused (undeclared):')|$(has "$O1C107" '  The drain reading — it is refused (an unknown step):')" \
      "yes|2|0|yes|yes" \
  "107-1c2 set_config the pin's, the ladder and the tool $BW107: the K and Q lines worded runs, with no refusal sentence after them; the switch-off and the drain refused"
anc107 pb2way 'case "$s" in pin) ws="runs"' 'case "$l$s" in pinpin) ws="runs"'                       # a two-way mixed wording: set_config runs only with the ladder
anc107 pbkqlad '  case "$s" in pin) ws="runs" ;; base) ws="is refused (unmanaged)" ;; *) ws="may be refused" ;; esac' '  case "$l" in pin) ws="runs" ;; base) ws="is refused (unmanaged)" ;; *) ws="may be refused" ;; esac'   # K/Q worded from the ladder
mutant107 w107-1c-2way pb2way -- --check; C1C2W107="$(grep -c ' it runs:$' <<<"$MUT107")"
mutant107 w107-1c-kqlad pbkqlad -- --check; C1CKL107="$(grep -c ' it runs:$' <<<"$MUT107")|$(grep -c ' it is refused (unmanaged):$' <<<"$MUT107")"
check "$C1C2W107|$C1CKL107" "0|0|2" "107-1c3 its weakened copies caught: one keeping a two-way mixed wording (set_config runs only beside the pin's ladder) words no K/Q line runs; one wording the K/Q lines from the ladder's reading words both refused (unmanaged)"
check "$(backall107 tools/set_config.php)|$(installed107 "$B107F")" "same|0" "107-1c4 put back"
# 1d — tools/set_config.php edited by hand (neither): may be refused, never runs and never is refused; two copies
keepall107 tools/set_config.php; edit107 tools/set_config.php
fresh107; O1D107="$(run107c --check)"; bd107 "107-1d1" "$O1D107"
check "$(has "$O1D107" "$(pbt107 "$BW107" "$BW107" absent 'neither (an edit by hand)')")|$(grep -c '(the run.s latest reading of the ladder.s keys): .* it may be refused:$' <<<"$O1D107")|$(grep -c '(the run.s latest reading of the ladder.s keys): .* it runs:$' <<<"$O1D107")|$(grep -c '(the run.s latest reading of the ladder.s keys): .* it is refused (unmanaged):$' <<<"$O1D107")" \
      "yes|2|0|0" "107-1d2 set_config edited by hand: it reads neither, and the K and Q lines are worded may be refused — never runs, never is refused"
anc107 pbnbase 'base) ws="is refused (unmanaged)"' 'base|neither) ws="is refused (unmanaged)"'
anc107 pbnpin 'case "$s" in pin) ws="runs"' 'case "$s" in pin|neither) ws="runs"'
mutant107 w107-1d-nbase pbnbase -- --check; C1DNB107="$(grep -c ' it is refused (unmanaged):$' <<<"$MUT107")"
mutant107 w107-1d-npin pbnpin -- --check; C1DNP107="$(grep -c ' it runs:$' <<<"$MUT107")"
check "$C1DNB107|$C1DNP107" "2|2" "107-1d3 its weakened copies caught: one folding neither into $BW107 words both lines refused (unmanaged); one folding it into the pin's words both runs"
check "$(backall107 tools/set_config.php)|$(installed107 "$B107F")" "same|0" "107-1d4 put back"
# 1e — the three files present at bf29cbd removed: the tools reading not made (v2 A11 S6: three not read, BurstMerge absent)
keepall107 lib/SalesFollowupLadder.php tools/sales_followup.php tools/set_config.php; rm -f "$PD/lib/SalesFollowupLadder.php" "$PD/tools/sales_followup.php" "$PD/tools/set_config.php"
fresh107; O1E107="$(run107c --check)"; bd107 "107-1e1" "$O1E107"
check "$(has "$O1E107" "$(pbt107 'not read' 'not read' absent 'not read')")|$(has "$O1E107" '  The switch-off — it may be refused:')|$(has "$O1E107" '  The drain reading — it may be refused:')|$(grep -cF "it leaves '0'" <<<"$O1E107")|$(grep -c ' it may be refused:$' <<<"$O1E107")|$(grep -c "^      cd .* && bash scripts/sales-followup.sh " <<<"$O1E107")" \
      "yes|yes|yes|0|4|2" \
  "107-1e2 the three files bf29cbd has removed: the ladder, the tool and set_config not read, BurstMerge absent; every command, the K and Q lines included, worded may be refused; it leaves '0' never said; both §K commands still printed, each alone"
check "$(backall107 lib/SalesFollowupLadder.php tools/sales_followup.php tools/set_config.php)|$(installed107 "$B107F")" "samesamesame|0" "107-1e3 put back"
# 1f — the ladder the pin's and tools/sales_followup.php removed: the switch-off may be refused, never it leaves '0'; the copy keying it on the ladder alone
keepall107 lib/SalesFollowupLadder.php tools/sales_followup.php; pinf107 lib/SalesFollowupLadder.php; rm -f "$PD/tools/sales_followup.php"
fresh107; O1F107="$(run107c --check)"; bd107 "107-1f1" "$O1F107"
check "$(has "$O1F107" "$(pbt107 "the pin's" 'not read' absent "$BW107")")|$(has "$O1F107" '  The switch-off — it may be refused:')|$(grep -cF "it leaves '0'" <<<"$O1F107")|$(has "$O1F107" ' A refusal changes nothing and saves nothing.')|$(has "$O1F107" " Where the switch-off does not run, the repair is root docs/76 §T.10's known limit")|$(grep -c "^      cd .* && bash scripts/sales-followup.sh " <<<"$O1F107")" \
      "yes|yes|0|yes|yes|2" \
  "107-1f2 the ladder the pin's and the tool removed (not read): the switch-off may be refused, never it leaves '0'; the refusal sentence and the known-limits repair printed; both §K commands, each alone"
anc107 pbladonly 'case "$l:$t" in pin:pin|pin:base)' 'case "$l:$t" in pin:*)'
mutant107 w107-1f-lad pbladonly -- --check
check "$(has "$MUT107" "  The switch-off — it runs, and leaves '0':")" "yes" "107-1f3 its weakened copy caught: keying the switch-off on the ladder alone says it runs and leaves '0'"
check "$(backall107 lib/SalesFollowupLadder.php tools/sales_followup.php)|$(installed107 "$B107F")" "samesame|0" "107-1f4 put back"
# 1g — the pin's tool with lib/BurstMerge.php absent: burst-drain fails without reading anything, never runs
keepall107 tools/sales_followup.php; pinf107 tools/sales_followup.php
fresh107; O1G107="$(run107c --check)"; bd107 "107-1g1" "$O1G107"
check "$(has "$O1G107" "$(pbt107 "$BW107" "the pin's" absent "$BW107")")|$(has "$O1G107" '  The drain reading — it fails without reading anything:')|$(grep -c '  The drain reading — it runs:' <<<"$O1G107")" "yes|yes|0" \
  "107-1g2 the pin's tool with BurstMerge absent: the drain worded as failing without reading anything, never as running"
# 1h — and BurstMerge edited by hand (one byte): neither; may fail, never runs, never fails without reading anything; two copies
keep106 t107-burst "$PD/lib/BurstMerge.php" >/dev/null; git -C "$REPO" show "$P107F:$P/lib/BurstMerge.php" > "$PD/lib/BurstMerge.php"; chown 1000:1000 "$PD/lib/BurstMerge.php"; edit107 lib/BurstMerge.php
fresh107; O1H107="$(run107c --check)"; bd107 "107-1h1" "$O1H107"
check "$(has "$O1H107" "$(pbt107 "$BW107" "the pin's" 'neither (an edit by hand)' "$BW107")")|$(has "$O1H107" "  The drain reading — it may fail (it may also run on bytes that are not the pin's):")|$(grep -c '  The drain reading — it runs:' <<<"$O1H107")|$(grep -c '  The drain reading — it fails without reading anything:' <<<"$O1H107")" "yes|yes|0|0" \
  "107-1h2 the pin's tool with BurstMerge edited by hand: it reads neither, and the drain is worded may fail — never runs, never fails without reading anything"
anc107 pbbabs 'pin:absent) wd="fails without reading anything"' 'pin:absent|pin:neither) wd="fails without reading anything"'
anc107 pbbpin 'case "$t:$b" in pin:pin) wd="runs"' 'case "$t:$b" in pin:pin|pin:neither) wd="runs"'
mutant107 w107-1h-abs pbbabs -- --check; C1HA107="$(has "$MUT107" '  The drain reading — it fails without reading anything:')"
mutant107 w107-1h-pin pbbpin -- --check; C1HP107="$(has "$MUT107" '  The drain reading — it runs:')"
check "$C1HA107|$C1HP107" "yes|yes" "107-1h3 its weakened copies caught: keying fails-without-reading on not-the-pin's, and treating neither as the pin's"
check "$(back106 t107-burst)|$(backall107 tools/sales_followup.php)|$(installed107 "$B107F")|$( [ -e "$PD/lib/BurstMerge.php" ] && echo present || echo absent)" "same|same|0|absent" \
  "107-1h4 put back: the tool $BW107's, BurstMerge absent again (as $BV107's tree has it)"
# 1i — a stage-A mixed-tree refusal: the print-time reading NOT READ (the pin is not yet staged), K and Q cannot be read with their
# lines, the wording from the tools reading, no unbound-variable error under set -u; three copies
keepall107 lib/DishNetAiBrain.php; edit107 lib/DishNetAiBrain.php
fresh107; O1I107="$(run107c --check)"; bd107 "107-1i1" "$O1I107"
check "$(has "$O1I107" 'Read at ')$(has "$O1I107" ': switch NOT READ (the pin is not yet staged); the drain not read (the pin is not yet staged). One reading, not a drain.')|$(grep -c 'unbound variable' <<<"$O1I107")|$(grep -c '^  quoted_no_prices_enabled cannot be read\|^  connection_quote_enabled cannot be read' <<<"$O1I107")" "yesyes|0|2" \
  "107-1i2 the stage-A mixed-tree refusal: the reading NOT READ (the pin is not yet staged), one reading and not a drain; K and Q cannot be read, their lines printed; no unbound-variable error"
anc107 p3bunset 'P3B_PIN=""; P3B_WHY="the pin is not yet staged"   #' ': "the pin is not yet staged"   #'
anc107 pbnotread '  if [ -z "${P3B_PIN:-}" ]; then sw="NOT READ (the pin is not yet staged)"; bd="bd=unread(the pin is not yet staged)"; at="$(date -u +%s)"' '  if [ -z "${P3B_PIN:-}" ]; then sw="off"; bd="bd=unread(the pin is not yet staged)"; at="$(date -u +%s)"'
anc107 pbnokq '    [ "$q" = "off" ] && continue
    kq=' '    continue
    kq='
mutant107 w107-1i-unset p3bunset -- --check; C1IU107="$(grep -c 'unbound variable' <<<"$MUT107")"
mutant107 w107-1i-read pbnotread -- --check; C1IR107="$(has "$MUT107" ': switch NOT READ (the pin is not yet staged);')"
mutant107 w107-1i-nokq pbnokq -- --check; C1IK107="$(grep -c '^  quoted_no_prices_enabled cannot be read\|^  connection_quote_enabled cannot be read' <<<"$MUT107")"
check "$( [ "$(n103 "$C1IU107")" -ge 1 ] && echo unbound)|$C1IR107|$C1IK107" "unbound|no|0" \
  "107-1i3 its weakened copies caught: P3B_PIN and P3B_WHY left undefined before stage A's sites (the unbound-variable error under set -u); a reading other than NOT READ; the K/Q lines dropped"
check "$(backall107 lib/DishNetAiBrain.php)|$(installed107 "$B107F")|$(ancc107)" "same|0|0|$(ls "$AN107"/*.a | wc -l | tr -d ' ')|-" \
  "107-1z put back; $BV107's tree whole; every anchor of 107-1's weakened copies the script's own text, found once, its replacement different (the control of the controls)"
# ════════════════════════════════════════════════════════════════════════════
# 107-2 — MG-G1, QF-G2 and NA-G1 at stage A (root docs/76 §T.11; v2 §B 107-2): the pairing, a partial 092, 092 with other
# bytes. Each a --check refused at stage A, nothing changed; each plant a fixture of rh107-seed.php (said so: no writer
# makes these states), the book kept first and put back after by its digest, migration.log by its sha256.
# ════════════════════════════════════════════════════════════════════════════
echo; echo "== 107-2. MG-G1, QF-G2 and NA-G1 refusing at stage A: the pairing, a partial 092, 092 with other bytes (root docs/76 §T.11, §T.16) =="
B2_107="$(bookdig107)"; L2_107="$(logsha107)"
check "$(ledger107)|$(kept107)" "mig092=0 wa_no_ai=none|0" "107-2-0 the entry: 107-pre's book — no 092 in the ledger, no wa_no_ai — and nothing kept"
# The pairing's two weakened copies: one accepts any pairing, one drops the pairing check (§T.11). Each must read GO where
# the script refuses.
anc107 pairany '    "absent|reset=0 status=partial partial=1 check=1 row=0"|"present|reset=1 status=partial partial=1 check=1 row=0") ;;' \
               '    "absent|reset="?" status=partial partial=1 check=1 row=0"|"present|reset="?" status=partial partial=1 check=1 row=0") ;;'
anc107 pairdrop 'bad="$bad 092-negative-control-pairing(MG-G1:${MG_G1:-not-read};${o%% status=*})" ;;' ': ;;'
# 107-2a — the pairing: 092's five objects with no ledger row. MG-G1 reads 092 absent, neg092 reset=1: NO-GO naming the pairing.
PA107="$(seedk107 "$(pinroot107)" ledger092 pair107 '"what":"unledgered"')"
check "$(sd107 "$PA107" fixture)|$(sd107 "$PA107" objects)|$(ledger107)|$(logsha107)" "1|5|mig092=0 wa_no_ai=table|$L2_107" \
  "107-2a0 the pairing's ground, a FIXTURE (no writer makes it): 092's five objects made by 092's own statements on the live book, with no ledger row and no _schema_checks row; migration.log untouched (no runner ran)"
D94_107="$(data94)"; fresh107; O107="$(run107c --check)"
check "$(has "$O107" "  ok    MG-G1 the copy's migration ledger has no 092")|$(has "$O107" '  negative control (092) reset=1 status=partial partial=1 check=1 row=0')|$(has "$O107" '092-negative-control-pairing(MG-G1:absent;reset=1)')|$(has "$O107" '  wa_no_ai (NA-G1) p1=none ')|$(has "$O107" ' p3=PDOException gate=')" \
      "yes|yes|yes|yes|yes" \
  "107-2a the pairing: MG-G1 reads 092 absent (no ledger row), 092's negative control reads reset=1 (it found wa_no_ai and took it off its own copy), and QF-G2 names the pairing; NA-G1's probe 3 throws at the read, not the rename (§T.16 S-1)"
nogo107 "107-2a'" "NO-GO (QF-G2): the one migration trial" "$O107" "$D94_107" "$P106"
mutant107 pair-any pairany -- --check
check "$(has "$MUT107" 'NO-GO (QF-G2)')|$(has "$MUT107" "  $V107 (check): GO — stage A holds.")|$MUTRC107" "no|yes|0" \
  "107-2a1 a weakened copy that accepts any pairing reads GO over the same book — caught: the script refuses it"
mutant107 pair-drop pairdrop -- --check
check "$(has "$MUT107" 'NO-GO (QF-G2)')|$(has "$MUT107" "  $V107 (check): GO — stage A holds.")|$MUTRC107" "no|yes|0" \
  "107-2a2 a weakened copy that drops the pairing check reads GO over the same book — caught"
PB107="$(seedk107 "$(pinroot107)" ledger092_undo pair107 '"what":"unledgered"')"
check "$(sd107 "$PB107" fixture)|$(ledger107)|$(bookdig107)|$(logsha107)|$(kept107)" "1|mig092=0 wa_no_ai=none|$B2_107|$L2_107|0" \
  "107-2a3 the pairing's ground taken off: the book put back from its keep (SQLite backup), its digest as before 107-2, migration.log unchanged, nothing kept"
# 107-2b and 107-2c — 092 applied by the pin's runner over the live book (the fixture mig092: said so, the book and the log
# kept first), then its _schema_checks row taken out (a partial 092), or its ledger checksum rewritten (other bytes).
for c107 in partial:b checksum:c; do
  w107="${c107%%:*}"; l107="107-2${c107##*:}"
  K1="$(seedk107 "$(pinroot107)" book_keep "mg-$w107")"; K2="$(seedk107 "$(pinroot107)" log_keep "lg-$w107")"
  M1="$(seed107 "$(pinroot107)" '[{"op":"mig092"}]')"
  K3="$(seedk107 "$(pinroot107)" ledger092 "f-$w107" "\"what\":\"$w107\"")"
  X1="$(ledgerx107)"
  if [ "$w107" = partial ]; then v107="$(kv107 "$X1" sc092)"; elif [ "$(kv107 "$X1" cks092)" != "$(sd107 "$M1" cks)" ]; then v107=other; else v107=same; fi
  check "$(sd107 "$K1" fixture)|$(sd107 "$K2" fixture)|$(sd107 "$M1" rows)|$(sd107 "$M1" ok_lines)|$(sd107 "$K3" fixture)|$(kv107 "$X1" mig092)|$v107" \
        "1|1|1|1|1|1|$( [ "$w107" = partial ] && echo missing || echo other)" \
    "${l107}0 a $( [ "$w107" = partial ] && echo 'partial 092' || echo '092 with other bytes'), a FIXTURE: the book and migration.log kept, 092 applied by the pin's MigrationRunner (one ledger row, one OK line), then $( [ "$w107" = partial ] && echo 'its _schema_checks row taken out' || echo 'its ledger checksum rewritten to another md5')"
  D94_107="$(data94)"; fresh107; O107="$(run107c --check)"
  check "$(has "$O107" 'NO-GO (MG-G1): the book holds a 092 that is not whole')|$(has "$O107" "$( [ "$w107" = partial ] && echo 'sc=missing' || echo "mig092=1 cks=$(kv107 "$X1" cks092)")")" "yes|yes" \
    "$l107 MG-G1 refuses $( [ "$w107" = partial ] && echo 'a 092 recorded without its _schema_checks row' || echo 'a 092 recorded with other bytes'), naming what it read"
  nogo107 "$l107'" "NO-GO (MG-G1): the book holds a 092 that is not whole" "$O107" "$D94_107" "$P106"
  U1="$(seedk107 "$(pinroot107)" ledger092_undo "f-$w107" "\"what\":\"$w107\"")"; U2="$(seedk107 "$(pinroot107)" book_back "mg-$w107")"; U3="$(seedk107 "$(pinroot107)" log_back "lg-$w107")"
  check "$(sd107 "$U1" fixture)$(sd107 "$U2" fixture)$(sd107 "$U3" fixture)|$(ledger107)|$(bookdig107)|$(logsha107)|$(kept107)" "111|mig092=0 wa_no_ai=none|$B2_107|$L2_107|0" \
    "${l107}1 put back: the plant undone, the book from its keep (its digest as before 107-2), migration.log byte for byte, nothing kept"
done
check "$(ledger107)|$(bookdig107)|$(logsha107)|$(snapeq107)|$(live)" "mig092=0 wa_no_ai=none|$B2_107|$L2_107|same|$P106" \
  "107-2z the exit: 107-pre's state — no 092, the book and migration.log as they were, the snapshot's files, $P106 serving"

# ════════════════════════════════════════════════════════════════════════════
# 107-3 — NS-G1, NS-B1 and stage A's notes (root docs/76 §T.11; v2 §B 107-3, A13). The places: p <plugin>/data/config.json,
# d <data>/config.json, k <data>/kyc_config.json — planted by rh106-seed.php's fx_file as root (said so: no release writes
# p or d, and 5.18.106's tools refuse these keys) and put back by fx_file_back; s the store row, by rh107-seed.php's
# store_keys107. W.1's switch is one of the ladder's own keys (107-infra b), so in p or d LK-G1 refuses it first (S:7480):
# NS-G1 is shown there with K and Q, and W.1 in p is LK-G1's refusal, recorded as such.
# ════════════════════════════════════════════════════════════════════════════
echo; echo "== 107-3. NS-G1, NS-B1 and stage A's notes: the switches in each place, '0', the store row alone, the texts, NS-B1 mid-run, the settings text, StaffJobsGate =="
B3_107="$(bookdig107)"; NSK107="$R107/nskept"; mkdir -p "$NSK107"; chmod 700 "$NSK107"
fxs107() {   # fxs107 PLACE TAG JSON-SET — a key or keys put into PLACE's file (fx_file, as root); its line
  seed106r "[{\"op\":\"fx_file\",\"place\":\"$1\",\"keep\":\"$NSK107\",\"tag\":\"$2\",\"set\":$3,\"plugin\":\"$PD\"}]"; }
fxb107() { seed106r "[{\"op\":\"fx_file_back\",\"place\":\"$1\",\"keep\":\"$NSK107\",\"tag\":\"$2\",\"plugin\":\"$PD\"}]"; }   # fxb107 PLACE TAG
nskept107() { find "$NSK107" -mindepth 1 2>/dev/null | wc -l | tr -d ' '; }
check "$(sd106 "$(fxs107 k probe '{"rh107_probe":"1"}')" 0 fixture)|$(sd106 "$(fxb107 k probe)" 0 fixture)|$(nskept107)|$(snapeq107)" "True|True|0|same" \
  "107-3-0 the place fixture answers (a probe key into k and out again, the file byte for byte, nothing kept) — the control for every plant below"
# The per-place weakened copies: NS-G1 with one place left out of its judged set (S:6488). Each must read GO where the script refuses.
anc107 nsp "\$ns = \$nsOn(\$doc, ['p', 'd', 'k']);" "\$ns = \$nsOn(\$doc, ['d', 'k']);"
anc107 nsd "\$ns = \$nsOn(\$doc, ['p', 'd', 'k']);" "\$ns = \$nsOn(\$doc, ['p', 'k']);"
anc107 nsk "\$ns = \$nsOn(\$doc, ['p', 'd', 'k']);" "\$ns = \$nsOn(\$doc, ['p', 'd']);"
for pl107 in p d k; do
  set107='{"quoted_no_prices_enabled":"1","connection_quote_enabled":true'"$( [ "$pl107" = k ] && echo ',"ai_burst_merge_enabled":1')"'}'
  want107="quoted_no_prices_enabled in $pl107; connection_quote_enabled in $pl107$( [ "$pl107" = k ] && echo "; ai_burst_merge_enabled in $pl107") reads on"
  X1="$(fxs107 "$pl107" "on-$pl107" "$set107")"
  D94_107="$(data94)"; fresh107; O107="$(run107c --check)"
  check "$(sd106 "$X1" 0 fixture)|$(has "$O107" "NO-GO (NS-G1): $want107")|$(has "$O107" 'no value is read out')" "True|yes|yes" \
    "107-3$pl107 NS-G1 refuses $( [ "$pl107" = k ] && echo "K, Q and W.1" || echo "K and Q") on in $pl107 (a FIXTURE plant: '1', true$( [ "$pl107" = k ] && echo ', 1')), naming keys and the place, never a value"
  nogo107 "107-3$pl107'" "NO-GO (NS-G1): " "$O107" "$D94_107" "$P106"
  mutant107 "ns-no-$pl107" "ns$pl107" -- --check
  check "$(has "$MUT107" 'NO-GO (NS-G1)')|$(has "$MUT107" "  $V107 (check): GO — stage A holds.")|$MUTRC107" "no|yes|0" \
    "107-3$pl107\" a weakened copy that leaves $pl107 out of NS-G1's judged places reads GO over the same plant — caught"
  check "$(sd106 "$(fxb107 "$pl107" "on-$pl107")" 0 fixture)|$(nskept107)|$(snapeq107)" "True|0|same" "107-3$pl107* the plant put back, the file byte for byte"
done
# W.1's switch in p: a ladder key held by a config.json — LK-G1's refusal, before NS-G1 reads (recorded, root docs/76 §C)
X1="$(fxs107 p w1-p '{"ai_burst_merge_enabled":"1"}')"
D94_107="$(data94)"; fresh107; O107="$(run107c --check)"
check "$(sd106 "$X1" 0 fixture)|$(has "$O107" 'NO-GO (LK-G1): 1 of the ladder')|$(has "$O107" 'ai_burst_merge_enabled')|$(has "$O107" 'NO-GO (NS-G1)')" "True|yes|yes|no" \
  "107-3w W.1's switch on in p: LK-G1 refuses it first — a ladder key held by a config.json (S:7480) — so NS-G1 never reads it there; the refusal names the key"
nogo107 "107-3w'" "NO-GO (LK-G1): " "$O107" "$D94_107" "$P106"
check "$(sd106 "$(fxb107 p w1-p)" 0 fixture)|$(nskept107)|$(snapeq107)" "True|0|same" "107-3w* put back"
echo "  note  RH-NOTE 107-3w (for the owner, root docs/76 §C): W.1's switch is a ladder key, so in p or d LK-G1's refusal stands where §T.11 names NS-G1's; nothing is changed either way"
# 107-3o — off and set, never judged on: K 0, Q 'off' and W.1 '0' in k (§K's own pre-rollback step leaves '0'); K and W.1 on in
# the store row alone (no reader reads it); the texts set: two in k, W.1's window in the store row. GO, with each note.
X1="$(fxs107 k off-k '{"quoted_no_prices_enabled":0,"connection_quote_enabled":"off","ai_burst_merge_enabled":"0","quoted_reply_text":"RH107SECRET- a quoted reply","no_ai_alert_cooldown_hours":"6"}')"
X2="$(seed107 "$PD" '[{"op":"store_keys107","keys":{"quoted_no_prices_enabled":"1","ai_burst_merge_enabled":"1","ai_burst_merge_window_minutes":5}}]')"
fresh107; O107="$(run107c --check)"
check "$(sd106 "$X1" 0 fixture)|$(sd107 "$X2" fixture)|$(checkgo107 "$O107")|$(has "$O107" '  ok    NS-G1 quoted_no_prices_enabled (K), connection_quote_enabled (Q) and ai_burst_merge_enabled (W.1) read off in every place their readers read')" \
      "True|1|yesnono0|0|0|samekey|0|yes" \
  "107-3o '0', 0 and 'off' present in k read off: NS-G1 ok, the --check GO with nothing changed (FIXTURE plants in k and in the store row)"
check "$(has "$O107" 'NS-G1 the store row holds quoted_no_prices_enabled in s; ai_burst_merge_enabled in s reading on — reported, never judged')|$(has "$O107" 'NS-G1 the texts and W.1'"'"'s window, set per place, by key name')|$(has "$O107" 'quoted_reply_text in k; no_ai_alert_cooldown_hours in k; ai_burst_merge_window_minutes in s')" \
      "yes|yes|yes" \
  "107-3o' the store row alone: reported, never judged; the texts and W.1's window noted per place by key name, no_ai_alert_cooldown_hours among them — never a value"
X3="$(seed107 "$PD" '[{"op":"store_keys107_undo","keys":["quoted_no_prices_enabled","ai_burst_merge_enabled","ai_burst_merge_window_minutes"]}]')"
check "$(sd106 "$(fxb107 k off-k)" 0 fixture)|$(sd107 "$X3" fixture)|$(nskept107)|$(snapeq107)|$(bookdig107)" "True|1|0|same|$B3_107" "107-3o* put back: the file byte for byte, the store row's keys taken out (the book's digest as before 107-3)"
# 107-3c — the rule's positive control: a weakened copy whose off values include '1' — the in-memory '1' then reads off, and NS-G1
# refuses NOT ESTABLISHED, nothing planted
anc107 nsctl "\$nsOff = function (\$v): bool { return \$v === null ||" "\$nsOff = function (\$v): bool { return \$v === '1' || \$v === null ||"
mutant107 ns-ctl nsctl -- --check
check "$(has "$MUT107" "NO-GO (NS-G1): NOT ESTABLISHED — THE SWITCH RULE'S POSITIVE CONTROL DID NOT HOLD (nsctl=bad)")|$MUTRC107" "yes|1" \
  "107-3c a weakened copy that counts '1' as off: the in-memory control fails and NS-G1 refuses NOT ESTABLISHED — the control has teeth"
# 107-3b — NS-B1: Q turned on in k while the script holds at Type DEPLOY (a FIXTURE writer, said so: 5.18.106's tools refuse the key).
D94_107="$(data94)"
csb1run107 107-3b "$DEPLOY107" -- fxs107 k nsb1 '{"connection_quote_enabled":"1"}'
# deploy-5.18.107.sh: ns_check NS-B1 in qf_keep_and_baseline, before LK-B1's comparison and the copy
check "$HOLDSAW107|$HOLDRC107|$(has "$HOLDOUT107" 'NO-GO (NS-B1): connection_quote_enabled in k reads on')|$(has "$HOLDOUT107" 'it was turned on during this run, after stage A. Nothing was copied.')|$(has "$HOLDOUT107" 'NO-GO (LK-B1)')|$(has "$HOLDOUT107" '  copy            at ')|$(rc107)|$(live)|$(installed107 "$B107F")" \
      "yes|0|yes|yes|no|no|1|$P106|0" \
  "107-3b NS-B1: Q on in k after stage A (a FIXTURE plant at Type DEPLOY), refused before LK-B1's comparison and before the copy — 'Nothing was copied', exit 1, $P106 serving, every file $V107 changes still $BV107's"
tidy107 107-3b
check "$(sd106 "$(fxb107 k nsb1)" 0 fixture)|$(nskept107)|$(data94)|$(states107)|$(backups107)" "True|0|$D94_107|0|0" \
  "107-3b* put back: the data folder's digest as before the run; the refused run's state file and LK key moved into its case folder, so the next deploy is a first deploy"
# 107-3t — stage A's settings-text note (A3): the planted RH107SECRET- text reads NOT v9 (107-pre6's --check); the v9 branch shown
# once by a READER CONTROL — a copy whose recorded prefix is the planted text's — never a production reading
TX107="$(sd107 "$SETTXT107" sha)"
anc107 satxt 'SA_TXT_V9="fca936af7760"' "SA_TXT_V9=\"${TX107:0:12}\""
check "$(has "$OA2_107" 'NOT v9 as the workers read it')|${#TX107}" "yes|16" \
  "107-3t the settings text (planted, RH107SECRET-) reads NOT v9 as the workers read it, in capitals (107-pre6's --check) — never the text"
mutant107 sa-v9 satxt -- --check
check "$(has "$MUT107" "v9 as the workers read it — its sha256 prefix is the recorded ${TX107:0:12}")|$(has "$MUT107" 'NOT v9 as the workers read it')|$MUTRC107" "yes|no|0" \
  "107-3t' a READER CONTROL (a copy pinning the planted text's prefix, one line differing): the same reading says v9 — the branch exists and the comparison is live"
# 107-3g — StaffJobsGate::applies false: the profile set to South Sudan in d (a FIXTURE, said so): SA's capitals note, no refusal
X1="$(fxs107 d sjg '{"tenant_profile":"south-sudan"}')"
fresh107; O107="$(run107c --check)"
check "$(sd106 "$X1" 0 fixture)|$(has "$O107" "SA STAFFJOBSGATE::APPLIES IS FALSE ON THIS BOOK'S SETTINGS, AS THE WORKERS READ THEM")|$(checkgo107 "$O107")" "True|yes|yesnono0|0|0|samekey|0" \
  "107-3g StaffJobsGate::applies false (South Sudan's profile in d): SA's note in capitals, and the --check still GO — a note, never a refusal"
check "$(sd106 "$(fxb107 d sjg)" 0 fixture)|$(nskept107)|$(snapeq107)" "True|0|same" "107-3g* put back"
check "$(ancc107)|$(snapeq107)|$(bookdig107)|$(ledger107)|$(live)" "0|$(ls "$AN107"/*.a | wc -l | tr -d ' ')|-|same|$B3_107|mig092=0 wa_no_ai=none|$P106" \
  "107-3z the exit: 107-pre's state — every key absent again (the files byte for byte, the book's digest), no 092, $P106 serving; every anchor so far the script's own text, once"
# ════════════════════════════════════════════════════════════════════════════
# D1 — the first deploy, PASSED, and everything judged after it on the same deploy (v3 §B D1: 107-4, 107-6 seen=1)
# ════════════════════════════════════════════════════════════════════════════
echo; echo "== 107-4. D1: $V107 over $BV106 — the first deploy, PASSED: QF-G2's trial and NA-G1, neg092, MG-CA1, NA-CA1, MG-CA2, R7 applied, LK-CA2 one row more, the state lines =="
tidy107 "107-4-pre"
P107R="$PD"   # the seed's code root once 5.18.107 serves: the installed release's own classes (rh107-seed.php never boots the store)
BDOFF107="cd $REPO && bash scripts/sales-followup.sh set ai_burst_merge_enabled 0"; BDDR107="cd $REPO && bash scripts/sales-followup.sh --check burst-drain"   # the script's BD_OFF_CMD and BD_DRAIN_CMD as printed (S:7341-7342)
check "$(sv107 "" QF_WRAPPER)|$(grep -cF 'BD_OFF_CMD="cd $REPO && bash $QF_WRAPPER set ai_burst_merge_enabled 0"' "$DEPLOY107")|$(grep -cF 'BD_DRAIN_CMD="cd $REPO && bash $QF_WRAPPER --check burst-drain"' "$DEPLOY107")" "scripts/sales-followup.sh|1|1" \
  "107-4-0 the two §K commands as the script assigns them, over its own wrapper (read from the script, never typed)"
check "$(states107)|$(backups107)|$(keysum107)|$(left107)|$(inst_ver)|$(ledger107)|$(kept107)" "0|0|nokey|0|$V106|mig092=0 wa_no_ai=none|0" \
  "107-4a a first deploy: no state file, rollback record, backup or LK key of $V107's in its run folder; none of the $NA107 files it adds on disk; $V106 installed; no 092 and no wa_no_ai; nothing kept"
fresh107; O4_107="$(run107c --answer DEPLOY)"; T4_107="$(last107)"
check "$(fails "$O4_107")$(has "$O4_107" "  $V107 (deploy): PASSED.")|$(rc107)|$(went107 "$O4_107")|$(live)|$(installed107 "$P107F")|$(ledger107)|$(cat "$R107/evo/run-$T4_107.n" 2>/dev/null)" \
      "0yes|0|yesyes|$P107|0|mig092=1 wa_no_ai=table|0 nocron" \
  "107-4b D1, DEPLOY typed, under the clock: PASSED (exit 0), $P107 serving, every file it changes installed as it has them, 092 applied by the first boot (V1's request to the stand-in) and wa_no_ai made; Evolution not called"
check "$(has "$O4_107" "  ok    MG-G1 the copy's migration ledger has no 092")|$(has "$O4_107" 'extra=092_wa_no_ai.sql:ok:OK:7')|$(has "$O4_107" '  wa_no_ai (NA-G1) p1=none ')|$(has "$O4_107" ' p3=PDOException gate=')|$(has "$O4_107" '  negative control (092) reset=0 status=partial partial=1 check=1 row=0')|$(has "$O4_107" 'applied, ok, its own migration.log OK with its 7 statements')" \
      "yes|yes|yes|yes|yes|yes" \
  "107-4c stage A: MG-G1 absent; QF-G2's trial applies 092 (ok, OK, 7 statements); NA-G1's probes, probe 3 throwing at the read (§T.16 S-1); 092's negative control reset=0 with CHECK named (§T.16 S-2); QF-G2 ok"
check "$(has "$O4_107" '  ok    MG-CA1 092 on a fresh copy after the copy')|$(has "$O4_107" '  ok    NA-CA1 wa_no_ai reads the way the worker reads it')|$(has "$O4_107" "  ok    MG-CA2 migration.log holds 092's OK line (1) and no PARTIAL line for it")|$(has "$O4_107" 'moved by exactly 092, applied at a boot')|$(has "$O4_107" 'but one added, exactly 092_wa_no_ai.sql:5/5')|$(has "$O4_107" 'the deploy run makes no poll')" \
      "yes|yes|yes|yes|yes|yes" \
  "107-4d after the copy: MG-CA1, NA-CA1 and MG-CA2 ok; R7 reads the ledger moved by exactly 092, applied at a boot; LK-CA2 every _schema_checks row unchanged but one added, exactly 092's 5/5; ME-CA1 a note in the deploy run (no poll)"
AT4_107="$(kv107 "$(ledgerx107)" at092)"; CU4_107="$(sget107 "$STATE107" QF_COPY_UNIX)"
check "$(sget107 "$STATE107" MG_G1)|$(sget107 "$STATE107" MG_CA2_SEEN)|$(case "$CU4_107" in [0-9]*) echo read ;; *) echo "unread:$CU4_107" ;; esac)|$( [ "$(n103 "$AT4_107")" -ge "$(n103 "$CU4_107")" ] && echo after)|$(has "$O4_107" '092 was applied before this copy began')" \
      "absent|1|read|after|no" \
  "107-4e the state file: MG_G1=absent, MG_CA2_SEEN=1, QF_COPY_UNIX recorded; 092's applied_at ($AT4_107) at or after it — no dated MG-CA1 note"
master106 "107-4f"
aft107 "107-4g" PASSED $L2ON107
check "$(has "$AFT107" 'ME-CA1 PASS —')|$(has "$AFT107" 'errors master caught are not judged (plugin.log:')|$(has "$AFT107" "  ok    MG-CA2 migration.log holds 092's OK line")" "yes|yes|yes" \
  "107-4h the --after-only after master's cycle: ME-CA1 PASS with its 'not judged (plugin.log: …)' clause; MG-CA2's OK line still there"
B4_107="$(bookdig107)"; L4_107="$(logsha107)"; S4_107="$(sha256sum < "$STATE107" | cut -c1-16)"; LX4_107="$(ledgerx107)"

# ════════════════════════════════════════════════════════════════════════════
# 107-6 on D1 (MG_CA2_SEEN=1) — each an --after-only, its plant kept and put back after (the book by its digest, the log and the
# state file by sha256). The branches judged are the same in --after-only and a redeploy (v3 §C item 6).
# ════════════════════════════════════════════════════════════════════════════
echo; echo "== 107-6. on D1 (MG_CA2_SEEN=1): the failures after the copy, MG-CA2's missing line, the straddle note's conditions, the ladder edited after the copy =="
ao107() { idle104; fresh107; AO107="$(run107c --after-only)"; AOT107="$(last107)"; AORC107="$(rc107)"; }   # one --after-only under the clock: AO107, AOT107, AORC107
STK107="$R107/stk"; mkdir -p "$STK107"; chmod 700 "$STK107"
sdrop107() {   # sdrop107 TAG KEY… — the state file kept as TAG, then every KEY= line taken out (each asserted present first)
  local t="$1" k; shift; cp -p "$STATE107" "$STK107/$t"
  for k in "$@"; do grep -q "^$k=" "$STATE107" || echo "sdrop107:$t:$k" >> "$SB/anchor-missing"; sed -i "/^$k=/d" "$STATE107"; done
}
sback107() { cp -p "$STK107/$1" "$STATE107" && rm -f "$STK107/$1"; }   # sback107 TAG — put back as kept
back4_107() {   # back4_107 LABEL — D1's state after 107-4: the book by its digest, migration.log by sha256, the state file's two 092 records, nothing kept
  check "$(bookdig107)|$(logsha107)|$(sget107 "$STATE107" MG_G1)|$(sget107 "$STATE107" MG_CA2_SEEN)|$(kept107)|$(ls -A "$STK107" | grep -c .)|$(live)" "$B4_107|$L4_107|absent|1|0|0|$P107" \
    "$1 put back: the book by its digest and migration.log by sha256 as 107-4 left them, the state file's MG_G1=absent and MG_CA2_SEEN=1, nothing kept"
}
declare -F standin_page >/dev/null || echo "standin_page:missing" >> "$SB/anchor-missing"   # the chain's request to the stand-in (5.18.90's harness)
# 6a — wa_no_ai renamed: NA-CA1 FAIL (probe 1 throws), MG-CA1 FAIL; the lead in capitals: no AI reply; the rollback command stands alone
X1="$(seedk107 "$P107R" ledger092 r6a '"what":"rename"')"
ao107
check "$(sd107 "$X1" fixture)|$AORC107|$(has "$AO107" 'NA-CA1 wa_no_ai does not read the way the worker reads it')|$(has "$AO107" 'MG-CA1 092 on a fresh copy after the copy is not whole (')|$(has "$AO107" '  NO UGANDA CUSTOMER GETS AN AI REPLY: THE ASSISTANT-OFF LIST')|$(has "$AO107" 'a failure after the copy means the rollback')|$(grep -cxF "      $EXP_ROLLBACK107" <<<"$AO107")" \
      "1|1|yes|yes|yes|yes|1" \
  "107-6a wa_no_ai renamed (a FIXTURE, the book kept first): NA-CA1 FAIL (probe 1 throws), MG-CA1 FAIL; the FAILED summary leads with the AI-reply consequence in capitals; the rollback command alone on its line"
check "$(sd107 "$(seedk107 "$P107R" ledger092_undo r6a '"what":"rename"')" fixture)" "1" "107-6a' the table's name back (the book from its keep)"; back4_107 "107-6a*"
# 6b — another md5 over a whole, readable table: MG-CA1 FAIL; NA-CA1 holds; the lead names the checksum and the redeploy consequence, and AI replies go on
X1="$(seedk107 "$P107R" ledger092 r6b '"what":"checksum"')"
ao107
check "$(sd107 "$X1" fixture)|$AORC107|$(has "$AO107" 'MG-CA1 092 on a fresh copy after the copy is not whole (')|$(has "$AO107" '  ok    NA-CA1 wa_no_ai reads the way the worker reads it')|$(has "$AO107" '  092 is not shown whole in the live migration ledger')|$(has "$AO107" 'MG-G1 refuses every redeploy')|$(has "$AO107" '(NA-CA1): AI replies go on.')|$(has "$AO107" 'NO UGANDA CUSTOMER GETS AN AI REPLY')" \
      "1|1|yes|yes|yes|yes|yes|no" \
  "107-6b 092's ledger row with another md5 (a FIXTURE): MG-CA1 FAIL, NA-CA1 holds; the lead names the checksum and that MG-G1 refuses every redeploy, and says AI replies go on — never that they stopped"
check "$(sd107 "$(seedk107 "$P107R" ledger092_undo r6b '"what":"checksum"')" fixture)" "1" "107-6b' the checksum put back"; back4_107 "107-6b*"
# 6c — MG_CA2_SEEN=1, the OK line removed, no trim marker: the note that does not name the halving. Copies: one ignores MG_CA2_SEEN
# (the absent branch: NOT ESTABLISHED naming the missing record), one names the halving there
anc107 vread '    v="$(sed -n '"'"'s/^MG_CA2_SEEN=//p'"'"' "$STATE" 2>/dev/null | tail -n1)"' '    v=""'
anc107 halvalways '(MG_CA2_SEEN=1), and it is gone since: $( [ -n "$mk" ] && echo' '(MG_CA2_SEEN=1), and it is gone since: $( [ -z "" ] && echo'
K1="$(seedk107 "$P107R" log_keep l6c)"; K2="$(seedk107 "$P107R" log_drop l6c '"match":"ok092"')"
ao107
check "$(sd107 "$K2" removed)|$AORC107|$(fails "$AO107")|$(has "$AO107" 'the log'"'"'s first line is no trim marker, so halving is not the cause')|$(has "$AO107" 'MG-CA2 NOT ESTABLISHED')" "1|0|0|yes|no" \
  "107-6c MG_CA2_SEEN=1 and 092's OK line gone with no trim marker (a FIXTURE): MG-CA2's note says the deploy read it and halving is not the cause — never NOT ESTABLISHED, never a FAIL"
mutant107 c6c-vread vread -- --after-only
check "$(has "$MUT107" 'MG-CA2 NOT ESTABLISHED — NO OK LINE FOR 092, AND THE STATE FILE HOLDS NO MG_CA2_SEEN RECORD')|$(has "$MUT107" 'halving is not the cause')" "yes|no" \
  "107-6c' a weakened copy that ignores MG_CA2_SEEN takes the absent branch and prints NOT ESTABLISHED naming the missing record — caught"
mutant107 c6c-halv halvalways -- --after-only
check "$(has "$MUT107" "is the runner's trim marker — 071_dpo_payments.sql's checksum WARNING")" "yes" "107-6c\" a weakened copy that names the halving with no marker — caught"
check "$(sd107 "$(seedk107 "$P107R" log_back l6c)" fixture)" "1" "107-6c* migration.log back"; back4_107 "107-6c**"
# 6d — the 071 halving, by the runner itself: 071's ledger checksum other (a FIXTURE: production's state, every boot logs 071's
# WARNING), migration.log filled with fictitious lines naming no migration until it is just over 256 KiB, then ONE logging boot
# through the stand-in (UTC): the marker on line 1, 092's OK line gone, 071's WARNING after. The note names both. Copies: one
# ignores MG_CA2_SEEN and the marker (NOT ESTABLISHED), one treats MG_CA2_SEEN=1 as 0 (NOT ESTABLISHED). The control (no sighting,
# no marker, NOT ESTABLISHED) is D6's.
anc107 mkread 'case "$l1" in "["*"] --- earlier entries trimmed ---") mk=1;' 'case "$l1" in "["*"] --- earlier entries trimmed -X-") mk=1;'
anc107 seen1as0 '      0) note "MG-CA2 NOT ESTABLISHED — NO OK LINE FOR 092, AND STAGE R OF THE DEPLOY READ NONE EITHER' '      0|1) note "MG-CA2 NOT ESTABLISHED — NO OK LINE FOR 092, AND STAGE R OF THE DEPLOY READ NONE EITHER'
K0="$(seedk107 "$P107R" book_keep b6d)"; K1="$(seedk107 "$P107R" ledger071 c6d)"; K2="$(seedk107 "$P107R" log_keep l6d)"; K3="$(seedk107 "$P107R" log_fill l6d '"over":262144')"
LG6D_107="$(log107)"
check "$(sd107 "$K1" fixture)|$(kv107 "$LG6D_107" ok)|$( [ "$(n103 "$(kv107 "$LG6D_107" okoff)")" -lt 131072 ] && echo under)|$( [ "$(n103 "$(kv107 "$LG6D_107" size)")" -gt 262144 ] && echo over)|$(kv107 "$LG6D_107" owner)|$(kv107 "$LG6D_107" mk)" \
      "1|1|under|over|1000|0" \
  "107-6d0 before the boot: 071's checksum other (a FIXTURE), migration.log filled ($(sd107 "$K3" lines) fictitious lines) to $(kv107 "$LG6D_107" size) bytes, 092's OK line at byte $(kv107 "$LG6D_107" okoff) (in the half the runner drops), the file uid 1000's, no marker"
STP6D_107="$(standin_page customer_login)"; LG6D_107="$(log107)"
check "$STP6D_107|$(kv107 "$LG6D_107" mk)|$(kv107 "$LG6D_107" ok)|$( [ "$(n103 "$(kv107 "$LG6D_107" w071)")" -ge 1 ] && echo w071)|$( [ "$(n103 "$(kv107 "$LG6D_107" mkt)")" -ge "$(n103 "$AT4_107")" ] && echo after)" "200|1|0|w071|after" \
  "107-6d1 ONE request to the stand-in (a boot, UTC): the runner halves the log — its trim marker on line 1, stamped after 092's applied_at; 092's OK line gone; 071's WARNING after (no until loop: a second boot would be a failure of the plant)"
ao107
check "$AORC107|$(fails "$AO107")|$(has "$AO107" "the log's first line is the runner's trim marker — 071_dpo_payments.sql's checksum WARNING is logged on every boot, so the runner halves the log within hours")|$(has "$AO107" 'MG-CA2 NOT ESTABLISHED')" "0|0|yes|no" \
  "107-6d MG_CA2_SEEN=1 and the runner's own halving: MG-CA2's note names 071's per-boot WARNING and the halving — never NOT ESTABLISHED"
mutant107 c6d-both vread mkread -- --after-only
check "$(has "$MUT107" 'MG-CA2 NOT ESTABLISHED')|$(has "$MUT107" "is the runner's trim marker")" "yes|no" "107-6d' a weakened copy that ignores both MG_CA2_SEEN and the marker prints NOT ESTABLISHED — caught"
mutant107 c6d-1as0 seen1as0 -- --after-only
check "$(has "$MUT107" 'STAGE R OF THE DEPLOY READ NONE EITHER (MG_CA2_SEEN=0)')|$(has "$MUT107" "is the runner's trim marker")" "yes|no" "107-6d\" a weakened copy that treats MG_CA2_SEEN=1 as 0 prints NOT ESTABLISHED — caught"
check "$(sd107 "$(seedk107 "$P107R" ledger071_undo c6d)" fixture)|$(sd107 "$(seedk107 "$P107R" log_back l6d)" fixture)|$(kv107 "$(ledgerx107)" cks071)" "1|1|$(kv107 "$LX4_107" cks071)" \
  "107-6d* 071's checksum and migration.log put back (ledger071_undo, asserted)"
check "$(sd107 "$(seedk107 "$P107R" book_back b6d)" fixture)" "1" "107-6d** the book from its keep (whatever the stand-in's boot wrote)"
back4_107 "107-6d***"
# 6e — MG_CA2_SEEN taken out of the state file (three stamps): a marker at or after applied_at reads the note; one before it, and no
# marker, read NOT ESTABLISHED naming the missing record. Copies: one accepts any marker with no stamp comparison (caught by the
# second), one ignores the marker here (caught by the first).
anc107 anymark '        if [ -n "$mk" ] && [ -n "$mkt" ] && [ -n "$au" ] && [ "$mkt" -ge "$au" ]; then' '        if [ -n "$mk" ]; then'
anc107 nomark '        if [ -n "$mk" ] && [ -n "$mkt" ] && [ -n "$au" ] && [ "$mkt" -ge "$au" ]; then' '        if [ -n "" ] && [ -n "$mk" ]; then'
sdrop107 s6e MG_CA2_SEEN
for st107 in after:60 before:-60 none:x; do
  w107="${st107%%:*}"; o107="${st107##*:}"
  K1="$(seedk107 "$P107R" log_keep "l6e-$w107")"
  if [ "$w107" = none ]; then K2="$(seedk107 "$P107R" log_drop "l6e-$w107" '"match":"ok092"')"; else K2="$(seedk107 "$P107R" log_mark "l6e-$w107" "\"offset_s\":$o107")"; fi
  ao107
  case "$w107" in
    after) check "$AORC107|$(has "$AO107" 'the state file holds no MG_CA2_SEEN record; the log'"'"'s first line is a trim marker stamped (read as UTC) at or after 092'"'"'s applied_at')|$(has "$AO107" 'MG-CA2 NOT ESTABLISHED')" "0|yes|no" \
             "107-6e1 no MG_CA2_SEEN record, a trim marker stamped 60 s after 092's applied_at (a FIXTURE, UTC): the note naming the halving"
           mutant107 c6e-nomark nomark -- --after-only
           check "$(has "$MUT107" 'MG-CA2 NOT ESTABLISHED')" "yes" "107-6e1' a weakened copy that ignores the marker here prints NOT ESTABLISHED — caught" ;;
    before) check "$(has "$AO107" 'MG-CA2 NOT ESTABLISHED — NO OK LINE FOR 092, AND THE STATE FILE HOLDS NO MG_CA2_SEEN RECORD')|$(has "$AO107" "the log's trim marker is stamped before 092's applied_at")" "yes|yes" \
              "107-6e2 a marker stamped 60 s before applied_at: NOT ESTABLISHED, naming the missing record and the marker's stamp"
            mutant107 c6e-anymark anymark -- --after-only
            check "$(has "$MUT107" 'MG-CA2 NOT ESTABLISHED')|$(has "$MUT107" 'stamped (read as UTC) at or after')" "no|yes" "107-6e2' a weakened copy that accepts any marker with no stamp comparison prints the note — caught" ;;
    none) check "$(has "$AO107" 'MG-CA2 NOT ESTABLISHED — NO OK LINE FOR 092, AND THE STATE FILE HOLDS NO MG_CA2_SEEN RECORD')|$(has "$AO107" "the log's first line is no trim marker")" "yes|yes" \
            "107-6e3 no marker at all: NOT ESTABLISHED, naming the missing record and no trim marker" ;;
  esac
  check "$(sd107 "$(seedk107 "$P107R" log_back "l6e-$w107")" fixture)" "1" "107-6e-$w107* migration.log back"
done
sback107 s6e; back4_107 "107-6e*"
# 6f — the state file without MG_G1 (an older one) and no OK line: NOT ESTABLISHED naming the missing record, never the redeploy note
sdrop107 s6f MG_G1; K1="$(seedk107 "$P107R" log_keep l6f)"; K2="$(seedk107 "$P107R" log_drop l6f '"match":"ok092"')"
ao107
check "$(has "$AO107" 'MG-CA2 NOT ESTABLISHED — THE STATE FILE HOLDS NO MG_G1 RECORD (an older state file)')|$(has "$AO107" 'a redeploy: 092 was applied by an earlier deploy')" "yes|no" \
  "107-6f the state file holds no MG_G1 and 092's OK line is gone: NOT ESTABLISHED naming the missing record — never the redeploy note"
check "$(sd107 "$(seedk107 "$P107R" log_back l6f)" fixture)" "1" "107-6f' migration.log back"; sback107 s6f; back4_107 "107-6f*"
# 6g — 092's installed bytes altered after the copy, beside a planted 092 WARNING: R1 FAIL, MG-CA2 names the WARNING as not judged,
# no straddle note. Copy: the note keyed on MG-CA1 alone, without R1 — prints the note, caught.
anc107 stradmg1 '    if [ -n "$ok1" ] && [ -n "$ok2" ] && [ "$r1" = "ok" ]; then' '    if [ -n "$ok1" ]; then'
anc107 stradnona '    if [ -n "$ok1" ] && [ -n "$ok2" ] && [ "$r1" = "ok" ]; then' '    if [ -n "$ok1" ] && [ "$r1" = "ok" ]; then'
MF107="$PD/migrations/092_wa_no_ai.sql"; cp -p "$MF107" "$STK107/mf092"; printf '%s\n' '-- RH107SECRET- a byte the pin does not have' >> "$MF107"
K1="$(seedk107 "$P107R" log_keep l6g)"; K2="$(seedk107 "$P107R" log_add l6g '"line":"warning092"')"
ao107
check "$AORC107|$(has "$AO107" 'R1 installed files that differ:')|$(has "$AO107" "MG-CA2 migration.log holds 092's checksum WARNING (1) — not judged here: R1 reads the installed migrations/092_wa_no_ai.sql other than the pin's")|$(has "$AO107" 'the tar straddle')" "1|yes|yes|no" \
  "107-6g 092's installed bytes altered after the copy (a FIXTURE, one comment line) beside a planted WARNING: R1 FAIL, MG-CA2 names the WARNING as not judged beside R1 — no straddle note"
mutant107 c6g-mg1 stradmg1 -- --after-only
check "$(has "$MUT107" 'the tar straddle')" "yes" "107-6g' a weakened copy that keys the straddle note on MG-CA1 alone, without R1, prints it — caught"
cp -p "$STK107/mf092" "$MF107" && rm -f "$STK107/mf092"
check "$(sd107 "$(seedk107 "$P107R" log_back l6g)" fixture)|$(installed107 "$P107F")" "1|0" "107-6g* 092's installed file and migration.log back"
back4_107 "107-6g**"
# 6h — NA-CA1's first sentinel planted active (a data-only FIXTURE) beside the WARNING: MG-CA1, R1 hold; NA-CA1 FAIL (probe 1 lists it),
# no straddle note, MG-CA2 names the WARNING as not judged; the lead is NA-CA1's (dat). Copy: NA-CA1 dropped from the note's condition.
X1="$(seedk107 "$P107R" ledger092 n6h "\"what\":\"sentinel\",\"phone\":\"$NAS107\"")"; K1="$(seedk107 "$P107R" log_keep l6h)"; K2="$(seedk107 "$P107R" log_add l6h '"line":"warning092"')"
check "$(sd107 "$X1" fixture)|$(kv107 "$(ledgerx107)" sentinel)" "1|1" "107-6h0 NA-CA1's first sentinel planted active (a FIXTURE: the script's own constant, a number nobody can hold)"
ao107
check "$(has "$AO107" '  ok    MG-CA1 092 on a fresh copy after the copy')|$(has "$AO107" 'R1 installed files that differ:')|$(has "$AO107" 'NA-CA1 wa_no_ai lists a number nobody can hold')|$(has "$AO107" 'the tar straddle')|$(has "$AO107" "checksum WARNING (1) — not judged here: NA-CA1 failed or was not read; ")|$(has "$AO107" '  NA-CA1 failed: the assistant-off list reads, but its first probe')" \
      "yes|no|yes|no|yes|yes" \
  "107-6h MG-CA1 and R1 hold; NA-CA1 FAIL (probe 1 lists the sentinel), no straddle note, MG-CA2 names the WARNING as not judged; the lead is NA-CA1's: the list reads, its rows are wrong"
mutant107 c6h-nona stradnona -- --after-only
check "$(has "$MUT107" 'the tar straddle')" "yes" "107-6h' a weakened copy that drops NA-CA1 from the note's condition prints it — caught"
# 6i — the same with wa_no_ai renamed as well: NA-CA1 and MG-CA1 FAIL, no straddle note (no copy claimed: MG-CA1's objects term covers it)
X2="$(seedk107 "$P107R" ledger092 r6i '"what":"rename"')"
ao107
check "$(sd107 "$X2" fixture)|$(has "$AO107" 'NA-CA1 wa_no_ai does not read the way the worker reads it')|$(has "$AO107" 'MG-CA1 092 on a fresh copy after the copy is not whole (')|$(has "$AO107" 'the tar straddle')" "1|yes|yes|no" \
  "107-6i the sentinel and the rename together: NA-CA1 and MG-CA1 FAIL, no straddle note"
check "$(sd107 "$(seedk107 "$P107R" ledger092_undo r6i '"what":"rename"')" fixture)|$(sd107 "$(seedk107 "$P107R" ledger092_undo n6h '"what":"sentinel"')" fixture)|$(sd107 "$(seedk107 "$P107R" log_back l6h)" fixture)" "1|1|1" "107-6i' the rename, the sentinel and the WARNING taken off"
back4_107 "107-6i*"
# 6j — the ladder edited after the copy (one byte, said so): R1 FAIL, the ladder read 'neither', the switch-off worded 'may be refused',
# never 'it leaves '0''; both §K commands printed. Copy: 'it leaves '0'' whatever the ladder reads — caught.
anc107 wlzero '*) wl="may be refused" ;; esac' "*) wl=\"runs, and leaves '0'\" ;; esac"
LF107="$PD/lib/SalesFollowupLadder.php"; cp -p "$LF107" "$STK107/lad"; printf '\n// RH107SECRET- a byte the pin does not have\n' >> "$LF107"
ao107
check "$AORC107|$(has "$AO107" 'R1 installed files that differ:')|$(has "$AO107" '  The switch-off — it may be refused:')|$(has "$AO107" "it runs, and leaves '0'")" \
      "1|yes|yes|no" \
  "107-6j the ladder edited after the copy (a FIXTURE): R1 FAIL; the put-back paragraph words the switch-off 'may be refused', never 'it leaves '0''"
check "$( [ "$(grep -cxF "      $BDOFF107" <<<"$AO107")" -ge 1 ] && echo off)|$( [ "$(grep -cxF "      $BDDR107" <<<"$AO107")" -ge 1 ] && echo drain)" "off|drain" \
  "107-6j' both of §K's commands printed, each alone on its line"
mutant107 c6j-zero wlzero -- --after-only
check "$(has "$MUT107" "it runs, and leaves '0'")" "yes" "107-6j\" a weakened copy that says it leaves '0' whatever the ladder reads — caught"
cp -p "$STK107/lad" "$LF107" && rm -f "$STK107/lad"
check "$(installed107 "$P107F")" "0" "107-6j* the ladder back as the pin has it"; back4_107 "107-6j**"
aft107 "107-6z" PASSED $L2ON107
check "$(ancc107)" "0|$(ls "$AN107"/*.a | wc -l | tr -d ' ')|-" "107-6zz every anchor so far the script's own text, once"
# ════════════════════════════════════════════════════════════════════════════
# 107-7 on D1 — ME-CA1 (W.7) and ME-CA2 against master's own record (root docs/76 §T.11; v3 A10's pollrun107). Each plant
# (plugin.log, master_schedule's row, master_budget_seconds) kept first and put back after; master106 and a PASSED
# --after-only at the end.
# ════════════════════════════════════════════════════════════════════════════
echo; echo "== 107-7. on D1: ME-CA1's caught-error half from plugin.log, the poll (a later cycle, no cycle, a re-dispatch), an unreadable schedule, the NOT YET summary; ME-CA2 =="
CU7_107="$(sget107 "$STATE107" COPY_AT)"
TZ7_107="$(cd "$H107/v" && setpriv --reuid 1000 --regid 1000 --clear-groups -- "$PHPBIN" -r 'require $argv[1] . "/lib/timezone.php"; echo dn_tz();' "$PD" 2>/dev/null)"
check "$(case "$CU7_107" in [0-9]*) echo read ;; *) echo "unread:$CU7_107" ;; esac)|$(case "$TZ7_107" in */*) echo zone ;; *) echo "unread:$TZ7_107" ;; esac)" "read|zone" \
  "107-7-0 the deploy's COPY_AT from its state file, and the zone master stamps [master] lines in (the installed lib/timezone.php's dn_tz(), called with no config as cron/master.php calls it: $TZ7_107)"
PLOG107="$PD/data/plugin.log"; MS0_107="$(ms107)"
mstamp107() { TZ="$TZ7_107" date -d "@$1" '+%Y-%m-%d %H:%M:%S'; }   # mstamp107 EPOCH — a [master] line's stamp in master's own zone
# 7a — [master] lines planted (a FIXTURE in master's format, said so): a cycle complete before the copy, then the four follow-up jobs
# run after it and a complete cycle — ME-CA1's control holds, 0 ERROR; then an ERROR of followup_send after that cycle: FAIL
cp -p "$PLOG107" "$STK107/plog" 2>/dev/null || : > "$STK107/plog.absent"
{ printf '[%s] [master] RUN followup_close\n' "$(mstamp107 $((CU7_107 - 120)))"; printf '[%s] [master] Cycle complete in 1s\n' "$(mstamp107 $((CU7_107 - 60)))"
  for j107 in followup_close followup_scan followup_run followup_send; do printf '[%s] [master] RUN %s\n' "$(mstamp107 $((CU7_107 + 60)))" "$j107"; done
  printf '[%s] [master] Cycle complete in 2s\n' "$(mstamp107 $((CU7_107 + 62)))"; } >> "$PLOG107"
chown 1000:1000 "$PLOG107"
ao107
check "$AORC107|$(has "$AO107" 'ME-CA1 PASS —')|$(has "$AO107" 'caught errors judged from plugin.log: 0 ERROR after the straddling cycle')|$(has "$AO107" 'errors master caught are not judged')" "0|yes|yes|no" \
  "107-7a [master] lines planted (a FIXTURE in master's own format and zone): ME-CA1's log half holds its control and judges 0 ERROR after the straddling cycle; PASS"
printf '[%s] [master] ERROR followup_send: RH107SECRET- a thrown error\n' "$(mstamp107 $((CU7_107 + 90)))" >> "$PLOG107"
ao107
check "$AORC107|$(has "$AO107" 'ERROR line(s) of the four follow-up jobs in')|$(has "$AO107" 'caught errors judged from plugin.log: 1 ERROR after the straddling cycle — its FAIL above')" "1|yes|yes" \
  "107-7a' a followup_send ERROR after that cycle (planted): ME-CA1's log half FAILs, and the verdict line's clause names it"
if [ -e "$STK107/plog.absent" ]; then rm -f "$PLOG107" "$STK107/plog.absent"; else cp -p "$STK107/plog" "$PLOG107" && rm -f "$STK107/plog"; fi
check "$(ls -A "$STK107" | grep -c .)" "0" "107-7a* plugin.log back as it was"
# 7b — the poll. The plant: followup_send at (L, -1), L the clock's now, after the copy, with no later entry (master_schedule's row
# through the store's save(), the row kept first). The script waits ("ME-CA1 waiting up to"); pollrun107 acts 1 s after the line.
clkfwd107() {   # clkfwd107 SECONDS — the rehearsal's clock moved forward (the offset file, never backward, its mode kept)
  local o; o="$(head -n1 "$CLKF104" 2>/dev/null)"; case "$o" in ''|*[!0-9-]*) echo "clkfwd107:unread" >> "$SB/anchor-missing"; return 1 ;; esac
  printf '%s\n' "$((o + $1))" > "$CLKF104.c107" && cat "$CLKF104.c107" > "$CLKF104" && rm -f "$CLKF104.c107"
}
schedL107() { seedk107 "$P107R" sched "$1" '"set":{"followup_send":{"ago_s":0,"duration_ms":-1}}'; }   # schedL107 TAG — the (L, -1) plant
schedat107() { seed107 "$P107R" "[{\"op\":\"sched\",\"keep\":\"$KEEP107\",\"tag\":\"$1\",\"set\":{\"$2\":{\"ago_s\":$3,\"duration_ms\":$4}}}]"; }   # schedat107 TAG JOB AGO DURATION
for c107 in cycle nocycle missed; do
  K1="$(schedL107 "s7b-$c107")"
  case "$c107" in
    cycle)   pollrun107 "107-7b-$c107" "$DEPLOY107" -- schedat107 "a7b-$c107" event_processor -30 5 ;;
    missed)  pollrun107 "107-7b-$c107" "$DEPLOY107" -- schedat107 "a7b-$c107" followup_send -30 5 ;;
    nocycle) K0="$(seedk107 "$P107R" budget g7b '"seconds":0')"
             check "$(sd107 "$K0" budget)" "0" "107-7b0 master_budget_seconds 0 in the store's kyc_config row (a small budget, said so: the poll's bound is then 360 s)"
             pollrun107 "107-7b-$c107" "$DEPLOY107" -- clkfwd107 420 ;;
  esac
  T7_107="$(secs107)"
  case "$c107" in
    cycle)   check "$HOLDSAW107|$HOLDRC107|$(rc107)|$(has "$HOLDOUT107" 'ME-CA1 FAIL —')|$(has "$HOLDOUT107" 'never returned to master (duration_ms -1), and a later cycle has begun')" "yes|0|1|yes|yes" \
               "107-7b1 (L, -1) then a later cycle written while the poll waits: ME-CA1 FAIL — followup_send never returned and a later cycle began" ;;
    missed)  check "$HOLDSAW107|$HOLDRC107|$(rc107)|$(has "$HOLDOUT107" 'ran again between two reads of the poll (a newer last_run): the poll missed the window')" "yes|0|3|yes" \
               "107-7b2 (L, -1) then followup_send re-dispatched between two reads: NOT YET, the poll missed the window" ;;
    nocycle) check "$HOLDSAW107|$HOLDRC107|$(rc107)|$(has "$HOLDOUT107" 'master did not start a new cycle within the poll'"'"'s bound: followup_send still read duration_ms -1')|$(has "$HOLDOUT107" "  $V107 (after-only): NOT YET — ")|$(has "$HOLDOUT107" 'run it again after')|$(grep -cxF "      $EXP_AFTER107" <<<"$HOLDOUT107")" \
                   "yes|0|3|yes|yes|yes|1" \
               "107-7b3 (L, -1) and no new cycle within the bound (the clock moved past it, said so): NOT YET, master did not start one; the summary NOT YET (exit 3), 'run it again after', the --after-only command alone"
             check "$(sd107 "$(seedk107 "$P107R" budget_back g7b)" budget)" "$(kv107 "$MS0_107" budget)" "107-7b3' master_budget_seconds back as it was" ;;
  esac
  check "$( [ "$T7_107" -lt 120 ] && echo quick)|$(sd107 "$(seedk107 "$P107R" sched_back "s7b-$c107")" fixture)" "quick|1" \
    "107-7b-$c107* the run took ${T7_107} s (no real wait at the bound); master_schedule's row back byte for byte"
  [ "$c107" = nocycle ] || rm -f "$KEEP107/a7b-$c107.meta" "$KEEP107/a7b-$c107.orig"   # the action's own keep, overtaken by the row's
done
# 7c — an unreadable schedule (a FIXTURE: the row made text that does not decode): the capitals note, never a PASS, a note
K1="$(seedk107 "$P107R" sched_raw s7c '"raw":"RH107 not json {"')"
ao107
check "$(sd107 "$K1" fixture)|$(has "$AO107" "ME-CA1 NOT ESTABLISHED — MASTER'S OWN RECORD COULD NOT BE READ FROM THE LIVE STORE")|$(has "$AO107" 'ME-CA1 PASS —')" "1|yes|no" \
  "107-7c master_schedule's row unreadable (a FIXTURE): ME-CA1 NOT ESTABLISHED in capitals — never a PASS; exit status $AORC107 (recorded)"
check "$(sd107 "$(seedk107 "$P107R" sched_back s7c)" fixture)" "1" "107-7c* the row back byte for byte"
# 7d — ME-CA2 on one of its seven: (L, -1) then a later cycle — FAIL; (L, -1) with no later entry — the note; and an (L, -1) on an entry
# outside the seven (notify_retry, said so) with a later cycle — no ME-CA2 FAIL line, as the known limit says
for c107 in fail note outside; do
  j107=quote_chase; [ "$c107" = outside ] && j107=notify_retry
  K1="$(seedk107 "$P107R" sched "s7d-$c107" "\"set\":{\"$j107\":{\"ago_s\":$( [ "$c107" = note ] && echo 0 || echo 30),\"duration_ms\":-1}$( [ "$c107" = note ] || echo ',"event_processor":{"ago_s":0,"duration_ms":5}')}")"
  ao107
  case "$c107" in
    fail) check "$AORC107|$(has "$AO107" 'ME-CA2 FAIL — quote_chase started after the copy and never returned to master')" "1|yes" "107-7d1 ME-CA2: quote_chase at (L, -1) and a later cycle begun: FAIL" ;;
    note) check "$(has "$AO107" 'quote_chase running since')|$(has "$AO107" '(no later cycle yet)')|$(has "$AO107" 'ME-CA2 FAIL')" "yes|yes|no" "107-7d2 ME-CA2: quote_chase at (L, -1) with no later entry: the note, running since … (no later cycle yet)" ;;
    outside) check "$(has "$AO107" 'ME-CA2 FAIL')|$(has "$AO107" 'ME-CA2 the seven other jobs whose code')" "no|yes" "107-7d3 an (L, -1) on notify_retry, outside the seven, with a later cycle: no ME-CA2 FAIL line (the known limit), the note still prints" ;;
  esac
  check "$(sd107 "$(seedk107 "$P107R" sched_back "s7d-$c107")" fixture)" "1" "107-7d-$c107* the row back byte for byte"
done
master106 "107-7z0"
aft107 "107-7z" PASSED $L2ON107
check "$(kv107 "$(ms107)" budget)|$(kept107)" "$(kv107 "$MS0_107" budget)|0" "107-7zz the exit: master_budget_seconds as before 107-7, nothing kept, a PASSED --after-only"
# ════════════════════════════════════════════════════════════════════════════
# 107-8 part one, on D1 — every rollback answered anything but ROLLBACK (v3 §B D1.5): nothing changed by any of them. The
# switches are turned on and off by the commands the script itself prints, run as printed, each alone.
# ════════════════════════════════════════════════════════════════════════════
echo; echo "== 107-8 (part one). on D1: the rollback answered anything but ROLLBACK — W.1 on, NOT READ, TD7 (K, then Q), the counts, W.1 off as printed, §M with the real worker, control 0; then K and Q off as printed =="
rbno107() {   # rbno107 [ANSWER] [--env K=V] — a --rollback under the clock answered ANSWER (no by default): not confirmed, nothing changed;
              # RBNO107 its output; asserted by the caller with rbq107
  local a=no; [ "${1:-}" = "" ] || { case "$1" in --*) ;; *) a="$1"; shift ;; esac; }
  D94R_107="$(data94)"; RBC0_107="$(rbcopy107)"; fresh107; RBNO107="$(run107c --answer "$a" "$@" --rollback)"
}
rbcopy107() { grep -l '^RB_COPY_AT=[0-9]' "$OUT107"/rollback-state-*.env 2>/dev/null | wc -l | tr -d ' '; }   # rollback records holding a copy time (a declined rollback writes its record before the question, S:13757, with none)
rbq107() {   # rbq107 LABEL — the latest rbno107 not confirmed, nothing changed: $P107 serving, the data folder's digest as before, no record with a copy time
  check "$(has "$RBNO107" '  STOP: not confirmed')$(has "$RBNO107" 'Nothing was changed. Send the log file.')|$(rc107)|$(live)|$(data94)|$(rbcopy107)" \
        "yesyes|1|$P107|$D94R_107|$RBC0_107" "$1 not confirmed: 'Nothing was changed', exit 1, $P107 serving, the data folder's digest as before, no new rollback record with a copy time (the record a declined rollback writes before its question carries none — S:13757, as 5.18.106's rehearsal reads it)"
}
printed107() { grep -F -- "$2" <<<"$1" | grep -E '^      ' | head -1 | sed -E 's/^ {6}//'; }   # printed107 OUTPUT FRAGMENT — the first command line the script printed (six spaces) holding FRAGMENT
jv107() { python3 -I -c 'import json, sys
try:
    d = json.load(open(sys.argv[1], encoding="utf-8"))
except Exception:
    print("unread"); sys.exit(0)
print(json.dumps(d[sys.argv[2]]) if isinstance(d, dict) and sys.argv[2] in d else "absent")' "$DATA/kyc_config.json" "$1"; }   # jv107 KEY — kyc_config.json's value for KEY, as JSON, or absent
W1ON107="$(printed107 "$AFT107" 'set ai_burst_merge_enabled 1')"; KON107="$(printed107 "$AFT107" '--key quoted_no_prices_enabled --value 1')"; QON107="$(printed107 "$AFT107" '--key connection_quote_enabled --value 1')"
check "$W1ON107|$( [ -n "$KON107" ] && echo k)|$( [ -n "$QON107" ] && echo q)" "cd $REPO && bash scripts/sales-followup.sh set ai_burst_merge_enabled 1|k|q" \
  "107-8-0 the PASSED --after-only (107-7z) printed the owner's steps, each alone: W.1's switch-on through the pin's wrapper, and K's and Q's through 5.18.107's set_config (read from its output, never typed)"
# 8a — W.1 on, as printed; then the rollback answered no: the capitals, both §K commands each alone, the redeploy sentence, the
# prompt saying on — no wait (the switch is on); then any other answer ("Rollback") changes nothing either
OW1_107="$(run_line107c "" "$W1ON107")"
check "$(rc107)|$(has "$OW1_107" 'SET ai_burst_merge_enabled = 1')|$(jv107 ai_burst_merge_enabled)" '0|yes|"1"' "107-8a0 W.1 on through the pin's wrapper, run as printed: 'SET ai_burst_merge_enabled = 1', kyc_config.json holds '1'"
rbno107
check "$(has "$RBNO107" 'ANSWER ANYTHING BUT ROLLBACK BELOW — NOTHING HAS BEEN CHANGED')|$(has "$RBNO107" "A REDEPLOY'S NS-G1 REFUSES WHILE IT READS ON")|$(grep -cxF "      $BDOFF107" <<<"$RBNO107")|$(grep -cxF "      $BDDR107" <<<"$RBNO107")|$(has "$RBNO107" 'RB107 waiting 125 s')|$(has "$RBNO107" '— ai_burst_merge_enabled on; the last drain reading')" \
      "yes|yes|1|1|no|yes" \
  "107-8a the rollback with W.1 on, answered no: ANSWER ANYTHING BUT ROLLBACK in capitals, the redeploy sentence, the switch-off and the drain reading each alone on its line, no 125 s wait, the prompt saying on"
rbq107 "107-8a'"
NAI0_107="$(sed -n 's/.*RB-X before the rollback: active wa_no_ai rows \([0-9][0-9]*\) —.*/\1/p' <<<"$RBNO107" | head -1)"; NAL0_107="$(sed -n 's/.*pending wa\.no_ai_alert events \([0-9][0-9]*\) —.*/\1/p' <<<"$RBNO107" | head -1)"
check "$( [ -n "$NAI0_107" ] && [ -n "$NAL0_107" ] && echo read)" "read" "107-8a* RB-X's counts as found, read from the script's own line before anything is planted: $NAI0_107 listed, $NAL0_107 alert(s)"
rbno107 Rollback; rbq107 "107-8a\" any other answer (Rollback, not ROLLBACK)"
# 8b — the switch NOT READ (FAKE_CP_FAIL: the pin's code cannot be copied, root:456): SWITCH NOT READ, both commands, the prompt saying
# NOT READ; step 5's capitals naming K and Q as not read, each set_config --value 0 alone. Copies: the set command dropped; step 5
# firing only when K or Q reads on.
anc107 dropset '    [ "$s2" = "off" ] || echo "      $BD_OFF_CMD"   # §K'"'"'s switch-off: printed, never run (RB107)' '    :   # rh107: the switch-off line dropped'
anc107 kqon "$(printf '    q="$(rb_kq "${LK_RB0:-}" "$k")"\n    [ "$q" = "off" ] && continue\n')" "$(printf '    q="$(rb_kq "${LK_RB0:-}" "$k")"\n    [ "$q" = "on" ] || continue\n')"
rbno107 no --env FAKE_CP_FAIL=1
KOFF107="$(printed107 "$RBNO107" '--key quoted_no_prices_enabled --value 0')"; QOFF107="$(printed107 "$RBNO107" '--key connection_quote_enabled --value 0')"
check "$(has "$RBNO107" 'SWITCH NOT READ — ai_burst_merge_enabled: NOT READ')|$(grep -cxF "      $BDOFF107" <<<"$RBNO107")|$(grep -cxF "      $BDDR107" <<<"$RBNO107")|$(has "$RBNO107" '— ai_burst_merge_enabled NOT READ')|$(has "$RBNO107" "quoted_no_prices_enabled COULD NOT BE READ (NS-G1'S RULE, ON LK-RB0)")|$(has "$RBNO107" "connection_quote_enabled COULD NOT BE READ (NS-G1'S RULE, ON LK-RB0)")|$(grep -cxF "      $KOFF107" <<<"$RBNO107")|$(grep -cxF "      $QOFF107" <<<"$RBNO107")" \
      "yes|1|1|yes|yes|yes|1|1" \
  "107-8b the switch NOT READ (the pin's code made uncopiable, said so): SWITCH NOT READ, both §K commands each alone, the prompt saying NOT READ; step 5 names K and Q as not read, each set_config --value 0 alone with its own log"
rbq107 "107-8b'"
fresh107; mutant107 c8b-dropset dropset -- --env FAKE_CP_FAIL=1 --answer no --rollback
check "$(grep -cxF "      $BDOFF107" <<<"$MUT107")" "0" "107-8b\" a weakened copy that drops the switch-off line — caught"
fresh107; mutant107 c8b-kqon kqon -- --env FAKE_CP_FAIL=1 --answer no --rollback
check "$(has "$MUT107" 'COULD NOT BE READ (NS-G1')|$(grep -cF -- '--value 0' <<<"$MUT107")" "no|0" "107-8b* a weakened copy whose step 5 fires only on 'on': no note and no line when K and Q cannot be read — caught"
# 8c — TD7: K on through 5.18.107's set_config, as printed; the rollback answered no names K, its off command alone; then Q on: both
for k107 in quoted_no_prices_enabled connection_quote_enabled; do
  OK8_107="$(run_line107c "" "$( [ "$k107" = quoted_no_prices_enabled ] && echo "$KON107" || echo "$QON107")")"
  check "$(rc107)|$(has "$OK8_107" "  $k107 = 1")" "0|yes" "107-8c0 $k107 on through 5.18.107's set_config, run as printed: it prints the line '  $k107 = 1'"
  rbno107
  check "$(has "$RBNO107" "quoted_no_prices_enabled READS ON (NS-G1'S RULE, ON LK-RB0)")|$(has "$RBNO107" "connection_quote_enabled READS ON (NS-G1'S RULE, ON LK-RB0)")|$(grep -cxF "      $KOFF107" <<<"$RBNO107")|$(grep -cxF "      $QOFF107" <<<"$RBNO107")|$(has "$RBNO107" "it prints the line \"  $k107 = 0\" once it has saved")" \
        "yes|$( [ "$k107" = connection_quote_enabled ] && echo yes || echo no)|1|$( [ "$k107" = connection_quote_enabled ] && echo 1 || echo 0)|yes" \
    "107-8c TD7 with $( [ "$k107" = quoted_no_prices_enabled ] && echo 'K on' || echo 'K and Q on'): each named READS ON in capitals, its set_config --value 0 alone, and 'without that line take nothing as saved'"
  rbq107 "107-8c'"
done
# 8d — a listed number (through NoAiList::add, as the staff screens list it) and a queued wa.no_ai_alert (as evo_webhook queues it):
# RB-X's counts; the book kept first and put back after (a wa_no_ai row is never deleted)
K1="$(seedk107 "$P107R" book_keep b8d)"; N8D_107="$(seed107 "$P107R" "[{\"op\":\"noai\",\"phone\":\"$(ph107 05)\",\"name\":\"RH107SECRET- staff\",\"reason\":\"RH107SECRET- a listed number\",\"alert\":1}]")"
rbno107
check "$( [ "$(n103 "$(sd107 "$N8D_107" id)")" -ge 1 ] && echo listed)|$( [ "$(n103 "$(sd107 "$N8D_107" event)")" -ge 1 ] && echo queued)|$(has "$RBNO107" "RB-X before the rollback: active wa_no_ai rows $(( $(n103 "$NAI0_107") + 1 )) — no $BV107 file reads the list")|$(has "$RBNO107" "pending wa.no_ai_alert events $(( $(n103 "$NAL0_107") + 1 )) — $BV107's event processor acks a queued alert as unknown")" "listed|queued|yes|yes" \
  "107-8d a listed number and a queued alert: RB-X counts one more of each than 107-8a found, each with what $BV107 does with it"
rbq107 "107-8d'"
check "$(sd107 "$(seedk107 "$P107R" book_back b8d)" fixture)" "1" "107-8d* the book back from its keep (the listed row and the alert gone with it)"
# 8e — W.1 off by the switch-off as printed (BD_OFF_CMD under 5.18.107: it leaves '0')
OOFF_107="$(run_line107c "" "$BDOFF107")"
check "$(rc107)|$(has "$OOFF_107" 'SET ai_burst_merge_enabled = 0')|$(jv107 ai_burst_merge_enabled)" '0|yes|"0"' "107-8e W.1 off, the switch-off run as printed under $V107: it leaves '0'"
# 8f — §M: a mark whose named event is retrying (two typed ai.reply events on one conversation, the first set aside for the second, the
# second failed once — EventBus and BurstMerge, as the worker writes them), the switch off: the drain lists the ids, after the 125 s
# wait. The real worker (run_worker.php, as the server's cron runs it) then answers the event, and the drain lists it no longer.
K1="$(seedk107 "$P107R" book_keep b8f)"
M8F_107="$(seed107 "$P107R" "[{\"op\":\"burst\",\"phone\":\"$(ph107 06)\",\"name\":\"RH107SECRET- a customer\",\"text\":\"RH107SECRET- hello\",\"text2\":\"RH107SECRET- the price please\",\"instance\":\"sales-001\",\"mark\":\"aside\",\"fail\":1}]")"
A8F_107="$(sd107 "$M8F_107" a)"; B8F_107="$(sd107 "$M8F_107" b)"; C8F_107="$(sd107 "$M8F_107" conv)"
check "$(sd107 "$M8F_107" a_marked)|$(sd107 "$M8F_107" b_status)|$(sd107 "$M8F_107" b_attempts | cut -d/ -f1)|$(sd107 "$M8F_107" origin)" "1|failed|1|typed" "107-8f0 the plant: event #$A8F_107 set aside for #$B8F_107, which failed once and is retrying"
rbno107
EV8F_107="$(printf '%s\n' "$A8F_107" "$B8F_107" | sort -un | paste -sd, -)"
check "$(has "$RBNO107" 'RB107 waiting 125 s, then reading the switch and the drain again')|$(has "$RBNO107" "; the conversations $C8F_107, the events $EV8F_107")|$(has "$RBNO107" '(a) 1, counted apart 0')|$(has "$RBNO107" "RB107 root docs/76 §K's drain holds")" "yes|yes|yes|no" \
  "107-8f §M: the switch off, two readings 125 s apart — the drain lists the mark ((a) 1): conversation $C8F_107, events $EV8F_107 (#$A8F_107 waiting for #$B8F_107); it does not hold"
rbq107 "107-8f'"
clkfwd107 15
worker107() { env PATH="$SB/bin:$PATH" LD_PRELOAD="$CLKPRE104" RH104_CLOCK_FILE="$CLKF104" bash "$SB/bin/docker" exec -u "$(owner104)" -e "DN_DATA_DIR=$PDD104_IN" ucrm \
  php -d log_errors=0 "$IN_CONTAINER/run_worker.php" </dev/null >> "$R107/logs/worker107.out" 2>&1; echo $?; }   # worker107 — one run of the installed worker, as the cron runs it
# This sandbox reaches no model provider (5.18.104's control: no provider key is set), so the worker cannot answer #B with a
# reply — it would fail it again and the mark would stay listed. Its own way to run #B here: #B's number listed through
# NoAiList::add, which the installed worker settles done with no model call (root docs/76 §X.3) — said so.
N8F_107="$(seed107 "$P107R" "[{\"op\":\"noai\",\"phone\":\"$(ph107 06)\",\"name\":\"RH107SECRET- a customer\",\"reason\":\"RH107SECRET- the named event run by the worker's own hold\"}]")"
check "$( [ "$(n103 "$(sd107 "$N8F_107" id)")" -ge 1 ] && echo listed)|$(sd107 "$N8F_107" event)" "listed|0" "107-8f\"0 #$B8F_107's number listed (no alert): the sandbox reaches no model provider, so this is how the installed worker runs #$B8F_107 here — settled done with no model call, said so"
WR8F_107="$(worker107)"; ES8F_107="$(sqx "PRAGMA wal_checkpoint(TRUNCATE)" >/dev/null 2>&1; q107 evstat "$DATA/plugin.sqlite3" "$A8F_107" "$B8F_107")"
check "$WR8F_107|$(cut -d' ' -f1 <<<"$ES8F_107" | cut -d: -f1-2)|$(cut -d' ' -f2 <<<"$ES8F_107" | cut -d: -f1-2)" "0|$A8F_107:done|$B8F_107:done" \
  "107-8f\" the installed worker (run_worker.php, as the cron runs it, as the book's owner, under the clock) runs both: #$A8F_107 carried by its mark, #$B8F_107 held (the list) — each done ($ES8F_107)"
rbno107
check "$(has "$RBNO107" "; the conversations $C8F_107,")|$(has "$RBNO107" '(a) 0, counted apart')" "no|yes" "107-8f* the drain after the worker: the mark no longer listed, (a) 0 (§T.11:3528–3529)"
rbq107 "107-8f**"
check "$(sd107 "$(seedk107 "$P107R" book_back b8f)" fixture)" "1" "107-8f*** the book back from its keep (the conversation and the events gone with it)"
# 8g — control 0: the clock two days on, so no typed ai.reply is in the last day: the drain's control 0 never counts as zero — the
# capitals and the drain command stand. Copy: a reading with control 0 counted as zero ("the drain holds").
anc107 zeroctl '[ "$(kv "$1" b)" = "0" ] && [ "$(kv "$1" control)" != "0" ] ;; *) return 1 ;; esac; }   # rb_bd_zero LINE' '[ "$(kv "$1" b)" = "0" ] ;; *) return 1 ;; esac; }   # rb_bd_zero LINE'
clkfwd107 $((2 * 86400 + 600))
rbno107
check "$(has "$RBNO107" ', control 0')|$(has "$RBNO107" 'ANSWER ANYTHING BUT ROLLBACK BELOW')|$(grep -cxF "      $BDDR107" <<<"$RBNO107")|$(has "$RBNO107" "RB107 root docs/76 §K's drain holds")" "yes|yes|1|no" \
  "107-8g no typed ai.reply in the last day (the clock two days on, said so): the drain's control 0 — the capitals and the drain command stand, never 'the drain holds'"
rbq107 "107-8g'"
fresh107; mutant107 c8g-zero zeroctl -- --answer no --rollback
check "$(has "$MUT107" "RB107 root docs/76 §K's drain holds")" "yes" "107-8g\" a weakened copy that counts a control-0 reading as zero says the drain holds — caught"
# 8h — K and Q off by their printed set_config --value 0 lines (8b's), each alone
for k107 in quoted_no_prices_enabled connection_quote_enabled; do
  OK8_107="$(run_line107c "" "$( [ "$k107" = quoted_no_prices_enabled ] && echo "$KOFF107" || echo "$QOFF107")")"
  check "$(rc107)|$(has "$OK8_107" "  $k107 = 0")" "0|yes" "107-8h $k107 off through 5.18.107's set_config, run as printed: '  $k107 = 0'"
done
master106 "107-8z0"
aft107 "107-8z" PASSED $L2ON107
check "$(live)|$(jv107 ai_burst_merge_enabled)|$(jv107 quoted_no_prices_enabled)|$(jv107 connection_quote_enabled)|$(kept107)" "$P107|\"0\"|\"0\"|\"0\"|0" \
  "107-8zz the exit: $P107 serving, a PASSED --after-only, W.1 '0', K and Q 0, nothing kept"
# ════════════════════════════════════════════════════════════════════════════
# D2 to D10 — first-deploy variants (v3 §B: 107-5, 107-6 seen=0 and nowait), each from A9 (reset107): the plant, the deploy,
# then the step's own --after-only runs. Each plant is said where it is made; each weakened copy runs in the cheapest mode
# that reaches its branch, said so. Every keep is undone before the next A9.
# ════════════════════════════════════════════════════════════════════════════
echo; echo "== 107-5. D2–D10: first deploys with a plant — before stamp_copy (H2), at the DEPLOY hold, at deploy-hybrid's read (H4), a WARNING beside the OK line, the log unwritable (seen=0), WARNING only, other bytes at stamp_copy, no boot (nowait, H1), a PARTIAL line =="
verd107() {   # verd107 OUTPUT — the run's verdict line as read (a deploy, a redeploy or a rollback): PASSED, FAILED:<n>, NOTYET, or none
  if grep -qxE "  ${V107//./\\.} \((deploy|rollback)\): PASSED\. Send this LOG FILE back \(not a copy of the terminal, and never the cash sheet\)\." <<<"$1"; then echo PASSED
  elif grep -qE "^  ${V107//./\\.} \((deploy|rollback)\): [0-9]+ FAILED" <<<"$1"; then echo "FAILED:$(grep -oE "^  ${V107//./\\.} \((deploy|rollback)\): [0-9]+ FAILED" <<<"$1" | grep -oE '[0-9]+ FAILED' | cut -d' ' -f1)"
  elif grep -qF "  $V107 (after-only): NOT YET — " <<<"$1"; then echo NOTYET
  else echo none; fi
}
dep107() { fresh107; DEP107="$(run107c --answer DEPLOY)"; DEPT107="$(last107)"; DEPRC107="$(rc107)"; }   # dep107 — a plain deploy under the clock, DEPLOY typed
dfail107() { [ "$(n103 "$(fails "$1")")" -gt 0 ] && echo some || echo none; }   # dfail107 OUTPUT — some FAIL line, or none
pin092_107() { echo "$(pinroot107)/migrations/092_wa_no_ai.sql"; }            # the pin's own 092, from Git's tree
PINMD5_107="$(md5sum < "$(pin092_107)" | cut -c1-16)"
check "$( [ "${#PINMD5_107}" = 16 ] && echo read)|$(grep -c "MIG107_LAST_MD5=\"$PINMD5_107" "$DEPLOY107")" "read|1" \
  "107-5-0 the pin's 092 read from Git's tree here: its md5 is the script's MIG107_LAST_MD5 (the checksum the runner records), so a ledger checksum can be told the pin's or another's"

# ── D2 — A6/H2: 092 applied before stamp_copy, after the tar (v2 A6; v3). PASSED; QF_COPY_UNIX ≤ applied_at < COPY_AT; no dated
# note; master106, --after-only PASSED. The copy (an applied_at < COPY_AT bound in MG-CA1) runs as --after-only and FAILS there;
# its deploy-run half is not repeated (the branch is the same in both modes, v3 §C item 6). ──
reset107 "107-5a-A9"
: > "$SB/plant092touch107.fired"; : > "$R107/boot/h2.out"; : > "$SB/.plant092touch107"
dep107
AT5A_107="$(kv107 "$(ledgerx107)" at092)"; CU5A_107="$(sget107 "$STATE107" QF_COPY_UNIX)"; CA5A_107="$(sget107 "$STATE107" COPY_AT)"
check "$(verd107 "$DEP107")|$DEPRC107|$(live)|$(grep -c . "$SB/plant092touch107.fired")|$( [ -e "$SB/.plant092touch107" ] && echo unspent || echo spent)|$(grep -c '^booted$' "$R107/boot/h2.out")" \
      "PASSED|0|$P107|1|spent|1" \
  "107-5a D2 the trigger set before the run: H2 fires once, at stamp_copy's touch (after the tar, before V1), boots the installed store and is spent; the deploy PASSED, $P107 serving"
check "$(case "$AT5A_107|$CU5A_107|$CA5A_107" in *[!0-9\|]*|*'||'*|'|'*|*'|') echo unread ;; *) [ "$CU5A_107" -le "$AT5A_107" ] && [ "$AT5A_107" -lt "$CA5A_107" ] && echo between || echo outside ;; esac)|$(has "$DEP107" '092 was applied before this copy began')|$(has "$DEP107" '  ok    MG-CA1 092 on a fresh copy after the copy')|$(sget107 "$STATE107" MG_CA2_SEEN)" \
      "between|no|yes|1" \
  "107-5a' QF_COPY_UNIX ($CU5A_107) ≤ 092's applied_at ($AT5A_107) < COPY_AT ($CA5A_107): MG-CA1 ok with no dated note — the note's bound is QF_COPY_UNIX, never COPY_AT; MG_CA2_SEEN=1"
master106 "107-5a0"
aft107 "107-5a\"" PASSED $L2ON107
check "$(has "$AFT107" '092 was applied before this copy began')|$(has "$AFT107" '  ok    MG-CA1 092 on a fresh copy after the copy')" "no|yes" "107-5a* the --after-only: MG-CA1 ok, no dated note"
anc107 cabound '    at="$(qf_kv "$o" at)"; au=""; case "$at" in [0-9][0-9][0-9][0-9]-*) au="$(date -u -d "${at/T/ }" +%s 2>/dev/null)" ;; esac' \
  '    at="$(qf_kv "$o" at)"; au=""; case "$at" in [0-9][0-9][0-9][0-9]-*) au="$(date -u -d "${at/T/ }" +%s 2>/dev/null)" ;; esac; cab="$(sed -n '"'"'s/^COPY_AT=//p'"'"' "$STATE" 2>/dev/null | tail -n1)"; [ -n "$au" ] && [ -n "$cab" ] && [ "$au" -lt "$cab" ] && { MG_CA1_R=bad; bad "MG-CA1 rh107 copy: 092 applied before COPY_AT"; }'
idle104; fresh107; mutant107 c5a-cabound cabound -- --after-only
check "$MUTRC107|$(has "$MUT107" 'MG-CA1 rh107 copy: 092 applied before COPY_AT')" "1|yes" \
  "107-5a** a weakened copy that bounds MG-CA1 by COPY_AT FAILS on D2's honest 092 — caught (run as --after-only; the deploy-run half is the same branch)"

# ── D3 — A7: 092 applied while the run waits at Type DEPLOY, before LK-B1 and QF_BASE (csb1run107; the action rh107-seed.php
# mig092, the pin's MigrationRunner over the live book as uid 1000 under the clock, said so). applied_at < QF_COPY_UNIX; the
# dated note; R7 applied (its baseline is stage A's); LK-CA2 exact against a baseline that already holds 092's row. ──
reset107 "107-5b-A9"
mig3_107() { seed107 "$(pinroot107)" '[{"op":"mig092"}]' > "$R107/logs/mig3.json"; case "$(head -c1 "$R107/logs/mig3.json")" in '{') return 0 ;; *) return 1 ;; esac; }
csb1run107 107-5b "$DEPLOY107" -- mig3_107
M3_107="$(cat "$R107/logs/mig3.json" 2>/dev/null)"; AT5B_107="$(kv107 "$(ledgerx107)" at092)"; CU5B_107="$(sget107 "$STATE107" QF_COPY_UNIX)"
check "$HOLDSAW107|$HOLDRC107|$(sd107 "$M3_107" rows)|$(sd107 "$M3_107" ok_lines)|$(sd107 "$M3_107" cks)|$(verd107 "$HOLDOUT107")|$(rc107)|$(live)" \
      "yes|0|1|1|$PINMD5_107|PASSED|0|$P107" \
  "107-5b D3 held at Type DEPLOY, the action (the seed's own exit status 0): the pin's runner applies 092 over the live book — one ledger row, one OK line, the pin's checksum; the deploy PASSED"
check "$(case "$AT5B_107|$CU5B_107" in *[!0-9\|]*|'|'*|*'|') echo unread ;; *) [ "$AT5B_107" -lt "$CU5B_107" ] && echo before || echo after ;; esac)|$(sget107 "$STATE107" MG_G1)|$(has "$HOLDOUT107" 'MG-CA1 092 was applied before this copy began (')|$(has "$HOLDOUT107" 'moved by exactly 092, applied at a boot')|$(has "$HOLDOUT107" 'LK-CA2 every _schema_checks row unchanged across the copy (')|$(has "$HOLDOUT107" 'but one added, exactly 092_wa_no_ai.sql:5/5')" \
      "before|absent|yes|yes|yes|no" \
  "107-5b' applied_at ($AT5B_107) < QF_COPY_UNIX ($CU5B_107), MG_G1=absent at stage A: MG-CA1's dated note; R7 the ledger moved by exactly 092 since stage A; LK-CA2 exact — every row unchanged across the copy (its baseline, taken after the hold, holds 092's row already)"

# ── D4 — A8/H4: 092 left on disk (the pin's file copied into the live 5.18.106 plugin, a FIXTURE, said so), a WARNING planted
# beside it; .boot107 armed by csb1run107's action at Type DEPLOY — never before the run (v3 TC2): 5.18.106's runner applies the
# 092 on disk at deploy-hybrid's read of the record, after QF_BASE, before the tar. First its control: armed before a --check,
# the counter stays 0 (the parent is not deploy-hybrid). Then: the straddle note, no dated note, PASSED. The copy (the note
# keyed on applied_at < QF_COPY_UNIX) runs as --after-only. ──
reset107 "107-5c-A9"
cp "$(pin092_107)" "$PD/migrations/092_wa_no_ai.sql"; chown 1000:1000 "$PD/migrations/092_wa_no_ai.sql"; chmod 644 "$PD/migrations/092_wa_no_ai.sql"
K1="$(seedk107 "$(pinroot107)" log_keep l5c)"; K2="$(seedk107 "$(pinroot107)" log_add l5c '"line":"warning092"')"
check "$(sd107 "$K2" fixture)|$(ledger107)|$(md5sum < "$PD/migrations/092_wa_no_ai.sql" | cut -c1-16)|$(live)" "1|mig092=0 wa_no_ai=none|$PINMD5_107|$P106" \
  "107-5c0 the plants: the pin's 092 on disk in the live $V106 plugin (a FIXTURE), a 092 WARNING appended to migration.log (a FIXTURE, UTC); the ledger holds no 092"
: > "$SB/boot107.fired"; : > "$SB/boot107.passed"; : > "$R107/boot/h4.out"; : > "$SB/.boot107"
D94_5C_107="$(data94)"; fresh107; O5C_107="$(run107c --check)"
check "$(grep -c . "$SB/boot107.fired")|$( [ "$(grep -c . "$SB/boot107.passed")" -ge 1 ] && echo passed)|$( [ -e "$SB/.boot107" ] && echo armed || echo spent)|$(ledger107)|$(data94)" "0|passed|armed|mig092=0 wa_no_ai=none|$D94_5C_107" \
  "107-5c1 H4's control: armed before a --check, the record read by the script itself passes through (its parent is not deploy-hybrid) — 0 boots, still armed, no 092 applied, the data as before"
rm -f "$SB/.boot107"; : > "$SB/boot107.passed"
arm4_107() { grep -c . "$SB/boot107.fired" > "$R107/logs/arm4.n"; : > "$SB/.boot107"; }   # at the hold: the counter read, then H4 armed
csb1run107 107-5c "$DEPLOY107" -- arm4_107
AT5C_107="$(kv107 "$(ledgerx107)" at092)"; CU5C_107="$(sget107 "$STATE107" QF_COPY_UNIX)"
check "$HOLDSAW107|$HOLDRC107|$(cat "$R107/logs/arm4.n" 2>/dev/null)|$(grep -c . "$SB/boot107.fired")|$( [ -e "$SB/.boot107" ] && echo armed || echo spent)|$(grep -c '^booted$' "$R107/boot/h4.out")|$(verd107 "$HOLDOUT107")|$(live)" \
      "yes|0|0|1|spent|1|PASSED|$P107" \
  "107-5c D4 H4 armed at the hold (the counter 0 there): it fires once, at deploy-hybrid's read of the record while it names $P106 — $V106's runner applies the 092 on disk — and the deploy PASSED"
check "$(case "$AT5C_107|$CU5C_107" in *[!0-9\|]*|'|'*|*'|') echo unread ;; *) [ "$AT5C_107" -ge "$CU5C_107" ] && echo after || echo before ;; esac)|$(has "$HOLDOUT107" 'the tar straddle (root docs/76 §T.6, TD12)')|$(has "$HOLDOUT107" '092 was applied before this copy began')|$(has "$HOLDOUT107" 'but one added, exactly 092_wa_no_ai.sql:5/5')" \
      "after|yes|no|yes" \
  "107-5c' applied_at ($AT5C_107) at or after QF_COPY_UNIX ($CU5C_107): the straddle note (the WARNING transient: MG-CA1, NA-CA1 and R1 hold), no dated note; LK-CA2 one row added, 092's"
anc107 stradcu '    if [ -n "$ok1" ] && [ -n "$ok2" ] && [ "$r1" = "ok" ]; then' '    if [ -n "$au" ] && [ -n "$cu" ] && [ "$au" -lt "$cu" ]; then'
idle104; fresh107; mutant107 c5c-stradcu stradcu -- --after-only
check "$(has "$MUT107" 'the tar straddle')|$(has "$MUT107" "MG-CA2 migration.log holds 092's checksum WARNING (1) — not judged here")" "no|yes" \
  "107-5c* a weakened copy that keys the straddle note on applied_at < QF_COPY_UNIX reads no note on D4 — caught (as --after-only)"
check "$(sd107 "$(seedk107 "$(pinroot107)" log_back l5c)" fixture)|$(kept107)" "1|0" "107-5c** migration.log back from its keep"

# ── D5 — a WARNING beside the OK line on a plain first deploy: the straddle note, PASSED. Its copy (the note keyed on MG-G1 alone)
# runs as --after-only. Then 092's installed bytes altered (a FIXTURE) and an --after-only: R1 FAIL, MG-CA2 names the WARNING as
# not judged, no straddle note. ──
reset107 "107-5d-A9"
K1="$(seedk107 "$(pinroot107)" log_keep l5d)"; K2="$(seedk107 "$(pinroot107)" log_add l5d '"line":"warning092"')"
dep107
check "$(sd107 "$K2" fixture)|$(verd107 "$DEP107")|$DEPRC107|$(has "$DEP107" 'the tar straddle (root docs/76 §T.6, TD12)')|$(has "$DEP107" "  ok    MG-CA2 migration.log holds 092's OK line (1)")|$(sget107 "$STATE107" MG_CA2_SEEN)" \
      "1|PASSED|0|yes|yes|1" \
  "107-5d D5 a 092 WARNING planted before a plain first deploy (a FIXTURE): MG-CA2's OK line and the straddle note beside it — PASSED"
anc107 stradmg '    if [ -n "$ok1" ] && [ -n "$ok2" ] && [ "$r1" = "ok" ]; then' '    if [ "$mg" = "present" ]; then'
idle104; fresh107; mutant107 c5d-stradmg stradmg -- --after-only
check "$(has "$MUT107" 'the tar straddle')|$(has "$MUT107" "checksum WARNING (1) — not judged here")" "no|yes" \
  "107-5d' a weakened copy that keys the straddle note on MG-G1 alone (a redeploy) reads no note on a first deploy — caught (as --after-only)"
MF107="$PD/migrations/092_wa_no_ai.sql"; mkdir -p "$STK107"; cp -p "$MF107" "$STK107/mf5d"; printf '%s\n' '-- RH107SECRET- a byte the pin does not have' >> "$MF107"
ao107
check "$AORC107|$(has "$AO107" 'R1 installed files that differ:')|$(has "$AO107" "MG-CA2 migration.log holds 092's checksum WARNING (")|$(has "$AO107" 'not judged here: R1 reads the installed migrations/092_wa_no_ai.sql other than the pin'"'"'s')|$(has "$AO107" 'the tar straddle')" \
      "1|yes|yes|yes|no" \
  "107-5d\" 092's installed bytes altered after the copy (a FIXTURE): the --after-only's own V1 boot logs the real WARNING beside the planted one — R1 FAIL, MG-CA2 names the WARNING as not judged beside R1, no straddle note"
cp -p "$STK107/mf5d" "$MF107" && rm -f "$STK107/mf5d"
check "$(installed107 "$P107F")|$(sd107 "$(seedk107 "$(pinroot107)" log_back l5d)" fixture)|$(kept107)" "0|1|0" "107-5d* 092's installed file and migration.log back"

# ── D6 — seen=0: migration.log made unwritable before the run (0444: the boot's @file_put_contents fails silently). Stage R's
# NOT ESTABLISHED in capitals, MG_CA2_SEEN=0. Then --after-only: NOT ESTABLISHED (seen=0, no marker) with two copies (a note
# instead; MG-G1 read from the run's own stage A); a marker stamped after applied_at: NOT ESTABLISHED still, with two copies (the
# marker taken as the halving; 0 read as 1). The log writable again at the end. ──
reset107 "107-5e-A9"
K1="$(seedk107 "$(pinroot107)" log_keep l5e)"; LM5E_107="$(stat -c %a "$DATA/migration.log")"; chmod 0444 "$DATA/migration.log"; LS5E_107="$(logsha107)"
dep107
check "$(sd107 "$K1" fixture)|$(verd107 "$DEP107")|$DEPRC107|$(sget107 "$STATE107" MG_CA2_SEEN)|$(has "$DEP107" 'MG-CA2 NOT ESTABLISHED — NO OK LINE FOR 092 IN migration.log, THOUGH THE LEDGER HOLDS IT')|$(kv107 "$(ledger107)" mig092)|$(logsha107)" \
      "1|PASSED|0|0|yes|1|$LS5E_107" \
  "107-5e D6 migration.log unwritable (0444, a FIXTURE): V1's boot applies 092 and logs nothing — stage R's NOT ESTABLISHED in capitals, MG_CA2_SEEN=0, the log unchanged; a note, PASSED"
check "$(sd107 "$(seedk107 "$(pinroot107)" log_back l5e)" fixture)|$(stat -c %a "$DATA/migration.log")" "1|$LM5E_107" "107-5e' migration.log writable again (put back from its keep, its mode $LM5E_107 as before)"
ao107
check "$(has "$AO107" 'MG-CA2 NOT ESTABLISHED — NO OK LINE FOR 092, AND STAGE R OF THE DEPLOY READ NONE EITHER (MG_CA2_SEEN=0)')|$(dfail107 "$AO107")" "yes|none" \
  "107-5e\" --after-only, MG_CA2_SEEN=0 and no marker: NOT ESTABLISHED naming stage R's own reading — a note, no FAIL"
anc107 seen0note '      0) note "MG-CA2 NOT ESTABLISHED — NO OK LINE FOR 092, AND STAGE R OF THE DEPLOY READ NONE EITHER (MG_CA2_SEEN=0)' '      0) note "MG-CA2 no OK line for 092, and stage R of the deploy read none either (MG_CA2_SEEN=0)'
anc107 mgown '  if [ "$MODE" = "after" ]; then mg="$(sed -n '"'"'s/^MG_G1=//p'"'"' "$STATE" 2>/dev/null | tail -n1)";' '  if [ "$MODE" = "after" ]; then mg="${MG_G1:-}";'
idle104; fresh107; mutant107 c5e-note seen0note -- --after-only
check "$(has "$MUT107" 'MG-CA2 NOT ESTABLISHED')" "no" "107-5e* a weakened copy that says seen=0 as a plain note — caught"
idle104; fresh107; mutant107 c5e-mgown mgown -- --after-only
check "$(has "$MUT107" 'STAGE R OF THE DEPLOY READ NONE EITHER')|$( { [ "$(has "$MUT107" 'THE STATE FILE HOLDS NO MG_G1 RECORD')" = yes ] || [ "$(has "$MUT107" 'a redeploy: 092 was applied by an earlier deploy')" = yes ]; } && echo misread)" "no|misread" \
  "107-5e** a weakened copy that reads MG-G1 from its own run (an --after-only runs no stage A) loses the deploy's record — caught"
K1="$(seedk107 "$P107R" log_keep l5em)"; K2="$(seedk107 "$P107R" log_mark l5em '"offset_s":60')"
ao107
check "$(sd107 "$K2" fixture)|$(has "$AO107" 'MG-CA2 NOT ESTABLISHED — NO OK LINE FOR 092, AND STAGE R OF THE DEPLOY READ NONE EITHER (MG_CA2_SEEN=0)')|$(has "$AO107" "the runner's trim marker")" "1|yes|no" \
  "107-5e*** a trim marker stamped 60 s after applied_at (a FIXTURE, UTC): still NOT ESTABLISHED — stage R read none minutes after the first boot, which no halving explains"
anc107 seen0mk '      0) note "MG-CA2 NOT ESTABLISHED — NO OK LINE FOR 092, AND STAGE R OF THE DEPLOY READ NONE EITHER (MG_CA2_SEEN=0)' '      0) [ -n "$mk" ] && note "MG-CA2 no OK line for 092 now — rh107 copy: the runner'"'"'s trim marker" || note "MG-CA2 NOT ESTABLISHED — NO OK LINE FOR 092, AND STAGE R OF THE DEPLOY READ NONE EITHER (MG_CA2_SEEN=0)'
anc107 seen0x '      0) note "MG-CA2 NOT ESTABLISHED — NO OK LINE FOR 092, AND STAGE R OF THE DEPLOY READ NONE EITHER (MG_CA2_SEEN=0)' '      0x) note "MG-CA2 NOT ESTABLISHED — NO OK LINE FOR 092, AND STAGE R OF THE DEPLOY READ NONE EITHER (MG_CA2_SEEN=0)'
anc107 seen0as1 '      1) note "MG-CA2 no OK line for 092 now — stage R of the deploy read it after the first boot (MG_CA2_SEEN=1)' '      0|1) note "MG-CA2 no OK line for 092 now — stage R of the deploy read it after the first boot (MG_CA2_SEEN=1)'
idle104; fresh107; mutant107 c5e-mk seen0mk -- --after-only
check "$(has "$MUT107" 'MG-CA2 NOT ESTABLISHED')|$(has "$MUT107" "rh107 copy: the runner's trim marker")" "no|yes" "107-5e+ a weakened copy that takes the marker as the halving when seen=0 — caught"
idle104; fresh107; mutant107 c5e-0as1 seen0x seen0as1 -- --after-only
check "$(has "$MUT107" 'MG-CA2 NOT ESTABLISHED')|$(has "$MUT107" "is the runner's trim marker")" "no|yes" "107-5e++ a weakened copy that reads 0 as 1 names the halving — caught"
check "$(sd107 "$(seedk107 "$P107R" log_back l5em)" fixture)|$(kept107)" "1|0" "107-5e+++ migration.log back"

# ── D7 — WARNING only, first deploy: a WARNING planted, then the log made unwritable. The straddle note beside stage R's
# NOT ESTABLISHED; PASSED. ──
reset107 "107-5f-A9"
K1="$(seedk107 "$(pinroot107)" log_keep l5f)"; K2="$(seedk107 "$(pinroot107)" log_add l5f '"line":"warning092"')"; LM5F_107="$(stat -c %a "$DATA/migration.log")"; chmod 0444 "$DATA/migration.log"
dep107
check "$(sd107 "$K2" fixture)|$(verd107 "$DEP107")|$DEPRC107|$(has "$DEP107" 'the tar straddle (root docs/76 §T.6, TD12)')|$(has "$DEP107" 'MG-CA2 NOT ESTABLISHED — NO OK LINE FOR 092 IN migration.log, THOUGH THE LEDGER HOLDS IT')|$(sget107 "$STATE107" MG_CA2_SEEN)" \
      "1|PASSED|0|yes|yes|0" \
  "107-5f D7 a 092 WARNING and no OK line (the WARNING planted, then the log made unwritable — FIXTURES): the straddle note beside stage R's NOT ESTABLISHED, MG_CA2_SEEN=0 — PASSED"
check "$(sd107 "$(seedk107 "$(pinroot107)" log_back l5f)" fixture)|$(stat -c %a "$DATA/migration.log")|$(kept107)" "1|$LM5F_107|0" "107-5f' migration.log back, writable"

# ── D8 — §T.11:3487: D7's plants, and H2's second trigger alters the INSTALLED 092 at stamp_copy, before V1 — V1's boot then applies
# other bytes, so the ledger records another md5. MG-CA1 FAIL and R1 FAIL (said so: R1 reads the same altered file). ──
reset107 "107-5g-A9"
K1="$(seedk107 "$(pinroot107)" log_keep l5g)"; K2="$(seedk107 "$(pinroot107)" log_add l5g '"line":"warning092"')"; LM5G_107="$(stat -c %a "$DATA/migration.log")"; chmod 0444 "$DATA/migration.log"
: > "$SB/alter092touch107.fired"; : > "$SB/.alter092touch107"
dep107
CK5G_107="$(kv107 "$(ledgerx107)" cks092)"; IM5G_107="$(md5sum < "$PD/migrations/092_wa_no_ai.sql" | cut -c1-16)"
check "$(cat "$SB/alter092touch107.fired")|$( [ -e "$SB/.alter092touch107" ] && echo unspent || echo spent)|$( [ "$CK5G_107" != "$PINMD5_107" ] && [ "$CK5G_107" = "$IM5G_107" ] && echo altered)|$(verd107 "$DEP107" | cut -d: -f1)|$DEPRC107" \
      "fired|spent|altered|FAILED|1" \
  "107-5g D8 H2's second trigger at stamp_copy appends to the installed 092: V1's boot applies those bytes — the ledger's checksum is the altered file's, not the pin's — and the deploy FAILED"
check "$(has "$DEP107" 'MG-CA1 092 on a fresh copy after the copy is not whole (')|$(has "$DEP107" 'R1 installed files that differ:')|$(has "$DEP107" "checksum WARNING (1) — not judged here: R1 reads the installed migrations/092_wa_no_ai.sql other than the pin's; MG-CA1 failed; ")|$(has "$DEP107" 'the tar straddle')|$(has "$DEP107" '  092 is not shown whole in the live migration ledger')" \
      "yes|yes|yes|no|yes" \
  "107-5g' MG-CA1 FAIL (another checksum) and R1 FAIL (the installed file is not the pin's); MG-CA2 names the WARNING as not judged beside both; no straddle note; the lead is MG-CA1's"
check "$(sd107 "$(seedk107 "$(pinroot107)" log_back l5g)" fixture)|$(stat -c %a "$DATA/migration.log")|$(kept107)" "1|$LM5G_107|0" "107-5g* migration.log back, writable (the altered 092 goes with A9's install)"

# ── D9 — nowait (H1: the stand-in answers 503 and boots nothing). FAILED: MG-CA1's timed-out FAIL, MG_CA2_SEEN=nowait, NO BOOT HAD
# APPLIED 092, R7 DID NOT MOVE, the lead; V1's 503 recorded, not judged. Then H1 removed and the ledger asserted without 092; one
# stand-in boot; a halving marker after applied_at: the --after-only note naming the halving. The control: the line removed and no
# marker reads NOT ESTABLISHED naming the wait. Last, the copy writing MG_CA2_SEEN=0 on a timeout — a deploy, from A9. ──
reset107 "107-5h-A9"
: > "$SB/web/.noboot107"; chmod 644 "$SB/web/.noboot107"
dep107
check "$(verd107 "$DEP107" | cut -d: -f1)|$DEPRC107|$(sget107 "$STATE107" MG_CA2_SEEN)|$(has "$DEP107" 'MG-CA1 092 is not in the live migration ledger after a wait of 60 s (its last reading: mig092=0)')|$(has "$DEP107" 'MG-CA2 NOT ESTABLISHED — NO BOOT HAD APPLIED 092 WHEN THE LOG WAS READ')|$(has "$DEP107" 'R7 the migration ledger DID NOT MOVE')|$(has "$DEP107" '  NO UGANDA CUSTOMER GETS AN AI REPLY: THE ASSISTANT-OFF LIST')" \
      "FAILED|1|nowait|yes|yes|yes|yes" \
  "107-5h D9 no boot at all (H1, a FIXTURE): MG-CA1 FAIL after its 60 s wait, MG_CA2_SEEN=nowait, MG-CA2's NOT ESTABLISHED naming no boot, R7 the ledger DID NOT MOVE, the lead in capitals: no AI reply"
echo "  note 107-5h V1 under H1, recorded and not judged: $(grep -c 'V1 the sign-in page on the public address → 503' <<<"$DEP107") line(s) 'V1 the sign-in page on the public address → 503'"
rm -f "$SB/web/.noboot107"
check "$(ledger107)|$(standin_page customer_login)|$(kv107 "$(ledger107)" mig092)|$(kv107 "$(log107)" ok)" "mig092=0 wa_no_ai=none|200|1|1" \
  "107-5h' H1 removed: the ledger still holds no 092; then ONE request to the stand-in boots the store — 092 applied, its OK line logged"
K1="$(seedk107 "$P107R" log_keep l5hm)"; K2="$(seedk107 "$P107R" log_mark l5hm '"offset_s":60')"
ao107
check "$(sd107 "$K2" fixture)|$(has "$AO107" "stage R's wait timed out (MG_CA2_SEEN=nowait), so its reading says nothing about the line; the log's first line is a trim marker stamped (read as UTC) at or after 092's applied_at")|$(has "$AO107" 'MG-CA2 NOT ESTABLISHED')" "1|yes|no" \
  "107-5h\" a trim marker stamped 60 s after applied_at (a FIXTURE, UTC): the --after-only's note names the wait and the halving — never NOT ESTABLISHED"
check "$(sd107 "$(seedk107 "$P107R" log_back l5hm)" fixture)" "1" "107-5h* migration.log back"
K1="$(seedk107 "$P107R" log_keep l5hc)"; K2="$(seedk107 "$P107R" log_drop l5hc '"match":"ok092"')"
ao107
check "$(sd107 "$K2" removed)|$(has "$AO107" "MG-CA2 NOT ESTABLISHED — NO OK LINE FOR 092, AND STAGE R'S WAIT TIMED OUT (MG_CA2_SEEN=nowait), SO ITS READING SAYS NOTHING ABOUT THE LINE")|$(has "$AO107" "the log's first line is no trim marker")" "1|yes|yes" \
  "107-5h** the control: the OK line removed and no marker — NOT ESTABLISHED, naming the wait and no trim marker"
check "$(sd107 "$(seedk107 "$P107R" log_back l5hc)" fixture)|$(kept107)" "1|0" "107-5h*** migration.log back"
anc107 nowait0 "    if [ -z \"\$seen\" ]; then printf 'MG_CA2_SEEN=nowait\\n' >> \"\$STATE\";" "    if [ -z \"\$seen\" ]; then printf 'MG_CA2_SEEN=0\\n' >> \"\$STATE\";"
reset107 "107-5h+A9"
: > "$SB/web/.noboot107"; chmod 644 "$SB/web/.noboot107"
fresh107; mutant107 c5h-nowait0 nowait0 -- --answer DEPLOY
check "$(sget107 "$STATE107" MG_CA2_SEEN)|$(has "$MUT107" 'MG-CA1 092 is not in the live migration ledger after a wait of 60 s')" "0|yes" \
  "107-5h+ a weakened copy that records a timed-out wait as MG_CA2_SEEN=0 — a later --after-only would claim stage R read none after seeing 092 — caught (a deploy under H1, from A9)"
rm -f "$SB/web/.noboot107"

# ── D10 — a PARTIAL line planted before the run: MG-CA2 FAIL in the deploy, then in --after-only; the lead is MG-CA2's alone — 092
# needs no repair and MG-G1 would accept a redeploy. The copy printing MG-G1's redeploy-refusal consequence, as --after-only. ──
reset107 "107-5i-A9"
K1="$(seedk107 "$(pinroot107)" log_keep l5i)"; K2="$(seedk107 "$(pinroot107)" log_add l5i '"line":"partial092"')"
dep107
check "$(sd107 "$K2" fixture)|$(verd107 "$DEP107" | cut -d: -f1)|$DEPRC107|$(has "$DEP107" 'MG-CA2 migration.log holds a PARTIAL line for 092 (1)')|$(has "$DEP107" '  ok    MG-CA1 092 on a fresh copy after the copy')|$(has "$DEP107" '  migration.log holds a PARTIAL line for 092 (MG-CA2 above), but the ledger is whole')|$(has "$DEP107" 'MG-G1 refuses every redeploy')" \
      "1|FAILED|1|yes|yes|yes|no" \
  "107-5i D10 a PARTIAL line for 092 planted before the run (a FIXTURE): MG-CA2 FAIL in the deploy, MG-CA1 ok; the lead is MG-CA2's alone — the ledger whole, no redeploy refusal"
ao107
check "$AORC107|$(has "$AO107" 'MG-CA2 migration.log holds a PARTIAL line for 092 (1)')|$(has "$AO107" 'MG-G1 would accept a redeploy')" "1|yes|yes" "107-5i' the --after-only: MG-CA2 FAIL again, the same lead"
anc107 logasmg '  elif [ "$MG_CA2_R" = "bad" ]; then MG_SAY=log' '  elif [ "$MG_CA2_R" = "bad" ]; then MG_SAY=mg'
idle104; fresh107; mutant107 c5i-logmg logasmg -- --after-only
check "$(has "$MUT107" 'MG-G1 refuses every redeploy')|$(has "$MUT107" 'MG-G1 would accept a redeploy')" "yes|no" \
  "107-5i\" a weakened copy that leads a PARTIAL line with MG-CA1's consequence (MG-G1 refuses every redeploy) — caught (as --after-only)"
check "$(sd107 "$(seedk107 "$(pinroot107)" log_back l5i)" fixture)|$(kept107)|$(LEFT107)" "1|0|none" "107-5z the exit: migration.log back, nothing kept, no trigger armed"
# ════════════════════════════════════════════════════════════════════════════
# D11 and on — the rollback, the redeploys, and the end (v3 §B: 107-8 part two, 107-9). Entry: A9, a plain first deploy
# PASSED, master106, --after-only PASSED. Corrections to v3 read off the script (the readers' map, §C of this part's notes):
#   R1's settings-text note prints NO restore command (S:9009) — its absence is asserted;
#   V1's automatic rollback exists only in deploy mode (S:13950) — the V1 cases are --redeploy runs, never a --rollback;
#   the three R3 copies reach their branch (pb_para's K/Q lines, S:9121–9131) in a PASSED --after-only's summary, the
#     cheapest mode that reaches it (§B's rule), said so; the put-back paragraph's readings (item 10) likewise.
# ════════════════════════════════════════════════════════════════════════════
echo; echo "== 107-8 (part two). D11: the typed rollbacks, the redeploys, H6 with Q and with K, H3, V1 through H5, the put-back paragraph; 107-9 the final redeploy and the owner's steps =="
rbset107() { ls -1 "$OUT107"/rollback-state-*.env 2>/dev/null | wc -l | tr -d ' '; }   # rollback records, any
dredeploy107() {   # dredeploy107 LABEL — a --redeploy after a rollback, DEPLOY typed, under the clock: PASSED, $P107 serving; MG-G1 present,
                   # QF-G2 none pending with 092's negative control reset=1, R7 the ledger the same, LK-CA2 exact, MG-CA1's earlier-deploy
                   # note, NS-G1 GO; DRD107 its output
  fresh107; DRD107="$(run107c --answer DEPLOY --redeploy)"
  check "$(verd107 "$DRD107")|$(rc107)|$(went107 "$DRD107")|$(live)|$(installed107 "$P107F")|$(has "$DRD107" "== $V107 (deploy, --redeploy) — ")" \
        "PASSED|0|yesyes|$P107|0|yes" "$1 the --redeploy, DEPLOY typed, under the clock: PASSED, $P107 serving, every file installed as the pin has it"
  check "$(has "$DRD107" "  ok    MG-G1 the copy's migration ledger holds 092 whole")|$(has "$DRD107" "092 already in the copy's ledger, applied by an earlier deploy (MG-G1), no line logged")|$(has "$DRD107" '  negative control (092) reset=1 status=partial partial=1 check=1 row=0')|$(has "$DRD107" "  ok    R7 the database since this run's stage A: the migration ledger its last row the same (")|$(has "$DRD107" '  ok    LK-CA2 every _schema_checks row unchanged across the copy (')|$(has "$DRD107" '  note  MG-CA1 092 was applied by an earlier deploy, before this copy (')|$(has "$DRD107" '  ok    NS-G1 quoted_no_prices_enabled (K), connection_quote_enabled (Q) and ai_burst_merge_enabled (W.1) read off in every place')" \
        "yes|yes|yes|yes|yes|yes|yes" \
    "$1' MG-G1 present; QF-G2 none pending, 092's negative control reset=1; R7 the ledger's last row the same; LK-CA2 exact; MG-CA1's earlier-deploy note; NS-G1 GO"
}
nsd107() { echo "$(jv107 quoted_no_prices_enabled)|$(jv107 connection_quote_enabled)|$(jv107 ai_burst_merge_enabled)"; }   # K, Q and W.1 in kyc_config.json, as JSON or absent

# ── Entry (v3 D11): A9, a plain first deploy PASSED, master106, --after-only PASSED ──
reset107 "107-8b-A9"
dep107
check "$(verd107 "$DEP107")|$DEPRC107|$(live)|$(nsd107)" "PASSED|0|$P107|absent|absent|absent" "107-8b the entry: a plain first deploy PASSED, $P107 serving, no K, Q or W.1 key"
master106 "107-8b0"
aft107 "107-8b1" PASSED $L2ON107

# ── R1 — a typed rollback on the off path (W.1 absent: off). Before it, the reader control of step 6 (v2 A3): a copy whose
# SA_TXT_V9 is the planted text's prefix reads the plant as v9 — a READER CONTROL, never a production reading — answered no. ──
fresh107; mutant107 r1-v9 satxt -- --answer no --rollback
check "$(has "$MUT107" 'RB107 the settings text reads v9 as the workers read it (its sha256 prefix the recorded')|$(has "$MUT107" '  STOP: not confirmed')|$(live)" "yes|yes|$P107" \
  "107-8c the reader control (a copy with the planted text's prefix as SA_TXT_V9, said so — never a production reading): step 6 says v9, nothing to restore; answered no, nothing changed"
RS0_107="$(rbset107)"
rbx107 "107-8d"
check "$(has "$RBX107_OUT" 'RB107 waiting 125 s, then reading the switch and the drain again')|$(has "$RBX107_OUT" 'RB107 the settings text does not read v9 as the workers read it (')|$(has "$RBX107_OUT" 'This script prints no command that writes bot_custom_instructions')|$(grep -cE '^      .*bot_custom_instructions' <<<"$RBX107_OUT")" \
      "yes|yes|yes|0" \
  "107-8d' the off path: two readings 125 s apart, then the question; step 6 says the planted text is not v9 — and prints no restore command (the script prints none, S:9009; v3's 'with the restore command' corrected)"
check "$(has "$RBX107_OUT" 'a queued assistant-off alert is settled done as unknown on both (wa.no_ai_alert=done/0+unknown)')|$(has "$RBX107_OUT" "  ok    RB107 the installed cron/master.php is $BV107's byte for byte")|$(has "$RBX107_OUT" '  ok    LK-RB1 every ladder key, in every place, reads after the rollback exactly as before it')|$(has "$RBX107_OUT" 'RB-X after the rollback: active wa_no_ai rows ')|$(( $(rbset107) - RS0_107 ))" \
      "yes|yes|yes|yes|1" \
  "107-8d\" after the put-back: RB's assistant-off alert settled done as unknown on both (NA_RB, EP_NOAI_DONE); RB107 master $BV107's byte for byte; LK-RB1 every ladder key as before; RB-X counted; one rollback record"
RS1_107="$(rbset107)"; fresh107; O8E_107="$(run107c --rollback)"
check "$(rc107)|$(verd107 "$O8E_107")|$(has "$O8E_107" '  note  the container already serves bf29cbd (5.18.106) — nothing to roll back; running the rollback checks')|$(has "$O8E_107" 'LK-RB1 not judged: this run rolled nothing back')|$(has "$O8E_107" 'Type ROLLBACK')|$(rbset107)|$(live)" \
      "0|PASSED|yes|yes|no|$RS1_107|$B107" \
  "107-8d* --rollback again with $BV107 serving: RB_NOTHING — nothing to roll back, LK-RB1 not judged, no question asked, no record written, PASSED"

# ── RD1 — a --redeploy with a 092 WARNING planted and 092's OK line removed before it (§T.11:3485–3486): the straddle note and the
# redeploy note, PASSED. Its copy (the WARNING counted as a FAIL) as --after-only. Then master106 and --after-only PASSED. ──
K1="$(seedk107 "$(pinroot107)" log_keep r8e)"; K2="$(seedk107 "$(pinroot107)" log_drop r8e '"match":"ok092"')"; K3="$(seedk107 "$(pinroot107)" log_add r8e '"line":"warning092"')"
check "$(sd107 "$K2" removed)|$(sd107 "$K3" fixture)|$(kv107 "$(log107)" ok)|$(kv107 "$(log107)" warning)" "1|1|0|1" "107-8e0 the plants (FIXTURES): 092's OK line taken out, a 092 WARNING appended"
dredeploy107 "107-8e"
check "$(has "$DRD107" 'the tar straddle (root docs/76 §T.6, TD12)')|$(has "$DRD107" '  note  MG-CA2 no OK line for 092 in migration.log — a redeploy: 092 was applied by an earlier deploy')|$(has "$DRD107" '— not judged here:')|$(has "$DRD107" '  FAIL  MG-CA2')" "yes|yes|no|no" \
  "107-8e\" RD1: the straddle note (MG-CA1, NA-CA1 and R1 hold) and MG-CA2's redeploy note — never a FAIL"
anc107 warnfail '      note "MG-CA2 migration.log holds 092'"'"'s checksum WARNING ($wl): a read met bytes other than those recorded' '      MG_CA2_R=bad; bad "MG-CA2 migration.log holds 092'"'"'s checksum WARNING ($wl): a read met bytes other than those recorded'
idle104; fresh107; mutant107 c8e-warn warnfail -- --after-only
check "$MUTRC107|$(has "$MUT107" "  FAIL  MG-CA2 migration.log holds 092's checksum WARNING")" "1|yes" "107-8e* a weakened copy that counts the WARNING as a FAIL — caught (as --after-only)"
master106 "107-8e**"
aft107 "107-8e***" PASSED $L2ON107
check "$(sd107 "$(seedk107 "$(pinroot107)" log_back r8e)" fixture)|$(kept107)" "1|0" "107-8e+ migration.log back"

# ── R2 — W.1 on (the pin's wrapper, as printed), and a burst mark written while Type ROLLBACK waits (rbhold107; the seed's burst op,
# as the worker writes it): ROLLBACK typed, it completes; rb_pre107's reading, taken before the mark, does not list it; rb107's
# lists its conversation. Then a --check: NS-G1 NO-GO naming the key and its place, nothing changed. A fixture clears W.1 (A13). ──
OW_107="$(run_line107c "" "$W1ON107")"
check "$(rc107)|$(has "$OW_107" 'SET ai_burst_merge_enabled = 1')|$(jv107 ai_burst_merge_enabled)" '0|yes|"1"' "107-8f0 W.1 on through the pin's wrapper, as printed"
plant2_107() { seed107 "$P107R" "[{\"op\":\"burst\",\"phone\":\"$(ph107 07)\",\"name\":\"RH107SECRET- a customer\",\"text\":\"RH107SECRET- hello\",\"text2\":\"RH107SECRET- and the price\",\"instance\":\"sales-001\",\"mark\":\"aside\",\"fail\":1}]" > "$R107/logs/plant2.json"; case "$(head -c1 "$R107/logs/plant2.json")" in '{') return 0 ;; *) return 1 ;; esac; }
RS2_107="$(rbset107)"
rbhold107 107-8f ROLLBACK -- plant2_107
P2_107="$(cat "$R107/logs/plant2.json" 2>/dev/null)"; C2_107="$(sd107 "$P2_107" conv)"
PRE2_107="$(sed -n '1,/Type ROLLBACK to put /p' <<<"$HOLDOUT107")"; POST2_107="$(sed -n '/Type ROLLBACK to put /,$p' <<<"$HOLDOUT107")"
check "$HOLDSAW107|$HOLDRC107|$(sd107 "$P2_107" a_marked)|$(rc107)|$(live)|$(has "$HOLDOUT107" "  $V107 (rollback): PASSED.")|$(( $(rbset107) - RS2_107 ))" "yes|0|1|0|$B107|yes|1" \
  "107-8f R2 held at Type ROLLBACK, a burst mark written there (the seed's own exit status 0); ROLLBACK typed: PASSED, $B107 serving, one record"
check "$(has "$PRE2_107" "conversations $C2_107")|$(has "$POST2_107" "HAND THESE CONVERSATIONS TO STAFF ($BV107 CANNOT DRAIN THEM): $C2_107")|$(has "$PRE2_107" 'ai_burst_merge_enabled on; the last drain reading (a) 0,')" "no|yes|yes" \
  "107-8f' rb_pre107's readings, taken before the mark, do not list conversation $C2_107; rb107's drain after the put-back hands it to staff in capitals"
D94_8F_107="$(data94)"; fresh107; O8F_107="$(run107c --check)"
check "$(has "$O8F_107" 'NO-GO (NS-G1): ai_burst_merge_enabled in k')|$(has "$O8F_107" 'no value is read out')" "yes|yes" "107-8f\" a --check with $BV107 serving: NS-G1 NO-GO naming W.1 and its place, no value"
nogo107 "107-8f*" "NO-GO (NS-G1): " "$O8F_107" "$D94_8F_107" "$B107"
CL2_107="$(seed107 "$PD" '[{"op":"clear107","keys":["ai_burst_merge_enabled"]}]')"; BU2_107="$(seed107 "$PD" "[{\"op\":\"burst_undo\",\"ids\":[$(sd107 "$P2_107" a),$(sd107 "$P2_107" b)]}]")"
check "$(sd107 "$CL2_107" fixture)|$(sd107 "$CL2_107" places)|$(jv107 ai_burst_merge_enabled)|$(sd107 "$BU2_107" op)" "1|none|absent|burst_undo" \
  "107-8f** A13: a fixture clears W.1 from kyc_config.json and the store row (PluginConfig::saveOverrides, the known-limits route, said so); the two planted events removed"

# ── RD2 — a --redeploy with every 092 line removed before it (§T.11:3499): the redeploy note, PASSED, reset=1. Then Q on through the
# pin's set_config, as printed. ──
K1="$(seedk107 "$(pinroot107)" log_keep r8g)"; K2="$(seedk107 "$(pinroot107)" log_drop r8g '"match":"any092"')"
dredeploy107 "107-8g"
check "$(sd107 "$K2" fixture)|$(has "$DRD107" '  note  MG-CA2 no OK line for 092 in migration.log — a redeploy')|$(has "$DRD107" 'the tar straddle')" "1|yes|no" "107-8g' RD2: every 092 line gone — the redeploy note, no straddle note"
check "$(sd107 "$(seedk107 "$(pinroot107)" log_back r8g)" fixture)|$(kept107)" "1|0" "107-8g\" migration.log back"
OQ_107="$(run_line107c "" "$QON107")"
check "$(rc107)|$(has "$OQ_107" '  connection_quote_enabled = 1')|$(jv107 connection_quote_enabled)" '0|yes|"1"' "107-8g* Q on through $V107's set_config, as printed"

# ── R3's three copies, each in a PASSED --after-only with Q on — the cheapest mode reaching pb_para's K/Q lines (S:9121–9131), said
# so: the summary prints the paragraph with LK-AO's reading. The control first: the real --after-only prints Q's line alone. ──
master106 "107-8h0"
idle104; fresh107; AO8H_107="$(run107c --after-only)"
check "$(has "$AO8H_107" "connection_quote_enabled reads on (the run's latest reading of the ladder's keys): once $BV107's tools are installed none can write it. Its own command, alone, with its own log — it runs:")|$(has "$AO8H_107" "quoted_no_prices_enabled reads on (the run's latest reading")" "yes|no" \
  "107-8h0' the control: with Q on, the summary's put-back paragraph prints Q's line alone, worded from the installed set_config (it runs)"
anc107 kqoff 'q="$(rb_kq "$ref" "$k")"' 'q=off'
anc107 kline 'q="$(rb_kq "$ref" "$k")"' 'q="$(rb_kq "$ref" "$k")"; [ "$k" = quoted_no_prices_enabled ] && q=on'
anc107 oldwhy "(the run'\"'\"'s latest reading of the ladder'\"'\"'s keys): once %s'\"'\"'s tools are installed none can write it. Its own command" "(the run'\"'\"'s latest reading of the ladder'\"'\"'s keys)%.0s: after the put-back no shipped tool can write these keys. Its own command"
idle104; fresh107; mutant107 c8h-kqoff kqoff -- --after-only
check "$(has "$MUT107" "connection_quote_enabled reads on (the run's latest reading")" "no" "107-8h a weakened copy that drops the K/Q lines — caught"
idle104; fresh107; mutant107 c8h-kline kline -- --after-only
check "$(has "$MUT107" "quoted_no_prices_enabled reads on (the run's latest reading")" "yes" "107-8h' a weakened copy that prints K's line while K reads off — caught"
idle104; fresh107; mutant107 c8h-why oldwhy -- --after-only
check "$(has "$MUT107" 'after the put-back no shipped tool can write these keys')|$(has "$MUT107" "once $BV107's tools are installed none can write it")" "yes|no" "107-8h\" a weakened copy with the old reason — caught"

# ── R3 — H6 with Q on: a typed rollback on the off path whose record does not verify (5.18.106's tools installed). The stop's put-back
# paragraph: the tools reading, the switch-off and the drain worded as refused, the known-limits repair, Q's line alone. A fixture
# then clears Q. ──
: > "$SB/rbfail107.fired"; : > "$SB/.rbfail107"
RS3_107="$(rbset107)"; fresh107; O8I_107="$(run107c --answer ROLLBACK --rollback)"
check "$(rc107)|$(grep -c . "$SB/rbfail107.fired")|$( [ -e "$SB/.rbfail107" ] && echo unspent || echo spent)|$(has "$O8I_107" '  STOP: the rollback did not verify (live=')|$(has "$O8I_107" '  Nothing further was done. Send the log file.')|$(live)|$(installed107 "$B107F")" \
      "1|1|spent|yes|yes|$B107|0" \
  "107-8i R3 H6 armed: ROLLBACK typed, $BV107's files copied, the record read wrong once — the rollback did not verify; 'Nothing further was done'; $BV107's files installed"
check "$(has "$O8I_107" "lib/SalesFollowupLadder.php ${B107:0:7}'s, tools/sales_followup.php ${B107:0:7}'s, lib/BurstMerge.php the pin's, tools/set_config.php ${B107:0:7}'s.")|$(has "$O8I_107" '  The switch-off — it is refused (undeclared):')|$(has "$O8I_107" '  The drain reading — it is refused (an unknown step):')|$(has "$O8I_107" "Where the switch-off does not run, the repair is root docs/76 §T.10's known limit")" \
      "yes|yes|yes|yes" \
  "107-8i' the stop's put-back paragraph: the tools reading (the ladder, the tool and set_config $BV107's; BurstMerge the pin's); the switch-off refused (undeclared), the drain refused (an unknown step); the known-limits repair named"
check "$(has "$O8I_107" "connection_quote_enabled reads on (the run's latest reading of the ladder's keys): once $BV107's tools are installed none can write it. Its own command, alone, with its own log — it is refused (unmanaged):")|$(has "$O8I_107" "quoted_no_prices_enabled reads on (the run's latest reading")|$(grep -cxF "      $QOFF107" <<<"$O8I_107")" \
      "yes|no|2" \
  "107-8i\" Q's line alone, its reason 'once $BV107's tools are installed none can write it', its command worded as refused (unmanaged) — printed in rb_pre107's step 5 and again in the stop; no K line"
CL3_107="$(seed107 "$PD" '[{"op":"clear107","keys":["connection_quote_enabled"]}]')"
check "$(sd107 "$CL3_107" fixture)|$(jv107 connection_quote_enabled)" "1|absent" "107-8i* A13: a fixture clears Q (the known-limits route, said so)"

# ── RD3, then K on as printed; R4 — H6 with K on: K's line alone. A fixture clears K. ──
dredeploy107 "107-8j"
OK4_107="$(run_line107c "" "$KON107")"
check "$(rc107)|$(has "$OK4_107" '  quoted_no_prices_enabled = 1')|$(jv107 quoted_no_prices_enabled)" '0|yes|"1"' "107-8j' K on through $V107's set_config, as printed"
: > "$SB/rbfail107.fired"; : > "$SB/.rbfail107"
fresh107; O8K_107="$(run107c --answer ROLLBACK --rollback)"
check "$(rc107)|$(grep -c . "$SB/rbfail107.fired")|$(has "$O8K_107" "quoted_no_prices_enabled reads on (the run's latest reading of the ladder's keys): once $BV107's tools are installed none can write it. Its own command, alone, with its own log — it is refused (unmanaged):")|$(has "$O8K_107" "connection_quote_enabled reads on (the run's latest reading")|$(live)" \
      "1|1|yes|no|$B107" \
  "107-8k R4 H6 with K on: the stop's paragraph prints K's line alone (refused, unmanaged), no Q line"
CL4_107="$(seed107 "$PD" '[{"op":"clear107","keys":["quoted_no_prices_enabled"]}]')"
check "$(sd107 "$CL4_107" fixture)|$(nsd107)" "1|absent|absent|absent" "107-8k' A13: a fixture clears K — no K, Q or W.1 key"

# ── RD4; R5 — H3, the clone's index lock: the rollback's checkout refused before anything is copied — 5.18.107's tools remain, every
# command worded as running, 'it leaves '0'' beside the switch-off. The lock removed; nothing changed. ──
dredeploy107 "107-8l"
: > "$REPO/.git/index.lock"; D94_8L_107="$(data94)"
fresh107; O8L_107="$(run107c --answer ROLLBACK --rollback)"
check "$(rc107)|$(has "$O8L_107" "  FAIL  git checkout $B107 failed — nothing was deployed")|$(has "$O8L_107" "  STOP: the rollback did not verify (live=$P107)")|$(has "$O8L_107" "lib/SalesFollowupLadder.php the pin's, tools/sales_followup.php the pin's, lib/BurstMerge.php the pin's, tools/set_config.php the pin's.")" \
      "1|yes|yes|yes" \
  "107-8l R5 H3 (the clone's index lock): the checkout refused, nothing deployed, the rollback did not verify — $V107's tools remain (all four the pin's)"
check "$(has "$O8L_107" "  The switch-off — it runs, and leaves '0':")|$(has "$O8L_107" '  The drain reading — it runs:')|$(has "$O8L_107" 'Where the switch-off does not run')|$(has "$O8L_107" ' A refusal changes nothing and saves nothing.')|$(unlock106)|$(live)|$(installed107 "$P107F")|$(data94)" \
      "yes|yes|no|no|absent|$P107|0|$D94_8L_107" \
  "107-8l' every command worded as running, 'it leaves '0'' beside the switch-off, no refusal sentence; the lock removed: $P107 serving, its files, the data as before"

# ── The put-back paragraph's reading (v3 item 10), in a PASSED --after-only's summary (the cheapest mode reaching pb_ready and
# pb_para sum): W.1 off — the switch off, (a) 0 and (b) 0 — then on. Then the one-block rule's control. Then W.1 off as printed. ──
master106 "107-8m0"
aft107 "107-8m" PASSED $L2ON107
check "$(has "$AFT107" ': switch off; the drain (a) 0, counted apart 0, (b) 0, control ')|$(has "$AFT107" 'One reading, not a drain.')" "yes|yes" "107-8m' the put-back paragraph, W.1 off: one reading — switch off, (a) 0 and (b) 0"
OW2_107="$(run_line107c "" "$W1ON107")"
idle104; fresh107; AO8M_107="$(run107c --after-only)"
check "$(has "$OW2_107" 'SET ai_burst_merge_enabled = 1')|$(has "$AO8M_107" ': switch on; the drain ')" "yes|yes" "107-8m\" W.1 on (as printed): the paragraph's reading says the switch is on"
anc107 blk1 '  echo "      $ROLLBACK_CMD"' "$(printf '  echo "      $ROLLBACK_CMD"\n  echo "      $REDEPLOY_CMD"')"
idle104; fresh107; mutant107 c8m-blk blk1 -- --after-only
check "$(q107 blocks107 "$R107/mutants/run-$MUTT107.txt" | grep -oE 'adjacent=[0-9]+' | cut -d= -f2 | awk '{ print ($1 >= 1) ? "caught" : "missed" }')" "caught" \
  "107-8m* the one-block rule's control (v2 A18): a copy that prints the redeploy command beside the rollback command reads adjacent — caught"
OO2_107="$(run_line107c "" "$BDOFF107")"
check "$(has "$OO2_107" 'SET ai_burst_merge_enabled = 0')|$(jv107 ai_burst_merge_enabled)" 'yes|"0"' "107-8m** W.1 off by the switch-off as printed under $V107: it leaves '0'"

# ── V1 through H5 — each a --redeploy whose V1 redirects (V1's automatic rollback, deploy mode only, S:13950). First §K's way out: a
# leftover mark planted, a typed rollback on the off path anyway; then a --redeploy ending in V1: 'this run wrote no mark' with
# stage A's count, the stop handing the conversation to staff. Then V1 with no mark and control 0: INDETERMINATE, and its copy.
# Then --rollback: RB_NOTHING. ──
v1run107() {   # v1run107 LABEL [SCRIPT] — H5 armed, then a --redeploy (or SCRIPT's): V1 FAIL, ROLLING BACK, exit 1, H5 spent, $B107 serving; V1RD107
  local scr="${2:-$DEPLOY107}"
  arm107; fresh107; V1RD107="$(run107c --script "$scr" --answer DEPLOY --redeploy)"
  check "$(has "$V1RD107" 'V1 the sign-in page on the public address → 200 after 1 redirect(s)')|$(has "$V1RD107" '  ROLLING BACK — the public address must never redirect. Every switch kept; ai_burst_merge_enabled read off since stage A (NS-G1, LK-B1): this run wrote no mark')|$(has "$V1RD107" '  STOP: rolled back (serves bf29cbd); RB107 above reads the put-back')|$(rc107)|$(spent99 .redirect107)|$(live)" \
        "yes|yes|yes|1|spent|$B107" \
    "$1 V1 through H5: the redeploy's sign-in page redirects once — V1 FAIL, ROLLING BACK (this run wrote no mark), the automatic rollback verified, exit 1, H5 spent, $B107 serving"
}
M9_107="$(seed107 "$P107R" "[{\"op\":\"burst\",\"phone\":\"$(ph107 08)\",\"name\":\"RH107SECRET- a customer\",\"text\":\"RH107SECRET- hello\",\"text2\":\"RH107SECRET- the price\",\"instance\":\"sales-001\",\"mark\":\"aside\",\"fail\":1}]")"
C9_107="$(sd107 "$M9_107" conv)"
check "$(sd107 "$M9_107" a_marked)|$(sd107 "$M9_107" b_status)" "1|failed" "107-8n0 a leftover mark planted under $V107 (the seed's burst op, as the worker writes it): conversation $C9_107"
rbx107 "107-8n"
check "$(has "$RBX107_OUT" 'ANSWER ANYTHING BUT ROLLBACK BELOW')|$(has "$RBX107_OUT" "the conversations $C9_107,")|$(has "$RBX107_OUT" "HAND THESE CONVERSATIONS TO STAFF ($BV107 CANNOT DRAIN THEM): $C9_107")" "yes|yes|yes" \
  "107-8n' §K's way out: the drain does not hold (the capitals, its ids), the rollback typed anyway; after it the drain hands conversation $C9_107 to staff"
v1run107 "107-8o"
check "$(has "$V1RD107" "this run wrote no mark; stage A counted leftover marks — (a) 1, ")|$(has "$V1RD107" 'the drain after the rollback lists what still waits')|$(has "$V1RD107" "HAND THESE CONVERSATIONS TO STAFF ($BV107 CANNOT DRAIN THEM): $C9_107")|$(has "$V1RD107" '(V1'"'"'s automatic rollback: against the deploy'"'"'s LK-B1)')" \
      "yes|yes|yes|yes" \
  "107-8o' the V1 stop: stage A's count of leftover marks beside 'this run wrote no mark'; the stop hands conversation $C9_107 to staff; LK-RB1 against the deploy's LK-B1"
BU9_107="$(seed107 "$PD" "[{\"op\":\"burst_undo\",\"ids\":[$(sd107 "$M9_107" a),$(sd107 "$M9_107" b)]}]")"
check "$(sd107 "$BU9_107" op)" "burst_undo" "107-8o* the planted events removed"
clkfwd107 $((2 * 86400 + 600))   # no typed ai.reply in the last day, whatever the book holds: control 0 (said so)
v1run107 "107-8p"
check "$(has "$V1RD107" 'stage A counted leftover marks')|$(has "$V1RD107" "THE PIN'S DRAIN AFTER THE ROLLBACK IS INDETERMINATE — NO TYPED MESSAGE IN THE LAST DAY ((a) 0, counted apart 0, (b) 0, control 0)")" "no|yes" \
  "107-8p' V1 with no mark and control 0 (the clock two days on, said so): no count beside 'this run wrote no mark'; the stop says INDETERMINATE — never 'nothing to drain'"
anc107 ctl0 'if [ "$(kv "$1" control)" = "0" ]; then printf "THE PIN' 'if false; then printf "THE PIN'
WK_107="$(weak107 v1-ctl0 ctl0)"; v1run107 "107-8q" "$WK_107"; rm -f "$WK_107"
check "$(has "$V1RD107" 'INDETERMINATE')|$(has "$V1RD107" "the pin's drain after the rollback, on a copy of the book: (a) 0, counted apart 0, (b) 0, control 0 — it lists no conversation")" "no|yes" \
  "107-8q' a weakened copy that reads control 0 as a reading — 'it lists no conversation' — caught (a V1 run of its own)"
RS9_107="$(rbset107)"; fresh107; O9R_107="$(run107c --rollback)"
check "$(rc107)|$(has "$O9R_107" 'nothing to roll back; running the rollback checks')|$(has "$O9R_107" 'LK-RB1 not judged: this run rolled nothing back')|$(rbset107)" "0|yes|yes|$RS9_107" "107-8r rb107 and RB_NOTHING after V1's rollback: nothing to roll back, LK-RB1 not judged, no record"

# ════════════════════════════════════════════════════════════════════════════
# 107-9 — the final step: --redeploy (NS-G1 GO with W.1 '0' — the switch-off's own '0', left by 107-8m** under 5.18.107 and kept by
# 107-8n's rollback), master106, --after-only PASSED with the owner's steps; steps 1 and 3 as printed; the drain through the
# wrapper; the put-back's and step 5's set_config --value 0 lines; K and W.1 off as printed; the one-block rule over every run.
# Exit: 5.18.107 serving, K, Q and W.1 off.
# ════════════════════════════════════════════════════════════════════════════
echo; echo "== 107-9. the final redeploy (NS-G1 GO with '0'), the PASSED --after-only and the owner's steps run as printed; the one-block rule =="
check "$(jv107 ai_burst_merge_enabled)|$(live)" '"0"|'"$B107" "107-9-0 W.1 reads '0' in kyc_config.json (the switch-off's own value, kept by the rollbacks since), $BV107 serving"
dredeploy107 "107-9a"
master106 "107-9b0"
aft107 "107-9b" PASSED $L2ON107
K9ON_107="$(printed107 "$AFT107" '--key quoted_no_prices_enabled --value 1')"; Q9ON_107="$(printed107 "$AFT107" '--key connection_quote_enabled --value 1')"; W9ON_107="$(printed107 "$AFT107" 'set ai_burst_merge_enabled 1')"
check "$(has "$AFT107" "  the owner's steps root docs/76 §K — $V107 serves and this --after-only PASSED")|$( [ "$K9ON_107" = "$KON107" ] && echo K)|$( [ "$Q9ON_107" = "$QON107" ] && echo Q)|$( [ "$W9ON_107" = "$W1ON107" ] && echo W)" "yes|K|Q|W" \
  "107-9b' the owner's steps printed, each command alone: K's, Q's and W.1's, as 107-7z's run printed them"
OS1_107="$(run_line107c "" "$K9ON_107")"; OS3_107="$(run_line107c "" "$W9ON_107")"
check "$(has "$OS1_107" '  quoted_no_prices_enabled = 1')|$(has "$OS3_107" 'SET ai_burst_merge_enabled = 1')|$(nsd107)" 'yes|yes|"1"|absent|"1"' "107-9c steps 1 and 3 run as printed, each alone with its own log: K on, W.1 on"
DR9_107="$(printed107 "$AFT107" '--check burst-drain')"; OD9_107="$(run_line107c "" "$DR9_107")"
check "$DR9_107|$(has "$OD9_107" 'burst-drain: (a) 0 mark(s) naming an event that has not run yet')|$(has "$OD9_107" 'burst-drain: control: ')" "$BDDR107|yes|yes" "107-9d the drain through the wrapper, as printed: it reads (a) 0, with its control line"
idle104; fresh107; AO9_107="$(run107c --after-only)"; KOFFP_107="$(printed107 "$AO9_107" '--key quoted_no_prices_enabled --value 0')"
rbno107
KOFFS_107="$(printed107 "$RBNO107" '--key quoted_no_prices_enabled --value 0')"
check "$( [ -n "$KOFFP_107" ] && [ "$KOFFP_107" = "$KOFF107" ] && echo putback)|$( [ "$KOFFS_107" = "$KOFF107" ] && echo step5)|$(has "$RBNO107" "quoted_no_prices_enabled READS ON (NS-G1'S RULE, ON LK-RB0)")" "putback|step5|yes" \
  "107-9e K's off line, printed by the put-back paragraph (an --after-only with K on) and by step 5 (a rollback answered no, W.1 on: no wait) — the same command both times"
rbq107 "107-9e'"
OK9_107="$(run_line107c "" "$KOFFP_107")"; OW9_107="$(run_line107c "" "$BDOFF107")"
check "$(has "$OK9_107" '  quoted_no_prices_enabled = 0')|$(has "$OW9_107" 'SET ai_burst_merge_enabled = 0')|$(nsd107)" 'yes|yes|"0"|absent|"0"' "107-9f K and W.1 off again by their printed off lines"
BL9_107="$(q107 blocks107 "$R107"/run-*.txt)"
check "$(kv107 "$BL9_107" mixed)|$(kv107 "$BL9_107" adjacent)|$( [ "$(n103 "$(kv107 "$BL9_107" cmds)")" -gt 0 ] && echo read)" "0|0|read" \
  "107-9g the one-block rule (v2 A18) over every run's output ($(kv107 "$BL9_107" files) files, $(kv107 "$BL9_107" cmds) command lines): no rollback command beside another, no two of the five without a prose line between"
master106 "107-9z0"
aft107 "107-9z" PASSED $L2ON107
check "$(live)|$(nsd107)|$(kept107)|$(LEFT107)" "$P107|\"0\"|absent|\"0\"|0|none" "107-9zz the exit: $P107 serving, K '0', Q absent, W.1 '0' — all three off; nothing kept, no trigger armed"
# ════════════════════════════════════════════════════════════════════════════
# 107-10 — what the rehearsal left behind, over 104's, 105's, 106's and 107's records alike (v2 §B 107-10, with v3's additions:
# the boots' output files, the stand-in log's fatal check, the leftover reading over 106's and 107's triggers, the ledger071
# undo). 5.18.106's 106-10 is cut with its rehearsal (107-cut), so what it judged is judged here again, over 106's records and
# 107's alike, by this file's own lines. Every file this section plants is a CONTROL of a reading (zz107ctl, zz-control,
# key-control, .zz107ctl.sh), said so where it is planted, removed at once and asserted gone in 107-10f. No number of 107's
# range is planted or newly used here.
# ════════════════════════════════════════════════════════════════════════════
echo; echo "== 107-10. what the rehearsal left behind, over 104's, 105's, 106's and 107's records alike: the sweeps, the fixtures' leftovers, the chain's failure files =="
# 107-10a — Evolution (106-10a's reading, carried): no run of 5.18.107's, 5.18.106's or 5.18.105's script called it, every run
# summed; the cron stand-in ran its worker during none of them; every request since 105 began on the stand-in's own base paths;
# outside every run, since 106-pre, no fewer calls than the sends the stand-in refused for J1's numbers (106-6), and since 105
# began no fewer than 105's drives counted
EVP107="${EVO_URL:-}"; EVP107="/${EVP107#http://*/}"
EVQ107="${EVF106:-}"; EVQ107="/${EVQ107#http://*/}"
EVA107=$(( $(evo_calls) - EVO0_106 )); EVA5_107=$(( EVO0_106 - EVO0_105 )); RFJ107=$(( $(refused106) - $(n103 "${RF4_106:-}") ))
EVN107="$(cat "$R107"/evo/run-*.n "$R106"/evo/run-*.n "$R105"/evo/run-*.n 2>/dev/null | awk '{s += $1} $2 == "cron" {c++} END {printf "%d|%d\n", s, c}')"
check "$EVN107|$(tail -n +"$(( EVO0_105 + 1 ))" "$SB/evo/requests.log" 2>/dev/null | grep -cvE "^(GET|POST) ($EVP107|$EVQ107)/")|$( [ -n "${RF4_106:-}" ] && [ "$RFJ107" -ge 15 ] && [ "$EVA107" -ge "$RFJ107" ] && echo ge)|$( [ -n "${EVODRV105:-}" ] && [ "$EVA5_107" -ge "$(n103 "${EVODRV105:-}")" ] && echo ge)" "0|0|0|ge|ge" \
  "107-10a Evolution: no run of $V107's script, $V106's or $V105's called it ($(ls "$R107"/evo/run-*.n "$R106"/evo/run-*.n "$R105"/evo/run-*.n 2>/dev/null | wc -l | tr -d ' ') runs read, every one summed); the cron stand-in ran its worker during none of them; every request on the stand-in's own base paths; outside every run, calls at least the sends refused for J1's numbers ($RFJ107) and 105's drives' own"
# 107-10b — uCRM (106-10b's reading, carried): every run's request record GETs only, no token copy left at the next call — 107's,
# 106's, 105's and 104's runs; nothing of any of them left in /tmp. The CONTROL a planted POST and a planted token copy, removed
SCN107="$R107/scan107"; mkdir -p "$SCN107"; chmod 700 "$SCN107"
UO107="$(uo106 "$R107"/ucrm/run-*.req "$R106"/ucrm/run-*.req "$R105"/ucrm/run-*.req "$R104"/ucrm/run-*.req)"; TK107="$(tl106 "$R107"/tok/run-*.tok "$R106"/tok/run-*.tok "$R105"/tok/run-*.tok "$R104"/tok/run-*.tok)"
printf 'GET /organizations app-key\nPOST /clients app-key\n' > "$SCN107/zz-control.req"; printf '0 x\n1 remote\n1 y\n' > "$SCN107/zz-control.tok"
check "$UO107|$TK107|$(ls -d /tmp/dnb-$V107-* /tmp/dnb-$V106-* /tmp/dnb-$V105-* /tmp/dnb-$V104-* /tmp/dnb-report-* 2>/dev/null | wc -l | tr -d ' ')|$(for d107 in "$R107" "$R106" "$R105" "$R104"; do ls "$d107"/ucrm/run-*.req 2>/dev/null | grep -c . | awk '{printf "%s", ($1 > 0) ? "r" : "-"}'; done)|$(uo106 "$SCN107/zz-control.req")$(tl106 "$SCN107/zz-control.tok")" \
      "0|0|0|rrrr|11" \
  "107-10b uCRM asked GETs only in every run of $V107, $V106, $V105 and $V104; no token copy left; nothing of any of them in /tmp; controls: a planted POST and a planted token copy left, each found"
rm -f "$SCN107/zz-control.req" "$SCN107/zz-control.tok"
# 107-10c — 107's own records (every terminal, mutant terminal, sheet, log, stream, state and rollback record, row digest and case
# file, backups aside), and what else 107's runs and plants wrote: the master passes (master106's files, which 107's calls write
# too), the held actions' output, the uCRM request records, the boots' output files (v3 FM7: H2's, H4's and boot107's) and the
# installed worker's output (107-8f). Scanned with both of 107's pattern files (the chain's names and RH107SECRET-; every number of
# the chain's ranges and 107's in its four forms and last nine digits). A CONTROL file holding one of each, planted through the
# list as a run terminal and as a boot output, found by both scans, removed.
sweep107() { { ls "$R107"/run-*.txt "$R107"/mutants/*.txt "$R107"/sheets/* "$R107"/logs/*.log "$R107"/logs/stream-*.txt "$OUT107"/state-* "$OUT107"/rollback-state-* "$OUT107"/cih-rows-* "$OUT107"/*.log "$OUT107"/cash-*.txt; \
               find "$R107/case" -type f ! -path '*/backup-*/*' 2>/dev/null; } 2>/dev/null | grep -v '/lk-key' | sort -u; }
sweepx107() { ls -1 "$R104"/logs/master-106m-*.out "$R104"/logs/master-106s-*.out "$R107"/logs/act-*.txt "$R107"/ucrm/run-*.req "$R107"/boot/*.out "$R107"/logs/worker107.out 2>/dev/null; }
sweepall107() { { sweep107; sweepx107; } | sort -u; }
printf 'RH107SECRET-control %s\n' "$(grep -m1 -F '7001070' "$FORMS107")" > "$SCN107/control.txt"; chmod 600 "$SCN107/control.txt"
NHS107="$(sweepall107 | xargs -r grep -lF -f "$NM107F" 2>/dev/null | wc -l | tr -d ' ')"; FHS107="$(sweepall107 | xargs -r grep -lF -f "$FORMS107" 2>/dev/null | wc -l | tr -d ' ')"
SWR107="$(ls "$R107"/run-*.txt 2>/dev/null | grep -c .)"; SWB107="$(sweepx107 | grep -c '/boot/')"
cp "$SCN107/control.txt" "$R107/run-zz107ctl.txt"; cp "$SCN107/control.txt" "$R107/boot/zz107ctl.out"
SWC107="$(for p107 in run-zz107ctl.txt boot/zz107ctl.out; do printf '%s%s ' "$(sweepall107 | grep -F "/$p107" | xargs -r grep -lF -f "$NM107F" 2>/dev/null | wc -l | tr -d ' ')" "$(sweepall107 | grep -F "/$p107" | xargs -r grep -lF -f "$FORMS107" 2>/dev/null | wc -l | tr -d ' ')"; done | sed 's/ $//')"
rm -f "$R107/run-zz107ctl.txt" "$R107/boot/zz107ctl.out"
check "$NHS107|$FHS107|$(grep -lF -f "$NM107F" "$SCN107/control.txt" | wc -l | tr -d ' ')$(grep -lF -f "$FORMS107" "$SCN107/control.txt" | wc -l | tr -d ' ')|$( [ "$SWR107" -gt 0 ] && [ "$SWB107" -gt 0 ] && echo listed)|$SWC107" "0|0|11|listed|11 11" \
  "107-10c no planted name (the chain's names, RH106SECRET-, RH107SECRET-) and no fixture number (the chain's ranges and $V107's, each in its four forms and its last nine digits) in any of $V107's terminals, logs, sheets, streams, state and rollback records, row digests, case files (their backups aside), master passes, held actions, request records, boot outputs or the worker's output ($(sweepall107 | grep -c .) files); the control found through the list as a run terminal and as a boot output"
# 107-10c' — 106's records by 106's own list (sweep106) and its extra writers (106-10c's sweepx106: master passes, held actions,
# request records), 105's by 105-10's (sweep105) — not swept by a 106-10 (cut with the chain); the CONTROL planted as one of each's
# run terminals, found by both scans through its list, removed
sweepx106_107() { ls -1 "$R106"/logs/act-*.txt "$R106"/ucrm/run-*.req 2>/dev/null; }
NH6_107="$( { sweep106; sweepx106_107; } | sort -u | xargs -r grep -lF -f "$NM107F" 2>/dev/null | wc -l | tr -d ' ')"; FH6_107="$( { sweep106; sweepx106_107; } | sort -u | xargs -r grep -lF -f "$FORMS107" 2>/dev/null | wc -l | tr -d ' ')"
NH5_107="$(sweep105 | xargs -r grep -lF -f "$NM107F" 2>/dev/null | wc -l | tr -d ' ')"; FH5_107="$(sweep105 | xargs -r grep -lF -f "$FORMS107" 2>/dev/null | wc -l | tr -d ' ')"
cp "$SCN107/control.txt" "$R106/run-zz107ctl.txt"; cp "$SCN107/control.txt" "$R105/run-zz107ctl.txt"
SWC65_107="$(sweep106 | grep -F '/run-zz107ctl.txt' | xargs -r grep -lF -f "$NM107F" 2>/dev/null | wc -l | tr -d ' ')$(sweep106 | grep -F '/run-zz107ctl.txt' | xargs -r grep -lF -f "$FORMS107" 2>/dev/null | wc -l | tr -d ' ') $(sweep105 | grep -F '/run-zz107ctl.txt' | xargs -r grep -lF -f "$NM107F" 2>/dev/null | wc -l | tr -d ' ')$(sweep105 | grep -F '/run-zz107ctl.txt' | xargs -r grep -lF -f "$FORMS107" 2>/dev/null | wc -l | tr -d ' ')"
rm -f "$R106/run-zz107ctl.txt" "$R105/run-zz107ctl.txt"
check "$NH6_107|$FH6_107|$NH5_107|$FH5_107|$( [ "$(sweep106 | grep -c .)" -gt 0 ] && [ "$(sweep105 | grep -c .)" -gt 0 ] && echo listed)|$SWC65_107" "0|0|0|0|listed|11 11" \
  "107-10c' …nor in any of $V106's records (sweep106 and its held actions and request records: $( { sweep106; sweepx106_107; } | sort -u | grep -c .) files) or $V105's (sweep105: $(sweep105 | grep -c .) files); the control found through each list"
# 107-10c'' — 104's records by 104-10's own lists (106-10c'''s reading, carried: the three one-line definitions read from 5.18.104's
# rehearsal, each the start of exactly one line after 104-10's heading, sourced unchanged); a CONTROL where each looks
L104F_107="$(mktemp)"
python3 -I - "$H104_HARNESS105" "$L104F_107" <<'PY' || echo "rh107:lists104" >> "$SB/anchor-missing"
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
. "$L104F_107"; rm -f "$L104F_107"
sweep104_107() { { sweep_files104; sheets104; ls -1 "$OUT104"/cash-*.txt 2>/dev/null; reqs104; } | sort -u; }
NH4_107="$(sweep104_107 | xargs -r grep -lF -f "$NM107F" 2>/dev/null | wc -l | tr -d ' ')"; FH4_107="$(sweep104_107 | xargs -r grep -lF -f "$FORMS107" 2>/dev/null | wc -l | tr -d ' ')"
mkdir -p "$R104/sheets" "$R104/ucrm"
for f107 in "$R104/run-zz107ctl.txt" "$R104/sheets/cash-zz107ctl.txt" "$R104/ucrm/run-zz107ctl.req"; do cp "$SCN107/control.txt" "$f107"; done
CL4_107="$(for l107 in sweep_files104 sheets104 reqs104; do printf '%s%s ' "$("$l107" 2>/dev/null | grep -F 'zz107ctl' | xargs -r grep -lF -f "$NM107F" 2>/dev/null | wc -l | tr -d ' ')" "$("$l107" 2>/dev/null | grep -F 'zz107ctl' | xargs -r grep -lF -f "$FORMS107" 2>/dev/null | wc -l | tr -d ' ')"; done | sed 's/ $//')"
rm -f "$R104/run-zz107ctl.txt" "$R104/sheets/cash-zz107ctl.txt" "$R104/ucrm/run-zz107ctl.req" "$SCN107/control.txt"
check "$NH4_107|$FH4_107|$CL4_107" "0|0|11 11 11" \
  "107-10c'' …nor in any of $V104's records, which 107's runs write too (sf.txt, the wrappers' logs of every command run as printed: $(sweep104_107 | grep -c .) files, by 104-10's own lists); the control found through each"
# 107-10d — the LK key files 5.18.107 wrote (its run folder's and the case folders'), each mode 0600 and read; no key's value in
# any file the sweeps above read; the CONTROL a copy of the keys, found
KL107="$( { [ -e "$LKF107" ] && echo "$LKF107"; find "$R107/case" -type f -name "lk-key-$V107" 2>/dev/null; } | sort -u)"
: > "$SCN107/key.pat"; chmod 600 "$SCN107/key.pat"; SK107=""
for f107 in $KL107; do SK107="$SK107 $(q105 secret "$f107" "$SCN107/key1.pat" | tr ' ' '/')"; [ -f "$SCN107/key1.pat" ] && cat "$SCN107/key1.pat" >> "$SCN107/key.pat"; rm -f "$SCN107/key1.pat"; done
SK107="${SK107# }"
KH107="$( { sweepall107; sweep106; sweep105; sweep104_107; } 2>/dev/null | sort -u | xargs -r grep -lF -f "$SCN107/key.pat" 2>/dev/null | wc -l | tr -d ' ')"
cp "$SCN107/key.pat" "$SCN107/key-control.txt"
check "$( [ "$(grep -c . <<<"$KL107")" -ge 1 ] && echo some)|$(tr ' ' '\n' <<<"$SK107" | grep -vcx 'mode=600/key=read')|$KH107|$(grep -lF -f "$SCN107/key.pat" "$SCN107/key-control.txt" 2>/dev/null | wc -l | tr -d ' ')" "some|0|0|1" \
  "107-10d every LK key file $V107 wrote ($(grep -c . <<<"$KL107") file(s), $(sort -u "$SCN107/key.pat" | grep -c .) key value(s)) mode 0600 and read; no key's value in any record of 107's, 106's, 105's or 104's; the control found"
rm -f "$SCN107/key.pat" "$SCN107/key-control.txt"
# 107-10e — 107's own numbers and marker in the plugin's data folders and the records: in no file but the book — and, for the
# marker alone, kyc_config.json, which holds 107-pre's planted settings text (the one fixture that stays by design, v2 A3, said
# so: its numbers are none). The control: the book holds both
sqx "PRAGMA wal_checkpoint(TRUNCATE)" >/dev/null 2>&1
ND1_107="$( { grep -rlaE --exclude='plugin.sqlite3*' "$RX107" "$DATA" "$PD/data" "$RECS"; grep -rlaF --exclude='plugin.sqlite3*' --exclude='kyc_config.json' 'RH107SECRET-' "$DATA" "$PD/data" "$RECS"; } 2>/dev/null | sort -u)"
check "$(grep -c . <<<"$ND1_107")|$(grep -caF 'RH107SECRET-' "$DATA/kyc_config.json" 2>/dev/null)|$(grep -caE "$RX107" "$DATA/kyc_config.json" 2>/dev/null)|$( [ "$(n103 "$(grep -caE "$RX107" "$DATA/plugin.sqlite3" 2>/dev/null)")" -ge 1 ] && echo numbers)|$( [ "$(n103 "$(grep -caF 'RH107SECRET-' "$DATA/plugin.sqlite3" 2>/dev/null)")" -ge 1 ] && echo marker)" \
      "0|1|0|numbers|marker" \
  "107-10e $V107's own numbers ($PR107<NN>) and its RH107SECRET- marker in no file of the plugin's data folders or the records but the book — the marker once in kyc_config.json, the planted settings text (107-pre5, kept by design), with none of 107's numbers ($(grep -c . <<<"$ND1_107") other file(s)$( [ -n "$ND1_107" ] && printf ': %s' "$(xargs -r -n1 basename <<<"$ND1_107" | paste -sd, -)")); the control: the book holds both"
# 107-10f — the fixtures' leftovers, each asserted gone: every trigger of 106's and 107's (LEFT107, v3); the clone's index lock;
# the weakened copies (every $REPO/scripts/.NAME.sh newer than this release's script; the CONTROL one planted, counted, removed);
# the keep folders empty (107's, and the case's state-file keep), then removed; NA-CA1's sentinel row gone; 071's ledger checksum
# as 107-4 read it (the ledger071 undo, v3); migration.log writable as found; H2's refusal record empty; no [master] line of
# 107-7's plants left; neither RH107_RANGE nor RH106_TOUCH in the rehearsal's own environment
WC0_107="$(find "$REPO/scripts" -maxdepth 1 -name '.*.sh' -newer "$DEPLOY107" 2>/dev/null | grep -c .)"
cp "$DEPLOY107" "$REPO/scripts/.zz107ctl.sh"; touch "$REPO/scripts/.zz107ctl.sh"; WC1_107="$(find "$REPO/scripts" -maxdepth 1 -name '.*.sh' -newer "$DEPLOY107" 2>/dev/null | grep -c .)"; rm -f "$REPO/scripts/.zz107ctl.sh"
KN107="$(kept107)|$(ls -A "$STK107" 2>/dev/null | grep -c .)"
check "$(LEFT107)|$(lock106)|$WC0_107:$WC1_107:$(find "$REPO/scripts" -maxdepth 1 -name '.*.sh' -newer "$DEPLOY107" 2>/dev/null | grep -c .)|$KN107|$(kv107 "$(ledgerx107)" sentinel)|$(kv107 "$(ledgerx107)" cks071)|$(stat -c %a "$DATA/migration.log")|$(grep -c . "$SB/plant092touch107.refused" 2>/dev/null)|$(grep -c 'RH107SECRET- a thrown error' "$PD/data/plugin.log" 2>/dev/null)|$(env | grep -cE '^(RH107_RANGE|RH106_TOUCH)=')" \
      "none|absent|0:1:0|0|0|0|$(kv107 "$LX4_107" cks071)|$(stat -c %a "$SNAP107/$(sha256sum <<<"$DATA/migration.log" | cut -c1-16)" 2>/dev/null)|0|0|0" \
  "107-10f the fixtures' leftovers gone: no trigger of 106's or 107's armed, no index lock, no weakened copy left (the control planted, counted, removed), nothing kept, NA-CA1's sentinel row gone, 071's checksum as 107-4 read it (ledger071 undone), migration.log's mode as the snapshot's, H2 never refused a backward clock, no planted [master] ERROR left, neither range nor touch flag in the rehearsal's own environment"
rm -rf "${SCN107:?}"
# 107-10g — the stand-in web server's log (v3 FM6): no new Fatal or Parse error since 107-infra f's relaunch; every boot output of
# 107's says booted
WF1X_107="$(grep -cE 'Fatal error|Parse error' "$SB/web.log" 2>/dev/null)"
BO107="$(for f107 in "$R107"/boot/*.out; do [ -s "$f107" ] || continue; grep -qx 'booted' "$f107" || echo "${f107##*/}"; done | paste -sd, -)"
check "$(( $(n103 "$WF1X_107") - $(n103 "$WF0X_107") ))|$( [ "$(grep -c . "$SB/web.log")" -gt "$(n103 "$WL0_107")" ] && echo grew)|${BO107:-all-booted}" "0|grew|all-booted" \
  "107-10g the stand-in's log: no new Fatal or Parse error since its relaunch on the clock (it grew: every request since was logged); every boot output of 107's (H2's, H4's, boot107's) says booted"
# 107-10h — 106's sections' failure files (106-10g/h, cut with 106-10): from 106's baselines (AM0_106, WF0_106, BR0_106) to 107's
# (AM0_107, WF0_107, BR0_107), and the control of the controls over every anchor 106's copies set (ancc106)
AMS6_107=$(( AM0_107 - $(n103 "${AM0_106:-}") )); WFS6_107=$(( WF0_107 - $(n103 "${WF0_106:-}") )); AC6_107="$(ancc106)"
check "$( [ -n "${AM0_106:-}" ] && [ -n "${WF0_106:-}" ] && echo set || echo unset)|$AMS6_107|$(( $(n103 "$(grep -c '^run106-' "$SB/bad-runs" 2>/dev/null)") - $(n103 "${BR0_106:-}") ))|$WFS6_107|$( [ "$AMS6_107" -gt 0 ] && tail -n +$(( $(n103 "${AM0_106:-}") + 1 )) "$SB/anchor-missing" 2>/dev/null | head -n "$AMS6_107" | paste -sd, -)|$(cut -d'|' -f1,3 <<<"$AC6_107")" \
      "set|0|0|0||0|-" \
  "107-10h $V106's sections (106-10g and 106-10h, cut with 106-10, judged here): every asserted edit and anchor found, no run of 106's runners given an unknown option, no bounded wait timed out; every anchor 106's copies set ($(cut -d'|' -f2 <<<"$AC6_107")) still its script's own text, once"
# 107-10i — 107's own: anchor-missing, wait104.fail and bad-runs against 107-infra's baselines; ancc107 over every anchor 107's copies set
AM1_107="$(grep -c . "$SB/anchor-missing" 2>/dev/null)"; AM1_107="${AM1_107:-0}"; WF1_107="$(grep -c . "$R104/wait104.fail" 2>/dev/null)"; WF1_107="${WF1_107:-0}"
BR1_107="$(grep -c '^run107-' "$SB/bad-runs" 2>/dev/null)"; AC107="$(ancc107)"
check "$(( AM1_107 - AM0_107 ))|$(( $(n103 "$BR1_107") - BR0_107 ))|$(( WF1_107 - WF0_107 ))|$(tail -n +$(( AM0_107 + 1 )) "$SB/anchor-missing" 2>/dev/null | paste -sd, -)|$(cut -d'|' -f1,3 <<<"$AC107")" "0|0|0||0|-" \
  "107-10i $V107's own sections: every asserted edit, anchor and plant found, no run of 107's runners given an unknown option, no bounded wait timed out; every anchor its weakened copies set ($(cut -d'|' -f2 <<<"$AC107")) still the script's own text, found in it once, its replacement different"
# 107-10j — the end state: 107-9 left 5.18.107 serving, every file 5.18.107 changes as the pin has it, K, Q and W.1 off; this
# checkout's status exactly as the rehearsal found it
check "$(live)|$(installed107 "$P107F")|$(nsd107)|$(git -C "$R" status --porcelain 2>/dev/null | sha256sum | cut -c1-16)" "$P107|0|\"0\"|absent|\"0\"|$ST0_107" \
  "107-10j the end state: $P107 ($(inst_ver)) serving, every file $V107 changes as the pin has it, K '0', Q absent, W.1 '0'; this checkout's status exactly as the rehearsal found it"

echo
echo "RESULT: $PASS passed, $FAILN failed — of them $FAIL_PRE107 before $V107's own sections (the earlier rehearsals' chain)"
[ "$FAILN" = 0 ]
