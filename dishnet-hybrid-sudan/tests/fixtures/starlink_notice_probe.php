<?php
/**
 * starlink_notice_probe.php — TEST ONLY (5.18.54, docs/46 row 45). One Starlink "order confirmed" e-mail through the real
 * StarlinkMailWorker of the plugin tree named, for a customer whose number uCRM stores as given, against fakes for
 * uCRM, Evolution and the classifier. Prints one JSON line, the last: what the worker returned, and the number each
 * WhatsApp was sent to.
 *
 *   php starlink_notice_probe.php <pluginRoot> <uganda|south-sudan> <number as uCRM stores it>
 *
 * In memory only; no vault decides the country (DN_VAULT_FILE names a file that is not there).
 */
[$_, $root, $tenant, $phone] = array_pad($argv, 4, '');
putenv('DN_VAULT_FILE=' . sys_get_temp_dir() . '/starlink-notice-no-vault-' . getmypid() . '.json');

require_once $root . '/lib/MailProviderInterface.php';
require_once $root . '/lib/CustomerIdentityService.php';
require_once $root . '/lib/StarlinkMailClassifier.php';
require_once $root . '/workers/StarlinkMailWorker.php';

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec((string)file_get_contents($root . '/migrations/063_customer_identity.sql'));

$provider = new class implements MailProviderInterface {
    public function name(): string { return 'null'; }
    public function isConfigured(): bool { return true; }
    public function ensureMailbox(string $e, string $d, int $q = 250): array { return ['ok' => true, 'data' => $e, 'error' => '']; }
    public function suspendMailbox(string $e): array { return ['ok' => true, 'data' => true, 'error' => '']; }
    public function unsuspendMailbox(string $e): array { return ['ok' => true, 'data' => true, 'error' => '']; }
    public function resetPassword(string $e): array { return ['ok' => true, 'data' => 'x', 'error' => '']; }
};
$ids = new CustomerIdentityService($pdo, ['identity_domain' => 'dishnetuganda.com'], $provider);
$ids->reserveForClient(500, 'Test Customer');

$crm = new class($phone) {
    private string $phone;
    public function __construct(string $phone) { $this->phone = $phone; }
    public function get(string $path) { return ['firstName' => 'Test', 'contacts' => [['phone' => $this->phone]]]; }
    public function post(string $path, array $body = []) { return []; }
};
$evo = new class {
    public array $to = [];
    public function sendText(string $channel, string $to, string $text, string $class = ''): array { $this->to[] = $to; return ['ok' => true]; }
};
$alerts = new class {
    public array $keys = [];
    public function notify(string $key, string $text, int $cooldownMin = 240): array { $this->keys[] = $key; return ['sent' => true]; }
};
$classifier = new StarlinkMailClassifier([], fn() => json_encode([
    'type' => 'ORDER_CONFIRMED', 'extracted' => [], 'confidence' => 0.97, 'action_required' => false, 'summary' => 'order confirmed',
]));
$cfg = ['starlink_mail_enabled' => true] + ($tenant === 'uganda' ? ['tenant_profile' => 'uganda'] : []);

$out = ['tenant' => $tenant, 'stored' => $phone];
try {
    $w = new StarlinkMailWorker($pdo, $cfg, $ids, $evo, $alerts, $crm, null, $classifier);
    $out['result'] = $w->processEmail([
        'message_id' => '<probe-1@starlink.example>', 'from' => 'noreply@starlink.example',
        'to' => ['test.customer@dishnetuganda.com'], 'subject' => 'Your Starlink order is confirmed',
        'received_at' => '2026-09-30T08:00:00Z', 'body' => 'FAKE order confirmation',
    ]);
} catch (\Throwable $e) {
    $out['exception'] = get_class($e) . ': ' . $e->getMessage();
}
$out['sent_to'] = $evo->to;
$out['alerts']  = $alerts->keys;
echo "\n" . json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
