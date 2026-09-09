<?php
declare(strict_types=1);

/**
 * InboundMailWorker — read the mailbox, understand it, draft, and stop.
 *
 * This worker never sends. Not "does not send yet" — the send path is not
 * reachable from here at all. Everything it produces lands in the draft inbox
 * for a person to approve, and approval is somebody else's code. That
 * separation is the whole safety argument: a bug in classification, in
 * matching, or in the model can waste a person's time, and cannot email a
 * customer.
 *
 * The order below is deliberate and each step can only stop the next one:
 *
 *   1. filter    is this even a human customer writing to us?
 *   2. classify  what are they asking for, and how careful must we be?
 *   3. match     whose account is this, and how sure are we?
 *   4. context   what does uCRM actually say about that account?
 *   5. draft     the same brain, the same knowledge, as WhatsApp and web
 *   6. store     pending, with every reason recorded
 *
 * Nothing later can undo a caution raised earlier.
 */
class InboundMailWorker
{
    /** @var JmapMailbox */   private $mailbox;
    /** @var EmailDraftStore */ private $drafts;
    /** @var mixed */         private $crm;
    /** @var mixed */         private $brain;
    /** @var array */         private $config;
    /** @var string[] */      private $ourAddresses;

    public function __construct($mailbox, EmailDraftStore $drafts, $crm, $brain, array $config)
    {
        $this->mailbox = $mailbox;
        $this->drafts  = $drafts;
        $this->crm     = $crm;
        $this->brain   = $brain;
        $this->config  = $config;

        // Every address that is us. A message from any of them is our own mail
        // coming back, and answering it is how a machine talks to itself
        // forever.
        $this->ourAddresses = array_values(array_filter(array_unique(array_map(
            'strtolower',
            [
                (string)($config['email_reply_to']    ?? ''),
                (string)($config['smtp_from']         ?? ''),
                (string)($config['mail_from']         ?? ''),
                (string)($config['email_ai_mailbox']  ?? ''),
                (string)($config['smtp_user']         ?? ''),
            ]
        ))));
    }

    /**
     * @return array{ok:bool, error:string, read:int, ignored:int, drafted:int,
     *                newest:string, items:array}
     */
    public function run(string $since = '', int $limit = 25): array
    {
        $out = ['ok' => false, 'error' => '', 'read' => 0, 'ignored' => 0,
                'drafted' => 0, 'newest' => $since, 'items' => []];

        $fetched = $this->mailbox->fetch($since, $limit);
        if (empty($fetched['ok'])) {
            $out['error'] = (string)($fetched['error'] ?? 'the mailbox could not be read');
            return $out;
        }
        $out['newest'] = (string)($fetched['newest'] ?? $since);

        foreach ((array)($fetched['emails'] ?? []) as $mail) {
            $out['read']++;
            $item = $this->handle(is_array($mail) ? $mail : []);
            $out['items'][] = $item;
            if ($item['action'] === 'ignored') $out['ignored']++;
            if ($item['action'] === 'drafted') $out['drafted']++;
        }

        $out['ok'] = true;
        return $out;
    }

    /**
     * One message, all the way through. Returns what happened and why, so a
     * run can be read back without opening the database.
     */
    public function handle(array $mail): array
    {
        $messageId = (string)($mail['message_id'] ?? '');
        $from      = (string)($mail['from'] ?? '');
        $subject   = (string)($mail['subject'] ?? '');
        $body      = (string)($mail['body'] ?? '');
        $files     = array_map('strval', (array)($mail['attachments'] ?? []));

        $say = function (string $action, string $why, array $extra = [])
                use ($messageId, $from, $subject) {
            return ['action' => $action, 'why' => $why, 'message_id' => $messageId,
                    'from' => $from, 'subject' => $subject] + $extra;
        };

        if ($messageId === '') return $say('skipped', 'the message carries no id');
        if ($this->drafts->seen($messageId)) return $say('skipped', 'already handled');

        // ── 1. Filter ────────────────────────────────────────────────────
        $verdict = InboundMailFilter::assess(
            (array)($mail['headers'] ?? []), $from, $subject, $this->ourAddresses);

        // assess() reports what to IGNORE. Reading it as a permission to reply
        // inverts it, and an inverted filter refuses every real customer while
        // letting every robot through — which is exactly what it did until the
        // Subterra email came out the far end marked "ignored".
        if (!empty($verdict['ignore'])) {
            // Recorded, not discarded. An operator must be able to see what
            // the filter refused, or the filter becomes a place mail vanishes.
            $this->drafts->add([
                'message_id'   => $messageId,
                'thread_id'    => (string)($mail['thread_id'] ?? ''),
                'from_addr'    => $from,
                'from_name'    => (string)($mail['from_name'] ?? ''),
                'subject'      => $subject,
                'body_excerpt' => $body,
                'received_at'  => (string)($mail['received_at'] ?? ''),
                'category'     => 'unclear',
                'escalation'   => (string)($verdict['reason'] ?? ''),
                'status'       => EmailDraftStore::IGNORED,
            ]);
            return $say('ignored', (string)($verdict['reason'] ?? 'the filter refused it'));
        }

        // ── 2. Classify ──────────────────────────────────────────────────
        $intent = EmailIntentClassifier::classify(
            ['subject' => $subject, 'body' => $body, 'attachments' => $files],
            $this->classifier()
        );

        // ── 3. Match ─────────────────────────────────────────────────────
        $match = ['client' => null, 'confidence' => EmailCustomerMatcher::NONE, 'why' => ''];
        if ($this->crm !== null) {
            $matcher = new EmailCustomerMatcher($this->crm);
            $match   = $matcher->match($from, $subject, $body);
        }
        $client   = is_array($match['client'] ?? null) ? $match['client'] : null;
        $clientId = (int)($client['id'] ?? 0);

        // ── 4 & 5. Context and draft ─────────────────────────────────────
        $draft = $this->draft($intent, $match, $mail);

        // ── 6. Store, always pending ─────────────────────────────────────
        $note = trim($match['why'] . ($intent['reasons']
            ? ' · ' . implode('; ', $intent['reasons']) : ''));

        $id = $this->drafts->add([
            'message_id'    => $messageId,
            'thread_id'     => (string)($mail['thread_id'] ?? ''),
            'from_addr'     => $from,
            'from_name'     => (string)($mail['from_name'] ?? ''),
            'subject'       => $subject,
            'body_excerpt'  => $body,
            'received_at'   => (string)($mail['received_at'] ?? ''),
            'crm_client_id' => $clientId,
            'customer_note' => $note,
            'category'      => (string)$intent['category'],
            'confidence'    => (float)$intent['confidence'],
            'escalation'    => trim(($intent['requires_human'] ? 'human approval required' : '')
                                . ((string)($draft['escalation'] ?? '') !== ''
                                    ? ' · ' . (string)$draft['escalation'] : '')),
            'draft_subject' => (string)$draft['subject'],
            'draft_body'    => (string)$draft['body'],
            'status'        => EmailDraftStore::PENDING,
        ]);

        return [
            'action'         => 'drafted',
            'why'            => $note !== '' ? $note : 'drafted for approval',
            'message_id'     => $messageId,
            'from'           => $from,
            'subject'        => $subject,
            'draft_id'       => $id,
            'category'       => (string)$intent['category'],
            'requires_human' => (bool)$intent['requires_human'],
            'client_id'      => $clientId,
            'match'          => (string)$match['confidence'],
            // Carried out so a dry run can show what it wrote. A run that
            // stores nothing must still be able to show its work.
            'draft_body'     => (string)$draft['body'],
            'draft_subject'  => (string)$draft['subject'],
        ];
    }

    // ══════════════════════════════════════════════════════════════════════

    /**
     * The classifier the intent reader consults — the same brain that answers
     * WhatsApp, asked for one word instead of a reply.
     *
     * Returns null when no brain is configured, and the classifier then treats
     * everything as unclear, which is the correct answer when nothing can read
     * the message.
     */
    private function classifier(): ?callable
    {
        if ($this->brain === null || !$this->brain->isConfigured()) return null;

        $brain      = $this->brain;
        $categories = array_keys(EmailReplyPolicy::all());

        return function (string $text) use ($brain, $categories): array {
            $ask = "Classify this customer email into exactly one category.\n"
                 . "Reply with the category word alone, nothing else.\n\n"
                 . "Categories: " . implode(', ', $categories) . "\n\n"
                 . "Email:\n" . mb_substr($text, 0, 4000);

            $r    = $brain->reply(['message' => $ask, 'channel' => 'support',
                                   'classify_only' => true]);
            $word = strtolower(trim((string)($r['reply'] ?? '')));
            $word = preg_replace('/[^a-z_]/', '', explode("\n", $word)[0] ?? '') ?? '';

            // No confidence is claimed on the model's behalf. A category it
            // named is worth 0.7; anything a person should see is decided by
            // the policy, not by a number we invented for it.
            return ['category' => $word, 'confidence' => $word !== '' ? 0.7 : 0.0];
        };
    }

    /**
     * Draft a reply using the same brain, knowledge and rules as every other
     * channel. There is no separate email knowledge base — a second one would
     * drift, and then the answer a customer got would depend on how they had
     * asked.
     */
    private function draft(array $intent, array $match, array $mail): array
    {
        $subject = (string)($mail['subject'] ?? '');
        $reply   = ['subject' => self::replySubject($subject), 'body' => '', 'escalation' => ''];

        if ($this->brain === null || !$this->brain->isConfigured()) {
            $reply['body'] = '';
            return $reply;
        }

        $client = is_array($match['client'] ?? null) ? $match['client'] : null;

        // These key names are the brain's, not mine.
        //
        // The first draft it produced said "It seems I don't have access to
        // installation dates or account details" — for a customer we had
        // matched exactly, seconds earlier. The reason was not the model. I
        // passed 'client', 'name' and 'constraint'; the brain reads 'customer',
        // 'account' and 'constraints', so it received a message with no
        // context and no rules and did the only sensible thing with it.
        $context = [
            'message' => (string)$intent['own_words'],
            'channel' => self::channelFor((string)$intent['category']),
            'medium'  => 'email',
        ];

        // Account facts, only when we actually know whose account it is. A
        // draft written against a guessed account is worse than one written
        // against none: a person reviewing it would have no way to tell.
        if ($client !== null && EmailCustomerMatcher::isCertain((string)$match['confidence'])) {
            $context['customer'] = [
                'name'    => trim((string)($client['companyName'] ?? ''))
                          ?: trim(($client['firstName'] ?? '') . ' ' . ($client['lastName'] ?? ''))
                          ?: (string)($mail['from_name'] ?? ''),
                'is_lead' => !empty($client['isLead']),
            ];
            $context['account'] = ['id' => (int)($client['id'] ?? 0)];
        }

        // What a person must decide, stated as rules the model is given before
        // the data. It is not trusted to hold this line — the policy already
        // refuses the send — but a draft that promises a date is a draft
        // somebody has to rewrite, and the point of a draft is to save that.
        if (!empty($intent['requires_human'])) {
            $context['constraints'] = [
                'Promise, confirm or estimate any date — installation, delivery or visit',
                'Confirm, accept or acknowledge acceptance of an order or purchase order',
                'Agree any price, discount, refund or credit',
                'Confirm that a payment has been received',
            ];
        }

        try {
            $r = $this->brain->reply($context);

            // An escalation is a note for the reviewer, never a draft.
            //
            // The brain's handover text — "Please hold on while I escalate
            // your request" — is written for a chat window where a colleague
            // appears shortly. Left in the draft body it sits under an
            // APPROVE & SEND button, one careless click from being sent to a
            // customer as a complete reply. The reason belongs to the person
            // reading; the empty body tells them plainly that this one has to
            // be written by hand.
            if (!empty($r['escalate'])) {
                $reply['body']      = '';
                $reply['escalation'] = trim((string)($r['escalate_reason'] ?? 'the assistant could not answer this'));
            } else {
                $reply['body'] = trim((string)($r['reply'] ?? ''));
            }
        } catch (\Throwable $e) {
            error_log('[InboundMailWorker] draft failed: ' . $e->getMessage());
            $reply['body'] = '';
        }

        return $reply;
    }

    /** Which of the brain's existing channel voices fits this category. */
    public static function channelFor(string $category): string
    {
        $sales   = ['plans_pricing', 'coverage', 'general_info', 'order_po', 'schedule_request'];
        $account = ['how_to_pay', 'account_status', 'invoice_query', 'payment_claim',
                    'contract_signed'];
        if (in_array($category, $sales, true))   return 'sales';
        if (in_array($category, $account, true)) return 'account';
        return 'support';
    }

    /** "Re:" once, however many times the thread has been round. */
    public static function replySubject(string $subject): string
    {
        $s = trim($subject);
        while (preg_match('/^(re|fw|fwd)\s*:\s*/i', $s)) {
            $s = trim(preg_replace('/^(re|fw|fwd)\s*:\s*/i', '', $s) ?? '');
        }
        return $s === '' ? 'Re: your message' : 'Re: ' . $s;
    }
}
