<?php
declare(strict_types=1);
chdir(dirname(__DIR__));

/**
 * wa_webhook_doctor.php — register the Evolution webhook from the terminal and
 * show Evolution's raw verdict, because the admin button can only say
 * "Evolution refused" without the detail.
 *
 * Run inside the ucrm container, from the plugin directory:
 *
 *   php tools/wa_webhook_doctor.php            diagnose AND register for every mapped number
 *   php tools/wa_webhook_doctor.php --check    diagnose only, change nothing
 *
 * Prints the webhook token only as a masked tail. CLI only.
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }

$root = dirname(__DIR__);
require_once $root . '/lib/error_handler.php';
require_once $root . '/lib/bootstrap_data.php';
require_once $root . '/lib/PluginConfig.php';
require_once $root . '/lib/EvolutionApiService.php';
require_once $root . '/lib/EvoWebhookGuard.php';
require_once $root . '/lib/wa_webhook_url.php';

$dataDir = getDataDir($root);
$config  = PluginConfig::load($root, $dataDir);
$CHECK   = in_array('--check', $argv, true);

$fails = 0;
function ok(string $m): void   { echo "  PASS  {$m}\n"; }
function bad(string $m): void  { global $fails; $fails++; echo "  FAIL  {$m}\n"; }
function info(string $m): void { echo "  info  {$m}\n"; }
function maskTokens(string $s, string $secret): string {
    if ($secret !== '') $s = str_replace($secret, '…' . substr($secret, -4), $s);
    return preg_replace('/(token=)[^&"\']*?([^&"\']{4})(?=[&"\']|$)/', '$1…$2', $s);
}

echo "WhatsApp webhook doctor — " . gmdate('Y-m-d H:i') . " UTC" . ($CHECK ? ' (check only)' : '') . "\n\n";

// ── The Evolution server itself ─────────────────────────────────────────────
$evoUrl = rtrim(trim((string)($config['evo_api_url'] ?? '')), '/');
$evoKey = trim((string)($config['evo_api_key'] ?? ''));
if ($evoUrl === '' || $evoKey === '') {
    bad('Evolution URL or key missing from Configuration');
    exit(1);
}
$ch = curl_init($evoUrl . '/');
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10,
                        CURLOPT_HTTPHEADER => ['apikey: ' . $evoKey]]);
$rootRaw  = (string)curl_exec($ch);
$rootCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
$rootJson = json_decode($rootRaw, true);
$version  = is_array($rootJson) ? (string)($rootJson['version'] ?? '') : '';
if ($version !== '') {
    ok("Evolution server answers, version {$version}");
    if ($version !== '' && $version[0] === '1') {
        info('version 1.x expects a FLAT webhook payload; this plugin sends the v2 shape.');
        info('If registration fails below, upgrade Evolution to v2 — that is the fix.');
    }
} else {
    info("Evolution root answered HTTP {$rootCode}: " . mb_substr(trim($rootRaw), 0, 160));
}

// ── The address we will register ────────────────────────────────────────────
$secret = PluginConfig::isSet_($config, 'evo_webhook_secret')
    ? (string)$config['evo_webhook_secret']
    : EvoWebhookGuard::autoSecret($dataDir);
if ($secret === '') { bad('could not obtain a webhook secret — is the data directory writable?'); exit(1); }

$url = wa_ai_webhook_url($config, $secret);
if ($url === '') {
    bad("cannot work out this plugin's public address: no plugin_public_url in Configuration,");
    bad('no pluginPublicUrl in ucrm.json, and no request context in the CLI.');
    echo "\n  Paste the plugin's public URL (UISP shows it on the plugin's own page)\n";
    echo "  into Configuration as plugin_public_url, then run this again.\n";
    exit(1);
}
echo "\n  will register: " . maskTokens($url, $secret) . "\n";
if (stripos($url, 'localhost') !== false || stripos($url, '127.0.0.1') !== false) {
    bad('that address points at localhost — Evolution runs elsewhere and could never deliver to it.');
    echo "  Set plugin_public_url in Configuration to the public https address and rerun.\n";
    exit(1);
}
if (stripos($url, 'https://') !== 0) {
    info('address is not https — fine only if Evolution can genuinely reach it.');
}

// ── Each mapped number ──────────────────────────────────────────────────────
$evo = new EvolutionApiService($config);
$live = [];
foreach ($evo->listInstances() as $i) $live[(string)($i['name'] ?? '')] = $i;

$mapped = 0;
foreach ([EvolutionApiService::CHANNEL_SALES,
          EvolutionApiService::CHANNEL_SUPPORT,
          EvolutionApiService::CHANNEL_ACCOUNT] as $chn) {
    $inst = $evo->instanceFor($chn);
    if ($inst === '') continue;
    $mapped++;
    echo "\n== {$chn} → instance '{$inst}' ==\n";
    $st = $live[$inst] ?? null;
    if ($st === null) {
        bad("Evolution has no instance named '{$inst}' — the mapping points at nothing");
        continue;
    }
    $line = "state=" . (string)($st['state'] ?? '?')
          . (!empty($st['phone']) ? ' number=' . (string)$st['phone'] : '');
    !empty($st['connected']) ? ok($line) : info($line . ' — not connected (webhook can still be registered)');

    $before = $evo->getWebhook($inst);
    info('registered before: ' . maskTokens(mb_substr(json_encode($before['data'] ?? []), 0, 260), $secret));

    if ($CHECK) continue;

    $set = $evo->setWebhook($inst, $url);
    if (!($set['ok'] ?? false)) {
        $e = $evo->getLastError();
        bad("Evolution refused the registration: " . (string)($e['detail'] ?? ($set['error'] ?? '?')));
        continue;
    }
    ok('setWebhook accepted');

    $after = $evo->getWebhook($inst);
    $afterS = json_encode($after['data'] ?? []);
    if (strpos($afterS, 'page=evo_webhook') !== false && strpos($afterS, $secret) !== false) {
        ok('verified: Evolution now holds our URL with the current token');
    } else {
        bad('setWebhook said OK but the read-back does not show our URL — Evolution answered: '
          . maskTokens(mb_substr($afterS, 0, 260), $secret));
    }
}
if ($mapped === 0) bad('no number is mapped to any instance — assign one in Engage → WhatsApp AI first');

echo "\n" . ($fails === 0 ? 'DOCTOR: PASS' : "DOCTOR: FAIL — {$fails} problem(s)") . "\n";
exit($fails === 0 ? 0 : 1);
