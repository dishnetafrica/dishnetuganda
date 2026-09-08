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
    // The reconciliation report is part of the Sudan dual-currency cash stack,
    // alongside CashbookReconcileWorker.php which is already exempt above.
    if ($base === 'cashbook_reconcile.php') return true;
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
        || strpos($line, 'mainLog(') !== false
        || strpos($line, 'rclog(')  !== false
        || strpos($line, 'mlog(')   !== false
        || strpos($line, 'ilog(')   !== false
        || strpos($line, 'qwa_log(') !== false
        || strpos($line, 'error_log(') !== false
        // The default AI knowledge block is headed "SOUTH SUDAN CONTEXT" and
        // lists that market's plans in dollars on purpose. Uganda seeds its
        // own knowledge (tools/seed_knowledge.php) rather than editing this.
        || strpos($line, 'Starlink: \$65') !== false
        || strpos($line, 'Fiber: \$50')    !== false
        || strpos($line, 'LTE: \$25')      !== false;
};

$patterns = [
    'quoted-$ concat'        => '~([\'"])\$\1\s*\.~',
    'escaped-\$ + number'    => '~\\\\\$[\'"]?\s*\.\s*number_format~',
    '$ before short echo'    => '~\$\s?<\?=~',
    '$ before php echo'      => '~\$\s?<\?php echo~',
    'JS quoted-$ +'          => '~([\'"])\$\1\s*\+~',
    'string-end-$ + number'  => '~[^\\\\\'"]\$[\'"]\s*\.\s*number_format~',
    'bare $ element'         => '~>\$</~',
    // The form that slipped through for months: a dollar escaped inside a
    // double-quoted string, immediately followed by an interpolated variable —
    // "Amount: *\${$a}*". Every customer WhatsApp message used it, so a
    // Ugandan invoice notification read "$1,645,440.00".
];

// These two catch the form that hid the defect for months: a dollar escaped
// inside a double-quoted string next to an interpolated variable —
// "Amount: *\${$a}*". Every customer WhatsApp message used it, so a Ugandan
// invoice notification read "$1,645,440.00".
//
// They are enforced on the files a CUSTOMER reads, not plugin-wide. Staff
// screens (handover, payroll, admin audit notes) still carry the old form;
// that is listed in docs/UGANDA-EMAIL-OWNERSHIP.md as remaining work rather
// than pretended away here.
$customerFacing = [
    'lib/NotificationService.php', 'lib/DeliveryPdfService.php',
    'lib/CustomerEmails.php', 'lib/EmailTemplate.php', 'lib/OtpEmailTemplate.php',
    'lib/OverdueDunningHelpers.php', 'lib/CustomerContact.php',
    'webhook.php', 'cron_quote_wa.php', 'cron_maintenance.php',
    'includes/api/api_customer_app.php',
];
$custPatterns = [
    'escaped-$ + interp'  => '~\\\\\$\{\$~',
    'escaped-$ + literal' => '~\\\\\$[0-9]~',
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

echo "\nNo escaped-dollar money in anything a customer reads\n";
$custViolations = [];
foreach ($customerFacing as $rel) {
    $path = $root . '/' . $rel;
    if (!is_file($path)) { $custViolations[] = "{$rel}  [missing — did it move?]"; continue; }
    foreach (explode("\n", (string)file_get_contents($path)) as $i => $line) {
        if ($exemptLine($line)) continue;
        foreach ($custPatterns as $name => $rx) {
            if (preg_match($rx, $line)) {
                $custViolations[] = "$rel:" . ($i + 1) . "  [$name]  " . trim(substr($line, 0, 130));
            }
        }
    }
}
t('no customer-facing message hardcodes a dollar sign', count($custViolations), 0);
if ($custViolations) echo "    " . implode("\n    ", array_slice($custViolations, 0, 25)) . "\n";

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail ? 1 : 0);
