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

$dataDir = getenv('DN_DATA_DIR') ?: getDataDir($root);
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
// The public base the operator configured, plus the loopback the API itself
// is reached on — which is proof that address works from in here.
$base      = rtrim(wa_ai_public_base($config), '/');
$apiBase   = rtrim($crm->getBaseUrl(), '/');
$localRoot = preg_replace('#/api/v[0-9.]+$#', '', $apiBase);

// uCRM serves only public.php from a plugin directory; webhook.php at its own
// path returns uCRM's 404, which is exactly what the first run of this tool
// found. The handler is routed through public.php?page=crm_webhook.
$candidates = [];
foreach ([$localRoot . '/_plugins/dishnet-hybrid-sudan', $base] as $b) {
    $b = rtrim((string)$b, '/');
    if ($b === '') continue;
    $candidates[] = $b . '/public.php?page=crm_webhook';
}
$candidates = array_values(array_unique(array_filter($candidates)));

$working = '';
foreach ($candidates as $url) {
    // A GET with no body: webhook.php answers 400 "Empty body." when it is
    // reached and running, which is exactly the proof we want. A connection
    // failure or uCRM's own 404 page is not.
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8,
        CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $body = (string)curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    $reached = $code > 0 && (stripos($body, 'Empty body') !== false || $code === 400);
    printf("    %-60s %s\n", $url,
        $reached ? "reachable (HTTP {$code})"
                 : ($code > 0 ? "HTTP {$code} — not our webhook" : 'no connection: ' . $err));
    if ($reached && $working === '') $working = $url;
}

if ($working === '') {
    no('uCRM cannot reach the plugin webhook at any address tried');
    echo "        Set plugin_public_url in the plugin Settings to the address the\n";
    echo "        plugin is served at, then rerun. If the public name is the only\n";
    echo "        option and it fails, that is the container hairpin problem again.\n";
    exit(1);
}
ok("uCRM can reach {$working}");

line();
echo "3) Registering\n";
$already = false;
foreach ($hooks as $h) {
    if (rtrim((string)($h['url'] ?? ''), '/') === rtrim($working, '/') && !empty($h['isActive'])) {
        $already = true;
    }
}
if ($already) { ok('that address is already registered and active — nothing to do'); exit(0); }

// No API resource means --fix cannot help, so say so now rather than sending
// the operator round a loop that ends here anyway.
if ($endpointUrl === '') {
    no('this uCRM exposes no webhook resource over the API — register it by hand');
    echo "\n  uCRM → System → Webhooks → Add:\n";
    echo "      URL     {$working}\n";
    echo "      Events  leave empty (all events)\n";
    echo "      Active  yes\n\n";
    echo "  Then create a quote and run tools/quote_email_doctor.php.\n";
    exit(1);
}

if (!$doFix) {
    wr('not registered. Rerun with --fix to register it:');
    echo "          php tools/webhook_setup.php --fix\n";
    exit(0);
}

// Empty event list = every event, which is what this plugin wants: it decides
// per changeType in its own switch, and a narrow list would silently drop any
// event added later.

$ch = curl_init($endpointUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15,
    CURLOPT_CUSTOMREQUEST  => 'POST',
    CURLOPT_HTTPHEADER     => [$authHdr . ': ' . $appKey, 'Content-Type: application/json'],
    CURLOPT_POSTFIELDS     => json_encode([
        'url' => $working, 'isActive' => true, 'verifySslCertificate' => false,
    ]),
    CURLOPT_SSL_VERIFYPEER => false,
]);
$raw  = (string)curl_exec($ch);
$code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
$res  = json_decode($raw, true);

if ($code >= 300 || !is_array($res) || empty($res['id'])) {
    no("registration failed (HTTP {$code}): " . substr($raw, 0, 300));
    echo "\n  Add it by hand in uCRM → System → Webhooks:\n";
    echo "      URL     {$working}\n";
    echo "      Events  leave empty (all events)\n";
    exit(1);
}
ok("registered as endpoint #{$res['id']}");

$after = $probe($endpointUrl);
$seen  = false;
foreach ((array)($after['json'] ?? []) as $h) {
    if ((int)($h['id'] ?? 0) === (int)$res['id']) $seen = true;
}
$seen ? ok('uCRM confirms it on re-read') : no('uCRM did not list it back — check the UI');

line();
echo "Create a quote now. The plugin should send the branded email, and\n";
echo "tools/quote_email_doctor.php will show the line proving it.\n";
exit(0);
