<?php
/**
 * test_email_intent.php — the first real customer email, and what it taught.
 *
 * Fixture below is the genuine article, received 9 Sep 2026 at the
 * accounts@ mailbox: a reply to our own Quotation 000001, a purchase order
 * attached, asking when we would install.
 *
 * It is here because the policy as first written would have let a machine
 * answer it. 'installation' was a MAY_AUTO category, meaning published facts
 * about how installation works; this customer was asking for a date. The two
 * are one word apart and worlds apart in consequence.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/lib/EmailReplyPolicy.php';
require_once $root . '/lib/EmailIntentClassifier.php';

$pass = 0; $fail = 0;
function ok(string $m): void  { global $pass; $pass++; echo "  ok   {$m}\n"; }
function bad(string $m, string $d=''): void { global $fail; $fail++; echo "  FAIL {$m}\n"; if($d)echo "       {$d}\n"; }
function is_(bool $c, string $m, string $d=''): void { $c ? ok($m) : bad($m, $d); }

// ── The real message ─────────────────────────────────────────────────────
$felix = [
    'subject'     => 'Re: Quotation 000001 — Starlink Internet for Subterra Limited',
    'attachments' => ['DISHNET PO 090926 INTERNET SERVICE.pdf'],
    'body'        => "Hello Dishnet team,\n\n"
        . "I have attached our PO for the Starlink Internet Service.\n"
        . "Please let us know when you will install it.\n\n"
        . "Regards\n\nFelix\n\n"
        . "On Tue, Sep 8, 2026 at 6:29 PM accounts@dishnetuganda.com\n"
        . "<accounts@dishnetuganda.com> wrote:\n"
        . "> Dear Felix,\n"
        . ">\n"
        . "> Thank you for your interest in DishNet. Please find attached our\n"
        . "> quotation 000001 for Starlink internet at your premises in Tororo,\n"
        . "> covering the Starlink Standard Kit, the Business 1TB service plan,\n"
        . "> and professional installation.\n"
        . ">\n"
        . "> Key points at a glance:\n"
        . "> Business 1TB — UGX 1,645,440 per month\n",
];

echo "\nThe first real customer email\n";
$r = EmailIntentClassifier::classify($felix);
is_($r['category'] === 'order_po',
    'is read as a purchase order, not as an installation question',
    'got: ' . $r['category']);
is_($r['requires_human'] === true, 'and a person must approve any reply to it');
is_($r['source'] === 'rules', 'decided from the words, with no model call spent');
is_(count($r['reasons']) > 0, 'and says why: ' . implode('; ', $r['reasons']));

echo "\nOur own quotation, quoted underneath, is not the customer speaking\n";
is_(strpos($r['own_words'], 'attached our PO') !== false, 'what Felix wrote is kept');
is_(strpos($r['own_words'], 'Business 1TB') === false,
    'what we wrote is dropped — our prices must not classify their email',
    'own_words: ' . $r['own_words']);
is_(strpos($r['own_words'], 'Dear Felix') === false, 'including our greeting to them');
is_(substr_count($r['own_words'], '>') === 0, 'no quoted line survives');

echo "\nThe date question alone is enough, with no PO at all\n";
$dateOnly = EmailIntentClassifier::classify([
    'subject' => 'Re: our order',
    'body'    => 'Thanks. Please let us know when you will install it.',
]);
is_($dateOnly['category'] === 'schedule_request',
    'asking when we will come is a commitment, not a fact', 'got: ' . $dateOnly['category']);
is_($dateOnly['requires_human'] === true, 'so it also waits for a person');

echo "\nThe old reading is no longer reachable for a commitment\n";
is_(EmailReplyPolicy::requiresHuman('order_po'), 'order_po can never auto-send');
is_(EmailReplyPolicy::requiresHuman('schedule_request'), 'schedule_request can never auto-send');
is_(EmailReplyPolicy::mayAutoSend('order_po', [
        'email_ai_auto_send' => 1, 'email_ai_auto_order_po' => 1,
        'email_ai_confidence_floor' => 0.0,
    ], 1.0) === false,
    'and an operator who switches everything on still cannot send one');

echo "\nA model may raise caution but never lower it\n";
$confidentlyWrong = function (string $t): array {
    return ['category' => 'plans_pricing', 'confidence' => 1.0];   // certain, and wrong
};
$r2 = EmailIntentClassifier::classify($felix, $confidentlyWrong);
is_($r2['category'] === 'order_po', 'the hard signal is not overridden by a sure model');
is_($r2['source'] === 'rules', 'the model was never even asked');

echo "\nAn attachment always brings a person in\n";
$benignWithFile = EmailIntentClassifier::classify(
    ['subject' => 'Question', 'body' => 'What plans do you offer?', 'attachments' => ['photo.jpg']],
    function (string $t): array { return ['category' => 'plans_pricing', 'confidence' => 1.0]; }
);
is_($benignWithFile['category'] === 'plans_pricing', 'the category can still be benign');
is_($benignWithFile['requires_human'] === true,
    'but a file the customer sent us is read by a human');

echo "\nWithout an attachment the same question stays automatable\n";
$benign = EmailIntentClassifier::classify(
    ['subject' => 'Question', 'body' => 'What plans do you offer?'],
    function (string $t): array { return ['category' => 'plans_pricing', 'confidence' => 0.95]; }
);
is_($benign['category'] === 'plans_pricing' && $benign['requires_human'] === false,
    'a plain pricing question is eligible for an automatic answer');

echo "\nA label nobody defined unlocks nothing\n";
$invented = EmailIntentClassifier::classify(
    ['subject' => 'Hi', 'body' => 'Hello there'],
    function (string $t): array { return ['category' => 'auto_approve_everything', 'confidence' => 1.0]; }
);
is_($invented['category'] === 'unclear', 'an invented category becomes unclear');
is_($invented['requires_human'] === true, 'and waits for a person');

echo "\nA broken classifier fails towards the human\n";
$broken = EmailIntentClassifier::classify(
    ['subject' => 'Hi', 'body' => 'Hello there'],
    function (string $t): array { throw new RuntimeException('provider down'); }
);
is_($broken['category'] === 'unclear' && $broken['requires_human'] === true,
    'a thrown classifier does not become an automatic reply');
is_(in_array('classifier unavailable', $broken['reasons'], true), 'and says why');

echo "\nEscalation words still win outright\n";
$angry = EmailIntentClassifier::classify(
    ['subject' => 'Plans', 'body' => 'What do you charge? Otherwise I want a refund.'],
    function (string $t): array { return ['category' => 'plans_pricing', 'confidence' => 1.0]; }
);
is_($angry['category'] === 'unclear', 'a refund demand is never a pricing question');
is_($angry['requires_human'] === true, 'and always reaches a person');

echo "\nOther real shapes this mailbox will see\n";
foreach ([
    ['I have paid the invoice, proof of payment attached.', 'payment_claim'],
    ['Please find the signed contract attached for your records.', 'contract_signed'],
    ['Our internet is not working since morning.',            'technical_fault'],
    ['Kindly raise a PO against our account.',                'order_po'],
] as [$text, $want]) {
    $g = EmailIntentClassifier::classify(['subject' => '', 'body' => $text]);
    is_($g['category'] === $want && $g['requires_human'],
        '"' . substr($text, 0, 44) . '" → ' . $want, 'got: ' . $g['category']);
}

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
