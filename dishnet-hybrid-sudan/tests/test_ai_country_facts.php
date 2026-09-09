<?php
/**
 * test_ai_country_facts.php — answers that belong to one country only.
 *
 * The assistant's business facts were written for the South Sudan operation
 * and were reaching Ugandan customers unchanged, live on WhatsApp: a walk-in
 * office in Juba, kits flown to Renk and crossing at the Joda border, payment
 * at a Sudanese URL — on a box whose own quotations ask for a bank transfer to
 * Ecobank Uganda.
 *
 * Nothing was broken. The answers were simply another country's, which is the
 * harder kind of wrong to notice.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/lib/DishNetAiBrain.php';

$pass = 0; $fail = 0;
function ok(string $m): void  { global $pass; $pass++; echo "  ok   {$m}\n"; }
function bad(string $m, string $d=''): void { global $fail; $fail++; echo "  FAIL {$m}\n"; if($d)echo "       {$d}\n"; }
function is_(bool $c, string $m, string $d=''): void { $c ? ok($m) : bad($m, $d); }

function prompt(array $config): string
{
    $b = new DishNetAiBrain($config);
    return $b->promptPreview(['channel' => 'sales', 'message' => 'Where is your office?']);
}

echo "\nUnset, the Sudan install is untouched\n";
$sudan = prompt([]);
is_(strpos($sudan, 'Tomping Sector 4, American Embassy Road') !== false,
    'the Juba office address is still stated word for word');
is_(strpos($sudan, 'flown to Renk') !== false, 'as is the Renk and Joda border route');
is_(strpos($sudan, 'https://dishnetafrica.com/pay.html') !== false, 'and the payment page');
is_(strpos($sudan, '- OFFICE:') !== false && strpos($sudan, '- DELIVERY TO SUDAN:') !== false,
    'under their original headings');

echo "\nSet, the operator's own answer replaces it entirely\n";
$ug = prompt([
    'ai_fact_office'  => 'Our office is in Kampala, Uganda.',
    'ai_fact_payment' => 'Customers pay by bank transfer to Ecobank Uganda, '
                       . 'using the reference on their invoice.',
]);
is_(strpos($ug, 'Our office is in Kampala, Uganda.') !== false, 'the new office answer is used');
is_(strpos($ug, 'Tomping Sector 4') === false,
    'and no trace of the Juba address survives — not appended, not beside it');
is_(strpos($ug, 'Ecobank Uganda') !== false, 'the real payment method is stated');
is_(strpos($ug, 'dishnetafrica.com/pay.html') === false, 'the Sudanese payment page is gone');
is_(strpos($ug, 'DELIVERY TO SUDAN') !== false,
    'a fact left unset keeps its default — one country at a time, deliberately');

echo "\nThe escalation mechanism survives a rewritten fact\n";
is_(strpos($ug, 'ESCALATE') !== false,
    'so a custom answer can still hand over when it does not cover the question');

echo "\n\"omit\" says nothing rather than saying the wrong country's answer\n";
$omitted = prompt(['ai_fact_delivery' => 'omit', 'ai_fact_office' => 'OMIT']);
is_(strpos($omitted, 'flown to Renk') === false, 'the delivery route is not mentioned at all');
is_(strpos($omitted, 'Tomping Sector 4') === false, 'nor the office address');
is_(strpos($omitted, 'DELIVERY') === false, 'not even the heading, which would invite a guess');
is_(strpos($omitted, 'dishnetafrica.com/pay.html') !== false,
    'while an untouched fact is still there');

echo "\nEvery channel reads the same facts\n";
// There is no separate knowledge base for email. A fact fixed for WhatsApp is
// fixed for email drafts in the same moment, which is the entire reason the
// email assistant was built on this brain rather than beside it.
$b = new DishNetAiBrain(['ai_fact_office' => 'Our office is in Kampala, Uganda.']);
foreach ([['channel' => 'sales'],
          ['channel' => 'support', 'medium' => 'email'],
          ['channel' => 'account', 'transport' => 'web']] as $ctx) {
    $p = $b->promptPreview($ctx + ['message' => 'Where are you?']);
    is_(strpos($p, 'Kampala, Uganda') !== false && strpos($p, 'Tomping Sector 4') === false,
        'channel ' . $ctx['channel'] . (isset($ctx['medium']) ? ' by email' : '')
        . ' gets the corrected answer');
}

echo "\nThe Uganda preset writes no numbers of its own\n";
// Account numbers are configured once, for the quotation and invoice
// templates. One system telling customers two different account numbers is
// worse than a system that tells them none.
$toolSrc = (string)file_get_contents($root . '/tools/ai_facts.php');
is_(strpos($toolSrc, "email_bank_account_ugx") !== false,
    'the payment answer is composed from the configured bank details');
is_(preg_match('/[\'"]\d{8,}[\'"]/', $toolSrc) === 0,
    'and no account number is written into the tool itself');
is_(strpos($toolSrc, 'not going to be typed in here') !== false,
    'missing bank details stop the preset rather than inviting a guess');
is_(strpos($toolSrc, "\$updates['ai_fact_office']") === false,
    'the office is never set by the preset — nobody can derive an address');

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
