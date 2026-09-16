<?php
declare(strict_types=1);

/**
 * ShopImages — the product photos, served safely and small.
 *
 * uCRM serves only public.php from a plugin directory, so the photos in
 * assets/shop/ are reached through ?page=shop_img&s=<slug>&w=<240|480>.
 * The slug is looked up in the catalogue, never used as a path, so the route
 * cannot be walked. Sized copies are WebP made with GD and cached in the data
 * directory; without GD, or for any other width, the original is served.
 */
final class ShopImages
{
    const WIDTHS  = [240, 480];
    const CACHE   = 'shop_cache';
    const QUALITY = 82;

    /** @return array{path:string,mime:string}|null */
    public static function resolve(string $pluginRoot, array $catalogue, string $slug): ?array
    {
        if (!preg_match('/^[a-z0-9][a-z0-9-]{0,80}$/', $slug)) return null;
        $item = ShopCatalogue::bySlug($catalogue, $slug);
        if ($item === null || $item['image'] === '') return null;
        $dir  = realpath(rtrim($pluginRoot, '/') . '/' . ShopCatalogue::DIR);
        $path = $dir === false ? false : realpath($dir . '/' . $item['image']);
        if ($dir === false || $path === false || strpos($path, $dir . DIRECTORY_SEPARATOR) !== 0 || !is_file($path)) return null;
        $mime = self::mimeOf($path);
        if ($mime === null) return null;
        return ['path' => $path, 'mime' => $mime];
    }

    /**
     * A w×w WebP copy of $src for the shop grid, cached under the data
     * directory; the original when the width is not one we make or GD is
     * absent. Never throws: on any failure the original is the answer.
     *
     * @return array{path:string,mime:string}
     */
    public static function variant(array $src, string $dataDir, string $slug, int $width): array
    {
        if (!in_array($width, self::WIDTHS, true) || !function_exists('imagewebp') || !function_exists('imagecreatefromstring')) {
            return $src;
        }
        $dir = rtrim($dataDir, '/') . '/' . self::CACHE;
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) return $src;
        $out = $dir . '/' . $slug . '-' . $width . '.webp';
        $srcM = (int)@filemtime($src['path']);
        if (is_file($out) && (int)@filemtime($out) >= $srcM && (int)@filesize($out) > 0) {
            return ['path' => $out, 'mime' => 'image/webp'];
        }
        try {
            $im = @imagecreatefromstring((string)@file_get_contents($src['path']));
            if ($im === false) return $src;
            $w = imagesx($im); $h = imagesy($im);
            if ($w < 1 || $h < 1) return $src;
            $dst = imagecreatetruecolor($width, $width);
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
            imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
            // Fit inside the square, centred, keeping the aspect ratio.
            $scale = min($width / $w, $width / $h);
            $nw = max(1, (int)round($w * $scale)); $nh = max(1, (int)round($h * $scale));
            imagecopyresampled($dst, $im, (int)(($width - $nw) / 2), (int)(($width - $nh) / 2), 0, 0, $nw, $nh, $w, $h);
            $tmp = $out . '.tmp.' . getmypid();
            $ok  = @imagewebp($dst, $tmp, self::QUALITY);
            imagedestroy($dst); imagedestroy($im);
            if (!$ok || !is_file($tmp) || (int)@filesize($tmp) === 0) { @unlink($tmp); return $src; }
            @rename($tmp, $out);
            return is_file($out) ? ['path' => $out, 'mime' => 'image/webp'] : $src;
        } catch (\Throwable $e) {
            return $src;
        }
    }

    private static function mimeOf(string $path): ?string
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $map = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'webp' => 'image/webp'];
        return $map[$ext] ?? null;
    }
}
