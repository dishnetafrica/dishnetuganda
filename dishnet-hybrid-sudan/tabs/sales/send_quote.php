<?php
// ── Send Quotation Tab — DishNet Hybrid v4.4.20 ───────────────────────────────
// Flows: B (lead quote), C (cash proforma), D (manual/any customer)
// Requires: $store, $dataDir, $retailer, $isAdmin, $userRole

if (!function_exists('str_contains')) { function str_contains(string $h, string $n): bool { return $n===''||strpos($h,$n)!==false; } }
if (!function_exists('str_starts_with')) { function str_starts_with(string $h, string $n): bool { return $n===''||strncmp($h,$n,strlen($n))===0; } }

require_once __DIR__ . '/../../lib/QuotationService.php';

$allowedRoles = ['sales', 'accountant', 'admin', 'field_accountant'];
if (!$isAdmin && !in_array($userRole ?? '', $allowedRoles)) {
    echo '<div style="padding:40px;text-align:center;color:#dc2626;">⛔ Access denied.</div>';
    return;
}

$cfg     = $store->load('kyc_config.json') ?: [];
$quotSvc = new QuotationService($store, $dataDir, $cfg);

// Load plans for item picker autocomplete
$plans = $store->load('subscription_plans.json') ?? [];
$plansJson = json_encode(array_values(array_map(fn($p) => [
    'id'    => $p['id'] ?? 0,
    'name'  => $p['name'] ?? '',
    'price' => (float)($p['customer_price'] ?? $p['amount'] ?? 0),
    'unit'  => 'month',
], array_filter($plans, fn($p) => (float)($p['customer_price'] ?? $p['amount'] ?? 0) > 0))));

// Load leads for lead picker
$leads = $store->load('leads.json') ?? [];
// 5.18.89 (docs/65 §AA, D7): own leads only, when sales_own_leads_only is ON — the picker offers only the leads this
// viewer may see. Nothing on this page saves the list. Off, or for an admin or All Leads holder, unchanged.
require_once dirname(__DIR__, 2) . '/lib/LeadVisibility.php';
$leads = LeadVisibility::filter($leads, (array)($retailer ?? []), (array)($config ?? []), $dataDir ?? null, $rbac ?? null);
$openLeads = array_values(array_filter($leads, fn($l) =>
    in_array($l['status'] ?? 'open', ['open','contacted','interested','qualified'], true) &&
    !empty($l['phone'])
));

// 5.18.102 (Uganda, docs/71 §J): the picker's prices are uCRM's — the plan mirrors, through the resolver — never
// customer_price; a plan uCRM does not price is listed without a price and cannot be picked into a line. The lead
// pre-fill matches a lead's interest plan by its exact key (PublicPriceFeed::planKey) against the map built here, or
// adds no line. Where the gate is off (South Sudan, or ucrm_quote_organization_id 0/off) none of it runs.
require_once dirname(__DIR__, 2) . '/lib/QuoteOrganization.php';   // 5.18.102: the file that names the class loads it
$ugQuote = !empty(QuoteOrganization::setting($cfg, $dataDir)['on']);
// 5.18.103 (Uganda, root docs/72 §G.9): with the uCRM catalogue's switch on ("direct mode"), the lines are picked from
// uCRM's own plans, hardware and installation (QuotationService::directQuotePicker(); null in any other state), and
// 5.18.102's picker below is not read: the plugin's plans are not offered, so a typed line suggests nothing. The forms post
// their marker, and the send reads every picked line from uCRM again (QuotationService::directLines()). With the switch
// off, or the gate off, $sqDirect is null and the page is 5.18.102's.
$sqDirect = $ugQuote ? $quotSvc->directQuotePicker($openLeads) : null;
if ($sqDirect !== null) {
    $sqJsonFlags  = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE;
    $sqDirectJson = json_encode(['lead_keys' => (object)$sqDirect['lead_keys']] + $sqDirect, $sqJsonFlags)
        ?: '{"ok":false,"reason":"","stale":false,"max_qty":99,"plans":[],"hardware":[],"install":{"ok":false},"standards":[],"lead_keys":{}}';
    $plansJson    = '[]';   // 5.18.102's lead pre-fill and datalist read these; in direct mode they offer nothing
    $ugLeadPlans  = '{}';
    $ugLeadKeys   = '{}';
    $ugLeadShared = '[]';
} else   // 5.18.103: not direct mode — 5.18.102's picker, exactly
if ($ugQuote) {
    $ugPick      = $quotSvc->quotePlanPicker($openLeads);
    $ugJsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE;
    $plansJson   = json_encode($ugPick['plans'], $ugJsonFlags) ?: '[]';
    $ugLeadPlans = json_encode((object)$ugPick['by_key'], $ugJsonFlags) ?: '{}';
    $ugLeadKeys  = json_encode((object)$ugPick['lead_keys'], $ugJsonFlags) ?: '{}';
    // 5.18.102 D14 review (D14-UI-1): the keys two or more plan rows hold, so the hint names that cause
    $ugLeadShared = json_encode(array_values($ugPick['shared_keys']), $ugJsonFlags) ?: '[]';
}

// Flash message from POST redirect
$flash = '';
$flashType = 'success';
if (!empty($_GET['flash'])) {
    $flash     = urldecode($_GET['flash']);
    $flashType = $_GET['ft'] ?? 'success';
}

// Active sub-tab: lead | cash | manual
$subTab = $_GET['qmode'] ?? 'lead';
?>

<style>
.sq-card { background:#fff; border-radius:12px; border:1px solid #e5e7eb; padding:24px; max-width:640px; }
.sq-label { display:block; font-size:0.83rem; font-weight:600; color:#374151; margin-bottom:4px; }
.sq-input { width:100%; padding:9px 12px; border:1px solid #d1d5db; border-radius:8px; font-size:0.9rem; box-sizing:border-box; }
.sq-input:focus { outline:none; border-color:#6366f1; box-shadow:0 0 0 3px rgba(99,102,241,0.12); }
.sq-row { display:flex; gap:10px; margin-bottom:14px; }
.sq-row > * { flex:1; }
.sq-btn { padding:10px 22px; border:none; border-radius:8px; cursor:pointer; font-size:0.9rem; font-weight:600; }
.sq-btn-primary { background:#6366f1; color:#fff; }
.sq-btn-primary:hover { background:#4f46e5; }
.sq-btn-ghost { background:#f3f4f6; color:#374151; }
.sq-btn-ghost:hover { background:#e5e7eb; }
.sq-btn-green { background:#10b981; color:#fff; }
.sq-btn-sm { padding:5px 12px; font-size:0.82rem; border-radius:6px; }
.sq-item-row { display:flex; gap:8px; align-items:center; margin-bottom:8px; background:#f9fafb; border-radius:8px; padding:8px 10px; }
.sq-item-row input { flex:1; padding:6px 9px; border:1px solid #d1d5db; border-radius:6px; font-size:0.85rem; }
.sq-item-row input.qty { max-width:58px; }
.sq-item-row input.price { max-width:90px; }
.sq-item-row input.unit { max-width:80px; }
.sq-remove { background:none; border:none; color:#ef4444; font-size:1.1rem; cursor:pointer; padding:0 4px; }
.sq-total-bar { background:#f0fdf4; border:1px solid #6ee7b7; border-radius:8px; padding:12px 16px; display:flex; justify-content:space-between; align-items:center; margin:12px 0; }
.sq-tab-pill { display:inline-block; padding:8px 18px; border-radius:30px; font-size:0.88rem; font-weight:600; text-decoration:none; color:#6b7280; border:2px solid transparent; }
.sq-tab-pill.active { background:#6366f1; color:#fff; }
.sq-section-title { font-size:0.75rem; text-transform:uppercase; letter-spacing:0.05em; color:#9ca3af; font-weight:700; margin:16px 0 8px; }
.sq-preview-box { background:#1a1a1a; color:#e5e7eb; border-radius:10px; padding:16px; font-size:0.82rem; font-family:monospace; white-space:pre-wrap; max-height:380px; overflow-y:auto; display:none; }
.sq-badge { display:inline-block; padding:1px 8px; border-radius:12px; font-size:0.75rem; font-weight:700; }
</style>

<div style="padding:24px 20px;">

  <!-- Header -->
  <div style="margin-bottom:20px;">
    <h2 style="margin:0;font-size:1.3rem;font-weight:700;color:#111827;">📄 Send Quotation</h2>
    <p style="margin:4px 0 0;font-size:0.85rem;color:#6b7280;">Build a proforma and send via WhatsApp (+ UCRM email for registered customers).</p>
  </div>

  <?php if ($flash): ?>
  <div style="padding:12px 16px;border-radius:8px;margin-bottom:16px;<?= $flashType==='success' ? 'background:#ecfdf5;color:#065f46;border:1px solid #6ee7b7;' : 'background:#fef2f2;color:#991b1b;border:1px solid #fca5a5;' ?>">
    <?= htmlspecialchars($flash) ?>
  </div>
  <?php endif; ?>
<?php if ($sqDirect === null): /* 5.18.103 (root docs/72 §G.9): 5.18.102's banner, drawn only outside direct mode */ ?>
<?php if ($ugQuote && empty($ugPick['ok'])): /* 5.18.102 (Uganda, docs/71 §J): uCRM's products could not be read for the picker */ ?>
  <div style="padding:12px 16px;border-radius:8px;margin-bottom:16px;background:#fffbeb;color:#92400e;border:1px solid #fcd34d;">
    ⚠️ <?= htmlspecialchars((string)($ugPick['reason'] ?: "uCRM's products could not be read")) ?>, so the plan list shows no prices and a lead's plan is not filled in. Type each item and its price yourself, or reload in a minute.
  </div>
<?php endif; ?>
<?php elseif (!$sqDirect['ok']): /* 5.18.103 (§G.9): direct mode, and uCRM could not be read, with no last good list */ ?>
  <div style="padding:12px 16px;border-radius:8px;margin-bottom:16px;background:#fffbeb;color:#92400e;border:1px solid #fcd34d;">
    ⚠️ <?= htmlspecialchars((string)($sqDirect['reason'] ?: "uCRM's products could not be read"), ENT_QUOTES) ?>, so nothing can be picked from uCRM. Type each item and its price yourself, or reload in a minute.
  </div>
<?php elseif ($sqDirect['stale']): /* 5.18.103 (§G.5, §G.9): direct mode, drawn from the last good list */ ?>
  <div style="padding:12px 16px;border-radius:8px;margin-bottom:16px;background:#fffbeb;color:#92400e;border:1px solid #fcd34d;">
    ⚠️ uCRM could not be read just now, so the uCRM lists below are the last ones read and may be out of date. Every line picked from uCRM is checked against uCRM again when you send: if its price changed, nothing is sent and you reload.
  </div>
<?php endif; ?>
<?php if ($sqDirect !== null): /* 5.18.103 (root docs/72 §G.9): the uCRM pickers the lead and manual forms draw in direct mode */
    $sqCur   = html_entity_decode(trim(dn_cur($config ?? null)), ENT_QUOTES, 'UTF-8');   // dn_cur() escapes; $sqH escapes once
    $sqMoney = function ($v): string { $v = (float)$v; return number_format($v, abs($v - round($v)) < 0.0001 ? 0 : 2); };
    $sqPickers = function (string $ctx) use ($sqDirect, $sqCur, $sqMoney): void {
        $sqH = function ($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };
        $groups = ['Kits' => [], 'Accessories' => []];
        foreach ($sqDirect['hardware'] as $x) $groups[$x['group'] === 'accessory' ? 'Accessories' : 'Kits'][] = $x;
        $inst = $sqDirect['install'];
?>
      <div class="sq-direct" style="margin:0 0 12px;padding:10px 12px;border:1px solid #c7d2fe;background:#eef2ff;border-radius:8px;">
<?php if ($sqDirect['standards'] !== []): ?>
        <div class="sq-label">Standard quotations — one click fills the lines</div>
        <div style="display:flex;flex-wrap:wrap;gap:6px;margin-bottom:8px;">
<?php foreach ($sqDirect['standards'] as $i => $s): ?>
          <button type="button" class="sq-btn sq-btn-ghost sq-btn-sm" onclick="sqDirectStandard('<?= $sqH($ctx) ?>', <?= (int)$i ?>)"<?= $s['ok'] ? '' : ' disabled' ?>><?= $sqH($s['ok'] ? $s['title'] . ' — ' . $sqCur . ' ' . $sqMoney($s['total']) : $s['title']) ?></button>
<?php endforeach; ?>
        </div>
<?php foreach ($sqDirect['standards'] as $s): if ($s['ok']) continue; ?>
        <div style="font-size:0.78rem;color:#92400e;margin:-4px 0 8px;"><?= $sqH($s['title']) ?> cannot be used: <?= $sqH($s['reason']) ?></div>
<?php endforeach; ?>
<?php endif; ?>
<?php if ($sqDirect['ok']): ?>
        <select class="sq-input" style="margin-bottom:8px;" onchange="sqDirectPickPlan('<?= $sqH($ctx) ?>', this)">
          <option value="">— Add a plan from uCRM —</option>
<?php foreach ($sqDirect['plans'] as $pl): ?>
          <option value="<?= (int)$pl['sp'] ?>:<?= (int)$pl['p'] ?>"><?= $sqH($pl['label'] . ' — ' . $sqCur . ' ' . $sqMoney($pl['price']) . ' / ' . $pl['unit'] . ($pl['speed'] === '' ? '' : ' · ' . $pl['speed'] . (is_numeric($pl['speed']) ? ' Mbps' : ''))) ?></option>
<?php endforeach; ?>
        </select>
        <select class="sq-input" onchange="sqDirectPickHw('<?= $sqH($ctx) ?>', this)">
          <option value="">— Add hardware or the installation from uCRM —</option>
<?php foreach ($groups as $gName => $rows): if ($rows === []) continue; ?>
          <optgroup label="<?= $sqH($gName) ?>">
<?php foreach ($rows as $hw): ?>
            <option value="item:<?= (int)$hw['p'] ?>"><?= $sqH($hw['label'] . ' — ' . $sqCur . ' ' . $sqMoney($hw['price']) . ' / ' . $hw['unit']) ?></option>
<?php endforeach; ?>
          </optgroup>
<?php endforeach; ?>
          <optgroup label="Installation">
<?php if ($inst['ok']): ?>
            <option value="install:<?= (int)$inst['p'] ?>"><?= $sqH($inst['label'] . ' — ' . $sqCur . ' ' . $sqMoney($inst['price']) . ' / ' . $inst['unit']) ?></option>
<?php else: ?>
            <option value="" disabled>Not available: <?= $sqH($inst['reason']) ?></option>
<?php endif; ?>
          </optgroup>
        </select>
<?php else: ?>
        <div style="font-size:0.82rem;color:#92400e;">Nothing can be picked from uCRM right now — type the items yourself.</div>
<?php endif; ?>
        <div style="font-size:0.75rem;color:#6b7280;margin-top:6px;">A line picked from uCRM goes at uCRM's name and price — only its quantity (1–<?= (int)$sqDirect['max_qty'] ?>) can be changed — and is checked against uCRM again when you send. A line typed by hand is marked "typed — not from uCRM".</div>
      </div>
<?php }; endif; ?>

  <!-- Sub-tab pills -->
  <div style="display:flex;gap:8px;margin-bottom:24px;flex-wrap:wrap;">
    <a href="?page=dashboard&tab=send_quote&qmode=lead"   class="sq-tab-pill <?= $subTab==='lead'   ? 'active' : '' ?>">🎯 Lead Quote</a>
    <a href="?page=dashboard&tab=send_quote&qmode=cash"   class="sq-tab-pill <?= $subTab==='cash'   ? 'active' : '' ?>">💵 Cash Proforma</a>
    <a href="?page=dashboard&tab=send_quote&qmode=manual" class="sq-tab-pill <?= $subTab==='manual' ? 'active' : '' ?>">✏️ Manual Quote</a>
  </div>

  <!-- ════════ FLOW B: LEAD QUOTE ════════ -->
  <?php if ($subTab === 'lead'): ?>
  <div class="sq-card">
    <p style="font-size:0.85rem;color:#6b7280;margin:0 0 16px;">Pick a lead — we'll auto-fill pricing from their interest plan and send via WhatsApp.</p>

    <form method="post" id="frmLeadQuote">
      <input type="hidden" name="action" value="send_lead_quote">
      <?= csrfField() ?>
      <input type="hidden" name="quote_items_json" id="leadItemsJson" value="[]">
<?php if ($sqDirect !== null): /* 5.18.103 (root docs/72 §G.9, G-9): this page was drawn under the uCRM catalogue */ ?>
      <input type="hidden" name="ucrm_quote_form" value="<?= htmlspecialchars((string)$sqDirect['marker'], ENT_QUOTES) ?>">
<?php endif; ?>

      <div style="margin-bottom:14px;">
        <label class="sq-label">Lead *</label>
        <select name="lead_id" id="leadPicker" class="sq-input" required onchange="onLeadPick(this)">
          <option value="">— Select a lead —</option>
          <?php foreach ($openLeads as $l): ?>
          <option value="<?= (int)$l['id'] ?>"
                  data-phone="<?= htmlspecialchars($l['phone'] ?? '') ?>"
                  data-service="<?= htmlspecialchars($l['service_type'] ?? '') ?>"
                  data-plan="<?= htmlspecialchars($l['interest_plan'] ?? '') ?>"
                  data-crm="<?= (int)($l['crm_client_id'] ?? 0) ?>">
            <?= htmlspecialchars($l['customer_name'] ?? 'Unknown') ?>
            (<?= htmlspecialchars($l['phone'] ?? '') ?>)
            — <?= htmlspecialchars(ucfirst($l['service_type'] ?? '')) ?>
            <?= $l['interest_plan'] ? '/ ' . htmlspecialchars($l['interest_plan']) : '' ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div id="leadPickedInfo" style="display:none;background:#f0f9ff;border:1px solid #bae6fd;border-radius:8px;padding:10px 14px;margin-bottom:14px;font-size:0.85rem;">
        📞 <span id="leadPhone"></span> &nbsp;|&nbsp; 🔧 <span id="leadService"></span>
        <span id="leadCrmBadge" style="display:none;margin-left:8px;">
          <span class="sq-badge" style="background:#d1fae5;color:#065f46;">CRM ✓</span>
          <label style="margin-left:6px;font-weight:600;font-size:0.8rem;">
            <input type="checkbox" name="create_crm_quote" value="1" checked> Also create UCRM quote
          </label>
        </span>
      </div>

      <div class="sq-section-title">Quote Items</div>
      <div id="leadItemsList"></div>
<?php if ($ugQuote): /* 5.18.102 (Uganda, docs/71 §J): why the pre-fill added no line */ ?>
      <div id="leadPlanHint" style="display:none;margin:0 0 10px;padding:8px 12px;border-radius:8px;background:#fffbeb;border:1px solid #fcd34d;color:#92400e;font-size:0.82rem;"></div>
<?php endif; ?>
<?php if ($sqDirect !== null) $sqPickers('lead');   /* 5.18.103 (root docs/72 §G.9): pick from the uCRM catalogue */ ?>

      <button type="button" class="sq-btn sq-btn-ghost sq-btn-sm" onclick="addItem('lead')" style="margin-bottom:12px;">+ Add item</button>

      <div class="sq-total-bar" id="leadTotalBar" style="display:none;">
        <span style="font-weight:600;color:#065f46;">Total</span>
        <span style="font-size:1.1rem;font-weight:800;color:#059669;">$<span id="leadTotal">0.00</span></span>
      </div>

      <div style="margin-bottom:14px;">
        <button type="button" class="sq-btn sq-btn-ghost sq-btn-sm" onclick="previewMsg('lead')" style="margin-right:8px;">👁 Preview WA Message</button>
      </div>

      <pre class="sq-preview-box" id="previewLead"></pre>

      <div style="display:flex;gap:10px;margin-top:16px;">
        <button type="submit" class="sq-btn sq-btn-primary" id="leadSubmitBtn" disabled>📤 Send Quotation via WhatsApp</button>
      </div>
    </form>
  </div>

  <!-- ════════ FLOW C: CASH PROFORMA ════════ -->
  <?php elseif ($subTab === 'cash'): ?>
  <div class="sq-card">
    <p style="font-size:0.85rem;color:#6b7280;margin:0 0 16px;">Instant proforma for a walk-in or cash sale. No CRM account needed — just name + phone.</p>

    <form method="post" id="frmCash">
      <input type="hidden" name="action" value="send_cash_proforma">
      <?= csrfField() ?>
      <input type="hidden" name="cq_items_json" id="cashItemsJson" value="[]">

      <div class="sq-row">
        <div>
          <label class="sq-label">Customer Name *</label>
          <input type="text" name="cq_customer_name" class="sq-input" required placeholder="e.g. James Diko">
        </div>
        <div>
          <label class="sq-label">WhatsApp Phone *</label>
          <input type="text" name="cq_customer_phone" class="sq-input" required placeholder="+211 920…">
        </div>
      </div>

      <div style="margin-bottom:14px;">
        <label class="sq-label">Amount Already Paid ($) <span style="color:#9ca3af;font-weight:400;">(0 if not yet paid)</span></label>
        <input type="number" name="cq_amount_paid" class="sq-input" min="0" step="0.01" value="0" style="max-width:180px;" onchange="recalc('cash')">
      </div>

      <div class="sq-section-title">Items / Services</div>
      <div id="cashItemsList"></div>
      <button type="button" class="sq-btn sq-btn-ghost sq-btn-sm" onclick="addItem('cash')" style="margin-bottom:12px;">+ Add item</button>

      <div class="sq-total-bar" id="cashTotalBar" style="display:none;">
        <span style="font-weight:600;color:#065f46;">Total</span>
        <span style="font-size:1.1rem;font-weight:800;color:#059669;">$<span id="cashTotal">0.00</span></span>
      </div>

      <div style="margin-bottom:14px;">
        <button type="button" class="sq-btn sq-btn-ghost sq-btn-sm" onclick="previewMsg('cash')">👁 Preview WA Message</button>
      </div>
      <pre class="sq-preview-box" id="previewCash"></pre>

      <div style="margin-top:16px;">
        <button type="submit" class="sq-btn sq-btn-primary">📤 Send Proforma via WhatsApp</button>
      </div>
    </form>
  </div>

  <!-- ════════ FLOW D: MANUAL QUOTE ════════ -->
  <?php elseif ($subTab === 'manual'): ?>
  <div class="sq-card">
    <p style="font-size:0.85rem;color:#6b7280;margin:0 0 16px;">Send a quote to any customer. Provide CRM ID to also generate a formal UCRM quote + email.</p>

    <form method="post" id="frmManual">
      <input type="hidden" name="action" value="send_manual_quote">
      <?= csrfField() ?>
      <input type="hidden" name="mq_items_json" id="manualItemsJson" value="[]">
<?php if ($sqDirect !== null): /* 5.18.103 (root docs/72 §G.9, G-9): this page was drawn under the uCRM catalogue */ ?>
      <input type="hidden" name="ucrm_quote_form" value="<?= htmlspecialchars((string)$sqDirect['marker'], ENT_QUOTES) ?>">
<?php endif; ?>

      <div class="sq-row">
        <div>
          <label class="sq-label">Customer Name *</label>
          <input type="text" name="mq_customer_name" class="sq-input" required placeholder="Full name">
        </div>
        <div>
          <label class="sq-label">WhatsApp Phone *</label>
          <input type="text" name="mq_customer_phone" class="sq-input" required placeholder="+211 920…">
        </div>
      </div>

      <div class="sq-row">
        <div>
          <label class="sq-label">CRM Client ID <span style="color:#9ca3af;font-weight:400;">(optional — auto-fetches name/phone)</span></label>
          <input type="number" name="mq_crm_client_id" class="sq-input" min="0" placeholder="e.g. 1234" id="manualCrmId" oninput="toggleCrmQuoteOption()">
        </div>
        <div id="mq_crm_opt" style="display:none;padding-top:22px;">
          <label style="font-size:0.85rem;font-weight:600;">
            <input type="checkbox" name="mq_create_crm" value="1" checked>
            Also create UCRM quote + send email
          </label>
        </div>
      </div>

      <div style="margin-bottom:14px;">
        <label class="sq-label">Note / Footer message <span style="color:#9ca3af;font-weight:400;">(optional)</span></label>
        <input type="text" name="mq_note" class="sq-input" placeholder="e.g. Includes free router installation this week only">
      </div>

      <div class="sq-section-title">Quote Items</div>
      <div id="manualItemsList"></div>
<?php if ($sqDirect !== null) $sqPickers('manual');   /* 5.18.103 (root docs/72 §G.9): pick from the uCRM catalogue */ ?>
      <button type="button" class="sq-btn sq-btn-ghost sq-btn-sm" onclick="addItem('manual')" style="margin-bottom:12px;">+ Add item</button>

      <div class="sq-total-bar" id="manualTotalBar" style="display:none;">
        <span style="font-weight:600;color:#065f46;">Total</span>
        <span style="font-size:1.1rem;font-weight:800;color:#059669;">$<span id="manualTotal">0.00</span></span>
      </div>

      <div style="margin-bottom:14px;">
        <button type="button" class="sq-btn sq-btn-ghost sq-btn-sm" onclick="previewMsg('manual')">👁 Preview WA Message</button>
      </div>
      <pre class="sq-preview-box" id="previewManual"></pre>

      <div style="margin-top:16px;">
        <button type="submit" class="sq-btn sq-btn-primary">📤 Send Quote via WhatsApp</button>
      </div>
    </form>
  </div>
  <?php endif; ?>

</div>

<script>
const PLANS = <?= $plansJson ?>;
const LEAD_DATA = {};
<?php foreach ($openLeads as $l): ?>
LEAD_DATA[<?= (int)$l['id'] ?>] = {
  phone: <?= json_encode($l['phone'] ?? '') ?>,
  service: <?= json_encode(ucfirst($l['service_type'] ?? '')) ?>,
  plan: <?= json_encode($l['interest_plan'] ?? '') ?>,
  crm_client_id: <?= (int)($l['crm_client_id'] ?? 0) ?>
};
<?php endforeach; ?>
<?php if ($ugQuote): /* 5.18.102 (Uganda, docs/71 §J): emitted only where the quoting-organization gate is on */ ?>
// 5.18.102 (Uganda, docs/71 §J): the lead pre-fill's exact match, built by the server — the key of each plan
// (PublicPriceFeed::planKey) → the plan uCRM prices, or price null with why; and each lead → the key of its interest
// plan. Nothing here matches a name: a lead gets the plan of exactly its key, or no line.
const SQ_LEAD_PLANS = <?= $ugLeadPlans ?>;
const SQ_LEAD_KEYS  = <?= $ugLeadKeys ?>;
const SQ_LEAD_SHARED = <?= $ugLeadShared ?>;
const SQ_CUR = <?= json_encode(dn_cur($config)) ?>;
function sqHas(o, k) { return Object.prototype.hasOwnProperty.call(o, k); }
function sqPriced(p) { return !!p && typeof p.price === 'number' && p.price > 0; }
// uCRM's names reach a row's HTML (addItem builds it as a string), so they are escaped first.
function sqEsc(s) {
  return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}
function sqPlanOption(p) {
  return sqPriced(p) ? p.name + ' — ' + SQ_CUR + p.price + '/' + p.unit : p.name + ' — not priced in uCRM';
}
// The picker: every plan listed, a plan uCRM does not price without a price; picking it puts nothing in the row.
function sqUgPlanSuggest(inp, ctx, id) {
  if (!PLANS.length) return;
  if (document.getElementById('planSuggest_' + ctx + '_' + id)) return;
  const wrap = document.createElement('datalist');
  wrap.id = 'planSuggest_' + ctx + '_' + id;
  PLANS.forEach(p => {
    const opt = document.createElement('option');
    opt.value = sqPlanOption(p);
    wrap.appendChild(opt);
  });
  inp.setAttribute('list', wrap.id);
  inp.after(wrap);
  inp.addEventListener('change', function() {
    const match = PLANS.find(p => this.value === sqPlanOption(p));
    if (!match) return;
    if (!sqPriced(match)) {
      this.value = '';
      recalc(ctx);
      // 5.18.102 D14 review (D14-UI-2, §R.4.1): a link reason carries its own remedy ("an admin links it…")
      alert('Not priced in uCRM: ' + (match.reason || match.name) + '. It cannot be added from the list — ask an admin, or type the item and its price yourself.');
      return;
    }
    const row = document.getElementById(ctx + '_item_' + id);
    row.querySelector('.price').value = match.price;
    row.querySelector('.unit').value  = match.unit;
    this.value = match.name;
    recalc(ctx);
  });
}
// The lead pre-fill: the plan of exactly the lead's key, priced from uCRM — else no line, and the hint says why.
function sqUgLeadPrefill(id) {
  const hint = document.getElementById('leadPlanHint');
  const d    = LEAD_DATA[id] || {};
  const key  = sqHas(SQ_LEAD_KEYS, String(id)) ? SQ_LEAD_KEYS[String(id)] : '';
  const p    = key !== '' && sqHas(SQ_LEAD_PLANS, key) ? SQ_LEAD_PLANS[key] : null;
  if (sqPriced(p)) {
    if (hint) hint.style.display = 'none';
    addItem('lead', p.name, p.price, 1, p.unit);
    return;
  }
  recalc('lead');
  if (!hint) return;
  // 5.18.102 D14 review (D14-UI-1): a key two plan rows hold pre-fills nothing (§R.4.5), and the hint says so
  hint.textContent = p ? 'Not priced in uCRM: ' + (p.reason || p.name) + '. No line was added — add the items yourself.'
    : (key !== '' && SQ_LEAD_SHARED.indexOf(key) !== -1
        ? 'More than one plan on the Plans screen is spelled like this lead\'s interest (' + d.plan + '), so no line was added — add the items yourself.'
    : d.plan ? 'No plan in the list is spelled like this lead\'s interest (' + d.plan + '), so no line was added — add the items yourself.'
              : 'This lead names no plan, so no line was added — add the items yourself.');
  hint.style.display = '';
}
// No line is sent without a price: the server refuses one too (QuotationService), this says so before the page posts.
(function() {
  [['frmLeadQuote', 'lead'], ['frmManual', 'manual']].forEach(([fid, ctx]) => {
    const f = document.getElementById(fid);
    if (!f) return;
    f.addEventListener('submit', e => {
      recalc(ctx);
      const bad = getItems(ctx).find(i => !(i.price > 0));
      if (bad) { e.preventDefault(); alert((bad.label || 'A line') + ' has no price — enter one or remove it'); }
    });
  });
})();
<?php endif; ?>
<?php if ($sqDirect !== null): /* 5.18.103 (root docs/72 §G.9): direct mode's pickers — emitted only with the uCRM catalogue's switch on */ ?>
// 5.18.103 (§G.9): what uCRM offers, as the server read it (QuotationService::directQuotePicker()). Nothing here is
// trusted at the send: every picked line is read from uCRM again there, and a price that changed refuses the send.
const SQ_DIRECT = <?= $sqDirectJson ?>;
const SQ_PICKED = {};   // row id → the picked line it shows; its name, unit and price are uCRM's and read-only
function sqDirectKey(l) { return l.kind + ':' + (l.kind === 'plan' ? l.sp + ':' : '') + l.p; }
function sqDirectMoney(v) {   // as the server's lists show it: thousands grouped, decimals only when there are any
  const n = Number(v) || 0;
  const d = Math.abs(n - Math.round(n)) < 0.0001 ? 0 : 2;
  return n.toLocaleString('en-US', {minimumFractionDigits: d, maximumFractionDigits: d});
}
// A line picked from uCRM: built from text nodes (uCRM's names are never HTML here); only its quantity (1–99) can change.
// The same item picked again adds to that line's quantity.
function sqDirectAdd(ctx, line) {
  const list = document.getElementById(ctx + 'ItemsList');
  if (!list || !line) return;
  const max = SQ_DIRECT.max_qty || 99;
  const want = sqDirectKey(line);
  const rows = list.querySelectorAll('.sq-item-row.sq-picked');
  for (let i = 0; i < rows.length; i++) {
    const had = SQ_PICKED[rows[i].id];
    if (had && sqDirectKey(had) === want) {
      const qi = rows[i].querySelector('.qty');
      qi.value = String(Math.min(max, (parseInt(qi.value, 10) || 1) + (parseInt(line.q, 10) || 1)));
      recalc(ctx);
      return;
    }
  }
  const id = ++itemCounters[ctx];
  const div = document.createElement('div');
  div.className = 'sq-item-row sq-picked';
  div.id = ctx + '_item_' + id;
  const keep = {};
  keep.kind = line.kind; keep.sp = line.sp; keep.p = line.p; keep.label = line.label; keep.price = line.price; keep.unit = line.unit;
  SQ_PICKED[div.id] = keep;
  const name = document.createElement('span');
  name.style.cssText = 'flex:1;font-size:0.85rem;';
  name.textContent = line.label;
  const tag = document.createElement('span');
  tag.className = 'sq-badge';
  tag.style.cssText = 'background:#e0e7ff;color:#3730a3;';
  tag.textContent = 'uCRM';
  tag.title = 'Picked from the uCRM catalogue: uCRM\'s name and price, checked against uCRM again when you send';
  const qty = document.createElement('input');
  qty.type = 'number'; qty.className = 'qty'; qty.min = '1'; qty.max = String(max); qty.step = '1';
  qty.value = String(Math.max(1, Math.min(max, parseInt(line.q, 10) || 1)));
  qty.addEventListener('input', function () { recalc(ctx); });
  const unit = document.createElement('span');
  unit.style.cssText = 'font-size:0.82rem;color:#6b7280;';
  unit.textContent = line.unit;
  const price = document.createElement('span');
  price.style.cssText = 'font-size:0.85rem;font-weight:600;min-width:90px;text-align:right;';
  price.textContent = SQ_CUR + sqDirectMoney(line.price);
  const rm = document.createElement('button');
  rm.type = 'button'; rm.className = 'sq-remove'; rm.title = 'Remove'; rm.textContent = '✕';
  rm.addEventListener('click', function () { removeItem(ctx, String(id)); });
  [name, tag, qty, unit, price, rm].forEach(function (el) { div.appendChild(el); });
  list.appendChild(div);
  recalc(ctx);
}
// A line typed by hand is marked on the page only — never in the label sent.
function sqDirectMarkTyped(div) {
  const m = document.createElement('span');
  m.className = 'sq-typed-mark';
  m.style.cssText = 'font-size:0.72rem;color:#92400e;white-space:nowrap;';
  m.textContent = 'typed — not from uCRM';
  div.insertBefore(m, div.querySelector('.sq-remove'));
}
// The lines a form posts: a picked line with its uCRM ids and the price it was drawn with; a typed line as 5.18.102 reads it.
function sqDirectItems(ctx) {
  const max = SQ_DIRECT.max_qty || 99;
  const items = [];
  document.querySelectorAll('#' + ctx + 'ItemsList .sq-item-row').forEach(function (row) {
    const l = SQ_PICKED[row.id];
    if (l && row.classList.contains('sq-picked')) {
      const qi = row.querySelector('.qty');
      const q = Math.max(1, Math.min(max, parseInt(qi.value, 10) || 1));
      if (qi.value !== '' && String(q) !== qi.value) qi.value = String(q);   // the box shows what is sent (1–99)
      const it = {};
      it.kind = l.kind; it.p = l.p; it.q = q; it.label = l.label; it.price = l.price; it.quantity = q; it.unit = l.unit;
      if (l.kind === 'plan') it.sp = l.sp;
      items.push(it);
      return;
    }
    const label = (row.querySelector('.label')?.value || '').trim();
    const qty   = parseInt(row.querySelector('.qty')?.value || '1') || 1;
    const price = parseFloat(row.querySelector('.price')?.value || '0') || 0;
    const unit  = (row.querySelector('.unit')?.value || 'amount').trim();
    if (label || price > 0) items.push({label, quantity: qty, price, unit});
  });
  return items;
}
function sqDirectPickPlan(ctx, sel) {
  const m = /^(\d+):(\d+)$/.exec(sel.value);
  sel.value = '';
  if (!m) return;
  const hit = SQ_DIRECT.plans.find(function (x) { return String(x.sp) === m[1] && String(x.p) === m[2]; });
  if (hit) sqDirectAdd(ctx, hit);
}
function sqDirectPickHw(ctx, sel) {
  const m = /^(item|install):(\d+)$/.exec(sel.value);
  sel.value = '';
  if (!m) return;
  const inst = SQ_DIRECT.install || {};
  const hit = m[1] === 'install'
    ? (inst.ok && String(inst.p) === m[2] ? inst : null)
    : SQ_DIRECT.hardware.find(function (x) { return String(x.p) === m[2]; });
  if (hit) sqDirectAdd(ctx, hit);
}
// A standard quotation (G-12): its lines, priced live from uCRM, replace the lines; it never sends.
function sqDirectStandard(ctx, i) {
  const s = SQ_DIRECT.standards[i];
  const list = document.getElementById(ctx + 'ItemsList');
  if (!s || !s.ok || !list) return;
  list.innerHTML = '';
  itemCounters[ctx] = 0;
  s.lines.forEach(function (l) { sqDirectAdd(ctx, l); });
  const hint = document.getElementById('leadPlanHint');
  if (ctx === 'lead' && hint) hint.style.display = 'none';
  recalc(ctx);
}
// The lead pre-fill: the one offered plan whose key (PublicPriceFeed::planKey, built by the server) is the lead's — else no line.
function sqDirectLeadPrefill(id) {
  const hint = document.getElementById('leadPlanHint');
  const d    = LEAD_DATA[id] || {};
  const keys = SQ_DIRECT.lead_keys || {};
  const key  = Object.prototype.hasOwnProperty.call(keys, String(id)) ? keys[String(id)] : '';
  const hits = key !== '' ? SQ_DIRECT.plans.filter(function (x) { return x.key === key; }) : [];
  if (hits.length === 1) {
    if (hint) hint.style.display = 'none';
    sqDirectAdd('lead', hits[0]);
    return;
  }
  recalc('lead');
  if (!hint) return;
  hint.textContent = !SQ_DIRECT.ok ? 'uCRM could not be read, so no plan was added — add the items yourself.'
    : d.plan ? 'No plan offered from uCRM is spelled like this lead\'s interest (' + d.plan + '), so no line was added — pick one below, or add the items yourself.'
             : 'This lead names no plan, so no line was added — pick one below, or add the items yourself.';
  hint.style.display = '';
}
<?php endif; ?>

const itemCounters = {lead:0, cash:0, manual:0};

function addItem(ctx, label='', price=0, qty=1, unit='month') {
<?php if ($ugQuote): ?>
  label = sqEsc(label); unit = sqEsc(unit);   // 5.18.102 (Uganda, docs/71 §J): uCRM's names reach the row's HTML escaped
<?php endif; ?>
  const container = document.getElementById(ctx + 'ItemsList');
  const id = ++itemCounters[ctx];
  const div = document.createElement('div');
  div.className = 'sq-item-row';
  div.id = ctx + '_item_' + id;
  div.innerHTML = `
    <input type="text" placeholder="Description" value="${label}" oninput="recalc('${ctx}')" class="label">
    <input type="number" class="qty" placeholder="Qty" value="${qty}" min="1" oninput="recalc('${ctx}')">
    <input type="text" class="unit" placeholder="Unit" value="${unit}" title="e.g. month, piece, amount">
    <span><?= trim(dn_cur($config)) ?></span>
    <input type="number" class="price" placeholder="Price" value="${price || ''}" min="0" step="0.01" oninput="recalc('${ctx}')">
    <button type="button" class="sq-remove" onclick="removeItem('${ctx}','${id}')" title="Remove">✕</button>
  `;
<?php if ($sqDirect !== null): /* 5.18.103 (root docs/72 §G.9): a typed line is marked on the page only; the cash proforma is unchanged */ ?>
  if (ctx !== 'cash') sqDirectMarkTyped(div);
<?php endif; ?>
  // Plan autocomplete on label input
  const labelInput = div.querySelector('.label');
  labelInput.addEventListener('focus', () => showPlanSuggest(labelInput, ctx, id));
  container.appendChild(div);
  recalc(ctx);
}

function showPlanSuggest(inp, ctx, id) {
<?php if ($ugQuote): ?>
  return sqUgPlanSuggest(inp, ctx, id);   // 5.18.102 (Uganda, docs/71 §J): uCRM's prices; an unpriced plan cannot be picked
<?php endif; ?>
  if (!PLANS.length) return;
  const existing = document.getElementById('planSuggest_' + ctx + '_' + id);
  if (existing) return;
  const wrap = document.createElement('datalist');
  wrap.id = 'planSuggest_' + ctx + '_' + id;
  PLANS.forEach(p => {
    const opt = document.createElement('option');
    opt.value = p.name + ' — ' + <?= json_encode(dn_cur($config)) ?> + p.price + '/' + p.unit;
    opt.dataset.price = p.price;
    opt.dataset.unit  = p.unit;
    opt.dataset.name  = p.name;
    wrap.appendChild(opt);
  });
  inp.setAttribute('list', wrap.id);
  inp.after(wrap);
  inp.addEventListener('change', function() {
    const match = PLANS.find(p => this.value.startsWith(p.name));
    if (match) {
      const row = document.getElementById(ctx + '_item_' + id);
      row.querySelector('.price').value = match.price;
      row.querySelector('.unit').value  = match.unit;
      this.value = match.name;
      recalc(ctx);
    }
  });
}

function removeItem(ctx, id) {
  const el = document.getElementById(ctx + '_item_' + id);
  if (el) el.remove();
  recalc(ctx);
}

function getItems(ctx) {
<?php if ($sqDirect !== null): /* 5.18.103 (root docs/72 §G.9): picked lines carry their uCRM ids; typed lines are read as below */ ?>
  return sqDirectItems(ctx);
<?php endif; ?>
  const rows = document.querySelectorAll('#' + ctx + 'ItemsList .sq-item-row');
  const items = [];
  rows.forEach(row => {
    const label = (row.querySelector('.label')?.value || '').trim();
    const qty   = parseInt(row.querySelector('.qty')?.value || '1') || 1;
    const price = parseFloat(row.querySelector('.price')?.value || '0') || 0;
    const unit  = (row.querySelector('.unit')?.value || 'amount').trim();
    if (label || price > 0) items.push({label, quantity: qty, price, unit});
  });
  return items;
}

function recalc(ctx) {
  const items = getItems(ctx);
  const total = items.reduce((s, i) => s + i.price * i.quantity, 0);
  const totalEl = document.getElementById(ctx + 'Total');
  const barEl   = document.getElementById(ctx + 'TotalBar');
  const jsonEl  = document.getElementById(ctx + 'ItemsJson');
  if (totalEl) totalEl.textContent = total.toFixed(2);
  if (barEl)   barEl.style.display = items.length ? '' : 'none';
  if (jsonEl)  jsonEl.value = JSON.stringify(items);
  // Enable lead submit
  if (ctx === 'lead') {
    const btn = document.getElementById('leadSubmitBtn');
    if (btn) btn.disabled = !items.length;
  }
}

function onLeadPick(sel) {
  const id  = parseInt(sel.value);
  const info = document.getElementById('leadPickedInfo');
  if (!id || !LEAD_DATA[id]) { info.style.display='none'; return; }
  const d = LEAD_DATA[id];
  document.getElementById('leadPhone').textContent   = d.phone;
  document.getElementById('leadService').textContent = d.service + (d.plan ? ' / ' + d.plan : '');
  const crmBadge = document.getElementById('leadCrmBadge');
  if (d.crm_client_id > 0) { crmBadge.style.display=''; } else { crmBadge.style.display='none'; }
  info.style.display = '';
  // Auto-populate items from plan name match
  const container = document.getElementById('leadItemsList');
  container.innerHTML = '';
  itemCounters.lead = 0;
<?php if ($sqDirect !== null): /* 5.18.103 (root docs/72 §G.9): the one offered uCRM plan keyed like the lead's interest, or no line */ ?>
  return sqDirectLeadPrefill(id);
<?php endif; ?>
<?php if ($ugQuote): ?>
  return sqUgLeadPrefill(id);   // 5.18.102 (Uganda, docs/71 §J): the exact key's uCRM-priced plan, or no line
<?php endif; ?>
  const match = PLANS.find(p => d.plan && (p.name.toLowerCase().includes(d.plan.toLowerCase()) || d.plan.toLowerCase().includes(p.name.toLowerCase())));
  if (match) {
    addItem('lead', match.name, match.price, 1, match.unit);
  } else if (d.plan) {
    addItem('lead', d.service + (d.plan ? ' — ' + d.plan : ''), 0, 1, 'month');
  } else {
    addItem('lead');
  }
}

function toggleCrmQuoteOption() {
  const val = parseInt(document.getElementById('manualCrmId')?.value || '0');
  const opt = document.getElementById('mq_crm_opt');
  if (opt) opt.style.display = val > 0 ? '' : 'none';
}

async function previewMsg(ctx) {
  const items = getItems(ctx);
  if (!items.length) { alert('Add at least one item first.'); return; }
  let custName = 'Customer';
  if (ctx === 'lead') {
    const sel = document.getElementById('leadPicker');
    custName = sel.options[sel.selectedIndex]?.text.split('(')[0].trim() || 'Customer';
  } else if (ctx === 'cash') {
    custName = document.querySelector('[name=cq_customer_name]')?.value || 'Customer';
  } else {
    custName = document.querySelector('[name=mq_customer_name]')?.value || 'Customer';
  }
  const note = document.querySelector('[name=mq_note]')?.value || '';
  const previewEl = document.getElementById('preview' + ctx.charAt(0).toUpperCase() + ctx.slice(1));
  previewEl.textContent = 'Loading preview…';
  previewEl.style.display = 'block';
  try {
    const resp = await fetch('?plugin=' + <?= json_encode(basename(dirname(__DIR__, 2))) ?> + '&api=1', {
          credentials:'same-origin',
          method: 'POST',
      headers: {'Content-Type':'application/json'},
      body: JSON.stringify({action:'preview_quote_message', customer_name:custName, items, note, type: ctx === 'cash' ? 'Cash Sale Receipt' : 'Quotation'})
    });
    const data = await resp.json();
    previewEl.textContent = data.message || 'Error';
  } catch(e) {
    previewEl.textContent = 'Preview unavailable — check connection.';
  }
}

// Init: add one empty row on cash & manual tabs
(function() {
  <?php if ($subTab === 'cash'): ?> addItem('cash'); <?php endif; ?>
  <?php if ($subTab === 'manual'): ?> addItem('manual'); <?php endif; ?>
  // Auto-select lead if coming from leads tab Quick Quote button
  <?php
  $prefillLeadId = (int)($_GET['prefill_lead'] ?? 0);
  if ($prefillLeadId && $subTab === 'lead'): ?>
  const sel = document.getElementById('leadPicker');
  if (sel) {
    sel.value = '<?= $prefillLeadId ?>';
    if (sel.value) { onLeadPick(sel); }
  }
  <?php endif; ?>
})();
</script>
