#!/usr/bin/env bash
# rehearse-run.sh — R0's gated run (scripts/bank-recon-r0-run.sh) and its hand-over line, in a sandbox: P0's
# (scripts/harness/bank-recon-p0/rehearse-run.sh), copied for R0. A bare "origin" holding the branch, a server-shaped
# checkout cloned BEFORE the R0 commits existed, a synthetic book (written by the plugin's own code, build-book.php) behind
# R0's strict fake docker. The hand-over line is read out of docs/69 when it holds one, else out of the harness template
# (or $HANDOVER_FILE), and run as written, with only its two server paths and its two pins replaced (each replacement
# asserted). It proves:
#   P   the pins: PINS=placeholder — the runner carries exactly the all-zero placeholders (a half-set pin is a failure);
#       PINS=real — the runner pins commit A's files, the document's sha256 is the runner's, its commit holds this runner;
#       either way the command pins the tool it is committed with, and the line is the specification's §6.1 line;
#   H   a wrong sha256 in the line (after substitution): the runner never runs, nothing is logged, docker is never called;
#   H2  the template's line with its placeholder pins left in, run as it stands: refused at git cat-file after its fetch,
#       an empty runner file left behind that noclobber then refuses to overwrite, and deleting it lets the real line run
#       — and the template and the hand-over say exactly that (guide.php placeholder; its earlier wording is refused);
#   GD  the log-reading guide of the document the line came from: its quoted read-twice line matches a run that waited
#       for the plugin and one that did not, it says R0 may print nothing for up to two minutes, and its exit table has
#       a row for every exit the runner ends with, the interruptions 129 / 130 / 143 included — each with a control;
#   R1  the gates pass on a clean checkout that does not hold the R0 files; R0 runs once, as the owner, on the reviewed
#       files taken out of the commit; its report equals expect.php's line for line and the planted truth; no planted
#       string, PHP diagnostic word or amount is in the log; the checkout is byte-for-byte as before; the log is 0600 in
#       a 0700 folder;
#   R2–R14  each gate refuses, before R0 and changing nothing; R13: any option to the runner → exit 4 before docker;
#   R15 R0's own stop (exit 3, a copy whose reading meets a missing table) is reported and NOT retried; R15b a book
#       without cb_ledger: exit 2, NOT retried; R16 a git repository above the work folder is not taken for the checkout;
#   R17–R33 P0's later findings, with R0's texts; R26: the candidate copy's lines pass through the runner unchanged,
#       DONE, never a 7; R34: a PHP fatal error after the reading — exit 255, no section line, nothing planted printed;
#   M   weakened copies of the runner, each caught.
# Fictitious data only. Refuses to run where the server could be, and never beside another rehearsal.
#
#   bash scripts/harness/bank-recon-r0/rehearse-run.sh     KEEP=1 keeps the sandbox; MUTANTS=0 skips the weakened copies;
#                                                          PINS=placeholder|real (default: read off the runner);
#                                                          HANDOVER_FILE=<file> instead of the template, while docs/69
#                                                          holds no R0 line
set -u
for f in /data/ucrm /opt/dishnet /var/run/docker.sock /root/dnb-bank-recon; do
  [ -e "$f" ] && { echo "refusing: $f exists — this looks like the server, and this rehearsal must never run there"; exit 2; }
done
[ "$(id -u)" = 0 ] || { echo "run as root"; exit 2; }
for c in php setpriv setsid sha256sum perl flock git; do command -v "$c" >/dev/null || { echo "missing $c"; exit 2; }; done
exec 9>/tmp/bank-recon-rehearsal.lock
flock -n 9 || { echo "refusing: another rehearsal holds /tmp/bank-recon-rehearsal.lock — never two at once"; exit 2; }
for p in /proc/[0-9]*/cmdline; do
  tr '\0' ' ' 2>/dev/null < "$p" | grep -q 'bank-recon-p0/rehearse' && { echo "refusing: a P0 rehearsal is running (${p%/cmdline})"; exit 2; }
done
for d in /tmp/bank-recon-p0.* /tmp/bank-recon-run.*; do
  [ -e "$d" ] && { echo "refusing: $d exists — a P0 rehearsal is running or was kept; never two at once"; exit 2; }
done
H="$(cd "$(dirname "$0")" && pwd)"; R="$(cd "$H/../../.." && pwd)"
RUN0="$R/scripts/bank-recon-r0-run.sh"; DOC="$R/docs/69-bank-book-design-2026-10-16.md"
SB="$(mktemp -d /tmp/bank-recon-r0-run.XXXXXX)"; chmod 755 "$SB"
trap '[ -n "${KEEP:-}" ] && echo "kept: $SB" || rm -rf "$SB"' EXIT
pass=0; fails=0
ok()  { pass=$((pass+1)); echo "  ok   $*"; }
bad() { fails=$((fails+1)); echo "  FAIL $*"; }
chk() { local m="$1"; shift; if "$@"; then ok "$m"; else bad "$m"; fi; }
O=1000:1000; own() { setpriv --reuid=1000 --regid=1000 --clear-groups "$@"; }
G() { git -c user.name=rh -c user.email=rh@example.invalid -c advice.detachedHead=false -c push.negotiate=false "$@"; }
# R0's own SECRETS (the specification's §5.0), as rehearse.sh: the planted strings, and PHP's diagnostic words
PLANTED='RH-MARK|RH-Client-|0772 123 456|0772123456|7000000001|BANK-REF001|RH-MID-0001|RH-Grown'
SECRETS="$PLANTED|Warning|Notice|Deprecated|Fatal|Uncaught|Stack trace|Standard input code"
noamount() { [ ! -s "$2" ] || ! grep -Fwq -f "$2" "$1"; }   # noamount <log> <amounts file>
FICT=734000037                                              # the fictitious adjustment, 7340000.37, in cents
FICT_SHA="$(php -r 'echo hash("sha256", "dn-r0-adj|" . $argv[1]);' "$FICT")"
Z40=0000000000000000000000000000000000000000
Z64=0000000000000000000000000000000000000000000000000000000000000000

# ── The pins ────────────────────────────────────────────────────────────────────────────────────────────────────────
R0C="$(sed -n 's/^R0_COMMIT="\([0-9a-f]\{40\}\)"$/\1/p' "$RUN0")"
TSHA0="$(sed -n 's/^TOOL_SHA="\([0-9a-f]\{64\}\)"$/\1/p' "$RUN0")"; WSHA0="$(sed -n 's/^WRAP_SHA="\([0-9a-f]\{64\}\)"$/\1/p' "$RUN0")"
[ -n "$R0C" ] && [ -n "$TSHA0" ] && [ -n "$WSHA0" ] || { echo "the runner carries no R0_COMMIT, TOOL_SHA or WRAP_SHA line"; exit 3; }
nz=0; for v in "$R0C" "$TSHA0" "$WSHA0"; do case "$v" in *[!0]*) ;; *) nz=$((nz+1)) ;; esac; done
PINS="${PINS:-auto}"
if [ "$PINS" = auto ]; then
  case "$nz" in 3) PINS=placeholder ;; 0) PINS=real ;; *) PINS=half ;; esac
fi
echo "pins: $PINS"
case "$PINS" in
  placeholder)
    chk "P  PINS=placeholder: the runner's R0_COMMIT, TOOL_SHA and WRAP_SHA are exactly the all-zero placeholders" [ "$nz" = 3 ]
    cp "$R/scripts/bank-recon-r0.php" "$SB/tool.php"; cp "$R/scripts/bank-recon-r0.sh" "$SB/wrap.sh" ;;
  real)
    chk "P  PINS=real: none of the runner's three pins is a placeholder" [ "$nz" = 0 ]
    git -C "$R" cat-file blob "$R0C:scripts/bank-recon-r0.php" > "$SB/tool.php" && git -C "$R" cat-file blob "$R0C:scripts/bank-recon-r0.sh" > "$SB/wrap.sh" \
      || { echo "the reviewed commit $R0C is not in this checkout"; exit 3; }
    chk "P  the runner pins the reviewed commit, and its two sha256s are that commit's files" \
      bash -c "[ \"\$(sha256sum < '$SB/tool.php' | cut -c1-64)\" = '$TSHA0' ] && [ \"\$(sha256sum < '$SB/wrap.sh' | cut -c1-64)\" = '$WSHA0' ]" ;;
  half) bad "P  the runner's pins are half set ($nz of 3 are placeholders) — a half-set pin is a failure"; exit 1 ;;
  *) echo "PINS must be placeholder or real"; exit 2 ;;
esac
TSHA="$(sha256sum < "$SB/tool.php" | cut -c1-64)"; WSHA="$(sha256sum < "$SB/wrap.sh" | cut -c1-64)"
chk "P  the command pins the tool it is committed with" [ "$(grep -c "^TOOL_SHA=\"$TSHA\"\$" "$SB/wrap.sh")" = 1 ]

# ── The hand-over line ──────────────────────────────────────────────────────────────────────────────────────────────
SPEC61="$(cat <<'EOF'
( umask 077 && mkdir -p /root/dnb-bank-recon && chmod 700 /root/dnb-bank-recon && cd /opt/dishnet && git -c gc.auto=0 -c maintenance.auto=false -c core.hooksPath=/dev/null -c fetch.writeCommitGraph=false -c remote.origin.followRemoteHEAD=never fetch -q --no-tags --no-recurse-submodules --refmap= origin +refs/heads/claude/study-this-jhe2eg:refs/remotes/origin/claude/study-this-jhe2eg && git cat-file blob <COMMIT B>:scripts/bank-recon-r0-run.sh > /root/dnb-bank-recon/bank-recon-r0-run.sh && echo "<RUNNER SHA256>  /root/dnb-bank-recon/bank-recon-r0-run.sh" | sha256sum -c --quiet - && env -u BASH_ENV -u ENV -u UCRM_CONTAINER -u SHELLOPTS -u BASHOPTS bash /root/dnb-bank-recon/bank-recon-r0-run.sh 2>&1 | ( trap '' INT HUP TERM; exec tee /root/dnb-bank-recon/r0-$(date -u +%Y%m%dT%H%M%SZ).log ) )
EOF
)"
pickline() { grep '^    ( umask 077 && mkdir -p /root/dnb-bank-recon' "$1" 2>/dev/null | grep -m1 'bank-recon-r0-run\.sh' | sed 's/^    //'; }
pinsof()   { # pinsof <line>: "<commit> <sha256>" of the runner the line fetches, or nothing
  local c s
  c="$(printf '%s' "$1" | sed -n 's/.*git cat-file blob \([0-9a-f]\{40\}\):scripts\/bank-recon-r0-run\.sh.*/\1/p')"
  s="$(printf '%s' "$1" | sed -n 's/.*echo "\([0-9a-f]\{64\}\)  \/root\/dnb-bank-recon\/bank-recon-r0-run\.sh".*/\1/p')"
  [ -n "$c" ] && [ -n "$s" ] && printf '%s %s' "$c" "$s"
}
canon()    { local l="${1//$3/<RUNNER SHA256>}"; printf '%s' "${l//$2/<COMMIT B>}"; }   # the sha first: Z40 is a prefix of Z64
TL="$(pickline "$H/handover-template.txt")"; read -r TC TS <<< "$(pinsof "$TL")"
chk "P  the harness template holds §6.1's line with the placeholder pins, and nothing else differs" \
  bash -c "[ '${TC:-}' = '$Z40' ] && [ '${TS:-}' = '$Z64' ] && [ \"\$1\" = \"\$2\" ]" _ "$(canon "$TL" "$Z40" "$Z64")" "$SPEC61"
LINE="$(pickline "$DOC")"; GUIDE=""   # GUIDE: the document the line came from, whose log-reading guide case GD checks
if [ -n "$LINE" ]; then echo "hand-over line: from docs/69"; GUIDE="$DOC"
elif [ "$PINS" = real ]; then echo "PINS=real needs the R0 hand-over line in docs/69, which holds none"; exit 3
elif [ -n "${HANDOVER_FILE:-}" ]; then LINE="$(pickline "$HANDOVER_FILE")"; GUIDE="$HANDOVER_FILE"; echo "hand-over line: from $HANDOVER_FILE — docs/69 holds none yet"
else LINE="$TL"; echo "hand-over line: from the harness template — docs/69 holds none yet"; fi
[ -n "$LINE" ] || { echo "no R0 hand-over line found"; exit 3; }
read -r DOC_C DOC_S <<< "$(pinsof "$LINE")"
chk "P  the hand-over line pins a runner commit and a sha256" bash -c "[ -n '${DOC_C:-}' ] && [ -n '${DOC_S:-}' ]"
[ -n "${DOC_C:-}" ] && [ -n "${DOC_S:-}" ] || exit 3
chk "P  the hand-over line is the specification's §6.1 line, its two pins aside" \
  bash -c "[ \"\$1\" = \"\$2\" ]" _ "$(canon "$LINE" "$DOC_C" "$DOC_S")" "$SPEC61"
if [ "$PINS" = real ]; then
  chk "P  the documented sha256 is the runner's own" bash -c "[ \"\$(sha256sum < '$RUN0' | cut -c1-64)\" = '$DOC_S' ]"
  chk "P  the documented commit holds exactly this runner" bash -c "git -C '$R' cat-file blob '$DOC_C:scripts/bank-recon-r0-run.sh' 2>/dev/null | cmp -s - '$RUN0'"
else
  [ "$DOC_C $DOC_S" = "$Z40 $Z64" ] && echo "  (the line's pins are the placeholders)" || echo "  (the line carries pins; the runner does not yet — PINS=placeholder substitutes both)"
fi

# ── The copies of the tool some worlds commit ───────────────────────────────────────────────────────────────────────
candcopy() {    # the tool with the adjustment hash replaced by the fictitious figure's (exactly one line differs)
  sed "s/^const ADJ_CENTS_SHA = '[0-9a-f]\{64\}';/const ADJ_CENTS_SHA = '$FICT_SHA';/" "$SB/tool.php" > "$SB/cand.php"
  [ "$(diff "$SB/tool.php" "$SB/cand.php" | grep -c '^[<>]')" = 2 ] || { echo "candcopy: the anchor did not apply exactly once"; exit 3; }
}
failcopy() {    # a copy whose reading meets a query SQLite cannot answer (rehearse.sh's T4 injection)
  awk '{ print } /^    \/\/ ── §1\.2 voided rows/ { print "    q($pdo, $h, \"SELECT * FROM rh_no_such_table\");" }' "$SB/tool.php" > "$SB/fail.php"
  [ "$(grep -c 'rh_no_such_table' "$SB/fail.php")" = 1 ] || { echo "failcopy: anchor not found"; exit 3; }
}
fatalcopy() {   # a copy that dies of a PHP fatal error after both readings, before it prints a line: memory exhausted
  # (bounded, so a PHP that would not lower its limit cannot take the host's memory: it ends, prints, and R34 fails)
  awk '{ print } /^\$r2 = readBook\(\$db\);$/ { print "if (ini_set(\x27memory_limit\x27, \x2764M\x27) === false) exit(99); $rh = []; for ($i = 0; $i < 512; $i++) $rh[] = str_repeat(\x27x\x27, 1048576);" }' "$SB/tool.php" > "$SB/fatal.php"
  [ "$(grep -c '\$rh\[\] = str_repeat' "$SB/fatal.php")" = 1 ] || { echo "fatalcopy: anchor not found"; exit 3; }
}
candcopy; failcopy; fatalcopy
wrapfor() {     # wrapfor <tool> <out>: the command, pinned to that tool (one line differs)
  sed "s/^TOOL_SHA=\"[0-9a-f]\{64\}\"\$/TOOL_SHA=\"$(sha256sum < "$1" | cut -c1-64)\"/" "$SB/wrap.sh" > "$2"
  [ "$(diff "$SB/wrap.sh" "$2" | grep -c '^[<>]')" = 2 ] || { echo "wrapfor: the anchor did not apply exactly once"; exit 3; }
}

# ── The book, behind the fake docker ────────────────────────────────────────────────────────────────────────────────
mkdir -p "$SB/bin" "$SB/plugin/tools"
cp -a "$R/dishnet-hybrid-sudan/lib" "$R/dishnet-hybrid-sudan/migrations" "$R/dishnet-hybrid-sudan/profiles" "$SB/plugin/"
cp "$R/dishnet-hybrid-sudan/tools/staff_records_currency.php" "$SB/plugin/tools/"
cp "$H/fake-docker.sh" "$SB/bin/docker"; chmod +x "$SB/bin/docker"
mkdir -p "$SB/slowgit"; cp "$H/slow-git.sh" "$SB/slowgit/git"; chmod +x "$SB/slowgit/git"; export REAL_GIT="$(command -v git)"
PLR=ucrm/data/plugins/dishnet-hybrid-sudan; PDR=ucrm/data/plugins/.dishnet-hybrid-sudan-data
book() {        # book <dir>: a server-shaped /data under <dir>/mnt holding the synthetic Uganda book, and its expectations
  local M="$1/mnt" PL="$1/mnt/$PLR" PD="$1/mnt/$PDR"
  mkdir -p "$PL/data" "$PD"; cp -a "$SB/plugin/." "$PL/"; echo '{"version":"5.18.94"}' > "$PL/manifest.json"
  printf 'f4b4c96\n' > "$PL/.deployed-commit"; printf '{}\n' > "$PL/ucrm.json"; chown -R "$O" "$M"; chown "$O" "$1"
  own env DN_VAULT_FILE="$1/vault.json" php "$H/build-book.php" "$PL" "$PD" ug > "$1/ids.json" 2> "$1/build.err" || { cat "$1/build.err"; exit 3; }
  rm -f "$PD/plugin.sqlite3-wal" "$PD/plugin.sqlite3-shm"
  own php "$H/expect.php" "$PD" ug none > "$1/exp.tool" 2> "$1/exp.err" || { cat "$1/exp.err"; exit 3; }
  own php "$H/expect.php" "$PD" ug "$FICT" > "$1/exp.cand" 2>> "$1/exp.err" || { cat "$1/exp.err"; exit 3; }
  own php "$H/amounts.php" "$PD" > "$1/amounts" || exit 3
}
mkdir -p "$SB/book0"; book "$SB/book0"
report() { awk '/^  tables          /{on=1} /^  checks          /{exit} on' "$1"; }   # the report, from tables to checks

# ── The origin and the checkout ─────────────────────────────────────────────────────────────────────────────────────
world() {       # world <dir> [tool]: origin.git (A, then B = the R0 files, C = the runner) and a checkout cloned at A;
                # sets ORIG CK BSHA CSHA RSHA WK TMPCL M, and a copy of the book. [tool]: commit B holds that copy of the
                # tool, with the command and the runner pinned to it
  local d="$1" t="$1/tmp" tool="${2:-$SB/tool.php}" wrap="$SB/wrap.sh"; rm -rf "$d"; mkdir -p "$t"
  G init -q --bare "$d/origin.git"; G -C "$d/origin.git" symbolic-ref HEAD refs/heads/claude/study-this-jhe2eg
  G clone -q "$d/origin.git" "$t" 2>/dev/null; G -C "$t" checkout -q -b claude/study-this-jhe2eg
  mkdir -p "$t/dishnet-hybrid-sudan/data" "$t/scripts"; echo 'synthetic checkout' > "$t/README.md"; echo '{}' > "$t/dishnet-hybrid-sudan/manifest.json"
  printf 'data/\n' > "$t/dishnet-hybrid-sudan/.gitignore"
  G -C "$t" add -A; G -C "$t" commit -qm A; G -C "$t" push -q origin claude/study-this-jhe2eg
  ORIG="$d/origin.git"; CK="$d/opt/dishnet"; WK="$d/root/dnb-bank-recon"; mkdir -p "$d/opt" "$d/root"
  G clone -q -b claude/study-this-jhe2eg "$ORIG" "$CK" 2>/dev/null          # the server's checkout: before R0 existed
  [ "$tool" = "$SB/tool.php" ] || { wrap="$d/wrap.sh"; wrapfor "$tool" "$wrap"; }
  cp "$tool" "$t/scripts/bank-recon-r0.php"; cp "$wrap" "$t/scripts/bank-recon-r0.sh"
  G -C "$t" add -A; G -C "$t" commit -qm "B: R0"; BSHA="$(G -C "$t" rev-parse HEAD)"; G -C "$t" tag rh-b   # a tag the fetch must not bring
  RUNC="$d/runner.sh"; sandboxed "$RUN0" "$BSHA" "$(sha256sum < "$tool" | cut -c1-64)" "$(sha256sum < "$wrap" | cut -c1-64)" > "$RUNC"
  cp "$RUNC" "$t/scripts/bank-recon-r0-run.sh"; G -C "$t" add -A; G -C "$t" commit -qm "C: runner"; CSHA="$(G -C "$t" rev-parse HEAD)"
  RSHA="$(sha256sum < "$RUNC" | cut -c1-64)"; G -C "$t" push -q origin claude/study-this-jhe2eg rh-b
  TMPCL="$t"; cp -a "$SB/book0/mnt" "$d/mnt"; M="$d/mnt"
}
sandboxed() {   # sandboxed <runner> <R0 commit> [tool sha] [command sha]: the runner with the sandbox's paths and pins
  local c="$2" ts="${3:-$TSHA}" ws="${4:-$WSHA}" s
  s="$(sed -e "s#^REPO=\"/opt/dishnet\"\$#REPO=\"$D/opt/dishnet\"#" -e "s#^WORK=\"/root/dnb-bank-recon\"\$#WORK=\"$D/root/dnb-bank-recon\"#" \
           -e "s#^R0_COMMIT=\"[0-9a-f]\{40\}\"\$#R0_COMMIT=\"$c\"#" -e "s#^TOOL_SHA=\"[0-9a-f]\{64\}\"\$#TOOL_SHA=\"$ts\"#" \
           -e "s#^WRAP_SHA=\"[0-9a-f]\{64\}\"\$#WRAP_SHA=\"$ws\"#" "$1")"
  [ "$(printf '%s\n' "$s" | grep -cFx -e "REPO=\"$D/opt/dishnet\"" -e "WORK=\"$D/root/dnb-bank-recon\"" -e "R0_COMMIT=\"$c\"" -e "TOOL_SHA=\"$ts\"" -e "WRAP_SHA=\"$ws\"")" = 5 ] \
    || { echo "sandboxed: anchors" >&2; exit 3; }
  printf '%s\n' "$s"
}
sandline() {    # sandline [line]: the line with the sandbox's paths and pins, in L (each replacement asserted)
  L="${1:-$LINE}"
  L="${L//\/opt\/dishnet/$D/opt/dishnet}"; L="${L//\/root\/dnb-bank-recon/$D/root/dnb-bank-recon}"
  L="${L//$DOC_S/$RSHA}"; L="${L//$DOC_C/$CSHA}"    # the sha first: a placeholder commit is a prefix of a placeholder sha
  [ "$(printf '%s' "$L" | grep -oF "$D/" | wc -l)" -ge 7 ] && [ "$(printf '%s' "$L" | grep -oF "$CSHA:scripts/bank-recon-r0-run.sh" | wc -l)" = 1 ] \
    && [ "$(printf '%s' "$L" | grep -oF "echo \"$RSHA  $D/root/dnb-bank-recon/bank-recon-r0-run.sh\"" | wc -l)" = 1 ] || { echo "handover: substitution"; exit 3; }
  TR="$D/trace"; : > "$TR"
}
readlog() {     # what the operator reads: the log's last line, RUNNER EXIT <n> — the shell's own status is tee's
  LOG="$(ls -t "$WK"/r0-*.log 2>/dev/null | head -1)"
  [ -n "$LOG" ] && RC="$(tail -n1 "$LOG" | sed -n 's/^  RUNNER EXIT \([0-9]*\)$/\1/p')" && [ -n "$RC" ] || { [ -n "$LOG" ] && RC=noexitline; }
}
runL() {        # the substituted line L, run as typed; rc in RC, log in LOG
  # HO_PRE: a folder put first on PATH; HO_ENV: one NAME=value added to the environment, as an operator's shell might
  env PATH="${HO_PRE:+$HO_PRE:}$SB/bin:$PATH" ${HO_ENV:+"$HO_ENV"} FAKE_MNT="$M" FAKE_TRACE="$TR" bash -c "$L" > "$D/terminal" 2>&1; RC=$?   # tee's status
  readlog
}
handover() { sandline "$@"; runL; }
handover_sig() { # handover_sig <INT|HUP|TERM> [line]: the line in its own process group, as a terminal runs it; once R0
                 # has begun, the signal goes to the whole group, as Ctrl-C or a closed terminal sends it
  # SIGAT=git: instead, the signal comes while the runner is inside its first `g cat-file blob … > file` (slow-git.sh)
  local sig="$1" i=0 pid pre="$SB/bin" hook="touch '$D/r0-begun'; sleep 30" flag=""; shift; sandline "$@"; rm -f "$D/r0-begun"
  if [ "${SIGAT:-r0}" = git ]; then pre="$SB/slowgit:$SB/bin"; hook=""; flag="$D/r0-begun"; fi
  # a background job of this script starts with SIGINT ignored; perl puts every signal back to its default first
  env PATH="$pre:$PATH" FAKE_MNT="$M" FAKE_TRACE="$TR" FAKE_ON_P0="$hook" SLOW_GIT_FLAG="$flag" \
    perl -e '$SIG{$_}="DEFAULT" for qw(INT QUIT HUP TERM); exec @ARGV or die' setsid bash -c "$L" > "$D/terminal" 2>&1 &
  pid=$!
  while [ ! -e "$D/r0-begun" ] && [ $i -lt 600 ]; do sleep 0.1; i=$((i+1)); done
  kill -"$sig" -- -"$pid" 2>/dev/null; wait "$pid" 2>/dev/null
  i=0; while kill -0 -- -"$pid" 2>/dev/null && [ $i -lt 300 ]; do sleep 0.1; i=$((i+1)); done   # tee, ignoring it, ends last
  readlog
}
direct() {      # direct <runner file> [args]: run a (weakened or sandboxed) runner as the hand-over would, after its fetch
  TR="$D/trace"; : > "$TR"; mkdir -p "$WK"; chmod 700 "$WK"
  (cd "$CK" && G fetch -q origin claude/study-this-jhe2eg)
  local f="$1"; shift
  env PATH="$SB/bin:$PATH" FAKE_MNT="$M" FAKE_TRACE="$TR" bash "$f" "$@" > "$D/direct.log" 2>&1; RC=$?; LOG="$D/direct.log"
  [ "$(tail -n1 "$LOG" | sed -n 's/^  RUNNER EXIT \([0-9]*\)$/\1/p')" = "$RC" ] || RC="exit-line-missing-or-wrong:$RC"
}
ckstate() {     # everything of the checkout an R0 run must leave alone, the remote-tracking refs and FETCH_HEAD aside
  ( cd "$CK" && git rev-parse HEAD; git symbolic-ref -q HEAD; git for-each-ref refs/heads refs/tags; git stash list
    sha256sum .git/index; stat -c '%Y %s' .git/index
    find . -path ./.git -prune -o -type f -printf '%p %s %T@ %m\n' | sort; find . -path ./.git -prune -o -type f -exec sha256sum {} + | sort )
}
r0runs()  { grep -c '^exec 1000:1000 stdin php -d display_errors=stderr -d log_errors=0 -d opcache.enable_cli=0 -- ' "$TR"; }
refused() { grep -q "GATE REFUSED: $1" "$LOG" && [ "$RC" = 4 ] && [ "$(r0runs)" = 0 ]; }

# ═════════════════════════════════════════════════════════════════════════════════════════════════════════════════════
suite() {       # suite <runner source>: every case; failures counted in $fails
  local RUNSRC="$1" WS opt
  RUN0_SAVE="$RUN0"; RUN0="$RUNSRC"

  D="$SB/h"; world "$D"; sandline
  L="${L//$RSHA/ffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffff}"
  if [ "$(printf '%s' "$L" | grep -c ffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffff)" = 1 ]; then runL
    chk "H  a wrong sha256 in the line: the runner never runs, nothing is logged, docker is never called" \
      bash -c "[ $RC != 0 ] && [ -z '$LOG' ] && [ ! -s '$TR' ] && ! ls '$WK'/src-* >/dev/null 2>&1"
  else bad "H: the line's sha256 could not be replaced"; fi
  D="$SB/r1"; world "$D"; touch -d '2026-01-01' "$CK/README.md"   # a stale stat: a plain `git status` would rewrite the index
  ckstate > "$D/s0"; handover; ckstate > "$D/s1"
  chk "R1 the line, on a checkout that does not hold R0: R0: DONE, RESULT: R0 DONE, and the log's last line RUNNER EXIT 0" \
    bash -c "[ '$RC' = 0 ] && grep -q '^R0: DONE — read only; nothing was written.\$' '$LOG' && grep -q 'RESULT: R0 DONE (exit 0). Send this LOG FILE back; keep it confidential: it holds the book.s ids and counts.\$' '$LOG' && [ \"\$(tail -n1 '$LOG')\" = '  RUNNER EXIT 0' ]"
  chk "R1 every gate line is there: checkout clean, the commit, both sha256s, the book and its owner" \
    bash -c "grep -q 'tracked files modified or staged: 0; not tracked inside dishnet-hybrid-sudan/ (data/ aside, ignored included): 0' '$LOG' && grep -qE \"reviewed R0 +commit $BSHA\" '$LOG' && grep -q 'tool sha256 ${TSHA:0:16}… = reviewed; command sha256 ${WSHA:0:16}… = reviewed; the command pins the same tool' '$LOG' && grep -qE 'book +/data/ucrm/data/plugins/.dishnet-hybrid-sudan-data/plugin.sqlite3, owner 1000:1000 — as expected' '$LOG'"
  chk "R1 the R0 files were taken out of the commit: the checkout holds none" \
    bash -c "grep -q \"the checkout's own scripts/bank-recon-r0.sh: absent (not needed)\" '$LOG' && [ ! -e '$CK/scripts/bank-recon-r0.sh' ]"
  chk "R1 R0 ran exactly once, as the owner, on standard input" bash -c "[ \$(grep -c '^exec 1000:1000 stdin php -d display_errors=stderr -d log_errors=0 -d opcache.enable_cli=0 -- ' '$TR') = 1 ] && ! grep -q '^REFUSED' '$TR'"
  if php "$H/check.php" "$LOG" "$SB/book0/exp.tool" "$SB/book0/ids.json" tool ug > "$D/check"; then ok "R1 its report equals the independent count line for line, and the planted truth ($(grep -c '^ok' "$D/check") checks)"
  else bad "R1 the report: $(grep FAIL "$D/check" | head -3 | tr '\n' ';' | cut -c1-400)"; fi
  chk "R1 the checkout is byte-for-byte as before — HEAD, branches, stash, index (not refreshed), every file and its time" cmp -s "$D/s0" "$D/s1"
  chk "R1 the runner says so too" grep -qE 'checkout +unchanged: HEAD, branch, local branches and tags, stash, index' "$LOG"
  chk "R1 no warning from bash or anything else in the log: no 'warning:' line, no line naming the runner's own file, no exit 7" \
    bash -c "! grep -qi 'warning:' '$LOG' && ! grep -q 'bank-recon-r0-run.sh: ' '$LOG' && ! grep -q 'exit 7' '$LOG'"
  chk "R1 the R0 command's own line names the folder the gate checked" grep -qE 'folder +the R0 command read the folder checked above: /data/ucrm/data/plugins/\.dishnet-hybrid-sudan-data, owner 1000:1000, in ucrm$' "$LOG"
  chk "R1 the fetch brought no tag (origin holds one on the R0 commit)" bash -c "[ -z \"\$(git -C '$CK' tag -l)\" ] && git -C '$ORIG' rev-parse -q --verify refs/tags/rh-b >/dev/null"
  chk "R1 the log is 0600 in a 0700 folder owned by root" bash -c "[ \"\$(stat -c '%a %u' '$LOG')\" = '600 0' ] && [ \"\$(stat -c '%a %u' '$WK')\" = '700 0' ]"
  chk "R1 no planted name, reference, number or PHP diagnostic word is in the log" bash -c "! grep -Eq '$SECRETS' '$LOG'"
  chk "R1 no planted amount is in the log, in any form" noamount "$LOG" "$SB/book0/amounts"
  chk "R1 the command, run from the extracted folder, does not take any other repository for its checkout" grep -q 'this checkout   not readable as a git checkout' "$LOG"

  D="$SB/r1b"; world "$D"; (cd "$CK" && G pull -q --ff-only origin claude/study-this-jhe2eg); ckstate > "$D/s0"; handover; ckstate > "$D/s1"
  chk "R1b a checkout already holding the commit: its own copies are named the same, R0 runs, the checkout unchanged" \
    bash -c "[ $RC = 0 ] && grep -q \"the checkout's own scripts/bank-recon-r0.php: the same\" '$LOG' && cmp -s '$D/s0' '$D/s1'"

  D="$SB/r2"; world "$D"; echo local >> "$CK/README.md"; ckstate > "$D/s0"; handover; ckstate > "$D/s1"
  chk "R2 a modified tracked file: refused before R0; the change is kept" eval "refused 'tracked files are modified or staged' && cmp -s '$D/s0' '$D/s1' && grep -q local '$CK/README.md'"
  D="$SB/r3"; world "$D"; echo new > "$CK/new.txt"; G -C "$CK" add new.txt; handover
  chk "R3 a staged file: refused before R0" refused 'tracked files are modified or staged'
  D="$SB/r4"; world "$D"; G -C "$CK" rev-parse HEAD > "$CK/.git/MERGE_HEAD"; handover
  chk "R4 a merge in progress: refused before R0" refused 'the checkout has an operation in progress (MERGE_HEAD)'
  D="$SB/r5"; world "$D"; echo x > "$CK/dishnet-hybrid-sudan/stray.php"; handover
  chk "R5 an untracked file inside the plugin folder: refused before R0" refused 'files git does not track inside dishnet-hybrid-sudan/'
  D="$SB/r5b"; world "$D"; mkdir -p "$CK/dishnet-hybrid-sudan/data"; echo x > "$CK/dishnet-hybrid-sudan/data/plugin.log" || bad "R5b: the data/ file could not be planted"; echo n > "$CK/notes.txt"; ckstate > "$D/s0"; handover; ckstate > "$D/s1"
  chk "R5b untracked files in data/ and outside the plugin: listed, not refused; R0 runs; they are left as they are" \
    bash -c "[ $RC = 0 ] && grep -q 'untracked, not read by R0: notes.txt' '$LOG' && cmp -s '$D/s0' '$D/s1'"
  D="$SB/r6"; world "$D"; sandboxed "$RUNSRC" 1111111111111111111111111111111111111111 > "$D/x.sh"; direct "$D/x.sh"
  chk "R6 a commit the checkout does not hold: refused before R0" refused 'the checkout does not hold the reviewed commit'
  D="$SB/r7"; world "$D"; echo '// changed' >> "$TMPCL/scripts/bank-recon-r0.php"; G -C "$TMPCL" commit -qam E; G -C "$TMPCL" push -q origin claude/study-this-jhe2eg
  sandboxed "$RUNSRC" "$(G -C "$TMPCL" rev-parse HEAD)" > "$D/x.sh"; direct "$D/x.sh"
  chk "R7 a commit whose tool is not the reviewed one: refused before R0" refused 'the tool in .* has sha256 .*, not the reviewed'
  D="$SB/r8"; world "$D"; sed -i "s/^TOOL_SHA=\"[0-9a-f]*\"\$/TOOL_SHA=\"$Z64\"/" "$TMPCL/scripts/bank-recon-r0.sh"
  G -C "$TMPCL" commit -qam F; G -C "$TMPCL" push -q origin claude/study-this-jhe2eg
  sandboxed "$RUNSRC" "$(G -C "$TMPCL" rev-parse HEAD)" "$TSHA" "$(sha256sum < "$TMPCL/scripts/bank-recon-r0.sh" | cut -c1-64)" > "$D/x.sh"; direct "$D/x.sh"
  chk "R8 a command that pins another tool, its own sha256 accepted: refused before R0" refused 'the command does not pin the reviewed tool sha256'
  D="$SB/r9"; world "$D"; (cd "$CK" && G fetch -q origin claude/study-this-jhe2eg); G -C "$TMPCL" reset -q --hard HEAD~2; echo z > "$TMPCL/z"; G -C "$TMPCL" add z; G -C "$TMPCL" commit -qm rewritten
  G -C "$TMPCL" push -q -f origin claude/study-this-jhe2eg; direct "$D/runner.sh"
  chk "R9 a rewritten branch that no longer holds the commit: refused before R0" refused 'origin/claude/study-this-jhe2eg (.*) does not contain .* — the branch was rewritten'
  D="$SB/r10"; world "$D"; printf '{"pluginDataDir":"/data/ucrm/data/plugins/elsewhere"}\n' > "$M/$PLR/ucrm.json"; handover
  chk "R10 ucrm.json naming another data folder: an unexpected database path, refused before R0" refused 'the data folder would be /data/ucrm/data/plugins/elsewhere'
  D="$SB/r11"; world "$D"; chown 1001:1001 "$M/$PDR/plugin.sqlite3"; handover
  chk "R11 an unexpected owner: refused before R0" refused "the database's owner is 1001:1001, not 1000:1000"
  D="$SB/r12"; world "$D"; mkdir -p "$WK"; chmod 755 "$WK"; (cd "$CK" && G fetch -q origin claude/study-this-jhe2eg)
  TR="$D/trace"; : > "$TR"; env PATH="$SB/bin:$PATH" FAKE_MNT="$M" FAKE_TRACE="$TR" bash "$D/runner.sh" > "$D/direct.log" 2>&1; RC=$?; LOG="$D/direct.log"
  chk "R12 a log folder others can read: refused before R0" refused '.* must exist, owned by root, mode 700'
  for opt in --adjustment-id=1 x; do
    D="$SB/r13.${opt//[^a-z0-9]/}"; world "$D"; direct "$D/runner.sh" "$opt"
    chk "R13 an option ($opt): refused (4) before G0 and before docker; the log holds the message and RUNNER EXIT 4 only" \
      bash -c "[ '$RC' = 4 ] && [ ! -s '$TR' ] && [ \"\$(grep -v '^\$' '$LOG')\" = \"\$(printf 'unknown option: %s (the R0 run takes none)\n  RUNNER EXIT 4' '$opt')\" ]"
  done
  D="$SB/r14"; world "$D"; mkdir -p "$WK"; chmod 700 "$WK"; TR="$D/trace"; : > "$TR"
  env PATH="$SB/bin:$PATH" FAKE_MNT="$M" FAKE_TRACE="$TR" setpriv --reuid=1000 --regid=1000 --clear-groups bash "$D/runner.sh" > "$D/direct.log" 2>&1; RC=$?; LOG="$D/direct.log"
  chk "R14 not root: refused before anything" refused 'run this as root'
  D="$SB/r17"; world "$D"; UCRM_CONTAINER=ucrm-other handover
  chk "R17 UCRM_CONTAINER exported in the operator's shell: the line drops it — R0 runs once, in ucrm, nowhere else" \
    bash -c "[ '$RC' = 0 ] && [ \$(grep -c '^exec 1000:1000 stdin php' '$TR') = 1 ] && ! grep -q 'ucrm-other' '$TR' '$LOG'"
  D="$SB/r17b"; world "$D"; UCRM_CONTAINER=ucrm-other direct "$D/runner.sh"
  chk "R17b the runner started with UCRM_CONTAINER naming another container: refused before docker" \
    eval "refused \"UCRM_CONTAINER is set to 'ucrm-other'\" && [ ! -s '$TR' ]"
  D="$SB/r18"; world "$D"; FAKE_ON_P0="echo meanwhile > '$CK/changed-meanwhile.txt'" handover
  chk "R18 the checkout changed while R0 ran: THE CHECKOUT CHANGED (exit 5), never R0 DONE; RUNNER EXIT 5" \
    bash -c "[ '$RC' = 5 ] && grep -q 'RESULT: THE CHECKOUT CHANGED while R0 ran (exit 5); R0 itself ended with exit 0' '$LOG' && ! grep -q 'RESULT: R0 DONE' '$LOG'"
  D="$SB/r19"; world "$D"; echo 'x' > "$CK/dishnet-hybrid-sudan/old.bak"; printf '*.bak\n' >> "$CK/.git/info/exclude"; handover
  chk "R19 an IGNORED file inside the plugin folder: refused before R0, as the 09 Oct record counts it" refused 'files git does not track inside dishnet-hybrid-sudan/'
  D="$SB/r20"; world "$D"; G -C "$CK" checkout -q --detach; handover
  chk "R20 a detached HEAD: refused before R0" refused 'the checkout is on a detached HEAD'
  D="$SB/r21"; world "$D"; FAKE_PHP_R_FAIL=1 handover
  chk "R21 ucrm.json cannot be read: refused before R0, not taken as 'names none'" refused "the container's ucrm.json is missing, unreadable or not JSON"
  D="$SB/r23"; world "$D"; rm "$M/$PLR/ucrm.json"; handover
  chk "R23 ucrm.json missing: refused before R0, not taken as 'names none'" refused "the container's ucrm.json is missing, unreadable or not JSON"
  D="$SB/r24"; world "$D"; printf '{"pluginDataDir":"/data/ucr' > "$M/$PLR/ucrm.json"; handover
  chk "R24 ucrm.json truncated: refused before R0, not taken as 'names none'" refused "the container's ucrm.json is missing, unreadable or not JSON"
  D="$SB/r25"; world "$D"; cp -a "$M/$PDR" "$M/ucrm/data/plugins/elsewhere"
  FAKE_ON_CMD_READ="printf '{\"pluginDataDir\":\"/data/ucrm/data/plugins/elsewhere\"}\\n' > '$M/$PLR/ucrm.json'" handover
  chk "R25 ucrm.json changed after the gate, before the R0 command's own read: READ ANOTHER FOLDER (exit 6), never R0 DONE" \
    bash -c "[ '$RC' = 6 ] && grep -q 'RESULT: THE R0 COMMAND READ ANOTHER FOLDER' '$LOG' && grep -qE 'folder +the R0 command read: in ucrm; data /data/ucrm/data/plugins/elsewhere; database owner 1000:1000\$' '$LOG' && ! grep -q 'RESULT: R0 DONE' '$LOG'"
  D="$SB/r25b"; world "$D"; FAKE_ON_CMD_READ="chown 1001:1001 '$M/$PDR/plugin.sqlite3'" handover
  chk "R25b the book's owner changed after the gate, before the R0 command's own stat: exit 6, naming the owner it read" \
    bash -c "[ '$RC' = 6 ] && grep -qE 'folder +the R0 command read: in ucrm; data /data/ucrm/data/plugins/\.dishnet-hybrid-sudan-data; database owner 1001:1001\$' '$LOG' && ! grep -q 'RESULT: R0 DONE' '$LOG'"
  D="$SB/r25c"; world "$D"; sed -i '/^echo "  installed /d' "$TMPCL/scripts/bank-recon-r0.sh"
  if grep -q '^echo "  installed ' "$TMPCL/scripts/bank-recon-r0.sh" || ! grep -q '^TOOL_SHA=' "$TMPCL/scripts/bank-recon-r0.sh"; then bad "R25c: the command could not be doctored"; fi
  G -C "$TMPCL" commit -qam N; G -C "$TMPCL" push -q origin claude/study-this-jhe2eg
  sandboxed "$RUNSRC" "$(G -C "$TMPCL" rev-parse HEAD)" "$TSHA" "$(sha256sum < "$TMPCL/scripts/bank-recon-r0.sh" | cut -c1-64)" > "$D/x.sh"; direct "$D/x.sh"
  chk "R25c an R0 command that ran R0 (exit 0) without saying which folder it read: exit 6, never DONE" \
    bash -c "[ '$RC' = 6 ] && grep -q 'the R0 command read: (it printed no such line)' '$LOG' && [ \$(grep -c '^exec 1000:1000 stdin php' '$TR') = 1 ] && grep -q '^R0: DONE' '$LOG' && ! grep -q 'RESULT: R0 DONE' '$LOG'"
  D="$SB/r26"; world "$D" "$SB/cand.php"; handover
  own env -i PATH=/usr/bin:/bin php -d display_errors=stderr -d log_errors=0 -d opcache.enable_cli=0 -- "$M/$PDR" "$M/$PLR" < "$SB/cand.php" > "$D/own.out" 2>&1
  chk "R26 the candidate copy committed and pinned, on a book with candidates and no adjustment on 12 Sep: RESULT: R0 DONE (exit 0), never a 7" \
    bash -c "[ '$RC' = 0 ] && grep -q 'RESULT: R0 DONE (exit 0). Send' '$LOG' && ! grep -qiE 'exit 7|not identified' '$LOG' && [ \$(grep -c '^exec 1000:1000 stdin php' '$TR') = 1 ]"
  if php "$H/check.php" "$LOG" "$SB/book0/exp.cand" "$SB/book0/ids.json" cand ug > "$D/check"; then ok "R26 … its report equals the independent count for the candidate copy, candidates included ($(grep -c '^ok' "$D/check") checks)"
  else bad "R26 the report: $(grep FAIL "$D/check" | head -3 | tr '\n' ';' | cut -c1-400)"; fi
  report "$LOG" > "$D/rep.log"; report "$D/own.out" > "$D/rep.own"
  chk "R26 … the candidate lines pass through the runner's copy loop unchanged: the report equals the copy's own output, run directly" \
    bash -c "grep -q '^  adj\. candidates ' '$D/rep.log' && cmp -s '$D/rep.log' '$D/rep.own'"
  chk "R26 … and nothing planted, no amount" bash -c "! grep -Eq '$SECRETS' '$LOG' && { [ ! -s '$SB/book0/amounts' ] || ! grep -Fwq -f '$SB/book0/amounts' '$LOG'; }"
  D="$SB/r27"; world "$D"; ckstate > "$D/s0"; handover_sig INT; ckstate > "$D/s1"
  chk "R27 Ctrl-C after R0 began (its docker exec under way): the log still ends RUNNER EXIT 130, no RESULT, the checkout unchanged" \
    bash -c "[ '$RC' = 130 ] && grep -q 'R0 begins' '$LOG' && ! grep -q 'RESULT:' '$LOG' && [ \$(grep -c '^exec 1000:1000 stdin php' '$TR') = 0 ] && cmp -s '$D/s0' '$D/s1'"
  D="$SB/r27h"; world "$D"; handover_sig HUP
  chk "R27h a hang-up after R0 began: the log still ends RUNNER EXIT 129, no RESULT line" bash -c "[ '$RC' = 129 ] && ! grep -q 'RESULT:' '$LOG'"
  D="$SB/r27t"; world "$D"; handover_sig TERM
  chk "R27t TERM to the whole job after R0 began (kill %1): the log still ends RUNNER EXIT 143, no RESULT line" bash -c "[ '$RC' = 143 ] && ! grep -q 'RESULT:' '$LOG'"
  D="$SB/r27g"; world "$D"; SIGAT=git handover_sig INT
  chk "R27g Ctrl-C while the runner's own git read writes into a file: the exit line still reaches the log, not that file; no docker call" \
    bash -c "[ '$RC' = 130 ] && ! grep -q 'R0 begins' '$LOG' && [ ! -s '$TR' ] && ! grep -rqs 'RUNNER EXIT' '$WK'/src-*"
  D="$SB/r27c"; world "$D"; plain="${LINE/| ( trap \'\' INT HUP TERM; exec tee /| tee /}"; plain="${plain% )} )"; plain="${plain/.log ) )/.log )}"
  [ "$plain" != "$LINE" ] || bad "R27c: the line could not be weakened"
  handover_sig INT "$plain"
  chk "R27c control: the same Ctrl-C with a plain tee cuts the log off before RUNNER EXIT — R27 has something to prove" \
    bash -c "[ '$RC' = noexitline ]"
  D="$SB/r28"; world "$D"; ckstate > "$D/s0"; handover "${LINE/ --no-tags/}"; ckstate > "$D/s1"
  chk "R28 control: the line without --no-tags creates the tag in the checkout — R1's no-tag check has something to prove" \
    bash -c "[ \"\$(git -C '$CK' tag -l)\" = rh-b ] && ! cmp -s '$D/s0' '$D/s1'"
  D="$SB/r4b"; world "$D"; G -C "$CK" checkout -q --detach; mkdir "$CK/.git/rebase-merge"; handover
  chk "R4b a rebase in progress (HEAD detached by it): refused, naming the rebase" refused 'the checkout has an operation in progress (rebase-merge)'
  D="$SB/r4c"; world "$D"; mkdir "$CK/.git/sequencer"; echo "pick 0000000 x" > "$CK/.git/sequencer/todo"; handover
  chk "R4c a cherry-pick sequence left unfinished (.git/sequencer, no CHERRY_PICK_HEAD): refused" refused 'the checkout has an operation in progress (sequencer)'
  D="$SB/r29"; world "$D"; head -c 300 /dev/urandom > "$CK/.git/index"; handover
  chk "R29 an index git cannot read: refused before R0 — a failed read is never 'nothing modified'" refused "git could not read the checkout's state"
  D="$SB/r30"; world "$D"; FAKE_ON_P0="head -c 300 /dev/urandom > '$CK/.git/index'" handover
  chk "R30 the index made unreadable while R0 ran: COULD NOT BE READ AGAIN (exit 5), never unchanged, never DONE" \
    bash -c "[ '$RC' = 5 ] && grep -q 'RESULT: THE CHECKOUT COULD NOT BE READ AGAIN after R0 (exit 5)' '$LOG' && ! grep -q 'RESULT: R0 DONE' '$LOG' && ! grep -qE 'checkout +unchanged' '$LOG'"
  D="$SB/r31"; world "$D"; FAKE_ON_P0="echo garbage > '$CK/.git/HEAD'" handover
  chk "R31 HEAD made unreadable while R0 ran: COULD NOT BE READ AGAIN (exit 5), never unchanged, never DONE" \
    bash -c "[ '$RC' = 5 ] && grep -q 'RESULT: THE CHECKOUT COULD NOT BE READ AGAIN after R0 (exit 5)' '$LOG' && ! grep -q 'RESULT: R0 DONE' '$LOG'"
  D="$SB/r32"; world "$D"; HO_PRE="$SB/slowgit" HO_ENV="FAIL_SYMREF_IF=$D/symref-fails" FAKE_ON_P0="touch '$D/symref-fails'" handover
  chk "R32 only the branch read of HEAD fails after R0 (exit 128): COULD NOT BE READ AGAIN, not taken for 'detached', not CHANGED" \
    bash -c "[ '$RC' = 5 ] && grep -q 'RESULT: THE CHECKOUT COULD NOT BE READ AGAIN after R0 (exit 5)' '$LOG' && ! grep -q 'RESULT: THE CHECKOUT CHANGED' '$LOG'"
  D="$SB/r22"; world "$D"; printf 'echo BASH_ENV RAN\n' > "$D/benv.sh"; BASH_ENV="$D/benv.sh" handover
  # (the rehearsal's own bash -c stands in for the operator's interactive shell, which would not read BASH_ENV; only the log counts)
  chk "R22 BASH_ENV exported: the line starts the runner without it; nothing of it reaches the log" bash -c "[ '$RC' = 0 ] && ! grep -q 'BASH_ENV RAN' '$LOG'"
  # xtrace too: the runner never resets it, so if the line let SHELLOPTS through, its trace ("+ …") would be in the log
  SO="SHELLOPTS=braceexpand:hashall:interactive-comments:monitor:noclobber:xtrace"
  D="$SB/r22c"; world "$D"; HO_ENV="$SO" handover
  chk "R22c SHELLOPTS exported (job control and xtrace on) in the operator's shell: the line drops it — no trace in the log, R0 DONE, the folder confirmed" \
    bash -c "[ '$RC' = 0 ] && ! grep -q '^+' '$LOG' && grep -qE 'folder +the R0 command read the folder checked above' '$LOG' && grep -q 'RESULT: R0 DONE (exit 0). Send' '$LOG'"
  D="$SB/r22e"; world "$D"; noso="${LINE/ -u SHELLOPTS -u BASHOPTS bash / bash }"
  [ "$noso" != "$LINE" ] || bad "R22e: the line could not be weakened"
  HO_ENV="$SO" handover "$noso"
  chk "R22e control: the same line without -u SHELLOPTS -u BASHOPTS lets the trace into the log — R22c has something to prove" \
    bash -c "grep -q '^+' '$LOG'"
  D="$SB/r22f"; world "$D"; perl -0pe 's/^shopt -s lastpipe[^\n]*\n//m' "$D/runner.sh" > "$D/x.sh"
  cmp -s "$D/runner.sh" "$D/x.sh" && bad "R22f: the runner could not be weakened"
  direct "$D/x.sh"
  chk "R22f a bash that does not keep a pipeline's last command in the shell (lastpipe removed): refused before R0, never a false exit 6" \
    refused "this bash does not run a pipeline's last command in the shell"
  D="$SB/r33"; world "$D"; touch "$D/symref-fails"; HO_PRE="$SB/slowgit" HO_ENV="FAIL_SYMREF_IF=$D/symref-fails" handover
  chk "R33 HEAD's branch cannot be read before R0 (exit 128): refused as unreadable, not as a detached HEAD" \
    eval "refused \"git could not read the checkout's state (the branch HEAD is on)\" && ! grep -q 'detached HEAD' '$LOG'"
  D="$SB/r22d"; world "$D"; mkdir -p "$WK"; chmod 700 "$WK"; (cd "$CK" && G fetch -q origin claude/study-this-jhe2eg); TR="$D/trace"; : > "$TR"
  env PATH="$SB/bin:$PATH" FAKE_MNT="$M" FAKE_TRACE="$TR" SHELLOPTS=braceexpand:hashall:interactive-comments:monitor bash "$D/runner.sh" > "$D/direct.log" 2>&1; RC=$?; LOG="$D/direct.log"
  chk "R22d the runner itself started with job control on (SHELLOPTS monitor): it turns it off — R0 DONE, the folder confirmed, not a false exit 6" \
    bash -c "[ '$RC' = 0 ] && grep -qE 'folder +the R0 command read the folder checked above' '$LOG' && [ \"\$(tail -n1 '$LOG')\" = '  RUNNER EXIT 0' ]"
  D="$SB/r22b"; world "$D"; printf 'echo BASH_ENV RAN\n' > "$D/benv.sh"; BASH_ENV="$D/benv.sh" direct "$D/runner.sh"
  chk "R22b the runner started with BASH_ENV set (its own bash reads it once): R0 runs once (exit 0), and R0's command does not read it" \
    bash -c "[ '$RC' = 0 ] && [ \$(grep -c '^exec 1000:1000 stdin php' '$TR') = 1 ] && [ \$(grep -c 'BASH_ENV RAN' '$LOG') = 1 ]"
  D="$SB/r15"; world "$D" "$SB/fail.php"; ckstate > "$D/s0"; handover; ckstate > "$D/s1"
  chk "R15 R0 stops (exit 3, a reading SQLite cannot finish): reported, NOT retried — R0 ran exactly once; the checkout unchanged" \
    bash -c "[ '$RC' = 3 ] && [ \$(grep -c '^exec 1000:1000 stdin php' '$TR') = 1 ] && grep -q 'SQLite could not finish reading the book' '$LOG' && grep -q 'RESULT: R0 STOPPED (exit 3)' '$LOG' && grep -q 'it was NOT retried' '$LOG' && cmp -s '$D/s0' '$D/s1'"
  D="$SB/r15b"; world "$D"; rm -f "$M/$PDR/plugin.sqlite3"
  own php -r '$p = new PDO("sqlite:" . $argv[1]); $p->exec("CREATE TABLE other(x)");' "$M/$PDR/plugin.sqlite3"; handover
  chk "R15b a book without cb_ledger: R0 REFUSED (exit 2), NOT retried — R0 ran exactly once" \
    bash -c "[ '$RC' = 2 ] && grep -q 'no cb_ledger table' '$LOG' && grep -q 'RESULT: R0 REFUSED (exit 2)' '$LOG' && [ \$(grep -c '^exec 1000:1000 stdin php' '$TR') = 1 ]"
  D="$SB/r16"; world "$D"; G init -q "$D/root"; G -C "$D/root" commit -q --allow-empty -m other; handover
  chk "R16 a git repository above the work folder is not taken for a checkout" bash -c "[ $RC = 0 ] && grep -q 'this checkout   not readable as a git checkout' '$LOG'"
  D="$SB/r34"; world "$D" "$SB/fatal.php"; ckstate > "$D/s0"; handover; ckstate > "$D/s1"
  chk "R34 a PHP fatal error after the reading (memory exhausted): R0 did not finish (exit 255), NOT retried — R0 ran once; the checkout unchanged" \
    bash -c "[ '$RC' = 255 ] && grep -q 'RESULT: R0 did not finish (exit 255). NOT retried.' '$LOG' && [ \$(grep -c '^exec 1000:1000 stdin php' '$TR') = 1 ] && cmp -s '$D/s0' '$D/s1'"
  chk "R34 … the log holds PHP's fatal line (sizes and a line number) and no section line, no DONE" \
    bash -c "grep -qE 'Allowed memory size of [0-9]+ bytes exhausted \(tried to allocate [0-9]+ bytes\) in Standard input code on line [0-9]+' '$LOG' && ! grep -qE '^  (tables|stores|columns|voided rows|void time|twins|checks) ' '$LOG' && ! grep -q '^R0: DONE' '$LOG'"
  chk "R34 … and no planted string and no planted amount (the diagnostic words are expected here)" \
    bash -c "! grep -Eq '$PLANTED' '$LOG' && { [ ! -s '$SB/book0/amounts' ] || ! grep -Fwq -f '$SB/book0/amounts' '$LOG'; }"
  RUN0="$RUN0_SAVE"
}

echo "== the gated run =="
suite "$RUN0"

# ── H2: the template's line with its PLACEHOLDER pins left in, run as it stands (only its two server paths replaced):
#    what it really does is what the template and the hand-over must say (guide.php placeholder) ─────────────────────
echo; echo "== the placeholder line, and the words of the hand-over =="
D="$SB/h2"; world "$D"
L="${TL//\/opt\/dishnet/$D/opt/dishnet}"; L="${L//\/root\/dnb-bank-recon/$D/root/dnb-bank-recon}"
[ "$(printf '%s' "$L" | grep -oF "$D/" | wc -l)" -ge 7 ] && [ "$(printf '%s' "$L" | grep -cF "$Z40:scripts/bank-recon-r0-run.sh")" = 1 ] || bad "H2: the template line's paths could not be replaced, or its pins are not the placeholders"
TR="$D/trace"; : > "$TR"
env PATH="$SB/bin:$PATH" FAKE_MNT="$M" FAKE_TRACE="$TR" bash -c "$L" > "$D/terminal" 2>&1; RC=$?
chk "H2 the template's line with the placeholder pins: refused at git cat-file (path … does not exist in '$Z40'), after its fetch ran; the sha256 check never reached" \
  bash -c "[ '$RC' != 0 ] && grep -qF \"fatal: path 'scripts/bank-recon-r0-run.sh' does not exist in '$Z40'\" '$D/terminal' && ! grep -q 'FAILED' '$D/terminal' && [ \"\$(git -C '$CK' rev-parse -q --verify refs/remotes/origin/claude/study-this-jhe2eg)\" = '$CSHA' ]"
chk "H2 … it leaves an empty runner file behind, and no log, no source folder, no docker call" \
  bash -c "[ -f '$WK/bank-recon-r0-run.sh' ] && [ ! -s '$WK/bank-recon-r0-run.sh' ] && ! ls '$WK'/r0-*.log >/dev/null 2>&1 && ! ls -d '$WK'/src-* >/dev/null 2>&1 && [ ! -s '$TR' ]"
sandline; L="set -o noclobber; $L"; runL
chk "H2 … the real line, typed next in a shell with noclobber: refused over that empty file (cannot overwrite existing file), no log, no docker call" \
  bash -c "grep -q 'cannot overwrite existing file' '$D/terminal' && [ -z '$LOG' ] && [ ! -s '$TR' ]"
rm -f "${WK:?}/bank-recon-r0-run.sh"; sandline; L="set -o noclobber; $L"; runL
chk "H2 … once that file is deleted, as the hand-over says, the same real line under noclobber runs: RUNNER EXIT 0" bash -c "[ '$RC' = 0 ]"
chk "H2 the template says so: refused at git cat-file after the fetch, an empty runner file, noclobber — not that sha256sum stops it" php "$H/guide.php" placeholder "$H/handover-template.txt"
OLDTPL="$SB/old-template.txt"; cat > "$OLDTPL" <<'OLDWORDS'
# handover-template.txt — the R0 hand-over line of the specification's §6.1, with PLACEHOLDER pins (an all-zero
# runner commit and an all-zero runner sha256). scripts/harness/bank-recon-r0/rehearse-run.sh reads it only while
# docs/69 holds no R0 hand-over line, and only with PINS=placeholder; it substitutes the sandbox's pins. This line is
# NOT for the server: with these pins, sha256sum refuses and nothing runs. The real line, with the pins of the
# committed runner, belongs in docs/69.
OLDWORDS
chk "H2 control: the template's earlier wording (sha256sum refuses, nothing runs) is refused by the same check" bash -c "! php '$H/guide.php' placeholder '$OLDTPL' >/dev/null"
if [ -n "$GUIDE" ] && grep -qi 'placeholder' "$GUIDE"; then
  chk "H2 the hand-over's placeholder paragraph says so too ($(basename "$GUIDE"))" php "$H/guide.php" placeholder "$GUIDE"
fi

# ── GD: the log-reading guide of the document the line came from, against logs this rehearsal made ─────────────────
#    a run that waited for the plugin (a -wal beside the book, removed three seconds into R0) and R1's run that did not
if [ -n "$GUIDE" ]; then
  D="$SB/gdw"; world "$D"; : > "$M/$PDR/plugin.sqlite3-wal"; chown "$O" "$M/$PDR/plugin.sqlite3-wal"
  FAKE_ON_P0="( sleep 3; rm -f '$M/$PDR/plugin.sqlite3-wal' ) </dev/null >/dev/null 2>&1 &" handover; WLOG="$LOG"
  chk "GD the waiting run: R0 waited for the -wal to go, then read (RUNNER EXIT 0, 'after waiting N s')" \
    bash -c "[ '$RC' = 0 ] && grep -qE 'read twice, immutable \(after waiting [0-9]+ s for the plugin to close the book\): the readings are identical' '$WLOG'"
  RLOG="$(ls -t "$SB"/r1/root/dnb-bank-recon/r0-*.log 2>/dev/null | head -1)"
  chk "GD the guide's read-twice line, read as a pattern, matches both the waiting run's line and R1's" php "$H/guide.php" readtwice "$GUIDE" "$WLOG" "$RLOG"
  chk "GD the guide says R0 may print nothing for up to two minutes" php "$H/guide.php" silence "$GUIDE"
  chk "GD the guide's exit table has a row for every exit the runner ends with: 0–6, 129 / 130 / 143 (no RESULT line, not retried), 255 or other" php "$H/guide.php" exits "$GUIDE"
  G2="$SB/guide-old-quote.md"; perl -pe 's/`read twice, immutable\[ \(after waiting N s for the plugin to close the book\)\]:/`read twice, immutable:/' "$GUIDE" > "$G2"
  chk "GD control: the guide with the read-twice line quoted only in its no-wait form fails against the waiting run's log" \
    bash -c "! cmp -s '$GUIDE' '$G2' && ! php '$H/guide.php' readtwice '$G2' '$WLOG' '$RLOG' >/dev/null && php '$H/guide.php' readtwice '$G2' '$RLOG' >/dev/null"
  G3="$SB/guide-no-interrupt.md"; grep -v '^| 129 / 130 / 143 |' "$GUIDE" > "$G3"
  chk "GD control: the guide without the 129 / 130 / 143 row fails the exit-table check" bash -c "! cmp -s '$GUIDE' '$G3' && ! php '$H/guide.php' exits '$G3' >/dev/null"
  G4="$SB/guide-no-silence.md"; perl -pe 's/print nothing for up to two minutes/pause/i' "$GUIDE" > "$G4"
  chk "GD control: the guide without the waiting sentence fails the silence check" bash -c "! cmp -s '$GUIDE' '$G4' && ! php '$H/guide.php' silence '$G4' >/dev/null"
else
  echo "  note GD not run: the line came from the harness template, which holds the line only — run with HANDOVER_FILE or once docs/69 holds the guide"
fi
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
mutant from-working-tree    's/g cat-file blob "\$R0_COMMIT:scripts\/bank-recon-r0\.php" > /cat "\$REPO\/scripts\/bank-recon-r0.php" > /'
mutant no-tool-sha          's/^\[ "\$T" = "\$TOOL_SHA" \] \|\| gate[^\n]*\n//m'
mutant no-pin-check         's/^\[ "\$\(grep -c "\^TOOL_SHA[^\n]*\n//m'
mutant no-ancestor          's/^g merge-base --is-ancestor[^\n]*\n//m'
mutant no-path-check        's/^\[ "\$\{PDD%\/\}" = "\$EXPECTED_DATA" \] \|\| gate[^\n]*\n//m'
mutant no-owner-check       's/^\[ "\$OWN" = "\$EXPECTED_OWNER" \] \|\| gate[^\n]*\n//m'
mutant retries              's/^rc=\$\{PIPESTATUS\[0\]\}\n/rc=\${PIPESTATUS[0]}; [ "\$rc" = 0 ] || { GIT_CEILING_DIRECTORIES="\$WORK" bash "\$SRC\/scripts\/bank-recon-r0.sh"; rc=\$?; }\n/m'
mutant index-refresh        's/git --no-optional-locks -C "\$REPO"/git -C "\$REPO"/'
mutant updates-checkout     's/^(ok "" "the working tree, the index, HEAD and every local branch are only read here[^\n]*\n)/$1g merge -q --ff-only "refs\/remotes\/origin\/\$BRANCH" >\/dev\/null 2>&1 || true\n/m'
mutant loose-log-folder     's/^\[ -d "\$WORK" \] && \[ "\$\(stat[^\n]*\n//m'
mutant no-ceiling           's/ GIT_CEILING_DIRECTORIES="\$WORK" bash "\$SRC/ bash "\$SRC/'
mutant not-root-ok          's/^\[ "\$\(id -u\)" = "0" \] \|\| gate[^\n]*\n//m'
mutant no-container-gate    's/^\[ "\$\{UCRM_CONTAINER:-\$CONTAINER\}" = "\$CONTAINER" \] \|\| gate[^\n]*\n//m'
mutant keeps-bash-env       's/env -u UCRM_CONTAINER -u BASH_ENV -u ENV /env -u UCRM_CONTAINER /'
mutant no-exit-line         's/^trap \x27printf "\\n  RUNNER EXIT %s\\n" "\$\?" >&3\x27 EXIT\n//m'
mutant exit-line-on-stdout  's/"\$\?" >&3\x27 EXIT/"\$?"\x27 EXIT/'
mutant changed-as-done      's/^if \[ "\$AFTER" != "\$BEFORE" \]; then\n/if false; then\n/m'
mutant exclude-ignored      's/ls-files --others -- "\$PLUGIN"/ls-files --others --exclude-standard -- "\$PLUGIN"/'
mutant detached-ok          's/^  detached\) gate[^\n]*\n//m'
mutant headref-err-as-ok    's/^  \x27\?ERR\?\x27\)  gate[^\n]*\n//m'
mutant part-swallows-errors 's/ \|\| \{ printf \x27\?ERR\?\x27; return; \}//'
mutant no-lp-guard          's/^LP=0; true \| LP=1;[^\n]*\n//m'
mutant ucrm-read-ignored    's/\)" \\\n  \|\| gate "the container\x27s ucrm.json is missing[^\n]*\n/)"\n/'
mutant weak-ucrm-read       's/\$s=\@file_get_contents\(\$argv\[1\]\); if\(!is_string\(\$s\)\) exit\(3\); \$u=json_decode\(\$s,true\); if\(!is_array\(\$u\)\) exit\(4\); /\$u=\@json_decode((string)\@file_get_contents(\$argv[1]),true); /'
mutant no-folder-check      's/^  if \[ "\$READ" != [^\n]*\n/  if false; then\n/m'
mutant no-sequencer         's/ rebase-apply sequencer; do/ rebase-apply; do/'
mutant git-read-unchecked   's/\|\| gate "git could not read the checkout[^\n]*/|| true/g'
mutant after-err-unseen     's/^if \[\[ "\$AFTER" == \*\x27\?ERR\?\x27\* \]\]; then\n  echo "  RESULT: THE CHECKOUT COULD NOT/if false; then\n  echo "  RESULT: THE CHECKOUT COULD NOT/m'
mutant owner-not-compared   's/if \[ "\$READ" != "in \$CONTAINER; data \$EXPECTED_DATA; database owner \$EXPECTED_OWNER" \]/if [ "\${READ%; database owner *}" != "in \$CONTAINER; data \$EXPECTED_DATA" ]/'
mutant index-through-shell  's/"\$\(part sha256sum "\$GD\/index"\)"/"\$(part cat "\$GD\/index")"/'
mutant no-set-m             's/^set \+m [^\n]*\n//m'
mutant lastpipe-unguarded   's/^set \+m [^\n]*\n//m; s/^LP=0; true \| LP=1;[^\n]*\n//m'
mutant headref-detached     's/\*\) printf \x27\?ERR\?\x27 ;; esac/*) printf \x27detached\x27 ;; esac/'
mutant installed-optional   's/^if \[ -n "\$INSTALLED" \] \|\| \[ "\$rc" != 1 \]; then/if [ -n "\$INSTALLED" ]; then/m'
mutant detached-first       's/^(for f in MERGE_HEAD[^\n]*\n[^\n]*\ndone\n)(.*?)(^fingerprint\(\) \{)/$2$1$3/ms'
mutant option-passthrough   's/bash "\$SRC\/scripts\/bank-recon-r0\.sh" 2>&1/bash "\$SRC\/scripts\/bank-recon-r0.sh" --adjustment-id=0 2>&1/'
mutant p0-files             's/g cat-file blob "\$R0_COMMIT:scripts\/bank-recon-r0\.(sh|php)"/g cat-file blob "\$R0_COMMIT:scripts\/bank-recon-p0.$1"/g'
mutant options-accepted     's/^\[ \$# -eq 0 \] \|\| \{ echo "unknown option: \$1 \(the R0 run takes none\)"; exit 4; \}\n//m'
# R5b's control: with data/ no longer excluded, the ignored file planted in data/ (now really there) is refused — so R5b
# has something to prove. While R5b planted nothing (its data/ folder did not exist in the clone), no case put a file in
# data/, so no case could catch this copy
mutant data-not-excluded    's/ ":\(exclude\)\$PLUGIN\/data"//'
echo; echo "== total: $pass ok, $fails failed (the gated run itself: $MAIN failed) =="
[ "$fails" = 0 ]
