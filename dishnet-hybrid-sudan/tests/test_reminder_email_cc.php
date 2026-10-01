<?php
declare(strict_types=1);
/**
 * test_reminder_email_cc.php — 5.18.60: CC the client's other contacts, and
 * payment reminders by e-mail (before-due, prepaid-safe).
 *
 *   (A) EmailRecipients — To = billing-first, CC = every other distinct valid
 *       email; invalids dropped, duplicates collapsed case-insensitively, To
 *       never duplicated into CC.
 *   (B) MailService delivers to CC — a Cc header produces one RCPT TO per CC
 *       address (proved against the fake relay), off by default (no header →
 *       one recipient).
 *   (C) CustomerEmailDispatcher — email_cc_contacts gates CC on lifecycle mail;
 *       off is byte-for-byte the old single-recipient behaviour.
 *   (D) Reminder e-mail — CustomerEmails::reminderDue is prepaid-safe (never
 *       "suspend"/"cut off"), carries the money + due facts, and
 *       sendReminderDue is gated by reminder_email_enabled AND the master.
 *   (E) Safety — reminder_due is NOT a catalogue entry (preview/golden
 *       untouched); OTP/login mail never CCs.
 */
$root = dirname(__DIR__);
require_once $root . '/lib/bootstrap_data.php';
require_once $root . '/lib/EmailTemplate.php';
require_once $root . '/lib/CustomerEmails.php';
require_once $root . '/lib/EmailRecipients.php';
require_once $root . '/lib/MailService.php';
require_once $root . '/lib/CustomerEmailDispatcher.php';

$pass = 0; $fail = 0;
function is_(bool $c, string $m, string $d = ''): void { global $pass, $fail;
    if ($c) { $pass++; echo "  ok   $m\n"; } else { $fail++; echo "  FAIL $m" . ($d !== '' ? "\n       $d" : '') . "\n"; } }

$tmp = sys_get_temp_dir() . '/remcc_' . bin2hex(random_bytes(4));
@mkdir($tmp, 0777, true);
$GLOBALS['dataDir'] = $tmp;

// ── A. EmailRecipients ──────────────────────────────────────────────────────
$client = ['firstName' => 'Kris', 'lastName' => 'Chinna', 'contacts' => [
    ['email' => 'ops@bul.co.ug'],                         // first, non-billing
    ['email' => 'Kris.Chinna@bul.co.ug', 'isBilling' => true],
    ['email' => 'accounts@bul.co.ug'],
    ['email' => 'ops@bul.co.ug'],                         // duplicate
    ['email' => 'not-an-email'],                          // invalid
]];
$r = EmailRecipients::fromClient($client);
is_($r['to'] === 'kris.chinna@bul.co.ug', 'A1 To is the billing contact', $r['to']);
is_($r['cc'] === ['ops@bul.co.ug', 'accounts@bul.co.ug'], 'A2 CC is every other distinct valid email, To excluded', json_encode($r['cc']));
is_(!in_array($r['to'], $r['cc'], true), 'A3 To never appears in CC');
is_($r['to_name'] === 'Kris Chinna', 'A4 display name from first/last');

$noBill = ['companyName' => 'Acme', 'contacts' => [['email' => 'a@acme.test'], ['email' => 'b@acme.test']]];
$r2 = EmailRecipients::fromClient($noBill);
is_($r2['to'] === 'a@acme.test' && $r2['cc'] === ['b@acme.test'], 'A5 no billing flag → first contact is To');
is_($r2['to_name'] === 'Acme', 'A6 company name when no person name');

$one = ['contacts' => [['email' => 'solo@x.test', 'isBilling' => true]]];
is_(EmailRecipients::fromClient($one)['cc'] === [], 'A7 a single contact has no CC');
is_(EmailRecipients::fromClient(['contacts' => []])['to'] === '', 'A8 no contacts → empty To');
is_(EmailRecipients::ccFor($client, 'kris.chinna@bul.co.ug') === ['ops@bul.co.ug', 'accounts@bul.co.ug'], 'A9 ccFor excludes a given To');

// ── E (static). reminder_due is NOT a catalogue entry; OTP never CCs ─────────
is_(!array_key_exists('reminder_due', CustomerEmails::CATALOGUE), 'E1 reminder_due is not in CATALOGUE (preview/golden untouched)');
$otp = (string)file_get_contents($root . '/lib/OtpEmail.php');
is_(strpos($otp, "'Cc'") === false && strpos($otp, '"Cc"') === false && strpos($otp, 'EmailRecipients') === false,
    'E2 OtpEmail never sets a Cc header / resolves contacts (login codes go to one person)');

// ── D (content). reminderDue is prepaid-safe and carries the facts ──────────
$cfg = ['email_currency' => 'UGX', 'company_name' => 'DishNet Africa', 'email_support_phone' => '+256 705 993 348'];
$built = CustomerEmails::reminderDue($cfg, [
    'name' => 'Bugiri Sugar', 'invoice_number' => '1234', 'amount' => 329000,
    'due_date' => '8 Oct 2026', 'due_phrase' => 'due in 7 days', 'plan_name' => 'Business 20Mbps',
    'pay_url' => 'https://dishnetuganda.com/pay',
]);
$blob = strtolower($built['subject'] . ' ' . $built['html'] . ' ' . $built['text']);
is_(strpos($blob, 'suspend') === false && strpos($blob, 'cut off') === false && strpos($blob, 'disconnect') === false,
    'D1 reminder wording never threatens suspension (prepaid-safe)');
is_(strpos($built['html'], '1234') !== false && strpos($built['html'], '329,000') !== false, 'D2 carries invoice number and amount');
is_(strpos($blob, 'due in 7 days') !== false, 'D3 carries the day phrase');
is_(strpos($built['subject'], 'reminder') !== false || strpos($built['subject'], 'Reminder') !== false, 'D4 subject says reminder');

// ── Fake SMTP relay, for the envelope assertions (B, C, D delivery) ──────────
$procs = [];
$start = function (string $cmd, callable $isUp) use (&$procs): int {
    for ($slot = 0; $slot < 40; $slot++) {
        $port = 20000 + ((getmypid() + $slot * 77) % 20000);
        $p = proc_open(str_replace('{PORT}', (string)$port, $cmd), [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w']], $pipes);
        if (!is_resource($p)) continue;
        $ok = false;
        for ($i = 0; $i < 50 && !$ok; $i++) { usleep(100000); $ok = $isUp($port); }
        if ($ok) { $procs[] = $p; return $port; }
        @proc_terminate($p);
    }
    return 0;
};
$transcript = $tmp . '/smtp.json';
$smtpPort = $start(
    sprintf('exec php %s {PORT} %s 20', escapeshellarg($root . '/tests/fixtures/fake_smtp_server.php'), escapeshellarg($transcript)),
    function (int $port) use ($transcript): bool {
        if (!is_file($transcript)) return false;
        $s = @fsockopen('127.0.0.1', $port, $e1, $e2, 2); if (!$s) return false; fclose($s); return true;
    }
);
is_($smtpPort > 0, 'fake SMTP relay started');

file_put_contents($tmp . '/email_settings.json', json_encode([
    'use_ucrm_email' => false, 'smtp_host' => '127.0.0.1', 'smtp_port' => $smtpPort,
    'smtp_user' => '', 'smtp_pass' => '', 'smtp_enc' => '', 'smtp_from' => 'accounts@dishnetuganda.com',
]));

// Only real sends. The readiness probe opens a socket, takes the greeting and
// closes without a RCPT, which the relay records as an empty session; filtering
// on a non-empty rcpt_to drops it regardless of when it lands.
$sessions = function () use ($transcript): array {
    clearstatcache();
    $all = json_decode((string)@file_get_contents($transcript), true) ?: [];
    return array_values(array_filter($all, static function ($s) { return !empty($s['rcpt_to']); }));
};
// The relay writes a session only after the connection closes, so a read
// straight after send() can race it. Poll until at least $n sessions exist,
// then return the $n-th (1-based).
$grab = function (int $n) use ($sessions) {
    for ($i = 0; $i < 60; $i++) { $s = $sessions(); if (count($s) >= $n) return $s[$n - 1]; usleep(50000); }
    $s = $sessions(); return $s ? end($s) : null;
};
$lc = function ($arr): array { return array_map('strtolower', (array)$arr); };

// A stub uCRM that returns our two-CC client for any clients/{id} read.
$stubCrm = new class($client) {
    private $c;
    public function __construct(array $c) { $this->c = $c; }
    public function get($path) { return strpos((string)$path, 'clients/') === 0 ? $this->c : null; }
};

if ($smtpPort > 0) {
    // ── C1 (control). CC switch OFF → one recipient, no Cc header ───────────
    $offCfg = ['customer_emails_enabled' => '1', 'customer_email_invoice' => '1']; // email_cc_contacts absent
    $dOff = new CustomerEmailDispatcher($tmp, $offCfg, $stubCrm, null);
    $dOff->send('invoice', ['client_id' => 42], 'Kris', ['invoice_number' => 'X1', 'amount' => 100, 'due_date' => '1 Jan'], '');
    $m = $grab(1);
    is_($m && $lc($m['rcpt_to']) === ['kris.chinna@bul.co.ug'], 'C1 CC off → single recipient (billing contact only)', json_encode($m['rcpt_to'] ?? null));
    is_($m && stripos($m['data'], "\nCc:") === false, 'C1b CC off → no Cc header in the message');

    // ── B/C2. CC switch ON → To + every other contact, Cc header present ────
    $onCfg = ['customer_emails_enabled' => '1', 'customer_email_invoice' => '1', 'email_cc_contacts' => '1'];
    $dOn = new CustomerEmailDispatcher($tmp, $onCfg, $stubCrm, null);
    $dOn->send('invoice', ['client_id' => 42], 'Kris', ['invoice_number' => 'X2', 'amount' => 100, 'due_date' => '1 Jan'], '');
    $m = $grab(2);
    $rcpt = $lc($m['rcpt_to'] ?? []);
    is_(in_array('kris.chinna@bul.co.ug', $rcpt, true)
        && in_array('ops@bul.co.ug', $rcpt, true)
        && in_array('accounts@bul.co.ug', $rcpt, true)
        && count($rcpt) === 3, 'B1 CC on → RCPT TO the billing contact AND both other contacts', json_encode($m['rcpt_to'] ?? null));
    is_($m && stripos($m['data'], 'Cc: ops@bul.co.ug, accounts@bul.co.ug') !== false, 'B2 Cc header lists the copied addresses');
    // The Cc header line must not carry the To address.
    $ccLine = '';
    foreach (explode("\n", (string)($m['data'] ?? '')) as $ln) { if (stripos($ln, 'Cc:') === 0) { $ccLine = strtolower($ln); break; } }
    is_($ccLine !== '' && strpos($ccLine, 'kris.chinna@bul.co.ug') === false, 'B3 the To is not also in the Cc header', $ccLine);

    // ── D5. sendReminderDue gated OFF (reminder_email_enabled absent) ───────
    $remOff = new CustomerEmailDispatcher($tmp, ['customer_emails_enabled' => '1'], $stubCrm, null);
    $before = count($sessions());
    $rr = $remOff->sendReminderDue(42, 'Kris', ['invoice_number' => 'R1', 'amount' => 100, 'due_date' => '1 Jan', 'due_phrase' => 'due tomorrow'], 'R1-d1');
    is_(empty($rr['sent']) && $rr['reason'] === 'switched off' && count($sessions()) === $before, 'D5 reminder e-mail off by default (no send)');

    // ── D6. sendReminderDue ON (master + reminder_email_enabled) + CC ───────
    $remOn = new CustomerEmailDispatcher($tmp,
        ['customer_emails_enabled' => '1', 'reminder_email_enabled' => '1', 'email_cc_contacts' => '1', 'email_currency' => 'UGX'],
        $stubCrm, null);
    $rr = $remOn->sendReminderDue(42, 'Kris', ['invoice_number' => 'R2', 'amount' => 250000, 'due_date' => '8 Oct 2026', 'due_phrase' => 'due in 3 days', 'plan_name' => 'Business'], 'R2-d3');
    is_(!empty($rr['sent']), 'D6 reminder e-mail sends when switched on', json_encode($rr));
    $m = $grab(3);
    $rcpt = $lc($m['rcpt_to'] ?? []);
    is_(count($rcpt) === 3 && in_array('ops@bul.co.ug', $rcpt, true), 'D7 reminder e-mail CCs the other contacts too', json_encode($m['rcpt_to'] ?? null));
    is_($m && stripos($m['data'], 'suspend') === false && strpos((string)$m['data'], '250,000') !== false, 'D8 reminder body prepaid-safe with the amount');
}

foreach ($procs as $p) { @proc_terminate($p); }

echo "\n  reminder-email + CC: {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
