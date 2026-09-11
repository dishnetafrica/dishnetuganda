<?php
/**
 * test_total_to_get_connected.php — the upfront figure, and the two tax guesses.
 *
 * "How much will I pay to get Starlink installed?" was being answered with the
 * kit price, or with kit plus installation, and nothing else — so a customer
 * agreed to a number that was not the number.
 *
 * The list itself is the easy half. The dangerous half is tax, because there
 * are TWO wrong answers and they are equal and opposite: adding VAT to a
 * price that already includes it overcharges the customer by the rate, and
 * assuming inclusive when it is exclusive undercharges DishNet by the same.
 * Nothing in the catalogue mapper carries the treatment — it keeps id, name,
 * price and unit, and drops every tax field uCRM returns — so the assistant
 * has no basis for either answer and must not produce one.
 *
 * The other quiet failure this covers: a total with a missing line reads as
 * complete. A regulatory charge nobody listed is not zero; it is unknown, and
 * saying so is the difference between a quote and a surprise.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/lib/DishNetAiBrain.php';

$pass = 0; $fail = 0;
function ok(string $m): void  { global $pass; $pass++; echo "  ok   {$m}\n"; }
function bad(string $m, string $d=''): void { global $fail; $fail++; echo "  FAIL {$m}\n"; if($d)echo "       {$d}\n"; }
function is_(bool $c, string $m, string $d=''): void { $c ? ok($m) : bad($m, $d); }
function has(string $m, string $h, string $n): void { is_(stripos($h,$n)!==false,$m,'missing: '.$n); }

$ctx = ['channel' => 'sales', 'medium' => 'whatsapp', 'customer' => null, 'history' => [],
        'message' => 'how much will I pay to get Starlink installed?'];
$p = (new DishNetAiBrain(['claude_api_key' => 'k']))->promptPreview($ctx);

echo "\nThe kit price alone is not the answer\n";
has('the rule exists',            $p, 'WHAT IT COSTS TO GET CONNECTED');
has('and says so explicitly',     $p, 'never answer with the kit price alone');
has('named as the usual surprise',$p, 'surprised later');

echo "\nIt is a list, then a labelled total, then the monthly separately\n";
has('one line each',           $p, 'one line each');
has('the kit',                 $p, 'the kit, the installation');
has('the total is labelled',   $p, 'TOTAL TO GET CONNECTED');
has('monthly comes after it',  $p, 'on its own line, after the total');
has('and never inside it',     $p, 'never inside it');
has('so neither can be read as the other', $p, 'read one figure as the other');

echo "\nA charge we do not have is named, not dropped\n";
// The quiet one: a total missing a line still looks like a total.
has('missing lines are named',   $p, 'NEVER DROPPED');
has('a UCC charge is the example',$p, 'regulatory or UCC');
has('not invented',              $p, 'do not invent it');
has('not treated as zero',       $p, 'do not treat it as zero');
has('and why that matters',      $p, 'reads as complete and is not');

echo "\nTax is never calculated in a chat\n";
has('the rule is absolute',        $p, 'TAX IS NEVER YOURS TO CALCULATE');
has('no rate is stated',           $p, 'never state a VAT amount or a rate you');
has('no percentage is worked out', $p, 'work one out as a percentage yourself');

echo "\nAnd neither tax assumption is allowed — they are equal and opposite\n";
has('not assumed inclusive', $p, 'never assume the prices you hold are tax-inclusive');
has('nor exclusive',         $p, 'never assume they are');
has('the cost is symmetric', $p, 'opposite directions');
has('so the quotation confirms it', $p, 'quotation confirms the tax treatment');

echo "\nNo rate is written anywhere in the rule\n";
// Uganda VAT is 18%. The moment that number appears here it is a second tax
// table, and it will be wrong the year it changes.
foreach (['18%', '18 %', '0.18'] as $rate) {
    is_(strpos($p, $rate) === false, 'no "' . $rate . '" in the prompt',
        'a rate written here is a rate nobody updates');
}

echo "\nThe knowledge rows say the same, and carry no figures\n";
$seed = json_decode((string)file_get_contents($root . '/tools/knowledge_seed.json'), true);
$by = [];
foreach ($seed['items'] as $r) $by[$r['item_key']] = $r;
foreach (['TOTAL_TO_GET_CONNECTED', 'RULE_NEVER_CALCULATE_TAX'] as $k) {
    is_(isset($by[$k]), $k . ' exists');
    $body = (string)($by[$k]['answer'] ?? '') . ' ' . (string)($by[$k]['wa_answer'] ?? '');
    is_(!preg_match('/\b\d{1,2}\s*%/', $body), $k . ' states no tax rate');
    is_(!preg_match('/UGX\s*[\d,]{4,}/i', $body), $k . ' states no price');
}
has('the total row keeps monthly out of the total',
    (string)$by['TOTAL_TO_GET_CONNECTED']['answer'], 'never folded into the total');
has('and the rule covers levies too',
    (string)$by['RULE_NEVER_CALCULATE_TAX']['answer'], 'a levy or any statutory figure');

echo "\nThe existing price discipline is untouched\n";
// Yesterday's fix: quote what you hold even when one line is missing. The new
// rule must not have reintroduced withholding.
has('still quotes what it has',   $p, 'quote everything else anyway');
has('still never estimates',      $p, 'Never estimate the missing');
has('and monthly vs one-time survives', $p, 'MONEY IS TWO SEPARATE THINGS');

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
