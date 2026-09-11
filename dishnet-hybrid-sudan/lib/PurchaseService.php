<?php
declare(strict_types=1);

require_once __DIR__ . '/StockService.php';
require_once __DIR__ . '/FinAudit.php';

/**
 * PurchaseService — what DishNet bought, what it cost, and what is still owed.
 *
 * Receiving stock used to be two unrelated things that happened to run in the
 * same request. A header row went into stock_purchases — supplier, one total,
 * a payment_method — and then the items[] array off the request was used to
 * create stock and thrown away. So the database could say a router existed
 * and could not say what it cost, minutes after somebody had typed the cost
 * in. There was no tax, no line, and no payable: a purchase was 'received'
 * and that was the last the system thought about it.
 *
 * Three things this insists on:
 *
 *   ONE TRANSACTION. The old path committed the header, then created units
 *   one at a time. A duplicate serial on the third of five left a purchase
 *   with two items and no way to finish it; retrying made a second header.
 *   Here the whole delivery lands or none of it does.
 *
 *   COST REACHES THE STOCK. Every unit created carries the unit cost from
 *   its line, and bulk lines move the weighted average on the quantity row.
 *   Inventory value is then a fact rather than an estimate.
 *
 *   THE SUPPLIER'S TOTAL IS NOT OVERWRITTEN. If the lines add up to
 *   something other than what the supplier billed, both numbers are kept and
 *   the variance is reported. Quietly replacing one with the other is how a
 *   short delivery becomes invisible.
 */
final class PurchaseService
{
    /** Matches fiber_supplier_invoices, deliberately — one vocabulary. */
    public const STATUSES = ['received', 'verified', 'approved', 'paid', 'cancelled'];

    private \PDO $db;
    private StockService $stock;
    private string $dataDir;

    public function __construct(\PDO $db, string $dataDir, ?StockService $stock = null)
    {
        $this->db      = $db;
        $this->dataDir = $dataDir;
        $this->stock   = $stock ?? new StockService($db, $dataDir);
    }

    public static function fromStore($store, string $dataDir): self
    {
        return new self($store->getPdo(), $dataDir);
    }

    // ── Receiving ───────────────────────────────────────────────────────────

    /**
     * Record a delivery: the supplier's bill, its lines, and the stock it puts
     * on the shelf — all or nothing.
     *
     * @param array $header supplier, invoice_number, purchase_date, currency,
     *                      payment_method, supplier_ref, due_date, notes,
     *                      total_cost (what the supplier billed), idem_key
     * @param array $lines  [{category_id|description, quantity, unit_cost,
     *                        tax_rate, serials[]}]
     * @param array $actor  staff row: id, name
     *
     * @return array{ok:bool, id?:int, error?:string, duplicate?:bool,
     *               totals?:array, variance?:float, units_created?:int}
     */
    public function receive(array $header, array $lines, array $actor): array
    {
        $supplier = trim((string)($header['supplier'] ?? ''));
        if ($supplier === '') return ['ok' => false, 'error' => 'A supplier name is required.'];
        if ($lines === []) return ['ok' => false, 'error' => 'A purchase needs at least one line.'];

        $idem = trim((string)($header['idem_key'] ?? ''));
        if ($idem !== '') {
            $st = $this->db->prepare("SELECT id FROM stock_purchases WHERE idem_key = ? LIMIT 1");
            $st->execute([$idem]);
            $existing = $st->fetchColumn();
            if ($existing !== false) {
                // A second tap on Receive, not a second delivery.
                return ['ok' => true, 'id' => (int)$existing, 'duplicate' => true,
                        'totals' => $this->totalsFor((int)$existing)];
            }
        }

        $priced = [];
        foreach ($lines as $i => $raw) {
            $p = $this->priceLine(self::normaliseLine((array)$raw), $i);
            if (isset($p['error'])) return ['ok' => false, 'error' => $p['error']];
            $priced[] = $p;
        }

        $subtotal = 0.0; $taxTotal = 0.0;
        foreach ($priced as $p) { $subtotal += $p['net']; $taxTotal += $p['tax_amount']; }
        $computed = round($subtotal + $taxTotal, 2);

        // What the supplier actually billed. Absent, the lines are the bill.
        $billed = array_key_exists('total_cost', $header) && $header['total_cost'] !== ''
            ? round((float)$header['total_cost'], 2) : $computed;
        $variance = round($billed - $computed, 2);

        $now = date('Y-m-d H:i:s');
        $own = !$this->db->inTransaction();
        if ($own) $this->db->beginTransaction();
        try {
            $this->db->prepare("INSERT INTO stock_purchases
                (supplier, invoice_number, purchase_date, total_cost, currency, ssp_rate,
                 payment_method, received_by, received_by_name, status, notes, photo_path,
                 subtotal, tax_total, amount_paid, supplier_ref, due_date, idem_key, created_at)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,0,?,?,?,?)")
                ->execute([
                    $supplier,
                    trim((string)($header['invoice_number'] ?? '')),
                    (string)($header['purchase_date'] ?? date('Y-m-d')),
                    $billed,
                    strtoupper(trim((string)($header['currency'] ?? 'UGX'))) ?: 'UGX',
                    !empty($header['ssp_rate']) ? (float)$header['ssp_rate'] : null,
                    (string)($header['payment_method'] ?? 'credit'),
                    (int)($actor['id'] ?? 0) ?: null,
                    trim((string)($actor['name'] ?? '')),
                    in_array($header['status'] ?? '', self::STATUSES, true) ? $header['status'] : 'received',
                    trim((string)($header['notes'] ?? '')),
                    trim((string)($header['photo_path'] ?? '')),
                    round($subtotal, 2), round($taxTotal, 2),
                    trim((string)($header['supplier_ref'] ?? '')),
                    trim((string)($header['due_date'] ?? '')) ?: null,
                    $idem,
                    $now,
                ]);
            $purchaseId = (int)$this->db->lastInsertId();

            $unitsCreated = 0;
            foreach ($priced as $p) {
                $this->db->prepare("INSERT INTO stock_purchase_items
                    (purchase_id, category_id, description, quantity, unit_cost,
                     tax_rate, tax_amount, line_total, serials, created_at)
                    VALUES (?,?,?,?,?,?,?,?,?,?)")
                    ->execute([
                        $purchaseId, $p['category_id'] ?: null, $p['description'],
                        $p['quantity'], $p['unit_cost'], $p['tax_rate'],
                        $p['tax_amount'], $p['line_total'],
                        json_encode($p['serials'], JSON_UNESCAPED_SLASHES), $now,
                    ]);
                $unitsCreated += $this->applyToStock($purchaseId, $p, $header, $actor);
            }

            if ($own) $this->db->commit();
        } catch (\Throwable $e) {
            if ($own && $this->db->inTransaction()) $this->db->rollBack();
            return ['ok' => false, 'error' => $e->getMessage()];
        }

        FinAudit::record($this->db, 'stock_purchase', $purchaseId, 'create', $actor, null,
                         $this->get($purchaseId) ?? [], 'goods received');

        return [
            'ok' => true, 'id' => $purchaseId, 'duplicate' => false,
            'totals' => ['subtotal' => round($subtotal, 2), 'tax_total' => round($taxTotal, 2),
                         'lines_total' => $computed, 'billed' => $billed],
            'variance' => $variance,
            'units_created' => $unitsCreated,
        ];
    }

    /**
     * Accept the shape the Stock Receive screen has always sent.
     *
     * That screen posts one entry PER SERIAL — {category_id, serial_number,
     * purchase_cost} with no quantity at all — because it was written against
     * createUnit(), which took exactly that. This service was written around
     * lines with a quantity and a list of serials, and moving the endpoint
     * onto it broke the screen: every serialised item failed validation with
     * "quantity must be more than zero", which is a confusing way to say
     * "I changed the contract underneath you".
     *
     * Both shapes are legitimate. One is a delivery note, the other is a
     * person entering serials one at a time. Neither caller should have to
     * know about the other.
     */
    public static function normaliseLine(array $raw): array
    {
        // Already the new shape.
        if (isset($raw['serials']) || array_key_exists('unit_cost', $raw)) return $raw;

        $single = trim((string)($raw['serial_number'] ?? $raw['serial'] ?? ''));
        if ($single !== '') {
            $raw['serials']   = [$single];
            $raw['quantity']  = 1;
            $raw['unit_cost'] = (float)($raw['purchase_cost'] ?? $raw['cost'] ?? 0);
            return $raw;
        }

        // A bulk line from the same screen: a quantity and no cost per unit.
        if (!array_key_exists('unit_cost', $raw)) {
            $raw['unit_cost'] = (float)($raw['purchase_cost'] ?? $raw['cost'] ?? 0);
        }
        return $raw;
    }

    /**
     * Work out one line's money. Kept separate so the arithmetic can be tested
     * without a database and read without a transaction around it.
     *
     * @return array{error?:string}|array<string,mixed>
     */
    private function priceLine(array $raw, int $i): array
    {
        $n = $i + 1;
        $qty  = round((float)($raw['quantity'] ?? 0), 4);
        $cost = round((float)($raw['unit_cost'] ?? 0), 4);
        $rate = round((float)($raw['tax_rate'] ?? 0), 4);

        if ($qty <= 0)  return ['error' => "Line {$n}: quantity must be more than zero."];
        if ($cost < 0)  return ['error' => "Line {$n}: unit cost cannot be negative."];
        if ($rate < 0 || $rate > 100) return ['error' => "Line {$n}: tax rate must be a percentage between 0 and 100."];

        $catId = (int)($raw['category_id'] ?? 0);
        $cat   = $catId ? $this->stock->getCategory($catId) : null;
        if ($catId && !$cat) return ['error' => "Line {$n}: no such stock category ({$catId})."];

        $serials = [];
        foreach ((array)($raw['serials'] ?? []) as $s) {
            $s = trim((string)$s);
            if ($s !== '') $serials[] = $s;
        }
        // A serial-tracked line means one physical thing per serial. If the
        // serials and the quantity disagree, one of the two is wrong and
        // guessing which produces either phantom stock or lost stock.
        if ($cat && $cat['track_mode'] === 'serial' && $serials !== []
            && count($serials) !== (int)$qty) {
            return ['error' => "Line {$n}: {$qty} of " . $cat['title'] . ' but '
                             . count($serials) . ' serial number(s) given.'];
        }

        $net = round($qty * $cost, 2);
        $tax = round($net * $rate / 100, 2);

        return [
            'category_id' => $catId,
            'category'    => $cat,
            'description' => trim((string)($raw['description'] ?? ($cat['title'] ?? ''))),
            'quantity'    => $qty,
            'unit_cost'   => $cost,
            'tax_rate'    => $rate,
            'tax_amount'  => $tax,
            'net'         => $net,
            'line_total'  => round($net + $tax, 2),
            'serials'     => $serials,
        ];
    }

    /**
     * Put a priced line on the shelf, carrying its cost with it.
     *
     * @return int units created (0 for a bulk line, which moves a quantity)
     */
    private function applyToStock(int $purchaseId, array $p, array $header, array $actor): int
    {
        $cat = $p['category'];
        if (!$cat) return 0;   // a service line — consultancy, freight — buys no stock

        $by     = (int)($actor['id'] ?? 0);
        $byName = trim((string)($actor['name'] ?? 'Staff'));
        $ref    = trim((string)($header['invoice_number'] ?? ''));

        if ($cat['track_mode'] === 'serial') {
            $made = 0;
            foreach ($p['serials'] as $serial) {
                $this->stock->createUnit([
                    'category_id'    => $p['category_id'],
                    'serial_number'  => $serial,
                    // The cost from THIS line, not the catalogue's buy_price.
                    // The catalogue holds what the item usually costs; the
                    // line holds what this one actually cost.
                    'purchase_cost'  => $p['unit_cost'],
                    'purchase_ref'   => $ref,
                    'reference_type' => 'purchase',
                    'reference_id'   => (string)$purchaseId,
                    'inbound_note'   => 'Received from ' . trim((string)($header['supplier'] ?? 'supplier')),
                ], $by, $byName);
                $made++;
            }
            return $made;
        }

        $qty = (int)round($p['quantity']);
        if ($qty <= 0) return 0;
        $this->stock->adjustQuantity($p['category_id'], $qty, [
            'reference_type' => 'purchase',
            'reference_id'   => (string)$purchaseId,
            'note'           => 'Received from ' . trim((string)($header['supplier'] ?? 'supplier')),
        ], $by, $byName);
        $this->blendAverageCost($p['category_id'], $qty, $p['unit_cost']);
        return 0;
    }

    /**
     * Move a bulk category's weighted average cost after receiving more of it.
     *
     * Consumables arrive at different prices and nobody is going to track
     * which metre of cable came from which delivery, so the average is the
     * honest answer. Read the quantity BEFORE this receipt: adjustQuantity
     * has already added it, so the old quantity is what is on hand now minus
     * what just arrived.
     */
    public function blendAverageCost(int $categoryId, int $qtyReceived, float $unitCost): void
    {
        if ($qtyReceived <= 0) return;
        $st = $this->db->prepare("SELECT COALESCE(SUM(qty_on_hand),0) AS q,
                                         COALESCE(MAX(avg_cost),0)  AS c
                                  FROM stock_quantities WHERE category_id = ?");
        $st->execute([$categoryId]);
        $row    = $st->fetch(\PDO::FETCH_ASSOC) ?: ['q' => 0, 'c' => 0];
        $nowQty = (float)$row['q'];
        $oldAvg = (float)$row['c'];
        $oldQty = max(0.0, $nowQty - $qtyReceived);

        $total = $oldQty + $qtyReceived;
        if ($total <= 0) return;
        $avg = round((($oldQty * $oldAvg) + ($qtyReceived * $unitCost)) / $total, 4);

        $this->db->prepare("UPDATE stock_quantities SET avg_cost = ?, updated_at = ?
                            WHERE category_id = ?")
            ->execute([$avg, date('Y-m-d H:i:s'), $categoryId]);
    }

    // ── Paying ──────────────────────────────────────────────────────────────

    /**
     * Record money paid against a supplier bill.
     *
     * @return array{ok:bool, error?:string, payment_id?:int, paid?:float,
     *               outstanding?:float, status?:string}
     */
    public function recordPayment(int $purchaseId, array $data, array $actor): array
    {
        $p = $this->get($purchaseId);
        if (!$p) return ['ok' => false, 'error' => 'Purchase not found.'];
        if (($p['status'] ?? '') === 'cancelled') {
            return ['ok' => false, 'error' => 'This purchase is cancelled — it cannot take a payment.'];
        }

        $amount = round((float)($data['amount'] ?? 0), 2);
        if ($amount <= 0) return ['ok' => false, 'error' => 'A payment must be more than zero.'];

        $outstanding = round((float)$p['total_cost'] - (float)$p['amount_paid'], 2);
        if ($amount > $outstanding + 0.005) {
            // Refused rather than accepted and flagged: paying more than the
            // bill is a typed figure with a decimal in the wrong place far
            // more often than it is a real overpayment, and a supplier
            // balance that has gone negative is read as a credit.
            return ['ok' => false, 'error' => sprintf(
                'That is more than is outstanding on this bill (%s left of %s).',
                number_format($outstanding, 2), number_format((float)$p['total_cost'], 2))];
        }

        $now = date('Y-m-d H:i:s');
        $own = !$this->db->inTransaction();
        if ($own) $this->db->beginTransaction();
        try {
            $this->db->prepare("INSERT INTO stock_purchase_payments
                (purchase_id, paid_on, amount, currency, method, reference,
                 cb_ledger_id, paid_by, paid_by_name, note, created_at)
                VALUES (?,?,?,?,?,?,?,?,?,?,?)")
                ->execute([
                    $purchaseId,
                    (string)($data['paid_on'] ?? date('Y-m-d')),
                    $amount,
                    strtoupper(trim((string)($data['currency'] ?? $p['currency'] ?? 'UGX'))),
                    (string)($data['method'] ?? 'cash'),
                    trim((string)($data['reference'] ?? '')),
                    !empty($data['cb_ledger_id']) ? (int)$data['cb_ledger_id'] : null,
                    (int)($actor['id'] ?? 0) ?: null,
                    trim((string)($actor['name'] ?? '')),
                    trim((string)($data['note'] ?? '')),
                    $now,
                ]);
            $paymentId = (int)$this->db->lastInsertId();

            $paid = round((float)$p['amount_paid'] + $amount, 2);
            // Settled to the cent closes the bill. A purchase left one shilling
            // short of 'paid' sits on the payables report for ever.
            $status = ($paid + 0.005 >= (float)$p['total_cost']) ? 'paid' : $p['status'];
            $this->db->prepare("UPDATE stock_purchases SET amount_paid = ?, status = ? WHERE id = ?")
                ->execute([$paid, $status, $purchaseId]);

            if ($own) $this->db->commit();
        } catch (\Throwable $e) {
            if ($own && $this->db->inTransaction()) $this->db->rollBack();
            return ['ok' => false, 'error' => $e->getMessage()];
        }

        FinAudit::record($this->db, 'stock_purchase', $purchaseId, 'update', $actor,
                         $p, $this->get($purchaseId) ?? [],
                         'supplier payment ' . number_format($amount, 2));

        return ['ok' => true, 'payment_id' => $paymentId, 'paid' => $paid,
                'outstanding' => round((float)$p['total_cost'] - $paid, 2),
                'status' => $status];
    }

    // ── Reading ─────────────────────────────────────────────────────────────

    /** One purchase header, or null. */
    public function get(int $id): ?array
    {
        $st = $this->db->prepare("SELECT * FROM stock_purchases WHERE id = ?");
        $st->execute([$id]);
        $row = $st->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** A purchase with its lines, its payments and what is left to pay. */
    public function detail(int $id): ?array
    {
        $p = $this->get($id);
        if (!$p) return null;

        $li = $this->db->prepare("SELECT * FROM stock_purchase_items WHERE purchase_id = ? ORDER BY id");
        $li->execute([$id]);
        $items = $li->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        foreach ($items as &$it) $it['serials'] = json_decode((string)$it['serials'], true) ?: [];
        unset($it);

        $pa = $this->db->prepare("SELECT * FROM stock_purchase_payments WHERE purchase_id = ? ORDER BY paid_on, id");
        $pa->execute([$id]);

        $p['items']       = $items;
        $p['payments']    = $pa->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        $p['outstanding'] = round((float)$p['total_cost'] - (float)$p['amount_paid'], 2);
        $p['audit']       = FinAudit::history($this->db, 'stock_purchase', $id);
        return $p;
    }

    /** @return array{subtotal:float,tax_total:float,total:float,paid:float,outstanding:float} */
    public function totalsFor(int $id): array
    {
        $p = $this->get($id) ?: [];
        $total = round((float)($p['total_cost'] ?? 0), 2);
        $paid  = round((float)($p['amount_paid'] ?? 0), 2);
        return [
            'subtotal'    => round((float)($p['subtotal'] ?? 0), 2),
            'tax_total'   => round((float)($p['tax_total'] ?? 0), 2),
            'total'       => $total,
            'paid'        => $paid,
            'outstanding' => round($total - $paid, 2),
        ];
    }

    /**
     * What DishNet owes each supplier — accounts payable.
     *
     * Cancelled bills are excluded; they are not debts. Grouped per currency
     * because one supplier billing in USD and another in UGX cannot be added
     * together, and a single "total payable" that does so is a wrong number
     * dressed as a right one.
     *
     * @return array<int,array<string,mixed>>
     */
    public function supplierBalances(): array
    {
        $sql = "SELECT supplier, currency,
                       COUNT(*)                               AS bills,
                       ROUND(SUM(total_cost), 2)              AS billed,
                       ROUND(SUM(amount_paid), 2)             AS paid,
                       ROUND(SUM(total_cost - amount_paid), 2) AS outstanding
                FROM stock_purchases
                WHERE status != 'cancelled'
                GROUP BY supplier, currency
                HAVING outstanding > 0.005
                ORDER BY outstanding DESC";
        return $this->db->query($sql)->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Total inventory value: serialised units at what each cost, plus bulk
     * quantities at their weighted average.
     *
     * @return array{serial:float, bulk:float, total:float, uncosted_units:int}
     */
    public function inventoryValue(): array
    {
        $serial = (float)$this->db->query(
            "SELECT COALESCE(SUM(purchase_cost),0) FROM stock_units
             WHERE status IN ('in_stock','returned','reserved')")->fetchColumn();
        $bulk = (float)$this->db->query(
            "SELECT COALESCE(SUM(qty_on_hand * avg_cost),0) FROM stock_quantities")->fetchColumn();
        // Named, not hidden in the total: a unit with no cost makes the
        // valuation an understatement, and the count is how anyone knows.
        $uncosted = (int)$this->db->query(
            "SELECT COUNT(*) FROM stock_units
             WHERE status IN ('in_stock','returned','reserved')
               AND (purchase_cost IS NULL OR purchase_cost <= 0)")->fetchColumn();

        return ['serial' => round($serial, 2), 'bulk' => round($bulk, 2),
                'total' => round($serial + $bulk, 2), 'uncosted_units' => $uncosted];
    }
}
