<?php
declare(strict_types=1);

/**
 * MediaPolicy — the limits a customer's media must satisfy before a byte of it is fetched or kept (Batch 1, docs/55 §9).
 *
 * Every number and every allowed type lives here and nowhere else. The flag is OFF unless the operator sets it:
 * ai_media_enabled unset or false means the webhook records nothing, queues nothing and the media worker fetches
 * nothing — the plugin behaves exactly as it did before this batch. PHP 7.4 compatible.
 */
final class MediaPolicy
{
    public const DEFAULT_MAX_BYTES = 15 * 1024 * 1024;   // 15 MiB: a voice note is tens of KB, a photo a few MB
    public const MIN_MAX_BYTES     = 64 * 1024;
    public const CAP_MAX_BYTES     = 64 * 1024 * 1024;
    public const DEFAULT_TIMEOUT_S = 20;
    public const MIN_TIMEOUT_S     = 3;
    public const CAP_TIMEOUT_S     = 60;

    /** What Batch 1 will fetch and validate. Video and stickers are recorded as unsupported, never fetched. */
    public const SUPPORTED_KINDS = ['audio', 'image', 'document'];

    /**
     * The base media types accepted per kind (the part before any ';' — WhatsApp announces voice notes as
     * "audio/ogg; codecs=opus"). Anything else is refused as unsupported_mime without being kept.
     */
    public const ALLOWED = [
        'audio'    => ['audio/ogg', 'audio/opus', 'audio/mpeg', 'audio/mp3', 'audio/mp4', 'audio/aac', 'audio/amr',
                       'audio/wav', 'audio/x-wav', 'audio/webm'],
        'image'    => ['image/jpeg', 'image/png', 'image/webp'],
        'document' => ['application/pdf',
                       'application/msword',
                       'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                       'application/vnd.ms-excel',
                       'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                       'text/csv', 'text/plain'],
    ];

    public static function enabled(array $config): bool
    {
        $v = $config['ai_media_enabled'] ?? null;
        if ($v === null || $v === '') return false;
        if (is_bool($v)) return $v;
        return in_array(strtolower(trim((string)$v)), ['1', 'true', 'on', 'yes'], true);
    }

    public static function maxBytes(array $config): int
    {
        $v = $config['ai_media_max_bytes'] ?? null;
        $n = is_numeric($v) ? (int)$v : self::DEFAULT_MAX_BYTES;
        return max(self::MIN_MAX_BYTES, min(self::CAP_MAX_BYTES, $n));
    }

    public static function timeoutSeconds(array $config): int
    {
        $v = $config['ai_media_timeout_s'] ?? null;
        $n = is_numeric($v) ? (int)$v : self::DEFAULT_TIMEOUT_S;
        return max(self::MIN_TIMEOUT_S, min(self::CAP_TIMEOUT_S, $n));
    }

    /** "audio/ogg; codecs=opus" → "audio/ogg"; lower-cased, trimmed. */
    public static function baseMime(string $mime): string
    {
        $m = strtolower(trim((string)explode(';', $mime)[0]));
        return $m;
    }

    public static function kindSupported(string $kind): bool
    {
        return in_array($kind, self::SUPPORTED_KINDS, true);
    }

    public static function allowed(string $kind, string $mime): bool
    {
        $base = self::baseMime($mime);
        if ($base === '') return false;
        return in_array($base, self::ALLOWED[$kind] ?? [], true);
    }
}
