<?php
// ═══════════════════════════════════════════════════════════════
// CRM SYNC / ORG7 / FTTH
// ═══════════════════════════════════════════════════════════════


// ─── POST: sync_now (fallback for non-JS, does full page reload after) ───────
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='sync_now') {
    $auth->requireAdmin();
    set_time_limit(120);
    $result = runInProcessSync($queue, $crm, $store, $wallet, $notify, 20);
    $store->save('sync_last_run.json', [
        'ran_at'    => date('Y-m-d H:i:s'),
        'ran_by'    => $retailer['name'] ?? 'Admin',
        'lines'     => array_slice($result['log'], -20),
        'exit_code' => $result['failed'] > 0 && $result['success'] === 0 ? 1 : 0,
        'success'   => $result['success'],
        'failed'    => $result['failed'],
        'processed' => $result['processed'],
    ]);
    if ($result['processed'] === 0) {
        flash('✓ Queue is empty — nothing to sync.', 'success');
    } elseif ($result['failed'] === 0) {
        flash("✓ Synced {$result['success']} application(s) to UCRM successfully.", 'success');
    } else {
        flash("⚠ {$result['success']} synced, {$result['failed']} failed. Check the log below.", 'warning');
    }
    redirect('?page=dashboard&tab=sync_queue');
}



if($_SERVER['REQUEST_METHOD']==='POST'&&($_POST['action']??'')==='ftth_sync_all'){
    $auth->requireAdmin();
    $result = $ftthCrm->syncAllRetailers();
    flash("FTTH sync complete — Linked:{$result['linked']} Created:{$result['created']} Failed:{$result['failed']}",
        $result['failed']===0?'success':'warning');
    redirect('?page=dashboard&tab=settings');
}
// ── Org 7 retailer sync — from retailers tab ──────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='sync_org7_retailers') {
    $admin = $auth->requireAdmin();
    csrfCheck();

    // 1. Push: ensure every plugin retailer has a CRM Org-7 client + sync wallet balance
    $pushResult = $ftthCrm->syncAllRetailers();

    // 2. Pull: fetch all org-7 clients from CRM and cache locally for display
    $org7OrgId  = (int)($config['crm_ftth_org_id'] ?? 7);
    $org7Clients = [];
    $offset      = 0;
    do {
        $page = $crm->get('clients?' . http_build_query([
            'organizationId' => $org7OrgId,
            'limit'          => 100,
            'offset'         => $offset,
            'direction'      => 'ASC',
            'order'          => 'client.id',
        ]));
        if (!is_array($page)) break;
        foreach ($page as $c) {
            $org7Clients[] = [
                'id'          => (int)($c['id'] ?? 0),
                'name'        => trim(($c['firstName'] ?? '') . ' ' . ($c['lastName'] ?? '')),
                'company'     => $c['companyName'] ?? '',
                'username'    => $c['username'] ?? '',
                'email'       => $c['contacts'][0]['email'] ?? '',
                'phone'       => $c['contacts'][0]['phone'] ?? '',
                'balance'     => (float)($c['accountBalance'] ?? 0),
                'is_active'   => (bool)($c['isActive'] ?? true),
                'is_lead'     => (bool)($c['isLead'] ?? false),
                'crm_url'     => dn_crm_web($config).'/crm/client/' . (int)($c['id'] ?? 0),
                'org_id'      => $org7OrgId,
                'synced_at'   => date('Y-m-d H:i:s'),
            ];
        }
        $offset += 100;
    } while (count($page ?? []) === 100);

    // Build lookup: crm_id → plugin retailer
    $retailers    = $store->load('retailers.json');
    $crmToPlugin  = [];
    foreach ($retailers as $r) {
        if (!empty($r['ftth_crm_client_id'])) {
            $crmToPlugin[(int)$r['ftth_crm_client_id']] = $r;
        }
    }

    // Annotate each org7 client with link status
    foreach ($org7Clients as &$c7) {
        $linked = $crmToPlugin[$c7['id']] ?? null;
        $c7['linked_plugin_id']   = $linked ? (int)$linked['id'] : null;
        $c7['linked_plugin_name'] = $linked ? $linked['name'] : null;
        $c7['linked_plugin_role'] = $linked ? ($linked['role'] ?? 'sales') : null;
    }
    unset($c7);

    $store->save('org7_crm_cache.json', [
        'fetched_at' => date('Y-m-d H:i:s'),
        'org_id'     => $org7OrgId,
        'clients'    => $org7Clients,
    ]);

    $pulled = count($org7Clients);
    flash(
        "CRM Org-7 sync complete — <strong>{$pushResult['linked']}</strong> linked, " .
        "<strong>{$pushResult['created']}</strong> created, " .
        "<strong>{$pushResult['failed']}</strong> failed push | " .
        "<strong>{$pulled}</strong> clients pulled from CRM.",
        $pushResult['failed'] === 0 ? 'success' : 'warning'
    );
    redirect('?page=dashboard&tab=retailers&subtab=org7');
}

// ── Record debt recovery (accountant deducts from retailer's outstanding wallet) ──
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='record_debt_recovery') {
    $accountant = $auth->requireLogin();
    csrfCheck();
    if (!$can('accounts_dash') && !($accountant['is_admin']??false))
        { flash('Access denied.', 'danger'); redirect('?page=dashboard'); }

    $retailerId = (int)($_POST['retailer_id'] ?? 0);
    $amount     = round((float)($_POST['amount'] ?? 0), 2);
    $method     = trim($_POST['recovery_method'] ?? 'cash');
    $note       = trim($_POST['note'] ?? '');
    if ($retailerId <= 0 || $amount <= 0)
        { flash('Invalid recovery amount.', 'danger'); redirect('?page=dashboard&tab=accounts_dashboard'); }

    $target = null;
    foreach ($auth->getAllRetailers() as $r) {
        if ((int)$r['id'] === $retailerId) { $target = $r; break; }
    }
    if (!$target) { flash('Retailer not found.', 'danger'); redirect('?page=dashboard&tab=accounts_dashboard'); }

    $currentDebt = (float)($target['wallet'] ?? 0);

    $newDebt = round(max(0, $currentDebt - $amount), 2);
    $store->updateOne('retailers.json', 'id', $retailerId, [
        'wallet'             => $newDebt,
        'crm_outstanding'    => $newDebt,
        'crm_outstanding_at' => date('Y-m-d H:i:s'),
    ]);

    // ── Plugin passbook entry
    $wallet->credit($retailerId, -$amount,
        "Cash recovery [{$method}] by {$accountant['name']}" . ($note ? " — {$note}" : ''),
        'debt_recovery', $accountant['name']);

    // ── Post payment to CRM Org-7 against this retailer's open invoices
    //    Dr Cash / Cr Justus Receivable — closes the loop in UCRM
    $crmPayResult   = null;
    $crmPaySuccess  = false;
    $crmRetailerId  = (int)($target['ftth_crm_client_id'] ?? 0);
    if ($crmRetailerId && $crm->isConfigured()) {
        $crmPayload = [
            'clientId'                       => $crmRetailerId,
            'methodId'     => PaymentUuids::resolve($method),
            'amount'                         => $amount,
            'note'                           => "Cash recovery — {$accountant['name']}" . ($note ? ": {$note}" : ''),
            'applyToInvoicesAutomatically'   => true,  // let UCRM apply to oldest unpaid first
        ];
        $crmPayResult  = $crm->post('payments', $crmPayload);
        $crmPaySuccess = !empty($crmPayResult) && isset($crmPayResult['id']);
    }

    // ── Audit log
    $store->appendTo('audit_log.json', [
        'action'         => 'debt_recovery',
        'retailer_id'    => $retailerId,
        'retailer'       => $target['name'],
        'amount'         => $amount,
        'method'         => $method,
        'note'           => $note,
        'prev_debt'      => $currentDebt,
        'new_debt'       => $newDebt,
        'crm_client_id'  => $crmRetailerId ?: null,
        'crm_payment_id' => $crmPayResult['id'] ?? null,
        'crm_synced'     => $crmPaySuccess,
        'by'             => $accountant['name'],
        'at'             => date('Y-m-d H:i:s'),
    ]);

    $crmMsg = $crmPaySuccess
        ? ' CRM payment #' . ($crmPayResult['id'] ?? '?') . ' posted — invoice(s) auto-applied.'
        : ($crmRetailerId ? ' ⚠ CRM payment post failed — record manually in CRM.' : '');

    flash(
        "<strong>\${$amount}</strong> recovered from <strong>{$target['name']}</strong>. " .
        "Remaining: <strong>\${$newDebt}</strong>." . $crmMsg,
        $crmPaySuccess || !$crmRetailerId ? 'success' : 'warning'
    );
    redirect('?page=dashboard&tab=accounts_dashboard');
}

// ── Pull CRM Org-7 balances → plugin wallets ──────────────────────────────
// For each retailer that has a CRM link, reads their CRM accountBalance
// and OVERWRITES the plugin wallet with abs(crmBalance) since negative CRM
// balance = they owe DishNet = that's their wallet debt.
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='sync_org7_wallets') {
    $admin = $auth->requireAdmin();
    csrfCheck();

    // mode=preview → read CRM, show table, no writes
    // mode=apply   → read CRM, write crm_outstanding on each retailer (NOT wallet)
    //                wallet stays as pure agent float; crm_outstanding = debt from CRM invoices
    $mode      = trim($_POST['sync_mode'] ?? 'preview');
    $retailers = $store->load('retailers.json');
    $report    = [];
    $updated   = 0;

    foreach ($retailers as $r) {
        $crmId = (int)($r['ftth_crm_client_id'] ?? 0);
        if (!$crmId) continue;

        $crmClient = $crm->get("clients/{$crmId}");
        if (!$crmClient) {
            $report[] = [
                'id'         => $r['id'],
                'name'       => $r['name'],
                'crm_id'     => $crmId,
                'crm_bal'    => null,
                'plugin_wal' => (float)($r['wallet'] ?? 0),
                'prev_debt'  => (float)($r['crm_outstanding'] ?? 0),
                'owes_amt'   => 0.0,
                'owes'       => false,
                'changed'    => false,
                'error'      => true,
            ];
            continue;
        }

        $crmBal    = (float)($crmClient['accountBalance'] ?? 0);
        // UCRM: negative balance = retailer owes DishNet (unpaid top-up invoices)
        $owesAmt   = $crmBal < 0 ? round(-$crmBal, 2) : 0.0;
        $prevDebt  = (float)($r['crm_outstanding'] ?? 0);
        $changed   = abs($prevDebt - $owesAmt) > 0.005;

        $report[] = [
            'id'         => $r['id'],
            'name'       => $r['name'],
            'crm_id'     => $crmId,
            'crm_bal'    => $crmBal,
            'plugin_wal' => (float)($r['wallet'] ?? 0),
            'prev_debt'  => $prevDebt,
            'owes_amt'   => $owesAmt,
            'owes'       => $owesAmt > 0.005,
            'changed'    => $changed,
            'error'      => false,
        ];

        if ($mode === 'apply') {
            // Write both wallet AND crm_outstanding so all panels work correctly
            // wallet     = the live outstanding amount (what Justus owes right now)
            // crm_outstanding = same value, separate tracking field
            $store->updateOne('retailers.json', 'id', (int)$r['id'], [
                'wallet'             => $owesAmt,   // ← this is what the system reads everywhere
                'crm_outstanding'    => $owesAmt,   // ← accountant panels read this
                'crm_outstanding_at' => date('Y-m-d H:i:s'),
                'crm_bal_raw'        => $crmBal,
            ]);
            // Log to passbook so there's a visible audit trail
            $prevWal = (float)($r['wallet'] ?? 0);
            if (abs($prevWal - $owesAmt) > 0.005) {
                $diff = $owesAmt - $prevWal;
                $wallet->credit((int)$r['id'], $diff,
                    "CRM balance sync — CRM #{$crmId}: outstanding \${$owesAmt}",
                    'crm_sync', 'system'
                );
            }
            $updated++;
        }
    }

    usort($report, function($a, $b) {
        if ($a['error'] !== $b['error']) return $a['error'] ? 1 : -1;
        return ($b['owes_amt'] ?? 0) <=> ($a['owes_amt'] ?? 0);
    });

    $_SESSION['org7_wallet_report'] = $report;

    // Always persist cache for accountant panels
    $store->save('org7_crm_balance_cache.json', [
        'checked_at' => date('Y-m-d H:i:s'),
        'checked_by' => $admin['name'] ?? 'Admin',
        'applied'    => $mode === 'apply',
        'report'     => $report,
    ]);

    $debtors   = count(array_filter($report, fn($x) => $x['owes'] ?? false));
    $totalOwed = array_sum(array_column(array_filter($report, fn($x) => $x['owes'] ?? false), 'owes_amt'));
    $changed   = count(array_filter($report, fn($x) => ($x['changed'] ?? false) && !($x['error'] ?? false)));

    if ($mode === 'apply') {
        flash(
            "Sync applied — <strong>{$updated}</strong> retailers updated. " .
            "<strong>{$debtors}</strong> have outstanding debt totalling <strong>" . dn_cur($config) . number_format($totalOwed, 2) . "</strong>.",
            $debtors > 0 ? 'warning' : 'success'
        );
    } else {
        flash(
            "Preview ready — <strong>{$changed}</strong> retailer(s) will change. " .
            "<strong>{$debtors}</strong> owe a total of <strong>" . dn_cur($config) . number_format($totalOwed, 2) . "</strong>. " .
            "Review below then click <strong>Apply</strong>.",
            $debtors > 0 ? 'warning' : 'success'
        );
    }
    redirect('?page=dashboard&tab=retailers&subtab=org7');
}

// ── Import / Link a single CRM Org-7 client into plugin ──────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='import_org7_client') {
    $admin = $auth->requireAdmin();
    csrfCheck();

    $crmId    = (int)($_POST['crm_id']   ?? 0);
    $crmName  = trim($_POST['crm_name']  ?? '');
    $crmEmail = strtolower(trim($_POST['crm_email'] ?? ''));
    $crmPhone = trim($_POST['crm_phone'] ?? '');
    $role     = trim($_POST['role']      ?? 'sales');
    $isAdmin  = !empty($_POST['is_admin']);
    $password = trim($_POST['password']  ?? '');
    $mode     = trim($_POST['mode']      ?? 'create'); // 'create' | 'link'
    $linkToId = (int)($_POST['link_to_plugin_id'] ?? 0);

    if (!$crmId || !$crmName) {
        flash('CRM ID and name are required.', 'danger');
        redirect('?page=dashboard&tab=retailers&subtab=org7');
    }

    if ($mode === 'link' && $linkToId > 0) {
        // Just link an existing plugin retailer to this CRM client
        $ftthCrm->linkRetailer($linkToId, $crmId);
        $linkedR = $store->findOne('retailers.json', 'id', $linkToId);
        flash("Linked <strong>" . h($linkedR['name'] ?? "#$linkToId") . "</strong> → CRM #$crmId.", 'success');
        redirect('?page=dashboard&tab=retailers&subtab=org7');
    }

    // CREATE mode — make a new plugin retailer from this CRM client
    if (!$crmEmail) $crmEmail = 'crm' . $crmId . '@dishnetafrica.com';

    $existing = $store->findOne('retailers.json', 'email', $crmEmail);
    if ($existing) {
        // Email already exists — just link
        $ftthCrm->linkRetailer((int)$existing['id'], $crmId);
        flash("Plugin account for <strong>" . h($crmEmail) . "</strong> already existed — linked to CRM #$crmId.", 'warning');
        redirect('?page=dashboard&tab=retailers&subtab=org7');
    }

    if (!$password) $password = 'DishNet' . $crmId . '@2026';

    $newR = $auth->createRetailer([
        'name'            => $crmName,
        'email'           => $crmEmail,
        'phone'           => $crmPhone,
        'password'        => $password,
        'wallet'          => 0,
        'is_admin'        => $isAdmin,
        'is_field_agent'  => ($role === 'field_agent'),
        'role'            => $role,
    ]);
    // Link to CRM Org-7
    $ftthCrm->linkRetailer((int)$newR['id'], $crmId);

    flash(
        "✅ Created plugin account for <strong>" . h($crmName) . "</strong> as <strong>{$role}</strong>. " .
        "Temp password: <code>{$password}</code> — share this securely.",
        'success'
    );
    redirect('?page=dashboard&tab=retailers&subtab=org7');
}

// ── Bulk import all unlinked Org-7 clients ────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='bulk_import_org7') {
    $admin = $auth->requireAdmin();
    csrfCheck();

    $defaultRole    = trim($_POST['default_role']    ?? 'sales');
    $defaultIsAdmin = !empty($_POST['default_is_admin']);
    $cache          = $store->load('org7_crm_cache.json');
    $org7Clients    = $cache['clients'] ?? [];

    $created = $linked = $skipped = 0;
    $passwords = [];

    foreach ($org7Clients as $c7) {
        if (!empty($c7['linked_plugin_id'])) { $skipped++; continue; } // already linked

        $email = strtolower(trim($c7['email'] ?? ''));
        if (!$email) $email = 'crm' . $c7['id'] . '@dishnetafrica.com';

        $existing = $store->findOne('retailers.json', 'email', $email);
        if ($existing) {
            $ftthCrm->linkRetailer((int)$existing['id'], (int)$c7['id']);
            $linked++;
        } else {
            $pw = 'DishNet' . $c7['id'] . '@2026';
            $newR = $auth->createRetailer([
                'name'           => $c7['name'] ?: ('CRM#' . $c7['id']),
                'email'          => $email,
                'phone'          => $c7['phone'] ?? '',
                'password'       => $pw,
                'wallet'         => 0,
                'is_admin'       => $defaultIsAdmin,
                'is_field_agent' => ($defaultRole === 'field_agent'),
                'role'           => $defaultRole,
            ]);
            $ftthCrm->linkRetailer((int)$newR['id'], (int)$c7['id']);
            $passwords[] = h($c7['name']) . ': <code>' . $pw . '</code>';
            $created++;
        }
    }

    $msg = "Bulk import done — <strong>{$created}</strong> created, <strong>{$linked}</strong> linked by email, <strong>{$skipped}</strong> skipped (already linked).";
    if ($passwords) $msg .= '<br><small style="font-size:11px;">Temp passwords — ' . implode(' | ', array_slice($passwords, 0, 10)) . ($created > 10 ? ' …' : '') . '</small>';
    flash($msg, $created + $linked > 0 ? 'success' : 'info');
    redirect('?page=dashboard&tab=retailers&subtab=org7');
}
if($_SERVER['REQUEST_METHOD']==='POST'&&($_POST['action']??'')==='save_plan'){
    $auth->requireAdmin();
    $plans = $store->load('subscription_plans.json');
    $planId = (int)($_POST['plan_id'] ?? 0);
    $plan = [
        'name'           => trim($_POST['plan_name'] ?? ''),
        'type'           => trim($_POST['plan_type'] ?? 'starlink'),
        'starlink_cost'  => round((float)($_POST['starlink_cost'] ?? 0), 2),
        'customer_price' => round((float)($_POST['customer_price'] ?? 0), 2),
        'is_active'      => isset($_POST['plan_active']) ? true : false,
        'color'          => trim($_POST['plan_color'] ?? '#2196F3'),
        'updated_at'     => date('Y-m-d H:i:s'),
    ];
    $plan['profit']  = round($plan['customer_price'] - $plan['starlink_cost'], 2);
    $plan['margin']  = $plan['customer_price'] > 0 ? round(($plan['profit'] / $plan['customer_price']) * 100, 1) : 0;

    $existingUcrmId = 0;
    if ($planId > 0) {
        foreach ($plans as &$p) {
            if ((int)($p['id']??0)===$planId) {
                $existingUcrmId = (int)($p['ucrm_product_id'] ?? 0);
                $p = array_merge($p, $plan);
                break;
            }
        }
        unset($p);
    } else {
        $plan['id'] = $store->nextId('subscription_plans.json');
        $plan['created_at'] = date('Y-m-d H:i:s');
        $plans[] = $plan;
        $planId = $plan['id'];
    }

    // ── Auto-sync to UCRM products ──────────────────────────────────────────
    // Create product in UCRM if new, update name/price if already mapped.
    $ucrmMsg = '';
    // 5.18.102 (Uganda, docs/71 §K.3, D12): uCRM's Products are the price list the quotes and the cash come from, so a
    // saved plan writes only the plugin's row: no PATCH, no POST, no ucrm_product_id written back. Elsewhere unchanged.
    require_once dirname(__DIR__, 2) . '/lib/QuoteOrganization.php';
    $ugNoPush = QuoteOrganization::setting(is_array($config ?? null) ? $config : [], $dataDir ?? null)['on'];
    if ($ugNoPush) $ucrmMsg = ' uCRM\'s product was not changed: on Uganda, prices are set in uCRM Products.';
    try {
        require_once dirname(__DIR__, 2) . '/lib/CrmApiClient.php';
        $crmSync = CrmApiClient::fromUcrm(dirname(__DIR__), $config ?? []);
        if (!$ugNoPush && $crmSync->isConfigured()) {   // 5.18.102: never where the gate is on (above)
            $productPayload = [
                'name'         => $plan['name'],
                'invoiceLabel' => $plan['name'],
                'unit'         => 'Month',
                'price'        => (float)$plan['customer_price'],
                'taxable'      => false,
            ];
            if ($existingUcrmId > 0) {
                // PATCH existing product — update name + price
                $crmSync->patch("products/{$existingUcrmId}", $productPayload);
                $ucrmMsg = ' UCRM product #' . $existingUcrmId . ' updated.';
                // Write ucrm_product_id back (already there, but keep it)
                foreach ($plans as &$p2) {
                    if ((int)($p2['id']??0) === $planId) { $p2['ucrm_product_id'] = $existingUcrmId; break; }
                }
                unset($p2);
            } else {
                // POST new product
                $resp = $crmSync->post('products', $productPayload);
                if (!empty($resp['id'])) {
                    $newProdId = (int)$resp['id'];
                    foreach ($plans as &$p2) {
                        if ((int)($p2['id']??0) === $planId) { $p2['ucrm_product_id'] = $newProdId; break; }
                    }
                    unset($p2);
                    $ucrmMsg = ' UCRM product #' . $newProdId . ' created.';
                }
            }
        }
    } catch (Throwable $e) {
        error_log('[save_plan] UCRM sync failed: ' . $e->getMessage());
    }
    // ── End UCRM sync ───────────────────────────────────────────────────────

    $store->save('subscription_plans.json', $plans);
    flash(($planId && !isset($plan['created_at']) ? 'Plan updated.' : 'Plan created.') . $ucrmMsg, $ugNoPush ? 'warning' : 'success');   // 5.18.102: amber when uCRM was left as it is
    redirect('?page=dashboard&tab=subscription_plans');
}
// ── A photo or spec sheet for one piece of equipment ────────────────────────
// The media name is derived from the equipment TITLE, so "Starlink Mini Kit"
// becomes "starlink-mini-kit" — the same name the assistant already sees in
// HARDWARE from uCRM. It asks for the product by the name it knows, and the
// file is there under that name. Nothing to keep in step by hand.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload_hw_media') {
    if (function_exists('csrfCheck')) csrfCheck();
    require_once dirname(__DIR__, 2) . '/lib/MediaLibrary.php';
    $hwTitle = trim((string)($_POST['hw_title'] ?? ''));
    $kind    = ($_POST['media_kind'] ?? '') === 'document' ? 'document' : 'image';
    if ($hwTitle === '') {
        flash('That equipment has no name to file the upload under.', 'danger');
    } else {
        $r = MediaLibrary::store($dataDir, $_FILES['media_file'] ?? [], $kind, $hwTitle,
                                 (string)($_POST['media_caption'] ?? ''));
        flash($r['ok']
            ? ('Saved for ' . $hwTitle . ' as "' . $r['name'] . '". The assistant can send it now.')
            : $r['error'], $r['ok'] ? 'success' : 'danger');
    }
    redirect('?page=dashboard&tab=hardware');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'caption_hw_media') {
    if (function_exists('csrfCheck')) csrfCheck();
    require_once dirname(__DIR__, 2) . '/lib/MediaLibrary.php';
    $kind = ($_POST['media_kind'] ?? '') === 'document' ? 'document' : 'image';
    $nm   = (string)($_POST['media_name'] ?? '');
    $cap  = trim((string)($_POST['media_caption'] ?? ''));
    if (!MediaLibrary::caption($dataDir, $kind, $nm, $cap)) {
        flash('Could not set that caption — there is nothing stored under "' . $nm . '".', 'danger');
    } else {
        flash($cap === ''
            ? ('Caption removed. "' . $nm . '" will now be sent with no message.')
            : ('"' . $nm . '" will be sent with: ' . $cap), 'success');
    }
    redirect('?page=dashboard&tab=hardware');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_hw_media') {
    if (function_exists('csrfCheck')) csrfCheck();
    require_once dirname(__DIR__, 2) . '/lib/MediaLibrary.php';
    $kind = ($_POST['media_kind'] ?? '') === 'document' ? 'document' : 'image';
    $nm   = (string)($_POST['media_name'] ?? '');
    flash(MediaLibrary::remove($dataDir, $kind, $nm) ? ('Removed "' . $nm . '".')
        : 'Nothing was removed.', 'success');
    redirect('?page=dashboard&tab=hardware');
}

if($_SERVER['REQUEST_METHOD']==='POST'&&($_POST['action']??'')==='save_hardware'){
    $auth->requireAdmin();
    $hw = $store->load('kyc_devices.json');
    $hwId = (int)($_POST['hw_id'] ?? 0);
    $item = [
        'title'       => trim($_POST['hw_title'] ?? ''),
        'type'        => trim($_POST['hw_type'] ?? 'starlink'),
        'buy_price'   => round((float)($_POST['hw_buy'] ?? 0), 2),
        'sell_price'  => round((float)($_POST['hw_sell'] ?? 0), 2),
        'price'       => 'USD ' . number_format((float)($_POST['hw_sell'] ?? 0), 0),
        'is_active'   => isset($_POST['hw_active']),
        'sku'         => trim($_POST['hw_sku'] ?? ''),
        'description' => trim($_POST['hw_description'] ?? ''),
    ];
    // Manual UCRM Product ID override — if user typed one, use it
    $manualUcrmId = (int)($_POST['hw_ucrm_product_id'] ?? 0);
    // 5.18.102 (Uganda, D14, docs/71 §R.2.2, Q8): where the quoting-organization gate is on the field is not drawn and
    // hw_ucrm_product_id is ignored: quoting never reads ucrm_product_id, and a value typed here would become productId
    // on Uganda with the gate off, or after a rollback (§R.1.2). A stored value is kept untouched. Elsewhere unchanged.
    require_once dirname(__DIR__, 2) . '/lib/QuoteOrganization.php';
    if (QuoteOrganization::setting(is_array($config ?? null) ? $config : [], $dataDir ?? null)['on']) $manualUcrmId = 0;

    $existingHwUcrmId = 0;
    $savedHwId = $hwId;
    if ($hwId > 0) {
        foreach ($hw as &$h2) {
            if ((int)($h2['id']??0)===$hwId) {
                $existingHwUcrmId = $manualUcrmId > 0 ? $manualUcrmId : (int)($h2['ucrm_product_id'] ?? 0);
                if ($manualUcrmId > 0) $item['ucrm_product_id'] = $manualUcrmId;
                $h2 = array_merge($h2, $item);
                break;
            }
        }
        unset($h2);
    } else {
        if ($manualUcrmId > 0) {
            $item['ucrm_product_id'] = $manualUcrmId;
            $existingHwUcrmId = $manualUcrmId;
        }
        $item['id'] = $store->nextId('kyc_devices.json');
        $item['created_at'] = date('Y-m-d H:i:s');
        $hw[] = $item;
        $savedHwId = $item['id'];
    }

    // ── Auto-sync to UCRM products ──────────────────────────────────────────
    $hwUcrmMsg = '';
    // 5.18.102 (Uganda, docs/71 §K.3, D12): as save_plan — the plugin's row only; uCRM's product is left as it is.
    require_once dirname(__DIR__, 2) . '/lib/QuoteOrganization.php';
    $ugNoPush = QuoteOrganization::setting(is_array($config ?? null) ? $config : [], $dataDir ?? null)['on'];
    if ($ugNoPush) $hwUcrmMsg = ' uCRM\'s product was not changed: on Uganda, prices are set in uCRM Products.';
    try {
        require_once dirname(__DIR__, 2) . '/lib/CrmApiClient.php';
        $crmHw = CrmApiClient::fromUcrm(dirname(__DIR__), $config ?? []);
        if (!$ugNoPush && $crmHw->isConfigured()) {   // 5.18.102: never where the gate is on (above)
            $hwPayload = [
                'name'         => $item['title'],
                'invoiceLabel' => $item['title'],
                'unit'         => 'Nos',
                'price'        => (float)$item['sell_price'],
                'taxable'      => false,
            ];
            if ($existingHwUcrmId > 0) {
                $crmHw->patch("products/{$existingHwUcrmId}", $hwPayload);
                $hwUcrmMsg = ' UCRM product #' . $existingHwUcrmId . ' updated.';
                foreach ($hw as &$h3) {
                    if ((int)($h3['id']??0) === $savedHwId) { $h3['ucrm_product_id'] = $existingHwUcrmId; break; }
                }
                unset($h3);
            } else {
                $hwResp = $crmHw->post('products', $hwPayload);
                if (!empty($hwResp['id'])) {
                    $newHwProdId = (int)$hwResp['id'];
                    foreach ($hw as &$h3) {
                        if ((int)($h3['id']??0) === $savedHwId) { $h3['ucrm_product_id'] = $newHwProdId; break; }
                    }
                    unset($h3);
                    $hwUcrmMsg = ' UCRM product #' . $newHwProdId . ' created.';
                }
            }
        }
    } catch (Throwable $e) {
        error_log('[save_hardware] UCRM sync failed: ' . $e->getMessage());
    }
    // ── End UCRM sync ───────────────────────────────────────────────────────

    $store->save('kyc_devices.json', $hw);
    flash(($hwId > 0 ? 'Hardware updated.' : 'Hardware added.') . $hwUcrmMsg, $ugNoPush ? 'warning' : 'success');   // 5.18.102: amber when uCRM was left as it is
    redirect('?page=dashboard&tab=hardware');
}
if($_SERVER['REQUEST_METHOD']==='POST'&&($_POST['action']??'')==='delete_hardware'){
    $auth->requireAdmin();
    $delId = (int)($_POST['hw_id'] ?? 0);
    $hw = array_values(array_filter($store->load('kyc_devices.json'), fn($h2) => (int)($h2['id']??0)!==$delId));
    $store->save('kyc_devices.json', $hw);
    // 5.18.102 (Uganda, D14, docs/71 §R.1.3): the deleted row's uCRM links end with it — every active record of the row,
    // ended_why "row deleted", in this request — only where the quoting-organization gate is on (the link store is never
    // opened elsewhere, §R.1.5). A failure is logged with ids only; a reused id is still caught by its stamp.
    require_once dirname(__DIR__, 2) . '/lib/QuoteOrganization.php';
    if ($delId > 0 && QuoteOrganization::setting(is_array($config ?? null) ? $config : [], isset($dataDir) ? (string)$dataDir : null)['on']) {
        require_once dirname(__DIR__, 2) . '/lib/UcrmQuoteLines.php';   // loads lib/UcrmQuoteLinks.php (F-6)
        try {
            $_dlEnded = UcrmQuoteLinks::end($store, 'kit', $delId, UcrmQuoteLinks::WHY_DELETED, (array)($auth->currentRetailer() ?? []));
            if ($_dlEnded !== []) error_log('[post_sync] 5.18.102 delete_hardware: kit #' . $delId . ': ' . count($_dlEnded) . ' uCRM link record(s) ended');
        } catch (\Throwable $e) {
            error_log('[post_sync] 5.18.102 delete_hardware: kit #' . $delId . ': its uCRM links could not be ended (' . get_class($e) . ')');
        }
    }
    flash('Hardware deleted.','success');
    redirect('?page=dashboard&tab=hardware');
}
// ── Save Fiber Installation Fee ─────────────────────────────────────────
if($_SERVER['REQUEST_METHOD']==='POST'&&($_POST['action']??'')==='save_install_fee'){
    $auth->requireAdmin();
    $cfg = $store->load('kyc_config.json') ?? [];
    $cfg['fiber_install_fee']        = max(0, (float)($_POST['fiber_install_fee'] ?? 100));
    $cfg['fiber_install_product_id'] = max(0, (int)($_POST['fiber_install_product_id'] ?? 244));
    $store->save('kyc_config.json', $cfg);
    flash('✅ Fiber installation fee updated to ' . dn_cur($config) . number_format($cfg['fiber_install_fee'], 2) . ' (UCRM Product #' . $cfg['fiber_install_product_id'] . ').', 'success');
    redirect('?page=dashboard&tab=hardware');
}
if($_SERVER['REQUEST_METHOD']==='POST'&&($_POST['action']??'')==='delete_plan'){
    $auth->requireAdmin();
    $delId = (int)($_POST['plan_id'] ?? 0);
    $plans = array_values(array_filter($store->load('subscription_plans.json'), fn($p) => (int)($p['id']??0)!==$delId));
    $store->save('subscription_plans.json', $plans);
    // 5.18.102 (Uganda, D14, docs/71 §R.1.3): as delete_hardware — the deleted plan's uCRM links end with it, every active
    // record, ended_why "row deleted", only where the quoting-organization gate is on.
    require_once dirname(__DIR__, 2) . '/lib/QuoteOrganization.php';
    if ($delId > 0 && QuoteOrganization::setting(is_array($config ?? null) ? $config : [], isset($dataDir) ? (string)$dataDir : null)['on']) {
        require_once dirname(__DIR__, 2) . '/lib/UcrmQuoteLines.php';   // loads lib/UcrmQuoteLinks.php (F-6)
        try {
            $_dlEnded = UcrmQuoteLinks::end($store, 'plan', $delId, UcrmQuoteLinks::WHY_DELETED, (array)($auth->currentRetailer() ?? []));
            if ($_dlEnded !== []) error_log('[post_sync] 5.18.102 delete_plan: plan #' . $delId . ': ' . count($_dlEnded) . ' uCRM link record(s) ended');
        } catch (\Throwable $e) {
            error_log('[post_sync] 5.18.102 delete_plan: plan #' . $delId . ': its uCRM links could not be ended (' . get_class($e) . ')');
        }
    }
    flash('Plan deleted.','success');
    redirect('?page=dashboard&tab=subscription_plans');
}

// ═══════════════════════════════════════════════════════════════════════════════════════════════════════════════════
// 5.18.102 (Uganda, D14, docs/71 §R.2, §R.1.4, §R.3.1): link a plan, a kit or the installation to its uCRM Product, and
// unlink it. Each block is handled only where the quoting-organization gate is on; with it off the block is skipped
// whole and the action falls through as an unknown one does in 5.18.101. In this order, each step before the next and
// each refusal writing nothing:
//   1. $auth->requireAdmin() (the sign-in redirect, or 403), then the account re-read fresh by id — refused with 403
//      unless it is the same account, active and an admin (BankGate::allows's expression, written here: requireAdmin
//      reads the session's cached record, which can be stale, §R.2.1);
//   2. the own-page check, made here and never left to the global CSRF gate (which is skipped for any POST carrying
//      cb_action or a tab-action field, and whose token is the same for everyone all day): csrfCheck()'s result used,
//      AND not CustomerSession::crossSite() AND BankGate::fromOwnPage('POST') — post_bank.php's check, both classes
//      loaded unchanged. A refusal: one fixed flash and a fixed screen address;
//   2a. (save) the quoting-organization setting must be valid (QuoteOrganization::setting()['id'] an int). Otherwise one
//      fixed flash naming the setting, with no uCRM read and no lock (5.18.102 D14 review, G2; round 2, FX-money-2). It
//      comes after 1 and 2, so a non-admin is still answered 403 and a cross-site post the security flash, never its text;
//   3. (save) rule 2: the row in its own table, not Fiber — its id and stamp kept for the check inside the lock;
//   4. (save) rules 3–8: a fresh read through UcrmQuoteLines::quoteClient (the client the quotes are made with, its
//      normal timeouts, never the page cache), outside any transaction, judged by the resolver's own functions
//      (candidate()), so save and use cannot apply different rules;
//   5. UcrmQuoteLinks::set() / ::end(): inside withLock, store checks only, then the write. Whatever withLock throws (it
//      has rolled back) is answered "nothing was linked / unlinked — try again".
// Every flash is fixed text plus ids, or names passed through QuoteOrganization::plain (the layout prints it raw). The
// log carries ids and counts only, to PHP's error_log.
// ═══════════════════════════════════════════════════════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'ucrm_link_save') {
    require_once dirname(__DIR__, 2) . '/lib/QuoteOrganization.php';
    if (QuoteOrganization::setting(is_array($config ?? null) ? $config : [], isset($dataDir) ? (string)$dataDir : null)['on']) {
        $_lkItem = is_string($_POST['item'] ?? null) ? (string)$_POST['item'] : '';
        $_lkBack = $_lkItem === 'plan' ? '?page=dashboard&tab=subscription_plans' : '?page=dashboard&tab=hardware';

        // 1. an admin, judged on the account record re-read fresh by id — never the session copy
        $_lkMe = $auth->requireAdmin();
        $_lkMeId = (int)($_lkMe['id'] ?? 0);
        try {
            $_lkFresh = $_lkMeId > 0 ? $store->findOne('retailers.json', 'id', $_lkMeId) : null;
        } catch (\Throwable $e) {
            $_lkFresh = null;
        }
        if (!is_array($_lkFresh) || $_lkMeId <= 0 || (int)($_lkFresh['id'] ?? 0) !== $_lkMeId
            || empty($_lkFresh['is_active']) || empty($_lkFresh['is_admin'])) {
            http_response_code(403);
            die('<p style="color:red;padding:20px;">Access denied — admin only.</p>');
        }

        // 2. the own-page check, made here (post_bank.php's)
        require_once dirname(__DIR__, 2) . '/lib/BankGate.php';
        require_once dirname(__DIR__, 2) . '/lib/CustomerSession.php';
        if (!(csrfCheck() && !CustomerSession::crossSite() && BankGate::fromOwnPage('POST'))) {
            flash('The security check failed, so nothing was changed. Open the screen again and retry.', 'danger');
            redirect($_lkBack);
        }

        require_once dirname(__DIR__, 2) . '/lib/UcrmQuoteLines.php';   // loads lib/UcrmQuoteLinks.php (F-6)
        $_lkSet = QuoteOrganization::setting(is_array($config ?? null) ? $config : [], isset($dataDir) ? (string)$dataDir : null);
        // 5.18.102 D14 review (G2), 5.18.99 R6: the quoting organization must be set correctly — no uCRM read, no lock,
        // one fixed answer naming the setting (never "changed … try again", which no retry could cure)
        if (!is_int($_lkSet['id'] ?? null)) {
            error_log('[post_sync] 5.18.102 ucrm_link_save: refused, the quoting-organization setting is invalid, by #' . $_lkMeId);
            flash('The company the app quotes under is not set correctly, so nothing was linked. Tell an admin.', 'danger');
            redirect($_lkBack);
        }
        $_lkNum = function ($v): int {
            return (is_string($v) && $v !== '' && strlen($v) <= 9 && ctype_digit($v)) ? (int)$v : 0;
        };
        $_lkPlain = function ($name, string $fallback): string {
            $p = QuoteOrganization::plain(is_scalar($name) ? (string)$name : '');
            return $p !== '' ? $p : $fallback;
        };
        // A reason, as a flash: the resolver's own words (names already plain), said once that nothing was linked
        $_lkSay = function (string $reason): string {
            $r = rtrim(trim($reason), '.');
            if (strpos($r, 'nothing was linked') === false) return 'Nothing was linked: ' . $r . '.';
            return (strpos($r, 'uCRM') === 0 ? $r : ucfirst($r)) . '.';
        };
        $_lkRowId = $_lkItem === 'install' ? 0 : $_lkNum($_POST['row_id'] ?? null);
        $_lkPid   = $_lkNum($_POST['product_id'] ?? null);
        $_lkSpid  = $_lkItem === 'plan' ? $_lkNum($_POST['service_plan_id'] ?? null) : 0;
        if (!in_array($_lkItem, UcrmQuoteLinks::ITEMS, true) || ($_lkItem !== 'install' && $_lkRowId <= 0)
            || $_lkPid <= 0 || ($_lkItem === 'plan' && $_lkSpid <= 0)) {
            flash($_lkItem === 'plan' ? 'Nothing was linked. Pick a uCRM product and a uCRM service plan, then save.'
                                      : 'Nothing was linked. Pick a uCRM product, then save.', 'danger');
            redirect($_lkBack);
        }

        // 3. rule 2: the row in its own table, not Fiber; its id and stamp are kept for the check inside the lock
        $_lkRow = null;
        if ($_lkItem !== 'install') {
            try {
                $_lkRow = $store->findOne($_lkItem === 'plan' ? 'subscription_plans.json' : 'kyc_devices.json', 'id', $_lkRowId);
            } catch (\Throwable $e) {
                $_lkRow = null;
            }
            if (!is_array($_lkRow) || (int)($_lkRow['id'] ?? 0) !== $_lkRowId) {
                flash('Nothing was linked. ' . ($_lkItem === 'plan' ? 'Plan #' : 'Hardware row #') . (int)$_lkRowId
                    . ' is not on this screen any more.', 'danger');
                redirect($_lkBack);
            }
            if (UcrmQuoteLinks::isFiber($_lkItem, $_lkRow)) {
                flash($_lkSay(UcrmQuoteLines::FIBER_REASON), 'danger');
                redirect($_lkBack);
            }
        }
        $_lkRowName = $_lkItem === 'install' ? 'Installation' : (string)($_lkRow[$_lkItem === 'plan' ? 'name' : 'title'] ?? '');
        $_lkShown   = $_lkItem === 'install' ? 'Installation'
                    : $_lkPlain($_lkRowName, ($_lkItem === 'plan' ? 'Plan #' : 'Hardware row #') . (int)$_lkRowId);

        // 4. rules 3–8: a fresh read through the quoting client, outside any transaction — the resolver's own rules
        try {
            $_lkCrm   = (isset($crm) && is_object($crm)) ? $crm : svc('crm');
            $_lkLines = new UcrmQuoteLines(UcrmQuoteLines::quoteClient($_lkCrm, is_array($config ?? null) ? $config : []),
                $_lkSet, UcrmQuoteLinks::fromStore($store, $_lkSet));
            $_lkCand = $_lkLines->candidate($_lkItem, $_lkRow, $_lkPid, $_lkItem === 'plan' ? $_lkSpid : null);
            $_lkCat  = $_lkLines->catalogue();
        } catch (\Throwable $e) {
            $_lkCand = ['ok' => false, 'reason' => "uCRM's products could not be read — nothing was linked"];
            $_lkCat  = ['plans' => []];
        }
        if (empty($_lkCand['ok']) || !is_array($_lkCand['line'] ?? null)) {
            error_log('[post_sync] 5.18.102 ucrm_link_save: ' . $_lkItem . ' #' . $_lkRowId . ' to product #' . $_lkPid
                . ($_lkItem === 'plan' ? ' and service plan #' . $_lkSpid : '') . ' refused before the lock, by #' . $_lkMeId);
            flash($_lkSay((string)($_lkCand['reason'] ?? '')), 'danger');
            redirect($_lkBack);
        }
        $_lkProdName = (string)($_lkCand['line']['label'] ?? '');
        $_lkSpName   = '';
        if ($_lkItem === 'plan') {
            foreach ((array)($_lkCat['plans'] ?? []) as $_lkSp) {
                if (is_array($_lkSp) && (int)($_lkSp['id'] ?? 0) === $_lkSpid) { $_lkSpName = (string)($_lkSp['name'] ?? ''); break; }
            }
        }

        // 5. the write, inside the lock: store checks only (the row and its stamp again, rule 8 again), then one record
        try {
            $_lkRes = UcrmQuoteLinks::set($store, [
                'item'              => $_lkItem,
                'row_id'            => $_lkRowId,
                'row_stamp'         => $_lkRow !== null ? UcrmQuoteLinks::rowStamp($_lkRow) : '',
                'product_id'        => $_lkPid,
                'service_plan_id'   => $_lkItem === 'plan' ? $_lkSpid : null,
                'row_name'          => $_lkRowName,
                'product_name'      => $_lkProdName,
                'service_plan_name' => $_lkSpName,
            ], $_lkFresh, is_array($config ?? null) ? $config : [], isset($dataDir) ? (string)$dataDir : null);
        } catch (\Throwable $e) {
            error_log('[post_sync] 5.18.102 ucrm_link_save: ' . $_lkItem . ' #' . $_lkRowId . ': nothing was linked, the lock failed ('
                . get_class($e) . ')');
            flash('Nothing was linked — try again.', 'danger');
            redirect($_lkBack);
        }
        $_lkKind  = (string)($_lkRes['kind'] ?? '');
        $_lkEnded = array_values(array_filter((array)($_lkRes['ended'] ?? []), 'is_int'));
        $_lkWhat  = 'uCRM product #' . (int)$_lkPid . ' (' . $_lkPlain($_lkProdName, 'no name') . ')'
                  . ($_lkItem === 'plan' ? ' and service plan #' . (int)$_lkSpid . ' (' . $_lkPlain($_lkSpName, 'no name') . ')' : '');
        $_lkCount = '';
        $_lkLog   = '';
        if ($_lkEnded !== []) {
            // §R.2.3: what was quoted under the records this save ended — KYC applications only, by sales type
            try {
                $_lkN = UcrmQuoteLinks::countQuoted($store, $_lkSet, $_lkEnded);
                $_lkCount = ' ' . ((int)$_lkN['cash'] + (int)$_lkN['credit']) . ' application(s) were quoted under the previous link ('
                          . (int)$_lkN['cash'] . ' Cash, ' . (int)$_lkN['credit'] . ' Credit). Review them in uCRM.';
                foreach ($_lkEnded as $_lkE) {
                    $_lkCount .= ' <a href="?page=dashboard&amp;tab=applications&amp;quote_link=' . (int)$_lkE
                               . '">Orders quoted under link #' . (int)$_lkE . '</a>';
                }
                $_lkLog = ', quoted under them ' . (int)$_lkN['cash'] . ' Cash and ' . (int)$_lkN['credit'] . ' Credit';
            } catch (\Throwable $e) {
                $_lkCount = ' The applications quoted under the previous link could not be counted.';
            }
        }
        if ($_lkKind === 'linked') {
            flash($_lkShown . ' is linked to ' . $_lkWhat . '.' . $_lkCount, 'success');
        } elseif ($_lkKind === 'already') {
            flash($_lkShown . ' is already linked to ' . $_lkWhat . ' — nothing was changed.', 'info');
        } else {
            flash($_lkSay((string)($_lkRes['text'] ?? '')), 'danger');   // changed, or rule 8 inside the lock
        }
        error_log('[post_sync] 5.18.102 ucrm_link_save: ' . $_lkItem . ' #' . $_lkRowId . ' to product #' . $_lkPid
            . ($_lkItem === 'plan' ? ' and service plan #' . $_lkSpid : '') . ' by #' . $_lkMeId . ' gave ' . $_lkKind
            . (is_array($_lkRes['record'] ?? null) ? ' (record #' . (int)($_lkRes['record']['id'] ?? 0) . ')' : '')
            . ', ' . count($_lkEnded) . ' record(s) ended' . $_lkLog);
        redirect($_lkBack);
    }
}

// 5.18.102 (Uganda, D14, docs/71 §R.2.3, §R.1.4): unlink — every active record of the item ends ("unlinked"), after the
// screen's confirmation. Steps 1 and 2 above, then UcrmQuoteLinks::end() inside the lock; no uCRM read.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'ucrm_link_clear') {
    require_once dirname(__DIR__, 2) . '/lib/QuoteOrganization.php';
    if (QuoteOrganization::setting(is_array($config ?? null) ? $config : [], isset($dataDir) ? (string)$dataDir : null)['on']) {
        $_ulItem = is_string($_POST['item'] ?? null) ? (string)$_POST['item'] : '';
        $_ulBack = $_ulItem === 'plan' ? '?page=dashboard&tab=subscription_plans' : '?page=dashboard&tab=hardware';

        // 1. an admin, judged on the account record re-read fresh by id — never the session copy
        $_ulMe = $auth->requireAdmin();
        $_ulMeId = (int)($_ulMe['id'] ?? 0);
        try {
            $_ulFresh = $_ulMeId > 0 ? $store->findOne('retailers.json', 'id', $_ulMeId) : null;
        } catch (\Throwable $e) {
            $_ulFresh = null;
        }
        if (!is_array($_ulFresh) || $_ulMeId <= 0 || (int)($_ulFresh['id'] ?? 0) !== $_ulMeId
            || empty($_ulFresh['is_active']) || empty($_ulFresh['is_admin'])) {
            http_response_code(403);
            die('<p style="color:red;padding:20px;">Access denied — admin only.</p>');
        }

        // 2. the own-page check, made here (post_bank.php's)
        require_once dirname(__DIR__, 2) . '/lib/BankGate.php';
        require_once dirname(__DIR__, 2) . '/lib/CustomerSession.php';
        if (!(csrfCheck() && !CustomerSession::crossSite() && BankGate::fromOwnPage('POST'))) {
            flash('The security check failed, so nothing was changed. Open the screen again and retry.', 'danger');
            redirect($_ulBack);
        }

        require_once dirname(__DIR__, 2) . '/lib/UcrmQuoteLines.php';   // loads lib/UcrmQuoteLinks.php (F-6)
        $_ulSet = QuoteOrganization::setting(is_array($config ?? null) ? $config : [], isset($dataDir) ? (string)$dataDir : null);
        $_ulRaw = $_POST['row_id'] ?? null;
        $_ulRowId = $_ulItem === 'install' ? 0
                  : ((is_string($_ulRaw) && $_ulRaw !== '' && strlen($_ulRaw) <= 9 && ctype_digit($_ulRaw)) ? (int)$_ulRaw : 0);
        if (!in_array($_ulItem, UcrmQuoteLinks::ITEMS, true) || ($_ulItem !== 'install' && $_ulRowId <= 0)) {
            flash('Nothing was unlinked. The request did not name a plan, a hardware row or the installation.', 'danger');
            redirect($_ulBack);
        }
        $_ulShown = 'Installation';
        if ($_ulItem !== 'install') {
            try {
                $_ulRow = $store->findOne($_ulItem === 'plan' ? 'subscription_plans.json' : 'kyc_devices.json', 'id', $_ulRowId);
            } catch (\Throwable $e) {
                $_ulRow = null;
            }
            $_ulName  = QuoteOrganization::plain(is_array($_ulRow) ? (string)($_ulRow[$_ulItem === 'plan' ? 'name' : 'title'] ?? '') : '');
            $_ulShown = $_ulName !== '' ? $_ulName : ($_ulItem === 'plan' ? 'Plan #' : 'Hardware row #') . (int)$_ulRowId;
        }

        try {
            $_ulEnded = UcrmQuoteLinks::end($store, $_ulItem, $_ulRowId, UcrmQuoteLinks::WHY_UNLINKED, $_ulFresh);
        } catch (\Throwable $e) {
            error_log('[post_sync] 5.18.102 ucrm_link_clear: ' . $_ulItem . ' #' . $_ulRowId . ': nothing was unlinked, the lock failed ('
                . get_class($e) . ')');
            flash('Nothing was unlinked — try again.', 'danger');
            redirect($_ulBack);
        }
        $_ulEnded = array_values(array_filter((array)$_ulEnded, 'is_int'));
        if ($_ulEnded === []) {
            flash($_ulShown . ' had no uCRM link — nothing was changed.', 'info');
        } else {
            $_ulCount = '';
            $_ulLog   = '';
            try {
                $_ulN = UcrmQuoteLinks::countQuoted($store, $_ulSet, $_ulEnded);
                $_ulCount = ' ' . ((int)$_ulN['cash'] + (int)$_ulN['credit']) . ' application(s) were quoted under the previous link ('
                          . (int)$_ulN['cash'] . ' Cash, ' . (int)$_ulN['credit'] . ' Credit). Review them in uCRM.';
                foreach ($_ulEnded as $_ulE) {
                    $_ulCount .= ' <a href="?page=dashboard&amp;tab=applications&amp;quote_link=' . (int)$_ulE
                               . '">Orders quoted under link #' . (int)$_ulE . '</a>';
                }
                $_ulLog = ', quoted under them ' . (int)$_ulN['cash'] . ' Cash and ' . (int)$_ulN['credit'] . ' Credit';
            } catch (\Throwable $e) {
                $_ulCount = ' The applications quoted under the previous link could not be counted.';
            }
            flash($_ulShown . ' is no longer linked to uCRM. Cash sales of it are refused until it is linked again.' . $_ulCount, 'warning');
            error_log('[post_sync] 5.18.102 ucrm_link_clear: ' . $_ulItem . ' #' . $_ulRowId . ' by #' . $_ulMeId . ' ended '
                . count($_ulEnded) . ' record(s)' . $_ulLog);
        }
        redirect($_ulBack);
    }
}

// ═══════════════════════════════════════════════════════════════════════════════════════════════════════════════════
// 5.18.102 (Uganda, D14, docs/71 §R.4.8, E-2): Make the quotation — the Orders card's button for a web registration left
// unpriced (P1). Handled only where the quoting-organization gate is on; with it off the block is skipped whole and the
// action falls through as an unknown one does in 5.18.101. In this order, each refusal at steps 3–5 writing nothing and
// sending no request to uCRM:
//   1. a POST with this action, the gate on;
//   2. $auth->requireAdmin(), then the account re-read fresh by id: 403 unless the same account, active and an admin;
//   3. the own-page check, made here (post_bank.php's): csrfCheck()'s result used, not CustomerSession::crossSite(),
//      BankGate::fromOwnPage('POST');
//   4. nothing from the request but the application's number: any key but action, _csrf and app_id, or an app_id that
//      is not digits only, is refused — the entry, the client, the amount and the lines all come from the store;
//   5. the application re-read by findOne, and the button's five conditions checked again there
//      (KycService::canMakeQuotation, the one implementation the card draws by);
//   6. svc('kyc')->makeQuotation($app, null, 'orders_card', $fresh) — the shared implementation retry_quote calls too;
//      no organization known, so postQuote() makes the company check itself;
//   7. a flash chosen by the answer's kind, never by its code (four kinds share 409), then a fixed address.
// Every id in a flash is cast with (int): the layout prints the flash raw, and postQuote() returns uCRM's id raw.
// ═══════════════════════════════════════════════════════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'kyc_make_quote') {
    require_once dirname(__DIR__, 2) . '/lib/QuoteOrganization.php';
    if (QuoteOrganization::setting(is_array($config ?? null) ? $config : [], isset($dataDir) ? (string)$dataDir : null)['on']) {
        $_mqBack = '?page=dashboard&tab=applications';

        // 2. an admin, judged on the account record re-read fresh by id — never the session copy
        $_mqMe = $auth->requireAdmin();
        $_mqMeId = (int)($_mqMe['id'] ?? 0);
        try {
            $_mqFresh = $_mqMeId > 0 ? $store->findOne('retailers.json', 'id', $_mqMeId) : null;
        } catch (\Throwable $e) {
            $_mqFresh = null;
        }
        if (!is_array($_mqFresh) || $_mqMeId <= 0 || (int)($_mqFresh['id'] ?? 0) !== $_mqMeId
            || empty($_mqFresh['is_active']) || empty($_mqFresh['is_admin'])) {
            http_response_code(403);
            die('<p style="color:red;padding:20px;">Access denied — admin only.</p>');
        }

        // 3. the own-page check, made here (post_bank.php's)
        require_once dirname(__DIR__, 2) . '/lib/BankGate.php';
        require_once dirname(__DIR__, 2) . '/lib/CustomerSession.php';
        if (!(csrfCheck() && !CustomerSession::crossSite() && BankGate::fromOwnPage('POST'))) {
            flash('The security check failed, so nothing was changed. Open the Orders screen again and retry.', 'danger');
            redirect($_mqBack);
        }

        // 4. nothing from the request but the application's number
        $_mqOther = array_diff(array_map('strval', array_keys($_POST)), ['action', '_csrf', 'app_id']);
        $_mqRaw   = $_POST['app_id'] ?? null;
        if ($_mqOther !== [] || !is_string($_mqRaw) || $_mqRaw === '' || !ctype_digit($_mqRaw)) {
            flash('The request carried more than the application\'s number, so nothing was changed. Open the Orders screen again and retry.', 'danger');
            redirect($_mqBack);
        }
        $_mqId = (int)$_mqRaw;

        // 5. the application as the store holds it, and the button's five conditions again
        require_once dirname(__DIR__, 2) . '/lib/KycService.php';
        try {
            $_mqApp = $store->findOne('kyc_applications.json', 'id', $_mqId);
        } catch (\Throwable $e) {
            error_log('[post_sync] 5.18.102 kyc_make_quote: application #' . (int)$_mqId . ' could not be read (' . get_class($e) . ')');
            flash('No quotation was made for application #' . (int)$_mqId . ' — try again.', 'danger');
            redirect($_mqBack);
        }
        if (!is_array($_mqApp) || (int)($_mqApp['id'] ?? 0) !== $_mqId || !KycService::canMakeQuotation($_mqApp)) {
            flash('Make the quotation does not apply to application #' . (int)$_mqId . ' now. Nothing was changed.', 'warning');
            redirect($_mqBack);
        }

        // 6. the shared implementation (KycService::makeQuotation): the claim, R-M4's guard, the lines, postQuote()
        try {
            $_mq = svc('kyc')->makeQuotation($_mqApp, null, 'orders_card', $_mqFresh);
        } catch (\Throwable $e) {
            error_log('[post_sync] 5.18.102 kyc_make_quote: application #' . (int)$_mqId . ' by #' . $_mqMeId
                . ': making the quotation did not finish, it may have been made (' . get_class($e) . ')');
            // 5.18.102 D14 review (D14-MQ-THROW-AFTER-POST): a Throwable may come after the POST made the quotation, so
            // the answer never says none exists
            flash('Making the quotation for application #' . (int)$_mqId . ' did not finish. Open the Orders screen again before you retry: uCRM may already have it, and Make the quotation never makes a second one.', 'danger');
            redirect($_mqBack);
        }

        // 7. a flash chosen by the kind
        $_mqKind = is_array($_mq) ? (string)($_mq['kind'] ?? '') : '';
        if ($_mqKind === 'made') {
            flash('Quotation #' . (int)($_mq['quote_id'] ?? 0) . ' was made in uCRM for application #' . (int)$_mqId
                . '. Its WhatsApp follows once uCRM\'s PDF is ready.', 'success');
        } elseif ($_mqKind === 'found') {
            flash('uCRM already has a quotation for this client made after application #' . (int)$_mqId
                . ' was saved — no second one was made.', 'warning');
        } elseif ($_mqKind === 'unreadable') {
            flash('uCRM\'s quotations for this client could not be read, so no quotation was made for application #' . (int)$_mqId
                . ' — try again.', 'warning');
        } elseif ($_mqKind === 'refused') {
            flash('No quotation was made for application #' . (int)$_mqId . ' — its card says why.', 'warning');
        } elseif ($_mqKind === 'claimed') {
            flash('A quotation is being made for application #' . (int)$_mqId . ' — wait, then open the Orders screen again.', 'info');
        } else {
            // error — 5.18.102 D14 review round 2 (FX-flows-2): uCRM's answer held no id, which a lost answer (a timeout, a
            // 5xx) after a quotation it made also gives, so the flash says "did not confirm", never "did not make"
            flash('uCRM did not confirm the quotation for application #' . (int)$_mqId . '. It may have been made: try Make the quotation again later — it never makes a second one.', 'danger');
        }
        error_log('[post_sync] 5.18.102 kyc_make_quote: application #' . (int)$_mqId . ' by #' . $_mqMeId . ' gave ' . $_mqKind);
        redirect($_mqBack);
    }
}
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='sim_inbound') {
    $me = $auth->currentRetailer();
    if ($me && !empty($me['is_admin'])) {
        $lines = array_filter(explode("\n", trim($_POST['sim_csv'] ?? '')));
        $sims = [];
        foreach ($lines as $l) {
            $p = array_map('trim', str_getcsv($l));
            if (count($p) >= 2) $sims[] = ['iccid'=>$p[0],'msisdn'=>$p[1],'imsi'=>$p[2]??'','pin'=>$p[3]??'','puk'=>$p[4]??''];
        }
        if ($sims) { $r = $sim->inboundBatch($sims); flash($r['imported'].' SIMs imported, '.$r['skipped'].' skipped.'); }
        else { flash('No valid SIM data.', 'danger'); }
    }
    redirect('?page=dashboard&tab=sim_cards');
}
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='sim_allocate') {
    $me = $auth->currentRetailer();
    if ($me && !empty($me['is_admin'])) {
        $ids = array_map('intval', array_filter(explode(',', ($_POST['sim_ids'] ?? ''))));
        $toId = (int)($_POST['to_org_id'] ?? 0);
        $toNm = $_POST['to_org_name'] ?? '';
        if ($ids && $toId > 0 && $toNm) { $r = $sim->allocate($ids, $toId, $toNm, (int)$me['id']); flash($r['allocated'].' SIMs allocated.'); }
        else { flash('Missing fields.', 'danger'); }
    }
    redirect('?page=dashboard&tab=sim_cards');
}


// ─── RETAILER: Edit ───────────────────────────────────────────────────────────
if($_SERVER['REQUEST_METHOD']==='POST'&&($_POST['action']??'')==='edit_retailer'){
    $auth->requireAdmin();
    $rId=(int)($_POST['retailer_id']??0);
    if(!$rId){flash('Invalid retailer.','danger');redirect('?page=dashboard&tab=retailers');}
    $target=$store->findOne('retailers.json','id',$rId);
    if(!$target){flash('Retailer not found.','danger');redirect('?page=dashboard&tab=retailers');}
    
    // Get role_id (new RBAC) or fall back to legacy role slug
    $roleId = !empty($_POST['role_id']) ? (int)$_POST['role_id'] : null;
    $roleSlug = $_POST['role'] ?? 'sales';
    
    $updates=[
        'name'       =>trim($_POST['name']??''),
        'email'      =>strtolower(trim($_POST['email']??'')),
        'phone'      =>trim($_POST['phone']??''),
        'is_admin'   =>!empty($_POST['is_admin']),
        'is_active'  =>!empty($_POST['is_active']),
        'on_leave'   =>!empty($_POST['on_leave']),
        'role'       =>$roleSlug,
        'role_id'    =>$roleId,
        'ucrm_user_id'=>(int)($_POST['ucrm_user_id']??0) ?: null,
        'ucrm_app_key'=>trim($_POST['ucrm_app_key']??'') ?: null,
        'project'=>trim($_POST['project']??'') ?: 'dishnet',
        'projects'=>!empty($_POST['projects']) ? array_values(array_filter(array_map('trim', (array)$_POST['projects']))) : ['dishnet'],
        'carry_limit'=>(int)($_POST['carry_limit']??0) ?: null,
    ];
    // Only update password if a new one was supplied
    $newPw=trim($_POST['password']??'');
    if($newPw!=='') $updates['password']=password_hash($newPw,PASSWORD_BCRYPT);
    // Prevent demoting the only remaining admin
    if($target['is_admin']&&!$updates['is_admin']){
        $admins=array_filter($store->load('retailers.json'),fn($r)=>($r['is_admin']??false));
        if(count($admins)<=1){flash('Cannot remove the last admin.','danger');redirect('?page=dashboard&tab=retailers');}
    }
    // ── 5.18.50 (docs/44 J2, J3, D9, M7, release A): Uganda only ─────────────────────────────────────────────────
    // J3: a job-taking account's number is saved in the international form, or kept as typed with a warning.
    // J2/D9/M7: the picker posts "keep" for the link as saved and a number for a choice. Only a choice changes the
    // link, and only when uCRM confirms it: a refusal, or a uCRM that does not answer, leaves the link as it was while
    // the other fields save. A link kept as saved is never re-verified behind the administrator's back, so an old
    // unverified id stays unverified — and still matches nobody. Every other install saves exactly as before:
    // $_erNotes stays empty.
    $_erNotes=[];
    require_once dirname(__DIR__, 2) . '/lib/StaffJobsGate.php';
    if (StaffJobsGate::applies(is_array($config ?? null) ? $config : [], $dataDir ?? null)) {
        foreach (['StaffDirectory', 'UcrmUsers', 'StaffLink', 'TenantProfile', 'PhoneNumber'] as $_erLib) {
            require_once dirname(__DIR__, 2) . '/lib/' . $_erLib . '.php';
        }
        $_erCand = array_merge($target, $updates);   // the account as it will be saved
        if (StaffDirectory::takesJobs($_erCand) && $updates['phone'] !== '') {
            $_erIntl = null;
            try {
                $_erIntl = PhoneNumber::international($updates['phone'], TenantProfile::current(is_array($config ?? null) ? $config : [], $dataDir ?? null));
            } catch (\Throwable $e) { $_erIntl = null; }
            if ($_erIntl !== null) { $updates['phone'] = $_erIntl; $_erCand['phone'] = $_erIntl; }
            else $_erNotes[] = 'This number cannot receive WhatsApp. Write it as +256 7XX XXX XXX.';
        }
        $_erOldId  = (int)($target['ucrm_user_id'] ?? 0);
        $_erOldVer = StaffDirectory::linkedUcrmUser($target);
        $_erPosted = array_key_exists('ucrm_user_id', $_POST) ? trim((string)$_POST['ucrm_user_id']) : 'keep';
        if ($_erPosted === 'keep' || !ctype_digit($_erPosted)) {
            unset($updates['ucrm_user_id']);            // the link as saved, a form without the field, or no number: not touched
            // What the note says is judged on the account as it will be saved: its e-mail may have just changed.
            $_erKept = array_merge($_erCand, ['ucrm_user_id' => $target['ucrm_user_id'] ?? null,
                                              StaffDirectory::LINK_KEY => $target[StaffDirectory::LINK_KEY] ?? null]);
            $_erCounts = $_erOldId > 0 && StaffDirectory::linkedUcrmUser($_erKept) === $_erOldId;
            if ($_erOldId > 0 && !$_erCounts && $_erOldVer === $_erOldId) {
                $_erNotes[] = "The e-mail changed, so the link to uCRM user #{$_erOldId} no longer counts and is not used for jobs: choose the uCRM user again to check it.";
            } elseif ($_erOldId > 0 && !$_erCounts && array_key_exists('ucrm_user_id', $_POST)) {
                $_erNotes[] = "uCRM user #{$_erOldId} was left as it was. It is not verified, so it is not used for jobs: choose the uCRM user in the list to check it, or choose \"not linked\".";
            }
        } else {
            $_erWanted = (int)$_erPosted;              // a choice — the same user as before included — is checked
            $_erMe = $auth->currentRetailer();
            $_erV  = StaffLink::verify($crm, $_erCand, $_erWanted, $store->load('retailers.json') ?? [], (int)(is_array($_erMe) ? ($_erMe['id'] ?? 0) : 0));
            if (!$_erV['ok']) {
                unset($updates['ucrm_user_id']);
                $_erNotes[] = 'The uCRM link was not changed: ' . $_erV['reason'] . '.';
            } elseif ($_erV['action'] === 'link') {
                $updates['ucrm_user_id'] = $_erWanted;
                $updates[StaffDirectory::LINK_KEY] = $_erV['link'];
            } else {
                $updates['ucrm_user_id'] = null;
                $updates[StaffDirectory::LINK_KEY] = null;
            }
        }
    }
    $store->updateOne('retailers.json','id',$rId,$updates);
    flash('Retailer <strong>'.htmlspecialchars($updates['name']).'</strong> updated.'.($_erNotes ? ' '.htmlspecialchars(implode(' ', $_erNotes)) : ''), $_erNotes ? 'warning' : 'success');
    redirect('?page=dashboard&tab=retailers');
}

// ─── RETAILER: Save module permissions ───────────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='save_modules') {
    $auth->requireAdmin();
    $rId = (int)($_POST['retailer_id'] ?? 0);
    if (!$rId) { flash('Invalid retailer.','danger'); redirect('?page=dashboard&tab=retailers'); }
    $target = $store->findOne('retailers.json','id',$rId);
    if (!$target) { flash('Retailer not found.','danger'); redirect('?page=dashboard&tab=retailers'); }
    // Admins always have full access — no point restricting
    if ($target['is_admin'] ?? false) {
        flash('Admins always have full access — module restrictions do not apply.','warning');
        redirect('?page=dashboard&tab=retailers');
    }
    $posted  = $_POST['modules'] ?? [];  // array of module IDs
    $cleaned = array_values(array_filter(array_map('trim', (array)$posted)));
    $store->updateOne('retailers.json','id',$rId,['modules' => $cleaned]);
    $name = htmlspecialchars($target['name']);
    $count = count($cleaned);
    flash("✓ Module access for <strong>{$name}</strong> updated — {$count} module" . ($count!==1?'s':'') . " enabled.", 'success');
    redirect('?page=dashboard&tab=retailers');
}

// ─── RETAILER: Delete ─────────────────────────────────────────────────────────
// ── Reassign all open leads from an on-leave agent to another agent ───────────
if($_SERVER['REQUEST_METHOD']==='POST'&&($_POST['action']??'')==='reassign_agent_leads'){
    $auth->requireAdmin();
    $fromId = (int)($_POST['from_retailer_id'] ?? 0);
    $toId   = (int)($_POST['to_retailer_id']   ?? 0);
    if (!$fromId || !$toId) { flash('Both agents required.','danger'); redirect('?page=dashboard&tab=retailers'); }
    $fromR  = $store->findOne('retailers.json','id',$fromId);
    $toR    = $store->findOne('retailers.json','id',$toId);
    if (!$fromR || !$toR) { flash('Agent not found.','danger'); redirect('?page=dashboard&tab=retailers'); }
    $leads   = $store->load('leads.json') ?? [];
    $changed = 0;
    $openStatuses = ['open','contacted','interested','quoted','qualified'];
    foreach ($leads as &$l) {
        $isOwned    = (int)($l['retailer_id']??0) === $fromId;
        $isAssigned = (int)($l['assigned_to']??0) === $fromId;
        $isOpen     = in_array($l['status']??'', $openStatuses, true);
        if (($isOwned || $isAssigned) && $isOpen) {
            $l['assigned_to']   = $toId;
            $l['assigned_name'] = $toR['name'];
            $l['assigned_at']   = date('Y-m-d H:i:s');
            $l['assigned_by']   = ($retailer['name'] ?? 'Admin') . ' (leave cover)';
            if (!isset($l['history'])) $l['history'] = [];
            $l['history'][] = ['status'=>$l['status']??'open','by'=>$retailer['name']??'Admin','at'=>date('Y-m-d H:i:s'),
                               'note'=>"Reassigned from {$fromR['name']} (on leave) to {$toR['name']}"];
            $changed++;
        }
    }
    unset($l);
    $store->save('leads.json', $leads);
    if ($changed > 0 && !empty($toR['phone'])) {
        $notify->sendRaw($toR['phone'],
            "Hi {$toR['name']}, {$changed} lead(s) transferred from {$fromR['name']} (on leave). Check your leads in Operations Hub.",
            'ops_lead_reassignment');
    }
    flash("✅ {$changed} open lead(s) reassigned from {$fromR['name']} to {$toR['name']}.",'success');
    redirect('?page=dashboard&tab=retailers');
}
if($_SERVER['REQUEST_METHOD']==='POST'&&($_POST['action']??'')==='delete_retailer'){
    $auth->requireAdmin();
    $rId=(int)($_POST['retailer_id']??0);
    if(!$rId){flash('Invalid retailer.','danger');redirect('?page=dashboard&tab=retailers');}
    $target=$store->findOne('retailers.json','id',$rId);
    if(!$target){flash('Retailer not found.','danger');redirect('?page=dashboard&tab=retailers');}
    // Prevent deleting self
    $me=$auth->currentRetailer();
    if($me&&$me['id']===$rId){flash('You cannot delete your own account.','danger');redirect('?page=dashboard&tab=retailers');}
    // Prevent deleting last admin
    if($target['is_admin']??false){
        $admins=array_filter($store->load('retailers.json'),fn($r)=>($r['is_admin']??false));
        if(count($admins)<=1){flash('Cannot delete the last admin account.','danger');redirect('?page=dashboard&tab=retailers');}
    }
    $all=array_values(array_filter($store->load('retailers.json'),fn($r)=>$r['id']!==$rId));
    $store->save('retailers.json',$all);
    flash('Retailer <strong>'.htmlspecialchars($target['name']).'</strong> deleted.','success');
    redirect('?page=dashboard&tab=retailers');
}

// ─── BACKUP: Download all data as ZIP ────────────────────────────────────────
// ─── BACKUP: Download auto-backup zip ────────────────────────────────────────
if (($_GET['action'] ?? '') === 'download_auto_backup') {
    $auth->requireAdmin();
    $reqFile = basename($_GET['file'] ?? '');
    if (!preg_match('/^pre-restore-auto-backup-[\d_-]+\.zip$/', $reqFile)) {
        flash('Invalid backup file requested.', 'danger');
        redirect('?page=dashboard&tab=backup');
    }
    $fullPath = $dataDir . '/' . $reqFile;
    if (!file_exists($fullPath)) {
        flash('Backup file not found.', 'danger');
        redirect('?page=dashboard&tab=backup');
    }
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $reqFile . '"');
    header('Content-Length: ' . filesize($fullPath));
    header('Cache-Control: no-cache');
    readfile($fullPath);
    exit;
}

if (($_GET['action'] ?? '') === 'download_backup') {
    $auth->requireAdmin();

    $timestamp  = date('Y-m-d_H-i-s');
    $pluginRoot = dirname($dataDir);
    // Read version from manifest.json
    $mfJson = @json_decode(@file_get_contents($pluginRoot . '/manifest.json'), true);
    $pluginVer = $mfJson['information']['version'] ?? 'unknown';
    $zipName    = "dishnet-hybrid-v{$pluginVer}-backup-{$timestamp}.zip";
    $tmpZip     = sys_get_temp_dir() . '/' . $zipName;

    $zip = new ZipArchive();
    if ($zip->open($tmpZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        flash('Could not create backup ZIP file.', 'danger');
        redirect('?page=dashboard&tab=backup');
    }

    $included = 0;

    // ── 1. ALL PLUGIN CODE (makes ZIP deployable as UCRM plugin) ─────────
    // Recursively add all files from plugin root, excluding data/ dir
    $skipDirs = ['data', '.git', 'node_modules', '__MACOSX'];
    $codeIter = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($pluginRoot, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($codeIter as $item) {
        $relative = substr((string)$item, strlen($pluginRoot) + 1);
        // Skip data directory and hidden/system dirs
        $topDir = explode('/', str_replace('\\', '/', $relative))[0];
        if (in_array($topDir, $skipDirs, true)) continue;
        if (strpos($relative, '.') === 0) continue; // hidden files
        if ($item->isFile()) {
            $zip->addFile((string)$item, $relative);
            $included++;
        }
    }

    // ── 2. DATA: JSON files ──────────────────────────────────────────────
    foreach (glob($dataDir . '/*.json') as $f) {
        $base = basename($f);
        if (strpos($base, '.') === 0) continue;
        if ($base === 'gdrive_config.json') continue; // OAuth tokens — security
        $zip->addFile($f, 'data/' . $base);
        $included++;
    }

    // ── 3. DATA: SQLite databases ────────────────────────────────────────
    $sqliteDbs = ['plugin.sqlite3', 'dishnet.sqlite'];
    foreach ($sqliteDbs as $dbFile) {
        $dbPath = $dataDir . '/' . $dbFile;
        if (!file_exists($dbPath)) continue;
        try {
            $pdo = new PDO('sqlite:' . $dbPath);
            $pdo->exec('PRAGMA wal_checkpoint(PASSIVE)');
            $pdo = null;
        } catch (Throwable $e) {}
        $tmpCopy = sys_get_temp_dir() . '/backup_' . $dbFile;
        if (@copy($dbPath, $tmpCopy)) {
            $zip->addFile($tmpCopy, 'data/' . $dbFile);
            $included++;
            if (file_exists($dbPath . '-wal') && @copy($dbPath . '-wal', $tmpCopy . '-wal')) {
                $zip->addFile($tmpCopy . '-wal', 'data/' . $dbFile . '-wal');
            }
        }
    }

    // ── 4. DATA: Uploads (expense photos, receipts) ──────────────────────
    $uploadsDir = $dataDir . '/uploads';
    if (is_dir($uploadsDir)) {
        $iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($uploadsDir, FilesystemIterator::SKIP_DOTS));
        foreach ($iter as $file) {
            $relative = 'data/uploads/' . $iter->getSubPathName();
            $zip->addFile((string)$file, $relative);
        }
    }

    // ── 5. CERTIFICATES (Magma mTLS) ─────────────────────────────────────
    $certsDir = $pluginRoot . '/certs';
    if (is_dir($certsDir)) {
        $certIter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($certsDir, FilesystemIterator::SKIP_DOTS));
        foreach ($certIter as $cf) {
            $zip->addFile((string)$cf, 'certs/' . $certIter->getSubPathName());
        }
    }

    // ── 6. BACKUP MANIFEST ───────────────────────────────────────────────
    $manifest = json_encode([
        'plugin'      => basename(dirname(__DIR__, 2)),
        'version'     => $pluginVer,
        'type'        => 'full_deployable',
        'created_at'  => date('Y-m-d H:i:s'),
        'files'       => $included,
        'note'        => 'Full deployable backup. Upload to UCRM Plugins to restore code + data + certs.',
    ], JSON_PRETTY_PRINT);
    $zip->addFromString('BACKUP_MANIFEST.json', $manifest);
    $zip->close();

    // Cleanup temp SQLite copies
    foreach ($sqliteDbs as $dbFile) {
        $tmpCopy = sys_get_temp_dir() . '/backup_' . $dbFile;
        @unlink($tmpCopy);
        @unlink($tmpCopy . '-wal');
    }

    // Stream to browser
    if (file_exists($tmpZip)) {
        logActivity($dataDir,'backup_created','Backup downloaded',$zipName.' ('.round(filesize($tmpZip)/1024).'KB)');
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $zipName . '"');
        header('Content-Length: ' . filesize($tmpZip));
        header('Cache-Control: no-cache');
        readfile($tmpZip);
        @unlink($tmpZip);
        exit;
    }
    flash('Backup generation failed — no ZIP created.', 'danger');
    redirect('?page=dashboard&tab=backup');
}

// ─── BACKUP: Restore from uploaded ZIP ───────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'restore_backup') {
    $admin = $auth->requireAdmin();

    $file = $_FILES['backup_zip'] ?? null;
    if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
        flash('Upload failed — please select a valid .zip backup file.', 'danger');
        redirect('?page=dashboard&tab=backup');
    }

    $mime = mime_content_type($file['tmp_name']);
    if (!in_array($mime, ['application/zip','application/x-zip','application/x-zip-compressed','application/octet-stream'], true)
        && strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)) !== 'zip') {
        flash('Invalid file type — only .zip backups are accepted.', 'danger');
        redirect('?page=dashboard&tab=backup');
    }

    $zip = new ZipArchive();
    if ($zip->open($file['tmp_name']) !== true) {
        flash('Could not open ZIP file — it may be corrupted.', 'danger');
        redirect('?page=dashboard&tab=backup');
    }

    // ── Detect backup type ──────────────────────────────────────────────
    // Type A: Plugin backup (BACKUP_MANIFEST.json, files at root)
    // Type B: Google Drive DATA zip (RESTORE_INSTRUCTIONS.txt, files under data/)
    $isTypeA = $zip->locateName('BACKUP_MANIFEST.json') !== false;
    $isTypeB = $zip->locateName('RESTORE_INSTRUCTIONS.txt') !== false;

    if (!$isTypeA && !$isTypeB) {
        // Type C: pre-restore auto-backup (has JSON + SQLite at root, no manifest)
        // Check if it has at least one known JSON file at root
        $_hasKnownFile = false;
        for ($ci = 0; $ci < $zip->numFiles; $ci++) {
            $_cn = $zip->getNameIndex($ci);
            if (in_array($_cn, ['retailers.json','passbook.json','kyc_applications.json','plugin.sqlite3'], true)) {
                $_hasKnownFile = true;
                break;
            }
        }
        if (!$_hasKnownFile) {
            flash('This does not appear to be a valid DishNet backup. Accepted: plugin backup, Google Drive DATA zip, or pre-restore auto-backup.', 'danger');
            $zip->close();
            redirect('?page=dashboard&tab=backup');
        }
    }

    if ($isTypeA) {
        $manifest = json_decode($zip->getFromIndex($zip->locateName('BACKUP_MANIFEST.json')), true);
        if (!in_array($manifest['plugin'] ?? '', ['kyc-customer-application', 'dishnet-hybrid-telecom', basename(dirname(__DIR__, 2))], true)) {
            flash('Backup is from a different plugin — restore cancelled.', 'danger');
            $zip->close();
            redirect('?page=dashboard&tab=backup');
        }
    }

    // ── Prefix: Google Drive DATA zips store files under data/ ────────
    $prefix = $isTypeB ? 'data/' : '';

    // ── Auto-backup current data before overwriting ─────────────────────
    $preName = "pre-restore-auto-backup-" . date('Y-m-d_H-i-s') . ".zip";
    $prePath  = $dataDir . '/' . $preName;
    $preZip   = new ZipArchive();
    if ($preZip->open($prePath, ZipArchive::CREATE) === true) {
        foreach (['kyc_applications.json','retailers.json','passbook.json','kyc_config.json','kyc_devices.json','kyc_packages.json','wallet_recharge_requests.json','activity_log.json','payment_collections.json','support_tickets.json','leads.json','subscription_plans.json','sim_cards.json','sim_movements.json','sim_fraud_log.json','crm_queue.json','cash_handovers.json','cash_expenses.json','cash_ins.json','cb_categories.json'] as $bf) {
            if (file_exists($dataDir . '/' . $bf)) $preZip->addFile($dataDir . '/' . $bf, $bf);
        }
        foreach (['plugin.sqlite3','dishnet.sqlite'] as $_preDb) {
            $_preDbPath = $dataDir . '/' . $_preDb;
            if (file_exists($_preDbPath)) {
                try { $pdo = new PDO('sqlite:' . $_preDbPath); $pdo->exec('PRAGMA wal_checkpoint(PASSIVE)'); $pdo = null; } catch (Throwable $e) {}
                $preZip->addFile($_preDbPath, $_preDb);
            }
        }
        $preZip->addFromString('BACKUP_MANIFEST.json', json_encode(['plugin'=>basename(dirname(__DIR__, 2)),'version'=>'auto-pre-restore','created_at'=>date('Y-m-d H:i:s'),'note'=>'Auto-backup before restore.'], JSON_PRETTY_PRINT));
        $preZip->close();
    }

    // ── Restore JSON files ──────────────────────────────────────────────
    $allowedFiles = ['kyc_applications.json','retailers.json','passbook.json','kyc_config.json','kyc_devices.json','kyc_packages.json','wallet_recharge_requests.json','activity_log.json','payment_collections.json','support_tickets.json','leads.json','subscription_plans.json','sim_cards.json','sim_movements.json','sim_fraud_log.json','crm_queue.json','cash_handovers.json','cash_expenses.json','cash_ins.json','cb_categories.json'];
    $restored = 0; $skipped = 0; $dbRestored = [];
    $mode = $_POST['restore_mode'] ?? 'merge'; // merge | overwrite

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $zipName = $zip->getNameIndex($i);

        // Strip data/ prefix for Google Drive DATA zips
        $name = $zipName;
        if ($prefix !== '' && strpos($zipName, $prefix) === 0) {
            $name = substr($zipName, strlen($prefix));
        }

        // ── JSON data files ─────────────────────────────────────────────
        if (in_array($name, $allowedFiles, true)) {
            $content = $zip->getFromIndex($i);
            $decoded = json_decode($content, true);
            if (!is_array($decoded)) { $skipped++; continue; }

            $dest = $dataDir . '/' . $name;
            if ($mode === 'merge' && file_exists($dest)) {
                $existing = json_decode(file_get_contents($dest), true) ?: [];
                if (!empty($existing) && isset($existing[0]['id'])) {
                    $existingIds = array_column($existing, 'id');
                    foreach ($decoded as $record) {
                        if (!in_array($record['id'] ?? null, $existingIds)) {
                            $existing[] = $record;
                        }
                    }
                    $decoded = $existing;
                }
                if ($name === 'kyc_config.json') {
                    $decoded = array_merge($decoded, json_decode(file_get_contents($dest), true) ?: []);
                }
            }
            file_put_contents($dest, json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $restored++;

        // ── SQLite databases (overwrite mode only) ──────────────────────
        } elseif (in_array($name, ['plugin.sqlite3', 'dishnet.sqlite'], true) && $mode === 'overwrite') {
            $destDb = $dataDir . '/' . $name;
            // Close any existing connections by checkpointing WAL
            if (file_exists($destDb)) {
                try {
                    $pdo = new PDO('sqlite:' . $destDb);
                    $pdo->exec('PRAGMA wal_checkpoint(TRUNCATE)');
                    $pdo = null;
                } catch (Throwable $e) {}
            }
            // Extract to temp, then move atomically
            $tmpDb = $dataDir . '/restore_tmp_' . $name;
            $content = $zip->getFromIndex($i);
            if ($content !== false && strlen($content) > 100) {
                file_put_contents($tmpDb, $content);
                // Verify the restored DB is valid SQLite
                try {
                    $testPdo = new PDO('sqlite:' . $tmpDb);
                    $testPdo->query('PRAGMA integrity_check')->fetchColumn();
                    $testPdo = null;
                    // Valid — replace the live DB
                    @unlink($destDb . '-wal');
                    @unlink($destDb . '-shm');
                    rename($tmpDb, $destDb);
                    $dbRestored[] = $name;
                    $restored++;
                } catch (Throwable $e) {
                    @unlink($tmpDb);
                    $skipped++;
                }
            }

        // ── SQLite WAL files ────────────────────────────────────────────
        } elseif (in_array($name, ['plugin.sqlite3-wal', 'dishnet.sqlite-wal'], true) && $mode === 'overwrite') {
            // WAL files: only restore if the matching DB was also restored
            $parentDb = str_replace('-wal', '', $name);
            if (in_array($parentDb, $dbRestored, true)) {
                $content = $zip->getFromIndex($i);
                if ($content !== false) {
                    file_put_contents($dataDir . '/' . $name, $content);
                }
            }

        // ── Upload files (receipt photos, KYC docs) ─────────────────────
        } elseif (strpos($name, 'uploads/') === 0 && strlen($name) > 8 && strpos($name, '..') === false) {
            $destFile = $dataDir . '/' . $name;
            $destDir  = dirname($destFile);
            if (!is_dir($destDir)) @mkdir($destDir, 0755, true);
            file_put_contents($destFile, $zip->getFromIndex($i));
            $restored++;

        // ── UCRM export files (clients, invoices, payments JSON) ────────
        } elseif (strpos($name, 'ucrm_export/') === 0 && strlen($name) > 12 && strpos($name, '..') === false) {
            $destFile = $dataDir . '/' . $name;
            $destDir  = dirname($destFile);
            if (!is_dir($destDir)) @mkdir($destDir, 0755, true);
            file_put_contents($destFile, $zip->getFromIndex($i));
            $restored++;
        }
    }
    $zip->close();

    // Build result message
    $dbMsg = '';
    if (!empty($dbRestored)) {
        $dbMsg = ' SQLite databases restored: ' . implode(', ', $dbRestored) . '.';
    } elseif ($mode === 'merge') {
        $dbMsg = ' SQLite databases skipped (merge mode — use Overwrite to restore databases).';
    }

    // Log the restore event
    $logEntry = [
        'event'      => 'data_restored',
        'actor'      => $admin['name'],
        'detail'     => "Restored {$restored} items from backup '{$file['name']}' (mode: {$mode}).{$dbMsg} Auto-backup saved as {$preName}.",
        'ref_id'     => null,
        'created_at' => date('Y-m-d H:i:s'),
    ];
    $store->appendWithId('activity_log.json', $logEntry);

    flash("✅ Restore complete! {$restored} data sets restored.{$dbMsg} Previous data auto-saved as <strong>{$preName}</strong>.", 'success');
    redirect('?page=dashboard&tab=backup');
}

// ─── POST: restore_from_gdrive ─────────────────────────────────────────────
// One-click: download latest DATA zip from Google Drive and restore (overwrite mode).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'restore_from_gdrive') {
    $admin = $auth->requireAdmin();
    @set_time_limit(600);

    require_once __DIR__ . '/../../lib/GoogleDriveBackup.php';
    $gdrive = new GoogleDriveBackup($dataDir);

    if (!$gdrive->isAuthorized()) {
        flash('Google Drive is not connected. Please authorize first.', 'danger');
        redirect('?page=dashboard&tab=backup');
    }

    $gConf = $gdrive->getConfig();
    $dataFileId = $gConf['last_backup']['data_file_id'] ?? '';
    $dataFileName = $gConf['last_backup']['data_file'] ?? 'unknown';

    if (empty($dataFileId)) {
        flash('No DATA backup found on Google Drive. Run a backup first.', 'danger');
        redirect('?page=dashboard&tab=backup');
    }

    // Download the DATA zip from Google Drive
    $token = $gdrive->getAccessToken();
    if (!$token) {
        flash('Could not get Google Drive access token. Try re-authorizing.', 'danger');
        redirect('?page=dashboard&tab=backup');
    }

    $downloadUrl = "https://www.googleapis.com/drive/v3/files/{$dataFileId}?alt=media";
    $tmpFile = $dataDir . '/gdrive_restore_tmp.zip';
    $fp = fopen($tmpFile, 'w');
    $ch = curl_init($downloadUrl);
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token],
        CURLOPT_FILE           => $fp,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 300,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $ok = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    fclose($fp);

    if (!$ok || $httpCode !== 200 || filesize($tmpFile) < 100) {
        @unlink($tmpFile);
        flash("Failed to download DATA backup from Google Drive (HTTP {$httpCode}). Try downloading manually and uploading above.", 'danger');
        redirect('?page=dashboard&tab=backup');
    }

    // Simulate a file upload and let the restore_backup handler process it
    // Instead of duplicating logic, we'll do the restore inline here
    $zip = new ZipArchive();
    if ($zip->open($tmpFile) !== true) {
        @unlink($tmpFile);
        flash('Downloaded file is not a valid ZIP.', 'danger');
        redirect('?page=dashboard&tab=backup');
    }

    // ── Auto-backup current data ────────────────────────────────────────
    $preName = "pre-restore-auto-backup-" . date('Y-m-d_H-i-s') . ".zip";
    $prePath = $dataDir . '/' . $preName;
    $preZip  = new ZipArchive();
    if ($preZip->open($prePath, ZipArchive::CREATE) === true) {
        foreach (['kyc_applications.json','retailers.json','passbook.json','kyc_config.json','kyc_devices.json','kyc_packages.json','wallet_recharge_requests.json','activity_log.json','payment_collections.json','support_tickets.json','leads.json','subscription_plans.json','sim_cards.json','sim_movements.json','sim_fraud_log.json','crm_queue.json','cash_handovers.json','cash_expenses.json','cash_ins.json','cb_categories.json'] as $bf) {
            if (file_exists($dataDir . '/' . $bf)) $preZip->addFile($dataDir . '/' . $bf, $bf);
        }
        foreach (['plugin.sqlite3','dishnet.sqlite'] as $_preDb) {
            $_preDbPath = $dataDir . '/' . $_preDb;
            if (file_exists($_preDbPath)) {
                try { $pdo = new PDO('sqlite:' . $_preDbPath); $pdo->exec('PRAGMA wal_checkpoint(PASSIVE)'); $pdo = null; } catch (Throwable $e) {}
                $preZip->addFile($_preDbPath, $_preDb);
            }
        }
        $preZip->addFromString('BACKUP_MANIFEST.json', json_encode(['plugin'=>basename(dirname(__DIR__, 2)),'version'=>'auto-pre-gdrive-restore','created_at'=>date('Y-m-d H:i:s'),'note'=>'Auto-backup before Google Drive restore.'], JSON_PRETTY_PRINT));
        $preZip->close();
    }

    // ── Restore (overwrite mode, data/ prefix) ──────────────────────────
    $allowedFiles = ['kyc_applications.json','retailers.json','passbook.json','kyc_config.json','kyc_devices.json','kyc_packages.json','wallet_recharge_requests.json','activity_log.json','payment_collections.json','support_tickets.json','leads.json','subscription_plans.json','sim_cards.json','sim_movements.json','sim_fraud_log.json','crm_queue.json','cash_handovers.json','cash_expenses.json','cash_ins.json','cb_categories.json'];
    $restored = 0; $dbRestored = [];
    $prefix = 'data/';

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $zipName = $zip->getNameIndex($i);
        $name = $zipName;
        if (strpos($zipName, $prefix) === 0) {
            $name = substr($zipName, strlen($prefix));
        }

        if (in_array($name, $allowedFiles, true)) {
            $content = $zip->getFromIndex($i);
            $decoded = json_decode($content, true);
            if (!is_array($decoded)) continue;
            file_put_contents($dataDir . '/' . $name, json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $restored++;

        } elseif (in_array($name, ['plugin.sqlite3', 'dishnet.sqlite'], true)) {
            $destDb = $dataDir . '/' . $name;
            if (file_exists($destDb)) {
                try { $pdo = new PDO('sqlite:' . $destDb); $pdo->exec('PRAGMA wal_checkpoint(TRUNCATE)'); $pdo = null; } catch (Throwable $e) {}
            }
            $tmpDb = $dataDir . '/restore_tmp_' . $name;
            $content = $zip->getFromIndex($i);
            if ($content !== false && strlen($content) > 100) {
                file_put_contents($tmpDb, $content);
                try {
                    $testPdo = new PDO('sqlite:' . $tmpDb);
                    $testPdo->query('PRAGMA integrity_check')->fetchColumn();
                    $testPdo = null;
                    @unlink($destDb . '-wal');
                    @unlink($destDb . '-shm');
                    rename($tmpDb, $destDb);
                    $dbRestored[] = $name;
                    $restored++;
                } catch (Throwable $e) {
                    @unlink($tmpDb);
                }
            }

        } elseif (in_array($name, ['plugin.sqlite3-wal', 'dishnet.sqlite-wal'], true)) {
            $parentDb = str_replace('-wal', '', $name);
            if (in_array($parentDb, $dbRestored, true)) {
                $content = $zip->getFromIndex($i);
                if ($content !== false) file_put_contents($dataDir . '/' . $name, $content);
            }

        } elseif (strpos($name, 'uploads/') === 0 && strlen($name) > 8 && strpos($name, '..') === false) {
            $destFile = $dataDir . '/' . $name;
            $destDir  = dirname($destFile);
            if (!is_dir($destDir)) @mkdir($destDir, 0755, true);
            file_put_contents($destFile, $zip->getFromIndex($i));
            $restored++;

        } elseif (strpos($name, 'ucrm_export/') === 0 && strlen($name) > 12 && strpos($name, '..') === false) {
            $destFile = $dataDir . '/' . $name;
            $destDir  = dirname($destFile);
            if (!is_dir($destDir)) @mkdir($destDir, 0755, true);
            file_put_contents($destFile, $zip->getFromIndex($i));
            $restored++;
        }
    }
    $zip->close();
    @unlink($tmpFile);

    $dbMsg = !empty($dbRestored) ? ' SQLite: ' . implode(', ', $dbRestored) . '.' : '';
    $logEntry = [
        'event'      => 'data_restored',
        'actor'      => $admin['name'],
        'detail'     => "Google Drive restore: {$restored} items from '{$dataFileName}' (overwrite).{$dbMsg} Auto-backup: {$preName}.",
        'ref_id'     => null,
        'created_at' => date('Y-m-d H:i:s'),
    ];
    $store->appendWithId('activity_log.json', $logEntry);

    flash("✅ Google Drive restore complete! {$restored} data sets restored from <strong>" . htmlspecialchars($dataFileName) . "</strong>.{$dbMsg} Previous data saved as <strong>{$preName}</strong>.", 'success');
    redirect('?page=dashboard&tab=backup');
}

// ─── POST: export_sqlite_to_json ───────────────────────────────────────────
// Emergency rollback: dumps all SQLite tables back to *.json files in data dir.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'export_sqlite_to_json') {
    $admin = $auth->requireAdmin();
    if ($store instanceof SqliteStore) {
        $exportDir = $dataDir . '/_json_export_' . date('Ymd_His');
        $exported  = $store->exportAllToJson($exportDir);
        $count     = count($exported);
        $total     = array_sum($exported);
        flash("✅ SQLite exported to JSON: {$count} tables, {$total} total records → <code>{$exportDir}</code>", 'success');
    } else {
        flash('System is already running on JSON — no export needed.', 'info');
    }
    redirect('?page=dashboard&tab=maintenance');
}
