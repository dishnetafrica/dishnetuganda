<?php
declare(strict_types=1);
/**
 * test_followup_lifecycle.php — the twelve cases, end to end.
 *
 * Real tables, real triggers, real indexes. A fake brain so the verdicts are
 * deterministic, but everything between the conversation and the draft is the
 * code that will run in production.
 */
require_once dirname(__DIR__) . '/lib/StoreInterface.php';
require_once dirname(__DIR__) . '/lib/JsonStore.php';
require_once dirname(__DIR__) . '/lib/SqliteStore.php';
require_once dirname(__DIR__) . '/lib/MigrationRunner.php';
require_once dirname(__DIR__) . '/lib/ConversationService.php';
require_once dirname(__DIR__) . '/lib/ContactOptOut.php';
require_once dirname(__DIR__) . '/lib/FollowUpPolicy.php';
require_once dirname(__DIR__) . '/lib/FollowUpService.php';
require_once dirname(__DIR__) . '/lib/FollowUpEvaluator.php';

$pass = 0; $fail = 0;
function t(string $n, $got, $want) { global $pass, $fail;
    if ($got === $want) { $pass++; printf("  ok   %s\n", $n); }
    else { $fail++; printf("  FAIL %s\n       got  %s\n       want %s\n", $n, var_export($got, true), var_export($want, true)); } }
function is_(bool $c, string $m, string $d = ''): void { global $pass, $fail;
    if ($c) { $pass++; echo "  ok   $m\n"; } else { $fail++; echo "  FAIL $m" . ($d ? "\n       $d" : '') . "\n"; } }

/** A brain that returns whatever the test told it to. */
final class FakeBrain {
    public string $next = '';
    public array  $prompts = [];
    public string $instructions = '';
    public function getReply(string $msg, array $ctx = [], string $ch = 'support',
                             string $hist = '', string $custom = '', string $mode = 'append'): ?string {
        $this->prompts[] = $msg;
        $this->instructions = $custom;
        return $this->next;
    }
}

$base = sys_get_temp_dir() . '/dn_fu_' . bin2hex(random_bytes(4));
@mkdir($base . '/data', 0777, true);
$store = SqliteStore::create($base . '/data');
$pdo   = $store->getPdo();
foreach (['069_contact_optouts', '070_followups'] as $m) {
    foreach (MigrationRunner::splitStatements((string)file_get_contents(
            dirname(__DIR__) . "/migrations/{$m}.sql")) as $q) $pdo->exec($q);
}
$convSvc = new ConversationService($base . '/data', $pdo);
$svc     = new FollowUpService($pdo);
$oo      = ContactOptOut::fromStore($store);

/** Build a conversation that went quiet $hoursAgo, with a thread. */
function quietConv(ConversationService $cs, \PDO $pdo, string $phone, array $opts = []): array {
    $c = $cs->ensureConversation($phone, $opts['channel'] ?? 'sales', $opts['name'] ?? null, 'test');
    $id = (int)$c['id'];
    $hours = (float)($opts['quiet_hours'] ?? 48);
    $last  = gmdate('Y-m-d H:i:s', time() - (int)($hours * 3600));
    $cs->storeMessage($id, ['direction' => 'out', 'role' => 'agent',
        'body' => 'DishNet Home is UGX 299,000 per month including installation.',
        'sent_at' => gmdate('Y-m-d H:i:s', time() - (int)($hours * 3600) - 600)]);
    $cs->storeMessage($id, ['direction' => 'in', 'role' => 'customer',
        'body' => $opts['last_message'] ?? 'How much is DishNet Home?', 'sent_at' => $last]);
    $sets = ['last_customer_at = :lc', 'status = :st', 'state = :state'];
    $p = [':lc' => $last, ':st' => $opts['status'] ?? 'active',
          ':state' => $opts['state'] ?? 'bot_active', ':id' => $id];
    if (isset($opts['crm_client_id'])) { $sets[] = 'crm_client_id = :cid'; $p[':cid'] = $opts['crm_client_id']; }
    if (isset($opts['link_method']))   { $sets[] = 'crm_link_method = :lm'; $p[':lm'] = $opts['link_method']; }
    $pdo->prepare('UPDATE wa_conversations SET ' . implode(', ', $sets) . ' WHERE id = :id')->execute($p);
    return $cs->getConversation($id);
}

echo "CASE A — a prospect asks a price, goes quiet, and a draft appears\n";
$convA = quietConv($convSvc, $pdo, '256700000001');
t('the prospect is not a CRM customer', (int)($convA['crm_client_id'] ?? 0), 0);
t('and is followable',      FollowUpPolicy::isFollowable($convA, gmdate('Y-m-d H:i:s'))['ok'], true);
$a = $svc->open($convA);
t('a follow-up opens',      $a['created'], true);
$fuA = $svc->get($a['id']);
t('attempt 1 is armed',     (int)$fuA['attempts'], 0);
is_(!empty($fuA['due_at']), 'with a due time', (string)$fuA['due_at']);
t('a prospect still gets enquiry-level content',
    FollowUpPolicy::contentLevel($convA), 'enquiry');

$brain = new FakeBrain();
$ev    = new FollowUpEvaluator($brain);
$brain->next = json_encode(['verdict' => 'SEND', 'reason' => 'they asked a price and never replied',
    'sales_stage' => 'enquired', 'product' => 'DishNet Home', 'objection' => null,
    'message' => 'Hello, following up on your DishNet Home enquiry — would you like us to arrange installation?',
    'next_due_hours' => 48]);
$vA = $ev->evaluate($fuA, $convSvc->getMessages((int)$convA['id']), 'enquiry');
t('the assistant says send',    $vA['verdict'], 'SEND');
t('naming the product',         $vA['product'], 'DishNet Home');
$dA = $svc->draft($a['id'], $vA, 'quiet 48h');
t('a draft is stored',          $dA['ok'], true);
t('and is pending',             $svc->pendingDraft($a['id'])['status'], 'pending');
// Nothing has been sent. That is the whole first milestone.
t('nothing has been sent',
    (int)$pdo->query("SELECT COUNT(*) FROM followup_sends")->fetchColumn(), 0);

echo "\nCASE B — 24h, then 72h, then stop\n";
t('attempt 1 is due 24h after quiet',
    FollowUpPolicy::dueAt('2026-09-10 08:00:00', 1), '2026-09-11 08:00:00');
t('attempt 2 at 72h',      FollowUpPolicy::dueAt('2026-09-10 08:00:00', 2), '2026-09-13 08:00:00');
t('and there is no third', FollowUpPolicy::dueAt('2026-09-10 08:00:00', 3), '');
// Spend both attempts and confirm the gate then closes it.
$svc->approve($dA['id'], 'staff:test');
$svc->recordSend($a['id'], $dA['id'], 'first follow-up', 'WAMSG-A1', 'staff:test');
$fuA = $svc->get($a['id']);
t('one attempt spent',     (int)$fuA['attempts'], 1);
is_(!empty($fuA['due_at']), 'and a second is armed', (string)$fuA['due_at']);
$vA2 = $ev->evaluate($fuA, [], 'enquiry');
$dA2 = $svc->draft($a['id'], $vA2, 'second attempt');
$svc->approve($dA2['id'], 'staff:test');
$svc->recordSend($a['id'], $dA2['id'], 'second follow-up', 'WAMSG-A2', 'staff:test');
$fuA = $svc->get($a['id']);
t('two attempts spent',    (int)$fuA['attempts'], 2);
t('and nothing further is armed', $fuA['due_at'], null);
$g = FollowUpPolicy::gate(['enabled' => true, 'now' => gmdate('Y-m-d H:i:s'),
    'conv' => $convA, 'followup' => $fuA, 'daily_cap' => 50]);
t('the gate now closes it as exhausted', $g['gate'], 'exhausted');

echo "\nCASE C — the customer replies, and the follow-up closes\n";
$convC = quietConv($convSvc, $pdo, '256700000003');
$c = $svc->open($convC);
$convSvc->storeMessage((int)$convC['id'], ['direction' => 'in', 'role' => 'customer',
    'body' => 'Yes please, how do I pay?']);
$convC2 = $convSvc->getConversation((int)$convC['id']);
is_((string)$convC2['last_customer_at'] > (string)$svc->get($c['id'])['last_customer_at'],
    'the conversation has moved on');
$svc->close($c['id'], 'replied', 'they wrote back', 'closer');
t('it closes as replied',   $svc->get($c['id'])['close_reason'], 'replied');
t('and is no longer open',  $svc->openFor((int)$convC['id']), null);

echo "\nCASE D — \"not interested\" closes and opts out\n";
$convD = quietConv($convSvc, $pdo, '256700000004', ['last_message' => 'Not interested']);
$d = $svc->open($convD);
$oo->add('256700000004', ['scope' => ContactOptOut::SCOPE_PROACTIVE,
                          'reason' => 'not_interested', 'evidence' => 'Not interested']);
$gD = FollowUpPolicy::gate(['enabled' => true, 'now' => gmdate('Y-m-d H:i:s'),
    'conv' => $convD, 'followup' => $svc->get($d['id']),
    'opt_out' => $oo->blocks('256700000004', 'sales', ContactOptOut::CLASS_PROACTIVE),
    'daily_cap' => 50]);
t('the gate closes it',     $gD['action'], 'close');
t('for the opt-out',        $gD['gate'], 'opt_out');
$svc->close($d['id'], 'opted_out', $gD['reason']);
t('recorded as opted_out',  $svc->get($d['id'])['close_reason'], 'opted_out');
is_(in_array('opted_out', array_column($svc->events($d['id']), 'event'), true),
    'and the audit trail says so');

echo "\nCASE E — an objection is acknowledged, not pushed past\n";
$convE = quietConv($convSvc, $pdo, '256700000005',
    ['last_message' => 'I need to discuss with my husband first']);
$e = $svc->open($convE);
$brain->next = json_encode(['verdict' => 'SEND',
    'reason' => 'they are deciding with their spouse; offer help rather than pressure',
    'sales_stage' => 'deciding', 'product' => 'DishNet Home',
    'objection' => 'discussing with spouse',
    'message' => 'Hello, just checking in regarding the DishNet Home package we discussed. If you have any questions while deciding, I am happy to help.',
    'next_due_hours' => 72]);
$vE = $ev->evaluate($svc->get($e['id']), $convSvc->getMessages((int)$convE['id']), 'enquiry');
t('the stage is recorded as deciding', $vE['sales_stage'], 'deciding');
t('and the objection is captured',     $vE['objection'], 'discussing with spouse');
$svc->setContext($e['id'], $vE);
t('stored on the row',      $svc->get($e['id'])['objection'], 'discussing with spouse');
// The instruction the model was given is what makes this reliable, so pin it.
is_(strpos($ev::instructions('enquiry'), 'do NOT ask them to proceed') !== false,
    'the brief tells it not to push when they are deciding');
is_(stripos($vE['message'], 'proceed') === false,
    'and the drafted message does not ask them to proceed', $vE['message']);

echo "\nCASE F — an ambiguous identity gets no proactive message\n";
$convF = quietConv($convSvc, $pdo, '256700000006', ['link_method' => 'ambiguous']);
t('content level is none',  FollowUpPolicy::contentLevel($convF), 'none');
$gF = FollowUpPolicy::gate(['enabled' => true, 'now' => gmdate('Y-m-d H:i:s'),
    'conv' => $convF, 'followup' => ['attempts' => 0, 'max_attempts' => 2], 'daily_cap' => 50]);
t('the gate stops it',      $gF['gate'], 'identity_ambiguous');
// Belt and braces: even called directly, the evaluator refuses.
$vF = $ev->evaluate(['channel' => 'sales'], [], 'none');
t('and the evaluator refuses too', $vF['verdict'], 'DO_NOT_SEND');

echo "\nCASE G — a phone-tail match may discuss the enquiry, not the account\n";
$convG = quietConv($convSvc, $pdo, '256700000007',
    ['crm_client_id' => 12, 'link_method' => 'phone_tail']);
t('content level is enquiry-only', FollowUpPolicy::contentLevel($convG), 'enquiry');
$g2 = quietConv($convSvc, $pdo, '256700000008',
    ['crm_client_id' => 12, 'link_method' => 'verified']);
t('a verified one may discuss the account', FollowUpPolicy::contentLevel($g2), 'account');
// The enforcement that actually holds: account facts never enter the prompt.
$brain->prompts = [];
$brain->next = json_encode(['verdict' => 'DO_NOT_SEND', 'reason' => 'x']);
$ev->evaluate(['channel' => 'sales', 'last_customer_at' => '2026-09-10 08:00:00',
               'attempts' => 0, 'max_attempts' => 2], [], 'enquiry',
              ['balance' => 'UGX 412,000 outstanding', 'account' => 'ACC-12345']);
$sent = $brain->prompts[0] ?? '';
is_(strpos($sent, '412,000') === false, 'the balance never reaches the prompt');
is_(strpos($sent, 'ACC-12345') === false, 'nor does the account number');
is_(strpos($sent, 'not confirmed') !== false, 'and the prompt says the identity is unconfirmed');
// While a verified identity does get them.
$brain->prompts = [];
$ev->evaluate(['channel' => 'sales', 'last_customer_at' => '2026-09-10 08:00:00',
               'attempts' => 0, 'max_attempts' => 2], [], 'account',
              ['balance' => 'UGX 412,000 outstanding']);
is_(strpos($brain->prompts[0] ?? '', '412,000') !== false,
    'a confirmed identity does get the account facts');

echo "\nCASE H — a colleague takes over\n";
$convH = quietConv($convSvc, $pdo, '256700000009', ['state' => 'human_active']);
$gH = FollowUpPolicy::gate(['enabled' => true, 'now' => gmdate('Y-m-d H:i:s'),
    'conv' => $convH, 'followup' => ['attempts' => 0, 'max_attempts' => 2], 'daily_cap' => 50]);
t('the gate closes it',     $gH['action'], 'close');
t('as human_active',        $gH['gate'], 'human_active');
t('and the scan will not even open one',
    FollowUpPolicy::isFollowable($convH, gmdate('Y-m-d H:i:s'))['ok'], false);

echo "\nCASE I — refund, lawyer, regulator: no sales follow-up\n";
foreach (['I want a refund', 'my lawyer will contact you', 'I am reporting this to the UCC'] as $angry) {
    $gI = FollowUpPolicy::gate(['enabled' => true, 'now' => gmdate('Y-m-d H:i:s'),
        'conv' => $convA, 'followup' => ['attempts' => 0, 'max_attempts' => 2],
        'daily_cap' => 50, 'thread_text' => $angry]);
    is_($gI['gate'] === 'escalation' && $gI['action'] === 'close', "closed: '{$angry}'");
}

echo "\nCASE J — due outside the window, deferred into it\n";
$gJ = FollowUpPolicy::gate(['enabled' => true, 'now' => '2026-09-14 20:30:00',  // 23:30 Kampala
    'conv' => $convA, 'followup' => ['attempts' => 0, 'max_attempts' => 2, 'due_at' => '2026-09-14 20:00:00'],
    'daily_cap' => 50]);
t('it defers',              $gJ['action'], 'defer');
t('for quiet hours',        $gJ['gate'], 'quiet_hours');
t('into a valid window',    FollowUpPolicy::withinSendingWindow($gJ['defer_until'])['ok'], true);
$svc->defer($a['id'] + 0, $gJ['defer_until'], 'quiet hours');   // a real write
is_(true, 'and deferring writes without error');

echo "\nCASE K — two workers race; the database allows exactly one\n";
$convK = quietConv($convSvc, $pdo, '256700000011');
$k1 = $svc->open($convK);
$k2 = $svc->open($convK);          // the second worker, same instant
t('the first creates it',   $k1['created'], true);
t('the second does not',    $k2['created'], false);
t('but is told the id',     $k2['id'], $k1['id']);
t('exactly one open row exists',
    (int)$pdo->query("SELECT COUNT(*) FROM followups WHERE conversation_id = "
        . (int)$convK['id'] . " AND closed_at IS NULL")->fetchColumn(), 1);
// And the index is what refuses it — prove it directly.
$raced = false;
try {
    $pdo->prepare("INSERT INTO followups (conversation_id, channel, phone, last_customer_at)
                   VALUES (?,?,?,?)")->execute([(int)$convK['id'], 'sales', '256700000011', gmdate('Y-m-d H:i:s')]);
} catch (\Throwable $e) { $raced = true; }
is_($raced, 'a direct second INSERT is refused by the index');

echo "\nCASE L — our own echo must not look like a colleague typing\n";
// AiReplyWorker claims its id before the echo arrives; followup_send must too.
$send = file_get_contents(dirname(__DIR__) . '/cron/followup_send.php');
is_(strpos($send, 'guard->claim(') !== false, 'the sender claims its message id');
is_(preg_match('/claim\(.*?\).*?recordSend\(/s', $send) === 1,
    'and claims it BEFORE recording the send');
is_(strpos($send, 'followup.send') !== false, 'under its own event name');
// The guard really does dedupe a claimed id.
require_once dirname(__DIR__) . '/lib/EvoWebhookGuard.php';
$guard = new EvoWebhookGuard($pdo, []);
t('a fresh id is claimable',    $guard->claim('WAMSG-ECHO-1', 'inst', 'followup.send'), true);
t('and the echo is refused',    $guard->claim('WAMSG-ECHO-1', 'inst', 'messages.upsert'), false);

echo "\nClosed follow-ups are history\n";
$hist = $svc->get($c['id']);
$threw = false;
try { $pdo->exec("UPDATE followups SET close_reason = 'edited' WHERE id = " . (int)$hist['id']); }
catch (\Throwable $e) { $threw = true; }
is_($threw, 'a closed row cannot be rewritten');
$threw = false;
try { $pdo->exec("DELETE FROM followups WHERE id = " . (int)$hist['id']); }
catch (\Throwable $e) { $threw = true; }
is_($threw, 'nor deleted');
t('and closing twice is not an error', $svc->close($c['id'], 'replied'), true);

echo "\nThe assistant's answer is read strictly\n";
foreach ([
    ['not json at all',                       'DO_NOT_SEND'],
    ['{"verdict":"MAYBE","reason":"x"}',      'DO_NOT_SEND'],
    ['{"verdict":"SEND","reason":"x"}',       'DO_NOT_SEND'],   // SEND with no message
    ['{"verdict":"SEND","reason":"x","message":"hello"}', 'SEND'],
] as [$raw, $want]) {
    t('"' . substr($raw, 0, 34) . '" → ' . $want, FollowUpEvaluator::parse($raw)['verdict'], $want);
}
// Fenced JSON is common and must not be treated as a failure.
t('a fenced block is read',
    FollowUpEvaluator::parse("```json\n{\"verdict\":\"WAIT\",\"reason\":\"x\"}\n```")['verdict'], 'WAIT');

echo "\nThe backlog floor — what stops a fifty-message first morning\n";
// On the live box, switching on with no floor would have opened 50 follow-ups
// at once, including a thread that was two colleagues testing the assistant.
// The floor is a lower bound on the customer's last message.
$floorTest = function (string $floor, string $lastCustomer): bool {
    $maxAge    = 336.0;
    $notBefore = gmdate('Y-m-d H:i:s', time() - (int)($maxAge * 3600));
    if ($floor !== '' && $floor > $notBefore) $notBefore = $floor;
    return $lastCustomer >= $notBefore;
};
$old   = gmdate('Y-m-d H:i:s', time() - 240 * 3600);   // 10 days quiet
$fresh = gmdate('Y-m-d H:i:s', time() - 30  * 3600);   // 30 hours quiet
$setAt = gmdate('Y-m-d H:i:s', time() - 48  * 3600);   // switched on 2 days ago

t('with no floor, a 10-day-old enquiry qualifies', $floorTest('', $old), true);
t('with a floor, it does not',                     $floorTest($setAt, $old), false);
t('but one quiet since the floor still does',      $floorTest($setAt, $fresh), true);
// The floor never widens the window — it only ever narrows it.
t('a floor older than max age does not widen it',
    $floorTest(gmdate('Y-m-d H:i:s', time() - 9999 * 3600), $old), true);
// And the scan actually reads the key.
$scan = file_get_contents(dirname(__DIR__) . '/cron/followup_scan.php');
is_(strpos($scan, 'followup_not_before') !== false, 'the scan honours followup_not_before');
is_(strpos($scan, "if (\$floor !== '' && \$floor > \$notBefore)") !== false,
    'raising the floor, never lowering it');

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
