<?php
declare(strict_types=1);
/**
 * Section A of tests/test_log_mask.php — 5.18.105 (root docs/74 §H.3.4 A, §H.1.0, §H.1.22): each masked site driven
 * through its real path, and the helper. TEST ONLY.
 *
 *   A00  PluginLog::tail(): '••••' and the last two digits for every form of a number, 'none' without a digit;
 *        PluginLog::mask(): a 9- to 13-digit number in each of the four forms comes out as <number> (§H.1.0 E, G-3: its
 *        pattern needs seven characters, so a 6-digit run is left by design and the test is on 9+ digit numbers).
 *   G01…G21  the 35 lines LN-01…LN-35 (LN-24's drive covering its three call sites), in 21 harness groups
 *        (tests/log_mask/g01_*.php … g21_*.php): per line A1 the line is written, found by its fixed words (the positive
 *        control), A2 no form of the fixture number and not its last nine digits, A3 no run of nine digits or more with
 *        its time prefix removed — and each line's own extra assertions.
 *
 * Each group is one case, named "Gnn LN-…", so a weakened copy runs only the group its line is driven in
 * (tests/log_mask/mutants_A.php). Every person and number is fictitious (+2567000001xx).
 */
foreach (glob(__DIR__ . '/g[0-9][0-9]_*.php') ?: [] as $f) require_once $f;

/** The groups, in order: [case prefix, function, the LNs it drives]. */
const LM_GROUPS = [
    ['G01', 'lm_g01', ['LN-01']], ['G02', 'lm_g02', ['LN-02']], ['G03', 'lm_g03', ['LN-03']], ['G04', 'lm_g04', ['LN-04']],
    ['G05', 'lm_g05', ['LN-05']], ['G06', 'lm_g06', ['LN-06']], ['G07', 'lm_g07', ['LN-07']], ['G08', 'lm_g08', ['LN-08']],
    ['G09', 'lm_g09', ['LN-09', 'LN-10']], ['G10', 'lm_g10', ['LN-11', 'LN-12', 'LN-13', 'LN-14']], ['G11', 'lm_g11', ['LN-15']],
    ['G12', 'lm_g12', ['LN-16', 'LN-17', 'LN-18', 'LN-19', 'LN-21', 'LN-22']], ['G13', 'lm_g13', ['LN-20']], ['G14', 'lm_g14', ['LN-23']],
    ['G15', 'lm_g15', ['LN-24']], ['G16', 'lm_g16', ['LN-25']], ['G17', 'lm_g17', ['LN-26', 'LN-27']], ['G18', 'lm_g18', ['LN-28', 'LN-29']],
    ['G19', 'lm_g19', ['LN-30', 'LN-31', 'LN-32']], ['G20', 'lm_g20', ['LN-33', 'LN-34']], ['G21', 'lm_g21', ['LN-35']],
];

/** The group a line is driven in: its case prefix ('G12'), or ''. */
function lm_group_of(string $ln): string
{
    foreach (LM_GROUPS as [$g, , $lns]) if (in_array($ln, $lns, true)) return $g;
    return '';
}

$LM_SECTIONS['A'] = ['title' => 'Each masked line driven through its real path (§H.3.4 A, §H.1.0)', 'run' => function (QfCtx $ctx) {
    $ctx->run('A00 tail() PluginLog::tail(): "••••" and two digits, or "none" (§H.1.22)', function () use ($ctx) {
        lm_lib($ctx, 'PluginLog');
        $ctx->t(method_exists('PluginLog', 'tail'), 'A00 control: PluginLog::tail() exists in the judged tree');
        if (!method_exists('PluginLog', 'tail')) return;
        $forms = lm_forms('+256700000731');
        $ctx->eq(array_map(function ($f) { return PluginLog::tail($f); }, $forms), ['••••31', '••••31', '••••31', '••••31'],
            'A00 tail(): every form of +256700000731 gives "••••31" (E.164, digits, national, spaced)');
        $ctx->eq([PluginLog::tail(''), PluginLog::tail(null), PluginLog::tail('abc'), PluginLog::tail('7')], ['none', 'none', 'none', '••••7'],
            'A00 tail(): "", null and "abc" give "none"; "7" gives "••••7"');
        $ctx->eq(lm_long_runs(implode(' ', array_map(function ($f) { return PluginLog::tail($f); }, $forms))), [],
            'A00 tail(): its output carries no run of nine digits or more');
    });
    $ctx->run('A00 mask() PluginLog::mask(): a 9- to 13-digit number in each of its four forms is <number> (§H.1.0 E)', function () use ($ctx) {
        lm_lib($ctx, 'PluginLog');
        $bad = [];
        foreach (range(9, 13) as $n) {
            $d = substr('2567000001319876', 0, $n);
            $forms = ['+' . $d, $d, '0' . substr($d, 3), trim(chunk_split($d, 3, ' '))];
            foreach ($forms as $f) {
                $got = PluginLog::mask('call ' . $f . ' today (test)');
                if ($got !== 'call <number> today (test)') $bad[] = $f . ' → ' . $got;
            }
        }
        $ctx->eq($bad, [], 'A00 mask(): every form of every 9- to 13-digit number comes out as <number>');
        $ctx->eq(PluginLog::mask('ref 123456 (test)'), 'ref 123456 (test)', 'A00 mask() control: a 6-digit run is left, by design (its pattern needs seven characters)');
    });
    foreach (LM_GROUPS as [$g, $fn]) {
        if (!function_exists($fn)) { $ctx->t(false, "{$g} its drive exists ({$fn})"); continue; }
        $fn($ctx);
    }
}];
