<?php
require_once dirname(__DIR__) . '/lib/KnowledgeBase.php';

$pass=0; $fail=0;
function t(string $n, $got, $want){ global $pass,$fail;
  if ($got===$want){$pass++;printf("  ok   %s\n",$n);}
  else{$fail++;printf("  FAIL %s\n       got  %s\n       want %s\n",$n,var_export($got,true),var_export($want,true));}}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

echo "Fails safe without the table\n";
t('no table -> empty block (legacy behaviour)', KnowledgeBase::promptBlock($pdo), '');

$pdo->exec(file_get_contents(dirname(__DIR__) . '/migrations/064_knowledge_base.sql'));
t('empty table -> empty block', KnowledgeBase::promptBlock($pdo), '');

echo "\nSeed file loads and is Uganda-correct\n";
$seed = json_decode(file_get_contents(dirname(__DIR__) . '/tools/knowledge_seed.json'), true);
$ins = $pdo->prepare("INSERT INTO knowledge_items (item_key, kind, title, answer, wa_answer) VALUES (?,?,?,?,?)");
foreach ($seed['items'] as $it) {
    $ins->execute([$it['item_key'], $it['kind'] ?? 'fact', $it['title'] ?? $it['item_key'],
                   $it['answer'] ?? '', $it['wa_answer'] ?? '']);
}
$block = KnowledgeBase::promptBlock($pdo);
t('office fact present', str_contains($block, 'Mawanda Road'), true);
t('office fact carries the landmark', str_contains($block, 'Family Shoppers Super Market'), true);
t('no Sudan office leaks into Uganda knowledge', str_contains($block, 'Juba'), false);
t('flex gate rule present', str_contains($block, 'Flex'), true);
t('tbc topics listed', str_contains($block, 'refund policy'), true);
t('holding line included verbatim', str_contains($block, KnowledgeBase::HOLDING_LINE), true);
t('master policy preamble present', str_contains($block, 'DISHNET MASTER POLICY'), true);

echo "\nEditing once changes the block (the whole point)\n";
$pdo->exec("UPDATE knowledge_items SET answer='We moved to Plot 9, Test Street, Kampala.', wa_answer='📍 Plot 9, Test Street, Kampala' WHERE item_key='OFFICE_LOCATION'");
$block2 = KnowledgeBase::promptBlock($pdo);
t('new answer flows through', str_contains($block2, 'Plot 9, Test Street'), true);
t('old answer gone', str_contains($block2, 'Mawanda Road'), false);

$pdo->exec("UPDATE knowledge_items SET status='disabled' WHERE item_key='OFFICE_LOCATION'");
t('disabled rows drop out', str_contains(KnowledgeBase::promptBlock($pdo), 'Plot 9'), false);

echo "\nBrain integration: knowledge supersedes the legacy Sudan facts\n";
require_once dirname(__DIR__) . '/lib/DishNetAiBrain.php';
$mk = function(array $cfg) {
    $b = new DishNetAiBrain($cfg);
    $m = new ReflectionMethod($b, 'buildSystemPrompt');
    $m->setAccessible(true);
    return $m->invoke($b, ['channel' => 'sales', 'transport' => 'whatsapp', 'message' => 'hi']);
};
$legacy = $mk([]);
t('without knowledge: legacy Sudan facts remain (Sudan installs unaffected)', str_contains($legacy, 'Juba'), true);
$modern = $mk(['knowledge_block' => $block]);
t('with knowledge: Uganda facts in the prompt', str_contains($modern, 'Mawanda Road'), true);
t('with knowledge: Sudan facts fully superseded', str_contains($modern, 'Juba'), false);
t('with knowledge: absolute rules still present', str_contains($modern, 'ABSOLUTE RULES'), true);

echo "\nCross-channel prior-contact rendering\n";
$b = new DishNetAiBrain(['knowledge_block' => $block]);
$m = new ReflectionMethod($b, 'buildSystemPrompt'); $m->setAccessible(true);
$withLead = $m->invoke($b, ['channel'=>'sales','transport'=>'whatsapp','message'=>'hi',
    'webchat_lead'=>['name'=>'John','topic'=>'lodge in Fort Portal, 12 rooms']]);
t('prior website contact is announced to the AI', str_contains($withLead, 'previously chatted on our WEBSITE'), true);
t('lead name carried', str_contains($withLead, 'John'), true);
t('lead topic carried', str_contains($withLead, 'Fort Portal'), true);

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail ? 1 : 0);
