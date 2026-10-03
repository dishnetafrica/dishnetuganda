<?php
declare(strict_types=1);
/**
 * test_job_photos.php — 5.18.66: labelled site photos on a uCRM job, and where the technician was when it was completed
 * (Uganda only; lib/JobPhotos.php, includes/api/api_job_photos.php, migrations/084_job_photos.sql).
 *
 * Proved through the real API against the fake uCRM, as test_job_access.php does — every person, number and job here is
 * fictitious and nothing leaves the machine:
 *   1. migration 084 — the two tables exist;
 *   2. the assignee adds a labelled photo: a real JPEG, re-encoded to 1600 px on the long edge, named by the server under
 *      uploads/job_photos/<job>/, its row carrying the hash of what is on disk; the answer carries no path;
 *   3. refusals that write nothing — an unknown label, a label shaped like a path, a text file called photo.jpg, no file,
 *      another engineer, an account whose uCRM link is not verified;
 *   4. a support leader and an admin may add photos to any job; a PNG is stored as a JPEG;
 *   5. completion: an installation is refused while kit, cable or router is missing (uCRM untouched); a fix out of range,
 *      no fix with no reason, and a one-word reason are refused; a fix is stored to seven decimals, and uCRM receives
 *      exactly the writes 5.18.65 sent — the location stays in the plugin;
 *   6. the detail carries the photos, the completion location and the rules; a job that is not an installation needs no
 *      photo and may be completed with a reason instead of a fix, which is recorded as such;
 *   7. ?page=job_photo serves the file to the person who took it, a leader and an admin, to nobody else, never for a
 *      non-numeric id, and 404 for a row that does not exist;
 *   8. a photo is removed by its taker, not by another engineer; a closed job's photos can no longer change;
 *   9. South Sudan: none of the actions exists, the detail carries no photos, and completion is 5.18.65's;
 *  10. three weakened copies — no access check on upload, no required-photo check, no reason required — are each caught.
 *
 *   php test_job_photos.php [--root=DIR] [--no-mutants]
 */
$opt  = getopt('', ['root:', 'no-mutants']);
$root = isset($opt['root']) ? rtrim((string)$opt['root'], '/') : dirname(__DIR__);
$withMutants = !isset($opt['no-mutants']);
require_once __DIR__ . '/fixtures/staff_jobs_sandbox.php';

$pass = 0; $fail = 0;
function is_(bool $c, string $m, string $d = ''): void {
    global $pass, $fail;
    if ($c) { $pass++; echo "  ok   {$m}\n"; }
    else    { $fail++; echo "  FAIL {$m}" . ($d !== '' ? "\n       {$d}" : '') . "\n"; }
}
if (!function_exists('imagecreatetruecolor')) { echo "GD is required to make the test photos\n"; exit(1); }

// ── Fixtures ─────────────────────────────────────────────────────────────────
const NOT_YOURS = 'This job is not assigned to you.';
$link = function (int $id, string $email): array {
    return ['ucrm_user_id' => $id, 'ucrm_link' => ['user_id' => $id, 'email' => $email, 'verified_at' => '2026-10-01T00:00:00Z', 'verified_by' => 1]];
};
$users = [
    '1000' => ['id' => 1000, 'username' => 'sb-admin', 'email' => 'admin@example.test', 'isActive' => true],
    '1099' => ['id' => 1099, 'username' => 'sb-tech',  'email' => 'tech@example.test',  'isActive' => true],
    '1100' => ['id' => 1100, 'username' => 'sb-other', 'email' => 'other@example.test', 'isActive' => true],
];
$clients = ['15' => ['id' => 15, 'firstName' => 'Canary', 'lastName' => 'Customer', 'street1' => 'Plot 9 Canary Road',
                     'city' => 'Kampala', 'note' => 'CANARY-CLIENT-NOTE', 'isLead' => false,
                     'contacts' => [['phone' => '+256700000915', 'email' => 'canary-client@example.test']]]];
$job = function (int $id, string $title, ?int $assignee, int $status = 1) {
    return ['id' => $id, 'title' => $title, 'description' => 'CANARY-DESCRIPTION', 'clientId' => 15,
            'client' => ['id' => 15, 'firstName' => 'Canary', 'lastName' => 'Customer'], 'date' => '2026-10-05T09:00:00+0300',
            'duration' => 60, 'status' => $status, 'address' => 'Plot 9 Canary Road', 'gpsLat' => null, 'gpsLon' => null,
            'assignedUserId' => $assignee];
};
$jobs = [
    '901' => $job(901, 'CANARY-JOB-901 Starlink installation', 1099),   // an installation: photos required
    '902' => $job(902, 'CANARY-JOB-902 Dish realignment', 1100),        // another engineer's
    '905' => $job(905, 'CANARY-JOB-905 Site check', 1099),              // not an installation
];
$seed = ['users' => $users, 'clients' => $clients, 'jobs' => $jobs, 'tasks' => []];
$accounts = function (SjSandbox $s) use ($link): void {
    $s->staff('admin', ['name' => 'Sandbox Admin', 'email' => 'admin@example.test', 'role' => 'admin', 'is_admin' => true] + $link(1000, 'admin@example.test'));
    $s->staff('lead',  ['name' => 'Sandbox Leader', 'email' => 'lead@example.test', 'role' => 'support_leader']);
    $s->staff('tech',  ['name' => 'Sandbox Tech', 'email' => 'tech@example.test', 'role' => 'support'] + $link(1099, 'tech@example.test'));
    $s->staff('other', ['name' => 'Sandbox Other', 'email' => 'other@example.test', 'role' => 'support'] + $link(1100, 'other@example.test'));
    $s->staff('stale', ['name' => 'Sandbox Stale', 'email' => 'stale@example.test', 'role' => 'support', 'ucrm_user_id' => 1099]);
    $s->staff('sales', ['name' => 'Sandbox Sales', 'email' => 'sales@example.test', 'role' => 'sales']);
};

// ── Helpers ──────────────────────────────────────────────────────────────────
$tmpDir = sys_get_temp_dir() . '/sj-photos-' . getmypid();
@mkdir($tmpDir, 0700, true);
/** A real photo-shaped JPEG of the given size. */
$jpeg = function (int $w, int $h, string $name) use ($tmpDir): string {
    $f = $tmpDir . '/' . $name;
    $im = imagecreatetruecolor($w, $h);
    imagefilledrectangle($im, 0, 0, $w, $h, imagecolorallocate($im, 30, 120, 200));
    imagefilledellipse($im, (int)($w / 2), (int)($h / 2), (int)($w / 3), (int)($h / 3), imagecolorallocate($im, 250, 250, 250));
    imagejpeg($im, $f, 90); imagedestroy($im);
    return $f;
};
$png = function (int $w, int $h, string $name) use ($tmpDir): string {
    $f = $tmpDir . '/' . $name;
    $im = imagecreatetruecolor($w, $h);
    imagealphablending($im, false); imagesavealpha($im, true);
    imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127));
    imagefilledellipse($im, (int)($w / 2), (int)($h / 2), (int)($w / 2), (int)($h / 2), imagecolorallocate($im, 200, 40, 40));
    imagepng($im, $f); imagedestroy($im);
    return $f;
};
/** The upload, as the job page sends it: multipart/form-data with a Bearer token. */
$upload = function (SjSandbox $s, string $who, $jobId, string $label, ?string $path, string $name = 'photo.jpg', string $mime = 'image/jpeg'): array {
    $fields = ['job_id' => (string)$jobId, 'label' => $label];
    if ($path !== null) $fields['photo'] = new \CURLFile($path, $mime, $name);
    $ch = curl_init("{$s->base}?page=api&action=job_photo_upload");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*',
        CURLOPT_POST => true, CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $s->tok[$who]], CURLOPT_POSTFIELDS => $fields]);
    $r = curl_exec($ch); $c = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return [$r === false ? 0 : $c, (string)$r, json_decode((string)$r, true)];
};
/** A page fetched with a web session, with its Content-Type. */
$getRaw = function (SjSandbox $s, string $who, string $qs): array {
    $ct = '';
    $ch = curl_init("{$s->base}?{$qs}");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*',
        CURLOPT_COOKIEFILE => $s->jars[$who], CURLOPT_COOKIEJAR => $s->jars[$who],
        CURLOPT_HEADERFUNCTION => function ($c, $h) use (&$ct) { if (stripos($h, 'Content-Type:') === 0) $ct = trim(substr($h, 13)); return strlen($h); }]);
    $b = curl_exec($ch); $c = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return [$b === false ? 0 : $c, (string)$b, $ct];
};
$msg    = function (array $r): string { return (string)($r[2]['message'] ?? ''); };
$rows   = function (SjSandbox $s, int $jobId): array { return $s->q('SELECT * FROM job_photos WHERE job_id = ? ORDER BY id', [$jobId]); };
$files  = function (SjSandbox $s, int $jobId): array { $d = $s->data . '/uploads/job_photos/' . $jobId; return is_dir($d) ? array_values(array_diff(scandir($d) ?: [], ['.', '..'])) : []; };
$writes = function (SjSandbox $s): array { return array_values(array_filter($s->crmDump()['requests'] ?? [], function ($r) { return ($r['method'] ?? 'GET') !== 'GET'; })); };
$crmJob = function (SjSandbox $s, int $id): array { return (array)($s->crmDump()['jobs'][(string)$id] ?? []); };
/** Nothing a refused request may change: photo rows, files on disk, uCRM writes. */
$snap = function (SjSandbox $s) use ($rows, $files, $writes): string {
    return md5(json_encode([$rows($s, 901), $rows($s, 905), $files($s, 901), $files($s, 905), count($writes($s))]));
};
$big   = $jpeg(3000, 2000, 'big.jpg');
$small = $jpeg(640, 480, 'small.jpg');
$trans = $png(900, 900, 'trans.png');
$text  = $tmpDir . '/photo.jpg'; file_put_contents($text, "this is not a photo, whatever its name says\n");

// ═════════════════════════════════════════════════════════════════════════════
$s = SjSandbox::start($root, ['tenant_profile' => 'uganda', 'timezone' => 'Africa/Kampala'], 'sjphoto');
$s->seedCrm($seed);
$accounts($s);

echo "\n1. Migration 084\n";
$tables = array_column($s->q("SELECT name FROM sqlite_master WHERE type = 'table' AND name IN ('job_photos', 'job_completion_gps') ORDER BY name"), 'name');
is_($tables === ['job_completion_gps', 'job_photos'], 'job_photos and job_completion_gps exist', json_encode($tables));

echo "\n2. The assignee adds the kit photo\n";
$r = $upload($s, 'tech', 901, 'kit', $big);
is_($r[0] === 200 && ($r[2]['status'] ?? '') === 'success', 'a 3000×2000 JPEG is accepted', $r[0] . ' ' . substr($r[1], 0, 200));
$p = $r[2]['data']['photo'] ?? [];
is_(($p['label'] ?? '') === 'kit' && ($p['label_name'] ?? '') === 'Kit / dish' && ($p['technician'] ?? '') === 'Sandbox Tech'
    && (int)($p['retailer_id'] ?? 0) === $s->ids['tech'], 'the answer names the label, the technician and the account', json_encode($p));
is_(($p['width'] ?? 0) === 1600 && ($p['height'] ?? 0) === 1067 && ($p['mime'] ?? '') === 'image/jpeg', 'stored at 1600 px on the long edge, as JPEG', json_encode([$p['width'] ?? null, $p['height'] ?? null, $p['mime'] ?? null]));
is_(!isset($p['file_rel']) && !isset($p['sha256']) && ($p['url'] ?? '') === '?page=job_photo&id=' . (int)($p['id'] ?? 0), 'the answer carries a URL by row id, never a path', json_encode(array_keys($p)));
is_(($r[2]['data']['missing_required'] ?? null) === ['cable', 'model'], 'cable and router are still missing', json_encode($r[2]['data']['missing_required'] ?? null));
$row = $rows($s, 901)[0] ?? [];
$kitId = (int)($row['id'] ?? 0);
$onDisk = $s->data . '/' . (string)($row['file_rel'] ?? '');
is_(is_file($onDisk) && preg_match('#^uploads/job_photos/901/kit-' . $s->ids['tech'] . '-\d{8}-\d{6}-[0-9a-f]{6}\.jpg$#', (string)$row['file_rel']) === 1,
    'the file is where the row says, under uploads/job_photos/901/, named by the server', (string)($row['file_rel'] ?? ''));
is_(is_file($onDisk) && hash_file('sha256', $onDisk) === ($row['sha256'] ?? '') && (int)$row['bytes'] === filesize($onDisk), 'the row carries the hash and size of what is on disk');
is_(is_file($onDisk) && filesize($onDisk) < filesize($big) && filesize($onDisk) > 1000, 'smaller than what was sent', filesize($onDisk) . ' < ' . filesize($big));
$dims = is_file($onDisk) ? (array)@getimagesize($onDisk) : [];
is_(($dims[0] ?? 0) === 1600 && ($dims[1] ?? 0) === 1067 && ($dims[2] ?? 0) === IMAGETYPE_JPEG, 'and really a 1600×1067 JPEG', json_encode(array_slice($dims, 0, 3)));
is_((int)$row['assignee_id'] === 1099 && (int)$row['ucrm_user_id'] === 1099, 'the row records the assignee and the verified link at the time', json_encode([$row['assignee_id'] ?? null, $row['ucrm_user_id'] ?? null]));

echo "\n3. Refusals that write nothing\n";
$before = $snap($s);
$r = $upload($s, 'tech', 901, 'selfie', $small);
is_($r[0] === 422 && $msg($r) === 'Unknown photo label.', 'an unknown label', $r[0] . ' ' . $msg($r));
$r = $upload($s, 'tech', 901, '../../x', $small);
is_($r[0] === 422 && $msg($r) === 'Unknown photo label.', 'a label shaped like a path', $r[0] . ' ' . $msg($r));
$r = $upload($s, 'tech', 901, 'cable', $text, 'photo.jpg', 'image/jpeg');
is_($r[0] === 422 && $msg($r) === 'That is not a JPG, PNG or WebP photo.', 'a text file called photo.jpg', $r[0] . ' ' . $msg($r));
$r = $upload($s, 'tech', 901, 'cable', null);
is_($r[0] === 422 && $msg($r) === 'No photo was received.', 'no file at all', $r[0] . ' ' . $msg($r));
$r = $upload($s, 'other', 901, 'cable', $small);
is_($r[0] === 403 && $msg($r) === NOT_YOURS, 'another engineer: refused, with the J6 words', $r[0] . ' ' . $msg($r));
$r = $upload($s, 'stale', 901, 'cable', $small);
is_($r[0] === 403 && $msg($r) === NOT_YOURS, 'an id stored without the verified picker, equal to the assignee: refused (M7)', $r[0] . ' ' . $msg($r));
$r = $upload($s, 'sales', 901, 'cable', $small);
is_($r[0] === 403, 'a sales account: refused', $r[0] . ' ' . $msg($r));
$r = $upload($s, 'tech', 0, 'cable', $small);
is_($r[0] === 422, 'no job id', $r[0] . ' ' . $msg($r));
$r = $upload($s, 'tech', 999, 'cable', $small);
is_($r[0] === 404, 'a job uCRM does not have', $r[0] . ' ' . $msg($r));
is_($snap($s) === $before, 'none of those changed a row, a file or uCRM');

echo "\n4. A leader and an admin may add photos to any job\n";
$r = $upload($s, 'lead', 901, 'cable', $small);
is_($r[0] === 200 && ($r[2]['data']['missing_required'] ?? null) === ['model'], 'the support leader adds the cable photo', $r[0] . ' ' . $msg($r));
$r = $upload($s, 'admin', 901, 'model', $trans, 'router.png', 'image/png');
is_($r[0] === 200 && ($r[2]['data']['missing_required'] ?? null) === [], 'the admin adds the router photo, a PNG', $r[0] . ' ' . $msg($r));
$p = $r[2]['data']['photo'] ?? [];
$pngRow = $s->q('SELECT * FROM job_photos WHERE id = ?', [(int)($p['id'] ?? 0)])[0] ?? [];
is_(($p['mime'] ?? '') === 'image/jpeg' && substr((string)($pngRow['file_rel'] ?? ''), -4) === '.jpg' && ($p['width'] ?? 0) === 900, 'stored as a JPEG, size kept under 1600', json_encode([$p['mime'] ?? null, $pngRow['file_rel'] ?? null, $p['width'] ?? null]));
is_(count($rows($s, 901)) === 3 && count($files($s, 901)) === 3, 'three rows, three files');

echo "\n5. Completing the installation\n";
// Take the router photo away again (through the API, so its file goes too) to prove the refusal, then put it back.
$r = $s->api('admin', 'POST', 'job_photo_delete', ['photo_id' => (int)($p['id'] ?? 0)]);
is_($r[0] === 200 && count($files($s, 901)) === 2 && count($rows($s, 901)) === 2, 'the admin removes the router photo again: row and file', $r[0] . ' ' . $msg($r));
$w0 = count($writes($s));
$r = $s->api('tech', 'POST', 'scheduling_complete', ['job_id' => 901, 'comment' => 'done', 'lat' => 0.3476, 'lon' => 32.5825, 'accuracy' => 8]);
is_($r[0] === 422 && $msg($r) === 'Add the required photos first: Router / model.', 'refused while the router photo is missing, naming it', $r[0] . ' ' . $msg($r));
is_(count($writes($s)) === $w0 && (int)($crmJob($s, 901)['status'] ?? -1) === 1, 'uCRM untouched: no write, the job still open');
is_($s->q('SELECT COUNT(*) AS n FROM job_completion_gps')[0]['n'] === '0' || (int)$s->q('SELECT COUNT(*) AS n FROM job_completion_gps')[0]['n'] === 0, 'and no completion location was recorded');
$r = $upload($s, 'tech', 901, 'model', $small);
is_($r[0] === 200, 'the router photo is added');
$r = $s->api('tech', 'POST', 'scheduling_complete', ['job_id' => 901, 'comment' => 'done', 'lat' => 95, 'lon' => 32.5, 'accuracy' => 8]);
is_($r[0] === 422 && $msg($r) === 'The location is out of range.', 'a fix out of range is refused', $r[0] . ' ' . $msg($r));
$r = $s->api('tech', 'POST', 'scheduling_complete', ['job_id' => 901, 'comment' => 'done', 'lat' => 'north', 'lon' => 32.5]);
is_($r[0] === 422 && $msg($r) === 'The location is not readable.', 'a fix that is not a number is refused', $r[0] . ' ' . $msg($r));
$r = $s->api('tech', 'POST', 'scheduling_complete', ['job_id' => 901, 'comment' => 'done', 'lat' => 0, 'lon' => 0]);
is_($r[0] === 422 && strpos($msg($r), '0,0') !== false, 'a fix of 0,0 is refused', $r[0] . ' ' . $msg($r));
$r = $s->api('tech', 'POST', 'scheduling_complete', ['job_id' => 901, 'comment' => 'done']);
is_($r[0] === 422 && strpos($msg($r), 'Location is required') === 0, 'no fix and no reason is refused', $r[0] . ' ' . $msg($r));
$r = $s->api('tech', 'POST', 'scheduling_complete', ['job_id' => 901, 'comment' => 'done', 'gps_missing_reason' => 'no']);
is_($r[0] === 422 && strpos($msg($r), 'Location is required') === 0, 'a two-letter reason is refused', $r[0] . ' ' . $msg($r));
is_(count($writes($s)) === $w0 && (int)($crmJob($s, 901)['status'] ?? -1) === 1, 'still nothing written to uCRM');
$r = $s->api('tech', 'POST', 'scheduling_complete', ['job_id' => 901, 'comment' => 'Service activated', 'lat' => '0.34761234', 'lon' => '32.58253456', 'accuracy' => '8.44', 'gps_client_ts' => '2026-10-03T09:12:00.000Z']);
is_($r[0] === 200 && ($r[2]['status'] ?? '') === 'success', 'with its photos and a fix the job completes', $r[0] . ' ' . substr($r[1], 0, 200));
is_((int)($crmJob($s, 901)['status'] ?? -1) === 2, 'uCRM holds the job closed');
$g = $s->q('SELECT * FROM job_completion_gps WHERE job_id = 901')[0] ?? [];
is_(abs((float)($g['lat'] ?? 0) - 0.3476123) < 1e-6 && abs((float)($g['lon'] ?? 0) - 32.5825346) < 1e-6 && (float)($g['accuracy_m'] ?? 0) === 8.4
    && ($g['source'] ?? '') === 'browser' && array_key_exists('missing_reason', $g) && $g['missing_reason'] === null && (int)($g['retailer_id'] ?? 0) === $s->ids['tech']
    && ($g['client_ts'] ?? '') === '2026-10-03T09:12:00.000Z', 'the fix is stored to seven decimals, with its accuracy and the browser\'s own time', json_encode($g));
$comments = array_values(array_filter($writes($s), function ($x) { return strpos((string)($x['path'] ?? ''), 'job-comments') !== false; }));
$all = json_encode($writes($s), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
is_(count($comments) === 1 && strpos($all, 'Service activated') !== false && count($writes($s)) === $w0 + 2,
    'uCRM got exactly what 5.18.65 sent — the status and the note — and nothing else', json_encode($comments, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
is_(strpos($all, '0.3476') === false && strpos($all, 'maps') === false, 'the location stays in the plugin: nothing of it went to uCRM', $all);
$jc = $s->q('SELECT * FROM job_completions');
is_(count($jc) === 1 && strpos(json_encode($jc), '0.3476123') !== false, 'the 5.18.65 completion record carries the same fix', substr(json_encode($jc), 0, 300));

echo "\n6. The detail, and a job that is not an installation\n";
$r = $s->api('tech', 'GET', 'scheduling_job_detail', null, '&job_id=901');
$d = $r[2]['data'] ?? [];
is_($r[0] === 200 && count($d['photos'] ?? []) === 3 && ($d['photo_rules']['enforced'] ?? null) === true && ($d['photo_rules']['required'] ?? null) === ['kit', 'cable', 'model']
    && ($d['photo_rules']['labels']['other'] ?? '') === 'Other', 'job 901: three photos, the rules enforced', json_encode([$r[0], count($d['photos'] ?? []), $d['photo_rules'] ?? null]));
is_(isset($d['completion']['maps_url']) && abs((float)$d['completion']['lat'] - 0.3476123) < 1e-6 && ($d['completion']['source'] ?? '') === 'browser', 'and the completion location', json_encode($d['completion'] ?? null));
is_(!isset($d['photos'][0]['file_rel']) && !isset($d['photos'][0]['sha256']), 'no path and no hash in the detail');
$r = $s->api('tech', 'GET', 'scheduling_job_detail', null, '&job_id=905');
$d = $r[2]['data'] ?? [];
is_($r[0] === 200 && ($d['photos'] ?? null) === [] && ($d['photo_rules']['enforced'] ?? null) === false && array_key_exists('completion', $d) && $d['completion'] === null,
    'job 905 (a site check): no photos required, none yet', json_encode([$r[0], $d['photo_rules'] ?? null]));
$r = $s->api('tech', 'GET', 'job_photos', null, '&job_id=901');
is_($r[0] === 200 && count($r[2]['data']['photos'] ?? []) === 3 && ($r[2]['data']['missing_required'] ?? null) === [], 'job_photos lists them for the assignee');
$r = $s->api('other', 'GET', 'job_photos', null, '&job_id=901');
is_($r[0] === 403 && $msg($r) === NOT_YOURS && !isset($r[2]['data']), 'and refuses another engineer with no data', $r[0] . ' ' . $r[1]);

echo "\n7. Serving a photo\n";
foreach (['tech', 'other', 'admin', 'lead'] as $who) $s->login($who, "{$who}@example.test", 'sj-password-1');
$r = $getRaw($s, 'tech', 'page=job_photo&id=' . $kitId);
is_($r[0] === 200 && strpos($r[2], 'image/jpeg') === 0 && substr($r[1], 0, 2) === "\xFF\xD8" && strlen($r[1]) === filesize($onDisk), 'the person who took it gets the JPEG', $r[0] . ' ' . $r[2] . ' ' . strlen($r[1]));
$r = $getRaw($s, 'lead', 'page=job_photo&id=' . $kitId);
is_($r[0] === 200 && substr($r[1], 0, 2) === "\xFF\xD8", 'a support leader too', (string)$r[0]);
$r = $getRaw($s, 'admin', 'page=job_photo&id=' . $kitId);
is_($r[0] === 200 && substr($r[1], 0, 2) === "\xFF\xD8", 'and an admin', (string)$r[0]);
$r = $getRaw($s, 'other', 'page=job_photo&id=' . $kitId);
is_($r[0] === 403 && strpos($r[1], "\xFF\xD8") === false, 'another engineer: 403, no image', (string)$r[0]);
$r = $getRaw($s, 'tech', 'page=job_photo&id=abc');
is_($r[0] === 400, 'a non-numeric id: 400', (string)$r[0]);
$r = $getRaw($s, 'tech', 'page=job_photo&id=999999');
is_($r[0] === 404, 'a row that does not exist: 404', (string)$r[0]);
$r = $getRaw($s, 'tech', 'page=job_photo&id=' . $kitId . '/../../etc/passwd');
is_($r[0] === 400, 'anything after the digits: 400', (string)$r[0]);

echo "\n8. Removing a photo, and a closed job\n";
$r = $upload($s, 'tech', 905, 'other', $small);
$otherId = (int)($r[2]['data']['photo']['id'] ?? 0);
is_($r[0] === 200 && $otherId > 0 && count($files($s, 905)) === 1, 'the tech adds an "other" photo to job 905');
$r = $s->api('other', 'POST', 'job_photo_delete', ['photo_id' => $otherId]);
is_($r[0] === 403 && count($files($s, 905)) === 1, 'another engineer cannot remove it', $r[0] . ' ' . $msg($r));
$r = $s->api('tech', 'POST', 'job_photo_delete', ['photo_id' => $otherId]);
is_($r[0] === 200 && ($r[2]['data']['photos'] ?? null) === [] && count($files($s, 905)) === 0 && $rows($s, 905) === [], 'its taker removes it: row and file gone', $r[0] . ' ' . $msg($r));
$r = $s->api('tech', 'POST', 'job_photo_delete', ['photo_id' => $otherId]);
is_($r[0] === 404, 'removing it again: 404', (string)$r[0]);
$r = $s->api('tech', 'POST', 'scheduling_complete', ['job_id' => 905, 'comment' => '', 'gps_missing_reason' => 'Indoors, phone had no signal']);
is_($r[0] === 200, 'job 905 completes with a reason instead of a fix, needing no photo', $r[0] . ' ' . $msg($r));
$g = $s->q('SELECT * FROM job_completion_gps WHERE job_id = 905')[0] ?? [];
is_(($g['source'] ?? '') === 'missing' && array_key_exists('lat', $g) && $g['lat'] === null && ($g['missing_reason'] ?? '') === 'Indoors, phone had no signal', 'recorded as missing, with the words', json_encode($g));
$all = json_encode($writes($s), JSON_UNESCAPED_UNICODE);
is_(strpos($all, 'no signal') === false && strpos($all, 'GPS') === false, 'and nothing of it went to uCRM');
$r = $upload($s, 'tech', 901, 'other', $small);
is_($r[0] === 409 && count($files($s, 901)) === 3, 'a closed job takes no more photos', $r[0] . ' ' . $msg($r));
$r = $s->api('tech', 'POST', 'job_photo_delete', ['photo_id' => $kitId]);
is_($r[0] === 409 && count($files($s, 901)) === 3, 'and loses none', $r[0] . ' ' . $msg($r));
$s->stop();

echo "\n9. South Sudan: none of this exists\n";
$ss = SjSandbox::start($root, ['tenant_profile' => 'south-sudan'], 'sjphotoss');
$ss->seedCrm($seed);
$ss->staff('tech', ['name' => 'Sandbox Tech', 'email' => 'tech@example.test', 'role' => 'support', 'ucrm_user_id' => 1099]);
$r = $upload($ss, 'tech', 901, 'kit', $small);
is_($r[0] === 404 && strpos($msg($r), 'Unknown API action') === 0, 'job_photo_upload is an unknown action', $r[0] . ' ' . $msg($r));
$r = $ss->api('tech', 'GET', 'job_photos', null, '&job_id=901');
is_($r[0] === 404 && strpos($msg($r), 'Unknown API action') === 0, 'so is job_photos', $r[0] . ' ' . $msg($r));
$r = $ss->api('tech', 'GET', 'scheduling_job_detail', null, '&job_id=901');
is_($r[0] === 200 && !array_key_exists('photos', $r[2]['data'] ?? []) && !array_key_exists('photo_rules', $r[2]['data'] ?? []), 'the detail carries no photos and no rules', json_encode(array_keys($r[2]['data'] ?? [])));
$r = $ss->api('tech', 'POST', 'scheduling_complete', ['job_id' => 901, 'comment' => 'done']);
is_($r[0] === 200 && (int)($crmJob($ss, 901)['status'] ?? -1) === 2, 'completion asks for no photo and no location, as in 5.18.65', $r[0] . ' ' . $msg($r));
is_($ss->q("SELECT COUNT(*) AS n FROM job_completion_gps")[0]['n'] == 0 && !is_dir($ss->data . '/uploads/job_photos'), 'and writes nothing of this');
$ss->stop();

// ── 10. Weakened copies ───────────────────────────────────────────────────────
if ($withMutants) {
    echo "\n10. Weakened copies, each caught\n";
    $mutants = [
        ['no access check on upload', 'includes/api/api_job_photos.php', '        $sjMayAct($jpCaller, $job);', '        // (weakened)',
         function (SjSandbox $m) use ($upload, $small): bool { $r = $upload($m, 'other', 901, 'kit', $small); return $r[0] === 200; }],
        ['no required-photo check', 'includes/api/api_scheduling.php', 'if ($_jpMissing) $er2(', 'if (false) $er2(',
         function (SjSandbox $m) use ($upload): bool { $r = $m->api('tech', 'POST', 'scheduling_complete', ['job_id' => 901, 'lat' => 0.35, 'lon' => 32.58]); return $r[0] === 200; }],
        ['no reason required without a fix', 'lib/JobPhotos.php', 'if ($len < self::REASON_MIN) {', 'if (false) {',
         function (SjSandbox $m) use ($upload, $small): bool {
             foreach (['kit', 'cable', 'model'] as $l) $upload($m, 'tech', 901, $l, $small);
             $r = $m->api('tech', 'POST', 'scheduling_complete', ['job_id' => 901, 'comment' => 'done']); return $r[0] === 200; }],
    ];
    foreach ($mutants as $i => [$label, $rel, $old, $new, $probe]) {
        [$tmp, $n] = sj_weakened_copy($root, $rel, $old, $new);
        if ($n !== 1) { is_(false, "weakened copy: {$label}", "anchor found {$n} times in {$rel}"); exec('rm -rf ' . escapeshellarg($tmp)); continue; }
        $m = SjSandbox::start($tmp, ['tenant_profile' => 'uganda', 'timezone' => 'Africa/Kampala'], 'sjpm' . $i);
        $m->seedCrm($seed);
        $accounts($m);
        is_($probe($m), "weakened copy caught: {$label}");
        $m->stop();
        exec('rm -rf ' . escapeshellarg($tmp));
    }
}

exec('rm -rf ' . escapeshellarg($tmpDir));
echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
