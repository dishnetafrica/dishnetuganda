<?php
// 5.18.103's rehearsal (root docs/72 §G.4–§G.6, §G.13): what the forms offer in direct mode, read by the RELEASE's own
// UcrmQuoteLines::offered() — never the deploy script's probe — so that what QC-G1 and QC-CA1 report can be set against
// the release's own reading of the same store and the same stand-in uCRM:
//   RH103_TOKEN=<the stand-in's token> php rh103-ref.php <the release's code root> <plugin folder> <store database> app|token
// The catalogue's settings are the store's own records (the table ucrm_catalogue, read as it stands — absent when nothing
// was ever saved on the uCRM catalogue screen), through the code root's UcrmCatalogue::fromRecords(): the installation
// product, the products kept off, the standard quotations — exactly what the screen and a submission pass offered() and
// standardFrom(). The store is opened READ-ONLY — never through SqliteStore::load(), which would create the table; the
// client is the plugin's own (CrmApiClient::fromUcrm on the plugin folder's ucrm.json) or, with "token", the admin
// token's client on the same base URL, as UcrmQuoteLines::quoteClient() builds it. The resolver holds an empty link judge
// (direct mode reads no link). Each item left out is counted by the release's own why code, never by its text, and with
// no list of known codes of this rehearsal's own: an empty code or an empty reason is "other". Prints one line —
//   sp:<n>,pr:<n>,plans:<n>,hw:<n>,not:<n>,unread:0,other:<n>|<why>.<n>+…|inst:<ok|unset|why>|std:<defined>/<usable>|stock:<n>|items:<n>
// (the service plans and products uCRM counted, the plans and hardware offered, those not offered; the not-offered by why,
// sorted by code as PHP's ksort; the installation; the standard quotations by the release's standardFrom(); the offered
// hardware with no active stock category of its title — lowercased, through QuoteOrganization::plain(), as a deduction
// looks it up; every item the deploy hashes: plans, hardware, not offered and the installation) — never a name, a product
// id or a price; "unread" when the catalogue could not be read.
declare(strict_types=1);
ini_set('display_errors', 'stderr');   // a notice must never reach stdout, which carries the one line the rehearsal reads
[$_, $root, $pd, $db, $how] = $argv + [null, '', '', '', 'app'];
ini_set('error_log', '/dev/null');
foreach (['lib/UcrmQuoteLines.php', 'lib/UcrmCatalogue.php'] as $f) {
    if (!is_file($root . '/' . $f)) { fwrite(STDERR, "refusing: the code root holds no $f\n"); exit(2); }
}
require_once $root . '/lib/UcrmQuoteLines.php';   // which requires UcrmQuoteLinks, QuoteOrganization, PublicPriceFeed and CrmApiClient by its own path
require_once $root . '/lib/UcrmCatalogue.php';
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
$cat = UcrmCatalogue::fromRecords($rows('ucrm_catalogue'), true);
$stock = [];
if ($p->query("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'stock_categories'")->fetchColumn() !== false) {
    foreach ($p->query('SELECT title FROM stock_categories WHERE is_active = 1')->fetchAll(PDO::FETCH_COLUMN) as $t) $stock[strtolower((string)$t)] = true;
}
$c = CrmApiClient::fromUcrm($pd, []);
if ($how === 'token') $c = new CrmApiClient(rtrim((string)$c->getBaseUrl(), '/'), (string)getenv('RH103_TOKEN'), 'x-auth-token');
$setting = ['on' => true, 'id' => 1, 'source' => 'rehearsal', 'error' => ''];
$u = new UcrmQuoteLines($c, $setting, UcrmQuoteLinks::fromRecords([], []));
$off = $u->offered($cat->installProductId(), $cat->hiddenIds());
if (empty($off['ok'])) { echo "unread\n"; exit(0); }
$cc = $u->catalogue();
$why = []; $other = 0;
foreach ($off['not_offered'] as $r) {
    $w = is_string($r['why'] ?? null) ? $r['why'] : '';
    if ($w === '' || trim((string)($r['reason'] ?? '')) === '') { $other++; $w = 'other'; }
    $why[$w] = ($why[$w] ?? 0) + 1;
}
$in = (array)$off['install'];
$iw = !empty($in['ok']) ? 'ok' : (string)($in['why'] ?? '');
if ($iw === '') { $other++; $iw = 'other'; }
$usable = 0; $stds = $cat->standards();
foreach ($stds as $sd) if (!empty(UcrmQuoteLines::standardFrom($sd, $off)['ok'])) $usable++;
$ns = 0;
foreach ($off['hardware'] as $r) if (!isset($stock[strtolower(QuoteOrganization::plain((string)$r['label']))])) $ns++;
ksort($why); $ws = []; foreach ($why as $k => $v) $ws[] = $k . '.' . $v;
echo 'sp:', count((array)$cc['plans']), ',pr:', count((array)$cc['products']), ',plans:', count($off['plans']), ',hw:', count($off['hardware']),
     ',not:', count($off['not_offered']), ',unread:0,other:', $other, '|', $ws ? implode('+', $ws) : 'none', '|inst:', $iw === 'install_unset' ? 'unset' : $iw,
     '|std:', count($stds), '/', $usable, '|stock:', $ns, '|items:', count($off['plans']) + count($off['hardware']) + count($off['not_offered']) + 1, "\n";
