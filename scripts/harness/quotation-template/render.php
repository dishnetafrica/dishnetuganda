<?php
// render.php — render the Uganda quotation template with real Twig under a sandbox and check clause 2 (docs/42 §3, §9).
//   php render.php <twig vendor dir> <template under test> <baseline>
// The sandbox allows exactly the tags, filters and functions the baseline uses. The baseline is the template as the
// repository gave it to uCRM before 5.18.48 (commit 2bfad82), so a template that renders here uses nothing that
// template did not already use. Output is a list of checks; exit 1 on any failure.
require $argv[1] . '/vendor/autoload.php';

// 5.18.49: every quotation without uCRM tax lines says this — the operator's sentence of 27 Sep 2026 (docs/42 §9).
const ALL_TAXES    = 'All prices include all taxes &mdash; URA taxes and UCC charges are already in them. '
                   . 'Nothing is added on top.';
const NO_VAT       = 'No VAT is charged on this quotation.';
const VAT_ITEMISED = 'VAT is itemised in the totals on page 1.';

function item(string $label, string $price, string $type = 'product'): array {
    return ['label' => $label, 'type' => $type, 'unit' => '', 'quantity' => '1', 'price' => $price, 'total' => $price];
}
function ctx(array $items, array $taxes = []): array {
    return [
        'organization' => ['name' => 'DishNet Africa Limited', 'street' => 'Acacia Mall, 4th floor', 'city' => 'Kampala', 'state' => '',
                           'phone' => '+256 700 000 000', 'email' => 'sales@example.invalid', 'website' => 'www.dishnetuganda.com',
                           'taxId' => null, 'registrationNumber' => null],
        'client' => ['name' => 'Sample Customer', 'companyName' => '', 'street' => 'Plot 1', 'city' => 'Kampala', 'state' => '',
                     'country' => 'Uganda', 'invoiceAddressSameAsContact' => true, 'invoiceStreet' => '', 'invoiceCity' => '',
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
];
$render = function (string $file) use ($policy, $QUOTES): ?array {
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
t('…clause 2 unchanged there', $c, clause2($old['kit_taxed']));
t('the baseline said no VAT is charged on a quote without tax lines (the contradiction 5.18.49 ends)', substr_count(clause2($old['no_kit']), NO_VAT), 1);

// Nothing else on the page moves: every quote renders as before once clause 2's sentence is set back.
foreach ($QUOTES as $k => $_) {
    $n = str_replace(ALL_TAXES, NO_VAT, page($new[$k]));
    t("{$k}: the rest of the page is byte-identical to the baseline's", $n === page($old[$k]), true);
}

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
