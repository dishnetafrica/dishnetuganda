<?php
declare(strict_types=1);
/**
 * Shared by tests/test_master_104.php (MS), tests/test_quote_followup.php L, tests/test_voice_south_sudan.php D and
 * tests/test_wa_offers.php I and J (5.18.104, root docs/73 §H.2.13; ruling R1: one guard, MS8, which the others read).
 * TEST ONLY. Ruling R1's four lines; a judged tree's cron/master.php run in a copy whose every job script is a stub; and
 * tools/cron_status.php run on a data directory of its own. Needs tests/quote_followup/_lib.php (QfCtx, qf_copy_tree).
 */
/** Ruling R1's four lines, byte for byte (root docs/73 §R R1, §H.2.13.1). */
const MS_LINES = [
    "    'quote_chase'      => ['interval' => 540, 'uganda' => true, 'flag' => 'quote_tracking_enabled',   'script' => __DIR__ . '/quote_chase.php'],",
    "    'quote_daily_list' => ['interval' => 240, 'uganda' => true, 'flag' => 'quote_daily_list_enabled', 'script' => __DIR__ . '/quote_daily_list.php'],",
    "    'ai_media'         => ['interval' => 60,  'uganda' => true, 'flag' => 'ai_media_enabled',         'script' => dirname(__DIR__) . '/run_media_worker.php'],",
    "    'offers_send'      => ['interval' => 240, 'uganda' => true, 'flag' => 'offers_live_enabled',      'script' => __DIR__ . '/offers_send.php'],",
];
const MS_JOBS  = ['quote_chase', 'quote_daily_list', 'ai_media', 'offers_send'];
const MS_FLAGS = ['quote_chase' => 'quote_tracking_enabled', 'quote_daily_list' => 'quote_daily_list_enabled',
                  'ai_media' => 'ai_media_enabled', 'offers_send' => 'offers_live_enabled'];
const MS_SCRIPTS = ['quote_chase' => 'cron/quote_chase.php', 'quote_daily_list' => 'cron/quote_daily_list.php',
                    'ai_media' => 'run_media_worker.php', 'offers_send' => 'cron/offers_send.php'];

/** The job registrations of a master.php, line by line, comments skipped (tools/cron_status.php's reading): name => line. */
function ms_job_lines(string $src): array
{
    $out = [];
    foreach (explode("\n", $src) as $line) {
        $t = ltrim($line);
        if ($t === '' || $t[0] === '#' || strpos($t, '//') === 0 || strpos($t, '*') === 0) continue;
        if (preg_match("/^\s*'([a-z0-9_]+)'\s*=>\s*\['interval'/", $line, $m)) $out[$m[1]] = $line;
    }
    return $out;
}

/**
 * A copy of $tree whose every job script master registers is a stub that appends its own path to $MS_RAN_FILE; the copy's
 * cron/master.php is the tree's own, untouched. The copy's ucrm.json names $data. Returns the copy's root.
 */
function ms_stub_tree(QfCtx $ctx, string $tree, string $tag): string
{
    $d = $ctx->dir($tag);
    qf_copy_tree($tree, $d);
    foreach (ms_job_lines((string)file_get_contents($d . '/cron/master.php')) as $line) {
        if (!preg_match("/'script'\s*=>\s*(__DIR__|dirname\(__DIR__\))\s*\.\s*'([^']+)'/", $line, $m)) continue;
        $rel = ($m[1] === '__DIR__' ? 'cron' : '') . $m[2];
        $rel = ltrim($rel, '/');
        @mkdir(dirname($d . '/' . $rel), 0700, true);
        file_put_contents($d . '/' . $rel, '<?php file_put_contents((string)getenv(\'MS_RAN_FILE\'), ' . var_export($rel, true) . " . \"\\n\", FILE_APPEND);\n");
    }
    return $d;
}

/** Run PHP code against $tree's store classes in a process of its own (two trees' classes never meet in one): stdout. */
function ms_php(string $tree, string $code): string
{
    $pre = 'require ' . var_export($tree . '/lib/StoreInterface.php', true) . '; require ' . var_export($tree . '/lib/JsonStore.php', true)
         . '; require ' . var_export($tree . '/lib/SqliteStore.php', true) . '; ';
    $out = [];
    exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($pre . $code) . ' 2>&1', $out);
    return implode("\n", $out);
}

/** A fresh data directory whose store and kyc_config.json hold $cfg (and, when given, master's record $sched). */
function ms_data(QfCtx $ctx, string $tree, array $cfg, ?array $sched = null): string
{
    $data = $ctx->dir('msdata');
    ms_php($tree, '$s = SqliteStore::create(' . var_export($data, true) . '); $s->save("kyc_config.json", ' . var_export($cfg, true) . ');'
        . ($sched === null ? '' : ' $s->save("master_schedule.json", ' . var_export($sched, true) . ');'));
    // after the store's first boot, which moves every JSON file of the directory into the book: what PluginConfig reads
    file_put_contents($data . '/kyc_config.json', json_encode($cfg));
    return $data;
}

/** Run a stub tree's cron/master.php once on a fresh book with $cfg: [ran scripts, schedule job names, rc, stdout]. */
function ms_master_run(QfCtx $ctx, string $stub, array $cfg): array
{
    $data = ms_data($ctx, $stub, $cfg);
    file_put_contents($stub . '/ucrm.json', json_encode(['pluginDataDir' => $data]));
    $ran = $data . '/ran.txt';
    $out = [];
    exec('cd ' . escapeshellarg($stub) . ' && MS_RAN_FILE=' . escapeshellarg($ran) . ' DN_DATA_DIR=' . escapeshellarg($data)
        . ' DN_VAULT_FILE=' . escapeshellarg($data . '/no-vault.json') . ' ' . escapeshellarg(PHP_BINARY) . ' cron/master.php 2>&1', $out, $rc);
    $sched = array_keys((array)json_decode(ms_php($stub, 'echo json_encode(SqliteStore::create(' . var_export($data, true) . ')->load("master_schedule.json") ?? []);'), true));
    sort($sched);
    $r = array_values(array_filter(explode("\n", (string)@file_get_contents($ran))));
    return [$r, $sched, $rc, implode("\n", $out)];
}

/** The configurations of section B: Uganda or South Sudan, with the given flags. */
function ms_cfg(string $tenant, array $flags = []): array
{
    $c = $tenant === 'uganda' ? ['tenant_profile' => 'uganda', 'timezone' => QF_TZ] : ['tenant_profile' => 'south-sudan'];
    // master's other jobs are stubs, so nothing here is ever contacted; a host name that cannot resolve, all the same
    return $c + ['evo_api_url' => 'http://127.0.0.1:9', 'crm_base_url' => 'http://127.0.0.1:9', 'dry_run_mode' => true] + $flags;
}

/** tools/cron_status.php of $tree on a data directory holding $cfg and master's record: [rc, stdout]. */
function ms_status(QfCtx $ctx, string $tree, array $cfg, array $args = [], ?string $data = null): array
{
    if ($data === null) {
        $data = ms_data($ctx, $tree, $cfg, ['starlink_alive' => ['last_run' => time() - 60, 'last_run_at' => date('Y-m-d H:i:s', time() - 60), 'duration_ms' => 5]]);
    }
    $out = [];
    exec('cd ' . escapeshellarg($tree) . ' && DN_DATA_DIR=' . escapeshellarg($data) . ' DN_VAULT_FILE=' . escapeshellarg($data . '/no-vault.json')
        . ' ' . escapeshellarg(PHP_BINARY) . ' tools/cron_status.php ' . implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1', $out, $rc);
    return [$rc, implode("\n", $out), $data];
}

/** The rows of a cron_status table: name => state ('' when none). */
function ms_rows(string $out): array
{
    $rows = [];
    foreach (explode("\n", $out) as $l) {
        if (preg_match('/^\s+\d+\s+([a-z0-9_]+)\s+\d+s\*?\s+\S+\s+\S+(?: \S+)?\s{2,}(.*)$/', $l, $m)) $rows[$m[1]] = trim($m[2]);
        elseif (preg_match('/^\s+\d+\s+([a-z0-9_]+)\s+\d+s\*?\s/', $l, $m)) $rows[$m[1]] = '';
    }
    return $rows;
}

