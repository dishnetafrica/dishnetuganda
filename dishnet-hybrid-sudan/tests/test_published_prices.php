<?php
/**
 * test_published_prices.php — the comparison file must never become a price list.
 *
 * tools/published_prices.json records what the flyer says, so price_check.php
 * can tell us when uCRM and the printed material have drifted apart. That is
 * useful and safe exactly as long as nothing quotes from it.
 *
 * The moment any runtime path reads it there are two price lists, and the one
 * a customer is told depends on which code ran. uCRM stops being the source of
 * truth without anybody deciding that it should. This test is the guard: it
 * fails if the file is ever required, included or read outside the checking
 * tool and the tests.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$pass = 0; $fail = 0;
function ok(string $m): void  { global $pass; $pass++; echo "  ok   {$m}\n"; }
function bad(string $m, string $d=''): void { global $fail; $fail++; echo "  FAIL {$m}\n"; if($d)echo "       {$d}\n"; }
function is_(bool $c, string $m, string $d=''): void { $c ? ok($m) : bad($m, $d); }

echo "\nThe file is loadable and says what it is\n";
$raw = (string)file_get_contents($root . '/tools/published_prices.json');
$j = json_decode($raw, true);
is_(is_array($j), 'published_prices.json parses');
is_(!empty($j['plans']) && !empty($j['hardware']), 'it lists plans and hardware');
is_(($j['currency'] ?? '') === 'UGX', 'and states its currency, so a figure is never bare');
is_(stripos(implode(' ', (array)($j['_readme'] ?? [])), 'not a price list') !== false,
    'the file says in its own text that it is not a price list');

echo "\nBusiness stays unpublished, exactly as the flyer has it\n";
// "ASK FOR TODAY'S QUOTE" on the flyer and RED in the AI must be the same fact.
$business = array_values(array_filter((array)$j['plans'],
    fn($p) => stripos((string)($p['match'] ?? ''), 'business') !== false));
is_(count($business) >= 3, 'the three Business tiers are listed');
foreach ($business as $b) {
    // ?? treats an explicit null as absent, so the key must be checked directly.
    is_(array_key_exists('published', $b) && $b['published'] === null,
        'no published price for "' . $b['match'] . '"',
        'publishing one here would contradict ASK FOR TODAY\'S QUOTE');
}

echo "\nNothing in the plugin reads it except the checking tool\n";
// The guard that matters. Anything else reading this file is a second source
// of truth for money.
$hits = [];
$rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,
    FilesystemIterator::SKIP_DOTS));
foreach ($rii as $f) {
    if ($f->getExtension() !== 'php') continue;
    $path = $f->getPathname();
    $rel  = ltrim(str_replace($root, '', $path), '/');
    if (strpos($rel, 'vendor/') === 0) continue;
    $src = (string)@file_get_contents($path);
    if (strpos($src, 'published_prices') === false) continue;
    $hits[] = $rel;
}
sort($hits);
$allowed = ['tests/test_published_prices.php', 'tools/price_check.php'];
$stray = array_values(array_diff($hits, $allowed));
is_(!$stray, 'only price_check.php and this test reference it',
    'reads it too: ' . implode(', ', $stray));
is_(in_array('tools/price_check.php', $hits, true),
    'and the checking tool does read it — the comparison is real');

echo "\nThe AI path in particular has never heard of it\n";
foreach (['lib/DishNetAiBrain.php', 'lib/DishNetTools.php', 'workers/AiReplyWorker.php',
          'lib/PublicPriceFeed.php', 'lib/KnowledgeBase.php'] as $f) {
    $src = (string)@file_get_contents($root . '/' . $f);
    is_(strpos($src, 'published_prices') === false, $f . ' quotes from uCRM only');
}

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
