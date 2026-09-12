<?php
declare(strict_types=1);
/**
 * test_service_plan.php — what the customer bought, and never a flattering guess.
 *
 * Ported from South Sudan, whose convention is sound: the plan a customer is
 * SOLD lives in uCRM and overrides whatever Starlink calls the service line.
 *
 * Ported WITHOUT its one live bug. Over there the admin Clients tab reads the
 * uCRM service and shows "6TB plan", while the customer's own shareable link
 * reads plan_name off sl_kits.json — Starlink's word, "Residential" — finds no
 * digits in it, and tells the customer they are UNLIMITED. Same kit, same
 * minute, two answers, and the customer sees the one that is wrong in the
 * expensive direction.
 *
 * The rule that prevents it: no plan name is UNKNOWN, not unlimited.
 */
require_once dirname(__DIR__) . '/lib/ServicePlan.php';

$pass = 0; $fail = 0;
function t(string $n, $got, $want) { global $pass, $fail;
    if ($got === $want) { $pass++; printf("  ok   %s\n", $n); }
    else { $fail++; printf("  FAIL %s\n       got  %s\n       want %s\n", $n, var_export($got, true), var_export($want, true)); } }
function is_(bool $c, string $m, string $d = ''): void { global $pass, $fail;
    if ($c) { $pass++; echo "  ok   $m\n"; } else { $fail++; echo "  FAIL $m" . ($d ? "\n       $d" : '') . "\n"; } }

echo "Where the plan name comes from\n";
// The invoice label first, because that is the field an operator keeps right —
// it is what the customer reads on their bill.
t('the "Services Plan :" pattern wins',
    ServicePlan::planName(['invoiceLabel' => 'Site Name : IOM Juba KIT302671048 Services Plan : Starlink Priority 6TB',
                           'servicePlanName' => 'Residential'])[0],
    'Starlink Priority 6TB');
t('then a trailing Starlink phrase',
    ServicePlan::planName(['invoiceLabel' => 'blueCARD_Kodok_01 KIT302671048 Starlink Priority 3TB'])[0],
    'Starlink Priority 3TB');
t('then the service plan object',
    ServicePlan::planName(['servicePlan' => ['name' => 'DishNet Business 2TB']])[0], 'DishNet Business 2TB');
t('then its flat name',
    ServicePlan::planName(['servicePlanName' => 'Starlink Residential'])[0], 'Starlink Residential');
t('and the service name last — most likely to be freehand',
    ServicePlan::planName(['name' => 'Site : KIT404246364BX6  Service Plan: Starlink Residential'])[1], 'name');
t('nothing at all is nothing', ServicePlan::planName([])[0], '');

echo "\nThe allowance\n";
t('6TB is 6144 GB',        ServicePlan::capGb('Starlink Priority 6TB'), 6144.0);
t('500GB is 500',          ServicePlan::capGb('DishNet 500GB'), 500.0);
t('a decimal works',       ServicePlan::capGb('1.5TB plan'), 1536.0);
// "6TB Monthly Plan (500GB Priority)" is a 6TB plan with a tranche inside it.
// Taking the first number found would sell them 500GB.
t('the largest figure named wins', ServicePlan::capGb('6TB Monthly Plan (500GB Priority)'), 6144.0);
t('a plan naming no size is unlimited', ServicePlan::capGb('Starlink Unlimited Business'), -1.0);
// The distinction the whole file exists for.
t('and NO plan name is zero, not unlimited', ServicePlan::capGb(''), 0.0);

echo "\nA speed is not an allowance\n";
// Uganda's live service line, read off Starlink's own subscription page:
// "Residential - 100 Mbps". The tier above it is "1 Gbps" — and the letters
// GB are sitting inside Gbps. Read as an allowance, a gigabit-per-second
// line becomes a ONE GIGABYTE monthly cap and the customer is 2,100% over
// on 22 GB of ordinary use.
t('Mbps names no allowance',   ServicePlan::capGb('Residential - 100 Mbps'), -1.0);
t('and Gbps is not 1 GB',      ServicePlan::capGb('Residential - 1 Gbps'), -1.0);
t('nor 2.5 GB',                ServicePlan::capGb('Business 2.5 Gbps'), -1.0);
t('nor is Gbit',               ServicePlan::capGb('Enterprise 10 Gbit/s'), -1.0);
// The unit has to END there. A plural reads as unknown rather than as a
// size — a false negative, which is this class's stated bias.
t('a real size still parses',  ServicePlan::capGb('Priority 2TB/month'), 2048.0);
t('and one in brackets',       ServicePlan::capGb('Plan (500GB)'), 500.0);
t('a plural is not a size',    ServicePlan::capGb('Wholesale 500GBs'), -1.0);

// mask() shares the regex: if a speed tier looks like it "states a size",
// the mask decides the name is ours and shows the customer Starlink's
// internal tier name.
t('a speed tier is still hidden', ServicePlan::mask('Residential - 1 Gbps'), 'Starlink Service Plan');

// And end to end: a speed tier must never come back claiming to be known.
$spd = ServicePlan::fromService(['name' => 'Residential - 1 Gbps']);
t('a speed tier claims no cap',       $spd['cap_gb'], 0.0);
t('and is not unlimited either',      $spd['unlimited'], false);
t('it is simply unknown',             $spd['known'], false);

echo "\nWhat the customer is allowed to see\n";
t('a DishNet plan passes through',   ServicePlan::mask('DishNet Business 2TB'), 'DishNet Business 2TB');
t('so does anything naming a size',  ServicePlan::mask('Starlink Priority 6TB'), 'Starlink Priority 6TB');
t('bare Residential is hidden',      ServicePlan::mask('Residential'), 'Starlink Service');
t('so is bare Unlimited',            ServicePlan::mask('Unlimited'), 'Starlink Service');
t('and an internal product code',    ServicePlan::mask('ss-consumer-2'), 'Starlink Service');
t('and a roam tier',                 ServicePlan::mask('Roam 50GB Regional'), 'Roam 50GB Regional');
t('an unknown name is made generic', ServicePlan::mask('Wholesale Tier 4'), 'Starlink Service Plan');
t('and an empty one too',            ServicePlan::mask(''), 'Starlink Service Plan');

echo "\nRaw for the arithmetic, masked for the eyes\n";
// Mask first and "6TB" would go with it — the allowance has to be read before
// the name is sanitised.
$p = ServicePlan::fromService(['invoiceLabel' => 'Services Plan : Residential 6TB Monthly']);
t('the customer sees a safe name', $p['display'], 'Residential 6TB Monthly');
t('and the cap survived',          $p['cap_gb'], 6144.0);
t('not unlimited',                 $p['unlimited'], false);

echo "\nThe South Sudan customer-page bug, refused\n";
// Exactly the live case: Starlink says "Residential", the customer is on 6TB.
$starlinkOnly = ServicePlan::fromService(['servicePlanName' => 'Residential']);
t('a bare Residential plan is masked', $starlinkOnly['display'], 'Starlink Service');
t('and reports NO allowance',          $starlinkOnly['cap_gb'], 0.0);
is_($starlinkOnly['unlimited'] === false,
    'and is NOT called unlimited — that is the mistake on their customer page');
t('it is simply unknown',              $starlinkOnly['known'], false);

// Uganda would have repeated it on day one: client #7's service really is
// named "Service Plan: Starlink Residential".
$ug = ServicePlan::fromService(['name' => 'Site : KIT404246364BX6  Service Plan: Starlink Residential']);
is_($ug['unlimited'] === false, 'and the live Uganda service name is unknown, not unlimited');
t('with no allowance claimed', $ug['cap_gb'], 0.0);

$none = ServicePlan::fromService([]);
t('a service with no plan at all', $none['cap_gb'], 0.0);
t('is unknown, not unlimited',     $none['unlimited'], false);
t('and shows a generic label',     $none['display'], 'Starlink Service Plan');

// Unlimited must be claimed, not inferred: the plan says the word, or it is
// one of ours that mask() passes through untouched.
$reallyUnlimited = ServicePlan::fromService(['servicePlan' => ['name' => 'DishNet Unlimited Business']]);
t('a plan that really is unlimited says so', $reallyUnlimited['unlimited'], true);
t('with no cap',                              $reallyUnlimited['cap_gb'], 0.0);
t('and it is known',                          $reallyUnlimited['known'], true);
t('the word alone is enough',
    ServicePlan::fromService(['servicePlanName' => 'Starlink Unlimited Business'])['unlimited'], true);
t('a DishNet plan naming no size is ours to call unlimited',
    ServicePlan::fromService(['servicePlanName' => 'DishNet Business'])['unlimited'], true);
t('a wholesale code is not',
    ServicePlan::fromService(['servicePlanName' => 'ss-consumer-2'])['unlimited'], false);
t('a capped plan is known and not unlimited',
    ServicePlan::fromService(['servicePlanName' => 'DishNet 500GB'])['known'], true);

echo "\nReading the kit written on a service\n";
// Every spelling South Sudan accepts, normalised.
foreach (['starlinkDetails', 'kit_number', 'Starlink Kit', 'KIT-NO', 'kit'] as $key) {
    t("'{$key}' is recognised",
        ServicePlan::kitsOnService(['attributes' => [['key' => $key, 'value' => 'KIT404246364BX6']]]),
        ['KIT404246364BX6']);
}
t('several kits on one service',
    ServicePlan::kitsOnService(['attributes' => [['key' => 'starlinkDetails',
        'value' => 'KIT404246364BX6, kit409033426kfr']]]),
    ['KIT404246364BX6', 'KIT409033426KFR']);
t('a nested customAttribute key works too',
    ServicePlan::kitsOnService(['attributes' => [['customAttribute' => ['key' => 'starlinkDetails'],
        'value' => 'KIT111111111']]]),
    ['KIT111111111']);
t('an unrelated attribute is ignored',
    ServicePlan::kitsOnService(['attributes' => [['key' => 'router_ip', 'value' => 'KIT999']]]), []);
// It must look like a kit. A customer name in the field is not a serial.
t('a value that is not a serial is ignored',
    ServicePlan::kitsOnService(['attributes' => [['key' => 'kit', 'value' => 'African skies Ltd']]]), []);
t('and no attributes at all', ServicePlan::kitsOnService([]), []);

echo "\nFinding the attribute to write into\n";
t('by key',  ServicePlan::kitAttributeId([['id' => 9, 'key' => 'starlinkDetails', 'attributeType' => 'service']]), 9);
t('by name', ServicePlan::kitAttributeId([['id' => 4, 'name' => 'Kit Number', 'attributeType' => 'service']]), 4);
t('a client attribute is not a service one',
    ServicePlan::kitAttributeId([['id' => 3, 'key' => 'starlinkDetails', 'attributeType' => 'client']]), 0);
t('and none at all is zero', ServicePlan::kitAttributeId([]), 0);

echo "\n  {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
