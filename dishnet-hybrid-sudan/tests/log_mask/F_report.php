<?php
declare(strict_types=1);
/**
 * Section F of tests/test_log_mask.php — 5.18.105 (root docs/74 §H.3.4 F, §H.1.23): the report's BY NUMBER columns,
 * counted in characters. TEST ONLY. Its own file, a section of tests/test_log_mask.php: neither
 * tests/fixtures/sales_followup_report_book.php nor tests/test_sales_followup_report.php is edited.
 *
 * The book is sfr_fixture()'s (tests/fixtures/sales_followup_report_book.php:87), whose channels carry synthetic business
 * numbers, built here on a fresh book of the judged root's migrations, plus F's own rows added through the fixture's
 * helpers sfr_conv()/sfr_in(): 1,000 sessions on the support channel (+256700000000 … +256700000999, one customer
 * message each inside the 30-day window) — in F's own copy of the book only, so K's exact counts are not touched. The
 * report runs on a folder of copies as test_sales_followup_report.php runs it (--db, --data, --at, a vault), once from a
 * copy of the judged tree and once from a copy of 994d660's (r105_base).
 *
 *   F01  counting characters with mb_strlen, every BY NUMBER row's conv field ends where the header's conv column ends,
 *        with a space before it — the rows whose line is ••••NN, the rows whose line is —, and the NEVER_WROTE row, each
 *        its own assertion; the control: the support number's 30-day conv has four digits;
 *   F02  the JSON by_number rows are 994d660's (test_sales_followup_report.php K reads them); the text, whitespace aside,
 *        is 994d660's.
 */
require_once dirname(__DIR__) . '/fixtures/sales_followup_report_book.php';   // sfr_fixture(), sfr_conv(), sfr_in() — read, not edited

/** The run's moment, as test_sales_followup_report.php's SFR_AT: Monday 2 Nov 2026 10:00 Kampala, in UTC. */
const LM_F_AT = '2026-11-02 07:00:00';

/** F's book: sfr_fixture()'s, plus 1,000 sessions on support; a folder of copies (the book, kyc_config.json, a vault). */
function lm_f_folder(QfCtx $ctx): string
{
    lm_store_classes($ctx->root);
    $d = $ctx->dir('f-book');
    $pdo = \SqliteStore::create($d)->getPdo();
    $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    sfr_fixture($pdo, true);
    $pdo->beginTransaction();
    for ($i = 0; $i < 1000; $i++) {
        $c = sfr_conv($pdo, '256700000' . sprintf('%03d', $i), 'support');
        sfr_in($pdo, $c, 'hello (test)', '2026-10-20 09:00:00');
    }
    $pdo->commit();
    $dir = $ctx->dir('f-copies');
    $pdo->exec('PRAGMA wal_checkpoint(TRUNCATE)');
    $pdo->exec('VACUUM INTO ' . $pdo->quote($dir . '/plugin.sqlite3'));
    $c = new \PDO('sqlite:' . $dir . '/plugin.sqlite3');
    $c->exec('PRAGMA journal_mode = DELETE');
    $c = null;
    file_put_contents($dir . '/kyc_config.json', json_encode(QF_UG));
    file_put_contents($dir . '/vault.json', json_encode(['config' => ['currency_symbol' => 'USh']]));
    return $dir;
}

/** Run a tree's tools/sales_followup_report.php on the folder: [rc, stdout, stderr]. */
function lm_f_run(string $tree, string $dir, array $args = []): array
{
    $cmd = 'exec ' . escapeshellarg(PHP_BINARY) . ' -d date.timezone=' . escapeshellarg(QF_TZ) . ' ' . escapeshellarg($tree . '/tools/sales_followup_report.php')
         . ' --db=' . escapeshellarg($dir . '/plugin.sqlite3') . ' --data=' . escapeshellarg($dir) . ' --at=' . escapeshellarg(LM_F_AT)
         . ' ' . implode(' ', array_map('escapeshellarg', $args));
    $env = array_filter(getenv(), function ($k) { return stripos((string)$k, 'proxy') === false; }, ARRAY_FILTER_USE_KEY);
    $env['DN_VAULT_FILE'] = $dir . '/vault.json';
    $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
    $out = (string)stream_get_contents($pipes[1]);
    $err = (string)stream_get_contents($pipes[2]);
    return [proc_close($p), $out, $err];
}

/**
 * The BY NUMBER blocks of a report's text: [window => ['header' => line, 'rows' => [line, …]]], the rows those between the
 * column header and the ALL line.
 */
function lm_f_blocks(string $text): array
{
    $out = []; $cur = null; $want = false;
    foreach (explode("\n", $text) as $l) {
        if (preg_match('/^  BY NUMBER \((\d+) d\)/', $l, $m)) { $cur = (int)$m[1]; $out[$cur] = ['header' => '', 'rows' => []]; $want = true; continue; }
        if ($cur === null) continue;
        if ($want) { $out[$cur]['header'] = $l; $want = false; continue; }
        if (strpos($l, '  ALL') === 0 || trim($l) === '') { $cur = null; continue; }
        $out[$cur]['rows'][] = $l;
    }
    return $out;
}

$LM_SECTIONS['F'] = ['title' => 'The report\'s BY NUMBER columns, counted in characters (§H.3.4 F, §H.1.23)', 'run' => function (QfCtx $ctx) {
    $st = null;
    $get = function () use ($ctx, &$st): array {
        if ($st !== null) return $st;
        [$base, $why] = r105_base($ctx->dir('r105-base'));
        if ($base === null) throw new \RuntimeException('994d660 from Git: ' . $why);
        $dir = lm_f_folder($ctx);
        $newTree = $ctx->dir('f-tree-new'); qf_copy_tree($ctx->root, $newTree);
        $oldTree = $ctx->dir('f-tree-old'); qf_copy_tree($base, $oldTree);
        $st = ['new' => lm_f_run($newTree, $dir), 'newJ' => lm_f_run($newTree, $dir, ['--json']),
               'old' => lm_f_run($oldTree, $dir), 'oldJ' => lm_f_run($oldTree, $dir, ['--json'])];
        return $st;
    };
    $ctx->run('F01 every BY NUMBER row\'s conv field ends at the header\'s conv column, a space before it (mb_strlen)', function () use ($ctx, $get) {
        $r = $get();
        [$rc, $text, $err] = $r['new'];
        $J = json_decode($r['newJ'][1], true);
        $ctx->t($rc === 0 && strpos($text, 'READ-ONLY — nothing was changed.') !== false && is_array($J), 'F01 control: the report ran on F\'s copy and gave its JSON',
            "rc {$rc}\n" . substr($err, -400) . "\n" . substr($text, -400));
        if (!is_array($J)) return;
        $blocks = lm_f_blocks($text);
        $ctx->t(isset($blocks[30]) && $blocks[30]['rows'] !== [], 'F01 control: the BY NUMBER blocks were read (' . implode(', ', array_keys($blocks)) . ' d)');
        $four = 0;
        foreach ((array)($J['by_number']['30'] ?? []) as $n => $row) if ($n !== 'ALL' && (int)($row['conv'] ?? 0) >= 1000) $four++;
        $ctx->eq($four, 1, 'F01 control: one number (support\'s) has a four-digit 30-day conv — F\'s 1,000 sessions');
        $kinds = ['tail' => [], 'dash' => [], 'never' => []]; $seen = ['tail' => 0, 'dash' => 0, 'never' => 0];
        foreach ($blocks as $d => $b) {
            $end = mb_strpos($b['header'], 'conv', 0, 'UTF-8');
            if ($end === false) { $kinds['tail'][] = "{$d} d: no conv column in the header"; continue; }
            $end += 4;
            foreach ((array)($J['by_number'][(string)$d] ?? []) as $n => $row) {
                if ($n === 'ALL') continue;
                $kind = $n === "(quote's own number — customer never wrote)" ? 'never' : (strpos((string)($row['line'] ?? ''), '••••') === 0 ? 'tail' : 'dash');
                $seen[$kind]++;
                $line = null;
                foreach ($b['rows'] as $l) if (strpos($l, '  ' . $n . ' ') === 0) { $line = $l; break; }
                if ($line === null) { $kinds[$kind][] = "{$d} d {$n}: no row"; continue; }
                $head = mb_substr($line, 0, $end, 'UTF-8');
                $ok = preg_match('/ (\d+)$/', $head, $m) === 1 && (int)$m[1] === (int)($row['conv'] ?? -1) && mb_substr($line, $end, 1, 'UTF-8') === ' ';
                if (!$ok) $kinds[$kind][] = "{$d} d {$n}: '" . $head . "|" . mb_substr($line, $end, 6, 'UTF-8') . "'";
            }
        }
        $ctx->t($seen['tail'] > 0 && $kinds['tail'] === [], 'F01 every BY NUMBER row whose line is ••••NN: its conv field ends at the header\'s conv column, a space before it (' . $seen['tail'] . ' rows)', implode("\n", $kinds['tail']));
        $ctx->t($seen['dash'] > 0 && $kinds['dash'] === [], 'F01 every BY NUMBER row whose line is —: the same (' . $seen['dash'] . ' rows)', implode("\n", $kinds['dash']));
        $ctx->t($seen['never'] > 0 && $kinds['never'] === [], 'F01 the NEVER_WROTE row: the same (' . $seen['never'] . ' rows)', implode("\n", $kinds['never']));
    });
    $ctx->run('F02 the JSON by_number rows, and the text whitespace aside, are 994d660\'s', function () use ($ctx, $get) {
        $r = $get();
        $J = json_decode($r['newJ'][1], true);
        $O = json_decode($r['oldJ'][1], true);
        $ctx->t(is_array($O) && $r['old'][0] === 0, 'F02 control: 994d660\'s report ran on the same copy and gave its JSON', substr($r['old'][2], -400));
        $ctx->t(is_array($J) && isset($J['by_number']['30']) && count($J['by_number']['30']) > 3, 'F02 control: the judged report\'s by_number has its rows');
        $ctx->eq($J['by_number'] ?? null, $O['by_number'] ?? 'absent', 'F02 the JSON by_number rows are 994d660\'s, row for row');
        $strip = function (string $s): string { return (string)preg_replace('/\s+/u', '', $s); };
        $ctx->eq($strip($r['new'][1]), $strip($r['old'][1]), 'F02 the text, whitespace aside, is 994d660\'s');
    });
}];
