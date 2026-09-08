<?php
declare(strict_types=1);
/**
 * The customer email layer: one shell, eight emails, and the promise that an
 * install which configures nothing still renders exactly what it rendered
 * before (Sudan safety).
 */
$pass = 0; $fail = 0;
function t(string $n, $got, $want) { global $pass, $fail;
    if ($got === $want) { $pass++; printf("  ok   %s\n", $n); }
    else { $fail++; printf("  FAIL %s\n       got  %s\n       want %s\n", $n, var_export($got, true), var_export($want, true)); } }

$root = dirname(__DIR__);
require_once $root . '/lib/CustomerEmails.php';
require_once $root . '/lib/OtpEmailTemplate.php';

$UG = [
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
    'email_bank_name'        => 'Ecobank Uganda Limited',
    'email_bank_account_ugx' => '7247510191',
];
$D = ['customer_name'=>'Felix Orech','quote_number'=>'PF000123','total'=>2868000,
      'invoice_number'=>'INV-0428','amount'=>329000,'due_date'=>'20 Sep 2026',
      'plan_name'=>'DishNet Residential','monthly_price'=>329000,'next_due'=>'25 Oct 2026',
      'date'=>'14 Sep 2026','window'=>'10:00–13:00','code'=>'482913','ticket_ref'=>'SUP-1180',
      'period'=>'1–30 Oct 2026','account_number'=>'DN-UG-10428'];

echo "Sudan safety — an install that configures nothing is untouched\n";
$b = EmailTemplate::brand([]);
t('company default', $b['company_name'], 'DishNet Africa Ltd.');
t('locality default', $b['locality'], 'Juba, South Sudan');
t('support default', $b['support_phone'], '+211 921 443 009');
t('website default', $b['website'], 'dishnetafrica.com');
t('reply-to default', $b['reply_to'], 'info@dishnetafrica.com');
t('no legal line by default', $b['legal_line'], '');
t('no badge line by default', $b['badge_line'], '');
$otpOld = OtpEmailTemplate::html('Amal', '123456', 15);
t('OTP unconfigured still prints the Sudan footer',
  strpos($otpOld, 'DishNet Africa Ltd.') !== false && strpos($otpOld, 'Juba, South Sudan') !== false
  && strpos($otpOld, '+211 921 443 009') !== false, true);
t('OTP text unconfigured too', strpos(OtpEmailTemplate::text('Amal','123456',15), '+211 921 443 009') !== false, true);
$otpUg = OtpEmailTemplate::html('Felix', '123456', 15, $UG);
t('OTP configured switches every contact detail',
  strpos($otpUg, '+256 705 993 348') !== false && strpos($otpUg, 'Kampala, Uganda') !== false
  && strpos($otpUg, '211') === false, true);

echo "\nEvery template renders, and carries the shell's guarantees\n";
$all = [];
foreach (array_keys(CustomerEmails::CATALOGUE) as $k) {
    $m = CustomerEmails::render($k, $UG, $D);
    $all[$k] = $m;
    $okOne = trim($m['subject']) !== '' && trim($m['html']) !== '' && trim($m['text']) !== '';
    t("{$k}: subject + html + text", $okOne, true);
}
foreach ($all as $k => $m) {
    $shell = strpos($m['html'], '<!DOCTYPE html>') === 0
          && strpos($m['html'], 'name="viewport"') !== false
          && strpos($m['html'], '@media only screen and (max-width:620px)') !== false
          && strpos($m['html'], 'prefers-color-scheme:dark') !== false;
    t("{$k}: responsive + dark-mode + viewport", $shell, true);
}

echo "\nNo Sudan leaks once Uganda is configured\n";
$leaks = 0;
foreach ($all as $m) $leaks += preg_match_all('/\+211|South Sudan|Juba|dishnetafrica\.com/i', $m['html'] . $m['text']);
t('zero Sudan references across all templates', $leaks, 0);
$brandHits = 0;
foreach ($all as $m) {
    if (strpos($m['html'], '+256 705 993 348') !== false
     && strpos($m['html'], 'UCC Authorised Starlink Installer') !== false
     && strpos($m['html'], 'TIN 1059140632') !== false) $brandHits++;
}
t('every template carries phone + UCC badge + legal line', $brandHits, count($all));

echo "\nPrepaid model — no suspension or debt-chasing language\n";
$paused = $all['service_paused'];
t('paused email never says suspended', stripos($paused['html'], 'suspend') === false, true);
t('paused email promises no reconnection fee', stripos($paused['html'], 'no reconnection fee') !== false, true);
t('paused email says nothing is cancelled', stripos($paused['html'], 'nothing is cancelled') !== false, true);
$welcome = $all['welcome'];
t('welcome explains prepaid billing', stripos($welcome['html'], 'prepaid') !== false, true);
t('welcome explains the pause, not suspension', stripos($welcome['html'], 'pauses') !== false, true);

echo "\nThe questions the audit found unanswered are now answered\n";
t('welcome states the plan', strpos($welcome['html'], 'DishNet Residential') !== false, true);
t('welcome states the monthly price', strpos($welcome['html'], 'UGX 329,000') !== false, true);
t('welcome states the next payment date', strpos($welcome['html'], '25 Oct 2026') !== false, true);
t('welcome states the account number', strpos($welcome['html'], 'DN-UG-10428') !== false, true);
t('welcome gives the support number', strpos($welcome['html'], '+256 705 993 348') !== false, true);
t('welcome says how to pay (bank details)', strpos($welcome['html'], '7247510191') !== false, true);
t('invoice subject carries number, amount and due date',
  strpos($all['invoice']['subject'], 'INV-0428') !== false
  && strpos($all['invoice']['subject'], 'UGX 329,000') !== false
  && strpos($all['invoice']['subject'], '20 Sep 2026') !== false, true);
t('quotation lists what happens next', stripos($all['quotation']['html'], 'What happens next') !== false, true);
t('installation lists what to have ready', stripos($all['install_scheduled']['html'], 'clear, unobstructed view') !== false, true);
t('login code appears and warns against sharing',
  strpos($all['login_code']['html'], '482913') !== false
  && stripos($all['login_code']['html'], 'never share this code') !== false, true);

echo "\nBank block only appears when bank details are configured\n";
$noBank = CustomerEmails::render('invoice', ['email_company_name' => 'X'], $D);
t('no bank configured: no invented account number', strpos($noBank['html'], '7247510191'), false);
t('bank configured: account shown', strpos($all['invoice']['html'], '7247510191') !== false, true);

echo "\nSafety and hygiene\n";
$xss = CustomerEmails::render('welcome', $UG, array_merge($D, ['customer_name' => '<script>alert(1)</script>Bad']));
t('customer name is escaped', strpos($xss['html'], '<script>'), false);
foreach ($all as $k => $m) {
    if (strpos($m['html'], 'http://') !== false) { t("{$k}: no plaintext http links", false, true); }
}
t('no remote images anywhere', (function() use ($all) {
    foreach ($all as $m) if (preg_match('/<img[^>]+src=["\']https?:/i', $m['html'])) return false;
    return true;
})(), true);
t('no tracking pixels', (function() use ($all) {
    foreach ($all as $m) if (stripos($m['html'], 'width="1"') !== false) return false;
    return true;
})(), true);
t('unknown template key throws', (function() use ($UG) {
    try { CustomerEmails::render('nope', $UG, []); return false; } catch (\InvalidArgumentException $e) { return true; }
})(), true);
t('facts() skips empty values', strpos(EmailTemplate::facts(['A' => 'x', 'B' => '', 'C' => null]), 'B'), false);

echo "\nPreview page wiring\n";
$tab = (string)file_get_contents($root . '/tabs/admin/email_preview.php');
t('preview is admin-gated', strpos($tab, 'Admin access required') !== false, true);
t('preview never sends', strpos($tab, '->send('), false);
t('preview renders every catalogue entry', strpos($tab, 'CustomerEmails::CATALOGUE') !== false, true);
t('route registered', strpos((string)file_get_contents($root . '/public.php'), "'email_preview'") !== false, true);

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail ? 1 : 0);
