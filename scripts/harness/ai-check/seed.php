<?php
// seed.php — build the rehearsal's plugin database: migrations, the knowledge seed, and conversations that carry
// canaries (a phone, an e-mail, a kit number, a staff name, an event's phone) which the check must never print.
//   php seed.php <plugin dir> <data dir>
declare(strict_types=1);
[$_, $plugin, $data] = $argv;
foreach (['bootstrap_data', 'StoreInterface', 'JsonStore', 'SqliteStore', 'KnowledgeSeeder'] as $l) require_once "{$plugin}/lib/{$l}.php";
$store = SqliteStore::create($data);
$pdo   = $store->getPdo();
$seed  = json_decode((string)file_get_contents("{$plugin}/tools/knowledge_seed.json"), true);
KnowledgeSeeder::apply($pdo, $seed['items'], false);

$ago = fn(int $days, int $min = 0): string => gmdate('Y-m-d H:i:s', time() - $days * 86400 + $min * 60);
$conv = function (string $phone, string $channel) use ($pdo): int {
    $pdo->prepare("INSERT INTO wa_conversations (phone, channel) VALUES (?, ?)")->execute([$phone, $channel]);
    return (int)$pdo->lastInsertId();
};
$msg = function (int $cid, string $dir, string $role, string $body, string $at, ?string $agent = null) use ($pdo): void {
    $pdo->prepare("INSERT INTO wa_messages (conversation_id, direction, role, body, agent_name, sent_at) VALUES (?, ?, ?, ?, ?, ?)")
        ->execute([$cid, $dir, $role, $body, $agent, $at]);
};
// c1, sales: a WiFi business asking for unlimited, then how to cover other areas; the AI names a Business plan only,
// and a colleague (a canary name) answers the second.
$c1 = $conv('256772123456', 'sales');
$msg($c1, 'in',  'customer',  'Hi, I want to start a wifi business, do you have unlimited internet? my number is +256 772 123 456', $ago(3));
$msg($c1, 'out', 'assistant', 'Yes — Business 500GB is UGX 285,000 a month with 500 GB of priority data.', $ago(3, 1), 'DishNet AI');
$msg($c1, 'in',  'customer',  'and how do I cover the other areas around my place? email me at someone@example.com', $ago(3, 5));
$msg($c1, 'out', 'agent',     'We have an Outdoor Access Point at UGX 450,000 — Richard', $ago(3, 9), 'Richard Canary');
// c2, support: WiFi to another building; the reply was refused by the price check and the thread handed over.
$c2 = $conv('256700999888', 'support');
$msg($c2, 'in',  'customer',  'I need the wifi to reach my other building in the compound, kit KIT3040X1234', $ago(5));
$msg($c2, 'out', 'assistant', "I'm not able to complete that one automatically. I've passed it to our team and someone will get back to you shortly.", $ago(5, 1), 'DishNet AI');
$pdo->prepare("INSERT INTO events (event_type, entity_type, entity_id, payload, created_at) VALUES ('wa.escalation', 'conversation', ?, ?, ?)")
    ->execute([$c2, json_encode(['channel' => 'support', 'phone' => '256700999888', 'reason' => 'reply blocked by guard: foreign:amount']), $ago(5, 1)]);
// c3, sales: the same topic, but older than the window — must not be shown.
$c3 = $conv('256711000333', 'sales');
$msg($c3, 'in', 'customer', 'OLDCANARY do you have an unlimited business plan?', $ago(100));
// c4: nothing on these topics.
$c4 = $conv('256711000444', 'sales');
$msg($c4, 'in', 'customer', 'hello', $ago(1));
// c5: five matching questions in one conversation — at most two are shown.
$c5 = $conv('256711000555', 'sales');
for ($i = 1; $i <= 5; $i++) $msg($c5, 'in', 'customer', "REPEATCANARY {$i}: is it unlimited?", $ago(2, $i * 10));
$pdo = null; $store = null;
echo "seeded\n";
