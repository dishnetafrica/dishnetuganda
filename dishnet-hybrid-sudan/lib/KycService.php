<?php
declare(strict_types=1);
require_once __DIR__ . '/currency.php';
require_once __DIR__ . '/UcrmClientTarget.php';
require_once __DIR__ . '/QuoteOrganization.php';   // 5.18.99: the Uganda quoting organization and the quote check
require_once __DIR__ . '/UcrmQuoteLines.php';      // 5.18.102: Uganda's lines and cash from uCRM Products
require_once __DIR__ . '/UcrmCatalogue.php';       // 5.18.103 (root docs/72 §G): the uCRM catalogue's switch and settings

/**
 * KycService
 *
 * Handles KYC form processing — retailer-aware.
 * Matches original kyc_store() controller logic exactly.
 */
class KycService
{
    // CRM Custom Attribute IDs
    const ATTR_PRIORITY    = 36;
    const ATTR_SALES_PERSON= 1;
    const ATTR_REF         = 43;
    const ATTR_DEVICE_ID   = 42;
    const ATTR_PACKAGE     = 41;
    const ATTR_KIT_NUMBER  = 37;
    const ATTR_KIT_QTY     = 38;
    const ATTR_KIT_UNIT    = 40;
    const ATTR_KIT_NAME    = 39;

    // Document template IDs
    const TPL_DELIVERY_NOTE    = 4;
    const TPL_WORK_ORDER_NEW   = 2;
    const TPL_WORK_ORDER_OTHER = 3;
    const QUOTE_MATURITY_DAYS  = 7;   // Quote valid for 7 days

    // CRM tag IDs
    const TAG_NEW_CONNECTION   = 52;
    const TAG_SHIFTING         = 53;
    const TAG_OWNERSHIP_CHANGE = 54;

    // Fiber fixed pricing
    const FIBER_PRICES = [
        'Premium (60 Mbps $ 100/Month)' => 100,
        'Standard (40 Mbps $ 75/Month)' => 75,
    ];

    private CrmApiClient  $crm;
    private      $store;
    private WalletService $wallet;
    private CrmQueue      $queue;
    private string        $dataDir;

    public function __construct(CrmApiClient $crm,  $store, WalletService $wallet, CrmQueue $queue, string $dataDir = '')
    {
        $this->crm     = $crm;
        $this->store   = $store;
        $this->wallet  = $wallet;
        $this->queue   = $queue;
        $this->dataDir = $dataDir ?: (dirname(__DIR__) . '/data');
    }

    /**
     * 5.18.54 (docs/46 row 19, D8): mark $username as a client this plugin is about to create in uCRM, on Uganda.
     *
     * uCRM raises client.add as soon as it has made the client, and the webhook decides between its welcome and the
     * form's own "Request Confirmed!" by looking for the application that names the client — which the form saves only
     * after uCRM has answered. The event can overtake it, and the customer then got both. The mark is written before
     * uCRM is asked; the webhook reads it as the application. Kept in notification_dedup, so no new table. An error
     * writes nothing, and the webhook then decides as before.
     */
    public static function markSignup($store, array $config, ?string $dataDir, string $username): void
    {
        if ($username === '') return;
        try {
            require_once __DIR__ . '/NotifyGate.php';
            if (!\NotifyGate::applies(\NotifyGate::KYC_WELCOME, $config, $dataDir)) return;
            require_once __DIR__ . '/NotificationService.php';
            (new \NotificationService($store, $config))->dedupMark('KYCNEW:' . $username);
        } catch (\Throwable $e) {
            error_log('[KycService] sign-up mark not written: ' . $e->getMessage());
        }
    }

    /**
     * 5.18.54 (docs/46 row 18, D-8): uCRM's own send for a KYC quote, behind a prepared switch.
     *
     * The send marks the quote sent in uCRM and has uCRM e-mail it; the plugin's quote.add e-mail can then be a second
     * (docs/45 D2a). Which of the two owns the quotation e-mail is decision O6, taken once uCRM's own notification
     * settings have been read, so nothing changes by default: kyc_quote_send_via_crm unset, or on, makes the call exactly
     * as before, in both countries; 0 makes none. The answer used to be dropped. On Uganda a refusal now leaves a line in
     * uCRM's log for the plugin, naming the quote and never the customer; South Sudan's call is the 5.18.53 call.
     */
    public static function sendQuoteViaCrm($quoteCrm, array $cfg, ?string $dataDir, $quoteId, string $context): void
    {
        $switch = $cfg['kyc_quote_send_via_crm'] ?? '';
        if ($switch !== '' && $switch !== null && !filter_var($switch, FILTER_VALIDATE_BOOLEAN)) {
            error_log("[KycService] Quote #{$quoteId} ({$context}): uCRM's send not called — kyc_quote_send_via_crm is off");
            return;
        }
        $answer = $quoteCrm->patch("billing/quotes/{$quoteId}/send");
        if ($answer !== null) return;
        try {
            require_once __DIR__ . '/NotifyGate.php';
            if (!\NotifyGate::applies(\NotifyGate::KYC_QUOTE_SEND, $cfg, $dataDir)) return;
            require_once __DIR__ . '/PluginLog.php';
            $err = method_exists($quoteCrm, 'getLastError') ? (array)$quoteCrm->getLastError() : [];
            $why = isset($err['http_code'])
                ? 'uCRM answered HTTP ' . (int)$err['http_code']
                  . (is_array($err['response'] ?? null) && is_scalar($err['response']['message'] ?? null)
                      ? ': ' . (string)$err['response']['message'] : '')
                : (isset($err['curl_error']) ? 'uCRM could not be reached' : 'no answer');
            $line = "KYC quote #{$quoteId} ({$context}) was not sent by uCRM — {$why}. "
                  . 'The quote is in uCRM, and its WhatsApp is still queued.';
            error_log('[KycService] ' . \PluginLog::mask($line));
            \PluginLog::write('quotes', $line);
        } catch (\Throwable $e) {
            error_log('[KycService] quote send result not recorded: ' . $e->getMessage());
        }
    }

    /**
     * Return a clone of this service using a different CRM client.
     * Used to post KYC payments under the agent's personal UCRM app key
     * so "Created By" in UCRM shows the agent's name.
     */
    public function withCrm(CrmApiClient $crm): self
    {
        $clone = clone $this;
        $clone->crm = $crm;
        return $clone;
    }

    /**
     * 5.18.99: the data directory the quoting-organization gate reads (QuoteOrganization::setting): the store's, as
     * QuotationService and KycCrmSync read it, else this service's own. It differs only when the vault falls back to
     * <dataDir>/config_vault.json (ConfigVault::path).
     */
    private function gateDataDir(): string
    {
        return method_exists($this->store, 'getDataDir') ? (string)$this->store->getDataDir() : $this->dataDir;
    }

    /**
     * 5.18.102 (Uganda, docs/71 §G): uCRM's prices moved under a Cash sale, and its refusal asks for the form to be
     * reloaded — so the page cache, which may hold the old prices for up to a minute, goes: the reload reads uCRM.
     */
    private function dropPageCache(): void
    {
        foreach (array_unique([$this->dataDir, $this->gateDataDir()]) as $d) UcrmQuoteLines::dropPageCache((string)$d);
    }

    // ══════════════════════════════════════════════════════════════════════
    // PUBLIC — called by web form & mobile API
    // ══════════════════════════════════════════════════════════════════════

    /**
     * @param array  $post       Validated form/POST data
     * @param array  $files      $_FILES array (or [] for API with base64)
     * @param array  $retailer   Full retailer record
     * @return array ['success'=>bool, 'message'=>string, 'data'=>array]
     */
    public function process(array $post, array $files, array $retailer): array
    {
        // MED-02 FIX: Idempotency key on form submission prevents double-register
        // from a double-tap on slow mobile networks (agent taps Submit, sees spinner,
        // taps again). The form embeds a UUID generated at page-load time.
        // If we've already processed this exact submission key, return cached result.
        $submissionKey = trim($post['submission_key'] ?? '');
        if ($submissionKey !== '') {
            $existing = $this->store->findOne('kyc_applications.json', 'submission_key', $submissionKey);
            if ($existing !== null) {
                return [
                    'success' => true,
                    'message' => 'Application already submitted (duplicate request ignored).',
                    'data'    => $existing,
                ];
            }
        }

        // Route: existing customer or new?
        if (!empty(trim($post['customer_id'] ?? ''))) {
            return $this->handleExisting($post, $retailer);
        }
        return $this->handleNew($post, $files, $retailer);
    }

    // ══════════════════════════════════════════════════════════════════════
    // CASE 1 — EXISTING CUSTOMER  (PATCH)
    // ══════════════════════════════════════════════════════════════════════

    private function handleExisting(array $post, array $retailer): array
    {
        $cfg         = $this->store->load('kyc_config.json') ?: [];
        $customerId  = trim($post['customer_id']);
        // 5.18.99 (Uganda): the CRM ID is a number, and the check, the quote and the record all use the same one. The
        // path is concatenated into the request, so "15/../16" would be checked as 15 and quoted for 16.
        $quoteOrg = QuoteOrganization::setting(is_array($cfg) ? $cfg : [], $this->gateDataDir());
        if ($quoteOrg['on']) {
            if (!ctype_digit($customerId) || (int)$customerId <= 0) {
                QuoteOrganization::logNotANumber('kyc-existing');   // PHP's log and the plugin's log, as every refusal
                return ['success' => false, 'message' => QuoteOrganization::text('R3', ['{ID}' => QuoteOrganization::plain($customerId)])];
            }
            $customerId = (string)(int)$customerId;
        }
        $salesPerson = $this->resolveSalesPerson($post, $retailer);

        // ── Resolve plan + hardware ───────────────────────────────────────
        $device = $this->store->findOne('kyc_devices.json', 'id', (int)($post['device_id'] ?? 0));
        $offer  = $this->store->findOne('subscription_plans.json', 'id', (int)($post['package_choice'] ?? 0));
        if (!$offer) $offer = $this->store->findOne('kyc_packages.json', 'id', (int)($post['package_choice'] ?? 0));

        if (!$offer) {
            return ['success' => false, 'message' => 'Please select a valid service plan.'];
        }

        // 5.18.99: built before anything is saved, so the quote check reads uCRM with the client the quote is made with.
        // Building it sends nothing.
        $_quoteCfg   = $cfg;
        $_quoteToken = trim($_quoteCfg['crm_auth_token'] ?? '');
        $_quoteUrl   = trim($_quoteCfg['crm_base_url'] ?? '');
        $quoteCrm = ($_quoteToken !== '')
            ? new CrmApiClient(
                $_quoteUrl !== '' ? $_quoteUrl : rtrim($this->crm->getBaseUrl(), '/'),
                $_quoteToken,
                'x-auth-token'
              )
            : $this->crm;

        // 5.18.99 (Uganda, "always company 1"): no quote for a customer filed under another company. Before the first
        // write: no application, no quote, no job, no wallet debit, nothing sent.
        if ($quoteOrg['on']) {
            $quoteCheck = (new QuoteOrganization($quoteCrm, $quoteOrg))->checkClient((int)$customerId, 'kyc-existing');
            if (!$quoteCheck['ok']) return ['success' => false, 'message' => $quoteCheck['message']];
        }

        // 5.18.102 (Uganda, docs/71 §F.2, §H): uCRM prices the additional service — after the company check, so a misfiled
        // customer is told to move, and before the wallet check and any write. A Cash sale it cannot price is refused here.
        $ug = null;
        if ($quoteOrg['on']) {
            // 5.18.102 (D14, docs/71 §R.4): priced only through the links an admin made, read from the store behind the gate
            // 5.18.103 (docs/72 §G G-9): with the uCRM catalogue switched on, no post here is 'web' (only the two APIs reach it)
            $ug = (new UcrmQuoteLines($quoteCrm, $quoteOrg, UcrmQuoteLinks::fromStore($this->store, $quoteOrg)))->resolve(
                self::saleFromPost($post, trim($post['connectivity_type'] ?? 'Additional Service'),
                    UcrmCatalogue::fromStore($this->store, $quoteOrg)->direct()), $this->store);
            $ugRefusal = self::cashRefusal($ug, $post);
            if ($ugRefusal === UcrmQuoteLines::text('CASH_CHANGED')) $this->dropPageCache();
            if ($ugRefusal !== '') return ['success' => false, 'message' => $ugRefusal];
        }

        // ── Resolve amount ────────────────────────────────────────────────
        // 5.18.102 (Uganda): the resolved total; 0 for a Credit sale uCRM cannot price (no quote, no debit)
        [$checkAmount, , ] = $ug !== null ? [self::ugAmount($ug), null, null] : $this->resolveAmount($post);
        $isCash = (($post['sales_type'] ?? '') === 'Cash');

        // ── Wallet check for Cash sales ───────────────────────────────────
        // handleExisting creates a quote + optionally posts payment to UCRM.
        // For Cash: agent must have sufficient wallet balance before proceeding.
        // For Credit: no wallet deduction — quote only.
        if ($isCash && $checkAmount > 0) {
            if (!$this->wallet->hasSufficientBalance($retailer['id'], $checkAmount)) {
                $bal = $this->wallet->getBalance($retailer['id']);
                return [
                    'success' => false,
                    'message' => "Insufficient wallet balance. Required: " . dn_cur() . number_format($checkAmount, 2) .
                                 ", Available: " . dn_cur() . number_format($bal, 2) . ".",
                ];
            }
        }

        // ── New service address (key info for admin in quote notes) ───────
        $newAddress  = trim(($post['address_1'] ?? '') . ', ' . ($post['address_2'] ?? ''));
        $newAddress  = rtrim($newAddress, ', ');
        if (($post['customer_type'] ?? '') === 'Fiber' && !empty($post['fiber_area'])) {
            $newAddress = ($post['fiber_area'] ?? '') . ($newAddress ? ' — ' . $newAddress : '');
        }
        $connectivity = trim($post['connectivity_type'] ?? 'Additional Service');

        // ── Build quote items ─────────────────────────────────────────────
        $quoteItems = [];

        // Service plan
        $planItem = [
            'label'    => $offer['name'] ?? 'Service Package',
            'quantity' => 1,
            'price'    => (float)($offer['customer_price'] ?? $offer['amount'] ?? 0),
            'unit'     => 'month',
        ];
        if (!empty($offer['ucrm_product_id'])) {
            $planItem['productId'] = (int)$offer['ucrm_product_id'];
        }
        $quoteItems[] = $planItem;

        // Hardware cart
        $hwCart = [];
        if (!empty($post['hw_cart_json'])) {
            $hwCart = json_decode($post['hw_cart_json'], true) ?? [];
        }
        if (!empty($hwCart)) {
            foreach ($hwCart as $hwItem) {
                $hwPrice = (float)preg_replace('/[^0-9.]/', '', (string)($hwItem['price'] ?? '0'));
                if ($hwPrice > 0) {
                    $item = [
                        'label'    => $hwItem['title'] ?? 'Hardware',
                        'quantity' => max(1, (int)($hwItem['qty'] ?? 1)),
                        'price'    => $hwPrice,
                        'unit'     => 'piece',
                    ];
                    if (!empty($hwItem['ucrm_product_id'])) {
                        $item['productId'] = (int)$hwItem['ucrm_product_id'];
                    }
                    $quoteItems[] = $item;
                }
            }
        } elseif (!empty($device) && !empty($device['price'])) {
            $devPrice = (float)preg_replace('/[^0-9.]/', '', $device['price'] ?? '0');
            if ($devPrice > 0) {
                $item = [
                    'label'    => $device['title'] ?? 'Hardware / Kit',
                    'quantity' => max(1, (int)($post['kitQty'] ?? 1)),
                    'price'    => $devPrice,
                    'unit'     => 'piece',
                ];
                if (!empty($device['ucrm_product_id'])) {
                    $item['productId'] = (int)$device['ucrm_product_id'];
                }
                $quoteItems[] = $item;
            }
        }

        // Fiber installation fee (5.18.102: never on Uganda — South Sudan's fee and product 244)
        if ($ug === null && ($post['customer_type'] ?? '') === 'Fiber') {
            $installFee     = (float)($cfg['fiber_install_fee'] ?? 100);
            $installProduct = (int)($cfg['fiber_install_product_id'] ?? 244);
            if ($installFee > 0) {
                $item = [
                    'label'    => 'Installation Fee',
                    'quantity' => 1,
                    'price'    => $installFee,
                    'unit'     => 'amount',
                ];
                if ($installProduct > 0) $item['productId'] = $installProduct;
                $quoteItems[] = $item;
            }
        }
        // 5.18.102 (Uganda): uCRM's lines replace the plugin-priced ones above — none when the sale is unpriced
        if ($ug !== null) $quoteItems = $ug['status'] === 'priced' ? $ug['lines'] : [];

        // ── Save application locally first (generates quote_ref) ──────────
        $appId = $this->saveApplication(array_merge([
            'connectivity_type'     => $connectivity,
            'customer_type'         => $post['customer_type'] ?? '',
            'date'                  => $post['date'] ?? date('Y-m-d'),
            'crm_client_id'         => $customerId,
            'crm_doc_id'            => null,
            'firstname'             => $post['firstname'] ?? '',
            'lastname'              => $post['lastname'] ?? '',
            'mobile'                => $post['mobile'] ?? '',
            'email'                 => $post['email'] ?? '',
            'contacts'              => $this->buildContacts($post),
            'address_1'             => $post['address_1'] ?? '',
            'address_2'             => $post['address_2'] ?? '',
            'fiber_area'            => $post['fiber_area'] ?? '',
            'device_id'             => $post['device_id'] ?? '',
            'package_choice'        => $post['package_choice'] ?? '',
            'priority'              => $post['priority'] ?? '',
            'sales_person'          => $salesPerson,
            'sales_type'            => $post['sales_type'] ?? 'Credit',
            'ref'                   => $post['ref'] ?? '',
            'latitude'              => $post['latitude'] ?? '',
            'longitude'             => $post['longitude'] ?? '',
            'retailer_id'           => $retailer['id'],
            'retailer_name'         => $retailer['name'] ?? '',
            'status'                => 'pending_sync',
            'sync_status'           => 'pending_sync',
            'amount_charged'        => 0,
            'is_lead'               => true,
            'crm_client_type'       => 'existing',
            'is_additional_service' => true,
            'new_service_address'   => $newAddress,
            'quote_id'              => null,
            'quote_created'         => false,
        ], $ug !== null ? self::linesRecord($ug, $post, $connectivity) : []));   // 5.18.102 (Uganda): docs/71 §F.3

        // ── Post quote to CRM ─────────────────────────────────────────────
        $quoteId      = null;
        $quoteCreated = false;
        $qRef         = '';

        $savedApp = $this->store->findOne('kyc_applications.json', 'id', $appId);
        $qRef     = $savedApp['quote_ref'] ?? '';

        // ($quoteCrm is built above, before the application is saved — 5.18.99.)

        // Notes include new service address prominently so admin knows where to install
        $quoteNotes = "ADDITIONAL SERVICE — EXISTING CUSTOMER\n"
                    . "New service address: {$newAddress}\n"
                    . "Service type: " . ($post['customer_type'] ?? '') . "\n"
                    . "Connection: {$connectivity}\n"
                    . "Priority: " . ($post['priority'] ?? 'Medium') . "\n"
                    . "Sales: {$salesPerson}\n"
                    . ($qRef ? "Ref: {$qRef}" : '');

        $quotePayload = [
            'notes'      => $quoteNotes,
            'adminNotes' => 'Additional service for existing client. Plugin ref: ' . $qRef,
            'items'      => $quoteItems,
        ];

        // 5.18.102 (Uganda, docs/71 §F.1 row 3): the customer sees the prefix and the installation site; the rest is
        // staff's. No quote at all when uCRM cannot price the sale (P1).
        $ugNoQuote = '';
        if ($ug !== null) {
            $quotePayload = [
                'notes'      => self::customerNotes($cfg, $newAddress !== '' ? 'Installation site: ' . $newAddress : ''),
                'adminNotes' => "ADDITIONAL SERVICE — EXISTING CUSTOMER\n"
                              . "Service type: " . ($post['customer_type'] ?? '') . "\n"
                              . "Connection: {$connectivity}\n"
                              . "Priority: " . ($post['priority'] ?? 'Medium') . "\n"
                              . "Sales: {$salesPerson}\n"
                              . (self::installLabel($post['install_by'] ?? '') !== '' ? 'Install: ' . self::installLabel($post['install_by'] ?? '') . "\n" : '')
                              . "KYC application #{$appId}"
                              . (self::composedRef($qRef) !== '' ? "\nPlugin ref: " . self::composedRef($qRef) : ''),
                'items'      => $quoteItems,
            ];
            if ($ug['status'] !== 'priced') {
                $ugNoQuote = self::p1Text($ug);   // 5.18.102 (D14, §R.4.1): P1_UNLINKED's text for a web sale not linked
                self::recordNoQuote($this->store, (int)$appId, 'P1', $ugNoQuote);
            }
        }

        $quoteResponse = $ugNoQuote === '' ? $quoteCrm->post("clients/{$customerId}/quotes", $quotePayload) : null;

        if (!empty($quoteResponse['id'])) {
            $quoteId      = $quoteResponse['id'];
            $quoteCreated = true;

            $ucrmQuoteNumber = $quoteResponse['number'] ?? null;
            if (!$ucrmQuoteNumber) {
                $fetched         = $quoteCrm->get("billing/quotes/{$quoteId}");
                $ucrmQuoteNumber = $fetched['number'] ?? null;
            }
            if ($ucrmQuoteNumber) $qRef = $ucrmQuoteNumber;
            if ($ug !== null) self::readBack($quoteCrm, $this->store, (int)$appId, $quoteId, $quoteItems, $quoteResponse, $fetched ?? null);   // 5.18.102

            self::sendQuoteViaCrm($quoteCrm, $cfg, $this->dataDir, $quoteId, "additional service, client #{$customerId}");   // D-8

            $this->store->updateOne('kyc_applications.json', 'id', $appId, [
                'quote_id'             => $quoteId,
                'quote_ref'            => $qRef,
                'quote_created'        => true,
                'sync_status'          => 'synced',
                'status'               => 'synced',
                'wa_quote_pending'     => true,
                'wa_quote_phone'       => preg_replace('/[^0-9+]/', '', $post['mobile'] ?? ''),
                'wa_quote_deferred_at' => date('Y-m-d H:i:s'),
            ]);
        } elseif ($ugNoQuote === '') {
            error_log('[KycService] Additional service quote failed for CRM #' . $customerId . ': ' . json_encode($quoteCrm->getLastError()));
            $this->store->updateOne('kyc_applications.json', 'id', $appId, [
                'quote_error' => json_encode($quoteCrm->getLastError()),
            ]);
        }

        // ── Create UCRM scheduling job assigned to Bidal (v4.20.2+) ───────
        // This is the operational handoff — Bidal sees a clearly-titled job
        // in CRM → Scheduling that flags this as a SECOND site for an
        // EXISTING customer, so he doesn't accidentally modify the original
        // service. Job creation failure is non-fatal: the quote stands.
        $crmJobId = $this->createCrmJobForBidal(
            $customerId,
            $post,
            $newAddress,
            $qRef,
            $appId,
            $ug   // 5.18.102 (Uganda): the job's plan price is uCRM's, and a kit-only sale says so
        );
        if ($crmJobId > 0) {
            $this->store->updateOne('kyc_applications.json', 'id', $appId, [
                'crm_job_id'         => $crmJobId,
                'crm_job_created_at' => date('Y-m-d H:i:s'),
            ]);
        }

        // ── Debit wallet for Cash sales ───────────────────────────────────
        $walletDebited = false;
        if ($isCash && $checkAmount > 0) {
            try {
                $this->wallet->debit(
                    $retailer['id'],
                    $checkAmount,
                    "Additional service — CRM #{$customerId} — {$newAddress}",
                    'additional_service_' . $appId
                );
                $walletDebited = true;
                $this->store->updateOne('kyc_applications.json', 'id', $appId, [
                    'amount_charged' => $checkAmount,
                    'wallet_debited' => true,
                ]);
            } catch (\Throwable $e) {
                error_log('[KycService] Wallet debit failed for handleExisting appId=' . $appId . ': ' . $e->getMessage());
            }
        }

        $successMsg = $quoteCreated
            ? "Quote #{$qRef} created for additional service at: {$newAddress}. Admin will review and activate."
            : "Application saved. Please notify admin to create the service manually.";

        if ($ugNoQuote !== '') $successMsg = 'Application saved. ' . $ugNoQuote;   // 5.18.102 (Uganda): no quote, and why
        if ($walletDebited) {
            $successMsg .= " Wallet debited " . dn_cur() . number_format($checkAmount, 2) . ".";
        }

        return self::ugWithInstall($ug, $post, $connectivity, [   // 5.18.102 (Uganda): who installs, for the staff messages
            'success' => true,
            'message' => $successMsg,
            'data'    => [
                'crm_client_id'         => $customerId,
                'application_id'        => $appId,
                'id'                    => $appId,            // alias: callers expect 'id'
                'quote_id'              => $quoteId,
                'quote_ref'             => $qRef,
                'quote_created'         => $quoteCreated,
                'new_address'           => $newAddress,
                'new_service_address'   => $newAddress,
                'wallet_debited'        => $walletDebited,
                'amount_charged'        => $isCash ? $checkAmount : 0,
                'is_additional_service' => true,
                'crm_client_type'       => 'existing',
                'crm_job_id'            => $crmJobId,
                'firstname'             => $post['firstname']     ?? '',
                'lastname'              => $post['lastname']      ?? '',
                'mobile'                => $post['mobile']        ?? '',
                'customer_type'         => $post['customer_type'] ?? '',
                'connectivity_type'     => $connectivity,
                'sales_type'            => $post['sales_type']    ?? 'Credit',
            ] + ($ugNoQuote !== '' ? ['quote_refused' => $ugNoQuote] : []),   // 5.18.102: only when no quote was made
        ]);
    }


    // ══════════════════════════════════════════════════════════════════════
    // CASE 2 — NEW CUSTOMER  (POST)
    // ══════════════════════════════════════════════════════════════════════

    private function handleNew(array $post, array $files, array $retailer): array
    {
        // I-02 FIX: Load kyc_config.json once and reuse throughout this method.
        // Previously it was loaded 4-5 times as $cfg2/$cfg3/$cfg4 in different steps.
        $cfg = $this->store->load('kyc_config.json') ?: [];

        $salesPerson  = $this->resolveSalesPerson($post, $retailer);
        $connectivity = trim($post['connectivity_type'] ?? 'New Connection');

        // ── 5.18.102 (Uganda, docs/71 §F.2, §H): uCRM prices the sale ONCE, before anything is written ──────
        // Ahead of the duplicate-phone block, which can write a duplicate_confirmations row, so a refusal here writes
        // nothing at all. That one result is the cash, the stored lines and the quote. A Cash sale uCRM cannot price,
        // or whose form showed another total, goes no further.
        $ugOrg = QuoteOrganization::setting(is_array($cfg) ? $cfg : [], $this->gateDataDir());
        $ug = null;
        if ($ugOrg['on']) {
            // 5.18.103 (root docs/72 §G G-9): the uCRM catalogue's switch decides how a post is read. A direct form's post is
            // priced by uCRM id (resolveDirect) only while the switch is on — with it off it is refused before anything is
            // written; every other post is 5.18.102's, by the links, read as 'app' while the switch is on.
            $ugCat    = UcrmCatalogue::fromStore($this->store, $ugOrg);
            $ugDirect = $ugCat->direct();
            $ugForm   = ($post['ucrm_quote_form'] ?? null) === UcrmQuoteLines::DIRECT_MARKER;
            if ($ugForm && !$ugDirect) return ['success' => false, 'message' => UcrmQuoteLines::text('DIRECT_OFF')];
            $ugSale = self::saleFromPost($post, $connectivity, $ugDirect);
            if ($ugForm) {
                if ($ugSale['direct_plan'] === null) return ['success' => false, 'message' => UcrmQuoteLines::text('DIRECT_NO_PLAN')];
                if ($ugSale['direct_cart'] === null) return ['success' => false, 'message' => UcrmQuoteLines::text('DIRECT_CART')];
                $ug = (new UcrmQuoteLines(UcrmQuoteLines::quoteClient($this->crm, is_array($cfg) ? $cfg : []), $ugOrg,
                        UcrmQuoteLinks::fromRecords([], [])))
                    ->resolveDirect($ugSale, $ugCat->installProductId(), $ugCat->hiddenIds());
                if ($ug['status'] === 'invalid') return ['success' => false, 'message' => (string)$ug['reason']];
                // G-7: from here on the plugin-id fields are the server's — never a uCRM id, never what the browser sent —
                // before the duplicate check, buildAttributes and the stored record read them.
                $post['package_choice'] = '';
                $post['device_id']      = '';
                $post['hw_cart_json']   = self::directCartJson($ug);
                $post['kitQty']         = (string)(($ug['item_ids']['hw'][0]['q'] ?? null) ?: 1);
            } else {
                // 5.18.102 (D14, docs/71 §R.4): priced only through the links an admin made, read from the store behind the gate
                $ug = (new UcrmQuoteLines(UcrmQuoteLines::quoteClient($this->crm, is_array($cfg) ? $cfg : []), $ugOrg,
                        UcrmQuoteLinks::fromStore($this->store, $ugOrg)))
                    ->resolve($ugSale, $this->store);
            }
            if ($ug['status'] === 'no_plan') return ['success' => false, 'message' => 'Please select a valid package/plan.'];
            $ugRefusal = self::cashRefusal($ug, $post);
            if ($ugRefusal === UcrmQuoteLines::text('CASH_CHANGED') || $ugRefusal === UcrmQuoteLines::text('CASH_ITEM_CHANGED')) $this->dropPageCache();
            if ($ugRefusal !== '') return ['success' => false, 'message' => $ugRefusal];
        }

        // ── Duplicate phone check ─────────────────────────────────────────
        // Uses local files only (< 50ms). Live CRM only if index is stale > 10 min.
        // Skips check if: customer_id already set (existing customer flow)
        //                 duplicate_confirmed = 1 (staff confirmed different customer)
        //                 only match is a failed/voided app
        $mobileRaw  = trim($post['mobile'] ?? '');
        $custIdSet  = trim($post['customer_id'] ?? '') !== '';
        $confirmed  = ($post['duplicate_confirmed'] ?? '0') === '1';
        $mobileNorm = preg_replace('/[^0-9]/', '', $mobileRaw);
        $last9      = strlen($mobileNorm) >= 9 ? substr($mobileNorm, -9) : $mobileNorm;

        if ($mobileRaw !== '' && $last9 !== '' && !$custIdSet) {

            $dupMatch    = null;  // first non-failed match found
            $failedOnly  = true;  // true if only failed apps matched

            // ── Check 1: local kyc_applications.json ─────────────────────
            $apps = $this->store->load('kyc_applications.json') ?? [];
            foreach ($apps as $app) {
                $appPhone = preg_replace('/[^0-9]/', '', $app['mobile'] ?? '');
                $appLast9 = strlen($appPhone) >= 9 ? substr($appPhone, -9) : $appPhone;
                if ($appLast9 !== $last9 || $appLast9 === '') continue;
                $appStatus = $app['sync_status'] ?? $app['status'] ?? '';
                if (in_array($appStatus, ['failed','voided','rejected','cancelled'])) continue;
                $failedOnly = false;
                $dupMatch = [
                    'name'   => trim(($app['firstname'] ?? '') . ' ' . ($app['lastname'] ?? ''))
                              ?: ($app['company_name'] ?? 'Customer'),
                    'crm_id' => (int)($app['crm_client_id'] ?? 0),
                    'source' => 'plugin_app',
                    // Saved here, but the uCRM create has not succeeded yet.
                    'waiting_app' => (empty($app['crm_client_id'])
                                      && in_array($app['crm_sync_status'] ?? '', ['pending', 'review', 'failed'], true))
                                     ? (int)($app['id'] ?? 0) : 0,
                    'saved_on' => substr((string)($app['submitted_at'] ?? ''), 0, 10),
                ];
                break;
            }

            // ── Check 2: client_search_index (SQLite table, O(1) — JSON fallback) ──
            if (!$dupMatch) {
                $csiFound = null;
                try {
                    // Fast path: SQLite indexed O(1) lookup
                    $csiStmt = $this->store->getPdo()->prepare(
                        "SELECT id, name, phone FROM client_search_index WHERE phone_norm = ? LIMIT 1"
                    );
                    $csiStmt->execute([$last9]);
                    $csiRow = $csiStmt->fetch(\PDO::FETCH_ASSOC);
                    if ($csiRow) $csiFound = $csiRow;
                } catch (\Throwable $e) {
                    $csiFound = null; // table not ready yet
                }

                if ($csiFound) {
                    $failedOnly = false;
                    $dupMatch = [
                        'name'   => $csiFound['name'] ?? 'Customer',
                        'crm_id' => (int)($csiFound['id'] ?? 0),
                        'source' => 'index',
                    ];
                } else {
                    // Fallback: JSON blob scan (or live CRM if stale > 10 min)
                    $index    = $this->store->load('client_search_index.json') ?? [];
                    $indexMeta = $this->store->load('client_index_meta.json') ?? [];
                    $indexAge  = isset($indexMeta['last_sync']) ? (time() - (int)$indexMeta['last_sync']) : 0;
                    $useIndex  = !empty($index) && ($indexAge < 600 || empty($this->crm));

                    if ($useIndex) {
                        foreach ($index as $cl) {
                            $clPhone = preg_replace('/[^0-9]/', '', $cl['phone'] ?? '');
                            $clLast9 = strlen($clPhone) >= 9 ? substr($clPhone, -9) : $clPhone;
                            if ($clLast9 !== $last9 || $clLast9 === '') continue;
                            $failedOnly = false;
                            $dupMatch = [
                                'name'   => $cl['name'] ?? 'Customer',
                                'crm_id' => (int)($cl['id'] ?? 0),
                                'source' => 'index',
                            ];
                            break;
                        }
                    } elseif ($this->crm && $this->crm->isConfigured()) {
                        // Stale index — one live CRM call as safety net
                        $crmRes = $this->crm->get('clients?search=' . urlencode($mobileRaw) . '&limit=5') ?? [];
                        foreach ($crmRes as $cl) {
                            foreach ($cl['contacts'] ?? [] as $c) {
                                $cPhone = preg_replace('/[^0-9]/', '', $c['phone'] ?? '');
                                $cLast9 = strlen($cPhone) >= 9 ? substr($cPhone, -9) : $cPhone;
                                if ($cLast9 !== $last9) continue;
                                $failedOnly = false;
                                $dupMatch = [
                                    'name'   => trim(($cl['firstName']??'').' '.($cl['lastName']??''))
                                              ?: ($cl['companyName'] ?? 'Customer'),
                                    'crm_id' => (int)($cl['id'] ?? 0),
                                    'source' => 'live_crm',
                                ];
                                break 2;
                            }
                        }
                    }
                }
            }

            // ── Decision ─────────────────────────────────────────────────
            if ($dupMatch && !$failedOnly) {
                $name   = $dupMatch['name'];
                $crmId  = $dupMatch['crm_id'];

                if ($confirmed) {
                    // Staff confirmed it's a different customer — log it and allow through
                    try {
                        $this->store->getPdo()->prepare(
                            "INSERT INTO duplicate_confirmations
                             (staff_id, staff_name, phone, existing_name, existing_crm_id, note, review_status)
                             VALUES (?, ?, ?, ?, ?, ?, 'pending')"
                        )->execute([
                            (int)($retailer['id'] ?? 0),
                            $retailer['name'] ?? 'Unknown',
                            $mobileRaw,
                            $name,
                            $crmId,
                            $post['duplicate_note'] ?? 'confirmed-different',
                        ]);
                    } catch (\Throwable $e) {
                        error_log('[KycService] Failed to log duplicate confirmation: ' . $e->getMessage());
                        // Non-fatal — log failure doesn't block the registration
                    }
                    // Allow through — fall to normal registration below

                } elseif (!empty($dupMatch['waiting_app'])) {
                    // The same customer is already saved here and waiting for
                    // uCRM. A second registration would be a second application
                    // for one person; the first is retried by itself. The staff
                    // app prints this message as HTML and the API returns it as
                    // text, so the stored name and typed phone lose the four
                    // characters that could be markup, instead of being escaped.
                    $plain = static fn(string $v): string => str_replace(['<', '>', '&', '"'], '', $v);
                    return [
                        'success' => false,
                        'message' => '⚠ Phone ' . $plain((string)$mobileRaw) . ' was already registered here for ' . $plain((string)$name)
                                   . ($dupMatch['saved_on'] !== '' ? ' on ' . $plain((string)$dupMatch['saved_on']) : '')
                                   . " (application #{$dupMatch['waiting_app']}), but that customer is NOT in the CRM yet. "
                                   . "Do not register them again: the CRM step is retried automatically, and an admin can retry it now from Orders. "
                                   . "If this is a different customer, please use a different phone number.",
                    ];
                } else {
                    // No confirmation — block
                    $crmHint = $crmId ? " (CRM ID: {$crmId})" : '';
                    return [
                        'success' => false,
                        'message' => "⚠ Phone {$mobileRaw} is already registered under: {$name}{$crmHint}. "
                                   . ($crmId
                                        ? "To add a new service for this customer use 'Existing Customer' and enter CRM ID {$crmId}."
                                        : "If this is a different customer, please use a different phone number."),
                    ];
                }
            }
            // If $failedOnly or no match — allow through silently
        }

        // ── Step 1: Resolve amount & wallet check ────────────────────────
        // 5.18.102 (Uganda): the resolved total; 0 for a Credit sale uCRM cannot price, which goes in as a lead, unquoted
        [$checkAmount, $offer, $device] = $ug !== null
            ? [self::ugAmount($ug), $ug['offer'], $ug['device'] ?: []]
            : $this->resolveAmount($post);
        if ($checkAmount === null) {
            return ['success' => false, 'message' => 'Please select a valid package/plan.'];
        }

        $packageId = (int)($post['package_choice'] ?? 0);
        // 5.18.103 (docs/72 §G.6): a direct sale carries its plan as a uCRM pair, checked above; package_choice is ''
        if ($packageId <= 0 && !($ug !== null && ($ug['entry'] ?? null) === UcrmQuoteLines::DIRECT_ENTRY)) {
            return ['success' => false, 'message' => 'Please select a service plan before submitting.'];
        }

        // Ensure quote will have actual pricing — $0 quotes are not useful
        // (5.18.102: not on Uganda — there an unpriced Credit sale is registered with no quote, docs/71 §H)
        if ($ug === null && $checkAmount <= 0 && (($post['sales_type'] ?? '') !== 'Cash')) {
            return ['success' => false, 'message' => 'Selected plan has no pricing. Please select a valid plan with pricing or contact admin to update plan prices.'];
        }

        $isCash = (($post['sales_type'] ?? '') === 'Cash');
        // Cash payment with actual amount → Regular Customer in UCRM (isLead=false)
        // Cash with $0 OR Credit sale → Lead in UCRM (isLead=true, payment not yet received)
        $isLead = !$isCash || $checkAmount <= 0;

        if ($isCash && !$this->wallet->hasSufficientBalance($retailer['id'], $checkAmount)) {
            $bal = $this->wallet->getBalance($retailer['id']);
            return [
                'success' => false,
                'message' => "Insufficient wallet balance. Required: " . dn_cur() . number_format($checkAmount, 2) .
                             ", Available: " . dn_cur() . number_format($bal, 2),
            ];
        }

        // ── Step 2: Resolve username ──────────────────────────────────────
        $manualUsername = trim($post['crm_username'] ?? '');
        if ($manualUsername !== '') {
            if (!preg_match('/^[A-Za-z0-9_\-]{3,50}$/', $manualUsername)) {
                return ['success' => false, 'message' => 'CRM Username may only contain letters, numbers, hyphens and underscores (3–50 chars).'];
            }
            if (!$this->isCrmUsernameUnique($manualUsername)) {
                return ['success' => false, 'message' => "CRM Username \"{$manualUsername}\" is already in use. Please choose another."];
            }
            $username = $manualUsername;
        } else {
            $username = $this->generateUsername($post['customer_type'] ?? 'StarLink');
        }

        // ── Step 3: POST to CRM /clients (synchronous — like Starlink plugin) ──
        // Files are available right now in $_FILES. We call CRM immediately,
        // upload files immediately, and return crm_client_id to the agent in
        // the same HTTP response. No queue, no cron, no staging needed.
        $crmPayload = [
            'clientType'     => 1,
            'isLead'         => $isLead,
            'firstName'      => $post['firstname'] ?? '',
            'lastName'       => $post['lastname'] ?? '',
            'street1'        => $post['address_1'] ?? '',
            'street2'        => $post['address_2'] ?? '',
            'city'           => ($post['customer_type'] ?? '') === 'Fiber' ? ($post['fiber_area'] ?? '') : '',
            'countryId'      => null,
            'organizationId' => 2,
            'stateId'        => null,
            'note'           => ($device['title'] ?? '') . ' / ' . ($offer['name'] ?? ''),
            'zipCode'        => '',
            'username'       => $username,
            'contacts'       => $this->buildContacts($post),
            'attributes'     => $this->buildAttributes($post, $retailer),
        ];

        // ── Fit the request to the uCRM that is connected ─────────────────
        // organizationId 2 and the nine numbered custom fields above are the
        // South Sudan uCRM's. UcrmClientTarget keeps them where they exist and
        // mean the same thing, and replaces or drops them where they do not —
        // on the Uganda uCRM organization 2 does not exist and field 1 is
        // "EFRIS TIN". See the class for the measurements.
        // 5.18.99 (Uganda): forInstall — the configured quoting organization, never "prefer 2".
        $target = UcrmClientTarget::forInstall($this->crm, is_array($cfg) ? $cfg : [], $this->gateDataDir());
        $placed = $target->apply($crmPayload);

        // ── Retry loop: if CRM rejects username as taken, auto-increment and retry ──
        $maxRetries   = 5;
        $crmResponse  = null;
        $lastError    = null;
        $crmSyncError = '';

        if ($placed['error'] !== '') {
            // Where this customer belongs could not be established, so nothing
            // is sent. Saved below as pending with the reason; the retry asks again.
            $crmSyncError = $placed['error'];
            error_log("[KycService] CRM client creation not attempted: {$crmSyncError} — saving locally for cron retry"
                . (!empty($placed['config']) ? ' (setting ucrm_quote_organization_id)' : ''));   // 5.18.99
            $maxRetries = 0;
        } else {
            $crmPayload = $placed['payload'];
        }

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            $crmPayload['username'] = $username;
            self::markSignup($this->store, is_array($cfg ?? null) ? $cfg : [], $this->dataDir, $username);   // D8, below
            $crmResponse = $this->crm->post('clients', $crmPayload);

            if ($crmResponse && !empty($crmResponse['id'])) {
                break; // Success
            }

            $lastError = $this->crm->getLastError();
            $errJson   = json_encode($lastError);

            // Only retry on "username already taken" — any other error is fatal
            $isUsernameTaken = false;
            if (isset($lastError['http_code']) && (int)$lastError['http_code'] === 422) {
                $resp = $lastError['response'] ?? [];
                $errs = $resp['errors'] ?? [];
                if (isset($errs['username']) || (function_exists('str_contains')
                    ? str_contains($errJson, 'already taken')
                    : strpos($errJson, 'already taken') !== false)) {
                    $isUsernameTaken = true;
                }
            }

            if (!$isUsernameTaken || $attempt >= $maxRetries) {
                // Non-username error or exhausted retries — save locally, sync later
                $retryNote = $attempt > 1 ? " (tried {$attempt} usernames)" : '';
                error_log("[KycService] CRM client creation failed{$retryNote}: {$errJson} — saving locally for cron retry");
                $crmSyncError = UcrmClientTarget::describe(is_array($lastError) ? $lastError : []);
                $crmResponse = null;
                break;
            }

            // Increment username and retry
            error_log("[KycService] Username '{$username}' taken in CRM — attempt {$attempt}/{$maxRetries}, generating next");
            $username = $this->generateNextUsername($username);
        }

        $crmClientId = ($crmResponse && !empty($crmResponse['id'])) ? (string)$crmResponse['id'] : null;
        $crmFailed = ($crmClientId === null);
        // Use temp ID for local storage paths when CRM client hasn't been created yet
        $storageId = $crmClientId ?: ('PENDING_' . date('YmdHis') . '_' . ($retailer['id'] ?? 0));

        // ── Step 3b: Save images to structured storage, compress, generate thumbnails ──
        $proofKey = isset($files['id_document']) ? 'id_document'
                  : (isset($files['id_proof'])   ? 'id_proof'
                  : (isset($files['doctument'])  ? 'doctument'
                  : null));

        $savedPhoto = null; $savedProof = null; $savedKit = null;

        // ── Save customer photo ───────────────────────────────────────────
        if (!empty($files['customer_image']['tmp_name']) && is_uploaded_file($files['customer_image']['tmp_name'])) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime  = finfo_file($finfo, $files['customer_image']['tmp_name']);
            finfo_close($finfo);
            $extMap = ['image/jpeg'=>'jpg','image/png'=>'png','image/gif'=>'gif','image/webp'=>'webp','application/pdf'=>'pdf'];
            if (isset($extMap[$mime])) {
                $origSize = filesize($files['customer_image']['tmp_name']);
                $sp = $this->buildStoragePath($storageId, 'photo', $extMap[$mime]);
                if (move_uploaded_file($files['customer_image']['tmp_name'], $sp['path'])) {
                    $savedPhoto = $sp['path'];
                    $this->compressImageFile($savedPhoto);
                    if (!file_exists($savedPhoto)) $savedPhoto = preg_replace('/\.[a-z]+$/', '.jpg', $savedPhoto);
                    $imgInfo   = @getimagesize($savedPhoto) ?: [0, 0];
                    $thumbPath = $this->generateThumbnail($savedPhoto);
                    $this->saveImageMeta($sp['meta_path'], [
                        'doc_type'           => 'photo',
                        'crm_client_id'      => $crmClientId,
                        'original_size_kb'   => (int)round($origSize / 1024),
                        'compressed_size_kb' => (int)round(filesize($savedPhoto) / 1024),
                        'width_px'           => $imgInfo[0],
                        'height_px'          => $imgInfo[1],
                        'mime'               => mime_content_type($savedPhoto),
                        'thumb_path'         => $thumbPath,
                    ]);
                }
            }
        }

        // ── Save ID proof ─────────────────────────────────────────────────
        if ($proofKey && !empty($files[$proofKey]['tmp_name']) && is_uploaded_file($files[$proofKey]['tmp_name'])) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime  = finfo_file($finfo, $files[$proofKey]['tmp_name']);
            finfo_close($finfo);
            $extMap = ['image/jpeg'=>'jpg','image/png'=>'png','image/gif'=>'gif','image/webp'=>'webp','application/pdf'=>'pdf'];
            if (isset($extMap[$mime])) {
                $origSize = filesize($files[$proofKey]['tmp_name']);
                $sp = $this->buildStoragePath($storageId, 'id_proof', $extMap[$mime]);
                if (move_uploaded_file($files[$proofKey]['tmp_name'], $sp['path'])) {
                    $savedProof = $sp['path'];
                    if ($mime !== 'application/pdf') {
                        $this->compressImageFile($savedProof);
                        if (!file_exists($savedProof)) $savedProof = preg_replace('/\.[a-z]+$/', '.jpg', $savedProof);
                    }
                    $this->saveImageMeta($sp['meta_path'], [
                        'doc_type'           => 'id_proof',
                        'crm_client_id'      => $crmClientId,
                        'original_size_kb'   => (int)round($origSize / 1024),
                        'compressed_size_kb' => (int)round(filesize($savedProof) / 1024),
                        'mime'               => mime_content_type($savedProof),
                    ]);
                }
            }
        }

        // ── Save kit label image ──────────────────────────────────────────
        $savedKit = null;
        if (!empty($files['kit_image']['tmp_name']) && is_uploaded_file($files['kit_image']['tmp_name'])) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime  = finfo_file($finfo, $files['kit_image']['tmp_name']);
            finfo_close($finfo);
            $extMap = ['image/jpeg'=>'jpg','image/png'=>'png','image/gif'=>'gif','image/webp'=>'webp'];
            if (isset($extMap[$mime])) {
                $origSize = filesize($files['kit_image']['tmp_name']);
                $sp = $this->buildStoragePath($storageId, 'kit_label', $extMap[$mime]);
                if (move_uploaded_file($files['kit_image']['tmp_name'], $sp['path'])) {
                    $savedKit = $sp['path'];
                    $this->compressImageFile($savedKit, 1600, 85); // higher quality for OCR readability
                    if (!file_exists($savedKit)) $savedKit = preg_replace('/\.[a-z]+$/', '.jpg', $savedKit);
                    $this->saveImageMeta($sp['meta_path'], [
                        'doc_type'           => 'kit_label',
                        'crm_client_id'      => $crmClientId,
                        'original_size_kb'   => (int)round($origSize / 1024),
                        'compressed_size_kb' => (int)round(filesize($savedKit) / 1024),
                        'mime'               => mime_content_type($savedKit),
                    ]);
                }
            }
        }

        // ── Step 4: Photos stored locally only (CRM upload disabled) ──────
        // Photos are saved in data/kyc_photos/{crm_client_id}/ with thumbnails
        // and metadata JSON. No upload to CRM — saves bandwidth and storage.
        $photoUploaded = false;
        $idUploaded    = false;
        $kitLabel      = 'Starlink Kit Label';
        $kitSn         = trim($post['kitName'] ?? '');
        if ($kitSn !== '') $kitLabel .= ' — ' . $kitSn;

        // Mark as "uploaded" (locally stored) so UI shows success
        if ($savedPhoto && file_exists($savedPhoto)) {
            $photoUploaded = true;
            $sp = $this->buildStoragePath($storageId, 'photo', 'jpg');
            if (file_exists($sp['meta_path'])) {
                $m = json_decode(file_get_contents($sp['meta_path']), true) ?? [];
                $m['stored_locally'] = true;
                $m['stored_at'] = date('Y-m-d H:i:s');
                file_put_contents($sp['meta_path'], json_encode($m, JSON_PRETTY_PRINT));
            }
        }

        // ── Step 5: ID proof stored locally ───────────────────────────────
        if ($savedProof && file_exists($savedProof)) {
            $idUploaded = true;
            $sp = $this->buildStoragePath($storageId, 'id', pathinfo($savedProof, PATHINFO_EXTENSION));
            if (file_exists($sp['meta_path'])) {
                $m = json_decode(file_get_contents($sp['meta_path']), true) ?? [];
                $m['stored_locally'] = true;
                $m['stored_at'] = date('Y-m-d H:i:s');
                file_put_contents($sp['meta_path'], json_encode($m, JSON_PRETTY_PRINT));
            }
        }

        // ── Step 5b: Kit label stored locally ─────────────────────────────
        $kitImageUploaded = false;
        if ($savedKit && file_exists($savedKit)) {
            $kitImageUploaded = true;
            $sp = $this->buildStoragePath($storageId, 'kit', pathinfo($savedKit, PATHINFO_EXTENSION));
            if (file_exists($sp['meta_path'])) {
                $m = json_decode(file_get_contents($sp['meta_path']), true) ?? [];
                $m['stored_locally'] = true;
                $m['stored_at'] = date('Y-m-d H:i:s');
                file_put_contents($sp['meta_path'], json_encode($m, JSON_PRETTY_PRINT));
            }
        }

        // ── No retry queue needed — photos stored locally ────────────────
        // (Retry queue disabled since photos are not uploaded to CRM)

        // ── Step 6: Generate Work Order document (template-based, no file) ─
        // Template ids 2 and 3 are the South Sudan uCRM's; elsewhere they are
        // some other document or none, so they are only used on that layout.
        if ($crmClientId && !$target->legacyLayout()) {
            error_log("[KycService] Work order and tag skipped for CRM #{$crmClientId}: this uCRM is not laid out like the South Sudan one");
        }
        if ($crmClientId && $target->legacyLayout()) {
        $tplId = ($connectivity === 'New Connection') ? self::TPL_WORK_ORDER_NEW : self::TPL_WORK_ORDER_OTHER;
        $this->crm->post('documents', [
            'clientId'   => (int)$crmClientId,
            'name'       => 'Work Order',
            'templateId' => $tplId,
        ]);
        }

        // ── Step 6.5: Create Quote/Proforma in UCRM ─────────────────────
        // Mirrors what POS apps do: auto-generate a quote immediately on
        // registration so customer receives a proforma via UCRM email.
        // Quote = package monthly fee + device/kit price (if any).
        // Controlled by kyc_config.json → kyc_auto_quote_enabled (default: true)
        $autoQuoteEnabled  = ($cfg['kyc_auto_quote_enabled'] ?? true) !== false;
        $quoteValidityDays = max(1, (int)($cfg['kyc_quote_validity_days'] ?? self::QUOTE_MATURITY_DAYS));
        // ── Build quote items with UCRM productId when available ──
        // One builder for the form and the uCRM retry (KycCrmSync), so a
        // customer whose create had to be retried is quoted the same lines.
        $hwCart = !empty($post['hw_cart_json']) ? json_decode($post['hw_cart_json'], true) : [];
        $quoteItems = $ug !== null
            ? ($ug['status'] === 'priced' ? $ug['lines'] : [])   // 5.18.102 (Uganda): uCRM's lines, none when unpriced
            : self::quoteItems($offer ?: null, is_array($hwCart) ? $hwCart : [], $device ?: null,
                (int)($post['kitQty'] ?? 1), (string)($post['customer_type'] ?? ''), $cfg);
        $quoteCreated = false;
        $quoteRefused = '';   // 5.18.99: the text when the Uganda quote check refused the quotation
        $quoteId      = null;
        $qSeq         = null;
        $qRef         = null;
        $qPrefix      = '';
        $qSeqStart    = 0;
        $qNotesPrefix = '';
        // Max-amount guard: if cart total exceeds threshold, skip auto-quote
        // (agent will generate the quotation manually for non-standard hardware bundles)
        $ugOverMax = false;   // 5.18.102: on Uganda the suppression is recorded (P3), not only logged
        if ($autoQuoteEnabled && self::quoteOverAutoMax($quoteItems, $cfg)) {
            $autoQuoteEnabled = false; // suppress — too complex for auto-quote
            $ugOverMax = true;
            error_log("KycService: auto-quote suppressed for CRM #{$crmClientId} — the quote is over kyc_auto_quote_max_amount");
        }
        if ($autoQuoteEnabled && !empty($quoteItems)) {
            // ── B-03 FIX: quote_seq is now computed atomically inside saveApplication()'s
            // withLock. We only prepare the prefix/start/year here. The actual
            // $qSeq and $qRef are populated AFTER saveApplication() returns,
            // then we POST the quote to UCRM with the guaranteed-unique ref.
            $qPrefix      = trim($cfg['quote_prefix']     ?? 'QUO');
            $qSeqStart    = max(0, (int)($cfg['quote_seq_start'] ?? 0));
            $qNotesPrefix = trim($cfg['kyc_quote_notes_prefix'] ?? '');
        }

        // ── Step 7a: Auto-create payment in UCRM for Cash sales ─────────────
        // Agent already collected cash. Post immediately as account credit.
        // Invoice will be created later (after installation) — UCRM will
        // auto-apply this credit to it when the invoice is issued.
        $paymentCreated = false;
        $paymentId      = null;
        $autoPayEnabled = ($cfg['kyc_auto_payment_enabled'] ?? true) !== false;

        if ($autoPayEnabled && $isCash && $checkAmount > 0 && $crmClientId) {
            // Resolve to UCRM UUID — UCRM rejects slugs/integers with HTTP 422
            if (!class_exists('PaymentUuids')) {
                require_once __DIR__ . '/PaymentUuids.php';
            }
            $ucrm_method = PaymentUuids::resolve($post['sales_type'] ?? 'Cash');

            // Unique reference for duplicate detection
            $uniqueRef = 'KYC-' . $crmClientId;

            // Post as credit — no invoiceId needed. UCRM holds it as
            // account balance and auto-applies when invoice is created.
            $paymentPayload = [
                'clientId'     => (int)$crmClientId,
                'amount'       => (float)$checkAmount,
                'currencyCode' => dn_payload_currency('', $cfg ?? null),
                'methodId'     => $ucrm_method,
                'note'         => 'Cash collected at registration — DishNet Sales Hub'
                                . ' | Agent: ' . ($retailer['name'] ?? '')
                                . ' | ' . $connectivity
                                . ' | Ref: ' . $uniqueRef,
            ];
            
            // ── DUPLICATE PREVENTION: Check UCRM before creating ──
            $payResult = $this->crm->createPaymentSafe($paymentPayload, $uniqueRef);
            if (!empty($payResult['success']) && !empty($payResult['id'])) {
                $paymentId      = $payResult['id'];
                $paymentCreated = true;
                if (!empty($payResult['duplicate'])) {
                    error_log("[KycService] Payment already existed for KYC-{$crmClientId}, using existing ID: {$paymentId}");
                }
            }
        }

        // ── Step 7: Add CRM tag ───────────────────────────────────────────
        // Tag ids 52–54 are the South Sudan uCRM's too (see Step 6).
        if ($crmClientId && $target->legacyLayout()) {
        $tagMap = ['Ownership Change'=>self::TAG_OWNERSHIP_CHANGE,'Shifting Connection'=>self::TAG_SHIFTING];
        $tagId = $tagMap[$connectivity] ?? self::TAG_NEW_CONNECTION;
        $this->crm->patch("clients/{$crmClientId}/add-tag/{$tagId}");
        }

        // ── Step 7b: Persist new area if not in built-in list ───────────────
        if (($post['customer_type'] ?? '') === 'Fiber' && !empty($post['fiber_area'])) {
            $submittedArea = trim($post['fiber_area']);
            $builtInAreas  = \SplynxTicketService::getJubaAreas();
            if (!in_array($submittedArea, $builtInAreas, true)) {
                // New area — add to custom areas store if not already there
                $customAreas = $this->store->load('kyc_custom_areas.json') ?? [];
                $exists = array_search($submittedArea, array_column($customAreas, 'name'));
                if ($exists === false) {
                    $customAreas[] = [
                        'name'       => $submittedArea,
                        'added_by'   => $retailer['name'] ?? 'Unknown',
                        'added_at'   => date('Y-m-d H:i:s'),
                        'use_count'  => 1,
                    ];
                } else {
                    $customAreas[$exists]['use_count'] = ($customAreas[$exists]['use_count'] ?? 0) + 1;
                }
                $this->store->save('kyc_custom_areas.json', $customAreas);
            }
        }

        // ── Step 8: Save application locally ─────────────────────────────
        // 5.18.102 (Uganda, docs/71 §F.3): with the resolved lines merged in (linesRecord), where the gate is on.
        $appId = $this->saveApplication(array_merge([
            'connectivity_type' => $connectivity,
            'customer_type'     => $post['customer_type'] ?? '',
            'date'              => $post['date'] ?? date('Y-m-d'),
            'crm_client_id'     => $crmClientId,   // null if CRM failed
            'crm_sync_status'   => $crmFailed ? 'pending' : 'synced',
            'crm_sync_payload'  => $crmFailed ? json_encode($crmPayload) : null,
            'crm_sync_error'    => $crmFailed ? ($crmSyncError !== '' ? $crmSyncError : 'uCRM gave no answer') : null,
            'quote_items'       => $crmFailed ? $quoteItems : null,   // what the retry quotes (KycCrmSync)
            'firstname'         => $post['firstname'] ?? '',
            'lastname'          => $post['lastname'] ?? '',
            'mobile'            => $post['mobile'] ?? '',
            'email'             => $post['email'] ?? '',
            'contacts'          => $this->buildContacts($post),
            'address_1'         => $post['address_1'] ?? '',
            'address_2'         => $post['address_2'] ?? '',
            'device_id'         => $post['device_id'] ?? '',
            'device_title'      => $device['title'] ?? null,
            'device_price'      => $device['price'] ?? null,
            'package_choice'    => $post['package_choice'] ?? '',
            'offer_name'        => $offer['name'] ?? null,
            'offer_price'       => $offer['customer_price'] ?? $offer['amount'] ?? null,
            'kitName'           => $post['kitName'] ?? '',
            'kitQty'            => $post['kitQty'] ?? '1',
            'kitUnit'           => $post['kitUnit'] ?? '',
            'kitNumber'         => $post['kitNumber'] ?? '',
            'accessories'       => json_encode($post['accessories'] ?? []),
            'priority'          => $post['priority'] ?? 'Low',
            'sales_person'      => $salesPerson,
            'sales_type'        => $post['sales_type'] ?? 'Cash',
            'ref'               => $post['ref'] ?? '',
            'latitude'          => $post['latitude'] ?? '',
            'longitude'         => $post['longitude'] ?? '',
            'fiber_area'        => ($post['customer_type'] ?? '') === 'Fiber' ? ($post['fiber_area'] ?? '') : '',
            'username'          => $username,
            'retailer_id'       => $retailer['id'],
            'retailer_name'     => $retailer['name'],
            'status'            => 'new',
            'amount_charged'    => $isCash ? $checkAmount : 0,
            'is_lead'           => $isLead,
            'crm_client_type'   => $isLead ? 'lead' : 'regular',
            'hw_cart_json'      => $post['hw_cart_json'] ?? null,
            // B-03 FIX: quote_seq/quote_ref computed atomically inside saveApplication().
            // Pass sentinel fields so the withLock callback can assign them race-free.
            '_quote_prefix'     => ($autoQuoteEnabled && !empty($quoteItems)) ? $qPrefix : null,
            '_quote_seq_start'  => ($autoQuoteEnabled && !empty($quoteItems)) ? $qSeqStart : null,
            '_quote_year'       => date('Y'),
            'quote_id'          => null,         // filled after quote is POSTed below
            'quote_seq'         => null,         // filled by saveApplication() lock
            'quote_ref'         => null,         // filled by saveApplication() lock
            'quote_created'     => false,        // updated below after UCRM POST
            'payment_id'        => $paymentId,
            'payment_created'   => $paymentCreated,
            'photo_uploaded'    => $photoUploaded,
            'id_uploaded'       => $idUploaded,
            'kit_image_uploaded'=> $kitImageUploaded,
        ], $ug !== null ? self::linesRecord($ug, $post, $connectivity) : []));   // 5.18.102 (Uganda): docs/71 §F.3

        // ── Step 8b: Now that appId + atomic quote_seq are assigned, POST quote to UCRM ──
        if ($ug !== null && $crmClientId) {
            // 5.18.102 (Uganda, docs/71 §F.2 steps 3–5): the company check, "Prepared by", then uCRM's lines or why not
            $ugQ = $this->ugQuoteNew($ug, $ugOrg, $target, is_array($crmResponse) ? $crmResponse : null, (int)$appId,
                (int)$crmClientId, is_array($cfg) ? $cfg : [], $post, $salesPerson, $connectivity, $ugOverMax);
            $quoteCreated = $ugQ['created'];
            $quoteId      = $ugQ['id'];
            if ($ugQ['ref'] !== '') $qRef = $ugQ['ref'];
            $quoteRefused = $ugQ['refused'];
        } elseif ($autoQuoteEnabled && !empty($quoteItems) && $crmClientId) {
            error_log("[KycService] Auto-quote ENABLED for CRM #{$crmClientId} — " . count($quoteItems) . " items, total: $" . array_sum(array_map(fn($i) => (float)($i['price'] ?? 0) * (int)($i['quantity'] ?? 1), $quoteItems)));
            $savedApp = $this->store->findOne('kyc_applications.json', 'id', $appId);
            $qRef     = $savedApp['quote_ref'] ?? '';

            // v4.9.18 FIX #3: gate on qRef instead of qSeq (quote_seq is always 0 now)
            if ($qRef !== '' && $qRef !== null) {
                // Made, sent and handed to WhatsApp by postQuote(), which the
                // uCRM retry (KycCrmSync) calls too: one way to quote.
                $autoNotes = $qRef . ' | Auto-generated on KYC registration. Sales: ' . $salesPerson
                           . ' | Connection: ' . $connectivity
                           . ' | Priority: ' . ($post['priority'] ?? 'Medium');
                // 5.18.99 (Uganda): what the create told us — the client's organization and the organizations read —
                // so the quote check needs no request when uCRM's answer already says where the client is filed.
                $quoteKnown = null;
                if (QuoteOrganization::setting(is_array($cfg) ? $cfg : [], $this->gateDataDir())['on']) {
                    $quoteKnown = [
                        'organizationId' => is_int($crmResponse['organizationId'] ?? null) ? $crmResponse['organizationId'] : null,
                        'organizations'  => $target->organizationRows(),
                        'where'          => 'kyc-new',
                    ];
                }
                $posted = self::postQuote($this->crm, $this->store, (int)$appId, (int)$crmClientId, $quoteItems,
                    ($qNotesPrefix ? $qNotesPrefix . "\n" : '') . $autoNotes, 'Plugin ref: ' . $qRef,
                    (string)($post['mobile'] ?? ''), $quoteKnown);
                if ($posted['created']) {
                    $quoteId      = $posted['id'];
                    $quoteCreated = true;
                    if ($posted['ref'] !== '') $qRef = $posted['ref'];
                } elseif (!empty($posted['refused'])) {
                    $quoteRefused = (string)$posted['refused'];   // 5.18.99
                }
            } else {
                error_log("[KycService] Quote BLOCKED — quote_ref is empty for app #{$appId}. savedApp keys: " . implode(',', array_keys($savedApp ?? [])));
                $this->store->updateOne('kyc_applications.json', 'id', $appId, [
                    'quote_debug' => 'quote_ref was empty — saveApplication may not have set _quote_prefix. qRef="' . ($qRef ?? 'NULL') . '"',
                ]);
            }
        } else {
            error_log("[KycService] Auto-quote SKIPPED for CRM #{$crmClientId} — enabled=" . ($autoQuoteEnabled ? 'yes' : 'no') . " items=" . count($quoteItems));
            // Save debug info to help troubleshoot
            $this->store->updateOne('kyc_applications.json', 'id', $appId, [
                'quote_debug' => 'Quote skipped: enabled=' . ($autoQuoteEnabled ? 'yes' : 'no')
                              . ', items=' . count($quoteItems)
                              . ', crmId=' . ($crmClientId ?: 'NONE')
                              . ', offer=' . ($offer ? ($offer['name'] ?? 'unnamed') . ' $' . ($offer['customer_price'] ?? '0') : 'NULL'),
            ]);
        }

        // ── Step 9: Wallet debit + passbook ─────────────────────────────
        // v4.11.3: Wallet debited regardless of CRM outcome — staff collected real money.
        if ($isCash && $checkAmount > 0) {
            $_debitLabel = $crmClientId
                ? "KYC – CRM client #{$crmClientId} ({$post['firstname']} {$post['lastname']})"
                : "KYC – {$post['firstname']} {$post['lastname']} (CRM pending)";
            $_debitRef = $crmClientId ? "KYC-{$crmClientId}" : "KYC-APP-{$appId}";
            $this->wallet->debit(
                $retailer['id'],
                $checkAmount,
                $_debitLabel,
                $_debitRef,
                $appId,
                $crmClientId ?: ''
            );

            // ── Step 9a: Write to payment_collections so Collections tab shows it ─
            $customerFullName = trim(($post['firstname'] ?? '') . ' ' . ($post['lastname'] ?? ''));
            $svcType = strtolower($post['customer_type'] ?? $post['connectivity_type'] ?? 'starlink');
            $_kycColRecord = $this->store->appendWithId('payment_collections.json', [
                'retailer_id'       => $retailer['id'],
                'retailer_name'     => $retailer['name'] ?? '',
                'customer_name'     => $customerFullName,
                'crm_customer_id'   => (string)($crmClientId ?: ''),
                'amount'            => $checkAmount,
                'method'            => 'Cash',
                'service_type'      => $svcType,
                'note'              => 'KYC Registration — ' . ($post['connectivity_type'] ?? 'New Connection'),
                'source'            => 'kyc_cash_sale',
                'kyc_app_id'        => $appId,
                'crm_payment_id'    => $paymentId ?: null,
                'crm_synced'        => $paymentCreated,
                'commission'        => 0,
                'comm_rate'         => 0,
                'created_at'        => date('Y-m-d H:i:s'),
            ]);
            // Dual-write: staff_ledger
            require_once dirname(__FILE__) . '/StaffLedgerWriter.php';
            StaffLedgerWriter::onCollection($this->store->getPdo(), array_merge($_kycColRecord, [
                'client_name' => $customerFullName,
                'collected_at' => date('Y-m-d H:i:s'),
            ]));

            // ── Step 9b: Cashbook entry for STAFF members ────────────────────
            // Staff (company employees) collect cash that must be handed over.
            // Independent retailers (dealers) use their own wallet funds.
            if ($this->isStaffRetailer($retailer)) {
                $this->createCashbookEntry($retailer, $checkAmount, $crmClientId ?: $appId, $post);
            }
        }

        if ($crmFailed) {
            // Saved here, NOT in the CRM. Said plainly: this line used to read as
            // a success while every create was being refused.
            $successMsg = "Customer saved in the plugin, but NOT in the CRM yet"
                        . ($crmSyncError !== '' ? " ({$crmSyncError})" : '')
                        . (!empty($placed['config'])   // 5.18.99: a retry cannot fix the company setting
                            ? ". Once that is fixed it goes into the CRM at the next automatic retry. Automatic retries stop about two and a half days after this; then an admin retries it from Orders. Username: {$username}."
                            : ". It is retried automatically, and an admin can retry it now from Orders. Username: {$username}.");
        } else {
            $successMsg = "Customer registered! CRM ID: {$crmClientId}. Username: {$username}.";
            if ($quoteCreated)   $successMsg .= " Quote #{$quoteId} created.";
            if ($quoteRefused !== '') $successMsg .= ' ' . $quoteRefused;   // 5.18.99 (Uganda): no quote, and why
            if ($paymentCreated) $successMsg .= " Payment #{$paymentId} posted to UCRM.";
            elseif ($isCash && $checkAmount > 0) $successMsg .= " (Payment could not be auto-posted — add manually in UCRM.)";
        }

        // Photo upload status — agent must know if they need to re-upload manually
        $hadPhoto = !empty($files['customer_image']['tmp_name']) || $savedPhoto;
        $hadId    = !empty($files[$proofKey ?? '']['tmp_name'] ?? '') || $savedProof;
        $hadKit   = $savedKit !== null || !empty($files['kit_image']['tmp_name']);
        if ($hadPhoto && !$photoUploaded) $successMsg .= " ⚠ Customer photo upload failed — please add manually in UCRM Files tab.";
        if ($hadId    && !$idUploaded)   $successMsg .= " ⚠ ID proof upload failed — please add manually in UCRM Files tab.";
        if ($hadKit   && !$kitImageUploaded) $successMsg .= " ⚠ Kit label photo upload failed — saved locally, will retry.";

        return self::ugWithInstall($ug, $post, $connectivity, [   // 5.18.102 (Uganda): who installs, for the staff messages
            'success' => true,
            'message' => $successMsg,
            'data'    => [
                'crm_client_id'      => $crmClientId,
                'crm_sync_status'    => $crmFailed ? 'pending' : 'synced',
                'crm_sync_error'     => $crmFailed ? $crmSyncError : null,
                'application_id'     => $appId,
                'username'           => $username,
                'amount_charged'     => $isCash ? $checkAmount : 0,
                'status'             => 'new',
                'quote_id'           => $quoteId,
                'quote_created'      => $quoteCreated,
                'payment_id'         => $paymentId,
                'payment_created'    => $paymentCreated,
                'photo_uploaded'     => $photoUploaded,
                'id_uploaded'        => $idUploaded,
                'kit_image_uploaded' => $kitImageUploaded,
            ] + ($quoteRefused !== '' ? ['quote_refused' => $quoteRefused] : []),   // 5.18.99: only when refused
        ]);
    }

    // ══════════════════════════════════════════════════════════════════════
    // HELPERS
    // ══════════════════════════════════════════════════════════════════════

    // ── REQ-02: PHP GD backend compression (safety net) ──────────────────
    /**
     * Compress an image file in-place using PHP GD.
     * Scales to $maxPx on longest side, saves as JPEG at $quality.
     * PDFs and unsupported types are skipped (returns false).
     */
    private function compressImageFile(string $filePath, int $maxPx = 1280, int $quality = 82): bool
    {
        if (!function_exists('imagecreatefromjpeg')) return false;
        $mime = mime_content_type($filePath);
        $creators = [
            'image/jpeg' => 'imagecreatefromjpeg',
            'image/png'  => 'imagecreatefrompng',
            'image/webp' => 'imagecreatefromwebp',
            'image/gif'  => 'imagecreatefromgif',
        ];
        if (!isset($creators[$mime])) return false;
        $src = $creators[$mime]($filePath);
        if (!$src) return false;
        $origW = imagesx($src); $origH = imagesy($src);
        if ($origW <= $maxPx && $origH <= $maxPx) {
            $newW = $origW; $newH = $origH;
        } elseif ($origW >= $origH) {
            $newW = $maxPx; $newH = (int)round($origH * $maxPx / $origW);
        } else {
            $newH = $maxPx; $newW = (int)round($origW * $maxPx / $origH);
        }
        $dst = imagecreatetruecolor($newW, $newH);
        if ($mime === 'image/png') {
            imagealphablending($dst, false); imagesavealpha($dst, true);
            $transparent = imagecolorallocatealpha($dst, 255, 255, 255, 127);
            imagefilledrectangle($dst, 0, 0, $newW, $newH, $transparent);
        }
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $newW, $newH, $origW, $origH);
        imagedestroy($src);
        $newPath = preg_replace('/\.[a-zA-Z0-9]+$/', '.jpg', $filePath);
        $ok = imagejpeg($dst, $newPath, $quality);
        imagedestroy($dst);
        if ($ok && $newPath !== $filePath) @unlink($filePath);
        return $ok;
    }

    // ── REQ-07: Admin review thumbnail ───────────────────────────────────
    /**
     * Generate a 120px thumbnail saved as {filename}_thumb.jpg.
     * Kept permanently even after CRM upload for admin review.
     */
    private function generateThumbnail(string $filePath, int $thumbPx = 120): ?string
    {
        if (!function_exists('imagecreatefromjpeg')) return null;
        $mime = mime_content_type($filePath);
        $creators = [
            'image/jpeg'=>'imagecreatefromjpeg','image/png'=>'imagecreatefrompng',
            'image/webp'=>'imagecreatefromwebp','image/gif'=>'imagecreatefromgif',
        ];
        if (!isset($creators[$mime])) return null;
        $src = $creators[$mime]($filePath);
        if (!$src) return null;
        $w = imagesx($src); $h = imagesy($src);
        $scale = min($thumbPx / $w, $thumbPx / $h);
        $tw = (int)round($w * $scale); $th = (int)round($h * $scale);
        $dst = imagecreatetruecolor($tw, $th);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $tw, $th, $w, $h);
        imagedestroy($src);
        $thumbPath = preg_replace('/\.[a-zA-Z]+$/', '', $filePath) . '_thumb.jpg';
        imagejpeg($dst, $thumbPath, 75);
        imagedestroy($dst);
        return $thumbPath;
    }

    // ── REQ-03: Structured storage paths ─────────────────────────────────
    /**
     * Build structured path: data/kyc_uploads/YYYY-MM/crm-{id}/{docType}.{ext}
     * Returns ['dir', 'path', 'meta_path'].
     */
    private function buildStoragePath(string $crmClientId, string $docType, string $ext): array
    {
        $base  = rtrim($this->dataDir, '/') . '/kyc_uploads';
        $month = date('Y-m');
        $dir   = "{$base}/{$month}/crm-{$crmClientId}";
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        $names     = ['photo' => 'photo', 'id_proof' => 'id_proof', 'kit_label' => 'kit_label'];
        $baseName  = $names[$docType] ?? $docType;
        $path      = "{$dir}/{$baseName}.{$ext}";
        $metaPath  = "{$dir}/{$baseName}_meta.json";
        return ['dir' => $dir, 'path' => $path, 'meta_path' => $metaPath];
    }

    // ── REQ-04: Metadata sidecar ──────────────────────────────────────────
    /**
     * Write JSON sidecar with image metadata alongside the file.
     */
    private function saveImageMeta(string $metaPath, array $data): void
    {
        $meta = array_merge([
            'saved_at'           => date('Y-m-d H:i:s'),
            'original_size_kb'   => 0,
            'compressed_size_kb' => 0,
            'width_px'           => 0,
            'height_px'          => 0,
            'mime'               => '',
            'crm_uploaded'       => false,
            'crm_uploaded_at'    => null,
            'retry_count'        => 0,
        ], $data);
        file_put_contents($metaPath, json_encode($meta, JSON_PRETTY_PRINT));
    }

    private function resolveAmount(array $post): array
    {
        $customerType = $post['customer_type'] ?? '';
        $packageId    = (int)($post['package_choice'] ?? 0);

        // Look up plan — check subscription_plans.json first (new wizard form),
        // fall back to kyc_packages.json (old form / legacy submissions).
        $offer = $this->store->findOne('subscription_plans.json', 'id', $packageId);
        if (!$offer) {
            $offer = $this->store->findOne('kyc_packages.json', 'id', $packageId);
        }

        $device = $this->store->findOne('kyc_devices.json', 'id', (int)($post['device_id'] ?? 0));

        if (!$offer) return [null, null, null];

        if ($customerType === 'Fiber') {
            // HIGH-03 FIX: Installation fee is now configurable in Settings (fiber_install_fee).
            // Defaults to $100 if not set, so existing behaviour is preserved.
            // This avoids a code deploy every time the fee changes.
            $config      = $this->store->load('kyc_config.json');
            $installFee  = (float)($config['fiber_install_fee'] ?? 100);
            // v4.11.3 FIX: Include device/router price in cash collection amount
            // Previously: only offer_price + installFee ($150), missing router ($50 = $200 total)
            $devicePrice = (float)preg_replace('/[^0-9.]/', '', $device['price'] ?? '0');
            $amount      = (float)($offer['customer_price'] ?? $offer['amount'] ?? 50) + $installFee + $devicePrice;
            return [$amount, $offer, $device ?: []];
        }

        // StarLink: package amount + device price
        $devicePrice = (float)preg_replace('/[^0-9.]/', '', $device['price'] ?? '0');
        $amount      = (float)($offer['customer_price'] ?? $offer['amount'] ?? 0) + $devicePrice;

        return [$amount, $offer, $device ?: []];
    }

    /**
     * Build UCRM contacts array from form POST data.
     * Supports multi-contact form (contacts[0][name], contacts[0][phone], etc.)
     * Falls back to legacy single email/mobile fields for backward compatibility.
     *
     * @return array  Array of ['name'=>..., 'email'=>..., 'phone'=>...] objects
     */
    private function buildContacts(array $post): array
    {
        $contacts = [];

        // New multi-contact form: contacts[0][name], contacts[0][phone], contacts[0][email]
        if (!empty($post['contacts']) && is_array($post['contacts'])) {
            foreach ($post['contacts'] as $c) {
                if (!is_array($c)) continue;
                $name  = trim($c['name']  ?? '');
                $phone = trim($c['phone'] ?? '');
                $email = trim($c['email'] ?? '');
                // Skip completely empty rows
                if ($name === '' && $phone === '' && $email === '') continue;
                // If name empty, fall back to first name from main form
                if ($name === '') $name = trim($post['firstname'] ?? '');
                $contacts[] = [
                    'name'  => $name,
                    'email' => $email,
                    'phone' => $phone,
                ];
            }
        }

        // Fallback: legacy single email/mobile fields (backward compat)
        if (empty($contacts)) {
            $contacts[] = [
                'name'  => trim($post['firstname'] ?? ''),
                'email' => trim($post['email'] ?? ''),
                'phone' => trim($post['mobile'] ?? ''),
            ];
        }

        return $contacts;
    }

    private function buildAttributes(array $post, array $retailer = []): array
    {
        $salesPerson = $this->resolveSalesPerson($post, $retailer);
        return [
            ['value' => $post['priority'] ?? '',       'customAttributeId' => self::ATTR_PRIORITY],
            ['value' => $salesPerson,                  'customAttributeId' => self::ATTR_SALES_PERSON],
            ['value' => $post['ref'] ?? '',            'customAttributeId' => self::ATTR_REF],
            ['value' => $post['device_id'] ?? '',      'customAttributeId' => self::ATTR_DEVICE_ID],
            ['value' => $post['package_choice'] ?? '', 'customAttributeId' => self::ATTR_PACKAGE],
            ['value' => $post['kitNumber'] ?? '',      'customAttributeId' => self::ATTR_KIT_NUMBER],
            ['value' => $post['kitQty'] ?? '',         'customAttributeId' => self::ATTR_KIT_QTY],
            ['value' => $post['kitUnit'] ?? '',        'customAttributeId' => self::ATTR_KIT_UNIT],
            ['value' => $post['kitName'] ?? '',        'customAttributeId' => self::ATTR_KIT_NAME],
        ];
    }

    private function resolveSalesPerson(array $post, array $retailer = []): string
    {
        $sp = trim($post['sales_person'] ?? '');
        if ($sp === 'Other') $sp = trim($post['sales_person_other'] ?? '');
        // If still empty (agent didn't manually select / FTTH form), use the
        // logged-in retailer's name — this ensures Sales Person in UCRM always
        // matches the agent who submitted the KYC, not the form dropdown value.
        if ($sp === '' && !empty($retailer['name'])) {
            $sp = trim($retailer['name']);
        }
        return $sp;
    }

    // ──────────────────────────────────────────────────────────────────────
    // CRM USERNAME MANAGEMENT
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Check if a given CRM username is not yet used in local application records.
     * (CRM-side uniqueness is enforced by the CRM itself; this is a local pre-check.)
     */
    public function isCrmUsernameUnique(string $username): bool
    {
        // I-03 FIX: Use findOne() (O(log n) indexed lookup) instead of
        // loading all applications and doing a PHP foreach scan.
        // findOne does a case-sensitive match; we normalise to lowercase first.
        $found = $this->store->findOne('kyc_applications.json', 'username', strtolower($username));
        if ($found !== null) return false;
        // Also check original-case in case old records were stored mixed-case
        $found2 = $this->store->findOne('kyc_applications.json', 'username', $username);
        return $found2 === null;
    }

    /**
     * Update CRM username for an existing (not yet provisioned) application.
     * Returns ['success'=>bool, 'message'=>string].
     */
    public function updateCrmUsername(int $appId, string $newUsername, array $admin): array
    {
        $apps  = $this->store->load('kyc_applications.json');
        $found = null;
        foreach ($apps as $a) {
            if ((int)($a['id'] ?? 0) === $appId) { $found = $a; break; }
        }

        if (!$found) {
            return ['success' => false, 'message' => 'Application not found.'];
        }
        if (($found['status'] ?? '') === 'updated') {
            return ['success' => false, 'message' => 'Cannot change username after CRM provisioning.'];
        }

        $newUsername = trim($newUsername);
        if (!preg_match('/^[A-Za-z0-9_\-]{3,50}$/', $newUsername)) {
            return ['success' => false, 'message' => 'Invalid username format.'];
        }

        // Uniqueness check (exclude current app)
        foreach ($apps as $a) {
            if ((int)($a['id'] ?? 0) !== $appId && strcasecmp(trim($a['username'] ?? ''), $newUsername) === 0) {
                return ['success' => false, 'message' => "Username \"{$newUsername}\" is already in use."];
            }
        }

        $oldUsername = $found['username'] ?? '';
        $this->store->updateOne('kyc_applications.json', 'id', $appId, [
            'username'    => $newUsername,
            'updated_at'  => date('Y-m-d H:i:s'),
        ]);

        // Log the change
        $logEntry = [
            'id'         => $this->store->nextId('activity_log.json'),
            'event'      => 'crm_username_changed',
            'actor'      => $admin['name'],
            'detail'     => "App #{$appId}: CRM username changed from \"{$oldUsername}\" to \"{$newUsername}\"",
            'ref_id'     => $appId,
            'created_at' => date('Y-m-d H:i:s'),
        ];
        $this->store->append('activity_log.json', $logEntry);

        return ['success' => true, 'message' => "CRM username updated to \"{$newUsername}\" successfully."];
    }

    private function generateUsername(string $customerType): string
    {
        $apps     = $this->store->load('kyc_applications.json');
        $isFiber  = ($customerType === 'Fiber');
        $filtered = array_filter($apps, fn($a) => $isFiber
            ? (($a['customer_type'] ?? '') === 'Fiber')
            : (($a['customer_type'] ?? '') !== 'Fiber')
        );
        $maxSeq = empty($filtered) ? 0 : max(array_map(fn($a) => (int)($a['username_seq'] ?? 0), $filtered));

        // Respect configurable start sequence so new installs continue from the
        // old system's last number (e.g. STAR000051 → next = 52, FTTH000237 → next = 238)
        $cfg      = $this->store->load('kyc_config.json') ?? [];
        $seqStart = $isFiber
            ? max(0, (int)($cfg['ftth_seq_start'] ?? 0))
            : max(0, (int)($cfg['star_seq_start'] ?? 0));

        $seq    = max($maxSeq, $seqStart) + 1;
        $prefix = $isFiber ? 'FTTH' : 'STAR';
        return $prefix . str_pad((string)$seq, 6, '0', STR_PAD_LEFT);
    }

    // ══════════════════════════════════════════════════════════════════════
    // 5.18.102 (Uganda, docs/71): the lines and the cash from uCRM Products
    // ══════════════════════════════════════════════════════════════════════

    /**
     * The sale as UcrmQuoteLines::resolve() and resolveDirect() read it, from a form or API post. 5.18.103 (docs/72 §G
     * G-9): $directOn is the uCRM catalogue's switch — true: only the direct form's marker is 'web-direct', and every other
     * post is 'app'; false: the direct marker is 'app' (handleNew refuses it before this) and 5.18.102's marker is 'web';
     * null (no caller of this release passes it): as 5.18.102.
     */
    public static function saleFromPost(array $post, string $connectivity, ?bool $directOn = null): array
    {
        $cart = !empty($post['hw_cart_json']) ? json_decode((string)$post['hw_cart_json'], true) : [];
        $marker = $post['ucrm_quote_form'] ?? null;
        if ($directOn === true) {
            $entry = $marker === UcrmQuoteLines::DIRECT_MARKER ? UcrmQuoteLines::DIRECT_ENTRY : 'app';
        } else {
            $entry = $marker === UcrmQuoteLines::FORM_MARKER ? 'web' : 'app';
        }
        return [
            // 5.18.103 (§G.6): the direct form's uCRM ids — null when absent or unreadable (handleNew refuses those)
            'direct_plan'       => UcrmQuoteLines::parsePlan($post['ucrm_plan'] ?? null),
            'direct_cart'       => UcrmQuoteLines::parseCart($post['ucrm_cart_json'] ?? null),
            'package_choice'    => $post['package_choice'] ?? 0,
            'device_id'         => $post['device_id'] ?? 0,
            'hw_cart'           => is_array($cart) ? $cart : [],
            'kitQty'            => $post['kitQty'] ?? 1,
            'customer_type'     => (string)($post['customer_type'] ?? ''),
            'connectivity_type' => $connectivity,
            'install_by'        => is_string($post['install_by'] ?? null) ? trim($post['install_by']) : '',
            // 5.18.102 (D14, docs/71 §R.4.2): 'web' only when the post carries the KYC form's marker, exactly;
            // 5.18.103: 'web-direct' only from the direct form while the switch is on
            'entry'             => $entry,
        ];
    }

    /**
     * The same, from a stored application (the retries, docs/71 §F.4). The cart and kit quantity linesRecord() kept with
     * an unpriced sale come first: an existing customer's application stores neither elsewhere, and a retry must resolve
     * the sale that was made.
     */
    public static function saleFromApp(array $app): array
    {
        $cart = array_key_exists('quote_sale_cart', $app) ? $app['quote_sale_cart'] : ($app['hw_cart_json'] ?? null);
        if (is_string($cart)) $cart = json_decode($cart, true);
        // 5.18.103 (docs/72 §G.8, G-10): a direct application's sale is its stored uCRM ids, whatever the switch says now
        $direct = ($app['quote_sale_entry'] ?? null) === UcrmQuoteLines::DIRECT_ENTRY;
        $ids    = $direct ? UcrmQuoteLines::itemIds($app['quote_item_ids'] ?? null) : null;
        return [
            'direct_plan'       => $ids !== null ? $ids['plan'] : null,
            'direct_cart'       => $ids !== null ? $ids['hw'] : ($direct ? null : []),
            'package_choice'    => $app['package_choice'] ?? 0,
            'device_id'         => $app['device_id'] ?? 0,
            'hw_cart'           => is_array($cart) ? $cart : [],
            'kitQty'            => $app['quote_sale_kit_qty'] ?? ($app['kitQty'] ?? 1),
            'customer_type'     => (string)($app['customer_type'] ?? ''),
            'connectivity_type' => trim((string)($app['connectivity_type'] ?? 'New Connection')),
            'install_by'        => is_string($app['install_by'] ?? null) ? $app['install_by'] : '',
            // 5.18.102 (D14, §R.4.2, E-1): 'web' only when stored exactly so; none (every application saved before the
            // deploy), 'app', '' or anything else is 'app'. 5.18.103: 'web-direct' when stored exactly so
            'entry'             => $direct ? UcrmQuoteLines::DIRECT_ENTRY : (($app['quote_sale_entry'] ?? null) === 'web' ? 'web' : 'app'),
        ];
    }

    /** The amount a resolved sale collects: its total, or 0 for a sale uCRM cannot price (a Credit lead, never Cash). */
    private static function ugAmount(array $res): float
    {
        return $res['status'] === 'priced' ? (float)$res['total'] : 0.0;
    }

    /**
     * A Cash sale that may not go ahead (docs/71 §G, §H, D10): the text, or ''. Asked before anything is written. The
     * total the form showed must come with the sale, as a plain decimal, and equal uCRM's at 2 dp.
     */
    private static function cashRefusal(array $res, array $post): string
    {
        if (($post['sales_type'] ?? '') !== 'Cash' || $res['status'] === 'no_plan') return '';
        if ($res['status'] !== 'priced') {
            return $res['reason'] === UcrmQuoteLines::NO_INSTALL_ANSWER
                ? UcrmQuoteLines::text('CASH_NO_INSTALL')
                : UcrmQuoteLines::text('CASH_UNPRICED', ['{REASON}' => (string)$res['reason']]);
        }
        if ((float)$res['total'] <= 0) return UcrmQuoteLines::text('CASH_ZERO');
        $seen = $post['ucrm_total_seen'] ?? null;
        if (!is_scalar($seen) || !preg_match('/^[0-9]+(\.[0-9]+)?$/', trim((string)$seen))) return UcrmQuoteLines::text('CASH_NO_TOTAL');
        if (!UcrmQuoteLines::postedTotalMatches($seen, (float)$res['total'])) return UcrmQuoteLines::text('CASH_CHANGED');
        // 5.18.103 (docs/72 §G.6): a direct sale's items must be the ones the form drew — their tokens, as sets — so a
        // rename or a swap at the same id and price is caught; there is no link record to compare
        if (($res['entry'] ?? null) === UcrmQuoteLines::DIRECT_ENTRY) {
            return UcrmQuoteLines::seenItemsMatch($post['ucrm_items_seen'] ?? null, (array)($res['tokens'] ?? []))
                ? '' : UcrmQuoteLines::text('CASH_ITEM_CHANGED');
        }
        // 5.18.102 (D14, docs/71 §R.4.7): the link records the form drew must be the ones this sale resolved through — a
        // relink at the same price would otherwise show the agent one product and charge the customer for another.
        if (!UcrmQuoteLines::seenLinksMatch($post['ucrm_links_seen'] ?? null, (array)($res['link_ids'] ?? []))) {
            return UcrmQuoteLines::text('CASH_RELINKED');
        }
        return '';
    }

    /**
     * What every Uganda application stores (docs/71 §F.3): the resolved lines with this release's mark, or none; the
     * resolved prices in offer_price and device_price, never the plugin's or the browser's; the installation answer
     * when the sale asked for one.
     */
    public static function linesRecord(array $res, array $post, string $connectivity): array
    {
        $rec = [];
        $by = self::installAnswer($post, $connectivity);
        if ($by !== '') $rec['install_by'] = $by;
        // 5.18.103 (docs/72 §G.7): a direct sale — its uCRM ids always, priced or not; never quote_link_ids, never a cart
        if (($res['entry'] ?? null) === UcrmQuoteLines::DIRECT_ENTRY) {
            $rec['quote_sale_entry'] = UcrmQuoteLines::DIRECT_ENTRY;
            $rec['quote_item_ids']   = $res['item_ids'];
            if ($res['status'] === 'priced') {
                return $rec + [
                    'quote_items'         => $res['lines'],
                    'quote_lines_source'  => UcrmQuoteLines::DIRECT_SOURCE,
                    'quote_items_total'   => $res['total'],
                    'quote_lines_read_at' => date('Y-m-d H:i:s'),
                    'offer_price'         => $res['plan_price'],
                    'device_price'        => $res['device_price'],
                ];
            }
            return $rec + ['quote_items' => null, 'offer_price' => null, 'device_price' => null,
                           'quote_unpriced_why' => (string)($res['why'] ?? '')];
        }
        // 5.18.102 (D14, docs/71 §R.4.2, §R.4.4): the entry point, from resolve() — read once, never from the post again
        $rec['quote_sale_entry'] = ($res['entry'] ?? null) === 'web' ? 'web' : 'app';
        if ($res['status'] === 'priced') {
            return $rec + [
                'quote_link_ids'      => UcrmQuoteLines::linkIds($res['link_ids'] ?? []),   // 5.18.102 (§R.4.4)
                'quote_items'         => $res['lines'],
                'quote_lines_source'  => UcrmQuoteLines::SOURCE,
                'quote_items_total'   => $res['total'],
                'quote_lines_read_at' => date('Y-m-d H:i:s'),
                'offer_price'         => $res['plan_price'],
                'device_price'        => $res['device_price'],
            ];
        }
        // Unpriced: no lines to keep, so the sale itself is kept for the retry, which resolves it again (saleFromApp).
        $sale = self::saleFromPost($post, $connectivity);
        return $rec + ['quote_items' => null, 'offer_price' => null, 'device_price' => null,
                       'quote_sale_cart' => $sale['hw_cart'], 'quote_sale_kit_qty' => $sale['kitQty']];
    }

    /** The sale's "Who installs?" answer, 'dishnet' or 'customer', where the sale asks it (docs/71 §E.1); '' otherwise. */
    private static function installAnswer(array $post, string $connectivity): string
    {
        $by = is_string($post['install_by'] ?? null) ? trim($post['install_by']) : '';
        return UcrmQuoteLines::needsInstallAnswer((string)($post['customer_type'] ?? ''), $connectivity)
            && ($by === 'dishnet' || $by === 'customer') ? $by : '';
    }

    /**
     * 5.18.102 (Uganda, docs/71 §E.2): a handler's answer with the installation answer added to its data — the data
     * post_kyc.php and the mobile API hand to the staff messages (kycSubmitted, kycCrmCreated, kycAdditionalService).
     * Where the gate is off ($ug null) the answer is returned unchanged.
     */
    private static function ugWithInstall(?array $ug, array $post, string $connectivity, array $answer): array
    {
        if ($ug === null) return $answer;
        $by = self::installAnswer($post, $connectivity);
        if ($by !== '' && isset($answer['data']) && is_array($answer['data'])) $answer['data']['install_by'] = $by;
        return $answer;
    }

    /** The customer-visible notes of a Uganda quote (docs/71 §F.1): the prefix, then $extra. '' leaves NOTES empty. */
    public static function customerNotes(array $cfg, string $extra = ''): string
    {
        $prefix = trim((string)($cfg['kyc_quote_notes_prefix'] ?? ''));
        $extra  = trim($extra);
        return $prefix . ($prefix !== '' && $extra !== '' ? "\n" : '') . $extra;
    }

    /** A reference fit to compose into text: '' for the placeholder PENDING, which must never reach uCRM. */
    public static function composedRef($ref): string
    {
        $r = trim((string)$ref);
        return strtoupper($r) === 'PENDING' ? '' : $r;
    }

    /** "DishNet" / "Customer installs — kit only" / '' (not asked). */
    public static function installLabel($by): string
    {
        return $by === 'dishnet' ? 'DishNet' : ($by === 'customer' ? 'Customer installs — kit only' : '');
    }

    /** The staff-only adminNotes of a Uganda KYC quote (docs/71 §F.1). */
    public static function kycAdminNotes(int $appId, string $how, string $sales, string $connectivity, string $priority,
                                         $installBy, $ref): string
    {
        $parts = ["KYC application #{$appId}", $how, 'Sales: ' . $sales, 'Connection: ' . $connectivity,
                  'Priority: ' . ($priority !== '' ? $priority : 'Medium')];
        $inst = self::installLabel($installBy);
        if ($inst !== '') $parts[] = 'Install: ' . $inst;
        $r = self::composedRef($ref);
        if ($r !== '') $parts[] = 'Plugin ref: ' . $r;
        return implode(' · ', $parts);
    }

    /** The text for a new KYC customer created in uCRM with no quotation (P1, P3): where they are, and not to register again. */
    public static function newCustomerNoQuote(int $clientId, string $text): string
    {
        return 'The customer is in uCRM as #' . $clientId . '. ' . $text . ' Do not register this customer again.';
    }

    /**
     * Record that no quotation was made, and why (docs/71 §F.5): P1 unpriced, P2 a retry whose lines do not total the
     * cash collected, P3 over the auto-quote limit. The fields are 5.18.99's, written only where its gate is on.
     * 5.18.102 D14 review round 9 (CK9-B1): and quote_refused_ts, the same moment as an epoch, which claimLeftUnsettled
     * compares with a claim's — never quote_refused_at, a local time that a change of zone would shift. Round 11 (CK11-B2):
     * $notBefore is a press's claim, which a refusal already recorded may have put a second ahead of the clock (round 10),
     * so that press's own refusal is never stamped before its claim.
     */
    public static function recordNoQuote($store, int $appId, string $code, string $text, int $notBefore = 0): void
    {
        $store->updateOne('kyc_applications.json', 'id', $appId, [
            'quote_refused'      => $code,
            'quote_refused_text' => $text,
            'quote_refused_at'   => date('Y-m-d H:i:s'),
            'quote_refused_ts'   => max(time(), $notBefore),   // 5.18.102 D14 review round 9 (CK9-B1); round 11 (CK11-B2)
        ]);
        error_log("[KycService] application #{$appId}: no quotation made ({$code})");
    }

    /** "UGX 249,000" — the book's symbol; decimals only when there are some. */
    private static function ugMoney(float $v, array $cfg): string
    {
        return dn_money($v, $cfg, abs($v - round($v)) < 0.0001 ? 0 : 2);
    }

    /**
     * 5.18.103 (docs/72 §G.7, G-7): the hw_cart_json a direct application stores, rebuilt by the server — what its readers
     * read (the installation prefill and the stock deduction: title and qty; the export: title and price), uCRM's names
     * and prices, id 0 (it matches no plugin row anywhere) and no ucrm_product_id (the gate-off builders would quote it).
     */
    public static function directCartJson(array $res): string
    {
        $rows = [];
        foreach ((array)($res['cart_rows'] ?? []) as $r) {
            $rows[] = ['id' => 0, 'title' => (string)($r['title'] ?? ''), 'price' => $r['price'] ?? null, 'qty' => (int)($r['qty'] ?? 1)];
        }
        return json_encode($rows, JSON_UNESCAPED_UNICODE);
    }

    /**
     * The lines a retry may quote for an application (docs/71 §F.4, D11), or why none:
     *   - 5.18.102 (D14, §R.4.2, E-1): the entry rule first — an application not stored with quote_sale_entry 'web'
     *     (every one saved before the deploy, every app-origin one) is unpriced with the entry reason, whatever it stores
     *     and whatever is linked;
     *   - marked lines (quote_lines_source) are re-confirmed by their links (UcrmQuoteLines::confirmLines, §R.4.4): each
     *     quote_link_ids record still in force, its item still resolving, at the price stored;
     *   - no marked lines: resolved now by the current links — never inferred from a stored productId;
     *   - a Cash application is never re-priced: quoted only when the lines total exactly the cash collected (2 dp).
     * A Credit refusal whose reason is "not linked" on a web application takes P1_UNLINKED's text (§R.4.1).
     * 5.18.103 (root docs/72 §G.8, G-10): a direct application ('web-direct') is retried by its uCRM ids, whatever the
     * switch says — stored lines re-confirmed (confirmDirect: the rules, not "kept off"), or an unpriced sale resolved
     * again (resolveDirect, "kept off" applying); its Credit refusal takes P1_DIRECT. $cat is the uCRM catalogue's
     * settings (null: none — the switch off, no installation, nothing kept off).
     *
     * @return array{ok:bool,lines:array,code:string,text:string,link_ids:array}  link_ids: the records the lines came
     *         from — the resolution's set for unmarked lines, the stored set for marked ones (§R.4.4); [] for a direct one
     */
    public static function retryLines(array $app, UcrmQuoteLines $uql, $store, array $cfg, ?UcrmCatalogue $cat = null): array
    {
        $lines    = null;
        $reason   = '';
        $linkIds  = [];
        $unlinked = false;
        $why      = '';
        $cat      = $cat ?? UcrmCatalogue::fromRecords([], false);
        $sale     = self::saleFromApp($app);
        if ($sale['entry'] === UcrmQuoteLines::DIRECT_ENTRY) {
            $markedD = ($app['quote_lines_source'] ?? '') === UcrmQuoteLines::DIRECT_SOURCE
                && is_array($app['quote_items'] ?? null) && $app['quote_items'] !== [];
            if ($markedD) {
                $reason = $uql->confirmDirect($app['quote_items'], $app['quote_item_ids'] ?? null);
                if ($reason === '') $lines = array_values($app['quote_items']);
            } elseif ($sale['direct_plan'] === null || $sale['direct_cart'] === null) {
                $reason = 'the uCRM items this application was saved with could not be read';
            } else {
                $res = $uql->resolveDirect($sale, $cat->installProductId(), $cat->hiddenIds(), true);
                if ($res['status'] === 'priced') {
                    $lines = $res['lines'];
                } else {
                    $reason = (string)$res['reason'];
                    $why    = (string)($res['why'] ?? '');
                }
            }
        } elseif ($sale['entry'] !== 'web') {
            $reason = UcrmQuoteLines::ENTRY_REASON;   // 5.18.102 (D14, E-1): before the marked lines are confirmed
        } else {
            $marked = ($app['quote_lines_source'] ?? '') === UcrmQuoteLines::SOURCE
                && is_array($app['quote_items'] ?? null) && $app['quote_items'] !== [];
            if ($marked) {
                $reason = $uql->confirmLines($app['quote_items'], $app['quote_link_ids'] ?? null, $store);
                if ($reason === '') {
                    $lines   = array_values($app['quote_items']);
                    $linkIds = UcrmQuoteLines::linkIds($app['quote_link_ids'] ?? []);
                }
            } else {
                $res = $uql->resolve($sale, $store);
                if ($res['status'] === 'priced') {
                    $lines   = $res['lines'];
                    $linkIds = UcrmQuoteLines::linkIds($res['link_ids'] ?? []);
                } else {
                    $reason   = $res['status'] === 'no_plan' ? "the customer's plan is no longer in the plugin" : (string)$res['reason'];
                    $unlinked = !empty($res['unlinked']);
                }
            }
        }
        $cash = round((float)($app['amount_charged'] ?? 0), 2);
        if (($app['sales_type'] ?? '') === 'Cash' && $cash > 0) {
            if ($lines === null || abs(UcrmQuoteLines::total($lines) - $cash) > 0.0001) {
                return ['ok' => false, 'lines' => [], 'code' => 'P2',
                        'text' => UcrmQuoteLines::text('P2', ['{AMOUNT}' => self::ugMoney($cash, $cfg)]), 'link_ids' => []];
            }
            return ['ok' => true, 'lines' => $lines, 'code' => '', 'text' => '', 'link_ids' => $linkIds];
        }
        if ($lines === null) {   // 5.18.103 (docs/72 §G.8): the why code and the switch choose the text (P1_DIRECT, WEB_SWITCH_ON)
            return ['ok' => false, 'lines' => [], 'code' => 'P1', 'link_ids' => [],
                    'text' => self::p1Text(['reason' => $reason, 'unlinked' => $unlinked, 'entry' => $sale['entry'], 'why' => $why],
                        $cat->direct())];
        }
        return ['ok' => true, 'lines' => $lines, 'code' => '', 'text' => '', 'link_ids' => $linkIds];
    }

    /**
     * 5.18.102 (D14, docs/71 §R.4.1, E-2): P1's text — or P1_UNLINKED's ("Do not make it by hand — once the item is
     * linked, an admin makes it from the Orders card") for a web sale whose reason is "not linked", an item or the
     * installation. resolve() marks those reasons; the choice never matches the text. The record stays P1.
     * 5.18.103 (root docs/72 §G.8): $directOn, the uCRM catalogue's switch, and a direct sale's why code.
     */
    public static function p1Text(array $res, bool $directOn = false): string
    {
        // 5.18.103 (root docs/72 §G.8): a direct sale takes P1_DIRECT — true after a rollback too — but a sale that does not
        // say who installs keeps P1; with the switch on, a 5.18.102 web sale left unlinked is quoted in uCRM by hand (G-10)
        if (($res['entry'] ?? null) === UcrmQuoteLines::DIRECT_ENTRY) {
            $code = ($res['why'] ?? '') === 'no_install_answer' ? 'P1' : 'P1_DIRECT';
            return UcrmQuoteLines::text($code, ['{REASON}' => (string)($res['reason'] ?? '')]);
        }
        if ($directOn && !empty($res['unlinked']) && ($res['entry'] ?? null) === 'web') return UcrmQuoteLines::text('WEB_SWITCH_ON');
        $code = (!empty($res['unlinked']) && ($res['entry'] ?? null) === 'web') ? 'P1_UNLINKED' : 'P1';
        return UcrmQuoteLines::text($code, ['{REASON}' => (string)($res['reason'] ?? '')]);
    }

    /** P3's text for the configured limit. */
    public static function overMaxText(array $cfg): string
    {
        return UcrmQuoteLines::text('P3', ['{MAX}' => self::ugMoney((float)($cfg['kyc_auto_quote_max_amount'] ?? 0), $cfg)]);
    }

    // ══════════════════════════════════════════════════════════════════════
    // 5.18.102 (Uganda, D14, docs/71 §R.4.8, E-2): Make the quotation — one implementation, two entry points
    // ══════════════════════════════════════════════════════════════════════

    /** 5.18.102 (F-9): the claim's age limit, above the method's worst case (about eight 30 s requests, ~240 s). */
    public const QUOTE_CLAIM_SECONDS = 900;
    /** 5.18.102 (F-1, F-13): the two entry points; any other $via throws before the claim. */
    public const MAKE_QUOTE_VIA = ['orders_card', 'retry_quote'];
    /** 5.18.102 (F-7): the fixed texts of makeQuotation()'s answers (no figure, no name). */
    public const MAKE_QUOTE_TEXTS = [
        'claimed'    => 'another quotation is being made for this application',
        'found'      => 'uCRM already has a quotation for this client made after this application was saved — no second one is made',
        'unreadable' => 'uCRM\'s quotations for this client could not be read — no quotation was made',
        // 5.18.102 D14 review round 2 (FX-flows-2): no id in uCRM's answer — a timeout or a 5xx may follow a quotation
        // uCRM made, so the text never says none was made
        'error'      => 'uCRM did not confirm the quotation — it may have been made; a retry never makes a second one',
    ];

    /**
     * 5.18.102 (D14, docs/71 §R.4.8, F-10): does Make the quotation apply to this application — the button's five
     * conditions, one implementation for the Orders card and the handler's re-check: quote_sale_entry exactly 'web', a
     * uCRM client, quote_refused P1, no quote_created, and no quote_found_in_ucrm.
     * 5.18.103 (root docs/72 §G.8, G-10): $directOn, the uCRM catalogue's switch — see the body.
     */
    public static function canMakeQuotation(array $app, bool $directOn = false): bool
    {
        // 5.18.103 (root docs/72 §G.8, G-10): a direct application always; a 5.18.102 web one only with the switch off —
        // with it on, its plugin items can no longer be linked, so its quotation is made in uCRM by hand
        $entry = $app['quote_sale_entry'] ?? null;
        return ($entry === UcrmQuoteLines::DIRECT_ENTRY || ($entry === 'web' && !$directOn))
            && !empty($app['crm_client_id'])
            && ($app['quote_refused'] ?? null) === 'P1'
            && empty($app['quote_created'])
            && empty($app['quote_found_in_ucrm']);
    }

    /**
     * 5.18.102 D14 review round 8 (CK8-F1, CK8-F2): a claim still recorded (quote_make_claim_ts) with no refusal recorded at
     * or after it. Every answer and every Throwable removes its claim, so such a claim is a request that died — after its
     * POST, perhaps — or a removal that failed after an answer that recorded nothing. One a refusal followed is a refusal
     * whose removal failed: the refusal holds. Round 9 (CK9-B1): "followed" is read from quote_refused_ts, the epoch the
     * two refusal writers a press reaches record beside quote_refused_at (recordNoQuote, postQuote's company check) —
     * never from quote_refused_at, a local time that a change of zone (or a process that falls back to another zone)
     * would shift. A refusal with no epoch — recorded before 5.18.102, or by a writer no press reaches (registration's
     * company check, the cron for a customer not yet in uCRM) — counts as before the claim: the card errs to "did not
     * finish", never to a refusal that would send someone to make by hand a quotation that may exist.
     */
    public static function claimLeftUnsettled(array $app): bool
    {
        $ts = $app['quote_make_claim_ts'] ?? null;
        if (!is_numeric($ts)) return false;
        // round 11 (CK11-B1): a press's own refusal falls within its claim's lifetime; an epoch past it is no refusal of this
        // claim's (a corrupt value, a clock that ran ahead), so the claim is unsettled
        if (is_numeric($app['quote_refused_ts'] ?? null) && (int)$app['quote_refused_ts'] >= (int)$ts + self::QUOTE_CLAIM_SECONDS) return true;
        $at = $app['quote_refused_ts'] ?? null;
        return !is_numeric($at) || (int)$at < (int)$ts;
    }

    /**
     * 5.18.102 (D14, docs/71 §R.4.8, E-2, F-1…F-13): quote one KYC application from its record, by the current links —
     * the shared implementation retry_quote's gated block and the Orders card's Make the quotation both call. Only
     * their gated paths call it; South Sudan and Uganda with the gate off never reach it.
     *
     *   1. the claim (quote_make_claim_ts, F-9): taken inside withLock, refused ('claimed') while quote_created is set or
     *      a claim younger than QUOTE_CLAIM_SECONDS is recorded; removed in a finally, only while it is still this
     *      request's;
     *   2. R-M4's guard (§R.4.4): billing/quotes?clientId=<crmId> read with the quoting client, rows kept by clientId. A
     *      quotation found — any, for a customer this application created; one dated on or after submitted_at, for an
     *      existing customer — records quote_found_in_ucrm and nothing else ('found'); an unreadable list writes nothing
     *      ('unreadable'); none found removes quote_unconfirmed_at, as the earlier POST it records made none;
     *   3. the lines: retryLines() — the entry from the record, the current links, the Cash rule on the stored
     *      amount_charged. A refusal is recorded (P1, P1_UNLINKED's text, or P2) ('refused');
     *   4. postQuote(): its company check (asking nothing when $known names the organization), the POST, the read-back,
     *      quote_ref, uCRM's send, quote_created and wa_quote_pending, as every KYC quotation is made. A company-check
     *      refusal is 'refused'; a failed POST records quote_error and quote_unconfirmed_at (the Orders card then says
     *      making the last quotation did not finish and that it may have been made, never that none was made) and is
     *      'error'. A Throwable from postQuote() records quote_unconfirmed_at too, then goes on (it may follow the POST).
     *      Either write that fails is logged (ids only), never answered in place of the answer it follows;
     *   5. once created, one write: quote_link_ids, quote_error null, quote_made_by_id and quote_made_via.
     *
     * No auto-quote limit applies (P3): either entry point is an admin's act. A Throwable is not an answer: the claim
     * is removed and the Throwable goes on to the caller. The log carries ids only.
     *
     * @param array      $app    the application as its caller read it
     * @param array|null $known  the organization the caller has already checked, in postQuote()'s shape, or null
     * @param string     $via    'orders_card' or 'retry_quote' (the staff's notes, and quote_made_via)
     * @param array      $actor  the admin's account record, re-read fresh by id (§R.2.1)
     * @return array{code:int,kind:string,quote_id:?int,lines:int,total:?float,text:string}
     */
    public function makeQuotation(array $app, ?array $known, string $via, array $actor): array
    {
        if (!in_array($via, self::MAKE_QUOTE_VIA, true)) throw new \InvalidArgumentException('makeQuotation: unknown entry point');
        $appId = (int)($app['id'] ?? 0);
        $crmId = (int)($app['crm_client_id'] ?? 0);
        if ($appId <= 0 || $crmId <= 0) throw new \InvalidArgumentException('makeQuotation: no application or no uCRM client');
        $cfg = $this->store->load('kyc_config.json') ?: [];
        if (!is_array($cfg)) $cfg = [];
        $setting = QuoteOrganization::setting($cfg, $this->gateDataDir());
        if (empty($setting['on'])) throw new \LogicException('makeQuotation: the quoting-organization gate is off');
        $quoteCrm = UcrmQuoteLines::quoteClient($this->crm, $cfg);

        // 1. the claim
        $claim = self::takeQuoteClaim($this->store, $appId);
        if ($claim === null) {
            error_log("[KycService] Make the quotation, application #{$appId} ({$via}): claimed");
            return self::madeAnswer(409, 'claimed', null, 0, null, self::MAKE_QUOTE_TEXTS['claimed']);
        }
        try {
            // 2. R-M4's guard: never a second quotation
            $found = self::quotationInUcrm($quoteCrm, $app, $crmId);
            if ($found === null) {
                error_log("[KycService] Make the quotation, application #{$appId} ({$via}): uCRM's quotations unreadable");
                return self::madeAnswer(409, 'unreadable', null, 0, null, self::MAKE_QUOTE_TEXTS['unreadable']);
            }
            if ($found !== []) {
                $num = is_scalar($found['number'] ?? null) && trim((string)$found['number']) !== ''
                    ? trim((string)$found['number']) : (string)(int)($found['id'] ?? 0);
                $this->store->updateOne('kyc_applications.json', 'id', $appId, [
                    'quote_found_in_ucrm' => ['number' => $num, 'at' => date('Y-m-d H:i:s')],
                ]);
                error_log("[KycService] Make the quotation, application #{$appId} ({$via}): a quotation is in uCRM already");
                return self::madeAnswer(409, 'found', null, 0, null, self::MAKE_QUOTE_TEXTS['found']);
            }
            // 5.18.102 D14 review round 3 (CK-flows-2): uCRM has no quotation for this application, so an earlier POST whose
            // answer did not confirm one made none — the card's "did not finish" box gives way to this press's answer.
            // Round 4 (CK4-F3): always, under the lock, by the row as stored — never by the caller's $app, read before the
            // claim, which another request may have outdated
            self::dropUnconfirmed($this->store, $appId);

            // 3. the lines, by the current links
            $r = self::retryLines($app, new UcrmQuoteLines($quoteCrm, $setting, UcrmQuoteLinks::fromStore($this->store, $setting)),
                $this->store, $cfg, UcrmCatalogue::fromStore($this->store, $setting));   // 5.18.103 (docs/72 §G.8)
            if (!$r['ok']) {
                self::recordNoQuote($this->store, $appId, $r['code'], $r['text'], $claim);   // round 11 (CK11-B2): never before the claim
                return self::madeAnswer(409, 'refused', null, 0, null, $r['text']);
            }

            // 4. postQuote(), as every KYC quotation is made
            $sp = trim((string)($app['sales_person'] ?? ''));
            $staffNotes = ($via === 'retry_quote' ? 'Retry for App #' : 'Made from the Orders card for App #') . $appId
                        . ' · Sales: ' . ($sp !== '' ? $sp : (string)($app['retailer_name'] ?? ''));
            try {
                $posted = self::postQuote($this->crm, $this->store, $appId, $crmId, $r['lines'], self::customerNotes($cfg),
                    $staffNotes, (string)($app['mobile'] ?? ''), $known);
            } catch (\Throwable $e) {
                // 5.18.102 D14 review round 4 (CK4-F1): a Throwable inside postQuote may follow its POST, so the card says
                // "did not finish" as after an 'error' answer. Recorded in its own try; the Throwable goes on either way
                self::recordUnconfirmed($this->store, $appId, $via);
                throw $e;
            }
            $n = count($r['lines']);
            if (empty($posted['created'])) {
                if (!empty($posted['refused'])) {
                    self::refusalNotBeforeClaim($this->store, $appId, $claim, $via);   // round 11 (CK11-B2)
                    error_log("[KycService] Make the quotation, application #{$appId} ({$via}): refused by the company check");
                    return self::madeAnswer(409, 'refused', null, $n, null, (string)$posted['refused']);
                }
                // 5.18.102 D14 review round 2 (FX-flows-2): "not confirmed", never "none made" (the answer may have been lost)
                // 5.18.102 D14 review round 3 (CK-flows-2): recorded, so the Orders card says "did not finish", never "none made".
                // Round 5 (CK5-B1): in its own try, as the catch's — the answer stays 'error' whatever the write does
                self::recordUnconfirmed($this->store, $appId, $via);
                error_log("[KycService] Make the quotation, application #{$appId} ({$via}): uCRM did not confirm a quotation (no id in its answer)");
                return self::madeAnswer(500, 'error', null, $n, null, self::MAKE_QUOTE_TEXTS['error']);
            }

            // 5. one write, once created. 5.18.102 D14 review (D14-MQ-THROW-AFTER-POST): postQuote() has already stored
            // quote_id, quote_created and wa_quote_pending, so 'made' is true whatever this write does — a failure here is
            // logged (ids only, so an admin can restore quote_link_ids) and never answered as "no quotation".
            $quoteId = (int)$posted['id'];
            try {
                // 5.18.103 (docs/72 §G.8): a direct application records no link ids — it was quoted by its uCRM ids
                $this->store->updateOne('kyc_applications.json', 'id', $appId,
                    (($app['quote_sale_entry'] ?? null) === UcrmQuoteLines::DIRECT_ENTRY ? [] : [
                    'quote_link_ids'   => UcrmQuoteLines::linkIds($r['link_ids'] ?? []),
                    ]) + [
                    'quote_error'      => null,
                    'quote_made_by_id' => (int)($actor['id'] ?? 0),
                    'quote_made_via'   => $via,
                ]);
            } catch (\Throwable $e) {
                error_log("[KycService] Make the quotation, application #{$appId} ({$via}): quotation #{$quoteId} made; its record (link ids "
                    . implode(',', UcrmQuoteLines::linkIds($r['link_ids'] ?? [])) . ') could not be written (' . get_class($e) . ')');
            }
            error_log("[KycService] Make the quotation, application #{$appId} ({$via}): quotation #{$quoteId} made, {$n} line(s)");
            return self::madeAnswer(200, 'made', $quoteId, $n, UcrmQuoteLines::total($r['lines']), '');
        } finally {
            self::dropQuoteClaim($this->store, $appId, $claim);
        }
    }

    /** 5.18.102 (F-7): makeQuotation()'s one answer shape. */
    private static function madeAnswer(int $code, string $kind, ?int $quoteId, int $lines, ?float $total, string $text): array
    {
        return ['code' => $code, 'kind' => $kind, 'quote_id' => $quoteId, 'lines' => $lines,
                'total' => $total === null ? null : round($total, 2), 'text' => $text];
    }

    /**
     * 5.18.102 (F-9): take the claim inside withLock — no network I/O in the callback. null when another request holds
     * a claim younger than QUOTE_CLAIM_SECONDS, or a quotation was made after the caller read the application (nothing
     * written); else the stamp written, which only this request may remove: time(), or (5.18.102 D14 review round 10,
     * CK10-B1) one second after a refusal recorded at or after it, so that refusal never reads as this claim's own.
     */
    private static function takeQuoteClaim($store, int $appId): ?int
    {
        $now = time();
        $taken = $store->withLock('kyc_applications.json', function (array $apps) use ($appId, $now) {
            foreach ($apps as $i => $a) {
                if (!is_array($a) || (int)($a['id'] ?? 0) !== $appId) continue;
                if (!empty($a['quote_created'])) return ['records' => $apps, 'result' => false];
                $ts = $a['quote_make_claim_ts'] ?? null;
                if (is_numeric($ts) && $now - (int)$ts < self::QUOTE_CLAIM_SECONDS) return ['records' => $apps, 'result' => false];
                // 5.18.102 D14 review round 8 (CK8-F1): an expired claim no refusal followed is a request that died, after its
                // POST perhaps. It becomes the key the Orders card's "did not finish" box reads, which only a guard that finds no
                // quotation removes — so a press that cannot settle it ('unreadable', a Throwable) never loses it
                if (self::claimLeftUnsettled($a) && !array_key_exists('quote_unconfirmed_at', $a)) {
                    $apps[$i]['quote_unconfirmed_at'] = date('Y-m-d H:i:s', (int)$ts);
                }
                // Round 10 (CK10-B1): stamped after any refusal already recorded — one recorded in this same second by
                // another request, or later by a clock that stepped back, would otherwise settle this claim (claimLeftUnsettled)
                $stamp = $now;
                $rts = $a['quote_refused_ts'] ?? null;
                // round 11 (CK11-B1): an epoch QUOTE_CLAIM_SECONDS or more ahead (corrupt, or a clock that ran ahead) never
                // holds a claim back; claimLeftUnsettled never lets such an epoch settle a claim either
                if (is_numeric($rts) && (int)$rts >= $now + self::QUOTE_CLAIM_SECONDS) $rts = null;
                if (is_numeric($rts) && (int)$rts >= $stamp) $stamp = (int)$rts + 1;
                $apps[$i]['quote_make_claim_ts'] = $stamp;
                return ['records' => $apps, 'result' => $stamp];
            }
            return ['records' => $apps, 'result' => null];
        });
        if ($taken === null) throw new \RuntimeException('makeQuotation: application #' . $appId . ' is not in the store');
        return is_int($taken) ? $taken : null;
    }

    /**
     * 5.18.102 (F-9): remove this request's claim by a second withLock that unsets that one key, on that one
     * application, only while it still holds the value this request wrote (updateOne only merges, so it could not). A
     * removal that throws is logged with ids only; the claim then expires by its age.
     */
    private static function dropQuoteClaim($store, int $appId, int $claim): void
    {
        try {
            $store->withLock('kyc_applications.json', function (array $apps) use ($appId, $claim) {
                foreach ($apps as $i => $a) {
                    if (!is_array($a) || (int)($a['id'] ?? 0) !== $appId) continue;
                    if (array_key_exists('quote_make_claim_ts', $a) && $a['quote_make_claim_ts'] === $claim) {
                        unset($apps[$i]['quote_make_claim_ts']);
                    }
                    break;
                }
                return ['records' => $apps, 'result' => null];
            });
        } catch (\Throwable $e) {
            error_log("[KycService] Make the quotation, application #{$appId}: the claim could not be removed; it expires by its age");
        }
    }

    /**
     * 5.18.102 D14 review round 11 (CK11-B2): postQuote's company check stamps its refusal at time(), and this press's claim
     * may stand a second ahead of the clock (round 10), so the refusal is raised to the claim — never stamped before it.
     * In its own try: a failed write is logged with ids only (the refusal then reads as before the claim, so the card errs
     * to "did not finish", and only if the claim's removal fails too) and never answers in place of the refusal.
     */
    private static function refusalNotBeforeClaim($store, int $appId, int $claim, string $via): void
    {
        if ($claim <= time()) return;
        try {
            $store->updateOne('kyc_applications.json', 'id', $appId, ['quote_refused_ts' => $claim]);
        } catch (\Throwable $e) {
            error_log("[KycService] Make the quotation, application #{$appId} ({$via}): the refusal's time could not be raised to its claim (" . get_class($e) . ')');
        }
    }

    /**
     * 5.18.102 D14 review rounds 3–5: record quote_unconfirmed_at, for the Orders card's "did not finish" box. A write that
     * fails is logged with ids only and never answers in place of the answer or the Throwable it follows.
     */
    private static function recordUnconfirmed($store, int $appId, string $via): void
    {
        try {
            $store->updateOne('kyc_applications.json', 'id', $appId, ['quote_unconfirmed_at' => date('Y-m-d H:i:s')]);
        } catch (\Throwable $e) {
            error_log("[KycService] Make the quotation, application #{$appId} ({$via}): quote_unconfirmed_at could not be recorded (" . get_class($e) . ')');
        }
    }

    /**
     * 5.18.102 D14 review round 3 (CK-flows-2): remove quote_unconfirmed_at by a withLock that unsets that one key on that one
     * application (updateOne only merges, so it could not). makeQuotation() calls it once R-M4's guard has found no
     * quotation, before any POST, whether or not the caller's record held the key: the earlier POST that key records
     * made none. A row without the key is left as it is. A removal that throws goes on to the caller.
     */
    private static function dropUnconfirmed($store, int $appId): void
    {
        $store->withLock('kyc_applications.json', function (array $apps) use ($appId) {
            foreach ($apps as $i => $a) {
                if (!is_array($a) || (int)($a['id'] ?? 0) !== $appId) continue;
                unset($apps[$i]['quote_unconfirmed_at']);
                break;
            }
            return ['records' => $apps, 'result' => null];
        });
    }

    /**
     * 5.18.102 (R-M4, docs/71 §R.4.4): a quotation uCRM already holds for this client — [] when none, null when the list
     * cannot be read (fail closed). Only rows whose clientId is $crmId count: the answer may hold other clients'. For an
     * existing customer (is_additional_service) only a quotation dated on or after submitted_at counts, both read in
     * the plugin's timezone; a date that cannot be parsed counts as after.
     */
    private static function quotationInUcrm($quoteCrm, array $app, int $crmId): ?array
    {
        try {
            $list = $quoteCrm->get('billing/quotes?' . http_build_query(['clientId' => $crmId]));
        } catch (\Throwable $e) {
            return null;
        }
        if (!is_array($list) || array_key_exists('raw', $list)) return null;
        if ($list !== [] && array_keys($list) !== range(0, count($list) - 1)) return null;
        $existing = !empty($app['is_additional_service']);
        $since = $existing ? strtotime((string)($app['submitted_at'] ?? '')) : false;
        foreach ($list as $q) {
            if (!is_array($q) || (int)($q['clientId'] ?? 0) !== $crmId) continue;
            if (!$existing || $since === false) return $q;
            $made = is_scalar($q['createdDate'] ?? null) ? strtotime((string)$q['createdDate']) : false;
            if ($made === false || $made >= $since) return $q;
        }
        return [];
    }

    /**
     * 5.18.102 (Uganda, docs/71 §F.6): what uCRM made, against what was sent — each item's label and price, and the
     * total. From the POST's answer, or one GET when the answer has no items. A mismatch is stored as quote_mismatch
     * for the Orders card; each item's type and whether it carries a productId are stored as a note. quote.add has
     * already messaged the customer, so this detects; it cannot prevent.
     */
    public static function readBack($quoteCrm, $store, int $appId, $quoteId, array $sent, array $resp, ?array $fetched = null): void
    {
        try {
            $q = (is_array($resp['items'] ?? null) && $resp['items'] !== []) ? $resp : null;
            if ($q === null && is_array($fetched) && is_array($fetched['items'] ?? null)) $q = $fetched;
            if ($q === null) $q = $quoteCrm->get("billing/quotes/{$quoteId}");
            $items = is_array($q) && is_array($q['items'] ?? null) ? array_values($q['items']) : null;
            $sentTotal = UcrmQuoteLines::total($sent);
            $now = date('Y-m-d H:i:s');
            if ($items === null) {
                $store->updateOne('kyc_applications.json', 'id', $appId, [
                    'quote_mismatch' => ['sent_total' => $sentTotal, 'created_total' => null, 'at' => $now],
                ]);
                error_log("[KycService] application #{$appId}: quote #{$quoteId} could not be read back");
                return;
            }
            $created = 0.0;
            $shape   = [];
            $same    = count($items) === count($sent);
            foreach ($items as $i => $it) {
                if (!is_array($it)) { $same = false; continue; }
                $qty = (float)($it['quantity'] ?? 1);
                $price = round((float)($it['price'] ?? 0), 2);
                $created += $price * $qty;
                $shape[] = ['type' => is_scalar($it['type'] ?? null) ? (string)$it['type'] : '',
                            'has_product_id' => !empty($it['productId']),
                            'unit' => is_scalar($it['unit'] ?? null) ? (string)$it['unit'] : ''];
                $s = $sent[$i] ?? null;
                if (!is_array($s) || (string)($it['label'] ?? '') !== (string)($s['label'] ?? '')
                    || abs($price - round((float)($s['price'] ?? 0), 2)) > 0.0001) {
                    $same = false;
                }
            }
            if (isset($q['total']) && is_numeric($q['total'])) $created = (float)$q['total'];
            $created = round($created, 2);
            $upd = ['quote_readback' => ['items' => $shape, 'at' => $now]];
            if (!$same || abs($created - $sentTotal) > 0.0001) {
                $upd['quote_mismatch'] = ['sent_total' => $sentTotal, 'created_total' => $created, 'at' => $now];
                error_log("[KycService] application #{$appId}: quote #{$quoteId} differs from the lines sent");
            }
            $store->updateOne('kyc_applications.json', 'id', $appId, $upd);
        } catch (\Throwable $e) {
            error_log("[KycService] application #{$appId}: quote read-back failed");
        }
    }

    /**
     * 5.18.102 (Uganda, docs/71 §F.2 steps 3–5, §I): after a new KYC client is created — the company check, then
     * "Prepared by" (whether or not a quote follows), then the quote, made only from uCRM's lines. A refusal is
     * recorded (R1–R7 from the check, P1 unpriced, P3 over the limit) and never written as quote_error.
     *
     * @return array{created:bool,id:mixed,ref:string,refused:string}
     */
    private function ugQuoteNew(array $ug, array $org, UcrmClientTarget $target, ?array $created, int $appId,
                                int $clientId, array $cfg, array $post, string $salesPerson, string $connectivity,
                                bool $overMax): array
    {
        $out = ['created' => false, 'id' => null, 'ref' => '', 'refused' => ''];
        $quoteCrm = UcrmQuoteLines::quoteClient($this->crm, $cfg);
        $rows = $target->organizationRows();
        $check = (new QuoteOrganization($quoteCrm, $org))->checkClient($clientId, 'kyc-new', [
            'organizationId' => is_int($created['organizationId'] ?? null) ? $created['organizationId'] : null,
            'organizations'  => $rows,
        ]);
        if (!$check['ok']) {
            $out['refused'] = QuoteOrganization::kycText($check, $clientId);
            $this->store->updateOne('kyc_applications.json', 'id', $appId, [
                'quote_refused'      => $check['code'],
                'quote_refused_text' => $out['refused'],
                'quote_refused_at'   => date('Y-m-d H:i:s'),
            ]);
            return $out;
        }
        $target->patchSalesPerson($clientId, $salesPerson);

        if (($cfg['kyc_auto_quote_enabled'] ?? true) === false) return $out;   // the switch, as before
        if ($ug['status'] !== 'priced') {
            $out['refused'] = self::newCustomerNoQuote($clientId, self::p1Text($ug));   // 5.18.102 (D14, §R.4.1)
            self::recordNoQuote($this->store, $appId, 'P1', $out['refused']);
            return $out;
        }
        if ($overMax) {
            $out['refused'] = self::newCustomerNoQuote($clientId, self::overMaxText($cfg));
            self::recordNoQuote($this->store, $appId, 'P3', $out['refused']);
            return $out;
        }
        $savedApp = $this->store->findOne('kyc_applications.json', 'id', $appId);
        $qRef = $savedApp['quote_ref'] ?? '';
        if ($qRef === '' || $qRef === null) {   // the post gate of Step 8b, unchanged
            $this->store->updateOne('kyc_applications.json', 'id', $appId, ['quote_debug' => 'quote_ref was empty']);
            return $out;
        }
        $by = is_string($post['install_by'] ?? null) ? trim($post['install_by']) : '';
        $posted = self::postQuote($this->crm, $this->store, $appId, $clientId, $ug['lines'], self::customerNotes($cfg),
            self::kycAdminNotes($appId, 'registration', $salesPerson, $connectivity, (string)($post['priority'] ?? 'Medium'),
                UcrmQuoteLines::needsInstallAnswer((string)($post['customer_type'] ?? ''), $connectivity) ? $by : '', $qRef),
            (string)($post['mobile'] ?? ''),
            // the check above passed: the client is filed under the quoting organization, so postQuote asks nothing
            ['organizationId' => $org['id'], 'organizations' => $rows, 'where' => 'kyc-new']);
        if ($posted['created']) {
            $out['created'] = true;
            $out['id'] = $posted['id'];
            $out['ref'] = (string)$posted['ref'];
        } elseif (!empty($posted['refused'])) {
            $out['refused'] = (string)$posted['refused'];
        }
        return $out;
    }

    /**
     * The lines of a KYC quote: the package, then the hardware (the cart, or
     * else the single device), then — for Fiber — the installation fee.
     *
     * The form and the uCRM retry (KycCrmSync) both build quotes here, so a
     * customer whose uCRM create had to be retried is quoted exactly what the
     * form would have quoted. Until 5.18.29 the retry quoted the package alone.
     */
    public static function quoteItems(?array $offer, array $hwCart, ?array $device, int $kitQty,
                                      string $customerType, array $cfg): array
    {
        $items = [];
        if ($offer) {
            $item = [
                'label'    => $offer['name'] ?? 'Service Package',
                'quantity' => 1,
                'price'    => (float)($offer['customer_price'] ?? $offer['amount'] ?? 0),
                'unit'     => 'month',
            ];
            // Link to UCRM product if mapped
            if (!empty($offer['ucrm_product_id'])) {
                $item['productId'] = (int)$offer['ucrm_product_id'];
            }
            $items[] = $item;
        }

        // Multi-item cart (hw_cart_json) takes priority over single device_id
        if (!empty($hwCart)) {
            foreach ($hwCart as $hwItem) {
                if (!is_array($hwItem)) continue;
                $hwPrice = (float)preg_replace('/[^0-9.]/', '', (string)($hwItem['price'] ?? '0'));
                if ($hwPrice > 0) {
                    $item = [
                        'label'    => $hwItem['title'] ?? 'Hardware',
                        'quantity' => max(1, (int)($hwItem['qty'] ?? 1)),
                        'price'    => $hwPrice,
                        'unit'     => 'piece',
                    ];
                    if (!empty($hwItem['ucrm_product_id'])) {
                        $item['productId'] = (int)$hwItem['ucrm_product_id'];
                    }
                    $items[] = $item;
                }
            }
        } elseif (!empty($device) && !empty($device['price'])) {
            // Fallback: single device
            $devPrice = (float)preg_replace('/[^0-9.]/', '', (string)($device['price'] ?? '0'));
            if ($devPrice > 0) {
                $item = [
                    'label'    => $device['title'] ?? 'Hardware / Kit',
                    'quantity' => max(1, $kitQty),
                    'price'    => $devPrice,
                    'unit'     => 'piece',
                ];
                if (!empty($device['ucrm_product_id'])) {
                    $item['productId'] = (int)$device['ucrm_product_id'];
                }
                $items[] = $item;
            }
        }

        // Fiber: add installation fee as separate line
        if ($customerType === 'Fiber') {
            $installFee     = (float)($cfg['fiber_install_fee'] ?? 100);
            $installProduct = (int)($cfg['fiber_install_product_id'] ?? 244); // Default UCRM product ID
            if ($installFee > 0) {
                $item = [
                    'label'    => 'Installation Fee',
                    'quantity' => 1,
                    'price'    => $installFee,
                    'unit'     => 'amount',
                ];
                if ($installProduct > 0) {
                    $item['productId'] = $installProduct;
                }
                $items[] = $item;
            }
        }
        return $items;
    }

    /** kyc_auto_quote_max_amount: a quote over it is left for the agent to make by hand. */
    public static function quoteOverAutoMax(array $items, array $cfg): bool
    {
        $max = (float)($cfg['kyc_auto_quote_max_amount'] ?? 0);
        if ($max <= 0) return false;
        $total = array_sum(array_map(
            fn($i) => (float)($i['price'] ?? 0) * max(1, (int)($i['quantity'] ?? 1)),
            $items
        ));
        return $total > $max;
    }

    /**
     * Create the quote in uCRM, have uCRM send it, and leave the WhatsApp copy
     * to cron_quote_wa — for the form and the uCRM retry alike.
     *
     * Made with the admin token when one is set (crm_auth_token, and
     * crm_base_url if given), otherwise with the plugin's own key: the rule
     * the form has always used.
     *
     * 5.18.99 (Uganda): before the quote is made, the client's company is checked (QuoteOrganization) with the same
     * client the quote is made with. A customer filed under another company gets no quote: the application records
     * quote_refused, quote_refused_text and quote_refused_at (never quote_error, never wa_quote_pending), and the
     * answer carries 'refused' with the text for the agent. $known holds what the create answered (organizationId,
     * the organizations read, and 'where' for the log), so no request is needed when uCRM already said.
     *
     * @return array{created:bool,id:mixed,ref:string,refused?:string}
     */
    public static function postQuote($pluginCrm, $store, int $appId, int $crmClientId, array $items,
                                     string $notes, string $adminNotes, string $phone, ?array $known = null): array
    {
        $cfg   = $store->load('kyc_config.json') ?? [];
        $token = trim((string)($cfg['crm_auth_token'] ?? ''));
        $url   = trim((string)($cfg['crm_base_url'] ?? ''));
        $quoteCrm = ($token !== '')
            ? new CrmApiClient($url !== '' ? $url : rtrim($pluginCrm->getBaseUrl(), '/'), $token, 'x-auth-token')
            : $pluginCrm;

        // 5.18.99 (Uganda, "always company 1"): no quote for a customer filed under another company.
        $quoteOrg = QuoteOrganization::setting(is_array($cfg) ? $cfg : [],
            method_exists($store, 'getDataDir') ? (string)$store->getDataDir() : null);
        if ($quoteOrg['on']) {
            $where = is_array($known) && is_string($known['where'] ?? null) ? $known['where'] : 'kyc-new';
            $check = (new QuoteOrganization($quoteCrm, $quoteOrg))->checkClient($crmClientId, $where, $known);
            if (!$check['ok']) {
                $text = QuoteOrganization::kycText($check, $crmClientId);
                $store->updateOne('kyc_applications.json', 'id', $appId, [
                    'quote_refused'      => $check['code'],
                    'quote_refused_text' => $text,
                    'quote_refused_at'   => date('Y-m-d H:i:s'),
                    'quote_refused_ts'   => time(),   // 5.18.102 D14 review round 9 (CK9-B1): see recordNoQuote
                ]);
                return ['created' => false, 'id' => null, 'ref' => '', 'refused' => $text];
            }
        }

        error_log("[KycService] Attempting quote POST for CRM #{$crmClientId} with " . count($items) . " items");
        $resp = $quoteCrm->post("clients/{$crmClientId}/quotes", [
            // v4.11.3 FIX: no 'maturityDays' — UISP 4.5.33 rejects it with 422
            'notes'      => $notes,
            'adminNotes' => $adminNotes,
            'items'      => $items,
        ]);
        error_log("[KycService] Quote POST response: " . json_encode($resp));
        if (empty($resp['id'])) {
            $err = $quoteCrm->getLastError();
            error_log("[KycService] Quote POST failed for CRM #{$crmClientId}: " . json_encode($err));
            $store->updateOne('kyc_applications.json', 'id', $appId, ['quote_error' => json_encode($err)]);
            return ['created' => false, 'id' => null, 'ref' => ''];
        }

        $quoteId = $resp['id'];
        // uCRM's own quote number is the reference; fetch it if the POST did not say.
        $number = $resp['number'] ?? null;
        $fetched = null;   // 5.18.102: kept for readBack() below, so the quote is read once
        if (!$number) {
            $fetched = $quoteCrm->get("billing/quotes/{$quoteId}");
            $number  = $fetched['number'] ?? null;
        }
        if ($quoteOrg['on']) self::readBack($quoteCrm, $store, $appId, $quoteId, $items, $resp, is_array($fetched) ? $fetched : null);   // 5.18.102
        if ($number) {
            $store->updateOne('kyc_applications.json', 'id', $appId, ['quote_ref' => $number]);
        }
        self::sendQuoteViaCrm($quoteCrm, $cfg, method_exists($store, 'getDataDir') ? $store->getDataDir() : null,
            $quoteId, "application #{$appId}");   // D-8, below
        $store->updateOne('kyc_applications.json', 'id', $appId, [
            'quote_id'      => $quoteId,
            'quote_created' => true,
        ]);
        // WhatsApp is deferred to cron_quote_wa: it waits for uCRM's PDF.
        $store->updateOne('kyc_applications.json', 'id', $appId, [
            'wa_quote_pending'     => true,
            'wa_quote_phone'       => preg_replace('/[^0-9+]/', '', $phone),
            'wa_quote_deferred_at' => date('Y-m-d H:i:s'),
        ]);
        error_log("[KycService] Quote #{$quoteId} (" . (string)($number ?? '') . ") deferred to cron_quote_wa with PDF");
        return ['created' => true, 'id' => $quoteId, 'ref' => (string)($number ?? '')];
    }

    /**
     * Increment a username like STAR000052 → STAR000053 or FTTH000237 → FTTH000238.
     * Used when CRM rejects a username as "already taken" (created directly in CRM,
     * not tracked in our local kyc_applications.json sequence).
     */
    public static function generateNextUsername(string $current): string
    {
        if (preg_match('/^([A-Za-z]+)(\d+)$/', $current, $m)) {
            $prefix = $m[1];
            $seq    = (int)$m[2] + 1;
            $padLen = max(6, strlen($m[2]));
            return $prefix . str_pad((string)$seq, $padLen, '0', STR_PAD_LEFT);
        }
        // Manual username or unexpected format — append _2, _3, etc.
        if (preg_match('/_(\d+)$/', $current, $m)) {
            $base = substr($current, 0, -strlen($m[0]));
            return $base . '_' . ((int)$m[1] + 1);
        }
        return $current . '_2';
    }

    /**
     * Upload a file from $_FILES to UCRM under the correct client.
     *
     * UPLOAD FIX (v3.6.1):
     *   - clientId now passed as URL path param (/clients/{id}/documents) ← was wrong endpoint
     *   - document name uses safe human-readable name ← was showing PHP tmpfile basename
     *   - upload result logged to application record for visibility
     *   - errors written to error_log so admin can diagnose
     *
     * B-06 FIX: MIME type validated against allowlist using finfo (server-side),
     * NOT the browser-supplied filename or Content-Type header.
     * Only jpeg/png/gif/webp/pdf accepted — anything else is silently dropped.
     */
    private function uploadFileToCrm(string $crmClientId, array $file, string $docName = 'document'): bool
    {
        $allowedMimes = [
            'image/jpeg'      => 'jpg',
            'image/png'       => 'png',
            'image/gif'       => 'gif',
            'image/webp'      => 'webp',
            'application/pdf' => 'pdf',
        ];

        $tmpPath = $file['tmp_name'] ?? '';
        if ($tmpPath === '' || !file_exists($tmpPath)) {
            error_log("uploadFileToCrm: tmp_name missing or file gone for [{$file['name']}] — client [{$crmClientId}]");
            return false;
        }

        $finfo    = finfo_open(FILEINFO_MIME_TYPE);
        $realMime = finfo_file($finfo, $tmpPath);
        finfo_close($finfo);

        if (!isset($allowedMimes[$realMime])) {
            error_log("uploadFileToCrm: rejected MIME [{$realMime}] for [{$file['name']}] — client [{$crmClientId}]");
            return false;
        }

        // Build safe, human-readable filename with correct extension
        $safeExt  = $allowedMimes[$realMime];
        $safeName = pathinfo($docName, PATHINFO_FILENAME) . '.' . $safeExt;
        // e.g. "Customer Photo.jpg", "ID Proof.pdf"

        $result = $this->crm->upload($tmpPath, ['name' => $safeName], $crmClientId);

        if ($result === null) {
            $err = $this->crm->getLastError();
            error_log("uploadFileToCrm: FAILED [{$safeName}] on client [{$crmClientId}] — " . json_encode($err));
            return false;
        }

        return true;
    }

    private function saveApplication(array $data): int
    {
        // BUG FIX: Use appendWithId() for atomic ID generation under a single
        // exclusive lock, preventing race conditions when concurrent requests
        // both read the same max(id) and produce duplicate IDs.
        // username_seq is computed inside the lock in appendWithId's callback.
        $isFiber = ($data['customer_type'] ?? '') === 'Fiber';

        $record = array_merge($data, [
            'submitted_at'   => date('Y-m-d H:i:s'),
            // MED-02: submission_key is set by caller (from form UUID); stored for dedup
            'submission_key' => $data['submission_key'] ?? null,
        ]);

        // appendWithId acquires LOCK_EX, assigns 'id', appends atomically.
        // We compute username_seq inside a withLock callback so seq is also
        // assigned atomically with the same lock cycle.
        $savedId       = null;
        $savedQuoteSeq = null;
        $this->store->withLock('kyc_applications.json', function (array $apps) use (&$record, &$savedId, &$savedQuoteSeq, $isFiber): array {
            $id = empty($apps) ? 1 : max(array_map(fn($a) => (int)($a['id'] ?? 0), $apps)) + 1;

            $filtered = array_filter($apps, fn($a) => $isFiber
                ? (($a['customer_type'] ?? '') === 'Fiber')
                : (($a['customer_type'] ?? '') !== 'Fiber')
            );
            $maxSeq = empty($filtered) ? 0 : max(array_map(fn($a) => (int)($a['username_seq'] ?? 0), $filtered));

            // Respect seq_start offset (same logic as generateUsername)
            $cfgLk      = $this->store->load('kyc_config.json') ?? [];
            $seqStartLk = $isFiber
                ? max(0, (int)($cfgLk['ftth_seq_start'] ?? 0))
                : max(0, (int)($cfgLk['star_seq_start'] ?? 0));

            $record['id']           = $id;
            $record['username_seq'] = max($maxSeq, $seqStartLk) + 1;

            // Quote ref is now assigned by UCRM after the POST (Option A).
            // We store a temporary placeholder here; KycService::process() overwrites
            // it with the real UCRM number (e.g. PF003847) after the quote is created.
            // This ensures WA and UCRM email always show the same number.
            if (!empty($record['_quote_prefix'])) {
                $record['quote_ref'] = 'PENDING';   // overwritten after UCRM POST
                $record['quote_seq'] = 0;            // not used anymore
                unset($record['_quote_prefix'], $record['_quote_seq_start'], $record['_quote_year']);
            }

            $apps[]  = $record;
            $savedId = $id;
            // withLock requires ['records' => $array, 'result' => $returnValue]
            return ['records' => $apps, 'result' => $id];
        });

        return (int)$savedId;
    }

    // ── Getters for listing ───────────────────────────────────────────────

    /** Get applications for one retailer (or all if admin) */
    public function getApplications(int $retailerId, bool $canViewAll = false): array
    {
        $all = $this->store->load('kyc_applications.json');
        if ($canViewAll) return array_reverse($all);
        $filtered = array_filter($all, fn($a) => (int)($a['retailer_id'] ?? 0) === $retailerId);
        return array_reverse(array_values($filtered));
    }
    
    // ═══════════════════════════════════════════════════════════════════════════
    // STAFF / CASHBOOK HELPERS
    // ═══════════════════════════════════════════════════════════════════════════
    
    /**
     * Check if retailer is a company staff member (creates cashbook entries)
     * 
     * Staff = company employees who collect cash that must be handed over.
     * Non-staff = independent retailers/dealers who use their own funds.
     */
    private function isStaffRetailer(array $retailer): bool
    {
        // Check RBAC role_id first (new system)
        $roleId = $retailer['role_id'] ?? null;
        if ($roleId) {
            try {
                $pdo = $this->store->getPdo();
                $stmt = $pdo->prepare("SELECT is_staff FROM roles WHERE id = ?");
                $stmt->execute([$roleId]);
                $isStaff = $stmt->fetchColumn();
                if ($isStaff !== false) {
                    return (bool)$isStaff;
                }
            } catch (Throwable $e) {
                // Fall through to legacy check
            }
        }
        
        // Legacy: check role slug against known staff roles
        $role = $retailer['role'] ?? 'sales';
        $staffRoles = ['admin', 'support_leader', 'support', 'accountant', 'sales_staff', 'field_agent', 'field_accountant', 'collection', 'employee'];
        return in_array($role, $staffRoles, true);
    }
    
    /**
     * Create cashbook (cb_ledger) entry for cash collected by staff
     */
    private function createCashbookEntry(array $retailer, float $amount, $crmClientId, array $post): void
    {
        try {
            $pdo = $this->store->getPdo();

            // Ensure cb_ledger table exists
            $pdo->exec("CREATE TABLE IF NOT EXISTS cb_ledger (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                project TEXT DEFAULT 'dishnet',
                date TEXT, direction TEXT, amount REAL, currency TEXT DEFAULT 'USD',
                category TEXT, category_raw TEXT, person TEXT, description TEXT,
                validation_ref TEXT, validation_status TEXT DEFAULT 'pending',
                status TEXT DEFAULT 'approved', approved_by TEXT,
                crm_client_id TEXT, source TEXT, created_at TEXT
            )");

            $customerName = trim(($post['firstname'] ?? '') . ' ' . ($post['lastname'] ?? ''));
            $serviceType  = $post['customer_type'] ?? $post['service_type'] ?? 'Starlink';
            $staffName    = $retailer['name'] ?? 'Unknown';

            $stmt = $pdo->prepare(
                "INSERT INTO cb_ledger (project, date, direction, amount, currency, category, category_raw,
                    person, description, validation_ref, validation_status, status, approved_by,
                    crm_client_id, source, created_at)
                 VALUES (?, ?, 'in', ?, ?, 'Receipt', 'KYC Cash Sale',
                    ?, ?, ?, 'pending', 'approved', 'Auto-KYC',
                    ?, 'kyc_cash_sale', ?)"
            );
            $stmt->execute([
                'dishnet',
                date('Y-m-d'),
                $amount,
                dn_book_base($this->store->load('kyc_config.json') ?: []),
                $staffName,
                "Cash collected for {$serviceType} registration: {$customerName}",
                "CRM#{$crmClientId}",
                (string)$crmClientId,
                date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            // Log but don't fail the KYC if cashbook fails
            error_log("KycService: Failed to create cashbook entry: " . $e->getMessage());
        }
    }

    /**
     * Create a UCRM scheduling job for an ADDITIONAL SERVICE at a NEW SITE
     * for an existing customer. Auto-assigned to Bidal (support_leader).
     *
     * Title pattern is intentionally descriptive so Bidal sees at a glance:
     *   "Additional Service — LATJOR KANG (1227) — New Site: Jabel"
     *
     * NEVER throws. Returns the new job ID on success, or 0 on any failure
     * (logged via error_log). Failure here MUST NOT block KYC submission —
     * the quote is the legal commitment; the job is operational scaffolding.
     *
     * 5.18.102 (Uganda, docs/71 §E.2): $ug, the resolved sale, prices the plan from uCRM and says when the customer
     * installs it (kit only); null as before.
     *
     * @return int  New CRM scheduling job ID, or 0 on failure.
     */
    private function createCrmJobForBidal(
        string $customerId,
        array $post,
        string $newAddress,
        string $quoteRef,
        int $appId,
        ?array $ug = null
    ): int {
        try {
            // ── Resolve Bidal's UCRM user ID ──────────────────────────────
            $cfg         = $this->store->load('kyc_config.json') ?: [];
            $retailers   = $this->store->load('retailers.json') ?? [];
            $bidalUcrmId = (int)($cfg['bidal_ucrm_user_id'] ?? 0);

            // Prefer role=support_leader retailer with a ucrm_user_id
            foreach ($retailers as $r) {
                if (($r['role'] ?? '') === 'support_leader' && !empty($r['ucrm_user_id'])) {
                    $bidalUcrmId = (int)$r['ucrm_user_id'];
                    break;
                }
            }
            // Soft-fallback: name match (handles legacy retailers without role set)
            if ($bidalUcrmId <= 0) {
                foreach ($retailers as $r) {
                    if (stripos($r['name'] ?? '', 'bidal') !== false && !empty($r['ucrm_user_id'])) {
                        $bidalUcrmId = (int)$r['ucrm_user_id'];
                        break;
                    }
                }
            }

            // ── Build human-friendly customer display ─────────────────────
            $firstName = trim($post['firstname'] ?? '');
            $lastName  = trim($post['lastname']  ?? '');
            $custDisplay = trim("{$firstName} {$lastName}");
            if ($custDisplay === '') $custDisplay = "Customer #{$customerId}";

            // Truncate area for title (UCRM job titles render in narrow columns)
            $areaShort = mb_substr(trim($newAddress), 0, 40);
            if ($areaShort === '') $areaShort = 'New site';

            $serviceType  = trim($post['customer_type'] ?? '');
            $connectivity = trim($post['connectivity_type'] ?? 'Additional Service');
            $priority     = trim($post['priority'] ?? 'Medium');
            $salesPerson  = $this->resolveSalesPerson($post, []);
            $custPhone    = preg_replace('/[^0-9+]/', '', $post['mobile'] ?? '');

            // ── Resolve plan + amount for description ─────────────────────
            $offer = $this->store->findOne('subscription_plans.json', 'id', (int)($post['package_choice'] ?? 0));
            if (!$offer) $offer = $this->store->findOne('kyc_packages.json', 'id', (int)($post['package_choice'] ?? 0));
            $planName = trim($offer['name'] ?? '-');
            $planAmt  = (float)($offer['customer_price'] ?? $offer['amount'] ?? 0);

            // ── Title: bold, scannable, identifies existing customer ──────
            $title = "Additional Service — {$custDisplay} ({$customerId}) — New Site: {$areaShort}";
            // 5.18.102 (Uganda, docs/71 §E.2): uCRM's monthly price, and a kit-only sale never booked as an installation
            $kitOnly = $ug !== null && ($post['install_by'] ?? '') === 'customer'
                && UcrmQuoteLines::needsInstallAnswer($serviceType, $connectivity);
            if ($kitOnly) $title .= ' — Kit only, no installation';

            // ── Description: full operational brief ───────────────────────
            $descLines = [
                "⚠️ ADDITIONAL SERVICE FOR EXISTING CUSTOMER",
                "",
                "Existing CRM Client : #{$customerId} — {$custDisplay}",
                "New Site Address    : {$newAddress}",
                "Service Type        : {$serviceType}",
                "Connection          : {$connectivity}",
                // 5.18.102 (D14, §R.5): uCRM's plan label beside uCRM's price; the plugin's name only with "not priced in uCRM"
                $ug !== null
                    ? ($ug['plan_price'] !== null
                        ? "Plan                : " . (string)($ug['plan_label'] ?? $planName) . ' (' . dn_money((float)$ug['plan_price'], $cfg, 0) . '/mo)'
                        : "Plan                : {$planName} (not priced in uCRM)")
                    : "Plan                : {$planName} (\${$planAmt}/mo)",
                "Priority            : {$priority}",
                "Customer Phone      : {$custPhone}",
                "Sales Person        : {$salesPerson}",
                "Quote Ref           : {$quoteRef}",
                "Plugin App ID       : {$appId}",
                "",
                "ACTION:",
                "1. Verify new site address & GPS",
                "2. Create a NEW service line on existing client #{$customerId}",
                "3. DO NOT modify existing service(s) — leave current site intact",
                $kitOnly ? "4. Kit only — no installation: the customer installs it" : "4. Schedule install per priority",
            ];
            $description = implode("\n", $descLines);

            // ── Build payload ─────────────────────────────────────────────
            // Schedule date = tomorrow 09:00 (Bidal can re-schedule from CRM)
            $tomorrow = date('Y-m-d', strtotime('+1 day'));
            $payload = [
                'title'       => $title,
                'date'        => $tomorrow . 'T09:00:00.000Z',
                'duration'    => 60,
                'description' => $description,
                'status'      => 1, // Open
                'clientId'    => (int)$customerId,
            ];
            if ($bidalUcrmId > 0) {
                $payload['assignedUserId'] = $bidalUcrmId;
            } else {
                error_log("[KycService] createCrmJobForBidal: no support_leader ucrm_user_id found — job will be unassigned (appId={$appId})");
            }
            if ($newAddress !== '') $payload['address'] = $newAddress;

            $lat = trim((string)($post['latitude']  ?? ''));
            $lon = trim((string)($post['longitude'] ?? ''));
            if ($lat !== '' && $lon !== '' && is_numeric($lat) && is_numeric($lon)) {
                $payload['gpsLat'] = (float)$lat;
                $payload['gpsLon'] = (float)$lon;
            }

            // ── POST to UCRM scheduling endpoint ──────────────────────────
            // Use the same auth pattern as the quote creation above:
            // prefer config-supplied user token (admin-level), fall back to
            // $this->crm (which may be the agent's UCRM app key via withCrm()).
            // Agents with valid app keys can create scheduling jobs.
            $jobToken = trim($cfg['crm_auth_token'] ?? '');
            $jobUrl   = trim($cfg['crm_base_url']   ?? '');
            $jobCrm   = ($jobToken !== '')
                ? new CrmApiClient(
                    $jobUrl !== '' ? $jobUrl : rtrim($this->crm->getBaseUrl(), '/'),
                    $jobToken,
                    'x-auth-token'
                  )
                : $this->crm;

            $newJob = $jobCrm->post('scheduling/jobs', $payload);
            if (!$newJob || empty($newJob['id'])) {
                error_log('[KycService] createCrmJobForBidal: CRM job creation failed (appId=' . $appId . ') — ' . json_encode($jobCrm->getLastError()));
                return 0;
            }
            $newJobId = (int)$newJob['id'];

            // ── Add a checklist task to the job ───────────────────────────
            try {
                $jobCrm->post("scheduling/jobs/{$newJobId}/job-tasks", [
                    'name' => "Verify site, create new service line on client #{$customerId}, schedule install",
                ]);
            } catch (\Throwable $e) {
                // Non-fatal — task is cosmetic
                error_log('[KycService] createCrmJobForBidal: task add failed for job #' . $newJobId . ': ' . $e->getMessage());
            }

            error_log("[KycService] CRM scheduling job #{$newJobId} created (additional service, appId={$appId}, client #{$customerId}, assigned uid:{$bidalUcrmId})");
            return $newJobId;

        } catch (\Throwable $e) {
            error_log('[KycService] createCrmJobForBidal exception: ' . $e->getMessage());
            return 0;
        }
    }
}
