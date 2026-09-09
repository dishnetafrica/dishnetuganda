<?php
declare(strict_types=1);
chdir(dirname(__DIR__));

/**
 * starlink_session.php — the Uganda Starlink session.
 *
 *   php tools/starlink_session.php                  what we hold, and its state
 *   docker exec -it ucrm php .../starlink_session.php --import
 *   php tools/starlink_session.php --account ops@dishnetuganda.com --number 000-1
 *   php tools/starlink_session.php --forget
 *
 * There is no Starlink password to give this tool, and that is deliberate: the
 * account is entered in a browser, and only the resulting session cookie comes
 * here. The worst this file can leak is a session, which signing out revokes.
 *
 * The cookie is typed in, never passed as an argument — an argument lives on
 * in shell history and is visible to anyone running ps. It is stored
 * encrypted, and nothing ever prints it back.
 *
 * ─── Getting the cookie ──────────────────────────────────────────────────
 *   1. Sign in to starlink.com in a browser, as the Uganda account.
 *   2. Open developer tools → Network, and click any request to starlink.com.
 *   3. In Request Headers, copy the whole value of the `cookie:` header.
 *   4. Run this with --import and paste it.
 *
 * That cookie belongs to the Uganda account only. Never paste South Sudan's
 * here, or Uganda's there: one account's activity would be attributed to the
 * other, and a session flagged on one would take out both.
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

$args  = array_slice($argv, 1);
$value = function (string $f) use ($args) {
    $i = array_search($f, $args, true);
    return ($i !== false && isset($args[$i + 1])) ? (string)$args[$i + 1] : '';
};
$has = function (string $f) use ($args) { return in_array($f, $args, true); };

// A cookie on a command line is already compromised.
foreach ($args as $a) {
    if (strpos($a, '=') !== false && strpos($a, '--') !== 0 && strlen($a) > 40) {
        echo "\n  Do not pass the cookie as an argument.\n";
        echo "  It stays in your shell history and is visible to anyone running ps.\n";
        echo "  Run --import with no value and paste it when asked.\n";
        echo "  If you have already done this, sign out of starlink.com to revoke it.\n\n";
        exit(1);
    }
}

function show(StarlinkSessionStore $store, array $config): void
{
    $s = $store->status();
    $label = [
        StarlinkSessionStore::STATE_ACTIVE => 'ACTIVE',
        StarlinkSessionStore::STATE_STALE  => 'STALE — refresh is failing',
        StarlinkSessionStore::STATE_DEAD   => 'DEAD — a fresh cookie must be imported',
        StarlinkSessionStore::STATE_ABSENT => 'NONE imported yet',
    ][$s['state']] ?? $s['state'];

    echo "\n  UGANDA STARLINK SESSION\n\n";
    printf("    %-16s %s\n", 'state', $label);
    printf("    %-16s %s\n", 'account', $s['account_email'] !== '' ? $s['account_email']
        : (($config['starlink_account_email'] ?? '') ?: '(not set)'));
    printf("    %-16s %s\n", 'account number', $s['account_number'] ?: '(not set)');
    printf("    %-16s %s\n", 'cookies held', $s['cookie_present']
        ? implode(', ', $s['cookie_names']) . '  (names only)' : 'none');
    printf("    %-16s %s\n", 'imported', $s['imported_at'] !== ''
        ? $s['imported_at'] . ' by ' . $s['imported_by'] : '—');
    printf("    %-16s %s\n", 'last accepted', $s['last_ok_at'] ?: '—');
    printf("    %-16s %s\n", 'failures', (string)$s['failures']);
    if ($s['last_error'] !== '')      printf("    %-16s %s\n", 'last error', $s['last_error']);
    if ($s['throttled_until'] !== '') printf("    %-16s %s\n", 'backing off until', $s['throttled_until']);
    printf("    %-16s %s\n", 'file', $s['file']);
    echo "\n";
}

if ($has('--forget')) {
    $store->save(['cookie_enc' => '', 'state' => StarlinkSessionStore::STATE_ABSENT,
                  'consecutive_failures' => 0, 'last_error' => '', 'throttled_until' => '',
                  'account_email' => (string)$store->load()['account_email'],
                  'account_number' => (string)$store->load()['account_number']]);
    echo "\n  Forgotten. Sign out of starlink.com too if the session should be revoked.\n\n";
    exit(0);
}

if ($value('--account') !== '' || $value('--number') !== '') {
    $rec = $store->load();
    if ($value('--account') !== '') $rec['account_email']  = trim($value('--account'));
    if ($value('--number')  !== '') $rec['account_number'] = trim($value('--number'));
    $store->save($rec);
    echo "\n  Saved.\n";
    show($store, $config);
    exit(0);
}

if ($has('--import')) {
    $stty = @shell_exec('stty -g 2>/dev/null');
    if ($stty === null || trim((string)$stty) === '') {
        echo "\n  This is not an interactive terminal, so typing cannot be hidden.\n";
        echo "  Re-run it with a terminal attached:\n\n";
        echo "    docker exec -it ucrm php " . __FILE__ . " --import\n\n";
        exit(1);
    }

    echo "\n  Paste the cookie header from a browser signed in to the UGANDA\n";
    echo "  Starlink account. It will not be shown.\n\n  cookie: ";
    @shell_exec('stty -echo');
    $cookie = (string)fgets(STDIN);
    @shell_exec('stty ' . trim((string)$stty));
    echo "\n";

    $cookie = trim($cookie, "\r\n ");
    if (stripos($cookie, 'cookie:') === 0) $cookie = trim(substr($cookie, 7));

    $r = $store->importCookie($cookie, get_current_user(),
        $value('--account') ?: (string)($config['starlink_account_email'] ?? ''),
        $value('--number'));
    $cookie = str_repeat("\0", strlen($cookie));   // do not leave it in memory

    if (empty($r['ok'])) { echo "\n  " . $r['error'] . "\n\n"; exit(1); }

    echo "  Imported " . count($r['names']) . " cookie(s): " . implode(', ', $r['names']) . "\n";
    echo "  (names only — the values are encrypted and never printed)\n";
    echo "\n  Now prove Starlink accepts it:\n\n";
    echo "    php tools/starlink_probe.php\n\n";
    exit(0);
}

show($store, $config);
if (!$store->status()['cookie_present']) {
    echo "  To import one:\n\n";
    echo "    docker exec -it ucrm php " . __FILE__ . " --import\n\n";
    echo "  Get it from a browser signed in as the Uganda Starlink account:\n";
    echo "  developer tools → Network → any starlink.com request → Request\n";
    echo "  Headers → copy the whole `cookie:` value.\n\n";
    exit(1);
}
exit(0);
