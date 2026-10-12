<?php
declare(strict_types=1);
/**
 * QuotationPrefill — 5.18.85: what the customer's uCRM quotation holds, for the installation authorisation's request form.
 *
 * docs/61 D4 decided the charges are "typed or confirmed by staff at request time, prefilled when a KYC application or
 * quotation exists". Until 5.18.85 only the KYC application was read, and never for the charges. The quotation is the
 * price agreed with that customer, so its lines fill the form:
 *   - a line whose label names an installation    → the installation charge (several are added up);
 *   - a line whose label names transport/delivery  → the transport charge; a transport line at 0 ("Transportation charges
 *                                                    to and from the site shall be borne by the customer.", as DishNet
 *                                                    Uganda's quotations carry it) is listed WITHOUT a price: the form
 *                                                    asks the sender for the agreed amount instead of filling 0;
 *   - a recurring line (a monthly unit, or a plan) → the service;
 *   - any other one-time line with an amount       → the other agreed charge, its labels as what it is for;
 *   - every other line (the kit, a router, cable)  → the equipment.
 * The equipment's prices are not charges here: the customer pays for the kit through the quotation itself.
 *
 * Only suggestions: the person sending the request sees which quotation filled the form, checks every value and may
 * change any of them, and the customer accepts exactly what is sent. Nothing here writes anything or calls anything.
 *
 * PHP 7.4 compatible.
 */
final class QuotationPrefill
{
    /** The plugin's own reading of uCRM's quote statuses (cron_quote_wa.php): 3 rejected, 4 void — never used here. */
    public const SKIP_STATUSES = [3, 4];

    /**
     * The customer's latest quotation in uCRM's answer: the newest by creation date (then by id), never another client's,
     * never a rejected or void one, never one without lines. null when there is none.
     */
    public static function pick($quotes, int $clientId): ?array
    {
        if (!is_array($quotes) || $clientId <= 0) return null;
        $best = null;
        foreach ($quotes as $q) {
            if (!is_array($q)) continue;
            if ((int)($q['clientId'] ?? 0) !== $clientId) continue;   // the answer may be every client's: never another's
            if (in_array((int)($q['status'] ?? 0), self::SKIP_STATUSES, true)) continue;
            if (!is_array($q['items'] ?? null) || !$q['items']) continue;
            if ($best === null || self::newer($q, $best)) $best = $q;
        }
        return $best;
    }

    /**
     * The form's suggestions from one quotation. Amounts are strings the form shows as they are ("150000"), or null when
     * the quotation says nothing about that charge.
     *
     * @return array{number:string, date:string, service:string, equipment:string, installation:?string, transport:?string,
     *               transport_unpriced:bool, other:?string, other_label:string}
     */
    public static function suggest(array $quote, ?\DateTimeZone $tz = null): array
    {
        $install = null; $transport = null; $transportListed = false; $other = 0.0; $otherLabels = [];
        $service = ''; $equipment = [];
        foreach ((array)($quote['items'] ?? []) as $it) {
            if (!is_array($it)) continue;
            $label = self::line((string)($it['label'] ?? ''));
            if ($label === '') continue;
            $qty    = is_numeric($it['quantity'] ?? null) && (float)$it['quantity'] > 0 ? (float)$it['quantity'] : 1.0;
            $amount = is_numeric($it['total'] ?? null) ? (float)$it['total'] : (is_numeric($it['price'] ?? null) ? (float)$it['price'] * $qty : 0.0);
            $unit   = strtolower(trim((string)($it['unit'] ?? '')));
            if (preg_match('/install/i', $label)) { $install = ($install ?? 0.0) + $amount; continue; }
            if (preg_match('/transport|delivery|travel/i', $label)) { $transportListed = true; $transport = ($transport ?? 0.0) + $amount; continue; }
            if (preg_match('/\b(month|months|monthly|year|years|yearly|annual|annually|week|weekly)\b/', $unit)
                || preg_match('/\b(mbps|subscription|per month)\b/i', $label)) {
                if ($service === '') $service = $label;
                continue;
            }
            if (preg_match('/^(time|times|once|one[- ]?time|job|visit|lump ?sum)$/', $unit)) {
                if ($amount > 0) { $other += $amount; $otherLabels[] = $label; }   // a discount is never an agreed charge
                continue;
            }
            $equipment[] = $label . ($qty > 1 ? ' x' . self::num($qty) : '');
        }
        $transportPriced = $transportListed && ($transport ?? 0.0) > 0;
        return [
            'number'             => self::line((string)($quote['number'] ?? '')) !== '' ? self::line((string)$quote['number']) : '#' . (int)($quote['id'] ?? 0),
            'date'               => self::date((string)($quote['createdDate'] ?? ''), $tz),
            'service'            => self::cut($service, 160),
            'equipment'          => self::cut(implode(', ', $equipment), 160),
            'installation'       => $install !== null ? self::num(max(0.0, $install)) : null,
            'transport'          => $transportPriced ? self::num((float)$transport) : null,
            'transport_unpriced' => $transportListed && !$transportPriced,
            'other'              => $other > 0 ? self::num($other) : null,
            'other_label'        => $other > 0 ? self::cut(implode(', ', $otherLabels), 80) : '',
        ];
    }

    // ── internals ────────────────────────────────────────────────────────────

    private static function newer(array $a, array $b): bool
    {
        $ta = strtotime((string)($a['createdDate'] ?? '')) ?: 0;
        $tb = strtotime((string)($b['createdDate'] ?? '')) ?: 0;
        if ($ta !== $tb) return $ta > $tb;
        return (int)($a['id'] ?? 0) > (int)($b['id'] ?? 0);
    }

    /** One line of plain text: control characters and runs of spaces folded. */
    private static function line(string $s): string
    {
        $s = (string)preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $s);
        return trim((string)preg_replace('/\s+/u', ' ', $s));
    }

    private static function cut(string $s, int $max): string
    {
        return mb_strlen($s) > $max ? rtrim(mb_substr($s, 0, $max - 1)) . '…' : $s;
    }

    /** 150000 for a whole amount, 1500.50 otherwise: what the form's number field takes. */
    private static function num(float $v): string
    {
        $v = round($v, 2);
        return floor($v) == $v ? (string)(int)$v : number_format($v, 2, '.', '');
    }

    private static function date(string $iso, ?\DateTimeZone $tz): string
    {
        $iso = trim($iso);
        if ($iso === '') return '';
        try {
            $d = new \DateTime($iso);
            if ($tz !== null) $d->setTimezone($tz);
            return $d->format('j M Y');
        } catch (\Throwable $e) {
            return '';
        }
    }
}
