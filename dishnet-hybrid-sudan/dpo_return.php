<?php
declare(strict_types=1);
/**
 * dpo_return.php — where DPO sends the customer's browser back.
 *
 * URL: public.php?page=dpo_return   (and …&cancelled=1 as the BackURL)
 *
 * ── NOTHING HERE IS READ AS A RESULT ────────────────────────────────────
 *
 * DPO puts ?TransactionToken= in the query string. That token is used to find
 * OUR payment row and for nothing else. The page then calls the same
 * verifyAndSettle() the push and the cron call, and renders what the STORED
 * row says afterwards.
 *
 * So a customer who edits the URL, or replays somebody else's, changes
 * nothing: there is no status in the query string to believe, and the screen
 * is a report of the database rather than of the request.
 *
 * Refreshing is safe and expected — people refresh when they are anxious about
 * money. Every refresh re-verifies and re-renders; only the first one can
 * settle anything.
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

$root      = __DIR__;
$dataDir   = getDataDir($root);
$config    = PluginConfig::load($root, $dataDir);
$token     = trim((string)($_GET['TransactionToken'] ?? ''));
$cancelled = !empty($_GET['cancelled']);

$state   = 'failed';      // failed | pending | success | cancelled
$row     = null;
$portal  = dn_plugin_public($config) . '?page=customer_portal';

try {
    $store = SqliteStore::create($dataDir);
    $pdo   = $store->getPdo();
    (new MigrationRunner($pdo, $root . '/migrations'))->run();
    $ps    = new DpoPaymentStore($pdo);

    $row = $token !== '' ? $ps->byToken($token) : null;

    if ($row !== null) {
        if ($cancelled) {
            // BackURL. The customer walked away from DPO's page — but DPO is
            // still the authority on whether money moved, so this is verified
            // like everything else rather than taken at face value.
            $ps->event((int)$row['id'], 'callback_received', 'customer returned via BackURL');
        }
        $crm = CrmApiClient::fromUcrm($root, $config);
        $svc = DpoBootstrap::service($store, $config, $crm,
            function (string $e, string $d) use ($dataDir) {
                if (function_exists('logActivity')) { logActivity($dataDir, $e, 'DPO Pay', $d); }
                else { error_log('[dpo] ' . $e . ' — ' . $d); }
            });
        $svc->verifyAndSettle((string)$row['reference']);
        $row = $ps->byReference((string)$row['reference']);   // re-read: the screen reports the row
    }
} catch (\Throwable $e) {
    error_log('[dpo_return] ' . $e->getMessage());
}

$status = $row === null ? '' : (string)$row['status'];
if ($status === 'SUCCESS')                                   $state = 'success';
elseif (in_array($status, ['CREATED','PENDING','REDIRECTED','QUARANTINED'], true)) $state = 'pending';
elseif ($status === 'CANCELLED' || ($cancelled && $status === ''))                 $state = 'cancelled';

$h  = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$gb = static fn($n, $c): string => $c . ' ' . number_format((float)$n, 0);

header('Content-Type: text/html; charset=utf-8');
// A payment outcome is never a cached page.
header('Cache-Control: no-store, no-cache, must-revalidate');
?><!doctype html>
<html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Payment — DishNet</title>
<style>
:root{--ink:#0F172A;--body:#475569;--muted:#64748B;--line:#E2E8F0;--ground:#F8FAFC;--card:#fff;
 --ok:#047857;--okbg:#ECFDF5;--okln:#A7F3D0;--wait:#B45309;--waitbg:#FFFBEB;--waitln:#FDE68A;
 --bad:#B91C1C;--badbg:#FEF2F2;--badln:#FECACA;--accent:#1565C0;}
*{box-sizing:border-box}
body{margin:0;background:var(--ground);color:var(--body);min-height:100vh;
 font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;
 display:flex;align-items:center;justify-content:center;padding:20px;}
.card{background:var(--card);border:1px solid var(--line);border-radius:14px;padding:30px 26px;
 max-width:420px;width:100%;text-align:center;box-shadow:0 1px 3px rgba(15,23,42,.06);}
.mark{width:56px;height:56px;border-radius:50%;margin:0 auto 18px;display:flex;align-items:center;
 justify-content:center;font-size:26px;font-weight:700;border:2px solid;}
.mark.ok{background:var(--okbg);border-color:var(--okln);color:var(--ok);}
.mark.wait{background:var(--waitbg);border-color:var(--waitln);color:var(--wait);}
.mark.bad{background:var(--badbg);border-color:var(--badln);color:var(--bad);}
h1{margin:0 0 8px;font-size:20px;font-weight:800;color:var(--ink);}
p.lede{margin:0 0 20px;font-size:14.5px;line-height:1.55;}
dl{margin:0 0 22px;text-align:left;border-top:1px solid var(--line);}
.r{display:flex;justify-content:space-between;gap:14px;padding:10px 0;border-bottom:1px solid #F1F5F9;}
dt{font-size:12px;color:var(--muted);text-transform:uppercase;letter-spacing:.4px;font-weight:700;}
dd{margin:0;font-size:14px;color:var(--ink);font-weight:600;text-align:right;word-break:break-all;}
.ref{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:12px;font-weight:500;}
.btns{display:flex;flex-direction:column;gap:9px;}
a.btn{display:block;padding:12px;border-radius:9px;text-decoration:none;font-size:14.5px;font-weight:700;}
a.pri{background:var(--accent);color:#fff;}
a.sec{background:var(--ground);color:var(--ink);border:1px solid var(--line);}
.small{font-size:12.5px;color:var(--muted);margin:16px 0 0;line-height:1.5;}
</style></head><body>
<div class="card">
<?php if ($state === 'success'): ?>
  <div class="mark ok">&check;</div>
  <h1>Payment successful</h1>
  <p class="lede">Thank you. Your payment has been received and applied to your invoice.</p>
  <dl>
    <div class="r"><dt>Invoice</dt><dd><?= $h($row['invoice_number']) ?></dd></div>
    <div class="r"><dt>Amount</dt><dd><?= $h($gb($row['amount'], $row['currency'])) ?></dd></div>
    <div class="r"><dt>Reference</dt><dd class="ref"><?= $h($row['reference']) ?></dd></div>
  </dl>
  <div class="btns">
    <a class="pri" href="<?= $h($portal) ?>&view=invoices">View receipt</a>
    <a class="sec" href="<?= $h($portal) ?>">Return to dashboard</a>
  </div>

<?php elseif ($state === 'pending'): ?>
  <div class="mark wait">&hellip;</div>
  <h1>Payment is being confirmed</h1>
  <!-- Deliberately NOT "successful". Until our server has confirmed it with
       DPO, saying so would be a promise we cannot keep. -->
  <p class="lede">We are confirming this with the payment provider. You can close this
    page — your invoice updates automatically once the payment is confirmed.</p>
  <?php if ($row !== null): ?>
  <dl>
    <div class="r"><dt>Invoice</dt><dd><?= $h($row['invoice_number']) ?></dd></div>
    <div class="r"><dt>Amount</dt><dd><?= $h($gb($row['amount'], $row['currency'])) ?></dd></div>
    <div class="r"><dt>Reference</dt><dd class="ref"><?= $h($row['reference']) ?></dd></div>
  </dl>
  <?php endif; ?>
  <div class="btns"><a class="sec" href="<?= $h($portal) ?>">Return to dashboard</a></div>
  <p class="small">If money has left your account it is safe. Keep the reference above
    and our team can trace it.</p>

<?php elseif ($state === 'cancelled'): ?>
  <div class="mark bad">&times;</div>
  <h1>Payment cancelled</h1>
  <p class="lede">Nothing has been charged. Your invoice is unchanged.</p>
  <div class="btns">
    <a class="pri" href="<?= $h($portal) ?>&view=invoices">Try again</a>
    <a class="sec" href="<?= $h($portal) ?>">Return to dashboard</a>
  </div>

<?php else: ?>
  <div class="mark bad">&times;</div>
  <h1>Payment was not completed</h1>
  <p class="lede">Nothing has been charged. Your invoice is unchanged, and you can try again.</p>
  <div class="btns">
    <a class="pri" href="<?= $h($portal) ?>&view=invoices">Try again</a>
    <a class="sec" href="<?= $h($portal) ?>">Return to dashboard</a>
  </div>
<?php endif; ?>
</div></body></html>
