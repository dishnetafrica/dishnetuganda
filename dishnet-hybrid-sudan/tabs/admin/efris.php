<?php
/**
 * EFRIS — Uganda e-invoicing control room (admin only).
 *
 * Everything an operator needs in one place: environment status, manual
 * submission (validate first, then submit), the transaction log with the
 * verbatim EFRIS responses, retry for rejected/errored invoices, the fiscal
 * PDF, and the two operator-maintained mappings (URA commodity codes per
 * item, EFRIS tax category per uCRM tax). Secrets never render here.
 *
 * Phase 1: the plugin submits ONLY in efris_environment=test, to the
 * configured fake/test endpoint. Production refuses by design until the
 * official URA specification and credentials arrive (Phase 2).
 */

require_once dirname(__DIR__, 2) . '/lib/PluginConfig.php';
require_once dirname(__DIR__, 2) . '/lib/CrmApiClient.php';
require_once dirname(__DIR__, 2) . '/lib/EfrisService.php';
require_once dirname(__DIR__, 2) . '/lib/EfrisInvoicePdf.php';

$_efRoot = dirname(__DIR__, 2);
$_efData = $GLOBALS['dataDir'] ?? ($_efRoot . '/data');
$_efCfg  = PluginConfig::load($_efRoot, $_efData);
$_efSvc  = new EfrisService($store, $_efCfg, $_efData);
$_efTx   = $_efSvc->transactions();
$_efEnv  = $_efSvc->environment();
$_csrf   = function_exists('csrfField') ? csrfField() : '';

$_efMsg = null;   // ['ok'=>bool,'text'=>..., 'detail'=>?array]

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ef_action'])) {
    if (function_exists('csrfCheck')) csrfCheck();
    $act = (string)$_POST['ef_action'];
    try {
        if ($act === 'submit' || $act === 'retry' || $act === 'preview') {
            $invId = (int)($_POST['invoice_id'] ?? 0);
            if ($invId <= 0) {
                $_efMsg = ['ok' => false, 'text' => 'Enter a uCRM invoice ID.'];
            } elseif ($act === 'preview') {
                $p = $_efSvc->preview($invId);
                $_efMsg = ['ok' => $p['ok'],
                    'text' => $p['ok'] ? 'Validation passed.' : 'Validation failed.',
                    'detail' => ['errors' => $p['errors'], 'warnings' => $p['warnings'],
                                 'model' => $p['model']]];
            } else {
                $r = $_efSvc->submitInvoice($invId, 'manual', $act === 'retry');
                $_efMsg = ['ok' => $r['ok'],
                    'text' => ($r['duplicate'] ?? false ? 'Duplicate detected: ' : '')
                            . $r['status'] . ' — ' . $r['message']];
            }
        } elseif ($act === 'make_pdf') {
            $invId = (int)($_POST['invoice_id'] ?? 0);
            $p = $_efSvc->preview($invId);
            if (!$p['ok']) {
                $_efMsg = ['ok' => false, 'text' => 'Cannot render: ' . implode('; ', $p['errors'])];
            } else {
                $pdf = new EfrisInvoicePdf($_efData, $_efCfg);
                $out = $pdf->generate($p['model'], $_efTx->find($invId));
                if (isset($out['error'])) {
                    $_efMsg = ['ok' => false, 'text' => $out['error']];
                } else {
                    $f = basename($out['pdf_path']);
                    $sec = (string)($_efCfg['webhook_secret'] ?? ($_efCfg['evo_webhook_secret'] ?? 'dishnet'));
                    $tok = hash_hmac('sha256', $f . gmdate('Ymd'), $sec);
                    $url = '?page=efris_pdf&file=' . rawurlencode($f) . '&token=' . rawurlencode($tok);
                    $_efMsg = ['ok' => true,
                        'text' => 'PDF ready: <a href="' . htmlspecialchars($url) . '" target="_blank">'
                                . htmlspecialchars($out['filename']) . '</a>'];
                }
            }
        } elseif ($act === 'save_maps') {
            $parse = function (string $raw): array {
                $rows = [];
                foreach (preg_split('/\r?\n/', $raw) as $line) {
                    $line = trim($line);
                    if ($line === '' || $line[0] === '#') continue;
                    $eq = strpos($line, '=');
                    if ($eq === false) continue;
                    $rows[] = [trim(substr($line, 0, $eq)), trim(substr($line, $eq + 1))];
                }
                return $rows;
            };
            $com = []; $tax = [];
            foreach ($parse((string)($_POST['commodity_map'] ?? '')) as [$k, $v]) {
                if ($k !== '' && $v !== '') $com[] = ['item' => $k, 'code' => $v];
            }
            $allowedCat = ['standard', 'zero_rated', 'exempt', 'non_taxable', 'other'];
            $bad = [];
            foreach ($parse((string)($_POST['tax_map'] ?? '')) as [$k, $v]) {
                if ($k === '' || $v === '') continue;
                if (!in_array($v, $allowedCat, true)) { $bad[] = "{$k} = {$v}"; continue; }
                $tax[] = ['tax' => $k, 'category' => $v];
            }
            $store->save('efris_commodity_map.json', $com);
            $store->save('efris_tax_map.json', $tax);
            $_efMsg = ['ok' => count($bad) === 0,
                'text' => 'Mappings saved: ' . count($com) . ' commodity code(s), ' . count($tax) . ' tax rule(s).'
                        . ($bad ? ' Ignored (unknown category — allowed: ' . implode(', ', $allowedCat) . '): '
                                . htmlspecialchars(implode('; ', $bad)) : '')];
        }
    } catch (\Throwable $e) {
        $_efMsg = ['ok' => false, 'text' => 'Error: ' . htmlspecialchars($e->getMessage())];
    }
}

// ── Data for the page ───────────────────────────────────────────────────────
$_efCounts = $_efTx->counts();
$_efFilter = [
    'status' => (string)($_GET['ef_status'] ?? ''),
    'q'      => (string)($_GET['ef_q'] ?? ''),
    'from'   => (string)($_GET['ef_from'] ?? ''),
    'to'     => (string)($_GET['ef_to'] ?? ''),
];
$_efRows = $_efTx->recent(array_filter($_efFilter));

$_efComRaw = '';
foreach ((array)$store->load('efris_commodity_map.json') as $r) {
    if (!empty($r['item'])) $_efComRaw .= $r['item'] . ' = ' . ($r['code'] ?? '') . "\n";
}
$_efTaxRaw = '';
foreach ((array)$store->load('efris_tax_map.json') as $r) {
    if (!empty($r['tax'])) $_efTaxRaw .= $r['tax'] . ' = ' . ($r['category'] ?? '') . "\n";
}

$_efStatuses = ['PENDING','SUBMITTED','FISCALISED','REJECTED','ERROR','NEEDS_ADJUSTMENT','CANCELLED','CREDITED','DEBITED'];
$_efBadge = function (string $s): string {
    $map = ['FISCALISED' => '#065f46', 'REJECTED' => '#991b1b', 'ERROR' => '#b45309',
            'NEEDS_ADJUSTMENT' => '#991b1b', 'SUBMITTED' => '#1d4ed8', 'PENDING' => '#6b7280'];
    $c = $map[$s] ?? '#6b7280';
    return '<span style="background:' . $c . ';color:#fff;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:700;">'
         . htmlspecialchars($s) . '</span>';
};
?>
<div style="max-width:1100px;margin:0 auto;padding:16px;font-size:14px;">
  <h2 style="margin:0 0 4px;">EFRIS — Uganda e-Invoicing</h2>
  <p style="color:#666;margin:0 0 14px;">
    uCRM stays the billing truth; this layer fiscalises approved invoices with URA EFRIS.
    A uCRM invoice being PAID and being FISCALISED are separate facts.
  </p>

  <?php if ($_efMsg): ?>
    <div style="padding:10px 14px;border-radius:8px;margin-bottom:14px;
                background:<?= $_efMsg['ok'] ? '#ecfdf5' : '#fef2f2' ?>;
                border:1px solid <?= $_efMsg['ok'] ? '#a7f3d0' : '#fecaca' ?>;">
      <?= $_efMsg['text'] /* trusted: built above, user values escaped there */ ?>
      <?php if (!empty($_efMsg['detail'])): ?>
        <?php foreach (($_efMsg['detail']['errors'] ?? []) as $e): ?>
          <div style="color:#991b1b;font-size:13px;">✗ <?= htmlspecialchars($e) ?></div>
        <?php endforeach; ?>
        <?php foreach (($_efMsg['detail']['warnings'] ?? []) as $w): ?>
          <div style="color:#92400e;font-size:13px;">⚠ <?= htmlspecialchars($w) ?></div>
        <?php endforeach; ?>
        <?php if (!empty($_efMsg['detail']['model'])): ?>
          <details style="margin-top:6px;"><summary style="cursor:pointer;font-size:13px;">Mapped model (internal)</summary>
            <pre style="background:#f8f8f8;padding:10px;border-radius:6px;font-size:11px;overflow:auto;max-height:320px;"><?= htmlspecialchars(json_encode($_efMsg['detail']['model'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></pre>
          </details>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <!-- Status strip -->
  <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:14px;">
    <div style="border:1px solid #e5e7eb;border-radius:8px;padding:10px 14px;">
      <div style="font-size:11px;color:#6b7280;text-transform:uppercase;">Environment</div>
      <strong style="color:<?= $_efEnv === 'test' ? '#b45309' : ($_efEnv === 'production' ? '#065f46' : '#6b7280') ?>;">
        <?= htmlspecialchars(strtoupper($_efEnv)) ?></strong>
      <?php if ($_efEnv === 'production'): ?>
        <div style="font-size:11px;color:#991b1b;">Production connector not built (Phase 2) — submissions refuse.</div>
      <?php elseif ($_efEnv === 'disabled'): ?>
        <div style="font-size:11px;color:#6b7280;">Set efris_environment=test in Configuration to use the test flow.</div>
      <?php endif; ?>
    </div>
    <div style="border:1px solid #e5e7eb;border-radius:8px;padding:10px 14px;">
      <div style="font-size:11px;color:#6b7280;text-transform:uppercase;">Auto-submit</div>
      <strong><?= PluginConfig::toBool($_efCfg['efris_auto_submit'] ?? false) ? 'ON' : 'OFF' ?></strong>
    </div>
    <div style="border:1px solid #e5e7eb;border-radius:8px;padding:10px 14px;">
      <div style="font-size:11px;color:#6b7280;text-transform:uppercase;">Seller TIN</div>
      <strong><?= htmlspecialchars((string)($_efCfg['efris_tin'] ?? '')) ?: '<span style="color:#991b1b">not set</span>' ?></strong>
    </div>
    <div style="border:1px solid #e5e7eb;border-radius:8px;padding:10px 14px;">
      <div style="font-size:11px;color:#6b7280;text-transform:uppercase;">Device No</div>
      <strong><?= trim((string)($_efCfg['efris_device_no'] ?? '')) !== '' ? 'set' : '<span style="color:#991b1b">not set</span>' ?></strong>
    </div>
    <?php foreach ($_efCounts['by_status'] as $s => $c): ?>
      <div style="border:1px solid #e5e7eb;border-radius:8px;padding:10px 14px;">
        <div style="font-size:11px;color:#6b7280;text-transform:uppercase;"><?= htmlspecialchars($s) ?></div>
        <strong><?= (int)$c ?></strong>
      </div>
    <?php endforeach; ?>
  </div>

  <!-- Manual submission -->
  <div style="border:1px solid #e5e7eb;border-radius:8px;padding:14px;margin-bottom:14px;">
    <h3 style="margin:0 0 8px;">Submit an invoice</h3>
    <form method="post" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;"><?= $_csrf ?>
      <input type="number" name="invoice_id" placeholder="uCRM invoice ID" required
             style="padding:8px 10px;border:1px solid #d1d5db;border-radius:6px;width:170px;">
      <button name="ef_action" value="preview"  style="padding:8px 14px;border-radius:6px;border:1px solid #d1d5db;background:#fff;cursor:pointer;">Validate only</button>
      <button name="ef_action" value="submit"   style="padding:8px 14px;border-radius:6px;border:none;background:#141414;color:#fff;cursor:pointer;">Submit to EFRIS</button>
      <button name="ef_action" value="make_pdf" style="padding:8px 14px;border-radius:6px;border:1px solid #d1d5db;background:#fff;cursor:pointer;">Generate PDF</button>
      <span style="font-size:12px;color:#6b7280;">Duplicates are impossible: an already-fiscalised invoice returns its stored fiscal record.</span>
    </form>
  </div>

  <!-- Transactions -->
  <div style="border:1px solid #e5e7eb;border-radius:8px;padding:14px;margin-bottom:14px;">
    <h3 style="margin:0 0 8px;">EFRIS Transactions</h3>
    <form method="get" style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:10px;">
      <input type="hidden" name="page" value="efris">
      <select name="ef_status" style="padding:7px;border:1px solid #d1d5db;border-radius:6px;">
        <option value="">All statuses</option>
        <?php foreach ($_efStatuses as $s): ?>
          <option value="<?= $s ?>" <?= $_efFilter['status'] === $s ? 'selected' : '' ?>><?= $s ?></option>
        <?php endforeach; ?>
      </select>
      <input type="text"  name="ef_q"    value="<?= htmlspecialchars($_efFilter['q']) ?>"    placeholder="Invoice # or customer" style="padding:7px;border:1px solid #d1d5db;border-radius:6px;">
      <input type="date"  name="ef_from" value="<?= htmlspecialchars($_efFilter['from']) ?>" style="padding:7px;border:1px solid #d1d5db;border-radius:6px;">
      <input type="date"  name="ef_to"   value="<?= htmlspecialchars($_efFilter['to']) ?>"   style="padding:7px;border:1px solid #d1d5db;border-radius:6px;">
      <button style="padding:7px 14px;border-radius:6px;border:1px solid #d1d5db;background:#fff;cursor:pointer;">Filter</button>
    </form>
    <div style="overflow-x:auto;">
    <table style="width:100%;border-collapse:collapse;font-size:13px;">
      <tr style="background:#f9fafb;text-align:left;">
        <th style="padding:8px;">Date</th><th style="padding:8px;">Invoice</th>
        <th style="padding:8px;">Customer</th><th style="padding:8px;text-align:right;">Amount</th>
        <th style="padding:8px;">EFRIS Status</th><th style="padding:8px;">FDN</th>
        <th style="padding:8px;">Env</th><th style="padding:8px;">Actions</th>
      </tr>
      <?php if (!$_efRows): ?>
        <tr><td colspan="8" style="padding:14px;color:#6b7280;">No EFRIS transactions yet.</td></tr>
      <?php endif; ?>
      <?php foreach ($_efRows as $r): ?>
      <tr style="border-top:1px solid #f3f4f6;vertical-align:top;">
        <td style="padding:8px;white-space:nowrap;"><?= htmlspecialchars(substr((string)$r['created_at'], 0, 16)) ?></td>
        <td style="padding:8px;"><?= htmlspecialchars((string)$r['invoice_number']) ?><br>
            <span style="color:#9ca3af;font-size:11px;">#<?= (int)$r['ucrm_invoice_id'] ?> · <?= htmlspecialchars((string)$r['kind']) ?></span></td>
        <td style="padding:8px;"><?= htmlspecialchars((string)($r['client_name'] ?? '')) ?></td>
        <td style="padding:8px;text-align:right;"><?= htmlspecialchars((string)($r['currency'] ?? '')) ?> <?= number_format((float)($r['amount'] ?? 0), 0) ?></td>
        <td style="padding:8px;"><?= $_efBadge((string)$r['status']) ?></td>
        <td style="padding:8px;font-family:monospace;font-size:12px;"><?= htmlspecialchars((string)($r['fdn'] ?? '')) ?></td>
        <td style="padding:8px;"><?= htmlspecialchars((string)$r['environment']) ?></td>
        <td style="padding:8px;">
          <details><summary style="cursor:pointer;color:#1d4ed8;">View</summary>
            <div style="font-size:12px;margin-top:6px;max-width:420px;">
              <div><strong>Verification:</strong> <?= htmlspecialchars((string)($r['verification_code'] ?? '')) ?></div>
              <div><strong>QR data:</strong> <?= htmlspecialchars((string)($r['qr_data'] ?? '')) ?></div>
              <div><strong>Submitted:</strong> <?= htmlspecialchars((string)($r['submitted_at'] ?? '')) ?>
                   · <strong>Fiscalised:</strong> <?= htmlspecialchars((string)($r['fiscalised_at'] ?? '')) ?></div>
              <div><strong>Response:</strong> [<?= htmlspecialchars((string)($r['response_code'] ?? '')) ?>]
                   <?= htmlspecialchars((string)($r['response_message'] ?? '')) ?></div>
              <div><strong>Retries:</strong> <?= (int)$r['retry_count'] ?></div>
              <?php if (!empty($r['response_payload'])): ?>
                <details><summary style="cursor:pointer;font-size:11px;">Raw EFRIS response</summary>
                  <pre style="background:#f8f8f8;padding:8px;border-radius:6px;font-size:10px;overflow:auto;max-height:200px;"><?= htmlspecialchars((string)$r['response_payload']) ?></pre>
                </details>
              <?php endif; ?>
            </div>
          </details>
          <?php if (in_array($r['status'], ['REJECTED', 'ERROR'], true)): ?>
            <form method="post" style="display:inline;"><?= $_csrf ?>
              <input type="hidden" name="invoice_id" value="<?= (int)$r['ucrm_invoice_id'] ?>">
              <button name="ef_action" value="retry" style="border:none;background:none;color:#b45309;cursor:pointer;padding:0;font-size:13px;">Retry</button>
            </form>
          <?php endif; ?>
          <form method="post" style="display:inline;margin-left:6px;"><?= $_csrf ?>
            <input type="hidden" name="invoice_id" value="<?= (int)$r['ucrm_invoice_id'] ?>">
            <button name="ef_action" value="make_pdf" style="border:none;background:none;color:#1d4ed8;cursor:pointer;padding:0;font-size:13px;">PDF</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
    </table>
    </div>
  </div>

  <!-- Mappings -->
  <div style="border:1px solid #e5e7eb;border-radius:8px;padding:14px;margin-bottom:14px;">
    <h3 style="margin:0 0 4px;">URA mappings</h3>
    <p style="font-size:12px;color:#6b7280;margin:0 0 10px;">
      Commodity codes come from URA's official goods/services list — enter them here, they are never guessed.
      Tax categories map each uCRM tax to EFRIS terms; the line <code>__no_tax__</code> decides what a
      tax-free line item means (zero_rated, exempt or non_taxable) — your accountant's call.
    </p>
    <form method="post"><?= $_csrf ?>
      <div style="display:flex;gap:14px;flex-wrap:wrap;">
        <label style="flex:1;min-width:320px;font-size:12px;color:#374151;">
          Commodity codes — one per line: <code>uCRM item name = URA code</code>
          <textarea name="commodity_map" rows="8" style="width:100%;font-family:monospace;font-size:12px;border:1px solid #d1d5db;border-radius:6px;padding:8px;"
            placeholder="DishNet Home = <URA commodity code>&#10;Starlink Mini Kit = <URA commodity code>"><?= htmlspecialchars($_efComRaw) ?></textarea>
        </label>
        <label style="flex:1;min-width:320px;font-size:12px;color:#374151;">
          Tax categories — one per line: <code>uCRM tax name or id:N = standard|zero_rated|exempt|non_taxable|other</code>
          <textarea name="tax_map" rows="8" style="width:100%;font-family:monospace;font-size:12px;border:1px solid #d1d5db;border-radius:6px;padding:8px;"
            placeholder="VAT 18% = standard&#10;id:1 = standard&#10;__no_tax__ = exempt"><?= htmlspecialchars($_efTaxRaw) ?></textarea>
        </label>
      </div>
      <button name="ef_action" value="save_maps" style="margin-top:8px;padding:8px 16px;border-radius:6px;border:none;background:#141414;color:#fff;cursor:pointer;">Save mappings</button>
    </form>
  </div>

  <div style="font-size:12px;color:#6b7280;">
    Customer TIN / BRN / NIN live as uCRM client custom attributes (create them once with
    <code>php tools/efris_setup_attributes.php</code>, then edit per client in uCRM).
    Real invoice structure check: <code>php tools/efris_invoice_probe.php latest</code>.
  </div>
</div>
