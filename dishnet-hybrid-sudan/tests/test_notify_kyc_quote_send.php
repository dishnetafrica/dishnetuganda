<?php
/**
 * test_notify_kyc_quote_send.php — 5.18.54, docs/46 row 18 (D-8): uCRM's send for a KYC quote, behind a prepared switch.
 *
 * A KYC quote asks uCRM to send it (PATCH billing/quotes/{id}/send), which has uCRM e-mail the quotation, whatever else
 * e-mails it (docs/45 D2a), and the answer was dropped. Who owns the quotation e-mail is decision O6, so the call is
 * kept: kyc_quote_send_via_crm unset (or on) makes it exactly as before, in both countries; 0 makes none. On Uganda a
 * refusal leaves one line in uCRM's log for the plugin, naming the quote and never the customer.
 *
 * Driven through the real KycService::process(), from a copy of lib/ so the plugin's log line lands in the copy, against
 * the fake uCRM (fixtures/fake_ucrm_kyc.php): both places the send is made — a new customer's quote (postQuote, which the
 * retry job shares) and an existing customer's additional service — and the switch set by the real tools/set_config.php.
 *
 *   1. Uganda, unset: the call, once, on both paths (today's behaviour)
 *   2. Uganda, 0: no call; the quote is still made in uCRM and its WhatsApp still queued
 *   3. Uganda, 1: the call
 *   4. Uganda, uCRM refuses: the refusal is logged, masked, naming the quote only; the quote and its WhatsApp stand
 *   5. South Sudan, unset: the call, and a refusal logs nothing (5.18.53); 0 is read there too (§0.1)
 *   6. tools/set_config.php sets it, and the form then makes no call
 *   7. Weakened copies, each caught (skipped with --no-mutants)
 *
 * Every person, number and document is fictitious; nothing leaves the machine.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$withMutants = !in_array('--no-mutants', $argv, true);
$pass = 0; $fail = 0;
function is_(bool $c, string $m, string $d = ''): void {
    global $pass, $fail;
    if ($c) { $pass++; echo "  ok   {$m}\n"; }
    else    { $fail++; echo "  FAIL {$m}" . ($d !== '' ? "\n       {$d}" : '') . "\n"; }
}

$tmp = sys_get_temp_dir() . '/dn_kyc_qsend_' . getmypid();
exec('rm -rf ' . escapeshellarg($tmp));
@mkdir($tmp, 0777, true);
$procs = [];
register_shutdown_function(function () use (&$procs, $tmp) {
    foreach ($procs as $p) { if (is_resource($p)) { proc_terminate($p); proc_close($p); } }
    exec('rm -rf ' . escapeshellarg($tmp));
});

$http = function (string $url, ?string $body = null): ?array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_PROXY => '']);
    if ($body !== null) {
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => ['Content-Type: application/json']]);
    }
    $out = curl_exec($ch); curl_close($ch);
    return $out === false ? null : (json_decode((string)$out, true) ?? []);
};

// ── The fake uCRM ────────────────────────────────────────────────────────────────────────────────────────────────────
$token = bin2hex(random_bytes(8));
$crmPort = 0;
foreach (range(0, 9) as $slot) {
    $port = 11700 + ((getmypid() + $slot * 23) % 90);
    if ($http("http://127.0.0.1:{$port}/__test/ping") !== null) continue;       // somebody else's server
    $p = proc_open(sprintf('exec php -S 127.0.0.1:%d %s', $port, escapeshellarg($root . '/tests/fixtures/fake_ucrm_kyc.php')),
                   [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, null,
                   ['FAKE_UCRM_KYC_TOKEN' => $token] + getenv());
    for ($i = 0; $i < 50; $i++) {
        usleep(100000);
        $r = $http("http://127.0.0.1:{$port}/__test/ping");
        if ($r !== null) break;
    }
    if (($r['marker'] ?? '') === 'FAKE-UCRM-KYC' && ($r['token'] ?? '') === $token) { $procs[] = $p; $crmPort = $port; break; }
    if (is_resource($p)) { proc_terminate($p); proc_close($p); }
}
if ($crmPort === 0) { is_(false, 'the fake uCRM started'); echo "\n{$pass} passed, {$fail} failed\n"; exit(1); }
register_shutdown_function(function () use ($crmPort) { @unlink(sys_get_temp_dir() . '/fake_ucrm_kyc_' . $crmPort . '.json'); });
$BASE = "http://127.0.0.1:{$crmPort}/api/v2.1";
$fake = function (string $path, ?array $body = null) use ($http, $crmPort): array {
    return $http("http://127.0.0.1:{$crmPort}{$path}", $body === null ? null : json_encode($body)) ?? [];
};
/** The requests the fake received for one method and path pattern (under /api/v2.1). */
$calls = function (string $method, string $re) use ($fake): array {
    return array_values(array_filter($fake('/__test/log'), function ($e) use ($method, $re) {
        return ($e['method'] ?? '') === $method && preg_match('#^/api/v2\.1' . $re . '$#', (string)($e['path'] ?? ''));
    }));
};

/**
 * A copy of the code under test — lib/, and profiles/, which the tenant gate reads — with $edits applied:
 * [dir, every edit applied exactly once]. Without profiles/ the gate cannot resolve a tenant and reads false everywhere,
 * which would make every South Sudan assertion here pass for the wrong reason.
 */
function tree_copy(string $root, string $tmp, array $edits = []): array
{
    static $n = 0;
    $dir = $tmp . '/tree' . (++$n);
    mkdir($dir . '/data', 0777, true);
    foreach (['lib', 'profiles'] as $d) exec('cp -R ' . escapeshellarg($root . '/' . $d) . ' ' . escapeshellarg($dir . '/'));
    foreach ($edits as [$rel, $old, $new]) {
        $f = $dir . '/' . $rel;
        $src = (string)@file_get_contents($f);
        if (substr_count($src, $old) !== 1) return [$dir, false];
        file_put_contents($f, str_replace($old, $new, $src));
    }
    return [$dir, true];
}

const AGENT = ['id' => 7, 'name' => 'Test Agent', 'role' => 'sales', 'is_admin' => false];

/**
 * One KYC submission on $tree: a new customer ($existing = 0) or an additional service for client $existing. The data
 * directory keeps any setting already in it (the set_config case). Returns what the application recorded, the uCRM
 * send calls, and the lines the copy's plugin log and PHP's error log gained.
 */
$submit = function (string $tree, string $tenant, array $cfg, int $existing = 0, bool $refuse = false, ?string $dir = null)
    use ($tmp, $BASE, $fake, $calls): array {
    static $n = 0;
    $n++;
    $fake('/__test/reset?scenario=' . ($tenant === 'uganda' ? 'uganda' : 'sudan'));
    if ($refuse) $fake('/__test/set', ['refuse_send' => true]);
    $dir = $dir ?? "{$tmp}/data{$n}";
    @mkdir($dir, 0777, true);
    $phone = '+256 700 000 6' . str_pad((string)$n, 2, '0', STR_PAD_LEFT);
    $post  = ['action' => 'kyc_submit', 'customer_type' => 'StarLink', 'connectivity_type' => 'New Connection',
              'firstname' => 'Test', 'lastname' => 'Quotesend' . $n, 'mobile' => $phone, 'email' => '',
              'address_1' => 'Test address', 'package_choice' => '1', 'device_id' => '3', 'kitQty' => '1',
              'sales_type' => 'Credit', 'priority' => 'Low'] + ($existing > 0 ? ['customer_id' => (string)$existing] : []);
    $seed  = ['tenant_profile' => $tenant, 'currency_code' => $tenant === 'uganda' ? 'UGX' : 'USD',
              'dry_run_mode' => true, 'data_dir' => $dir] + $cfg;
    $logBefore = (string)@file_get_contents($tree . '/data/plugin.log');
    $f = "{$dir}/run.php";
    file_put_contents($f, '<?php
error_reporting(E_ALL & ~E_DEPRECATED);
ini_set("error_log", ' . var_export($dir . '/php_errors.log', true) . ');
$root = ' . var_export($tree, true) . ';
foreach (["StoreInterface", "JsonStore", "SqliteStore", "CrmApiClient", "WalletService", "CrmQueue", "EfrisClientField",
          "UcrmClientTarget", "KycService"] as $l) require_once "$root/lib/$l.php";
$dir = ' . var_export($dir, true) . ';
$s   = SqliteStore::create($dir);
$s->save("subscription_plans.json", [["id" => 1, "name" => "Residential Standard (TEST)", "customer_price" => 100, "ucrm_product_id" => 12]]);
$s->save("kyc_devices.json", [["id" => 3, "title" => "Standard Kit (TEST)", "price" => "1500", "ucrm_product_id" => 55]]);
$s->save("kyc_config.json", ($s->load("kyc_config.json") ?? []) + json_decode(' . var_export(json_encode($seed), true) . ', true));
$crm = new CrmApiClient(' . var_export($BASE, true) . ', "FAKE-TEST-KEY", "X-Auth-App-Key");
$k   = new KycService($crm, $s, new WalletService($s, $s->getPdo()), new CrmQueue($s), $dir);
$r   = $k->process(json_decode(' . var_export(json_encode($post), true) . ', true), [],
                   json_decode(' . var_export(json_encode(AGENT), true) . ', true));
$app = $s->findOne("kyc_applications.json", "id", (int)($r["data"]["application_id"] ?? 0)) ?? [];
echo json_encode(["ok" => !empty($r["success"]), "message" => (string)($r["message"] ?? ""),
                  "quote_id" => $app["quote_id"] ?? null, "quote_created" => !empty($app["quote_created"]),
                  "wa_pending" => !empty($app["wa_quote_pending"])]);
');
    exec('php ' . escapeshellarg($f) . ' 2>/dev/null', $o);
    $j = json_decode((string)end($o), true);
    $logAfter = (string)@file_get_contents($tree . '/data/plugin.log');
    return (is_array($j) ? $j : ['ok' => false, 'raw' => implode("\n", $o)]) + [
        'posted' => count($calls('POST', '/clients/\d+/quotes')),
        'sends'  => count($calls('PATCH', '/billing/quotes/\d+/send')),
        'log'    => substr($logAfter, strlen($logBefore)),
        'errlog' => (string)@file_get_contents($dir . '/php_errors.log'),
        'phone_digits' => preg_replace('/\D/', '', $phone), 'lastname' => $post['lastname'],
    ];
};
$quoteMade = function (array $x): bool {       // the control: the quote exists in uCRM and its WhatsApp is queued
    return !empty($x['ok']) && $x['posted'] === 1 && !empty($x['quote_created']) && !empty($x['wa_pending']);
};

// ══════════════════════════════════════════════════════════════════════════════
[$real] = tree_copy($root, $tmp);
$UG = 'uganda'; $SS = 'south-sudan'; $CLIENT = 901;

echo "\n1. Uganda — the switch unset: uCRM is asked to send, once, on both paths (as before)\n";
$a = $submit($real, $UG, []);
is_($quoteMade($a) && $a['sends'] === 1, 'a new customer\'s quote: made, sent by uCRM once, its WhatsApp queued', json_encode($a));
$b = $submit($real, $UG, [], $CLIENT);
is_($quoteMade($b) && $b['sends'] === 1, 'an existing customer\'s additional service: the same', json_encode($b));
is_($a['log'] === '' && $b['log'] === '', 'nothing in the plugin\'s log when uCRM accepts');

echo "\n2. Uganda — kyc_quote_send_via_crm = 0: uCRM is not asked; the quote and its WhatsApp stand\n";
$c = $submit($real, $UG, ['kyc_quote_send_via_crm' => '0']);
is_($quoteMade($c) && $c['sends'] === 0, 'a new customer: the quote is made in uCRM, never sent by it', json_encode($c));
$d = $submit($real, $UG, ['kyc_quote_send_via_crm' => '0'], $CLIENT);
is_($quoteMade($d) && $d['sends'] === 0, 'an additional service: the same', json_encode($d));
is_(strpos($c['errlog'], 'kyc_quote_send_via_crm is off') !== false, 'PHP\'s log says why the call was not made');

echo "\n3. Uganda — kyc_quote_send_via_crm = 1: asked, as when unset\n";
$e = $submit($real, $UG, ['kyc_quote_send_via_crm' => '1']);
is_($quoteMade($e) && $e['sends'] === 1, 'sent by uCRM once', json_encode($e));

echo "\n4. Uganda — uCRM refuses to send: the refusal is written down, the quote stands\n";
$f = $submit($real, $UG, [], 0, true);
is_($quoteMade($f) && $f['sends'] === 1, 'the call was made, and the quote and its WhatsApp stand', json_encode($f));
is_(preg_match('/\[quotes\] KYC quote #\d+ \(application #\d+\) was not sent by uCRM — uCRM answered HTTP 422/', $f['log']) === 1,
    'one line in uCRM\'s log for the plugin names the quote and uCRM\'s answer', $f['log']);
is_(substr_count($f['log'], "\n") === 1, 'exactly one line');
is_(strpos($f['log'], '<e-mail>') !== false && strpos($f['log'] . $f['errlog'], 'example.test') === false,
    'uCRM\'s message is kept with the address in it masked, in both logs', $f['log']);
is_(strpos($f['log'], $f['lastname']) === false && strpos(preg_replace('/\D/', '', $f['log']), $f['phone_digits']) === false,
    'the line names neither the customer nor their number');
$g = $submit($real, $UG, [], $CLIENT, true);
is_($quoteMade($g) && preg_match('/KYC quote #\d+ \(additional service, client #' . $CLIENT . '\) was not sent by uCRM/', $g['log']) === 1,
    'the additional service\'s refusal is written down too', $g['log']);

echo "\n5. South Sudan — the 5.18.53 call; the prepared switch is read there too (docs/46 §0.1)\n";
$h = $submit($real, $SS, []);
is_($quoteMade($h) && $h['sends'] === 1, 'unset: sent by uCRM once, as before', json_encode($h));
$i = $submit($real, $SS, [], 0, true);
is_($quoteMade($i) && $i['sends'] === 1 && $i['log'] === '', 'a refusal: called as before, and nothing new written', json_encode($i));
$j = $submit($real, $SS, ['kyc_quote_send_via_crm' => '0'], $CLIENT);
is_($quoteMade($j) && $j['sends'] === 0, '0: not asked — the switch works wherever someone sets it', json_encode($j));

echo "\n6. tools/set_config.php sets the switch, and the form then makes no call\n";
$cfgDir = "{$tmp}/data_setcfg";
@mkdir($cfgDir, 0777, true);
exec('DN_DATA_DIR=' . escapeshellarg($cfgDir) . ' php ' . escapeshellarg($root . '/tools/set_config.php')
     . ' --key kyc_quote_send_via_crm --value 0 2>&1', $o1, $c1);
exec('DN_DATA_DIR=' . escapeshellarg($cfgDir) . ' php ' . escapeshellarg($root . '/tools/set_config.php') . ' 2>&1', $o2, $c2);
$shown = implode("\n", $o2);
is_($c1 === 0 && $c2 === 0 && preg_match('/kyc_quote_send_via_crm\s+OFF/', $shown) === 1, 'the tool knows the key, saves it and shows it OFF',
    implode("\n", $o1));
$k = $submit($real, $UG, [], 0, false, $cfgDir);
is_($quoteMade($k) && $k['sends'] === 0, 'the form, reading that setting: the quote made, not sent by uCRM', json_encode($k));

// ══════════════════════════════════════════════════════════════════════════════
echo "\n7. Weakened copies, each caught\n";
// Each predicate names the defect itself, with the control that the quote really was made — a copy that merely broke the
// form is never counted as caught.
$KS = 'lib/KycService.php';
$mutants = [
    'the switch ignored' => [[[$KS, "        if (\$switch !== '' && \$switch !== null && !filter_var(\$switch, FILTER_VALIDATE_BOOLEAN)) {\n",
                                    "        if (false) {\n"]],
        function (string $t) use ($submit, $quoteMade, $UG) { $x = $submit($t, $UG, ['kyc_quote_send_via_crm' => '0']); return $quoteMade($x) && $x['sends'] === 1; },
        'switched off, uCRM was still asked'],
    'unset read as off' => [[[$KS, "        \$switch = \$cfg['kyc_quote_send_via_crm'] ?? '';\n", "        \$switch = \$cfg['kyc_quote_send_via_crm'] ?? '0';\n"]],
        function (string $t) use ($submit, $quoteMade, $UG) { $x = $submit($t, $UG, []); return $quoteMade($x) && $x['sends'] === 0; },
        'unset, today\'s call was lost'],
    'the answer not read' => [[[$KS, "        if (\$answer !== null) return;\n", "        return;\n"]],
        function (string $t) use ($submit, $quoteMade, $UG) { $x = $submit($t, $UG, [], 0, true); return $quoteMade($x) && $x['sends'] === 1 && $x['log'] === ''; },
        'a refusal passed in silence'],
    'the refusal logged on South Sudan' => [[[$KS, "            if (!\\NotifyGate::applies(\\NotifyGate::KYC_QUOTE_SEND, \$cfg, \$dataDir)) return;\n", '']],
        function (string $t) use ($submit, $quoteMade, $SS) { $x = $submit($t, $SS, [], 0, true); return $quoteMade($x) && $x['log'] !== ''; },
        'South Sudan wrote a line 5.18.53 does not'],
    'the additional service not behind the switch' => [[[$KS,
            "            self::sendQuoteViaCrm(\$quoteCrm, \$cfg, \$this->dataDir, \$quoteId, \"additional service, client #{\$customerId}\");   // D-8\n",
            "            \$quoteCrm->patch(\"billing/quotes/{\$quoteId}/send\");\n"]],
        function (string $t) use ($submit, $quoteMade, $UG, $CLIENT) { $x = $submit($t, $UG, ['kyc_quote_send_via_crm' => '0'], $CLIENT); return $quoteMade($x) && $x['sends'] === 1; },
        'switched off, the additional service was still sent'],
    'a new customer\'s quote not behind the switch' => [[[$KS,
            "        self::sendQuoteViaCrm(\$quoteCrm, \$cfg, method_exists(\$store, 'getDataDir') ? \$store->getDataDir() : null,\n            \$quoteId, \"application #{\$appId}\");   // D-8, below\n",
            "        \$quoteCrm->patch(\"billing/quotes/{\$quoteId}/send\");\n"]],
        function (string $t) use ($submit, $quoteMade, $UG) { $x = $submit($t, $UG, ['kyc_quote_send_via_crm' => '0']); return $quoteMade($x) && $x['sends'] === 1; },
        'switched off, the new customer\'s quote was still sent'],
];
foreach ($withMutants ? $mutants : [] as $name => [$edits, $caught, $why]) {
    [$tree, $okEdit] = tree_copy($root, $tmp, $edits);
    if (!$okEdit) { is_(false, "caught: {$name}", 'an anchor was not found exactly once'); continue; }
    $ok = (bool)$caught($tree);
    is_($ok, "caught: {$name}" . ($ok ? " ({$why})" : ''));
}

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail ? 1 : 0);
