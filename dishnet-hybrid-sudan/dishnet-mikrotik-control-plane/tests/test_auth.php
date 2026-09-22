<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
use Dn\Api\Routes;
use Dn\Auth\Authenticator;
use Dn\Db\Database;
use Dn\Http\Kernel;
use Dn\Http\Request;
use Dn\Tenancy\TenantContext;

$owner = Database::inspector();
$ids = seed_two_customers($owner);
$A = $ids['A']; $B = $ids['B'];
$phoneA = '+256700001001';
$phoneB = '+256700001002';

$db   = Database::app();
$auth = new Authenticator($db);
$k    = new Kernel(Routes::build($auth), $db, $auth, new TenantContext($db));
$call = fn(string $m, string $p, array $body = [], string $tok = '')
    => $k->handle(new Request($m, $p, $tok ? ['Authorization' => "Bearer {$tok}"] : [], $body));

putenv('DNB_EXPOSE_OTP=1');

// ---------------------------------------------------------------------------
t('sign in end to end');
$r = $call('POST', '/api/v1/auth/request-code', ['phone' => $phoneA]);
is_($r->status, 202, 'request-code accepted');
$code = $r->body['dev_code'];
is_(strlen($code), 6, 'code is six digits');

$r = $call('POST', '/api/v1/auth/verify', ['phone' => $phoneA, 'code' => $code]);
is_($r->status, 200, 'verify succeeds');
$tokenA = $r->body['token'] ?? '';
is_(strlen($tokenA), 64, 'token is 32 bytes hex');

$r = $call('GET', '/api/v1/me', [], $tokenA);
is_($r->status, 200, 'the token authenticates');
is_($r->body['customer']['name'], 'Riverside Hotel', 'and resolves to the right customer');

// ---------------------------------------------------------------------------
t('ENUMERATION — request-code cannot reveal who has an account');
$known   = $call('POST', '/api/v1/auth/request-code', ['phone' => $phoneB]);
$unknown = $call('POST', '/api/v1/auth/request-code', ['phone' => '+256799999999']);
is_($known->status, $unknown->status, 'same status for a registered and an unregistered phone');
is_(array_keys($known->body), array_keys($unknown->body), 'same response shape');

t('ENUMERATION — an unregistered phone cannot verify, and fails identically');
$bogus = $unknown->body['dev_code'];
$r1 = $call('POST', '/api/v1/auth/verify', ['phone' => '+256799999999', 'code' => $bogus]);
is_($r1->status, 401, 'a correct code for an unregistered phone still fails');
$r2 = $call('POST', '/api/v1/auth/verify', ['phone' => $phoneA, 'code' => '000000']);
is_($r2->status, $r1->status, 'wrong code and unknown phone give the same status');
is_($r1->body, $r2->body, 'and the same body — the reason is never disclosed');

// ---------------------------------------------------------------------------
t('a code is single use');
$r = $call('POST', '/api/v1/auth/request-code', ['phone' => $phoneA]);
$c = $r->body['dev_code'];
is_($call('POST', '/api/v1/auth/verify', ['phone' => $phoneA, 'code' => $c])->status, 200, 'first use works');
is_($call('POST', '/api/v1/auth/verify', ['phone' => $phoneA, 'code' => $c])->status, 401, 'second use is refused');

t('a code expires');
$r = $call('POST', '/api/v1/auth/request-code', ['phone' => $phoneA]);
$c = $r->body['dev_code'];
$owner->exec("UPDATE mt_auth_codes SET expires_at = now() - interval '1 minute'
               WHERE phone = ? AND consumed_at IS NULL", [$phoneA]);
is_($call('POST', '/api/v1/auth/verify', ['phone' => $phoneA, 'code' => $c])->status, 401, 'an expired code is refused');

t('guessing is bounded');
$r = $call('POST', '/api/v1/auth/request-code', ['phone' => $phoneA]);
$real = $r->body['dev_code'];
for ($i = 0; $i < 5; $i++) { $call('POST', '/api/v1/auth/verify', ['phone' => $phoneA, 'code' => '999999']); }
is_($call('POST', '/api/v1/auth/verify', ['phone' => $phoneA, 'code' => $real])->status, 401,
    'after repeated wrong attempts even the correct code is refused');

t('code requests are rate limited');
// The seed leaves earlier codes in the window; drain to a known state first.
$owner->exec('DELETE FROM mt_auth_codes WHERE phone = ?', [$phoneB]);
$statuses = [];
for ($i = 0; $i < 7; $i++) {
    $statuses[] = $call('POST', '/api/v1/auth/request-code', ['phone' => $phoneB])->status;
}
is_(array_slice($statuses, 0, 5), [202,202,202,202,202], 'the first five are served');
is_($statuses[5], 429, 'the sixth is 429 — a rate limit, not a 500');
is_($statuses[6], 429, 'and it stays 429');
is_(in_array(500, $statuses, true), false,
    'a throttled client NEVER sees a 500 (which would send an operator hunting a fault)');

// ---------------------------------------------------------------------------
t('tokens: revocation, expiry, forgery');
// earlier sections filled this phone's rate-limit window
$owner->exec('DELETE FROM mt_auth_codes WHERE phone = ?', [$phoneA]);
$r = $call('POST', '/api/v1/auth/request-code', ['phone' => $phoneA]);
$owner->exec('UPDATE mt_auth_codes SET attempts = 0 WHERE phone = ?', [$phoneA]);
$tokenA = $call('POST', '/api/v1/auth/verify',
    ['phone' => $phoneA, 'code' => $r->body['dev_code']])->body['token'];
is_($call('GET', '/api/v1/me', [], $tokenA)->status, 200, 'a fresh token works');

is_($call('POST', '/api/v1/auth/logout', [], $tokenA)->status, 204, 'logout succeeds');
is_($call('GET', '/api/v1/me', [], $tokenA)->status, 401, 'the token stops working after logout');

is_($call('GET', '/api/v1/me', [], str_repeat('a', 64))->status, 401, 'a forged token is refused');
is_($call('GET', '/api/v1/me', [], '')->status, 401, 'no token is refused');
is_($call('GET', '/api/v1/me')->status, 401, 'a missing Authorization header is refused');

t('a token stored in the database is a hash, never the token');
$rows = $owner->query('SELECT token_hash FROM mt_auth_sessions');
$plain = array_filter($rows, fn($x) => strlen($x['token_hash']) === 64 && $x['token_hash'] === $tokenA);
is_(count($plain), 0, 'the plaintext token appears nowhere in mt_auth_sessions');

t('a disabled principal cannot use a token it already holds');
$owner->exec('DELETE FROM mt_auth_codes WHERE phone = ?', [$phoneA]);
$r = $call('POST', '/api/v1/auth/request-code', ['phone' => $phoneA]);
$owner->exec('UPDATE mt_auth_codes SET attempts = 0 WHERE phone = ?', [$phoneA]);
$tok = $call('POST', '/api/v1/auth/verify',
    ['phone' => $phoneA, 'code' => $r->body['dev_code']])->body['token'];
is_($call('GET', '/api/v1/me', [], $tok)->status, 200, 'works while active');
$owner->exec("UPDATE mt_principals SET status = 'disabled' WHERE id = ?", [$A['principal']]);
is_($call('GET', '/api/v1/me', [], $tok)->status, 401, 'stops the moment the principal is disabled');
$owner->exec("UPDATE mt_principals SET status = 'active' WHERE id = ?", [$A['principal']]);

t('the OTP is not returned unless explicitly enabled');
$owner->exec('DELETE FROM mt_auth_codes WHERE phone = ?', [$phoneA]);
putenv('DNB_EXPOSE_OTP');
$r = $call('POST', '/api/v1/auth/request-code', ['phone' => $phoneA]);
is_(isset($r->body['dev_code']), false, 'no dev_code in the response by default');
putenv('DNB_EXPOSE_OTP=1');

exit(t_summary());
