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
            $why = $this->transportProblem();
            $out['error'] = 'the JMAP session could not be opened'
                          . ($why !== '' ? ' — ' . $why : '');
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
    /**
     * What the last request actually did.
     *
     * The first version of call() returned null for everything that was not
     * valid JSON, so a name that would not resolve, a certificate that would
     * not verify, a wrong password and a wrong path all surfaced as the same
     * sentence: "the JMAP session could not be opened (check the URL and
     * credentials)". Four different faults, one message, and no way to tell
     * from the outside which one you had.
     *
     * @return array{status:int, error:string, body:string, url:string, location:string}
     */
    public function lastTransport(): array
    {
        return $this->lastTransport;
    }

    /** @var array{status:int, error:string, body:string, url:string, location:string} */
    private $lastTransport = ['status' => 0, 'error' => '', 'body' => '',
                              'url' => '', 'location' => ''];

    /**
     * A sentence naming what went wrong. Never empty when something did.
     *
     * The first version could return '' — and did, for the one candidate that
     * was actually reachable, which printed as a bare "the JMAP session could
     * not be opened" with nothing after it. A diagnostic that goes quiet on
     * the interesting case is worse than none, because it reads as though
     * that case were less informative than the failures around it.
     */
    public function transportProblem(): string
    {
        $t     = $this->lastTransport;
        $bytes = strlen($t['body']);
        $snip  = $bytes > 0 ? ' · ' . str_replace("\n", ' ', substr($t['body'], 0, 120)) : '';

        if ($t['error'] !== '')  return 'could not reach ' . $t['url'] . ': ' . $t['error'];

        if ($t['status'] === 401) {
            return 'HTTP 401 — the mailbox address or password is wrong';
        }
        if ($t['status'] === 403) {
            // Not necessarily auth: a web server in front of the wrong host
            // refuses the path just as readily.
            return 'HTTP 403 refused — either the credentials, or this is not '
                 . 'a JMAP endpoint at all' . $snip;
        }
        if ($t['status'] === 404) {
            return 'HTTP 404 — this URL serves no JMAP session';
        }
        if ($t['status'] >= 300 && $t['status'] < 400) {
            return 'HTTP ' . $t['status'] . ' redirect to ' . ($t['location'] ?: '(no Location)');
        }
        if ($t['status'] >= 400) {
            return 'HTTP ' . $t['status'] . $snip;
        }
        if ($t['status'] === 200) {
            return $bytes === 0
                ? 'HTTP 200 but an empty body — reachable, but not answering JMAP here'
                : 'HTTP 200, ' . $bytes . ' bytes, not a JMAP session' . $snip;
        }
        return 'no response' . ($t['status'] ? ' (HTTP ' . $t['status'] . ')' : '');
    }

    /**
     * Pin a hostname to an address, as curl --resolve does.
     *
     * Needed because the certificate is issued for the public mail name while
     * the only reachable copy is the container next door: connecting to the
     * container by its docker name fails the certificate check, and connecting
     * to the public name leaves the datacentre and comes back through a proxy.
     * This asks for the right name at the right address.
     *
     * @param string[] $entries  each "host:port:ip"
     */
    public function setResolve(array $entries): void
    {
        $this->resolve = array_values(array_filter(array_map('strval', $entries)));
    }

    /** @var string[] */
    private $resolve = [];

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
            CURLOPT_HEADER         => true,
        ]);
        if ($this->resolve !== []) curl_setopt($ch, CURLOPT_RESOLVE, $this->resolve);
        if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        $raw     = (string)curl_exec($ch);
        $status  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $hdrSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $err     = (string)curl_error($ch);
        curl_close($ch);

        $head = substr($raw, 0, $hdrSize);
        $raw  = substr($raw, $hdrSize);

        // Redirects are reported rather than followed. Following one would
        // carry the Authorization header to wherever it pointed, and that
        // header is the mailbox password.
        $loc = '';
        if (preg_match('/^Location:\s*(.+)$/mi', $head, $m)) $loc = trim($m[1]);

        $this->lastTransport = [
            'status'   => $status,
            'error'    => $err,
            'body'     => trim($raw),
            'url'      => $url,
            'location' => $loc,
        ];

        $j = json_decode((string)$raw, true);
        return is_array($j) ? $j : null;
    }
}
