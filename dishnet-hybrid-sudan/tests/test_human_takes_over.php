<?php
/**
 * test_human_takes_over.php — when a colleague types, the AI stops.
 *
 * AiReplyWorker::humanIsHandling() checks state='human_active' and
 * last_human_reply_at. On the live Evolution path nothing ever wrote either —
 * only WaBotService did, and that is the legacy WASender path, disabled in
 * this edition. So the rule had never once fired in production.
 *
 * c109 is what that looks like from the outside: a colleague answering from
 * the handset at 19:18, 19:21, 19:24 and 19:49, with the assistant replying
 * over the top of them every time, and the conversation still reading
 * "last human: never" after ninety-seven messages.
 *
 * The discrimination this rests on: the AI records what it sends under the id
 * Evolution returns, so its own echo dedupes and is recognisable as ours. A
 * fromMe message that inserts is one nobody sent through us — a person typed
 * it. Both halves are asserted, because getting the second one wrong would
 * silence the AI on its own reply for the whole cooldown.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$pass = 0; $fail = 0;
function ok(string $m): void  { global $pass; $pass++; echo "  ok   {$m}\n"; }
function bad(string $m, string $d=''): void { global $fail; $fail++; echo "  FAIL {$m}\n"; if($d)echo "       {$d}\n"; }
function is_(bool $c, string $m, string $d=''): void { $c ? ok($m) : bad($m, $d); }

$tmp = sys_get_temp_dir() . '/dn_human_' . bin2hex(random_bytes(4));
@mkdir($tmp, 0777, true);

require_once $root . '/lib/bootstrap_data.php';
require_once $root . '/lib/StoreInterface.php';
require_once $root . '/lib/JsonStore.php';
require_once $root . '/lib/SqliteStore.php';
require_once $root . '/lib/ConversationService.php';
require_once $root . '/lib/EventBus.php';
require_once $root . '/workers/WorkerBase.php';
require_once $root . '/workers/AiReplyWorker.php';

putenv('DN_DATA_DIR=' . $tmp);
$store = SqliteStore::create($tmp);
$pdo   = $store->getPdo();
$svc   = new ConversationService($tmp, $pdo);
$conv  = $svc->ensureConversation('256772000111', 'sales', null, 'test');
$cid   = (int)$conv['id'];

$state = function () use ($pdo, $cid): array {
    $st = $pdo->prepare("SELECT state, last_human_reply_at FROM wa_conversations WHERE id = ?");
    $st->execute([$cid]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: [];
};
$handling = function (array $cfg) use ($store, $cid): bool {
    $w = new AiReplyWorker($store, $cfg + ['claude_api_key' => 'k']);
    $m = new ReflectionMethod(AiReplyWorker::class, 'humanIsHandling');
    $m->setAccessible(true);
    return (bool)$m->invoke($w, $cid);
};

echo "\nA fresh conversation belongs to the AI\n";
$s = $state();
is_(($s['state'] ?? '') !== 'human_active', 'state is not human_active');
is_(!$handling([]), 'so the AI answers it');

echo "\nA colleague typing on the handset takes it over\n";
$svc->markHumanHandling($cid);
$s = $state();
is_(($s['state'] ?? '') === 'human_active', 'the state flips');
is_(!empty($s['last_human_reply_at']), 'and the time is recorded',
    'this field was empty on a 97-message thread with a colleague in it');
is_($handling([]), 'the AI now stands down');

echo "\nThe timestamp is UTC, matching the clock it is compared against\n";
// humanIsHandling compares this to time(). A Juba-stamped value reads three
// hours in the future and holds the AI quiet three hours longer than intended.
// Two wrong diagnoses in this project came from exactly this mismatch.
$drift = abs(time() - (int)strtotime((string)$state()['last_human_reply_at']));
is_($drift < 120, 'the stamp is within two minutes of now', 'drift: ' . $drift . 's');

echo "\nThe cooldown is honoured, and is a setting\n";
is_($handling(['wa_human_cooldown_minutes' => 60]), 'inside the window the AI stays out');
is_(!$handling(['wa_human_cooldown_minutes' => 0]),
    '0 means the AI never stands down — it reads what was said and carries on');

$pdo->exec("UPDATE wa_conversations SET last_human_reply_at = '2000-01-01 00:00:00' WHERE id = {$cid}");
is_(!$handling(['wa_human_cooldown_minutes' => 60]),
    'and an old reply releases it again',
    'a colleague answering once must not take a customer off the AI forever');

echo "\nOur own reply must never be mistaken for a colleague\n";
// The half that would be dangerous to get wrong: if the AI's echo registered
// as a human, the AI would silence itself for the whole cooldown after every
// single reply it sent.
$conv2 = $svc->ensureConversation('256772000222', 'sales', null, 'test');
$cid2  = (int)$conv2['id'];
$WAMID = 'EVO-MSG-ABC123';

// The worker records what it sent, under the id Evolution gave back.
$first = $svc->storeMessage($cid2, [
    'direction' => 'out', 'role' => 'assistant', 'body' => 'Which town are you in?',
    'agent_name' => 'DishNet AI', 'wa_message_id' => $WAMID,
]);
is_($first !== null, 'the AI reply is stored');

// The echo then arrives at the webhook carrying the same id.
$echo = $svc->storeMessage($cid2, [
    'direction' => 'out', 'role' => 'agent', 'body' => 'Which town are you in?',
    'agent_name' => 'Team', 'wa_message_id' => $WAMID,
]);
is_($echo === null, 'the echo dedupes to null — recognisably ours',
    'this null is the entire signal the webhook uses to tell a person from us');

echo "\nWhile a genuinely new outbound message does insert\n";
$typed = $svc->storeMessage($cid2, [
    'direction' => 'out', 'role' => 'agent', 'body' => 'Let me check that for you.',
    'agent_name' => 'Team', 'wa_message_id' => 'EVO-MSG-TYPED-ON-PHONE',
]);
is_($typed !== null, 'a handset message inserts, and is therefore a person');

echo "\nThe webhook acts on exactly that distinction\n";
$src = (string)file_get_contents($root . '/evo_webhook.php');
is_(strpos($src, '$stored = $convSvc->storeMessage') !== false,
    'the webhook keeps the return value');
is_(preg_match('/if\s*\(\s*\$stored\s*!==\s*null\s*\)\s*\{\s*\$convSvc->markHumanHandling/s', $src) === 1,
    'and marks human handling only when it inserted',
    'marking on every fromMe would silence the AI after each of its own replies');

echo "\nAnd the worker claims its own echo before the webhook can see it\n";
$w = (string)file_get_contents($root . '/workers/AiReplyWorker.php');
is_(strpos($w, 'EvoWebhookGuard') !== false, 'the guard is used from the reply path');
is_(strpos($w, "claim(\$ourId") !== false, 'claiming the id it just sent');

exec('rm -rf ' . escapeshellarg($tmp));
printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
