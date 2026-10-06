<?php
// ═══════════════════════════════════════════════════════════════
// CUSTOMER INSTALLATION AUTHORISATION — 5.18.82, Uganda only, install_auth_enabled only (docs/61 §3, docs/63)
// ═══════════════════════════════════════════════════════════════
// The staff side of a Starlink installation's customer authorisation: ask the customer (the request, with the charges
// the staff member confirms — D4), resend the link, cancel a pending request, record a dispute, read the state. Loaded
// right after api_job_photos.php and reusing api_scheduling.php's Uganda gate ($_sjUganda), its caller ($sjCaller) and
// its job-access check ($sjMayAct, docs/44 J6): the assignee, a support leader or an admin, decided on the server from
// the account as stored now and the job as uCRM holds it. Off Uganda, or with the flag off, none of these actions
// exists — the request falls through to "Unknown API action". The customer's side is tabs/customer_app/install_auth_page.php;
// the guard that keeps a job from starting is in api_scheduling.php / api_field_ops.php; the record is lib/InstallAuth.php.

if (!empty($_sjUganda) && in_array($act, ['install_auth_status', 'install_auth_prefill', 'install_auth_request', 'install_auth_resend', 'install_auth_cancel', 'install_auth_dispute'], true)) {
    require_once dirname(__DIR__, 2) . '/lib/InstallAuth.php';
    $iaCfg = is_array($config ?? null) ? $config : [];
    $iaDir = isset($dataDir) && is_string($dataDir) ? $dataDir : null;
    if (!InstallAuth::enabled($iaCfg, $iaDir)) $er2('Customer installation authorisation is not switched on here.', 404);
    if (!$crm->isConfigured()) $er2('CRM not configured.', 503);
    $iaPdo = $store->getPdo();
    $iaTz  = dn_tz_obj($iaCfg);
    $iaMe  = $sjCaller();                                              // the account as stored now; 403 when inactive
    $iaMeId = (int)($iaMe['id'] ?? 0);

    // The job, and the right to act on it, before anything is read or written (J6).
    $iaJobOf = function (int $jobId) use ($crm, $er2, $sjMayAct, $iaMe): array {
        if ($jobId <= 0) $er2('job_id required.', 422);
        $job = $crm->get("scheduling/jobs/{$jobId}");
        if (!is_array($job) || !$job) $er2('Job not found.', 404);
        $sjMayAct($iaMe, $job);
        return $job;
    };
    $iaClientOf = function (array $job) use ($crm): array {
        $cid = (int)($job['clientId'] ?? $job['client']['id'] ?? 0);
        if ($cid <= 0) return [];
        $c = $crm->get("clients/{$cid}");
        return is_array($c) ? $c : [];
    };
    $iaState = function (array $job) use ($iaPdo, $iaCfg, $iaDir, $iaTz): array {
        return InstallAuth::detailExtras($iaPdo, $iaCfg, $iaDir, $job, $iaTz);
    };
    $iaNotifier = function () use ($crm, $store, $notify, $iaCfg, $iaDir): InstallAuthNotifier {
        require_once dirname(__DIR__, 2) . '/lib/InstallAuthNotifier.php';
        return new InstallAuthNotifier($crm, $store, $notify, $iaCfg, $iaDir);
    };

    // ── GET install_auth_status — the state, the record and the trail, as the job detail carries them ──
    if ($act === 'install_auth_status' && $met === 'GET') {
        $job = $iaJobOf((int)($_GET['job_id'] ?? 0));
        $ok2($iaState($job));
    }

    // ── GET install_auth_prefill — what the request form starts from: the customer's uCRM contact, and suggestions ──
    // The charges are never prefilled from a price list: there is no authoritative installation charge in this plugin
    // (docs/61 item 7), so the staff member types or confirms them (D4). Service and equipment are suggested from the
    // client's uCRM services and the KYC application when one exists; the staff member may change either.
    // 5.18.85: the customer's latest uCRM quotation — the price agreed with that customer, not a price list — fills what it
    // holds first: the kit, the plan, the installation charge, a priced transport charge, other one-time charges (D4's
    // "prefilled when a quotation exists"). The form names the quotation; the sender checks and may change every value.
    if ($act === 'install_auth_prefill' && $met === 'GET') {
        $job = $iaJobOf((int)($_GET['job_id'] ?? 0));
        if (!InstallAuth::inScope($job, $iaCfg)) $er2('Customer authorisation applies to Starlink installation jobs only.', 422);
        $client = $iaClientOf($job);
        $cid    = (int)($client['id'] ?? 0);
        $service = ''; $equipment = '';
        $iaQuote = null; $iaQuoteRead = 'none';
        if ($cid > 0) {
            // Quotes are read the way the plugin's quote screens read them: with the admin token when one is set
            // (crm_auth_token, and crm_base_url if given), otherwise with the plugin's own key. The list may hold every
            // client's quotes; QuotationPrefill::pick keeps this client's only.
            require_once dirname(__DIR__, 2) . '/lib/QuotationPrefill.php';
            $iaQTok = trim((string)($iaCfg['crm_auth_token'] ?? ''));
            $iaQUrl = trim((string)($iaCfg['crm_base_url'] ?? ''));
            $iaQCrm = $iaQTok !== '' ? new CrmApiClient($iaQUrl !== '' ? $iaQUrl : rtrim($crm->getBaseUrl(), '/'), $iaQTok, 'x-auth-token') : $crm;
            $iaQList = $iaQCrm->get('billing/quotes?' . http_build_query(['clientId' => $cid]));
            if (is_array($iaQList)) {
                $iaQ = QuotationPrefill::pick($iaQList, $cid);
                if ($iaQ !== null) { $iaQuote = QuotationPrefill::suggest($iaQ, $iaTz); $iaQuoteRead = 'found'; }
            } else {
                $iaQuoteRead = 'unreadable';
            }
        }
        if ($cid > 0) {
            $svcs = $crm->get("clients/{$cid}/services");
            foreach (is_array($svcs) ? $svcs : [] as $sv) {
                $st = (int)($sv['status'] ?? 0);
                if (in_array($st, [1, 4, 5], true)) { $service = trim((string)($sv['name'] ?? $sv['servicePlanName'] ?? '')); if ($service !== '') break; }
            }
            $apps = $store->load('kyc_applications.json') ?? [];
            $latest = null;
            foreach (is_array($apps) ? $apps : [] as $app) {
                if ((int)($app['crm_client_id'] ?? 0) !== $cid) continue;
                if ($latest === null || strcmp((string)($app['created_at'] ?? ''), (string)($latest['created_at'] ?? '')) >= 0) $latest = $app;
            }
            if (is_array($latest)) {
                $parts = [];
                $cart = json_decode((string)($latest['hw_cart_json'] ?? ''), true);
                if (is_array($cart) && $cart) {
                    foreach ($cart as $it) {
                        $t = trim((string)($it['title'] ?? '')); if ($t === '') continue;
                        $q = max(1, (int)($it['qty'] ?? 1));
                        $parts[] = $t . ($q > 1 ? " x{$q}" : '');
                    }
                } elseif (trim((string)($latest['device_title'] ?? '')) !== '') {
                    $q = max(1, (int)($latest['kitQty'] ?? 1));
                    $parts[] = trim((string)$latest['device_title']) . ($q > 1 ? " x{$q}" : '');
                }
                $equipment = implode(', ', $parts);
                if ($service === '' && trim((string)($latest['offer_name'] ?? '')) !== '') $service = trim((string)$latest['offer_name']);
            }
        }
        if ($iaQuote !== null && $iaQuote['service'] !== '')   $service   = $iaQuote['service'];
        if ($iaQuote !== null && $iaQuote['equipment'] !== '') $equipment = $iaQuote['equipment'];
        if ($service === '') $service = 'Starlink internet service';
        $when = trim((string)($job['date'] ?? ''));
        $whenTs = $when !== '' ? strtotime($when) : false;
        $ok2([
            'job' => ['id' => (int)$job['id'], 'title' => (string)($job['title'] ?? ''), 'status' => is_numeric($job['status'] ?? null) ? (int)$job['status'] : null,
                      'address' => trim((string)($job['address'] ?? '')), 'duration' => (int)($job['duration'] ?? 0),
                      'date_label' => $whenTs !== false ? InstallAuth::when(gmdate('Y-m-d H:i:s', $whenTs), $iaTz) : ''],
            'customer' => ['id' => $cid, 'name' => InstallAuth::clientName($client), 'phone' => InstallAuth::clientPhone($client),
                           'email' => InstallAuth::clientEmail($client), 'address' => InstallAuth::clientAddress($client)],
            'suggest' => ['service' => InstallAuth::oneLine($service, 160), 'equipment' => InstallAuth::oneLine($equipment, 160),
                          'location' => InstallAuth::oneLine(trim((string)($job['address'] ?? '')) ?: InstallAuth::clientAddress($client), 200),
                          // 5.18.85: from the quotation, or null — the form then starts empty, as before
                          'installation' => $iaQuote['installation'] ?? null, 'transport' => $iaQuote['transport'] ?? null,
                          'other' => $iaQuote['other'] ?? null, 'other_label' => (string)($iaQuote['other_label'] ?? '')],
            // 5.18.85: which quotation filled the form (found), that there is none (none), or that uCRM did not answer (unreadable)
            'quote'      => $iaQuote === null ? null : ['number' => $iaQuote['number'], 'date' => $iaQuote['date'],
                                                        'transport_unpriced' => $iaQuote['transport_unpriced']],
            'quote_read' => $iaQuoteRead,
            'currency'      => InstallAuth::currency($iaCfg),
            'terms_version' => InstallationTerms::VERSION,
            'terms_hash'    => InstallationTerms::hash(),
            'link_days'     => InstallAuth::linkDays($iaCfg),
            // 5.18.83 (docs/64 §E): which channels are switched on, and why a request would be refused before it is made.
            'channels'      => ['whatsapp' => InstallAuth::whatsappOn($iaCfg), 'email' => InstallAuth::emailOn('request', $iaCfg)],
            'cannot_send'   => InstallAuth::noChannelReason($iaCfg, InstallAuth::clientPhone($client), InstallAuth::clientEmail($client)),
            'existing'      => $iaState($job),
        ]);
    }

    // ── POST install_auth_request — the record, the token, then the customer's WhatsApp and e-mail ──
    if ($act === 'install_auth_request' && $met === 'POST') {
        $job    = $iaJobOf((int)($body['job_id'] ?? 0));
        $client = $iaClientOf($job);
        $r = InstallAuth::request($iaPdo, $job, $client, is_array($body) ? $body : [], $iaMe, $iaCfg, $iaTz);
        if (!$r['ok']) $er2($r['error'], (int)$r['code']);
        $sent = $iaNotifier()->sendRequest($r['row'], $r['token'], 'request', $iaMeId);   // the token leaves the server only inside the messages
        $ok2($iaState($job) + ['sent' => $sent, 'superseded' => $r['superseded']], 'Authorisation request sent.', 201);
    }

    // ── POST install_auth_resend — a new link for a pending request, the same record ──
    if ($act === 'install_auth_resend' && $met === 'POST') {
        $job = $iaJobOf((int)($body['job_id'] ?? 0));
        $r = InstallAuth::resend($iaPdo, (int)$job['id'], $iaCfg, $iaTz);
        if (!$r['ok']) $er2($r['error'], (int)$r['code']);
        $sent = $iaNotifier()->sendRequest($r['row'], $r['token'], 'resend', $iaMeId);
        $ok2($iaState($job) + ['sent' => $sent], 'Link sent again.');
    }

    // ── POST install_auth_cancel — withdraw a pending request; the link stops working at once ──
    if ($act === 'install_auth_cancel' && $met === 'POST') {
        $job = $iaJobOf((int)($body['job_id'] ?? 0));
        $r = InstallAuth::cancel($iaPdo, (int)$job['id'], 'staff', $iaMeId, (string)($body['reason'] ?? ''));
        if (!$r['ok']) $er2($r['error'], (int)$r['code']);
        $ok2($iaState($job), 'Request cancelled.');
    }

    // ── POST install_auth_dispute — the customer disputes the installation: recorded, nothing else changes ──
    if ($act === 'install_auth_dispute' && $met === 'POST') {
        $job = $iaJobOf((int)($body['job_id'] ?? 0));
        $r = InstallAuth::dispute($iaPdo, (int)$job['id'], $iaMeId, (string)($body['reason'] ?? ''));
        if (!$r['ok']) $er2($r['error'], (int)$r['code']);
        $ok2($iaState($job), 'Dispute recorded.');
    }
}
