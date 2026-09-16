<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/ContactOptOut.php';

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
    /** Same idea per named photo, so the same picture is never sent twice. */
    const PHOTO_MEDIA_TAG = 'dishnet:photo:';

    private EvolutionApiService $evo;
    private DishNetTools $tools;
    private DishNetAiBrain $brain;
    private $convSvc;
    /** @var array|null FlyerAsset::find() result, resolved once per run */
    private $flyer;
    /** Where the photo library lives; the worker resolves names against it. */
    private string $photoDir = '';

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
        require_once $root . '/lib/UtcClock.php';

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
        // Named photos the operator dropped in <dataDir>/photos. An empty
        // folder leaves photo_block as '' and the model is never told the
        // action exists — the same absence story the flyer uses.
        if (!class_exists('MediaLibrary')) {
            $pl = __DIR__ . '/../lib/MediaLibrary.php';
            if (is_file($pl)) require_once $pl;
        }
        $this->photoDir = $dataDir;
        if (class_exists('MediaLibrary')) {
            $config['photo_block'] = \MediaLibrary::promptBlock($dataDir);
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

        // A colleague has this conversation. The question is not dropped —
        // "human active, skipping AI" acked the event, and that is how a
        // customer's follow-up went unanswered by anyone. It is parked and
        // comes back when the pause ends, unless somebody answered it by then.
        if ($convId > 0) {
            $wait = $this->humanPauseRemaining($convId);
            if ($wait > 0) {
                $this->parkBehindHuman($event, $convId, $wait);   // throws WorkerDefer, or drops
                return;
            }
            if ($this->answeredMeanwhile($event, $convId)) {
                $this->log('info', "conv {$convId}: a colleague replied after this message arrived — nothing to add");
                return;
            }
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
        $send = $this->evo->sendText($channel, $phone, $reply, ContactOptOut::CLASS_REPLY);
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
            $this->claimOwnEcho($send, $channel, 'ai.reply');

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
            // A named photo the model asked for. Before the flyer, because a
            // customer who asked to see the kit wants the kit, not the price
            // list.
            if (!empty($ai['photo'])) {
                $this->maybeSendPhoto($convId, $channel, $phone, (string)$ai['photo']);
            }
            if (!empty($ai['doc'])) {
                $this->maybeSendDocument($convId, $channel, $phone, (string)$ai['doc']);
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
        } elseif ($id['ok'] && ($id['data']['reason'] ?? '') === 'ambiguous') {
            $ctx['identity_ambiguous'] = true;
        }

        // Open the turn under the identity just resolved, BEFORE anything is
        // linked or stored. beginTurn advances the epoch and clears a stale
        // CRM link when the identity has changed, so it has to run first —
        // after linkToCrm it would wipe the link that had just been made.
        $identityKey = ConversationService::identityKey(
            $identified ? $clientId : null, !empty($ctx['identity_ambiguous']));
        if ($convId > 0) {
            try { $this->convSvc->beginTurn($convId, $identityKey); }
            catch (\Throwable $e) { $this->log('warn', 'conv ' . $convId . ': beginTurn failed — '
                . $e->getMessage()); }
        }

        if ($identified) {
            if ($convId > 0) {
                try {
                    $this->convSvc->linkToCrm($convId, $clientId, (string)($id['data']['customer']['name'] ?? ''),
                                              ConversationService::LINK_AI);
                } catch (\Throwable $e) { /* non-fatal */ }
            }
        } elseif (!empty($ctx['identity_ambiguous'])) {
            // Several customers share this number's last digits, so the AI
            // must ask a verifying question rather than pick one.
            //
            // And remember it. Answering an ambiguous number is fine, because
            // whoever wrote in is the person reading the reply. STARTING a
            // conversation with one is not: we would be guessing which of
            // several customers we are addressing. FollowUpPolicy refuses a
            // proactive send on this marker.
            if ($convId > 0) {
                try { $this->convSvc->markIdentityAmbiguous($convId); }
                catch (\Throwable $e) { /* non-fatal */ }
            }
        }

        // The website-lead lookup that used to live here is gone. It matched
        // on trailing nine digits with no country check — the same defect
        // fixed in DishNetTools — and put a South Sudan visitor's typed name
        // into a Ugandan caller's prompt. It bought a greeting.

        switch ($channel) {
            case EvolutionApiService::CHANNEL_SALES:
                // A customer we can name is not necessarily a subscriber. The
                // one a colleague created five minutes ago, mid-chat, has an
                // account and a quotation and no service yet — a sign-up in
                // progress, not an existing customer to put in service mode.
                // One read of their own services says which; a failed read
                // leaves the key out and the prompt keeps its old posture.
                if ($identified && empty($ctx['identity_ambiguous']) && is_array($ctx['customer'])) {
                    $svc = $this->tools->getCustomerServices($clientId);
                    if ($svc['ok']) {
                        $live = 0;
                        foreach ((array)$svc['data'] as $s) {
                            // 1 active, 3 suspended: a service that exists and bills.
                            if (in_array((int)($s['status'] ?? -1), [1, 3], true)) $live++;
                        }
                        $ctx['customer']['has_service'] = $live > 0;
                        $this->log('info', sprintf('conv %d: identified customer, %d live service(s)', $convId, $live));
                    }
                }
                $this->loadCatalogue($ctx, $convId);
                break;

            case EvolutionApiService::CHANNEL_SUPPORT:
                if ($identified) {
                    $svc = $this->tools->getCustomerServices($clientId);
                    if ($svc['ok']) $ctx['services'] = $svc['data'];
                }
                // A number that sells needs the price list. With
                // ai_sales_on_all_numbers the prompt tells this number to
                // answer what-it-costs questions "from PLANS" — and until
                // 5.18.10 PLANS was fetched for the sales number alone, so
                // the model here was instructed to quote from a list it did
                // not have. Every support-channel turn in the 16 Sep log has
                // no catalogue line after it; every sales turn does.
                if ($this->sellsOnAllNumbers()) $this->loadCatalogue($ctx, $convId);
                // Splynx line status removed for Uganda: Splynx is the South
                // Sudan fibre stack and this deployment is Starlink-only.
                // The triage it gave for callers we could NOT identify is a
                // deliberate loss, not an oversight. Starlink operational
                // status, if it is wanted, gets its own capability with its
                // own authoritative source.
                break;

            case EvolutionApiService::CHANNEL_ACCOUNT:
                // Money is only ever disclosed for an unambiguous identification.
                if ($identified && empty($ctx['identity_ambiguous'])) {
                    $acct = $this->tools->getAccount($clientId);
                    if ($acct['ok']) $ctx['account'] = $acct['data'];
                }
                if ($this->sellsOnAllNumbers()) $this->loadCatalogue($ctx, $convId);
                break;
        }

        if ($convId > 0) {
            try {
                // Twenty, to match the website: a WhatsApp customer answers in single
                // words even more than a web one, so the model needs to still see the
                // question those words are answering.
                //
                // Identity-bound since B3.1, using the key resolved above —
                // never the conversation row. A phone number is reassigned and
                // shared, and following it was how the next holder of a number
                // inherited the last one's balance. Nothing replays across an
                // identity change, and nothing at all for an ambiguous number.
                // Since 5.18.3 an UNKNOWN caller — a prospect, or a customer
                // while the CRM is down — does get their own recent turns back:
                // those were written with no account data in the prompt, and
                // without them the sales number answered every prospect one
                // message at a time. See ConversationService::replayableIdentity().
                $msgs = $this->convSvc->getMessagesForAi($convId, $identityKey, 20);
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
                        if ($who === ConversationService::AGENT_AUTO_REPLY) {
                            // The WhatsApp Business app's own greeting or away
                            // message: not a colleague, not the assistant.
                            $text = '[automatic message from our WhatsApp app] ' . $text;
                        } elseif (($m['role'] ?? '') === 'agent' && $who !== '' && $who !== 'DishNet AI') {
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
        // One line, no content: whether the model had the conversation in
        // front of it, or answered this message on its own.
        $this->log('info', sprintf('conv %d: identity=%s history=%d turn(s)', $convId,
            ConversationService::identityState($identityKey), count($ctx['history'])));

        // ── B3.4: does the tool layer agree with the prompt? ────────────
        //
        // Runs both readers and compares them; the customer still gets the
        // legacy answer. Nothing below this line may reach $ctx, and nothing
        // it produces may reach the reply — see shadowCompare().
        $this->shadowCompare($channel, $convId, $clientId,
                             $identified && empty($ctx['identity_ambiguous']), $ctx);

        // ── The B3.2 contract, for the callers that lose nothing by it ──
        //
        // Sales never rendered account data, so it adopts the twelve-key
        // contract today. Support and accounts do not: their prompts carry
        // the customer's plan, status, expiry, balance, invoice and last
        // payment, and the tool layer that will answer those on demand is
        // B3.3. Enforcing the contract on them now would make them tell a
        // customer asking what they owe that we will check — a functionality
        // regression bought with no security gain. They migrate in B3.5,
        // accounts last, after B3.4 has shown the tool answers match.
        if ($channel === EvolutionApiService::CHANNEL_SALES) {
            require_once dirname(__DIR__) . '/lib/BrainContext.php';
            $products = $ctx['products'] ?? [];
            $products['stock'] = (string)($this->config['stock_statement'] ?? '');
            return \BrainContext::build(
                \ConversationService::identityState($identityKey),
                [
                    'customer'  => $ctx['customer'] ?? null,
                    'channel'   => $channel,
                    'transport' => 'whatsapp',
                    'medium'    => '',
                    'products'  => $products,
                    'message'   => $message,
                    'history'   => $ctx['history'] ?? [],
                ]);
        }

        return $ctx;
    }

    /**
     * B3.4 — run the controlled tools beside the legacy prompt and compare.
     *
     * B3.5 takes the customer's balance, invoice, payment, plan and service
     * status out of the prompt and lets the model ask for them instead. This
     * is how we find out, on real customers and real uCRM data, whether the
     * answers are the same BEFORE the customer depends on the new one.
     *
     * ── IT CANNOT CHANGE THE REPLY ──────────────────────────────────────
     *
     * It takes $ctx by value and returns void, so there is no expression by
     * which a shadow result reaches the prompt. It runs after the context is
     * finished. Every failure is swallowed. If this method were deleted the
     * customer would not be able to tell.
     *
     * ── IT CANNOT WIDEN A LIVE ALLOWLIST ────────────────────────────────
     *
     * The CustomerDataTools instance is built here and dropped here. A tool
     * call records what it disclosed, and ReplyPrivacyGuard checks a reply
     * against that record; a shadow that shared an instance with a live
     * caller would quietly permit values the live prompt never carried. The
     * worker has no live instance today, and this is why it must not acquire
     * one by borrowing this.
     *
     * ── IT LOGS VERDICTS, NEVER VALUES ──────────────────────────────────
     *
     * ShadowCompare returns reason codes built from its own constants. What
     * lands in the log is "conv 412: shadow account balance=same
     * invoice=differ:number payment=same" — not a second at-rest copy of
     * everybody's financial position. Even the exception path logs the class
     * and not the message, because a database error carries its query.
     *
     * The conversation id is the diagnostic handle, and it is usually enough
     * to find the case. A turn that arrived without a conversation row logs
     * "conv 0" and names a divergence nobody can trace to a customer — still
     * worth having, because knowing a class of divergence exists is what
     * tells you to go looking, but it is not a case you can open.
     *
     * ── COST ────────────────────────────────────────────────────────────
     *
     * Roughly doubles the uCRM reads on these two channels: accounts adds a
     * client, an invoice and a payment read, support adds two service reads
     * (get_my_plan and get_my_service_status each fetch). Off by default, and
     * one flag turns it off again.
     */
    private function shadowCompare(string $channel, int $convId, int $clientId,
                                   bool $identified, array $ctx): void
    {
        if (empty($this->config['ai_shadow_compare'])) return;
        if (!$identified || $clientId <= 0) return;

        // Inside the try, all of it. A missing file is a broken deploy, but a
        // broken deploy must not be a broken reply: the whole promise of this
        // method is that deleting it would make no difference to the customer,
        // and a require that can fatal outside a catch does not keep it.
        try {
            $root = dirname(__DIR__);
            require_once $root . '/lib/ShadowCompare.php';
            if (!isset(\ShadowCompare::CHANNEL_FACTS[$channel])) return;

            require_once $root . '/lib/CrmApiClient.php';
            require_once $root . '/lib/UcrmCustomerDataGateway.php';
            require_once $root . '/lib/CustomerIdentity.php';

            $crm = \CrmApiClient::fromUcrm($root, $this->config);
            if (!$crm->isConfigured()) {
                $this->log('warn', 'conv ' . $convId . ': shadow skipped — CRM not configured');
                return;
            }

            // The id is the one the backend resolved for this turn, in the
            // shape CustomerIdentity::resolve() returns. It is not read from
            // the context, the message or the model, and this method has no
            // parameter by which a different customer could be named.
            $tools = \CustomerDataTools::forIdentityState(
                \ConversationService::STATE_IDENTIFIED,
                ['status' => \CustomerIdentity::IDENTIFIED, 'client_id' => $clientId],
                new \UcrmCustomerDataGateway(new \UcrmGatewayHost($crm, $this->pdo)));

            $r = \ShadowCompare::compare($channel, $ctx, $tools);
            $this->log($r['divergent'] === [] ? 'info' : 'warn',
                       sprintf('conv %d: shadow %s', $convId, $r['line']));
        } catch (\Throwable $e) {
            $this->log('warn', sprintf('conv %d: shadow failed — %s', $convId, get_class($e)));
        }
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
            $external = $this->askShopBot($context);
            // Same guard, same permitted set. The prompt reference is the one
            // this brain would have built — the data section is identical.
            return $external === null ? null
                 : $this->guardReply($external, $context, $this->brain->promptPreview($context));
        }
        if (!$this->brain->isConfigured()) {
            $this->log('error', 'No AI provider key configured');
            return null;
        }
        $result = $this->guardReply($this->brain->reply($context), $context, $this->brain->lastSystemPrompt());

        $usage = $this->brain->getLastUsage();
        if ($usage) {
            $this->log('info', sprintf(
                'ai tokens in=%d out=%d model=%s',
                $usage['input_tokens'] ?? 0, $usage['output_tokens'] ?? 0, $usage['model'] ?? '?'
            ));
        }
        return $result;
    }

    /** Is every number in the selling business, not only the sales one? */
    private function sellsOnAllNumbers(): bool
    {
        return filter_var($this->config['ai_sales_on_all_numbers'] ?? false, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * The live price list into the context, or a loud log line.
     *
     * The brain falls back to "PLANS unavailable" and hands over when this
     * fails — safe, but it must never be invisible in the log.
     */
    private function loadCatalogue(array &$ctx, int $convId): void
    {
        $products = $this->tools->getProducts();
        if ($products['ok']) {
            $ctx['products'] = $products['data'];
            $mirrors = (int)($products['data']['hardware_plan_mirrors'] ?? 0);
            $acc = (int)($products['data']['accessory_count'] ?? 0);
            $this->log('info', sprintf('conv %d: catalogue loaded, %d plan(s), %d hardware item(s)%s%s',
                $convId, (int)($products['data']['count'] ?? 0),
                (int)($products['data']['hardware_count'] ?? 0),
                $acc > 0 ? sprintf(', %d accessor%s', $acc, $acc === 1 ? 'y' : 'ies') : '',
                $mirrors > 0 ? sprintf(', %d plan mirror(s) dropped from hardware', $mirrors) : ''));
            if (!empty($products['data']['hardware_error'])) {
                $this->log('warn', 'conv ' . $convId . ': hardware lookup failed — '
                    . (string)$products['data']['hardware_error']);
            }
        } else {
            $this->log('error', 'conv ' . $convId . ': product lookup FAILED — '
                . (string)($products['error'] ?? 'unknown') . ' (AI will not quote prices)');
        }
    }

    /**
     * The last check before a reply reaches a person — on this path for the
     * first time.
     *
     * ReplyPrivacyGuard has carried the rule this needs since B3: money the
     * tools did not return and the prompt does not contain is refused, and
     * the whole reply goes. It was wired into the WASender webhook and never
     * into this worker, which is the path Uganda runs. On 16 Sep a prospect
     * on the support number was quoted a kit, an installation and two
     * monthly plans — four figures, none in uCRM, all invented — because
     * that number was never handed the catalogue and nothing between the
     * model and the customer looked at the numbers. "Never invent a price"
     * is a prompt instruction; this is the boundary.
     *
     * On a block the customer gets the safe fallback, the thread is handed to
     * a person with the category as the reason, and an event is stored with
     * metadata only. The blocked text is written nowhere.
     */
    private function guardReply(array $ai, array $ctx, string $prompt): array
    {
        $reply = (string)($ai['reply'] ?? '');
        if (trim($reply) === '') return $ai;   // nothing to send; the escalate flag stands

        if (!class_exists('ReplyPrivacyGuard')) {
            require_once dirname(__DIR__) . '/lib/ReplyPrivacyGuard.php';
        }
        try {
            $res = \ReplyPrivacyGuard::check($reply, [
                'values' => $this->permittedValues($ctx, $prompt),
                'prompt' => $prompt,
            ]);
        } catch (\Throwable $e) {
            // Fail closed: a reply nobody checked is the failure this exists
            // to end, and the fallback is a smaller one than a wrong price.
            $this->log('error', 'guard failed, reply withheld: ' . $e->getMessage());
            $res = ['safe' => false, 'reply' => \ReplyPrivacyGuard::SAFE_FALLBACK, 'categories' => ['guard_error']];
        }
        if (!empty($res['safe'])) return $ai;

        $convId = (int)($ctx['conversation_id'] ?? 0);
        $cats   = implode(',', (array)($res['categories'] ?? []));
        $this->log('warn', sprintf('conv %d: reply BLOCKED by guard — %s (len=%d)', $convId, $cats, mb_strlen($reply)));
        try {
            $this->store->append('ai_security_events.json', \ReplyPrivacyGuard::auditEvent($res, [
                'conversation_id' => $convId,
                'customer_id'     => (int)($ctx['customer']['id'] ?? 0),
                'channel'         => (string)($ctx['channel'] ?? ''),
                'provider'        => 'ai_reply_worker',
                'blocked_length'  => strlen($reply),
            ]));
        } catch (\Throwable $e) {
            $this->log('warn', 'guard event not stored: ' . $e->getMessage());
        }

        return [
            'reply'           => \ReplyPrivacyGuard::SAFE_FALLBACK,
            'escalate'        => true,
            'escalate_reason' => 'reply blocked by guard: ' . $cats,
            'send_flyer'      => false,
            'lead'            => null,
            'photo'           => '',
            'doc'             => '',
        ];
    }

    /**
     * What this reply is allowed to contain, by number.
     *
     * Everything in the context the model was given: the catalogue, the
     * customer's own services and account, their name and number. Then the
     * arithmetic a correct sales answer needs and the prompt itself does not
     * spell out: every sum of the one-time items (TOTAL TO GET CONNECTED is
     * kit plus installation) and whole-month multiples of each plan (a year
     * of a plan is not an invention). Then the customer's own words, this
     * turn and earlier — a figure they typed may be echoed back. Then every
     * number already in the prompt, so our own phone number reformatted is
     * still ours. Prices are added with and without .00, since the guard
     * compares digit strings and the model writes either.
     *
     * Nothing here reaches beyond this conversation's context. No other
     * customer, no other record, is consulted.
     *
     * @return string[]
     */
    private function permittedValues(array $ctx, string $prompt): array
    {
        $values = [];
        $walk = function ($node) use (&$walk, &$values): void {
            if (is_array($node)) { foreach ($node as $v) $walk($v); return; }
            if ($node === null || is_bool($node)) return;
            $values[] = (string)$node;
        };
        foreach (['products', 'customer', 'services', 'account', 'customer_phone', 'push_name'] as $k) {
            if (isset($ctx[$k])) $walk($ctx[$k]);
        }

        $hw = [];
        foreach ((array)($ctx['products']['hardware'] ?? []) as $h) {
            if (isset($h['price']) && is_numeric($h['price'])) $hw[] = (float)$h['price'];
        }
        $hw = array_slice($hw, 0, 6);                  // at most 63 non-empty subsets
        // Accessories (5.18.11): a customer may take the kit, the
        // installation and a wall mount. Every hardware subset, alone and
        // with one or two accessories — a total with three mounts in it is
        // one to confirm by hand, not to wave through.
        $acc = [];
        foreach ((array)($ctx['products']['accessories'] ?? []) as $a) {
            if (isset($a['price']) && is_numeric($a['price'])) $acc[] = (float)$a['price'];
        }
        $acc = array_slice($acc, 0, 24);
        $accSums = [];
        foreach ($acc as $i => $a) {
            $accSums[] = $a;
            for ($j = $i + 1; $j < count($acc); $j++) $accSums[] = $a + $acc[$j];
        }
        $n  = count($hw);
        for ($mask = 0; $mask < (1 << $n); $mask++) {
            $sum = 0.0;
            for ($i = 0; $i < $n; $i++) if ($mask & (1 << $i)) $sum += $hw[$i];
            if ($mask > 0) $values[] = self::money($sum);
            foreach ($accSums as $extra) $values[] = self::money($sum + $extra);
        }
        foreach ((array)($ctx['products']['products'] ?? []) as $p) {
            if (!isset($p['price']) || !is_numeric($p['price'])) continue;
            for ($m = 1; $m <= 12; $m++) $values[] = self::money((float)$p['price'] * $m);
        }

        $said = [(string)($ctx['message'] ?? '')];
        foreach ((array)($ctx['history'] ?? []) as $h) {
            if (($h['role'] ?? '') === 'customer') $said[] = (string)($h['text'] ?? '');
        }
        foreach ($said as $s) {
            foreach (preg_split('/\s+/', $s) ?: [] as $w) {
                $w = trim($w, ".,;:!?()[]\"'");
                if ($w !== '') $values[] = $w;
            }
        }

        if ($prompt !== '' && preg_match_all('/\+?\d[\d\s,.-]{4,}\d/', $prompt, $m) > 0) {
            foreach ($m[0] as $found) $values[] = $found;
        }

        $out = [];
        foreach ($values as $v) {
            $out[] = $v;
            if (preg_match('/^\d+(\.\d+)?$/', $v) === 1) $out[] = number_format((float)$v, 2, '.', '');
        }
        return $out;
    }

    /** A price as the prompt prints it: no trailing zeros, no dangling point. */
    private static function money(float $v): string
    {
        return rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');
    }

    /**
     * Call an external ShopBot service (Option B only).
     *
     * Contract — deliberately identical to DishNetAiBrain::reply(), so the two
     * are interchangeable:
     *
     *   POST {shopbot_ai_url}
     *   Authorization: Bearer {shopbot_ai_token}
     *   body:     ShopBotPayload::project($context) — NOT the raw context
     *   response: {"reply": "...", "escalate": false, "escalate_reason": ""}
     *
     * The body used to be the raw envelope, which carried the complete uCRM
     * client record, internal ids, the Splynx id and service address, and the
     * customer's balance on channels that never render one — none of it read
     * by the brain, all of it leaving the process. It is now projected against
     * an explicit contract: see ShopBotPayload, which also records what it
     * refuses to send and why. The response contract is unchanged.
     */
    private function askShopBot(array $context): ?array
    {
        $url   = trim((string)($this->config['shopbot_ai_url'] ?? ''));
        $token = trim((string)($this->config['shopbot_ai_token'] ?? ''));
        if ($url === '') {
            $this->log('error', 'shopbot_ai_url is not configured');
            return null;
        }

        // Default deny, before anything is encoded. Nothing between here and
        // curl sees the unprojected context.
        require_once dirname(__DIR__) . '/lib/ShopBotPayload.php';
        $payload = \ShopBotPayload::project($context);
        $dropped = \ShopBotPayload::dropped($context);
        if ($dropped !== []) {
            // Key names only. The values are the thing being withheld.
            $this->log('info', 'shopbot: withheld ' . count($dropped) . ' context key(s): '
                . implode(', ', $dropped));
        }

        $headers = ['Content-Type: application/json'];
        if ($token !== '') $headers[] = 'Authorization: Bearer ' . $token;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
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
     * Send one named photo from the operator's library.
     *
     * A name the library does not have sends nothing and logs it. That is the
     * safety property: the model picks from a list it was given, and a name it
     * invented resolves to no file rather than to the wrong picture.
     */
    private function maybeSendPhoto(int $convId, string $channel, string $phone, string $name): void
    {
        try {
            if (!class_exists('MediaLibrary')) return;
            $photo = \MediaLibrary::find($this->photoDir, $name);
            if ($photo === null) {
                $this->log('warn', "conv {$convId}: no photo named '{$name}' — nothing sent");
                return;
            }
            // Twice is worse than not at all: they already have it, and a
            // repeat reads as a bot that forgot.
            if ($convId > 0 && $this->photoSentAlready($convId, $name)) {
                $this->log('info', "conv {$convId}: photo '{$name}' already sent — not repeating");
                return;
            }

            $media = \MediaLibrary::payload($photo);
            if ($media === '') {
                $this->log('warn', "conv {$convId}: photo '{$name}' vanished before sending");
                return;
            }

            $send = $this->evo->sendImage($channel, $phone, $media, (string)$photo['caption']);
            if (empty($send['ok'])) {
                $this->log('warn', "conv {$convId}: photo '{$name}' failed — "
                    . (string)($send['error'] ?? '?'));
                return;
            }
            $this->log('info', "conv {$convId}: photo '{$name}' sent");

            $ourId = $this->claimOwnEcho($send, $channel, 'ai.photo');
            if ($convId > 0) {
                $this->convSvc->storeMessage($convId, [
                    'direction'  => 'out',
                    'role'       => 'assistant',
                    'body'       => $photo['caption'] !== '' ? $photo['caption'] : ('[photo: ' . $name . ']'),
                    'media_type' => 'image',
                    'media_url'  => self::PHOTO_MEDIA_TAG . $name,
                    'agent_name' => 'DishNet AI',
                    'wa_message_id' => $ourId,
                ]);
            }
        } catch (\Throwable $e) {
            $this->log('error', 'photo send failed: ' . $e->getMessage());
        }
    }

    /**
     * Send one named document — a spec sheet, a brochure.
     *
     * sendMedia with mediatype 'document' and a real file name, because a PDF
     * arriving as "file" with no extension is a PDF nobody opens.
     */
    private function maybeSendDocument(int $convId, string $channel, string $phone, string $name): void
    {
        try {
            if (!class_exists('MediaLibrary')) return;
            $doc = \MediaLibrary::findDocument($this->photoDir, $name);
            if ($doc === null) {
                $this->log('warn', "conv {$convId}: no document named '{$name}' — nothing sent");
                return;
            }
            if ($convId > 0 && $this->photoSentAlready($convId, 'doc:' . $name)) {
                $this->log('info', "conv {$convId}: document '{$name}' already sent — not repeating");
                return;
            }

            $media = \MediaLibrary::payload($doc);
            if ($media === '') {
                $this->log('warn', "conv {$convId}: document '{$name}' vanished before sending");
                return;
            }

            $send = $this->evo->sendMedia($channel, $phone, 'document', $media,
                                          (string)$doc['caption'], (string)$doc['file'],
                                          ContactOptOut::CLASS_REPLY);
            if (empty($send['ok'])) {
                $this->log('warn', "conv {$convId}: document '{$name}' failed — "
                    . (string)($send['error'] ?? '?'));
                return;
            }
            $this->log('info', "conv {$convId}: document '{$name}' sent");

            $ourId = $this->claimOwnEcho($send, $channel, 'ai.document');
            if ($convId > 0) {
                $this->convSvc->storeMessage($convId, [
                    'direction'  => 'out',
                    'role'       => 'assistant',
                    'body'       => $doc['caption'] !== '' ? $doc['caption'] : ('[document: ' . $doc['file'] . ']'),
                    'media_type' => 'document',
                    'media_url'  => self::PHOTO_MEDIA_TAG . 'doc:' . $name,
                    'agent_name' => 'DishNet AI',
                    'wa_message_id' => $ourId,
                ]);
            }
        } catch (\Throwable $e) {
            $this->log('error', 'document send failed: ' . $e->getMessage());
        }
    }

    /** Has this exact photo already gone to this conversation? */
    private function photoSentAlready(int $convId, string $name): bool
    {
        try {
            $st = $this->pdo->prepare(
                "SELECT 1 FROM wa_messages WHERE conversation_id = ? AND media_url = ? LIMIT 1"
            );
            $st->execute([$convId, self::PHOTO_MEDIA_TAG . $name]);
            return (bool)$st->fetchColumn();
        } catch (\Throwable $e) {
            return false;   // a failed check must never block a photo
        }
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

            $ourId = $this->claimOwnEcho($send, $channel, 'ai.flyer');
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
                    'wa_message_id' => $ourId,
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
        return $this->humanPauseRemaining($convId) > 0;
    }

    /**
     * Seconds left on a colleague's pause for this conversation; 0 when none.
     */
    private function humanPauseRemaining(int $convId): int
    {
        try {
            $stmt = $this->pdo->prepare('SELECT state, last_human_reply_at FROM wa_conversations WHERE id = ?');
            $stmt->execute([$convId]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (!$row || ($row['state'] ?? '') !== 'human_active') return 0;

            $mins = $this->cooldownMinutes();
            if ($mins === 0) return 0;

            // The stamp is UTC (markHumanHandling writes gmdate) and is read
            // as UTC. strtotime() applied the process zone, and this worker
            // runs under two of them: UTC when the webhook spawns it, Africa/
            // Kampala when cron/master.php runs it. On the scheduled path the
            // stamp read three hours old and the pause never held.
            $last = UtcClock::parse($row['last_human_reply_at'] ?? '');
            if ($last <= 0) return 0;
            $left = $last + $mins * 60 - time();
            return $left > 0 ? $left : 0;
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * How long the AI stays quiet after a colleague replies, in minutes.
     *
     * The point of the pause is that two answers to one question, from a
     * person and a bot at the same time, is worse than a slow answer. But 24
     * hours meant one staff reply took a customer off the AI for the rest of
     * the day, which is far longer than anyone is actually still typing. It
     * is a setting: minutes, and 0 means the AI never stands down -- it
     * simply reads what the colleague said and carries on from there.
     */
    private function cooldownMinutes(): int
    {
        $mins = $this->config['wa_human_cooldown_minutes'] ?? null;
        return ($mins === null || $mins === '' || !is_numeric($mins))
             ? 1440                      // unchanged default: 24 hours
             : max(0, (int)$mins);
    }

    /**
     * Park this question behind a colleague's pause.
     *
     * Throws WorkerDefer, so WorkerBase puts the event back unchanged for
     * when the pause should be over — never less than half a minute, never
     * more than ten, since the pause can be extended and is re-read each
     * time. A question that has waited longer than wa_parked_max_minutes
     * (default 120) is dropped with a log line: by then the colleague has
     * dealt with it or the watchdog has paged the team about it, and an
     * answer two hours late from a bot reads worse than none.
     */
    private function parkBehindHuman(array $event, int $convId, int $wait): void
    {
        $received = $this->receivedAt($event);
        $maxMin   = $this->config['wa_parked_max_minutes'] ?? null;
        $maxMin   = ($maxMin === null || $maxMin === '' || !is_numeric($maxMin)) ? 120 : max(1, (int)$maxMin);
        if ($received > 0 && (time() - $received) > $maxMin * 60) {
            $this->log('info', "conv {$convId}: human active for over {$maxMin} min — parked question dropped");
            return;
        }
        $wait = max(30, min(600, $wait));
        $this->log('info', "conv {$convId}: human active — parked for {$wait}s");
        throw new WorkerDefer($wait, 'parked: a colleague is active on this conversation');
    }

    /**
     * Did a person on our side write in this conversation after this message
     * arrived? Then the question has been dealt with and the AI adds nothing.
     *
     * Only when a cooldown is set: 0 means the AI always answers, reading
     * what the colleague said, which is that setting's whole meaning.
     */
    private function answeredMeanwhile(array $event, int $convId): bool
    {
        if ($this->cooldownMinutes() === 0) return false;
        $received = $this->receivedAt($event);
        if ($received <= 0) return false;
        return $this->convSvc->humanRepliedSince($convId, UtcClock::stamp($received));
    }

    /** When the customer's message reached the webhook, as unix time (0 if unknown). */
    private function receivedAt(array $event): int
    {
        $p = $event['_payload'] ?? [];
        $t = UtcClock::parse($p['received_at'] ?? '');
        if ($t <= 0) $t = UtcClock::parse($event['created_at'] ?? '');
        return $t;
    }

    /**
     * Claim the echo of a message we just sent, and hand back its id.
     *
     * Every outbound message returns through the webhook as fromMe. The text
     * reply has always claimed its id; the photo, document and flyer sends
     * did not, and stored no wa_message_id either, so each caption came
     * back, inserted as a 'Team' message and stood the AI down for the whole
     * cooldown — the bot went quiet right after showing someone the kit.
     * Claim in the guard's table first (the echo can arrive before the store
     * that follows), then the id goes on the row so the store dedupes too.
     */
    private function claimOwnEcho(array $send, string $channel, string $source): ?string
    {
        $ourId = trim((string)($send['data']['key']['id'] ?? ''));
        if ($ourId === '') return null;
        try {
            if (!class_exists('EvoWebhookGuard')) {
                $g = __DIR__ . '/../lib/EvoWebhookGuard.php';
                if (is_file($g)) require_once $g;
            }
            if (class_exists('EvoWebhookGuard')) {
                (new \EvoWebhookGuard($this->pdo, $this->config))
                    ->claim($ourId, $this->evo->instanceFor($channel), $source);
            }
        } catch (\Throwable $e) { /* dedupe is a backstop, not a requirement */ }
        return $ourId;
    }

    /**
     * The retry budget for a customer's message is spent.
     *
     * Five attempts over about forty minutes have failed — the model
     * unreachable, Evolution refusing — and the event is a dead letter
     * nobody reads. The customer has heard nothing for all of that time.
     * Hand over the way an unanswerable question is handed over: the thread
     * goes red for the team, the alert number buzzes, and the customer gets
     * the holding line, once.
     */
    protected function onDead(array $event, \Throwable $e): void
    {
        $p       = $event['_payload'] ?? [];
        $channel = (string)($p['channel'] ?? '');
        $phone   = (string)($p['customer_phone'] ?? '');
        $convId  = (int)($event['entity_id'] ?? 0);
        if ($channel === '' || $phone === '') return;
        $attempts = (int)($event['attempts'] ?? 0) + 1;
        $this->escalate($convId, $channel, $phone,
            "no reply after {$attempts} attempts (" . mb_substr($e->getMessage(), 0, 80) . ')');
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
                $send = $this->evo->sendText($channel, $phone, $holding, ContactOptOut::CLASS_REPLY);
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
