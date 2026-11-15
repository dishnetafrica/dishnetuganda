#!/usr/bin/env bash
#
# run.sh — the whole harness for scripts/enquiry-followup-report.{php,sh}, as root, from any folder:
#
#   1. the book (build.php, from 994d660's tree by git archive, at a fixed NOW so every count is exact);
#   2. test.php — every line the report prints, in five runs (R2, R1, R3, R4, R5), privacy, read-only (with its
#      control); then weaken.php — the 44 weakened copies of the report in copies.php, each caught for its named reason;
#   3. test-wrapper.sh — the wrapper end to end against a fake docker, nine scenarios; then weaken-wrapper.sh — 18
#      weakened copies of the wrapper, each caught for its named reason.
#
# Exit 0 only when all four pass. It leaves nothing behind (each part removes its own folders).
set -uo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SRCREPO="$(cd "$HERE/../../.." && pwd)"
COMMIT=994d660628cc5b1b42bee648b44b58e389f44bf2
NOW="2026-11-15 12:00:00"
T="$(mktemp -d /tmp/efr-run-XXXXXX)"; trap 'rm -rf "$T"' EXIT
git -C "$SRCREPO" archive "$COMMIT" dishnet-hybrid-sudan | tar -x -C "$T" || { echo "the tree at $COMMIT could not be read"; exit 2; }
php "$HERE/build.php" "$T/dishnet-hybrid-sudan" "$T/b" "$NOW" || { echo "build.php failed"; exit 2; }
RC=0
REP="$SRCREPO/scripts/enquiry-followup-report.php"
step() { echo "== $1"; shift; "$@"; local r=$?; echo "== exit $r"; [ "$r" = 0 ] || RC=1; }
step "test.php"           php "$HERE/test.php" "$REP" "$T/b" "$NOW"
step "weaken.php"         php "$HERE/weaken.php" "$REP" "$T/b" "$NOW"
step "test-wrapper.sh"    bash "$HERE/test-wrapper.sh"
step "weaken-wrapper.sh"  bash "$HERE/weaken-wrapper.sh"
echo; [ "$RC" = 0 ] && echo "ALL PASSED" || echo "FAILURES — see above"
exit "$RC"
