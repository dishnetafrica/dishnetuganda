<?php
/**
 * KitTaxNote — the taxes line under a kit price (5.18.48, docs/42).
 *
 * On 27 Sep 2026 the live assistant quoted the Starlink Standard Kit at 2,649,000 and said nothing about tax. The
 * operator asked: "for kits can we clearly mention all the taxes are inclusive including UCC registration fees and
 * URA taxes so users feel more relevant?" — and approved the sentence below, written from those words. It is a
 * promise to every customer that the kit price is the whole price.
 *
 * Appended by code, as PlanFenceGuard appends the Business-plan note, and not asked of the model: the prompt version
 * of that note was measured, and 18 of 21 replies ignored it. The prompt already carries the operator's tax fact
 * (ai_fact_prices), and the reply of 27 Sep still said nothing.
 *
 * When: a reply the price check has passed states the price of a Starlink kit — a HARDWARE item whose name holds
 * both "Starlink" and "Kit" — read exactly as the price check reads amounts. A name alone is not enough: "Travel Kit
 * | Mini" is a case, sold as an accessory, and no UCC registration fee is in its price.
 *
 * Where: only where the hardware module is on (Uganda), the switch the price check's added rules use
 * (ReplyPrivacyGuard::optionsFor). Elsewhere — South Sudan — nothing is appended and nothing is read.
 *
 * The wording: ai_fact_kit_taxes. Unset is the approved sentence; "omit" switches the note off; anything else is the
 * operator's own wording. It names no price and no rate: prices come from uCRM only.
 */
final class KitTaxNote
{
    /** The operator's approved wording, 27 Sep 2026 (docs/42 §1). Overridden by ai_fact_kit_taxes. */
    public const DEFAULT_NOTE =
        'The kit price includes all taxes — URA taxes and the UCC registration fee are already in it. '
      . 'Nothing is added on top.';

    /** The note for this install, or '' where it is off: the hardware module is off, or ai_fact_kit_taxes is omit. */
    public static function note(array $config): string
    {
        if (!filter_var($config['ai_hardware_expert'] ?? false, FILTER_VALIDATE_BOOLEAN)) return '';
        $v = trim((string)($config['ai_fact_kit_taxes'] ?? ''));
        if ($v === '') return self::DEFAULT_NOTE;
        return strtolower($v) === 'omit' ? '' : $v;
    }

    /** A Starlink kit, by its name: "Starlink Standard Kit", "Starlink Mini Kit" — not "Travel Kit | Mini". */
    public static function isKit(string $name): bool
    {
        return preg_match('/\bstarlink\b/i', $name) === 1 && preg_match('/\bkits?\b/i', $name) === 1;
    }

    /**
     * The prices of the Starlink kits in the catalogue the reply was written from: its HARDWARE rows only, as
     * BrainContext hands them over. Accessories are a list of their own and are never read here.
     *
     * @return float[]
     */
    public static function kitPrices(array $products): array
    {
        $out = [];
        foreach ((array)($products['hardware'] ?? []) as $h) {
            if (!is_array($h) || !isset($h['price']) || !is_numeric($h['price'])) continue;
            if (!self::isKit((string)($h['name'] ?? ''))) continue;
            $p = (float)$h['price'];
            if ($p > 0 && !in_array($p, $out, true)) $out[] = $p;
        }
        return $out;
    }

    /**
     * @param float[] $kitPrices from kitPrices()
     * @return array{reply:string, appended:bool, reason:string}
     */
    public static function apply(string $reply, array $config, array $kitPrices): array
    {
        $keep = static fn(string $why): array => ['reply' => $reply, 'appended' => false, 'reason' => $why];

        if (trim($reply) === '') return $keep('empty reply');
        if (!filter_var($config['ai_hardware_expert'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            return $keep('the hardware module is off');
        }
        $note = self::note($config);
        if ($note === '')        return $keep('ai_fact_kit_taxes is omit');
        if ($kitPrices === [])   return $keep('no Starlink kit in the catalogue');
        if (!self::quotesKitPrice($reply, $kitPrices)) return $keep('no kit price in the reply');
        if (stripos($reply, $note) !== false)          return $keep('the note is already present');
        // The model sometimes says it in its own words. Twice in one message reads like a machine.
        if (preg_match('/\bUCC\b/i', $reply) === 1 && preg_match('/\btax/i', $reply) === 1) {
            return $keep('the reply already says it in its own words');
        }
        return ['reply' => rtrim($reply) . "\n\n" . $note, 'appended' => true, 'reason' => 'a Starlink kit price was quoted'];
    }

    /** Does the reply state one of these prices? Read as the price check reads amounts, for this install. */
    public static function quotesKitPrice(string $reply, array $kitPrices): bool
    {
        if (!class_exists('ReplyPrivacyGuard')) require_once __DIR__ . '/ReplyPrivacyGuard.php';
        // Plain amounts ("2649000") are read wherever the note can apply: the note needs the hardware module on,
        // which is the same switch that turns the price check's plain-amount reading on.
        foreach (ReplyPrivacyGuard::amountSpans($reply, true) as [$s]) {
            $v = (float)preg_replace('/[^\d.]/', '', str_replace(',', '', (string)$s));
            foreach ($kitPrices as $p) if (abs($v - (float)$p) <= 0.5) return true;
        }
        return false;
    }
}
