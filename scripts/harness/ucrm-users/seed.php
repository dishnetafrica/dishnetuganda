<?php
/**
 * seed.php — builds the fake install the ucrm-users rehearsal reads.
 *
 *   php seed.php <plugin dir> <data dir> <fake uCRM url> <fake Evolution url> <uCRM seed out> <scenario>
 *
 * Scenarios:
 *   normal  three uCRM users: one shares the admin account's e-mail, one carries a phone only in its DETAIL record
 *           (nested, contacts.*.phone) and is reachable at users/{id}, one is inactive and shares a support account's
 *           e-mail; two uCRM-id settings, one real and one not
 *   prod    the shape measured on 27 Sep 15:54 UTC: one uCRM user, the admin's e-mail, no phone anywhere; follow-ups
 *           on, as measured on 27 Sep 20:31 UTC
 *   prod-ss prod on South Sudan's currency, where the follow-up crons skip nobody
 *
 * Every personal value is a CANARY: the rehearsal asserts that none of them reaches the report's output.
 */
[$_, $plugin, $pdd, $ucrm, $evo, $seedOut, $scenario] = $argv + [6 => 'normal'];
require_once $plugin . '/lib/StoreInterface.php';
require_once $plugin . '/lib/SqliteStore.php';

@mkdir($pdd, 0700, true); @mkdir($plugin . '/data', 0700, true);
file_put_contents($plugin . '/ucrm.json', json_encode([
    'pluginDataDir' => '/data/ucrm/data/plugins/.dishnet-hybrid-sudan-data',
    'ucrmLocalUrl'  => rtrim($ucrm, '/') . '/',
    'ucrmPublicUrl' => 'https://canary-host.example/crm/',
    'pluginAppKey'  => 'CANARY-APPKEY-abcdefghijklmnopqrstuvwxyz0123456789',
]));
file_put_contents($plugin . '/.deployed-commit', "e076632\n");

// Production's shape: no tenant_profile and no timezone setting; the currency UGX selects uganda.
$cfg = [
    'currency_code' => 'UGX', 'dry_run_mode' => false, 'followup_enabled' => false,
    'evo_api_url' => $evo, 'evo_api_key' => 'CANARY-EVO-KEY-0123456789abcdef0123456789',
    'evo_instance_support' => 'canary-support-inst', 'evo_instance_account' => 'canary-account-inst',
    'evo_instance_sales' => 'canary-sales-inst',
];
if ($scenario === 'normal') { $cfg['bidal_ucrm_user_id'] = 1007; $cfg['accountant_ucrm_user_id'] = 55; }
if ($scenario === 'prod' || $scenario === 'prod-ss') $cfg['followup_enabled'] = true;
if ($scenario === 'prod-ss') $cfg['currency_code'] = 'SSP';
$store = SqliteStore::create($pdd);          // first: a first boot renames any *.json it finds to *.json.migrated
$store->save('kyc_config.json', $cfg);
file_put_contents($pdd . '/kyc_config.json', json_encode($cfg));   // what PluginConfig and dn_tz() read

$e = [
    'admin'   => 'canary.admin@canary-mail.test',
    'acct'    => 'canary.accounts@canary-mail.test',
    'agent'   => 'canary.agent@canary-mail.test',
    'support' => 'canary.support@canary-mail.test',
    'owner'   => 'Canary.Owner@Canary-Mail.test',      // mixed case: the match must ignore case
];
$staff = [
    ['name' => 'Canary Adminname',   'email' => $e['admin'],   'role' => 'admin', 'is_admin' => true, 'phone' => '', 'ucrm_user_id' => 1],
    ['name' => 'Canary Accountname', 'email' => $e['acct'],    'role' => 'accountant', 'phone' => '+211 912 345 678'],
    ['name' => 'Canary Agentname',   'email' => $e['agent'],   'role' => 'support', 'is_employee' => false, 'phone' => '+256 771 234 567', 'ucrm_user_id' => 4],
    ['name' => 'Canary Supportname', 'email' => $e['support'], 'role' => 'support', 'phone' => '+256 772 345 678', 'ucrm_user_id' => 81],
    ['name' => 'Canary Ownername',   'email' => $e['owner'],   'role' => 'support', 'phone' => '+256 773 456 789', 'ucrm_user_id' => 1581, 'must_change_pwd' => true],
];
foreach ($staff as $s) $store->appendWithId('retailers.json', $s + ['is_active' => true, 'password' => '$2y$04$CANARYHASHcanaryhashcanaryhashcanaryhashcanaryhash12']);

$u1000 = ['id' => 1000, 'unmsId' => 'canary-unms-0000-aaaa-bbbb', 'email' => strtoupper($e['admin']), 'firstName' => 'Canary', 'lastName' => 'Uadminname',
          'username' => 'canaryuadmin', 'avatarColor' => '#e53935', 'isActive' => true];
$users = ['list' => [$u1000], 'detail' => ['1000' => $u1000 + ['permissions' => ['scheduling' => 'edit']]], 'users_route_ok' => []];
if ($scenario === 'normal') {
    $u1007 = ['id' => 1007, 'unmsId' => 'canary-unms-1007-cccc-dddd', 'email' => 'canary.other@canary-mail.test', 'firstName' => 'Canary', 'lastName' => 'Uothername',
              'username' => 'canaryuother', 'avatarColor' => '#43a047', 'isActive' => true];
    $u1009 = ['id' => 1009, 'unmsId' => '', 'email' => $e['support'], 'firstName' => 'Canary', 'lastName' => 'Uinactivename',
              'username' => 'canaryuinactive', 'avatarColor' => '#1e88e5', 'isActive' => false];
    $users['list'][] = $u1007; $users['list'][] = $u1009;
    $users['detail']['1007'] = $u1007 + ['contacts' => [['type' => 'work', 'phone' => '+256 775 000 007'], ['type' => 'home', 'phone' => '']]];
    $users['detail']['1009'] = $u1009;
    $users['users_route_ok'] = [1007];
}
$users['clients']  = [['id' => 42, 'firstName' => 'Canary', 'lastName' => 'Clientname', 'registrationDate' => '2026-09-12T00:00:00+0300']];
$users['invoices'] = [['id' => 7, 'number' => 'CANARY-INV-7', 'total' => 98765, 'createdDate' => '2026-09-20T10:15:00+0300']];
$users['payments'] = [];
file_put_contents($seedOut, json_encode($users));
echo "seeded\n";
