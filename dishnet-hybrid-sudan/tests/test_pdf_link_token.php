<?php
declare(strict_types=1);
/**
 * test_pdf_link_token.php — receipt and delivery-note links carry a key of
 * their own (5.18.37, Phase 1 of the customer-login audit; P-36b).
 *
 * Until 5.18.37 those links were signed with
 *     hash_hmac('sha256', $file . date('Ymd'), $config['webhook_secret'] ?? 'dishnet')
 * Receipt file names are predictable (DishNet-Receipt-<id>.pdf), and on an
 * install where webhook_secret was never set the key was the word 'dishnet':
 * anyone who knew a receipt number could compute the day's link and read
 * another customer's receipt.
 *
 * PdfLinkToken gives those links a 64-hex secret generated once per install
 * (pdf_link_secret, vaulted like quote_pdf_secret), a daily token valid for
 * today and yesterday, and an unguessable one-off token for the temporary
 * PDFs whose .meta record is the only check. The old scheme is accepted only
 * while webhook_secret is a real value — never under the 'dishnet' default —
 * so links already sent keep opening for their last day on installs that had
 * a secret, and the weak ones die at once.
 */
$pass = 0; $fail = 0;
function t(string $n, $got, $want): void { global $pass, $fail;
    if ($got === $want) { $pass++; printf("  ok   %s\n", $n); }
    else { $fail++; printf("  FAIL %s\n       got  %s\n       want %s\n", $n, var_export($got, true), var_export($want, true)); } }
function is_(bool $c, string $m, string $d = ''): void { global $pass, $fail;
    if ($c) { $pass++; echo "  ok   $m\n"; } else { $fail++; echo "  FAIL $m" . ($d !== '' ? "\n       $d" : '') . "\n"; } }
function codeNC(string $f): string {
    $o = '';
    foreach (token_get_all((string)file_get_contents($f)) as $k) {
        if (is_array($k)) { if (in_array($k[0], [T_COMMENT, T_DOC_COMMENT], true)) continue; $o .= $k[1]; }
        else $o .= $k;
    }
    return $o;
}

$root = dirname(__DIR__);
if (!getenv('DN_VAULT_FILE')) putenv('DN_VAULT_FILE=' . tempnam(sys_get_temp_dir(), 'dn-vault-'));
@unlink((string)getenv('DN_VAULT_FILE'));
require_once $root . '/lib/StoreInterface.php';
require_once $root . '/lib/SqliteStore.php';
require_once $root . '/lib/PdfLinkToken.php';
require_once $root . '/lib/ConfigVault.php';
require_once $root . '/lib/PluginConfig.php';

$DAY  = 86400;
$file = 'DishNet-Receipt-12.pdf';

// ═════════════════════════════════════════════════
echo "\n1. The key: generated once, 64 hex, persisted, vaulted, restored\n";
// ═════════════════════════════════════════════════
$tmp = sys_get_temp_dir() . '/dn_pdflink_' . getmypid();
exec('rm -rf ' . escapeshellarg($tmp)); @mkdir($tmp . '/plugin/data', 0700, true);
register_shutdown_function(function () use ($tmp) { exec('rm -rf ' . escapeshellarg($tmp)); });
$data  = $tmp . '/plugin/data';
$store = SqliteStore::create($data);
$store->save('kyc_config.json', ['dry_run_mode' => true]);
$cfg = $store->load('kyc_config.json');
t('no secret before ensureSecret()',                        PdfLinkToken::secret($cfg), '');
t('…so mint() gives nothing rather than a token under a guessable key', PdfLinkToken::mint($file, $cfg), '');
t('…and verify() accepts nothing',                           PdfLinkToken::verify($file, hash_hmac('sha256', $file . '|' . gmdate('Ymd'), ''), $cfg), false);
$s1 = PdfLinkToken::ensureSecret($store, $cfg);
t('ensureSecret() returns a 64-hex secret',                  [strlen($s1), ctype_xdigit($s1)], [64, true]);
t('…written into the store copy of kyc_config.json',        ($store->load('kyc_config.json') ?? [])['pdf_link_secret'] ?? null, $s1);
t('…and into $config by reference',                          $cfg['pdf_link_secret'] ?? null, $s1);
$cfg2 = $store->load('kyc_config.json');
t('a second call keeps the same secret',                     PdfLinkToken::ensureSecret($store, $cfg2), $s1);
$vault = json_decode((string)@file_get_contents((string)getenv('DN_VAULT_FILE')), true) ?: [];
t('the secret is in the vault, so a re-install does not orphan every link in customers\' hands', $vault['config']['pdf_link_secret'] ?? null, $s1);
// A re-install: fresh store, same vault → the same key comes back.
$data2 = $tmp . '/plugin/data2'; @mkdir($data2, 0700, true);
$store2 = SqliteStore::create($data2); $store2->save('kyc_config.json', []);
$cfgR = [];
t('a fresh store with the vault present restores the SAME secret', PdfLinkToken::ensureSecret($store2, $cfgR), $s1);
is_(in_array('pdf_link_secret', ConfigVault::VAULT_KEYS, true) && in_array('pdf_link_secret', PluginConfig::SECRET_KEYS, true),
    'pdf_link_secret is a vault key and a secret to PluginConfig');
t('…redacted() hides it',                                    PluginConfig::redacted(['pdf_link_secret' => $s1])['pdf_link_secret'] ?? null, '[set]');
[$okSO] = PluginConfig::saveOverrides($tmp . '/never-created', ['pdf_link_secret' => 'x']);
t('…saveOverrides() refuses to write it',                    $okSO, false);
$none = [];
t('ensureSecret() on something that is not a store returns "" and mints nothing', PdfLinkToken::ensureSecret(null, $none), '');
t('…and leaves the config without a key', $none, []);

// ═════════════════════════════════════════════════
echo "\n2. Daily tokens: today and yesterday open, nothing else\n";
// ═════════════════════════════════════════════════
$now = time();
$tok = PdfLinkToken::mint($file, $cfg, $now);
t('a token is 64 hex',                                        [strlen($tok), ctype_xdigit($tok)], [64, true]);
t('today\'s token verifies',                                  PdfLinkToken::verify($file, $tok, $cfg, $now), true);
t('yesterday\'s token verifies today (a link lives 24–48 h)', PdfLinkToken::verify($file, PdfLinkToken::mint($file, $cfg, $now - $DAY), $cfg, $now), true);
t('a two-day-old token does not',                             PdfLinkToken::verify($file, PdfLinkToken::mint($file, $cfg, $now - 2 * $DAY), $cfg, $now), false);
t('tomorrow\'s token does not',                               PdfLinkToken::verify($file, PdfLinkToken::mint($file, $cfg, $now + $DAY), $cfg, $now), false);
t('another file\'s token does not',                           PdfLinkToken::verify('DishNet-Receipt-13.pdf', $tok, $cfg, $now), false);
t('a directory prefix on the file name changes nothing (basename)', PdfLinkToken::mint('../x/' . $file, $cfg, $now), $tok);
t('an empty token does not',                                  PdfLinkToken::verify($file, '', $cfg, $now), false);
t('the token is not the old scheme under the new key',        $tok === hash_hmac('sha256', $file . date('Ymd', $now), $s1), false);
t('random() gives 32 hex, never the same twice',              [strlen(PdfLinkToken::random()), PdfLinkToken::random() === PdfLinkToken::random()], [32, false]);

// ═════════════════════════════════════════════════
echo "\n3. The old scheme: accepted only under a REAL webhook_secret, never 'dishnet'\n";
// ═════════════════════════════════════════════════
$old = function (string $secret, int $at) use ($file): string { return hash_hmac('sha256', $file . date('Ymd', $at), $secret); };
t('webhook_secret unset: the old token under "dishnet" is refused',    PdfLinkToken::legacyVerify($file, $old('dishnet', $now), ['webhook_secret' => ''], $now), false);
t('webhook_secret literally "dishnet": still refused',                 PdfLinkToken::legacyVerify($file, $old('dishnet', $now), ['webhook_secret' => 'dishnet'], $now), false);
$real = 'a-real-webhook-secret-' . bin2hex(random_bytes(6));
t('a real webhook_secret: today\'s old token is accepted (transition)', PdfLinkToken::legacyVerify($file, $old($real, $now), ['webhook_secret' => $real], $now), true);
t('…and yesterday\'s',                                                 PdfLinkToken::legacyVerify($file, $old($real, $now - $DAY), ['webhook_secret' => $real], $now), true);
t('…but not the day before',                                           PdfLinkToken::legacyVerify($file, $old($real, $now - 2 * $DAY), ['webhook_secret' => $real], $now), false);
t('…and not a "dishnet" token even then',                              PdfLinkToken::legacyVerify($file, $old('dishnet', $now), ['webhook_secret' => $real], $now), false);

// ═════════════════════════════════════════════════
echo "\n4. Over HTTP: public.php generates the key at boot and serves by it\n";
// ═════════════════════════════════════════════════
$web  = $tmp . '/web'; @mkdir($web . '/data', 0700, true);
exec(sprintf('cp -R %s/. %s/ 2>/dev/null', escapeshellarg($root), escapeshellarg($web)));
exec('rm -rf ' . escapeshellarg($web . '/data')); @mkdir($web . '/data/receipt_pdfs', 0700, true); @mkdir($web . '/data/delivery_pdfs', 0700, true); @mkdir($web . '/data/temp_pdf', 0700, true);
file_put_contents($web . '/ucrm.json', json_encode(['pluginDataDir' => $web . '/data']));
$ws = SqliteStore::create($web . '/data'); $ws->save('kyc_config.json', ['dry_run_mode' => true, 'data_dir' => $web . '/data']); unset($ws);
@unlink((string)getenv('DN_VAULT_FILE'));   // the web install must generate its own
$receiptBytes  = "%PDF-1.4\n% receipt test body " . bin2hex(random_bytes(8)) . "\n%%EOF\n";
$deliveryBytes = "%PDF-1.4\n% delivery test body " . bin2hex(random_bytes(8)) . "\n%%EOF\n";
$dfile = 'DishNet_starlink_KYC-2026-0347.pdf';
file_put_contents($web . '/data/receipt_pdfs/' . $file, $receiptBytes);
file_put_contents($web . '/data/delivery_pdfs/' . $dfile, $deliveryBytes);
file_put_contents($web . '/data/receipt_pdfs/decoy.pdf', 'not for you');

$nonce = bin2hex(random_bytes(8));
file_put_contents($web . '/__nonce.txt', $nonce);
$get = function (string $url): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 20, CURLOPT_PROXY => '']);
    $r = curl_exec($ch);
    if ($r === false) { curl_close($ch); return ['code' => 0, 'headers' => '', 'body' => '']; }
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $hlen = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE); curl_close($ch);
    return ['code' => $code, 'headers' => substr($r, 0, $hlen), 'body' => substr($r, $hlen)];
};
$srv = null; $port = 0;
foreach (range(0, 9) as $slot) {
    $cand = 10700 + ((getmypid() + $slot * 29) % 200);
    $p = proc_open(sprintf('exec php -S 127.0.0.1:%d -t %s', $cand, escapeshellarg($web)),
                   [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
    $ours = false;
    for ($i = 0; $i < 60; $i++) {
        $got = $get("http://127.0.0.1:{$cand}/__nonce.txt");
        if ($got['code'] !== 0) { $ours = $got['code'] === 200 && trim($got['body']) === $nonce; break; }
        usleep(100000);
    }
    if ($ours) { $srv = $p; $port = $cand; break; }
    proc_terminate($p); proc_close($p);
}
@unlink($web . '/__nonce.txt');
if ($srv === null) { echo "  FAIL could not start the plugin under php -S\n"; printf("\n%d passed, %d failed\n", $pass, $fail + 1); exit(1); }
register_shutdown_function(function () use (&$srv) { if (is_resource($srv)) { proc_terminate($srv); proc_close($srv); } });
$base = "http://127.0.0.1:{$port}/public.php?page=api&action=";
$url  = function (string $action, string $f, string $tok) use ($base): string { return $base . $action . '&file=' . urlencode($f) . '&token=' . urlencode($tok); };

$r = $get($base . 'serve_receipt_pdf');
t('first request boots the plugin: no token is 400',        $r['code'], 400);
$webCfg = SqliteStore::create($web . '/data')->load('kyc_config.json') ?? [];
$webSecret = (string)($webCfg['pdf_link_secret'] ?? '');
t('…and public.php generated pdf_link_secret at boot (64 hex)', [strlen($webSecret), ctype_xdigit($webSecret)], [64, true]);
t('…different from the first install\'s key',              $webSecret === $s1, false);

$r = $get($url('serve_receipt_pdf', $file, PdfLinkToken::mint($file, $webCfg)));
t('receipt with today\'s token: 200',                       $r['code'], 200);
t('…the PDF bytes',                                          $r['body'], $receiptBytes);
is_(stripos($r['headers'], 'Content-Type: application/pdf') !== false, '…as application/pdf');
$r = $get($url('serve_receipt_pdf', $file, PdfLinkToken::mint($file, $webCfg, time() - $DAY)));
t('receipt with yesterday\'s token: 200',                   $r['code'], 200);
$r = $get($url('serve_receipt_pdf', $file, PdfLinkToken::mint($file, $webCfg, time() - 2 * $DAY)));
t('a two-day-old token: 403',                                $r['code'], 403);
$r = $get($url('serve_receipt_pdf', $file, $old('dishnet', time())));
t('THE POINT — the old token under the "dishnet" default: 403', $r['code'], 403);
$r = $get($url('serve_receipt_pdf', $file, $old('', time())));
t('the old token under an empty secret: 403',               $r['code'], 403);
$r = $get($url('serve_receipt_pdf', $file, bin2hex(random_bytes(32))));
t('a random token: 403',                                     $r['code'], 403);
$r = $get($url('serve_receipt_pdf', 'decoy.pdf', PdfLinkToken::mint($file, $webCfg)));
t('the receipt\'s token on another file: 403',              $r['code'], 403);
$r = $get($url('serve_receipt_pdf', 'DishNet-Receipt-99.pdf', PdfLinkToken::mint('DishNet-Receipt-99.pdf', $webCfg)));
t('a valid token for a file that does not exist: 404',      $r['code'], 404);
$r = $get($url('serve_delivery_pdf', $dfile, PdfLinkToken::mint($dfile, $webCfg)));
t('delivery note with today\'s token: 200, the bytes',      [$r['code'], $r['body']], [200, $deliveryBytes]);
$r = $get($url('serve_delivery_pdf', $dfile, $old('dishnet', time())));
t('delivery note under the old "dishnet" token: 403',       $r['code'], 403);
// The .meta token recorded at minting still opens the file (DeliveryPdfService writes one).
file_put_contents($web . '/data/delivery_pdfs/' . $dfile . '.meta', json_encode(['token' => PdfLinkToken::mint($dfile, $webCfg, time() - $DAY), 'filename' => 'Delivery-Note.pdf']));
$r = $get($url('serve_delivery_pdf', $dfile, PdfLinkToken::mint($dfile, $webCfg, time() - $DAY)));
is_($r['code'] === 200 && strpos($r['headers'], 'filename="Delivery-Note.pdf"') !== false, 'the .meta display name is used; the .meta token opens the file');
// Transition: an install that HAD a real webhook_secret keeps its last-day links.
$ws = SqliteStore::create($web . '/data'); $c = $ws->load('kyc_config.json'); $c['webhook_secret'] = $real; $ws->save('kyc_config.json', $c); unset($ws);
$r = $get($url('serve_receipt_pdf', $file, $old($real, time())));
t('with a REAL webhook_secret configured, the old-scheme token still opens today (transition)', $r['code'], 200);
$r = $get($url('serve_receipt_pdf', $file, $old('dishnet', time())));
t('…and the "dishnet" token still does not',                $r['code'], 403);
// Temp PDFs: the .meta token is the only check, and it is random now.
$tf = 'inv_12345_' . bin2hex(random_bytes(4)) . '.pdf';
$ttok = PdfLinkToken::random();
file_put_contents($web . '/data/temp_pdf/' . $tf, $receiptBytes);
file_put_contents($web . '/data/temp_pdf/' . $tf . '.meta', json_encode(['token' => $ttok, 'created' => time()]));
$r = $get($url('serve_temp_pdf', $tf, $old('dishnet', time())));
t('a temp PDF under the old "dishnet" token: 403',          $r['code'], 403);
$r = $get($url('serve_temp_pdf', $tf, $ttok));
t('…and with its own .meta token: 200, then gone',          [$r['code'], file_exists($web . '/data/temp_pdf/' . $tf)], [200, false]);

// ═════════════════════════════════════════════════
echo "\n5. Every link builder mints through PdfLinkToken; the old scheme survives only where recorded\n";
// ═════════════════════════════════════════════════
foreach (['webhook.php' => 'PdfLinkToken::ensureSecret(', 'cron_quote_wa.php' => 'PdfLinkToken::mint(', 'lib/DeliveryPdfService.php' => 'PdfLinkToken::mint(',
          'cron_maintenance.php' => 'PdfLinkToken::random(', 'includes/api/api_whatsapp.php' => 'PdfLinkToken::random(',
          'cron_invoice_notify.php' => 'PdfLinkToken::random(', 'includes/api/api_customer_app.php' => 'PdfLinkToken::random(',
          'public.php' => 'PdfLinkToken::ensureSecret('] as $f => $needle) {
    $src = codeNC($root . '/' . $f);
    is_(strpos($src, $needle) !== false, "$f uses $needle");
    is_(strpos($src, "/PdfLinkToken.php'") !== false, "$f loads the helper itself");
}
$oldScheme = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $fi) {
    $p = $fi->getPathname();
    if (substr($p, -4) !== '.php' || strpos($p, '/tests/') !== false || strpos($p, '/.git/') !== false || strpos($p, '/data/') !== false) continue;
    $src = codeNC($p);
    if (preg_match("#webhook_secret'\\]\\s*\\?\\?\\s*(\\(\\s*\\\$[A-Za-z_]+\\['evo_webhook_secret'\\]\\s*\\?\\?\\s*)?'dishnet'#", $src)) $oldScheme[] = substr($p, strlen($root) + 1);
}
sort($oldScheme);
t('the "webhook_secret ?? \'dishnet\'" fallback survives ONLY in the two EFRIS files recorded as a follow-up (not in Phase 1 scope)',
  $oldScheme, ['efris_pdf.php', 'tabs/admin/efris.php']);
$files = codeNC($root . '/includes/api/api_public_files.php');
foreach (['serve_receipt_pdf', 'serve_delivery_pdf'] as $a) {
    $at = strpos($files, "if (\$act === '{$a}')"); $blk = substr($files, (int)$at, 2200);
    is_(strpos($blk, 'PdfLinkToken::verify(') !== false && strpos($blk, 'PdfLinkToken::legacyVerify(') !== false && strpos($blk, "'dishnet'") === false,
        "$a verifies through PdfLinkToken and carries no 'dishnet' default");
}

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
