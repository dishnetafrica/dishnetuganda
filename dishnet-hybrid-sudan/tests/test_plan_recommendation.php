<?php
/**
 * test_plan_recommendation.php — the default is the higher-capacity
 * Residential plan, and a customer who picks Business 50 gets told what it is.
 *
 * ── The conversation this comes from ────────────────────────────────────
 *
 * 18 September, a real customer: home, ten people, asked the price. The
 * assistant recommended Residential and did it well. That path was never the
 * problem.
 *
 * The problem is the other path. Every rule in qualification() governs what
 * the assistant RECOMMENDS; none of them covered a customer who arrives
 * having already chosen. "How much is Business 50?" matched nothing at all,
 * so the assistant quoted it — and Business 50 is the cheapest line on the
 * list, the number reads as a speed, and the 50 GB priority block runs out in
 * days on a busy household. People were buying it on price and discovering
 * the rest later.
 *
 * So these assertions are about the consequence arriving BEFORE the price,
 * and about it being stated in terms a customer can act on: not "behaves like
 * standard data", which is true and persuades nobody, but the 1 Mbps figure
 * and the fact that more data restores the speed.
 *
 * The last section is the one that matters most. The operator's decision is
 * to lead with Residential everywhere, and that is theirs to make. It does
 * NOT extend to telling a customer that remote CCTV works on a plan sitting
 * behind CGNAT. Selling the plan is commercial; claiming the capability is
 * the assistant inventing network availability.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$pass = 0; $fail = 0;
function ok(string $m): void  { global $pass; $pass++; echo "  ok   {$m}\n"; }
function bad(string $m, string $d=''): void { global $fail; $fail++; echo "  FAIL {$m}\n"; if($d)echo "       {$d}\n"; }
function is_(bool $c, string $m, string $d=''): void { $c ? ok($m) : bad($m, $d); }

require_once $root . '/lib/DishNetAiBrain.php';

$ON  = ['ai_provider' => 'openai', 'openai_api_key' => 'k', 'ai_qualification' => '1'];
$CTX = ['channel' => 'sales', 'message' => 'how much is business 50?', 'identity_state' => 'unknown'];
$p   = (new DishNetAiBrain($ON))->promptPreview($CTX);

echo "\n1. A customer who picks Business themselves is no longer just quoted\n";
is_(stripos($p, 'CHOOSING A BUSINESS PLAN THEMSELVES') !== false,
    'the self-selection case exists at all',
    'this is the gap: every other rule is about what the assistant recommends');
is_(stripos($p, 'do NOT simply quote it') !== false, 'and it is told not to just quote it');
is_(stripos($p, 'BEFORE any price') !== false,
    'the consequence comes before the price, not after it');

echo "\n2. In terms a customer can act on\n";
is_(stripos($p, '1 Mbps') !== false,
    'the actual speed after the block is stated',
    'the knowledge base says "behaves like standard data", which persuades nobody');
is_(stripos($p, 'until more data is bought') !== false, 'and that buying data restores it');
is_(stripos($p, 'PRIORITY DATA') !== false, 'the tier numbers are named as data, not speed');
is_(stripos($p, 'not speeds') !== false, 'explicitly not speeds');
is_(stripos($p, '50 GB block in days') !== false, 'and how fast a busy site exhausts one');

echo "\n3. Then it stops arguing\n";
// A customer told the truth who still wants the plan has decided. An
// assistant that keeps pushing is a worse experience than one that never
// warned, and it is not ours to overrule.
is_(stripos($p, 'without arguing further') !== false, 'an informed customer is quoted, not lectured');
is_(stripos($p, 'it is their money') !== false, 'and the reason is stated');

echo "\n4. The higher-capacity Residential plan is the default answer\n";
is_(stripos($p, 'HIGHER-CAPACITY RESIDENTIAL PLAN IS YOUR DEFAULT ANSWER') !== false,
    'it is the default, not merely preferred');
is_(stripos($p, 'never as the opening') !== false,
    'the cheaper plan is the alternative underneath, not the opening');

echo "\n5. The Mini is the kit led with — but never made compulsory\n";
is_(stripos($p, 'LEAD WITH THE MINI KIT') !== false, 'the Mini is offered first');
is_(stripos($p, 'A PLAN NEVER REQUIRES A PARTICULAR KIT') !== false,
    'and the separation rule survives the change',
    'leading with a kit is not the same as a plan requiring one');

echo "\n6. Leading with Residential NEVER becomes a claim that remote access works\n";
// The operator's commercial decision is to recommend Residential everywhere.
// It does not extend to a false statement about CGNAT, and this is the
// assertion that stops a future edit quietly turning one into the other.
is_(stripos($p, 'never claim it provides remote access') !== false,
    'the assistant may recommend Residential but not claim remote access on it');
is_(stripos($p, 'quoted separately') !== false,
    'the public IP is named as a separate quotation');
is_(stripos($p, 'fact about the network, not a preference') !== false,
    'and the reason it is not negotiable is stated in the prompt itself');

echo "\n7. Off unless switched on — shipping is not enabling\n";
$off = (new DishNetAiBrain(['ai_provider' => 'openai', 'openai_api_key' => 'k']))->promptPreview($CTX);
is_(stripos($off, 'CHOOSING A BUSINESS PLAN THEMSELVES') === false,
    'no qualification block without ai_qualification');
is_(stripos($off, '1 Mbps') === false, 'and no 1 Mbps claim anywhere near it');

echo "\n8. Support is still not a selling channel\n";
$sup = (new DishNetAiBrain($ON))->promptPreview(
    ['channel' => 'support', 'message' => 'hi', 'identity_state' => 'identified']);
is_(stripos($sup, 'HIGHER-CAPACITY RESIDENTIAL PLAN IS YOUR DEFAULT') === false,
    'the recommendation rules stay off the support channel');

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
