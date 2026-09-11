<?php
/**
 * test_email_settings_writer.php — the Settings screen that changed nothing.
 *
 * There were two copies of the email settings. The admin form read and wrote
 * the SQLite store. MailService, the dunning cron, the quote sender and every
 * doctor read the FILE. Nothing carried a change from one to the other.
 *
 * So the form worked perfectly and did nothing: type a new SMTP host, press
 * Save, get a green "Email settings saved", and mail keeps going out through
 * the old one. Every configuration that has ever actually taken effect on
 * this install was made from the command line, and that was not a preference.
 *
 * Two further ways the old save destroyed things, both kept out by force here:
 *
 *   It rebuilt the settings from the nine fields the form knows. Anything
 *   else on disk — sent_copy_enabled, system_from — was deleted by omission.
 *
 *   It took the password from the STORE when the form's box was left blank.
 *   The store's copy can be empty while the file's is correct, and writing
 *   that empty one takes all customer mail down silently.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/lib/StoreInterface.php';
require_once $root . '/lib/JsonStore.php';
require_once $root . '/lib/SqliteStore.php';
require_once $root . '/lib/EmailSettingsWriter.php';

$pass = 0; $fail = 0;
function ok(string $m): void  { global $pass; $pass++; echo "  ok   $m\n"; }
function bad(string $m, string $d=''): void { global $fail; $fail++; echo "  FAIL $m" . ($d ? "\n       $d" : '') . "\n"; }
function is_(bool $c, string $m, string $d=''): void { $c ? ok($m) : bad($m, $d); }

$tmp = sys_get_temp_dir() . '/dn_esw_' . bin2hex(random_bytes(4));
@mkdir($tmp, 0777, true);
$file  = $tmp . '/email_settings.json';
$store = SqliteStore::create($tmp);

$onDisk = function () use ($file): array {
    clearstatcache();
    return json_decode((string)@file_get_contents($file), true) ?: [];
};

// A working install: the file holds the truth, the store holds something
// older and thinner — which is exactly the live situation.
file_put_contents($file, json_encode([
    'smtp_host'          => 'smtp-relay.brevo.com',
    'smtp_port'          => 587,
    'smtp_user'          => 'relay-user',
    'smtp_pass'          => 'THE-WORKING-KEY',
    'smtp_enc'           => 'tls',
    'smtp_from'          => 'accounts@dishnetuganda.com',
    'sent_copy_enabled'  => true,
    'system_from'        => 'no-reply@dishnetuganda.com',
], JSON_PRETTY_PRINT));
$store->save('email_settings.json', [
    'smtp_host' => 'smtp.gmail.com', 'smtp_user' => 'old@gmail.com', 'smtp_pass' => '',
    'recipients' => 'ops@dishnetuganda.com',
]);

echo "\n── read: the file is what sends mail, so the file wins ──\n";
$r = EmailSettingsWriter::read($tmp, $store);
is_(($r['smtp_host'] ?? '') === 'smtp-relay.brevo.com',
    'the host on the screen is the host mail goes through',
    'got: ' . (string)($r['smtp_host'] ?? ''));
is_(($r['smtp_pass'] ?? '') === 'THE-WORKING-KEY', 'and the password is the working one');
is_(($r['recipients'] ?? '') === 'ops@dishnetuganda.com',
    'a key only the store has still shows — the store fills gaps');

echo "\n── read: no file yet ──\n";
$empty = sys_get_temp_dir() . '/dn_esw_none_' . bin2hex(random_bytes(4));
@mkdir($empty, 0777, true);
$s2 = SqliteStore::create($empty);
$s2->save('email_settings.json', ['smtp_host' => 'only.in.store']);
is_((EmailSettingsWriter::read($empty, $s2)['smtp_host'] ?? '') === 'only.in.store',
    'an install that has only ever used the form still reads back');
is_(EmailSettingsWriter::read($empty . '/nope', null) === [],
    'nothing anywhere is an empty array, not a crash');
exec('rm -rf ' . escapeshellarg($empty));

echo "\n── save: the file actually changes ──\n";
$res = EmailSettingsWriter::save($tmp, $store, [
    'smtp_host' => 'smtp.example.net',
    'smtp_port' => 25,
    'smtp_pass' => '',          // the form's box, left blank
]);
is_(!empty($res['ok']), 'the save reports success', (string)($res['error'] ?? ''));
$d = $onDisk();
is_(($d['smtp_host'] ?? '') === 'smtp.example.net',
    'THE FILE changed — this is the whole bug', 'got: ' . (string)($d['smtp_host'] ?? ''));
is_(($d['smtp_port'] ?? 0) === 25, 'and so did the port');
is_(($store->load('email_settings.json')['smtp_host'] ?? '') === 'smtp.example.net',
    'the store changed too, so the screen and the sender agree');

echo "\n── save: the password is never blanked by an empty box ──\n";
is_(($d['smtp_pass'] ?? '') === 'THE-WORKING-KEY',
    'a blank password box left the working key alone',
    'got: ' . json_encode($d['smtp_pass'] ?? null));
is_(!in_array('smtp_pass', $res['changed'], true), 'and is not reported as changed');

$res2 = EmailSettingsWriter::save($tmp, $store, ['smtp_pass' => 'A-NEW-KEY']);
is_(($onDisk()['smtp_pass'] ?? '') === 'A-NEW-KEY', 'a filled password box does change it');
is_(in_array('smtp_pass', $res2['changed'], true), 'and is reported as changed');
EmailSettingsWriter::save($tmp, $store, ['smtp_pass' => 'THE-WORKING-KEY']);

echo "\n── save: keys the form never heard of survive ──\n";
$d = $onDisk();
is_(($d['sent_copy_enabled'] ?? null) === true,
    'sent_copy_enabled is still there after two saves that never mentioned it');
is_(($d['system_from'] ?? '') === 'no-reply@dishnetuganda.com',
    'and so is system_from — OTP mail does not silently move back');

echo "\n── save: only form keys are accepted ──\n";
EmailSettingsWriter::save($tmp, $store, ['smtp_host' => 'smtp.example.net', 'evil_key' => 'x']);
is_(!array_key_exists('evil_key', $onDisk()),
    'a key the form does not own is ignored, not written through');

echo "\n── save: a backup, every time, and they do not overwrite each other ──\n";
$backups = glob($tmp . '/email_settings.json.bak.*');
sort($backups);
is_(count($backups) >= 4, 'one per save so far — this file is the whole mail system',
    'got ' . count($backups) . ' for 4+ saves');
$b = json_decode((string)file_get_contents($backups[0]), true) ?: [];
is_(($b['smtp_host'] ?? '') === 'smtp-relay.brevo.com',
    'the oldest holds what was there before the first save — same-second saves '
    . 'used to overwrite it', 'got: ' . (string)($b['smtp_host'] ?? ''));

// Saves in a tight loop are the case that collides.
for ($i = 0; $i < 12; $i++) EmailSettingsWriter::save($tmp, $store, ['smtp_port' => 500 + $i]);
$backups = glob($tmp . '/email_settings.json.bak.*');
is_(count($backups) === 10, 'the pile is capped at ten, so a data directory cannot fill',
    'got ' . count($backups));

echo "\n── save: changed lists what moved and nothing else ──\n";
$res3 = EmailSettingsWriter::save($tmp, $store, [
    'smtp_host'   => 'smtp.example.net',              // same as now
    'system_from' => 'otp@dishnetuganda.com',         // different
]);
is_($res3['changed'] === ['system_from'],
    'only the field that actually differs is reported',
    'got: ' . json_encode($res3['changed']));

echo "\n── save: no store at all ──\n";
$solo = sys_get_temp_dir() . '/dn_esw_solo_' . bin2hex(random_bytes(4));
@mkdir($solo, 0777, true);
$res4 = EmailSettingsWriter::save($solo, null, ['smtp_host' => 'cli.example.com']);
is_(!empty($res4['ok']) && ($res4['backup'] ?? 'x') === '',
    'a first write with no file to back up still succeeds');
is_((json_decode((string)file_get_contents($solo . '/email_settings.json'), true)['smtp_host'] ?? '') === 'cli.example.com',
    'and lands on disk — a CLI caller needs no store');
exec('rm -rf ' . escapeshellarg($solo));

echo "\n── the Settings screen is wired to this ──\n";
$tab = (string)file_get_contents($root . '/tabs/admin/settings.php');
is_(strpos($tab, 'EmailSettingsWriter::save(') !== false,
    'the form saves through the writer');
is_(preg_match('/save_email_settings.{0,2400}\$store->save\(\s*\'email_settings\.json\'/s', $tab) !== 1,
    'and no longer writes the store on its own, which was the no-op');
is_(strpos($tab, "EmailSettingsWriter::read(") !== false,
    'and reads the file, so the screen shows what actually sends');
is_(strpos($tab, 'name="system_from"') !== false,
    'the system sender has a field on the screen');

exec('rm -rf ' . escapeshellarg($tmp));
echo "\n  {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
