<?php
/**
 * JmapMailbox — read a mailbox over JMAP.
 *
 * JMAP rather than IMAP on purpose, the same reasoning StarlinkMailWorker
 * settled on: it is plain HTTPS and JSON, so it runs inside the uCRM container
 * with curl alone and no php-imap extension to gamble on being present.
 *
 * The one thing this does that the Starlink reader does not is ask for the
 * HEADERS. InboundMailFilter decides whether a message may be answered by
 * reading Auto-Submitted, List-Id, Precedence, Return-Path and Content-Type,
 * and a reader that returns only sender and subject would leave that filter
 * guessing — which, for a filter whose whole job is preventing mail loops, is
 * the same as not having it.
 *
 * The HTTP call is injectable so the whole path can be tested without a
 * server.
 *
 * PHP 7.4 compatible.
 */
declare(strict_types=1);

class JmapMailbox
{
    /** Headers InboundMailFilter needs to make its decision. */
    const WANTED_HEADERS = [
        'Auto-Submitted', 'X-Auto-Response-Suppress', 'X-Autoreply', 'X-Autorespond',
        'X-Failed-Recipients', 'X-Mailer-Daemon',
        'List-Id', 'List-Unsubscribe', 'List-Post', 'List-Help',
        'X-Mailchimp-Id', 'X-Campaign-Id', 'X-SG-EID',
        'Precedence', 'Return-Path', 'Content-Type',
        'In-Reply-To', 'References', 'Message-ID', 'X-DishNet-Auto',
    ];

    /** @var string */ private $baseUrl;
    /** @var string */ private $user;
    /** @var string */ private $pass;
    /** @var callable|null */ private $http;

    public function __construct(string $baseUrl, string $user, string $pass, ?callable $http = null)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->user    = $user;
        $this->pass    = $pass;
        $this->http    = $http;
    }

    public function isConfigured(): bool
    {
        return $this->baseUrl !== '' && $this->user !== '' && $this->pass !== '';
    }

    /**
     * Messages received after $since, oldest first.
     *
     * @return array{ok:bool, error:string, emails:array, newest:string}
     */
    public function fetch(string $since = '', int $limit = 25): array
    {
        $out = ['ok' => false, 'error' => '', 'emails' => [], 'newest' => $since];
        if (!$this->isConfigured()) {
            $out['error'] = 'jmap_url, user or password is not set';
            return $out;
        }

        $sess = $this->call('GET', $this->baseUrl . '/.well-known/jmap');
        if (!is_array($sess) || empty($sess['accounts'])) {
            $out['error'] = 'the JMAP session could not be opened (check the URL and credentials)';
            return $out;
        }
        $apiUrl    = (string)($sess['apiUrl'] ?? ($this->baseUrl . '/jmap/'));
        $accountId = (string)(array_key_first($sess['accounts']) ?? '');
        if ($accountId === '') {
            $out['error'] = 'the JMAP session names no account';
            return $out;
        }

        // Ask for each header by name. JMAP returns them as
        // header:<Name>:asText properties, which is the only way to get at
        // headers it does not model as first-class fields.
        // hasAttachment and attachments matter as much as the text: the
        // classifier treats a customer's attached document as a reason for a
        // person to read the reply, and it can only do that if the names
        // actually arrive here.
        $props = ['id', 'messageId', 'threadId', 'from', 'to', 'cc', 'subject',
                  'receivedAt', 'preview', 'bodyValues', 'textBody',
                  'hasAttachment', 'attachments'];
        foreach (self::WANTED_HEADERS as $hdr) $props[] = 'header:' . $hdr . ':asText';

        $resp = $this->call('POST', $apiUrl, [
            'using' => ['urn:ietf:params:jmap:core', 'urn:ietf:params:jmap:mail'],
            'methodCalls' => [
                ['Email/query', [
                    'accountId' => $accountId,
                    'filter'    => $since !== '' ? ['after' => $since] : new \stdClass(),
                    'sort'      => [['property' => 'receivedAt', 'isAscending' => true]],
                    'limit'     => max(1, $limit),
                ], 'q'],
                ['Email/get', [
                    'accountId'  => $accountId,
                    '#ids'       => ['resultOf' => 'q', 'name' => 'Email/query', 'path' => '/ids'],
                    'properties' => $props,
                    'fetchTextBodyValues' => true,
                ], 'g'],
            ],
        ]);

        if (!is_array($resp) || empty($resp['methodResponses'])) {
            $out['error'] = 'the mailbox returned nothing usable';
            return $out;
        }

        $newest = $since;
        foreach ($resp['methodResponses'] as $mr) {
            if (($mr[0] ?? '') !== 'Email/get') continue;
            foreach (($mr[1]['list'] ?? []) as $e) {
                $out['emails'][] = $this->normalise($e);
                $recv = (string)($e['receivedAt'] ?? '');
                if ($recv > $newest) $newest = $recv;
            }
        }
        $out['newest'] = $newest;
        $out['ok'] = true;
        return $out;
    }

    private function normalise(array $e): array
    {
        $body = '';
        foreach (($e['textBody'] ?? []) as $part) {
            $pid = $part['partId'] ?? null;
            if ($pid !== null && isset($e['bodyValues'][$pid]['value'])) {
                $body .= $e['bodyValues'][$pid]['value'] . "\n";
            }
        }
        if (trim($body) === '') $body = (string)($e['preview'] ?? '');

        $headers = [];
        foreach (self::WANTED_HEADERS as $hdr) {
            $v = $e['header:' . $hdr . ':asText'] ?? null;
            if ($v !== null && trim((string)$v) !== '') $headers[strtolower($hdr)] = trim((string)$v);
        }

        return [
            'jmap_id'     => (string)($e['id'] ?? ''),
            'message_id'  => (string)(($e['messageId'][0] ?? null)
                                ?? ($headers['message-id'] ?? '')
                                ?: 'jmap-' . (string)($e['id'] ?? '')),
            'thread_id'   => (string)($e['threadId'] ?? ''),
            'from'        => (string)($e['from'][0]['email'] ?? ''),
            'from_name'   => (string)($e['from'][0]['name'] ?? ''),
            'to'          => array_map(function ($t) { return (string)($t['email'] ?? ''); },
                                       (array)($e['to'] ?? [])),
            'cc'          => array_map(function ($t) { return (string)($t['email'] ?? ''); },
                                       (array)($e['cc'] ?? [])),
            'attachments' => self::attachmentNames($e),
            'subject'     => (string)($e['subject'] ?? ''),
            'received_at' => (string)($e['receivedAt'] ?? ''),
            'body'        => $body,
            'headers'     => $headers,
        ];
    }

    /**
     * Filenames of what the customer attached.
     *
     * An unnamed part still counts — the fact that something was attached is
     * the signal, not what it was called — so it is reported by type instead
     * of dropped.
     *
     * @return string[]
     */
    private static function attachmentNames(array $e): array
    {
        $out = [];
        foreach ((array)($e['attachments'] ?? []) as $a) {
            if (!is_array($a)) continue;
            $name = trim((string)($a['name'] ?? ''));
            if ($name === '') $name = '(unnamed ' . (string)($a['type'] ?? 'file') . ')';
            $out[] = $name;
        }
        // Some servers report the flag without listing the parts.
        if ($out === [] && !empty($e['hasAttachment'])) $out[] = '(attachment)';
        return $out;
    }

    /** @return array|null */
    private function call(string $method, string $url, ?array $body = null)
    {
        $headers = [
            'Authorization: Basic ' . base64_encode($this->user . ':' . $this->pass),
            'Content-Type: application/json',
        ];
        if ($this->http) {
            $r = ($this->http)($method, $url, $headers, $body);
            return is_array($r) ? $r : null;
        }
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
        return is_array($j) ? $j : null;
    }
}
