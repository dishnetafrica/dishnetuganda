<?php
declare(strict_types=1);
chdir(dirname(__DIR__));

/**
 * webhook_setup.php — make uCRM tell the plugin when things happen.
 *
 *   php tools/webhook_setup.php            inspect and probe, change nothing
 *   php tools/webhook_setup.php --fix      register the endpoint that works
 *   php tools/webhook_setup.php --delete N remove endpoint id N
 *
 * Without a registered endpoint the plugin never hears about a new invoice,
 * payment, quote or suspension — every event this plugin acts on arrives here
 * or not at all. quote_email_doctor found none registered, which is why a new
 * quote produced no branded email.
 *
 * The trap this tool exists to avoid: uCRM calls the webhook URL from INSIDE
 * its own container. A public https://crm.dishnetuganda.com/... address
 * resolves to this host's public IP, and connecting back to that from a
 * container needs NAT hairpinning the host may not do — the same wall that
 * broke the SMTP and IMAP paths. So every candidate URL is probed from where
 * uCRM will actually call it, and only a working one is registered.
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }

$root = dirname(__DIR__);
require_once $root . '/lib/bootstrap_data.php';
require_once $root . '/lib/PluginConfig.php';
require_once $root . '/lib/CrmApiClient.php';
require_once $root . '/lib/wa_webhook_url.php';

$dataDir = cliDataDir($root);
$config  = PluginConfig::load($root, $dataDir);
$doFix   = in_array('--fix', $argv, true);

function line(): void { echo str_repeat('─', 66) . "\n"; }
function ok(string $m): void { echo "  ok    {$m}\n"; }
function wr(string $m): void { echo "  warn  {$m}\n"; }
function no(string $m): void { echo "  FAIL  {$m}\n"; }

$crm = CrmApiClient::fromUcrm($root, $config);
if (!$crm || !$crm->isConfigured()) { no('no uCRM API credentials'); exit(1); }

line();
echo "1) Where does this uCRM keep its webhook endpoints?\n";
// webhooks/endpoints answers 404 here, the same way settings does. The
// resource has moved between uCRM versions and is spelled differently across
// them, so ask rather than assume — the PDF endpoint taught the same lesson.
$apiBase = rtrim($crm->getBaseUrl(), '/');
$root2   = preg_replace('#/api/v[0-9.]+$#', '', $apiBase);
$appKey  = $crm->getAppKey();
$authHdr = $crm->getAuthHeader();

$probe = function (string $url) use ($appKey, $authHdr): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => [$authHdr . ': ' . $appKey, 'Content-Type: application/json'],
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $body = (string)curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $json = json_decode($body, true);
    return ['code' => $code, 'json' => $json, 'raw' => $body];
};

$bases = array_values(array_unique(array_filter([
    $apiBase,
    $root2 . '/api/v1.0',
    $root2 . '/api/v2.0',
])));
$paths = ['webhook-endpoints', 'webhooks/endpoints', 'webhooks', 'webhook_endpoints'];

$endpointUrl = '';
$hooks = [];
foreach ($bases as $b) {
    foreach ($paths as $pth) {
        $u = $b . '/' . $pth;
        $r = $probe($u);
        $isList = $r['code'] === 200 && is_array($r['json']);
        printf("    %-52s HTTP %-4s %s\n", str_replace($root2, '', $u), (string)$r['code'],
               $isList ? 'LIST (' . count($r['json']) . ' entries)' : '');
        if ($isList && $endpointUrl === '') { $endpointUrl = $u; $hooks = $r['json']; }
    }
}

if ($endpointUrl === '') {
    no('no webhook endpoint resource answered on any API version');
    echo "        Register it by hand instead: uCRM → System → Webhooks → Add.\n";
    echo "        Section 2 below prints the URL to paste.\n";
} else {
    ok("this uCRM serves them at " . str_replace($root2, '', $endpointUrl));
    if (!$hooks) {
        no('and NONE are registered — uCRM cannot notify the plugin of anything');
    } else {
        foreach ($hooks as $h) {
            printf("    #%-4s %-6s %s\n", (string)($h['id'] ?? '?'),
                   !empty($h['isActive']) ? 'active' : 'OFF', (string)($h['url'] ?? ''));
        }
    }
}

line();
echo "2) Which address can uCRM actually reach?\n";
// One policy with the Settings button: lib/WebhookRegistrar.php. It probes the
// way uCRM will call — an empty POST that webhook.php answers 400 "Empty
// body." (a GET gets 405 "POST required."). Anything else — uCRM's own 404, a
// login redirect, no connection — is not the plugin.
require_once $root . '/lib/WebhookRegistrar.php';
$name = basename($root);
$plan = WebhookRegistrar::plan($hooks, $config, $name, [WebhookRegistrar::class, 'reaches'], $apiBase);
foreach ($plan['probed'] as $u => $p) {
    printf("    %-72s %s\n", $u, $p['reached']
        ? 'REACHED' . ($p['verify_ssl'] ? '' : ' (certificate not verifiable — registered with verification off)')
        : 'not our webhook / no connection');
}
if ($plan['action'] === 'unreachable') {
    no('uCRM cannot reach the plugin webhook at any address tried');
    echo "        Set plugin_public_url in the plugin Settings to the address the\n";
    echo "        plugin is served at, then rerun. If the public name is the only\n";
    echo "        option and it fails, that is the container hairpin problem again.\n";
    exit(1);
}
ok("uCRM can reach {$plan['url']}");
if (strpos($plan['url'], 'localhost') !== false || strpos($plan['url'], '127.0.0.1') !== false) {
    wr('that is the loopback address — it only works if webhook delivery runs in '
     . 'this same container. Prefer the public URL if it is also REACHED above.');
}

line();
echo "3) Registering\n";
foreach ($plan['reasons'] as $r) echo "    - {$r}\n";
if ($plan['action'] === 'keep') { ok('registered, active, reachable, every event — nothing to do'); exit(0); }

// No API resource means --fix cannot help, so say so now rather than sending
// the operator round a loop that ends here anyway.
if ($endpointUrl === '') {
    no('this uCRM exposes no webhook resource over the API — register it by hand');
    echo "\n  uCRM → System → Webhooks → Add:\n";
    echo "      URL     {$plan['url']}\n";
    echo "      Events  leave empty (all events)\n";
    echo "      Active  yes\n\n";
    echo "  Then create a quote and run tools/quote_email_doctor.php.\n";
    exit(1);
}
if (substr($endpointUrl, -18) !== 'webhooks/endpoints') {
    no('this uCRM answers at ' . str_replace($root2, '', $endpointUrl)
     . " but the plugin's client speaks webhooks/endpoints — register by hand as above");
    exit(1);
}

$what = $plan['action'] === 'create'
    ? "create an endpoint for every event at {$plan['url']}"
    : 'repair endpoint #' . ($plan['endpoint']['id'] ?? '?') . ': ' . implode(', ', array_map(
        function ($k, $v) { return $k === 'events' ? 'widen the event list' : ($k === 'url' ? "address → {$v}" : 'activate'); },
        array_keys($plan['changes']), $plan['changes']));
if (!$doFix) {
    wr("would {$what}. Rerun with --fix to do it:");
    echo "          php tools/webhook_setup.php --fix\n";
    exit(0);
}

$res = WebhookRegistrar::apply($crm, $plan);
foreach ($res['steps'] as $st) {
    printf("    %-10s %s %s\n", $st['step'], $st['ok'] ? 'ok' : 'FAILED', $st['ok'] ? '' : json_encode($st['error']));
}
if (!$res['success']) { no($res['message']); exit(1); }
ok($res['message']);

line();
echo "Create a quote now. The plugin should send the branded email, and\n";
echo "tools/quote_email_doctor.php will show the line proving it.\n";
exit(0);
