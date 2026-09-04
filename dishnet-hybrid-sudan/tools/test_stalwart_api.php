<?php
declare(strict_types=1);
/**
 * test_stalwart_api.php — probe the deployed Stalwart's management API before
 * enabling identity provisioning.
 *
 * Stalwart is a fast-moving project, so rather than trusting documented field
 * names, this exercises the real thing: create a throwaway mailbox, suspend
 * it, reset its password, and report exactly what the server said. Run it on
 * the app droplet AFTER §3 of dishnet-mail/README-DEPLOY.md:
 *
 *     php tools/test_stalwart_api.php
 *
 * Reads stalwart_api_url / stalwart_api_token from kyc_config.json like the
 * real worker. Cleans up after itself where the API allows. CLI only.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }

$pluginRoot = dirname(__DIR__);
require_once $pluginRoot . '/lib/bootstrap_data.php';
require_once $pluginRoot . '/lib/StoreInterface.php';
require_once $pluginRoot . '/lib/JsonStore.php';
require_once $pluginRoot . '/lib/SqliteStore.php';
require_once $pluginRoot . '/lib/PluginConfig.php';
require_once $pluginRoot . '/lib/MailProviderInterface.php';
require_once $pluginRoot . '/lib/StalwartProvider.php';

$dataDir  = getDataDir($pluginRoot);
$config   = PluginConfig::load($pluginRoot, $dataDir);
$provider = new StalwartProvider($config);

if (!$provider->isConfigured()) {
    exit("stalwart_api_url / stalwart_api_token not set in kyc_config.json (URL must be https)\n");
}

$probe = 'zz.api.probe.' . bin2hex(random_bytes(3)) . '@' .
         strtolower((string)($config['identity_domain'] ?? 'dishnetuganda.com'));
echo "Probing with throwaway mailbox: {$probe}\n\n";

$steps = [
    'create (ensureMailbox)'      => fn() => $provider->ensureMailbox($probe, 'API Probe', 50),
    'create again (idempotency)'  => fn() => $provider->ensureMailbox($probe, 'API Probe', 50),
    'suspend'                     => fn() => $provider->suspendMailbox($probe),
    'reset password (reactivate)' => fn() => $provider->resetPassword($probe),
];

$failed = 0;
foreach ($steps as $label => $fn) {
    $r = $fn();
    printf("  %-28s %s %s\n", $label, $r['ok'] ? 'OK  ' : 'FAIL',
        $r['ok'] ? '' : '— ' . $r['error']);
    if (!$r['ok']) $failed++;
}

echo $failed === 0
    ? "\nAll probes passed — set identity_enabled: true when ready.\n"
    : "\n{$failed} probe(s) failed. If the error names an unknown path or field,\n"
    . "set stalwart_principal_path in kyc_config.json to match this Stalwart\n"
    . "version's management API (see its /api docs), then re-run.\n";
echo "Delete the probe mailbox in the Stalwart admin UI: {$probe}\n";
exit($failed === 0 ? 0 : 1);
