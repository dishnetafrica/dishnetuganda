<?php
declare(strict_types=1);
/**
 * test_starlink_accounts.php — the fleet seen from Starlink's side.
 *
 * The Fleet screen groups by customer, which is the question the business
 * asks. It is not the question that gets a broken fleet working again: a dead
 * cookie, a service line nobody bound, an assignment carrying no account —
 * none of those have a customer row to appear on, so the customer view cannot
 * show them at all. The South Sudan installation runs a Starlink Accounts tab
 * beside its Clients tab for exactly this reason.
 *
 * What these insist on:
 *
 *   · nothing is recomputed — the figures are regrouped from the fleet the
 *     other screen renders, so the two can never disagree about "GB this
 *     cycle"
 *   · the four things that stop a fleet reporting stay four separate things,
 *     because each is a different job for a different person
 *   · an assignment with no account, and a line with no account, are SHOWN;
 *     dropping them is how a fleet quietly stops being complete
 *   · merely looking at the screen does not change which account the next
 *     collection runs against
 */
require_once dirname(__DIR__) . '/lib/StarlinkSessionStore.php';
require_once dirname(__DIR__) . '/lib/StarlinkAccounts.php';

$pass = 0; $fail = 0;
function t(string $n, $got, $want) { global $pass, $fail;
    if ($got === $want) { $pass++; printf("  ok   %s\n", $n); }
    else { $fail++; printf("  FAIL %s\n       got  %s\n       want %s\n", $n, var_export($got, true), var_export($want, true)); } }
function is_(bool $c, string $m, string $d = ''): void { global $pass, $fail;
    if ($c) { $pass++; echo "  ok   $m\n"; } else { $fail++; echo "  FAIL $m" . ($d ? "\n       $d" : '') . "\n"; } }

const AA = 'ACC-DF-15744579-40001-43';
const AB = 'ACC-DF-15973474-59163-60';

/** One row in the shape StarlinkFleet::build() emits. */
function row(string $kit, string $acct, array $o = []): array
{
    return [
        'client_id'    => $o['client_id']   ?? 7,
        'client_name'  => $o['client_name'] ?? 'A Customer',
        'kit_serial'   => $kit,
        'service_line' => $o['service_line'] ?? 'SL-DF-1-1-1',
        'account'      => $acct,
        'plan'         => $o['plan'] ?? '',
        'live'         => ['status' => $o['status'] ?? 'active', 'label' => $o['label'] ?? 'Active'],
        'usage'        => ['used_gb' => array_key_exists('used_gb', $o) ? $o['used_gb'] : 12.5,
                           'cycle'   => $o['cycle']  ?? 'Sep 2026',
                           'reason'  => $o['reason'] ?? ''],
        'label'        => $o['label'] ?? 'Active',
    ];
}

echo "\nIt regroups the fleet by account\n";
$g = StarlinkAccounts::group([
    'rows' => [row('KIT1', AA), row('KIT2', AA), row('KIT3', AB)],
], [AA => ['state' => 'ok', 'last_ok' => '2026-09-13 08:00']]);
t('two accounts came out of three kits', count($g['accounts']), 2);
$byAcct = [];
foreach ($g['accounts'] as $a) $byAcct[$a['account']] = $a;
t('the first has both of its kits',   $byAcct[AA]['total_kits'], 2);
t('the second has its one',           $byAcct[AB]['total_kits'], 1);
t('and their usage is summed, not re-fetched', $byAcct[AA]['used_gb'], 25.0);
t('kits are listed in a stable order',
  array_column($byAcct[AA]['kits'], 'kit_serial'), ['KIT1', 'KIT2']);

echo "\nThe account spelling never decides whether two kits are together\n";
$g = StarlinkAccounts::group(['rows' => [row('KIT1', AA), row('KIT2', ' ' . strtolower(AA) . ' ')]]);
t('case and stray whitespace group as one account', count($g['accounts']), 1);
t('with both kits under it', $g['accounts'][0]['total_kits'], 2);

echo "\nAn assignment with no Starlink account is shown, not dropped\n";
// The usage endpoint takes the account in its path. These can never be
// collected however fresh the cookie is — which is worth seeing.
$g = StarlinkAccounts::group(['rows' => [row('KIT1', AA), row('KITX', '')]]);
t('it does not become an account of its own', count($g['accounts']), 1);
t('it is kept aside',                         count($g['orphans']), 1);
t('with its kit intact',                      $g['orphans'][0]['kit_serial'], 'KITX');
t('and it is counted',                        $g['summary']['no_account'], 1);

echo "\nA line nobody bound is shown under its account\n";
$g = StarlinkAccounts::group([
    'rows'      => [row('KIT1', AA)],
    'unclaimed' => [['service_line' => 'SL-DF-9-9-9', 'status' => 'active',
                     'label' => 'Active', 'plan' => '', 'account' => AA]],
], [AA => ['state' => 'ok', 'last_ok' => '']]);
t('the account carries it',        count($g['accounts'][0]['unclaimed']), 1);
t('it is counted separately from kits', $g['summary']['unbound_lines'], 1);
t('and it does not inflate the kit count', $g['summary']['kits'], 1);

echo "\nAnd a line whose account we do not even know gets its own heading\n";
$g = StarlinkAccounts::group(['unclaimed' => [
    ['service_line' => 'SL-DF-9-9-9', 'status' => '', 'label' => '', 'plan' => '', 'account' => '']]]);
t('it still appears', count($g['accounts']), 1);
is_(strpos($g['accounts'][0]['account'], 'no account') !== false,
    'under a heading that says why it has nowhere else to go');

echo "\nThe four needs stay four separate things\n";
// Merging them into one "problems" number would make the screen useless: the
// person who pastes a cookie and the person who records an install are not
// the same person, and neither is waiting on the other.
$g = StarlinkAccounts::group([
    'rows'      => [row('KIT1', AA, ['used_gb' => null, 'reason' => 'nothing collected'])],
    'unclaimed' => [['service_line' => 'SL-1', 'status' => '', 'label' => '', 'plan' => '', 'account' => AA]],
]);
$a = $g['accounts'][0];
is_(in_array('no_session',    $a['needs'], true), 'no cookie is one of them');
is_(in_array('unbound_lines', $a['needs'], true), 'an unbound line is another');
is_(in_array('no_usage',      $a['needs'], true), 'active but nothing collected is a third');
t('three distinct needs, not one lumped count', count($a['needs']), 3);

echo "\nA customer who genuinely used nothing is not a collection failure\n";
// 0.0 GB is an answer. null is silence. Treating them alike would send
// somebody chasing a fault that is not there.
$g = StarlinkAccounts::group(['rows' => [row('KIT1', AA, ['used_gb' => 0.0])]],
                             [AA => ['state' => 'ok', 'last_ok' => '']]);
t('zero counts as collected', $g['accounts'][0]['collected_kits'], 1);
t('so nothing is flagged',    $g['accounts'][0]['needs'], []);

$g = StarlinkAccounts::group(['rows' => [row('KIT1', AA,
        ['used_gb' => null, 'reason' => 'the session for this account has expired'])]],
                             [AA => ['state' => 'expired', 'last_ok' => '']]);
t('silence does not',  $g['accounts'][0]['collected_kits'], 0);
t('and it is flagged', $g['accounts'][0]['needs'], ['no_usage']);
// Without the reason the screen can only say "no data", which tells whoever
// is looking nothing about what to do next.
t('with the reason carried through', $g['accounts'][0]['kits'][0]['why_no_usage'],
  'the session for this account has expired');

echo "\nA paused kit is not counted active\n";
$g = StarlinkAccounts::group(['rows' => [
    row('KIT1', AA, ['status' => 'active']), row('KIT2', AA, ['status' => 'paused'])]],
    [AA => ['state' => 'ok', 'last_ok' => '']]);
t('one of the two is active', $g['accounts'][0]['active_kits'], 1);
t('both are still listed',    $g['accounts'][0]['total_kits'], 2);

echo "\nAn account we hold a cookie for but have nothing in still appears\n";
// This is the normal state before the first install, and seeing the row is
// how somebody knows the paste took at all.
$g = StarlinkAccounts::group([], [AB => ['state' => 'ok', 'last_ok' => '2026-09-13']]);
t('the empty account is a row',   count($g['accounts']), 1);
t('marked as held',               $g['accounts'][0]['session_held'], true);
t('carrying its state',           $g['accounts'][0]['session_state'], 'ok');
t('and it is not flagged as needing a cookie', $g['accounts'][0]['needs'], []);

echo "\nThe account needing most attention is first\n";
$g = StarlinkAccounts::group([
    'rows'      => [row('K1', AA), row('K2', AA), row('K3', AB, ['used_gb' => null])],
    'unclaimed' => [['service_line' => 'SL-1', 'status' => '', 'label' => '', 'plan' => '', 'account' => AB]],
], [AA => ['state' => 'ok', 'last_ok' => '']]);
t('the troubled one leads, not the busiest', $g['accounts'][0]['account'], AB);

echo "\nThe summary is the same arithmetic, not a second opinion\n";
t('accounts',      $g['summary']['accounts'], 2);
t('cookies held',  $g['summary']['sessions_held'], 1);
t('bound kits',    $g['summary']['kits'], 3);
t('unbound lines', $g['summary']['unbound_lines'], 1);
t('accounts with no cookie', $g['summary']['accounts_no_session'], 1);

echo "\nIt never goes and asks Starlink anything\n";
// Two screens computing "GB this cycle" from two sources is how they end up
// disagreeing, and then nobody believes either.
$lib = (string)file_get_contents(dirname(__DIR__) . '/lib/StarlinkAccounts.php');
is_(strpos($lib, 'StarlinkPortalConnector') === false, 'no connector is constructed');
is_(strpos($lib, 'new KitUsage')            === false, 'usage is not recomputed');
is_(strpos($lib, 'curl_')                   === false, 'and nothing is fetched');

echo "\nLooking at the screen does not move the selected account\n";
$base = sys_get_temp_dir() . '/dn_sl_acct_' . bin2hex(random_bytes(4));
@mkdir($base . '/plugins/dishnet-hybrid-sudan', 0777, true);
@mkdir($base . '/data', 0777, true);
putenv('DN_PLUGIN_ROOT=' . $base . '/plugins/dishnet-hybrid-sudan');
$store = new StarlinkSessionStore($base . '/plugins/dishnet-hybrid-sudan', $base . '/data');
$store->importCookie('Starlink.Com.Sso=aaa; starlink.com.account_number=' . AA, 'tester');
$store->importCookie('Starlink.Com.Sso=bbb; starlink.com.account_number=' . AB, 'tester');
$store->useAccount(AA);
$snap = StarlinkAccounts::sessionSnapshot($store);
t('every held account is in the snapshot', count($snap), 2);
is_(isset($snap[AA]) && isset($snap[AB]), 'both by their own account number');
t('the operator keeps the account they chose', $store->active(), AA);

echo "\nThe page itself\n";
$page = (string)file_get_contents(dirname(__DIR__) . '/tabs/admin/starlink_accounts.php');
$pub  = (string)file_get_contents(dirname(__DIR__) . '/public.php');
$nav  = (string)file_get_contents(dirname(__DIR__) . '/includes/navigation.php');
is_(strpos($page, "\$retailer['is_admin']") !== false, 'guards itself as admin-only');
is_(strpos($pub, "'starlink_accounts'    => '*admin'") !== false,
    'and the permission map agrees');
is_(strpos($pub, "'starlink_accounts'=> 'tabs/admin/starlink_accounts.php'") !== false,
    'the route exists, so the guard is reachable');
is_(strpos($pub, "'id'=>'starlink_accounts'") !== false, 'it is listed for admins');
// The sidebar is hand-written links, not generated from the tab array. A tab
// registered and not linked is a tab only its author can find.
is_(strpos($nav, 'tab=starlink_accounts') !== false, 'and a person can reach it from the sidebar');

echo "\nIt is read-only, like the Fleet screen beside it\n";
is_(strpos($page, '$_POST') === false, 'it takes no input');
is_(strpos($page, '->save(') === false && strpos($page, '->assign(') === false,
    'and writes no assignment — that is what tools/assign_kit.php is for');
is_(strpos($page, 'htmlspecialchars') !== false, 'output is escaped');
is_(strpos($page, 'cookie()') === false, 'and it never asks for a cookie in the clear');

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
