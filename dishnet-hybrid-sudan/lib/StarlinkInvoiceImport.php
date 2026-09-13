<?php
declare(strict_types=1);

require_once __DIR__ . '/PurchaseService.php';

/**
 * StarlinkInvoiceImport — read a Starlink invoice PDF and book it as the
 * supplier bill it is.
 *
 * dishnet-starlink-finance ingested 24 of these and captured the header of
 * every one and the money of none: subtotal 0, VAT 0, total 0, no lines,
 * status pending. An invoice recorded at zero is worse than one not recorded
 * at all, because it looks like a fact.
 *
 * ── WHAT THESE DOCUMENTS ACTUALLY LOOK LIKE ─────────────────────────────
 *
 * Page 1 is a summary: description, qty and an ex-VAT amount per row, then
 * Subtotal, optionally Excise Tax (12%), VAT (18%), Total Charges, Payment,
 * Total Due. Shipping appears HERE ONLY.
 *
 * The pages after it are the detail — "Service Lines", "Addon Lines",
 * "Hardware Lines" — each row carrying Unit Price, Total Tax and an
 * inc-tax Amount. This is where a per-unit hardware cost comes from.
 *
 * Two traps, both of which corrupt figures silently rather than loudly:
 *
 *   The date is written two ways. "Friday, 11 September 2026" on one
 *   invoice, "Saturday, September 12, 2026" on the next.
 *
 *   The currency is written two ways. "USh154,134" and "UGX 1,107,408" are
 *   the same shilling with two labels. They are treated as one currency
 *   because they ARE one currency — no conversion happens anywhere here,
 *   and an invoice mixing a genuinely different currency is refused.
 *
 * ── IT REFUSES RATHER THAN GUESSES ──────────────────────────────────────
 *
 * Every invoice is checked against its own arithmetic before it can be
 * booked: the summary rows must sum to the subtotal, VAT must be the stated
 * percentage of it, the total must be subtotal plus VAT, the detail lines
 * plus shipping must reach the same total, and each detail row's own
 * unit × qty + tax must reach its amount. A document that fails any of those
 * has been misread, and a misread bill in the ledger is a wrong number
 * someone will later trust. So it is reported and not booked.
 */
final class StarlinkInvoiceImport
{
    /** Rounding slack, in whole shillings, for a figure we recompute. */
    private const TOLERANCE = 2.0;

    /** Both spellings of the Ugandan shilling as Starlink writes them. */
    private const MONEY = '(?:UGX|USh)\s*([0-9][0-9,]*(?:\.[0-9]{1,2})?)';

    private PurchaseService $purchases;

    public function __construct(PurchaseService $purchases)
    {
        $this->purchases = $purchases;
    }

    // ── Reading ─────────────────────────────────────────────────────────────

    /** A money string as Starlink writes it, or null when it is not money. */
    public static function money(string $s): ?float
    {
        if (preg_match('/^' . self::MONEY . '$/', trim($s), $m) !== 1) return null;
        return (float)str_replace(',', '', $m[1]);
    }

    /**
     * Starlink's two date spellings.
     *
     * Both start with a weekday and a comma; what follows differs. Rather than
     * match two shapes, drop the weekday and let the rest be parsed — but
     * return null on anything unrecognised, because a wrong date on a bill
     * puts it in the wrong period.
     *
     * A four-digit year is required. These lines wrap: "Payment Due Date:
     * Saturday, September 12," can sit on one line with "2026" on the next,
     * and strtotime fills a missing year with the CURRENT one. That reads
     * correctly in the same year and silently books a December invoice twelve
     * months out when it is read in January.
     */
    public static function date(string $s): ?string
    {
        $s = trim(preg_replace('/^[A-Za-z]+,\s*/', '', trim($s)) ?? '');
        $s = trim($s, " ,\t");
        if ($s === '') return null;
        if (preg_match('/\b\d{4}\b/', $s) !== 1) return null;
        $ts = strtotime($s);
        return $ts === false ? null : date('Y-m-d', $ts);
    }

    /**
     * Parse the text of one invoice, as pdftotext -layout produces it.
     *
     * @return array{ok:bool, error?:string, invoice?:array<string,mixed>}
     */
    public static function parse(string $text): array
    {
        if (trim($text) === '') return ['ok' => false, 'error' => 'the document has no text at all'];

        // Reject a scan before it becomes a bill of zero: no money anywhere
        // means pdftotext found no text layer, not that nothing was charged.
        if (preg_match('/' . self::MONEY . '/', $text) !== 1) {
            return ['ok' => false, 'error' => 'no amounts found — this may be a scanned image with no text layer'];
        }

        $pages = explode("\f", $text);
        $head  = $pages[0];

        $num = '';
        if (preg_match('/\b(INV-[A-Z0-9]+(?:-[A-Z0-9]+)+)\b/', $head, $m) === 1) $num = $m[1];
        if ($num === '') return ['ok' => false, 'error' => 'no invoice number found'];

        $acct = '';
        if (preg_match('/Customer Account:\s*(ACC-[A-Z0-9-]+)/i', $head, $m) === 1) $acct = trim($m[1]);

        $date = null;
        if (preg_match('/Invoice Date:\s*(.+)/i', $head, $m) === 1) $date = self::date($m[1]);
        if ($date === null) return ['ok' => false, 'error' => 'no readable invoice date'];

        $cur = strpos($head, 'UGX') !== false ? 'UGX' : (strpos($head, 'USh') !== false ? 'UGX' : '');
        if ($cur === '') return ['ok' => false, 'error' => 'no recognised currency on the invoice'];

        $grab = static function (string $label) use ($head): ?float {
            if (preg_match('/^\s*' . $label . '\s+' . self::MONEY . '\s*$/mi', $head, $m) !== 1) return null;
            return (float)str_replace(',', '', $m[1]);
        };

        $subtotal = $grab('Subtotal');
        $total    = $grab('Total Charges');
        if ($subtotal === null || $total === null) {
            return ['ok' => false, 'error' => 'the summary has no Subtotal or Total Charges'];
        }

        $vat = 0.0; $vatRate = 0.0;
        if (preg_match('/^\s*VAT\s*\(([\d.]+)%\)\s+' . self::MONEY . '\s*$/mi', $head, $m) === 1) {
            $vatRate = (float)$m[1];
            $vat     = (float)str_replace(',', '', $m[2]);
        }
        $excise = 0.0; $exciseRate = 0.0;
        if (preg_match('/^\s*Excise Tax\s*\(([\d.]+)%\)\s+' . self::MONEY . '\s*$/mi', $head, $m) === 1) {
            $exciseRate = (float)$m[1];
            $excise     = (float)str_replace(',', '', $m[2]);
        }

        // Summary rows: description, optional qty, ex-VAT amount. The totals
        // block is matched first and excluded, so a row named "Subtotal" in a
        // future layout cannot be double-counted as goods.
        $skip = '/^(Subtotal|VAT\s*\(|Excise Tax\s*\(|Total Charges|Payment|Total Due|Product Description)/i';
        $summary = []; $shipping = 0.0;
        foreach (explode("\n", $head) as $ln) {
            if (preg_match('/^\s*(.+?)\s{2,}(?:(\d+)\s+)?' . self::MONEY . '\s*$/', $ln, $m) !== 1) continue;
            $desc = trim($m[1]);
            if ($desc === '' || preg_match($skip, $desc) === 1) continue;
            $amt = (float)str_replace(',', '', $m[3]);
            $row = ['description' => $desc,
                    'quantity'    => $m[2] === '' ? 1.0 : (float)$m[2],
                    'amount'      => $amt];
            if (stripos($desc, 'Shipping') !== false) $shipping += $amt;
            $summary[] = $row;
        }

        // Detail pages. The section title names what the rows are.
        $lines = [];
        foreach (array_slice($pages, 1) as $page) {
            $section = '';
            foreach (explode("\n", $page) as $ln) {
                $t = trim($ln);
                if ($t === '') continue;
                if (preg_match('/\b(Service|Addon|Hardware)\s+Lines\b/i', $t, $m) === 1) {
                    $section = strtolower($m[1]);
                    continue;
                }
                if ($section === '') continue;
                // index, description, qty, unit price, total tax, amount
                if (preg_match('/^(\d+)\s+(.*?)\s+(\d+)\s+' . self::MONEY
                             . '\s+' . self::MONEY . '\s+' . self::MONEY . '$/', $t, $m) !== 1) continue;
                $lines[] = [
                    'section'     => $section,
                    'description' => trim($m[2]),
                    'quantity'    => (float)$m[3],
                    'unit_price'  => (float)str_replace(',', '', $m[4]),
                    'tax'         => (float)str_replace(',', '', $m[5]),
                    'amount'      => (float)str_replace(',', '', $m[6]),
                ];
            }
        }

        return ['ok' => true, 'invoice' => [
            'invoice_number' => $num,
            'account'        => $acct,
            'date'           => $date,
            'currency'       => $cur,
            'subtotal'       => $subtotal,
            'vat'            => $vat,
            'vat_rate'       => $vatRate,
            'excise'         => $excise,
            'excise_rate'    => $exciseRate,
            'shipping'       => $shipping,
            'total'          => $total,
            'paid'           => $grab('Payment') ?? 0.0,
            'due'            => $grab('Total Due') ?? 0.0,
            'summary'        => $summary,
            'lines'          => $lines,
        ]];
    }

    // ── Checking ────────────────────────────────────────────────────────────

    /**
     * Every way this invoice can be shown to have been misread.
     *
     * @return array<int,string> empty when the document reconciles with itself
     */
    public static function verify(array $inv): array
    {
        $bad = [];
        $near = static fn(float $a, float $b): bool => abs($a - $b) <= self::TOLERANCE;
        $f    = static fn(float $v): string => number_format($v, 0);

        if ($inv['summary'] === []) $bad[] = 'no summary rows were read';

        $sum = 0.0;
        foreach ($inv['summary'] as $r) $sum += $r['amount'];
        $sum += $inv['excise'];
        if ($inv['summary'] !== [] && !$near($sum, $inv['subtotal'])) {
            $bad[] = 'summary rows total ' . $f($sum) . ' but Subtotal says ' . $f($inv['subtotal']);
        }

        if ($inv['vat_rate'] > 0) {
            $want = round($inv['subtotal'] * $inv['vat_rate'] / 100, 2);
            if (!$near($want, $inv['vat'])) {
                $bad[] = 'VAT at ' . $inv['vat_rate'] . '% of the subtotal is ' . $f($want)
                       . ' but the invoice says ' . $f($inv['vat']);
            }
        }

        if (!$near($inv['subtotal'] + $inv['vat'], $inv['total'])) {
            $bad[] = 'subtotal plus VAT is ' . $f($inv['subtotal'] + $inv['vat'])
                   . ' but Total Charges says ' . $f($inv['total']);
        }

        // The detail pages must reach the same total. Shipping is on the
        // summary only, so it is added back with its VAT.
        if ($inv['lines'] !== []) {
            $det = 0.0;
            foreach ($inv['lines'] as $l) $det += $l['amount'];
            $ship = $inv['shipping'] * (1 + $inv['vat_rate'] / 100);
            if (!$near($det + $ship, $inv['total'])) {
                $bad[] = 'detail lines plus shipping come to ' . $f($det + $ship)
                       . ' but Total Charges says ' . $f($inv['total']);
            }
            foreach ($inv['lines'] as $i => $l) {
                $want = round($l['unit_price'] * $l['quantity'] + $l['tax'], 2);
                if (!$near($want, $l['amount'])) {
                    $bad[] = 'line ' . ($i + 1) . ' (' . $l['description'] . '): '
                           . $f($l['unit_price']) . ' × ' . $l['quantity'] . ' + tax ' . $f($l['tax'])
                           . ' is ' . $f($want) . ' but the line says ' . $f($l['amount']);
                }
            }
        }

        return $bad;
    }

    // ── Booking ─────────────────────────────────────────────────────────────

    /**
     * Book one verified invoice as a purchase.
     *
     * The detail lines are preferred when present: they carry a per-unit cost,
     * which is the figure the business actually needs. Tax is carried as the
     * rate that reproduces the stated tax amount, so that what is stored
     * multiplies back to what the supplier charged rather than to an
     * assumption about which taxes applied to which line.
     *
     * @return array{ok:bool, error?:string, id?:int, duplicate?:bool}
     */
    public function book(array $inv, array $actor, array $opts = []): array
    {
        $bad = self::verify($inv);
        if ($bad !== []) {
            return ['ok' => false, 'error' => 'does not reconcile: ' . implode('; ', $bad)];
        }

        $lines = [];
        foreach ($inv['lines'] as $l) {
            $net  = $l['unit_price'] * $l['quantity'];
            $rate = $net > 0 ? round($l['tax'] / $net * 100, 4) : 0.0;
            $lines[] = [
                'description' => $l['description'],
                'quantity'    => $l['quantity'],
                'unit_cost'   => $l['unit_price'],
                'tax_rate'    => $rate,
                'category_id' => (int)($opts['category_id'] ?? 0),
            ];
        }
        if ($inv['shipping'] > 0) {
            $lines[] = [
                'description' => 'Shipping & Handling',
                'quantity'    => 1,
                'unit_cost'   => $inv['shipping'],
                'tax_rate'    => $inv['vat_rate'],
                'category_id' => 0,
            ];
        }
        if ($lines === []) {
            return ['ok' => false, 'error' => 'no lines to book'];
        }

        return $this->purchases->receive([
            'supplier'       => (string)($opts['supplier'] ?? 'Starlink Global Internet Services Ltd.'),
            'invoice_number' => $inv['invoice_number'],
            'purchase_date'  => $inv['date'],
            'currency'       => $inv['currency'],
            'total_cost'     => $inv['total'],
            'supplier_ref'   => $inv['account'],
            // One invoice, one bill, however many times this is run.
            'idem_key'       => 'starlink-invoice:' . $inv['invoice_number'],
            'status'         => $inv['due'] <= 0.0 ? 'paid' : 'unpaid',
            'notes'          => 'Starlink invoice ' . $inv['invoice_number']
                              . ' — account ' . $inv['account'],
        ], $lines, $actor);
    }
}
