<?php
declare(strict_types=1);
/**
 * test_brain_context.php — the brain is told twelve things, and only twelve.
 *
 * What this replaces: a context its caller assembled freely, most of which
 * dataBlock() rendered into every prompt whether the question needed it or
 * not — balance, invoice, last payment, plan, expiry, a Splynx line record —
 * and a good deal more that was never rendered but sat in the object anyway:
 * the complete uCRM client record, internal ids, the Splynx id and service
 * address, the customer's phone, our own WhatsApp instance.
 *
 * The contract is CONSTRUCTED, not filtered. These tests push a context full
 * of everything that used to travel through build() and check the result
 * field by field, because a filter that forgets one field is a leak and a
 * constructor that forgets one is a missing feature.
 */
$pass = 0; $fail = 0;
function is_(bool $c, string $m): void { global $pass, $fail;
    if ($c) { $pass++; echo "  ok   $m\n"; } else { $fail++; echo "  FAIL $m\n"; } }
function t(string $n, $got, $want) { global $pass, $fail;
    if ($got === $want) { $pass++; printf("  ok   %s\n", $n); }
    else { $fail++; printf("  FAIL %s\n       got  %s\n       want %s\n", $n, var_export($got, true), var_export($want, true)); } }

$root = dirname(__DIR__);
require_once $root . '/lib/timezone.php';
require_once $root . '/lib/ConversationService.php';
require_once $root . '/lib/BrainContext.php';
require_once $root . '/lib/ShopBotPayload.php';

function codeOf(string $f): string {
    $o = '';
    foreach (token_get_all((string)file_get_contents($f)) as $k) {
        if (is_array($k)) { if (in_array($k[0], [T_COMMENT, T_DOC_COMMENT], true)) continue; $o .= $k[1]; }
        else $o .= $k;
    }
    return $o;
}

/** Everything that used to reach the brain, offered to the constructor. */
function everything(): array
{
    return [
        'channel' => 'account', 'transport' => 'whatsapp', 'medium' => '',
        'message' => 'what do I owe?',
        'customer' => ['id' => 7, 'name' => 'Bhavin', 'is_lead' => false,
                       'is_active' => true, 'balance' => 249000.0,
                       '_raw' => ['companyTaxId' => 'TIN1009876543',
                                  'street1' => 'Plot 42, Acacia Avenue',
                                  'note' => 'internal: chase this one']],
        'account' => ['client_id' => 7, 'balance' => 887766.0,
                      'invoice' => ['id' => 5150, 'number' => 'INV-2026-0007',
                                    'amount_due' => 887766.0, 'due_date' => '2026-10-01'],
                      'last_payment' => ['amount' => 887766.0, 'date' => '2026-09-01',
                                         '_raw' => ['providerPaymentId' => 'MTN-77213']]],
        'services' => [['id' => 331, 'plan_id' => 12, 'name' => 'Residential Lite',
                        'status' => 'active', 'active_to' => '2026-10-11',
                        '_raw' => ['ip' => '100.64.12.9', 'mac' => 'A4:34:D9:1C:22:07']]],
        'line_status' => ['available' => true, 'splynx_id' => 9911,
                          'customer_status' => 'active',
                          'service_address' => 'Plot 42, Acacia Avenue',
                          'services' => [['login' => 'bhavin01', 'password' => 'hunter2']]],
        'products' => ['count' => 1, 'stock' => 'Kits in stock in Kampala',
                       'products' => [['id' => 12, 'name' => 'Residential Lite',
                                       'price' => 249000.0, 'period_months' => 1,
                                       'download_speed' => '50Mbps', 'upload_speed' => '10Mbps',
                                       'data_limit' => null, '_raw' => ['costPrice' => 180000.0]]],
                       'hardware' => [['id' => 44, 'name' => 'Starlink Standard Kit',
                                       'price' => 1690000.0,
                                       '_raw' => ['supplierCost' => 1107408.0]]]],
        'webchat_lead' => ['name' => 'Somebody Else', 'topic' => 'a lodge in Fort Portal'],
        'history' => [['role' => 'customer', 'text' => 'hello', 'db_id' => 991],
                      ['role' => 'dishnet', 'text' => 'Hello Bhavin.']],
        'thread' => 'On Monday you wrote...', 'attachments' => ['po.pdf'],
        'signature' => "DishNet Uganda", 'constraints' => ['Promise any date'],
        'customer_phone' => '+256758123456', 'whatsapp_instance' => 'dishnet-accounts-01',
        'conversation_id' => 4242, 'push_name' => 'Bhavin',
        'metadata' => ['anything' => 'SECRET'], 'extra' => 'SECRET', 'internal' => 'SECRET',
    ];
}

$built = BrainContext::build(ConversationService::STATE_IDENTIFIED, everything());
$json  = json_encode($built);

echo "\nTwelve keys, and only the twelve\n";
foreach (array_keys($built) as $k) {
    is_(array_key_exists($k, BrainContext::CONTRACT), "\"{$k}\" is in the contract");
}
t('and every one of them is declared', count(BrainContext::CONTRACT), 12);
t('nothing outside it was built', array_diff(array_keys($built), array_keys(BrainContext::CONTRACT)), []);

echo "\nBlanket customer data is gone\n";
foreach (['account', 'invoice', 'last_payment', 'services', 'line_status',
          'webchat_lead', 'visitor_provided_context', 'metadata', 'extra',
          'internal', 'customer_phone', 'whatsapp_instance', 'conversation_id',
          'push_name'] as $k) {
    is_(!isset($built[$k]), "no \"{$k}\" key");
}
foreach (['887766' => 'the balance', 'INV-2026-0007' => 'the invoice number',
          '2026-09-01' => 'the last payment date', '2026-10-11' => 'the service expiry',
          'TIN1009876543' => 'the tax id', 'Acacia Avenue' => 'the service address',
          'hunter2' => 'a line password', '100.64.12.9' => 'an IP',
          'A4:34:D9:1C:22:07' => 'a MAC', '+256758123456' => 'the phone number',
          'dishnet-accounts-01' => 'the WhatsApp instance', '9911' => 'the Splynx id',
          '5150' => 'the invoice id', 'MTN-77213' => 'the payment reference',
          'SECRET' => 'any planted passthrough value',
          'Somebody Else' => "the website visitor's typed name",
          'Fort Portal' => 'their typed topic',
          '180000' => 'the plan cost price', '1107408' => 'the supplier cost'] as $n => $what) {
    is_(strpos($json, (string)$n) === false, "no {$what}");
}
is_(strpos($json, '_raw') === false, 'and no _raw anywhere');
// The retail catalogue DOES survive — this is minimisation, not deletion.
is_(strpos($json, '1690000') !== false, 'but the retail kit price is kept');
is_(strpos($json, 'Kits in stock in Kampala') !== false, 'and the stock statement');

echo "\nIdentified gets a name and a posture, and nothing else\n";
t('customer carries exactly two leaves', array_keys($built['customer']), ['name', 'is_lead']);
t('the name is the verified one', $built['customer']['name'], 'Bhavin');
t('identity_state says so', $built['identity_state'], ConversationService::STATE_IDENTIFIED);
is_(strpos($json, '"id"') === false, 'and no customer id — the model never selects a customer');

echo "\nAnonymous, unknown and ambiguous get no customer block at all\n";
foreach ([ConversationService::STATE_ANONYMOUS,
          ConversationService::STATE_UNKNOWN,
          ConversationService::STATE_AMBIGUOUS] as $state) {
    $c = BrainContext::build($state, everything());
    is_(!isset($c['customer']), "{$state}: no customer block");
    // Checked outside history: our own earlier reply legitimately says the
    // name, and that is retained conversation, not an identity assertion.
    $noHist = $c; unset($noHist['history']);
    is_(strpos(json_encode($noHist), 'Bhavin') === false, "{$state}: not even a name");
    t("{$state}: the state is carried", $c['identity_state'], $state);
    // Public sales must keep working in EVERY state.
    is_(!empty($c['products']['products']), "{$state}: the catalogue is still there");
    is_(!empty($c['products']['hardware']), "{$state}: hardware too");
}
t('an unrecognised state falls back to unknown, never to identified',
  BrainContext::build('administrator', everything())['identity_state'],
  ConversationService::STATE_UNKNOWN);

echo "\nidentity_state is a posture, never a customer id\n";
foreach (BrainContext::STATES as $s) {
    is_(preg_match('/\d/', $s) === 0, "\"{$s}\" contains no identifier");
}
$bcCode = codeOf($root . '/lib/BrainContext.php');
is_(strpos($bcCode, 'client:') === false,
    'BrainContext never constructs or reads a client: key');
is_(strpos($bcCode, 'crm_client_id') === false, 'and never reads crm_client_id');

echo "\nCustomer content is carried, and stays untrusted\n";
t('the message survives', $built['message'], 'what do I owe?');
t('history survives, role and text only', array_keys($built['history'][0]), ['role', 'text']);
is_(strpos(json_encode($built['history']), 'db_id') === false, 'without the row id');
t('the quoted thread survives', $built['thread'], 'On Monday you wrote...');
t('attachment filenames survive', $built['attachments'], ['po.pdf']);

echo "\nThe builder cannot reach the message store itself\n";
// A context builder that could fetch history would be a second way to get it
// without an identity. It takes what it is given.
foreach (['getMessages', 'wa_messages', 'PDO', 'ConversationService::'] as $n) {
    is_(strpos($bcCode, $n) === false || $n === 'ConversationService::',
        "BrainContext does not reach for {$n}");
}
is_(strpos($bcCode, 'getMessagesForAi') === false, 'it does not fetch history at all');

echo "\nHostile shapes do not widen it\n";
$evil = everything();
$evil['channel']  = ['nested' => 'SECRET'];
$evil['customer'] = 'not-an-array';
$evil['products'] = ['products' => 'SECRET', 'hardware' => ['SECRET']];
$evil['history']  = ['not-a-row'];
$ev = json_encode(BrainContext::build(ConversationService::STATE_IDENTIFIED, $evil));
is_(strpos($ev, 'SECRET') === false, 'no planted value escapes through a scalar field');
is_(strpos($ev, 'Array') === false, 'and nothing stringifies to "Array"');

echo "\nThe whole contract survives the external egress boundary\n";
// B3.0 projects what leaves for an external brain. Every approved field must
// come back, or the external brain silently receives less than it needs.
$proj = ShopBotPayload::project($built);
foreach (array_keys($built) as $k) {
    is_(isset($proj[$k]), "\"{$k}\" survives ShopBotPayload::project()");
}
t('nothing was dropped', ShopBotPayload::dropped($built), []);
t('identity_state reaches the external brain', $proj['identity_state'] ?? null, 'identified');
is_(!array_key_exists('line_status', ShopBotPayload::CONTRACT),
    'and line_status is not in the egress contract either');

echo "\nUganda is Starlink-only: no Splynx anywhere in the new path\n";
// The builder BODY, not the file: NEVER_PRESENT names the excluded concepts
// on purpose, and a search that tripped on its own documentation would push
// us to stop documenting.
$bStart = strpos($bcCode, 'function build');
$bBody  = substr($bcCode, $bStart, strpos($bcCode, 'function isContract', $bStart) - $bStart);
foreach ([$bBody, codeOf($root . '/workers/AiReplyWorker.php')] as $i => $code) {
    $where = $i === 0 ? 'BrainContext::build' : 'AiReplyWorker';
    foreach (['line_status', 'getLineStatus', 'splynx', 'Splynx'] as $n) {
        is_(strpos($code, $n) === false, "{$where} has no {$n}");
    }
}
is_(array_key_exists('line_status', BrainContext::NEVER_PRESENT),
    'and NEVER_PRESENT records why it is gone');

echo "\nThe four migrated callers, and the two that are not\n";
$worker = codeOf($root . '/workers/AiReplyWorker.php');
is_(strpos($worker, 'BrainContext::build') !== false, 'the worker builds the contract for sales');
is_(strpos(codeOf($root . '/web_chat.php'), 'BrainContext::build') !== false,
    'web chat builds it');
is_(strpos(codeOf($root . '/lib/InboundMailWorker.php'), 'BrainContext::build') !== false,
    'email builds it');
// Support and accounts are deliberately NOT migrated: their plan, status,
// balance, invoice and payment have no tool replacement until B3.3, and
// enforcing the contract on them today would make them answer "I will check"
// to a customer asking what they owe.
t('the legacy callers are named and counted', BrainContext::LEGACY_CALLERS,
  ['wa_support', 'wa_accounts']);
is_(strpos($worker, 'CHANNEL_SALES') !== false,
    'and the worker gates the contract on the sales channel only');

echo "\nA legacy context is identifiable, and is not a fallback\n";
is_(!BrainContext::isContract(['channel' => 'support', 'account' => ['balance' => 1]]),
    'a legacy context is not mistaken for the contract');
is_(BrainContext::isContract($built), 'and the contract is recognised');
is_(!BrainContext::isContract(['identity_state' => 'administrator']),
    'an invented state does not make something the contract');
foreach (BrainContext::STATES as $s) {
    is_(BrainContext::isContract(['identity_state' => $s]), "\"{$s}\" is recognised");
}

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
