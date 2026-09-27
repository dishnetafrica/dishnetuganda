<?php
/**
 * ucrm_users_facts.php — READ-ONLY: Uganda's uCRM staff users, before J1–J8 are built (docs/44).
 *
 * Piped into the ucrm container by scripts/dnb-ucrm-users-facts.sh and run there as the
 * store's owner, with the INSTALLED plugin as the working directory:
 *
 *   RO_DIR  a COPY of the plugin's store (plugin.sqlite3 [+ -wal]); the live one is never opened
 *   PDD     the plugin's persistent data directory (read only: the settings files)
 *
 * It answers what the specification needs and the repository cannot:
 *   1. the tenant profile and timezone the plugin actually runs on
 *   2. every uCRM staff user: id, active, whether its record (list AND detail) has any
 *      phone-like field and whether that field holds a number, and what the job.add
 *      handler's own lookup (users/{id}) answers for a REAL id
 *   3. each staff account against those users: the e-mail match the J2 picker would
 *      propose, whether the stored uCRM id is a real user, duplicates, and the two other
 *      settings that hold a uCRM user id
 *   4. the offset uCRM writes into its own timestamps (J5: which clock uCRM keeps)
 *   5. whether the follow-up engine is switched on (J8: staff conversations)
 *   6. which events each WhatsApp number's webhook subscribes to (J7: delivery receipts)
 *
 * It sends nothing, writes nothing outside RO_DIR, and makes GET requests only (uCRM
 * through the plugin's own client; Evolution's webhook settings through the plugin's own
 * client).
 *
 * What it never prints: a name, a username, an e-mail address, a phone number (only its
 * country code and form), a date, an amount, an address, a URL, a token, a key or an
 * instance name. uCRM users appear as U1…Un with their numeric id; staff as S1…Sn by
 * internal id, the same labels the jobs-facts command printed. Lists of numbers are joined
 * with ", " so the wrapper's number filter cannot mistake them for a telephone number.
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
require_once $root . '/lib/timezone.php';
require_once $root . '/lib/TenantProfile.php';

function out(string $s = ''): void { echo $s, "\n"; }
function yn($v): string { return $v ? 'yes' : 'no'; }
/** The form of a phone number and its country code — never the number. */
function phoneForm(string $p): string {
    $t = trim($p); if ($t === '') return 'empty';
    $d = preg_replace('/\D/', '', $t);
    if ($d === '') return 'no digits';
    foreach (['256', '211', '254', '255', '250', '243', '249', '251', '252', '257'] as $cc) {
        if ($t[0] === '+' && strpos($d, $cc) === 0) return "+{$cc} international";
        if (strpos($d, '00' . $cc) === 0) return "00{$cc} international";
        if (strpos($d, $cc) === 0 && strlen($d) >= 11) return "{$cc}… international, no +";
    }
    if ($t[0] === '+') return '+ other country code';
    if ($d[0] === '0') return 'national 0…';
    return 'other (' . strlen($d) . ' digits)';
}
/**
 * Every phone-like field of a record, nested ones included: path => value. The path is a
 * field NAME (schema), with list positions shown as *, so "contacts.*.phone" — never a value.
 */
function phoneFields(array $rec, string $prefix = ''): array {
    $out = [];
    foreach ($rec as $k => $v) {
        $seg  = is_int($k) ? '*' : (string)$k;
        $path = $prefix === '' ? $seg : $prefix . '.' . $seg;
        if (is_array($v)) { foreach (phoneFields($v, $path) as $p => $vals) foreach ($vals as $x) $out[$p][] = $x; continue; }
        if (!is_int($k) && preg_match('/phone|mobile|msisdn|whats|^tel/i', $seg)) $out[$path][] = is_scalar($v) ? (string)$v : '';
    }
    return $out;
}
/** "phone (empty)", "contacts.*.phone (+256 international)" — names and forms, joined. */
function describePhones(array $fields): string {
    if (!$fields) return 'none';
    $parts = [];
    foreach ($fields as $path => $vals) {
        $forms = array_values(array_unique(array_map('phoneForm', $vals)));
        $parts[] = $path . ' (' . implode(' / ', $forms) . ')';
    }
    return implode(' · ', $parts);
}
function hasPhoneValue(array $fields): bool {
    foreach ($fields as $vals) foreach ($vals as $v) if (trim((string)$v) !== '') return true;
    return false;
}
/** The offset at the end of an ISO timestamp ("+0300", "+03:00", "Z"), or '' — the date itself is never kept. */
function offsetOf($ts): string {
    return (is_string($ts) && preg_match('/T\d{2}:\d{2}(?::\d{2}(?:\.\d+)?)?(Z|[+-]\d{2}:?\d{2})$/', $ts, $m)) ? $m[1] : '';
}

// ── the store copy, the configuration, the clients ─────────────────────────
$store = SqliteStore::create($roDir);
$pdo   = $store->getPdo();
$cfgFile = [];
try { $cfgFile = PluginConfig::read($root, $pdd); } catch (\Throwable $e) {}
$cfgStore = $store->load('kyc_config.json') ?: [];
$cfg = $cfgFile + $cfgStore;
$crm = CrmApiClient::fromUcrm($root, $cfg);
$code = function () use ($crm): int { return (int)($crm->getLastError()['http_code'] ?? 0); };

// ── 1. what is installed ───────────────────────────────────────────────────
$manifest   = json_decode((string)@file_get_contents($root . '/manifest.json'), true) ?: [];
$profileKey = strtolower(trim((string)($cfg[TenantProfile::SELECTOR_KEY] ?? '')));
$profileCur = strtoupper(trim((string)($cfg['currency_code'] ?? $cfg['cashbook_base_currency'] ?? '')));
$profileId  = TenantProfile::resolveId($cfg);
$profileWhy = in_array($profileKey, TenantProfile::IDS, true) ? 'set explicitly'
            : ($profileCur === 'UGX' ? 'selected by the currency UGX; no tenant_profile setting'
            : 'the default; no tenant_profile setting and no UGX currency');
$tz = dn_tz();
try { $off = (new DateTime('now', new DateTimeZone($tz)))->format('P'); } catch (\Throwable $e) { $off = '?'; }
out('== 1. What is installed ==');
out('  plugin version ' . ($manifest['information']['version'] ?? $manifest['version'] ?? '?')
    . ' · tenant profile ' . $profileId . ' (' . $profileWhy . ')');
out('  plugin timezone ' . $tz . ' (UTC' . $off . ', ' . (trim((string)($cfg['timezone'] ?? '')) !== '' ? 'from the timezone setting' : "from the {$profileId} profile") . ')');
out('  uCRM reachable for the plugin: ' . yn($crm->isConfigured()));
out('@@PROFILE ' . $profileId);

// ── staff rows (S-labels exactly as the jobs-facts command numbered them) ──
$rows = [];
foreach ($pdo->query('SELECT * FROM retailers ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $d = isset($r['data']) ? (json_decode((string)$r['data'], true) ?: []) : $r;
    $d['id'] = (int)($r['id'] ?? $d['id'] ?? 0);
    $rows[] = $d;
}
$label = []; $n = 0; foreach ($rows as $r) $label[(int)$r['id']] = 'S' . (++$n);
$staffByEmail = [];   // e-mail => [S-labels] (duplicates kept, to be counted)
foreach ($rows as $r) { $e = strtolower(trim((string)($r['email'] ?? ''))); if ($e !== '') $staffByEmail[$e][] = $label[(int)$r['id']]; }

// ── 2. uCRM's staff users ──────────────────────────────────────────────────
out('');
out('== 2. uCRM staff users (GET users/admins, then each one) — ids, field names and yes/no only ==');
$admins = $crm->isConfigured() ? $crm->get('users/admins') : null;
$users = [];          // id => ['U' => label, 'active' => bool, 'email' => lower-case e-mail (never printed)]
$usersByEmail = [];   // e-mail => [ids]
$tot = 0; $act = 0; $withField = 0; $withValue = 0;
if (!is_array($admins)) {
    out('  users/admins → ' . $code() . ' (no list; nothing below about uCRM users can be read)');
} else {
    $k = 0;
    foreach ($admins as $u) {
        if (!is_array($u) || !isset($u['id'])) continue;
        $id = (int)$u['id']; $L = 'U' . (++$k); $tot++;
        $active = !array_key_exists('isActive', $u) || !empty($u['isActive']);
        if ($active) $act++;
        $em = strtolower(trim((string)($u['email'] ?? '')));
        $users[$id] = ['U' => $L, 'active' => $active, 'email' => $em];
        if ($em !== '') $usersByEmail[$em][] = $id;
        $listPhones = phoneFields($u);
        $det = $crm->get("users/admins/{$id}");
        $detCode = is_array($det) ? 200 : $code();
        $detPhones = is_array($det) ? phoneFields($det) : [];
        $byUsers = $crm->get("users/{$id}");
        $usersCode = is_array($byUsers) ? 200 : $code();
        $allPhones = $listPhones;
        foreach ($detPhones as $p => $vals) foreach ($vals as $x) $allPhones[$p][] = $x;
        if ($allPhones) $withField++;
        if (hasPhoneValue($allPhones)) $withValue++;
        $match = ($em !== '' && isset($staffByEmail[$em])) ? implode(', ', $staffByEmail[$em]) : 'none';
        out(sprintf('  %-4s id %-6d active %-3s UISP-linked %-3s same e-mail as a staff account: %s', $L, $id, yn($active),
            yn(trim((string)($u['unmsId'] ?? '')) !== ''), $match));
        out('        list record fields: ' . implode(', ', array_map('strval', array_keys($u))));
        if (is_array($det)) {
            $extra = array_values(array_diff(array_map('strval', array_keys($det)), array_map('strval', array_keys($u))));
            out('        detail GET users/admins/' . $id . ' → 200 · fields beyond the list record: ' . ($extra ? implode(', ', $extra) : 'none'));
        } else {
            out('        detail GET users/admins/' . $id . ' → ' . $detCode);
        }
        out('        phone-like fields (list + detail): ' . describePhones($allPhones));
        out('        GET users/' . $id . ' (the address the job.add handler reads) → ' . $usersCode);
    }
    out('  ' . $tot . ' user(s) · active ' . $act . ' · with a phone-like field ' . $withField . ' · with a number in it ' . $withValue);
    out('  a uCRM user with no CRM access does not appear here; it could not be given a uCRM job either');
}
out('@@USERS ' . $tot . ' ' . $act . ' ' . $withField . ' ' . $withValue);

// ── 3. staff accounts against uCRM users ───────────────────────────────────
out('');
out('== 3. Staff accounts against uCRM users (what a verified picker would propose; nothing is changed) ==');
$matchedRows = 0; $storedTot = 0; $storedReal = 0;
foreach ($rows as $r) {
    $id = (int)$r['id']; $L = $label[$id];
    $role = (string)($r['role'] ?? '');
    $em = strtolower(trim((string)($r['email'] ?? '')));
    $stored = (int)($r['ucrm_user_id'] ?? 0);
    $storedTxt = 'not set';
    if ($stored) {
        $storedTot++;
        if (isset($users[$stored])) { $storedReal++; $storedTxt = $stored . ' (' . $users[$stored]['U'] . ($users[$stored]['email'] === $em && $em !== '' ? ', same e-mail' : ', a DIFFERENT person\'s e-mail') . ')'; }
        elseif (is_array($admins)) { $storedTxt = $stored . ' (no such uCRM user)'; }
        else { $storedTxt = $stored . ' (not checked: no uCRM list)'; }
    }
    if ($em === '') $prop = 'no e-mail on this account';
    elseif (!is_array($admins)) $prop = 'not checked: no uCRM list';
    elseif (!isset($usersByEmail[$em])) $prop = 'none — no uCRM user has this e-mail';
    else {
        $matchedRows++;
        $prop = implode(', ', array_map(function ($uid) use ($users) {
            return $users[$uid]['U'] . ' (id ' . $uid . ($users[$uid]['active'] ? '' : ', INACTIVE') . ')';
        }, $usersByEmail[$em]));
    }
    out(sprintf('  %-4s role %-16s active %-3s stored uCRM id %s', $L, $role !== '' ? $role : '(none)',
        yn(!array_key_exists('is_active', $r) || !empty($r['is_active'])), $storedTxt));
    out('        same e-mail in uCRM: ' . $prop);
}
$dupStaff = 0; foreach ($staffByEmail as $ls) if (count($ls) > 1) $dupStaff++;
$dupUsers = 0; foreach ($usersByEmail as $is) if (count($is) > 1) $dupUsers++;
out('  e-mails shared by two staff accounts: ' . $dupStaff . ' · by two uCRM users: ' . $dupUsers);
out('  stored uCRM ids that are real uCRM users: ' . $storedReal . ' of ' . $storedTot);
foreach (['bidal_ucrm_user_id' => 'second-site KYC jobs', 'accountant_ucrm_user_id' => 'fibre-install accountant'] as $key => $what) {
    $v = (int)($cfg[$key] ?? 0);
    out('  setting ' . $key . ' (' . $what . '): ' . (!$v ? 'not set' : $v . ' — ' . (isset($users[$v]) ? 'a real uCRM user' : (is_array($admins) ? 'no such uCRM user' : 'not checked'))));
}
out('@@MATCH ' . $matchedRows . ' ' . count($rows));
out('@@STORED ' . $storedReal . ' ' . $storedTot);

// ── 4. uCRM's clock ────────────────────────────────────────────────────────
out('');
out('== 4. The offset uCRM writes into its own timestamps (offsets only; no date, name or amount) ==');
$offsets = [];
foreach ([['clients?limit=1', 'registrationDate', 'a client\'s registration date'],
          ['invoices?limit=1', 'createdDate', 'an invoice\'s created date'],
          ['payments?limit=1', 'createdDate', 'a payment\'s created date']] as [$path, $field, $what]) {
    $list = $crm->isConfigured() ? $crm->get($path) : null;
    $rec = is_array($list) ? ($list[0] ?? null) : null;
    $o = is_array($rec) ? offsetOf($rec[$field] ?? null) : '';
    out('  ' . $what . ': ' . ($o !== '' ? $o : (is_array($list) ? 'no timestamp to read' : 'not read (' . $code() . ')')));
    if ($o !== '') $offsets[] = $o;
}
$offsets = array_values(array_unique($offsets));
out('@@OFFSET ' . ($offsets ? implode(',', $offsets) : '-'));

// ── 5. follow-ups ──────────────────────────────────────────────────────────
out('');
out('== 5. Follow-up engine ==');
out('  followup_enabled: ' . (PluginConfig::toBool($cfg['followup_enabled'] ?? false) ? 'on — follow-ups can be drafted for any conversation, a staff member\'s included' : 'off'));

// ── 6. WhatsApp delivery receipts ──────────────────────────────────────────
out('');
out('== 6. WhatsApp webhook settings per number (GET webhook/find) — event names only; never the address ==');
$evo = new EvolutionApiService($cfg);
$rcSub = 0; $rcTot = 0;
if (!$evo->isConfigured()) {
    out('  Evolution not configured');
} else {
    foreach ($evo->configuredChannels() as $ch) {
        $inst = $evo->instanceFor($ch);
        if ($inst === '') continue;
        $rcTot++;
        $res = $evo->findWebhook($inst);
        $data = is_array($res['data'] ?? null) ? $res['data'] : [];
        $w = is_array($data['webhook'] ?? null) ? $data['webhook'] : $data;
        if (empty($res['ok']) || !$w) { out('  ' . $ch . ': webhook settings → ' . (int)($res['http'] ?? 0) . ' (not read)'); continue; }
        $events = array_values(array_filter(array_map('strval', (array)($w['events'] ?? []))));
        sort($events);
        $url = (string)($w['url'] ?? '');
        $sub = in_array('MESSAGES_UPDATE', array_map('strtoupper', $events), true);
        if ($sub) $rcSub++;
        out('  ' . $ch . ': enabled ' . yn(!empty($w['enabled'])) . ' · points at this plugin ' . yn(strpos($url, 'evo_webhook') !== false)
            . ' · events ' . ($events ? implode(', ', $events) : 'none listed')
            . ' · delivery receipts (MESSAGES_UPDATE) ' . ($sub ? 'subscribed' : 'NOT subscribed'));
    }
}
out('@@RECEIPTS ' . $rcSub . ' ' . $rcTot);
out('@@DONE');
