<?php
/**
 * test_never_invent_a_link.php — the AI sent a customer a map pin that does not exist.
 *
 * From a live conversation at 2:40 in the morning:
 *
 *   customer: "Where is your office"
 *   assistant: "… Would you like us to share the directions/location pin?"
 *   customer: "Yes please"
 *   assistant: "I'll share the location pin. Here it is: https://goo.gl/maps/5Lz1F1ttZpo"
 *
 * That link is invented. Nobody can check a short link by looking at it — they
 * find out by driving somewhere.
 *
 * Two causes, and both are fixed here.
 *
 * OFFICE_LOCATION ended "offer to share the directions/location pin" and
 * supplied no pin. The row instructed an offer it had no data to fulfil, so
 * when the customer accepted, something had to be produced.
 *
 * And absolute rule 1 listed prices, speeds, allowances and balances — nothing
 * on it covered a URL. A fabricated link is more dangerous than a fabricated
 * price precisely because it looks unremarkable.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/lib/DishNetAiBrain.php';

$pass = 0; $fail = 0;
function ok(string $m): void  { global $pass; $pass++; echo "  ok   {$m}\n"; }
function bad(string $m, string $d=''): void { global $fail; $fail++; echo "  FAIL {$m}\n"; if($d)echo "       {$d}\n"; }
function is_(bool $c, string $m, string $d=''): void { $c ? ok($m) : bad($m, $d); }
function has(string $m, string $h, string $n): void { is_(stripos($h,$n)!==false,$m,'missing: '.$n); }

$ctx = ['channel' => 'sales', 'medium' => 'whatsapp', 'customer' => null,
        'history' => [], 'message' => 'where is your office?'];

echo "\nA link is now a fact, subject to the same rule as a price\n";
$p = (new DishNetAiBrain(['claude_api_key' => 'k']))->promptPreview($ctx);
has('the rule exists',              $p, 'A LINK, ADDRESS OR PHONE NUMBER IS A FACT');
has('URLs and map pins are named',  $p, 'Never write a URL, a map pin');
has('so are addresses and numbers', $p, 'a street address or a phone number');
has('word for word from data',      $p, 'word for word in your DATA');
has('never reconstructed from memory', $p, 'reconstruct one from memory');
has('and the consequence is stated', $p, 'sends a customer across a city');

echo "\nWith no pin on file, it is told it has none\n";
// The half that produced the invention: an offer with nothing behind it.
has('the absence is explicit',   $p, 'we have none on file');
has('and writing one is forbidden', $p, 'do NOT write one');
has('a colleague sends it instead', $p, 'a colleague will send it');

echo "\nWith a pin configured, it is sent verbatim\n";
$PIN = 'https://maps.app.goo.gl/vKVyVWr8PsF1TpTg9';
$on  = (new DishNetAiBrain(['claude_api_key' => 'k', 'ai_fact_location_pin' => $PIN]))
       ->promptPreview($ctx);
has('the pin is in the prompt', $on, $PIN);
has('character for character',  $on, 'character for character');
has('never shortened or tidied',$on, 'Never shorten it');
is_(strpos($on, 'we have none on file') === false,
    'and the "no pin" instruction is gone', 'both would be a contradiction');

echo "\nThe office row no longer promises what it cannot supply\n";
$seed = json_decode((string)file_get_contents($root . '/tools/knowledge_seed.json'), true);
$by = [];
foreach ($seed['items'] as $r) $by[$r['item_key']] = $r;
$office = (string)($by['OFFICE_LOCATION']['answer'] ?? '');
is_(stripos($office, 'offer to share the directions/location pin.') === false,
    'the unconditional offer is gone',
    'it is what the customer accepted, leaving nothing to send');
has('it is conditional on having one', $office, 'Only offer to send a pin');
has('and forbids writing one',         $office, 'never write a map link');

echo "\nAnd it is a standing conduct rule, not only a prompt line\n";
$rule = (string)($by['RULE_NEVER_INVENT_A_LINK']['answer'] ?? '');
is_($rule !== '', 'RULE_NEVER_INVENT_A_LINK exists');
has('a link obeys the price rule', $rule, 'same rule as a price');
has('and why it is worse',         $rule, 'more dangerous than a fabricated price');

echo "\nNo invented link is carried anywhere in our own data\n";
// The one from the transcript must never appear as though it were real.
foreach (['tools/knowledge_seed.json', 'lib/DishNetAiBrain.php'] as $f) {
    $src = (string)file_get_contents($root . '/' . $f);
    is_(strpos($src, 'goo.gl/maps/5Lz1F1ttZpo') === false,
        $f . ' does not contain the fabricated pin');
}

echo "\nSouth Sudan keeps its own facts\n";
// localFacts is shared; the pin instruction must not change what Sudan says
// about its office, delivery or payment.
$sudan = (new DishNetAiBrain(['claude_api_key' => 'k']))->promptPreview(
    ['channel' => 'support', 'medium' => 'whatsapp', 'customer' => null,
     'history' => [], 'message' => 'hi']);
has('the Juba office line survives', $sudan, 'Juba');
has('and the delivery route',        $sudan, 'Joda border');

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
