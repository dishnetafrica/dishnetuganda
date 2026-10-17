<?php
declare(strict_types=1);
/**
 * test_kyc_photo_route.php — 5.18.95 (K1): ?page=kyc_photo still serves every stored photo to the people who may see
 * it, and only them, now that the route refuses an unexpected path instead of cleaning it and serves only a file that
 * lies inside its own photo folder.
 *
 * Proved over HTTP on the sandbox, with invented photos and invented staff; nothing leaves the machine:
 *   1. each allowed folder — kyc_photos, kyc_uploads, uploads/expenses, uploads/expense_receipts, uploads/install_photos
 *      (nested, dots in the name), uploads/job_photos, uploads/proof… — serves its photo byte for byte, with its type,
 *      to an admin, an accountant, a support leader and a field accountant;
 *   2. a field agent sees their own expense photo and no other photo (403), as before;
 *   3. a photo that is not there is 404, a folder outside the list is 400, and no sign-in means no photo;
 *   4. the route's source refuses rather than cleans, and contains by realpath (pinned, as the Bank deploy will check).
 *
 *   php test_kyc_photo_route.php [--root=DIR]
 */
$opt  = getopt('', ['root:']);
$root = isset($opt['root']) ? rtrim((string)$opt['root'], '/') : dirname(__DIR__);
require_once __DIR__ . '/fixtures/staff_jobs_sandbox.php';

$pass = 0; $fail = 0;
function is_(bool $c, string $m, string $d = ''): void {
    global $pass, $fail;
    if ($c) { $pass++; echo "  ok   {$m}\n"; }
    else    { $fail++; echo "  FAIL {$m}" . ($d !== '' ? "\n       {$d}" : '') . "\n"; }
}
if (!function_exists('imagecreatetruecolor')) { echo "GD is required to make the test photos\n"; exit(1); }

$s = SjSandbox::start($root, ['tenant_profile' => 'uganda', 'timezone' => 'Africa/Kampala'], 'sjkyc');
$s->staff('admin', ['name' => 'Sandbox Admin', 'email' => 'admin@example.test', 'role' => 'admin', 'is_admin' => true]);
$s->staff('acct',  ['name' => 'Sandbox Accountant', 'email' => 'acct@example.test', 'role' => 'accountant']);
$s->staff('lead',  ['name' => 'Sandbox Leader', 'email' => 'lead@example.test', 'role' => 'support_leader']);
$s->staff('facct', ['name' => 'Sandbox Field Accountant', 'email' => 'facct@example.test', 'role' => 'field_accountant']);
$agentId = $s->staff('agent', ['name' => 'Sandbox Agent', 'email' => 'agent@example.test', 'role' => 'field_agent']);
foreach (['admin', 'acct', 'lead', 'facct', 'agent'] as $who) $s->login($who, $who . '@example.test', 'sj-password-1');

/** A small real JPEG, distinct per call, written under the sandbox's data folder. */
$photo = function (string $rel, int $seed) use ($s): string {
    $im = imagecreatetruecolor(8, 8);
    imagefill($im, 0, 0, imagecolorallocate($im, $seed % 256, (7 * $seed) % 256, (13 * $seed) % 256));
    ob_start(); imagejpeg($im, null, 80); $bytes = (string)ob_get_clean(); imagedestroy($im);
    @mkdir(dirname($s->data . '/' . $rel), 0700, true);
    file_put_contents($s->data . '/' . $rel, $bytes);
    return $bytes;
};
$get = function (string $who, string $f) use ($s): array {
    return $s->http('GET', "{$s->base}?page=kyc_photo&f=" . rawurlencode($f), null, [], $s->jars[$who] ?? null);
};

$photos = [
    'kyc_photos/app12_customer_photo_1.jpg'          => 11,
    'kyc_uploads/app12_id_front_2.jpg'               => 12,
    'uploads/expenses/exp-' . $agentId . '-1730000000.jpg' => 13,
    'uploads/expense_receipts/rcpt-77-1730000001.jpg' => 14,
    'uploads/install_photos/901/kit.v2.final.jpg'    => 15,
    'uploads/job_photos/901/cable-1730000002.jpg'    => 16,
    'uploads/proof_of_payment/pay-55.jpg'            => 17,
];
$bytes = [];
foreach ($photos as $rel => $seed) $bytes[$rel] = $photo($rel, $seed);
$photo('uploads/expenses/exp-' . ($agentId + 1) . '-1730000003.jpg', 18);   // another agent's expense photo

echo "1. Every allowed folder, every privileged role\n";
foreach (['admin', 'acct', 'lead', 'facct'] as $who) {
    foreach ($photos as $rel => $_) {
        $r = $get($who, $rel);
        is_($r[0] === 200 && $r[1] === $bytes[$rel], "{$who}: {$rel} is served byte for byte", $r[0] . ' ' . strlen($r[1]));
    }
}
$ch = curl_init("{$s->base}?page=kyc_photo&f=" . rawurlencode('uploads/install_photos/901/kit.v2.final.jpg'));
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*', CURLOPT_COOKIEFILE => $s->jars['admin']]);
curl_exec($ch);
is_(strpos((string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE), 'image/jpeg') === 0, 'a photo keeps its image/jpeg type', (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE));
curl_close($ch);

echo "\n2. A field agent: their own expense photo, nothing else\n";
$own = 'uploads/expenses/exp-' . $agentId . '-1730000000.jpg';
$r = $get('agent', $own);
is_($r[0] === 200 && $r[1] === $bytes[$own], 'their own expense photo is served', (string)$r[0]);
$r = $get('agent', 'uploads/expenses/exp-' . ($agentId + 1) . '-1730000003.jpg');
is_($r[0] === 403, "another agent's expense photo: 403", (string)$r[0]);
$r = $get('agent', 'kyc_photos/app12_customer_photo_1.jpg');
is_($r[0] === 403, 'a KYC photo: 403', (string)$r[0]);

echo "\n3. What is not a stored photo\n";
$r = $get('admin', 'kyc_photos/no-such-photo.jpg');
is_($r[0] === 404, 'a photo that is not there: 404', (string)$r[0]);
$r = $get('admin', 'uploads/wa/x.jpg');
is_($r[0] === 400, 'a folder outside the list: 400', (string)$r[0]);
$r = $s->http('GET', "{$s->base}?page=kyc_photo&f=" . rawurlencode('kyc_photos/app12_customer_photo_1.jpg'));
is_($r[1] !== $bytes['kyc_photos/app12_customer_photo_1.jpg'], 'no sign-in: no photo', (string)$r[0]);
$s->stop();

echo "\n4. The route's source\n";
$src = (string)file_get_contents($root . '/includes/routes.php');
$a = strpos($src, "if (\$page === 'kyc_photo') {");
$b = $a === false ? false : strpos($src, "\nif (\$page === ", $a + 10);
$blk = ($a !== false && $b !== false) ? substr($src, $a, $b - $a) : '';
is_($blk !== '', 'the kyc_photo route is found');
is_(strpos($blk, 'str_replace(') === false, 'it no longer cleans the path with str_replace');
is_(strpos($blk, 'realpath(') !== false && strpos($blk, 'DIRECTORY_SEPARATOR') !== false && strpos($blk, 'is_file(') !== false,
    'it serves only a file that realpath() finds inside its folder');
is_(strpos($blk, "in_array('..', \$fSeg, true)") !== false && strpos($blk, 'is_string($f)') !== false,
    'it refuses unexpected segments and non-string input');

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
