<?php
/**
 * test_alert_target_guard.php — never alert a number the assistant is talking to.
 *
 * One handset was set as the handover alert target while it was also
 * conversation c109, a live thread the assistant answers. Alerts landed in that
 * thread, were read as customer messages, and were answered — twenty-four
 * messages of the assistant in conversation with its own alert channel, while
 * five real customers waited for a human nobody had told.
 *
 * The number itself was not the fault; it was the operator's own phone. What
 * made it a fault was the overlap. So the guard is the overlap, not a
 * blocklist — a blocklist would have to be updated after each new instance of
 * the same mistake, which is not a guard.
 *
 * Runs the real tool as a subprocess, so what is asserted is what an operator
 * pasting the command actually gets.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$pass = 0; $fail = 0;
function ok(string $m): void  { global $pass; $pass++; echo "  ok   {$m}\n"; }
function bad(string $m, string $d=''): void { global $fail; $fail++; echo "  FAIL {$m}\n"; if($d)echo "       {$d}\n"; }
function is_(bool $c, string $m, string $d=''): void { $c ? ok($m) : bad($m, $d); }

$tmp = sys_get_temp_dir() . '/dn_alert_' . bin2hex(random_bytes(4));
@mkdir($tmp, 0777, true);

require_once $root . '/lib/StoreInterface.php';
require_once $root . '/lib/JsonStore.php';
require_once $root . '/lib/SqliteStore.php';
require_once $root . '/lib/ConversationService.php';

$store = SqliteStore::create($tmp);
$pdo   = $store->getPdo();
(new ConversationService($tmp, $pdo))->ensureConversation('211927797217', 'sales', null, 'test');
$pdo->exec("UPDATE wa_conversations SET message_count = 24 WHERE phone LIKE '%927797217'");

/** Run the real tool with this data dir. Returns [exitCode, output]. */
$run = function (array $args) use ($root, $tmp): array {
    $cmd = 'DN_DATA_DIR=' . escapeshellarg($tmp) . ' php '
         . escapeshellarg($root . '/tools/set_alert_number.php') . ' '
         . implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1';
    exec($cmd, $out, $code);
    return [$code, implode("\n", $out)];
};
// PluginConfig::saveOverrides writes kyc_config.json in the data dir.
$isSet = function () use ($tmp): string {
    $j = @json_decode((string)@file_get_contents($tmp . '/kyc_config.json'), true);
    return is_array($j) ? (string)($j['alert_whatsapp'] ?? '') : '';
};

echo "\nA number the assistant is already talking to is refused\n";
[$code, $out] = $run(['--to', '211927797217']);
is_($code !== 0, 'the tool exits non-zero');
is_(strpos($out, 'c1') !== false || stripos($out, 'conversation') !== false,
    'and names the conversation rather than just refusing', $out);
is_(strpos($out, '24 message') !== false, 'with the size of the thread it would land in');
is_($isSet() === '', 'nothing was saved', 'a refusal that still writes is not a refusal');

echo "\nIt says how to proceed anyway, because sometimes it is the right number\n";
is_(strpos($out, '--force') !== false, 'the escape hatch is printed');

echo "\nAnd --force does proceed\n";
// The operator's own handset, thread closed. Their call to make.
[$code2, $out2] = $run(['--to', '211927797217', '--force']);
is_($code2 === 0, 'it exits clean');
is_($isSet() === '211927797217', 'and the number is saved', 'got: "' . $isSet() . '"');

echo "\nA number with no conversation is set with no argument\n";
[$code3, $out3] = $run(['--to', '256772123456']);
is_($code3 === 0, 'it exits clean');
is_($isSet() === '256772123456', 'and is saved');

echo "\nMatching ignores formatting, the way identity lookup does\n";
// +211 927 797 217 and 0927797217 are one handset; the guard must see that.
[$code4, $out4] = $run(['--to', '+211 927 797 217']);
is_($code4 !== 0, 'a formatted version of the same number is still caught',
    'exit ' . $code4);
is_($isSet() === '256772123456', 'and the previous setting is untouched');

echo "\nRubbish is still rubbish\n";
[$code5, $out5] = $run(['--to', 'the-handset-to-wake']);
is_($code5 !== 0, 'a pasted placeholder is refused');
is_($isSet() === '256772123456', 'and cannot silently empty the setting',
    'this is exactly how alerts stopped reaching anyone before');

exec('rm -rf ' . escapeshellarg($tmp));
printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
