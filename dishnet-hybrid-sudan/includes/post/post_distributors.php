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
