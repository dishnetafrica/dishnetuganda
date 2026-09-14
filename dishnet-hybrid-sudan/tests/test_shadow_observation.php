<?php
declare(strict_types=1);
/**
 * test_shadow_observation.php — reading the shadow log back, without reading
 * anything out of it.
 *
 * The observation window turns the shadow log into counts a person can make a
 * migration decision from. The report is the one artefact of this whole phase
 * that a human will paste into a message, so it is the one place where a
 * value that slipped into the log would actually travel.
 *
 * So the central test is adversarial: a log stuffed with customer values in
 * lines shaped almost like shadow lines. The reader must count them as
 * unparseable, print none of them, and say so loudly.
 */
$pass = 0; $fail = 0;
function is_(bool $c, string $m): void { global $pass, $fail;
    if ($c) { $pass++; echo "  ok   $m\n"; } else { $fail++; echo "  FAIL $m\n"; } }
function t(string $n, $got, $want) { global $pass, $fail;
    if ($got === $want) { $pass++; printf("  ok   %s\n", $n); }
    else { $fail++; printf("  FAIL %s\n       got  %s\n       want %s\n", $n, var_export($got, true), var_export($want, true)); } }

$root = dirname(__DIR__);
require_once $root . '/lib/ShadowObservation.php';

// No test may reach the real vault; run.sh does this for the suite, and this
// covers a standalone run of just this file.
if (!getenv('DN_VAULT_FILE')) putenv('DN_VAULT_FILE=' . tempnam(sys_get_temp_dir(), 'dn-vault-'));

$tmp = sys_get_temp_dir() . '/shadow_obs_' . getmypid();
exec('rm -rf ' . escapeshellarg($tmp));
@mkdir($tmp, 0777, true);
$logFile = $tmp . '/ai_platform.log';

$line = function (string $ts, string $lvl, string $msg): string {
    return "[{$ts}] [AiReplyWorker] [{$lvl}] {$msg}\n";
};

// ════════════════════════════════════════════════════════════════════════
echo "\n1. Counting what the shadow saw\n";
// ════════════════════════════════════════════════════════════════════════

$log  = $line('2026-09-01 08:00:00', 'info', 'conv 5: in channel=account len=12');
$log .= $line('2026-09-01 08:00:01', 'warn', 'conv 5: shadow account balance=same invoice=differ:number payment=same');
$log .= $line('2026-09-01 09:00:00', 'warn', 'conv 6: shadow account balance=same invoice=differ:number+amount payment=legacy_only');
$log .= $line('2026-09-01 10:00:00', 'warn', 'conv 7: shadow account balance=differ:sign invoice=differ:number payment=same');
$log .= $line('2026-09-02 11:00:00', 'warn', 'conv 8: shadow support plan=same service_status=differ:unmapped_status');
$log .= $line('2026-09-02 11:05:00', 'info', 'conv 8: shadow support plan=same service_status=same');
$log .= $line('2026-09-02 12:00:00', 'warn', 'conv 9: shadow skipped — CRM not configured');
$log .= $line('2026-09-02 12:30:00', 'warn', 'conv 9: shadow failed — RuntimeException');
$log .= $line('2026-09-02 13:00:00', 'info', 'ai tokens in=8500 out=120 model=gpt-4o-mini');
file_put_contents($logFile, $log);

$r = ShadowObservation::aggregate($logFile);
t('every line was scanned', $r['lines_scanned'], 9);
// Seven: three accounts, two support, one skipped, one failed.
t('seven of them were shadow lines', $r['shadow_lines'], 7);
t('three accounts turns', $r['turns']['account'] ?? 0, 3);
t('two support turns', $r['turns']['support'] ?? 0, 2);
t('three distinct accounts conversations', count($r['conversations']['account'] ?? []), 3);
t('one distinct support conversation', count($r['conversations']['support'] ?? []), 1);
t('balance verdicts', $r['verdicts']['account']['balance'], ['same' => 2, 'differ' => 1]);
t('invoice verdicts', $r['verdicts']['account']['invoice'], ['differ' => 3]);
t('payment verdicts', $r['verdicts']['account']['payment'], ['same' => 2, 'legacy_only' => 1]);
t('invoice reasons', $r['reasons']['account']['invoice'], ['number' => 3, 'amount' => 1]);
t('balance reasons', $r['reasons']['account']['balance'], ['sign' => 1]);
t('the unmapped status was noticed', $r['reasons']['support']['service_status'], ['unmapped_status' => 1]);
t('one skipped', $r['skipped'], 1);
t('one failure, by class', $r['failed'], ['RuntimeException' => 1]);
t('nothing unparseable', $r['unparseable'], 0);
t('first seen', $r['first_seen'], '2026-09-01 08:00:01');
t('last seen',  $r['last_seen'],  '2026-09-02 12:30:00');

$r2 = ShadowObservation::aggregate($logFile, '2026-09-02 00:00:00');
// The second day holds two support turns, the skip and the failure.
t('--since drops the earlier day', $r2['in_window'], 4);
t('and leaves only support turns', array_keys($r2['turns']), ['support']);

// ════════════════════════════════════════════════════════════════════════
echo "\n2. HOSTILE: a log with customer values in shadow-shaped lines\n";
// ════════════════════════════════════════════════════════════════════════

$CANARIES = ['INV-ZZTOP-999', '8675309', 'ZCUSTOMERNAMEZ', '256701998877',
             '2031-07-04', 'ZPAYMETHODZ', '987654.32', 'ZPLANNAMEZ'];

$bad  = $line('2026-09-03 08:00:00', 'warn', 'conv 11: shadow account balance=differ:sign(987654.32) invoice=same');
$bad .= $line('2026-09-03 08:01:00', 'warn', 'conv 12: shadow account invoice=differ:number legacy=INV-ZZTOP-999 tool=8675309');
$bad .= $line('2026-09-03 08:02:00', 'warn', 'conv 13: shadow account balance=same {"amount":987654.32,"name":"ZCUSTOMERNAMEZ"}');
$bad .= $line('2026-09-03 08:03:00', 'warn', 'conv 14: shadow ZCUSTOMERNAMEZ balance=same');
$bad .= $line('2026-09-03 08:04:00', 'warn', 'conv 15: shadow account phone=256701998877');
$bad .= $line('2026-09-03 08:05:00', 'warn', 'conv 16: shadow support plan=differ:ZPLANNAMEZ');
$bad .= $line('2026-09-03 08:06:00', 'warn', 'conv 17: shadow account payment=differ:date:2031-07-04');
$bad .= $line('2026-09-03 08:07:00', 'warn', 'conv 18: shadow failed — could not read ZPAYMETHODZ for 256701998877');
// One good line among them, so the reader is not simply refusing everything.
$bad .= $line('2026-09-03 08:08:00', 'warn', 'conv 19: shadow account balance=same invoice=same payment=same');
file_put_contents($logFile, $bad);

$rb = ShadowObservation::aggregate($logFile);
t('eight shaped-but-wrong lines were refused', $rb['unparseable'], 8);
t('and the one real line was counted', $rb['turns']['account'] ?? 0, 1);
t('no support turn was invented from the bad one', $rb['turns']['support'] ?? 0, 0);
t('the malformed failure line is not counted as a failure class', $rb['failed'], []);

// The result, at every depth.
$leaves = function ($v, array &$acc = []) use (&$leaves) {
    if (is_array($v)) { foreach ($v as $k => $x) { $acc[] = (string)$k; $leaves($x, $acc); } return $acc; }
    if ($v === null || is_bool($v)) return $acc;
    $acc[] = (string)$v; return $acc;
};
$seen = $leaves($rb);
$found = [];
foreach ($CANARIES as $c) foreach ($seen as $s) if (strpos($s, $c) !== false) { $found[] = $c; break; }
t('not one canary is anywhere in the aggregate', array_values(array_unique($found)), []);

$report = ShadowObservation::render($rb, $logFile, '', null);
$inReport = [];
foreach ($CANARIES as $c) if (strpos($report, $c) !== false) $inReport[] = $c;
t('nor anywhere in the rendered report', $inReport, []);
is_(strpos($report, 'INVESTIGATE') !== false, 'and the report says to investigate');
is_(strpos($report, 'NOT printed here') !== false, 'and explains why it is not showing them');

// ════════════════════════════════════════════════════════════════════════
echo "\n3. A line is understood entirely, or not at all\n";
// ════════════════════════════════════════════════════════════════════════

$cases = [
    'an unknown fact'            => 'conv 20: shadow account ssn=same',
    'an unknown verdict'         => 'conv 21: shadow account balance=leaked',
    'an unknown reason'          => 'conv 22: shadow account balance=differ:ssn',
    'an unknown channel'         => 'conv 23: shadow billing balance=same',
    'a good fact beside a bad one' => 'conv 24: shadow account balance=same invoice=differ:oops',
    'no facts at all'            => 'conv 25: shadow account',
    'a trailing stray token'     => 'conv 26: shadow account balance=same ZCUSTOMERNAMEZ',
];
foreach ($cases as $why => $msg) {
    file_put_contents($logFile, $line('2026-09-04 08:00:00', 'warn', $msg));
    $c = ShadowObservation::aggregate($logFile);
    t("$why is refused whole", [$c['unparseable'], array_sum($c['turns'])], [1, 0]);
}

// 'other' is a channel ShadowCompare itself can emit, so it must be accepted.
file_put_contents($logFile, $line('2026-09-04 09:00:00', 'warn', 'conv 27: shadow other balance=absent'));
$c = ShadowObservation::aggregate($logFile);
t("ShadowCompare's own 'other' channel is understood", $c['turns']['other'] ?? 0, 1);

// ════════════════════════════════════════════════════════════════════════
echo "\n4. One customer is never a pattern\n";
// ════════════════════════════════════════════════════════════════════════

t('three conversations, every turn', ShadowObservation::spread(10, 10, 3), 'SYSTEMATIC');
t('three conversations, most turns', ShadowObservation::spread(6, 10, 3), 'WIDESPREAD');
t('three conversations, a few turns', ShadowObservation::spread(2, 10, 3), 'INTERMITTENT');
t('two conversations, every turn, is still not a pattern',
  ShadowObservation::spread(50, 50, 2), 'TOO FEW CUSTOMERS TO CLASSIFY');
t('one very chatty customer is not a pattern either',
  ShadowObservation::spread(500, 500, 1), 'TOO FEW CUSTOMERS TO CLASSIFY');
t('the threshold is stated, not hidden', ShadowObservation::MIN_CUSTOMERS, 3);

// The whole point of section 1's shape: invoice=number spans 3 conversations
// and every accounts turn, so it should read as systematic.
$r = ShadowObservation::aggregate($tmp . '/full.log');
file_put_contents($tmp . '/full.log', $log);
$r = ShadowObservation::aggregate($tmp . '/full.log');
$rep = ShadowObservation::render($r, $tmp . '/full.log', '', null);
is_(preg_match('/account\s+invoice\s+number\s+3 turns \/\s+3 conversations\s+SYSTEMATIC/', $rep) === 1,
    'invoice=number across three customers and every turn reads SYSTEMATIC');
is_(preg_match('/support\s+service_status\s+unmapped_status.*TOO FEW/', $rep) === 1,
    'one support customer is held back as too few to classify');

// ════════════════════════════════════════════════════════════════════════
echo "\n5. The reader writes nothing\n";
// ════════════════════════════════════════════════════════════════════════

$before = scandir($tmp);
$size   = filesize($tmp . '/full.log');
ShadowObservation::render(ShadowObservation::aggregate($tmp . '/full.log'), $tmp . '/full.log', '', null);
clearstatcache();
t('no file was created or removed', scandir($tmp), $before);
t('and the log itself is untouched', filesize($tmp . '/full.log'), $size);

$src = '';
foreach (token_get_all((string)file_get_contents($root . '/lib/ShadowObservation.php')) as $k) {
    if (is_array($k)) { if (in_array($k[0], [T_COMMENT, T_DOC_COMMENT], true)) continue; $src .= $k[1]; }
    else $src .= $k;
}
foreach (['file_put_contents', 'fwrite', 'fputs', 'PDO', 'curl_', 'error_log', 'mail(',
          'unlink', 'mkdir', 'rename', 'copy('] as $bad) {
    is_(strpos($src, $bad) === false, "ShadowObservation never calls $bad");
}
is_(substr_count($src, 'fopen') === 1 && strpos($src, "fopen(\$logFile, 'r')") !== false,
    'it opens exactly one file, read-only');

// ════════════════════════════════════════════════════════════════════════
echo "\n6. The window opens and closes on the exact previous state\n";
// ════════════════════════════════════════════════════════════════════════

$dataDir = $tmp . '/data';
@mkdir($dataDir, 0777, true);
$tool = function (string $flags) use ($root, $dataDir): array {
    $cmd = sprintf('DN_DATA_DIR=%s DN_VAULT_FILE=%s php %s %s 2>&1',
                   escapeshellarg($dataDir), escapeshellarg((string)getenv('DN_VAULT_FILE')),
                   escapeshellarg($root . '/tools/shadow_observe.php'), $flags);
    exec($cmd, $out, $code);
    return [implode("\n", $out), $code];
};
$effective = function () use ($root, $dataDir) {
    $cmd = sprintf('DN_DATA_DIR=%s DN_VAULT_FILE=%s php -r %s 2>&1',
        escapeshellarg($dataDir), escapeshellarg((string)getenv('DN_VAULT_FILE')),
        escapeshellarg('require "' . $root . '/lib/PluginConfig.php";
            $c = PluginConfig::load("' . $root . '", "' . $dataDir . '");
            echo array_key_exists("ai_shadow_compare", $c) ? var_export($c["ai_shadow_compare"], true) : "ABSENT";'));
    return trim((string)shell_exec($cmd));
};

t('the key starts absent', $effective(), 'ABSENT');
[$o, $code] = $tool('--begin');
t('--begin succeeds', $code, 0);
is_(strpos($o, 'BEFORE') !== false && strpos($o, 'AFTER') !== false, 'and shows both states');
t('the key is now on', $effective(), 'true');
is_(is_file($dataDir . '/shadow_observation.json'), 'a snapshot was recorded');

[$o2, $code2] = $tool('--begin');
t('a second --begin refuses', $code2, 1);
is_(strpos($o2, 'already recorded') !== false, 'saying an observation is already open');
t('and did not change the key', $effective(), 'true');

[$o3, $code3] = $tool('--end');
t('--end succeeds', $code3, 0);
is_(strpos($o3, 'Restored exactly.') !== false, 'and says it restored exactly');
t('the key is absent again — not 0, absent', $effective(), 'ABSENT');

// A key that was explicitly OFF must come back explicitly OFF, not absent.
@unlink($dataDir . '/shadow_observation.json');
file_put_contents($dataDir . '/kyc_config.json', json_encode(['ai_shadow_compare' => '0']));
t('the key starts explicitly off', $effective(), 'false');
$tool('--begin');
t('the window turns it on', $effective(), 'true');
[$o4, ] = $tool('--end');
is_(strpos($o4, 'Restored exactly.') !== false, 'and closing restores it');
t('back to explicitly off, not absent', $effective(), 'false');
$k = json_decode((string)file_get_contents($dataDir . '/kyc_config.json'), true);
is_(array_key_exists('ai_shadow_compare', $k) && (string)$k['ai_shadow_compare'] === '0',
    'and the file holds the original value, not a rewritten one');

exec('rm -rf ' . escapeshellarg($tmp));
printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
