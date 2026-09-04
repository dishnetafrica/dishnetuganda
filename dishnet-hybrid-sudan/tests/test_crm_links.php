<?php
declare(strict_types=1);
/**
 * CRM link guard — every URL to this install's CRM or to this plugin must go
 * through lib/crm_url.php, never a hardcoded hostname or the plugin's old
 * directory name. One helper, every install (Uganda, Sudan, next country).
 */
require_once dirname(__DIR__) . '/lib/crm_url.php';

$pass = 0; $fail = 0;
function t(string $n, $got, $want) { global $pass, $fail;
    if ($got === $want) { $pass++; printf("  ok   %s\n", $n); }
    else { $fail++; printf("  FAIL %s\n       got  %s\n       want %s\n", $n, var_export($got, true), var_export($want, true)); } }

echo "Helper derivation (no ucrm.json in a repo checkout, so config decides)\n";
t('api v2.1 url -> host', dn_crm_web(['crm_base_url' => 'https://crm.dishnetuganda.com/crm/api/v2.1']), 'https://crm.dishnetuganda.com');
t('api v1.0 with slash', dn_crm_web(['crm_base_url' => 'https://crm.dishnetuganda.com/crm/api/v1.0/']), 'https://crm.dishnetuganda.com');
t('bare /crm suffix stripped', dn_crm_web(['crm_base_url' => 'https://crm.dishnetuganda.com/crm']), 'https://crm.dishnetuganda.com');
t('host-only passes through', dn_crm_web(['crm_base_url' => 'https://crm.dishnetuganda.com']), 'https://crm.dishnetuganda.com');
t('empty config -> empty string', dn_crm_web([]), '');
t('link composition', dn_crm_link(['crm_base_url' => 'https://x.example/crm/api/v2.1'], 'client/42'), 'https://x.example/crm/client/42');
t('plugin public falls back to this directory name',
  dn_plugin_public(['crm_base_url' => 'https://x.example/crm/api/v2.1']),
  'https://x.example/crm/_plugins/dishnet-hybrid-sudan/public.php');
t('plugin sibling file', dn_plugin_file(['crm_base_url' => 'https://x.example'], 'webhook.php'),
  'https://x.example/crm/_plugins/dishnet-hybrid-sudan/webhook.php');

echo "\nNo hardcoded CRM hostname or old plugin directory in live code\n";
$root = dirname(__DIR__);
$excluded = function (string $rel): bool {
    foreach (['tests/', 'retailer/', 'node_modules/', 'data/', 'dishnet-mail/'] as $d) {
        if (strpos($rel, $d) === 0) return true;
    }
    $b = basename($rel);
    // dishnet_wa_pusher: legacy standalone WASender script; api_cron_debug
    // deliberately probes historical data paths by their old names.
    return in_array($b, ['dishnet_wa_pusher.php', 'api_cron_debug.php'], true)
        || substr($b, -9) === '.disabled';
};
$violations = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($it as $file) {
    $path = $file->getPathname();
    if (substr($path, -4) !== '.php') continue;
    $rel = ltrim(str_replace($root, '', $path), '/');
    if ($excluded($rel)) continue;
    foreach (explode("\n", (string)file_get_contents($path)) as $i => $line) {
        $trim = ltrim($line);
        if ($trim === '' || $trim[0] === '*' || strpos($trim, '//') === 0 || strpos($trim, '#') === 0 || strpos($trim, '/*') === 0) continue;
        // the backup-restore allow-list legitimately names the old plugin id
        if (strpos($line, 'kyc-customer-application') !== false) continue;
        if (strpos($line, 'crm.dishnetafrica.com') !== false) {
            $violations[] = "$rel:" . ($i + 1) . "  [host]  " . trim(substr($line, 0, 120));
        }
        if (preg_match('~[\'"][^\'"\n]*dishnet-hybrid-telecom~', $line)) {
            $violations[] = "$rel:" . ($i + 1) . "  [old-name]  " . trim(substr($line, 0, 120));
        }
    }
}
t('zero hardcoded CRM hosts / old plugin names', count($violations), 0);
if ($violations) echo "    " . implode("\n    ", array_slice($violations, 0, 20)) . "\n";

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail ? 1 : 0);
