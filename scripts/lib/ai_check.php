<?php
// ai_check.php — READ-ONLY. `scripts/dnb-ai-check.sh` pipes this into the ucrm container, with the plugin's
// directory as the working directory:   php -- <report|ask> [days]
//
// Two kinds of question, and what the WhatsApp assistant is given and says for each (docs/40):
//   · a customer running a BUSINESS who needs UNLIMITED data — a Residential plan, not a Business plan with a GB block;
//   · covering ANOTHER AREA — the outdoor access point and the MikroTik now priced in uCRM.
//
// report  spends nothing. Which assistant answers and with which switches; the price list exactly as the assistant
//         sees it on the sales number and on the others; the approved-knowledge rows on these topics and the part of
//         each the assistant never sees; and recent real conversations on these topics — the customer's message and
//         the reply they got.
// ask     spends a few model calls. The same questions put to the INSTALLED assistant through the live path — the
//         knowledge base, the sales number's context contract (BrainContext), the price check (ReplyPrivacyGuard) and
//         the Business-plan note (PlanFenceGuard) — each reply printed as the customer would receive it. Nothing is
//         sent to anyone.
//
// It writes nothing: it reads a COPY of the database that the calling script made as the database's owner (the
// journey audit's rule), settings are read with PluginConfig::read (no vault refresh), the price list is fetched with
// its cache off, and no message, lead, event or log line is stored. Phone numbers, e-mail addresses and kit numbers in
// any text are masked; no key, token or secret is printed.
// The last line, "@@ <mode> <ok|stop> …", is for the calling script.
declare(strict_types=1);
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED & ~E_NOTICE & ~E_WARNING);
ini_set('display_errors', '0');

$mode = (string)($argv[1] ?? 'report');
$days = max(1, min(365, (int)($argv[2] ?? 60)));
$root = (string)getcwd();

function hdr(string $t): void { echo "\n== {$t} ==\n"; }
function out(string $label, string $value): void { printf("  %-30s %s\n", $label, $value); }
function note(string $t): void { echo "  note  {$t}\n"; }
function stop(string $mode, string $why): void { echo "\n  STOP: {$why}\n@@ {$mode} stop\n"; exit(0); }
/** No phone number, e-mail address or kit number survives. Prices do: they are public. */
function mask(string $s): string {
    $s = (string)preg_replace('/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/', '{e-mail}', $s);
    $s = (string)preg_replace('/\bKIT[0-9A-Z]{4,}\b/i', '{kit}', $s);
    return (string)preg_replace('/(?<!\d)\+?\d[\d\s-]{8,}\d(?!\d)/', '{phone}', $s);
}
function clip(string $s, int $n): string {
    $s = trim((string)preg_replace('/\s+/u', ' ', $s));
    return mb_strlen($s) > $n ? mb_substr($s, 0, $n - 1) . '…' : $s;
}
function money($v): string { return number_format((float)$v, 0, '.', ','); }
function onOff(array $c, string $k): string { return filter_var($c[$k] ?? false, FILTER_VALIDATE_BOOLEAN) ? 'on' : 'off'; }

/** What a reply does, in the terms of this check. */
function traits(string $r): string {
    $t = [];
    if (preg_match('/\bbusiness\s*(?:50|500|1\s*tb)|\bbusiness\s+plans?\b/i', $r)) $t[] = 'names a Business plan';
    if (preg_match('/\bresidential\b/i', $r))                      $t[] = 'names Residential';
    if (preg_match('/\bunlimited\b/i', $r))                         $t[] = 'says "unlimited"';
    if (preg_match('/priority data|\b1\s*mbps/i', $r))              $t[] = 'explains the priority-data cap';
    if (preg_match('/access ?points?/i', $r))                       $t[] = 'access point';
    if (preg_match('/mikro ?tik/i', $r))                            $t[] = 'MikroTik';
    if (preg_match('/\brouter\s*(?:mini|3)\b|starlink routers?\b/i', $r)) $t[] = 'Starlink router';
    if (preg_match('/ruijie|rg-rap|outdoor (?:wi-?fi )?access points?/i', $r)) $t[] = 'the outdoor access point';
    if (preg_match('/survey|site (visit|assessment)|assess/i', $r)) $t[] = 'site survey';
    if (preg_match_all('/(?<![\d.,])\d{1,3}(?:,\d{3})+(?![\d,])/', $r, $m)) $t[] = 'amounts ' . implode(' · ', array_slice(array_unique($m[0]), 0, 6));
    if (class_exists('ReplyPrivacyGuard') && trim($r) === ReplyPrivacyGuard::SAFE_FALLBACK) $t[] = 'THE FALLBACK (a reply was refused)';
    elseif (preg_match('/colleague|our team will|team (will|to) (confirm|check|get back)|get back to you|someone from our team/i', $r)) $t[] = 'says the team will follow up';
    return $t ? implode(' · ', $t) : '—';
}

// ── The installed plugin ────────────────────────────────────────────────────
foreach (['bootstrap_data', 'PluginConfig', 'KnowledgeBase', 'CrmApiClient', 'ShopCatalogue', 'DishNetTools',
          'PlanCatalogue', 'PlanFenceGuard', 'ReplyPrivacyGuard', 'BrainContext', 'DishNetAiBrain',
          'EvolutionApiService', 'FlyerAsset', 'MediaLibrary', 'NetworkEquipment', 'EventBus'] as $lib) {
    if (is_file("{$root}/lib/{$lib}.php")) require_once "{$root}/lib/{$lib}.php";
}
// The worker's class, for its own catalogue preparation and price check (never constructed).
foreach (['workers/WorkerBase.php', 'workers/AiReplyWorker.php'] as $f) {
    if (is_file("{$root}/{$f}") && class_exists('EventBus')) require_once "{$root}/{$f}";
}
// 5.18.44 and later prepare the sales catalogue and the price check in one public place each.
$since44 = method_exists('AiReplyWorker', 'salesCatalogue') && method_exists('AiReplyWorker', 'permittedAmounts');
// 5.18.45 and later: the Starlink routers among the accessories, for more floors inside one building.
$since45 = $since44 && class_exists('NetworkEquipment') && method_exists('NetworkEquipment', 'starlinkRouters');
$man = json_decode((string)@file_get_contents("{$root}/manifest.json"), true) ?: [];
$version = (string)($man['information']['version'] ?? '?');
if (!function_exists('getDataDir') || !class_exists('PluginConfig') || !class_exists('DishNetAiBrain')) {
    stop($mode, "this is not the plugin's directory, or the plugin is incomplete ({$root})");
}
if (!method_exists('PluginConfig', 'read')) {
    stop($mode, "the installed plugin ({$version}) has no side-effect-free settings reader; this check needs 5.18.34 or later");
}
$dataDir = getDataDir($root);
$config  = PluginConfig::read($root, $dataDir);

// A COPY, never the live file. Opened even read-only, the live WAL database leaves SQLite's -wal and -shm files
// behind, owned by whoever opened it — root, on the server — and the plugin's own user may then be unable to write
// its database. Measured in the rehearsal (scripts/harness/ai-check).
$live   = $dataDir . '/plugin.sqlite3';
$dbFile = (string)(getenv('AI_CHECK_DB') ?: '');
if ($dbFile === '' || !is_file($dbFile)) stop($mode, 'no copy of the database was given (AI_CHECK_DB) — run this through scripts/dnb-ai-check.sh');
if (is_file($live) && realpath($dbFile) === realpath($live)) stop($mode, 'AI_CHECK_DB names the live database; this check reads a copy only');
try {
    $pdo = new PDO('sqlite:' . $dbFile, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('PRAGMA query_only = ON');
} catch (\Throwable $e) {
    stop($mode, 'the copy of the database could not be opened (' . get_class($e) . ')');
}

// The assistant's settings exactly as the live worker builds them (AiReplyWorker::__construct).
// 5.18.44 and later cut each approved answer at the limit the configuration sets (KnowledgeBase::answerLimit).
$kbLimit = method_exists('KnowledgeBase', 'answerLimit') ? KnowledgeBase::answerLimit($config) : 600;
$config['knowledge_block'] = class_exists('KnowledgeBase')
    ? (method_exists('KnowledgeBase', 'answerLimit') ? KnowledgeBase::promptBlock($pdo, $kbLimit) : KnowledgeBase::promptBlock($pdo)) : '';
if (class_exists('FlyerAsset') && FlyerAsset::find($config, $dataDir) !== null) $config['flyer_available'] = '1';
if (class_exists('MediaLibrary')) $config['photo_block'] = MediaLibrary::promptBlock($dataDir);

// The price list, as the worker fetches it, with the cache off so nothing is written.
$toolsCfg = $config; $toolsCfg['catalogue_cache_seconds'] = 0;
$tools = new DishNetTools(null, $toolsCfg, $root);
$cat   = $tools->getProducts();
$data  = !empty($cat['ok']) && is_array($cat['data']) ? $cat['data'] : null;

/** The context the live worker gives the brain: the sales number goes through BrainContext. */
$contextFor = function (string $channel, string $message, array $history) use ($data, $config): array {
    if ($channel === 'sales' && class_exists('BrainContext')) {
        if (method_exists('AiReplyWorker', 'salesCatalogue')) {
            $products = AiReplyWorker::salesCatalogue($data ?? [], $config);
        } else {
            $products = $data ?? [];
            $products['stock'] = (string)($config['stock_statement'] ?? '');
        }
        return BrainContext::build('unknown', ['customer' => null, 'channel' => 'sales', 'transport' => 'whatsapp',
            'medium' => '', 'products' => $products, 'message' => $message, 'history' => $history]);
    }
    $ctx = ['channel' => $channel, 'message' => $message, 'history' => $history, 'customer' => null];
    if ($data !== null) $ctx['products'] = $data;
    return $ctx;
};

echo "\n== DishNet AI check ({$mode}) — plugin {$version} — " . gmdate('Y-m-d H:i') . " UTC ==\n";

// ════════════════════════════════════════════════════════════════════════════
if ($mode === 'report') {
    hdr('1. Which assistant answers, and with which switches');
    $keySet = trim((string)($config[strtolower((string)($config['ai_provider'] ?? 'claude')) === 'openai' ? 'openai_api_key' : 'claude_api_key'] ?? '')) !== '';
    out('WhatsApp AI (ai_enabled)', onOff($config, 'ai_enabled'));
    out('provider / model', ((string)($config['ai_provider'] ?? 'claude') ?: 'claude') . ' · ' . ((string)($config['ai_model'] ?? '') ?: 'the default')
        . ' · key ' . ($keySet ? 'set (not shown)' : 'NOT SET'));
    if (trim((string)($config['shopbot_ai_url'] ?? '')) !== '') {
        note('shopbot_ai_url is set: replies come from an EXTERNAL brain, not the one this check reads (AiReplyWorker::askBrain)');
    }
    foreach (['ai_qualification' => 'qualify before recommending', 'ai_hardware_expert' => 'hardware advice block',
              'ai_sales_on_all_numbers' => 'sell on every number', 'ai_lead_capture' => 'record opportunities'] as $k => $what) {
        out($k, onOff($config, $k) . "  ({$what})");
    }
    $cap = trim((string)($config['ai_fact_business_cap'] ?? ''));
    out('Business-plan note', $cap === '' ? 'the default (PlanFenceGuard::DEFAULT_NOTE)' : (strtolower($cap) === 'omit' ? 'OFF (omit)' : 'your own wording: ' . clip(mask($cap), 160)));
    if (defined('DishNetAiBrain::UNLIMITED_FACT')) {
        $uf = trim((string)($config['ai_fact_unlimited'] ?? ''));
        out('"unlimited" fact', $uf === '' ? 'the default wording: ' . clip(DishNetAiBrain::UNLIMITED_FACT, 110)
            : (strtolower($uf) === 'omit' ? 'OFF (omit)' : 'your own wording: ' . clip(mask($uf), 110)));
    } else {
        out('"unlimited" fact', 'none — this version does not state it (5.18.44 does)');
    }
    $custom = trim((string)($config['bot_custom_instructions'] ?? ''));
    if ($custom === '') {
        out('extra instructions', 'none');
    } else {
        out('extra instructions', strlen($custom) . ' characters, mode ' . ((string)($config['bot_instructions_mode'] ?? 'append') ?: 'append'));
        foreach (preg_split('/\R/', $custom) ?: [] as $line) {
            if (preg_match('/unlimited|business|access ?point|outdoor|mikro ?tik|cover|hot ?spot|residential/i', $line)) {
                out('', '“' . clip(mask($line), 150) . '”');
            }
        }
    }
    // Whether the plugin's AI is actually the one replying: its own recent replies, per number.
    try {
        $q = $pdo->query("SELECT c.channel, COUNT(*) n, MAX(m.sent_at) last FROM wa_messages m JOIN wa_conversations c ON c.id = m.conversation_id
                           WHERE m.direction = 'out' AND m.agent_name = 'DishNet AI' AND m.sent_at >= datetime('now', '-7 days')
                           GROUP BY c.channel ORDER BY c.channel");
        $rows = $q->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if (!$rows) out('its replies, last 7 days', 'none recorded — check the WhatsApp numbers before reading anything else');
        foreach ($rows as $i => $r) out($i === 0 ? 'its replies, last 7 days' : '', sprintf('%-8s %4d  (last %s UTC)', $r['channel'], $r['n'], $r['last']));
    } catch (\Throwable $e) {
        out('its replies, last 7 days', 'not readable (' . get_class($e) . ')');
    }

    hdr('2. The price list as the assistant sees it (uCRM, fetched now)');
    if ($data === null) {
        note('the price list could not be fetched: ' . clip(mask((string)($cat['error'] ?? '?')), 120) . ' — the assistant quotes nothing while this lasts');
    } else {
        $plans = (array)($data['products'] ?? []);
        $bizCut = class_exists('PlanCatalogue')
            ? PlanCatalogue::forConversation($plans, ['message' => 'I run a business and need unlimited internet', 'history' => []])
            : ['products' => $plans];
        $shownForBusiness = array_map(fn($p) => (string)($p['name'] ?? ''), (array)$bizCut['products']);
        out('monthly plans', count($plans) . '   (★ = what a customer who says "business, unlimited" is shown)');
        foreach ($plans as $p) {
            $name = (string)($p['name'] ?? '?');
            printf("    %s %-44s %12s%s%s\n", in_array($name, $shownForBusiness, true) ? '★' : ' ', clip($name, 44),
                isset($p['price']) && $p['price'] !== null ? money($p['price']) : 'no price',
                !empty($p['period_months']) ? ' / ' . ((int)$p['period_months'] === 1 ? 'month' : $p['period_months'] . ' months') : '',
                !empty($p['data_limit']) ? '  · data limit ' . clip((string)$p['data_limit'], 20) : '  · no data limit in uCRM');
        }
        $hw = (array)($data['hardware'] ?? []);
        $hwOn = filter_var($config['ai_hardware_expert'] ?? false, FILTER_VALIDATE_BOOLEAN);
        if ($since44 && $hwOn && class_exists('NetworkEquipment')) {
            // 5.18.44: network equipment is its own block, with its role, and totals cover up to ten items.
            [$kit, $netRows] = NetworkEquipment::split($root, $hw);
            out('HARDWARE (one-time)', count($kit) . ' — the kit, the installation and other one-time items');
            foreach ($kit as $i => $h) {
                printf("    %2d. %-60s %12s\n", $i + 1, clip((string)($h['name'] ?? '?'), 60), isset($h['price']) && $h['price'] !== null ? money($h['price']) : 'no price');
            }
            out('NETWORK EQUIPMENT (one-time)', count($netRows) . ' — what the assistant designs a bigger-area setup from');
            foreach ($netRows as $i => $h) {
                printf("    %2d. %-60s %12s  ← %s\n", $i + 1, clip((string)($h['name'] ?? '?'), 60), isset($h['price']) && $h['price'] !== null ? money($h['price']) : 'no price',
                    str_replace('_', ' ', (string)$h['role_key']));
            }
            out('totals the price check allows', 'any combination of the first 10 one-time items, and 2 to 5 of one access point with any of the others'
                . ($since45 ? '; 1 to 5 of one Starlink router with any of the kit and the installation' : ''));
        } else {
            out('HARDWARE (one-time)', count($hw) . ' — both numbers see these; a total may combine only the first 6');
            foreach ($hw as $i => $h) {
                $n = (string)($h['name'] ?? '?');
                $tag = preg_match('/access ?point|outdoor|mikro ?tik|\bap\b|router|mesh|switch/i', $n) ? '  ← network equipment' : '';
                printf("    %2d. %-60s %12s%s%s\n", $i + 1, clip($n, 60), isset($h['price']) && $h['price'] !== null ? money($h['price']) : 'no price',
                    $i >= 6 ? '  · beyond the 6th: a total including it is REFUSED by the price check' : '', $tag);
            }
        }
        $acc = (array)($data['accessories'] ?? []);
        out('ACCESSORIES (optional extras)', count($acc) . (($since44 && $hwOn) ? ' — every number sees these'
            : ' — shown on the support and accounts numbers only; the sales number never sees them (BrainContext)'));
        foreach ($acc as $a) {
            $n = (string)($a['name'] ?? '?');
            if ($since44 && $hwOn && class_exists('NetworkEquipment')) {
                // The shop catalogue claims its own names first, so network equipment named there stays an accessory.
                $r = NetworkEquipment::roleFor($root, $n);
                if ($r !== null) printf("        %-44s %12s  ← named like a %s, but the shop lists it: an ACCESSORY, not in NETWORK EQUIPMENT\n",
                    clip($n, 44), isset($a['price']) ? money($a['price']) : 'no price', str_replace('_', ' ', $r['key']));
            } elseif (preg_match('/access ?point|outdoor|mikro ?tik|router|mesh/i', $n)) {
                printf("        %-44s %12s  ← network equipment\n", clip($n, 44), isset($a['price']) ? money($a['price']) : 'no price');
            }
        }
        if ($since45 && $hwOn) {
            // 5.18.45: the Starlink routers — what the assistant suggests for more floors inside one building.
            $routers = NetworkEquipment::starlinkRouters($root, $acc);
            out('STARLINK ROUTERS (indoor)', count($routers) . ' — among the accessories: what the assistant suggests for more floors inside one building');
            foreach ($routers as $r) {
                printf("        %-44s %12s  ← Starlink router · fits %s\n", clip((string)($r['name'] ?? '?'), 44),
                    isset($r['price']) ? money($r['price']) : 'no price', clip((string)($r['fits'] ?? ''), 70));
            }
            if (!$routers) note('no accessory is a Starlink router in the shop catalogue — the assistant has none to suggest for more floors');
        }
        out('plan copies dropped', (string)(int)($data['hardware_plan_mirrors'] ?? 0) . ' product(s) named like a plan');
        if (!empty($data['hardware_error'])) note('the one-time products could not be read: ' . clip(mask((string)$data['hardware_error']), 120));
        $net = array_filter(array_merge($hw, $acc), fn($x) => preg_match('/access ?point|outdoor|mikro ?tik/i', (string)($x['name'] ?? '')));
        if (!$net) note('no product named like an outdoor access point or a MikroTik is in uCRM Products — the assistant has no such price to quote');
    }

    hdr('3. Approved knowledge on these topics (knowledge base)');
    try {
        $rows = $pdo->query("SELECT item_key, kind, status, updated_by, answer, wa_answer FROM knowledge_items ORDER BY kind, id")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable $e) { $rows = []; note('the knowledge base could not be read (' . get_class($e) . ')'); }
    out('each answer reaches it up to', $kbLimit . ' characters' . ($kbLimit > 600 ? '' : ' (1,000 from 5.18.44, where ai_qualification is on)'));
    $shown = 0;
    foreach ($rows as $r) {
        $all = $r['answer'] . ' ' . $r['wa_answer'];
        if (!preg_match('/unlimited|business|access ?point|outdoor|mikro ?tik|hot ?spot|many users|cover/i', $all)) continue;
        $shown++;
        $a = trim((string)$r['answer']); $len = mb_strlen($a);
        $cut = $kbLimit;
        $by = (string)$r['updated_by'] === 'seed' ? 'as seeded' : 'edited';
        printf("  %-30s %s · %s · %s · %d chars%s\n", $r['item_key'], $r['kind'], $r['status'], $by, $len,
            ($r['kind'] === 'fact' && $len > $cut) ? " — CUT at {$cut}" : '');
        if ($r['kind'] === 'fact' && $len > $cut) {
            echo '      the assistant never sees: “…' . clip(mask(mb_substr($a, $cut)), 360) . "”\n";
        }
        if (preg_match('/unlimited/i', $a)) {
            $vis = $r['kind'] === 'fact' ? mb_substr($a, 0, $cut) : $a;
            echo '      "unlimited" reaches the assistant here: ' . (preg_match('/unlimited/i', $vis) ? 'yes' : 'NO — only in the cut part') . "\n";
        }
        // The operator, 27 Sep: when a Business plan's priority block is used up, about 1 Mbps until more is bought.
        $both = $a . ' ' . (string)$r['wa_answer'];
        if (preg_match('/standard data continues|behaves like standard data|then unlimited standard data/i', $both)) {
            echo "      after the priority block it says: unlimited standard data continues — NOT the approved fact (about 1 Mbps until more is bought)\n";
        } elseif (preg_match('/priority/i', $both) && preg_match('/\b1\s*Mbps/i', $both)) {
            echo "      after the priority block it says: about 1 Mbps until more is bought — the approved fact\n";
        }
    }
    if ($shown === 0) note('no approved row mentions these topics');

    hdr("4. Recent conversations on these topics (last {$days} days; phones, e-mails and kit numbers masked)");
    $topics = [
        'A business that wants unlimited data' => '/\b(unlimited|business|biashara|hot ?spot|wi-?fi (business|zone)|sell(ing)? (wi-?fi|internet|data|vouchers?)|cyber|internet caf|trading cent(re|er))/iu',
        'Covering another area'                => '/(access ?points?|outdoor|mikro ?tik|\bextend|mesh|repeater|booster|how far|distance|metres|meters|compound|(another|other|next|second) (area|areas|building|house|place|side|room|shop|block)|cover (the|my|other|another|more|whole|all|a bigger|bigger)|coverage area)/iu',
    ];
    try {
        $in = $pdo->prepare("SELECT m.id, m.conversation_id cid, m.body, m.sent_at, c.channel FROM wa_messages m JOIN wa_conversations c ON c.id = m.conversation_id
                              WHERE m.direction = 'in' AND m.sent_at >= datetime('now', ?) ORDER BY m.sent_at DESC, m.id DESC LIMIT 5000");
        $in->execute(['-' . $days . ' days']);
        $inbound = $in->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable $e) { $inbound = []; note('the conversations could not be read (' . get_class($e) . ')'); }
    $next = $pdo->prepare("SELECT body, role, agent_name, sent_at FROM wa_messages WHERE conversation_id = ? AND direction = 'out'
                            AND (sent_at > ? OR (sent_at = ? AND id > ?)) ORDER BY sent_at, id LIMIT 3");
    $nextIn = $pdo->prepare("SELECT sent_at FROM wa_messages WHERE conversation_id = ? AND direction = 'in'
                              AND (sent_at > ? OR (sent_at = ? AND id > ?)) ORDER BY sent_at, id LIMIT 1");
    $esc = null;
    try { $esc = $pdo->prepare("SELECT payload FROM events WHERE entity_type = 'conversation' AND entity_id = ? AND event_type = 'wa.escalation' AND created_at >= ? AND created_at <= ? ORDER BY id LIMIT 1"); }
    catch (\Throwable $e) { $esc = null; }
    $summary = [];
    foreach ($topics as $topic => $re) {
        echo "\n  ── {$topic}\n";
        $perConv = []; $n = 0; $ai = 0; $answered = 0;
        foreach ($inbound as $m) {
            if (!preg_match($re, (string)$m['body'])) continue;
            $cid = (int)$m['cid'];
            if (($perConv[$cid] ?? 0) >= 2) continue;
            $perConv[$cid] = ($perConv[$cid] ?? 0) + 1;
            if (++$n > 12) break;
            printf("  c%-5d %-8s %s UTC\n", $cid, (string)$m['channel'], substr((string)$m['sent_at'], 0, 16));
            echo '    customer     ' . clip(mask((string)$m['body']), 300) . "\n";
            $nextIn->execute([$cid, $m['sent_at'], $m['sent_at'], $m['id']]);
            $until = (string)($nextIn->fetchColumn() ?: '9999-12-31 23:59:59');
            $next->execute([$cid, $m['sent_at'], $m['sent_at'], $m['id']]);
            $replies = array_values(array_filter($next->fetchAll(PDO::FETCH_ASSOC) ?: [], fn($r) => (string)$r['sent_at'] <= $until));
            if (!$replies) { echo "    reply        none before the customer wrote again\n"; continue; }
            $answered++;
            foreach (array_slice($replies, 0, 2) as $r) {
                $agent = (string)$r['agent_name'];
                $who = $agent === 'DishNet AI' ? 'AI' : ($agent === 'WhatsApp auto-reply' ? 'auto-reply' : 'staff');
                if ($who === 'AI') $ai++;
                $body = mask((string)$r['body']);
                // A colleague is "staff", never a name — nor the name they signed the message with.
                if ($who === 'staff') foreach (preg_split('/\s+/', $agent) ?: [] as $part) {
                    if (mb_strlen($part) >= 3) $body = (string)preg_replace('/\b' . preg_quote($part, '/') . '\b/iu', '{staff}', $body);
                }
                echo '    reply (' . str_pad($who . ')', 10) . clip($body, 700) . "\n";
                echo '    what it did  ' . traits((string)$r['body']) . "\n";
            }
            if ($esc !== null) {
                $esc->execute([$cid, $m['sent_at'], $until]);
                $pl = json_decode((string)($esc->fetchColumn() ?: ''), true);
                if (is_array($pl)) echo '    handed over  yes — ' . clip(mask((string)($pl['reason'] ?? '')), 120) . "\n";
            }
        }
        if ($n === 0) echo "    none in the last {$days} days\n";
        $summary[] = min($n, 12) . ':' . $answered . ':' . $ai;
    }
    echo "\n@@ report ok " . implode(' ', $summary) . "\n";
    exit(0);
}

// ════════════════════════════════════════════════════════════════════════════
if ($mode === 'ask') {
    $brain = new DishNetAiBrain($config);
    if (!$brain->isConfigured()) stop('ask', 'no AI provider key is configured — nothing was asked');
    if ($data === null) stop('ask', 'the price list could not be fetched, so the answers would not be the ones customers get — nothing was asked');
    if (!class_exists('ReplyPrivacyGuard') || !class_exists('PlanFenceGuard')) stop('ask', 'the installed plugin lacks the reply checks this path needs');

    // The worker's own permitted-amounts list, so the price check here is the one customers go through.
    $permitted = null;
    if (method_exists('AiReplyWorker', 'permittedAmounts')) {
        $permitted = fn(array $ctx, string $prompt): array => AiReplyWorker::permittedAmounts($ctx, $prompt, $config);
    } elseif (class_exists('AiReplyWorker')) {
        if (method_exists('AiReplyWorker', 'permittedValues')) {
            $w = (new ReflectionClass('AiReplyWorker'))->newInstanceWithoutConstructor();
            $m = new ReflectionMethod('AiReplyWorker', 'permittedValues'); $m->setAccessible(true);
            $permitted = fn(array $ctx, string $prompt): array => $m->invoke($w, $ctx, $prompt);
        }
    }
    if ($permitted === null) stop('ask', "the installed worker's price check could not be reached — nothing was asked");

    $SCENARIOS = [
        'A1  a WiFi business asks for unlimited'  => ['sales', ['I want to start a WiFi business in my trading centre',
                                                                'About 50 people at a time. I need unlimited internet, which package do I take?']],
        'A2  "unlimited business plans?"'          => ['sales', ['Do you have unlimited business plans?']],
        'A3  names a Business plan'                => ['sales', ['How much is Business 500?']],
        'A4  wants to sell internet'               => ['sales', ['I want to sell internet to the people around my shop. Which package do I need?']],
        'B1  cover the area around a hotspot'      => ['sales', ['I have a wifi hotspot business and I want to cover other areas around my place, about 200 metres. What equipment do I need and how much?',
                                                                'OK. What would two outdoor access points and the MikroTik cost together?']],
        'B2  WiFi to another building'             => ['sales', ['I already have Starlink at home. How do I get the WiFi to my other building across the compound?']],
        'B3  the price of the two items'           => ['sales', ['How much is an outdoor access point and a MikroTik router?']],
        'B4  more floors inside one house'         => ['sales', ['The WiFi does not reach the upper floors of my house. It has 3 floors. What do I need and how much?']],
        'C1  a home total (nothing extra added?)'  => ['sales', ['How much will I pay to get Starlink installed at my home?']],
    ];
    // The amounts in a refused draft that the price check cannot match, each checked alone exactly as the reply was —
    // so a refusal can be read (docs/40 §13: two replies were refused on 27 Sep and the log could not say why).
    $foreignAmounts = function (string $raw, array $vals, string $prompt) use ($config): array {
        $out = [];
        if (preg_match_all('/(?<![\d.,])\d{1,3}(?:[,\s]\d{3})+(?:\.\d{1,2})?(?![\d])/', $raw, $m)) {
            foreach (array_unique($m[0]) as $amt) {
                $one = ReplyPrivacyGuard::check('TOTAL ' . trim($amt), ['values' => $vals, 'prompt' => $prompt,
                                                                      'public' => DishNetAiBrain::operatorText($config)]);
                if (in_array('foreign:amount', (array)$one['categories'], true)) $out[] = trim((string)preg_replace('/\s+/', ' ', $amt));
            }
        }
        return $out;
    };
    $calls = 0; $blocked = 0; $fenced = 0; $tokIn = 0; $tokOut = 0;
    hdr('The questions, asked of the installed assistant on the sales number — nothing is sent to anyone');
    foreach ($SCENARIOS as $name => [$channel, $turns]) {
        echo "\n  ── {$name}\n";
        $history = [];
        foreach ($turns as $say) {
            $ctx = $contextFor($channel, $say, $history);
            $res = $brain->reply($ctx); $calls++;
            $u = $brain->getLastUsage() ?: []; $tokIn += (int)($u['input_tokens'] ?? 0); $tokOut += (int)($u['output_tokens'] ?? 0);
            $prompt = $brain->lastSystemPrompt();
            $raw = trim((string)($res['reply'] ?? ''));
            $final = $raw; $how = []; $refused = null;
            if ($raw !== '') {
                $vals = $permitted($ctx, $prompt);
                $g = ReplyPrivacyGuard::check($raw, ['values' => $vals, 'prompt' => $prompt,
                                                     'public' => DishNetAiBrain::operatorText($config)]);
                if (empty($g['safe'])) {
                    $final = ReplyPrivacyGuard::SAFE_FALLBACK; $blocked++;
                    $how[] = 'the price check REFUSED the reply (' . implode(',', (array)$g['categories']) . ') — the customer gets the fallback and staff are alerted';
                    // Only for an amount: the model's own text for a made-up question, masked like every reply here.
                    if ((array)$g['categories'] === ['foreign:amount']) $refused = ['amounts' => $foreignAmounts($raw, $vals, $prompt), 'draft' => $raw];
                } else {
                    $f = PlanFenceGuard::apply($raw, $config);
                    if (!empty($f['appended'])) { $final = $f['reply']; $fenced++; $how[] = 'the Business-plan note was added'; }
                }
            }
            if (!empty($res['escalate'])) $how[] = 'hands over to staff: ' . clip(mask((string)($res['escalate_reason'] ?? '')), 100);
            // What the model actually saw: the brain applies PlanCatalogue to the catalogue in the context, so this does too.
            $all  = (array)($ctx['products']['products'] ?? []);
            $seen = class_exists('PlanCatalogue') ? (array)PlanCatalogue::forConversation($all, $ctx)['products'] : $all;
            $plans = array_map(fn($p) => (string)($p['name'] ?? ''), $seen);
            $shownBiz = count(array_filter($plans, fn($n) => class_exists('PlanCatalogue') && PlanCatalogue::isBusinessPlan(['name' => $n])));
            echo '    customer     ' . clip($say, 300) . "\n";
            echo '    AI replies   ' . ($final === '' ? '(nothing — handed over)' : (string)preg_replace('/\n(?=[^\n])/', "\n                 ", mask($final))) . "\n";
            echo '    what it did  ' . traits($final) . "\n";
            if ($how) echo '    on the way   ' . implode(' · ', $how) . "\n";
            if ($refused !== null) {
                echo '    refused      ' . ($refused['amounts'] ? 'amounts it could not match to the price list: ' . implode(' · ', $refused['amounts'])
                                                              : 'no single amount — only together') . "\n";
                $dr = mb_strlen($refused['draft']) > 900 ? mb_substr($refused['draft'], 0, 899) . '…' : $refused['draft'];
                echo '    the draft    ' . (string)preg_replace('/\n(?=[^\n])/', "\n                 ", mask($dr)) . "\n";
            }
            echo '    it was shown ' . count($plans) . ' plan(s), ' . ($shownBiz > 0 ? "including {$shownBiz} Business" : 'no Business plan') . "\n";
            $history[] = ['role' => 'customer', 'text' => $say];
            $history[] = ['role' => 'dishnet',  'text' => $final];
        }
    }
    printf("\n  %d model call(s) · tokens in %d, out %d · %d refused by the price check · %d with the Business-plan note added\n",
        $calls, $tokIn, $tokOut, $blocked, $fenced);
    echo "@@ ask ok {$calls} {$blocked} {$fenced}\n";
    exit(0);
}

stop($mode, 'unknown mode (report or ask)');
