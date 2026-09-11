<?php
/**
 * test_wa_conversation_tool.php — reading a thread must not change it.
 *
 * This tool exists to answer one question quickly: is this conversation a real
 * customer, or is it our own handset talking to our own bot? That decision was
 * being made from a browser inbox, or from memory, and getting it wrong is how
 * an alert target ended up inside a 97-message thread with the assistant.
 *
 * Two things are asserted. That it reads what is actually there, and that it
 * writes nothing — a diagnostic that mutates the thing being diagnosed is worse
 * than no diagnostic.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$pass = 0; $fail = 0;
function ok(string $m): void  { global $pass; $pass++; echo "  ok   {$m}\n"; }
function bad(string $m, string $d=''): void { global $fail; $fail++; echo "  FAIL {$m}\n"; if($d)echo "       {$d}\n"; }
function is_(bool $c, string $m, string $d=''): void { $c ? ok($m) : bad($m, $d); }

$tmp = sys_get_temp_dir() . '/dn_convtool_' . bin2hex(random_bytes(4));
@mkdir($tmp, 0777, true);

require_once $root . '/lib/StoreInterface.php';
require_once $root . '/lib/JsonStore.php';
require_once $root . '/lib/SqliteStore.php';
require_once $root . '/lib/ConversationService.php';

$store = SqliteStore::create($tmp);
$pdo   = $store->getPdo();
$svc   = new ConversationService($tmp, $pdo);
$conv  = $svc->ensureConversation('211927797217', 'sales', null, 'test');
$cid   = (int)$conv['id'];
$svc->storeMessage($cid, ['direction' => 'in',  'role' => 'customer',
                          'body' => 'how much is starlink for my home']);
$svc->storeMessage($cid, ['direction' => 'out', 'role' => 'assistant',
                          'body' => 'Happy to help — which town are you in?',
                          'agent_name' => 'DishNet AI']);
$svc->storeMessage($cid, ['direction' => 'out', 'role' => 'agent',
                          'body' => 'Taking this one over.', 'agent_name' => 'Richard']);

$run = function (array $args) use ($root, $tmp): array {
    $cmd = 'DN_DATA_DIR=' . escapeshellarg($tmp) . ' php '
         . escapeshellarg($root . '/tools/wa_conversation.php') . ' '
         . implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1';
    exec($cmd, $out, $code);
    return [$code, implode("\n", $out)];
};
$countAll = function () use ($pdo): array {
    return [(int)$pdo->query("SELECT COUNT(*) FROM wa_messages")->fetchColumn(),
            (int)$pdo->query("SELECT COUNT(*) FROM wa_conversations")->fetchColumn()];
};

$before = $countAll();

echo "\nIt finds the thread by id\n";
[$code, $out] = $run(['--id', (string)$cid]);
is_($code === 0, 'exits clean');
is_(strpos($out, 'c' . $cid) !== false, 'and names the conversation');
is_(strpos($out, '211927797217') !== false, 'with the number on it');

echo "\nAnd by phone, however it is written\n";
[$c2, $out2] = $run(['--phone', '+211 927 797 217']);
is_($c2 === 0 && strpos($out2, 'c' . $cid) !== false,
    'a formatted number reaches the same thread', $out2);

echo "\nIt shows who said what\n";
is_(strpos($out, 'how much is starlink') !== false, 'the customer message is there');
is_(strpos($out, 'THEM') !== false, 'attributed to them');
is_(strpos($out, 'which town are you in') !== false, 'the AI reply is there');
is_(strpos($out, 'AI') !== false, 'attributed to the AI');
is_(strpos($out, 'Taking this one over') !== false, 'and so is the human message');
is_(strpos($out, 'RICHARD') !== false, 'attributed to the colleague who sent it',
    'telling a human reply from an AI one is the whole point of reading a thread');

echo "\nTimestamps say which clock they are on\n";
// Two wrong diagnoses in this project came from a timestamp whose zone had to
// be guessed. wa_messages.sent_at is UTC.
is_(strpos($out, 'UTC') !== false, 'the zone is stated, not left to be guessed');

echo "\nIt warns when the thread is the alert target\n";
// The c109 situation, which is the reason this tool was written.
file_put_contents($tmp . '/kyc_config.json',
    json_encode(['alert_whatsapp' => '211927797217']));
[$c3, $out3] = $run(['--id', (string)$cid]);
is_(strpos($out3, 'ALERT TARGET') !== false,
    'the overlap is called out at the top of the thread', $out3);
unlink($tmp . '/kyc_config.json');

echo "\nWith no arguments it lists recent threads instead of failing\n";
[$c4, $out4] = $run([]);
is_($c4 === 0, 'exits clean');
is_(strpos($out4, 'MOST RECENT') !== false, 'and shows what there is to look at');

echo "\nA thread that does not exist says so\n";
[$c5, $out5] = $run(['--id', '999999']);
is_($c5 !== 0 && stripos($out5, 'no such') !== false, 'plainly', $out5);

echo "\nAnd none of that wrote anything\n";
$after = $countAll();
is_($before === $after, 'message and conversation counts are unchanged',
    'a diagnostic that mutates what it inspects is worse than none');

exec('rm -rf ' . escapeshellarg($tmp));
printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
