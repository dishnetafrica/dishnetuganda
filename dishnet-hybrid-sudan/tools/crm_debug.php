<?php
declare(strict_types=1);
chdir(dirname(__DIR__));

/**
 * crm_debug.php — the uCRM payment-API probe, from the command line.
 *
 * Until 5.18.37 this lived at public.php?page=crm_debug with no login of any
 * kind: a GET listed the payment methods, a POST created (and deleted) a 0.01
 * payment on a client, and another POST replayed the whole payment retry
 * queue. Creating payments in uCRM is not something a public URL may do, so
 * the page is gone and this is its replacement — run by an operator who is
 * already on the server.
 *
 *   php tools/crm_debug.php --status                 queue counts, recent errors, configured?
 *   php tools/crm_debug.php --methods                uCRM payment methods (id, name, type)
 *   php tools/crm_debug.php --probe --client N --yes create a 0.01 payment on client N and
 *                                                    delete it again; proves the method UUID
 *   php tools/crm_debug.php --retry --yes            replay pending rows of the payment retry
 *                                                    queue through createPaymentSafe()
 *
 * Prints no credential and no key prefix.
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }

$root = dirname(__DIR__);
require_once $root . '/lib/error_handler.php';
require_once $root . '/lib/bootstrap_data.php';
require_once $root . '/lib/StoreInterface.php';
require_once $root . '/lib/JsonStore.php';
require_once $root . '/lib/SqliteStore.php';
require_once $root . '/lib/PluginConfig.php';
require_once $root . '/lib/CrmApiClient.php';
require_once $root . '/lib/PaymentUuids.php';
require_once $root . '/lib/currency.php';

$args = $argv ?? [];
$has  = static function (string $flag) use ($args): bool { return in_array($flag, $args, true); };
$val  = static function (string $flag) use ($args): ?string {
    $i = array_search($flag, $args, true);
    return ($i !== false && isset($args[$i + 1])) ? (string)$args[$i + 1] : null;
};

$dataDir = cliDataDir($root);
$store   = SqliteStore::create($dataDir);
$config  = PluginConfig::load($root, $dataDir);
$crm     = CrmApiClient::fromUcrm($root, $config);

$out = static function (array $data): void { echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n"; };

if ($has('--methods')) {
    $methods = $crm->get('payment-methods') ?? [];
    $list = [];
    foreach ((array)$methods as $m) {
        if (!empty($m['id'])) $list[] = ['id' => $m['id'], 'name' => $m['name'] ?? '', 'type' => $m['type'] ?? ''];
    }
    $out(['payment_methods' => $list, 'count' => count($list)]);
    exit(0);
}

if ($has('--probe')) {
    $clientId = (int)($val('--client') ?? 0);
    if ($clientId <= 0) { fwrite(STDERR, "  --probe needs --client <uCRM client id>\n"); exit(1); }
    if (!$has('--yes')) {
        echo "  This creates a 0.01 payment on client #{$clientId} in uCRM and deletes it again.\n"
           . "  Add --yes to proceed.\n";
        exit(1);
    }
    $methods = $crm->get('payment-methods') ?? [];
    $cashId  = null;
    foreach ((array)$methods as $m) {
        if (stripos(($m['name'] ?? '') . ($m['type'] ?? ''), 'cash') !== false && !empty($m['id'])) { $cashId = $m['id']; break; }
    }
    $variants = ['no_method' => ['clientId' => $clientId, 'amount' => 0.01,
                                 'currencyCode' => dn_payload_currency('', $config), 'note' => 'API probe - ignore']];
    if ($cashId) {
        $variants = ['methodId_uuid' => $variants['no_method'] + ['methodId' => $cashId]] + $variants;
    }
    $probe = ['payment_methods_found' => count((array)$methods)];
    foreach ($variants as $label => $payload) {
        $result = $crm->post('payments', $payload);
        if (!empty($result['id'])) {
            $crm->delete('payments/' . $result['id']);
            $probe['result'] = ['status' => 'SUCCESS', 'variant' => $label, 'payment_id' => $result['id'], 'deleted' => true];
            break;
        }
        $err = $crm->getLastError();
        $probe[$label] = ['status' => 'FAIL', 'http_code' => $err['http_code'] ?? null];
    }
    $out($probe);
    exit(isset($probe['result']) ? 0 : 1);
}

if ($has('--retry')) {
    if (!$has('--yes')) { echo "  This posts every pending row of the payment retry queue to uCRM. Add --yes to proceed.\n"; exit(1); }
    $retryQ  = $store->load('crm_payment_retry.json') ?? [];
    $results = [];
    foreach ($retryQ as $i => $rq) {
        if (($rq['status'] ?? '') !== 'pending') continue;
        $payload = $rq['payload'] ?? [];
        $rawMethod = null;
        foreach (['methodId', 'method', 'paymentType'] as $mk) {
            if (isset($payload[$mk])) { $rawMethod = $payload[$mk]; unset($payload[$mk]); break; }
        }
        $payload['methodId'] = PaymentUuids::resolve($rawMethod);
        // createPaymentSafe de-duplicates by the reference in the note, so a
        // replay that already reached uCRM is reported, not posted twice.
        $ref = 'RETRY-' . (string)($rq['collection_id'] ?? $i);
        $r   = $crm->createPaymentSafe($payload, $ref);
        if (!empty($r['success']) && !empty($r['id'])) {
            $retryQ[$i]['status']         = 'synced';
            $retryQ[$i]['crm_payment_id'] = $r['id'];
            $retryQ[$i]['synced_at']      = date('Y-m-d H:i:s');
            $retryQ[$i]['payload']        = $payload;
            if (!empty($rq['collection_id'])) {
                $store->updateOne('payment_collections.json', 'id', (int)$rq['collection_id'],
                                  ['crm_synced' => true, 'crm_payment_id' => $r['id']]);
            }
            $results[] = ['row' => $i, 'status' => !empty($r['duplicate']) ? 'already in uCRM' : 'synced', 'crm_payment_id' => $r['id']];
        } else {
            $retryQ[$i]['attempts']      = ($rq['attempts'] ?? 1) + 1;
            $retryQ[$i]['last_error']    = (string)($r['error'] ?? 'unknown');
            $retryQ[$i]['payload']       = $payload;
            $retryQ[$i]['next_retry_at'] = date('Y-m-d H:i:s', time() + 300);
            $results[] = ['row' => $i, 'status' => 'failed', 'error' => (string)($r['error'] ?? 'unknown')];
        }
    }
    $store->save('crm_payment_retry.json', $retryQ);
    $out(['results' => $results]);
    exit(0);
}

// --status (default)
$retryQ  = $store->load('crm_payment_retry.json') ?? [];
$pending = array_filter($retryQ, static fn($r) => ($r['status'] ?? '') === 'pending');
$failed  = array_filter($retryQ, static fn($r) => ($r['status'] ?? '') === 'failed');
$out([
    'crm_configured' => $crm->isConfigured(),
    'retry_queue'    => ['total' => count($retryQ), 'pending' => count($pending), 'failed' => count($failed)],
    'recent'         => array_values(array_map(static fn($r) => [
        'customer' => $r['customer_name'] ?? '',
        'amount'   => $r['payload']['amount'] ?? 0,
        'attempts' => $r['attempts'] ?? 0,
        'status'   => $r['status'] ?? '',
        'error'    => $r['last_error'] ?? $r['error'] ?? 'none',
        'created'  => $r['created_at'] ?? '',
    ], array_slice(array_reverse(array_values($retryQ)), 0, 5))),
    'usage' => ['--methods', '--probe --client N --yes', '--retry --yes'],
]);
