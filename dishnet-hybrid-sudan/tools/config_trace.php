<?php
declare(strict_types=1);
chdir(dirname(__DIR__));

/**
 * config_trace.php — where does this setting come from, and who wins?
 *
 *   php tools/config_trace.php email_ai_mailbox_pw
 *   php tools/config_trace.php billing_model currency_code
 *   php tools/config_trace.php --all-email
 *
 * A value has been lost or overridden four separate times in this plugin now:
 * the switches read as off, the body branded itself Sudan, the Reply-To
 * pointed at the wrong country, and a live mailbox password read as missing.
 * Every one of them was the same question — which layer is winning — and every
 * one was answered by reading code and guessing.
 *
 * This answers it directly. Each layer is shown in the order it is applied,
 * with what it contributes, and the winner is named. Secrets are reported as
 * set/empty/absent with a length, never printed.
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }

$root = dirname(__DIR__);
require_once $root . '/lib/bootstrap_data.php';
require_once $root . '/lib/PluginConfig.php';
require_once $root . '/lib/ConfigVault.php';
require_once $root . '/lib/SecureFile.php';
require_once $root . '/lib/EmailTemplate.php';
require_once $root . '/lib/CustomerEmailDispatcher.php';

$dataDir = cliDataDir($root);

$keys = array_values(array_filter(array_slice($argv, 1), function ($a) {
    return strpos($a, '--') !== 0;
}));
if (in_array('--all-email', array_slice($argv, 1), true)) {
    $keys = array_merge($keys, ['email_ai_jmap_url', 'email_ai_mailbox',
                                'email_ai_mailbox_pw', 'email_ai_jmap_via',
                                'email_reply_to', 'email_company_name']);
}
if ($keys === []) {
    echo "\n  Name a key, or use --all-email.\n";
    echo "    php tools/config_trace.php email_ai_mailbox_pw\n\n";
    exit(1);
}

$secret = function (string $k): bool {
    return in_array($k, PluginConfig::SECRET_KEYS, true)
        || strpos($k, 'password') !== false || strpos($k, '_pw') !== false
        || strpos($k, 'key') !== false     || strpos($k, 'secret') !== false;
};

/** How a value should be shown: never the value itself when it is a secret. */
$describe = function ($v, bool $isSecret) {
    if ($v === null)                       return 'absent';
    if (is_string($v) && trim($v) === '')  return 'EMPTY   ← can no longer erase a real value';
    if ($isSecret)                         return 'set (' . strlen((string)$v) . ' chars)';
    if (is_bool($v))                       return $v ? 'true' : 'false';
    if (is_array($v))                      return 'array(' . count($v) . ')';
    return (string)$v;
};

// The layers, in the order the plugin applies them.
$vaultFile = ConfigVault::path($root, $dataDir);
$layers = [
    'plugin data/config.json' => $root . '/data/config.json',
    'uCRM config.json'        => $dataDir . '/config.json',
    'kyc_config.json'         => $dataDir . '/kyc_config.json',
];

$fileData = [];
foreach ($layers as $label => $path) {
    $fileData[$label] = is_file($path)
        ? (json_decode((string)@file_get_contents($path), true) ?: [])
        : null;
}
$vaultData = is_file($vaultFile)
    ? ((json_decode((string)@file_get_contents($vaultFile), true) ?: [])['config'] ?? [])
    : null;

$loaded    = PluginConfig::load($root, $dataDir);
$effective = CustomerEmailDispatcher::effectiveConfig($loaded);

echo "\n  Data dir   {$dataDir}\n";
echo "  Vault      {$vaultFile}\n";
echo "             " . (is_file($vaultFile)
        ? SecureFile::describeOwner($vaultFile) . ' · '
          . substr(sprintf('%o', @fileperms($vaultFile) ?: 0), -4)
        : 'DOES NOT EXIST') . "\n";

foreach ($keys as $key) {
    $isSecret = $secret($key);
    echo "\n  " . str_repeat('─', 66) . "\n  {$key}\n  " . str_repeat('─', 66) . "\n";

    foreach ($layers as $label => $path) {
        $d = $fileData[$label];
        printf("    %-24s %s\n", $label, $d === null
            ? '(file not present)'
            : $describe(array_key_exists($key, $d) ? $d[$key] : null, $isSecret));
    }
    printf("    %-24s %s\n", 'ConfigVault', $vaultData === null
        ? '(vault not present)'
        : $describe(array_key_exists($key, $vaultData) ? $vaultData[$key] : null, $isSecret));

    echo "    " . str_repeat('·', 66) . "\n";
    printf("    %-24s %s\n", 'PluginConfig::load()',
        $describe(array_key_exists($key, $loaded) ? $loaded[$key] : null, $isSecret));
    printf("    %-24s %s\n", 'effectiveConfig()',
        $describe(array_key_exists($key, $effective) ? $effective[$key] : null, $isSecret));

    // The one line that matters: is the thing that will actually be used usable?
    $final = $effective[$key] ?? null;
    $ok    = $final !== null && !(is_string($final) && trim($final) === '');
    echo "\n    " . ($ok ? 'IN USE' : 'NOT SET — whatever reads this key gets nothing') . "\n";
    if (!$ok && $vaultData !== null && !empty($vaultData[$key])) {
        echo "    The vault holds a value that is not reaching the reader.\n";
    }
    if (!$ok && ($vaultData === null || empty($vaultData[$key]))) {
        echo "    The vault does not hold one either — it has to be set again.\n";
    }
}
echo "\n";
exit(0);
