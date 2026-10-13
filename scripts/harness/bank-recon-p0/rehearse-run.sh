#!/usr/bin/env bash
# rehearse-run.sh — the gated run (scripts/bank-recon-p0-run.sh) and its hand-over line from docs/68 §P, in a sandbox:
# a bare "origin" holding the branch, a server-shaped checkout cloned BEFORE the P0 commits existed, a synthetic book
# behind the strict fake docker. The hand-over line is read out of docs/68 and run as written, with only its two server
# paths and its two pins replaced by the sandbox's (each replacement asserted). It proves:
#   H   the documented line runs the runner it pins and nothing else: a wrong sha256 stops it before anything runs;
#   R1  the gates pass on a clean checkout that does not even hold the P0 files; P0 runs once, as the owner, on the
#       reviewed files taken out of the commit; its figures equal the plugin's own; the checkout — HEAD, branches, index
#       (not even refreshed), every file — is byte-for-byte as before; the log is 0600 in a 0700 folder;
#   R2–R14  each gate refuses, before P0 and changing nothing: tracked changes, staged changes, an operation in progress,
#       untracked plugin files, a missing commit, a changed tool, a command pinning another tool, a rewritten branch, an
#       unexpected data folder, an unexpected owner, a loose log folder, a bad option, not root;
#   R15 P0's own stop (exit 3) is reported and NOT retried; R16 a git repository above the work folder is not taken for
#       the checkout;
#   M   weakened copies of the runner, each caught.
# Fictitious data only. Refuses to run where the server could be.
#
#   bash scripts/harness/bank-recon-p0/rehearse-run.sh        KEEP=1 keeps the sandbox; MUTANTS=0 skips the weakened copies
set -u
for f in /data/ucrm /opt/dishnet /var/run/docker.sock /root/dnb-bank-recon; do
  [ -e "$f" ] && { echo "refusing: $f exists — this looks like the server, and this rehearsal must never run there"; exit 2; }
done
[ "$(id -u)" = 0 ] || { echo "run as root"; exit 2; }
H="$(cd "$(dirname "$0")" && pwd)"; R="$(cd "$H/../../.." && pwd)"
RUN0="$R/scripts/bank-recon-p0-run.sh"; DOC="$R/docs/68-bank-reconciliation-discovery-2026-10-13.md"
SB="$(mktemp -d /tmp/bank-recon-run.XXXXXX)"; chmod 755 "$SB"
trap '[ -n "${KEEP:-}" ] && echo "kept: $SB" || rm -rf "$SB"' EXIT
pass=0; fails=0
ok()  { pass=$((pass+1)); echo "  ok   $*"; }
bad() { fails=$((fails+1)); echo "  FAIL $*"; }
chk() { local m="$1"; shift; if "$@"; then ok "$m"; else bad "$m"; fi; }
O=1000:1000; own() { setpriv --reuid=1000 --regid=1000 --clear-groups "$@"; }
G() { git -c user.name=rh -c user.email=rh@example.invalid -c advice.detachedHead=false "$@"; }

# ── The reviewed files, and the pins the documented hand-over carries ─────────────────────────────────────────────────
P0C="$(sed -n 's/^P0_COMMIT="\([0-9a-f]\{40\}\)"$/\1/p' "$RUN0")"
TSHA="$(sed -n 's/^TOOL_SHA="\([0-9a-f]\{64\}\)"$/\1/p' "$RUN0")"; WSHA="$(sed -n 's/^WRAP_SHA="\([0-9a-f]\{64\}\)"$/\1/p' "$RUN0")"
git -C "$R" cat-file blob "$P0C:scripts/bank-recon-p0.php" > "$SB/tool.php" && git -C "$R" cat-file blob "$P0C:scripts/bank-recon-p0.sh" > "$SB/wrap.sh" \
  || { echo "the reviewed commit $P0C is not in this checkout"; exit 3; }
chk "the runner pins the reviewed commit, and its two sha256s are that commit's files" \
  bash -c "[ \"\$(sha256sum < '$SB/tool.php' | cut -c1-64)\" = '$TSHA' ] && [ \"\$(sha256sum < '$SB/wrap.sh' | cut -c1-64)\" = '$WSHA' ]"
LINE="$(grep -m1 '^    umask 077 && mkdir -p /root/dnb-bank-recon && cd /opt/dishnet && git fetch' "$DOC" | sed 's/^    //')"
[ -n "$LINE" ] || { echo "docs/68 holds no hand-over line"; exit 3; }
DOC_C="$(printf '%s' "$LINE" | sed -n 's/.*git cat-file blob \([0-9a-f]\{40\}\):scripts\/bank-recon-p0-run.sh.*/\1/p')"
DOC_S="$(printf '%s' "$LINE" | sed -n 's/.*echo "\([0-9a-f]\{64\}\)  \/root\/dnb-bank-recon\/bank-recon-p0-run.sh".*/\1/p')"
chk "the documented line pins a runner commit and a sha256" bash -c "[ -n '$DOC_C' ] && [ -n '$DOC_S' ]"
chk "the documented sha256 is the runner's own" bash -c "[ \"\$(sha256sum < '$RUN0' | cut -c1-64)\" = '$DOC_S' ]"
chk "the documented commit holds exactly this runner" bash -c "git -C '$R' cat-file blob '$DOC_C:scripts/bank-recon-p0-run.sh' 2>/dev/null | cmp -s - '$RUN0'"

# ── The book, behind the fake docker ──────────────────────────────────────────────────────────────────────────────────
mkdir -p "$SB/bin" "$SB/plugin"; cp -a "$R/dishnet-hybrid-sudan/lib" "$R/dishnet-hybrid-sudan/migrations" "$SB/plugin/"
cp "$H/fake-docker.sh" "$SB/bin/docker"; chmod +x "$SB/bin/docker"
book() {        # book <dir>: a server-shaped /data under <dir>/mnt with the synthetic Uganda book; sets M
  M="$1/mnt"; local PL="$M/ucrm/data/plugins/dishnet-hybrid-sudan" PD="$M/ucrm/data/plugins/.dishnet-hybrid-sudan-data"
  mkdir -p "$PL/data" "$PD"; cp -a "$SB/plugin/lib" "$SB/plugin/migrations" "$PL/"; echo '{"version":"5.18.94"}' > "$PL/manifest.json"
  printf 'f4b4c96\n' > "$PL/.deployed-commit"; printf '{}\n' > "$PL/ucrm.json"; chown -R "$O" "$M"
  own env DN_VAULT_FILE="$M/ucrm/data/plugins/.dishnet-sudan.vault.json" php "$H/build-book.php" "$PL" "$PD" ug > "$1/ids.json" 2>"$1/build.err" || { cat "$1/build.err"; exit 3; }
  own env DN_VAULT_FILE="$M/ucrm/data/plugins/.dishnet-sudan.vault.json" php "$H/expect.php" "$PL" "$PD" \
    "$(php -r 'echo json_decode(file_get_contents($argv[1]),true)["adj"];' "$1/ids.json")" > "$1/exp.json" 2>/dev/null || exit 3
}
mkdir -p "$SB/book0"; book "$SB/book0"

# ── The origin and the checkout ───────────────────────────────────────────────────────────────────────────────────────
world() {       # world <dir>: origin.git (A, then B = the P0 files, C = the runner) and a checkout cloned at A; sets
                # ORIG CK BSHA CSHA RSHA WK, and a copy of the book
  local d="$1" t="$1/tmp"; rm -rf "$d"; mkdir -p "$t"
  G init -q --bare "$d/origin.git"; G -C "$d/origin.git" symbolic-ref HEAD refs/heads/claude/study-this-jhe2eg
  G clone -q "$d/origin.git" "$t" 2>/dev/null; G -C "$t" checkout -q -b claude/study-this-jhe2eg
  mkdir -p "$t/dishnet-hybrid-sudan/data" "$t/scripts"; echo 'synthetic checkout' > "$t/README.md"; echo '{}' > "$t/dishnet-hybrid-sudan/manifest.json"
  printf 'data/\n' > "$t/dishnet-hybrid-sudan/.gitignore"
  G -C "$t" add -A; G -C "$t" commit -qm A; G -C "$t" push -q origin claude/study-this-jhe2eg
  ORIG="$d/origin.git"; CK="$d/opt/dishnet"; WK="$d/root/dnb-bank-recon"; mkdir -p "$d/opt" "$d/root"
  G clone -q -b claude/study-this-jhe2eg "$ORIG" "$CK" 2>/dev/null          # the server's checkout: before P0 existed
  cp "$SB/tool.php" "$t/scripts/bank-recon-p0.php"; cp "$SB/wrap.sh" "$t/scripts/bank-recon-p0.sh"
  G -C "$t" add -A; G -C "$t" commit -qm "B: P0"; BSHA="$(G -C "$t" rev-parse HEAD)"
  RUNC="$d/runner.sh"; sandboxed "$RUN0" "$BSHA" > "$RUNC"
  cp "$RUNC" "$t/scripts/bank-recon-p0-run.sh"; G -C "$t" add -A; G -C "$t" commit -qm "C: runner"; CSHA="$(G -C "$t" rev-parse HEAD)"
  RSHA="$(sha256sum < "$RUNC" | cut -c1-64)"; G -C "$t" push -q origin claude/study-this-jhe2eg
  TMPCL="$t"; cp -a "$SB/book0/mnt" "$d/mnt"; M="$d/mnt"
}
sandboxed() {   # sandboxed <runner> <P0 commit>: the runner with the sandbox's paths and commit (each anchor asserted)
  local s; s="$(sed -e "s#^REPO=\"/opt/dishnet\"\$#REPO=\"$D/opt/dishnet\"#" -e "s#^WORK=\"/root/dnb-bank-recon\"\$#WORK=\"$D/root/dnb-bank-recon\"#" \
                    -e "s#^P0_COMMIT=\"[0-9a-f]\{40\}\"\$#P0_COMMIT=\"$2\"#" "$1")"
  [ "$(printf '%s\n' "$s" | grep -c "^REPO=\"$D/opt/dishnet\"\$\|^WORK=\"$D/root/dnb-bank-recon\"\$\|^P0_COMMIT=\"$2\"\$")" = 3 ] || { echo "sandboxed: anchors" >&2; exit 3; }
  printf '%s\n' "$s"
}
handover() {    # handover [line]: the documented line with the sandbox's paths and pins; rc in RC, log in LOG, trace in TR
  local l="${1:-$LINE}"
  l="${l//\/opt\/dishnet/$D/opt/dishnet}"; l="${l//\/root\/dnb-bank-recon/$D/root/dnb-bank-recon}"
  l="${l//$DOC_C/$CSHA}"; l="${l//$DOC_S/$RSHA}"
  [ "$(printf '%s' "$l" | grep -o "$D/" | wc -l)" -ge 6 ] || { echo "handover: substitution"; exit 3; }
  TR="$D/trace"; : > "$TR"
  env PATH="$SB/bin:$PATH" FAKE_MNT="$M" FAKE_TRACE="$TR" bash -o pipefail -c "$l" > "$D/terminal" 2>&1; RC=$?   # pipefail: the runner's exit, not tee's
  LOG="$(ls -t "$WK"/p0-*.log 2>/dev/null | head -1)"
}
direct() {      # direct <runner file> [args]: run a (weakened or sandboxed) runner as the hand-over would, after its fetch
  TR="$D/trace"; : > "$TR"; mkdir -p "$WK"; chmod 700 "$WK"
  (cd "$CK" && G fetch -q origin claude/study-this-jhe2eg)
  local f="$1"; shift
  env PATH="$SB/bin:$PATH" FAKE_MNT="$M" FAKE_TRACE="$TR" bash "$f" "$@" > "$D/direct.log" 2>&1; RC=$?; LOG="$D/direct.log"
}
ckstate() {     # everything of the checkout a P0 run must leave alone, the remote-tracking refs and FETCH_HEAD aside
  ( cd "$CK" && git rev-parse HEAD; git symbolic-ref -q HEAD; git for-each-ref refs/heads refs/tags; git stash list
    sha256sum .git/index; stat -c '%Y %s' .git/index
    find . -path ./.git -prune -o -type f -printf '%p %s %T@ %m\n' | sort; find . -path ./.git -prune -o -type f -exec sha256sum {} + | sort )
}
p0runs()  { grep -c '^exec 1000:1000 stdin php -d display_errors=stderr -d log_errors=0 -d opcache.enable_cli=0 -- ' "$TR"; }
refused() { grep -q "GATE REFUSED: $1" "$LOG" && [ "$RC" = 4 ] && [ "$(p0runs)" = 0 ]; }

# ═════════════════════════════════════════════════════════════════════════════════════════════════════════════════════
suite() {       # suite <runner source>: every case; failures counted in $fails
  local RUNSRC="$1" s0 s1
  RUN0_SAVE="$RUN0"; RUN0="$RUNSRC"

  D="$SB/h"; world "$D"; ckstate > "$D/s0"
  bad_line="${LINE//$DOC_S/0000000000000000000000000000000000000000000000000000000000000000}"
  handover "$bad_line"
  chk "H  a wrong sha256 in the line: the runner never runs, nothing is logged, docker is never called" \
    bash -c "[ $RC != 0 ] && [ -z '$LOG' ] && [ ! -s '$TR' ] && ! ls '$WK'/src-* >/dev/null 2>&1"
  D="$SB/r1"; world "$D"; touch -d '2026-01-01' "$CK/README.md"   # a stale stat: a plain `git status` would rewrite the index
  ckstate > "$D/s0"; handover; ckstate > "$D/s1"
  chk "R1 the documented line, on a checkout that does not hold P0: exit 0, P0: DONE, RESULT: P0 DONE" \
    bash -c "[ $RC = 0 ] && grep -q '^P0: DONE — read only; nothing was written.\$' '$LOG' && grep -q 'RESULT: P0 DONE (exit 0)' '$LOG'"
  chk "R1 every gate line is there: checkout clean, the commit, both sha256s, the book and its owner" \
    bash -c "grep -q 'tracked files modified or staged: 0; untracked inside dishnet-hybrid-sudan/ (data/ aside): 0' '$LOG' && grep -qE \"reviewed P0 +commit $BSHA\" '$LOG' && grep -q 'tool sha256 ${TSHA:0:16}… = reviewed; command sha256 ${WSHA:0:16}… = reviewed; the command pins the same tool' '$LOG' && grep -qE 'book +/data/ucrm/data/plugins/.dishnet-hybrid-sudan-data/plugin.sqlite3, owner 1000:1000 — as expected' '$LOG'"
  chk "R1 the P0 files were taken out of the commit: the checkout holds none" \
    bash -c "grep -q \"the checkout's own scripts/bank-recon-p0.sh: absent (not needed)\" '$LOG' && [ ! -e '$CK/scripts/bank-recon-p0.sh' ]"
  chk "R1 P0 ran exactly once, as the owner, on standard input" bash -c "[ \$(grep -c '^exec 1000:1000 stdin php -d display_errors=stderr -d log_errors=0 -d opcache.enable_cli=0 -- ' '$TR') = 1 ] && ! grep -q '^REFUSED' '$TR'"
  chk "R1 its figures equal the plugin's own" php "$H/check.php" "$LOG" "$SB/book0/exp.json" yes
  chk "R1 the checkout is byte-for-byte as before — HEAD, branches, stash, index (not refreshed), every file and its time" cmp -s "$D/s0" "$D/s1"
  chk "R1 the runner says so too" grep -qE 'checkout +unchanged: HEAD, branch, local branches and tags, stash, index' "$LOG"
  chk "R1 the log is 0600 in a 0700 folder owned by root" bash -c "[ \"\$(stat -c '%a %u' '$LOG')\" = '600 0' ] && [ \"\$(stat -c '%a %u' '$WK')\" = '700 0' ]"
  chk "R1 nothing planted in the book is printed" bash -c "! grep -qE 'RH-MARK|0772|987 654|\\+256|Ecobank|MoMo|Airtel|ADJUSTMENT ENTRY|malformed|Uncaught|Fatal' '$LOG'"
  chk "R1 the command, run from the extracted folder, does not take any other repository for its checkout" grep -q 'this checkout   not readable as a git checkout' "$LOG"

  D="$SB/r1b"; world "$D"; (cd "$CK" && G pull -q --ff-only origin claude/study-this-jhe2eg); ckstate > "$D/s0"; handover; ckstate > "$D/s1"
  chk "R1b a checkout already holding the commit: its own copies are named the same, P0 runs, the checkout unchanged" \
    bash -c "[ $RC = 0 ] && grep -q \"the checkout's own scripts/bank-recon-p0.php: the same\" '$LOG' && cmp -s '$D/s0' '$D/s1'"

  D="$SB/r2"; world "$D"; echo local >> "$CK/README.md"; ckstate > "$D/s0"; handover; ckstate > "$D/s1"
  chk "R2 a modified tracked file: refused before P0; the change is kept" eval "refused 'tracked files are modified or staged' && cmp -s '$D/s0' '$D/s1' && grep -q local '$CK/README.md'"
  D="$SB/r3"; world "$D"; echo new > "$CK/new.txt"; G -C "$CK" add new.txt; handover
  chk "R3 a staged file: refused before P0" refused 'tracked files are modified or staged'
  D="$SB/r4"; world "$D"; G -C "$CK" rev-parse HEAD > "$CK/.git/MERGE_HEAD"; handover
  chk "R4 a merge in progress: refused before P0" refused 'the checkout has an operation in progress (MERGE_HEAD)'
  D="$SB/r5"; world "$D"; echo x > "$CK/dishnet-hybrid-sudan/stray.php"; handover
  chk "R5 an untracked file inside the plugin folder: refused before P0" refused 'untracked files inside dishnet-hybrid-sudan/'
  D="$SB/r5b"; world "$D"; echo x > "$CK/dishnet-hybrid-sudan/data/plugin.log"; echo n > "$CK/notes.txt"; ckstate > "$D/s0"; handover; ckstate > "$D/s1"
  chk "R5b untracked files in data/ and outside the plugin: listed, not refused; P0 runs; they are left as they are" \
    bash -c "[ $RC = 0 ] && grep -q 'untracked, not read by P0: notes.txt' '$LOG' && cmp -s '$D/s0' '$D/s1'"
  D="$SB/r6"; world "$D"; sandboxed "$RUNSRC" 1111111111111111111111111111111111111111 > "$D/x.sh"; direct "$D/x.sh"
  chk "R6 a commit the checkout does not hold: refused before P0" refused 'the checkout does not hold the reviewed commit'
  D="$SB/r7"; world "$D"; echo '// changed' >> "$TMPCL/scripts/bank-recon-p0.php"; G -C "$TMPCL" commit -qam E; G -C "$TMPCL" push -q origin claude/study-this-jhe2eg
  sandboxed "$RUNSRC" "$(G -C "$TMPCL" rev-parse HEAD)" > "$D/x.sh"; direct "$D/x.sh"
  chk "R7 a commit whose tool is not the reviewed one: refused before P0" refused 'the tool in .* has sha256 .*, not the reviewed'
  D="$SB/r8"; world "$D"; sed -i 's/^TOOL_SHA="[0-9a-f]*"$/TOOL_SHA="0000000000000000000000000000000000000000000000000000000000000000"/' "$TMPCL/scripts/bank-recon-p0.sh"
  G -C "$TMPCL" commit -qam F; G -C "$TMPCL" push -q origin claude/study-this-jhe2eg
  sandboxed "$RUNSRC" "$(G -C "$TMPCL" rev-parse HEAD)" | sed "s/^WRAP_SHA=\"[0-9a-f]*\"\$/WRAP_SHA=\"$(sha256sum < "$TMPCL/scripts/bank-recon-p0.sh" | cut -c1-64)\"/" > "$D/x.sh"; direct "$D/x.sh"
  chk "R8 a command that pins another tool, its own sha256 accepted: refused before P0" refused 'the command does not pin the reviewed tool sha256'
  D="$SB/r9"; world "$D"; (cd "$CK" && G fetch -q origin claude/study-this-jhe2eg); G -C "$TMPCL" reset -q --hard HEAD~2; echo z > "$TMPCL/z"; G -C "$TMPCL" add z; G -C "$TMPCL" commit -qm rewritten
  G -C "$TMPCL" push -q -f origin claude/study-this-jhe2eg; direct "$D/runner.sh"
  chk "R9 a rewritten branch that no longer holds the commit: refused before P0" refused 'origin/claude/study-this-jhe2eg (.*) does not contain .* — the branch was rewritten'
  D="$SB/r10"; world "$D"; printf '{"pluginDataDir":"/data/ucrm/data/plugins/elsewhere"}\n' > "$M/ucrm/data/plugins/dishnet-hybrid-sudan/ucrm.json"; handover
  chk "R10 ucrm.json naming another data folder: an unexpected database path, refused before P0" refused 'the data folder would be /data/ucrm/data/plugins/elsewhere'
  D="$SB/r11"; world "$D"; chown 1001:1001 "$M/ucrm/data/plugins/.dishnet-hybrid-sudan-data/plugin.sqlite3"; handover
  chk "R11 an unexpected owner: refused before P0" refused "the database's owner is 1001:1001, not 1000:1000"
  D="$SB/r12"; world "$D"; mkdir -p "$WK"; chmod 755 "$WK"; (cd "$CK" && G fetch -q origin claude/study-this-jhe2eg)
  TR="$D/trace"; : > "$TR"; env PATH="$SB/bin:$PATH" FAKE_MNT="$M" FAKE_TRACE="$TR" bash "$D/runner.sh" > "$D/direct.log" 2>&1; RC=$?; LOG="$D/direct.log"
  chk "R12 a log folder others can read: refused before P0" refused '.* must exist, owned by root, mode 700'
  D="$SB/r13"; world "$D"; direct "$D/runner.sh" --adjustment-id=12345678901
  chk "R13 an 11-digit --adjustment-id: refused (4) before docker" bash -c "[ $RC = 4 ] && grep -q 'at most 10 digits' '$LOG' && [ ! -s '$TR' ]"
  D="$SB/r14"; world "$D"; mkdir -p "$WK"; chmod 700 "$WK"; TR="$D/trace"; : > "$TR"
  env PATH="$SB/bin:$PATH" FAKE_MNT="$M" FAKE_TRACE="$TR" setpriv --reuid=1000 --regid=1000 --clear-groups bash "$D/runner.sh" > "$D/direct.log" 2>&1; RC=$?; LOG="$D/direct.log"
  chk "R14 not root: refused before anything" refused 'run this as root'
  D="$SB/r15"; world "$D"; ckstate > "$D/s0"; direct "$D/runner.sh" --adjustment-id=1; ckstate > "$D/s1"
  chk "R15 P0 stops (exit 3): reported, NOT retried — P0 ran exactly once; the checkout unchanged" \
    bash -c "[ $RC = 3 ] && [ \$(grep -c '^exec 1000:1000 stdin php' '$TR') = 1 ] && grep -q 'RESULT: P0 STOPPED (exit 3)' '$LOG' && grep -q 'it was NOT retried' '$LOG' && cmp -s '$D/s0' '$D/s1'"
  D="$SB/r16"; world "$D"; G init -q "$D/root"; G -C "$D/root" commit -q --allow-empty -m other; handover
  chk "R16 a git repository above the work folder is not taken for a checkout" bash -c "[ $RC = 0 ] && grep -q 'this checkout   not readable as a git checkout' '$LOG'"
  RUN0="$RUN0_SAVE"
}

echo "== the gated run =="
suite "$RUN0"
echo; echo "== result: $pass ok, $fails failed =="
MAIN=$fails
[ "${MUTANTS:-1}" = 0 ] && { [ "$fails" = 0 ]; exit; }

mutant() {      # mutant <name> <perl substitution>: a weakened runner, through the whole suite; it must fail somewhere
  local name="$1"; local dst="$SB/mut.$name.sh" f0=$fails p0_=$pass
  perl -0pe "$2" "$RUN0" > "$dst"
  if cmp -s "$RUN0" "$dst"; then bad "mutant $name: the substitution did not apply"; return; fi
  suite "$dst" > "$SB/mut.$name.log" 2>&1
  local caught=$((fails - f0)); fails=$f0; pass=$p0_
  [ "$caught" -gt 0 ] && ok "mutant $name is caught ($caught: $(grep -m1 FAIL "$SB/mut.$name.log" | cut -c8-80))" || bad "mutant $name is NOT caught"
}
echo; echo "== weakened copies =="
mutant no-dirty-check       's/^\[ -z "\$DIRTY" \] \|\| \{[^\n]*\n//m'
mutant no-plugin-untracked  's/^\[ -z "\$UNTR_PLUGIN" \] \|\| \{[^\n]*\n//m'
mutant no-op-in-progress    's/  \[ ! -e "\$GD\/\$f" \] \|\| gate[^\n]*\n/  :\n/'
mutant from-working-tree    's/g cat-file blob "\$P0_COMMIT:scripts\/bank-recon-p0\.php" > /cat "\$REPO\/scripts\/bank-recon-p0.php" > /'
mutant no-tool-sha          's/^\[ "\$T" = "\$TOOL_SHA" \] \|\| gate[^\n]*\n//m'
mutant no-pin-check         's/^\[ "\$\(grep -c "\^TOOL_SHA[^\n]*\n//m'
mutant no-ancestor          's/^g merge-base --is-ancestor[^\n]*\n//m'
mutant no-path-check        's/^\[ "\$\{PDD%\/\}" = "\$EXPECTED_DATA" \] \|\| gate[^\n]*\n//m'
mutant no-owner-check       's/^\[ "\$OWN" = "\$EXPECTED_OWNER" \] \|\| gate[^\n]*\n//m'
mutant retries              's/^rc=\$\?\n/rc=\$?; [ "\$rc" = 0 ] || { GIT_CEILING_DIRECTORIES="\$WORK" bash "\$SRC\/scripts\/bank-recon-p0.sh" \${ADJ[@]+"\${ADJ[@]}"}; rc=\$?; }\n/m'
mutant index-refresh        's/git --no-optional-locks -C "\$REPO"/git -C "\$REPO"/'
mutant updates-checkout     's/^(ok "" "the working tree, the index, HEAD and every local branch are only read here[^\n]*\n)/$1g merge -q --ff-only "refs\/remotes\/origin\/\$BRANCH" >\/dev\/null 2>&1 || true\n/m'
mutant loose-log-folder     's/^\[ -d "\$WORK" \] && \[ "\$\(stat[^\n]*\n//m'
mutant no-ceiling           's/^GIT_CEILING_DIRECTORIES="\$WORK" bash/bash/m'
mutant not-root-ok          's/^\[ "\$\(id -u\)" = "0" \] \|\| gate[^\n]*\n//m'
echo; echo "== total: $pass ok, $fails failed (the gated run itself: $MAIN failed) =="
[ "$fails" = 0 ]
