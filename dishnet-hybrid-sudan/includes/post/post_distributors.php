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
            $r = $att->link((string)($_POST['scope'] ?? ''), (string)($_POST['entity_id'] ?? ''), (int)($_POST['partner_id'] ?? 0),
                            (string)($_POST['assigned_via'] ?? 'manual'), $actor, (string)($_POST['note'] ?? ''), 'admin');
            flash(!empty($r['linked'])
                ? (!empty($r['relinked']) ? 'Owner changed (the previous owner is kept as history).' : 'Owner recorded.')
                : 'That customer/lead is already owned by this distributor.', 'success');
        }
    } catch (\Throwable $e) {
        flash('Could not complete: ' . $e->getMessage(), 'danger');
    }
    redirect('?page=dashboard&tab=distributors');
}
