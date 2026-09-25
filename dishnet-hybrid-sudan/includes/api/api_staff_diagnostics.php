<?php
// ═══════════════════════════════════════════════════════════════
// STAFF DIAGNOSTICS (admin only) — moved from api_public.php in 5.18.37
// ═══════════════════════════════════════════════════════════════
// These seven actions ran before the staff guard: the data directory
// layout, the CRM retry queue, a handover audit that can PATCH a uCRM
// payment note, the payment key/push/catch-up logs and a retailer's
// uCRM key check. None is something the public may call. This file is
// included AFTER the guard in api_handlers.php, so $me2 and $isAdmin exist.

    $_sdAdminOnly = ['data_dir_info', 'crm_debug', 'handover_audit', 'payment_key_log',
                     'check_retailer_key', 'payment_push_log', 'payment_catchup_log'];
    if (in_array($act, $_sdAdminOnly, true) && empty($isAdmin)) $er2('Admin only.', 403);

    // ─── DIAGNOSTIC: Data directory configuration ────────────────────────────
    // GET ?page=api&action=data_dir_info — verify persistent storage setup
    if ($act === 'data_dir_info' && $met === 'GET') {
        $pluginRoot = dirname(__DIR__);
        $ucrmJson = [];
        foreach ([$pluginRoot . '/ucrm.json', $pluginRoot . '/data/ucrm.json'] as $path) {
            if (file_exists($path)) {
                $ucrmJson = @json_decode(file_get_contents($path), true) ?: [];
                break;
            }
        }
        
        $pluginDataDir = $ucrmJson['pluginDataDir'] ?? null;
        $isUcrm = !empty($pluginDataDir);
        $actualDataDir = $dataDir; // Inherited from public.php
        
        // Check critical files exist in data directory
        $criticalFiles = ['plugin.sqlite3', 'retailers.json', 'kyc_config.json', 'kyc_applications.json'];
        $filesStatus = [];
        foreach ($criticalFiles as $f) {
            $path = $actualDataDir . '/' . $f;
            $filesStatus[$f] = file_exists($path) ? filesize($path) . ' bytes' : 'MISSING';
        }
        
        // Check if old plugin/data folder exists (would indicate migration needed)
        $oldDataDir = $pluginRoot . '/data';
        $oldDataExists = is_dir($oldDataDir) && $oldDataDir !== $actualDataDir;
        $oldDataFiles = $oldDataExists ? count(glob($oldDataDir . '/*')) : 0;
        
        $ok2([
            'environment'       => $isUcrm ? 'UCRM (persistent)' : 'Development (local)',
            'plugin_data_dir'   => $pluginDataDir ?: 'N/A (not in UCRM)',
            'actual_data_dir'   => $actualDataDir,
            'data_dir_exists'   => is_dir($actualDataDir),
            'data_dir_writable' => is_writable($actualDataDir),
            'critical_files'    => $filesStatus,
            'old_data_warning'  => $oldDataExists ? "OLD DATA FOUND: {$oldDataDir} has {$oldDataFiles} files - may need migration!" : null,
            'ucrm_version'      => $ucrmJson['ucrmVersion'] ?? 'N/A',
            'plugin_version'    => $ucrmJson['pluginVersion'] ?? 'N/A',
        ]);
    }
    
    // GET ?page=api&action=crm_debug — BEFORE auth guard, no credentials needed
    if ($act === 'crm_debug' && $met === 'GET') {
        $retryQueue = $store->load('crm_payment_retry.json') ?? [];
        $pending = array_filter($retryQueue, fn($r) => ($r['status']??'') === 'pending');
        $failed  = array_filter($retryQueue, fn($r) => ($r['status']??'') === 'failed');
        $connTest = $crm->testConnection(__DIR__ . '/..', $config);
        $ok2([
            'crm_configured'  => $crm->isConfigured(),
            'crm_base_url'    => $crm->getBaseUrl(),
            'connection_test' => $connTest,
            'retry_pending'   => count($pending),
            'retry_failed'    => count($failed),
            'recent_errors'   => array_values(array_map(fn($r) => [
                'id'            => $r['id'] ?? '?',
                'customer'      => $r['customer_name'] ?? '',
                'amount'        => $r['payload']['amount'] ?? 0,
                'attempts'      => $r['attempts'] ?? 0,
                'status'        => $r['status'] ?? '',
                'error'         => $r['last_error'] ?? $r['error'] ?? '',
                'next_retry_at' => $r['next_retry_at'] ?? '',
                'created_at'    => $r['created_at'] ?? '',
            ], array_slice(array_reverse(array_values($retryQueue)), 0, 10))),
        ]);
    }

    // ─── DIAGNOSTIC: Handover linkage audit ──────────────────────────────────
    // GET ?page=api&action=handover_audit
    // GET ?page=api&action=handover_audit&retailer_id=5
    // GET ?page=api&action=handover_audit&days=7
    if ($act === 'handover_audit' && $met === 'GET') {
        $allCols = $store->load('payment_collections.json') ?? [];
        $allHov  = $store->load('cash_handovers.json') ?? [];
        $allRetailers = $store->load('retailers.json') ?? [];
        
        $filterRetailer = (int)($_GET['retailer_id'] ?? 0);
        $filterDays = (int)($_GET['days'] ?? 30);
        $cutoff = date('Y-m-d', strtotime("-{$filterDays} days"));
        
        // Build retailer lookup
        $retailerNames = [];
        foreach ($allRetailers as $r) {
            $retailerNames[(int)$r['id']] = $r['name'] ?? 'Unknown';
        }
        
        // Filter collections
        $cols = array_filter($allCols, function($c) use ($filterRetailer, $cutoff) {
            if ($filterRetailer && (int)($c['retailer_id'] ?? 0) !== $filterRetailer) return false;
            $dt = substr($c['created_at'] ?? '', 0, 10);
            return $dt >= $cutoff;
        });
        
        // Categorize collections
        $linked   = []; // Has handover_id
        $unlinked = []; // No handover_id, has crm_payment_id
        $noCrm    = []; // No crm_payment_id at all
        $voided   = []; // Voided collections
        
        foreach ($cols as $c) {
            $entry = [
                'id'             => $c['id'] ?? 0,
                'date'           => substr($c['created_at'] ?? '', 0, 16),
                'retailer'       => $c['retailer_name'] ?? $retailerNames[(int)($c['retailer_id'] ?? 0)] ?? 'Unknown',
                'retailer_id'    => $c['retailer_id'] ?? 0,
                'customer'       => $c['customer_name'] ?? '—',
                'amount'         => (float)($c['amount'] ?? 0),
                'crm_payment_id' => $c['crm_payment_id'] ?? null,
                'crm_synced'     => $c['crm_synced'] ?? false,
                'handover_id'    => $c['handover_id'] ?? null,
                'handover_receipt' => $c['handover_receipt'] ?? null,
                'handover_by'    => $c['handover_by'] ?? null,
                'handover_at'    => $c['handover_at'] ?? null,
                'status'         => $c['status'] ?? 'active',
            ];
            
            if (($c['status'] ?? '') === 'voided') {
                $voided[] = $entry;
            } elseif (!empty($c['handover_id'])) {
                $linked[] = $entry;
            } elseif (!empty($c['crm_payment_id'])) {
                $unlinked[] = $entry;
            } else {
                $noCrm[] = $entry;
            }
        }
        
        // Test UCRM PATCH capability
        $patchTest = null;
        $testPayId = (int)($_GET['test_payment_id'] ?? 0);
        if ($testPayId && $crm->isConfigured()) {
            // Try to read the payment first
            $payment = $crm->get("payments/{$testPayId}");
            if ($payment && isset($payment['id'])) {
                $origNote = $payment['note'] ?? '';
                $testNote = $origNote . "\n[PATCH TEST " . date('Y-m-d H:i:s') . " - DELETE THIS LINE]";
                $patchResult = $crm->patch("payments/{$testPayId}", ['note' => $testNote]);
                
                // Restore original note
                if ($patchResult !== null) {
                    $crm->patch("payments/{$testPayId}", ['note' => $origNote]);
                }
                
                $patchTest = [
                    'payment_id'   => $testPayId,
                    'original_note' => substr($origNote, 0, 100) . (strlen($origNote) > 100 ? '...' : ''),
                    'patch_success' => $patchResult !== null,
                    'patch_result'  => $patchResult !== null ? 'OK - PATCH works!' : 'FAILED - PATCH not supported',
                ];
            } else {
                $patchTest = [
                    'payment_id'   => $testPayId,
                    'error'        => 'Payment not found in UCRM',
                ];
            }
        }
        
        // Summary by retailer
        $byRetailer = [];
        foreach ($unlinked as $u) {
            $rn = $u['retailer'];
            if (!isset($byRetailer[$rn])) {
                $byRetailer[$rn] = ['count' => 0, 'amount' => 0];
            }
            $byRetailer[$rn]['count']++;
            $byRetailer[$rn]['amount'] += $u['amount'];
        }
        
        $ok2([
            'period'           => "Last {$filterDays} days (since {$cutoff})",
            'filter_retailer'  => $filterRetailer ?: 'All',
            'summary' => [
                'linked_to_handover'   => count($linked),
                'unlinked_with_crm'    => count($unlinked),
                'no_crm_payment'       => count($noCrm),
                'voided'               => count($voided),
                'total'                => count($cols),
            ],
            'unlinked_total_amount' => array_sum(array_column($unlinked, 'amount')),
            'unlinked_by_retailer'  => $byRetailer,
            'linked_collections'    => array_slice($linked, 0, 20),
            'unlinked_collections'  => array_slice($unlinked, 0, 50),
            'no_crm_collections'    => array_slice($noCrm, 0, 20),
            'patch_test'            => $patchTest,
            'help' => [
                'test_patch'    => 'Add &test_payment_id=123 to test UCRM PATCH on a specific payment',
                'filter_agent'  => 'Add &retailer_id=5 to filter by agent',
                'filter_days'   => 'Add &days=7 to change time window (default 30)',
            ],
        ]);
    }

    // ─── DIAGNOSTIC: View payment key usage log ──────────────────────────────
    // GET ?page=api&action=payment_key_log
    if ($act === 'payment_key_log' && $met === 'GET') {
        // $dataDir inherited from public.php (UCRM persistent)
        $diagFile = $dataDir . '/payment_key_log.json';
        $diagLogs = file_exists($diagFile) ? json_decode(file_get_contents($diagFile), true) : [];
        if (!is_array($diagLogs)) $diagLogs = [];
        $ok2([
            'description' => 'Shows which API key was used for each payment (personal vs plugin)',
            'count' => count($diagLogs),
            'logs'  => $diagLogs,
        ]);
    }

    // ─── DIAGNOSTIC: Check retailer's UCRM key config ────────────────────────
    // GET ?page=api&action=check_retailer_key&retailer_id=XX
    if ($act === 'check_retailer_key' && $met === 'GET') {
        $rId = (int)($_GET['retailer_id'] ?? 0);
        if (!$rId) $er2('retailer_id required', 400);
        
        require_once dirname(__DIR__, 2) . '/lib/StoreInterface.php';
        require_once dirname(__DIR__, 2) . '/lib/JsonStore.php';
        require_once dirname(__DIR__, 2) . '/lib/SqliteStore.php';
        // $dataDir inherited from public.php (UCRM persistent)
        $store = SqliteStore::create($dataDir);
        
        $retailer = $store->findOne('retailers.json', 'id', $rId);
        if (!$retailer) $er2('Retailer not found', 404);
        
        $hasKey = !empty($retailer['ucrm_app_key']);
        $keyPreview = $hasKey ? '[set]' : null;   // 5.18.37: never echo any part of a credential
        
        $ok2([
            'retailer_id'      => $rId,
            'retailer_name'    => $retailer['name'] ?? '',
            'has_ucrm_app_key' => $hasKey,
            'key_preview'      => $keyPreview,
            'key_length'       => $hasKey ? strlen($retailer['ucrm_app_key']) : 0,
            'ucrm_user_id'     => $retailer['ucrm_user_id'] ?? null,
            'note'             => $hasKey 
                ? 'Key is set. Payments should show this user as creator in UCRM.' 
                : 'No personal key set. Payments will use plugin key (generic creator).',
        ]);
    }

    // ─── DIAGNOSTIC: View payment push log ─────────────────────────────────
    // GET ?page=api&action=payment_push_log
    if ($act === 'payment_push_log' && $met === 'GET') {
        // $dataDir inherited from public.php (UCRM persistent)
        $diagFile = $dataDir . '/payment_push_log.json';
        $diagLogs = file_exists($diagFile) ? json_decode(file_get_contents($diagFile), true) : [];
        if (!is_array($diagLogs)) $diagLogs = [];
        $ok2([
            'count' => count($diagLogs),
            'logs'  => $diagLogs,
        ]);
    }

    // ─── DIAGNOSTIC: View catchup sync log ───────────────────────────────────
    // GET ?page=api&action=payment_catchup_log
    if ($act === 'payment_catchup_log' && $met === 'GET') {
        // $dataDir inherited from public.php (UCRM persistent)
        $diagFile = $dataDir . '/payment_catchup_log.json';
        $diagLogs = file_exists($diagFile) ? json_decode(file_get_contents($diagFile), true) : [];
        if (!is_array($diagLogs)) $diagLogs = [];
        $ok2([
            'count' => count($diagLogs),
            'logs'  => $diagLogs,
        ]);
    }
