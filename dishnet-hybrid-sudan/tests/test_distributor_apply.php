<?php
declare(strict_types=1);
/**
 * test_distributor_apply.php — 5.18.59: public distributor-recruitment intake.
 *
 * Covers the capture feature wired to the website's "Become a DishNet
 * Distributor" page:
 *   (A) migration 077 creates dist_partner_applications,
 *   (B) DistributorApplicationService::normalise() — the server-side gate
 *       (never trusts the browser: labels derived, ids whitelisted, control
 *       chars stripped, lengths capped, required fields enforced),
 *   (C) create/get/list/counts against a real temp DB,
 *   (D) the public endpoint's CORS + anti-abuse guards, by source,
 *   (E) the boundary: NO uCRM call, NO client/partner/account creation,
 *   (F) the route is wired public (before the login gate),
 *   (G) manifest version.
 */
$root = dirname(__DIR__);
require_once $root . '/lib/bootstrap_data.php';
require_once $root . '/lib/StoreInterface.php';
require_once $root . '/lib/SqliteStore.php';
require_once $root . '/lib/DistributorApplicationService.php';

$pass = 0; $fail = 0;
function is_(bool $c, string $m, string $d = ''): void { global $pass, $fail;
    if ($c) { $pass++; echo "  ok   $m\n"; } else { $fail++; echo "  FAIL $m" . ($d !== '' ? "\n       $d" : '') . "\n"; } }
function nc(string $f): string { $o=''; foreach (token_get_all((string)file_get_contents($f)) as $k){ if(is_array($k)){ if(in_array($k[0],[T_COMMENT,T_DOC_COMMENT],true)) continue; $o.=$k[1]; } else $o.=$k; } return $o; }

$tmp = sys_get_temp_dir() . '/dpa_' . bin2hex(random_bytes(4));
@mkdir($tmp, 0777, true);
$store = SqliteStore::create($tmp);
$pdo = $store->getPdo();
$svc = DistributorApplicationService::fromStore($store, $tmp);

echo "A. migration 077 — the table exists\n";
$has = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='dist_partner_applications'")->fetchColumn();
is_($has === 'dist_partner_applications', 'dist_partner_applications table created on SqliteStore::create');
$cols = array_column($pdo->query("PRAGMA table_info(dist_partner_applications)")->fetchAll(\PDO::FETCH_ASSOC), 'name');
foreach (['id','created_at','status','partner_model','business_name','email','phone','services','activities','consent','ip','raw_json'] as $c) {
    is_(in_array($c, $cols, true), "column '$c' present");
}

echo "\nB. normalise() — the server-side gate\n";
$good = ['model'=>'retail','source'=>'website-become-a-distributor',
    'profile'=>['biz_name'=>'Example Traders Ltd','trading_name'=>'ExTrade','biz_type'=>'Limited company',
        'contact_name'=>'Jane Doe','contact_role'=>'Director','email'=>'jane@example.co.ug',
        'phone'=>'+256 700 111 222','city'=>'Kampala','website'=>'https://x.ug','desc'=>'We sell electronics.','operating'=>'Yes'],
    'services'=>['starlink','data'],'activities'=>['sell_hardware','outlet'],'consent'=>true,
    'coverage'=>['regions'=>'Kampala','groups'=>['SMEs']],'readiness'=>['sales_exp'=>'Some'],
    'training'=>[['group'=>'Core','modules'=>['Intro']]]];
$n = DistributorApplicationService::normalise($good);
is_($n['ok'] === true && $n['errors'] === [], 'a complete valid application passes', json_encode($n['errors']));
is_($n['clean']['model_label'] === 'Retail outlet or reseller', 'model label is DERIVED server-side');
is_(strpos($n['clean']['services'], 'Starlink') !== false && strpos($n['clean']['services'], 'Data Network') !== false, 'service labels derived from ids');
is_($n['clean']['consent'] === 1, 'consent normalised to 1');

// required-field failures
$miss = DistributorApplicationService::normalise(['model'=>'retail','profile'=>[],'services'=>[],'activities'=>[],'consent'=>false]);
is_($miss['ok'] === false, 'an empty application is rejected');
foreach (['business_name','contact_name','email','phone','city','consent','services','activities'] as $e) {
    is_(in_array($e, $miss['errors'], true), "missing '$e' is reported");
}
// bad values
$bad = DistributorApplicationService::normalise(['model'=>'hacker',
    'profile'=>['biz_name'=>'X','contact_name'=>'Y','email'=>'not-an-email','phone'=>'12','city'=>'K'],
    'services'=>['evil','starlink'],'activities'=>['nope','refer'],'consent'=>1]);
is_(in_array('model', $bad['errors'], true), 'an unknown partner model is rejected');
is_(in_array('email', $bad['errors'], true), 'an invalid email is rejected');
is_(in_array('phone', $bad['errors'], true), 'a too-short phone is rejected');
is_($bad['clean']['services'] === json_encode(['Starlink']), 'unknown service ids are dropped, known kept');
is_($bad['clean']['activities'] === json_encode(['Refer customers to DishNet']), 'unknown activity ids are dropped, known kept');
// sanitisation
$dirty = DistributorApplicationService::normalise(['model'=>'referral',
    'profile'=>['biz_name'=>"Ref\x00 Co\x07",'contact_name'=>'A','email'=>'a@b.cd','phone'=>'0700111222','city'=>'Gulu',
        'desc'=>str_repeat('x', 9000)],
    'services'=>['starlink'],'activities'=>['refer'],'consent'=>1]);
is_(strpos($dirty['clean']['business_name'], "\x00") === false && strpos($dirty['clean']['business_name'], "\x07") === false, 'control characters are stripped');
is_(mb_strlen($dirty['clean']['description']) <= 4000, 'over-long description is capped');

echo "\nC. create / get / list / counts\n";
$r1 = $svc->create($n['clean'], ['ip'=>'1.2.3.4','user_agent'=>'UA','raw_json'=>json_encode($good)]);
is_($r1['id'] >= 1 && $r1['ref'] === DistributorApplicationService::ref($r1['id']), 'create returns id + DNP ref');
$got = $svc->get($r1['id']);
is_($got && $got['business_name'] === 'Example Traders Ltd' && $got['status'] === 'received', 'get round-trips the row, status defaults received');
is_($got['ip'] === '1.2.3.4' && $got['raw_json'] !== '', 'request metadata (ip, raw_json) stored');
$svc->create(DistributorApplicationService::normalise($good)['clean'], []);
$list = $svc->listRecent();
is_($list['total'] === 2 && count($list['items']) === 2, 'list returns both, newest first');
is_((int)$list['items'][0]['id'] > (int)$list['items'][1]['id'], 'list ordered id DESC');
$c = $svc->counts();
is_($c['total'] === 2 && $c['received'] === 2, 'counts by status');

echo "\nD. the public endpoint — CORS + anti-abuse guards (by source)\n";
$ep = nc($root . '/distributor_apply.php');
is_(strpos($ep, 'PublicPriceFeed::allowedOrigins($config)') !== false, 'origin allow-list from site_origins (shared with the price feed)');
is_(strpos($ep, "header('Access-Control-Allow-Origin: ' . \$origin)") !== false && strpos($ep, '$originOk') !== false, 'ACAO reflected only for an allowed origin (never *)');
is_(strpos($ep, "=== 'OPTIONS'") !== false, 'OPTIONS preflight handled');
is_(strpos($ep, "!== 'POST'") !== false, 'POST-only');
is_(strpos($ep, "\$_POST['hp']") !== false, 'honeypot checked');
is_(strpos($ep, "\$_POST['t']") !== false && strpos($ep, '2500') !== false, 'minimum fill-time enforced');
is_(strpos($ep, 'dpa_rate_limited') !== false, 'per-IP rate limit present');
is_(strpos($ep, "\$_POST['payload']") !== false && strpos($ep, '20000') !== false, 'payload read with a size cap');
is_(strpos($ep, 'DistributorApplicationService::normalise') !== false, 'server-side validation via normalise()');
is_(strpos($ep, 'HTTP_X_FORWARDED_FOR') !== false, 'client IP taken from the trusted forwarded hop');

echo "\nE. boundary — no uCRM, no client/partner/account creation\n";
foreach (['distributor_apply.php', 'lib/DistributorApplicationService.php'] as $f) {
    $src = nc($root . '/' . $f);
    is_(strpos($src, 'CrmApiClient') === false, "$f does not use CrmApiClient");
    is_(strpos($src, 'api/v2.1') === false && strpos($src, 'X-Auth-App-Key') === false, "$f makes no uCRM API call");
    is_(stripos($src, 'createClient') === false, "$f creates no uCRM client");
}

echo "\nF. the route is wired PUBLIC (before the login gate)\n";
$pub = nc($root . '/public.php');
is_(strpos($pub, "\$page === 'distributor_apply'") !== false, 'public.php routes page=distributor_apply');
$posRoute = strpos($pub, "\$page === 'distributor_apply'");
$posGate  = strpos($pub, "requireLogin()");
is_($posRoute !== false && $posGate !== false && $posRoute < $posGate, 'the route is reached before the login gate');
is_(strpos($pub, "'partner_applications'=> 'tabs/admin/partner_applications.php'") !== false, 'the review tab is registered in $_tabFiles');
is_(strpos($pub, "'partner_applications' => '*admin'") !== false, 'the review tab is admin-gated in $_tabPerms');
// The review MODULE (nav + the Staff-page permission matrix, which lists every $ALL_MODULES entry) is added only on the
// Uganda tenant, so a South Sudan / non-Uganda install's admin UI is unchanged (proved by render in
// test_staff_jobs_south_sudan.php). Here: the array entry is behind the $_staffJobsUganda gate.
is_(strpos($pub, "\$_staffJobsUganda ? [['id'=>'partner_applications'") !== false,
    'the Distributor Applications module is added to $ALL_MODULES only on the Uganda tenant ($_staffJobsUganda)');

echo "\nG. manifest version\n";
$mani = json_decode((string)file_get_contents($root . '/manifest.json'), true);
is_(($mani['information']['version'] ?? '') === '5.18.85', 'manifest version is 5.18.71');

exec('rm -rf ' . escapeshellarg($tmp));
echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
