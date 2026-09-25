<?php
declare(strict_types=1);
namespace Dn\Auth;

/**
 * The one canonical form of a sign-in phone number (docs/126 D-3).
 *
 * The phone is the operator plane's AUTHENTICATION KEY: mt_auth_issue_code
 * looks it up EXACTLY, so "+256 700 123 456" and "+256700123456" would be two
 * different keys for one person. Spaces, dashes, dots and parentheses are
 * removed; what remains must be E.164 — a plus sign and 8 to 15 digits, the
 * first not zero. Anything else is not a phone number this platform can send a
 * code to, and is refused rather than guessed at: a national form such as
 * "0700 123 456" says nothing about which country it belongs to.
 *
 * Where a login is created (the Admin plane, docs/126) the number is stored in
 * this form. Where a login is used (the sign-in routes, once the operator app
 * is served) the same function must be applied to what the person types
 * (docs/126 D-4) — one form, at both ends.
 */
final class Phone
{
    public const PATTERN = '/^\+[1-9][0-9]{7,14}$/';

    /** The canonical form, or null when the input is not an E.164 number. */
    public static function canonical(string $raw): ?string
    {
        $p = preg_replace('/[\s\-.()]+/u', '', trim($raw)) ?? '';
        return preg_match(self::PATTERN, $p) ? $p : null;
    }
}
