#!/bin/bash
# Rehearses scripts/job-walkthrough.sh (docs/44 §16.25–§16.27): every scenario of rehearse.php on the script as
# committed, then weakened copies of the script, each of which the scenario that guards it must catch. Nothing leaves
# the host.
set -u
H="$(cd "$(dirname "$0")" && pwd)"; R="$(cd "$H/../../.." && pwd)"; S="$R/scripts/job-walkthrough.sh"
T="$(mktemp -d)"; trap 'rm -rf "$T"' EXIT
pass=0; fail=0

echo "== the script as committed =="
if php "$H/rehearse.php" "$S"; then pass=$((pass+1)); else fail=$((fail+1)); echo "  ✗ the committed script failed its rehearsal"; fi

# A copy with one check weakened; $sc, the scenarios that guard it, must fail on it.
mutant() {
  local name="$1" sc="$2" from="$3" to="$4" m="$T/$1.sh"
  cp "$S" "$m"
  if ! grep -qF -- "$from" "$m"; then echo "  ✗ $name: the text to weaken is not in the script"; fail=$((fail+1)); return; fi
  FROM="$from" TO="$to" perl -0pi -e 's/\Q$ENV{FROM}\E/$ENV{TO}/g' "$m"
  if grep -qF -- "$from" "$m" || cmp -s "$S" "$m"; then echo "  ✗ $name: the copy was not weakened"; fail=$((fail+1)); return; fi
  if php "$H/rehearse.php" "$m" "$sc" > "$T/$name.out" 2>&1; then
    echo "  ✗ $name: NOT caught by $sc"; fail=$((fail+1)); sed 's/^/      /' "$T/$name.out" | tail -5
  else
    echo "  ok   caught: $name ($(grep -c '^  FAIL' "$T/$name.out") failing in $sc)"; pass=$((pass+1))
  fi
}
echo ""; echo "== weakened copies =="
mutant X1-no-run-mark-check   S6 'if (!$mine) {' 'if (false) {'
mutant X2-no-uganda-check     S3 '$say(StaffJobsGate::applies($config, $dataDir), ' '$say(true, '
mutant X3-no-old-code-check   S5 'echo $old ? "OLD-CODE\n" :' 'echo false ? "OLD-CODE\n" :'
mutant X4-no-masking          S1 "return (string)preg_replace(['/[^\\s<>()\"]+@" "return \$s; (string)preg_replace(['/[^\\s<>()\"]+@"
mutant X5-unverified-link     S4 '$rows = StaffDirectory::byUcrmUser((array)($store->load('"'"'retailers.json'"'"') ?? []), $tech);' \
       '$rows = array_values(array_filter((array)($store->load('"'"'retailers.json'"'"') ?? []), function ($r) use ($tech) { return (int)($r['"'"'ucrm_user_id'"'"'] ?? 0) === $tech; }));'
mutant X6-any-answer-goes-on  S2 '*) say "  Stopped. Nothing more is done."; finish; exit 0 ;;' '*) return 0 ;;'
mutant X7-step4-keeps-the-time S1 '"assignee=none" "date=none"' '"assignee=none"'
mutant X8-step5-not-gated     S8 'if [ "$TOOK" != 1 ]; then' 'if false; then'
mutant X9-claim-ignored       S10 '} elseif ($claim) {' '} elseif (false) {'
mutant X10-no-second-try      S9 'if [ "$ACC" = NONE-PROGRESS ] && ask' 'if false && ask'
mutant X11-facts-unmasked     S9 'echo "LINE {$at}  ", wt_mask($m), "\n";' 'echo "LINE {$at}  ", $m, "\n";'
mutant X12-replay-no-handler  S11b 'set_error_handler(function ($no, $str, $file, $line) use ($level) {' '(function ($no, $str, $file, $line) use ($level) {'
mutant X13-every-warning-stops S11b "echo 'WARN ', (\$level & (int)\$no) ? 'stops' : 'noted'," "echo 'WARN ', true ? 'stops' : 'noted',"
mutant X14-split-not-reported S11c 'if ($tApi === '"'"'none'"'"' && $tHook !== '"'"'none'"'"') echo' 'if (false) echo'
mutant X15-query-unmasked     S11b 'wt_mask(wt_noquery((string)$str))' 'wt_mask((string)$str)'
mutant X16-fatal-unreported   S11e 'in_array($e['"'"'type'"'"'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)' 'false'
mutant X17-error-verdict-gone S11d 'elif [ "$ne" -gt 0 ]; then' 'elif false; then'

echo ""; echo "rehearsal: $pass passed, $fail failed"
[ "$fail" = 0 ]
