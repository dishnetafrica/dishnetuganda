<?php
declare(strict_types=1);

require_once __DIR__ . '/EfrisClient.php';

/**
 * WeafEfrisClient — the WEAF Company EFRIS gateway transport.
 *
 * WEAF (weafcompany.com) exposes URA EFRIS behind a bearer-token REST API:
 * they hold the device/crypto against URA, we POST near-raw URA structures
 * to /api/{tin}/<endpoint>?environment=Sandbox|Production. Every field name
 * and dictionary code below is transcribed VERBATIM from their official
 * Postman collection (WEAF_EFRIS_WEB_API, 2026-09) — nothing is guessed;
 * where their samples leave a dictionary ambiguous the sample's own value is
 * used and marked for the vendor meeting.
 *
 * Selected via efris_gateway=weaf. Environment rules stay the house rules:
 *   - disabled → refuse;
 *   - test     → environment=Sandbox against efris_weaf_base_url;
 *   - production → REFUSE until WEAF's URA accreditation is verified in
 *     writing and DishNet's own TIN is onboarded under the account.
 *
 * The response is normalised to the same content keys EfrisService already
 * reads (fdn, verificationCode, qrCode, referenceNo, fiscalisedAt), so the
 * whole pipeline — queue, idempotency, audit rows, PDFs — is unchanged.
 */
class WeafEfrisClient extends EfrisClient
{
    private array  $wcfg;
    private string $wBase   = '';
    private string $wToken  = '';
    private string $wTin    = '';
    private string $wEnvArg = 'Sandbox';
    private string $wRefuse = '';
    private int    $wTimeout;

    public function __construct(array $config, int $timeout = 25)
    {
        parent::__construct($config, $timeout);
        $this->wcfg     = $config;
        $this->wTimeout = $timeout;

        $env = $this->environment();
        if ($env === self::ENV_DISABLED) {
            $this->wRefuse = 'EFRIS is disabled (efris_environment=disabled).';
        } elseif ($env === self::ENV_PRODUCTION) {
            $this->wRefuse = 'PRODUCTION via the WEAF gateway is locked: verify their URA accreditation '
                           . 'in writing and onboard DishNet\'s own TIN first. Nothing was sent.';
        } else {
            $this->wBase  = rtrim(trim((string)($config['efris_weaf_base_url'] ?? 'https://weafcompany.com')), '/');
            $this->wToken = trim((string)($config['efris_weaf_token'] ?? ''));
            $this->wTin   = trim((string)($config['efris_tin'] ?? ''));
            if ($this->wToken === '') $this->wRefuse = 'efris_weaf_token is not set — generate one against the WEAF API and add it in Configuration.';
            elseif ($this->wTin === '') $this->wRefuse = 'efris_tin is not set — the WEAF API addresses everything by TIN.';
        }
    }

    public function isUsable(): bool        { return $this->wRefuse === ''; }
    public function refusalReason(): string { return $this->wRefuse; }

    /** Reachability + auth probe: their validate-token endpoint. */
    public function ping(): array
    {
        return $this->request('POST', '/api/v1/auth/validate-token', ['token' => $this->wToken], false);
    }

    public function submitInvoice(array $model): array
    {
        [$payload, $err] = $this->translateInvoice($model);
        if ($err !== '') return $this->localFail($err);
        $r = $this->request('POST', $this->tinPath('generate-fiscal-invoice'), $payload);
        if (!$r['ok']) return $r;
        $d = (array)($r['content'] ?? []);
        $basic = (array)($d['basicInformation'] ?? []);
        $sum   = (array)($d['summary'] ?? []);
        $fdn   = trim((string)($basic['invoiceNo'] ?? ''));
        $r['content'] = [
            'fdn'              => $fdn,
            'verificationCode' => trim((string)($basic['antifakeCode'] ?? '')),
            'qrCode'           => trim((string)($sum['qrCode'] ?? '')),
            'referenceNo'      => $fdn,
            'fiscalisedAt'     => $this->weafDate((string)($basic['issuedDate'] ?? '')),
            'gross'            => (string)($sum['grossAmount'] ?? ''),
            'net'              => (string)($sum['netAmount'] ?? ''),
            'tax'              => (string)($sum['taxAmount'] ?? ''),
        ];
        return $r;
    }

    public function queryTin(string $tin): array
    {
        $r = $this->request('POST', $this->tinPath('search-taxpayer'),
            ['tin' => trim($tin), 'ninBrn' => '']);
        if (!$r['ok']) return $r;
        $d = (array)($r['content'] ?? []);
        $name = trim((string)($d['taxpayerName'] ?? $d['legalName'] ?? $d['businessName'] ?? ''));
        $r['content'] = [
            'taxpayerName' => $name !== '' ? $name : 'REGISTERED',
            'status'       => 'REGISTERED',
        ];
        return $r;
    }

    public function uploadGoods(array $payload): array
    {
        $g = (array)($payload['goods'] ?? []);
        $name = trim((string)($g['name'] ?? ''));
        $cur  = strtoupper(trim((string)($g['currency'] ?? 'UGX')));
        if ($cur !== 'UGX') {
            return $this->localFail("WEAF product registration is mapped for UGX only so far — '{$name}' is {$cur}; confirm the currency dictionary code with WEAF first.");
        }
        // Verbatim WEAF/URA product shape; their own sample sets goodsCode
        // equal to the goods name, and invoices reference items by that same
        // code — so the item NAME is the join key across the whole gateway.
        $product = [
            'goodsName'           => $name,
            'goodsCode'           => $name,
            'measureUnit'         => $this->unitCode((string)($g['unit'] ?? 'each')),
            'unitPrice'           => (string)(float)($g['unit_price'] ?? 0),
            'currency'            => '101',            // UGX per the WEAF sample dictionary
            'commodityCategoryId' => trim((string)($g['commodity_code'] ?? '')),
            'haveExciseTax'       => '102',            // no excise
            'description'         => $name,
            'stockPrewarning'     => '10',
            'pieceMeasureUnit'    => '',
            'havePieceUnit'       => '102',
            'pieceUnitPrice'      => '',
            'packageScaledValue'  => '',
            'customsMeasureUnit'  => '',
            'customsScaledValue'  => '',
            'customsUnitPrice'    => '',
            'packageScaledValueCustoms' => '',
            'pieceScaledValue'    => '',
            'exciseDutyCode'      => '',
            'operationType'       => '102',            // per the WEAF sample
        ];
        $r = $this->request('POST', $this->tinPath('register-product'), ['products' => [$product]]);
        if (!$r['ok']) return $r;
        // Their acknowledgement carries no reference of its own — record the
        // gateway ack + our request id as the registration reference.
        $ref = trim((string)(((array)($r['content'] ?? []))['referenceNo'] ?? ''));
        $r['content'] = ['goodsReference' => $ref !== '' ? $ref : ('WEAF-ACK-' . substr($r['request_id'], 0, 12))];
        return $r;
    }

    public function stockMaintain(array $payload): array
    {
        $s  = (array)($payload['stock'] ?? []);
        $op = (string)($s['op'] ?? '');
        $item = [
            'itemCode'    => trim((string)($s['goods_code'] ?? '')),
            'quantity'    => (float)($s['qty'] ?? 0),
            'unitPrice'   => (float)($s['unit_price'] ?? 0),
            'measureUnit' => $this->unitCode((string)($s['unit'] ?? 'each')),
        ];
        if ($op === 'increase') {
            $body = [
                'invoiceNo'         => '',
                'remarks'           => trim((string)($s['note'] ?? '')) ?: trim((string)($s['reason'] ?? '')),
                'branchId'          => '',
                'stockInDate'       => gmdate('Y-m-d'),
                'stockInType'       => '102',          // local purchase, per the WEAF sample
                'stockInItem'       => [$item],
                'supplierName'      => trim((string)($s['supplier'] ?? '')),
                'supplierTin'       => '',
                'productionBatchNo' => '',
                'productionDate'    => '',
            ];
            $r = $this->request('POST', $this->tinPath('increase-stock'), $body);
        } else {
            $body = [
                'remarks'     => trim(((string)($s['reason'] ?? '')) . ' ' . ((string)($s['note'] ?? ''))),
                'branchId'    => '',
                'stockInItem' => [$item + ['itemName' => null]],
                'adjustType'  => '105',                // per the WEAF sample; dictionary with vendor
            ];
            $r = $this->request('POST', $this->tinPath('decrease-stock'), $body);
        }
        if (!$r['ok']) return $r;
        $r['content'] = ['stockReference' => (string)($r['message_raw'] ?? 'OK')];
        return $r;
    }

    public function applyCreditNote(array $model): array
    {
        $oriFdn = trim((string)($model['original']['fdn'] ?? ''));
        if ($oriFdn === '') return $this->localFail('Credit note needs the original fiscal invoice number');
        $body = [
            'generalInfo' => [
                'oriInvoiceNo'             => $oriFdn,
                'reasonCode'               => '102',   // cancellation/refund per the WEAF sample; dictionary with vendor
                'reason'                   => trim((string)($model['reason'] ?? '')),
                'invoiceApplyCategoryCode' => '101',
                'remarks'                  => trim((string)($model['reason'] ?? '')),
                'sellersReferenceNo'       => trim((string)($model['credit_note']['number'] ?? '')),
            ],
        ];
        $r = $this->request('POST', $this->tinPath('apply-for-creditnote'), $body);
        if (!$r['ok']) return $r;
        $ref = trim((string)(((array)($r['content'] ?? []))['referenceNo'] ?? ''));
        if ($ref === '') return $this->localFail('WEAF returned no credit-note application reference');
        $r['content'] = [
            'fdn'          => $ref,
            'referenceNo'  => $ref,
            'fiscalisedAt' => gmdate('Y-m-d H:i:s'),
        ];
        return $r;
    }

    public function cancelCreditNote(array $payload): array
    {
        return $this->localFail('The WEAF gateway does not expose credit-note cancellation (T114) — '
            . 'raised with the vendor; the URA-direct connector will carry it.');
    }

    // ── translation ─────────────────────────────────────────────────────────

    /** Internal invoice model → WEAF generate-fiscal-invoice payload (public for tests). */
    public function translateInvoice(array $m): array
    {
        $inv    = (array)($m['invoice'] ?? []);
        $seller = (array)($m['seller'] ?? []);
        $buyer  = (array)($m['buyer'] ?? []);
        $items  = (array)($m['items'] ?? []);
        if (!$items) return [[], 'No line items to fiscalise'];

        $itemsBought = [];
        foreach ($items as $it) {
            $label = trim((string)($it['label'] ?? ''));
            $line  = (float)($it['line_total'] ?? 0);
            $cat   = (string)($it['tax_category'] ?? '');
            if ($cat !== 'standard') {
                return [[], "Item '{$label}': only the standard VAT rule is mapped for the WEAF gateway so far "
                          . "(category '{$cat}') — confirm the taxRule dictionary with WEAF before sending others."];
            }
            // Per-item net: uCRM prices are VAT-inclusive on this install; use
            // the item's own tax amount when uCRM provided one, else the
            // standard-rate arithmetic (line / 1.18) matching WEAF's sample.
            $taxAmt = isset($it['tax']['amount']) && $it['tax']['amount'] !== null ? (float)$it['tax']['amount'] : null;
            $net    = $taxAmt !== null ? round($line - $taxAmt, 2) : round($line / 1.18, 2);
            $disc   = (float)($it['discount'] ?? 0);
            $itemsBought[] = [
                'itemCode'      => $label,             // the name is the gateway join key
                'quantity'      => (float)($it['qty'] ?? 1),
                'unitPrice'     => (float)($it['unit_price'] ?? 0),
                'total'         => $line,
                'taxForm'       => '101',
                'taxRule'       => 'STANDARD',
                'netAmount'     => $net,
                'discountFlag'  => $disc > 0 ? 1 : 2,
                'deemedFlag'    => 2,
                'discountTotal' => $disc > 0 ? (string)$disc : '',
                'exciseFlag'    => '2',
                'exciseRate'    => '',
                'exciseUnit'    => '',
                'exciseTax'     => '',
                'exciseCurrency'=> (string)($inv['currency'] ?? 'UGX'),
            ];
        }

        $issued = trim((string)($inv['issued_date'] ?? ''));
        $ts = $issued !== '' ? strtotime($issued) : false;
        $payload = ['data' => [
            'sellerDetails' => [
                'placeOfBusiness' => (string)($seller['address'] ?? ''),
                'referenceNo'     => (string)($inv['number'] ?? ''),
                'issuedDate'      => $ts !== false ? gmdate('d/m/Y H:i:s', $ts) : gmdate('d/m/Y H:i:s'),
                'branchId'        => '',
                'remarks'         => '',
            ],
            'basicInformation' => [
                'operator'            => (string)($seller['business_name'] ?? $seller['legal_name'] ?? 'DishNet'),
                'currency'            => (string)($inv['currency'] ?? 'UGX'),
                'invoiceType'         => 1,
                'invoiceKind'         => 1,
                'paymentMode'         => '101',
                'invoiceIndustryCode' => '101',
            ],
            'buyerDetails' => [
                'buyerTin'          => (string)($buyer['tin'] ?? ''),
                'buyerBusinessName' => (string)($buyer['name'] ?? ''),
                'buyerAddress'      => (string)($buyer['address'] ?? ''),
                'buyerEmail'        => (string)($buyer['email'] ?? ''),
                'buyerLinePhone'    => (string)($buyer['phone'] ?? ''),
                'buyerMobilePhone'  => (string)($buyer['phone'] ?? ''),
                'buyerType'         => (string)($buyer['type_code'] ?? 1),
                'buyerNinBrn'       => (string)($buyer['nin'] ?? ($buyer['brn'] ?? '')),
                'buyerPassportNum'  => '',
            ],
            'itemsBought' => $itemsBought,
        ]];
        return [$payload, ''];
    }

    private function unitCode(string $unit): string
    {
        // UN/ECE-style codes per the WEAF samples (PCE pieces, KGM kilos).
        // Time/service units pend the master-data dictionary — PCE meanwhile.
        return ['each' => 'PCE', 'kg' => 'KGM', 'litre' => 'LTR', 'metre' => 'MTR'][$unit] ?? 'PCE';
    }

    private function weafDate(string $dmy): string
    {
        // "01/10/2025 10:21:20" (d/m/Y) → "2025-10-01 10:21:20"
        if (preg_match('#^(\d{2})/(\d{2})/(\d{4})[ T](\d{2}:\d{2}:\d{2})$#', trim($dmy), $m)) {
            return "{$m[3]}-{$m[2]}-{$m[1]} {$m[4]}";
        }
        return gmdate('Y-m-d H:i:s');
    }

    // ── transport ───────────────────────────────────────────────────────────

    private function tinPath(string $endpoint): string
    {
        return '/api/' . rawurlencode($this->wTin) . '/' . $endpoint . '?environment=' . rawurlencode($this->wEnvArg);
    }

    private function localFail(string $msg): array
    {
        return ['ok' => false, 'error' => $msg, 'http' => 0, 'envelope' => null,
                'content' => null, 'raw' => '', 'request_id' => bin2hex(random_bytes(16))];
    }

    /** Same result contract as EfrisClient::call, over WEAF's REST envelope. */
    private function request(string $method, string $path, ?array $body, bool $envFields = true): array
    {
        $requestId = bin2hex(random_bytes(16));
        if (!$this->isUsable()) {
            return ['ok' => false, 'error' => $this->wRefuse, 'http' => 0,
                    'envelope' => null, 'content' => null, 'raw' => '', 'request_id' => $requestId];
        }
        if ($body !== null && $envFields) {
            $body['environment']           = $this->wEnvArg;
            $body['deploymentEnvironment'] = $this->wEnvArg;
        }
        $ch = curl_init($this->wBase . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_POSTFIELDS     => $body !== null ? json_encode($body) : null,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Accept: application/json',
                'Authorization: Bearer ' . $this->wToken,
                'X-Environment: ' . $this->wEnvArg,
            ],
            CURLOPT_TIMEOUT        => $this->wTimeout,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);
        $raw  = curl_exec($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            return ['ok' => false, 'error' => 'Connection failed: ' . $err, 'http' => 0,
                    'envelope' => null, 'content' => null, 'raw' => '', 'request_id' => $requestId];
        }
        $resp = json_decode((string)$raw, true);
        if (!is_array($resp)) {
            return ['ok' => false, 'error' => 'Malformed response (not JSON)', 'http' => $http,
                    'envelope' => null, 'content' => null,
                    'raw' => mb_substr((string)$raw, 0, 2000), 'request_id' => $requestId];
        }
        $rc  = (string)($resp['status']['returnCode'] ?? '');
        $msg = (string)($resp['status']['returnMessage'] ?? '');
        $ok  = $http < 400 && in_array($rc, ['00', '0'], true);
        $data = $resp['data'] ?? null;
        return ['ok' => $ok,
                'error' => $ok ? '' : trim($msg . (is_string($data) && $data !== '' && $data !== $msg ? ' — ' . $data : '')),
                'message_raw' => $msg,
                'http' => $http, 'envelope' => $resp,
                'content' => is_array($data) ? $data : null,
                'raw' => mb_substr((string)$raw, 0, 4000), 'request_id' => $requestId];
    }
}
