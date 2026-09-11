<?php
/**
 * test_coverage_and_scale.php — three answers a real customer was given wrong.
 *
 * From a live conversation with a trading centre in Luuka District:
 *
 *   "For coverage i meant radius from the service room"
 *   → "The coverage radius of the Starlink Standard Kit is approximately
 *      297 square meters"
 *
 * An area reported as a radius, from a figure describing a domestic router,
 * to a customer planning public wifi. The caveat was in the prompt and was not
 * strong enough to stop the number being used as an answer it cannot be.
 *
 *   "can a standard kit allow 100 connections at the same time"
 *   → a hedge about bandwidth, and no mention that a hundred users needs
 *     access points and a designed network rather than a plan.
 *
 *   And before either: Business 50 quoted to that site, because BUSINESS_PLANS
 *   said Business suits "heavy users" — so a trading centre expecting a
 *   hundred people read as heavy, and got a 50 GB priority block it would
 *   exhaust in hours, with no public-IP requirement anywhere in the thread.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/lib/DishNetAiBrain.php';
require_once $root . '/lib/HardwareKnowledge.php';

$pass = 0; $fail = 0;
function ok(string $m): void  { global $pass; $pass++; echo "  ok   {$m}\n"; }
function bad(string $m, string $d=''): void { global $fail; $fail++; echo "  FAIL {$m}\n"; if($d)echo "       {$d}\n"; }
function is_(bool $c, string $m, string $d=''): void { $c ? ok($m) : bad($m, $d); }
function has(string $m, string $hay, string $n): void { is_(stripos($hay,$n)!==false,$m,'missing: '.$n); }

$ctx = ['channel' => 'sales', 'medium' => 'whatsapp', 'customer' => null,
        'history' => [], 'message' => 'what is the coverage?'];
$brain = new DishNetAiBrain(['claude_api_key' => 'k', 'ai_qualification' => '1',
                             'ai_hardware_expert' => '1']);
$p = $brain->promptPreview($ctx);

echo "\nThe three coverage questions are separated\n";
has('satellite coverage is answerable', $p, 'Whether Starlink reaches their part of the country');
has('a specific site is not',           $p, 'never promise a particular roof, compound or trading');
has('and only a survey settles it',     $p, 'only a survey');
has('signal reach is a Wi-Fi question', $p, 'is a THIRD question and it is about');
has('never answered with a dish figure',$p, 'Never answer it with a dish');

echo "\nThe area figure can no longer become a radius\n";
$hw = HardwareKnowledge::promptBlock($root . '/tools/starlink_hardware.json', '2026-09-11');
has('it is labelled an area', $hw, 'Wi-Fi area');
has('and explicitly not a radius', $hw, 'never a RADIUS');
has('not an answer to how far it reaches', $hw, 'how far does the signal reach');
has('and never sizes a public site', $hw, 'never use it to size a trading centre');
foreach (['hotspot', 'school', 'church', 'hotel'] as $place) {
    has('named: ' . $place, $hw, $place);
}
has('those need access points', $hw, 'need access points');

echo "\nMany users is a network question before it is a plan question\n";
has('the rule exists',            $p, 'MANY PEOPLE ON ONE CONNECTION');
has('one kit does not serve 100', $p, 'one kit alone does not serve fifty or a hundred');
has('it needs a designed network',$p, 'access points and someone to size it');
has('and goes to a site assessment', $p, 'site assessment');
// Single quotes keep a backslash, so the needle is written without escapes.
has('no invented user count',     $p, 'with a number you have not been given');

echo "\nBeing busy is not a reason to buy a public IP\n";
// The row that produced the wrong quote: "ideal for ... heavy users".
$seed = json_decode((string)file_get_contents($root . '/tools/knowledge_seed.json'), true);
$by = [];
foreach ($seed['items'] as $r) $by[$r['item_key']] = $r;
$bp = (string)($by['BUSINESS_PLANS']['answer'] ?? '');
is_(stripos($bp, 'heavy users') === false,
    '"heavy users" is gone from the Business description',
    'a trading centre expecting a hundred people reads as heavy');
has('the tiers are data, not speed',   $bp, 'NOT speeds');
has('nor a number of people',          $bp, 'NOT how many people can connect');
has('50 GB is called small',           $bp, '50 GB is a small block');
has('the reason for Business is the public IP', $bp, 'reason to be on Business at all is the public IP');
has('and a busy site without one goes residential', $bp, 'trading centre selling wifi');

echo "\nSize never decides the plan\n";
$rule = (string)($by['RULE_SIZE_IS_NOT_A_PLAN']['answer'] ?? '');
is_($rule !== '', 'the rule exists');
has('never moved up for sounding large', $rule, 'because they sound large');
has('and not quoted Business without the requirement', $rule, 'do not quote Business, whatever size');

echo "\nAnd the knowledge for the two questions that were answered wrong\n";
foreach (['MANY_USERS_HOTSPOT' => 'a site where many people connect',
          'WIFI_VS_SATELLITE_COVERAGE' => 'area coverage vs signal reach'] as $k => $what) {
    is_(isset($by[$k]), 'there is an approved answer for: ' . $what);
}
$cov = (string)($by['WIFI_VS_SATELLITE_COVERAGE']['answer'] ?? '');
has('the area figure is never a radius', $cov, 'never a radius');
has('and never sizes a hotspot',         $cov, 'used to size a hotspot');
$many = (string)($by['MANY_USERS_HOTSPOT']['answer'] ?? '');
has('no simultaneous-user number is quoted', $many, 'Never quote a number of simultaneous users');
has('and not answered with the coverage area', $many, "Never answer it with the router's coverage area");

echo "\nNone of it names a price or a plan\n";
// The catalogue is the only place either lives.
foreach (['BUSINESS_PLANS', 'MANY_USERS_HOTSPOT', 'WIFI_VS_SATELLITE_COVERAGE',
          'RULE_SIZE_IS_NOT_A_PLAN'] as $k) {
    $body = (string)($by[$k]['answer'] ?? '') . ' ' . (string)($by[$k]['wa_answer'] ?? '');
    is_(!preg_match('/UGX\s*[\d,]{4,}/i', $body), $k . ' carries no price');
}

echo "\nAnd South Sudan is still untouched\n";
$off = new DishNetAiBrain(['claude_api_key' => 'k']);
$q = $off->promptPreview($ctx);
is_(strpos($q, 'MANY PEOPLE ON ONE CONNECTION') === false,
    'the many-users rule is behind ai_qualification');
is_(strpos($q, 'Wi-Fi area') === false, 'and the hardware block behind its own flag');

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
