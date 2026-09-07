<?php
declare(strict_types=1);
/**
 * Phase A regression: the cashbook may never hard-code a payment currency
 * again. uCRM's currencyCode decides; configuration decides the base and the
 * selectable list; the Sudan defaults are preserved exactly (base USD,
 * currencies USD,SSP) so an install that configures nothing behaves as
 * before, byte for byte.
 */
require_once dirname(__DIR__) . '/lib/currency.php';

$pass = 0; $fail = 0;
function t(string $n, $got, $want) { global $pass, $fail;
    if ($got === $want) { $pass++; printf("  ok   %s\n", $n); }
    else { $fail++; printf("  FAIL %s\n       got  %s\n       want %s\n", $n, var_export($got, true), var_export($want, true)); } }

echo "Book base and selectable currencies\n";
t('Sudan default base is USD (nothing configured)', dn_book_base([]), 'USD');
t('Sudan default currencies are USD,SSP', dn_book_currencies([]), ['USD', 'SSP']);
t('Uganda base from config', dn_book_base(['cashbook_base_currency' => 'UGX']), 'UGX');
t('Uganda currencies: UGX first, USD kept, no SSP',
  dn_book_currencies(['cashbook_base_currency' => 'UGX', 'cashbook_currencies' => 'UGX,USD']),
  ['UGX', 'USD']);
t('base is forced to the front even if listed later',
  dn_book_currencies(['cashbook_base_currency' => 'UGX', 'cashbook_currencies' => 'USD,UGX']),
  ['UGX', 'USD']);
t('junk entries are dropped',
  dn_book_currencies(['cashbook_base_currency' => 'UGX', 'cashbook_currencies' => 'UGX, usd , 12A, US']),
  ['UGX', 'USD']);
t('lowercase config value normalised', dn_book_base(['cashbook_base_currency' => 'ugx']), 'UGX');
t('invalid base falls back to USD', dn_book_base(['cashbook_base_currency' => 'shillings']), 'USD');

echo "\nPayment currency comes from the payment, never a literal\n";
t('UGX uCRM payment books as UGX',
  dn_payment_currency(['currencyCode' => 'UGX', 'amount' => 329000], []), 'UGX');
t('USD uCRM payment books as USD — even on a UGX book',
  dn_payment_currency(['currencyCode' => 'USD'], ['cashbook_base_currency' => 'UGX']), 'USD');
t('payment without a currency falls back to the configured base',
  dn_payment_currency(['amount' => 5], ['cashbook_base_currency' => 'UGX']), 'UGX');
t('Sudan fallback stays USD', dn_payment_currency([], []), 'USD');
t('lowercase currencyCode normalised', dn_payment_currency(['currencyCode' => 'ugx'], []), 'UGX');
t('garbage currencyCode falls back', dn_payment_currency(['currencyCode' => 'U$D'], []), 'USD');

echo "\nEntry/payload/SSP helpers (the lines that used to guess)\n";
$ug = ['cashbook_base_currency' => 'UGX', 'cashbook_currencies' => 'UGX,USD'];
t('Sudan: USD passes the handler', dn_entry_currency('USD', []), 'USD');
t('Sudan: SSP passes the handler', dn_entry_currency('SSP', []), 'SSP');
t('Sudan: missing falls to USD', dn_entry_currency('', []), 'USD');
t('Sudan: garbage falls to USD', dn_entry_currency('EURO', []), 'USD');
t('Sudan cash-in default stays SSP', dn_entry_currency('', [], 'SSP'), 'SSP');
t('Uganda: UGX SURVIVES the handler', dn_entry_currency('UGX', $ug), 'UGX');
t('Uganda: USD stays selectable', dn_entry_currency('usd', $ug), 'USD');
t('Uganda: SSP is coerced to the base, never booked', dn_entry_currency('SSP', $ug), 'UGX');
t('Uganda: missing falls to the base', dn_entry_currency('', $ug), 'UGX');
t('Uganda cash-in default follows the base', dn_entry_currency('', $ug, 'SSP'), 'UGX');
t('SSP selectable on Sudan', dn_ssp_selectable([]), true);
t('SSP not selectable on Uganda', dn_ssp_selectable($ug), false);
t('payload: the staff-recorded currency wins', dn_payload_currency('UGX', []), 'UGX');
t('payload: missing falls to the book base (Uganda)', dn_payload_currency('', $ug), 'UGX');
t('payload: missing falls to the book base (Sudan)', dn_payload_currency('', []), 'USD');
t('payload: lowercase normalised', dn_payload_currency('ugx', []), 'UGX');

echo "\naddEntry() books the CONFIGURED base when currency is omitted\n";
require_once dirname(__DIR__) . '/lib/StoreInterface.php';
require_once dirname(__DIR__) . '/lib/SqliteStore.php';
require_once dirname(__DIR__) . '/lib/CashbookService.php';
$tdU = sys_get_temp_dir() . '/cb_curr_defu_' . getmypid();
@mkdir($tdU, 0777, true);
$stU = SqliteStore::create($tdU);
$svU = new CashbookService($stU, $tdU);
file_put_contents($tdU . '/kyc_config.json', json_encode($ug));
$rU = $svU->addEntry(['project' => 'dishnet', 'direction' => 'in', 'amount' => 5.0,
    'category' => 'Receipt', 'description' => 'no currency given'], ['name' => 't'], true);
t('Uganda addEntry default is UGX, not USD',
  $stU->getPdo()->query('SELECT currency FROM cb_ledger WHERE id=' . (int)$rU['id'])->fetchColumn(), 'UGX');
$tdS = sys_get_temp_dir() . '/cb_curr_defs_' . getmypid();
@mkdir($tdS, 0777, true);
$stS = SqliteStore::create($tdS);
$svS = new CashbookService($stS, $tdS);
file_put_contents($tdS . '/kyc_config.json', json_encode(['cashbook_base_currency' => 'USD']));
$rS = $svS->addEntry(['project' => 'dishnet', 'direction' => 'in', 'amount' => 5.0,
    'category' => 'Receipt', 'description' => 'no currency given'], ['name' => 't'], true);
t('Sudan addEntry default stays USD',
  $stS->getPdo()->query('SELECT currency FROM cb_ledger WHERE id=' . (int)$rS['id'])->fetchColumn(), 'USD');

echo "\nC0.1 — a manual entry can never be an Opening Balance again\n";
$rOB = $svU->addEntry(['project' => 'dishnet', 'direction' => 'in', 'amount' => 10000.0,
    'currency' => 'USD', 'category' => 'Opening Balance', 'person' => 'Bhavin',
    'description' => 'the exact shape of the 2026-09-07 live entry'], ['name' => 't'], true);
t('addEntry refuses the Opening Balance category', $rOB['ok'], false);
t('and points at the Opening Balances screen',
  stripos((string)($rOB['message'] ?? ''), 'Opening Balances screen') !== false, true);
t('no row was written by the refused entry',
  (int)$stU->getPdo()->query("SELECT COUNT(*) FROM cb_ledger WHERE category='Opening Balance'")->fetchColumn(), 0);
exec('rm -rf ' . escapeshellarg($tdU) . ' ' . escapeshellarg($tdS));

$rootC01 = dirname(__DIR__);
$apiCb = (string)file_get_contents($rootC01 . '/includes/api/api_cashbook.php');
$cbC01 = (string)file_get_contents($rootC01 . '/tabs/accounts/cashbook.php');
t('category API strips Opening Balance before responding',
  strpos($apiCb, "\$_catHide = ['Opening Balance'];") !== false, true);
t('and strips the SSP flows on a non-SSP book',
  strpos($apiCb, "dn_ssp_selectable(\$config ?? null)") !== false, true);
t('wizard strips hidden categories on EVERY load path (id or name)',
  substr_count($cbC01, '_cb4StripHidden(') >= 6, true);
t('the wizard fallback list lost its Opening Balance tile',
  strpos($cbC01, "{id:'Opening Balance'") === false, true);

echo "\nScanner v2 — the blind spots the audit proved are covered\n";
$root = dirname(__DIR__);
// Every file that creates money records or uCRM payments/quotes.
$writePaths = [
    'webhook.php', 'cron_sync.php', 'cron_crm_payment_gap.php', 'cron_quote_wa.php',
    'cron/payment_catchup_sync.php', 'cron/kyc_crm_sync.php',
    'lib/CashbookService.php', 'lib/KycService.php', 'lib/ExpenseAdvanceService.php',
    'lib/QuotationService.php',
    'includes/routes.php',
    'includes/post/post_cashbook.php', 'includes/post/post_field.php',
    'includes/post/post_sales.php', 'includes/post/post_kyc.php',
    'includes/api/api_retailer.php', 'includes/api/api_leads.php',
    'includes/api/api_cashbook.php', 'includes/api/api_payments_admin.php',
    'includes/api/api_cron_debug.php',
    'tabs/admin/settings.php', 'tabs/accounts/handover_queue.php',
    'tabs/sales/my_account.php', 'tabs/accounts/staff_cashbooks.php',
    'tabs/support/field_expenses.php', 'tabs/sales/collect_payment.php',
    'api/index.php',
];
// Deliberate Sudan literals that REMAIN, all behind dn_ssp_selectable() guards
// (exchange legs, SSP give/return). Counts are pinned: one new literal fails.
$pinnedStamps = [
    'includes/post/post_cashbook.php'   => ['USD' => 1, 'SSP' => 7],
    'includes/api/api_cashbook.php'     => ['USD' => 5, 'SSP' => 5],
    'tabs/accounts/staff_cashbooks.php' => ['USD' => 4, 'SSP' => 4],
    'tabs/sales/my_account.php'         => ['USD' => 3, 'SSP' => 3],
];
$viol = [];
foreach ($writePaths as $rel) {
    $src   = (string)@file_get_contents($root . '/' . $rel);
    $lines = explode("\n", $src);
    $counts = ['USD' => 0, 'SSP' => 0, 'UGX' => 0];
    foreach ($lines as $i => $line) {
        $trim = ltrim($line);
        if ($trim === '' || strpos($trim, '//') === 0 || strpos($trim, '*') === 0) continue;
        // (1) literal ledger stamps
        if (preg_match("/'currency'\s*=>\s*'(USD|SSP|UGX)'/", $line, $m)) {
            $counts[$m[1]]++;
        }
        // (2) literal uCRM payload currency
        if (preg_match("/'currencyCode'\s*=>\s*'[A-Z]{3}'/", $line)) {
            $viol[] = "$rel:" . ($i + 1) . '  payload literal  ' . trim(substr($line, 0, 90));
        }
        // (3) request-input currency guessing
        if (preg_match("/\\\$_(POST|GET|REQUEST)\[['\"]currency['\"]\]\s*\?\?\s*'(USD|SSP)'/", $line)
            || preg_match("/\\\$body\[['\"]currency['\"]\]\s*\?\?\s*'(USD|SSP)'/", $line)) {
            $viol[] = "$rel:" . ($i + 1) . '  input guess  ' . trim(substr($line, 0, 90));
        }
        // (4) hard USD/SSP whitelist arrays
        if (preg_match("/\[\s*'USD'\s*,\s*'SSP'\s*\]/", $line)) {
            $viol[] = "$rel:" . ($i + 1) . '  whitelist  ' . trim(substr($line, 0, 90));
        }
    }
    $pin = $pinnedStamps[$rel] ?? ['USD' => 0, 'SSP' => 0];
    foreach (['USD', 'SSP'] as $c) {
        if ($counts[$c] > ($pin[$c] ?? 0)) {
            $viol[] = "$rel  {$c} stamps grew: {$counts[$c]} > " . ($pin[$c] ?? 0);
        }
    }
    if ($counts['UGX'] > 0) $viol[] = "$rel  UGX literal stamp ({$counts['UGX']}) — Uganda literals corrupt Sudan";
    if (isset($pinnedStamps[$rel]) && strpos($src, 'dn_ssp_selectable(') === false) {
        $viol[] = "$rel  carries pinned SSP literals but no dn_ssp_selectable() guard";
    }
}
t('scanner v2: zero unpinned literals across ALL write paths', $viol, []);
if ($viol) echo '    ' . implode("\n    ", $viol) . "\n";

// CashbookService's remaining SQL currency literals are the Phase-C reader
// debt (getBalance/getSummary/getLedger family). Pinned: may only shrink.
$cbs = (string)file_get_contents($root . '/lib/CashbookService.php');
// USD-stream filters are the Sudan-lens reader debt (may only shrink as
// consumers move to the account-aware readers). SSP CASE-reads are the
// legitimate dual-column semantics and are not counted.
t('reader-layer USD-filter debt did not grow (may only shrink)',
  substr_count($cbs, "currency='USD'") + substr_count($cbs, "currency = 'USD'") <= 5, true);
t('SSP dual-column reads are CASE-guarded semantics, not stream filters',
  substr_count($cbs, "currency='SSP'")
    - substr_count($cbs, "CASE WHEN currency='SSP'") <= 5, true);
t('KYC cash sale no longer bakes USD into SQL',
  strpos((string)file_get_contents($root . '/lib/KycService.php'), "'in', ?, 'USD'") === false, true);

echo "\nFixed paths use the helpers (spot welds)\n";
$reads = [];
foreach (['includes/post/post_field.php', 'cron_crm_payment_gap.php', 'webhook.php',
          'includes/post/post_kyc.php', 'tabs/sales/collect_payment.php',
          'lib/CashbookService.php'] as $rel) {
    $reads[$rel] = (string)file_get_contents($root . '/' . $rel);
}
t('staff collection pushes the RECORDED currency to uCRM',
  strpos($reads['includes/post/post_field.php'], 'dn_payload_currency($currency') !== false, true);
t('gap-fill cron reads the payment currency', strpos($reads['cron_crm_payment_gap.php'], 'dn_payment_currency($payment') !== false, true);
t('gap-fill cron respects the cash-only rule', strpos($reads['cron_crm_payment_gap.php'], 'PaymentUuids::CASH') !== false, true);
t('gap-fill cron uses the payment date', strpos($reads['cron_crm_payment_gap.php'], "createdDate") !== false, true);
t('payment-delete reversal is typed REFUND', strpos($reads['webhook.php'], "'txn_type'          => 'REFUND'") !== false, true);
t('KYC-cancel refund actually posts (typed, ref-linked)',
  strpos($reads['includes/post/post_kyc.php'], "'KYC-REFUND-'") !== false
  && strpos($reads['includes/post/post_kyc.php'], 'createEntry') === false, true);
t('collect-payment currency buttons come from configuration',
  strpos($reads['tabs/sales/collect_payment.php'], 'dn_book_currencies($config') !== false
  && strpos($reads['tabs/sales/collect_payment.php'], 'value="USD"') === false, true);
t('the Uganda chart seeder refuses on a non-UGX base',
  strpos($reads['lib/CashbookService.php'], "bookBase() !== 'UGX'") !== false, true);
t('the do-nothing opening stub is gone', strpos($reads['lib/CashbookService.php'], 'setOpeningBalance') === false, true);

echo "\nEntry forms are config-driven (SSP cannot appear on a UGX install)\n";
$fe = (string)file_get_contents($root . '/tabs/support/field_expenses.php');
t('field_expenses has no hard-coded SSP radio', strpos($fe, 'value="SSP"') === false, true);
t('field_expenses renders from dn_book_currencies', substr_count($fe, 'dn_book_currencies($config)') >= 2, true);
$cb = (string)file_get_contents($root . '/tabs/accounts/cashbook.php');
t('cashbook pills render from dn_book_currencies', strpos($cb, 'dn_book_currencies($config)') !== false, true);
t('cashbook JS carries the allowed list', strpos($cb, '_cb4Currs = <?= json_encode(dn_book_currencies($config)) ?>') !== false, true);
t('SSP FX flows are gated on SSP being selectable',
  strpos($cb, "<?php if (!\$_cbSSP): ?>, 'Exchange', 'SSP Advance', 'SSP Return'<?php endif; ?>") !== false, true);
t('wizard hides Opening Balance where the accounts layer owns it',
  strpos($cb, "var _cb4Hidden = ['Opening Balance'") !== false, true);
t("no fixed cb4PillSSP markup remains", strpos($cb, 'id="cb4PillSSP"') === false, true);

echo "\nLedger rows wear the ROW currency, never the install symbol\n";
t('row cells render via the row-currency helper', substr_count($cb, '$_cbRowMoney($e)') >= 4, true);
t('no cell prints the display symbol on a raw row amount',
  strpos($cb, "dn_cur(\$config) . number_format(\$e['amount']") === false, true);
t('balance cell follows the balance-stream currency',
  strpos($cb, "\$e['_bal_currency'] ?? \$_cbBase") !== false, true);
// The LIVE exporter is includes/routes.php — it intercepts cb_export=csv
// before the tab loads. The audit found the tab-side twin unreachable; it
// has been removed, so these guards aim at the code that actually runs.
$routes = (string)file_get_contents($root . '/includes/routes.php');
t('live CSV export authenticates', strpos($routes, '$csvUser = $auth->requireLogin();') !== false, true);
t('live CSV headers carry the configured base, not a USD literal',
  strpos($routes, "'Received USD'") === false, true);
t('live CSV currency filter is config-driven',
  strpos($routes, "in_array(strtoupper(\$_GET['cb_curr'] ?? ''), \$_csvCurrs2, true)") !== false, true);
t('live CSV Currency column states the row currency',
  strpos($routes, '$rowCur2') !== false && strpos($routes, "\$_isSspRow2?'SSP':'USD'") === false, true);
t('the unreachable tab-side exporter is gone (links to the live one remain)',
  strpos($cb, 'CSV EXPORT lives in includes/routes.php') !== false
  && strpos($cb, '$_csvIsAll') === false && strpos($cb, 'fputcsv') === false, true);

echo "\nPhase C hero: a balance always names its OWN currency\n";
t('non-SSP hero renders per-currency POSITION cards',
  strpos($cb, "htmlspecialchars(\$_pos['currency']) ?> POSITION") !== false, true);
t('the position amount is prefixed by the position currency, not the display symbol',
  strpos($cb, "<?= htmlspecialchars(\$_pos['currency']) ?> <?php echo number_format(\$_pos['total'], 2); ?>") !== false, true);
t('the legacy mislabeled hero only survives behind the SSP gate',
  strpos($cb, "<?php if (\$_cbSSP && (\$filterCurr === '' || \$filterCurr === \$_cbBase)): ?>") !== false, true);
t('no combined figure on a non-SSP book (COMBINED card is SSP-gated)',
  strpos($cb, '<?php if ($_cbSSP && $filterCurr === \'\'): ?>') !== false, true);
t('live entry count feeds the card, not the seeder metadata',
  strpos($cb, '$_cbLiveCount = $cb->countEntries($proj);') !== false, true);
t('summary view is per-currency P&L on a non-SSP book',
  strpos($cb, '$plData  = $cb->plByPeriod($proj') !== false
  && strpos($cb, 'capital flows excluded · one section per currency') !== false, true);
t('void action wired (safe correction path)',
  strpos($cb, "cbCrudVoid()") !== false, true);
t('the Exchange tile lives on every book, labeled with ITS counter-currency',
  strpos($cb, 'USD ↔ <?= htmlspecialchars($_cbXC) ?>') !== false
  && strpos($cb, "<?php if (\$_cbSSP): ?>\n      <div class=\"cb4-dir-btn\" id=\"cb4DirExch\"") === false, true);
t('no exchange label hard-codes SSP any more (JS uses _cb4XC)',
  strpos($cb, "var _cb4XC = <?= json_encode(\$_cbXC) ?>;") !== false
  && strpos($cb, "'Exchange · USD ↔ SSP'") === false
  && strpos($cb, "'Exchange USD to SSP (") === false, true);
t('the USD amount field wears $ on a non-SSP book, never the display symbol',
  strpos($cb, "<?= \$_cbSSP ? trim(dn_cur(\$config)) : '\$' ?>") !== false, true);
t('the Convert Currency quick action opens the wizard exchange',
  strpos($cb, "cb4Open('exchange');return false;") !== false, true);
t('the wizard tolerates a missing tile (null-guarded toggle)',
  strpos($cb, "if (_cb4ExchBtn) _cb4ExchBtn.classList.toggle") !== false, true);
t('non-SSP Exchange posts route to the honest pair writer',
  strpos((string)file_get_contents($rootC01 . '/includes/post/post_cashbook.php'),
    "strcasecmp(\$category, 'Exchange') === 0 && !dn_ssp_selectable(\$config ?? null)") !== false, true);
$maX = (string)file_get_contents($rootC01 . '/tabs/sales/my_account.php');
t('my_account: the exchange view collapses to summary on a non-SSP book',
  strpos($maX, "if (\$v === 'exchange' && !dn_ssp_selectable(\$config ?? null)) { \$v = 'summary'; }") !== false, true);
t('my_account: the Convert tile is SSP-gated',
  substr_count($maX, "<?php if (dn_ssp_selectable(\$config ?? null)): ?>") >= 1, true);
$waX = (string)file_get_contents($rootC01 . '/tabs/sales/wallet.php');
t('wallet: the field Exchange tile is SSP-gated',
  strpos($waX, "<?php if (dn_ssp_selectable(\$config ?? null)): ?>\n      <div class=\"fr3-dir-btn\" id=\"fr3DirExch\"") !== false, true);
$soX = (string)file_get_contents($rootC01 . '/tabs/accounts/ssp_overview.php');
t('ssp_overview tab refuses to render on a non-SSP book',
  strpos($soX, "if (!dn_ssp_selectable(\$config ?? null)) {") !== false, true);

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail ? 1 : 0);
