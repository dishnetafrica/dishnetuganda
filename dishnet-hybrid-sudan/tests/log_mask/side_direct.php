<?php
declare(strict_types=1);
/**
 * side_direct.php — 5.18.105 (root docs/74 §H.1.3, §H.1.4): one direct call of the real code, with fakes, in a process
 * of its own, for tests/test_log_mask.php section A (LN-03, LN-04). TEST ONLY.
 *
 *   php tests/log_mask/side_direct.php <spec.json>
 *
 * A process of its own because it loads ONE tree's classes (the judged tree's copy, or a weakened copy) and PHP's error
 * log is pointed at the spec's file before anything runs (ini_set('error_log', FILE), §H.1.0 "The drive"). The spec
 * names the tree (root), the data folder (data), the configuration (config), the log file (error_log) and the call (do):
 *
 *   quotation  QuotationService::sendManualQuote() then sendLeadQuote(), with $ns replaced by reflection — as
 *              tests/quote_agent_copy/drive.php replaces crm — by a NotificationService whose sendVia throws an
 *              exception carrying the fixture number (LN-03)
 *   bidal      WaAutoReplyService::createCrmJobForBidal() by reflection (it is private), its uCRM the spec's fake uCRM
 *              (crm_base_url), the support leader a retailers.json row (LN-04)
 *
 * It prints one JSON object: each call's answer, or its uncaught throwable. Every name and number is fictitious.
 */
$spec = json_decode((string)file_get_contents((string)($argv[1] ?? '')), true);
if (!is_array($spec)) { fwrite(STDERR, "no spec\n"); exit(2); }
$ROOT = rtrim((string)$spec['root'], '/');
$DATA = rtrim((string)$spec['data'], '/');
date_default_timezone_set('UTC');
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', (string)$spec['error_log']);
error_reporting(E_ALL);
$GLOBALS['dataDir'] = $DATA;
$config = (array)($spec['config'] ?? []);

foreach (['StoreInterface', 'SqliteStore', 'ContactOptOut', 'NotificationService'] as $l) require_once $ROOT . '/lib/' . $l . '.php';
$store = SqliteStore::create($DATA);
$out = [];

if (($spec['do'] ?? '') === 'quotation') {
    require_once $ROOT . '/lib/QuotationService.php';
    /** A notifier whose WhatsApp send throws, its message carrying the number it was asked to reach. */
    $thrower = new class($store, $config) extends NotificationService {
        public $calls = [];
        public function sendVia(string $sender, string $toPhone, string $message, string $event = '', array $vars = [], string $class = ContactOptOut::CLASS_TRANSACTIONAL): void
        {
            $this->calls[] = [$sender, $event];
            throw new \RuntimeException('Evolution refused the send to ' . $toPhone . ' (test)');
        }
    };
    $svc = new QuotationService($store, $DATA, $config);
    $p = new ReflectionProperty(QuotationService::class, 'ns');
    $p->setAccessible(true);
    $p->setValue($svc, $thrower);
    $items = [['label' => 'Test kit', 'quantity' => 1, 'price' => 1000, 'unit' => 'each']];
    $agent = ['name' => 'Test Agent'];
    foreach (['manual' => function () use ($svc, $items, $agent, $spec) {
                  return $svc->sendManualQuote(['customer_name' => 'Test Manual Customer', 'customer_phone' => (string)$spec['phone'],
                                                'items' => $items, 'crm_client_id' => 0], $agent);
              },
              'lead' => function () use ($svc, $items, $agent, $spec) {
                  return $svc->sendLeadQuote(['id' => 9103, 'customer_name' => 'Test Lead Customer', 'phone' => (string)$spec['phone'],
                                              'service_type' => 'starlink'], $items, $agent, false);
              }] as $k => $fn) {
        try { $r = $fn(); $out[$k] = ['ok' => $r['ok'] ?? null, 'sent_via_wa' => $r['sent_via_wa'] ?? null, 'error' => $r['error'] ?? null]; }
        catch (\Throwable $e) { $out[$k] = ['exc' => get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine()]; }
    }
    $out['calls'] = $thrower->calls;
} elseif (($spec['do'] ?? '') === 'bidal') {
    require_once $ROOT . '/lib/ConversationService.php';
    require_once $ROOT . '/lib/WaAutoReplyService.php';
    $pdo = $store->getPdo();
    $svc = new WaAutoReplyService($store, $pdo, new NotificationService($store, $config), $config, new ConversationService($DATA, $pdo));
    $m = new ReflectionMethod(WaAutoReplyService::class, 'createCrmJobForBidal');
    $m->setAccessible(true);
    try { $out['ret'] = $m->invoke($svc, (string)$spec['phone'], 'Test Bidal Customer', 'No internet since this morning (test)', 'support'); }
    catch (\Throwable $e) { $out['exc'] = get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine(); }
} else {
    $out['exc'] = 'unknown call';
}
echo json_encode($out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
