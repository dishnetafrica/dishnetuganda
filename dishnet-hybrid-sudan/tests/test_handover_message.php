<?php
/**
 * test_handover_message.php — a handover must not leave the customer silent.
 *
 * When the assistant decides it cannot answer, escalate() marks the
 * conversation for a human and buzzes the team. It said nothing at all to the
 * person who asked, and from their side that is indistinguishable from being
 * ignored. Six conversations were sitting in that silence, one of them from
 * nine in the morning, while every dashboard showed the handoff working.
 *
 * Runs against the fake Evolution server, so "it sent something" means a real
 * send actually left the worker rather than a flag being set.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$pass = 0; $fail = 0;
function ok(string $m): void  { global $pass; $pass++; echo "  ok   {$m}\n"; }
function bad(string $m, string $d=''): void { global $fail; $fail++; echo "  FAIL {$m}\n"; if($d)echo "       {$d}\n"; }
function is_(bool $c, string $m, string $d=''): void { $c ? ok($m) : bad($m, $d); }

$tmp = sys_get_temp_dir() . '/wa_handover_' . getmypid();
exec('rm -rf ' . escapeshellarg($tmp));
@mkdir($tmp, 0777, true);

// ── Fake Evolution ──────────────────────────────────────────────────────────
$router = $root . '/tests/fixtures/fake_evo_server.php';
$hit = function (int $port, string $p) {
    $ch = curl_init("http://127.0.0.1:{$port}{$p}");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 2, CURLOPT_PROXY => '']);
    $r = curl_exec($ch); curl_close($ch);
    return $r === false ? null : (string)$r;
};
$srv = null; $port = 0;
foreach (range(0, 9) as $slot) {
    $cand = 9720 + ((getmypid() + $slot * 17) % 70);
    $p = proc_open(sprintf('exec php -S 127.0.0.1:%d %s', $cand, escapeshellarg($router)),
                   [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
    $ours = false;
    for ($i = 0; $i < 40; $i++) {
        $got = $hit($cand, '/__test/state');
        if ($got !== null) { $ours = strpos($got, 'FAKE-EVO-TEST') !== false; break; }
        usleep(100000);
    }
    if ($ours) { $srv = $p; $port = $cand; break; }
    proc_terminate($p); proc_close($p);
}
if ($port === 0) { fwrite(STDERR, "could not start the fake Evolution server\n"); exit(1); }
$state = function () use ($hit, $port) { return json_decode((string)$hit($port, '/__test/state'), true) ?: []; };
$sentCount = function () use ($state) { return count($state()['text_calls'] ?? []); };
$lastText  = function () use ($state) {
    $c = $state()['text_calls'] ?? [];
    return $c ? (string)(end($c)['text'] ?? '') : '';
};

require_once $root . '/lib/StoreInterface.php';
require_once $root . '/lib/SqliteStore.php';
require_once $root . '/lib/EventBus.php';
require_once $root . '/lib/ConversationService.php';
require_once $root . '/workers/WorkerBase.php';
require_once $root . '/workers/AiReplyWorker.php';

putenv('DN_DATA_DIR=' . $tmp);
$store = SqliteStore::create($tmp);

$base = [
    'evo_api_url'        => "http://127.0.0.1:{$port}",
    'evo_api_key'        => 'TESTKEY',
    'evo_instance_sales' => 'dishnet_ug',
    'claude_api_key'     => 'test-key-never-called',
];
$escalate = function (AiReplyWorker $w, int $convId, string $phone) {
    $m = new ReflectionMethod(AiReplyWorker::class, 'escalate');
    $m->setAccessible(true);
    $m->invoke($w, $convId, 'sales', $phone, 'test reason');
};

echo "\nWith nothing configured, behaviour is exactly as before\n";
$before = $sentCount();
$escalate(new AiReplyWorker($store, $base), 0, '256700000001');
is_($sentCount() === $before, 'a handover sends the customer nothing',
    'an install that never set a line must not start messaging customers');

echo "\nWith a line configured, the customer hears it\n";
$LINE = 'Let me get a colleague to confirm that for you.';
$w = new AiReplyWorker($store, array_merge($base, ['ai_handover_message' => $LINE]));
$before = $sentCount();
$escalate($w, 0, '256700000002');
is_($sentCount() === $before + 1, 'exactly one message goes out',
    'silence is what this whole test exists to prevent');

is_($lastText() === $LINE, 'and it carries the configured wording exactly',
    'got: ' . $lastText());

echo "\nIt is said once, not on every repeated handoff\n";
// A customer who trips the handoff three times running should hear it once.
require_once $root . '/lib/ConversationService.php';
$pdo = new PDO('sqlite:' . $tmp . '/plugin.sqlite3');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$conv = (new ConversationService($tmp, $pdo))->ensureConversation('256700000003', 'sales', null, 'test');
$cid  = (int)$conv['id'];

$before = $sentCount();
$escalate($w, $cid, '256700000003');
$afterFirst = $sentCount();
$escalate($w, $cid, '256700000003');
$afterSecond = $sentCount();

is_($afterFirst === $before + 1, 'the first handover in a conversation speaks');
is_($afterSecond === $afterFirst, 'the second one stays quiet',
    'repeating a holding line at someone already waiting reads worse than silence');

echo "\nA different customer is unaffected by that\n";
$before = $sentCount();
$escalate($w, 0, '256700000004');
is_($sentCount() === $before + 1, 'they hear it too');

if ($srv) { proc_terminate($srv); proc_close($srv); }
exec('rm -rf ' . escapeshellarg($tmp));

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
