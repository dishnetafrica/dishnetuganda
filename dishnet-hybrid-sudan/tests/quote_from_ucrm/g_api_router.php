<?php
/**
 * A php -S router for section G of tests/test_quote_from_ucrm.php (5.18.102, docs/71 §K.3) — TEST ONLY.
 *
 * Each request names its case in the X-Qfu-Case header: a JSON file holding the plugin tree to run (root), the
 * configuration, the data directory, the store's rows (a JSON file the store reads and writes), whether the caller is an
 * administrator, the action, and the fake uCRM's API base. It then runs the tree's includes/api/api_products_admin.php
 * as public.php's API runs it, with $er2/$ok2 as includes/api_handlers.php defines them. Served only on 127.0.0.1.
 */
error_reporting(E_ALL & ~E_DEPRECATED);
$case = json_decode((string)@file_get_contents((string)($_SERVER['HTTP_X_QFU_CASE'] ?? '')), true);
if (!is_array($case)) { http_response_code(500); echo json_encode(['status' => 'no case']); return true; }

final class QfuGApiStore
{
    private string $file;
    private array $rows;
    public function __construct(string $file) { $this->file = $file; $this->rows = json_decode((string)@file_get_contents($file), true) ?: []; }
    public function load($f) { return $this->rows[$f] ?? []; }
    public function save($f, $d) { $this->rows[$f] = $d; file_put_contents($this->file, json_encode($this->rows)); return true; }
    public function getDataDir() { return $GLOBALS['dataDir']; }
}

$root    = (string)$case['root'];
$config  = (array)$case['cfg'];
$dataDir = (string)$case['data'];
$store   = new QfuGApiStore((string)$case['store']);
$act     = (string)$case['act'];
$met     = (string)($_SERVER['REQUEST_METHOD'] ?? 'GET');
$isAdmin = !empty($case['admin']);
require_once $root . '/lib/CrmApiClient.php';
$crm = new CrmApiClient((string)$case['crm'], 'TEST-TOKEN', 'x-auth-token');
ob_start();
$ok2 = function ($d, $m = 'OK', $c = 200) { ob_end_clean(); http_response_code($c); echo json_encode(['status' => 'success', 'message' => $m, 'data' => $d]); exit; };
$er2 = function ($m, $c = 400) { ob_end_clean(); http_response_code($c); echo json_encode(['status' => 'error', 'message' => $m]); exit; };
require $root . '/includes/api/api_products_admin.php';
ob_end_clean();
echo json_encode(['status' => 'fallthrough']);
return true;
