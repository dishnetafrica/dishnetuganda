<?php
/**
 * test_dunning_gate.php — the 9-stage overdue ladder must never reach a
 * prepaid customer, and must be completely unchanged for a postpaid one.
 *
 * The ladder tells people their service is "suspended" and chases debt for
 * 210 days. That is true on the Sudan install and false on the Uganda one,
 * where a customer who reaches the end of a paid period owes nothing. These
 * tests pin both halves: the gate closes on prepaid, and absence of the key
 * leaves Sudan exactly as it is.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$pass = 0; $fail = 0;
function ok(string $m): void   { global $pass; $pass++; echo "  ok   {$m}\n"; }
function bad(string $m): void  { global $fail; $fail++; echo "  FAIL {$m}\n"; }
function is_(bool $c, string $m): void { $c ? ok($m) : bad($m); }

// The file-reading fallback is cached per process, so point it at a directory
// with no config before anything else runs. That models a plain install.
$tmp = sys_get_temp_dir() . '/dngate_' . bin2hex(random_bytes(4));
@mkdir($tmp, 0777, true);
$GLOBALS['dataDir'] = $tmp;

require_once $root . '/lib/OverdueDunningHelpers.php';

echo "\nThe gate reads the billing model\n";
is_(_dunningBillingModel([]) === 'postpaid',
    'no key at all means postpaid — the Sudan install is untouched');
is_(_dunningBillingModel(null) === 'postpaid',
    'a null config means postpaid, not a crash');
is_(_dunningBillingModel(['billing_model' => 'postpaid']) === 'postpaid',
    'an explicit postpaid is postpaid');
is_(_dunningBillingModel(['billing_model' => 'prepaid']) === 'prepaid',
    'prepaid is recognised');
is_(_dunningBillingModel(['billing_model' => '  PrePaid ']) === 'prepaid',
    'spacing and capitals do not defeat the gate');
is_(_dunningBillingModel(['billing_model' => '']) === 'postpaid',
    'an empty value falls back to postpaid rather than blocking Sudan');
is_(_dunningBillingModel(['billing_model' => 'nonsense']) === 'postpaid',
    'an unrecognised value fails safe to the old behaviour');

echo "\nThe ladder is allowed exactly when it should be\n";
is_(_dunningBlockedReason([]) === '',
    'a plain install may run the ladder');
is_(_dunningBlockedReason(['billing_model' => 'postpaid']) === '',
    'a postpaid install may run the ladder');
$why = _dunningBlockedReason(['billing_model' => 'prepaid']);
is_($why !== '', 'a prepaid install is blocked');
is_(stripos($why, 'prepaid') !== false && stripos($why, 'postpaid') !== false,
    'the reason names both models so a cron log explains itself');
is_(stripos($why, 'paused') !== false || stripos($why, 'resumed') !== false,
    'the reason points at the replacement, not just the refusal');

echo "\nThe wire itself refuses, whoever calls it\n";
// _sendEmail takes no config, so it reads the files. Write prepaid there and
// prove the send is refused before a socket is opened.
file_put_contents($tmp . '/kyc_config.json', json_encode(['billing_model' => 'prepaid']));
// Bust the per-process cache the same way a fresh request would.
$sub = <<<'SUB'
$root = __ROOT__; $tmp = __TMP__;
$GLOBALS['dataDir'] = $tmp;
require_once $root . '/lib/OverdueDunningHelpers.php';
$err = '';
$smtp = ['host' => '127.0.0.1', 'port' => 1, 'from' => 'a@b.c', 'user' => '', 'pass' => '', 'enc' => ''];
$t0 = microtime(true);
$out = _sendEmail($smtp, 'victim@example.com', 'Your service is suspended', '<p>x</p>', $err);
printf("%s|%s|%.2f\n", $out ? 'SENT' : 'refused', $err, microtime(true) - $t0);
SUB;
$code = str_replace(['__ROOT__', '__TMP__'],
                    [var_export($root, true), var_export($tmp, true)], $sub);
$res  = [];
exec('php -r ' . escapeshellarg($code) . ' 2>&1', $res);
$line = trim(implode("\n", $res));
[$verdict, $errText, $secs] = array_pad(explode('|', $line, 3), 3, '');
is_($verdict === 'refused', '_sendEmail refuses on a prepaid install');
is_(stripos($errText, 'prepaid') !== false,
    'and says why, so the caller can report it truthfully');
is_((float)$secs < 2.0,
    'it refuses before opening a socket (no connect timeout was paid)');

echo "\nThe footer no longer hardcodes South Sudan\n";
$srcHelpers = (string)file_get_contents($root . '/lib/OverdueDunningHelpers.php');
is_(strpos($srcHelpers, '<div class="ft">DishNet Africa Ltd · Airport Road') === false,
    'the Juba address is not welded into the HTML any more');
is_(strpos($srcHelpers, '$footLine') !== false && strpos($srcHelpers, '$footWeb') !== false,
    'the address and website come from variables');

// Byte-preservation: with no config, the rendered footer must be the exact
// string this email has always carried.
$html = _buildEmail(1, 'Felix', 'Felix Orech', 'INV-1', '$100', '1 Jan 2026', 20, 'https://x/y', 'https://x/y', []);
is_(strpos($html, 'DishNet Africa Ltd · Airport Road, Juba, South Sudan') !== false,
    'a Sudan install still renders the identical footer line');
is_(strpos($html, 'www.dishnetafrica.com') !== false,
    'and the identical website');
is_(strpos($html, '+211 921 443 009') !== false,
    'and the identical accounts phone number');

$ug = _buildEmail(1, 'Felix', 'Felix Orech', 'INV-1', 'UGX 100', '1 Jan 2026', 20, 'https://x/y', 'https://x/y', [
    'overdue_email_company_line'   => 'DishNet Africa Ltd · Kampala, Uganda',
    'overdue_email_website'        => 'www.dishnetuganda.com',
    'overdue_email_phone'          => '+256 200 900 850',
    'overdue_email_accounts_email' => 'accounts@dishnetuganda.com',
]);
is_(strpos($ug, 'Kampala, Uganda') !== false && strpos($ug, 'Juba') === false,
    'a configured install shows its own address and no Juba');
is_(strpos($ug, 'www.dishnetuganda.com') !== false && strpos($ug, 'www.dishnetafrica.com') === false,
    'and its own website, with the Sudan one gone');
is_(strpos($ug, '+211') === false,
    'and no +211 number anywhere in the message');

echo "\nBoth send paths check the gate before doing any work\n";
$cron = (string)file_get_contents($root . '/cron_overdue_email.php');
is_(strpos($cron, '_dunningBlockedReason($config)') !== false,
    'the weekly cron checks the gate');
is_(strpos($cron, '_dunningBlockedReason($config)') < strpos($cron, '_getSmtpSettings'),
    'and checks it before it even resolves SMTP');
$api = (string)file_get_contents($root . '/includes/api/api_crm_misc.php');
is_(substr_count($api, '_dunningBlockedReason($cfg)') === 1,
    'the workbench bulk-send checks the gate too');
is_(strpos($api, '_dunningBlockedReason($cfg)') < strpos($api, '_buildSubject($stage'),
    'and refuses before building a single message');

@unlink($tmp . '/kyc_config.json'); @rmdir($tmp);
echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
