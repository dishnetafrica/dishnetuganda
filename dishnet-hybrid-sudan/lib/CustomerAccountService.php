<?php
declare(strict_types=1);

/**
 * CustomerAccountService — one customer, assembled.
 *
 * Everything a customer account needs was already recorded somewhere. The
 * profile is in uCRM, the invoices in one cache, the payments in another
 * keyed by invoice, the equipment in stock_units, the portal mailbox in
 * customer_identities, the onboarding paperwork in kyc_applications. Six
 * places, six shapes, and nothing that put them together — so "does this
 * customer have a complete account" could only be answered by opening six
 * screens and remembering what was on the last one.
 *
 * This assembles them. It adds no table and recomputes no figure that uCRM
 * already owns: billing totals come from the invoices uCRM issued, not from
 * a ledger of our own that could disagree with them.
 *
 * ── AUDIENCE ────────────────────────────────────────────────────────────
 *
 * Two audiences, and the difference is not cosmetic. Staff see what a kit
 * cost us. Customers must never see it: purchase cost is the margin on their
 * own installation, and a portal that leaks it is a negotiation they should
 * not have been handed. forCustomer() strips it and everything like it, and
 * the test suite asserts on the actual returned structure rather than on
 * this paragraph.
 *
 * ── GAPS ────────────────────────────────────────────────────────────────
 *
 * An account that cannot be used is not the same as one that does not
 * exist, and a total of zero says nothing about which. gaps() names what is
 * missing — no email, no service, no equipment, no way to log in — so the
 * answer to "is this customer properly set up" is a list rather than a
 * judgement.
 */
final class CustomerAccountService
{
    private $store;
    private $crm;          // CrmApiClient|null — null means caches only
    private string $dataDir;
    private ?\PDO $pdo;

    public function __construct($store, $crm, string $dataDir, ?\PDO $pdo = null)
    {
        $this->store   = $store;
        $this->crm     = $crm;
        $this->dataDir = $dataDir;
        $this->pdo     = $pdo ?: (method_exists($store, 'getPdo') ? $store->getPdo() : null);
    }

    // ── The whole account ───────────────────────────────────────────────────

    /**
     * @param array $opts ['refresh' => bool]  ask uCRM before reading caches
     * @return array|null null when no such client is known
     */
    public function account(int $clientId, array $opts = []): ?array
    {
        if ($clientId <= 0) return null;

        if (!empty($opts['refresh'])) $this->refresh($clientId);

        $client = $this->client($clientId);
        if ($client === null) return null;

        $invoices = $this->invoices($clientId);
        $billing  = $this->billing($invoices);

        $account = [
            'client_id'   => $clientId,
            'account_ref' => $this->accountRef($client, $clientId),
            'name'        => $this->displayName($client),
            'company'     => trim((string)($client['companyName'] ?? '')),
            'is_company'  => trim((string)($client['companyName'] ?? '')) !== '',
            'is_lead'     => !empty($client['isLead']) || (int)($client['clientType'] ?? 2) === 1,
            'status'      => $this->status($client),
            'contacts'    => $this->contacts($client),
            'email'       => $this->firstContact($client, 'email'),
            'phone'       => $this->firstContact($client, 'phone'),
            'address'     => $this->address($client),
            'registered'  => (string)($client['registrationDate'] ?? $client['createdDate'] ?? ''),
            'services'    => $this->services($clientId),
            'onboarding'  => $this->onboarding($clientId),
            'billing'     => $billing,
            'invoices'    => $invoices,
            'payments'    => $this->payments($clientId, $invoices),
            'equipment'   => $this->equipment($clientId),
            'portal'      => $this->portal($clientId),
        ];
        $account['gaps'] = $this->gaps($account);
        return $account;
    }

    /**
     * The same account as the customer may see it.
     *
     * A denylist would be wrong here: it keeps every field added later by
     * default, so the next column called something like unit_cost ships to
     * customers because nobody remembered to add it. This rebuilds from an
     * explicit list instead — a new internal field is invisible until
     * somebody decides otherwise.
     */
    public function forCustomer(int $clientId, array $opts = []): ?array
    {
        $a = $this->account($clientId, $opts);
        if ($a === null) return null;

        $equipment = [];
        foreach ($a['equipment'] as $e) {
            $equipment[] = [
                'name'          => $e['name'],
                'serial'        => $e['serial'],
                'status'        => $e['status'],
                'since'         => $e['since'],
                'service_label' => $e['service_label'],
            ];
        }

        return [
            'client_id'  => $a['client_id'],
            'account_ref'=> $a['account_ref'],
            'name'       => $a['name'],
            'email'      => $a['email'],
            'phone'      => $a['phone'],
            'address'    => $a['address'],
            'status'     => $a['status'],
            'services'   => array_map(function (array $s): array {
                return ['name' => $s['name'], 'status' => $s['status'],
                        'price' => $s['price'], 'currency' => $s['currency'],
                        'since' => $s['since']];
            }, $a['services']),
            'billing'    => [
                'currency'    => $a['billing']['currency'],
                'outstanding' => $a['billing']['outstanding'],
                'overdue'     => $a['billing']['overdue'],
                'paid_total'  => $a['billing']['paid'],
            ],
            'invoices'   => $a['invoices'],
            'payments'   => $a['payments'],
            'equipment'  => $equipment,
            'since'      => $a['registered'],
        ];
    }

    // ── Pieces ──────────────────────────────────────────────────────────────

    /** The uCRM client record, from cache, or live if the cache misses. */
    public function client(int $clientId): ?array
    {
        foreach ($this->load('ucrm_clients_cache.json') as $row) {
            if ((int)($row['id'] ?? 0) === $clientId) return $row;
        }
        if ($this->crm && method_exists($this->crm, 'get')) {
            try {
                $r = $this->crm->get("clients/{$clientId}");
                if (is_array($r) && !empty($r['id'])) return $r;
            } catch (\Throwable $e) { /* offline — the caches are the answer */ }
        }
        return null;
    }

    /**
     * The identifier a customer quotes on the phone.
     *
     * uCRM's userIdent when there is one — that is what is printed on their
     * invoice — and the client id otherwise, never a number invented here.
     */
    public function accountRef(array $client, int $clientId): string
    {
        $ident = trim((string)($client['userIdent'] ?? ''));
        return $ident !== '' ? $ident : (string)$clientId;
    }

    /** @return array<int,array<string,mixed>> newest first */
    public function invoices(int $clientId): array
    {
        $out = [];
        foreach ($this->load('ucrm_invoices_cache.json') as $inv) {
            if ((int)($inv['clientId'] ?? 0) !== $clientId) continue;
            $total = round((float)($inv['total'] ?? 0), 2);
            $paid  = round((float)($inv['amountPaid'] ?? 0), 2);
            $out[] = [
                'id'          => (int)($inv['id'] ?? 0),
                'number'      => (string)($inv['number'] ?? ''),
                'issued'      => substr((string)($inv['createdDate'] ?? ''), 0, 10),
                'due'         => substr((string)($inv['dueDate'] ?? ''), 0, 10),
                'total'       => $total,
                'paid'        => $paid,
                'outstanding' => round(max(0, $total - $paid), 2),
                // No hardcoded fallback currency. An invoice whose currency we
                // do not know is reported as unknown rather than guessed at —
                // a UGX invoice labelled USD is a thousandfold error.
                'currency'    => strtoupper(trim((string)($inv['currencyCode'] ?? ''))),
                'status'      => $this->invoiceStatus($inv, $total, $paid),
                'description' => (string)($inv['items'][0]['label'] ?? 'DishNet service'),
            ];
        }
        usort($out, function (array $a, array $b): int {
            return strcmp((string)$b['issued'], (string)$a['issued']) ?: ($b['id'] <=> $a['id']);
        });
        return $out;
    }

    /** uCRM status: 1 draft, 2 unpaid, 3 partial, 4 paid, 5 void, 6 overdue */
    private function invoiceStatus(array $inv, float $total, float $paid): string
    {
        $s = (int)($inv['status'] ?? 0);
        if ($s === 5) return 'void';
        if ($s === 1) return 'draft';
        if ($s === 4 || ($total > 0 && $paid + 0.005 >= $total)) return 'paid';
        if ($s === 6) return 'overdue';
        $due = (string)($inv['dueDate'] ?? '');
        if ($due !== '' && strtotime($due) !== false && strtotime($due) < time()) return 'overdue';
        return $paid > 0 ? 'partial' : 'pending';
    }

    /**
     * What the account owes, from the invoices uCRM issued.
     *
     * Per currency, and the currency is carried rather than assumed: adding a
     * USD invoice to a UGX one produces a number that is wrong by a factor of
     * about 3,700 and looks perfectly reasonable on a dashboard.
     */
    public function billing(array $invoices): array
    {
        $cur = [];
        foreach ($invoices as $i) {
            if (in_array($i['status'], ['void', 'draft'], true)) continue;
            $c = $i['currency'] !== '' ? $i['currency'] : 'UNKNOWN';
            if (!isset($cur[$c])) $cur[$c] = ['invoiced' => 0.0, 'paid' => 0.0,
                                              'outstanding' => 0.0, 'overdue' => 0.0, 'count' => 0];
            $cur[$c]['invoiced']    += $i['total'];
            $cur[$c]['paid']        += $i['paid'];
            $cur[$c]['outstanding'] += $i['outstanding'];
            if ($i['status'] === 'overdue') $cur[$c]['overdue'] += $i['outstanding'];
            $cur[$c]['count']++;
        }
        foreach ($cur as $c => $v) {
            foreach (['invoiced', 'paid', 'outstanding', 'overdue'] as $k) {
                $cur[$c][$k] = round($v[$k], 2);
            }
        }
        // The headline figures belong to whichever currency the account is
        // actually billed in — the one with invoices, not a configured default.
        $main = '';
        $most = -1;
        foreach ($cur as $c => $v) if ($v['count'] > $most) { $most = $v['count']; $main = $c; }
        $head = $cur[$main] ?? ['invoiced' => 0.0, 'paid' => 0.0, 'outstanding' => 0.0,
                                'overdue' => 0.0, 'count' => 0];

        return [
            'currency'      => $main,
            'invoiced'      => $head['invoiced'],
            'paid'          => $head['paid'],
            'outstanding'   => $head['outstanding'],
            'overdue'       => $head['overdue'],
            'invoice_count' => $head['count'],
            'by_currency'   => $cur,
        ];
    }

    /**
     * Every payment on the account, newest first.
     *
     * Assembled from the per-invoice payment cache, which is the only place
     * receipts were kept: the portal could show what was paid against one
     * invoice and had no way to answer "what has this customer ever paid".
     *
     * @return array<int,array<string,mixed>>
     */
    public function payments(int $clientId, ?array $invoices = null): array
    {
        $invoices = $invoices ?? $this->invoices($clientId);
        $byId = [];
        foreach ($invoices as $i) $byId[(int)$i['id']] = $i;

        $cache = $this->load('ucrm_invoice_payments_cache.json');
        $out = [];
        foreach ($cache as $key => $entry) {
            $invId = (int)$key;
            if (!isset($byId[$invId])) continue;    // another customer's invoice
            foreach ((array)($entry['payments'] ?? []) as $p) {
                $out[] = [
                    'id'         => (int)($p['id'] ?? 0),
                    'invoice_id' => $invId,
                    'invoice'    => $byId[$invId]['number'],
                    'amount'     => round((float)($p['amount'] ?? 0), 2),
                    'currency'   => $byId[$invId]['currency'],
                    'method'     => (string)($p['method'] ?? ''),
                    'paid_on'    => substr((string)($p['createdDate'] ?? ''), 0, 10),
                    'note'       => (string)($p['note'] ?? ''),
                ];
            }
        }
        usort($out, function (array $a, array $b): int {
            return strcmp((string)$b['paid_on'], (string)$a['paid_on']) ?: ($b['id'] <=> $a['id']);
        });
        return $out;
    }

    /** @return array<int,array<string,mixed>> */
    public function services(int $clientId): array
    {
        $out = [];
        foreach ($this->load('ucrm_services_cache.json') as $s) {
            if ((int)($s['clientId'] ?? 0) !== $clientId) continue;
            // uCRM service status: 0 prepared, 1 active, 2 ended, 3 suspended,
            // 4 prepared-blocked, 5 obsolete, 8 quoted
            $map = [0 => 'prepared', 1 => 'active', 2 => 'ended', 3 => 'suspended',
                    4 => 'blocked', 5 => 'obsolete', 8 => 'quoted'];
            $out[] = [
                'id'       => (int)($s['id'] ?? 0),
                'name'     => (string)($s['name'] ?? ''),
                'status'   => $map[(int)($s['status'] ?? -1)] ?? 'unknown',
                'price'    => round((float)($s['price'] ?? 0), 2),
                'currency' => strtoupper(trim((string)($s['currencyCode'] ?? ''))),
                'since'    => substr((string)($s['activeFrom'] ?? ''), 0, 10),
                'until'    => substr((string)($s['activeTo'] ?? ''), 0, 10),
            ];
        }
        return $out;
    }

    /**
     * How this customer came to be one: the KYC application they were signed
     * up on, and the install job that put a dish on their roof.
     */
    public function onboarding(int $clientId): array
    {
        $out = ['application_id' => null, 'signed_up' => '', 'sold_by' => '',
                'install_job_id' => null, 'installed_on' => '', 'installed_by' => ''];

        foreach ($this->load('kyc_applications.json') as $app) {
            if ((int)($app['crm_client_id'] ?? 0) !== $clientId) continue;
            $out['application_id'] = (int)($app['id'] ?? 0) ?: null;
            $out['signed_up']      = substr((string)($app['created_at'] ?? ''), 0, 10);
            $out['sold_by']        = (string)($app['retailer_name'] ?? $app['created_by_name'] ?? '');
            break;
        }

        // The install is whatever put equipment at this customer — the
        // movement log is the record of that, and it holds the date and the
        // engineer that nothing else does.
        if ($this->pdo) {
            try {
                $st = $this->pdo->prepare(
                    "SELECT reference_id, performed_by_name, created_at
                     FROM stock_movements
                     WHERE movement_type = 'install' AND to_location_type = 'customer'
                       AND to_location_ref = ? ORDER BY id ASC LIMIT 1");
                $st->execute([(string)$clientId]);
                $row = $st->fetch(\PDO::FETCH_ASSOC);
                if ($row) {
                    $out['install_job_id'] = (int)$row['reference_id'] ?: null;
                    $out['installed_on']   = substr((string)$row['created_at'], 0, 10);
                    $out['installed_by']   = (string)$row['performed_by_name'];
                }
            } catch (\Throwable $e) { /* no stock tables on this install */ }
        }
        return $out;
    }

    /**
     * Equipment this customer holds — installed, or reserved for them.
     *
     * purchase_cost is included because staff need it for margin. It is
     * exactly what forCustomer() removes.
     *
     * @return array<int,array<string,mixed>>
     */
    public function equipment(int $clientId): array
    {
        if (!$this->pdo) return [];
        try {
            $st = $this->pdo->prepare(
                "SELECT u.id, u.serial_number, u.secondary_serial, u.status, u.updated_at,
                        u.crm_service_id, u.purchase_cost, u.purchase_ref, u.condition_grade,
                        u.starlink_account, c.title AS category, c.sku, c.service_type
                 FROM stock_units u
                 LEFT JOIN stock_categories c ON c.id = u.category_id
                 WHERE u.crm_client_id = ? AND u.status IN ('installed','reserved')
                 ORDER BY u.id");
            $st->execute([$clientId]);
            $rows = $st->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            return [];
        }

        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'unit_id'       => (int)$r['id'],
                'name'          => (string)($r['category'] ?? 'Equipment'),
                'sku'           => (string)($r['sku'] ?? ''),
                'service_label' => (string)($r['service_type'] ?? ''),
                'serial'        => (string)($r['serial_number'] ?? ''),
                'second_serial' => (string)($r['secondary_serial'] ?? ''),
                'status'        => (string)$r['status'],
                'condition'     => (string)($r['condition_grade'] ?? ''),
                'since'         => substr((string)($r['updated_at'] ?? ''), 0, 10),
                'crm_service_id'=> (int)($r['crm_service_id'] ?? 0) ?: null,
                // Internal. forCustomer() drops these two.
                'purchase_cost' => round((float)($r['purchase_cost'] ?? 0), 2),
                'purchase_ref'  => (string)($r['purchase_ref'] ?? ''),
            ];
        }
        return $out;
    }

    /** The portal mailbox and whether this customer can actually get in. */
    public function portal(int $clientId): array
    {
        $out = ['identity_email' => '', 'identity_status' => '', 'can_login_by' => [],
                'last_login' => ''];

        if ($this->pdo) {
            try {
                $st = $this->pdo->prepare("SELECT email, status FROM customer_identities WHERE client_id = ?");
                $st->execute([$clientId]);
                if ($row = $st->fetch(\PDO::FETCH_ASSOC)) {
                    $out['identity_email']  = (string)$row['email'];
                    $out['identity_status'] = (string)$row['status'];
                }
            } catch (\Throwable $e) { /* table not present */ }
        }

        // A login code reaches a customer by WhatsApp or by email. Which of
        // those works is a property of the contact details on the record, not
        // of anything stored about the portal.
        $client = $this->client($clientId) ?? [];
        if ($this->firstContact($client, 'phone') !== '') $out['can_login_by'][] = 'whatsapp';
        if ($this->firstContact($client, 'email') !== '') $out['can_login_by'][] = 'email';

        return $out;
    }

    /**
     * What is missing before this account can be operated.
     *
     * Plain sentences, because the reader is whoever onboarded the customer
     * and the useful output is the thing they now have to go and do.
     *
     * @return string[]
     */
    public function gaps(array $account): array
    {
        $g = [];
        if ($account['email'] === '') {
            $g[] = 'No email address — no invoice, receipt or login code can be emailed.';
        }
        if ($account['phone'] === '') {
            $g[] = 'No phone number — no WhatsApp reaches them, and five of the '
                 . 'lifecycle emails are sent from the same block and will not fire either.';
        }
        if ($account['services'] === []) {
            $g[] = 'No service on the account — nothing will ever be invoiced.';
        } elseif (!array_filter($account['services'], fn($s) => $s['status'] === 'active')) {
            $g[] = 'A service exists but none is active.';
        }
        if ($account['invoices'] === []) {
            $g[] = 'No invoice has been issued yet.';
        }
        if ($account['equipment'] === []) {
            $g[] = 'No equipment is assigned — if a dish was installed, the unit '
                 . 'was never marked against this customer and still counts as stock.';
        }
        if ($account['portal']['can_login_by'] === []) {
            $g[] = 'No way to log in: a login code needs a phone or an email.';
        }
        if ($account['is_lead']) {
            $g[] = 'Still a lead in uCRM, not a client.';
        } elseif ($account['status'] !== 'active') {
            $g[] = 'The client is marked ' . $account['status'] . ' in uCRM.';
        }
        if ($account['address'] === '') {
            $g[] = 'No service address on file.';
        }
        return $g;
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /** Ask uCRM for this client's current invoices before reading the caches. */
    public function refresh(int $clientId): void
    {
        if (!$this->crm) return;
        try {
            require_once __DIR__ . '/ClientInvoiceCacheRefresher.php';
            $r = new ClientInvoiceCacheRefresher($this->store, $this->crm, $this->dataDir);
            $r->refreshForClient($clientId, true, 'customer-account');
            if (method_exists($r, 'refreshClientRecord')) $r->refreshClientRecord($clientId, 'customer-account');
        } catch (\Throwable $e) { /* stale data beats no data */ }
    }

    private function load(string $file): array
    {
        try { return $this->store->load($file) ?: []; }
        catch (\Throwable $e) { return []; }
    }

    private function displayName(array $c): string
    {
        $n = trim(((string)($c['firstName'] ?? '')) . ' ' . ((string)($c['lastName'] ?? '')));
        if ($n === '') $n = trim((string)($c['companyName'] ?? ''));
        return $n !== '' ? $n : 'Customer';
    }

    private function status(array $c): string
    {
        if (!empty($c['isLead']) || (int)($c['clientType'] ?? 2) === 1) return 'lead';
        if (array_key_exists('isActive', $c) && !$c['isActive']) return 'inactive';
        return 'active';
    }

    /** @return array<int,array<string,string>> */
    private function contacts(array $c): array
    {
        $out = [];
        foreach ((array)($c['contacts'] ?? []) as $ct) {
            $out[] = [
                'name'  => trim((string)($ct['name'] ?? '')),
                'email' => trim((string)($ct['email'] ?? '')),
                'phone' => trim((string)($ct['phone'] ?? '')),
                'types' => implode(',', array_map(
                    fn($t) => (string)($t['name'] ?? ''), (array)($ct['types'] ?? []))),
            ];
        }
        return $out;
    }

    /** First non-empty $field across the contacts, then the client record. */
    private function firstContact(array $c, string $field): string
    {
        foreach ((array)($c['contacts'] ?? []) as $ct) {
            $v = trim((string)($ct[$field] ?? ''));
            if ($v !== '') return $v;
        }
        return trim((string)($c[$field] ?? ''));
    }

    /**
     * The service address, or '' when there is none.
     *
     * Deliberately no fallback. app_me answered 'Juba' for any customer
     * without one, which is a Sudan default reaching Ugandan customers and
     * reads as a fact rather than as a blank.
     */
    private function address(array $c): string
    {
        $parts = array_filter([
            trim((string)($c['street1'] ?? '')),
            trim((string)($c['street2'] ?? '')),
            trim((string)($c['city'] ?? '')),
        ]);
        return implode(', ', $parts);
    }
}
