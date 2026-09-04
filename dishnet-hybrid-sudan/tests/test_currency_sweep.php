<?php
declare(strict_types=1);
/**
 * Currency sweep guard — no NEW hardcoded "$" money renderings.
 *
 * Every screen, message and PDF must ask dn_cur()/dn_code() (lib/currency.php)
 * so one config key (currency_symbol) moves the whole plugin between markets.
 * The Sudan-only dual-currency subsystems (retailer app, LTE stack, SSP cash
 * screens and FX widgets) legitimately render literal USD and stay exempt.
 */
require_once dirname(__DIR__) . '/lib/currency.php';

$pass = 0; $fail = 0;
function t(string $n, $got, $want) { global $pass, $fail;
    if ($got === $want) { $pass++; printf("  ok   %s\n", $n); }
    else { $fail++; printf("  FAIL %s\n       got  %s\n       want %s\n", $n, var_export($got, true), var_export($want, true)); } }

echo "Helper behaviour\n";
t('default symbol is UGX + space', dn_cur([]), 'UGX ');
t('null config tolerated', dn_cur(null), 'UGX ');
t('configured symbol wins', dn_cur(['currency_symbol' => '$']), '$ ');
t('code defaults to UGX', dn_code([]), 'UGX');
t('dollar symbol derives USD code', dn_code(['currency_code' => '', 'currency_symbol' => '$']), 'USD');
t('explicit code wins, uppercased', dn_code(['currency_code' => 'usd']), 'USD');
t('three-letter symbol doubles as code', dn_code(['currency_symbol' => 'KSh ']), 'KSH');

echo "\nNo hardcoded dollar renderings outside the exempt Sudan subsystems\n";
$root = dirname(__DIR__);

$excludedPath = function (string $rel): bool {
    foreach (['tests/', 'retailer/', 'tabs/lte/', 'workers/lte/', 'node_modules/', 'data/',
              'dishnet-mail/', 'migrations/', 'templates/', 'ucrm_email_templates/'] as $dir) {
        if (strpos($rel, $dir) === 0) return true;
    }
    $base = basename($rel);
    $sudanOnly = ['ssp_cashbook.php','ssp_imprest.php','ssp_overview.php','staff_ssp_report.php',
                  'cashbook_summary.php','retailers.php','api_retailer.php','api_lte.php',
                  'RechargeService.php','TransactionIntegrityGuard.php','CashbookReconcileWorker.php',
                  'main.php'];
    if (in_array($base, $sudanOnly, true)) return true;
    if (strpos($base, 'cron_lte') === 0 || strpos($base, 'lte_') === 0) return true;
    if (substr($base, -9) === '.disabled') return true;
    return false;
};

// Line-level exemptions: FX widget currency prefixes (JS-toggled USD/SSP pair)
// and internal log lines, where "$" genuinely means US dollars.
$exemptLine = function (string $line): bool {
    return strpos($line, 'exPfx') !== false
        || strpos($line, 'scEx_amtPfx') !== false
        || strpos($line, "pfx.textContent") !== false
        || strpos($line, 'whLog(') !== false
        || strpos($line, '->log(') !== false
        || strpos($line, 'mainLog(') !== false;
};

$patterns = [
    'quoted-$ concat'        => '~([\'"])\$\1\s*\.~',
    'escaped-\$ + number'    => '~\\\\\$[\'"]?\s*\.\s*number_format~',
    '$ before short echo'    => '~\$\s?<\?=~',
    '$ before php echo'      => '~\$\s?<\?php echo~',
    'JS quoted-$ +'          => '~([\'"])\$\1\s*\+~',
    'string-end-$ + number'  => '~[^\\\\\'"]\$[\'"]\s*\.\s*number_format~',
    'bare $ element'         => '~>\$</~',
];

$violations = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($it as $file) {
    $path = $file->getPathname();
    if (!preg_match('~\.(php|html)$~', $path)) continue;
    $rel = ltrim(str_replace($root, '', $path), '/');
    if ($excludedPath($rel)) continue;
    foreach (explode("\n", (string)file_get_contents($path)) as $i => $line) {
        if ($exemptLine($line)) continue;
        foreach ($patterns as $name => $rx) {
            if (preg_match($rx, $line)) $violations[] = "$rel:" . ($i + 1) . "  [$name]  " . trim(substr($line, 0, 140));
        }
    }
}
t('zero hardcoded money-dollar renderings', count($violations), 0);
if ($violations) echo "    " . implode("\n    ", array_slice($violations, 0, 25)) . "\n";

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail ? 1 : 0);
