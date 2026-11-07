<?php
declare(strict_types=1);
/**
 * Section A of tests/test_quote_followup.php — migration 089 (5.18.104, root docs/73 §H.2.4, §H.2.14 A; ruling R9).
 * TEST ONLY. 089 on a 5.18.103-shaped book, on an empty book and on a South Sudan book; the _schema_checks row is the
 * verdict; all 38 names; every trigger ending END; the CHECKs, the limits (ruling R18) and the erase-only phones; the
 * self-check's failure logged PARTIAL with no row, once per error class on its INSERT as split; the _schema_checks
 * CREATE compared byte for byte with D's text and with every later migration that holds one.
 */

/** D's _schema_checks CREATE, byte for byte (§H.2.4); 090 and 091 repeat it exactly (ruling R9). */
const QA_SCHEMA_CHECKS_CREATE = "CREATE TABLE IF NOT EXISTS _schema_checks (\n  migration  TEXT PRIMARY KEY,\n  expected   INTEGER NOT NULL,\n"
    . "  found      INTEGER NOT NULL,\n  checked_at TEXT NOT NULL DEFAULT (datetime('now')),\n  CHECK (found = expected));";

const QA_TABLES   = ['_schema_checks', 'quote_tracker', 'wa_auto_sends', 'quote_list_sends', 'quote_followup_state', 'wa_seen_marks'];
const QA_INDEXES  = ['idx_qt_quote_id', 'idx_qt_open', 'idx_qt_phone', 'idx_qt_owner', 'idx_fu_quote', 'idx_was_ref', 'idx_was_day',
                     'idx_was_time', 'idx_was_phone', 'idx_was_waid', 'idx_qls_day', 'idx_wsm_msg', 'idx_wsm_phone'];
const QA_TRIGGERS = ['qt_never_deleted', 'qt_status_once', 'qt_identity_fixed', 'qt_phone_erase_only', 'was_never_deleted',
                     'was_settled_once', 'was_identity_fixed', 'was_phone_erase_only', 'qls_never_deleted', 'wsm_never_deleted',
                     'wsm_never_changed', 'wsm_phone_erase_only'];

/** A PDO whose exec() answers 089's self-check INSERT with an error of a class the test names (the class 089 cannot raise). */
final class QaClassPdo extends \PDO
{
    public $raise = '';
    #[\ReturnTypeWillChange]
    public function exec($statement)
    {
        if ($this->raise !== '' && strpos((string)$statement, 'INSERT INTO _schema_checks') === 0) throw new \PDOException($this->raise);
        return parent::exec($statement);
    }
}

function qa_pdo(string $file, string $class = \PDO::class): \PDO
{
    $p = new $class('sqlite:' . $file);
    $p->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    $p->exec('PRAGMA busy_timeout = 5000');
    return $p;
}

/** The migrations of $tree up to (not including) 089, in a folder of their own. */
function qa_migrations_before(QfCtx $ctx, string $before): string
{
    $d = $ctx->dir('mig');
    foreach (glob($ctx->tree() . '/migrations/*.sql') ?: [] as $f) {
        if (strcmp(basename($f), $before) < 0) copy($f, $d . '/' . basename($f));
    }
    return $d;
}

/** One file holding $sql, in a folder of its own, for the runner. */
function qa_one_file(QfCtx $ctx, string $name, string $sql): string
{
    $d = $ctx->dir('one');
    file_put_contents($d . '/' . $name, $sql);
    return $d;
}

/** The verdict the doctor, A, QF-G2 and QF-CA1 share: the row, found = expected = 38 — never the log line (S2-18, MG4). */
function qa_verdict(\PDO $pdo): string
{
    try {
        $r = $pdo->query("SELECT expected, found FROM _schema_checks WHERE migration = '089_quote_followups.sql'")->fetch(\PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {
        return 'FAIL schema_check_missing:089_quote_followups.sql';
    }
    if (!$r) return 'FAIL schema_check_missing:089_quote_followups.sql';
    return ((int)$r['expected'] === 38 && (int)$r['found'] === 38) ? 'PASS' : 'FAIL schema_check_count';
}

/** The names 089 creates that a book holds, by kind. */
function qa_names(\PDO $pdo): array
{
    $have = [];
    foreach ($pdo->query("SELECT type, name, sql FROM sqlite_master") as $r) $have[$r['type'] . ':' . $r['name']] = (string)$r['sql'];
    $miss = [];
    foreach (QA_TABLES as $n) if (!isset($have['table:' . $n])) $miss[] = $n;
    foreach (QA_INDEXES as $n) if (!isset($have['index:' . $n])) $miss[] = $n;
    $noEnd = [];
    foreach (QA_TRIGGERS as $n) {
        if (!isset($have['trigger:' . $n])) $miss[] = $n;
        elseif (substr(rtrim($have['trigger:' . $n]), -3) !== 'END') $noEnd[] = $n;
    }
    $cols = [];
    foreach (['followups' => ['kind', 'quote_key'], 'wa_auto_sends' => ['win_from', 'win_to', 'cap_day', 'cap_hour'], 'quote_list_sends' => ['sections']] as $t => $want) {
        $got = [];
        try { foreach ($pdo->query("PRAGMA table_info({$t})") as $c) $got[] = $c['name']; } catch (\Throwable $e) {}
        foreach ($want as $c) if (!in_array($c, $got, true)) $miss[] = "{$t}.{$c}";
    }
    return ['missing' => $miss, 'no_end' => $noEnd];
}

/** Run a statement; '' when it succeeded, else the error. */
function qa_try(\PDO $pdo, string $sql, array $a = []): string
{
    try { $st = $pdo->prepare($sql); $st->execute($a); return ''; } catch (\Throwable $e) { return $e->getMessage(); }
}

$QF_SECTIONS['A'] = ['title' => 'Migration 089: the schema (§H.2.4; ruling R9)', 'run' => function (QfCtx $ctx) {
    $tree = $ctx->tree();
    foreach (['StoreInterface', 'SqliteStore', 'MigrationRunner'] as $l) require_once $tree . '/lib/' . $l . '.php';
    $src089 = (string)file_get_contents($tree . '/migrations/089_quote_followups.sql');
    $stmts = MigrationRunner::splitStatements($src089);
    $insert = (string)end($stmts);

    $ctx->run('A01 an empty book: the row, the 38 names, every trigger ending END, the log', function () use ($ctx) {
        $d = $ctx->dir('a-empty');
        $b = ['pdo' => SqliteStore::create($d)->getPdo(), 'data' => $d];   // a store of its own: every migration runs here
        $ctx->eq(qa_verdict($b['pdo']), 'PASS', 'A01 the _schema_checks row: found = expected = 38');
        $n = qa_names($b['pdo']);
        $ctx->eq($n['missing'], [], 'A01 all 38 names are present (6 tables, 13 indexes, 12 triggers, 7 columns)');
        $ctx->eq($n['no_end'], [], 'A01 every 089 trigger ends in END (none cut short by the splitter)');
        $log = (string)@file_get_contents($b['data'] . '/migration.log');
        $ctx->t(strpos($log, 'OK: 089_quote_followups.sql') !== false && strpos($log, 'PARTIAL: 089') === false, 'A01 migration.log holds OK: 089_quote_followups.sql (printed beside the verdict)');
    });

    $ctx->run('A02 a 5.18.103-shaped book: 089 applies, closed follow-ups byte-identical', function () use ($ctx) {
        $d = $ctx->dir('a-103');
        $pdo = qa_pdo($d . '/plugin.sqlite3');
        (new MigrationRunner($pdo, qa_migrations_before($ctx, '089'), $d . '/m1.log'))->run();
        $ctx->eq(qa_verdict($pdo), 'FAIL schema_check_missing:089_quote_followups.sql', 'A02 control: at 088 there is no row');
        $pdo->exec("INSERT INTO followups (conversation_id, channel, phone, last_customer_at, due_at) VALUES (11, 'support', '256772500611', '2026-10-01 08:00:00', '2026-10-02 08:00:00')");
        $pdo->exec("INSERT INTO followups (conversation_id, channel, phone, last_customer_at, closed_at, close_reason) VALUES (12, 'support', '256772500612', '2026-09-01 08:00:00', '2026-09-03 08:00:00', 'replied')");
        $before = $pdo->query('SELECT * FROM followups ORDER BY id')->fetchAll(\PDO::FETCH_ASSOC);
        $res = (new MigrationRunner($pdo, $ctx->tree() . '/migrations', $d . '/m2.log'))->run();
        // 5.18.104 (root docs/73 §H.3.3): from stage B5 the tree holds 090 too; every migration after 088 runs, and runs clean.
        $later = [];
        foreach (glob($ctx->tree() . '/migrations/*.sql') ?: [] as $f) if (strcmp(basename($f), '089') >= 0) $later[basename($f)] = 'ok';
        $ctx->t(isset($later['089_quote_followups.sql']), 'A02 control: 089 is among the migrations after 088');
        $ctx->eq(array_column($res, 'status', 'file'), $later, 'A02 only 089 and the later migrations run, and run clean');
        $ctx->eq(qa_verdict($pdo), 'PASS', 'A02 the row');
        $after = $pdo->query('SELECT * FROM followups ORDER BY id')->fetchAll(\PDO::FETCH_ASSOC);
        $same = true;
        foreach ($before as $i => $row) foreach ($row as $k => $v) if (!array_key_exists($k, $after[$i] ?? []) || $after[$i][$k] !== $v) $same = false;
        $ctx->t($same, 'A02 every existing follow-up keeps every column byte for byte, the closed one included');
        $ctx->eq(array_map(function ($r) { return [$r['kind'], $r['quote_key']]; }, $after), [['enquiry', null], ['enquiry', null]],
                 'A02 and reads kind enquiry, quote_key NULL (an ALTER fires no trigger)');
    });

    $ctx->run('A03 a South Sudan book: the same schema, dark', function () use ($ctx) {
        $d = $ctx->dir('a-ss');
        file_put_contents($d . '/kyc_config.json', json_encode(['tenant_profile' => 'south-sudan']));
        $s = SqliteStore::create($d);
        $ctx->eq([qa_verdict($s->getPdo()), qa_names($s->getPdo())['missing']], ['PASS', []], 'A03 089 applies on every install; only Uganda\'s gated code writes a row');
        $ctx->eq((int)$s->getPdo()->query('SELECT COUNT(*) FROM wa_auto_sends')->fetchColumn(), 0, 'A03 and nothing is written by it');
    });

    $ctx->run('A04 the CHECKs and the limits (ruling R18)', function () use ($ctx) {
        $b = $ctx->book([], 'a-chk'); $p = $b['pdo'];
        $ins = "INSERT INTO wa_auto_sends (handset, kind, ref, try, mode, proactive, phone, local_day, gap_s, outcome, win_from, win_to, cap_day, cap_hour)
                VALUES (:h, :k, :r, :t, :m, :p, NULL, '2026-11-02', :g, :o, :wf, :wt, :cd, :ch)";
        $row = function (array $o) { return $o + [':h' => 'i:test-x', ':k' => 'quote', ':r' => 'r' . mt_rand(), ':t' => 1, ':m' => 'claim', ':p' => 1,
                                                   ':g' => 60, ':o' => null, ':wf' => 8, ':wt' => 20, ':cd' => 60, ':ch' => 15]; };
        $ctx->eq(qa_try($p, $ins, $row([])), '', 'A04 control: a claim row with its four limits is stored');
        $ctx->eq(qa_try($p, $ins, $row([':k' => 'reminder', ':m' => 'record', ':o' => 'sent', ':wf' => null, ':wt' => null, ':cd' => null, ':ch' => null])), '',
                 'A04 control: a reminder record row with an outcome and no limits is stored');
        $ctx->eq(qa_try($p, $ins, $row([':k' => 'voice_ack', ':p' => 0, ':wf' => 7, ':wt' => 21, ':cd' => 25, ':ch' => null])), '',
                 'A04 control: a voice_ack claim with D15\'s hours, its ceiling and no hourly cap is stored');
        $refused = [
            'a reminder row with proactive = 0'      => [':k' => 'reminder', ':m' => 'record', ':p' => 0, ':o' => 'sent', ':wf' => null, ':wt' => null, ':cd' => null, ':ch' => null],
            'a record row without an outcome'        => [':k' => 'reminder', ':m' => 'record', ':o' => null, ':wf' => null, ':wt' => null, ':cd' => null, ':ch' => null],
            'a handset without i:'                   => [':h' => 'test-x'],
            'a claim row without its four limits'    => [':wf' => null, ':wt' => null, ':cd' => null, ':ch' => null],
            'a record row with one limit'            => [':k' => 'reminder', ':m' => 'record', ':o' => 'sent', ':wf' => 8, ':wt' => null, ':cd' => null, ':ch' => null],
            'a voice_ack claim with a cap_hour'      => [':k' => 'voice_ack', ':p' => 0, ':wf' => 7, ':wt' => 21, ':cd' => 25, ':ch' => 15],
            'a proactive window opening at 7'        => [':wf' => 7],
            'a proactive window closing at 21'       => [':wt' => 21],
            'a voice_ack window closing at 22'       => [':k' => 'voice_ack', ':p' => 0, ':wf' => 7, ':wt' => 22, ':cd' => 25, ':ch' => null],
            'a window closing before it opens'       => [':wf' => 12, ':wt' => 12],
            'a daily cap of 61'                      => [':cd' => 61],
            'an hourly cap of 31'                    => [':ch' => 31],
            'a claim of a record-only kind'          => [':k' => 'reminder', ':m' => 'claim'],
            'a fourth try'                           => [':t' => 4],
            'a gap of 901 s'                         => [':g' => 901],
            'an unknown kind'                        => [':k' => 'guest', ':p' => 0],
            'an unknown outcome'                     => [':o' => 'maybe'],
        ];
        foreach ($refused as $what => $o) {
            $e = qa_try($p, $ins, $row($o));
            $ctx->t(stripos($e, 'CHECK constraint failed') !== false, "A04 refused: {$what}", $e === '' ? 'stored' : $e);
        }
        $id = (int)$p->query("SELECT id FROM wa_auto_sends WHERE mode = 'claim' AND kind = 'quote' LIMIT 1")->fetchColumn();
        foreach (['win_from' => 9, 'win_to' => 19, 'cap_day' => 30, 'cap_hour' => 10, 'handset' => "'i:other'", 'ref' => "'x'", 'gap_s' => 70, 'claimed_at' => "'2026-01-01 00:00:00'"] as $col => $v) {
            $e = qa_try($p, "UPDATE wa_auto_sends SET {$col} = {$v} WHERE id = {$id}");
            $ctx->t(strpos($e, 'what was claimed is fixed') !== false, "A04 an UPDATE of {$col} on a claim row is refused (was_identity_fixed)", $e === '' ? 'changed' : $e);
        }
        $qls = 'INSERT INTO quote_list_sends (list_date, owner_key, outcome, sections) VALUES (?, ?, ?, ?)';
        $ctx->eq([qa_try($p, $qls, ['2026-11-02', 'central', 'missed', null]), qa_try($p, $qls, ['2026-11-02', 'staff:1', 'sent', '{"open":[]}'])], ['', ''],
                 'A04 control: a missed list without sections and a sent one with them are stored');
        foreach (['a missed list with sections' => ['2026-11-03', 'central', 'missed', '{}'], 'a sent list without them' => ['2026-11-03', 'staff:2', 'sent', null],
                  'an unknown list outcome' => ['2026-11-03', 'staff:3', 'lost', '{}']] as $what => $a) {
            $ctx->t(stripos(qa_try($p, $qls, $a), 'CHECK constraint failed') !== false, "A04 refused: {$what}");
        }
        $qt = "INSERT INTO quote_tracker (quote_key, quote_ref, phone, evidence_msg_id, evidence_event, made_by, sent_at, sent_conv_id, sent_channel, valid_until, status, backfilled)
               VALUES (?, 'Q-1', '256772500613', 1, 'ev', 'kyc', '2026-11-01 08:00:00', 1, 'support', '2026-11-08', ?, ?)";
        $ctx->eq(qa_try($p, $qt, ['ref:Q-A04', 'open', 0]), '', 'A04 control: a tracked quotation is stored');
        $ctx->t(stripos(qa_try($p, $qt, ['ref:Q-A04b', 'lost', 0]), 'CHECK') !== false && stripos(qa_try($p, $qt, ['ref:Q-A04c', 'open', 2]), 'CHECK') !== false,
                'A04 refused: an unknown tracker status, a backfilled flag other than 0/1');
    });

    $ctx->run('A05 the triggers: kept, settled once, history, erase only', function () use ($ctx) {
        $b = $ctx->book([], 'a-trg'); $p = $b['pdo'];
        $p->exec("INSERT INTO wa_auto_sends (handset, kind, ref, mode, proactive, phone, local_day, gap_s, outcome) VALUES ('i:test-x', 'reminder', 'n:A05', 'record', 1, '256772500614', '2026-11-02', 60, 'sent')");
        $p->exec("INSERT INTO wa_auto_sends (handset, kind, ref, mode, proactive, phone, local_day, gap_s, outcome, claimed_at) VALUES ('i:test-x', 'brief', 'n:A05old', 'record', 1, NULL, '2025-01-02', 60, 'sent', datetime('now', '-500 days'))");
        $ctx->t(strpos(qa_try($p, "UPDATE wa_auto_sends SET outcome = 'uncertain' WHERE ref = 'n:A05'"), 'a claim is settled once') !== false, 'A05 a settled row cannot be settled again (was_settled_once)');
        $ctx->t(strpos(qa_try($p, "DELETE FROM wa_auto_sends WHERE ref = 'n:A05'"), 'kept for 400 days') !== false, 'A05 a ledger row younger than 400 days cannot be deleted');
        $ctx->eq(qa_try($p, "DELETE FROM wa_auto_sends WHERE ref = 'n:A05old'"), '', 'A05 control: one older than 400 days can (a later prune needs no schema change)');
        $ctx->eq(qa_try($p, "UPDATE wa_auto_sends SET phone = NULL WHERE ref = 'n:A05'"), '', 'A05 a ledger phone may be erased (NULL)');
        $ctx->t(strpos(qa_try($p, "UPDATE wa_auto_sends SET phone = '256772500699' WHERE ref = 'n:A05'"), 'may only be erased') !== false, 'A05 and never changed to another number');

        $p->exec("INSERT INTO quote_tracker (quote_key, quote_ref, phone, evidence_msg_id, evidence_event, made_by, sent_at, sent_conv_id, sent_channel, valid_until)
                  VALUES ('ref:Q-A05', 'Q-A05', '256772500615', 3, 'ev', 'kyc', '2026-11-01 08:00:00', 1, 'support', '2026-11-08')");
        $ctx->t(strpos(qa_try($p, "UPDATE quote_tracker SET phone = '256772500698' WHERE quote_key = 'ref:Q-A05'"), 'may only be erased') !== false, 'A05 a tracker phone is never changed');
        $ctx->eq(qa_try($p, "UPDATE quote_tracker SET phone = 'erased' WHERE quote_key = 'ref:Q-A05'"), '', 'A05 … but may be erased (\'erased\')');
        $ctx->t(strpos(qa_try($p, "UPDATE quote_tracker SET sent_at = '2026-11-02 08:00:00' WHERE quote_key = 'ref:Q-A05'"), 'what was sent and when is fixed') !== false, 'A05 what was sent and when is fixed');
        $ctx->eq(qa_try($p, "UPDATE quote_tracker SET status = 'answered', status_at = datetime('now') WHERE quote_key = 'ref:Q-A05'"), '', 'A05 control: a quotation leaves open');
        $ctx->t(strpos(qa_try($p, "UPDATE quote_tracker SET status = 'open' WHERE quote_key = 'ref:Q-A05'"), 'leaves open once') !== false, 'A05 … once');
        $ctx->t(strpos(qa_try($p, "DELETE FROM quote_tracker"), 'never deleted') !== false, 'A05 a tracked quotation is never deleted');
        $p->exec("INSERT INTO quote_list_sends (list_date, owner_key, outcome) VALUES ('2026-11-02', 'central', 'missed')");
        $ctx->t(strpos(qa_try($p, "DELETE FROM quote_list_sends"), 'never deleted') !== false, 'A05 a list record is never deleted');

        $p->exec("INSERT INTO wa_seen_marks (direction, phone, channel, instance, kind, wa_message_id) VALUES ('out', '256772500616', 'support', 'DishNet-UG', 'audioMessage', 'WA-A05')");
        $ctx->t(strpos(qa_try($p, "UPDATE wa_seen_marks SET phone = '256772500697'"), 'may only be erased') !== false, 'A05 a mark\'s phone is never changed');
        $ctx->eq(qa_try($p, "UPDATE wa_seen_marks SET phone = ''"), '', 'A05 … but may be erased (\'\')');
        $ctx->t(strpos(qa_try($p, "UPDATE wa_seen_marks SET kind = 'imageMessage'"), 'never changed') !== false, 'A05 a mark is never changed');
        $ctx->t(strpos(qa_try($p, "DELETE FROM wa_seen_marks"), 'kept for 400 days') !== false, 'A05 a mark younger than 400 days cannot be deleted');
        $ctx->t(stripos(qa_try($p, "INSERT INTO wa_seen_marks (direction, phone, channel, instance, kind, wa_message_id) VALUES ('sideways', '1', 'support', 'x', 'k', 'WA-A05b')"), 'CHECK') !== false,
                'A05 refused: a mark\'s direction other than in/out');
    });

    $ctx->run('A06 the self-check fails closed: PARTIAL with no row, per error class on its INSERT as split (MG4)', function () use ($ctx, $insert, $src089) {
        $ctx->t(strpos($insert, 'INSERT INTO _schema_checks') === 0, 'A06 control: the last statement of 089 as split is its self-check INSERT');
        $ctx->t(stripos($insert, 'INDEX') === false && stripos($insert, 'CREATE') === false, 'A06 the INSERT as split holds neither INDEX nor CREATE, in any case');
        // no such table: the real error, a book with no _schema_checks
        $d = $ctx->dir('a-cls1'); $pdo = qa_pdo($d . '/plugin.sqlite3');
        (new MigrationRunner($pdo, qa_migrations_before($ctx, '089'), $d . '/m1.log'))->run();
        $res = (new MigrationRunner($pdo, qa_one_file($ctx, 'zz_selfcheck.sql', $insert . ';'), $d . '/m2.log'))->run();
        $ctx->eq($res[0]['status'] ?? null, 'partial', 'A06 no such table: the runner does not swallow it (PARTIAL)');
        $ctx->eq(qa_verdict($pdo), 'FAIL schema_check_missing:089_quote_followups.sql', 'A06 … and the verdict reads FAIL (no row)');
        // CHECK constraint failed: a book missing one 089 object
        $b = $ctx->book([], 'a-cls2');
        $b['pdo']->exec('DROP TRIGGER wsm_phone_erase_only');
        $b['pdo']->exec('DELETE FROM _schema_checks');
        $res = (new MigrationRunner($b['pdo'], qa_one_file($ctx, 'zz_selfcheck.sql', $insert . ';'), $b['data'] . '/m3.log'))->run();
        $ctx->eq([$res[0]['status'] ?? null, qa_verdict($b['pdo'])], ['partial', 'FAIL schema_check_missing:089_quote_followups.sql'],
                 'A06 CHECK constraint failed (37 of 38): PARTIAL, no row');
        // no such column: a class 089's fixed identifiers cannot raise, raised by the connection, through the real runner
        $d = $ctx->dir('a-cls3');
        $pdo = qa_pdo($d . '/plugin.sqlite3', QaClassPdo::class);
        (new MigrationRunner($pdo, qa_migrations_before($ctx, '089'), $d . '/m1.log'))->run();
        $pdo->raise = 'SQLSTATE[HY000]: General error: 1 no such column: found';
        $res = (new MigrationRunner($pdo, qa_one_file($ctx, 'zz_selfcheck.sql', $insert . ';'), $d . '/m2.log'))->run();
        $ctx->eq($res[0]['status'] ?? null, 'partial', 'A06 no such column: the runner does not swallow it (PARTIAL)');
        $pdo->raise = 'SQLSTATE[HY000]: General error: 1 no such table: _schema_checks';
        $res = (new MigrationRunner($pdo, qa_one_file($ctx, 'zz_selfcheck2.sql', $insert . ';'), $d . '/m3.log'))->run();
        $ctx->eq($res[0]['status'] ?? null, 'partial', 'A06 no such table, by the connection too: PARTIAL');
        // the row is the verdict: 089 with its _schema_checks CREATE gone, run whole
        $broken = str_replace(QA_SCHEMA_CHECKS_CREATE, '', $src089);
        $ctx->t($broken !== $src089, 'A06 control: the CREATE is found in 089 and removed from the copy');
        $d = $ctx->dir('a-noschk'); $pdo = qa_pdo($d . '/plugin.sqlite3');
        (new MigrationRunner($pdo, qa_migrations_before($ctx, '089'), $d . '/m1.log'))->run();
        $res = (new MigrationRunner($pdo, qa_one_file($ctx, '089_quote_followups.sql', $broken), $d . '/m2.log'))->run();
        $log = (string)@file_get_contents($d . '/m2.log');
        $ctx->eq([$res[0]['status'] ?? null, strpos($log, 'PARTIAL: 089_quote_followups.sql') !== false], ['partial', true],
                 'A06 089 run with _schema_checks missing is logged PARTIAL');
        $ctx->eq(qa_verdict($pdo), 'FAIL schema_check_missing:089_quote_followups.sql', 'A06 and the verdict, read from the row, is FAIL whatever the log says');
    });

    $ctx->run('A07 the _schema_checks CREATE, byte for byte (ruling R9)', function () use ($ctx, $src089) {
        $ctx->eq(substr_count($src089, QA_SCHEMA_CHECKS_CREATE), 1, 'A07 089 holds D\'s _schema_checks CREATE exactly once, with no inline comment');
        $later = [];
        foreach (glob($ctx->tree() . '/migrations/*.sql') ?: [] as $f) {
            if (strcmp(basename($f), '089_quote_followups.sql') <= 0) continue;
            $s = (string)file_get_contents($f);
            if (strpos($s, '_schema_checks') === false) continue;
            $later[basename($f)] = substr_count($s, QA_SCHEMA_CHECKS_CREATE) === 1
                && preg_match('/' . preg_quote(QA_SCHEMA_CHECKS_CREATE, '/') . '\s*INSERT INTO _schema_checks/', $s) === 1;
        }
        $ctx->eq(array_keys(array_filter($later, function ($ok) { return !$ok; })), [],
                 'A07 every later migration that writes _schema_checks repeats the CREATE byte for byte, immediately before its INSERT (' . count($later) . ' found)');
    });
}];
