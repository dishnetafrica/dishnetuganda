<?php
declare(strict_types=1);
/**
 * test_dpo_screens.php — the two screens a person actually touches.
 *
 * The portal button and the admin screen are where a careless line does real
 * damage: a browser that can name an amount, a page that says "successful"
 * before the server knows, or a credential rendered into HTML. These pin the
 * things that must not drift.
 */
$pass = 0; $fail = 0;
function t(string $n, $got, $want) { global $pass, $fail;
    if ($got === $want) { $pass++; printf("  ok   %s\n", $n); }
    else { $fail++; printf("  FAIL %s\n       got  %s\n       want %s\n", $n, var_export($got, true), var_export($want, true)); } }
function is_(bool $c, string $m, string $d = ''): void { global $pass, $fail;
    if ($c) { $pass++; echo "  ok   $m\n"; } else { $fail++; echo "  FAIL $m" . ($d ? "\n       $d" : '') . "\n"; } }

$root   = dirname(__DIR__);
$portal = (string)file_get_contents($root . '/tabs/customer_app/portal.php');
$pdata  = (string)file_get_contents($root . '/tabs/customer_app/portal_data.php');
$admin  = (string)file_get_contents($root . '/tabs/admin/dpo_payments.php');
$pub    = (string)file_get_contents($root . '/public.php');
$nav    = (string)file_get_contents($root . '/includes/navigation.php');

echo "\nThe Pay Now button sends an invoice id and nothing else\n";
// A browser that could name the amount could name 1,000 instead of 299,000.
is_(strpos($portal, "JSON.stringify({ invoice_id: invoiceId })") !== false,
    'the request body is only the invoice id');
is_(preg_match('/payNow.*?JSON\.stringify\(\{[^}]*\b(amount|total|currency|client)\b/s', $portal) === 0,
    'no amount, currency or client is ever sent');
is_(strpos($portal, "action=dpo_initiate") !== false, 'it calls dpo_initiate');
is_(strpos($portal, 'this.apiFetch(') !== false,
    'through apiFetch, so the bearer token and active account ride along');

echo "\nAnd it cannot be pressed twice\n";
is_(preg_match('/payNow:.*?if \(!btn \|\| btn\.disabled\) return;.*?btn\.disabled = true;/s', $portal) === 1,
    'a second tap is refused before anything is sent');
is_(strpos($portal, "lbl.textContent = 'Processing payment…'") !== false,
    'and the button says what is happening');
is_(preg_match('/payNow:.*?catch\(function\(e\).*?btn\.disabled = false;/s', $portal) === 1,
    'it re-enables only when the attempt actually failed');

echo "\nThe button only appears when there is something to pay\n";
is_(preg_match("/portalDpoEnabled.*?status'\] !== 'paid'.*?due'\] > 0/s", $portal) === 1,
    'enabled, unpaid, and an outstanding amount — all three');
is_(strpos($pdata, '$portalDpoEnabled') !== false, 'the flag is set by the shared loader');
is_(strpos($pdata, 'Display only') !== false,
    'and the loader says it decides a button, never money');

echo "\nThe customer is told who handles their card\n";
is_(strpos($portal, 'DishNet never sees your card') !== false,
    'the hosted-checkout promise is on the button, where it is relevant');

echo "\nThe admin screen never renders a credential\n";
is_(strpos($admin, 'value="<?= $h($dpConfig[\'dpo_company_token\']') === false,
    'the company token is never echoed into a value attribute');
is_(preg_match('/name="dpo_company_token"[^>]*type="password"/', $admin) === 1
    || preg_match('/type="password"[^>]*name="dpo_company_token"/', $admin) === 1,
    'its field is a password field');
is_(strpos($admin, 'configured — leave blank to keep') !== false,
    'and it reports configured-or-not instead of the value');
is_(strpos($admin, 'Never displayed') !== false, 'the screen says so out loud');

echo "\nSaving a blank token does not wipe a live one\n";
// Clearing a working credential must be deliberate, not what happens when
// somebody saves the form after changing something else.
is_(preg_match("/\\\$dpTok = trim\(\(string\)\(\\\$_POST\['dpo_company_token'\] \?\? ''\)\);\s*\n\s*if \(\\\$dpTok !== ''\)/", $admin) === 1,
    'a blank field leaves the stored token alone');

echo "\nSettings are vaulted, not just saved\n";
is_(strpos($admin, 'ConfigVault::store') !== false, 'they go into the vault');
is_(strpos($admin, "\$store->save('kyc_config.json'") !== false, 'and into config');
is_(strpos($admin, 'NOT vaulted') !== false,
    'and a vault failure is reported, not swallowed — it would be lost on re-install');

echo "\nThe screen is honest about which environment it is in\n";
is_(strpos($admin, 'LIVE environment') !== false && strpos($admin, 'real money') !== false,
    'live says real money');
is_(strpos($admin, 'DPO has no separate test host') !== false,
    'and test explains why the stamp matters');
is_(strpos($admin, 'excluded from settled-value totals') !== false,
    'test rows are kept out of the money figures');

echo "\nIt gives the operator the three URLs DPO needs\n";
is_(strpos($admin, 'DpoBootstrap::returnUrl') !== false, 'return URL');
is_(strpos($admin, 'DpoBootstrap::backUrl') !== false,   'back URL');
is_(strpos($admin, 'DpoBootstrap::pushUrl') !== false,   'push URL');
is_(strpos($admin, 'not</strong> part of the API request') !== false,
    'and says the push URL must be registered with DPO by hand');

echo "\nQuarantined payments are surfaced, not buried in a filter\n";
is_(strpos($admin, 'payment(s) quarantined') !== false, 'a banner counts them');
is_(strpos($admin, 'Nothing has been posted to') !== false,
    'and says plainly that nothing was posted to uCRM');

echo "\nIt is admin-only, and reachable\n";
is_(strpos($admin, "\$retailer['is_admin']") !== false, 'the page guards itself');
is_(strpos($pub, "'dpo_payments'         => '*admin'") !== false, 'the permission map agrees');
is_(strpos($pub, "'dpo_payments'     => 'tabs/admin/dpo_payments.php'") !== false, 'the route exists');
is_(strpos($pub, "'id'=>'dpo_payments'") !== false, 'it is listed for admins');
// The sidebar is hand-written links, not generated from the tab array.
is_(strpos($nav, 'tab=dpo_payments') !== false, 'and a person can reach it from the sidebar');

echo "\nEvery admin write is CSRF-checked\n";
is_(strpos($admin, 'csrfCheck()') !== false, 'the save checks a token');
is_(strpos($admin, 'csrfField()') !== false, 'and the form carries one');
is_(preg_match("/REQUEST_METHOD'\] \?\? 'GET'\) === 'POST'/", $admin) === 1, 'and it is POST only');

echo "\nOutput is escaped\n";
// Not a count — counts test nothing and break on any edit. Find every
// `<?= …` that emits something dynamic and check it went through an escaper
// or a cast. A reference or a failure reason reaching HTML raw is the bug
// this is looking for.
preg_match_all('/<\?=\s*(.+?)\s*\?>/s', $admin, $mm);
$raw = [];
foreach ($mm[1] as $expr) {
    $e = trim($expr);
    if ($e === '') continue;
    // Safe: escaped, cast to a number, a URL-encoded id, or a helper that
    // escapes internally.
    if (preg_match('/^\$h\(|^\$money\(|^\$dpPill\(|^\(int\)|^urlencode\(/', $e)) continue;
    // Safe: a bare literal or a ternary over literals (the selected= flags).
    if (preg_match("/^'[^\$]*'$/", $e)) continue;
    if (preg_match("/\?\s*'[^\$]*'\s*:\s*'[^\$]*'$/", $e) && strpos($e, '$dpConfig') === false
        && strpos($e, '$dpOne') === false && strpos($e, '$r[') === false) continue;
    // Safe: a loop variable that is one of our own status constants.
    if ($e === '$s') continue;
    $raw[] = $e;
}
t('nothing dynamic reaches HTML unescaped', $raw, []);
is_(strpos($admin, 'htmlspecialchars') !== false, 'escaping is htmlspecialchars with ENT_QUOTES');
is_(strpos($admin, "ENT_QUOTES") !== false, 'quotes included, so attributes are safe too');

echo "\nReconciliation is explained where finance will look for it\n";
// DishNet absorbs the fee, so DPO's bank settlement will NOT equal the sum of
// these rows. Somebody will notice that and needs to know it is expected.
is_(strpos($admin, "DishNet absorbs DPO's fee") !== false,
    'the fee arrangement is stated on the screen');
is_(strpos($admin, 'Match on the') !== false, 'and it says what to reconcile on');

echo "\nThe portal reads the same binding the admin screens trust\n";
// The bug: the portal resolved a customer to a kit through sl_kits.json, a
// sibling plugin's file. On the Uganda box dishnet-starlink-finance is not
// installed at all and dishnet-data-report has no sl_kits.json, so a bound
// customer whose usage WE had collected saw nothing — while the Fleet screen
// showed 52 GB for the same kit.
is_(strpos($pdata, 'EquipmentAssignment::fromStore($store)') !== false,
    'it resolves the customer through equipment_assignments');
is_(strpos($pdata, 'new KitUsage(') !== false,
    'and reads usage through KitUsage, which prefers our own collection');
is_(preg_match("/if \\(!empty\\(\\\$allUsage\\) && \\(!empty\\(\\\$kitsData\\) \\|\\| !empty\\(\\\$customerKits\\)\\)\\)/", $pdata) === 1,
    'and no longer requires the sibling kit file to be present at all');
is_(strpos($pdata, "\$pdEa->forClient(\$portalCustomerId)") !== false,
    'the assignment is read for this customer specifically');

echo "\nAnd the sibling chain still works where it is the collector\n";
// South Sudan's box IS collected by the sibling. That path must be untouched.
is_(strpos($pdata, "SiblingPlugin::readJsonFromAny(\n            ['dishnet-starlink-finance', 'dishnet-data-report'], 'sl_kits.json')") !== false
    || strpos($pdata, "'dishnet-starlink-finance', 'dishnet-data-report'], 'sl_kits.json'") !== false,
    'sl_kits.json is still read');
is_(strpos($pdata, "readJsonOrEmpty('dishnet-data-report', 'sl_svc_cache.json')") !== false,
    'and the service-line cache still contributes');

echo "\nA case difference cannot silently drop a customer's usage\n";
is_(preg_match("/\\\$uKit = strtoupper\\(trim\\(/", $pdata) === 1, 'usage kit serials are normalised');
is_(preg_match("/\\\$uSl  = strtoupper\\(trim\\(/", $pdata) === 1, 'and so are service lines');

echo "\nThe usage source is reported honestly\n";
// It was basename(dirname($uf)) against a variable that never existed, so the
// portal always claimed an empty source.
// Parsed, not grepped: the comment explaining this bug necessarily names the
// variable, and a substring search would fail on the explanation rather than
// on the code.
$pdUf = 0;
foreach (token_get_all($pdata) as $pdT) {
    if (is_array($pdT) && $pdT[0] === T_VARIABLE && $pdT[1] === '$uf') $pdUf++;
}
t('the undefined variable is gone from the code', $pdUf, 0);
is_(strpos($pdata, "'source' => \$pdSource") !== false,
    'and the real collector is named instead');

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
