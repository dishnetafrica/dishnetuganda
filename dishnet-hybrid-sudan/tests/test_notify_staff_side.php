<?php
declare(strict_types=1);
/**
 * test_notify_staff_side.php — 5.18.54, docs/46 rows 24-29 (S-2 … S-7): the staff side of the notifications.
 *
 * Through the real plugin under php -S (tests/fixtures/staff_jobs_sandbox.php), with a fake uCRM and the fake
 * Evolution, as the accounts that use it:
 *
 *   1. S-2: the support leaders' "Job Accepted" copy goes once per job and assignment, as message 2 does. A second tap,
 *      or an Accept of a job already in progress, tells them nothing new; a new engineer's Accept does.
 *   2. S-3: the morning jobs brief is built and sent, where before it died on its first line. Each person gets their OWN
 *      jobs — uCRM ignores the assignee filter the old query relied on, so fixed as it stood it would have handed
 *      everyone the whole list — only through a verified uCRM link, once a day, and the administrator is told once about
 *      staff with no link.
 *   3. S-4: an administrator alert with no number leaves one line a day in the plugin log, where before it vanished;
 *      an operations alert that went, or failed, leaves a Message Log row like any other send.
 *   4. S-5: the alert-number field suggests the tenant's own number format.
 *   5. S-6: the Workbench bulk send counts what WhatsApp accepted: a refused message is counted as failed.
 *   6. S-7: the help page's WhatsApp answers say what this install does, and name no screen that does not exist.
 *   7. South Sudan: 5.18.53 unchanged in each (docs/46 §E) — its brief still dies on its first line.
 *   8. Weakened copies, each caught (skipped with --no-mutants, for a quick run while editing).
 *
 * What this proves is what the plugin hands to WhatsApp, to its logs and to its screens, never delivery to a phone.
 * Documents the server writes are read from its database directly: this process's store caches. Every person, number
 * and job is fictitious; nothing leaves the machine.
 */
$root = dirname(__DIR__);
$withMutants = !in_array('--no-mutants', $argv, true);
require_once __DIR__ . '/fixtures/staff_jobs_scenario.php';

$pass = 0; $fail = 0;
function is_(bool $c, string $m, string $d = ''): void {
    global $pass, $fail;
    if ($c) { $pass++; echo "  ok   {$m}\n"; }
    else    { $fail++; echo "  FAIL {$m}" . ($d !== '' ? "\n       {$d}" : '') . "\n"; }
}

const TECH     = '256700000111';   // Sandbox Tech, uCRM user 1099, verified link
const TECH2    = '256700000114';   // Sandbox Tech Two, uCRM user 1100, verified link
const LEAD     = '256700000112';   // Sandbox Leader: support leader, no verified link
const ADMIN    = '256700000110';   // Sandbox Admin, uCRM user 1000: an administrator takes jobs too
const ACCT     = '256700000113';   // Sandbox Accountant: takes no jobs
const ALERT_NO = '256700000199';   // the administrator alert number (whatsapp_admin_phone)
const OPS_NO   = '256700000198';   // the operations alert number (alert_whatsapp)

/** A sandbox with the accounts every case uses: SjScenario's four, and a second linked engineer. */
$start = function (string $tree, string $tenant, string $tag): SjSandbox {
    $base = $tenant === 'uganda' ? ['tenant_profile' => 'uganda', 'timezone' => 'Africa/Kampala'] : ['timezone' => 'Africa/Juba'];
    $s = SjSandbox::start($tree, $base + ['whatsapp_admin_phone' => '+' . ALERT_NO], $tag);
    file_put_contents($s->plug . '/ucrm.json', json_encode(['pluginDataDir' => $s->data, 'pluginPublicUrl' => $s->base]));
    $crm = SjScenario::crm();
    $crm['users']['1100'] = ['id' => 1100, 'username' => 'sb-tech2', 'firstName' => 'Sandbox', 'lastName' => 'Tech Two',
                             'email' => 'tech2@example.test', 'isActive' => true];
    $crm['clients']['16'] = ['id' => 16, 'firstName' => 'Second', 'lastName' => 'Customer', 'isLead' => false,
                             'contacts' => [['email' => 'second@example.test', 'phone' => '+256700000916', 'isBilling' => true]]];
    $s->seedCrm($crm);
    SjScenario::staff($s);
    $s->staff('tech2', ['name' => 'Sandbox Tech Two', 'email' => 'tech2@example.test', 'role' => 'support', 'phone' => '+256700000114',
        'ucrm_user_id' => 1100, 'ucrm_link' => ['user_id' => 1100, 'email' => 'tech2@example.test', 'verified_at' => '2026-09-27T00:00:00Z', 'verified_by' => 1]]);
    return $s;
};
$textsTo = function (SjSandbox $s, string $to, int $from = 0): array {
    return array_values(array_filter(array_slice($s->texts(), $from), function ($t) use ($to) { return $t['number'] === $to; }));
};
$has = function (array $texts, string $needle): bool {
    foreach ($texts as $t) if (strpos((string)$t['text'], $needle) !== false) return true;
    return false;
};

// ── The cases, as functions of the sandbox, so a weakened copy runs the very same ones ──────────────────────────────

/** S-2: job 901 (SjScenario's, Open, the technician's) accepted four times. The leaders' copies each time. */
$acceptCase = function (SjSandbox $s) use ($textsTo): array {
    $setJob = function (array $fields) use ($s): void {
        $jobs = (array)($s->crmDump()['jobs'] ?? []);
        $jobs['901'] = array_merge((array)($jobs['901'] ?? []), $fields);
        $s->seedCrm(['jobs' => $jobs]);
    };
    $copies = function (int $from) use ($s, $textsTo): array {
        return array_values(array_filter($textsTo($s, LEAD, $from), function ($t) { return strpos($t['text'], '🔔 *Job Accepted*') !== false; }));
    };
    $accept = function (string $who) use ($s): array {
        return $s->api($who, 'POST', 'scheduling_job_update', ['job_id' => 901, 'status' => 'open', 'notify_accept' => 1]);
    };
    $o = [];
    $n = count($s->texts()); $o['r1'] = $accept('tech');  $o['first'] = $copies($n);
    $n = count($s->texts()); $o['r2'] = $accept('tech');  $o['again'] = $copies($n);        // the job is in progress now
    $setJob(['status' => 0]);
    $n = count($s->texts()); $o['r3'] = $accept('tech');  $o['reopened'] = $copies($n);     // set back to Open, same engineer
    $setJob(['status' => 0, 'assignedUserId' => 1100]);
    $n = count($s->texts()); $o['r4'] = $accept('tech2'); $o['new_engineer'] = $copies($n); // given to another engineer
    return $o;
};

/** S-3: the morning brief, run twice, against jobs dated from today (Kampala) and a leader with an unverified id. */
$briefCase = function (SjSandbox $s) use ($textsTo): array {
    $tz  = new DateTimeZone('Africa/Kampala');
    $day = function (string $rel) use ($tz): string { return (new DateTime($rel, $tz))->format('Y-m-d'); };
    $job = function (int $id, string $title, string $date, ?int $who, int $status): array {
        return ['id' => $id, 'title' => $title, 'description' => '', 'clientId' => 15, 'assignedUserId' => $who,
                'date' => $date, 'duration' => 60, 'status' => $status, 'address' => null];
    };
    [$today, $yday, $tmrw] = [$day('now'), $day('-1 day'), $day('+1 day')];
    // uCRM's whole job list, and only these: the fake answers every query with all of it, as uCRM answers an assignee
    // filter it ignores.
    $s->seedCrm(['jobs' => [
        '3101' => $job(3101, 'BRIEF-T1-TODAY-PENDING', "{$today}T09:00:00+0300", 1099, 0),
        '3102' => $job(3102, 'BRIEF-T1-TODAY-OPEN',    "{$today}T14:00:00+0300", 1099, 1),
        '3103' => $job(3103, 'BRIEF-T1-OVERDUE',       "{$yday}T10:00:00+0300",  1099, 0),
        '3104' => $job(3104, 'BRIEF-T1-TOMORROW',      "{$tmrw}T10:00:00+0300",  1099, 0),
        '3105' => $job(3105, 'BRIEF-T2-TODAY',         "{$today}T11:00:00+0300", 1100, 0),
        '3106' => $job(3106, 'BRIEF-ADMIN-TODAY',      "{$today}T12:00:00+0300", 1000, 1),
        '3107' => $job(3107, 'BRIEF-NOBODY-TODAY',     "{$today}T13:00:00+0300", null, 0),
    ]]);
    // A uCRM id typed into the leader's account, never verified (docs/44 M7): it must match nobody.
    $s->update($s->ids['lead'], ['ucrm_user_id' => 1099]);

    // Held back first: staff_jobs_brief = 0, as tools/set_config.php writes it, sends nothing and claims nothing.
    $setCfg = function (array $cfg) use ($s): void {
        $s->store()->save('kyc_config.json', $cfg);
        file_put_contents($s->data . '/kyc_config.json', json_encode($cfg));
    };
    $setCfg($s->cfg + ['staff_jobs_brief' => '0']);
    $n0 = count($s->texts());
    [$rcOff, $outOff] = $s->run('cron/staff_jobs_summary.php');
    $off = array_slice($s->texts(), $n0);
    $setCfg($s->cfg);

    $n = count($s->texts());
    $q0 = count($s->crmReqs('GET', '#^/scheduling/jobs$#'));
    [$rc, $out] = $s->run('cron/staff_jobs_summary.php');
    $o = ['rc' => $rc, 'out' => $out, 'today' => $today, 'from' => $day('-7 days'), 'rc_off' => $rcOff, 'out_off' => $outOff, 'off' => $off,
          'reqs' => array_slice($s->crmReqs('GET', '#^/scheduling/jobs$#'), $q0)];
    foreach (['tech' => TECH, 'tech2' => TECH2, 'admin' => ADMIN, 'lead' => LEAD, 'acct' => ACCT, 'alert' => ALERT_NO] as $k => $no) {
        $o[$k] = $textsTo($s, $no, $n);
    }
    try { $o['log'] = $s->q("SELECT phone, success FROM notification_audit_log WHERE event = 'staff_jobs_summary' ORDER BY id"); }
    catch (\Throwable $e) { $o['log'] = []; }
    $n2 = count($s->texts());
    [$o['rc2'], $o['out2']] = $s->run('cron/staff_jobs_summary.php');
    $o['second'] = array_slice($s->texts(), $n2);
    return $o;
};

/** S-4: administrator and operations alerts, raised by the probe inside the sandbox, with and without a number. */
$alertCase = function (SjSandbox $s) use ($textsTo): array {
    $probe = function (string $what, string $arg, array $over) use ($s): string {
        $cmd = sprintf('cd %s && DN_DATA_DIR=%s DN_VAULT_FILE=%s PROBE_CFG=%s %s %s %s %s %s 2>&1', escapeshellarg($s->plug),
            escapeshellarg($s->data), escapeshellarg($s->vault), escapeshellarg((string)json_encode($over)), escapeshellarg(PHP_BINARY),
            escapeshellarg(__DIR__ . '/fixtures/notify_alert_probe.php'), escapeshellarg($s->plug), escapeshellarg($what), escapeshellarg($arg));
        $out = [];
        exec($cmd, $out);
        return implode("\n", $out);
    };
    $lines = function () use ($s): array {
        return array_values(array_filter(explode("\n", (string)@file_get_contents($s->data . '/plugin.log')),
            function ($l) { return strpos($l, '[alerts]') !== false; }));
    };
    $rows = function () use ($s): array {
        try { return $s->q("SELECT event, phone, success, http_code, error FROM notification_audit_log WHERE event LIKE 'ops_alert_%' ORDER BY id"); }
        catch (\Throwable $e) { return []; }
    };
    $o = [];
    $l0 = count($lines()); $n = count($s->texts());
    $o['admin_none'] = [$probe('admin', 'probe_event', ['whatsapp_admin_phone' => '']), $probe('admin', 'probe_event', ['whatsapp_admin_phone' => ''])];
    $o['admin_lines'] = array_slice($lines(), $l0);
    $o['admin_none_texts'] = count($s->texts()) - $n;
    $n = count($s->texts());
    $o['admin_set'] = $probe('admin', 'probe_event', []);                                    // the sandbox's own number
    $o['admin_set_texts'] = $textsTo($s, ALERT_NO, $n);
    $l0 = count($lines());
    $o['ops_none'] = [$probe('alert', 'watchdog:conv:7', ['alert_whatsapp' => '']), $probe('alert', 'watchdog:conv:7', ['alert_whatsapp' => ''])];
    $o['ops_lines'] = array_slice($lines(), $l0);
    $r0 = count($rows()); $n = count($s->texts());
    $o['ops_sent'] = $probe('alert', 'watchdog:conv:8', ['alert_whatsapp' => '+' . OPS_NO]);
    $o['ops_sent_texts'] = $textsTo($s, OPS_NO, $n);
    $s->http('GET', "{$s->evo}/__test/fail_next?n=1");
    $o['ops_failed'] = $probe('alert', 'watchdog:conv:9', ['alert_whatsapp' => '+' . OPS_NO]);
    $o['ops_rows'] = array_slice($rows(), $r0);
    return $o;
};

/** S-5 and S-7: the two screens, as the administrator. */
$pagesCase = function (SjSandbox $s): array {
    $s->login('admin', 'admin@example.test', 'sj-password-1');
    return ['setup' => $s->page('admin', 'page=dashboard&tab=wa_ai_setup'), 'faq' => $s->page('admin', 'page=dashboard&tab=faq')];
};

/** S-6: two overdue invoices sent from the Workbench; the fake WhatsApp refuses the first. */
$bulkCase = function (SjSandbox $s): array {
    $due = (new DateTime('-20 days', new DateTimeZone('Africa/Kampala')))->format('Y-m-d') . 'T00:00:00+0300';
    $inv = function (int $id, string $num, int $client) use ($due): array {
        return ['id' => $id, 'number' => $num, 'clientId' => $client, 'status' => 1, 'total' => 50000.0, 'amountToPay' => 50000.0,
                'currencyCode' => 'UGX', 'dueDate' => $due];
    };
    $s->store()->save('ucrm_invoices_cache.json', [$inv(6601, 'INV-S6-1', 15), $inv(6602, 'INV-S6-2', 16)]);
    $s->http('GET', "{$s->evo}/__test/fail_next?n=1");
    $n = count($s->texts());
    $r = $s->api('admin', 'POST', 'owb_bulk_send', ['invoice_numbers' => ['INV-S6-1', 'INV-S6-2'], 'channels' => ['whatsapp'], 'throttle_ms' => 0]);
    $id = basename((string)($r[2]['data']['job_id'] ?? ''));
    $job = [];
    for ($i = 0; $i < 300 && $id !== ''; $i++) {
        $job = json_decode((string)@file_get_contents($s->data . '/owb_bulk_send_jobs/' . $id . '.json'), true) ?: [];
        if (($job['running'] ?? true) === false) break;
        usleep(200000);
    }
    $customers = array_values(array_filter(array_slice($s->texts(), $n), function ($t) {
        return in_array($t['number'], ['256700000915', '256700000916'], true);
    }));
    return ['r' => $r, 'job' => $job, 'customers' => $customers];
};

// ══════════════════════════════════════════════════════════════════════════════
echo "\n1. Uganda — S-2: the leaders hear of an Accept once per job and assignment\n";
$u = $start($root, 'uganda', 'nss');
$a = $acceptCase($u);
$wantLead = "🔔 *Job Accepted*\n\n*Sandbox Tech* has accepted:\n*Fiber installation*\n📅 Mon 05 Oct at 09:00\n\nJob #901 status → *In Progress*\n— DishNET NOC";
is_((int)$a['r1'][0] === 200 && count($a['first']) === 1 && $a['first'][0]['text'] === $wantLead,
    'the first Accept: one copy to the support leader, its text unchanged', json_encode([$a['r1'][0], $a['first']], JSON_UNESCAPED_UNICODE));
is_((int)$a['r2'][0] === 200 && $a['again'] === [], 'Accept again, the job already in progress: no second copy', json_encode($a['again'], JSON_UNESCAPED_UNICODE));
is_($a['reopened'] === [], 'set back to Open and accepted again by the same engineer: still none — the leaders know', json_encode($a['reopened'], JSON_UNESCAPED_UNICODE));
is_((int)$a['r4'][0] === 200 && count($a['new_engineer']) === 1 && strpos($a['new_engineer'][0]['text'], '*Sandbox Tech Two* has accepted') !== false,
    'given to another engineer, who accepts: the leaders are told, once', json_encode($a['new_engineer'], JSON_UNESCAPED_UNICODE));

echo "\n2. Uganda — S-3: the morning brief, each person their own jobs\n";
$b = $briefCase($u);
is_($b['rc_off'] === 0 && $b['off'] === [] && strpos($b['out_off'], 'Switched off (staff_jobs_brief)') !== false,
    'held back with staff_jobs_brief = 0: nothing sent, and the run says why', substr($b['out_off'], 0, 300));
is_($b['rc'] === 0 && stripos($b['out'], 'TypeError') === false, 'the brief runs: no error on its first line', $b['rc'] . ' ' . substr($b['out'], 0, 300));
$tb = $b['tech'][0]['text'] ?? '';
is_(count($b['tech']) === 1 && strpos($tb, 'BRIEF-T1-TODAY-PENDING') !== false && strpos($tb, 'BRIEF-T1-TODAY-OPEN') !== false
    && strpos($tb, 'BRIEF-T1-OVERDUE') !== false && strpos($tb, '📦 Total Today: 2') !== false && strpos($tb, '⚠️ Overdue (not closed): 1') !== false,
    'the technician: one brief, with their two jobs today and the one overdue', json_encode($b['tech'], JSON_UNESCAPED_UNICODE));
is_(strpos($tb, 'BRIEF-T2') === false && strpos($tb, 'BRIEF-ADMIN') === false && strpos($tb, 'BRIEF-NOBODY') === false,
    'and nobody else\'s: uCRM answers the whole list, the brief keeps theirs');
is_(strpos($tb, 'BRIEF-T1-TOMORROW') === false, 'tomorrow\'s job is not today\'s');
is_(strpos($tb, '🔗 ' . $u->base . "?page=dashboard&tab=scheduling\n") !== false, 'its link opens My Jobs (with page=dashboard, not the sign-in page)');
is_(count($b['tech2']) === 1 && strpos($b['tech2'][0]['text'], 'BRIEF-T2-TODAY') !== false && strpos($b['tech2'][0]['text'], 'BRIEF-T1') === false,
    'the second engineer: their one job, no one else\'s', json_encode($b['tech2'], JSON_UNESCAPED_UNICODE));
is_(count($b['admin']) === 1 && strpos($b['admin'][0]['text'], 'BRIEF-ADMIN-TODAY') !== false && strpos($b['admin'][0]['text'], 'BRIEF-T1') === false,
    'the administrator, who takes jobs too: theirs only');
is_($b['lead'] === [], 'the leader, whose uCRM id was typed and never verified (M7): no brief — least of all the technician\'s jobs',
    json_encode($b['lead'], JSON_UNESCAPED_UNICODE));
is_($b['acct'] === [], 'the accountant, who takes no jobs: nothing');
is_(!array_filter(array_merge($b['tech'], $b['tech2'], $b['admin']), function ($t) { return $t['instance'] !== 'sj-support'; }),
    'every brief through the support number, as the job messages go');
is_(count($b['alert']) === 1 && strpos($b['alert'][0]['text'], 'Mapping Alert') !== false && strpos($b['alert'][0]['text'], 'Sandbox Leader') !== false
    && strpos($b['alert'][0]['text'], 'no verified uCRM user link') !== false,
    'the administrator is told, once, who has no verified link', json_encode($b['alert'], JSON_UNESCAPED_UNICODE));
$q = []; parse_str((string)($b['reqs'][0]['query'] ?? ''), $q);
is_(count($b['reqs']) === 1 && ($q['limit'] ?? '') === '500' && ($q['statuses'] ?? null) === ['0', '1'] && ($q['dateFrom'] ?? '') === $b['from']
    && !isset($q['assigneeId']), 'uCRM is read once for everybody: open and pending jobs from a week ago, no assignee filter',
    json_encode($b['reqs']));
is_(count($b['log']) === 3 && !array_filter($b['log'], function ($r) { return (int)$r['success'] !== 1; }),
    'the Message Log has the three briefs, each accepted', json_encode($b['log']));
is_(strpos($b['out'], 'Brief accepted by WhatsApp for Sandbox Tech') !== false && strpos($b['out'], '256700000') === false,
    'the run\'s log says what WhatsApp answered, and carries no phone number', substr($b['out'], 0, 600));
is_($b['rc2'] === 0 && $b['second'] === [] && substr_count($b['out2'], 'today\'s brief was already sent') === 3,
    'run again the same day: no second brief, no second alert', json_encode($b['second'], JSON_UNESCAPED_UNICODE));

echo "\n3. Uganda — S-4: an alert leaves a trace\n";
$al = $alertCase($u);
is_(count($al['admin_lines']) === 1 && strpos($al['admin_lines'][0], 'probe_event') !== false
    && strpos($al['admin_lines'][0], 'no administrator number is set (whatsapp_admin_phone)') !== false,
    'an administrator alert with no number: one line in the plugin log, naming the alert and the setting — twice raised, once said',
    json_encode($al['admin_lines']));
is_($al['admin_none_texts'] === 0 && !preg_match('/2567\d{8}/', implode("\n", $al['admin_lines'])), 'nothing sent, and the line carries no number');
is_(count($al['admin_set_texts']) === 1, 'the same alert with the number set goes, as before (control)');
is_(strpos($al['ops_none'][0], '"no_alert_number"') !== false && count($al['ops_lines']) === 1
    && strpos($al['ops_lines'][0], 'ops_alert_watchdog') !== false && strpos($al['ops_lines'][0], 'alert_whatsapp') !== false,
    'an operations alert with no number: one line too, naming alert_whatsapp', json_encode([$al['ops_none'], $al['ops_lines']]));
is_(strpos($al['ops_sent'], '"sent":true') !== false && count($al['ops_sent_texts']) === 1 && $al['ops_sent_texts'][0]['instance'] === 'sj-sales',
    'with a number, the operations alert goes through the sales number, as before', $al['ops_sent']);
$ok = $al['ops_rows'][0] ?? []; $ko = $al['ops_rows'][1] ?? [];
is_(count($al['ops_rows']) === 2 && $ok['event'] === 'ops_alert_watchdog' && $ok['phone'] === OPS_NO && (int)$ok['success'] === 1 && (int)$ok['http_code'] === 200,
    'the alert that went is a Message Log row: sent, meaning WhatsApp took it', json_encode($al['ops_rows']));
is_((int)($ko['success'] ?? 1) === 0 && (int)($ko['http_code'] ?? 0) === 500 && strpos((string)($ko['error'] ?? ''), 'FAKE-EVO-FAILURE') !== false
    && strpos($al['ops_failed'], 'send_failed') !== false, 'the alert WhatsApp refused is a row too: failed, with the reason');

echo "\n4. Uganda — S-5 and S-7: the screens say what this install does\n";
$p = $pagesCase($u);
is_(strpos($p['setup'], 'name="alert_whatsapp" style="min-width:200px" placeholder="+256 7XX XXX XXX"') !== false
    && strpos($p['setup'], '+249XXXXXXXXX') === false, 'the alert-number field suggests a Uganda number, not Sudan\'s +249');
is_(strpos($p['faq'], 'Message Log') !== false && strpos($p['faq'], 'Failed Queue') !== false && strpos($p['faq'], 'Settings → Notifications') !== false,
    'the help page sends staff to the Message Log and the Failed Queue, and to uCRM for its e-mails');
is_(strpos($p['faq'], 'Notify tab') === false && strpos($p['faq'], 'wa.dishnetafrica.com') === false && strpos($p['faq'], 'our plugin never touches these') === false,
    'and no longer to a Notify tab that does not exist, a server this install does not use, or "the plugin never sends" what it sends');
is_(strpos($p['faq'], 'whether it reached the phone is not measured') !== false, '"sent" is explained as WhatsApp taking it, not the phone showing it');

echo "\n5. Uganda — S-6: the Workbench counts what WhatsApp accepted\n";
$w = $bulkCase($u);
is_((int)$w['r'][0] === 200 && ($w['job']['running'] ?? null) === false, 'the bulk send ran to its end', json_encode([$w['r'][0], $w['job']['running'] ?? null]));
is_(($w['job']['sent_wa'] ?? null) === 1 && count($w['customers']) === 1, 'one WhatsApp went, and one is counted', json_encode([$w['job']['sent_wa'] ?? null, count($w['customers'])]));
$errs = array_values(array_filter((array)($w['job']['errors'] ?? []), function ($e) { return ($e['invoice_number'] ?? '') === 'INV-S6-1'; }));
is_(count($errs) === 1 && strpos((string)$errs[0]['error'], 'wa_failed: ') === 0 && strpos((string)$errs[0]['error'], 'FAKE-EVO-FAILURE') !== false,
    'the refused one is counted as failed, with WhatsApp\'s reason', json_encode($w['job']['errors'] ?? null));
$u->stop();

// ══════════════════════════════════════════════════════════════════════════════
echo "\n6. South Sudan: 5.18.53 unchanged (docs/46 §E)\n";
$ss = $start($root, 'south-sudan', 'nss-ss');
$sa = $acceptCase($ss);
is_(count($sa['first']) === 1 && count($sa['again']) === 1 && count($sa['reopened']) === 1 && count($sa['new_engineer']) === 1,
    'S-2 there awaits approval: the leaders\' copy on every Accept, as before',
    json_encode(array_map('count', [$sa['first'], $sa['again'], $sa['reopened'], $sa['new_engineer']])));
$sb = $briefCase($ss);
is_($sb['rc'] !== 0 && strpos($sb['out'], 'TypeError') !== false && $sb['tech'] === [] && $sb['alert'] === [],
    'the brief there still dies on its first line: nothing sent (a fix there must also filter by person — §E)', substr($sb['out'], 0, 300));
$sal = $alertCase($ss);
is_($sal['admin_lines'] === [] && $sal['ops_lines'] === [] && $sal['ops_rows'] === [], 'alerts with no number still leave nothing; alerts sent leave no Message Log row',
    json_encode([$sal['admin_lines'], $sal['ops_lines'], $sal['ops_rows']]));
is_(count($sal['ops_sent_texts']) === 1 && count($sal['admin_set_texts']) === 1, 'and still go when a number is set (control)');
$sp = $pagesCase($ss);
is_(strpos($sp['setup'], 'placeholder="+249XXXXXXXXX"') !== false && strpos($sp['faq'], 'Notify tab') !== false, 'the screens as they were');
$sw = $bulkCase($ss);
is_(($sw['job']['sent_wa'] ?? null) === 2 && count($sw['customers']) === 1, 'the Workbench there still counts the refused WhatsApp as sent',
    json_encode([$sw['job']['sent_wa'] ?? null, count($sw['customers'])]));
$ss->stop();

// ══════════════════════════════════════════════════════════════════════════════
echo "\n7. Weakened copies, each caught\n";
/** A weakened copy with several changes in one file, each anchor found exactly once. */
$weaken = function (string $rel, array $pairs) use ($root): array {
    [$old0, $new0] = array_shift($pairs);
    [$tree, $n] = sj_weakened_copy($root, $rel, $old0, $new0);
    foreach ($pairs as [$old, $new]) {
        $src = (string)file_get_contents($tree . '/' . $rel);
        if (substr_count($src, $old) !== 1) { $n = 0; break; }
        file_put_contents($tree . '/' . $rel, str_replace($old, $new, $src));
    }
    return [$tree, $n];
};
$mutants = [
    'the leaders told of every Accept (S-2)' => ['includes/api/api_scheduling.php', [
        ["\$_s2Quiet = ((\$_sjAccepted['outcome'] ?? '') === 'no_change')\n                             || !\$notify->dedupMark(\"JOBACC{\$jobId}:{\$_s2Assignee}\");",
         "\$_s2Quiet = false;"]],
        'accept', function (array $x) { return count($x['again']) > 0; }, 'a second Accept sent a second copy'],
    'the claim forgets which engineer (S-2)' => ['includes/api/api_scheduling.php', [
        ['!$notify->dedupMark("JOBACC{$jobId}:{$_s2Assignee}")', '!$notify->dedupMark("JOBACC{$jobId}")']],
        'accept', function (array $x) { return count($x['first']) === 1 && (int)$x['r4'][0] === 200 && count($x['new_engineer']) === 0; },
        'a new engineer\'s Accept went untold'],
    'the brief hands each person everyone\'s jobs (S-3)' => ['cron/staff_jobs_summary.php', [
        ['$jobs = array_values(array_filter($_sbJobs, fn($j) => (int)($j[\'assignedUserId\'] ?? 0) === $ucrmUserId));', '$jobs = $_sbJobs;']],
        'brief', function (array $x) { return strpos($x['tech'][0]['text'] ?? '', 'BRIEF-T2-TODAY') !== false; }, 'the technician was sent another engineer\'s job'],
    'the brief trusts a typed uCRM id (S-3, M7)' => ['cron/staff_jobs_summary.php', [
        ['$ucrmUserId  = $_sbUg ? StaffDirectory::linkedUcrmUser($person) : (int)($person[\'ucrm_user_id\'] ?? 0);',
         '$ucrmUserId  = (int)($person[\'ucrm_user_id\'] ?? 0);']],
        'brief', function (array $x) { return $x['lead'] !== []; }, 'the leader was sent the technician\'s jobs'],
    'the brief twice a day (S-3)' => ['cron/staff_jobs_summary.php', [
        ['if (!$notify->dedupMark(\'STAFFBRIEF:\' . ((int)($person[\'id\'] ?? 0) ?: $phone) . \':\' . $todayStr)) {', 'if (false) {']],
        'brief', function (array $x) { return $x['second'] !== []; }, 'the second run sent again'],
    'the brief cannot be held back (S-3)' => ['cron/staff_jobs_summary.php', [
        ["    if (\$_sbSwitch !== '' && \$_sbSwitch !== null && !filter_var(\$_sbSwitch, FILTER_VALIDATE_BOOLEAN)) {", "    if (false) {"]],
        'brief', function (array $x) { return $x['off'] !== [] && $x['second'] === []; }, 'switched off, it still went'],
    'the brief\'s first-line crash back (S-3)' => ['cron/staff_jobs_summary.php', [
        ['$crm = $_sbUg ? CrmApiClient::fromUcrm(dirname(__DIR__), $config) : new CrmApiClient($config);', '$crm = new CrmApiClient($config);']],
        'brief', function (array $x) { return $x['tech'] === [] && strpos($x['out'], 'TypeError') !== false; }, 'the TypeError again, no brief'],
    'an alert with no number vanishes again (S-4)' => ['lib/NotificationService.php', [
        ['if (empty($this->adminPhone)) { $this->noAdminNumber($event); return; }', 'if (empty($this->adminPhone)) return;']],
        'alert', function (array $x) { return $x['admin_lines'] === [] && count($x['admin_set_texts']) === 1; }, 'no line was written'],
    'the missing-number line on every alert (S-4)' => ['lib/NotificationService.php', [
        ["            if (!\$this->dedupMark('NOADMIN:' . (\$event !== '' ? \$event : 'alert') . ':' . date('Y-m-d'))) return;\n", '']],
        'alert', function (array $x) { return count($x['admin_lines']) > 1; }, 'two alerts, two lines'],
    'operations alerts leave no Message Log row (S-4)' => ['lib/AlertService.php', [
        ['            $ns->logSend(\'sales\', $event, $to, $text, $ok, $http, $why);', '']],
        'alert', function (array $x) { return $x['ops_rows'] === [] && count($x['ops_sent_texts']) === 1; }, 'the alert went and left no row'],
    'Sudan\'s example number on Uganda (S-5)' => ['tabs/engage/wa_ai_setup.php', [
        ['            $_s5Ph = TenantProfile::current($_wCfg, $_wData ?? null)->phoneExample() ?: $_s5Ph;', '']],
        'pages', function (array $x) { return strpos($x['setup'], 'placeholder="+249XXXXXXXXX"') !== false; }, 'the field says +249'],
    'a refused WhatsApp counted as sent (S-6)' => ['includes/api/api_crm_misc.php', [
        ['                    if (!empty($_s6[\'success\'])) {', '                    if (true) {']],
        'bulk', function (array $x) { return ($x['job']['sent_wa'] ?? null) === 2; }, 'two counted, one went'],
    'the old help answers on Uganda (S-7)' => ['tabs/help/faq.php', [
        ['    if (NotifyGate::applies(NotifyGate::STAFF_SIDE, is_array($config ?? null) ? $config : [], $dataDir ?? null)): ?>', '    if (false): ?>']],
        'pages', function (array $x) { return strpos($x['faq'], 'Notify tab') !== false; }, 'the page names the Notify tab'],
];
// Each predicate names the defect itself, with a control where the defect is an absence, so a copy that merely crashed
// is never counted as caught.
$cases = ['accept' => $acceptCase, 'brief' => $briefCase, 'alert' => $alertCase, 'pages' => $pagesCase, 'bulk' => $bulkCase];
foreach ($withMutants ? $mutants : [] as $name => [$rel, $pairs, $case, $caught, $why]) {
    [$tree, $n] = $weaken($rel, $pairs);
    if ($n !== 1) { is_(false, "caught: {$name}", "an anchor was not found exactly once in {$rel}"); exec('rm -rf ' . escapeshellarg($tree)); continue; }
    $wk = $start($tree, 'uganda', 'nss-wk');
    $x = $cases[$case]($wk);
    $c = (bool)$caught($x);
    is_($c, "caught: {$name}" . ($c ? " ({$why})" : ''));
    $wk->stop();
    exec('rm -rf ' . escapeshellarg($tree));
}

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail ? 1 : 0);
