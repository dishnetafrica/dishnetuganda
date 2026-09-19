<?php
declare(strict_types=1);
/**
 * test_dpo_endpoints.php — the four doors, and what they refuse to believe.
 *
 * The security of this integration is not in the service alone; it is in what
 * the endpoints decline to read. DPO's push is unsigned and its URL is public,
 * so the only thing standing between a stranger and a cleared invoice is that
 * our handler does not read <Result> out of the body. That is a property of
 * the source, so it is asserted against the source.
 */
require_once dirname(__DIR__) . '/lib/DpoBootstrap.php';

$pass = 0; $fail = 0;
function t(string $n, $got, $want) { global $pass, $fail;
    if ($got === $want) { $pass++; printf("  ok   %s\n", $n); }
    else { $fail++; printf("  FAIL %s\n       got  %s\n       want %s\n", $n, var_export($got, true), var_export($want, true)); } }
function is_(bool $c, string $m, string $d = ''): void { global $pass, $fail;
    if ($c) { $pass++; echo "  ok   $m\n"; } else { $fail++; echo "  FAIL $m" . ($d ? "\n       $d" : '') . "\n"; } }

$root   = dirname(__DIR__);
$push   = (string)file_get_contents($root . '/dpo_push.php');
$return = (string)file_get_contents($root . '/dpo_return.php');
$api    = (string)file_get_contents($root . '/includes/api/api_dpo.php');
$cron   = (string)file_get_contents($root . '/cron/dpo_reconcile.php');
$pub    = (string)file_get_contents($root . '/public.php');
$apipub = (string)file_get_contents($root . '/includes/api/api_public.php');
$master = (string)file_get_contents($root . '/cron/master.php');

echo "\nThe push endpoint does not believe the push\n";
// DPO sends <Result>000</Result> in the body and their own plugin settles on
// it. This URL is public, so reading it would let anyone with a CompanyRef
// clear an invoice for free.
is_(preg_match('/\$xml->Result|\[.Result.\]|->Result\b/', $push) === 0,
    'it never reads Result out of the body');
// Ban the READ, not the word: the comment explaining why we ignore these
// fields necessarily names them.
is_(preg_match('/->ResultExplanation|\[.ResultExplanation.\]/', $push) === 0,
    'nor ResultExplanation');
is_(strpos($push, '$xml->CompanyRef') !== false && strpos($push, '$xml->TransactionToken') !== false,
    'it takes only the identity of the payment');
is_(strpos($push, 'verifyAndSettle') !== false,
    'and then asks DPO itself');
is_(strpos($push, "echo 'OK'") !== false,
    'it acknowledges with OK, as DPO expects');
is_(strpos($push, 'fastcgi_finish_request') !== false,
    'and closes the connection before the slow work, so DPO never retries on our latency');

echo "\nThe return page does not believe the query string\n";
is_(strpos($return, "\$_GET['TransactionToken']") !== false,
    'the token identifies the payment');
is_(preg_match("/\\\$_GET\['(Result|status|paid|amount|success)'\]/i", $return) === 0,
    'but no status, amount or result is ever read from the URL');
is_(strpos($return, 'verifyAndSettle') !== false,
    'it verifies through the one door');
is_(preg_match('/byReference\(.*\);\s*\/\/ re-read/', $return) === 1,
    'then re-reads the row, so the screen reports the database and not the request');

echo "\nAnd it never tells a customer a payment succeeded unless it did\n";
// The pending wording is the whole point: "being confirmed" is true while
// "successful" would be a promise we cannot keep.
is_(strpos($return, 'Payment is being confirmed') !== false, 'pending says being confirmed');
is_(strpos($return, 'Deliberately NOT') !== false,          'and the file says why');
is_(preg_match("/state === 'success'.*Payment successful/s", $return) === 1,
    'and "Payment successful" only renders in the success branch');
is_(strpos($return, 'no-store') !== false, 'a payment outcome is never cached');

echo "\nThe initiate action cannot be told an amount\n";
is_(strpos($api, "\$body['invoice_id']") !== false, 'it takes an invoice id');
is_(preg_match("/\\\$body\['(amount|total|currency|client_id|clientId)'\]/", $api) === 0,
    'and never an amount, currency or client from the body');
is_(strpos($api, 'ca_require_auth') !== false && strpos($api, 'ca_resolve_active_client_id') !== false,
    'the client comes from the token');
is_(strpos($api, "\$ok2(['checkout_url'") !== false,
    'and the browser is handed the checkout URL');
is_(strpos($api, 'company_token') === false && strpos($api, 'trans_token') === false,
    'never a credential or a DPO token');

echo "\nThe status action cannot be used to read somebody else's payment\n";
is_(preg_match("/crm_client_id.*!==.*\\\$clientId.*\\\$er2\('Not found', 404\)/s", $api) === 1,
    "another customer's reference looks exactly like one that does not exist");
is_(strpos($api, 'verifyAndSettle') === false,
    'and polling status never drives settlement');

echo "\nA customer never sees a DPO result code\n";
foreach (['SUCCESS' => 'Payment received',
          'PENDING' => 'being confirmed',
          'CANCELLED' => 'cancelled',
          'EXPIRED' => 'try again',
          'QUARANTINED' => 'being checked'] as $st => $frag) {
    is_(strpos($api, $frag) !== false, "$st reads as \"…$frag…\"");
}
is_(preg_match("/return '.*\b(000|001|002|900|901|903|904)\b.*'/", $api) === 0,
    'and no result code reaches the wording');

echo "\nAll four doors are actually reachable\n";
is_(strpos($pub, "\$page === 'dpo_return'") !== false, 'dpo_return is routed');
is_(strpos($pub, "\$page === 'dpo_push'") !== false,   'dpo_push is routed');
is_(strpos($apipub, "require __DIR__ . '/api_dpo.php';") !== false, 'the API actions are loaded');
is_(strpos($master, "'dpo_reconcile'") !== false,      'the cron is scheduled');
is_(preg_match("/'dpo_reconcile'.*'interval' => (\d+)/", $master, $m) === 1 && (int)$m[1] === 300,
    'every 5 minutes');

echo "\nThe cron returns — it never exits\n";
// master.php INCLUDES its crons. An exit() would silently kill every cron
// scheduled after this one.
$exits = 0;
foreach (token_get_all($cron) as $tok) { if (is_array($tok) && $tok[0] === T_EXIT) $exits++; }
t('no exit() or die() anywhere in the cron', $exits, 0);
is_(substr_count($cron, 'return;') >= 3, 'it returns early instead');
is_(strpos($cron, "name='dpo_payments'") !== false,
    'and it is silent before the table exists, which is the normal state at first');

echo "\nReconciliation survives the kill switch\n";
// Switching DPO off must stop new payments, never strand money already taken.
is_(strpos($cron, 'isEnabled') === false && strpos($cron, 'dpo_enabled') === false,
    'the cron does not consult the feature flag');
$svc = (string)file_get_contents($root . '/lib/DpoPaymentService.php');
is_(preg_match('/function reconcile\(.*?isEnabled/s', $svc) === 0,
    'and reconcile() does not either');

echo "\nURLs are derived from this install, never stored\n";
// dn_plugin_public() already carries the override for when uCRM reports an
// internally-correct but externally-wrong hostname. A URL copied into config
// would drift from it, and customers would land nowhere after paying.
$cfg = ['crm_public_url' => 'https://crm.example.test'];
t('the return URL',  DpoBootstrap::returnUrl($cfg), 'https://crm.example.test/crm/_plugins/dishnet-hybrid-sudan/public.php?page=dpo_return');
t('the back URL',    DpoBootstrap::backUrl($cfg),   'https://crm.example.test/crm/_plugins/dishnet-hybrid-sudan/public.php?page=dpo_return&cancelled=1');
t('the push URL',    DpoBootstrap::pushUrl($cfg),   'https://crm.example.test/crm/_plugins/dishnet-hybrid-sudan/public.php?page=dpo_push');
$boot = (string)file_get_contents($root . '/lib/DpoBootstrap.php');
is_(strpos($boot, 'dn_plugin_public') !== false, 'built from dn_plugin_public()');
is_(preg_match('#https://(?!secure\.3gdirectpay)[a-z0-9.\-]+\.(com|ug)#i', $boot) === 0,
    'and no hostname is hardcoded anywhere in the factory');

echo "\nConfiguration is parsed the way a person types it\n";
t('currencies from a comma list', DpoBootstrap::currencies(['dpo_currencies' => 'ugx, UGX , usd']), ['UGX', 'USD']);
t('currencies from an array',     DpoBootstrap::currencies(['dpo_currencies' => ['ugx']]), ['UGX']);
t('empty means unset',            DpoBootstrap::currencies([]), []);
t('unpayable statuses',           DpoBootstrap::statuses(['dpo_unpayable_statuses' => '0, 9 , 9']), [0, 9]);
t('and rubbish is dropped',       DpoBootstrap::statuses(['dpo_unpayable_statuses' => 'void, 4']), [4]);

echo "\nReadiness names what is missing, and never shows a secret\n";
$r = DpoBootstrap::readiness([]);
is_(!$r['ready'], 'an unconfigured install is not ready');
t('and every missing piece is named', $r['missing'],
  ['company token', 'service type', 'uCRM payment method', 'accepted currencies']);
t('defaulting to the test environment', $r['environment'], 'test');
t('and switched off',                   $r['enabled'], false);
$r2 = DpoBootstrap::readiness(['dpo_company_token' => 'secret-token-value',
    'dpo_service_type' => '3854', 'dpo_payment_method_uuid' => 'uuid',
    'dpo_currencies' => 'UGX', 'dpo_environment' => 'live', 'dpo_enabled' => '1']);
is_($r2['ready'], 'a configured install is ready');
t('and reports live',   $r2['environment'], 'live');
t('and enabled',        $r2['enabled'], true);
is_(strpos(json_encode($r2), 'secret-token-value') === false,
    'the company token is never in what readiness reports');

echo "\nThe feature flag only accepts an affirmative\n";
require_once $root . '/lib/DpoPaymentService.php';
require_once $root . '/lib/DpoClient.php';
$mk = function ($v) use ($root) {
    $db = new PDO('sqlite::memory:');
    $db->exec((string)file_get_contents($root . '/migrations/071_dpo_payments.sql'));
    return new DpoPaymentService(new DpoPaymentStore($db), new DpoClient([]), null, ['dpo_enabled' => $v]);
};
foreach ([true, 1, '1', 'yes', 'on'] as $on)  is_($mk($on)->isEnabled(),  'on for ' . var_export($on, true));
foreach ([false, 0, '0', '', 'no', null] as $off) is_(!$mk($off)->isEnabled(), 'off for ' . var_export($off, true));

echo "\nA credential that lives only in the vault is still found\n";
// The bug this pins: public.php builds its $config from kyc_config.json
// straight off disk and never calls PluginConfig::load(), so a value stored
// ONLY in the vault was invisible to every screen — the admin page read
// "uCRM payment method: missing" while the UUID sat safely in the vault, and
// initiate() would have refused every payment for the same reason.
$vBase = sys_get_temp_dir() . '/dn_vault_' . bin2hex(random_bytes(4));
@mkdir($vBase, 0777, true);
putenv('DN_PLUGIN_ROOT=' . $root);
putenv('DN_DATA_DIR=' . $vBase);
putenv('DN_VAULT_FILE=' . $vBase . '/vault.json');
require_once $root . '/lib/ConfigVault.php';
ConfigVault::store($root, $vBase, [
    'dpo_payment_method_uuid' => 'uuid-from-the-vault',
    'dpo_company_token'       => 'token-from-the-vault',
    'dpo_service_type'        => '3854',
]);
$vFilled = DpoBootstrap::vaulted([]);
t('the payment method is restored', $vFilled['dpo_payment_method_uuid'] ?? '', 'uuid-from-the-vault');
t('and the company token',          $vFilled['dpo_company_token'] ?? '', 'token-from-the-vault');
$vReady = DpoBootstrap::readiness([]);
is_(!in_array('uCRM payment method', $vReady['missing'], true),
    'so readiness no longer calls it missing');
is_(!in_array('company token', $vReady['missing'], true), 'nor the token');
is_(in_array('accepted currencies', $vReady['missing'], true),
    'and something genuinely unset is still reported');
// A value a person typed must never be overwritten by an older vaulted one.
t('an explicit value wins over the vault',
  DpoBootstrap::vaulted(['dpo_service_type' => 'typed-by-hand'])['dpo_service_type'], 'typed-by-hand');
// fill() must not write. apply() refreshes the vault; a screen must not.
$vBefore = (string)file_get_contents($vBase . '/vault.json');
DpoBootstrap::vaulted(['dpo_service_type' => 'something-else']);
t('and reading the vault never rewrites it',
  (string)file_get_contents($vBase . '/vault.json'), $vBefore);
$vAdmin = (string)file_get_contents($root . '/tabs/admin/dpo_payments.php');
is_(strpos($vAdmin, 'DpoBootstrap::vaulted(') !== false,
    'the admin screen reads through the vault');


printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
