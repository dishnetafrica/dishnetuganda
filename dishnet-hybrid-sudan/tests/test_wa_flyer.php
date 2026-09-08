<?php
declare(strict_types=1);
/**
 * The plans flyer: FlyerAsset resolution, and AiReplyWorker actually sending
 * the image through Evolution (fake server), with the per-conversation
 * cooldown that stops an eager model from spamming it.
 */
$pass = 0; $fail = 0;
function t(string $n, $got, $want) { global $pass, $fail;
    if ($got === $want) { $pass++; printf("  ok   %s\n", $n); }
    else { $fail++; printf("  FAIL %s\n       got  %s\n       want %s\n", $n, var_export($got, true), var_export($want, true)); } }

$root = dirname(__DIR__);

// ── FlyerAsset alone ────────────────────────────────────────────────────────
require_once $root . '/lib/FlyerAsset.php';
$tmp = sys_get_temp_dir() . '/wa_flyer_test_' . getmypid();
exec('rm -rf ' . escapeshellarg($tmp));
@mkdir($tmp, 0777, true);

echo "FlyerAsset — nothing configured means no flyer, and no behaviour change\n";
t('empty install resolves to null', FlyerAsset::find([], $tmp), null);
t('describe admits it', FlyerAsset::describe([], $tmp), 'not installed');
t('a non-http url is refused', FlyerAsset::find(['wa_flyer_url' => 'ftp://x/y.jpg'], $tmp), null);

$bytes = 'FAKE-JPEG-BYTES-' . str_repeat('x', 64);
file_put_contents($tmp . '/wa_flyer.jpg', $bytes);
$f = FlyerAsset::find([], $tmp);
t('a dropped file is found', $f['kind'] ?? '', 'file');
t('with its mime', $f['mime'] ?? '', 'image/jpeg');
t('payload is the file base64-encoded', FlyerAsset::payload($f), base64_encode($bytes));
t('the file wins over a configured URL',
  FlyerAsset::find(['wa_flyer_url' => 'https://example.test/f.jpg'], $tmp)['kind'] ?? '', 'file');
unlink($tmp . '/wa_flyer.jpg');
$f = FlyerAsset::find(['wa_flyer_url' => 'https://example.test/f.jpg'], $tmp);
t('without the file, the URL is used', $f['kind'] ?? '', 'url');
t('payload passes the URL through untouched', FlyerAsset::payload($f), 'https://example.test/f.jpg');

// ── Boot the fake Evolution server ──────────────────────────────────────────
array_map('unlink', glob(sys_get_temp_dir() . '/fake_evo_state_*.json') ?: []);
$router = $root . '/tests/fixtures/fake_evo_server.php';
$hit = function (int $port, string $p) {
    $ch = curl_init("http://127.0.0.1:{$port}{$p}");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 2, CURLOPT_PROXY => '']);
    $r = curl_exec($ch); curl_close($ch);
    return $r === false ? null : (string)$r;
};
$srv = null; $port = 0;
foreach (range(0, 9) as $slot) {
    $cand = 9620 + ((getmypid() + $slot * 19) % 70);
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
$evoState = fn() => json_decode((string)$hit($port, '/__test/state'), true) ?: [];

// ── The worker, wired to the fake server and a tmp data dir ─────────────────
require_once $root . '/lib/StoreInterface.php';
require_once $root . '/lib/SqliteStore.php';
require_once $root . '/lib/EventBus.php';
require_once $root . '/lib/ConversationService.php';
require_once $root . '/workers/WorkerBase.php';
require_once $root . '/workers/AiReplyWorker.php';

putenv('DN_DATA_DIR=' . $tmp);
$store = SqliteStore::create($tmp);
file_put_contents($tmp . '/wa_flyer.jpg', $bytes);   // flyer back in place

$config = [
    'evo_api_url'        => "http://127.0.0.1:{$port}",
    'evo_api_key'        => 'TESTKEY',
    'evo_instance_sales' => 'dishnet_ug',
    'claude_api_key'     => 'test-key-never-called',
    'wa_flyer_caption'   => 'DishNet Uganda — Starlink Plans',
];

$mkWorker = function (array $extra = []) use ($store, $config): AiReplyWorker {
    return new AiReplyWorker($store, array_merge($config, $extra));
};
$sendFlyer = function (AiReplyWorker $w, int $convId): string {
    $m = new ReflectionMethod(AiReplyWorker::class, 'maybeSendFlyer');
    $m->setAccessible(true);
    ob_start();
    $m->invoke($w, $convId, 'sales', '256701112233');
    return (string)ob_get_clean();
};

$conv   = (new ConversationService($tmp, $store->getPdo()))->ensureConversation('256701112233', 'sales');
$convId = (int)($conv['id'] ?? 0);
t('a conversation exists to hang the flyer on', $convId > 0, true);

echo "\nFirst <<FLYER>> — the image goes out with the caption\n";
$w   = $mkWorker();
$out = $sendFlyer($w, $convId);
$s   = $evoState();
t('exactly one media call reached Evolution', count($s['media_calls'] ?? []), 1);
$mc = $s['media_calls'][0] ?? [];
t('on the sales instance', $mc['instance'] ?? '', 'dishnet_ug');
t('as an image', $mc['mediatype'] ?? '', 'image');
t('with the operator caption', $mc['caption'] ?? '', 'DishNet Uganda — Starlink Plans');
t('carrying the file as base64', $mc['media_prefix'] ?? '', substr(base64_encode($bytes), 0, 48));
t('to the customer number', strpos((string)($mc['number'] ?? ''), '256701112233') !== false, true);
t('the worker says it sent', strpos($out, 'plans flyer sent') !== false, true);

$row = $store->getPdo()->query(
    "SELECT body, media_type, media_url FROM wa_messages
      WHERE conversation_id = {$convId} AND media_url = 'flyer:plans'"
)->fetch(PDO::FETCH_ASSOC) ?: [];
t('the send is on the conversation record', $row['media_type'] ?? '', 'image');
t('with the caption as its body (the model sees it in history)',
  $row['body'] ?? '', 'DishNet Uganda — Starlink Plans');

echo "\nSecond <<FLYER>> in the same conversation — the cooldown holds\n";
$out = $sendFlyer($w, $convId);
t('no second media call', count($evoState()['media_calls'] ?? []), 1);
t('and the worker says why', strpos($out, 'already sent recently') !== false, true);

echo "\nCooldown 0 hands the decision back to the model\n";
$w0 = $mkWorker(['wa_flyer_cooldown_hours' => '0']);
$sendFlyer($w0, $convId);
t('the flyer goes out again', count($evoState()['media_calls'] ?? []), 2);

echo "\nNo flyer installed — the flag is ignored, nothing breaks\n";
unlink($tmp . '/wa_flyer.jpg');
$wNone = $mkWorker(['wa_flyer_cooldown_hours' => '0']);
$sendFlyer($wNone, $convId);
t('no media call without a flyer', count($evoState()['media_calls'] ?? []), 2);

echo "\nURL mode — the address is passed through, not fetched by us\n";
$wUrl = $mkWorker(['wa_flyer_cooldown_hours' => '0', 'wa_flyer_url' => 'https://example.test/flyer.jpg']);
$sendFlyer($wUrl, $convId);
$s = $evoState();
t('the URL flyer went out', count($s['media_calls'] ?? []), 3);
t('as the URL itself', $s['media_calls'][2]['media_prefix'] ?? '', 'https://example.test/flyer.jpg');

putenv('DN_DATA_DIR');
if (is_resource($srv)) { proc_terminate($srv); proc_close($srv); }
exec('rm -rf ' . escapeshellarg($tmp));
array_map('unlink', glob(sys_get_temp_dir() . '/fake_evo_state_*.json') ?: []);
printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail ? 1 : 0);
