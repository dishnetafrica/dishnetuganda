<?php
// render.php — render the Uganda quotation template with real Twig under a sandbox and check clause 2 (docs/42 §3, §9).
//   php render.php <twig vendor dir> <template under test> <baseline>
// The sandbox allows exactly the tags, filters and functions the baseline uses. The baseline is the template as the
// repository gave it to uCRM before 5.18.48 (commit 2bfad82), so a template that renders here uses nothing that
// template did not already use. Output is a list of checks; exit 1 on any failure.
// The Service Agreement (pages 3–4, docs/67 §L) is checked on its own, then cut out at its two markers before the
// rest of the page is compared with the baseline's.
require $argv[1] . '/vendor/autoload.php';

// 5.18.49: every quotation without uCRM tax lines says this — the operator's sentence of 27 Sep 2026 (docs/42 §9).
const ALL_TAXES    = 'All prices include all taxes &mdash; URA taxes and UCC charges are already in them. '
                   . 'Nothing is added on top.';
const NO_VAT       = 'No VAT is charged on this quotation.';
const VAT_ITEMISED = 'VAT is itemised in the totals on page 1.';
// The Service Agreement's markers, and what it must and must not say (docs/67 §L).
const AGR_START = '<!-- ═══ SERVICE AGREEMENT — UGANDA (pages 3–4) — start ═══ -->';
const AGR_END   = '<!-- ═══ SERVICE AGREEMENT — UGANDA (pages 3–4) — end ═══ -->';
const AGR_KEEPS = 'Nothing in this Agreement takes away or reduces a right the CUSTOMER has under those terms and policies or under the '
                 . 'law of Uganda:</b> where this Agreement and any of them deal with the same matter, the provision more favourable to '
                 . 'the CUSTOMER applies.';
const AGR_TERM  = 'For a DishNet service plan on this quotation: monthly, with no fixed term and no cancellation fee; it continues month '
                 . 'to month until cancelled (clause 6). Equipment and installation alone carry no recurring charge from DISHNET.';
const AGR_FLEX  = 'This quotation includes a Flex package.';
// South Sudan's terms that Uganda's published policies do not allow, claims the pages never make (docs/67 §J), words the
// PDF doctor reads as South Sudan content, and clauses the review removed (docs/67 §L).
const AGR_NEVER = ['termination fee', 'arbitration', 'merchantability', 'fitness for a particular purpose', 'sole and entire remedy',
                   'south sudan', 'juba', '+211', 'dishnetafrica', 'ucc authorised', 'ucc-authorised', 'authorised starlink installer',
                   'licensed by', 'licence', 'license', 'security deposit is needed', 'interest', 'entire agreement', 'whole agreement',
                   'confidential'];
// The agreement as reviewed, pinned per sample quote: sha256 of the rendered agreement, comments removed. A change to its
// text must change these deliberately (docs/67 §L).
const AGR_SHA = [];
/** The numbers the agreement quotes, from their single source: PARAMS in the website's policies/build.py. */
function params(): array {
    $src = (string)@file_get_contents(dirname(__DIR__, 3) . '/dishnet-web-uganda/policies/build.py');
    $o = [];
    foreach (['PRICE_NOTICE', 'OUTAGE'] as $k) if (preg_match("/'$k':\\s*'([^']+)'/", $src, $m) === 1) $o[$k] = $m[1];
    return $o;
}

// Withdrawn from the end of clause 2 on 12 Oct 2026: the website says all taxes are included and nothing is added.
const NEW_TAX      = ' Any new or increased government tax, levy or regulatory fee introduced after acceptance may be '
                   . 'passed on at cost.';

function item(string $label, string $price, string $type = 'product'): array {
    return ['label' => $label, 'type' => $type, 'unit' => '', 'quantity' => '1', 'price' => $price, 'total' => $price];
}
function ctx(array $items, array $taxes = [], array $client = []): array {
    return [
        'organization' => ['name' => 'DishNet Africa Limited', 'street1' => 'Acacia Mall, 4th floor', 'street2' => '', 'city' => 'Kampala', 'state' => '',
                           'phone' => '+256 700 000 000', 'email' => 'sales@example.invalid', 'website' => 'www.dishnetuganda.com',
                           'taxId' => null, 'registrationNumber' => null],
        'client' => $client + ['name' => 'Sample Customer', 'companyName' => '', 'street1' => 'Plot 1', 'street2' => '', 'city' => 'Kampala', 'state' => '',
                     'country' => 'Uganda', 'invoiceAddressSameAsContact' => true, 'invoiceStreet1' => '', 'invoiceStreet2' => '', 'invoiceCity' => '',
                     'invoiceState' => '', 'invoiceCountry' => '', 'firstGeneralPhone' => '', 'firstBillingPhone' => '',
                     'firstBillingEmail' => ''],
        'quote'  => ['number' => '000200', 'createdDate' => '27/09/2026', 'notes' => ''],
        'items'  => $items,
        'totals' => ['subtotal' => 'UGX 3,128,000', 'hasDiscount' => false, 'discountPrice' => '', 'discountLabel' => '',
                     'taxes' => $taxes, 'total' => 'UGX 3,128,000'],
    ];
}
/** The tags, filters and functions a template uses — what a Twig sandbox checks. */
function uses(\Twig\Environment $tw, string $src): array {
    $u = ['tags' => [], 'filters' => [], 'functions' => []];
    $walk = function ($n) use (&$walk, &$u) {
        if ($n instanceof \Twig\Node\Expression\FilterExpression) $u['filters'][$n->getNode('filter')->getAttribute('value')] = 1;
        if ($n instanceof \Twig\Node\Expression\FunctionExpression) $u['functions'][$n->getAttribute('name')] = 1;
        if ($n->getNodeTag()) $u['tags'][$n->getNodeTag()] = 1;
        foreach ($n as $c) if ($c) $walk($c);
    };
    $walk($tw->parse($tw->tokenize(new \Twig\Source($src, 'x'))));
    foreach ($u as &$v) { $v = array_keys($v); sort($v); }
    return $u;
}
$pass = 0; $fail = 0;
function t(string $name, $got, $want): void { global $pass, $fail;
    if ($got === $want) { $pass++; echo "  ok   $name\n"; }
    else { $fail++; echo "  FAIL $name\n       got  " . var_export($got, true) . "\n       want " . var_export($want, true) . "\n"; } }

$plain = new \Twig\Environment(new \Twig\Loader\ArrayLoader([]));
$base  = uses($plain, (string)file_get_contents($argv[3]));
echo "Twig " . \Twig\Environment::VERSION . " · the baseline uses tags " . implode(',', $base['tags']) . " · filters "
   . implode(',', $base['filters']) . " · functions " . (implode(',', $base['functions']) ?: 'none') . "\n";
$policy = new \Twig\Sandbox\SecurityPolicy($base['tags'], array_merge($base['filters'], ['escape']), [], [], $base['functions']);

$QUOTES = [
    'kit'          => ctx([item('Starlink Standard Kit', 'UGX 2,649,000'), item('Professional Installation', 'UGX 150,000', 'service'),
                           item('Residential (up to 400 Mbps)', 'UGX 329,000', 'service')]),
    'kit_last'     => ctx([item('Professional Installation', 'UGX 150,000', 'service'), item('Router Mini', 'UGX 435,000'),
                           item('Starlink Mini Kit', 'UGX 2,249,000')]),
    'kit_lower'    => ctx([item('starlink mini kit', 'UGX 2,249,000')]),
    'kit_upper'    => ctx([item('STARLINK STANDARD KIT', 'UGX 2,649,000')]),
    'no_kit'       => ctx([item('Residential (up to 400 Mbps)', 'UGX 329,000', 'service'), item('Router Mini', 'UGX 435,000')]),
    'starlink_svc' => ctx([item('Starlink Residential (up to 400 Mbps)', 'UGX 329,000', 'service')]),
    'travel_kit'   => ctx([item('Travel Kit | Mini', 'UGX 301,000')]),
    'kit_taxed'    => ctx([item('Starlink Standard Kit', 'UGX 2,649,000')], [['label' => 'VAT 18%', 'price' => 'UGX 404,085']]),
    // docs/67 §L: a business with only a city on file, a Flex quotation, and a quotation with no Starlink item at all
    'business'     => ctx([item('Starlink Standard Kit', 'UGX 2,649,000'), item('Business (up to 400 Mbps)', 'UGX 1,200,000', 'service')], [],
                          ['companyName' => 'Example Traders Limited', 'street1' => '', 'street2' => '']),
    'flex'         => ctx([item('Starlink Flex 12 Package', 'UGX 450,000', 'service')]),
    'cctv'         => ctx([item('CCTV camera', 'UGX 300,000'), item('Installation', 'UGX 100,000', 'service')]),
];
$render = function (string $file) use ($policy, $QUOTES): ?array {
    if (trim((string)@file_get_contents($file)) === '') { echo "  FAIL " . basename($file) . " is missing or empty\n"; return null; }
    $tw = new \Twig\Environment(new \Twig\Loader\ArrayLoader(['t' => (string)file_get_contents($file)]),
                                ['autoescape' => 'html', 'strict_variables' => false]);
    $tw->addExtension(new \Twig\Extension\SandboxExtension($policy, true));
    $out = [];
    try {
        foreach ($QUOTES as $k => $c) $out[$k] = $tw->render('t', $c);
    } catch (\Throwable $e) {
        echo "  FAIL " . basename($file) . " under the baseline's sandbox: " . get_class($e) . ': ' . $e->getMessage() . "\n";
        return null;
    }
    return $out;
};
/** Clause 2 as the customer reads it. */
function clause2(string $html): string {
    return preg_match('/2\. Currency &amp; Pricing:<\/b>(.*?)<\/div>/s', $html, $m) === 1 ? trim($m[1]) : '';
}
/** The page with its HTML comments removed — what a reader and a PDF engine are given. */
function page(string $html): string { return (string)preg_replace('/<!--.*?-->\s*/s', '', $html); }

$new = $render($argv[2]);
$old = $render($argv[3]);
if ($new === null || $old === null) { printf("\n%d passed, %d failed\n", $pass, $fail + 1); exit(1); }
t('the template renders under the baseline\'s sandbox', true, true);

// Every quote without tax lines — with a kit or without, however it is spelled — says what all its prices include.
foreach (['kit' => 'a Starlink kit', 'kit_last' => 'a Starlink kit listed last', 'kit_lower' => 'a kit named in lower case',
          'kit_upper' => 'a kit named in capitals', 'no_kit' => 'no kit on the quote',
          'starlink_svc' => 'a Starlink service alone', 'travel_kit' => 'the Travel Kit case alone'] as $k => $what) {
    $c = clause2($new[$k]);
    t("{$what}: clause 2 says all prices include all taxes", substr_count($c, ALL_TAXES), 1);
    t("{$what}: …and not that no VAT is charged", substr_count($c, NO_VAT), 0);
}
$c = clause2($new['kit_taxed']);
t('a quote with uCRM tax lines: VAT is itemised, as before', substr_count($c, VAT_ITEMISED), 1);
t('…and the all-taxes sentence is not printed beside it', substr_count($c, ALL_TAXES), 0);
t('…clause 2 unchanged there, but for the new-tax sentence withdrawn on 12 Oct (docs/67)', $c . NEW_TAX, clause2($old['kit_taxed']));
foreach ($QUOTES as $k => $_) t("{$k}: no new tax is \"passed on at cost\"", substr_count($new[$k], trim(NEW_TAX)), 0);
t('the baseline said no VAT is charged on a quote without tax lines (the contradiction 5.18.49 ends)', substr_count(clause2($old['no_kit']), NO_VAT), 1);

// Nothing else on the page moves: every quote renders as before once clause 2's sentence is set back, and the T&C lines
// changed on 12 Oct 2026 (tc_changes.php, docs/67 §I) are set back too — each found exactly once on both sides first,
// so a pair that no longer matches, or a line that changed beyond its pair, fails here.
// The Service Agreement: on every quote, once, filled from the quote, saying what the policies require, and never
// what they forbid. Then it is cut out at its markers so the rest of the page can be compared with the baseline's.
/** The agreement exactly as it was inserted — the two spaces before its start marker, through its end marker and the
 *  blank line after it — or '' when the markers are not there exactly once, in order, with that spacing. */
function agreement(string $html): string {
    if (substr_count($html, AGR_START) !== 1 || substr_count($html, AGR_END) !== 1) return '';
    $s = strpos($html, AGR_START) - 2; $e = strpos($html, AGR_END) + strlen(AGR_END) + 2;
    if ($s < 0 || $e <= $s || substr($html, $s, 2) !== '  ' || substr($html, $e - 2, 2) !== "\n\n") return '';
    return substr($html, $s, $e - $s);
}
$P = params(); $bizSite = null;
t('the policy numbers are read from build.py (PRICE_NOTICE, OUTAGE)', count($P), 2);
foreach ($QUOTES as $k => $c) {
    $a = agreement($new[$k]); $p = page($a); $lc = strtolower(html_entity_decode(strip_tags($p), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    $cl = $c['client']; $party = ($cl['companyName'] !== '' && $cl['companyName'] !== $cl['name'])
        ? '<b>' . $cl['companyName'] . '</b>, acting through ' . $cl['name'] : '<b>' . ($cl['companyName'] !== '' ? $cl['companyName'] : $cl['name']) . '</b>';
    t("{$k}: the Service Agreement is there, once, between its markers", $a !== '', true);
    t("{$k}: …it starts on a page of its own and has a second page", substr_count($p, 'page-break-before:always'), 2);
    t("{$k}: …it names the customer as the party (the company where there is one) and the quotation", substr_count($p, $party) === 1
        && substr_count($p, 'For the CUSTOMER: ' . ($cl['companyName'] !== '' ? $cl['companyName'] : $cl['name'])) === 1
        && substr_count($p, 'Quotation <b>' . $c['quote']['number'] . '</b>') === 1, true);
    t("{$k}: …it takes away nothing the Terms of Service, the policies and the law give", substr_count($p, AGR_KEEPS), 1);
    t("{$k}: …monthly, with no fixed term and no cancellation fee, for a DishNet service plan only", substr_count($p, AGR_TERM), 1);
    t("{$k}: …a Flex package is left to its own agreement exactly when the quotation has one", substr_count($p, AGR_FLEX), $k === 'flex' ? 1 : 0);
    t("{$k}: …the notice and outage numbers are build.py's", count($P) === 2 && substr_count($p, 'at least ' . $P['PRICE_NOTICE'] . '&rsquo; notice') === 1
        && substr_count($p, 'more than ' . $P['OUTAGE'] . ' in a row') === 1, true);
    $site = preg_match('/Site \/ installation address<\/td><td[^>]*>(.*?)<\/td>/s', $p, $m) === 1 ? $m[1] : '';
    t("{$k}: …the site address has no stray comma", $site !== '' && !preg_match('/^\s*,|,\s*,/', $site), true);
    if ($k === 'business') $bizSite = $site;
    $bad = array_values(array_filter(AGR_NEVER, fn($w) => strpos($lc, $w) !== false));
    t("{$k}: …and none of the terms or claims Uganda's policies do not allow", $bad, []);
    t("{$k}: …its text is the reviewed one (pinned)", hash('sha256', $p), AGR_SHA[$k] ?? '');
    $new[$k] = str_replace($a, '', $new[$k]);
}
t('business: a city alone prints as the site, without a leading comma', $bizSite, 'Kampala, Uganda &mdash; or the address confirmed in writing before installation');
t('the baseline has no Service Agreement', agreement($old['kit']), '');

$TC = require __DIR__ . '/tc_changes.php';
foreach ($QUOTES as $k => $_) {
    $n = str_replace(ALL_TAXES, NO_VAT, page($new[$k]));
    $o = page($old[$k]);
    $ok = true;
    foreach ($TC as [$was, $now]) {
        if (substr_count($n, $now) !== 1 || substr_count($o, $was) !== 1) { $ok = false; break; }
        $n = str_replace($now, $was, $n);
    }
    t("{$k}: each changed T&C line appears once, as listed", $ok, true);
    t("{$k}: the rest of the page is byte-identical to the baseline's", $n === $o, true);
}

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
