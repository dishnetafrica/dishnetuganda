<?php
declare(strict_types=1);

/**
 * AiReplyWorker — turns a queued inbound WhatsApp message into a reply.
 *
 * Consumes 'ai.reply' events emitted by evo_webhook_v2.php. This is where the
 * slow work lives, so the webhook can answer Evolution immediately.
 *
 * Per message:
 *   1. identify the customer from their number
 *   2. gather the context this channel's role needs
 *   3. ask ShopBot's AI for a reply
 *   4. send it through Evolution on the same number the customer used
 *
 * Retry safety: the Evolution send is the LAST thing that happens, and once it
 * has succeeded this method never throws. A retry after a successful send
 * would message the customer twice, which is worse than losing a log line.
 *
 * PHP 7.4 compatible.
 */
class AiReplyWorker extends WorkerBase
{
    /** wa_messages.media_url tag marking a flyer send, for the cooldown query. */
    const FLYER_MEDIA_TAG = 'flyer:plans';

    private EvolutionApiService $evo;
    private DishNetTools $tools;
    private DishNetAiBrain $brain;
    private $convSvc;
    /** @var array|null FlyerAsset::find() result, resolved once per run */
    private $flyer;

    public function __construct($store, array $config, int $maxRun = 55, int $batch = 10)
    {
        parent::__construct($store, $config, $maxRun, $batch);

        $root = dirname(__DIR__);
        require_once $root . '/lib/EvolutionApiService.php';
        require_once $root . '/lib/DishNetTools.php';
        require_once $root . '/lib/ConversationService.php';
        require_once $root . '/lib/DishNetAiBrain.php';
        require_once $root . '/lib/KnowledgeBase.php';
        require_once $root . '/lib/FlyerAsset.php';

        // Same override the tools and crons honour, so tests and one-shots can
        // point the worker at their own data directory.
        $dataDir = getenv('DN_DATA_DIR') ?: getDataDir($root);

        $this->evo   = new EvolutionApiService($config);
        $this->tools = new DishNetTools($store, $config, $root);
        // One brain, one knowledge base: the same approved answers the website
        // chat uses ride into the shared system prompt. Empty (legacy) when
        // migration 064 has not been seeded.
        $config['knowledge_block'] = KnowledgeBase::promptBlock($store->getPdo());
        // Only when an image actually exists is the model offered <<FLYER>> —
        // otherwise the prompt is unchanged and the AI cannot promise an
        // attachment nothing would send.
        $this->flyer = FlyerAsset::find($config, $dataDir);
        if ($this->flyer !== null) {
            $config['flyer_available'] = '1';
        }
        $this->brain = new DishNetAiBrain($config);
        $this->config = $config;

        $this->convSvc = new ConversationService($dataDir, $this->pdo);
    }

    protected function getEventTypes(): array
    {
        return ['ai.reply'];
    }

    protected function handle(array $event): void
    {
        $p       = $event['_payload'] ?? [];
        $channel = (string)($p['channel'] ?? '');
        $phone   = (string)($p['customer_phone'] ?? '');
        $message = (string)($p['message'] ?? '');
        $convId  = (int)($event['entity_id'] ?? 0);

        if ($channel === '' || $phone === '' || $message === '') {
            $this->log('warn', 'ai.reply event missing required fields — dropping');
            return;   // not retryable; a retry cannot make the payload valid
        }

        // A human has taken this conversation — stay out of it.
        if ($convId > 0 && $this->humanIsHandling($convId)) {
            $this->log('info', "conv {$convId}: human active, skipping AI");
            return;
        }

        // One line per message: enough to trace the pipeline, no content.
        $this->log('info', sprintf('conv %d: in channel=%s len=%d', $convId, $channel, mb_strlen($message)));

        // Let the customer see something is happening while the model thinks.
        $this->evo->sendTyping($channel, $phone);

        $context = $this->buildContext($channel, $phone, $message, $p, $convId);

        $ai = $this->askBrain($context);
        if ($ai === null) {
            // Throw: EventBus retries with backoff. The customer has not been
            // messaged yet, so a retry is safe.
            throw new \RuntimeException('AI brain did not return a reply');
        }

        $reply = trim((string)($ai['reply'] ?? ''));
        if ($reply === '') {
            $this->log('warn', 'AI returned an empty reply — escalating instead');
            $this->escalate($convId, $channel, $phone, 'AI produced no reply');
            return;
        }

        // ── Point of no return ───────────────────────────────────────────
        $send = $this->evo->sendText($channel, $phone, $reply);
        if (!$send['ok']) {
            throw new \RuntimeException('Evolution send failed: ' . $send['error']);
        }

        // Everything past here is bookkeeping. Never throw — the customer has
        // already received the message and must not receive it again.
        try {
            // Claim our own echo in the webhook's dedupe table, first thing.
            //
            // Evolution posts every outbound message back as fromMe, and the
            // webhook now reads an unrecognised fromMe message as a colleague
            // typing on the handset — which stands the AI down. Our own reply
            // must never look like that. Storing it below already dedupes it,
            // but the echo is a separate HTTP request that could in principle
            // arrive first; claiming the id here means it is dropped at the
            // webhook's idempotency check before it can be misread.
            $ourId = (string)($send['data']['key']['id'] ?? '');
            if ($ourId !== '') {
                try {
                    if (!class_exists('EvoWebhookGuard')) {
                        $g = __DIR__ . '/../lib/EvoWebhookGuard.php';
                        if (is_file($g)) require_once $g;
                    }
                    if (class_exists('EvoWebhookGuard')) {
                        (new \EvoWebhookGuard($this->pdo, $this->config))
                            ->claim($ourId, (string)($p['whatsapp_instance'] ?? ''), 'ai.reply');
                    }
                } catch (\Throwable $e) { /* dedupe is a backstop, not a requirement */ }
            }

            if ($convId > 0) {
                $this->convSvc->storeMessage($convId, [
                    'direction'  => 'out',
                    'role'       => 'assistant',
                    'body'       => $reply,
                    'agent_name' => 'DishNet AI',
                    // Evolution echoes every outbound message back through the
                    // webhook, including this one. storeMessage dedupes on
                    // wa_message_id, so recording the id Evolution just gave us
                    // is what stops the echo landing as a second copy.
                    'wa_message_id' => (string)($send['data']['key']['id'] ?? '') ?: null,
                    'metadata'   => json_encode(['channel' => $channel]),
                ]);
            }
            // After the text, so a retry of a failed text send can never have
            // already delivered the image once.
            if (!empty($ai['send_flyer'])) {
                $this->maybeSendFlyer($convId, $channel, $phone);
            }
            // The sales record. After the send, inside the never-throw zone:
            // a CRM failure must never cost the customer their reply, and the
            // service refuses anything that has not established a requirement.
            if (!empty($ai['lead']) && is_array($ai['lead'])) {
                try {
                    if (!class_exists('AiLeadService')) {
                        $f = __DIR__ . '/../lib/AiLeadService.php';
                        if (is_file($f)) require_once $f;
                    }
                    if (class_exists('AiLeadService')) {
                        $svc = new \AiLeadService($this->store, $this->config, $this->pdo);
                        $r   = $svc->capture($ai['lead'], $phone, $convId, 'whatsapp_ai');
                        $this->log($r['ok'] ? 'info' : 'info', sprintf(
                            'conv %d: lead %s%s', $convId, $r['action'],
                            $r['reason'] !== '' ? ' — ' . $r['reason'] : ' #' . (int)$r['lead_id']
                        ));
                    }
                } catch (\Throwable $e) {
                    $this->log('warn', 'lead capture failed: ' . $e->getMessage());
                }
            }

            if (!empty($ai['escalate'])) {
                // The customer already has a reply. Passing true stops the holding
                // line being sent on top of it: c109 received "I can't provide
                // final pricing without checking with our team" and "Let me get a
                // colleague to confirm that for you" in the same second — two
                // apologies for one question.
                $this->escalate($convId, $channel, $phone,
                    (string)($ai['escalate_reason'] ?? 'AI requested handover'), true);
            }
        } catch (\Throwable $e) {
            $this->log('error', 'post-send bookkeeping failed: ' . $e->getMessage());
        }
    }

    /**
     * Build the envelope ShopBot receives.
     *
     * Context is scoped by role: the sales number never needs a balance, and
     * the account number never needs the product catalogue. Fetching less is
     * both faster and a smaller disclosure surface.
     */
    private function buildContext(string $channel, string $phone, string $message, array $p, int $convId): array
    {
        $ctx = [
            'channel'           => $channel,
            'whatsapp_instance' => (string)($p['whatsapp_instance'] ?? ''),
            'customer_phone'    => $phone,
            'message'           => $message,
            'push_name'         => (string)($p['push_name'] ?? ''),
            'conversation_id'   => $convId,
            'customer'          => null,
            'history'           => [],
        ];

        // Identity is shared across all three numbers.
        $id = $this->tools->identifyCustomerByPhone($phone);
        if (!$id['ok']) {
            $this->log('warn', 'conv ' . $convId . ': identity lookup failed — '
                . (string)($id['error'] ?? 'unknown'));
        }
        $identified = false;
        $clientId   = 0;
        if ($id['ok'] && !empty($id['data']['found'])) {
            $ctx['customer'] = $id['data']['customer'];
            $clientId   = (int)($id['data']['customer']['id'] ?? 0);
            $identified = $clientId > 0;
            if ($convId > 0 && $identified) {
                try {
                    $this->convSvc->linkToCrm($convId, $clientId, (string)($id['data']['customer']['name'] ?? ''));
                } catch (\Throwable $e) { /* non-fatal */ }
            }
        } elseif ($id['ok'] && ($id['data']['reason'] ?? '') === 'ambiguous') {
            // Several customers share this number's last digits. Say so rather
            // than picking one — the AI must ask a verifying question.
            $ctx['identity_ambiguous'] = true;
        }

        // Cross-channel memory: an anonymous phone that previously chatted on
        // the website (web_chat_leads) is greeted as a returning contact
        // instead of being asked everything again.
        if (!$identified) {
            try {
                $digits = preg_replace('/\D+/', '', $phone) ?? '';
                $last9  = strlen($digits) >= 9 ? substr($digits, -9) : $digits;
                if ($last9 !== '') {
                    foreach (($this->store->load('web_chat_leads.json') ?? []) as $wl) {
                        $lp = preg_replace('/\D+/', '', (string)($wl['phone'] ?? '')) ?? '';
                        if (strlen($lp) >= 9 && substr($lp, -9) === $last9) {
                            $ctx['webchat_lead'] = [
                                'name'  => (string)($wl['name'] ?? ''),
                                'topic' => (string)($wl['note'] ?? ($wl['topic'] ?? ($wl['message'] ?? ''))),
                            ];
                            break;
                        }
                    }
                }
            } catch (\Throwable $e) { /* memory is a bonus, never a blocker */ }
        }

        switch ($channel) {
            case EvolutionApiService::CHANNEL_SALES:
                $products = $this->tools->getProducts();
                if ($products['ok']) {
                    $ctx['products'] = $products['data'];
                    $this->log('info', sprintf('conv %d: catalogue loaded, %d plan(s), %d hardware item(s)',
                        $convId, (int)($products['data']['count'] ?? 0),
                        (int)($products['data']['hardware_count'] ?? 0)));
                    if (!empty($products['data']['hardware_error'])) {
                        $this->log('warn', 'conv ' . $convId . ': hardware lookup failed — '
                            . (string)$products['data']['hardware_error']);
                    }
                } else {
                    // The brain falls back to "PLANS unavailable" and hands
                    // over — safe, but it must never be invisible in the log.
                    $this->log('error', 'conv ' . $convId . ': product lookup FAILED — '
                        . (string)($products['error'] ?? 'unknown') . ' (AI will not quote prices)');
                }
                break;

            case EvolutionApiService::CHANNEL_SUPPORT:
                if ($identified) {
                    $svc = $this->tools->getCustomerServices($clientId);
                    if ($svc['ok']) $ctx['services'] = $svc['data'];
                }
                // Live line status works from the phone number alone, so it is
                // still useful for a customer we could not identify in UCRM.
                $line = $this->tools->getLineStatus($phone);
                if ($line['ok']) $ctx['line_status'] = $line['data'];
                break;

            case EvolutionApiService::CHANNEL_ACCOUNT:
                // Money is only ever disclosed for an unambiguous identification.
                if ($identified && empty($ctx['identity_ambiguous'])) {
                    $acct = $this->tools->getAccount($clientId);
                    if ($acct['ok']) $ctx['account'] = $acct['data'];
                }
                break;
        }

        if ($convId > 0) {
            try {
                // Twenty, to match the website: a WhatsApp customer answers in single
                // words even more than a web one, so the model needs to still see the
                // question those words are answering.
                $msgs = $this->convSvc->getMessages($convId, 20, 0);
                foreach ($msgs as $m) {
                    $inbound = ($m['direction'] ?? 'in') === 'in';
                    $text    = mb_substr((string)($m['body'] ?? ''), 0, 400);

                    // An outbound message is not necessarily ours. A colleague
                    // replying by hand was being handed to the model as its own
                    // previous turn, so the AI would carry on as though it had
                    // promised whatever the person promised. Name them instead:
                    // the AI reads it, honours it, and does not claim it.
                    if (!$inbound) {
                        $who = trim((string)($m['agent_name'] ?? ''));
                        if (($m['role'] ?? '') === 'agent' && $who !== '' && $who !== 'DishNet AI') {
                            $text = '[' . $who . ', from our team] ' . $text;
                        }
                    }

                    $ctx['history'][] = [
                        'role' => $inbound ? 'customer' : 'dishnet',
                        'text' => $text,
                    ];
                }
            } catch (\Throwable $e) { /* history is optional */ }
        }

        return $ctx;
    }

    /**
     * Ask the AI for a reply.
     *
     * Two implementations behind ONE contract. In-process is the default
     * (Option A); setting shopbot_ai_url switches to an external brain
     * (Option B) with no other change anywhere in the system. This is the
     * seam that keeps the architecture decision reversible.
     */
    private function askBrain(array $context): ?array
    {
        if (trim((string)($this->config['shopbot_ai_url'] ?? '')) !== '') {
            return $this->askShopBot($context);
        }
        if (!$this->brain->isConfigured()) {
            $this->log('error', 'No AI provider key configured');
            return null;
        }
        $result = $this->brain->reply($context);

        $usage = $this->brain->getLastUsage();
        if ($usage) {
            $this->log('info', sprintf(
                'ai tokens in=%d out=%d model=%s',
                $usage['input_tokens'] ?? 0, $usage['output_tokens'] ?? 0, $usage['model'] ?? '?'
            ));
        }
        return $result;
    }

    /**
     * Call an external ShopBot service (Option B only).
     *
     * Contract — deliberately identical to DishNetAiBrain::reply(), so the two
     * are interchangeable:
     *
     *   POST {shopbot_ai_url}
     *   Authorization: Bearer {shopbot_ai_token}
     *   body:     the context envelope above
     *   response: {"reply": "...", "escalate": false, "escalate_reason": ""}
     */
    private function askShopBot(array $context): ?array
    {
        $url   = trim((string)($this->config['shopbot_ai_url'] ?? ''));
        $token = trim((string)($this->config['shopbot_ai_token'] ?? ''));
        if ($url === '') {
            $this->log('error', 'shopbot_ai_url is not configured');
            return null;
        }

        $headers = ['Content-Type: application/json'];
        if ($token !== '') $headers[] = 'Authorization: Bearer ' . $token;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($context),
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 45,      // LLM round trips are slow
            CURLOPT_CONNECTTIMEOUT => 8,
        ]);
        $raw  = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            $this->log('error', 'ShopBot unreachable: ' . $err);
            return null;
        }
        if ($code >= 400) {
            $this->log('error', "ShopBot returned HTTP {$code}");
            return null;
        }
        $data = json_decode((string)$raw, true);
        return is_array($data) ? $data : null;
    }

    /**
     * Send the plans flyer image, when there is one and it was not sent to
     * this conversation recently.
     *
     * Lives entirely in the never-throw zone after the text reply: a missing
     * file, an Evolution refusal or a full disk downgrades to a log line and
     * the customer still has the text they were answered with. The cooldown
     * is the backstop for a model that emits <<FLYER>> too eagerly — the
     * prompt already says once per conversation, but prompts are requests.
     */
    private function maybeSendFlyer(int $convId, string $channel, string $phone): void
    {
        try {
            if ($this->flyer === null) return;   // model flagged it, nothing to send
            if ($convId > 0 && $this->flyerSentRecently($convId)) {
                $this->log('info', "conv {$convId}: flyer already sent recently — not repeating it");
                return;
            }

            $media = FlyerAsset::payload($this->flyer);
            if ($media === '') {
                $this->log('warn', "conv {$convId}: flyer vanished between resolve and send — skipped");
                return;
            }

            $caption = trim((string)($this->config['wa_flyer_caption'] ?? ''));
            $send = $this->evo->sendImage($channel, $phone, $media, $caption);
            if (empty($send['ok'])) {
                $this->log('warn', "conv {$convId}: flyer send failed — " . (string)($send['error'] ?? '?'));
                return;
            }
            $this->log('info', "conv {$convId}: plans flyer sent (" . (string)$this->flyer['kind'] . ")");

            if ($convId > 0) {
                // Stored as a media message: the Inbox shows it happened, and
                // the model sees it in history — which is how "already sent,
                // refer back to it" becomes possible.
                $this->convSvc->storeMessage($convId, [
                    'direction'  => 'out',
                    'role'       => 'assistant',
                    'body'       => $caption !== '' ? $caption : '[plans flyer image]',
                    'media_type' => 'image',
                    'media_url'  => self::FLYER_MEDIA_TAG,
                    'agent_name' => 'DishNet AI',
                ]);
            }
        } catch (\Throwable $e) {
            $this->log('error', 'flyer send failed: ' . $e->getMessage());
        }
    }

    private function flyerSentRecently(int $convId): bool
    {
        $hours = $this->config['wa_flyer_cooldown_hours'] ?? null;
        $hours = ($hours === null || $hours === '' || !is_numeric($hours))
               ? 24                          // default: once a day per conversation
               : max(0, (int)$hours);
        if ($hours === 0) return false;      // 0 = the model's judgement alone

        $stmt = $this->pdo->prepare(
            "SELECT sent_at FROM wa_messages
              WHERE conversation_id = ? AND direction = 'out' AND media_url = ?
              ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([$convId, self::FLYER_MEDIA_TAG]);
        $at = (string)($stmt->fetchColumn() ?: '');
        if ($at === '') return false;

        $ts = strtotime($at . ' UTC');       // sent_at is stored as UTC
        return $ts !== false && (time() - $ts) < $hours * 3600;
    }

    private function humanIsHandling(int $convId): bool
    {
        try {
            $stmt = $this->pdo->prepare('SELECT state, last_human_reply_at FROM wa_conversations WHERE id = ?');
            $stmt->execute([$convId]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (!$row || ($row['state'] ?? '') !== 'human_active') return false;

            // How long the AI stays quiet after a colleague replies.
            //
            // The point of the pause is that two answers to one question, from
            // a person and a bot at the same time, is worse than a slow answer.
            // But 24 hours meant one staff reply took a customer off the AI for
            // the rest of the day, which is far longer than anyone is actually
            // still typing. It is a setting now: minutes, and 0 means the AI
            // never stands down -- it simply reads what the colleague said and
            // carries on from there.
            $mins = $this->config['wa_human_cooldown_minutes'] ?? null;
            $mins = ($mins === null || $mins === '' || !is_numeric($mins))
                  ? 1440                      // unchanged default: 24 hours
                  : max(0, (int)$mins);
            if ($mins === 0) return false;

            $last = strtotime((string)($row['last_human_reply_at'] ?? '2000-01-01'));
            return (time() - $last) < $mins * 60;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * @param bool $alreadyAnswered The customer has had a reply this turn, so the
     *                              holding line would be a second message saying
     *                              the same thing.
     */
    private function escalate(int $convId, string $channel, string $phone, string $reason,
                              bool $alreadyAnswered = false): void
    {
        $this->log('info', "conv {$convId}: HANDOFF to human — {$reason}");
        try {
            if ($convId > 0) {
                $this->pdo->prepare(
                    "UPDATE wa_conversations SET state = 'needs_human', updated_at = datetime('now') WHERE id = ?"
                )->execute([$convId]);
            }
            $this->bus->emit('wa.escalation', 'conversation', $convId, [
                'channel' => $channel,
                'phone'   => $phone,
                'reason'  => $reason,
            ], 2, 'ai_reply_worker');

            // Tell a person now. The Inbox tab turning red only works if
            // someone is looking at it; the phone in their pocket always is.
            // 30-minute cooldown per conversation, so a customer who trips
            // escalation three times in a row is one buzz, not three.
            require_once dirname(__DIR__) . '/lib/AlertService.php';
            $alerts = new \AlertService($this->store, $this->config, $this->evo);
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
            $holding = trim((string)($this->config['ai_handover_message'] ?? ''));
            if ($holding !== '' && $phone !== '' && !$alreadyAnswered && !$this->alreadySaid($convId, $holding)) {
                $send = $this->evo->sendText($channel, $phone, $holding);
                if (!empty($send['ok'])) {
                    if ($convId > 0) {
                        $this->convSvc->storeMessage($convId, [
                            'direction'     => 'out',
                            'role'          => 'assistant',
                            'body'          => $holding,
                            'agent_name'    => 'DishNet AI',
                            'wa_message_id' => (string)($send['data']['key']['id'] ?? '') ?: null,
                            'metadata'      => json_encode(['channel' => $channel, 'handover' => true]),
                        ]);
                    }
                } else {
                    $this->log('warn', "conv {$convId}: handover line not sent: "
                        . (string)($send['error'] ?? '?'));
                }
            }
        } catch (\Throwable $e) {
            $this->log('error', 'escalation failed: ' . $e->getMessage());
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
    private function alreadySaid(int $convId, string $text): bool
    {
        if ($convId <= 0) return false;
        try {
            $stmt = $this->pdo->prepare(
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
