<?php
declare(strict_types=1);
/**
 * PhoneNumber — the one rule that turns what a person typed into the
 * international number a message is addressed to.
 *
 * Phase 2 of the customer-login audit (remediation plan §D.4). Until now the
 * sign-in path carried a rule of its own with the South Sudan dial code
 * written into it, so a Uganda customer's code was addressed to a number in
 * another country. This helper carries NO dial code of its own: the caller
 * passes the tenant's (TenantProfile::dialCode()), and a test reads this file
 * for any digit sequence that looks like one.
 *
 * Rules, in order ("digits" = the input with everything that is not a digit
 * removed):
 *   - a '00' prefix            → international; the '00' is dropped
 *   - a leading '+'            → international; kept as typed
 *   - 11 digits or more        → already international; kept as typed
 *   - a leading national '0'   → dropped; the rest must be a national significant number
 *   - a bare national number   → the tenant's dial code in front
 *   - anything else            → null. Never guess.
 * An international candidate must not start with '0' and needs at least
 * MIN_INTERNATIONAL digits; nothing is ever re-prefixed.
 *
 * Lookup is a different question: ca_phone_normalize() (the last nine digits)
 * stays the index key. This helper decides only where a message goes and
 * which canonical identifier a sign-in is recorded under.
 */
final class PhoneNumber
{
    /** The shortest number accepted as international: a 1–3 digit country code and a 7-digit subscriber number. */
    const MIN_INTERNATIONAL = 10;

    /** The tenant's rule: dial code and national-number length from the profile. */
    public static function international(string $raw, TenantProfile $tenant): ?string
    {
        return self::withDialCode($raw, $tenant->dialCode(), $tenant->nsnLength());
    }

    /**
     * @param string $dialCode the country code, with or without '+' (e.g. "+256"); empty → null for every input
     * @param int    $nsnLength the national significant number's length (9 for Uganda and South Sudan)
     */
    public static function withDialCode(string $raw, string $dialCode, int $nsnLength = 9): ?string
    {
        $dial = (string)preg_replace('/\D+/', '', $dialCode);
        if ($dial === '' || $nsnLength < 6) return null;          // a tenant without a dial code addresses nothing
        $trim = trim($raw);
        $plus = $trim !== '' && $trim[0] === '+';
        $digits = (string)preg_replace('/\D+/', '', $trim);
        if ($digits === '') return null;
        if (strpos($digits, '00') === 0) { $plus = true; $digits = substr($digits, 2); }
        if ($plus || strlen($digits) >= 11) {
            if ($digits === '' || $digits[0] === '0' || strlen($digits) < self::MIN_INTERNATIONAL) return null;
            return '+' . $digits;                                  // kept as typed, never re-prefixed
        }
        if ($digits[0] === '0') $digits = substr($digits, 1);      // national trunk prefix
        return strlen($digits) === $nsnLength ? '+' . $dial . $digits : null;
    }
}
