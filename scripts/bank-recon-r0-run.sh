#!/usr/bin/env bash
#
# bank-recon-r0-run.sh — the gated run of R0 (docs/69 §2.3.8), built on the operator's approval of 16 Oct. It runs the
# reviewed R0 exactly once, and only when every gate below holds; otherwise it stops before R0 and names the gate. It
# never updates the checkout's working tree, never retries, never repairs. It is P-zero's runner
# (scripts/bank-recon-p0-run.sh), copied: the gates are the same; only the files it runs, its pins and its texts are R0's,
# it takes no option, and it has no exit 7.
#
# It is not run from the checkout. The hand-over fetches the branch (new objects and the remote-tracking ref only), takes
# THIS file out of a pinned commit with `git cat-file`, checks its sha256, and runs it from /root/dnb-bank-recon. The
# command is in the hand-over, not repeated here. Every git call here passes --no-optional-locks, so not even the index's
# stat cache is refreshed.
#
#   G0  root; no UCRM_CONTAINER naming another container than the one checked below (the R0 command reads it); this bash
#       keeps a pipeline's last command in the shell (lastpipe, job control off), or the checks after R0 could not read
#       its lines; the log folder root's own, mode 700.
#   G1  the checkout: a git repository at /opt/dishnet; no merge, rebase, cherry-pick, revert or bisect in progress (a
#       sequence left in .git/sequencer included); on a branch (not a detached HEAD); every git read of its state
#       succeeds (a read that fails is a refusal, never "nothing found"); no tracked file modified or staged; no file git
#       does not track inside the plugin folder (its data/ aside), ignored ones included — as the 09 Oct record and the
#       deploy scripts count it. Untracked files elsewhere are counted and listed, not refused: R0 never reads them.
#   G2  the reviewed R0: commit R0_COMMIT is present, and the fetched branch still contains it. The tool and the command
#       are taken out of THAT commit (never out of the working tree) into a new folder, and each must match its reviewed
#       sha256; the command must pin the same tool sha256.
#   G3  the book: the container is running; its ucrm.json exists and is valid JSON that decodes as the plugin decodes it
#       (missing, unreadable, truncated, not JSON, or a bare value stops here); the data folder it names, or the folder
#       beside the plugin when it names none, is
#       EXPECTED_DATA (the live store since 09 Oct); plugin.sqlite3 is there; its owner is EXPECTED_OWNER. Anything else is
#       an unexpected database path and stops here.
#   G4  then R0 runs ONCE, in this container, with UCRM_CONTAINER, BASH_ENV, ENV, SHELLOPTS and BASHOPTS removed from its
#       environment. Whatever its exit, it is reported with its meaning and NOT retried. Afterwards the R0 command's own
#       `installed` line must name this container, EXPECTED_DATA and EXPECTED_OWNER — the command reads ucrm.json again
#       itself, so a change in between would otherwise go unseen (exit 6).
#   G5  afterwards the checkout is proved untouched: HEAD, the branch, every local branch and tag, the stash, the index and
#       the status of every file (an ignored directory counts as one entry) are the same as before R0; a read that fails
#       afterwards is reported as "could not be read again" (exit 5), never as unchanged.
#
# Exit: R0's own exit (0 done; 1 the command's STOP; 2 R0 refused; 3 R0 stopped on a check or on its own internal error);
# 4 a gate refused before R0 (an option given to this runner included); 5 the checkout changed while R0 ran (nothing here
# changes it — reported, never repaired); 6 the R0 command read another folder than the one checked. There is no exit 7.
# The exit is the log's last line, "RUNNER EXIT <n>" (the hand-over pipes into tee, whose status the shell reports
# instead; its tee ignores Ctrl-C, a hang-up and TERM, and the line is written on descriptor 3, a copy of the log's, so no
# redirection in force at that moment can divert it). A log that does not end with that line was cut off and is no result.
set -u
umask 077
set +m              # no job control, even if an exported SHELLOPTS asked for it ("monitor"): lastpipe needs it off
shopt -s lastpipe   # the loop that passes R0's output on runs in this shell, so it keeps its line without a side file
exec 3>&1          # the exit line goes to this copy of the log: a signal during `g … > file` must not divert it there
trap 'printf "\n  RUNNER EXIT %s\n" "$?" >&3' EXIT
trap 'exit 129' HUP; trap 'exit 130' INT; trap 'exit 143' TERM
REPO="/opt/dishnet"
WORK="/root/dnb-bank-recon"
BRANCH="claude/study-this-jhe2eg"
PLUGIN="dishnet-hybrid-sudan"
R0_COMMIT="fa327f4a02fcb07a529380bc123580857970d6c5"
TOOL_SHA="9cb7e4fecb592f1abcffd41c29ad23c2804d56b680616509ded6d19d14b085b4"
WRAP_SHA="c34d4675a131c2bba87713255e3165cd103620e0830a454ce36948830c8ed7fd"
CONTAINER="ucrm"
IN_CONTAINER="/data/ucrm/data/plugins/$PLUGIN"
EXPECTED_DATA="/data/ucrm/data/plugins/.$PLUGIN-data"
EXPECTED_OWNER="1000:1000"

[ $# -eq 0 ] || { echo "unknown option: $1 (the R0 run takes none)"; exit 4; }
gate() { printf '\n  GATE REFUSED: %s\n\n  R0 was NOT run (exit 4). Nothing was changed. Send this log file.\n' "$*"; exit 4; }
ok()   { printf '  %-15s %s\n' "$1" "$2"; }
g()    { git --no-optional-locks -C "$REPO" "$@"; }   # never refreshes or locks the index: a plain `git status` may rewrite it
dx()   { docker exec "$@" </dev/null; }

printf '\n== bank-recon R0, gated run — %s ==\n\n' "$(date -u +%Y%m%dT%H%M%SZ)"
[ "$(id -u)" = "0" ] || gate "run this as root"
[ "${UCRM_CONTAINER:-$CONTAINER}" = "$CONTAINER" ] || gate "UCRM_CONTAINER is set to '$UCRM_CONTAINER' — R0 would read that container, not the $CONTAINER checked here; unset it"
command -v git >/dev/null 2>&1 && command -v docker >/dev/null 2>&1 && command -v sha256sum >/dev/null 2>&1 || gate "git, docker or sha256sum is missing"
LP=0; true | LP=1; [ "$LP" = 1 ] || gate "this bash does not run a pipeline's last command in the shell (lastpipe) — the checks after R0 could not read its lines"
[ -d "$WORK" ] && [ "$(stat -c '%u %a' "$WORK")" = "0 700" ] || gate "$WORK must exist, owned by root, mode 700 (the hand-over makes it with umask 077) — the log is confidential"

# ── G1: the checkout ───────────────────────────────────────────────────────────────────────────────────────────────
[ "$(g rev-parse --is-inside-work-tree 2>/dev/null)" = "true" ] && [ "$(g rev-parse --show-toplevel 2>/dev/null)" = "$(realpath "$REPO" 2>/dev/null)" ] || gate "$REPO is not the top of a git checkout"
GD="$(g rev-parse --absolute-git-dir)"
for f in MERGE_HEAD CHERRY_PICK_HEAD REVERT_HEAD BISECT_LOG rebase-merge rebase-apply sequencer; do
  [ ! -e "$GD/$f" ] || gate "the checkout has an operation in progress ($f) — not the state the records describe"
done
part() {   # part <command…>: its output's hash, or ?ERR? when it fails ('?' is in no ref name and no hash)
  local o; o="$("$@" 2>/dev/null)" || { printf '?ERR?'; return; }; printf '%s' "$o" | sha256sum | cut -c1-16
}
headref() {       # the branch HEAD is on; "detached" only when git says so (exit 1), ?ERR? when it cannot read HEAD
  local r; r="$(g symbolic-ref -q HEAD 2>/dev/null)"
  case $? in 0) printf '%s' "$r" ;; 1) printf 'detached' ;; *) printf '?ERR?' ;; esac
}
case "$(headref)" in
  detached) gate "the checkout is on a detached HEAD — not the state the records describe" ;;
  '?ERR?')  gate "git could not read the checkout's state (the branch HEAD is on) — it cannot be shown clean or unchanged" ;;
esac
fingerprint() {   # everything a checkout update could change, as one line
  printf '%s|%s|%s|%s|%s|%s\n' \
    "$(g rev-parse HEAD 2>/dev/null || printf '?ERR?')" \
    "$(headref)" \
    "$(part g for-each-ref --format='%(refname) %(objectname)' refs/heads refs/tags)" \
    "$(part g stash list)" \
    "$(part sha256sum "$GD/index")" \
    "$(part g -c core.quotePath=false status --porcelain=v1 --untracked-files=all --ignored=matching)"
}
BEFORE="$(fingerprint)"
[[ "$BEFORE" != *'?ERR?'* ]] || gate "git could not read the checkout's state (a ref, the stash, the index or its status) — it cannot be shown clean or unchanged"
DIRTY="$(g -c core.quotePath=false status --porcelain=v1 --untracked-files=no)" || gate "git could not read the checkout's state (git status failed) — it cannot be shown clean"
UNTR_PLUGIN="$(g -c core.quotePath=false ls-files --others -- "$PLUGIN" ":(exclude)$PLUGIN/data")" || gate "git could not read the checkout's state (the files it does not track) — it cannot be shown clean"   # ignored files too, as the deploy scripts count
UNTR_ALL="$(g -c core.quotePath=false ls-files --others --exclude-standard)" || gate "git could not read the checkout's state (the files it does not track) — it cannot be shown clean"
n() { printf '%s' "$1" | grep -c . || true; }
ok "checkout" "$REPO at $(g rev-parse --short HEAD) on $(g symbolic-ref -q --short HEAD || echo 'a detached HEAD')"
ok "" "tracked files modified or staged: $(n "$DIRTY"); not tracked inside $PLUGIN/ (data/ aside, ignored included): $(n "$UNTR_PLUGIN"); untracked elsewhere: $(n "$(printf '%s\n' "$UNTR_ALL" | grep -v "^$PLUGIN/")")"
if [ -n "$UNTR_ALL" ] && [ -z "$UNTR_PLUGIN" ]; then
  printf '%s\n' "$UNTR_ALL" | head -5 | sed 's/^/                    untracked, not read by R0: /'
fi
[ -z "$DIRTY" ] || { printf '%s\n' "$DIRTY" | head -10 | sed 's/^/                    /'; gate "tracked files are modified or staged in the checkout — unrelated work; R0 does not touch it, but this state is not the expected one"; }
[ -z "$UNTR_PLUGIN" ] || { printf '%s\n' "$UNTR_PLUGIN" | head -10 | sed 's/^/                    /'; gate "files git does not track inside $PLUGIN/ — the 09 Oct record has none"; }
ok "" "the working tree, the index, HEAD and every local branch are only read here; nothing below checks anything out"

# ── G2: the reviewed R0, out of its commit ───────────────────────────────────────────────────────────────────────────
g cat-file -e "$R0_COMMIT^{commit}" 2>/dev/null || gate "the checkout does not hold the reviewed commit $R0_COMMIT — the fetch in the hand-over did not bring it"
TIP="$(g rev-parse -q --verify "refs/remotes/origin/$BRANCH" 2>/dev/null || true)"
[ -n "$TIP" ] || gate "there is no origin/$BRANCH after the fetch"
g merge-base --is-ancestor "$R0_COMMIT" "$TIP" || gate "origin/$BRANCH (${TIP:0:7}) does not contain $R0_COMMIT — the branch was rewritten"
ok "reviewed R0" "commit $R0_COMMIT; origin/$BRANCH at ${TIP:0:7}, $(g rev-list --count "$R0_COMMIT..$TIP") commit(s) after it"
SRC="$(mktemp -d "$WORK/src-r0-${R0_COMMIT:0:7}-XXXXXX")" || gate "could not make a folder under $WORK"
mkdir "$SRC/scripts"
g cat-file blob "$R0_COMMIT:scripts/bank-recon-r0.sh"  > "$SRC/scripts/bank-recon-r0.sh"  2>/dev/null || gate "the commit has no scripts/bank-recon-r0.sh"
g cat-file blob "$R0_COMMIT:scripts/bank-recon-r0.php" > "$SRC/scripts/bank-recon-r0.php" 2>/dev/null || gate "the commit has no scripts/bank-recon-r0.php"
T="$(sha256sum "$SRC/scripts/bank-recon-r0.php" | cut -c1-64)"; W="$(sha256sum "$SRC/scripts/bank-recon-r0.sh" | cut -c1-64)"
[ "$T" = "$TOOL_SHA" ] || gate "the tool in $R0_COMMIT has sha256 $T, not the reviewed $TOOL_SHA"
[ "$W" = "$WRAP_SHA" ] || gate "the command in $R0_COMMIT has sha256 $W, not the reviewed $WRAP_SHA"
[ "$(grep -c "^TOOL_SHA=\"$TOOL_SHA\"\$" "$SRC/scripts/bank-recon-r0.sh")" = 1 ] || gate "the command does not pin the reviewed tool sha256"
ok "" "taken out of the commit into $SRC: tool sha256 ${T:0:16}… = reviewed; command sha256 ${W:0:16}… = reviewed; the command pins the same tool"
for f in bank-recon-r0.sh bank-recon-r0.php; do
  if [ -f "$REPO/scripts/$f" ]; then
    cmp -s "$REPO/scripts/$f" "$SRC/scripts/$f" && s="the same" || s="DIFFERENT (not used)"
  else s="absent (not needed)"; fi
  ok "" "the checkout's own scripts/$f: $s"
done

# ── G3: the book ────────────────────────────────────────────────────────────────────────────────────────────────────
[ "$(docker inspect -f '{{.State.Running}}' "$CONTAINER" 2>/dev/null)" = "true" ] || gate "the $CONTAINER container is not running"
PDD="$(dx "$CONTAINER" php -r '$s=@file_get_contents($argv[1]); if(!is_string($s)) exit(3); $u=json_decode($s,true); if(!is_array($u)) exit(4); echo rtrim((string)($u["pluginDataDir"]??""),"/");' "$IN_CONTAINER/ucrm.json" 2>/dev/null)" \
  || gate "the container's ucrm.json is missing, unreadable or not JSON — the data folder cannot be established"
[ -n "$PDD" ] || PDD="$EXPECTED_DATA"   # ucrm.json names none (09 Oct): the folder beside the plugin, as the command chooses
[ "${PDD%/}" = "$EXPECTED_DATA" ] || gate "the data folder would be $PDD, not $EXPECTED_DATA — an unexpected database path"
dx "$CONTAINER" test -f "$EXPECTED_DATA/plugin.sqlite3" 2>/dev/null || gate "no plugin.sqlite3 in $EXPECTED_DATA"
OWN="$(dx "$CONTAINER" stat -c '%u:%g' "$EXPECTED_DATA/plugin.sqlite3" 2>/dev/null || true)"
[ "$OWN" = "$EXPECTED_OWNER" ] || gate "the database's owner is ${OWN:-unreadable}, not $EXPECTED_OWNER"
ok "book" "$EXPECTED_DATA/plugin.sqlite3, owner $OWN — as expected"

# ── G4: R0, once ────────────────────────────────────────────────────────────────────────────────────────────────────
printf '\n  ── R0 begins (it runs once; whatever it ends with is reported, not retried) ──\n'
# Every line goes on to the log as it comes; one of them is also kept, in this shell only, for the check below: the
# command's first `installed` line.
INSTALLED=""
env -u UCRM_CONTAINER -u BASH_ENV -u ENV -u SHELLOPTS -u BASHOPTS GIT_CEILING_DIRECTORIES="$WORK" bash "$SRC/scripts/bank-recon-r0.sh" 2>&1 \
  | while IFS= read -r line || [ -n "$line" ]; do
      printf '%s\n' "$line"
      case "$line" in
        "  installed "*)     [ -n "$INSTALLED" ] || INSTALLED="$line" ;;
      esac
    done
rc=${PIPESTATUS[0]}
printf '  ── R0 ended: exit %s ──\n\n' "$rc"

# ── G5: the checkout, untouched ─────────────────────────────────────────────────────────────────────────────────────
AFTER="$(fingerprint)"
if [ "$AFTER" = "$BEFORE" ]; then
  ok "checkout" "unchanged: HEAD, branch, local branches and tags, stash, index and every file's status are as before"
elif [[ "$AFTER" == *'?ERR?'* ]]; then
  ok "checkout" "COULD NOT BE READ AGAIN after R0 (before $BEFORE, after $AFTER) — it cannot be shown unchanged; nothing repaired"
else
  ok "checkout" "CHANGED while R0 ran (before $BEFORE, after $AFTER) — not by this run, which only reads it; nothing repaired"
fi
# The command prints "  installed  <commit> in <container>; data <folder>; database owner <uid:gid>" before it reads.
READ="$(printf '%s\n' "$INSTALLED" | sed -E 's/^  installed +[^ ]+ //')"
if [ -n "$INSTALLED" ] || [ "$rc" != 1 ]; then   # exit 1 with no such line: the command stopped before reading anything
  if [ "$READ" != "in $CONTAINER; data $EXPECTED_DATA; database owner $EXPECTED_OWNER" ]; then
    ok "folder" "the R0 command read: ${READ:-(it printed no such line)}"
    echo
    echo "  RESULT: THE R0 COMMAND READ ANOTHER FOLDER than the one checked, or did not say which (exit 6); R0 ended with"
    echo "  exit $rc. Do not use these counts. Nothing was repaired. Send this log file."
    exit 6
  fi
  ok "folder" "the R0 command read the folder checked above: $EXPECTED_DATA, owner $EXPECTED_OWNER, in $CONTAINER"
fi
echo
if [[ "$AFTER" == *'?ERR?'* ]]; then
  echo "  RESULT: THE CHECKOUT COULD NOT BE READ AGAIN after R0 (exit 5); R0 itself ended with exit $rc. Do not use these"
  echo "  counts until that is explained. Nothing was repaired. Send this log file."
  exit 5
fi
if [ "$AFTER" != "$BEFORE" ]; then
  echo "  RESULT: THE CHECKOUT CHANGED while R0 ran (exit 5); R0 itself ended with exit $rc. Do not use these counts until the"
  echo "  change is explained. Nothing was repaired. Send this log file."
  exit 5
fi
case "$rc" in
  0) echo "  RESULT: R0 DONE (exit 0). Send this LOG FILE back; keep it confidential: it holds the book's ids and counts." ;;
  3) echo "  RESULT: R0 STOPPED (exit 3) — it saw the plugin write during the reading, one of its checks failed, or it stopped on"
     echo "  an internal error (the reason is above). As instructed, it was NOT retried. Send this log file; a further run waits"
     echo "  for your word." ;;
  2) echo "  RESULT: R0 REFUSED (exit 2) — the reason is above. NOT retried. Send this log file." ;;
  1) echo "  RESULT: the R0 command STOPPED before R0 ran (exit 1) — the reason is above. Send this log file." ;;
  *) echo "  RESULT: R0 did not finish (exit $rc). NOT retried. Send this log file." ;;
esac
exit "$rc"
