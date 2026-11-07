<?php
declare(strict_types=1);
/** Weakened copies judged by section H (5.18.104, root docs/73 §H.4.16): the per-offer counts and the 72-hour marks. TEST ONLY. */
$M = array_merge($M, [
    ['label' => 'OM60 the replied count includes STOP', 'file' => 'lib/OfferStore.php',
     'old' => "sum(state IN ('sent', 'uncertain') AND replied_at IS NOT NULL AND stop_at IS NULL) AS replied_72h", 'new' => "sum(state IN ('sent', 'uncertain') AND replied_at IS NOT NULL) AS replied_72h",
     'sections' => 'H', 'pattern' => '/H01 /'],
    ['label' => 'OM61 72 h becomes 96 h', 'file' => 'lib/OfferStore.php', 'old' => 'const MARK_WINDOW_S = 259200;', 'new' => 'const MARK_WINDOW_S = 345600;',
     'sections' => 'H', 'pattern' => '/H01 /'],
    ['label' => 'OM-H1 an opt-out in the window not read as STOP', 'file' => 'lib/OfferStore.php',
     'old' => "                if (\$c > \$from && \$c <= \$to && (\$stop === null || \$c < \$stop)) \$stop = \$c;\n", 'new' => '',
     'sections' => 'H', 'pattern' => '/H01 /'],
]);
