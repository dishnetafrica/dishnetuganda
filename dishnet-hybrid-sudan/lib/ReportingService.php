<?php
declare(strict_types=1);

require_once __DIR__ . '/PurchaseService.php';
require_once __DIR__ . '/CustomerAccountService.php';

/**
 * ReportingService — the one place a management figure is worked out.
 *
 * Before this, the CEO dashboard, the accounts dashboard and the daily report
 * each computed their own version of what the business had sold, and nothing
 * made them agree. Three screens, three "total sales", and no way to tell
 * which was right.
 *
 * ── EVERY FIGURE CARRIES ITS BASIS ──────────────────────────────────────
 *
 * The failure running through this entire system is a zero that means "I
 * could not ask" wearing the clothes of a zero that means "there is nothing".
 * Tables that a migration never created. Cache keys stripped on read. Two
 * plugins that were not installed. Starlink accounts with no cookie, whose
 * invoices came back as "0 new invoices" rather than "nobody is logged in".
 * Every one of them rendered as a confident number on a screen.
 *
 * So nothing here returns a bare number. A figure is
 *
 *     ['value' => 0.0, 'currency' => 'UGX', 'basis' => '0 invoices',
 *      'complete' => false, 'caveat' => 'why you cannot trust this yet']
 *
 * and a caller that wants to print it has to walk past the reason it might
 * be wrong. `complete` false does not mean broken — a business with no sales
 * yet has an honest zero — it means the figure rests on something a reader
 * should know about.
 *
 * ── ONE CURRENCY PER FIGURE ─────────────────────────────────────────────
 *
 * Never summed across currencies. A USD invoice added to a UGX one is wrong
 * by a factor of about 3,700 and looks entirely reasonable on a dashboard.
 * Totals are per currency, and a figure's currency travels with it.
 *
 * ── NOTHING IS RECOMPUTED THAT UCRM OWNS ────────────────────────────────
 *
 * Sales, receivable and payments received come from the invoices uCRM issued
 * and the payments recorded against them. Purchases, payable and inventory
 * come from this plugin's own tables, which are the only record of them.
 * Neither is re-derived from the other.
 */
final class ReportingService
{
    private $store;
    private ?\PDO $pdo;
    private string $dataDir;
    private array $config;
    private PurchaseService $purchases;

    /** CrmApiClient|null — when present, figures can be checked against uCRM */
    private $crm = null;

    public function __construct($store, string $dataDir, ?\PDO $pdo = null, array $config = [], $crm = null)
    {
        $this->store   = $store;
        $this->dataDir = $dataDir;
        $this->pdo     = $pdo ?: (method_exists($store, 'getPdo') ? $store->getPdo() : null);
        $this->config  = $config;
        $this->crm     = $crm;
        $this->purchases = new PurchaseService(
            $this->pdo ?? new \PDO('sqlite::memory:'), $dataDir);
    }

    /**
     * One figure, and everything a reader needs to judge it.
     *
     * @param string $caveat why the value may not mean what it appears to
     */
    public static function figure(float $value, string $currency, string $basis,
                                  bool $complete = true, string $caveat = ''): array
    {
        return [
            'value'    => round($value, 2),
            'currency' => $currency,
            'basis'    => $basis,
            'complete' => $complete,
            'caveat'   => $caveat,
        ];
    }

    // ── The whole report ────────────────────────────────────────────────────

    /**
     * @param array $opts ['from' => 'YYYY-MM-DD', 'to' => 'YYYY-MM-DD']
     * @return array<string,mixed>
     */
    public function summary(array $opts = []): array
    {
        $from = (string)($opts['from'] ?? '');
        $to   = (string)($opts['to']   ?? '');

        $sales = $this->sales($from, $to);
        $purch = $this->purchasesTotal($from, $to);

        return [
            'period'     => ['from' => $from ?: '(all time)', 'to' => $to ?: '(all time)'],
            'sales'      => $sales,
            'receivable' => $this->receivable(),
            'payments'   => $this->paymentsReceived($from, $to),
            'purchases'  => $purch,
            'payable'    => $this->payable(),
            'inventory'  => $this->inventory(),
            'equipment'  => $this->equipmentAtCustomers(),
            'margin'     => $this->grossMargin(),
            'by_product' => $this->salesByProduct($from, $to),
            'warnings'   => $this->warnings(),
        ];
    }

    // ── Sales, from the invoices uCRM issued ────────────────────────────────

    /** @return array<string,array<string,mixed>> currency => figure */
    public function sales(string $from = '', string $to = ''): array
    {
        $byCur = []; $counted = 0; $undated = 0; $noCurrency = 0;
        foreach ($this->invoices() as $inv) {
            // Draft and void invoices are not sales. Counting a draft is how
            // a dashboard reports revenue that nobody has been billed for.
            if (in_array($inv['status'], ['void', 'draft'], true)) continue;
            if (!$this->inPeriod($inv['issued'], $from, $to)) {
                if ($inv['issued'] === '' && ($from !== '' || $to !== '')) $undated++;
                continue;
            }
            $cur = $inv['currency'];
            if ($cur === '') { $noCurrency++; $cur = 'UNKNOWN'; }
            $byCur[$cur] = ($byCur[$cur] ?? 0.0) + $inv['total'];
            $counted++;
        }

        $out = [];
        foreach ($byCur as $cur => $v) {
            $caveat = '';
            if ($cur === 'UNKNOWN') {
                $caveat = 'these invoices carry no currency code — the figure is a sum of unlike things';
            } elseif ($undated > 0) {
                $caveat = $undated . ' invoice(s) have no issue date and fall outside any period';
            }
            $out[$cur] = self::figure($v, $cur, $counted . ' invoice(s)',
                                      $cur !== 'UNKNOWN' && $undated === 0, $caveat);
        }
        if ($out === []) {
            $out['NONE'] = self::figure(0.0, '', 'no invoices in this period', true,
                $this->invoices() === [] ? 'no invoice has been issued on this install at all' : '');
        }
        return $out;
    }

    /** Money customers owe, per currency — accounts receivable. */
    public function receivable(): array
    {
        $byCur = []; $overdue = []; $n = 0;
        foreach ($this->invoices() as $inv) {
            if (in_array($inv['status'], ['void', 'draft'], true)) continue;
            if ($inv['outstanding'] <= 0.005) continue;
            $cur = $inv['currency'] ?: 'UNKNOWN';
            $byCur[$cur] = ($byCur[$cur] ?? 0.0) + $inv['outstanding'];
            if ($inv['status'] === 'overdue') $overdue[$cur] = ($overdue[$cur] ?? 0.0) + $inv['outstanding'];
            $n++;
        }
        $out = [];
        foreach ($byCur as $cur => $v) {
            $out[$cur] = self::figure($v, $cur, $n . ' unpaid invoice(s)', $cur !== 'UNKNOWN',
                isset($overdue[$cur]) ? 'of which ' . number_format($overdue[$cur], 0) . ' is overdue' : '');
        }
        return $out ?: ['NONE' => self::figure(0.0, '', 'nothing outstanding')];
    }

    /** Money actually received, per currency. */
    public function paymentsReceived(string $from = '', string $to = ''): array
    {
        $invById = [];
        foreach ($this->invoices() as $i) $invById[(int)$i['id']] = $i;

        $byCur = []; $n = 0; $orphans = 0;
        foreach ($this->load('ucrm_invoice_payments_cache.json') as $invId => $entry) {
            $inv = $invById[(int)$invId] ?? null;
            foreach ((array)($entry['payments'] ?? []) as $p) {
                $when = substr((string)($p['createdDate'] ?? ''), 0, 10);
                if (!$this->inPeriod($when, $from, $to)) continue;
                if ($inv === null) { $orphans++; continue; }
                $cur = $inv['currency'] ?: 'UNKNOWN';
                $byCur[$cur] = ($byCur[$cur] ?? 0.0) + round((float)($p['amount'] ?? 0), 2);
                $n++;
            }
        }
        $out = [];
        foreach ($byCur as $cur => $v) {
            $out[$cur] = self::figure($v, $cur, $n . ' payment(s)', $orphans === 0,
                $orphans > 0 ? $orphans . ' payment(s) are against invoices not in the cache and are excluded' : '');
        }
        // The cache is per invoice and only holds invoices somebody has opened.
        // Saying so matters: this figure is a floor, not a total.
        if ($out === []) {
            $out['NONE'] = self::figure(0.0, '', 'no payments recorded in this period', false,
                'payments are cached per invoice as they are viewed — an empty result may mean nothing has been opened yet');
        }
        return $out;
    }

    // ── Purchases, from this plugin's own records ───────────────────────────

    public function purchasesTotal(string $from = '', string $to = ''): array
    {
        if (!$this->pdo) return ['NONE' => self::figure(0.0, '', 'no database')];
        $rows = $this->q("SELECT currency, COUNT(*) AS n, SUM(total_cost) AS t, SUM(tax_total) AS x
                          FROM stock_purchases WHERE status != 'cancelled'"
                        . $this->dateClause('purchase_date', $from, $to) . ' GROUP BY currency');
        $out = [];
        foreach ($rows as $r) {
            $cur = strtoupper(trim((string)$r['currency'])) ?: 'UNKNOWN';
            $out[$cur] = self::figure((float)$r['t'], $cur, (int)$r['n'] . ' purchase(s)', true,
                (float)$r['x'] > 0 ? 'including ' . number_format((float)$r['x'], 0) . ' tax' : '');
        }
        return $out ?: ['NONE' => self::figure(0.0, '', 'no purchases recorded', true,
            'nothing has been received into stock on this install')];
    }

    /** What DishNet owes suppliers, per currency — accounts payable. */
    public function payable(): array
    {
        $out = [];
        foreach ($this->purchases->supplierBalances() as $b) {
            $cur = strtoupper(trim((string)$b['currency'])) ?: 'UNKNOWN';
            $out[$cur] = self::figure(
                ($out[$cur]['value'] ?? 0) + (float)$b['outstanding'], $cur,
                (int)$b['bills'] . ' bill(s) across ' . count($this->purchases->supplierBalances()) . ' supplier(s)');
        }
        return $out ?: ['NONE' => self::figure(0.0, '', 'nothing owed to suppliers')];
    }

    // ── What is on the shelf and at customers ───────────────────────────────

    public function inventory(): array
    {
        if (!$this->pdo) return ['value' => self::figure(0.0, '', 'no database')];
        $v = $this->purchases->inventoryValue();
        $caveat = $v['uncosted_units'] > 0
            ? $v['uncosted_units'] . ' unit(s) have no recorded cost, so this is an understatement'
            : '';
        return [
            'value'  => self::figure($v['total'], $this->bookCurrency(),
                          'serialised ' . number_format($v['serial'], 0)
                          . ' + bulk ' . number_format($v['bulk'], 0),
                          $v['uncosted_units'] === 0, $caveat),
            'serial' => $v['serial'],
            'bulk'   => $v['bulk'],
            'uncosted_units' => $v['uncosted_units'],
        ];
    }

    /** Equipment sitting at customers — ours, not on the shelf. */
    public function equipmentAtCustomers(): array
    {
        if (!$this->pdo) return ['count' => 0, 'value' => self::figure(0.0, '', 'no database')];
        $r = $this->q("SELECT COUNT(*) AS n, COALESCE(SUM(purchase_cost),0) AS v,
                              SUM(CASE WHEN purchase_cost IS NULL OR purchase_cost <= 0 THEN 1 ELSE 0 END) AS z
                       FROM stock_units WHERE status = 'installed'")[0] ?? ['n' => 0, 'v' => 0, 'z' => 0];
        $z = (int)$r['z'];
        return [
            'count' => (int)$r['n'],
            'value' => self::figure((float)$r['v'], $this->bookCurrency(),
                        (int)$r['n'] . ' unit(s) installed at customers', $z === 0,
                        $z > 0 ? $z . ' of them have no recorded cost' : ''),
        ];
    }

    /**
     * Gross margin, only where BOTH numbers are real.
     *
     * A margin needs what something sold for and what it cost. Uganda's
     * hardware cost lives on the stock unit; what it sold for lives on a uCRM
     * invoice line. Where either is missing there is no margin to report, and
     * inventing one from a catalogue price would be a made-up number on a
     * management screen — which is the one thing this must never do.
     */
    public function grossMargin(): array
    {
        if (!$this->pdo) return ['available' => false, 'reason' => 'no database'];

        $units = $this->q("SELECT COUNT(*) AS n FROM stock_units WHERE status = 'installed'")[0]['n'] ?? 0;
        if ((int)$units === 0) {
            return ['available' => false,
                    'reason' => 'no equipment has been installed at a customer yet, so nothing has a cost AND a sale against it'];
        }
        $costed = $this->q("SELECT COUNT(*) AS n FROM stock_units
                            WHERE status = 'installed' AND purchase_cost > 0")[0]['n'] ?? 0;
        if ((int)$costed === 0) {
            return ['available' => false,
                    'reason' => 'installed equipment carries no purchase cost — receive deliveries through the purchase flow and the cost follows the unit'];
        }
        // Linking a specific unit to the invoice line that sold it is not
        // recorded anywhere yet. Saying that is better than dividing two
        // unrelated totals and calling the result a margin.
        return ['available' => false,
                'reason' => 'a unit is not yet linked to the invoice line that sold it, so cost and revenue cannot be matched per item'];
    }

    /** @return array<int,array<string,mixed>> what sold, by invoice line label */
    public function salesByProduct(string $from = '', string $to = ''): array
    {
        $byLabel = [];
        foreach ($this->load('ucrm_invoices_cache.json') as $inv) {
            $status = (int)($inv['status'] ?? 0);
            if ($status === 5 || $status === 1) continue;             // void, draft
            $when = substr((string)($inv['createdDate'] ?? ''), 0, 10);
            if (!$this->inPeriod($when, $from, $to)) continue;
            $cur = strtoupper(trim((string)($inv['currencyCode'] ?? ''))) ?: 'UNKNOWN';
            foreach ((array)($inv['items'] ?? []) as $it) {
                $label = trim((string)($it['label'] ?? '')) ?: '(no label)';
                $key   = $label . '|' . $cur;
                if (!isset($byLabel[$key])) {
                    $byLabel[$key] = ['label' => $label, 'currency' => $cur, 'qty' => 0.0, 'total' => 0.0];
                }
                $byLabel[$key]['qty']   += (float)($it['quantity'] ?? 1);
                $byLabel[$key]['total'] += (float)($it['total'] ?? $it['price'] ?? 0);
            }
        }
        usort($byLabel, fn($a, $b) => $b['total'] <=> $a['total']);
        foreach ($byLabel as &$r) $r['total'] = round($r['total'], 2);
        return array_values($byLabel);
    }

    /**
     * What a reader should know before trusting any of the above.
     *
     * @return string[]
     */
    public function warnings(): array
    {
        $w = [];
        if ($this->invoices() === []) {
            // "Nothing to read" is honest but it stops one question short. An
            // empty cache and an empty business produce the same zero, and
            // they are opposite situations: one means nobody has sold
            // anything, the other means the reporting is not reading what was
            // sold. uCRM can settle it in one call, so ask.
            $live = $this->ucrmHasInvoices();
            if ($live === true) {
                $w[] = 'THE CACHE IS EMPTY BUT UCRM HAS INVOICES. Every sales figure above '
                     . 'is zero because nothing has been read, not because nothing was sold. '
                     . 'Populate it: php tools/report.php --refresh';
            } elseif ($live === false) {
                $w[] = 'No invoices are cached, and uCRM confirms it has none either — so '
                     . 'these zeros are real. Nothing has been invoiced yet.';
            } else {
                $w[] = 'No invoices are cached, and uCRM could not be asked whether that is '
                     . 'correct. These zeros may mean nothing was sold, or that nothing has '
                     . 'been read. Run with --refresh to find out.';
            }
        }
        if ($this->load('ucrm_invoice_payments_cache.json') === []) {
            $w[] = 'No payment records are cached. Payments are fetched per invoice as '
                 . 'each is opened, so "payments received" is a floor and not a total.';
        }
        if ($this->pdo) {
            $n = (int)($this->q("SELECT COUNT(*) AS n FROM stock_units")[0]['n'] ?? 0);
            if ($n === 0) {
                $w[] = 'No stock units exist. Inventory value, equipment at customers and '
                     . 'gross margin are all zero for that reason alone.';
            }
            $u = (int)($this->q("SELECT COUNT(*) AS n FROM stock_units
                                 WHERE status IN ('in_stock','returned','reserved')
                                   AND (purchase_cost IS NULL OR purchase_cost <= 0)")[0]['n'] ?? 0);
            if ($u > 0) $w[] = $u . ' unit(s) in stock have no recorded cost — inventory value is an understatement.';
        }
        return $w;
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /**
     * Invoices in CustomerAccountService's shape, for every client.
     *
     * The cache is a PROPERTY, not a function static. A `static $cache`
     * inside a method belongs to the method, not to the object — so the
     * first ReportingService to run would hand its answer to every later
     * one, and a report built for an empty install would go on reporting
     * zero for an install full of invoices. SqliteStore had exactly this
     * bug and it took comparing two terminal windows to see it.
     */
    private ?array $invoiceCache = null;

    private function invoices(): array
    {
        if ($this->invoiceCache !== null) return $this->invoiceCache;
        $cache = null;
        $svc  = new CustomerAccountService($this->store, null, $this->dataDir, $this->pdo, $this->config);
        $out  = [];
        $seen = [];
        foreach ($this->load('ucrm_invoices_cache.json') as $inv) {
            $cid = (int)($inv['clientId'] ?? 0);
            if ($cid === 0 || isset($seen[$cid])) continue;
            $seen[$cid] = true;
            foreach ($svc->invoices($cid) as $i) $out[] = $i;
        }
        return $this->invoiceCache = $out;
    }

    /**
     * Does uCRM itself hold any invoice?
     *
     * @return bool|null null when uCRM cannot be reached — which is its own
     *                   answer, and a different one from "no".
     */
    public function ucrmHasInvoices(): ?bool
    {
        if (!$this->crm || !method_exists($this->crm, 'get')) return null;
        try {
            $r = $this->crm->get('invoices?limit=1');
            if (!is_array($r)) return null;
            return $r !== [];
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Pull every client's invoices from uCRM into the cache.
     *
     * The cache is filled on demand, one client at a time, when somebody
     * opens the portal. On an install where no customer has logged in it is
     * simply empty — and a management report built on it reads zero for a
     * business that has been invoicing all month.
     *
     * @return array{ok:bool, clients:int, invoices:int, errors:string[]}
     */
    public function refreshFromUcrm(): array
    {
        if (!$this->crm) {
            return ['ok' => false, 'clients' => 0, 'invoices' => 0,
                    'errors' => ['uCRM is not configured — nothing to refresh from']];
        }
        require_once __DIR__ . '/ClientInvoiceCacheRefresher.php';
        $r = new ClientInvoiceCacheRefresher($this->store, $this->crm, $this->dataDir);

        $clients = $this->load('ucrm_clients_cache.json');
        if ($clients === []) {
            try {
                $live = $this->crm->get('clients?limit=1000');
                if (is_array($live)) { $clients = $live; $this->store->save('ucrm_clients_cache.json', $live); }
            } catch (\Throwable $e) { /* fall through with what we have */ }
        }

        $errors = []; $n = 0;
        foreach ($clients as $c) {
            $id = (int)($c['id'] ?? 0);
            if ($id <= 0) continue;
            $res = $r->refreshForClient($id, true, 'report-refresh');
            if (empty($res['ok'])) $errors[] = 'client ' . $id . ': ' . (string)($res['error'] ?? 'failed');
            $n++;
        }
        $this->invoiceCache = null;   // whatever was read before this is stale now

        return ['ok' => $errors === [], 'clients' => $n,
                'invoices' => count($this->load('ucrm_invoices_cache.json')), 'errors' => $errors];
    }

    private function bookCurrency(): string
    {
        $c = strtoupper(trim((string)($this->config['book_base_currency']
                                      ?? $this->config['currency'] ?? '')));
        return preg_match('/^[A-Z]{3}$/', $c) ? $c : '';
    }

    private function inPeriod(string $date, string $from, string $to): bool
    {
        if ($from === '' && $to === '') return true;
        if ($date === '') return false;
        if ($from !== '' && $date < $from) return false;
        if ($to   !== '' && $date > $to)   return false;
        return true;
    }

    private function dateClause(string $col, string $from, string $to): string
    {
        $sql = '';
        if ($from !== '') $sql .= " AND {$col} >= " . $this->pdo->quote($from);
        if ($to   !== '') $sql .= " AND {$col} <= " . $this->pdo->quote($to);
        return $sql;
    }

    private function q(string $sql): array
    {
        try { return $this->pdo->query($sql)->fetchAll(\PDO::FETCH_ASSOC) ?: []; }
        catch (\Throwable $e) { return []; }
    }

    private function load(string $file): array
    {
        try { return $this->store->load($file) ?: []; }
        catch (\Throwable $e) { return []; }
    }
}
