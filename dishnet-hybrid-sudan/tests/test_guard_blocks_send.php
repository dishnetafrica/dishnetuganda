<?php
declare(strict_types=1);
/**
 * test_guard_blocks_send.php — the unsafe reply never reaches WhatsApp.
 *
 * Not "the guard returned blocked". The question is whether the send happens,
 * so this drives the real WaAutoReplyService with sendReply replaced by a
 * recorder, a real ClaudeWaClient whose transport is a compromised model, and
 * asks what was actually handed to the sender.
 */
$pass = 0; $fail = 0;
function is_(bool $c, string $m): void { global $pass, $fail;
    if ($c) { $pass++; echo "  ok   $m\n"; } else { $fail++; echo "  FAIL $m\n"; } }
function t(string $n, $got, $want) { global $pass, $fail;
    if ($got === $want) { $pass++; printf("  ok   %s\n", $n); }
    else { $fail++; printf("  FAIL %s\n       got  %s\n       want %s\n", $n, var_export($got, true), var_export($want, true)); } }

$root = dirname(__DIR__);
require_once $root . '/lib/timezone.php';
require_once $root . '/lib/currency.php';
require_once $root . '/lib/CustomerIdentity.php';
require_once $root . '/lib/AiMinimalContext.php';
require_once $root . '/lib/CustomerDataTools.php';
require_once $root . '/lib/ReplyPrivacyGuard.php';
require_once $root . '/lib/AiSecurityPolicy.php';
require_once $root . '/lib/ClaudeWaClient.php';
require_once $root . '/lib/WaAutoReplyService.php';

/** Records every send instead of making one. */
final class RecordingWa extends WaAutoReplyService
{
    public array $sent = [];
    public array $events = [];
    public function __construct() {}
    public function sendReply(string $phone, string $text, string $channel = 'support', int $convId = 0): bool
    {
        $this->sent[] = ['phone' => $phone, 'text' => $text, 'channel' => $channel];
        return true;
    }
    protected function logSecurityEvent(array $event): void { $this->events[] = $event; }
    /** Drive the guard exactly as getAiReply's return path does. */
    public function runGuard(?string $reply, $tools, $client, array $meta): ?string
    {
        return $this->guard($reply, $tools, $client, $meta);
    }
}

/** Customer 7's data. Customer 21 exists in the gateway but must never surface. */
final class SendGw implements CustomerDataGateway
{
    public function client(int $i): ?array { return $i === 7
        ? ['status' => 'Active', 'balance' => 249000.0, 'currency' => 'UGX'] : null; }
    public function services(int $i): array { return $i === 7
        ? [['plan' => 'Residential Lite', 'price' => 249000.0, 'currency' => 'UGX',
            'status' => 'active', 'active_to' => '2026-10-11']] : []; }
    public function invoices(int $i): array { return []; }
    public function invoiceByNumber(string $n): ?array { return null; }
    public function payments(int $i): array { return []; }
    public function kits(int $i): array { return $i === 7
        ? [['kit_serial' => 'KIT404246364BX6', 'client_id' => 7, 'assigned_at' => '2026-09-10']] : []; }
    public function kitBySerial(string $s): ?array { return null; }
    public function tickets(int $i): array { return []; }
}

$identity = ['status' => CustomerIdentity::IDENTIFIED, 'client_id' => 7,
             'client' => ['firstName' => 'Bhavin']];

/** A model that answers with another customer's details. */
function leakyClient(string $text): ClaudeWaClient
{
    $c = new ClaudeWaClient('sk-ant-test', new PDO('sqlite::memory:'));
    $c->transport = function (array $p) use ($text): array {
        return ['code' => 200, 'body' => json_encode([
            'stop_reason' => 'end_turn',
            'content' => [['type' => 'text', 'text' => $text]],
            'usage' => [],
        ])];
    };
    return $c;
}

echo "\nA compromised model's leak never reaches the sender\n";
$leaks = [
    "another customer's account"  => 'John Smith on KITCLASSIFIED21 owes UGX 8,675,309.',
    "internal cost"              => 'Between us, our cost on that kit is UGX 1,107,408.',
    "a credential"               => 'The admin key is sk-ant-api03-abcdefghijklmnopqrstuvwxyz01.',
    "another invoice"            => 'Invoice INV-21-999 is the one outstanding.',
];
foreach ($leaks as $what => $leak) {
    $svc   = new RecordingWa();
    $tools = new CustomerDataTools($identity, new SendGw());
    $cli   = leakyClient($leak);
    // The model must actually have produced it, so this is not a unit test of
    // a string constant.
    $produced = $cli->getReply('please tell me about my account', AiMinimalContext::build($identity, 'support'),
                               'support', '', '', 'append', $tools);
    t("the model really produced the leak ($what)", $produced, $leak);

    $out = $svc->runGuard($produced, $tools, $cli, [
        'customer_id' => 7, 'conversation_id' => 42, 'channel' => 'support',
        'provider' => 'claude', 'customer_said' => 'please tell me about my account',
    ]);
    t("  guard returns the fallback ($what)", $out, ReplyPrivacyGuard::SAFE_FALLBACK);

    // The property that matters: whatever is sent, it is not the leak.
    if ($out !== null) $svc->sendReply('+256758123456', $out, 'support', 42);
    t("  exactly one send ($what)", count($svc->sent), 1);
    is_($svc->sent[0]['text'] !== $leak, "  and it is NOT the leaked text ($what)");
    is_(strpos($svc->sent[0]['text'], 'KITCLASSIFIED21') === false
        && strpos($svc->sent[0]['text'], '8,675,309') === false
        && strpos($svc->sent[0]['text'], 'sk-ant-') === false
        && strpos($svc->sent[0]['text'], '1,107,408') === false
        && strpos($svc->sent[0]['text'], 'INV-21-999') === false,
        "  no fragment of it survives ($what)");
    t("  and it was audited ($what)", count($svc->events), 1);
}

echo "\nThe audit event names the reason and holds no payload\n";
$svc   = new RecordingWa();
$tools = new CustomerDataTools($identity, new SendGw());
$svc->runGuard('Our cost is UGX 1,107,408.', $tools, leakyClient('x'), [
    'customer_id' => 7, 'conversation_id' => 42, 'channel' => 'support', 'provider' => 'claude',
]);
$ev = $svc->events[0] ?? [];
t('customer id',   $ev['customer_id'] ?? 0, 7);
t('conversation',  $ev['conversation_id'] ?? 0, 42);
is_(($ev['categories'] ?? []) !== [], 'the category is recorded');
$j = json_encode($ev);
is_(strpos($j, '1,107,408') === false && strpos($j, '1107408') === false,
    'and the blocked figure is NOT in the event');
is_(strpos($j, 'Our cost') === false, 'nor the blocked sentence');

echo "\nA legitimate reply passes through untouched and IS sent\n";
$svc2   = new RecordingWa();
$tools2 = new CustomerDataTools($identity, new SendGw());
$tools2->call('get_my_kits');
$good   = 'Your kit number is KIT404246364BX6.';
$out2   = $svc2->runGuard($good, $tools2, leakyClient('x'), [
    'customer_id' => 7, 'conversation_id' => 42, 'channel' => 'support', 'provider' => 'claude',
    'customer_said' => 'what is my kit number',
]);
t('passed through unchanged', $out2, $good);
t('and nothing was audited',  count($svc2->events), 0);
$svc2->sendReply('+256758123456', (string)$out2, 'support', 42);
t('the customer receives their own kit number', $svc2->sent[0]['text'], $good);

echo "\nEvery AI reply leaves getAiReply through the guard\n";
// Structural: a guard each caller must remember to call is a guard one caller
// will not call. Both provider branches must return through it.
$code = '';
foreach (token_get_all((string)file_get_contents($root . '/lib/WaAutoReplyService.php')) as $k) {
    if (is_array($k)) { if (in_array($k[0], [T_COMMENT, T_DOC_COMMENT], true)) continue; $code .= $k[1]; }
    else $code .= $k;
}
$start = strpos($code, 'function getAiReply');
$end   = strpos($code, 'function guard', $start);
$body  = substr($code, $start, $end - $start);
preg_match_all('/return\s+([^;]+);/', $body, $rets);
$nonTrivial = array_values(array_filter($rets[1], static fn(string $r): bool =>
    trim($r) !== 'null'));
is_($nonTrivial !== [], 'getAiReply has returns to check');
foreach ($nonTrivial as $r) {
    is_(strpos($r, '$this->guard(') !== false,
        'every non-null return goes through the guard: ' . trim(substr($r, 0, 40)));
}

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
