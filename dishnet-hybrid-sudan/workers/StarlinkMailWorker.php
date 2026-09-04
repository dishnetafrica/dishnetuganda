<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/StarlinkMailClassifier.php';

/**
 * StarlinkMailWorker — reads the central Starlink intake mailbox
 * (starlink@dishnetuganda.com) over JMAP, matches each message to a customer,
 * classifies it with the AI layer, records it exactly once in
 * starlink_events, and routes the outcome:
 *
 *   informational + confident  → uCRM timeline row + customer WhatsApp
 *   action required            → staff alert (AlertService), status 'alerted'
 *   unmatched or low confidence→ staff alert, no customer message
 *
 * JMAP, not IMAP, on purpose: it is plain HTTPS + JSON, so this runs inside
 * the uCRM container with curl alone — no php-imap extension gamble. Stalwart
 * serves JMAP on the same TLS listener as its admin API.
 *
 * The worker's authority is bounded by its imports: it can read mail, write
 * its own tables, and send messages through the existing WhatsApp/alert
 * services. There is no code path that touches a Starlink account.
 *
 * Config (kyc_config.json):
 *   starlink_mail_enabled   bool gate
 *   jmap_url                https://mail.dishnetuganda.com   (session at /.well-known/jmap)
 *   starlink_mail_user      starlink@dishnetuganda.com
 *   starlink_mail_password  app password (SECRET)
 *   starlink_notify_channel Evolution channel for customer notices ('support')
 */
class StarlinkMailWorker
{
    /** Types safe to relay to the customer without a human in the loop. */
    private const CUSTOMER_NOTIFY = ['ORDER_CONFIRMED', 'ORDER_SHIPPED', 'ACTIVATION'];
    private const MIN_CONFIDENCE  = 0.85;

    private \PDO $pdo;
    private array $config;
    private StarlinkMailClassifier $classifier;
    private $identity;   // CustomerIdentityService
    private $evo;        // EvolutionApiService|null
    private $alerts;     // AlertService|null
    private $crm;        // CrmApiClient|null
    /** @var callable|null test seam: fn(method,url,headers,body)=>[status,json] */
    private $http;
    private string $lockFile;

    public function __construct(
        \PDO $pdo, array $config, $identity,
        $evo = null, $alerts = null, $crm = null,
        ?callable $httpOverride = null, ?StarlinkMailClassifier $classifier = null
    ) {
        $this->pdo        = $pdo;
        $this->config     = $config;
        $this->identity   = $identity;
        $this->evo        = $evo;
        $this->alerts     = $alerts;
        $this->crm        = $crm;
        $this->http       = $httpOverride;
        $this->classifier = $classifier ?? new StarlinkMailClassifier($config);
        $this->lockFile   = sys_get_temp_dir() . '/dishnet_starlink_mail.lock';
    }

    public function isConfigured(): bool
    {
        return trim((string)($this->config['jmap_url'] ?? '')) !== ''
            && trim((string)($this->config['starlink_mail_user'] ?? '')) !== ''
            && trim((string)($this->config['starlink_mail_password'] ?? '')) !== '';
    }

    public function run(int $maxMessages = 25): array
    {
        if (empty($this->config['starlink_mail_enabled'])) return ['skipped' => 'disabled'];
        if (!$this->isConfigured())                        return ['skipped' => 'not configured'];

        $fp = @fopen($this->lockFile, 'w+');
        if (!$fp || !flock($fp, LOCK_EX | LOCK_NB)) return ['skipped' => 'already running'];

        try {
            $emails = $this->fetchNewEmails($maxMessages);
            $stats  = ['fetched' => count($emails), 'events' => 0, 'notified' => 0, 'alerted' => 0, 'dupes' => 0];
            foreach ($emails as $em) {
                $out = $this->processEmail($em);
                if (isset($stats[$out])) $stats[$out]++;
            }
            return $stats;
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }

    /**
     * One email in, one outcome out: 'events' (stored+timeline), 'notified'
     * (customer messaged too), 'alerted' (humans pulled in), 'dupes'.
     * Public and pure-in/pure-out enough to be tested without any JMAP.
     */
    public function processEmail(array $em): string
    {
        $msgId = (string)($em['message_id'] ?? '');
        if ($msgId === '') return 'dupes';

        // Exactly-once: the UNIQUE(message_id) insert is the claim.
        try {
            $this->pdo->prepare(
                "INSERT INTO starlink_events (message_id, from_addr, subject, received_at, body_sha256)
                 VALUES (?,?,?,?,?)"
            )->execute([
                $msgId,
                (string)($em['from'] ?? ''),
                mb_substr((string)($em['subject'] ?? ''), 0, 300),
                (string)($em['received_at'] ?? ''),
                hash('sha256', (string)($em['body'] ?? '')),
            ]);
        } catch (\PDOException $e) {
            return 'dupes';
        }

        // ── match the customer ───────────────────────────────────────────
        $clientId = null; $identityEmail = '';
        foreach ((array)($em['to'] ?? []) as $addr) {
            $addr = strtolower(trim((string)$addr));
            $cid  = $this->identity ? $this->identity->findClientByEmail($addr) : null;
            if ($cid) { $clientId = $cid; $identityEmail = $addr; break; }
        }
        if ($clientId === null && preg_match('/\b(DN-UG-\d+|ACC-[A-Z0-9-]+)\b/i', (string)($em['body'] ?? ''), $m)) {
            $clientId = $this->clientFromReference($m[1]);
        }

        // ── classify ─────────────────────────────────────────────────────
        $c = $this->classifier->classify(
            (string)($em['from'] ?? ''), (string)($em['subject'] ?? ''), (string)($em['body'] ?? '')
        );

        $this->pdo->prepare(
            "UPDATE starlink_events
                SET client_id=?, identity_email=?, type=?, extracted_json=?,
                    confidence=?, action_required=?, ai_model=?
              WHERE message_id=?"
        )->execute([
            $clientId, $identityEmail, $c['type'],
            json_encode(['summary' => $c['summary']] + $c['extracted']),
            $c['confidence'], $c['action_required'] ? 1 : 0, $c['ai_model'], $msgId,
        ]);

        // ── route ────────────────────────────────────────────────────────
        $needsHuman = $c['action_required'] || $clientId === null || $c['confidence'] < self::MIN_CONFIDENCE;
        if ($needsHuman) {
            $who = $clientId ? "client #{$clientId}" : 'UNMATCHED customer';
            $this->alert(
                'starlink_' . strtolower($c['type']) . '_' . substr($msgId, -8),
                "⚠ *Starlink: {$c['type']}* — {$who}\n"
                . ($c['summary'] !== '' ? $c['summary'] . "\n" : '')
                . 'Subject: ' . mb_substr((string)($em['subject'] ?? ''), 0, 120) . "\n"
                . 'Review in starlink@ inbox / Starlink Orders tab.'
            );
            $this->setStatus($msgId, 'alerted');
            return 'alerted';
        }

        $this->logToCrm($clientId, $c, (string)($em['subject'] ?? ''));

        if (in_array($c['type'], self::CUSTOMER_NOTIFY, true) && $this->notifyCustomer($clientId, $c)) {
            $this->setStatus($msgId, 'notified');
            return 'notified';
        }
        $this->setStatus($msgId, 'resolved');
        return 'events';
    }

    // ── JMAP (Stalwart) ──────────────────────────────────────────────────

    /** @return array[] each: message_id, from, to[], subject, received_at, body */
    private function fetchNewEmails(int $limit): array
    {
        $base = rtrim((string)$this->config['jmap_url'], '/');
        $sess = $this->jmap('GET', $base . '/.well-known/jmap');
        $apiUrl    = (string)($sess['apiUrl'] ?? ($base . '/jmap/'));
        $accountId = (string)(array_key_first($sess['accounts'] ?? []) ?? '');
        if ($accountId === '') return [];

        $since = $this->cursor();
        $query = [
            'using' => ['urn:ietf:params:jmap:core', 'urn:ietf:params:jmap:mail'],
            'methodCalls' => [
                ['Email/query', [
                    'accountId' => $accountId,
                    'filter'    => $since ? ['after' => $since] : new \stdClass(),
                    'sort'      => [['property' => 'receivedAt', 'isAscending' => true]],
                    'limit'     => $limit,
                ], 'q'],
                ['Email/get', [
                    'accountId'  => $accountId,
                    '#ids'       => ['resultOf' => 'q', 'name' => 'Email/query', 'path' => '/ids'],
                    'properties' => ['id', 'messageId', 'from', 'to', 'subject', 'receivedAt', 'preview', 'bodyValues', 'textBody'],
                    'fetchTextBodyValues' => true,
                ], 'g'],
            ],
        ];
        $resp = $this->jmap('POST', $apiUrl, $query);

        $emails = [];
        foreach (($resp['methodResponses'] ?? []) as $mr) {
            if (($mr[0] ?? '') !== 'Email/get') continue;
            foreach (($mr[1]['list'] ?? []) as $e) {
                $body = '';
                foreach (($e['textBody'] ?? []) as $part) {
                    $pid = $part['partId'] ?? null;
                    if ($pid !== null && isset($e['bodyValues'][$pid]['value'])) {
                        $body .= $e['bodyValues'][$pid]['value'] . "\n";
                    }
                }
                if ($body === '') $body = (string)($e['preview'] ?? '');
                $emails[] = [
                    'message_id'  => (string)(($e['messageId'][0] ?? null) ?? ('jmap-' . ($e['id'] ?? bin2hex(random_bytes(8))))),
                    'from'        => (string)($e['from'][0]['email'] ?? ''),
                    'to'          => array_map(fn($t) => (string)($t['email'] ?? ''), (array)($e['to'] ?? [])),
                    'subject'     => (string)($e['subject'] ?? ''),
                    'received_at' => (string)($e['receivedAt'] ?? ''),
                    'body'        => $body,
                ];
                if (($e['receivedAt'] ?? '') > ($newest ?? '')) $newest = $e['receivedAt'];
            }
        }
        if (!empty($newest)) $this->cursor($newest);
        return $emails;
    }

    private function jmap(string $method, string $url, ?array $body = null): array
    {
        $auth = base64_encode($this->config['starlink_mail_user'] . ':' . $this->config['starlink_mail_password']);
        $headers = ['Authorization: Basic ' . $auth, 'Content-Type: application/json'];
        if ($this->http) { [, $j] = ($this->http)($method, $url, $headers, $body); return $j ?? []; }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);
        if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        $raw = curl_exec($ch);
        curl_close($ch);
        $j = json_decode((string)$raw, true);
        return is_array($j) ? $j : [];
    }

    /** Poll cursor kept in a tiny kv table so restarts never re-read history. */
    private function cursor(?string $set = null): string
    {
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS starlink_mail_state (k TEXT PRIMARY KEY, v TEXT)");
        if ($set !== null) {
            $this->pdo->prepare("INSERT INTO starlink_mail_state (k,v) VALUES ('since',?)
                                 ON CONFLICT(k) DO UPDATE SET v=excluded.v")->execute([$set]);
            return $set;
        }
        $v = $this->pdo->query("SELECT v FROM starlink_mail_state WHERE k='since'")->fetchColumn();
        return $v === false ? '' : (string)$v;
    }

    // ── side effects ─────────────────────────────────────────────────────

    private function notifyCustomer(int $clientId, array $c): bool
    {
        if (!$this->evo || !$this->crm) return false;
        try {
            $client = $this->crm->get("clients/{$clientId}") ?? [];
            $phone  = '';
            foreach (($client['contacts'] ?? []) as $ct) {
                if (!empty($ct['phone'])) { $phone = (string)$ct['phone']; break; }
            }
            if ($phone === '') return false;
            $first = trim((string)($client['firstName'] ?? '')) ?: 'there';
            $text  = match ($c['type']) {
                'ORDER_CONFIRMED' => "Hi {$first}, good news — your DishNet Starlink order is confirmed. We'll message you when it ships.",
                'ORDER_SHIPPED'   => "Hi {$first}, your DishNet Starlink kit has been shipped"
                                     . (!empty($c['extracted']['tracking_number']) ? " (tracking {$c['extracted']['tracking_number']})" : '')
                                     . ". Our team will contact you to schedule installation.",
                'ACTIVATION'      => "Hi {$first}, your DishNet Starlink service is now active. Welcome online! Reply here any time for support.",
                default           => '',
            };
            if ($text === '') return false;
            $channel = (string)($this->config['starlink_notify_channel'] ?? 'support');
            $r = $this->evo->sendText($channel, $phone, $text);
            return is_array($r) ? !empty($r['ok']) || !isset($r['ok']) : (bool)$r;
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function logToCrm(int $clientId, array $c, string $subject): void
    {
        if (!$this->crm) return;
        try {
            $this->crm->post("clients/{$clientId}/logs", [
                'message' => 'Starlink → ' . $c['type']
                    . ($c['summary'] !== '' ? ': ' . $c['summary'] : '')
                    . ' [' . mb_substr($subject, 0, 100) . ']',
            ]);
        } catch (\Throwable $e) { /* timeline is best-effort */ }
    }

    private function alert(string $key, string $text): void
    {
        if (!$this->alerts) return;
        try { $this->alerts->notify($key, $text, 60); } catch (\Throwable $e) {}
    }

    private function setStatus(string $msgId, string $status): void
    {
        $this->pdo->prepare('UPDATE starlink_events SET status=? WHERE message_id=?')
                  ->execute([$status, $msgId]);
    }
}
