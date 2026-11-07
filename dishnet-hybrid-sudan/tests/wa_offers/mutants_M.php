<?php
declare(strict_types=1);
/**
 * Weakened copies judged by section M (5.18.104, root docs/73 §H.4.16 M; ruling R10), stage B10: M is a read of Part F's
 * N, so each is a change N does not declare, in P6's files: an unmarked hunk in a declared changed file, PHP 8 syntax in a
 * new library, and a P6 file put back to 221c9dc (declared, not changed). TEST ONLY.
 */
$M = array_merge($M, [
    ['label' => 'OM-M1 an unmarked hunk in includes/post_handlers.php', 'file' => 'includes/post_handlers.php',
     'old' => "<?php\n", 'new' => "<?php\n\$__omm1 = 1;\n", 'sections' => 'M', 'pattern' => '/M01 N reads no FAIL/'],
    ['label' => 'OM-M2 PHP 8 syntax in lib/OfferText.php', 'file' => 'lib/OfferText.php',
     'old' => "final class OfferText\n{\n", 'new' => "final class OfferText\n{\n    public static function omm2(\$x) { return \$x?->y; }\n",
     'sections' => 'M', 'pattern' => '/M01 N reads no FAIL|M05 /'],
    ['label' => 'OM-M3 P6\'s post_handlers.php line taken out (a declared file not changed)', 'file' => 'includes/post_handlers.php',
     'old' => "require __DIR__ . '/post/post_offers.php';         // 5.18.104 (root docs/73 §H.4.7): the Offers screen's seven actions. OfferSwitch, the fresh admin, CSRF and own-page inside.\n", 'new' => '',
     'sections' => 'M', 'pattern' => '/M01 N reads no FAIL/'],
]);
