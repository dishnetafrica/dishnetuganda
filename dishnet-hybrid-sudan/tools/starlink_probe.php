<?php
declare(strict_types=1);
chdir(dirname(__DIR__));

/**
 * starlink_probe.php — does the Uganda session actually work?
 *
 *   php tools/starlink_probe.php            verify, then list service lines
 *   php tools/starlink_probe.php --verify   verify only, one request
 *   php tools/starlink_probe.php --shape    what the response actually looks like
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

// ── 0a. Shape: what does the payload actually contain? ───────────────────
// Guessing field names is what produced a table of em-dashes: the kit serial
// was read from userTerminals[0].serialNumber because that is where the fleet
// plugin found it, and this account's response evidently puts it elsewhere.
// This prints the structure so the mapping is read rather than assumed.
if (in_array('--shape', array_slice($argv, 1), true)) {
    $r = $conn->get('/api/webagg/v2/accounts/service-lines?limit=2&page=0'
                  . '&isConverting=false&onlyActive=false');
    if (empty($r['ok'])) { echo "\n  " . (string)$r['error'] . "\n\n"; exit(1); }

    /** Keys and types, with short scalars shown. Business identifiers, not secrets. */
    $walk = function ($node, string $prefix = '', int $depth = 0) use (&$walk) {
        if ($depth > 4) return;
        foreach ((array)$node as $k => $v) {
            $path = $prefix === '' ? (string)$k : $prefix . '.' . $k;
            if (is_array($v)) {
                // An empty array decodes the same whether it was [] or {}, and
                // printing it as "object" hid the finding that mattered:
                // userTerminals is EMPTY on every line, which is why no serial
                // was found. Say empty when it is empty.
                if ($v === []) { printf("    %-46s %s\n", $path, 'EMPTY'); continue; }

                $isList = array_keys($v) === range(0, count($v) - 1);
                printf("    %-46s %s\n", $path, $isList ? 'list(' . count($v) . ')' : 'object');

                // Only descend into a container. Walking a string yields a
                // phantom child at key 0 — addressLines[0].0 was that.
                $next = $isList ? ($v[0] ?? null) : $v;
                if (is_array($next)) $walk($next, $path . ($isList ? '[0]' : ''), $depth + 1);
                elseif ($isList && $next !== null) {
                    $show = (string)$next;
                    printf("    %-46s %-5s %s\n", $path . '[0]', gettype($next),
                        mb_strlen($show) > 40 ? mb_substr($show, 0, 37) . '...' : $show);
                }
            } elseif (is_bool($v)) {
                printf("    %-46s bool  %s\n", $path, $v ? 'true' : 'false');
            } elseif ($v === null) {
                printf("    %-46s null\n", $path);
            } else {
                $show = (string)$v;
                if (mb_strlen($show) > 40) $show = mb_substr($show, 0, 37) . '...';
                printf("    %-46s %-5s %s\n", $path, gettype($v), $show);
            }
        }
    };

    echo "\n  RESPONSE SHAPE — service-lines\n\n";
    $walk($r['data']);
    echo "\n  Read the kit serial and status field names off this, rather than\n";
    echo "  guessing them a second time.\n\n";
    exit(0);
}

// ── 0. Diagnose: what does Starlink actually say, to each thing we ask? ──
if (in_array('--diagnose', array_slice($argv, 1), true)) {
    $shape = $store->shape();
    printf("  %-22s %d cookies, %d bytes\n", 'session holds',
        count($shape['names']), $shape['total']);
    if (!empty($shape['suspect_truncated'])) {
        echo "  " . str_repeat('!', 64) . "\n";
        echo "  That is ~4096 bytes — where a terminal cuts a pasted line.\n";
        echo "  Every cookie NAME survives truncation; the last VALUE does not.\n";
        echo "  Re-import by piping the cookie in rather than pasting it.\n";
        echo "  " . str_repeat('!', 64) . "\n";
    }
    echo "\n  Raw responses — no refresh, no retry, nothing helping:\n\n";
    printf("  %-6s %-52s %s\n", 'CODE', 'PATH', 'BYTES');
    printf("  %s\n", str_repeat('-', 72));
    foreach ([
        ['GET',  '/api/accounts/v3/accounts/contact'],
        ['GET',  '/api/webagg/v2/accounts/service-lines?limit=1&page=0'],
        ['GET',  '/api/accounts/v1/accounts/service-line-numbers'],
        ['POST', '/api/auth/v1/session/refresh'],
        ['POST', '/api/auth/v1/token/refresh'],
    ] as [$m, $path]) {
        $d = $conn->raw($m, $path);
        printf("  %-6s %-52s %d\n", $d['code'] ?: ($d['error'] !== '' ? 'ERR' : '0'),
            $m . ' ' . substr($path, 0, 48), $d['bytes']);
        if ($d['error'] !== '')   echo "         " . $d['error'] . "\n";
        if ($d['snippet'] !== '') echo "         " . $d['snippet'] . "\n";
    }
    echo "\n  401/403 everywhere means the cookie is not accepted — truncated,\n";
    echo "  expired, or from a different account.\n";
    echo "  200 on contact but 401 elsewhere means the session is real and a\n";
    echo "  second auth layer is refusing — a different problem entirely.\n";
    echo "  404 on a refresh path means that endpoint is not there any more.\n\n";
    exit(0);
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
    echo "  To see what Starlink said to each request, with nothing helping:\n\n";
    echo "    php tools/starlink_probe.php --diagnose\n\n";
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

printf("\n  %-22s %-14s %-9s %-22s %s\n",
    'SERVICE LINE', 'DISH', 'STATE', 'PLAN', 'ADDRESS');
printf("  %s\n", str_repeat('-', 96));
foreach (array_slice($rows, 0, 50) as $sl) {
    if (!is_array($sl)) continue;
    // The kit serial rides inside the service line rather than arriving from
    // an endpoint of its own — the discovery that makes KitSync a consumer of
    // this call instead of a separate fetch.
    // Where the serial lives when there IS one. On this account every
    // service line has an empty userTerminals, because none has a dish
    // attached yet — pendingActivation is true on all ten. An em-dash here is
    // the truth, not a lookup that failed.
    $serial = '';
    foreach ([$sl['userTerminals'][0]['serialNumber'] ?? null,
              $sl['userTerminals'][0]['kitSerialNumber'] ?? null,
              $sl['userTerminal']['serialNumber']    ?? null,
              $sl['userTerminalSerialNumber']        ?? null,
              $sl['kitSerialNumber']                 ?? null] as $cand) {
        if (is_string($cand) && trim($cand) !== '') { $serial = trim($cand); break; }
    }

    // The subscription says in words what `status: 7` says in a code nobody
    // has decoded. Prefer the words.
    $sub    = is_array($sl['subscription'] ?? null) ? $sl['subscription'] : [];
    $state  = '—';
    if (!empty($sub['isSuspended']))            $state = 'suspended';
    elseif (!empty($sub['isPaused']))           $state = 'paused';
    elseif (!empty($sub['pendingActivation']))  $state = 'pending';
    elseif (!empty($sub['active']))             $state = 'active';
    elseif (array_key_exists('active', $sub))   $state = 'inactive';

    printf("  %-22s %-14s %-9s %-22s %s\n",
        substr((string)($sl['serviceLineNumber'] ?? ''), 0, 22),
        $serial !== '' ? substr($serial, 0, 14) : 'no dish yet',
        $state,
        substr((string)($sub['productDescription'] ?? ''), 0, 22),
        substr((string)($sl['serviceAddress']['formattedAddress'] ?? ''), 0, 30));
}

$pending = 0;
foreach ($rows as $sl) {
    if (is_array($sl) && !empty($sl['subscription']['pendingActivation'])) $pending++;
}
if ($pending > 0) {
    echo "\n  {$pending} of these have no dish attached yet (pendingActivation).\n";
    echo "  An empty DISH column there is the account's real state, not a\n";
    echo "  lookup that failed — the serial appears once a terminal is\n";
    echo "  assigned to the line.\n";
}

echo "\n  Nothing was stored. Phase 2 is what starts keeping it.\n\n";
exit(0);
