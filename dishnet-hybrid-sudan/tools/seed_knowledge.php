<?php
declare(strict_types=1);
/**
 * seed_knowledge.php — load tools/knowledge_seed.json into knowledge_items.
 *
 * Idempotent by design: an item_key that already exists is NEVER overwritten,
 * so anything the operator has edited in the admin tab always wins over the
 * seed. Run once after deploying migration 064 (and again any time — it only
 * fills gaps):
 *
 *   docker exec ucrm php /data/ucrm/data/plugins/dishnet-hybrid-sudan/tools/seed_knowledge.php
 *
 * --refresh-seeded additionally corrects rows that are STILL AS SEEDED
 * (updated_by='seed'), leaving every operator-edited row alone:
 *
 *   docker exec ucrm php /data/ucrm/data/plugins/dishnet-hybrid-sudan/tools/seed_knowledge.php --refresh-seeded
 *
 * That flag exists because a seeded row can be wrong. TBC_SLA_STATIC_IP put
 * "public/static IP availability" on the never-improvise list while
 * BUSINESS_PLANS stated a public IP as a feature of Business — so the AI was
 * told to answer and to refuse the same question, and took the safer branch.
 * Insert-or-ignore could never have corrected that, and asking an operator to
 * hand-edit a row to fix our own mistake is not a fix.
 *
 * The one thing it will not do is overwrite a human. An operator's wording is
 * the approved wording; if they have touched a row, the correction is reported
 * and skipped so someone can apply it deliberately.
 *
 * CLI only.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }

$root = dirname(__DIR__);
require_once $root . '/lib/bootstrap_data.php';
require_once $root . '/lib/StoreInterface.php';
require_once $root . '/lib/JsonStore.php';
require_once $root . '/lib/SqliteStore.php';
require_once $root . '/lib/KnowledgeSeeder.php';

$store = SqliteStore::create(getDataDir($root));   // runs migrations, incl. 064
$pdo   = $store->getPdo();

$seed = json_decode((string)file_get_contents(__DIR__ . '/knowledge_seed.json'), true);
$items = $seed['items'] ?? [];
if (!$items) exit("knowledge_seed.json has no items\n");

$refresh = in_array('--refresh-seeded', $argv, true);

$r = KnowledgeSeeder::apply($pdo, $items, $refresh);

foreach ($r['corrected'] as $k) echo "  corrected: {$k}\n";
printf("knowledge seed: %d added, %d already present, %d corrected\n",
       $r['added'], $r['kept'], count($r['corrected']));

if (!$refresh) {
    echo "(run with --refresh-seeded to also correct rows still as seeded)\n";
}
if ($r['protected']) {
    echo "\nEdited by hand, so left exactly as they are — apply these yourself if you want them:\n";
    foreach ($r['protected'] as $k) echo "  - {$k}\n";
}
