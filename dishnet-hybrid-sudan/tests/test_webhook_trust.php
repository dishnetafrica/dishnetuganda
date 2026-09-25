<?php
declare(strict_types=1);
/**
 * test_webhook_trust.php — the uCRM webhook acts on what uCRM says, never on
 * what was posted (5.18.37, Phase 1 of the customer-login audit; P-25).
 *
 * Until 5.18.37 public.php?page=crm_webhook accepted a request with no key at
 * all (many uCRM versions send none) and every handler read the entity from
 * the posted body: a POST shaped like payment.add posted a payment into the
 * cashbook, one shaped like payment.delete reversed a real receipt, and one
 * shaped like client.message sent a WhatsApp message to a customer in
 * DishNet's name.
 *
 * Now every handler re-reads its entity from uCRM by id and works on THAT; an
 * id uCRM does not know, or a uCRM that cannot be reached, skips the event
 * with a 200 (uCRM keeps its own log; nothing is acted on from an unverified
 * copy). payment.delete reverses only a payment uCRM answers 404 for.
 * client.message, whose text cannot be re-read, needs the new mandatory key.
 * And the operator may set that key — crm_webhook_key — after which a
 * request without it is refused outright.
 *
 * The uCRM here is tests/fixtures/fake_ucrm_entities.php: it knows payment
 * 501 (client 7, UGX 50,000, cash) and nothing about payment 9999.
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
require_once $root . '/lib/StoreInterface.php';
require_once $root . '/lib/SqliteStore.php';
require_once $root . '/lib/ConfigVault.php';
require_once $root . '/lib/PluginConfig.php';

$http = function (string $method, string $url, $json = null, array $headers = []): array {
    $ch = curl_init($url);
    $o = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_PROXY => '', CURLOPT_CUSTOMREQUEST => $method,
          CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json'], $headers)];
    if ($json !== null) $o[CURLOPT_POSTFIELDS] = is_string($json) ? $json : json_encode($json);
    curl_setopt_array($ch, $o);
    $r = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return ['code' => $r === false ? 0 : $code, 'json' => is_string($r) ? json_decode($r, true) : null, 'raw' => is_string($r) ? $r : ''];
};
$startServer = function (string $cmd, callable $ready, int $baseport, int $step): array {
    foreach (range(0, 9) as $slot) {
        $cand = $baseport + ((getmypid() + $slot * $step) % 200);
        $p = proc_open(str_replace('{PORT}', (string)$cand, $cmd), [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        $ok = false;
        for ($i = 0; $i < 60; $i++) { if ($ready($cand)) { $ok = true; break; } usleep(100000); }
        if ($ok) return [$p, $cand];
        if (is_resource($p)) { proc_terminate($p); proc_close($p); }
    }
    return [null, 0];
};

// ── the fake uCRM ────────────────────────────────────────────────────────────
array_map('unlink', glob(sys_get_temp_dir() . '/fake_ucrm_entities_*.json') ?: []);
[$crmSrv, $crmPort] = $startServer(sprintf('exec php -S 127.0.0.1:{PORT} %s', escapeshellarg($root . '/tests/fixtures/fake_ucrm_entities.php')),
    function (int $port) use ($http): bool { return ($http('GET', "http://127.0.0.1:{$port}/__test/requests")['json']['count'] ?? null) !== null; }, 10300, 17);
if ($crmSrv === null) { echo "  FAIL could not start the fake uCRM\n"; exit(1); }
register_shutdown_function(function () use (&$crmSrv) { if (is_resource($crmSrv)) { proc_terminate($crmSrv); proc_close($crmSrv); } });
$crmBase  = "http://127.0.0.1:{$crmPort}";
$crmReqs  = function () use ($http, $crmBase): array { return $http('GET', "{$crmBase}/__test/requests")['json']['requests'] ?? []; };
$crmReset = function () use ($http, $crmBase): void { $http('GET', "{$crmBase}/__test/reset"); };
$crmDown  = function (bool $on) use ($http, $crmBase): void { $http('GET', "{$crmBase}/__test/unreachable?on=" . ($on ? '1' : '0')); };

// ── the plugin, served by public.php the way uCRM serves it ──────────────────
$sandbox = sys_get_temp_dir() . '/dn_whtrust_' . getmypid();
$tmp     = $sandbox . '/plugin';
$data    = $tmp . '/data';
exec('rm -rf ' . escapeshellarg($sandbox)); @mkdir($data, 0700, true);
register_shutdown_function(function () use ($sandbox) { exec('rm -rf ' . escapeshellarg($sandbox)); });
exec(sprintf('cp -R %s/. %s/ 2>/dev/null', escapeshellarg($root), escapeshellarg($tmp)));
exec('rm -rf ' . escapeshellarg($data)); @mkdir($data, 0700, true);
file_put_contents($tmp . '/ucrm.json', json_encode(['pluginDataDir' => $data, 'ucrmLocalUrl' => $crmBase, 'ucrmPublicUrl' => $crmBase, 'pluginAppKey' => 'test-app-key']));
$baseConfig = [
    'dry_run_mode' => true, 'data_dir' => $data,
    'wa_plugin_url' => 'http://127.0.0.1:1/', 'wa_app_key' => 'k', 'wa_auth_key' => 'a',
    'crm_public_url' => 'https://crm.example.test',
];
$setConfig = function (array $extra) use ($data, $baseConfig): void {
    $s = SqliteStore::create($data);
    $cur = $s->load('kyc_config.json') ?? [];
    $s->save('kyc_config.json', array_merge($cur, $baseConfig, $extra));
};
$setConfig([]);
$nonce = bin2hex(random_bytes(8));
file_put_contents($tmp . '/__nonce.txt', $nonce);
[$webSrv, $webPort] = $startServer(sprintf('exec php -S 127.0.0.1:{PORT} -t %s', escapeshellarg($tmp)),
    function (int $port) use ($http, $nonce): bool { $r = $http('GET', "http://127.0.0.1:{$port}/__nonce.txt"); return $r['code'] === 200 && trim($r['raw']) === $nonce; }, 10500, 19);
@unlink($tmp . '/__nonce.txt');
if ($webSrv === null) { echo "  FAIL could not start the plugin under php -S\n"; exit(1); }
register_shutdown_function(function () use (&$webSrv) { if (is_resource($webSrv)) { proc_terminate($webSrv); proc_close($webSrv); } });
$hook = "http://127.0.0.1:{$webPort}/public.php?page=crm_webhook";

$fire = function (string $changeType, string $entityType, int $entityId, array $entity = [], array $headers = []) use ($http, $hook): array {
    $payload = ['changeType' => $changeType, 'entity' => $entityType, 'entityId' => $entityId,
                'uuid' => 'test-' . $changeType . '-' . $entityId . '-' . bin2hex(random_bytes(3)),
                'extraData' => ['entity' => $entity + ['id' => $entityId]]];
    return $http('POST', $hook, $payload, $headers);
};
$whLog  = function () use ($data): array { return json_decode((string)@file_get_contents($data . '/webhook_log.json'), true) ?: []; };
$logHas = function (string $needle) use ($whLog): bool {
    foreach ($whLog() as $row) if (strpos((string)($row['message'] ?? ''), $needle) !== false || (string)($row['event'] ?? '') === $needle) return true;
    return false;
};
$dryLog = function () use ($data): array { return json_decode((string)@file_get_contents($data . '/dry_run_notification_log.json'), true) ?: []; };
$ledger = function (string $where = '1=1', array $args = []) use ($data): array {
    try { $st = SqliteStore::create($data)->getPdo()->prepare("SELECT * FROM cb_ledger WHERE {$where}"); $st->execute($args); return $st->fetchAll(\PDO::FETCH_ASSOC); }
    catch (\Throwable $e) { return []; }
};
$paths = function () use ($crmReqs): array { return array_map(function ($r) { return $r['method'] . ' ' . $r['path']; }, $crmReqs()); };

// ═════════════════════════════════════════════════
echo "\n1. A forged payment.add — a body uCRM knows nothing about — does nothing\n";
// ═════════════════════════════════════════════════
$crmReset();
$forged = ['id' => 9999, 'clientId' => 7, 'amount' => 5000000, 'currencyCode' => 'UGX',
           'methodId' => '6efe0fa8-36b2-4dd1-b049-427bffc7d369', 'methodName' => 'Cash', 'note' => 'forged'];
$r = $fire('payment.add', 'payment', 9999, $forged);
t('answered 200 — uCRM must not retry it',                 $r['code'], 200);
is_(strpos((string)($r['json']['message'] ?? ''), 'could not be verified') !== false, '…as "could not be verified with uCRM — skipped"', $r['raw']);
t('nothing reached the cashbook',                          $ledger(), []);
t('no WhatsApp message was composed',                      $dryLog(), []);
is_($logHas('entity_unverified'), 'the webhook log records the unverified event');
$p = $paths();
is_(in_array('GET /payments/9999', $p, true) && in_array('GET /billing/payments/9999', $p, true), 'uCRM was asked for the payment, on both path forms', json_encode($p));
is_(!in_array('GET /clients/7', $p, true), '…and never for the client the forgery named — the posted body steered nothing');

// ═════════════════════════════════════════════════
echo "\n2. A real payment.add is processed from uCRM's copy, not the posted one, and only once\n";
// ═════════════════════════════════════════════════
$crmReset();
$r = $fire('payment.add', 'payment', 501, ['id' => 501, 'clientId' => 7, 'amount' => 5000000, 'currencyCode' => 'UGX',
    'methodId' => '6efe0fa8-36b2-4dd1-b049-427bffc7d369', 'methodName' => 'Cash']);
t('answered 200',                                          $r['code'], 200);
is_(strpos((string)($r['json']['message'] ?? ''), 'could not be verified') === false, '…and processed, not skipped', $r['raw']);
is_(in_array('GET /clients/7', $paths(), true), 'the client was read from uCRM for the receipt');
$log = $dryLog();
t('one WhatsApp thank-you was composed (dry-run)',         count($log), 1);
$thanks = (string)($log[0]['message'] ?? '');
is_(strpos($thanks, '5,000,000') === false && strpos($thanks, '5000000') === false, '…never mentioning the 5,000,000 the body claimed');
is_(strpos($thanks, '50,000') !== false || strpos($thanks, '50000') !== false, '…but uCRM\'s 50,000', $thanks);
is_($logHas('Payment thanks sent: UGX 50000'), 'the webhook log agrees: UGX 50000 from uCRM\'s copy');
// The cashbook auto-post itself is not asserted here: the fixture's payment
// carries uCRM's integer `method` and no `methodName`, and the auto-post's
// trim() on it throws under strict_types before any row is written (a
// pre-existing condition, caught and logged as cashbook_error, recorded in the
// Phase 1 report; not Phase 1's to change). Nothing forged reaches it either way.
$r = $fire('payment.add', 'payment', 501, ['id' => 501, 'clientId' => 7, 'amount' => 50000]);
t('a replay of the same event: still one thank-you',      count($dryLog()), 1);
is_($logHas('Payment notification SKIPPED — already sent for PAY-501'), '…the webhook log says it was skipped as already sent');

// ═════════════════════════════════════════════════
echo "\n3. When uCRM cannot be reached, nothing is acted on\n";
// ═════════════════════════════════════════════════
$crmDown(true);
$before = count($whLog());
$r = $fire('payment.add', 'payment', 501, ['id' => 501, 'clientId' => 7, 'amount' => 50000]);
t('answered 200',                                          $r['code'], 200);
is_(strpos((string)($r['json']['message'] ?? ''), 'could not be verified') !== false, '…as skipped: an outage is not a verification', $r['raw']);
$r = $fire('payment.delete', 'payment', 501);
is_(strpos((string)($r['json']['message'] ?? ''), 'could not confirm') !== false, 'payment.delete during the outage: "uCRM could not confirm the deletion; skipped"', $r['raw']);
t('no message was composed during the outage',            count($dryLog()), 1);
is_(!$logHas('Cashbook reversal posted'), '…and nothing was reversed');
$crmDown(false);

// ═════════════════════════════════════════════════
echo "\n4. payment.delete reverses only a payment uCRM says is gone\n";
// ═════════════════════════════════════════════════
$r = $fire('payment.delete', 'payment', 501);
t('payment 501 still exists in uCRM: answered 200',       $r['code'], 200);
is_(strpos((string)($r['json']['message'] ?? ''), 'still exists') !== false, '…"payment still exists in uCRM; skipped"', $r['raw']);
is_(!$logHas('Cashbook reversal posted') && $ledger("direction = 'out'") === [], '…no reversal was posted');
$r = $fire('payment.delete', 'payment', 777);
t('payment 777, which uCRM answers 404 for: processed',    [$r['code'], strpos((string)($r['json']['message'] ?? ''), 'payment.delete processed') === 0], [200, true]);
is_($logHas('Processing CRM payment deletion #777'), '…the deletion path ran (nothing of ours to reverse, and it says so)');

// ═════════════════════════════════════════════════
echo "\n5. client.message: its text cannot be re-read, so it needs the mandatory key\n";
// ═════════════════════════════════════════════════
$n = count($dryLog());
$r = $fire('client.message', 'client', 7, ['clientId' => 7, 'message' => 'Please call this number now: it is urgent.']);
t('without a verified key: 200, ignored',                  [$r['code'], strpos((string)($r['json']['message'] ?? ''), 'ignored without a verified webhook key') !== false], [200, true]);
t('…and no WhatsApp message was composed',                 count($dryLog()), $n);

// ═════════════════════════════════════════════════
echo "\n6. crm_webhook_key: once set, a request without it is refused outright\n";
// ═════════════════════════════════════════════════
$KEY = bin2hex(random_bytes(32));
$setConfig(['crm_webhook_key' => $KEY]);
$r = $fire('payment.add', 'payment', 501, ['id' => 501]);
t('no key header: 401',                                    [$r['code'], $r['json']['message'] ?? null], [401, 'Unauthorized.']);
$r = $fire('payment.add', 'payment', 501, ['id' => 501], ['X-Crm-Key: ' . bin2hex(random_bytes(32))]);
t('a wrong key: 401',                                      $r['code'], 401);
$n0 = count($dryLog());
$r = $fire('payment.add', 'payment', 501, ['id' => 501], ['X-Crm-Key: ' . $KEY]);
t('the right key: the event is handled (and the thank-you still not sent twice)', [$r['code'], count($dryLog())], [200, $n0]);
$r = $fire('client.message', 'client', 7, ['clientId' => 7, 'message' => 'Your installation is confirmed for Monday.'], ['X-Crm-Key: ' . $KEY]);
t('client.message with the key: forwarded',               [$r['code'], $r['json']['message'] ?? null], [200, 'client.message processed.']);
$log = $dryLog();
t('…one WhatsApp message composed for the customer',      count($log), $n + 1);
is_(strpos((string)($log[count($log) - 1]['message'] ?? ''), 'Your installation is confirmed for Monday.') !== false, '…carrying the message text');
// uCRM sends one secret header. With the older optional webhook_secret ALSO set
// to some other value, the mandatory key must still be enough.
$setConfig(['crm_webhook_key' => $KEY, 'webhook_secret' => 'older-optional-secret-' . bin2hex(random_bytes(4))]);
$r = $fire('payment.add', 'payment', 501, ['id' => 501], ['X-Crm-Key: ' . $KEY]);
t('with webhook_secret also set to another value, the mandatory key alone is accepted', $r['code'], 200);
$r = $fire('payment.add', 'payment', 501, ['id' => 501]);
t('…and no key is still 401',                              $r['code'], 401);
$setConfig(['crm_webhook_key' => '', 'webhook_secret' => '']);
$r = $fire('payment.add', 'payment', 501, ['id' => 501]);
t('key cleared: keyless requests are accepted again, still re-read from uCRM', $r['code'], 200);

// ═════════════════════════════════════════════════
echo "\n7. Other forged events are skipped the same way\n";
// ═════════════════════════════════════════════════
$n = count($dryLog()); $rowsBefore = count($ledger());
foreach ([['client.add', 'client', 424242, ['firstName' => 'Nobody']],
          ['invoice.add', 'invoice', 424242, ['clientId' => 7, 'total' => 1]],
          ['service.suspend', 'service', 424242, ['clientId' => 7]],
          ['quote.add', 'quote', 424242, ['clientId' => 7]],
          ['ticket.add', 'ticket', 424242, ['clientId' => 7, 'subject' => 'x']],
          ['credit_note.add', 'credit_note', 424242, ['clientId' => 7]],
          ['job.add', 'job', 424242, ['clientId' => 7]]] as [$ev, $type, $id, $body]) {
    $r = $fire($ev, $type, $id, $body);
    t("forged {$ev} #{$id}: 200, skipped as unverified", [$r['code'], strpos((string)($r['json']['message'] ?? ''), 'could not be verified') !== false], [200, true], );
}
t('…no message composed by any of them',                  count($dryLog()), $n);
t('…no cashbook row by any of them',                      count($ledger()), $rowsBefore);

// ═════════════════════════════════════════════════
echo "\n8. The source: every business handler verifies; the key is a secret; the CLI\n";
// ═════════════════════════════════════════════════
$wh = codeNC($root . '/webhook.php');
$verified = substr_count($wh, 'whVerified(') - 1;   // minus the definition
t('fifteen handler sites pass their entity through whVerified() — pinned; a new handler must be added here deliberately', $verified, 15);
foreach (['payment.add', 'payment.delete', 'client.add', 'client.edit', 'invoice.add', 'invoice.draft_approved', 'service.add', 'service.suspend',
          'service.end', 'quote.add', 'quote.approve', 'job.add', 'ticket.add', 'credit_note.add'] as $ev) {
    $at = strpos($wh, "case '{$ev}':");
    $blk = substr($wh, (int)$at, 1800);
    is_($at !== false && (strpos($blk, 'whVerified(') !== false || strpos($blk, 'whFetchFirst(') !== false), "the {$ev} handler re-reads from uCRM before acting");
}
t('the whole posted entity is a fallback only in the cache-refresh block (twice, cache only)', preg_match_all('/\?\?\s*\$entity\s*;/', $wh), 2);
$msgAt = strpos($wh, "case 'client.message':");
is_(strpos(substr($wh, (int)$msgAt, 600), 'if (!$whKeyVerified)') !== false, 'client.message is gated on the verified key');
is_(in_array('crm_webhook_key', ConfigVault::VAULT_KEYS, true), 'crm_webhook_key is a vault key (survives a re-install)');
is_(in_array('crm_webhook_key', PluginConfig::SECRET_KEYS, true), '…and a secret to PluginConfig');
t('…so redacted() hides it', PluginConfig::redacted(['crm_webhook_key' => 'x'])['crm_webhook_key'] ?? null, '[set]');
[$okSO] = PluginConfig::saveOverrides($sandbox . '/never-created', ['crm_webhook_key' => 'x']);
t('…and saveOverrides() refuses to write it', $okSO, false);

// The CLI that sets it, against its own data directory. Its output is captured,
// never echoed: the key it prints once is the one thing that must not appear here.
$cliData = $sandbox . '/cli-data'; @mkdir($cliData, 0700, true);
$env = array_merge(getenv(), ['DN_DATA_DIR' => $cliData, 'DN_VAULT_FILE' => (string)getenv('DN_VAULT_FILE')]);
$cli = function (string $args) use ($root, $env): array {
    $p = proc_open('php ' . escapeshellarg($root . '/tools/crm_webhook_key.php') . ' ' . $args, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root, $env);
    $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
    return [proc_close($p), $out, $err];
};
[$code, $out] = $cli('--status');
t('--status on a fresh install: not set',                  [$code, strpos($out, 'not set') !== false], [0, true]);
[$code, $out] = $cli('--generate');
$stored = trim((string)((SqliteStore::create($cliData)->load('kyc_config.json') ?? [])['crm_webhook_key'] ?? ''));
t('--generate stores a 64-hex key',                        [$code, strlen($stored), ctype_xdigit($stored)], [0, 64, true]);
is_(strpos($out, $stored) !== false, '…and shows it once, for uCRM');
$vault = json_decode((string)@file_get_contents((string)getenv('DN_VAULT_FILE')), true) ?: [];
t('…and vaults it',                                        $vault['config']['crm_webhook_key'] ?? null, $stored);
[$code, $out] = $cli('--status');
is_($code === 0 && strpos($out, 'set (64 characters, value withheld)') !== false && strpos($out, $stored) === false, '--status afterwards: set, value withheld');
[$code] = $cli('--generate');
t('a second --generate without --force refuses (uCRM holds the first key)', $code, 1);
[$code, $out] = $cli('--clear');
$after = (SqliteStore::create($cliData)->load('kyc_config.json') ?? [])['crm_webhook_key'] ?? null;
t('--clear removes it',                                    [$code, $after], [0, null]);

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
