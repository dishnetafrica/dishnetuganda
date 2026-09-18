<?php
/**
 * test_operator_facts_reach_prompt.php — the facts an operator set must be in
 * the prompt, knowledge base or no knowledge base.
 *
 * ── What this is about ──────────────────────────────────────────────────
 *
 * DishNetAiBrain::coverageRules() was an if/else:
 *
 *     if ($kb !== '') { $p .= $kb; }
 *     else            { WHERE WE OPERATE ... BUSINESS FACTS + localFacts() ... }
 *
 * The Uganda install has 34 knowledge entries seeded, so it took the `if`
 * branch and localFacts() never ran. Every business fact set from
 * tools/set_config.php reached nothing at all:
 *
 *     ai_fact_payment       the Ecobank account, set 17 September
 *     ai_fact_office        the Acacia Mall address
 *     ai_fact_delivery      how kits reach a customer
 *     ai_fact_prices        the VAT line
 *     ai_fact_location_pin  the map pin
 *
 * Three releases — 5.18.14, 5.18.15, 5.18.16 — were written against a code
 * path that does not execute on that install. The customer who asked "will pay
 * in which number" was refused by a knowledge-base RULE, not by the
 * ai_fact_payment default those releases were built to replace.
 *
 * A knowledge base is company policy. These are this deployment's
 * configuration. Neither supersedes the other, and this asserts that.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$pass = 0; $fail = 0;
function ok(string $m): void  { global $pass; $pass++; echo "  ok   {$m}\n"; }
function bad(string $m, string $d=''): void { global $fail; $fail++; echo "  FAIL {$m}\n"; if($d)echo "       {$d}\n"; }
function is_(bool $c, string $m, string $d=''): void { $c ? ok($m) : bad($m, $d); }

require_once $root . '/lib/DishNetAiBrain.php';

$KB = "DISHNET MASTER POLICY: every DishNet channel answers from this knowledge base.\n"
    . "APPROVED KNOWLEDGE — answer these topics from here, exactly and only:\n"
    . "- [OFFICE_LOCATION] Where is the DishNet Uganda office: 4th floor, Acacia Mall.\n"
    . "- [BUSINESS_PLANS] Business plans: a public IP plus a priority-data block.\n"
    . "CONDUCT RULES (in addition to your absolute rules):\n"
    . "- Never direct a customer to pay to any personal phone number or personal account.\n";

// Exactly what is configured on the live box.
$FACTS = [
    'ai_fact_payment'      => 'Pay by bank transfer to DishNet Africa Limited at Ecobank Uganda '
                            . 'Limited, Head Office branch. UGX account number 7247510191.',
    'ai_fact_office'       => 'Our office is on the 4th Floor of Acacia Mall, Kampala.',
    'ai_fact_delivery'     => "our team delivers the kit and installs it at the customer's premises.",
    'ai_fact_prices'       => 'All our listed prices include VAT.',
    'ai_fact_location_pin' => 'https://maps.app.goo.gl/vKVyVWr8PsF1TpTg9',
];
$BASE = ['ai_provider' => 'openai', 'openai_api_key' => 'k'];
$CTX  = ['channel' => 'sales', 'message' => 'where do I pay?', 'identity_state' => 'unknown'];
$prompt = function (array $cfg) use ($CTX) {
    return (new DishNetAiBrain($cfg))->promptPreview($CTX);
};

echo "\n1. With a knowledge base seeded — the case that was broken\n";
$withKb = $prompt($BASE + $FACTS + ['knowledge_block' => $KB]);
is_(strpos($withKb, '7247510191') !== false,
    'the configured bank account is in the prompt',
    'this is the regression: it was absent on every Uganda prompt');
is_(strpos($withKb, 'Acacia Mall') !== false, 'the office address is in the prompt');
is_(strpos($withKb, 'delivers the kit') !== false, 'the delivery fact is in the prompt');
is_(strpos($withKb, 'include VAT') !== false, 'the VAT fact is in the prompt');
is_(strpos($withKb, 'maps.app.goo.gl') !== false, 'the map pin is in the prompt');

echo "\n2. And the knowledge base is still all there\n";
is_(strpos($withKb, 'DISHNET MASTER POLICY') !== false, 'the master policy line survives');
is_(strpos($withKb, '[OFFICE_LOCATION]') !== false, 'an APPROVED KNOWLEDGE entry survives');
is_(strpos($withKb, '[BUSINESS_PLANS]') !== false, 'and another');
is_(strpos($withKb, 'CONDUCT RULES') !== false, 'the conduct rules survive');
is_(strpos($withKb, 'personal phone number') !== false, 'including the payment-safety rule');
// Order matters for reading, not for correctness — but a fact printed before
// the policy that governs it reads as though it overrides it.
is_(strpos($withKb, 'DISHNET MASTER POLICY') < strpos($withKb, 'BUSINESS FACTS'),
    'the knowledge base comes first, the operator facts after it');

echo "\n3. Without a knowledge base, nothing whatsoever changed\n";
// A South Sudan install must produce the byte-identical prompt it always has.
$noKb = $prompt($BASE + $FACTS);
is_(strpos($noKb, '7247510191') !== false, 'the facts are still there');
is_(strpos($noKb, 'This is DishNet SUDAN') !== false,
    'and so is the legacy coverage block, which the knowledge base replaces');
is_(strpos($noKb, 'DISHNET MASTER POLICY') === false, 'with no knowledge base in it');
is_(strpos($noKb, 'BUSINESS FACTS') !== false, 'the facts header is where it always was');

echo "\n4. A knowledge-base install that configured NOTHING gets no Juba\n";
// The defaults are South Sudan's — a Juba office, kits crossing at the Joda
// border. They are a fallback for an install with no knowledge base. Pushing
// them into a prompt whose knowledge base answers the office question for its
// own country would recreate, one paragraph lower, the exact conflict this
// release removes.
$kbOnly = $prompt($BASE + ['knowledge_block' => $KB]);
is_(strpos($kbOnly, 'office is in Juba') === false, 'no Juba office');
is_(strpos($kbOnly, 'Joda border') === false, 'no Joda border route');
is_(strpos($kbOnly, 'dishnetafrica.com/pay.html') === false, 'no South Sudan pay page');
is_(strpos($kbOnly, 'BUSINESS FACTS') === false,
    'and no empty heading with nothing under it');
is_(strpos($kbOnly, 'DISHNET MASTER POLICY') !== false, 'the knowledge base is untouched');

echo "\n   but WITHOUT a knowledge base the defaults still answer, as before\n";
$bare = $prompt($BASE);
is_(strpos($bare, 'office is in Juba') !== false,
    'a South Sudan install with nothing configured still has its defaults');

echo "\n5. One fact at a time: setting one does not drag in the others\n";
$onlyPay = $prompt($BASE + ['knowledge_block' => $KB,
                            'ai_fact_payment' => $FACTS['ai_fact_payment']]);
is_(strpos($onlyPay, '7247510191') !== false, 'the one that is set appears');
is_(strpos($onlyPay, 'office is in Juba') === false, 'the ones that are not stay absent');
is_(strpos($onlyPay, 'Joda border') === false, 'both of them');

echo "\n6. 'omit' still means say nothing\n";
// The override goes FIRST: PHP's + keeps the first occurrence of a key, so
// putting it after $FACTS would silently do nothing — which is what the first
// version of this assertion did.
$omitted = $prompt($BASE + ['knowledge_block' => $KB, 'ai_fact_payment' => 'omit'] + $FACTS);
is_(strpos($omitted, '7247510191') === false, 'an omitted fact is not printed');
is_(strpos($omitted, 'Acacia Mall') !== false, 'and the rest are unaffected');

echo "\n7. The verbatim instruction still rides with the payment fact\n";
// 5.18.16: the guard permits a figure the prompt contains character for
// character, so a re-typed account number is a refused reply.
is_(strpos($withKb, 'EXACTLY as written above') !== false,
    'the payment fact still carries its copy-it-exactly instruction');

echo "\n8. The wiring itself\n";
$src = (string)file_get_contents($root . '/lib/DishNetAiBrain.php');
is_(substr_count($src, '$this->businessFactsBlock(') === 2,
    'both branches of coverageRules call the facts block',
    substr_count($src, '$this->businessFactsBlock(') . ' call sites');
is_(strpos($src, '$this->businessFactsBlock(false)') !== false,
    'and the knowledge-base branch asks for operator-set facts only');

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
