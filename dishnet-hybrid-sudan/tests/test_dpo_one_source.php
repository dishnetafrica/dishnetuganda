<?php
/**
 * test_dpo_one_source.php — every DPO door reads what the DPO Pay screen saved
 * (5.18.36).
 *
 * On the server, 25 September 2026: the token was entered on the DPO Pay
 * screen and saved, and tools/dpo_probe.php still said "No company token is
 * set". The screen saves into the settings store and backs up to the vault.
 * ConfigVault::store() refuses the whole batch for one key it does not know,
 * and the screen passed two it did not (dpo_currencies,
 * dpo_unpayable_statuses): no save made on the screen had ever reached the
 * vault. The probe, the reviewer's test page, the return page, DPO's push and
 * the reconcile cron read only the files and the vault, so for them the token
 * did not exist — and the screen reported the failure in green.
 *
 * The screen's own test only found 'ConfigVault::store' in its source. This
 * one presses Save, then rebuilds the server's state and asks each door.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/lib/StoreInterface.php';
require_once $root . '/lib/JsonStore.php';
require_once $root . '/lib/SqliteStore.php';

$pass = 0; $fail = 0;
function ok(string $m): void  { global $pass; $pass++; echo "  ok   $m\n"; }
function bad(string $m, string $d = ''): void { global $fail; $fail++; echo "  FAIL $m" . ($d !== '' ? "\n       $d" : '') . "\n"; }
function is_(bool $c, string $m, string $d = ''): void { $c ? ok($m) : bad($m, $d); }
function eq($got, $want, string $m): void
{
    is_($got === $want, $m, 'got:  ' . var_export($got, true) . "\n       want: " . var_export($want, true));
}

$tmp = sys_get_temp_dir() . '/dn-dpo-one-' . getmypid();
exec('rm -rf ' . escapeshellarg($tmp));
@mkdir($tmp, 0777, true);
$fake = null;
register_shutdown_function(static function () use ($tmp, &$fake) {
    if (is_resource($fake)) { @proc_terminate($fake); @proc_close($fake); }
    exec('rm -rf ' . escapeshellarg($tmp));
});

$TOKEN = '7E57A0B1-0000-4000-8000-00000000000A';   // shaped like DPO's; the fake knows 7E57…
$STALE = 'ABCDEF01-2345-4678-9ABC-DEF012345678';   // shaped like one; the fake answers 802

/** A data directory with a settings store, made before anything is written beside it. */
$freshDir = static function (string $name) use ($tmp): string {
    $d = $tmp . '/' . $name;
    @mkdir($d, 0777, true);
    SqliteStore::create($d);   // first: a NEW store imports and renames every *.json beside it
    return $d;
};
/**
 * PHP code run in its own process, with an environment of our choosing.
 * stdout is the answer; stderr, where the vault logs, is kept apart.
 */
$child = static function (string $code, array $env) use ($tmp, $root): array {
    $f = $tmp . '/child_' . md5($code . json_encode($env)) . '.php';
    file_put_contents($f, $code);
    $env += ['T_ROOT' => $root];
    $p = proc_open(['php', $f], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root, $env + getenv());
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    return [proc_close($p), $out, $err];
};
$vaultOf = static function (string $file): array {
    $d = json_decode((string)@file_get_contents($file), true);
    return is_array($d['config'] ?? null) ? $d['config'] : [];
};

// ── A. The DPO Pay screen's Save, pressed ───────────────────────────────────
echo "\nA. Save settings on the DPO Pay screen reaches the vault\n";
$SCREEN = <<<'PHP'
<?php
error_reporting(E_ALL & ~E_DEPRECATED);
$root    = getenv('T_ROOT');
$dataDir = getenv('T_DATA');
require $root . '/lib/StoreInterface.php';
require $root . '/lib/JsonStore.php';
require $root . '/lib/SqliteStore.php';
$store    = SqliteStore::create($dataDir);
$retailer = ['is_admin' => true];
function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function csrfField() { return ''; }
function csrfCheck() { return true; }
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = json_decode((string)getenv('T_POST'), true);
$_GET  = [];
ob_start();
include $root . '/tabs/admin/dpo_payments.php';
$html = (string)ob_get_clean();
$notes = [];
if (preg_match_all('#<div class="dp-note (\w+)">(.*?)</div>#s', $html, $m, PREG_SET_ORDER)) {
    foreach ($m as $x) $notes[] = [$x[1], html_entity_decode(strip_tags($x[2]))];
}
echo json_encode(['notes' => $notes, 'token_in_page' => strpos($html, (string)getenv('T_TOKEN')) !== false]);
PHP;
$save = static function (string $dir, array $post, string $vault) use ($child, $SCREEN, $TOKEN): array {
    [$code, $out, $err] = $child($SCREEN, ['T_DATA' => $dir, 'DN_VAULT_FILE' => $vault,
        'T_POST' => json_encode($post), 'T_TOKEN' => $TOKEN]);
    $j = json_decode($out, true);
    return is_array($j) ? $j : ['notes' => [], '_raw' => $out . $err, '_code' => $code];
};
$noteFor = static function (array $r, string $needle): ?array {
    foreach ($r['notes'] ?? [] as $n) if (strpos($n[1], $needle) !== false) return $n;
    return null;
};
$FORM = ['dp_action' => 'save', 'dpo_enabled' => '1', 'dpo_environment' => 'test',
         'dpo_company_token' => $TOKEN, 'dpo_service_type' => '5525', 'dpo_company_acc_ref' => '',
         'dpo_payment_method_uuid' => 'pm-uuid-1', 'dpo_currencies' => 'UGX',
         'dpo_unpayable_statuses' => '', 'dpo_ptl' => '30', 'dpo_test_clients' => '12'];

$dA = $freshDir('screen');
$vA = $tmp . '/vault-screen.json';
$r  = $save($dA, $FORM, $vA);
$n  = $noteFor($r, 'Saved');
is_($n !== null && $n[1] === 'Saved.' && $n[0] === 'green', 'the screen says "Saved.", in green', json_encode($r));
$v = $vaultOf($vA);
eq($v['dpo_company_token'] ?? null, $TOKEN, 'the vault holds the token');
is_(($v['dpo_currencies'] ?? null) === 'UGX' && ($v['dpo_environment'] ?? null) === 'test'
    && ($v['dpo_test_clients'] ?? null) === '12' && ($v['dpo_enabled'] ?? null) === '1',
    'and the rest of the form, the two keys that used to refuse the batch included', json_encode(array_keys($v)));
$sc = SqliteStore::create($dA)->load('kyc_config.json');
is_(($sc['dpo_company_token'] ?? null) === $TOKEN && ($sc['dpo_currencies'] ?? null) === 'UGX',
    'the settings store holds them too');
is_(empty($r['token_in_page']), 'and the page never shows the token');

// The server's own history: the token saved while the vault could not take
// it — here, a vault in a directory that does not exist; there, the refused
// batch — and then the screen saved again with the token field left blank,
// which is what anyone does once the vault works.
$dS = $freshDir('screen-then-fixed');
$r  = $save($dS, $FORM, $tmp . '/no-such-dir/vault.json');
$n  = $noteFor($r, 'NOT vaulted');
is_($n !== null && $n[0] === 'red', 'a vault that cannot be written: "NOT vaulted", in red, not green', json_encode($r));
$vS = $tmp . '/vault-then-fixed.json';
$r  = $save($dS, ['dpo_company_token' => ''] + $FORM, $vS);
is_($noteFor($r, 'Saved.') !== null, 'saved again, the token field left blank: "Saved."', json_encode($r));
eq($vaultOf($vS)['dpo_company_token'] ?? null, $TOKEN,
   'and the vault now holds the token saved the first time: the settings in effect are backed up, not just the fields posted');

// ── B. The server's state: saved on the screen, never vaulted ───────────────
echo "\nB. Every other door reads what the screen saved\n";
$dB = $freshDir('server');
$vB = $tmp . '/vault-server.json';
SqliteStore::create($dB)->save('kyc_config.json', ['commission_rate' => 5,
    'dpo_enabled' => '1', 'dpo_environment' => 'test', 'dpo_company_token' => $TOKEN,
    'dpo_service_type' => '5525', 'dpo_currencies' => 'UGX', 'dpo_test_clients' => '12',
    'dpo_payment_method_uuid' => 'pm-uuid-1', 'dpo_company_acc_ref' => '']);
// What the vault held on the server, plus a stale token and account ref it
// must not win with.
file_put_contents($vB, json_encode(['config' => ['dpo_payment_method_uuid' => 'pm-uuid-1',
    'dpo_test_link_key' => str_repeat('b', 32), 'dpo_company_token' => $STALE,
    'dpo_company_acc_ref' => 'old-ref']]));
$DOOR = <<<'PHP'
<?php
$root    = getenv('T_ROOT');
$dataDir = getenv('T_DATA');          // what dpo_return, dpo_push, the cron and the probe do
require $root . '/lib/bootstrap_data.php';
require $root . '/lib/PluginConfig.php';
require $root . '/lib/DpoBootstrap.php';
$c = DpoBootstrap::vaulted(PluginConfig::load($root, $dataDir));
echo json_encode(['token' => $c['dpo_company_token'] ?? null, 'acc' => $c['dpo_company_acc_ref'] ?? null,
                  'link' => $c['dpo_test_link_key'] ?? null, 'cur' => $c['dpo_currencies'] ?? null,
                  'ready' => DpoBootstrap::readiness($c)['ready'] ?? null]);
PHP;
[$code, $out, $err] = $child($DOOR, ['T_DATA' => $dB, 'DN_VAULT_FILE' => $vB]);
$j = json_decode($out, true) ?: ['_raw' => $out . $err];
is_(($j['token'] ?? null) === $TOKEN, 'the probe, test page, return page, push and cron see the screen\'s token',
    'not the stale one the vault holds: ' . json_encode(array_keys($j)));
eq($j['cur'] ?? null, 'UGX', 'and its currencies');
eq($j['acc'] ?? null, '', 'a blank the screen saved stays blank — a stale vault value does not come back');
eq($j['link'] ?? null, str_repeat('b', 32), 'what the screen never saved still comes from the vault');

// ── C. The probe, on that state, against a fake DPO ─────────────────────────
echo "\nC. The probe finds the token the screen saved\n";
$fixture = $root . '/tests/fixtures/fake_dpo_server.php';
$sock = stream_socket_server('tcp://127.0.0.1:0');
$port = (int)substr((string)stream_socket_get_name($sock, false), strlen('127.0.0.1:'));
fclose($sock);
$fake = proc_open(['php', '-S', '127.0.0.1:' . $port, $fixture],
    [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pp);
for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) usleep(100000);
$dpoState = sys_get_temp_dir() . '/fake_dpo_state_' . md5($fixture . $port) . '.json';
register_shutdown_function(static function () use ($dpoState) { @unlink($dpoState); });
$probe = static function (string $dir, string $vault, array $args = []) use ($root, $port): array {
    $env = ['DN_DATA_DIR' => $dir, 'DN_VAULT_FILE' => $vault,
            'DN_DPO_FAKE_URL' => 'http://127.0.0.1:' . $port . '/'] + getenv();
    $p = proc_open(array_merge(['php', $root . '/tools/dpo_probe.php'], $args),
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root, $env);
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    return [proc_close($p), $out];
};
[$code, $out] = $probe($dB, $vB);
is_($code === 0 && strpos($out, 'Settings: as saved on the DPO Pay screen.') !== false,
    'it says where the settings came from', $out);
is_(preg_match('/createToken\s+000/', $out) === 1 && preg_match('/verifyToken\s+900/', $out) === 1,
    'and DPO accepts the screen\'s token — the stale vault token would have been answered 802', $out);
is_(strpos($out, $TOKEN) === false, 'without printing it');

$dC = $freshDir('screen-without-token');
SqliteStore::create($dC)->save('kyc_config.json', ['dpo_environment' => 'test', 'dpo_service_type' => '5525']);
[$code, $out] = $probe($dC, $tmp . '/vault-empty.json');
is_($code === 1 && strpos($out, 'No company token is set. The DPO Pay screen has none saved') !== false
    && strpos($out, 'Save settings') !== false,
    'a screen with no token saved: said so, with where to save one', $out);

$dD = $tmp . '/no-store';
@mkdir($dD, 0777, true);
file_put_contents($dD . '/kyc_config.json', json_encode(['dpo_environment' => 'test', 'dpo_service_type' => '5525']));
[$code, $out] = $probe($dD, $tmp . '/vault-empty.json');
is_($code === 1 && strpos($out, 'No settings database was found') !== false, 'no settings database: said so', $out);
is_(!is_file($dD . '/plugin.sqlite3'), 'and none was created looking for one');

// ── D. The doors ────────────────────────────────────────────────────────────
echo "\nD. Every door reaches DPO's settings through the one function\n";
foreach (['dpo_test.php', 'dpo_return.php', 'dpo_push.php', 'cron/dpo_reconcile.php', 'tools/dpo_probe.php',
          'includes/api/api_dpo.php', 'tabs/admin/dpo_payments.php', 'tabs/customer_app/portal_data.php'] as $f) {
    $src = (string)file_get_contents($root . '/' . $f);
    is_(preg_match('/DpoBootstrap::(vaulted|service|payNowFor)\(/', $src) === 1, $f . ' goes through DpoBootstrap');
}
$screenSrc = (string)file_get_contents($root . '/tabs/admin/dpo_payments.php');
is_(strpos($screenSrc, 'array_intersect(DpoBootstrap::KEYS, ConfigVault::VAULT_KEYS)') !== false,
    'the screen hands the vault only keys it keeps');
require_once $root . '/lib/ConfigVault.php';
require_once $root . '/lib/DpoBootstrap.php';
eq(array_values(array_diff(DpoBootstrap::KEYS, ConfigVault::VAULT_KEYS)), [],
   'and every DPO setting is one the vault keeps');

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
