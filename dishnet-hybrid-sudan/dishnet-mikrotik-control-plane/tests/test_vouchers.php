<?php
declare(strict_types=1);
/**
 * Vouchers, batches and the projection into AAA.
 *
 * docs/55 step 5 exit condition:
 *   CONCURRENT REDEMPTION OF ONE CODE MUST FAIL FOR THE SECOND.
 * That is the RACE section, and it is run with real parallel processes rather
 * than two sequential calls — a sequential test would pass against code that
 * has the bug.
 */
require __DIR__ . '/bootstrap.php';
use Dn\Api\Routes;
use Dn\Auth\Authenticator;
use Dn\Db\Database;
use Dn\Http\Kernel;
use Dn\Http\Request;
use Dn\Http\Serializer\Projection;
use Dn\Tenancy\TenantContext;
use Dn\Vouchers\CodeGenerator;
use Dn\Vouchers\VoucherService;

$owner = Database::inspector();
$ids = seed_two_customers($owner);
$A = $ids['A']; $B = $ids['B'];
$db   = Database::app();
$auth = new Authenticator($db);
$ctx  = new TenantContext($db);
$k    = new Kernel(Routes::build($auth), $db, $auth, $ctx);
$call = fn(string $m, string $p, array $body = [], string $tok = '', array $h = [])
    => $k->handle(new Request($m, $p,
        ($tok ? ['Authorization' => "Bearer {$tok}"] : []) + $h, $body));

putenv('DNB_EXPOSE_OTP=1');
$signIn = function (string $phone) use ($call, $owner): string {
    $owner->exec('DELETE FROM mt_auth_codes WHERE phone = ?', [$phone]);
    $c = $call('POST', '/api/v1/auth/request-code', ['phone' => $phone])->body['dev_code'];
    return $call('POST', '/api/v1/auth/verify', ['phone' => $phone, 'code' => $c])->body['token'];
};
$tokA = $signIn('+256700001001');
$tokB = $signIn('+256700001002');

$mkPlan = function (string $tok, string $name) use ($call): array {
    return $call('POST', '/api/v1/me/plans', [
        'name' => $name, 'duration_s' => 86400,
        'rate_down_bps' => 10_000_000, 'rate_up_bps' => 3_000_000,
        'devices_per_voucher' => 2, 'mode' => 'elapsed',
        'price_minor' => 800000, 'currency' => 'UGX',
    ], $tok)->body['plan'];
};
$planA = $mkPlan($tokA, 'Day Pass');
$planB = $mkPlan($tokB, 'Day Pass');

// ===========================================================================
t('issuing a batch');
$r = $call('POST', '/api/v1/me/vouchers', ['plan_id' => $planA['id'], 'count' => 25], $tokA);
is_($r->status, 202, 'accepted, not 200 — publishing is queued');
is_(count($r->body['vouchers']), 25, '25 codes exist immediately');
is_(isset($r->body['intent_id']), true, 'and an intent was raised');
is_($r->body['batch']['issued_count'], 25, 'the batch records what it issued');
$codes = array_column($r->body['vouchers'], 'code');

t('codes are readable, unpredictable and unique');
is_(count(array_unique($codes)), 25, 'all 25 are distinct');
$bad = array_filter($codes, fn($c) => preg_match('/[01OI]/', $c));
is_(array_values($bad), [], 'no 0, O, 1 or I — the characters people mis-hear and mistype');
$shape = array_filter($codes, fn($c) => !preg_match('/^[A-Z2-9]{5}-[A-Z2-9]{5}$/', $c));
is_(array_values($shape), [], 'every code has the same readable shape');

// A weak generator shows up as repeated leading characters across a sample.
$firsts = array_count_values(array_map(fn($c) => $c[0], $codes));
is_(max($firsts) < 10, true, 'no single starting character dominates (' . max($firsts) . '/25)');
is_(CodeGenerator::keyspace() > 1e15, true, 'keyspace is ~1.1e15');

t('a code collision is retried, not fatal');
// 32^10 makes this vanishingly rare, which is exactly why the recovery path
// needs a test rather than an assumption: it will otherwise never run.
$fixed = new class implements \Dn\Vouchers\CodeSource {
    public int $calls = 0;
    public function generate(): string {
        $this->calls++;
        return $this->calls <= 2 ? 'AAAAA-AAAAA' : 'BBBBB-' . str_pad((string) $this->calls, 5, 'Z');
    }
};
$out = $ctx->run($A['customer'], fn($d) => (new VoucherService($d, $fixed))
    ->issueBatch($A['customer'], $planA['id'], 2, null, $A['principal']));
$got = array_column($out['vouchers'], 'code');
is_(count(array_unique($got)), 2, 'two distinct codes despite the generator repeating itself');
is_(in_array('AAAAA-AAAAA', $got, true), true, 'the first use of the repeated code succeeded');
is_($fixed->calls > 2, true, 'and the collision caused another draw rather than an error');

// ===========================================================================
t('RACE — twelve processes redeem one code at the same instant');
$target = $codes[0];
$start  = microtime(true) + 1.5;
$procs  = [];
for ($i = 0; $i < 12; $i++) {
    $cmd = sprintf('DNB_DSN=%s php %s %s %s',
        escapeshellarg(getenv('DNB_DSN') ?: ''),
        escapeshellarg(__DIR__ . '/redeem_race_child.php'),
        escapeshellarg($target), escapeshellarg((string) $start));
    $procs[] = popen($cmd . ' 2>&1', 'r');
}
$results = [];
foreach ($procs as $p) { $results[] = trim(stream_get_contents($p)); pclose($p); }

$won  = array_values(array_filter($results, fn($r) => str_starts_with($r, 'WON')));
$lost = array_values(array_filter($results, fn($r) => $r === 'LOST'));
$err  = array_values(array_filter($results, fn($r) => str_starts_with($r, 'ERR')));

is_(count($results), 12, 'all twelve ran');
is_($err, [], 'none errored');
is_(count($won), 1, 'EXACTLY ONE redeemed the code');
is_(count($lost), 11, 'the other eleven were refused');

$row = $owner->one('SELECT state, activated_at FROM mt_vouchers WHERE code = ?', [$target]);
is_($row['state'], 'active', 'the voucher is active');
is_($row['activated_at'] !== null, true, 'with one activation time');

t('RACE — a lost redemption is indistinguishable from a wrong code');
$again = $ctx->runUnscoped(fn($d) => (new VoucherService($d))->redeem($target));
$never = $ctx->runUnscoped(fn($d) => (new VoucherService($d))->redeem('ZZZZZ-ZZZZZ'));
is_($again, null, 'redeeming an already-used code returns nothing');
is_($again, $never, 'and is identical to a code that never existed');

t('a revoked voucher cannot be redeemed');
$v = $ctx->run($A['customer'], fn($d) => (new VoucherService($d))->list('unused')[0]);
$ctx->run($A['customer'], fn($d) => (new VoucherService($d))->revoke($v['id']));
is_($ctx->runUnscoped(fn($d) => (new VoucherService($d))->redeem($v['code'])), null,
    'a revoked code is refused');

// ===========================================================================
t('AAA — usernames are namespaced so a code cannot cross customers');
$cA = $owner->one('SELECT * FROM mt_customers WHERE id = ?', [$A['customer']]);
$cB = $owner->one('SELECT * FROM mt_customers WHERE id = ?', [$B['customer']]);
is_($cA['radius_ref'] !== $cB['radius_ref'], true, 'each customer has a distinct ref');
$svc = new VoucherService($db);
$uA = $svc->radiusUsername($cA, 'ABCDE-FGHIJ');
$uB = $svc->radiusUsername($cB, 'ABCDE-FGHIJ');
is_($uA !== $uB, true, 'the SAME code yields different AAA usernames for two customers');
is_(str_starts_with($uA, $cA['radius_ref']), true, "and each is prefixed by its owner's ref");

t('the ref is unique by constraint, not by probability');
throws_(fn() => $owner->exec('UPDATE mt_customers SET radius_ref = ? WHERE id = ?',
        [$cA['radius_ref'], $B['customer']]),
    'unique', 'two customers cannot share a radius_ref');

// ===========================================================================
t('MONEY — a voucher records what it sold for, not a pointer to a price');
$before = $ctx->run($A['customer'], fn($d) => (new VoucherService($d))->list())[0];
is_((int) $before['price_minor'], 800000, 'issued at the plan price');
$call('PATCH', '/api/v1/me/plans/' . $planA['id'], ['price_minor' => 5000000], $tokA);
$after = $owner->one('SELECT price_minor FROM mt_vouchers WHERE id = ?', [$before['id']]);
is_((int) $after['price_minor'], 800000,
    'raising the plan price does NOT rewrite what an already-issued voucher sold for');

t('no floating point anywhere near money');
$floats = $owner->query(
    "SELECT table_name, column_name FROM information_schema.columns
      WHERE table_schema='public' AND data_type IN ('double precision','real','numeric')");
is_(count($floats), 0, 'no float or numeric column exists');

// ===========================================================================
t('ISOLATION — vouchers do not cross customers');
$call('POST', '/api/v1/me/vouchers', ['plan_id' => $planB['id'], 'count' => 3], $tokB);
$listA = $call('GET', '/api/v1/me/vouchers', [], $tokA)->body['vouchers'];
$listB = $call('GET', '/api/v1/me/vouchers', [], $tokB)->body['vouchers'];
is_(count($listB), 3, 'B sees its three');
$codesB = array_column($listB, 'code');
$overlap = array_intersect(array_column($listA, 'code'), $codesB);
is_(array_values($overlap), [], 'no code appears in both lists');

$bVoucher = $owner->one('SELECT id FROM mt_vouchers WHERE customer_id = ? LIMIT 1', [$B['customer']]);
is_($call('POST', '/api/v1/me/vouchers/' . $bVoucher['id'] . '/revoke', [], $tokA)->status, 404,
    "A cannot revoke B's voucher");
is_($owner->one('SELECT state FROM mt_vouchers WHERE id = ?', [$bVoucher['id']])['state'], 'unused',
    "and B's voucher is untouched");

t('a customer cannot issue against another customer\'s plan');
is_($call('POST', '/api/v1/me/vouchers', ['plan_id' => $planB['id'], 'count' => 1], $tokA)->status,
    404, "A referencing B's plan id is not found");

t('PROJECTION — no internal field reaches the customer');
$one = $listA[0];
$extra = array_diff(array_keys($one), Projection::fieldsFor('voucher'));
is_(array_values($extra), [], 'no field outside the voucher allowlist');
$json = json_encode($listA);
foreach (['batch_id', 'plan_id', 'sold_by', 'radius', 'profile'] as $f) {
    is_(str_contains($json, $f), false, "voucher list withholds {$f}");
}

t('EXPIRE, never delete');
throws_(fn() => $ctx->run($A['customer'], fn($d) => $d->exec(
    'DELETE FROM mt_vouchers WHERE id = ?', [$before['id']])),
    'not deleted', 'the database refuses to delete a voucher');

t('a retired plan cannot be issued against');
$call('POST', '/api/v1/me/plans/' . $planA['id'] . '/retire', [], $tokA);
is_($call('POST', '/api/v1/me/vouchers', ['plan_id' => $planA['id'], 'count' => 1], $tokA)->status,
    404, 'issuing against a retired plan is refused');

exit(t_summary());
