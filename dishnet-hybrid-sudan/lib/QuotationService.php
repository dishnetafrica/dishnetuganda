<?php
declare(strict_types=1);

// PHP 7.4 polyfills
if (!function_exists('str_contains'))  { function str_contains(string $h, string $n): bool  { return $n===''||strpos($h,$n)!==false; } }
if (!function_exists('str_starts_with')){ function str_starts_with(string $h, string $n): bool { return $n===''||strncmp($h,$n,strlen($n))===0; } }
if (!function_exists('str_ends_with')) { function str_ends_with(string $h, string $n): bool  { return $n===''||substr($h,-strlen($n))===$n; } }

require_once __DIR__ . '/NotificationService.php';
require_once __DIR__ . '/CrmApiClient.php';

/**
 * QuotationService — DishNet Hybrid v4.4.20
 *
 * Handles all quotation flows:
 *
 *   A) KYC registration  → quote already created in UCRM → send WA to customer
 *   B) Lead quote        → build proforma text + optional UCRM quote → WA customer
 *   C) Cash sale         → instant proforma WA (no CRM client required)
 *   D) Manual trigger    → agent picks customer + items → WA + optional UCRM
 *
 * Quote lifecycle stored in quotes_log.json:
 *   id, quote_ref, type (kyc|lead|cash|manual), customer_name, customer_phone,
 *   crm_client_id, crm_quote_id, items[], total, currency, valid_until,
 *   sent_via_wa, sent_via_crm, sent_by (retailer name), created_at
 *
 * PHP 7.4 compatible.
 */
class QuotationService
{
    const LOG_FILE     = 'quotes_log.json';
    // Historical constant; the live code derives the code from config.
    const CURRENCY     = 'USD';
    const VALIDITY_DAYS = 7;

    // DishNet branding for WA proforma
    const COMPANY_NAME  = 'DishNet Africa';
    const COMPANY_PHONE = '+211920000000';  // override via config: quote_company_phone
    const COMPANY_EMAIL = 'info@dishnetafrica.com';

    private \PDO   $pdo;
    private        $store;
    private string $dataDir;
    private array  $config;
    private NotificationService $ns;
    private CrmApiClient $crm;
    /** @var array<string,array> organization lookups, memoised for this instance */
    private array $orgMemo = [];
    /** Why uCRM was not the source, when it was not. Empty means it was. */
    private string $orgError = '';

    public function __construct($store, string $dataDir, array $config = [])
    {
        $this->store   = $store;
        $this->dataDir = rtrim($dataDir, '/');
        // Load config from store if not provided directly
        if (empty($config)) {
            $config = $store->load('kyc_config.json') ?: [];
        }
        $this->config = $config;
        $this->ns  = new NotificationService($store, $config);
        // The plugin root is where THIS FILE lives, not somewhere up from the
        // data directory. The old derivation walked up from $dataDir with the
        // comment "data/ is inside plugin root", and that stopped being true
        // when bootstrap_data.php moved the data directory to a SIBLING of the
        // plugin so it would survive uCRM upgrades — uCRM replaces the plugin
        // directory wholesale, and the database used to go with it.
        //
        // When the walk misses, fromUcrm() gets a root with no ucrm.json,
        // finds no credentials, and every uCRM call from this class fails
        // quietly: branding falls back to config, and createCrmQuote() cannot
        // post a quote at all. lib/ is inside the plugin root by definition,
        // so this cannot miss.
        $this->crm = CrmApiClient::fromUcrm(dirname(__DIR__), $config);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // FLOW A — KYC Quote WhatsApp delivery
    // Called right after KycService creates the UCRM quote on registration.
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * Send WA proforma to customer after KYC registration.
     * The UCRM quote already exists — we just send WA with the summary.
     *
     * @param array $application  The saved KYC application record
     * @param array $quoteItems   Line items [{label, quantity, price, unit}]
     * @param int   $crmQuoteId   UCRM quote ID
     * @param array $retailer     Logged-in agent
     */
    public function sendKycQuoteWhatsApp(array $application, array $quoteItems, int $crmQuoteId, array $retailer): bool
    {
        $phone = preg_replace('/[^0-9+]/', '', $application['mobile'] ?? $application['phone'] ?? '');
        if (!$phone) return false;

        $name     = trim(($application['firstname'] ?? '') . ' ' . ($application['lastname'] ?? '')) ?: ($application['customer_name'] ?? 'Valued Customer');
        $quoteRef = $application['quote_ref'] ?? ('QUO-' . $crmQuoteId);
        $total    = $this->itemsTotal($quoteItems);

        $msg = $this->buildProformaMessage($quoteRef, $name, $quoteItems, $total, [
            'type'    => 'New Connection',
            'via_crm' => true,
            'agent'   => $retailer['name'] ?? '',
        ]);

        $sent = $this->sendWA($phone, $msg, 'quote_kyc');

        $this->logQuote([
            'type'           => 'kyc',
            'quote_ref'      => $quoteRef,
            'crm_client_id'  => (int)($application['crm_client_id'] ?? 0),
            'crm_quote_id'   => $crmQuoteId,
            'customer_name'  => $name,
            'customer_phone' => $phone,
            'items'          => $quoteItems,
            'total'          => $total,
            'sent_via_wa'    => $sent,
            'sent_via_crm'   => true,
            'sent_by'        => $retailer['name'] ?? '',
            'valid_until'    => date('Y-m-d', strtotime('+' . self::VALIDITY_DAYS . ' days')),
        ]);

        return $sent;
    }

    // ══════════════════════════════════════════════════════════════════════════
    // FLOW B — Lead Quote (before KYC — just interest stage)
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * Send a quotation for a lead (no CRM client yet).
     * Builds proforma from interest_plan + service_type on the lead record.
     * Optionally creates a UCRM quote if lead has a crm_client_id already.
     *
     * @param array  $lead      Lead record from leads.json
     * @param array  $items     Override line items — if empty, auto-built from lead
     * @param array  $retailer  Logged-in agent
     * @param bool   $createCrmQuote  Push to UCRM billing/quotes if crm_client_id exists
     */
    public function sendLeadQuote(array $lead, array $items, array $retailer, bool $createCrmQuote = false): array
    {
        $phone = preg_replace('/[^0-9+]/', '', $lead['phone'] ?? $lead['mobile'] ?? '');
        $name  = $lead['customer_name'] ?? 'Valued Customer';

        // Auto-build items from lead interest if none provided
        if (empty($items)) {
            $items = $this->buildLeadItems($lead);
        }

        if (empty($items)) {
            return ['ok' => false, 'error' => 'No pricing items could be determined for this lead. Please add items manually.'];
        }

        $total    = $this->itemsTotal($items);
        $quoteRef = $this->nextQuoteRef('LEAD');
        $crmQuoteId = null;
        $sentViaCrm = false;

        // Optionally push to UCRM if lead already has a CRM client ID
        $crmClientId = (int)($lead['crm_client_id'] ?? 0);
        if ($createCrmQuote && $crmClientId > 0) {
            $result = $this->createCrmQuote($crmClientId, $items, $quoteRef, $retailer);
            if ($result['ok']) {
                $crmQuoteId = $result['quote_id'];
                $sentViaCrm = true;
                // Use UCRM's number as canonical ref (e.g. "Q-00042")
                if (!empty($result['quote_number'])) {
                    $quoteRef = $result['quote_number'];
                }
            }
        }

        // Send WA to customer
        $waSent = false;
        if ($phone) {
            $msg    = $this->buildProformaMessage($quoteRef, $name, $items, $total, [
                'type'       => 'Quotation — ' . ucfirst($lead['service_type'] ?? 'Service'),
                'via_crm'    => $sentViaCrm,
                'agent'      => $retailer['name'] ?? '',
                'note'       => $lead['notes'] ?? '',
                'follow_up'  => $lead['follow_up_date'] ?? '',
            ]);
            $waSent = $this->sendWA($phone, $msg, 'quote_lead');
        }

        // Update lead status to 'quoted'
        $leads = $this->store->load('leads.json') ?? [];
        foreach ($leads as &$l) {
            if ((int)($l['id'] ?? 0) === (int)($lead['id'] ?? 0)) {
                $l['status']     = 'quoted';
                $l['quote_ref']  = $quoteRef;
                $l['quoted_at']  = date('Y-m-d H:i:s');
                $l['quoted_by']  = $retailer['name'] ?? '';
                if ($crmQuoteId) $l['crm_quote_id'] = $crmQuoteId;
                break;
            }
        }
        unset($l);
        $this->store->save('leads.json', $leads);

        $this->logQuote([
            'type'           => 'lead',
            'quote_ref'      => $quoteRef,
            'crm_client_id'  => $crmClientId,
            'crm_quote_id'   => $crmQuoteId,
            'customer_name'  => $name,
            'customer_phone' => $phone,
            'items'          => $items,
            'total'          => $total,
            'sent_via_wa'    => $waSent,
            'sent_via_crm'   => $sentViaCrm,
            'sent_by'        => $retailer['name'] ?? '',
            'valid_until'    => date('Y-m-d', strtotime('+' . self::VALIDITY_DAYS . ' days')),
        ]);

        return [
            'ok'           => true,
            'quote_ref'    => $quoteRef,
            'sent_via_wa'  => $waSent,
            'sent_via_crm' => $sentViaCrm,
            'crm_quote_id' => $crmQuoteId,
            'total'        => $total,
        ];
    }

    // ══════════════════════════════════════════════════════════════════════════
    // FLOW C — Cash Sale Proforma (walk-in, instant WA)
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * Send instant proforma for a cash walk-in sale.
     * No CRM client required — phone number is enough.
     *
     * @param string $customerName
     * @param string $customerPhone
     * @param array  $items          [{label, quantity, price, unit}]
     * @param float  $amountPaid     Cash already collected
     * @param array  $retailer       Logged-in agent
     */
    public function sendCashSaleProforma(
        string $customerName,
        string $customerPhone,
        array  $items,
        float  $amountPaid,
        array  $retailer
    ): array {
        $phone = preg_replace('/[^0-9+]/', '', $customerPhone);
        if (!$phone) return ['ok' => false, 'error' => 'Customer phone number required.'];
        if (empty($items)) return ['ok' => false, 'error' => 'At least one item required.'];

        $total    = $this->itemsTotal($items);
        $balance  = round($total - $amountPaid, 2);
        $quoteRef = $this->nextQuoteRef('CASH');

        $msg = $this->buildProformaMessage($quoteRef, $customerName, $items, $total, [
            'type'        => 'Cash Sale Receipt',
            'amount_paid' => $amountPaid,
            'balance'     => $balance,
            'agent'       => $retailer['name'] ?? '',
        ]);

        $sent = $this->sendWA($phone, $msg, 'quote_cash');

        $this->logQuote([
            'type'           => 'cash',
            'quote_ref'      => $quoteRef,
            'crm_client_id'  => 0,
            'crm_quote_id'   => null,
            'customer_name'  => $customerName,
            'customer_phone' => $phone,
            'items'          => $items,
            'total'          => $total,
            'amount_paid'    => $amountPaid,
            'balance_due'    => $balance,
            'sent_via_wa'    => $sent,
            'sent_via_crm'   => false,
            'sent_by'        => $retailer['name'] ?? '',
            'valid_until'    => date('Y-m-d'),
        ]);

        return [
            'ok'          => true,
            'quote_ref'   => $quoteRef,
            'sent_via_wa' => $sent,
            'total'       => $total,
            'balance_due' => $balance,
        ];
    }

    // ══════════════════════════════════════════════════════════════════════════
    // FLOW D — Manual Quote (agent triggers for any existing CRM customer)
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * Agent manually triggers a quote for any customer.
     * Requires: crm_client_id OR customer_phone + customer_name.
     *
     * @param array $data [
     *   'crm_client_id'   => int (optional — fetches phone from CRM if set),
     *   'customer_name'   => string,
     *   'customer_phone'  => string,
     *   'items'           => [{label, quantity, price, unit}],
     *   'note'            => string (optional footer note),
     *   'create_crm_quote'=> bool (default true if crm_client_id set),
     * ]
     * @param array $retailer
     */
    public function sendManualQuote(array $data, array $retailer): array
    {
        $crmClientId   = (int)($data['crm_client_id'] ?? 0);
        $customerName  = trim($data['customer_name'] ?? '');
        $customerPhone = trim($data['customer_phone'] ?? '');
        $items         = $data['items'] ?? [];
        $note          = trim($data['note'] ?? '');
        $createCrm     = ($data['create_crm_quote'] ?? ($crmClientId > 0));

        if (empty($items)) return ['ok' => false, 'error' => 'At least one line item required.'];

        // If CRM client ID given, fetch name/phone from CRM if not provided
        if ($crmClientId > 0 && (!$customerName || !$customerPhone)) {
            try {
                $crmClient = $this->crm->get("clients/{$crmClientId}");
                if ($crmClient) {
                    if (!$customerName)  $customerName  = trim(($crmClient['firstName'] ?? '') . ' ' . ($crmClient['lastName'] ?? ''));
                    if (!$customerPhone) $customerPhone = $crmClient['contacts'][0]['phone'] ?? $crmClient['contacts'][0]['value'] ?? '';
                }
            } catch (\Throwable $e) { /* CRM unreachable — proceed with what we have */ }
        }

        if (!$customerName)  return ['ok' => false, 'error' => 'Customer name required.'];
        if (!$customerPhone) return ['ok' => false, 'error' => 'Customer phone required (or provide crm_client_id to auto-fetch).'];

        $phone    = preg_replace('/[^0-9+]/', '', $customerPhone);
        $total    = $this->itemsTotal($items);
        $quoteRef = $this->nextQuoteRef('MAN');
        $crmQuoteId = null;
        $sentViaCrm = false;

        if ($createCrm && $crmClientId > 0) {
            $result = $this->createCrmQuote($crmClientId, $items, $quoteRef, $retailer, $note);
            if ($result['ok']) {
                $crmQuoteId = $result['quote_id'];
                $sentViaCrm = true;
                // Use UCRM's number as canonical ref
                if (!empty($result['quote_number'])) {
                    $quoteRef = $result['quote_number'];
                }
            }
        }

        $msg    = $this->buildProformaMessage($quoteRef, $customerName, $items, $total, [
            'type'    => 'Quotation',
            'via_crm' => $sentViaCrm,
            'agent'   => $retailer['name'] ?? '',
            'note'    => $note,
        ]);
        $waSent = $this->sendWA($phone, $msg, 'quote_manual');

        $this->logQuote([
            'type'           => 'manual',
            'quote_ref'      => $quoteRef,
            'crm_client_id'  => $crmClientId,
            'crm_quote_id'   => $crmQuoteId,
            'customer_name'  => $customerName,
            'customer_phone' => $phone,
            'items'          => $items,
            'total'          => $total,
            'sent_via_wa'    => $waSent,
            'sent_via_crm'   => $sentViaCrm,
            'sent_by'        => $retailer['name'] ?? '',
            'valid_until'    => date('Y-m-d', strtotime('+' . self::VALIDITY_DAYS . ' days')),
        ]);

        return [
            'ok'           => true,
            'quote_ref'    => $quoteRef,
            'sent_via_wa'  => $waSent,
            'sent_via_crm' => $sentViaCrm,
            'crm_quote_id' => $crmQuoteId,
            'total'        => $total,
        ];
    }

    // ══════════════════════════════════════════════════════════════════════════
    // SHARED HELPERS
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * Build the WhatsApp proforma message.
     * Produces a clean, structured WA message that renders well on mobile.
     */
    public function buildProformaMessage(
        string $quoteRef,
        string $customerName,
        array  $items,
        float  $total,
        array  $opts = []
    ): string {
        $type       = $opts['type']        ?? 'Quotation';
        $viaCrm     = $opts['via_crm']     ?? false;
        $agent      = $opts['agent']       ?? '';
        $agentPhone = $opts['agent_phone'] ?? '';
        $note       = $opts['note']        ?? '';
        $followUp   = $opts['follow_up']   ?? '';
        $amountPaid = isset($opts['amount_paid']) ? (float)$opts['amount_paid'] : null;
        $balance    = isset($opts['balance'])     ? (float)$opts['balance']     : null;
        $co         = $this->companyDetails(isset($opts['client_id']) ? (int)$opts['client_id'] : null);
        $company    = $co['name'];
        $compPhone  = $co['phone'];
        $compEmail  = $co['email'];
        foreach ($co['_warnings'] as $w) {
            error_log('[QuotationService] company details: ' . $w);
        }
        $validDays  = (int)($this->config['kyc_quote_validity_days'] ?? self::VALIDITY_DAYS);
        $validUntil = date('d M Y', strtotime("+{$validDays} days"));

        // ── Header ──────────────────────────────────────────────────────────
        $lines = [];
        $lines[] = "━━━━━━━━━━━━━━━━━━━━━━";
        $lines[] = "🏢 *{$company}*";
        $lines[] = "📄 *{$type}*";
        $lines[] = "━━━━━━━━━━━━━━━━━━━━━━";
        $lines[] = "";
        $lines[] = "📋 *Ref:* {$quoteRef}";
        $lines[] = "👤 *To:* {$customerName}";
        $lines[] = "📅 *Date:* " . date('d M Y');
        $lines[] = "⏳ *Valid Until:* {$validUntil}";
        $lines[] = "";

        // ── Line items ───────────────────────────────────────────────────────
        $lines[] = "━━━━━━━━━━━━━━━━━━━━━━";
        $lines[] = "*ITEMS*";
        $lines[] = "━━━━━━━━━━━━━━━━━━━━━━";
        foreach ($items as $item) {
            $qty       = max(1, (int)($item['quantity'] ?? 1));
            $price     = (float)($item['price'] ?? 0);
            $lineTotal = round($qty * $price, 2);
            $unit      = $item['unit'] ?? '';
            $unitLabel = $unit && $unit !== 'amount' ? " / {$unit}" : '';
            $label     = $item['label'] ?? 'Item';
            $c = dn_cur($this->config);
            if ($qty > 1) {
                $lines[] = "• {$label}";
                $lines[] = "  {$qty} × {$c}{$price}{$unitLabel} = *{$c}{$lineTotal}*";
            } else {
                $lines[] = "• {$label}: *{$c}{$lineTotal}{$unitLabel}*";
            }
        }
        $lines[] = "";
        $lines[] = "━━━━━━━━━━━━━━━━━━━━━━";
        $lines[] = "💰 *TOTAL: " . dn_cur($this->config) . "{$total}*";

        // ── Payment info ────────────────────────────────────────────────────
        if ($amountPaid !== null) {
            $lines[] = "✅ *Paid: " . dn_cur($this->config) . "{$amountPaid}*";
            if ($balance !== null && $balance > 0) {
                $lines[] = "⚠️ *Balance Due: " . dn_cur($this->config) . "{$balance}*";
            } elseif ($balance !== null && $balance <= 0) {
                $lines[] = "✅ *Fully Paid*";
            }
        }
        $lines[] = "━━━━━━━━━━━━━━━━━━━━━━";

        // ── Notes / CRM status ──────────────────────────────────────────────
        if ($note) {
            $lines[] = "";
            $lines[] = "📝 " . $note;
        }
        if ($viaCrm) {
            $lines[] = "";
            $lines[] = "📧 _A formal quote has also been sent to your email via our billing system._";
        }
        if ($followUp) {
            $lines[] = "";
            $lines[] = "📞 _We'll follow up with you on " . date('d M Y', strtotime($followUp)) . "._";
        }

        // ── Footer ──────────────────────────────────────────────────────────
        $lines[] = "";
        if ($agent && $agentPhone) {
            $lines[] = "👨‍💼 Agent: {$agent}";
            $lines[] = "📞 {$agentPhone}";
        } elseif ($agent) {
            $lines[] = "👨‍💼 Agent: {$agent}";
            $lines[] = "📞 {$compPhone}";
        } else {
            $lines[] = "📞 {$compPhone}";
        }
        $lines[] = "✉️  {$compEmail}";
        $lines[] = "";
        $lines[] = "_Thank you for choosing {$company}!_ 🌐";

        return implode("\n", $lines);
    }

    /**
     * Push a quote to UCRM billing/quotes and immediately send it via UCRM.
     */
    /**
     * Whose details go on this quotation.
     *
     * The three constants at the top of this class are South Sudan's, and an
     * unset config key did not mean "blank" — it meant Juba's phone number
     * printed on a Ugandan customer's quote as the number to call. That was
     * live until today.
     *
     * uCRM already holds the right answer and always did: organization 1 on
     * this install carries DishNet Africa Limited, +256705993348,
     * accounts@dishnetuganda.com, the Acacia Mall address, the URA TIN and the
     * bank details. Duplicating that into plugin config was the same mistake
     * as typing prices into the prompt — a second source of truth that nobody
     * remembers to update.
     *
     * WHICH organization is not a guess. A quote is issued for a client, and a
     * client carries organizationId. Without a client — a lead quote — the one
     * uCRM marks `selected` is used. (lib/FtthCrmService.php's note about "Org
     * 2 / Org 7" describes the South Sudan install; this one has a single
     * organization at id 1.)
     *
     * The fallback is PER FIELD, not per source. uCRM holding a name but no
     * phone must not drag the name back down to the constant with it.
     *
     *   1. the client's organization in uCRM
     *   2. explicit plugin config      quote_company_{name,phone,email}
     *   3. the compiled constant       — recorded as a fault, never silent
     *
     * @return array{name:string,phone:string,email:string,website:string,
     *               address:string,tax_id:string,registration_number:string,
     *               bank_name:string,bank_1:string,bank_2:string,logo_url:string,
     *               _source:array<string,string>,_warnings:array<string>}
     */
    public function companyDetails(?int $clientId = null): array
    {
        $org = $this->resolveOrganization($clientId);

        $src = []; $warn = [];
        // [uCRM key, config key, compiled constant]
        $map = [
            'name'  => ['name',  'quote_company_name',  self::COMPANY_NAME],
            'phone' => ['phone', 'quote_company_phone', self::COMPANY_PHONE],
            'email' => ['email', 'quote_company_email', self::COMPANY_EMAIL],
        ];
        $out = [];
        foreach ($map as $field => [$orgKey, $cfgKey, $const]) {
            $fromOrg = trim((string)($org[$orgKey] ?? ''));
            $fromCfg = trim((string)($this->config[$cfgKey] ?? ''));
            if ($fromOrg !== '')      { $out[$field] = $fromOrg; $src[$field] = 'ucrm'; }
            elseif ($fromCfg !== '')  { $out[$field] = $fromCfg; $src[$field] = 'config'; }
            else {
                $out[$field] = $const; $src[$field] = 'constant';
                $warn[] = "{$field} fell through to the built-in default \"{$const}\" — "
                        . "uCRM has no {$orgKey} and {$cfgKey} is unset";
            }
        }

        // Fields uCRM holds that nothing was reading. No constant for these:
        // absent is absent, and the caller omits the line rather than inventing it.
        foreach (['website' => 'website', 'tax_id' => 'taxId',
                  'registration_number' => 'registrationNumber',
                  'bank_name' => 'bankAccountName', 'bank_1' => 'bankAccountField1',
                  'bank_2' => 'bankAccountField2', 'logo_url' => 'logoUrl'] as $k => $orgKey) {
            $out[$k] = trim((string)($org[$orgKey] ?? ''));
            if ($out[$k] !== '') $src[$k] = 'ucrm';
        }

        $addr = array_values(array_filter([
            trim((string)($org['street1'] ?? '')), trim((string)($org['street2'] ?? '')),
            trim((string)($org['city'] ?? '')),    trim((string)($org['zipCode'] ?? '')),
        ], fn($v) => $v !== ''));
        $out['address'] = implode(', ', $addr);
        if ($out['address'] !== '') $src['address'] = 'ucrm';

        $out['_source']    = $src;
        $out['_org_error'] = $this->orgError;
        $out['_warnings'] = $warn;
        return $out;
    }

    /**
     * The organization a quote belongs to, memoised for the request.
     *
     * Never organizations[0]: on an install with more than one, that is a coin
     * toss between two companies' letterheads. Client first, then whichever
     * uCRM itself marks selected.
     */
    private function resolveOrganization(?int $clientId): array
    {
        // Per instance, not static. A static memo would outlive the config that
        // produced it, so a second QuotationService built with different
        // settings in the same process would silently answer from the first
        // one's uCRM. Within a single quote it is the same organization either
        // way, which is all the memo is for.
        $key = $clientId === null ? 'default' : ('c' . $clientId);
        if (isset($this->orgMemo[$key])) return $this->orgMemo[$key];

        $org = [];
        try {
            $orgId = null;
            if ($clientId !== null && $clientId > 0) {
                $client = $this->crm->get("clients/{$clientId}");
                $orgId  = isset($client['organizationId']) ? (int)$client['organizationId'] : null;
            }
            $all = $this->crm->get('organizations') ?? [];
            foreach ((array)$all as $o) {
                if (!is_array($o)) continue;
                if ($orgId !== null && (int)($o['id'] ?? 0) === $orgId) { $org = $o; break; }
                if ($orgId === null && !empty($o['selected']))          { $org = $o; break; }
            }
            // uCRM marks none as selected and we have no client: only then is
            // taking the first one reasonable, and only because there is one.
            if (!$org && $orgId === null && count((array)$all) === 1 && is_array($all[0])) {
                $org = $all[0];
            }
        } catch (\Throwable $e) {
            // uCRM unreachable degrades to config, never to the constant
            // silently — companyDetails() records the source either way.
            $this->orgError = $e->getMessage();
            $org = [];
        }
        if (!$org && $this->orgError === '') {
            $this->orgError = $this->crm->isConfigured()
                ? 'uCRM returned no matching organization'
                : 'uCRM credentials not resolved for this plugin root';
        }
        return $this->orgMemo[$key] = $org;
    }

    public function createCrmQuote(int $crmClientId, array $items, string $quoteRef, array $retailer, string $note = ''): array
    {
        $validityDays = (int)($this->config['kyc_quote_validity_days'] ?? self::VALIDITY_DAYS);
        $notesPrefix  = trim($this->config['kyc_quote_notes_prefix']  ?? '');
        $agentNote    = "Ref: {$quoteRef} | Agent: " . ($retailer['name'] ?? 'Agent');
        $fullNote     = trim(($notesPrefix ? $notesPrefix . "\n" : '') . $agentNote . ($note ? "\n" . $note : ''));

        $payload = [
            'clientId'            => $crmClientId,
            'invoiceMaturityDays' => $validityDays,
            'notes'               => $fullNote,
            'adminNotes'          => "Plugin ref: {$quoteRef}",
            'invoiceItems'        => $items,
        ];

        try {
            $resp = $this->crm->post('billing/quotes', $payload);
            if (!empty($resp['id'])) {
                $quoteId    = (int)$resp['id'];
                // Use UCRM's number (e.g. PF003847) — fetch if not in POST response
                $ucrmNumber = $resp['number'] ?? null;
                if (!$ucrmNumber) {
                    $fetched    = $this->crm->get("billing/quotes/{$quoteId}");
                    $ucrmNumber = $fetched['number'] ?? null;
                }
                // Who emails the customer? When the operator has switched
                // quotation email to the plugin (Settings → System → Email),
                // we fetch the PDF and send it ourselves through MailService —
                // no uCRM mailer involved. Toggle absent or any plugin-send
                // failure falls back to uCRM's own /send, the historical
                // behaviour, so a quote never goes silently unemailed.
                $viaPlugin = false; $emailErr = '';
                // Same source MailService itself reads, so the toggle and the
                // SMTP config can never disagree about which file is truth.
                $ef   = $this->dataDir . '/email_settings.json';
                $eset = is_file($ef) ? (json_decode((string)@file_get_contents($ef), true) ?: []) : [];
                if (!empty($eset['quote_email_via_plugin'])) {
                    [$viaPlugin, $emailErr] = $this->emailQuotePdf(
                        $quoteId, (string)($ucrmNumber ?: $quoteRef), $crmClientId, $retailer,
                        $this->itemsTotal($items)
                    );
                }
                if (!$viaPlugin) {
                    // Send via UCRM (triggers email to customer)
                    $this->crm->patch("billing/quotes/{$quoteId}/send");
                }
                return ['ok' => true, 'quote_id' => $quoteId, 'quote_number' => $ucrmNumber,
                        'emailed_by_plugin' => $viaPlugin, 'email_error' => $emailErr];
            }
            return ['ok' => false, 'error' => 'UCRM did not return quote ID.'];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Email the quotation PDF straight from the plugin's mail system.
     *
     * Returns [sent, error]. Never throws: any failure returns [false, why]
     * and the caller falls back to uCRM's /send.
     */
    /**
     * The database handle for the once-only email claim.
     *
     * $this->pdo is a typed property that only some construction paths set,
     * and reading it unset throws — which was swallowed by the catch below and
     * came back as "could not send", quietly handing the email to uCRM. The
     * store is always there.
     */
    private function emailPdo(): \PDO
    {
        return $this->store->getPdo();
    }

    protected function emailQuotePdf(int $quoteId, string $number, int $crmClientId, array $retailer, float $total = 0.0): array
    {
        try {
            $mail = $this->newMailService();
            if (!$mail->getConfig()) {
                return [false, 'plugin mail is not configured (Settings → System → Email)'];
            }

            // Recipient: the customer's billing contact, else any contact email.
            $client = $this->crm->get("clients/{$crmClientId}") ?: [];
            $name = trim((string)(($client['firstName'] ?? '') . ' ' . ($client['lastName'] ?? '')));
            if ($name === '') $name = (string)($client['companyName'] ?? '');
            $email = '';
            $contacts = is_array($client['contacts'] ?? null) ? $client['contacts'] : [];
            foreach ($contacts as $c) {
                if (!empty($c['isBilling']) && !empty($c['email'])) { $email = (string)$c['email']; break; }
            }
            if ($email === '') {
                foreach ($contacts as $c) {
                    if (!empty($c['email'])) { $email = (string)$c['email']; break; }
                }
            }
            if ($email === '') return [false, 'customer has no email address in uCRM'];

            // One place decides where the PDF comes from, because the quote
            // email now has two entry points — here, and the quote.add webhook
            // for quotes created in uCRM's own screen.
            // Claim it before doing the work: quote.add fires for this very
            // quote and would otherwise send the customer a second copy.
            require_once __DIR__ . '/CustomerEmailDispatcher.php';
            if (!CustomerEmailDispatcher::claimOnce($this->emailPdo(), "QEMAIL{$quoteId}")) {
                return [false, 'the quotation email for this quote was already sent'];
            }

            require_once __DIR__ . '/QuotePdfSource.php';
            [$pdf, $pdfSource] = QuotePdfSource::fetch(
                $this->crm, $this->dataDir, $this->config, $quoteId, $client);
            // (the quote is fetched inside when not supplied — this path does
            //  not hold one at this point)
            if ($pdf === '') {
                CustomerEmailDispatcher::releaseClaim($this->emailPdo(), "QEMAIL{$quoteId}");
                return [false, 'uCRM served no quotation PDF and the plugin could not render one'];
            }

            $days  = (int)($this->config['kyc_quote_validity_days'] ?? self::VALIDITY_DAYS);
            $cmail = $this->companyDetails($crmClientId)['email'];

            // One shell for every customer email (docs/UGANDA-EMAIL-LIFECYCLE-AUDIT.md):
            // branded header/footer, mobile and dark-mode ready, plain-text twin,
            // and the money + next-steps the plain version never carried.
            require_once __DIR__ . '/CustomerEmails.php';
            $built = CustomerEmails::quotation($this->config, [
                'customer_name' => $name !== '' ? $name : 'Customer',
                'quote_number'  => $number,
                'total'         => $total > 0 ? $total : '',
                'valid_days'    => $days,
            ]);
            $subject = $built['subject'];

            $send = $mail->send($email, $name !== '' ? $name : 'Customer',
                $subject, $built['html'], $built['text'],
                ['Reply-To' => $cmail],
                [[
                    'name'    => 'Quotation-' . (preg_replace('/[^A-Za-z0-9_\-]/', '', $number) ?: 'quote') . '.pdf',
                    'mime'    => 'application/pdf',
                    'content' => $pdf,
                ]]
            );
            if (empty($send['ok'])) {
                CustomerEmailDispatcher::releaseClaim($this->emailPdo(), "QEMAIL{$quoteId}");
                return [false, (string)($send['error'] ?? 'send failed')];
            }

            @file_put_contents($this->dataDir . '/quote_mail.log',
                '[' . gmdate('Y-m-d H:i:s') . "] {$number} -> {$email} sent\n", FILE_APPEND | LOCK_EX);
            // Not an error, but worth recording: a plugin-rendered PDF means
            // uCRM served none, which the operator should know about.
            return [true, $pdfSource === 'plugin'
                ? 'sent with a plugin-rendered PDF (uCRM served none)' : ''];
        } catch (\Throwable $e) {
            return [false, $e->getMessage()];
        }
    }

    /** Seam for tests: real callers get a MailService on the plugin data dir. */
    protected function newMailService(): MailService
    {
        require_once __DIR__ . '/MailService.php';
        return new MailService($this->dataDir);
    }

    /**
     * Auto-build quote items from a lead record using subscription_plans / kyc_packages.
     */
    public function buildLeadItems(array $lead): array
    {
        $items = [];
        $serviceType  = strtolower($lead['service_type'] ?? 'starlink');
        $interestPlan = trim($lead['interest_plan'] ?? '');

        // Try to match plan by name in subscription_plans.json
        if ($interestPlan) {
            $plans = $this->store->load('subscription_plans.json') ?? [];
            foreach ($plans as $plan) {
                $planName = strtolower($plan['name'] ?? '');
                if (str_contains($planName, strtolower($interestPlan)) ||
                    str_contains(strtolower($interestPlan), $planName)) {
                    $price = (float)($plan['customer_price'] ?? $plan['amount'] ?? 0);
                    if ($price > 0) {
                        $items[] = [
                            'label'    => $plan['name'],
                            'quantity' => 1,
                            'price'    => $price,
                            'unit'     => 'month',
                        ];
                        break;
                    }
                }
            }
        }

        // Fiber: add installation fee
        if ($serviceType === 'fiber' && empty($items)) {
            $installFee = (float)($this->config['fiber_install_fee'] ?? 100);
            $items[] = ['label' => 'Fiber Installation Fee', 'quantity' => 1, 'price' => $installFee, 'unit' => 'amount'];
        }

        // If still empty, add a generic placeholder so the agent sees what to fill
        if (empty($items) && $interestPlan) {
            $items[] = ['label' => ucfirst($serviceType) . ' — ' . $interestPlan, 'quantity' => 1, 'price' => 0, 'unit' => 'month'];
        }

        return $items;
    }

    /**
     * Get quote log with optional filters.
     */
    public function getQuotes(array $f = []): array
    {
        $all = $this->store->load(self::LOG_FILE) ?? [];
        if (!empty($f['type']))  $all = array_values(array_filter($all, fn($q) => ($q['type'] ?? '') === $f['type']));
        if (!empty($f['phone'])) {
            $fp = preg_replace('/[^0-9]/', '', $f['phone']);
            $all = array_values(array_filter($all, fn($q) => str_contains(preg_replace('/[^0-9]/', '', $q['customer_phone'] ?? ''), $fp)));
        }
        usort($all, fn($a, $b) => strcmp($b['created_at'] ?? '', $a['created_at'] ?? ''));
        return array_slice($all, 0, (int)($f['limit'] ?? 100));
    }

    // ══════════════════════════════════════════════════════════════════════════
    // PRIVATE
    // ══════════════════════════════════════════════════════════════════════════

    private function sendWA(string $phone, string $message, string $event): bool
    {
        try {
            // Quotes are pre-sale documents — use SUPPORT channel (sales number)
            $this->ns->sendVia(NotificationService::SUPPORT, $phone, $message, $event);
            return true;
        } catch (\Throwable $e) {
            error_log("[QuotationService] WA send failed to {$phone}: " . $e->getMessage());
            return false;
        }
    }

    private function itemsTotal(array $items): float
    {
        return round(array_sum(array_map(
            fn($i) => (float)($i['price'] ?? 0) * max(1, (int)($i['quantity'] ?? 1)),
            $items
        )), 2);
    }

    private function nextQuoteRef(string $prefix = 'QUO'): string
    {
        $year  = date('Y');
        $month = date('m');
        $key   = "{$prefix}-{$year}{$month}-";
        $all   = $this->store->load(self::LOG_FILE) ?? [];
        $max   = 0;
        foreach ($all as $q) {
            $ref = $q['quote_ref'] ?? '';
            if (str_starts_with($ref, $key) && preg_match('/-(\d+)$/', $ref, $m)) {
                $max = max($max, (int)$m[1]);
            }
        }
        return $key . str_pad((string)($max + 1), 3, '0', STR_PAD_LEFT);
    }

    private function logQuote(array $data): void
    {
        $all = $this->store->load(self::LOG_FILE) ?? [];
        $maxId = empty($all) ? 0 : max(array_map(fn($q) => (int)($q['id'] ?? 0), $all));
        $all[] = array_merge([
            'id'         => $maxId + 1,
            'created_at' => date('Y-m-d H:i:s'),
            'currency'   => dn_code($this->config),
        ], $data);
        if (count($all) > 2000) $all = array_slice($all, -2000);
        $this->store->save(self::LOG_FILE, $all);
    }
}
