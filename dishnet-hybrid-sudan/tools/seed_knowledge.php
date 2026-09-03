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
 * CLI only.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }

$root = dirname(__DIR__);
require_once $root . '/lib/bootstrap_data.php';
require_once $root . '/lib/StoreInterface.php';
require_once $root . '/lib/JsonStore.php';
require_once $root . '/lib/SqliteStore.php';

$store = SqliteStore::create(getDataDir($root));   // runs migrations, incl. 064
$pdo   = $store->getPdo();

$seed = json_decode((string)file_get_contents(__DIR__ . '/knowledge_seed.json'), true);
$items = $seed['items'] ?? [];
if (!$items) exit("knowledge_seed.json has no items\n");

$ins = $pdo->prepare(
    "INSERT OR IGNORE INTO knowledge_items (item_key, kind, title, answer, wa_answer, updated_by)
     VALUES (?,?,?,?,?, 'seed')"
);
$added = 0; $kept = 0;
foreach ($items as $it) {
    $ins->execute([
        (string)$it['item_key'],
        (string)($it['kind'] ?? 'fact'),
        (string)($it['title'] ?? $it['item_key']),
        (string)($it['answer'] ?? ''),
        (string)($it['wa_answer'] ?? ''),
    ]);
    $ins->rowCount() ? $added++ : $kept++;
}
printf("knowledge seed: %d added, %d already present (left untouched)\n", $added, $kept);
