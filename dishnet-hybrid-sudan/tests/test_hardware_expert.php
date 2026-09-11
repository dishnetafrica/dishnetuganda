<?php
/**
 * test_hardware_expert.php — the dish is not the Wi-Fi, and specs go stale.
 *
 * "Which Starlink should I buy?" is a question about a building, a number of
 * people, a power supply and whether the thing ever has to move. Answered from
 * a product list it fails in both directions: a family sold a Mini that cannot
 * cover the house, or two people in a flat sold the largest thing on the page.
 *
 * Two failures are worse than getting the model wrong, and both are asserted
 * here.
 *
 * Promising Wi-Fi. A coverage figure detached from its caveat becomes "your
 * whole house will have Wi-Fi", which is not what the number means and is not
 * fixed by a bigger dish.
 *
 * Stating a stale specification confidently. Starlink changes hardware
 * generations. A figure nobody has checked in a year, delivered with
 * confidence, is exactly the plausible-and-wrong answer the rest of this
 * system exists to prevent — so age is data, not a comment.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/lib/HardwareKnowledge.php';
require_once $root . '/lib/DishNetAiBrain.php';

$pass = 0; $fail = 0;
function ok(string $m): void  { global $pass; $pass++; echo "  ok   {$m}\n"; }
function bad(string $m, string $d=''): void { global $fail; $fail++; echo "  FAIL {$m}\n"; if($d)echo "       {$d}\n"; }
function is_(bool $c, string $m, string $d=''): void { $c ? ok($m) : bad($m, $d); }
function has(string $m, string $hay, string $needle): void {
    is_(stripos($hay, $needle) !== false, $m, 'missing: ' . $needle);
}

$FILE = $root . '/tools/starlink_hardware.json';

echo "\nThe shipped hardware file is loadable and sane\n";
$kb = HardwareKnowledge::load($FILE);
is_(count($kb['models']) >= 3, count($kb['models']) . ' models');
is_($kb['stale_after_days'] > 0, 'a staleness horizon is set');
foreach ($kb['models'] as $m) {
    is_(!empty($m['verified_on']), (string)$m['model'] . ' carries a verification date',
        'an undated specification cannot be known to be current');
    is_(!empty($m['source']), (string)$m['model'] . ' says where it came from');
}

echo "\nNo price is anywhere near it\n";
// Money lives in uCRM. A spec sheet that grows a price becomes a second
// catalogue that nobody remembers to update.
$raw = (string)file_get_contents($FILE);
is_(!preg_match('/UGX\s*[\d,]{4,}/i', $raw), 'no UGX figure in the hardware file');
is_(stripos($raw, 'price') === false || stripos($raw, 'NO PRICES') !== false,
    'and the only mention of price is the rule forbidding it');

echo "\nThe coverage number never appears without its caveat\n";
$p = HardwareKnowledge::promptBlock($FILE, '2026-09-10');
is_(strpos($p, '112 m2') !== false || strpos($p, '112 m') !== false, 'Mini coverage is stated');
// Strengthened after the figure was given to a customer as a "coverage
// radius" for a trading centre. It is an area, in ideal conditions, and it
// sizes nothing public.
has('and immediately qualified', $p, 'never a RADIUS');
has('and never sizes a public site', $p, 'never use it to size a trading centre');
has('the dish and the Wi-Fi are separated explicitly', $p, 'THE DISH IS NOT THE WI-FI');
has('and a bigger dish is named as the wrong fix', $p, 'does not fix a weak signal');

echo "\nEthernet is never answered generically\n";
// The clearest mark of a chatbot that does not know the product: the answer
// differs by generation, so the model has to be established first.
has('the rule is explicit', $p, 'NEVER answer an Ethernet, router or third-party');
has('and says to ask which kit', $p, 'Ask which Starlink they have');
// Corrected against Starlink's own Mini specification sheet, which lists one
// latching Ethernet LAN port used with the Starlink Plug — and lists that plug
// in What's In The Box. The earlier entry said no port existed without a
// separate adapter, and this assertion was holding that wrong answer in place.
has('Mini has a port, with the plug from the box', $p, 'latching Ethernet LAN port');
has('and no separate adapter is needed',           $p, 'No separate adapter');
has('Standard ships Router 3 with two ports',      $p, 'TWO latching Ethernet LAN ports');
has('older generations still differ',              $p, 'Older Standard generations differ');
has('and Starlink mesh only, not third-party',     $p, 'NOT with third-party');
foreach (['MikroTik', 'UniFi', 'Fortinet', 'Cisco'] as $vendor) {
    has('third-party gear named: ' . $vendor, $p, $vendor);
}

echo "\nSolar is sized on the whole load, not the dish\n";
has('the whole-load rule', $p, 'covers the WHOLE load');
has('and never on the dish alone', $p, "from the dish's figure alone");
has('Mini power is stated', $p, '25-40 W');
has('Standard power is stated', $p, '75-100 W');

echo "\nUnverified fields say so rather than being filled in\n";
// High Performance ships with nulls on purpose. That is the honest state of
// what we have confirmed, and it must render as such.
has('a null renders as not verified', $p, 'not verified');
has('and instructs a confirmation', $p, 'never fill the gap');

echo "\nHardware is never recommended on price\n";
has('not because it costs more', $p, 'Never because one costs');
has('and not because it is cheapest', $p, 'never because one is cheapest');

echo "\nExtras are quoted separately, not folded into the kit\n";
has('access points, switches, cabling', $p, 'quoted separately');

echo "\nA difficult site becomes a survey, not a promise\n";
has('obstructions matter more than the model', $p, 'clear view of the sky');
has('and a survey is the answer', $p, 'site survey');

echo "\nAge is data: a stale spec stops being asserted\n";
// Same file, read two years later.
$later = HardwareKnowledge::promptBlock($FILE, '2028-09-10');
is_(strpos($p, 'last verified') === false, 'fresh specs carry no warning');
is_(strpos($later, 'last verified') !== false, 'stale ones do',
    'a figure nobody has checked in two years must not be stated flatly');
has('and the reason is given', $later, 'changes hardware generations');
has('with an offer to confirm', $later, 'offer to confirm');

echo "\nAn empty or missing file leaves the prompt alone\n";
is_(HardwareKnowledge::promptBlock('/nonexistent/file.json') === '',
    'a missing file yields nothing, not a broken block');
$empty = sys_get_temp_dir() . '/dn_hw_empty_' . getmypid() . '.json';
file_put_contents($empty, '{"models":[]}');
is_(HardwareKnowledge::promptBlock($empty) === '', 'and neither does an empty one');
unlink($empty);

// ── The gate ──────────────────────────────────────────────────────────────
echo "\nWithout the setting, South Sudan is untouched\n";
$ctx = fn(string $ch): array => ['channel' => $ch, 'medium' => 'whatsapp',
                                 'customer' => null, 'history' => [],
                                 'message' => 'which dish should I buy?'];
$off = new DishNetAiBrain(['claude_api_key' => 'k']);
foreach (['sales', 'support', 'account'] as $ch) {
    is_(strpos($off->promptPreview($ctx($ch)), 'STARLINK HARDWARE') === false,
        'the ' . $ch . ' prompt is exactly as it was');
}

echo "\nWith it, the sales number knows the hardware\n";
$on = new DishNetAiBrain(['claude_api_key' => 'k', 'ai_hardware_expert' => '1']);
has('the block is in the sales prompt', $on->promptPreview($ctx('sales')), 'STARLINK HARDWARE');
is_(strpos($on->promptPreview($ctx('support')), 'STARLINK HARDWARE') === false,
    'and not on a support number that does not sell');

$everywhere = new DishNetAiBrain(['claude_api_key' => 'k', 'ai_hardware_expert' => '1',
                                  'ai_sales_on_all_numbers' => '1']);
has('but it is once that number sells', $everywhere->promptPreview($ctx('support')), 'STARLINK HARDWARE');

echo "\nCCTV is asked about, not assumed\n";
// Corrects the first version of this rule, which treated every camera as a
// public-IP requirement. Local recording does not need one, and over-selling
// is the same error as under-selling.
$q = new DishNetAiBrain(['claude_api_key' => 'k', 'ai_qualification' => '1']);
$qp = $q->promptPreview($ctx('sales'));
has('cameras recording on site need no public IP', $qp, 'need no public IP');
has('watching from elsewhere does',                $qp, 'watching them from');
has('so it asks first',                            $qp, 'ask which they want before steering');
has('and over-selling is named as a mistake too',  $qp, 'same mistake as the reverse');

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
