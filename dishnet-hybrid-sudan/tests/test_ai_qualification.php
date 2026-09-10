<?php
/**
 * test_ai_qualification.php — establish the requirement before naming a plan.
 *
 * The sales prompt's entire recommendation logic was "ask one or two short
 * qualifying questions, then recommend from PLANS". A hotel, a factory and a
 * two-person household all arrived at that same sentence.
 *
 * The dangerous half was the direction the guard ran in. RULE_CHEAPEST_PLAN
 * stops Business being offered as a cheap home plan. Nothing stopped the
 * reverse: a business that needs to reach its own cameras from outside being
 * sold Residential, which is behind CGNAT and cannot do it at any speed. That
 * sale fails after the customer has paid.
 *
 * The flag matters as much as the feature. South Sudan's prompt must not move,
 * so absence means the old prompt byte for byte, and that is asserted first.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/lib/DishNetAiBrain.php';

$pass = 0; $fail = 0;
function ok(string $m): void  { global $pass; $pass++; echo "  ok   {$m}\n"; }
function bad(string $m, string $d=''): void { global $fail; $fail++; echo "  FAIL {$m}\n"; if($d)echo "       {$d}\n"; }
function is_(bool $c, string $m, string $d=''): void { $c ? ok($m) : bad($m, $d); }
function has(string $m, string $hay, string $needle): void {
    is_(strpos($hay, $needle) !== false, $m, 'prompt is missing: ' . $needle);
}

$ctx = fn(string $ch): array => ['channel' => $ch, 'medium' => 'whatsapp',
                                 'customer' => null, 'history' => [],
                                 'message' => 'which plan should I buy?'];

$off = new DishNetAiBrain(['claude_api_key' => 'k']);
$on  = new DishNetAiBrain(['claude_api_key' => 'k', 'ai_qualification' => '1']);
$onEverywhere = new DishNetAiBrain(['claude_api_key' => 'k', 'ai_qualification' => '1',
                                    'ai_sales_on_all_numbers' => '1']);

$MARK = 'QUALIFY BEFORE YOU RECOMMEND';

echo "\nWithout the setting, South Sudan is untouched\n";
foreach (['sales', 'support', 'account'] as $ch) {
    is_(strpos($off->promptPreview($ctx($ch)), $MARK) === false,
        'the ' . $ch . ' prompt is exactly as it was');
}

echo "\nWith it, the sales number qualifies\n";
$p = $on->promptPreview($ctx('sales'));
has('the block is present', $p, $MARK);

echo "\nThe Public IP triggers are all named\n";
// Each of these is a customer who will be unable to do the thing they bought
// the connection for if they are put on Residential.
foreach (['CCTV they want to view from elsewhere', 'VPN', 'a server', 'remote desktop',
          'hosting', 'remote monitoring', 'access control', 'more than one'] as $trigger) {
    has('trigger: ' . $trigger, $p, $trigger);
}
has('and they are named as needing a public IP', $p, 'needs a PUBLIC IP');
has('which Residential does not carry',          $p, 'NOT part of a Residential plan');

echo "\nThe expensive mistake is forbidden explicitly\n";
has('never sell Residential to that customer', $p, 'Never quote a '
    . "Residential plan to that customer as though it would do the job");
has('and the reason is stated, not just the rule', $p, 'cannot reach their own cameras');

echo "\nWhen it cannot tell, it asks — once\n";
has('the proactive question', $p, 'ask once');
has('one question at a time',  $p, 'never a list of questions');
has('and never re-asks',       $p, 'never re-ask something they have already told you');

echo "\nOrganisations are never defaulted to Residential\n";
has('the organisation list', $p, 'hotel, lodge, factory, school, NGO, bank');
has('no Residential default', $p, 'never given Residential as the default');

echo "\nBusiness pricing stays RED unless it is in the live catalogue\n";
// The whole point of the price rules: an unlisted Business price is confirmed,
// never derived from a Residential one.
has('only quotable from PLANS',   $p, 'only yours to quote when it is in PLANS');
has('never estimated',            $p, 'Never estimate it');
has('and never extrapolated',     $p, 'never work it out from a Residential price');

echo "\nEach kind of customer has its own short question set\n";
foreach ([['Home', 'Two questions'], ['Hotel or lodge', 'how many rooms'],
          ['Factory or warehouse', 'production or ERP'], ['School', 'labs or classes'],
          ['Farm, remote site or field team', 'Mini against Standard'],
          ['Office or small business', 'which applications matter']] as [$seg, $probe]) {
    has($seg . ' asks about ' . $probe, $p, $probe);
}
has('and only what changes the answer', $p, 'only what changes the recommendation');

echo "\nAnything big enough to design is handed over, not designed\n";
has('multi-site and SLA go to a human', $p, 'take the details and');

echo "\nIt reaches the support number only when that number sells\n";
is_(strpos($on->promptPreview($ctx('support')), $MARK) === false,
    'support alone does not qualify — it is not selling',
    'without ai_sales_on_all_numbers the support role has no sales funnel to protect');
is_(strpos($onEverywhere->promptPreview($ctx('support')), $MARK) !== false,
    'but it does once that number sells too');

echo "\nWherever a number sells, it carries the guard\n";
// Accounts answers sales questions too under ai_sales_on_all_numbers (see
// test_sales_on_all_numbers.php). A number allowed to sell without the public-IP
// guard is the exact hole this closes, so it gets the block as well.
is_(strpos($onEverywhere->promptPreview($ctx('account')), $MARK) !== false,
    'accounts qualifies too once it is allowed to sell');
is_(strpos($on->promptPreview($ctx('account')), $MARK) === false,
    'and not when it is not selling');

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
