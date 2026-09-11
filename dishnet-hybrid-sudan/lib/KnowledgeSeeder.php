<?php
declare(strict_types=1);

/**
 * KnowledgeSeeder — load approved knowledge rows into knowledge_items.
 *
 * Two rules, and the second one is the whole reason this is a class rather
 * than a handful of statements in a CLI script.
 *
 * 1. A missing row is added.
 * 2. An existing row is corrected ONLY when it is still exactly as seeded.
 *    The moment an operator edits a row in the admin tab, their wording is the
 *    approved wording and no later run may touch it.
 *
 * Rule 2 exists in both directions. Insert-or-ignore alone could never fix a
 * row we shipped wrong — TBC_SLA_STATIC_IP told the AI that public IP
 * availability had no approved answer while BUSINESS_PLANS stated a public IP
 * as a feature of Business, so the model was instructed to answer and to refuse
 * the same question and took the safer branch. That correction had to be able
 * to reach installs that already had the bad row. But a correction that
 * silently overwrote an operator's own text would be a worse bug than the one
 * it fixed, so an edited row is reported and skipped instead.
 */
class KnowledgeSeeder
{
    /**
     * @param array $items  rows from knowledge_seed.json
     * @param bool  $refresh  also correct rows still marked updated_by='seed'
     * @return array{added:int,kept:int,corrected:array<string>,protected:array<string>}
     */
    public static function apply(\PDO $pdo, array $items, bool $refresh = false): array
    {
        $ins = $pdo->prepare(
            "INSERT OR IGNORE INTO knowledge_items (item_key, kind, title, answer, wa_answer, updated_by)
             VALUES (?,?,?,?,?, 'seed')"
        );
        $upd = $pdo->prepare(
            "UPDATE knowledge_items SET kind=?, title=?, answer=?, wa_answer=?
              WHERE item_key=? AND updated_by='seed'"
        );
        $cur = $pdo->prepare(
            "SELECT kind, title, answer, wa_answer, updated_by FROM knowledge_items WHERE item_key=?"
        );

        $added = 0; $kept = 0; $corrected = []; $protected = [];

        foreach ($items as $it) {
            if (!is_array($it) || !isset($it['item_key'])) continue;
            $key = (string)$it['item_key'];
            $row = [
                (string)($it['kind']      ?? 'fact'),
                (string)($it['title']     ?? $key),
                (string)($it['answer']    ?? ''),
                (string)($it['wa_answer'] ?? ''),
            ];

            $ins->execute(array_merge([$key], $row));
            if ($ins->rowCount()) { $added++; continue; }
            $kept++;
            if (!$refresh) continue;

            $cur->execute([$key]);
            $live = $cur->fetch(\PDO::FETCH_ASSOC) ?: [];
            if ((string)($live['kind'] ?? '')      === $row[0]
             && (string)($live['title'] ?? '')     === $row[1]
             && (string)($live['answer'] ?? '')    === $row[2]
             && (string)($live['wa_answer'] ?? '') === $row[3]) {
                continue;                       // already correct, nothing to do
            }
            if ((string)($live['updated_by'] ?? '') !== 'seed') {
                $protected[] = $key;            // a human wrote this — leave it
                continue;
            }
            $upd->execute(array_merge($row, [$key]));
            if ($upd->rowCount()) $corrected[] = $key;
        }

        return ['added' => $added, 'kept' => $kept,
                'corrected' => $corrected, 'protected' => $protected];
    }
}
