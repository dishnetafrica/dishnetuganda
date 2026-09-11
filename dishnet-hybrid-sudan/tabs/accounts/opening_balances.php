<?php
/**
 * Opening Balances — accounts and the commencement position of the books.
 *
 * Proper accounting treatment, enforced by CashbookService, not by this page:
 * every account has exactly one currency; an opening balance is one ledger
 * row typed OPENING_BALANCE (never a sale, payment or expense); it can be
 * corrected only while the account has no other activity — after that the
 * position changes via an ADJUSTMENT entry, and history stays honest.
 *
 * Sudan installs simply never open this screen; nothing here runs on its own.
 */

require_once dirname(__DIR__, 2) . '/lib/PluginConfig.php';
require_once dirname(__DIR__, 2) . '/lib/CashbookService.php';

$_obRoot = dirname(__DIR__, 2);
$_obData = $GLOBALS['dataDir'] ?? ($_obRoot . '/data');
$_obCfg  = PluginConfig::load($_obRoot, $_obData);
$_obCb   = new CashbookService($store, $_obData);
$_obCurrs = dn_book_currencies($_obCfg);
$_obStart = trim((string)($_obCfg['books_start_date'] ?? ''));
$_obAdmin = trim((string)($retailer['name'] ?? ($retailer['username'] ?? 'admin')));

$_obMsg = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ob_action'])) {
    if (function_exists('csrfCheck')) csrfCheck();
    try {
        $act = (string)$_POST['ob_action'];
        if ($act === 'seed') {
            $r = $_obCb->seedStandardAccounts();
            $_obMsg = $r['ok']
                ? ['ok' => true, 'text' => 'Created: ' . implode(', ', $r['created'])]
                : ['ok' => false, 'text' => $r['error']];
        } elseif ($act === 'add_account') {
            $r = $_obCb->addAccount((string)($_POST['name'] ?? ''),
                                    (string)($_POST['currency'] ?? ''),
                                    (string)($_POST['kind'] ?? ''));
            $_obMsg = $r['ok'] ? ['ok' => true, 'text' => 'Account created.']
                               : ['ok' => false, 'text' => $r['error']];
        } elseif ($act === 'toggle_account') {
            $_obCb->setAccountActive((int)($_POST['account_id'] ?? 0),
                                     (string)($_POST['to'] ?? '') === '1');
            $_obMsg = ['ok' => true, 'text' => 'Account updated.'];
        } elseif ($act === 'funding') {
            $r = $_obCb->recordFunding(
                (int)($_POST['recv_id'] ?? 0), (int)($_POST['liab_id'] ?? 0),
                (float)str_replace([',', ' '], '', (string)($_POST['amount'] ?? '0')),
                (string)($_POST['date'] ?? ''),
                trim((string)($_POST['description'] ?? '')),
                trim((string)($_POST['reference'] ?? '')),
                $_obAdmin
            );
            $_obMsg = $r['ok']
                ? ['ok' => true, 'text' => 'Funding recorded — ' . $r['ref'] . ' (funds in + payable, linked).']
                : ['ok' => false, 'text' => $r['error']];
        } elseif ($act === 'transfer') {
            $r = $_obCb->recordAccountTransfer(
                (int)($_POST['from_id'] ?? 0), (int)($_POST['to_id'] ?? 0),
                (float)str_replace([',', ' '], '', (string)($_POST['amount_from'] ?? '0')),
                (float)str_replace([',', ' '], '', (string)($_POST['amount_to'] ?? '0')),
                (string)($_POST['date'] ?? ''),
                trim((string)($_POST['description'] ?? '')),
                trim((string)($_POST['rate_source'] ?? '')),
                $_obAdmin
            );
            $_obMsg = $r['ok']
                ? ['ok' => true, 'text' => 'Movement recorded — ' . $r['ref']
                    . ($r['rate'] !== null ? ' (rate ' . number_format($r['rate'], 2) . ', both legs linked)' : ' (both legs linked)')]
                : ['ok' => false, 'text' => $r['error']];
        } elseif ($act === 'opening') {
            $r = $_obCb->recordOpeningBalance(
                (int)($_POST['account_id'] ?? 0),
                (float)str_replace([',', ' '], '', (string)($_POST['amount'] ?? '0')),
                (string)($_POST['as_of'] ?? ''),
                trim((string)($_POST['description'] ?? '')) !== ''
                    ? trim((string)$_POST['description'])
                    : 'Opening balance at commencement of Uganda operations',
                trim((string)($_POST['reference'] ?? '')),
                $_obAdmin
            );
            $_obMsg = $r['ok']
                ? ['ok' => true, 'text' => ($r['updated'] ? 'Opening balance corrected' : 'Opening balance saved') . ' (' . $r['sr'] . ').']
                : ['ok' => false, 'text' => $r['error']];
        }
    } catch (\Throwable $e) {
        $_obMsg = ['ok' => false, 'text' => 'Error: ' . $e->getMessage()];
    }
}

$_obAccounts = $_obCb->accounts();
?>
<div style="max-width:980px;margin:0 auto;padding:16px;font-size:14px;">
  <h2 style="margin:0 0 4px;">Opening Balances</h2>
  <p style="color:#666;margin:0 0 14px;">
    Books start date: <strong><?= $_obStart !== '' ? htmlspecialchars($_obStart) : 'not set' ?></strong>.
    Opening balances are their own transaction type — they never appear as sales, customer payments or expenses.
  </p>

  <?php if ($_obMsg): ?>
    <div style="padding:10px 14px;border-radius:8px;margin-bottom:14px;
                background:<?= $_obMsg['ok'] ? '#ecfdf5' : '#fef2f2' ?>;
                border:1px solid <?= $_obMsg['ok'] ? '#a7f3d0' : '#fecaca' ?>;">
      <?= htmlspecialchars($_obMsg['text']) ?>
    </div>
  <?php endif; ?>

  <?php if (!$_obAccounts): ?>
    <div style="border:1px dashed #d1d5db;border-radius:10px;padding:18px;margin-bottom:14px;text-align:center;">
      <p style="margin:0 0 10px;color:#374151;">No accounts yet.</p>
      <form method="post" style="display:inline;"><?= $_csrf ?? '' ?>
        <button name="ob_action" value="seed" style="padding:10px 18px;border:none;border-radius:8px;background:#141414;color:#fff;font-weight:700;cursor:pointer;">
          Create the standard Uganda accounts
        </button>
      </form>
      <div style="font-size:12px;color:#6b7280;margin-top:8px;">
        Ecobank UGX &amp; USD · Cash · MTN MoMo · Airtel Money · Director/Shareholder Funding
      </div>
    </div>
  <?php endif; ?>

  <!-- Accounts -->
  <div style="border:1px solid #e5e7eb;border-radius:10px;padding:14px;margin-bottom:14px;">
    <h3 style="margin:0 0 10px;">Accounts</h3>
    <div style="overflow-x:auto;">
    <table style="width:100%;border-collapse:collapse;font-size:13px;">
      <tr style="background:#f9fafb;text-align:left;">
        <th style="padding:8px;">Account</th><th style="padding:8px;">Kind</th>
        <th style="padding:8px;">Currency</th><th style="padding:8px;text-align:right;">Balance</th>
        <th style="padding:8px;">Opening</th><th style="padding:8px;"></th>
      </tr>
      <?php foreach ($_obAccounts as $a):
        $bal = $_obCb->accountBalance((int)$a['id']);
        $op  = $_obCb->openingFor((int)$a['id']); ?>
      <tr style="border-top:1px solid #f3f4f6;<?= (int)$a['active'] ? '' : 'opacity:.5;' ?>">
        <td style="padding:8px;font-weight:600;"><?= htmlspecialchars((string)$a['name']) ?></td>
        <td style="padding:8px;"><?= htmlspecialchars((string)$a['kind']) ?></td>
        <td style="padding:8px;"><?= htmlspecialchars((string)$a['currency']) ?></td>
        <td style="padding:8px;text-align:right;font-weight:700;">
          <?= htmlspecialchars((string)$a['currency']) ?> <?= number_format($bal, 2) ?></td>
        <td style="padding:8px;font-size:12px;color:#6b7280;">
          <?= $op ? htmlspecialchars((string)$op['date']) . ' · ' . htmlspecialchars((string)$a['currency']) . ' ' . number_format((float)$op['amount'], 2) : '—' ?>
        </td>
        <td style="padding:8px;">
          <form method="post" style="display:inline;"><?= $_csrf ?? '' ?>
            <input type="hidden" name="account_id" value="<?= (int)$a['id'] ?>">
            <input type="hidden" name="to" value="<?= (int)$a['active'] ? 0 : 1 ?>">
            <button name="ob_action" value="toggle_account" style="border:none;background:none;color:#6b7280;cursor:pointer;font-size:12px;">
              <?= (int)$a['active'] ? 'deactivate' : 'activate' ?></button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
      <?php if (!$_obAccounts): ?>
        <tr><td colspan="6" style="padding:12px;color:#6b7280;">No accounts.</td></tr>
      <?php endif; ?>
    </table>
    </div>
    <details style="margin-top:10px;"><summary style="cursor:pointer;color:#1d4ed8;font-size:13px;">Add another account</summary>
      <form method="post" style="display:flex;gap:8px;flex-wrap:wrap;margin-top:8px;"><?= $_csrf ?? '' ?>
        <input type="text" name="name" placeholder="Account name" required
               style="padding:8px 10px;border:1px solid #d1d5db;border-radius:6px;min-width:220px;">
        <select name="currency" style="padding:8px;border:1px solid #d1d5db;border-radius:6px;">
          <?php foreach ($_obCurrs as $c): ?><option><?= htmlspecialchars($c) ?></option><?php endforeach; ?>
        </select>
        <select name="kind" style="padding:8px;border:1px solid #d1d5db;border-radius:6px;">
          <?php foreach (CashbookService::ACCOUNT_KINDS as $k): ?><option><?= htmlspecialchars($k) ?></option><?php endforeach; ?>
        </select>
        <button name="ob_action" value="add_account" style="padding:8px 16px;border:none;border-radius:6px;background:#141414;color:#fff;font-weight:700;cursor:pointer;">Add</button>
      </form>
    </details>
  </div>

  <!-- Funding received (investment in) -->
  <div style="border:1px solid #e5e7eb;border-radius:10px;padding:14px;margin-bottom:14px;">
    <h3 style="margin:0 0 4px;">Funding received (investor / director money in)</h3>
    <p style="font-size:12px;color:#6b7280;margin:0 0 10px;">
      Two linked legs: the funds land in an account AND a payable of the same currency records
      what the company owes. Never booked as revenue. USD injections need a USD liability
      account (add one above, e.g. "Director Funding – USD").
    </p>
    <form method="post" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:10px;align-items:end;"><?= $_csrf ?? '' ?>
      <label style="font-size:12px;color:#374151;">Money lands in
        <select name="recv_id" required style="width:100%;padding:8px;border:1px solid #d1d5db;border-radius:6px;">
          <option value="">— account —</option>
          <?php foreach ($_obAccounts as $a): if (!(int)$a['active'] || in_array($a['kind'], ['payable','director','equity'], true)) continue; ?>
            <option value="<?= (int)$a['id'] ?>"><?= htmlspecialchars((string)$a['name']) ?> (<?= htmlspecialchars((string)$a['currency']) ?>)</option>
          <?php endforeach; ?>
        </select>
      </label>
      <label style="font-size:12px;color:#374151;">Owed to
        <select name="liab_id" required style="width:100%;padding:8px;border:1px solid #d1d5db;border-radius:6px;">
          <option value="">— liability account —</option>
          <?php foreach ($_obAccounts as $a): if (!(int)$a['active'] || !in_array($a['kind'], ['payable','director','equity'], true)) continue; ?>
            <option value="<?= (int)$a['id'] ?>"><?= htmlspecialchars((string)$a['name']) ?> (<?= htmlspecialchars((string)$a['currency']) ?>)</option>
          <?php endforeach; ?>
        </select>
      </label>
      <label style="font-size:12px;color:#374151;">Amount
        <input type="text" name="amount" required inputmode="decimal" placeholder="0"
               style="width:100%;padding:8px;border:1px solid #d1d5db;border-radius:6px;">
      </label>
      <label style="font-size:12px;color:#374151;">Date
        <input type="date" name="date" value="<?= htmlspecialchars(date('Y-m-d')) ?>" required
               style="width:100%;padding:8px;border:1px solid #d1d5db;border-radius:6px;">
      </label>
      <label style="font-size:12px;color:#374151;">Reference
        <input type="text" name="reference" placeholder="auto (FUND-…)"
               style="width:100%;padding:8px;border:1px solid #d1d5db;border-radius:6px;">
      </label>
      <label style="font-size:12px;color:#374151;grid-column:1/-1;">Description
        <input type="text" name="description" placeholder="Shareholder/director funding received"
               style="width:100%;padding:8px;border:1px solid #d1d5db;border-radius:6px;">
      </label>
      <button name="ob_action" value="funding" style="padding:10px 18px;border:none;border-radius:8px;background:#141414;color:#fff;font-weight:800;cursor:pointer;">
        Record Funding
      </button>
    </form>
  </div>

  <!-- Exchange / transfer between accounts -->
  <div style="border:1px solid #e5e7eb;border-radius:10px;padding:14px;margin-bottom:14px;">
    <h3 style="margin:0 0 4px;">Exchange / transfer between accounts</h3>
    <p style="font-size:12px;color:#6b7280;margin:0 0 10px;">
      Both amounts are entered by you — the system never converts silently. For a currency
      exchange (e.g. USD → UGX) the receiving leg permanently records the original currency,
      original amount, effective rate and your rate source, linked by one FX reference —
      the same chain discipline as the South Sudan books.
    </p>
    <form method="post" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:10px;align-items:end;"><?= $_csrf ?? '' ?>
      <label style="font-size:12px;color:#374151;">From
        <select name="from_id" required style="width:100%;padding:8px;border:1px solid #d1d5db;border-radius:6px;">
          <option value="">— account —</option>
          <?php foreach ($_obAccounts as $a): if (!(int)$a['active']) continue; ?>
            <option value="<?= (int)$a['id'] ?>"><?= htmlspecialchars((string)$a['name']) ?> (<?= htmlspecialchars((string)$a['currency']) ?>)</option>
          <?php endforeach; ?>
        </select>
      </label>
      <label style="font-size:12px;color:#374151;">Amount out
        <input type="text" name="amount_from" required inputmode="decimal" placeholder="e.g. 8,000"
               style="width:100%;padding:8px;border:1px solid #d1d5db;border-radius:6px;">
      </label>
      <label style="font-size:12px;color:#374151;">To
        <select name="to_id" required style="width:100%;padding:8px;border:1px solid #d1d5db;border-radius:6px;">
          <option value="">— account —</option>
          <?php foreach ($_obAccounts as $a): if (!(int)$a['active']) continue; ?>
            <option value="<?= (int)$a['id'] ?>"><?= htmlspecialchars((string)$a['name']) ?> (<?= htmlspecialchars((string)$a['currency']) ?>)</option>
          <?php endforeach; ?>
        </select>
      </label>
      <label style="font-size:12px;color:#374151;">Amount in
        <input type="text" name="amount_to" required inputmode="decimal" placeholder="e.g. 29,760,000"
               style="width:100%;padding:8px;border:1px solid #d1d5db;border-radius:6px;">
      </label>
      <label style="font-size:12px;color:#374151;">Date
        <input type="date" name="date" value="<?= htmlspecialchars(date('Y-m-d')) ?>" required
               style="width:100%;padding:8px;border:1px solid #d1d5db;border-radius:6px;">
      </label>
      <label style="font-size:12px;color:#374151;">Rate source (for FX)
        <input type="text" name="rate_source" placeholder="e.g. Ecobank board rate"
               style="width:100%;padding:8px;border:1px solid #d1d5db;border-radius:6px;">
      </label>
      <label style="font-size:12px;color:#374151;grid-column:1/-1;">Description (optional)
        <input type="text" name="description" placeholder="auto"
               style="width:100%;padding:8px;border:1px solid #d1d5db;border-radius:6px;">
      </label>
      <button name="ob_action" value="transfer" style="padding:10px 18px;border:none;border-radius:8px;background:#141414;color:#fff;font-weight:800;cursor:pointer;">
        Record Movement
      </button>
    </form>
  </div>

  <!-- Opening balance entry -->
  <div style="border:1px solid #e5e7eb;border-radius:10px;padding:14px;">
    <h3 style="margin:0 0 4px;">Save an opening balance</h3>
    <p style="font-size:12px;color:#6b7280;margin:0 0 10px;">
      One per account. Correctable only while the account has no other activity;
      after that, use an ADJUSTMENT entry. For payable/director accounts the amount
      is what the company owes as at the start date.
    </p>
    <form method="post" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px;align-items:end;"><?= $_csrf ?? '' ?>
      <label style="font-size:12px;color:#374151;">Account
        <select name="account_id" required style="width:100%;padding:8px;border:1px solid #d1d5db;border-radius:6px;">
          <option value="">— choose —</option>
          <?php foreach ($_obAccounts as $a): if (!(int)$a['active']) continue; ?>
            <option value="<?= (int)$a['id'] ?>"><?= htmlspecialchars((string)$a['name']) ?> (<?= htmlspecialchars((string)$a['currency']) ?>)</option>
          <?php endforeach; ?>
        </select>
      </label>
      <label style="font-size:12px;color:#374151;">Opening balance
        <input type="text" name="amount" placeholder="0" required inputmode="decimal"
               style="width:100%;padding:8px;border:1px solid #d1d5db;border-radius:6px;">
      </label>
      <label style="font-size:12px;color:#374151;">As of
        <input type="date" name="as_of" value="<?= htmlspecialchars($_obStart) ?>" required
               style="width:100%;padding:8px;border:1px solid #d1d5db;border-radius:6px;">
      </label>
      <label style="font-size:12px;color:#374151;">Reference
        <input type="text" name="reference" placeholder="Bank statement ref…"
               style="width:100%;padding:8px;border:1px solid #d1d5db;border-radius:6px;">
      </label>
      <label style="font-size:12px;color:#374151;grid-column:1/-1;">Description
        <input type="text" name="description" placeholder="Opening balance at commencement of Uganda operations"
               style="width:100%;padding:8px;border:1px solid #d1d5db;border-radius:6px;">
      </label>
      <button name="ob_action" value="opening" style="padding:10px 18px;border:none;border-radius:8px;background:#D41C1C;color:#fff;font-weight:800;cursor:pointer;">
        Save Opening Balance
      </button>
    </form>
  </div>
</div>
