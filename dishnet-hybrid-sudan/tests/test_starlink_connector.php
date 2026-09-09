<?php
/**
 * test_starlink_connector.php — Uganda's Starlink session, proved without one.
 *
 * Everything here runs against a fake Starlink, so the connector is finished
 * and verified before a real cookie exists. When the Uganda session is
 * imported, the only untested thing left is whether Starlink accepts it.
 *
 * The mechanism is adapted from the South Sudan implementation. These tests
 * pin the parts that were expensive to learn there — the header set, cookie
 * merging, not following redirects, refreshing then verifying — so an
 * "improvement" that undoes one of them fails here rather than in production.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/lib/StarlinkSessionStore.php';
require_once $root . '/lib/StarlinkPortalConnector.php';

$pass = 0; $fail = 0;
function ok(string $m): void  { global $pass; $pass++; echo "  ok   {$m}\n"; }
function bad(string $m, string $d=''): void { global $fail; $fail++; echo "  FAIL {$m}\n"; if($d)echo "       {$d}\n"; }
function is_(bool $c, string $m, string $d=''): void { $c ? ok($m) : bad($m, $d); }

$tmp = sys_get_temp_dir() . '/dn_sl_' . bin2hex(random_bytes(4));
@mkdir($tmp . '/data', 0755, true);
function freshStore(string $tmp): StarlinkSessionStore
{
    foreach (glob($tmp . '/data/*') ?: [] as $f) @unlink($f);
    return new StarlinkSessionStore($tmp, $tmp . '/data');
}

/** A fake Starlink. Records what it was asked, answers what it is told to. */
function fakeStarlink(array $script, array &$seen): callable
{
    return function (string $method, string $url, array $headers, $body) use ($script, &$seen) {
        $seen[] = ['method' => $method, 'url' => $url, 'headers' => $headers];
        foreach ($script as $match => $reply) {
            if (strpos($url, $match) !== false) return $reply;
        }
        return ['code' => 404, 'body' => '', 'cookies' => [], 'error' => ''];
    };
}

echo "\nThe session is encrypted at rest, and no password is ever held\n";
$store = freshStore($tmp);
$store->importCookie('sid=abc123; token=xyz789', 'bhavin', 'ops@dishnetuganda.com', '000-1');
$onDisk = (string)file_get_contents($store->path());
is_(strpos($onDisk, 'abc123') === false, 'the cookie value is not in the file');
is_(strpos($onDisk, 'xyz789') === false, 'nor any part of it');
is_($store->cookie() === 'sid=abc123; token=xyz789', 'but it decrypts back exactly');
is_(strpos($onDisk, 'password') === false, 'there is no password field to leak');

echo "\nAn operator can see the shape of a session without seeing the session\n";
$st = $store->status();
is_($st['cookie_names'] === ['sid', 'token'], 'the names are shown', json_encode($st['cookie_names']));
is_(strpos(json_encode($st), 'abc123') === false, 'the values never are');
is_($st['account_email'] === 'ops@dishnetuganda.com', 'the account is recorded');

echo "\nUganda's store is Uganda's alone\n";
// Comments stripped first. These files DOCUMENT the rule that they never
// read the other plugins' files, so a plain grep finds the rule and calls it
// a violation — it cannot tell a promise from a breach.
$src = php_strip_whitespace($root . '/lib/StarlinkSessionStore.php')
     . php_strip_whitespace($root . '/lib/StarlinkPortalConnector.php');
foreach (['dr_accounts.json', 'sl_kits.json', 'dr_kit_registry.json', 'dishnet-data-report',
          'dishnet-starlink-finance'] as $foreign) {
    is_(strpos($src, $foreign) === false,
        "nothing reads {$foreign} — the mechanism travels, the data does not");
}
is_(strpos($store->path(), '/data/starlink_session.json') !== false,
    'and the session lives in Hybrid\'s own data directory', $store->path());

echo "\nThe header set is the proven one, unrotated\n";
// Rotation was tried upstream as anti-ban protection, broke telemetry, and was
// reverted. Inheriting the fix means not repeating the mistake.
$conn = new StarlinkPortalConnector($store, []);
$h = $conn->headers('sid=abc');
is_(count($h) === 9, 'nine headers, exactly as upstream sends them', (string)count($h));
is_(in_array('cookie: sid=abc', $h, true), 'the cookie is carried');
$again = $conn->headers('sid=abc');
is_($h === $again, 'and two calls produce identical headers — nothing rotates');
$connSrc = (string)file_get_contents($root . '/lib/StarlinkPortalConnector.php');
is_(strpos($connSrc, 'CURLOPT_FOLLOWLOCATION => false') !== false,
    'redirects are not followed — following one turns a 200 into a 401');

echo "\nCookie merging keeps what it was not told about\n";
$merged = StarlinkPortalConnector::mergeCookies('a=1; b=2; c=3', ['b' => '9']);
is_($merged === 'a=1; b=9; c=3', 'one value changes, order and the rest survive', $merged);
is_(StarlinkPortalConnector::mergeCookies('a=1', []) === 'a=1', 'nothing new means no change');
is_(StarlinkPortalConnector::mergeCookies('a=1', ['d' => '4']) === 'a=1; d=4',
    'a new cookie is appended');
is_(StarlinkPortalConnector::parseSetCookie(['Set-Cookie: s=1; Path=/; HttpOnly', 'Date: x'])
    === ['s' => '1'], 'Set-Cookie is parsed and its attributes dropped');

echo "\nA good request works and records success\n";
$seen = [];
$store = freshStore($tmp);
$store->importCookie('sid=abc', 'bhavin');
$conn = new StarlinkPortalConnector($store, [], fakeStarlink([
    '/api/webagg/v2/accounts/service-lines' =>
        ['code' => 200, 'body' => '{"results":[{"serviceLineNumber":"SL-1"}]}', 'cookies' => [], 'error' => ''],
], $seen));
$r = $conn->get('/api/webagg/v2/accounts/service-lines');
is_(!empty($r['ok']), 'the call succeeds', (string)$r['error']);
is_(($r['data']['results'][0]['serviceLineNumber'] ?? '') === 'SL-1', 'and the JSON comes back');
is_($store->status()['state'] === StarlinkSessionStore::STATE_ACTIVE, 'the session reads active');
is_($store->status()['last_ok_at'] !== '', 'and the success is dated');

echo "\nA rotated cookie is kept, even when it arrives with a failure\n";
$seen = [];
$store = freshStore($tmp);
$store->importCookie('sid=old', 'bhavin');
$conn = new StarlinkPortalConnector($store, [], fakeStarlink([
    '/api/anything' => ['code' => 500, 'body' => '', 'cookies' => ['sid' => 'new'], 'error' => ''],
], $seen));
$conn->get('/api/anything');
is_($store->cookie() === 'sid=new',
    'a newer cookie is the newer cookie whatever the status code was', $store->cookie());

echo "\nAn expired session refreshes once, then retries\n";
$seen = [];
$store = freshStore($tmp);
$store->importCookie('sid=stale', 'bhavin');
$calls = 0;
$conn = new StarlinkPortalConnector($store, [], function (string $m, string $u, array $h, $b)
        use (&$calls, &$seen) {
    $seen[] = $u; $calls++;
    if (strpos($u, '/refresh') !== false) {
        return ['code' => 200, 'body' => '', 'cookies' => ['sid' => 'renewed'], 'error' => ''];
    }
    if (strpos($u, '/accounts/contact') !== false) {
        return ['code' => 200, 'body' => '{"email":"ops@dishnetuganda.com"}', 'cookies' => [], 'error' => ''];
    }
    // Unauthorised while the cookie is stale; fine once it is renewed.
    $cookieHeader = '';
    foreach ($h as $line) if (stripos($line, 'cookie:') === 0) $cookieHeader = $line;
    return strpos($cookieHeader, 'renewed') !== false
        ? ['code' => 200, 'body' => '{"ok":true}', 'cookies' => [], 'error' => '']
        : ['code' => 401, 'body' => '', 'cookies' => [], 'error' => ''];
});
$r = $conn->get('/api/webagg/v2/accounts/service-lines');
is_(!empty($r['ok']), 'the retry after refresh succeeds', (string)$r['error']);
is_($store->cookie() === 'sid=renewed', 'the renewed cookie is kept');
$refreshCalls = count(array_filter($seen, function ($u) { return strpos($u, '/refresh') !== false; }));
is_($refreshCalls === 1, 'the refresh happened once, not in a loop', (string)$refreshCalls);
$verify = count(array_filter($seen, function ($u) { return strpos($u, '/accounts/contact') !== false; }));
is_($verify === 1, 'and the new cookie was verified before being trusted');

echo "\nA refresh that is accepted but does not work is not trusted\n";
$store = freshStore($tmp);
$store->importCookie('sid=stale', 'bhavin');
$conn = new StarlinkPortalConnector($store, [], function (string $m, string $u, array $h, $b) {
    if (strpos($u, '/refresh') !== false) {
        return ['code' => 200, 'body' => '', 'cookies' => ['sid' => 'bogus'], 'error' => ''];
    }
    return ['code' => 401, 'body' => '', 'cookies' => [], 'error' => ''];   // verify also fails
});
$r = $conn->get('/api/webagg/v2/accounts/service-lines');
is_(empty($r['ok']), 'the call fails rather than reporting a working session');
is_(strpos((string)$r['error'], 'could not be refreshed') !== false,
    'and says the session could not be refreshed', (string)$r['error']);

echo "\nBeing asked to slow down is obeyed\n";
$store = freshStore($tmp);
$store->importCookie('sid=abc', 'bhavin');
$conn = new StarlinkPortalConnector($store, [], fakeStarlink([
    '/api/' => ['code' => 429, 'body' => '', 'cookies' => [], 'error' => ''],
], $seen));
$r = $conn->get('/api/webagg/v2/accounts/service-lines');
is_(empty($r['ok']) && (int)$r['code'] === 429, 'a 429 fails the call');
is_(!empty($r['retryable']), 'and is marked retryable — it is not a wrong answer, just a wait');
is_($store->isThrottled(), 'the backoff is recorded');
$r2 = $conn->get('/api/webagg/v2/accounts/service-lines');
is_(strpos((string)$r2['error'], 'backing off') !== false,
    'and the next call does not even leave the building', (string)$r2['error']);

echo "\nA session that keeps failing is declared dead, not retried forever\n";
$store = freshStore($tmp);
$store->importCookie('sid=abc', 'bhavin');
for ($i = 0; $i < StarlinkSessionStore::MAX_FAILURES; $i++) $store->markFailure('nope');
is_($store->needsReimport(), 'after enough failures it needs a person');
$conn = new StarlinkPortalConnector($store, [], fakeStarlink([], $seen));
$r = $conn->get('/api/whatever');
is_(strpos((string)$r['error'], 'needs a fresh cookie') !== false,
    'and says so instead of hammering Starlink with a dead session', (string)$r['error']);
$store->importCookie('sid=fresh', 'bhavin');
is_(!$store->needsReimport(), 'importing a new one clears the verdict');
is_($store->status()['failures'] === 0, 'and the failure count with it');

echo "\nWith nothing imported, it says so plainly\n";
$store = freshStore($tmp);
$conn = new StarlinkPortalConnector($store, []);
is_(!$conn->isConfigured(), 'it reports itself unconfigured');
$r = $conn->get('/api/anything');
is_(strpos((string)$r['error'], 'no Starlink session has been imported') !== false,
    'and names what is missing', (string)$r['error']);
$v = $conn->verify();
is_(empty($v['ok']), 'verify fails too, without pretending');

echo "\nA cookie that is not a cookie is refused at the door\n";
$store = freshStore($tmp);
$r = $store->importCookie('I pasted the whole page by mistake', 'bhavin');
is_(empty($r['ok']), 'nonsense is not stored');
is_(strpos((string)$r['error'], 'does not look like a cookie') !== false,
    'with an error that says what was expected', (string)$r['error']);

foreach (glob($tmp . '/data/*') ?: [] as $f) @unlink($f);
@rmdir($tmp . '/data'); @rmdir($tmp);

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
