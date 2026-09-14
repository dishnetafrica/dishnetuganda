<?php
declare(strict_types=1);
/**
 * test_shopbot_payload.php — what may leave this process, and nothing else.
 *
 * The egress this guards is the only one in the plugin that carries customer
 * data past every control at once: not the prompt, not the tool layer, not
 * the output guard — a straight json_encode of the internal context to a URL
 * from config. So the assertions here are about the PAYLOAD, not about
 * intentions: a hostile, over-full, deeply-nested context goes in, and what
 * comes out is compared field by field against the contract.
 */
$pass = 0; $fail = 0;
function is_(bool $c, string $m): void { global $pass, $fail;
    if ($c) { $pass++; echo "  ok   $m\n"; } else { $fail++; echo "  FAIL $m\n"; } }
function t(string $n, $got, $want) { global $pass, $fail;
    if ($got === $want) { $pass++; printf("  ok   %s\n", $n); }
    else { $fail++; printf("  FAIL %s\n       got  %s\n       want %s\n", $n, var_export($got, true), var_export($want, true)); } }

$root = dirname(__DIR__);
require_once $root . '/lib/ShopBotPayload.php';

/**
 * A context shaped exactly as AiReplyWorker::buildContext() builds one, with
 * the real field names taken from DishNetTools::normaliseCustomer(),
 * normaliseInvoice(), getCustomerServices(), getAccount() and getLineStatus().
 * Every secret-shaped value is a canary: if it appears in the JSON, it left.
 */
function fullContext(): array
{
    return [
        'channel'           => 'account',
        'transport'         => 'whatsapp',
        'medium'            => '',
        'whatsapp_instance' => 'dishnet-accounts-01',
        'customer_phone'    => '+256758123456',
        'message'           => 'what do I owe?',
        'push_name'         => 'Bhavin',
        'conversation_id'   => 4242,
        'customer' => [
            'id'        => 7,
            'name'      => 'Bhavin',
            'is_lead'   => false,
            'is_active' => true,
            'balance'   => 249000.0,
            '_raw'      => [
                'id' => 7, 'userIdent' => 'UG-0007', 'companyTaxId' => 'TIN1009876543',
                'contacts' => [['email' => 'someone@example.com', 'phone' => '+256758123456']],
                'street1' => 'Plot 42, Acacia Avenue', 'note' => 'internal: chase this one',
                'attributes' => [['key' => 'kit_serial', 'value' => 'KIT404246364BX6']],
            ],
        ],
        'services' => [[
            'id' => 331, 'name' => 'Residential Lite', 'plan_name' => 'Residential Lite',
            'plan_id' => 12, 'status' => 'active', 'active_to' => '2026-10-11',
            '_raw' => ['id' => 331, 'servicePlanId' => 12, 'note' => 'supplier cost 1107408',
                       'ip' => '100.64.12.9', 'mac' => 'A4:34:D9:1C:22:07'],
        ]],
        'line_status' => [
            'available' => true, 'splynx_id' => 9911, 'customer_status' => 'active',
            'customer_name' => 'Bhavin', 'service_address' => 'Plot 42, Acacia Avenue',
            'services' => [
                ['id' => 1, 'login' => 'bhavin01', 'password' => 'hunter2',
                 'ipv4' => '100.64.12.9', 'mac' => 'A4:34:D9:1C:22:07', 'nas_id' => 4],
                ['id' => 2, 'login' => 'bhavin02', 'ipv4' => '100.64.12.10'],
            ],
        ],
        'account' => [
            'client_id' => 7, 'name' => 'Bhavin', 'balance' => 249000.0,
            'owes' => true, 'in_credit' => false,
            'invoice' => [
                'id' => 5150, 'number' => 'INV-2026-0007', 'total' => 249000.0,
                'amount_due' => 249000.0, 'due_date' => '2026-10-01', 'created' => '2026-09-01',
                'status' => 3, '_raw' => ['id' => 5150, 'clientId' => 7, 'organizationId' => 1],
            ],
            'last_payment' => [
                'amount' => 249000.0, 'date' => '2026-09-01',
                '_raw' => ['id' => 8801, 'clientId' => 7, 'providerPaymentId' => 'MTN-77213'],
            ],
        ],
        'products' => [
            'count' => 1, 'hardware_count' => 1, 'hardware_error' => '',
            'products' => [['id' => 12, 'name' => 'Residential Lite', 'price' => 249000.0,
                            'period_months' => 1, 'download_speed' => '50Mbps',
                            'upload_speed' => '10Mbps', 'data_limit' => null,
                            '_raw' => ['costPrice' => 180000.0]]],
            'hardware' => [['id' => 44, 'name' => 'Starlink Standard Kit', 'price' => 1690000.0,
                            '_raw' => ['supplierCost' => 1107408.0]]],
        ],
        'webchat_lead' => ['name' => 'Bhavin', 'topic' => 'price of home plan', 'ip' => '41.210.0.9'],
        'history' => [
            ['role' => 'customer', 'text' => 'hello', 'wa_message_id' => 'ABC123', 'db_id' => 991],
            ['role' => 'dishnet',  'text' => 'Hello Bhavin, how can we help?'],
        ],
        'thread'      => 'On Monday you wrote: what is the price?',
        'attachments' => ['purchase-order.pdf'],
        'signature'   => "Bhavin\nDishNet Uganda",
        'constraints' => ['Promise, confirm or estimate any date'],
    ];
}

$ctx  = fullContext();
$out  = ShopBotPayload::project($ctx);
$json = json_encode($out);

echo "\nNothing raw survives\n";
// _raw is the whole upstream record. There are five of them in the context.
is_(strpos($json, '_raw') === false, 'the string "_raw" does not appear in the payload');
foreach (['UG-0007' => 'the uCRM user ident',
          'TIN1009876543' => 'the tax id',
          'someone@example.com' => 'the contact email',
          'Acacia Avenue' => 'the street address',
          'internal: chase this one' => 'the internal note',
          'KIT404246364BX6' => 'the kit serial from custom attributes',
          'MTN-77213' => 'the payment provider reference',
          'organizationId' => 'the uCRM organisation key'] as $needle => $what) {
    is_(strpos($json, (string)$needle) === false, "no {$what}");
}

echo "\nNo internal identifier survives\n";
foreach (['"id"' => 'any bare id key', '"client_id"' => 'client_id',
          '"plan_id"' => 'plan_id', '"splynx_id"' => 'splynx_id',
          '"conversation_id"' => 'conversation_id', '"nas_id"' => 'nas_id'] as $needle => $what) {
    is_(strpos($json, (string)$needle) === false, "no {$what}");
}
// 5150 is the invoice id, 331 the service id, 9911 the Splynx id, 12 the plan
// id. The invoice NUMBER survives, because that is the customer's own
// reference and the brain answers with it.
foreach (['5150' => 'invoice id', '9911' => 'Splynx id', '8801' => 'payment id'] as $n => $what) {
    is_(strpos($json, (string)$n) === false, "no {$what}");
}
is_(strpos($json, 'INV-2026-0007') !== false, 'but the customer-facing invoice number is kept');

echo "\nNo operational or security data survives\n";
foreach (['hunter2' => 'a Splynx line password',
          '100.64.12.9' => 'an IP address',
          'A4:34:D9:1C:22:07' => 'a MAC address',
          'bhavin01' => 'a line login',
          'dishnet-accounts-01' => 'the WhatsApp instance',
          '+256758123456' => 'the customer phone number',
          '41.210.0.9' => "the web lead's IP"] as $needle => $what) {
    is_(strpos($json, (string)$needle) === false, "no {$what}");
}

echo "\nNo internal financial data survives\n";
foreach (['180000' => 'the plan cost price', '1107408' => 'the kit supplier cost'] as $n => $what) {
    is_(strpos($json, (string)$n) === false, "no {$what}");
}
is_(strpos($json, '249000') !== false, 'but the RETAIL price and the balance are kept');

echo "\nThe balance travels only where it is gated\n";
// customer.balance bypasses the accounts-channel gate: buildContext sets
// customer on EVERY channel, but loads account only for accounts, after an
// unambiguous identification. Only the gated one may travel.
t('customer carries exactly two leaves', array_keys($out['customer']), ['name', 'is_lead']);
$sales = fullContext();
$sales['channel'] = 'sales';
unset($sales['account']);                      // as buildContext would leave it
$sj = json_encode(ShopBotPayload::project($sales));
is_(strpos($sj, '249000') === false || strpos($sj, '"balance"') === false,
    'on the sales channel no balance travels at all');
is_(!isset(ShopBotPayload::project($sales)['account']), 'and there is no account block');

echo "\nOnly the contract is serialised\n";
// B3.2: identity_state joins the contract; line_status and webchat_lead
// leave it — Uganda is Starlink-only, and a visitor's typed name was never
// identity.
$expected = ['channel', 'transport', 'message', 'thread', 'signature', 'customer',
             'products', 'services', 'account',
             'history', 'attachments', 'constraints'];
sort($expected);
$got = array_keys($out); sort($got);
t('the payload has exactly the expected top-level keys', $got, $expected);
foreach (array_keys($out) as $k) {
    is_(array_key_exists($k, ShopBotPayload::CONTRACT), "\"{$k}\" is declared in CONTRACT");
}

echo "\nEvery dropped key is named, and the list is what we think it is\n";
t('dropped keys', ShopBotPayload::dropped($ctx),
  ['conversation_id', 'customer_phone', 'line_status', 'medium', 'push_name',
   'webchat_lead', 'whatsapp_instance']);
is_(!array_key_exists('line_status', ShopBotPayload::CONTRACT),
    'line_status is not in the contract at all — Uganda is Starlink-only');
is_(!array_key_exists('webchat_lead', ShopBotPayload::CONTRACT),
    'nor webchat_lead');
t('identity_state survives projection',
  ShopBotPayload::project(['identity_state' => 'identified'])['identity_state'] ?? null,
  'identified');
// 'medium' is dropped only because it is empty here; it travels when set.
$em = ShopBotPayload::project(['medium' => 'email', 'message' => 'x']);
t('medium travels when it has a value', $em['medium'] ?? null, 'email');

echo "\nA hostile context cannot widen the projection\n";
$evil = fullContext();
$evil['api_key']            = 'sk-ant-api03-abcdefghijklmnopqrstuvwxyz01';
$evil['claude_api_key']     = 'sk-ant-SECRET';
$evil['shopbot_ai_token']   = 'bearer-SECRET';
$evil['config']             = ['starlink_cookie' => 'SESSION=SECRET', 'db_password' => 'SECRET'];
$evil['__proto__']          = ['polluted' => true];
$evil['customer']['_raw']['secret']   = 'SECRET';
$evil['customer']['api_key']          = 'SECRET';
$evil['products']['products'][0]['note'] = 'SECRET';
$evil['services'][0]['password']      = 'SECRET';
$evil['history'][0]['api_key']        = 'SECRET';
$evil['account']['invoice']['secret'] = 'SECRET';
$evil['line_status']['secret']        = 'SECRET';
// Keys that LOOK like contract keys but sit at the wrong depth.
$evil['customer']['channel']          = 'SECRET';
$evil['products']['message']          = 'SECRET';
$ej = json_encode(ShopBotPayload::project($evil));
is_(strpos($ej, 'SECRET') === false, 'not one planted secret reaches the payload');
is_(strpos($ej, 'sk-ant-') === false, 'no API key shape reaches it');
is_(strpos($ej, 'polluted') === false, 'a prototype-shaped key does not either');
t('and the key set is unchanged by any of it',
  count(array_keys(ShopBotPayload::project($evil))), count($got));

echo "\nHostile SHAPES do not break it open\n";
// A field that should be a string arriving as an array is the classic way a
// projection turns into "Array" — or worse, passes the nested thing through.
$shape = ['channel' => ['nested' => 'SECRET'], 'message' => ['a' => 'SECRET'],
          'customer' => 'not-an-array', 'services' => 'not-a-list',
          'history' => ['not-a-row'], 'account' => ['balance' => ['SECRET']],
          'products' => ['products' => 'SECRET', 'hardware' => ['SECRET']],
          'attachments' => [['nested' => 'SECRET']]];
$sp = ShopBotPayload::project($shape);
$spj = json_encode($sp);
is_(strpos($spj, 'SECRET') === false, 'no nested value escapes through a scalar field');
is_(strpos($spj, 'Array') === false, 'and nothing stringifies to "Array"');
is_(!isset($sp['customer']), 'a non-array customer produces no customer block');

echo "\nThe empty and absent cases are quiet\n";
t('an empty context projects to nothing', ShopBotPayload::project([]), []);
$min = ShopBotPayload::project(['channel' => 'sales', 'message' => 'hi']);
t('a minimal context projects to itself', $min, ['channel' => 'sales', 'message' => 'hi']);

echo "\nThe worker sends the projection, not the context\n";
// Structural: the one line that matters. If a future edit puts $context back
// into CURLOPT_POSTFIELDS, this fails.
$code = '';
foreach (token_get_all((string)file_get_contents($root . '/workers/AiReplyWorker.php')) as $k) {
    if (is_array($k)) { if (in_array($k[0], [T_COMMENT, T_DOC_COMMENT], true)) continue; $code .= $k[1]; }
    else $code .= $k;
}
$s = strpos($code, 'function askShopBot');
$body = substr($code, $s, strpos($code, 'curl_exec', $s) - $s);
is_(strpos($body, 'CURLOPT_POSTFIELDS=>json_encode($payload)') !== false
    || preg_match('/CURLOPT_POSTFIELDS\s*=>\s*json_encode\(\$payload\)/', $body) === 1,
    'askShopBot encodes $payload');
is_(preg_match('/json_encode\(\$context\)/', $body) === 0,
    'and never json_encodes the raw context');
is_(strpos($body, 'ShopBotPayload::project') !== false, 'the projection is applied in askShopBot');
// It must be applied BEFORE the request is built, not somewhere hopeful.
is_(strpos($body, 'ShopBotPayload::project') < strpos($body, 'curl_init'),
    'and applied before curl_init');

echo "\nThe response contract is untouched\n";
$after = substr($code, strpos($code, 'curl_exec', $s));
$after = substr($after, 0, strpos($after, 'function ', 10) ?: 1200);
is_(strpos($after, 'json_decode') !== false, 'the response is still json_decoded');
is_(strpos($after, 'is_array($data)?$data:null') !== false
    || preg_match('/is_array\(\$data\)\s*\?\s*\$data\s*:\s*null/', $after) === 1,
    'and returned unchanged — reply/escalate/escalate_reason as before');

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
