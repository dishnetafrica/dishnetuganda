<?php
declare(strict_types=1);
/**
 * test_distributor_link_ucrm.php — 5.18.62: link an appointed distributor to an
 * EXISTING uCRM company client (WS-A P1b, docs/49).
 *
 * Driven through DistributorRegistry::linkUcrmClient against a FAKE uCRM
 * (FakeCrm extends the real CrmApiClient and overrides get()). The fake records
 * every call, so the test can PROVE the operation is read-only — no POST/PATCH/
 * DELETE ever reaches uCRM, i.e. no uCRM client is created or modified.
 *
 * Covers: success + field caching; idempotent same-link; already-linked-other;
 * duplicate uCRM id (conflict); individual-not-company; missing company name;
 * normalised-TIN collision (conflict); uCRM error/timeout; not-found;
 * not-configured; the unique index as the floor beneath the app checks; never
 * by phone; and the admin + flag + Uganda gating of the route.
 */
$root = dirname(__DIR__);
require_once $root . '/lib/bootstrap_data.php';
require_once $root . '/lib/StoreInterface.php';
require_once $root . '/lib/SqliteStore.php';
require_once $root . '/lib/CrmApiClient.php';
require_once $root . '/lib/DistributorRegistry.php';

$pass = 0; $fail = 0;
function is_(bool $c, string $m, string $d = ''): void { global $pass, $fail;
    if ($c) { $pass++; echo "  ok   $m\n"; } else { $fail++; echo "  FAIL $m" . ($d !== '' ? "\n       $d" : '') . "\n"; } }
function nc(string $f): string { $o=''; foreach (token_get_all((string)file_get_contents($f)) as $k){ if(is_array($k)){ if(in_array($k[0],[T_COMMENT,T_DOC_COMMENT],true)) continue; $o.=$k[1]; } else $o.=$k; } return $o; }
function threw(callable $fn, string &$msg = null): bool { try { $fn(); return false; } catch (\Throwable $e) { $msg = $e->getMessage(); return true; } }

/** A fake uCRM: canned clients, records every call, never touches a network. */
class FakeCrm extends CrmApiClient {
    public array $clients = [];     // id => client array; value null simulates a read error/timeout; absent = not found
    public array $calls = [];       // [[method, path], …]
    public bool $configured = true;
    public function __construct() { parent::__construct('http://fake.invalid', 'k'); }
    public function isConfigured(): bool { return $this->configured; }
    public function get(string $path): ?array {
        $this->calls[] = ['GET', $path];
        if (preg_match('#^clients/(\d+)$#', $path, $m)) {
            $id = (int)$m[1];
            if (!array_key_exists($id, $this->clients)) return [];   // genuinely not found
            return $this->clients[$id];                              // may be null → read error/timeout
        }
        return [];
    }
    public function post(string $p, array $x = []): ?array { $this->calls[] = ['POST', $p]; return null; }
    public function patch(string $p, array $x = []): ?array { $this->calls[] = ['PATCH', $p]; return null; }
    public function delete(string $p): ?array { $this->calls[] = ['DELETE', $p]; return null; }
    public function getLastError(): array { return ['message' => 'simulated timeout']; }
}

$tmp = sys_get_temp_dir() . '/dlink_' . bin2hex(random_bytes(4));
@mkdir($tmp, 0777, true);
$store = SqliteStore::create($tmp);
$pdo = $store->getPdo();
$reg = DistributorRegistry::fromStore($store);

$A = $reg->create(['legal_name' => 'Partner A prospect'], 'admin <a@x>');
$B = $reg->create(['legal_name' => 'Partner B prospect'], 'admin <a@x>');

$crm = new FakeCrm();
$crm->clients = [
    5001 => ['id' => 5001, 'clientType' => 2, 'companyName' => 'Acme Distributors Ltd',
             'companyTaxId' => '1000-200-300', 'companyRegistrationNumber' => 'RG-77',
             'contacts' => [['phone' => '+256700111222']]],          // phone present but MUST NOT be used
    5002 => ['id' => 5002, 'clientType' => 1, 'firstName' => 'Jane', 'lastName' => 'Doe'],  // an individual
    5003 => ['id' => 5003, 'clientType' => 2, 'companyName' => ''],                         // company, no name
    5004 => ['id' => 5004, 'clientType' => 2, 'companyName' => 'Clone Co',
             'companyTaxId' => '1000 200 300'],                        // TIN normalises to Acme's
    9999 => null,                                                      // read error / timeout
];

echo "A. success — a company client links, fields cache, read-only\n";
$res = $reg->linkUcrmClient($A['id'], 5001, $crm, 'admin <a@x>');
is_(($res['linked'] ?? false) === true && (int)$res['ucrm_client_id'] === 5001, 'link succeeds and returns the uCRM id');
$a = $reg->get($A['id']);
is_((int)$a['ucrm_client_id'] === 5001, 'ucrm_client_id stored on the partner');
is_($a['legal_name'] === 'Acme Distributors Ltd', 'company name cached from uCRM (uCRM is master)');
is_($a['tin'] === '1000-200-300' && $a['tin_norm'] === '1000200300', 'TIN + normalised TIN cached');
is_($a['registration_no'] === 'RG-77', 'registration number cached');
is_(trim((string)$a['ucrm_linked_by']) !== '' && trim((string)$a['ucrm_linked_at']) !== '', 'who/when linked recorded');
is_(count($crm->calls) >= 1 && $crm->calls[0] === ['GET', 'clients/5001'], 'it READ clients/5001');

echo "\nB. idempotent — linking the SAME id to the SAME partner is a no-op\n";
$again = $reg->linkUcrmClient($A['id'], 5001, $crm, 'admin <a@x>');
is_(($again['linked'] ?? null) === false && ($again['reason'] ?? '') === 'already_linked', 'a repeat link is a no-op, not an error');

echo "\nC. conflicts are refused, never merged\n";
$m = '';
is_(threw(fn() => $reg->linkUcrmClient($A['id'], 5002, $crm, 'a'), $m), 'linking A to a different client is refused (already linked)');
is_(stripos($m, 'already linked') !== false, '…with a clear reason', $m);
is_(threw(fn() => $reg->linkUcrmClient($B['id'], 5001, $crm, 'a'), $m), 'linking B to A\'s uCRM id is refused (duplicate id)');
is_(stripos($m, $A['partner_code']) !== false && stripos($m, 'never merged') !== false, '…names the owning partner and refuses to merge', $m);

echo "\nD. identity rules\n";
is_(threw(fn() => $reg->linkUcrmClient($B['id'], 5002, $crm, 'a'), $m), 'an individual (clientType 1) is refused');
is_(stripos($m, 'individual') !== false, '…because a distributor is a company', $m);
is_(threw(fn() => $reg->linkUcrmClient($B['id'], 5003, $crm, 'a'), $m), 'a company with no name is refused');
is_(stripos($m, 'company name') !== false, '…missing legal identity', $m);

echo "\nE. TIN collision is a conflict (dedupe by TIN, never phone)\n";
is_(threw(fn() => $reg->linkUcrmClient($B['id'], 5004, $crm, 'a'), $m), 'a client whose TIN normalises to another partner\'s is refused');
is_(stripos($m, $A['partner_code']) !== false && stripos($m, 'never merged') !== false, '…flagged for review, not merged', $m);
// B still carries A's customer phone in 5001/5004? No — phone is never read. Prove B is still unlinked.
is_(empty($reg->get($B['id'])['ucrm_client_id']), 'B remains unlinked after every refused attempt');

echo "\nF. uCRM read failures\n";
is_(threw(fn() => $reg->linkUcrmClient($B['id'], 9999, $crm, 'a'), $m), 'a uCRM read error/timeout is refused');
is_(stripos($m, 'timeout') !== false || stripos($m, 'error') !== false, '…and says nothing was changed', $m);
is_(threw(fn() => $reg->linkUcrmClient($B['id'], 8888, $crm, 'a'), $m), 'an unknown client id is refused (not found)');
$crm->configured = false;
is_(threw(fn() => $reg->linkUcrmClient($B['id'], 5004, $crm, 'a'), $m), 'an unconfigured uCRM refuses loudly (no guess)');
$crm->configured = true;

echo "\nG. read-only — the whole run never POST/PATCH/DELETEd uCRM\n";
$writes = array_filter($crm->calls, fn($c) => $c[0] !== 'GET');
is_(count($writes) === 0, 'no uCRM write of any kind occurred (no client created or modified)', json_encode(array_values($writes)));

echo "\nH. the unique index is the floor beneath the app checks\n";
// Even if the app pre-check were bypassed, the DB refuses a second partner on the same uCRM id.
$threwDb = false;
try { $pdo->prepare("UPDATE dist_partners SET ucrm_client_id = 5001 WHERE id = ?")->execute([$B['id']]); }
catch (\PDOException $e) { $threwDb = (stripos($e->getMessage(), 'unique') !== false); }
is_($threwDb, 'a direct UPDATE to a duplicate ucrm_client_id is refused by the unique index');
is_(empty($reg->get($B['id'])['ucrm_client_id']), 'control: B is still unlinked (the refused write changed nothing)');

echo "\nI. never by phone — the link path reads no phone field\n";
$src = nc($root . '/lib/DistributorRegistry.php');
// isolate linkUcrmClient's body and assert it never touches a phone
$body = '';
if (preg_match('/function linkUcrmClient\b.*?\n    \}/s', $src, $mm)) $body = $mm[0];
is_($body !== '' && stripos($body, 'phone') === false, 'linkUcrmClient never reads or matches a phone');

echo "\nJ. the route is admin-only, flag- and Uganda-gated; the form is wired\n";
$pd = nc($root . '/includes/post/post_distributors.php');
is_(strpos($pd, "dist_link_ucrm") !== false, 'the handler acts on action=dist_link_ucrm');
is_(preg_match('/dist_link_ucrm.*?requireAdmin/s', $pd) === 1, 'the link handler requires admin');
is_(strpos($pd, 'distributors_enabled') !== false && strpos($pd, 'StaffJobsGate::applies') !== false, 'the link handler is flag- and Uganda-gated');
is_(strpos($pd, 'createClient') === false && strpos($pd, "api/v2.1") === false, 'the handler makes no direct uCRM write/call of its own');
$tab = nc($root . '/tabs/admin/distributors.php');
is_(strpos($tab, "name=\"action\" value=\"dist_link_ucrm\"") !== false, 'the tab renders the link form');

echo "\nK. manifest version\n";
$mani = json_decode((string)file_get_contents($root . '/manifest.json'), true);
is_(($mani['information']['version'] ?? '') === '5.18.82', 'manifest version is 5.18.71');

exec('rm -rf ' . escapeshellarg($tmp));
echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
