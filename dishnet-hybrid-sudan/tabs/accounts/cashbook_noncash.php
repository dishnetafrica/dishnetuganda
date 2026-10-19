<?php
// ── 5.18.98 (SPEC-separate-v2 §2.5): the Cashbook's "Bank & other accounts" tab ─────────────────────────────────────
// Required by cashbook.php only under rule A (the Uganda tenant AND a book without SSP), for a visitor who is not a
// field agent. The rows are the page's own $ledger, read with book=noncash and the button in view ($_cbNcSel), so the
// page's counter, pager and rows follow one predicate. Read-only: no per-row menu, no row selection, no bulk form, no
// handover banner and no entry form here. No balance and no total of any kind: the book holds only part of Ecobank's
// history, so a sum of these rows is not Ecobank's balance; Ecobank's own figures are on ECO Bank. Every value printed
// is escaped.
$_cbNcEsc    = function ($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };
$_cbNcLabels = $cb->accountLabels();
$_cbNcChip   = null;
foreach ($_cbNcChips as $_cbNcC) {
    if ($_cbNcC['key'] === $_cbNcSel) { $_cbNcChip = $_cbNcC; break; }
}
// ECO Bank opens on this account's lines only when ECO Bank offers it: a bank account, active, in one of the book's
// currencies (the rule of BankStatementStore's offered accounts). Any other button links to ECO Bank as it opens.
$_cbNcBkQ = ['page' => 'dashboard', 'tab' => 'bank'];
if ($_cbNcChip !== null && is_int($_cbNcChip['key']) && $_cbNcChip['active']
    && in_array(strtoupper(trim((string)$_cbNcChip['currency'])), $_cbCurrs, true)) {
    $_cbNcBkQ['bk_acct'] = $_cbNcChip['key'];
}
$_cbNcHome   = ['page' => 'dashboard', 'tab' => 'cashbook', 'cb_proj' => $proj, 'cb_view' => 'noncash'];
$_cbNcExport = array_filter(['page' => 'dashboard', 'tab' => 'cashbook', 'cb_export' => 'csv', 'cb_proj' => $proj,
    'cb_book' => 'noncash', 'cb_acct' => (string)$_cbNcSel, 'cb_from' => $dateFrom, 'cb_to' => $dateTo, 'cb_q' => $search]);
$_cbNcSrc = function (string $src): string {
    if ($src === 'crm_api_sync' || $src === 'crm_webhook') return '<span style="background:#dbeafe;color:#1e40af;padding:1px 6px;border-radius:10px;font-size:9px;font-weight:800;">&#127760;CRM</span>';
    if ($src === 'collect_payment' || $src === 'crm_sync') return '<span style="background:#dcfce7;color:#15803d;padding:1px 6px;border-radius:10px;font-size:9px;font-weight:800;">&#128241;PWA</span>';
    if ($src === 'field_exchange') return '<span style="background:#fef3c7;color:#92400e;padding:1px 6px;border-radius:10px;font-size:9px;font-weight:800;">&#128177;Auto</span>';
    if ($src === 'expense_sync') return '<span style="background:#f5f3ff;color:#6d28d9;padding:1px 6px;border-radius:10px;font-size:9px;font-weight:800;">&#128184;Exp</span>';
    if ($src === 'bank_import') return '<span style="background:#f1f5f9;color:#334155;padding:1px 6px;border-radius:10px;font-size:9px;font-weight:800;">&#127974;Import</span>';
    return '<span style="color:#cbd5e1;font-size:10px;">&#9998;</span>';
};
?>
<!-- cb-noncash:start -->
<div style="margin:12px 14px 0;background:#f1f5f9;border:1px solid #cbd5e1;border-radius:10px;padding:12px 16px;font-size:12px;line-height:1.5;color:#334155;">
  <div style="font-weight:800;margin-bottom:4px;">Not physical cash.</div>
  <div>These entries are on Ecobank, stock, supplier, director, share-capital and other non-cash accounts. They are kept exactly as they were, but they are not in the Cashbook list, its export or cash in hand. No balance is worked out here: the book holds only part of Ecobank's history, so a total of these entries is not Ecobank's balance.</div>
<?php require_once dirname(__DIR__, 2) . '/lib/BankGate.php';
      if (BankGate::showLink($dataDir ?? null, $store ?? null, is_array($retailer ?? null) ? $retailer : [])): ?>
  <div style="margin-top:6px;"><a href="?<?php echo http_build_query($_cbNcBkQ); ?>" style="color:#334155;font-weight:700;">Ecobank's own balances, UGX and USD each on its own: open ECO Bank &rarr;</a></div>
<?php else: ?>
  <div style="margin-top:6px;color:#64748b;">An administrator can see Ecobank's own balances under ECO Bank.</div>
<?php endif; ?>
</div>

<!-- ── The accounts: one button each, then Other accounts; no "All" ── -->
<div style="padding:12px 14px;background:#fff;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
<?php foreach ($_cbNcChips as $_cbNcC):
    $_cbNcOn  = $_cbNcC['key'] === $_cbNcSel;
    $_cbNcTxt = $_cbNcC['key'] === 'other'
        ? 'Other accounts'
        : CashbookNonCashView::flag(strtoupper(trim((string)$_cbNcC['currency']))) . ' ' . $_cbNcC['name']; ?>
  <a href="?<?php echo http_build_query(array_merge($_GET, ['cb_acct' => (string)$_cbNcC['key'], 'cb_page' => 1])); ?>" data-cb-nc-acct="<?php echo $_cbNcEsc($_cbNcC['key']); ?>"
     style="padding:8px 14px;border-radius:20px;font-size:13px;font-weight:700;text-decoration:none;white-space:nowrap;border:1.5px solid;<?php echo $_cbNcOn ? 'background:#334155;color:#fff;border-color:#334155;' : 'background:#fff;color:#374151;border-color:#e2e8f0;'; ?>"><?php echo $_cbNcEsc($_cbNcTxt); ?> <span style="font-size:11px;font-weight:800;opacity:.75;">(<?php echo $_cbNcEsc(number_format($_cbNcC['count'])); ?>)</span></a>
<?php endforeach; ?>
</div>

<!-- ── Dates, search, clear, export ── -->
<div style="padding:10px 14px;background:#fff;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
  <input type="date" value="<?php echo $_cbNcEsc($dateFrom); ?>" onchange="cbv2F('cb_from',this.value)"
    style="padding:7px 10px;border:1.5px solid #e2e8f0;border-radius:10px;font-size:12px;font-family:inherit;color:#374151;min-width:120px;">
  <input type="date" value="<?php echo $_cbNcEsc($dateTo); ?>" onchange="cbv2F('cb_to',this.value)"
    style="padding:7px 10px;border:1.5px solid #e2e8f0;border-radius:10px;font-size:12px;font-family:inherit;color:#374151;min-width:120px;">
  <input type="text" class="cb3-search-inp" placeholder="&#128269; Search description, SR, person&hellip;"
    value="<?php echo $_cbNcEsc($search); ?>" oninput="cbv2FD('cb_q',this.value)" style="flex:1;min-width:160px;">
<?php if ($dateFrom !== '' || $dateTo !== '' || $search !== ''): ?>
  <a href="?<?php echo http_build_query($_cbNcHome); ?>"
    style="padding:6px 12px;background:#f1f5f9;color:#334155;border-radius:7px;font-size:11px;font-weight:800;text-decoration:none;white-space:nowrap;">&#10005; Clear</a>
<?php endif; ?>
  <a href="?<?php echo http_build_query($_cbNcExport); ?>"
    style="padding:8px 16px;background:#334155;color:#fff;border:none;border-radius:10px;font-size:13px;font-weight:700;text-decoration:none;white-space:nowrap;">&darr; CSV</a>
</div>

<?php if (empty($ledger)): ?>
<div class="cb3-empty">No entries on this account match.</div>
<?php else: ?>

<!-- ══ MOBILE: Card layout (read-only) ══ -->
<div class="cb3-cards">
<?php foreach ($ledger as $e):
    $_cbNcIn   = ($e['direction'] ?? '') === 'in';
    $_cbNcVoid = in_array($e['status'] ?? '', ['voided', 'voided_reconcile'], true); ?>
<div class="cb3-card"<?php if ($_cbNcVoid): ?> style="opacity:.5;text-decoration:line-through;"<?php endif; ?> data-id="<?php echo $_cbNcEsc($e['id']); ?>">
  <div class="cb3-card-body">
    <div class="cb3-card-top">
      <div class="cb3-card-desc" title="<?php echo $_cbNcEsc($e['description'] ?? ''); ?>"><?php echo $_cbNcEsc(mb_strimwidth((string)($e['description'] ?? ''), 0, 55, '…')); ?></div>
      <div class="cb3-card-amt <?php echo $_cbNcIn ? 'in' : 'out'; ?>"><?php echo ($_cbNcIn ? '+' : '-') . $_cbRowMoney($e); ?></div>
    </div>
    <div class="cb3-card-meta">
      <span class="cb3-card-date"><?php echo $_cbNcEsc($e['date'] ?? ''); ?></span>
      <span class="cb3-card-cat"><?php echo CashbookNonCashView::label($e['account_id'] ?? null, $_cbNcLabels); ?></span>
      <span class="cb3-card-cat"><?php echo cbCatIcon((string)($e['category'] ?? '')); ?> <?php echo $_cbNcEsc($e['category'] ?? ''); ?></span>
      <span style="background:#f1f5f9;color:#64748b;border-radius:10px;padding:1px 6px;font-size:9px;font-weight:800;"><?php echo $_cbNcEsc(CashbookNonCashView::state((string)($e['status'] ?? ''))); ?></span>
      <span class="cb3-card-sr"><?php echo $_cbNcEsc($e['sr'] ?? ''); ?></span>
      <?php if ((string)($e['validation_ref'] ?? '') !== ''): ?><span class="cb3-card-sr"><?php echo $_cbNcEsc(mb_strimwidth((string)($e['validation_ref'] ?? ''), 0, 20, '…')); ?></span><?php endif; ?>
      <?php echo $_cbNcSrc((string)($e['source'] ?? 'manual')); ?>
    </div>
  </div>
</div>
<?php endforeach; ?>
</div>

<!-- ══ DESKTOP: Table layout (read-only) ══ -->
<div class="cb3-tbl-wrap">
<table class="cbv2-tbl">
  <thead><tr>
    <th>Date</th><th>SR</th><th>Particulars</th><th>Account</th><th>Category</th>
    <th style="color:var(--green);">In &darr;</th><th style="color:#dc2626;">Out &uarr;</th><th>Ref</th><th>State</th><th>Src</th>
  </tr></thead>
  <tbody>
<?php foreach ($ledger as $e):
    $_cbNcIn   = ($e['direction'] ?? '') === 'in';
    $_cbNcVoid = in_array($e['status'] ?? '', ['voided', 'voided_reconcile'], true); ?>
  <tr<?php if ($_cbNcVoid): ?> style="opacity:.5;text-decoration:line-through;background:#fafafa;"<?php endif; ?> data-id="<?php echo $_cbNcEsc($e['id']); ?>">
    <td style="font-family:monospace;font-size:10px;white-space:nowrap;"><?php echo $_cbNcEsc($e['date'] ?? ''); ?></td>
    <td style="font-family:monospace;font-size:10px;color:#94a3b8;"><?php echo $_cbNcEsc($e['sr'] ?? ''); ?></td>
    <td style="max-width:280px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-weight:500;" title="<?php echo $_cbNcEsc($e['description'] ?? ''); ?>"><?php echo $_cbNcEsc(mb_strimwidth((string)($e['description'] ?? ''), 0, 65, '…')); ?></td>
    <td style="font-size:11px;"><?php echo CashbookNonCashView::label($e['account_id'] ?? null, $_cbNcLabels); ?></td>
    <td style="font-size:11px;"><?php echo cbCatIcon((string)($e['category'] ?? '')); ?> <?php echo $_cbNcEsc($e['category'] ?? ''); ?></td>
    <td class="cbv2-in"><?php echo $_cbNcIn ? $_cbRowMoney($e) : ''; ?></td>
    <td class="cbv2-out"><?php echo $_cbNcIn ? '' : $_cbRowMoney($e); ?></td>
    <td style="font-size:10px;color:#94a3b8;max-width:100px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?php echo $_cbNcEsc(mb_strimwidth((string)($e['validation_ref'] ?? ''), 0, 20, '…')); ?></td>
    <td style="font-size:11px;color:#475569;"><?php echo $_cbNcEsc(CashbookNonCashView::state((string)($e['status'] ?? ''))); ?></td>
    <td><?php echo $_cbNcSrc((string)($e['source'] ?? 'manual')); ?></td>
  </tr>
<?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif; ?>

<!-- ══ PAGER ══ -->
<div class="cb3-pager">
  <div class="cb3-pg-info"><?php echo number_format($offset+1); ?>–<?php echo number_format(min($offset+$perPage,$totalRows)); ?> of <?php echo number_format($totalRows); ?></div>
  <div class="cb3-pg-btns">
    <?php if($page>1): ?>
    <a href="?<?php echo http_build_query(array_merge($_GET,['cb_page'=>1])); ?>" class="cb3-pg">«</a>
    <a href="?<?php echo http_build_query(array_merge($_GET,['cb_page'=>$page-1])); ?>" class="cb3-pg">‹</a>
    <?php endif; ?>
    <?php for($p2=max(1,$page-2);$p2<=min($totalPages,$page+2);$p2++): ?>
    <a href="?<?php echo http_build_query(array_merge($_GET,['cb_page'=>$p2])); ?>" class="cb3-pg <?php echo $p2===$page?'on':''; ?>"><?php echo $p2; ?></a>
    <?php endfor; ?>
    <?php if($page<$totalPages): ?>
    <a href="?<?php echo http_build_query(array_merge($_GET,['cb_page'=>$page+1])); ?>" class="cb3-pg">›</a>
    <a href="?<?php echo http_build_query(array_merge($_GET,['cb_page'=>$totalPages])); ?>" class="cb3-pg">»</a>
    <?php endif; ?>
  </div>
</div>
<!-- cb-noncash:end -->
