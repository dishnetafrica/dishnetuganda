<?php
declare(strict_types=1);
/**
 * test_install_scheduled_whatsapp.php — 5.18.84: the customer's WhatsApp when an installation job is booked (Uganda).
 *
 * On 06 Oct a customer with no e-mail address was booked for an installation: the technician was told at once, the
 * customer heard nothing, because uCRM's job.add told the customer by e-mail only. Proved here through the real
 * webhook.php of a sandboxed plugin, beside a fake uCRM, a fake WhatsApp and a mail relay:
 *
 *   1. off by default: job.add for an installation sends the customer no WhatsApp, and claims nothing
 *   2. on (Uganda): one WhatsApp, from the support number, to the client's uCRM number — every byte pinned; the Message
 *      Log and the webhook log say so, the log line without the number
 *   3. once per job: job.add delivered again, with the same or a new uuid, is still one message
 *   4. a client with no e-mail address gets the WhatsApp (the case that started this); a client with one gets both
 *   5. nothing for a job that is not an installation, has no date, no client, or a client with no number (and no claim
 *      then); no Technician line for an unassigned job or a uCRM user with no name
 *   6. a Starlink installation under Customer Installation Authorisation says the secure link follows; without the
 *      authorisation switch, or for a Fiber installation, it does not
 *   7. South Sudan: the switch on changes nothing
 *   8. the text, a pure function: every line, the lines left out, emphasis marks and line breaks in values, the zone
 *   9. a failed send: in the failure queue for a manual retry, said in the log, not sent again on a redelivery
 *  10. tools/set_config.php sets, lists and clears the switch
 *  11. weakened copies of the code each fail this test
 *
 * What this proves is what the plugin hands to WhatsApp and to the mail server, not delivery to a phone. Every person,
 * number, e-mail and job is fictitious; nothing leaves the machine.
 *
 *   php test_install_scheduled_whatsapp.php [--root=DIR] [--no-mutants]
 */
$opt  = getopt('', ['root:', 'no-mutants']);
$root = isset($opt['root']) ? rtrim((string)$opt['root'], '/') : dirname(__DIR__);
$withMutants = !isset($opt['no-mutants']);
require_once __DIR__ . '/fixtures/staff_jobs_scenario.php';

$pass = 0; $fail = 0;
function is_(bool $c, string $m, string $d = ''): void {
    global $pass, $fail;
    if ($c) { $pass++; echo "  ok   {$m}\n"; }
    else    { $fail++; echo "  FAIL {$m}" . ($d !== '' ? "\n       {$d}" : '') . "\n"; }
}

const CUST    = '256700000915';   // client 15, the scenario's customer: a number and an e-mail address
const NOMAIL  = '256700000916';   // client 16: a number, no e-mail address
const WHEN    = '2026-10-05T09:00:00+0300';
const FLAG    = 'customer_wa_install_scheduled';

$prof = json_decode((string)file_get_contents($root . '/profiles/uganda.json'), true);
$SUPPORT_WA = (string)preg_replace('/\D+/', '', (string)($prof['contacts']['support_wa'] ?? ''));
is_($SUPPORT_WA !== '', 'the Uganda profile has a support WhatsApp number (the message\'s Support line reads it)');

/** A Uganda sandbox: the scenario's uCRM, plus a client with no e-mail, one with no number, a user with no name. */
$start = function (string $tag, array $cfg = []) use ($root): SjSandbox {
    $s = SjSandbox::start($root, $cfg + ['tenant_profile' => 'uganda', 'timezone' => 'Africa/Kampala'], $tag);
    file_put_contents($s->plug . '/ucrm.json', json_encode(['pluginDataDir' => $s->data, 'pluginPublicUrl' => $s->base]));
    $crm = SjScenario::crm();
    $crm['clients']['16'] = ['id' => 16, 'firstName' => 'Nomail', 'lastName' => 'Customer', 'street1' => 'Plot 16 Sandbox Lane',
        'street2' => '', 'city' => 'Kampala', 'note' => '', 'isLead' => false,
        'contacts' => [['email' => '', 'phone' => '+256700000916', 'isBilling' => true]]];
    $crm['clients']['17'] = ['id' => 17, 'firstName' => 'Nophone', 'lastName' => 'Customer', 'street1' => 'Plot 17 Sandbox Lane',
        'street2' => '', 'city' => 'Kampala', 'note' => '', 'isLead' => false,
        'contacts' => [['email' => 'nophone@example.test', 'phone' => '', 'isBilling' => true]]];
    $crm['users']['1101'] = ['id' => 1101, 'username' => 'sb-noname', 'firstName' => '', 'lastName' => '', 'email' => 'noname@example.test', 'isActive' => true];
    $s->seedCrm($crm);
    return $s;
};
$setJob = function (SjSandbox $s, int $id, array $fields): void {
    $jobs = (array)($s->crmDump()['jobs'] ?? []);
    $jobs[(string)$id] = array_merge(['id' => $id, 'title' => "Sandbox job {$id}", 'description' => '', 'clientId' => 15,
        'assignedUserId' => 1099, 'date' => WHEN, 'duration' => 60, 'status' => 0, 'address' => 'Plot 9 Sandbox Road, Kampala'], $fields);
    $s->seedCrm(['jobs' => $jobs]);
};
/** WhatsApp texts the fake Evolution took, to $to, from index $from. */
$texts = function (SjSandbox $s, string $to, int $from = 0): array {
    return array_values(array_filter(array_slice($s->texts(), $from), function ($t) use ($to) { return $t['number'] === $to; }));
};
$claimed = function (SjSandbox $s, int $job): bool {
    try { return count($s->q('SELECT 1 FROM notification_dedup WHERE dedup_key = ?', ['WAINSTALL' . $job])) === 1; }
    catch (\Throwable $e) { return false; }
};
/** The webhook log's lines about this feature for one job. */
$waLines = function (SjSandbox $s, int $job): array {
    return array_values(array_filter(preg_split('/\R/', $s->webhookLog()) ?: [], function ($l) use ($job) {
        return strpos($l, "Job #{$job}: customer WhatsApp (installation scheduled)") !== false;
    }));
};
$expected = function (string $type, string $date, string $time, string $loc, string $tech, int $job, bool $auth = false, string $name = 'Sandbox') use ($SUPPORT_WA): string {
    $m  = "🔧 *Installation Scheduled — DishNet Africa*\n\nDear {$name},\n\nYour installation has been scheduled. ✅\n\n";
    $m .= "📋 *{$type}*\n📅 Date: *{$date}*\n";
    if ($time !== '') $m .= "⏰ Time: *{$time}*\n";
    if ($loc !== '')  $m .= "📍 Location: {$loc}\n";
    if ($tech !== '') $m .= "👷 Technician: *{$tech}*\n";
    $m .= "🔖 Job: #{$job}\n\n";
    if ($auth) $m .= "📝 Before the installation, you will receive a separate WhatsApp from us with a secure link to review and accept the installation terms and charges.\n\n";
    $m .= "Our technician will contact you before arriving. Please make sure someone is available at the site.\n\n";
    $m .= "🛠 Support: wa.me/{$SUPPORT_WA}\n— DishNet Africa Support";
    return $m;
};

// ── 1 ─────────────────────────────────────────────────────────────────────────
echo "\n1. Off by default\n";
$s = $start('isw-off');
$s->fire('job.add', 'job', 901, 'isw-1a');
is_(count($texts($s, CUST)) === 0, 'an installation booked with the switch absent sends the customer no WhatsApp');
is_(!$claimed($s, 901) && $waLines($s, 901) === [], '…claims nothing, and writes no line about it');
$s->stop();
$s = $start('isw-off0', [FLAG => '0']);
$s->fire('job.add', 'job', 901, 'isw-1b');
is_(count($texts($s, CUST)) === 0 && !$claimed($s, 901), 'set to 0: nothing either');
$s->stop();

// ── 2 ─────────────────────────────────────────────────────────────────────────
echo "\n2. On, Uganda: one WhatsApp to the client's uCRM number\n";
$s = $start('isw-on', [FLAG => '1']);
$r = $s->fire('job.add', 'job', 901, 'isw-2a');
is_($r[0] === 200, 'job.add answers 200', 'HTTP ' . $r[0]);
$t = $texts($s, CUST);
is_(count($t) === 1, 'one WhatsApp to the client\'s first uCRM contact number', count($t) . ' message(s)');
is_(($t[0]['instance'] ?? '') === 'sj-support', 'from the support number', (string)($t[0]['instance'] ?? ''));
$want = $expected('Fiber installation', 'Monday 5 October 2026', '9:00 AM', 'Plot 9 Sandbox Road, Kampala', 'Sandbox Tech', 901);
is_(($t[0]['text'] ?? '') === $want, 'the message, byte for byte: the date and the time in Kampala, the location, the technician, the job',
    "got:\n" . ($t[0]['text'] ?? '') . "\nwant:\n" . $want);
$log = $s->q("SELECT event, phone, success FROM notification_audit_log WHERE event = 'ops_installation_scheduled'");
is_(count($log) === 1 && (int)$log[0]['success'] === 1 && strpos((string)$log[0]['phone'], '700000915') !== false,
    'the Message Log has it, as ops_installation_scheduled, sent', json_encode($log));
$lines = $waLines($s, 901);
is_(count($lines) === 1 && strpos($lines[0], "sent to the client's uCRM number") !== false, 'the webhook log says it was sent', implode(' | ', $lines));
is_($lines !== [] && strpos($lines[0], '700000915') === false && strpos($lines[0], 'Dear') === false,
    '…without the customer\'s number or the message', $lines[0] ?? '');
is_($claimed($s, 901), 'and the job is claimed in notification_dedup (WAINSTALL901)');

// ── 3 ─────────────────────────────────────────────────────────────────────────
echo "\n3. Once per job\n";
$s->fire('job.add', 'job', 901, 'isw-2a');
$s->fire('job.add', 'job', 901, 'isw-3b');
$s->fire('job.add', 'job', 901, 'isw-3c');
is_(count($texts($s, CUST)) === 1, 'job.add delivered again — the same uuid, then two new ones — is still one message');
$lines = $waLines($s, 901);
is_(count(array_filter($lines, function ($l) { return strpos($l, 'not sent again') !== false; })) >= 1,
    'a redelivery the webhook processes says it was not sent again', implode(' | ', $lines));

// ── 5 (same sandbox) ──────────────────────────────────────────────────────────
echo "\n5. Nothing to send, and the lines a job does not have\n";
$n0 = count($s->texts());
$setJob($s, 930, ['title' => 'Router check — Sandbox Customer']);
$s->fire('job.add', 'job', 930, 'isw-5a');
$setJob($s, 931, ['title' => 'Fiber installation — Sandbox Customer', 'date' => '']);
$s->fire('job.add', 'job', 931, 'isw-5b');
$setJob($s, 932, ['title' => 'Fiber installation', 'clientId' => null]);
$s->fire('job.add', 'job', 932, 'isw-5c');
is_(count($texts($s, CUST, $n0)) === 0 && !$claimed($s, 930) && !$claimed($s, 931) && !$claimed($s, 932),
    'not an installation, no date, no client: no WhatsApp, no claim — the e-mail\'s three conditions');
$setJob($s, 933, ['title' => 'Fiber installation — Nophone Customer', 'clientId' => 17]);
$s->fire('job.add', 'job', 933, 'isw-5d');
$lines = $waLines($s, 933);
is_(!$claimed($s, 933) && count($lines) === 1 && strpos($lines[0], 'the client has no phone number in uCRM') !== false,
    'a client with no number: nothing sent, nothing claimed, and the log says why', implode(' | ', $lines));
$setJob($s, 934, ['title' => 'Fiber installation — Sandbox Customer', 'assignedUserId' => 1101]);
$s->fire('job.add', 'job', 934, 'isw-5e');
$t = $texts($s, CUST, $n0);
is_(count($t) === 1 && strpos($t[0]['text'], 'Technician') === false && strpos($t[0]['text'], '🔖 Job: #934') !== false,
    'a uCRM user with no name: no Technician line — never uCRM\'s "Technician" placeholder', $t[0]['text'] ?? '(none)');
$setJob($s, 935, ['title' => 'Fiber installation — Sandbox Customer', 'assignedUserId' => null, 'address' => null]);
$s->fire('job.add', 'job', 935, 'isw-5f');
$t = $texts($s, CUST, $n0);
is_(count($t) === 2 && $t[1]['text'] === $expected('Fiber installation', 'Monday 5 October 2026', '9:00 AM', 'Plot 9 Sandbox Road Kampala Kampala', '', 935),
    'nobody assigned: no Technician line; no job address: the client\'s address', $t[1]['text'] ?? '(none)');

// ── 6a (same sandbox: authorisation off) ──────────────────────────────────────
echo "\n6. The Starlink line\n";
$n0 = count($s->texts());
$setJob($s, 940, ['title' => 'Starlink Installation — Sandbox Customer']);
$s->fire('job.add', 'job', 940, 'isw-6a');
$t = $texts($s, CUST, $n0);
is_(count($t) === 1 && strpos($t[0]['text'], 'secure link') === false,
    'a Starlink installation with Customer Installation Authorisation off: no line about a secure link', $t[0]['text'] ?? '(none)');

// ── 9 (same sandbox) ──────────────────────────────────────────────────────────
echo "\n9. A failed send\n";
$n0 = count($s->texts());
$s->http('GET', "{$s->evo}/__test/fail_next?n=1");
$setJob($s, 950, ['title' => 'Fiber installation — Sandbox Customer']);
$s->fire('job.add', 'job', 950, 'isw-9a');
$q = $s->q("SELECT event, status FROM notification_queue WHERE event = 'ops_installation_scheduled'");
is_(count($texts($s, CUST, $n0)) === 0 && count($q) === 1, 'the send fails: in the failure queue, for a person to retry', json_encode($q));
$lines = $waLines($s, 950);
is_(count($lines) === 1 && strpos($lines[0], 'the send failed') !== false && strpos($lines[0], 'failure queue') !== false,
    'the webhook log says it failed and where it went', implode(' | ', $lines));
$s->fire('job.add', 'job', 950, 'isw-9b');
is_(count($texts($s, CUST, $n0)) === 0 && count($s->q("SELECT 1 FROM notification_queue WHERE event = 'ops_installation_scheduled'")) === 1,
    'a redelivery does not send it again, nor queue a second copy');

// ── 10 (same sandbox) ─────────────────────────────────────────────────────────
echo "\n10. tools/set_config.php\n";
[$rc, $out] = $s->run('tools/set_config.php', ['--key', FLAG, '--clear']);
$cfgFile = json_decode((string)@file_get_contents($s->data . '/kyc_config.json'), true) ?: [];
is_($rc === 0 && strpos($out, FLAG . ' cleared') !== false && !array_key_exists(FLAG, $cfgFile), '--clear removes it from the configuration file', $out);
[$rc, $out] = $s->run('tools/set_config.php', ['--key', FLAG, '--value', '1']);
$cfgFile = json_decode((string)@file_get_contents($s->data . '/kyc_config.json'), true) ?: [];
$row = json_decode((string)($s->q('SELECT data FROM kyc_config WHERE id = 0')[0]['data'] ?? ''), true) ?: [];
is_($rc === 0 && strpos($out, FLAG . ' = 1') !== false && ($cfgFile[FLAG] ?? null) === '1' && ($row[FLAG] ?? null) === '1',
    '--value 1 saves it in the file and in the store row the webhook reads', $out);
is_(strpos($out, 'Sent whether or not the customer has an e-mail address') !== false, 'the listing explains it');
$s->stop();

// ── 4 ─────────────────────────────────────────────────────────────────────────
echo "\n4. No e-mail address — the case that started this\n";
$s = $start('isw-mail', [FLAG => '1', 'customer_emails_enabled' => '1', 'customer_email_install_scheduled' => '1']);
$s->mailRelay(120);
$setJob($s, 920, ['title' => 'Starlink Installation — Nomail Customer', 'clientId' => 16, 'address' => 'Plot 16 Sandbox Lane, Kampala']);
$m0 = count($s->mails());
$s->fire('job.add', 'job', 920, 'isw-4a');
$t = $texts($s, NOMAIL);
is_(count($t) === 1 && $t[0]['text'] === $expected('Starlink Installation', 'Monday 5 October 2026', '9:00 AM', 'Plot 16 Sandbox Lane, Kampala', 'Sandbox Tech', 920, false, 'Nomail'),
    'a client with no e-mail address gets the WhatsApp', $t[0]['text'] ?? '(none)');
is_(count($s->mails()) === $m0, '…and no e-mail went: there was nowhere to send it');
$setJob($s, 921, ['title' => 'Fiber installation — Sandbox Customer']);
$s->fire('job.add', 'job', 921, 'isw-4b');
$mails = array_slice($s->mails(), $m0);
is_(count($texts($s, CUST)) === 1 && count($mails) === 1 && ($mails[0]['to'] ?? []) === ['customer@example.test'],
    'a client with an e-mail address gets both, the e-mail unchanged', json_encode(array_map(function ($m) { return $m['to'] ?? null; }, $mails)));
$s->stop();

// ── 6b ────────────────────────────────────────────────────────────────────────
$s = $start('isw-auth', [FLAG => '1', 'install_auth_enabled' => '1']);
$setJob($s, 941, ['title' => 'Starlink Installation — Sandbox Customer']);
$s->fire('job.add', 'job', 941, 'isw-6b');
$t = $texts($s, CUST);
is_(count($t) === 1 && $t[0]['text'] === $expected('Starlink Installation', 'Monday 5 October 2026', '9:00 AM', 'Plot 9 Sandbox Road, Kampala', 'Sandbox Tech', 941, true),
    'under Customer Installation Authorisation, a Starlink installation says that a separate secure link follows', $t[0]['text'] ?? '(none)');
$s->fire('job.add', 'job', 911, 'isw-6c');
$t = $texts($s, CUST);
is_(count($t) === 2 && strpos($t[1]['text'], "📋 *Starlink installation*\n") !== false && strpos($t[1]['text'], '⏰ Time: *9:00 AM – 12:00 PM*') !== false
    && strpos($t[1]['text'], 'secure link') !== false, 'the title compared case aside, as the guard compares it; a time window from uCRM\'s timeFrom and timeTo', $t[1]['text'] ?? '(none)');
$setJob($s, 942, ['title' => 'Fiber installation — Sandbox Customer']);
$s->fire('job.add', 'job', 942, 'isw-6d');
$t = $texts($s, CUST);
is_(count($t) === 3 && strpos($t[2]['text'], 'secure link') === false, 'a Fiber installation: no such line', $t[2]['text'] ?? '(none)');
$s->stop();

// ── 7 ─────────────────────────────────────────────────────────────────────────
echo "\n7. South Sudan\n";
$s = $start('isw-ss', [FLAG => '1', 'tenant_profile' => 'south-sudan', 'timezone' => 'Africa/Juba']);
$s->fire('job.add', 'job', 901, 'isw-7a');
is_(count($texts($s, CUST)) === 0 && !$claimed($s, 901) && $waLines($s, 901) === [], 'the switch on changes nothing: no WhatsApp, no claim, no line');
$s->stop();

// ── 8 ─────────────────────────────────────────────────────────────────────────
echo "\n8. The text\n";
require_once $root . '/lib/InstallScheduledWhatsApp.php';
$f = ['brand' => 'DishNet Africa', 'name' => 'Sandbox', 'job_id' => 901, 'job_type' => 'Fiber installation', 'date' => 'Monday 5 October 2026',
      'time' => '9:00 AM', 'location' => 'Plot 9 Sandbox Road, Kampala', 'technician' => 'Sandbox Tech', 'starlink_auth' => false, 'support_wa' => $SUPPORT_WA];
is_(InstallScheduledWhatsApp::text($f) === $expected('Fiber installation', 'Monday 5 October 2026', '9:00 AM', 'Plot 9 Sandbox Road, Kampala', 'Sandbox Tech', 901),
    'every line in its place');
$bare = InstallScheduledWhatsApp::text(['time' => '', 'location' => '', 'technician' => ''] + $f);
is_(strpos($bare, '⏰') === false && strpos($bare, '📍') === false && strpos($bare, '👷') === false && strpos($bare, '📅 Date: *Monday 5 October 2026*') !== false,
    'a value uCRM does not have gives no line at all');
$odd = InstallScheduledWhatsApp::text(['name' => "San*dbox\nInjected line", 'technician' => 'Sand_box *Tech*', 'location' => "Plot 9\n\nKampala"] + $f);
is_(strpos($odd, 'Dear Sandbox Injected line,') !== false && strpos($odd, '👷 Technician: *Sandbox Tech*') !== false
    && strpos($odd, '📍 Location: Plot 9 Kampala') !== false, 'emphasis marks and line breaks inside a value are taken out: a value cannot break the layout or add a line', $odd);
$cfg = ['tenant_profile' => 'uganda', 'timezone' => 'Africa/Kampala'];
$client = ['firstName' => 'Sandbox', 'lastName' => 'Customer', 'street1' => 'Plot 9', 'city' => 'Kampala', 'contacts' => [['phone' => '+256700000915']]];
$ff = InstallScheduledWhatsApp::fields(901, ['title' => 'Starlink Installation — Sandbox Customer', 'date' => '2026-10-07T06:00:00+0000', 'address' => ''], $client, '', $cfg, null);
is_($ff['date'] === 'Wednesday 7 October 2026' && $ff['time'] === '9:00 AM' && $ff['job_type'] === 'Starlink Installation' && $ff['location'] === 'Plot 9 Kampala',
    'a time uCRM gives in UTC is read in Kampala, as the technician\'s message reads it; the type is the title before " — "', json_encode($ff));
$ff = InstallScheduledWhatsApp::fields(901, ['title' => 'Fiber installation', 'date' => '2026-10-07T00:00:00+0300'], $client, '', $cfg, null);
is_($ff['time'] === '' && $ff['date'] === 'Wednesday 7 October 2026', 'midnight is a day with no time chosen: no Time line', json_encode($ff));
is_($ff['support_wa'] === $SUPPORT_WA && $ff['brand'] === 'DishNet Africa' && $ff['starlink_auth'] === false,
    'the brand and the support number are the Uganda profile\'s; no authorisation line without the switch');
is_(InstallScheduledWhatsApp::flagOn([]) === false && InstallScheduledWhatsApp::flagOn([FLAG => '1']) === true
    && InstallScheduledWhatsApp::flagOn([FLAG => 'no']) === false, 'absent means OFF');

// ── 11 ────────────────────────────────────────────────────────────────────────
if ($withMutants) {
    echo "\n11. Weakened copies of the code must each fail this test\n";
    $MUTANTS = [
        ['no switch: on whenever Uganda', [['lib/InstallScheduledWhatsApp.php', "        if (!self::flagOn(\$config)) return false;\n        if (!class_exists('StaffJobsGate'))",
            "        if (!class_exists('StaffJobsGate'))"]]],
        ['no Uganda gate: South Sudan too', [['lib/InstallScheduledWhatsApp.php', '        return StaffJobsGate::applies($config, $dataDir);', '        return true;']]],
        ['no claim: every delivery sends', [['lib/InstallScheduledWhatsApp.php', '        if (!CustomerEmailDispatcher::claimOnce($pdo, self::claimKey($jobId))) {', '        if (false) {']]],
        ['the claim before the number is checked', [['lib/InstallScheduledWhatsApp.php',
            "        \$phone = InstallAuth::clientPhone(\$client);\n        if (\$phone === '') return self::out('no_phone', 'not sent: the client has no phone number in uCRM');\n        \$pdo = (is_object(\$store) && method_exists(\$store, 'getPdo')) ? \$store->getPdo() : null;\n        if (!\$pdo instanceof \\PDO) return self::out('error', 'not sent: the plugin database is not available to record it');\n        if (!CustomerEmailDispatcher::claimOnce(\$pdo, self::claimKey(\$jobId))) {\n            return self::out('already_sent', 'not sent again: this job\\'s message was already sent, or tried once');\n        }",
            "        \$pdo = (is_object(\$store) && method_exists(\$store, 'getPdo')) ? \$store->getPdo() : null;\n        if (!\$pdo instanceof \\PDO) return self::out('error', 'not sent: the plugin database is not available to record it');\n        if (!CustomerEmailDispatcher::claimOnce(\$pdo, self::claimKey(\$jobId))) {\n            return self::out('already_sent', 'not sent again: this job\\'s message was already sent, or tried once');\n        }\n        \$phone = InstallAuth::clientPhone(\$client);\n        if (\$phone === '') return self::out('no_phone', 'not sent: the client has no phone number in uCRM');"]]],
        ['not wired into job.add', [['webhook.php', "            whInstallScheduledWhatsApp(\$jobId, is_array(\$job) ? \$job : [], is_array(\$client ?? null) ? \$client : [],",
            "            if (false) whInstallScheduledWhatsApp(\$jobId, is_array(\$job) ? \$job : [], is_array(\$client ?? null) ? \$client : [],"]]],
        ['only when the customer has no e-mail address', [['lib/InstallScheduledWhatsApp.php', "        if (\$phone === '') return self::out('no_phone', 'not sent: the client has no phone number in uCRM');",
            "        if (\$phone === '') return self::out('no_phone', 'not sent: the client has no phone number in uCRM');\n        if (InstallAuth::clientEmail(\$client) !== '') return self::out('off', 'the e-mail covers it');"]]],
        ['uCRM\'s "Technician" placeholder passed', [['webhook.php', "                \$assignedUserId ? trim((string)((\$user['firstName'] ?? '') . ' ' . (\$user['lastName'] ?? ''))) : '',",
            "                (string)(\$techName ?? ''),"]]],
        ['from the accounts number', [['lib/InstallScheduledWhatsApp.php', "        \$notify->sendVia('support', \$phone,", "        \$notify->sendVia('accounts', \$phone,"]]],
        ['the Starlink line on every installation', [['lib/InstallScheduledWhatsApp.php', "            'starlink_auth' => InstallAuth::enabled(\$config, \$dataDir) && InstallAuth::inScope(\$job, \$config),",
            "            'starlink_auth' => true,"]]],
        ['the time in uCRM\'s offset, not Kampala\'s', [['lib/InstallScheduledWhatsApp.php', "            return (new \\DateTime(\$iso))->setTimezone(\$tz);", "            return new \\DateTime(\$iso);"]]],
        ['values not cleaned', [['lib/InstallScheduledWhatsApp.php', "        \$v = str_replace(['*', '_', '~', '`'], '', \$v);\n", '']]],
    ];
    foreach ($MUTANTS as [$label, $edits]) {
        [$tmp, $n] = sj_weakened_copy($root, $edits[0][0], $edits[0][1], $edits[0][2]);
        $bad = $n !== 1 ? "{$edits[0][0]}: found {$n} times" : '';
        foreach (array_slice($edits, 1) as [$rel, $old, $new]) {
            $src = (string)@file_get_contents($tmp . '/' . $rel);
            $k = substr_count($src, $old);
            if ($k !== 1) { $bad .= " {$rel}: found {$k} times"; continue; }
            file_put_contents($tmp . '/' . $rel, str_replace($old, $new, $src));
        }
        if ($bad !== '') { is_(false, "weakened copy \"{$label}\": each anchor occurs once", $bad); exec('rm -rf ' . escapeshellarg($tmp)); continue; }
        $out = [];
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --root=' . escapeshellarg($tmp) . ' --no-mutants 2>&1', $out, $rc);
        $fails = array_values(array_filter($out, function ($l) { return strpos($l, '  FAIL ') === 0; }));
        is_($rc !== 0 && $fails !== [], "caught: {$label}", 'exit ' . $rc . ', ' . count($fails) . ' failure(s)');
        if ($fails) echo '         first: ' . trim(substr($fails[0], 7, 110)) . "\n";
        exec('rm -rf ' . escapeshellarg($tmp));
    }
}

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
