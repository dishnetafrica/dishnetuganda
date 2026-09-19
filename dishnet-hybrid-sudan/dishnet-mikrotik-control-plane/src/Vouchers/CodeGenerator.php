<?php
declare(strict_types=1);
namespace Dn\Vouchers;

/**
 * Voucher codes.
 *
 * docs/30 Artifact 8: 32-symbol alphabet with 0/O/1/I removed, drawn from a
 * CSPRNG, uniqueness enforced by a unique index rather than by checking first.
 *
 * Each of those three is load-bearing:
 *
 *   the alphabet — a code is read aloud across a reception desk and typed on
 *     a phone keypad. 0/O and 1/I are the pairs people get wrong.
 *   the CSPRNG  — a predictable code is free internet for whoever works out
 *     the pattern. rand() and uniqid() are both predictable.
 *   the index   — checking "does this code exist" and then inserting is a
 *     race. Two requests can both check, both see nothing, and both insert.
 *     The unique index decides it instead, and the caller retries.
 */
final class CodeGenerator implements CodeSource
{
    /** 32 symbols: A–Z and 2–9, less O and I. */
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    private const LENGTH   = 10;

    /** Formatted in blocks, which is how people read a code back. */
    public function generate(): string
    {
        $raw = '';
        for ($i = 0; $i < self::LENGTH; $i++) {
            $raw .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }
        return substr($raw, 0, 5) . '-' . substr($raw, 5);
    }

    public static function alphabet(): string { return self::ALPHABET; }

    /** Entropy, for the record: 32^10 ≈ 1.1e15. */
    public static function keyspace(): float
    {
        return strlen(self::ALPHABET) ** self::LENGTH;
    }
}
