<?php
/**
 * ReplyTotals — does a reply's total add up? (5.18.47, docs/41 §9)
 *
 * The price check (ReplyPrivacyGuard) asks whether each amount in a reply is a price we list, or a sum of them. On
 * 27 Sep 2026 the live assistant listed five items that add up to 4,527,000 and wrote "Total: UGX 4,627,000". And
 * 4,627,000 is itself a sum of listed prices — the kit, the installation, the MikroTik and the two Starlink routers —
 * so the check could not tell. With that price list about 4 in 10 round amounts between 1 and 6 million are such
 * sums (docs/41 §8.2). This asks the other question, by arithmetic on the reply itself: does the total match the
 * lines listed with it?
 *
 * The operator's decision, 27 Sep: "Build the total check". Two findings:
 *   missing   a money TOTAL label with no figure: "TOTAL TO GET CONNECTED: (Add total of kit, …)", "TOTAL: [Sum …]";
 *   mismatch  a stated total that is neither the sum of the list lines directly above it (with or without the lines
 *             priced per month) nor a total the reply already stated; or a written sum that is wrong
 *             ("2 × 700,000 = 1,500,000").
 *
 * Only where the arithmetic is unambiguous. A line with two prices, a unit price ("each", "per floor") with no count
 * anywhere, a total with no priced line above it, a total said in running prose, a "Total users: 50" — each is left
 * alone, so a correct reply the model wrote in an unusual shape is never refused for its shape. A count that is not
 * beside its price ("2 × Router 3 — 827,000", "For the two upper floors: 2 × Router Mini — 435,000", "Router Mini x2
 * — 435,000") leaves open whether the price is for one or for all: the line is read both ways, and a total that
 * matches either is sent. Only "each" with such a count says the price is for one. The 30 replies the live assistant
 * wrote on 27 Sep are the test set (tests/test_price_check_totals.php).
 *
 * Amounts are read exactly as the price check reads them (ReplyPrivacyGuard::amountSpans) — one definition.
 */
final class ReplyTotals
{
    /** A total label at the start of a line: "TOTAL:", "Total**:", "TOTAL TO GET CONNECTED:", "Total =", "Sub-total:". */
    private const LABEL = '/^(?:(?:the|your|grand|sub[- ]?)\s*)?total\b(?<words>[^:=\n]{0,60}?)[*_]*\s*(?<sep>[:=])/iu';

    /** "The total is / comes to / would be 2,399,000" — a stated total without a colon. Never a missing figure. */
    private const VERB_LABEL = '/^(?:(?:the|your|grand)\s+)?total\b(?<words>[^:=\n]{0,40}?)\s+(?:is|comes?\s+to|would\s+be|will\s+be|amounts?\s+to)\b/iu';

    /** Words that make "total …:" a sentence, not a label: "The total will depend on the cable length: …". */
    private const NOT_A_LABEL = '/\b(?:will|depends?|depending|vary|varies|confirm\w*|include\w*|exclud\w*|change\w*|may|might|can|could|should|breakdown|provided|below|above|after|before|if)\b/i';

    /** A label about money. A bare "Total" is; "Total users", "Total data", "Total coverage" are not. */
    private const MONEY_WORDS = '/\b(?:cost|costs|price|amount|pay|payable|due|charges?|set[- ]?up|connected|equipment|network|installation|install|order|package|kit|everything|items?|one[- ]?time|upfront|up[- ]front|investment|monthly|month|ugx|ush|shs|bill|start|get|all)\b/i';

    /** A list line: a bullet or a number. "**Bold**" at the start of a line is not a bullet. */
    private const BULLET = '/^\s*(?:[-•*▪◦·]|\d{1,2}[.)])\s+/u';

    /** A priced line without a bullet: "Name — 700,000 UGX", "Name: 700,000". */
    private const DASH_ITEM = '/^\s*[*_]*[A-Za-z][^\n]{1,120}?\s[—–]\s/u';
    private const COLON_ITEM = '/^\s*[*_]*[A-Za-z][^:\n]{1,80}:\s*[*_]*\s*(?:UGX|USh|Shs)?\s*\d/u';

    /** A price for one of something, with no worked quantity: then the line's total is not known. */
    private const UNIT = '/\b(?:each|apiece|per\s+(?:floor|unit|piece|room|building|point|router|item|device|metre|meter)|for\s+each)\b/i';

    /**
     * A count elsewhere in a line — neither beside the price nor at the item's start: "For the two upper floors: 2 ×
     * Router Mini — 435,000", "Router Mini x2 — 435,000", "Deco (3 x units) — 1,050,000". The price may be for one or
     * for all, so the line is read both ways. An upper-case X counts here though it is never read as "times": this
     * only ever adds a reading, it never refuses one.
     */
    private const MID_QUANTITY = '/(?<![\w.,])(\d{1,2})\s*[×xX]\s*[A-Za-z]|(?<![A-Za-z\d])[×xX]\s*(\d{1,2})(?![\d.,])/u';

    private const MONTHLY = '/\b(?:per\s+month|a\s+month|monthly|per\s+mo)\b|\/\s*(?:month|mo)\b/i';

    /**
     * What does not add up in $text.
     *
     * @return array<int,array{kind:string,line:string,stated:?float,sum:?float}>
     *         kind: missing | mismatch | equation
     */
    public static function findings(string $text, bool $plain = true): array
    {
        if (!class_exists('ReplyPrivacyGuard')) require_once __DIR__ . '/ReplyPrivacyGuard.php';
        $lines = preg_split('/\R/u', $text) ?: [];
        $out = [];
        $earlier = [];                                   // totals already stated, in order
        foreach ($lines as $i => $line) {
            // A written sum is checked wherever it stands.
            $eq = self::equation($line, $plain);
            if ($eq !== null && !self::near($eq['sums'], $eq['stated'])) {
                $out[] = ['kind' => 'equation', 'line' => self::clip($line), 'stated' => $eq['stated'], 'sum' => $eq['sum']];
            }

            $t = self::totalOf($lines, $i, $plain);
            if ($t === null) continue;
            if ($t['missing']) {
                $out[] = ['kind' => 'missing', 'line' => self::clip($line), 'stated' => null, 'sum' => null];
                continue;
            }
            if ($t['value'] === null) continue;       // a label introducing a figure further down
            $stated = $t['value'];
            $restated = self::near($earlier, $stated);
            $earlier[] = $stated;
            if ($restated) continue;

            $block = self::blockAbove($lines, $i, $plain);
            if ($block === null) continue;               // no priced list above it, or one that cannot be read surely
            if (!self::near($block['candidates'], $stated)) {
                $out[] = ['kind' => 'mismatch', 'line' => self::clip($line), 'stated' => $stated, 'sum' => $block['sum']];
            }
        }
        return $out;
    }

    /**
     * The total a line states, if it is a total line.
     *
     * @return array{missing:bool,value:?float}|null  null: not a total line
     */
    private static function totalOf(array $lines, int $i, bool $plain): ?array
    {
        $body = self::body($lines[$i]);
        if (preg_match(self::LABEL, $body, $m) === 1) {
            $words = trim((string)preg_replace('/[*_#~`]/', '', $m['words']));
            if ($words !== '' && (str_word_count($words) > 6 || preg_match(self::NOT_A_LABEL, $words) === 1)) return null;
            $money = $words === '' || preg_match(self::MONEY_WORDS, $words) === 1;
            $rest  = substr($body, strlen($m[0]));
            // The figure belongs to the label when it comes before a bracket, a sentence end or the end of the line.
            $seg = (string)preg_split('/[(\[]|\.\s|\.$/u', $rest, 2)[0];
            $spans = ReplyPrivacyGuard::amountSpans($seg, $plain);
            if ($spans !== []) {
                $all = ReplyPrivacyGuard::amountSpans($rest, $plain);
                $after = strrpos($rest, '=');
                if ($after !== false) {                  // "TOTAL: a + b = c": the total is c
                    foreach ($all as [$s, $o]) if ($o > $after) return ['missing' => false, 'value' => self::num($s)];
                }
                return ['missing' => false, 'value' => self::num($spans[0][0])];
            }
            if (!$money) return null;                    // "Total users: 50" — not money
            if (preg_match('/\d/', $seg) === 1) return null;
            if (trim((string)preg_replace('/[*_#~`\s]/u', '', $rest)) === '') {
                // "Your total for this setup would be:" — the figure may follow on the next lines.
                for ($j = $i + 1, $seen = 0; $j < count($lines) && $seen < 3; $j++) {
                    if (trim($lines[$j]) === '') continue;
                    $seen++;
                    if (ReplyPrivacyGuard::amountSpans($lines[$j], $plain) !== []) return ['missing' => false, 'value' => null];
                    return ['missing' => true, 'value' => null];
                }
                return ['missing' => true, 'value' => null];
            }
            return ['missing' => true, 'value' => null]; // "TOTAL: (Add total of …)", "TOTAL: [Sum of setup costs]"
        }
        if (preg_match(self::VERB_LABEL, $body, $m) === 1) {
            $words = trim((string)preg_replace('/[*_#~`]/', '', $m['words']));
            if ($words !== '' && (str_word_count($words) > 6 || preg_match(self::NOT_A_LABEL, $words) === 1)) return null;
            $rest = substr($body, strlen($m[0]));
            $seg = (string)preg_split('/[(\[]|\.\s|\.$/u', $rest, 2)[0];
            $spans = ReplyPrivacyGuard::amountSpans($seg, $plain);
            return $spans === [] ? null : ['missing' => false, 'value' => self::num($spans[0][0])];
        }
        return null;
    }

    /**
     * The priced list directly above line $i, and every total it could honestly add up to.
     *
     * @return array{candidates:float[],sum:float}|null  null: nothing to compare with, surely
     */
    private static function blockAbove(array $lines, int $i, bool $plain): ?array
    {
        $runs = [[]]; $collecting = false; $intros = 0; $stop = null; $priced = 0;
        for ($k = $i - 1; $k >= 0 && $i - $k <= 40; $k--) {
            $line = $lines[$k];
            if (trim($line) === '') {
                if ($collecting && $runs[count($runs) - 1] !== []) $runs[] = [];
                continue;
            }
            $t = self::totalOf($lines, $k, $plain);
            if ($t !== null && $t['value'] !== null) { $stop = $t['value']; break; }   // an earlier total (a subtotal)
            if ($t !== null && !$collecting) { $intros++; if ($intros > 2) break; continue; }
            $item = self::item($line, $plain);
            if ($item === 'ambiguous') return null;
            if ($item !== null) {
                $collecting = true;
                $runs[count($runs) - 1][] = $item;
                if (max($item['values']) > 0) $priced++;
                continue;
            }
            if (!$collecting && ReplyPrivacyGuard::amountSpans($line, $plain) === [] && $intros < 2) { $intros++; continue; }
            break;                                        // prose: the list ends here
        }
        if ($priced === 0) return null;
        $cands = []; $sums = [[0.0, 0.0]];               // every reading so far: [all lines, the one-time lines]
        foreach ($runs as $run) {
            if ($run === []) continue;
            foreach ($run as $it) {
                $next = [];
                foreach ($sums as [$a, $o]) foreach ($it['values'] as $v) $next[] = [$a + $v, $it['monthly'] ? $o : $o + $v];
                $sums = $next;
                if (count($sums) > 64) return null;       // too many readings to be sure of any
            }
            foreach ($sums as [$all, $once]) {
                $cands[] = $all; $cands[] = $once;
                if ($stop !== null) { $cands[] = $all + $stop; $cands[] = $once + $stop; }
            }
        }
        return ['candidates' => $cands, 'sum' => $sums[0][0]];
    }

    /**
     * A list line's value.
     *
     * @return array{values:float[],monthly:bool}|string|null  null: not a list line; 'ambiguous': its value is not sure
     */
    private static function item(string $line, bool $plain)
    {
        $isList = preg_match(self::BULLET, $line) === 1;
        $spans  = ReplyPrivacyGuard::amountSpans($line, $plain);
        if (!$isList && ($spans === [] || (preg_match(self::DASH_ITEM, $line) !== 1 && preg_match(self::COLON_ITEM, $line) !== 1))) return null;
        if (strlen($line) > 240) return null;
        $monthly = preg_match(self::MONTHLY, $line) === 1;
        if ($spans === []) return ['values' => [0.0], 'monthly' => $monthly];      // an item not priced
        $eq = strrpos($line, '=');
        if ($eq !== false) {                              // "2 × 700,000 UGX = 1,400,000 UGX": the line comes to what follows "="
            foreach ($spans as [$s, $o]) if ($o > $eq) return ['values' => [self::num($s)], 'monthly' => $monthly];
            return 'ambiguous';
        }
        if (count($spans) > 1) return 'ambiguous';        // "2,649,000 (or 2,249,000 for the Mini)"
        [$s, $o] = $spans[0];
        $v = self::values($line, $s, $o);
        return $v === null ? 'ambiguous' : ['values' => $v, 'monthly' => $monthly];
    }

    /**
     * A written sum on one line: "a + b + c = d", "N × a = d", "Total = a + b = c". The part between the last two "="
     * is the sum, what follows the last "=" is its result.
     *
     * @return array{sums:float[],sum:float,stated:float}|null  null: none, or not surely readable
     */
    private static function equation(string $line, bool $plain): ?array
    {
        $parts = explode('=', $line);
        if (count($parts) < 2) return null;
        $right = ReplyPrivacyGuard::amountSpans((string)end($parts), $plain);
        if ($right === []) return null;
        $left = $parts[count($parts) - 2];
        $sums = [0.0]; $terms = 0;
        foreach (explode('+', $left) as $term) {
            $spans = ReplyPrivacyGuard::amountSpans($term, $plain);
            if (count($spans) !== 1) return null;         // a term with no amount, or with two, is not surely read
            [$s, $o] = $spans[0];
            $v = self::values($term, $s, $o);
            if ($v === null) return null;                 // "827,000 each", and no count: not surely read
            $next = [];
            foreach ($sums as $x) foreach ($v as $y) $next[] = $x + $y;
            $sums = $next;
            if (count($sums) > 64) return null;
            $terms++;
        }
        return $terms === 0 ? null : ['sums' => $sums, 'sum' => $sums[0], 'stated' => self::num($right[0][0])];
    }

    /**
     * What a priced line, or one term of a written sum, may come to: one value; two, where a count is not beside its
     * price and so the price may be for one or for all; or null, where it cannot be read ("827,000 each" and no count).
     *
     * @return float[]|null
     */
    private static function values(string $text, string $amount, int $offset): ?array
    {
        $a = self::num($amount);
        $q = self::quantity($text, $amount, $offset);
        if ($q !== null) return [$q * $a];                            // "2 × 700,000", "700,000 × 2": arithmetic
        $unit = preg_match(self::UNIT, $text) === 1;
        $n = self::leading($text);
        if ($n !== null) return $unit ? [$n * $a] : [$n * $a, $a];    // "2 × Router 3 — 827,000": "each" says for one
        $c = preg_match_all(self::MID_QUANTITY, $text, $m, PREG_SET_ORDER);
        if ($c > 1) return null;                                      // two counts: which one is it?
        if ($c === 1) {                                               // "For the upper floors: 2 × Router Mini — 435,000"
            $k = (int)(($m[0][1] ?? '') !== '' ? $m[0][1] : ($m[0][2] ?? 1));
            return $unit ? [$k * $a, $a] : [$a, $k * $a];             // the likelier reading first: it is the one reported
        }
        if ($unit) return null;                                       // "827,000 (for each additional floor)"
        return [$a];
    }

    /** "2 × 700,000", "700,000 × 2": a count beside the price. null: none. */
    private static function quantity(string $text, string $amount, int $offset): ?int
    {
        $before = substr($text, 0, $offset);
        $after  = substr($text, $offset + strlen($amount));
        // Only "×" and a lower-case "x": "Standard 4 or 4 X" is a product name, and "*" is Markdown.
        if (preg_match('/(?<![\d.,])(\d{1,2})\s*[×x]\s*(?:UGX|USh|Shs)?\s*$/u', $before, $m) === 1) return (int)$m[1];
        if (preg_match('/^\s*(?:UGX|USh|Shs)?\s*[×x]\s*(\d{1,2})(?![\d,])/u', $after, $m) === 1) return (int)$m[1];
        return null;
    }

    /** "2 × Ruijie … 700,000": a count at the start of the item. null: none. */
    private static function leading(string $text): ?int
    {
        $item = (string)preg_replace(self::BULLET, '', $text);
        $item = ltrim((string)preg_replace('/^[*_#\s]+/u', '', $item));
        return preg_match('/^(\d{1,2})\s*[×x]\s+\S/u', $item, $m) === 1 ? (int)$m[1] : null;
    }

    /** Is $v one of $xs, to within half a shilling? */
    private static function near(array $xs, float $v): bool
    {
        foreach ($xs as $x) if (abs($x - $v) <= 0.5) return true;
        return false;
    }

    /** A line without its bullet and leading markup. */
    private static function body(string $line): string
    {
        $b = (string)preg_replace(self::BULLET, '', $line);
        return ltrim((string)preg_replace('/^[*_#~`>\s]+/u', '', $b));
    }

    private static function num(string $s): float
    {
        return (float)preg_replace('/[^\d.]/', '', str_replace(',', '', $s));
    }

    private static function clip(string $line): string
    {
        $l = trim($line);
        return mb_strlen($l) > 160 ? mb_substr($l, 0, 159) . '…' : $l;
    }
}
