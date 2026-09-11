<?php
declare(strict_types=1);

/**
 * FlyerAsset — where the plans flyer image lives, if the operator has one.
 *
 * Two ways to provide it, checked in this order:
 *
 *   1. A file dropped into the plugin's data directory as wa_flyer.jpg
 *      (or .jpeg / .png / .webp). No hosting needed — it is sent to
 *      Evolution as base64. This is the normal path: one docker cp.
 *   2. wa_flyer_url in settings — a public https address of the image,
 *      for operators who already host it on their website.
 *
 * Neither present means there is no flyer: the AI is never told the
 * <<FLYER>> action exists and nothing anywhere changes behaviour. That
 * absence IS the compatibility story for installs that predate this.
 */
class FlyerAsset
{
    const FILE_BASE = 'wa_flyer';

    /** extension => mime, in the order we look */
    const EXTS = [
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png'  => 'image/png',
        'webp' => 'image/webp',
    ];

    /** Evolution takes base64 inline; refuse anything absurd. */
    const MAX_FILE_BYTES = 4194304;   // 4 MB

    /**
     * Locate the flyer. Cheap — reads no image content, so it is safe to
     * call on every request and on worker construction.
     *
     * @return array|null  ['kind'=>'file','path'=>…,'mime'=>…]
     *                     or ['kind'=>'url','url'=>…]
     *                     or null when no flyer is configured.
     */
    public static function find(array $config, string $dataDir): ?array
    {
        foreach (self::EXTS as $ext => $mime) {
            $path = rtrim($dataDir, '/') . '/' . self::FILE_BASE . '.' . $ext;
            if (is_file($path)) {
                $size = (int)@filesize($path);
                if ($size > 0 && $size <= self::MAX_FILE_BYTES) {
                    return ['kind' => 'file', 'path' => $path, 'mime' => $mime];
                }
            }
        }

        $url = trim((string)($config['wa_flyer_url'] ?? ''));
        if ($url !== '' && preg_match('#^https?://#i', $url)) {
            return ['kind' => 'url', 'url' => $url];
        }

        return null;
    }

    /**
     * What goes into Evolution's `media` field: the URL itself, or the
     * file base64-encoded. Empty string when the file has gone missing
     * between find() and now — callers treat that as "no flyer today",
     * never as an error the customer sees.
     */
    public static function payload(array $found): string
    {
        if (($found['kind'] ?? '') === 'url') {
            return (string)($found['url'] ?? '');
        }
        $path = (string)($found['path'] ?? '');
        if ($path === '' || !is_file($path)) return '';
        $raw = @file_get_contents($path);
        if ($raw === false || $raw === '' || strlen($raw) > self::MAX_FILE_BYTES) return '';
        return base64_encode($raw);
    }

    /** One line for settings screens and doctors: what is installed, if anything. */
    public static function describe(array $config, string $dataDir): string
    {
        $f = self::find($config, $dataDir);
        if ($f === null)         return 'not installed';
        if ($f['kind'] === 'url') return 'using URL: ' . $f['url'];
        return 'installed: ' . basename($f['path'])
            . ' (' . number_format((float)@filesize($f['path']) / 1024, 0) . ' KB)';
    }
}
