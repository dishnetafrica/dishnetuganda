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
     * Build tools for a BrainContext identity state.
     *
     * The one construction site the brain may use, and the reason it exists is
     * that there are four states and only one of them is a customer. An
     * anonymous website session is a real, replayable identity — it owns its
     * own conversation — and it is NOT a customer: it has no account, and
     * treating it as one would hand a stranger a tool that answers "what do I
     * owe". unknown and ambiguous are refused for the ordinary reason.
     *
     * Identity still comes from CustomerIdentity, never from the context. The
     * state is a gate in front of it, not a substitute for it.
     */
    public static function forIdentityState(string $identityState, array $identity,
                                            CustomerDataGateway $gw): self
    {
        require_once __DIR__ . '/ConversationService.php';
        if ($identityState !== \ConversationService::STATE_IDENTIFIED) {
            // Nothing of the caller's identity is trusted in any other state.
            $identity = [];
        }
        return new self($identity, $gw);
    }

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
            'get_my_latest_invoice' => ['args' => [],
                'about' => 'This customer\'s most recent invoice: number, amount due, due date.'],
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
     * What the BRAIN may call — five, not ten.
     *
     * Kits, tickets, account status and the invoice list are not here because
     * the prompts these tools replace never carried them. get_my_invoice is
     * not here for a stronger reason: it is the only tool in this class whose
     * backend read is not customer-scoped, and excluding it is what makes
     * "another customer's record is never read" true rather than merely
     * "never returned".
     */
    public const BRAIN_TOOLS = [
        'get_my_balance', 'get_my_plan', 'get_my_service_status',
        'get_my_latest_invoice', 'get_my_last_payment',
    ];

    /** The brain's slice of the catalogue, in catalogue order. */
    public static function brainCatalogue(): array
    {
        return array_intersect_key(self::catalogue(), array_flip(self::BRAIN_TOOLS));
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

    /**
     * Every service, not the first one.
     *
     * Returning services[0] silently discards the second, and a customer with
     * two Starlink lines asking what they pay would be told about one of them
     * with no hint that the other exists. Nothing in the schema or the sync
     * code limits a client to one service, so the shape carries a list
     * whether or not today's data happens to have any.
     */
    private function get_my_plan(): ?array
    {
        $rows = [];
        foreach ($this->gw->services($this->customerId) as $s) {
            $rows[] = ['name'     => (string)($s['plan'] ?? ''),
                       'price'    => (float)($s['price'] ?? 0),
                       'currency' => (string)($s['currency'] ?? '')];
        }
        return $rows === [] ? null : ['services' => $rows];
    }

    /**
     * uCRM service status, as uCRM means it.
     *
     *   0 prepared · 1 active · 2 ended · 3 suspended
     *   4 blocked  · 5 obsolete · 8 quoted
     *
     * Confirmed from CustomerAccountService:329 and KitAttributeIntake:54,
     * not assumed. 3 is SUSPENDED, not active — Finance counts 1 and 3 alike
     * because a suspended customer still holds a kit to track, which is a
     * different question from whether their internet is running.
     *
     * The gateway's own 'status' collapses everything but 1 into "not active".
     * That is right for its callers and wrong here: a suspended customer needs
     * to hear "suspended", because the support prompt routes that to billing
     * rather than to a fault.
     */
    private const SERVICE_STATUS = [
        0 => 'prepared', 1 => 'active',   2 => 'ended',  3 => 'suspended',
        4 => 'blocked',  5 => 'obsolete', 8 => 'quoted',
    ];

    private function get_my_service_status(): ?array
    {
        $rows = [];
        foreach ($this->gw->services($this->customerId) as $s) {
            // When the gateway gave us the code, the code decides — and a
            // code outside the confirmed map is 'unknown', never the
            // gateway's collapsed word, because reporting an unseen state as
            // "not active" claims a certainty we do not have.
            $status = array_key_exists('status_code', $s)
                ? (self::SERVICE_STATUS[(int)$s['status_code']] ?? 'unknown')
                : ((string)($s['status'] ?? '') ?: 'unknown');
            $rows[] = ['status' => $status, 'active_to' => (string)($s['active_to'] ?? '')];
        }
        return $rows === [] ? null : ['services' => $rows];
    }

    /**
     * The most recent invoice, with no argument at all.
     *
     * Deliberately not get_my_invoice(number): that one asks the gateway for
     * ANY invoice with a number and checks ownership afterwards, so another
     * customer's record is read before it is refused. This asks only for
     * theirs, so no other customer's record is touched at any point.
     */
    private function get_my_latest_invoice(): ?array
    {
        $rows = $this->gw->invoices($this->customerId);
        if ($rows === []) return null;
        $i = $rows[0];
        return ['number'     => (string)($i['number'] ?? ''),
                'amount_due' => (float)($i['due'] ?? 0),
                'due_date'   => (string)($i['due_date'] ?? $i['date'] ?? '')];
    }

    /**
     * What they owe, and which way round it is.
     *
     * 'state' is derived HERE rather than left to the model. A signed float is
     * a thing a model can get backwards, and getting it backwards tells a
     * customer in credit that they owe money. The thresholds are the ones the
     * legacy prompt already used, not new ones.
     */
    private function get_my_balance(): ?array
    {
        $c = $this->gw->client($this->customerId);
        if ($c === null) return null;
        $amount = (float)($c['balance'] ?? 0);
        return ['amount'   => $amount,
                'currency' => (string)($c['currency'] ?? ''),
                'state'    => $amount > 0.01 ? 'owed' : ($amount < -0.01 ? 'credit' : 'clear')];
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
