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

echo "\nA configured base is normalised — a doubled public.php mutes the AI\n";
// Lived failure (8 Sep 2026): plugin_public_url was set to the full
// .../public.php address, so the builder produced
// .../public.php/public.php?page=evo_webhook — a 404 Evolution posted into
// for as long as nobody noticed.
$base = 'https://crm.example.test/crm/_plugins/dishnet-hybrid-sudan';
$want = $base . '/public.php?page=evo_webhook&token=abc';
foreach ([$base, $base . '/', $base . '/public.php', $base . '/public.php/', $base . '/PUBLIC.PHP'] as $variant) {
    t('base "' . substr($variant, strrpos($variant, '/') === false ? 0 : strrpos($variant, '/')) . '" builds one public.php',
      wa_ai_webhook_url(['plugin_public_url' => $variant], 'abc'), $want);
}
t('never two public.php segments',
  substr_count(wa_ai_webhook_url(['plugin_public_url' => $base . '/public.php'], 'abc'), 'public.php'), 1);

echo "\nThe doctor verifies exactly, not by substring\n";
$doc = (string)file_get_contents(dirname(__DIR__) . '/tools/wa_webhook_doctor.php');
t('doctor compares the whole URL', strpos($doc, '$afterUrl === $url') !== false, true);
t('doctor no longer blesses any URL containing the fragments',
  strpos($doc, "strpos(\$afterS, 'page=evo_webhook') !== false && strpos(\$afterS, \$secret)"), false);

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail ? 1 : 0);
