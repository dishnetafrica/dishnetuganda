<?php
/**
 * test_price_answer.php — a price you have is a price you quote.
 *
 * The sales number was loading "7 plan(s), 5 hardware item(s)" on every single
 * message and still answering "I can't provide details on final pricing
 * without checking with our team". 124 replies in a day, and the people asking
 * what Starlink costs were told to wait for a human who never came.
 *
 * The cause was one instruction: if a one-time price they need is not in
 * HARDWARE, say you will confirm it and hand over. Getting started needs a kit
 * AND an installation, so a single gap in HARDWARE suppressed the kit price
 * too — the model was holding the answer while apologising for not having it.
 *
 * The line between the two failures is narrow and this test guards both sides:
 * quote every price you hold, and never invent the one you don't.
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
function hasnt(string $m, string $hay, string $needle): void {
    is_(strpos($hay, $needle) === false, $m, 'prompt still contains: ' . $needle);
}

$brain = new DishNetAiBrain(['claude_api_key' => 'k']);
$sales = ['channel' => 'sales', 'medium' => 'whatsapp', 'customer' => null, 'history' => [],
          'message' => 'how much to get started?'];

// What the sales number actually had loaded while it was refusing to answer.
$catalogue = [
    'plans' => [
        ['name' => 'Residential Lite', 'price' => 150000.0, 'period' => 'month'],
        ['name' => 'Residential',      'price' => 250000.0, 'period' => 'month'],
    ],
    'hardware' => [
        ['name' => 'Starlink Standard Kit',      'price' => 1500000.0],
        ['name' => 'Professional Installation',  'price' => 200000.0],
    ],
];

echo "\nThe instruction that caused the refusals is gone\n";
$p = $brain->promptPreview($sales + ['products' => $catalogue]);
hasnt('no blanket hand-over on a missing one-time price', $p, 'say you will confirm it and hand over');
hasnt('and no "only the confirmed items" gate',           $p, 'ONLY the confirmed one-time items');

echo "\nWhat replaced it tells the model to answer\n";
has('quote what you have',        $p, 'give them the prices you DO have');
has('one-time total from HARDWARE', $p, 'Add up the one-time items from HARDWARE');
has('monthly stated separately',  $p, "state the chosen plan's monthly price");

echo "\nOne missing price no longer suppresses the others\n";
// The whole bug in one assertion: a gap in one item must not silence the rest.
has('quote everything else anyway',   $p, 'quote everything else anyway');
has('name only the item in question', $p, 'name just that item as the one you are confirming');
has('withholding is called out as the failure', $p, 'NEVER withhold a price you have');

echo "\nBut the guard against inventing one is still there\n";
// Loosening the refusal must not become permission to guess. Uganda pricing is
// uCRM's to state; a plausible number is worse than an honest gap.
has('the missing price is never estimated', $p, 'Never estimate the missing');
has('no invented delivery/customs/taxes',   $p, 'Never add delivery, customs, taxes');
has('monthly and one-time never blended',   $p, 'MONEY IS TWO SEPARATE THINGS');
has('only real plans at real prices',       $p, 'real plan from PLANS, quoted at its real price');

echo "\nWith no catalogue at all it still refuses — that gap is real\n";
// No data is the one case where "I'll confirm and come back" is the correct
// answer. The fix must not turn an empty catalogue into invention.
$empty = $brain->promptPreview($sales);
has('no PLANS means quote nothing',    $empty, 'Do not name any plan or price');
has('no HARDWARE means confirm first', $empty, 'say you will confirm and take their details');

echo "\nThe individually-unpriced item is still flagged in the data\n";
// uCRM can hold a product with a null price. That single item says "confirm",
// while everything around it keeps its number.
$oneGap = $catalogue;
$oneGap['hardware'][] = ['name' => 'Roof Mount', 'price' => null];
$g = $brain->promptPreview($sales + ['products' => $oneGap]);
has('the unpriced item is marked', $g, 'price not listed (say you will confirm)');
has('the kit keeps its price',     $g, 'Starlink Standard Kit');
has('and so does installation',    $g, 'Professional Installation');

echo "\nSouth Sudan's support role is untouched by any of this\n";
$sup = $brain->promptPreview(['channel' => 'support', 'message' => 'internet slow',
                              'customer' => null, 'history' => []]);
hasnt('the sales pricing rules stay off support', $sup, 'give them the prices you DO have');

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
