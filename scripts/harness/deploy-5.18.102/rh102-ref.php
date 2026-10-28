<?php
// 5.18.102's rehearsal: the catalogue the KYC form offers, resolved by the RELEASE's own UcrmQuoteLines — never the deploy
// script's probe — so that what the probe reports can be set against the release's own reading of the same rows and the
// same stand-in uCRM:
//   RH102_TOKEN=<the stand-in's token> php rh102-ref.php <the release's code root> <plugin folder> <store database> app|token
// The rows are the form's own (tabs/sales/kyc_form.php): active subscription_plans (by name) and kyc_devices (by title),
// a Fiber row apart (never resolved: unpriced by design on Uganda), and the installation product. The store is opened
// READ-ONLY; the client is the plugin's own (CrmApiClient::fromUcrm on the plugin folder's ucrm.json) or, with "token",
// the admin token's client on the same base URL, as KycService::postQuote builds it. Prints one line of counts —
// rows:<n>,ok:<n>,unpriced:<n>,fiber:<n>,zero:<n>,other:<n> (zero: "…'s price in uCRM is 0"; other: any other reason) —
// never a name, a product id or a price.
declare(strict_types=1);
[$_, $root, $pd, $db, $how] = $argv + [null, '', '', '', 'app'];
ini_set('error_log', '/dev/null');
require_once $root . '/lib/UcrmQuoteLines.php';
$p = new PDO('sqlite:' . $db, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY]);
$rows = function (string $t) use ($p): array {
    if ($p->query("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = " . $p->quote($t))->fetchColumn() === false) return [];
    $o = [];
    foreach ($p->query("SELECT id, data FROM [$t] ORDER BY id")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $d = json_decode((string)$r['data'], true);
        if (is_array($d)) { $d['id'] = (int)$r['id']; $o[] = $d; }
    }
    return $o;
};
$c = CrmApiClient::fromUcrm($pd, []);
if ($how === 'token') $c = new CrmApiClient(rtrim((string)$c->getBaseUrl(), '/'), (string)getenv('RH102_TOKEN'), 'x-auth-token');
$u = new UcrmQuoteLines($c, ['on' => true, 'id' => 1, 'source' => 'rehearsal', 'error' => '']);
$n = ['rows' => 0, 'ok' => 0, 'unpriced' => 0, 'fiber' => 0, 'zero' => 0, 'other' => 0];
$fiber = function (array $r, string $def): bool { $t = $r['type'] ?? $def; return is_scalar($t) && strtolower((string)$t) === 'fiber'; };
$judge = function (array $a) use (&$n): void {
    if (!empty($a['ok'])) { $n['ok']++; return; }
    $n['unpriced']++;
    $x = is_string($a['reason'] ?? null) ? $a['reason'] : '';
    if (preg_match("/'s price in uCRM is 0\$/", $x)) $n['zero']++; else $n['other']++;
};
foreach ($rows('subscription_plans') as $r) {
    if (empty($r['is_active'])) continue;
    if ($fiber($r, 'starlink')) { $n['fiber']++; continue; }
    $n['rows']++; $judge($u->planLine(['name' => is_scalar($r['name'] ?? null) ? (string)$r['name'] : '']));
}
foreach ($rows('kyc_devices') as $r) {
    if (empty($r['is_active'])) continue;
    if ($fiber($r, 'general')) { $n['fiber']++; continue; }
    $n['rows']++; $judge($u->kitLine(['title' => is_scalar($r['title'] ?? null) ? (string)$r['title'] : ''], 1));
}
$n['rows']++; $judge($u->installLine());
$out = [];
foreach ($n as $k => $v) $out[] = $k . ':' . $v;
echo implode(',', $out), "\n";
