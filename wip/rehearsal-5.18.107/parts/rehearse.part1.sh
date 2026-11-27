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
            rc106 sv106 sweep106 nr106 tst106 lock106 unlock106 refuse106 backups106 states106 keyf106 keysum106 \
            run104 fresh104 weak104 live104 sf104 sf104c sfrc104 clk_set104 clk_to104 fnow104 kat104 kday104 kdow104 dsnap104 drest104 \
            kv104 q104 master104 sched104 ledger104 owner104 sv104 install_commit \
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
