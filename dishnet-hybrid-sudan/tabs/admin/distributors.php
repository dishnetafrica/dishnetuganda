<?php
/**
 * tabs/admin/distributors.php — the distributor registry (WS-A Phase 1a, docs/49).
 *
 * Admin-only. Lists appointed distributor partners, and lets an admin APPOINT a
 * recruitment application (DNP-…) as a local 'prospect' partner. Appointing here
 * creates a LOCAL record only: it does NOT create a uCRM client, grant any
 * account/login/wallet/portal, or send anything — those are separate, later,
 * explicitly-approved steps (docs/49 §15.1). Every value shown is escaped.
 *
 * Gated twice, defence-in-depth: admin AND the distributors_enabled flag (and
 * the whole module is Uganda-only in public.php). In scope from public.php:
 * $store, $dataDir, $retailer, $isAdmin, $userRole, $config, csrfField().
 */
require_once __DIR__ . '/../../lib/DistributorRegistry.php';
require_once __DIR__ . '/../../lib/DistributorApplicationService.php';

if (empty($isAdmin)) {
    echo '<div style="color:#b91c1c;padding:24px;font-weight:600;">Access denied.</div>';
    return;
}
if (empty($config['distributors_enabled'])) {
    echo '<div style="padding:24px;color:#6b7280;">Distributor management is not enabled on this install.</div>';
    return;
}

$reg = DistributorRegistry::fromStore($store);
$apps = DistributorApplicationService::fromStore($store, $dataDir);
$hh = function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };

echo '<div style="max-width:1100px;margin:0 auto;">';
echo '<h2 style="font-size:22px;margin:0 0 4px;">Distributors</h2>';
echo '<p style="color:#6b7280;margin:0 0 6px;">Appointed distribution partners. Appointing a partner here creates a '
   . '<b>local prospect record only</b> — it does not create a uCRM client, grant any account or send anything. '
   . 'Linking a uCRM company client and giving portal access are separate, later steps.</p>';

$c = $reg->counts();
echo '<p style="color:#6b7280;margin:0 0 18px;font-size:13px;">' . (int)$c['total'] . ' partner(s)'
   . ' · ' . (int)($c['prospect'] ?? 0) . ' prospect'
   . ' · ' . (int)($c['onboarding'] ?? 0) . ' onboarding'
   . ' · ' . (int)($c['active'] ?? 0) . ' active'
   . ' · ' . (int)($c['suspended'] ?? 0) . ' suspended'
   . ' · ' . (int)($c['terminated'] ?? 0) . ' terminated</p>';

// ── Appointed partners ──
$partners = $reg->listAll();
if (!$partners) {
    echo '<div style="padding:28px;text-align:center;color:#6b7280;border:1px dashed #e5e7eb;border-radius:12px;margin-bottom:26px;">No partners yet. Appoint one from an application below.</div>';
} else {
    echo '<table style="border-collapse:collapse;width:100%;font-size:13.5px;margin-bottom:26px;">';
    echo '<thead><tr style="text-align:left;border-bottom:2px solid #e5e7eb;">'
       . '<th style="padding:9px 10px;">Code</th><th style="padding:9px 10px;">Legal name</th>'
       . '<th style="padding:9px 10px;">Trading name</th><th style="padding:9px 10px;">Type</th>'
       . '<th style="padding:9px 10px;">Status</th><th style="padding:9px 10px;">uCRM</th>'
       . '<th style="padding:9px 10px;">Appointed</th></tr></thead><tbody>';
    foreach ($partners as $p) {
        $ucrm = ($p['ucrm_client_id'] ?? null) ? ('#' . (int)$p['ucrm_client_id']) : '<span style="color:#9ca3af">not linked</span>';
        echo '<tr style="border-bottom:1px solid #f1f1ef;">'
           . '<td style="padding:9px 10px;font-weight:600;">' . $hh($p['partner_code']) . '</td>'
           . '<td style="padding:9px 10px;">' . $hh($p['legal_name']) . '</td>'
           . '<td style="padding:9px 10px;">' . $hh($p['trading_name']) . '</td>'
           . '<td style="padding:9px 10px;">' . $hh(DistributorRegistry::PARTNER_TYPES[$p['partner_type']] ?? ($p['partner_type'] ?: '—')) . '</td>'
           . '<td style="padding:9px 10px;">' . $hh($p['status']) . '</td>'
           . '<td style="padding:9px 10px;">' . $ucrm . '</td>'
           . '<td style="padding:9px 10px;color:#6b7280;">' . $hh(substr((string)$p['created_at'], 0, 16)) . '</td>'
           . '</tr>';
    }
    echo '</tbody></table>';
}

// ── Applications not yet appointed ──
$appointed = $reg->appointedApplicationIds();
$pending = [];
foreach ($apps->listRecent(300, 0)['items'] as $a) {
    if (empty($appointed[(int)$a['id']])) $pending[] = $a;
}
echo '<h3 style="font-size:16px;margin:0 0 8px;">Applications awaiting appointment</h3>';
if (!$pending) {
    echo '<div style="padding:22px;color:#6b7280;border:1px dashed #e5e7eb;border-radius:12px;">No applications waiting. New expressions of interest appear in <b>Distributor Applications</b>.</div></div>';
    return;
}
echo '<table style="border-collapse:collapse;width:100%;font-size:13.5px;">';
echo '<thead><tr style="text-align:left;border-bottom:2px solid #e5e7eb;">'
   . '<th style="padding:9px 10px;">Ref</th><th style="padding:9px 10px;">Business</th>'
   . '<th style="padding:9px 10px;">Model</th><th style="padding:9px 10px;">City</th>'
   . '<th style="padding:9px 10px;">Contact</th><th></th></tr></thead><tbody>';
foreach ($pending as $a) {
    echo '<tr style="border-bottom:1px solid #f1f1ef;">'
       . '<td style="padding:9px 10px;font-weight:600;">' . $hh($a['ref']) . '</td>'
       . '<td style="padding:9px 10px;">' . $hh($a['business_name']) . '</td>'
       . '<td style="padding:9px 10px;">' . $hh($a['model_label'] ?: $a['partner_model']) . '</td>'
       . '<td style="padding:9px 10px;">' . $hh($a['city']) . '</td>'
       . '<td style="padding:9px 10px;">' . $hh($a['contact_name']) . '</td>'
       . '<td style="padding:9px 10px;text-align:right;">'
       . '<form method="post" action="?page=dashboard&amp;tab=distributors" style="margin:0;" '
       . 'onsubmit="return confirm(\'Appoint ' . $hh($a['ref']) . ' as a prospect partner? This creates a local record only — no uCRM client and no account.\');">'
       . csrfField()
       . '<input type="hidden" name="action" value="dist_appoint">'
       . '<input type="hidden" name="dpa_id" value="' . (int)$a['id'] . '">'
       . '<button type="submit" style="background:#C8102E;color:#fff;border:0;border-radius:6px;padding:7px 12px;font-weight:600;cursor:pointer;">Appoint as prospect</button>'
       . '</form></td></tr>';
}
echo '</tbody></table>';
echo '<p style="margin:20px 0 0;padding:14px;background:#fff6f7;border:1px solid #f0c9cf;border-radius:8px;font-size:13px;color:#6b7280;">'
   . 'Appointing records a local prospect and keeps the provenance from the application. It creates no uCRM company client, '
   . 'no login, no wallet and no portal access — those are deliberate, separate steps that follow.</p>';
echo '</div>';
