<?php
declare(strict_types=1);
/**
 * test_distributor_territory.php — 5.18.63: territory + customer/lead attribution
 * (WS-A P2, docs/49). Driven against a real temp SQLite via DistributorAttribution.
 *
 *   A migration 079 tables + the no-phone invariant
 *   B territory: regions, areas, normalisation, one-area-one-distributor (+controls)
 *   C candidateFor: proposes one owner, fails safe to null, normalises
 *   D attribution: active link, relink supersedes (history kept, one active),
 *     idempotent, scope/via guards, NEVER by phone
 *   E the partial unique index is the floor under "one active owner"
 *   F wiring + gating (handlers admin+flag+Uganda; tab forms; no uCRM)
 *   G manifest version
 */
$root = dirname(__DIR__);
require_once $root . '/lib/bootstrap_data.php';
require_once $root . '/lib/StoreInterface.php';
require_once $root . '/lib/SqliteStore.php';
require_once $root . '/lib/DistributorRegistry.php';
require_once $root . '/lib/DistributorAttribution.php';

$pass = 0; $fail = 0;
function is_(bool $c, string $m, string $d = ''): void { global $pass, $fail;
    if ($c) { $pass++; echo "  ok   $m\n"; } else { $fail++; echo "  FAIL $m" . ($d !== '' ? "\n       $d" : '') . "\n"; } }
function nc(string $f): string { $o=''; foreach (token_get_all((string)file_get_contents($f)) as $k){ if(is_array($k)){ if(in_array($k[0],[T_COMMENT,T_DOC_COMMENT],true)) continue; $o.=$k[1]; } else $o.=$k; } return $o; }
function threw(callable $fn, string &$msg = null): bool { try { $fn(); return false; } catch (\Throwable $e) { $msg = $e->getMessage(); return true; } }

$tmp = sys_get_temp_dir() . '/dterr_' . bin2hex(random_bytes(4));
@mkdir($tmp, 0777, true);
$store = SqliteStore::create($tmp);
$pdo = $store->getPdo();
$reg = DistributorRegistry::fromStore($store);
$att = DistributorAttribution::fromStore($store);
$A = $reg->create(['legal_name' => 'Alpha Distributors'], 'adm');
$B = $reg->create(['legal_name' => 'Beta Distributors'], 'adm');

echo "A. migration 079 — tables + the no-phone invariant\n";
foreach (['dist_regions', 'dist_territory_map', 'dist_customer_links'] as $t) {
    is_($pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='$t'")->fetchColumn() === $t, "$t table created");
}
$linkCols = array_column($pdo->query("PRAGMA table_info(dist_customer_links)")->fetchAll(\PDO::FETCH_ASSOC), 'name');
is_(!in_array('phone', $linkCols, true), 'dist_customer_links has NO phone column (attribution is structurally never by phone)');
is_(in_array('active', $linkCols, true) && in_array('assigned_via', $linkCols, true) && in_array('superseded_at', $linkCols, true), 'link columns present (active, assigned_via, superseded_at)');
foreach (['idx_dist_territory_area', 'idx_dist_link_active'] as $ix) {
    is_($pdo->query("SELECT name FROM sqlite_master WHERE type='index' AND name='$ix'")->fetchColumn() === $ix, "index '$ix' created");
}

echo "\nB. territory — regions, areas, one-area-one-distributor (controls paired)\n";
$rA = $att->addRegion($A['id'], 'KLA', 'Kampala', 'adm');
is_($rA['id'] >= 1, 'addRegion returns an id');
is_(threw(fn() => $att->addRegion($A['id'], 'KLA', 'dup', 'adm')), 'a duplicate region code for the same partner is refused');
is_(threw(fn() => $att->addRegion(999999, 'X', 'y', 'adm')), 'addRegion refuses an unknown partner');
$rB = $att->addRegion($B['id'], 'KLA', 'Kampala (B)', 'adm');
is_($rB['id'] >= 1, 'control: the same region code under a DIFFERENT partner is allowed');
$a1 = $att->addArea($rA['id'], 'Nakawa Division', 'adm');
is_(($a1['added'] ?? false) === true && $a1['area_key'] === 'nakawa division', 'addArea normalises the area key');
is_(($att->addArea($rA['id'], 'nakawa   DIVISION', 'adm')['added'] ?? null) === false, 'the same area for the same partner is an idempotent no-op');
$m = '';
is_(threw(fn() => $att->addArea($rB['id'], 'Nakawa Division', 'adm'), $m), 'the same area for a DIFFERENT partner is refused (one area, one distributor)');
is_(stripos($m, 'one') !== false || stripos($m, 'territory') !== false, '…flagged for review, not merged', $m);
is_(($att->addArea($rB['id'], 'Kira', 'adm')['added'] ?? false) === true, 'control: a different area for B is accepted');
is_(threw(fn() => $att->addArea($rA['id'], '   ', 'adm')), 'an empty area is refused');

echo "\nC. candidateFor — proposes one owner, fails safe\n";
$c = $att->candidateFor('  Nakawa   division ');
is_($c !== null && (int)$c['partner_id'] === (int)$A['id'], 'a location in A\'s territory proposes A (normalised match)');
is_($att->candidateFor('Gulu') === null, 'a location with no territory proposes nobody (null)');
is_($att->candidateFor('') === null, 'an empty location proposes nobody');
is_((int)$att->candidateFor('KIRA')['partner_id'] === (int)$B['id'], 'case-insensitive match resolves B for Kira');

echo "\nD. attribution — one active owner, relink keeps history, never by phone\n";
$l1 = $att->link('ucrm_client', '5001', (int)$A['id'], 'territory', 'adm', 'first owner');
is_(($l1['linked'] ?? false) === true && ($l1['relinked'] ?? null) === false, 'first attribution links, nothing superseded');
$cur = $att->activeLink('ucrm_client', '5001');
is_($cur && (int)$cur['partner_id'] === (int)$A['id'] && $cur['assigned_via'] === 'territory', 'active owner is A via territory');
$l2 = $att->link('ucrm_client', '5001', (int)$B['id'], 'manual', 'adm', 'reassigned to B');
is_(($l2['relinked'] ?? false) === true, 're-attributing supersedes the previous owner');
is_((int)$att->activeLink('ucrm_client', '5001')['partner_id'] === (int)$B['id'], 'the active owner is now B');
is_((int)$pdo->query("SELECT COUNT(*) FROM dist_customer_links WHERE scope='ucrm_client' AND entity_id='5001' AND active=1")->fetchColumn() === 1, 'exactly ONE active owner remains');
is_(count($att->history('ucrm_client', '5001')) === 2, 'history keeps both rows (the superseded one is not deleted)');
$sup = $pdo->query("SELECT superseded_at FROM dist_customer_links WHERE scope='ucrm_client' AND entity_id='5001' AND active=0")->fetchColumn();
is_(trim((string)$sup) !== '', 'the superseded row records when it was replaced');
$l3 = $att->link('ucrm_client', '5001', (int)$B['id'], 'manual', 'adm');
is_(($l3['linked'] ?? null) === false, 're-linking to the SAME owner is an idempotent no-op');
is_(threw(fn() => $att->link('nope', '1', (int)$A['id'], 'manual', 'adm')), 'an unknown scope is refused');
is_(threw(fn() => $att->link('lead', '7', (int)$A['id'], 'telepathy', 'adm')), 'an unknown assignment basis is refused');
is_(threw(fn() => $att->link('lead', '', (int)$A['id'], 'manual', 'adm')), 'an empty entity id is refused');
is_(threw(fn() => $att->link('lead', '7', 999999, 'manual', 'adm')), 'attribution to an unknown partner is refused');
// a lead and a client with the same numeric id are independent (keyed by scope+id, never phone)
$att->link('lead', '5001', (int)$A['id'], 'manual', 'adm');
is_((int)$att->activeLink('lead', '5001')['partner_id'] === (int)$A['id'] && (int)$att->activeLink('ucrm_client', '5001')['partner_id'] === (int)$B['id'],
    'scope separates a lead from a uCRM client with the same id');

echo "\nE. the partial unique index is the floor under one-active-owner\n";
$threwDb = false;
try { $pdo->prepare("INSERT INTO dist_customer_links (scope, entity_id, partner_id, active) VALUES ('ucrm_client','5001',?,1)")->execute([(int)$A['id']]); }
catch (\PDOException $e) { $threwDb = stripos($e->getMessage(), 'unique') !== false; }
is_($threwDb, 'a direct 2nd ACTIVE row for the same customer is refused by the unique index');
$okHist = true;
try { $pdo->prepare("INSERT INTO dist_customer_links (scope, entity_id, partner_id, active) VALUES ('ucrm_client','5001',?,0)")->execute([(int)$A['id']]); }
catch (\PDOException $e) { $okHist = false; }
is_($okHist, 'control: an inactive (history) row for the same customer is allowed');

echo "\nF. wiring + gating + no uCRM\n";
$src = nc($root . '/lib/DistributorAttribution.php');
is_(strpos($src, 'CrmApiClient') === false, 'DistributorAttribution never touches uCRM');
$body = '';
if (preg_match('/function link\b.*?\n    \}/s', $src, $mm)) $body = $mm[0];
is_($body !== '' && stripos($body, 'phone') === false, 'link() never reads or matches a phone');
$pd = nc($root . '/includes/post/post_distributors.php');
foreach (['dist_region_add', 'dist_area_add', 'dist_attribute'] as $a) {
    is_(strpos($pd, $a) !== false, "handler wired for action=$a");
}
is_(preg_match('/dist_region_add.*dist_area_add.*dist_attribute.*requireAdmin/s', $pd) === 1, 'the P2 handlers require admin');
is_(strpos($pd, 'distributors_enabled') !== false && strpos($pd, 'StaffJobsGate::applies') !== false, 'the P2 handlers are flag- and Uganda-gated');
$tab = nc($root . '/tabs/admin/distributors.php');
foreach (['dist_region_add', 'dist_area_add', 'dist_attribute'] as $a) {
    is_(strpos($tab, "value=\"$a\"") !== false, "tab renders the $a form");
}

echo "\nG. manifest version\n";
$mani = json_decode((string)file_get_contents($root . '/manifest.json'), true);
is_(($mani['information']['version'] ?? '') === '5.18.71', 'manifest version is 5.18.71');

exec('rm -rf ' . escapeshellarg($tmp));
echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
