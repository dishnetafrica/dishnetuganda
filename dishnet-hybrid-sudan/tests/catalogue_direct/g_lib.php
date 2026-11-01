<?php
declare(strict_types=1);
/**
 * TEST ONLY — section G of tests/test_catalogue_direct.php (5.18.103, root docs/72 §G.5): the KYC form drawn in a child.
 *
 * cd_g_draw() includes tabs/sales/kyc_form.php in a child PHP process (QfuCtx::child) with the stubs the panel gives it —
 * h(), csrfField(), $store, $config, $dataDir, $devices, $retailer, $myWallet, and $can where the viewer may see the
 * Plans screen — and $crm the plugin's client on the 'kyc' fake, recording withTimeouts(), exactly as 5.18.102's section
 * H draws it (tests/quote_from_ucrm/H_kyc_form.php). The store's ucrm_catalogue.json is seeded first (cd_seed()). The
 * plugin's own plan and kit rows (qfu_rows()) and the 5.18.102 links (qfu_links()) are seeded on every Uganda draw, the
 * direct ones included: a direct page must draw none of them. Every name, number and price is fictitious.
 */

/**
 * The page, drawn in a child. $o: 'tree' (default: a fresh copy of the judged tree's lib, profiles and tabs), 'records'
 * (ucrm_catalogue.json, cd_seed()'s argument; null leaves the table alone), 'tenant', 'cfg', 'dir' (a data folder to
 * reuse — the page cache and the last good copy live there), 'wallet', 'links' (QfuCtx::child's), 'admin' (a viewer who
 * may see the Plans screen). Returns html, requests (the fake's log for this draw), timeouts, cache, last_good, tables,
 * errlog, dir, out, rc.
 */
function cd_g_draw(QfuCtx $c, array $o = []): array
{
    $port = $c->fake('kyc');
    $c->ctl('kyc', '/__test/set', ['log' => []]);
    $args = ['url' => "http://127.0.0.1:{$port}/api/v2.1", 'wallet' => $o['wallet'] ?? 10000000, 'admin' => !empty($o['admin'])];
    $body = cd_seed(array_key_exists('records', $o) ? $o['records'] : null) . str_replace('@@ARGS@@', var_export(json_encode($args), true), <<<'PHP'
$A = json_decode(@@ARGS@@, true);
ini_set('display_errors', '0');
require_once "$root/lib/currency.php";
if (!function_exists('h')) { function h($s): string { return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8'); } }
if (!function_exists('csrfField')) { function csrfField(): string { return '<input type="hidden" name="_csrf" value="TEST-CSRF">'; } }
if (!class_exists('CdGCrm', false)) {
    // the plugin's client, recording each withTimeouts() — the page's read must be the short one
    class CdGCrm extends CrmApiClient {
        public static $t = [];
        public function withTimeouts(int $a, int $b): CrmApiClient { self::$t[] = [$a, $b]; return parent::withTimeouts($a, $b); }
    }
}
$crm      = new CdGCrm($A['url'], 'FAKE-TEST-KEY', 'X-Auth-App-Key');
$store    = $s;
$config   = $s->load('kyc_config.json') ?? [];
$dataDir  = $dir;
$devices  = $s->load('kyc_devices.json') ?? [];
$retailer = ['api_token' => 'TEST-TOKEN', 'name' => 'Test Agent'];
$myWallet = ['balance' => (float)$A['wallet']];
if ($A['admin']) { $can = function (string $perm): bool { return in_array($perm, ['plans', 'hardware'], true); }; }
$_SESSION = [];
ob_start();
include "$root/tabs/sales/kyc_form.php";
$html = (string)ob_get_clean();
file_put_contents("$dir/page.html", $html);
$tables = $s->getPdo()->query("SELECT name FROM sqlite_master WHERE type = 'table'")->fetchAll(\PDO::FETCH_COLUMN);
return ['timeouts' => CdGCrm::$t, 'cache' => is_file("$dir/ucrm_quote_catalogue_cache.json"),
        'last_good' => is_file("$dir/ucrm_quote_catalogue_last_good.json"), 'tables' => $tables];
PHP);
    $r = $c->child($body, ['tree' => $o['tree'] ?? cd_g_tree($c), 'tenant' => $o['tenant'] ?? 'uganda',
                           'cfg' => ($o['cfg'] ?? []) + ['sales_persons' => ['Referral A'], 'fiber_install_fee' => 100]]
                          + (array_key_exists('links', $o) ? ['links' => $o['links']] : [])
                          + (isset($o['dir']) ? ['dir' => $o['dir']] : []));
    return ['html' => (string)@file_get_contents($r['dir'] . '/page.html'), 'requests' => $c->requests('kyc'),
            'timeouts' => $r['value']['timeouts'] ?? null, 'cache' => $r['value']['cache'] ?? null,
            'last_good' => $r['value']['last_good'] ?? null, 'tables' => $r['value']['tables'] ?? null,
            'errlog' => $r['errlog'], 'dir' => $r['dir'], 'out' => $r['out'], 'rc' => $r['rc']];
}

/**
 * A copy of the judged tree's lib, profiles and tabs, with the website's shop catalogue (assets/shop/catalogue.json) set
 * to a TEST one naming "Pipe Adapter (TEST)" an accessory: offered() groups the hardware by it (G-5), so the copy decides
 * which row is an accessory. The photos are not copied.
 */
function cd_g_tree(QfuCtx $c): string
{
    $t = $c->tree(['tabs']);
    @mkdir($t . '/assets/shop', 0777, true);
    file_put_contents($t . '/assets/shop/catalogue.json', json_encode(['version' => 1, 'items' => [
        ['slug' => 'pipe-adapter-test', 'kind' => 'accessory', 'name' => 'Pipe Adapter (TEST)', 'match' => ['Pipe Adapter (TEST)']],
        ['slug' => 'standard-kit-test', 'kind' => 'kit', 'name' => 'Standard Kit (TEST)', 'match' => ['Standard Kit (TEST)']],
    ]]));
    return $t;
}

/** One row of the drawn lists by data-id ('hw-row' or 'pkg-opt'): its opening tag to the next row of its list, or ''. */
function cd_g_row(string $html, string $class, string $id): string
{
    [$from, $to] = $class === 'hw-row' ? ['id="wHardwareList"', '<!-- Cart -->'] : ['id="wPackageList"', 'id="wNoPlanMsg"'];
    $a = strpos($html, $from);
    $b = $a === false ? false : strpos($html, $to, $a);
    if ($a === false || $b === false) return '';
    $list = substr($html, $a, $b - $a);
    $re = '#<div class="' . $class . '[^"]*"\s+data-id="' . preg_quote($id, '#') . '".*?(?=<div class="(?:' . $class . '|q103-group)[ "]|$)#s';
    return preg_match($re, $list, $m) ? $m[0] : '';
}

/** An attribute of a row's opening tag, as drawn (still escaped), or null. */
function cd_g_attr(string $rowHtml, string $name): ?string
{
    return preg_match('#\s' . preg_quote($name, '#') . '="([^"]*)"#', $rowHtml, $m) ? $m[1] : null;
}

/** Every data-id of a list, in the order drawn. */
function cd_g_ids(string $html, string $class): array
{
    [$from, $to] = $class === 'hw-row' ? ['id="wHardwareList"', '<!-- Cart -->'] : ['id="wPackageList"', 'id="wNoPlanMsg"'];
    $a = strpos($html, $from);
    $b = $a === false ? false : strpos($html, $to, $a);
    if ($a === false || $b === false) return [];
    preg_match_all('#<div class="' . $class . '[^"]*"\s+data-id="([^"]*)"#', substr($html, $a, $b - $a), $m);
    return $m[1];
}

/** Every input (or select / textarea) drawn with a name, as name => count. */
function cd_g_names(string $html): array
{
    preg_match_all('#<(?:input|select|textarea)\b[^>]*\bname="([^"]*)"#i', $html, $m);
    return array_count_values($m[1]);
}

/** The PHP warnings, notices and fatals a child logged. */
function cd_g_warnings(string $log): array
{
    return array_values(array_filter(explode("\n", $log), function ($l) { return (bool)preg_match('/PHP (Warning|Notice|Fatal|Parse)/', $l); }));
}

/** node and a NODE_PATH holding Playwright, or null (H_kyc_form.php's search). */
function cd_g_node(): ?array
{
    $node = trim((string)shell_exec('command -v node 2>/dev/null'));
    $nodePath = trim((string)(getenv('NODE_PATH') ?: ''));
    foreach (['/opt/node22/lib/node_modules', '/usr/lib/node_modules', '/usr/local/lib/node_modules'] as $cand) {
        if (is_dir($cand . '/playwright')) { $nodePath = $nodePath === '' ? $cand : $nodePath . ':' . $cand; break; }
    }
    if ($node === '') return null;
    exec('NODE_PATH=' . escapeshellarg($nodePath) . ' ' . escapeshellarg($node) . " -e \"require('playwright')\" 2>/dev/null", $po, $prc);
    return $prc === 0 ? ['node' => $node, 'path' => $nodePath] : null;
}
