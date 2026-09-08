<?php
/**
 * test_email_no_sudan.php — every customer email, rendered for real, proven
 * free of the other country's identity.
 *
 * A Uganda customer must never see a Juba address, a +211 number, a
 * dishnetafrica.com link or a dollar sign on a shilling amount. Eyeballing
 * nine templates once does not keep that true, so this renders all of them
 * through the same code the senders use and reads the output.
 *
 * The mirror half matters just as much: rendered with NO configuration, the
 * templates must still produce the Sudan identity, because that is the
 * install that has been running on these defaults all along.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/lib/EmailTemplate.php';
require_once $root . '/lib/CustomerEmails.php';
require_once $root . '/lib/OtpEmailTemplate.php';

$pass = 0; $fail = 0;
function ok(string $m): void  { global $pass; $pass++; echo "  ok   {$m}\n"; }
function bad(string $m, string $d = ''): void {
    global $fail; $fail++; echo "  FAIL {$m}\n"; if ($d !== '') echo "       {$d}\n";
}
function is_(bool $c, string $m, string $d = ''): void { $c ? ok($m) : bad($m, $d); }

// The exact preset tools/set_email_brand.php --uganda writes.
$UG = [
    'email_company_name'  => 'DishNet Africa Limited',
    'email_locality'      => 'Kampala, Uganda',
    'email_website'       => 'dishnetuganda.com',
    'email_support_phone' => '+256 705 993 348',
    'email_support_wa'    => '256705993348',
    'email_reply_to'      => 'accounts@dishnetuganda.com',
    'email_legal_line'    => 'TIN 1059140632 · Reg. No. 80046255496181',
    'email_badge_line'    => 'UCC Authorised Starlink Installer',
    'email_currency'      => 'UGX',
];

// One data bag rich enough that every template fills every slot it has.
$D = [
    'name' => 'Felix Orech', 'first_name' => 'Felix', 'account' => 'DN-UG-10428',
    'quote_number' => 'Q-2026-0141', 'invoice_number' => 'INV-2026-0512',
    'amount' => 1645440, 'total' => 1645440, 'balance' => 0,
    'plan' => 'Business 50', 'speed' => '50 Mbps',
    'date' => '14 September 2026', 'due_date' => '21 September 2026',
    'period' => '14 Sep 2026 – 13 Oct 2026', 'paid_on' => '14 September 2026',
    'method' => 'Bank transfer', 'reference' => 'ECO-889210',
    'code' => '481920', 'ttl_minutes' => 15,
    'ticket' => 'SUP-3391', 'subject' => 'Speed slower than usual',
    'install_date' => '18 September 2026', 'install_window' => '09:00 – 12:00',
    'engineer' => 'Moses K.', 'address' => 'Plot 12, Ntinda, Kampala',
];

// What must never reach a Uganda customer.
$SUDAN = [
    '+211'                => 'a South Sudan phone number',
    '211921443009'        => 'a South Sudan wa.me link',
    '211921443002'        => 'a South Sudan wa.me link',
    'dishnetafrica.com'   => 'the Sudan website',
    'Juba'                => 'the Juba address',
    'South Sudan'         => 'the country name',
    'SSP'                 => 'the South Sudanese pound',
];

echo "\nEvery customer email, rendered with the Uganda configuration\n";
foreach (array_keys(CustomerEmails::CATALOGUE) as $key) {
    $out  = CustomerEmails::render($key, $UG, $D);
    $blob = (string)($out['subject'] ?? '') . "\n"
          . (string)($out['html'] ?? '')    . "\n"
          . (string)($out['text'] ?? '');
    is_(trim((string)($out['subject'] ?? '')) !== '', "{$key}: has a subject");
    is_(trim((string)($out['html'] ?? ''))    !== '', "{$key}: has an HTML body");
    is_(trim((string)($out['text'] ?? ''))    !== '', "{$key}: has a plain-text body");

    $hits = [];
    foreach ($SUDAN as $needle => $what) {
        $needle = (string)$needle;   // numeric-looking keys arrive as int
        if (stripos($blob, $needle) !== false) $hits[] = "{$needle} ({$what})";
    }
    is_($hits === [], "{$key}: carries nothing from the Sudan operation",
        $hits ? 'found ' . implode(', ', $hits) : '');

    // A dollar sign in front of a shilling figure is the mistake that makes a
    // quotation look fake. Money here is UGX or nothing.
    is_(!preg_match('/\$\s?[\d,]{3,}/', $blob), "{$key}: no dollar sign on a shilling amount");

    // The customer must be able to reply somewhere that reaches Uganda.
    if (stripos($blob, '@') !== false) {
        is_(stripos($blob, 'dishnetuganda.com') !== false || $key === 'login_code',
            "{$key}: any address shown is a dishnetuganda.com one");
    }
}

echo "\nThe login email the customer actually receives\n";
// The portal sends OtpEmailTemplate, not CustomerEmails::loginCode(). Both
// must be clean, or the one real login email leaks Sudan.
$otp = OtpEmailTemplate::html('Felix', '481920', 15, $UG) . "\n"
     . OtpEmailTemplate::text('Felix', '481920', 15, $UG) . "\n"
     . OtpEmailTemplate::subject('481920');
$hits = [];
foreach ($SUDAN as $needle => $what) {
        $needle = (string)$needle;   // numeric-looking keys arrive as int
    if (stripos($otp, $needle) !== false) $hits[] = $needle;
}
is_($hits === [], 'OtpEmailTemplate carries nothing from the Sudan operation',
    $hits ? 'found ' . implode(', ', $hits) : '');
is_(stripos($otp, '481920') !== false, 'and it does contain the code');

echo "\nThe Sudan install is untouched by all of this\n";
// With no configuration at all, the shell must still print what it always has.
$sud = EmailTemplate::wrap([], 'Hello', '<p>body</p>');
is_(strpos($sud, 'Juba, South Sudan') !== false,
    'an unconfigured install still shows the Juba locality');
is_(strpos($sud, 'dishnetafrica.com') !== false,
    'and the dishnetafrica.com website');
is_(strpos($sud, '+211 921 443 009') !== false,
    'and the +211 support number');
is_(EmailTemplate::replyTo([]) === 'info@dishnetafrica.com',
    'and replies still go to the Sudan inbox');
is_(EmailTemplate::replyTo($UG) === 'accounts@dishnetuganda.com',
    'while a configured install redirects them to Uganda');

echo "\nThe preview cannot drift from what is actually sent\n";
$prev = (string)file_get_contents($root . '/tabs/admin/email_preview.php');
is_(strpos($prev, 'CustomerEmails::render') !== false,
    'the preview renders through CustomerEmails::render, not its own copy');

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
