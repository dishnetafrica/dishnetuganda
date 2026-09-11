<?php
declare(strict_types=1);
chdir(dirname(__DIR__));

/**
 * set_email_brand.php — write the email_* branding keys every customer email
 * reads (header, footer, contacts, legal line, bank details).
 *
 *   php tools/set_email_brand.php --uganda            apply the Uganda preset
 *   php tools/set_email_brand.php --show              print what is set now
 *   php tools/set_email_brand.php --set key=value ... set individual keys
 *
 * Unset keys fall back to the values the Sudan install has always printed, so
 * running nothing here leaves that install untouched.
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }

$root = dirname(__DIR__);
require_once $root . '/lib/bootstrap_data.php';
require_once $root . '/lib/PluginConfig.php';
require_once $root . '/lib/EmailTemplate.php';
require_once $root . '/lib/CustomerContact.php';

$dataDir = cliDataDir($root);
$config  = PluginConfig::load($root, $dataDir);
$argvAll = $argv;

// Facts from the company documents already in this project: certificate of
// incorporation, URA registration, the UCC-authorised-installer wording used
// on the flyer and the quotation terms, and the Ecobank account details.
$UGANDA = [
    'email_company_name'     => 'DishNet Africa Limited',
    'email_locality'         => 'Kampala, Uganda',
    'email_website'          => 'dishnetuganda.com',
    'email_support_phone'    => '+256 705 993 348',
    'email_support_wa'       => '256705993348',
    'email_reply_to'         => 'accounts@dishnetuganda.com',
    'email_legal_line'       => 'TIN 1059140632 · Reg. No. 80046255496181',
    'email_badge_line'       => 'UCC Authorised Starlink Installer',
    'email_currency'         => 'UGX',
    'email_bank_beneficiary' => 'DISHNET AFRICA LIMITED',
    'email_bank_name'        => 'Ecobank Uganda Limited — Head Office',
    'email_bank_account_ugx' => '7247510191',
    'email_bank_account_usd' => '7247510192',
    'email_bank_swift'       => 'ECOCUGKA',
] + CustomerContact::UGANDA;   // the phone numbers and links in WhatsApp copy

if (in_array('--show', $argvAll, true)) {
    echo "Effective email branding (configured value, else historical default):\n\n";
    foreach (EmailTemplate::brand($config) as $k => $v) {
        printf("  %-16s %s\n", $k, $v);
    }
    echo "\nBank / currency keys:\n";
    foreach (['email_currency','email_bank_beneficiary','email_bank_name',
              'email_bank_account_ugx','email_bank_account_usd','email_bank_swift'] as $k) {
        printf("  %-24s %s\n", substr($k, 6), trim((string)($config[$k] ?? '')) ?: '(not set)');
    }
    exit(0);
}

$changes = [];
if (in_array('--uganda', $argvAll, true)) {
    $changes = $UGANDA;
}
foreach ($argvAll as $i => $a) {
    if ($a === '--set' && isset($argvAll[$i + 1]) && strpos($argvAll[$i + 1], '=') !== false) {
        [$k, $v] = explode('=', $argvAll[$i + 1], 2);
        $changes[trim($k)] = trim($v);
    }
}
if (!$changes) {
    fwrite(STDERR, "Nothing to do. Use --uganda, --show, or --set key=value\n");
    exit(1);
}

list($ok, $err) = PluginConfig::saveOverrides($dataDir, $changes);
if (!$ok) { fwrite(STDERR, "FAILED: {$err}\n"); exit(1); }

echo "Saved " . count($changes) . " email branding key(s) to {$dataDir}/kyc_config.json\n\n";
foreach (EmailTemplate::brand(PluginConfig::load($root, $dataDir)) as $k => $v) {
    printf("  %-16s %s\n", $k, $v);
}
echo "\nNow open Admin → Email Preview to inspect every template.\n";
if (in_array('--uganda', $argvAll, true)) {
    // Branding alone does not stop the postpaid overdue ladder, which
    // would tell a prepaid customer their service is suspended. Say so
    // here rather than changing an unrelated key behind the operator.
    echo "\nUganda is prepaid. Also run, once:\n";
    echo "  php tools/set_billing_model.php --prepaid\n";
}

