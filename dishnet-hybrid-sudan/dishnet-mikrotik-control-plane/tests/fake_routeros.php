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

// Defaults merged UNDER any persisted state, so a state file from an earlier
// run never hides a path this fake has since learned.
$s = ($load() ?: []) + [
    'ip/hotspot/profile' => [['.id' => '*1', 'name' => 'hsprof1', 'use-radius' => 'no']],
    'ip/hotspot/active'  => [['.id' => '*A', 'user' => 'guest-1']],
    'system/identity'    => ['name' => 'MikroTik'],
    // G-C (docs/118 H8/H9): the identity and version reads. The serial the
    // fake reports is the one the tests register, unless a test says otherwise
    // — a MISMATCH is exactly what the adapter must refuse.
    'system/routerboard' => ['serial-number' => getenv('FAKE_ROS_SERIAL') ?: 'HGX8842011',
                             'model' => 'hAP ax2', 'firmware' => '7.14.3'],
    'system/resource'    => ['version' => '7.14.3 (stable)', 'board-name' => 'hAP ax2',
                             'architecture-name' => 'arm64'],
];

// Fault injection, for the adapter's failure paths (docs/118 D-10):
//   FAKE_ROS_SLOW=<seconds>   every answer waits this long (timeouts)
//   GET system/malformed      200 with a body that is not JSON
if (($slow = (int) (getenv('FAKE_ROS_SLOW') ?: 0)) > 0) { sleep($slow); }

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

if ($method === 'GET' && $path === 'system/malformed') {
    // Not JSON, on purpose, with a success status: the answer a half-broken
    // device or a proxy page gives. The adapter must not read it as a state.
    echo 'this is not json {{'; exit;
}

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
