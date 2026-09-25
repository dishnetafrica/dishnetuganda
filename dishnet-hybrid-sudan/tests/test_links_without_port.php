<?php
/**
 * test_links_without_port.php — the links the plugin sends, on the address a
 * browser can open (5.18.34).
 *
 * uCRM reports its own address as https://crm.dishnetuganda.com:8443/crm/.
 * 8443 is where UISP listens behind Traefik: routers connect to it, and it
 * serves a self-signed certificate, so a customer's browser shows a warning
 * instead of the page. crm_public_url replaces that address in every link --
 * for the code that could see it. The value lives in the data directory's
 * config.json, and only PluginConfig::load() merges that file. public.php
 * (every admin screen, the customer portal, the API) and about a hundred
 * other readers take the settings store's copy of kyc_config.json, which
 * never carries it. So they kept :8443 with the override correctly set --
 * DPO's return, push and test addresses on the admin screen included.
 *
 * Proved here in a plugin laid out as on the server: the plugin directory
 * with uCRM's ucrm.json, the data directory beside it, and an entry point
 * holding only the store copy, the way public.php does.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/lib/StoreInterface.php';
require_once $root . '/lib/JsonStore.php';
require_once $root . '/lib/SqliteStore.php';
require_once $root . '/lib/PluginConfig.php';

$pass = 0; $fail = 0;
function ok(string $m): void  { global $pass; $pass++; echo "  ok   $m\n"; }
function bad(string $m, string $d = ''): void { global $fail; $fail++; echo "  FAIL $m" . ($d !== '' ? "\n       $d" : '') . "\n"; }
function is_(bool $c, string $m, string $d = ''): void { $c ? ok($m) : bad($m, $d); }
function eq($got, $want, string $m): void
{
    is_($got === $want, $m, 'got:  ' . var_export($got, true) . "\n       want: " . var_export($want, true));
}

// ── The layout of the live install ──────────────────────────────────────────
$tmp     = sys_get_temp_dir() . '/dn-noport-' . getmypid();
$plugins = $tmp . '/plugins';
$pr      = $plugins . '/dishnet-hybrid-sudan';
$data    = $plugins . '/.dishnet-hybrid-sudan-data';     // where getDataDir() puts it
$vault   = $tmp . '/vault.json';
exec('rm -rf ' . escapeshellarg($tmp));
$server = null;
register_shutdown_function(static function () use ($tmp, &$server) {
    if (is_array($server)) { @proc_terminate($server['proc']); @proc_close($server['proc']); }
    exec('rm -rf ' . escapeshellarg($tmp));
});
@mkdir($pr . '/tools', 0777, true);
@mkdir($data, 0777, true);
exec('cp -R ' . escapeshellarg($root . '/lib') . ' ' . escapeshellarg($pr . '/lib'));
copy($root . '/tools/crm_url_check.php', $pr . '/tools/crm_url_check.php');
// This process loads config too (section D); keep it off every real vault.
putenv('DN_VAULT_FILE=' . $vault);

$PORTED = 'https://crm.example.test:8443';
$PUBLIC = 'https://crm.example.test';
$PLUGIN = '/crm/_plugins/dishnet-hybrid-sudan/public.php';
file_put_contents($pr . '/ucrm.json', json_encode([
    'ucrmPublicUrl'   => $PORTED . '/crm/',
    'ucrmLocalUrl'    => 'http://localhost/crm/',
    'pluginPublicUrl' => $PORTED . $PLUGIN,
]));

// The entry point. It picks $dataDir the way its mode says, then holds ONLY
// the store copy it is given on the command line.
file_put_contents($pr . '/entry.php', <<<'PHP'
<?php
$mode = $argv[1];                             // global | env | discover
if ($mode === 'global') $dataDir = $argv[2];  // what public.php and every cron do
require __DIR__ . '/lib/bootstrap_data.php';
require __DIR__ . '/lib/crm_url.php';
require __DIR__ . '/lib/DpoBootstrap.php';
$c = json_decode($argv[3], true);
$t = $c + ['dpo_environment' => 'test', 'dpo_test_link_key' => str_repeat('k', 32)];
echo json_encode([
    'web'     => dn_crm_web($c),
    'client'  => dn_crm_link($c, 'client/7'),
    'public'  => dn_plugin_public($c),
    'file'    => dn_plugin_file($c, 'webhook.php'),
    'return'  => DpoBootstrap::returnUrl($c),
    'back'    => DpoBootstrap::backUrl($c),
    'push'    => DpoBootstrap::pushUrl($c),
    'test'    => DpoBootstrap::testLinkUrl($t),
    'wrapped' => dn_with_override('https://crm.example.test:8443/crm/client-zone/invoices/5/pay', $c),
    'none'    => dn_crm_web(null),
    'bare'    => dn_crm_web(),
]);
PHP);

/** Run a PHP file of the copied plugin with a clean environment of our choosing. */
function dn_child(string $cmd, array $env): array
{
    $set = '';
    foreach ($env as $k => $v) $set .= ' ' . $k . '=' . escapeshellarg((string)$v);
    // No inherited data directory, and no proxy: the probes below are local.
    $full = 'env -u DN_DATA_DIR -u https_proxy -u HTTPS_PROXY -u http_proxy -u HTTP_PROXY'
          . $set . ' ' . $cmd . ' 2>&1';
    $out = []; $code = 0;
    exec($full, $out, $code);
    return [$code, implode("\n", $out)];
}

// A realistic store copy: settings the admin screens wrote, and no override.
$STORE = ['crm_base_url' => '', 'crm_auth_token' => '', 'commission_rate' => 5];

$entry = static function (string $mode, array $cfg, array $env = [], string $dir = '')
    use ($pr, $vault, $data): array {
    [$code, $out] = dn_child('php ' . escapeshellarg($pr . '/entry.php') . ' ' . escapeshellarg($mode)
        . ' ' . escapeshellarg($dir !== '' ? $dir : $data) . ' ' . escapeshellarg((string)json_encode($cfg)),
        $env + ['DN_VAULT_FILE' => $vault]);
    $j = json_decode($out, true);
    return is_array($j) ? $j : ['_raw' => $out, '_code' => $code];
};
$setCfg = static function (string $dir, ?string $v): void {
    $f = $dir . '/config.json';
    $c = is_file($f) ? (array)json_decode((string)file_get_contents($f), true) : [];
    if ($v === null) unset($c['crm_public_url']); else $c['crm_public_url'] = $v;
    file_put_contents($f, json_encode($c));
};
$LINKS = ['web', 'client', 'public', 'file', 'return', 'back', 'push', 'test', 'wrapped'];

// ── A. The defect: a store copy without the key ─────────────────────────────
echo "\nA. An entry point holding only the store copy builds every link without :8443\n";
$setCfg($data, $PUBLIC);
foreach (['global' => [], 'env' => ['DN_DATA_DIR' => $data], 'discover' => []] as $mode => $env) {
    $r = $entry($mode, $STORE, $env);
    $ported = array_filter($LINKS, static fn($k) => strpos((string)($r[$k] ?? ':8443'), ':8443') !== false);
    is_($ported === [], "data directory from $mode: no link carries :8443",
        'still ported: ' . implode(', ', $ported) . ' ' . json_encode($r));
}
$r = $entry('global', $STORE);
eq($r['web'] ?? null, $PUBLIC, 'the CRM home');
eq($r['client'] ?? null, $PUBLIC . '/crm/client/7', 'a "View in CRM" link');
eq($r['public'] ?? null, $PUBLIC . $PLUGIN, "the plugin's public.php, path intact");
eq($r['return'] ?? null, $PUBLIC . $PLUGIN . '?page=dpo_return',
   "DPO's return address — where a customer lands after paying");
eq($r['push'] ?? null, $PUBLIC . $PLUGIN . '?page=dpo_push',
   "DPO's push address — what DPO registers on the account");
is_(strpos((string)($r['test'] ?? ''), $PUBLIC . $PLUGIN . '?page=dpo_test&k=') === 0,
    "the test link for DPO's reviewer", (string)($r['test'] ?? ''));
eq($r['wrapped'] ?? null, $PUBLIC . '/crm/client-zone/invoices/5/pay',
   'a link built elsewhere and passed through dn_with_override()');
eq($r['none'] ?? null, $PORTED, 'no config at all is left alone: those callers talk to a sibling plugin');
eq($r['bare'] ?? null, $PORTED, 'and so is a call with no argument');
is_(!is_file($vault), 'building links wrote nothing — not even the vault',
    'a helper that refreshes the vault on every link writes a file per link on busy pages');

// ── B. The controls ─────────────────────────────────────────────────────────
echo "\nB. What it must not change\n";
$setCfg($data, null);
$r = $entry('global', $STORE);
eq($r['public'] ?? null, $PORTED . $PLUGIN,
   'with no override anywhere, links are exactly what uCRM reports (the Sudan install)');
eq($r['web'] ?? null, $PORTED, 'the CRM home too — so section A is not passing by accident');
$setCfg($data, $PUBLIC);
$r = $entry('global', $STORE + ['crm_public_url' => 'https://other.example.test']);
eq($r['web'] ?? null, 'https://other.example.test', 'a value in the caller\'s own config wins');
$data2 = $plugins . '/elsewhere';
@mkdir($data2, 0777, true);
$setCfg($data2, 'https://two.example.test');
$r = $entry('global', $STORE, [], $data2);
eq($r['web'] ?? null, 'https://two.example.test',
   'the entry point\'s own data directory is read, not a guessed one');
$setCfg($data, 'crm.example.test');
$r = $entry('global', $STORE);
eq($r['web'] ?? null, $PORTED, 'an unusable value is ignored, never turned into a link to nowhere');
$setCfg($data, $PUBLIC);

// ── C. A re-install that loses config.json ──────────────────────────────────
echo "\nC. The setting survives a re-install\n";
$boot = $pr . '/boot.php';
file_put_contents($boot, '<?php require __DIR__ . "/lib/PluginConfig.php"; '
    . 'PluginConfig::load(__DIR__, $argv[1]);');
dn_child('php ' . escapeshellarg($boot) . ' ' . escapeshellarg($data), ['DN_VAULT_FILE' => $vault]);
$v = json_decode((string)@file_get_contents($vault), true);
eq($v['config']['crm_public_url'] ?? null, $PUBLIC, 'the first ordinary config load copies it into the vault');
$setCfg($data, null);
$r = $entry('global', $STORE);
eq($r['public'] ?? null, $PUBLIC . $PLUGIN, 'config.json without it: the vault still supplies it');
$setCfg($data, $PUBLIC);

// ── D. tools/crm_url_check.php ──────────────────────────────────────────────
echo "\nD. The check tool\n";
// A stand-in for UISP's own web server: a bare /crm gets the redirect nginx
// sends, carrying its own listening port; /crm/ and /crm/login do not.
file_put_contents($tmp . '/fake_uisp.php', <<<'PHP'
<?php
$p = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$host = explode(':', (string)($_SERVER['HTTP_HOST'] ?? 'localhost'))[0];
if ($p === '/crm')       { header('Location: https://' . $host . ':8443/crm/', true, 301); exit; }
if ($p === '/crm/')      { header('Location: https://crm.example.test/crm/login', true, 302); exit; }
if ($p === '/crm/login') { echo 'sign in'; exit; }
http_response_code(404);
PHP);
$sock = stream_socket_server('tcp://127.0.0.1:0');
$port = (int)substr((string)stream_socket_get_name($sock, false), strlen('127.0.0.1:'));
fclose($sock);
$proc = proc_open(['php', '-S', '127.0.0.1:' . $port, $tmp . '/fake_uisp.php'],
    [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
$server = ['proc' => $proc];
for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) usleep(100000);
$LOCAL = 'http://127.0.0.1:' . $port;

$tdata = $plugins . '/.tool-data';
@mkdir($tdata, 0777, true);
// The store first: a NEW store imports every *.json beside it and renames it.
$st = SqliteStore::create($tdata);
$st->save('kyc_config.json', ['commission_rate' => 5, 'crm_public_url' => 'https://stale.example.test']);
file_put_contents($tdata . '/kyc_config.json',
    json_encode(['something_else' => 'kept', 'crm_public_url' => 'https://stale.example.test']));
file_put_contents($tdata . '/config.json', json_encode(['crm_public_url' => $LOCAL]));
$tool = static function (array $args, array $env = []) use ($pr, $tdata, $vault): array {
    return dn_child('php ' . escapeshellarg($pr . '/tools/crm_url_check.php')
        . implode('', array_map(static fn($a) => ' ' . escapeshellarg($a), $args)),
        $env + ['DN_DATA_DIR' => $tdata, 'DN_VAULT_FILE' => $vault]);
};
@unlink($vault);

[$c, $o] = $tool([]);
is_(strpos($o, 'kyc_config.json holds a DIFFERENT value') !== false
    && strpos($o, 'settings store holds a DIFFERENT value') !== false,
    'the report finds both stale copies that disagree with config.json', $o);

[$c, $o] = $tool(['--set', $LOCAL]);
eq($c, 0, '--set succeeds');
is_(strpos($o, 'Removed an older copy from kyc_config.json and the settings store') !== false,
    'and says it removed both older copies', $o);
$cfgNow = json_decode((string)file_get_contents($tdata . '/config.json'), true);
eq($cfgNow['crm_public_url'] ?? null, $LOCAL, 'config.json holds it');
$v = json_decode((string)@file_get_contents($vault), true);
eq($v['config']['crm_public_url'] ?? null, $LOCAL, 'the vault holds it');
$kyc = json_decode((string)file_get_contents($tdata . '/kyc_config.json'), true);
is_(!array_key_exists('crm_public_url', $kyc) && ($kyc['something_else'] ?? '') === 'kept',
    'kyc_config.json lost the stale copy and nothing else', json_encode($kyc));
$sc = SqliteStore::create($tdata)->load('kyc_config.json');
is_(is_array($sc) && !array_key_exists('crm_public_url', $sc) && ($sc['commission_rate'] ?? 0) === 5,
    'the settings store lost it too, and kept the rest', json_encode($sc));

[$c, $o] = $tool([]);
is_(preg_match('/config\.json\s+' . preg_quote($LOCAL, '/') . '/', $o) === 1
    && preg_match('/vault\s+' . preg_quote($LOCAL, '/') . '/', $o) === 1,
    'the report shows where it is kept', $o);
is_(substr_count($o, $LOCAL . $PLUGIN) >= 2,
    'the crons and the screens build the same public.php', $o);
is_(strpos($o, 'DIFFERENT') === false, 'and no copy disagrees any more', $o);
is_(preg_match('#' . preg_quote($LOCAL, '#') . '/crm\s+301\s+->\s+https://127\.0\.0\.1:8443/crm/#', $o) === 1,
    'the bare /crm is shown redirecting to :8443 — the website\'s old Customer Login', $o);
is_(strpos($o, 'the bare /crm redirects to port 8443') !== false && strpos($o, '301 is PERMANENT') !== false,
    'named as the problem, with the warning that browsers keep a 301', $o);
is_(strpos($o, 'with the slash redirects to port') === false
    && preg_match('#' . preg_quote($LOCAL, '#') . '/crm/login\s+200#', $o) === 1,
    '/crm/ and /crm/login are not flagged, and the sign-in page answers', $o);

[$c, $o] = $tool(['--clear']);
eq($c, 0, '--clear succeeds');
$cfgNow = json_decode((string)file_get_contents($tdata . '/config.json'), true);
$v = json_decode((string)@file_get_contents($vault), true);
is_(!array_key_exists('crm_public_url', $cfgNow) && !array_key_exists('crm_public_url', $v['config'] ?? []),
    'and removes it from config.json and the vault alike',
    'left in the vault, the next load would quietly put it back');
[$c, $o] = $tool([]);
is_(strpos($o, '(none — following uCRM)') !== false && strpos($o, $PORTED . $PLUGIN) !== false,
    'after which links follow uCRM again', $o);

$noVault = $tmp . '/no-such-dir/vault.json';
[$c, $o] = $tool(['--set', $LOCAL], ['DN_VAULT_FILE' => $noVault]);
is_($c === 1 && strpos($o, 'the vault is NOT') !== false,
    'a vault that cannot be written is reported, not passed over', $o);
[$c, $o] = $tool([], ['DN_VAULT_FILE' => $noVault]);
is_(strpos($o, 'not in the vault yet') !== false, 'and the report says a re-install would lose it', $o);

// ── E. Nothing builds a link past the helpers ───────────────────────────────
echo "\nE. Every address builder goes through the helpers\n";
/**
 * Lines that read uCRM's raw address or the request's host, with no
 * dn_with_override() nearby. Each allowed one names why it is not a link.
 */
function dn_bypasses(string $rel, string $src, array $allow): array
{
    $hits  = [];
    $lines = explode("\n", $src);
    foreach ($lines as $i => $line) {
        if (!preg_match('/\[\'(ucrmPublicUrl|pluginPublicUrl)\'\]|\$_SERVER\[[\'"]HTTP_HOST[\'"]\]/', $line)) continue;
        foreach ($allow[$rel] ?? [] as $needle => $why) {
            if (strpos($line, $needle) !== false) continue 2;
        }
        $window = implode("\n", array_slice($lines, max(0, $i - 3), 16));
        if (strpos($window, 'dn_with_override(') !== false) continue;
        $hits[] = $rel . ':' . ($i + 1) . '  ' . trim($line);
    }
    return $hits;
}
$ALLOW = [
    'lib/crm_url.php'              => ["dn_ucrm_json()['ucrmPublicUrl']" => 'the resolver itself'],
    'lib/CrmApiClient.php'         => ["\$ucrmConfig['ucrmPublicUrl']" => 'API base, local address first'],
    'lib/MailService.php'          => ["\$ucrm['ucrmPublicUrl']" => 'reads uCRM mail settings over its API'],
    'lib/DailyReportService.php'   => ["\$ucrm['ucrmPublicUrl']" => 'reads uCRM settings over its API'],
    'lib/StarlinkBlockService.php' => ["\$ucrmJson['ucrmPublicUrl']" => 'server call to the data-report plugin'],
    'lib/StarlinkBlockBridge.php'  => ["\$ucrmJson['ucrmPublicUrl']" => 'wrapped in dn_with_override() at the end of the method; test_block_chain'],
    'lib/wa_webhook_url.php'       => ["\$_SERVER['HTTP_HOST']" => 'last resort, only when uCRM reports no public URL'],
    // 5.18.37: the routes.php "API base for a status read" exception went with the
    // ?page=crm_debug block it sat in (tools/crm_debug.php replaces it, under tools/).
    'includes/routes.php'          => ["\$host      = \$_SERVER['HTTP_HOST']" => 'PWA manifest: must match the page that asked',
                                       "\$httpsUrl = 'https://'" => 'upgrades the request the browser already made'],
    'includes/api/api_crm_sync.php' => ["\$host = \$_SERVER['HTTP_HOST']" => 'same-origin check on the referer'],
    // Phase 2: the customer session compares the Origin header with the request host before a cookie may
    // authenticate a POST. A comparison, not an address: no link is built from it.
    'lib/CustomerSession.php'       => ["\$reqHost = strtolower((string)(\$_SERVER['HTTP_HOST']" => 'same-origin check on the Origin header of a cookie-authenticated request; no link is built'],
    'production-preflight.php'     => ["\$ucrmJson['pluginPublicUrl']" => 'probes the internal port on purpose'],
    'tabs/admin/settings.php'      => ["\$_emUcrm['ucrmPublicUrl']" => 'API base for the e-mail settings read'],
    'tabs/engage/wa_ai_setup.php'  => ["\$_ucrmJson['pluginPublicUrl']" => 'shown as a hint beside its port-less form'],
    'tabs/customer_app/portal_data.php' => ["\$_uj['ucrmPublicUrl']" => 'normalised, then dn_with_override() below'],
];
$hits = []; $seen = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    $rel = substr($f->getPathname(), strlen($root) + 1);
    if (substr($rel, -4) !== '.php') continue;
    if (preg_match('#^(tests|tools|docs|vendor|dishnet-mikrotik-control-plane)/#', $rel)) continue;
    $src = (string)file_get_contents($f->getPathname());
    foreach (array_keys($ALLOW[$rel] ?? []) as $needle) if (strpos($src, $needle) !== false) $seen[$rel . '|' . $needle] = true;
    $hits = array_merge($hits, dn_bypasses($rel, $src, $ALLOW));
}
is_($hits === [], 'no link is built from uCRM\'s raw address or the request\'s host',
    implode("\n       ", $hits));
$stale = [];
foreach ($ALLOW as $rel => $ns) foreach (array_keys($ns) as $n) if (!isset($seen[$rel . '|' . $n])) $stale[] = "$rel: $n";
is_($stale === [], 'and every allowed exception still exists — the list cannot rot', implode('; ', $stale));
// The control on the control: the scan must see a bypass when one is there.
$planted = "<?php\n\$u = 'https://' . \$_SERVER['HTTP_HOST'] . '/crm/client/1';\n";
eq(count(dn_bypasses('tabs/x.php', $planted, $ALLOW)), 1, 'a planted bypass is caught');
eq(count(dn_bypasses('tabs/x.php', str_replace("'https://' . \$_SERVER['HTTP_HOST'] . '/crm/client/1'",
    "dn_with_override('https://' . \$_SERVER['HTTP_HOST'] . '/crm/client/1', \$config)", $planted), $ALLOW)), 0,
   'and the same line wrapped is not');

// ── F. One merge, two doors ─────────────────────────────────────────────────
echo "\nF. PluginConfig::read() is load() without the writes\n";
file_put_contents($tdata . '/config.json', json_encode(['crm_public_url' => ' ' . $PUBLIC . ' ', 'ai_enabled' => '1']));
file_put_contents($tdata . '/kyc_config.json', json_encode(['something_else' => 'kept', 'ai_enabled' => '0']));
@unlink($vault);
$read = PluginConfig::read($pr, $tdata);
is_(!is_file($vault), 'read() writes no vault');
$load = PluginConfig::load($pr, $tdata);
is_(is_file($vault), 'load() still does — the boot path is unchanged');
eq($read, $load, 'and both return the same configuration');
eq($read['ai_enabled'] ?? null, false, 'booleans normalised, the later file winning');
eq($read['crm_public_url'] ?? null, $PUBLIC, 'strings trimmed');

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
