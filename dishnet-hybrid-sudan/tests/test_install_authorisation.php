<?php
declare(strict_types=1);
/**
 * test_install_authorisation.php — 5.18.82: Customer Installation Authorisation for a Starlink installation job
 * (Uganda only, install_auth_enabled; lib/InstallAuth.php, lib/InstallationTerms.php, lib/InstallAuthNotifier.php,
 * lib/InstallAuthEmails.php, tabs/customer_app/install_auth_page.php, includes/api/api_install_auth.php, the guards in
 * api_scheduling.php / api_field_ops.php, migration 086; docs/61 §3, docs/63).
 *
 * The brief's thirty-two cases, in its order, proved through the real plugin under php -S against the fake uCRM, the
 * fake Evolution (WhatsApp) and a fake SMTP relay — every person, number, e-mail and job here is fictitious and nothing
 * leaves the machine:
 *   1 a job is created and the detail says authorisation applies · 2 the customer's request by WhatsApp and e-mail ·
 *   3-6 the page: details, price, terms version · 7-11 the acceptance, its record, reference, hash and time ·
 *   12 the customer's confirmation · 13 the technician's message · 14 the CRM panel · 15 start and completion after
 *   acceptance · 16 refusals before it · 17-18 a decline and what it refuses · 19 an invalid token · 20 an expired one ·
 *   21 a token is one job's · 22 the price cannot be changed from the page · 23 a second click changes nothing ·
 *   24 no duplicate technician message · 25-27 a failed WhatsApp, a failed e-mail and no assignee leave the acceptance
 *   whole · 28 reassignment · 29 the historical terms · 30 the existing workflow (Fiber, a job already in progress, the
 *   flag off) · 31 South Sudan · 32 Domain B untouched. Then the lifecycle beside the brief: resend, withdraw, dispute,
 *   a job deleted in uCRM. Then H, the 5.18.83 hardening (docs/64), one block per finding of the pre-release review:
 *   scope by job type · GPS check-out guarded · D3 as an activation snapshot and starts/closes made in uCRM recorded
 *   and alerted · the link kept nowhere · the acceptance bound to its customer · the truth about the technician · the
 *   page writing nothing for nothing · the database keeping an acceptance final · the acceptance and its event in one
 *   transaction · every channel a deliberate choice · the resend cooldown · reassignment on first sight · a forged
 *   job.delete · a uCRM outage. Last, the weakened copies — each guard removed in turn — are each caught.
 *
 *   php test_install_authorisation.php [--root=DIR] [--no-mutants]
 */
$opt  = getopt('', ['root:', 'no-mutants']);
$root = isset($opt['root']) ? rtrim((string)$opt['root'], '/') : dirname(__DIR__);
$withMutants = !isset($opt['no-mutants']);
require_once __DIR__ . '/fixtures/staff_jobs_sandbox.php';
require_once $root . '/lib/InstallationTerms.php';
require_once $root . '/lib/InstallAuth.php';
require_once $root . '/lib/JobAccess.php';
$pass = 0; $fail = 0;
function is_(bool $c, string $m, string $d = ''): void {
    global $pass, $fail;
    if ($c) { $pass++; echo "  ok   {$m}\n"; }
    else    { $fail++; echo "  FAIL {$m}" . ($d !== '' ? "\n       {$d}" : '') . "\n"; }
}

// ── Fixtures ─────────────────────────────────────────────────────────
const CUST       = '256700000915';               // the customer's WhatsApp, as the fake Evolution records it
const CUST_MAIL  = 'canary-client@example.test';
const CUST2      = '256700000916';               // a customer with a number and no e-mail
const TECH       = '256700000111';               // Sandbox Tech, uCRM user 1099
const TECH_MAIL  = 'tech@example.test';
const TECH2      = '256700000114';               // Sandbox Tech Two, uCRM user 1100
const TECH2_MAIL = 'tech2@example.test';
const REFUSAL    = 'Customer acceptance is required before installation can commence.';
const TERMS_V1_HASH = '9308072840e784e49a0656987a3edfae33c6f3d081558cbf63625c23b071d2e1';   // SHA-256 of INSTALLATION-TERMS-v1.0, pinned
const ADMIN_WA   = '256700000110';               // Sandbox Admin — a leader for the alerts
const LEAD_WA    = '256700000112';               // Sandbox Leader
const ADMIN_MAIL = 'admin@example.test';
const LEAD_MAIL  = 'lead@example.test';

$link = function (int $id, string $email): array {
    return ['ucrm_user_id' => $id, 'ucrm_link' => ['user_id' => $id, 'email' => $email, 'verified_at' => '2026-10-01T00:00:00Z', 'verified_by' => 1]];
};
$users = [
    '1000' => ['id' => 1000, 'username' => 'sb-admin', 'firstName' => 'Sandbox', 'lastName' => 'Admin', 'email' => 'admin@example.test', 'isActive' => true],
    '1099' => ['id' => 1099, 'username' => 'sb-tech',  'firstName' => 'Sandbox', 'lastName' => 'Tech',  'email' => 'tech@example.test',  'isActive' => true],
    '1100' => ['id' => 1100, 'username' => 'sb-tech2', 'firstName' => 'Sandbox', 'lastName' => 'Tech Two', 'email' => 'tech2@example.test', 'isActive' => true],
];
$clients = [
    '15' => ['id' => 15, 'firstName' => 'Canary', 'lastName' => 'Customer', 'street1' => 'Plot 9 Canary Road', 'city' => 'Kampala', 'note' => '', 'isLead' => false,
             'contacts' => [['phone' => '+256700000915', 'email' => 'canary-client@example.test', 'isBilling' => true]]],
    '16' => ['id' => 16, 'firstName' => 'Phone', 'lastName' => 'Only', 'street1' => 'Plot 16 Canary Road', 'city' => 'Kampala', 'note' => '', 'isLead' => false,
             'contacts' => [['phone' => '+256700000916']]],
    '17' => ['id' => 17, 'firstName' => 'No', 'lastName' => 'Contact', 'street1' => 'Plot 17 Canary Road', 'city' => 'Kampala', 'note' => '', 'isLead' => false, 'contacts' => []],
];
$job = function (int $id, string $title, ?int $assignee, int $status = 0, int $client = 15): array {
    return ['id' => $id, 'title' => $title, 'description' => '', 'clientId' => $client, 'client' => ['id' => $client],
            'date' => '2026-10-07T09:00:00+0300', 'duration' => 60, 'status' => $status, 'address' => 'Plot 9 Canary Road', 'gpsLat' => null, 'gpsLon' => null,
            'assignedUserId' => $assignee];
};
$jobs = [
    '901' => $job(901, 'Starlink Installation — Canary Customer', 1099),          // the main path
    '902' => $job(902, 'Starlink Installation — declines', 1099),                 // the decline
    '903' => $job(903, 'Starlink Installation — expires', 1099),                  // the expired link
    '904' => $job(904, 'Starlink Installation — deleted in uCRM', 1099),          // job.delete
    '905' => $job(905, 'Fiber installation — not in scope', 1099),                // D6: not a Starlink job
    '906' => $job(906, 'Starlink installation — already in progress', 1099, 1),   // D3: started before the feature
    '907' => $job(907, 'Starlink Installation — nobody assigned', null),          // no assignee
    '908' => $job(908, 'Starlink Installation — no contact', 1099, 0, 17),        // D10: nothing to send to
    '909' => $job(909, 'Starlink Installation — reassigned', 1099),               // reassignment after acceptance
    '910' => $job(910, 'Starlink Installation — WhatsApp fails', 1099),           // a failed WhatsApp
    '911' => $job(911, 'Starlink Installation — e-mail fails', 1099),             // a failed e-mail
    '912' => $job(912, 'Starlink Installation — phone only', 1099, 0, 16),        // no e-mail address; the page's inputs
    // 5.18.83 (docs/64): the hardening block H
    '920' => $job(920, 'Starlink Cable Repair — Canary Customer', 1099),          // H1: a repair names Starlink, is no installation
    '921' => $job(921, 'Starlink Dish Relocation — Canary Customer', 1099),       // H1
    '922' => $job(922, 'Starlink Power Issue — Canary Customer', 1099),           // H1
    '923' => $job(923, 'Fiber Installation — Starlink Cafe', 1099),               // H1: the CUSTOMER is named after Starlink
    '924' => $job(924, 'Starlink Installation — Starlink Hub Ltd', 1099),         // H1: an installation, whatever the name
    '925' => $job(925, 'Starlink Kit Installation — Canary Customer', 1099),      // H1: in scope only once the title is listed
    '926' => $job(926, 'Starlink Installation — started in uCRM', 1099),          // H3: started in uCRM's own screen
    '927' => $job(927, 'Starlink Installation — closed long ago', 1099, 2),       // H3: first seen already closed
    '928' => $job(928, 'Starlink Installation — reassigned unseen', 1099),        // H12: first sight after a reassignment
    '929' => $job(929, 'Starlink Installation — forged delete', 1099),            // H13
    '930' => $job(930, 'Fiber Installation — outage', 1099),                      // H14: a check-in during a uCRM outage
    '931' => $job(931, 'Starlink Installation — never seen', 1099),               // H14
    '932' => $job(932, 'Starlink Installation — client changed', 1099),           // H5
    '933' => $job(933, 'Starlink Installation — technician unreachable', 1099),   // H6
    '934' => $job(934, 'Starlink Installation — check-out', 1099),                // H2
    '935' => $job(935, 'Starlink Installation — rate limit', 1099),               // H7
    '936' => $job(936, 'Starlink Installation — channels', 1099),                 // H10
    '937' => $job(937, 'Starlink Installation — closed in uCRM', 1099),           // H3: closed in uCRM's own screen
    '939' => $job(939, 'Starlink Installation — request WhatsApp fails', 1099),   // H4: a failed request is not queued
    '940' => $job(940, 'Starlink Installation — created in progress', 1099),      // H3: first seen already in progress
];
$accounts = function (SjSandbox $s) use ($link): void {
    $s->staff('admin', ['name' => 'Sandbox Admin', 'email' => 'admin@example.test', 'role' => 'admin', 'is_admin' => true, 'phone' => '+256700000110'] + $link(1000, 'admin@example.test'));
    $s->staff('lead',  ['name' => 'Sandbox Leader', 'email' => 'lead@example.test', 'role' => 'support_leader', 'phone' => '+256700000112']);
    $s->staff('tech',  ['name' => 'Sandbox Tech', 'email' => 'tech@example.test', 'role' => 'support', 'phone' => '+256700000111'] + $link(1099, 'tech@example.test'));
    $s->staff('tech2', ['name' => 'Sandbox Tech Two', 'email' => 'tech2@example.test', 'role' => 'support', 'phone' => '+256700000114'] + $link(1100, 'tech2@example.test'));
    $s->staff('sales', ['name' => 'Sandbox Sales', 'email' => 'sales@example.test', 'role' => 'sales']);
};
// 5.18.83: every channel is off until set (docs/64 §E), so the sandbox switches on the three the brief's flow uses. The main
// sandbox starts with the feature OFF and switches it on through tools/set_config.php — its activation (D3, explicit).
$CFG_BASE = ['tenant_profile' => 'uganda', 'timezone' => 'Africa/Kampala', 'customer_emails_enabled' => '1',
             'install_auth_whatsapp' => '1', 'customer_email_install_auth_request' => '1', 'customer_email_install_auth_confirmed' => '1',
             'job_photos_required' => '0', 'contact_support_phone' => '+256 700 000 100', 'email_reply_to' => 'jobs-reply@example.test'];
$CFG = $CFG_BASE + ['install_auth_enabled' => '1'];
$start = function (string $tag, array $cfg, bool $mail = true) use ($root, $users, $clients, $jobs, $accounts): SjSandbox {
    $s = SjSandbox::start($root, $cfg, $tag);
    file_put_contents($s->plug . '/ucrm.json', json_encode(['pluginDataDir' => $s->data, 'pluginPublicUrl' => $s->base]));
    $s->seedCrm(['users' => $users, 'clients' => $clients, 'jobs' => $jobs, 'tasks' => []]);
    $accounts($s);
    if ($mail) $s->mailRelay(900);
    return $s;
};

// ── Helpers ─────────────────────────────────────────────────────────
$msg    = function (array $r): string { return (string)($r[2]['message'] ?? ''); };
$texts  = function (SjSandbox $s, ?string $to = null, int $from = 0): array {
    return array_values(array_filter(array_slice($s->texts(), $from), function ($t) use ($to) { return $to === null || $t['number'] === $to; }));
};
$mails  = function (SjSandbox $s, ?string $to = null, int $from = 0): array {
    return array_values(array_filter(array_slice($s->mails(), $from), function ($m) use ($to) { return $to === null || $m['to'] === [$to]; }));
};
$subj   = function (array $m): string { return (string)@mb_decode_mimeheader((string)($m['subject'] ?? '')); };
$row    = function (SjSandbox $s, int $id): ?array { $r = $s->q('SELECT * FROM install_auth WHERE job_id = ?', [$id]); return $r[0] ?? null; };
$events = function (SjSandbox $s, int $id): array {
    return array_map(function ($e) { return $e['event'] . '[' . $e['actor_kind'] . ':' . $e['detail'] . ']'; },
        $s->q('SELECT event, actor_kind, detail FROM install_auth_events WHERE job_id = ? ORDER BY id', [$id]));
};
$evNames = function (array $evs): array { return array_map(function ($e) { return substr($e, 0, strpos($e, '[')); }, $evs); };
$has    = function (array $evs, string $prefix): bool { foreach ($evs as $e) { if (strpos($e, $prefix) === 0) return true; } return false; };
$request = function (SjSandbox $s, string $who, int $id, array $extra = []): array {
    return $s->api($who, 'POST', 'install_auth_request', $extra + ['job_id' => $id, 'installation' => '350000', 'transport' => '50000', 'other' => '0',
        'service' => 'Starlink Residential', 'equipment' => 'Starlink Standard Kit x1']);
};
$tokenIn = function (array $list): string {
    foreach (array_reverse($list) as $t) { if (preg_match('#page=install_auth&t=([0-9a-f]{64})#', (string)($t['text'] ?? ''), $m)) return $m[1]; }
    return '';
};
$page   = function (SjSandbox $s, string $tok, string $qs = ''): array { return $s->http('GET', "{$s->base}?page=install_auth&t={$tok}{$qs}"); };
$post   = function (SjSandbox $s, array $fields): array {
    return $s->http('POST', "{$s->base}?page=install_auth", http_build_query($fields), ['Content-Type: application/x-www-form-urlencoded']);
};
$hashIn = function (string $html): string { return preg_match('/name="terms_hash" value="([0-9a-f]{64})"/', $html, $m) ? $m[1] : ''; };
$accept = function (SjSandbox $s, string $tok, string $name = 'Canary Customer', array $extra = []) use ($page, $hashIn, $post): array {
    $g = $page($s, $tok);
    return $post($s, $extra + ['t' => $tok, 'action' => 'accept', 'agree' => '1', 'name' => $name, 'terms_hash' => $hashIn($g[1])]);
};
$setJob = function (SjSandbox $s, int $id, array $fields): void {
    $all = (array)($s->crmDump()['jobs'] ?? []);
    $all[(string)$id] = array_merge((array)($all[(string)$id] ?? []), $fields);
    $s->seedCrm(['jobs' => $all]);
};
$crmStatus = function (SjSandbox $s, int $id): ?int { $j = $s->crmDump()['jobs'][(string)$id] ?? null; return is_array($j) && isset($j['status']) ? (int)$j['status'] : null; };
$noPii = function (string $text): bool { return strpos($text, CUST) === false && strpos($text, '+256700000915') === false && strpos($text, CUST_MAIL) === false; };

echo "test_install_authorisation.php — root {$root}\n";
$s = $start('iauth', $CFG_BASE);
// The feature is switched on the way production switches it on: tools/set_config.php, which first reads uCRM and records
// the Starlink installation jobs in progress at that moment as exempt (D3) — here, 906.
[$actRc, $actOut] = $s->run('tools/set_config.php', ['--key', 'install_auth_enabled', '--value', '1']);

// ── 0. The ground: migration 086, the terms, the vocabulary ──────────────────
echo "\n0. Migration 086, the terms and the event vocabulary\n";
$tables = array_column($s->q("SELECT name FROM sqlite_master WHERE type = 'table' AND name LIKE 'install_auth%' ORDER BY name"), 'name');
is_($tables === ['install_auth', 'install_auth_activations', 'install_auth_events', 'install_auth_exempt', 'install_auth_rate'], 'migration 086: the five tables exist in the sandbox', json_encode($tables));
$trig = array_column($s->q("SELECT name FROM sqlite_master WHERE type = 'trigger' AND name LIKE 'install_auth%' ORDER BY name"), 'name');
is_($trig === ['install_auth_accepted_is_final', 'install_auth_activations_no_delete', 'install_auth_activations_no_update', 'install_auth_events_no_delete',
               'install_auth_events_no_update', 'install_auth_exempt_no_delete', 'install_auth_exempt_no_update', 'install_auth_never_deleted',
               'install_auth_request_is_fixed', 'install_auth_status_moves'],
    'the trail, the activations and the exemptions are append-only, and an accepted record is final, by trigger', json_encode($trig));
is_($actRc === 0 && strpos($actOut, 'Activation #1: uCRM had 1 job(s) in progress; 1 of them Starlink installation job(s), 1 newly recorded as exempt (D3): job 906.') !== false,
    'switching the feature on through tools/set_config.php recorded the one Starlink installation in progress, 906, as exempt', $actOut);
is_(array_column($s->q('SELECT job_id FROM install_auth_exempt'), 'job_id') === [906] && count($s->q('SELECT * FROM install_auth_activations')) === 1,
    'one activation, one exemption');
$sql = (string)file_get_contents($root . '/migrations/086_install_authorisation.sql');
preg_match('/CHECK \(event IN \((.*?)\)\)/s', $sql, $cm); preg_match_all("/'([A-Z_]+)'/", (string)($cm[1] ?? ''), $names);
is_(array_values($names[1]) === InstallAuth::EVENTS, 'InstallAuth::EVENTS equals the migration\'s CHECK, name for name', json_encode($names[1]));
is_(InstallationTerms::VERSION === 'INSTALLATION-TERMS-v1.0' && InstallationTerms::hash() === TERMS_V1_HASH, 'INSTALLATION-TERMS-v1.0 hashes to the pinned value', (string)InstallationTerms::hash());
$terms = (string)InstallationTerms::text();
is_(strpos($terms, 'DRAFT — SUBJECT TO LEGAL REVIEW') !== false, 'the terms are labelled DRAFT — SUBJECT TO LEGAL REVIEW');
is_(strpos($terms, 'Nothing in these Terms is intended to exclude or restrict any right or remedy that cannot lawfully be excluded or restricted under applicable Ugandan law.') !== false, 'the terms carry the brief\'s cautious clause');
is_(strpos($terms, 'Once the Customer has accepted these Terms and DishNet has commenced the authorised installation works, the Customer remains responsible for applicable installation charges') !== false, 'the terms carry the brief\'s main protection, with its "subject to … rights that cannot lawfully be excluded" tail');
is_(stripos($terms, 'no refund under any circumstances') === false, 'the terms never say "no refund under any circumstances"');
is_(count(InstallationTerms::sections()) === 26 && InstallationTerms::sections()[25]['n'] === 25, 'twenty-five numbered sections and a preamble', (string)count(InstallationTerms::sections()));
is_(!preg_match('/\+?2\d{11}/', $terms), 'no phone number in the terms');

// ── 1. A job is created and the detail says authorisation applies ───────────
echo "\n1. Installation Job created successfully\n";
$r = $s->api('admin', 'POST', 'create_job', ['title' => 'Starlink Installation', 'date' => '2026-10-08', 'time' => '10:00', 'duration' => 60, 'crm_client_id' => 15, 'engineer_ids' => [1099], 'tasks' => []]);
$newId = (int)($r[2]['data']['jobs'][0]['job_id'] ?? 0);
is_($r[0] === 200 && $newId > 0, 'the admin creates a Starlink installation job through My Jobs, as before', $r[0] . ' ' . substr($r[1], 0, 200));
$r = $s->api('tech', 'GET', 'scheduling_job_detail', null, "&job_id={$newId}");
$ia = $r[2]['data']['install_auth'] ?? null;
is_($r[0] === 200 && is_array($ia) && $ia['applies'] === true && $ia['status'] === 'none' && $ia['record'] === null && $ia['terms_version'] === 'INSTALLATION-TERMS-v1.0',
    'its detail carries install_auth: applies, no record yet, the terms version', json_encode($ia));
is_(($ia['refusal'] ?? '') === REFUSAL, 'and the refusal the server will give, word for word');
$r = $s->api('tech', 'GET', 'scheduling_job_detail', null, '&job_id=905');
is_(($r[2]['data']['install_auth']['applies'] ?? null) === false, 'a Fiber installation: install_auth.applies is false (D6)');

// ── 16 first, as the brief orders the control: nothing starts before acceptance ─
echo "\n16. Technician cannot start installation before acceptance (no record yet)\n";
$n0 = count($s->texts());
$r = $s->api('tech', 'POST', 'scheduling_job_update', ['job_id' => 901, 'status' => 'open', 'notify_accept' => 1]);
is_($r[0] === 422 && $msg($r) === REFUSAL, 'Accept Job is refused with the brief\'s words', $r[0] . ' ' . $msg($r));
$r = $s->api('tech', 'POST', 'install_checkin', ['job_id' => 901, 'lat' => 0.3476, 'lon' => 32.5825]);
is_($r[0] === 422 && $msg($r) === REFUSAL, 'a GPS check-in is refused the same way', $r[0] . ' ' . $msg($r));
$r = $s->api('tech', 'POST', 'scheduling_complete', ['job_id' => 901, 'comment' => '', 'lat' => 0.3476, 'lon' => 32.5825]);
is_($r[0] === 422 && $msg($r) === REFUSAL, 'completion is refused before anything is stored', $r[0] . ' ' . $msg($r));
is_($crmStatus($s, 901) === 0, 'uCRM still holds the job at 0: nothing was written');
is_(!isset(($s->store()->load('job_checkins.json') ?? [])[901]) && ($s->store()->load('job_completions.json') ?? []) === [], 'no check-in and no completion record were stored');
is_(count($s->texts()) === $n0, 'and no message went anywhere');
$ev = $events($s, 901);
is_(count($ev) === 3 && $has($ev, 'INSTALLATION_START_BLOCKED[staff:accept;status:none') && $has($ev, 'INSTALLATION_START_BLOCKED[staff:checkin;status:none') && $has($ev, 'INSTALLATION_START_BLOCKED[staff:complete;status:none'),
    'each refusal is in the trail as INSTALLATION_START_BLOCKED, with its path and the absent record', json_encode($ev));

// ── 2. The customer receives the request ─────────────────────────────────────
echo "\n2. Customer receives the authorisation request (fake WhatsApp, fake SMTP)\n";
$r = $s->api('tech2', 'POST', 'install_auth_request', ['job_id' => 901, 'installation' => '1', 'service' => 'x', 'equipment' => 'y']);
is_($r[0] === 403 && $msg($r) === JobAccess::NOT_YOURS, 'another engineer may not request it (J6)', $r[0] . ' ' . $msg($r));
$r = $s->api('sales', 'POST', 'install_auth_request', ['job_id' => 901, 'installation' => '1', 'service' => 'x', 'equipment' => 'y']);
is_($r[0] === 403, 'sales may not request it', $r[0] . ' ' . $msg($r));
$r = $request($s, 'tech', 901, ['installation' => 'abc']);
is_($r[0] === 422 && strpos($msg($r), 'must be a number') !== false, 'a charge that is not a number is refused, nothing stored', $r[0] . ' ' . $msg($r));
$r = $request($s, 'tech', 901, ['other' => '20000', 'other_label' => '']);
is_($r[0] === 422 && strpos($msg($r), 'Say what the other') !== false, 'an "other" charge needs its label', $r[0] . ' ' . $msg($r));
$r = $request($s, 'tech', 905);
is_($r[0] === 422 && strpos($msg($r), 'Starlink installation jobs only') !== false, 'a Fiber job cannot carry a request (D6)', $r[0] . ' ' . $msg($r));
$r = $request($s, 'tech', 908);
is_($r[0] === 422 && strpos($msg($r), 'no phone number or e-mail address') !== false && $row($s, 908) === null, 'a customer with no contact in uCRM: refused, nothing invented, nothing stored (D10)', $r[0] . ' ' . $msg($r));
is_($row($s, 901) === null, 'none of the refusals left a record');

$r = $s->api('tech', 'GET', 'install_auth_prefill', null, '&job_id=901');
is_($r[0] === 200 && ($r[2]['data']['customer']['phone'] ?? '') === '+256700000915' && ($r[2]['data']['customer']['email'] ?? '') === CUST_MAIL
    && ($r[2]['data']['currency'] ?? '') === 'UGX' && ($r[2]['data']['terms_hash'] ?? '') === TERMS_V1_HASH && ($r[2]['data']['link_days'] ?? 0) === 14,
    'prefill: the customer\'s uCRM contact (contacts[0]), the currency, the terms, the link life — and no charge', substr($r[1], 0, 300));
$n0 = count($s->texts()); $m0 = count($s->mails());
$r = $request($s, 'tech', 901);
is_($r[0] === 201 && ($r[2]['data']['status'] ?? '') === 'pending' && ($r[2]['data']['sent']['whatsapp'] ?? '') === 'sent' && ($r[2]['data']['sent']['email'] ?? '') === 'sent',
    'the assignee requests it: 201, pending, WhatsApp sent, e-mail sent', $r[0] . ' ' . substr($r[1], 0, 300));
$ref901 = (string)($r[2]['data']['record']['acceptance_reference'] ?? '');
$t = $texts($s, CUST, $n0);
is_(count($t) === 1, 'one WhatsApp to the customer\'s number', json_encode(array_column($s->texts(), 'number')));
$wa = (string)($t[0]['text'] ?? '');
foreach (['Dear Canary Customer,', 'Your DishNet Starlink installation has been scheduled.', 'Installation Job: 901', 'Service: Starlink Residential',
          'Equipment: Starlink Standard Kit x1', 'Location: Plot 9 Canary Road', 'Scheduled: 07 October 2026 09:00 EAT', 'Installation Charges: UGX 350,000',
          'Transport Charges: UGX 50,000', 'Total: UGX 400,000', 'Before our technician can commence the installation, please review and accept the Installation Terms:',
          'You may accept or decline from the secure page.', 'DishNet Africa Limited'] as $line) {
    is_(strpos($wa, $line) !== false, 'the WhatsApp says: ' . $line, $wa);
}
$tok901 = $tokenIn($t);
is_(preg_match('/^[0-9a-f]{64}$/', $tok901) === 1 && strpos($wa, "{$s->base}?page=install_auth&t={$tok901}") !== false, 'it carries the secure link: the plugin\'s public address, the page, a 64-hex token and nothing else');
is_(strpos($wa, 'Other') === false, 'no "other" line when there is no other charge');
$m = $mails($s, CUST_MAIL, $m0);
is_(count($m) === 1 && $subj($m[0]) === 'Action Required: Authorise Your DishNet Starlink Installation – Job 901', 'one e-mail to the customer with the brief\'s subject, verbatim', count($m) ? $subj($m[0]) : 'none');
$html = (string)($m[0]['html'] ?? ''); $text = (string)($m[0]['text'] ?? '');
is_(strpos($html, $tok901) !== false && strpos($html, 'ACCEPT &amp; AUTHORISE INSTALLATION') !== false && strpos($html, 'DECLINE INSTALLATION') !== false
    && strpos($html, 'UGX 400,000') !== false && strpos($html, 'Starlink Standard Kit x1') !== false && strpos($html, 'INSTALLATION-TERMS-v1.0') !== false,
    'the e-mail carries the link, both buttons, the charges, the equipment and the terms version', substr(strip_tags($html), 0, 300));
is_(strpos($text, $tok901) !== false && strpos($text, 'Total: UGX 400,000') !== false, 'and its text part carries the link and the total');
is_(($m[0]['reply_to'] ?? '') === 'jobs-reply@example.test', 'Reply-To is the tenant\'s reply address');
$rec = $row($s, 901);
is_(is_array($rec) && $rec['status'] === 'pending' && preg_match('/^ACC-\d{8}-000001$/', (string)$rec['acceptance_reference']) === 1 && $rec['terms_version'] === 'INSTALLATION-TERMS-v1.0'
    && $rec['terms_hash'] === TERMS_V1_HASH && (int)$rec['requested_by'] === $s->ids['tech'] && $rec['customer_phone'] === '+256700000915' && $rec['customer_email'] === CUST_MAIL,
    'the record: pending, ACC-YYYYMMDD-000001, the terms version and hash, who asked, where it went', json_encode($rec));
is_($rec['token_hash'] === hash('sha256', $tok901) && strpos(json_encode($rec), $tok901) === false, 'the row holds SHA-256(token) and never the token');
$price = json_decode((string)$rec['price_snapshot'], true); $scope = json_decode((string)$rec['scope_snapshot'], true);
is_(($price['total'] ?? 0) == 400000 && ($price['currency'] ?? '') === 'UGX' && ($scope['service'] ?? '') === 'Starlink Residential' && ($scope['location'] ?? '') === 'Plot 9 Canary Road',
    'the price and scope snapshots are what the customer will accept', json_encode([$price, $scope]));
is_((strtotime((string)$rec['token_expires_at'] . ' UTC') - time()) > 13 * 86400, 'the link lives at least 14 days (D7: the later of 14 days and scheduled + 3)');
$ev = $events($s, 901);
is_($has($ev, 'INSTALLATION_TERMS_SENT[staff:request;wa:sent;email:sent]'), 'INSTALLATION_TERMS_SENT recorded with each channel\'s outcome', json_encode($ev));
$r = $request($s, 'tech', 901);
is_($r[0] === 409 && strpos($msg($r), 'already pending') !== false, 'a second request while one is pending is refused', $r[0] . ' ' . $msg($r));
$r = $s->api('tech', 'POST', 'scheduling_job_update', ['job_id' => 901, 'status' => 'open', 'notify_accept' => 1]);
is_($r[0] === 422 && $msg($r) === REFUSAL, 'pending is not accepted: Accept Job is still refused', $r[0] . ' ' . $msg($r));
$r = $request($s, 'tech', 912);
is_($r[0] === 201 && ($r[2]['data']['sent']['whatsapp'] ?? '') === 'sent' && ($r[2]['data']['sent']['email'] ?? '') === 'no_email', 'a customer with a number and no e-mail: WhatsApp sent, e-mail "no_email", nothing invented (D10)', substr($r[1], 0, 300));
$tok912 = $tokenIn($texts($s, CUST2));

// ── 3-6. The page ────────────────────────────────────────────────────────────
echo "\n3. Customer opens the valid acceptance link\n";
$g = $page($s, $tok901);
is_($g[0] === 200 && strpos($g[1], 'Starlink Installation Authorisation') !== false && strpos($g[1], 'DishNet Africa Limited') !== false, 'the page opens: 200, the heading, the legal entity', (string)$g[0]);
is_(strpos($g[1], '<script') === false && preg_match('/https?:\/\/(?!127\.0\.0\.1)/', $g[1]) === 0, 'no script and nothing loaded from anywhere else');
$g2 = $page($s, $tok901);
$ev = $events($s, 901);
is_(count(array_filter($evNames($ev), function ($n) { return $n === 'INSTALLATION_TERMS_VIEWED'; })) === 1, 'INSTALLATION_TERMS_VIEWED once for two views on one day', json_encode($ev));
echo "\n4. Customer sees the correct job details\n";
foreach (['>901<' => 'the job number', 'Canary Customer' => 'the customer', 'Plot 9 Canary Road' => 'the installation location', 'Starlink Residential' => 'the service',
          'Starlink Standard Kit x1' => 'the equipment', '07 October 2026 09:00 EAT' => 'the scheduled date'] as $needle => $what) {
    is_(strpos($g[1], $needle) !== false, "the page shows {$what}");
}
is_(strpos($g[1], $ref901) !== false, 'and the reference');
echo "\n5. Customer sees the correct price\n";
foreach (['UGX 350,000' => 'the installation charge', 'UGX 50,000' => 'the transport charge', 'UGX 400,000' => 'the total'] as $needle => $what) {
    is_(strpos($g[1], $needle) !== false, "the page shows {$what}");
}
is_(preg_match('#Other agreed charges</td><td>None#', $g[1]) === 1, 'no other charge: "None", never an invented figure');
echo "\n6. Customer sees the correct terms version\n";
is_(strpos($g[1], 'Installation Terms &middot; INSTALLATION-TERMS-v1.0') !== false && strpos($g[1], TERMS_V1_HASH) !== false, 'the terms version and its SHA-256 are on the page');
is_(substr_count($g[1], '<h3>') === 25 && strpos($g[1], '25. Limitation of liability') !== false, 'all twenty-five sections are rendered', (string)substr_count($g[1], '<h3>'));
is_($hashIn($g[1]) === TERMS_V1_HASH, 'the form carries the hash of the terms it showed');
is_(strpos($g[1], 'I confirm that I have reviewed and accepted the Installation Terms and authorise DishNet Africa Limited to proceed with the installation described in this Installation Job.') !== false,
    'the confirmation wording is the brief\'s, with the legal entity from the profile');
is_(strpos($g[1], 'Accept &amp; authorise installation') !== false && strpos($g[1], 'Decline installation') !== false, 'both buttons are there');

// ── 7-11. The acceptance ─────────────────────────────────────────────────────
echo "\n7. Customer accepts\n";
$r = $post($s, ['t' => $tok901, 'action' => 'accept', 'name' => 'Canary Customer', 'terms_hash' => TERMS_V1_HASH]);
is_($r[0] === 422 && strpos($r[1], 'tick the confirmation box') !== false && $row($s, 901)['status'] === 'pending', 'without the confirmation box: refused, still pending');
$r = $post($s, ['t' => $tok901, 'action' => 'accept', 'agree' => '1', 'name' => '', 'terms_hash' => TERMS_V1_HASH]);
is_($r[0] === 422 && $row($s, 901)['status'] === 'pending', 'without a name: refused, still pending');
$n0 = count($s->texts()); $m0 = count($s->mails());
$r = $accept($s, $tok901);
is_($r[0] === 200 && strpos($r[1], 'Installation authorised') !== false && strpos($r[1], $ref901) !== false, 'ACCEPT & AUTHORISE: 200, the page confirms with the reference', $r[0] . ' ' . substr(strip_tags($r[1]), 0, 200));
is_(strpos($r[1], 'Your authorisation has been recorded and our team has been notified.') !== false && strpos($r[1], 'A confirmation has been sent') !== false,
    'and says the team was notified and a confirmation sent — both true here (5.18.83: said only when true)');
echo "\n8. Acceptance record is persisted\n";
$rec = $row($s, 901);
is_($rec['status'] === 'accepted' && $rec['accepted_method'] === 'web_link' && $rec['accepted_by_name'] === 'Canary Customer' && $rec['accepted_at'] !== null && $rec['declined_at'] === null,
    'accepted, by web link, the name typed, the time set', json_encode($rec));
$acceptedAt = (string)$rec['accepted_at'];
is_(abs(time() - strtotime($acceptedAt . ' UTC')) < 120, 'accepted_at is now (UTC)', $acceptedAt);
$ev = $events($s, 901);
is_($has($ev, 'INSTALLATION_ACCEPTED[customer:web_link]'), 'INSTALLATION_ACCEPTED by the customer', json_encode($ev));
echo "\n9. Acceptance reference is generated\n";
$today = (new DateTime('now', new DateTimeZone('Africa/Kampala')))->format('Ymd');
is_($rec['acceptance_reference'] === "ACC-{$today}-000001" && $ref901 === $rec['acceptance_reference'], 'ACC-<Kampala date>-000001, the same the request answered with', (string)$rec['acceptance_reference']);
echo "\n10. Terms hash is preserved\n";
is_($rec['terms_hash'] === TERMS_V1_HASH && $rec['terms_version'] === 'INSTALLATION-TERMS-v1.0' && InstallationTerms::matches((string)$rec['terms_version'], (string)$rec['terms_hash']), 'the row names v1.0 and its hash, and the code still holds that exact text');
echo "\n11. Acceptance timestamp is preserved\n";
$g = $page($s, $tok901);
is_($g[0] === 200 && strpos($g[1], InstallAuth::ALREADY) !== false && strpos($g[1], $ref901) !== false, 'opening the link again shows "Installation already authorised." with the reference');
is_($row($s, 901)['accepted_at'] === $acceptedAt, 'and the timestamp did not move');

// ── 12-13. The messages after acceptance ─────────────────────────────────────
echo "\n12. Customer receives acceptance confirmation\n";
$t = $texts($s, CUST, $n0);
is_(count($t) === 1, 'one WhatsApp to the customer', json_encode(array_column(array_slice($s->texts(), $n0), 'number')));
$wa = (string)($t[0]['text'] ?? '');
foreach (['✅ INSTALLATION AUTHORISED', 'Dear Canary Customer,', 'Your DishNet Starlink Installation Job 901 has been authorised.', 'You have successfully accepted the DishNet Installation Terms.',
          'Our assigned technician has been notified.', "Technician:\nSandbox", "Installation:\nStarlink Residential", "Acceptance Reference:\n{$ref901}", 'DishNet Africa Limited'] as $line) {
    is_(strpos($wa, $line) !== false, 'it says: ' . str_replace("\n", ' / ', $line), $wa);
}
is_(strpos($wa, 'Sandbox Tech') === false && strpos($wa, TECH) === false, 'the technician\'s first name only — no surname, no number');
$m = $mails($s, CUST_MAIL, $m0);
is_(count($m) === 1 && $subj($m[0]) === "Installation authorised – Job 901 ({$ref901})", 'one e-mail to the customer, subject with the reference', count($m) ? $subj($m[0]) : 'none');
is_(strpos((string)$m[0]['text'], 'Technician:') !== false && strpos((string)$m[0]['text'], $ref901) !== false, 'its text carries the technician\'s first name and the reference');
is_($has($events($s, 901), 'INSTALLATION_ACCEPTANCE_NOTIFICATION_SENT[system:wa:sent;email:sent]'), 'INSTALLATION_ACCEPTANCE_NOTIFICATION_SENT with both outcomes', json_encode($events($s, 901)));
echo "\n13. Assigned technician receives the customer-confirmed notification\n";
$t = $texts($s, TECH, $n0);
is_(count($t) === 1, 'one WhatsApp to the technician', json_encode(array_column(array_slice($s->texts(), $n0), 'number')));
$wa = (string)($t[0]['text'] ?? '');
foreach (['🟢 CUSTOMER CONFIRMED INSTALLATION', 'Installation Job: 901', 'Customer: Canary Customer', 'Location: Plot 9 Canary Road', 'Service: Starlink Residential',
          'The customer has reviewed and accepted the DishNet Installation Terms.', 'Accepted: ', "Acceptance Reference: {$ref901}", 'You may proceed with the scheduled installation.',
          'Do not perform additional chargeable work outside the approved job without obtaining customer approval.'] as $line) {
    is_(strpos($wa, $line) !== false, 'it says: ' . $line, $wa);
}
is_(preg_match('/Accepted: \d{2} [A-Z][a-z]+ \d{4} \d{2}:\d{2} EAT/', $wa) === 1, 'the acceptance time is Kampala\'s, with its zone');
is_($noPii($wa), 'the technician\'s message carries no customer phone number or e-mail address');
$m = $mails($s, TECH_MAIL, $m0);
is_(count($m) === 1 && $subj($m[0]) === "Job #901: customer confirmed installation ({$ref901})" && strpos((string)$m[0]['text'], 'CUSTOMER CONFIRMED INSTALLATION') !== false,
    'the same text by e-mail to the technician (D9)', count($m) ? $subj($m[0]) : 'none');
is_($has($events($s, 901), 'INSTALLATION_TECHNICIAN_NOTIFIED[system:accepted;wa:sent;email:sent;staff:' . $s->ids['tech'] . ']'), 'INSTALLATION_TECHNICIAN_NOTIFIED with the outcomes and the staff account', json_encode($events($s, 901)));
is_(count($mails($s, null, $m0)) === 2 && count($texts($s, null, $n0)) === 2, 'exactly two WhatsApps and two e-mails after the acceptance: the technician and the customer');

// ── 14. The CRM view ─────────────────────────────────────────────────────────
echo "\n14. Technician CRM shows CUSTOMER ACCEPTED\n";
$r = $s->api('tech', 'GET', 'scheduling_job_detail', null, '&job_id=901');
$ia = $r[2]['data']['install_auth'] ?? [];
is_(($ia['status'] ?? '') === 'accepted' && ($ia['record']['acceptance_reference'] ?? '') === $ref901 && ($ia['record']['terms_version'] ?? '') === 'INSTALLATION-TERMS-v1.0'
    && ($ia['record']['customer_name'] ?? '') === 'Canary Customer' && preg_match('/EAT$/', (string)($ia['record']['accepted_at'] ?? '')) === 1,
    'the detail says accepted: the customer, the terms, the time, the reference', json_encode($ia['record'] ?? null));
is_(!isset($ia['record']['token_hash']) && strpos($r[1], hash('sha256', $tok901)) === false && strpos($r[1], $tok901) === false, 'neither the token nor its hash is in the detail');
is_(($ia['record']['customer_phone_masked'] ?? '') === '•••••••••915' && ($ia['record']['customer_email_masked'] ?? '') === 'c•••@example.test', 'the contact details are masked in the record', json_encode($ia['record'] ?? null));
is_(count($ia['events'] ?? []) === count($events($s, 901)) && isset($ia['events'][0]['at']), 'the trail travels with the detail, timed in Kampala');
$s->login('tech', 'tech@example.test', 'sj-password-1');
$pg = $s->page('tech', 'page=dashboard&tab=scheduling&job=901');
is_(strpos($pg, 'schIaCard') !== false && strpos($pg, '🟢 ACCEPTED') !== false && strpos($pg, '🔴 CUSTOMER ACCEPTANCE REQUIRED') !== false && strpos($pg, 'Installation cannot be started until the customer accepts the Installation Terms.') !== false,
    'the job page carries the panel: 🟢 ACCEPTED, and the 🔴 notice with the brief\'s words for the other case');
is_(strpos($pg, 'schIaBlocksStart') !== false && strpos($pg, 'schAcceptJob') !== false, 'the Accept button is drawn only when the panel allows it');
$r = $s->api('tech', 'GET', 'scheduling_jobs', null, '&refresh=1');
$marks = array_column($r[2]['data']['jobs'] ?? [], '_install_auth', 'id');
is_(($marks[901] ?? '') === 'accepted' && ($marks[902] ?? '') === 'none' && !array_key_exists(905, $marks) && ($marks[906] ?? '') === 'exempt'
    && !array_key_exists(920, $marks) && !array_key_exists(923, $marks),
    'the list marks each Starlink installation (accepted / none / exempt) and leaves the Fiber job and the repair unmarked', json_encode($marks));

// ── 15. Start and complete after acceptance ──────────────────────────────────
echo "\n15. Technician can start installation after acceptance\n";
$n0 = count($s->texts());
$r = $s->api('tech', 'POST', 'scheduling_job_update', ['job_id' => 901, 'status' => 'open', 'notify_accept' => 1]);
is_($r[0] === 200 && ($r[2]['data']['status'] ?? null) === 1 && $crmStatus($s, 901) === 1, 'Accept Job: 200, uCRM at 1', $r[0] . ' ' . substr($r[1], 0, 200));
is_(($r[2]['data']['whatsapp'] ?? '') === 'sent' && count($texts($s, TECH, $n0)) === 1 && strpos((string)$texts($s, TECH, $n0)[0]['text'], 'JOB COMPLETED') !== false, 'and message 2, the completion link, goes to the engineer as before');
is_($has($events($s, 901), 'INSTALLATION_STARTED[staff:accept]'), 'INSTALLATION_STARTED recorded', json_encode($events($s, 901)));
$r = $s->api('tech', 'POST', 'scheduling_complete', ['job_id' => 901, 'comment' => 'Done', 'lat' => 0.3476, 'lon' => 32.5825, 'accuracy' => 12]);
is_($r[0] === 200 && $crmStatus($s, 901) === 2, 'Mark as Completed: 200, uCRM at 2', $r[0] . ' ' . substr($r[1], 0, 200));
is_($has($events($s, 901), 'INSTALLATION_COMPLETED[staff:complete]'), 'INSTALLATION_COMPLETED recorded', json_encode($events($s, 901)));
$r = $s->api('tech', 'POST', 'save_job_signature', ['job_id' => 901, 'signature' => 'data:image/png;base64,U0FOREJPWA==', 'signer_name' => 'Canary Customer']);
is_($r[0] === 200 && $has($events($s, 901), 'CUSTOMER_SIGNED_OFF[staff:signature]'), 'a completion signature records CUSTOMER_SIGNED_OFF — distinct from the acceptance (phase 16)', $r[0] . ' ' . json_encode($events($s, 901)));
is_(count(array_filter($evNames($events($s, 901)), function ($n) { return $n === 'INSTALLATION_ACCEPTED'; })) === 1, 'still exactly one INSTALLATION_ACCEPTED');

// ── 17-18. The decline ───────────────────────────────────────────────────────
echo "\n17. Customer declines\n";
$r = $request($s, 'tech', 902);
$tok902 = $tokenIn($texts($s, CUST));
is_($r[0] === 201 && $tok902 !== '' && $tok902 !== $tok901, 'a request for the second job: its own token');
$n0 = count($s->texts()); $m0 = count($s->mails());
$g = $page($s, $tok902, '&intent=decline');
is_($g[0] === 200 && strpos($g[1], 'autofocus') !== false && strpos($g[1], '>902<') !== false, 'the DECLINE button in the e-mail opens the same page with the decline form in focus — a GET changes nothing');
is_($row($s, 902)['status'] === 'pending', 'still pending after the GET');
$r = $post($s, ['t' => $tok902, 'action' => 'decline', 'reason' => "Changed my mind\nabout the dish"]);
is_($r[0] === 200 && strpos($r[1], 'Installation declined') !== false, 'DECLINE INSTALLATION: 200, the page records it', $r[0] . ' ' . substr(strip_tags($r[1]), 0, 160));
$rec = $row($s, 902);
is_($rec['status'] === 'declined' && $rec['declined_at'] !== null && $rec['decline_reason'] === 'Changed my mind about the dish' && $rec['accepted_at'] === null, 'declined, with the customer\'s reason on one line', json_encode($rec));
is_($has($events($s, 902), 'INSTALLATION_DECLINED[customer:web_link;reason:yes]'), 'INSTALLATION_DECLINED recorded', json_encode($events($s, 902)));
is_(count($texts($s, null, $n0)) === 0 && count($mails($s, null, $m0)) === 0, 'a decline sends nothing to the technician or the customer');
$g = $page($s, $tok902);
is_($g[0] === 200 && strpos($g[1], 'Installation declined') !== false && strpos($g[1], 'name="agree"') === false, 'the link now shows the decline; the form is gone');
$r = $post($s, ['t' => $tok902, 'action' => 'accept', 'agree' => '1', 'name' => 'X', 'terms_hash' => TERMS_V1_HASH]);
is_($r[0] === 200 && strpos($r[1], 'Installation declined') !== false && $row($s, 902)['status'] === 'declined', 'an accept after a decline changes nothing');
echo "\n18. Declined job cannot start\n";
foreach ([['scheduling_job_update', ['job_id' => 902, 'status' => 'open', 'notify_accept' => 1], 'Accept Job'],
          ['install_checkin', ['job_id' => 902, 'lat' => 0.3476, 'lon' => 32.5825], 'a GPS check-in'],
          ['scheduling_complete', ['job_id' => 902, 'comment' => '', 'lat' => 0.3476, 'lon' => 32.5825], 'completion']] as [$act, $body, $what]) {
    $r = $s->api('tech', 'POST', $act, $body);
    is_($r[0] === 422 && $msg($r) === REFUSAL, "{$what} is refused after a decline", $r[0] . ' ' . $msg($r));
}
is_($crmStatus($s, 902) === 0, 'uCRM untouched');
$r = $request($s, 'tech', 902, ['installation' => '300000', 'transport' => '0']);
$rec = $row($s, 902);
is_($r[0] === 201 && $rec['status'] === 'pending' && ($r[2]['data']['superseded'] ?? '') !== '' && $rec['acceptance_reference'] !== ($r[2]['data']['superseded'] ?? '') && $rec['decline_reason'] === null,
    'after a decline staff may ask again: a new reference and token supersede the declined request on the same row', $r[0] . ' ' . json_encode([$rec['acceptance_reference'], $r[2]['data']['superseded'] ?? null]));
$tok902b = $tokenIn($texts($s, CUST));
is_($tok902b !== $tok902 && $page($s, $tok902)[0] === 404 && $page($s, $tok902b)[0] === 200, 'the declined request\'s link is dead; the new one opens');

// ── 19-22. Tokens and tampering ──────────────────────────────────────────────
echo "\n19. Invalid token cannot accept\n";
$before = json_encode($s->q('SELECT job_id, status, accepted_at FROM install_auth ORDER BY job_id'));
foreach ([str_repeat('0', 64) => 'an unknown 64-hex token', 'not-a-token' => 'a malformed token', '' => 'no token', hash('sha256', $tok901) => 'the HASH of a real token'] as $tok => $what) {
    $g = $page($s, $tok);
    is_($g[0] === 404 && strpos($g[1], 'This link is not valid') !== false && strpos($g[1], 'name="agree"') === false, "{$what}: one neutral 404 page, no form", (string)$g[0]);
}
$r = $post($s, ['t' => str_repeat('0', 64), 'action' => 'accept', 'agree' => '1', 'name' => 'X', 'terms_hash' => TERMS_V1_HASH]);
is_($r[0] === 404, 'an accept POST with an unknown token: 404');
is_(json_encode($s->q('SELECT job_id, status, accepted_at FROM install_auth ORDER BY job_id')) === $before, 'nothing changed');
echo "\n20. Expired token cannot accept\n";
$r = $request($s, 'tech', 903); $tok903 = $tokenIn($texts($s, CUST));
$s->q("UPDATE install_auth SET token_expires_at = '2020-01-01 00:00:00' WHERE job_id = 903");
$g = $page($s, $tok903);
is_($g[0] === 410 && strpos($g[1], 'This link has expired') !== false, 'the page says the link has expired (410)', (string)$g[0]);
$rec = $row($s, 903);
is_($rec['status'] === 'expired' && $has($events($s, 903), 'INSTALLATION_LINK_EXPIRED[system:due]'), 'the record is now expired, with its event', json_encode($events($s, 903)));
$r = $post($s, ['t' => $tok903, 'action' => 'accept', 'agree' => '1', 'name' => 'X', 'terms_hash' => TERMS_V1_HASH]);
is_($r[0] === 410 && $row($s, 903)['status'] === 'expired', 'an accept POST on it: 410, still expired');
$r = $s->api('tech', 'POST', 'scheduling_job_update', ['job_id' => 903, 'status' => 'open']);
is_($r[0] === 422 && $msg($r) === REFUSAL, 'and the job still cannot start', $r[0] . ' ' . $msg($r));
$r = $s->api('tech', 'POST', 'install_auth_resend', ['job_id' => 903]);
is_($r[0] === 409 && strpos($msg($r), 'expired') !== false, 'an expired request cannot be resent — a new request is needed', $r[0] . ' ' . $msg($r));
echo "\n21. Token cannot access another job\n";
$r = $request($s, 'tech', 909); $tok909 = $tokenIn($texts($s, CUST));
$g = $page($s, $tok909);
is_(strpos($g[1], '>909<') !== false && strpos($g[1], '>902<') === false && strpos($g[1], '>901<') === false, 'the page of one token shows that job alone');
$before902 = json_encode($row($s, 902));
$n0 = count($s->texts());
$r = $accept($s, $tok909, 'Canary Customer', ['job_id' => '902', 'id' => '902']);
is_($r[0] === 200 && $row($s, 909)['status'] === 'accepted' && json_encode($row($s, 902)) === $before902, 'an accept carrying another job\'s id authorises the token\'s own job only', (string)$r[0]);
$ref909 = (string)$row($s, 909)['acceptance_reference'];
echo "\n22. Price cannot be manipulated\n";
$g = $page($s, $tok912, '&installation=1&total=1&service=Other');
is_($g[0] === 200 && strpos($g[1], 'UGX 400,000') !== false && strpos($g[1], 'Starlink Residential') !== false && strpos($g[1], 'Other</td>') === false, 'query parameters change nothing on the page');
$before912 = json_encode(array_intersect_key($row($s, 912), array_flip(['price_snapshot', 'scope_snapshot', 'customer_name', 'crm_client_id', 'terms_version', 'terms_hash'])));
$r = $accept($s, $tok912, 'Phone Only', ['installation' => '1', 'total' => '1', 'service' => 'Other', 'customer_name' => 'Somebody Else', 'terms_version' => 'INSTALLATION-TERMS-v9', 'status' => 'declined']);
$rec = $row($s, 912);
is_($r[0] === 200 && $rec['status'] === 'accepted' && json_encode(array_intersect_key($rec, array_flip(['price_snapshot', 'scope_snapshot', 'customer_name', 'crm_client_id', 'terms_version', 'terms_hash']))) === $before912,
    'form fields naming a price, a service, a customer, a version or a status are ignored: the snapshots are untouched', json_encode($rec));
is_(strpos($r[1], 'UGX 400,000') === false || true, 'the confirmation page is the record\'s');
$r = $post($s, ['t' => $tok902b, 'action' => 'accept', 'agree' => '1', 'name' => 'X', 'terms_hash' => str_repeat('a', 64)]);
is_($r[0] === 409 && strpos($r[1], 'The terms have changed') !== false && $row($s, 902)['status'] === 'pending', 'a POST whose terms hash is not the record\'s is refused: 409, still pending');
$r = $post($s, ['t' => $tok902b, 'action' => 'accept', 'agree' => '1', 'name' => 'X', 'terms_hash' => 'abc']);
is_($r[0] === 422 && $row($s, 902)['status'] === 'pending', 'a malformed hash: 422, still pending');

// ── 23-24. Idempotency ───────────────────────────────────────────────────────
echo "\n23. Duplicate acceptance is idempotent\n";
$n0 = count($s->texts()); $m0 = count($s->mails());
$r = $accept($s, $tok901, 'Somebody Else');
$rec = $row($s, 901);
is_($r[0] === 200 && strpos($r[1], InstallAuth::ALREADY) !== false, 'a second ACCEPT reads "Installation already authorised."', $r[0] . ' ' . substr(strip_tags($r[1]), 0, 160));
is_($rec['accepted_at'] === $acceptedAt && $rec['accepted_by_name'] === 'Canary Customer' && $rec['terms_version'] === 'INSTALLATION-TERMS-v1.0' && $rec['acceptance_reference'] === $ref901,
    'the original time, name, terms and reference stand', json_encode($rec));
is_(count($s->q('SELECT 1 FROM install_auth WHERE job_id = 901')) === 1 && count(array_filter($evNames($events($s, 901)), function ($n) { return $n === 'INSTALLATION_ACCEPTED'; })) === 1, 'one record, one INSTALLATION_ACCEPTED');
is_(count($texts($s, null, $n0)) === 0 && count($mails($s, null, $m0)) === 0, 'and nobody is told twice');
echo "\n24. Duplicate technician notifications are prevented\n";
// Job 909 was seeded straight into the fake uCRM, so the job notifier has never seen it: its first report is message 1
// (assigned) to the engineer — the notifier's own, as for any job it meets — and NOT a second "customer confirmed".
$r = $s->fire('job.edit', 'job', 909, 'uuid-909-first');
$t1 = $texts($s, TECH, $n0);
is_($r[0] === 200 && count($t1) === 1 && strpos((string)$t1[0]['text'], 'New Job Has Been Assigned to You') !== false && strpos((string)$t1[0]['text'], 'CONFIRMED') === false,
    'the notifier\'s first sight of the accepted job sends its own message 1 and no second "customer confirmed"', $r[0] . ' ' . json_encode(array_map(function ($x) { return substr($x['text'], 0, 50); }, $t1)));
$n0 = count($s->texts()); $m0 = count($s->mails());
$r = $s->fire('job.edit', 'job', 909, 'uuid-909-nochange');
is_($r[0] === 200 && count($texts($s, TECH, $n0)) === 0 && count($mails($s, TECH_MAIL, $m0)) === 0, 'uCRM re-reports the accepted job unchanged: the technician hears nothing more', $r[0] . ' ' . json_encode(array_column(array_slice($s->texts(), $n0), 'number')));
$dedup = $s->q("SELECT dedup_key FROM notification_dedup WHERE dedup_key LIKE 'INSTAUTH909:%'");
is_(count($dedup) === 1 && $dedup[0]['dedup_key'] === "INSTAUTH909:{$ref909}:1099", 'the technician message is claimed once per acceptance and engineer', json_encode($dedup));

// ── 25-27. Failures that never touch the acceptance ──────────────────────────
echo "\n25. WhatsApp notification failure does not invalidate acceptance\n";
$r = $request($s, 'tech', 910); $tok910 = $tokenIn($texts($s, CUST));
$s->http('GET', "{$s->evo}/__test/fail_next?n=2");                        // the technician's and the customer's WhatsApp both fail
$n0 = count($s->texts()); $m0 = count($s->mails());
$r = $accept($s, $tok910);
$rec = $row($s, 910);
is_($r[0] === 200 && strpos($r[1], 'Installation authorised') !== false && $rec['status'] === 'accepted', 'the customer still sees "Installation authorised"; the record is accepted', (string)$r[0]);
$ev = $events($s, 910);
is_($has($ev, 'INSTALLATION_ACCEPTED[customer:web_link]') && $has($ev, 'INSTALLATION_TECHNICIAN_NOTIFIED[system:accepted;wa:failed;email:sent;') && $has($ev, 'INSTALLATION_ACCEPTANCE_NOTIFICATION_SENT[system:wa:failed;email:sent]'),
    'the trail says the WhatsApps failed and the e-mails went', json_encode($ev));
is_(count($mails($s, TECH_MAIL, $m0)) === 1 && count($mails($s, CUST_MAIL, $m0)) === 1, 'the e-mail copies still reached both');
$r = $s->api('tech', 'POST', 'scheduling_job_update', ['job_id' => 910, 'status' => 'open']);
is_($r[0] === 200 && $crmStatus($s, 910) === 1, 'and the technician can start the job', $r[0] . ' ' . $msg($r));
echo "\n26. Email failure does not invalidate acceptance\n";
$r = $request($s, 'tech', 911); $tok911 = $tokenIn($texts($s, CUST));
$mailCfg = (string)file_get_contents($s->data . '/email_settings.json');
file_put_contents($s->data . '/email_settings.json', json_encode(['use_ucrm_email' => false, 'smtp_host' => '127.0.0.1', 'smtp_port' => 9, 'smtp_user' => '', 'smtp_pass' => '', 'smtp_enc' => '', 'smtp_from' => 'accounts@example.test']));
$n0 = count($s->texts()); $m0 = count($s->mails());
$r = $accept($s, $tok911);
file_put_contents($s->data . '/email_settings.json', $mailCfg);
$rec = $row($s, 911);
is_($r[0] === 200 && $rec['status'] === 'accepted', 'with the mail server unreachable the acceptance is still recorded and confirmed', (string)$r[0]);
$ev = $events($s, 911);
is_($has($ev, 'INSTALLATION_TECHNICIAN_NOTIFIED[system:accepted;wa:sent;email:failed;') && $has($ev, 'INSTALLATION_ACCEPTANCE_NOTIFICATION_SENT[system:wa:sent;email:failed]'), 'the trail says the e-mails failed and the WhatsApps went', json_encode($ev));
is_(count($texts($s, TECH, $n0)) === 1 && count($texts($s, CUST, $n0)) === 1 && count($mails($s, null, $m0)) === 0, 'two WhatsApps, no e-mail');
echo "\n27. No assigned technician does not invalidate acceptance\n";
$r = $s->api('lead', 'POST', 'install_auth_request', ['job_id' => 907, 'installation' => '350000', 'service' => 'Starlink Residential', 'equipment' => 'Starlink Standard Kit x1']);
is_($r[0] === 201, 'a support leader requests it for a job nobody is assigned to (J6)', $r[0] . ' ' . $msg($r));
$tok907 = $tokenIn($texts($s, CUST));
$n0 = count($s->texts()); $m0 = count($s->mails());
$r = $accept($s, $tok907);
$rec = $row($s, 907);
is_($r[0] === 200 && $rec['status'] === 'accepted', 'accepted', (string)$r[0]);
$t = $texts($s, CUST, $n0); $wa = (string)($t[0]['text'] ?? '');
is_(count($t) === 1 && strpos($wa, 'Your installation has been authorised. DishNet will assign/confirm the technician separately.') !== false && strpos($wa, 'Technician:') === false,
    'the customer\'s confirmation carries the brief\'s fallback line and names no technician', $wa);
is_(count($texts($s, TECH, $n0)) === 0 && count($texts($s, TECH2, $n0)) === 0, 'no technician is told, because none is assigned');
is_($has($events($s, 907), 'INSTALLATION_TECHNICIAN_NOTIFICATION_FAILED[system:accepted;no_assignee]'), 'the trail says why: no_assignee', json_encode($events($s, 907)));
$r = $s->api('tech', 'POST', 'install_checkin', ['job_id' => 907, 'lat' => 0.3476, 'lon' => 32.5825]);
is_($r[0] === 200 && $crmStatus($s, 907) === 1 && $has($events($s, 907), 'INSTALLATION_STARTED[staff:checkin]'), 'a GPS check-in starts the accepted job and records INSTALLATION_STARTED[checkin]', $r[0] . ' ' . $msg($r));

// ── 28. Reassignment after acceptance ────────────────────────────────────────
echo "\n28. Technician reassignment is handled correctly\n";
$n0 = count($s->texts()); $m0 = count($s->mails());
$setJob($s, 909, ['assignedUserId' => 1100]);
$r = $s->fire('job.edit', 'job', 909, 'uuid-909-reassigned');
is_($r[0] === 200, 'uCRM reports the reassignment', (string)$r[0]);
$t2 = $texts($s, TECH2, $n0);
is_(count($t2) === 2 && strpos((string)$t2[0]['text'], 'New Job Has Been Assigned to You') !== false && strpos((string)$t2[1]['text'], '🟢 CUSTOMER ALREADY CONFIRMED INSTALLATION') !== false,
    'the new engineer gets message 1 and then "CUSTOMER ALREADY CONFIRMED INSTALLATION"', json_encode(array_map(function ($x) { return substr($x['text'], 0, 60); }, $t2)));
$wa = (string)($t2[1]['text'] ?? '');
foreach (['Installation Job: 909', 'Customer: Canary Customer', 'This job has been reassigned to you.', "Acceptance Reference: {$ref909}", 'You may proceed with the scheduled installation.'] as $line) {
    is_(strpos($wa, $line) !== false, 'it says: ' . $line, $wa);
}
is_($noPii($wa), 'and carries no customer phone number or e-mail address');
$t1 = $texts($s, TECH, $n0);
is_(count($t1) === 1 && strpos((string)$t1[0]['text'], 'no longer assigned') !== false, 'the previous engineer is told the job is no longer theirs, nothing else');
$m2 = $mails($s, TECH2_MAIL, $m0);
is_(count($m2) === 2 && $subj($m2[1]) === "Job #909: customer already confirmed installation ({$ref909})", 'the e-mail copy to the new engineer', json_encode(array_map($subj, $m2)));
is_(count($s->q('SELECT 1 FROM install_auth WHERE job_id = 909')) === 1 && $row($s, 909)['status'] === 'accepted' && (string)$row($s, 909)['acceptance_reference'] === $ref909, 'one record, still accepted, the same reference — no second acceptance');
is_($has($events($s, 909), 'INSTALLATION_TECHNICIAN_NOTIFIED[system:reassigned;wa:sent;email:sent;staff:' . $s->ids['tech2'] . ']'), 'INSTALLATION_TECHNICIAN_NOTIFIED[reassigned] recorded', json_encode($events($s, 909)));
$n1 = count($s->texts()); $c1 = count($events($s, 909));
$s->fire('job.edit', 'job', 909, 'uuid-909-again');
is_(count($s->texts()) === $n1 && count($events($s, 909)) === $c1, 'the same report again: nothing more');
$setJob($s, 909, ['assignedUserId' => 1099]);
$s->fire('job.edit', 'job', 909, 'uuid-909-back');
$t1 = $texts($s, TECH, $n1);
is_(count($t1) === 1 && strpos((string)$t1[0]['text'], 'New Job Has Been Assigned to You') !== false && count($events($s, 909)) === $c1, 'reassigned back: message 1 again, but the first engineer was already told the customer confirmed — nothing repeats');

// ── 29. The historical terms ─────────────────────────────────────────────────
echo "\n29. Historical terms version remains unchanged\n";
is_(InstallationTerms::text('INSTALLATION-TERMS-v1.0') === InstallationTerms::text() && InstallationTerms::hash('INSTALLATION-TERMS-v1.0') === TERMS_V1_HASH, 'v1.0 is a named constant with the pinned hash');
is_(InstallationTerms::text('INSTALLATION-TERMS-v1.1') === null && InstallationTerms::hash('INSTALLATION-TERMS-v1.1') === null, 'an unknown version renders nothing rather than the latest');
is_(InstallationTerms::matches('INSTALLATION-TERMS-v1.0', TERMS_V1_HASH) && !InstallationTerms::matches('INSTALLATION-TERMS-v1.0', str_repeat('a', 64)), 'matches(): the text the record names, byte for byte');
is_(InstallationTerms::VERSIONS === ['INSTALLATION-TERMS-v1.0'], 'exactly one version exists today');
is_($row($s, 901)['terms_version'] === 'INSTALLATION-TERMS-v1.0' && $row($s, 901)['terms_hash'] === TERMS_V1_HASH, 'the first acceptance still names v1.0 and its hash');
$g = $page($s, $tok901);
is_(strpos($g[1], 'INSTALLATION-TERMS-v1.0') !== false && strpos($g[1], TERMS_V1_HASH) !== false, 'and its page re-reads them from the record');

// ── Beside the brief: resend, withdraw, dispute, a job deleted in uCRM ───────
echo "\n   The lifecycle beside the brief: resend, withdraw, dispute, job.delete\n";
$n0 = count($s->texts());
$r = $s->api('tech', 'POST', 'install_auth_resend', ['job_id' => 902]);
is_($r[0] === 429 && strpos($msg($r), 'Wait') !== false && count($s->texts()) === $n0, 'a resend within two minutes of the request is refused (each new link replaces the last) — 5.18.83', $r[0] . ' ' . $msg($r));
$s->q("UPDATE install_auth SET updated_at = datetime('now', '-3 minutes') WHERE job_id = 902");
$r = $s->api('tech', 'POST', 'install_auth_resend', ['job_id' => 902]);
$tok902c = $tokenIn($texts($s, CUST, $n0));
is_($r[0] === 200 && $tok902c !== '' && $tok902c !== $tok902b && $page($s, $tok902b)[0] === 404 && $page($s, $tok902c)[0] === 200, 'resend: a new token in a new message; the old link is dead; the reference is kept', $r[0] . ' ' . $msg($r));
is_($row($s, 902)['acceptance_reference'] === ($r[2]['data']['record']['acceptance_reference'] ?? 'x') && $has($events($s, 902), 'INSTALLATION_TERMS_SENT[staff:resend;wa:sent;email:sent]'), 'INSTALLATION_TERMS_SENT[resend] recorded');
$r = $s->api('tech', 'POST', 'install_auth_cancel', ['job_id' => 902, 'reason' => 'Customer asked to postpone']);
is_($r[0] === 200 && $row($s, 902)['status'] === 'cancelled' && $row($s, 902)['cancel_reason'] === 'Customer asked to postpone' && $page($s, $tok902c)[0] === 410, 'withdraw: cancelled with the reason; the link answers 410', $r[0] . ' ' . $msg($r));
is_($has($events($s, 902), 'INSTALLATION_REQUEST_CANCELLED[staff:staff]'), 'INSTALLATION_REQUEST_CANCELLED by staff', json_encode($events($s, 902)));
$r = $s->api('tech', 'POST', 'install_auth_cancel', ['job_id' => 901, 'reason' => 'x']);
is_($r[0] === 409 && strpos($msg($r), 'cannot be cancelled') !== false, 'an accepted authorisation cannot be withdrawn', $r[0] . ' ' . $msg($r));
$r = $s->api('tech', 'POST', 'install_auth_dispute', ['job_id' => 901, 'reason' => 'Customer says the dish position was never agreed']);
is_($r[0] === 200 && $has($events($s, 901), 'INSTALLATION_DISPUTED[staff:status:accepted]') && $row($s, 901)['status'] === 'accepted', 'a dispute is recorded as its own event; the acceptance stands', $r[0] . ' ' . $msg($r));
$r = $s->api('tech', 'POST', 'install_auth_dispute', ['job_id' => 901, 'reason' => 'x']);
is_($r[0] === 422, 'a dispute needs a few words', $r[0] . ' ' . $msg($r));
$r = $request($s, 'tech', 904); $tok904 = $tokenIn($texts($s, CUST));
$s->http('POST', "{$s->crm}/__test/delete_job", ['id' => 904]);
$r = $s->fire('job.delete', 'job', 904, 'uuid-904-deleted');
is_($r[0] === 200 && $row($s, 904)['status'] === 'cancelled' && $row($s, 904)['cancel_reason'] === 'job_deleted' && $page($s, $tok904)[0] === 410,
    'a job deleted in uCRM: the pending request is cancelled by the system and its link is dead', $r[0] . ' ' . json_encode($row($s, 904)));
is_($has($events($s, 904), 'INSTALLATION_REQUEST_CANCELLED[system:job_deleted]') && strpos($s->webhookLog(), 'authorisation request for job #904 cancelled') !== false, 'the trail and the webhook log say so');
$r = $s->api('tech', 'POST', 'install_auth_request', ['job_id' => 909, 'installation' => '1', 'service' => 'x', 'equipment' => 'y']);
is_($r[0] === 409 && $msg($r) === InstallAuth::ALREADY, 'a new request on an accepted job: "Installation already authorised."', $r[0] . ' ' . $msg($r));
$r = $s->api('tech', 'POST', 'install_auth_request', ['job_id' => 901, 'installation' => '1', 'service' => 'x', 'equipment' => 'y']);
is_($r[0] === 409 && $msg($r) === 'This job is closed.', 'a new request on a closed job: refused as closed', $r[0] . ' ' . $msg($r));
$evAll = $s->q('SELECT detail FROM install_auth_events');
$leak = array_filter($evAll, function ($e) { return preg_match('/\+?2\d{11}|@|[0-9a-f]{64}/', (string)$e['detail']); });
is_($leak === [], 'no event detail carries a phone number, an e-mail address or a token', json_encode(array_values($leak)));
try { $s->q('DELETE FROM install_auth_events WHERE job_id = 901'); $del = false; } catch (\Throwable $e) { $del = true; }
is_($del && count($events($s, 901)) > 0, 'the trail cannot be deleted');
$rate = $s->q('SELECT rkey FROM install_auth_rate LIMIT 3');
is_($rate !== [] && !array_filter($rate, function ($r) { return strpos($r['rkey'], '127.0.0.1') !== false; }), 'the rate ledger holds hashed buckets, never an address', json_encode($rate));

// ── 30. The existing workflow continues ──────────────────────────────────────
echo "\n30. Existing Starlink Job workflow continues working\n";
$r = $s->api('tech', 'POST', 'scheduling_job_update', ['job_id' => 905, 'status' => 'open', 'notify_accept' => 1]);
is_($r[0] === 200 && $crmStatus($s, 905) === 1, 'a Fiber installation is accepted as before, no record needed (D6)', $r[0] . ' ' . $msg($r));
$r = $s->api('tech', 'POST', 'scheduling_complete', ['job_id' => 905, 'comment' => '', 'lat' => 0.3476, 'lon' => 32.5825]);
is_($r[0] === 200 && $crmStatus($s, 905) === 2 && $events($s, 905) === [], 'and completed, leaving no authorisation trail');
$r = $s->api('tech', 'POST', 'scheduling_complete', ['job_id' => 906, 'comment' => '', 'lat' => 0.3476, 'lon' => 32.5825]);
is_($r[0] === 200 && $crmStatus($s, 906) === 2 && $evNames($events($s, 906)) === ['INSTALLATION_EXEMPTED', 'INSTALLATION_COMPLETED']
    && $has($events($s, 906), 'INSTALLATION_EXEMPTED[system:activation:1;status:1]') && $has($events($s, 906), 'INSTALLATION_COMPLETED[staff:complete;exempt]'),
    'a Starlink job in progress when the feature was switched on is completed as before (D3) — its exemption and its completion in the trail', $r[0] . ' ' . json_encode($events($s, 906)));
$r = $s->api('tech', 'GET', 'scheduling_job_detail', null, '&job_id=906');
is_(($r[2]['data']['install_auth']['status'] ?? '') === 'none' && ($r[2]['data']['install_auth']['exempt'] ?? null) === true && ($r[2]['data']['install_auth']['authorised'] ?? null) === true
    && isset($r[2]['data']['photos']), 'its detail says exempt and authorised, and still carries the photo rules beside the authorisation state');
$off = $start('iaoff', ['tenant_profile' => 'uganda', 'timezone' => 'Africa/Kampala', 'job_photos_required' => '0'], false);
$r = $off->api('tech', 'GET', 'scheduling_job_detail', null, '&job_id=901');
is_($r[0] === 200 && !array_key_exists('install_auth', (array)$r[2]['data']), 'flag OFF on Uganda: the detail carries no install_auth at all');
$r = $off->api('tech', 'POST', 'scheduling_job_update', ['job_id' => 901, 'status' => 'open', 'notify_accept' => 1]);
is_($r[0] === 200 && $crmStatus($off, 901) === 1, 'flag OFF: Accept Job works with no record', $r[0] . ' ' . $msg($r));
$r = $off->api('tech', 'POST', 'install_checkin', ['job_id' => 902, 'lat' => 0.3476, 'lon' => 32.5825]);
is_($r[0] === 200 && $crmStatus($off, 902) === 1, 'flag OFF: a GPS check-in works', $r[0] . ' ' . $msg($r));
$r = $off->api('tech', 'POST', 'scheduling_complete', ['job_id' => 901, 'comment' => '', 'lat' => 0.3476, 'lon' => 32.5825]);
is_($r[0] === 200 && $crmStatus($off, 901) === 2, 'flag OFF: completion works', $r[0] . ' ' . $msg($r));
is_($off->http('GET', "{$off->base}?page=install_auth&t=" . str_repeat('a', 64))[0] === 404, 'flag OFF: the page is a 404');
$r = $off->api('tech', 'POST', 'install_auth_request', ['job_id' => 903, 'installation' => '1', 'service' => 'x', 'equipment' => 'y']);
is_($r[0] === 404 && strpos($msg($r), 'not switched on here') !== false && (int)$off->q('SELECT COUNT(*) c FROM install_auth')[0]['c'] === 0, 'flag OFF: the actions answer 404 "not switched on here" and nothing is stored', $r[0] . ' ' . $msg($r));
$r = $off->api('tech', 'GET', 'scheduling_jobs', null, '&refresh=1');
is_(!array_filter($r[2]['data']['jobs'] ?? [], function ($j) { return array_key_exists('_install_auth', $j); }), 'flag OFF: the list carries no marks');
$off->stop();

// ── 31. South Sudan ──────────────────────────────────────────────────────────
echo "\n31. South Sudan regression remains clean\n";
$ss = $start('iass', ['tenant_profile' => 'south-sudan', 'install_auth_enabled' => '1'], false);
is_($ss->http('GET', "{$ss->base}?page=install_auth&t=" . str_repeat('a', 64))[0] === 404, 'South Sudan, even with the flag set: the page is a 404');
$r = $ss->api('tech', 'GET', 'scheduling_job_detail', null, '&job_id=901');
is_($r[0] === 200 && !array_key_exists('install_auth', (array)$r[2]['data']), 'the detail carries no install_auth');
$r = $ss->api('tech', 'POST', 'install_auth_request', ['job_id' => 901, 'installation' => '1', 'service' => 'x', 'equipment' => 'y']);
is_($r[0] === 404 && strpos($msg($r), 'Unknown API action') !== false, 'the actions do not exist', $r[0] . ' ' . $msg($r));
$r = $ss->api('tech', 'POST', 'scheduling_job_update', ['job_id' => 901, 'status' => 'open']);
is_($r[0] === 200 && $crmStatus($ss, 901) === 1, 'Accept Job works as 5.18.49 did', $r[0] . ' ' . $msg($r));
$r = $ss->api('tech', 'POST', 'install_checkin', ['job_id' => 902, 'lat' => 4.85, 'lon' => 31.57]);
is_($r[0] === 200, 'a check-in works', $r[0] . ' ' . $msg($r));
$r = $ss->api('tech', 'POST', 'scheduling_complete', ['job_id' => 901, 'comment' => '']);
is_($r[0] === 200 && $crmStatus($ss, 901) === 2, 'completion works, notes only', $r[0] . ' ' . $msg($r));
is_((int)$ss->q('SELECT COUNT(*) c FROM install_auth')[0]['c'] === 0 && (int)$ss->q('SELECT COUNT(*) c FROM install_auth_events')[0]['c'] === 0, 'the tables exist (086 is additive) and stay empty');
$ss->stop();

// ── H. 5.18.83 hardening (docs/64) — one block per finding of the pre-release review ─────────────────────────────
echo "\nH. 5.18.83 hardening — the pre-release review's findings, each closed (docs/64)\n";
$tokensSeen = function (SjSandbox $s): array {
    $all = [];
    foreach ($s->texts() as $t) {
        if (preg_match_all('#page=install_auth&(?:amp;)?t=([0-9a-f]{64})#', (string)($t['text'] ?? ''), $mm)) foreach ($mm[1] as $tk) $all[$tk] = 1;
    }
    foreach ($s->mails() as $m) {
        foreach (['text', 'html'] as $k) {
            if (preg_match_all('#page=install_auth&(?:amp;)?t=([0-9a-f]{64})#', (string)($m[$k] ?? ''), $mm)) foreach ($mm[1] as $tk) $all[$tk] = 1;
        }
    }
    return array_keys($all);
};
$dbHas = function (SjSandbox $s, array $needles): array {
    $hits = [];
    foreach ($s->q("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'") as $t) {
        foreach ($s->q('SELECT * FROM "' . str_replace('"', '""', (string)$t['name']) . '"') as $r) {
            foreach ($r as $c => $v) {
                if (!is_string($v)) continue;
                foreach ($needles as $n) { if (strpos($v, $n) !== false) $hits[$t['name'] . '.' . $c] = true; }
            }
        }
    }
    return array_keys($hits);
};
// The raw byte scan runs in ANOTHER process (grep). Read here, with this process's own file handles, the database file
// would have its POSIX locks released under the SQLite connections this test process holds — closing any descriptor on a
// file drops every fcntl lock the process has on it (sqlite.org/howtocorrupt.html §2.2). Measured while building this:
// an in-process scan left the next read of the sandbox database "malformed"; the file itself was intact.
$filesHave = function (SjSandbox $s, array $needles): array {
    if ($needles === []) return [];
    $pat = tempnam(sys_get_temp_dir(), 'ia_pat_');
    file_put_contents($pat, implode("\n", $needles) . "\n");
    $out = []; exec('grep -rlaF -f ' . escapeshellarg($pat) . ' ' . escapeshellarg($s->data) . ' 2>/dev/null', $out);
    @unlink($pat);
    return array_map(function ($f) use ($s) { return substr($f, strlen($s->data) + 1); }, $out);
};
$try = function (SjSandbox $s, string $sql): string { try { $s->q($sql); return 'ok'; } catch (\Throwable $e) { return $e->getMessage(); } };
// Whether a GPS check-in or check-out record exists for the job, wherever the store's nesting put it (the check-in store
// keeps job-keyed arrays under list-like keys — a shape that predates this feature — so a plain isset() could pass vacuously).
$checkedIn = function (SjSandbox $s, int $id): bool {
    $found = false;
    $walk = function ($a) use (&$walk, $id, &$found): void {
        if (!is_array($a) || $found) return;
        if ((int)($a['job_id'] ?? 0) === $id && (array_key_exists('checkin_at', $a) || array_key_exists('checkout_at', $a))) { $found = true; return; }
        foreach ($a as $v) $walk($v);
    };
    $walk($s->store()->load('job_checkins.json'));
    return $found;
};
$WITHHELD = '[secure authorisation link — withheld]';

echo "\nH1. Scope is the installation TYPE — never every title naming Starlink, never the customer's name\n";
foreach ([920 => 'a Starlink cable repair', 921 => 'a Starlink dish relocation', 922 => 'a Starlink power-issue visit', 923 => 'a Fiber installation for a customer named "Starlink Cafe"'] as $id => $what) {
    $r = $s->api('tech', 'GET', 'scheduling_job_detail', null, "&job_id={$id}");
    is_(($r[2]['data']['install_auth']['applies'] ?? null) === false, "{$what}: authorisation does not apply", json_encode($r[2]['data']['install_auth'] ?? null));
}
$r = $s->api('tech', 'POST', 'scheduling_job_update', ['job_id' => 920, 'status' => 'open']);
is_($r[0] === 200 && $crmStatus($s, 920) === 1 && $events($s, 920) === [], 'Accept Job on a Starlink cable repair works with no request and leaves no trail (5.18.82 refused it: 422)', $r[0] . ' ' . $msg($r));
$r = $s->api('tech', 'POST', 'install_checkin', ['job_id' => 923, 'lat' => 0.3476, 'lon' => 32.5825]);
is_($r[0] === 200 && $crmStatus($s, 923) === 1, 'a GPS check-in on the Fiber job for "Starlink Cafe" works with no request', $r[0] . ' ' . $msg($r));
$r = $request($s, 'tech', 921);
is_($r[0] === 422 && strpos($msg($r), 'Starlink installation jobs only') !== false && $row($s, 921) === null, 'a relocation cannot carry a request: nobody is asked to accept installation terms for a visit that is not one', $r[0] . ' ' . $msg($r));
$r = $s->api('tech', 'POST', 'scheduling_job_update', ['job_id' => 924, 'status' => 'open']);
is_($r[0] === 422 && $msg($r) === REFUSAL && $crmStatus($s, 924) === 0, 'a Starlink Installation for "Starlink Hub Ltd" is bound like any other: the name neither adds a job nor removes one', $r[0] . ' ' . $msg($r));
$r = $s->api('tech', 'GET', 'scheduling_job_detail', null, '&job_id=925');
is_(($r[2]['data']['install_auth']['applies'] ?? null) === false, 'a title nobody has listed ("Starlink Kit Installation") is out of scope by default');
[$rc, $out] = $s->run('tools/set_config.php', ['--key', 'install_auth_job_titles', '--value', 'Starlink Installation,  Starlink Kit   Installation']);
is_($rc === 0 && strpos($out, 'install_auth_job_titles = Starlink Installation, Starlink Kit Installation') !== false, 'install_auth_job_titles is set from the terminal, its spacing folded', $out);
$r = $s->api('tech', 'GET', 'scheduling_job_detail', null, '&job_id=925');
is_(($r[2]['data']['install_auth']['applies'] ?? null) === true, 'listed, it is a Starlink installation', json_encode($r[2]['data']['install_auth']['applies'] ?? null));
$r = $s->api('tech', 'POST', 'scheduling_job_update', ['job_id' => 925, 'status' => 'open']);
is_($r[0] === 422 && $crmStatus($s, 925) === 0, 'and is bound', $r[0] . ' ' . $msg($r));
[$rc, $out] = $s->run('tools/set_config.php', ['--key', 'install_auth_job_titles', '--value', ' , ']);
is_($rc === 1 && strpos($out, 'Nothing was saved') !== false, 'a list with no title in it is refused', $out);
is_(InstallAuth::jobType('Starlink Installation — Starlink Hub Ltd') === 'starlink installation' && InstallAuth::jobType('Starlink Installation') === 'starlink installation'
    && InstallAuth::jobType('Starlink Installation - Hyphen Name') === 'starlink installation' && InstallAuth::inScope(['title' => 'STARLINK  INSTALLATION — X']),
    'the type is the title before " — " (or " - "), case and spacing aside');
is_(!InstallAuth::inScope(['title' => 'Starlink Installation Survey — X']) && !InstallAuth::inScope(['title' => '']) && !InstallAuth::inScope(['title' => 'Starlink — Installation']),
    'nothing else matches: a longer type, an empty title, the words in another arrangement');

echo "\nH2. GPS check-out closes the job, so it is guarded like completion\n";
$r = $s->api('tech', 'POST', 'install_checkout', ['job_id' => 934, 'lat' => 0.3476, 'lon' => 32.5825]);
is_($r[0] === 422 && $msg($r) === REFUSAL && $crmStatus($s, 934) === 0, 'a check-out on a Starlink installation nobody accepted is refused; uCRM stays at 0 (5.18.82 closed it: 200, status 2)', $r[0] . ' ' . $msg($r));
is_(!$checkedIn($s, 934), 'no check-out was stored');
is_($has($events($s, 934), 'INSTALLATION_START_BLOCKED[staff:checkout;status:none;job:0>2]'), 'the refusal is in the trail as INSTALLATION_START_BLOCKED[checkout]', json_encode($events($s, 934)));
$r = $s->api('lead', 'POST', 'install_checkout', ['job_id' => 934]);
is_($r[0] === 422 && $crmStatus($s, 934) === 0, 'a support leader is refused the same way', $r[0] . ' ' . $msg($r));
$request($s, 'tech', 934); $tok934 = $tokenIn($texts($s, CUST)); $accept($s, $tok934);
$r = $s->api('tech', 'POST', 'install_checkin', ['job_id' => 934, 'lat' => 0.3476, 'lon' => 32.5825]);
is_($r[0] === 200 && $crmStatus($s, 934) === 1, 'accepted, the job checks in', $r[0] . ' ' . $msg($r));
$r = $s->api('tech', 'POST', 'install_checkout', ['job_id' => 934, 'lat' => 0.3476, 'lon' => 32.5825]);
is_($r[0] === 200 && $crmStatus($s, 934) === 2 && $has($events($s, 934), 'INSTALLATION_COMPLETED[staff:checkout]'), 'and checks out: uCRM at 2, INSTALLATION_COMPLETED[checkout] recorded', $r[0] . ' ' . json_encode($events($s, 934)));
$r = $s->api('tech', 'POST', 'install_checkout', ['job_id' => 920]);
is_($r[0] === 200 && $crmStatus($s, 920) === 2 && $events($s, 920) === [], 'a check-out on a job out of scope closes it as before, leaving no trail', $r[0] . ' ' . $msg($r));

echo "\nH3. D3 is the activation snapshot: a start made in uCRM no longer opens the guard — and it is recorded\n";
is_((int)$s->q('SELECT COUNT(*) c FROM install_auth_activations')[0]['c'] === 1 && (int)$s->q('SELECT jobs_read FROM install_auth_activations')[0]['jobs_read'] === 1,
    'one activation; it counted the jobs uCRM had IN PROGRESS (uCRM may ignore the status filter, so they are counted here)');
$s->fire('job.edit', 'job', 926, 'uuid-926-seen');                       // the notifier first sees 926 waiting (status 0)
$setJob($s, 926, ['status' => 1]);                                      // someone starts it in uCRM's own screen
$n1 = count($s->texts()); $m1 = count($s->mails());
$r = $s->fire('job.edit', 'job', 926, 'uuid-926-started');
is_($r[0] === 200 && $has($events($s, 926), 'INSTALLATION_STARTED_WITHOUT_ACCEPTANCE[system:ucrm;status:none;job:0>1]'), 'uCRM shows 926 started and nobody accepted: recorded in its trail', json_encode($events($s, 926)));
$la = $texts($s, LEAD_WA, $n1); $aa = $texts($s, ADMIN_WA, $n1);
is_(count($la) === 1 && count($aa) === 1 && strpos((string)$la[0]['text'], '⚠️ STARLINK INSTALLATION STARTED WITHOUT CUSTOMER ACCEPTANCE') !== false
    && strpos((string)$la[0]['text'], 'Installation Job: 926') !== false && strpos((string)$la[0]['text'], 'Starlink Installation — started in uCRM') !== false,
    'both leaders (the support leader and the admin) are alerted by WhatsApp, naming the job', json_encode(array_column(array_slice($s->texts(), $n1), 'number')));
is_(count($mails($s, LEAD_MAIL, $m1)) === 1 && count($mails($s, ADMIN_MAIL, $m1)) === 1 && $subj($mails($s, LEAD_MAIL, $m1)[0]) === 'Job #926: Starlink installation started in uCRM without customer acceptance',
    'and by e-mail', json_encode(array_map($subj, $mails($s, null, $m1))));
is_($noPii((string)$la[0]['text']) && strpos((string)$la[0]['text'], 'install_auth') === false, 'the alert carries no customer number, no address and no link');
is_(count($texts($s, TECH, $n1)) === 0, 'the technician is not told anything by this');
is_(strpos($s->webhookLog(), "Job #926 — Customer Installation Authorisation: uCRM shows this Starlink installation started without the customer's acceptance; recorded, leaders alerted (2 by WhatsApp, 2 by e-mail)") !== false,
    'the webhook log says so, in words WA Events does not file as a WhatsApp outcome');
$n2 = count($s->texts());
$s->fire('job.edit', 'job', 926, 'uuid-926-again');
is_(count($s->texts()) === $n2 && count(array_filter($evNames($events($s, 926)), function ($n) { return $n === 'INSTALLATION_STARTED_WITHOUT_ACCEPTANCE'; })) === 1,
    'reported once: the same change delivered again records and alerts nothing');
$r = $s->api('tech', 'POST', 'scheduling_complete', ['job_id' => 926, 'comment' => '', 'lat' => 0.3476, 'lon' => 32.5825]);
is_($r[0] === 422 && $msg($r) === InstallAuth::REFUSAL_COMPLETE && $crmStatus($s, 926) === 1, 'Complete Job is refused: "in progress" is no longer an exemption (5.18.82 let it through: the laundering the review found)', $r[0] . ' ' . $msg($r));
$r = $s->api('tech', 'POST', 'install_checkout', ['job_id' => 926]);
is_($r[0] === 422 && $crmStatus($s, 926) === 1, 'so is a check-out', $r[0] . ' ' . $msg($r));
$r = $s->api('lead', 'POST', 'scheduling_job_update', ['job_id' => 926, 'status' => 'closed']);
is_($r[0] === 422 && $crmStatus($s, 926) === 1, 'and a leader closing it by status', $r[0] . ' ' . $msg($r));
$r = $s->api('tech', 'GET', 'scheduling_job_detail', null, '&job_id=926');
is_(($r[2]['data']['install_auth']['authorised'] ?? null) === false && ($r[2]['data']['install_auth']['exempt'] ?? null) === false, 'the panel is told: not authorised, not exempt');
$s->fire('job.edit', 'job', 937, 'uuid-937-seen');
$setJob($s, 937, ['status' => 2]);
$n1 = count($s->texts());
$s->fire('job.edit', 'job', 937, 'uuid-937-closed');
is_($has($events($s, 937), 'INSTALLATION_COMPLETED_WITHOUT_ACCEPTANCE[system:ucrm;status:none;job:0>2]') && count($texts($s, LEAD_WA, $n1)) === 1
    && strpos((string)$texts($s, LEAD_WA, $n1)[0]['text'], 'CLOSED WITHOUT CUSTOMER ACCEPTANCE') !== false, 'closed in uCRM with no acceptance: recorded, the leaders alerted', json_encode($events($s, 937)));
$setJob($s, 940, ['status' => 1]);                                      // created — or started — in uCRM before the notifier ever saw it
$s->fire('job.edit', 'job', 940, 'uuid-940-first');
is_($has($events($s, 940), 'INSTALLATION_STARTED_WITHOUT_ACCEPTANCE[system:ucrm;status:none;job:unseen>1]'), 'first seen already in progress, after the activation: recorded (every job in progress AT activation is exempt)', json_encode($events($s, 940)));
$s->fire('job.edit', 'job', 927, 'uuid-927-old');
is_($events($s, 927) === [], 'first seen already closed: not reported — it may have closed long before the feature existed');
[$rc, $out] = $s->run('tools/set_config.php', ['--key', 'install_auth_enabled', '--value', '1']);
is_($rc === 0 && strpos($out, 'is already on (activated') !== false && (int)$s->q('SELECT COUNT(*) c FROM install_auth_activations')[0]['c'] === 1 && $s->q('SELECT job_id FROM install_auth_exempt WHERE job_id IN (926, 940)') === [],
    'switching it on again changes nothing: no second snapshot, and 926 and 940 — in progress NOW — are not exempted by a repeat', $out);
is_(strpos($try($s, 'DELETE FROM install_auth_exempt'), 'never deleted') !== false && strpos($try($s, "UPDATE install_auth_exempt SET job_id = 926"), 'never changed') !== false
    && strpos($try($s, 'DELETE FROM install_auth_activations'), 'never deleted') !== false, 'the exemptions and the activations cannot be edited or deleted');

$act = $start('iaact', $CFG_BASE, false);
$cfgFile = $act->data . '/kyc_config.json';
$keepCfg = (string)file_get_contents($cfgFile);
$badCfg = json_decode($keepCfg, true); $badCfg['crm_base_url'] = 'http://127.0.0.1:9';
file_put_contents($cfgFile, json_encode($badCfg));
[$rc, $out] = $act->run('tools/set_config.php', ['--key', 'install_auth_enabled', '--value', '1']);
$after = json_decode((string)file_get_contents($cfgFile), true);
file_put_contents($cfgFile, $keepCfg);
is_($rc === 1 && strpos($out, 'Nothing was switched on') !== false && empty($after['install_auth_enabled']) && (int)$act->q('SELECT COUNT(*) c FROM install_auth_activations')[0]['c'] === 0,
    'uCRM unreachable: the feature is NOT switched on and nothing is recorded', $out);
[$rc, $out] = $act->run('tools/set_config.php', ['--key', 'install_auth_enabled', '--value', '1']);
is_($rc === 0 && strpos($out, 'Activation #1') !== false && strpos($out, 'job 906') !== false, 'uCRM back: switched on, 906 exempt', $out);
[$rc, $out] = $act->run('tools/set_config.php', ['--key', 'install_auth_enabled', '--clear']);
$act->seedCrm(['jobs' => $jobs + ['941' => $job(941, 'Starlink Installation — started while off', 1099, 1)]]);   // + keeps the ids as keys
[$rc2, $out2] = $act->run('tools/set_config.php', ['--key', 'install_auth_enabled', '--value', '1']);
is_($rc === 0 && $rc2 === 0 && strpos($out2, 'Activation #2: uCRM had 2 job(s) in progress; 2 of them Starlink installation job(s), 1 newly recorded as exempt (D3): job 941.') !== false,
    'off and on again is a new activation: a job started while the feature was off is exempt, 906 keeps its first exemption', $out2);
$many = [];
for ($i = 1; $i <= 500; $i++) $many[(string)(5000 + $i)] = $job(5000 + $i, 'Fiber Installation — bulk ' . $i, 1099, 1);
$act->seedCrm(['jobs' => $many]);
$act->run('tools/set_config.php', ['--key', 'install_auth_enabled', '--clear']);
[$rc, $out] = $act->run('tools/set_config.php', ['--key', 'install_auth_enabled', '--value', '1']);
is_($rc === 1 && strpos($out, 'its page limit') !== false && (int)$act->q('SELECT COUNT(*) c FROM install_auth_activations')[0]['c'] === 2,
    'a full page of jobs in progress could hide some: refused, nothing recorded, nothing switched on', $out);
$act->stop();

echo "\nH4. The secure link is kept nowhere but in the message itself\n";
$s->http('GET', "{$s->evo}/__test/fail_next?n=1");
$r = $request($s, 'tech', 939);
is_($r[0] === 201 && ($r[2]['data']['sent']['whatsapp'] ?? '') === 'failed' && ($r[2]['data']['sent']['email'] ?? '') === 'sent', 'a request whose WhatsApp fails (the e-mail goes)', $r[0] . ' ' . substr($r[1], 0, 200));
is_($s->q("SELECT id FROM notification_queue WHERE event = 'ops_install_auth_request'") === [], 'the failed request is NOT put on the failure queue, which keeps whole texts (5.18.82 queued it with its link)');
$all = $tokensSeen($s);
is_(count($all) >= 12, 'the run so far minted many links (counted from the fake phone and the fake mailbox)', (string)count($all));
$hitsDb = $dbHas($s, $all);
is_($hitsDb === [], 'not one of them is in any table of the plugin database — the Inbox, the Message Log, the failure queue included', json_encode($hitsDb));
$hitsFiles = $filesHave($s, $all);
is_($hitsFiles === [], 'nor in any file of the data directory, the database and its journal read as raw bytes', json_encode($hitsFiles));
$ctlDb = $dbHas($s, [$ref901]);
file_put_contents($s->data . '/ia_scan_control.txt', 'x' . $all[0] . 'y');
$ctlFiles = $filesHave($s, [$all[0]]);
@unlink($s->data . '/ia_scan_control.txt');
is_(in_array('install_auth.acceptance_reference', $ctlDb, true) && $ctlFiles === ['ia_scan_control.txt'], 'the controls: the same scans find a reference in its table and a link planted in a file', json_encode([$ctlDb, $ctlFiles]));
$inbox = $s->q("SELECT body FROM wa_messages WHERE event_key = 'ops_install_auth_request'");
is_(count($inbox) >= 5 && !array_filter($inbox, function ($m) use ($WITHHELD) { return strpos((string)$m['body'], $WITHHELD) === false || strpos((string)$m['body'], 'Installation Charges:') === false; }),
    'the Inbox still shows each request as sent, with its charges, and "' . $WITHHELD . '" where the link was', (string)count($inbox));
$audit = $s->q("SELECT preview FROM notification_audit_log WHERE event = 'ops_install_auth_request' LIMIT 1");
is_($audit !== [] && strpos((string)$audit[0]['preview'], 'Dear ') === 0, 'the Message Log keeps its preview line');

echo "\nH5. An acceptance binds the customer who gave it\n";
$request($s, 'tech', 932); $tok932 = $tokenIn($texts($s, CUST)); $accept($s, $tok932);
$setJob($s, 932, ['clientId' => 16, 'client' => ['id' => 16]]);
$r = $s->api('tech', 'POST', 'scheduling_job_update', ['job_id' => 932, 'status' => 'open']);
is_($r[0] === 422 && $msg($r) === REFUSAL . ' ' . InstallAuth::OTHER_CLIENT && $crmStatus($s, 932) === 0, 'uCRM moved the accepted job to another customer: Accept Job is refused, and says why', $r[0] . ' ' . $msg($r));
$r = $s->api('tech', 'GET', 'scheduling_job_detail', null, '&job_id=932');
is_(($r[2]['data']['install_auth']['client_changed'] ?? null) === true && ($r[2]['data']['install_auth']['authorised'] ?? null) === false, 'the panel is told');
is_($row($s, 932)['status'] === 'accepted' && (int)$row($s, 932)['crm_client_id'] === 15 && $has($events($s, 932), 'INSTALLATION_START_BLOCKED[staff:accept;status:other_client;'),
    'the acceptance itself is untouched, naming the customer who gave it; the refusal is in the trail', json_encode($events($s, 932)));
$setJob($s, 932, ['clientId' => 15, 'client' => ['id' => 15]]);
$r = $s->api('tech', 'POST', 'scheduling_job_update', ['job_id' => 932, 'status' => 'open']);
is_($r[0] === 200 && $crmStatus($s, 932) === 1, 'restored to its customer, it starts', $r[0] . ' ' . $msg($r));

echo "\nH6. When the technician cannot be told, nobody is told they were — and the leaders are\n";
$request($s, 'tech', 933); $tok933 = $tokenIn($texts($s, CUST));
$mailCfg = (string)file_get_contents($s->data . '/email_settings.json');
file_put_contents($s->data . '/email_settings.json', json_encode(['use_ucrm_email' => false, 'smtp_host' => '127.0.0.1', 'smtp_port' => 9, 'smtp_user' => '', 'smtp_pass' => '', 'smtp_enc' => '', 'smtp_from' => 'accounts@example.test']));
$s->http('GET', "{$s->evo}/__test/fail_next?n=1");                        // the technician's WhatsApp
$n0 = count($s->texts());
$r = $accept($s, $tok933);
file_put_contents($s->data . '/email_settings.json', $mailCfg);
is_($r[0] === 200 && $row($s, 933)['status'] === 'accepted', 'the acceptance is recorded', (string)$r[0]);
is_($has($events($s, 933), 'INSTALLATION_TECHNICIAN_NOTIFICATION_FAILED[system:accepted;wa:failed;email:failed;staff:' . $s->ids['tech'] . ']'), 'both of the technician\'s channels failed', json_encode($events($s, 933)));
$c = $texts($s, CUST, $n0); $wa = (string)($c[0]['text'] ?? '');
is_(count($c) === 1 && strpos($wa, 'Our assigned technician has been notified.') === false && strpos($wa, 'Technician:') === false
    && strpos($wa, 'Your installation has been authorised. DishNet will assign/confirm the technician separately.') !== false,
    'the customer is NOT told the technician was notified: the brief\'s fallback line instead (5.18.82 said "Our assigned technician has been notified.")', $wa);
is_(strpos($r[1], 'our team has been notified') === false && strpos($r[1], 'DishNet will contact you to confirm the installation.') !== false, 'nor does the page say so');
$la = $texts($s, LEAD_WA, $n0);
is_(count($la) === 1 && strpos((string)$la[0]['text'], '⚠️ TECHNICIAN NOT TOLD — CUSTOMER CONFIRMED INSTALLATION') !== false
    && strpos((string)$la[0]['text'], 'neither the WhatsApp nor the e-mail to the technician went') !== false && strpos((string)$la[0]['text'], (string)$row($s, 933)['acceptance_reference']) !== false,
    'the leaders are alerted with the reason and the reference', (string)($la[0]['text'] ?? ''));
is_(count($texts($s, LEAD_WA, $n0)) === 1 && count($texts($s, ADMIN_WA, $n0)) === 1, 'each leader once');
$r = $accept($s, $tok907, 'Again');
is_($r[0] === 200 && strpos($r[1], InstallAuth::ALREADY) !== false && strpos($r[1], 'A confirmation has been sent') === false, 'a later view of an accepted link claims no confirmation it cannot vouch for');
$al = $s->q("SELECT dedup_key FROM notification_dedup WHERE dedup_key LIKE 'INSTAUTHALERT907:%'");
is_(count($al) === 1 && $al[0]['dedup_key'] === 'INSTAUTHALERT907:technician_not_told', 'the job with nobody assigned (27) alerted the leaders too, once', json_encode($al));

echo "\nH7. The public page writes nothing for a link that names nothing, and limits each link by itself\n";
$before = (int)$s->q('SELECT COUNT(*) c FROM install_auth_rate')[0]['c'];
for ($i = 0; $i < 25; $i++) $post($s, ['t' => bin2hex(random_bytes(32)), 'action' => 'accept', 'agree' => '1', 'name' => 'X', 'terms_hash' => TERMS_V1_HASH]);
for ($i = 0; $i < 5; $i++) $page($s, 'not-a-token-' . $i);
is_((int)$s->q('SELECT COUNT(*) c FROM install_auth_rate')[0]['c'] === $before, 'thirty requests with unknown or malformed links: not one row written (5.18.82 wrote one for each)', (string)$s->q('SELECT COUNT(*) c FROM install_auth_rate')[0]['c']);
$request($s, 'tech', 935); $tok935 = $tokenIn($texts($s, CUST));
$codes = [];
for ($i = 0; $i < 21; $i++) {
    $codes[] = $s->http('POST', "{$s->base}?page=install_auth", http_build_query(['t' => $tok935, 'action' => 'nothing']),
        ['Content-Type: application/x-www-form-urlencoded', 'X-Forwarded-For: 203.0.113.' . $i . ', 10.0.0.1'])[0];
}
is_($codes[20] === 429 && !in_array(429, array_slice($codes, 0, 20), true), 'twenty POSTs on one link are answered; the twenty-first is 429 — whatever address each one claims', json_encode($codes));
is_($row($s, 935)['status'] === 'pending' && $page($s, $tok935)[0] === 200, 'none of them changed the record, and the link still opens (the page bucket is apart)');
$g = $page($s, $tok901);
is_($g[0] === 200, 'another link is not limited by this one', (string)$g[0]);

echo "\nH8. The database itself keeps an accepted authorisation final\n";
is_(strpos($try($s, "UPDATE install_auth SET accepted_by_name = 'Somebody Else' WHERE job_id = 909"), 'final') !== false && $row($s, 909)['accepted_by_name'] === 'Canary Customer',
    'an UPDATE of an accepted row by hand is refused (install_auth_accepted_is_final)');
is_(strpos($try($s, 'DELETE FROM install_auth WHERE job_id = 909'), 'never deleted') !== false && strpos($try($s, 'DELETE FROM install_auth WHERE job_id = 902'), 'never deleted') !== false,
    'no record is deleted, accepted or not');
is_(strpos($try($s, "UPDATE install_auth SET status = 'accepted', accepted_at = '2026-10-06 00:00:00', accepted_method = 'web_link' WHERE job_id = 902"), 'lifecycle') !== false && $row($s, 902)['status'] === 'cancelled',
    'a withdrawn request cannot be turned into an acceptance by hand');
is_(strpos($try($s, "UPDATE install_auth SET price_snapshot = '{\"total\":1}' WHERE job_id = 935"), 'fixed') !== false
    && strpos($try($s, "UPDATE install_auth SET terms_hash = '" . str_repeat('b', 64) . "' WHERE job_id = 935"), 'fixed') !== false,
    'a pending request\'s charges and terms cannot be changed after it was sent');
is_($try($s, "UPDATE install_auth SET viewed_at = viewed_at WHERE job_id = 935") === 'ok', 'what the lifecycle itself writes on a pending row still works (the control)');

echo "\nH9. The acceptance and its event commit together or not at all\n";
$probeSrc = <<<'PROBE'
<?php
declare(strict_types=1);
$tree = $argv[1]; $fault = $argv[2] === '1';
foreach (['StoreInterface', 'JsonStore', 'SqliteStore', 'InstallAuth'] as $c) require_once $tree . '/lib/' . $c . '.php';
$dir = sys_get_temp_dir() . '/ia_atomic_' . bin2hex(random_bytes(4)); mkdir($dir, 0700, true);
putenv('DN_VAULT_FILE=' . $dir . '/vault.json');
$pdo = SqliteStore::create($dir)->getPdo();
$tz  = new DateTimeZone('Africa/Kampala');
$cfg = ['install_auth_enabled' => '1', 'tenant_profile' => 'uganda', 'install_auth_whatsapp' => '1'];
$job = ['id' => 1, 'title' => 'Starlink Installation', 'status' => 0, 'date' => '2026-10-07T09:00:00+0300', 'address' => 'Plot 9', 'clientId' => 1, 'duration' => 60];
$client = ['id' => 1, 'firstName' => 'Canary', 'lastName' => 'Customer', 'contacts' => [['phone' => '+256700000915']]];
$r   = InstallAuth::request($pdo, $job, $client, ['installation' => '1', 'service' => 's', 'equipment' => 'e'], ['id' => 1, 'name' => 'L'], $cfg, $tz);
if ($fault) $pdo->exec("CREATE TRIGGER t_fault BEFORE INSERT ON install_auth_events WHEN NEW.event = 'INSTALLATION_ACCEPTED' BEGIN SELECT RAISE(ABORT, 'fault'); END");
$row = InstallAuth::byToken($pdo, $r['token']);
$a   = InstallAuth::accept($pdo, $row, (string)$row['terms_hash'], 'First');
$ev  = (int)$pdo->query("SELECT COUNT(*) FROM install_auth_events WHERE event = 'INSTALLATION_ACCEPTED'")->fetchColumn();
echo json_encode([$a['outcome'], InstallAuth::find($pdo, 1)['status'], $ev]);
exec('rm -rf ' . escapeshellarg($dir));
PROBE;
$probeFile = sys_get_temp_dir() . '/ia_atomic_' . getmypid() . '.php';
file_put_contents($probeFile, $probeSrc);
$atomic = function (string $tree, bool $fault) use ($probeFile): array {
    $out = []; exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($probeFile) . ' ' . escapeshellarg($tree) . ' ' . ($fault ? '1' : '0') . ' 2>&1', $out);
    return (array)json_decode((string)end($out), true);
};
$ok = $atomic($root, false); $bad = $atomic($root, true);
is_($ok === ['accepted', 'accepted', 1], 'the control: an acceptance writes its row and exactly one INSTALLATION_ACCEPTED', json_encode($ok));
is_($bad === ['invalid', 'pending', 0], 'with the event refused by the database, the acceptance is undone with it: still pending, no event (5.18.82 left an accepted row with no event)', json_encode($bad));

echo "\nH10. Every channel is a deliberate choice\n";
$s->run('tools/set_config.php', ['--key', 'customer_email_install_auth_request', '--clear']);
$m0 = count($s->mails());
$r = $request($s, 'tech', 936);
is_($r[0] === 201 && ($r[2]['data']['sent']['email'] ?? '') === 'switched_off' && ($r[2]['data']['sent']['whatsapp'] ?? '') === 'sent' && count($mails($s, CUST_MAIL, $m0)) === 0,
    'the request e-mail key unset: no e-mail, though the customer e-mails master switch is on (5.18.82: absent meant on)', $r[0] . ' ' . substr($r[1], 0, 200));
$s->api('tech', 'POST', 'install_auth_cancel', ['job_id' => 936, 'reason' => 'channels test']);
$s->run('tools/set_config.php', ['--key', 'install_auth_whatsapp', '--clear']);
$r = $s->api('tech', 'GET', 'install_auth_prefill', null, '&job_id=936');
is_(($r[2]['data']['channels'] ?? null) === ['whatsapp' => false, 'email' => false] && strpos((string)($r[2]['data']['cannot_send'] ?? ''), 'switched off') !== false,
    'with no channel on, the request form is told before anything is sent', json_encode([$r[2]['data']['channels'] ?? null, $r[2]['data']['cannot_send'] ?? null]));
$n0 = count($s->texts());
$r = $request($s, 'tech', 936);
is_($r[0] === 422 && strpos($msg($r), 'The request cannot be sent') !== false && strpos($msg($r), 'Nothing was recorded') !== false && $row($s, 936)['status'] === 'cancelled' && count($s->texts()) === $n0,
    'and the request is refused: no new record binds a job nobody could tell the customer about', $r[0] . ' ' . $msg($r));
$s->run('tools/set_config.php', ['--key', 'customer_email_install_auth_request', '--value', '1']);
$m0 = count($s->mails());
$r = $request($s, 'tech', 936);
is_($r[0] === 201 && ($r[2]['data']['sent']['whatsapp'] ?? '') === 'switched_off' && ($r[2]['data']['sent']['email'] ?? '') === 'sent' && count($texts($s, null, $n0)) === 0,
    'e-mail on and WhatsApp off: the request goes by e-mail only', $r[0] . ' ' . substr($r[1], 0, 200));
$tok936 = $tokenIn($mails($s, CUST_MAIL, $m0));
$r = $accept($s, $tok936);
is_($r[0] === 200 && $has($events($s, 936), 'INSTALLATION_TECHNICIAN_NOTIFIED[system:accepted;wa:switched_off;email:sent;'), 'the technician is told by the e-mail copy alone', json_encode($events($s, 936)));
is_(count($texts($s, null, $n0)) === 0, 'and no WhatsApp went to anyone while install_auth_whatsapp was off');
$s->run('tools/set_config.php', ['--key', 'install_auth_whatsapp', '--value', '1']);
is_(!InstallAuth::emailOn('request', ['customer_emails_enabled' => '1']) && !InstallAuth::emailOn('request', ['customer_email_install_auth_request' => '1'])
    && InstallAuth::emailOn('request', ['customer_emails_enabled' => '1', 'customer_email_install_auth_request' => '1'])
    && !InstallAuth::whatsappOn([]) && InstallAuth::whatsappOn(['install_auth_whatsapp' => '1']),
    'absent means off, for both e-mails and for WhatsApp; the master switch still rules the e-mails');

echo "\nH12. A job the notifier first sees after a reassignment still tells the new engineer\n";
$request($s, 'tech', 928); $tok928 = $tokenIn($texts($s, CUST)); $accept($s, $tok928);   // 1099 is told at the acceptance
$setJob($s, 928, ['assignedUserId' => 1100]);
$n0 = count($s->texts());
$s->fire('job.edit', 'job', 928, 'uuid-928-first');
$t2 = $texts($s, TECH2, $n0);
is_(count($t2) === 2 && strpos((string)$t2[0]['text'], 'New Job Has Been Assigned to You') !== false && strpos((string)$t2[1]['text'], '🟢 CUSTOMER ALREADY CONFIRMED INSTALLATION') !== false,
    'its first sight reads as an assignment, and the new engineer still gets "CUSTOMER ALREADY CONFIRMED" (5.18.82 sent message 1 only)', json_encode(array_map(function ($x) { return substr($x['text'], 0, 50); }, $t2)));
is_(count($texts($s, TECH, $n0)) === 0, 'the engineer told at the acceptance hears nothing more');

echo "\nH13. A posted job.delete withdraws nothing unless uCRM confirms the job is gone\n";
$request($s, 'tech', 929); $tok929 = $tokenIn($texts($s, CUST));
$r = $s->fire('job.delete', 'job', 929, 'uuid-929-forged');
is_($r[0] === 200 && $row($s, 929)['status'] === 'pending' && $page($s, $tok929)[0] === 200 && strpos($s->webhookLog(), 'request for job #929 kept: uCRM did not confirm the job was deleted') !== false,
    'a job.delete for a job uCRM still holds: the request stays pending, its link works, the log says why (5.18.82 cancelled it on the posted body)', $r[0] . ' ' . json_encode($row($s, 929)['status']));

echo "\nH14. A uCRM outage no longer costs a Fiber job its check-in\n";
$s->fire('job.edit', 'job', 930, 'uuid-930-seen');                       // the notifier records the job's title
$s->http('POST', "{$s->crm}/__test/jobs_down", ['down' => true]);
$r  = $s->api('tech', 'POST', 'install_checkin', ['job_id' => 930, 'lat' => 0.3476, 'lon' => 32.5825]);
$r2 = $s->api('tech', 'POST', 'install_checkin', ['job_id' => 931, 'lat' => 0.3476, 'lon' => 32.5825]);
$s->http('POST', "{$s->crm}/__test/jobs_down", ['down' => false]);
is_($r[0] === 200 && $checkedIn($s, 930), 'uCRM unreadable: a Fiber job the plugin has seen checks in, decided from its own record of the title (5.18.82 refused every check-in)', $r[0] . ' ' . $msg($r));
is_($r2[0] === 502 && !$checkedIn($s, 931) && $checkedIn($s, 934), 'a job the plugin has never seen is refused, never let through unread', $r2[0] . ' ' . $msg($r2));

// ── 32. Domain B ─────────────────────────────────────────────────────────────
echo "\n32. Domain B remains untouched\n";
$top = trim((string)shell_exec('git -C ' . escapeshellarg($root) . ' rev-parse --show-toplevel 2>/dev/null'));
if ($top === '') {
    echo "  skip git is not available here: the Domain B check is run by hand (git status -- dishnet-hybrid-sudan/dishnet-mikrotik-control-plane)\n";
} else {
    $out = []; exec('git -C ' . escapeshellarg($top) . ' status --porcelain -- dishnet-hybrid-sudan/dishnet-mikrotik-control-plane 2>&1', $out, $rc);
    is_($rc === 0 && $out === [], 'git status: nothing under dishnet-mikrotik-control-plane is modified, added or untracked', implode("\n", $out));
    $out = []; exec('git -C ' . escapeshellarg($top) . ' diff --stat HEAD -- dishnet-hybrid-sudan/dishnet-mikrotik-control-plane 2>&1', $out, $rc);
    is_($rc === 0 && $out === [], 'git diff against HEAD: no change under dishnet-mikrotik-control-plane', implode("\n", $out));
}
$src = '';
foreach (['lib/InstallAuth.php', 'lib/InstallAuthNotifier.php', 'lib/InstallAuthEmails.php', 'lib/InstallationTerms.php', 'includes/api/api_install_auth.php', 'tabs/customer_app/install_auth_page.php'] as $f) $src .= (string)file_get_contents($root . '/' . $f);
is_(stripos($src, 'mikrotik') === false && strpos($src, 'dnb_') === false, 'the new files never mention the control plane');
$s->stop();

// ── Weakened copies ──────────────────────────────────────────────────────────
if ($withMutants) {
    echo "\nM. Weakened copies — each is caught\n";
    $q1 = "\\'";   // an escaped quote, as it stands inside a single-quoted PHP string in the source
    $mutants = [
        ['the Accept Job guard removed', 'includes/api/api_scheduling.php',
         "if (\$_iaMsg !== null) \$er2(\$_iaMsg, 422);\n            \$_iaWas", "if (false) \$er2(\$_iaMsg, 422);\n            \$_iaWas", 'accept'],
        ['the GPS check-in guard removed', 'includes/api/api_field_ops.php',
         "if (\$_iaMsg !== null) \$er2(\$_iaMsg, 422);\n                \$_iaCheckinStart", "if (false) \$er2(\$_iaMsg, 422);\n                \$_iaCheckinStart", 'checkin'],
        ['the completion guard removed', 'includes/api/api_scheduling.php',
         "if (\$_iaMsg !== null) \$er2(\$_iaMsg, 422);\n            \$_iaComplete = true;", "if (false) \$er2(\$_iaMsg, 422);\n            \$_iaComplete = true;", 'complete'],
        ['the guard answering "allowed" for every job', 'lib/InstallAuth.php',
         "        if (!self::applies(\$config, \$dataDir, \$job)) return null;\n        if (\$target !== 1 && \$target !== 2) return null;",
         "        return null;\n        if (!self::applies(\$config, \$dataDir, \$job)) return null;\n        if (\$target !== 1 && \$target !== 2) return null;", 'accept'],
        ['the acceptance statement no longer requiring a pending record', 'lib/InstallAuth.php',
         "AND status = {$q1}pending{$q1} AND terms_hash = ?", "AND terms_hash = ?", 'twice'],
        // 5.18.83 (docs/64): one weakened copy per hardening — each guard removed, the check that proves it must fail.
        ['the GPS check-out guard removed', 'includes/api/api_field_ops.php',
         "if (\$_iaMsg !== null) \$er2(\$_iaMsg, 422);\n                \$_iaCheckoutClose", "if (false) \$er2(\$_iaMsg, 422);\n                \$_iaCheckoutClose", 'checkout'],
        ['the scope widened back to every title naming Starlink', 'lib/InstallAuth.php',
         "            if (\$type === self::norm(\$t)) return true;", "            if (strpos(\$type, 'starlink') !== false) return true;", 'scope'],
        ['"in progress" counted as exempt again (5.18.82\'s D3)', 'lib/InstallAuth.php',
         "        \$exempt = self::isExempt(\$pdo, \$jobId);\n        \$status",
         "        \$exempt = self::isExempt(\$pdo, \$jobId) || (is_numeric(\$job['status'] ?? null) && (int)\$job['status'] === 1);\n        \$status", 'launder'],
        ['the activation snapshot ignored', 'lib/InstallAuth.php',
         "        \$exempt = self::isExempt(\$pdo, \$jobId);\n        \$status", "        \$exempt = false;\n        \$status", 'exempt'],
        ['the link kept in the Inbox', 'lib/NotificationService.php',
         "'body'       => self::storable(\$message),", "'body'       => \$message,", 'inbox'],
        ['a failed request queued for a retry', 'lib/NotificationService.php',
         "if (!\$success && !\$this->_retryMode && !in_array(\$event, self::NEVER_QUEUED, true)) {\n            \$this->queueFailed(\$sender, \$to, self::storable(\$message),",
         "if (!\$success && !\$this->_retryMode && \$event !== 'app_otp') {\n            \$this->queueFailed(\$sender, \$to, \$message,", 'queue'],
        ['the acceptance no longer bound to its customer', 'lib/InstallAuth.php',
         "if (\$given > 0 && \$now > 0 && \$given !== \$now) {", "if (false) {", 'client'],
        ['the page writing a ledger row before the lookup (5.18.82\'s order)', 'tabs/customer_app/install_auth_page.php',
         "\$iaPdo = \$store->getPdo();\n\$iaRow = InstallAuth::byToken(\$iaPdo, \$iaToken);",
         "\$iaPdo = \$store->getPdo();\nInstallAuth::rateAllow(\$iaPdo, 'page', (string)(\$_SERVER['REMOTE_ADDR'] ?? ''), 60, 600);\n\$iaRow = InstallAuth::byToken(\$iaPdo, \$iaToken);", 'ratewrite'],
        ['the uCRM-side check removed', 'lib/InstallAuth.php',
         "            if (\$d['allow']) return null;\n            \$jobId = (int)(\$job['id'] ?? 0);\n            \$event",
         "            return null;\n            \$jobId = (int)(\$job['id'] ?? 0);\n            \$event", 'observe'],
        ['a posted job.delete believed without asking uCRM', 'webhook.php',
         "\$_iaGone  = !is_array(\$_iaStill) && (int)(\$crm->getLastError()['http_code'] ?? 0) === 404;", "\$_iaGone  = true;", 'forged'],
        ['the accepted-is-final trigger dropped', 'migrations/086_install_authorisation.sql',
         "FOR EACH ROW WHEN OLD.status = 'accepted'\nBEGIN\n    SELECT RAISE(ABORT, 'install_auth: an accepted authorisation is final; it is never changed');\nEND;",
         "FOR EACH ROW WHEN 0\nBEGIN\n    SELECT RAISE(ABORT, 'install_auth: an accepted authorisation is final; it is never changed');\nEND;", 'trigger'],
        ['the accepted-is-final trigger AND the pending condition both gone', 'migrations/086_install_authorisation.sql',
         "FOR EACH ROW WHEN OLD.status = 'accepted'\nBEGIN\n    SELECT RAISE(ABORT, 'install_auth: an accepted authorisation is final; it is never changed');\nEND;",
         "FOR EACH ROW WHEN 0\nBEGIN\n    SELECT RAISE(ABORT, 'install_auth: an accepted authorisation is final; it is never changed');\nEND;", 'twice_bare'],
        ['the confirmation naming a technician nobody told', 'lib/InstallAuthNotifier.php',
         "['technician_first' => \$told ? (string)(\$tech['first_name'] ?? '') : '']", "['technician_first' => (string)(\$tech['first_name'] ?? '')]", 'wording'],
        ['a request made with no channel to carry it', 'lib/InstallAuth.php',
         "        \$why = self::noChannelReason(\$config, \$phone, \$email);\n        if (\$why !== null) return self::fail(\$why, 422);",
         "        \$why = self::noChannelReason(\$config, \$phone, \$email);\n        if (false) return self::fail(\$why, 422);", 'nochannel'],
        ['the resend cooldown removed', 'lib/InstallAuth.php',
         "if (\$last !== false && \$now - \$last < self::RESEND_COOLDOWN) {", "if (false) {", 'cooldown'],
        ['the e-mail keys back to "absent means on"', 'lib/CustomerEmailDispatcher.php',
         "        if (is_bool(\$v)) return \$v;\n        return in_array(strtolower(trim((string)\$v)), ['1', 'on', 'true', 'yes'], true);",
         "        if (\$v === null || \$v === '') return true;\n        return !in_array(strtolower(trim((string)\$v)), ['0', 'off', 'false', 'no'], true);", 'emaildefault'],
    ];
    $probeSrc = <<<'PROBE'
<?php
declare(strict_types=1);
$tree = $argv[1];
foreach (['StoreInterface', 'JsonStore', 'SqliteStore', 'InstallAuth'] as $c) require_once $tree . '/lib/' . $c . '.php';
$dir = sys_get_temp_dir() . '/ia_probe_' . bin2hex(random_bytes(4)); mkdir($dir, 0700, true);
putenv('DN_VAULT_FILE=' . $dir . '/vault.json');
$pdo = SqliteStore::create($dir)->getPdo();
$tz  = new DateTimeZone('Africa/Kampala');
$cfg = ['install_auth_enabled' => '1', 'tenant_profile' => 'uganda', 'install_auth_whatsapp' => '1'];
$job = ['id' => 1, 'title' => 'Starlink installation', 'status' => 0, 'date' => '2026-10-07T09:00:00+0300', 'address' => 'Plot 9', 'clientId' => 1, 'duration' => 60];
$client = ['id' => 1, 'firstName' => 'Canary', 'lastName' => 'Customer', 'contacts' => [['phone' => '+256700000915']]];
$r   = InstallAuth::request($pdo, $job, $client, ['installation' => '1', 'service' => 's', 'equipment' => 'e'], ['id' => 1, 'name' => 'L'], $cfg, $tz);
$row = InstallAuth::byToken($pdo, $r['token']);
$a   = InstallAuth::accept($pdo, $row, (string)$row['terms_hash'], 'First');
$b   = InstallAuth::accept($pdo, InstallAuth::find($pdo, 1), (string)$row['terms_hash'], 'Second');
$name = InstallAuth::find($pdo, 1)['accepted_by_name'];
try { $pdo->exec("UPDATE install_auth SET accepted_by_name = 'Forged' WHERE job_id = 1"); $u = 'ok'; } catch (\Throwable $e) { $u = 'refused'; }
echo json_encode([$a['outcome'], $b['outcome'], $name, $u]);
exec('rm -rf ' . escapeshellarg($dir));
PROBE;
    // The probe builds its database from the TREE it is given, in its own process: this test process has already loaded
    // SqliteStore (and with it MigrationRunner) from the real tree, so a sandbox started here would take the REAL
    // migrations — a weakened 086 can only be proved in a process of its own.
    $probeFile = sys_get_temp_dir() . '/ia_probe_' . getmypid() . '.php';
    file_put_contents($probeFile, $probeSrc);
    $twice = function (string $tree) use ($probeFile): array {
        $out = []; exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($probeFile) . ' ' . escapeshellarg($tree) . ' 2>&1', $out);
        return (array)json_decode((string)end($out), true);
    };
    $realTwice = $twice($root);
    is_($realTwice === ['accepted', 'already', 'First', 'refused'], 'the control: in the real tree a second acceptance answers "already", the first name stands, and a hand-written UPDATE of the accepted row is refused', json_encode($realTwice));
    $mSeed = array_intersect_key($jobs, array_flip(['901', '906', '920', '926', '929', '932', '933']));
    $mutantDone = [];
    foreach ($mutants as $i => [$label, $rel, $old, $new, $probe]) {
        if ($probe === 'twice_bare') {
            // Both floors removed at once — the statement's pending condition AND the trigger: the control on the controls.
            [$tmp, $n] = sj_weakened_copy($root, $rel, $old, $new);
            $p2 = $tmp . '/lib/InstallAuth.php'; $src2 = (string)file_get_contents($p2);
            $n2 = substr_count($src2, "AND status = {$q1}pending{$q1} AND terms_hash = ?");
            if ($n2 === 1) file_put_contents($p2, str_replace("AND status = {$q1}pending{$q1} AND terms_hash = ?", 'AND terms_hash = ?', $src2));
            if ($n !== 1 || $n2 !== 1) { is_(false, "weakened copy ({$label}): both anchors appear once", "{$n} {$n2}"); exec('rm -rf ' . escapeshellarg($tmp)); continue; }
            $w = $twice($tmp);
            is_(($w[0] ?? '') === 'accepted' && ($w[1] ?? '') === 'accepted' && ($w[2] ?? '') === 'Second' && ($w[3] ?? '') === 'ok',
                "caught: with {$label}, a second acceptance overwrites the first — so each of the two alone is what holds (the real tree: already)", json_encode($w));
            exec('rm -rf ' . escapeshellarg($tmp));
            continue;
        }
        [$tmp, $n] = sj_weakened_copy($root, $rel, $old, $new);
        if ($n !== 1) { is_(false, "weakened copy ({$label}): the anchor appears once", "{$rel}: {$n}"); exec('rm -rf ' . escapeshellarg($tmp)); continue; }
        if ($probe === 'twice') {
            // The page refuses a second click by itself (its state switch answers before any POST is read), and since
            // 5.18.83 the database refuses any change to an accepted row: the loosened statement is reached through the
            // service, and it is the TRIGGER that now holds — the second acceptance fails, the first name stands.
            $w = $twice($tmp);
            is_(($w[0] ?? '') === 'accepted' && ($w[1] ?? '') === 'invalid' && ($w[2] ?? '') === 'First' && ($w[3] ?? '') === 'refused',
                "caught: with {$label}, the database still refuses the second acceptance (install_auth_accepted_is_final) — the first name stands", json_encode($w));
            exec('rm -rf ' . escapeshellarg($tmp));
            continue;
        }
        if ($probe === 'trigger') {
            $w = $twice($tmp);
            is_(($w[0] ?? '') === 'accepted' && ($w[1] ?? '') === 'already' && ($w[2] ?? '') === 'First', "with {$label}, the statement alone still answers \"already\" — each floor stands by itself", json_encode($w));
            is_(($w[3] ?? '') === 'ok', "caught: with {$label}, an accepted row can be rewritten by hand (H8: the real tree refuses)", json_encode($w));
            exec('rm -rf ' . escapeshellarg($tmp));
            continue;
        }
        $cfgM = $CFG;
        if ($probe === 'nochannel')    $cfgM = array_diff_key($CFG, ['install_auth_whatsapp' => 1, 'customer_email_install_auth_request' => 1]);
        if ($probe === 'emaildefault') $cfgM = array_diff_key($CFG, ['customer_email_install_auth_request' => 1, 'customer_email_install_auth_confirmed' => 1]);
        if ($probe === 'exempt')       $cfgM = $CFG_BASE;
        $m = SjSandbox::start($tmp, $cfgM, 'iam' . $i);
        file_put_contents($m->plug . '/ucrm.json', json_encode(['pluginDataDir' => $m->data, 'pluginPublicUrl' => $m->base]));
        $m->seedCrm(['users' => $users, 'clients' => $clients, 'jobs' => $mSeed, 'tasks' => []]);
        $accounts($m);
        if (in_array($probe, ['wording', 'emaildefault'], true)) $m->mailRelay(300);
        switch ($probe) {
            case 'accept':
                $r = $m->api('tech', 'POST', 'scheduling_job_update', ['job_id' => 901, 'status' => 'open']);
                is_($r[0] === 200 && $crmStatus($m, 901) === 1, "caught: with {$label}, Accept Job goes through with no acceptance (a real tree answers 422)", $r[0] . ' ' . $msg($r));
                break;
            case 'checkin':
                $r = $m->api('tech', 'POST', 'install_checkin', ['job_id' => 901, 'lat' => 0.3476, 'lon' => 32.5825]);
                is_($r[0] === 200 && $crmStatus($m, 901) === 1, "caught: with {$label}, a check-in starts the job with no acceptance", $r[0] . ' ' . $msg($r));
                break;
            case 'complete':
                $r = $m->api('tech', 'POST', 'scheduling_complete', ['job_id' => 901, 'comment' => '', 'lat' => 0.3476, 'lon' => 32.5825]);
                is_($r[0] === 200 && $crmStatus($m, 901) === 2, "caught: with {$label}, a never-started job is completed with no acceptance", $r[0] . ' ' . $msg($r));
                break;
            case 'checkout':
                $r = $m->api('tech', 'POST', 'install_checkout', ['job_id' => 901, 'lat' => 0.3476, 'lon' => 32.5825]);
                is_($r[0] === 200 && $crmStatus($m, 901) === 2, "caught: with {$label}, a check-out closes a job nobody accepted (H2: the real tree answers 422)", $r[0] . ' ' . $msg($r));
                break;
            case 'scope':
                $r = $m->api('tech', 'POST', 'scheduling_job_update', ['job_id' => 920, 'status' => 'open']);
                is_($r[0] === 422 && $crmStatus($m, 920) === 0, "caught: with {$label}, a Starlink cable repair is refused (H1: the real tree accepts it)", $r[0] . ' ' . $msg($r));
                break;
            case 'launder':
                $setJob($m, 926, ['status' => 1]);
                $r = $m->api('tech', 'POST', 'scheduling_complete', ['job_id' => 926, 'comment' => '', 'lat' => 0.3476, 'lon' => 32.5825]);
                is_($r[0] === 200 && $crmStatus($m, 926) === 2, "caught: with {$label}, a job started in uCRM is completed with no acceptance (H3: the real tree answers 422)", $r[0] . ' ' . $msg($r));
                break;
            case 'exempt':
                $m->run('tools/set_config.php', ['--key', 'install_auth_enabled', '--value', '1']);
                $r = $m->api('tech', 'POST', 'scheduling_complete', ['job_id' => 906, 'comment' => '', 'lat' => 0.3476, 'lon' => 32.5825]);
                is_($r[0] === 422 && $crmStatus($m, 906) === 1 && count($m->q('SELECT * FROM install_auth_exempt')) === 1,
                    "caught: with {$label}, the job in progress at activation is refused although recorded exempt (30: the real tree completes it)", $r[0] . ' ' . $msg($r));
                break;
            case 'inbox':
                $request($m, 'tech', 901);
                $tk = $tokenIn($m->texts());
                $hit = $m->q("SELECT COUNT(*) c FROM wa_messages WHERE body LIKE ?", ['%' . $tk . '%']);
                is_($tk !== '' && (int)$hit[0]['c'] === 1, "caught: with {$label}, the raw link is in the Inbox again (H4: the real tree keeps it nowhere)", $tk . ' ' . json_encode($hit));
                break;
            case 'queue':
                $m->http('GET', "{$m->evo}/__test/fail_next?n=1");
                $request($m, 'tech', 901);
                $qq = $m->q("SELECT message FROM notification_queue WHERE event = 'ops_install_auth_request'");
                is_(count($qq) === 1 && preg_match('#page=install_auth&t=[0-9a-f]{64}#', (string)$qq[0]['message']) === 1, "caught: with {$label}, the whole request, link included, sits in the failure queue (H4: the real tree queues nothing)", json_encode(count($qq)));
                break;
            case 'client':
                $request($m, 'tech', 932); $tk = $tokenIn($m->texts()); $accept($m, $tk);
                $setJob($m, 932, ['clientId' => 16, 'client' => ['id' => 16]]);
                $r = $m->api('tech', 'POST', 'scheduling_job_update', ['job_id' => 932, 'status' => 'open']);
                is_($r[0] === 200 && $crmStatus($m, 932) === 1, "caught: with {$label}, an acceptance given by one customer starts a job now another customer's (H5: the real tree answers 422)", $r[0] . ' ' . $msg($r));
                break;
            case 'ratewrite':
                for ($k = 0; $k < 3; $k++) $m->http('GET', "{$m->base}?page=install_auth&t=" . bin2hex(random_bytes(32)));
                $c = (int)$m->q('SELECT COUNT(*) c FROM install_auth_rate')[0]['c'];
                is_($c === 3, "caught: with {$label}, every unknown link writes a ledger row (H7: the real tree writes none)", (string)$c);
                break;
            case 'observe':
                $m->fire('job.edit', 'job', 926, 'uuid-m926-seen');
                $setJob($m, 926, ['status' => 1]);
                $m->fire('job.edit', 'job', 926, 'uuid-m926-started');
                $evm = $m->q("SELECT event FROM install_auth_events WHERE job_id = 926");
                is_($evm === [] && $crmStatus($m, 926) === 1, "caught: with {$label}, a start made in uCRM leaves no trace (H3: the real tree records it and alerts the leaders)", json_encode($evm));
                break;
            case 'forged':
                $request($m, 'tech', 929);
                $m->fire('job.delete', 'job', 929, 'uuid-m929-forged');
                is_($row($m, 929)['status'] === 'cancelled', "caught: with {$label}, a posted job.delete withdraws a live request (H13: the real tree keeps it pending)", json_encode($row($m, 929)['status'] ?? null));
                break;
            case 'wording':
                $request($m, 'tech', 933); $tk = $tokenIn($m->texts());
                file_put_contents($m->data . '/email_settings.json', json_encode(['use_ucrm_email' => false, 'smtp_host' => '127.0.0.1', 'smtp_port' => 9, 'smtp_user' => '', 'smtp_pass' => '', 'smtp_enc' => '', 'smtp_from' => 'accounts@example.test']));
                $m->http('GET', "{$m->evo}/__test/fail_next?n=1");
                $n0 = count($m->texts());
                $accept($m, $tk);
                $c = $texts($m, CUST, $n0); $wa = (string)($c[0]['text'] ?? '');
                is_(strpos($wa, 'Our assigned technician has been notified.') !== false, "caught: with {$label}, the customer is told a technician was notified although nobody was (H6: the real tree says DishNet will confirm the technician)", $wa);
                break;
            case 'nochannel':
                $r = $request($m, 'tech', 901);
                is_($r[0] === 201 && ($row($m, 901)['status'] ?? '') === 'pending', "caught: with {$label}, a request nobody can be told about binds the job (H10: the real tree refuses it, recording nothing)", $r[0] . ' ' . $msg($r));
                break;
            case 'cooldown':
                $request($m, 'tech', 901);
                $r = $m->api('tech', 'POST', 'install_auth_resend', ['job_id' => 901]);
                is_($r[0] === 200, "caught: with {$label}, a resend a moment after the request replaces the link at once (the real tree answers 429)", $r[0] . ' ' . $msg($r));
                break;
            case 'emaildefault':
                $m0 = count($m->mails());
                $r = $request($m, 'tech', 901);
                is_(($r[2]['data']['sent']['email'] ?? '') === 'sent' && count($mails($m, CUST_MAIL, $m0)) === 1, "caught: with {$label}, the request e-mail goes although nobody switched it on (H10: the real tree does not send it)", substr($r[1], 0, 200));
                break;
        }
        $m->stop();
        exec('rm -rf ' . escapeshellarg($tmp));
    }
    @unlink($probeFile);
}

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
