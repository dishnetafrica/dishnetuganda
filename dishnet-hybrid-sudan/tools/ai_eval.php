<?php
declare(strict_types=1);
/**
 * ai_eval.php — the consistency test: the SAME questions, asked of the SAME
 * brain, on BOTH transports (whatsapp + web), scored against approved
 * expectations. This is how you KNOW every channel follows the playbook,
 * instead of hoping.
 *
 *   docker exec ucrm php .../tools/ai_eval.php              # all questions, both transports
 *   docker exec ucrm php .../tools/ai_eval.php --limit=5    # quick smoke
 *
 * Costs real model calls (one per question per transport) — run deliberately,
 * not on cron. Questions live in tools/ai_eval_questions.json:
 *   { "q": "...", "channel": "sales|support|account",
 *     "must_contain": ["..."], "must_not_contain": ["..."],
 *     "expect_escalate": true, "must_contain_live_price": "DishNet Home" }
 * must_contain_live_price resolves the CURRENT uCRM price at runtime, so the
 * eval never hardcodes a number.
 *
 * CLI only. Requires ai provider keys in kyc_config.json.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }

$root = dirname(__DIR__);
foreach (['error_handler','bootstrap_data','StoreInterface','JsonStore','SqliteStore',
          'PluginConfig','KnowledgeBase','DishNetTools','DishNetAiBrain'] as $lib) {
    require_once $root . "/lib/{$lib}.php";
}

$limit = 0;
foreach ($argv as $a) if (preg_match('/^--limit=(\d+)$/', $a, $m)) $limit = (int)$m[1];

$dataDir = getDataDir($root);
$store   = SqliteStore::create($dataDir);
$config  = PluginConfig::load($root, $dataDir);
$config['knowledge_block'] = KnowledgeBase::promptBlock($store->getPdo());

$brain = new DishNetAiBrain($config);
if (!$brain->isConfigured()) exit("AI provider key not configured — set claude_api_key or openai_api_key.\n");

$tools    = new DishNetTools($store, $config, $root);
$catalog  = $tools->getProducts();
$products = $catalog['ok'] ? $catalog['data'] : null;

$questions = json_decode((string)file_get_contents(__DIR__ . '/ai_eval_questions.json'), true)['questions'] ?? [];
if ($limit > 0) $questions = array_slice($questions, 0, $limit);
if (!$questions) exit("no questions\n");

/** current live price for a named plan/product, formatted with thousands. */
$livePrice = function (string $name) use ($products): ?string {
    if (!$products) return null;
    foreach (array_merge((array)($products['plans'] ?? []), (array)($products['hardware'] ?? []),
                         (array)($products['products'] ?? [])) as $p) {
        if (strcasecmp(trim((string)($p['name'] ?? '')), trim($name)) === 0 && isset($p['price'])) {
            return number_format((float)$p['price']);
        }
    }
    return null;
};

$score = ['whatsapp' => ['pass' => 0, 'fail' => 0], 'web' => ['pass' => 0, 'fail' => 0]];
$failures = [];

foreach ($questions as $i => $q) {
    foreach (['whatsapp', 'web'] as $transport) {
        $ctx = [
            'channel'   => (string)($q['channel'] ?? 'sales'),
            'transport' => $transport,
            'message'   => (string)$q['q'],
            'history'   => [],
            'customer'  => null,
        ];
        if ($products) $ctx['products'] = $products;

        $r = $brain->reply($ctx);
        $text = (string)($r['reply'] ?? '');
        $esc  = !empty($r['escalate']);

        $problems = [];
        foreach ((array)($q['must_contain'] ?? []) as $needle) {
            if (mb_stripos($text, $needle) === false) $problems[] = "missing \"{$needle}\"";
        }
        foreach ((array)($q['must_not_contain'] ?? []) as $needle) {
            if (mb_stripos($text, $needle) !== false) $problems[] = "must NOT contain \"{$needle}\"";
        }
        if (!empty($q['must_contain_live_price'])) {
            $price = $livePrice((string)$q['must_contain_live_price']);
            if ($price === null) $problems[] = "live price for {$q['must_contain_live_price']} unavailable";
            elseif (mb_stripos(str_replace(' ', '', $text), str_replace(' ', '', $price)) === false
                 && mb_stripos($text, $price) === false) $problems[] = "missing live price {$price}";
        }
        if (!empty($q['expect_escalate']) && !$esc) $problems[] = 'expected escalation, none happened';

        if ($problems) {
            $score[$transport]['fail']++;
            $failures[] = sprintf("[%s #%d] %s\n    -> %s\n    reply: %s",
                $transport, $i + 1, $q['q'], implode('; ', $problems), mb_substr($text, 0, 220));
        } else {
            $score[$transport]['pass']++;
        }
    }
}

echo "\nDISHNET AI CONSISTENCY REPORT\n=============================\n";
foreach ($score as $t => $s) {
    $total = $s['pass'] + $s['fail'];
    printf("%-9s %d/%d passed (%.1f%%)\n", $t, $s['pass'], $total, $total ? 100 * $s['pass'] / $total : 0);
}
if ($failures) {
    echo "\nFAILURES\n--------\n" . implode("\n\n", $failures) . "\n";
}
exit($failures ? 1 : 0);
