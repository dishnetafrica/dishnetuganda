<?php
//
// DISTRIBUTORS (WS-A Phase 1a, docs/49) — appoint an application as a local
// prospect partner. Admin-only, Uganda-only, behind the distributors_enabled
// flag. Creates a LOCAL record only: no uCRM client, no account, no message.
// The global CSRF gate in post_handlers.php already covers action=dist_appoint
// (it is not on the bypass allow-list). The actor is the authenticated admin
// from the identity boundary, never a request field.
//

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'dist_appoint') {
    $admin = $auth->requireAdmin();

    require_once dirname(__DIR__, 2) . '/lib/StaffJobsGate.php';
    $enabled = StaffJobsGate::applies(is_array($config ?? null) ? $config : [], $dataDir ?? null)
        && !empty($config['distributors_enabled']);
    if (!$enabled) {
        flash('Distributor management is not enabled on this install.', 'danger');
        redirect('?page=dashboard');
    }

    require_once dirname(__DIR__, 2) . '/lib/DistributorRegistry.php';
    $reg = DistributorRegistry::fromStore($store);

    $appId = (int)($_POST['dpa_id'] ?? 0);
    $actor = trim((string)($admin['name'] ?? '') . ' <' . (string)($admin['email'] ?? '') . '>');

    try {
        $res = $reg->appointFromApplication($appId, [], $actor);
        flash('Appointed ' . $res['partner_code'] . ' as a prospect. No uCRM client or account was created.', 'success');
    } catch (\Throwable $e) {
        flash('Could not appoint: ' . $e->getMessage(), 'danger');
    }
    redirect('?page=dashboard&tab=distributors');
}

// WS-A P1b: link an appointed partner to an EXISTING uCRM company client.
// Reads the client to verify and cache it — never creates or modifies uCRM.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'dist_link_ucrm') {
    $admin = $auth->requireAdmin();

    require_once dirname(__DIR__, 2) . '/lib/StaffJobsGate.php';
    $enabled = StaffJobsGate::applies(is_array($config ?? null) ? $config : [], $dataDir ?? null)
        && !empty($config['distributors_enabled']);
    if (!$enabled) {
        flash('Distributor management is not enabled on this install.', 'danger');
        redirect('?page=dashboard');
    }

    require_once dirname(__DIR__, 2) . '/lib/DistributorRegistry.php';
    require_once dirname(__DIR__, 2) . '/lib/CrmApiClient.php';
    $reg = DistributorRegistry::fromStore($store);
    $crm = CrmApiClient::fromUcrm(dirname(__DIR__, 2), is_array($config ?? null) ? $config : []);

    $partnerId = (int)($_POST['partner_id'] ?? 0);
    $ucrmId    = (int)($_POST['ucrm_client_id'] ?? 0);
    $actor     = trim((string)($admin['name'] ?? '') . ' <' . (string)($admin['email'] ?? '') . '>');

    try {
        $res = $reg->linkUcrmClient($partnerId, $ucrmId, $crm, $actor);
        if (!empty($res['linked'])) {
            flash('Linked to uCRM client #' . $res['ucrm_client_id'] . ' (' . ($res['legal_name'] ?? '') . ').', 'success');
        } else {
            flash('Already linked to uCRM client #' . $res['ucrm_client_id'] . '.', 'success');
        }
    } catch (\Throwable $e) {
        flash('Could not link: ' . $e->getMessage(), 'danger');
    }
    redirect('?page=dashboard&tab=distributors');
}

// WS-A P2: territory + customer/lead attribution. Admin + flag + Uganda, local only.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['dist_region_add', 'dist_area_add', 'dist_attribute'], true)) {
    $admin = $auth->requireAdmin();
    require_once dirname(__DIR__, 2) . '/lib/StaffJobsGate.php';
    $enabled = StaffJobsGate::applies(is_array($config ?? null) ? $config : [], $dataDir ?? null)
        && !empty($config['distributors_enabled']);
    if (!$enabled) {
        flash('Distributor management is not enabled on this install.', 'danger');
        redirect('?page=dashboard');
    }
    require_once dirname(__DIR__, 2) . '/lib/DistributorAttribution.php';
    $att = DistributorAttribution::fromStore($store);
    $actor = trim((string)($admin['name'] ?? '') . ' <' . (string)($admin['email'] ?? '') . '>');
    $act = (string)($_POST['action'] ?? '');
    try {
        if ($act === 'dist_region_add') {
            $att->addRegion((int)($_POST['partner_id'] ?? 0), (string)($_POST['code'] ?? ''), (string)($_POST['name'] ?? ''), $actor);
            flash('Region added.', 'success');
        } elseif ($act === 'dist_area_add') {
            $r = $att->addArea((int)($_POST['region_id'] ?? 0), (string)($_POST['area'] ?? ''), $actor);
            flash(!empty($r['added']) ? 'Area added to the territory.' : 'That area already belongs to this distributor.', 'success');
        } else { // dist_attribute — human-confirmed owner
            $scope    = (string)($_POST['scope'] ?? '');
            $entityId = (string)($_POST['entity_id'] ?? '');
            $r = $att->link($scope, $entityId, (int)($_POST['partner_id'] ?? 0),
                            (string)($_POST['assigned_via'] ?? 'manual'), $actor, (string)($_POST['note'] ?? ''), 'admin');
            // Attributing a LEAD to a distributor IS the "new lead attributed" event
            // (docs/49 §7, Flow B). Draft an alert for the owner — nothing is sent
            // (pilot is draft->approve only). This is the one event wired from our own
            // code; the two CRM events hook the live webhook paths (DistributorEvents).
            $extra = '';
            if ($scope === 'lead' && !empty($r['linked'])) {
                require_once dirname(__DIR__, 2) . '/lib/DistributorEvents.php';
                $ev = DistributorEvents::maybeNotify($store, is_array($config ?? null) ? $config : [], $dataDir ?? null,
                    'lead_attributed', ['lead_id' => $entityId], [], $actor);
                if (!empty($ev['created'])) $extra = ' A draft alert is waiting for your approval under Notifications below.';
            }
            flash((!empty($r['linked'])
                ? (!empty($r['relinked']) ? 'Owner changed (the previous owner is kept as history).' : 'Owner recorded.')
                : 'That customer/lead is already owned by this distributor.') . $extra, 'success');
        }
    } catch (\Throwable $e) {
        flash('Could not complete: ' . $e->getMessage(), 'danger');
    }
    redirect('?page=dashboard&tab=distributors');
}

// WS-A P3: distributor notifications — verified contacts, consent, and the
// draft->approve queue. Admin + flag + Uganda, local only. The bound channel is
// NullWhatsAppChannel, so APPROVING a draft queues it — nothing is sent to a
// real number in the pilot (docs/49 §3.3, Bhavin B-4).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['dist_contact_add', 'dist_contact_verify', 'dist_consent_mute', 'dist_notify_approve', 'dist_notify_reject'], true)) {
    $admin = $auth->requireAdmin();
    require_once dirname(__DIR__, 2) . '/lib/StaffJobsGate.php';
    $enabled = StaffJobsGate::applies(is_array($config ?? null) ? $config : [], $dataDir ?? null)
        && !empty($config['distributors_enabled']);
    if (!$enabled) {
        flash('Distributor management is not enabled on this install.', 'danger');
        redirect('?page=dashboard');
    }
    require_once dirname(__DIR__, 2) . '/lib/DistributorNotifier.php';
    $notifier = DistributorNotifier::fromStore($store); // NullWhatsAppChannel — nothing is sent
    $actor = trim((string)($admin['name'] ?? '') . ' <' . (string)($admin['email'] ?? '') . '>');
    $act = (string)($_POST['action'] ?? '');
    try {
        if ($act === 'dist_contact_add') {
            $notifier->addContact((int)($_POST['partner_id'] ?? 0), (string)($_POST['phone'] ?? ''), (string)($_POST['role'] ?? 'owner'), $actor);
            flash('Number recorded (unverified). Verify it before it can receive alerts.', 'success');
        } elseif ($act === 'dist_contact_verify') {
            $notifier->verifyContact((int)($_POST['contact_id'] ?? 0), $actor);
            flash('Number verified — it can now receive this distributor\'s alerts.', 'success');
        } elseif ($act === 'dist_consent_mute') {
            $mute = ((string)($_POST['muted'] ?? '') === '1');
            $notifier->setMuted((int)($_POST['partner_id'] ?? 0), $mute, $actor);
            flash($mute ? 'Alerts muted for this distributor (their own choice).' : 'Alerts un-muted for this distributor.', 'success');
        } elseif ($act === 'dist_notify_approve') {
            $r = $notifier->approve((int)($_POST['log_id'] ?? 0), $actor, isset($_POST['body']) ? (string)$_POST['body'] : null);
            if (!empty($r['ok'])) {
                flash(($r['status'] ?? '') === 'sent'
                    ? 'Approved and sent.'
                    : 'Approved and queued. Live WhatsApp sending is not enabled in the pilot — nothing was sent to a number.', 'success');
            } else {
                flash('Could not approve: ' . ($r['reason'] ?? 'unknown'), 'danger');
            }
        } else { // dist_notify_reject
            $notifier->reject((int)($_POST['log_id'] ?? 0), $actor, (string)($_POST['note'] ?? ''));
            flash('Draft rejected.', 'success');
        }
    } catch (\Throwable $e) {
        flash('Could not complete: ' . $e->getMessage(), 'danger');
    }
    redirect('?page=dashboard&tab=distributors');
}

// WS-A P4 (docs/53 §5): admin-only, audited TOTP reset for a distributor portal
// account. Clears the authenticator AND revokes the account's live sessions in
// one transaction; the distributor enrols a new authenticator at their next
// sign-in, which still needs a fresh one-time login code — so this recovers a
// lost device without becoming a sign-in bypass. No self-service. Admin + flag +
// Uganda, local only. The actor is the authenticated admin from the identity
// boundary, never a request field (PartnerAccounts::resetTotp requires it).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'dist_totp_reset') {
    $admin = $auth->requireAdmin();

    require_once dirname(__DIR__, 2) . '/lib/StaffJobsGate.php';
    $enabled = StaffJobsGate::applies(is_array($config ?? null) ? $config : [], $dataDir ?? null)
        && !empty($config['distributors_enabled']);
    if (!$enabled) {
        flash('Distributor management is not enabled on this install.', 'danger');
        redirect('?page=dashboard');
    }

    require_once dirname(__DIR__, 2) . '/lib/PartnerAccounts.php';
    $acct = PartnerAccounts::fromStore($store);
    $actor = trim((string)($admin['name'] ?? '') . ' <' . (string)($admin['email'] ?? '') . '>');

    try {
        $r = $acct->resetTotp((int)($_POST['user_id'] ?? 0), $actor);
        flash('Authenticator reset. The distributor must set up a new authenticator at their next sign-in, and '
            . (int)($r['revoked'] ?? 0) . ' active session(s) were signed out.', 'success');
    } catch (\Throwable $e) {
        flash('Could not reset the authenticator: ' . $e->getMessage(), 'danger');
    }
    redirect('?page=dashboard&tab=distributors');
}
