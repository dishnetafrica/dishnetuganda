<?php
declare(strict_types=1);

require_once __DIR__ . '/EmailTemplate.php';
require_once __DIR__ . '/PaymentOptions.php';

/**
 * CustomerEmails — the eight emails a DishNet customer actually needs.
 *
 * Chosen in docs/UGANDA-EMAIL-LIFECYCLE-AUDIT.md. The platform's WhatsApp
 * layer already carries the conversational lifecycle (13 wired customer
 * events), so email deliberately covers only what email does better:
 * documents, onboarding, money, credentials and formal notices.
 *
 *   1 quotation           5 invoice
 *   2 paymentReceived     6 servicePaused / serviceResumed
 *   3 installScheduled    7 loginCode
 *   4 welcomeActivated    8 supportReceived
 *
 * Every method returns ['subject' => …, 'html' => …, 'text' => …] and takes a
 * flat data array — no database access, no uCRM calls — so the preview screen
 * and the real senders render the exact same thing.
 *
 * PREPAID MODEL. Uganda sells prepaid service that pauses at the end of an
 * unpaid period; there is no suspension ladder and no debt-chasing language.
 * That matches the quotation terms the customer signed.
 *
 * PHP 7.4 compatible.
 */
class CustomerEmails
{
    /** Everything the preview screen needs to describe each template. */
    const CATALOGUE = [
        'quotation'         => ['Quotation',            'Quote created for a customer',            'sales'],
        'payment_received'  => ['Payment received',     'Payment recorded against the account',    'billing'],
        'install_scheduled' => ['Installation scheduled','Installation job created with a date',   'onboarding'],
        'welcome'           => ['Welcome — service active','Service activated for the first time',  'onboarding'],
        'invoice'           => ['Invoice',              'Invoice issued for the next period',      'billing'],
        'service_paused'    => ['Service paused',       'Period ended with no payment received',   'billing'],
        'service_resumed'   => ['Service resumed',      'Paused service switched back on',         'billing'],
        'login_code'        => ['Login code',           'Customer requests a portal login code',   'transactional'],
        'support_received'  => ['Support request received','Support ticket opened for a customer', 'support'],
    ];

    private static function money($v): string
    {
        if ($v === null || $v === '') return '';
        return is_numeric($v) ? number_format((float)$v, 0) : (string)$v;
    }

    private static function cur(array $c): string
    {
        $x = trim((string)($c['email_currency'] ?? ($c['ai_currency'] ?? '')));
        if ($x === '') return 'UGX';
        // A three-letter code is an ISO code and is written in capitals. One
        // install had it stored lowercase and a customer was quoted "ugx 0",
        // which reads like a placeholder somebody forgot to fill in.
        return preg_match('/^[A-Za-z]{3}$/', $x) ? strtoupper($x) : $x;
    }

    private static function amt(array $c, $v): string
    {
        $m = self::money($v);
        return $m === '' ? '' : self::cur($c) . ' ' . $m;
    }

    /**
     * Who to greet.
     *
     * Two things were wrong here. Every sender passes 'name', while every
     * template read 'customer_name' — so the fallback fired every time and
     * real customers were greeted "Dear there,". And taking the first word
     * turns a company into "Dear Family," when the account is Family Shoppers.
     *
     * So: an explicit first_name wins, because a person is nicer greeted by
     * it; otherwise the name is used whole, which is right for a company and
     * merely formal for a person. "there" is the last resort it was always
     * meant to be, not the common case.
     */
    private static function greetingName(array $d): string
    {
        $fn = trim((string)($d['first_name'] ?? ''));
        if ($fn !== '') return $fn;
        foreach (['customer_name', 'name', 'company_name'] as $k) {
            $n = trim((string)($d[$k] ?? ''));
            if ($n !== '') return $n;
        }
        return 'there';
    }

    /**
     * How to pay. Configured bank details are printed; without them the email
     * points at the document rather than inventing an account number. The
     * Airtel Money merchant ID (5.18.31) comes first when it is configured.
     */
    private static function payHow(array $c): string
    {
        $ben  = trim((string)($c['email_bank_beneficiary'] ?? ''));
        $bank = trim((string)($c['email_bank_name'] ?? ''));
        $ugx  = trim((string)($c['email_bank_account_ugx'] ?? ''));
        $usd  = trim((string)($c['email_bank_account_usd'] ?? ''));
        $swift= trim((string)($c['email_bank_swift'] ?? ''));
        $airtel = PaymentOptions::emailRows($c);
        if ($ben === '' && $ugx === '' && $airtel === []) return '';
        $rows = $airtel;
        if ($ben  !== '') $rows['Beneficiary'] = $ben;
        if ($bank !== '') $rows['Bank']        = $bank;
        if ($ugx  !== '') $rows['Account (UGX)'] = $ugx;
        if ($usd  !== '') $rows['Account (USD)'] = $usd;
        if ($swift!== '') $rows['SWIFT/BIC']   = $swift;
        return EmailTemplate::facts($rows);
    }

    private static function payHowText(array $c): string
    {
        $ben = trim((string)($c['email_bank_beneficiary'] ?? ''));
        $ugx = trim((string)($c['email_bank_account_ugx'] ?? ''));
        $airtel = '';
        foreach (PaymentOptions::emailRows($c) as $label => $value) $airtel .= "  {$label}: {$value}\r\n";
        if ($ben === '' && $ugx === '' && $airtel === '') return '';
        return "How to pay:\r\n"
             . $airtel
             . ($ben !== '' ? "  Beneficiary: {$ben}\r\n" : '')
             . (trim((string)($c['email_bank_name'] ?? '')) !== '' ? '  Bank: ' . $c['email_bank_name'] . "\r\n" : '')
             . ($ugx !== '' ? "  Account (UGX): {$ugx}\r\n" : '')
             . (trim((string)($c['email_bank_account_usd'] ?? '')) !== '' ? '  Account (USD): ' . $c['email_bank_account_usd'] . "\r\n" : '')
             . "\r\n";
    }

    /**
     * The plain-text twin of EmailTemplate::facts(): one "Label: value" line
     * per fact, and a fact with no value is left out. The HTML side always
     * did this; the text side printed "Service period:" with nothing after
     * it, which on a receipt reads as a field somebody forgot to fill in.
     */
    private static function textFacts(array $rows): string
    {
        $out = '';
        foreach ($rows as $label => $value) {
            $v = trim((string)$value);
            if ($v === '') continue;
            $out .= $label . ': ' . $v . "\r\n";
        }
        return $out;
    }

    private static function supportLine(array $c): string
    {
        $b = EmailTemplate::brand($c);
        return 'Questions? WhatsApp or call us on <strong>' . EmailTemplate::e($b['support_phone'])
             . '</strong> — we answer every day.';
    }

    private static function pack(array $c, string $subject, string $body, string $text, string $pre = ''): array
    {
        $banner = trim((string)($c['email_test_banner'] ?? ''));
        if ($banner !== '') {
            $subject = '[TEST] ' . $subject;
            $text    = '*** ' . $banner . " ***\r\n\r\n" . $text;
        }
        return [
            'subject' => $subject,
            'html'    => EmailTemplate::wrap($c, $subject, $body, ['preheader' => $pre]),
            'text'    => $text . EmailTemplate::textFooter($c),
        ];
    }

    // ── 1. QUOTATION ────────────────────────────────────────────────────
    public static function quotation(array $c, array $d): array
    {
        $e = fn($s) => EmailTemplate::e((string)$s);
        $num   = (string)($d['quote_number'] ?? '');
        $total = self::amt($c, $d['total'] ?? '');
        $days  = (int)($d['valid_days'] ?? 7);
        $sub   = "Quotation {$num} — " . EmailTemplate::brand($c)['company_name'];
        // "Attached" only when the sender attached one. Every sender passes
        // pdf_attached; a render without it (a preview, a doctor) gets the
        // wording for a message that carries no file.
        $pdf   = !empty($d['pdf_attached']);

        $body = EmailTemplate::h1($pdf ? 'Your quotation is attached' : 'Your quotation')
              . EmailTemplate::p('Dear ' . $e(self::greetingName($d)) . ',')
              . EmailTemplate::p('Thank you for your interest in DishNet. Quotation <strong>' . $e($num)
                . '</strong>' . ($pdf
                    ? ' is attached to this email as a PDF, with the full breakdown of items and prices.'
                    : ' is summarised below.'))
              . EmailTemplate::facts([
                    'Quotation'  => $num,
                    'Total'      => $total,
                    'Valid for'  => $days . ' days from date of issue',
                    'Reference'  => $num . ' (use this when you pay)',
                ])
              . EmailTemplate::h1('What happens next')
              . EmailTemplate::steps(array_values(array_filter([
                    $pdf ? 'Read the quotation — page 2 explains how we work and our terms of service.' : '',
                    'To go ahead, simply <strong>reply to confirm</strong> or <strong>make payment</strong>. Either one confirms your order.',
                    'We contact you to agree an installation date.',
                    'Our team installs, activates your service and shows you it working.',
                ], 'strlen')))
              . self::payHow($c)
              . EmailTemplate::p(self::supportLine($c));

        $text = "Dear " . self::greetingName($d) . ",\r\n\r\n"
              . "Thank you for your interest in DishNet. Quotation {$num} "
              . ($pdf ? "is attached as a PDF." : "is summarised below.") . "\r\n\r\n"
              . self::textFacts(['Total' => $total, 'Valid for' => "{$days} days", 'Payment reference' => $num])
              . "\r\n"
              . "To go ahead, reply to confirm or make payment - either one confirms your order.\r\n"
              . "We will then contact you to arrange installation.\r\n\r\n"
              . self::payHowText($c);

        return self::pack($c, $sub, $body, $text, "Quotation {$num} — total {$total}, valid {$days} days");
    }

    // ── 2. PAYMENT RECEIVED (also the order confirmation) ───────────────
    public static function paymentReceived(array $c, array $d): array
    {
        $e = fn($s) => EmailTemplate::e((string)$s);
        $amt = self::amt($c, $d['amount'] ?? '');
        $sub = 'Payment received — thank you (' . $amt . ')';

        $body = EmailTemplate::h1('We have received your payment')
              . EmailTemplate::p('Dear ' . $e(self::greetingName($d)) . ',')
              . EmailTemplate::p('Thank you — your payment has been received and applied to your account. '
                . 'This email is your receipt.')
              . EmailTemplate::facts([
                    'Amount received' => $amt,
                    'Received on'     => (string)($d['paid_on'] ?? ''),
                    'Method'          => (string)($d['method'] ?? ''),
                    'Reference'       => (string)($d['reference'] ?? ''),
                    'Applied to'      => (string)($d['applied_to'] ?? ''),
                    'Service period'  => (string)($d['period'] ?? ''),
                    'Next payment due'=> (string)($d['next_due'] ?? ''),
                    'Balance now'     => isset($d['balance']) ? self::amt($c, $d['balance']) : '',
                ])
              . (($d['next_step'] ?? '') !== ''
                    ? EmailTemplate::note('<strong>What happens next:</strong> ' . $e($d['next_step']), 'good')
                    : '')
              . EmailTemplate::p(self::supportLine($c));

        $text = "Dear " . self::greetingName($d) . ",\r\n\r\n"
              . "Thank you - we have received your payment. This email is your receipt.\r\n\r\n"
              . self::textFacts([
                    'Amount received'  => $amt,
                    'Received on'      => (string)($d['paid_on'] ?? ''),
                    'Method'           => (string)($d['method'] ?? ''),
                    'Reference'        => (string)($d['reference'] ?? ''),
                    'Applied to'       => (string)($d['applied_to'] ?? ''),
                    'Service period'   => (string)($d['period'] ?? ''),
                    'Next payment due' => (string)($d['next_due'] ?? ''),
                    'Balance now'      => isset($d['balance']) ? self::amt($c, $d['balance']) : '',
                ])
              . (($d['next_step'] ?? '') !== '' ? "\r\nWhat happens next: " . (string)$d['next_step'] . "\r\n" : '')
              . "\r\n";

        return self::pack($c, $sub, $body, $text, 'Receipt for ' . $amt);
    }

    // ── 3. INSTALLATION SCHEDULED ───────────────────────────────────────
    public static function installScheduled(array $c, array $d): array
    {
        $e = fn($s) => EmailTemplate::e((string)$s);
        $date = (string)($d['date'] ?? '');
        $sub  = 'Your DishNet installation is booked' . ($date !== '' ? ' for ' . $date : '');

        $body = EmailTemplate::h1('Your installation is booked')
              . EmailTemplate::p('Dear ' . $e(self::greetingName($d)) . ',')
              . EmailTemplate::p('Good news — your DishNet installation is scheduled. Here are the details.')
              . EmailTemplate::facts([
                    'Date'      => $date,
                    'Time'      => (string)($d['window'] ?? ''),
                    'Address'   => (string)($d['address'] ?? ''),
                    'Technician'=> (string)($d['technician'] ?? ''),
                    'Contact'   => (string)($d['technician_phone'] ?? ''),
                ])
              . EmailTemplate::h1('Please have ready')
              . EmailTemplate::steps([
                    'Someone at the premises who can approve where the dish is mounted.',
                    'A clear, unobstructed view of the sky at the chosen spot.',
                    'Mains power near where the router will sit.',
                    'Permission from the landlord if the building is rented.',
                ])
              . EmailTemplate::note('Need to change the date? Reply to this email or WhatsApp us — '
                . 'please give us as much notice as you can.', 'info')
              . EmailTemplate::p(self::supportLine($c));

        $text = "Dear " . self::greetingName($d) . ",\r\n\r\n"
              . "Your DishNet installation is scheduled.\r\n\r\n"
              . self::textFacts([
                    'Date'       => $date,
                    'Time'       => (string)($d['window'] ?? ''),
                    'Address'    => (string)($d['address'] ?? ''),
                    'Technician' => (string)($d['technician'] ?? ''),
                    'Contact'    => (string)($d['technician_phone'] ?? ''),
                ])
              . "\r\n"
              . "Please have ready: someone who can approve the dish position, a clear view of the sky,\r\n"
              . "mains power near the router, and landlord permission if renting.\r\n\r\n"
              . "To change the date, reply to this email or WhatsApp us.\r\n\r\n";

        return self::pack($c, $sub, $body, $text, 'Installation ' . $date . ' — what to have ready');
    }

    // ── 4. WELCOME / SERVICE ACTIVATED ──────────────────────────────────
    public static function welcomeActivated(array $c, array $d): array
    {
        $e = fn($s) => EmailTemplate::e((string)$s);
        $plan  = (string)($d['plan_name'] ?? '');
        $price = self::amt($c, $d['monthly_price'] ?? '');
        $sub   = 'Welcome to DishNet — your internet is live';
        $b     = EmailTemplate::brand($c);

        $body = EmailTemplate::h1('Your internet is live 🎉')
              . EmailTemplate::p('Dear ' . $e(self::greetingName($d)) . ',')
              . EmailTemplate::p('Welcome to DishNet. Your service is installed, activated and ready to use. '
                . 'Keep this email — it has everything about your account in one place.')
              . EmailTemplate::h1('Your service')
              . EmailTemplate::facts([
                    'Plan'             => $plan,
                    'Monthly price'    => $price,
                    'Activated on'     => (string)($d['activated_on'] ?? ''),
                    'Account number'   => (string)($d['account_number'] ?? ''),
                    'Service address'  => (string)($d['address'] ?? ''),
                    'Next payment due' => (string)($d['next_due'] ?? ''),
                ])
              . EmailTemplate::h1('How your billing works')
              . EmailTemplate::steps([
                    'Your service is <strong>prepaid</strong> — you pay for each month before it starts.',
                    'We send your invoice by email and WhatsApp before the due date.',
                    'Pay using the bank details below, quoting your invoice number.',
                    'If a month is not paid, the service simply <strong>pauses</strong> at the end of the paid '
                    . 'period and resumes as soon as payment reaches us. No reconnection fee.',
                ])
              . self::payHow($c)
              . EmailTemplate::h1('What is included')
              . EmailTemplate::p('Your monthly price covers the Starlink service plan, DishNet account '
                . 'management and local after-sales support. Your equipment is yours; the manufacturer\'s '
                . 'warranty applies. Speeds and coverage are provided by the Starlink network.')
              . (($d['portal_url'] ?? '') !== ''
                    ? EmailTemplate::button($c, 'Open my account', (string)$d['portal_url'])
                    : '')
              . EmailTemplate::note('<strong>Support:</strong> WhatsApp or call <strong>'
                . $e($b['support_phone']) . '</strong>. Tell us your account number and we will help — '
                . 'faults, slow speeds, moving house, upgrading your plan.', 'good');

        $text = "Dear " . self::greetingName($d) . ",\r\n\r\n"
              . "Welcome to DishNet - your internet is live.\r\n\r\n"
              . self::textFacts([
                    'Plan'             => $plan,
                    'Monthly price'    => $price,
                    'Activated on'     => (string)($d['activated_on'] ?? ''),
                    'Account number'   => (string)($d['account_number'] ?? ''),
                    'Service address'  => (string)($d['address'] ?? ''),
                    'Next payment due' => (string)($d['next_due'] ?? ''),
                ])
              . "\r\n"
              . "HOW BILLING WORKS\r\n"
              . "Your service is prepaid: you pay for each month before it starts. We send the invoice\r\n"
              . "before the due date. If a month is unpaid the service pauses at the end of the paid\r\n"
              . "period and resumes as soon as payment reaches us. There is no reconnection fee.\r\n\r\n"
              . self::payHowText($c)
              . "Support: " . $b['support_phone'] . " (WhatsApp or call).\r\n\r\n";

        return self::pack($c, $sub, $body, $text, $plan . ' active — your account details inside');
    }

    // ── 5. INVOICE ──────────────────────────────────────────────────────
    public static function invoice(array $c, array $d): array
    {
        $e = fn($s) => EmailTemplate::e((string)$s);
        $num = (string)($d['invoice_number'] ?? '');
        $amt = self::amt($c, $d['amount'] ?? '');
        $due = (string)($d['due_date'] ?? '');
        $sub = "Invoice {$num} — {$amt} due {$due}";
        // "A PDF copy is attached" only when the sender attached one.
        $pdf = !empty($d['pdf_attached']);

        $body = EmailTemplate::h1('Your invoice is ready')
              . EmailTemplate::p('Dear ' . $e(self::greetingName($d)) . ',')
              . EmailTemplate::p('Here is your invoice for the coming service period.'
                . ($pdf ? ' A PDF copy is attached for your records.' : ''))
              . EmailTemplate::facts([
                    'Invoice'        => $num,
                    'Plan'           => (string)($d['plan_name'] ?? ''),
                    'Service period' => (string)($d['period'] ?? ''),
                    'Amount due'     => $amt,
                    'Due date'       => $due,
                    'Payment reference' => $num,
                ])
              . (($d['pay_url'] ?? '') !== ''
                    ? EmailTemplate::button($c, 'Pay ' . $amt, (string)$d['pay_url'])
                    : '')
              . self::payHow($c)
              . EmailTemplate::note('Your service is prepaid. Paying before <strong>' . $e($due)
                . '</strong> keeps your internet running without interruption.', 'info')
              . EmailTemplate::p(self::supportLine($c));

        $text = "Dear " . self::greetingName($d) . ",\r\n\r\n"
              . "Your invoice for the coming service period is ready" . ($pdf ? " (PDF attached)" : "") . ".\r\n\r\n"
              . self::textFacts([
                    'Invoice'           => $num,
                    'Plan'              => (string)($d['plan_name'] ?? ''),
                    'Service period'    => (string)($d['period'] ?? ''),
                    'Amount due'        => $amt,
                    'Due date'          => $due,
                    'Payment reference' => $num,
                ])
              . "\r\n"
              . self::payHowText($c)
              . "Your service is prepaid - paying before {$due} keeps your internet running.\r\n\r\n";

        return self::pack($c, $sub, $body, $text, $amt . ' due ' . $due);
    }

    // ── 6a. SERVICE PAUSED (prepaid — not a suspension) ─────────────────
    public static function servicePaused(array $c, array $d): array
    {
        $e = fn($s) => EmailTemplate::e((string)$s);
        $amt = self::amt($c, $d['amount'] ?? '');
        $num = (string)($d['invoice_number'] ?? '');
        $sub = 'Your DishNet service is paused' . ($amt !== '' ? ' — ' . $amt . ' to resume' : '');

        $body = EmailTemplate::h1('Your service is paused')
              . EmailTemplate::p('Dear ' . $e(self::greetingName($d)) . ',')
              . EmailTemplate::p('Your paid service period has ended, so your internet is paused for now. '
                . 'Nothing is cancelled and your equipment stays yours — the service simply waits for the '
                . 'next payment.')
              . EmailTemplate::facts([
                    'Invoice'         => $num,
                    'Amount to resume'=> $amt,
                    'Paid period ended'=> (string)($d['period_ended'] ?? ''),
                    'Payment reference'=> $num,
                ])
              . EmailTemplate::note('<strong>There is no reconnection fee.</strong> Your service resumes as '
                . 'soon as your payment reaches us — usually the same working day.', 'good')
              . self::payHow($c)
              . (($d['pay_url'] ?? '') !== ''
                    ? EmailTemplate::button($c, 'Pay ' . $amt . ' and resume', (string)$d['pay_url'])
                    : '')
              . EmailTemplate::p('Already paid? Send us the confirmation and we will check it straight away. '
                . self::supportLine($c));

        $text = "Dear " . self::greetingName($d) . ",\r\n\r\n"
              . "Your paid service period has ended, so your internet is paused. Nothing is cancelled.\r\n\r\n"
              . self::textFacts([
                    'Invoice'           => $num,
                    'Amount to resume'  => $amt,
                    'Paid period ended' => (string)($d['period_ended'] ?? ''),
                    'Payment reference' => $num,
                ])
              . "\r\n"
              . "There is no reconnection fee - your service resumes as soon as payment reaches us.\r\n\r\n"
              . self::payHowText($c)
              . "Already paid? Send us the confirmation and we will check immediately.\r\n\r\n";

        return self::pack($c, $sub, $body, $text, 'Paused — ' . $amt . ' resumes it, no reconnection fee');
    }

    // ── 6b. SERVICE RESUMED ─────────────────────────────────────────────
    public static function serviceResumed(array $c, array $d): array
    {
        $e = fn($s) => EmailTemplate::e((string)$s);
        $sub = 'Your DishNet service is back on';
        // "Your payment has been received" only when the sender saw one. A
        // service switched back on by staff, or after a postponement, is
        // active again all the same — and is told exactly that.
        $paid = !empty($d['paid']);

        $body = EmailTemplate::h1('You are back online')
              . EmailTemplate::p('Dear ' . $e(self::greetingName($d)) . ',')
              . EmailTemplate::p(($paid ? 'Your payment has been received and your internet is active again. '
                                        : 'Your internet is active again. ')
                . 'Thank you.')
              . EmailTemplate::facts([
                    'Plan'             => (string)($d['plan_name'] ?? ''),
                    'Payment received' => self::amt($c, $d['amount'] ?? ''),
                    'Service period'   => (string)($d['period'] ?? ''),
                    'Next payment due' => (string)($d['next_due'] ?? ''),
                ])
              . EmailTemplate::p('If any device is still offline, restart your router and wait two minutes. '
                . self::supportLine($c));

        $text = "Dear " . self::greetingName($d) . ",\r\n\r\n"
              . ($paid ? "Your payment has been received and your internet is active again.\r\n\r\n"
                       : "Your internet is active again.\r\n\r\n")
              . self::textFacts([
                    'Plan'             => (string)($d['plan_name'] ?? ''),
                    'Payment received' => self::amt($c, $d['amount'] ?? ''),
                    'Service period'   => (string)($d['period'] ?? ''),
                    'Next payment due' => (string)($d['next_due'] ?? ''),
                ])
              . "\r\n"
              . "If a device is still offline, restart your router and wait two minutes.\r\n\r\n";

        return self::pack($c, $sub, $body, $text, 'Service restored — thank you');
    }

    // ── 7. LOGIN CODE ───────────────────────────────────────────────────
    public static function loginCode(array $c, array $d): array
    {
        $e    = fn($s) => EmailTemplate::e((string)$s);
        $code = (string)($d['code'] ?? '');
        $ttl  = (int)($d['ttl_minutes'] ?? 15);
        $sub  = $code . ' is your DishNet login code';

        $body = EmailTemplate::h1('Your login code')
              . EmailTemplate::p('Hi ' . $e(self::greetingName($d)) . ', '
                . 'use this code to sign in to your DishNet account.')
              . '<table role="presentation" cellpadding="0" cellspacing="0" width="100%" '
                . 'style="background:#f7f7f7;border-radius:10px;margin:0 0 18px;"><tr>'
                . '<td align="center" style="padding:22px 14px;font-family:Helvetica,Arial,sans-serif;'
                . 'font-size:34px;font-weight:800;letter-spacing:8px;color:#141414;">' . $e($code) . '</td>'
                . '</tr></table>'
              . EmailTemplate::p('The code expires in <strong>' . $ttl . ' minutes</strong>.')
              . EmailTemplate::note('<strong>Didn\'t request this?</strong> You can ignore this email — '
                . 'your account is secure. Never share this code with anyone, including DishNet staff.', 'warn');

        $text = 'Hi ' . self::greetingName($d) . ",\r\n\r\n"
              . "Your DishNet login code is:\r\n\r\n    {$code}\r\n\r\n"
              . "It expires in {$ttl} minutes.\r\n\r\n"
              . "If you did not request this code, ignore this email - your account is secure.\r\n"
              . "Never share this code with anyone, including DishNet staff.\r\n\r\n";

        return self::pack($c, $sub, $body, $text, 'Code expires in ' . $ttl . ' minutes');
    }

    // ── 8. SUPPORT REQUEST RECEIVED ─────────────────────────────────────
    public static function supportReceived(array $c, array $d): array
    {
        $e   = fn($s) => EmailTemplate::e((string)$s);
        $ref = (string)($d['ticket_ref'] ?? '');
        $sub = 'We have your request' . ($ref !== '' ? ' — ' . $ref : '');

        $body = EmailTemplate::h1('We are on it')
              . EmailTemplate::p('Dear ' . $e(self::greetingName($d)) . ',')
              . EmailTemplate::p('Thank you for contacting DishNet support. Your request is logged and '
                . 'our team is looking at it.')
              . EmailTemplate::facts([
                    'Reference'   => $ref,
                    'Subject'     => (string)($d['subject'] ?? ''),
                    'Logged'      => (string)($d['logged_at'] ?? ''),
                    'Account'     => (string)($d['account_number'] ?? ''),
                ])
              . EmailTemplate::p('Reply to this email to add anything — photos of the equipment or error '
                . 'messages help us a lot. ' . self::supportLine($c))
              . EmailTemplate::note('For anything urgent, WhatsApp is fastest — we usually reply within '
                . 'minutes during the day.', 'info');

        $text = "Dear " . self::greetingName($d) . ",\r\n\r\n"
              . "Thank you for contacting DishNet support. Your request is logged.\r\n\r\n"
              . self::textFacts([
                    'Reference' => $ref,
                    'Subject'   => (string)($d['subject'] ?? ''),
                    'Logged'    => (string)($d['logged_at'] ?? ''),
                    'Account'   => (string)($d['account_number'] ?? ''),
                ])
              . "\r\n"
              . "Reply to this email to add anything. For anything urgent, WhatsApp is fastest.\r\n\r\n";

        return self::pack($c, $sub, $body, $text, 'Reference ' . $ref);
    }

    /** Render any catalogue key with a data array — used by the preview screen. */
    public static function render(string $key, array $config, array $data): array
    {
        switch ($key) {
            case 'quotation':         return self::quotation($config, $data);
            case 'payment_received':  return self::paymentReceived($config, $data);
            case 'install_scheduled': return self::installScheduled($config, $data);
            case 'welcome':           return self::welcomeActivated($config, $data);
            case 'invoice':           return self::invoice($config, $data);
            case 'service_paused':    return self::servicePaused($config, $data);
            case 'service_resumed':   return self::serviceResumed($config, $data);
            case 'login_code':        return self::loginCode($config, $data);
            case 'support_received':  return self::supportReceived($config, $data);
        }
        throw new \InvalidArgumentException('Unknown email template: ' . $key);
    }
}
