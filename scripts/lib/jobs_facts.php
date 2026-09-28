<?php
/**
 * jobs_facts.php — READ-ONLY facts for the staff/job/WhatsApp audit (docs/43).
 *
 * Piped into the ucrm container by scripts/dnb-jobs-facts.sh and run there as the
 * store's owner, with the INSTALLED plugin as the working directory:
 *
 *   RO_DIR  a COPY of the plugin's store (plugin.sqlite3 [+ -wal]); the live one is never opened
 *   PDD     the plugin's persistent data directory (read only: config file, webhook log, plugin log)
 *
 * It sends nothing, writes nothing outside RO_DIR, and makes GET requests only (uCRM
 * through the plugin's own client; Evolution's connection-state read).
 *
 * What it never prints: a name, an e-mail address, a phone number (only its country
 * code and form), a job title, an address, a token, a key or an instance name. Staff
 * appear as S1…Sn by internal id. uCRM user ids and job counts are the operator's own.
 * Lists of numbers are joined with ", " so the wrapper's number filter cannot mistake
 * them for a telephone number.
 */
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE & ~E_WARNING);
ini_set('display_errors', '0');

$roDir = rtrim((string)getenv('RO_DIR'), '/');
$pdd   = rtrim((string)getenv('PDD'), '/');
$root  = getcwd();
if ($roDir === '' || !is_file($roDir . '/plugin.sqlite3')) { echo "  FAIL no store copy to read\n"; exit(1); }

require_once $root . '/lib/StoreInterface.php';
require_once $root . '/lib/SqliteStore.php';
require_once $root . '/lib/CrmApiClient.php';
require_once $root . '/lib/PluginConfig.php';
require_once $root . '/lib/EvolutionApiService.php';
require_once $root . '/lib/WebhookRegistrar.php';
require_once $root . '/lib/timezone.php';
require_once $root . '/lib/TenantProfile.php';

function out(string $s = ''): void { echo $s, "\n"; }
function yn($v): string { return $v ? 'yes' : 'no'; }
function ids(array $a): string { $a = array_values(array_unique(array_map('strval', $a))); sort($a, SORT_NATURAL); return $a ? implode(', ', $a) : '—'; }
/** The form of a phone number and its country code — never the number. */
function phoneForm(string $p): string {
    $t = trim($p); if ($t === '') return 'none';
    $d = preg_replace('/\D/', '', $t);
    if ($d === '') return 'unreadable';
    foreach (['256', '211', '254', '255', '250', '243', '249', '251', '252', '257'] as $cc) {
        if ($t[0] === '+' && strpos($d, $cc) === 0) return "+{$cc} international";
        if (strpos($d, '00' . $cc) === 0) return "00{$cc} international";
        if (strpos($d, $cc) === 0 && strlen($d) >= 11) return "{$cc}… international, no +";
    }
    if ($t[0] === '+') return '+ other country code';
    if ($d[0] === '0') return 'national 0… (goes out with no country code)';
    return 'other (' . strlen($d) . ' digits)';
}
function last9(string $p): string { $d = preg_replace('/\D/', '', $p); return strlen($d) >= 9 ? substr($d, -9) : ''; }
/** The e-mail → id pairs of the hard-coded map assigned to $var in $file (installed copy). */
function seedMap(string $file, string $var, int $nth = 1): array {
    $src = (string)@file_get_contents($file);
    // The nth ASSIGNMENT: the same variable is also read further down ($seedMap[$email]).
    if (!preg_match_all('/' . preg_quote($var, '/') . '\\s*=\\s*\\[/', $src, $at, PREG_OFFSET_CAPTURE) || !isset($at[0][$nth - 1])) return [];
    $pos = (int)$at[0][$nth - 1][1];
    $open = strpos($src, '[', $pos); $close = strpos($src, '];', $open);
    if ($open === false || $close === false) return [];
    preg_match_all("/'([^'\\s]+@[^'\\s]+)'\\s*=>\\s*(\\d+)/", substr($src, $open, $close - $open), $m, PREG_SET_ORDER);
    $map = []; foreach ($m as $x) $map[strtolower($x[1])] = (int)$x[2];
    return $map;
}

// ── the store copy, the configuration, the clients ─────────────────────────
$store = SqliteStore::create($roDir);
$pdo   = $store->getPdo();
$cfgFile = [];
try { $cfgFile = PluginConfig::read($root, $pdd); } catch (\Throwable $e) {}
$cfgStore = $store->load('kyc_config.json') ?: [];
$cfg = $cfgFile + $cfgStore;
$crm = CrmApiClient::fromUcrm($root, $cfg);

$manifest = json_decode((string)@file_get_contents($root . '/manifest.json'), true) ?: [];
$tz = dn_tz();
try { $off = (new DateTime('now', new DateTimeZone($tz)))->format('P'); } catch (\Throwable $e) { $off = '?'; }
out('== 1. What is installed ==');
// The profile the plugin actually runs on, and why: an explicit tenant_profile key, else the currency (UGX selects
// uganda), else the South Sudan default. $cfg is already filled from the vault, where currency_code lives.
$profileKey = strtolower(trim((string)($cfg[TenantProfile::SELECTOR_KEY] ?? '')));
$profileCur = strtoupper(trim((string)($cfg['currency_code'] ?? $cfg['cashbook_base_currency'] ?? '')));
$profileId  = TenantProfile::resolveId($cfg);
$profileWhy = in_array($profileKey, TenantProfile::IDS, true) ? 'set explicitly'
            : ($profileCur === 'UGX' ? 'selected by the currency UGX; no tenant_profile setting'
            : 'the default; no tenant_profile setting and no UGX currency');
$tzWhy = trim((string)($cfg['timezone'] ?? '')) !== '' ? 'from the timezone setting' : "from the {$profileId} profile";
out('  plugin version ' . ($manifest['information']['version'] ?? $manifest['version'] ?? '?')
    . ' · timezone ' . $tz . ' (UTC' . $off . ', ' . $tzWhy . ')');
out('  tenant profile ' . $profileId . ' (' . $profileWhy . ')');
out('  uCRM reachable for the plugin: ' . yn($crm->isConfigured()));
out('@@TZ ' . $tz . ' ' . $off);
out('@@PROFILE ' . $profileId);

// ── 2. staff rows ──────────────────────────────────────────────────────────
$rows = [];
foreach ($pdo->query('SELECT * FROM retailers ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $d = isset($r['data']) ? (json_decode((string)$r['data'], true) ?: []) : $r;
    $d['id'] = (int)($r['id'] ?? $d['id'] ?? 0);
    $rows[] = $d;
}
$label = []; $n = 0; foreach ($rows as $r) $label[(int)$r['id']] = 'S' . (++$n);
$maps = [
    'deploy'      => seedMap($root . '/public.php', '$_seedMapUpgrade'),
    'page load'   => seedMap($root . '/public.php', '$_ucrmSeedMapGlobal'),
    'My Jobs'     => seedMap($root . '/tabs/support/scheduling.php', '$_ucrmSeedMap'),
    'Clear Cache' => seedMap($root . '/includes/api/api_scheduling.php', '$seedMap', 1),
    'auto-map'    => seedMap($root . '/includes/api/api_scheduling.php', '$seedMap', 2),
];
$probe = [];   // uCRM user id → what uCRM answers for it (booleans and status codes only)
$uProbe = function (int $id) use ($crm, &$probe): array {
    if (isset($probe[$id])) return $probe[$id];
    $res = ['admins' => 0, 'users' => 0, 'email' => '', 'phoneKey' => false, 'phone' => '', 'keys' => []];
    $a = $crm->get("users/admins/{$id}");
    $res['admins'] = $a !== null ? 200 : (int)($crm->getLastError()['http_code'] ?? 0);
    $u = $crm->get("users/{$id}");
    $res['users'] = $u !== null ? 200 : (int)($crm->getLastError()['http_code'] ?? 0);
    $obj = is_array($a) && isset($a['id']) ? $a : (is_array($u) && isset($u['id']) ? $u : null);
    if ($obj) {
        $res['email'] = strtolower(trim((string)($obj['email'] ?? '')));
        $res['phoneKey'] = array_key_exists('phone', $obj);
        $res['phone'] = (string)($obj['phone'] ?? '');
        $res['keys'] = array_keys($obj);
    }
    return $probe[$id] = $res;
};
out('');
out('== 2. Staff accounts (Staff & Retailers page) — S1…Sn by internal id; no name, e-mail or number ==');
$tiles = ['total' => 0, 'active' => 0, 'admins' => 0, 'field' => 0, 'ftth' => 0, 'ucrm' => 0];
$assignable = [];
$userKeys = [];
foreach ($rows as $r) {
    $id = (int)$r['id']; $L = $label[$id];
    $role = (string)($r['role'] ?? '');
    $active = (bool)($r['is_active'] ?? true);
    $tiles['total']++; if ($active) $tiles['active']++; if (!empty($r['is_admin'])) $tiles['admins']++;
    if ($role === 'field_agent') $tiles['field']++; if (!empty($r['ftth_crm_client_id'])) $tiles['ftth']++;
    $uid = (int)($r['ucrm_user_id'] ?? 0); if ($uid) $tiles['ucrm']++;
    if (in_array($role, ['support_engineer', 'support', 'support_leader', 'admin'], true) && !empty($r['is_active']) && $uid) $assignable[] = $L;
    $tokAge = !empty($r['api_token']) ? (int)floor((time() - (int)($r['token_issued_at'] ?? 0)) / 86400) : -1;
    out(sprintf('  %-4s id %-4d role %-16s admin %-3s active %-3s phone %s', $L, $id, $role !== '' ? $role : '(none)',
        yn(!empty($r['is_admin'])), yn($active), phoneForm((string)($r['phone'] ?? ''))));
    out('        app sign-in token ' . ($tokAge >= 0 ? "yes, issued {$tokAge} day(s) ago" . ($tokAge > 90 ? ' (expired: 90-day limit)' : '') : 'no')
        . ' · must change password (PWD badge) ' . yn(!empty($r['must_change_pwd'])) . ' · commission agent (AGENT badge) ' . yn(!($r['is_employee'] ?? true)));
    out('        uCRM user id ' . ($uid ?: 'not set') . ' · FTTH CRM client link ' . yn(!empty($r['ftth_crm_client_id'])));
    $em = strtolower(trim((string)($r['email'] ?? '')));
    $forced = [];
    foreach ($maps as $mk => $mm) if ($em !== '' && isset($mm[$em])) $forced[$mk] = $mm[$em];
    if ($forced) {
        $parts = []; foreach ($forced as $mk => $v) $parts[] = "{$mk} → {$v}";
        out('        hard-coded South Sudan maps force: ' . implode(' · ', $parts) . (count(array_unique($forced)) > 1 ? ' (the maps DISAGREE)' : ''));
    } else {
        out('        hard-coded maps: not listed (the id is whatever was typed)');
    }
    foreach (array_unique(array_merge($uid ? [$uid] : [], array_values($forced))) as $cand) {
        if (!$crm->isConfigured()) break;
        $p = $uProbe((int)$cand);
        if ($p['keys']) $userKeys = $p['keys'];
        out(sprintf('        uCRM id %d: users/admins/%d → %d · users/%d → %d · %s', $cand, $cand, $p['admins'], $cand, $p['users'],
            !$p['keys'] ? 'no such uCRM user'
              : 'same e-mail as this row: ' . ($p['email'] === '' ? 'unknown' : yn($em !== '' && $p['email'] === $em))
                . ' · phone field: ' . (!$p['phoneKey'] ? 'absent' : ($p['phone'] === '' ? 'empty' : phoneForm($p['phone'])))));
    }
}
out(sprintf('  tiles as the page computes them: Total %d · Active %d · Admins %d · Field agents %d · CRM LINKED %d/%d (FTTH CRM client link)',
    $tiles['total'], $tiles['active'], $tiles['admins'], $tiles['field'], $tiles['ftth'], $tiles['total']));
// The FTTH CRM link is made by creating a uCRM client in organisation 7 for the staff member
// (FtthCrmService::ensureRetailerClient, on Add New Staff Account and wallet top-ups). Does it exist here?
$orgs = $crm->isConfigured() ? $crm->get('organizations') : null;
if (is_array($orgs)) {
    $oids = array_map(function ($o) { return (int)(is_array($o) ? ($o['id'] ?? 0) : 0); }, $orgs);
    out('  uCRM organisations (GET organizations): ids ' . ids($oids) . ' · organisation 7 (where the FTTH CRM link would be created) exists: ' . yn(in_array(7, $oids, true)));
} else {
    out('  uCRM organisations (GET organizations) → ' . (int)($crm->getLastError()['http_code'] ?? 0) . ' (not readable)');
}
out('  rows with a uCRM user id: ' . $tiles['ucrm'] . ' · offered in My Jobs → New Job (active support/leader/engineer/admin role + uCRM id): ' . ids($assignable));
foreach ($maps as $mk => $mm) out("  map '{$mk}': " . count($mm) . ' e-mail → id pairs in the installed file');
out('@@STAFF ' . $tiles['total'] . ' ' . $tiles['ftth'] . ' ' . $tiles['ucrm'] . ' ' . count($assignable));

// ── 3. uCRM's own staff users ─────────────────────────────────────────────
out('');
out('== 3. uCRM staff users (GET users/admins) ==');
$staffByEmail = []; foreach ($rows as $r) { $e = strtolower(trim((string)($r['email'] ?? ''))); if ($e !== '') $staffByEmail[$e] = $label[(int)$r['id']]; }
$admins = $crm->isConfigured() ? $crm->get('users/admins') : null;
if (!is_array($admins)) {
    out('  users/admins → ' . (int)($crm->getLastError()['http_code'] ?? 0) . ' (no list)');
} else {
    $withPhoneKey = 0; $withPhone = 0; $matched = []; $unmatched = [];
    foreach ($admins as $u) {
        if (!is_array($u)) continue;
        if (!$userKeys) $userKeys = array_keys($u);
        if (array_key_exists('phone', $u)) $withPhoneKey++;
        if (trim((string)($u['phone'] ?? '')) !== '') $withPhone++;
        $e = strtolower(trim((string)($u['email'] ?? '')));
        if ($e !== '' && isset($staffByEmail[$e])) $matched[] = (int)($u['id'] ?? 0) . '=' . $staffByEmail[$e];
        else $unmatched[] = (int)($u['id'] ?? 0);
    }
    out('  ' . count($admins) . ' user(s) · with a phone field ' . $withPhoneKey . ' · with a phone ' . $withPhone);
    out('  same e-mail as a staff row: ' . ids($matched) . ' · no staff row: ' . ids($unmatched));
}
out('  field names of a uCRM user: ' . ($userKeys ? implode(', ', $userKeys) : 'none read'));

// ── 4. uCRM scheduling jobs ────────────────────────────────────────────────
out('');
out('== 4. uCRM scheduling jobs (GET scheduling/jobs) — counts only, never a title or address ==');
$jobs = $crm->isConfigured() ? $crm->get('scheduling/jobs?limit=500') : null;
if (!is_array($jobs)) {
    out('  scheduling/jobs → ' . (int)($crm->getLastError()['http_code'] ?? 0) . ' (no list)');
} else {
    $byStatus = []; $byUser = []; $withClient = 0; $dates = []; $classes = []; $keys = [];
    $staffUid = []; foreach ($rows as $r) if (!empty($r['ucrm_user_id'])) $staffUid[(int)$r['ucrm_user_id']] = $label[(int)$r['id']];
    foreach ($jobs as $j) {
        if (!is_array($j)) continue;
        if (!$keys) $keys = array_keys($j);
        $s = (string)($j['status'] ?? '?'); $byStatus[$s] = ($byStatus[$s] ?? 0) + 1;
        $u = (int)($j['assignedUserId'] ?? 0); $k = $u ? (string)$u : 'none'; $byUser[$k] = ($byUser[$k] ?? 0) + 1;
        if (!empty($j['clientId']) || !empty($j['client']['id'])) $withClient++;
        if (!empty($j['date'])) $dates[] = substr((string)$j['date'], 0, 10);
        $t = strtolower((string)($j['title'] ?? ''));
        $c = preg_match('/install|setup|set-up|mount/', $t) ? 'installation' : (preg_match('/survey/', $t) ? 'survey'
           : (preg_match('/repair|troubl|fault|maint|replace/', $t) ? 'repair/troubleshooting' : (preg_match('/deliver/', $t) ? 'delivery'
           : (preg_match('/payment|collect/', $t) ? 'payment collection' : (preg_match('/follow/', $t) ? 'follow-up' : 'other')))));
        $classes[$c] = ($classes[$c] ?? 0) + 1;
    }
    ksort($byStatus); ksort($byUser, SORT_NATURAL); arsort($classes); sort($dates);
    $st = []; foreach ($byStatus as $k => $v) $st[] = "{$k}:{$v}";
    $bu = []; foreach ($byUser as $k => $v) $bu[] = "{$k}:{$v}" . (isset($staffUid[(int)$k]) ? '=' . $staffUid[(int)$k] : ($k === 'none' ? '' : '=no staff row'));
    $cl = []; foreach ($classes as $k => $v) $cl[] = "{$k} {$v}";
    out('  ' . count($jobs) . ' job(s) returned (limit 500) · by status (0 pending, 1 open, 2 closed) ' . implode(', ', $st));
    out('  by assigned uCRM user ' . implode(', ', $bu));
    out('  with a client ' . $withClient . ' · dated ' . ($dates ? $dates[0] . ' … ' . end($dates) : '—'));
    out('  by title keyword ' . implode(', ', $cl));
    out('  field names of a job (list): ' . ($keys ? implode(', ', $keys) : '—'));
    $first = null; foreach ($jobs as $j) if (is_array($j) && !empty($j['id'])) { $first = (int)$j['id']; break; }
    if ($first) {
        $one = $crm->get("scheduling/jobs/{$first}");
        out('  field names of one job (detail): ' . (is_array($one) ? implode(', ', array_keys($one)) : 'not readable'));
    }
    out('@@JOBS ' . count($jobs) . ' ' . $withClient);
}

// ── 5. messages for jobs ───────────────────────────────────────────────────
out('');
out('== 5. Messages sent for jobs (Message Log · failure queue · webhook log) ==');
$events = ['job_assigned', 'ops_scheduling_job_assigned', 'ops_scheduling_job_reassigned', 'ops_scheduling_job_accepted',
           'ops_job_accepted_self', 'ops_job_accepted_leader', 'ops_scheduling_task_done', 'ops_scheduling_all_tasks_done',
           'ops_scheduling_job_complete', 'ops_scheduling_job_complete_admin', 'ops_scheduling_rescheduled', 'ops_invoice_queue_new'];
$in = implode(',', array_fill(0, count($events), '?'));
try {
    $st = $pdo->prepare("SELECT event, SUM(success = 1) ok, SUM(success = 0) bad, MIN(sent_at) first, MAX(sent_at) last FROM notification_audit_log WHERE event IN ({$in}) OR event LIKE '%job%suppressed%' GROUP BY event ORDER BY event");
    $st->execute($events); $any = false;
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) { $any = true;
        out(sprintf('  Message Log  %-34s sent %d · failed %d · %s … %s', $r['event'], $r['ok'], $r['bad'], $r['first'], $r['last'])); }
    if (!$any) out('  Message Log  no job message at all');
    $tot = $pdo->query('SELECT COUNT(*), MIN(sent_at) FROM notification_audit_log')->fetch(PDO::FETCH_NUM);
    out('  Message Log  holds ' . (int)$tot[0] . ' row(s) of every kind since ' . ($tot[1] ?: '—') . ' (the positive control: the log is readable and not empty)');
} catch (\Throwable $e) { out('  Message Log  not readable: ' . get_class($e)); }
try {
    $st = $pdo->prepare("SELECT event, status, COUNT(*) c FROM notification_queue WHERE event IN ({$in}) GROUP BY event, status ORDER BY event, status");
    $st->execute($events); $q = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $q[] = "{$r['event']} {$r['status']} {$r['c']}";
    out('  failure queue ' . ($q ? implode(' · ', $q) : 'no job message waiting or retried'));
} catch (\Throwable $e) { out('  failure queue not present'); }
$wl = json_decode((string)@file_get_contents($pdd . '/webhook_log.json'), true);
if (is_array($wl)) {
    $c = ['job.add' => 0, 'sent' => 0, 'nophone' => 0, 'nouser' => 0, 'off' => 0, 'unverified' => 0]; $oldest = '';
    // One delivery from uCRM, one "Received UCRM webhook: <type>" line: webhook.php writes it once per request, after
    // turning uCRM's changeType/entity ("edit"/"job") into "job.edit", in every version. No other line names the event
    // once per delivery — a job.edit or job.delete that nothing handles is otherwise logged only as "Unhandled event
    // type — logged only" (docs/44 §16.17).
    $rx = ['job.add' => 0, 'job.edit' => 0, 'job.delete' => 0];
    foreach ($wl as $e) {
        $ev = (string)($e['event'] ?? ''); $m = (string)($e['message'] ?? ''); $oldest = (string)($e['received_at'] ?? $oldest);
        if (preg_match('/^Received UCRM webhook: (job\.(?:add|edit|delete))$/', $m, $mm) && isset($rx[$mm[1]])) { $rx[$mm[1]]++; continue; }
        if ($ev === 'job.add' || $ev === 'JOB_ADD') { $c['job.add']++;
            if (strpos($m, 'notification sent') !== false) $c['sent']++;
            if (strpos($m, 'No phone found') !== false) $c['nophone']++;
            if (strpos($m, 'No user assigned') !== false) $c['nouser']++;
            if (strpos($m, 'not switched on yet') !== false) $c['off']++; }
        if ($ev === 'entity_unverified' && strpos($m, 'job #') !== false) $c['unverified']++;
    }
    out(sprintf('  webhook log  %d entr(ies), oldest %s · received from uCRM: job.add %d · job.edit %d · job.delete %d',
        count($wl), $oldest ?: '—', $rx['job.add'], $rx['job.edit'], $rx['job.delete']));
    out(sprintf('  webhook log  job.add handler lines %d: "notification sent" %d · "No phone found" %d · "No user assigned" %d · "not switched on yet" %d · job unverified %d',
        $c['job.add'], $c['sent'], $c['nophone'], $c['nouser'], $c['off'], $c['unverified']));
} else {
    out('  webhook log  not found');
}

// ── 6. the uCRM webhook endpoint ───────────────────────────────────────────
out('');
out('== 6. uCRM webhook endpoint (GET webhooks/endpoints) — no address printed ==');
$eps = $crm->isConfigured() ? $crm->get('webhooks/endpoints') : null;
if (!is_array($eps)) {
    out('  webhooks/endpoints → ' . (int)($crm->getLastError()['http_code'] ?? 0));
} else {
    $ours = 0;
    foreach ($eps as $ep) {
        if (!is_array($ep) || !WebhookRegistrar::isOurs($ep, basename($root))) continue; $ours++;
        $list = (array)($ep['eventTypes'] ?? []);
        out('  ours: active ' . yn(!empty($ep['isActive'])) . ' · events ' . (!empty($ep['anyEvent']) || !$list ? 'any' : count($list) . ' listed')
            . ' · job.add delivered ' . yn(!empty($ep['anyEvent']) || !$list || in_array('job.add', $list, true))
            . ' · job.edit delivered ' . yn(!empty($ep['anyEvent']) || !$list || in_array('job.edit', $list, true))
            . ' · route ' . (WebhookRegistrar::routesToPlugin((string)($ep['url'] ?? ''), basename($root)) ? 'reaches webhook.php' : 'NOT the plugin route'));
    }
    out('  ' . count($eps) . ' endpoint(s), ' . $ours . ' of them this plugin\'s');
}

// ── 7. the WhatsApp transport ──────────────────────────────────────────────
out('');
out('== 7. WhatsApp transport — presence only; no key, URL or instance name ==');
$evo = new EvolutionApiService($cfg);
out('  Evolution configured ' . yn($evo->isConfigured()) . ' · channels with an instance: ' . ids($evo->configuredChannels())
    . ' · wa_force_accounts ' . yn(!empty($cfg['wa_force_accounts'])) . ' · dry run ' . yn(!empty($cfg['dry_run_mode'])));
out('  WASender keys (the other transport) ' . yn(trim((string)($cfg['wa_plugin_url'] ?? '')) !== '' && trim((string)($cfg['wa_app_key'] ?? '')) !== '')
    . ' · admin alert number ' . phoneForm((string)($cfg['whatsapp_admin_phone'] ?? '')));
if ($evo->isConfigured()) {
    $h = []; foreach ($evo->channelHealth() as $ch => $x) $h[] = $ch . ' ' . (string)($x['state'] ?? '?');
    out('  connection state (GET): ' . ($h ? implode(' · ', $h) : '—'));
}

// ── 8. background jobs ─────────────────────────────────────────────────────
out('');
out('== 8. Background jobs ==');
$master = (string)@file_get_contents($root . '/cron/master.php');
$jaOn = (bool)preg_match("#^\\s*'job_assign'\\s*=>#m", $master);
$sjOn = (bool)preg_match("#^\\s*'staff_jobs'\\s*=>#m", $master);
out('  installed master.php: job_assign scheduled ' . yn($jaOn) . ' · staff_jobs scheduled ' . yn($sjOn));
$sch = $store->load('master_schedule.json') ?: [];
foreach (['job_assign', 'staff_jobs', 'jobs_cache'] as $k) {
    $s = $sch[$k] ?? null;
    out("  last run of {$k}: " . ($s ? ((string)($s['last_run_at'] ?? '?') . ', ' . (string)($s['duration_ms'] ?? '?') . ' ms') : 'never recorded'));
}
$seen = $store->load('job_assignments_seen.json') ?: [];
$lastN = ''; foreach ($seen as $v) if (is_array($v) && ($v['notified_at'] ?? '') > $lastN) $lastN = (string)$v['notified_at'];
out('  memory of the disabled dispatch cron (job_assignments_seen): ' . count($seen) . ' entr(ies)' . ($lastN ? ', last ' . $lastN : ''));
$meta = $store->load('scheduling_cache_meta.json') ?: [];
out('  My Jobs cache: ' . count($store->load('scheduling_jobs_cache.json') ?: []) . ' job(s), last sync ' . ((string)($meta['last_sync_ts'] ?? '') ?: '—')
    . ', plugin version stamp ' . ((string)($meta['plugin_version'] ?? '') ?: '—'));
$logs = array_values(array_filter([$root . '/data/plugin.log', $pdd . '/plugin.log'], 'is_file'));
if (!$logs) out('  plugin log: not found');
foreach ($logs as $lf) {
    $txt = (string)@file_get_contents($lf, false, null, max(0, (int)@filesize($lf) - 4000000));
    preg_match_all('/^\[([0-9: -]{19})\] \[master\] ERROR staff_jobs: (.{0,110})/m', $txt, $m, PREG_SET_ORDER);
    preg_match_all('/^\[([0-9: -]{19})\] \[master\] RUN staff_jobs/m', $txt, $r2);
    out('  plugin log (' . basename(dirname($lf)) . '/plugin.log): staff_jobs ran ' . count($r2[0]) . ' time(s), ERROR ' . count($m)
        . ($m ? ', last ' . end($m)[1] . ': ' . preg_replace('/[\/\w.-]+\.php/', '<file>', end($m)[2]) : ''));
}

// ── 9. staff numbers in the WhatsApp inbox ─────────────────────────────────
out('');
out('== 9. Staff numbers in the WhatsApp inbox (a colleague answering is filed as a customer) ==');
$staffTail = []; foreach ($rows as $r) { $t = last9((string)($r['phone'] ?? '')); if ($t !== '') $staffTail[$t] = $label[(int)$r['id']]; }
$convHit = []; $aiHit = 0; $aiLast = '';
try {
    foreach ($pdo->query('SELECT phone, channel FROM wa_conversations')->fetchAll(PDO::FETCH_ASSOC) as $c) {
        $t = last9((string)$c['phone']); if ($t !== '' && isset($staffTail[$t])) $convHit[] = $staffTail[$t] . ' on ' . (string)$c['channel'];
    }
} catch (\Throwable $e) {}
try {
    $cols = array_column($pdo->query('PRAGMA table_info(events)')->fetchAll(PDO::FETCH_ASSOC), 'name');
    $typeCol = in_array('event_type', $cols, true) ? 'event_type' : (in_array('type', $cols, true) ? 'type' : '');
    $timeCol = in_array('created_at', $cols, true) ? 'created_at' : '';
    if ($typeCol !== '' && in_array('payload', $cols, true)) {
        $st = $pdo->query("SELECT payload" . ($timeCol ? ", {$timeCol} t" : '') . " FROM events WHERE {$typeCol} = 'ai.reply'");
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $e) {
            $p = json_decode((string)$e['payload'], true) ?: [];
            $t = last9((string)($p['customer_phone'] ?? ''));
            if ($t !== '' && isset($staffTail[$t])) { $aiHit++; if (($e['t'] ?? '') > $aiLast) $aiLast = (string)$e['t']; }
        }
    }
} catch (\Throwable $e) {}
out('  staff numbers with a customer conversation: ' . count($convHit) . ($convHit ? ' (' . ids($convHit) . ')' : ''));
out('  AI replies queued for a staff number: ' . $aiHit . ($aiLast ? ', last ' . $aiLast : ''));

// ── 10. local job records ──────────────────────────────────────────────────
out('');
out('== 10. Local job records kept by the plugin ==');
$iq = $store->load('job_invoice_queue.json') ?: []; $pend = 0; foreach ($iq as $x) if (($x['status'] ?? '') === 'pending') $pend++;
out('  job completions ' . count($store->load('job_completions.json') ?: []) . ' · invoice queue ' . count($iq) . ' (pending ' . $pend . ')'
    . ' · signatures ' . count($store->load('job_signatures.json') ?: []) . ' · site surveys ' . count($store->load('site_surveys.json') ?: []));
out('@@DONE');
