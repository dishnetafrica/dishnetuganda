<?php
/**
 * test_lead_matcher.php — one dedupe rule, called twice, never copied.
 *
 * This matcher was written inline in api/index.php's "convert from WA Inbox"
 * handler and was correct there. When AiLeadService needed the same behaviour,
 * copying it would have produced two rules that agree today and drift apart
 * the first time either is edited — and the symptom of that drift is a
 * customer with two leads, which is a customer two people call.
 *
 * So the rule lives in one class, both callers use it, and a test asserts
 * neither has grown its own copy.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/lib/LeadMatcher.php';

$pass = 0; $fail = 0;
function ok(string $m): void  { global $pass; $pass++; echo "  ok   {$m}\n"; }
function bad(string $m, string $d=''): void { global $fail; $fail++; echo "  FAIL {$m}\n"; if($d)echo "       {$d}\n"; }
function is_(bool $c, string $m, string $d=''): void { $c ? ok($m) : bad($m, $d); }

$leads = [
    ['id' => 1, 'phone' => '256772000111', 'status' => 'open'],
    ['id' => 2, 'phone' => '256772000222', 'status' => 'won'],
    ['id' => 3, 'phone' => '256772000333', 'status' => 'lost'],
    ['id' => 4, 'phone' => '256772000444', 'status' => 'dead'],
    ['id' => 5, 'phone' => '0772000555',   'status' => 'open'],
];

echo "\nOne handset, however it is written\n";
// Uganda numbers are written locally without the country code, and the same
// person writes their own number differently on different days.
foreach (['256772000111', '+256772000111', '+256 772 000 111', '0772000111',
          '772000111', '256-772-000-111'] as $form) {
    $hit = LeadMatcher::find($leads, $form);
    is_($hit !== null && (int)$hit['id'] === 1, 'matches: ' . $form);
}

echo "\nClosed business is not reopened\n";
// A new enquiry from someone whose last deal is done is a new opportunity.
foreach ([['256772000222', 'won'], ['256772000333', 'lost'], ['256772000444', 'dead']] as [$p, $st]) {
    is_(LeadMatcher::find($leads, $p) === null, 'a ' . $st . ' lead is not matched');
}

echo "\nA lead stored without a country code is still found\n";
is_((LeadMatcher::find($leads, '256772000555')['id'] ?? 0) === 5,
    'the stored side is normalised too, not just the query');

echo "\nA number too short to identify anyone matches nothing\n";
// Better no match than the wrong customer's file.
foreach (['', '12345', 'not a phone', '+256'] as $junk) {
    is_(LeadMatcher::find($leads, $junk) === null, 'no match for: "' . $junk . '"');
    is_(LeadMatcher::key($junk) === '', 'and no key is produced');
}

echo "\nfindIndex agrees with find\n";
is_(LeadMatcher::findIndex($leads, '+256 772 000 111') === 0, 'same row, by position');
is_(LeadMatcher::findIndex($leads, '256772000222') === null, 'and the same exclusions');

echo "\nNeither caller has grown its own copy of the rule\n";
// The whole point. A second implementation is a second answer waiting.
foreach (['api/index.php', 'lib/AiLeadService.php'] as $f) {
    $src = (string)file_get_contents($root . '/' . $f);
    is_(strpos($src, 'LeadMatcher::') !== false, $f . ' calls the shared matcher');
    is_(!preg_match("/substr\s*\(\s*preg_replace\s*\(\s*'\/\[\^0-9\]\/'/", $src),
        $f . ' has no inline last-9 matcher of its own',
        'two copies agree until one is edited');
}

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
