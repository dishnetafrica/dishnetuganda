<?php
declare(strict_types=1);

require_once __DIR__ . '/PurchaseService.php';
require_once __DIR__ . '/StockService.php';
require_once __DIR__ . '/SiblingPlugin.php';

/**
 * StarlinkOrderImport — a Starlink order is a supplier bill, so book it as one.
 *
 * The orders endpoint carries everything an accounting entry needs and it was
 * going nowhere: kit and shipping lines with prices, a tax figure that
 * reconciles to exactly 18%, whether it has been paid, and — on a line that
 * has actually been delivered — the KIT serial number.
 *
 * ── AN ORDER IS NOT STOCK ───────────────────────────────────────────────
 *
 * Four kits ordered is a commitment and a payable. It is not four kits on a
 * shelf, and a warehouse that thinks it is will promise a dish to a customer
 * three weeks before it lands. So the purchase is recorded on order, and the
 * stock unit is created only when a line reports delivered AND carries a
 * serial. Until then there is a bill and no stock, which is the truth.
 *
 * ── RUN IT AS OFTEN AS YOU LIKE ─────────────────────────────────────────
 *
 * Idempotent on the order number, and on the serial for the stock half. The
 * same four orders imported ten times are four purchases. An order imported
 * while in transit and again after delivery gains its unit on the second run
 * without duplicating the bill.
 *
 * ── TWO GAPS IN THE SOURCE, NAMED RATHER THAN PAPERED OVER ──────────────
 *
 * dishnet-data-report has a cron_orders.php that fetches this endpoint, but
 * it is not registered in that plugin's manifest, so it never runs on its
 * own. And when it does run it drops two things: orderDetails[].parts[],
 * which is where the serial lives, and every shipping line (productType 5),
 * which is real money — 98,086 a time here — so its totals cannot reconcile
 * with the supplier's own figure.
 *
 * This reads either source. From the raw API response nothing is lost. From
 * dr_orders.json the import still produces a correct bill, and says what the
 * missing pieces cost it.
 */
final class StarlinkOrderImport
{
    /** Starlink's own line type for shipping and handling. */
    private const PRODUCT_TYPE_SHIPPING = 5;

    private PurchaseService $purchases;
    private StockService $stock;
    private \PDO $db;
    private array $config;

    public function __construct(\PDO $db, string $dataDir, array $config = [],
                                ?PurchaseService $purchases = null,
                                ?StockService $stock = null)
    {
        $this->db        = $db;
        $this->config    = $config;
        $this->stock     = $stock ?? new StockService($db, $dataDir);
        $this->purchases = $purchases ?? new PurchaseService($db, $dataDir, $this->stock);
    }

    // ── Reading the two shapes ──────────────────────────────────────────────

    /**
     * Orders out of a raw /orders/customer-account response.
     *
     * This is the complete shape — parts[] included — so a serial survives.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function fromRawApi(array $payload): array
    {
        $results = $payload['content']['results'] ?? $payload['results'] ?? [];
        if (!is_array($results)) return [];

        $out = [];
        foreach ($results as $o) {
            if (!is_array($o) || trim((string)($o['orderNumber'] ?? '')) === '') continue;
            $lines = [];
            foreach ((array)($o['orderDetails'] ?? []) as $d) {
                // The serial lives one level deeper than everything else, on
                // the shipped part. An undelivered line has an empty parts[].
                $serial = '';
                foreach ((array)($d['parts'] ?? []) as $part) {
                    $s = trim((string)($part['serialNumber'] ?? ''));
                    if ($s !== '') { $serial = strtoupper($s); break; }
                }
                $lines[] = [
                    'product_id'   => (string)($d['productId'] ?? ''),
                    'description'  => (string)($d['description'] ?? ''),
                    'quantity'     => (float)($d['quantity'] ?? 1),
                    'price'        => (float)($d['preTaxPrice'] ?? $d['price'] ?? 0),
                    'product_type' => (int)($d['productType'] ?? 0),
                    'serial'       => $serial,
                    'delivered'    => trim((string)($d['deliveredDate'] ?? '')) !== '',
                    'asset_number' => (string)($d['assetNumber'] ?? ''),
                ];
            }
            $out[] = self::order($o, $lines, true);
        }
        return $out;
    }

    /**
     * Orders out of data-report's dr_orders.json.
     *
     * Its records carry no parts[] and no shipping line, so an order read
     * from here is marked incomplete and the caller is told what that costs.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function fromDrOrders(array $drOrders): array
    {
        $out = [];
        // Keyed by account number, each holding a list of orders.
        foreach ($drOrders as $orders) {
            foreach ((array)$orders as $o) {
                if (!is_array($o) || trim((string)($o['orderNumber'] ?? '')) === '') continue;
                $lines = [];
                foreach ((array)($o['orderDetails'] ?? []) as $d) {
                    $lines[] = [
                        'product_id'   => (string)($d['productId'] ?? ''),
                        'description'  => (string)($d['description'] ?? ''),
                        'quantity'     => (float)($d['quantity'] ?? 1),
                        'price'        => (float)($d['price'] ?? 0),
                        'product_type' => (int)($d['productType'] ?? 0),
                        'serial'       => '',           // never stored by that cron
                        'delivered'    => trim((string)($d['deliveredDate'] ?? '')) !== '',
                        'asset_number' => (string)($d['assetNumber'] ?? ''),
                    ];
                }
                $out[] = self::order($o, $lines, false);
            }
        }
        return $out;
    }

    /**
     * Payments out of a /billing/payment/with-invoices response.
     *
     * This is the only place the two sides meet: a payment carries its exact
     * timestamp, its amount, and the order numbers it was applied to. Without
     * it, a bank debit has to be tied to an order by amount and date — and
     * when twelve payments are the same amount inside nine days, that is a
     * guess. With it, the order date stops standing in for the payment date,
     * which is not the same thing: an order placed late on the 8th is paid at
     * 22:18 UTC and reaches a Kampala bank statement on the 9th.
     *
     * @return array<string,array{date:string,amount:float,currency:string,
     *                            ref:string,status:string}> keyed by order number
     */
    public static function paymentsFromRawApi(array $payload): array
    {
        $results = $payload['content']['results'] ?? $payload['results'] ?? [];
        if (!is_array($results)) return [];

        $out = [];
        foreach ($results as $p) {
            if (!is_array($p)) continue;
            // Only money that actually left. A failed or pending authorisation
            // is not a payment, and booking one would show a bill as settled
            // that the supplier is still waiting on.
            if (strcasecmp(trim((string)($p['status'] ?? '')), 'Captured') !== 0) continue;
            $when = trim((string)($p['paymentDate'] ?? ''));
            if ($when === '') continue;
            foreach ((array)($p['appliedOrderNumbers'] ?? []) as $orderNo) {
                $orderNo = trim((string)$orderNo);
                if ($orderNo === '') continue;
                $out[$orderNo] = [
                    'date'     => substr($when, 0, 10),
                    'datetime' => $when,
                    'amount'   => round((float)($p['amount'] ?? 0), 2),
                    'currency' => strtoupper(trim((string)($p['currencyCode'] ?? ''))) ?: 'UGX',
                    'ref'      => trim((string)($p['publicId'] ?? '')),
                    'method'   => trim((string)($p['paymentMethod'] ?? '')),
                ];
            }
        }
        return $out;
    }

    /** One order, in the shape import() takes. */
    private static function order(array $o, array $lines, bool $complete): array
    {
        return [
            'order_number'   => trim((string)($o['orderNumber'] ?? '')),
            'invoice_number' => trim((string)($o['invoiceNumber'] ?? '')),
            'account'        => trim((string)($o['customerAccountId'] ?? '')),
            'ordered_on'     => substr((string)($o['orderDate'] ?? ''), 0, 10),
            'currency'       => strtoupper(trim((string)($o['isoCurrencyCode'] ?? ''))) ?: 'UGX',
            'total'          => round((float)($o['totalAmount'] ?? 0), 2),
            'tax'            => round((float)($o['taxAmount'] ?? 0), 2),
            'paid'           => !empty($o['isOrderPaid']) || !empty($o['invoicePaid']),
            'cancelled'      => trim((string)($o['cancelledDate'] ?? '')) !== '',
            'shipped'        => !empty($o['isShipped']),
            'eta'            => substr((string)($o['estimatedShipBeforeDate'] ?? ''), 0, 10),
            'lines'          => $lines,
            'source_complete'=> $complete,
        ];
    }

    // ── Booking them ────────────────────────────────────────────────────────

    /**
     * @param array $orders from fromRawApi() or fromDrOrders()
     * @param array $actor  staff row: id, name
     * @param array $opts   ['commit' => bool, 'category_id' => int, 'supplier' => string,
     *                        'payments' => array from paymentsFromRawApi()]
     *
     * @return array{ok:bool, purchases:int, skipped:int, units:int,
     *               notes:string[], errors:string[], planned:array}
     */
    public function import(array $orders, array $actor, array $opts = []): array
    {
        $commit   = !empty($opts['commit']);
        // Each step falls through on EMPTY, not just on absent: a caller that
        // passes an unfilled --supplier through would otherwise hand down a
        // blank name and every bill would be refused for want of a supplier.
        $supplier = trim((string)($opts['supplier'] ?? ''));
        if ($supplier === '') $supplier = trim((string)($this->config['starlink_supplier_name'] ?? ''));
        if ($supplier === '') $supplier = 'Starlink';
        $catId    = (int)($opts['category_id'] ?? 0);

        $payments = (array)($opts['payments'] ?? []);
        $made = 0; $skipped = 0; $units = 0; $landed = false;
        $notes = []; $errors = []; $planned = [];

        foreach ($orders as $o) {
            if ($o['cancelled']) { $skipped++; $notes[] = $o['order_number'] . ': cancelled, not booked'; continue; }

            $idem     = 'starlink-order:' . $o['order_number'];
            $existing = $this->purchaseByIdem($idem);

            // Every line, shipping included. Dropping shipping understates what
            // the kit actually cost to get here, and leaves the lines unable to
            // reconcile with the supplier's own total.
            $net = 0.0;
            foreach ($o['lines'] as $l) $net += $l['quantity'] * $l['price'];
            // One tax figure for the whole order, so the rate comes from the
            // supplier's own arithmetic rather than from a configured 18.
            $rate = $net > 0 ? round($o['tax'] / $net * 100, 4) : 0.0;

            $lines = [];
            foreach ($o['lines'] as $l) {
                $isKit = $l['product_type'] !== self::PRODUCT_TYPE_SHIPPING;
                $lines[] = [
                    // Only a kit line belongs to a stock category. Shipping is
                    // a cost on the bill and never a thing on a shelf.
                    'category_id' => $isKit ? $catId : 0,
                    'description' => $l['description'] !== '' ? $l['description'] : $l['product_id'],
                    'quantity'    => $l['quantity'],
                    'unit_cost'   => $l['price'],
                    'tax_rate'    => $rate,
                    // Deliberately no serials here: an ordered kit is not stock.
                    'serials'     => [],
                ];
            }

            $plan = [
                'order'      => $o['order_number'],
                'invoice'    => $o['invoice_number'],
                'ordered_on' => $o['ordered_on'],
                'currency' => $o['currency'],
                'net'      => round($net, 2),
                'tax'      => $o['tax'],
                'total'    => $o['total'],
                'rate'     => $rate,
                'paid'     => $o['paid'],
                'status'   => $existing ? 'already booked' : 'new',
                'serials'  => [],
            ];

            if (!$existing) {
                if ($commit) {
                    $r = $this->purchases->receive([
                        'supplier'       => $supplier,
                        'invoice_number' => $o['invoice_number'] ?: $o['order_number'],
                        'supplier_ref'   => $o['order_number'],
                        'purchase_date'  => $o['ordered_on'] ?: date('Y-m-d'),
                        'currency'       => $o['currency'],
                        'total_cost'     => $o['total'],
                        'payment_method' => $o['paid'] ? 'prepaid' : 'credit',
                        'notes'          => 'Starlink order ' . $o['order_number']
                                          . ($o['account'] !== '' ? ' on ' . $o['account'] : '')
                                          . ($o['eta'] !== '' && !$o['shipped'] ? ' — ETA ' . $o['eta'] : ''),
                        'idem_key'       => $idem,
                    ], $lines, $actor);

                    if (empty($r['ok'])) { $errors[] = $o['order_number'] . ': ' . (string)$r['error']; continue; }
                    $made++;
                    $existing = (int)$r['id'];

                    // Starlink's total and Starlink's own lines differ by half
                    // a shilling on each of these bills — their rounding. The
                    // bill is booked at THEIR figure, because that is what has
                    // to be paid, and the difference is said out loud rather
                    // than quietly dropped on one side or the other.
                    $var = round((float)($r['variance'] ?? 0), 2);
                    if (abs($var) > 0.005) {
                        $notes[] = $o['order_number'] . ': billed ' . number_format($o['total'], 2)
                                 . ' where its own lines come to ' . number_format($o['total'] - $var, 2)
                                 . ' — booked at the billed figure, ' . number_format($var, 2)
                                 . ' difference recorded on it';
                    }

                    // Paid at the till. Recording it keeps the payable honest —
                    // these bills are settled and must not sit on a report of
                    // what DishNet owes.
                    if ($o['paid']) {
                        // The payment feed knows WHEN, to the second. The order
                        // date is only a stand-in for it, and the two differ by
                        // a day often enough to matter: a bank statement is
                        // reconciled on the day the money moved.
                        $pf = $payments[$o['order_number']] ?? null;
                        $p = $this->purchases->recordPayment($existing, [
                            'amount'    => $o['total'],
                            'paid_on'   => $pf['date'] ?? ($o['ordered_on'] ?: date('Y-m-d')),
                            'method'    => 'card',
                            'reference' => $pf['ref'] ?? $o['invoice_number'],
                            'note'      => $pf
                                ? 'paid to Starlink ' . $pf['datetime'] . ' UTC'
                                : 'paid to Starlink at order time',
                        ], $actor);
                        if (empty($p['ok'])) $errors[] = $o['order_number'] . ' payment: ' . (string)$p['error'];
                    }
                } else {
                    $made++;
                }
            } else {
                $skipped++;
            }

            // The stock half. A line is stock when it has been delivered AND
            // names the thing that arrived.
            foreach ($o['lines'] as $l) {
                if (!$l['delivered'] || $l['serial'] === '') continue;
                $plan['serials'][] = $l['serial'];
                if ($this->serialExists($l['serial'])) continue;
                if ($catId <= 0) {
                    $notes[] = $l['serial'] . ' has been delivered but no stock category was given '
                             . '— pass --category to put it on the shelf';
                    continue;
                }
                if (!$commit) { $units++; $landed = true; continue; }
                try {
                    // Straight onto the shelf, against the bill that bought it.
                    // Deliberately NOT a second purchase: the order was the
                    // purchase, and a zero-value bill invented to carry a
                    // serial is a phantom document that every later report
                    // then has to explain away.
                    $this->stock->createUnit([
                        'category_id'    => $catId,
                        'serial_number'  => $l['serial'],
                        // What this one actually cost, off its own order line.
                        'purchase_cost'  => $l['price'],
                        'purchase_ref'   => $o['invoice_number'] ?: $o['order_number'],
                        'reference_type' => 'purchase',
                        'reference_id'   => (string)($existing ?: ''),
                        'inbound_note'   => 'Delivered on Starlink order ' . $o['order_number'],
                    ], (int)($actor['id'] ?? 0), trim((string)($actor['name'] ?? '')) ?: 'order import');
                    $units++; $landed = true;
                } catch (\Throwable $e) {
                    $errors[] = $l['serial'] . ': ' . $e->getMessage();
                }
            }

            if (!$o['source_complete']) {
                $notes[] = $o['order_number'] . ': read from dr_orders.json, which drops shipping '
                         . 'lines and serial numbers — the bill is booked but may under-total, '
                         . 'and no unit can be created from it';
            }
            $planned[] = $plan;
        }

        if ($landed) {
            // Freight-in could arguably be capitalised into the kit. Which of
            // the two is right is a bookkeeping decision with tax consequences
            // and it is not mine to make quietly, so the choice is stated.
            $notes[] = 'A received kit is costed at its own order line. Shipping stays a cost '
                     . 'on the bill and is not rolled into the unit — say so if you want '
                     . 'landed cost instead.';
        }

        return ['ok' => $errors === [], 'purchases' => $made, 'skipped' => $skipped,
                'units' => $units, 'notes' => $notes, 'errors' => $errors, 'planned' => $planned];
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    private function purchaseByIdem(string $idem): ?int
    {
        try {
            $st = $this->db->prepare("SELECT id FROM stock_purchases WHERE idem_key = ? LIMIT 1");
            $st->execute([$idem]);
            $id = $st->fetchColumn();
            return $id === false ? null : (int)$id;
        } catch (\Throwable $e) { return null; }
    }

    private function serialExists(string $serial): bool
    {
        try {
            $st = $this->db->prepare("SELECT 1 FROM stock_units WHERE UPPER(serial_number) = ? LIMIT 1");
            $st->execute([strtoupper($serial)]);
            return $st->fetchColumn() !== false;
        } catch (\Throwable $e) { return false; }
    }
}
