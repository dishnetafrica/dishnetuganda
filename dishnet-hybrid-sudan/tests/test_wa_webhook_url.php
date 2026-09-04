<?php
declare(strict_types=1);
/**
 * The webhook address rules: operator's plugin_public_url wins, then
 * ucrm.json/crm_base_url derivation, then the request — and never a scheme
 * with an empty host, because that garbage is exactly what Evolution refuses
 * with an error the admin page cannot explain.
 */
require_once dirname(__DIR__) . '/lib/wa_webhook_url.php';

$pass = 0; $fail = 0;
function t(string $n, $got, $want) { global $pass, $fail;
    if ($got === $want) { $pass++; printf("  ok   %s\n", $n); }
    else { $fail++; printf("  FAIL %s\n       got  %s\n       want %s\n", $n, var_export($got, true), var_export($want, true)); } }

$SAVED_SERVER = $_SERVER;
unset($_SERVER['HTTP_HOST'], $_SERVER['SCRIPT_NAME'], $_SERVER['HTTP_X_FORWARDED_PROTO'], $_SERVER['HTTPS']);

echo "Resolution order\n";
t('operator plugin_public_url wins, trailing slash trimmed',
  wa_ai_public_base(['plugin_public_url' => 'https://x.example/crm/_plugins/p/']),
  'https://x.example/crm/_plugins/p');
t('crm_base_url derivation when no operator value (repo has no ucrm.json)',
  wa_ai_public_base(['crm_base_url' => 'https://crm.example/crm/api/v2.1']),
  'https://crm.example/crm/_plugins/dishnet-hybrid-sudan');
t('CLI with nothing known returns empty, never "https://"',
  wa_ai_public_base([]),
  '');
t('webhook url is empty when the base is unknown',
  wa_ai_webhook_url([], 'secret'),
  '');
t('webhook url composition, secret rawurlencoded',
  wa_ai_webhook_url(['plugin_public_url' => 'https://x.example/p'], 'a b+c'),
  'https://x.example/p/public.php?page=evo_webhook&token=a%20b%2Bc');

echo "\nRequest-derived last resort\n";
$_SERVER['HTTP_HOST']   = 'crm.test';
$_SERVER['SCRIPT_NAME'] = '/crm/_plugins/dishnet-hybrid-sudan/public.php';
t('derived from host + script dir, https assumed',
  wa_ai_public_base([]),
  'https://crm.test/crm/_plugins/dishnet-hybrid-sudan');
$_SERVER['HTTP_X_FORWARDED_PROTO'] = 'http';
t('forwarded proto is trusted',
  wa_ai_public_base([]),
  'http://crm.test/crm/_plugins/dishnet-hybrid-sudan');
$_SERVER = $SAVED_SERVER;

echo "\nThe tools parse\n";
$phpBin = PHP_BINARY;
foreach (['tools/wa_webhook_doctor.php', 'tools/wa_prune_history.php', 'tabs/engage/wa_ai_setup.php'] as $f) {
    $out = (string)shell_exec(escapeshellarg($phpBin) . ' -l ' . escapeshellarg(dirname(__DIR__) . '/' . $f) . ' 2>&1');
    t("php -l {$f}", strpos($out, 'No syntax errors') !== false, true);
}

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail ? 1 : 0);
