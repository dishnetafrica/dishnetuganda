<?php
declare(strict_types=1);
/**
 * test_sales_followup_report.php — 5.18.104 (Uganda, root docs/73 §H.2.8, §H.2.14 K, §H.2.21; root docs/72 §F.1 §3.7):
 * the read-only sales follow-up report, tools/sales_followup_report.php. Section K.
 *
 * The tool runs from a copy of the judged tree, in a child process as the operator's wrapper runs it: on a copy of a
 * book (made by VACUUM INTO, journal_mode DELETE), in a folder that holds the copies of the settings files and a vault,
 * with --data that folder, --at a fixed UTC moment, under the Kampala process zone (so a window computed from local
 * time would show). The book is tests/fixtures/sales_followup_report_book.php's: every definition, and the K additions
 * (double-encoded handset metadata, an Inbox image dated in Kampala, 'PENDING', a partner's number, the F4 order).
 * Section K starts with its positive control; the weakened copies (RP1–RP15, HT19 and the guards K names) follow, each
 * judged by this file run again with --root=<the copy> --sections=K. Every person, number and amount is fictitious.
 *
 *   cd tests && DN_VAULT_FILE=$(mktemp -d)/v.json php test_sales_followup_report.php [--root=DIR] [--cases=REGEX]
 *        [--no-mutants] [--mutants=REGEX]
 */
define('QF_TZ', 'Africa/Kampala');
require_once __DIR__ . '/quote_followup/_lib.php';
require_once __DIR__ . '/fixtures/sales_followup_report_book.php';
date_default_timezone_set('UTC');

const SFR_AT = '2026-11-02 07:00:00';   // Monday 10:00 Kampala, UTC

/** A copy of $b's book in a folder of its own, with the settings copies and a vault: the folder. */
function sfr_folder(QfCtx $ctx, array $b, array $kyc = [], array $vault = ['currency_symbol' => 'USh']): string
{
    $dir = $ctx->dir('copies');
    $b['pdo']->exec('PRAGMA wal_checkpoint(TRUNCATE)');
    $b['pdo']->exec("VACUUM INTO " . $b['pdo']->quote($dir . '/plugin.sqlite3'));
    $c = new \PDO('sqlite:' . $dir . '/plugin.sqlite3');
    $c->exec('PRAGMA journal_mode = DELETE');
    $c = null;
    file_put_contents($dir . '/kyc_config.json', json_encode($kyc + QF_UG));
    file_put_contents($dir . '/vault.json', json_encode(['config' => $vault]));
    return $dir;
}

/** Run the tool from the copy of the tree on a folder of copies. [rc, stdout, stderr]. */
function sfr_run(QfCtx $ctx, string $dir, array $args = [], string $zone = QF_TZ, string $tool = ''): array
{
    $tool = $tool !== '' ? $tool : $ctx->tree() . '/tools/sales_followup_report.php';
    $cmd = 'exec php -d date.timezone=' . escapeshellarg($zone) . ' ' . escapeshellarg($tool) . ' --db=' . escapeshellarg($dir . '/plugin.sqlite3')
         . ' --data=' . escapeshellarg($dir) . ' --at=' . escapeshellarg(SFR_AT) . ' ' . implode(' ', array_map('escapeshellarg', $args));
    $env = array_filter(getenv(), function ($k) { return stripos((string)$k, 'proxy') === false; }, ARRAY_FILTER_USE_KEY);
    $env['DN_VAULT_FILE'] = $dir . '/vault.json';
    $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
    $out = (string)stream_get_contents($pipes[1]);
    $err = (string)stream_get_contents($pipes[2]);
    $rc = proc_close($p);
    return [$rc, $out, $err];
}

/** The folder's files, each with its sha256. */
function sfr_files(string $dir): array
{
    $o = [];
    foreach (scandir($dir) ?: [] as $f) if ($f !== '.' && $f !== '..') $o[$f] = hash_file('sha256', $dir . '/' . $f);
    ksort($o);
    return $o;
}

function sfr_rows(\PDO $p, string $sql, array $a = []): array { $st = $p->prepare($sql); $st->execute($a); return $st->fetchAll(\PDO::FETCH_ASSOC); }

/** A fixture book on the copy's migrations. */
function sfr_book(QfCtx $ctx, bool $withNew = true): array
{
    $b = $ctx->book(QF_UG + ['quote_tracking_enabled' => '1', 'quote_tracking_since' => '2026-10-01 00:00:00'], 'k');
    $b['fx'] = sfr_fixture($b['pdo'], $withNew);
    return $b;
}

$O   = qf_options();
$own = qf_own_tmp('sfr');
$ctx = new QfCtx($O['root'], $own);
$ctx->cases = $O['cases'];

$QF_SECTIONS = ['K' => ['title' => 'The read-only report (§H.2.8, §H.2.21)', 'run' => function (QfCtx $ctx) {
    $tree = $ctx->tree();
    foreach (['StoreInterface', 'SqliteStore', 'UtcClock', 'QuoteTracker', 'FollowUpPolicy'] as $l) require_once $tree . '/lib/' . $l . '.php';
    $b = sfr_book($ctx);
    $dir = sfr_folder($ctx, $b);
    [$rc, $text, $err] = sfr_run($ctx, $dir);
    $lines = explode("\n", rtrim($text));
    $ctx->t($rc === 0 && trim((string)end($lines)) === 'READ-ONLY — nothing was changed.', 'K00 control: the report runs on the copy and ends READ-ONLY',
            "rc {$rc}\n{$err}\n" . substr($text, -600));
    [$rcj, $json] = sfr_run($ctx, $dir, ['--json']);
    $J = json_decode($json, true);
    $ctx->t($rcj === 0 && is_array($J), 'K00 control: --json gives the same counts as JSON', substr($json, 0, 400));
    if (!is_array($J)) return;
    $row = function (string $n, int $d = 30) use ($J): array {
        $r = $J['by_number'][(string)$d][$n] ?? null;
        if (!is_array($r)) return ['absent'];
        return [$r['conv'], $r['cust'], $r['new'], $r['quotes'], $r['kyc'], $r['answ'], $r['chased'], $r['eng'], $r['per'], $r['neither'], $r['reg'], $r['paid'], $r['optout']];
    };
    $NW = "(quote's own number — customer never wrote)";

    $ctx->run('K01 every definition, number by number (30 d): conv cust new quotes kyc answ chased eng per neither reg paid optout', function () use ($ctx, $row, $NW, $J) {
        $ctx->eq($row('sales'), [3, 2, 2, 2, 1, 1, 1, 0, 1, 0, 0, 0, 0],
                 'K01 sales: two sessions of one customer 48 h apart; the reply on sales to a quotation sent to support (RP1); a handset chase whose metadata is double-encoded (RP10)');
        $ctx->eq($row('support'), [11, 10, 10, 9, 1, 1, 1, 1, 0, 7, 0, 1, 1],
                 'K01 support: STOP-only is an opt-out, never an answer; the engine\'s chase; no AI reply, notification, DishNet row, our own echo or earlier image is a chase (RP2, RP11, HT); the +211 number is not the customer (RP4); a mark answers');
        $ctx->eq($row('account'), [1, 1, 1, 1, 0, 0, 0, 0, 0, 1, 1, 1, 0], 'K01 account: `accounts` folded into account; registered by its client; paid by a cashbook receipt for its client');
        $ctx->eq($row('retailer-001'), [1, 1, 1, 1, 0, 0, 0, 0, 0, 1, 1, 0, 0], 'K01 a partner\'s number: its own row; registered by its wa_quote_phone');
        $ctx->eq($row('sales-002'), [1, 1, 1, 1, 1, 0, 0, 0, 0, 1, 0, 1, 0], 'K01 a salesperson\'s number: its own row; paid by a Cash application for its client');
        $ctx->eq($row($NW), [0, 0, 0, 1, 0, 0, 0, 0, 0, 1, 1, 1, 0], 'K01 the customer never wrote: its own row, never support\'s (RP8); registered by its mobile, paid by the receipt');
        $ctx->eq($row('web chat'), [1, 1, 1, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0], 'K01 a web: session: the web chat\'s row');
        $ctx->eq($row('ALL'), [18, 16, 16, 15, 3, 2, 2, 1, 1, 11, 3, 4, 1],
                 'K01 ALL: a quote_cash receipt, a claim with no message, a colleague\'s phone and a staff thread are no quotation (RP3, RP7); PENDING is no application\'s number (RP13)');
        $ctx->eq($J['by_number']['30']['ALL']['sent_from'] ?? null, ['support' => 14, 'account' => 1], 'K01 sent from: support 14 · account 1');
        $ctx->eq($row('ALL', 90), [20, 18, 18, 17, 3, 2, 2, 1, 1, 13, 3, 4, 1], 'K01 90 d: the two older quotations added; the one an hour before the 30-day window is in 90 d only (RP9)');
        $ctx->eq($row('support', 90)[3], 11, 'K01 90 d support: 11 quotations');
    });

    $ctx->run('K02 owners as owner n (F4 order, rules 1–7, a shared name), the headline, still open, the diagnostics', function () use ($ctx, $J) {
        $o = function (string $l, int $d) use ($J): array {
            $r = $J['by_owner'][$l][(string)$d] ?? null;
            return is_array($r) ? [$r['quotes'], $r['kyc'], $r['answ'], $r['chased'], $r['neither'], $r['reg'], $r['paid']] : ['absent'];
        };
        $ctx->eq(array_keys($J['by_owner'] ?? []), ['owner 1', 'owner 2', 'unattributed'], 'K02 owners print as owner 1…n, never as staff ids (RP15)');
        $ctx->eq($o('owner 1', 30), [4, 2, 1, 2, 1, 0, 0],
                 'K02 owner 1: rule 1 (sales_person), rule 1 falling to the submitter when two active accounts share the name, rule 2 (the lead\'s owner), rule 3 (the log)');
        $ctx->eq($o('owner 2', 30), [1, 1, 0, 0, 1, 0, 1], 'K02 owner 2: the F4 order — the application\'s sales_person wins over the lead and the log (RP12)');
        $ctx->eq($o('unattributed', 30), [10, 0, 1, 0, 9, 3, 3], 'K02 unattributed: no rule yields');
        $ctx->eq($J['headline'] ?? null, ['30' => [11, 14], '90' => [13, 16]], 'K02 HEADLINE: neither answered nor chased within 7 days, of the quotations 7 days old');
        $ctx->eq($J['still_open'] ?? null, ['7d' => 1, '30d' => 15], 'K02 STILL OPEN: one under 7 days; 15 under 30');
        $ctx->eq($J['never_wrote_30d'] ?? null, ['n' => 1, 'support' => 1, 'account' => 0], 'K02 NEVER WROTE (F5): one, left from support');
        $ctx->eq($J['diagnostics'] ?? null, ['claimed_no_message' => 1, 'logged_no_message' => 1, 'owner_ambiguous' => 1, 'phone_unparseable' => 0,
                                             'registrations_no_quote' => ['P1' => 1], 'standard_quotations' => 'UNKNOWN'],
                 'K02 DIAGNOSTICS: a claim and a log row with no message, one ambiguous owner, a registration refused (P1); standard quotations UNKNOWN');
    });

    $ctx->run('K03 counts only: no phone, name, amount, staff id or 9-digit run; owners on a mode-0600 sheet', function () use ($ctx, $b, $dir, $text, $err) {
        $digits = (string)preg_replace('/\D+/', '', $text);
        $phone = '';
        foreach ($b['fx']['phones'] as $ph) if (strpos($digits, substr($ph, -9)) !== false) { $phone = $ph; break; }
        $name = '';
        foreach ($b['fx']['names'] as $n) if (stripos($text, $n) !== false) { $name = $n; break; }
        $ctx->eq([$phone === '' ? 'none' : 'a phone', $name === '' ? 'none' : $name, (bool)preg_match('/\d{9,}/', $text), strpos($text, 'staff #') !== false,
                  (bool)preg_match('/1800000|900000|1,800,000|UGX|USh/', $text), $err],
                 ['none', 'none', false, false, false, ''], 'K03 the output holds no phone, no fixture name, no 9-digit run, no staff id, no amount; nothing on stderr (RP6, RP15)');
        $sheet = $dir . '/owners-sheet.txt';
        [$rc, $out] = sfr_run($ctx, $dir, ['--sheet=' . $sheet]);
        $ctx->eq([$rc, is_file($sheet) ? sprintf('%o', fileperms($sheet) & 0777) : 'absent', (string)@file_get_contents($sheet), strpos($out, 'staff #') !== false],
                 [0, '600', "owner 1 = staff #202\nowner 2 = staff #204\n", false], 'K03 the mapping owner n = staff #id goes to the sheet, mode 0600, never to the output');
        [$rc, $out] = sfr_run($ctx, $dir, ['--sheet=' . $sheet]);
        $ctx->eq([strpos($out, 'the sheet was NOT written') !== false, (string)file_get_contents($sheet)], [true, "owner 1 = staff #202\nowner 2 = staff #204\n"],
                 'K03 an existing sheet is never written over');
        @unlink($sheet);
    });

    $ctx->run('K04 it cannot write (F17): the copy is byte for byte the same, nothing appears beside it; a write fails; an open book is refused', function () use ($ctx, $b) {
        $dir = sfr_folder($ctx, $b);   // a folder no run has read yet: its first reading is the one judged
        $before = sfr_files($dir);
        $m0 = (int)(new \PDO('sqlite:file:' . $dir . '/plugin.sqlite3?immutable=1', null, null, [\PDO::SQLITE_ATTR_OPEN_FLAGS => \PDO::SQLITE_OPEN_READONLY]))
                    ->query('SELECT COUNT(*) FROM _migrations')->fetchColumn();
        [$rc] = sfr_run($ctx, $dir, ['--json']);
        $m1 = (int)(new \PDO('sqlite:file:' . $dir . '/plugin.sqlite3?immutable=1', null, null, [\PDO::SQLITE_ATTR_OPEN_FLAGS => \PDO::SQLITE_OPEN_READONLY]))
                    ->query('SELECT COUNT(*) FROM _migrations')->fetchColumn();
        $ctx->eq([$rc, sfr_files($dir) === $before, $m0 > 80, $m1 === $m0], [0, true, true, true], 'K04 the copy\'s sha256 and the folder unchanged; _migrations unchanged (RP5)');
        // A tool that tries to write on the connection the report opens: refused by SQLite, the copy unchanged.
        $src = (string)file_get_contents($ctx->tree() . '/tools/sales_followup_report.php');
        $at = "\$sfrPdo->exec('BEGIN');\n";
        $wd = $ctx->dir('writer');
        exec('cp -R ' . escapeshellarg($ctx->tree() . '/lib') . ' ' . escapeshellarg($wd . '/lib') . ' && mkdir -p ' . escapeshellarg($wd . '/tools') . ' ' . escapeshellarg($wd . '/data')
             . ' && cp -R ' . escapeshellarg($ctx->tree() . '/profiles') . ' ' . escapeshellarg($wd . '/'));
        file_put_contents($wd . '/tools/sales_followup_report.php', str_replace($at, $at . "\$sfrPdo->exec(\"INSERT INTO wa_conversations (phone, channel) VALUES ('1', 'x')\");\n", $src));
        [$rcw] = sfr_run($ctx, $dir, [], QF_TZ, $wd . '/tools/sales_followup_report.php');
        $ctx->eq([substr_count($src, $at), $rcw !== 0, sfr_files($dir) === $before], [1, true, true], 'K04 a write on the report\'s connection fails (read-only, immutable, query_only) and changes nothing');
        touch($dir . '/plugin.sqlite3-wal');
        [$rco, , $erro] = sfr_run($ctx, $dir);
        @unlink($dir . '/plugin.sqlite3-wal');
        $ctx->eq([$rco, strpos($erro, 'a -wal or -shm file sits beside the book') !== false], [2, true], 'K04 a book with a -wal beside it is refused: an open book, not a copy');
    });

    $ctx->run('K05 the tenant from the copies (R104-18): the live files never decide; anything but Uganda reads nothing (RP14)', function () use ($ctx, $b) {
        $live = $ctx->tree() . '/data/kyc_config.json';
        file_put_contents($live, json_encode(['tenant_profile' => 'south-sudan']));
        $dir = sfr_folder($ctx, $b);
        [$rc, $out] = sfr_run($ctx, $dir);
        $ctx->eq([$rc, strpos($out, "  tenant     uganda (decided by kyc_config.json)\n") !== false, strpos($out, 'BY NUMBER (30 d)') !== false], [0, true, true],
                 'K05 the copies say Uganda, the live data folder South Sudan: Uganda, decided by the copy of kyc_config.json');
        file_put_contents($live, json_encode(['tenant_profile' => 'uganda']));
        $ss = sfr_folder($ctx, $b, ['tenant_profile' => 'south-sudan']);
        $before = sfr_files($ss);
        [$rc, $out] = sfr_run($ctx, $ss);
        $ctx->eq([$rc, explode("\n", trim($out)), sfr_files($ss) === $before],
                 [0, ['== sales follow-up report (READ-ONLY) — 2026-11-02T07:00:00Z ==', '  tenant     south-sudan (decided by kyc_config.json)', '  not this install — nothing read'], true],
                 'K05 the copies say South Sudan, the live folder Uganda: not this install — nothing read');
        @unlink($live);
        $vd = sfr_folder($ctx, $b, [], ['currency_code' => 'UGX']);
        $kc = json_decode((string)file_get_contents($vd . '/kyc_config.json'), true);
        unset($kc['tenant_profile']);
        file_put_contents($vd . '/kyc_config.json', json_encode($kc));
        [$rc, $out] = sfr_run($ctx, $vd);
        $ctx->eq([$rc, strpos($out, "  tenant     uganda (decided by the vault)\n") !== false], [0, true], 'K05 a Uganda vault with no tenant file still resolves Uganda, decided by the vault');
    });

    $ctx->run('K06 an 088-shaped copy (A11-02): "chased by a person" and the headline print numbers, equal to the same book\'s with 089\'s two tables empty', function () use ($ctx, $row) {
        $b9 = sfr_book($ctx, false);
        $d9 = sfr_folder($ctx, $b9);
        [$r9, $j9] = sfr_run($ctx, $d9, ['--json']);
        $p = $b9['pdo'];
        foreach (['_schema_checks', 'quote_tracker', 'wa_auto_sends', 'quote_list_sends', 'quote_followup_state', 'wa_seen_marks'] as $t) $p->exec("DROP TABLE IF EXISTS {$t}");
        $d8 = sfr_folder($ctx, $b9);
        [$r8, $j8] = sfr_run($ctx, $d8, ['--json']);
        [, $t8] = sfr_run($ctx, $d8);
        $A = json_decode($j9, true); $B = json_decode($j8, true);
        $pick = function ($J) { return is_array($J) ? [$J['by_number']['30']['ALL']['per'] ?? 'x', $J['by_number']['30']['ALL']['chased'] ?? 'x', $J['headline'] ?? 'x'] : ['no json']; };
        $ctx->eq([$r9, $r8, $pick($B), is_array($A) && is_array($A['after'] ?? null), $B['after'] ?? null], [0, 0, $pick($A), true, 'UNKNOWN'],
                 'K06 the 088-shaped copy reads both tables through HumanTakeover\'s absent-table rule: the same numbers, only the after-deploy lines UNKNOWN (HT19)',
                 $j8 . "\n" . substr($t8, -400));
        $ctx->eq([$pick($A)[0] >= 1, strpos($t8, 'HEADLINE   quotes neither answered nor chased within 7 days: ') !== false, strpos($t8, 'AFTER 5.18.104 not deployed') !== false],
                 [true, true, true], 'K06 there is a person\'s chase to count; the headline is a number; after-deploy says not deployed');
    });

    $ctx->run('K07 parity: the report\'s counts equal the tracker\'s on the same book', function () use ($ctx) {
        $b = sfr_book($ctx);
        $cfg = $b['cfg'];
        $c = QuoteTracker::run($b['pdo'], $cfg, $b['data'], $b['store'], UtcClock::parse(SFR_AT), time() + 120);
        $T = sfr_rows($b['pdo'], "SELECT quote_key, owner_staff_id, made_by, spoke_conv_id, status FROM quote_tracker
                                  WHERE sent_at >= '2026-10-03 07:00:00' AND sent_at < '2026-11-02 07:00:00' AND status <> 'colleague'");
        [$rc, $json] = sfr_run($ctx, sfr_folder($ctx, $b), ['--json']);
        $J = json_decode($json, true);
        $owners = [];
        foreach ($T as $r) { $k = $r['owner_staff_id'] === null ? 'unattributed' : (string)$r['owner_staff_id']; $owners[$k] = ($owners[$k] ?? 0) + 1; }
        ksort($owners);
        $rep = [];
        foreach ((array)($J['by_owner'] ?? []) as $l => $w) $rep[$l] = $w['30']['quotes'];
        $ctx->eq([$c['inserted'] > 0, $rc, count($T), array_values($owners), count(array_filter($T, function ($r) { return $r['made_by'] === 'kyc'; })),
                  count(array_filter($T, function ($r) { return $r['spoke_conv_id'] === null; }))],
                 [true, 0, $J['by_number']['30']['ALL']['quotes'] ?? -1, [$rep['owner 1'] ?? -1, $rep['owner 2'] ?? -1, $rep['unattributed'] ?? -1],
                  $J['by_number']['30']['ALL']['kyc'] ?? -1, $J['never_wrote_30d']['n'] ?? -1],
                 'K07 quotations, owners (by staff id), from a registration and never wrote: the report\'s equal the tracker\'s rows');
        $P = $J['after']['parity'] ?? [];
        $ctx->eq([$J['after']['tracked'] ?? null, $P['missing'] ?? null, $P['wrong_evidence'] ?? null, ($P['keys'] ?? -1) === ($P['tracked'] ?? -2)], [count(sfr_rows($b['pdo'], 'SELECT quote_key FROM quote_tracker')), 0, 0, true],
                 'K07 after the deploy: the tracker\'s parity as Q1-ON states it — every key tracked, none missing');
    });

    $ctx->run('K08 the windows are UTC: the same counts under any process zone (RP9); the trusted links are FollowUpPolicy\'s', function () use ($ctx, $dir, $json) {
        [$rc, $utc] = sfr_run($ctx, $dir, ['--json'], 'UTC');
        $ctx->eq([$rc, json_decode($utc, true)], [0, json_decode($json, true)], 'K08 under UTC and under the Kampala zone: the same JSON');
        $src = (string)file_get_contents($ctx->tree() . '/tools/sales_followup_report.php');
        preg_match("/const TRUSTED_LINKS = \\[([^\\]]*)\\];/", $src, $m);
        $mine = array_map(function ($s) { return trim($s, " '"); }, explode(',', $m[1] ?? ''));
        $ctx->eq($mine, FollowUpPolicy::TRUSTED_LINKS, 'K08 the report\'s TRUSTED_LINKS are FollowUpPolicy::TRUSTED_LINKS');
    });
}]];

qf_run_sections($ctx, $QF_SECTIONS, $O['sections']);

if ($O['mutants']) {
    $T = 'tools/sales_followup_report.php';
    $E = 'lib/QuoteSendEvidence.php';
    $H = 'lib/HumanTakeover.php';
    $M = [
        ['label' => 'RP1 a reply counted only on the quotation\'s own conversation', 'file' => $T,
         'old' => "            foreach (\$this->q('SELECT body, sent_at FROM wa_messages WHERE conversation_id IN (' . implode(',', \$convs) . \")",
         'new' => "            foreach (\$this->q('SELECT body, sent_at FROM wa_messages WHERE conversation_id IN (' . (int)\$q['conv'] . \")",
         'sections' => 'K', 'cases' => '^K01 ', 'pattern' => '/K01 /'],
        ['label' => 'RP2 an AI reply counted as a chase', 'file' => $T,
         'old' => "        \$per = \$person !== null;",
         'new' => "        \$per = \$person !== null || (\$convs !== [] && (int)\$this->one(\"SELECT COUNT(*) FROM wa_messages WHERE conversation_id IN (\" . implode(',', \$convs) . \") AND direction = 'out' AND role = 'assistant' AND sent_at > ? AND sent_at <= ?\", [\$sent, self::stamp(\$end)]) > 0);",
         'sections' => 'K', 'cases' => '^K01 ', 'pattern' => '/K01 /'],
        ['label' => 'RP3 claims counted as sends', 'file' => $T,
         'old' => "// owners as `owner n`, numbered by staff id; the mapping only to the sheet",
         'new' => "if (\$R->hasTable('wa_sent_quotes')) foreach (\$R->q('SELECT quote_ref, sent_at FROM wa_sent_quotes') as \$sfrC) { \$sfrK = QuoteSendEvidence::key((string)\$sfrC['quote_ref']); if (!isset(\$sfrQuotes[\$sfrK])) \$sfrJudged[] = ['key' => \$sfrK, 't' => UtcClock::parse((string)\$sfrC['sent_at']), 'p' => '', 'number' => SalesFollowupReport::NEVER_WROTE, 'sent_from' => 'support', 'owner' => null, 'kyc' => false, 'answ' => false, 'optout' => false, 'eng' => false, 'per' => false, 'chased' => false, 'reg' => false, 'paid' => false, 'neither' => true]; }\n// owners as `owner n`, numbered by staff id; the mapping only to the sheet",
         'sections' => 'K', 'cases' => '^K01 ', 'pattern' => '/K01 /'],
        ['label' => 'RP4 phones matched on their last nine digits', 'file' => $T,
         'old' => "\"SELECT id FROM wa_conversations WHERE phone = ? AND COALESCE(category, '') <> 'staff'\", [\$p]",
         'new' => "\"SELECT id FROM wa_conversations WHERE substr(phone, -9) = substr(?, -9) AND COALESCE(category, '') <> 'staff'\", [\$p]",
         'sections' => 'K', 'cases' => '^K01 ', 'pattern' => '/K01 /'],
        ['label' => 'RP5 SqliteStore::create used', 'file' => $T,
         'old' => "    \$sfrPdo->exec('PRAGMA query_only = ON');",
         'new' => "    require_once \$sfrRoot . '/lib/StoreInterface.php'; require_once \$sfrRoot . '/lib/JsonStore.php'; require_once \$sfrRoot . '/lib/SqliteStore.php';\n    \$sfrPdo = SqliteStore::create(dirname(\$sfrDb))->getPdo();\n    \$sfrPdo->exec('PRAGMA query_only = ON');",
         'sections' => 'K', 'cases' => '^K04 ', 'pattern' => '/K04 /'],
        ['label' => 'RP6 a phone printed', 'file' => $T,
         'old' => "    \$sfrSay('    ' . str_pad(\$sfrL, 14) . \$sfrF(\$sfrW[30]) . ' | ' . \$sfrF(\$sfrW[90]));",
         'new' => "    \$sfrSay('    ' . str_pad(\$sfrL, 14) . \$sfrF(\$sfrW[30]) . ' | ' . \$sfrF(\$sfrW[90]) . ' ' . (string)(\$sfrJudged[0]['p'] ?? ''));",
         'sections' => 'K', 'cases' => '^K03 ', 'pattern' => '/K03 /'],
        ['label' => 'RP7 quote_cash counted as a quotation', 'file' => $E,
         'old' => "    const EVENTS = ['ops_quote_created', 'ops_quote_wa', 'ops_quote_text', 'quote_kyc', 'quote_lead', 'quote_manual'];",
         'new' => "    const EVENTS = ['ops_quote_created', 'ops_quote_wa', 'ops_quote_text', 'quote_kyc', 'quote_lead', 'quote_manual', 'quote_cash'];",
         'sections' => 'K', 'cases' => '^K01 ', 'pattern' => '/K01 /'],
        ['label' => 'RP8 the never-wrote row merged into support', 'file' => $T,
         'old' => "'number' => \$spoke !== null ? self::number((string)\$spoke['channel']) : self::NEVER_WROTE,",
         'new' => "'number' => \$spoke !== null ? self::number((string)\$spoke['channel']) : 'support',",
         'sections' => 'K', 'cases' => '^K01 ', 'pattern' => '/K01 /'],
        ['label' => 'RP9 local date() windows', 'file' => $T,
         'old' => "    \$sfrFrom = \$sfrNow - \$sfrD * 86400;\n    \$sfrRows = [];",
         'new' => "    \$sfrFrom = strtotime(date('Y-m-d', \$sfrNow - \$sfrD * 86400));\n    \$sfrRows = [];",
         'sections' => 'K', 'cases' => '^K0[18] ', 'pattern' => '/K01 |K08 /'],
        ['label' => 'RP10 the handset detected through json_extract(metadata, \'$.source\')', 'file' => $H,
         'old' => "AND m.agent_name = 'Team' AND m.sent_at > :since{\$ub}{\$notOurs}\", \$args)) {",
         'new' => "AND json_extract(m.metadata, '\$.source') = 'handset' AND m.sent_at > :since{\$ub}{\$notOurs}\", \$args)) {",
         'sections' => 'K', 'cases' => '^K01 ', 'pattern' => '/K01 /'],
        ['label' => 'RP11 DishNet counted as a person (humanRepliedSince\'s reading)', 'file' => $H,
         'old' => "AND m.agent_name = 'Team' AND m.sent_at > :since{\$ub}{\$notOurs}\", \$args)) {",
         'new' => "AND COALESCE(m.agent_name, '') NOT IN ('', 'DishNet AI', 'DishNet Plugin', 'WhatsApp auto-reply') AND m.sent_at > :since{\$ub}{\$notOurs}\", \$args)) {",
         'sections' => 'K', 'cases' => '^K01 ', 'pattern' => '/K01 /'],
        ['label' => 'RP12 the F4 order reversed (the application\'s rule last)',
         'edits' => [[$E, "        if (is_array(\$f['kyc_app'] ?? null)) {\n            \$k = self::kycAgent(\$f['kyc_app'], \$retailerRows);\n            if (\$k['id'] !== null) return \$k;\n        }\n        if (!empty(\$f['lead_log'])",
                      "        if (!empty(\$f['lead_log'])"],
                     [$E, "            if (\$k['id'] !== null) return ['id' => \$k['id'], 'source' => 'kyc_client'];\n        }\n        return ['id' => null, 'source' => 'none'];",
                      "            if (\$k['id'] !== null) return ['id' => \$k['id'], 'source' => 'kyc_client'];\n        }\n        if (is_array(\$f['kyc_app'] ?? null)) {\n            \$k = self::kycAgent(\$f['kyc_app'], \$retailerRows);\n            if (\$k['id'] !== null) return \$k;\n        }\n        return ['id' => null, 'source' => 'none'];"]],
         'sections' => 'K', 'cases' => '^K02 ', 'pattern' => '/K02 /'],
        ['label' => 'RP13 PENDING read as a quotation\'s number', 'file' => $T,
         'old' => "        if (strtoupper(trim(\$number)) === 'PENDING') return null;\n",
         'new' => "",
         'sections' => 'K', 'cases' => '^K0[12] ', 'pattern' => '/K01 |K02 /'],
        ['label' => 'RP14 the tenant read from the live files instead of the copies', 'file' => $T,
         'old' => "\$sfrConfig = PluginConfig::read(\$sfrRoot, \$sfrData);",
         'new' => "\$sfrConfig = PluginConfig::read(\$sfrRoot, \$sfrRoot . '/data');",
         'sections' => 'K', 'cases' => '^K05 ', 'pattern' => '/K0[05] /'],
        ['label' => 'RP15 owner ids printed to the log', 'file' => $T,
         'old' => "return \$id === null ? 'unattributed' : 'owner ' . \$sfrOwnerN[\$id];",
         'new' => "return \$id === null ? 'unattributed' : 'staff #' . \$id;",
         'sections' => 'K', 'cases' => '^K0[23] ', 'pattern' => '/K02 |K03 /'],
        ['label' => 'HT19a the absent-table rule removed (089\'s tables read as present)', 'file' => $H,
         'old' => "        return [in_array('wa_auto_sends', \$names, true), in_array('wa_seen_marks', \$names, true)];",
         'new' => "        return [true, true];",
         'sections' => 'K', 'cases' => '^K06 ', 'pattern' => '/K06 /'],
        ['label' => 'HT19b an absent ledger read otherwise than as empty (every row then reads as ours)', 'file' => $H,
         'old' => "        if (!\$hasLedger) return '';",
         'new' => "        if (!\$hasLedger) return ' AND 0';",
         'sections' => 'K', 'cases' => '^K06 ', 'pattern' => '/K06 /'],
        ['label' => 'K-IMG an Inbox image\'s Kampala stamp compared as UTC', 'file' => $H,
         'old' => "\$args + [':since_local' => self::kampala(\$since)])) {",
         'new' => "\$args + [':since_local' => \$since])) {",
         'sections' => 'K', 'cases' => '^K01 ', 'pattern' => '/K01 /'],
        ['label' => 'K-TRUST a trusted link FollowUpPolicy does not trust', 'file' => $T,
         'old' => "    const TRUSTED_LINKS = ['verified', 'manual', 'ai_identified'];",
         'new' => "    const TRUSTED_LINKS = ['verified', 'manual', 'ai_identified', 'phone_match'];",
         'sections' => 'K', 'cases' => '^K08 ', 'pattern' => '/K08 /'],
        ['label' => 'K-SHEET the owners\' sheet readable by others',
         'edits' => [[$T, "    \$sfrOld = umask(077);", "    \$sfrOld = umask(022);"], [$T, "        @chmod(\$sfrOpt['sheet'], 0600);\n", ""]],
         'sections' => 'K', 'cases' => '^K03 ', 'pattern' => '/K03 /'],
        ['label' => 'K-OPEN an open book (a -wal beside it) read', 'file' => $T,
         'old' => "if (is_file(\$sfrDb . '-wal') || is_file(\$sfrDb . '-shm')) ",
         'new' => "if (false) ",
         'sections' => 'K', 'cases' => '^K04 ', 'pattern' => '/K04 /'],
    ];
    qf_mutants($ctx, __FILE__, $M, $O['only']);
}

printf("\n%d passed, %d failed\n", $ctx->pass, $ctx->fail);
exit($ctx->fail === 0 ? 0 : 1);
