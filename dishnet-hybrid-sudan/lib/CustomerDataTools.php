<?php
declare(strict_types=1);

require_once __DIR__ . '/CustomerIdentity.php';

/**
 * A source of raw records, with NO authorization of its own.
 *
 * Deliberately dumb. invoice() will hand back any invoice by number, and
 * ticket() any ticket by id, for ANY customer — because the authorization
 * belongs in exactly one place and this is not it. Keeping the gateway naive
 * is what lets the tests prove the check above it is real: if the gateway
 * filtered too, a broken check in CustomerDataTools would still look safe.
 */
interface CustomerDataGateway
{
    public function client(int $clientId): ?array;
    /** @return array<int,array<string,mixed>> */
    public function services(int $clientId): array;
    /** @return array<int,array<string,mixed>> */
    public function invoices(int $clientId): array;
    /** Any invoice, by number, unfiltered — the adversarial surface. */
    public function invoiceByNumber(string $number): ?array;
    /** @return array<int,array<string,mixed>> */
    public function payments(int $clientId): array;
    /** @return array<int,array<string,mixed>> */
    public function kits(int $clientId): array;
    /** Any kit, by serial, unfiltered. */
    public function kitBySerial(string $serial): ?array;
    /** @return array<int,array<string,mixed>> */
    public function tickets(int $clientId): array;
}

/**
 * CustomerDataTools — the security boundary.
 *
 * The prompt rules in AiSecurityPolicy are guidance: a model can be argued
 * out of an instruction. This layer is not guidance. A customer-facing tool
 * here has no code path that reaches another customer's record, so the
 * guarantee holds even if the model is completely compromised by injection —
 * the model can ask for anything it likes and the answer is the same.
 *
 * ── THE ID COMES FROM THE SERVER, ALWAYS ────────────────────────────────
 *
 *     WhatsApp number
 *         ↓  CustomerIdentity::resolve()   (exact, unique, or nobody)
 *     authenticated customer id
 *         ↓  constructor — private, never re-assigned
 *     every query, filtered by that id
 *
 * There is no public method on this class that accepts a customer id. Not as
 * a parameter, not as an option, not nested inside an argument array. The
 * dispatcher strips identity-bearing keys at any depth before a tool sees
 * them, so a model that invents `{"customer_id": 8}` or buries it in
 * `{"filter": {"client": {"id": 8}}}` changes nothing about whose data comes
 * back. That is belt and braces: the structural reason it cannot work is
 * that no method reads such a key at all.
 *
 * ── EXISTENCE IS NOT AUTHORIZATION ──────────────────────────────────────
 *
 * Tools that take a reference a customer might actually mention — an invoice
 * number, a kit serial — fetch the record and then verify it belongs to the
 * authenticated customer. A record that exists but belongs to someone else
 * returns NOT FOUND, not "forbidden": telling a stranger that an invoice
 * exists but is not theirs is itself a disclosure.
 *
 * ── MINIMUM NECESSARY ───────────────────────────────────────────────────
 *
 * Each tool returns the few fields its question needs. Asking when a service
 * expires returns an expiry date, not a customer object with an expiry date
 * in it. Nothing here returns a whole record.
 */
final class CustomerDataTools
{
    /** Argument keys that could name a customer, at any depth. */
    private const IDENTITY_KEYS = '/^(client|customer|user|account|owner)_?(id|uuid|ref|number)?$|^(clientId|customerId|userId|accountId)$/i';

    private int $customerId;
    private bool $authenticated;
    private CustomerDataGateway $gw;
    /** @var array<int,array<string,mixed>> */
    private array $audit = [];
    /**
     * Every scalar this layer has actually handed to the model this turn.
     *
     * The output guard needs to recognise another customer's identifiers
     * without holding any other customer's data. It does not need to: what it
     * needs is what THIS customer was permitted, and that is exactly what
     * passed through here. The allowlist is a by-product of authorization,
     * not a second copy of the database.
     *
     * @var array<int,string>
     */
    private array $disclosed = [];

    /**
     * @param array $identity exactly what CustomerIdentity::resolve() returned
     */
    public function __construct(array $identity, CustomerDataGateway $gw)
    {
        $this->gw            = $gw;
        $this->authenticated = (($identity['status'] ?? '') === CustomerIdentity::IDENTIFIED);
        // An unidentified caller gets id 0, which matches no record anywhere.
        // The tools still run; they simply find nothing, which is the correct
        // answer and not a special case anyone has to remember to write.
        $this->customerId    = $this->authenticated ? (int)($identity['client_id'] ?? 0) : 0;
        if ($this->customerId <= 0) $this->authenticated = false;
    }

    public function isAuthenticated(): bool { return $this->authenticated; }

    /**
     * What the model is allowed to ask for.
     *
     * Note that no tool takes a customer id. The two that take a reference
     * take one the customer would say out loud.
     */
    public static function catalogue(): array
    {
        return [
            'get_my_account_status' => ['args' => [],
                'about' => 'Whether this customer\'s account is active, suspended or a lead.'],
            'get_my_plan' => ['args' => [],
                'about' => 'The plan this customer is on and what they pay for it.'],
            'get_my_service_status' => ['args' => [],
                'about' => 'Whether their service is running, and when the current period ends.'],
            'get_my_balance' => ['args' => [],
                'about' => 'What this customer currently owes.'],
            'get_my_last_payment' => ['args' => [],
                'about' => 'Their most recent payment: amount, date, method.'],
            'get_my_invoices' => ['args' => [],
                'about' => 'A short list of this customer\'s recent invoices.'],
            'get_my_invoice' => ['args' => ['number'],
                'about' => 'One of THEIR invoices by number. Another customer\'s is not found.'],
            'get_my_kits' => ['args' => [],
                'about' => 'The Starlink kits assigned to this customer.'],
            'get_my_kit' => ['args' => ['serial'],
                'about' => 'One of THEIR kits by serial. Another customer\'s is not found.'],
            'get_my_support_cases' => ['args' => [],
                'about' => 'This customer\'s open support cases.'],
        ];
    }

    /**
     * Strip anything that looks like it names a customer, however deep.
     *
     * A model cannot choose whose data it gets by supplying an id, because no
     * tool reads one. This removes them anyway, so that an attempt is visible
     * in the audit trail rather than silently ignored.
     *
     * @return array{args:array<string,mixed>, stripped:array<int,string>}
     */
    public static function scrub(array $args, string $path = ''): array
    {
        $out = []; $stripped = [];
        foreach ($args as $k => $v) {
            $here = $path === '' ? (string)$k : $path . '.' . $k;
            if (is_string($k) && preg_match(self::IDENTITY_KEYS, $k) === 1) {
                $stripped[] = $here;
                continue;
            }
            if (is_array($v)) {
                $inner = self::scrub($v, $here);
                $out[$k]  = $inner['args'];
                $stripped = array_merge($stripped, $inner['stripped']);
            } else {
                $out[$k] = $v;
            }
        }
        return ['args' => $out, 'stripped' => $stripped];
    }

    /**
     * The only way a tool runs.
     *
     * @return array{ok:bool, tool:string, data?:array, error?:string, stripped?:array}
     */
    public function call(string $tool, array $args = []): array
    {
        $clean    = self::scrub($args);
        $stripped = $clean['stripped'];
        $args     = $clean['args'];

        $known = array_key_exists($tool, self::catalogue());
        if (!$known) {
            $this->note($tool, 'unknown_tool', $stripped);
            return ['ok' => false, 'tool' => $tool, 'error' => 'no such tool', 'stripped' => $stripped];
        }
        if (!$this->authenticated) {
            $this->note($tool, 'not_authenticated', $stripped);
            return ['ok' => false, 'tool' => $tool,
                    'error' => 'this customer has not been identified, so no account data is available',
                    'stripped' => $stripped];
        }

        $data = $this->{$tool}(...$this->positional($tool, $args));
        if (is_array($data)) $this->remember($data);
        $this->note($tool, $data === null ? 'not_found' : 'ok', $stripped);
        return $data === null
            ? ['ok' => false, 'tool' => $tool, 'error' => 'not found', 'stripped' => $stripped]
            : ['ok' => true,  'tool' => $tool, 'data' => $data, 'stripped' => $stripped];
    }

    /** Only the arguments the catalogue declares, in order, as strings. */
    private function positional(string $tool, array $args): array
    {
        $out = [];
        foreach (self::catalogue()[$tool]['args'] as $name) {
            $out[] = trim((string)($args[$name] ?? ''));
        }
        return $out;
    }

    /** Metadata only — which tool, what happened. Never the data itself. */
    private function note(string $tool, string $outcome, array $stripped): void
    {
        $this->audit[] = ['tool' => $tool, 'outcome' => $outcome,
                          'customer_id' => $this->customerId,
                          'stripped_identity_args' => $stripped];
    }

    /** @return array<int,array<string,mixed>> */
    public function auditTrail(): array { return $this->audit; }

    /** Record every scalar leaf of a tool result, however deeply nested. */
    private function remember(array $data): void
    {
        foreach ($data as $v) {
            if (is_array($v)) { $this->remember($v); continue; }
            if ($v === null || is_bool($v)) continue;
            $s = trim((string)$v);
            if ($s !== '') $this->disclosed[] = $s;
        }
    }

    /**
     * What this customer was actually permitted to be told, this turn.
     *
     * @return array<int,string>
     */
    public function disclosed(): array { return array_values(array_unique($this->disclosed)); }

    // ── The tools. Every one filters on $this->customerId. ──────────────────

    private function get_my_account_status(): ?array
    {
        $c = $this->gw->client($this->customerId);
        if ($c === null) return null;
        return ['status' => (string)($c['status'] ?? 'unknown')];
    }

    private function get_my_plan(): ?array
    {
        $s = $this->gw->services($this->customerId);
        if ($s === []) return null;
        $first = $s[0];
        return ['plan' => (string)($first['plan'] ?? ''),
                'price' => (float)($first['price'] ?? 0),
                'currency' => (string)($first['currency'] ?? '')];
    }

    private function get_my_service_status(): ?array
    {
        $s = $this->gw->services($this->customerId);
        if ($s === []) return null;
        $first = $s[0];
        return ['status' => (string)($first['status'] ?? ''),
                'active_to' => (string)($first['active_to'] ?? '')];
    }

    private function get_my_balance(): ?array
    {
        $c = $this->gw->client($this->customerId);
        if ($c === null) return null;
        return ['balance' => (float)($c['balance'] ?? 0),
                'currency' => (string)($c['currency'] ?? '')];
    }

    private function get_my_last_payment(): ?array
    {
        $p = $this->gw->payments($this->customerId);
        if ($p === []) return null;
        $last = $p[0];
        return ['amount' => (float)($last['amount'] ?? 0),
                'date' => (string)($last['date'] ?? ''),
                'method' => (string)($last['method'] ?? '')];
    }

    private function get_my_invoices(): ?array
    {
        $rows = [];
        foreach (array_slice($this->gw->invoices($this->customerId), 0, 5) as $i) {
            $rows[] = ['number' => (string)($i['number'] ?? ''),
                       'date' => (string)($i['date'] ?? ''),
                       'total' => (float)($i['total'] ?? 0),
                       'due' => (float)($i['due'] ?? 0)];
        }
        return $rows === [] ? null : ['invoices' => $rows];
    }

    /**
     * One invoice by number — theirs, or not found.
     *
     * The gateway will return ANY invoice with this number. Ownership is
     * checked here, against the authenticated id, not against whether the
     * invoice exists.
     */
    private function get_my_invoice(string $number): ?array
    {
        if ($number === '') return null;
        $i = $this->gw->invoiceByNumber($number);
        if ($i === null) return null;
        if ((int)($i['client_id'] ?? 0) !== $this->customerId) return null;
        return ['number' => (string)($i['number'] ?? ''),
                'date' => (string)($i['date'] ?? ''),
                'total' => (float)($i['total'] ?? 0),
                'due' => (float)($i['due'] ?? 0),
                'currency' => (string)($i['currency'] ?? '')];
    }

    private function get_my_kits(): ?array
    {
        $rows = [];
        foreach ($this->gw->kits($this->customerId) as $k) {
            $rows[] = ['kit' => (string)($k['kit_serial'] ?? ''),
                       'assigned_at' => (string)($k['assigned_at'] ?? '')];
        }
        return $rows === [] ? null : ['kits' => $rows];
    }

    /** One kit by serial — theirs, or not found. Same rule as an invoice. */
    private function get_my_kit(string $serial): ?array
    {
        if ($serial === '') return null;
        $k = $this->gw->kitBySerial($serial);
        if ($k === null) return null;
        if ((int)($k['client_id'] ?? 0) !== $this->customerId) return null;
        return ['kit' => (string)($k['kit_serial'] ?? ''),
                'assigned_at' => (string)($k['assigned_at'] ?? '')];
    }

    private function get_my_support_cases(): ?array
    {
        $rows = [];
        foreach (array_slice($this->gw->tickets($this->customerId), 0, 5) as $t) {
            $rows[] = ['reference' => (string)($t['reference'] ?? ''),
                       'status' => (string)($t['status'] ?? ''),
                       'opened' => (string)($t['opened'] ?? '')];
        }
        return $rows === [] ? null : ['cases' => $rows];
    }
}
