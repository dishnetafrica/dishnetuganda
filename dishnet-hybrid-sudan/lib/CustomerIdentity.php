<?php
declare(strict_types=1);

/**
 * CustomerIdentity — who is on the other end of this WhatsApp number.
 *
 * This replaces a comparison that could bind the wrong customer:
 *
 *     if ($cPhone && (str_ends_with($cPhone, $phone) || str_ends_with($phone, $cPhone)))
 *
 * The incoming number had to be 8 digits. The STORED number had no minimum at
 * all, and the second half of that `||` matched whenever the incoming number
 * merely ended with whatever was on a client record. A client whose phone was
 * typed as a short local fragment — "758123", a truncated paste, an extension
 * — therefore matched every number ending in those digits. The first match in
 * the index won, and that client's balance, plan and payment history were
 * then assembled into the AI prompt and answered to whoever had sent the
 * message.
 *
 * ── THE RULE HERE ───────────────────────────────────────────────────────
 *
 * A phone number identifies a customer only when it matches EXACTLY on its
 * significant part, and only when exactly one customer matches.
 *
 *   - Both numbers must carry at least 9 significant digits. Uganda and South
 *     Sudan mobile numbers both have 9 after the country code, so anything
 *     shorter is not a phone number and can never identify anyone.
 *   - The last 9 digits must be equal. Not "ends with" — equal. That already
 *     normalises 0700123456, 256700123456 and +256 700 123 456 to one another.
 *   - Where both numbers carry a country code, the country codes must agree
 *     too, so a Ugandan and a South Sudanese number sharing nine digits are
 *     not the same person.
 *   - EVERY candidate is collected. Two customers on one number is ambiguous,
 *     and ambiguous is not identified. There is no first-match-wins.
 *
 * ── WHAT "NOT IDENTIFIED" MEANS ─────────────────────────────────────────
 *
 * Unknown, ambiguous and unusable are three different answers and the caller
 * is told which. None of them may produce customer-specific information. They
 * differ in what a human should do about it, not in what the AI may say.
 *
 * The resolved id is returned by the server and is never accepted from a
 * caller, a message, or a model. Nothing in this class takes a client id as
 * input.
 */
final class CustomerIdentity
{
    /** Digits after the country code on a Ugandan or South Sudanese number. */
    public const MIN_SIGNIFICANT = 9;

    public const IDENTIFIED = 'identified';
    public const UNKNOWN    = 'unknown';
    public const AMBIGUOUS  = 'ambiguous';
    public const UNUSABLE   = 'unusable';

    private $store;
    private $crm;

    /** @param object|null $crm anything with get(string): ?array */
    public function __construct($store, $crm = null)
    {
        $this->store = $store;
        $this->crm   = $crm;
    }

    // ── The comparison, kept pure so it can be tested exhaustively ──────────

    /** Just the digits. */
    public static function digits(string $phone): string
    {
        return (string)preg_replace('/[^0-9]/', '', $phone);
    }

    /**
     * The last 9 digits, or null when there are not 9 to take.
     *
     * Null is the honest answer for a fragment: it is not a number that can
     * identify anybody, and treating it as one is the bug this replaces.
     */
    public static function significant(string $phone): ?string
    {
        $d = self::digits($phone);
        if (strlen($d) < self::MIN_SIGNIFICANT) return null;
        return substr($d, -self::MIN_SIGNIFICANT);
    }

    /** Whatever precedes the significant part — a country code, or ''. */
    public static function prefix(string $phone): string
    {
        $d = self::digits($phone);
        if (strlen($d) <= self::MIN_SIGNIFICANT) return '';
        $p = substr($d, 0, -self::MIN_SIGNIFICANT);
        // A local number written 0700123456 carries a trunk zero, not a
        // country code. It says nothing about which country, so it is dropped
        // rather than being compared against "256".
        return ltrim($p, '0');
    }

    /**
     * Are these two the same subscriber?
     *
     * Exact on the significant digits, and on the country code when both
     * numbers state one. Never a suffix test in either direction.
     */
    public static function same(string $a, string $b): bool
    {
        $sa = self::significant($a);
        $sb = self::significant($b);
        if ($sa === null || $sb === null) return false;
        if ($sa !== $sb) return false;

        $pa = self::prefix($a);
        $pb = self::prefix($b);
        // One of them not stating a country is not evidence of disagreement.
        if ($pa === '' || $pb === '') return true;
        return $pa === $pb;
    }

    // ── Resolution ──────────────────────────────────────────────────────────

    /**
     * Resolve a WhatsApp number to at most one customer.
     *
     * @return array{status:string, client_id:int, client:?array, reason:string,
     *               candidates:array<int,int>}
     */
    public function resolve(string $phone): array
    {
        $none = static fn(string $status, string $why, array $cands = []): array => [
            'status' => $status, 'client_id' => 0, 'client' => null,
            'reason' => $why, 'candidates' => $cands,
        ];

        if (self::significant($phone) === null) {
            return $none(self::UNUSABLE,
                'fewer than ' . self::MIN_SIGNIFICANT . ' digits — not a number that can identify anyone');
        }

        $hits = [];   // client id => client row

        // The local index first. Every row is compared; none short-circuits.
        try {
            foreach ((array)($this->store->load('client_search_index.json') ?? []) as $c) {
                if (!is_array($c)) continue;
                $id = (int)($c['id'] ?? 0);
                if ($id <= 0) continue;
                foreach (self::phonesOf($c) as $cand) {
                    if (self::same($cand, $phone)) { $hits[$id] = $c; break; }
                }
            }
        } catch (\Throwable $e) {
            // An unreadable index is not "no customers" — say so rather than
            // letting a read failure look like an unknown number.
            return $none(self::UNKNOWN, 'the local client index could not be read');
        }

        // Then uCRM, which may know a contact the index has not cached. Its
        // results are re-checked here; the API's own matching is looser than
        // ours and is treated as a shortlist, never as an answer.
        if ($this->crm !== null) {
            try {
                $sig  = (string)self::significant($phone);
                $rows = $this->crm->get('clients?phone=' . rawurlencode($sig) . '&limit=25');
                if (!is_array($rows) || $rows === []) {
                    $rows = $this->crm->get('clients?search=' . rawurlencode($sig) . '&limit=25');
                }
                foreach ((array)$rows as $r) {
                    if (!is_array($r)) continue;
                    $id = (int)($r['id'] ?? 0);
                    if ($id <= 0) continue;
                    foreach (self::phonesOf($r) as $cand) {
                        if (self::same($cand, $phone)) { $hits[$id] = $r; break; }
                    }
                }
            } catch (\Throwable $e) {
                // Fall through on whatever the index found.
            }
        }

        $ids = array_map('intval', array_keys($hits));
        if ($ids === []) {
            return $none(self::UNKNOWN, 'no customer holds this number');
        }
        if (count($ids) > 1) {
            sort($ids);
            return $none(self::AMBIGUOUS,
                count($ids) . ' customers hold this number — a human must resolve which', $ids);
        }

        $id  = $ids[0];
        $row = $hits[$id];
        return ['status' => self::IDENTIFIED, 'client_id' => $id, 'client' => $row,
                'reason' => 'exact match on the last ' . self::MIN_SIGNIFICANT . ' digits',
                'candidates' => [$id]];
    }

    /** Every phone a client record carries, wherever uCRM happens to put it. */
    public static function phonesOf(array $client): array
    {
        $out = [];
        foreach (['phone', 'mobile', 'telephone'] as $k) {
            $v = trim((string)($client[$k] ?? ''));
            if ($v !== '') $out[] = $v;
        }
        foreach ((array)($client['contacts'] ?? []) as $ct) {
            if (!is_array($ct)) continue;
            foreach (['phone', 'mobile'] as $k) {
                $v = trim((string)($ct[$k] ?? ''));
                if ($v !== '') $out[] = $v;
            }
        }
        return array_values(array_unique($out));
    }
}
