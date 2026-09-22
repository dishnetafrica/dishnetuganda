<?php
declare(strict_types=1);
/**
 * A FAKE RouterOS REST endpoint. IT IS NOT A ROUTER.
 *
 * docs/30 Artifact 13 rule 2: "A RouterOS harness, not a mock. A container
 * running a real RouterOS CHR instance... A fake MikroTik would pass while
 * the real one rejects the command."
 *
 * So be precise about what this proves and what it does not:
 *
 *   PROVES   our client's HTTP handling — auth, JSON, methods, status codes —
 *            and the delivery/confirm logic built on top of it.
 *   PROVES   nothing whatsoever about whether RouterOS accepts these paths,
 *            these payload shapes, or these values.
 *
 * The real check is tools/chr_harness.sh against a CHR instance, which has
 * not been run. See that file.
 */
$state = sys_get_temp_dir() . '/fake-ros-' . (getenv('FAKE_ROS_ID') ?: 'default') . '.json';
$load = fn(): array => is_file($state) ? (json_decode(file_get_contents($state), true) ?: []) : [];
$save = fn(array $s) => file_put_contents($state, json_encode($s));

$s = $load() ?: [
    'ip/hotspot/profile' => [['.id' => '*1', 'name' => 'hsprof1', 'use-radius' => 'no']],
    'ip/hotspot/active'  => [['.id' => '*A', 'user' => 'guest-1']],
    'system/identity'    => ['name' => 'MikroTik'],
];

$auth = $_SERVER['PHP_AUTH_USER'] ?? '';
$pass = $_SERVER['PHP_AUTH_PW'] ?? '';
$hdr  = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if ($auth === '' && str_starts_with($hdr, 'Basic ')) {
    [$auth, $pass] = array_pad(explode(':', base64_decode(substr($hdr, 6)), 2), 2, '');
}
header('Content-Type: application/json');
if ($auth !== 'dn-mgmt' || $pass !== (getenv('FAKE_ROS_PASS') ?: 'correct-horse')) {
    http_response_code(401);
    echo json_encode(['error' => 401, 'message' => 'not authorized']);
    exit;
}

$path   = ltrim(preg_replace('#^/rest/#', '', parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH)), '/');
$method = $_SERVER['REQUEST_METHOD'];
$body   = json_decode(file_get_contents('php://input') ?: '[]', true) ?: [];

// A path this fake does not know about is refused, so a test cannot pass by
// inventing an endpoint that happens to look plausible.
$known = array_keys($s) + ['ip/hotspot/active/remove' => 1];
if (!in_array($path, array_keys($s), true) && $path !== 'ip/hotspot/active/remove') {
    http_response_code(404);
    echo json_encode(['error' => 404, 'message' => 'no such command prefix']);
    exit;
}

if ($method === 'GET') { echo json_encode($s[$path]); exit; }

if ($method === 'PATCH') {
    if ($path === 'ip/hotspot/profile') {
        foreach ($s[$path] as $i => $row) { $s[$path][$i] = array_merge($row, $body); }
    } else {
        $s[$path] = array_merge(is_array($s[$path]) ? $s[$path] : [], $body);
    }
    $save($s);
    echo json_encode($s[$path]); exit;
}

if ($method === 'POST' && $path === 'ip/hotspot/active/remove') {
    $s['ip/hotspot/active'] = array_values(array_filter(
        $s['ip/hotspot/active'], fn($a) => ($a['.id'] ?? null) !== ($body['.id'] ?? null)));
    $save($s);
    http_response_code(204); exit;
}

http_response_code(405);
echo json_encode(['error' => 405, 'message' => 'method not allowed']);
