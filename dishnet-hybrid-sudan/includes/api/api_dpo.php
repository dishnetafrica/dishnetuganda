<?php
// ═══════════════════════════════════════════════════════════════
// DPO PAY — customer-facing actions (Bearer JWT, same as app_*)
// ═══════════════════════════════════════════════════════════════
//
// Two actions, and neither of them can be told a result.
//
//   dpo_initiate  takes ONLY an invoice id. The amount is read live from
//                 uCRM; a body that carried one would be ignored, and the
//                 client id comes from the token, never from the request.
//   dpo_status    reports the STORED status of the caller's own attempt,
//                 for the pending screen to poll. It never triggers a charge
//                 and never verifies — polling must not be a way to drive
//                 settlement from a browser.

    if ($act === 'dpo_initiate' && $met === 'POST') {
        require_once dirname(__DIR__, 2) . '/lib/DpoBootstrap.php';
        ca_init_tables($store->getPdo());
        $pdo      = $store->getPdo();
        $claims   = ca_require_auth($config, $pdo, $er2);
        $clientId = ca_resolve_active_client_id($claims, $er2);

        $invoiceId = (int)($body['invoice_id'] ?? 0);
        if ($invoiceId <= 0) $er2('invoice_id required', 400);
        if (!$crm) $er2('CRM service unavailable.', 500);

        // Customer details are a convenience for DPO's own receipt, never an
        // input to the money. Anything the caller sends here is decoration.
        $me   = $crm->get('clients/' . $clientId) ?? [];
        $dpoS = DpoBootstrap::service($store, $config, $crm,
            function (string $e, string $d) use ($dataDir) { logActivity($dataDir, $e, 'DPO Pay', $d); });

        $r = $dpoS->initiate($clientId, $invoiceId, [
            'first'     => (string)($me['firstName'] ?? ''),
            'last'      => (string)($me['lastName'] ?? ($me['companyName'] ?? '')),
            'email'     => (string)($me['contacts'][0]['email'] ?? ''),
            'phone'     => (string)($me['contacts'][0]['phone'] ?? ''),
            'country'   => (string)($config['dpo_customer_country'] ?? ''),
            'dial_code' => (string)($config['dpo_customer_dial_code'] ?? ''),
        ]);

        ca_audit($pdo, $clientId, 'dpo_initiate', $claims['phone'] ?? null,
                 ['invoice' => $invoiceId, 'ok' => $r['ok'], 'code' => $r['code'],
                  'reference' => $r['reference']]);

        if (!$r['ok']) $er2($r['error'], $r['code'] === 'FORBIDDEN' ? 403 : 400);

        // The browser gets the checkout URL and nothing else. No token, no
        // credential, no internal state.
        $ok2(['checkout_url' => $r['checkout_url'], 'reference' => $r['reference'],
              'amount' => $r['amount'], 'currency' => $r['currency']], 'Redirecting to DPO Pay.');
    }

    if ($act === 'dpo_status' && $met === 'GET') {
        require_once dirname(__DIR__, 2) . '/lib/DpoPaymentStore.php';
        ca_init_tables($store->getPdo());
        $pdo      = $store->getPdo();
        $claims   = ca_require_auth($config, $pdo, $er2);
        $clientId = ca_resolve_active_client_id($claims, $er2);

        $ref = trim((string)($_GET['reference'] ?? ''));
        if ($ref === '') $er2('reference required', 400);

        $row = (new DpoPaymentStore($pdo))->byReference($ref);
        // Another customer's reference must look exactly like one that does
        // not exist, or this endpoint becomes a way to enumerate payments.
        if ($row === null || (int)$row['crm_client_id'] !== $clientId) $er2('Not found', 404);

        $ok2(['reference' => $ref, 'status' => (string)$row['status'],
              'amount'    => (float)$row['amount'], 'currency' => (string)$row['currency'],
              'invoice'   => (string)$row['invoice_number'],
              'settled'   => (string)$row['status'] === 'SUCCESS',
              // A customer never sees a DPO result code or our failure text —
              // those are for the admin screen and the event trail.
              'message'   => dpo_customer_message((string)$row['status'])]);
    }

if (!function_exists('dpo_customer_message')) {
    /** Plain words for a customer. One line, no result codes, no blame. */
    function dpo_customer_message(string $status): string
    {
        switch ($status) {
            case 'SUCCESS':     return 'Payment received. Thank you.';
            case 'CREATED':
            case 'PENDING':
            case 'REDIRECTED':  return 'Payment is being confirmed.';
            case 'CANCELLED':   return 'Payment was cancelled.';
            case 'EXPIRED':     return 'This payment attempt has expired. You can try again.';
            case 'QUARANTINED': return 'Payment is being checked by our team.';
            case 'REFUNDED':    return 'This payment was refunded.';
            default:            return 'Payment was not completed.';
        }
    }
}
