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

        $this->applyVia();

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

        // Read the INBOX, not the account.
        //
        // Without this the query spans every mailbox, so the first live run
        // read seventeen messages of which fourteen were our own quotations
        // sitting in Sent Items. Nothing was drafted for them only because
        // the loop guard recognised our own address — a second line of
        // defence doing the first line's job. Drafts, Trash and Spam are in
        // scope on that query too, and a reply drafted to something in Spam
        // is a reply drafted to whatever put it there.
        $inbox = $this->inboxId($apiUrl, $accountId);
        if ($inbox === '') {
            $out['error'] = 'the account has no mailbox with the inbox role — '
                          . 'refusing to read every folder instead';
            return $out;
        }

        $filter = ['inMailbox' => $inbox];
        if ($since !== '') $filter['after'] = $since;

        $resp = $this->call('POST', $apiUrl, [
            'using' => ['urn:ietf:params:jmap:core', 'urn:ietf:params:jmap:mail'],
            'methodCalls' => [
                ['Email/query', [
                    'accountId' => $accountId,
                    'filter'    => $filter,
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

    /**
     * The id of the mailbox mail actually arrives in.
     *
     * By role rather than by name: "Inbox" is localised and renameable, while
     * the role is what the protocol guarantees. If no mailbox claims the role,
     * the caller is told rather than quietly falling back to every folder —
     * reading the wrong folders is the fault being fixed, so a fallback to
     * exactly that would be no fix at all.
     */
    private function inboxId(string $apiUrl, string $accountId): string
    {
        $r = $this->call('POST', $apiUrl, [
            'using' => ['urn:ietf:params:jmap:core', 'urn:ietf:params:jmap:mail'],
            'methodCalls' => [
                ['Mailbox/get', [
                    'accountId'  => $accountId,
                    'properties' => ['id', 'name', 'role'],
                ], 'm'],
            ],
        ]);

        foreach ((array)($r['methodResponses'] ?? []) as $mr) {
            if (($mr[0] ?? '') !== 'Mailbox/get') continue;
            foreach ((array)($mr[1]['list'] ?? []) as $box) {
                if (strtolower((string)($box['role'] ?? '')) === 'inbox') {
                    return (string)($box['id'] ?? '');
                }
            }
        }
        return '';
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

    /**
     * Reach the server's proper hostname through a neighbouring container.
     *
     * The certificate is issued for the public mail name; the only reachable
     * copy is the container next door. Pinning a fixed address would work
     * until the mail stack is recreated and the address changes — the same
     * rot that already costs us the docker network attachment. So the docker
     * NAME is stored and resolved at connect time, and an address change
     * costs nothing.
     */
    public function setVia(string $dockerName): void
    {
        $this->via = trim($dockerName);
    }

    /** @var string */
    private $via = '';

    /** Turn $via into a pin for this base URL, if it resolves. */
    private function applyVia(): void
    {
        if ($this->via === '' || $this->resolve !== []) return;

        $ip = gethostbyname($this->via);
        if ($ip === $this->via || !filter_var($ip, FILTER_VALIDATE_IP)) {
            // Left unpinned deliberately: the request then fails with a real
            // transport error naming the host, which is more useful than a
            // pin quietly built from nothing.
            return;
        }
        $u    = parse_url($this->baseUrl);
        $host = (string)($u['host'] ?? '');
        if ($host === '') return;
        $port = (int)($u['port'] ?? (($u['scheme'] ?? '') === 'https' ? 443 : 80));

        $this->resolve = [$host . ':' . $port . ':' . $ip];
    }

    /**
     * Is $to the same origin as $from — same scheme, host and port?
     *
     * This is the whole safety question for following a redirect. The request
     * carries Basic authentication, and that header is the mailbox password;
     * sending it to a host we were merely pointed at would hand the password
     * to whoever controls the pointer. Staying on the same origin sends it
     * only back where it was already going.
     */
    public static function sameOrigin(string $from, string $to): bool
    {
        $a = parse_url($from);
        $b = parse_url($to);
        if (!is_array($a) || !is_array($b)) return false;

        $port = function (array $u): int {
            if (isset($u['port'])) return (int)$u['port'];
            return ($u['scheme'] ?? '') === 'https' ? 443 : 80;
        };
        return strtolower((string)($a['scheme'] ?? '')) === strtolower((string)($b['scheme'] ?? ''))
            && strtolower((string)($a['host']   ?? '')) === strtolower((string)($b['host']   ?? ''))
            && $port($a) === $port($b);
    }

    /** Turn a Location value into an absolute URL against the request it answered. */
    public static function absolutise(string $location, string $base): string
    {
        $location = trim($location);
        if ($location === '') return '';
        if (preg_match('#^https?://#i', $location)) return $location;

        $u = parse_url($base);
        if (!is_array($u) || !isset($u['scheme'], $u['host'])) return '';
        $root = $u['scheme'] . '://' . $u['host'] . (isset($u['port']) ? ':' . $u['port'] : '');

        if ($location[0] === '/') return $root . $location;
        $dir = rtrim(dirname((string)($u['path'] ?? '/')), '/');
        return $root . $dir . '/' . $location;
    }

    private function call(string $method, string $url, ?array $body = null, int $hop = 0)
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

        // Stalwart answers /.well-known/jmap with a 307 to /jmap/session: the
        // well-known path is a pointer, and refusing every redirect meant
        // never arriving. Same-origin redirects are followed — 307 preserves
        // the method and body, which is what the JMAP call needs — and a
        // redirect that leaves the origin is reported instead, because
        // following it would carry the mailbox password to a host we were
        // merely pointed at.
        if ($status >= 300 && $status < 400 && $loc !== '' && $hop < 3) {
            $next = self::absolutise($loc, $url);
            if ($next !== '' && self::sameOrigin($url, $next)) {
                return $this->call($method, $next, $body, $hop + 1);
            }
            $this->lastTransport['error'] =
                'redirected off this host to ' . $next . ' — not followed, because the '
              . 'request carries the mailbox password';
            return null;
        }

        $j = json_decode((string)$raw, true);
        return is_array($j) ? $j : null;
    }
}
