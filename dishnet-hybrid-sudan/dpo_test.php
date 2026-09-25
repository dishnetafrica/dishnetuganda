<?php
declare(strict_types=1);
/**
 * dpo_test.php — the test checkout DPO's reviewer opens.
 *
 * URL: public.php?page=dpo_test&k=<key made on the DPO admin screen>
 *
 * DPO issues live credentials only after their team has paid through a test
 * link of ours. The customer portal cannot be that link: it signs people in
 * with a one-time code sent to the customer's own phone. So this page lists
 * the TEST customers' unpaid invoices with a Pay button, and the button starts
 * the payment through the same DpoPaymentService::initiate() a customer's Pay
 * Now uses — the live invoice read, the amount rules, the attempt reuse, the
 * same return page and the same verification. Nothing here settles anything.
 *
 * It opens only when:
 *   · the DPO environment is TEST — in LIVE it answers 404 whatever the key;
 *   · the key matches the one on the admin screen, which can replace it;
 *   · and, to pay, the invoice belongs to a named test customer. The service
 *     refuses everyone else in the test environment on its own as well.
 *
 * Referrer-Policy: no-referrer — the key is in this URL, and the reviewer is
 * sent on to DPO's checkout page from here.
 */

require_once __DIR__ . '/lib/error_handler.php';
require_once __DIR__ . '/lib/bootstrap_data.php';
require_once __DIR__ . '/lib/StoreInterface.php';
require_once __DIR__ . '/lib/SqliteStore.php';
require_once __DIR__ . '/lib/PluginConfig.php';
require_once __DIR__ . '/lib/MigrationRunner.php';
require_once __DIR__ . '/lib/CrmApiClient.php';
require_once __DIR__ . '/lib/DpoBootstrap.php';
require_once __DIR__ . '/lib/crm_url.php';

$root = __DIR__;
// public.php has already worked out the data directory; so has a test router.
if (!isset($dataDir) || !is_string($dataDir) || $dataDir === '') $dataDir = getDataDir($root);
$dtConfig = DpoBootstrap::vaulted(PluginConfig::load($root, $dataDir));

header('Cache-Control: no-store, no-cache, must-revalidate');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow');
header('X-Content-Type-Options: nosniff');

$dtEnv   = ((string)($dtConfig['dpo_environment'] ?? 'test')) === 'live' ? 'live' : 'test';
$dtKey   = (string)($dtConfig['dpo_test_link_key'] ?? '');
$dtGiven = (string)($_GET['k'] ?? '');
if ($dtEnv !== 'test' || strlen($dtKey) < 16 || !hash_equals($dtKey, $dtGiven)) {
    // The same answer for "live", "no link made" and "wrong key": a
    // stranger learns nothing about which it was.
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Not found';
    return;
}

$dtClients = DpoBootstrap::testClients($dtConfig);
$dtReady   = DpoBootstrap::readiness($dtConfig);
$dtCrm     = CrmApiClient::fromUcrm($root, $dtConfig);
$dtLog     = function (string $e, string $d) use ($dataDir) {
    if (function_exists('logActivity')) { logActivity($dataDir, $e, 'DPO Pay', $d); }
    else { error_log('[dpo] ' . $e . ' — ' . $d); }
};
$dtError = '';

// ── Pay: start the payment exactly as Pay Now does ──────────────────────
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $dtInvoiceId = (int)($_POST['invoice_id'] ?? 0);
    $dtInv       = $dtInvoiceId > 0 ? $dtCrm->get('invoices/' . $dtInvoiceId) : null;
    $dtClientId  = is_array($dtInv) ? (int)($dtInv['clientId'] ?? 0) : 0;
    if ($dtClientId <= 0 || !in_array($dtClientId, $dtClients, true)) {
        $dtError = 'That invoice is not on a test customer.';
    } else {
        try {
            $dtStore = SqliteStore::create($dataDir);
            (new MigrationRunner($dtStore->getPdo(), $root . '/migrations'))->run();
            $dtSvc = DpoBootstrap::service($dtStore, $dtConfig, $dtCrm, $dtLog);
            $dtMe  = $dtCrm->get('clients/' . $dtClientId) ?? [];
            $dtR   = $dtSvc->initiate($dtClientId, $dtInvoiceId, [
                'first'     => (string)($dtMe['firstName'] ?? ''),
                'last'      => (string)($dtMe['lastName'] ?? ($dtMe['companyName'] ?? '')),
                'email'     => (string)($dtMe['contacts'][0]['email'] ?? ''),
                'phone'     => (string)($dtMe['contacts'][0]['phone'] ?? ''),
                'country'   => (string)($dtConfig['dpo_customer_country'] ?? ''),
                'dial_code' => (string)($dtConfig['dpo_customer_dial_code'] ?? ''),
            ]);
            if ($dtR['ok']) {
                header('Location: ' . $dtR['checkout_url'], true, 303);
                return;
            }
            // This page is for DishNet and DPO, so the code is shown with the
            // words: "CREATE_904" says the test account refused the currency.
            $dtError = $dtR['error'] . ' (' . $dtR['code'] . ')';
        } catch (\Throwable $e) {
            error_log('[dpo_test] ' . $e->getMessage());
            $dtError = 'The payment could not be started.';
        }
    }
}

// ── The test customers and their unpaid invoices, read live ─────────────
$dtList = [];
foreach ($dtClients as $dtCid) {
    $dtC = $dtCrm->get('clients/' . $dtCid);
    $dtName = trim((string)($dtC['companyName'] ?? '')) !== ''
        ? (string)$dtC['companyName']
        : trim((string)($dtC['firstName'] ?? '') . ' ' . (string)($dtC['lastName'] ?? ''));
    $dtRows = $dtCrm->get("invoices?clientId={$dtCid}&statuses[]=1&statuses[]=2&limit=20")
           ?? $dtCrm->get("billing/invoices?clientId={$dtCid}&statuses[]=1&statuses[]=2&limit=20") ?? [];
    $dtInvs = [];
    foreach ((array)$dtRows as $dtI) {
        if (!is_array($dtI) || (int)($dtI['clientId'] ?? 0) !== $dtCid) continue;
        $dtDue = round((float)($dtI['total'] ?? 0) - (float)($dtI['amountPaid'] ?? 0), 2);
        if ($dtDue <= 0 || !in_array((int)($dtI['status'] ?? 0), [1, 2], true)) continue;
        $dtInvs[] = ['id' => (int)$dtI['id'], 'number' => (string)($dtI['number'] ?? ('#' . (int)$dtI['id'])),
                     'label' => (string)($dtI['items'][0]['label'] ?? 'Service'),
                     'due' => $dtDue, 'currency' => (string)($dtI['currencyCode'] ?? '')];
    }
    $dtList[] = ['id' => $dtCid, 'name' => $dtName !== '' ? $dtName : ('client #' . $dtCid), 'invoices' => $dtInvs];
}

$h = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
header('Content-Type: text/html; charset=utf-8');
?><!doctype html>
<html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>DPO Pay test checkout — DishNet</title>
<style>
:root{--ink:#0F172A;--body:#475569;--muted:#64748B;--line:#E2E8F0;--ground:#F8FAFC;--card:#fff;
 --accent:#1565C0;--wait:#B45309;--waitbg:#FFFBEB;--waitln:#FDE68A;--bad:#B91C1C;--badbg:#FEF2F2;--badln:#FECACA;}
*{box-sizing:border-box}
body{margin:0;background:var(--ground);color:var(--body);min-height:100vh;
 font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;padding:24px 16px;}
.wrap{max-width:560px;margin:0 auto;}
.card{background:var(--card);border:1px solid var(--line);border-radius:14px;padding:24px 22px;
 margin-bottom:16px;box-shadow:0 1px 3px rgba(15,23,42,.06);}
h1{margin:0 0 6px;font-size:20px;font-weight:800;color:var(--ink);}
h2{margin:0 0 12px;font-size:15px;font-weight:800;color:var(--ink);}
.tag{display:inline-block;font-size:11px;font-weight:800;letter-spacing:.5px;padding:3px 8px;border-radius:6px;
 background:var(--waitbg);border:1px solid var(--waitln);color:var(--wait);margin-bottom:10px;}
p{margin:0 0 10px;font-size:14px;line-height:1.55;}
.note{padding:10px 12px;border-radius:9px;font-size:13.5px;line-height:1.5;margin:0 0 14px;}
.note.bad{background:var(--badbg);border:1px solid var(--badln);color:var(--bad);}
.note.wait{background:var(--waitbg);border:1px solid var(--waitln);color:var(--wait);}
.inv{display:flex;justify-content:space-between;align-items:center;gap:12px;padding:12px 0;border-top:1px solid #F1F5F9;}
.inv b{display:block;color:var(--ink);font-size:14px;}
.inv span{font-size:12.5px;color:var(--muted);}
.amt{font-weight:800;color:var(--ink);font-size:15px;white-space:nowrap;}
button{background:var(--accent);color:#fff;border:0;border-radius:9px;padding:10px 14px;font-size:14px;
 font-weight:700;cursor:pointer;white-space:nowrap;}
.small{font-size:12.5px;color:var(--muted);}
</style></head><body><div class="wrap">
<div class="card">
  <span class="tag">TEST</span>
  <h1>DishNet — DPO Pay test checkout</h1>
  <p>This page is for DPO Pay's integration review. It uses DPO's test account, so pay
    with one of DPO's test cards. No real money moves, and only DishNet's test customers
    are listed here.</p>
  <p class="small">After paying, DPO sends you back to DishNet's payment result page, which
    confirms the payment with DPO before it says anything.</p>
</div>

<?php if ($dtError !== ''): ?>
  <div class="note bad"><?= $h($dtError) ?></div>
<?php endif; ?>
<?php if (!$dtReady['enabled']): ?>
  <div class="note wait">Pay Now is switched off on the DPO Pay screen, so a payment cannot be started.</div>
<?php elseif (!$dtReady['ready']): ?>
  <div class="note wait">DPO Pay is not fully set up. Missing: <?= $h(implode(', ', $dtReady['missing'])) ?>.</div>
<?php endif; ?>

<?php if ($dtList === []): ?>
  <div class="card"><p>No test customer is set. On the DPO Pay screen, enter the uCRM client id
    of a test customer.</p></div>
<?php endif; ?>

<?php foreach ($dtList as $dtC): ?>
  <div class="card">
    <h2><?= $h($dtC['name']) ?></h2>
    <?php if ($dtC['invoices'] === []): ?>
      <p class="small">No unpaid invoice. Create one in uCRM for this customer.</p>
    <?php endif; ?>
    <?php foreach ($dtC['invoices'] as $dtI): ?>
      <form method="post" class="inv">
        <div><b><?= $h($dtI['number']) ?></b><span><?= $h($dtI['label']) ?></span></div>
        <div class="amt"><?= $h($dtI['currency']) ?> <?= $h(number_format($dtI['due'], floor($dtI['due']) == $dtI['due'] ? 0 : 2)) ?></div>
        <input type="hidden" name="invoice_id" value="<?= (int)$dtI['id'] ?>">
        <button type="submit">Pay with DPO</button>
      </form>
    <?php endforeach; ?>
  </div>
<?php endforeach; ?>
</div></body></html>
