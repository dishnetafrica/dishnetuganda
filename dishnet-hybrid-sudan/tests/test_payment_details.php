<?php
/**
 * test_payment_details.php — the bot may give the bank account, and only ours.
 *
 * 17 September, 00:59. A customer ready to buy asked "will pay in which
 * number" and was told "I can't provide payment details directly. Please
 * refer to the invoice" — for an order that did not exist yet, so there was
 * no invoice to refer to. The sale stopped at the last step.
 *
 * The refusal was not a bug. It is the DEFAULT text of the ai_fact_payment
 * business fact, written for South Sudan, which says never to share bank
 * details and points at a South Sudan pay page. Nothing could change it:
 * the key was absent from the uCRM Configuration screen, from the Engage
 * tab and from set_config.php.
 *
 * Making it settable is most of the work. The rest is making it safe, and
 * that part already existed: ReplyPrivacyGuard permits a figure the prompt
 * contains and refuses every other, so the account the operator configured
 * goes out and an invented one does not. The one gap was the model
 * re-spacing the number — a correct account, refused, and the customer left
 * with a fallback. The prompt now tells it to copy the text character for
 * character.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$pass = 0; $fail = 0;
function ok(string $m): void  { global $pass; $pass++; echo "  ok   {$m}\n"; }
function bad(string $m, string $d=''): void { global $fail; $fail++; echo "  FAIL {$m}\n"; if($d)echo "       {$d}\n"; }
function is_(bool $c, string $m, string $d=''): void { $c ? ok($m) : bad($m, $d); }

require_once $root . '/lib/ReplyPrivacyGuard.php';
require_once $root . '/lib/DishNetAiBrain.php';

$ACCT = '9030012345678';
$PAY  = 'pay into DishNet Africa Ltd, Ecobank Uganda, account ' . $ACCT
      . ', branch Kampala Road. Send the deposit slip here and we confirm within the hour.';

echo "\n1. Unset, the assistant refuses — the South Sudan default\n";
$plain  = new DishNetAiBrain(['ai_provider' => 'openai', 'openai_api_key' => 'k']);
$pDef   = $plain->promptPreview(['channel' => 'sales', 'message' => 'where do I pay?', 'identity_state' => 'unknown']);
is_(strpos($pDef, 'NEVER share bank details or account numbers in chat') !== false,
    'the default still says never share bank details',
    'this is the sentence that stopped a sale at 00:59 on 17 Sep');
is_(strpos($pDef, 'dishnetafrica.com/pay.html') !== false, 'and points at the South Sudan pay page');

echo "\n2. Set, the operator's own words replace it entirely\n";
$cfg   = ['ai_provider' => 'openai', 'openai_api_key' => 'k', 'ai_fact_payment' => $PAY];
$brain = new DishNetAiBrain($cfg);
$p     = $brain->promptPreview(['channel' => 'sales', 'message' => 'where do I pay?', 'identity_state' => 'unknown']);
is_(strpos($p, $ACCT) !== false, 'the account number is in the prompt');
is_(strpos($p, 'NEVER share bank details') === false, 'the refusal is gone');
is_(strpos($p, 'dishnetafrica.com/pay.html') === false, 'and so is the South Sudan pay page');
is_(strpos($p, '- PAYMENT: pay into DishNet Africa Ltd') !== false, 'it renders under the PAYMENT heading');

echo "\n3. And it must be copied out character for character\n";
is_(strpos($p, 'EXACTLY as written above, character for character') !== false,
    'the prompt says to write the number exactly as given',
    'a re-spaced account number is refused by the guard, costing the customer their answer');
is_(strpos($p, 'never add or remove spaces') !== false, 'spelling out what reformatting means');
is_(strpos($p, 'If you are not certain of a digit, do not write it') !== false,
    'and an uncertain digit is a hand-over, not a guess');
// Only for payment. The other facts keep their shape exactly.
$other = (new DishNetAiBrain(['ai_provider' => 'openai', 'openai_api_key' => 'k',
    'ai_fact_office' => 'Our office is on Kampala Road, Mon-Sat 9-6.']))
    ->promptPreview(['channel' => 'sales', 'message' => 'x', 'identity_state' => 'unknown']);
is_(strpos($other, 'character for character') === false, 'no other business fact gained that instruction');

echo "\n4. The guard: our account leaves, any other does not\n";
$check = fn(string $reply) => ReplyPrivacyGuard::check($reply, ['values' => [], 'prompt' => $p]);
$ours  = $check('You can pay into DishNet Africa Ltd, Ecobank Uganda, account ' . $ACCT . '. Send the slip here.');
is_(!empty($ours['safe']), 'the configured account is sent', json_encode($ours['categories'] ?? []));
$theirs = $check('You can pay into DishNet Africa Ltd, Ecobank Uganda, account 9030099999999.');
is_(empty($theirs['safe']) && in_array('foreign:phone', $theirs['categories'], true),
    'a different account number is blocked',
    'this is the case that matters: an invented or injected account sends a customer\'s money elsewhere');
is_($theirs['reply'] === ReplyPrivacyGuard::SAFE_FALLBACK, 'and the customer gets the fallback, not a wrong number');
$respaced = $check('Account: 9030 0123 45678, Ecobank Uganda.');
is_(empty($respaced['safe']),
    'even OUR account re-spaced is blocked — which is why the prompt insists on verbatim',
    'safe direction: a withheld correct answer beats a confident wrong one');
$noAcct = $check('You can pay by mobile money or bank transfer — I will send the details.');
is_(!empty($noAcct['safe']), 'a reply with no figure in it is unaffected');

echo "\n5. Nothing is reachable by accident: the key has to be set deliberately\n";
$src = (string)file_get_contents($root . '/tools/set_config.php');
foreach (['ai_fact_payment', 'ai_fact_office', 'ai_fact_delivery'] as $k) {
    is_(strpos($src, "'" . $k . "' => ['text',") !== false, "set_config manages {$k}");
}
is_(strpos($src, 'Read it back against the bank statement') !== false,
    'and setting an account number warns about the cost of a typo');
is_(strpos($src, 'That names a South Sudan place') !== false,
    'an office or delivery text naming Juba is flagged on a Uganda box');

echo "\n6. And the example template cannot be pasted straight through\n";
// It was. The 5.18.14 deploy note carried "<BANK>, account <NUMBER>" as a
// shape to fill in; it went in verbatim and the assistant began telling
// customers to pay into "account <NUMBER>" — worse than the refusal it had
// replaced. Angle-bracketed capitals are never something a customer should
// read, so the tool refuses the value rather than warning about it.
is_(preg_match('/<\[A-Z\]\[A-Z0-9 _-\]\{1,30\}>/', $src) === 1,
    'set_config refuses a value still carrying a placeholder');
is_(strpos($src, 'nothing was saved') !== false, 'and says nothing was saved');
is_(strpos($src, 'or use --clear to leave the setting unset') !== false,
    'offering the way back when the real value is not to hand');
$refusal = strpos($src, 'That still has the example placeholder');
$tzCheck  = strpos($src, "\$key === 'timezone' && trim(\$new) !== ''");
is_($refusal !== false && $tzCheck !== false && $refusal < $tzCheck,
    'the check runs before anything is written');

echo "\n7. And the assistant can actually deliver it — the fact is quotable\n";
// The one that nearly shipped. The prompt orders the payment fact repeated
// "EXACTLY as written above, character for character"; the guard's leak rule
// refuses any 45+ character run of the prompt echoed back. So the more
// obediently the model answered "which account do I pay to?", the more
// certainly its reply was replaced by the safe fallback and handed to a
// person — the same dead end the fact was set to end, arriving by a
// different road, and silent, because a hand-over looks like a hand-over.
//
// The fix is not to soften the leak rule. It is to tell the guard which part
// of the prompt an operator wrote FOR customers. Nothing else is exempted.
$cfg7 = ['ai_provider' => 'openai', 'openai_api_key' => 'k', 'ai_fact_payment' => $PAY];
$b7   = new DishNetAiBrain($cfg7);
$p7   = $b7->promptPreview(['channel' => 'sales', 'message' => 'where do I pay?',
                            'identity_state' => 'unknown']);
$pub7 = DishNetAiBrain::operatorText($cfg7);

$verbatim = ReplyPrivacyGuard::check($PAY, ['values' => [], 'prompt' => $p7, 'public' => $pub7]);
is_(!empty($verbatim['safe']), 'the payment fact repeated word for word is sent',
    'categories: ' . implode(',', (array)($verbatim['categories'] ?? []))
    . ' — the prompt ORDERS this reply, so blocking it blocks the answer');

$wrapped = ReplyPrivacyGuard::check('Of course. ' . $PAY . ' Let me know once it is done.',
    ['values' => [], 'prompt' => $p7, 'public' => $pub7]);
is_(!empty($wrapped['safe']), 'and so is the same text with a greeting around it');

// Without the list it is refused — which is what was happening live.
$without = ReplyPrivacyGuard::check($PAY, ['values' => [], 'prompt' => $p7]);
is_(empty($without['safe']) && in_array('system_prompt', (array)($without['categories'] ?? []), true),
    'while the same reply with no public list is still refused as a leak',
    'if this passes, the leak rule is no longer doing anything');

// The rule itself must be untouched: every sentence of OUR instructions, and
// the machinery wrapped around the operator's text, still refused. Swept
// exhaustively rather than sampled, because a hole here is a hole nobody
// sees until a customer is reading our prompt back to us.
$segments = [];
foreach (preg_split('/(?<=[.\n])\s+/', $p7) ?: [] as $s) {
    $s = trim((string)$s);
    if (strlen($s) >= 45) $segments[] = $s;
}
$quotable = implode("\n", $pub7);
$ours = 0; $escaped = [];
foreach ($segments as $s) {
    if (stripos($quotable, $s) !== false) continue;          // the operator's own words
    $ours++;
    $r = ReplyPrivacyGuard::check('Sure — ' . $s, ['values' => [], 'prompt' => $p7, 'public' => $pub7]);
    if (!empty($r['safe'])) $escaped[] = substr($s, 0, 70);
}
is_($ours >= 20, 'the sweep found our instructions to check against', $ours . ' sentences');
is_($escaped === [], 'and not one sentence of them can be read back to a customer',
    count($escaped) . ' escaped, first: ' . ($escaped[0] ?? '-'));

// The exemption covers the operator's text only, never the sentence the
// plugin appends to it. That sentence is machinery and reads like an
// instruction, which is exactly what must not reach a customer.
$machinery = 'If you cannot answer fully from this, tell them you will have a colleague confirm';
$isMachinery = false;
foreach ($segments as $s) { if (stripos($s, 'If you cannot answer fully from this') !== false) $isMachinery = true; }
is_($isMachinery, 'the appended escalation sentence is in the prompt at all');
is_(stripos($quotable, 'If you cannot answer fully from this') === false,
    'and it is NOT in the quotable list — only the operator typed text is');

echo "\n8. Both reply paths hand the guard that list\n";
foreach (['workers/AiReplyWorker.php', 'lib/WaAutoReplyService.php'] as $f) {
    $s = (string)file_get_contents($root . '/' . $f);
    is_(strpos($s, "'public' => \\DishNetAiBrain::operatorText((array)(\$this->config ?? []))") !== false,
        basename($f) . ' passes the operator facts to the guard');
}
// Only what the operator set. A built-in default carries instructions a
// customer must never be shown ("Say exactly that", "Do NOT promise").
is_(DishNetAiBrain::operatorText(['ai_provider' => 'openai']) === [],
    'nothing is quotable when the operator has set nothing');
is_(DishNetAiBrain::operatorText(['ai_fact_payment' => 'omit']) === [],
    'and "omit" is a decision to say nothing, not a string to quote');

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
