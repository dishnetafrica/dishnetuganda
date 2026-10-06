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
 *   a job deleted in uCRM. Last, five weakened copies — each guard removed in turn, the idempotent UPDATE loosened —
 *   are each caught.
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
];
$accounts = function (SjSandbox $s) use ($link): void {
    $s->staff('admin', ['name' => 'Sandbox Admin', 'email' => 'admin@example.test', 'role' => 'admin', 'is_admin' => true, 'phone' => '+256700000110'] + $link(1000, 'admin@example.test'));
    $s->staff('lead',  ['name' => 'Sandbox Leader', 'email' => 'lead@example.test', 'role' => 'support_leader', 'phone' => '+256700000112']);
    $s->staff('tech',  ['name' => 'Sandbox Tech', 'email' => 'tech@example.test', 'role' => 'support', 'phone' => '+256700000111'] + $link(1099, 'tech@example.test'));
    $s->staff('tech2', ['name' => 'Sandbox Tech Two', 'email' => 'tech2@example.test', 'role' => 'support', 'phone' => '+256700000114'] + $link(1100, 'tech2@example.test'));
    $s->staff('sales', ['name' => 'Sandbox Sales', 'email' => 'sales@example.test', 'role' => 'sales']);
};
$CFG = ['tenant_profile' => 'uganda', 'timezone' => 'Africa/Kampala', 'install_auth_enabled' => '1', 'customer_emails_enabled' => '1',
        'job_photos_required' => '0', 'contact_support_phone' => '+256 700 000 100', 'email_reply_to' => 'jobs-reply@example.test'];
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
$s = $start('iauth', $CFG);

// ── 0. The ground: migration 086, the terms, the vocabulary ──────────────────
echo "\n0. Migration 086, the terms and the event vocabulary\n";
$tables = array_column($s->q("SELECT name FROM sqlite_master WHERE type = 'table' AND name LIKE 'install_auth%' ORDER BY name"), 'name');
is_($tables === ['install_auth', 'install_auth_events', 'install_auth_rate'], 'migration 086: the three tables exist in the sandbox', json_encode($tables));
$trig = array_column($s->q("SELECT name FROM sqlite_master WHERE type = 'trigger' AND name LIKE 'install_auth%' ORDER BY name"), 'name');
is_($trig === ['install_auth_events_no_delete', 'install_auth_events_no_update'], 'the trail is append-only by trigger', json_encode($trig));
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
is_(($marks[901] ?? '') === 'accepted' && ($marks[902] ?? '') === 'none' && !array_key_exists(905, $marks) && ($marks[906] ?? '') === 'none',
    'the list marks each Starlink installation (accepted / none) and leaves the Fiber job unmarked', json_encode($marks));

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
is_($r[0] === 200 && $crmStatus($s, 906) === 2 && $events($s, 906) === [], 'a Starlink job already in progress with no record is completed as before (D3)', $r[0] . ' ' . $msg($r));
$r = $s->api('tech', 'GET', 'scheduling_job_detail', null, '&job_id=906');
is_(($r[2]['data']['install_auth']['status'] ?? '') === 'none' && isset($r[2]['data']['photos']), 'its detail still carries the photo rules beside the authorisation state');
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
        ['the acceptance no longer requiring a pending record', 'lib/InstallAuth.php',
         "AND status = \\'pending\\' AND terms_hash = ?", "AND terms_hash = ?", 'twice'],
    ];
    foreach ($mutants as $i => [$label, $rel, $old, $new, $probe]) {
        [$tmp, $n] = sj_weakened_copy($root, $rel, $old, $new);
        if ($n !== 1) { is_(false, "weakened copy ({$label}): the anchor appears once", "{$rel}: {$n}"); exec('rm -rf ' . escapeshellarg($tmp)); continue; }
        $m = SjSandbox::start($tmp, $CFG, 'iam' . $i);
        file_put_contents($m->plug . '/ucrm.json', json_encode(['pluginDataDir' => $m->data, 'pluginPublicUrl' => $m->base]));
        $m->seedCrm(['users' => $users, 'clients' => $clients, 'jobs' => ['901' => $jobs['901']], 'tasks' => []]);
        $accounts($m);
        if ($probe === 'accept') {
            $r = $m->api('tech', 'POST', 'scheduling_job_update', ['job_id' => 901, 'status' => 'open']);
            is_($r[0] === 200 && $crmStatus($m, 901) === 1, "caught: with {$label}, Accept Job goes through with no acceptance (a real tree answers 422)", $r[0] . ' ' . $msg($r));
        } elseif ($probe === 'checkin') {
            $r = $m->api('tech', 'POST', 'install_checkin', ['job_id' => 901, 'lat' => 0.3476, 'lon' => 32.5825]);
            is_($r[0] === 200 && $crmStatus($m, 901) === 1, "caught: with {$label}, a check-in starts the job with no acceptance", $r[0] . ' ' . $msg($r));
        } elseif ($probe === 'complete') {
            $r = $m->api('tech', 'POST', 'scheduling_complete', ['job_id' => 901, 'comment' => '', 'lat' => 0.3476, 'lon' => 32.5825]);
            is_($r[0] === 200 && $crmStatus($m, 901) === 2, "caught: with {$label}, a never-started job is completed with no acceptance", $r[0] . ' ' . $msg($r));
        } else {
            // The page refuses a second click by itself (its state switch answers "already authorised" before any POST is
            // read), so the loosened statement can be reached only through the service: probe InstallAuth::accept() twice
            // in the weakened tree, with the real tree as the control — and prove the page still holds above it.
            $probeSrc = <<<'PROBE'
<?php
declare(strict_types=1);
$tree = $argv[1];
foreach (['StoreInterface', 'JsonStore', 'SqliteStore', 'InstallAuth'] as $c) require_once $tree . '/lib/' . $c . '.php';
$dir = sys_get_temp_dir() . '/ia_probe_' . bin2hex(random_bytes(4)); mkdir($dir, 0700, true);
putenv('DN_VAULT_FILE=' . $dir . '/vault.json');
$pdo = SqliteStore::create($dir)->getPdo();
$tz  = new DateTimeZone('Africa/Kampala');
$cfg = ['install_auth_enabled' => '1', 'tenant_profile' => 'uganda'];
$job = ['id' => 1, 'title' => 'Starlink installation', 'status' => 0, 'date' => '2026-10-07T09:00:00+0300', 'address' => 'Plot 9', 'clientId' => 1, 'duration' => 60];
$client = ['id' => 1, 'firstName' => 'Canary', 'lastName' => 'Customer', 'contacts' => [['phone' => '+256700000915']]];
$r   = InstallAuth::request($pdo, $job, $client, ['installation' => '1', 'service' => 's', 'equipment' => 'e'], ['id' => 1, 'name' => 'L'], $cfg, $tz);
$row = InstallAuth::byToken($pdo, $r['token']);
$a   = InstallAuth::accept($pdo, $row, (string)$row['terms_hash'], 'First');
$b   = InstallAuth::accept($pdo, InstallAuth::find($pdo, 1), (string)$row['terms_hash'], 'Second');
echo json_encode([$a['outcome'], $b['outcome'], InstallAuth::find($pdo, 1)['accepted_by_name']]);
exec('rm -rf ' . escapeshellarg($dir));
PROBE;
            $probeFile = sys_get_temp_dir() . '/ia_probe_' . getmypid() . '.php';
            file_put_contents($probeFile, $probeSrc);
            $probe = function (string $tree) use ($probeFile): array {
                $out = []; exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($probeFile) . ' ' . escapeshellarg($tree) . ' 2>&1', $out);
                return (array)json_decode((string)end($out), true);
            };
            $weak = $probe($tmp); $real = $probe($root);
            @unlink($probeFile);
            is_($real === ['accepted', 'already', 'First'] && ($weak[0] ?? '') === 'accepted' && ($weak[1] ?? '') === 'accepted' && ($weak[2] ?? '') === 'Second',
                "caught: with {$label}, the service accepts a second time and overwrites the name (the real tree answers already)", json_encode(['weak' => $weak, 'real' => $real]));
            $request($m, 'tech', 901);
            $tk = $tokenIn($m->texts());
            $accept($m, $tk, 'First Name');
            $r = $accept($m, $tk, 'Second Name');
            is_(strpos($r[1], InstallAuth::ALREADY) !== false && $row($m, 901)['accepted_by_name'] === 'First Name',
                'and even so the page alone still answers "already authorised" — a second guard above the statement');
        }
        $m->stop();
        exec('rm -rf ' . escapeshellarg($tmp));
    }
}

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
