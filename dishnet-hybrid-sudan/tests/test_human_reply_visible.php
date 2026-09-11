<?php
/**
 * test_human_reply_visible.php — the AI must read what a colleague typed.
 *
 * "even if human typed you supposed read what human send and reply from next
 * message we dont need ai to stop answering" — and it could not, because a
 * reply typed on the handset was never recorded at all.
 *
 * evo_webhook.php carried the comment "Record them, never reply to them" above
 * a line that skipped before reaching any storing call. So a colleague's reply
 * never entered the conversation, never reached the model's history, and the
 * assistant answered the next message as though nobody had spoken. Every
 * wa_conversation on this install read state=new with last_human_reply_at
 * empty, which looked like nobody had ever replied by hand.
 *
 * The echo is the reason this was not simply switched on: Evolution sends our
 * own outbound messages back through the same webhook. Recording them without
 * an id would have stored every AI reply twice.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$pass = 0; $fail = 0;
function ok(string $m): void  { global $pass; $pass++; echo "  ok   {$m}\n"; }
function bad(string $m, string $d=''): void { global $fail; $fail++; echo "  FAIL {$m}\n"; if($d)echo "       {$d}\n"; }
function is_(bool $c, string $m, string $d=''): void { $c ? ok($m) : bad($m, $d); }

$hook   = (string)file_get_contents($root . '/evo_webhook.php');
$worker = (string)file_get_contents($root . '/workers/AiReplyWorker.php');
$convSv = (string)file_get_contents($root . '/lib/ConversationService.php');

echo "\nA message we sent is recorded, not discarded\n";
is_(strpos($hook, 'if ($fromMe) { $skipped++; continue; }') === false,
    'the bare skip is gone',
    'that line is what made a colleague invisible to the model');
is_(strpos($hook, "'role'          => 'agent'") !== false
    && strpos($hook, "'direction'     => 'out'") !== false,
    'it is stored as an outbound message from a person');

echo "\nBut it is never answered\n";
$at    = strpos($hook, 'if ($fromMe) {');
$emit  = strpos($hook, '$bus->emit(');
is_($at !== false && $emit !== false && $at < $emit,
    'the branch returns before anything can be queued for the AI',
    'replying to our own message would talk to ourselves forever');

echo "\nIt is claimed for idempotency first\n";
// Evolution redelivers. Without the claim, a redelivered handset reply would
// be stored again on every delivery.
$claim = strpos($hook, '$guard->claim($messageId');
is_($claim !== false && $at > $claim,
    'the guard claims the message before it is stored');

echo "\nAnd our own replies do not come back as duplicates\n";
is_(strpos($worker, "'wa_message_id' => (string)(\$send['data']['key']['id']") !== false,
    'the AI records the id Evolution gave its reply');
is_(strpos($convSv, 'SELECT id FROM wa_messages WHERE wa_message_id = ?') !== false,
    'and storeMessage refuses a second row for the same id',
    'without this the echo would double every AI message');
is_(strpos($hook, "'wa_message_id' => \$messageId") !== false,
    'the echo carries that same id, which is what makes them match');

echo "\nThe model is told a person wrote it, not the assistant\n";
// Attributing a colleague's words to the AI made it carry on as though it had
// promised whatever the person promised.
is_(strpos($worker, 'from our team] ') !== false,
    'history tags it by name');
is_(strpos($hook, "'agent_name'    => 'Team'") !== false,
    'and an agent_name is set, which is what triggers that tag',
    "an empty agent_name reads as the AI's own previous turn");

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
