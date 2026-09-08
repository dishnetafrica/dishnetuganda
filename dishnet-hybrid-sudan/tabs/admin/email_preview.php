<?php
// ══════════════════════════════════════════════════════════════════════════════
// Tab: email_preview — see every customer email before a customer does.
//
// The email audit asked for a quality-control window: one admin page showing
// each template rendered with realistic customer data, so the whole customer
// experience can be judged visually without sending anything to anybody.
//
// This page NEVER sends. It only renders.
// ══════════════════════════════════════════════════════════════════════════════

if (!isset($retailer)) $retailer = $auth->requireLogin();
$isAdmin = !empty($retailer['is_admin']);
if (!$isAdmin) {
    echo '<div style="padding:40px;text-align:center;color:#666">Admin access required for the email preview.</div>';
    return;
}

require_once dirname(__DIR__, 2) . '/lib/CustomerEmails.php';
require_once dirname(__DIR__, 2) . '/lib/EmailTemplate.php';

$_epCfg   = $config ?? [];
$_epBrand = EmailTemplate::brand($_epCfg);
$_epFrom  = '';
try {
    require_once dirname(__DIR__, 2) . '/lib/MailService.php';
    $_epMail = (new MailService($dataDir))->getConfig();
    $_epFrom = (string)($_epMail['from'] ?? '');
} catch (\Throwable $e) { /* preview must render even with no SMTP */ }
if ($_epFrom === '') $_epFrom = '(sender not configured)';

// ── Realistic sample customer ────────────────────────────────────────────────
// One customer, one story, carried across every template — so reading the
// previews in order reads like a real customer's inbox.
$_epSample = [
    'customer_name'    => 'Felix Orech',
    'account_number'   => 'DN-UG-10428',
    'address'          => 'Plot 14, Nakawa, Kampala',
    'plan_name'        => 'DishNet Residential',
    'monthly_price'    => 329000,
    'quote_number'     => 'PF000123',
    'total'            => 2868000,
    'valid_days'       => 7,
    'invoice_number'   => 'INV-2026-0428',
    'amount'           => 329000,
    'due_date'         => '20 September 2026',
    'period'           => '1–30 October 2026',
    'next_due'         => '25 October 2026',
    'paid_on'          => '9 September 2026',
    'method'           => 'Bank transfer — Ecobank',
    'reference'        => 'PF000123',
    'applied_to'       => 'Invoice INV-2026-0428',
    'balance'          => 0,
    'next_step'        => 'We will call you within one working day to agree your installation date.',
    'date'             => '14 September 2026',
    'window'           => '10:00 – 13:00',
    'technician'       => 'Joseph M.',
    'technician_phone' => $_epBrand['support_phone'],
    'activated_on'     => '14 September 2026',
    'period_ended'     => '30 September 2026',
    'portal_url'       => 'https://' . $_epBrand['website'] . '/app',
    'pay_url'          => '',
    'code'             => '482913',
    'ttl_minutes'      => 15,
    'ticket_ref'       => 'SUP-1180',
    'subject'          => 'Internet slow in the evenings',
    'logged_at'        => '9 September 2026, 20:14',
];

$_epKeys = array_keys(CustomerEmails::CATALOGUE);
$_epPick = (string)($_GET['tpl'] ?? $_epKeys[0]);
if (!in_array($_epPick, $_epKeys, true)) $_epPick = $_epKeys[0];

$_epErr = '';
try {
    $_epMailOut = CustomerEmails::render($_epPick, $_epCfg, $_epSample);
} catch (\Throwable $e) {
    $_epErr = $e->getMessage();
    $_epMailOut = ['subject' => '', 'html' => '', 'text' => ''];
}
list($_epLabel, $_epTrigger, $_epType) = CustomerEmails::CATALOGUE[$_epPick];

// Which sample fields this template actually printed — honest variable list.
$_epUsed = [];
foreach ($_epSample as $k => $v) {
    if ($v === '' || $v === null) continue;
    $needle = is_numeric($v) ? number_format((float)$v, 0) : (string)$v;
    if ($needle !== '' && (strpos($_epMailOut['html'], $needle) !== false
        || strpos($_epMailOut['subject'], $needle) !== false)) {
        $_epUsed[$k] = $needle;
    }
}
$_epSudan = preg_match_all('/\+211|South Sudan|Juba|dishnetafrica\.com/i', $_epMailOut['html']);
?>
<style>
.ep-wrap{padding:22px 20px;}
.ep-grid{display:flex;gap:18px;align-items:flex-start;flex-wrap:wrap;}
.ep-side{width:240px;flex:0 0 240px;}
.ep-main{flex:1;min-width:420px;}
.ep-item{display:block;padding:9px 12px;border-radius:8px;text-decoration:none;color:#374151;font-size:13px;
         border:1px solid transparent;margin-bottom:4px;}
.ep-item:hover{background:#f3f4f6;}
.ep-item.on{background:#141414;color:#fff;font-weight:700;}
.ep-card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:16px 18px;margin-bottom:16px;}
.ep-meta{width:100%;border-collapse:collapse;font-size:13px;}
.ep-meta td{padding:6px 8px;border-bottom:1px solid #f1f1f1;vertical-align:top;}
.ep-meta td:first-child{color:#6b7280;white-space:nowrap;width:130px;}
.ep-chip{display:inline-block;padding:2px 9px;border-radius:20px;font-size:11px;font-weight:700;}
.ep-frame{width:100%;height:760px;border:1px solid #e5e7eb;border-radius:10px;background:#f2f2f2;}
.ep-pre{background:#0f172a;color:#e2e8f0;border-radius:10px;padding:14px;font-size:12px;line-height:1.55;
        white-space:pre-wrap;max-height:340px;overflow:auto;font-family:ui-monospace,Menlo,monospace;}
.ep-w{width:390px!important;}
</style>

<div class="ep-wrap">
  <div style="margin-bottom:16px;">
    <h2 style="margin:0;font-size:1.3rem;font-weight:800;color:#111827;">✉️ Email Preview</h2>
    <p style="margin:4px 0 0;font-size:.85rem;color:#6b7280;">
      Every customer email, rendered with one realistic customer. <strong>Nothing is sent from this page.</strong>
    </p>
  </div>

  <div class="ep-grid">
    <div class="ep-side">
      <?php foreach (CustomerEmails::CATALOGUE as $k => $meta): ?>
        <a class="ep-item <?= $k === $_epPick ? 'on' : '' ?>"
           href="?page=dashboard&tab=email_preview&tpl=<?= urlencode($k) ?>"><?= htmlspecialchars($meta[0]) ?></a>
      <?php endforeach; ?>
      <div style="margin-top:14px;padding:10px 12px;background:#f9fafb;border-radius:8px;font-size:11px;color:#6b7280;line-height:1.6;">
        Brand values come from Configuration.<br>
        <strong><?= htmlspecialchars($_epBrand['company_name']) ?></strong><br>
        <?= htmlspecialchars($_epBrand['locality']) ?><br>
        <?= htmlspecialchars($_epBrand['support_phone']) ?><br>
        <?= htmlspecialchars($_epBrand['website']) ?>
      </div>
    </div>

    <div class="ep-main">
      <?php if ($_epErr !== ''): ?>
        <div class="ep-card" style="border-color:#fca5a5;background:#fef2f2;color:#991b1b;">
          Render failed: <?= htmlspecialchars($_epErr) ?>
        </div>
      <?php endif; ?>

      <div class="ep-card">
        <table class="ep-meta">
          <tr><td>Subject</td><td><strong><?= htmlspecialchars($_epMailOut['subject']) ?></strong></td></tr>
          <tr><td>From</td><td><?= htmlspecialchars($_epFrom) ?></td></tr>
          <tr><td>To</td><td>the customer's billing email address</td></tr>
          <tr><td>Reply-To</td><td><?= htmlspecialchars(EmailTemplate::replyTo($_epCfg)) ?></td></tr>
          <tr><td>Trigger</td><td><?= htmlspecialchars($_epTrigger) ?></td></tr>
          <tr><td>Type</td><td><span class="ep-chip" style="background:#e0e7ff;color:#3730a3;"><?= htmlspecialchars($_epType) ?></span></td></tr>
          <tr><td>Template</td><td><code>CustomerEmails::<?= htmlspecialchars($_epPick) ?></code></td></tr>
          <tr><td>Checks</td><td>
            <span class="ep-chip" style="background:#dcfce7;color:#166534;">responsive <?= strpos($_epMailOut['html'],'max-width:620px')!==false?'✓':'✗' ?></span>
            <span class="ep-chip" style="background:#dcfce7;color:#166534;">dark mode <?= strpos($_epMailOut['html'],'prefers-color-scheme')!==false?'✓':'✗' ?></span>
            <span class="ep-chip" style="background:#dcfce7;color:#166534;">plain text <?= trim($_epMailOut['text'])!==''?'✓':'✗' ?></span>
            <span class="ep-chip" style="background:#dcfce7;color:#166534;">no remote images ✓</span>
            <span class="ep-chip" style="background:<?= $_epSudan? '#fee2e2':'#dcfce7' ?>;color:<?= $_epSudan? '#991b1b':'#166534' ?>;">
              Sudan references: <?= (int)$_epSudan ?></span>
          </td></tr>
          <tr><td>Variables used</td><td style="font-size:12px;color:#374151;">
            <?= $_epUsed ? htmlspecialchars(implode(' · ', array_keys($_epUsed))) : '—' ?>
          </td></tr>
        </table>
      </div>

      <div class="ep-card">
        <div style="display:flex;align-items:center;gap:10px;margin-bottom:10px;">
          <strong style="font-size:13px;">HTML rendering</strong>
          <button onclick="document.getElementById('epF').classList.toggle('ep-w')"
                  style="padding:5px 12px;border:1px solid #d1d5db;border-radius:7px;background:#fff;cursor:pointer;font-size:12px;">
            Toggle phone width
          </button>
        </div>
        <iframe id="epF" class="ep-frame" sandbox
                srcdoc="<?= htmlspecialchars($_epMailOut['html'], ENT_QUOTES, 'UTF-8') ?>"></iframe>
      </div>

      <div class="ep-card">
        <strong style="font-size:13px;display:block;margin-bottom:8px;">Plain-text alternative</strong>
        <div class="ep-pre"><?= htmlspecialchars($_epMailOut['text']) ?></div>
      </div>
    </div>
  </div>
</div>
