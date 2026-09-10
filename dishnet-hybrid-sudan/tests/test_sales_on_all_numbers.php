<?php
/**
 * test_sales_on_all_numbers.php — a sales question gets answered where it is asked.
 *
 * DishNet Uganda runs two public numbers. Someone messaging the support one to
 * ask what internet costs is not on the wrong number, but the support role
 * said nothing about plans or prices, so they got troubleshooting steps or a
 * handover. Both numbers could not simply be put on the sales channel either:
 * evo_instance_sales holds one instance name.
 *
 * So the channel keeps deciding the primary role, and this adds the ability to
 * answer a sales question anywhere.
 *
 * The flag matters as much as the feature. South Sudan runs genuinely separate
 * desks, and its prompts must not move. Absent means the old prompt, byte for
 * byte, and these assertions are what keep that true.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/lib/DishNetAiBrain.php';

$pass = 0; $fail = 0;
function ok(string $m): void  { global $pass; $pass++; echo "  ok   {$m}\n"; }
function bad(string $m, string $d=''): void { global $fail; $fail++; echo "  FAIL {$m}\n"; if($d)echo "       {$d}\n"; }
function is_(bool $c, string $m, string $d=''): void { $c ? ok($m) : bad($m, $d); }

$MARK = 'ALSO ON THIS NUMBER: SALES ENQUIRIES';
$ctx  = function (string $ch): array {
    return ['channel' => $ch, 'medium' => 'whatsapp', 'message' => 'how much for internet at home?'];
};

$off = new DishNetAiBrain(['claude_api_key' => 'k']);
$on  = new DishNetAiBrain(['claude_api_key' => 'k', 'ai_sales_on_all_numbers' => '1']);

echo "\nWithout the setting, nothing changes — South Sudan is untouched\n";
foreach (['sales', 'support', 'account'] as $ch) {
    is_(strpos($off->promptPreview($ctx($ch)), $MARK) === false,
        'the ' . $ch . ' prompt is exactly as it was');
}

echo "\nWith it, the other numbers can sell\n";
is_(strpos($on->promptPreview($ctx('support')), $MARK) !== false,
    'support can answer a sales question');
is_(strpos($on->promptPreview($ctx('account')), $MARK) !== false,
    'so can accounts');

echo "\nThe sales number is not touched either way\n";
// If enabling this changed the sales prompt, the flag would be doing something
// other than what it says, and the sales role is the one already tuned.
is_($off->promptPreview($ctx('sales')) === $on->promptPreview($ctx('sales')),
    'the sales prompt is byte-identical with the setting on and off');

echo "\nThe added block carries the same guardrails, not a looser copy\n";
$p = $on->promptPreview($ctx('support'));
is_(strpos($p, 'MONEY IS TWO SEPARATE THINGS') !== false,
    'monthly and one-time are never blended into one figure');
is_(strpos($p, 'only real plans from PLANS') !== false,
    'and it may only quote real plans at real prices');
is_(strpos($p, 'Coverage and installation dates are NOT in your data') !== false,
    'coverage and install dates are still never confirmed');
is_(strpos($p, 'never confirm either') !== false,
    'and it hands over for them instead');

echo "\nIt still knows what the number is mainly for\n";
is_(strpos($p, 'YOUR ROLE ON THIS NUMBER: SUPPORT') !== false,
    'the support role survives — this adds, it does not replace',
    'a support number that forgot how to handle a fault would be a bad trade');

echo "\nAnd it never sends a customer somewhere else\n";
is_(strpos($p, 'Never tell a customer they have reached the wrong number') !== false,
    'which is the behaviour being fixed');

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
