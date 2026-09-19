<?php
declare(strict_types=1);
/**
 * test_dpo_client.php — the two DPO calls, against a server we control.
 *
 * DPO has no sandbox host: test and live share one URL and differ only by
 * company token. So a real test merchant account cannot produce code 903 on
 * demand, cannot drop the amount out of a verify response, and cannot stall
 * mid-call. A fake server can, which is why these run even once a test token
 * exists.
 *
 * The behaviour these pin down hardest is the one that loses money quietly:
 * a verify response that does NOT tell us the amount must read as
 * "unconfirmed", never as "it matched".
 */
require_once dirname(__DIR__) . '/lib/DpoClient.php';

$pass = 0; $fail = 0;
function t(string $n, $got, $want) { global $pass, $fail;
    if ($got === $want) { $pass++; printf("  ok   %s\n", $n); }
    else { $fail++; printf("  FAIL %s\n       got  %s\n       want %s\n", $n, var_export($got, true), var_export($want, true)); } }
function is_(bool $c, string $m, string $d = ''): void { global $pass, $fail;
    if ($c) { $pass++; echo "  ok   $m\n"; } else { $fail++; echo "  FAIL $m" . ($d ? "\n       $d" : '') . "\n"; } }

// ── Boot the fake DPO server ────────────────────────────────────────────
array_map('unlink', glob(sys_get_temp_dir() . '/fake_dpo_state_*.json') ?: []);
$router = dirname(__DIR__) . '/tests/fixtures/fake_dpo_server.php';
$probe = function (int $port): ?string {
    $ch = curl_init("http://127.0.0.1:{$port}/");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
        CURLOPT_TIMEOUT => 2, CURLOPT_PROXY => '', CURLOPT_POSTFIELDS => '']);
    $r = curl_exec($ch); curl_close($ch);
    return $r === false ? null : (string)$r;
};
$srv = null; $port = 0;
foreach (range(0, 9) as $slot) {
    $cand = 9400 + ((getmypid() + $slot * 13) % 80);
    $p = proc_open(sprintf('exec php -S 127.0.0.1:%d %s', $cand, escapeshellarg($router)),
                   [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
    $ours = false;
    for ($i = 0; $i < 40; $i++) {
        $got = $probe($cand);
        if ($got !== null) { $ours = strpos($got, 'TEST-SERVER-SIGNATURE') !== false; break; }
        usleep(100000);
    }
    if ($ours) { $srv = $p; $port = $cand; break; }
    if (is_resource($p)) { proc_terminate($p); proc_close($p); }
}
if ($srv === null) { echo "  SKIP could not start the fake DPO server\n"; exit(0); }
register_shutdown_function(function () use (&$srv) {
    if (is_resource($srv)) { proc_terminate($srv); proc_close($srv); }
});

$base = "http://127.0.0.1:{$port}/";
function client(array $over = []): DpoClient {
    global $base;
    return new DpoClient(array_merge([
        'company_token'   => 'TESTTOKEN-0001',
        'service_type'    => '3854',
        'company_acc_ref' => 'DISHNET-UG',
        'environment'     => 'test',
        'api_create'      => $base,
        'api_verify'      => $base,
        'pay_url'         => 'https://secure.3gdirectpay.com/payv2.php',
        'timeout'         => 5,
    ], $over));
}
function tx(string $ref, float $amt = 299000.0, string $cur = 'UGX'): array {
    return ['reference' => $ref, 'amount' => $amt, 'currency' => $cur,
            'description' => 'DishNet Home - Monthly Internet Service',
            'customer_first' => 'Grace', 'customer_last' => 'Nakato',
            'customer_email' => 'grace@example.com', 'customer_phone' => '+256 772 000 000',
            'redirect_url' => 'https://example.test/return', 'back_url' => 'https://example.test/back'];
}

echo "\nA token is created, and the customer has somewhere to go\n";
$c = client();
$r = $c->createToken(tx('DPO-125-7-AAA'));
t('DPO accepted it',        $r['code'], '000');
is_($r['ok'],               'so the call reports success');
is_($r['trans_token'] !== '', 'with a transaction token');
is_($r['trans_ref']   !== '', 'and a transaction reference to show finance');
t('the checkout URL is DPO\'s hosted page, carrying the token',
  $c->checkoutUrl($r['trans_token']),
  'https://secure.3gdirectpay.com/payv2.php?ID=' . $r['trans_token']);

echo "\nThe same reference never buys a second token\n";
// DPO enforces CompanyRef uniqueness (code 940). A client that quietly got a
// fresh token for a reference already in flight would be a double charge.
$again = $c->createToken(tx('DPO-125-7-AAA'));
t('the same reference returns the same token', $again['trans_token'], $r['trans_token']);

echo "\nEvery create failure is reported as itself, never as success\n";
foreach ([['NOCCY','904','Currency not supported'],
          ['LIMIT','905','exceeded'],
          ['PAIDREF','940','already exists and paid']] as [$tag, $code, $frag]) {
    $f = $c->createToken(tx('DPO-125-7-' . $tag));
    t("$tag → $code", $f['code'], $code);
    is_(!$f['ok'], "  and it is not treated as success");
    is_(stripos($f['message'], $frag) !== false, "  with DPO's own words: \"{$f['message']}\"");
}

echo "\n904 must never become a currency conversion\n";
// The refusal has to reach the caller intact. Anything that "helpfully"
// retried in another currency would bill a customer in money they never agreed.
$cu = $c->createToken(tx('DPO-125-7-NOCCY', 299000.0, 'UGX'));
t('the refusal is surfaced',      $cu['code'], '904');
t('and no token was invented',    $cu['trans_token'], '');

echo "\nA success with no token is not a success\n";
$nt = $c->createToken(tx('DPO-125-7-NOTOKEN'));
is_(!$nt['ok'], 'Result 000 with no TransToken is refused');
t('and it says why',  $nt['code'], 'NOTOKEN');

echo "\nRubbish back from the gateway does not crash anything\n";
$bx = $c->createToken(tx('DPO-125-7-BADXML'));
is_(!$bx['ok'],                 'an HTML error page is a failure');
t('named as unparseable',       $bx['code'], 'BADXML');
is_(strlen($bx['raw']) > 0,     'and the raw response is kept for diagnosis');

echo "\nA stalled gateway is our problem, not a failed payment\n";
$slow = client(['timeout' => 5])->createToken(tx('DPO-125-7-CSTALL'));
is_(!$slow['ok'], 'the create times out rather than hanging');

echo "\nCredentials are checked by DPO, and never echoed by us\n";
t('no company token at all → 801', client(['company_token' => ''])->createToken(tx('X'))['code'], 'CONFIG');
t('an unknown company token → 802', client(['company_token' => 'WRONG-1'])->createToken(tx('DPO-1'))['code'], '802');
$leak = $c->createToken(tx('DPO-125-7-BBB'));
is_(strpos($leak['raw'], 'TESTTOKEN-0001') === false,
    'the company token never appears in what we keep');

echo "\nVerification: paid means paid, and says so with figures\n";
$made = $c->createToken(tx('DPO-125-7-CARD'));
$v = $c->verifyToken($made['trans_token']);
t('Result 000',              $v['code'], '000');
is_($v['paid'],              'and paid is true');
t('the reference comes back',$v['reference'], 'DPO-125-7-CARD');
t('with the amount',         $v['amount'], 299000.0);
t('and the currency',        $v['currency'], 'UGX');
is_($v['method'] !== '',     'and how they paid: ' . $v['method']);

echo "\nAuthorized is NOT paid\n";
// The single most dangerous code in the table. Settling on 001 would mark
// invoices paid for money that has not moved.
$a = $c->createToken(tx('DPO-125-7-AUTH'));
$av = $c->verifyToken($a['trans_token']);
t('Result 001',      $av['code'], '001');
is_(!$av['paid'],    'and paid is false');
is_($av['ok'],       'but the answer was understood — this is a wait, not an error');

echo "\nEvery other verify code is carried through unchanged\n";
foreach ([['WAIT','900',false], ['DECLINE','901',false], ['EXPIRE','903',false],
          ['CANCEL','904',false], ['UNDER','002',false], ['OVER','002',false]] as [$tag, $code, $paid]) {
    $m = $c->createToken(tx('DPO-125-7-' . $tag));
    $vv = $c->verifyToken($m['trans_token']);
    t("$tag → $code", $vv['code'], $code);
    is_($vv['paid'] === $paid, "  paid = " . var_export($paid, true));
}

echo "\nOver and underpayment carry the real figure, so a person can judge\n";
$u  = $c->createToken(tx('DPO-125-7-UNDER'));
$uv = $c->verifyToken($u['trans_token']);
t('underpaid by 1,000', $uv['amount'], 298000.0);
$o  = $c->createToken(tx('DPO-125-7-OVER'));
$ov = $c->verifyToken($o['trans_token']);
t('overpaid by 1,000',  $ov['amount'], 300000.0);

echo "\n\"DPO did not say\" must never read as \"it matched\"\n";
// A paid response that omits the amount is the quiet failure mode: a client
// that defaulted to 0.0 or to our own figure would settle unchecked.
$n  = $c->createToken(tx('DPO-125-7-NOAMT'));
$nv = $c->verifyToken($n['trans_token']);
is_($nv['paid'],          'DPO says paid');
t('but the amount is null, not zero',   $nv['amount'], null);
t('and the currency is null, not ours', $nv['currency'], null);

echo "\nA different currency back is visible, not silently accepted\n";
$w  = $c->createToken(tx('DPO-125-7-ALTCCY'));
$wv = $c->verifyToken($w['trans_token']);
is_($wv['paid'],         'DPO says paid');
t('in a currency we did not ask for', $wv['currency'], 'KES');

echo "\nAn unreachable gateway changes nothing\n";
// This is the difference between "the customer's payment failed" and "we
// could not ask". Confusing them either strands money or refunds thin air.
$dead = new DpoClient(['company_token' => 'TESTTOKEN-0001', 'service_type' => '3854',
                       'api_verify' => 'http://127.0.0.1:1/', 'timeout' => 5]);
$dv = $dead->verifyToken('TEST-TOK-000001');
is_(!$dv['ok'],         'the verify did not succeed');
is_(!$dv['reachable'],  'and it is marked unreachable, not declined');
is_(!$dv['paid'],       'and certainly not paid');
t('named plainly',      $dv['code'], 'UNREACHABLE');

$sv = client(['timeout' => 5]);
$sm = $sv->createToken(tx('DPO-125-7-VSTALL'));
$svr = $sv->verifyToken($sm['trans_token']);
is_(!$svr['reachable'], 'a verify that stalls is also unreachable, not a decline');

echo "\nAn unknown token is a data mismatch, not a payment\n";
$un = $c->verifyToken('TEST-TOK-NOSUCH');
t('Result 902',   $un['code'], '902');
is_(!$un['paid'], 'and nothing is paid');
$empty = $c->verifyToken('');
is_(!$empty['ok'] && !$empty['paid'], 'an empty token never even leaves the building');

echo "\nThe wire format is DPO's, not ours\n";
$ref = new ReflectionClass('DpoClient');
t('createToken goes to v6', $ref->getConstant('API_CREATE'), 'https://secure.3gdirectpay.com/API/v6/');
t('verifyToken goes to v7', $ref->getConstant('API_VERIFY'), 'https://secure.3gdirectpay.com/API/v7/');
t('checkout is the hosted page', $ref->getConstant('PAY_URL'), 'https://secure.3gdirectpay.com/payv2.php');
// There is no sandbox host. A class that invented one would work in every
// test and fail only against production.
$src = (string)file_get_contents(dirname(__DIR__) . '/lib/DpoClient.php');
is_(preg_match('#https://(?!secure\.3gdirectpay\.com)[a-z0-9.\-]+3gdirectpay#i', $src) === 0
    && stripos($src, 'secure1.') === false && stripos($src, 'sandbox.') === false,
    'and no second DPO host is invented — there is no sandbox to point at');
t('every documented create code is known', count($ref->getConstant('CREATE_CODES')), 15);
t('every documented verify code is known', count($ref->getConstant('VERIFY_CODES')), 13);

echo "\nXML is escaped, so a customer's name cannot break the request\n";
$amp = $c->createToken(array_merge(tx('DPO-125-7-CCC'), ['customer_last' => 'Okello & Sons <Ltd>']));
t('an ampersand in a name still creates a token', $amp['code'], '000');

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
