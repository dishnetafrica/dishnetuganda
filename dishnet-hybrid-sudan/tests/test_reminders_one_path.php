<?php
declare(strict_types=1);
/**
 * test_reminders_one_path.php — 5.18.54, docs/46 rows 5-8 (D-4, D5, N-2, N-3, N-5, C1, C2) and row 20 (C9).
 *
 * Uganda's payment reminders come from one path, once per invoice and tier, in the daytime:
 *
 *   1. the daily run (InvoiceReminders): 7, 3 and 1 day before the due date, 1, 3, 5 and 7 days after — each once;
 *      the tier is claimed only after every check has passed (N-3), and a key the 02:00 job wrote is honoured;
 *   2. the same day again sends nothing; an invoice held back by a missing phone goes once the phone exists;
 *   3. prepaid: nothing after the due date — those texts speak of suspension (C1); each suppression is logged;
 *   4. a list as long as uCRM's page limit is reported, not silently cut;
 *   5. win-back is a proactive message: STOP stops it (C9);
 *   6. uCRM's invoice.near_due and invoice.overdue events are recorded and send nothing (D-4, D5);
 *   7. a prepaid service that ends is "paused", not "suspended for an unpaid invoice" (C2);
 *   8. the 02:00 maintenance job leaves these tasks to the daytime run;
 *   9. the daytime job itself (cron/customer_reminders.php), and the 15-minute invoice scan's quiet hours (N-2);
 *  10. the small decisions: the window, the quiet hours, the due date as a calendar date (N-5);
 *  11. master.php dispatches the job only where the fix applies;
 *  12. South Sudan: 5.18.53 unchanged, every one of the above (docs/46 §E);
 *  13. weakened copies, each caught.
 *
 * The run is driven from the plugin tree under test (tests/fixtures/notify_reminders_side.php, a weakened copy
 * included), against a seeded fake uCRM and the fake Evolution: nothing leaves the machine.
 */
$pass = 0; $fail = 0;
function is_(bool $c, string $m, string $d = ''): void { global $pass, $fail;
    if ($c) { $pass++; echo "  ok   $m\n"; } else { $fail++; echo "  FAIL $m" . ($d !== '' ? "\n       $d" : '') . "\n"; } }

$root = dirname(__DIR__);
if (!getenv('DN_VAULT_FILE')) putenv('DN_VAULT_FILE=' . tempnam(sys_get_temp_dir(), 'dn-vault-'));
require_once __DIR__ . '/fixtures/notify_harness.php';

const DAY = '2026-10-05';     // the day the side runner is given (a Monday)

// ── Fixtures ──────────────────────────────────────────────────────────────────
$on  = fn(string $day, int $n): string => (new DateTimeImmutable($day))->modify(($n >= 0 ? '+' : '') . $n . ' days')->format('Y-m-d');
$inv = function (int $id, string $due, int $client = 7, array $x = [], string $offset = '+0300'): array {
    return $x + ['id' => $id, 'number' => "INV-{$id}", 'clientId' => $client, 'status' => 1, 'total' => 90000.0,
                 'amountToPay' => 90000.0, 'amountPaid' => 0.0, 'currencyCode' => 'UGX',
                 'dueDate' => $due . 'T00:00:00' . $offset, 'createdDate' => '2026-01-01T10:00:00' . $offset];
};
$clients = [
    '7'  => ['id' => 7,  'firstName' => 'Test',   'lastName' => 'Payer',   'accountOutstandingRaw' => 90000.0,
             'contacts' => [['phone' => '256700000007', 'email' => 'payer@example.test']]],
    '8'  => ['id' => 8,  'firstName' => 'Second', 'lastName' => 'Contact', 'contacts' => [['email' => 'first@example.test'], ['phone' => '256700000008']]],
    '9'  => ['id' => 9,  'firstName' => 'No',     'lastName' => 'Phone',   'contacts' => [['email' => 'nophone@example.test']]],
    '10' => ['id' => 10, 'firstName' => 'Said',   'lastName' => 'Stop',    'contacts' => [['phone' => '256700000010']]],
];
/** The daily run's estate, due dates counted from $day. */
$estate = function (string $day, bool $phoneFor9 = false, string $offset = '+0300') use ($on, $inv, $clients): array {
    $c = $clients;
    if ($phoneFor9) $c['9']['contacts'][] = ['phone' => '256700000009'];
    $i = fn(int $id, int $n, int $client = 7, array $x = []) => $inv($id, $on($day, $n), $client, $x, $offset);
    return ['clients' => $c, 'invoices' => [
        '9301' => $i(9301, 7), '9302' => $i(9302, 3), '9303' => $i(9303, 1),
        '9304' => $i(9304, -1), '9305' => $i(9305, -3), '9306' => $i(9306, -5), '9307' => $i(9307, -7),
        '9308' => $i(9308, 5),                                            // no tier on this day
        '9309' => $i(9309, -1),                                           // paid since the list was read (fresh)
        '9310' => $i(9310, 7, 9),                                         // its client has no phone yet
        '9311' => $i(9311, 3),                                            // reminded by the 02:00 job before the upgrade
        '9312' => $i(9312, -3),                                           // likewise, after the due date
        '9313' => $i(9313, 1, 8),                                         // the phone is on the second contact
        '9314' => $i(9314, -5, 7, ['status' => 3, 'amountToPay' => 0.0]), // paid: not in the list at all
    ], 'invoices_fresh' => ['9309' => $i(9309, -1, 7, ['status' => 3, 'amountToPay' => 0.0])]];
};
/** Reminder texts, by kind: the header each template opens with. */
const KINDS = ['Upcoming Invoice' => 'pre-d7', 'Due in 3 Days' => 'pre-d3', 'Due Tomorrow' => 'pre-d1',
               'Gentle Reminder' => 'overdue-d1', 'Account Overdue' => 'overdue-d3', 'Final Notice' => 'overdue-d5',
               'Payment Reminder' => 'overdue-d7'];
$reminders = function (array $texts): array {
    $out = [];
    foreach ($texts as $t) {
        foreach (KINDS as $head => $kind) {
            if (strpos((string)$t['text'], '*' . $head) === false) continue;
            $num = preg_match('/#(INV-\d+)/', (string)$t['text'], $m) ? $m[1] : '?';
            $out[] = $kind . ' ' . $num . ' → ' . $t['number'];
        }
    }
    sort($out);
    return $out;
};
$count = fn(array $texts, string $needle): int => count(array_filter($texts, fn($t) => strpos((string)$t['text'], $needle) !== false));
$claimed = function (NotifyHarness $h, string $key): bool {
    $st = $h->pdo()->prepare('SELECT 1 FROM notification_dedup WHERE dedup_key = ?');
    $st->execute([$key]);
    return (bool)$st->fetchColumn();
};
$claim = function (NotifyHarness $h, string $key): void {     // a key the 02:00 job wrote before the upgrade
    $h->pdo()->exec('CREATE TABLE IF NOT EXISTS notification_dedup (dedup_key TEXT PRIMARY KEY, sent_at TEXT NOT NULL)');
    $h->pdo()->prepare('INSERT OR IGNORE INTO notification_dedup (dedup_key, sent_at) VALUES (?, ?)')->execute([$key, '2026-10-04 02:00:05']);
};
/** One pass of the daily run from the tree under test (winback too, when asked). */
$runSide = function (string $pluginRoot, NotifyHarness $h, string $day, bool $winback = false): array {
    $err = $h->tmp . '/side.stderr';
    $cmd = 'php ' . escapeshellarg(__DIR__ . '/fixtures/notify_reminders_side.php') . ' ' . escapeshellarg($pluginRoot) . ' '
         . escapeshellarg($h->dataDir) . ' ' . escapeshellarg($day) . ($winback ? ' winback' : '') . ' 2>' . escapeshellarg($err);
    $out = json_decode((string)shell_exec($cmd), true);
    $r = is_array($out) ? $out : ['error' => 'no JSON'];
    $r['stderr'] = (string)@file_get_contents($err);
    return $r;
};
/** A plugin tree whose entry script is a real copy, so that it finds the harness data directory through ucrm.json. */
$treeFor = function (string $pluginRoot, NotifyHarness $h, string $script, array $patches = []) {
    $p = $patches + [$script => []];     // a patch for the script itself wins over the plain copy
    return NotifyHarness::weakened($pluginRoot, $p, 'rem_tree', ['ucrm.json' => json_encode(['pluginDataDir' => $h->dataDir])]);
};
$runScript = function (string $tree, NotifyHarness $h, string $script): string {
    return (string)shell_exec('DN_DATA_DIR=' . escapeshellarg($h->dataDir) . ' timeout 120 php ' . escapeshellarg($tree . '/' . $script) . ' 2>&1');
};

// ══════════════════════════════════════════════════════════════════════════════
// The scenarios, as functions of the plugin tree, so a weakened copy runs the very same ones.
// ══════════════════════════════════════════════════════════════════════════════
$dailyRun = function (string $pluginRoot) use ($estate, $reminders, $claimed, $claim, $runSide): array {
    $h = NotifyHarness::start($pluginRoot, 'uganda', [], 'rem');
    $h->seedCrm($estate(DAY));
    $claim($h, 'INV-9311-pre-d3'); $claim($h, 'INV-9312-d3');
    $r1 = $runSide($pluginRoot, $h, DAY);
    $t1 = $reminders($h->evoTexts());
    $keys = ['paid' => $claimed($h, 'INV-9309-d1'), 'nophone' => $claimed($h, 'INV-9310-pre-d7'),
             'sent' => $claimed($h, 'INV-9301-pre-d7') && $claimed($h, 'INV-9307-d7')];
    $r2 = $runSide($pluginRoot, $h, DAY);
    $t2 = $reminders($h->evoTexts());
    $h->seedCrm($estate(DAY, true));           // the client's phone is added in uCRM
    $r3 = $runSide($pluginRoot, $h, DAY);
    $t3 = $reminders($h->evoTexts());
    $out = ['r1' => $r1, 't1' => $t1, 'keys' => $keys, 'r2' => $r2, 't2' => $t2, 'r3' => $r3, 't3' => $t3];
    $h->stop();
    return $out;
};
$prepaidRun = function (string $pluginRoot) use ($estate, $reminders, $claimed, $claim, $runSide): array {
    $h = NotifyHarness::start($pluginRoot, 'uganda', ['billing_model' => 'prepaid'], 'rem');
    $h->seedCrm($estate(DAY));
    $claim($h, 'INV-9311-pre-d3'); $claim($h, 'INV-9312-d3');
    $r = $runSide($pluginRoot, $h, DAY);
    $out = ['r' => $r, 't' => $reminders($h->evoTexts()), 'claimedOverdue' => $claimed($h, 'INV-9304-d1') || $claimed($h, 'INV-9306-d5')];
    $h->stop();
    return $out;
};
$pageLimitRun = function (string $pluginRoot) use ($inv, $on, $clients, $runSide): array {
    $h = NotifyHarness::start($pluginRoot, 'uganda', [], 'rem');
    $many = [];
    for ($i = 1; $i <= 500; $i++) $many[(string)(20000 + $i)] = $inv(20000 + $i, $on(DAY, 20));
    $h->seedCrm(['clients' => $clients, 'invoices' => $many]);
    $logFile = $pluginRoot . '/data/plugin.log';
    $before = is_file($logFile) ? filesize($logFile) : 0;
    $r = $runSide($pluginRoot, $h, DAY);
    clearstatcache();
    $new = is_file($logFile) ? (string)file_get_contents($logFile, false, null, $before) : '';
    $h->stop();
    return ['r' => $r, 'pluginlog' => $new];
};
$winbackRun = function (string $pluginRoot) use ($clients, $on, $runSide): array {
    $h = NotifyHarness::start($pluginRoot, 'uganda', [], 'rem');
    $ended = $on(DAY, -8) . 'T00:00:00+0300';
    $h->seedCrm(['clients' => $clients, 'services' => [
        '601' => ['id' => 601, 'clientId' => 7,  'name' => 'Home 50', 'status' => 3, 'activeTo' => $ended],
        '602' => ['id' => 602, 'clientId' => 10, 'name' => 'Home 50', 'status' => 3, 'activeTo' => $ended],
        '603' => ['id' => 603, 'clientId' => 8,  'name' => 'Home 50', 'status' => 3, 'activeTo' => $ended],
    ]]);
    require_once $pluginRoot . '/lib/ContactOptOut.php';
    $opt = (new ContactOptOut($h->pdo()))->add('256700000010', ['scope' => 'proactive', 'reason' => 'STOP', 'source' => 'keyword']);
    // The old task's log as the store leaves it after two of its runs: a list holding the object, nested (N-6).
    require_once $pluginRoot . '/lib/StoreInterface.php';
    require_once $pluginRoot . '/lib/SqliteStore.php';
    \SqliteStore::create($h->dataDir)->save('winback_log.json', ['0' => ['0' => ['WB603' => '2026-10-04 02:00:05'], 'WB999' => 'x'], 'WB998' => 'y']);
    $count = fn(string $n) => count(array_filter($h->evoTexts(), fn($t) => $t['number'] === $n && strpos($t['text'], 'We Miss You') !== false));
    $r  = $runSide($pluginRoot, $h, DAY, true);
    $first = $count('256700000007');
    $runSide($pluginRoot, $h, DAY, true);                  // the same day again
    $runSide($pluginRoot, $h, $on(DAY, 1), true);          // the next day, still inside the 7-10 day window
    $out = ['r' => $r, 'opt' => $opt, 'first7' => $first, 'to7' => $count('256700000007'),
            'to10' => count(array_filter($h->evoTexts(), fn($t) => $t['number'] === '256700000010')),
            'to8' => $count('256700000008')];
    $h->stop();
    return $out;
};
$webhookRun = function (string $pluginRoot, string $tenant) use ($estate, $reminders): array {
    $h = NotifyHarness::start($pluginRoot, $tenant, [], 'rem');
    $today = (new DateTimeImmutable('today', new DateTimeZone($tenant === 'uganda' ? 'Africa/Kampala' : 'Africa/Juba')))->format('Y-m-d');
    $h->seedCrm($estate($today, false, $tenant === 'uganda' ? '+0300' : '+0200'));
    $a = $h->fire('invoice.near_due', 'invoice', 9301); $h->settle(0.5);
    $b = $h->fire('invoice.overdue', 'invoice', 9306); $h->settle(0.5);
    $c = $h->fire('invoice.overdue', 'invoice', 9306, 'nh-overdue-again'); $h->settle(0.5);   // uCRM raises it again
    $out = ['near' => (string)$a[1], 'over' => (string)$b[1], 't' => $reminders($h->evoTexts()), 'log' => $h->webhookLog(),
            'stderr' => $h->stderr()];
    $h->stop();
    return $out;
};
$suspendRun = function (string $pluginRoot, string $tenant, string $model) use ($clients): array {
    $h = NotifyHarness::start($pluginRoot, $tenant, $model === '' ? [] : ['billing_model' => $model], 'rem');
    $h->seedCrm(['clients' => $clients, 'services' => [
        '701' => ['id' => 701, 'clientId' => 7, 'name' => 'Site : Test (000701) Service Plan HOME50 : Period', 'status' => 3,
                  'activeTo' => '2026-09-29T00:00:00+0300'],
    ]]);
    $r = $h->fire('service.suspend', 'service', 701); $h->settle(1.0);
    $texts = $h->evoTexts();
    $out = ['resp' => (string)$r[1], 'paused' => count(array_filter($texts, fn($t) => strpos($t['text'], 'Service Paused') !== false)),
            'suspended' => count(array_filter($texts, fn($t) => strpos($t['text'], 'Service Suspended') !== false)),
            'text' => (string)($texts[0]['text'] ?? ''), 'log' => $h->webhookLog(), 'stderr' => $h->stderr()];
    $h->stop();
    return $out;
};
/** The 02:00 job, run whole from a tree with a real copy of it, against invoices due relative to today. */
$maintenanceRun = function (string $pluginRoot, string $tenant, array $patches = [], string $offset = '', int $runs = 1) use ($on, $inv, $clients, $reminders, $treeFor, $runScript): array {
    $h = NotifyHarness::start($pluginRoot, $tenant, [], 'rem');
    $zone   = $tenant === 'uganda' ? 'Africa/Kampala' : 'Africa/Juba';
    $offset = $offset !== '' ? $offset : ($tenant === 'uganda' ? '+0300' : '+0200');
    $today  = (new DateTimeImmutable('today', new DateTimeZone($zone)))->format('Y-m-d');
    $ended  = $on($today, -8) . 'T00:00:00' . $offset;
    $h->seedCrm(['clients' => $clients,
        'invoices' => ['9401' => $inv(9401, $on($today, 7), 7, [], $offset), '9402' => $inv(9402, $on($today, -3), 7, [], $offset)],
        'services' => ['602' => ['id' => 602, 'clientId' => 10, 'name' => 'Home 50', 'status' => 3, 'activeTo' => $ended]]]);
    require_once $pluginRoot . '/lib/ContactOptOut.php';
    (new ContactOptOut($h->pdo()))->add('256700000010', ['scope' => 'proactive', 'reason' => 'STOP', 'source' => 'keyword']);
    $tree = $treeFor($pluginRoot, $h, 'cron_maintenance.php', $patches);
    $log  = '';
    for ($i = 0; $i < $runs; $i++) $log .= $runScript($tree, $h, 'cron_maintenance.php');
    $texts = $h->evoTexts();
    $out = ['log' => $log, 't' => $reminders($texts), 'newInvoice' => count(array_filter($texts, fn($t) => strpos($t['text'], 'New Invoice') !== false)),
            'winback10' => count(array_filter($texts, fn($t) => $t['number'] === '256700000010'))];
    $h->stop();
    return $out;
};
/** cron/customer_reminders.php, as master.php would include it, from a tree with a real copy of it. */
$dailyJobRun = function (string $pluginRoot, string $tenant) use ($on, $inv, $clients, $reminders, $treeFor, $runScript): array {
    $h = NotifyHarness::start($pluginRoot, $tenant, [], 'rem');
    $zone   = $tenant === 'uganda' ? 'Africa/Kampala' : 'Africa/Juba';
    $offset = $tenant === 'uganda' ? '+0300' : '+0200';
    $today  = (new DateTimeImmutable('today', new DateTimeZone($zone)))->format('Y-m-d');
    $h->seedCrm(['clients' => $clients,
        'invoices' => ['9501' => $inv(9501, $on($today, 3), 7, [], $offset), '9502' => $inv(9502, $on($today, -1), 7, [], $offset)]]);
    $tree = $treeFor($pluginRoot, $h, 'cron/customer_reminders.php');
    $log  = $runScript($tree, $h, 'cron/customer_reminders.php');
    $out  = ['log' => $log, 't' => $reminders($h->evoTexts())];
    $h->stop();
    return $out;
};
/** The 15-minute invoice scan with quiet hours set around the present hour, or away from it. */
$scanRun = function (string $pluginRoot, bool $quietNow) use ($inv, $on, $clients, $treeFor, $runScript): array {
    $hour = (int)(new DateTimeImmutable('now', new DateTimeZone('Africa/Kampala')))->format('G');
    $cfg  = $quietNow ? ['notify_quiet_from_hour' => $hour, 'notify_quiet_until_hour' => ($hour + 2) % 24]
                      : ['notify_quiet_from_hour' => ($hour + 2) % 24, 'notify_quiet_until_hour' => ($hour + 4) % 24];
    $h = NotifyHarness::start($pluginRoot, 'uganda', $cfg, 'rem');
    $today = (new DateTimeImmutable('today', new DateTimeZone('Africa/Kampala')))->format('Y-m-d');
    $h->seedCrm(['clients' => $clients, 'invoices' => ['9601' => $inv(9601, $on($today, 14), 7,
        ['createdDate' => (new DateTimeImmutable('-1 hour'))->format('Y-m-d\TH:i:sO')])]]);
    $tree = $treeFor($pluginRoot, $h, 'cron_invoice_notify.php');
    $out  = $runScript($tree, $h, 'cron_invoice_notify.php');
    $r = ['out' => $out, 'new' => count(array_filter($h->evoTexts(), fn($t) => strpos($t['text'], 'New Invoice') !== false)),
          'log' => (string)@file_get_contents($h->dataDir . '/invoice_notify_cron.log')];
    $h->stop();
    return $r;
};
$units = function (string $pluginRoot): array {
    $j = json_decode((string)shell_exec('php ' . escapeshellarg(__DIR__ . '/fixtures/notify_units_side.php') . ' ' . escapeshellarg($pluginRoot) . ' 2>&1'), true);
    return is_array($j) ? $j : [];
};
const UNITS = [
    'window.09:00, last ran yesterday 09:05' => true,  'window.08:59, last ran yesterday' => false,
    'window.17:00, last ran yesterday' => false,       'window.16:59, missed all morning' => true,
    'window.12:00, ran today at 09:05' => false,       'window.12:00, never ran' => true,
    'window.12:00, last run today at 08:30' => true,   'window.02:00, last ran yesterday' => false,
    'quiet.default 21h' => true, 'quiet.default 23h' => true, 'quiet.default 0h' => true, 'quiet.default 7h' => true,
    'quiet.default 8h' => false, 'quiet.default 12h' => false, 'quiet.default 20h' => false,
    'quiet.22-6 21h' => false, 'quiet.22-6 22h' => true, 'quiet.22-6 5h' => true, 'quiet.22-6 6h' => false,
    'quiet.off (0-0) 3h' => false, 'quiet.invalid (25-8) 3h' => false,
    'date.+0300 midnight' => '2026-10-12 00:00 CAT', 'date.+03:00 midnight' => '2026-10-12 00:00 CAT',
    'date.UTC late evening' => '2026-10-12 00:00 CAT', 'date.bare date' => '2026-10-12 00:00 CAT',
    'date.not a date' => null, 'date.impossible day' => null,
    'date.days from 5 Oct to a +0300 due date of 12 Oct' => 7,
];

// ══════════════════════════════════════════════════════════════════════════════
echo "\n1. The daily run (Uganda): each tier once, the claim after the checks\n";
$d = $dailyRun($root);
$want1 = ['overdue-d1 INV-9304 → 256700000007', 'overdue-d3 INV-9305 → 256700000007', 'overdue-d5 INV-9306 → 256700000007',
          'overdue-d7 INV-9307 → 256700000007', 'pre-d1 INV-9303 → 256700000007', 'pre-d1 INV-9313 → 256700000008',
          'pre-d3 INV-9302 → 256700000007', 'pre-d7 INV-9301 → 256700000007'];
is_($d['t1'] === $want1, 'the seven tiers go, each to its invoice, and nothing else', json_encode($d['t1']));
is_(($d['r1']['reminders']['pre'] ?? null) === ['d7' => 1, 'd3' => 1, 'd1' => 2]
    && ($d['r1']['reminders']['overdue'] ?? null) === ['d1' => 1, 'd3' => 1, 'd5' => 1, 'd7' => 1],
    'the run counts what it sent', json_encode($d['r1']['reminders'] ?? $d['r1']));
is_(!in_array('pre-d3 INV-9311 → 256700000007', $d['t1'], true) && !in_array('overdue-d3 INV-9312 → 256700000007', $d['t1'], true),
    'an invoice the 02:00 job reminded before the upgrade is not reminded again (its keys are honoured)');
is_(in_array('pre-d1 INV-9313 → 256700000008', $d['t1'], true), 'the phone is the first contact that has one (the old job read contact 0 only)');
is_(!$d['keys']['paid'], 'paid since the list was read: no text, and the tier is not used up (N-3)');
is_(!$d['keys']['nophone'], 'no phone: no text, and the tier is not used up (N-3)');
is_($d['keys']['sent'], 'a tier that was sent is recorded');
is_(strpos(implode("\n", $d['r1']['log'] ?? []), 'SKIP #INV-9309 overdue-d1 — paid (fresh check)') !== false, 'the paid invoice is logged as such');
is_(!preg_match('/\b(Warning|Notice|Deprecated|Fatal|Uncaught|Parse error)\b/', $d['r1']['stderr']), 'no PHP warning or error', substr($d['r1']['stderr'], 0, 300));

echo "\n2. The same day again, then the missing phone\n";
is_($d['t2'] === $d['t1'], 'a second run the same day sends nothing', json_encode(array_values(array_diff($d['t2'], $d['t1']))));
$new3 = array_values(array_diff($d['t3'], $d['t2']));
is_($new3 === ['pre-d7 INV-9310 → 256700000009'], 'the phone added in uCRM: that reminder goes, once, the same day (N-3)', json_encode($new3));

echo "\n3. Prepaid: before the due date only (C1)\n";
$p = $prepaidRun($root);
$pre = array_values(array_filter($p['t'], fn($x) => strpos($x, 'pre-') === 0));
$post = array_values(array_filter($p['t'], fn($x) => strpos($x, 'overdue-') === 0));
is_($pre === ['pre-d1 INV-9303 → 256700000007', 'pre-d1 INV-9313 → 256700000008', 'pre-d3 INV-9302 → 256700000007', 'pre-d7 INV-9301 → 256700000007']
    && $post === [], 'the reminders before the due date go, as for postpaid; none after it', json_encode($p['t']));
is_(($p['r']['reminders']['suppressed_prepaid'] ?? 0) === 5, 'the five after-due reminders due that day are suppressed, and counted', json_encode($p['r']['reminders'] ?? $p['r']));
is_(strpos(implode("\n", $p['r']['log'] ?? []), 'SUPPRESSED #INV-9304 overdue-d1: prepaid') !== false, 'each suppression is logged');
is_(!$p['claimedOverdue'], 'a suppressed reminder is not recorded as sent');

echo "\n4. uCRM's page limit\n";
$pl = $pageLimitRun($root);
is_(($pl['r']['reminders']['fetched'] ?? 0) === 500, 'a list of 500 is read', json_encode($pl['r']['reminders'] ?? $pl['r']));
is_(strpos(implode("\n", $pl['r']['log'] ?? []), "WARNING: uCRM returned 500 invoices, its page limit") !== false, 'the run says it reached the page limit');
is_(strpos($pl['pluginlog'] . $pl['r']['stderr'], "page limit (500)") !== false, 'and so does uCRM\'s log for the plugin (PluginLog)');

echo "\n5. Win-back: a proactive message, so STOP stops it (C9)\n";
$w = $winbackRun($root);
is_(!empty($w['opt']['ok']), 'the opt-out is recorded (control)', json_encode($w['opt']));
is_($w['first7'] === 1, 'the customer who did not opt out receives it', (string)$w['first7']);
is_($w['to10'] === 0, 'the customer who wrote STOP does not', (string)$w['to10']);
is_($w['to7'] === 1, 'once: the same day again and the next day send nothing (N-6: the old guard never read back)', (string)$w['to7']);
is_($w['to8'] === 0, 'a service named in the old task\'s log, nested as the store leaves it, is not written to again', (string)$w['to8']);

echo "\n6. uCRM's reminder events: recorded, nothing sent (D-4, D5)\n";
$wu = $webhookRun($root, 'uganda');
is_($wu['t'] === [], 'Uganda: invoice.near_due and invoice.overdue (twice) send nothing', json_encode($wu['t']));
is_(strpos($wu['near'], 'recorded') !== false && strpos($wu['over'], 'recorded') !== false, 'each is answered "recorded"', $wu['near'] . ' | ' . $wu['over']);
is_(strpos($wu['log'], 'near_due recorded for invoice #9301') !== false && strpos($wu['log'], 'overdue recorded for invoice #9306') !== false, 'and written to the webhook log');

echo "\n7. A prepaid service that ends is paused, not suspended for a debt (C2)\n";
$sp = $suspendRun($root, 'uganda', 'prepaid');
is_($sp['paused'] === 1 && $sp['suspended'] === 0, 'Uganda, prepaid: "Service Paused", not "Service Suspended"', json_encode(['p' => $sp['paused'], 's' => $sp['suspended'], 'r' => $sp['resp']]));
is_(strpos($sp['text'], 'unpaid invoice') === false && strpos($sp['text'], 'no reconnection fee') !== false, 'it names no debt, and says there is no reconnection fee');
is_(strpos($sp['text'], 'HOME50') !== false, 'it names the service');
is_(strpos($sp['log'], 'Suspension WhatsApp sent') !== false, 'the rest of the handler runs as before (its log line)');
$sq = $suspendRun($root, 'uganda', '');
is_($sq['suspended'] === 1 && $sq['paused'] === 0, 'Uganda, postpaid: "Service Suspended", as before', json_encode(['p' => $sq['paused'], 's' => $sq['suspended']]));

echo "\n8. The 02:00 maintenance job leaves these tasks to the daytime run\n";
$mu = $maintenanceRun($root, 'uganda');
is_(substr_count($mu['log'], 'MOVED — sent by the daytime reminder run') === 3, 'Uganda: the two reminder tasks and win-back say MOVED', substr($mu['log'], 0, 200));
is_(strpos($mu['log'], 'MOVED — the 15-minute scanner') !== false, 'the 02:00 new-invoice scan too');
is_($mu['t'] === [] && $mu['newInvoice'] === 0 && $mu['winback10'] === 0, 'and nothing is sent at 02:00', json_encode($mu['t']));
is_(strpos($mu['log'], 'Done —') !== false, 'the job runs to the end');

echo "\n9. The daytime job, and the invoice scan's quiet hours (N-2)\n";
$ju = $dailyJobRun($root, 'uganda');
is_($ju['t'] === ['overdue-d1 INV-9502 → 256700000007', 'pre-d3 INV-9501 → 256700000007'], 'Uganda: cron/customer_reminders.php sends today\'s reminders', json_encode($ju['t']) . ' ' . substr($ju['log'], 0, 400));
is_(strpos($ju['log'], 'Reminders done — before due: 7d=0 3d=1 1d=0; after due: 1d=1') !== false, 'and logs its counts');
is_(strpos($ju['log'], 'Win-back done') !== false, 'and runs win-back');
$qn = $scanRun($root, true);
is_($qn['new'] === 0 && strpos($qn['log'], 'Quiet hours') !== false, 'the invoice scan inside quiet hours: nothing sent, and it says why', substr($qn['log'] . $qn['out'], 0, 300));
$qf = $scanRun($root, false);
is_($qf['new'] === 1, 'outside them: the new invoice is announced (control)', substr($qf['log'] . $qf['out'], 0, 300));
is_(strpos($qf['out'], 'invoice_notify_cron.log') === false && strpos($qf['log'], 'Fetched') !== false,
    'its log goes to the data directory, with no warning (N-4)', substr($qf['out'], 0, 300));

echo "\n10. The window, the quiet hours and the due date\n";
$u = $units($root);
foreach (UNITS as $k => $v) is_(array_key_exists($k, $u) && $u[$k] === $v, $k . ' → ' . json_encode($v), json_encode($u[$k] ?? 'missing'));

echo "\n11. master.php dispatches the job only where the fix applies\n";
$master = (string)file_get_contents($root . '/cron/master.php');
$jobLine = '';
foreach (explode("\n", $master) as $line) if (preg_match("/^\s*'customer_reminders'\s*=>\s*\['interval'/", $line)) $jobLine = $line;
is_($jobLine !== '' && strpos($jobLine, "'gate' => 'reminders'") !== false && strpos($jobLine, "'run_hour' => 9") !== false
    && strpos($jobLine, "'run_until' => 17") !== false && strpos($jobLine, "__DIR__ . '/customer_reminders.php'") !== false,
    'the job is in the job list (so the status tool and the no-exit check see it), gated to the reminders fix', $jobLine);
require_once $root . '/lib/NotifyGate.php';
is_(NotifyGate::REMINDERS === 'reminders', 'the gate named there is NotifyGate::REMINDERS');
$loop = substr($master, (int)strpos($master, 'foreach ($_m_jobs as $_m_name => $_m_job) {'));
$g = strpos($loop, "if (isset(\$_m_job['gate']) && !NotifyGate::applies((string)\$_m_job['gate']");
is_($g !== false && $g < (int)strpos($loop, '$_m_scriptPath = $_m_job[\'script\'];'), 'the gate is checked first, before anything is dispatched or recorded');
is_(strpos($loop, 'JobWindow::due((int)$_m_now, $_m_lastRun, (int)$_m_job[\'run_hour\'], (int)$_m_job[\'run_until\'])') !== false,
    'a job with run_until runs in its window');
$cr = (string)file_get_contents($root . '/cron/customer_reminders.php');
is_(!preg_match('/\bexit\s*\(|\bdie\s*\(/', $cr), 'the job never calls exit() (it shares master.php\'s process)');

echo "\n12. South Sudan: 5.18.53 unchanged\n";
$ws = $webhookRun($root, 'south-sudan');
is_($ws['t'] === ['overdue-d5 INV-9306 → 256700000007', 'pre-d7 INV-9301 → 256700000007'],
    'invoice.near_due and invoice.overdue send, once per invoice per day, as before', json_encode($ws['t']));
$ss = $suspendRun($root, 'south-sudan', 'prepaid');
is_($ss['suspended'] === 1 && $ss['paused'] === 0, 'service.suspend: "Service Suspended", even with billing_model prepaid (C2 there awaits approval)');
$ms = $maintenanceRun($root, 'south-sudan');
is_($ms['t'] === ['overdue-d3 INV-9402 → 256700000007', 'pre-d7 INV-9401 → 256700000007'], 'the 02:00 job still sends the reminders', json_encode($ms['t']) . ' ' . substr($ms['log'], 0, 300));
is_(strpos($ms['log'], 'MOVED') === false, 'and moves nothing');
is_($ms['winback10'] === 1, 'its win-back still goes as a transactional message, STOP or not (C9 there awaits approval)', (string)$ms['winback10']);
$m6 = $maintenanceRun($root, 'south-sudan', [], '', 2);
is_($m6['winback10'] === 2, 'recorded, not changed (N-6): there the 02:00 job\'s win-back goes again on its next run', (string)$m6['winback10']);
$m5 = $maintenanceRun($root, 'south-sudan', [], '+0300');
is_($m5['t'] === ['overdue-d3 INV-9402 → 256700000007'],
    'recorded, not changed (N-5): with uCRM dates at +0300 under Africa/Juba, the 02:00 job misses the 7-day reminder', json_encode($m5['t']));
$js = $dailyJobRun($root, 'south-sudan');
is_($js['t'] === [] && strpos($js['log'], 'Not this install') !== false, 'the daytime job does nothing there', substr($js['log'], 0, 300));

echo "\n13. Weakened copies, each caught\n";
$mut = [
    'the tier claimed before the checks (5.18.53 order)' => [
        'lib/InvoiceReminders.php' => [
            ["            // Claimed here, after every check, just before the send (N-3).\n            if (!\$this->notify->dedupMark(\$key)) { \$out['skipped']++; continue; }",
             "            // (weakened: no late claim)"],
            ["if (\$this->notify->dedupCheck(\$key)) { \$out['skipped']++; continue; }",
             "if (!\$this->notify->dedupMark(\$key)) { \$out['skipped']++; continue; }"],
        ]],
    'no prepaid rule' => ['lib/InvoiceReminders.php' => [["if (\$kind === 'overdue' && \$prepaid) {", 'if (false) {']]],
    'the old job\'s keys not honoured' => ['lib/InvoiceReminders.php' => [["public static function preKey(string \$num, string \$tier): string     { return \"{\$num}-pre-{\$tier}\"; }",
                                                                            "public static function preKey(string \$num, string \$tier): string     { return \"R-{\$num}-pre-{\$tier}\"; }"]]],
    'the page limit not reported' => ['lib/InvoiceReminders.php' => [['if (count($rows) >= self::PAGE_LIMIT) {', 'if (false) {']]],
    'win-back as a transactional message again' => ['lib/WinBack.php' => [['\ContactOptOut::CLASS_PROACTIVE', '\ContactOptOut::CLASS_TRANSACTIONAL']]],
    'win-back on the old file guard again' => ['lib/WinBack.php' => [
        ['            if (isset($old[$key]) || $this->notify->dedupCheck($key)) continue;', '            if (isset($old[$key])) continue;'],
        ['            if (!$this->notify->dedupMark($key)) continue;                          // claimed just before the send', '']]],
];
foreach ($mut as $name => $patch) {
    $wk = NotifyHarness::weakened($root, $patch, 'rem_wk');
    if (isset($patch['lib/WinBack.php'])) {
        $m = $winbackRun($wk);
        is_($m['first7'] === 1 && ($m['to10'] !== 0 || $m['to7'] > 1), "caught: {$name}", json_encode(['to7' => $m['to7'], 'to10' => $m['to10']]));
        continue;
    }
    $a = $dailyRun($wk); $b = $prepaidRun($wk); $c = $pageLimitRun($wk);
    $ran = count($a['t1']) > 0 && ($c['r']['reminders']['fetched'] ?? 0) === 500;   // it ran: a failure below is behaviour, not a crash
    $ok = $a['t1'] === $want1 && !$a['keys']['paid'] && !$a['keys']['nophone']
       && array_values(array_diff($a['t3'], $a['t2'])) === ['pre-d7 INV-9310 → 256700000009']
       && count(array_filter($b['t'], fn($x) => strpos($x, 'overdue-') === 0)) === 0
       && strpos(implode("\n", $c['r']['log'] ?? []), 'page limit') !== false;
    is_($ran && !$ok, "caught: {$name}");
}
$wk = NotifyHarness::weakened($root, ['webhook.php' => [["        if (NotifyGate::applies(NotifyGate::REMINDERS, \$config, \$dataDir)) {\n            whLog(\$changeType, \"near_due recorded",
                                                           "        if (false) {\n            whLog(\$changeType, \"near_due recorded"]]], 'rem_wk');
is_($webhookRun($wk, 'uganda')['t'] !== [], 'caught: the webhook still sends near_due reminders on Uganda');
$wk = NotifyHarness::weakened($root, ['webhook.php' => [["        \$_suspPrepaid = NotifyGate::applies(NotifyGate::REMINDERS, \$config, \$dataDir) && InvoiceReminders::prepaid(\$config);",
                                                           "        \$_suspPrepaid = false;"]]], 'rem_wk');
is_($suspendRun($wk, 'uganda', 'prepaid')['paused'] === 0, 'caught: a prepaid suspension told as a debt');
$mm = $maintenanceRun($root, 'uganda', ['cron_maintenance.php' => [['$_nrMoved = NotifyGate::applies(NotifyGate::REMINDERS, is_array($config) ? $config : [], $dataDir);', '$_nrMoved = false;']]]);
is_($mm['t'] !== [], 'caught: the 02:00 job still sends the reminders on Uganda');
$wk = NotifyHarness::weakened($root, ['lib/JobWindow.php' => [['if ($hour < $fromHour || $hour >= $untilHour) return false;', 'if ($hour !== $fromHour) return false;']]], 'rem_wk');
$uw = $units($wk);
is_(($uw['window.16:59, missed all morning'] ?? null) !== true, 'caught: an exact hour instead of a window (a missed 09:00 skips the day)');
$wk = NotifyHarness::weakened($root, ['lib/InvoiceReminders.php' => [["        if (!preg_match('/^(\\d{4}-\\d{2}-\\d{2})/', trim(\$raw), \$m)) return null;\n        \$d = \\DateTimeImmutable::createFromFormat('!Y-m-d', \$m[1], \$tz);\n        return (\$d instanceof \\DateTimeImmutable && \$d->format('Y-m-d') === \$m[1]) ? \$d : null;",
    "        \$d = \\DateTimeImmutable::createFromFormat('Y-m-d\\TH:i:sO', \$raw) ?: \\DateTimeImmutable::createFromFormat('Y-m-d', \$raw);\n        return \$d ? \$d->setTime(0, 0, 0) : null;"]]], 'rem_wk');
$ud = $units($wk);
is_(($ud['date.days from 5 Oct to a +0300 due date of 12 Oct'] ?? null) !== 7, 'caught: the due date read as an instant (5.18.53, N-5)', json_encode($ud['date.days from 5 Oct to a +0300 due date of 12 Oct'] ?? null));
$wk = NotifyHarness::weakened($root, ['cron_invoice_notify.php' => [['if ($GLOBALS[\'_ilogUg\'] && InvoiceReminders::quiet(', 'if (false && InvoiceReminders::quiet(']]], 'rem_wk');
is_($scanRun($wk, true)['new'] === 1, 'caught: the invoice scan ignores the quiet hours');

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail ? 1 : 0);
