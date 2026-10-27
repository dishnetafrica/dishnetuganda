<?php
// render.php — render the Uganda quotation template with real Twig under a sandbox and check clause 2 (docs/42 §3, §9).
//   php render.php <twig vendor dir> <template under test> <baseline>
// The sandbox allows exactly the tags, filters and functions the baseline uses. The baseline is the template as the
// repository gave it to uCRM before 5.18.48 (commit 2bfad82), so a template that renders here uses nothing that
// template did not already use. Output is a list of checks; exit 1 on any failure.
// The Service Agreement (pages 3–4, docs/67 §L) is checked on its own, then cut out at its two markers before the
// rest of the page is compared with the baseline's. The numbers it and page 2 quote are read from build.py's PARAMS,
// and the policy sections they cite from the published pages, so neither can drift from its source unnoticed.
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
const AGR_TERM  = 'For a DishNet service plan on this quotation, other than a Flex package: monthly, with no fixed term and no '
                 . 'cancellation fee; it continues month to month until cancelled (clause 6). Equipment and installation alone carry '
                 . 'no recurring charge from DISHNET.';
const AGR_FLEX  = 'This quotation includes a Flex package. It is a quotation only:';
const FLEX_SAMPLES = ['flex', 'flex_lower', 'flex_upper'];
// On every quotation, whether or not the Flex row prints: no Flex package before its agreement, and the 7-day right stays.
const AGR_FLEX_GATE = 'No Flex package is in force until its agreement is signed by both parties';
// docs/67 §J: no claim of a UCC authorisation, licence, approval or registration as an installer, in any word order.
const AGR_UCC_CLAIM = '/\b(?:authori[sz](?:ed|ation|ing)|licen[cs](?:e|ed|ing)|accredit\w*|approv(?:ed|al)|certif\w*|registered|permit(?:ted)?)\b'
                    . '[^.;]{0,80}\b(?:uganda communications commission|ucc)\b|\b(?:uganda communications commission|ucc)\b[\s-]{0,3}'
                    . '(?:authori[sz](?:ed|ation)|licen[cs](?:e|ed)|accredit\w*|approv(?:ed|al)|certif\w*|registered)\b/';
// South Sudan's terms that Uganda's published policies do not allow, claims the pages never make (docs/67 §J), words the
// PDF doctor reads as South Sudan content, and clauses the review removed (docs/67 §L).
const AGR_NEVER = ['termination fee', 'arbitration', 'merchantability', 'fitness for a particular purpose', 'sole and entire remedy',
                   'south sudan', 'juba', '+211', 'dishnetafrica', 'ucc authorised', 'ucc-authorised', 'authorised starlink installer',
                   'licensed by', 'licence', 'license', 'security deposit is needed', 'interest', 'entire agreement', 'whole agreement',
                   'confidential', 'best-effort', 'best effort', 'without further notice', 'deemed accepted', '48 hours', 'sole remedy',
                   'early termination', 'passed on at cost', '24/7'];
// The agreement as reviewed, pinned per sample quote: sha256 of the rendered agreement, comments removed; and its source
// between the markers, which also covers branches no sample renders. A change to its text must change these
// deliberately (docs/67 §L).
const AGR_SHA = [
    'kit'          => 'edf054a7f8b82e1eebc4058acf8a9f5b669220ca7de83617a726cf6276ae4cbd',
    'kit_last'     => 'edf054a7f8b82e1eebc4058acf8a9f5b669220ca7de83617a726cf6276ae4cbd',
    'kit_lower'    => 'edf054a7f8b82e1eebc4058acf8a9f5b669220ca7de83617a726cf6276ae4cbd',
    'kit_upper'    => 'edf054a7f8b82e1eebc4058acf8a9f5b669220ca7de83617a726cf6276ae4cbd',
    'no_kit'       => 'edf054a7f8b82e1eebc4058acf8a9f5b669220ca7de83617a726cf6276ae4cbd',
    'starlink_svc' => 'edf054a7f8b82e1eebc4058acf8a9f5b669220ca7de83617a726cf6276ae4cbd',
    'travel_kit'   => 'edf054a7f8b82e1eebc4058acf8a9f5b669220ca7de83617a726cf6276ae4cbd',
    'kit_taxed'    => 'edf054a7f8b82e1eebc4058acf8a9f5b669220ca7de83617a726cf6276ae4cbd',
    'business'     => '732bb64f86e6d17c1569c1437360ef3e93315feb8572784a716bfb44a6e32235',
    'flex'         => 'e019e75f1218f4bc0e0ce941be5034cbcc58baad950d31efafadd1817f4286eb',
    'flex_lower'   => 'e019e75f1218f4bc0e0ce941be5034cbcc58baad950d31efafadd1817f4286eb',
    'flex_upper'   => 'e019e75f1218f4bc0e0ce941be5034cbcc58baad950d31efafadd1817f4286eb',
    'not_flex'     => 'edf054a7f8b82e1eebc4058acf8a9f5b669220ca7de83617a726cf6276ae4cbd',
    'cctv'         => 'edf054a7f8b82e1eebc4058acf8a9f5b669220ca7de83617a726cf6276ae4cbd',
    'no_address'   => 'cbba1161ba90f1ad7106c68477952b5aa19c6538df8ed7520de106f0fe6cf1bc',
    'street2'      => '11f8d5e55ebb93c8cf7064765fd1c480d6b4d0bcfe9a60501491c9af08efb063',
    'company_same' => '45c79a0d0b96a0034fa4d6112fc4bdefd82b5924655be685c4932eaf02ce488b',
    'org_ids'      => 'b743496709ae47ff06fa52885e6da5075430cf2fb3516bee33b45fc1438b560b',
];
const AGR_SRC_SHA = 'ae7e3593bff3b3e9e9cf86d2f3e2c10f22b5e1da588a527132a381d4a61d90e1';
/** The numbers the agreement and page 2 quote, from their single source: PARAMS in the website's policies/build.py. */
const PARAM_KEYS = ['PRICE_NOTICE', 'OUTAGE', 'RETURN_DAYS', 'KIT_WARRANTY'];
function params(): array {
    $src = (string)@file_get_contents(dirname(__DIR__, 3) . '/dishnet-web-uganda/policies/build.py');
    $o = [];
    foreach (PARAM_KEYS as $k) if (preg_match("/'$k':\\s*'([^']+)'/", $src, $m) === 1) $o[$k] = $m[1];
    return $o;
}
/** Every policy section a page cites, as [page, number], sorted and unique. Read from the decoded text in any case and
 *  in any of the template's spellings ("the", "its", "DishNet's", "DISHNET's"); "sections 2, 4 and 5" is read as three.
 *  A "section N" that names no document listed here, or a "§", comes back as ['?', …], so that it fails. */
const CITE_DOCS = ['terms' => 'terms of service', 'returns' => 'returns, refunds and cancellations', 'privacy' => 'privacy policy',
                   'acceptable-use' => 'acceptable use', 'installation' => 'installation terms', 'warranty' => 'warranty policy',
                   'terms-of-sale' => 'terms of sale'];
function citations(string $html): array {
    $x = preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags(page($html)), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    preg_match_all('/\bsections?\s+(\d+(?:(?:,\s*|\s+and\s+)\d+)*)(?:\s+of\s+(?:the\s+|its\s+|dishnet[\'’]s\s+)?(?=(.{0,40})))?/iu', $x, $m, PREG_SET_ORDER);
    $o = strpos($x, '§') !== false ? [['?', '§']] : [];
    foreach ($m as $c) {
        $pg = '?'; foreach (CITE_DOCS as $k => $name) if (stripos($c[2] ?? '', $name) === 0) $pg = $k;
        foreach (preg_split('/,\s*|\s+and\s+/', $c[1]) as $n) $o[] = [$pg, $n];
    }
    sort($o); return array_values(array_unique($o, SORT_REGULAR));
}
/** A published page's numbered sections, id => number, read from its headings (<h3 id="…">N. …). */
function sections(string $page): array {
    $src = (string)@file_get_contents(dirname(__DIR__, 3) . "/dishnet-web-uganda/policies/bodies/$page.html");
    preg_match_all('/<h3 id="([a-z0-9-]+)">(\d+)\./', $src, $m, PREG_SET_ORDER);
    $o = []; foreach ($m as $x) $o[$x[1]] = $x[2];
    return $o;
}
// Every section the agreement and page 2 cite, by the page's own heading id: [page, id, the number cited].
const CITED = [['terms', 'who', '1'], ['terms', 'documents', '2'], ['terms', 'cancel', '8'], ['terms', 'equipment', '9'],
               ['terms', 'faults', '11'], ['terms', 'liability', '14'], ['terms', 'outside', '15'], ['terms', 'ending', '16'],
               ['returns', 'change', '2'], ['returns', 'orders', '4'], ['returns', 'installation', '5'], ['returns', 'plans', '6'],
               ['privacy', 'share', '5'], ['acceptable-use', 'action', '5'], ['warranty', 'workmanship', '4'],
               ['terms-of-sale', 'delivery', '5']];

// Withdrawn from the end of clause 2 on 12 Oct 2026: the website says all taxes are included and nothing is added.
const NEW_TAX      = ' Any new or increased government tax, levy or regulatory fee introduced after acceptance may be '
                   . 'passed on at cost.';

function item(string $label, string $price, string $type = 'product'): array {
    return ['label' => $label, 'type' => $type, 'unit' => '', 'quantity' => '1', 'price' => $price, 'total' => $price];
}
function ctx(array $items, array $taxes = [], array $client = [], array $org = []): array {
    return [
        'organization' => $org + ['name' => 'DishNet Africa Limited', 'street1' => 'Acacia Mall, 4th floor', 'street2' => '', 'city' => 'Kampala', 'state' => '',
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
    // docs/67 §L: a business with only a city on file, Flex quotations in uCRM's three spellings, a label that only
    // looks like Flex, a quotation with no Starlink item at all, and every branch of the party and site cells
    'business'     => ctx([item('Starlink Standard Kit', 'UGX 2,649,000'), item('Business (up to 400 Mbps)', 'UGX 1,200,000', 'service')], [],
                          ['companyName' => 'Example Traders Limited', 'street1' => '', 'street2' => '']),
    'flex'         => ctx([item('DishNet Flex 12', 'UGX 450,000', 'service')]),
    'flex_lower'   => ctx([item('dishnet flex 24', 'UGX 450,000', 'service')]),
    'flex_upper'   => ctx([item('DISHNET FLEX 12', 'UGX 450,000', 'service')]),
    'not_flex'     => ctx([item('Flexible conduit (per metre)', 'UGX 5,000'), item('Installation', 'UGX 100,000', 'service')]),
    'cctv'         => ctx([item('CCTV camera', 'UGX 300,000'), item('Installation', 'UGX 100,000', 'service')]),
    'no_address'   => ctx([item('Starlink Standard Kit', 'UGX 2,649,000')], [], ['street1' => '', 'street2' => '', 'city' => '']),
    'street2'      => ctx([item('Starlink Standard Kit', 'UGX 2,649,000')], [], ['street2' => 'Block B', 'country' => '']),
    'company_same' => ctx([item('Starlink Standard Kit', 'UGX 2,649,000')], [],
                          ['name' => 'Example Traders Limited', 'companyName' => 'Example Traders Limited']),
    'org_ids'      => ctx([item('Starlink Standard Kit', 'UGX 2,649,000')], [], [],
                          ['taxId' => 'TIN-EXAMPLE-1', 'registrationNumber' => 'REG-EXAMPLE-1']),
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
$P = params(); $sites = []; $ids = [];
t('the policy numbers are read from build.py (' . implode(', ', PARAM_KEYS) . ')', array_keys($P), PARAM_KEYS);
$sec = []; foreach (CITED as [$pg, $id, $n]) $sec[] = [$pg, $id, (sections($pg)[$id] ?? '')];
t('every section the agreement and page 2 cite still has that number on its published page', $sec, CITED);
// The Installation Terms are not a published page: the agreement and page 2 cite their section 25 for the installation
// cap (docs/67 §J item 5), so it must stay that section in every version a job can have been accepted under.
require_once dirname(__DIR__, 3) . '/dishnet-hybrid-sudan/lib/InstallationTerms.php';
$inst = []; foreach (InstallationTerms::VERSIONS as $v) foreach (InstallationTerms::sections($v) as $s) if ($s['n'] === 25) $inst[$v] = $s['title'];
t('section 25 of the Installation Terms is still their limitation of liability, in every version', $inst,
  array_fill_keys(InstallationTerms::VERSIONS, 'Limitation of liability'));
$citedPairs = array_map(fn($c) => [$c[0], $c[2]], CITED); sort($citedPairs); $citedPairs = array_values(array_unique($citedPairs, SORT_REGULAR));
$src = (string)file_get_contents($argv[2]); $s0 = strpos($src, AGR_START); $e0 = strpos($src, AGR_END);
t("the agreement's source is the reviewed text (pinned)", ($s0 !== false && $e0 !== false && $e0 > $s0)
    ? hash('sha256', substr($src, $s0, $e0 + strlen(AGR_END) - $s0)) : '', AGR_SRC_SHA);
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
    t("{$k}: …a Flex package is quoted only, exactly when the quotation has one", substr_count($p, AGR_FLEX), in_array($k, FLEX_SAMPLES, true) ? 1 : 0);
    t("{$k}: …no Flex package is in force before its agreement is signed, and the 7-day cancellation stays", count($P) === 4
        && substr_count($p, AGR_FLEX_GATE) === 1 && substr_count($p, 'within ' . $P['RETURN_DAYS'] . ' of receiving the kit') === 1, true);
    t("{$k}: …the notice and outage numbers are build.py's", count($P) === 4 && substr_count($p, 'at least ' . $P['PRICE_NOTICE'] . '&rsquo; notice') === 1
        && substr_count($p, 'more than ' . $P['OUTAGE'] . ' in a row') === 1, true);
    $site = preg_match('/Site \/ installation address<\/td><td[^>]*>(.*?)<\/td>/s', $p, $m) === 1 ? $m[1] : '';
    t("{$k}: …the site address has no stray comma", $site !== '' && !preg_match('/^\s*,|,\s*,/', $site), true);
    $sites[$k] = $site;
    $ids[$k] = preg_match('/\(a\) <b>[^<]*<\/b>, a company registered in Uganda \((registration number [^)]*)\)/', $p, $m) === 1 ? $m[1] : '';
    $bad = array_values(array_filter(AGR_NEVER, fn($w) => strpos($lc, $w) !== false));
    t("{$k}: …and none of the terms or claims Uganda's policies do not allow", $bad, []);
    t("{$k}: …and no UCC authorisation or licence claim (docs/67 §J)", preg_match(AGR_UCC_CLAIM, $lc), 0);
    t("{$k}: …the agreement and page 2 cite exactly the policy sections the harness maps",
      array_values(array_filter(citations($new[$k]), fn($c) => $c[0] !== 'installation')), $citedPairs);
    t("{$k}: …it cites section 25 of the Installation Terms, and page 2 does, once each", substr_count(page($new[$k]), 'section 25 of the Installation Terms'), 2);
    t("{$k}: …its text is the reviewed one (pinned)", hash('sha256', $p), AGR_SHA[$k] ?? '');
    $new[$k] = str_replace($a, '', $new[$k]);
    $p2 = page($new[$k]);
    t("{$k}: page 2's notice, return and warranty periods are build.py's", count($P) === 4
        && substr_count($p2, 'Prices may change with ' . $P['PRICE_NOTICE'] . "' notice.") === 1
        && substr_count($p2, 'may be returned within ' . $P['RETURN_DAYS'] . ' of delivery') === 1
        && substr_count($p2, '(' . $P['KIT_WARRANTY'] . ' on Starlink kits from delivery)') === 1, true);
}
t('business: a city alone prints as the site, without a leading comma', $sites['business'] ?? null, 'Kampala, Uganda &mdash; or the address confirmed in writing before installation');
t('no_address: no street or city prints the site as to be confirmed', $sites['no_address'] ?? null, 'To be confirmed in writing before installation');
t('street2: a second street line and no country print in order', $sites['street2'] ?? null, 'Plot 1, Block B, Kampala &mdash; or the address confirmed in writing before installation');
t("org_ids: uCRM's registration number and TIN print in party (a)", $ids['org_ids'] ?? null, 'registration number REG-EXAMPLE-1, TIN TIN-EXAMPLE-1');
// The fallbacks are read from the template's own {% set %} lines, so no company number is copied into the harness.
$fb = 'registration number ' . (preg_match_all('/\{% set org_reg = \'(\d+)\' %\}/', $src, $m) === 1 ? $m[1][0] : '?')
    . ', TIN ' . (preg_match_all('/\{% set org_tin = \'(\d+)\' %\}/', $src, $m) === 1 ? $m[1][0] : '?');
t('…and a quotation without them prints the fallback numbers, not those', array_keys(array_filter($ids, fn($v) => $v !== $fb)), ['org_ids']);
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
