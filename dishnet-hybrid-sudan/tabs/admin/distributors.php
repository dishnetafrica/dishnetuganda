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

require_once __DIR__ . '/../../lib/DistributorAttribution.php';
require_once __DIR__ . '/../../lib/DistributorNotifier.php';
$reg = DistributorRegistry::fromStore($store);
$apps = DistributorApplicationService::fromStore($store, $dataDir);
$att = DistributorAttribution::fromStore($store);
$notifier = DistributorNotifier::fromStore($store);
$hh = function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };
$partnerOptions = function ($sel = 0) use ($reg, $hh) {
    $o = '<option value="">— choose a distributor —</option>';
    foreach ($reg->listAll() as $p) {
        $o .= '<option value="' . (int)$p['id'] . '"' . ((int)$sel === (int)$p['id'] ? ' selected' : '') . '>'
            . $hh($p['partner_code'] . ' — ' . ($p['legal_name'] ?: '(unnamed)')) . '</option>';
    }
    return $o;
};

echo '<div style="max-width:1100px;margin:0 auto;">';
echo '<h2 style="font-size:22px;margin:0 0 4px;">Distributors</h2>';
echo '<p style="color:#6b7280;margin:0 0 6px;">Appointed distribution partners. Appointing a partner here creates a '
   . '<b>local prospect record only</b> — it does not create a uCRM client, grant any account or send anything. '
   . 'Linking an <b>existing</b> uCRM company client (the uCRM column) reads it to verify and cache its company '
   . 'details — it creates and changes nothing in uCRM. Giving portal access is a separate, later step.</p>';

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
        if ($p['ucrm_client_id'] ?? null) {
            $ucrm = '#' . (int)$p['ucrm_client_id'];
        } else {
            // Link to an EXISTING uCRM company client. Reads it to verify + cache; creates nothing in uCRM.
            $ucrm = '<form method="post" action="?page=dashboard&amp;tab=distributors" style="margin:0;display:flex;gap:4px;align-items:center;" '
                  . 'onsubmit="return confirm(\'Link ' . $hh($p['partner_code']) . ' to the uCRM company client id you entered? This reads the existing uCRM client to verify and cache it — it creates and changes nothing in uCRM.\');">'
                  . csrfField()
                  . '<input type="hidden" name="action" value="dist_link_ucrm">'
                  . '<input type="hidden" name="partner_id" value="' . (int)$p['id'] . '">'
                  . '<input type="number" name="ucrm_client_id" min="1" placeholder="uCRM id" required style="width:84px;padding:4px 6px;border:1px solid #d1d5db;border-radius:5px;">'
                  . '<button type="submit" style="background:#111827;color:#fff;border:0;border-radius:5px;padding:5px 9px;font-weight:600;cursor:pointer;font-size:12px;">Link</button>'
                  . '</form>';
        }
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

// ── Territory & attribution (WS-A P2) ──
echo '<h3 style="font-size:16px;margin:26px 0 8px;">Territory &amp; attribution</h3>';
echo '<p style="color:#6b7280;margin:0 0 12px;font-size:13px;">Define each distributor\'s areas, then attribute a '
   . 'customer or lead to its owner. Attribution is <b>never by phone</b>; one customer/lead has <b>one active owner</b> '
   . '(re-attributing keeps the history); one area belongs to <b>one distributor</b>.</p>';
if (!$partners) {
    echo '<div style="padding:18px;color:#6b7280;border:1px dashed #e5e7eb;border-radius:10px;margin-bottom:20px;">Appoint a distributor first, then its territory can be defined.</div>';
} else {
    echo '<div style="display:flex;gap:18px;flex-wrap:wrap;margin-bottom:12px;">';
    echo '<form method="post" action="?page=dashboard&amp;tab=distributors" style="margin:0;display:flex;gap:6px;align-items:end;flex-wrap:wrap;">'
       . csrfField() . '<input type="hidden" name="action" value="dist_region_add">'
       . '<label style="font-size:12px;color:#6b7280;">Distributor<br><select name="partner_id" required style="padding:5px;">' . $partnerOptions() . '</select></label>'
       . '<label style="font-size:12px;color:#6b7280;">Region code<br><input name="code" required maxlength="40" style="padding:5px;width:90px;"></label>'
       . '<label style="font-size:12px;color:#6b7280;">Region name<br><input name="name" maxlength="160" style="padding:5px;width:140px;"></label>'
       . '<button type="submit" style="background:#111827;color:#fff;border:0;border-radius:5px;padding:7px 11px;font-weight:600;cursor:pointer;">Add region</button></form>';
    $regs = [];
    foreach ($partners as $p) foreach ($att->regionsFor((int)$p['id']) as $rg) $regs[] = ['id' => (int)$rg['id'], 'label' => $p['partner_code'] . ' / ' . $rg['code'] . ' ' . $rg['name']];
    if ($regs) {
        $ro = '';
        foreach ($regs as $rg) $ro .= '<option value="' . (int)$rg['id'] . '">' . $hh($rg['label']) . '</option>';
        echo '<form method="post" action="?page=dashboard&amp;tab=distributors" style="margin:0;display:flex;gap:6px;align-items:end;flex-wrap:wrap;">'
           . csrfField() . '<input type="hidden" name="action" value="dist_area_add">'
           . '<label style="font-size:12px;color:#6b7280;">Region<br><select name="region_id" required style="padding:5px;">' . $ro . '</select></label>'
           . '<label style="font-size:12px;color:#6b7280;">Area / district<br><input name="area" required maxlength="120" placeholder="e.g. Nakawa" style="padding:5px;width:140px;"></label>'
           . '<button type="submit" style="background:#111827;color:#fff;border:0;border-radius:5px;padding:7px 11px;font-weight:600;cursor:pointer;">Add area</button></form>';
    }
    echo '</div>';
    $terr = $att->territory();
    if ($terr) {
        $pById = []; foreach ($partners as $p) $pById[(int)$p['id']] = $p;
        echo '<table style="border-collapse:collapse;width:100%;font-size:13px;margin-bottom:20px;"><thead><tr style="text-align:left;border-bottom:2px solid #e5e7eb;">'
           . '<th style="padding:7px 9px;">Area</th><th style="padding:7px 9px;">Region</th><th style="padding:7px 9px;">Distributor</th></tr></thead><tbody>';
        foreach ($terr as $t) {
            $pp = $pById[(int)$t['partner_id']] ?? [];
            echo '<tr style="border-bottom:1px solid #f1f1ef;"><td style="padding:7px 9px;">' . $hh($t['area_label']) . '</td>'
               . '<td style="padding:7px 9px;color:#6b7280;">' . $hh(trim($t['region_code'] . ' ' . $t['region_name'])) . '</td>'
               . '<td style="padding:7px 9px;">' . $hh(($pp['partner_code'] ?? ('#' . (int)$t['partner_id'])) . ' — ' . ($pp['legal_name'] ?? '')) . '</td></tr>';
        }
        echo '</tbody></table>';
    } else {
        echo '<p style="color:#9ca3af;font-size:13px;margin:0 0 20px;">No areas defined yet.</p>';
    }
    echo '<h4 style="font-size:14px;margin:6px 0 6px;">Attribute a customer or lead</h4>';
    echo '<p style="color:#6b7280;font-size:12.5px;margin:0 0 8px;">The territory table above proposes the owner for a location — you confirm the distributor here. Re-attributing supersedes the previous owner (kept as history).</p>';
    echo '<form method="post" action="?page=dashboard&amp;tab=distributors" style="margin:0 0 6px;display:flex;gap:6px;align-items:end;flex-wrap:wrap;">'
       . csrfField() . '<input type="hidden" name="action" value="dist_attribute">'
       . '<label style="font-size:12px;color:#6b7280;">Scope<br><select name="scope" style="padding:5px;"><option value="ucrm_client">uCRM client</option><option value="lead">Lead</option></select></label>'
       . '<label style="font-size:12px;color:#6b7280;">Customer / lead id<br><input name="entity_id" required maxlength="120" placeholder="e.g. 5001" style="padding:5px;width:110px;"></label>'
       . '<label style="font-size:12px;color:#6b7280;">Owner (confirm)<br><select name="partner_id" required style="padding:5px;">' . $partnerOptions() . '</select></label>'
       . '<label style="font-size:12px;color:#6b7280;">Basis<br><select name="assigned_via" style="padding:5px;"><option value="territory">territory</option><option value="manual">manual</option></select></label>'
       . '<label style="font-size:12px;color:#6b7280;">Note<br><input name="note" maxlength="200" style="padding:5px;width:150px;"></label>'
       . '<button type="submit" style="background:#C8102E;color:#fff;border:0;border-radius:5px;padding:7px 12px;font-weight:600;cursor:pointer;">Confirm owner</button></form>';
    echo '<p style="font-size:12px;color:#9ca3af;margin:0 0 20px;">Never attributed by phone — use the uCRM client id or the lead id.</p>';
}

// ── Notifications (WS-A P3) ──
echo '<h3 style="font-size:16px;margin:28px 0 8px;">Notifications</h3>';
echo '<p style="color:#6b7280;margin:0 0 4px;font-size:13px;">When something happens to a distributor\'s own customer or lead — a lead attributed, a '
   . 'payment received, a new customer activated — an alert is <b>drafted</b> here for you to approve. The assistant proposes; you decide.</p>';
echo '<p style="margin:0 0 12px;padding:9px 12px;background:#fff6f7;border:1px solid #f0c9cf;border-radius:8px;font-size:12.5px;color:#6b7280;">'
   . '<b>Nothing is sent to a real number in this pilot.</b> Approving a draft queues it; connecting a live WhatsApp number is a separate, '
   . 'explicitly-approved step. A customer\'s opt-out never mutes a distributor; a distributor can mute their own alerts.</p>';
if ($partners) {
    $pById = $pById ?? [];
    foreach ($partners as $p) $pById[(int)$p['id']] = $p;

    // Verified numbers & consent, per distributor
    echo '<h4 style="font-size:14px;margin:14px 0 6px;">Verified numbers &amp; consent</h4>';
    echo '<table style="border-collapse:collapse;width:100%;font-size:13px;margin-bottom:18px;"><thead><tr style="text-align:left;border-bottom:2px solid #e5e7eb;">'
       . '<th style="padding:7px 9px;">Distributor</th><th style="padding:7px 9px;">Numbers (verified numbers receive alerts)</th>'
       . '<th style="padding:7px 9px;">Alerts</th></tr></thead><tbody>';
    foreach ($partners as $p) {
        $pid = (int)$p['id'];
        $contacts = $notifier->contactsFor($pid);
        $verified = array_values(array_filter($contacts, fn($c) => (int)$c['verified'] === 1));
        $muted = $notifier->isMuted($pid);
        echo '<tr style="border-bottom:1px solid #f1f1ef;vertical-align:top;">';
        echo '<td style="padding:8px 9px;font-weight:600;">' . $hh($p['partner_code']) . '<br><span style="font-weight:400;color:#6b7280;font-size:12px;">' . $hh($p['legal_name']) . '</span></td>';
        echo '<td style="padding:8px 9px;">';
        if (!$contacts) {
            echo '<span style="color:#9ca3af;">No number yet.</span>';
        } else {
            echo '<div style="display:flex;flex-direction:column;gap:4px;margin-bottom:6px;">';
            foreach ($contacts as $c) {
                $v = (int)$c['verified'] === 1;
                echo '<div style="display:flex;gap:6px;align-items:center;">'
                   . '<code style="font-size:12px;">' . $hh($c['phone']) . '</code>'
                   . '<span style="font-size:11px;color:#6b7280;">' . $hh($c['role']) . '</span>'
                   . ($v
                       ? '<span style="font-size:10px;font-weight:700;color:#166534;background:#DCFCE7;border-radius:999px;padding:1px 7px;">verified</span>'
                       : '<form method="post" action="?page=dashboard&amp;tab=distributors" style="margin:0;">' . csrfField()
                           . '<input type="hidden" name="action" value="dist_contact_verify"><input type="hidden" name="contact_id" value="' . (int)$c['id'] . '">'
                           . '<button type="submit" style="font-size:11px;background:#111827;color:#fff;border:0;border-radius:4px;padding:2px 8px;cursor:pointer;">Verify</button></form>')
                   . '</div>';
            }
            echo '</div>';
            if (count($verified) > 1) {
                echo '<div style="font-size:11px;color:#b45309;background:#FFFBEB;border:1px solid #FDE68A;border-radius:6px;padding:4px 8px;margin-bottom:6px;">More than one number is verified — alerts are held until exactly one is verified (no guessing).</div>';
            }
        }
        // add a number
        echo '<form method="post" action="?page=dashboard&amp;tab=distributors" style="margin:0;display:flex;gap:4px;align-items:center;flex-wrap:wrap;">' . csrfField()
           . '<input type="hidden" name="action" value="dist_contact_add"><input type="hidden" name="partner_id" value="' . $pid . '">'
           . '<input type="text" name="phone" placeholder="+256…" required style="width:130px;padding:3px 6px;border:1px solid #d1d5db;border-radius:4px;font-size:12px;">'
           . '<select name="role" style="padding:3px;border:1px solid #d1d5db;border-radius:4px;font-size:12px;"><option value="owner">owner</option><option value="ops">ops</option></select>'
           . '<button type="submit" style="font-size:11px;background:#fff;border:1px solid #cbd5e1;border-radius:4px;padding:3px 8px;cursor:pointer;">Add number</button></form>';
        echo '</td>';
        // consent
        echo '<td style="padding:8px 9px;">'
           . '<form method="post" action="?page=dashboard&amp;tab=distributors" style="margin:0;">' . csrfField()
           . '<input type="hidden" name="action" value="dist_consent_mute"><input type="hidden" name="partner_id" value="' . $pid . '">'
           . '<input type="hidden" name="muted" value="' . ($muted ? '0' : '1') . '">'
           . ($muted
               ? '<span style="font-size:11px;font-weight:700;color:#991B1B;">muted</span> <button type="submit" style="font-size:11px;background:#fff;border:1px solid #cbd5e1;border-radius:4px;padding:2px 8px;cursor:pointer;">Un-mute</button>'
               : '<span style="font-size:11px;font-weight:700;color:#166534;">on</span> <button type="submit" style="font-size:11px;background:#fff;border:1px solid #cbd5e1;border-radius:4px;padding:2px 8px;cursor:pointer;">Mute</button>')
           . '</form></td>';
        echo '</tr>';
    }
    echo '</tbody></table>';

    // Drafts awaiting approval
    $drafts = $notifier->pendingDrafts(50);
    echo '<h4 style="font-size:14px;margin:14px 0 6px;">Alerts awaiting your approval <span style="color:#6b7280;font-weight:400;">(' . count($drafts) . ')</span></h4>';
    if (!$drafts) {
        echo '<p style="color:#9ca3af;font-size:13px;margin:0 0 16px;">Nothing waiting. Alerts appear here when a distributor\'s customer/lead triggers one of the three events.</p>';
    } else {
        foreach ($drafts as $d) {
            $pp = $pById[(int)$d['partner_id']] ?? [];
            $who = ($pp['partner_code'] ?? ('#' . (int)$d['partner_id'])) . ' — ' . ($pp['legal_name'] ?? '');
            $dest = (string)$d['to_phone'] !== '' ? $hh($d['to_phone']) : '<span style="color:#b45309;">no verified number yet — add &amp; verify one above</span>';
            echo '<div style="border:1px solid #e5e7eb;border-radius:10px;margin-bottom:12px;overflow:hidden;">';
            echo '<div style="padding:9px 12px;background:#f8fafc;border-bottom:1px solid #f1f5f9;display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;">'
               . '<span style="font-weight:600;font-size:13px;">' . $hh($who) . '</span>'
               . '<span style="font-size:12px;color:#6b7280;">' . $hh(str_replace('_', ' ', $d['event'])) . ' · to ' . $dest . '</span></div>';
            echo '<form method="post" action="?page=dashboard&amp;tab=distributors" style="margin:0;padding:11px 12px;">' . csrfField()
               . '<input type="hidden" name="action" value="dist_notify_approve"><input type="hidden" name="log_id" value="' . (int)$d['id'] . '">'
               . '<textarea name="body" style="width:100%;box-sizing:border-box;min-height:64px;border:1px solid #cbd5e1;border-radius:8px;padding:8px 10px;font:inherit;font-size:13px;">' . $hh($d['body']) . '</textarea>'
               . '<div style="display:flex;gap:8px;margin-top:8px;">'
               . '<button type="submit" style="background:#166534;color:#fff;border:0;border-radius:7px;padding:6px 14px;font-weight:600;cursor:pointer;">Approve &amp; queue</button>'
               . '</form>'
               . '<form method="post" action="?page=dashboard&amp;tab=distributors" style="margin:0;">' . csrfField()
               . '<input type="hidden" name="action" value="dist_notify_reject"><input type="hidden" name="log_id" value="' . (int)$d['id'] . '">'
               . '<button type="submit" style="background:#fff;color:#991B1B;border:1px solid #FCA5A5;border-radius:7px;padding:6px 14px;font-weight:600;cursor:pointer;">Reject</button></form>'
               . '</div>';
            echo '</div>';
        }
    }

    // Recent activity
    $recent = $notifier->recent(20);
    if ($recent) {
        echo '<h4 style="font-size:14px;margin:18px 0 6px;">Recent alerts</h4>';
        echo '<table style="border-collapse:collapse;width:100%;font-size:12.5px;margin-bottom:8px;"><thead><tr style="text-align:left;border-bottom:2px solid #e5e7eb;">'
           . '<th style="padding:6px 9px;">When</th><th style="padding:6px 9px;">Distributor</th><th style="padding:6px 9px;">Event</th><th style="padding:6px 9px;">Status</th></tr></thead><tbody>';
        $badge = ['draft' => '#64748B', 'approved' => '#166534', 'sent' => '#166534', 'rejected' => '#991B1B', 'suppressed' => '#92400E', 'blocked' => '#991B1B'];
        foreach ($recent as $r) {
            $pp = $pById[(int)$r['partner_id']] ?? [];
            $col = $badge[$r['status']] ?? '#64748B';
            echo '<tr style="border-bottom:1px solid #f1f1ef;">'
               . '<td style="padding:6px 9px;color:#6b7280;">' . $hh(substr((string)$r['created_at'], 0, 16)) . '</td>'
               . '<td style="padding:6px 9px;">' . $hh($pp['partner_code'] ?? ('#' . (int)$r['partner_id'])) . '</td>'
               . '<td style="padding:6px 9px;">' . $hh(str_replace('_', ' ', $r['event'])) . '</td>'
               . '<td style="padding:6px 9px;font-weight:700;color:' . $col . ';">' . $hh($r['status'])
               . ((string)$r['reason'] !== '' ? ' <span style="font-weight:400;color:#9ca3af;">· ' . $hh($r['reason']) . '</span>' : '') . '</td></tr>';
        }
        echo '</tbody></table>';
    }
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
