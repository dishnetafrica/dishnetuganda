#!/usr/bin/env bash
# Rehearse the Uganda quotation template (dishnet-hybrid-sudan/ucrm_pdf_templates/quotation_uganda) with real Twig,
# 2 and 3, before anyone loads it in uCRM (docs/42 §3, §9). See render.php for what is checked.
#
# The baseline is the template as the repository gave it before 5.18.48 (commit 2bfad82), so the sandbox permits
# only constructs that template already used. Needs composer and the network (Packagist) the first time; TWIG_CACHE
# keeps the two installs between runs. The controls: three weakened copies must each fail — the all-taxes sentence
# never printed, the tax-lines branch dropped (all taxes claimed where uCRM itemises VAT), and a filter the baseline
# never used.
set -u
R="$(cd "$(dirname "$0")/../../.." && pwd)"; H="$R/scripts/harness/quotation-template"
TPL="${TPL:-$R/dishnet-hybrid-sudan/ucrm_pdf_templates/quotation_uganda/template.html.twig}"
CACHE="${TWIG_CACHE:-${TMPDIR:-/tmp}/dn-twig-cache}"; SB="$(mktemp -d)"; trap 'rm -rf "$SB"' EXIT
PASS=0; FAILN=0
check() { if [ "$1" = "$2" ]; then PASS=$((PASS+1)); echo "  ok   $3"; else FAILN=$((FAILN+1)); echo "  FAIL $3 (got '$1', want '$2')"; fi; }

git -C "$R" show 2bfad82:dishnet-hybrid-sudan/ucrm_pdf_templates/quotation_uganda/template.html.twig > "$SB/base.twig" \
  || { echo "cannot read the baseline (commit 2bfad82) from git"; exit 2; }
for v in 3 2; do
  d="$CACHE/twig$v"
  if [ ! -f "$d/vendor/autoload.php" ]; then
    mkdir -p "$d" && (cd "$d" && COMPOSER_ALLOW_SUPERUSER=1 composer require --no-interaction --quiet "twig/twig:^$v") \
      || { echo "could not install Twig $v with composer"; exit 2; }
  fi
done

for v in 3 2; do
  echo "== the Uganda quotation template, Twig $v =="
  php "$H/render.php" "$CACHE/twig$v" "$TPL" "$SB/base.twig"; rc=$?
  check "$rc" "0" "the Uganda quotation template passes every check on Twig $v"
done

echo "== controls: weakened copies must fail =="
python3 - "$TPL" "$SB/w1.twig" "$SB/w2.twig" "$SB/w3.twig" <<'PY'
import sys
t = open(sys.argv[1]).read()
a = "{% else %}All prices include all taxes &mdash; URA taxes and UCC charges are already in them. Nothing is added on top.{% endif %}"
assert t.count(a) == 1, "the all-taxes branch is not where it is expected"
open(sys.argv[2], "w").write(t.replace(a, "{% else %}No VAT is charged on this quotation.{% endif %}"))   # w1: never printed
b = "{% if totals.taxes|length > 0 %}VAT is itemised in the totals on page 1.{% else %}"
assert t.count(b) == 1, "the tax-lines branch is not where it is expected"
open(sys.argv[3], "w").write(t.replace(b, "{% if false %}{% else %}"))                   # w2: the tax-lines branch dropped
c = "{% if totals.taxes|length > 0 %}"
assert t.count(c) == 1, "the tax-lines test is not where it is expected"
open(sys.argv[4], "w").write(t.replace(c, "{% if totals.taxes|length > 0 and totals.total|lower %}"))   # w3: a new filter
PY
out="$(php "$H/render.php" "$CACHE/twig3" "$SB/w1.twig" "$SB/base.twig" 2>&1)"; rc=$?
check "$rc" "1" "a copy that never prints the all-taxes sentence fails"
check "$(printf '%s\n' "$out" | grep -c 'FAIL no kit on the quote: clause 2 says all prices include all taxes')" "1" "…on a quote without a kit"
out="$(php "$H/render.php" "$CACHE/twig3" "$SB/w2.twig" "$SB/base.twig" 2>&1)"; rc=$?
check "$rc" "1" "a copy that claims all taxes are inside where uCRM itemises VAT fails"
check "$(printf '%s\n' "$out" | grep -c 'FAIL a quote with uCRM tax lines: VAT is itemised, as before')" "1" "…on the quote with tax lines"
out="$(php "$H/render.php" "$CACHE/twig3" "$SB/w3.twig" "$SB/base.twig" 2>&1)"; rc=$?
check "$rc" "1" "a copy that uses a filter the baseline never used fails"
check "$(printf '%s\n' "$out" | grep -c 'is not allowed')" "1" "…refused by the sandbox, by name"

echo; echo "REHEARSAL: $PASS ok, $FAILN failed"
[ "$FAILN" = "0" ]
