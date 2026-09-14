<?php
declare(strict_types=1);

require_once __DIR__ . '/WaInbound.php';

/**
 * WaMessageProcessor — the one pipeline, whichever transport delivered.
 *
 * Before this, wa_webhook.php and cron_wa_sync.php each decided for themselves
 * what to store, whether to reply, and what to do about media. The two had
 * drifted in ways nobody chose: the webhook discarded captions, the cron never
 * acknowledged anything. A security fix applied to one would have missed the
 * other, which is the real danger — not that the behaviours differ, but that
 * nobody can tell they differ until it matters.
 *
 * Now the entry points do three things: receive, normalise, hand over. Every
 * decision after that is made here, once.
 *
 *     webhook ─┐
 *              ├─→ WaInbound::normalise() ─→ WaMessageProcessor::process()
 *     cron ────┘                                    │
 *                                                   ├─ idempotency
 *                                                   ├─ conversation
 *                                                   ├─ store + media metadata
 *                                                   ├─ identity  (CustomerIdentity)
 *                                                   ├─ AI        (minimal context,
 *                                                   │             controlled tools)
 *                                                   ├─ guard     (ReplyPrivacyGuard)
 *                                                   └─ send
 *
 * Items 1–5 all live below that fork, so neither transport can be the weaker
 * path: there is only one path.
 *
 * ── MEDIA IS RECOGNISED, NOT PROCESSED ──────────────────────────────────
 *
 * Voice, image and document understanding are later phases. What happens here
 * is that media is received, normalised, stored with the metadata those phases
 * will need, and acknowledged honestly — the same acknowledgement on both
 * transports. A caption travels with it, because a caption is the customer's
 * question and dropping it is how a question becomes a bare photo.
 */
final class WaMessageProcessor
{
    /** What we say for media we cannot yet read. One text per modality. */
    private const UNSUPPORTED = [
        'audio'    => "We got your voice note. We can't listen to audio automatically yet — "
                    . "could you type a short message about what you need? Someone will also listen to it.",
        'image'    => "Thanks for the photo. We can't read images automatically yet — "
                    . "could you describe what we're looking at? Our team will review it too.",
        'video'    => "Thanks for the video. Could you also type a brief description? Our team will review it.",
        'document' => "Got your document. What is it about? Our team will review it.",
        'location' => "Got your location. Are you asking about an installation or a site visit?",
        'contact'  => "Got the contact. What would you like us to do with it?",
        'sticker'  => null,   // answered with silence, deliberately
        'other'    => "Thanks — we've received that. Could you also type what you need?",
    ];

    private $convSvc;
    private $autoReply;
    private $notify;
    private array $config;

    public function __construct($convSvc, $autoReply, $notify, array $config)
    {
        $this->convSvc   = $convSvc;
        $this->autoReply = $autoReply;
        $this->notify    = $notify;
        $this->config    = $config;
    }

    /**
     * Handle one normalised message.
     *
     * @param array $msg from WaInbound::normalise()
     * @return array{handled:bool, action:string, conversation_id:int, replied:bool,
     *               modality:string, duplicate:bool}
     */
    public function process(array $msg): array
    {
        $done = static fn(string $action, array $x = []): array => array_merge([
            'handled' => false, 'action' => $action, 'conversation_id' => 0,
            'replied' => false, 'modality' => (string)($msg['modality'] ?? 'text'),
            'duplicate' => false,
        ], $x);

        if (empty($msg['usable'])) {
            return $done('ignored:' . (string)($msg['why'] ?: 'unusable'));
        }
        if (($msg['direction'] ?? 'in') !== 'in') {
            return $done('ignored:outbound');
        }

        $phone   = (string)$msg['phone'];
        $channel = (string)$msg['channel'];

        // ── conversation and storage ─────────────────────────────────────
        $conv = $this->convSvc->ensureConversation(
            $phone, $channel, ($msg['push_name'] ?: null), (string)$msg['source']);
        $convId = (int)($conv['id'] ?? 0);

        // Idempotency. storeMessage returns null when this wa_message_id is
        // already present, which is what makes a message delivered by BOTH
        // transports produce one conversation row, one reply and one audit
        // event rather than two of each.
        $stored = $this->convSvc->storeMessage($convId, [
            'direction'     => 'in',
            'role'          => 'customer',
            'body'          => WaInbound::storableBody($msg),
            'media_type'    => $msg['has_media'] ? (string)$msg['modality'] : null,
            'media_url'     => (string)($msg['media_ref'] ?? '') ?: null,
            'wa_message_id' => (string)($msg['message_id'] ?? '') ?: null,
            // storeMessage json_encodes this itself — passing a string double-encodes it.
            'metadata'      => WaInbound::mediaMetadata($msg),
            'sent_at'       => (string)$msg['received_at'],
        ]);
        if ($stored === null && ($msg['message_id'] ?? '') !== '') {
            // Seen before. Nothing else runs: no second reply, no second alert.
            return $done('duplicate', ['conversation_id' => $convId, 'duplicate' => true]);
        }

        // ── are we allowed to answer at all ──────────────────────────────
        if (empty($this->config['wa_bot_enabled']) && empty($this->config['wa_auto_reply_enabled'])) {
            return $done('logged:bot_disabled', ['handled' => true, 'conversation_id' => $convId]);
        }
        if ($channel === 'accounts' && empty($this->config['wa_accounts_autoreply_enabled'])) {
            return $done('logged:accounts_autoreply_off', ['handled' => true, 'conversation_id' => $convId]);
        }

        // ── media we cannot yet read ─────────────────────────────────────
        //
        // Identical on both transports, which is the whole point. The customer
        // is told the truth rather than left in silence, a person is alerted,
        // and no model is asked to interpret something it was not given.
        if ($msg['has_media'] && trim((string)$msg['text']) === '') {
            $ack = self::UNSUPPORTED[(string)$msg['modality']] ?? self::UNSUPPORTED['other'];
            if ($ack === null) {
                return $done('media:ignored', ['handled' => true, 'conversation_id' => $convId]);
            }
            $this->say($phone, $ack, $channel, $convId);
            $this->alertTeam($msg, $phone);
            return $done('media:unsupported', ['handled' => true, 'conversation_id' => $convId,
                                               'replied' => true]);
        }

        // ── the secured path: identity, tools, model, guard ──────────────
        //
        // Media WITH a caption comes through here too. The caption is the
        // customer's question and is answered as text; the picture is not
        // interpreted, and the reply says nothing about it.
        $result = $this->autoReply->handleIncoming(
            $phone, (string)$msg['text'], $channel, ($msg['push_name'] ?: null), $convId);

        if ($msg['has_media']) $this->alertTeam($msg, $phone);

        return $done('ai:' . (string)($result['action'] ?? 'unknown'), [
            'handled'         => true,
            'conversation_id' => $convId,
            'replied'         => (bool)($result['replied'] ?? false),
        ]);
    }

    /** Send, and record what was sent, so the thread stays complete. */
    private function say(string $phone, string $text, string $channel, int $convId): void
    {
        try {
            if ($this->notify && method_exists($this->notify, 'sendRaw')) {
                $this->notify->sendRaw($phone, $text, 'wa_media_ack');
            }
            $this->convSvc->storeMessage($convId, [
                'direction' => 'out', 'role' => 'agent', 'body' => $text,
                'agent_name' => 'DishNet Bot', 'sent_at' => gmdate('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            error_log('[WaProcessor] ack not sent: ' . $e->getMessage());
        }
    }

    /**
     * Tell a person that media arrived.
     *
     * Deliberately names the modality and the sender and nothing else — not
     * the caption, not the media reference. An alert is a prompt to go and
     * look, not a copy of the content.
     */
    private function alertTeam(array $msg, string $phone): void
    {
        try {
            if (!$this->notify || !method_exists($this->notify, 'sendAdmin')) return;
            $who = trim((string)($msg['push_name'] ?? '')) ?: $phone;
            $this->notify->sendAdmin(
                "📎 " . strtoupper((string)$msg['modality']) . " received from {$who} ({$phone})"
                . "\nvia " . (string)$msg['source'] . " — please review in the WA Inbox.",
                'wa_media_received');
        } catch (\Throwable $e) {
            error_log('[WaProcessor] admin alert failed: ' . $e->getMessage());
        }
    }
}
