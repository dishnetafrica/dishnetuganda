<?php
/**
 * tabs/admin/partner_applications.php — read-only staff review of distributor
 * recruitment applications captured from the public website (table 077).
 *
 * Read-only by design in this release: it shows what was submitted so staff can
 * review and follow up. It creates no uCRM client and appoints no partner —
 * that is a separate staff action (root docs/47, docs/48). Every value shown is
 * applicant-supplied and is escaped on output.
 *
 * In scope from public.php: $store, $dataDir, $retailer, $isAdmin, $userRole, $can, h().
 */
require_once __DIR__ . '/../../lib/DistributorApplicationService.php';

// Defence-in-depth gate (independent of $_tabPerms): Admin only in this release.
if (empty($isAdmin)) {
    echo '<div style="color:#b91c1c;padding:24px;font-weight:600;">Access denied.</div>';
    return;
}

$svc = DistributorApplicationService::fromStore($store, $dataDir);
$hh = function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };
$fmtList = function ($json) use ($hh) {
    $a = json_decode((string)$json, true);
    if (!is_array($a) || !$a) return '<span style="color:#9ca3af">—</span>';
    return implode(', ', array_map($hh, $a));
};

$detailId = isset($_GET['dpa']) ? (int)$_GET['dpa'] : 0;

echo '<div style="max-width:1100px;margin:0 auto;">';

if ($detailId > 0) {
    // ── Detail ──
    $row = $svc->get($detailId);
    echo '<p style="margin:0 0 14px;"><a href="?tab=partner_applications" style="color:#C8102E;font-weight:600;text-decoration:none;">&larr; Back to all applications</a></p>';
    if (!$row) {
        echo '<div style="padding:24px;color:#6b7280;">Application not found.</div></div>';
        return;
    }
    echo '<h2 style="font-size:22px;margin:0 0 4px;">' . $hh($row['ref']) . ' — ' . $hh($row['business_name']) . '</h2>';
    echo '<p style="color:#6b7280;margin:0 0 18px;">Received ' . $hh($row['created_at']) . ' · status <b>' . $hh($row['status']) . '</b> · model <b>' . $hh($row['model_label'] ?: $row['partner_model']) . '</b></p>';

    $field = function ($label, $val) use ($hh) {
        $val = trim((string)$val);
        if ($val === '') $val = '—';
        return '<tr><td style="padding:7px 14px 7px 0;color:#6b7280;vertical-align:top;white-space:nowrap;">' . $hh($label) . '</td>'
             . '<td style="padding:7px 0;">' . nl2br($hh($val)) . '</td></tr>';
    };
    echo '<table style="border-collapse:collapse;font-size:14px;width:100%;">';
    echo $field('Contact', trim(($row['contact_name'] ?? '') . ' · ' . ($row['contact_role'] ?? ''), ' ·'));
    echo $field('Email', $row['email']);
    echo $field('Phone / WhatsApp', $row['phone']);
    echo $field('Business type', $row['business_type']);
    echo $field('Trading name', $row['trading_name']);
    echo $field('City / district', $row['city']);
    echo $field('Website', $row['website']);
    echo $field('Already operating', $row['operating']);
    echo '<tr><td style="padding:7px 14px 7px 0;color:#6b7280;vertical-align:top;">Services</td><td style="padding:7px 0;">' . $fmtList($row['services']) . '</td></tr>';
    echo '<tr><td style="padding:7px 14px 7px 0;color:#6b7280;vertical-align:top;">Activities</td><td style="padding:7px 0;">' . $fmtList($row['activities']) . '</td></tr>';
    echo $field('Description', $row['description']);
    echo '</table>';

    // Coverage + readiness (JSON objects), shown as key/value.
    foreach (['coverage' => 'Coverage & outlets', 'readiness' => 'Readiness & training needs'] as $k => $title) {
        $obj = json_decode((string)($row[$k] ?? ''), true);
        if (is_array($obj) && $obj) {
            echo '<h3 style="font-size:15px;margin:22px 0 8px;">' . $hh($title) . '</h3><table style="border-collapse:collapse;font-size:14px;">';
            foreach ($obj as $kk => $vv) {
                if (is_array($vv)) $vv = implode(', ', array_map(fn($x) => is_scalar($x) ? (string)$x : json_encode($x), $vv));
                echo $field((string)$kk, (string)$vv);
            }
            echo '</table>';
        }
    }

    // Proposed training roadmap (array of {group, modules}).
    $tr = json_decode((string)($row['training'] ?? ''), true);
    if (is_array($tr) && $tr) {
        echo '<h3 style="font-size:15px;margin:22px 0 8px;">Proposed training plan</h3>';
        foreach ($tr as $g) {
            if (!is_array($g)) continue;
            echo '<p style="margin:6px 0 2px;font-weight:600;">' . $hh($g['group'] ?? '') . '</p>';
            $mods = is_array($g['modules'] ?? null) ? $g['modules'] : [];
            echo '<p style="margin:0 0 6px;color:#6b7280;font-size:13px;">' . ($mods ? implode(' · ', array_map($hh, $mods)) : '—') . '</p>';
        }
    }
    echo '<p style="margin:24px 0 0;padding:14px;background:#fff6f7;border:1px solid #f0c9cf;border-radius:8px;font-size:13px;color:#6b7280;">'
       . 'This is an expression of interest. Reviewing it here does not appoint a partner, create a uCRM client or grant any account — those remain separate, deliberate actions.</p>';
    echo '</div>';
    return;
}

// ── List ──
$c = $svc->counts();
$data = $svc->listRecent(300, 0);
echo '<h2 style="font-size:22px;margin:0 0 4px;">Distributor applications</h2>';
echo '<p style="color:#6b7280;margin:0 0 18px;">' . (int)$c['total'] . ' received'
   . ' · ' . (int)($c['received'] ?? 0) . ' new'
   . ' · ' . (int)($c['reviewing'] ?? 0) . ' reviewing'
   . ' · ' . (int)($c['contacted'] ?? 0) . ' contacted'
   . ' · ' . (int)($c['closed'] ?? 0) . ' closed'
   . ' — expressions of interest from the website. Read-only; appointing a partner is a separate action.</p>';

if (!$data['items']) {
    echo '<div style="padding:40px;text-align:center;color:#6b7280;border:1px dashed #e5e7eb;border-radius:12px;">No applications yet.</div></div>';
    return;
}
echo '<table style="border-collapse:collapse;width:100%;font-size:13.5px;">';
echo '<thead><tr style="text-align:left;border-bottom:2px solid #e5e7eb;">'
   . '<th style="padding:9px 10px;">Ref</th><th style="padding:9px 10px;">Received</th>'
   . '<th style="padding:9px 10px;">Model</th><th style="padding:9px 10px;">Business</th>'
   . '<th style="padding:9px 10px;">City</th><th style="padding:9px 10px;">Contact</th>'
   . '<th style="padding:9px 10px;">Status</th><th></th></tr></thead><tbody>';
foreach ($data['items'] as $r) {
    echo '<tr style="border-bottom:1px solid #f1f1ef;">'
       . '<td style="padding:9px 10px;font-weight:600;">' . $hh($r['ref']) . '</td>'
       . '<td style="padding:9px 10px;color:#6b7280;">' . $hh(substr((string)$r['created_at'], 0, 16)) . '</td>'
       . '<td style="padding:9px 10px;">' . $hh($r['model_label'] ?: $r['partner_model']) . '</td>'
       . '<td style="padding:9px 10px;">' . $hh($r['business_name']) . '</td>'
       . '<td style="padding:9px 10px;">' . $hh($r['city']) . '</td>'
       . '<td style="padding:9px 10px;">' . $hh($r['contact_name']) . '</td>'
       . '<td style="padding:9px 10px;">' . $hh($r['status']) . '</td>'
       . '<td style="padding:9px 10px;"><a href="?tab=partner_applications&amp;dpa=' . (int)$r['id'] . '" style="color:#C8102E;font-weight:600;text-decoration:none;">View &rarr;</a></td>'
       . '</tr>';
}
echo '</tbody></table></div>';
