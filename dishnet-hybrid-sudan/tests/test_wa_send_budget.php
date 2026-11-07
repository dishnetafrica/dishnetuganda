<?php
declare(strict_types=1);
/**
 * test_wa_send_budget.php — 5.18.104 (Uganda, root docs/73 §H.2.5, §H.2.6, §H.2.14): the one send ledger
 * (lib/WaSendBudget.php, migration 089's wa_auto_sends) and the record-only hooks at the existing senders.
 *
 *   LG  the ledger: the claim's steps, the kinds, the priority, the per-person rule both ways, reminders first, the zone,
 *       two processes at once, the call contract every caller uses (ruling R2), onConnection(), the in-flight bound,
 *       the limits a claim records (ruling R18)
 *   HK  the hooks: NotificationService's three, AlertService's (and lastResult()), StarlinkMailWorker's, the WASender
 *       and no-instance counts, a throwing or locked ledger never stopping a send, the send-site inventory (HK7); and
 *       L-ON's halves (5.18.104 (root docs/73 §H.2.14), built with lib/SalesFollowupChecks.php in stage B9): its PASS
 *       beside a lost write, its hook control (HK11, HK12) and the switch day (HK14), each with its hook_write_lost count
 *
 * Every section begins with its positive control. Then the weakened copies (W), each a copy of the plugin with one
 * anchored edit, judged by this file run with --root=<the copy> --sections=<its sections>: a copy not caught is a
 * failure, never a skip; an anchor not found exactly once is a failure too.
 *
 *   cd tests && DN_VAULT_FILE=$(mktemp -d)/v.json php test_wa_send_budget.php [--root=DIR] [--sections=LG,HK]
 *        [--cases=REGEX] [--no-mutants] [--mutants=REGEX]
 *
 * The process clock is UTC on purpose; the install's zone is Kampala by configuration (dn_tz()), and the ledger must
 * compute every window and day in a literal Kampala either way. Every number, name and message is fictitious; nothing
 * leaves 127.0.0.1. L-ON's halves of HK run its check here, on a read-only connection, with the process zone set to
 * Kampala around the sends they judge (the Message Log is written with date(), in the install's zone); the CK forms of
 * HK15, HK16 and HK17 are tests/test_sales_followup_tool.php's (5.18.104 (root docs/73 §H.2.14)).
 */
define('QF_TZ', 'Africa/Kampala');
require_once __DIR__ . '/quote_followup/_lib.php';
date_default_timezone_set('UTC');

$O   = qf_options();
$own = qf_own_tmp('wsb');
$ctx = new QfCtx($O['root'], $own);
$ctx->cases = $O['cases'];
$TREE = $ctx->tree();
foreach (['StoreInterface', 'SqliteStore', 'timezone', 'WaSendBudget', 'HumanTakeover', 'VoiceAckLimits', 'ReminderDay',
          'EvolutionApiService', 'NotificationService', 'AlertService',
          'UtcClock', 'PluginLog', 'ContactOptOut', 'SalesFollowupChecks'] as $lib) {   // 5.18.104 (root docs/73 §H.2.14): L-ON's halves
    require_once $TREE . '/lib/' . $lib . '.php';
}

// ── helpers ─────────────────────────────────────────────────────────────────────────────────────────────────────────

/** A ledger on $b with the clock at $T (by reference) and a gap draw that takes the minimum unless told otherwise. */
function wsb_ledger(array $b, int &$T, ?callable $handsetOf = null, ?callable $rand = null): WaSendBudget
{
    return WaSendBudget::forInstall($b['cfg'], $b['data'], $b['store'], $handsetOf,
        function () use (&$T): int { return $T; }, $rand ?? function (int $a, int $c): int { return $a; });
}

/** master_schedule.json saying today's reminder job ran at $at and finished. */
function wsb_reminders_done(array $b, int $at): void
{
    $b['store']->save('master_schedule.json', ['customer_reminders' => ['last_run' => $at, 'last_run_at' => 'x', 'duration_ms' => 1200]]);
}

function wsb_rows(\PDO $pdo, string $where = '1', array $a = []): array
{
    $st = $pdo->prepare("SELECT * FROM wa_auto_sends WHERE {$where} ORDER BY id");
    $st->execute($a);
    return $st->fetchAll(\PDO::FETCH_ASSOC);
}

function wsb_one(\PDO $pdo, string $sql, array $a = [])
{
    $st = $pdo->prepare($sql);
    $st->execute($a);
    return $st->fetchColumn();
}

/** The answer's code, 'ok' for a claim. */
function wsb_code(array $r): string
{
    return !empty($r['ok']) ? 'ok' : (string)($r['defer'] ?? '?');
}

/** A book with the ledger on, reminders done at 09:00 Monday, for the common case. */
function wsb_ug(QfCtx $ctx, array $cfg = []): array
{
    $b = $ctx->book($cfg + QF_UG);
    wsb_reminders_done($b, qf_t(QF_MONDAY . ' 09:00:00'));
    return $b;
}

/** A conversation row for a phone on a channel; its id. */
function wsb_conv(\PDO $pdo, string $phone, string $channel = 'support', ?string $category = null): int
{
    $pdo->prepare("INSERT INTO wa_conversations (phone, channel, category) VALUES (?, ?, ?)")->execute([$phone, $channel, $category]);
    return (int)$pdo->lastInsertId();
}

/** A process that takes BEGIN IMMEDIATE on a book for $holdS seconds once $startFile exists; it writes $lockedFile when held. */
function wsb_holder(string $db, string $startFile, string $lockedFile, float $holdS, string $releaseFile = ''): array
{
    $code = '$p = new PDO("sqlite:" . $argv[1]); $p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);'
          . '$p->exec("PRAGMA busy_timeout = 5000");'
          . 'for ($i = 0; $i < 600 && !is_file($argv[2]); $i++) usleep(50000);'
          . '$p->exec("BEGIN IMMEDIATE"); touch($argv[3]);'
          . 'if ($argv[5] !== "") touch($argv[5]);'
          . 'usleep((int)((float)$argv[4] * 1000000)); $p->exec("ROLLBACK");';
    $proc = proc_open(['php', '-r', $code, $db, $startFile, $lockedFile, (string)$holdS, $releaseFile],
        [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
    return [$proc];
}

function wsb_wait_file(string $f, float $s): bool
{
    for ($i = 0; $i < (int)($s * 20); $i++) { if (is_file($f)) return true; usleep(50000); }
    return is_file($f);
}

// 5.18.104 (root docs/73 §H.2.14): L-ON's halves of HK — its check on a read-only connection, the Kampala process zone
// around the sends it judges, data/plugin.log reset to cover the days judged, and never a run across Kampala midnight.

/** L-ON on $b as the tool runs it: a query_only connection, asserted unwritten. */
function wsb_lon(QfCtx $ctx, array $b, array $cfg, int $now): array
{
    $ro = new \PDO('sqlite:' . $b['data'] . '/plugin.sqlite3', null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
    $ro->exec('PRAGMA busy_timeout = 5000');
    $ro->exec('PRAGMA query_only = ON');
    $r = SalesFollowupChecks::check($ro, $cfg, $b['data'], 'L-ON', $now);
    if ((int)$ro->query('SELECT total_changes()')->fetchColumn() !== 0) $ctx->t(false, 'HK L-ON wrote through its read-only connection');
    return $r;
}

/** $fn run with the process zone at Kampala, as the install's own runs are (dn_tz()); UTC again after. B9-fix1: the install
 *  zone PluginLog stamps its lines in too (dn_tz() with no configuration reads the tree copy's empty data/, which names no
 *  zone, so the lines were Juba's and, from 00:00 to 01:00 Kampala, on the previous day: HK10 read FAIL hook_silent then). */
function wsb_kampala(callable $fn)
{
    $c =& dn_tz_cache(); $was = $c; $c['tz'] = QF_TZ;
    date_default_timezone_set(QF_TZ);
    try { return $fn(); } finally { date_default_timezone_set('UTC'); $c = $was; }
}

/** The tree's data/plugin.log reset to one dated line at $kampalaAt (a Kampala wall time): it covers the days after it. */
function wsb_log_reset(QfCtx $ctx, string $kampalaAt): void
{
    file_put_contents($ctx->tree() . '/data/plugin.log', '[' . $kampalaAt . '] [test] the log begins (fixture)' . "\n");
}

/** Waits out the last three minutes of a Kampala day, so the sends a case judges by their day share one. */
function wsb_clear_of_midnight(): void
{
    $k = new \DateTime('now', new \DateTimeZone(QF_TZ));
    $left = 86400 - ((int)$k->format('G') * 3600 + (int)$k->format('i') * 60 + (int)$k->format('s'));
    if ($left < 180) sleep($left + 2);
}

/** The last Message Log row re-dated to a Kampala wall time (a send made by the real sender, placed on the case's day). */
function wsb_redate(\PDO $pdo, string $kampalaAt): void
{
    $pdo->prepare('UPDATE notification_audit_log SET sent_at = ? WHERE id = (SELECT MAX(id) FROM notification_audit_log)')->execute([$kampalaAt]);
}

/** A status, its hook_write_lost count and the detail, for an assertion. */
function wsb_lst(array $r, string ...$counts): array
{
    $out = [(string)($r['status'] ?? '?')];
    foreach ($counts as $c) $out[] = (int)($r['counts'][$c] ?? -1);
    return $out;
}

// ── LG: the ledger ──────────────────────────────────────────────────────────────────────────────────────────────────

$SECTIONS = [];
$SECTIONS['LG'] = ['title' => 'The ledger (§H.2.5; rulings R2, R4, R18)', 'run' => function (QfCtx $ctx) {
    $MON = function (string $hms) { return qf_t(QF_MONDAY . ' ' . $hms); };

    $ctx->run('LG01 positive control: a quote claim, its row, its settle', function () use ($ctx, $MON) {
        $b = wsb_ug($ctx); $T = $MON('10:00:00'); $L = wsb_ledger($b, $T);
        $r = $L->claim('DishNet-UG', 'quote', 'fu:1:1', ['phone' => '0772500101']);
        $ctx->eq(wsb_code($r), 'ok', 'LG01 a quote claim inside the window, reminders done, is ok');
        $ctx->t(is_int($r['claim'] ?? null) && $r['claim'] > 0, 'LG01 the answer carries the claim id');
        $row = wsb_rows($b['pdo'])[0] ?? [];
        $ctx->eq([$row['handset'] ?? null, $row['kind'] ?? null, (int)($row['try'] ?? 0), $row['mode'] ?? null, (int)($row['proactive'] ?? -1),
                  $row['phone'] ?? null, $row['local_day'] ?? null, (int)($row['gap_s'] ?? -1), $row['claimed_at'] ?? null, array_key_exists('outcome', $row) ? $row['outcome'] : 'x'],
                 ['i:dishnet-ug', 'quote', 1, 'claim', 1, '256772500101', QF_MONDAY, 60, '2026-11-02 07:00:00', null],
                 'LG01 the row: handset normalised, try 1, a claim, proactive, the phone in international digits, the Kampala day, the gap drawn, the claim\'s moment, in flight');
        $ctx->eq([(int)$row['win_from'], (int)$row['win_to'], (int)$row['cap_day'], (int)$row['cap_hour']], [8, 20, 60, 15],
                 'LG01 the limits it applied: the window 08-20 and the caps 60 and 15 (D3, D12)');
        $L->settle((int)$r['claim'], 'sent', ['wa_message_id' => 'WA-LG01']);
        $row = wsb_rows($b['pdo'])[0];
        $ctx->eq([$row['outcome'], $row['wa_message_id'], $row['settled_at']], ['sent', 'WA-LG01', '2026-11-02 07:00:00'],
                 'LG01 settled sent with the message id');
        // WB3: the same (kind, ref) again
        $T = $MON('10:05:00');
        $ctx->eq($L->claim('DishNet-UG', 'quote', 'fu:1:1', ['phone' => '0772500101']),
                 ['ok' => false, 'defer' => 'already_sent', 'retry_at' => null, 'final' => true],
                 'LG02 the same (kind, ref) once sent answers already_sent, final, retry_at null');
        $ctx->eq(count(wsb_rows($b['pdo'])), 1, 'LG02 and inserts nothing');
    });

    $ctx->run('LG03-05 in flight, stale, tries', function () use ($ctx, $MON) {
        $b = wsb_ug($ctx); $T = $MON('10:00:00'); $L = wsb_ledger($b, $T);
        $c = $L->claim('DishNet-UG', 'staff_list', 'list:2026-11-02:central');
        $ctx->eq(wsb_code($c), 'ok', 'LG03 control: the list claim is ok');
        $T += 10;
        $r = $L->claim('DishNet-UG', 'staff_list', 'list:2026-11-02:central');
        $ctx->eq([wsb_code($r), $r['retry_at'] ?? null, $r['final'] ?? null], ['in_flight', $MON('10:00:00') + 900, false],
                 'LG03 the same ref in flight answers in_flight, retry at claimed_at + 900, not final');
        $T = $MON('10:00:00') + 901;
        $r = $L->claim('DishNet-UG', 'staff_list', 'list:2026-11-02:central');
        $ctx->eq(wsb_code($r), 'already_sent', 'LG04 past 900 s the stale claim is never re-sent: already_sent');
        $row = wsb_rows($b['pdo'])[0];
        $ctx->eq([$row['outcome'], $row['detail'], count(wsb_rows($b['pdo']))], ['uncertain', 'stale', 1],
                 'LG04 the stale row is settled uncertain (stale), counted as sent, and nothing new is inserted');

        $b = wsb_ug($ctx); $T = $MON('11:00:00'); $L = wsb_ledger($b, $T);
        $codes = [];
        for ($i = 1; $i <= 3; $i++) {
            $c = $L->claim('DishNet-UG', 'quote', 'fu:5:1', ['phone' => '0772500105']);
            $codes[] = wsb_code($c);
            if (!empty($c['ok'])) $L->settle((int)$c['claim'], 'failed', ['detail' => 'evo_error']);
            $T += 5;
        }
        $ctx->eq($codes, ['ok', 'ok', 'ok'], 'LG05 control: three tries after not_sent are each claimed');
        $ctx->eq(array_map('intval', array_column(wsb_rows($b['pdo']), 'try')), [1, 2, 3], 'LG05 try counts 1, 2, 3');
        $ctx->eq($L->claim('DishNet-UG', 'quote', 'fu:5:1', ['phone' => '0772500105']),
                 ['ok' => false, 'defer' => 'tries_exhausted', 'retry_at' => null, 'final' => true], 'LG05 a fourth try: tries_exhausted, final');
    });

    $ctx->run('LG06 not_sent rows are not counted in the caps', function () use ($ctx, $MON) {
        $b = wsb_ug($ctx, ['wa_send_daily_cap' => 2, 'wa_send_hourly_cap' => 2]); $T = $MON('10:00:00'); $L = wsb_ledger($b, $T);
        foreach (['fu:61:1' => '0772500161', 'fu:62:1' => '0772500162'] as $ref => $p) {
            $c = $L->claim('DishNet-UG', 'quote', $ref, ['phone' => $p]);
            if (!empty($c['ok'])) $L->settle((int)$c['claim'], 'failed');
            $T += 5;
        }
        $ctx->eq(wsb_one($b['pdo'], "SELECT COUNT(*) FROM wa_auto_sends WHERE outcome = 'not_sent'"), 2, 'LG06 control: two not_sent rows today');
        $ctx->eq(wsb_code($L->claim('DishNet-UG', 'quote', 'fu:63:1', ['phone' => '0772500163'])), 'ok',
                 'LG06 with caps of 2 and two not_sent rows, a third claim is ok');
    });

    $ctx->run('LG07 the day is Kampala\'s', function () use ($ctx) {
        $b = $ctx->book(QF_UG);
        $T = qf_t('2026-11-03 00:30:00'); $L = wsb_ledger($b, $T);   // Monday 21:30 UTC
        $ctx->eq($L->record('DishNet-UG', 'reminder', 'n:LG07', 'sent', ['phone' => '0772500107']), 'recorded', 'LG07 control: a reminder recorded at 00:30 Tuesday');
        $ctx->eq(wsb_rows($b['pdo'])[0]['local_day'] ?? null, '2026-11-03', 'LG07 its day is Tuesday, Kampala, not Monday (UTC)');
        wsb_reminders_done($b, qf_t('2026-11-03 09:00:00'));
        $T = qf_t('2026-11-03 10:00:00');
        $ctx->eq(wsb_code($L->claim('DishNet-UG', 'quote', 'fu:7:1', ['phone' => '+256772500107'])), 'phone_today',
                 'LG07 a chase to the same person at 10:00 Tuesday: phone_today');
    });

    $ctx->run('LG08 the handset is the instance, never the channel', function () use ($ctx) {
        $b = $ctx->book(QF_UG);
        WaSendBudget::recordNotify($b['store'], $b['cfg'], 'account', 'ops_pre_due_d3', '0772500108', true, '', json_encode(['data' => ['key' => ['id' => 'WA-LG08A']]]));
        WaSendBudget::recordNotify($b['store'], $b['cfg'], 'support', 'ops_pre_due_d1', '0772500109', true, '', json_encode(['data' => ['key' => ['id' => 'WA-LG08S']]]));
        $ctx->eq(array_column(wsb_rows($b['pdo']), 'handset'), ['i:dishnet-ug', 'i:dishnet-ug'],
                 'LG08 support and account share one handset: both rows on i:dishnet-ug');
        $ctx->eq(WaSendBudget::handset('DishNet-UG'), 'i:dishnet-ug', 'LG08 handset() of the instance');
    });

    $ctx->run('LG09-11 the hourly cap and the spacing', function () use ($ctx, $MON) {
        $b = wsb_ug($ctx, ['wa_send_hourly_cap' => 2]); $T = $MON('10:00:00'); $L = wsb_ledger($b, $T);
        foreach (['fu:91:1' => '0772500191', 'fu:92:1' => '0772500192'] as $ref => $p) {
            $c = $L->claim('DishNet-UG', 'quote', $ref, ['phone' => $p]);
            $L->settle((int)($c['claim'] ?? 0), 'sent');
            $T += 200;
        }
        $r = $L->claim('DishNet-UG', 'quote', 'fu:93:1', ['phone' => '0772500193']);
        $ctx->eq([wsb_code($r), $r['retry_at'] ?? null], ['hour_cap', $MON('10:00:00') + 3600], 'LG09 a third proactive send in the hour: hour_cap, retry when the oldest leaves it');

        $b = wsb_ug($ctx); $T = $MON('10:00:00'); $L = wsb_ledger($b, $T);
        $c = $L->claim('DishNet-UG', 'voice_ack', 'ack:WA-IN-10');
        $ctx->eq(wsb_code($c), 'ok', 'LG10 control: an acknowledgement claimed');
        $L->settle((int)($c['claim'] ?? 0), 'sent', ['wa_message_id' => 'WA-ACK-10']);
        $T += 30;
        $r = $L->claim('DishNet-UG', 'quote', 'fu:10:1', ['phone' => '0772500110']);
        $ctx->eq([wsb_code($r), $r['retry_at'] ?? null], ['spacing', $MON('10:00:00') + 60], 'LG10 a chase 30 s after an acknowledgement waits its spacing (any kind)');
        $T += 31;
        $ctx->eq(wsb_code($L->claim('DishNet-UG', 'quote', 'fu:10:1', ['phone' => '0772500110'])), 'ok', 'LG10 control: after the gap it is ok');

        $b = wsb_ug($ctx); $T = $MON('10:00:00'); $L = wsb_ledger($b, $T);
        $ctx->eq($L->record('DishNet-UG', 'reminder', 'n:LG11', 'sent', ['phone' => '0772500111']), 'recorded', 'LG11 control: a reminder recorded');
        $T += 30;
        $ctx->eq(wsb_code($L->claim('DishNet-UG', 'quote', 'fu:11:1', ['phone' => '0772500112'])), 'spacing',
                 'LG11 a chase 30 s after a recorded reminder waits its spacing (record rows count)');
    });

    $ctx->run('LG12-14 voice_ack is a reply; its ceiling; recorded proactive rows count', function () use ($ctx, $MON) {
        $b = wsb_ug($ctx, ['wa_send_daily_cap' => 1]); $T = $MON('10:00:00'); $L = wsb_ledger($b, $T);
        $c = $L->claim('DishNet-UG', 'quote', 'fu:12:1', ['phone' => '0772500120']);
        $L->settle((int)($c['claim'] ?? 0), 'sent');
        $ctx->eq(wsb_one($b['pdo'], "SELECT COUNT(*) FROM wa_auto_sends WHERE proactive = 1"), 1, 'LG12 control: the day cap of 1 is used');
        $T += 100;
        $ctx->eq(wsb_code($L->claim('DishNet-UG', 'voice_ack', 'ack:WA-IN-12')), 'ok', 'LG12 an acknowledgement is not refused by the proactive day cap');

        $b = wsb_ug($ctx, ['wa_voice_ack_daily_max' => 2]); $T = $MON('10:00:00'); $L = wsb_ledger($b, $T);
        $codes = [];
        foreach (['ack:WA-13-1', 'ack:WA-13-2', 'ack:WA-13-3'] as $ref) {
            $c = $L->claim('DishNet-UG', 'voice_ack', $ref);
            $codes[] = wsb_code($c);
            if (!empty($c['ok'])) $L->settle((int)$c['claim'], 'sent');
            $T += 30;
        }
        $ctx->eq($codes, ['ok', 'ok', 'ack_ceiling'], 'LG13 the third acknowledgement of the day with a ceiling of 2: ack_ceiling');
        $T += 30;
        $ctx->eq($L->claim('DishNet-UG', 'voice_ack', 'ack:WA-13-4'), ['ok' => false, 'defer' => 'ack_ceiling', 'retry_at' => null, 'final' => true],
                 'LG13 ack_ceiling is final with retry_at null');

        $b = wsb_ug($ctx, ['wa_send_daily_cap' => 2]); $T = $MON('09:40:00'); $L = wsb_ledger($b, $T);
        $L->record('DishNet-UG', 'reminder', 'n:LG14A', 'sent', ['phone' => '0772500141']);
        $T += 200;
        $L->record('DishNet-UG', 'winback', 'n:LG14B', 'sent', ['phone' => '0772500142']);
        $T = $MON('10:00:00');
        $ctx->eq(wsb_code($L->claim('DishNet-UG', 'quote', 'fu:14:1', ['phone' => '0772500143'])), 'day_cap',
                 'LG14 two recorded proactive sends fill a day cap of 2: a chase answers day_cap');
    });

    $ctx->run('LG15-16 one automated message per person per Kampala day, across handsets', function () use ($ctx, $MON) {
        $b = wsb_ug($ctx); $T = $MON('10:00:00'); $L = wsb_ledger($b, $T);
        $c = $L->claim('DishNet-UG', 'quote', 'fu:15:1', ['phone' => '0772500150']);
        $L->settle((int)($c['claim'] ?? 0), 'sent');
        $T += 300;
        $ctx->eq(wsb_code($L->claim('DishNet-UG', 'enquiry', 'fu:16:1', ['phone' => '256772500150'])), 'phone_today',
                 'LG15 an enquiry follow-up to the same person later that day: phone_today');
        $T += 10;
        $ctx->eq(wsb_code($L->claim('Sales-UG', 'offer', 'offer:1:15', ['phone' => '+256 772 500 150'])), 'phone_today',
                 'LG16 an offer to the same person from another handset: phone_today');
        $ctx->eq(wsb_code($L->claim('Sales-UG', 'offer', 'offer:1:16', ['phone' => '0772500151'])), 'ok',
                 'LG16 control: an offer to another person from that handset is ok');
    });

    $ctx->run('LG17 the reservation: offers use only what the chases leave (and WB36)', function () use ($ctx, $MON) {
        $mk = function () use ($ctx, $MON): array {
            $b = wsb_ug($ctx, ['wa_send_daily_cap' => 2]); $T = $MON('10:00:00');
            $L = wsb_ledger($b, $T);
            $L->record('DishNet-UG', 'brief', 'n:LG17B', 'sent');
            $cid = wsb_conv($b['pdo'], '256772500170');
            $b['pdo']->prepare("INSERT INTO followups (conversation_id, channel, phone, last_customer_at, due_at, kind, quote_key)
                                VALUES (?, 'support', '256772500170', '2026-11-01 08:00:00', ?, 'quote', 'ref:Q-LG17')")
                     ->execute([$cid, UtcClock::stamp($MON('15:00:00'))]);
            return $b;
        };
        $b = $mk(); $T = $MON('10:05:00'); $L = wsb_ledger($b, $T);
        $r = $L->claim('DishNet-UG', 'offer', 'offer:2:1', ['phone' => '0772500171', 'in_flight_s' => 50]);
        $ctx->eq([wsb_code($r), $r['retry_at'] ?? null], ['reserved', qf_t('2026-11-03 08:00:00')],
                 'LG17 one used, one chase due today, a cap of 2: the offer answers reserved, retry at the next day\'s opening');
        $ctx->eq(wsb_code($L->claim('DishNet-UG', 'quote', 'fu:17:1', ['phone' => '0772500172'])), 'ok', 'LG17 the chase itself is ok');

        $b = $mk(); $T = $MON('10:05:00');
        $ident = function (string $ch): string { return $ch; };
        $old = getenv(WaSendBudget::TEST_SEAM_ENV);
        putenv(WaSendBudget::TEST_SEAM_ENV);
        $L = wsb_ledger($b, $T, $ident);
        $ctx->eq(wsb_code($L->claim('DishNet-UG', 'offer', 'offer:2:2', ['phone' => '0772500173'])), 'reserved',
                 'LG17 outside tests a caller\'s channel map is ignored: the derived map keeps the chase reserved (ruling R2)');
        putenv(WaSendBudget::TEST_SEAM_ENV . '=1');
        $L = wsb_ledger($b, $T, $ident);
        $ctx->eq(wsb_code($L->claim('DishNet-UG', 'offer', 'offer:2:3', ['phone' => '0772500174'])), 'ok',
                 'LG17 control on the control: under the test seam the identity map is honoured, and the reserve reads 0');
        putenv($old === false ? WaSendBudget::TEST_SEAM_ENV : WaSendBudget::TEST_SEAM_ENV . '=' . $old);
    });

    $ctx->run('LG18-21 the window in a literal Kampala, the zone, Sunday, the ledger off', function () use ($ctx, $MON) {
        $b = wsb_ug($ctx); $T = $MON('08:30:00'); $L = wsb_ledger($b, $T);   // 05:30 UTC
        $ctx->eq(wsb_code($L->claim('DishNet-UG', 'staff_list', 'list:2026-11-02:central')), 'ok', 'LG18 08:30 Kampala (05:30 UTC) is inside the window');
        $b = wsb_ug($ctx); $T = $MON('22:30:00'); $L = wsb_ledger($b, $T);   // 19:30 UTC
        $r = $L->claim('DishNet-UG', 'staff_list', 'list:2026-11-02:central');
        $ctx->eq([wsb_code($r), $r['retry_at'] ?? null], ['window', qf_t('2026-11-03 08:00:00')], 'LG18 22:30 Kampala (19:30 UTC) is outside: window, retry Tuesday 08:00');

        $b = wsb_ug($ctx, ['timezone' => 'Africa/Juba']); $T = $MON('10:00:00'); $L = wsb_ledger($b, $T);
        $ctx->eq($L->claim('DishNet-UG', 'staff_list', 'list:2026-11-02:central'), ['ok' => false, 'defer' => 'zone', 'retry_at' => null, 'final' => true],
                 'LG19 dn_tz() not Kampala: zone, final');

        $b = wsb_ug($ctx); $T = qf_t('2026-11-08 10:00:00'); $L = wsb_ledger($b, $T);
        $r = $L->claim('DishNet-UG', 'staff_list', 'list:2026-11-08:central');
        $ctx->eq([wsb_code($r), $r['retry_at'] ?? null], ['window', qf_t('2026-11-09 08:00:00')], 'LG20 Sunday is never open: window, retry Monday 08:00');

        foreach (['unset' => null, 'off' => 'off', 'not Uganda' => 'on'] as $why => $v) {
            $cfg = QF_UG; if ($v === null) unset($cfg['wa_send_ledger']); else $cfg['wa_send_ledger'] = $v;
            if ($why === 'not Uganda') $cfg['tenant_profile'] = 'south-sudan';
            $b = $ctx->book($cfg); $T = $MON('10:00:00'); $L = wsb_ledger($b, $T);
            $ctx->eq($L->claim('DishNet-UG', 'staff_list', 'list:2026-11-02:central'), ['ok' => false, 'defer' => 'ledger_off', 'retry_at' => null, 'final' => true],
                     "LG21 the ledger off ({$why}): ledger_off, final");
            $ctx->eq(count(wsb_rows($b['pdo'])), 0, "LG21 ({$why}) and nothing inserted");
        }
    });

    $ctx->run('LG22-25 record never throws or writes off Uganda; the handset normalised; settle once', function () use ($ctx, $MON) {
        $b = wsb_ug($ctx); $T = $MON('10:00:00'); $L = wsb_ledger($b, $T);
        $ctx->eq($L->record('DishNet-UG', 'reminder', 'n:LG22-control', 'sent', ['phone' => '0772500220']), 'recorded', 'LG22 control: a record on a sound table');
        $b['pdo']->exec('DROP TABLE wa_auto_sends');
        $b['pdo']->exec('CREATE TABLE wa_auto_sends (id INTEGER PRIMARY KEY, handset TEXT)');
        $n0 = $ctx->logLines('hook_write_lost reminder');
        $code = null; $threw = '';
        try { $code = $L->record('DishNet-UG', 'reminder', 'n:LG22', 'sent', ['phone' => '0772500221']); } catch (\Throwable $e) { $threw = $e->getMessage(); }
        $ctx->eq([$threw, $code], ['', 'error'], 'LG22 a ledger that cannot be written: record() answers error and throws nothing');
        $ctx->eq($ctx->logLines('hook_write_lost reminder') - $n0, 1, 'LG22 one hook_write_lost reminder line, the kind only');
        $r = $L->claim('DishNet-UG', 'staff_list', 'list:2026-11-02:central');
        $ctx->eq([wsb_code($r), is_int($r['retry_at'] ?? null), $r['final'] ?? null], ['ledger_error', true, false],
                 'LG22 a claim on it: ledger_error, retried, never ok (the ledger fails closed)');

        $b = $ctx->book(['tenant_profile' => 'south-sudan'] + QF_UG); $T = $MON('10:00:00'); $L = wsb_ledger($b, $T);
        $ctx->eq($L->record('DishNet-UG', 'reminder', 'n:LG23', 'sent', ['phone' => '0912500230']), 'not_uganda', 'LG23 on South Sudan record() answers not_uganda');
        $ctx->eq(count(wsb_rows($b['pdo'])), 0, 'LG23 and writes no row');

        $ctx->eq([WaSendBudget::handset('  DishNet-UG '), WaSendBudget::handset('i:DISHNET-UG'), WaSendBudget::handset('I:dishnet-ug'),
                  WaSendBudget::handset('   '), WaSendBudget::handset('i:')], ['i:dishnet-ug', 'i:dishnet-ug', 'i:dishnet-ug', '', ''],
                 'LG24 handset() trims, lower-cases, is idempotent on an i: value, and is empty when blank');
        $b = wsb_ug($ctx); $T = $MON('10:00:00'); $L = wsb_ledger($b, $T);
        $L->record(' DISHNET-ug ', 'other', 'n:LG24', 'sent');
        $ctx->eq(array_column(wsb_rows($b['pdo']), 'handset'), ['i:dishnet-ug'], 'LG24 a raw mixed-case instance lands on the same handset');

        $T = $MON('10:30:00');
        $c = $L->claim('DishNet-UG', 'staff_list', 'list:2026-11-02:c2');
        $ctx->eq(wsb_code($c), 'ok', 'LG25 control: a claim to settle');
        $l0 = $ctx->logLines('late_settle'); $s0 = $ctx->logLines('settle_lost');
        $L->settle((int)$c['claim'], 'sent', ['wa_message_id' => 'WA-LG25']);
        $threw = '';
        try { $L->settle((int)$c['claim'], 'failed'); } catch (\Throwable $e) { $threw = $e->getMessage(); }
        $row = wsb_rows($b['pdo'], 'id = ?', [(int)$c['claim']])[0];
        $ctx->eq([$threw, $row['outcome'], $row['wa_message_id']], ['', 'sent', 'WA-LG25'], 'LG25 a second settle changes nothing and throws nothing');
        $ctx->eq([$ctx->logLines('late_settle') - $l0, $ctx->logLines('settle_lost') - $s0], [1, 0], 'LG25 it writes one late_settle line, and no settle_lost');
    });

    $ctx->run('LG26 Part F\'s instance name and Part V\'s and O\'s raw ones meet on one handset', function () use ($ctx, $MON) {
        $b = wsb_ug($ctx); $T = $MON('10:00:00'); $L = wsb_ledger($b, $T);
        $c = $L->claim('DishNet-UG', 'quote', 'fu:26:1', ['phone' => '0772500260']);
        $L->settle((int)($c['claim'] ?? 0), 'sent', ['wa_message_id' => 'WA-LG26']);
        $T += 10;
        $ctx->eq(wsb_code($L->claim(' DISHNET-ug ', 'voice_ack', 'ack:WA-IN-26')), 'spacing', 'LG26 an acknowledgement 10 s after a chase, named raw and mixed-case: spacing');
        $T += 20;
        $ctx->eq(wsb_code($L->claim('DISHNET-UG', 'offer', 'offer:26:1', ['phone' => '0772500261', 'in_flight_s' => 50])), 'spacing',
                 'LG26 an offer 30 s after it, named upper-case: spacing');
    });

    $ctx->run('LG27 reminders first: a chase at 08:30, then the reminder job for the same person: one message', function () use ($ctx, $MON) {
        $b = $ctx->book(QF_UG); $T = $MON('08:30:00'); $L = wsb_ledger($b, $T);
        $b['store']->save('master_schedule.json', ['customer_reminders' => ['last_run' => 1, 'last_run_at' => 'never', 'duration_ms' => 0]]);
        $r = $L->claim('DishNet-UG', 'quote', 'fu:27:1', ['phone' => '0772500270']);
        $ctx->eq([wsb_code($r), $r['retry_at'] ?? null], ['reminders_first', $MON('08:40:00')], 'LG27 08:30, reminders not run: reminders_first, retry at the earlier of now + 600 and 17:00');
        $T = $MON('09:05:00');
        $L->record('DishNet-UG', 'reminder', 'n:LG27', 'sent', ['phone' => '0772500270']);
        $b['store']->save('master_schedule.json', ['customer_reminders' => ['last_run' => $MON('09:00:00'), 'last_run_at' => 'x', 'duration_ms' => 1500]]);
        $T = $MON('09:30:00');
        $ctx->eq(wsb_code($L->claim('DishNet-UG', 'quote', 'fu:27:1', ['phone' => '0772500270'])), 'phone_today', 'LG27 09:30, the reminder went: phone_today');
        $ctx->eq((int)wsb_one($b['pdo'], "SELECT COUNT(*) FROM wa_auto_sends WHERE phone = '256772500270'"), 1, 'LG27 one message to that person today');
        $b['store']->save('master_schedule.json', ['customer_reminders' => ['last_run' => $MON('09:00:00'), 'last_run_at' => 'x', 'duration_ms' => -1]]);
        $T = $MON('10:00:00');
        $ctx->eq(wsb_code($L->claim('DishNet-UG', 'quote', 'fu:27:2', ['phone' => '0772500271'])), 'reminders_first', 'LG27 a crashed reminder run (-1) still holds');
        $T = $MON('17:00:00');
        $ctx->eq(wsb_code($L->claim('DishNet-UG', 'quote', 'fu:27:2', ['phone' => '0772500271'])), 'ok', 'LG27 … until reminder_until_hour, never later');
        $hours = [];
        $master = (string)file_get_contents($ctx->tree() . '/cron/master.php');
        $ok = preg_match('/(\$_m_remHour\s*=.*?\n)(\$_m_remUntil\s*=.*?\n)(if \(\$_m_remHour.*?\n)(if \(\$_m_remUntil.*?\n)/s', $master, $mm);
        $ctx->t($ok === 1, 'LG27 cron/master.php\'s four normalising lines are found');
        $same = true;
        foreach ([[], ['reminder_hour' => 7], ['reminder_hour' => -2], ['reminder_hour' => 30], ['reminder_until_hour' => 5],
                  ['reminder_until_hour' => 30], ['reminder_hour' => 20, 'reminder_until_hour' => 19], ['reminder_hour' => '10', 'reminder_until_hour' => '12'],
                  ['reminder_hour' => 23]] as $cfg) {
            $_m_config = $cfg;
            if ($ok === 1) eval($mm[1] . $mm[2] . $mm[3] . $mm[4]);
            if ($ok !== 1 || ReminderDay::hours($cfg) !== [$_m_remHour, $_m_remUntil]) $same = false;
        }
        $ctx->t($same, 'LG27 ReminderDay::hours() equals master\'s normalisation over a table of inputs');
    });

    $ctx->run('LG28 an orphan on the handset is settled by the next claim; its late settle only logs', function () use ($ctx, $MON) {
        $b = wsb_ug($ctx); $T = $MON('10:00:00'); $L = wsb_ledger($b, $T);
        $o = $L->claim('DishNet-UG', 'offer', 'offer:5:9', ['phone' => '0772500280', 'in_flight_s' => 50]);
        $ctx->eq(wsb_code($o), 'ok', 'LG28 control: an offer claimed and never settled');
        $T += 901;
        $ctx->eq(wsb_code($L->claim('DishNet-UG', 'quote', 'fu:28:1', ['phone' => '0772500281'])), 'ok', 'LG28 a claim of another kind and ref 901 s later is ok');
        $row = wsb_rows($b['pdo'], 'id = ?', [(int)($o['claim'] ?? 0)])[0] ?? [];
        $ctx->eq([$row['outcome'] ?? null, $row['detail'] ?? null], ['uncertain', 'stale_orphan'], 'LG28 the orphan is settled uncertain, stale_orphan');
        $l0 = $ctx->logLines('late_settle'); $threw = '';
        try { $L->settle((int)($o['claim'] ?? 0), 'sent', ['wa_message_id' => 'WA-LG28']); } catch (\Throwable $e) { $threw = $e->getMessage(); }
        $row = wsb_rows($b['pdo'], 'id = ?', [(int)($o['claim'] ?? 0)])[0] ?? [];
        $ctx->eq([$threw, $row['outcome'] ?? null, $ctx->logLines('late_settle') - $l0], ['', 'uncertain', 1], 'LG28 its late settle throws nothing, changes nothing, logs late_settle');
    });

    $ctx->run('LG29 Part V\'s and Part O\'s call shapes; their echoes stored as Team are ours', function () use ($ctx, $MON) {
        $b = wsb_ug($ctx); $T = $MON('10:00:00'); $L = wsb_ledger($b, $T);
        $cid = wsb_conv($b['pdo'], '256772500290');
        $o = $L->claim('DishNet-UG', 'offer', 'offer:7:3', ['phone' => '256772500290', 'in_flight_s' => 50]);
        $ctx->eq(wsb_code($o), 'ok', 'LG29 Part O\'s claim: the stable ref, the phone, in_flight_s 50');
        $L->settle((int)($o['claim'] ?? 0), 'failed', ['maybe_sent' => true, 'wa_message_id' => 'WA-OFFER-7-3']);
        $row = wsb_rows($b['pdo'], 'id = ?', [(int)($o['claim'] ?? 0)])[0] ?? [];
        $ctx->eq([$row['outcome'] ?? null, $row['wa_message_id'] ?? null], ['uncertain', 'WA-OFFER-7-3'], 'LG29 a may-have-been-sent result settled failed + maybe_sent is stored uncertain, with its id');
        $T += 200;
        $a = $L->claim('DishNet-UG', 'voice_ack', 'ack:WA-IN-29');
        $ctx->eq(wsb_code($a), 'ok', 'LG29 Part V\'s claim: voice_ack, ack:<the voice note\'s id>');
        $L->settle((int)($a['claim'] ?? 0), 'sent', ['wa_message_id' => 'WA-ACK-29']);
        foreach (['WA-OFFER-7-3', 'WA-ACK-29'] as $id) {
            $b['pdo']->prepare("INSERT INTO wa_messages (conversation_id, direction, role, body, agent_name, wa_message_id, sent_at)
                                VALUES (?, 'out', 'agent', 'echo (test)', 'Team', ?, ?)")->execute([$cid, $id, UtcClock::stamp($T)]);
        }
        $ctx->eq(HumanTakeover::since($b['pdo'], '256772500290', [$cid], UtcClock::stamp($MON('09:00:00'))), null,
                 'LG29 their echoes stored as Team do not read as a person (the ledger holds their ids)');
        $b['pdo']->prepare("INSERT INTO wa_messages (conversation_id, direction, role, body, agent_name, wa_message_id, sent_at)
                            VALUES (?, 'out', 'agent', 'a colleague (test)', 'Team', 'WA-PERSON-29', ?)")->execute([$cid, UtcClock::stamp($T)]);
        $ctx->eq(HumanTakeover::since($b['pdo'], '256772500290', [$cid], UtcClock::stamp($MON('09:00:00'))), 'team_message',
                 'LG29 control: a Team row whose id the ledger does not hold is a person');

        $ctx->eq(WaSendBudget::window(['wa_send_hour_to' => 18] + QF_UG), [8, 18], 'LG29 window() reads the settings');
        $same = true;
        foreach ([[], ['wa_voice_ack_hour_to' => 20], ['wa_voice_ack_gap_s' => '45'], ['wa_voice_ack_daily_max' => 'x'], ['wa_voice_ack_hour_from' => 6]] as $c) {
            if (WaSendBudget::ackPolicy($c) !== VoiceAckLimits::of($c)) $same = false;
        }
        $ctx->t($same, 'LG29 ackPolicy() equals VoiceAckLimits::of() (ruling R4)');
        foreach (['wa_voice_ack_gap_s' => 'abc', 'wa_voice_ack_hour_to' => 25, 'wa_voice_ack_hour_from' => 6] as $k => $v) {
            $b = wsb_ug($ctx, [$k => $v]); $T = $MON('10:00:00'); $L = wsb_ledger($b, $T);
            $ctx->eq($L->claim('DishNet-UG', 'voice_ack', 'ack:WA-IN-29b'), ['ok' => false, 'defer' => 'window', 'retry_at' => null, 'final' => true],
                     "LG29 an invalid stored {$k}: ackPolicy() is null and voice_ack answers window, final");
        }
        $b = wsb_ug($ctx, ['wa_voice_ack_hour_to' => 20]); $T = $MON('20:30:00'); $L = wsb_ledger($b, $T);
        $ctx->eq(wsb_code($L->claim('DishNet-UG', 'voice_ack', 'ack:WA-IN-29c')), 'window', 'LG29 hour_to narrowed to 20: a 20:30 acknowledgement answers window');
        $T = $MON('19:30:00');
        $ctx->eq(wsb_code($L->claim('DishNet-UG', 'voice_ack', 'ack:WA-IN-29d')), 'ok', 'LG29 control: at 19:30 it is ok');
        $b = wsb_ug($ctx); $T = $MON('20:30:00'); $L = wsb_ledger($b, $T);
        $ctx->eq(wsb_code($L->claim('DishNet-UG', 'voice_ack', 'ack:WA-IN-29e')), 'ok', 'LG29 with D15\'s default 07-21 an acknowledgement at 20:30 is ok (a reply, every day)');
    });

    $ctx->run('LG30 ruling R2\'s contract: no_phone, one phone form, retry_at, record()\'s codes and one form', function () use ($ctx, $MON) {
        $b = wsb_ug($ctx); $T = $MON('10:00:00'); $L = wsb_ledger($b, $T);
        foreach (['quote', 'enquiry', 'offer'] as $k) {
            $ctx->eq($L->claim('DishNet-UG', $k, "x:{$k}:1"), ['ok' => false, 'defer' => 'no_phone', 'retry_at' => null, 'final' => true], "LG30 a {$k} claim with no phone: no_phone, final");
            $ctx->eq(wsb_code($L->claim('DishNet-UG', $k, "x:{$k}:2", ['phone' => '12'])), 'no_phone', "LG30 a {$k} claim whose phone does not normalise: no_phone");
        }
        $ctx->eq(count(wsb_rows($b['pdo'])), 0, 'LG30 and nothing inserted');
        $L->record('DishNet-UG', 'reminder', 'n:LG30', 'sent', ['phone' => '0772 500 301']);
        $T += 600;
        $ctx->eq(wsb_code($L->claim('Sales-UG', 'quote', 'fu:30:1', ['phone' => '+256772500301'])), 'phone_today',
                 'LG30 a reminder recorded with national digits and a chase claimed with international ones meet: phone_today');
        $ctx->eq(wsb_rows($b['pdo'])[0]['phone'] ?? null, '256772500301', 'LG30 the ledger stores its own form: international digits, no +');
        $r = $L->claim('Sales-UG', 'quote', 'fu:30:1', ['phone' => '+256772500301']);
        $ctx->t(is_int($r['retry_at'] ?? null), 'LG30 a defer\'s retry_at is an int unix time', json_encode($r));
        $ctx->t(($L->claim('Sales-UG', 'quote', 'x:q:3'))['retry_at'] === null, 'LG30 a final answer\'s retry_at is null');

        // record()'s five codes
        $ctx->eq($L->record('DishNet-UG', 'other', 'offer_test:1', 'sent', ['wa_message_id' => 'WA-T1']), 'recorded', 'LG30 record(): recorded');
        $off = $ctx->book(['wa_send_ledger' => 'off'] + QF_UG); $To = $MON('10:00:00');
        $ctx->eq(wsb_ledger($off, $To)->record('DishNet-UG', 'other', 'offer_test:2', 'sent'), 'ledger_off', 'LG30 record(): ledger_off');
        $ss = $ctx->book(['tenant_profile' => 'south-sudan'] + QF_UG);
        $ctx->eq(wsb_ledger($ss, $To)->record('DishNet-UG', 'other', 'offer_test:3', 'sent'), 'not_uganda', 'LG30 record(): not_uganda');
        $nt = $ctx->book(QF_UG); $nt['pdo']->exec('DROP TABLE wa_auto_sends');
        $ctx->eq(wsb_ledger($nt, $To)->record('DishNet-UG', 'other', 'offer_test:4', 'sent'), 'no_table', 'LG30 record(): no_table');
        $er = $ctx->book(QF_UG); $er['pdo']->exec('DROP TABLE wa_auto_sends'); $er['pdo']->exec('CREATE TABLE wa_auto_sends (x INTEGER)');
        $ctx->eq(wsb_ledger($er, $To)->record('DishNet-UG', 'other', 'offer_test:5', 'sent'), 'error', 'LG30 record(): error');
        foreach ([$off, $ss, $nt] as $bk) {
            $n = 0; try { $n = count(wsb_rows($bk['pdo'])); } catch (\Throwable $e) { $n = 0; }
            if ($n !== 0) $ctx->t(false, 'LG30 a code other than recorded wrote nothing');
        }

        // record()'s one form, as settle()'s
        $b = wsb_ug($ctx); $T = $MON('10:00:00'); $L = wsb_ledger($b, $T);
        $L->record('DishNet-UG', 'reminder', 'n:R1', 'failed', ['maybe_sent' => true, 'wa_message_id' => 'WA-R1', 'detail' => 'evo_error', 'phone' => '0772500311']);
        $L->record('DishNet-UG', 'reminder', 'n:R2', 'failed', ['detail' => 'evo_error', 'phone' => '0772500312']);
        $L->record('DishNet-UG', 'winback', 'n:R3', 'sent', ['wa_message_id' => 'WA-R3', 'phone' => '0772500313']);
        $got = array_map(function ($r) { return [$r['ref'], $r['mode'], $r['outcome'], $r['wa_message_id'], $r['detail'], $r['phone'],
                                                 $r['win_from'], $r['cap_day']]; }, wsb_rows($b['pdo']));
        $ctx->eq($got, [['n:R1', 'record', 'uncertain', 'WA-R1', 'evo_error', '256772500311', null, null],
                        ['n:R2', 'record', 'not_sent', null, 'evo_error', '256772500312', null, null],
                        ['n:R3', 'record', 'sent', 'WA-R3', null, '256772500313', null, null]],
                 'LG30 record(): failed + maybe_sent → uncertain, failed → not_sent, sent → sent, with the id, the detail and the ledger\'s phone, no limits');
    });

    $ctx->run('LG31 onConnection(): counts on a read-only connection, settle and record on a writable one, no claim', function () use ($ctx, $MON) {
        $b = wsb_ug($ctx); $T = time(); $L = wsb_ledger($b, $T);
        $L->record('DishNet-UG', 'reminder', 'n:LG31A', 'sent', ['phone' => '0772500310']);
        $L->record('DishNet-UG', 'handover_alert', 'a:LG31B', 'sent');
        $ro = new \PDO('sqlite:' . $b['data'] . '/plugin.sqlite3');
        $ro->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $ro->exec('PRAGMA query_only = ON');
        $c1 = WaSendBudget::onConnection($ro, $b['cfg'], $b['data'])->counts('DishNet-UG');
        $c2 = $L->counts('DishNet-UG');
        unset($c1['next_proactive_at'], $c2['next_proactive_at']);
        $ctx->eq($c1, $c2, 'LG31 counts() on a query_only connection equals forInstall()\'s');
        $ctx->eq([$c1['total_today'] ?? null, $c1['record_proactive_today'] ?? null, $c1['internal_today'] ?? null], [2, 1, 1], 'LG31 control: the counts see both rows');
        $ctx->eq((int)$ro->query('SELECT total_changes()')->fetchColumn(), 0, 'LG31 and changed nothing (total_changes() 0)');
        $rw = new \PDO('sqlite:' . $b['data'] . '/plugin.sqlite3');
        $rw->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $rw->exec('PRAGMA busy_timeout = 5000');
        $oc = WaSendBudget::onConnection($rw, $b['cfg'], $b['data']);
        $ctx->eq($oc->claim('DishNet-UG', 'staff_list', 'list:x:central'), ['ok' => false, 'defer' => 'not_claimable', 'retry_at' => null, 'final' => true],
                 'LG31 its claim() answers not_claimable, final');
        $ctx->eq(count(wsb_rows($b['pdo'])), 2, 'LG31 and inserts nothing');
        $b2 = wsb_ug($ctx); $T2 = $MON('10:00:00'); $L2 = wsb_ledger($b2, $T2);
        $c = $L2->claim('DishNet-UG', 'staff_list', 'list:2026-11-02:central');
        $rw2 = new \PDO('sqlite:' . $b2['data'] . '/plugin.sqlite3'); $rw2->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        WaSendBudget::onConnection($rw2, $b2['cfg'], $b2['data'])->settle((int)($c['claim'] ?? 0), 'failed', ['maybe_sent' => true]);
        $ctx->eq(wsb_rows($b2['pdo'])[0]['outcome'] ?? null, 'uncertain', 'LG31 its settle() settles a claim as forInstall()\'s does');
        $ctx->eq($oc->record('DishNet-UG', 'other', 's:LG31C', 'sent', ['wa_message_id' => 'WA-LG31C']), 'recorded', 'LG31 its record() writes (the Starlink hook\'s path)');
        $L->record('DishNet-UG', 'other', 's:LG31D', 'sent', ['wa_message_id' => 'WA-LG31D']);
        $pick = function ($r) { unset($r['id'], $r['ref'], $r['claimed_at'], $r['settled_at'], $r['wa_message_id']); return $r; };
        $rows = wsb_rows($b['pdo'], "ref IN ('s:LG31C','s:LG31D')");
        $ctx->t(count($rows) === 2 && $pick($rows[0]) === $pick($rows[1]), 'LG31 the row it writes is the row forInstall()\'s record() writes', json_encode($rows));
    });

    $ctx->run('LG32 the in-flight bound (A12-01, B12-02)', function () use ($ctx, $MON) {
        $at = function (string $kampala, string $kind, array $o, array $cfg = []) use ($ctx): array {
            $b = $ctx->book($cfg + QF_UG);
            wsb_reminders_done($b, qf_t(substr($kampala, 0, 10) . ' 09:00:00'));
            $T = qf_t($kampala); $L = wsb_ledger($b, $T);
            $r = $L->claim('DishNet-UG', $kind, $kind . ':LG32', $o);
            return [$r, count(wsb_rows($b['pdo']))];
        };
        [$r, $n] = $at('2026-11-02 19:59:50', 'offer', ['phone' => '0772500320', 'in_flight_s' => 50]);
        $ctx->eq([wsb_code($r), $r['retry_at'] ?? null, $n], ['window', qf_t('2026-11-03 08:00:00'), 0], 'LG32 an offer at 19:59:50 with in_flight_s 50: window, retry Tuesday 08:00, nothing inserted');
        [$r] = $at('2026-11-02 19:59:00', 'offer', ['phone' => '0772500320', 'in_flight_s' => 50]);
        $ctx->eq(wsb_code($r), 'ok', 'LG32 control: at 19:59:00 it is ok');
        [$r] = $at('2026-11-07 19:59:50', 'offer', ['phone' => '0772500320', 'in_flight_s' => 50]);
        $ctx->eq([wsb_code($r), $r['retry_at'] ?? null], ['window', qf_t('2026-11-09 08:00:00')], 'LG32 on a Saturday the retry is Monday 08:00');
        [$r] = $at('2026-11-02 19:59:50', 'quote', ['phone' => '0772500321']);
        $ctx->eq(wsb_code($r), 'ok', 'LG32 a quote at 19:59:50 with no in_flight_s is ok (the bound defaults to 0)');
        [$r] = $at('2026-11-02 17:59:50', 'offer', ['phone' => '0772500320', 'in_flight_s' => 50], ['wa_send_hour_to' => 18]);
        $ctx->eq(wsb_code($r), 'window', 'LG32 with wa_send_hour_to 18 the bound is judged against 18:00');
        foreach ([['offer', 901], ['offer', -1], ['offer', '50'], ['voice_ack', 50]] as [$k, $v]) {
            [$r, $n] = $at('2026-11-02 10:00:00', $k, ['phone' => '0772500322', 'in_flight_s' => $v]);
            $ctx->eq([$r, $n], [['ok' => false, 'defer' => 'not_claimable', 'retry_at' => null, 'final' => true], 0],
                     'LG32 in_flight_s ' . json_encode($v) . " with {$k}: not_claimable, final, nothing inserted");
        }
    });

    $ctx->run('LG33 what a claim records: its moment and the limits it applied (ruling R18)', function () use ($ctx, $MON) {
        $b = wsb_ug($ctx); $T = $MON('19:59:59'); $L = wsb_ledger($b, $T);
        $c = $L->claim('DishNet-UG', 'staff_list', 'list:LG33');
        $ctx->eq(wsb_rows($b['pdo'])[0]['claimed_at'] ?? null, '2026-11-02 16:59:59', 'LG33 a claim judged at 19:59:59 Kampala stores that instant as claimed_at');
        $b = wsb_ug($ctx, ['wa_send_hour_to' => 18, 'wa_send_daily_cap' => 40, 'wa_send_hourly_cap' => 10]); $T = $MON('10:00:00'); $L = wsb_ledger($b, $T);
        $c = $L->claim('DishNet-UG', 'quote', 'fu:33:1', ['phone' => '0772500330']);
        $row = wsb_rows($b['pdo'])[0] ?? [];
        $ctx->eq([(int)($row['win_from'] ?? 0), (int)($row['win_to'] ?? 0), (int)($row['cap_day'] ?? 0), (int)($row['cap_hour'] ?? 0)], [8, 18, 40, 10],
                 'LG33 the row records the settings the claim read: win_to 18, cap_day 40, cap_hour 10');
        $L->settle((int)($c['claim'] ?? 0), 'sent');
        $T += 300;
        $b['cfg']['wa_send_daily_cap'] = 30;
        $L2 = wsb_ledger($b, $T);
        $L2->claim('DishNet-UG', 'staff_list', 'list:LG33b');
        $row = wsb_rows($b['pdo'], "ref = 'fu:33:1'")[0] ?? [];
        $ctx->eq((int)($row['cap_day'] ?? 0), 40, 'LG33 a set made after a claim leaves its row unchanged');
        $err = '';
        try { $b['pdo']->exec("UPDATE wa_auto_sends SET cap_day = 30 WHERE ref = 'fu:33:1'"); } catch (\Throwable $e) { $err = $e->getMessage(); }
        $ctx->t(strpos($err, 'what was claimed is fixed') !== false, 'LG33 and nothing may change it (was_identity_fixed)', $err);
        $b = wsb_ug($ctx, ['wa_voice_ack_hour_from' => 9, 'wa_voice_ack_hour_to' => 20, 'wa_voice_ack_daily_max' => 30]); $T = $MON('10:00:00'); $L = wsb_ledger($b, $T);
        $L->claim('DishNet-UG', 'voice_ack', 'ack:LG33');
        $L->record('DishNet-UG', 'brief', 'n:LG33', 'sent');
        $got = array_map(function ($r) { return [$r['kind'], $r['win_from'] === null ? null : (int)$r['win_from'], $r['win_to'] === null ? null : (int)$r['win_to'],
                                                 $r['cap_day'] === null ? null : (int)$r['cap_day'], $r['cap_hour']]; }, wsb_rows($b['pdo']));
        $ctx->eq($got, [['voice_ack', 9, 20, 30, null], ['brief', null, null, null, null]],
                 'LG33 a voice_ack claim records ackPolicy()\'s hours and ceiling with cap_hour NULL; a record() row none');
    });

    $ctx->run('LG35 a setting may narrow an owner\'s window or spacing, never widen it (A19)', function () use ($ctx, $MON) {
        $b = wsb_ug($ctx, ['wa_send_hour_from' => 9]); $T = $MON('08:30:00'); $L = wsb_ledger($b, $T);
        $ctx->eq(wsb_code($L->claim('DishNet-UG', 'staff_list', 'list:LG35a')), 'window', 'LG35 control: wa_send_hour_from narrowed to 9 holds an 08:30 claim');
        $b = wsb_ug($ctx, ['wa_send_hour_from' => 6]); $T = $MON('07:00:00'); $L = wsb_ledger($b, $T);
        $r = $L->claim('DishNet-UG', 'staff_list', 'list:LG35b');
        $ctx->eq([wsb_code($r), count(wsb_rows($b['pdo']))], ['ledger_error', 0], 'LG35 wa_send_hour_from widened to 6: a 07:00 claim is refused by 089\'s CHECK (ledger_error), nothing inserted');
        $b = wsb_ug($ctx, ['wa_send_hour_to' => 22]); $T = $MON('21:00:00'); $L = wsb_ledger($b, $T);
        $ctx->eq(wsb_code($L->claim('DishNet-UG', 'staff_list', 'list:LG35c')), 'ledger_error', 'LG35 wa_send_hour_to widened to 22: a 21:00 claim answers ledger_error');
        $b = wsb_ug($ctx, ['wa_send_daily_cap' => 61]); $T = $MON('10:00:00'); $L = wsb_ledger($b, $T);
        $ctx->eq(wsb_code($L->claim('DishNet-UG', 'staff_list', 'list:LG35d')), 'ledger_error', 'LG35 wa_send_daily_cap raised to 61: ledger_error (D12\'s 60 is the ceiling)');
        $b = wsb_ug($ctx, ['wa_voice_ack_hour_to' => 22]); $T = $MON('21:30:00'); $L = wsb_ledger($b, $T);
        $ctx->eq($L->claim('DishNet-UG', 'voice_ack', 'ack:LG35e'), ['ok' => false, 'defer' => 'window', 'retry_at' => null, 'final' => true],
                 'LG35 wa_voice_ack_hour_to widened to 22: a 21:30 acknowledgement answers window, final (VoiceAckLimits refuses it)');
        $b = wsb_ug($ctx, ['wa_send_gap_min_s' => 5]); $T = $MON('10:00:00'); $L = wsb_ledger($b, $T);
        $ctx->eq(wsb_code($L->claim('DishNet-UG', 'staff_list', 'list:LG35f')), 'ledger_error', 'LG35 wa_send_gap_min_s narrowed below 30 s: ledger_error, nothing sent');
    });

    $ctx->run('LG36 the priority: enquiry drafts while enquiry claims, and the reminders\' usual day, reserved before offers', function () use ($ctx, $MON) {
        $mk = function (array $cfg) use ($ctx, $MON): array {
            $b = wsb_ug($ctx, $cfg + ['wa_send_daily_cap' => 2]); $T = $MON('09:50:00');
            wsb_ledger($b, $T)->record('DishNet-UG', 'brief', 'n:LG36-b', 'sent');   // used 1 of 2
            $cid = wsb_conv($b['pdo'], '256772500360');
            $b['pdo']->prepare("INSERT INTO followups (conversation_id, channel, phone, last_customer_at) VALUES (?, 'support', '256772500360', '2026-11-01 08:00:00')")->execute([$cid]);
            $fid = (int)$b['pdo']->lastInsertId();
            $b['pdo']->prepare("INSERT INTO followup_drafts (followup_id, attempt, verdict, reason, body, status) VALUES (?, 1, 'SEND', 'test', 'A follow-up (test)', 'approved')")->execute([$fid]);
            return $b;
        };
        $b = $mk([]); $T = $MON('10:05:00');
        $ctx->eq(wsb_code(wsb_ledger($b, $T)->claim('DishNet-UG', 'offer', 'offer:36:1', ['phone' => '0772500361'])), 'ok',
                 'LG36 control: with neither the chase nor offers on, enquiry drafts are recorded, so nothing is reserved for them');
        $b = $mk(['quote_chase_enabled' => '1']); $T = $MON('10:05:00');
        $ctx->eq(wsb_code(wsb_ledger($b, $T)->claim('DishNet-UG', 'offer', 'offer:36:2', ['phone' => '0772500362'])), 'reserved',
                 'LG36 while enquiry claims, an approved enquiry draft on the handset is reserved before an offer (1 used + 1 reserved + 1 > 2)');
        $ctx->eq(wsb_code(wsb_ledger($b, $T)->claim('DishNet-UG', 'quote', 'fu:36:1', ['phone' => '0772500363'])), 'ok', 'LG36 … and never before a chase');
        // the reminders' usual day: yesterday two reminders went from this handset, none yet today
        $b = wsb_ug($ctx, ['wa_send_daily_cap' => 2]); $T = $MON('10:00:00') - 86400; $L = wsb_ledger($b, $T);
        $L->record('DishNet-UG', 'reminder', 'n:LG36-r1', 'sent', ['phone' => '0772500364']);
        $T += 300;
        $L->record('DishNet-UG', 'reminder', 'n:LG36-r2', 'sent', ['phone' => '0772500365']);
        $T = $MON('10:00:00'); $L = wsb_ledger($b, $T);
        $ctx->eq(wsb_code($L->claim('DishNet-UG', 'offer', 'offer:36:3', ['phone' => '0772500366'])), 'reserved',
                 'LG36 before reminder_until_hour, with none recorded today, the reminders\' largest day of the week (2) is reserved before an offer');
        $T = $MON('17:05:00');
        $ctx->eq(wsb_code($L->claim('DishNet-UG', 'offer', 'offer:36:3', ['phone' => '0772500366'])), 'ok', 'LG36 control: after reminder_until_hour nothing is reserved for them');
    });

    $ctx->run('LG34 two processes at once: one claim, the other busy (BEGIN IMMEDIATE)', function () use ($ctx, $MON) {
        $b = wsb_ug($ctx); $sig = $ctx->dir('lg34');
        $child = $sig . '/child.php';
        file_put_contents($child, '<?php
            require $argv[1] . "/lib/StoreInterface.php"; require $argv[1] . "/lib/SqliteStore.php"; require $argv[1] . "/lib/WaSendBudget.php";
            $store = SqliteStore::create($argv[2]); $cfg = json_decode($argv[3], true); $T = (int)$argv[4]; $sig = $argv[5]; $who = $argv[6];
            $rand = function (int $a, int $b) use ($sig, $who): int { if ($who === "A") { touch($sig . "/a"); usleep(1500000); } return $a; };
            $L = WaSendBudget::forInstall($cfg, $argv[2], $store, null, function () use ($T): int { return $T; }, $rand);
            echo json_encode($L->claim("DishNet-UG", "staff_list", "list:2026-11-02:" . $who));');
        $T = $MON('10:00:00');
        $args = function (string $who) use ($ctx, $b, $T, $sig, $child): array {
            return ['php', $child, $ctx->tree(), $b['data'], json_encode($b['cfg']), (string)$T, $sig, $who];
        };
        $pa = proc_open($args('A'), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipesA);
        $ctx->t(wsb_wait_file($sig . '/a', 15), 'LG34 control: process A holds its claim open');
        $pb = proc_open($args('B'), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipesB);
        $outA = stream_get_contents($pipesA[1]); $errA = stream_get_contents($pipesA[2]); proc_close($pa);
        $outB = stream_get_contents($pipesB[1]); $errB = stream_get_contents($pipesB[2]); proc_close($pb);
        $ra = json_decode((string)$outA, true) ?: []; $rb = json_decode((string)$outB, true) ?: [];
        $ctx->eq([wsb_code($ra), wsb_code($rb)], ['ok', 'handset_busy'], 'LG34 two senders on one handset at once: one ok, the other handset_busy', $errA . $errB);
        $ctx->eq(count(wsb_rows($b['pdo'])), 1, 'LG34 one row');
    });
}];

// ── HK: the hooks ───────────────────────────────────────────────────────────────────────────────────────────────────

$SECTIONS['HK'] = ['title' => 'The record-only hooks (§H.2.6)', 'run' => function (QfCtx $ctx) {
    $evo = qf_fake_evo();
    $ctx->t($evo !== '', 'HK control: the fake Evolution is up');
    $NS = function (array $b): NotificationService { return new NotificationService($b['store'], $b['cfg']); };
    $hk = function (array $extra = []) use ($ctx, $evo): array {
        return $ctx->book($extra + ['evo_api_url' => $evo, 'evo_api_key' => 'fake-key-hk'] + QF_UG, 'hk');
    };
    $texts = function () use ($evo): array { return (array)(qf_http('GET', $evo . '/__test/state')[1]['text_calls'] ?? []); };

    $ctx->run('HK01 the event map and the literals in the tree', function () use ($ctx) {
        $want = ['ops_pre_due_d7' => 'reminder', 'ops_pre_due_d3' => 'reminder', 'ops_pre_due_d1' => 'reminder', 'ops_overdue_d1' => 'reminder',
                 'ops_overdue_d3' => 'reminder', 'ops_overdue_d5' => 'reminder', 'ops_low_balance' => 'reminder', 'ops_win_back' => 'winback',
                 'staff_jobs_summary' => 'brief', 'staff_daily_report' => 'brief', 'bidal_morning_summary' => 'brief',
                 'cashbook_daily_summary' => 'brief', 'cash_carry_reminder' => 'brief'];
        $got = WaSendBudget::NOTIFY_KINDS; ksort($got); ksort($want);
        $ctx->eq($got, $want, 'HK01 NOTIFY_KINDS is §H.2.6.1\'s table: seven reminder tiers, win-back, five briefs; every other event is other');
        $src = '';
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($ctx->tree(), FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            $p = $f->getPathname();
            if (substr($p, -4) !== '.php' || substr($p, -17) === '/WaSendBudget.php') continue;
            $src .= (string)file_get_contents($p);
        }
        $missing = [];
        foreach (array_keys($want) as $ev) {
            if (strpos($src, "'{$ev}'") === false && strpos($src, '"' . $ev . '"') === false) $missing[] = $ev;
        }
        $ctx->eq($missing, [], 'HK01 every mapped event is a literal in the tree (a renamed event fails here)');
    });

    $ctx->run('HK02 one row per Evolution send through sendVia, sendDocument and sendImage', function () use ($ctx, $hk, $NS, $evo) {
        $b = $hk(); $ns = $NS($b);
        $ns->sendRaw('0772500401', 'A payment reminder (test)', 'ops_pre_due_d3');
        $ns->sendDocument('accounts', '0772500402', 'http://127.0.0.1:9/inv-test.pdf', 'INV-TEST.pdf', 'Invoice (test)', 'ops_invoice_pdf_test');
        $ns->sendImage('support', '256772500403', 'http://127.0.0.1:9/img-test.jpg', 'Image (test)', 'ops_image_test');
        $got = array_map(function ($r) { return [$r['kind'], $r['mode'], $r['handset'], $r['phone'], $r['outcome'], $r['ref'], $r['wa_message_id']]; },
                         wsb_rows($b['pdo']));
        $ctx->eq($got, [['reminder', 'record', 'i:dishnet-ug', '256772500401', 'sent', 'n:FAKE-EVO-MSG-1', 'FAKE-EVO-MSG-1'],
                        ['other', 'record', 'i:dishnet-ug', null, 'sent', 'n:FAKE-EVO-MEDIA-1', 'FAKE-EVO-MEDIA-1'],
                        ['other', 'record', 'i:dishnet-ug', null, 'sent', 'n:FAKE-EVO-MEDIA-2', 'FAKE-EVO-MEDIA-2']],
                 'HK02 a reminder (with its phone), an invoice PDF on the account channel and an image (no phone), one recorded row each');
        qf_http('GET', $evo . '/__test/fail_next?n=1');
        $ns->sendRaw('0772500404', 'A refused reminder (test)', 'ops_overdue_d1');
        $row = wsb_rows($b['pdo'], "kind = 'reminder' AND phone = '256772500404'")[0] ?? [];
        $ctx->eq($row['outcome'] ?? null, 'not_sent', 'HK02 a send Evolution refused is recorded not_sent (the hook reads the result)');
    });

    $ctx->run('HK03 WASender: counted, never a row; a person\'s event: nothing', function () use ($ctx, $hk, $NS, $texts) {
        $b = $hk(['evo_instance_support' => '', 'wa_plugin_url' => 'http://127.0.0.1:9', 'wa_app_key' => 'fake-app', 'wa_auth_key' => 'fake-auth']);
        $NS($b)->sendRaw('0772500405', 'A reminder WASender carries (test)', 'ops_pre_due_d7');
        $k = 'wasender:' . WaSendBudget::localDay(time()) . ':reminder';
        $ctx->eq([count(wsb_rows($b['pdo'])), wsb_one($b['pdo'], 'SELECT v FROM quote_followup_state WHERE k = ?', [$k])], [0, '1'],
                 'HK03 a send WASender carried: no ledger row, one on wasender:<day>:reminder');
        $b = $hk(); $n0 = count($texts());
        $NS($b)->sendRaw('0772500406', 'An Inbox reply (test)', 'wa_staff_reply');
        $ctx->eq(count($texts()) - $n0, 1, 'HK03 control: the person\'s reply went');
        $ctx->eq([count(wsb_rows($b['pdo'])), (int)wsb_one($b['pdo'], 'SELECT COUNT(*) FROM quote_followup_state')], [0, 0],
                 'HK03 a person\'s event (wa_staff_reply) is never filed as automation: no row, no count');
    });

    $ctx->run('HK04 AlertService: recorded, quote_list not twice, a fake with no instanceFor, lastResult()', function () use ($ctx, $hk) {
        $b = $hk(['alert_whatsapp' => '256772500450']);
        $fake = new class {
            public $n = 0;
            public function instanceFor(string $c): string { return $c === 'sales' ? 'Sales-UG' : ''; }
            public function sendText(string $ch, string $to, string $t, string $cl = ''): array { $this->n++; return ['ok' => true, 'http' => 201, 'data' => ['key' => ['id' => 'WA-ALERT-' . $this->n]]]; }
        };
        $al = new AlertService($b['store'], $b['cfg'], $fake);
        $r1 = $al->notify('escalate:conv:7', 'A hand-over (test)', 0);
        $ctx->eq($al->lastResult(), ['ok' => true, 'http' => 201, 'data' => ['key' => ['id' => 'WA-ALERT-1']]], 'HK04 lastResult() is sendText\'s answer');
        $al->notify('lead:9', 'A lead alert (test)', 0);
        $al->notify('quote_list:2026-11-02:central', 'The daily list (test)', 0);
        $got = array_map(function ($r) { return [$r['kind'], $r['handset'], $r['ref'], $r['phone'], $r['outcome']]; }, wsb_rows($b['pdo']));
        $ctx->eq([$r1['sent'] ?? null, $got], [true, [['handover_alert', 'i:sales-ug', 'a:WA-ALERT-1', null, 'sent'], ['other', 'i:sales-ug', 'a:WA-ALERT-2', null, 'sent']]],
                 'HK04 an escalation is handover_alert, any other alert other, on the sales handset, no phone; a quote_list: alert adds no row');
        $al->notify('lead:9', 'Again, inside its cooldown (test)', 240);
        $ctx->eq($al->lastResult(), null, 'HK04 a cooldown leaves lastResult() null');
        $bare = new class {
            public function sendText(string $ch, string $to, string $t, string $cl = ''): array { return ['ok' => true, 'http' => 201, 'data' => ['key' => ['id' => 'WA-BARE']]]; }
        };
        $l0 = $ctx->logLines('hook_write_lost');
        $r = (new AlertService($b['store'], $b['cfg'], $bare))->notify('escalate:conv:8', 'A hand-over (test)', 0);
        $ctx->eq([$r['sent'] ?? null, $ctx->logLines('hook_write_lost') - $l0, count(wsb_rows($b['pdo']))], [true, 0, 2],
                 'HK04 a fake with no instanceFor: the alert goes, no error, no row');
    });

    $ctx->run('HK05 an alert with no sales instance is counted, and with one recorded (Q18-F01)', function () use ($ctx, $hk) {
        $b = $hk(['alert_whatsapp' => '256772500451', 'evo_instance_sales' => '']);
        $r = (new AlertService($b['store'], $b['cfg']))->notify('escalate:conv:9', 'A hand-over (test)', 0);
        $k = 'noinstance:' . WaSendBudget::localDay(time()) . ':handover_alert';
        $log = $b['pdo']->query("SELECT http_code FROM notification_audit_log WHERE event = 'ops_alert_escalate' ORDER BY id DESC LIMIT 1")->fetchColumn();
        $ctx->eq([$r['sent'] ?? null, $log === false ? 'none' : (int)$log, count(wsb_rows($b['pdo'])), wsb_one($b['pdo'], 'SELECT v FROM quote_followup_state WHERE k = ?', [$k])],
                 [false, 0, 0, '1'], 'HK05 refused before any request (http 0): logged with http_code 0, no ledger row, one on noinstance:<day>:handover_alert');
        $b = $hk(['alert_whatsapp' => '256772500452']);
        (new AlertService($b['store'], $b['cfg']))->notify('escalate:conv:10', 'A hand-over (test)', 0);
        $ctx->eq([array_column(wsb_rows($b['pdo']), 'kind'), (int)wsb_one($b['pdo'], 'SELECT COUNT(*) FROM quote_followup_state')], [['handover_alert'], 0],
                 'HK05 with the instance set, the same alert is recorded and counts nothing');
    });

    $ctx->run('HK06 enquiry claims only while the chase or offers is on', function () use ($ctx) {
        $ctx->eq([WaSendBudget::enquiryClaims(QF_UG), WaSendBudget::enquiryClaims(['quote_chase_enabled' => '1'] + QF_UG),
                  WaSendBudget::enquiryClaims(['offers_live_enabled' => 1] + QF_UG), WaSendBudget::enquiryClaims(['quote_chase_enabled' => '0'] + QF_UG)],
                 [false, true, true, false], 'HK06 enquiryClaims(): off with nothing on, on with the chase or offers');
    });

    $ctx->run('HK07 the send-site inventory, by file and method (§H.2.6.7; ruling R10)', function () use ($ctx) {
        $classes = [
            'lib/NotificationService.php' => ['sendViaEvolution' => 'hooked', 'sendMediaViaEvolution' => 'hooked', 'sendOnChannel' => 'a person'],
            'lib/AlertService.php' => ['sendTo' => 'hooked'],
            'workers/StarlinkMailWorker.php' => ['notifyCustomer' => 'hooked'],
            'cron/followup_send.php' => ['(top)' => 'claiming'],
            'lib/VoiceAck.php' => ['acknowledge' => 'claiming'],
            'lib/EvolutionOfferGateway.php' => ['send' => 'claiming'],
            'tabs/sales/wa_leads.php' => ['(top)' => 'a person'],
            'tabs/engage/whatsapp.php' => ['(top)' => 'a person'],
            // 5.18.104 (root docs/73 §H.3.2 (a)): escalate is now the wrapper over lib/Handover.php, which holds the holding line
            // (a reply, §H.2.6.6) — so the worker's entry is dropped here, as B1 said this stage would.
            'workers/AiReplyWorker.php' => ['handle' => 'a reply', 'maybeSendPhoto' => 'a reply', 'maybeSendDocument' => 'a reply', 'maybeSendFlyer' => 'a reply'],
            'lib/Handover.php' => ['escalate' => 'a reply'],
            'lib/WhatsAppChannel.php' => ['send' => 'never reached'],
            'cron/job_assignment_notify.php' => ['(top)' => 'never reached'],
            'tools/wa_send_test.php' => ['(top)' => 'never reached'],
            'tools/wa_test_send.php' => ['(top)' => 'never reached'],
        ];
        $sites = wsb_send_sites($ctx->tree());
        $ctx->t(count($sites) >= 15, 'HK07 control: the scan finds the send sites (' . count($sites) . ')');
        $un = [];
        foreach ($sites as [$file, $method, $line]) {
            if (!isset($classes[$file][$method])) $un[] = "{$file}: {$method} (:{$line})";
        }
        $ctx->eq($un, [], 'HK07 every Evolution send site in the tree is classified');
        $ctx->t(in_array(['workers/AiReplyWorker.php', 'handle'], array_map(function ($s) { return [$s[0], $s[1]]; }, $sites), true),
                'HK07 control: AiReplyWorker::handle is found as a site');
    });

    $ctx->run('HK08 Starlink: recorded as other on the worker\'s PDO, the tenant read from its data directory (A11-05)', function () use ($ctx, $hk) {
        require_once $ctx->tree() . '/workers/StarlinkMailWorker.php';
        $vault = $ctx->dir('vault') . '/v.json';
        file_put_contents($vault, json_encode(['config' => ['tenant_profile' => 'uganda']]));
        $was = getenv('DN_VAULT_FILE'); putenv('DN_VAULT_FILE=' . $vault);
        $mk = function ($instThrows = false) use ($ctx) {
            $b = $ctx->book(['wa_send_ledger' => 'on', 'starlink_notify_channel' => 'support', 'evo_instance_support' => 'DishNet-UG'], 'sl');
            $evo = new class($instThrows) {
                public $sent = 0; private $t;
                public function __construct($t) { $this->t = $t; }
                public function instanceFor(string $c): string { if ($this->t) throw new \RuntimeException('instance lookup failed (test)'); return 'DishNet-UG'; }
                public function sendText(string $ch, string $to, string $t, string $cl = ''): array { $this->sent++; return ['ok' => true, 'http' => 201, 'data' => ['key' => ['id' => 'WA-SL-' . $this->sent]]]; }
            };
            $crm = new class {
                public function get(string $p): array { return ['firstName' => 'Testa', 'contacts' => [['phone' => '0772500460']]]; }
            };
            $w = new StarlinkMailWorker($b['pdo'], $b['cfg'], null, $evo, null, $crm);
            $m = new \ReflectionMethod($w, 'notifyCustomer'); $m->setAccessible(true);
            return [$b, $evo, $m->invoke($w, 1, ['type' => 'ORDER_CONFIRMED', 'extracted' => []])];
        };
        [$b, $evo, $ok] = $mk();
        $got = array_map(function ($r) { return [$r['kind'], $r['handset'], $r['ref'], $r['phone'], $r['outcome']]; }, wsb_rows($b['pdo']));
        $ctx->eq([$ok, $evo->sent, $got], [true, 1, [['other', 'i:dishnet-ug', 's:WA-SL-1', null, 'sent']]],
                 'HK08 the order message goes and is recorded as other, no phone, on the handset of its channel');
        $l0 = $ctx->logLines('hook_write_lost other');
        [$b, $evo, $ok] = $mk(true);
        $ctx->eq([$ok, $evo->sent, count(wsb_rows($b['pdo'])), $ctx->logLines('hook_write_lost other') - $l0], [true, 1, 0, 1],
                 'HK08 a throwing ledger lookup never stops the order message: it goes, the worker reads true, one hook_write_lost other line');
        putenv($was === false ? 'DN_VAULT_FILE' : 'DN_VAULT_FILE=' . $was);
    });

    $ctx->run('HK09 a throwing ledger never stops a send', function () use ($ctx, $hk, $NS, $texts) {
        $b = $hk(['alert_whatsapp' => '256772500470']);
        $b['pdo']->exec('DROP TABLE wa_auto_sends');
        $b['pdo']->exec('CREATE TABLE wa_auto_sends (id INTEGER PRIMARY KEY, handset TEXT)');
        $n0 = count($texts()); $l0 = $ctx->logLines('hook_write_lost reminder');
        $NS($b)->sendRaw('0772500471', 'A reminder (test)', 'ops_pre_due_d1');
        $logged = (int)wsb_one($b['pdo'], "SELECT COUNT(*) FROM notification_audit_log WHERE event = 'ops_pre_due_d1'");
        $ctx->eq([count($texts()) - $n0, $logged, $ctx->logLines('hook_write_lost reminder') - $l0], [1, 1, 1],
                 'HK09 the reminder goes, its Message Log row lands, and one hook_write_lost reminder line says what was lost');
    });

    $ctx->run('HK10 a lost write (Q19-F02): the send goes, no row, one line per lost write', function () use ($ctx, $hk, $NS, $texts, $evo) {
        $b = $hk(); $ns = $NS($b);
        $db = $b['data'] . '/plugin.sqlite3';
        // 5.18.104 (root docs/73 §H.2.14): L-ON's half — the sends in Kampala's zone, the log covering the day, the ledger
        // on for two days, so each lost write is judged and printed beside its line.
        wsb_clear_of_midnight();
        wsb_log_reset($ctx, (new \DateTime('@' . (time() - 3 * 86400)))->setTimezone(new \DateTimeZone(QF_TZ))->format('Y-m-d H:i:s'));
        $lcfg = ['wa_send_ledger_since' => UtcClock::stamp(time() - 2 * 86400)] + $b['cfg'];
        foreach ([1, 2] as $i) {
            $d = $ctx->dir('hold');
            qf_http('GET', $evo . '/__test/hold?dir=' . rawurlencode($d));
            [$h] = wsb_holder($db, $d . '/held', $d . '/locked', 12.5, $d . '/release');
            $n0 = count($texts()); $l0 = $ctx->logLines('hook_write_lost reminder');
            wsb_kampala(function () use ($ns, $i) { $ns->sendRaw('07725004' . (80 + $i), "Reminder {$i} under a held lock (test)", 'ops_pre_due_d3'); });   // 5.18.104 (root docs/73 §H.2.14)
            proc_close($h);
            $logged = (int)wsb_one($b['pdo'], "SELECT COUNT(*) FROM notification_audit_log WHERE event = 'ops_pre_due_d3' AND phone LIKE ?", ['%7725004' . (80 + $i)]);
            $ctx->eq([count($texts()) - $n0, count(wsb_rows($b['pdo'])), $logged, $ctx->logLines('hook_write_lost reminder') - $l0], [1, 0, 1, 1],
                     "HK10 hold {$i}: the send goes, no ledger row, the Message Log row lands after the release, one hook_write_lost reminder line");
            $r = wsb_lon($ctx, $b, $lcfg, time());   // 5.18.104 (root docs/73 §H.2.14): L-ON beside the line
            $day = WaSendBudget::localDay(time());
            $ctx->eq([wsb_lst($r, 'hook_write_lost'), in_array('hook_write_lost ' . $day . ' reminder lines=' . $i, $r['notes'], true)], [['PASS', $i], true],
                     "HK10 hold {$i}: L-ON reads PASS with hook_write_lost {$i} printed beside the day's lines", json_encode($r));
        }
        $b = $hk(['evo_instance_support' => '', 'wa_plugin_url' => 'http://127.0.0.1:9', 'wa_app_key' => 'fake-app', 'wa_auth_key' => 'fake-auth']);
        $d = $ctx->dir('hold');
        [$h] = wsb_holder($b['data'] . '/plugin.sqlite3', $d . '/go', $d . '/locked', 7.5);
        touch($d . '/go'); wsb_wait_file($d . '/locked', 10);
        $l0 = $ctx->logLines('hook_write_lost reminder');
        $NS($b)->sendRaw('0772500490', 'A reminder WASender carries, held (test)', 'ops_pre_due_d7');
        proc_close($h);
        $ctx->eq([(int)wsb_one($b['pdo'], 'SELECT COUNT(*) FROM quote_followup_state'), $ctx->logLines('hook_write_lost reminder') - $l0], [0, 1],
                 'HK10 the WASender count lost under the hold writes one hook_write_lost reminder line');
        $b = $hk(['alert_whatsapp' => '256772500491', 'evo_instance_sales' => '']);
        $d = $ctx->dir('hold');
        [$h] = wsb_holder($b['data'] . '/plugin.sqlite3', $d . '/go', $d . '/locked', 12.5);
        touch($d . '/go'); wsb_wait_file($d . '/locked', 10);
        $l0 = $ctx->logLines('hook_write_lost handover_alert');
        (new AlertService($b['store'], $b['cfg']))->notify('escalate:conv:11', 'A hand-over, held (test)', 0);
        proc_close($h);
        $ctx->eq([(int)wsb_one($b['pdo'], 'SELECT COUNT(*) FROM quote_followup_state'), $ctx->logLines('hook_write_lost handover_alert') - $l0], [0, 1],
                 'HK10 the no-instance count lost under the hold writes one hook_write_lost handover_alert line');
    });

    // 5.18.104 (root docs/73 §H.2.14; §H.2.12.5, A11-04): L-ON's hook control, through the real senders to the fake.
    $ctx->run('HK11 HK12 L-ON\'s hook control: hooked sends of each kind beside the rows no hook can record, an alert and a list by event', function () use ($ctx, $hk, $NS, $texts) {
        wsb_clear_of_midnight();
        wsb_log_reset($ctx, (new \DateTime('@' . (time() - 3 * 86400)))->setTimezone(new \DateTimeZone(QF_TZ))->format('Y-m-d H:i:s'));
        $b = $hk(['alert_whatsapp' => '256772500540', 'wa_send_pdf' => false]);
        $lcfg = ['wa_send_ledger_since' => UtcClock::stamp(time() - 2 * 86400)] + $b['cfg'];
        // The daily list first, as the list's job runs it: its own claim (at 10:00 today, after the day's reminders), the
        // alert, the settle. The clock is injected for the claim only; the alert and its Message Log row are the real ones.
        $day = WaSendBudget::localDay(time());
        $T = qf_t($day . ' 10:00:00');
        wsb_reminders_done($b, qf_t($day . ' 09:00:00'));
        $L = wsb_ledger($b, $T);
        $cl = $L->claim('Sales-UG', 'staff_list', 'list:' . $day . ':central');
        $ctx->eq(wsb_code($cl), 'ok', 'HK11 control: the daily list\'s claim is ok', json_encode($cl));
        $al = new AlertService($b['store'], $b['cfg']);
        wsb_kampala(function () use ($al, $day) { $al->notify('quote_list:' . $day . ':central', 'The daily list (test)', 0); });
        $L->settle((int)($cl['claim'] ?? 0), 'sent', ['wa_message_id' => (string)($al->lastResult()['data']['key']['id'] ?? '')]);
        ContactOptOut::fromStore($b['store'])->add('256772500505', ['scope' => ContactOptOut::SCOPE_ALL, 'created_by' => 'test']);
        $n0 = count($texts());
        wsb_kampala(function () use ($b, $NS, $al) {
            $ns = $NS($b);
            $ns->sendRaw('0772500501', 'A payment reminder (test)', 'ops_pre_due_d3');
            $ns->sendRaw('0772500502', 'A win-back (test)', 'ops_win_back');
            $ns->sendRaw('0772500503', 'The daily report (test)', 'staff_daily_report');
            $ns->sendImage('support', '256772500504', 'http://127.0.0.1:9/img-test.jpg', 'Image (test)', 'ops_image_test');
            $ns->sendRaw('12345', 'A reminder to a number WhatsApp cannot use (test)', 'ops_pre_due_d1');
            $ns->sendRaw('0772500505', 'A reminder to a number that opted out (test)', 'ops_pre_due_d1');
            $ns->sendDocument('accounts', '0772500506', 'http://127.0.0.1:9/inv-test.pdf', 'INV-TEST.pdf', 'Invoice (test)', 'ops_invoice_pdf_test');
            $al->notify('escalate:conv:61', 'A hand-over (test)', 0);
        });
        $ev = function (string $where) use ($b): int { return (int)wsb_one($b['pdo'], "SELECT COUNT(*) FROM notification_audit_log WHERE {$where}"); };
        $ctx->eq([$ev("event = 'ops_pre_due_d1' AND success = 0 AND http_code IS NULL"), $ev("event = 'ops_pre_due_d1_suppressed_optout'"),
                  $ev("event = 'ops_invoice_pdf_test' AND success = 0 AND http_code IS NULL"), $ev("event IN ('ops_alert_escalate', 'ops_alert_quote_list') AND success = 1")],
                 [1, 1, 1, 2], 'HK11 control: the unusable number, the opt-out and the PDF switched off each left a Message Log row; both alerts were logged');
        $ctx->eq(count($texts()) - $n0, 4, 'HK11 control: four texts went (three hooked sends and the escalation); the unusable number and the opt-out sent nothing');
        $kinds = array_count_values(array_column(wsb_rows($b['pdo']), 'kind')); ksort($kinds);
        $ctx->eq($kinds, ['brief' => 1, 'handover_alert' => 1, 'other' => 1, 'reminder' => 1, 'staff_list' => 1, 'winback' => 1],
                 'HK11 control: one ledger row per hooked send and the list\'s own claim');
        $r = wsb_lon($ctx, $b, $lcfg, time());
        $ctx->eq(wsb_lst($r, 'hook_write_lost', 'log_rows_matched', 'inside_switch_margin'), ['PASS', 0, 6, 0],
                 'HK11 HK12 L-ON PASS: the rows written before any send skipped, the alert matched as handover_alert and the list as staff_list, hook_write_lost 0', json_encode($r));
        $ctx->eq([SalesFollowupChecks::kindOfEvent('ops_alert_escalate'), SalesFollowupChecks::kindOfEvent('ops_alert_quote_list'),
                  SalesFollowupChecks::kindOfEvent('ops_alert_lead'), SalesFollowupChecks::kindOfEvent('ops_win_back')],
                 ['handover_alert', 'staff_list', 'other', 'winback'], 'HK12 AlertService\'s rows by event, every other row by NOTIFY_KINDS');
    });

    // 5.18.104 (root docs/73 §H.2.14; §H.2.12.5, Q15-F03, Q16-F02; ruling R18): the switch day. The sends are the real
    // senders' through the fake, by a service built before the switch (its configuration had the ledger off), re-dated
    // to the case's Kampala times.
    $ctx->run('HK14 the switch day: a reminder run still sending after on ack at 10:00; a receipt at 00:10 after 23:50; the control', function () use ($ctx, $hk, $NS) {
        wsb_log_reset($ctx, '2026-11-01 08:00:00');
        $b = $hk(['wa_send_ledger' => 'off']);
        $lcfg = ['wa_send_ledger' => 'on', 'wa_send_ledger_since' => UtcClock::stamp(qf_t(QF_MONDAY . ' 10:00:00'))] + $b['cfg'];
        $ns = $NS($b);
        foreach (['10:20:00', '10:21:00', '11:40:00'] as $i => $at) {
            wsb_kampala(function () use ($ns, $i) { $ns->sendRaw('07725005' . (10 + $i), 'A reminder from the run built before the switch (test)', 'ops_overdue_d3'); });
            wsb_redate($b['pdo'], QF_MONDAY . ' ' . $at);
        }
        $ctx->eq([count(wsb_rows($b['pdo'])), (int)wsb_one($b['pdo'], "SELECT COUNT(*) FROM notification_audit_log WHERE event = 'ops_overdue_d3' AND success = 1")], [0, 3],
                 'HK14 control: three reminders went and were logged, no ledger row (the run\'s hook tested the configuration it was built with)');
        $r = wsb_lon($ctx, $b, $lcfg, qf_t('2026-11-03 12:00:00'));
        $ctx->eq(wsb_lst($r, 'inside_switch_margin', 'hook_write_lost'), ['PASS', 3, 0],
                 'HK14 on ack at 10:00, the run sending at 10:20 and still at 11:40: PASS, the three counted inside_switch_margin, hook_write_lost 0', json_encode($r));
        $b = $hk(['wa_send_ledger' => 'off']);
        $lcfg = ['wa_send_ledger' => 'on', 'wa_send_ledger_since' => UtcClock::stamp(qf_t(QF_MONDAY . ' 23:50:00'))] + $b['cfg'];
        $ns = $NS($b);
        wsb_kampala(function () use ($ns) { $ns->sendRaw('0772500520', 'A receipt from a worker built before the switch (test)', 'ops_payment_receipt_test'); });
        wsb_redate($b['pdo'], '2026-11-03 00:10:00');
        $r = wsb_lon($ctx, $b, $lcfg, qf_t('2026-11-04 12:00:00'));
        $ctx->eq(wsb_lst($r, 'inside_switch_margin', 'hook_write_lost'), ['PASS', 1, 0],
                 'HK14 on ack at 23:50, a receipt at 00:10 with no ledger row: PASS, counted inside_switch_margin, hook_write_lost 0', json_encode($r));
        wsb_kampala(function () use ($ns) { $ns->sendRaw('0772500521', 'A reminder on the next day, unrecorded (test)', 'ops_pre_due_d1'); });
        wsb_redate($b['pdo'], '2026-11-03 09:00:00');
        $r = wsb_lon($ctx, $b, $lcfg, qf_t('2026-11-04 12:00:00'));
        $ctx->eq((string)$r['status'], 'FAIL hook_silent',
                 'HK14 control: a row on the next Kampala day, more than an hour after the switch, its kind\'s only row, with no ledger row, count or line and the log covering the day: FAIL hook_silent', json_encode($r));
    });
}];

/**
 * Every ->sendText( ->sendImage( ->sendDocument( ->sendMedia( call on an Evolution service in a tree, as
 * [file, method or '(top)', line]. An Evolution receiver is a variable whose name starts with evo ($evo, $evoSvc,
 * $this->evo); EvolutionApiService's own delegation inside itself is not a site.
 */
function wsb_send_sites(string $tree): array
{
    $out = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tree, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        $p = $f->getPathname();
        if (substr($p, -4) !== '.php') continue;
        $rel = substr($p, strlen($tree) + 1);
        if ($rel === 'lib/EvolutionApiService.php' || strpos($rel, 'tests/') === 0) continue;
        $src = (string)file_get_contents($p);
        if (!preg_match('/\$(this->)?evo[A-Za-z_]*->send(Text|Image|Document|Media)\(/', $src)) continue;
        $toks = token_get_all($src);
        $stack = []; $depth = 0; $pending = null; $line = 1;
        for ($i = 0, $n = count($toks); $i < $n; $i++) {
            $t = $toks[$i];
            if (is_array($t)) {
                $line = $t[2];
                if ($t[0] === T_FUNCTION) {
                    for ($j = $i + 1; $j < $n; $j++) {
                        if (is_array($toks[$j]) && $toks[$j][0] === T_WHITESPACE) continue;
                        if ($toks[$j] === '&') continue;
                        $pending = (is_array($toks[$j]) && $toks[$j][0] === T_STRING) ? $toks[$j][1] : '{closure}';
                        break;
                    }
                }
                if ($t[0] === T_CURLY_OPEN || $t[0] === T_DOLLAR_OPEN_CURLY_BRACES) { $depth++; }
                if ($t[0] === T_VARIABLE && preg_match('/^\$evo[A-Za-z_]*$/', $t[1]) || ($t[0] === T_VARIABLE && $t[1] === '$this')) {
                    $k = $i + 1; $chain = $t[1];
                    while ($k < $n && is_array($toks[$k]) && in_array($toks[$k][0], [T_OBJECT_OPERATOR, T_STRING], true)) { $chain .= $toks[$k][1]; $k++; }
                    if (preg_match('/^\$(this->)?evo[A-Za-z_]*->send(Text|Image|Document|Media)$/', $chain) && ($toks[$k] ?? null) === '(') {
                        $method = '(top)';
                        for ($s = count($stack) - 1; $s >= 0; $s--) { if ($stack[$s][0] !== '{closure}') { $method = $stack[$s][0]; break; } }
                        $out[] = [$rel, $method, $line];
                    }
                }
                continue;
            }
            if ($t === '{') {
                $depth++;
                if ($pending !== null) { $stack[] = [$pending, $depth]; $pending = null; }
            } elseif ($t === '}') {
                if ($stack !== [] && end($stack)[1] === $depth) array_pop($stack);
                $depth--;
            } elseif ($t === ';' && $pending !== null) {
                $pending = null;   // an abstract or interface method: no body
            }
        }
    }
    return $out;
}

qf_run_sections($ctx, $SECTIONS, $O['sections']);

// ── the weakened copies ─────────────────────────────────────────────────────────────────────────────────────────────
if ($O['mutants']) {
    $M = [];
    require __DIR__ . '/quote_followup/mutants_wa_send_budget.php';
    qf_mutants($ctx, __FILE__, $M, $O['only']);
}

printf("\n%d passed, %d failed\n", $ctx->pass, $ctx->fail);
exit($ctx->fail === 0 ? 0 : 1);
