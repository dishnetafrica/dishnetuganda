<?php
declare(strict_types=1);
/**
 * Section I — the job, cron/offers_send.php, as cron/master.php includes it (5.18.104, root docs/73 §H.4.10, §H.4.13,
 * §H.4.16 I; ruling R1). TEST ONLY.
 *
 * The job file run in a process of its own, included from a scope that holds master's $_m_deadline, on a copy of the tree
 * whose ucrm.json names the book: off, it returns before any store is opened; master's deadline reaches the run only as
 * the closure's argument (with under 20 s left the run does its stale sweep and live-since guard and nothing past them);
 * with no deadline the run goes on to its later steps. The four lines of cron/master.php and the flag rule are stage B10's
 * (master's one coordinated change); P-O1-6 and MS8 read them there.
 *
 * 5.18.104 (root docs/73 §H.4.13, §H.4.16 I; ruling R1), stage B10: I03 reads ruling R1's four lines — offers_send once,
 * last, interval 240, 'uganda' => true, 'flag' => 'offers_live_enabled' — and I04 reads Part F's MS8
 * (tests/quote_followup/master_lib.php): with the flag off no dispatch and no schedule row, and on Uganda
 * tools/cron_status.php lists it OFF (switch).
 */
require_once dirname(__DIR__) . '/quote_followup/master_lib.php';   // 5.18.104 (root docs/73 §H.4.16 I): a read of MS8

/** A copy of the judged tree for the job's own process (ucrm.json is the copy's, so nothing else is disturbed). */
function wo_itree(QfCtx $ctx): string
{
    static $t = null;
    if ($t === null) { $t = $ctx->dir('itree'); qf_copy_tree($ctx->root, $t); }
    return $t;
}

/** Run cron/offers_send.php in its own process on $data with $cfg; $deadline a PHP expression for $_m_deadline, or null. [stdout, rc]. */
function wo_cron(QfCtx $ctx, string $data, array $cfg, ?string $deadline): array
{
    $tree = wo_itree($ctx);
    file_put_contents($tree . '/ucrm.json', json_encode(['pluginDataDir' => $data]));
    file_put_contents($data . '/kyc_config.json', json_encode($cfg));
    $code = ($deadline === null ? '' : '$_m_deadline = ' . $deadline . '; ') . 'include ' . var_export($tree . '/cron/offers_send.php', true) . ';';
    $out = [];
    $env = 'DN_VAULT_FILE=' . escapeshellarg((string)getenv('DN_VAULT_FILE')) . ' DN_DATA_DIR= ';
    exec($env . 'php -d display_errors=stderr -r ' . escapeshellarg($code) . ' 2>/dev/null', $out, $rc);
    return [implode("\n", $out), $rc];
}

$WO_SECTIONS['I'] = ['title' => 'The job: cron/offers_send.php and master\'s deadline (§H.4.10, §H.4.13)', 'run' => function (QfCtx $ctx) {
    $ctx->run('I01 off: the job returns before any store is opened, and prints nothing', function () use ($ctx) {
        $d = $ctx->dir('i-off');
        [$out, $rc] = wo_cron($ctx, $d, wo_cfg(['offers_live_enabled' => null]), 'time() + 200');
        $ctx->eq([$rc, $out, is_file($d . '/plugin.sqlite3')], [0, '', false], 'I01 offers_live_enabled unset: exit 0, no output, no book created');
        $d = $ctx->dir('i-off2');
        [$out, $rc] = wo_cron($ctx, $d, wo_cfg(['offers_live_since' => null]), null);
        $ctx->eq([$rc, $out, is_file($d . '/plugin.sqlite3')], [0, '', false], 'I01 offers_live_since missing: the same (live() is false)');
    });

    $ctx->run('I02 master\'s deadline reaches the run through the closure argument: 10 s left, only the sweep and the guard', function () use ($ctx) {
        $now = time();
        $plant = function () use ($ctx, $now): array {
            $b = wo_book($ctx, wo_cfg(['offers_live_since' => UtcClock::stamp($now - 20 * 86400)]), 'i');
            $gw = new WoGateway(new WoLog());
            wo_warm($b, '256700920201', $now, 3600);
            wo_warm($b, '256700920202', $now, 3600);
            [$id, $r] = wo_started($b, $now - 15 * 86400, $gw);                       // started 15 days ago: past offers_max_days (14)
            $b['pdo']->prepare("UPDATE wa_offer_recipients SET state = 'claimed', claim_token = 'x', claimed_at = ?, attempt = 1 WHERE phone = '256700920201'")
                     ->execute([UtcClock::stamp($now - 1000)]);
            $b['pdo']->exec('PRAGMA wal_checkpoint(TRUNCATE)');
            return [$b, $id, $r['ok']];
        };
        [$b, $id, $ok] = $plant();
        $ctx->t($ok, 'I02 control: an offer started, past its lifetime, with a stale claim');
        [$out, $rc] = wo_cron($ctx, $b['data'], $b['cfg'], 'time() + 10');
        $o = OfferStore::find($b['pdo'], $id);
        $ctx->eq([$rc, wo_row($b['pdo'], '256700920201')['state'], $o['state']], [0, 'queued', 'running'],
                 'I02 $_m_deadline 10 s away: the stale claim swept, and nothing past the guard (the expired offer still running)');
        [$b, $id, $ok] = $plant();
        [$out, $rc] = wo_cron($ctx, $b['data'], $b['cfg'], null);
        $o = OfferStore::find($b['pdo'], $id);
        $ctx->eq([$rc, $o['state'], $o['close_reason']], [0, 'done', 'expired'], 'I02 control: no deadline, the run goes on and expires the offer');
        $ctx->t((bool)preg_match('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d \[offers_send\] sent=\d+ uncertain=\d+ failed=\d+ skipped=\d+ deferred=\d+ handsets=\d+ code=[a-z_]*$/', trim($out)) || trim($out) === '',
                'I02 the run line, when there is one, is counts and a fixed code only');
    });

    // 5.18.104 (root docs/73 §H.4.13; ruling R1): master's line, read as text, as MS reads it
    $ctx->run('I03 cron/master.php: offers_send once, last of ruling R1\'s four lines, byte for byte', function () use ($ctx) {
        $jobs = ms_job_lines((string)@file_get_contents($ctx->root . '/cron/master.php'));
        $ctx->t(count($jobs) > 30, 'I03 control: master\'s job list reads (' . count($jobs) . ' jobs)');
        $names = array_keys($jobs);
        $ctx->eq([end($names), $jobs['offers_send'] ?? null, array_slice($names, -4)], ['offers_send', MS_LINES[3], MS_JOBS],
                 'I03 offers_send is the last job, its line ruling R1\'s (240, uganda, offers_live_enabled), after the other three');
        $ctx->eq(substr_count((string)file_get_contents($ctx->root . '/cron/master.php'), "'offers_send'"), 1, 'I03 … registered once');
    });

    // 5.18.104 (root docs/73 §H.4.16 I; ruling R1): a read of MS8
    $ctx->run('I04 the flag off: master neither runs offers_send nor writes its row; cron_status lists it OFF (switch) on Uganda', function () use ($ctx) {
        $stub = ms_stub_tree($ctx, $ctx->root, 'wo-ms');
        [$ran, $sched, $rc] = ms_master_run($ctx, $stub, ms_cfg('uganda', ['offers_test_enabled' => '1']));
        $ctx->t($rc === 0 && count($ran) > 20, 'I04 control: master runs its other jobs (' . count($ran) . ')');
        $ctx->eq([in_array(MS_SCRIPTS['offers_send'], $ran, true), in_array('offers_send', $sched, true)], [false, false],
                 'I04 offers_live_enabled unset (offers_test_enabled on): no run, no schedule row');
        [$ran2, $sched2] = ms_master_run($ctx, $stub, ms_cfg('uganda', ['offers_live_enabled' => '1']));
        $ctx->eq([in_array(MS_SCRIPTS['offers_send'], $ran2, true), in_array('offers_send', $sched2, true)], [true, true], 'I04 control: with it on, it runs and has its row');
        [, $out] = ms_status($ctx, $ctx->root, ms_cfg('uganda'), ['--all']);
        $ctx->eq(ms_rows($out)['offers_send'] ?? null, 'OFF (switch)', 'I04 cron_status on Uganda: offers_send OFF (switch)');
    });
}];
