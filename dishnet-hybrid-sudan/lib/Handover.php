<?php
declare(strict_types=1);

/**
 * Handover — hand a conversation to a person, the one way the plugin does it.
 *
 * This is AiReplyWorker::escalate() moved into a library class (Batch 2 of the AI communication layer, docs/55 §9,
 * docs/56 §5), so that the media worker can hand a voice note it could not transcribe to a person through EXACTLY the
 * same path as an unanswerable typed message: the thread goes red for the team (`needs_human`), a `wa.escalation`
 * event is queued, the alert number buzzes (30-minute cooldown per conversation), and the customer gets the
 * operator's own holding line once — if one is configured, if they were not just answered, and if it was not just said.
 * Nothing here is new in kind; the statements are the worker's, in the worker's order. PHP 7.4 compatible.
 */
final class Handover
{
    /**
     * @param callable $log function (string $level, string $message): void — the caller's logger
     * @param string   $source who queued the wa.escalation event ('ai_reply_worker', 'media_worker')
     */
    public static function escalate(\PDO $pdo, EventBus $bus, $store, array $config, EvolutionApiService $evo,
                                    ConversationService $convSvc, int $convId, string $channel, string $phone,
                                    string $reason, bool $alreadyAnswered, callable $log,
                                    string $source = 'ai_reply_worker'): void
    {
        $log('info', "conv {$convId}: HANDOFF to human — {$reason}");
        try {
            if ($convId > 0) {
                $pdo->prepare(
                    "UPDATE wa_conversations SET state = 'needs_human', updated_at = datetime('now') WHERE id = ?"
                )->execute([$convId]);
            }
            $bus->emit('wa.escalation', 'conversation', $convId, [
                'channel' => $channel,
                'phone'   => $phone,
                'reason'  => $reason,
            ], 2, $source);

            // Tell a person now. The Inbox tab turning red only works if
            // someone is looking at it; the phone in their pocket always is.
            // 30-minute cooldown per conversation, so a customer who trips
            // escalation three times in a row is one buzz, not three.
            if (!class_exists('AlertService')) require_once __DIR__ . '/AlertService.php';
            $alerts = new \AlertService($store, $config, $evo);
            $alerts->notify(
                'escalate:conv:' . $convId,
                "🔴 DishNet: the AI needs a human for {$phone} ({$channel})"
                . ($reason !== '' ? " — {$reason}" : '') . '. Open Engage → WhatsApp → Inbox.',
                30
            );

            // ── And tell the customer ────────────────────────────────────
            // A handoff sent them nothing at all. The team gets a buzz, the
            // Inbox turns red, and the person who asked the question hears
            // silence — indistinguishable from being ignored. Six
            // conversations were sitting like that, one of them since nine
            // that morning.
            //
            // Empty by default: an installation that has not set a line keeps
            // the old behaviour exactly.
            $holding = trim((string)($config['ai_handover_message'] ?? ''));
            if ($holding !== '' && $phone !== '' && !$alreadyAnswered && !self::alreadySaid($pdo, $convId, $holding)) {
                if (!class_exists('ContactOptOut')) require_once __DIR__ . '/ContactOptOut.php';
                $send = $evo->sendText($channel, $phone, $holding, \ContactOptOut::CLASS_REPLY);
                if (!empty($send['ok'])) {
                    if ($convId > 0) {
                        $convSvc->storeMessage($convId, [
                            'direction'     => 'out',
                            'role'          => 'assistant',
                            'body'          => $holding,
                            'agent_name'    => 'DishNet AI',
                            'wa_message_id' => (string)($send['data']['key']['id'] ?? '') ?: null,
                            'metadata'      => json_encode(['channel' => $channel, 'handover' => true]),
                        ]);
                    }
                } else {
                    $log('warn', "conv {$convId}: handover line not sent: "
                        . (string)($send['error'] ?? '?'));
                }
            }
        } catch (\Throwable $e) {
            $log('error', 'escalation failed: ' . $e->getMessage());
        }
    }

    /**
     * Have we said this already in the last few turns?
     *
     * A customer who trips the handoff three times running should hear it
     * once. The team alert has a 30-minute cooldown for the same reason, and
     * repeating a holding line at somebody already waiting reads worse than
     * saying nothing.
     *
     * On any error it answers false: a duplicate is a smaller failure than
     * another silence.
     */
    public static function alreadySaid(\PDO $pdo, int $convId, string $text): bool
    {
        if ($convId <= 0) return false;
        try {
            $stmt = $pdo->prepare(
                "SELECT body FROM wa_messages
                  WHERE conversation_id = ? AND direction = 'out'
                  ORDER BY id DESC LIMIT 3"
            );
            $stmt->execute([$convId]);
            foreach ((array)$stmt->fetchAll(\PDO::FETCH_COLUMN) as $b) {
                if (trim((string)$b) === trim($text)) return true;
            }
        } catch (\Throwable $e) { /* fall through */ }
        return false;
    }
}
