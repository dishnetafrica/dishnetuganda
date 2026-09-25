<?php
declare(strict_types=1);
chdir(dirname(__DIR__));

/**
 * customer_jwt_key.php — the customer-portal signing keys (Phase 2 of the
 * customer-login audit, plan §E.1–E.2).
 *
 *   php tools/customer_jwt_key.php            key ids, which is active, since when
 *   php tools/customer_jwt_key.php --ensure   provision k1 if no key exists yet (the plugin
 *                                             also does this on its first request)
 *   php tools/customer_jwt_key.php --rotate   add a key and sign new tokens with it; the
 *                                             previous keys keep verifying, nobody is signed out
 *   php tools/customer_jwt_key.php --prune    drop retired keys whose tokens have all expired
 *
 * It never prints a secret. The keys live in the store copy of kyc_config.json
 * and in the vault (so a re-install restores them instead of signing every
 * customer out).
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }

$root = dirname(__DIR__);
require_once $root . '/lib/error_handler.php';
require_once $root . '/lib/bootstrap_data.php';
require_once $root . '/lib/StoreInterface.php';
require_once $root . '/lib/JsonStore.php';
require_once $root . '/lib/SqliteStore.php';
require_once $root . '/lib/ConfigVault.php';
require_once $root . '/lib/CustomerJwtKeys.php';

$args    = $argv ?? [];
$dataDir = cliDataDir($root);
$store   = SqliteStore::create($dataDir);
$cfg     = $store->load('kyc_config.json');
if (!is_array($cfg)) $cfg = [];

$show = function (array $cfg): void {
    $rows = CustomerJwtKeys::describe($cfg);
    if ($rows === []) { echo "  customer JWT key: NOT PROVISIONED (run --ensure, or load any customer page once)\n"; return; }
    foreach ($rows as $r) printf("  %-4s %-8s since %s\n", $r['kid'], $r['active'] ? 'ACTIVE' : 'retired', $r['since']);
    printf("  token lifetime %d days (app_jwt_ttl_days)\n", (int)(CustomerJwtKeys::ttlSeconds($cfg) / 86400));
};

if (in_array('--ensure', $args, true)) {
    $ok = CustomerJwtKeys::ensure($store, $cfg);
    echo $ok ? "  customer JWT key: provisioned\n" : "  could not provision a key (store unwritable?)\n";
    $show($cfg); exit($ok ? 0 : 1);
}
if (in_array('--rotate', $args, true)) {
    $new = CustomerJwtKeys::rotate($store, $cfg);
    if ($new === '') { fwrite(STDERR, "  rotation failed\n"); exit(1); }
    echo "  new active key: {$new}. Tokens signed with earlier keys stay valid until they expire; run --prune after "
       . (int)(CustomerJwtKeys::ttlSeconds($cfg) / 86400) . " days.\n";
    $show($cfg); exit(0);
}
if (in_array('--prune', $args, true)) {
    $removed = CustomerJwtKeys::prune($store, $cfg);
    echo $removed === [] ? "  nothing to prune (no retired key whose tokens have all expired)\n" : '  removed: ' . implode(', ', $removed) . "\n";
    $show($cfg); exit(0);
}
$show($cfg);
exit(0);
