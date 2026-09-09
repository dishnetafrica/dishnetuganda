<?php
declare(strict_types=1);
chdir(dirname(__DIR__));

/**
 * starlink_probe.php — does the Uganda session actually work?
 *
 *   php tools/starlink_probe.php            verify, then list service lines
 *   php tools/starlink_probe.php --verify   verify only, one request
 *
 * Phase 1 ends here. This reads and prints; it stores no Starlink data, posts
 * nothing to the books, and changes nothing except the session's own
 * bookkeeping. If this works, the account is good and the syncs that follow
 * have something to stand on. If it does not, nothing else was built on sand.
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }

$root = dirname(__DIR__);
require_once $root . '/lib/bootstrap_data.php';
require_once $root . '/lib/PluginConfig.php';
require_once $root . '/lib/StarlinkSessionStore.php';
require_once $root . '/lib/StarlinkPortalConnector.php';

$dataDir = getenv('DN_DATA_DIR') ?: getDataDir($root);
$config  = PluginConfig::load($root, $dataDir);
$store   = new StarlinkSessionStore($root, $dataDir);
$conn    = new StarlinkPortalConnector($store, $config);

echo "\n  " . $conn->describe() . "\n";
echo "  " . str_repeat('─', 64) . "\n";

if (!$conn->isConfigured()) {
    echo "\n  No session imported yet.\n\n";
    echo "    docker exec -it ucrm php tools/starlink_session.php --import\n\n";
    exit(1);
}

// ── 1. Does Starlink still accept us? ────────────────────────────────────
$v = $conn->verify();
printf("  %-22s %s\n", 'session accepted', !empty($v['ok']) ? 'YES' : 'NO');
if (empty($v['ok'])) {
    echo "  " . str_repeat('─', 64) . "\n\n";
    echo "  " . (string)$v['error'] . "\n\n";
    $s = $store->status();
    if ($s['state'] === StarlinkSessionStore::STATE_DEAD) {
        echo "  The session is dead. Sign in again and import a fresh cookie:\n\n";
        echo "    docker exec -it ucrm php tools/starlink_session.php --import\n\n";
    }
    exit(1);
}
printf("  %-22s %s\n", 'identified as', (string)$v['detail']);

if (in_array('--verify', array_slice($argv, 1), true)) {
    echo "  " . str_repeat('─', 64) . "\n\n";
    exit(0);
}

// ── 2. What is on the account? ───────────────────────────────────────────
$r = $conn->get('/api/webagg/v2/accounts/service-lines?limit=100&page=0'
              . '&isConverting=false&onlyActive=false');
if (empty($r['ok'])) {
    echo "  " . str_repeat('─', 64) . "\n\n";
    echo "  The session works, but the service-line call did not: "
       . (string)$r['error'] . "\n";
    echo "  " . (!empty($r['retryable'])
        ? "That is worth one retry — it is a wait, not a wrong answer.\n\n"
        : "That is not a retry; something about the request is wrong.\n\n");
    exit(1);
}

$rows = $r['data']['content']['results'] ?? $r['data']['results'] ?? [];
if (!is_array($rows)) $rows = [];

printf("  %-22s %d\n", 'service lines', count($rows));
echo "  " . str_repeat('─', 64) . "\n";

if ($rows === []) {
    echo "\n  The account answered, with no service lines on it. That is a real\n";
    echo "  answer — worth checking it is the account you meant.\n\n";
    exit(0);
}

printf("\n  %-22s %-18s %-10s %s\n", 'SERVICE LINE', 'KIT SERIAL', 'STATUS', 'NICKNAME');
printf("  %s\n", str_repeat('-', 70));
foreach (array_slice($rows, 0, 50) as $sl) {
    if (!is_array($sl)) continue;
    // The kit serial rides inside the service line rather than arriving from
    // an endpoint of its own — the discovery that makes KitSync a consumer of
    // this call instead of a separate fetch.
    $serial = (string)($sl['userTerminals'][0]['serialNumber']
                    ?? $sl['userTerminalId'] ?? '');
    printf("  %-22s %-18s %-10s %s\n",
        substr((string)($sl['serviceLineNumber'] ?? ''), 0, 22),
        substr($serial, 0, 18) ?: '—',
        substr((string)($sl['active'] ?? '') !== '' ? (!empty($sl['active']) ? 'active' : 'inactive')
            : (string)($sl['status'] ?? '—'), 0, 10),
        substr((string)($sl['nickname'] ?? ''), 0, 24));
}

echo "\n  Nothing was stored. Phase 2 is what starts keeping it.\n\n";
exit(0);
