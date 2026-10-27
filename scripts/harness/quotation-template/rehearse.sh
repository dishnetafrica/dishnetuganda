#!/usr/bin/env bash
# Rehearse the Uganda quotation template (dishnet-hybrid-sudan/ucrm_pdf_templates/quotation_uganda) with real Twig,
# 2 and 3, before anyone loads it in uCRM (docs/42 §3, §9). See render.php for what is checked.
#
# The baseline is the template as the repository gave it before 5.18.48 (commit 2bfad82), so the sandbox permits
# only constructs that template already used. Needs composer and the network (Packagist) the first time; TWIG_CACHE
# keeps the two installs between runs. The controls: twenty-one weakened copies must each fail, each by the check that
# guards it — the all-taxes sentence never printed, the tax-lines branch dropped, a filter the baseline never used, and
# eighteen on the Service Agreement and page 2 (docs/67 §L): its 'takes away nothing' sentence removed, South Sudan's
# termination fee, the agreement removed, a fixed term, a clause deleted (only the pins see it), the notice, outage and
# return periods off build.py's (agreement and page 2), the site cell's stray comma back, a 24/7 claim, a UCC
# authorisation claim, the Flex row removed, the party named as the contact instead of the company, clause 1's Flex
# sentence removed, a Terms of Service section misnumbered, the no-address branch changed, and an edit no sample
# renders (only the source pin sees it).
set -u
R="$(cd "$(dirname "$0")/../../.." && pwd)"; H="$R/scripts/harness/quotation-template"
TPL="${TPL:-$R/dishnet-hybrid-sudan/ucrm_pdf_templates/quotation_uganda/template.html.twig}"
CACHE="${TWIG_CACHE:-${TMPDIR:-/tmp}/dn-twig-cache}"; SB="$(mktemp -d)"; trap 'rm -rf "$SB"' EXIT
NS=18      # sample quotes in render.php ($QUOTES); asserted below from the run itself
NFLEX=3    # of them, Flex quotations (FLEX_SAMPLES)
PASS=0; FAILN=0
check() { if [ "$1" = "$2" ]; then PASS=$((PASS+1)); echo "  ok   $3"; else FAILN=$((FAILN+1)); echo "  FAIL $3 (got '$1', want '$2')"; fi; }
count() { printf '%s\n' "$out" | grep -c -- "$1"; }

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
  out="$(php "$H/render.php" "$CACHE/twig$v" "$TPL" "$SB/base.twig" 2>&1)"; rc=$?
  printf '%s\n' "$out"
  check "$rc" "0" "the Uganda quotation template passes every check on Twig $v"
  check "$(count 'ok   .*: the Service Agreement is there, once, between its markers')" "$NS" "…on all $NS sample quotes"
done

echo "== controls: weakened copies must fail =="
python3 - "$TPL" "$SB" <<'PY' || { echo "could not write the weakened copies (an anchor moved): nothing was proved"; exit 2; }
import re, sys
t = open(sys.argv[1], encoding="utf-8").read(); sb = sys.argv[2]
def w(n, anchor, new):
    assert t.count(anchor) == 1, f"w{n}: its anchor is not where it is expected ({t.count(anchor)} times)"
    out = t.replace(anchor, new)
    assert out != t and out.count(new) >= 1, f"w{n}: the copy did not change"
    open(f"{sb}/w{n}.twig", "w", encoding="utf-8").write(out)
a = "{% else %}All prices include all taxes &mdash; URA taxes and UCC charges are already in them. Nothing is added on top.{% endif %}"
w(1, a, "{% else %}No VAT is charged on this quotation.{% endif %}")                          # never printed
b = "{% if totals.taxes|length > 0 %}VAT is itemised in the totals on page 1.{% else %}"
w(2, b, "{% if false %}{% else %}")                                                          # the tax-lines branch dropped
c = "{% if totals.taxes|length > 0 %}"
w(3, c, "{% if totals.taxes|length > 0 and totals.total|lower %}")                            # a new filter
d = "<b>Nothing in this Agreement takes away or reduces a right the CUSTOMER has under those terms and policies or under the law of Uganda:</b> where this Agreement and any of them deal with the same matter, the provision more favourable to the CUSTOMER applies."
w(4, d, "")                                                                                  # the sentence removed
e = "with no cancellation fee. The service continues to the end of the month already paid for;"
w(5, e, e + " ending early, the CUSTOMER pays a Termination Fee equal to the remaining months' fees;")   # South Sudan's fee
S, E = "  <!-- ═══ SERVICE AGREEMENT — UGANDA (pages 3–4) — start", "<!-- ═══ SERVICE AGREEMENT — UGANDA (pages 3–4) — end ═══ -->\n\n"
f0, f1 = t.find(S), t.find(E)
assert 0 < f0 < f1 and t.count(S) == 1 and t.count(E) == 1, "the agreement's markers are not where they are expected"
w(6, t[f0:f1 + len(E)], "")                                                                  # the agreement removed
g = "monthly, with no fixed term and no cancellation fee; it continues month to month until cancelled (clause 6)"
w(7, g, "12 months from the service start date")                                             # a fixed term
h = re.findall(r'        <div style="padding:2px 0;"><b style="color:#141414;">13\. Transfer\.</b>.*?</div>\n', t)
assert len(h) == 1, "clause 13 is not where it is expected"
w(8, h[0], "")                                                                               # a clause deleted
w(9, "at least 30 days&rsquo; notice", "at least 14 days&rsquo; notice")                     # agreement: a notice build.py does not give
w(10, "Prices may change with 30 days' notice.", "Prices may change with 14 days' notice.")  # page 2: the same
w(11, "more than 3 days in a row", "more than 4 days in a row")                              # agreement: an outage figure build.py does not give
w(12, "may be returned within 7 days of delivery", "may be returned within 3 days of delivery")   # page 2: a return period build.py does not give
i = "{% if client.city %}{% if client.street1 or client.street2 %}, {% endif %}{{ client.city }}{% endif %}"
w(13, i, "{% if client.city %}, {{ client.city }}{% endif %}")                               # the stray comma back
k = "Work outside the quotation is quoted before it begins."
w(14, k, k + " DISHNET monitors the service 24/7.")                                          # a 24/7 claim
o = "<b>{{ organization.name }}</b>"
w(15, o, o + ", authorised by the Uganda Communications Commission,")                       # a UCC authorisation claim
fx = re.findall(r'    \{% if is_flex %\}<tr>.*?</tr>\{% endif %\}\n', t)
assert len(fx) == 1, "the Flex row is not where it is expected"
w(16, fx[0], "")                                                                             # the Flex row removed
pb = "{% if client.companyName and client.companyName != client.name %}<b>{{ client.companyName }}</b>, acting through {{ client.name }}{% elseif client.companyName %}<b>{{ client.companyName }}</b>{% else %}<b>{{ client.name }}</b>{% endif %}"
w(17, pb, "<b>{{ client.name }}</b>")                                                        # the contact named instead of the company
fg = " No Flex package is in force until its agreement is signed by both parties, and that agreement does not take away the CUSTOMER&rsquo;s right to cancel within 7 days of receiving the kit (section 6 of the Returns, Refunds and Cancellations page) or any right the law gives."
w(18, fg, "")                                                                                # clause 1's Flex sentence removed
w(19, "as section 11 of the Terms of Service sets out", "as section 12 of the Terms of Service sets out")   # a section misnumbered
w(20, "{% else %}To be confirmed in writing before installation{% endif %}", "{% else %}Kampala{% endif %}")   # the no-address branch changed
w(21, "  {% set is_flex = false %}", "  <!-- an edit that no sample renders -->\n  {% set is_flex = false %}")   # only the source pin sees it
PY
run() { out="$(php "$H/render.php" "$CACHE/twig3" "$SB/w$1.twig" "$SB/base.twig" 2>&1)"; rc=$?; check "$rc" "1" "$2"; }
run 1 "a copy that never prints the all-taxes sentence fails"
check "$(count 'FAIL no kit on the quote: clause 2 says all prices include all taxes')" "1" "…on a quote without a kit"
run 2 "a copy that claims all taxes are inside where uCRM itemises VAT fails"
check "$(count 'FAIL a quote with uCRM tax lines: VAT is itemised, as before')" "1" "…on the quote with tax lines"
run 3 "a copy that uses a filter the baseline never used fails"
check "$(count 'is not allowed')" "1" "…refused by the sandbox, by name"
run 4 "an agreement without its 'takes away nothing' sentence fails"
check "$(count 'FAIL .*: …it takes away nothing the Terms of Service, the policies and the law give')" "$NS" "…on every sample quote"
run 5 "an agreement with South Sudan's termination fee fails"
check "$(count "FAIL .*: …and none of the terms or claims Uganda's policies do not allow")" "$NS" "…on every sample quote"
run 6 "a template without the agreement fails"
check "$(count 'FAIL .*: the Service Agreement is there, once, between its markers')" "$NS" "…on every sample quote"
run 7 "an agreement with a fixed term fails"
check "$(count 'FAIL .*: …monthly, with no fixed term and no cancellation fee, for a DishNet service plan only')" "$NS" "…on every sample quote"
run 8 "an agreement with a clause deleted fails"
check "$(count 'FAIL .*: …its text is the reviewed one (pinned)')" "$NS" "…on every sample quote, by its pin"
check "$(count "FAIL the agreement's source is the reviewed text (pinned)")" "1" "…and by the source pin"
check "$(count 'FAIL')" "$((NS + 1))" "…and by nothing else: only the pins see a deleted clause"
run 9 "an agreement with a notice period build.py does not give fails"
check "$(count "FAIL .*: …the notice and outage numbers are build.py's")" "$NS" "…on every sample quote"
run 10 "a page 2 with a notice period build.py does not give fails"
check "$(count "FAIL .*: page 2's notice, return and warranty periods are build.py's")" "$NS" "…on every sample quote"
run 11 "an agreement with an outage figure build.py does not give fails"
check "$(count "FAIL .*: …the notice and outage numbers are build.py's")" "$NS" "…on every sample quote"
run 12 "a page 2 with a return period build.py does not give fails"
check "$(count "FAIL .*: page 2's notice, return and warranty periods are build.py's")" "$NS" "…on every sample quote"
run 13 "an agreement whose site cell can start with a comma fails"
check "$(count 'FAIL .*: …the site address has no stray comma')" "1" "…on the city-only sample, by name"
check "$(count 'FAIL business: a city alone prints as the site')" "1" "…and by its exact site"
run 14 "an agreement that claims 24/7 monitoring fails"
check "$(count "FAIL .*: …and none of the terms or claims Uganda's policies do not allow")" "$NS" "…on every sample quote"
run 15 "an agreement that claims a UCC authorisation fails"
check "$(count 'FAIL .*: …and no UCC authorisation or licence claim')" "$NS" "…on every sample quote"
run 16 "an agreement without the Flex row fails"
check "$(count 'FAIL .*: …a Flex package is quoted only, exactly when the quotation has one')" "$NFLEX" "…on each Flex quotation"
run 17 "an agreement that names the contact instead of the company fails"
check "$(count 'FAIL .*: …it names the customer as the party')" "1" "…on the business whose contact differs"
run 18 "an agreement without clause 1's Flex sentence fails"
check "$(count 'FAIL .*: …no Flex package is in force before its agreement is signed, and the 7-day cancellation stays')" "$NS" "…on every sample quote"
run 19 "an agreement citing a Terms of Service section the harness does not map fails"
check "$(count 'FAIL .*: …the agreement and page 2 cite only Terms of Service sections the harness maps')" "$NS" "…on every sample quote"
run 20 "an agreement whose no-address branch changed fails"
check "$(count 'FAIL no_address: no street or city prints the site as to be confirmed')" "1" "…on the no-address sample"
run 21 "an agreement edited where no sample renders fails"
check "$(count "FAIL the agreement's source is the reviewed text (pinned)")" "1" "…by the source pin"
check "$(count 'FAIL')" "1" "…and by nothing else"

echo; echo "REHEARSAL: $PASS ok, $FAILN failed"
[ "$FAILN" = "0" ]
