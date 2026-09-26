#!/usr/bin/env bash
# Rehearse the Uganda invoice template (dishnet-hybrid-sudan/ucrm_pdf_templates/invoice_uganda) with real Twig,
# 2 and 3, before anyone pastes it into uCRM. See render.php for what is checked; docs/38 §7.2 for why.
#
# The baseline is "V1 invoice" as uCRM holds it — byte-identical to commit acb51d0, measured 26 Sep from the
# operator's export — so the sandbox permits only constructs uCRM has already accepted in this very template.
# Needs composer and the network (Packagist) the first time; TWIG_CACHE keeps the two installs between runs.
# The controls: two weakened copies must each fail — the old list-based PAID check, and a filter the baseline
# never used.  OPTIONAL: OTHER=<a template file> reports what that one shows (e.g. an export of today's template).
set -u
R="$(cd "$(dirname "$0")/../../.." && pwd)"; H="$R/scripts/harness/invoice-template"
TPL="${TPL:-$R/dishnet-hybrid-sudan/ucrm_pdf_templates/invoice_uganda/template.html.twig}"
CACHE="${TWIG_CACHE:-${TMPDIR:-/tmp}/dn-twig-cache}"; SB="$(mktemp -d)"; trap 'rm -rf "$SB"' EXIT
PASS=0; FAILN=0
check() { if [ "$1" = "$2" ]; then PASS=$((PASS+1)); echo "  ok   $3"; else FAILN=$((FAILN+1)); echo "  FAIL $3 (got '$1', want '$2')"; fi; }

git -C "$R" show acb51d0:dishnet-hybrid-sudan/ucrm_pdf_templates/invoice_uganda/template.html.twig > "$SB/v1.twig" \
  || { echo "cannot read the baseline (commit acb51d0) from git"; exit 2; }
for v in 3 2; do
  d="$CACHE/twig$v"
  if [ ! -f "$d/vendor/autoload.php" ]; then
    mkdir -p "$d" && (cd "$d" && COMPOSER_ALLOW_SUPERUSER=1 composer require --no-interaction --quiet "twig/twig:^$v") \
      || { echo "could not install Twig $v with composer"; exit 2; }
  fi
done

for v in 3 2; do
  echo "== the Uganda template, Twig $v =="
  php "$H/render.php" "$CACHE/twig$v" "$TPL" "$SB/v1.twig" ${OTHER:+"$OTHER"}; rc=$?
  check "$rc" "0" "the Uganda template passes every check on Twig $v"
done

echo "== controls: weakened copies must fail =="
python3 - "$TPL" "$SB/w1.twig" "$SB/w2.twig" <<'PY'
import sys, re
t = open(sys.argv[1]).read()
# w1: the list-based PAID check the digit rule replaced
a = re.search(r"\{% set is_paid = totals\.amountDue is not empty %\}\n\{% for d in .*?\{% endfor %\}", t, re.S)
assert a, "the digit rule is not where it is expected"
open(sys.argv[2], "w").write(t[:a.start()] + "{% set is_paid = totals.amountDue in ['UGX 0.00', 'UGX 0', 'USh 0.00', '0.00', '$0.00'] %}" + t[a.end():])
# w2: a filter the baseline never used
b = "{{ totals.amountDue }}</td>"
assert b in t
open(sys.argv[3], "w").write(t.replace(b, "{{ totals.amountDue|upper }}</td>", 1))
PY
out="$(php "$H/render.php" "$CACHE/twig3" "$SB/w1.twig" "$SB/v1.twig" 2>&1)"; rc=$?
check "$rc" "1" "a copy with the old list-based PAID check fails"
check "$(printf '%s\n' "$out" | grep -c 'FAIL "UGX 0" with a non-breaking space')" "1" "…on a zero written with a non-breaking space"
check "$(printf '%s\n' "$out" | grep -c 'FAIL "USh0"')" "1" "…and on \"USh0\""
out="$(php "$H/render.php" "$CACHE/twig3" "$SB/w2.twig" "$SB/v1.twig" 2>&1)"; rc=$?
check "$rc" "1" "a copy that uses a filter the baseline never used fails"
check "$(printf '%s\n' "$out" | grep -c 'is not allowed')" "1" "…refused by the sandbox, by name"

echo; echo "REHEARSAL: $PASS ok, $FAILN failed"
[ "$FAILN" = "0" ]
