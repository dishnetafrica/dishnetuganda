<?php
/**
 * evo_retry_probe.php — TEST ONLY (5.18.54, docs/46 row 31). One call through the EvolutionApiService of the plugin
 * tree named, against the Evolution URL named, with a 1-second timeout. Prints JSON: whether it succeeded, its error,
 * whether that error says the message may have been sent, the exception if one escaped, and how long it took.
 * Nothing leaves the machine: the URL is a local fake.
 *
 *   php evo_retry_probe.php <pluginRoot> <uganda|south-sudan> <post|get> <evolutionUrl> <dataDir>
 */
[$_, $root, $tenant, $op, $url, $dd] = array_pad($argv, 6, '');
putenv('DN_VAULT_FILE=' . $dd . '/no-vault.json');   // no configuration vault may decide the country here
require_once $root . '/lib/EvolutionApiService.php';

$cfg = ['evo_api_url' => $url, 'evo_api_key' => 'FAKE-KEY', 'evo_instance_support' => 'fake_inst', '_data_dir' => $dd];
if ($tenant === 'uganda') $cfg['tenant_profile'] = 'uganda';

$evo = new EvolutionApiService($cfg, 1);
$t0 = microtime(true);
$out = [];
try {
    $r = $op === 'get'
        ? $evo->fetchInstances()
        : $evo->sendText('support', '256700000001', 'FAKE probe message', ContactOptOut::CLASS_TRANSACTIONAL);
    $out = ['ok' => !empty($r['ok']), 'error' => (string)($r['error'] ?? ''),
            'maybe' => method_exists('EvolutionApiService', 'mayHaveBeenSent') ? EvolutionApiService::mayHaveBeenSent($r) : null];
} catch (\Throwable $e) {
    $out = ['exception' => get_class($e) . ': ' . $e->getMessage()];
}
$out['elapsed'] = round(microtime(true) - $t0, 2);
echo json_encode($out, JSON_UNESCAPED_UNICODE);
