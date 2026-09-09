<?php
/**
 * test_jmap_mailbox.php — reading the mailbox, with a fake server.
 *
 * The point of interest is the headers. InboundMailFilter decides whether a
 * message may be answered by reading Auto-Submitted, List-Id, Precedence and
 * the rest; a reader that drops them turns the loop guard into a guess. So
 * these tests care most that the headers arrive and arrive lower-cased, ready
 * for the filter.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/lib/JmapMailbox.php';
require_once $root . '/lib/InboundMailFilter.php';

$pass = 0; $fail = 0;
function ok(string $m): void  { global $pass; $pass++; echo "  ok   {$m}\n"; }
function bad(string $m, string $d=''): void { global $fail; $fail++; echo "  FAIL {$m}\n"; if($d)echo "       {$d}\n"; }
function is_(bool $c, string $m, string $d=''): void { $c ? ok($m) : bad($m, $d); }

// A fake JMAP server: session, then one query/get pair.
$calls = [];
$fake = function (string $method, string $url, array $headers, ?array $body) use (&$calls) {
    $calls[] = ['method' => $method, 'url' => $url, 'body' => $body, 'headers' => $headers];
    if ($method === 'GET') {
        return ['apiUrl' => 'https://mail.example/jmap/', 'accounts' => ['acc1' => ['name' => 'x']]];
    }
    return ['methodResponses' => [
        ['Email/query', ['ids' => ['e1', 'e2']], 'q'],
        ['Email/get', ['list' => [
            [
                'id' => 'e1', 'threadId' => 't1',
                'messageId' => ['<real@customer.com>'],
                'from' => [['email' => 'felix@customer.co.ug', 'name' => 'Felix Orech']],
                'to'   => [['email' => 'accounts@dishnetuganda.com']],
                'subject' => 'Starlink Mini pricing',
                'receivedAt' => '2026-09-09T08:00:00Z',
                'textBody' => [['partId' => '1']],
                'bodyValues' => ['1' => ['value' => "Hello,\nHow much is the monthly package?\nFelix"]],
                'header:Return-Path:asText' => '<felix@customer.co.ug>',
            ],
            [
                'id' => 'e2', 'threadId' => 't2',
                'messageId' => ['<ooo@corp.com>'],
                'from' => [['email' => 'someone@corp.com', 'name' => 'Someone']],
                'subject' => 'Automatic reply: Starlink Mini pricing',
                'receivedAt' => '2026-09-09T09:00:00Z',
                'preview' => 'I am away until Monday.',
                'header:Auto-Submitted:asText' => 'auto-replied',
                'header:Precedence:asText'     => 'bulk',
            ],
        ]], 'g'],
    ]];
};

echo "\nReading the mailbox\n";
$mb = new JmapMailbox('https://mail.example', 'accounts@dishnetuganda.com', 'pw', $fake);
is_($mb->isConfigured(), 'it reports itself configured');
$r = $mb->fetch('', 25);
is_($r['ok'], 'a fetch succeeds', $r['error']);
is_(count($r['emails']) === 2, 'both messages come back', 'got ' . count($r['emails']));

$e1 = $r['emails'][0];
is_($e1['from'] === 'felix@customer.co.ug', 'the sender address is read');
is_($e1['from_name'] === 'Felix Orech', 'and the display name');
is_($e1['subject'] === 'Starlink Mini pricing', 'the subject');
is_($e1['message_id'] === '<real@customer.com>', 'the RFC Message-ID, not the JMAP id');
is_($e1['thread_id'] === 't1', 'the thread, so a reply can be attached to it');
is_(strpos($e1['body'], 'How much is the monthly package?') !== false,
    'and the body text, not just the preview');

echo "\nThe headers the loop guard needs actually arrive\n";
is_(isset($e1['headers']['return-path']), 'a header is present');
is_($e1['headers']['return-path'] === '<felix@customer.co.ug>', 'with its value intact');
is_(array_keys($e1['headers']) === array_map('strtolower', array_keys($e1['headers'])),
    'and lower-cased, which is what InboundMailFilter expects');
$e2 = $r['emails'][1];
is_(($e2['headers']['auto-submitted'] ?? '') === 'auto-replied',
    'the autoresponder header survives the round trip');
is_(($e2['headers']['precedence'] ?? '') === 'bulk', 'and Precedence');

echo "\nAsking for them in the first place\n";
$post = null;
foreach ($calls as $c) if ($c['method'] === 'POST') $post = $c;
is_($post !== null, 'a POST was made');
$props = $post['body']['methodCalls'][1][1]['properties'] ?? [];
is_(in_array('header:Auto-Submitted:asText', $props, true),
    'Auto-Submitted is requested by name');
is_(in_array('header:List-Id:asText', $props, true), 'List-Id is requested');
is_(in_array('header:Return-Path:asText', $props, true), 'Return-Path is requested');
is_(count(array_filter($props, function ($p) { return strpos($p, 'header:') === 0; }))
      === count(JmapMailbox::WANTED_HEADERS),
    'every header the filter reads is asked for, none forgotten');

echo "\nThe reader and the filter agree end to end\n";
$ours = ['accounts@dishnetuganda.com'];
$v1 = InboundMailFilter::assess($e1['headers'], $e1['from'], $e1['subject'], $ours);
is_(!$v1['ignore'], 'the real customer question is answerable');
$v2 = InboundMailFilter::assess($e2['headers'], $e2['from'], $e2['subject'], $ours);
is_($v2['ignore'], "the autoresponder is refused  ({$v2['reason']})");

echo "\nThe cursor moves forward so history is not re-read\n";
is_($r['newest'] === '2026-09-09T09:00:00Z', 'the newest timestamp is reported back',
    'got ' . $r['newest']);
$r2 = $mb->fetch('2026-09-09T08:30:00Z', 25);
$q = null;
foreach ($calls as $c) if ($c['method'] === 'POST') $q = $c;
is_(($q['body']['methodCalls'][0][1]['filter']['after'] ?? '') === '2026-09-09T08:30:00Z',
    'and a later fetch asks only for what came after it');

echo "\nFailures are reported, not thrown\n";
$dead = new JmapMailbox('https://mail.example', 'u', 'p', function () { return null; });
$r3 = $dead->fetch();
is_(!$r3['ok'] && $r3['error'] !== '', 'a dead server gives an error, not an exception');
$unset = new JmapMailbox('', '', '', $fake);
is_(!$unset->isConfigured(), 'an unconfigured mailbox says so');
is_(!$unset->fetch()['ok'], 'and refuses to fetch');

echo "\nThe diagnostic never goes quiet\n";
// The one candidate that was actually reachable printed a bare "the JMAP
// session could not be opened" with nothing after it, because the explainer
// returned '' for a 200 with no body. The reachable case must be the most
// informative line, not the least.
$cases = [
    [['status' => 200, 'error' => '', 'body' => '',        'location' => ''], 'empty body'],
    [['status' => 200, 'error' => '', 'body' => 'hello',   'location' => ''], 'not a JMAP session'],
    [['status' => 302, 'error' => '', 'body' => '',        'location' => 'https://x/'], 'redirect'],
    [['status' => 401, 'error' => '', 'body' => '',        'location' => ''], 'password is wrong'],
    [['status' => 403, 'error' => '', 'body' => '',        'location' => ''], 'refused'],
    [['status' => 404, 'error' => '', 'body' => '',        'location' => ''], 'no JMAP session'],
    [['status' => 502, 'error' => '', 'body' => 'Bad Gateway', 'location' => ''], 'HTTP 502'],
    [['status' => 0,   'error' => 'Could not resolve host', 'body' => '', 'location' => ''], 'Could not resolve'],
];
foreach ($cases as [$t, $expect]) {
    $box = new JmapMailbox('http://x', 'u', 'p');
    $ref = new ReflectionProperty(JmapMailbox::class, 'lastTransport');
    $ref->setAccessible(true);
    $ref->setValue($box, $t + ['url' => 'http://x']);
    $said = $box->transportProblem();
    is_($said !== '' && stripos($said, $expect) !== false,
        'status ' . $t['status'] . ($t['error'] ? ' / ' . $t['error'] : '') . ' → says "' . $expect . '"',
        'said: ' . $said);
}

echo "\nA pinned address is carried into the request\n";
$seen = null;
$box  = new JmapMailbox('https://mail.example.com', 'u', 'p',
    function ($m, $u, $h, $b) use (&$seen) { $seen = $u; return null; });
$box->setResolve(['mail.example.com:443:172.20.0.4']);
$box->fetch('', 1);
is_($seen === 'https://mail.example.com/.well-known/jmap',
    'the session is still requested by its proper name', 'got: ' . var_export($seen, true));

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
