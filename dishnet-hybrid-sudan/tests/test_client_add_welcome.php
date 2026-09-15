<?php
declare(strict_types=1);
/**
 * test_client_add_welcome.php — a customer never receives our variable names.
 *
 * On 15 Sep a colleague created a client by hand in uCRM while chatting with
 * him. The client.add webhook fired and the plugin sent him its "welcome":
 *
 *     EVENT CLIENT ADD
 *     To: Julius Peter
 *     Customer name: Julius Peter
 *     Crm id: 13
 *
 * NotificationService::send() had no template behind it. It built a line per
 * variable — a debugging format — and sent that. The overdue follow-ups used
 * the same method and were going out as the dump with their real text tacked
 * on as "Raw message:". Now send() sends exactly the text it is handed in
 * _raw_message, or nothing at all, and the client.add handler writes a real
 * welcome.
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
require_once $root . '/lib/currency.php';
require_once $root . '/lib/CustomerContact.php';
require_once $root . '/lib/NotificationService.php';

$tmp = sys_get_temp_dir() . '/welcome_' . getmypid();
exec('rm -rf ' . escapeshellarg($tmp)); @mkdir($tmp, 0777, true);
register_shutdown_function(function () use ($tmp) { exec('rm -rf ' . escapeshellarg($tmp)); });
$store = SqliteStore::create($tmp);
// Dry-run: the service logs what it would have sent and touches no network.
$svc = new NotificationService($store, [
    'dry_run_mode' => true, 'data_dir' => $tmp,
    'wa_plugin_url' => 'http://127.0.0.1:1/', 'wa_app_key' => 'k', 'wa_auth_key' => 'a',
]);
$dry = function () use ($tmp): array { return json_decode((string)@file_get_contents($tmp . '/dry_run_notification_log.json'), true) ?: []; };

// ═════════════════════════════════════════════════
echo "\n1. send(): the caller's text, or nothing\n";
// ═════════════════════════════════════════════════
$svc->send('event_client_add', '256700000000', 'Julius Peter', ['customer_name' => 'Julius Peter', 'crm_id' => '13']);
t('with no text, nothing is sent — not a dump of the variables',   count($dry()), 0);
$text = "🎉 *Welcome to DishNet!*\n\nHi Julius Peter,\n\nYour account has been created.";
$svc->send('event_client_add', '256700000000', 'Julius Peter', ['customer_name' => 'Julius Peter', 'crm_id' => '13', '_raw_message' => $text]);
$log = $dry();
t('with text, exactly one message goes out',                        count($log), 1);
t('…and it is exactly the text',                                    $log[0]['message'] ?? null, $text);
is_(strpos((string)json_encode($log[0]), 'Crm id') === false && strpos((string)($log[0]['message'] ?? ''), 'EVENT') === false,
    'none of the variable names, none of the event name');
is_(!isset($log[0]['vars']['_raw_message']),                        'the text is not duplicated into the logged variables');
t('the other variables still travel for the audit trail',          $log[0]['vars']['crm_id'] ?? null, '13');

// ═════════════════════════════════════════════════
echo "\n2. The callers write their text\n";
// ═════════════════════════════════════════════════
$wh    = codeNC($root . '/webhook.php');
$start = strpos($wh, "case 'client.add':"); $end = strpos($wh, "case 'invoice.add':");
is_($start !== false && $end !== false && $end > $start, 'the client.add handler is where it was');
$blk = substr($wh, (int)$start, (int)$end - (int)$start);
is_(strpos($blk, "'_raw_message'") !== false,                       'client.add hands send() a message text');
is_(strpos($blk, 'Welcome to DishNet') !== false,                   '…a welcome');
is_(strpos($blk, 'CustomerContact::escalation(') !== false,         '…with the support contact from config, like the activation message');
$ns = codeNC($root . '/lib/NotificationService.php');
is_(strpos($ns, 'strtoupper($event)') === false,                    'the dump format is gone from NotificationService');
foreach (['cron_overdue_email.php', 'includes/api/api_crm_misc.php'] as $f) {
    is_(strpos(codeNC($root . '/' . $f), "'_raw_message'") !== false, "$f still hands over its own text");
}

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
