<?php
declare(strict_types=1);
/**
 * TEST ONLY — 5.18.105 (root docs/74 §H.3.2, §H.1.24): the 5.18.105 layer, one mechanism for every older boundary.
 *
 * 5.18.104's boundaries judge the tree against 221c9dc, and the older ones against older commits. To them a 5.18.105
 * hunk is an unmarked change and a 5.18.105 file is undeclared. So 5.18.105 is taken out first, against 994d660
 * (5.18.104's release commit, from this checkout's Git, never from a working tree), and each older test then judges
 * what is left, unchanged: the role tests/fixtures/r104_revert.php plays for r103_revert.php, made general. Every older
 * boundary therefore judges 994d660's tree exactly as it does today; 5.18.105's own hunks are judged once, by
 * tests/test_log_mask.php D.
 *
 *   R105_DECLARED        every file 5.18.105 changes or adds, each with its kind and its reason; a path ending in '/' is a
 *                        directory 5.18.105 adds whole. R105_HELD: what 5.18.105 leaves byte for byte.
 *   R105_MARK            the marker, "5.18.105 … root docs/74 §" within one line.
 *   r105_hunks()         tests/quote_followup/declared.php's qf_n_hunks against 994d660 and R105_MARK: a hunk is
 *                        5.18.105's when the marker stands in its own added lines or in the 30 lines above it, or when it
 *                        only closes a block. r105_revert_file(): qf_n_revert_file, the same way.
 *   r105_revert_tree()   the judged tree with 5.18.105's new files removed, its marked hunks put back to 994d660's lines and
 *                        manifest.json's version put back from 5.18.105 to 5.18.104 (only where that line occurs exactly
 *                        once); the count of unmarked hunks it left. The older boundaries then take 5.18.104 out the same
 *                        way (qf_n_revert_tree, 5.18.104 → 5.18.103), so the version revert reaches 5.18.103 through
 *                        5.18.104.
 *   r105_less()          that tree for a judged root, cached per process: the key is the root's real path plus the
 *                        sha256 of every R105_DECLARED file in it, re-checked at every call, the tree rebuilt on any
 *                        difference. It is built only from the judged root it is given, never from a tree a control
 *                        plants into (gap 8: TL14's plant controls keep reading their own copy). r105_file(): one file
 *                        of it. A checkout whose Git cannot supply 994d660 (at 5.18.104) is a failure of the test that
 *                        asks — r105_less() throws — never a skip.
 *   r105_planted_outside() a weakened copy's own check (§H.1.0): the edit it plants stands in a hunk the layer leaves in
 *                        place — unmarked, not one that only closes a block — at least 31 lines from every 5.18.105
 *                        marker in the planted file. A copy that fails it is not planted, and is a failure of its test.
 *   r105_revert()        for the tests that hold includes/post/post_cashbook.php as text (§H.1.24): 994d660's text,
 *                        recovered by exact text — LN-35's marker line removed and its logged line put back, each found
 *                        exactly once, or null; any other file comes back as given. Applied first:
 *                        r103_revert($rel, r104_revert($rel, r105_revert($rel, $src))). Being exact, it leaves every
 *                        other edit in place wherever it stands. Generated from Git and checked there: 994d660's bytes
 *                        come back exactly (tests/test_bank_boundaries.php A).
 *
 * The older boundaries read 5.18.105 out through this file only, and tests/log_mask/declared.php (the 5.18.105
 * boundary, tests/test_log_mask.php D) loads its list and marker from here: one list, never two. Every person, number and
 * message in these tests is fictitious.
 */

const R105_BASE      = '994d660';
const R105_BASE_FULL = '994d660628cc5b1b42bee648b44b58e389f44bf2';
/** The marker, as a pattern over one line. */
const R105_MARK = '#5\.18\.105[^\n]{0,40}root docs/74 §#u';
/** What a release does not ship and a weakened copy does not carry (QF_N_SKIP, tests/quote_followup/declared.php). */
const R105_SKIP = ['docs', 'prototype', 'dishnet-mikrotik-control-plane', 'data', '.git', 'scripts'];

/** Every file 5.18.105 changes or adds: path => [kind, reason]. */
const R105_DECLARED = [
    // ── the 19 log files (§H.1.1–§H.1.19): LN-01 … LN-35, 35 edits covering 37 log statements ─────────────────────────
    'evo_webhook.php'                   => ['changed', '§H.1.1 LN-01'],
    'cron/staff_ssp_report.php'         => ['changed', '§H.1.2 LN-02'],
    'lib/QuotationService.php'          => ['changed', '§H.1.3 LN-03'],
    'lib/WaAutoReplyService.php'        => ['changed', '§H.1.4 LN-04'],
    'cron/bidal_summary.php'            => ['changed', '§H.1.5 LN-05'],
    'cron/cashbook_reconcile.php'       => ['changed', '§H.1.6 LN-06'],
    'cron/cash_carry_reminder.php'      => ['changed', '§H.1.7 LN-07'],
    'cron/wa_watchdog.php'              => ['changed', '§H.1.8 LN-08'],
    'cron_invoice_notify.php'           => ['changed', '§H.1.9 LN-09, LN-10'],
    'cron_quote_wa.php'                 => ['changed', '§H.1.10 LN-11 … LN-15'],
    'webhook.php'                       => ['changed', '§H.1.11 LN-16 … LN-23, and the loader after :129'],
    'cron_evo_sync.php'                 => ['changed', '§H.1.12 LN-24 (evoLog, masked centrally)'],
    'cron_wa_sync.php'                  => ['changed', '§H.1.13 LN-25'],
    'cron_overdue_email.php'            => ['changed', '§H.1.14 LN-26, LN-27'],
    'cron_leads.php'                    => ['changed', '§H.1.15 LN-28, LN-29'],
    'cron_lead_alerts.php'              => ['changed', '§H.1.16 LN-30 … LN-32'],
    'includes/notify_actions.php'       => ['changed', '§H.1.17 LN-33'],
    'includes/post/post_kyc.php'        => ['changed', '§H.1.18 LN-34 (review G-1)'],
    'includes/post/post_cashbook.php'   => ['changed', '§H.1.19 LN-35 (review G-1)'],
    // ── the helper, the report, the version, the record ────────────────────────────────────────────────────────────
    'lib/PluginLog.php'                 => ['changed', '§H.1.22 PluginLog::tail()'],
    'tools/sales_followup_report.php'   => ['changed', '§H.1.23 the BY NUMBER columns, padded by characters'],
    'manifest.json'                     => ['changed', '§H.3.1 the version line, 5.18.104 → 5.18.105'],
    'SAFETY.md'                         => ['changed', '§H.3.1 the 5.18.105 section of the VERSION LOG'],
    // ── 5.18.105's own tests (§H.3.4) and this layer (§H.3.2, §H.1.24) ───────────────────────────────────────────────
    'tests/test_log_mask.php'           => ['new', '§H.3.4'],
    'tests/log_mask/'                   => ['new', '§H.3.4 (its sections, the two new fakes, section F)'],
    'tests/fixtures/r105_revert.php'    => ['new', '§H.3.2, §H.1.24 (the layer)'],
    // ── the older boundaries, re-pointed through the layer (§H.3.2, §H.1.24), never weakened ───────────────────────────
    'tests/quote_followup/N_boundary.php'   => ['changed', '§H.3.2 quote_followup N (r105_less, N00, and N09 the control on the controls)'],
    'tests/quote_followup/M_logging.php'    => ['changed', '§H.3.2 quote_followup M01 (the walk over r105_less)'],
    'tests/quote_followup/mutants_N.php'    => ['changed', '§H.3.4 through the layer: an unmarked edit to cron_quote_wa.php fails N03; §H.1.0 QN8 moved to test_quote_company\'s I3, outside every marker\'s window'],
    'tests/quote_followup/_lib.php'         => ['changed', '§H.1.0 a weakened copy planted for the layer is asserted outside every marker\'s window'],
    'tests/quote_agent_copy/J.php'          => ['changed', '§H.3.2 quote_agent_copy J (r105_less; J3 still counts twelve hunks)'],
    'tests/catalogue_direct/I_boundary.php' => ['changed', '§H.3.2 catalogue_direct I0 (r105_less, then qf_n_revert_tree)'],
    'tests/catalogue_direct/mutants_I.php'  => ['changed', '§H.3.4 through the layer: an unmarked edit to cron_quote_wa.php fails CD I2'],
    'tests/test_catalogue_direct.php'       => ['changed', '§H.1.0 a weakened copy planted for the layer is asserted outside every marker\'s window'],
    'tests/quote_from_ucrm/I_boundary.php'  => ['changed', '§H.3.2 quote_from_ucrm I0 (r105_less, then qf_n_revert_tree)'],
    'tests/quote_from_ucrm/mutants_I.php'   => ['changed', '§H.1.0 MI5 and MI7 re-anchored outside LN-03\'s marker window (the same fault each)'],
    'tests/test_quote_from_ucrm.php'        => ['changed', '§H.1.0 a weakened copy planted for the layer is asserted outside every marker\'s window'],
    'tests/sales_followup_tool/TL_tool.php' => ['changed', '§H.3.2 TL14: the main scan and Git\'s set read r105_less; the plant controls keep the copy (gap 8); §S.3 TL40: the control judged at a fixed moment, not time()'],
    'tests/test_quote_company.php'          => ['changed', '§H.3.2 I4 (the files outside QC_I4_102 read in r105_less, gap 2), §H.3.3 I5\'s version pin'],
    'tests/test_quote_company_mutants.php'  => ['changed', '§H.3.4 through the layer: an unmarked edit to cron_quote_wa.php fails I4'],
    'tests/test_quote_summary.php'          => ['changed', '§H.1.24 M: webhook.php read through r105_file; its control planted raw, outside every marker\'s window, and read through r105_revert_file (found by the suite, §S.3)'],
    'tests/test_bank_boundaries.php'        => ['changed', '§H.1.24 A and F through r105_revert (gap 1), §H.3.3 the version pin and its weakened copy'],
    'tests/test_cashbook_physical_list.php' => ['changed', '§H.1.24 M reads post_cashbook.php through r105_revert, §H.3.3 the version pin'],
    'tests/test_cashbook_physical_list_mutants.php' => ['changed', '§H.3.4 through the layer: an unmarked edit to post_cashbook.php fails M'],
    'tests/test_distributor_apply.php'      => ['changed', '§H.3.3 the version pin'],
    'tests/test_distributor_link_ucrm.php'  => ['changed', '§H.3.3 the version pin'],
    'tests/test_distributor_notify.php'     => ['changed', '§H.3.3 the version pin'],
    'tests/test_distributor_registry.php'   => ['changed', '§H.3.3 the version pin'],
    'tests/test_distributor_territory.php'  => ['changed', '§H.3.3 the version pin'],
];

/** What 5.18.105 leaves byte for byte (§H.3.2). lib/PluginLog.php is not held: it gains tail(). */
const R105_HELD = [
    'tools/sales_followup.php', 'lib/SalesFollowupLadder.php', 'lib/SalesFollowupChecks.php',
    // the report wrapper's five
    'lib/PhoneNumber.php', 'lib/TenantProfile.php', 'lib/PluginConfig.php', 'lib/ConfigVault.php', 'lib/ContactOptOut.php',
    'cron/master.php', 'cron/event_processor.php', 'lib/NotificationService.php', 'lib/AlertService.php', 'workers/StarlinkMailWorker.php',
    'migrations/',
];

/**
 * LN-35 (§H.1.19), the one line 5.18.105 changes in a file older tests hold as text: [5.18.105's text, 994d660's text], each
 * found exactly once. Its hunk both adds its marker on the line above and replaces the logged line, so both go back.
 */
const R105_REVERT = [
    'includes/post/post_cashbook.php' => [
        [<<<'R105'
                                // 5.18.105 (root docs/74 §H.1.19, LN-35): the name and the amount, without the number.
R105
        . "\n", ''],
        [<<<'R105'
                                    "Refund WA sent to {$refundName}: \${$_refAmt}", '');
R105
        . "\n", <<<'R105'
                                    "Refund WA sent to {$refundName} ({$refundPhone}): \${$_refAmt}", '');
R105
        . "\n"],
    ],
];

/** 994d660's text of $rel from this release's $src; $src itself for any other file; null when a 5.18.105 line is not found exactly once. */
function r105_revert(string $rel, ?string $src): ?string
{
    if ($src === null) return null;
    if (!isset(R105_REVERT[$rel])) return $src;
    foreach (R105_REVERT[$rel] as [$new, $old]) {
        if (substr_count($src, $new) !== 1) return null;
        $src = str_replace($new, $old, $src, $made);
        if ($made !== 1) return null;
    }
    return $src;
}

/** Is $rel declared — by its own entry, or under a declared new directory? The entry's path, or null. */
function r105_declared_entry(string $rel): ?string
{
    if (isset(R105_DECLARED[$rel])) return $rel;
    foreach (R105_DECLARED as $path => $row) {
        if (substr($path, -1) === '/' && strpos($rel, $path) === 0) return $path;
    }
    return null;
}

/** A plugin tree's files, rel => absolute, the skipped top-level directories left out. */
function r105_walk(string $tree): array
{
    $out = [];
    if (!is_dir($tree)) return $out;
    $it = new \RecursiveIteratorIterator(new \RecursiveCallbackFilterIterator(
        new \RecursiveDirectoryIterator($tree, \FilesystemIterator::SKIP_DOTS),
        function ($f) use ($tree) { return !in_array(explode('/', substr($f->getPathname(), strlen($tree) + 1))[0], R105_SKIP, true); }));
    foreach ($it as $f) if ($f->isFile()) $out[substr($f->getPathname(), strlen($tree) + 1)] = $f->getPathname();
    ksort($out);
    return $out;
}

/**
 * 994d660's plugin tree, from this checkout's Git, into $dir (made once per process; '' picks a folder under the
 * process's temporary folder): [dir|null, reason]. Its manifest must read 5.18.104. Never from a working tree. A checkout
 * that cannot supply it is a failure of the test that asks, never a skip.
 */
function r105_base(string $dir = ''): array
{
    static $got = null;
    if ($got !== null && is_dir((string)$got[0])) return $got;
    if ($dir === '') {   // a folder of its own, removed when the process ends (a caller's folder is the caller's to remove)
        $dir = rtrim(sys_get_temp_dir(), '/') . '/r105-base-' . getmypid() . '-' . bin2hex(random_bytes(3));
        register_shutdown_function(function () use ($dir) { exec('rm -rf ' . escapeshellarg($dir) . ' ' . escapeshellarg($dir . '.tar')); });
    }
    @mkdir($dir, 0700, true);
    $checkout = dirname(__DIR__, 2);
    $top = trim((string)shell_exec('git -C ' . escapeshellarg($checkout) . ' rev-parse --show-toplevel 2>/dev/null'));
    $o = [];
    exec('git -C ' . escapeshellarg($top !== '' ? $top : $checkout) . ' archive -o ' . escapeshellarg($dir . '.tar') . ' '
        . escapeshellarg(R105_BASE_FULL . ':dishnet-hybrid-sudan') . ' 2>&1 && tar -xf ' . escapeshellarg($dir . '.tar') . ' -C ' . escapeshellarg($dir) . ' 2>&1', $o, $rc);
    @unlink($dir . '.tar');
    $ver = (string)(json_decode((string)@file_get_contents($dir . '/manifest.json'), true)['information']['version'] ?? '');
    $got = ($rc === 0 && $ver === '5.18.104') ? [$dir, ''] : [null, 'git could not supply ' . R105_BASE . ' (5.18.104): rc ' . $rc . ' ' . trim(implode(' ', $o)) . ' version ' . $ver];
    return $got;
}

/**
 * The hunks of $new against $old (diff -U0), each [oldStart, oldLen, newStart, newLen, added lines, removed lines,
 * marked]. marked: R105_MARK in its own added lines, in the 30 lines above it, or a hunk that only closes a block —
 * qf_n_hunks' rule (tests/quote_followup/declared.php), with 5.18.105's marker.
 */
function r105_hunks(string $oldFile, string $newFile): array
{
    $nl = explode("\n", (string)@file_get_contents($newFile));
    $d = [];
    exec('diff -U0 ' . escapeshellarg(is_file($oldFile) ? $oldFile : '/dev/null') . ' ' . escapeshellarg($newFile), $d);
    $out = []; $i = -1;
    foreach ($d as $l) {
        if (preg_match('/^@@ -(\d+)(?:,(\d+))? \+(\d+)(?:,(\d+))? @@/', $l, $h)) {
            $out[++$i] = [(int)$h[1], ($h[2] ?? '') === '' ? 1 : (int)$h[2], (int)$h[3], ($h[4] ?? '') === '' ? 1 : (int)$h[4], [], [], false];
            continue;
        }
        if ($i < 0 || strpos($l, '+++ ') === 0 || strpos($l, '--- ') === 0) continue;
        if ($l !== '' && $l[0] === '+') $out[$i][4][] = substr($l, 1);
        if ($l !== '' && $l[0] === '-') $out[$i][5][] = substr($l, 1);
    }
    foreach ($out as $k => [$os, $ol, $ns, $len, $plus]) {
        $at = $len === 0 ? $ns : $ns - 1;
        $span = implode("\n", array_slice($nl, max(0, $at - 30), $at - max(0, $at - 30)));
        $closer = $plus !== [] && array_filter($plus, function ($x) { return !preg_match('/^\s*(?:<\?php\s+(?:endif|else|endforeach);\s*\?>|[})\];,]+)\s*$/', $x); }) === [];
        $out[$k][6] = preg_match(R105_MARK, implode("\n", $plus)) === 1 || preg_match(R105_MARK, $span) === 1 || $closer;
    }
    return $out;
}

/** $newFile's text with its marked hunks put back to $oldFile's lines; [text, unmarked hunk count]. */
function r105_revert_file(string $oldFile, string $newFile): array
{
    $old = explode("\n", (string)@file_get_contents($oldFile));
    $new = explode("\n", (string)@file_get_contents($newFile));
    $unmarked = 0;
    foreach (array_reverse(r105_hunks($oldFile, $newFile)) as [$os, $ol, $ns, $nl, , , $marked]) {
        if (!$marked) { $unmarked++; continue; }
        array_splice($new, $nl === 0 ? $ns : $ns - 1, $nl, array_slice($old, $ol === 0 ? $os : $os - 1, $ol));
    }
    return [implode("\n", $new), $unmarked];
}

/**
 * The judged tree with exactly 5.18.105's declared files and hunks taken out, built in $dest (the skipped directories left
 * out): every declared new file removed; every declared changed file's marked hunks put back to $base's lines (an unmarked
 * hunk stays, so the tree then differs from 994d660 there); manifest.json's version line put back. For the real tree the
 * result is 994d660's, file for file. Returns the count of unmarked hunks left in declared files.
 */
function r105_revert_tree(string $tree, string $base, string $dest): int
{
    @mkdir($dest, 0700, true);
    foreach (scandir($tree) ?: [] as $e) {
        if ($e === '.' || $e === '..' || in_array($e, R105_SKIP, true)) continue;
        exec('cp -R ' . escapeshellarg($tree . '/' . $e) . ' ' . escapeshellarg($dest . '/') . ' 2>/dev/null');
    }
    foreach (R105_DECLARED as $path => [$kind]) {
        if ($kind === 'new' && substr($path, -1) === '/' && !is_dir($base . '/' . $path)) exec('rm -rf ' . escapeshellarg($dest . '/' . rtrim($path, '/')));
    }
    $left = 0;
    foreach (r105_walk($dest) as $rel => $abs) {
        $entry = r105_declared_entry($rel);
        if ($entry === null) continue;
        if (R105_DECLARED[$entry][0] === 'new') { if (!is_file($base . '/' . $rel)) @unlink($abs); continue; }
        if (!is_file($base . '/' . $rel)) continue;
        if ($rel === 'manifest.json') {
            $s = (string)file_get_contents($abs);
            if (substr_count($s, '    "version": "5.18.105",') === 1) file_put_contents($abs, str_replace('    "version": "5.18.105",', '    "version": "5.18.104",', $s));
            continue;
        }
        [$text, $n] = r105_revert_file($base . '/' . $rel, $abs);
        $left += $n;
        file_put_contents($abs, $text);
    }
    return $left;
}

/** The cache key of r105_less(): the root's real path plus the sha256 of every R105_DECLARED file in it. */
function r105_less_key(string $real): string
{
    $h = hash_init('sha256');
    hash_update($h, $real . "\0");
    foreach (R105_DECLARED as $path => $row) {
        $files = [];
        if (substr($path, -1) === '/') {
            $dir = $real . '/' . rtrim($path, '/');
            if (is_dir($dir)) {
                foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $f) {
                    if ($f->isFile()) $files[substr($f->getPathname(), strlen($real) + 1)] = $f->getPathname();
                }
                ksort($files);
            }
        } elseif (is_file($real . '/' . $path)) {
            $files[$path] = $real . '/' . $path;
        }
        foreach ($files as $rel => $abs) hash_update($h, $rel . "\0" . hash_file('sha256', $abs) . "\0");
    }
    return hash_final($h);
}

/**
 * The judged tree with 5.18.105 taken out, and the count of unmarked 5.18.105 hunks left: [dir, left]. Built under $tmp,
 * only from $root, and cached per process by r105_less_key() — re-checked at every call, rebuilt on any difference.
 * Throws when 994d660 cannot be had from Git, or when $root holds no plugin: a failure, never a skip.
 */
function r105_less_build(string $root, string $tmp): array
{
    static $cache = [];
    $real = realpath($root);
    if ($real === false || !is_file($real . '/manifest.json')) throw new \RuntimeException('r105_less: ' . $root . ' holds no plugin');
    $key = r105_less_key($real);
    if (isset($cache[$key]) && is_dir($cache[$key][0])) return $cache[$key];
    [$base, $why] = r105_base(rtrim($tmp, '/') . '/r105-base');
    if ($base === null) throw new \RuntimeException('r105_less: ' . $why);
    $dest = rtrim($tmp, '/') . '/r105-less-' . substr($key, 0, 12) . '-' . bin2hex(random_bytes(3));
    $left = r105_revert_tree($real, $base, $dest);
    if (r105_less_key($real) !== $key) throw new \RuntimeException('r105_less: ' . $root . ' changed while 5.18.105 was taken out of it');
    $cache[$key] = [$dest, $left];
    return $cache[$key];
}

/** The judged tree $root with 5.18.105 taken out against 994d660: its folder (§H.3.2). */
function r105_less(string $root, string $tmp): string
{
    return r105_less_build($root, $tmp)[0];
}

/** The count of unmarked 5.18.105 hunks r105_less() left in $root's declared files (0 for the real tree). */
function r105_less_left(string $root, string $tmp): int
{
    return r105_less_build($root, $tmp)[1];
}

/** $rel in r105_less()'s tree for $root: its path, or null where 5.18.105 taken out leaves no such file. */
function r105_file(string $root, string $tmp, string $rel): ?string
{
    $p = r105_less($root, $tmp) . '/' . $rel;
    return is_file($p) ? $p : null;
}

/**
 * A weakened copy's own check (§H.1.0): '' when $rel in $tree carries an edit the layer leaves in place — at least one
 * hunk against 994d660 that is unmarked (no marker in it or in the 30 lines above it, and not one that only closes a
 * block), each such hunk at least $min lines from every 5.18.105 marker in the planted file — else why not. An edit inside
 * a marker's window would be taken for 5.18.105's own and put back without a word, and the copy would pass.
 */
function r105_planted_outside(string $tree, string $rel, int $min = 31, bool $layerWindow = false): string
{
    // 5.18.105 (root docs/74 §H.1.0): $layerWindow — only a marker inside the planted hunk or above it can mark it (the
    // layer's own rule: a marker in the hunk's added lines or in the 30 lines above it), so a marker below is not counted
    // there; QN8b, the one copy that needs it, plants in CD_I_KEPT nine lines above the I0 re-point's marker
    [$base, $why] = r105_base();
    if ($base === null) return $why;
    $new = $tree . '/' . $rel;
    $old = $base . '/' . $rel;
    if (!is_file($new) || !is_file($old)) return $rel . ' is not in both the copy and ' . R105_BASE;
    $marks = [];
    foreach (explode("\n", (string)file_get_contents($new)) as $i => $l) if (preg_match(R105_MARK, $l) === 1) $marks[] = $i + 1;
    $unmarked = [];
    foreach (r105_hunks($old, $new) as [$os, $ol, $ns, $nl, , , $marked]) {
        if (!$marked) $unmarked[] = [$ns, $nl === 0 ? $ns : $ns + $nl - 1];
    }
    if ($unmarked === []) return 'no unmarked hunk in ' . $rel . ': the layer would put the planted edit back without a word';
    foreach ($unmarked as [$a, $b]) foreach ($marks as $m) {
        if ($layerWindow && $m > $b) continue;
        $d = $m < $a ? $a - $m : ($m > $b ? $m - $b : 0);
        if ($d < $min) return $rel . ' +' . $a . '…' . $b . ' stands ' . $d . ' line(s) from the 5.18.105 marker at line ' . $m . ' (at least ' . $min . ' asked)';
    }
    return '';
}
