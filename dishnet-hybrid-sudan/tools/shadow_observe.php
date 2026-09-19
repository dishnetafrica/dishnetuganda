<?php
declare(strict_types=1);
chdir(dirname(__DIR__));

/**
 * shadow_observe.php — run the B3.4 observation window, and read it back.
 *
 * B3.4 compares the controlled customer tools against the legacy support and
 * accounts prompts and logs which disagree. This drives that window:
 *
 *     php tools/shadow_observe.php --begin     snapshot, then switch on
 *     php tools/shadow_observe.php --report    aggregate what has been seen
 *     php tools/shadow_observe.php --end       switch off, restore exactly
 *
 * ── IT READS THE LOG THAT ALREADY EXISTS ────────────────────────────────
 *
 * --report opens <dataDir>/ai_platform.log, counts, and prints. It writes
 * nothing, creates no second copy, and adds no logging of its own: a
 * measurement instrument that starts collecting its own file is how a
 * temporary observation becomes a permanent data store nobody remembers
 * creating.
 *
 * The only file it writes is the SNAPSHOT — the previous value of one config
 * key, so --end can put it back exactly. That is a config record, not
 * observation data, and it holds no customer anything.
 *
 * ── IT CANNOT PRINT A CUSTOMER VALUE ────────────────────────────────────
 *
 * Every shadow line is parsed against the grammar ShadowCompare can emit, and
 * the report is built from the CONSTANTS that grammar is made of — never from
 * the text of the line. A line that does not match is counted as unparseable
 * and is NOT echoed: if something unexpected ever reaches that log, printing
 * it here to find out what it was is the one move that turns a logging bug
 * into a disclosure.
 *
 * Conversation ids are not reported either. They are legitimately in the log,
 * but an aggregate does not need them; what it needs is how MANY distinct
 * conversations a divergence spans, which is what separates a systematic
 * difference from one odd customer.
 *
 * This whole file leaves with B3.5.
 */

require_once __DIR__ . '/../lib/bootstrap_data.php';
require_once __DIR__ . '/../lib/StoreInterface.php';
require_once __DIR__ . '/../lib/SqliteStore.php';
require_once __DIR__ . '/../lib/PluginConfig.php';
require_once __DIR__ . '/../lib/ShadowObservation.php';

const KEY = 'ai_shadow_compare';

$root    = dirname(__DIR__);
// Through the CLI guard, like every other tool. It honours DN_DATA_DIR
// exactly, so this resolves to the SAME directory the worker writes shadow
// lines into — and it refuses getDataDir()'s in-plugin last resort, which is
// what stops a run as the wrong user from reading an empty database and
// reporting "no divergences" as a fact about the business.
$dataDir = cliDataDir($root);
$snapFile = $dataDir . '/shadow_observation.json';
$logFile  = $dataDir . '/ai_platform.log';

$args = array_slice($argv, 1);
$has  = fn(string $f) => in_array($f, $args, true);
$val  = function (string $f) use ($args) {
    $i = array_search($f, $args, true);
    return ($i === false || !isset($args[$i + 1])) ? '' : (string)$args[$i + 1];
};

// ── Where the key stands, in every place it could stand ─────────────────
$state = function () use ($root, $dataDir): array {
    $raw = function (string $p): array {
        if (!is_file($p)) return ['file' => $p, 'exists' => false, 'present' => false, 'value' => null];
        $d = json_decode((string)@file_get_contents($p), true);
        return ['file' => $p, 'exists' => true,
                'present' => is_array($d) && array_key_exists(KEY, $d),
                'value'   => is_array($d) ? ($d[KEY] ?? null) : null];
    };
    $eff = PluginConfig::load($root, $dataDir);
    $store = ['present' => false, 'value' => null];
    try {
        $s = SqliteStore::create($dataDir);
        $k = (array)($s->load('kyc_config.json') ?? []);
        $store = ['present' => array_key_exists(KEY, $k), 'value' => $k[KEY] ?? null];
    } catch (\Throwable $e) { $store['error'] = get_class($e); }

    return [
        'config_json'     => $raw($root . '/data/config.json'),
        'kyc_config_json' => $raw($dataDir . '/kyc_config.json'),
        'store'           => $store,
        'effective'       => ['present' => array_key_exists(KEY, $eff),
                              'value'   => $eff[KEY] ?? null,
                              'on'      => !empty($eff[KEY] ?? null)],
    ];
};

$printState = function (array $s): void {
    printf("      config.json      %s\n", $s['config_json']['present']
        ? 'present = ' . var_export($s['config_json']['value'], true) : 'ABSENT');
    printf("      kyc_config.json  %s\n", $s['kyc_config_json']['present']
        ? 'present = ' . var_export($s['kyc_config_json']['value'], true) : 'ABSENT');
    printf("      store            %s\n", $s['store']['present']
        ? 'present = ' . var_export($s['store']['value'], true) : 'ABSENT');
    printf("      EFFECTIVE        %s\n", $s['effective']['present']
        ? var_export($s['effective']['value'], true) . ' (' . ($s['effective']['on'] ? 'ON' : 'OFF') . ')'
        : 'ABSENT (OFF)');
};

$setKey = function (?string $value) use ($dataDir): array {
    // '' clears the override outright — PluginConfig::saveOverrides unsets the
    // key rather than writing a falsey one. Restoring a key that was ABSENT to
    // 0 would not be the same state, and this is the difference.
    return PluginConfig::saveOverrides($dataDir, [KEY => $value === null ? '' : $value]);
};

// ══════════════════════════════════════════════════════════════════════════
if ($has('--begin')) {
    if (is_file($snapFile)) {
        echo "\n  An observation is already recorded in " . $snapFile . "\n";
        echo "  Run --end to close it before beginning another.\n\n";
        exit(1);
    }
    $before = $state();
    echo "\n  BEFORE\n";
    $printState($before);

    $snap = ['recorded_at' => date('c'), 'key' => KEY, 'before' => $before,
             'log_bytes_at_start' => is_file($logFile) ? filesize($logFile) : 0,
             'log_lines_at_start' => is_file($logFile)
                 ? (int)trim((string)shell_exec('wc -l < ' . escapeshellarg($logFile))) : 0];
    if (@file_put_contents($snapFile, json_encode($snap, JSON_PRETTY_PRINT)) === false) {
        echo "\n  Could not write " . $snapFile . " — nothing was changed.\n\n";
        exit(1);
    }

    [$ok, $err] = $setKey('1');
    if (!$ok) { @unlink($snapFile); echo "\n  Could not enable: " . (string)$err . "\n\n"; exit(1); }

    echo "\n  AFTER\n";
    $printState($state());
    echo "\n  Snapshot written to " . $snapFile . "\n";
    echo "  Shadow lines will appear in " . $logFile . "\n";
    echo "  Close the window with:  php tools/shadow_observe.php --end\n\n";
    exit(0);
}

// ══════════════════════════════════════════════════════════════════════════
if ($has('--end')) {
    if (!is_file($snapFile)) {
        echo "\n  No snapshot at " . $snapFile . " — nothing to restore from.\n";
        echo "  Switch off by hand if needed:\n";
        echo "      php tools/set_config.php --key " . KEY . " --clear\n\n";
        exit(1);
    }
    $snap = json_decode((string)file_get_contents($snapFile), true);
    if (!is_array($snap) || !isset($snap['before'])) {
        echo "\n  " . $snapFile . " is not readable as a snapshot. Nothing changed.\n\n";
        exit(1);
    }
    $before = $snap['before'];
    $wasPresent = !empty($before['kyc_config_json']['present']);
    $wasValue   = $before['kyc_config_json']['value'] ?? null;

    echo "\n  RESTORING to the state recorded at " . (string)($snap['recorded_at'] ?? '?') . "\n";
    [$ok, $err] = $setKey($wasPresent ? (string)$wasValue : null);
    if (!$ok) { echo "\n  Could not restore: " . (string)$err . "\n\n"; exit(1); }

    $now = $state();
    echo "\n  NOW\n";
    $printState($now);

    $same = ($now['kyc_config_json']['present'] === (bool)$wasPresent)
         && ((string)($now['kyc_config_json']['value'] ?? '') === (string)($wasValue ?? ''))
         && ($now['effective']['on'] === !empty($before['effective']['on']));
    echo "\n  " . ($same ? 'Restored exactly.' : 'DOES NOT MATCH the snapshot — check by hand.') . "\n";
    if (is_file($logFile)) {
        printf("  Log grew %d bytes during the window.\n",
               filesize($logFile) - (int)($snap['log_bytes_at_start'] ?? 0));
    }
    echo "  The snapshot is left in place as the record of the window.\n\n";
    exit($same ? 0 : 1);
}

// ══════════════════════════════════════════════════════════════════════════
if ($has('--status')) {
    echo "\n  " . KEY . "\n";
    $printState($state());
    echo "\n  snapshot  " . (is_file($snapFile) ? $snapFile : 'none') . "\n";
    echo "  log       " . (is_file($logFile)
        ? $logFile . ' (' . number_format((float)filesize($logFile)) . ' bytes)' : 'not created yet') . "\n\n";
    exit(0);
}

// ══════════════════════════════════════════════════════════════════════════
if ($has('--report')) {
    if (!is_file($logFile)) { echo "\n  No log at " . $logFile . "\n\n"; exit(1); }
    $since = trim($val('--since'));
    $snap  = is_file($snapFile) ? json_decode((string)file_get_contents($snapFile), true) : null;

    $r = ShadowObservation::aggregate($logFile, $since);
    echo ShadowObservation::render($r, $logFile, $since, is_array($snap) ? $snap : null);
    exit(0);
}

echo "\n  php tools/shadow_observe.php --begin            snapshot, then switch on\n";
echo "  php tools/shadow_observe.php --report [--since 'YYYY-MM-DD HH:MM:SS']\n";
echo "  php tools/shadow_observe.php --end              switch off, restore exactly\n";
echo "  php tools/shadow_observe.php --status           where things stand\n\n";
exit(0);
