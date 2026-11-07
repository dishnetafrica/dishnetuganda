<?php
declare(strict_types=1);

require_once __DIR__ . '/ContactOptOut.php';

/**
 * OfferText — what an offer says (5.18.104 P6, root docs/73 §H.4.2 O-D5/O-D6, §H.4.9). Pure.
 *
 * The admin writes two or three wordings; the code adds only §H.1's approved line, STOP_LINE, stamped on the offer row at
 * creation and appended to every message by render(). A wording that already says STOP is refused, so the line is never
 * doubled or altered. stopWordIn() is the offers-only stop-word test: ContactOptOut::STOP_WORDS as whole words, at ANY
 * length (ContactOptOut::detect() reads only a message of 40 characters or fewer). PHP 7.4.
 */
final class OfferText
{
    /** §H.1, verbatim: the STOP line on every offer. */
    const STOP_LINE = 'Reply STOP to stop these messages.';
    const MIN_CHARS = 20;
    const MAX_CHARS = 900;
    const TITLE_MAX = 80;

    /**
     * The fixed problems with a title and its wordings; [] = valid. Wordings are trimmed; an empty third is no wording.
     *
     * @param string[] $wordings
     * @return string[] title_length | wording_count | wording_length:<n> | wording_braces:<n> | wording_stop:<n> | wording_same
     */
    public static function validate(string $title, array $wordings): array
    {
        $bad = [];
        $tl = mb_strlen(trim($title));
        if ($tl < 1 || $tl > self::TITLE_MAX) $bad[] = 'title_length';
        $w = self::wordings($wordings);
        if (count($w) < 2 || count($w) > 3 || trim((string)($wordings[0] ?? '')) === '' || trim((string)($wordings[1] ?? '')) === '') {
            $bad[] = 'wording_count';
        }
        $folded = [];
        foreach ($w as $i => $text) {
            $n = $i + 1;
            $len = mb_strlen($text);
            if ($len < self::MIN_CHARS || $len > self::MAX_CHARS) $bad[] = 'wording_length:' . $n;
            if (strpbrk($text, '{}') !== false) $bad[] = 'wording_braces:' . $n;
            if (preg_match('/(?<![a-z])stop(?![a-z])/i', $text)) $bad[] = 'wording_stop:' . $n;
            $folded[] = self::fold($text);
        }
        if (count(array_unique($folded)) !== count($folded)) $bad[] = 'wording_same';
        return $bad;
    }

    /** The wordings as stored: trimmed, the empty ones dropped, in order. @return string[] */
    public static function wordings(array $wordings): array
    {
        $out = [];
        foreach (array_values($wordings) as $t) {
            $t = trim((string)$t);
            if ($t !== '') $out[] = $t;
        }
        return $out;
    }

    /** Exactly what a customer receives: the wording, a blank line, the STOP line stamped on the offer. */
    public static function render(string $wording, string $stopLine): string
    {
        return trim($wording) . "\n\n" . $stopLine;
    }

    /** sha256 of the wordings, the stop line and the image's sha256: what a test proves and a Start must match. */
    public static function contentHash(array $wordings, string $stopLine, ?string $imageSha256): string
    {
        return hash('sha256', json_encode([self::wordings($wordings), $stopLine, (string)$imageSha256],
                                          JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /** The wording the next send on a handset uses, in turn: 1 … $count, by the number of earlier ones there. */
    public static function variantFor(int $count, int $priorOnHandset): int
    {
        $n = max(1, min(3, $count));
        return ($priorOnHandset % $n) + 1;
    }

    /** The STOP_WORDS entry this text holds as a whole word, at any length; '' when none. */
    public static function stopWordIn(string $text): string
    {
        $t = strtolower(trim((string)preg_replace('/\s+/', ' ', $text)));
        if ($t === '') return '';
        $t = ' ' . preg_replace('/[^a-z0-9\' -]/', ' ', $t) . ' ';
        $t = (string)preg_replace('/\s+/', ' ', $t);
        foreach (ContactOptOut::STOP_WORDS as $w) {
            if (preg_match('/(?<![a-z])' . preg_quote($w, '/') . '(?![a-z])/', $t)) return $w;
        }
        return '';
    }

    /** Case and whitespace folded, for "distinct" (O-D6). */
    private static function fold(string $s): string
    {
        return mb_strtolower(trim((string)preg_replace('/\s+/u', ' ', $s)));
    }
}
