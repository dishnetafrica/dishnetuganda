<?php
/**
 * test_airtel_money.php — 5.18.31, Airtel Money as a way to pay DishNet.
 *
 * DishNet Uganda holds an Airtel Money Pay merchant ID (4428146, on the Airtel
 * sign at the office). A customer dials *185*9#, enters the merchant ID, the
 * amount and their PIN — free of charge to them — and gets an SMS with a
 * transaction ID. This release lets the plugin say so wherever it tells a
 * customer how to pay, from one setting, `pay_airtel_merchant`:
 *
 *   the WhatsApp quotation summary   through the REAL webhook.php under php -S,
 *                                    sending to a fake Evolution API that keeps
 *                                    every message whole (the dry-run log keeps
 *                                    200 bytes, and the payment line is later)
 *   the e-mails' "How to pay"        CustomerEmails::render, HTML and text
 *   the assistant                    the prompt it is given, and the guard that
 *                                    checks what it writes
 *   tools/ai_facts.php --uganda      the preset payment answer
 *
 * Unset — South Sudan, and this install until it is set — nothing changes.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$pass = 0; $fail = 0;
function ok(string $m): void  { global $pass; $pass++; echo "  ok   {$m}\n"; }
function bad(string $m, string $d=''): void { global $fail; $fail++; echo "  FAIL {$m}\n"; if($d)echo "       {$d}\n"; }
function is_(bool $c, string $m, string $d=''): void { $c ? ok($m) : bad($m, $d); }

require_once $root . '/lib/StoreInterface.php';
require_once $root . '/lib/JsonStore.php';
require_once $root . '/lib/SqliteStore.php';
require_once $root . '/lib/PaymentOptions.php';
require_once $root . '/lib/CustomerEmails.php';
require_once $root . '/lib/ReplyPrivacyGuard.php';
require_once $root . '/lib/DishNetAiBrain.php';

$tmp = sys_get_temp_dir() . '/dn_airtel_' . getmypid();
exec('rm -rf ' . escapeshellarg($tmp));
@mkdir($tmp, 0777, true);
ini_set('error_log', $tmp . '/php_errors.log');
$procs = [];
register_shutdown_function(function () use (&$procs, $tmp) {
    foreach ($procs as $p) { if (is_resource($p)) { proc_terminate($p); proc_close($p); } }
    exec('rm -rf ' . escapeshellarg($tmp));
});

$M = '4428146';

// ── A. the setting ─────────────────────────────────────────────────────────
echo "\nA. pay_airtel_merchant — digits or nothing\n";
foreach ([[[], ''], [['pay_airtel_merchant' => ''], ''], [['pay_airtel_merchant' => 'omit'], ''],
          [['pay_airtel_merchant' => '123'], ''], [['pay_airtel_merchant' => '12345678901'], ''],
          [['pay_airtel_merchant' => '44281a6'], ''],
          [['pay_airtel_merchant' => $M], $M], [['pay_airtel_merchant' => ' 442 8146 '], $M],
          [['pay_airtel_merchant' => '442-8146'], $M]] as [$cfg, $want]) {
    is_(PaymentOptions::airtelMerchant($cfg) === $want,
        ($cfg === [] ? 'unset' : var_export($cfg['pay_airtel_merchant'], true)) . ' → ' . ($want === '' ? 'none' : $want));
}
is_(PaymentOptions::AIRTEL_USSD === '*185*9#', "the code is Airtel Uganda's Airtel Money Pay, *185*9#");
is_(PaymentOptions::quoteLines([]) === "💳 Cash / Transfer / Card\n", 'unset, the quotation line is the one it always was');
$ql = PaymentOptions::quoteLines(['pay_airtel_merchant' => $M]);
is_($ql === "💳 Airtel Money: Merchant ID {$M}, dial *185*9#\n💵 Or Cash / Transfer / Card\n",
    'set: Airtel Money first, then everything offered before', json_encode($ql));
$line = explode("\n", $ql)[0];
is_(substr_count($line, '*') === 2 && substr($line, -7) === '*185*9#',
    "the code ends its line, and the line has no asterisk but the code's own (WhatsApp bold)", $line);
is_(PaymentOptions::emailRows([]) === [] && PaymentOptions::factSentence([]) === '', 'unset: no e-mail row, no sentence for the assistant');
$sc = (string)file_get_contents($root . '/tools/set_config.php');
is_(strpos($sc, "'pay_airtel_merchant' => ['text',") !== false, 'it is set with tools/set_config.php');

// ── B. the WhatsApp quotation, through the real webhook ─────────────────────
echo "\nB. The quotation summary on WhatsApp — the real webhook, the whole message\n";
$http = function (string $url, ?string $body = null, array $headers = [], int $timeout = 10): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 3,
                            CURLOPT_HTTPHEADER => $headers, CURLOPT_PROXY => '']);
    if ($body !== null) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, $body); }
    $out = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return [$code, $out === false ? null : (string)$out];
};
$start = function (string $cmd, callable $isUp, int $base, int $step, int $span, ?array $env = null) use (&$procs): int {
    foreach (range(0, 9) as $slot) {
        $port = $base + ((getmypid() + $slot * $step) % $span);
        if ($isUp($port, true)) continue;                              // somebody else's server
        $p = proc_open(str_replace('{PORT}', (string)$port, $cmd),
                       [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, null, $env);
        $ok = false;
        for ($i = 0; $i < 50 && !$ok; $i++) { usleep(100000); $ok = $isUp($port, false); }
        if ($ok) { $procs[] = $p; return $port; }
        if (is_resource($p)) { proc_terminate($p); proc_close($p); }
    }
    return 0;
};
$token = bin2hex(random_bytes(8));
$crmPort = $start(sprintf('exec php -S 127.0.0.1:{PORT} %s', escapeshellarg($root . '/tests/fixtures/fake_ucrm_kyc.php')),
    function (int $port, bool $probe) use ($http, $token): bool {
        [, $b] = $http("http://127.0.0.1:{$port}/__test/ping", null, [], 2);
        if ($b === null) return false;
        $j = json_decode($b, true);
        return $probe ? true : (($j['marker'] ?? '') === 'FAKE-UCRM-KYC' && ($j['token'] ?? '') === $token);
    }, 10700, 17, 90, ['FAKE_UCRM_KYC_TOKEN' => $token] + getenv());
$evoFixture = $root . '/tests/fixtures/fake_evo_server.php';
$evoPort = $start(sprintf('exec php -S 127.0.0.1:{PORT} %s', escapeshellarg($evoFixture)),
    function (int $port, bool $probe) use ($http, $evoFixture): bool {
        if (!$probe) @unlink(sys_get_temp_dir() . '/fake_evo_state_' . md5($evoFixture . $port) . '.json');
        [, $b] = $http("http://127.0.0.1:{$port}/__test/state", null, [], 2);
        return $b !== null && ($probe || strpos($b, 'FAKE-EVO-TEST') !== false);
    }, 10900, 19, 90, getenv());
if ($crmPort === 0 || $evoPort === 0) { bad('the fake uCRM and the fake Evolution API started'); printf("\n%d passed, %d failed\n", $pass, $fail); exit(1); }
$evoState = sys_get_temp_dir() . '/fake_evo_state_' . md5($evoFixture . $evoPort) . '.json';
@unlink($evoState);
register_shutdown_function(function () use ($crmPort, $evoState) {
    @unlink(sys_get_temp_dir() . '/fake_ucrm_kyc_' . $crmPort . '.json');
    @unlink($evoState);
});
$BASE = "http://127.0.0.1:{$crmPort}/api/v2.1";
$ctl = function (string $path, ?array $body = null) use ($http, $crmPort): array {
    [, $b] = $http("http://127.0.0.1:{$crmPort}{$path}", $body === null ? null : json_encode($body),
                   $body === null ? [] : ['Content-Type: application/json']);
    return json_decode((string)$b, true) ?? [];
};

// Each run gets its own data directory (SqliteStore keeps per-path state);
// the webhook reads which one from this pointer on every request.
file_put_contents($tmp . '/router.php', '<?php
declare(strict_types=1);
$root = ' . var_export($root, true) . ';
require_once $root . "/lib/timezone.php"; dn_tz_apply();
require_once $root . "/lib/StoreInterface.php";
require_once $root . "/lib/SqliteStore.php";
$dataDir = trim((string)file_get_contents(' . var_export($tmp . '/datadir', true) . '));
$store   = SqliteStore::create($dataDir);
$config  = $store->load("kyc_config.json") ?? [];
require $root . "/webhook.php";
');
$first = $tmp . '/data0';
@mkdir($first, 0777, true);
file_put_contents($tmp . '/datadir', $first);
SqliteStore::create($first);
$webPort = $start(sprintf('exec php -S 127.0.0.1:{PORT} %s', escapeshellarg($tmp . '/router.php')),
    function (int $port, bool $probe) use ($http): bool {
        [, $b] = $http("http://127.0.0.1:{$port}/webhook.php", null, [], 2);
        return $b !== null && ($probe || strpos($b, 'POST required') !== false);
    }, 11100, 23, 90, getenv());
if ($webPort === 0) { bad('the plugin started under php -S'); printf("\n%d passed, %d failed\n", $pass, $fail); exit(1); }

/** One quotation, sent the way uCRM sends quote.add; returns the WhatsApp text the customer got. */
function quotation(array $extra, string $phone, int $n): array {
    global $ctl, $http, $tmp, $BASE, $evoPort, $webPort, $evoState;
    $dir = $tmp . '/data' . $n;
    @mkdir($dir, 0777, true);
    file_put_contents($tmp . '/datadir', $dir);
    $s = SqliteStore::create($dir);
    $s->save('kyc_config.json', [
        'crm_base_url' => $BASE, 'crm_auth_token' => 'FAKE-TEST-KEY', 'data_dir' => $dir,
        'webhook_secret' => 'testsecret', 'currency_code' => 'UGX',
        'evo_api_url' => "http://127.0.0.1:{$evoPort}", 'evo_api_key' => 'k', 'evo_instance_support' => 'ug-support',
        'contact_sales_phone' => '+256 705 993 348', 'contact_shop_phone' => '+256 705 993 348',
    ] + $extra);
    $ctl('/__test/reset?scenario=uganda');
    $ctl('/__test/set', ['clients' => [['id' => 555, 'firstName' => 'Walk', 'lastName' => 'In', 'street1' => 'Plot 9',
        'contacts' => [['phone' => $phone, 'email' => '']]]]]);
    $http("http://127.0.0.1:{$GLOBALS['crmPort']}/api/v2.1/clients/555/quotes", json_encode(['items' => [
        ['label' => 'Residential Lite (TEST)', 'quantity' => 1, 'price' => 249000],
        ['label' => 'Starlink Mini Kit (TEST)', 'quantity' => 1, 'price' => 2249000]]]),
        ['Content-Type: application/json', 'X-Auth-App-Key: FAKE-TEST-KEY']);
    $http("http://127.0.0.1:{$webPort}/webhook.php", json_encode(['changeType' => 'quote.add', 'entity' => 'quote',
        'entityId' => 77, 'uuid' => 'airtel-' . $n, 'extraData' => ['entity' => ['id' => 77]]]),
        ['Content-Type: application/json', 'X-Ucrm-Key: testsecret'], 90);
    $http("http://127.0.0.1:{$webPort}/webhook.php", null, [], 90);    // php -S: waits for the handler to finish
    $st = json_decode((string)@file_get_contents($evoState), true) ?: [];
    $digits = preg_replace('/\D/', '', $phone);
    $texts = array_values(array_filter((array)($st['text_calls'] ?? []), fn($c) => preg_replace('/\D/', '', (string)$c['number']) === $digits));
    $media = array_values(array_filter((array)($st['media_calls'] ?? []), fn($c) => preg_replace('/\D/', '', (string)$c['number']) === $digits));
    return [array_map(fn($c) => (string)$c['text'], $texts), $media];
}

[$texts, $media] = quotation([], '+256 700 000 701', 1);
$sum = $texts[0] ?? '';
is_(count($texts) === 1 && strpos($sum, "📄 *Quotation & Order Summary*") === 0,
    'unset: the customer gets the Quotation & Order Summary, sent whole', json_encode($texts));
is_(strpos($sum, "*\n\n💳 Cash / Transfer / Card\n✅ Reply *YES* to proceed.") !== false,
    '…with the payment line exactly as before', $sum);
is_(strpos($sum, 'Airtel') === false && count($media) === 1, '…no Airtel Money, and the PDF follows as before');

[$texts, $media] = quotation(['pay_airtel_merchant' => $M], '+256 700 000 702', 2);
$sum = $texts[0] ?? '';
is_(strpos($sum, "📄 *Quotation & Order Summary*") === 0, 'set: the same summary goes out');
is_(strpos($sum, "*\n\n💳 Airtel Money: Merchant ID {$M}, dial *185*9#\n💵 Or Cash / Transfer / Card\n✅ Reply *YES* to proceed.") !== false,
    '…telling the customer to pay Merchant ID ' . $M . ' by dialling *185*9#, then the old choices', $sum);
$airtelLine = '';
foreach (explode("\n", $sum) as $l) if (strpos($l, '*185*9#') !== false) $airtelLine = $l;
is_(substr_count($airtelLine, '*') === 2, "the code's line carries no other asterisk in the message as sent", $airtelLine);
is_(count($media) === 1, '…and the PDF still follows');

// ── C. the e-mails ─────────────────────────────────────────────────────────
echo "\nC. \"How to pay\" in the e-mails\n";
$UG = ['email_company_name' => 'DishNet Africa Limited', 'email_currency' => 'UGX',
       'email_bank_beneficiary' => 'DISHNET AFRICA LIMITED', 'email_bank_name' => 'Ecobank Uganda Limited',
       'email_bank_account_ugx' => '7247510191'];
$D = ['name' => 'Sample Customer', 'amount' => 329000, 'invoice_number' => 'INV-1', 'quote_number' => 'Q-1',
      'due_date' => '1 Oct 2026', 'date' => '1 Oct 2026'];
$withPay = 0;
foreach (array_keys(CustomerEmails::CATALOGUE) as $k) {
    if ($k === 'login_code') continue;
    $plain = CustomerEmails::render($k, $UG, $D);
    $blank = CustomerEmails::render($k, $UG + ['pay_airtel_merchant' => ''], $D);
    $bad   = CustomerEmails::render($k, $UG + ['pay_airtel_merchant' => 'not-a-number'], $D);
    $set   = CustomerEmails::render($k, $UG + ['pay_airtel_merchant' => $M], $D);
    is_($plain === $blank && $plain === $bad, "{$k}: unset, blank or unreadable — the same e-mail as without the setting");
    if (strpos($plain['text'], 'How to pay:') === false) {
        is_($set === $plain, "{$k}: an e-mail without a How to pay block gets none");
        continue;
    }
    $withPay++;
    is_(strpos($set['text'], "How to pay:\r\n  Airtel Money: Merchant ID {$M} — dial *185*9#\r\n  Beneficiary: DISHNET AFRICA LIMITED") !== false,
        "{$k}: the text part lists Airtel Money first, then the bank", $set['text']);
    is_(strpos($set['html'], 'Airtel Money') !== false && strpos($set['html'], "Merchant ID {$M} — dial *185*9#") !== false
        && strpos($set['html'], '7247510191') !== false, "{$k}: and so does the HTML part, bank details kept");
    is_(strpos($plain['text'] . $plain['html'], 'Airtel') === false, "{$k}: unset, no Airtel Money anywhere");
}
is_($withPay >= 3, 'the e-mails that tell a customer how to pay were all checked', $withPay . ' of them');
$only = CustomerEmails::render('invoice', ['pay_airtel_merchant' => $M], $D);
is_(strpos($only['text'], "How to pay:\r\n  Airtel Money: Merchant ID {$M}") !== false,
    'with no bank configured, Airtel Money alone still tells the customer how to pay', $only['text']);

// ── D. the assistant ───────────────────────────────────────────────────────
echo "\nD. The assistant: the answer it is given, and what the guard lets it say\n";
$LIVE = 'Pay by bank transfer to DishNet Africa Limited at Ecobank Uganda Limited, Head Office branch, Plot 4 '
      . 'Parliament Avenue, Kampala. UGX account number 7247510191. Enter the account name and number exactly as '
      . 'written. Use your own name or your quotation number as the payment reference, then send the transfer '
      . 'confirmation here so we can match it to your order.';
$NEW = 'Pay by Airtel Money or by bank transfer. Airtel Money: dial *185*9#, enter Merchant ID 4428146, the amount '
     . 'and your Airtel Money PIN — it is free of charge for the customer. The merchant name is DISHNET AFRICA LTD, and '
     . 'at our office customers can also scan the Airtel Money QR code with the My Airtel app. Bank transfer: DishNet '
     . 'Africa Limited at Ecobank Uganda Limited, Head Office branch, Plot 4 Parliament Avenue, Kampala. UGX account '
     . 'number 7247510191. Enter the account name and number exactly as written, and use your own name or your '
     . 'quotation number as the payment reference. After paying either way, send the Airtel Money transaction ID or '
     . 'the transfer confirmation here so we can match it to your order.';
$prompt = function (string $pay): string {
    $b = new DishNetAiBrain(['ai_provider' => 'openai', 'openai_api_key' => 'k', 'ai_fact_payment' => $pay]);
    return $b->promptPreview(['channel' => 'sales', 'message' => 'how do I pay?', 'identity_state' => 'unknown']);
};
$pLive = $prompt($LIVE);
is_(strpos($pLive, '- PAYMENT: ' . $LIVE) !== false && strpos($pLive, 'USSD') === false,
    "today's answer (no USSD code) reads exactly as before — no new rule appears");
$pNew = $prompt($NEW);
is_(strpos($pNew, '- PAYMENT: ' . $NEW) !== false, 'the new answer reaches the prompt word for word');
is_(strpos($pNew, 'Write the USSD code *185*9# exactly as written, and never on a line that has any other asterisk') !== false,
    "and the prompt tells it to keep the code's line free of bold asterisks");
$pub = DishNetAiBrain::operatorText(['ai_fact_payment' => $NEW]);
$chk = fn(string $r) => ReplyPrivacyGuard::check($r, ['values' => [], 'prompt' => $pNew, 'public' => $pub]);
$r1 = $chk("You can pay with Airtel Money: dial *185*9#, enter Merchant ID 4428146, the amount and your PIN.\nThen send the transaction ID here.");
is_(!empty($r1['safe']), 'a reply giving the merchant ID and the code is sent', json_encode($r1['categories'] ?? []));
$r2 = $chk($NEW);
is_(!empty($r2['safe']), 'and so is the whole answer, repeated word for word', json_encode($r2['categories'] ?? []));
$r3 = $chk('You can pay into Ecobank account 7247519999 instead.');
is_(empty($r3['safe']), 'while an account number the answer does not contain is still refused (control)');

// ── E. tools/ai_facts.php --uganda ──────────────────────────────────────────
echo "\nE. The Uganda preset for the assistant's payment answer\n";
$facts = function (array $cfg) use ($root, $tmp): string {
    static $n = 0;
    $dir = $tmp . '/facts' . (++$n);
    @mkdir($dir, 0777, true);
    file_put_contents($dir . '/kyc_config.json', json_encode($cfg));
    $vault = $tmp . '/vault' . $n . '.json';
    exec('DN_DATA_DIR=' . escapeshellarg($dir) . ' DN_VAULT_FILE=' . escapeshellarg($vault)
       . ' php ' . escapeshellarg($root . '/tools/ai_facts.php') . ' --uganda 2>&1', $o, $code);
    $saved = json_decode((string)@file_get_contents($dir . '/kyc_config.json'), true) ?: [];
    return (string)($saved['ai_fact_payment'] ?? ('exit ' . $code . ': ' . implode(' ', $o)));
};
$bank = ['email_bank_beneficiary' => 'DISHNET AFRICA LIMITED', 'email_bank_name' => 'Ecobank Uganda Limited',
         'email_bank_account_ugx' => '7247510191', 'email_bank_account_usd' => '7247510202', 'email_bank_swift' => 'ECOCUGKA'];
$without = $facts($bank);
is_(strpos($without, 'payment is by bank transfer to DISHNET AFRICA LIMITED') === 0 && strpos($without, 'Airtel') === false,
    'unset: the preset writes the bank answer it always wrote', $without);
$with = $facts($bank + ['pay_airtel_merchant' => $M]);
is_(strpos($with, 'Or by Airtel Money, free of charge to the customer: dial *185*9#, enter Merchant ID ' . $M) !== false,
    'set: it adds Airtel Money, from the setting', $with);
is_(strpos($with, '7247510191') !== false && strpos($with, 'These are the only payment details we have') !== false,
    '…keeping the bank details and the rule against inventing another method');
$src = (string)file_get_contents($root . '/tools/ai_facts.php');
is_(strpos($src, $M) === false, 'the merchant ID is configuration — it is not written into the tool');

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
