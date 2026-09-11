<?php
/**
 * test_knowledge_seed.php — the knowledge base must not argue with itself.
 *
 * BUSINESS_PLANS said, as an approved fact, that DishNet Business includes a
 * public IP. TBC_SLA_STATIC_IP said "business SLA terms and public/static IP
 * availability" had no approved answer, and tbc rows carry the strongest
 * instruction in the whole prompt: never improvise, reply only with the holding
 * line, escalate.
 *
 * Both were in front of the model on every sales message. Told to answer and to
 * refuse the same question, it took the safer branch and hedged — on business
 * enquiries, which are the most valuable ones we get.
 *
 * The first half of this file is the guard against that returning. The second
 * half covers --refresh-seeded, which exists because insert-or-ignore could
 * never have corrected a wrong seeded row, and which must never overwrite an
 * operator's own wording.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$pass = 0; $fail = 0;
function ok(string $m): void  { global $pass; $pass++; echo "  ok   {$m}\n"; }
function bad(string $m, string $d=''): void { global $fail; $fail++; echo "  FAIL {$m}\n"; if($d)echo "       {$d}\n"; }
function is_(bool $c, string $m, string $d=''): void { $c ? ok($m) : bad($m, $d); }

$seed = json_decode((string)file_get_contents($root . '/tools/knowledge_seed.json'), true);
$items = $seed['items'] ?? [];
$by = [];
foreach ($items as $r) $by[$r['item_key']] = $r;

echo "\nThe seed is loadable and populated\n";
is_(count($items) > 20, count($items) . ' rows');
is_(count($by) === count($items), 'every item_key is unique',
    'a duplicate key would silently lose one row to INSERT OR IGNORE');

echo "\nNothing on the never-answer list contradicts an approved fact\n";
// The regression guard. A tbc row silences a topic completely, so a topic
// answered by a fact must never also appear as tbc.
$tbcText = '';
foreach ($items as $r) {
    if (($r['kind'] ?? '') === 'tbc') $tbcText .= ' ' . strtolower((string)($r['title'] ?? ''));
}
foreach (['public ip', 'static ip', 'public/static ip'] as $topic) {
    is_(strpos($tbcText, $topic) === false,
        'no tbc row silences "' . $topic . '"',
        'BUSINESS_PLANS and PUBLIC_IP answer this — a tbc row would override both');
}

echo "\nSLA terms are still unapproved, and still refused\n";
// Loosening the public-IP topic must not have loosened the one next to it:
// uptime guarantees and signed contracts genuinely have no approved answer.
is_(($by['TBC_SLA_STATIC_IP']['kind'] ?? '') === 'tbc', 'the SLA row is still tbc');
$slaTitle = strtolower((string)($by['TBC_SLA_STATIC_IP']['title'] ?? ''));
is_(strpos($slaTitle, 'uptime') !== false, 'and it still covers guaranteed uptime');
is_(strpos($slaTitle, 'contract') !== false, 'and signed contracts');

echo "\nPublic IP is answerable, and answered correctly\n";
$pip = strtolower((string)($by['PUBLIC_IP']['answer'] ?? ''));
is_(($by['PUBLIC_IP']['kind'] ?? '') === 'fact', 'PUBLIC_IP is an approved fact');
is_(strpos($pip, 'business') !== false, 'it names Business as where the public IP is');
is_(strpos($pip, 'not with residential') !== false || strpos($pip, 'cgnat') !== false,
    'and is explicit that Residential will not do it');
is_(strpos($pip, 'live catalogue') !== false, 'while still taking the price from the catalogue',
    'a knowledge row must never become a second price list');

echo "\nThe objections a Ugandan customer actually raises are covered\n";
foreach (['OBJECTION_TOO_EXPENSIVE'  => 'price is too high',
          'OBJECTION_IMPORT_MYSELF'  => 'I will import it myself',
          'OBJECTION_DISCOUNT'       => 'can I have a discount',
          'OBJECTION_CHEAPER_KIT'    => 'someone is cheaper',
          'INSTALLATION_SCOPE'       => 'what installation includes'] as $key => $what) {
    is_(isset($by[$key]), 'there is an approved answer for: ' . $what);
}

echo "\nAnd none of them invents authority we do not have\n";
$disc = strtolower((string)($by['OBJECTION_DISCOUNT']['answer'] ?? ''));
is_(strpos($disc, 'no authority to discount') !== false, 'discounts are never offered by the AI');
$imp = strtolower((string)($by['OBJECTION_IMPORT_MYSELF']['answer'] ?? ''));
is_(strpos($imp, 'never claim an import is illegal') !== false,
    'and importing is never called illegal — we make no regulatory claims');
$inst = strtolower((string)($by['INSTALLATION_SCOPE']['answer'] ?? ''));
is_(strpos($inst, 'not every site') !== false,
    'the standard installation price is not promised for every site');

echo "\nNo knowledge row carries a price\n";
// uCRM is the source of truth. A number typed into a knowledge row is a second
// price list that nobody remembers to update.
foreach ($items as $r) {
    $body = (string)($r['answer'] ?? '') . ' ' . (string)($r['wa_answer'] ?? '');
    // "UGX 0 upfront" is the Flex proposition, not a catalogue price.
    $body = str_replace('UGX 0', '', $body);
    if (preg_match('/UGX\s*[\d,]{4,}/i', $body, $m)) {
        bad('no price in ' . $r['item_key'], 'found: ' . $m[0]);
    }
}
ok('no catalogue price is hardcoded in any knowledge row');

// ══════════════════════════════════════════════════════════════════════════
//  --refresh-seeded, against a real database
// ══════════════════════════════════════════════════════════════════════════
require_once $root . '/lib/KnowledgeSeeder.php';

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec("CREATE TABLE knowledge_items (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    item_key TEXT UNIQUE, kind TEXT, title TEXT, answer TEXT, wa_answer TEXT,
    status TEXT DEFAULT 'approved', updated_by TEXT)");

$rows = [
    ['item_key' => 'A_SEEDED',  'kind' => 'fact', 'title' => 'right', 'answer' => 'right'],
    ['item_key' => 'B_EDITED',  'kind' => 'fact', 'title' => 'ours',  'answer' => 'ours'],
];

echo "\nA first run adds everything\n";
$r = KnowledgeSeeder::apply($pdo, $rows);
is_($r['added'] === 2, 'both rows added');
is_($r['kept'] === 0 && !$r['corrected'], 'nothing kept or corrected on an empty table');

echo "\nRunning it again changes nothing\n";
$r = KnowledgeSeeder::apply($pdo, $rows);
is_($r['added'] === 0 && $r['kept'] === 2, 'idempotent — the original guarantee still holds');

echo "\nWithout the flag, a wrong seeded row stays wrong\n";
// The behaviour that made the public-IP contradiction unfixable in the field.
$pdo->exec("UPDATE knowledge_items SET answer='wrong' WHERE item_key='A_SEEDED'");
$r = KnowledgeSeeder::apply($pdo, $rows);
$get = fn(string $k, string $col) => $pdo->query(
    "SELECT {$col} FROM knowledge_items WHERE item_key='{$k}'")->fetchColumn();
is_($get('A_SEEDED', 'answer') === 'wrong', 'insert-or-ignore cannot correct it',
    'this is why --refresh-seeded had to exist');

echo "\nWith the flag, it is corrected\n";
$r = KnowledgeSeeder::apply($pdo, $rows, true);
is_($get('A_SEEDED', 'answer') === 'right', 'the seeded row is put back to the approved text');
is_($r['corrected'] === ['A_SEEDED'], 'and it is reported, not corrected silently');

echo "\nBut an operator's own wording is never overwritten\n";
// The line this must not cross. Their text is the approved text.
$pdo->exec("UPDATE knowledge_items SET answer='the operator wrote this', updated_by='admin'
             WHERE item_key='B_EDITED'");
$r = KnowledgeSeeder::apply($pdo, $rows, true);
is_($get('B_EDITED', 'answer') === 'the operator wrote this',
    'the edited row is left exactly as the operator left it');
is_($r['protected'] === ['B_EDITED'], 'and it is named, so the change can be applied deliberately');
is_(!in_array('B_EDITED', $r['corrected'], true), 'it is never counted as corrected');

echo "\nA row that already matches is not touched or reported\n";
$r = KnowledgeSeeder::apply($pdo, $rows, true);
is_(!$r['corrected'], 'no churn on an already-correct row',
    'a report that cries wolf every run stops being read');

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
