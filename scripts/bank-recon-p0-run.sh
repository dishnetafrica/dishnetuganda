#!/usr/bin/env bash
#
# bank-recon-p0-run.sh — the gated run of P0 (docs/68 §O, §P), on the operator's approval of 13 Oct. It runs the reviewed P0
# exactly once, and only when every gate below holds; otherwise it stops before P0 and names the gate. It never updates
# the checkout's working tree, never retries, never repairs.
#
# It is not run from the checkout. The hand-over fetches the branch (new objects and the remote-tracking ref only), takes
# THIS file out of a pinned commit with `git cat-file`, checks its sha256, and runs it from /root/dnb-bank-recon. The
# command is in docs/68 §P, not repeated here. Every git call here passes --no-optional-locks, so not even the index's
# stat cache is refreshed.
#
#   G0  root; no UCRM_CONTAINER naming another container than the one checked below (the P0 command reads it); the log
#       folder root's own, mode 700.
#   G1  the checkout: a git repository at /opt/dishnet, on a branch (not a detached HEAD); no merge, rebase,
#       cherry-pick, revert or bisect in progress; no tracked file modified or staged; no file git does not track inside
#       the plugin folder (its data/ aside), ignored ones included — as the 09 Oct record and the deploy scripts count
#       it. Untracked files elsewhere are counted and listed, not refused: P0 never reads the checkout's files.
#   G2  the reviewed P0: commit P0_COMMIT is present, and the fetched branch still contains it. The tool and the command
#       are taken out of THAT commit (never out of the working tree) into a new folder, and each must match its reviewed
#       sha256; the command must pin the same tool sha256.
#   G3  the book: the container is running; its ucrm.json is read (a failed read stops here); the data folder resolves,
#       as the command resolves it, to EXPECTED_DATA (the live store since 09 Oct); plugin.sqlite3 is there; its owner is
#       EXPECTED_OWNER. Anything else is an unexpected database path and stops here.
#   G4  then P0 runs ONCE, in this container, with UCRM_CONTAINER, BASH_ENV and ENV removed from its environment. Whatever
#       its exit, it is reported with its meaning and NOT retried.
#   G5  afterwards the checkout is proved untouched: HEAD, the branch, every local branch and tag, the stash, the index and
#       the status of every file are the same as before P0.
#
# Exit: P0's own exit (0 done; 1 the command's STOP; 2 P0 refused; 3 P0 stopped on a check); 4 a gate refused before P0;
# 5 the checkout changed while P0 ran (nothing here changes it — reported, never repaired). The exit is ALWAYS the log's
# last line, "RUNNER EXIT <n>": the hand-over pipes into tee, whose status the shell reports instead.

set -u
umask 077
trap 'printf "\n  RUNNER EXIT %s\n" "$?"' EXIT
trap 'exit 129' HUP; trap 'exit 130' INT; trap 'exit 143' TERM
REPO="/opt/dishnet"
WORK="/root/dnb-bank-recon"
BRANCH="claude/study-this-jhe2eg"
PLUGIN="dishnet-hybrid-sudan"
P0_COMMIT="5954ede513f46e6ddcdb061fa60ce63f65cdacf5"
TOOL_SHA="0143dae93cf34ba414a27f3c15af4946c7b224d24ffffd250eb6e8e27f08b422"
WRAP_SHA="9a96e7fe1cd325294d9e112e12dd325663c1f2c399c2c782dd2009dcc158937e"
CONTAINER="ucrm"
IN_CONTAINER="/data/ucrm/data/plugins/$PLUGIN"
EXPECTED_DATA="/data/ucrm/data/plugins/.$PLUGIN-data"
EXPECTED_OWNER="1000:1000"

ADJ=()
for a in "$@"; do
  case "$a" in
    --adjustment-id=[0-9]*) v="${a#--adjustment-id=}"
      case "$v" in *[!0-9]*) echo "bad --adjustment-id"; exit 4 ;; esac
      [ "${#v}" -le 10 ] || { echo "bad --adjustment-id (at most 10 digits)"; exit 4; }
      ADJ=("--adjustment-id=$v") ;;
    *) echo "unknown option: $a (only --adjustment-id=N)"; exit 4 ;;
  esac
done
gate() { printf '\n  GATE REFUSED: %s\n\n  P0 was NOT run (exit 4). Nothing was changed. Send this log file.\n' "$*"; exit 4; }
ok()   { printf '  %-15s %s\n' "$1" "$2"; }
g()    { git --no-optional-locks -C "$REPO" "$@"; }   # never refreshes or locks the index: a plain `git status` may rewrite it
dx()   { docker exec "$@" </dev/null; }

printf '\n== bank-recon P0, gated run — %s ==\n\n' "$(date -u +%Y%m%dT%H%M%SZ)"
[ "$(id -u)" = "0" ] || gate "run this as root"
[ "${UCRM_CONTAINER:-$CONTAINER}" = "$CONTAINER" ] || gate "UCRM_CONTAINER is set to '$UCRM_CONTAINER' — P0 would read that container, not the $CONTAINER checked here; unset it"
command -v git >/dev/null 2>&1 && command -v docker >/dev/null 2>&1 && command -v sha256sum >/dev/null 2>&1 || gate "git, docker or sha256sum is missing"
[ -d "$WORK" ] && [ "$(stat -c '%u %a' "$WORK")" = "0 700" ] || gate "$WORK must exist, owned by root, mode 700 (the hand-over makes it with umask 077) — the log is confidential"

# ── G1: the checkout ───────────────────────────────────────────────────────────────────────────────────────────────
[ "$(g rev-parse --is-inside-work-tree 2>/dev/null)" = "true" ] && [ "$(g rev-parse --show-toplevel 2>/dev/null)" = "$(realpath "$REPO" 2>/dev/null)" ] || gate "$REPO is not the top of a git checkout"
g symbolic-ref -q HEAD >/dev/null || gate "the checkout is on a detached HEAD — not the state the records describe"
GD="$(g rev-parse --absolute-git-dir)"
for f in MERGE_HEAD CHERRY_PICK_HEAD REVERT_HEAD BISECT_LOG rebase-merge rebase-apply; do
  [ ! -e "$GD/$f" ] || gate "the checkout has an operation in progress ($f) — not the state the records describe"
done
fingerprint() {   # everything a checkout update could change, as one line
  printf '%s|%s|%s|%s|%s|%s\n' \
    "$(g rev-parse HEAD 2>/dev/null)" \
    "$(g symbolic-ref -q HEAD 2>/dev/null || echo detached)" \
    "$(g for-each-ref --format='%(refname) %(objectname)' refs/heads refs/tags | sha256sum | cut -c1-16)" \
    "$(g stash list 2>/dev/null | sha256sum | cut -c1-16)" \
    "$(sha256sum "$GD/index" 2>/dev/null | cut -c1-16)" \
    "$(g -c core.quotePath=false status --porcelain=v1 --untracked-files=all --ignored=matching 2>/dev/null | sha256sum | cut -c1-16)"
}
BEFORE="$(fingerprint)"
DIRTY="$(g -c core.quotePath=false status --porcelain=v1 --untracked-files=no)"
UNTR_PLUGIN="$(g -c core.quotePath=false ls-files --others -- "$PLUGIN" ":(exclude)$PLUGIN/data")"   # ignored files too, as the deploy scripts count
UNTR_ALL="$(g -c core.quotePath=false ls-files --others --exclude-standard)"
n() { printf '%s' "$1" | grep -c . || true; }
ok "checkout" "$REPO at $(g rev-parse --short HEAD) on $(g symbolic-ref -q --short HEAD || echo 'a detached HEAD')"
ok "" "tracked files modified or staged: $(n "$DIRTY"); not tracked inside $PLUGIN/ (data/ aside, ignored included): $(n "$UNTR_PLUGIN"); untracked elsewhere: $(n "$(printf '%s\n' "$UNTR_ALL" | grep -v "^$PLUGIN/")")"
if [ -n "$UNTR_ALL" ] && [ -z "$UNTR_PLUGIN" ]; then
  printf '%s\n' "$UNTR_ALL" | head -5 | sed 's/^/                    untracked, not read by P0: /'
fi
[ -z "$DIRTY" ] || { printf '%s\n' "$DIRTY" | head -10 | sed 's/^/                    /'; gate "tracked files are modified or staged in the checkout — unrelated work; P0 does not touch it, but this state is not the expected one"; }
[ -z "$UNTR_PLUGIN" ] || { printf '%s\n' "$UNTR_PLUGIN" | head -10 | sed 's/^/                    /'; gate "files git does not track inside $PLUGIN/ — the 09 Oct record has none"; }
ok "" "the working tree, the index, HEAD and every local branch are only read here; nothing below checks anything out"

# ── G2: the reviewed P0, out of its commit ───────────────────────────────────────────────────────────────────────────
g cat-file -e "$P0_COMMIT^{commit}" 2>/dev/null || gate "the checkout does not hold the reviewed commit $P0_COMMIT — the fetch in the hand-over did not bring it"
TIP="$(g rev-parse -q --verify "refs/remotes/origin/$BRANCH" 2>/dev/null || true)"
[ -n "$TIP" ] || gate "there is no origin/$BRANCH after the fetch"
g merge-base --is-ancestor "$P0_COMMIT" "$TIP" || gate "origin/$BRANCH (${TIP:0:7}) does not contain $P0_COMMIT — the branch was rewritten"
ok "reviewed P0" "commit $P0_COMMIT; origin/$BRANCH at ${TIP:0:7}, $(g rev-list --count "$P0_COMMIT..$TIP") commit(s) after it"
SRC="$(mktemp -d "$WORK/src-${P0_COMMIT:0:7}-XXXXXX")" || gate "could not make a folder under $WORK"
mkdir "$SRC/scripts"
g cat-file blob "$P0_COMMIT:scripts/bank-recon-p0.sh"  > "$SRC/scripts/bank-recon-p0.sh"  2>/dev/null || gate "the commit has no scripts/bank-recon-p0.sh"
g cat-file blob "$P0_COMMIT:scripts/bank-recon-p0.php" > "$SRC/scripts/bank-recon-p0.php" 2>/dev/null || gate "the commit has no scripts/bank-recon-p0.php"
T="$(sha256sum "$SRC/scripts/bank-recon-p0.php" | cut -c1-64)"; W="$(sha256sum "$SRC/scripts/bank-recon-p0.sh" | cut -c1-64)"
[ "$T" = "$TOOL_SHA" ] || gate "the tool in $P0_COMMIT has sha256 $T, not the reviewed $TOOL_SHA"
[ "$W" = "$WRAP_SHA" ] || gate "the command in $P0_COMMIT has sha256 $W, not the reviewed $WRAP_SHA"
[ "$(grep -c "^TOOL_SHA=\"$TOOL_SHA\"\$" "$SRC/scripts/bank-recon-p0.sh")" = 1 ] || gate "the command does not pin the reviewed tool sha256"
ok "" "taken out of the commit into $SRC: tool sha256 ${T:0:16}… = reviewed; command sha256 ${W:0:16}… = reviewed; the command pins the same tool"
for f in bank-recon-p0.sh bank-recon-p0.php; do
  if [ -f "$REPO/scripts/$f" ]; then
    cmp -s "$REPO/scripts/$f" "$SRC/scripts/$f" && s="the same" || s="DIFFERENT (not used)"
  else s="absent (not needed)"; fi
  ok "" "the checkout's own scripts/$f: $s"
done

# ── G3: the book ────────────────────────────────────────────────────────────────────────────────────────────────────
[ "$(docker inspect -f '{{.State.Running}}' "$CONTAINER" 2>/dev/null)" = "true" ] || gate "the $CONTAINER container is not running"
PDD="$(dx "$CONTAINER" php -r '$u=@json_decode((string)@file_get_contents($argv[1]),true); echo rtrim((string)($u["pluginDataDir"]??""),"/");' "$IN_CONTAINER/ucrm.json" 2>/dev/null)" \
  || gate "the container's ucrm.json could not be read — the data folder cannot be established"
[ -n "$PDD" ] || PDD="$EXPECTED_DATA"   # ucrm.json names none (09 Oct): the folder beside the plugin, as the command chooses
[ "${PDD%/}" = "$EXPECTED_DATA" ] || gate "the data folder would be $PDD, not $EXPECTED_DATA — an unexpected database path"
dx "$CONTAINER" test -f "$EXPECTED_DATA/plugin.sqlite3" 2>/dev/null || gate "no plugin.sqlite3 in $EXPECTED_DATA"
OWN="$(dx "$CONTAINER" stat -c '%u:%g' "$EXPECTED_DATA/plugin.sqlite3" 2>/dev/null || true)"
[ "$OWN" = "$EXPECTED_OWNER" ] || gate "the database's owner is ${OWN:-unreadable}, not $EXPECTED_OWNER"
ok "book" "$EXPECTED_DATA/plugin.sqlite3, owner $OWN — as expected"

# ── G4: P0, once ────────────────────────────────────────────────────────────────────────────────────────────────────
printf '\n  ── P0 begins (it runs once; whatever it ends with is reported, not retried) ──\n'
env -u UCRM_CONTAINER -u BASH_ENV -u ENV GIT_CEILING_DIRECTORIES="$WORK" bash "$SRC/scripts/bank-recon-p0.sh" ${ADJ[@]+"${ADJ[@]}"}
rc=$?
printf '  ── P0 ended: exit %s ──\n\n' "$rc"

# ── G5: the checkout, untouched ─────────────────────────────────────────────────────────────────────────────────────
AFTER="$(fingerprint)"
if [ "$AFTER" = "$BEFORE" ]; then
  ok "checkout" "unchanged: HEAD, branch, local branches and tags, stash, index and every file's status are as before"
else
  ok "checkout" "CHANGED while P0 ran (before $BEFORE, after $AFTER) — not by this run, which only reads it; nothing repaired"
fi
echo
if [ "$AFTER" != "$BEFORE" ]; then
  echo "  RESULT: THE CHECKOUT CHANGED while P0 ran (exit 5); P0 itself ended with exit $rc. Do not use these figures until the"
  echo "  change is explained. Nothing was repaired. Send this log file."
  exit 5
fi
case "$rc" in
  0) echo "  RESULT: P0 DONE (exit 0). Send this LOG FILE back; keep it confidential: it holds the book's totals." ;;
  3) echo "  RESULT: P0 STOPPED (exit 3) — it saw the plugin write during the reading, or one of its checks failed (the reason is"
     echo "  above). As instructed, it was NOT retried. Send this log file; a further run waits for your word." ;;
  2) echo "  RESULT: P0 REFUSED (exit 2) — the reason is above. NOT retried. Send this log file." ;;
  1) echo "  RESULT: the P0 command STOPPED before P0 ran (exit 1) — the reason is above. Send this log file." ;;
  *) echo "  RESULT: P0 did not finish (exit $rc). NOT retried. Send this log file." ;;
esac
exit "$rc"
