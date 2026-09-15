<?php
declare(strict_types=1);
/**
 * test_webhook_config.php — the webhook sees the same config everyone else does.
 *
 * webhook.php hydrated $config from the SqliteStore copy of kyc_config.json
 * and nothing else. Fifteen admin screens write that copy directly, so it had
 * to keep winning — but a key held only in config.json or the kyc_config FILE
 * was invisible, and that is how quotation PDF URLs went out on :8443 while
 * crm_public_url sat correctly configured on disk.
 *
 * The fix is one line and one operator: `$config = $config + PluginConfig::load()`.
 * These tests pin the operator. '+' means the store still wins every key it
 * holds — including a blank, because a blank in the store already beat
 * everything yesterday — and disk fills only what is absent.
 *
 * Two entry paths are exercised, because there are two in production: uCRM
 * posts to webhook.php directly, and public.php includes it with $config
 * already built. The overlay has to apply on both.
 */
$pass = 0; $fail = 0;
function is_(bool $c, string $m): void { global $pass, $fail;
    if ($c) { $pass++; echo "  ok   $m\n"; } else { $fail++; echo "  FAIL $m\n"; } }
function t(string $n, $got, $want) { global $pass, $fail;
    if ($got === $want) { $pass++; printf("  ok   %s\n", $n); }
    else { $fail++; printf("  FAIL %s\n       got  %s\n       want %s\n", $n, var_export($got, true), var_export($want, true)); } }

$root = dirname(__DIR__);
if (!getenv('DN_VAULT_FILE')) putenv('DN_VAULT_FILE=' . tempnam(sys_get_temp_dir(), 'dn-vault-'));
require_once $root . '/lib/StoreInterface.php';
require_once $root . '/lib/SqliteStore.php';

$tmp = sys_get_temp_dir() . '/whcfg_' . getmypid();
exec('rm -rf ' . escapeshellarg($tmp)); @mkdir($tmp, 0777, true);

// The store FIRST. SqliteStore::create() on a fresh directory imports every
// *.json it finds and renames it .migrated — write the files after, or the
// disk side of this test silently becomes the store side.
$store = SqliteStore::create($tmp);
$store->save('kyc_config.json', [
    'only_in_store'  => 'S',
    'both'           => 'STORE',
    'blank_in_store' => '',
    'webhook_secret' => '',
]);
file_put_contents($tmp . '/config.json', json_encode([
    'only_on_disk'   => 'D',
    'both'           => 'DISK',
    'blank_in_store' => 'DISK_REAL',
    'crm_public_url' => 'https://public.test',
]));

/**
 * Run webhook.php's bootstrap in a subprocess and read back what $config
 * became. The script exits early (405, no request method) through whResp(),
 * so a shutdown function is the only place to look; its own JSON is buffered
 * away first.
 */
$probe = function (string $preamble) use ($root, $tmp): array {
    $code = <<<'SUB'
$__root = __ROOT__; $__tmp = __TMP__;
$dataDir = $__tmp;
__PREAMBLE__
register_shutdown_function(function () use ($__root) {
    global $config;
    while (ob_get_level() > 0) ob_end_clean();
    require_once $__root . '/lib/crm_url.php';
    echo json_encode([
        'only_in_store'  => $config['only_in_store']  ?? null,
        'only_on_disk'   => $config['only_on_disk']   ?? null,
        'both'           => $config['both']           ?? null,
        'blank_in_store' => $config['blank_in_store'] ?? null,
        'crm_public_url' => $config['crm_public_url'] ?? null,
        'webhook_secret' => $config['webhook_secret'] ?? null,
        'plugin_public'  => dn_plugin_public($config),
    ]);
});
ob_start();
require $__root . '/webhook.php';
SUB;
    $code = str_replace(['__ROOT__', '__TMP__', '__PREAMBLE__'],
                        [var_export($root, true), var_export($tmp, true), $preamble], $code);
    $out = [];
    exec('DN_VAULT_FILE=' . escapeshellarg((string)getenv('DN_VAULT_FILE'))
         . ' php -r ' . escapeshellarg($code) . ' 2>/dev/null', $out);
    $json = json_decode(trim(implode("\n", $out)), true);
    return is_array($json) ? $json : ['_raw' => implode("\n", $out)];
};

foreach ([
    'direct hit — uCRM posts to webhook.php, $config built here' => '',
    'via public.php — $config already built from the store'     =>
        "require_once \$__root . '/lib/StoreInterface.php'; require_once \$__root . '/lib/SqliteStore.php';\n"
      . "\$store = SqliteStore::create(\$__tmp); \$config = \$store->load('kyc_config.json');",
] as $label => $pre) {
    echo "\n$label\n";
    $c = $probe($pre);
    is_(!isset($c['_raw']), 'the bootstrap ran and reported back' . (isset($c['_raw']) ? ': ' . $c['_raw'] : ''));
    t('a key only the store has is still seen',            $c['only_in_store'] ?? null, 'S');
    t('a key only on disk is now seen too',                $c['only_on_disk'] ?? null, 'D');
    t('where both have it, the STORE wins',                $c['both'] ?? null, 'STORE');
    t('a BLANK in the store still wins — nothing that read blank yesterday reads otherwise today',
                                                            $c['blank_in_store'] ?? null, '');
    t('crm_public_url reaches the webhook',                $c['crm_public_url'] ?? null, 'https://public.test');
    is_(strpos((string)($c['plugin_public'] ?? ''), 'https://public.test/') === 0,
        'and the PDF URL it would hand Evolution is built on it: ' . ($c['plugin_public'] ?? '(none)'));
    is_(strpos((string)($c['plugin_public'] ?? ''), ':8443') === false, 'with no :8443 anywhere');
    t('the webhook secret is exactly what the store held', $c['webhook_secret'] ?? null, '');
}

echo "\nThe change is where it should be, and only there\n";
$wh = (string)file_get_contents($root . '/webhook.php');
$overlay = '$config = (array)$config + PluginConfig::load(__DIR__, $dataDir);';
t('the overlay is present exactly once', substr_count($wh, $overlay), 1);
is_(strpos($wh, $overlay) > strpos($wh, "if (!isset(\$config)) {\n    \$config = \$store->load('kyc_config.json') ?? [];\n}"),
    'and it comes after the store load, so it overlays whichever path built $config');
is_(strpos($wh, "if (!isset(\$config)) {") !== false, 'the public.php include guard is intact');
$pub = (string)file_get_contents($root . '/public.php');
is_(strpos($pub, "\$config = \$store->load('kyc_config.json');") !== false,
    'public.php still builds the UI\'s $config from the store — untouched');
is_(strpos($pub, 'PluginConfig::load(__DIR__, $dataDir)') === false
    || substr_count($pub, '+ PluginConfig::load') === 0,
    'and no overlay was pushed up into public.php, whose $config feeds the whole UI');

exec('rm -rf ' . escapeshellarg($tmp));
printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
