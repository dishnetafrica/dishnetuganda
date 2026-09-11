<?php
declare(strict_types=1);
chdir(dirname(__DIR__));

/**
 * account_doctor.php — is this customer actually set up?
 *
 *   php tools/account_doctor.php --client 4021
 *   php tools/account_doctor.php --find "Family Shoppers"
 *   php tools/account_doctor.php --client 4021 --refresh
 *
 * Onboarding a customer touches six systems. uCRM gets the client and the
 * service, the plugin gets the application, the warehouse gets the kit, the
 * mail server gets a mailbox, the portal gets a way in. Each of those has a
 * screen. None of them has the answer to "did all of it happen", so the only
 * way to check was to open six and remember the first by the time you
 * reached the last.
 *
 * This walks the chain in the order it happens and says, at each step, what
 * is there and what is not. It changes nothing.
 *
 * A total is never the answer here. "Outstanding 0" is true of a customer
 * who has paid and of one who was never invoiced, and those are opposite
 * situations.
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }

$root = dirname(__DIR__);
require_once $root . '/lib/bootstrap_data.php';
require_once $root . '/lib/StoreInterface.php';
require_once $root . '/lib/JsonStore.php';
require_once $root . '/lib/SqliteStore.php';
require_once $root . '/lib/PluginConfig.php';
require_once $root . '/lib/CustomerAccountService.php';
require_once $root . '/lib/CustomerEmailDispatcher.php';

$args = array_slice($argv, 1);
$val = function (string $f) use ($args): string {
    $i = array_search($f, $args, true);
    return ($i !== false && isset($args[$i + 1])) ? (string)$args[$i + 1] : '';
};
$has = function (string $f) use ($args): bool { return in_array($f, $args, true); };

foreach ($args as $a) {
    if (strpos($a, '--') !== 0) continue;
    if (!in_array($a, ['--client', '--find', '--refresh', '--json'], true)) {
        fwrite(STDERR, "\n  Unknown option: {$a}\n");
        fwrite(STDERR, "  Known options are --client <id>, --find <name>, --refresh, --json.\n\n");
        exit(2);
    }
}

$dataDir = cliDataDir($root);
$store   = SqliteStore::create($dataDir);
$config  = PluginConfig::load($root, $dataDir);

// uCRM if it will answer, caches if it will not. A doctor that cannot run
// without the network is a doctor that cannot run when it is most needed.
// fromUcrm(), never the constructor. The constructor takes a url and a key
// and knows nothing about ucrm.json, so a tool that resolves those itself
// reads whichever config keys its author happened to think of — org_probe
// did exactly that and reported "uCRM is not configured" on an install that
// had been talking to uCRM all day.
$crm = null;
try {
    require_once $root . '/lib/CrmApiClient.php';
    $crm = CrmApiClient::fromUcrm($root, $config);
} catch (\Throwable $e) { /* caches only — this tool still works offline */ }

// The config too: the contact checks compare a customer's phone and email
// against DishNet's own published ones, and read the country this install
// operates in out of its own support number.
$svc = new CustomerAccountService($store, $crm, $dataDir, $store->getPdo(),
                                  CustomerEmailDispatcher::effectiveConfig($config));

// ── Which customer ──────────────────────────────────────────────────────────
$clientId = (int)$val('--client');
$find     = trim($val('--find'));

if ($clientId <= 0 && $find === '') {
    echo "\n  usage: php tools/account_doctor.php --client <uCRM client id>\n";
    echo "         php tools/account_doctor.php --find \"Family Shoppers\"\n\n";
    exit(2);
}

if ($clientId <= 0) {
    $matches = [];
    foreach (($store->load('ucrm_clients_cache.json') ?: []) as $c) {
        $name = trim(((string)($c['firstName'] ?? '')) . ' ' . ((string)($c['lastName'] ?? '')));
        if ($name === '') $name = (string)($c['companyName'] ?? '');
        if ($name !== '' && stripos($name, $find) !== false) {
            $matches[] = ['id' => (int)($c['id'] ?? 0), 'name' => $name];
        }
    }
    if ($matches === []) {
        echo "\n  No customer matching \"{$find}\" in the local client cache.\n";
        echo "  The cache may be stale — try the id from uCRM directly:\n";
        echo "    php tools/account_doctor.php --client <id>\n\n";
        exit(1);
    }
    if (count($matches) > 1) {
        echo "\n  \"{$find}\" matches " . count($matches) . " customers:\n\n";
        foreach ($matches as $m) printf("    %-8d %s\n", $m['id'], $m['name']);
        echo "\n  Run it again with --client <id>.\n\n";
        exit(1);
    }
    $clientId = $matches[0]['id'];
}

$account = $svc->account($clientId, ['refresh' => $has('--refresh')]);
if ($account === null) {
    echo "\n  No client #{$clientId} in uCRM or in the local caches.\n\n";
    exit(1);
}

if ($has('--json')) {
    echo json_encode($account, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
    exit($account['gaps'] === [] ? 0 : 1);
}

// ── The report ──────────────────────────────────────────────────────────────
$cur = function (float $n, string $code): string {
    return ($code !== '' ? $code . ' ' : '') . number_format($n, 0);
};
/**
 * Three marks, not two.
 *
 *   ok   present and right
 *   --   absent, and that is allowed. A customer created straight in uCRM
 *        has no KYC application; a customer who logs in by WhatsApp needs no
 *        mailbox. Marking those GAP made the report contradict its own
 *        summary, which teaches a reader to stop trusting the marks.
 *   GAP  absent and blocking — and every one of these appears in the list at
 *        the bottom, so the two always agree.
 */
$step = function (string $label, $state, string $detail): void {
    $mark = $state === true ? ' ok ' : ($state === null ? ' -- ' : 'GAP ');
    printf("  %s  %-22s %s\n", $mark, $label, $detail);
};

echo "\n";
echo "  ACCOUNT DOCTOR — " . $account['name'] . "  (client #" . $account['client_id'] . ")\n";
echo "  " . str_repeat('─', 72) . "\n\n";

echo "  1) THE CUSTOMER\n";
$step('account ref', $account['account_ref'] !== '', $account['account_ref']);
$step('status', $account['status'] === 'active', $account['status']
      . ($account['is_lead'] ? ' — still a LEAD in uCRM, not a client' : ''));
$step('email', $account['email'] !== '', $account['email'] ?: 'none on file');
$step('phone', $account['phone'] !== '', $account['phone'] ?: 'none on file');
$step('address', $account['address'] !== '', $account['address'] ?: 'none on file');
// Informational: an older client imported without a registration date is
// not broken, and marking it GAP would put a mark in the report with no
// matching line in the summary.
$step('registered', $account['registered'] !== '' ? true : null,
      substr($account['registered'], 0, 10) ?: 'unknown');

echo "\n  2) ONBOARDING\n";
$o = $account['onboarding'];
$step('application', $o['application_id'] !== null ? true : null,
      $o['application_id'] ? "#{$o['application_id']}" . ($o['sold_by'] ? " · sold by {$o['sold_by']}" : '')
                           : 'no KYC application — fine if they were created straight in uCRM');
// Not blocking on its own: the equipment check below is what says whether a
// dish is actually accounted for, and it is the one in the summary.
$step('install job', $o['install_job_id'] !== null ? true : null,
      $o['install_job_id'] ? "job #{$o['install_job_id']} on {$o['installed_on']}"
                            . ($o['installed_by'] ? " by {$o['installed_by']}" : '')
                          : 'no install recorded in the stock movement log');

echo "\n  3) SERVICE\n";
if ($account['services'] === []) {
    $step('service', false, 'none — nothing will ever be invoiced');
} else {
    foreach ($account['services'] as $s) {
        $step($s['status'] === 'active' ? 'active service' : 'service (' . $s['status'] . ')',
              $s['status'] === 'active' ? true : null,
              $s['name'] . ' · ' . $cur($s['price'], $s['currency'])
              . ($s['since'] ? ' · since ' . $s['since'] : ''));
    }
}

echo "\n  4) EQUIPMENT\n";
if ($account['equipment'] === []) {
    $step('assigned', false,
          'none — if a dish was installed, the unit was never marked against '
          . 'this customer and still counts as available stock');
} else {
    foreach ($account['equipment'] as $e) {
        $step($e['status'], in_array($e['status'], ['installed', 'reserved'], true),
              $e['name'] . ' · ' . ($e['serial'] ?: 'no serial')
              . ($e['since'] ? ' · ' . $e['since'] : '')
              . ' · cost ' . number_format($e['purchase_cost'], 0)
              . ($e['purchase_cost'] <= 0 ? ' (NOT RECORDED)' : ''));
    }
}

echo "\n  5) BILLING\n";
$b = $account['billing'];
$step('invoices', $b['invoice_count'] > 0 ? true : false,
      $b['invoice_count'] > 0 ? $b['invoice_count'] . ' issued · ' . $cur($b['invoiced'], $b['currency'])
                              : 'none issued yet');
// Nothing paid is not a fault on a customer invoiced yesterday.
$step('paid', $b['paid'] > 0 ? true : null, $cur($b['paid'], $b['currency'])
      . ' across ' . count($account['payments']) . ' payment(s)');
// Outstanding is not a gap — it is a fact, and zero is good news only when
// something was actually invoiced.
printf("  %s  %-22s %s\n",
       ($b['outstanding'] <= 0 ? ' ok ' : ' -- '),
       'outstanding', $cur($b['outstanding'], $b['currency'])
       . ($b['overdue'] > 0 ? '  (OVERDUE ' . $cur($b['overdue'], $b['currency']) . ')' : ''));
if (count($b['by_currency']) > 1) {
    echo "       billed in more than one currency:\n";
    foreach ($b['by_currency'] as $c => $v) {
        printf("         %-8s invoiced %-14s outstanding %s\n", $c,
               number_format($v['invoiced'], 0), number_format($v['outstanding'], 0));
    }
}
foreach (array_slice($account['invoices'], 0, 5) as $i) {
    printf("       %-12s %-10s %-9s %14s  due %s\n", $i['number'] ?: ('#' . $i['id']),
           $i['issued'], $i['status'], $cur($i['total'], $i['currency']), $i['due'] ?: '—');
}

echo "\n  6) PORTAL\n";
$p = $account['portal'];
// A mailbox is a convenience, not a login. Absent is fine as long as a code
// can reach them some other way, which the next line is what checks.
$step('mailbox', $p['identity_email'] !== '' ? true : null,
      $p['identity_email'] ? $p['identity_email'] . ' · ' . $p['identity_status']
                           : 'no @dishnetuganda.com identity provisioned');
$step('can log in', $p['can_login_by'] !== [],
      $p['can_login_by'] ? 'code by ' . implode(' or ', $p['can_login_by'])
                         : 'NO — a login code needs a phone or an email, and this account has neither');

echo "\n  " . str_repeat('─', 72) . "\n";
if ($account['gaps'] === []) {
    echo "  Nothing missing. This account is ready to operate.\n\n";
    exit(0);
}
echo "  " . count($account['gaps']) . " thing(s) to fix:\n\n";
foreach ($account['gaps'] as $g) echo "    · " . $g . "\n";
echo "\n";
exit(1);
