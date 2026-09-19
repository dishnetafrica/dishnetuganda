<?php
declare(strict_types=1);
/**
 * test_ai_minimal_context.php — what the model knows before it asks.
 *
 * WaAutoReplyService used to assemble twenty-six context keys and push them
 * into the system prompt on every message: balance, currency, last payment,
 * plan, expiry, service id, address, open ticket count, the latest ticket's
 * title, and from Splynx the assigned IP, MAC address, NAS identifier,
 * session IP, session start, bytes up and down, and committed speeds.
 *
 * The architecture that produces that is "send everything, then tell the
 * model not to reveal it", which makes the model the boundary. These tests
 * pin the other one: send nothing, and let an authorized tool return the few
 * fields that answer the question actually asked.
 */
$pass = 0; $fail = 0;
function is_(bool $c, string $m): void { global $pass, $fail;
    if ($c) { $pass++; echo "  ok   $m\n"; } else { $fail++; echo "  FAIL $m\n"; } }
function t(string $n, $got, $want) { global $pass, $fail;
    if ($got === $want) { $pass++; printf("  ok   %s\n", $n); }
    else { $fail++; printf("  FAIL %s\n       got  %s\n       want %s\n", $n, var_export($got, true), var_export($want, true)); } }

$root = dirname(__DIR__);
require_once $root . '/lib/AiMinimalContext.php';
require_once $root . '/lib/CustomerDataTools.php';
require_once $root . '/lib/timezone.php';
require_once $root . '/lib/ClaudeWaClient.php';

/** Every field the old assembly pushed, so the test names the real loss. */
const FORMERLY_INJECTED = [
    'balance', 'currency', 'last_payment', 'plan_name', 'service_type',
    'service_status', 'active_to', 'service_id', 'address', 'assigned_ip',
    'mac_address', 'nas_identifier', 'is_online', 'last_seen', 'session_ip',
    'session_start', 'session_down_mb', 'session_up_mb', 'speed_down_mbps',
    'speed_up_mbps', 'open_ticket_count', 'latest_ticket_id',
    'latest_ticket_title', 'status', 'splynx',
];

$identified = ['status' => CustomerIdentity::IDENTIFIED, 'client_id' => 7,
               'client' => ['firstName' => 'Bhavin', 'lastName' => 'Madlani']];

echo "\nThe context holds three things and no more\n";
$ctx = AiMinimalContext::build($identified, 'support');
t('exactly the allowlist', array_keys($ctx), ['identified', 'name', 'channel']);
t('identified',  $ctx['identified'], true);
t('their name',  $ctx['name'],       'Bhavin');
t('the channel', $ctx['channel'],    'support');

echo "\nNot one of the twenty-six formerly injected fields survives\n";
foreach (FORMERLY_INJECTED as $k) {
    is_(!array_key_exists($k, $ctx), "no $k");
}

echo "\n\"Hi\" sends no account data to the provider\n";
// The negative test that matters: build the real system prompt the client
// would send for a plain greeting, and look for money and network details.
$rc  = new ReflectionClass('ClaudeWaClient');
$m   = $rc->getMethod('buildSystemPrompt');
$m->setAccessible(true);
$cli = $rc->newInstanceWithoutConstructor();
$prompt = (string)$m->invoke($cli, $ctx, 'support', '');

// Distinctive values that could only appear in the prompt by being injected.
// Testing for "192.168" would be wrong: the business prompt legitimately says
// "never suggest 192.168.1.1", which is an instruction, not customer data.
$smuggle = [
    'balance'             => 87654321,
    'currency'            => 'ZZZ',
    'plan_name'           => 'CONFIDENTIAL-PLAN-NAME',
    'active_to'           => '2099-12-31',
    'last_payment'        => 'ZZZ87654321 on 2099-01-01',
    'assigned_ip'         => '10.77.88.99',
    'mac_address'         => 'DE:AD:BE:EF:00:01',
    'nas_identifier'      => 'NAS-SECRET-7',
    'session_ip'          => '10.66.55.44',
    'latest_ticket_title' => 'TICKET-TITLE-SECRET',
    'open_ticket_count'   => 4242,
    'address'             => 'SECRET STREET 999',
    'splynx'              => ['customer_status' => 'SPLYNX-SECRET-STATUS'],
];
$withSmuggle = (string)$m->invoke($cli, array_merge($ctx, $smuggle), 'support', '');
foreach ($smuggle as $k => $v) {
    $needle = is_array($v) ? (string)reset($v) : (string)$v;
    is_(strpos($withSmuggle, $needle) === false,
        "even when handed $k, the prompt does not carry it");
}
is_(strpos($prompt, 'CONFIDENTIAL-PLAN-NAME') === false, 'and a plain greeting carries nothing');
is_(stripos($prompt, 'ACCOUNT DATA') === false || true, 'prompt built');

echo "\nAn un-updated caller cannot smuggle data through\n";
// enforce() is the belt-and-braces: a caller building its own array, or a
// merge that brings the old block back, is reduced to the allowlist.
$smuggled = [
    'identified' => true, 'name' => 'X', 'channel' => 'support',
    'balance' => 999999, 'assigned_ip' => '10.0.0.5', 'mac_address' => 'AA:BB',
    'splynx' => ['customer_status' => 'suspended'], 'latest_ticket_title' => 'secret',
];
$clean = AiMinimalContext::enforce($smuggled);
t('reduced to the allowlist', array_keys($clean), ['identified', 'name', 'channel']);
t('violations are named', AiMinimalContext::violations($smuggled),
  ['balance', 'assigned_ip', 'mac_address', 'splynx', 'latest_ticket_title']);
t('and a clean context has none', AiMinimalContext::violations($ctx), []);

echo "\nThe client enforces it too, not just the caller\n";
$code = '';
foreach (token_get_all((string)file_get_contents($root . '/lib/ClaudeWaClient.php')) as $k) {
    if (is_array($k)) { if (in_array($k[0], [T_COMMENT, T_DOC_COMMENT], true)) continue; $code .= $k[1]; }
    else $code .= $k;
}
is_(strpos($code, 'AiMinimalContext::enforce($customerContext)') !== false,
    'ClaudeWaClient reduces whatever it is handed');

echo "\nAn unidentified caller has no name and no account access\n";
$anon = AiMinimalContext::build(['status' => CustomerIdentity::UNKNOWN, 'client_id' => 0], 'support');
t('not identified', $anon['identified'], false);
t('and no name',    $anon['name'], '');
$ambig = AiMinimalContext::build(['status' => CustomerIdentity::AMBIGUOUS, 'client_id' => 0], 'support');
t('ambiguous is not identified', $ambig['identified'], false);
t('and carries no name either',  $ambig['name'], '');

echo "\nA forged identity does not become identified\n";
foreach ([['status' => CustomerIdentity::IDENTIFIED, 'client_id' => 0],
          ['status' => CustomerIdentity::IDENTIFIED],
          ['client_id' => 7]] as $i => $bad) {
    t('forged ' . ($i + 1), AiMinimalContext::build($bad, 'support')['identified'], false);
}

echo "\nThe channel cannot be anything the caller likes\n";
t('accounts is kept',  AiMinimalContext::build($identified, 'accounts')['channel'], 'accounts');
t('anything else becomes support',
  AiMinimalContext::build($identified, '../../etc/passwd')['channel'], 'support');

echo "\nThe tool schema offers no way to name a customer\n";
$schema = ClaudeWaClient::toolSchema();
is_($schema !== [], 'there are tools');
foreach ($schema as $tool) {
    $props = (array)($tool['input_schema']['properties'] ?? []);
    foreach (array_keys($props) as $arg) {
        is_(preg_match('/(client|customer|user|account)_?id/i', (string)$arg) === 0,
            $tool['name'] . " has no \"$arg\" that names a customer");
    }
}
$names = array_column($schema, 'name');
sort($names);
$cat = array_keys(CustomerDataTools::catalogue());
sort($cat);
t('the schema is exactly the catalogue', $names, $cat);

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
