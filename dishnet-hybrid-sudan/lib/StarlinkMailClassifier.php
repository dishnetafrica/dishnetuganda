<?php
declare(strict_types=1);

/**
 * StarlinkMailClassifier — turns one Starlink email into one structured event.
 *
 * Read-and-classify only, by construction: this class can call the AI
 * provider and nothing else. It holds no Starlink credential, no mail-server
 * credential, and returns data — the worker decides what happens next, and
 * the riskiest thing the worker can do is send a WhatsApp message or raise a
 * staff alert.
 *
 * Uses the same provider selection and keys as the rest of the AI platform
 * (ai_provider + claude_api_key / openai_api_key in kyc_config.json).
 * Deterministic settings (temperature 0), JSON-only output, and a hard rule:
 * anything unparseable comes back as OTHER / confidence 0 / action_required,
 * which the worker routes to a human. Uncertainty always fails toward people.
 */
class StarlinkMailClassifier
{
    public const TYPES = [
        'EMAIL_VERIFICATION', 'ORDER_CONFIRMED', 'ORDER_SHIPPED', 'ACTIVATION',
        'INVOICE', 'PAYMENT_ISSUE', 'SERVICE_NOTICE', 'SUSPENSION', 'OTHER',
    ];

    private array $config;
    /** @var callable|null test seam: fn(system, user) => raw model text|null */
    private $llm;

    public function __construct(array $config, ?callable $llmOverride = null)
    {
        $this->config = $config;
        $this->llm    = $llmOverride;
    }

    public function isConfigured(): bool
    {
        $p = strtolower(trim((string)($this->config['ai_provider'] ?? 'claude')));
        $key = $p === 'openai' ? ($this->config['openai_api_key'] ?? '') : ($this->config['claude_api_key'] ?? '');
        return trim((string)$key) !== '';
    }

    /**
     * @return array{type:string, extracted:array, confidence:float,
     *               action_required:bool, summary:string, ai_model:string}
     */
    public function classify(string $fromAddr, string $subject, string $body): array
    {
        $fallback = [
            'type' => 'OTHER', 'extracted' => [], 'confidence' => 0.0,
            'action_required' => true, 'summary' => 'unclassified — needs human review',
            'ai_model' => '',
        ];

        $body = mb_substr($body, 0, 6000);
        $system = $this->systemPrompt();
        $user   = "From: {$fromAddr}\nSubject: {$subject}\n\n{$body}";

        try {
            $raw = $this->llm ? ($this->llm)($system, $user) : $this->callProvider($system, $user);
        } catch (\Throwable $e) {
            $raw = null;
        }
        if (!is_string($raw) || $raw === '') return $fallback;

        // Accept the JSON object wherever the model put it.
        if (!preg_match('/\{.*\}/s', $raw, $m)) return $fallback;
        $j = json_decode($m[0], true);
        if (!is_array($j)) return $fallback;

        $type = strtoupper(trim((string)($j['type'] ?? 'OTHER')));
        if (!in_array($type, self::TYPES, true)) $type = 'OTHER';

        $conf = (float)($j['confidence'] ?? 0);
        $conf = max(0.0, min(1.0, $conf));

        return [
            'type'            => $type,
            'extracted'       => is_array($j['extracted'] ?? null) ? $j['extracted'] : [],
            'confidence'      => $conf,
            'action_required' => !empty($j['action_required']),
            'summary'         => mb_substr(trim((string)($j['summary'] ?? '')), 0, 300),
            'ai_model'        => $this->modelName(),
        ];
    }

    // ── internals ────────────────────────────────────────────────────────

    private function systemPrompt(): string
    {
        $types = implode(', ', self::TYPES);
        return <<<P
You classify ONE email from Starlink to a DishNet-managed customer account.
Reply with ONLY a JSON object, no prose:
{"type": one of [{$types}],
 "extracted": {"order_reference": "", "tracking_number": "", "amount": "", "due_date": "", "kit": "", "link_present": true/false},
 "confidence": 0.0-1.0,
 "action_required": true/false,
 "summary": "one short sentence for staff"}

Rules:
- action_required=true when a human must click, verify, pay or decide
  (verification links, OTP requests, payment problems, suspension warnings).
- Routine notices (shipped, activated, invoice issued and auto-paid) are
  action_required=false.
- Extract only what is literally present. Empty string for anything absent.
- If unsure of the type, use OTHER with low confidence and action_required=true.
P;
    }

    private function modelName(): string
    {
        $p = strtolower(trim((string)($this->config['ai_provider'] ?? 'claude')));
        $m = trim((string)($this->config['ai_model'] ?? ''));
        if ($m !== '') return $m;
        return $p === 'openai' ? 'gpt-4o-mini' : 'claude-haiku-4-5';
    }

    private function callProvider(string $system, string $user): ?string
    {
        $p = strtolower(trim((string)($this->config['ai_provider'] ?? 'claude')));
        return $p === 'openai'
            ? $this->callOpenAi($system, $user)
            : $this->callClaude($system, $user);
    }

    private function callClaude(string $system, string $user): ?string
    {
        $key = trim((string)($this->config['claude_api_key'] ?? ''));
        if ($key === '') return null;
        $resp = $this->http('https://api.anthropic.com/v1/messages', [
            'x-api-key: ' . $key,
            'anthropic-version: 2023-06-01',
            'Content-Type: application/json',
        ], [
            'model'       => $this->modelName(),
            'max_tokens'  => 400,
            'temperature' => 0,
            'system'      => $system,
            'messages'    => [['role' => 'user', 'content' => $user]],
        ]);
        return $resp['content'][0]['text'] ?? null;
    }

    private function callOpenAi(string $system, string $user): ?string
    {
        $key = trim((string)($this->config['openai_api_key'] ?? ''));
        if ($key === '') return null;
        $resp = $this->http('https://api.openai.com/v1/chat/completions', [
            'Authorization: Bearer ' . $key,
            'Content-Type: application/json',
        ], [
            'model'       => $this->modelName(),
            'max_tokens'  => 400,
            'temperature' => 0,
            'messages'    => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user',   'content' => $user],
            ],
        ]);
        return $resp['choices'][0]['message']['content'] ?? null;
    }

    private function http(string $url, array $headers, array $body): ?array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_POSTFIELDS     => json_encode($body),
            CURLOPT_TIMEOUT        => 45,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);
        $raw = curl_exec($ch);
        curl_close($ch);
        if ($raw === false) return null;
        $j = json_decode((string)$raw, true);
        return is_array($j) ? $j : null;
    }
}
