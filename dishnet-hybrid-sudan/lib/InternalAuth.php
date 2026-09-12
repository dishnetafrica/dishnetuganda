<?php
declare(strict_types=1);

/**
 * InternalAuth — the shared secret two DishNet plugins use to trust each other.
 *
 * Both plugins live under the same uCRM plugins directory and talk over
 * loopback HTTP. The secret sits in a sibling folder each can reach, is
 * created on first use, and is never sent anywhere but localhost.
 *
 * This is NOT a customer-facing credential and must never gate anything a
 * customer can reach: it says "the request came from the other half of this
 * system", nothing about who is asking.
 */
final class InternalAuth
{
    public const HEADER = 'X-DishNet-Internal-Auth';

    /** @return string the secret, or '' if one could not be established */
    public static function secret(): string
    {
        $candidates = [
            dirname(__DIR__, 2) . '/_dishnet_shared/internal_auth.json',
            dirname(__DIR__, 1) . '/../_dishnet_shared/internal_auth.json',
        ];
        foreach ($candidates as $p) {
            if (!file_exists($p)) continue;
            $j = @json_decode((string)@file_get_contents($p), true);
            if (is_array($j) && !empty($j['secret'])) return (string)$j['secret'];
        }

        $file = $candidates[0];
        try {
            @mkdir(dirname($file), 0755, true);
            $secret = bin2hex(random_bytes(24));
            @file_put_contents($file, json_encode([
                'secret' => $secret, 'created_at' => date('c'), 'created_by' => 'InternalAuth',
            ]), LOCK_EX);
            @chmod($file, 0640);
            return $secret;
        } catch (\Throwable $e) {
            return '';
        }
    }

    /** "X-DishNet-Internal-Auth: <secret>", or '' when there is no secret. */
    public static function header(): string
    {
        $s = self::secret();
        return $s !== '' ? (self::HEADER . ': ' . $s) : '';
    }

    /**
     * Does this request carry the secret?
     *
     * Compared with hash_equals: a plain === leaks the secret one byte at a
     * time to anything that can measure the response, and this endpoint is
     * reachable over loopback by every other process on the box.
     */
    public static function verify(?string $presented = null): bool
    {
        $expected = self::secret();
        if ($expected === '') return false;

        if ($presented === null) {
            $presented = (string)($_SERVER['HTTP_X_DISHNET_INTERNAL_AUTH'] ?? '');
            if ($presented === '' && function_exists('getallheaders')) {
                foreach ((array)getallheaders() as $k => $v) {
                    if (strcasecmp((string)$k, self::HEADER) === 0) { $presented = (string)$v; break; }
                }
            }
        }
        return $presented !== '' && hash_equals($expected, $presented);
    }
}
