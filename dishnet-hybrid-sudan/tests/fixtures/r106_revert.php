<?php
declare(strict_types=1);
/**
 * TEST ONLY — 5.18.106 (root docs/75 §J): the 5.18.106 layer, tests/fixtures/r105_revert.php's mechanism one release on.
 *
 * Every older boundary judges the tree against an older commit; to them a 5.18.106 hunk is an unmarked change and a
 * 5.18.106 file is undeclared. So 5.18.106 is taken out first, against 7835906 (5.18.105's release commit, from this
 * checkout's Git, never from a working tree), and the 5.18.105 layer then takes 5.18.105 out of what is left:
 * r105_less_build() reads r106_less() of the judged root (tests/fixtures/r105_revert.php), and tests/log_mask D judges
 * r106_less() of the root against 994d660. Every older boundary therefore judges 7835906's tree exactly as it
 * does today; 5.18.106's own hunks are judged once, by tests/quote_followup/U_unjam.php U20–U23.
 *
 *   R106_DECLARED        every file 5.18.106 changes or adds, each with its kind and its reason. R106_HELD: what 5.18.106
 *                        leaves byte for byte.
 *   R106_MARK            the marker, "5.18.106 … root docs/75 §" within one line.
 *   r106_hunks()         a hunk is 5.18.106's when the marker stands in its own added lines or in the 30 lines above it,
 *                        or when it only closes a block. r106_revert_file(): the same way.
 *   r106_revert_tree()   the judged tree with 5.18.106's new files removed, its marked hunks put back to 7835906's lines and
 *                        manifest.json's version put back from 5.18.106 to 5.18.105 (only where that line occurs exactly
 *                        once); the count of unmarked hunks it left.
 *   r106_less()          that tree for a judged root, cached per process by the sha256 of every R106_DECLARED file in it,
 *                        re-checked at every call. A checkout whose Git cannot supply 7835906 (at 5.18.105) is a failure of
 *                        the test that asks — r106_less() throws — never a skip.
 *
 * Every person, number and message in these tests is fictitious.
 */

const R106_BASE      = '7835906';
const R106_BASE_FULL = '7835906a0e4af49557f7c0eb994cb9129637ca00';
/** The marker, as a pattern over one line. */
const R106_MARK = '#5\.18\.106[^\n]{0,40}root docs/75 §#u';
/** What a release does not ship and a weakened copy does not carry (QF_N_SKIP, tests/quote_followup/declared.php). */
const R106_SKIP = ['docs', 'prototype', 'dishnet-mikrotik-control-plane', 'data', '.git', 'scripts'];

/** Every file 5.18.106 changes or adds: path => [kind, reason]. */
const R106_DECLARED = [
    // ── the five runtime files (§C F1–F5) ─────────────────────────────────────────────────────────────────────────────
    'lib/FollowUpService.php'               => ['changed', '§C F1 refusedWhileDelivering, F2 staleBefore/staleHours, F3 expireStale and due()\'s stale limit, F5 due()\'s waitingOut, attemptTaken, expireTakenDraft, expireOtherApproved; §K'],
    'cron/followup_send.php'                => ['changed', '§C F1 three refusals while the number delivers, F2 the age limit, F5 one draft a pass, a taken attempt expired, the others expired after a send; §K'],
    'cron/followup_run.php'                 => ['changed', '§C F3, F5 due() on Uganda'],
    'cron/followup_close.php'               => ['changed', '§C F3 stale follow-ups expired'],
    'cron/followup_scan.php'                => ['changed', '§C F4 one follow-up per quiet spell'],
    // ── the version and the record ───────────────────────────────────────────────────────────────────────────────────
    'manifest.json'                         => ['changed', '§J the version line, 5.18.105 → 5.18.106'],
    'SAFETY.md'                             => ['changed', '§J the 5.18.106 section of the VERSION LOG'],
    // ── 5.18.106's own tests and this layer ──────────────────────────────────────────────────────────────────────────
    'tests/quote_followup/U_unjam.php'      => ['new', '§J section U'],
    'tests/quote_followup/mutants_U.php'    => ['new', '§J section U\'s weakened copies'],
    'tests/fixtures/r106_revert.php'        => ['new', '§J the layer'],
    // ── the older tests, re-pointed through the layer or to the new version, never weakened ──────────────────────────
    'tests/fixtures/r105_revert.php'        => ['changed', '§J r105_less_build() reads r106_less() of the judged root'],
    'tests/log_mask/D_boundary.php'         => ['changed', '§J D judges r106_less() of the root'],
    'tests/quote_followup/H_kill_switch.php'=> ['changed', '§J H03: its enquiry row quiet two days before the run\'s real clock (F3 leaves a stale one out)'],
    'tests/quote_followup/N_boundary.php'   => ['changed', '§J N09, the control on the controls: the judged tree with 5.18.106 taken out, 5.18.105 left in'],
    'tests/quote_followup/G_sender.php'     => ['changed', '§J G04: claim mode\'s tries_exhausted counted as ledger_tries_exhausted, never F1\'s count'],
    'tests/test_bank_boundaries.php'        => ['changed', '§J the version pin'],
    'tests/test_cashbook_physical_list.php' => ['changed', '§J the version pin'],
    'tests/test_quote_company.php'          => ['changed', '§J I5\'s version pin'],
    'tests/test_distributor_apply.php'      => ['changed', '§J the version pin'],
    'tests/test_distributor_link_ucrm.php'  => ['changed', '§J the version pin'],
    'tests/test_distributor_notify.php'     => ['changed', '§J the version pin'],
    'tests/test_distributor_registry.php'   => ['changed', '§J the version pin'],
    'tests/test_distributor_territory.php'  => ['changed', '§J the version pin'],
];

/** What 5.18.106 leaves byte for byte. */
const R106_HELD = [
    'lib/WaSendBudget.php', 'lib/QuoteChase.php', 'lib/EvolutionApiService.php', 'lib/StaffJobsGate.php', 'lib/FollowUpPolicy.php',
    'lib/ConversationService.php', 'lib/PluginLog.php', 'lib/AutomationPolicy.php', 'lib/OwnedNumberHold.php',
    'tools/sales_followup.php', 'lib/SalesFollowupLadder.php', 'lib/SalesFollowupChecks.php', 'tools/sales_followup_report.php',
    'cron/master.php', 'cron/quote_chase.php', 'cron/offers_send.php', 'migrations/',
];

/** Is $rel declared — by its own entry, or under a declared new directory? The entry's path, or null. */
function r106_declared_entry(string $rel): ?string
{
    if (isset(R106_DECLARED[$rel])) return $rel;
    foreach (R106_DECLARED as $path => $row) {
        if (substr($path, -1) === '/' && strpos($rel, $path) === 0) return $path;
    }
    return null;
}

/** A plugin tree's files, rel => absolute, the skipped top-level directories left out. */
function r106_walk(string $tree): array
{
    $out = [];
    if (!is_dir($tree)) return $out;
    $it = new \RecursiveIteratorIterator(new \RecursiveCallbackFilterIterator(
        new \RecursiveDirectoryIterator($tree, \FilesystemIterator::SKIP_DOTS),
        function ($f) use ($tree) { return !in_array(explode('/', substr($f->getPathname(), strlen($tree) + 1))[0], R106_SKIP, true); }));
    foreach ($it as $f) if ($f->isFile()) $out[substr($f->getPathname(), strlen($tree) + 1)] = $f->getPathname();
    ksort($out);
    return $out;
}

/**
 * 7835906's plugin tree, from this checkout's Git, into $dir (made once per process; '' picks a folder under the
 * process's temporary folder): [dir|null, reason]. Its manifest must read 5.18.105. Never from a working tree. A checkout
 * that cannot supply it is a failure of the test that asks, never a skip.
 */
function r106_base(string $dir = ''): array
{
    static $got = null;
    if ($got !== null && is_dir((string)$got[0])) return $got;
    if ($dir === '') {   // a folder of its own, removed when the process ends (a caller's folder is the caller's to remove)
        $dir = rtrim(sys_get_temp_dir(), '/') . '/r106-base-' . getmypid() . '-' . bin2hex(random_bytes(3));
        register_shutdown_function(function () use ($dir) { exec('rm -rf ' . escapeshellarg($dir) . ' ' . escapeshellarg($dir . '.tar')); });
    }
    @mkdir($dir, 0700, true);
    $checkout = dirname(__DIR__, 2);
    $top = trim((string)shell_exec('git -C ' . escapeshellarg($checkout) . ' rev-parse --show-toplevel 2>/dev/null'));
    $o = [];
    exec('git -C ' . escapeshellarg($top !== '' ? $top : $checkout) . ' archive -o ' . escapeshellarg($dir . '.tar') . ' '
        . escapeshellarg(R106_BASE_FULL . ':dishnet-hybrid-sudan') . ' 2>&1 && tar -xf ' . escapeshellarg($dir . '.tar') . ' -C ' . escapeshellarg($dir) . ' 2>&1', $o, $rc);
    @unlink($dir . '.tar');
    $ver = (string)(json_decode((string)@file_get_contents($dir . '/manifest.json'), true)['information']['version'] ?? '');
    $got = ($rc === 0 && $ver === '5.18.105') ? [$dir, ''] : [null, 'git could not supply ' . R106_BASE . ' (5.18.105): rc ' . $rc . ' ' . trim(implode(' ', $o)) . ' version ' . $ver];
    return $got;
}

/**
 * The hunks of $new against $old (diff -U0), each [oldStart, oldLen, newStart, newLen, added lines, removed lines,
 * marked]. marked: R106_MARK in its own added lines, in the 30 lines above it, or a hunk that only closes a block —
 * qf_n_hunks' rule (tests/quote_followup/declared.php), with 5.18.106's marker.
 */
function r106_hunks(string $oldFile, string $newFile): array
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
        $out[$k][6] = preg_match(R106_MARK, implode("\n", $plus)) === 1 || preg_match(R106_MARK, $span) === 1 || $closer;
    }
    return $out;
}

/** $newFile's text with its marked hunks put back to $oldFile's lines; [text, unmarked hunk count]. */
function r106_revert_file(string $oldFile, string $newFile): array
{
    $old = explode("\n", (string)@file_get_contents($oldFile));
    $new = explode("\n", (string)@file_get_contents($newFile));
    $unmarked = 0;
    foreach (array_reverse(r106_hunks($oldFile, $newFile)) as [$os, $ol, $ns, $nl, , , $marked]) {
        if (!$marked) { $unmarked++; continue; }
        array_splice($new, $nl === 0 ? $ns : $ns - 1, $nl, array_slice($old, $ol === 0 ? $os : $os - 1, $ol));
    }
    return [implode("\n", $new), $unmarked];
}

/**
 * The judged tree with exactly 5.18.106's declared files and hunks taken out, built in $dest (the skipped directories left
 * out): every declared new file removed; every declared changed file's marked hunks put back to $base's lines (an unmarked
 * hunk stays, so the tree then differs from 7835906 there); manifest.json's version line put back. For the real tree the
 * result is 7835906's, file for file. Returns the count of unmarked hunks left in declared files.
 */
function r106_revert_tree(string $tree, string $base, string $dest): int
{
    @mkdir($dest, 0700, true);
    foreach (scandir($tree) ?: [] as $e) {
        if ($e === '.' || $e === '..' || in_array($e, R106_SKIP, true)) continue;
        exec('cp -R ' . escapeshellarg($tree . '/' . $e) . ' ' . escapeshellarg($dest . '/') . ' 2>/dev/null');
    }
    foreach (R106_DECLARED as $path => [$kind]) {
        if ($kind === 'new' && substr($path, -1) === '/' && !is_dir($base . '/' . $path)) exec('rm -rf ' . escapeshellarg($dest . '/' . rtrim($path, '/')));
    }
    $left = 0;
    foreach (r106_walk($dest) as $rel => $abs) {
        $entry = r106_declared_entry($rel);
        if ($entry === null) continue;
        if (R106_DECLARED[$entry][0] === 'new') { if (!is_file($base . '/' . $rel)) @unlink($abs); continue; }
        if (!is_file($base . '/' . $rel)) continue;
        if ($rel === 'manifest.json') {
            $s = (string)file_get_contents($abs);
            if (substr_count($s, '    "version": "5.18.106",') === 1) file_put_contents($abs, str_replace('    "version": "5.18.106",', '    "version": "5.18.105",', $s));
            continue;
        }
        [$text, $n] = r106_revert_file($base . '/' . $rel, $abs);
        $left += $n;
        file_put_contents($abs, $text);
    }
    return $left;
}

/** The cache key of r106_less(): the root's real path plus the sha256 of every R106_DECLARED file in it. */
function r106_less_key(string $real): string
{
    $h = hash_init('sha256');
    hash_update($h, $real . "\0");
    // 5.18.106 (root docs/75 §J): also every file the 5.18.105 layer reads after this one (R105_DECLARED, when loaded), so
    // a change to one of them rebuilds this copy and, through its new folder, the 5.18.105 tree built from it
    $lists = R106_DECLARED + (defined('R105_DECLARED') ? constant('R105_DECLARED') : []);
    foreach ($lists as $path => $row) {
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
 * The judged tree with 5.18.106 taken out, and the count of unmarked 5.18.106 hunks left: [dir, left]. Built under $tmp,
 * only from $root, and cached per process by r106_less_key() — re-checked at every call, rebuilt on any difference.
 * Throws when 7835906 cannot be had from Git, or when $root holds no plugin: a failure, never a skip.
 */
function r106_less_build(string $root, string $tmp): array
{
    static $cache = [];
    $real = realpath($root);
    if ($real === false || !is_file($real . '/manifest.json')) throw new \RuntimeException('r106_less: ' . $root . ' holds no plugin');
    $key = r106_less_key($real);
    if (isset($cache[$key]) && is_dir($cache[$key][0])) return $cache[$key];
    [$base, $why] = r106_base(rtrim($tmp, '/') . '/r106-base');
    if ($base === null) throw new \RuntimeException('r106_less: ' . $why);
    $dest = rtrim($tmp, '/') . '/r106-less-' . substr($key, 0, 12) . '-' . bin2hex(random_bytes(3));
    $left = r106_revert_tree($real, $base, $dest);
    if (r106_less_key($real) !== $key) throw new \RuntimeException('r106_less: ' . $root . ' changed while 5.18.106 was taken out of it');
    $cache[$key] = [$dest, $left];
    return $cache[$key];
}

/** The judged tree $root with 5.18.106 taken out against 7835906: its folder (§J). */
function r106_less(string $root, string $tmp): string
{
    return r106_less_build($root, $tmp)[0];
}

/** The count of unmarked 5.18.106 hunks r106_less() left in $root's declared files (0 for the real tree). */
function r106_less_left(string $root, string $tmp): int
{
    return r106_less_build($root, $tmp)[1];
}

/** $rel in r106_less()'s tree for $root: its path, or null where 5.18.106 taken out leaves no such file. */
function r106_file(string $root, string $tmp, string $rel): ?string
{
    $p = r106_less($root, $tmp) . '/' . $rel;
    return is_file($p) ? $p : null;
}
