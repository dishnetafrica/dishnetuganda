<?php
declare(strict_types=1);
namespace Dn\Notify;

use Dn\Crypto\SecretBox;

/**
 * A sign-in code sealed for the SMS outbox (docs/127 S-3).
 *
 * The code must travel from the request (which invents it) to the worker (which
 * sends it) through the database, and a database dump must not carry live
 * sign-in codes. So it is sealed here, before the database sees it, and opened
 * only in the worker's memory. The database stores the envelope and erases it
 * the moment the message settles or expires (migration 032).
 *
 * THE KEY IS DERIVED, NOT REUSED. DNB_SECRET_KEY is the key for stored device
 * credentials; sealing sign-in codes with the same bytes would be key reuse
 * across unrelated purposes, so the key here is HMAC-derived from it under a
 * label of its own — the AdminSession precedent. An envelope from one purpose
 * does not open under the other, and the test suite proves both directions.
 *
 * The phone is the associated data: an envelope copied onto another number's
 * row does not open, so a code can only ever be sent to the number it was
 * issued for.
 */
final class CodeEnvelope
{
    public const LABEL = 'dn-sms-outbox-v1';

    private SecretBox $box;

    public function __construct(?string $masterKey = null)
    {
        $master = $masterKey ?? (getenv('DNB_SECRET_KEY') ?: '');
        if ($master === '') {
            throw new \RuntimeException(
                'DNB_SECRET_KEY is not set, so a sign-in code cannot be sealed for delivery. '
                . 'Refusing to run with an implicit or default key.');
        }
        $this->box = new SecretBox(hash_hmac('sha256', self::LABEL, $master, true));
    }

    public function seal(string $code, string $phone): string
    {
        return $this->box->seal($code, $phone);
    }

    /** @throws \RuntimeException when the envelope does not open — wrong key, wrong phone, or tampered. */
    public function open(string $sealed, string $phone): string
    {
        return $this->box->open($sealed, $phone);
    }
}
