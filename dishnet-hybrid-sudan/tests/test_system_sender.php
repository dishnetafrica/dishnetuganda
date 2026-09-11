<?php
/**
 * test_system_sender.php — "i want OTP need to send via no-reply".
 *
 * A login code arriving from the sales mailbox is two problems wearing one
 * costume. A customer answers it, and the reply lands in a mailbox where an
 * expired six-digit number means nothing to anyone. And the sales mailbox
 * collects the bounces of every address that has gone stale.
 *
 * Making it come from no-reply@ is two changes, not one, and the second is
 * the one that hides. The From HEADER is in the message, easy to set and
 * easy to see. The ENVELOPE sender is a line of SMTP conversation — it is
 * what the relay routes bounces by, and it is not in the message at all. A
 * first attempt on the live server set only the header: the mail looked
 * right in the inbox and every bounce still went to accounts@.
 *
 * So these assertions are made against a relay's transcript, not a message.
 * The fake SMTP server writes down what it was actually told.
 *
 * And the whole feature is off unless it is configured, because Sudan has
 * never had a system sender and nothing there may move.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/lib/MailService.php';

$pass = 0; $fail = 0;
function ok(string $m): void  { global $pass; $pass++; echo "  ok   $m\n"; }
function bad(string $m, string $d=''): void { global $fail; $fail++; echo "  FAIL $m" . ($d ? "\n       $d" : '') . "\n"; }
function is_(bool $c, string $m, string $d=''): void { $c ? ok($m) : bad($m, $d); }

$tmp = sys_get_temp_dir() . '/dn_sysfrom_' . bin2hex(random_bytes(4));
@mkdir($tmp, 0777, true);
$transcript = $tmp . '/smtp.json';

// ── The relay ───────────────────────────────────────────────────────────────
$srv = null; $port = 0;
foreach (range(0, 9) as $slot) {
    $cand = 9790 + ((getmypid() + $slot * 13) % 60);
    @unlink($transcript);
    $p = proc_open(sprintf('exec php %s %d %s',
                       escapeshellarg($root . '/tests/fixtures/fake_smtp_server.php'),
                       $cand, escapeshellarg($transcript)),
                   [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
    // The server truncates the transcript once it is listening. Waiting for
    // that, rather than for a successful connect, means a port held by some
    // other process is never mistaken for ours.
    $up = false;
    for ($i = 0; $i < 40; $i++) {
        if (is_file($transcript)) { $up = true; break; }
        usleep(50000);
    }
    if ($up) {
        $probe = @fsockopen('127.0.0.1', $cand, $e1, $e2, 2);
        if ($probe) { $greet = fgets($probe, 256); @fclose($probe);
                      if (strpos((string)$greet, 'fake.smtp.test') !== false) { $srv = $p; $port = $cand; break; } }
    }
    proc_terminate($p); proc_close($p);
}
if ($port === 0) { fwrite(STDERR, "could not start the fake SMTP server\n"); exit(1); }

$settings = function (array $extra = []) use ($tmp): void {
    file_put_contents($tmp . '/email_settings.json', json_encode(array_merge([
        'use_ucrm_email' => false,
        'smtp_host'      => '127.0.0.1',
        'smtp_port'      => (int)$GLOBALS['__port'],
        'smtp_user'      => '',
        'smtp_pass'      => '',
        'smtp_enc'       => '',
        'smtp_from'      => 'accounts@dishnetuganda.com',
    ], $extra), JSON_PRETTY_PRINT));
};
$GLOBALS['__port'] = $port;

// Only sessions that actually delivered a message count.
//
// The port probe above opens a connection, reads the greeting and closes —
// and the server records that session AFTER the socket closes, so it can
// land in the transcript at any moment, including after $before was read.
// Counting raw sessions made the probe look like the first send: mail_from
// and ehlo came back empty and three assertions failed, intermittently and
// only under the load of the full suite. A probe never speaks MAIL FROM,
// so that is the thing to filter on.
$sessions = function () use ($transcript): array {
    clearstatcache();
    $all = json_decode((string)@file_get_contents($transcript), true) ?: [];
    return array_values(array_filter($all, function ($s) {
        return is_array($s) && trim((string)($s['mail_from'] ?? '')) !== '';
    }));
};
$before = count($sessions());
$latest = function () use ($sessions, &$before): ?array {
    for ($i = 0; $i < 60; $i++) {
        $all = $sessions();
        if (count($all) > $before) { $before = count($all); return end($all); }
        usleep(50000);
    }
    return null;
};

// ── normalizeFrom / bareAddress ─────────────────────────────────────────────
echo "\n── accepting and rejecting a sender ──\n";
is_(MailService::normalizeFrom('no-reply@dishnetuganda.com') === 'no-reply@dishnetuganda.com',
    'a bare address is accepted whole');
is_(MailService::normalizeFrom('  "DishNet Africa" <no-reply@dishnetuganda.com> ') === '"DishNet Africa" <no-reply@dishnetuganda.com>',
    'a display name survives; surrounding space does not');
is_(MailService::normalizeFrom('') === '', 'empty stays empty — that is the Sudan answer');
is_(MailService::normalizeFrom('no-reply') === '', 'a bare word is not an address');
is_(MailService::normalizeFrom('no-reply@') === '', 'a domainless address is refused');
is_(MailService::normalizeFrom("a@b.co\r\nBcc: someone@else.com") === '',
    'a newline is refused — that is header injection into every OTP');
is_(MailService::bareAddress('"DishNet" <no-reply@x.com>') === 'no-reply@x.com',
    'the envelope gets the address without the display name');
is_(MailService::bareAddress('no-reply@x.com') === 'no-reply@x.com',
    'an address with no display name is already bare');

// ── Sudan: nothing configured, nothing changes ──────────────────────────────
echo "\n── unconfigured: the sender does not move ──\n";
$settings();
$m = new MailService($tmp);
is_($m->systemFrom() === '', 'systemFrom() is empty when the key is absent');
$r = $m->send('customer@example.com', 'A Customer', 'Your code', '<p>123456</p>', '123456',
              [], [], $m->systemFrom());
is_(!empty($r['ok']), 'the mail sends', (string)($r['error'] ?? ''));
$s = $latest();
is_($s !== null && $s['mail_from'] === 'accounts@dishnetuganda.com',
    'envelope is the ordinary sender', 'got: ' . (string)($s['mail_from'] ?? 'nothing'));
is_($s !== null && strpos($s['data'], 'From: DishNet Africa <accounts@dishnetuganda.com>') !== false,
    'From header is the ordinary sender');
$steps = array_column($r['log'] ?? [], 'step');
is_(!in_array('from_override', $steps, true),
    'no override step is logged at all — this send is byte-for-byte the old one');
is_($s !== null && $s['ehlo'] === 'dishnetuganda.com',
    'EHLO is still the sender domain', 'got: ' . (string)($s['ehlo'] ?? ''));

// ── Configured: header AND envelope move together ───────────────────────────
echo "\n── configured: both halves move ──\n";
$settings(['system_from' => 'no-reply@dishnetuganda.com']);
$m = new MailService($tmp);
is_($m->systemFrom() === 'no-reply@dishnetuganda.com', 'systemFrom() reads the key');
$r = $m->send('customer@example.com', 'A Customer', 'Your code', '<p>123456</p>', '123456',
              ['Reply-To' => 'support@dishnetuganda.com'], [], $m->systemFrom());
is_(!empty($r['ok']), 'the mail sends', (string)($r['error'] ?? ''));
$s = $latest();
is_($s !== null && $s['mail_from'] === 'no-reply@dishnetuganda.com',
    'ENVELOPE is no-reply — this is the half that hides',
    'got: ' . (string)($s['mail_from'] ?? 'nothing'));
is_($s !== null && strpos($s['data'], 'From: DishNet Africa <no-reply@dishnetuganda.com>') !== false,
    'From header is no-reply');
is_($s !== null && strpos($s['data'], 'accounts@dishnetuganda.com') === false,
    'the ordinary sender appears nowhere in the message');
is_($s !== null && strpos($s['data'], 'Reply-To: support@dishnetuganda.com') !== false,
    'Reply-To still points a confused customer at a human');
is_($s !== null && $s['rcpt_to'] === ['customer@example.com'], 'the recipient is unchanged');

// ── Only OTP moves: an ordinary send still uses the ordinary sender ─────────
echo "\n── configured, but not asked for: quotes are correspondence ──\n";
$m = new MailService($tmp);
$r = $m->send('customer@example.com', 'A Customer', 'Your quotation', '<p>Quote</p>', 'Quote');
is_(!empty($r['ok']), 'the mail sends', (string)($r['error'] ?? ''));
$s = $latest();
is_($s !== null && $s['mail_from'] === 'accounts@dishnetuganda.com',
    'a caller that asks for nothing still sends as accounts@ — quotes must be answerable',
    'got: ' . (string)($s['mail_from'] ?? 'nothing'));

// ── A display name in the config ────────────────────────────────────────────
echo "\n── a display name is kept in the header and dropped from the envelope ──\n";
$settings(['system_from' => '"DishNet Africa" <no-reply@dishnetuganda.com>']);
$m = new MailService($tmp);
$r = $m->send('customer@example.com', '', 'Your code', '<p>123456</p>', '123456', [], [], $m->systemFrom());
$s = $latest();
is_($s !== null && $s['mail_from'] === 'no-reply@dishnetuganda.com', 'envelope is the bare address');
is_($s !== null && strpos($s['data'], 'From: "DishNet Africa" <no-reply@dishnetuganda.com>') !== false,
    'header keeps the name the operator chose, not a second one bolted on');

// ── A bad value must not take mail down ─────────────────────────────────────
echo "\n── a bad override falls back, loudly ──\n";
$settings(['system_from' => 'not-an-address']);
$m = new MailService($tmp);
is_($m->systemFrom() === '', 'a bad configured value reads as unset, not as itself');
$r = $m->send('customer@example.com', 'A Customer', 'Your code', '<p>1</p>', '1', [], [], 'also-bad');
is_(!empty($r['ok']), 'the mail still sends — a typo here must not stop OTP', (string)($r['error'] ?? ''));
$s = $latest();
is_($s !== null && $s['mail_from'] === 'accounts@dishnetuganda.com',
    'envelope falls back to the ordinary sender');
$over = null;
foreach (($r['log'] ?? []) as $step) if (($step['step'] ?? '') === 'from_override') $over = $step;
is_($over !== null && empty($over['ok']), 'the refusal is in the log, not swallowed');
is_($over !== null && strpos((string)$over['msg'], 'accounts@dishnetuganda.com') !== false,
    'the log says which address was used instead');

// ── The uCRM-mailer path keeps the key ──────────────────────────────────────
echo "\n── system_from survives the uCRM-mailer toggle ──\n";
$settings(['system_from' => 'no-reply@dishnetuganda.com', 'use_ucrm_email' => true]);
$m = new MailService($tmp);
// No ucrm.json here, so the UCRM read fails and the plugin SMTP is the
// fallback — the path a live install takes when the API is unreachable.
is_($m->systemFrom() === 'no-reply@dishnetuganda.com',
    'the fallback config still carries the system sender');

// ── email_setup.php must not drop it ────────────────────────────────────────
echo "\n── a full email_setup run leaves it alone ──\n";
$settings(['system_from' => 'no-reply@dishnetuganda.com']);
$env = 'DN_DATA_DIR=' . escapeshellarg($tmp);
exec(sprintf('%s php %s --user setup@dishnetuganda.com --pass secret --host smtp.example.com 2>&1',
     $env, escapeshellarg($root . '/tools/email_setup.php')), $out, $rc);
$after = json_decode((string)@file_get_contents($tmp . '/email_settings.json'), true) ?: [];
is_(($after['system_from'] ?? '') === 'no-reply@dishnetuganda.com',
    'email_setup rewrote the SMTP block and kept system_from',
    'rc=' . $rc . ' got: ' . json_encode($after['system_from'] ?? null));
is_(($after['smtp_host'] ?? '') === 'smtp.example.com', 'and it did change what it was asked to change');

// ── The real OTP path, not an imitation of it ───────────────────────────────
// The assertions above prove MailService can do it. This one proves the code
// that actually sends a login code asks it to — which is the part that was
// missing on the live server while the header already said no-reply.
echo "\n── a real OTP, built by the code the API calls ──\n";
require_once $root . '/lib/OtpEmail.php';
$settings(['system_from' => 'no-reply@dishnetuganda.com']);
$r = OtpEmail::send([], $tmp, 'customer@example.com', 'Bhavin Madlani', '481920', 10);
is_(!empty($r['ok']), 'the OTP sends', (string)($r['error'] ?? ''));
is_(($r['from'] ?? '') === 'no-reply@dishnetuganda.com', 'it reports which sender it used');
$s = $latest();
is_($s !== null && $s['mail_from'] === 'no-reply@dishnetuganda.com',
    'the OTP envelope is no-reply', 'got: ' . (string)($s['mail_from'] ?? 'nothing'));
is_($s !== null && strpos($s['data'], 'no-reply@dishnetuganda.com') !== false,
    'and so is the OTP header');
is_($s !== null && strpos($s['data'], '481920') !== false, 'the code is in the message');
is_($s !== null && stripos($s['data'], 'Reply-To:') !== false,
    'a Reply-To is still set, so a stuck customer can reach a person');

// Unconfigured, the same call must behave exactly as it did before this
// feature existed — that is the Sudan install, and it may not move.
$settings();
$r = OtpEmail::send([], $tmp, 'customer@example.com', 'Bhavin Madlani', '481920', 10);
is_(!empty($r['ok']) && ($r['from'] ?? 'x') === '', 'unconfigured, the OTP reports no override');
$s = $latest();
is_($s !== null && $s['mail_from'] === 'accounts@dishnetuganda.com',
    'and goes out as the ordinary sender, exactly as before');

// The dispatcher must be calling this and not a private copy of it.
$api = (string)file_get_contents($root . '/includes/api/api_customer_app.php');
$fn  = strpos($api, 'function ca_send_otp_email');
$body = $fn === false ? '' : substr($api, $fn, 1500);
is_(strpos($body, 'OtpEmail::send(') !== false,
    'ca_send_otp_email() delegates to the code tested above, so the two cannot drift');

// ── otp_email_test.php prints a readable conversation ───────────────────────
// A relay answers EHLO with several CRLF-separated lines. Printed raw, the
// bare \r sends the terminal's cursor to column 0 and the next row overwrites
// this one — which is exactly what happened on the live server: the EHLO step
// disappeared from a report whose entire job is to show every step.
echo "\n── the report survives a multi-line relay reply ──\n";
$settings(['system_from' => 'no-reply@dishnetuganda.com']);
$envTool = 'DN_DATA_DIR=' . escapeshellarg($tmp);
$out = [];
exec(sprintf('%s php %s --to bhavin@dishnetafrica.com 2>&1',
     $envTool, escapeshellarg($root . '/tools/otp_email_test.php')), $out, $rcTool);
$printed = implode("\n", $out);
$latest();   // consume the session this just created
is_($rcTool === 0, 'the tool exits clean on a successful send', $printed);
is_(strpos($printed, "\r") === false, 'no carriage return survives into the output');
is_(preg_match('/^\s+ok\s+ehlo\s+250-/m', $printed) === 1,
    'the EHLO row is present and on its own line', $printed);
is_(substr_count($printed, ' / ') >= 1, 'the reply\'s continuation lines are joined, not lost');
is_(strpos($printed, 'envelope sender  no-reply@dishnetuganda.com') !== false,
    'the headline names the envelope address, not the relay\'s response code');

// ── set_system_sender.php ───────────────────────────────────────────────────
echo "\n── the tool an operator without a UI actually runs ──\n";
$tool = escapeshellarg($root . '/tools/set_system_sender.php');
$settings();   // back to unset
$run = function (string $args) use ($env, $tool): array {
    $out = []; $rc = 0;
    exec(sprintf('%s php %s %s 2>&1', $env, $tool, $args), $out, $rc);
    return [implode("\n", $out), $rc];
};
[$o, $rc] = $run('--show');
is_($rc === 0 && strpos($o, '(not set)') !== false, '--show says so when nothing is set', $o);

[$o, $rc] = $run('--set no-reply@dishnetuganda.com');
$after = json_decode((string)@file_get_contents($tmp . '/email_settings.json'), true) ?: [];
is_($rc === 0 && ($after['system_from'] ?? '') === 'no-reply@dishnetuganda.com', '--set writes the key', $o);
is_(($after['smtp_from'] ?? '') === 'accounts@dishnetuganda.com' && ($after['smtp_host'] ?? '') === '127.0.0.1',
    'and leaves the SMTP credentials exactly where they were');
is_(count(glob($tmp . '/email_settings.json.bak.*')) === 1,
    'a backup of the old file is kept — this file is the whole mail system');

[$o, $rc] = $run('--set "not an address"');
$after = json_decode((string)@file_get_contents($tmp . '/email_settings.json'), true) ?: [];
is_($rc !== 0, 'a bad address is refused with a non-zero exit', $o);
is_(($after['system_from'] ?? '') === 'no-reply@dishnetuganda.com',
    'and the file is untouched — a refusal never half-writes');

[$o, $rc] = $run('--froom no-reply@dishnetuganda.com');
is_($rc === 2 && stripos($o, 'older than the command') !== false,
    'a typo is named, not silently ignored while reporting success', $o);

[$o, $rc] = $run('--clear');
$after = json_decode((string)@file_get_contents($tmp . '/email_settings.json'), true) ?: [];
is_($rc === 0 && !isset($after['system_from']), '--clear removes the key', $o);
is_(($after['smtp_host'] ?? '') === '127.0.0.1', 'and still leaves SMTP alone');

// ── done ────────────────────────────────────────────────────────────────────
if ($srv) { proc_terminate($srv); proc_close($srv); }
exec('rm -rf ' . escapeshellarg($tmp));

echo "\n  {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
