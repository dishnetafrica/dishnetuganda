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
echo "1) What uCRM has registered now\n";
$hooks = $crm->get('webhooks/endpoints');
if ($hooks === null) {
    no('the webhooks endpoint itself failed: ' . json_encode($crm->getLastError()));
    echo "        That is different from having none registered — the API refused\n";
    echo "        the question. Check the App Key's permissions in uCRM.\n";
    exit(1);
}
if (!$hooks) {
    no('NO endpoints registered — uCRM cannot notify the plugin of anything');
} else {
    foreach ($hooks as $h) {
        printf("    #%-4s %-6s %s\n", (string)($h['id'] ?? '?'),
               !empty($h['isActive']) ? 'active' : 'OFF', (string)($h['url'] ?? ''));
    }
}

line();
echo "2) Which address can uCRM actually reach?\n";
// The public base the operator configured, plus the loopback the API itself
// is reached on — which is proof that address works from in here.
$base      = rtrim(wa_ai_public_base($config), '/');
$apiBase   = rtrim($crm->getBaseUrl(), '/');
$localRoot = preg_replace('#/api/v[0-9.]+$#', '', $apiBase);

$candidates = [];
if ($localRoot !== '') $candidates[] = $localRoot . '/_plugins/dishnet-hybrid-sudan/webhook.php';
if ($base !== '')      $candidates[] = $base . '/webhook.php';
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

if (!$doFix) {
    wr('not registered. Rerun with --fix to register it:');
    echo "          php tools/webhook_setup.php --fix\n";
    exit(0);
}

// Empty event list = every event, which is what this plugin wants: it decides
// per changeType in its own switch, and a narrow list would silently drop any
// event added later.
$res = $crm->createWebhook($working, [], false);
if (!is_array($res) || empty($res['id'])) {
    no('registration failed: ' . json_encode($crm->getLastError()));
    exit(1);
}
ok("registered as endpoint #{$res['id']}");

$after = $crm->get('webhooks/endpoints') ?: [];
$seen  = false;
foreach ($after as $h) if ((int)($h['id'] ?? 0) === (int)$res['id']) $seen = true;
$seen ? ok('uCRM confirms it on re-read') : no('uCRM did not list it back — check the UI');

line();
echo "Create a quote now. The plugin should send the branded email, and\n";
echo "tools/quote_email_doctor.php will show the line proving it.\n";
exit(0);
