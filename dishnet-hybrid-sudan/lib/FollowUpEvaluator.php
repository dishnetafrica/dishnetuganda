<?php
declare(strict_types=1);

require_once __DIR__ . '/FollowUpPolicy.php';

/**
 * FollowUpEvaluator — ask the AI whether to follow somebody up, and what to say.
 *
 * ── ENFORCEMENT BY OMISSION ─────────────────────────────────────────────
 *
 * The provenance rule says a phone-tail match may discuss the enquiry but not
 * the customer's account. The obvious way to implement that is to tell the
 * model not to mention account details. That is not enforcement, it is a
 * request, and it fails silently the first time the model is confused.
 *
 * So account facts are simply NOT PUT IN THE PROMPT unless the identity is
 * trusted. A model cannot leak what it was never given. The instruction is
 * still there as a second layer, but the first layer is the one that holds.
 *
 * ── FAILING TOWARDS SILENCE ─────────────────────────────────────────────
 *
 * Every failure here resolves to DO_NOT_SEND: an unparseable answer, a
 * missing message on a SEND verdict, a verdict nobody recognises, an
 * exception. The cost of not sending a follow-up is a lead going cold. The
 * cost of sending a wrong one is a customer receiving something baffling from
 * a company they trust with their connection. Those are not comparable.
 */
final class FollowUpEvaluator
{
    public const VERDICTS = ['SEND', 'DO_NOT_SEND', 'WAIT', 'ESCALATE_TO_HUMAN'];

    /** @var object anything exposing getReply(string,array,string,string,string,string):?string */
    private $brain;
    private array $config;

    public function __construct($brain, array $config = [])
    {
        $this->brain  = $brain;
        $this->config = $config;
    }

    /**
     * @param array  $fu        the followups row
     * @param array  $messages  the thread, oldest first: [['role'=>..,'body'=>..,'sent_at'=>..], ..]
     * @param string $level     FollowUpPolicy::CONTENT_* — what may be discussed
     * @param array  $account   assembled account facts; IGNORED unless $level is account
     * @return array{verdict:string, reason:string, message:string, sales_stage:string,
     *               product:string, objection:string, next_due_hours:int, raw:string}
     */
    public function evaluate(array $fu, array $messages, string $level, array $account = []): array
    {
        $attempt = (int)($fu['attempts'] ?? 0) + 1;

        if ($level === FollowUpPolicy::CONTENT_NONE) {
            // Should never reach here — the gate stops it — but a second
            // refusal costs nothing and a missing one could cost everything.
            return self::refuse('the identity behind this number is not certain enough to write to');
        }

        $prompt = $this->buildPrompt($fu, $messages, $level,
                                     $level === FollowUpPolicy::CONTENT_ACCOUNT ? $account : [],
                                     $attempt);

        try {
            $raw = $this->brain->getReply(
                $prompt, [], (string)($fu['channel'] ?? 'sales'), '',
                self::instructions($level), 'override');
        } catch (\Throwable $e) {
            return self::refuse('the assistant could not be reached: ' . $e->getMessage());
        }

        if (!is_string($raw) || trim($raw) === '') {
            return self::refuse('the assistant returned nothing');
        }
        return self::parse($raw);
    }

    /** What the model is told it must produce. */
    public static function instructions(string $level): string
    {
        $accountRule = $level === FollowUpPolicy::CONTENT_ACCOUNT
            ? "You may refer to this customer's account, service and equipment where the context below includes them."
            : "You have NOT been given this customer's account details and must not imply you know them. "
            . "Do not mention balances, invoices, account numbers, service status or equipment. "
            . "Refer only to what they asked us about in the conversation.";

        return <<<TXT
You are deciding whether DishNet Uganda should follow up a customer who went quiet, and if so, writing that one message.

Reply with a single JSON object and nothing else:

{"verdict":"SEND|DO_NOT_SEND|WAIT|ESCALATE_TO_HUMAN",
 "reason":"one sentence explaining the decision, for a colleague to read",
 "sales_stage":"enquired|quoted|deciding|objection|ready|unknown",
 "product":"what they asked about, or null",
 "objection":"what is holding them back, or null",
 "message":"the follow-up message, or null when not sending",
 "next_due_hours":48}

Choosing the verdict:
- SEND when a short, specific, useful message would genuinely help them decide.
- DO_NOT_SEND when they have said no, bought already, or a follow-up would irritate. This closes the enquiry and is a good outcome.
- WAIT when something is pending that makes now the wrong moment.
- ESCALATE_TO_HUMAN when they are unhappy, confused, or asking something the assistant should not answer alone.

Writing the message, if you send one:
- Refer to what they actually asked about. "Hi, just following up" on its own is not acceptable.
- Answer the state they left the conversation in. If they said they needed to talk to their spouse, acknowledge that they are deciding and offer to help with questions — do NOT ask them to proceed.
- One short paragraph. WhatsApp, not email. No subject line, no signature, no emoji unless the customer used them.
- Never invent a price, a date, a discount or a promise. If you do not know something, do not mention it.
- {$accountRule}
TXT;
    }

    /** The thread and the state, in the order a person would read them. */
    private function buildPrompt(array $fu, array $messages, string $level,
                                 array $account, int $attempt): string
    {
        $lines = [];
        $lines[] = 'CONVERSATION (oldest first, times UTC):';
        foreach ($messages as $m) {
            $who = ($m['role'] ?? '') === 'customer' ? 'CUSTOMER'
                 : ((($m['role'] ?? '') === 'staff') ? 'OUR TEAM' : 'ASSISTANT');
            $body = trim((string)($m['body'] ?? ''));
            if ($body === '') continue;
            $lines[] = '  [' . (string)($m['sent_at'] ?? '') . '] ' . $who . ': ' . $body;
        }

        $lines[] = '';
        $lines[] = 'WHERE THIS STANDS:';
        $lines[] = '  The customer last wrote at ' . (string)($fu['last_customer_at'] ?? '?') . ' UTC and has not replied since.';
        $lines[] = '  This would be follow-up attempt ' . $attempt . ' of ' . (int)($fu['max_attempts'] ?? 2)
                 . '. After the last one we stop and do not contact them again about this.';
        if (($fu['topic'] ?? '') !== '' && $fu['topic'] !== 'unknown') {
            $lines[] = '  Recorded topic: ' . (string)$fu['topic'];
        }
        if (!empty($fu['product']))   $lines[] = '  Recorded interest: ' . (string)$fu['product'];
        if (!empty($fu['objection'])) $lines[] = '  Recorded objection: ' . (string)$fu['objection'];

        $lines[] = '';
        if ($level === FollowUpPolicy::CONTENT_ACCOUNT && $account !== []) {
            $lines[] = 'THIS CUSTOMER (identity confirmed):';
            foreach ($account as $k => $v) {
                if (is_scalar($v) && (string)$v !== '') {
                    $lines[] = '  ' . $k . ': ' . (string)$v;
                }
            }
        } else {
            // Said plainly, so the model does not fill the gap by guessing.
            $lines[] = 'WHO THIS IS: not confirmed. Treat them as an enquirer and nothing more.';
        }

        return implode("\n", $lines);
    }

    /**
     * Read the model's answer.
     *
     * Tolerant about wrapping — a fenced block or a sentence before the JSON —
     * and strict about content. Anything it cannot read becomes DO_NOT_SEND.
     */
    public static function parse(string $raw): array
    {
        $txt = trim($raw);
        if (preg_match('/```(?:json)?\s*(.+?)```/s', $txt, $m)) $txt = trim($m[1]);
        $start = strpos($txt, '{');
        $end   = strrpos($txt, '}');
        if ($start === false || $end === false || $end <= $start) {
            return self::refuse('the assistant did not return JSON', $raw);
        }
        $data = json_decode(substr($txt, $start, $end - $start + 1), true);
        if (!is_array($data)) {
            return self::refuse('the assistant\'s JSON could not be read', $raw);
        }

        $verdict = strtoupper(trim((string)($data['verdict'] ?? '')));
        if (!in_array($verdict, self::VERDICTS, true)) {
            return self::refuse('unrecognised verdict "' . $verdict . '"', $raw);
        }

        $message = trim((string)($data['message'] ?? ''));
        // A SEND with nothing to send is not a send. Downgrading rather than
        // erroring keeps the row alive and the reason legible.
        if ($verdict === 'SEND' && $message === '') {
            return self::refuse('the assistant chose to send but wrote no message', $raw);
        }

        $hours = (int)($data['next_due_hours'] ?? 48);
        if ($hours < 1)    $hours = 1;
        if ($hours > 336)  $hours = 336;   // two weeks is the outer limit

        return [
            'verdict'        => $verdict,
            'reason'         => trim((string)($data['reason'] ?? '')) ?: 'no reason given',
            'message'        => $verdict === 'SEND' ? $message : '',
            'sales_stage'    => self::stage((string)($data['sales_stage'] ?? '')),
            'product'        => trim((string)($data['product'] ?? '')),
            'objection'      => trim((string)($data['objection'] ?? '')),
            'next_due_hours' => $hours,
            'raw'            => $raw,
        ];
    }

    private static function stage(string $s): string
    {
        $s = strtolower(trim($s));
        return in_array($s, ['enquired', 'quoted', 'deciding', 'objection', 'ready'], true)
            ? $s : 'unknown';
    }

    /** Every failure lands here, and every failure is silence. */
    private static function refuse(string $why, string $raw = ''): array
    {
        return ['verdict' => 'DO_NOT_SEND', 'reason' => $why, 'message' => '',
                'sales_stage' => 'unknown', 'product' => '', 'objection' => '',
                'next_due_hours' => 48, 'raw' => $raw];
    }
}
