#!/usr/bin/env bash
# weakened.sh PLUGIN_ROOT — TEST ONLY: each weakened copy of the console script (weakened.py) must FAIL the test, on the
# check that names its fault. Prints one line per copy; exit 0 only when every copy was caught there.
set -u
HERE="$(cd "$(dirname "$0")" && pwd)"; REPO="$(cd "$HERE/../../.." && pwd)"; ROOT="${1:?the plugin release checkout}"
OUT="$(mktemp -d)"; BAD=0
python3 -I "$HERE/weakened.py" "$REPO/scripts/console/sales-number-on.console.js" "$OUT" > "$OUT/list.tsv" || { echo "weakened.sh: an anchor was not found once"; exit 2; }
while IFS=$'\t' read -r NAME MUST; do
  node --check "$OUT/$NAME.js" || { echo "NOT A SCRIPT  $NAME"; BAD=1; continue; }
  bash "$HERE/run.sh" "$ROOT" block "$OUT/$NAME.js" > "$OUT/$NAME.log" 2>&1; RC=$?
  if [ "$RC" != 0 ] && grep -F "  FAIL $MUST" "$OUT/$NAME.log" >/dev/null; then echo "caught      $NAME — $(grep -c '^  FAIL' "$OUT/$NAME.log") check(s) failed, \"$MUST…\" among them"
  else echo "NOT CAUGHT  $NAME (exit $RC; expected a FAIL of \"$MUST…\"; log $OUT/$NAME.log)"; BAD=1; fi
done < "$OUT/list.tsv"
[ "$BAD" = 0 ] && echo "every weakened copy was caught ($(grep -c . "$OUT/list.tsv"))"
exit "$BAD"
