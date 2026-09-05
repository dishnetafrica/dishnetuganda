<?php
declare(strict_types=1);

/**
 * EfrisInvoiceMapper — uCRM invoice + client → the plugin's INTERNAL fiscal
 * model. Pure transform: never talks to uCRM or EFRIS.
 *
 * Deliberately two-stage: uCRM → internal model here (frozen once a real
 * invoice JSON from this install is inspected with tools/efris_invoice_probe),
 * and internal model → official T109 field names in Phase 2, transcribed from
 * the official URA specification. Nothing in this file guesses an official
 * EFRIS field name, enumeration or rounding rule.
 *
 * The uCRM tax structure is read TOLERANTLY (items may carry taxes[], tax,
 * tax1..tax3, or taxRate/taxAmount depending on version) and the shape that
 * was actually found is recorded in meta.tax_shapes so the probe run can
 * confirm it. VAT rates are read from the invoice, NEVER hard-coded.
 */
class EfrisInvoiceMapper
{
    private array $config;
    private array $commodityMap;  // uCRM item label (lowercased) => URA goodsCategoryId (operator-entered, never guessed)
    private array $taxMap;        // uCRM tax name/id (lowercased) => category: standard|zero_rated|exempt|non_taxable|other
    private array $taxRegistry;   // uCRM tax id => ['name' =>, 'rate' =>] from GET /taxes (probe-confirmed shape)

    public function __construct(array $config, array $commodityMap = [], array $taxMap = [],
                                array $taxRegistry = [])
    {
        $this->config = $config;
        $this->commodityMap = array_change_key_case($commodityMap, CASE_LOWER);
        $this->taxMap = array_change_key_case($taxMap, CASE_LOWER);
        $this->taxRegistry = $taxRegistry;
    }

    /**
     * @return array{ok:bool, errors:string[], warnings:string[], model:array}
     */
    public function map(array $invoice, array $client): array
    {
        $errors = []; $warnings = [];

        // ── Seller: constants from configuration, nothing per-invoice ──
        $seller = [
            'tin'          => trim((string)($this->config['efris_tin'] ?? '')),
            'legal_name'   => trim((string)($this->config['efris_legal_name'] ?? 'DishNet Africa Limited')),
            'business_name'=> trim((string)($this->config['efris_business_name'] ?? 'DishNet Uganda')),
            'address'      => trim((string)($this->config['efris_address'] ?? '')),
            'phone'        => trim((string)($this->config['efris_phone'] ?? '')),
            'email'        => trim((string)($this->config['efris_email'] ?? '')),
            'device_no'    => trim((string)($this->config['efris_device_no'] ?? '')),
        ];
        if ($seller['tin'] === '')       $errors[] = 'Seller TIN missing — set efris_tin in Configuration';
        if ($seller['device_no'] === '') $errors[] = 'EFRIS device number missing — set efris_device_no (issued by URA)';

        // ── Invoice basics ──
        $ucrmId = (int)($invoice['id'] ?? 0);
        $number = trim((string)($invoice['number'] ?? $invoice['invoiceNumber'] ?? ''));
        $status = (int)($invoice['status'] ?? -1);   // 0 draft, 1 unpaid, 2 partial, 3 paid, 4 void
        if ($ucrmId <= 0)   $errors[] = 'Invoice has no uCRM id';
        if ($number === '') $errors[] = 'Invoice has no number (draft?) — only approved invoices are fiscalised';
        if ($status === 0)  $errors[] = 'Invoice is a DRAFT — approve it in uCRM first';
        if ($status === 4)  $errors[] = 'Invoice is VOID in uCRM — a void invoice is never fiscalised';

        if (!empty($invoice['proforma'])) {
            $errors[] = 'This is a PROFORMA — only the final invoice is fiscalised';
        }

        $currency = trim((string)($invoice['currencyCode'] ?? $invoice['currency'] ?? ''));
        if ($currency === '') $errors[] = 'Invoice has no currency code';

        $issued = substr((string)($invoice['createdDate'] ?? $invoice['createdAt'] ?? ''), 0, 19);
        $due    = substr((string)($invoice['maturityDate'] ?? $invoice['dueDate'] ?? ''), 0, 10);
        // Probe-confirmed: this install carries taxableSupplyDate — the VAT
        // time-of-supply, which EFRIS cares about more than the created date.
        $supply = substr((string)($invoice['taxableSupplyDate'] ?? ''), 0, 10);

        $payStatus = $status === 3 ? 'paid' : ($status === 2 ? 'partial' : 'unpaid');

        // ── Buyer ──
        $attrs = $this->clientAttributes($client);
        $firstLast = trim(((string)($client['firstName'] ?? '')) . ' ' . ((string)($client['lastName'] ?? '')));
        $company   = trim((string)($client['companyName'] ?? ''));
        $buyerName = $company !== '' ? $company : $firstLast;
        if ($buyerName === '') $warnings[] = 'Buyer has no name in uCRM';

        $phone = ''; $email = '';
        foreach (($client['contacts'] ?? []) as $c) {
            if ($phone === '' && !empty($c['phone'])) $phone = trim((string)$c['phone']);
            if ($email === '' && !empty($c['email'])) $email = trim((string)$c['email']);
        }

        // Probe-confirmed: uCRM natively stores a Tax ID and a registration
        // number on the client — those are the primary source; the EFRIS
        // custom attributes override them (and carry NIN, which uCRM lacks).
        $tin = $attrs['tin'] !== '' ? $attrs['tin'] : trim((string)($client['companyTaxId'] ?? ''));
        $brn = $attrs['brn'] !== '' ? $attrs['brn'] : trim((string)($client['companyRegistrationNumber'] ?? ''));
        $nin = $attrs['nin'];
        if ($tin !== '' && !preg_match('/^\d{10}$/', $tin)) {
            $warnings[] = "Buyer TIN '{$tin}' is not 10 digits — confirm before production";
        }
        // Probe-confirmed: clientType 1 = residential person, 2 = company.
        $clientType = (int)($client['clientType'] ?? 0);
        $buyerType = $clientType === 2 ? 'business'
                   : ($clientType === 1 ? 'individual'
                   : (($tin !== '' || $company !== '') ? 'business' : 'individual'));

        $buyer = [
            'type'          => $buyerType,
            'name'          => $buyerName,
            'company_name'  => $company,
            'tin'           => $tin,
            'brn'           => $brn,
            'nin'           => $nin,
            'taxpayer_type' => $attrs['taxpayer_type'],
            'address'       => $this->clientAddress($client),
            'phone'         => $phone,
            'email'         => $email,
            'ucrm_client_id'=> (int)($client['id'] ?? ($invoice['clientId'] ?? 0)),
        ];

        // ── Items with tolerant tax reading ──
        $items = []; $shapes = [];
        $subtotal = 0.0; $taxTotal = 0.0;
        $rawItems = $invoice['items'] ?? [];
        if (!is_array($rawItems) || count($rawItems) === 0) {
            $errors[] = 'Invoice has no line items';
            $rawItems = [];
        }
        foreach ($rawItems as $i => $it) {
            $label = trim((string)($it['label'] ?? $it['name'] ?? $it['description'] ?? ''));
            $qty   = (float)($it['quantity'] ?? 1);
            $unit  = (float)($it['price'] ?? $it['unitPrice'] ?? 0);
            $line  = isset($it['total']) ? (float)$it['total'] : $qty * $unit;
            $disc  = (float)($it['discountTotal'] ?? $it['discount'] ?? 0);
            if ($label === '') $errors[] = 'Item #' . ($i + 1) . ' has no label';
            if ($qty <= 0)     $warnings[] = "Item '{$label}': quantity {$qty}";

            [$taxInfo, $shape] = $this->readItemTax($it);
            $shapes[$shape] = true;

            $lookup = strtolower($label);
            $commodity = $this->commodityMap[$lookup] ?? null;
            if ($commodity === null) {
                $warnings[] = "Item '{$label}' has no URA commodity code mapped — configure it in the EFRIS tab before production";
            }
            $taxCategory = $this->resolveTaxCategory($taxInfo);
            if ($taxCategory === null) {
                $warnings[] = "Item '{$label}': tax category not resolvable from uCRM tax data — map it in the EFRIS tab";
            }

            $subtotal += $line;
            $taxTotal += (float)($taxInfo['amount'] ?? 0);

            $items[] = [
                'label'         => $label,
                'qty'           => $qty,
                'unit_price'    => $unit,
                'discount'      => $disc,
                'line_total'    => $line,
                'tax'           => $taxInfo,        // null when the item carries none
                'tax_category'  => $taxCategory,    // standard|zero_rated|exempt|non_taxable|other|null
                'commodity_code'=> $commodity,      // operator-mapped URA code, never guessed
            ];
        }

        $grand = isset($invoice['total']) ? (float)$invoice['total'] : $subtotal;
        $paid  = isset($invoice['amountPaid']) ? (float)$invoice['amountPaid']
               : (isset($invoice['amountToPay']) ? max(0.0, $grand - (float)$invoice['amountToPay']) : null);

        $model = [
            'seller'  => $seller,
            'invoice' => [
                'ucrm_id'             => $ucrmId,
                'number'              => $number,
                'issued_date'         => $issued,
                'due_date'            => $due,
                'taxable_supply_date' => $supply,
                'currency'            => $currency,
                'ucrm_status'         => $status,
                'payment_status'      => $payStatus,
                'amount_paid'         => $paid,
            ],
            'buyer'  => $buyer,
            'items'  => $items,
            // Probe-confirmed: the invoice carries authoritative subtotal and
            // totalTaxAmount — prefer uCRM's own arithmetic over re-summing,
            // so pricing mode (tax-inclusive vs exclusive) can never skew us.
            'totals' => [
                'subtotal'  => round(isset($invoice['subtotal']) ? (float)$invoice['subtotal'] : $subtotal, 2),
                'tax_total' => round(isset($invoice['totalTaxAmount']) ? (float)$invoice['totalTaxAmount'] : $taxTotal, 2),
                'grand'     => round($grand, 2),
                'discount'  => round((float)($invoice['totalDiscount'] ?? 0), 2),
                'tax_breakdown' => array_values(array_map(
                    fn($t) => ['name' => (string)($t['name'] ?? ''),
                               'amount' => (float)($t['totalValue'] ?? $t['amount'] ?? 0)],
                    is_array($invoice['taxes'] ?? null) ? $invoice['taxes'] : [])),
            ],
            'meta' => [
                'tax_shapes' => array_keys($shapes),
                'mapped_at'  => gmdate('c'),
            ],
        ];

        return ['ok' => count($errors) === 0, 'errors' => $errors,
                'warnings' => $warnings, 'model' => $model];
    }

    /**
     * uCRM items have carried taxes in several shapes across versions.
     * Return [taxInfo|null, shapeName]; taxInfo = {name, rate, amount, ucrm_tax_id}.
     */
    private function readItemTax(array $it): array
    {
        // Shape A: items[].taxes = [{name, rate|percent, totalValue|amount, id}]
        if (!empty($it['taxes']) && is_array($it['taxes'])) {
            $t = $it['taxes'][0];
            if (count($it['taxes']) > 1) {
                // keep the sum but flag it — multi-tax items exist in uCRM
                $amount = 0.0;
                foreach ($it['taxes'] as $x) $amount += (float)($x['totalValue'] ?? $x['amount'] ?? 0);
                return [[
                    'name' => 'multiple', 'rate' => null, 'amount' => $amount,
                    'ucrm_tax_id' => null, 'multi' => true,
                ], 'taxes[] (multiple)'];
            }
            return [[
                'name'        => (string)($t['name'] ?? ''),
                'rate'        => isset($t['rate']) ? (float)$t['rate'] : (isset($t['percent']) ? (float)$t['percent'] : null),
                'amount'      => (float)($t['totalValue'] ?? $t['amount'] ?? 0),
                'ucrm_tax_id' => isset($t['id']) ? (int)$t['id'] : null,
            ], 'taxes[]'];
        }
        // Shape B: items[].tax = {...}
        if (!empty($it['tax']) && is_array($it['tax'])) {
            $t = $it['tax'];
            return [[
                'name'        => (string)($t['name'] ?? ''),
                'rate'        => isset($t['rate']) ? (float)$t['rate'] : null,
                'amount'      => (float)($t['totalValue'] ?? $t['amount'] ?? 0),
                'ucrm_tax_id' => isset($t['id']) ? (int)$t['id'] : null,
            ], 'tax{}'];
        }
        // Shape C (PROBE-CONFIRMED on this install): items carry only
        // tax1Id/tax2Id/tax3Id references; name and rate live in uCRM's
        // /taxes registry, passed in as $taxRegistry. The per-item tax AMOUNT
        // is deliberately left null here — computing it needs the org's
        // pricing mode (tax-inclusive vs exclusive) and the official EFRIS
        // rounding rules, which is Phase-2 translation work. Invoice-level
        // totalTaxAmount stays the authoritative total meanwhile.
        foreach (['tax1Id', 'tax2Id', 'tax3Id'] as $k) {
            if (!empty($it[$k])) {
                $id  = (int)$it[$k];
                $reg = $this->taxRegistry[$id] ?? [];
                return [[
                    'name'        => (string)($reg['name'] ?? ''),
                    'rate'        => isset($reg['rate']) ? (float)$reg['rate'] : null,
                    'amount'      => null,
                    'ucrm_tax_id' => $id,
                ], $k];
            }
        }
        // Shape C-legacy: tax1..tax3 as embedded objects
        foreach (['tax1', 'tax2', 'tax3'] as $k) {
            if (!empty($it[$k])) {
                $t = is_array($it[$k]) ? $it[$k] : ['id' => $it[$k]];
                return [[
                    'name'        => (string)($t['name'] ?? $k),
                    'rate'        => isset($t['rate']) ? (float)$t['rate'] : null,
                    'amount'      => (float)($t['totalValue'] ?? $t['amount'] ?? 0),
                    'ucrm_tax_id' => isset($t['id']) ? (int)$t['id'] : null,
                ], $k];
            }
        }
        // Shape D: flat rate/amount
        if (isset($it['taxRate']) || isset($it['taxAmount'])) {
            return [[
                'name'        => (string)($it['taxName'] ?? ''),
                'rate'        => isset($it['taxRate']) ? (float)$it['taxRate'] : null,
                'amount'      => (float)($it['taxAmount'] ?? 0),
                'ucrm_tax_id' => null,
            ], 'taxRate/taxAmount'];
        }
        return [null, 'none'];
    }

    /** Map a uCRM tax to an EFRIS-style category via the operator's mapping. */
    private function resolveTaxCategory(?array $taxInfo): ?string
    {
        if ($taxInfo === null) {
            // No tax on the line: the OPERATOR decides whether that means
            // zero-rated, exempt or non-taxable — never assumed here.
            return $this->taxMap['__no_tax__'] ?? null;
        }
        $byId   = $taxInfo['ucrm_tax_id'] !== null ? ('id:' . $taxInfo['ucrm_tax_id']) : null;
        $byName = strtolower(trim((string)($taxInfo['name'] ?? '')));
        if ($byId !== null && isset($this->taxMap[$byId]))  return $this->taxMap[$byId];
        if ($byName !== '' && isset($this->taxMap[$byName])) return $this->taxMap[$byName];
        return null;
    }

    /** Read EFRIS fields from uCRM client custom attributes (never a second DB). */
    private function clientAttributes(array $client): array
    {
        $out = ['tin' => '', 'brn' => '', 'nin' => '', 'taxpayer_type' => ''];
        foreach (($client['attributes'] ?? []) as $a) {
            $key = strtolower(preg_replace('/[^a-z0-9]/i', '',
                (string)($a['key'] ?? $a['name'] ?? '')));
            $val = trim((string)($a['value'] ?? ''));
            if ($val === '') continue;
            if (substr($key, -3) === 'tin' || $key === 'tin')            $out['tin'] = $val;
            elseif (substr($key, -3) === 'brn')                           $out['brn'] = $val;
            elseif (substr($key, -3) === 'nin')                           $out['nin'] = $val;
            elseif (strpos($key, 'taxpayertype') !== false)               $out['taxpayer_type'] = $val;
        }
        return $out;
    }

    private function clientAddress(array $client): string
    {
        $parts = array_filter([
            trim((string)($client['street1'] ?? '')),
            trim((string)($client['street2'] ?? '')),
            trim((string)($client['city'] ?? '')),
        ], fn($p) => $p !== '');
        return implode(', ', $parts);
    }
}
