<?php
declare(strict_types=1);

require_once __DIR__ . '/CustomerDataTools.php';

/**
 * UcrmCustomerDataGateway — raw records from uCRM, with no authorization.
 *
 * This is deliberately the dumb half. Every method takes whatever it is given
 * and returns what uCRM holds; invoiceByNumber() and kitBySerial() will hand
 * back another customer's record without complaint. The authorization lives
 * one layer up, in CustomerDataTools, and nowhere else.
 *
 * That split is not tidiness. If this class also filtered, a broken check in
 * CustomerDataTools would still look safe under test, and the day someone
 * added a second gateway the guarantee would quietly depend on remembering to
 * filter there too. One boundary, in one place, is the whole design.
 *
 * Nothing here is reached except through CustomerDataTools::call().
 */
final class UcrmCustomerDataGateway implements CustomerDataGateway
{
    /** @var object anything exposing getCrm(), getClientServices(), getLastPayment() */
    private $svc;

    public function __construct($service)
    {
        $this->svc = $service;
    }

    private function crm()
    {
        return method_exists($this->svc, 'getCrm') ? $this->svc->getCrm() : null;
    }

    public function client(int $clientId): ?array
    {
        $crm = $this->crm();
        if (!$crm || $clientId <= 0) return null;
        try {
            $c = $crm->get('clients/' . $clientId);
            if (!is_array($c) || $c === []) return null;
            return [
                'status'   => !empty($c['isLead']) ? 'Lead'
                            : (($c['isActive'] ?? true) ? 'Active' : 'Suspended'),
                'balance'  => (float)($c['accountBalance'] ?? $c['balance'] ?? 0),
                'currency' => (string)($c['currencyCode'] ?? ''),
            ];
        } catch (\Throwable $e) { return null; }
    }

    public function services(int $clientId): array
    {
        if ($clientId <= 0 || !method_exists($this->svc, 'getClientServices')) return [];
        $out = [];
        try {
            foreach ((array)$this->svc->getClientServices($clientId) as $s) {
                if (!is_array($s)) continue;
                $out[] = [
                    'plan'      => (string)($s['servicePlanName'] ?? $s['name'] ?? ''),
                    'price'     => (float)($s['totalPrice'] ?? $s['price'] ?? 0),
                    'currency'  => (string)($s['currencyCode'] ?? ''),
                    'status'    => ((int)($s['status'] ?? 0) === 1) ? 'active' : 'not active',
                    // The raw uCRM code, ADDITIVE. 'status' above is unchanged
                    // for every existing caller; the customer tools need the
                    // code because "not active" collapses prepared, ended and
                    // suspended into one word, and a suspended customer needs
                    // to hear "suspended" rather than a shrug.
                    'status_code' => (int)($s['status'] ?? -1),
                    'active_to' => substr((string)($s['activeTo'] ?? ''), 0, 10),
                ];
            }
        } catch (\Throwable $e) {}
        return $out;
    }

    public function invoices(int $clientId): array
    {
        $crm = $this->crm();
        if (!$crm || $clientId <= 0) return [];
        $out = [];
        try {
            foreach ((array)$crm->get('invoices?clientId=' . $clientId . '&limit=5') as $i) {
                if (!is_array($i)) continue;
                $out[] = self::invoiceRow($i);
            }
        } catch (\Throwable $e) {}
        return $out;
    }

    /** Unfiltered on purpose — see the class docblock. */
    public function invoiceByNumber(string $number): ?array
    {
        $crm = $this->crm();
        if (!$crm || trim($number) === '') return null;
        try {
            $rows = $crm->get('invoices?number=' . rawurlencode(trim($number)) . '&limit=5');
            foreach ((array)$rows as $i) {
                if (!is_array($i)) continue;
                if ((string)($i['number'] ?? '') !== trim($number)) continue;
                return self::invoiceRow($i);
            }
        } catch (\Throwable $e) {}
        return null;
    }

    private static function invoiceRow(array $i): array
    {
        $total = (float)($i['total'] ?? 0);
        $paid  = (float)($i['amountPaid'] ?? 0);
        return [
            'number'    => (string)($i['number'] ?? ''),
            // The owner, carried so the layer above can check it. This is the
            // field that makes "is this yours" answerable at all.
            'client_id' => (int)($i['clientId'] ?? 0),
            'date'      => substr((string)($i['createdDate'] ?? ''), 0, 10),
            // When it is actually due, which is the thing a customer asks.
            // Additive: no existing caller reads this key.
            'due_date'  => substr((string)($i['dueDate'] ?? ''), 0, 10),
            'total'     => $total,
            'due'       => round($total - $paid, 2),
            'currency'  => (string)($i['currencyCode'] ?? ''),
        ];
    }

    public function payments(int $clientId): array
    {
        if ($clientId <= 0 || !method_exists($this->svc, 'getLastPayment')) return [];
        try {
            $p = $this->svc->getLastPayment($clientId);
            if (!is_array($p) || $p === []) return [];
            return [[
                'amount' => (float)($p['amount'] ?? 0),
                'date'   => substr((string)($p['createdDate'] ?? ''), 0, 10),
                'method' => (string)($p['methodName'] ?? $p['method'] ?? ''),
            ]];
        } catch (\Throwable $e) { return []; }
    }

    public function kits(int $clientId): array
    {
        $rows = $this->assignments();
        if ($rows === null || $clientId <= 0) return [];
        $out = [];
        foreach ($rows as $a) {
            if ((int)($a['crm_client_id'] ?? 0) !== $clientId) continue;
            $out[] = ['kit_serial' => (string)($a['kit_serial'] ?? ''),
                      'client_id'  => (int)($a['crm_client_id'] ?? 0),
                      'assigned_at' => substr((string)($a['assigned_at'] ?? ''), 0, 10)];
        }
        return $out;
    }

    /** Unfiltered on purpose. */
    public function kitBySerial(string $serial): ?array
    {
        $rows = $this->assignments();
        $serial = strtoupper(trim($serial));
        if ($rows === null || $serial === '') return null;
        foreach ($rows as $a) {
            if (strtoupper(trim((string)($a['kit_serial'] ?? ''))) !== $serial) continue;
            return ['kit_serial' => (string)$a['kit_serial'],
                    'client_id'  => (int)($a['crm_client_id'] ?? 0),
                    'assigned_at' => substr((string)($a['assigned_at'] ?? ''), 0, 10)];
        }
        return null;
    }

    /** @return array<int,array<string,mixed>>|null null when unreadable */
    private function assignments(): ?array
    {
        if (!property_exists($this->svc, 'pdo') && !method_exists($this->svc, 'getPdo')) return null;
        try {
            $pdo = method_exists($this->svc, 'getPdo') ? $this->svc->getPdo() : $this->svc->pdo;
            if (!$pdo instanceof \PDO) return null;
            $st = $pdo->query("SELECT crm_client_id, kit_serial, assigned_at
                               FROM equipment_assignments WHERE released_at IS NULL");
            return $st ? (array)$st->fetchAll(\PDO::FETCH_ASSOC) : [];
        } catch (\Throwable $e) { return null; }
    }

    public function tickets(int $clientId): array
    {
        $crm = $this->crm();
        if (!$crm || $clientId <= 0) return [];
        $out = [];
        try {
            foreach ((array)$crm->get('ticketing/tickets?clientId=' . $clientId . '&limit=5') as $t) {
                if (!is_array($t)) continue;
                $out[] = ['reference' => (string)($t['id'] ?? ''),
                          'status'    => (string)($t['status'] ?? ''),
                          'opened'    => substr((string)($t['createdAt'] ?? ''), 0, 10)];
            }
        } catch (\Throwable $e) {}
        return $out;
    }
}

/**
 * UcrmGatewayHost — the object UcrmCustomerDataGateway reads uCRM through.
 *
 * The gateway is duck-typed against WaAutoReplyService: getCrm(),
 * getClientServices(), getLastPayment(), and a pdo for the kit table. That
 * class belongs to the hardened WhatsApp path, which is not the path running
 * in production, and constructing the whole of it to reach three accessors
 * would drag its config surface along with it.
 *
 * So this is those three accessors and nothing else, over a CrmApiClient the
 * caller already holds.
 *
 * The two query methods are deliberately IDENTICAL to WaAutoReplyService's —
 * same endpoints, same parameters, same fallbacks. A gateway that read
 * different rows depending on which host it was given would make every
 * comparison built on it meaningless, because a difference would no longer
 * tell you whether the readers disagree or merely the plumbing.
 */
final class UcrmGatewayHost
{
    /** @var \CrmApiClient|null */
    private $crm;
    /** @var \PDO|null */
    private $pdo;

    public function __construct($crm, $pdo = null)
    {
        $this->crm = $crm;
        $this->pdo = $pdo instanceof \PDO ? $pdo : null;
    }

    public function getCrm()
    {
        return $this->crm;
    }

    /** @return array<int,mixed> */
    public function getClientServices(int $clientId): array
    {
        if (!$this->crm || $clientId <= 0) return [];
        try {
            return (array)($this->crm->get("clients/{$clientId}/services") ?? []);
        } catch (\Throwable $e) { return []; }
    }

    public function getLastPayment(int $clientId): ?array
    {
        if (!$this->crm || $clientId <= 0) return null;
        try {
            $payments = (array)($this->crm->get("payments?clientId={$clientId}&limit=1") ?? []);
            $p = $payments[0] ?? null;
            return is_array($p) ? $p : null;
        } catch (\Throwable $e) { return null; }
    }

    /** Null is honest: the gateway answers "unreadable", not "no kits". */
    public function getPdo()
    {
        return $this->pdo;
    }
}
