<?php
// ═══════════════════════════════════════════════════════════════
// PUBLIC / DIAGNOSTICS (pre-auth)
// ═══════════════════════════════════════════════════════════════

    if ($act === 'login') {
        if ($met !== 'POST') $er2('POST required.', 405);
        $em = trim($body['email'] ?? ''); $pw = $body['password'] ?? '';
        if (!$em || !$pw) $er2('Email and password required.');
        $found = null;
        foreach ($store->load('retailers.json') as $r) {
            if (strtolower($r['email']) === strtolower($em) && !empty($r['is_active'])) {
                if (password_verify($pw, $r['password'])) { $found = $r; break; }
            }
        }
        if (!$found) $er2('Invalid email or password.', 401);
        unset($found['password']);
        $ok2(['retailer'=>$found,'token'=>$found['api_token'],'wallet'=>$wallet->getSummary($found['id'])], 'Login successful.');
    }
    
    // 5.18.37: the seven staff diagnostics that lived here (data_dir_info,
    // crm_debug, handover_audit, payment_key_log, check_retailer_key,
    // payment_push_log, payment_catchup_log) answered without any login.
    // They now live in api_staff_diagnostics.php, behind the staff guard,
    // admin only.

    // ─── Customer Context API (read-only; n8n/Evolution sales bot) ───────────
    // GET ?page=api&action=customer_context&phone=<digits>&key=<webhook_secret>
    // Re-applied to the v4.21.114 lineage (originally shipped as v4.21.115).
    // Auth reuses $config['webhook_secret'] via hash_equals. Fully READ-ONLY:
    // identity from client_search_index (SQLite O(1) fast path, JSON fallback);
    // plan/status/balance ride along on the index row; open-ticket count is
    // best-effort enrichment. Every step is try/caught — the endpoint always
    // answers and never 500s. Creates NO tables, touches NO other handler.
    if ($act === 'customer_context' && $met === 'GET') {

        // ── auth (webhook_secret from DishNet Settings) ──────────────────
        $ccProvided = (string)($_GET['key'] ?? ($_SERVER['HTTP_X_DISHNET_KEY'] ?? ''));
        $ccExpected = (string)($config['webhook_secret'] ?? '');
        if ($ccExpected === '' || $ccProvided === '' || !hash_equals($ccExpected, $ccProvided)) {
            $er2('Unauthorized.', 401);
        }

        $ccDigits = preg_replace('/[^0-9]/', '', (string)($_GET['phone'] ?? ''));
        if (strlen($ccDigits) < 7) $er2('Valid phone required.', 400);
        $ccKey9 = substr($ccDigits, -9);

        $ccOut = [
            'found'        => false,
            'phone'        => $ccDigits,
            'customer'     => null,   // {crm_id, name}
            'service'      => null,   // plan(s) string when known
            'status'       => null,   // CRM status string when known
            'balance'      => null,
            'open_tickets' => 0,
            'sources'      => [],
        ];

        // ── 1. identity: SQLite indexed fast path ────────────────────────
        $ccHit = null;
        try {
            $ccStmt = $store->getPdo()->prepare(
                "SELECT id, name, phone FROM client_search_index WHERE phone_norm = ? LIMIT 1"
            );
            $ccStmt->execute([$ccKey9]);
            $ccRow = $ccStmt->fetch(\PDO::FETCH_ASSOC);
            if ($ccRow) { $ccHit = $ccRow; $ccOut['sources'][] = 'index-sqlite'; }
        } catch (\Throwable $e) { /* table not ready — fall through */ }

        // ── 1b. identity: JSON index scan fallback (carries plans/status/bal) ─
        try {
            $ccIndex = $store->load('client_search_index.json') ?? [];
            foreach ($ccIndex as $ccC) {
                $ccP  = preg_replace('/[^0-9]/', '', (string)($ccC['phone'] ?? ''));
                $ccL9 = strlen($ccP) >= 9 ? substr($ccP, -9) : $ccP;
                if ($ccL9 === '' || $ccL9 !== $ccKey9) continue;
                // JSON row is richer than the SQLite row — prefer it either way
                $ccHit = $ccC;
                if (!in_array('index-json', $ccOut['sources'], true)) $ccOut['sources'][] = 'index-json';
                break;
            }
        } catch (\Throwable $e) {}

        if ($ccHit) {
            $ccOut['found']    = true;
            $ccOut['customer'] = [
                'crm_id' => (int)($ccHit['id'] ?? 0),
                'name'   => (string)($ccHit['name'] ?? 'Customer'),
            ];
            if (isset($ccHit['plans'])) {
                $ccOut['service'] = is_array($ccHit['plans'])
                    ? implode(', ', array_filter(array_map('strval', $ccHit['plans'])))
                    : (string)$ccHit['plans'];
            }
            if (isset($ccHit['status'])) $ccOut['status']  = (string)$ccHit['status'];
            if (isset($ccHit['bal']))    $ccOut['balance'] = $ccHit['bal'];
        }

        // ── 2. open-ticket count (best-effort, never blocks the reply) ───
        try {
            $ccOpenStates = ['open', 'new', 'pending', 'in progress', 'work in progress', 'waiting on agent'];
            $ccN = 0;
            foreach (['support_tickets.json', 'wa_tickets.json', 'splynx_tickets.json'] as $ccTf) {
                $ccTs = $store->load($ccTf) ?? [];
                if (!is_array($ccTs)) continue;
                foreach ($ccTs as $ccTk) {
                    if (!is_array($ccTk)) continue;
                    $ccTp  = preg_replace('/[^0-9]/', '', (string)($ccTk['phone'] ?? ($ccTk['customer_phone'] ?? '')));
                    $ccTl9 = strlen($ccTp) >= 9 ? substr($ccTp, -9) : $ccTp;
                    if ($ccTl9 === '' || $ccTl9 !== $ccKey9) continue;
                    $ccSt = strtolower((string)($ccTk['status'] ?? ''));
                    if ($ccSt === '' || in_array($ccSt, $ccOpenStates, true)) $ccN++;
                }
            }
            if ($ccN > 0) { $ccOut['open_tickets'] = $ccN; $ccOut['sources'][] = 'tickets'; }
        } catch (\Throwable $e) {}

        $ok2($ccOut);
    }

    // ─── Customer App API (mobile app endpoints) ─────────────────────────────
    // All actions prefixed app_*, see api_customer_app.php
    require __DIR__ . '/api_customer_app.php';
    // dpo_* — paying an invoice from the portal. Loaded here because they
    // authenticate with the customer's Bearer JWT, like the app_* actions,
    // not with a staff session.
    require __DIR__ . '/api_dpo.php';
