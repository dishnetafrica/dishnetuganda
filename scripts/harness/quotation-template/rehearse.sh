#!/usr/bin/env bash
# Rehearse the Uganda quotation template (dishnet-hybrid-sudan/ucrm_pdf_templates/quotation_uganda) with real Twig,
# 2 and 3, before anyone pastes its clause 2 into uCRM (docs/42 §3). See render.php for what is checked.
#
# The baseline is the template as the repository gave it before 5.18.48 (commit 2bfad82), so the sandbox permits
# only constructs that template already used. Needs composer and the network (Packagist) the first time; TWIG_CACHE
# keeps the two installs between runs. The controls: three weakened copies must each fail — the kit sentence never
# printed, "Starlink" alone taken for a kit, and a filter the baseline never used.
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
a = "{% elseif has_kit %}"
assert t.count(a) == 1, "the kit branch is not where it is expected"
open(sys.argv[2], "w").write(t.replace(a, "{% elseif false %}"))                     # w1: the kit sentence never printed
b = "and ('Kit' in item.label or 'kit' in item.label or 'KIT' in item.label)"
assert t.count(b) == 1, "the kit test is not where it is expected"
open(sys.argv[3], "w").write(t.replace(b, ""))                                       # w2: any Starlink item taken for a kit
c = "{% for item in items %}{% if ('Starlink' in item.label"
assert t.count(c) == 1, "the kit loop is not where it is expected"
open(sys.argv[4], "w").write(t.replace(c, "{% for item in items %}{% if ('Starlink' in item.label|lower"))   # w3: a new filter
PY
out="$(php "$H/render.php" "$CACHE/twig3" "$SB/w1.twig" "$SB/base.twig" 2>&1)"; rc=$?
check "$rc" "1" "a copy that never prints the kit sentence fails"
check "$(printf '%s\n' "$out" | grep -c 'FAIL a Starlink kit: clause 2 says what the kit price includes')" "1" "…on the kit quote"
out="$(php "$H/render.php" "$CACHE/twig3" "$SB/w2.twig" "$SB/base.twig" 2>&1)"; rc=$?
check "$rc" "1" "a copy that takes any Starlink item for a kit fails"
check "$(printf '%s\n' "$out" | grep -c 'FAIL a Starlink service but no kit: clause 2 is unchanged')" "1" "…on a quote for the service alone"
out="$(php "$H/render.php" "$CACHE/twig3" "$SB/w3.twig" "$SB/base.twig" 2>&1)"; rc=$?
check "$rc" "1" "a copy that uses a filter the baseline never used fails"
check "$(printf '%s\n' "$out" | grep -c 'is not allowed')" "1" "…refused by the sandbox, by name"

echo; echo "REHEARSAL: $PASS ok, $FAILN failed"
[ "$FAILN" = "0" ]
