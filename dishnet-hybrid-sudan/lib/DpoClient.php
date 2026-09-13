<?php
declare(strict_types=1);

/**
 * DpoClient — the two DPO Pay calls, and nothing else.
 *
 * No business logic lives here. It builds XML, posts it, parses what comes
 * back, and reports honestly what DPO said. Whether a result means an invoice
 * is settled is DpoPaymentService's decision, not this class's.
 *
 * ── WHAT IS READ FROM DPO'S OWN SOURCE ──────────────────────────────────
 *
 * The endpoints, the XML shapes and both result-code tables are taken from
 * DPO Group's published production code — the DPO-Pay-Common class every
 * official DPO module uses, and their WooCommerce gateway. Two details from
 * there are easy to mistake for typos and are not:
 *
 *   · createToken posts to /API/v6/ and verifyToken posts to /API/v7/.
 *     Different versions, same class, in DPO's current release.
 *
 *   · the test and live API URLs are IDENTICAL. There is no sandbox host.
 *     "Test mode" is entirely which company token you send. This class
 *     therefore does NOT branch its URL on environment — inventing a test
 *     host would be a fiction that fails only in production.
 *
 * ── AUTHENTICATION ──────────────────────────────────────────────────────
 *
 * There is none in the HTTP sense: no header, no signature, no HMAC. The
 * credential is <CompanyToken> inside the body. So the token is a bearer
 * secret in a request body, and this class never logs the body, never
 * returns it, and redacts the token from anything it does surface.
 *
 * ── WHY THE PARSING IS DEFENSIVE ────────────────────────────────────────
 *
 * A missing field means "DPO did not tell us", which is not the same as
 * "it matched". verifiedAmount() and verifiedCurrency() return null when
 * absent, and the caller must treat null as unconfirmed rather than assume
 * agreement. Confirming a payment we could not check is how money goes
 * missing quietly.
 */
final class DpoClient
{
    /** From DPO's own class. Test and live are deliberately the same. */
    const API_CREATE = 'https://secure.3gdirectpay.com/API/v6/';
    const API_VERIFY = 'https://secure.3gdirectpay.com/API/v7/';
    const PAY_URL    = 'https://secure.3gdirectpay.com/payv2.php';

    /** createToken result codes, verbatim from DPO's published table. */
    const CREATE_CODES = [
        '000' => 'Transaction created',
        '801' => 'Request missing company token',
        '802' => 'Company token does not exist',
        '803' => 'No request or error in Request type name',
        '804' => 'Error in XML',
        '902' => 'Request missing transaction level mandatory fields',
        '904' => 'Currency not supported',
        '905' => 'The transaction amount has exceeded your allowed transaction limit',
        '906' => 'You exceeded your monthly transactions limit',
        '922' => 'Provider does not exist',
        '923' => 'Allocated money exceeds payment amount',
        '930' => 'Block payment code incorrect',
        '940' => 'CompanyRef already exists and paid',
        '950' => 'Request missing mandatory fields',
        '960' => 'Tag has been sent multiple times',
    ];

    /** verifyToken result codes, verbatim from DPO's published table. */
    const VERIFY_CODES = [
        '000' => 'Transaction Paid',
        '001' => 'Authorized',
        '002' => 'Transaction overpaid/underpaid',
        '801' => 'Request missing company token',
        '802' => 'Company token does not exist',
        '803' => 'No request or error in Request type name',
        '804' => 'Error in XML',
        '900' => 'Transaction not paid yet',
        '901' => 'Transaction declined',
        '902' => 'Data mismatch in one of the fields',
        '903' => 'The transaction passed the Payment Time Limit',
        '904' => 'Transaction cancelled',
        '950' => 'Request missing transaction level mandatory fields',
    ];

    private string $companyToken;
    private string $serviceType;
    private string $companyAccRef;
    private string $environment;
    private string $createUrl;
    private string $verifyUrl;
    private string $payUrl;
    private int    $timeout;
    private int    $ptl;
    private string $ptlType;

    /**
     * @param array $cfg company_token, service_type, company_acc_ref, environment,
     *                   ptl, ptl_type, timeout, and api_create/api_verify/pay_url
     *                   overrides used ONLY by the test harness.
     */
    public function __construct(array $cfg)
    {
        $this->companyToken  = (string)($cfg['company_token']   ?? '');
        $this->serviceType   = (string)($cfg['service_type']    ?? '');
        $this->companyAccRef = (string)($cfg['company_acc_ref'] ?? '');
        $this->environment   = ((string)($cfg['environment'] ?? 'test')) === 'live' ? 'live' : 'test';
        $this->createUrl     = (string)($cfg['api_create'] ?? self::API_CREATE);
        $this->verifyUrl     = (string)($cfg['api_verify'] ?? self::API_VERIFY);
        $this->payUrl        = (string)($cfg['pay_url']    ?? self::PAY_URL);
        $this->timeout       = max(5, (int)($cfg['timeout'] ?? 30));
        // Omitted entirely when unset: DPO's own modules never send PTL, and
        // the accepted PTLtype spelling is not established from their source.
        $this->ptl           = (int)($cfg['ptl'] ?? 0);
        $this->ptlType       = (string)($cfg['ptl_type'] ?? 'minutes');
    }

    public function isConfigured(): bool
    {
        return $this->companyToken !== '' && $this->serviceType !== '';
    }

    public function environment(): string { return $this->environment; }

    /** Where the customer is sent once a token exists. */
    public function checkoutUrl(string $transToken): string
    {
        return $this->payUrl . '?ID=' . rawurlencode($transToken);
    }

    // ── createToken ─────────────────────────────────────────────────────

    /**
     * @param array $t reference, amount, currency, description, and optional
     *                 customer first/last/email/phone/dial_code/country,
     *                 redirect_url, back_url
     * @return array{ok:bool, code:string, message:string, trans_token:string,
     *                trans_ref:string, http:int, raw:string}
     */
    public function createToken(array $t): array
    {
        if (!$this->isConfigured()) {
            return self::fail('CONFIG', 'DPO is not configured — company token or service type missing');
        }

        $x = static fn($v): string => htmlspecialchars((string)$v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        // DPO's own class strips non-digits from the phone. We do it here
        // rather than rely on theirs.
        $phone = preg_replace('/\D/', '', (string)($t['customer_phone'] ?? '')) ?? '';

        $body  = '<?xml version="1.0" encoding="utf-8"?>' . "\n<API3G>\n"
               . '<CompanyToken>' . $x($this->companyToken) . "</CompanyToken>\n"
               . "<Request>createToken</Request>\n<Transaction>\n"
               . '<PaymentAmount>' . number_format((float)$t['amount'], 2, '.', '') . "</PaymentAmount>\n"
               . '<PaymentCurrency>' . $x($t['currency']) . "</PaymentCurrency>\n"
               . '<CompanyRef>' . $x($t['reference']) . "</CompanyRef>\n"
               . '<customerFirstName>' . $x($t['customer_first'] ?? '') . "</customerFirstName>\n"
               . '<customerLastName>' . $x($t['customer_last'] ?? '') . "</customerLastName>\n"
               . '<customerEmail>' . $x($t['customer_email'] ?? '') . "</customerEmail>\n"
               . '<customerPhone>' . $x($phone) . "</customerPhone>\n"
               . '<customerDialCode>' . $x($t['customer_dial_code'] ?? '') . "</customerDialCode>\n"
               . '<customerCountry>' . $x($t['customer_country'] ?? '') . "</customerCountry>\n"
               . '<customerCity>' . $x($t['customer_city'] ?? '') . "</customerCity>\n"
               . '<customerAddress>' . $x($t['customer_address'] ?? '') . "</customerAddress>\n"
               . '<customerZip>' . $x($t['customer_zip'] ?? '') . "</customerZip>\n"
               . '<RedirectURL>' . $x($t['redirect_url'] ?? '') . "</RedirectURL>\n"
               . '<BackURL>' . $x($t['back_url'] ?? '') . "</BackURL>\n"
               . '<CompanyAccRef>' . $x($this->companyAccRef) . "</CompanyAccRef>\n";

        if ($this->ptl > 0) {
            $body .= '<PTL>' . $this->ptl . "</PTL>\n"
                   . '<PTLtype>' . $x($this->ptlType) . "</PTLtype>\n";
        }

        $body .= "</Transaction>\n<Services>\n<Service>\n"
               . '<ServiceType>' . $x($this->serviceType) . "</ServiceType>\n"
               . '<ServiceDescription>' . $x($t['description'] ?? 'Service') . "</ServiceDescription>\n"
               . '<ServiceDate>' . date('Y/m/d H:i') . "</ServiceDate>\n"
               . "</Service>\n</Services>\n</API3G>";

        $r   = $this->post($this->createUrl, $body);
        $xml = self::parse($r['body']);

        if ($xml === null) {
            return self::fail('BADXML',
                'DPO did not return a usable answer' . ($r['error'] !== '' ? ': ' . $r['error'] : ''),
                $r['code'], $r['body']);
        }

        $code = self::el($xml, 'Result');
        $out  = [
            'ok'          => $code === '000',
            'code'        => $code,
            'message'     => self::el($xml, 'ResultExplanation') ?: (self::CREATE_CODES[$code] ?? 'Unknown result'),
            'trans_token' => self::el($xml, 'TransToken'),
            'trans_ref'   => self::el($xml, 'TransRef'),
            'http'        => $r['code'],
            'raw'         => self::redact($r['body']),
        ];
        // A 000 with no token is not a success, whatever the code says.
        if ($out['ok'] && $out['trans_token'] === '') {
            $out['ok'] = false;
            $out['code'] = 'NOTOKEN';
            $out['message'] = 'DPO reported success but returned no transaction token';
        }
        return $out;
    }

    // ── verifyToken ─────────────────────────────────────────────────────

    /**
     * @return array{ok:bool, code:string, message:string, paid:bool,
     *                reference:string, amount:?float, currency:?string,
     *                approval:string, method:string, reachable:bool,
     *                http:int, raw:string}
     *
     * `ok` means we got an answer we understood — NOT that money arrived.
     * `paid` alone means Result 000. `reachable` false means the caller must
     * change nothing and try again later: an unreachable gateway is our
     * problem, never the customer's failed payment.
     */
    public function verifyToken(string $transToken): array
    {
        if (!$this->isConfigured()) {
            return self::verifyFail('CONFIG', 'DPO is not configured', true);
        }
        if (trim($transToken) === '') {
            return self::verifyFail('NOTOKEN', 'No transaction token to verify', true);
        }

        $x    = static fn($v): string => htmlspecialchars((string)$v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $body = '<?xml version="1.0" encoding="utf-8"?>' . "\n<API3G>\n"
              . '<CompanyToken>' . $x($this->companyToken) . "</CompanyToken>\n"
              . "<Request>verifyToken</Request>\n"
              . '<TransactionToken>' . $x($transToken) . "</TransactionToken>\n"
              . '</API3G>';

        $r = $this->post($this->verifyUrl, $body);

        if ($r['body'] === '' && $r['error'] !== '') {
            // Transport failure. Say so plainly; do not invent a payment state.
            return self::verifyFail('UNREACHABLE', 'Could not reach DPO: ' . $r['error'], false);
        }

        $xml = self::parse($r['body']);
        if ($xml === null) {
            return self::verifyFail('BADXML', 'DPO did not return a usable answer', false, $r['code'], $r['body']);
        }

        $code = self::el($xml, 'Result');
        $amt  = self::el($xml, 'TransactionAmount');
        $cur  = self::el($xml, 'TransactionCurrency');

        return [
            'ok'        => isset(self::VERIFY_CODES[$code]),
            'code'      => $code,
            'message'   => self::el($xml, 'ResultExplanation') ?: (self::VERIFY_CODES[$code] ?? 'Unknown result'),
            'paid'      => $code === '000',
            'reference' => self::el($xml, 'CompanyRef'),
            // null, not 0.0 — "DPO did not tell us" must never read as "zero".
            'amount'    => $amt === '' ? null : (float)$amt,
            'currency'  => $cur === '' ? null : $cur,
            'approval'  => self::el($xml, 'TransactionApproval'),
            'method'    => self::el($xml, 'CustomerCreditType'),
            'reachable' => true,
            'http'      => $r['code'],
            'raw'       => self::redact($r['body']),
        ];
    }

    public function describeCreate(string $code): string { return self::CREATE_CODES[$code] ?? ''; }
    public function describeVerify(string $code): string { return self::VERIFY_CODES[$code] ?? ''; }

    // ── plumbing ────────────────────────────────────────────────────────

    /** @return array{code:int, body:string, error:string} */
    private function post(string $url, string $body): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => ['Content-Type: text/xml; charset=utf-8', 'cache-control: no-cache'],
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS      => 0,
        ]);
        $out  = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = (string)curl_error($ch);
        curl_close($ch);
        return ['code' => $code, 'body' => $out === false ? '' : (string)$out, 'error' => $err];
    }

    /**
     * Parse a DPO answer, or return null.
     *
     * "Starts with <" is not enough. An HTML error page from a proxy —
     * <html><body>502 Bad Gateway</body></html> — is perfectly well-formed
     * XML, so a lenient parser accepts it and then reads an empty Result,
     * which reads downstream as an unknown DPO code rather than as "that was
     * not DPO". A document with no Result element is not an answer.
     */
    private static function parse(string $body): ?SimpleXMLElement
    {
        $body = trim($body);
        if ($body === '' || $body[0] !== '<') return null;
        $prev = libxml_use_internal_errors(true);
        try {
            $xml = new SimpleXMLElement($body, LIBXML_NONET | LIBXML_NOCDATA);
        } catch (\Throwable $e) {
            $xml = null;
        }
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        if (!$xml) return null;
        $r = $xml->{'Result'};
        return ($r !== null && count($r) > 0 && trim((string)$r) !== '') ? $xml : null;
    }

    private static function el(SimpleXMLElement $xml, string $name): string
    {
        $n = $xml->{$name};
        return ($n === null || count($n) === 0) ? '' : trim((string)$n);
    }

    /** The company token must never reach a log, an event row or a screen. */
    private static function redact(string $body): string
    {
        $body = (string)preg_replace('#<CompanyToken>.*?</CompanyToken>#is',
                                     '<CompanyToken>[redacted]</CompanyToken>', $body);
        return strlen($body) > 2000 ? substr($body, 0, 2000) . '…[truncated]' : $body;
    }

    private static function fail(string $code, string $msg, int $http = 0, string $raw = ''): array
    {
        return ['ok' => false, 'code' => $code, 'message' => $msg, 'trans_token' => '',
                'trans_ref' => '', 'http' => $http, 'raw' => self::redact($raw)];
    }

    private static function verifyFail(string $code, string $msg, bool $reachable,
                                       int $http = 0, string $raw = ''): array
    {
        return ['ok' => false, 'code' => $code, 'message' => $msg, 'paid' => false,
                'reference' => '', 'amount' => null, 'currency' => null, 'approval' => '',
                'method' => '', 'reachable' => $reachable, 'http' => $http,
                'raw' => self::redact($raw)];
    }
}
