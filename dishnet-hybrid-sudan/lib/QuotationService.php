<?php
declare(strict_types=1);

// PHP 7.4 polyfills
if (!function_exists('str_contains'))  { function str_contains(string $h, string $n): bool  { return $n===''||strpos($h,$n)!==false; } }
if (!function_exists('str_starts_with')){ function str_starts_with(string $h, string $n): bool { return $n===''||strncmp($h,$n,strlen($n))===0; } }
if (!function_exists('str_ends_with')) { function str_ends_with(string $h, string $n): bool  { return $n===''||substr($h,-strlen($n))===$n; } }

require_once __DIR__ . '/NotificationService.php';
require_once __DIR__ . '/CrmApiClient.php';
require_once __DIR__ . '/QuoteTaxLine.php';   // 5.18.49: what every Uganda quotation says about tax
require_once __DIR__ . '/QuoteOrganization.php';   // 5.18.99: the Uganda quoting organization and the quote check

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
    /** @var array|null 5.18.99: QuoteOrganization::setting() for this instance, once read */
    private ?array $quoteOrgSetting = null;
    /** @var array<int,array> 5.18.99: the quote check's answer per client, so it runs once per client per request */
    private array $quoteCheckMemo = [];
    /** @var QuoteOrganization|null 5.18.99 */
    private $quoteGuard = null;
    /** @var UcrmCatalogue|false|null 5.18.103 (root docs/72 §G.3): the uCRM catalogue's settings, once read; false where the gate is closed */
    private $ucrmCatalogue = null;
    /** @var array|null 5.18.103 (§G.9, G-15): offered() from this instance's one fresh uCRM read, for a send */
    private ?array $directOfferedMemo = null;

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
            'letterhead_client_id' => (int)($application['crm_client_id'] ?? 0),   // 5.18.99 (read on Uganda only)
        ]);

        // 5.18.54 (docs/46 row 17, D2c): one WhatsApp per quote on Uganda — see claimQuoteWa().
        $byEvent = !$this->claimQuoteWa($crmQuoteId, (string)$quoteRef, 'plugin_kyc');
        $sent = $byEvent ? true : $this->sendWA($phone, $msg, 'quote_kyc');

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
        ] + ($byEvent ? ['wa_sent_by' => 'quote.add'] : []));

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

        // 5.18.103 (Uganda, root docs/72 §G.9, G-15): in direct mode every line picked from the uCRM catalogue — from this
        // page or the mobile API — is re-read from uCRM fresh, before anything is created or sent, and goes at uCRM's label
        // and price; a line that was not picked names no uCRM product. The line auto-built below is from a fresh read
        // already. With the switch off, or the gate off, nothing here runs.
        if ($items !== [] && $this->directMode()) {
            $direct = $this->directLines($items);
            if (!$direct['ok']) return ['ok' => false, 'error' => $direct['error']];
            $items = $direct['items'];
        }

        // Auto-build items from lead interest if none provided
        if (empty($items)) {
            $items = $this->buildLeadItems($lead);
        }

        if (empty($items)) {
            return ['ok' => false, 'error' => 'No pricing items could be determined for this lead. Please add items manually.'];
        }
        // 5.18.102 (Uganda, docs/71 §J): no line is ever sent at price 0 — the first one is named, and nothing is written
        // or sent.
        if ($this->quoteOrg()['on'] && ($unpriced = $this->unpricedLine($items)) !== '') {
            return ['ok' => false, 'error' => $unpriced];
        }

        $total    = $this->itemsTotal($items);
        // 5.18.99 (Uganda, "always company 1"): no quote — in uCRM or plugin-only, box ticked or not — for a lead whose
        // uCRM client is filed under another company. Nothing is written and nothing is sent.
        if ($this->quoteOrg()['on'] && (int)($lead['crm_client_id'] ?? 0) > 0) {
            $check = $this->checkQuoteClient((int)$lead['crm_client_id'], 'lead');
            if (!$check['ok']) return ['ok' => false, 'error' => $check['message'], 'refused' => true];
        }
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
            } elseif ($this->quoteOrg()['on']) {
                // 5.18.99 (Uganda): the uCRM quote failed, so the salesperson is told — never a plugin-only reference
                // sent in its place. No WhatsApp, no lead update, no log row.
                return ['ok' => false, 'error' => $result['error']];
            }
        }

        // 5.18.102 (Uganda, docs/71 §J): a lead's notes are internal — AI summaries, "Auto-created from WA Lead Recovery
        // …", staff notes — and never reach the customer's WhatsApp. The lead quotation screen has no note for the
        // customer, so none is sent. (Only this local copy is cleared; the lead's saved notes are not touched.)
        if ($this->quoteOrg()['on']) $lead['notes'] = '';

        // Send WA to customer
        $waSent = false;
        // 5.18.54 (docs/46 row 17, D2c): one WhatsApp per quote on Uganda — see claimQuoteWa().
        $waByEvent = $phone && $crmQuoteId && !$this->claimQuoteWa((int)$crmQuoteId, $quoteRef, 'plugin_lead');
        if ($waByEvent) {
            $waSent = true;   // uCRM's quote.add claimed it first and sends it, with its PDF
        } elseif ($phone) {
            $msg    = $this->buildProformaMessage($quoteRef, $name, $items, $total, [
                'type'       => 'Quotation — ' . ucfirst($lead['service_type'] ?? 'Service'),
                'via_crm'    => $sentViaCrm,
                'agent'      => $retailer['name'] ?? '',
                'note'       => $lead['notes'] ?? '',
                'follow_up'  => $lead['follow_up_date'] ?? '',
                'letterhead_client_id' => $crmClientId,   // 5.18.99 (read on Uganda only)
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
        ] + ($waByEvent ? ['wa_sent_by' => 'quote.add'] : []));

        return [
            'ok'           => true,
            'quote_ref'    => $quoteRef,
            'sent_via_wa'  => $waSent,
            'sent_via_crm' => $sentViaCrm,
            'crm_quote_id' => $crmQuoteId,
            'total'        => $total,
        ] + ($waByEvent ? ['wa_sent_by' => 'quote.add'] : []);
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

        // 5.18.103 (Uganda, root docs/72 §G.9, G-15): in direct mode every line picked from the uCRM catalogue — from this
        // page or the mobile API — is re-read from uCRM fresh, before anything below, and goes at uCRM's label and price;
        // a line that was not picked names no uCRM product, and the price-0 rule below still holds for it. With the switch
        // off, or the gate off, nothing here runs.
        if (is_array($items) && $items !== [] && $this->directMode()) {
            $direct = $this->directLines($items);
            if (!$direct['ok']) return ['ok' => false, 'error' => $direct['error']];
            $items = $direct['items'];
        }

        if (empty($items)) return ['ok' => false, 'error' => 'At least one line item required.'];
        // 5.18.102 (Uganda, docs/71 §J): no line is ever sent at price 0 — the first one is named, and nothing is written
        // or sent. Lines staff type are otherwise sent as typed (no productId).
        if ($this->quoteOrg()['on'] && ($unpriced = $this->unpricedLine($items)) !== '') {
            return ['ok' => false, 'error' => $unpriced];
        }

        // 5.18.99 (Uganda, "always company 1"): no quote — in uCRM or plugin-only, box ticked or not — for a customer
        // filed under another company. Nothing is written and nothing is sent.
        $quoteOrgOn = $this->quoteOrg()['on'];
        if ($quoteOrgOn && $crmClientId > 0) {
            $check = $this->checkQuoteClient($crmClientId, 'manual');
            if (!$check['ok']) return ['ok' => false, 'error' => $check['message'], 'refused' => true];
        }

        // If CRM client ID given, fetch name/phone from CRM if not provided
        if ($crmClientId > 0 && (!$customerName || !$customerPhone)) {
            try {
                $crmClient = ($quoteOrgOn && is_array($this->quoteCheckMemo[$crmClientId]['client'] ?? null))
                    ? $this->quoteCheckMemo[$crmClientId]['client']   // 5.18.99: the row the check just read
                    : $this->crm->get("clients/{$crmClientId}");
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
            $result = $this->createCrmQuote($crmClientId, $items, $quoteRef, $retailer, $note, 'manual');
            if ($result['ok']) {
                $crmQuoteId = $result['quote_id'];
                $sentViaCrm = true;
                // Use UCRM's number as canonical ref
                if (!empty($result['quote_number'])) {
                    $quoteRef = $result['quote_number'];
                }
            } elseif ($quoteOrgOn) {
                // 5.18.99 (Uganda): the uCRM quote failed, so the salesperson is told — never a plugin-only reference
                // sent in its place. No WhatsApp, no log row.
                return ['ok' => false, 'error' => $result['error']];
            }
        }

        $msg    = $this->buildProformaMessage($quoteRef, $customerName, $items, $total, [
            'type'    => 'Quotation',
            'via_crm' => $sentViaCrm,
            'agent'   => $retailer['name'] ?? '',
            'note'    => $note,
            'letterhead_client_id' => $crmClientId,   // 5.18.99 (read on Uganda only)
        ]);
        // 5.18.54 (docs/46 row 17, D2c): one WhatsApp per quote on Uganda — see claimQuoteWa().
        $waByEvent = $crmQuoteId && !$this->claimQuoteWa((int)$crmQuoteId, $quoteRef, 'plugin_manual');
        $waSent = $waByEvent ? true : $this->sendWA($phone, $msg, 'quote_manual');

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
        ] + ($waByEvent ? ['wa_sent_by' => 'quote.add'] : []));

        return [
            'ok'           => true,
            'quote_ref'    => $quoteRef,
            'sent_via_wa'  => $waSent,
            'sent_via_crm' => $sentViaCrm,
            'crm_quote_id' => $crmQuoteId,
            'total'        => $total,
        ] + ($waByEvent ? ['wa_sent_by' => 'quote.add'] : []);
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
        $letter     = isset($opts['client_id']) ? (int)$opts['client_id'] : null;
        // 5.18.99 (Uganda): the letterhead is the quoted client's own organization. letterhead_client_id is ignored
        // where the gate is off, so South Sudan reads no client and its text is unchanged.
        if ($letter === null && $this->quoteOrg()['on']) {
            $lc = (int)($opts['letterhead_client_id'] ?? 0);
            if ($lc > 0) $letter = $lc;
        }
        $co         = $this->companyDetails($letter);
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
        // 5.18.49 (docs/42 §9): every Uganda quotation says what its prices include, in the operator's words — the same
        // line the quote.add webhook puts under its Total. South Sudan's message is unchanged.
        if (QuoteTaxLine::applies($this->config, $this->dataDir)) $lines[] = '✅ ' . QuoteTaxLine::TEXT;

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
        // 5.18.99 (Uganda): the last fallback is the Uganda profile's own name, phone and reply-to — never the South
        // Sudan constants. uCRM, then quote_company_*, still come first.
        $profileLast = $this->quoteOrg()['on'] ? $this->profileLetterhead() : null;
        foreach ($map as $field => [$orgKey, $cfgKey, $const]) {
            $fromOrg = trim((string)($org[$orgKey] ?? ''));
            $fromCfg = trim((string)($this->config[$cfgKey] ?? ''));
            if ($fromOrg !== '')      { $out[$field] = $fromOrg; $src[$field] = 'ucrm'; }
            elseif ($fromCfg !== '')  { $out[$field] = $fromCfg; $src[$field] = 'config'; }
            elseif ($profileLast !== null) {
                $out[$field] = $profileLast[$field]; $src[$field] = 'profile';
                $warn[] = "{$field} fell through to the Uganda profile's \"{$profileLast[$field]}\" — "
                        . "uCRM has no {$orgKey} and {$cfgKey} is unset";
            }
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
            $qo    = $this->quoteOrg();
            if ($clientId !== null && $clientId > 0) {
                $client = ($qo['on'] && is_array($this->quoteCheckMemo[$clientId]['client'] ?? null))
                    ? $this->quoteCheckMemo[$clientId]['client']   // 5.18.99: the row the quote check read
                    : $this->crm->get("clients/{$clientId}");
                $orgId  = isset($client['organizationId']) ? (int)$client['organizationId'] : null;
            }
            // 5.18.99 (Uganda, "always company 1"): no client, or none readable — the quoting organization, never
            // whichever uCRM marks selected.
            if (($orgId === null || $orgId <= 0) && $qo['on'] && is_int($qo['id'] ?? null) && $qo['id'] > 0) {
                $orgId = (int)$qo['id'];
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

    public function createCrmQuote(int $crmClientId, array $items, string $quoteRef, array $retailer, string $note = '',
                                   string $where = 'lead'): array
    {
        // 5.18.99 (Uganda, "always company 1"): no uCRM quote for a client filed under another company. The answer
        // carries 'refused' only here, only where the gate is on.
        if ($this->quoteOrg()['on']) {
            $check = $this->checkQuoteClient($crmClientId, $where);
            if (!$check['ok']) return ['ok' => false, 'error' => $check['message'], 'refused' => true];
        }
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
        // 5.18.102 (Uganda, docs/71 §F.1 row 4): notes print on the customer's quotation, so they carry only the notes
        // prefix and the sender's own "Note / Footer message"; the plugin's reference and the agent are staff-only, in
        // adminNotes. Every caller — Send Quotation, a lead, LTE — the same. Empty notes hide the template's NOTES box.
        if ($this->quoteOrg()['on']) {
            $payload['notes']      = trim(($notesPrefix !== '' ? $notesPrefix . "\n" : '') . trim($note));
            $payload['adminNotes'] = "Plugin ref: {$quoteRef} · Agent: " . ($retailer['name'] ?? 'Agent');
        }

        $madeId = null;   // 5.18.99: kept outside the try, so a failure after uCRM made the quote says so
        // 5.18.104 (root docs/73 §H.5.8.1) P7: who is making this quote, for a quote.add that may e-mail it before this request
        // has stored anything (§H.5.5). Uganda only — South Sudan never loads P7 — with P7's switch on; else 0 and nothing written.
        $p7Maker = 0;
        require_once __DIR__ . '/StaffJobsGate.php';
        if (StaffJobsGate::applies($this->config, $this->dataDir)) {
            try {
                require_once __DIR__ . '/QuoteAgentCopy.php';
                $p7Maker = QuoteAgentCopy::markMaking($this->store, dirname(__DIR__), $this->dataDir, $crmClientId, $retailer);
            } catch (\Throwable $e) { $p7Maker = 0; }
        }
        try {
            $resp = $this->crm->post('billing/quotes', $payload);
            if (!empty($resp['id'])) {
                $quoteId    = (int)$resp['id'];
                $madeId     = $quoteId;
                if ($p7Maker > 0) { try { QuoteAgentCopy::markResult($this->store, $p7Maker, $quoteId); } catch (\Throwable $e) {} }   // 5.18.104 (root docs/73 §H.5.8.1) P7: made
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
            if ($p7Maker > 0) { try { QuoteAgentCopy::markResult($this->store, $p7Maker, 0); } catch (\Throwable $e) {} }   // 5.18.104 (root docs/73 §H.5.8.1) P7: none made
            if ($this->quoteOrg()['on']) return $this->quoteNotMade($crmClientId, $where, $resp, null, null);   // 5.18.99
            return ['ok' => false, 'error' => 'UCRM did not return quote ID.'];
        } catch (\Throwable $e) {
            if ($p7Maker > 0) { try { QuoteAgentCopy::markResult($this->store, $p7Maker, 0); } catch (\Throwable $e2) {} }   // 5.18.104 (root docs/73 §H.5.8.1) P7: none made
            if ($this->quoteOrg()['on']) return $this->quoteNotMade($crmClientId, $where, null, $e, $madeId);   // 5.18.99
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * 5.18.99 (Uganda): why a uCRM quote was not made, for the salesperson. Definite only when uCRM answered 4xx;
     * anything else is uncertain — uCRM may hold the quote, and its quote.add may already have messaged the customer.
     * lastError is read only when the POST itself returned null: CrmApiClient never clears it after a success.
     */
    private function quoteNotMade(int $clientId, string $where, ?array $resp, ?\Throwable $e, ?int $madeId): array
    {
        if ($e !== null) {
            $why = $madeId !== null
                ? 'uCRM made quotation #' . $madeId . ' but the app could not finish it'
                : QuoteOrganization::plain($e->getMessage(), 120);
            $code = 'F7u';
        } elseif ($resp === null) {
            $err  = (array)$this->crm->getLastError();
            $http = (int)($err['http_code'] ?? 0);
            if (!class_exists('UcrmClientTarget')) require_once __DIR__ . '/UcrmClientTarget.php';
            $why  = UcrmClientTarget::describe($err);
            $code = ($http === 401 || $http === 403) ? 'F7k' : (($http >= 400 && $http <= 499) ? 'F7a' : 'F7u');
        } else {
            $why  = 'uCRM answered without a quotation number';
            $code = 'F7u';
        }
        $texts = [
            'F7a' => 'uCRM refused the quotation (' . $why . '), so nothing was sent to the customer. Tell an admin: trying again will not help.',
            'F7k' => 'uCRM refused the app\'s key, so no quotation was made and nothing was sent to the customer. Tell an admin.',
            'F7u' => 'uCRM did not confirm the quotation (' . $why . '). It may have been made, so check the customer\'s quotes in uCRM before trying again. Nothing was sent from the app.',
        ];
        $line = "[QuotationService] uCRM quote not made for client #{$clientId} ({$where}): {$code} {$why}";
        try {
            if (!class_exists('PluginLog')) require_once __DIR__ . '/PluginLog.php';
            error_log(PluginLog::mask($line));
            PluginLog::write('quotes', $line);
        } catch (\Throwable $le) { /* the answer below is what matters */ }
        return ['ok' => false, 'error' => $texts[$code]];
    }

    /** 5.18.99: QuoteOrganization::setting() for this instance's configuration and data directory, read once. */
    private function quoteOrg(): array
    {
        if ($this->quoteOrgSetting === null) {
            $this->quoteOrgSetting = QuoteOrganization::setting($this->config, $this->dataDir);
        }
        return $this->quoteOrgSetting;
    }

    /**
     * 5.18.99: the quote check (QuoteOrganization), with the client that makes the quote, once per client per request.
     * The client row it read is kept, so the letterhead and the Manual name/phone fetch do not read it again.
     */
    private function checkQuoteClient(int $id, string $where): array
    {
        if (isset($this->quoteCheckMemo[$id])) return $this->quoteCheckMemo[$id];
        if ($this->quoteGuard === null) $this->quoteGuard = new QuoteOrganization($this->crm, $this->quoteOrg());
        $r = $this->quoteGuard->checkClient($id, $where);
        $r['client'] = $this->quoteGuard->lastClient();
        return $this->quoteCheckMemo[$id] = $r;
    }

    /** 5.18.99: the Uganda profile's letterhead (name, phone, reply-to), the last fallback on the Uganda book. */
    private function profileLetterhead(): array
    {
        try {
            if (!class_exists('TenantProfile')) require_once __DIR__ . '/TenantProfile.php';
            $p = TenantProfile::current($this->config, $this->dataDir);
            return ['name'  => $p->text('email_brand.company_name'),
                    'phone' => $p->text('email_brand.support_phone'),
                    'email' => $p->text('email_brand.reply_to')];
        } catch (\Throwable $e) {
            return ['name' => '', 'phone' => '', 'email' => ''];
        }
    }

    // ── 5.18.102 (Uganda, docs/71 §J): Send Quotation and lead quotes priced from uCRM ─────────────────────────────

    /**
     * 5.18.102 (Uganda, docs/71 §J): Send Quotation's plan picker, priced from uCRM's plan mirrors through the resolver —
     * never customer_price. A plan uCRM does not price comes back with price null and why: the page lists it without a
     * price and never picks it into a line. by_key is the lead pre-fill's exact match (quotePlanKeys(), as
     * buildLeadItems() uses it) and lead_keys each lead's key of its interest plan, so the page matches no name itself.
     * For drawing the page: short timeouts, a successful read reused for 60 s.
     *
     * @param array $leads the leads the page offers
     * @return array{ok:bool,reason:string,plans:array,by_key:array,lead_keys:array,shared_keys:list<string>}
     */
    public function quotePlanPicker(array $leads = []): array
    {
        $lines = $this->quoteLines(true);
        $cat   = $lines->catalogue();
        $keyed = $this->quotePlanKeys($lines);
        $plans = [];
        foreach ($this->quotePlanRows() as $row) {
            $name = is_scalar($row['name'] ?? null) ? trim((string)$row['name']) : '';
            if ($name === '') continue;
            // 5.18.102 (D14, docs/71 §R.4.5): per row — two rows spelled alike may be linked to different products
            $hit = $lines->planLine($row);
            $plans[] = ['id' => (int)($row['id'] ?? 0)] + self::pickerPlan($hit, $name);
        }
        $byKey = [];
        foreach ($keyed as $key => $hit) $byKey[(string)$key] = self::pickerPlan($hit, (string)$hit['name']);
        // 5.18.102 D14 review (D14-UI-1, docs/71 §R.4.5): a key two or more plan rows hold pre-fills nothing; the page
        // names that cause rather than "no plan is spelled like it"
        $held = [];
        foreach ($this->quotePlanRows() as $row) {
            $k = PublicPriceFeed::planKey(is_scalar($row['name'] ?? null) ? (string)$row['name'] : '');
            if ($k !== '') $held[$k] = ($held[$k] ?? 0) + 1;
        }
        $shared = [];
        foreach ($held as $k => $n) if ($n > 1) $shared[] = (string)$k;
        $leadKeys = [];
        foreach ($leads as $l) {
            if (!is_array($l)) continue;
            $leadKeys[(string)(int)($l['id'] ?? 0)] = PublicPriceFeed::planKey(
                is_scalar($l['interest_plan'] ?? null) ? (string)$l['interest_plan'] : '');
        }
        return ['ok' => !empty($cat['ok']), 'reason' => (string)($cat['reason'] ?? ''), 'plans' => $plans,
                'by_key' => $byKey, 'lead_keys' => $leadKeys, 'shared_keys' => $shared];
    }

    /** 5.18.102: one plan as the picker shows it — uCRM's name and price when priced, else the plugin's name, no price and why. */
    private static function pickerPlan(array $hit, string $name): array
    {
        $ok = !empty($hit['ok']) && is_array($hit['line'] ?? null);
        return [
            'name'   => $ok ? (string)$hit['line']['label'] : trim($name),
            'price'  => $ok ? (float)$hit['line']['price'] : null,
            'unit'   => 'month',
            'reason' => $ok ? '' : (string)($hit['reason'] ?? ''),
        ];
    }

    /** 5.18.102: the resolver's file and PublicPriceFeed::planKey, loaded only where the gate is on. */
    private static function needQuoteLines(): void
    {
        if (!class_exists('UcrmQuoteLines')) require_once __DIR__ . '/UcrmQuoteLines.php';
        if (!class_exists('PublicPriceFeed')) require_once __DIR__ . '/PublicPriceFeed.php';
    }

    /**
     * 5.18.102 (Uganda, docs/71 §D.1, §J): the resolver, reading with the client this class makes its quotes with
     * ($this->crm: the plugin's own key, or crm_base_url with crm_auth_token, as fromUcrm() chose). $forPage: short
     * timeouts and a successful read reused for 60 s, to draw Send Quotation; otherwise a fresh read with the client's
     * normal timeout, for a quotation being sent.
     */
    private function quoteLines(bool $forPage): UcrmQuoteLines
    {
        self::needQuoteLines();
        // 5.18.102 (D14, docs/71 §R.4): priced only through the links an admin made, read live from the store, behind the
        // gate (every caller here is). Send Quotation records no link ids: it never touches kyc_applications (§R.4.4).
        $links = UcrmQuoteLinks::fromStore($this->store, $this->quoteOrg());
        return $forPage ? UcrmQuoteLines::forPage($this->crm, $this->quoteOrg(), $this->dataDir, $links)
                        : new UcrmQuoteLines($this->crm, $this->quoteOrg(), $links);
    }

    /** 5.18.102: subscription_plans.json's rows — the plans Send Quotation and a lead quote may name. */
    private function quotePlanRows(): array
    {
        return array_values(array_filter((array)($this->store->load('subscription_plans.json') ?? []), 'is_array'));
    }

    /**
     * 5.18.102 (Uganda, docs/71 §D.3, §J, §R.4.5): the lead pre-fill's spelling step — from a lead's interest plan (free
     * text, no id to follow) to a plugin plan row by PublicPriceFeed::planKey — then that row's own link to uCRM. Each key
     * held by exactly one subscription_plans row, with the resolver's answer for that row: the one exact match the lead
     * pre-fill and buildLeadItems() share. A key two rows share answers nothing (D14: they may be linked to different
     * products), and the lead asks for its items by hand. An empty key matches nothing. $only answers that one key
     * alone, so a lead naming no plan row reads nothing from uCRM.
     *
     * @return array<string,array{ok:bool,reason:string,line:?array,name:string}>
     */
    private function quotePlanKeys(UcrmQuoteLines $lines, ?string $only = null): array
    {
        $held = [];
        foreach ($this->quotePlanRows() as $row) {
            $name = is_scalar($row['name'] ?? null) ? (string)$row['name'] : '';
            $key  = PublicPriceFeed::planKey($name);
            if ($key === '' || ($only !== null && $key !== $only)) continue;
            $held[$key][] = [$row, $name];
        }
        $out = [];
        foreach ($held as $key => $rows) {
            if (count($rows) !== 1) continue;   // two rows spelled alike: no pre-fill (§R.4.5)
            $out[(string)$key] = $lines->planLine($rows[0][0]) + ['name' => $rows[0][1]];
        }
        return $out;
    }

    /**
     * 5.18.102 (Uganda, docs/71 §J): '' when every line has a price above 0, else the refusal naming the first that has
     * not. A line at 0 reads as "free" on the customer's quotation, and the quote.add summary drops it.
     */
    private function unpricedLine(array $items): string
    {
        foreach ($items as $item) {
            $price = is_array($item) ? ($item['price'] ?? null) : null;
            if (is_string($price)) $price = trim($price);
            if ((is_int($price) || is_float($price) || (is_string($price) && is_numeric($price))) && (float)$price > 0) continue;
            $label = QuoteOrganization::plain(is_array($item) && is_scalar($item['label'] ?? null) ? (string)$item['label'] : '');
            return ($label !== '' ? $label : 'A line') . ' has no price — enter one or remove it';
        }
        return '';
    }

    // ── 5.18.103 (Uganda, root docs/72 §G.9, G-14, G-15): Send Quotation and lead quotes from the uCRM catalogue ──────────
    //
    // With the uCRM catalogue's switch on ("direct mode", UcrmCatalogue), Send Quotation picks uCRM's own plans, hardware
    // and installation (UcrmQuoteLines::offered()), and every send re-reads each picked line from uCRM before anything is
    // created or sent — here, which the web page and the mobile API share, so neither can send a uCRM product at a posted
    // price. The send itself is unchanged: sendLeadQuote / sendManualQuote, type lead / manual, event quote_lead /
    // quote_manual, the Ref marker and the quotes_log row (G-14). With the switch off, or the gate off, none of this runs.

    /** 5.18.103 (§G.9, G-9): a page drawn in direct mode, posted after an admin turned the switch off — nothing is sent. */
    public const DIRECT_OFF_TEXT = 'This quotation page was opened under the uCRM catalogue, which an admin has since turned off — reload the page. Nothing was sent.';
    /** 5.18.103 (§G.9): a picked line whose price in uCRM is not the price the page showed. */
    public const DIRECT_PRICE_CHANGED = 'a price changed in uCRM since the page was opened — reload';
    /** 5.18.103 (§G.9): a picked line that cannot be read — no item id, or a quantity outside 1–99. */
    public const DIRECT_LINE_UNREADABLE = 'a line picked from the uCRM catalogue could not be read — reload the page';
    /** 5.18.103 (§G.9): the kinds of line a page picks from the uCRM catalogue; any other line is typed. */
    public const DIRECT_KINDS = ['plan', 'item', 'install'];

    /**
     * 5.18.103 (§G.3): the uCRM catalogue's settings — null where the quoting gate is closed (QuoteOrganization::setting()'s
     * 'on' with an integer id): there its table is never opened and its file never loaded.
     */
    private function catalogueSettings(): ?UcrmCatalogue
    {
        if ($this->ucrmCatalogue === null) {
            $this->ucrmCatalogue = false;
            $qo = $this->quoteOrg();
            if (!empty($qo['on'])) {
                if (!class_exists('UcrmCatalogue')) require_once __DIR__ . '/UcrmCatalogue.php';
                if (UcrmCatalogue::gateOpen($qo)) $this->ucrmCatalogue = UcrmCatalogue::fromStore($this->store, $qo);
            }
        }
        return $this->ucrmCatalogue === false ? null : $this->ucrmCatalogue;
    }

    /** 5.18.103 (§G.3, G-1): is direct mode on here — the gate open with an integer id, and the uCRM catalogue's switch on? */
    public function directMode(): bool
    {
        $c = $this->catalogueSettings();
        return $c !== null && $c->direct();
    }

    /**
     * 5.18.103 (§G.9, G-9): what a Send Quotation post's marker (ucrm_quote_form) says. '' when it carries none, or the gate
     * is closed — the post is read as 5.18.102 reads it; 'direct' when the page was drawn in direct mode and the switch is
     * still on; 'off' when it was, but an admin has since turned the switch off — the handler then sends nothing.
     */
    public static function directForm($store, string $dataDir, $posted): string
    {
        if (!is_string($posted) || $posted === '') return '';
        self::needQuoteLines();
        if ($posted !== UcrmQuoteLines::DIRECT_MARKER) return '';
        $cat = (new self($store, $dataDir))->catalogueSettings();
        if ($cat === null) return '';
        return $cat->direct() ? 'direct' : 'off';
    }

    /**
     * 5.18.103 (§G.9): a posted line picked from the uCRM catalogue — {kind, sp, p, q, label, price}: the ids and the
     * quantity as integers (0 when they cannot be read, which the send refuses), the price as it was drawn (null when it is
     * not a number) — or null for any other line, which is a typed one. One reading for the web handlers and the send.
     */
    public static function pickedLine($it): ?array
    {
        if (!is_array($it) || !is_string($it['kind'] ?? null) || !in_array($it['kind'], self::DIRECT_KINDS, true)) return null;
        $int = function ($v): int {
            if (is_string($v) && preg_match('/^[0-9]{1,9}$/', trim($v))) $v = (int)trim($v);
            return (is_int($v) && $v > 0) ? $v : 0;
        };
        $price = $it['price'] ?? null;
        if (is_string($price)) $price = trim($price);
        $isNum = is_int($price) || is_float($price) || (is_string($price) && is_numeric($price));
        return [
            'kind'  => $it['kind'],
            'sp'    => $it['kind'] === 'plan' ? $int($it['sp'] ?? null) : null,
            'p'     => $int($it['p'] ?? null),
            'q'     => $int(array_key_exists('q', $it) ? $it['q'] : ($it['quantity'] ?? null)),
            'label' => is_scalar($it['label'] ?? null) ? trim((string)$it['label']) : '',
            'price' => $isNum ? round((float)$price, 2) : null,
        ];
    }

    /**
     * 5.18.103 (Uganda, root docs/72 §G.9): Send Quotation's pickers in direct mode — null when direct mode is off (the page
     * then draws 5.18.102's). What uCRM offers (offered(), drawn through forDirectPage(): the page timeouts and the 60-second
     * cache, or the last good list, marked stale), each item as the page posts it once picked — {kind, sp (plans only), p,
     * q, label, price, unit} — and the standard quotations priced live against it (standardFrom(): usable whole, else
     * disabled with the reason, G-12). lead_keys is each lead's key of its interest plan (PublicPriceFeed::planKey), so the
     * page matches no name itself. Nothing drawn here is trusted at the send: directLines() reads uCRM again.
     *
     * @return array{ok:bool,reason:string,stale:bool,marker:string,max_qty:int,plans:array,hardware:array,install:array,
     *               standards:array,lead_keys:array}|null
     */
    public function directQuotePicker(array $leads = []): ?array
    {
        if (!$this->directMode()) return null;
        self::needQuoteLines();
        $cat   = $this->catalogueSettings();
        $stale = false;
        try {
            // $this->crm makes the quote, so it reads the catalogue: no configuration is handed on, and quoteClient() keeps it
            $page  = UcrmQuoteLines::forDirectPage($this->crm, [], $this->quoteOrg(), $this->dataDir);
            $stale = !empty($page['stale']);
            $off   = $page['uql']->offered($cat->installProductId(), $cat->hiddenIds());
        } catch (\Throwable $e) {
            $off = self::directUnread();
        }
        $plans = [];
        foreach ((array)$off['plans'] as $row) {
            $plans[] = self::directPick('plan', $row, 1) + ['name' => (string)($row['name'] ?? ''),
                'speed' => (string)($row['speed'] ?? ''), 'key' => (string)($row['key'] ?? '')];
        }
        $hardware = [];
        foreach ((array)$off['hardware'] as $row) {
            $hardware[] = self::directPick('item', $row, 1) + ['group' => ($row['group'] ?? '') === 'accessory' ? 'accessory' : 'kit'];
        }
        $in = is_array($off['install'] ?? null) ? $off['install'] : [];
        $install = (!empty($in['ok']) && is_array($in['line'] ?? null))
            ? ['ok' => true, 'reason' => ''] + self::directPick('install', $in['line'], 1)
            : ['ok' => false, 'reason' => (string)($in['reason'] ?? '')];
        $standards = [];
        foreach ($cat->standards() as $std) {
            $r = UcrmQuoteLines::standardFrom($std, $off);
            $lines = [];
            if ($r['ok']) {
                // the same rows standardFrom() priced, in its order: the plan, each hardware item, the installation
                foreach ((array)$off['plans'] as $row) {
                    if ((int)$row['sp'] === (int)$std['plan']['sp'] && (int)$row['p'] === (int)$std['plan']['p']) { $lines[] = self::directPick('plan', $row, 1); break; }
                }
                foreach ($std['hw'] as $h) {
                    foreach ((array)$off['hardware'] as $row) {
                        if ((int)$row['p'] === (int)$h['p']) { $lines[] = self::directPick('item', $row, (int)$h['q']); break; }
                    }
                }
                if (!empty($std['install']) && $install['ok']) $lines[] = array_diff_key($install, ['ok' => 1, 'reason' => 1]);
            }
            $whole = $r['ok'] && count($lines) === 1 + count($std['hw']) + (!empty($std['install']) ? 1 : 0);
            $standards[] = ['id' => (int)$std['id'], 'title' => (string)$std['title'], 'ok' => $whole,
                'reason' => $whole ? '' : ($r['ok'] ? 'its items could not be read — see the uCRM catalogue screen' : (string)$r['reason']),
                'total' => $whole ? (float)$r['total'] : null, 'lines' => $whole ? $lines : []];
        }
        $leadKeys = [];
        foreach ($leads as $l) {
            if (!is_array($l)) continue;
            $interest = is_scalar($l['interest_plan'] ?? null) ? (string)$l['interest_plan'] : '';
            $leadKeys[(string)(int)($l['id'] ?? 0)] = PublicPriceFeed::planKey($interest);
        }
        return ['ok' => !empty($off['ok']), 'reason' => (string)($off['reason'] ?? ''), 'stale' => $stale,
                'marker' => UcrmQuoteLines::DIRECT_MARKER, 'max_qty' => UcrmQuoteLines::MAX_QTY,
                'plans' => $plans, 'hardware' => $hardware, 'install' => $install, 'standards' => $standards,
                'lead_keys' => $leadKeys];
    }

    /**
     * 5.18.103 (§G.9, G-15): the lines a send in direct mode goes with. Every picked line (pickedLine()) is re-resolved from
     * one fresh uCRM read through the direct rules (offered(), with the installation in force and the products kept off):
     * a plan by its service plan and product, an item by its product (quantity 1–99), the installation by its product
     * being the one offered now. One that is not offered refuses the whole send with its reason; one whose price in uCRM
     * is not the price it was drawn with (2 dp) refuses with DIRECT_PRICE_CHANGED — and either drops the page cache, so the
     * reload reads uCRM. The line sent is {productId, label, price, quantity, unit} from that read, never the posted label
     * or price. Every other line is typed: it goes as given, less any productId. Nothing picked, nothing read.
     *
     * @return array{ok:bool,error:string,items:array}
     */
    private function directLines(array $items): array
    {
        self::needQuoteLines();
        $out = [];
        $off = null;
        $installId = null;
        foreach ($items as $item) {
            $pk = self::pickedLine($item);
            if ($pk === null) {
                if (is_array($item)) unset($item['productId']);   // G-15: a line not picked names no uCRM product
                $out[] = $item;
                continue;
            }
            if ($pk['p'] <= 0 || $pk['q'] < 1 || $pk['q'] > UcrmQuoteLines::MAX_QTY || ($pk['kind'] === 'plan' && $pk['sp'] <= 0)) {
                return ['ok' => false, 'error' => self::DIRECT_LINE_UNREADABLE, 'items' => []];
            }
            if ($off === null) {
                $off = $this->directOffered();
                if (empty($off['ok'])) return ['ok' => false, 'error' => (string)($off['reason'] ?: "uCRM's products could not be read"), 'items' => []];
                $cat = $this->catalogueSettings();
                $installId = $cat !== null ? $cat->installProductId() : null;
            }
            $hit = self::directFind($off, $pk);
            if ($hit === null) {
                UcrmQuoteLines::dropPageCache($this->dataDir);
                return ['ok' => false, 'error' => self::directWhyNot($off, $pk, $installId), 'items' => []];
            }
            if ($pk['price'] === null || abs(round((float)$hit['price'], 2) - $pk['price']) > 0.0001) {
                UcrmQuoteLines::dropPageCache($this->dataDir);
                return ['ok' => false, 'error' => self::DIRECT_PRICE_CHANGED, 'items' => []];
            }
            $out[] = self::directSendLine($hit, $pk['q']);
        }
        return ['ok' => true, 'error' => '', 'items' => $out];
    }

    /**
     * 5.18.103 (§G.9): a lead's auto-built line in direct mode — the one offered plan whose key is exactly the interest
     * plan's (PublicPriceFeed::planKey), from a fresh read, or none (the lead then asks for its items by hand). A lead that
     * names no plan reads nothing from uCRM.
     */
    private function directLeadItems(array $lead): array
    {
        $key = PublicPriceFeed::planKey(is_scalar($lead['interest_plan'] ?? null) ? (string)$lead['interest_plan'] : '');
        if ($key === '') return [];
        $off = $this->directOffered();
        if (empty($off['ok'])) return [];
        $hits = [];
        foreach ((array)$off['plans'] as $row) {
            if ((string)($row['key'] ?? '') === $key) $hits[] = $row;
        }
        return count($hits) === 1 ? [self::directSendLine($hits[0], 1)] : [];
    }

    /** 5.18.103 (§G.9): offered() from one fresh uCRM read (forDirectUse(), never a cache), read once per instance. */
    private function directOffered(): array
    {
        if ($this->directOfferedMemo === null) {
            self::needQuoteLines();
            $cat = $this->catalogueSettings();
            try {
                // $this->crm makes the quote, so it reads the catalogue: no configuration is handed on, and quoteClient() keeps it
                $this->directOfferedMemo = UcrmQuoteLines::forDirectUse($this->crm, [], $this->quoteOrg())
                    ->offered($cat !== null ? $cat->installProductId() : null, $cat !== null ? $cat->hiddenIds() : []);
            } catch (\Throwable $e) {
                $this->directOfferedMemo = self::directUnread();
            }
        }
        return $this->directOfferedMemo;
    }

    /** 5.18.103: offered()'s answer when uCRM could not be read. */
    private static function directUnread(): array
    {
        $why = "uCRM's products could not be read";
        return ['ok' => false, 'reason' => $why, 'plans' => [], 'hardware' => [],
                'install' => ['ok' => false, 'reason' => $why, 'why' => 'unreadable', 'line' => null], 'not_offered' => []];
    }

    /** 5.18.103 (§G.9): an offered row as the page posts it once picked. */
    private static function directPick(string $kind, array $row, int $q): array
    {
        return ['kind' => $kind, 'sp' => $kind === 'plan' ? (int)($row['sp'] ?? 0) : null, 'p' => (int)($row['productId'] ?? 0),
                'q' => $q, 'label' => (string)($row['label'] ?? ''), 'price' => round((float)($row['price'] ?? 0), 2),
                'unit' => (string)($row['unit'] ?? '')];
    }

    /** 5.18.103 (§G.9, G-15): the line a send goes with, from an offered row of the fresh read — the shape every quote carries. */
    private static function directSendLine(array $row, int $q): array
    {
        return ['productId' => (int)$row['productId'], 'label' => (string)$row['label'], 'price' => round((float)$row['price'], 2),
                'quantity' => $q, 'unit' => (string)$row['unit']];
    }

    /** 5.18.103 (§G.9): the offered row a picked line names — a plan by service plan and product, an item, the installation. */
    private static function directFind(array $off, array $pk): ?array
    {
        if ($pk['kind'] === 'plan') {
            foreach ((array)$off['plans'] as $row) {
                if ((int)$row['sp'] === $pk['sp'] && (int)$row['p'] === $pk['p']) return $row;
            }
            return null;
        }
        if ($pk['kind'] === 'item') {
            foreach ((array)$off['hardware'] as $row) {
                if ((int)$row['p'] === $pk['p']) return $row;
            }
            return null;
        }
        $in = is_array($off['install'] ?? null) ? $off['install'] : [];
        return (!empty($in['ok']) && is_array($in['line'] ?? null) && (int)$in['line']['productId'] === $pk['p']) ? $in['line'] : null;
    }

    /**
     * 5.18.103 (§G.9): why a picked line is not offered now — the rule's own reason where offered() gives one (not_offered),
     * else what changed since the page was drawn. uCRM's names pass through QuoteOrganization::plain.
     */
    private static function directWhyNot(array $off, array $pk, ?int $installId): string
    {
        $listed = function (string $kind, int $id) use ($off): string {
            foreach ((array)($off['not_offered'] ?? []) as $n) {
                if (is_array($n) && ($n['kind'] ?? '') === $kind && (int)($n['id'] ?? 0) === $id) return (string)($n['reason'] ?? '');
            }
            return '';
        };
        if ($pk['kind'] === 'plan') {
            $r = $listed('plan', $pk['sp']);
            if ($r !== '') return $r;
            foreach ((array)$off['plans'] as $row) {
                if ((int)$row['sp'] === $pk['sp']) {
                    return 'the uCRM product of the service plan ' . QuoteOrganization::plain((string)($row['name'] ?? '')) . ' changed since the page was opened — reload';
                }
            }
            return 'uCRM service plan #' . $pk['sp'] . ' is not offered on the forms — reload';
        }
        $in = is_array($off['install'] ?? null) ? $off['install'] : [];
        if ($pk['kind'] === 'install') {
            if (empty($in['ok'])) return (string)($in['reason'] ?? '') !== '' ? (string)$in['reason'] : 'the installation product is not set';
            return 'the installation product was changed on the uCRM catalogue screen since the page was opened — reload';
        }
        if ($installId !== null && $pk['p'] === $installId) {
            $shown = (!empty($in['ok']) && is_array($in['line'] ?? null)) ? QuoteOrganization::plain((string)$in['line']['label']) : 'uCRM product #' . $pk['p'];
            return $shown . ' is the installation product, so it cannot be quoted as hardware — add it as the installation';
        }
        foreach ((array)$off['plans'] as $row) {
            if ((int)$row['p'] === $pk['p']) return QuoteOrganization::plain((string)$row['label']) . ' is a service plan\'s product in uCRM — add it from the plan list';
        }
        $r = $listed('product', $pk['p']);
        return $r !== '' ? $r : 'uCRM product #' . $pk['p'] . ' is not offered on the forms — reload';
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
                'pdf_attached'  => true,   // this path returned above when there was no PDF
            ]);
            $subject = $built['subject'];

            // Same CC rule as every other customer email: when email_cc_contacts
            // is on, the client's other contact addresses are copied. Off by
            // default, so this send is unchanged until an operator turns it on.
            $qHeaders = ['Reply-To' => $cmail];
            if (CustomerEmailDispatcher::ccEnabled($this->config)) {
                $qcc = EmailRecipients::ccFor($client, $email);
                if ($qcc) $qHeaders['Cc'] = implode(', ', $qcc);
            }
            // 5.18.104 (root docs/73 §H.5.8.1) P7: the account this quote was made as is copied, visibly, in this same e-mail —
            // Uganda with P7's switch on, an agent whose address is usable, is not the customer's and fits the Cc line. Off, on
            // South Sudan, or on any throw: the headers above are 5.18.103's, and no decision is recorded.
            $p7 = null;
            require_once __DIR__ . '/StaffJobsGate.php';
            if (StaffJobsGate::applies($this->config, $this->dataDir)) {
                try {
                    require_once __DIR__ . '/QuoteAgentCopy.php';
                    $p7 = QuoteAgentCopy::forActor($this->store, dirname(__DIR__), $this->dataDir, $retailer, $email, $qcc ?? []);
                    if ($p7 !== null && $p7['address'] !== '') {
                        [$p7Cc, $p7Code] = QuoteAgentCopy::mergeCc($qcc ?? [], $p7['address'], $email);
                        if ($p7Code !== '') $p7 = QuoteAgentCopy::skipped($p7, $p7Code);
                        else $qHeaders['Cc'] = implode(', ', $p7Cc);                         // the last statement: nothing after it can throw
                    }
                } catch (\Throwable $e) { $p7 = null; }
            }
            $send = $mail->send($email, $name !== '' ? $name : 'Customer',
                $subject, $built['html'], $built['text'],
                $qHeaders,
                [[
                    'name'    => 'Quotation-' . (preg_replace('/[^A-Za-z0-9_\-]/', '', $number) ?: 'quote') . '.pdf',
                    'mime'    => 'application/pdf',
                    'content' => $pdf,
                ]]
            );
            if ($p7 !== null) {   // 5.18.104 (root docs/73 §H.5.8.1) P7: the decision, after the send; never the address
                try {
                    QuoteAgentCopy::record($this->store, 'plugin', $quoteId, $number, $p7, !empty($send['ok']),
                        QuoteAgentCopy::rcptOutcome((array)($send['log'] ?? []), $p7['address']));
                } catch (\Throwable $e) {}
            }
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
        // 5.18.102 (Uganda, docs/71 §J): the one plan line uCRM prices whose key is exactly the interest plan's
        // (PublicPriceFeed::planKey), read fresh with the client the quote is made with — or none. Never a name contained
        // in another, never a placeholder at 0, never the Fiber fee (South Sudan's). With none, sendLeadQuote asks for
        // the items to be added by hand.
        if ($this->quoteOrg()['on']) {
            self::needQuoteLines();
            // 5.18.103 (root docs/72 §G.9): in direct mode, the one offered uCRM plan keyed exactly like the interest, or none
            if ($this->directMode()) return $this->directLeadItems($lead);
            $interest = is_scalar($lead['interest_plan'] ?? null) ? (string)$lead['interest_plan'] : '';
            $key = PublicPriceFeed::planKey($interest);
            if ($key === '') return [];
            $hit = $this->quotePlanKeys($this->quoteLines(false), $key)[$key] ?? null;
            return ($hit !== null && !empty($hit['ok']) && is_array($hit['line'] ?? null)) ? [$hit['line']] : [];
        }
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

    /**
     * 5.18.54 (docs/46 row 17, D2c): whether this screen sends the WhatsApp for quote $quoteId, which it has just made in
     * uCRM. uCRM then raises quote.add, and the webhook sends its own quotation unless the quote is claimed in the
     * ledger every quote sender honours (QuoteWaLedger). Claimed here first, the webhook's is refused; refused here, the
     * webhook was first and sends it with its PDF, so this screen does not. Either way the customer gets one.
     *
     * Uganda only: elsewhere, always true, as in 5.18.53. When the ledger cannot be written, true: this screen has no
     * fallback, and one message too many is better than none.
     */
    private function claimQuoteWa(int $quoteId, string $ref, string $source): bool
    {
        try {
            require_once __DIR__ . '/NotifyGate.php';
            if (!\NotifyGate::applies(\NotifyGate::QUOTE_ONCE, $this->config, $this->dataDir)) return true;
            require_once __DIR__ . '/QuoteWaLedger.php';
            return \QuoteWaLedger::claim($this->store->getPdo(), $quoteId, $ref, $source);
        } catch (\Throwable $e) {
            error_log('[QuotationService] quote WhatsApp claim failed, sending anyway: ' . $e->getMessage());
            return true;
        }
    }

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
