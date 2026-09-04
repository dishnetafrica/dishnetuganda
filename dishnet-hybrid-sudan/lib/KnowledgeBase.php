<?php
declare(strict_types=1);

/**
 * KnowledgeBase — the single source of approved answers for every
 * customer-facing AI channel.
 *
 * Both the WhatsApp AI and the website chat run the same DishNetAiBrain;
 * this class renders the knowledge_items table into one prompt block that
 * callers pass in as config['knowledge_block']. Update a row once (admin →
 * Knowledge Base) and every channel answers the new way on its next message.
 *
 * Three kinds of row, three effects on the AI:
 *   fact — "answer this topic from here, exactly"
 *   rule — a conduct rule appended to the non-negotiables
 *   tbc  — "no approved answer exists: use the holding line and escalate"
 *
 * Live commercial data (prices, plans, balances) is deliberately NOT here —
 * it comes from uCRM through DishNetTools at conversation time. The
 * knowledge base is for stable approved facts and conduct, never numbers
 * that live in billing.
 *
 * Fails safe: no table, empty table, or a broken PDO yields '' and the brain
 * falls back to its built-in behaviour.
 */
class KnowledgeBase
{
    public const HOLDING_LINE =
        "I don't want to give you incorrect information. Let me confirm with our team and come back to you today.";

    /** Fetch approved rows grouped by kind. */
    public static function load(\PDO $pdo): array
    {
        $out = ['fact' => [], 'rule' => [], 'tbc' => []];
        try {
            $rows = $pdo->query(
                "SELECT item_key, kind, title, answer, wa_answer FROM knowledge_items
                  WHERE status='approved' ORDER BY kind, id"
            )->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            return $out;   // table absent (migration not run) — behave as before
        }
        foreach ($rows as $r) {
            $k = in_array($r['kind'], ['fact', 'rule', 'tbc'], true) ? $r['kind'] : 'fact';
            $out[$k][] = $r;
        }
        return $out;
    }

    /** The prompt block injected into the shared system prompt. */
    public static function promptBlock(\PDO $pdo): string
    {
        $kb = self::load($pdo);
        if (!$kb['fact'] && !$kb['rule'] && !$kb['tbc']) return '';

        $p = "DISHNET MASTER POLICY: every DishNet channel (WhatsApp, website chat, and any future one) "
           . "answers from this same knowledge base, the same live billing catalogue, and the same rules. "
           . "Never answer from general knowledge where an approved answer or an open topic below applies.\n";

        if ($kb['fact']) {
            $p .= "\nAPPROVED KNOWLEDGE — answer these topics from here, exactly and only:\n";
            foreach ($kb['fact'] as $f) {
                $ans = trim($f['answer']);
                $p .= "- [" . $f['item_key'] . "] " . $f['title'] . ": " . mb_substr($ans, 0, 600) . "\n";
                $short = trim($f['wa_answer']);
                if ($short !== '') $p .= "  (short form for chat: " . mb_substr($short, 0, 300) . ")\n";
            }
        }

        if ($kb['rule']) {
            $p .= "\nCONDUCT RULES (in addition to your absolute rules):\n";
            foreach ($kb['rule'] as $i => $r) {
                $p .= "- " . trim($r['answer'] !== '' ? $r['answer'] : $r['title']) . "\n";
            }
        }

        if ($kb['tbc']) {
            $topics = array_map(fn($t) => $t['title'], $kb['tbc']);
            $p .= "\nTOPICS WITH NO APPROVED ANSWER YET — for these, never improvise: reply only with "
                . "\"" . self::HOLDING_LINE . "\" and hand over "
                . "(<<ESCALATE topic>>): " . implode('; ', $topics) . ".\n";
        }

        return trim($p);
    }
}
