<?php
declare(strict_types=1);
/**
 * Section M — the boundary, a read of Part F's N (5.18.104, root docs/73 §H.4.16 M, §H.4.17; K2-16, ruling R10), stage B10.
 * TEST ONLY.
 *
 * N is the one boundary test (tests/test_quote_followup.php N over tests/quote_followup/declared.php, the one merged
 * declared list of Parts F, V, O and P). M runs it on the judged tree and reads P6's slice of the same list: P6's files of
 * §H.4.4 are in it, new or changed as §H.4.4 says; public.php and includes/navigation.php carry both Part F's and P6's
 * markers (one entry, one commit); 091 is the only P6 migration; php -l and the PHP 7.4 rules
 * (tests/quote_from_ucrm/I_boundary.php's QFU_I_RULES) on P6's runtime files.
 */
require_once dirname(__DIR__) . '/quote_followup/declared.php';
require_once dirname(__DIR__) . '/quote_from_ucrm/I_boundary.php';   // QFU_I_RULES (its section is not run from here)

/** P6's files of §H.4.4: new, then changed (the shared ones declared once, under Part F, with P6's hunks in them). */
const WO_M_NEW = ['migrations/091_wa_offers.sql', 'lib/OfferSwitch.php', 'lib/OfferText.php', 'lib/OfferImage.php', 'lib/OfferAudience.php',
    'lib/OfferStore.php', 'lib/OfferGateway.php', 'lib/EvolutionOfferGateway.php', 'lib/OfferSender.php', 'lib/OfferWindow.php',
    'lib/OfferSchema.php', 'cron/offers_send.php', 'tabs/engage/wa_offers.php', 'includes/post/post_offers.php', 'tests/test_wa_offers.php',
    'tests/wa_offers/'];
const WO_M_CHANGED = ['cron/master.php', 'public.php', 'includes/navigation.php', 'includes/post_handlers.php', 'manifest.json', 'SAFETY.md'];
/** §H.4.4 lists Part F's new lib/SalesFollowupLadder.php among the changed files (P6's rows are in it): in O's slice, as new. */
const WO_M_IN_F = ['lib/SalesFollowupLadder.php'];

$WO_SECTIONS['M'] = ['title' => 'The boundary: a read of Part F\'s N over the one merged declared list (§H.4.16 M; ruling R10)', 'run' => function (QfCtx $ctx) {
    $T = $ctx->root;
    [$B, $why] = qf_n_base($ctx->tmp . '/base221');
    $ctx->t($B !== null, 'M00 control: Git supplies ' . QF_N_BASE . ' — without it nothing below is measured', $why);
    if ($B === null) return;

    $ctx->run('M01 N, the one boundary test, passes on the judged tree', function () use ($ctx, $T) {
        $out = [];
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/test_quote_followup.php') . ' --no-mutants --root=' . escapeshellarg($T)
            . ' --sections=N 2>&1', $out, $rc);
        $ok = array_values(array_filter($out, function ($l) { return strpos($l, '  ok   N') === 0; }));
        $fails = array_values(array_filter($out, function ($l) { return strpos($l, '  FAIL ') === 0; }));
        $ctx->t(count($ok) > 10, 'M01 control: N ran its cases (' . count($ok) . ' ok)', implode("\n", array_slice($out, -10)));
        $ctx->eq([$rc, $fails], [0, []], 'M01 N reads no FAIL on the judged tree', implode("\n", array_slice($out, -30)));
    });

    $ctx->run('M02 P6\'s files of §H.4.4 are in the one merged list, each declared once with O among its parts, new or changed as §H.4.4 says', function () use ($ctx) {
        $o = qf_declared('O');
        $miss = [];
        foreach (WO_M_NEW as $f) if (!isset($o[$f]) || $o[$f][1] !== 'new') $miss[] = $f . ' (new)';
        foreach (WO_M_CHANGED as $f) if (!isset($o[$f]) || $o[$f][1] !== 'changed') $miss[] = $f . ' (changed)';
        foreach (WO_M_IN_F as $f) if (!isset($o[$f]) || explode(',', $o[$f][0])[0] !== 'F') $miss[] = $f . ' (Part F\'s, P6\'s rows in it)';
        $ctx->eq($miss, [], 'M02 the ' . (count(WO_M_NEW) + count(WO_M_CHANGED) + count(WO_M_IN_F)) . ' files of §H.4.4 in O\'s slice');
        $owned = array_keys(array_filter($o, function ($row) { return explode(',', $row[0])[0] === 'O'; }));
        $ctx->eq(array_values(array_diff($owned, WO_M_NEW, WO_M_CHANGED)), [], 'M02 … and O owns nothing §H.4.4 does not name');
    });

    $ctx->run('M03 public.php and includes/navigation.php carry both Part F\'s and P6\'s markers, in hunks of their own', function () use ($ctx, $T, $B) {
        foreach (['public.php' => ['§H.2.11.5', '§H.4.'], 'includes/navigation.php' => ['§H.2.11.5', '§H.4.7']] as $rel => [$f, $o]) {
            $hunks = qf_n_hunks($B . '/' . $rel, $T . '/' . $rel);
            $fh = 0; $oh = 0;
            foreach ($hunks as $h) {
                $txt = implode("\n", $h[4]);
                if (strpos($txt, $f) !== false) $fh++;
                if (strpos($txt, 'root docs/73 ' . $o) !== false) $oh++;
            }
            $ctx->eq([$fh > 0, $oh > 0, count($hunks) === count(array_filter($hunks, function ($h) { return $h[6]; }))], [true, true, true],
                     "M03 {$rel}: a Part F hunk ({$f}) and a P6 hunk ({$o}), every hunk marked");
        }
    });

    $ctx->run('M04 091 is the only P6 migration', function () use ($ctx, $T, $B) {
        $new = array_values(array_diff(array_map('basename', glob($T . '/migrations/*') ?: []), array_map('basename', glob($B . '/migrations/*') ?: [])));
        $o = array_values(array_filter($new, function ($m) { return explode(',', QF_DECLARED['migrations/' . $m][0] ?? '?')[0] === 'O'; }));
        $ctx->eq([$o, count($new)], [['091_wa_offers.sql'], 3], 'M04 of the three new migrations, 091 alone is P6\'s');
    });

    $ctx->run('M05 php -l, and the PHP 7.4 rules on every line of P6\'s new runtime files and every added line of its changed ones', function () use ($ctx, $T, $B) {
        $lint = []; $bad = []; $n = 0;
        foreach (array_merge(WO_M_NEW, WO_M_CHANGED) as $rel) {
            if (substr($rel, -4) !== '.php' || strpos($rel, 'tests/') === 0 || !is_file($T . '/' . $rel)) continue;
            $n++;
            $lo = []; exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($T . '/' . $rel) . ' 2>&1', $lo, $rc);
            if ($rc !== 0) $lint[] = $rel;
            $cb = $ctx->tmp . '/m05_base.php'; $cn = $ctx->tmp . '/m05_new.php';
            file_put_contents($cb, is_file($B . '/' . $rel) ? qf_n_code((string)file_get_contents($B . '/' . $rel)) : '');
            file_put_contents($cn, qf_n_code((string)file_get_contents($T . '/' . $rel)));
            $d = []; exec('diff ' . escapeshellarg($cb) . ' ' . escapeshellarg($cn), $d);
            foreach ($d as $l) {
                if (strpos($l, '> ') !== 0) continue;
                foreach (QFU_I_RULES as $what => $re) if (preg_match($re, substr($l, 2)) && !($what === 'a named argument' && preg_match('/\?\s*[^:]*:/', $l))) { $bad[] = "{$rel}: {$what}"; break; }
            }
        }
        $ctx->eq([$lint, $bad, $n >= 15], [[], [], true], 'M05 the ' . $n . ' P6 runtime PHP files lint and use nothing PHP 7.4 lacks');
    });
}];
