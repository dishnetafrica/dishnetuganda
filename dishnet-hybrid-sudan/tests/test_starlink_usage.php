<?php
declare(strict_types=1);
/**
 * test_starlink_usage.php — collecting usage on our own session.
 *
 * The payload in here is the real one, field for field, as returned by
 * /api/telemetryagg/v1/data-usage/account/{acc}/service-line/{sl}/annotated
 * for a live Uganda service line on 2026-09-13: seven billing cycles, a
 * servicePlan in UGX, usageLimitGB 100000, and — the detail that matters —
 * cycles running from March against a subscription that began on 11 September.
 *
 * Starlink returns every cycle the LINE has ever had, including ones that
 * closed before this customer's subscription existed. Writing those through as
 * "0 GB used" would put a flat zero on a customer who did not have the service
 * yet, on the screen a person reads to decide whether a dish is faulty.
 */
require_once dirname(__DIR__) . '/lib/StoreInterface.php';
require_once dirname(__DIR__) . '/lib/JsonStore.php';
require_once dirname(__DIR__) . '/lib/SqliteStore.php';
require_once dirname(__DIR__) . '/lib/StockService.php';
require_once dirname(__DIR__) . '/lib/MigrationRunner.php';
require_once dirname(__DIR__) . '/lib/EquipmentAssignment.php';
require_once dirname(__DIR__) . '/lib/SiblingPlugin.php';
require_once dirname(__DIR__) . '/lib/KitUsage.php';
require_once dirname(__DIR__) . '/lib/StarlinkSessionStore.php';
require_once dirname(__DIR__) . '/lib/StarlinkUsage.php';

$pass = 0; $fail = 0;
function t(string $n, $got, $want) { global $pass, $fail;
    if ($got === $want) { $pass++; printf("  ok   %s\n", $n); }
    else { $fail++; printf("  FAIL %s\n       got  %s\n       want %s\n", $n, var_export($got, true), var_export($want, true)); } }
function is_(bool $c, string $m, string $d = ''): void { global $pass, $fail;
    if ($c) { $pass++; echo "  ok   $m\n"; } else { $fail++; echo "  FAIL $m" . ($d ? "\n       $d" : '') . "\n"; } }

const ACCT = 'ACC-DF-15973474-59163-60';
const LINE = 'SL-DF-16046613-35504-0';
const KIT  = 'KIT404246364BX6';

/** The real shape, with cycles before and after the subscription began. */
function payload(array $over = []): array
{
    $cycles = [];
    // Mar → Sep: six cycles that closed before the service started.
    foreach ([['2026-03-11', '2026-04-11'], ['2026-04-11', '2026-05-11'],
              ['2026-05-11', '2026-06-11'], ['2026-06-11', '2026-07-11'],
              ['2026-07-11', '2026-08-11'], ['2026-08-11', '2026-09-11']] as [$a, $b]) {
        $cycles[] = ['dataUsageSummaryLines' => [], 'startDate' => $a . 'T00:00:00+00:00',
                     'endDate' => $b . 'T00:00:00+00:00', 'totalAmountGB' => 0, 'dailyData' => []];
    }
    // The live one.
    $cycles[] = ['dataUsageSummaryLines' => [], 'startDate' => '2026-09-11T00:00:00+00:00',
                 'endDate' => '2026-10-11T00:00:00+00:00', 'totalAmountGB' => 42.5,
                 'dailyData' => [['date' => '2026-09-12', 'gb' => 12.25]]];

    return array_replace_recursive([
        'content' => [
            'dataBuckets' => [['name' => 'Residential Data', 'style' => 0, 'color' => 0]],
            'overageProductSummaryLine' => null,
            'billingCyclesAnnotated'    => $cycles,
            'servicePlan' => [
                'isoCurrencyCode' => 'UGX', 'isMobilePlan' => false,
                'activeFrom' => '2026-09-11T14:58:18.590675+00:00',
                'subscriptionActiveFrom' => '2026-09-11T14:58:15.268408+00:00',
                'subscriptionEndDate' => null,
                'productId' => 'ug-consumer-subscription-residential-100-mbps',
                'usageLimitGB' => 100000, 'dataCategoryMapping' => [],
            ],
        ],
        'errors' => [], 'warnings' => [], 'information' => [], 'isValid' => true,
    ], $over);
}

echo "\nCycles from before the subscription are not this customer's zeros\n";
$rows = StarlinkUsage::rowsFrom(payload(), KIT, LINE);
t('only the live cycle survives', count($rows), 1);
t('and it is the September one',  $rows[0]['cycle_key'], '2026-09-11');
t('with the real figure',         $rows[0]['total_gb'], 42.5);
is_(strpos($rows[0]['cycle_label'], 'Sep') !== false, 'labelled for a person: ' . $rows[0]['cycle_label']);

echo "\nWhat a collected row carries\n";
t('the kit it belongs to',   $rows[0]['kit_number'], KIT);
t('and the line it came from', $rows[0]['service_line'], LINE);
t('the allowance as stated',   $rows[0]['limit_gb'], 100000.0);
t('the plan id',               $rows[0]['product_id'], 'ug-consumer-subscription-residential-100-mbps');
t('the currency Starlink bills in', $rows[0]['currency'], 'UGX');
is_($rows[0]['source'] === 'dishnet-hybrid-sudan', 'and says we collected it, not the sibling');

echo "\nA payload with nothing in it yields nothing, not a zero\n";
// Built directly: array_replace_recursive cannot empty a list, it can only
// merge into one, so asking payload() for "no cycles" would silently hand back
// all seven and the assertion would pass on the wrong data.
$empty = payload();
$empty['content']['billingCyclesAnnotated'] = [];
t('no cycles at all', StarlinkUsage::rowsFrom($empty, KIT, LINE), []);
t('no content',       StarlinkUsage::rowsFrom(['errors' => []], KIT, LINE), []);
$noPlan = payload();
unset($noPlan['content']['servicePlan']);
$r2 = StarlinkUsage::rowsFrom($noPlan, KIT, LINE);
t('without a plan, every cycle is kept — none can be ruled out', count($r2), 7);
t('and the allowance is null, never assumed', $r2[0]['limit_gb'], null);

echo "\nRows sort oldest first, the way KitUsage reads them\n";
$keys = array_map(static fn(array $r): string => $r['cycle_key'], $r2);
$sorted = $keys; sort($sorted);
t('already in order', $keys, $sorted);

// ── Collection, through a fake Starlink ──────────────────────────────────
function usageEnv(): array
{
    $base = sys_get_temp_dir() . '/dn_su_' . bin2hex(random_bytes(4));
    @mkdir($base . '/plugins/dishnet-hybrid-sudan', 0777, true);
    @mkdir($base . '/data', 0777, true);
    putenv('DN_PLUGIN_ROOT=' . $base . '/plugins/dishnet-hybrid-sudan');
    SiblingPlugin::reset();
    $store = SqliteStore::create($base . '/data');
    $pdo   = $store->getPdo();
    $stock = StockService::fromStore($store, $base . '/data');
    $stock->ensureTables();
    foreach (MigrationRunner::splitStatements((string)file_get_contents(
            dirname(__DIR__) . '/migrations/068_equipment_assignments.sql')) as $q) $pdo->exec($q);
    $cat = (int)($stock->saveCategory(['title' => 'Kit', 'sku' => 'K',
        'service_type' => 'starlink', 'track_mode' => 'serial'])['id'] ?? 0);
    $sess = new StarlinkSessionStore($base . '/plugins/dishnet-hybrid-sudan', $base . '/data');
    return ['base' => $base, 'dir' => $base . '/data', 'ea' => new EquipmentAssignment($pdo),
            'stock' => $stock, 'cat' => $cat, 'session' => $sess];
}

echo "\nCollecting for a bound customer whose account we hold\n";
$e = usageEnv();
$u = (int)$e['stock']->createUnit(['category_id' => $e['cat'], 'serial_number' => KIT], 7, 'b')['id'];
$e['stock']->install($u, ['crm_client_id' => 7, 'crm_service_id' => 1,
    'starlink_service_line' => LINE, 'starlink_account' => ACCT], 7, 'b');
$e['session']->importCookie('Starlink.Com.Sso=x; starlink.com.account_number=' . ACCT, 'tester');

$asked = [];
$http = function (string $m, string $url, array $h) use (&$asked) {
    $asked[] = $url;
    return ['code' => 200, 'body' => json_encode(payload()), 'cookies' => [], 'headers' => []];
};
$res = (new StarlinkUsage($e['session'], [], $http))->collect($e['ea']->liveAssignments());
t('one row collected', count($res['rows']), 1);
is_(strpos($asked[0] ?? '', '/api/telemetryagg/v1/data-usage/account/' . ACCT
    . '/service-line/' . LINE . '/annotated') !== false,
    'at the proven endpoint, with account AND line', $asked[0] ?? '(nothing asked)');
t('the report says collected', $res['report'][0]['status'], 'collected');
t('and the account is accounted for', $res['accounts'][ACCT], '1 of 1 collected');

echo "\nAn account we hold no session for is named, not silently dropped\n";
$e2 = usageEnv();
$u2 = (int)$e2['stock']->createUnit(['category_id' => $e2['cat'], 'serial_number' => KIT], 7, 'b')['id'];
$e2['stock']->install($u2, ['crm_client_id' => 7, 'crm_service_id' => 1,
    'starlink_service_line' => LINE, 'starlink_account' => 'ACC-OTHER-1'], 7, 'b');
$e2['session']->importCookie('Starlink.Com.Sso=x; starlink.com.account_number=' . ACCT, 'tester');
$res2 = (new StarlinkUsage($e2['session'], [], $http))->collect($e2['ea']->liveAssignments());
t('nothing collected',        count($res2['rows']), 0);
t('and the reason is named',  $res2['report'][0]['status'], 'no_session');
is_(strpos($res2['report'][0]['why'], 'ACC-OTHER-1') !== false, 'with the account that is missing');

echo "\nA dead session is reported as dead, not as zero usage\n";
$deadHttp = static fn(string $m, string $u, array $h): array =>
    ['code' => 200, 'body' => '<!DOCTYPE html><html>sign in</html>', 'cookies' => [], 'headers' => []];
$res3 = (new StarlinkUsage($e['session'], [], $deadHttp))->collect($e['ea']->liveAssignments());
t('no rows',                 count($res3['rows']), 0);
t('reported as failed',      $res3['report'][0]['status'], 'failed');
is_(strpos($res3['report'][0]['why'], 'not JSON') !== false, 'naming the sign-in page');

echo "\nIt refuses to write an empty file over a good one\n";
$w = (new StarlinkUsage($e['session'], []))->save($e['dir'], []);
t('refused',          $w['ok'], false);
is_(strpos($w['why'], 'empty reads as zero') !== false, 'saying why');
is_(!is_file($e['dir'] . '/sl_usage.json'), 'and wrote nothing');

echo "\nWhat it writes, KitUsage reads — with no change to the screen\n";
$w = (new StarlinkUsage($e['session'], []))->save($e['dir'], $res['rows']);
t('written', $w['ok'], true);
$ku = new KitUsage($e['ea'], $e['dir']);
$got = $ku->forKit(KIT);
t('the kit has usage',      $got['available'], true);
t('one cycle',              count($got['cycles']), 1);
t('and the source is ours', $ku->source(), 'this plugin (sl_usage.json)');
$against = $ku->against(KIT, ['known' => true, 'unlimited' => false, 'cap_gb' => 100000.0]);
t('measured against the stated allowance', $against['used_gb'], 42.5);
is_($against['pct'] !== null && $against['pct'] < 1, 'as a percentage of it');

echo "\nWithout our file it still falls back to the sibling\n";
// Point the sibling resolver back at THIS environment: building the second one
// above moved DN_PLUGIN_ROOT, and the resolver caches per process.
putenv('DN_PLUGIN_ROOT=' . $e['base'] . '/plugins/dishnet-hybrid-sudan');
@unlink($e['dir'] . '/sl_usage.json');
@mkdir($e['base'] . '/plugins/dishnet-data-report/data', 0777, true);
file_put_contents($e['base'] . '/plugins/dishnet-data-report/data/sl_usage.json',
    json_encode([['kit_number' => KIT, 'cycle_key' => '2026-09', 'total_gb' => 7.0]]));
SiblingPlugin::reset();
$ku2 = new KitUsage($e['ea'], $e['dir']);
t('the sibling is read',   $ku2->forKit(KIT)['available'], true);
t('and named as the source', $ku2->source(), 'dishnet-data-report');

echo "\nThe cron is wired and safe to include\n";
$src = (string)file_get_contents(dirname(__DIR__) . '/cron/starlink_usage.php');
is_(preg_match('/^\s*exit\s*[(;]/m', $src) === 0, 'it never exit()s — master.php includes it');
is_(strpos($src, 'return;') !== false, 'it returns');
$master = (string)file_get_contents(dirname(__DIR__) . '/cron/master.php');
is_(strpos($master, "'starlink_usage'") !== false, 'master.php dispatches it');
is_((bool)preg_match("/'starlink_usage'\s*=>\s*\['interval'\s*=>\s*3600/", $master), 'hourly');

exec('rm -rf ' . escapeshellarg($e['base']) . ' ' . escapeshellarg($e2['base']));
printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
