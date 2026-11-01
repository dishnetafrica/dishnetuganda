<?php
// 5.18.103's rehearsal (root docs/72 §G.6, §G.7, G-7): a direct sale as the KYC form posts it with the uCRM catalogue
// switched on, priced and shaped by the INSTALLED code — and a web-direct record that breaks G-7, for QC-ON4. Two steps,
// so that the one that reads uCRM never writes and the one that writes never reads uCRM:
//   price  RH103_TOKEN=<token> php rh103-sale.php price <installed root> <plugin folder> <store database> <record file> <sale as JSON>
//          — as root, the store READ-ONLY: the switch and the catalogue's settings from the store's ucrm_catalogue table
//          through the installed UcrmCatalogue::fromRecords() (direct() must be on: with it off the form's post is refused,
//          DIRECT_OFF); the post a direct form makes (ucrm_quote_form = UcrmQuoteLines::DIRECT_MARKER, ucrm_plan,
//          ucrm_cart_json; the plugin-id fields a browser could still send among them) read by the installed
//          KycService::saleFromPost($post, $connectivity, true); priced by the installed UcrmQuoteLines::resolveDirect()
//          over the stand-in uCRM through UcrmQuoteLines::quoteClient() (the admin token, as the store row has it), the
//          installation product and the products kept off passed as handleNew passes them; then G-7 as handleNew applies it
//          — its four lines, which this helper refuses to run unless the installed lib/KycService.php carries each exactly
//          once, verbatim — and the record handleNew saves, its uCRM fields from the installed KycService::linesRecord() and
//          ::directCartJson(), with no uCRM client (uCRM's client creation is not driven: it POSTs, and this rehearsal's
//          stand-in takes GETs only), so pending for uCRM with no quotation, as handleNew saves a sale whose client could not
//          be made. The record goes to <record file>, mode 0600; stdout says its state — never a name, a price or an id.
//   append php rh103-sale.php append <installed root> <data dir> <record file> [g7]
//          — as the store's user, with a vault of its own: the record appended through the installed
//          SqliteStore::appendWithId(), as saveApplication() appends it; with "g7", the same record with package_choice '1'
//          (a plugin plan id where G-7 requires '') and the marker "_rh103g7": a web-direct record that breaks G-7, which no
//          release writer makes. Prints {"rows":1}.
// Synthetic: every record marked "_rh103": true; the customer's name a marked secret of the rehearsal's; no phone.
declare(strict_types=1);
ini_set('display_errors', 'stderr');   // a notice must never reach stdout, which carries the one line the rehearsal reads
$mode = (string)($argv[1] ?? '');
if ($mode === 'price') {
    [, , $root, $pd, $db, $out, $spec] = $argv + [null, null, '', '', '', '', ''];
    foreach (['lib/KycService.php', 'lib/UcrmQuoteLines.php', 'lib/UcrmCatalogue.php'] as $f) {
        if (!is_file($root . '/' . $f)) { fwrite(STDERR, "refusing: the installed root holds no $f\n"); exit(2); }
    }
    $src = (string)file_get_contents($root . '/lib/KycService.php');
    $g7 = ["                \$post['package_choice'] = '';\n", "                \$post['device_id']      = '';\n",
           "                \$post['hw_cart_json']   = self::directCartJson(\$ug);\n",
           "                \$post['kitQty']         = (string)((\$ug['item_ids']['hw'][0]['q'] ?? null) ?: 1);\n"];
    foreach ($g7 as $l) if (substr_count($src, $l) !== 1) { fwrite(STDERR, "refusing: the installed KycService.php does not carry G-7's lines as this helper applies them\n"); exit(2); }
    $sale = json_decode((string)$spec, true);
    if (!is_array($sale)) { fwrite(STDERR, "refusing: no sale given\n"); exit(2); }
    ini_set('error_log', '/dev/null'); umask(0077); date_default_timezone_set('Africa/Kampala');   // the record file 0600 from its making; the plugin's stamps in the server's zone
    foreach (['currency', 'CrmApiClient', 'UcrmQuoteLines', 'UcrmCatalogue', 'KycService'] as $l) if (is_file($root . "/lib/$l.php")) require_once $root . "/lib/$l.php";
    $p = new PDO('sqlite:' . $db, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY]);
    $recs = [];
    if ($p->query("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'ucrm_catalogue'")->fetchColumn() !== false) {
        foreach ($p->query('SELECT id, data FROM ucrm_catalogue ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $d = json_decode((string)$r['data'], true); if (is_array($d)) { $d['id'] = (int)$r['id']; $recs[] = $d; }
        }
    }
    $cat = UcrmCatalogue::fromRecords($recs, true);
    if (!$cat->direct()) { echo json_encode(['status' => 'switch-off']), "\n"; exit(0); }
    $conn = 'New Connection';
    $post = [
        'ucrm_quote_form'   => UcrmQuoteLines::DIRECT_MARKER,
        'ucrm_plan'         => (string)($sale['plan'] ?? ''),
        'ucrm_cart_json'    => json_encode($sale['cart'] ?? []),
        'customer_type'     => (string)($sale['customer_type'] ?? 'StarLink'),
        'connectivity_type' => $conn,
        'install_by'        => (string)($sale['install_by'] ?? 'dishnet'),
        'sales_type'        => (string)($sale['sales_type'] ?? 'Credit'),
        // what a browser could still send in the plugin-id fields: the server overwrites each (G-7)
        'package_choice'    => '1', 'device_id' => '1', 'hw_cart_json' => json_encode([['id' => 1, 'title' => 'x', 'qty' => 1]]), 'kitQty' => '1',
    ];
    $setting = ['on' => true, 'id' => 1, 'source' => 'profile', 'error' => ''];
    $crm = CrmApiClient::fromUcrm($pd, []);
    $client = UcrmQuoteLines::quoteClient($crm, ['crm_auth_token' => (string)getenv('RH103_TOKEN')]);
    $sp = KycService::saleFromPost($post, $conn, true);
    if (($sp['entry'] ?? null) !== UcrmQuoteLines::DIRECT_ENTRY || $sp['direct_plan'] === null || $sp['direct_cart'] === null) {
        echo json_encode(['status' => 'not-direct']), "\n"; exit(0);
    }
    $ug = (new UcrmQuoteLines($client, $setting, UcrmQuoteLinks::fromRecords([], [])))->resolveDirect($sp, $cat->installProductId(), $cat->hiddenIds());
    if ($ug['status'] !== 'priced') { echo json_encode(['status' => (string)$ug['status'], 'why' => (string)($ug['why'] ?? '')]), "\n"; exit(0); }
    // G-7, as handleNew applies it once resolveDirect has answered (the four lines checked above)
    $post['package_choice'] = '';
    $post['device_id']      = '';
    $post['hw_cart_json']   = KycService::directCartJson($ug);
    $post['kitQty']         = (string)(($ug['item_ids']['hw'][0]['q'] ?? null) ?: 1);
    $device = $ug['device'] ?: [];
    $rec = array_merge([
        '_rh103' => true, '_rh103sale' => true,
        'connectivity_type' => $conn, 'customer_type' => $post['customer_type'], 'date' => date('Y-m-d'),
        'crm_client_id' => null, 'crm_sync_status' => 'pending', 'crm_sync_payload' => null, 'crm_sync_error' => 'uCRM gave no answer',
        'quote_items' => null, 'firstname' => 'RH103QSECRET-Guest', 'lastname' => 'RH103QSECRET-Direct', 'mobile' => '', 'email' => '',
        'device_id' => $post['device_id'], 'device_title' => $device['title'] ?? null, 'device_price' => $device['price'] ?? null,
        'package_choice' => $post['package_choice'], 'offer_name' => $ug['offer']['name'] ?? null, 'offer_price' => null,
        'kitQty' => $post['kitQty'], 'priority' => 'Low', 'sales_type' => $post['sales_type'], 'status' => 'new',
        'amount_charged' => 0, 'is_lead' => true, 'crm_client_type' => 'lead', 'hw_cart_json' => $post['hw_cart_json'],
        'quote_id' => null, 'quote_created' => false, 'submitted_at' => date('Y-m-d H:i:s'),
    ], KycService::linesRecord($ug, $post, $conn));
    $fd = fopen($out, 'xb');
    if ($fd === false) { fwrite(STDERR, "refusing: the record file exists or cannot be made\n"); exit(2); }
    chmod($out, 0600);
    fwrite($fd, json_encode($rec, JSON_UNESCAPED_UNICODE)); fclose($fd);
    $cart = json_decode((string)$rec['hw_cart_json'], true);
    echo json_encode(['status' => 'priced', 'lines' => count($ug['lines']), 'entry' => (string)($rec['quote_sale_entry'] ?? ''),
                      'g7' => ($rec['package_choice'] === '' ? 'pc:empty' : 'pc:set') . ',' . ($rec['device_id'] === '' ? 'dev:empty' : 'dev:set') . ','
                              . (is_array($cart) && array_reduce($cart, function ($ok, $c) { return $ok && is_array($c) && ($c['id'] ?? null) === 0; }, true) ? 'cart:ids0' : 'cart:other'),
                      'install' => isset($rec['quote_item_ids']['install']) && $rec['quote_item_ids']['install'] !== null ? 'yes' : 'no']), "\n";
    exit(0);
}
if ($mode === 'append') {
    [, , $root, $data, $file, $g7] = $argv + [null, null, '', '', '', ''];
    if ((string)getenv('DN_VAULT_FILE') === '') { fwrite(STDERR, "refusing: DN_VAULT_FILE must point into the sandbox\n"); exit(2); }
    if (!is_file($root . '/lib/SqliteStore.php') || !is_file($data . '/plugin.sqlite3')) { fwrite(STDERR, "refusing: no installed plugin or no store\n"); exit(2); }
    $rec = json_decode((string)@file_get_contents($file), true);
    if (!is_array($rec) || ($rec['_rh103'] ?? null) !== true || ($rec['quote_sale_entry'] ?? null) !== 'web-direct') { fwrite(STDERR, "refusing: not a rehearsal's direct record\n"); exit(2); }
    if ($g7 === 'g7') { $rec['package_choice'] = '1'; $rec['_rh103g7'] = true; unset($rec['_rh103sale']); }
    elseif ($g7 !== '') { fwrite(STDERR, "refusing: an unknown variant\n"); exit(2); }
    unset($rec['id']);
    foreach (['bootstrap_data', 'StoreInterface', 'JsonStore', 'SqliteStore'] as $l) require_once $root . '/lib/' . $l . '.php';
    $GLOBALS['dataDir'] = $data;
    try { SqliteStore::create($data)->appendWithId('kyc_applications.json', $rec); }
    catch (Throwable $e) { fwrite(STDERR, 'refused: ' . get_class($e) . "\n"); exit(3); }
    echo json_encode(['rows' => 1]), "\n";
    exit(0);
}
fwrite(STDERR, "refusing: price or append\n");
exit(2);
