<?php
declare(strict_types=1);
/**
 * G — the documents and the screens around the Uganda quote (docs/71 §E.2, §F.1, §F.6, §K): the fallback quotation PDF
 * (lib/PluginQuotePdf.php), the Delivery Acknowledgment PDF (lib/DeliveryPdfService.php), the plan and hardware saves
 * (includes/post/post_sync.php), the product pushes (includes/api/api_products_admin.php), the notes-prefix setting
 * (tabs/admin/settings.php), the Orders card (tabs/sales/applications.php), the staff messages (lib/NotificationService.php)
 * and the bulk-push buttons (tabs/sales/subscription_plans.php, hardware.php, product_mapping.php). Each runs on a copy
 * of the judged tree in a fresh PHP process; where the gate is off, each output is byte for byte f2055baf's. TEST ONLY.
 *
 * 5.18.102 D14 (docs/71 §R.9.1 G): the plan and kit saves never touch the link store (ucrm_quote_links.json) and, on
 * Uganda, save_hardware ignores hw_ucrm_product_id; the link save itself writes the link store only, never a plugin row
 * (G3); Auto-Map and set_product_mapping answer 409 on Uganda (G4); Product Mapping stops claiming that ucrm_product_id
 * reaches quotes (G9); and the gate-off pages stay f2055baf's with a link store present, the picker asked for, a link
 * action posted, and Orders drawn with web P1 applications (G8).
 */
require_once __DIR__ . '/f_lib.php';

/** A probe on $tree with its own data directory (the PHP log beside it, never inside). */
function qfu_g_php(QfuCtx $c, string $tree, string $code, array $args = []): array
{
    $dir  = $c->dir('g');
    $data = $dir . '/data';
    @mkdir($data, 0700, true);
    $r = qcj_php_raw($tree, ['DN_DATA_DIR' => $data, 'DN_VAULT_FILE' => $dir . '/vault.json'], $dir . '/php_errors.log', $code, $args + ['data' => $data]);
    $r['data'] = $data;
    $r['errlog'] = (string)@file_get_contents($dir . '/php_errors.log');
    $r['paths'] = [$data => 'DATA', $dir => 'DIR', rtrim($tree, '/') => 'TREE'];
    return $r;
}

/** Every file under $dir, relative, sorted. */
function qfu_g_files(string $dir): array
{
    $out = [];
    if (!is_dir($dir)) return $out;
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $f) {
        $out[] = substr((string)$f, strlen($dir));
    }
    sort($out);
    return $out;
}

const QFU_G_CFG = [
    'ug'      => ['tenant_profile' => 'uganda', 'currency_code' => 'UGX', 'currency_symbol' => 'UGX'],
    'ss'      => ['tenant_profile' => 'south-sudan', 'currency_code' => 'USD', 'currency_symbol' => '$'],
    'ug_off'  => ['tenant_profile' => 'uganda', 'currency_code' => 'UGX', 'currency_symbol' => 'UGX', 'ucrm_quote_organization_id' => '0'],
    'ug_offw' => ['tenant_profile' => 'uganda', 'currency_code' => 'UGX', 'currency_symbol' => 'UGX', 'ucrm_quote_organization_id' => 'off'],
];
const QFU_G_OFF = ['ss' => 'South Sudan', 'ug_off' => 'Uganda with the setting 0', 'ug_offw' => 'Uganda with the setting off'];

/** A FakeStore class for a probe: rows from $ARGS["rows"], saves recorded, the data directory from $DATA. */
const QFU_G_STORE = '
final class QfuGStore {
    public $rows; public $saves = []; public $loads = []; public $locks = [];
    public function __construct(array $rows) { $this->rows = $rows; }
    public function load($f) { $this->loads[] = $f; return $this->rows[$f] ?? []; }
    // 5.18.102 D14: withLock as SqliteStore answers it (the callback returns records and a result), recorded
    public function withLock($f, callable $fn) { $this->locks[] = $f; $r = $fn($this->rows[$f] ?? []); $this->rows[$f] = $r["records"]; $this->saves[] = [$f, $r["records"]]; return $r["result"]; }
    public function save($f, $d) { $this->saves[] = [$f, $d]; $this->rows[$f] = $d; return true; }
    public function nextId($f) { $m = 0; foreach ((array)($this->rows[$f] ?? []) as $r) $m = max($m, (int)($r["id"] ?? 0)); return $m + 1; }
    public function findOne($f, $k, $v) { $this->loads[] = $f; foreach ((array)($this->rows[$f] ?? []) as $r) if ((string)($r[$k] ?? "") === (string)$v) return $r; return null; }
    public function updateOne($f, $k, $v, array $set) { $this->saves[] = ["update:" . $f, [$k => $v] + $set]; return true; }
    public function getDataDir(): string { return $GLOBALS["DATA"]; }
    public function getPdo() { return new \PDO("sqlite::memory:"); }
}
';

$QFU_SECTIONS['G'] = ['title' => 'The documents and screens: the fallback PDF, no Delivery PDF, no product push, the prefix hint, the Orders card, the staff messages', 'run' => function (QfuCtx $c) {
    $trees = qfu_trees($c);
    $srv = qfu_servers($c);
    $fake = $srv['fake'];
    $evo  = $srv['evo'];
    if ($fake === '' || $evo === '') { $c->t(false, 'G the fake uCRM and the fake Evolution started'); return; }
    $c->t($trees['old'] !== null, 'G Git supplies f2055baf (5.18.101\'s release commit) — without it nothing below can be compared: a failure, never a skip');
    $new = $trees['new'];
    $old = $trees['old'];
    // Gate off: the same probe on both trees, compared after the run's own paths and time stamps are named.
    // $real: the old tree's output is a real one (never two identical failures read as "the same").
    $same = function (string $label, callable $probe, callable $real) use ($c, $old, $new) {
        if ($old === null) return;
        foreach (QFU_G_OFF as $k => $what) {
            [$a, $pa] = $probe($old, $k);
            [$b, $pb] = $probe($new, $k);
            $d = qfu_first_diff(qfu_norm($a, $pa), qfu_norm($b, $pb));
            $c->t($d === '' && $real($a), "{$label} — {$what}: byte for byte f2055baf's", $d !== '' ? $d : 'the old tree produced no real output: ' . substr(json_encode($a), 0, 600));
        }
    };

    // ── G1 the fallback quotation PDF (PluginQuotePdf) ──────────────────────────────────────────────────────────────
    $quotes = [
        'notax' => ['id' => 901, 'number' => 'Q-TEST-1', 'createdDate' => '2026-10-20T08:00:00+0300', 'total' => 500000,
                    'items' => [['label' => 'TEST Starlink Kit', 'quantity' => 1, 'price' => 500000, 'total' => 500000, 'unit' => 'Pc']]],
        'vat'   => ['id' => 902, 'number' => 'Q-TEST-2', 'createdDate' => '2026-10-20T08:00:00+0300', 'total' => 531000,
                    'taxes' => [['name' => 'VAT <18%> & Co', 'totalValue' => 81000]],
                    'items' => [['label' => 'TEST Starlink Kit', 'quantity' => 1, 'price' => 500000, 'total' => 500000, 'unit' => 'Pc']]],
        'taxsum' => ['id' => 903, 'number' => 'Q-TEST-3', 'createdDate' => '2026-10-20T08:00:00+0300', 'total' => 109000, 'totalTaxAmount' => 9000,
                    'items' => [['label' => 'TEST Starlink Kit', 'quantity' => 1, 'price' => 100000, 'total' => 100000, 'unit' => 'Pc']]],
    ];
    $pdf = function (string $tree, string $k) use ($c, $fake, $quotes): array {
        qcj_fake($fake, '/__test/reset');
        $r = qfu_g_php($c, $tree, '
require_once "$TREE/lib/PluginQuotePdf.php";
$gen = new PluginQuotePdf($DATA, $ARGS["cfg"]);
$res = [];
foreach ($ARGS["quotes"] as $k => $q) { $x = $gen->generate($q, $ARGS["client"], $ARGS["org"]); $res[$k] = isset($x["error"]) ? ["error" => $x["error"]] : ["filename" => $x["filename"]]; }
qcj_out($res);', ['cfg' => QFU_G_CFG[$k] + ['pdf_service_url' => $fake . '/__pdf', 'quote_pdf_secret' => 'TEST-PDF-SECRET'],
                  'quotes' => $quotes,
                  'client' => ['firstName' => 'TEST', 'lastName' => 'Client', 'companyName' => '', 'street1' => 'Plot 1 Test Road', 'city' => 'Testville',
                               'contacts' => [['phone' => '+256700000150', 'email' => 'client@example.test']], 'attributes' => []],
                  'org' => ['name' => 'TEST Org', 'street1' => 'Plot 2 Test Road', 'city' => 'Testville', 'phone' => '+256700000151',
                            'email' => 'org@example.test', 'website' => 'org.example.test']]);
        $html = array_values((array)(qcj_fake_state($fake)['pdf_html'] ?? []));
        return [['res' => $r['out'], 'html' => $html, 'errlog' => $r['errlog']], $r['paths']];
    };
    [$u] = $pdf($new, 'ug');
    $h = array_combine(array_keys($quotes), array_pad($u['html'], 3, ''));
    $c->eq($u['res'], ['notax' => ['filename' => 'Quote-Q-TEST-1.pdf'], 'vat' => ['filename' => 'Quote-Q-TEST-2.pdf'], 'taxsum' => ['filename' => 'Quote-Q-TEST-3.pdf']],
        'G1 the control: the fallback PDF is made for three Uganda quotes, each printed by the PDF service');
    $c->t(strpos($h['notax'], 'IT Solutions Provider &middot; Starlink Installation &middot; ') !== false && strpos($h['notax'], 'Internet Service Provider &middot;') === false,
        'G1 the header reads "IT Solutions Provider · Starlink Installation · <locality>"');
    $terms = function (string $html): array {
        preg_match_all('#<b style="color:\#D41C1C;">\*</b> <b style="color:\#141414;">([^<]*)</b> ([^<]*)</div>#', $html, $m);
        return array_map(null, $m[1], $m[2]);
    };
    $t1 = $terms($h['notax']);
    $c->eq(array_column($t1, 0), ['1. Currency &amp; Pricing:', '2. Agreement:', '3. Installation:', '4. Liability:', '5. Governing Law:'],
        'G1 exactly five terms, the design\'s');
    require_once $c->root . '/lib/QuoteTaxLine.php';
    $c->t(strpos($t1[0][1] ?? '', htmlspecialchars(QuoteTaxLine::TEXT)) !== false && strpos($t1[0][1] ?? '', 'VAT is itemised') === false,
        'G1 a quote with no tax: item 1 carries QuoteTaxLine::TEXT', $t1[0][1] ?? '');
    $t2 = $terms($h['vat']);
    $c->t(strpos($t2[0][1] ?? '', 'VAT is itemised in the totals.') !== false && strpos($t2[0][1] ?? '', htmlspecialchars(QuoteTaxLine::TEXT)) === false,
        'G1 a quote that carries tax: item 1 says VAT is itemised in the totals', $t2[0][1] ?? '');
    foreach (['best-effort', 'penalties or reconnection fees', 'ommitment period', 'within 24 hours', '3 months of fees'] as $gone) {
        $c->t(stripos(implode('', $h), $gone) === false, "G1 none of the withdrawn terms: no \"{$gone}\"");
    }
    $c->t(strpos($h['vat'], 'VAT &lt;18%&gt; &amp; Co') !== false && strpos($h['vat'], 'VAT <18%>') === false, 'G1 a tax\'s name is escaped');
    $row = function (string $html, string $name): ?string {
        return preg_match('#>' . preg_quote($name, '#') . '</td><td[^>]*>([^<]*)</td></tr>#', $html, $m) ? $m[1] : null;
    };
    $c->eq([$row($h['vat'], 'Subtotal'), $row($h['vat'], 'Discount'), $row($h['vat'], 'VAT &lt;18%&gt; &amp; Co'), $row($h['vat'], 'Total Due')],
        ['UGX 500,000.00', '-UGX 50,000.00', 'UGX 81,000.00', 'UGX 531,000.00'],
        'G1 tax and discount: Subtotal 500,000 − Discount 50,000 + VAT 81,000 = Total Due 531,000 — the rows add up');
    $c->eq([$row($h['taxsum'], 'Subtotal'), $row($h['taxsum'], 'Discount'), $row($h['taxsum'], 'Tax'), $row($h['taxsum'], 'Total Due')],
        ['UGX 100,000.00', null, 'UGX 9,000.00', 'UGX 109,000.00'],
        'G1 a quote stating only totalTaxAmount: one "Tax" row, no discount');
    $same('G1 the fallback PDF\'s HTML for the three quotes', $pdf, function ($a) {
        return count(array_column((array)$a['res'], 'filename')) === 3 && count(array_filter($a['html'], function ($x) { return strlen($x) > 2000; })) === 3; });

    // ── G2 the Delivery Acknowledgment PDF (DeliveryPdfService) ─────────────────────────────────────────────────────
    $deliv = function (string $tree, string $k) use ($c, $fake, $evo): array {
        qcj_fake($fake, '/__test/reset');
        qcj_http('GET', "{$evo}/__test/reset");
        $r = qfu_g_php($c, $tree, QFU_G_STORE . '
$DATA = $ARGS["data"]; $GLOBALS["DATA"] = $DATA;
require_once "$TREE/lib/DeliveryPdfService.php";
$store = new QfuGStore($ARGS["rows"]);
$svc = new DeliveryPdfService($store, $DATA, $ARGS["cfg"]);
$res = $svc->generateAndSend($ARGS["app"], ["name" => "TEST Agent", "role" => "field_agent"], "901");
qcj_out(["res" => $res, "saves" => $store->saves]);', [
            'cfg' => QFU_G_CFG[$k] + ['pdf_service_url' => $fake . '/__pdf', 'pdf_link_secret' => str_repeat('t', 64),
                                      'evo_api_url' => $evo, 'evo_api_key' => 'qc-evo-key', 'evo_instance_support' => 'qc-support',
                                      'evo_instance_account' => 'qc-account', 'evo_instance_sales' => 'qc-sales', 'dry_run_mode' => false],
            'rows' => ['kyc_applications.json' => [['id' => 7, 'firstname' => 'TEST', 'lastname' => 'Deliv']]],
            'app' => ['id' => 7, 'firstname' => 'TEST', 'lastname' => 'Deliv', 'customer_type' => 'StarLink', 'mobile' => '+256700000152',
                      'amount_charged' => 1800, 'sales_type' => 'Cash', 'kit_serial' => 'TEST-KIT-1', 'plan_name' => 'TEST Plan',
                      'hw_cart_json' => json_encode([['title' => 'TEST Kit', 'qty' => 1, 'price' => 'USD 1500']])]]);
        $st = qcj_http('GET', "{$evo}/__test/state")[2];
        return [['res' => $r['out']['res'] ?? $r['raw'], 'saves' => $r['out']['saves'] ?? null, 'files' => qfu_g_files($r['data']),
                 'pdf_html' => array_map('md5', (array)(qcj_fake_state($fake)['pdf_html'] ?? [])),
                 'evo' => ['text' => count((array)($st['text_calls'] ?? [])), 'media' => array_map(function ($m) { unset($m['at']); return $m; }, (array)($st['media_calls'] ?? []))],
                 'errlog' => $r['errlog']], $r['paths']];
    };
    [$d] = $deliv($new, 'ug');
    $c->eq($d['res'], ['ok' => false, 'skipped' => 'uganda', 'sent' => false, 'pdf_path' => '', 'filename' => '',
                       'error' => 'skipped: no Delivery Acknowledgment PDF on Uganda (docs/71 §K.2)'],
        'G2 Uganda: generateAndSend answers "skipped", not sent — the exact answer its three callers read');
    $c->eq([$d['files'], $d['pdf_html'], $d['saves'], $d['evo']['text'], $d['evo']['media']], [[], [], [], 0, []],
        'G2 … no file written, the PDF service never called, nothing saved, no WhatsApp document or text');
    if ($old !== null) {
        [$ds] = $deliv($new, 'ss');
        $c->t(count($ds['files']) > 0 && count($ds['pdf_html']) === 1,
            'G2 the control: on South Sudan the same call makes the PDF (so G2\'s nothing measures a real change)', json_encode([$ds['files'], $ds['res']]));
    }
    $same('G2 the Delivery PDF: its answer, files, PDF-service HTML, saves and WhatsApp sends', $deliv, function ($a) { return count($a['files']) > 0 && count($a['pdf_html']) === 1; });

    // ── G3 the plan and hardware saves (post_sync.php) ──────────────────────────────────────────────────────────────
    $save = function (string $tree, string $k, array $post) use ($c, $fake): array {
        qcj_fake($fake, '/__test/reset');
        $r = qfu_g_php($c, $tree, QFU_G_STORE . '
$DATA = $ARGS["data"]; $GLOBALS["DATA"] = $DATA;
$dataDir = $DATA; $config = $ARGS["cfg"];
$store = new QfuGStore($ARGS["rows"]);
final class QfuGAuth { public function requireAdmin() { return ["id" => 1, "role" => "admin"]; } public function requireLogin() { return ["id" => 1, "role" => "admin"]; } }
$auth = new QfuGAuth();
$GLOBALS["__flash"] = null;
function flash($m, $t = "success") { $GLOBALS["__flash"] = [$m, $t]; }
function redirect($u) { qcj_out(["flash" => $GLOBALS["__flash"], "saves" => $GLOBALS["store"]->saves, "to" => $u,
    "touched" => array_values(array_unique(array_merge($GLOBALS["store"]->loads, $GLOBALS["store"]->locks)))]); exit; }
$_SERVER["REQUEST_METHOD"] = "POST"; $_POST = $ARGS["post"];
include "$TREE/includes/post/post_sync.php";
qcj_out(["flash" => "NO REDIRECT", "touched" => array_values(array_unique(array_merge($GLOBALS["store"]->loads, $GLOBALS["store"]->locks)))]);', [
            'cfg' => QFU_G_CFG[$k] + ['crm_base_url' => $fake . '/api/v2.1', 'crm_auth_token' => 'TEST-TOKEN'],
            'rows' => ['subscription_plans.json' => [['id' => 3, 'name' => 'TEST Standard', 'customer_price' => 200000, 'ucrm_product_id' => 41]],
                       'kyc_devices.json' => [['id' => 5, 'title' => 'TEST Standard Kit', 'sell_price' => 2000000, 'ucrm_product_id' => 42]],
                       // 5.18.102 D14: a link store present, which no plan or kit save may touch
                       'ucrm_quote_links.json' => [['id' => 1, 'item' => 'plan', 'row_id' => 3, 'row_stamp' => '', 'product_id' => 41,
                           'service_plan_id' => 1, 'organization_id' => 1, 'ended_at_ts' => null]]],
            'post' => $post]);
        $out = $r['out'] ?? $r['raw'];
        $touched = is_array($out) ? (array)($out['touched'] ?? []) : null;
        if (is_array($out)) unset($out['touched']);
        // the third element (what the store was asked for) is never part of the gate-off comparison
        return [['out' => $out, 'requests' => qcj_reqs(qcj_fake_log($fake)), 'errlog' => $r['errlog']], $r['paths'], $touched];
    };
    $posts = [
        'plan, mapped'     => ['action' => 'save_plan', 'plan_id' => '3', 'plan_name' => 'TEST Standard', 'customer_price' => '210000', 'starlink_cost' => '100000', 'plan_active' => '1'],
        'plan, new'        => ['action' => 'save_plan', 'plan_id' => '0', 'plan_name' => 'TEST Lite', 'customer_price' => '150000', 'starlink_cost' => '90000', 'plan_active' => '1'],
        'hardware, mapped' => ['action' => 'save_hardware', 'hw_id' => '5', 'hw_title' => 'TEST Standard Kit', 'hw_sell' => '2100000', 'hw_buy' => '1500000', 'hw_active' => '1'],
        'hardware, new'    => ['action' => 'save_hardware', 'hw_id' => '0', 'hw_title' => 'TEST Mini Kit', 'hw_sell' => '1200000', 'hw_buy' => '900000', 'hw_active' => '1'],
    ];
    $MSG = ' uCRM\'s product was not changed: on Uganda, prices are set in uCRM Products.';
    $want = ['plan, mapped' => 'Plan updated.', 'plan, new' => 'Plan created.', 'hardware, mapped' => 'Hardware updated.', 'hardware, new' => 'Hardware added.'];
    $touch = [];
    foreach ($posts as $what => $p) {
        [$s, , $touch[$what]] = $save($new, 'ug', $p);
        $file = $p['action'] === 'save_plan' ? 'subscription_plans.json' : 'kyc_devices.json';
        $rows = []; foreach ((array)($s['out']['saves'] ?? []) as $sv) if ($sv[0] === $file) $rows = $sv[1];
        $byId = []; foreach ($rows as $rw) $byId[(int)$rw['id']] = $rw;
        $mine = $p['action'] === 'save_plan' ? ($byId[(int)$p['plan_id'] ?: 4] ?? []) : ($byId[(int)$p['hw_id'] ?: 6] ?? []);
        $price = $p['action'] === 'save_plan' ? (float)($mine['customer_price'] ?? 0) : (float)($mine['sell_price'] ?? 0);
        $c->eq([$s['requests'], $s['out']['flash'] ?? null, $price, $mine['ucrm_product_id'] ?? 'none'],
            [[], [$want[$what] . $MSG, 'warning'], (float)($p['customer_price'] ?? $p['hw_sell']), strpos($what, 'mapped') !== false ? ($p['action'] === 'save_plan' ? 41 : 42) : 'none'],
            "G3 Uganda, {$what}: the plugin's row saved at its new price, no uCRM request, no ucrm_product_id written, the amber flash");
    }
    if ($old !== null) {
        [$s] = $save($new, 'ss', $posts['plan, new']);
        $c->t(in_array('POST /products', $s['requests'], true), 'G3 the control: on South Sudan a new plan is pushed to uCRM\'s products, as before', json_encode($s['requests']));
    }
    // 5.18.102 D14 (docs/71 §R.2.1): a plan's or a kit's own save never carries a link, so it cannot touch one. The control:
    // each save asked the store for its own table.
    $own = []; $links = [];
    foreach ($posts as $what => $p) {
        $own[$what]   = in_array($p['action'] === 'save_plan' ? 'subscription_plans.json' : 'kyc_devices.json', (array)$touch[$what], true);
        $links[$what] = in_array('ucrm_quote_links.json', (array)$touch[$what], true);
    }
    $c->eq($own, array_fill_keys(array_keys($posts), true), 'G3 the control: each of the four Uganda saves asked the store for its own table (what the store is asked for is recorded)');
    $c->eq($links, array_fill_keys(array_keys($posts), false), 'G3 … and none of them asked it for ucrm_quote_links.json — read, written or locked: the plan and kit saves never touch the link store');
    // 5.18.102 D14 (docs/71 §R.2.2, Q8): on Uganda save_hardware ignores hw_ucrm_product_id — the stored value is kept, and
    // a new row gets none. The control: on South Sudan the same typed id is written and pushed, as 5.18.101 does.
    $typed = [
        'hardware, mapped, an id typed' => $posts['hardware, mapped'] + ['hw_ucrm_product_id' => '77'],
        'hardware, new, an id typed'    => $posts['hardware, new'] + ['hw_ucrm_product_id' => '77'],
    ];
    $savedId = function (array $s, int $id) {
        foreach ((array)($s['out']['saves'] ?? []) as $sv) if ($sv[0] === 'kyc_devices.json') foreach ($sv[1] as $rw) if ((int)$rw['id'] === $id) return $rw['ucrm_product_id'] ?? 'none';
        return 'not saved';
    };
    if ($old !== null) {
        [$s] = $save($new, 'ss', $typed['hardware, mapped, an id typed']);
        $c->eq([$savedId($s, 5), in_array('PATCH /products/77', $s['requests'], true)], [77, true],
            'G3 the control: on South Sudan a typed UCRM Product ID (77) is written to the row and pushed, as before');
    }
    [$s] = $save($new, 'ug', $typed['hardware, mapped, an id typed']);
    $c->eq([$savedId($s, 5), $s['requests'], $s['out']['flash'][0] ?? null], [42, [], 'Hardware updated.' . $MSG],
        'G3 Uganda, hardware mapped, a UCRM Product ID 77 typed: ignored — the row keeps its stored 42, no uCRM request');
    [$s] = $save($new, 'ug', $typed['hardware, new, an id typed']);
    $c->eq([$savedId($s, 6), $s['requests']], ['none', []], 'G3 Uganda, new hardware, a UCRM Product ID 77 typed: ignored — the new row carries none');
    foreach ($typed as $what => $p) {
        $same("G3 save {$what}: flash, saved rows and uCRM requests", function ($tree, $k) use ($save, $p) { return $save($tree, $k, $p); },
            function ($a) { return is_array($a['out']['flash'] ?? null) && count($a['out']['saves'] ?? []) > 0 && count($a['requests']) > 0; });
    }

    // 5.18.102 D14 (docs/71 §R.2.1, §R.1.2): the link save itself — ucrm_link_save, through post_sync.php on a real store —
    // writes the link store and never a plugin row: neither its ucrm_product_id nor its name. The control: the link is
    // written (one active record naming the product).
    $linkSave = function (string $tree, array $post) use ($c, $fake): array {
        qcj_fake($fake, '/__test/reset');
        qcj_fake($fake, '/__test/set', ['products' => [
                ['id' => 41, 'name' => 'TEST Basic', 'price' => 200000, 'unit' => 'Month', 'taxable' => false],
                ['id' => 42, 'name' => 'TEST Kit Pro', 'price' => 2000000, 'unit' => 'Pc', 'taxable' => false]],
            'service_plans' => [['id' => 1, 'name' => 'TEST Basic', 'periods' => [['period' => 1, 'price' => 200000, 'enabled' => true]]]],
            'log' => []]);
        $r = qfu_g_php($c, $tree, '
require_once "$TREE/lib/StoreInterface.php"; require_once "$TREE/lib/JsonStore.php"; require_once "$TREE/lib/SqliteStore.php";
require_once "$TREE/lib/CrmApiClient.php";
$DATA = $ARGS["data"]; $dataDir = $DATA; $config = $ARGS["cfg"];
$store = SqliteStore::create($DATA);
foreach ($ARGS["rows"] as $f => $rows) $store->save($f, $rows);
final class QfuGAuth3 {
    public function requireAdmin() { return ["id" => 1, "name" => "TEST Admin", "role" => "admin", "is_admin" => true]; }
    public function requireLogin() { return $this->requireAdmin(); }
    public function currentRetailer() { return $this->requireAdmin(); }
}
$auth = new QfuGAuth3();
$crm = new CrmApiClient($ARGS["crm"], "FAKE-TEST-KEY", "X-Auth-App-Key");
function csrfCheck() { return true; }
$GLOBALS["__flash"] = null;
function flash($m, $t = "success") { $GLOBALS["__flash"] = [$m, $t]; }
function redirect($u) {
    $s = $GLOBALS["store"];
    qcj_out(["flash" => $GLOBALS["__flash"], "to" => $u, "plans" => $s->load("subscription_plans.json"), "devices" => $s->load("kyc_devices.json"),
             "links" => $s->load("ucrm_quote_links.json")]);
    exit;
}
$_SERVER["REQUEST_METHOD"] = "POST"; $_SERVER["HTTP_SEC_FETCH_SITE"] = "same-origin"; $_SERVER["HTTP_HOST"] = "crm.example.test";
$_POST = $ARGS["post"];
include "$TREE/includes/post/post_sync.php";
qcj_out(["flash" => "NO REDIRECT"]);', [
            'cfg' => QFU_G_CFG['ug'] + ['crm_base_url' => $fake . '/api/v2.1', 'crm_auth_token' => 'TEST-TOKEN'],
            'crm' => $fake . '/api/v2.1',
            'rows' => ['subscription_plans.json' => [['id' => 3, 'name' => 'TEST Standard', 'customer_price' => 200000, 'ucrm_product_id' => 99,
                                                     'is_active' => true, 'created_at' => '2026-10-01 08:00:03']],
                       'kyc_devices.json' => [['id' => 5, 'title' => 'TEST Standard Kit', 'sell_price' => 2000000, 'ucrm_product_id' => 98,
                                               'type' => 'starlink', 'is_active' => true, 'created_at' => '2026-10-01 08:00:05']],
                       'retailers.json' => [['id' => 1, 'name' => 'TEST Admin', 'role' => 'admin', 'is_admin' => true, 'is_active' => true]]],
            'post' => $post]);
        return is_array($r['out']) ? $r['out'] : ['raw' => substr((string)$r['raw'], 0, 1500)];
    };
    $before = ['plans' => [['id' => 3, 'name' => 'TEST Standard', 'customer_price' => 200000, 'ucrm_product_id' => 99, 'is_active' => true, 'created_at' => '2026-10-01 08:00:03']],
               'devices' => [['id' => 5, 'title' => 'TEST Standard Kit', 'sell_price' => 2000000, 'ucrm_product_id' => 98, 'type' => 'starlink', 'is_active' => true, 'created_at' => '2026-10-01 08:00:05']]];
    foreach ([
        'a plan'  => [['action' => 'ucrm_link_save', 'item' => 'plan', 'row_id' => '3', 'product_id' => '41', 'service_plan_id' => '1'], 41],
        'a kit'   => [['action' => 'ucrm_link_save', 'item' => 'kit', 'row_id' => '5', 'product_id' => '42'], 42],
    ] as $what => [$post, $pid]) {
        $l = $linkSave($new, $post);
        $act = array_values(array_filter((array)($l['links'] ?? []), function ($r) { return is_array($r) && array_key_exists('ended_at_ts', $r) && $r['ended_at_ts'] === null; }));
        $c->eq([$l['flash'][1] ?? null, count($act), (int)($act[0]['product_id'] ?? 0), $act[0]['item'] ?? null],
            ['success', 1, $pid, $post['item']],
            "G3 the control: linking {$what} to uCRM product #{$pid} through ucrm_link_save writes one active link record", json_encode($l));
        $c->eq([$l['plans'] ?? null, $l['devices'] ?? null], [$before['plans'], $before['devices']],
            "G3 … and the plugin's rows are as they were — no ucrm_product_id written ({$what}: still the wrong 99 / 98), no name copied from uCRM");
    }
    foreach ($posts as $what => $p) {
        $same("G3 save {$what}: flash, saved rows and uCRM requests", function ($tree, $k) use ($save, $p) { return $save($tree, $k, $p); },
            function ($a) { return is_array($a['out']['flash'] ?? null) && count($a['out']['saves'] ?? []) > 0 && count($a['requests']) > 0; });
    }

    // ── G4 the product pushes (api_products_admin.php, over HTTP) ───────────────────────────────────────────────────
    static $api = null;
    if ($api === null) {
        [$proc, $port] = qcj_serve(sprintf('exec php -S 127.0.0.1:{PORT} %s', escapeshellarg(__DIR__ . '/g_api_router.php')),
            function (int $port): bool { return qcj_http('GET', "http://127.0.0.1:{$port}/", null, ['X-Qfu-Case: /nonexistent'], null, 2)[0] === 500; },
            15200);
        register_shutdown_function(function () use ($proc) { qcj_stop($proc); });
        $api = $port;
    }
    if (!$api) { $c->t(false, 'G4 the API router started'); return; }
    $call = function (string $tree, string $k, string $act, string $method, ?array $body, string $qs, bool $admin, array $more = []) use ($c, $fake, $api): array {
        qcj_fake($fake, '/__test/reset');
        $dir = $c->dir('g-api');
        $rows = ['subscription_plans.json' => [['id' => 3, 'name' => 'TEST Standard', 'customer_price' => 200000, 'is_active' => true],
                                               ['id' => 4, 'name' => 'TEST Mapped', 'customer_price' => 300000, 'is_active' => true, 'ucrm_product_id' => 41]],
                 'kyc_devices.json' => [['id' => 5, 'title' => 'TEST Standard Kit', 'sell_price' => 2000000, 'is_active' => true]]] + $more;
        file_put_contents($dir . '/store.json', json_encode($rows));
        file_put_contents($dir . '/case.json', json_encode(['root' => $tree, 'cfg' => QFU_G_CFG[$k] + ['crm_base_url' => $fake . '/api/v2.1', 'crm_auth_token' => 'TEST-TOKEN'],
            'data' => $dir, 'store' => $dir . '/store.json', 'admin' => $admin, 'act' => $act, 'crm' => $fake . '/api/v2.1']));
        [$code, $raw] = qcj_http($method, "http://127.0.0.1:{$api}/?page=api&action={$act}{$qs}", $body === null ? null : json_encode($body),
            ['Content-Type: application/json', 'X-Qfu-Case: ' . $dir . '/case.json']);
        return [['code' => $code, 'body' => $raw, 'requests' => qcj_reqs(qcj_fake_log($fake)),
                 'store_unchanged' => json_decode((string)file_get_contents($dir . '/store.json'), true) === $rows,
                 'store' => json_decode((string)file_get_contents($dir . '/store.json'), true)], [$dir => 'DIR', rtrim($tree, '/') => 'TREE']];
    };
    $calls = [
        'push_products_to_ucrm' => ['push_products_to_ucrm', 'GET', null, '&dry_run=0'],
        'push_products_to_ucrm (dry run)' => ['push_products_to_ucrm', 'GET', null, '&dry_run=1'],
        'sync_hardware_to_ucrm' => ['sync_hardware_to_ucrm', 'POST', ['hw_id' => 5], ''],
        'sync_plan_to_ucrm' => ['sync_plan_to_ucrm', 'POST', ['plan_id' => 3], ''],
    ];
    $refusal = json_encode(['status' => 'error', 'message' => 'uCRM\'s product was not changed: on Uganda, prices are set in uCRM Products.']);
    foreach ($calls as $what => [$act, $m, $b, $qs]) {
        [$r] = $call($new, 'ug', $act, $m, $b, $qs, true);
        $c->eq([$r['code'], $r['body'], $r['requests'], $r['store_unchanged']], [409, $refusal, [], true],
            "G4 Uganda, {$what} (an administrator): 409 with the reason, no uCRM request, the store as it was");
        [$r] = $call($new, 'ug', $act, $m, $b, $qs, false);
        $c->eq([$r['code'], $r['body'], $r['requests']], [403, json_encode(['status' => 'error', 'message' => 'Admin only.']), []],
            "G4 Uganda, {$what} (not an administrator): still 403, before anything else");
    }
    if ($old !== null) {
        [$r] = $call($new, 'ss', 'push_products_to_ucrm', 'GET', null, '&dry_run=0', true);
        $c->t($r['code'] === 200 && count($r['requests']) > 0, 'G4 the control: on South Sudan the push reads and writes uCRM\'s products, as before',
            json_encode([$r['code'], $r['requests']]));
    }
    foreach ($calls as $what => [$act, $m, $b, $qs]) {
        foreach ([true, false] as $admin) {
            $same("G4 {$what}" . ($admin ? '' : ' (not an administrator)') . ': status, answer, uCRM requests and store',
                function ($tree, $k) use ($call, $act, $m, $b, $qs, $admin) { return $call($tree, $k, $act, $m, $b, $qs, $admin); },
                function ($a) use ($admin) { return is_array(json_decode($a['body'], true)) && ($admin ? $a['code'] === 200 && count($a['requests']) > 0 : $a['code'] === 403); });
        }
    }

    // 5.18.102 D14 (docs/71 §R.2.6, Q7's default): Auto-Map and set_product_mapping answer 409 on Uganda, before anything is
    // read or written — the push's way. ucrm_products.json (the local list Auto-Map matches against) is present, so the
    // South Sudan control maps and writes; a non-administrator is still answered 403 first.
    $mapRows = ['ucrm_products.json' => [['id' => 41, 'name' => 'TEST Standard', 'price' => 200000], ['id' => 42, 'name' => 'TEST Standard Kit', 'price' => 2000000]]];
    $mapCalls = [
        'auto_map_products' => ['auto_map_products', 'GET', null, '&dry_run=0'],
        'auto_map_products (dry run)' => ['auto_map_products', 'GET', null, '&dry_run=1'],
        'set_product_mapping' => ['set_product_mapping', 'POST', ['source' => 'subscription_plans', 'local_id' => 3, 'ucrm_product_id' => 99], ''],
    ];
    $refusal409 = json_encode(['status' => 'error', 'message' => 'On Uganda, plans and kits are linked to uCRM on the Plans and Hardware screens.']);
    if ($old !== null) {
        foreach (['auto_map_products', 'set_product_mapping'] as $what) {
            [$act, $m, $b, $qs] = $mapCalls[$what];
            [$r] = $call($new, 'ss', $act, $m, $b, $qs, true, $mapRows);
            $c->t($r['code'] === 200 && !$r['store_unchanged'] && (json_decode($r['body'], true)['status'] ?? '') === 'success',
                "G4 the control: on South Sudan {$what} answers 200 and writes ucrm_product_id into the store, as before", json_encode([$r['code'], $r['body']]));
        }
    }
    foreach ($mapCalls as $what => [$act, $m, $b, $qs]) {
        [$r] = $call($new, 'ug', $act, $m, $b, $qs, true, $mapRows);
        $c->eq([$r['code'], $r['body'], $r['requests'], $r['store_unchanged']], [409, $refusal409, [], true],
            "G4 Uganda, {$what} (an administrator): 409 \"On Uganda, plans and kits are linked to uCRM on the Plans and Hardware screens.\", nothing read from uCRM, the store as it was");
        [$r] = $call($new, 'ug', $act, $m, $b, $qs, false, $mapRows);
        $c->eq([$r['code'], $r['body'], $r['requests']], [403, json_encode(['status' => 'error', 'message' => 'Admin only.']), []],
            "G4 Uganda, {$what} (not an administrator): still 403, before anything else");
        foreach ([true, false] as $admin) {
            $same("G4 {$what}" . ($admin ? '' : ' (not an administrator)') . ': status, answer, uCRM requests and store',
                function ($tree, $k) use ($call, $act, $m, $b, $qs, $admin, $mapRows) { return $call($tree, $k, $act, $m, $b, $qs, $admin, $mapRows); },
                function ($a) use ($admin) { return is_array(json_decode($a['body'], true)) && ($admin ? $a['code'] === 200 : $a['code'] === 403); });
        }
    }

    // ── G5 the notes-prefix setting (settings.php, Registration) ───────────────────────────────────────────────────
    $settings = function (string $tree, string $k) use ($c): array {
        $r = qfu_g_php($c, $tree, '
error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE & ~E_DEPRECATED);
final class QfuGAny {
    public function __call($n, $a) {
        if ($n === "load") return [];
        if ($n === "testConnection") return ["ok" => false, "error" => ""];
        if ($n === "isConfigured") return false;
        if ($n === "getSource") return ["source" => "none", "url" => "", "label" => ""];
        if ($n === "getPdo") return new \PDO("sqlite::memory:");
        if ($n === "getDataDir") return $GLOBALS["dataDir"];
        return null;
    }
}
function h($s) { return htmlspecialchars((string)($s ?? ""), ENT_QUOTES, "UTF-8"); }
function csrfToken() { return "TEST-CSRF"; }
function csrfField() { return "<input type=\"hidden\" name=\"_csrf\" value=\"TEST-CSRF\">"; }
function csrfCheck() {}
function flash($m, $t = "success") {}
function redirect($u) { exit; }
function logActivity(...$a) {}
$GLOBALS["_PLUGIN_ROOT"] = $TREE;
require_once "$TREE/lib/currency.php";
$config = $ARGS["cfg"] + ["kyc_quote_notes_prefix" => "TEST prefix"];
$_GET["stab"] = "registration";
$_SERVER["REQUEST_METHOD"] = "GET"; $_SERVER["HTTP_HOST"] = "crm.example.test";
$dataDir = $ARGS["data"];
$crm = new QfuGAny(); $splynx = new QfuGAny(); $store = new QfuGAny(); $auth = new QfuGAny(); $wallet = new QfuGAny(); $notify = new QfuGAny();
$isAdmin = true; $retailer = ["id" => 1, "name" => "TEST Admin", "role" => "admin"];
ob_start(); include "$TREE/tabs/admin/settings.php"; $html = ob_get_clean();
qcj_out(["html" => $html]);', ['cfg' => QFU_G_CFG[$k]]);
        return [['html' => (string)($r['out']['html'] ?? ('NO OUTPUT ' . substr($r['raw'], 0, 1500)))], $r['paths']];
    };
    [$st] = $settings($new, 'ug');
    preg_match('#<input type="text" name="kyc_quote_notes_prefix"[^>]*>#', $st['html'], $in);
    $c->t(isset($in[0]) && strpos($in[0], 'placeholder="e.g. Thank you for choosing DishNet"') !== false && strpos($in[0], '+211') === false,
        'G5 Uganda: the prefix field\'s example is a Uganda one, with no +211', $in[0] ?? substr($st['html'], 0, 300));
    $c->t(strpos($st['html'], '<div class="st-hint">Printed on the customer\'s quotation, in its notes. Leave it empty, or put only text meant for the customer.</div>') !== false,
        'G5 … and the hint says the prefix is printed on the customer\'s quotation');
    [$ss] = $settings($new, 'ss');
    $c->t(strpos($ss['html'], 'placeholder="e.g. Valid 7 days. Call +211..."') !== false && strpos($ss['html'], 'Printed on the customer\'s quotation') === false,
        'G5 the control: South Sudan keeps its example and has no hint');
    $same('G5 the Registration settings page', $settings, function ($a) { return strpos($a['html'], 'name="kyc_quote_notes_prefix"') !== false; });

    // ── G6 the Orders card (applications.php) ──────────────────────────────────────────────────────────────────────
    $apps = function (string $tree, string $k) use ($c): array {
        $r = qfu_g_php($c, $tree, '
error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE & ~E_DEPRECATED);
function h($s) { return htmlspecialchars((string)($s ?? ""), ENT_QUOTES, "UTF-8"); }
function csrfToken() { return "TEST-CSRF"; }
function csrfField() { return "<input type=\"hidden\" name=\"_csrf\" value=\"TEST-CSRF\">"; }
require_once "$TREE/lib/currency.php";
$config = $ARGS["cfg"]; $isAdmin = true; $dataDir = $ARGS["data"];
$_SERVER["HTTP_HOST"] = "crm.example.test";
$myApps = $ARGS["apps"];
ob_start(); include "$TREE/tabs/sales/applications.php"; $html = ob_get_clean();
qcj_out(["html" => $html]);', ['cfg' => QFU_G_CFG[$k], 'apps' => [
            ['id' => 1, 'firstname' => 'TEST', 'lastname' => 'One', 'status' => 'new', 'crm_client_id' => '901', 'crm_sync_status' => 'synced', 'customer_type' => 'StarLink',
             'install_by' => 'dishnet', 'amount_charged' => 1800, 'sales_type' => 'Cash', 'connectivity_type' => 'New Connection'],
            ['id' => 2, 'firstname' => 'TEST', 'lastname' => 'Two', 'status' => 'new', 'crm_client_id' => '902', 'crm_sync_status' => 'synced', 'customer_type' => 'StarLink',
             'install_by' => 'customer', 'sales_type' => 'Credit', 'quote_refused' => 'P1',
             'quote_refused_text' => 'No quotation was made: TEST Kit is not a product in uCRM.', 'quote_refused_at' => '2026-10-27 10:00:00'],
            ['id' => 3, 'firstname' => 'TEST', 'lastname' => 'Three', 'status' => 'new', 'crm_client_id' => '903', 'customer_type' => 'StarLink', 'install_by' => 'weird',
             'quote_created' => true, 'quote_mismatch' => ['sent_total' => 1800, 'created_total' => 2124, 'at' => '2026-10-27 10:00:00']],
            ['id' => 4, 'firstname' => 'TEST', 'lastname' => 'Four', 'status' => 'new', 'crm_client_id' => '904', 'customer_type' => 'StarLink', 'quote_created' => true,
             'quote_mismatch' => ['sent_total' => 100, 'created_total' => null, 'at' => '2026-10-27 10:00:00']],
            ['id' => 5, 'firstname' => 'TEST', 'lastname' => 'Five', 'status' => 'new', 'crm_client_id' => '905', 'customer_type' => 'StarLink', 'quote_created' => true,
             'quote_mismatch' => ['sent_total' => 1800, 'created_total' => 1800, 'at' => '2026-10-27 10:00:00']],
        ]]);
        return [['html' => (string)($r['out']['html'] ?? ('NO OUTPUT ' . substr($r['raw'], 0, 1500)))], $r['paths']];
    };
    [$ap] = $apps($new, 'ug');
    $cards = preg_split('#<span><i class="bi bi-cloud-check"></i> CRM: #', $ap['html']);
    $chip = function (int $i) use ($cards): array {
        $seg = (string)($cards[$i] ?? '');
        return [strpos($seg, 'Install: DishNet') !== false, strpos($seg, 'Customer installs — kit only') !== false];
    };
    $c->eq([count($cards), $chip(1), $chip(2), $chip(3), $chip(4), $chip(5), substr_count($ap['html'], '<i class="bi bi-tools"></i>')],
        [6, [true, false], [false, true], [false, false], [false, false], [false, false], 2],
        'G6 Uganda: "Install: DishNet" and "Customer installs — kit only" on their two cards, and nothing for any other stored value');
    $c->t(strpos($ap['html'], 'The quotation uCRM made totals UGX 2,124.00, not the UGX 1,800.00 sent — check it in uCRM.') !== false,
        'G6 a quotation uCRM made at another total: both totals named');
    // 5.18.102 (rewritten on purpose after the review, X4): a read-back that failed says so, and lines that differ at the
    // same total say that — never "totals X, not the X sent", never a mismatch nobody saw.
    $c->t(strpos($ap['html'], 'The quotation could not be read back from uCRM — check it there.') !== false
        && strpos($ap['html'], 'does not match what was sent') === false,
        'G6 … a quotation that could not be read back (no total): says so, and claims no mismatch');
    $c->t(strpos($ap['html'], 'The quotation uCRM made has other lines than the ones sent, for the same total — check it in uCRM.') !== false
        && strpos($ap['html'], 'totals UGX 1,800.00, not the UGX 1,800.00 sent') === false,
        'G6 … other lines at the same total: says so, never "totals X, not the X sent"');
    $c->t(strpos($ap['html'], 'No quotation was made: TEST Kit is not a product in uCRM.') !== false, 'G6 … the 5.18.99 reason block shows a P1 text unchanged');
    [$as] = $apps($new, 'ss');
    $c->t(strpos($as['html'], 'bi-tools') === false && strpos($as['html'], 'Check the quotation in uCRM') === false, 'G6 the control: none of it on South Sudan');
    $same('G6 the Orders page', $apps, function ($a) { return substr_count($a['html'], 'bi-cloud-check') === 5; });

    // ── G7 the staff messages (NotificationService) ────────────────────────────────────────────────────────────────
    $notify = function (string $tree, string $k) use ($c): array {
        $r = qfu_g_php($c, $tree, QFU_G_STORE . '
$DATA = $ARGS["data"]; $GLOBALS["DATA"] = $DATA;
require_once "$TREE/lib/NotificationService.php";
final class QfuGNotify extends NotificationService {
    public $sent = [];
    public function sendVia(string $sender, string $toPhone, string $message, string $event = "", array $vars = [], string $class = ContactOptOut::CLASS_TRANSACTIONAL): void
    { $this->sent[] = [$sender, $toPhone, $event, $message, $vars]; }
}
$out = [];
$retailer = ["name" => "TEST Agent", "phone" => "+256700000160"];
foreach ([null, "dishnet", "customer", "other"] as $by) {
    foreach ([false, true] as $paid) {
        $app = ["id" => 7, "firstname" => "TEST", "lastname" => "Notify", "customer_type" => "StarLink", "connectivity_type" => "New Connection",
                "amount_charged" => 1800, "mobile" => "+256700000161", "address_1" => "Plot 1 Test Road", "sales_type" => "Cash",
                "username" => "star-7", "quote_id" => 314, "crm_client_id" => "901", "delivery_pdf_sent" => true];
        if ($paid) $app["payment_id"] = 55;
        if ($by !== null) $app["install_by"] = $by;
        $n = new QfuGNotify(new QfuGStore(["retailers.json" => [["id" => 9, "role" => "support_leader", "phone" => "+256700000162", "name" => "TEST Lead"]]]), $ARGS["cfg"]);
        $n->kycSubmitted($retailer, $app);
        $n->kycAdditionalService($retailer, $app, ["quote_ref" => "Q-TEST-9", "crm_job_id" => 12, "new_address" => "Plot 9 Test Road"]);
        $n->kycCrmCreated($retailer, $app, "901");
        $out[] = ["by" => $by, "paid" => $paid, "sent" => $n->sent];
    }
}
qcj_out($out);', ['cfg' => QFU_G_CFG[$k] + ['whatsapp_admin_phone' => '+256700000163']]);
        return [['out' => $r['out'] ?? $r['raw']], $r['paths']];
    };
    [$nu] = $notify($new, 'ug');
    $v = []; foreach ((array)$nu['out'] as $x) $v[($x['by'] ?? 'none') . '|' . ($x['paid'] ? 'paid' : 'unpaid')] = $x['sent'];
    $with = function (string $key, string $line) use ($v): int { return count(array_filter((array)($v[$key] ?? []), function ($s) use ($line) { return strpos($s[3], $line) !== false; })); };
    $c->t(count($v) === 8 && count($v['none|unpaid'] ?? []) >= 6, 'G7 the control: every variant sent its messages', json_encode(array_map('count', $v)));
    $c->eq([$with('dishnet|unpaid', "🔧 Install: DishNet\n"), $with('customer|unpaid', "🔧 Customer installs — kit only\n"),
            $with('none|unpaid', '🔧'), $with('other|paid', '🔧')], [6, 6, 0, 0],
        'G7 Uganda: the install line in all six staff sends (registration ×2, additional service ×3, account created ×1); none when unanswered or unknown');
    $c->t(($v['other|paid'] ?? 1) === ($v['none|paid'] ?? 2) && ($v['other|unpaid'] ?? 1) === ($v['none|unpaid'] ?? 2), 'G7 … an unknown value adds nothing at all: the messages are those of no answer');
    $next = function (string $key) use ($v): array {
        foreach ((array)($v[$key] ?? []) as $s) if (strpos($s[3], '✅ *CRM Account Created*') !== false) {
            return [preg_match('/➡️ Next: (.*)$/u', $s[3], $m) ? $m[1] : null, $s[4]['next_step'] ?? null];
        }
        return [null, null];
    };
    $c->eq([$next('customer|paid'), $next('dishnet|paid'), $next('customer|unpaid')],
        [['Hand over kit (no installation)', 'Hand over kit (no installation)'], ['Schedule installation', 'Schedule installation'], ['Awaiting payment', 'Awaiting payment']],
        'G7 "Next": hand over the kit only when paid and the customer installs; DishNet installing still schedules; unpaid still awaits payment');
    $same('G7 the staff messages, every variant', $notify, function ($a) { return is_array($a['out']) && count($a['out']) === 8 && count($a['out'][0]['sent'] ?? []) >= 6; });

    // ── G8 the bulk-push buttons (subscription_plans.php, hardware.php, product_mapping.php) ───────────────────────
    // 5.18.102 D14 (docs/71 §R.9.1 G8): drawn with a link store present (a link for each item) and every row carrying a
    // ucrm_product_id; $get asks for the picker. What the store was asked for is returned apart, never compared.
    $page = function (string $tree, string $k, string $file, array $get = []) use ($c): array {
        $r = qfu_g_php($c, $tree, QFU_G_STORE . '
error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE & ~E_DEPRECATED);
$DATA = $ARGS["data"]; $GLOBALS["DATA"] = $DATA;
function h($s) { return htmlspecialchars((string)($s ?? ""), ENT_QUOTES, "UTF-8"); }
function csrfToken() { return "TEST-CSRF"; }
function csrfField() { return "<input type=\"hidden\" name=\"_csrf\" value=\"TEST-CSRF\">"; }
require_once "$TREE/lib/currency.php"; require_once "$TREE/lib/crm_url.php";
final class QfuGAuth2 { public function requireLogin() { return ["id" => 1, "name" => "TEST Admin", "role" => "admin", "is_admin" => true]; } }
$auth = new QfuGAuth2();
$config = $ARGS["cfg"]; $dataDir = $DATA; $isAdmin = true; $retailer = ["id" => 1, "name" => "TEST Admin", "role" => "admin"];
$_SERVER["HTTP_HOST"] = "crm.example.test"; $_GET = $ARGS["get"];
$store = new QfuGStore(["subscription_plans.json" => [["id" => 3, "name" => "TEST Standard", "type" => "starlink", "customer_price" => 200000, "starlink_cost" => 100000, "profit" => 100000, "is_active" => true, "ucrm_product_id" => 41, "created_at" => "2026-10-01 08:00:03"]],
                        "kyc_devices.json" => [["id" => 5, "title" => "TEST Standard Kit", "type" => "starlink", "sell_price" => 2000000, "buy_price" => 1500000, "is_active" => true, "ucrm_product_id" => 42, "created_at" => "2026-10-01 08:00:05"]],
                        "ucrm_quote_links.json" => $ARGS["links"]]);
ob_start(); include "$TREE/tabs/sales/" . $ARGS["file"]; $html = ob_get_clean();
qcj_out(["html" => $html, "touched" => array_values(array_unique($store->loads))]);', ['cfg' => QFU_G_CFG[$k], 'file' => $file, 'get' => (object)$get,
            'links' => [
                ['id' => 1, 'item' => 'plan', 'row_id' => 3, 'row_stamp' => '2026-10-01 08:00:03', 'product_id' => 41, 'service_plan_id' => 1, 'organization_id' => 1,
                 'row_name' => 'TEST Standard', 'product_name' => 'TEST Basic', 'service_plan_name' => 'TEST Basic', 'linked_by_id' => 1, 'linked_by_name' => 'TEST Admin',
                 'linked_at' => '2026-10-01 10:00:00', 'ended_at' => null, 'ended_by_id' => null, 'ended_why' => null, 'linked_at_ts' => 1790848801, 'ended_at_ts' => null],
                ['id' => 2, 'item' => 'kit', 'row_id' => 5, 'row_stamp' => '2026-10-01 08:00:05', 'product_id' => 42, 'service_plan_id' => null, 'organization_id' => 1,
                 'row_name' => 'TEST Standard Kit', 'product_name' => 'TEST Kit Pro', 'service_plan_name' => null, 'linked_by_id' => 1, 'linked_by_name' => 'TEST Admin',
                 'linked_at' => '2026-10-01 10:00:00', 'ended_at' => null, 'ended_by_id' => null, 'ended_why' => null, 'linked_at_ts' => 1790848802, 'ended_at_ts' => null],
                ['id' => 3, 'item' => 'install', 'row_id' => 0, 'row_stamp' => '', 'product_id' => 70, 'service_plan_id' => null, 'organization_id' => 1,
                 'row_name' => 'Installation', 'product_name' => 'Professional Installation', 'service_plan_name' => null, 'linked_by_id' => 1, 'linked_by_name' => 'TEST Admin',
                 'linked_at' => '2026-10-01 10:00:00', 'ended_at' => null, 'ended_by_id' => null, 'ended_why' => null, 'linked_at_ts' => 1790848803, 'ended_at_ts' => null],
            ]]);
        return [['html' => (string)($r['out']['html'] ?? ('NO OUTPUT ' . substr($r['raw'], 0, 1500)))], $r['paths'], (array)($r['out']['touched'] ?? [])];
    };
    $lines = [
        'subscription_plans.php' => "        if (d.status === 'error') { alert(d.message || 'Not done.'); return; }\n        var s = (d.data||d).summary || {};\n        alert('✅ Done!",
        'hardware.php'           => "        if (d.status === 'error') { alert(d.message || 'Not done.'); return; }\n        var s = (d.data||d).summary || {};\n        alert('✅ Done!",
        'product_mapping.php'    => "    if (res.status === 'error') { showNotice('', 'warn'); document.getElementById('pm-notice').textContent = res.message || 'Not done.'; return; }\n    var d = res.data || res;",
    ];
    // 5.18.102 D14: the picker each screen opens (§R.2.3) — asked for on every tenant, drawn only where the gate is on
    $pickers = [
        'subscription_plans.php' => ['ucrm_link_plan' => '3'],
        'hardware.php'           => ['ucrm_link_hw' => '5'],
        'product_mapping.php'    => [],
    ];
    $real8 = function ($a) { return strlen($a['html']) > 1000 && strpos($a['html'], 'NO OUTPUT') !== 0 && strpos($a['html'], 'push_products_to_ucrm') !== false; };
    foreach ($lines as $file => $line) {
        [$pg, , $tug] = $page($new, 'ug', $file);
        $c->t(substr_count($pg['html'], $line) === 1, "G8 Uganda, {$file}: the refused push is shown as refused, before any \"Done\" (the API's 409)",
            substr($pg['html'], 0, 400));
        [$ps, , $tss] = $page($new, 'ss', $file);
        $c->t(strpos($ps['html'], $line) === false && strlen($ps['html']) > 1000, "G8 the control: {$file} on South Sudan has no such line");
        $same("G8 {$file}, with a link store present", function ($tree, $k) use ($page, $file) { return $page($tree, $k, $file); }, $real8);
        if ($pickers[$file] !== []) {
            $same("G8 {$file}, with a link store present and the picker asked for (" . http_build_query($pickers[$file]) . ')',
                function ($tree, $k) use ($page, $file, $pickers) { return $page($tree, $k, $file, $pickers[$file]); }, $real8);
            // the control: where the gate is on, the store's links are read and the link column is drawn
            $c->t(in_array('ucrm_quote_links.json', $tug, true) && strpos($pg['html'], 'value="ucrm_link_clear"') !== false,
                "G8 the control: on Uganda {$file} reads the link store and draws the uCRM link column (Unlink for the linked item)", json_encode($tug));
            $c->t(!in_array('ucrm_quote_links.json', $tss, true) && strpos($ps['html'], 'ucrm_link_') === false,
                "G8 … and on South Sudan {$file} never asks the store for ucrm_quote_links.json, and draws no link control", json_encode($tss));
        }
    }
    if ($old !== null) {
        $same('G8 hardware.php, with a link store present and the installation\'s picker asked for (ucrm_link_install=1)',
            function ($tree, $k) use ($page) { return $page($tree, $k, 'hardware.php', ['ucrm_link_install' => '1']); }, $real8);
    }

    // 5.18.102 D14 (§R.8): the link actions and Make the quotation, posted where the gate is off, are unknown actions there,
    // as in 5.18.101 — nothing handled, nothing written, no uCRM request. The control: on Uganda ucrm_link_save is handled
    // (this probe's account is not in the store, so the fresh re-read refuses it with 403).
    foreach ([
        'ucrm_link_save'  => ['action' => 'ucrm_link_save', 'item' => 'plan', 'row_id' => '3', 'product_id' => '41', 'service_plan_id' => '1'],
        'ucrm_link_clear' => ['action' => 'ucrm_link_clear', 'item' => 'plan', 'row_id' => '3'],
        'kyc_make_quote'  => ['action' => 'kyc_make_quote', 'app_id' => '1'],
    ] as $what => $p) {
        $same("G8 POST {$what}: an unknown action, as in 5.18.101", function ($tree, $k) use ($save, $p) { return $save($tree, $k, $p); },
            function ($a) { return is_array($a['out']) && ($a['out']['flash'] ?? null) === 'NO REDIRECT' && $a['requests'] === []; });
    }
    [$s] = $save($new, 'ug', ['action' => 'ucrm_link_save', 'item' => 'plan', 'row_id' => '3', 'product_id' => '41', 'service_plan_id' => '1']);
    $c->t(!is_array($s['out']) && strpos((string)$s['out'], 'Access denied — admin only.') !== false,
        'G8 the control: on Uganda the same ucrm_link_save is handled — the fresh account re-read refuses it (403)', substr(json_encode($s['out']), 0, 300));

    // 5.18.102 D14 (§R.4.8, §R.4.4): the Orders page with web P1 applications — the five conditions of Make the quotation
    // hold — and with the Orders filter asked for. Where the gate is off, byte for byte f2055baf's and no Make the
    // quotation; the control: on Uganda the button is drawn for each of them.
    $orders = function (string $tree, string $k, array $get = [], bool $admin = true, array $extra = []) use ($c): array {
        $r = qfu_g_php($c, $tree, '
error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE & ~E_DEPRECATED);
function h($s) { return htmlspecialchars((string)($s ?? ""), ENT_QUOTES, "UTF-8"); }
function csrfToken() { return "TEST-CSRF"; }
function csrfField() { return "<input type=\"hidden\" name=\"_csrf\" value=\"TEST-CSRF\">"; }
require_once "$TREE/lib/currency.php";
$config = $ARGS["cfg"]; $isAdmin = $ARGS["admin"]; $dataDir = $ARGS["data"];
$_SERVER["HTTP_HOST"] = "crm.example.test"; $_GET = $ARGS["get"];
$myApps = $ARGS["apps"];
ob_start(); include "$TREE/tabs/sales/applications.php"; $html = ob_get_clean();
qcj_out(["html" => $html]);', ['cfg' => QFU_G_CFG[$k], 'get' => (object)$get, 'admin' => $admin, 'apps' => [
            ['id' => 11, 'firstname' => 'TEST', 'lastname' => 'Webone', 'status' => 'new', 'crm_client_id' => '911', 'crm_sync_status' => 'synced', 'customer_type' => 'StarLink',
             'sales_type' => 'Credit', 'quote_sale_entry' => 'web', 'quote_refused' => 'P1', 'quote_refused_at' => '2026-10-27 10:00:00',
             'quote_refused_text' => 'No quotation was made: TEST Kit is not linked to a uCRM product yet — an admin links it on the Hardware screen. Do not make it by hand — once the item is linked, an admin makes it from the Orders card.'],
            $extra + ['id' => 12, 'firstname' => 'TEST', 'lastname' => 'Webtwo', 'status' => 'new', 'crm_client_id' => '912', 'crm_sync_status' => 'synced', 'customer_type' => 'StarLink',
             'sales_type' => 'Cash', 'amount_charged' => 1800, 'quote_sale_entry' => 'web', 'quote_refused' => 'P1', 'quote_refused_at' => '2026-10-27 10:00:00',
             'quote_refused_text' => 'No quotation was made: uCRM\'s products could not be read. Make the quotation in uCRM (Send Quotation, or ask an admin).',
             'quote_id' => 77, 'quote_link_ids' => [1, 2]],
        ]]);
        return [['html' => (string)($r['out']['html'] ?? ('NO OUTPUT ' . substr($r['raw'], 0, 1500)))], $r['paths']];
    };
    [$ou] = $orders($new, 'ug');
    $c->eq([substr_count($ou['html'], '>Make the quotation</button>'), substr_count($ou['html'], 'name="action" value="kyc_make_quote"')], [2, 2],
        'G8 the control: on Uganda both web P1 applications draw Make the quotation (the five conditions hold)');
    if ($old !== null) {
        foreach (QFU_G_OFF as $k => $what) {
            [$ox] = $orders($new, $k);
            $c->t(strpos($ox['html'], 'Make the quotation</button>') === false && strpos($ox['html'], 'kyc_make_quote') === false && substr_count($ox['html'], 'bi-cloud-check') === 2,
                "G8 {$what}: the same web P1 applications draw no Make the quotation");
        }
    }
    $realO = function ($a) { return substr_count($a['html'], 'bi-cloud-check') === 2; };
    $same('G8 the Orders page with web P1 applications (quote_sale_entry web)', function ($tree, $k) use ($orders) { return $orders($tree, $k); }, $realO);
    $same('G8 the Orders page with the Orders filter asked for (quote_link=1), as an administrator', function ($tree, $k) use ($orders) { return $orders($tree, $k, ['quote_link' => '1']); }, $realO);
    $same('G8 the Orders page with the Orders filter asked for (quote_link=1), not an administrator', function ($tree, $k) use ($orders) { return $orders($tree, $k, ['quote_link' => '1'], false); }, $realO);
    // 5.18.102 D14 review round 3 (CK-flows-2): an application whose last Make the quotation went unconfirmed. On Uganda its
    // card says "did not finish" in place of "No quotation was made" (the control); where the gate is off, f2055baf's page
    $UNC = ['quote_unconfirmed_at' => '2026-10-30 10:00:00'];
    [$ouc] = $orders($new, 'ug', [], true, $UNC);
    $c->eq([substr_count($ouc['html'], '⚠ Making the last quotation did not finish'), substr_count($ouc['html'], '⚠ No quotation was made'), substr_count($ouc['html'], '>Make the quotation</button>')],
        [1, 1, 2], 'G8 the control: on Uganda the application whose last Make the quotation went unconfirmed says "did not finish" (the other still "No quotation was made"); both draw the button');
    $same('G8 the Orders page with an application whose last Make the quotation went unconfirmed (quote_unconfirmed_at)', function ($tree, $k) use ($orders, $UNC) { return $orders($tree, $k, [], true, $UNC); }, $realO);
    // 5.18.102 D14 review round 6 (CK6-F1): a press in flight (a live claim). On Uganda the card says so; gate off, f2055baf's
    $CLM = ['quote_make_claim_ts' => time()];
    [$ocl] = $orders($new, 'ug', [], true, $CLM);
    $c->eq([substr_count($ocl['html'], '⏳ Making a quotation for this application has not finished'), substr_count($ocl['html'], '⚠ No quotation was made'), substr_count($ocl['html'], '>Make the quotation</button>')],
        [1, 1, 2], 'G8 the control: on Uganda the application with a press in flight says it has not finished (the other still "No quotation was made"); both draw the button');
    $same('G8 the Orders page with an application whose Make the quotation is in flight (quote_make_claim_ts)', function ($tree, $k) use ($orders, $CLM) { return $orders($tree, $k, [], true, $CLM); }, $realO);
    // Round 9 (CK9-T8): an expired claim no refusal followed (a press that died). On Uganda the card says "did not finish"; gate
    // off, f2055baf's page — a claim a gate-on period left changes nothing
    $OLDC = ['quote_make_claim_ts' => time() - 901];
    [$oex] = $orders($new, 'ug', [], true, $OLDC);
    $c->eq([substr_count($oex['html'], '⚠ Making the last quotation did not finish'), substr_count($oex['html'], '⚠ No quotation was made'), substr_count($oex['html'], '>Make the quotation</button>')],
        [1, 1, 2], 'G8 the control: on Uganda the application whose claim expired with no refusal after it says "did not finish" (the other still "No quotation was made"); both draw the button');
    $same('G8 the Orders page with an application whose claim expired (quote_make_claim_ts past QUOTE_CLAIM_SECONDS)', function ($tree, $k) use ($orders, $OLDC) { return $orders($tree, $k, [], true, $OLDC); }, $realO);

    // ── G9 Product Mapping (product_mapping.php, §R.2.6) ───────────────────────────────────────────────────────────
    // 5.18.102 D14: on Uganda with the gate on the page stops claiming that ucrm_product_id reaches quotes — drawn here
    // with every row carrying a ucrm_product_id. The header's claim is matched case-insensitively. The control: with the
    // gate off the page carries both claims, as 5.18.101's does.
    [$pm] = $page($new, 'ug', 'product_mapping.php');
    $h9 = $pm['html'];
    $c->t(strpos($h9, 'On Uganda, quotations use only the uCRM links made on the Plans and Hardware screens.') !== false
        && strpos($h9, "showNotice('On Uganda, plans and kits are linked to uCRM on the Plans and Hardware screens.', 'info');") !== false,
        'G9 the control: on Uganda Product Mapping draws its gated header and its one neutral notice, in the server\'s words');
    $c->eq([strpos($h9, 'Quotes will reference real products') !== false, stripos($h9, 'quotes reference real billing items') !== false],
        [false, false], 'G9 Uganda: neither "Quotes will reference real products" nor (any case) "quotes reference real billing items" — every row carries a ucrm_product_id');
    $c->eq([strpos($h9, 'onclick="autoMap(0)"') !== false, strpos($h9, 'Auto-Map</strong> to link them') !== false, strpos($h9, '✅ All Mapped') !== false,
            strpos($h9, '<th>UCRM Product ID</th>') !== false, strpos($h9, '<th>Stored product id (not used for quotes)</th>') !== false],
        [false, false, false, false, true], 'G9 … no Auto-Map button, no "Auto-Map to link them", no "All Mapped" badge; the column reads "Stored product id (not used for quotes)"');
    foreach (QFU_G_OFF as $k => $what) {
        [$px] = $page($new, $k, 'product_mapping.php');
        $c->eq([strpos($px['html'], 'Quotes will reference real products') !== false, stripos($px['html'], 'quotes reference real billing items') !== false,
                strpos($px['html'], 'onclick="autoMap(0)"') !== false],
            [true, true, true], "G9 the control: {$what}, the page carries both claims and Auto-Map, as before");
    }
}];
