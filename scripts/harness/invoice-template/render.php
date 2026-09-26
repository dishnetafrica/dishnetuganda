<?php
// render.php — render the Uganda invoice template with real Twig under a sandbox and check what a customer sees.
//   php render.php <twig vendor dir> <template under test> <baseline accepted by uCRM> [<another template to report>]
// The sandbox allows exactly the tags, filters and functions the baseline uses. The baseline is "V1 invoice" as uCRM
// holds it (byte-identical to commit acb51d0), so uCRM has already accepted every construct the policy permits: a
// template that renders here needs nothing uCRM has not accepted. Output is a list of checks; exit 1 on any failure.
require $argv[1] . '/vendor/autoload.php';

final class Inv {                      // uCRM passes objects; invoice.getAttribute() is a method call
    public $number = '000003'; public $createdDate = '21/09/2026'; public $dueDate = '28/09/2026'; public $notes = '';
    public $onlinePaymentLink = 'https://crm.dishnetuganda.com:8443/crm/online-payment/pay/9f8e7d6c5b4a39281706f5e4d3c2b1a0';
    public function getAttribute(string $k) { return ['siteId' => 'KLA-01', 'referencePO' => '', 'deliveredInstall' => 'Kampala'][$k] ?? null; }
}
function ctx(string $due, string $paid): array {
    return [
        'organization' => ['name' => 'DishNet Africa Limited', 'street1' => 'Acacia Mall, 4th floor', 'street2' => '', 'city' => 'Kampala',
                           'state' => '', 'phone' => '+256 700 000 000', 'email' => 'billing@example.invalid',
                           'website' => 'www.dishnetuganda.com', 'stamp' => null, 'taxId' => null, 'registrationNumber' => null],
        'client' => ['name' => 'Sample Customer', 'username' => '', 'invoiceAddressSameAsContact' => true, 'street1' => 'Plot 1', 'street2' => '',
                     'city' => 'Kampala', 'state' => '', 'country' => 'Uganda', 'companyTaxId' => '', 'firstBillingPhone' => '', 'firstBillingEmail' => ''],
        'invoice' => new Inv(),
        'items' => [['label' => 'Starlink monthly service', 'type' => 'service', 'unit' => '', 'quantity' => '1', 'price' => 'UGX 329,000', 'total' => 'UGX 329,000',
                     'service' => ['tariff' => 'Standard', 'activeFrom' => '21/09/2026', 'activeTo' => '20/10/2026', 'contractId' => '']]],
        'totals' => ['subtotal' => 'UGX 329,000', 'hasDiscount' => false, 'taxes' => [], 'total' => 'UGX 329,000', 'amountPaid' => $paid, 'amountDue' => $due],
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
echo "Twig " . \Twig\Environment::VERSION . " · the baseline uses tags " . implode(',', $base['tags']) . " · filters " . implode(',', $base['filters'])
   . " · functions " . (implode(',', $base['functions']) ?: 'none') . "\n";
$policy = new \Twig\Sandbox\SecurityPolicy($base['tags'], array_merge($base['filters'], ['escape']), [Inv::class => ['getattribute']],
                                           [Inv::class => ['number', 'createdDate', 'dueDate', 'notes', 'onlinePaymentLink']], $base['functions']);
$render = function (string $file) use ($policy): ?array {
    $tw = new \Twig\Environment(new \Twig\Loader\ArrayLoader(['t' => (string)file_get_contents($file)]), ['autoescape' => 'html', 'strict_variables' => false]);
    $tw->addExtension(new \Twig\Extension\SandboxExtension($policy, true));
    try {
        return ['unpaid' => $tw->render('t', ctx('UGX 329,000', 'UGX 0')), 'partly' => $tw->render('t', ctx('UGX 100,000', 'UGX 229,000')),
                'cents'  => $tw->render('t', ctx('UGX 0.50', 'UGX 328,999.50')),
                'paid'   => $tw->render('t', ctx('UGX 0', 'UGX 329,000')),    'paid_nbsp' => $tw->render('t', ctx("UGX\u{00A0}0", 'UGX 329,000')),
                'paid_ush' => $tw->render('t', ctx('USh0', 'UGX 329,000')),   'paid_dec' => $tw->render('t', ctx('UGX 0.00', 'UGX 329,000')),
                'paid_after' => $tw->render('t', ctx('0 UGX', 'UGX 329,000')), 'empty' => $tw->render('t', ctx('', 'UGX 0'))];
    } catch (\Throwable $e) { echo "  FAIL " . basename($file) . " under the baseline's sandbox: " . get_class($e) . ': ' . $e->getMessage() . "\n"; return null; }
};
$has = fn(string $s, string $n): bool => strpos($s, $n) !== false;
$status = fn(string $h): string => preg_match('/>\s*(PAID|UNPAID)\s*</', $h, $m) ? $m[1] : '?';

echo "1) the template renders under a sandbox that permits only what the baseline uses\n";
$out = $render($argv[2]);
t('it renders', $out !== null, true);
$nu = uses($plain, (string)file_get_contents($argv[2]));
foreach (['tags', 'filters', 'functions'] as $k) t("its $k are the baseline's or fewer", array_values(array_diff($nu[$k], $base[$k])), []);
if ($out === null) { printf("\n%d passed, %d failed\n", $pass, $fail + 1); exit(1); }

echo "2) what an unpaid Uganda invoice shows\n";
t('Kampala, Uganda, no Juba, no +211', $has($out['unpaid'], 'Kampala, Uganda') && !$has($out['unpaid'], 'Juba') && !$has($out['unpaid'], '+211'), true);
t('the amount due is labelled UGX', $has($out['unpaid'], 'Amount Due (UGX)'), true);
t('UNPAID', $status($out['unpaid']), 'UNPAID');
t('PAY NOW goes to DishNet\'s pay page', $has($out['unpaid'], 'href="https://dishnetuganda.com/pay"'), true);
t('the address under it is dishnetuganda.com/pay', $has($out['unpaid'], '>dishnetuganda.com/pay</div>'), true);
t('nothing on :8443', $has($out['unpaid'], ':8443'), false);
t('uCRM\'s payment token is not printed', $has($out['unpaid'], '9f8e7d6c5b4a'), false);
t('the bank details are printed', $has($out['unpaid'], '7247510191') && $has($out['unpaid'], 'ECOCUGKA'), true);
t('no Twig comment reaches the page', $has($out['unpaid'], 'non-breaking') || $has($out['unpaid'], 'docs/38'), false);
t('a partly paid invoice is UNPAID, with PAY NOW', [$status($out['partly']), $has($out['partly'], 'PAY NOW')], ['UNPAID', true]);
t('fifty cents due is UNPAID', $status($out['cents']), 'UNPAID');
t('an empty amount due is never read as PAID', $status($out['empty']), 'UNPAID');

echo "3) a paid invoice reads PAID however uCRM writes a zero, and shows no PAY NOW\n";
foreach (['paid' => '"UGX 0"', 'paid_nbsp' => '"UGX 0" with a non-breaking space', 'paid_ush' => '"USh0"', 'paid_dec' => '"UGX 0.00"', 'paid_after' => '"0 UGX"'] as $k => $label) {
    t("$label → PAID, no PAY NOW", [$status($out[$k]), $has($out[$k], 'PAY NOW')], ['PAID', false]);
}

if (isset($argv[4])) {
    echo "4) for the record: " . basename(dirname($argv[4])) . '/' . basename($argv[4]) . " under the same sandbox\n";
    $o = $render($argv[4]);
    if ($o !== null) {
        printf("  report  Juba %s · +211 %s · \"Amount Due (USD)\" %s · PAY NOW to :8443 %s · a paid \"UGX 0\" reads %s\n",
               $has($o['unpaid'], 'Juba') ? 'yes' : 'no', $has($o['unpaid'], '+211') ? 'yes' : 'no', $has($o['unpaid'], 'Amount Due (USD)') ? 'yes' : 'no',
               $has($o['unpaid'], 'href="https://crm.dishnetuganda.com:8443/') ? 'yes' : 'no', $status($o['paid']));
    }
}
printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
