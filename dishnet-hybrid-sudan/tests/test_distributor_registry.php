<?php
declare(strict_types=1);
/**
 * test_distributor_registry.php — 5.18.61: the distributor registry (WS-A P1a, docs/49).
 *
 *   (A) migration 078 creates dist_partners + dist_appointment,
 *   (B) DistributorRegistry create/get/list/setStatus + TIN/uCRM dedupe + enum guards
 *       (each negative paired with a positive control),
 *   (C) appointFromApplication: prospect + provenance; one appointment per application;
 *       and the headline rule — dedupe is NEVER by phone (two same-phone applications
 *       appoint to two distinct partners),
 *   (D) the boundary: NO uCRM call, NO client/account creation anywhere in the batch,
 *   (E) the tab + POST handler are wired and gated (admin + distributors_enabled + Uganda),
 *   (F) off by default: nothing is reachable until the flag is set,
 *   (G) manifest version.
 */
$root = dirname(__DIR__);
require_once $root . '/lib/bootstrap_data.php';
require_once $root . '/lib/StoreInterface.php';
require_once $root . '/lib/SqliteStore.php';
require_once $root . '/lib/DistributorRegistry.php';
require_once $root . '/lib/DistributorApplicationService.php';

$pass = 0; $fail = 0;
function is_(bool $c, string $m, string $d = ''): void { global $pass, $fail;
    if ($c) { $pass++; echo "  ok   $m\n"; } else { $fail++; echo "  FAIL $m" . ($d !== '' ? "\n       $d" : '') . "\n"; } }
function nc(string $f): string { $o=''; foreach (token_get_all((string)file_get_contents($f)) as $k){ if(is_array($k)){ if(in_array($k[0],[T_COMMENT,T_DOC_COMMENT],true)) continue; $o.=$k[1]; } else $o.=$k; } return $o; }
function threw(callable $fn): bool { try { $fn(); return false; } catch (\Throwable $e) { return true; } }

$tmp = sys_get_temp_dir() . '/dreg_' . bin2hex(random_bytes(4));
@mkdir($tmp, 0777, true);
$store = SqliteStore::create($tmp);
$pdo = $store->getPdo();
$reg = DistributorRegistry::fromStore($store);
$apps = DistributorApplicationService::fromStore($store, $tmp);

echo "A. migration 078 — the tables exist\n";
foreach (['dist_partners', 'dist_appointment'] as $t) {
    $has = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='$t'")->fetchColumn();
    is_($has === $t, "$t table created on SqliteStore::create");
}
$cols = array_column($pdo->query("PRAGMA table_info(dist_partners)")->fetchAll(\PDO::FETCH_ASSOC), 'name');
foreach (['id','partner_code','partner_type','category','status','legal_name','trading_name','tin','tin_norm','ucrm_client_id','created_by','created_at'] as $c) {
    is_(in_array($c, $cols, true), "dist_partners column '$c' present");
}
$pcols = array_column($pdo->query("PRAGMA table_info(dist_partners)")->fetchAll(\PDO::FETCH_ASSOC), 'name');
is_(!in_array('phone', $pcols, true), 'dist_partners has NO phone column (dedupe is structurally never by phone)');
foreach (['idx_dist_partner_tinnorm','idx_dist_partner_ucrm','idx_dist_appointment_app'] as $ix) {
    $hasIx = $pdo->query("SELECT name FROM sqlite_master WHERE type='index' AND name='$ix'")->fetchColumn();
    is_($hasIx === $ix, "index '$ix' created");
}

echo "\nB. create / dedupe / enums (each negative has a positive control)\n";
$r1 = $reg->create(['legal_name'=>'Alpha Traders Ltd','trading_name'=>'Alpha','partner_type'=>'regional_distributor','tin'=>'1000-200-300'], 'admin <a@x>');
is_($r1['id'] >= 1 && $r1['partner_code'] === DistributorRegistry::code($r1['id']), 'create returns id + DP- code');
$got = $reg->get($r1['id']);
is_($got && $got['status'] === 'prospect' && $got['legal_name'] === 'Alpha Traders Ltd', 'get round-trips; status defaults prospect');
is_($got['tin_norm'] === '1000200300', 'TIN normalised for dedupe');

// TIN dedupe: a different SPELLING of the same TIN is refused; a genuinely different TIN succeeds (control).
is_(threw(fn() => $reg->create(['legal_name'=>'Alpha Again','tin'=>'1000 200 300'], 'admin <a@x>')),
    'a duplicate TIN (different spelling, same normalised value) is REFUSED');
$r2 = $reg->create(['legal_name'=>'Beta Co','tin'=>'9000-800-700'], 'admin <a@x>');
is_($r2['id'] !== $r1['id'], 'control: a different TIN succeeds');
is_(DistributorRegistry::normTin('u-123 456') === DistributorRegistry::normTin('U123456'), 'normTin is case/separator-insensitive');

// enum guards, each with a positive control.
is_(threw(fn() => $reg->create(['legal_name'=>'X','partner_type'=>'not_a_type'], 'a')), 'unknown partner_type refused');
is_(threw(fn() => $reg->create(['legal_name'=>'X','category'=>'not_a_cat'], 'a')), 'unknown category refused');
is_(threw(fn() => $reg->create(['legal_name'=>'X','status'=>'wat'], 'a')), 'unknown status refused');
$r3 = $reg->create(['legal_name'=>'Gamma','partner_type'=>'authorised_reseller','category'=>'electronics','status'=>'onboarding'], 'a');
is_($r3['id'] >= 1, 'control: valid enum values accepted');

// setStatus
is_($reg->setStatus($r3['id'], 'active', 'admin <a@x>') === true, 'setStatus updates a partner');
is_($reg->get($r3['id'])['status'] === 'active', 'status read back');
is_(threw(fn() => $reg->setStatus($r3['id'], 'bogus', 'a')), 'setStatus refuses an unknown status');

echo "\nC. appointFromApplication — prospect + provenance; one per application; NEVER by phone\n";
// Seed two DNP applications that SHARE a contact phone (the key anti-pattern).
$mk = function (string $biz, string $phone) use ($pdo): int {
    $pdo->prepare("INSERT INTO dist_partner_applications (business_name, phone, partner_model, status) VALUES (?,?, 'regional', 'received')")
        ->execute([$biz, $phone]);
    return (int)$pdo->lastInsertId();
};
$appA = $mk('Same-Phone One Ltd', '+256700111222');
$appB = $mk('Same-Phone Two Ltd', '+256700111222');   // identical phone, different business

$ap1 = $reg->appointFromApplication($appA, [], 'admin <a@x>');
is_(isset($ap1['partner_id'], $ap1['partner_code'], $ap1['appointment_id']), 'appoint returns partner + appointment ids');
$pp = $reg->get($ap1['partner_id']);
is_($pp && $pp['status'] === 'prospect' && $pp['legal_name'] === 'Same-Phone One Ltd', 'appointment creates a prospect seeded from the application');
is_($pp['ucrm_client_id'] === null, 'the appointed prospect has NO uCRM link (that is P1b)');
is_(!empty($reg->appointedApplicationIds()[$appA]), 'the application is recorded as appointed');

// Second appointment of the SAME application is refused (provenance uniqueness).
is_(threw(fn() => $reg->appointFromApplication($appA, [], 'admin <a@x>')), 'appointing the same application twice is refused');

// The headline: the OTHER same-phone application appoints to a DISTINCT partner. No phone dedupe.
$ap2 = $reg->appointFromApplication($appB, [], 'admin <a@x>');
is_($ap2['partner_id'] !== $ap1['partner_id'], 'two applications sharing a phone yield two DISTINCT partners (never deduped by phone)');
is_(count($reg->listAll()) >= 2, 'both same-phone partners exist');

echo "\nD. boundary — registry + tab never touch uCRM; the P1b link handler only READS, never creates\n";
// The registry's linkUcrmClient is duck-typed on a passed-in client, and the tab only renders a form,
// so neither references CrmApiClient. (Updated in 5.18.62: post_distributors.php now DOES build a
// CrmApiClient — deliberately, to READ an existing company client for the link — asserted below.)
foreach (['lib/DistributorRegistry.php','tabs/admin/distributors.php'] as $f) {
    $src = nc($root . '/' . $f);
    is_(strpos($src, 'CrmApiClient') === false, "$f does not reference CrmApiClient");
    is_(stripos($src, 'createClient') === false, "$f creates no uCRM client");
}
$ph = nc($root . '/includes/post/post_distributors.php');
is_(strpos($ph, 'linkUcrmClient') !== false, 'the link handler goes through DistributorRegistry::linkUcrmClient (read + local write)');
is_(stripos($ph, 'createClient') === false, 'the link handler creates no uCRM client');
is_(strpos($ph, "->post(") === false && strpos($ph, "->patch(") === false && strpos($ph, "->delete(") === false,
    'the link handler issues no uCRM write (no POST/PATCH/DELETE)');

echo "\nE. the tab + POST handler are wired and gated\n";
$pub = nc($root . '/public.php');
is_(strpos($pub, "'distributors'       => 'tabs/admin/distributors.php'") !== false, 'public.php registers the distributors tab in $_tabFiles');
is_(strpos($pub, "'distributors' => '*admin'") !== false, 'the distributors tab is admin-gated in $_tabPerms');
is_(strpos($pub, "distributors_enabled") !== false && strpos($pub, "id'=>'distributors'") !== false, 'the nav module is behind the distributors_enabled flag');
is_(strpos($pub, "\$_staffJobsUganda && is_array(\$config ?? null) && !empty(\$config['distributors_enabled'])") !== false,
    'the nav module is gated on BOTH Uganda and the flag');
// 5.18.65: the registry also has a LEFT-SIDEBAR link in includes/navigation.php — the clickable item an admin
// actually sees — gated identically: admin + Uganda (StaffJobsGate) + distributors_enabled. ($ALL_MODULES above
// feeds the module list / Staff permission matrix; this is the sidebar entry.) The gate must sit immediately
// before the link, so a copy that drops it fails this.
$nav    = nc($root . '/includes/navigation.php');
$navPos = strpos($nav, 'tab=distributors');
is_($navPos !== false, 'the left sidebar has a Distributors link (tab=distributors)');
$navGate = $navPos !== false ? substr($nav, max(0, $navPos - 500), 500) : '';
is_(strpos($navGate, '$isAdmin') !== false
    && strpos($navGate, 'StaffJobsGate::applies') !== false
    && strpos($navGate, "distributors_enabled") !== false,
    'the sidebar link is gated by admin + Uganda (StaffJobsGate) + the flag, immediately before it');
$ph = nc($root . '/includes/post_handlers.php');
is_(strpos($ph, "/post/post_distributors.php") !== false, 'post_handlers.php includes the distributor handler');
$pd = nc($root . '/includes/post/post_distributors.php');
is_(strpos($pd, "dist_appoint") !== false, 'the handler acts on action=dist_appoint');
is_(strpos($pd, 'requireAdmin') !== false, 'the handler requires admin');
is_(strpos($pd, "distributors_enabled") !== false && strpos($pd, 'StaffJobsGate::applies') !== false, 'the handler is gated on the flag and Uganda');

echo "\nF. off by default — nothing reachable until the flag is set\n";
$tab = nc($root . '/tabs/admin/distributors.php');
is_(strpos($tab, "empty(\$isAdmin)") !== false, 'the tab denies a non-admin (defence-in-depth)');
is_(strpos($tab, "empty(\$config['distributors_enabled'])") !== false, 'the tab refuses when the flag is off');
is_(strpos($tab, 'CrmApiClient') === false, 'the tab calls no uCRM');

echo "\nG. manifest version\n";
$mani = json_decode((string)file_get_contents($root . '/manifest.json'), true);
is_(($mani['information']['version'] ?? '') === '5.18.70', 'manifest version is 5.18.70');

exec('rm -rf ' . escapeshellarg($tmp));
echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
