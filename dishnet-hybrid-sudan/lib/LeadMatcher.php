<?php
declare(strict_types=1);

/**
 * LeadMatcher — is this person already a lead?
 *
 * One rule, in one place. It was written inline in api/index.php's "convert
 * from WA Inbox" handler and worked correctly there; the moment a second
 * caller needed it, copying it would have created two dedupe rules that agree
 * today and drift apart the first time either is touched. A customer with two
 * leads is a customer two people call.
 *
 * The rule, unchanged from the original:
 *
 *   - compare the LAST NINE DIGITS, so +256 772 000 111, 256772000111 and
 *     0772000111 are one handset. Uganda numbers written locally drop the
 *     country code, and the same person writes it differently on different days.
 *   - skip leads that are won, lost or dead. Those are closed business; a new
 *     enquiry from the same person is a new opportunity, not a reopening.
 */
class LeadMatcher
{
    /** Statuses that end a lead's life. A new enquiry starts a new one. */
    public const CLOSED = ['won', 'lost', 'dead'];

    /** The comparable form of a phone number: last 9 digits, or '' if unusable. */
    public static function key(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        return strlen($digits) >= 9 ? substr($digits, -9) : '';
    }

    /**
     * The open lead for this phone, or null.
     *
     * @param array $leads rows from leads.json
     */
    public static function find(array $leads, string $phone): ?array
    {
        $want = self::key($phone);
        if ($want === '') return null;
        foreach ($leads as $lead) {
            if (!is_array($lead)) continue;
            if (self::key((string)($lead['phone'] ?? '')) !== $want) continue;
            if (in_array((string)($lead['status'] ?? ''), self::CLOSED, true)) continue;
            return $lead;
        }
        return null;
    }

    /** Index of that lead in the array, for in-place update. */
    public static function findIndex(array $leads, string $phone): ?int
    {
        $want = self::key($phone);
        if ($want === '') return null;
        foreach ($leads as $i => $lead) {
            if (!is_array($lead)) continue;
            if (self::key((string)($lead['phone'] ?? '')) !== $want) continue;
            if (in_array((string)($lead['status'] ?? ''), self::CLOSED, true)) continue;
            return (int)$i;
        }
        return null;
    }
}
