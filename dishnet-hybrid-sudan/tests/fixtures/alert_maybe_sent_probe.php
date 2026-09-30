<?php
/**
 * alert_maybe_sent_probe.php — TEST ONLY (5.18.54, docs/46 row 44). Two alerts with the same key, one after the other,
 * through the real AlertService of the plugin tree named, against a fake Evolution whose first answer is the failure
 * named and whose second is a success. Prints one JSON line, the last: both answers, and how many sends reached Evolution.
 *
 *   php alert_maybe_sent_probe.php <pluginRoot> <uganda|south-sudan> <first failure's error text>
 *
 * Its own data directory, removed on exit; no vault decides the country (DN_VAULT_FILE names a file that is not there).
 */
[$_, $root, $tenant, $failText] = array_pad($argv, 4, '');
$dd = sys_get_temp_dir() . '/alert-maybe-' . getmypid() . '-' . bin2hex(random_bytes(3));
mkdir($dd, 0700, true);
putenv('DN_VAULT_FILE=' . $dd . '/no-vault.json');
register_shutdown_function(function () use ($dd) { exec('rm -rf ' . escapeshellarg($dd)); });

require_once $root . '/lib/StoreInterface.php';
require_once $root . '/lib/JsonStore.php';
require_once $root . '/lib/SqliteStore.php';
require_once $root . '/lib/AlertService.php';

$store = SqliteStore::create($dd);
$cfg   = ['alert_whatsapp' => '256700000777'] + ($tenant === 'uganda' ? ['tenant_profile' => 'uganda'] : []);
$evo   = new class($failText) {
    public int $calls = 0;
    private string $fail;
    public function __construct(string $fail) { $this->fail = $fail; }
    public function sendText(string $channel, string $to, string $text, string $class = ''): array
    {
        $this->calls++;
        return $this->calls === 1 ? ['ok' => false, 'error' => $this->fail] : ['ok' => true, 'http' => 201];
    }
};
$out = ['tenant' => $tenant];
try {
    $alerts = new AlertService($store, $cfg, $evo);
    $out['first']  = $alerts->notify('test:maybe:1', 'FAKE alert: something needs a person', 240);
    $out['second'] = $alerts->notify('test:maybe:1', 'FAKE alert: something needs a person', 240);
} catch (\Throwable $e) {
    $out['exception'] = get_class($e) . ': ' . $e->getMessage();
}
$out['calls'] = $evo->calls;
echo "\n" . json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
