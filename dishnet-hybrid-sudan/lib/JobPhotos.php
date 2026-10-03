<?php
/**
 * JobPhotos — labelled site photos and the completion location of a uCRM job (5.18.66, Uganda only).
 *
 * A technician photographs the kit, the cable used and the router on the job page, and the plugin records where they
 * were when they marked the job completed. Everything lives in the plugin's own data directory and its SQLite tables;
 * nothing new is written to uCRM.
 *
 *   labels    a fixed list, never free text — so a label can never become part of a path (the install_photos flaw,
 *             includes/api/api_support.php install_upload_photo)
 *   files     $dataDir/uploads/job_photos/<job id>/<label>-<staff id>-<time>-<random>.jpg — named by the server, served
 *             by ?page=job_photo&id=<row id> (includes/routes.php) from the row, never from the URL
 *   images    checked with getimagesize() — a name ending .jpg proves nothing — then re-encoded with GD to at most
 *             MAX_EDGE px on the long edge, JPEG QUALITY, upright: a 4 MB camera shot stores at a few hundred KB and a
 *             serial sticker stays readable. Without GD the checked original is kept as it arrived.
 *   rows      job_photos and job_completion_gps (migrations/084_job_photos.sql) — real tables, not id-keyed JSON lists,
 *             which SqliteStore::load() strips of their keys unless listed in $FLAT_TABLES (lib/SqliteStore.php)
 *   access    decided by the caller before anything here runs (lib/JobAccess.php: the assignee, a support leader or an
 *             admin). This class trusts nothing from the browser but the bytes of the image.
 *
 * PHP 7.4. Only Uganda reads or writes any of this (StaffJobsGate); on every other install the tables stay empty.
 */
final class JobPhotos
{
    /** Label → what the technician sees. The order is the display order. */
    public const LABELS = ['kit' => 'Kit / dish', 'cable' => 'Cable used', 'model' => 'Router / model', 'other' => 'Other'];
    /** The labels an installation job must carry before it can be completed (while job_photos_required is not '0'). */
    public const REQUIRED = ['kit', 'cable', 'model'];
    /** An installation, by its title — the same words scheduling_complete uses to queue the job's invoice. Keep in step. */
    public const INSTALL_KEYWORDS = ['install', 'fiber', 'fibre', 'starlink', 'ftth', 'lte activation'];

    public const DIR         = 'uploads/job_photos';
    public const MAX_BYTES   = 8388608;   // 8 MB — the browser sends a few hundred KB after its own resize
    public const MAX_PER_JOB = 12;
    public const MAX_EDGE    = 1600;      // px, long edge, after re-encoding
    public const QUALITY     = 82;        // JPEG
    public const REASON_MIN  = 3;         // characters, "no GPS fix" note
    public const REASON_MAX  = 200;

    // ── Rules ────────────────────────────────────────────────────────────────

    /** Off only when the setting is exactly '0': the photos are the point of the feature. */
    public static function photosRequired(array $config): bool
    {
        return (string)($config['job_photos_required'] ?? '1') !== '0';
    }

    public static function isInstallJob(array $job): bool
    {
        $t = strtolower((string)($job['title'] ?? ''));
        foreach (self::INSTALL_KEYWORDS as $k) {
            if (strpos($t, $k) !== false) return true;
        }
        return false;
    }

    /** "Kit / dish, Cable used" from ['kit', 'cable'], for a refusal a technician can act on. */
    public static function labelNames(array $labels): string
    {
        $out = [];
        foreach ($labels as $l) $out[] = self::LABELS[$l] ?? (string)$l;
        return implode(', ', $out);
    }

    public static function dir(string $dataDir, int $jobId): string
    {
        return rtrim($dataDir, '/') . '/' . self::DIR . '/' . $jobId;
    }

    // ── Reading ──────────────────────────────────────────────────────────────

    /** The job's photos, oldest first, as the API shows them (no file path). */
    public static function list(\PDO $pdo, int $jobId): array
    {
        $st = $pdo->prepare('SELECT * FROM job_photos WHERE job_id = ? ORDER BY id');
        $st->execute([$jobId]);
        $out = [];
        foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $r) $out[] = self::publicRow($r);
        return $out;
    }

    public static function count(\PDO $pdo, int $jobId): int
    {
        $st = $pdo->prepare('SELECT COUNT(*) FROM job_photos WHERE job_id = ?');
        $st->execute([$jobId]);
        return (int)$st->fetchColumn();
    }

    /** The required labels this job does not carry yet, in REQUIRED's order. */
    public static function missingRequired(\PDO $pdo, int $jobId): array
    {
        $st = $pdo->prepare('SELECT DISTINCT label FROM job_photos WHERE job_id = ?');
        $st->execute([$jobId]);
        $have = array_map('strval', $st->fetchAll(\PDO::FETCH_COLUMN));
        return array_values(array_diff(self::REQUIRED, $have));
    }

    /** One row, whole, for the route that serves the file and for delete. null when there is none. */
    public static function find(\PDO $pdo, int $id): ?array
    {
        if ($id <= 0) return null;
        $st = $pdo->prepare('SELECT * FROM job_photos WHERE id = ?');
        $st->execute([$id]);
        $r = $st->fetch(\PDO::FETCH_ASSOC);
        if (!$r) return null;
        $r['id'] = (int)$r['id']; $r['job_id'] = (int)$r['job_id']; $r['retailer_id'] = (int)$r['retailer_id'];
        $r['assignee_id'] = $r['assignee_id'] === null ? null : (int)$r['assignee_id'];
        $r['url'] = self::url($r['id']);
        return $r;
    }

    public static function url(int $id): string
    {
        return '?page=job_photo&id=' . $id;
    }

    /** The absolute path of a stored photo — only while it lies under the job-photos folder and exists. */
    public static function path(string $dataDir, array $row): ?string
    {
        $base = realpath(rtrim($dataDir, '/') . '/' . self::DIR);
        $rel  = (string)($row['file_rel'] ?? '');
        if ($base === false || $rel === '' || strpos($rel, "\0") !== false) return null;
        $full = realpath(rtrim($dataDir, '/') . '/' . $rel);
        if ($full === false || strpos($full, $base . DIRECTORY_SEPARATOR) !== 0) return null;
        return is_file($full) ? $full : null;
    }

    private static function publicRow(array $r): array
    {
        $label = (string)$r['label'];
        return [
            'id'          => (int)$r['id'],
            'job_id'      => (int)$r['job_id'],
            'label'       => $label,
            'label_name'  => self::LABELS[$label] ?? $label,
            'retailer_id' => (int)$r['retailer_id'],
            'technician'  => (string)$r['technician'],
            'mime'        => (string)$r['mime'],
            'bytes'       => (int)$r['bytes'],
            'width'       => $r['width']  === null ? null : (int)$r['width'],
            'height'      => $r['height'] === null ? null : (int)$r['height'],
            'created_at'  => (string)$r['created_at'],
            'url'         => self::url((int)$r['id']),
        ];
    }

    // ── Writing ──────────────────────────────────────────────────────────────

    /**
     * Take one upload and keep it as a photo of this job.
     *
     * @param array    $file     one entry of $_FILES
     * @param array    $by       the caller: id, name, and ucrm_user_id (the VERIFIED link, StaffDirectory::linkedUcrmUser)
     * @param int|null $assignee the job's assignee as uCRM answered at upload time (JobAccess::assigneeOf)
     * @return array{ok:bool,error:string,photo:?array}
     */
    public static function store(\PDO $pdo, string $dataDir, int $jobId, string $label, array $file, array $by, ?int $assignee): array
    {
        if ($jobId <= 0) return self::fail('job_id required.');
        if (!isset(self::LABELS[$label])) return self::fail('Unknown photo label.');

        $err = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($err === UPLOAD_ERR_NO_FILE) return self::fail('No photo was received.');
        if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) return self::fail('That photo is larger than the server accepts.');
        if ($err !== UPLOAD_ERR_OK) return self::fail('The upload did not complete (code ' . $err . ').');
        $tmp = (string)($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_readable($tmp)) return self::fail('The uploaded photo could not be read.');
        $bytes = (int)@filesize($tmp);
        if ($bytes <= 0) return self::fail('That photo is empty.');
        if ($bytes > self::MAX_BYTES) {
            return self::fail('That photo is ' . round($bytes / 1048576, 1) . ' MB. The limit is ' . (int)(self::MAX_BYTES / 1048576) . ' MB.');
        }

        // What is it really? The name and the declared type prove nothing.
        $info   = @getimagesize($tmp);
        $byType = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'];
        $type   = is_array($info) ? (int)($info[2] ?? 0) : 0;
        if (!isset($byType[$type])) return self::fail('That is not a JPG, PNG or WebP photo.');
        $w = (int)($info[0] ?? 0);
        $h = (int)($info[1] ?? 0);
        if ($w < 16 || $h < 16) return self::fail('That image is too small to be a photo.');

        $dir = self::dir($dataDir, $jobId);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) return self::fail('Could not create the photo folder.');

        // Re-encode: upright, at most MAX_EDGE on the long edge, JPEG. Without GD the checked original is kept.
        $enc  = self::reencode($tmp, $type);
        $ext  = $enc !== null ? 'jpg' : $byType[$type];
        $mime = $enc !== null ? 'image/jpeg' : (string)($info['mime'] ?? ('image/' . ($ext === 'jpg' ? 'jpeg' : $ext)));
        $name = $label . '-' . (int)($by['id'] ?? 0) . '-' . date('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.' . $ext;
        $dest = $dir . '/' . $name;
        if ($enc !== null) {
            if (@file_put_contents($dest, $enc['data'], LOCK_EX) === false) return self::fail('Could not save the photo. Check the folder is writable.');
            $w = $enc['w'];
            $h = $enc['h'];
        } else {
            $moved = is_uploaded_file($tmp) ? @move_uploaded_file($tmp, $dest) : @rename($tmp, $dest);
            if (!$moved && !@copy($tmp, $dest)) return self::fail('Could not save the photo. Check the folder is writable.');
        }
        @chmod($dest, 0664);

        $rel = self::DIR . '/' . $jobId . '/' . $name;
        $st  = $pdo->prepare('INSERT INTO job_photos (job_id, label, retailer_id, ucrm_user_id, assignee_id, technician, file_rel, mime, bytes, width, height, sha256)
                              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $link = (int)($by['ucrm_user_id'] ?? 0);
        $st->execute([$jobId, $label, (int)($by['id'] ?? 0), $link > 0 ? $link : null, $assignee, (string)($by['name'] ?? ''),
                      $rel, $mime, (int)filesize($dest), $w, $h, hash_file('sha256', $dest)]);
        $row = self::find($pdo, (int)$pdo->lastInsertId());
        return ['ok' => true, 'error' => '', 'photo' => $row === null ? null : self::publicRow($row)];
    }

    /** Remove one photo: the file (when it is where the row says) and the row. True when a row was removed. */
    public static function delete(\PDO $pdo, string $dataDir, array $row): bool
    {
        $p = self::path($dataDir, $row);
        if ($p !== null) @unlink($p);
        $st = $pdo->prepare('DELETE FROM job_photos WHERE id = ?');
        $st->execute([(int)($row['id'] ?? 0)]);
        return $st->rowCount() > 0;
    }

    /**
     * Decode, turn upright, shrink to MAX_EDGE, encode as JPEG. null when GD cannot (then the original is kept).
     * @return array{data:string,w:int,h:int}|null
     */
    private static function reencode(string $path, int $type): ?array
    {
        if (!function_exists('imagecreatetruecolor') || !function_exists('imagejpeg')) return null;
        $src = false;
        try {
            if ($type === IMAGETYPE_JPEG && function_exists('imagecreatefromjpeg'))      $src = @imagecreatefromjpeg($path);
            elseif ($type === IMAGETYPE_PNG && function_exists('imagecreatefrompng'))   $src = @imagecreatefrompng($path);
            elseif ($type === IMAGETYPE_WEBP && function_exists('imagecreatefromwebp')) $src = @imagecreatefromwebp($path);
        } catch (\Throwable $e) {
            $src = false;
        }
        if (!$src) return null;

        // A JPEG straight from a camera carries its rotation in EXIF. (The browser's own resize already drops it.)
        if ($type === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
            $exif = @exif_read_data($path);
            $o = (int)($exif['Orientation'] ?? 1);
            if ($o === 3 || $o === 6 || $o === 8) {
                $rot = @imagerotate($src, $o === 3 ? 180 : ($o === 6 ? -90 : 90), 0);
                if ($rot) { imagedestroy($src); $src = $rot; }
            }
        }

        $w = imagesx($src);
        $h = imagesy($src);
        $edge = max($w, $h);
        $nw = $w; $nh = $h;
        if ($edge > self::MAX_EDGE) {
            $r  = self::MAX_EDGE / $edge;
            $nw = max(1, (int)round($w * $r));
            $nh = max(1, (int)round($h * $r));
        }
        // Always draw onto a white canvas: a transparent PNG/WebP would otherwise get black where it was clear.
        $dst = imagecreatetruecolor($nw, $nh);
        if (!$dst) { imagedestroy($src); return null; }
        $white = imagecolorallocate($dst, 255, 255, 255);
        imagefill($dst, 0, 0, $white);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
        imagedestroy($src);

        ob_start();
        $ok   = imagejpeg($dst, null, self::QUALITY);
        $data = (string)ob_get_clean();
        imagedestroy($dst);
        if (!$ok || $data === '') return null;
        return ['data' => $data, 'w' => $nw, 'h' => $nh];
    }

    // ── The completion location ──────────────────────────────────────────────

    /**
     * What the browser sent with scheduling_complete: a fix (lat, lon, accuracy, gps_client_ts) or a reason there is
     * none (gps_missing_reason). One or the other is required; neither is taken on trust.
     * @return array{ok:bool,error:string,gps:?array}
     */
    public static function validateGps(array $body): array
    {
        $lat = $body['lat'] ?? null;
        $lon = $body['lon'] ?? null;
        $has = ($lat !== null && $lat !== '') || ($lon !== null && $lon !== '');
        $ts  = isset($body['gps_client_ts']) ? substr(trim((string)$body['gps_client_ts']), 0, 40) : null;
        if ($ts === '') $ts = null;

        if ($has) {
            if (!is_numeric($lat) || !is_numeric($lon)) return self::fail('The location is not readable.');
            $lat = (float)$lat;
            $lon = (float)$lon;
            if ($lat < -90 || $lat > 90 || $lon < -180 || $lon > 180) return self::fail('The location is out of range.');
            if ($lat == 0.0 && $lon == 0.0) return self::fail('The location reads 0,0 — that is not a real fix.');
            $acc = $body['accuracy'] ?? null;
            if ($acc !== null && $acc !== '') {
                if (!is_numeric($acc) || (float)$acc < 0 || (float)$acc > 100000) return self::fail('The location accuracy is not readable.');
                $acc = round((float)$acc, 1);
            } else {
                $acc = null;
            }
            return ['ok' => true, 'error' => '', 'gps' => [
                'lat' => round($lat, 7), 'lon' => round($lon, 7), 'accuracy_m' => $acc,
                'source' => 'browser', 'missing_reason' => null, 'client_ts' => $ts,
            ]];
        }

        $reason = trim((string)($body['gps_missing_reason'] ?? ''));
        $len = function_exists('mb_strlen') ? mb_strlen($reason) : strlen($reason);
        if ($len < self::REASON_MIN) {
            return self::fail('Location is required to complete this job: allow location access and try again, or say why no GPS fix was possible.');
        }
        if ($len > self::REASON_MAX) return self::fail('Keep the GPS note under ' . self::REASON_MAX . ' characters.');
        return ['ok' => true, 'error' => '', 'gps' => [
            'lat' => null, 'lon' => null, 'accuracy_m' => null,
            'source' => 'missing', 'missing_reason' => $reason, 'client_ts' => $ts,
        ]];
    }

    /** One row per job: a second completion of the same job (uCRM reopened it) replaces the first. */
    public static function saveCompletion(\PDO $pdo, int $jobId, int $retailerId, array $gps): void
    {
        $pdo->prepare('INSERT OR REPLACE INTO job_completion_gps (job_id, retailer_id, lat, lon, accuracy_m, source, missing_reason, client_ts, captured_at)
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, datetime(\'now\'))')
            ->execute([$jobId, $retailerId, $gps['lat'], $gps['lon'], $gps['accuracy_m'], $gps['source'], $gps['missing_reason'], $gps['client_ts']]);
    }

    public static function completion(\PDO $pdo, int $jobId): ?array
    {
        $st = $pdo->prepare('SELECT * FROM job_completion_gps WHERE job_id = ?');
        $st->execute([$jobId]);
        $r = $st->fetch(\PDO::FETCH_ASSOC);
        if (!$r) return null;
        $lat = $r['lat'] === null ? null : (float)$r['lat'];
        $lon = $r['lon'] === null ? null : (float)$r['lon'];
        return [
            'job_id'         => (int)$r['job_id'],
            'retailer_id'    => (int)$r['retailer_id'],
            'lat'            => $lat,
            'lon'            => $lon,
            'accuracy_m'     => $r['accuracy_m'] === null ? null : (float)$r['accuracy_m'],
            'source'         => (string)$r['source'],
            'missing_reason' => $r['missing_reason'],
            'client_ts'      => $r['client_ts'],
            'captured_at'    => (string)$r['captured_at'],
            'maps_url'       => ($lat !== null && $lon !== null) ? self::mapsUrl($lat, $lon) : null,
        ];
    }

    public static function mapsUrl(float $lat, float $lon): string
    {
        return 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode($lat . ',' . $lon);
    }

    /** What the job detail carries on Uganda besides the job: its photos, its completion location, and the rules. */
    public static function detailExtras(\PDO $pdo, int $jobId, array $job, array $config): array
    {
        return [
            'photos'      => self::list($pdo, $jobId),
            'completion'  => self::completion($pdo, $jobId),
            'photo_rules' => [
                'labels'      => self::LABELS,
                'required'    => self::REQUIRED,
                'enforced'    => self::photosRequired($config) && self::isInstallJob($job),
                'max_per_job' => self::MAX_PER_JOB,
            ],
        ];
    }

    private static function fail(string $m): array
    {
        return ['ok' => false, 'error' => $m, 'photo' => null, 'gps' => null];
    }
}
