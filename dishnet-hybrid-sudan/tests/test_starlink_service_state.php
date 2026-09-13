<?php
declare(strict_types=1);
/**
 * test_starlink_service_state.php — what Starlink says, joined on the line.
 *
 * The fixture in here is not invented. It is the record shape the Uganda
 * server actually holds in dishnet-data-report/data/sl_svc_cache.json, down
 * to the empty kit_number — which is the whole reason this reader exists.
 * On that box sl_usage.json and dr_kit_registry.json are both empty, because
 * both are keyed by a kit serial the data plugin never resolves, while the
 * cache it keys by SERVICE LINE holds fifteen live subscriptions.
 *
 * So the tests that matter most here are the ones about not knowing:
 *
 *   · subscription_active null is NOT "inactive" — Starlink has not said
 *   · a line absent from the cache is NOT "inactive" — we have not asked
 *   · no service line recorded is NOT "inactive" — we never wrote one down
 *   · usage silent and subscription active are INDEPENDENT facts
 *
 * Collapse any of those into "inactive" and the screen tells an operator to
 * go and fix a dish that is working perfectly.
 */
require_once dirname(__DIR__) . '/lib/StoreInterface.php';
require_once dirname(__DIR__) . '/lib/JsonStore.php';
require_once dirname(__DIR__) . '/lib/SqliteStore.php';
require_once dirname(__DIR__) . '/lib/StockService.php';
require_once dirname(__DIR__) . '/lib/MigrationRunner.php';
require_once dirname(__DIR__) . '/lib/EquipmentAssignment.php';
require_once dirname(__DIR__) . '/lib/ServicePlan.php';
require_once dirname(__DIR__) . '/lib/SiblingPlugin.php';
require_once dirname(__DIR__) . '/lib/KitUsage.php';
require_once dirname(__DIR__) . '/lib/StarlinkServiceState.php';
require_once dirname(__DIR__) . '/lib/CrmKitAttribute.php';
require_once dirname(__DIR__) . '/lib/StarlinkFleet.php';

$pass = 0; $fail = 0;
function t(string $n, $got, $want) { global $pass, $fail;
    if ($got === $want) { $pass++; printf("  ok   %s\n", $n); }
    else { $fail++; printf("  FAIL %s\n       got  %s\n       want %s\n", $n, var_export($got, true), var_export($want, true)); } }
function is_(bool $c, string $m, string $d = ''): void { global $pass, $fail;
    if ($c) { $pass++; echo "  ok   $m\n"; } else { $fail++; echo "  FAIL $m" . ($d ? "\n       $d" : '') . "\n"; } }

/** The record the Uganda box really holds, kit_number and all. */
function ugLine(array $over = []): array
{
    return $over + [
        'service_line'        => 'SL-DF-15754766-41032-7',
        'kit_number'          => '',
        'account_number'      => 'ACC-DF-15744579-40001-43',
        'has_telemetry'       => true,
        'plan_id'             => 'ug-consumer-subscription-residential-100-mbps',
        'product_desc'        => 'Residential - 100 Mbps',
        'isPaused'            => false,
        'isSuspended'         => false,
        'isStandby'           => false,
        'canPauseService'     => false,
        'canResumeService'    => false,
        'subscription_active' => true,
        'pendingActivation'   => false,
        'sl_status'           => 7,
        'sl_synced_at'        => gmdate('c'),
    ];
}

/** A plugin tree with a data-report sibling, and optionally a cache in it. */
function stateEnv(?array $cache): string
{
    $base    = sys_get_temp_dir() . '/dn_sls_' . bin2hex(random_bytes(4));
    $plugins = $base . '/plugins';
    @mkdir($plugins . '/dishnet-data-report/data', 0777, true);
    @mkdir($plugins . '/dishnet-hybrid-sudan', 0777, true);
    putenv('DN_PLUGIN_ROOT=' . $plugins . '/dishnet-hybrid-sudan');
    SiblingPlugin::reset();
    if ($cache !== null) {
        file_put_contents($plugins . '/dishnet-data-report/data/sl_svc_cache.json',
            json_encode($cache));
    }
    return $base;
}

echo "\nNo cache at all is not a fleet that is switched off\n";
stateEnv(null);
$s = new StarlinkServiceState();
t('rows() is null, not empty',          $s->rows(), null);
t('available() says no',                $s->available()['available'], false);
is_(strpos($s->available()['reason'], 'not the same as a fleet that is switched off') !== false,
    'and says so in as many words');
$r = $s->forLine('SL-DF-15754766-41032-7');
t('a line cannot be known',             $r['known'], false);
t('status stays empty, never inactive', $r['status'], '');
t('and the reason names the cause',     $r['reason'], 'the data-report plugin has written no service cache here');

echo "\nA cache that ran and saw nothing is its own answer\n";
stateEnv([]);
$s = new StarlinkServiceState();
t('rows() is empty, not null',          $s->rows(), []);
t('available() still no',               $s->available()['available'], false);
is_(strpos($s->available()['reason'], 'saw nothing') !== false, 'and blames the session, not the fleet');

echo "\nThe real Uganda record reads correctly\n";
stateEnv(['SL-DF-15754766-41032-7' => ugLine()]);
$s = new StarlinkServiceState();
t('one line held',                      $s->available()['lines'], 1);
$r = $s->forLine('SL-DF-15754766-41032-7');
t('known',                              $r['known'], true);
t('active',                             $r['status'], 'active');
t('labelled for a person',              $r['label'], 'Active');
t('the plan is the human one',          $r['plan'], 'Residential - 100 Mbps');
t('the account carries through',        $r['account'], 'ACC-DF-15744579-40001-43');
t('telemetry is advertised',            $r['telemetry'], true);
t('fresh, so not stale',                $r['stale'], false);
is_($r['age_hours'] !== null && $r['age_hours'] < 1.0, 'and its age is known');

echo "\nStatus precedence — the flag a person must act on wins\n";
t('pending beats active',   StarlinkServiceState::statusOf(ugLine(['pendingActivation' => true])), 'pending');
t('suspended beats active', StarlinkServiceState::statusOf(ugLine(['isSuspended' => true])), 'suspended');
t('pending beats suspended',
    StarlinkServiceState::statusOf(ugLine(['pendingActivation' => true, 'isSuspended' => true])), 'pending');
t('paused',                 StarlinkServiceState::statusOf(ugLine(['isPaused' => true])), 'paused');
t('suspended beats paused',
    StarlinkServiceState::statusOf(ugLine(['isSuspended' => true, 'isPaused' => true])), 'suspended');
t('standby',                StarlinkServiceState::statusOf(ugLine(['isStandby' => true])), 'standby');
t('plain active',           StarlinkServiceState::statusOf(ugLine()), 'active');
t('a known false is inactive',
    StarlinkServiceState::statusOf(ugLine(['subscription_active' => false])), 'inactive');

echo "\nThe registry's snake_case spellings mean the same thing\n";
t('pending_activation',   StarlinkServiceState::statusOf(
    ['subscription_active' => true, 'pending_activation' => true]), 'pending');
t('subscription_suspended', StarlinkServiceState::statusOf(
    ['subscription_active' => true, 'subscription_suspended' => true]), 'suspended');
t('subscription_paused',  StarlinkServiceState::statusOf(
    ['subscription_active' => true, 'subscription_paused' => true]), 'paused');
t('subscription_standby', StarlinkServiceState::statusOf(
    ['subscription_active' => true, 'subscription_standby' => true]), 'standby');

echo "\nSilence from Starlink is never reported as \"off\"\n";
t('null is not false', StarlinkServiceState::statusOf(['subscription_active' => null]), '');
t('absent is not false', StarlinkServiceState::statusOf(['product_desc' => 'x']), '');
stateEnv(['SL-A-1' => ['service_line' => 'SL-A-1', 'subscription_active' => null]]);
$s = new StarlinkServiceState();
$r = $s->forLine('SL-A-1');
t('so the line is not known',   $r['known'], false);
t('and is not called inactive', $r['status'], '');
t('the reason blames Starlink', $r['reason'], 'Starlink has not reported a subscription state for this line');

echo "\nFour ways of not knowing, each with its own words\n";
stateEnv(['SL-A-1' => ugLine(['service_line' => 'SL-A-1'])]);
$s = new StarlinkServiceState();
t('a line we never wrote down', $s->forLine('')['reason'],
    'no Starlink service line recorded on this assignment');
t('a line the cache has not got', $s->forLine('SL-NOT-THERE')['reason'],
    'this service line is not in the data plugin\'s cache');
is_($s->forLine('')['reason'] !== $s->forLine('SL-NOT-THERE')['reason'],
    'and those two are never the same sentence');

echo "\nThe join does not turn on case or whitespace\n";
t('lowercase matches',  $s->forLine('sl-a-1')['known'], true);
t('padded matches',     $s->forLine('  SL-A-1  ')['known'], true);
t('but a different line does not', $s->forLine('SL-A-2')['known'], false);

echo "\nAn old reading is shown with its age, not hidden\n";
stateEnv(['SL-OLD' => ugLine(['service_line' => 'SL-OLD',
    'sl_synced_at' => gmdate('c', time() - 30 * 3600)])]);
$s = new StarlinkServiceState();
$r = $s->forLine('SL-OLD');
t('still known',        $r['known'], true);
t('still active',       $r['status'], 'active');
t('but flagged stale',  $r['stale'], true);
is_($r['age_hours'] >= 29.9, 'with the age a person can judge');

echo "\nLines nobody is bound to are found, not silently dropped\n";
stateEnv([
    'SL-BOUND'   => ugLine(['service_line' => 'SL-BOUND']),
    'SL-ORPHAN'  => ugLine(['service_line' => 'SL-ORPHAN', 'isSuspended' => true]),
    'SL-ORPHAN2' => ugLine(['service_line' => 'SL-ORPHAN2', 'subscription_active' => null]),
]);
$s = new StarlinkServiceState();
$o = $s->unclaimed(['SL-BOUND']);
t('two are unclaimed',          count($o), 2);
t('sorted, so the list is stable', $o[0]['service_line'], 'SL-ORPHAN');
t('with its real state',        $o[0]['label'], 'Suspended');
t('an unreported one says so',  $o[1]['label'], 'Unreported');
t('claimed matching ignores case', count($s->unclaimed(['sl-bound'])), 2);
t('claiming everything leaves none', count($s->unclaimed(['SL-BOUND','SL-ORPHAN','SL-ORPHAN2'])), 0);

// ── Through the fleet, on the Uganda shape: usage empty, state full ──────
function fleetWith(array $cache, ?array $usage): array
{
    $base    = sys_get_temp_dir() . '/dn_slf_' . bin2hex(random_bytes(4));
    $plugins = $base . '/plugins';
    @mkdir($plugins . '/dishnet-data-report/data', 0777, true);
    @mkdir($base . '/data', 0777, true);
    putenv('DN_PLUGIN_ROOT=' . $plugins . '/dishnet-hybrid-sudan');
    SiblingPlugin::reset();
    file_put_contents($plugins . '/dishnet-data-report/data/sl_svc_cache.json', json_encode($cache));
    if ($usage !== null) {
        file_put_contents($plugins . '/dishnet-data-report/data/sl_usage.json', json_encode($usage));
    }

    $store = SqliteStore::create($base . '/data');
    $pdo   = $store->getPdo();
    $stock = StockService::fromStore($store, $base . '/data');
    $stock->ensureTables();
    foreach (MigrationRunner::splitStatements((string)file_get_contents(
            dirname(__DIR__) . '/migrations/068_equipment_assignments.sql')) as $q) $pdo->exec($q);
    $ea  = new EquipmentAssignment($pdo);
    $cat = (int)($stock->saveCategory(['title' => 'Kit', 'sku' => 'K',
        'service_type' => 'starlink', 'track_mode' => 'serial'])['id'] ?? 0);
    return ['store' => $store, 'ea' => $ea, 'stock' => $stock, 'cat' => $cat];
}

echo "\nThe Uganda box exactly: no usage anywhere, every line live\n";
$e = fleetWith([
    'SL-DF-15754766-41032-7' => ugLine(['pendingActivation' => true]),
    'SL-DF-99999999-00000-1' => ugLine(['service_line' => 'SL-DF-99999999-00000-1']),
], []);   // sl_usage.json is `[]` on that server — present, and empty
$u1 = (int)$e['stock']->createUnit(['category_id' => $e['cat'], 'serial_number' => 'KIT404246364BX6'], 7, 'b')['id'];
$u2 = (int)$e['stock']->createUnit(['category_id' => $e['cat'], 'serial_number' => 'KITNOLINE00002'], 7, 'b')['id'];
$e['stock']->install($u1, ['crm_client_id' => 7, 'crm_service_id' => 1,
    'starlink_service_line' => 'SL-DF-15754766-41032-7'], 7, 'b');
$e['stock']->install($u2, ['crm_client_id' => 9, 'crm_service_id' => 2], 7, 'b');

$f = (new StarlinkFleet($e['ea'], new KitUsage($e['ea']), $e['store'], null))->build();
$sum = $f['summary'];
$byKit = [];
foreach ($f['rows'] as $row) $byKit[$row['kit_serial']] = $row;

t('both kits list',                 $sum['kits'], 2);
t('and neither has a reading',      $sum['usage_silent'], 2);
t('yet one is known to Starlink',   $sum['live_known'], 1);
t('and it is pending activation',   $sum['live_pending'], 1);
t('the other has no state',         $sum['live_silent'], 1);
t('because no line was recorded',   $sum['no_service_line'], 1);
is_($byKit['KIT404246364BX6']['live']['known'] === true
    && $byKit['KIT404246364BX6']['usage']['used_gb'] === null,
    'one kit: active on Starlink AND silent on usage — both true at once');
t('the bound kit shows its plan',   $byKit['KIT404246364BX6']['live']['plan'], 'Residential - 100 Mbps');
t('the unbound kit says why',       $byKit['KITNOLINE00002']['live']['reason'],
    'no Starlink service line recorded on this assignment');
t('the cache is reported available', $f['starlink']['available'], true);
t('the second line is unclaimed',   count($f['unclaimed']), 1);
t('and named',                      $f['unclaimed'][0]['service_line'], 'SL-DF-99999999-00000-1');

echo "\nA fleet with no cache still renders, and says what is missing\n";
$e = fleetWith([], null);
$u = (int)$e['stock']->createUnit(['category_id' => $e['cat'], 'serial_number' => 'KITX1'], 7, 'b')['id'];
$e['stock']->install($u, ['crm_client_id' => 3, 'crm_service_id' => 1,
    'starlink_service_line' => 'SL-Z-1'], 7, 'b');
// the file written above is `[]`; remove it so there is no source at all
@unlink(dirname((string)getenv('DN_PLUGIN_ROOT')) . '/dishnet-data-report/data/sl_svc_cache.json');
SiblingPlugin::reset();
$f = (new StarlinkFleet($e['ea'], new KitUsage($e['ea']), $e['store'], null))->build();
t('the kit is still listed',        $f['summary']['kits'], 1);
t('no state is claimed',            $f['summary']['live_known'], 0);
t('none counted as inactive',       $f['summary']['live_inactive'], 0);
t('the screen is told why',         $f['starlink']['available'], false);
t('and no orphans are invented',    $f['unclaimed'], []);

// ── The count that fooled a person once already ──────────────────────────
//
// dr_kit_registry.json is an ENVELOPE. sibling_doctor printed count() of the
// decoded array, which is 7 metadata keys, for a file whose kits list is
// empty — and 7 was read off the screen as seven kits. This runs the real
// tool against the real envelope and insists it reports the payload.
echo "\nAn envelope reports its payload, not its metadata keys\n";
$base    = sys_get_temp_dir() . '/dn_doc_' . bin2hex(random_bytes(4));
$plugins = $base . '/plugins';
@mkdir($plugins . '/dishnet-data-report/data', 0777, true);
@mkdir($plugins . '/dishnet-hybrid-sudan', 0777, true);
// Verbatim from the Uganda server, 7 keys and no kits.
file_put_contents($plugins . '/dishnet-data-report/data/dr_kit_registry.json', json_encode([
    'schema_version'  => 1,
    'generated_at'    => gmdate('c'),
    'generator'       => 'dishnet-data-report 2.8.73',
    'contract'        => 'This file is owned by dishnet-data-report. Other plugins MAY read it.',
    'kit_count'       => 0,
    'auto_discovered' => 0,
    'kits'            => [],
], JSON_PRETTY_PRINT));
file_put_contents($plugins . '/dishnet-data-report/data/sl_usage.json', '[]');
file_put_contents($plugins . '/dishnet-data-report/data/sl_svc_cache.json',
    json_encode(['SL-A-1' => ugLine(['service_line' => 'SL-A-1'])]));

$out = (string)shell_exec('DN_PLUGIN_ROOT=' . escapeshellarg($plugins . '/dishnet-hybrid-sudan')
    . ' php ' . escapeshellarg(dirname(__DIR__) . '/tools/sibling_doctor.php') . ' 2>&1');

preg_match('/dr_kit_registry\.json\s+(\d+)\s+(\S+)/', $out, $m);
t('the registry reports 0',       $m[1] ?? 'no line', '0');
t('and names what it counted',    $m[2] ?? '', 'kits');
is_(strpos($out, '7       kits') === false && strpos($out, '7       rows') === false,
    'never 7 — the metadata keys are not records', $out);
preg_match('/sl_svc_cache\.json\s+(\d+)\s+(\S+)/', $out, $m2);
t('a plain map still counts rows', $m2[2] ?? '', 'rows');
t('and counts them right',        $m2[1] ?? '', '1');
preg_match('/sl_usage\.json\s+(\d+)/', $out, $m3);
t('an empty list is 0',           $m3[1] ?? 'no line', '0');

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
