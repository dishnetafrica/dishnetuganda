<?php
// 5.18.102's rehearsal (D14): the catalogue the KYC form offers, resolved by the RELEASE's own UcrmQuoteLines and judged
// by its own UcrmQuoteLinks — never the deploy script's probe — so that what the probe reports can be set against the
// release's own reading of the same rows, the same link records and the same stand-in uCRM:
//   RH102_TOKEN=<the stand-in's token> php rh102-ref.php <the release's code root> <plugin folder> <store database> app|token
// The rows are the form's own (tabs/sales/kyc_form.php): active subscription_plans, active kyc_devices — the Fiber ones
// apart (never priced on Uganda) — and the installation; whole rows, in their stored shape, as the form hands them. The
// links are the store's own records (the table ucrm_quote_links, read as it stands; absent when nothing was ever linked),
// with every kyc_devices row, as UcrmQuoteLinks::fromStore reads them. The store is opened READ-ONLY — never through
// SqliteStore::load(), which would create the link table; the client is the plugin's own (CrmApiClient::fromUcrm on the
// plugin folder's ucrm.json) or, with "token", the admin token's client on the same base URL, as KycService::postQuote
// builds it. Each answer is counted by the release's own fixed why code (docs/71 §R.4.1), never by its text. What uCRM
// offers that could be linked at all is the release's own candidate() on a resolver over an empty judge and the same
// catalogue (no second read): a plan over the counted product and service-plan pairs whose PublicPriceFeed::planKey are
// equal and not empty, hardware and the installation over each counted product with a non-Fiber stand-in row. Prints
// one line of counts —
//   rows:<n>,ok:<n>,unpriced:<n>,unlinked:<n>,fiber:<n>|<class>:<n>,…|plans:<n>,hw:<n>
// (the classes the deploy script's qlr names, in its order) — never a name, a product id or a price; "unread" when the
// catalogue could not be read.
declare(strict_types=1);
[$_, $root, $pd, $db, $how] = $argv + [null, '', '', '', 'app'];
ini_set('error_log', '/dev/null');
require_once $root . '/lib/UcrmQuoteLines.php';   // which requires UcrmQuoteLinks, QuoteOrganization, PublicPriceFeed and CrmApiClient by its own path
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
$kits = $rows('kyc_devices');
$links = UcrmQuoteLinks::fromRecords($rows('ucrm_quote_links'), $kits);
$c = CrmApiClient::fromUcrm($pd, []);
if ($how === 'token') $c = new CrmApiClient(rtrim((string)$c->getBaseUrl(), '/'), (string)getenv('RH102_TOKEN'), 'x-auth-token');
$setting = ['on' => true, 'id' => 1, 'source' => 'rehearsal', 'error' => ''];
$u = new UcrmQuoteLines($c, $setting, $links);
$cat = $u->catalogue();
if (empty($cat['ok'])) { echo "unread\n"; exit(0); }
$classOf = ['unlinked' => 'unlinked', 'two' => 'two', 'replaced' => 'replaced', 'organization' => 'org', 'unlisted' => 'gone', 'bad' => 'bad',
            'plan_rows' => 'rows200', 'plan_twin' => 'twin', 'no_month' => 'nomonth', 'plan_price' => 'planprice', 'price_differs' => 'differs',
            'spelled_apart' => 'apart', 'like_plan' => 'likeplan', 'rule8' => 'rule8'];
$n = ['rows' => 0, 'ok' => 0, 'unpriced' => 0, 'unlinked' => 0, 'fiber' => 0];
$k = array_fill_keys(array_values($classOf), 0); $k['other'] = 0;
$judge = function (array $a) use (&$n, &$k, $classOf): void {
    $n['rows']++;
    if (!empty($a['ok'])) { $n['ok']++; return; }
    $n['unpriced']++;
    $w = is_string($a['why'] ?? null) ? $a['why'] : '';
    if ($w === 'unlinked') $n['unlinked']++;
    if (isset($classOf[$w])) $k[$classOf[$w]]++; else $k['other']++;
};
foreach ($rows('subscription_plans') as $r) {
    if (empty($r['is_active'])) continue;
    if (UcrmQuoteLinks::isFiber('plan', $r)) { $n['fiber']++; continue; }
    $judge($u->planLine($r));
}
foreach ($kits as $r) {
    if (empty($r['is_active'])) continue;
    if (!UcrmQuoteLinks::offeredKit($r)) { $n['fiber']++; continue; }
    $judge($u->kitLine($r, 1));
}
$judge($u->installLine());
$e = new UcrmQuoteLines($c, $setting, UcrmQuoteLinks::fromRecords([], []), $cat);
$cp = 0; $ch = 0;
foreach ((array)$cat['products'] as $pr) {
    if (!is_array($pr)) continue;
    $pid = (int)($pr['id'] ?? 0); $pk = PublicPriceFeed::planKey((string)($pr['name'] ?? ''));
    foreach ((array)$cat['plans'] as $sp) {
        if (!is_array($sp) || $pk === '' || PublicPriceFeed::planKey((string)($sp['name'] ?? '')) !== $pk) continue;
        if (!empty($e->candidate('plan', ['id' => 0, 'name' => '', 'is_active' => 1, 'type' => 'starlink'], $pid, (int)($sp['id'] ?? 0))['ok'])) $cp++;
    }
    if (!empty($e->candidate('kit', ['id' => 0, 'title' => '', 'is_active' => 1, 'type' => 'general'], $pid)['ok'])) $ch++;
}
$a = []; foreach ($n as $x => $v) $a[] = $x . ':' . $v;
$b = []; foreach ($k as $x => $v) $b[] = $x . ':' . $v;
echo implode(',', $a), '|', implode(',', $b), '|plans:', $cp, ',hw:', $ch, "\n";
