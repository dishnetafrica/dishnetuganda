<?php
declare(strict_types=1);
chdir(dirname(__DIR__));

/**
 * email_identity.php — who does the customer think wrote to them?
 *
 *   php tools/email_identity.php
 *
 * Every customer email carries an identity: the company name in the body, the
 * Reply-To a customer's answer goes to, the phone they will ring, the site
 * they will open. Each of those has a Sudan default, because the plugin was
 * born there, and each falls back to that default the moment its key is
 * missing from the config the sender happens to be holding.
 *
 * That is how a Ugandan quotation went out asking customers to reply to a
 * Juba address. Nothing failed; a default filled a gap, quietly and correctly.
 *
 * This prints the identity as the dispatcher resolves it, marks every value
 * still sitting on a Sudan default, and never sends anything. Run it after a
 * deploy and read the SOURCE column: DISK is set here, DEFAULT is Sudan's.
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }

$root = dirname(__DIR__);
require_once $root . '/lib/bootstrap_data.php';
require_once $root . '/lib/EmailTemplate.php';
require_once $root . '/lib/CustomerEmailDispatcher.php';

// File scope, so this IS $GLOBALS['dataDir'] — which is where effectiveConfig()
// looks. Without it the tool would read the plugin's own data/ and report on a
// box other than the one it is running on.
$dataDir = cliDataDir($root);

// Deliberately EMPTY. A caller's array is exactly what cannot be trusted here:
// the webhook builds its own from the SqliteStore copy, which never learns the
// keys written to the config file. If this comes out right from nothing, it
// comes out right everywhere.
$effective = CustomerEmailDispatcher::effectiveConfig([]);
$brand     = EmailTemplate::brand($effective);

echo "\n  EMAIL IDENTITY — resolved from disk, as the sender sees it\n\n";
printf("  %-16s %-34s %s\n", 'FIELD', 'VALUE', 'SOURCE');
printf("  %-16s %-34s %s\n", str_repeat('─', 16), str_repeat('─', 34), str_repeat('─', 8));

// Only the fields a customer would ACT on decide the verdict. The accent is a
// colour: it has a Sudan default too, and saying so in the same breath as a
// wrong phone number is how a checker teaches people to ignore it.
$contact = ['email_company_name', 'email_locality', 'email_website',
            'email_support_phone', 'email_support_wa', 'email_reply_to'];

$sudan = 0;
$blank = 0;
foreach (EmailTemplate::DEFAULTS as $key => $default) {
    $short = substr($key, 6);                      // strip 'email_'
    $set   = trim((string)($effective[$key] ?? ''));
    $value = $brand[$short];

    if ($set !== '') {
        $source = 'DISK';
    } elseif ($default === '') {
        $source = 'unset';                          // legal/badge lines are optional
        $blank++;
    } else {
        $source = 'DEFAULT';                        // i.e. still Sudan's
        if (in_array($key, $contact, true)) $sudan++;
    }
    $shown = $value !== '' ? $value : '(none)';
    printf("  %-16s %-34s %s\n", $short, $shown, $source);
}

// The SENDER is part of the identity too, and it is the one part that is not
// in EmailTemplate::DEFAULTS — it lives with the SMTP settings, because it is
// an address a relay has to be willing to use, not a line of copy.
require_once $root . '/lib/EmailSettingsWriter.php';
require_once $root . '/lib/MailService.php';
$es       = EmailSettingsWriter::read($dataDir);
$ordinary = trim((string)($es['smtp_from'] ?? $es['smtp_user'] ?? ''));
$sysFrom  = MailService::normalizeFrom((string)($es['system_from'] ?? ''));
printf("  %-16s %-34s %s\n", 'from', $ordinary !== '' ? $ordinary : '(none)',
       $ordinary !== '' ? 'DISK' : 'unset');
printf("  %-16s %-34s %s\n", 'login codes',
       $sysFrom !== '' ? MailService::bareAddress($sysFrom) : ($ordinary ?: '(none)'),
       $sysFrom !== '' ? 'DISK' : 'same as from');

echo "\n";
if ($sudan > 0) {
    echo "  $sudan contact field(s) are still on the Sudan default. A customer\n";
    echo "  reading one of these emails is given South Sudan contact details.\n\n";
    echo "  Fix:  php tools/set_email_brand.php --uganda\n\n";
    exit(1);
}
echo "  No Sudan defaults in play. Replies go to {$brand['reply_to']}.\n";
if ($blank > 0) {
    echo "  ($blank optional line(s) unset — that is a choice, not a fault.)\n";
}
echo "\n";
exit(0);
