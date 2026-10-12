<?php
declare(strict_types=1);

/**
 * InboxReplyRoute — which number a staff reply from the WhatsApp Inbox leaves on (5.18.86, docs/65 §A).
 *
 * Every Inbox send chose its sender with one line:
 *
 *     $sender = ($conv['channel'] ?? 'support') === 'accounts' ? 'accounts' : 'support';
 *
 * so a customer who wrote to the SALES number was answered from the SUPPORT number — in a different chat, from a
 * number they had never written to — and so was one who wrote to the accounts number, whose conversations are stored
 * as 'account', which is not 'accounts'.
 *
 * On Uganda a reply now leaves on the conversation's own channel:
 *
 *   sales, account, and any other channel id (a registry channel) → that channel's number through
 *       NotificationService::sendOnChannel(), or nothing at all: no other number, no WASender, no retry queue
 *   support   → sendVia('support'), exactly as before
 *   accounts  → sendVia('accounts'), exactly as before (the notification thread; it already left on the account number)
 *   web, marketing, '' → sendVia('support'), exactly as before (not conversations of an Evolution number)
 *
 * With the channel registry on, a department number the registry has switched off refuses the reply as well — support
 * and accounts included — rather than sending on it.
 *
 * Every other install (South Sudan) keeps the one line above, verbatim. PHP 7.4 compatible.
 */
final class InboxReplyRoute
{
    /** Uganda: channels that keep the department sender they always had, and the channel each sender sends on. */
    const SENDER_CHANNELS = ['support' => 'support', 'accounts' => 'accounts', 'web' => 'support',
                             'marketing' => 'support', '' => 'support'];
    const SENDER_NUMBER   = ['support' => 'support', 'accounts' => 'account'];

    /**
     * @return array{mode:string, sender:string, channel:string, reason:string}
     *   mode 'sender'  — send with sendVia($sender), as before;
     *   mode 'channel' — send with sendOnChannel($channel);
     *   mode 'refused' — send nothing; $reason says why, for the person in the Inbox
     */
    public static function decide(string $conversationChannel, array $config, ?string $dataDir, ?\PDO $pdo = null): array
    {
        $ch = trim($conversationChannel);
        if (!self::uganda($config, $dataDir)) {
            // The 5.18.85 rule, verbatim.
            return ['mode' => 'sender', 'sender' => $ch === 'accounts' ? 'accounts' : 'support', 'channel' => $ch, 'reason' => ''];
        }
        if (!array_key_exists($ch, self::SENDER_CHANNELS)) {
            return ['mode' => 'channel', 'sender' => '', 'channel' => $ch, 'reason' => ''];
        }
        $sender = self::SENDER_CHANNELS[$ch];
        // With the registry on, the department number this sender sends on must be active.
        if ($pdo !== null) {
            try {
                require_once __DIR__ . '/EvolutionApiService.php';
                $evo = EvolutionApiService::forStore($config, $pdo, $dataDir);
                $num = self::SENDER_NUMBER[$sender];
                $ctx = $evo->registryOn() ? $evo->channelContext($num) : null;
                if ($ctx !== null && !$ctx->isActive()) {
                    return ['mode' => 'refused', 'sender' => $sender, 'channel' => $ch,
                            'reason' => "Not sent — the {$num} number is switched off ({$ctx->status()}) in the channel registry."];
                }
            } catch (\Throwable $e) { /* the registry could not be asked: the sender route, as before */ }
        }
        return ['mode' => 'sender', 'sender' => $sender, 'channel' => $ch, 'reason' => ''];
    }

    /**
     * Send one Inbox message on the route decide() chose.
     *
     * A sender route calls exactly the NotificationService method the Inbox always called, with the same arguments, and
     * reports success whatever happened — the 5.18.85 behaviour, kept for support and accounts. A channel route sends on
     * the conversation's own number and reports what really happened; nothing is ever sent from another number.
     *
     * @param array  $route decide()'s answer, mode sender or channel
     * @param object $notify NotificationService
     * @param string $kind  text | image | document
     * @return array{ok:bool, error:string, channel:string, store:array} store: extra fields for the stored message
     */
    public static function send(array $route, $notify, string $kind, string $phone, string $text, string $event,
                                string $url = '', string $filename = ''): array
    {
        if (($route['mode'] ?? '') === 'channel') {
            $channel = (string)$route['channel'];
            $r = $notify->sendOnChannel($channel, $phone, $text, $event,
                                        $kind === 'text' ? null : ['type' => $kind, 'url' => $url, 'filename' => $filename]);
            if (empty($r['ok'])) return ['ok' => false, 'error' => self::failureText($r, $channel), 'channel' => $channel, 'store' => []];
            $id = (string)($r['wa_message_id'] ?? '');
            return ['ok' => true, 'error' => '', 'channel' => $channel, 'store' => $id !== '' ? ['wa_message_id' => $id] : []];
        }
        $sender = (string)($route['sender'] ?? 'support');
        if ($kind === 'image') {
            $notify->sendImage($sender, $phone, $url, $text, $event);
        } elseif ($kind === 'document') {
            $notify->sendDocument($sender, $phone, $url, $filename, $text, $event);
        } else {
            $notify->sendVia($sender, $phone, $text, $event);
        }
        return ['ok' => true, 'error' => '', 'channel' => $sender, 'store' => []];
    }

    /** What the person in the Inbox reads when a channel send did not go. Never a number, never an instance. */
    public static function failureText(array $r, string $channel): string
    {
        $why = trim((string)($r['error'] ?? ''));
        // Evolution's own error text can carry a path with the instance in it: say only the class of failure.
        if (!empty($r['maybe_sent'])) {
            return "May have been sent on the {$channel} number — WhatsApp did not answer. Check the chat before sending again.";
        }
        if (strpos($why, 'Not sent — ') === 0 || strpos($why, '[HTTP') !== false || strpos($why, 'Connection failed') === 0) {
            $why = 'WhatsApp did not accept it';
        }
        return "Not sent on the {$channel} number — " . ($why !== '' ? $why : 'the send failed') . '. Nothing was sent from any other number.';
    }

    private static function uganda(array $config, ?string $dataDir): bool
    {
        try {
            if (!class_exists('StaffJobsGate')) require_once __DIR__ . '/StaffJobsGate.php';
            return \StaffJobsGate::applies($config, $dataDir);
        } catch (\Throwable $e) {
            return false;
        }
    }
}
