<?php
/**
 * test_plan_fence.php — the priority-data fact is attached, not requested.
 *
 * ── Why this test exists in this shape ──────────────────────────────────
 *
 * Because the previous attempt passed its own tests and failed in production.
 * test_ai_qualification.php asserts the rule is in the prompt, and it is: all
 * six markers, verified live, 29,029 characters. Then 18 of 21 replies to a
 * customer who said "business" named a Business plan and never mentioned
 * Residential. The test was true and the behaviour was wrong, because what it
 * pinned was that we had ASKED.
 *
 * So the cases below are real replies, copied from wa_messages, that the
 * prompt rule did not prevent. A test built from invented strings would have
 * passed for the prompt version too.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$pass = 0; $fail = 0;
function ok(string $m): void  { global $pass; $pass++; echo "  ok   {$m}\n"; }
function bad(string $m, string $d=''): void { global $fail; $fail++; echo "  FAIL {$m}\n"; if($d)echo "       {$d}\n"; }
function is_(bool $c, string $m, string $d=''): void { $c ? ok($m) : bad($m, $d); }

require_once $root . '/lib/PlanFenceGuard.php';

$cfg  = [];
$note = PlanFenceGuard::DEFAULT_NOTE;

echo "\nThe replies that actually went out, which the prompt rule did not stop\n";
// c723, 19 Sep 05:25:37 — sent with all six prompt markers verified present.
$c723 = 'Thanks for sharing! For business needs, we have the following plans: '
      . '1. **Starlink Business 50**: - Price: 175,000 UGX per month - Speed: Up to 400 Mbps';
// c738 — the model inventing a reason for the wrong plan.
$c738 = "Thanks for the details! Since it's for a business Wi-Fi hotspot, you'll likely need "
      . 'a plan with a public IP for better management and access. The DishNet Business plans are: '
      . '1. Business 50';
// c737 — asked what the plan IS, told the speed, never told about the cap.
$c737 = 'Starlink Business 50 offers a download speed of up to 400 Mbps and an upload speed '
      . 'of 50 Mbps. It is designed for businesses needing reliable internet with a public IP.';
foreach (['c723' => $c723, 'c738' => $c738, 'c737' => $c737] as $id => $reply) {
    $r = PlanFenceGuard::apply($reply, $cfg);
    is_($r['appended'] === true, $id . ' now carries the fact');
    is_(strpos($r['reply'], $note) !== false, $id . ' carries it verbatim');
    is_(strpos($r['reply'], $reply) === 0, $id . ' keeps what the model wrote, unedited');
}

echo "\nIt fires on a plan name, never on the word business\n";
foreach (['Starlink Business 50', 'Business 500', 'Business 1TB', 'Business 1 TB',
          'the DishNet Business plans are', 'our Business plan'] as $s) {
    is_(PlanFenceGuard::namesBusinessPlan($s), 'names a plan: ' . $s);
}
foreach (["it's for my business", 'business hours are 9 to 5', 'how is business going',
          'I run a small business in Kampala', 'business needs'] as $s) {
    is_(!PlanFenceGuard::namesBusinessPlan($s), 'not a plan reference: ' . $s);
}

echo "\nWhere the comparison is already there, it stays out of the way\n";
$both = 'Starlink Business 50 is 175,000 and Starlink Residential is unlimited at 400 Mbps.';
is_(PlanFenceGuard::apply($both, $cfg)['appended'] === false,
    'Residential named — nothing appended',
    'the customer already has the comparison; a second copy reads like a machine');
$own = 'Business 500 gives 500 GB of priority data, then speeds drop to about 1 Mbps.';
is_(PlanFenceGuard::apply($own, $cfg)['appended'] === false,
    'the model said it in its own words — nothing appended');
is_(PlanFenceGuard::apply('Our kits are in stock in Kampala.', $cfg)['appended'] === false,
    'a reply about something else is untouched');
is_(PlanFenceGuard::apply('', $cfg)['appended'] === false, 'an empty reply is left empty');

echo "\nApplying it twice does not say it twice\n";
$once  = PlanFenceGuard::apply($c723, $cfg)['reply'];
$twice = PlanFenceGuard::apply($once, $cfg);
is_($twice['appended'] === false, 'the second pass appends nothing');
is_(substr_count($twice['reply'], 'amounts of priority data') === 1, 'the fact appears once');

echo "\nThe operator owns the wording, and may switch it off\n";
$mine = ['ai_fact_business_cap' => 'Business tiers are data caps, not speeds. Ask us first.'];
$r = PlanFenceGuard::apply($c723, $mine);
is_(strpos($r['reply'], 'Ask us first.') !== false, 'their wording is used');
is_(strpos($r['reply'], $note) === false, 'and ours is not');
foreach (['omit', 'OMIT', 'Omit'] as $o) {
    is_(PlanFenceGuard::apply($c723, ['ai_fact_business_cap' => $o])['appended'] === false,
        '"' . $o . '" switches the fence off');
}
is_(PlanFenceGuard::apply($c723, ['ai_fact_business_cap' => '  '])['appended'] === true,
    'blank falls back to the built-in wording, it does not disable the fence');

echo "\nNo price is written into the default\n";
// Prices come from uCRM. A figure here becomes a second catalogue that goes
// stale without anyone noticing, which is the failure 5.18.10 was built to end.
is_(!preg_match('/\d{1,3}[, ]\d{3}/', $note), 'the built-in wording quotes no amount');
is_(stripos($note, 'UGX') === false, 'and names no currency');
is_(strpos($note, '400 Mbps') !== false, 'it does state the speed, which is not a price');

echo "\nIt is wired into both outbound paths, not just the one\n";
// The service path and the Evolution worker each call ReplyPrivacyGuard
// separately. A fence on one of them is a fence on neither.
// Three, not two. The first version of this fence covered the two paths that
// call ReplyPrivacyGuard and missed followup_send.php, which reaches Evolution
// on its own and passes through neither guard. A fence on some of the doors is
// a fence on none of them.
foreach (['lib/WaAutoReplyService.php', 'workers/AiReplyWorker.php',
          'cron/followup_send.php'] as $f) {
    is_(strpos((string)file_get_contents($root . '/' . $f), 'PlanFenceGuard::apply') !== false,
        basename($f) . ' applies the fence');
}

echo "\nEvery path that reaches Evolution is accounted for, by name\n";
// The rule: fence anything that puts MODEL-GENERATED text in front of a
// CUSTOMER. Staff alerts are internal, and fixed templates contain no model
// output to fence. Both exemptions are decisions, so they are written down
// here; a sender that appears without a line in this ledger fails the test
// until somebody chooses which side it is on. That choice going unmade by
// default is how followup_send.php shipped unfenced.
$ledger = [
    'AiReplyWorker.php'        => 'fence',   // AI reply, customer
    'followup_send.php'        => 'fence',   // AI-drafted follow-up, customer
    'AlertService.php'         => 'staff',   // CLASS_STAFF
    'NotificationService.php'  => 'staff',   // CLASS_STAFF
    'job_assignment_notify.php'=> 'staff',   // CLASS_STAFF
    'StarlinkMailWorker.php'   => 'fixed',   // three literal templates, no model text
    'EvolutionApiService.php'  => 'transport',
    'EvolutionApiClient.php'   => 'transport',
];
// WaAutoReplyService is deliberately absent: it calls sendText nowhere. It
// produces the reply and fences it in guard(), and something else posts it.
// Listing it here as a sender would be a lie the next reader has to unpick.
$found = [];
foreach (array_merge(glob($root . '/cron/*.php') ?: [], glob($root . '/workers/*.php') ?: [],
                     glob($root . '/lib/*.php') ?: []) as $f) {
    $src = (string)file_get_contents($f);
    if (strpos($src, 'sendText(') !== false) $found[] = basename($f);
}
foreach ($found as $name) {
    $how = $ledger[$name] ?? '';
    is_($how !== '', $name . ' has a decision recorded',
        'a new sender reached Evolution and nobody said whether customers see model text');
    if ($how === 'fence') {
        $path = file_exists($root . '/lib/' . $name) ? '/lib/' . $name
              : (file_exists($root . '/cron/' . $name) ? '/cron/' . $name : '/workers/' . $name);
        is_(strpos((string)file_get_contents($root . $path), 'PlanFenceGuard::apply') !== false,
            $name . ' is fenced, as its entry says');
    }
}
is_(count(array_intersect(array_keys($ledger, 'fence'), $found)) === 2,
    'both customer-facing AI senders are present and fenced');
is_(substr_count((string)file_get_contents($root . '/lib/WaAutoReplyService.php'),
    'sendText(') === 0,
    'and WaAutoReplyService still sends nothing itself, so the ledger stays true',
    'if it starts sending, it needs a ledger entry of its own');

is_(strpos((string)file_get_contents($root . '/lib/DishNetAiBrain.php'),
    "'ai_fact_business_cap'") !== false,
    'and the guard knows the note is operator text, so it cannot block us for echoing it');

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
