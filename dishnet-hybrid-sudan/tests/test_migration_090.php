<?php
declare(strict_types=1);
/**
 * test_migration_090.php — 5.18.104 (root docs/73 §H.3.3; C2, K2-04, S2-10, rulings R9, R18): migration 090_wa_voice.sql.
 *
 * A the objects on an empty book and on a 5.18.103-shaped one (089 then 090): three tables, nine indexes, the 64
 * columns, three of them unique, every CHECK, no trigger, no comment, no ALTER or DROP, 089's _schema_checks CREATE
 * repeated immediately before the self-check, and the row `090_wa_voice.sql` with found = expected = 79;
 * B an 085-shaped wa_media (the development tree's, 8 columns short): PARTIAL naming CHECK constraint failed, no row;
 * C the self-check per error class, through the runner on the statement as split (V39): the row is the evidence, never
 * the log. Each section begins with its positive control. Then the weakened copies (V39's three forms and the schema's
 * own guards), each judged by this file run on the copy with the sections it names. The comparison of the three
 * _schema_checks CREATE texts is tests/test_quote_followup.php section A's (A07, and its copy MG6-090).
 *
 *   cd tests && DN_VAULT_FILE=$(mktemp -d)/v.json php test_migration_090.php [--root=DIR] [--sections=A] [--no-mutants]
 */
define('QF_TZ', 'Africa/Kampala');
require_once __DIR__ . '/voice_media/_lib.php';
date_default_timezone_set('UTC');

$O   = qf_options();
$own = qf_own_tmp('m90');
$ctx = new QfCtx($O['root'], $own);
$ctx->cases = $O['cases'];

/** A PDO whose exec() answers 090's self-check INSERT with an error of a class the test names. */
final class M90ClassPdo extends \PDO
{
    public $raise = '';
    #[\ReturnTypeWillChange]
    public function exec($statement)
    {
        if ($this->raise !== '' && strpos((string)$statement, 'INSERT INTO _schema_checks') === 0
            && strpos((string)$statement, '090_wa_voice.sql') !== false) throw new \PDOException($this->raise);
        return parent::exec($statement);
    }
}

const M90_COLS = [
    'wa_media' => ['id', 'conversation_id', 'message_id', 'wa_message_id', 'instance', 'channel', 'remote_jid', 'from_me', 'kind', 'mimetype',
                   'file_name', 'caption', 'declared_bytes', 'seconds', 'status', 'attempts', 'failure_reason', 'fetched_bytes', 'fetched_mimetype',
                   'sha256', 'understanding', 'understanding_kind', 'created_at', 'updated_at', 'ptt', 'voice_rung', 'local_seconds', 'provider',
                   'provider_model', 'provider_ms', 'provider_seconds', 'transcript_chars'],
    'wa_voice_calls' => ['id', 'media_id', 'conversation_id', 'attempt', 'model', 'local_day', 'seconds_reserved', 'seconds_billed', 'cap_day_s',
                         'voice_rung', 'outcome', 'reason', 'http', 'ms', 'created_at', 'settled_at'],
    'wa_voice_acks' => ['id', 'wa_message_id', 'conversation_id', 'channel', 'handset', 'outcome', 'reason', 'variant', 'persona', 'ledger_ref',
                        'period_hours', 'gap_s', 'local_day', 'sent_at', 'created_at', 'updated_at'],
];
const M90_INDEXES = ['idx_wa_media_wa_message_id' => 1, 'idx_wa_media_status' => 0, 'idx_wa_media_conversation' => 0,
                     'idx_wa_voice_calls_attempt' => 1, 'idx_wa_voice_calls_day' => 0, 'idx_wa_voice_calls_conv' => 0,
                     'idx_wa_voice_acks_msg' => 1, 'idx_wa_voice_acks_conv' => 0, 'idx_wa_voice_acks_handset' => 0];
/** The eight columns this release adds to the development tree's 085 shape. */
const M90_NEW = ['ptt', 'voice_rung', 'local_seconds', 'provider', 'provider_model', 'provider_ms', 'provider_seconds', 'transcript_chars'];

function m90_pdo(string $file, string $class = \PDO::class): \PDO
{
    $p = new $class('sqlite:' . $file);
    $p->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    return $p;
}

/** The row is the verdict every reader shares (the tool's on ack, the doctor, VN-M1): found = expected = 79. */
function m90_verdict(\PDO $pdo): string
{
    try {
        $r = $pdo->query("SELECT expected, found FROM _schema_checks WHERE migration = '090_wa_voice.sql'")->fetch(\PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {
        return 'FAIL schema_check_missing:090_wa_voice.sql';
    }
    if (!$r) return 'FAIL schema_check_missing:090_wa_voice.sql';
    return ((int)$r['expected'] === 79 && (int)$r['found'] === 79) ? 'PASS' : 'FAIL schema_check_count';
}

/** The migrations of the tree before $before (by name), in a folder of their own. */
function m90_before(QfCtx $ctx, string $before): string
{
    $d = $ctx->dir('mig');
    foreach (glob($ctx->tree() . '/migrations/*.sql') ?: [] as $f) if (strcmp(basename($f), $before) < 0) copy($f, $d . '/' . basename($f));
    return $d;
}

function m90_one(QfCtx $ctx, string $name, string $sql): string
{
    $d = $ctx->dir('one');
    file_put_contents($d . '/' . $name, $sql);
    return $d;
}

function m90_try(\PDO $pdo, string $sql, array $a = []): string
{
    try { $st = $pdo->prepare($sql); $st->execute($a); return ''; } catch (\Throwable $e) { return $e->getMessage(); }
}

$SECTIONS = [
    'A' => ['title' => 'Migration 090: the objects, the 79 and the row', 'run' => function (QfCtx $ctx): void {
        $tree = $ctx->tree();
        foreach (['StoreInterface', 'SqliteStore', 'MigrationRunner'] as $l) require_once $tree . '/lib/' . $l . '.php';
        $src = (string)file_get_contents($tree . '/migrations/090_wa_voice.sql');

        $ctx->run('A01 control: an empty book — the row 090_wa_voice.sql, found = expected = 79, the log OK', function () use ($ctx) {
            $d = $ctx->dir('a-empty');
            $pdo = SqliteStore::create($d)->getPdo();
            $ctx->eq(m90_verdict($pdo), 'PASS', 'A01 the row: 79 of 79');
            $ctx->t(strpos((string)@file_get_contents($d . '/migration.log'), 'OK: 090_wa_voice.sql') !== false, 'A01 the log reads OK');
            foreach (M90_COLS as $t => $cols) {
                $got = array_column($pdo->query("PRAGMA table_info({$t})")->fetchAll(\PDO::FETCH_ASSOC), 'name');
                $ctx->eq($got, $cols, "A01 {$t}: exactly its columns, in order");
            }
            $idx = [];
            foreach ($pdo->query("SELECT name, sql FROM sqlite_master WHERE type = 'index' AND tbl_name IN ('wa_media','wa_voice_calls','wa_voice_acks') AND name LIKE 'idx_%'") as $r) {
                $idx[$r['name']] = stripos((string)$r['sql'], 'UNIQUE') !== false ? 1 : 0;
            }
            ksort($idx); $want = M90_INDEXES; ksort($want);
            $ctx->eq($idx, $want, 'A01 the nine indexes, three of them unique');
            $ctx->eq((int)$pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'trigger' AND tbl_name IN ('wa_media','wa_voice_calls','wa_voice_acks')")->fetchColumn(), 0, 'A01 no trigger');
        });

        $ctx->run('A02 089 runs first, and a 5.18.103-shaped book takes 089 then 090', function () use ($ctx) {
            $d = $ctx->dir('a-103'); $pdo = m90_pdo($d . '/plugin.sqlite3');
            (new MigrationRunner($pdo, m90_before($ctx, '089'), $d . '/m1.log'))->run();
            $ctx->eq((int)$pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE name IN ('wa_media','_schema_checks')")->fetchColumn(), 0, 'A02 control: a 5.18.103 book holds neither');
            $res = (new MigrationRunner($pdo, $ctx->tree() . '/migrations', $d . '/m2.log'))->run();
            $ran = array_map(function ($r) { return $r['file'] . ':' . $r['status']; }, array_values(array_filter($res, function ($r) { return isset($r['file']) && in_array($r['file'], ['089_quote_followups.sql', '090_wa_voice.sql'], true); })));
            $ctx->eq($ran, ['089_quote_followups.sql:ok', '090_wa_voice.sql:ok'], 'A02 089 then 090, both OK');
            $ctx->eq([m90_verdict($pdo), (int)$pdo->query("SELECT COUNT(*) FROM _schema_checks WHERE migration = '089_quote_followups.sql'")->fetchColumn()], ['PASS', 1],
                     'A02 both rows written');
        });

        $ctx->run('A03 the file: no trigger, no comment, no ALTER, no DROP; 089\'s CREATE repeated right before the self-check (ruling R9)', function () use ($ctx, $src) {
            $c089 = (string)file_get_contents($ctx->tree() . '/migrations/089_quote_followups.sql');
            $i = strpos($c089, 'CREATE TABLE IF NOT EXISTS _schema_checks (');
            $create = substr($c089, $i, strpos($c089, 'CHECK (found = expected));', $i) + strlen('CHECK (found = expected));') - $i);
            $ctx->t(strlen($create) > 100 && preg_match('/' . preg_quote($create, '/') . '\s*INSERT INTO _schema_checks/', $src) === 1,
                    'A03 089\'s _schema_checks CREATE, byte for byte, immediately before the INSERT');
            $ctx->eq([preg_match('/\bTRIGGER\b/i', $src), preg_match('/\bALTER\b/i', $src), preg_match('/\bDROP\b/i', $src), strpos($src, '--'), strpos($src, '/*')],
                     [0, 0, 0, false, false], 'A03 no trigger, no ALTER, no DROP, no comment');
            $stmts = MigrationRunner::splitStatements($src);
            $ins = (string)end($stmts);
            $ctx->t(strpos($ins, 'INSERT INTO _schema_checks') === 0 && stripos($ins, 'INDEX') === false && preg_match('/\bCREATE\b/i', $ins) === 0,
                    'A03 the last statement as split is the self-check, with no INDEX and no CREATE but inside created_at');
        });

        $ctx->run('A04 every CHECK (rulings R18, Q18-V03)', function () use ($ctx) {
            $b = $ctx->book([], 'a-chk'); $p = $b['pdo'];
            $vc = "INSERT INTO wa_voice_calls (media_id, conversation_id, attempt, model, local_day, seconds_reserved, seconds_billed, cap_day_s, voice_rung, outcome)
                   VALUES (?, 1, ?, 'whisper-1', '2026-11-05', ?, ?, ?, ?, ?)";
            $ctx->eq(m90_try($p, $vc, [1, 1, 3, null, 7200, 'dry_run', 'started']), '', 'A04 control: a reservation is stored');
            foreach (['an unknown rung' => [2, 1, 3, null, 7200, 'off', 'started'], 'an unknown outcome' => [3, 1, 3, null, 7200, 'dry_run', 'lost'],
                      'negative reserved seconds' => [4, 1, -1, null, 7200, 'dry_run', 'started'], 'negative billed seconds' => [5, 1, 3, -2, 7200, 'dry_run', 'ok'],
                      'a negative cap' => [6, 1, 3, null, -60, 'dry_run', 'started'], 'no rung' => [7, 1, 3, null, 7200, null, 'started']] as $what => $a) {
                $e = m90_try($p, $vc, $a);
                $ctx->t($e !== '' && (stripos($e, 'CHECK') !== false || stripos($e, 'NOT NULL') !== false), "A04 wa_voice_calls refuses {$what}", $e);
            }
            $ctx->t(stripos(m90_try($p, $vc, [1, 1, 3, null, 7200, 'dry_run', 'started']), 'UNIQUE') !== false, 'A04 one reservation per note and attempt');
            $va = "INSERT INTO wa_voice_acks (wa_message_id, conversation_id, channel, handset, outcome, variant, persona, period_hours, gap_s, local_day)
                   VALUES (?, 1, 'sales', 'i:sales-ug', ?, ?, ?, ?, ?, '2026-11-05')";
            $ctx->eq([m90_try($p, $va, ['A1', 'claimed', 'A', 0, 24, 20]), m90_try($p, $va, ['A2', 'skipped', null, 0, null, null])], ['', ''],
                     'A04 control: a claimed decision with its limits, a skip without them');
            foreach (['an unknown outcome' => ['A3', 'lost', 'A', 0, 24, 20], 'a variant C' => ['A4', 'sent', 'C', 0, 24, 20], 'a persona of 2' => ['A5', 'sent', 'A', 2, 24, 20],
                      'a claimed decision without its period' => ['A6', 'claimed', 'A', 0, null, 20], 'a sent decision without its gap' => ['A7', 'sent', 'B', 0, 24, null]] as $what => $a) {
                $ctx->t(stripos(m90_try($p, $va, $a), 'CHECK') !== false, "A04 wa_voice_acks refuses {$what}");
            }
            $ctx->t(stripos(m90_try($p, $va, ['A1', 'skipped', null, 0, null, null]), 'UNIQUE') !== false, 'A04 one decision per message');
            $vm = "INSERT INTO wa_media (conversation_id, wa_message_id, instance, channel, remote_jid, kind) VALUES (1, ?, 'i', 'sales', 'j', 'audio')";
            $ctx->eq([m90_try($p, $vm, ['M1']), stripos(m90_try($p, $vm, ['M1']), 'UNIQUE') !== false], ['', true], 'A04 wa_media: one row per WhatsApp message id');
        });
    }],
    'B' => ['title' => 'An 085-shaped wa_media: PARTIAL, CHECK constraint failed, and no row', 'run' => function (QfCtx $ctx): void {
        $tree = $ctx->tree();
        foreach (['StoreInterface', 'SqliteStore', 'MigrationRunner'] as $l) require_once $tree . '/lib/' . $l . '.php';
        $src = (string)file_get_contents($tree . '/migrations/090_wa_voice.sql');
        $shape085 = function (\PDO $pdo) {
            $cols = array_values(array_diff(M90_COLS['wa_media'], M90_NEW));
            $def = [];
            foreach ($cols as $c) $def[] = $c === 'id' ? 'id INTEGER PRIMARY KEY AUTOINCREMENT' : $c . ' TEXT';
            $pdo->exec('CREATE TABLE wa_media (' . implode(', ', $def) . ')');
            $pdo->exec('CREATE UNIQUE INDEX idx_wa_media_wa_message_id ON wa_media(wa_message_id)');
            $pdo->exec('CREATE INDEX idx_wa_media_status ON wa_media(status, created_at)');
            $pdo->exec('CREATE INDEX idx_wa_media_conversation ON wa_media(conversation_id, id)');
            return count($cols);
        };
        $ctx->run('B01 control: on a 5.18.103 book 090 writes its row', function () use ($ctx) {
            $d = $ctx->dir('b-ok'); $pdo = m90_pdo($d . '/plugin.sqlite3');
            (new MigrationRunner($pdo, m90_before($ctx, '091'), $d . '/m.log'))->run();
            $ctx->eq(m90_verdict($pdo), 'PASS', 'B01 PASS');
        });
        $ctx->run('B02 an 085-shaped wa_media: found 71, the CHECK refuses the row, the log says so', function () use ($ctx, $shape085) {
            $d = $ctx->dir('b-085'); $pdo = m90_pdo($d . '/plugin.sqlite3');
            (new MigrationRunner($pdo, m90_before($ctx, '090'), $d . '/m1.log'))->run();
            $n = $shape085($pdo);
            $ctx->eq($n, 24, 'B02 control: the 085 shape has 24 columns, 8 fewer than 090\'s');
            $res = (new MigrationRunner($pdo, m90_one($ctx, '090_wa_voice.sql', (string)file_get_contents($ctx->tree() . '/migrations/090_wa_voice.sql')), $d . '/m2.log'))->run();
            $log = (string)@file_get_contents($d . '/m2.log');
            $ctx->eq([$res[0]['status'] ?? null, strpos($log, 'PARTIAL: 090_wa_voice.sql') !== false, stripos($log, 'CHECK constraint failed') !== false],
                     ['partial', true, true], 'B02 PARTIAL: 090_wa_voice.sql, naming CHECK constraint failed', $log);
            $ctx->eq(m90_verdict($pdo), 'FAIL schema_check_missing:090_wa_voice.sql', 'B02 and no 090 row: every reader refuses');
        });
    }],
    'C' => ['title' => 'The self-check per error class, through the runner on the statement as split (V39)', 'run' => function (QfCtx $ctx): void {
        $tree = $ctx->tree();
        foreach (['StoreInterface', 'SqliteStore', 'MigrationRunner'] as $l) require_once $tree . '/lib/' . $l . '.php';
        $src = (string)file_get_contents($tree . '/migrations/090_wa_voice.sql');
        $ctx->run('C01 control: the statement as split is the self-check', function () use ($ctx, $src) {
            $st = MigrationRunner::splitStatements($src);
            $ctx->t(strpos((string)end($st), "INSERT INTO _schema_checks (migration, expected, found)\nSELECT '090_wa_voice.sql', 79,") === 0, 'C01 the INSERT names its key and 79');
        });
        $class = function (string $raise) use ($ctx, $src): array {
            $d = $ctx->dir('c-cls'); $pdo = m90_pdo($d . '/plugin.sqlite3', M90ClassPdo::class);
            (new MigrationRunner($pdo, m90_before($ctx, '090'), $d . '/m1.log'))->run();
            $pdo->raise = $raise;
            $res = (new MigrationRunner($pdo, m90_one($ctx, '090_wa_voice.sql', $src), $d . '/m2.log'))->run();
            $pdo->raise = '';
            return [$res[0]['status'] ?? null, (string)@file_get_contents($d . '/m2.log'), m90_verdict($pdo)];
        };
        $ctx->run('C02 no such table on the INSERT: not swallowed (it holds no INDEX)', function () use ($ctx, $class) {
            [$st, $log, $v] = $class('SQLSTATE[HY000]: General error: 1 no such table: pragma_table_info');
            $ctx->eq([$st, strpos($log, 'PARTIAL: 090_wa_voice.sql') !== false, $v], ['partial', true, 'FAIL schema_check_missing:090_wa_voice.sql'], 'C02 PARTIAL, no row');
        });
        $ctx->run('C03 no such column on the INSERT: the runner swallows it (CREATE inside created_at) — and the missing row still refuses', function () use ($ctx, $class) {
            [$st, $log, $v] = $class('SQLSTATE[HY000]: General error: 1 no such column: seconds');
            $ctx->eq([$st, strpos($log, 'OK: 090_wa_voice.sql') !== false, $v], ['ok', true, 'FAIL schema_check_missing:090_wa_voice.sql'],
                     'C03 the log reads OK; the verdict, from the row, reads FAIL');
        });
        $ctx->run('C04 090 verifies itself where 089\'s _schema_checks never came to be', function () use ($ctx, $src) {
            $d = $ctx->dir('c-noschk'); $pdo = m90_pdo($d . '/plugin.sqlite3');
            (new MigrationRunner($pdo, m90_before($ctx, '089'), $d . '/m1.log'))->run();
            $ctx->eq((int)$pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE name = '_schema_checks'")->fetchColumn(), 0, 'C04 control: no _schema_checks on this book');
            (new MigrationRunner($pdo, m90_one($ctx, '090_wa_voice.sql', $src), $d . '/m2.log'))->run();
            $ctx->eq(m90_verdict($pdo), 'PASS', 'C04 090\'s own CREATE makes the table, and its row is written');
        });
    }],
];

qf_run_sections($ctx, $SECTIONS, $O['sections']);

if ($O['mutants']) {
    $G = 'migrations/090_wa_voice.sql';
    $create = "CREATE TABLE IF NOT EXISTS _schema_checks (\n  migration  TEXT PRIMARY KEY,\n  expected   INTEGER NOT NULL,\n  found      INTEGER NOT NULL,\n"
            . "  checked_at TEXT NOT NULL DEFAULT (datetime('now')),\n  CHECK (found = expected));\n";
    $cols085 = "            'id','conversation_id','message_id','wa_message_id','instance','channel','remote_jid','from_me','kind',\n"
             . "            'mimetype','file_name','caption','declared_bytes','seconds','status','attempts','failure_reason',\n"
             . "            'fetched_bytes','fetched_mimetype','sha256','understanding','understanding_kind','created_at','updated_at',\n"
             . "            'ptt','voice_rung','local_seconds','provider','provider_model','provider_ms','provider_seconds',\n"
             . "            'transcript_chars'))";
    qf_mutants($ctx, __FILE__, [
        ['label' => 'V39a the _schema_checks INSERT removed', 'file' => $G, 'old' => "INSERT INTO _schema_checks (migration, expected, found)\nSELECT '090_wa_voice.sql', 79,",
         'new' => "SELECT '090_wa_voice.sql', 79,", 'sections' => 'A', 'cases' => 'A01', 'pattern' => '/A01 the row/'],
        ['label' => 'V39b the INSERT carrying INDEX, and 090\'s repeated CREATE dropped', 'edits' => [
            [$G, $create, ''], [$G, "SELECT '090_wa_voice.sql', 79,", "SELECT '090_wa_voice.sql' AS index_probe, 79,"]],
         'sections' => 'C', 'cases' => 'C04', 'pattern' => '/C04 090/'],
        ['label' => 'V39c the column list (and its count) cut to 085\'s', 'edits' => [
            [$G, $cols085 . "\n     + (SELECT COUNT(*) FROM pragma_table_info('wa_voice_calls')",
             "            'id','conversation_id','message_id','wa_message_id','instance','channel','remote_jid','from_me','kind',\n"
             . "            'mimetype','file_name','caption','declared_bytes','seconds','status','attempts','failure_reason',\n"
             . "            'fetched_bytes','fetched_mimetype','sha256','understanding','understanding_kind','created_at','updated_at'))\n"
             . "     + (SELECT COUNT(*) FROM pragma_table_info('wa_voice_calls')"],
            [$G, "SELECT '090_wa_voice.sql', 79,", "SELECT '090_wa_voice.sql', 71,"]],
         'sections' => 'A,B', 'cases' => 'A01|B02', 'pattern' => '/A01 the row|B02 /'],
        ['label' => 'M90a the rung CHECK dropped', 'file' => $G, 'old' => "    voice_rung        TEXT    NOT NULL CHECK (voice_rung IN ('fetch_only','dry_run','handover','reply')),",
         'new' => '    voice_rung        TEXT    NOT NULL,', 'sections' => 'A', 'cases' => 'A04', 'pattern' => '/A04 /'],
        ['label' => 'M90b the limits CHECK on a decision dropped (ruling R18)', 'file' => $G,
         'old' => "    updated_at       TEXT    NOT NULL DEFAULT (datetime('now')),\n    CHECK (outcome = 'skipped' OR (period_hours IS NOT NULL AND gap_s IS NOT NULL))\n",
         'new' => "    updated_at       TEXT    NOT NULL DEFAULT (datetime('now'))\n", 'sections' => 'A', 'cases' => 'A04', 'pattern' => '/A04 /'],
        ['label' => 'M90c the message-id index not unique', 'file' => $G, 'old' => 'CREATE UNIQUE INDEX IF NOT EXISTS idx_wa_media_wa_message_id ON wa_media(wa_message_id);',
         'new' => 'CREATE INDEX IF NOT EXISTS idx_wa_media_wa_message_id ON wa_media(wa_message_id);', 'sections' => 'A', 'cases' => 'A01', 'pattern' => '/A01 /'],
        ['label' => 'M90d a comment in the file', 'file' => $G, 'old' => "CREATE TABLE IF NOT EXISTS wa_voice_calls (\n",
         'new' => "-- the reservations\nCREATE TABLE IF NOT EXISTS wa_voice_calls (\n", 'sections' => 'A', 'cases' => 'A03', 'pattern' => '/A03 no trigger/'],
        ['label' => 'M90e the cap a reservation applied not required', 'file' => $G, 'old' => '    cap_day_s         INTEGER NOT NULL CHECK (cap_day_s >= 0),',
         'new' => '    cap_day_s         INTEGER,', 'sections' => 'A', 'cases' => 'A04', 'pattern' => '/A04 /'],
    ], $O['only']);
}

printf("\n%d passed, %d failed\n", $ctx->pass, $ctx->fail);
exit($ctx->fail === 0 ? 0 : 1);
