<?php
declare(strict_types=1);
/**
 * InvoiceTotals — the totals block of a uCRM invoice, read as uCRM states it.
 *
 * 5.18.42 (docs/38 §7.5). The portal and the app API printed one "Tax" line from a
 * field named `totalTaxes`, which a uCRM invoice does not have: the field is
 * `totalTaxAmount`, and the per-tax split is `taxes[] = [{name, totalValue}]`
 * (probe-confirmed shape, tests/test_efris_mapper.php). So no tax was ever shown,
 * and a VAT line and a UCC levy line could not have been told apart. Everything
 * here is READ from the invoice — no rate is applied, no amount derived — so the
 * screen can never disagree with the invoice document uCRM issued.
 */
final class InvoiceTotals
{
    /** The amount before tax, as uCRM states it (0 when the invoice does not carry one). */
    public static function subtotal(array $inv): float
    {
        return round((float)($inv['subtotal'] ?? 0), 2);
    }

    /**
     * One line per tax or levy, named exactly as uCRM names it ("VAT 18%", "UCC levy 2%").
     * An invoice with a tax total but no split still yields one line, so a tax is
     * never hidden. @return list<array{name:string, amount:float}>
     */
    public static function taxLines(array $inv): array
    {
        $out = [];
        foreach ((is_array($inv['taxes'] ?? null) ? $inv['taxes'] : []) as $t) {
            if (!is_array($t)) continue;
            $name   = trim((string)($t['name'] ?? ''));
            $amount = round((float)($t['totalValue'] ?? $t['amount'] ?? 0), 2);
            if ($name === '' && $amount == 0.0) continue;
            $out[] = ['name' => $name !== '' ? $name : 'Tax', 'amount' => $amount];
        }
        $total = round((float)($inv['totalTaxAmount'] ?? 0), 2);
        if ($out === [] && $total != 0.0) $out[] = ['name' => 'Tax', 'amount' => $total];
        return $out;
    }

    /** uCRM's own tax total; the sum of the lines only when it states none. */
    public static function taxTotal(array $inv): float
    {
        if (isset($inv['totalTaxAmount'])) return round((float)$inv['totalTaxAmount'], 2);
        $sum = 0.0;
        foreach (self::taxLines($inv) as $l) $sum += $l['amount'];
        return round($sum, 2);
    }

    /** The discount as a positive amount (uCRM records it negative; -0.0 on the live shape). */
    public static function discount(array $inv): float
    {
        return round(abs((float)($inv['totalDiscount'] ?? 0)), 2);
    }

    /** Is there anything between the items and the total worth a line of its own? */
    public static function hasBreakdown(array $inv): bool
    {
        $total = (float)($inv['total'] ?? 0);
        $sub   = self::subtotal($inv);
        return self::taxLines($inv) !== [] || self::discount($inv) > 0.0 || ($sub > 0.0 && abs($sub - $total) > 0.004);
    }
}
