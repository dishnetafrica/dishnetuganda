<?php
declare(strict_types=1);
chdir(dirname(__DIR__));

/**
 * email_lifecycle_test.php — walk one imaginary customer through the whole
 * DishNet Uganda journey and send every email it produces, labelled [TEST].
 *
 *   php tools/email_lifecycle_test.php --to bhavin@dishnetafrica.com
 *   php tools/email_lifecycle_test.php --to you@x.com --dry-run    (send nothing)
 *   php tools/email_lifecycle_test.php --to you@x.com --only welcome,invoice
 *
 * Every message carries a [TEST] subject prefix and a dashed TEST banner in the
 * body, so one landing in front of a real person cannot be mistaken for the
 * real thing. For each email it prints the inspection sheet: subject, from,
 * reply-to, trigger, template, variables that actually appeared, the CTA, every
 * link, image count, responsive/dark-mode/plain-text checks, and the SMTP
 * outcome.
 *
 * Read-only against the business: no customer, invoice or CRM record is
 * touched — the data below is invented.
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }

$root = dirname(__DIR__);
require_once $root . '/lib/bootstrap_data.php';
require_once $root . '/lib/PluginConfig.php';
require_once $root . '/lib/MailService.php';
require_once $root . '/lib/CustomerEmails.php';
require_once $root . '/lib/EmailTemplate.php';

$opt  = getopt('', ['to:', 'only:', 'dry-run', 'pause:']);
$to   = trim((string)($opt['to'] ?? ''));
$dry  = isset($opt['dry-run']);
$wait = (int)($opt['pause'] ?? 2);
if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Usage: php tools/email_lifecycle_test.php --to someone@example.com [--dry-run] [--only key,key]\n");
    exit(1);
}

$dataDir = getenv('DN_DATA_DIR') ?: getDataDir($root);
$config  = PluginConfig::load($root, $dataDir);
$config['email_test_banner'] = 'TEST EMAIL — DishNet system check, not a real customer message';

$mail = new MailService($dataDir);
$cfg  = $mail->getConfig();
$from = (string)($cfg['from'] ?? '(unset)');
if (empty($cfg['host']) && !$dry) {
    fwrite(STDERR, "No SMTP configured: " . $mail->lastError() . "\n");
    exit(1);
}

// ── One customer, one story ─────────────────────────────────────────────────
$C = [
    'customer_name'    => 'Felix Orech',
    'account_number'   => 'DN-UG-10428',
    'address'          => 'Plot 14, Nakawa, Kampala',
    'plan_name'        => 'DishNet Residential',
    'monthly_price'    => 329000,
    'quote_number'     => 'PF000123',
    'total'            => 2868000,
    'valid_days'       => 7,
    'invoice_number'   => 'INV-2026-0428',
    'amount'           => 329000,
    'due_date'         => '20 September 2026',
    'period'           => '1–30 October 2026',
    'next_due'         => '25 October 2026',
    'paid_on'          => '9 September 2026',
    'method'           => 'Bank transfer — Ecobank',
    'reference'        => 'PF000123',
    'applied_to'       => 'Quotation PF000123',
    'balance'          => 0,
    'next_step'        => 'We will call you within one working day to agree your installation date.',
    'date'             => '14 September 2026',
    'window'           => '10:00 – 13:00',
    'technician'       => 'Joseph M.',
    'technician_phone' => EmailTemplate::brand($config)['support_phone'],
    'activated_on'     => '14 September 2026',
    'period_ended'     => '30 September 2026',
    'code'             => '482913',
    'ttl_minutes'      => 15,
    'ticket_ref'       => 'SUP-1180',
    'subject'          => 'Internet slow in the evenings',
    'logged_at'        => '9 September 2026, 20:14',
];

// Journey order — the sequence a real customer would receive them in.
$JOURNEY = [
    ['quotation',         'Customer asked for a quote; quote created in uCRM'],
    ['payment_received',  'Customer paid; payment recorded — doubles as the order confirmation'],
    ['install_scheduled', 'Installation date agreed with the customer'],
    ['welcome',           'Technician finished; service activated'],
    ['login_code',        'Customer requested a portal login code'],
    ['invoice',           'Next service period invoiced'],
    ['service_paused',    'Period ended with no payment received'],
    ['service_resumed',   'Payment arrived; service back on'],
    ['support_received',  'Customer reported a fault'],
];
$only = array_filter(array_map('trim', explode(',', (string)($opt['only'] ?? ''))));
if ($only) $JOURNEY = array_values(array_filter($JOURNEY, fn($s) => in_array($s[0], $only, true)));

echo "DishNet Uganda — customer email lifecycle test\n";
echo str_repeat('=', 74) . "\n";
echo "From:    {$from}\n";
echo "To:      {$to}\n";
echo "Mode:    " . ($dry ? 'DRY RUN — nothing is sent' : 'LIVE — emails will be delivered') . "\n";
echo "Stages:  " . count($JOURNEY) . "\n";
echo str_repeat('=', 74) . "\n";

$sent = 0; $failed = 0; $n = 0;
foreach ($JOURNEY as [$key, $trigger]) {
    $n++;
    $m = CustomerEmails::render($key, $config, $C);
    list($label, , $type) = CustomerEmails::CATALOGUE[$key];

    // Inspection sheet
    preg_match_all('#href="([^"]+)"#', $m['html'], $lm);
    $links = array_values(array_unique($lm[1] ?? []));
    preg_match('#<a href="([^"]+)"[^>]*>([^<]{3,60})</a>\s*</td></tr></table>#', $m['html'], $cta);
    $imgs  = preg_match_all('#<img#i', $m['html']);
    // Honest variable detection: ignore values short enough to appear by
    // accident, and ignore anything that is really a brand value printed in
    // the footer of every email (the support phone, for instance).
    $brandVals = array_values(EmailTemplate::brand($config));
    $bodyOnly  = preg_replace('#<tr><td class="dn-pad" style="padding:16px 32px 26px.*$#s', '', $m['html']);
    $vars = [];
    foreach ($C as $k => $v) {
        if ($v === '' || $v === null) continue;
        $needle = is_numeric($v) ? number_format((float)$v, 0) : (string)$v;
        if (strlen($needle) < 4) continue;
        if (in_array($needle, $brandVals, true)) continue;
        if (strpos($bodyOnly, $needle) !== false) $vars[] = $k;
    }

    printf("\n[%d/%d] %s  (%s)\n", $n, count($JOURNEY), strtoupper($label), $type);
    echo str_repeat('-', 74) . "\n";
    echo "  Subject     : {$m['subject']}\n";
    echo "  From        : {$from}\n";
    echo "  To          : {$to}\n";
    echo "  Reply-To    : " . EmailTemplate::replyTo($config) . "\n";
    echo "  Trigger     : {$trigger}\n";
    echo "  Template    : CustomerEmails::{$key}\n";
    echo "  Variables   : " . (count($vars) ? implode(', ', $vars) : '—') . "\n";
    echo "  CTA         : " . (isset($cta[2]) ? trim($cta[2]) . ' → ' . $cta[1] : '(none — informational email)') . "\n";
    echo "  Links       : " . (count($links) ? implode("\n                ", $links) : '(none)') . "\n";
    echo "  Images      : {$imgs} (0 expected — no remote assets to fail loading)\n";
    printf("  HTML        : %d bytes · responsive %s · dark mode %s\n", strlen($m['html']),
        strpos($m['html'], 'max-width:620px') !== false ? 'YES' : 'NO',
        strpos($m['html'], 'prefers-color-scheme') !== false ? 'YES' : 'NO');
    printf("  Plain text  : %d bytes%s\n", strlen($m['text']),
        strpos($m['text'], '*** TEST') === 0 ? ' · TEST-labelled' : '');
    $leak = preg_match_all('/\+211|South Sudan|Juba|dishnetafrica\.com/i', $m['html'] . $m['text']);
    echo "  Sudan refs  : {$leak}" . ($leak ? '  ← WRONG' : '  (clean)') . "\n";

    if ($dry) { echo "  Result      : DRY RUN — not sent\n"; continue; }

    $r = $mail->send($to, 'Bhavin Madlani (test)', $m['subject'], $m['html'], $m['text'],
                     ['Reply-To' => EmailTemplate::replyTo($config)]);
    if (!empty($r['ok'])) { $sent++;  echo "  Result      : SENT\n"; }
    else { $failed++; echo "  Result      : FAILED — " . (string)($r['error'] ?? '?') . "\n"; }
    if ($wait > 0 && $n < count($JOURNEY)) sleep($wait);
}

echo "\n" . str_repeat('=', 74) . "\n";
if ($dry) {
    echo "DRY RUN complete — " . count($JOURNEY) . " emails rendered, none sent.\n";
    exit(0);
}
printf("LIFECYCLE TEST: %d sent · %d failed\n", $sent, $failed);
echo "Check {$to} — every subject starts with [TEST] and every body carries a dashed TEST banner.\n";
echo "Read them in the order above: that is the order a real customer receives them.\n";
exit($failed ? 1 : 0);
