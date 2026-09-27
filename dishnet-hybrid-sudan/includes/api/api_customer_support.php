<?php
// ═══════════════════════════════════════════════════════════════
// CUSTOMER LOGIN SUPPORT (staff, admin only) — 5.18.37
// ═══════════════════════════════════════════════════════════════
// Until 5.18.37 the customer-app file answered four app_debug_* actions to
// anyone, before any login: whether a phone belonged to a customer (with
// another customer's index row as a "sample"), every message ever sent to a
// number with the first 70 characters of its text — the login code among
// them — the tables and sample rows of every plugin on the host, and a
// WhatsApp send to any registered phone. Those actions are gone.
//
// This file is included AFTER the staff guard in api_handlers.php, so $me2 and
// $isAdmin exist. It keeps what an administrator legitimately needs when a
// customer says "the code never arrived": did the number match, is a code
// pending, did the send succeed — and never the code, never another
// customer, never a message body.
//
// The helpers (ca_init_tables, ca_phone_normalize, ca_phone_intl,
// ca_find_clients_by_phone, ca_find_clients_by_email) are defined by
// api_customer_app.php, which api_public.php includes on every API request.

    $_csAdminOnly = ['staff_login_lookup', 'staff_otp_log', 'app_debug_list', 'staff_revoke_customer_sessions'];
    if (in_array($act, $_csAdminOnly, true) && empty($isAdmin)) $er2('Admin only.', 403);

    // How the customer typed it → how the login path keys it.
    $_csIdentifier = static function (array $q) use ($config): array {   // Phase 2: the profile needs the configuration in scope
        $rawPhone = trim((string)($q['phone'] ?? ''));
        $rawEmail = trim((string)($q['email'] ?? ''));
        if ($rawEmail !== '') return ['email', strtolower($rawEmail), $rawEmail];
        if ($rawPhone !== '') return ['phone', ca_phone_intl($rawPhone, is_array($config ?? null) ? $config : []), $rawPhone];   // Phase 2: the tenant's rule
        return ['', '', ''];
    };

    // ─── What the login path would do with this identifier ───────────────────
    // GET ?page=api&action=staff_login_lookup&phone=…   or   &email=…
    // Answers the support question without disclosing anything the customer
    // themselves could not learn by trying to log in — plus the send result,
    // which the login path deliberately hides from the caller (P-10).
    if ($act === 'staff_login_lookup' && $met === 'GET') {
        ca_init_tables($store->getPdo());
        $pdo = $store->getPdo();
        [$mode, $identifier, $raw] = $_csIdentifier($_GET);
        if ($mode === '') $er2('phone or email required', 400);

        $matches = $mode === 'email' ? ca_find_clients_by_email($store, $raw) : ca_find_clients_by_phone($store, $raw);
        $accounts = [];
        foreach ($matches as $m) {
            // Phase 2: whether the gates would let this account sign in, and why not.
            $why = ca_login_eligibility($m, $config);
            $liveQ = $pdo->prepare("SELECT COUNT(*) FROM customer_sessions WHERE client_id = ? AND revoked_at IS NULL AND expires_at > ?");
            $liveQ->execute([(int)($m['id'] ?? 0), time()]);
            $accounts[] = ['id' => (int)($m['id'] ?? 0), 'name' => (string)($m['name'] ?? ''),
                'eligible' => $why === null, 'refused_because' => $why,
                'flags' => ['is_lead' => $m['is_lead'] ?? null, 'is_archived' => $m['is_archived'] ?? null, 'has_service' => $m['has_service'] ?? null],
                'live_sessions' => (int)$liveQ->fetchColumn()];
        }
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM app_otp_rate WHERE phone = ? AND sent_at > ?");
        $stmt->execute([$identifier, time() - 3600]);
        $sentLastHour = (int)$stmt->fetchColumn();
        $limit = (int)($config['app_otp_limit_per_hour'] ?? 10);

        $stmt = $pdo->prepare("SELECT expires_at, created_at, attempts FROM app_otp_pending WHERE phone = ?");
        $stmt->execute([$identifier]);
        $pending = $stmt->fetch(\PDO::FETCH_ASSOC);

        $senderEnabled = !empty($config['wa_plugin_url']) && !empty($config['wa_app_key']) && !empty($config['wa_auth_key']);
        $evoEnabled    = !empty($config['evo_api_url']) && !empty($config['evo_api_key']);
        // Phase 2: what a send would actually use — the same choice sendVia() makes.
        $_csNotify = (isset($notify) && $notify instanceof NotificationService) ? $notify : (function_exists('svc') ? svc('notify') : null);
        $transportInUse = $_csNotify instanceof NotificationService ? $_csNotify->phoneTransport(NotificationService::SUPPORT) : '';

        $ok2([
            'mode'       => $mode,
            'identifier' => $identifier,
            'accounts'   => $accounts,          // every match; none of anybody else's
            'rate_limit' => [
                'sent_last_hour' => $sentLastHour,
                'max_per_hour'   => $limit,
                'allowed'        => $sentLastHour < $limit,
            ],
            'pending_otp' => $pending ? [
                'exists'             => true,
                'expires_in_seconds' => max(0, (int)$pending['expires_at'] - time()),
                'attempts'           => (int)$pending['attempts'],
            ] : ['exists' => false],
            'transport' => [
                'wasender_configured'  => $senderEnabled,
                'evolution_configured' => $evoEnabled,
                'in_use'               => $transportInUse !== '' ? $transportInUse : 'none',
                'dry_run_mode'         => (bool)($config['dry_run_mode'] ?? false),
            ],
            'eligibility_defaults' => [
                'allow_leads'     => ca_cfg_bool($config['portal_login_allow_leads'] ?? null, false),
                'require_service' => ca_cfg_bool($config['portal_login_require_service'] ?? null, false),
            ],
        ], 'Login lookup complete.');
    }

    // ─── Sign one customer out everywhere (Phase 2, plan §E.5) ────────────────
    // POST ?page=api&action=staff_revoke_customer_sessions   {"client_id": N}
    if ($act === 'staff_revoke_customer_sessions' && $met === 'POST') {
        ca_init_tables($store->getPdo());
        $pdo = $store->getPdo();
        $cid = (int)($body['client_id'] ?? 0);
        if ($cid <= 0) $er2('client_id required', 400);
        $by = 'staff:' . (string)($me2['email'] ?? $me2['name'] ?? 'admin');
        $n  = CustomerSession::revokeAll($pdo, $cid, $by);
        ca_audit($pdo, $cid, 'sessions_revoked_by_staff', null, ['count' => $n, 'by' => $by]);
        $ok2(['client_id' => $cid, 'revoked' => $n], $n === 1 ? '1 session signed out.' : "{$n} sessions signed out.");
    }

    // ─── Delivery log for one identifier, without message bodies ─────────────
    // GET ?page=api&action=staff_otp_log&phone=…&limit=10
    if ($act === 'staff_otp_log' && $met === 'GET') {
        ca_init_tables($store->getPdo());
        $pdo = $store->getPdo();
        [$mode, $identifier, $raw] = $_csIdentifier($_GET);
        if ($mode === '') $er2('phone or email required', 400);
        $limit  = min(50, max(1, (int)($_GET['limit'] ?? 10)));
        $digits = preg_replace('/[^0-9]/', '', $identifier);
        $last9  = strlen($digits) >= 9 ? substr($digits, -9) : $digits;

        $out = ['identifier' => $identifier, 'sends' => [], 'failed_queue' => [], 'login_events' => []];
        try {
            if ($mode === 'phone' && $last9 !== '') {
                // No `preview`: the login message carries the code in its first line.
                $stmt = $pdo->prepare("SELECT sender, event, phone, success, http_code, error, sent_at
                                       FROM notification_audit_log
                                       WHERE phone = ? OR phone LIKE ?
                                       ORDER BY sent_at DESC LIMIT ?");
                $stmt->execute([$digits, '%' . $last9, $limit]);
                $out['sends'] = $stmt->fetchAll(\PDO::FETCH_ASSOC);
                $stmt = $pdo->prepare("SELECT id, sender, phone, event, status, http_code, error, attempts, last_attempt_at
                                       FROM notification_queue
                                       WHERE phone LIKE ? OR phone = ?
                                       ORDER BY id DESC LIMIT 10");
                $stmt->execute(['%' . $last9, $digits]);
                $out['failed_queue'] = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            }
        } catch (\Throwable $e) {
            $out['sends_error'] = 'notification log unavailable';
        }
        try {
            $stmt = $pdo->prepare("SELECT action, at FROM app_audit_log WHERE phone = ? ORDER BY at DESC LIMIT ?");
            $stmt->execute([$identifier, $limit]);
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            foreach ($rows as &$r) $r['at_iso'] = gmdate('c', (int)$r['at']);
            $out['login_events'] = $rows;
        } catch (\Throwable $e) {
            $out['login_events_error'] = 'audit log unavailable';
        }
        $ok2($out, 'Delivery log retrieved.');
    }

    // ─── Debug reports the app/PWA uploaded (moved from the customer file) ────
    // GET ?page=api&action=app_debug_list
    // Was reachable with ANY customer's token and listed every customer's
    // reports. Staff only now.
    if ($act === 'app_debug_list' && $met === 'GET') {
        ca_init_tables($store->getPdo());
        $pdo = $store->getPdo();
        $pdo->exec("CREATE TABLE IF NOT EXISTS app_debug_reports (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            crm_client_id INTEGER NOT NULL,
            phone TEXT,
            report TEXT NOT NULL,
            created_at INTEGER NOT NULL
        )");
        $stmt = $pdo->query("SELECT * FROM app_debug_reports ORDER BY id DESC LIMIT 50");
        $reports = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $ok2(['reports' => $reports], count($reports) . ' reports found.');
    }
