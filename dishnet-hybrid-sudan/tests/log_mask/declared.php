<?php
declare(strict_types=1);
/**
 * declared.php — 5.18.105 (root docs/74 §H.3.2): the 5.18.105 boundary, as tests/test_log_mask.php reads it. TEST ONLY.
 *
 * The boundary itself — R105_BASE, R105_DECLARED (every file 5.18.105 changes or adds, with its kind and its reason),
 * R105_HELD, R105_MARK, and the layer that takes 5.18.105 out of a tree (r105_base, r105_hunks, r105_revert_file,
 * r105_revert_tree, r105_less, r105_planted_outside) — lives in ONE file, tests/fixtures/r105_revert.php, which every
 * older boundary re-pointed in §H.3.2 already loads. Defined twice, the two copies would drift, and a process loading
 * both would stop on a redeclared function; so this file loads that one and adds only what section D asks of it.
 *
 * Every person and number in these tests is fictitious.
 */
require_once dirname(__DIR__) . '/fixtures/r105_revert.php';

/** Is $rel held byte for byte by 5.18.105 (by its own entry in R105_HELD, or under a held directory)? */
function lm_r105_held(string $rel): bool
{
    foreach (R105_HELD as $h) {
        if ($h === $rel || (substr($h, -1) === '/' && strpos($rel, $h) === 0)) return true;
    }
    return false;
}

/** The runtime files R105_DECLARED names (outside tests/), PHP or not. */
function lm_r105_runtime(): array
{
    $out = [];
    foreach (R105_DECLARED as $path => $row) {
        if (strpos($path, 'tests/') === 0 || substr($path, -1) === '/') continue;
        $out[] = $path;
    }
    return $out;
}
