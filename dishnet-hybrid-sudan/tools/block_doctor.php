<?php
declare(strict_types=1);
chdir(dirname(__DIR__));

/**
 * block_doctor.php — can we actually block a customer's WiFi?
 *
 *   php tools/block_doctor.php
 *   php tools/block_doctor.php --client 4021
 *
 * Blocking a non-paying customer's WiFi is a chain, and every link of it is
 * somewhere else:
 *
 *   uCRM says suspended
 *     → which dish does this customer have?        (stock, or a service name)
 *       → which router is behind that dish?        (dishnet-data-report)
 *         → speak gRPC to the router               (dishnet-data-report)
 *           → pause the devices, change the SSID
 *
 * Hybrid does not speak gRPC and never has. Every router call goes out over
 * HTTP to dishnet-data-report's public.php, which is not a reporting plugin
 * at all despite its name — it is the gateway to the dish. Without it
 * installed, nothing here can block anybody, and the only evidence of that
 * was a webhook log line nobody reads.
 *
 * This walks the chain and stops at the first broken link. It blocks
 * nobody: no router is contacted except to ask whether it can be found.
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }

$root = dirname(__DIR__);
$GLOBALS['_PLUGIN_ROOT'] = $root;
require_once $root . '/lib/bootstrap_data.php';
require_once $root . '/lib/StoreInterface.php';
require_once $root . '/lib/JsonStore.php';
require_once $root . '/lib/SqliteStore.php';
require_once $root . '/lib/PluginConfig.php';
require_once $root . '/lib/SiblingPlugin.php';

$args = array_slice($argv, 1);
foreach ($args as $a) {
    if (strpos($a, '--') !== 0) continue;
    if (!in_array($a, ['--client'], true)) {
        fwrite(STDERR, "\n  Unknown option: {$a}\n  Known: --client <uCRM client id>\n\n");
        exit(2);
    }
}
$i = array_search('--client', $args, true);
$clientId = ($i !== false && isset($args[$i + 1])) ? (int)$args[$i + 1] : 0;

$dataDir = cliDataDir($root);
$store   = SqliteStore::create($dataDir);
$pdo     = $store->getPdo();
$config  = PluginConfig::load($root, $dataDir);

$fail = 0; $warn = 0;
/**
 * Three marks. ok is fine, FAIL breaks the chain and is counted at the call
 * site, and -- is something that is absent but allowed to be — the shared
 * secret before the first block, a dish nobody has reserved yet.
 */
$step = function (string $label, $state, string $detail): void {
    $mark = $state === true ? ' ok ' : ($state === null ? ' -- ' : 'FAIL');
    // Long labels wrap onto their own line rather than shunting the detail
    // out of alignment — a report that loses its columns stops being read.
    if (strlen($label) > 24) {
        printf("  %s  %s\n%s%s\n", $mark, $label, str_repeat(' ', 32), $detail);
        return;
    }
    printf("  %s  %-24s %s\n", $mark, $label, $detail);
};

echo "\n  WIFI BLOCK DOCTOR — " . gmdate('Y-m-d H:i') . " UTC\n";
echo "  " . str_repeat('─', 72) . "\n\n";

// ── 1. The gateway ──────────────────────────────────────────────────────────
echo "  1) THE ROUTER GATEWAY\n";
$drInstalled = SiblingPlugin::installed('dishnet-data-report');
if (!$drInstalled) {
    $step('dishnet-data-report', false, 'NOT INSTALLED');
    echo "\n";
    echo "      This plugin does not speak gRPC and never has. Every router call —\n";
    echo "      pause a device, change an SSID — goes out over HTTP to that plugin.\n";
    echo "      Until it is installed, no customer can be blocked by any route,\n";
    echo "      and the webhook that tries will log a failure nobody reads.\n\n";
    $fail++;
} else {
    $step('dishnet-data-report', true, 'installed');
}

// The URL Hybrid would call. Resolved the same way the bridge resolves it,
// so a wrong ucrmPublicUrl shows up here rather than at 2am on a suspension.
$override = trim((string)($config['data_report_plugin_url'] ?? ''));
$ucrmFile = null;
foreach ([$dataDir . '/../ucrm.json', $dataDir . '/ucrm.json', $root . '/ucrm.json'] as $p) {
    if (file_exists($p)) { $ucrmFile = $p; break; }
}
$ucrm = $ucrmFile ? (json_decode((string)@file_get_contents($ucrmFile), true) ?: []) : [];
$base = (string)($ucrm['ucrmPublicUrl'] ?? '');
if ($override !== '') {
    $url = rtrim($override, '/');
    $step('gateway URL', true, $url . '   (from data_report_plugin_url)');
} elseif ($base === '') {
    $url = '';
    $step('gateway URL', false, 'cannot be resolved — ucrm.json has no ucrmPublicUrl');
    $fail++;
} else {
    $u = preg_replace('#/api/v\d+\.\d+/?$#', '', $base);
    $u = rtrim($u, '/');
    if (substr($u, -4) === '/crm') $u = substr($u, 0, -4);
    $url = rtrim($u, '/') . '/crm/_plugins/dishnet-data-report/public.php';

    // Resolved exactly as the bridge resolves it, through the same override.
    // A doctor that works it out its own way eventually reports a URL nothing
    // actually calls, which is worse than not checking.
    require_once $root . '/lib/crm_url.php';
    $raw = $url;
    $url = dn_with_override($url, $config);
    $viaOverride = ($url !== $raw);

    // uCRM writes the address it was CONFIGURED with, and behind a reverse
    // proxy that is the port the proxy forwards TO — not one a browser reaches.
    $odd = (bool)preg_match('#:(8443|8080|8000|9443)(/|$)#', $url);
    $step('gateway URL', $odd ? false : true,
          $url . ($viaOverride ? '   (via crm_public_url)' : ''));
    if ($odd) {
        $fail++;
        echo "\n";
        echo "      That port is what the proxy forwards to, not one anything can\n";
        echo "      reach from outside. Point the override at the address a browser\n";
        echo "      uses and every generated link follows, this one included:\n\n";
        echo "        php tools/crm_url_check.php --set https://crm.dishnetuganda.com\n\n";
    }
}

// ── 2. The shared secret ────────────────────────────────────────────────────
echo "\n  2) AUTHENTICATION BETWEEN THE PLUGINS\n";
$secretFile = dirname($root) . '/_dishnet_shared/internal_auth.json';
if (is_file($secretFile)) {
    $sec = json_decode((string)@file_get_contents($secretFile), true) ?: [];
    $has = trim((string)($sec['secret'] ?? $sec['token'] ?? '')) !== '';
    $step('shared secret', $has, $has ? $secretFile : 'file exists but holds no secret');
    if (!$has) $fail++;
} else {
    // Written on first use by the bridge, so absent is not yet a failure.
    $step('shared secret', null, 'not created yet — written on the first block attempt');
    $warn++;
}

// ── 3. Which dish belongs to which customer ─────────────────────────────────
echo "\n  3) CUSTOMER → DISH\n";
$inStock = (int)$pdo->query("SELECT COUNT(*) FROM stock_units")->fetchColumn();
$assigned = (int)$pdo->query("SELECT COUNT(*) FROM stock_units
    WHERE crm_client_id IS NOT NULL AND status IN ('installed','reserved')")->fetchColumn();
$step('units in stock', $inStock > 0 ? true : false,
      $inStock . ' unit(s) recorded' . ($inStock === 0
        ? ' — receive your kits first: tools/, Stock → Receive' : ''));
if ($inStock === 0) $fail++;
$step('assigned to a customer', $assigned > 0 ? true : null,
      $assigned . ' unit(s) installed or reserved against a client');

$slKits = SiblingPlugin::path('dishnet-starlink-finance', 'sl_kits.json');
$step('sl_kits.json', $slKits !== null ? true : null,
      $slKits !== null ? $slKits
        : 'absent — stock is the register now, so this is not needed');

// ── 4. Can we still talk to Starlink at all ─────────────────────────────────
//
// Everything downstream is built from Starlink API calls, and those need a
// live session cookie. When a session dies, discovery still runs, still
// succeeds, and writes an EMPTY map — so "0 routers mapped" and "no Starlink
// account has a working login" look identical from the map alone. They are
// completely different problems.
echo "\n  4) THE STARLINK SESSION\n";
$accts = SiblingPlugin::readJson('dishnet-data-report', 'dr_accounts.json');
$alive = 0; $dead = 0; $needReimport = [];
if ($accts === null) {
    $step('Starlink accounts', false, 'dr_accounts.json unreadable — no account is configured');
    $fail++;
} elseif ($accts === []) {
    $step('Starlink accounts', false, 'none configured — add one in the Data Report session manager');
    $fail++;
} else {
    foreach ($accts as $num => $a) {
        if (!is_array($a)) continue;
        if (!empty($a['session_alive'])) { $alive++; continue; }
        $dead++;
        if (!empty($a['needs_manual_reimport'])) $needReimport[] = (string)$num;
    }
    $step('accounts configured', true, count($accts) . ' account(s)');
    $step('sessions alive', $alive > 0, $alive . ' alive, ' . $dead . ' dead');
    if ($alive === 0) $fail++;
    // An account with no cookie is not broken, it is un-onboarded — its
    // service lines are simply invisible until somebody imports one.
    if ($dead > 0 && $alive > 0) {
        echo "         " . $dead . " account(s) have no working cookie. Their dishes cannot be\n";
        echo "         seen or blocked until one is imported for them.\n";
    }
    if ($needReimport !== []) {
        $step('need a fresh login', false, implode(', ', $needReimport));
        echo "\n";
        echo "      Their cookie has failed enough times that the plugin gave up on it.\n";
        echo "      Paste a new session cookie in the Data Report session manager —\n";
        echo "      nothing downstream works until that account can call Starlink.\n\n";
    }
}

// ── 5. Which router is behind the dish ──────────────────────────────────────
echo "\n  5) DISH → ROUTER\n";
$map     = SiblingPlugin::readJson('dishnet-data-report', 'wifi_router_map.json');
$mapPath = SiblingPlugin::path('dishnet-data-report', 'wifi_router_map.json');
if ($map === null) {
    $step('wifi_router_map.json', false,
          'not written yet — the discovery cron has not completed a run');
    echo "\n";
    echo "      It runs hourly, and also self-triggers when an admin opens any\n";
    echo "      Data Report page. Opening one is the quickest way to find out.\n\n";
    $fail++;
} elseif (count($map) === 0) {
    // The distinction that matters. The file is there, so discovery ran and
    // completed; it simply came back with nothing. WHY it came back with
    // nothing is a different question for each of these, and the number on
    // its own answers none of them.
    $svc  = SiblingPlugin::readJson('dishnet-data-report', 'sl_svc_cache.json') ?? [];
    $nSvc = count($svc);

    if ($alive === 0 && $accts !== null && $accts !== []) {
        $step('wifi_router_map.json', false, 'EMPTY — and no Starlink session is alive');
        $fail++;
        echo "\n";
        echo "      Discovery cannot list routers it cannot ask about, and it writes\n";
        echo "      an empty map rather than failing loudly. Import a cookie first.\n\n";
    } elseif ($nSvc > 0) {
        // Service lines exist, no router has ever answered. On a new
        // operation that is not a fault at all — the dishes are on order.
        // Marked informational, because there is nothing here to fix.
        $step('wifi_router_map.json', null,
              'empty — ' . $nSvc . ' service line(s) exist, no router online yet');
        echo "\n";
        echo "      Nothing is broken. A router appears here once its dish is shipped,\n";
        echo "      powered on and reachable. Until hardware is on the ground there is\n";
        echo "      nothing to block, and that is the whole of it.\n\n";
    } else {
        $step('wifi_router_map.json', false, 'EMPTY — and no service lines either');
        $fail++;
        echo "\n";
        echo "      Starlink answered and listed nothing. Either this account has no\n";
        echo "      active service, or its dishes are on customer-supplied routers,\n";
        echo "      which cannot be blocked this way at all.\n\n";
    }
} else {
    $step('wifi_router_map.json', true, count($map) . ' router(s) mapped');
    $age = time() - (int)@filemtime((string)$mapPath);
    // A map from last month blocks whoever used to own that dish.
    $step('freshness', $age < 172800, sprintf('%.1f days old', $age / 86400));
    if ($age >= 172800) $fail++;
}

// ── 5. Who must never be blocked ────────────────────────────────────────────
echo "\n  6) THE VIP GUARD\n";
$vipTag  = (int)($config['starlink_block_vip_tag_id'] ?? 84);
$vipList = trim((string)($config['starlink_block_vip_clients'] ?? ''));
$step('uCRM tag', true, '#' . $vipTag . ' ('
      . (string)($config['starlink_block_vip_tag_name'] ?? 'NO_AUTO_BLOCK') . ')');
// Tag 84 is the production South Sudan CRM's id. On a different CRM it is
// some other tag, or nothing — and a guard pointing at the wrong tag is a
// guard that is not there.
$step('tag belongs to this CRM', null,
      'confirm #' . $vipTag . ' really is NO_AUTO_BLOCK here, not in the Sudan CRM');
$step('explicit never-block list', $vipList !== '' ? true : null,
      $vipList !== '' ? $vipList : '(empty)');

// ── 6. One customer, if asked ───────────────────────────────────────────────
if ($clientId > 0) {
    echo "\n  7) CLIENT #{$clientId}\n";
    $st = $pdo->prepare("SELECT serial_number, status FROM stock_units
                         WHERE crm_client_id = ? AND status IN ('installed','reserved')");
    $st->execute([$clientId]);
    $units = $st->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    $step('dish on record', $units !== [],
          $units === [] ? 'none — nothing to block'
            : implode(', ', array_map(fn($u) => $u['serial_number'] . ' (' . $u['status'] . ')', $units)));
    if ($units === []) $fail++;

    foreach ($units as $u) {
        $serial = strtoupper(trim((string)$u['serial_number']));
        $hit = null;
        foreach ((array)($map ?? []) as $rid => $info) {
            $ks = strtoupper(trim((string)($info['kit_serial'] ?? $info['terminal_id'] ?? '')));
            if ($ks !== '' && ($ks === $serial || strpos($ks, $serial) !== false)) { $hit = $rid; break; }
        }
        $step('→ router', $hit !== null,
              $hit !== null ? $serial . ' → ' . $hit
                : $serial . ' is in no router map entry — it cannot be blocked');
        if ($hit === null) $fail++;
    }
}

echo "\n  " . str_repeat('─', 72) . "\n";
if ($fail === 0) {
    echo "  The chain is complete. A suspension would reach the router.\n\n";
    exit(0);
}
echo "  {$fail} broken link(s). A suspension today would NOT block anybody —\n";
echo "  it would log a failure and the customer would stay online.\n\n";
exit(1);
