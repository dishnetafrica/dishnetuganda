<?php
declare(strict_types=1);
/**
 * test_quote_pdf_token.php — a quotation PDF link dies.
 *
 * serve_quote_pdf is public: Evolution fetches the URL server-side and the
 * only guard is the token in it. Until 5.18.0 the endpoint accepted the daily
 * HMAC *or* a permanent token stored in the PDF's .meta file, so every
 * quotation URL ever logged stayed fetchable for good. Now there is one
 * construction (lib/QuotePdfToken.php), minted by every generator and checked
 * by the endpoint, good for today and yesterday in UTC and then refused; the
 * .meta file is metadata only; the endpoint serves PDFs and nothing else from
 * that directory; and an admin retry re-signs a stale link instead of
 * re-sending one the endpoint will refuse.
 *
 * The endpoint is exercised for real — the API include running under PHP's
 * built-in server, over HTTP — not by reading its source. Source pins are
 * used only for what a round trip cannot see: that no generator computes the
 * HMAC itself, and that the validator no longer reads the .meta token.
 */
$pass = 0; $fail = 0;
function is_(bool $c, string $m, string $d = ''): void { global $pass, $fail;
    if ($c) { $pass++; echo "  ok   $m\n"; } else { $fail++; echo "  FAIL $m" . ($d !== '' ? "\n       $d" : '') . "\n"; } }
function t(string $n, $got, $want): void { global $pass, $fail;
    if ($got === $want) { $pass++; printf("  ok   %s\n", $n); }
    else { $fail++; printf("  FAIL %s\n       got  %s\n       want %s\n", $n, var_export($got, true), var_export($want, true)); } }
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
require_once $root . '/lib/QuotePdfToken.php';

$tmp = sys_get_temp_dir() . '/qtoken_' . getmypid();
exec('rm -rf ' . escapeshellarg($tmp)); @mkdir($tmp . '/quote_pdfs', 0777, true);
register_shutdown_function(function () use ($tmp) { exec('rm -rf ' . escapeshellarg($tmp)); });

// The install's own key — and the shared uCRM secret it must NOT fall back to
// once the key exists (that one also derives the customer app's JWT key and
// acts as the debug_key bearer, so it cannot double as a PDF-link key).
$secret = 'own-' . bin2hex(random_bytes(6));
$shared = 'shared-' . bin2hex(random_bytes(6));
$cfg    = ['webhook_secret' => $shared, 'quote_pdf_secret' => $secret];
$file   = 'DishNet-Quote-000016.pdf';
$now    = time();
$DAY    = 86400;

// ═════════════════════════════════════════════════
echo "\n1. One construction, on one clock\n";
// ═════════════════════════════════════════════════
t('mint() is the daily HMAC every generator and the endpoint have always used',
  QuotePdfToken::mint($file, $cfg, $now), hash_hmac('sha256', $file . gmdate('Ymd', $now), $secret));
t('a path mints the same token as its file name (the endpoint sees only the name)',
  QuotePdfToken::mint('/data/quote_pdfs/' . $file, $cfg, $now), QuotePdfToken::mint($file, $cfg, $now));
t('the install\'s own key wins',                         QuotePdfToken::secret($cfg), $secret);
t('a whitespace-only key is no key',                     QuotePdfToken::secret(['webhook_secret' => $shared, 'quote_pdf_secret' => "  \n"]), $shared);
t('before the key exists, the shared webhook_secret signs — exactly as before 5.18.1',
  QuotePdfToken::secret(['webhook_secret' => $shared]), $shared);
t('an install with neither signs with the same default it always did',
  QuotePdfToken::mint($file, [], $now), hash_hmac('sha256', $file . gmdate('Ymd', $now), 'dishnet'));
t('hasRealSecret(): nothing set → false',                QuotePdfToken::hasRealSecret([]), false);
t('hasRealSecret(): the default typed in by hand → false', QuotePdfToken::hasRealSecret(['webhook_secret' => 'dishnet']), false);
t('only the shared secret: real yes, own no',            [QuotePdfToken::hasRealSecret(['webhook_secret' => $shared]), QuotePdfToken::hasOwnSecret(['webhook_secret' => $shared])], [true, false]);
t('the key itself: real yes, own yes',                   [QuotePdfToken::hasRealSecret($cfg), QuotePdfToken::hasOwnSecret($cfg)], [true, true]);
t('a token signed with the shared uCRM secret is refused once the key exists',
  QuotePdfToken::verify($file, QuotePdfToken::mint($file, ['webhook_secret' => $shared], $now), $cfg, $now), false);

$today = QuotePdfToken::mint($file, $cfg, $now);
$yday  = QuotePdfToken::mint($file, $cfg, $now - $DAY);
t('today\'s token verifies',                          QuotePdfToken::verify($file, $today, $cfg, $now), true);
t('yesterday\'s token still verifies',                QuotePdfToken::verify($file, $yday,  $cfg, $now), true);
t('two days old is refused',                          QuotePdfToken::verify($file, QuotePdfToken::mint($file, $cfg, $now - 2 * $DAY), $cfg, $now), false);
t('a token from the future is refused',               QuotePdfToken::verify($file, QuotePdfToken::mint($file, $cfg, $now + $DAY), $cfg, $now), false);
t('another install\'s secret is refused',             QuotePdfToken::verify($file, QuotePdfToken::mint($file, ['webhook_secret' => 'other'], $now), $cfg, $now), false);
t('a token for a different file is refused',          QuotePdfToken::verify('DishNet-Quote-000017.pdf', $today, $cfg, $now), false);
t('an empty token is refused',                        QuotePdfToken::verify($file, '', $cfg, $now), false);
t('the token is bound to the day, not to the file\'s .meta or anything on disk',
  QuotePdfToken::verify($file, $today, $cfg, $now + $DAY), true);      // tomorrow: it is "yesterday's"
t('…and is dead the day after that',                  QuotePdfToken::verify($file, $today, $cfg, $now + 2 * $DAY), false);

// The web process runs on the install's timezone (public.php applies it); a
// cron process may sit on the container's default clock. The day must not
// depend on which of them is asking.
$tzWas = date_default_timezone_get();
// Pick an instant where the two extreme zones are on different calendar days.
$inst = $now; for ($i = 0; $i < 48; $i++) {
    date_default_timezone_set('Pacific/Kiritimati'); $a = date('Ymd', $inst);
    date_default_timezone_set('Pacific/Pago_Pago');  $b = date('Ymd', $inst);
    if ($a !== $b) break; $inst += 3600;
}
is_($a !== $b, 'found an instant where two process clocks disagree on the calendar day');
date_default_timezone_set('Pacific/Kiritimati'); $mA = QuotePdfToken::mint($file, $cfg, $inst); $vA = QuotePdfToken::verify($file, $mA, $cfg, $inst);
date_default_timezone_set('Pacific/Pago_Pago');  $mB = QuotePdfToken::mint($file, $cfg, $inst); $vB = QuotePdfToken::verify($file, $mA, $cfg, $inst);
date_default_timezone_set($tzWas);
t('both clocks mint the same token at that instant',  $mA, $mB);
is_($vA && $vB, 'and a token minted under one clock verifies under the other');
is_(strpos(codeNC($root . '/lib/QuotePdfToken.php'), 'hash_equals(') !== false, 'the comparison is constant-time (hash_equals)');

// ═════════════════════════════════════════════════
echo "\n2. The endpoint, for real, over HTTP\n";
// ═════════════════════════════════════════════════
$pdfBytes = "%PDF-1.4\n% quotation test body " . bin2hex(random_bytes(8)) . "\n%%EOF\n";
file_put_contents($tmp . '/quote_pdfs/' . $file, $pdfBytes);
// The old permanent token, exactly as every generator used to write it.
$permanent = hash_hmac('sha256', $file . '20240101', $secret);
file_put_contents($tmp . '/quote_pdfs/' . $file . '.meta', json_encode([
    'token' => $permanent, 'created' => $now - 400 * $DAY, 'quote' => '000016',
    'filename' => 'Quote-000016.pdf', 'customer' => 'Secure Solutions', 'total' => 2749000,
]));
// A PDF with no .meta at all — authorization must not need one.
$bare = 'quote_77_ABC.pdf';
file_put_contents($tmp . '/quote_pdfs/' . $bare, $pdfBytes);
// A decoy outside the directory, for the traversal probe.
file_put_contents($tmp . '/decoy.pdf', 'not for you');

$router = $tmp . '/router.php';
file_put_contents($router, '<?php
ob_start();
$dataDir = ' . var_export($tmp, true) . ';
$config  = ' . var_export($cfg, true) . ';
$store = null; $pdo = null; $body = [];
$act = $_GET["action"] ?? ""; $met = $_SERVER["REQUEST_METHOD"];
$ok2 = function($d,$m="OK",$c=200){ while (ob_get_level() > 0) ob_end_clean(); http_response_code($c); echo json_encode(["status"=>"success","message"=>$m,"data"=>$d]); exit; };
$er2 = function($m,$c=400){ while (ob_get_level() > 0) ob_end_clean(); http_response_code($c); echo json_encode(["status"=>"error","message"=>$m]); exit; };
if ($act === "ping") { while (ob_get_level() > 0) ob_end_clean(); echo "QUOTE-PDF-TEST-SERVER"; exit; }
require ' . var_export($root . '/includes/api/api_public_files.php', true) . ';   // 5.18.37: the serve_* actions live here
while (ob_get_level() > 0) ob_end_clean(); http_response_code(404); echo "unhandled";
');

$get = function (int $port, string $query): array {
    $ch = curl_init("http://127.0.0.1:{$port}/?page=api&{$query}");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true,
        CURLOPT_TIMEOUT => 5, CURLOPT_PROXY => '']);
    $r = curl_exec($ch);
    if ($r === false) { curl_close($ch); return ['code' => 0, 'headers' => '', 'body' => '']; }
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hlen = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    return ['code' => $code, 'headers' => substr($r, 0, $hlen), 'body' => substr($r, $hlen)];
};
$srv = null; $port = 0;
foreach (range(0, 9) as $slot) {
    $cand = 9500 + ((getmypid() + $slot * 13) % 80);
    $p = proc_open(sprintf('exec php -S 127.0.0.1:%d %s', $cand, escapeshellarg($router)),
                   [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
    $ours = false;
    for ($i = 0; $i < 40; $i++) {
        $got = $get($cand, 'action=ping');
        if ($got['code'] !== 0) { $ours = strpos($got['body'], 'QUOTE-PDF-TEST-SERVER') !== false; break; }
        usleep(100000);
    }
    if ($ours) { $srv = $p; $port = $cand; break; }
    if (is_resource($p)) { proc_terminate($p); proc_close($p); }
}
if ($srv === null) { echo "  FAIL could not start the built-in server for the endpoint\n"; $fail++; }
else {
    register_shutdown_function(function () use (&$srv) { if (is_resource($srv)) { proc_terminate($srv); proc_close($srv); } });
    $url = function (string $f, string $tok): string { return 'action=serve_quote_pdf&file=' . urlencode($f) . '&token=' . urlencode($tok); };

    $r = $get($port, $url($file, QuotePdfToken::mint($file, $cfg)));
    t('today\'s token: 200',                                  $r['code'], 200);
    t('…and the body is the PDF, byte for byte',              $r['body'], $pdfBytes);
    is_(stripos($r['headers'], 'Content-Type: application/pdf') !== false, 'served as application/pdf');
    is_(strpos($r['headers'], 'filename="Quote-000016.pdf"') !== false, 'the .meta display name is still used — metadata, not authorization', $r['headers']);

    $r = $get($port, $url($file, QuotePdfToken::mint($file, $cfg, time() - $DAY)));
    t('yesterday\'s token: 200',                              $r['code'], 200);

    $r = $get($port, $url($file, $permanent));
    t('THE POINT — the permanent .meta token: 403',           $r['code'], 403);
    is_(strpos($r['body'], $pdfBytes) === false, 'and not a byte of the PDF came back');

    $r = $get($port, $url($file, QuotePdfToken::mint($file, $cfg, time() - 2 * $DAY)));
    t('a two-day-old token: 403',                             $r['code'], 403);
    $r = $get($port, $url($file, QuotePdfToken::mint($file, $cfg, time() + $DAY)));
    t('a token from the future: 403',                         $r['code'], 403);
    $r = $get($port, $url($file, QuotePdfToken::mint($file, ['webhook_secret' => 'other'])));
    t('a token signed with another secret: 403',              $r['code'], 403);
    $r = $get($port, $url($file, hash_hmac('sha256', $file . gmdate('Ymd'), $shared)));
    t('a token signed with the shared uCRM webhook_secret: 403', $r['code'], 403);
    $r = $get($port, 'action=serve_quote_pdf&file=' . urlencode($file));
    t('no token at all: 400',                                 $r['code'], 400);
    $r = $get($port, $url('DishNet-Quote-999999.pdf', QuotePdfToken::mint('DishNet-Quote-999999.pdf', $cfg)));
    t('a file that does not exist: 404',                      $r['code'], 404);
    $r = $get($port, $url('../decoy.pdf', QuotePdfToken::mint('decoy.pdf', $cfg)));
    t('a path outside quote_pdfs, even correctly signed for its name: 404', $r['code'], 404);
    is_(strpos($r['body'], 'not for you') === false, 'and the decoy did not leak');
    $r = $get($port, $url($file . '.meta', QuotePdfToken::mint($file . '.meta', $cfg)));
    t('the .meta file itself, even correctly signed for its name: 404 (PDFs only)', $r['code'], 404);
    is_(strpos($r['body'], 'Secure Solutions') === false, 'and the customer name in it did not leak');

    $r = $get($port, $url($bare, QuotePdfToken::mint($bare, $cfg)));
    t('a PDF with no .meta at all: 200 — authorization never needed the file', $r['code'], 200);
    is_(strpos($r['headers'], 'filename="quote-77-ABC.pdf"') !== false, 'with the display name derived from the file name', $r['headers']);
}

// ═════════════════════════════════════════════════
echo "\n3. A retried document is re-signed, everything else passes through\n";
// ═════════════════════════════════════════════════
$base  = 'https://crm.example.test/crm/_plugins/dishnet-hybrid-sudan/public.php';
$stale = $base . '?page=api&action=serve_quote_pdf&file=' . urlencode($file) . '&token=' . urlencode($permanent);
$fresh = QuotePdfToken::refreshUrl($stale, $cfg, $now);
t('a stale quotation URL comes back with today\'s token, nothing else changed',
  $fresh, $base . '?page=api&action=serve_quote_pdf&file=' . urlencode($file) . '&token=' . urlencode(QuotePdfToken::mint($file, $cfg, $now)));
$fr = $base . '?page=api&action=serve_quote_pdf&file=' . urlencode($file) . '&token=x#top';
is_(substr(QuotePdfToken::refreshUrl($fr, $cfg, $now), -4) === '#top', 'a fragment survives');
foreach ([
    'a receipt URL'         => $base . '?page=api&action=serve_receipt_pdf&file=DishNet-Receipt-12.pdf&token=' . $permanent,
    'a delivery-note URL'   => $base . '?page=api&action=serve_delivery_pdf&file=DishNet_starlink_KYC-2026-0347.pdf&token=' . $permanent,
    'a temporary-PDF URL'   => $base . '?page=api&action=serve_temp_pdf&file=inv_12345_abc.pdf&token=' . $permanent,
    'a quote URL with no file' => $base . '?page=api&action=serve_quote_pdf&token=' . $permanent,
    'a URL with no query'   => $base,
    'an unrelated URL'      => 'https://cdn.example.test/flyer.jpg',
] as $what => $u) {
    t($what . ' is untouched, byte for byte', QuotePdfToken::refreshUrl($u, $cfg, $now), $u);
}

// ═════════════════════════════════════════════════
echo "\n4. Nobody computes the HMAC themselves; the validator reads no .meta token\n";
// ═════════════════════════════════════════════════
// 5.18.37: the four serve_* actions moved out of api_cron_debug.php (now behind the
// staff guard) into api_public_files.php, the one pre-auth include that serves files.
$api   = codeNC($root . '/includes/api/api_public_files.php');
$start = strpos($api, "if (\$act === 'serve_quote_pdf')");
$end   = strpos($api, "if (\$act === 'serve_delivery_pdf')");
is_($start !== false && $end !== false && $end > $start, 'the serve_quote_pdf block is in the pre-auth file-serving include');
is_(strpos(codeNC($root . '/includes/api/api_cron_debug.php'), "if (\$act === 'serve_quote_pdf')") === false, '…and no longer in api_cron_debug.php, which is staff-only since 5.18.37');
$block = substr($api, (int)$start, (int)$end - (int)$start);
is_(strpos($block, 'QuotePdfToken::verify(') !== false,  'serve_quote_pdf verifies through QuotePdfToken');
is_(strpos($block, 'hash_hmac(') === false,               'serve_quote_pdf computes no HMAC of its own');
is_(strpos($block, "\$meta['token']") === false && strpos($block, 'hash_equals(') === false,
    'serve_quote_pdf never compares against a stored token');
is_(strpos($block, "preg_match('/\\.pdf$/i'") !== false,  'serve_quote_pdf serves .pdf files only');

// Every place that builds a serve_quote_pdf URL, wherever it is.
$gen = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $fi) {
    $p = $fi->getPathname();
    if (substr($p, -4) !== '.php' || strpos($p, '/tests/') !== false || strpos($p, '/data/') !== false
        || strpos($p, '/.git/') !== false || basename($p) === 'api_public_files.php') continue;
    $src = codeNC($p);
    $off = 0;
    while (($at = strpos($src, 'action=serve_quote_pdf', $off)) !== false) {
        $gen[] = [substr($p, strlen($root) + 1), substr($src, max(0, $at - 2200), 2200)];
        $off = $at + 1;
    }
}
is_(count($gen) >= 4, 'the four known generators were found (webhook, cron_quote_wa, PluginQuotePdf, QuotePdfService)', 'found ' . count($gen));
foreach ($gen as [$where, $window]) {
    is_(strpos($window, 'QuotePdfToken::mint(') !== false, "$where mints through QuotePdfToken");
    is_(strpos(codeNC($root . '/' . $where), "/QuotePdfToken.php'") !== false, "$where loads the helper itself (no test runs that block; a missing require would be a fatal at quote time)");
    is_(strpos($window, 'hash_hmac(') === false,           "$where computes no HMAC of its own");
    is_(strpos($window, "'token' =>") === false && strpos($window, "'token'=>") === false,
        "$where stores no token in .meta");
}
is_(strpos(codeNC($root . '/lib/NotificationService.php'), 'QuotePdfToken::refreshUrl(') !== false,
    'NotificationService::retryOne re-signs through QuotePdfToken');
foreach (['public.php' => 'every plugin page, API call and routed webhook',
          'webhook.php' => 'a direct webhook hit',
          'cron_quote_wa.php' => 'the WhatsApp quote cron'] as $f => $what) {
    is_(strpos(codeNC($root . '/' . $f), 'QuotePdfToken::ensureSecret(') !== false, "$f generates the key at boot ($what)");
}
require_once $root . '/lib/PluginConfig.php';
is_(in_array('quote_pdf_secret', PluginConfig::SECRET_KEYS, true), 'quote_pdf_secret is a secret to PluginConfig');
t('…so redacted() hides it',                                PluginConfig::redacted(['quote_pdf_secret' => $secret])['quote_pdf_secret'] ?? null, '[set]');
[$okSO, $errSO] = PluginConfig::saveOverrides($tmp . '/never-created', ['quote_pdf_secret' => 'x']);
t('…and saveOverrides() refuses to write it',               $okSO, false);

// ═════════════════════════════════════════════════
echo "\n5. The admin retry, for real: a queued quotation send goes out re-signed\n";
// ═════════════════════════════════════════════════
require_once $root . '/lib/StoreInterface.php';
require_once $root . '/lib/SqliteStore.php';
require_once $root . '/lib/currency.php';
require_once $root . '/lib/CustomerContact.php';
require_once $root . '/lib/NotificationService.php';
$store = SqliteStore::create($tmp);
$pdo   = $store->getPdo();
$pdo->exec("CREATE TABLE IF NOT EXISTS notification_queue (
    id INTEGER PRIMARY KEY AUTOINCREMENT, sender TEXT NOT NULL DEFAULT 'support', phone TEXT NOT NULL,
    message TEXT NOT NULL, event TEXT DEFAULT NULL, vars TEXT DEFAULT NULL, status TEXT NOT NULL DEFAULT 'failed',
    http_code INTEGER DEFAULT NULL, error TEXT DEFAULT NULL, attempts INTEGER NOT NULL DEFAULT 1,
    last_attempt_at TEXT NOT NULL, retry_at TEXT DEFAULT NULL, retry_by TEXT DEFAULT NULL,
    created_at TEXT NOT NULL DEFAULT (datetime('now')))");
$ins = $pdo->prepare("INSERT INTO notification_queue (sender, phone, message, event, vars, status, http_code, error, attempts, last_attempt_at)
                      VALUES ('support', '256700000000', 'Your quotation', 'quote_pdf', ?, 'failed', 500, 'sendMedia 500', 1, ?)");
$ins->execute([json_encode(['_type' => 'document', 'url' => $stale, 'filename' => 'Quote-000016.pdf']), date('Y-m-d H:i:s', $now - 3 * $DAY)]);
$quoteRow = (int)$pdo->lastInsertId();
$receiptUrl = $base . '?page=api&action=serve_receipt_pdf&file=DishNet-Receipt-12.pdf&token=' . $permanent;
$ins->execute([json_encode(['_type' => 'document', 'url' => $receiptUrl, 'filename' => 'Receipt.pdf']), date('Y-m-d H:i:s', $now - 3 * $DAY)]);
$receiptRow = (int)$pdo->lastInsertId();

// Dry-run mode: the service logs what it would have sent and touches no network.
$svc = new NotificationService($store, $cfg + [
    'dry_run_mode' => true, 'data_dir' => $tmp,
    'wa_plugin_url' => 'http://127.0.0.1:1/', 'wa_app_key' => 'k', 'wa_auth_key' => 'a',
]);
$svc->retryOne($quoteRow, 'Tester');
$svc->retryOne($receiptRow, 'Tester');
$log = json_decode((string)@file_get_contents($tmp . '/dry_run_notification_log.json'), true) ?: [];
$sentUrls = array_values(array_map(fn($e) => (string)($e['vars']['url'] ?? ''), $log));
is_(count($sentUrls) === 2, 'both retries reached the send step', 'entries: ' . count($sentUrls));
$q = parse_url($sentUrls[0] ?? '', PHP_URL_QUERY) ?: ''; parse_str($q, $qq);
t('the quotation retry went out for the same file',            $qq['file'] ?? null, $file);
is_(($qq['token'] ?? '') !== $permanent,                       'with a new token — not the dead one from the queue row');
is_(QuotePdfToken::verify($file, (string)($qq['token'] ?? ''), $cfg), 'that the endpoint will accept today');
t('the receipt retry went out exactly as queued',              $sentUrls[1] ?? null, $receiptUrl);

// ═════════════════════════════════════════════════
echo "\n6. The key is generated once, stored, and adopted by every process\n";
// ═════════════════════════════════════════════════
$sdir = $tmp . '/store'; @mkdir($sdir, 0777, true);
$s1 = SqliteStore::create($sdir);
$c1 = ['webhook_secret' => $shared];
$k1 = QuotePdfToken::ensureSecret($s1, $c1);
is_(preg_match('/^[0-9a-f]{32}$/', $k1) === 1,               'a fresh install gets a 32-hex key of its own', $k1);
t('…placed into this process\'s config',                     $c1['quote_pdf_secret'] ?? null, $k1);
t('…and stored beside everything else in kyc_config.json',   SqliteStore::create($sdir)->load('kyc_config.json')['quote_pdf_secret'] ?? null, $k1);
t('…leaving the shared secret where it was',                 SqliteStore::create($sdir)->load('kyc_config.json')['webhook_secret'] ?? null, null);
$c2 = [];
t('another process with a stale config adopts the stored key, not a new one', QuotePdfToken::ensureSecret(SqliteStore::create($sdir), $c2), $k1);
t('…and secret() then resolves to it',                       QuotePdfToken::secret($c2), $k1);
$c3 = ['quote_pdf_secret' => 'already-here'];
t('a config that already carries the key is left alone',     QuotePdfToken::ensureSecret($s1, $c3), 'already-here');
t('…and the store keeps its own',                            $s1->load('kyc_config.json')['quote_pdf_secret'] ?? null, $k1);
$s1->save('kyc_config.json', ['quote_pdf_secret' => '   ', 'other' => 'kept']);
$c4 = [];
$k4 = QuotePdfToken::ensureSecret($s1, $c4);
is_($k4 !== '' && $k4 !== $k1 && preg_match('/^[0-9a-f]{32}$/', $k4) === 1, 'a blank stored value is replaced');
t('…without disturbing the other keys in the store',         $s1->load('kyc_config.json')['other'] ?? null, 'kept');
$broken = new class { public function load(string $f): array { throw new RuntimeException('database is locked'); } public function save(string $f, array $d): void {} };
$c5 = ['webhook_secret' => $shared];
t('a store that cannot be read yields no key and no exception', QuotePdfToken::ensureSecret($broken, $c5), '');
t('…and the config is left as it was, so the fallback applies here as everywhere else', $c5, ['webhook_secret' => $shared]);
t('no store at all yields the same',                         QuotePdfToken::ensureSecret(null, $c5), '');

// ═════════════════════════════════════════════════
echo "\n7. A direct webhook hit on an install without the key generates it — for real\n";
// ═════════════════════════════════════════════════
$bdir = $tmp . '/boot'; @mkdir($bdir, 0777, true);
SqliteStore::create($bdir)->save('kyc_config.json', ['webhook_secret' => $shared]);
$boot = function () use ($root, $bdir): array {
    $code = <<<'SUB'
$__root = __ROOT__; $dataDir = __TMP__;
register_shutdown_function(function () use ($__root, $dataDir) {
    global $config;
    while (ob_get_level() > 0) ob_end_clean();
    $s = SqliteStore::create($dataDir)->load('kyc_config.json') ?? [];
    echo json_encode(['in_config' => $config['quote_pdf_secret'] ?? null, 'in_store' => $s['quote_pdf_secret'] ?? null,
                      'shared_kept' => $s['webhook_secret'] ?? null]);
});
ob_start();
require $__root . '/webhook.php';
SUB;
    $code = str_replace(['__ROOT__', '__TMP__'], [var_export($root, true), var_export($bdir, true)], $code);
    $out = [];
    exec('DN_VAULT_FILE=' . escapeshellarg((string)getenv('DN_VAULT_FILE')) . ' php -r ' . escapeshellarg($code) . ' 2>/dev/null', $out);
    $j = json_decode(trim(implode("\n", $out)), true);
    return is_array($j) ? $j : ['_raw' => implode("\n", $out)];
};
$b1 = $boot();
is_(!isset($b1['_raw']), 'the webhook bootstrap ran and reported back' . (isset($b1['_raw']) ? ': ' . $b1['_raw'] : ''));
is_(preg_match('/^[0-9a-f]{32}$/', (string)($b1['in_store'] ?? '')) === 1, 'the store now holds a key', var_export($b1, true));
t('…the running process saw the same one',                   $b1['in_config'] ?? null, $b1['in_store'] ?? '?');
t('…and the shared secret was not touched',                  $b1['shared_kept'] ?? null, $shared);
$b2 = $boot();
t('a second boot keeps the key',                             $b2['in_store'] ?? null, $b1['in_store'] ?? '?');

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
