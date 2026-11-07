<?php
/**
 * TEST ONLY (5.18.104, root docs/73 §H.2.14 G, H): run one plugin cron script in this process, as cron/master.php
 * includes it, with the variables a test gives it — $_qf_test_now (the run's clock, unix time) and $_m_deadline
 * (master's cycle deadline). The script reads its book through the copy's ucrm.json, which the test aims at the book.
 *
 *   php run_cron.php <path/to/cron.php> '{"_qf_test_now": 1793000000, "_m_deadline": 1793000300}'
 *
 * Two options of the runner's own, never variables the script sees (B2b-fix1; B11-02):
 *   "_qf_as_master": 1  the script is included inside master's try/catch (cron/master.php:384-388): a throwable is caught
 *                       and printed as `qf_master_caught=<class>: <message>`, and this process goes on, as master's does;
 *   "_qf_lock_probe": 1 after the script, IN THIS PROCESS, the quote pass's lock (<DN_DATA_DIR>/quote_send.lock) is tried
 *                       with QuoteChase::passLock(): `qf_lock_probe=free` or `=held`. A lock the script left taken in its
 *                       globals is still held here, where the child's exit would have freed it. The probe's own control,
 *                       printed first and whatever the script left: in a directory of its own, a second passLock() of
 *                       this process must answer null while its first holds the lock (`qf_lock_probe_control=held`), so
 *                       `free` is never the answer of a probe that cannot see a lock.
 *   "_qf_pause_after_config": "<dir>"   5.18.104 (root docs/73 §H.2.14 TL32; B11-02), B9-fix1: the run pauses just after the
 *                       script's own configuration read (`$config  = PluginConfig::load(…)`, found exactly once, else the
 *                       runner refuses): it touches <dir>/read and waits (60 s at most) for <dir>/go — a pass that read its
 *                       configuration before an `off` and reaches its lock after it. The script runs from a copy beside
 *                       itself, the pause its one added line; nothing else of it changes.
 */
$qfVars = json_decode((string)($argv[2] ?? '{}'), true);
$qfAsMaster = false; $qfProbe = false; $qfPause = '';
if (is_array($qfVars)) {
    foreach ($qfVars as $qfK => $qfV) {
        if ($qfK === '_qf_test_now' || $qfK === '_m_deadline') $$qfK = (int)$qfV;
        if ($qfK === '_qf_as_master') $qfAsMaster = !empty($qfV);
        if ($qfK === '_qf_lock_probe') $qfProbe = !empty($qfV);
        if ($qfK === '_qf_pause_after_config') $qfPause = (string)$qfV;   // 5.18.104 (root docs/73 §H.2.14 TL32), B9-fix1
    }
}
unset($qfVars, $qfK, $qfV);
if ($qfPause !== '') {   // 5.18.104 (root docs/73 §H.2.14 TL32), B9-fix1: the paused copy of the script, beside it
    $qfSrc = (string)file_get_contents($argv[1]);
    $qfAnchor = '$config  = PluginConfig::load($pluginRoot, $dataDir);';
    if (substr_count($qfSrc, $qfAnchor) !== 1) { echo "qf_pause_anchor_not_once\n"; exit(9); }
    $qfLine = ' touch(' . var_export($qfPause . '/read', true) . '); for ($qfI = 0; $qfI < 1200 && !is_file(' . var_export($qfPause . '/go', true) . '); $qfI++) usleep(50000);';
    $qfCopy = dirname($argv[1]) . '/.qfpause-' . getmypid() . '-' . basename($argv[1]);
    file_put_contents($qfCopy, str_replace($qfAnchor, $qfAnchor . $qfLine, $qfSrc));
    register_shutdown_function(function () use ($qfCopy) { @unlink($qfCopy); });
    $argv[1] = $qfCopy;
    unset($qfSrc, $qfAnchor, $qfLine, $qfCopy);
}
if ($qfAsMaster) {
    try {
        include $argv[1];
    } catch (\Throwable $qfE) {
        echo 'qf_master_caught=' . get_class($qfE) . ': ' . $qfE->getMessage() . "\n";
        $qfT = [];
        foreach ($qfE->getTrace() as $qfF) $qfT[] = (isset($qfF['class']) ? $qfF['class'] . ($qfF['type'] ?? '::') : '') . ($qfF['function'] ?? '');
        echo 'qf_master_trace=' . implode(',', $qfT) . "\n";   // where it was thrown from, for a test's control
    }
} else {
    require $argv[1];
}
if ($qfProbe) {
    if (!class_exists('QuoteChase', false)) {
        echo "qf_lock_probe=unloaded\n";
    } else {
        // the control, whatever the script left: in a directory of its own, a lock this process holds is seen held by a
        // second passLock() of this same process (flock binds each open, never only the process)
        $qfCtl = sys_get_temp_dir() . '/qf-lock-control-' . getmypid() . '-' . bin2hex(random_bytes(3));
        @mkdir($qfCtl, 0700, true);
        $qfC1 = QuoteChase::passLock($qfCtl);
        $qfC2 = $qfC1 === null ? null : QuoteChase::passLock($qfCtl);
        echo 'qf_lock_probe_control=' . ($qfC1 !== null && $qfC2 === null ? 'held' : 'unseen') . "\n";
        if ($qfC2 !== null) QuoteChase::passUnlock($qfC2);
        if ($qfC1 !== null) QuoteChase::passUnlock($qfC1);
        @unlink($qfCtl . '/' . QuoteChase::LOCK_FILE); @rmdir($qfCtl);
        // the probe: the book's quote pass lock, as master's next job in this process would find it
        $qfL = QuoteChase::passLock((string)getenv('DN_DATA_DIR'));
        echo 'qf_lock_probe=' . ($qfL === null ? 'held' : 'free') . "\n";
        if ($qfL !== null) QuoteChase::passUnlock($qfL);
    }
}
