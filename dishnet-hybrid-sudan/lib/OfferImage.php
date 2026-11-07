<?php
declare(strict_types=1);

require_once __DIR__ . '/FlyerAsset.php';

/**
 * OfferImage — an offer's optional image (5.18.104 P6, root docs/73 §H.4.7, §H.4.11).
 *
 * An upload is accepted by the type getimagesize() detects (JPEG, PNG or WebP), never by the client's file name, at most
 * FlyerAsset::MAX_FILE_BYTES, into <data>/offer_images/offer-<id>.<ext> (folder 0750), with its sha256 recorded on the
 * offer. It is never served by URL: Evolution gets base64 (FlyerAsset::payload, the path the AI's flyer takes) and the
 * screen a data URI. payload() checks the bytes against the recorded sha256 every time it is read, so a file changed or
 * removed after the tests is never sent. PHP 7.4.
 */
final class OfferImage
{
    const DIR = 'offer_images';
    /** Detected type => [mime, extension]. */
    const TYPES = [
        IMAGETYPE_JPEG => ['image/jpeg', 'jpg'],
        IMAGETYPE_PNG  => ['image/png', 'png'],
        IMAGETYPE_WEBP => ['image/webp', 'webp'],
    ];

    /**
     * Accept one upload ($_FILES['image']) for an offer. $move is move_uploaded_file unless a caller (a test) gives its own.
     *
     * @return array{ok:bool, code:string, file?:string, mime?:string, bytes?:int, sha256?:string}
     */
    public static function accept(array $upload, int $offerId, string $dataDir, ?callable $move = null): array
    {
        if ((int)($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) return ['ok' => false, 'code' => 'upload_error'];
        $tmp = (string)($upload['tmp_name'] ?? '');
        if ($tmp === '' || !is_file($tmp) || $offerId <= 0) return ['ok' => false, 'code' => 'upload_error'];
        $bytes = (int)@filesize($tmp);
        if ($bytes < 1) return ['ok' => false, 'code' => 'empty'];
        if ($bytes > FlyerAsset::MAX_FILE_BYTES) return ['ok' => false, 'code' => 'too_large'];
        $info = @getimagesize($tmp);
        $type = is_array($info) ? (int)($info[2] ?? 0) : 0;
        if (!isset(self::TYPES[$type])) return ['ok' => false, 'code' => 'not_an_image'];
        [$mime, $ext] = self::TYPES[$type];
        $dir = rtrim($dataDir, '/') . '/' . self::DIR;
        if (!is_dir($dir) && !@mkdir($dir, 0750, true)) return ['ok' => false, 'code' => 'store_failed'];
        @chmod($dir, 0750);
        $name = 'offer-' . $offerId . '.' . $ext;
        $dest = $dir . '/' . $name;
        $mover = $move ?? 'move_uploaded_file';
        if (!$mover($tmp, $dest) || !is_file($dest)) return ['ok' => false, 'code' => 'store_failed'];
        @chmod($dest, 0640);
        $sha = (string)hash_file('sha256', $dest);
        return ['ok' => true, 'code' => '', 'file' => $name, 'mime' => $mime, 'bytes' => (int)filesize($dest), 'sha256' => $sha];
    }

    /**
     * What goes into Evolution's media field: null when the offer has no image, '' when its file is missing or no longer
     * the bytes the offer recorded (image_changed: nothing is sent), else the base64 of the file.
     */
    public static function payload(string $dataDir, array $offer): ?string
    {
        $file = (string)($offer['image_file'] ?? '');
        if ($file === '') return null;
        $p = FlyerAsset::payload(['kind' => 'file', 'path' => rtrim($dataDir, '/') . '/' . self::DIR . '/' . basename($file)]);
        if ($p === '') return '';
        $raw = base64_decode($p, true);
        if ($raw === false || !hash_equals((string)($offer['image_sha256'] ?? ''), hash('sha256', $raw))) return '';
        return $p;
    }

    /** The image as a data URI for the screen's preview; '' when there is none or it no longer matches. */
    public static function thumbnailDataUri(string $dataDir, array $offer): string
    {
        $p = self::payload($dataDir, $offer);
        if ($p === null || $p === '') return '';
        $mime = (string)($offer['image_mime'] ?? '');
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) return '';
        return 'data:' . $mime . ';base64,' . $p;
    }
}
