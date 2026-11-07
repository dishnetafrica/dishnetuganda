<?php
// Tab: my_quotes — 5.18.104 (Uganda, root docs/73 §H.2.11.5, D13): My quotations, the staff page beside the daily list.
//
// The daily list's WhatsApp text carries quote numbers and counts only (C8); this page carries the customers' names.
// Read-only. Own quotations only: a salesperson sees the rows whose owner_staff_id is their own retailers id, whatever
// sales_own_leads_only says; an admin, or a holder of All Leads (LeadVisibility::seesAll), sees every row, with an owner
// column. Shown only while QuoteDailyList::pageOpen() (Uganda and the list on); elsewhere one line says the page does not
// apply, and nothing is read. Every value goes through h(); the page holds no phone — the Inbox link carries the
// conversation's id, and the Inbox shows the number when they call. A row under "Asked us to stop" shows no call prompt.
require_once dirname(__DIR__, 2) . '/lib/QuoteDailyList.php';
require_once dirname(__DIR__, 2) . '/lib/LeadVisibility.php';
$_mqCfg = is_array($config ?? null) ? $config : [];
$_mqDir = isset($dataDir) ? (string)$dataDir : null;
if (!QuoteDailyList::pageOpen($_mqCfg, $_mqDir)) {
    echo '<div class="card" style="padding:20px;max-width:760px;"><h3 style="margin-top:0;">My quotations</h3>'
       . '<p>This page is not in use here.</p></div>';
    return;
}
$_mqMe  = is_array($retailer ?? null) ? $retailer : [];
$_mqAll = LeadVisibility::seesAll($_mqMe, $rbac ?? null);
$_mqId  = (int)($retailerId ?? ($_mqMe['id'] ?? 0));
$_mqRows = []; $_mqErr = false; $_mqNames = [];
if ($_mqAll || $_mqId > 0) {
    try {
        $_mqRows = QuoteDailyList::pageRows($store->getPdo(), $_mqCfg, $_mqDir, $_mqAll ? null : $_mqId, time());
        if ($_mqAll) $_mqNames = QuoteDailyList::staffNames($store->getPdo());
    } catch (\Throwable $e) {
        $_mqErr = true;
        error_log('[my_quotes] 5.18.104: the quotations could not be read (' . get_class($e) . ')');
    }
}
?>
<div class="card" style="padding:20px;">
<h3 style="margin-top:0;">My quotations</h3>
<p style="color:#64748b;font-size:13px;margin-top:0;"><?= $_mqAll
    ? 'Every open quotation, and those answered or stopped this week, with the salesperson each belongs to.'
    : 'Your open quotations, and those answered or stopped this week. The same as your morning WhatsApp list, with the names.' ?></p>
<?php if ($_mqErr): ?>
<p style="color:#b91c1c;">The quotations could not be read just now. Try again in a minute.</p>
<?php elseif ($_mqRows === []): ?>
<p>No open quotations.</p>
<?php else: ?>
<div style="overflow-x:auto;">
<table class="kyc-table" style="width:100%;border-collapse:collapse;font-size:13px;">
<thead><tr>
    <th style="text-align:left;padding:6px;">Quote</th>
    <th style="text-align:left;padding:6px;">Customer</th>
    <th style="text-align:left;padding:6px;">Where it stands</th>
    <th style="text-align:left;padding:6px;">Next step</th>
<?php if ($_mqAll): ?>
    <th style="text-align:left;padding:6px;">Salesperson</th>
<?php endif; ?>
    <th style="text-align:left;padding:6px;"></th>
</tr></thead>
<tbody>
<?php foreach ($_mqRows as $_mqR): ?>
<tr style="border-top:1px solid #e2e8f0;">
    <td style="padding:6px;"><?= h($_mqR['ref']) ?></td>
    <td style="padding:6px;"><?= h($_mqR['name'] !== '' ? $_mqR['name'] : '—') ?></td>
    <td style="padding:6px;"><?= h(QuoteDailyList::PAGE_SECTIONS[$_mqR['section']] ?? '') ?><?php
        if (!empty($_mqR['expiring'])) echo '<br><small>' . h('Expires today') . '</small>';
        if (!empty($_mqR['maybe_sent'])) echo '<br><small>' . h('A reminder may have been sent — check the chat') . '</small>';
        if (!empty($_mqR['unread'])) echo '<br><small>' . h('The reply could not be read here: open WhatsApp') . '</small>'; ?></td>
    <td style="padding:6px;"><?= h($_mqR['next'] !== null ? QuoteDailyList::when((int)$_mqR['next']) : QuoteDailyList::holdWords($_mqR['hold'])) ?></td>
<?php if ($_mqAll): ?>
    <td style="padding:6px;"><?= h($_mqR['owner'] === null ? 'No salesperson' : (($_mqNames[$_mqR['owner']] ?? '') !== '' ? $_mqNames[$_mqR['owner']] : 'Staff account')) ?></td>
<?php endif; ?>
    <td style="padding:6px;"><?php if (!empty($_mqR['stop'])): ?><?= h('Do not message') ?><?php elseif ((int)$_mqR['conv'] > 0): ?><a href="?page=dashboard&tab=whatsapp&subtab=conversations&conv_id=<?= (int)$_mqR['conv'] ?>"><?= h('Open the chat to call') ?></a><?php endif; ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<?php endif; ?>
</div>
