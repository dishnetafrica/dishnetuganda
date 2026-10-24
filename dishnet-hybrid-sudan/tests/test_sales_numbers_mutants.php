<?php
declare(strict_types=1);
/**
 * test_sales_numbers_mutants.php — 5.18.100 (a technician's own WhatsApp number, docs/65 §AE): every weakened copy of the
 * owner rule — X-a to X-k — made in a copy of the plugin, must change every fact the table names for it; the unchanged
 * copy must give this checkout's facts first. Development and release suites only, never on the server: it copies the
 * plugin and runs child processes.
 *
 * Each copy is made with sj_weakened_copy() (tests/fixtures/staff_jobs_sandbox.php) and every further edit the same way:
 * the anchor occurs exactly once, it is replaced exactly once, the copy holds the new text, the anchor survives only
 * where the new text carries it, and every edited PHP file lints. A copy whose anchor is missing is a FAILURE of this
 * harness, never a skip. Every anchor is 5.18.100's own text, or StaffDirectory's JOB_ROLES line; none is one that
 * tests/test_pilot_safety.php or tests/test_sales_numbers.php's X already holds in lib/SalesNumbersAdmin.php.
 *
 * What judges a copy: tests/test_sales_numbers.php's driver, this checkout's, run on the copy for the parts the table's
 * facts come from — "unit" (U9 and U10, in process) and "screen,tech" (S, and U10 in the sandbox) — and the facts it
 * prints. The facts' values are tests/test_sales_numbers.php's to assert; this harness asserts that each weakening moves
 * them. A copy is caught only when EVERY fact it names differs from the unchanged copy's; one that changes somewhere else
 * does not count, and a driver that does not finish (a fatal, no facts) is not caught.
 *
 * In order:
 *   1. the control: this checkout and an unchanged copy give the same facts, every fact the table names, none missing;
 *   2. the weakened copies, one line each: its name, the facts that moved, its verdict;
 *   3. the end check: the default vault beside this checkout is untouched, and every file this run made is in its own
 *      temporary folder.
 *
 *   cd tests && php test_sales_numbers_mutants.php [--mutants=REGEX]
 *
 * --mutants runs only the copies whose label matches (the control still runs first), so the table can be run in parts
 * within a time limit.
 *
 * Every person, number and instance is fictitious, as in tests/test_sales_numbers.php; nothing leaves 127.0.0.1.
 */
$lib = dirname(__DIR__);
$opt = getopt('', ['mutants:']);

// This run's own temporary folder, and TMPDIR pointed at it before anything asks PHP for one: every copy and every
// child's own folder land inside it, and it is removed at the end.
$own = rtrim((string)(getenv('TMPDIR') ?: '/tmp'), '/') . '/sn100m-' . getmypid() . '-' . bin2hex(random_bytes(3));
mkdir($own, 0700, true);
putenv('TMPDIR=' . $own);
putenv('DN_VAULT_FILE=' . $own . '/children.vault.json');   // each driver sets its own; this is only what a child inherits
register_shutdown_function(function () use ($own) { exec('rm -rf ' . escapeshellarg($own)); });
require_once __DIR__ . '/fixtures/staff_jobs_sandbox.php';                  // sj_weakened_copy()

$defVault  = dirname($lib) . '/.dishnet-sudan.vault.json';                  // ConfigVault::path()'s default for this tree
clearstatcache();
$defBefore = is_file($defVault) ? [hash_file('sha256', $defVault), filemtime($defVault)] : null;

$pass = 0; $fail = 0;
function is_(bool $c, string $m, string $d = ''): void {
    global $pass, $fail;
    if ($c) { $pass++; echo "  ok   {$m}\n"; }
    else    { $fail++; echo "  FAIL {$m}" . ($d !== '' ? "\n       " . substr($d, 0, 900) : '') . "\n"; }
}
function snm_done(): void {
    global $pass, $fail;
    printf("\n%d passed, %d failed\n", $pass, $fail);
    exit($fail === 0 ? 0 : 1);
}

const SNM_TEST = 'tests/test_sales_numbers.php';

/** The driver's facts for $parts on $tree: tests/test_sales_numbers.php --driver, this checkout's. ['_raw' => …] when it did not finish. */
function snm_drive(string $lib, string $tree, string $parts): array {
    $out = (string)shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($lib . '/' . SNM_TEST) . ' --driver '
        . escapeshellarg($tree) . ' ' . escapeshellarg($parts) . ' 2>/dev/null');
    $lines = array_values(array_filter(explode("\n", trim($out)), 'strlen'));
    $g = json_decode((string)end($lines), true);
    return is_array($g) ? $g : ['_raw' => substr($out, -600)];
}

/** The driver parts a list of facts comes from: U's in process, S's and U10's sandbox (t_) facts in the sandbox. */
function snm_parts(array $facts): array {
    $p = [];
    foreach ($facts as $k) $p[strpos($k, 'u') === 0 ? 'unit' : 'screen,tech'] = true;
    return array_keys($p);
}

/**
 * A weakened copy: every edit asserted. [copy, '' or why it is not a valid copy]. An edit is [file, anchor, replacement].
 * The first edit is sj_weakened_copy()'s (the anchor counted, replaced only when it occurs once); every further one is
 * counted the same way and replaced exactly once. Then the copy must hold each new text, each anchor may survive only
 * where its new text carries it, and every edited PHP file must lint.
 */
function snm_copy(string $lib, array $edits): array {
    [$rel0, $old0, $new0] = $edits[0];
    [$tmp, $n] = sj_weakened_copy($lib, $rel0, $old0, $new0);
    if ($n !== 1) return [$tmp, "the anchor of edit 1 ({$rel0}) occurs {$n} time(s), not once"];
    foreach (array_slice($edits, 1) as $i => [$rel, $o, $w]) {
        $src = (string)@file_get_contents($tmp . '/' . $rel);
        $c = substr_count($src, $o);
        if ($c !== 1) return [$tmp, 'the anchor of edit ' . ($i + 2) . " ({$rel}) occurs {$c} time(s), not once"];
        $res = str_replace($o, $w, $src, $made);
        if ($made !== 1) return [$tmp, 'edit ' . ($i + 2) . " ({$rel}) replaced {$made} time(s), not once"];
        file_put_contents($tmp . '/' . $rel, $res);
    }
    $lint = [];
    foreach ($edits as $i => [$rel, $o, $w]) {
        $src = (string)@file_get_contents($tmp . '/' . $rel);
        if ($w !== '' && strpos($src, $w) === false) return [$tmp, 'the copy does not hold the new text of edit ' . ($i + 1) . " ({$rel})"];
        if (substr_count($src, $o) !== substr_count($w, $o)) {
            return [$tmp, 'the anchor of edit ' . ($i + 1) . " ({$rel}) survives where its change does not carry it"];
        }
        if (substr($rel, -4) === '.php') $lint[$rel] = true;
    }
    foreach (array_keys($lint) as $rel) {
        $lo = [];
        exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($tmp . '/' . $rel) . ' 2>&1', $lo, $lrc);
        if ($lrc !== 0) return [$tmp, "{$rel} does not lint: " . trim(implode(' ', $lo))];
    }
    return [$tmp, ''];
}

// ═══ The weakened copies (docs/65 §AE; the design's X-a to X-g, X-h and X-j for the two controls it names, X-i, and X-k,
// after review: the sales rule stays 5.18.99's, so an is_admin salesperson is still offered) ═══
// [label, [[file, anchor, replacement], ...], the facts that must move].
$SNA = 'lib/SalesNumbersAdmin.php';
$SD  = 'lib/StaffDirectory.php';
$MUTANTS = [
    ['X-a: owners() offers every active row — the kind test dropped',
        [[$SNA, "            if (\$kind === '') continue;\n", "            if (false) continue;\n"]],
        ['u9_people', 's_card']],
    ['X-b: eligibleOwner() skips the owner rule',
        [[$SNA, "        if (self::ownerKind(\$r) === '') {\n", "        if (false) {\n"]],
        ['u9_add_refused', 'u9_refusal_text', 'u9_owner']],
    ['X-c: TECHNICIAN_ROLES gains admin — StaffDirectory::JOB_ROLES reused',
        [[$SNA, "    const TECHNICIAN_ROLES = ['support', 'support_leader', 'support_engineer'];\n",
                "    const TECHNICIAN_ROLES = ['support', 'support_leader', 'support_engineer', 'admin'];\n"]],
        ['u9_people', 'u9_add_refused']],
    ['X-d: a new owner is not asked the owner rule — the registry\'s active-only check alone',
        [[$SNA, "        \$person  = \$this->eligibleOwner(\$staffId);\n        \$this->reg->setOwner(",
                "        \$person  = \$this->staffById()[\$staffId] ?? [];\n        \$this->reg->setOwner("]],
        ['u9_owner']],
    ['X-e: ownerKind() reads SALES_ROLES for both kinds — technicians refused again',
        [[$SNA, "        if (in_array(\$role, self::TECHNICIAN_ROLES, true)) return \\StaffDirectory::isAdmin(\$r) ? '' : 'technician';\n",
                "        if (in_array(\$role, self::SALES_ROLES, true)) return \\StaffDirectory::isAdmin(\$r) ? '' : 'technician';\n"]],
        ['u9_people', 'u9_owner', 'u10_added', 's_card_tech', 's_add_tech']],
    ['X-f: the add writes role support for a technician\'s number',
        [[$SNA, "            'role'            => 'sales',\n",
                "            'role'            => self::ownerKind(\$person) === 'technician' ? 'support' : 'sales',\n"]],
        ['u10_added', 's_added_tech']],
    ['X-g: StaffDirectory::JOB_ROLES loses support — the "stays on install jobs" control has teeth',
        [[$SD, "    public const JOB_ROLES = ['support', 'support_leader', 'support_engineer', 'admin'];\n",
               "    public const JOB_ROLES = ['support_leader', 'support_engineer', 'admin'];\n"]],
        ['u10_jobs']],
    ['X-h: ownerKind() offers an is_admin technician — the is_admin test dropped',
        [[$SNA, "        if (in_array(\$role, self::TECHNICIAN_ROLES, true)) return \\StaffDirectory::isAdmin(\$r) ? '' : 'technician';\n",
                "        if (in_array(\$role, self::TECHNICIAN_ROLES, true)) return 'technician';\n"]],
        ['u9_people', 'u9_add_refused', 'u9_owner']],
    ['X-i: the add writes the owner\'s own staff row — his role made sales',
        [[$SNA, "        \$person  = \$this->eligibleOwner(\$staffId);\n        \$name    = trim((string)(\$post['display_name'] ?? ''));\n",
                "        \$person  = \$this->eligibleOwner(\$staffId);\n"
              . "        if (\$this->store !== null) {   // weakened copy X-i: the card writes the owner's own row\n"
              . "            \$xi = (array)(\$this->store->load('retailers.json') ?? []);\n"
              . "            foreach (\$xi as &\$xr) if (is_array(\$xr) && (int)(\$xr['id'] ?? 0) === \$staffId) \$xr['role'] = 'sales';\n"
              . "            unset(\$xr);\n"
              . "            \$this->store->save('retailers.json', \$xi);\n"
              . "        }\n"
              . "        \$name    = trim((string)(\$post['display_name'] ?? ''));\n"]],
        ['u10_row_hash', 'u10_jobs']],
    ['X-j: ownerKind() offers an inactive row — the active test dropped',
        [[$SNA, "        if (!\\StaffDirectory::isActive(\$r)) return '';\n",
                "        if (false) return '';\n"]],
        ['u9_people']],
    ['X-k: ownerKind() refuses an is_admin salesperson — the narrowing of salespeople nobody decided',
        [[$SNA, "        if (!\\StaffDirectory::isActive(\$r)) return '';\n",
                "        if (!\\StaffDirectory::isActive(\$r) || \\StaffDirectory::isAdmin(\$r)) return '';\n"]],
        ['u9_people', 'u9_owner']],
];
$TABLE = count($MUTANTS);
if (isset($opt['mutants'])) {                                               // a part of the table only: the control still runs
    $only = '#' . str_replace('#', '\\#', (string)$opt['mutants']) . '#';
    $MUTANTS = array_values(array_filter($MUTANTS, function (array $m) use ($only) { return preg_match($only, $m[0]) === 1; }));
    echo '(--mutants: ' . count($MUTANTS) . " of the table's {$TABLE} weakened copies)\n";
    if ($MUTANTS === []) { is_(false, 'the --mutants pattern names at least one weakened copy'); snm_done(); }
}

// ═══════════════════════════════════════════════════════════════════════════
echo "1. The control: this checkout and an unchanged copy give the same facts\n";
is_($TABLE === 11, 'the table holds X-a to X-k, eleven weakened copies', (string)$TABLE);
$labels = array_map(function ($m) { return explode(':', $m[0])[0]; }, $MUTANTS);
is_(count(array_unique($labels)) === count($labels), 'no weakened copy is listed twice');
$named = [];
foreach ($MUTANTS as $m) foreach ($m[2] as $k) $named[$k] = true;
$named = array_keys($named);
$parts = snm_parts($named);
$facts0 = [];
foreach ($parts as $part) {
    $r = snm_drive($lib, $lib, $part);
    is_(!isset($r['_raw']), "this checkout's driver finishes for {$part}", (string)($r['_raw'] ?? ''));
    if (!isset($r['_raw'])) $facts0 += $r;
}
$missing = array_values(array_filter($named, function ($k) use ($facts0) { return ($facts0[$k] ?? null) === null; }));
is_($missing === [], 'it gives every fact the table names (' . count($named) . ')', json_encode($missing));
[$ctl, $why] = snm_copy($lib, [[$SNA, "final class SalesNumbersAdmin\n", "final class SalesNumbersAdmin\n"]]);
if ($why !== '') {
    is_(false, 'the unchanged copy: its anchor occurs once and the copy lints', $why);
    exec('rm -rf ' . escapeshellarg($ctl));
    snm_done();
}
$facts1 = [];
foreach ($parts as $part) {
    $r = snm_drive($lib, $ctl, $part);
    is_(!isset($r['_raw']), "the unchanged copy's driver finishes for {$part}", (string)($r['_raw'] ?? ''));
    if (!isset($r['_raw'])) $facts1 += $r;
}
exec('rm -rf ' . escapeshellarg($ctl));
$differ = array_values(array_filter($named, function ($k) use ($facts0, $facts1) { return ($facts1[$k] ?? null) !== ($facts0[$k] ?? null); }));
is_($differ === [], 'the unchanged copy gives this checkout\'s facts, every one the table names', json_encode($differ));
if ($fail > 0) { echo "\n  The control failed: no weakened copy is judged against facts that are not this checkout's.\n"; snm_done(); }

// ═══════════════════════════════════════════════════════════════════════════
echo "\n2. The weakened copies: each must move every fact the table names for it\n";
$caught = 0;
foreach ($MUTANTS as [$label, $edits, $facts]) {
    $id = explode(':', $label)[0];
    [$tmp, $why] = snm_copy($lib, $edits);
    if ($why !== '') {
        is_(false, "{$id}: the copy is made — each anchor occurs once, is replaced once, the copy holds the change and lints", $why);
        exec('rm -rf ' . escapeshellarg($tmp));
        continue;
    }
    $x = []; $raw = '';
    foreach (snm_parts($facts) as $part) {
        $r = snm_drive($lib, $tmp, $part);
        if (isset($r['_raw'])) { $raw = "{$part}: " . $r['_raw']; break; }
        $x += $r;
    }
    exec('rm -rf ' . escapeshellarg($tmp));
    $moved = []; $stayed = [];
    foreach ($facts as $k) {
        if ($raw === '' && ($x[$k] ?? null) !== $facts0[$k]) $moved[] = $k; else $stayed[] = $k;
    }
    $ok = $raw === '' && $stayed === [];
    if ($ok) $caught++;
    is_($ok, sprintf('%-4s moved: %s — %s', $id, $moved === [] ? 'nothing' : implode(', ', $moved),
        $ok ? 'CAUGHT' : ($raw !== '' ? 'NOT CAUGHT: the driver did not finish' : 'SURVIVED: not moved ' . implode(', ', $stayed)))
        . ' — ' . substr($label, strlen($id) + 2),
        $raw !== '' ? $raw : json_encode(array_intersect_key($x, array_flip($stayed)), JSON_UNESCAPED_UNICODE));
}
echo "\n  {$caught} of " . count($MUTANTS) . " weakened copies caught where the table says\n";

// ═══════════════════════════════════════════════════════════════════════════
echo "\n3. The end check\n";
clearstatcache();
$defAfter = is_file($defVault) ? [hash_file('sha256', $defVault), filemtime($defVault)] : null;
is_($defAfter === $defBefore, 'the default vault beside this checkout is untouched (' . ($defBefore === null ? 'still absent' : 'same bytes and mtime') . ')');
is_(strpos(sys_get_temp_dir(), $own) === 0, 'every temporary file of this run lives in its own folder, removed when it ends', sys_get_temp_dir());
snm_done();
