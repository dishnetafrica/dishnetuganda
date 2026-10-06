<?php
declare(strict_types=1);
/**
 * test_dist_isolation.php — WS-A P4a (docs/50, docs/47 §10.3, docs/49 §13).
 *
 * The #1-risk floor for the distributor portal: the partner-scoped read layer
 * (DistributorPortalData) returns ONLY a distributor's own attributed
 * customers/leads and own profile, and is structurally incapable of returning
 * another distributor's. Driven against a real temp SQLite; links are seeded
 * through the real write path (DistributorAttribution).
 *
 *   A class shape — scope has ONE source; no partner_id parameter on any read
 *   B scoped reads — A sees only A's links; B's are ABSENT (+ non-vacuity control)
 *   C composite-key single fetch — a foreign/guessed id is null, though it exists
 *   D allow-list — only allow-listed fields; staff identity / billing withheld (+controls)
 *   E PartnerContext fail-closed — bad partner id and unknown role refused
 *   F THE CONTROL ON THE CONTROL — a weakened copy that drops the scope LEAKS,
 *     proving the real predicate is load-bearing, not vacuous
 *   G read-only — no write, no uCRM
 *   H manifest version
 */
$root = dirname(__DIR__);
require_once $root . '/lib/bootstrap_data.php';
require_once $root . '/lib/StoreInterface.php';
require_once $root . '/lib/SqliteStore.php';
require_once $root . '/lib/DistributorRegistry.php';
require_once $root . '/lib/DistributorAttribution.php';
require_once $root . '/lib/PartnerContext.php';
require_once $root . '/lib/DistributorPortalData.php';

$pass = 0; $fail = 0;
function is_(bool $c, string $m, string $d = ''): void { global $pass, $fail;
    if ($c) { $pass++; echo "  ok   $m\n"; } else { $fail++; echo "  FAIL $m" . ($d !== '' ? "\n       $d" : '') . "\n"; } }
function nc(string $f): string { $o=''; foreach (token_get_all((string)file_get_contents($f)) as $k){ if(is_array($k)){ if(in_array($k[0],[T_COMMENT,T_DOC_COMMENT],true)) continue; $o.=$k[1]; } else $o.=$k; } return $o; }
function threw(callable $fn, string &$msg = null): bool { try { $fn(); return false; } catch (\Throwable $e) { $msg = $e->getMessage(); return true; } }

/**
 * A DELIBERATELY WRONG copy of the reader with the partner scope removed. It is
 * the mutation docs/49 §13 requires: if the real reader ever looked like this,
 * the isolation assertions in section B would fail. Section F proves it leaks.
 */
class WidenedPortalData extends DistributorPortalData
{
    public function myLinks(bool $activeOnly = true): array
    {
        $sql = "SELECT * FROM dist_customer_links" . ($activeOnly ? " WHERE active = 1" : "") . " ORDER BY id DESC";
        $st = $this->db()->query($sql);                 // no partner scope — the bug under test
        return array_map(['DistributorPortalData', 'projectLink'], $st->fetchAll(\PDO::FETCH_ASSOC) ?: []);
    }
    public function myLink(int $linkId): ?array
    {
        $st = $this->db()->prepare("SELECT * FROM dist_customer_links WHERE id = :id"); // no AND partner_id
        $st->execute([':id' => $linkId]);
        $row = $st->fetch(\PDO::FETCH_ASSOC);
        return $row ? DistributorPortalData::projectLink($row) : null;
    }
}

$tmp = sys_get_temp_dir() . '/distiso_' . bin2hex(random_bytes(4));
@mkdir($tmp, 0777, true);
$store = SqliteStore::create($tmp);
$pdo = $store->getPdo();
$reg = DistributorRegistry::fromStore($store);
$att = DistributorAttribution::fromStore($store);

// Two distributors, seeded through the real write paths.
$A = $reg->create(['legal_name' => 'Alpha Distributors Ltd', 'trading_name' => 'Alpha', 'trading_currency' => 'UGX'], 'adm');
$B = $reg->create(['legal_name' => 'Beta Distributors Ltd',  'trading_name' => 'Beta',  'trading_currency' => 'UGX'], 'adm');
$att->link('ucrm_client', '5001', (int)$A['id'], 'manual', 'adm', 'A customer');
$att->link('lead',        '7001', (int)$A['id'], 'manual', 'adm', 'A lead');
$att->link('ucrm_client', '6001', (int)$B['id'], 'manual', 'adm', 'B customer');

$ctxA = new PartnerContext((int)$A['id']);
$ctxB = new PartnerContext((int)$B['id']);
$portalA = new DistributorPortalData($pdo, $ctxA);
$portalB = new DistributorPortalData($pdo, $ctxB);

echo "A. class shape — scope has ONE source; no partner_id parameter on any read\n";
$clsSrc = nc($root . '/lib/DistributorPortalData.php');
is_(substr_count($clsSrc, '->partnerId()') === 1, 'the context partner id is read in exactly ONE place (scopePartnerId)');
is_(strpos($clsSrc, 'scopePartnerId') !== false, 'scopePartnerId() is that single source of scope');
$ref = new \ReflectionClass('DistributorPortalData');
$paramLeak = '';
foreach ($ref->getMethods(\ReflectionMethod::IS_PUBLIC) as $m) {
    foreach ($m->getParameters() as $p) {
        if (preg_match('/partner/i', $p->getName())) $paramLeak = $m->getName() . '($' . $p->getName() . ')';
    }
}
is_($paramLeak === '', 'no public read method takes a partner id parameter (scope is the context, never the request)', $paramLeak);

echo "\nB. scoped reads — A sees only A's; B's are ABSENT (non-vacuity paired)\n";
$aLinks = $portalA->myLinks();
$aEntities = array_map(fn($r) => $r['entity_id'] . ':' . $r['scope'], $aLinks);
is_(count($aLinks) === 2, 'A sees its 2 active links', 'got ' . count($aLinks));
is_(in_array('5001:ucrm_client', $aEntities, true) && in_array('7001:lead', $aEntities, true), 'A sees exactly its own 5001 + 7001');
is_(!in_array('6001:ucrm_client', $aEntities, true), "B's customer 6001 is ABSENT from A's view");
$bLinks = $portalB->myLinks();
is_(count($bLinks) === 1 && $bLinks[0]['entity_id'] === '6001', 'B sees only its own 6001');
// non-vacuity: the table really holds BOTH partners' rows
$rawActive = (int)$pdo->query("SELECT COUNT(*) FROM dist_customer_links WHERE active=1")->fetchColumn();
is_($rawActive === 3, 'control: the table holds 3 active links in total — A\'s view is a real subset, not an empty table');
$counts = $portalA->myCounts();
is_($counts['active'] === 2 && $counts['ucrm_client'] === 1 && $counts['lead'] === 1, 'myCounts scopes to A (2 active: 1 client, 1 lead)');
is_($portalB->myCounts()['active'] === 1, 'myCounts scopes to B (1 active)');

echo "\nC. composite-key single fetch — a foreign id is null, though it exists\n";
$aLinkId = (int)$att->activeLink('ucrm_client', '5001')['id'];
$bLinkId = (int)$att->activeLink('ucrm_client', '6001')['id'];
is_($portalA->myLink($aLinkId) !== null, 'A can fetch its own link by id');
is_($portalA->myLink($bLinkId) === null, "A fetching B's link id returns null (the API 404)");
is_($portalB->myLink($aLinkId) === null, "B fetching A's link id returns null");
$existsRaw = (int)$pdo->query("SELECT COUNT(*) FROM dist_customer_links WHERE id=$bLinkId")->fetchColumn();
is_($existsRaw === 1, "control: B's link id DOES exist — A's null is the scope, not an absent row");
is_($portalA->myLink(999999) === null, 'a non-existent id is null too (absent and not-yours are indistinguishable)');

echo "\nD. allow-list — only allow-listed fields; staff identity / billing withheld (controls paired)\n";
$row0 = $aLinks[0];
is_(count(array_diff(array_keys($row0), DistributorPortalData::LINK_PUBLIC)) === 0, 'a link row exposes only allow-listed keys');
is_(!array_key_exists('assigned_by', $row0), "the staff identity 'assigned_by' is withheld from the partner");
is_(!array_key_exists('source', $row0), "the internal 'source' tag is withheld");
$rawLink = $pdo->query("SELECT * FROM dist_customer_links WHERE id=$aLinkId")->fetch(\PDO::FETCH_ASSOC);
is_(($rawLink['assigned_by'] ?? '') === 'adm', "control: the raw row DOES carry assigned_by='adm' — the projection drops it");
$prof = $portalA->myProfile();
is_($prof !== null && count(array_diff(array_keys($prof), DistributorPortalData::PROFILE_PUBLIC)) === 0, 'the profile exposes only allow-listed keys');
is_(!array_key_exists('ucrm_client_id', $prof) && !array_key_exists('tin', $prof) && !array_key_exists('created_by', $prof),
    'billing linkage (ucrm_client_id, tin) and staff identity (created_by) are withheld from the profile');
is_(($prof['partner_code'] ?? '') === $A['partner_code'] && ($prof['trading_name'] ?? '') === 'Alpha', 'the profile shows the partner\'s own code and trading name');
$rawProf = $pdo->query("SELECT * FROM dist_partners WHERE id=".(int)$A['id'])->fetch(\PDO::FETCH_ASSOC);
is_(($rawProf['created_by'] ?? '') === 'adm' && array_key_exists('ucrm_client_id', $rawProf), 'control: the raw partner row carries created_by and ucrm_client_id — the projection drops them');

echo "\nE. PartnerContext — fail closed\n";
is_(threw(fn() => new PartnerContext(0)), 'a zero partner id is refused (no anonymous context can widen a query)');
is_(threw(fn() => new PartnerContext(-5)), 'a negative partner id is refused');
is_(threw(fn() => new PartnerContext((int)$A['id'], 'superuser')), 'an unknown role is refused');
$ctxP = new PartnerContext((int)$A['id'], 'head_office');
is_($ctxP->partnerId() === (int)$A['id'] && $ctxP->role() === 'head_office' && $ctxP->isPartnerWide(), 'a valid head_office context is accepted and is partner-wide');
$ctxO = new PartnerContext((int)$A['id'], 'branch_clerk', ['3', 3, 0, -1, 'x']);
is_($ctxO->outletIds() === [3] && !$ctxO->isPartnerWide(), 'outlet ids are coerced to positive ints and deduped; an outlet role is not partner-wide');

echo "\nF. THE CONTROL ON THE CONTROL — a weakened scope LEAKS (so the predicate is real)\n";
$widenedA = new WidenedPortalData($pdo, $ctxA);
$wLinks = $widenedA->myLinks();
$wEntities = array_map(fn($r) => $r['entity_id'], $wLinks);
is_(count($wLinks) === 3, 'the weakened copy (no partner scope) returns ALL 3 links');
is_(in_array('6001', $wEntities, true), "the weakened copy leaks B's 6001 into A's view");
is_(count($wLinks) > count($aLinks), 'the real reader returns strictly fewer than the weakened copy — the scope predicate is load-bearing');
is_($widenedA->myLink($bLinkId) !== null, "the weakened single-fetch leaks B's link by id; the real one returned null above");

echo "\nG. read-only — no write, no uCRM\n";
foreach (['CrmApiClient', 'INSERT ', 'INSERT INTO', 'UPDATE ', 'DELETE ', 'DELETE FROM'] as $needle) {
    is_(stripos($clsSrc, $needle) === false, "DistributorPortalData contains no '$needle' (read-only, no uCRM)");
}

echo "\nH. manifest version\n";
$mani = json_decode((string)file_get_contents($root . '/manifest.json'), true);
is_(($mani['information']['version'] ?? '') === '5.18.84', 'manifest version is 5.18.71');

exec('rm -rf ' . escapeshellarg($tmp));
echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
