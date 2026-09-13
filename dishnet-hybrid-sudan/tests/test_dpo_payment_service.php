<?php
declare(strict_types=1);
/**
 * test_dpo_payment_service.php — the fourteen scenarios from the brief, plus
 * the ones that only money teaches you.
 *
 * Both ends are real: a fake DPO and a fake uCRM, talked to over HTTP by the
 * actual DpoClient and the actual CrmApiClient. That matters most for the
 * duplicate and race cases, because the assertion there is not "the code took
 * the right branch" — it is "COUNT the payments that actually reached uCRM,
 * and it is one".
 */
require_once dirname(__DIR__) . '/lib/CrmApiClient.php';
require_once dirname(__DIR__) . '/lib/DpoClient.php';
require_once dirname(__DIR__) . '/lib/DpoPaymentStore.php';
require_once dirname(__DIR__) . '/lib/DpoPaymentService.php';

$pass = 0; $fail = 0;
function t(string $n, $got, $want) { global $pass, $fail;
    if ($got === $want) { $pass++; printf("  ok   %s\n", $n); }
    else { $fail++; printf("  FAIL %s\n       got  %s\n       want %s\n", $n, var_export($got, true), var_export($want, true)); } }
function is_(bool $c, string $m, string $d = ''): void { global $pass, $fail;
    if ($c) { $pass++; echo "  ok   $m\n"; } else { $fail++; echo "  FAIL $m" . ($d ? "\n       $d" : '') . "\n"; } }

// ── Boot both fakes ─────────────────────────────────────────────────────
function boot(string $router, int $base, string $probeBody, string $sig): array {
    $probe = function (int $port) use ($probeBody): ?string {
        $ch = curl_init("http://127.0.0.1:{$port}/");
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
            CURLOPT_TIMEOUT => 2, CURLOPT_PROXY => '', CURLOPT_POSTFIELDS => $probeBody]);
        $r = curl_exec($ch); curl_close($ch);
        return $r === false ? null : (string)$r;
    };
    foreach (range(0, 9) as $slot) {
        $cand = $base + ((getmypid() + $slot * 11) % 60);
        $p = proc_open(sprintf('exec php -S 127.0.0.1:%d %s', $cand, escapeshellarg($router)),
                       [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        for ($i = 0; $i < 40; $i++) {
            $got = $probe($cand);
            if ($got !== null) {
                if (strpos($got, $sig) !== false) return [$p, $cand];
                break;
            }
            usleep(100000);
        }
        if (is_resource($p)) { proc_terminate($p); proc_close($p); }
    }
    return [null, 0];
}
array_map('unlink', glob(sys_get_temp_dir() . '/fake_dpo_state_*.json') ?: []);
array_map('unlink', glob(sys_get_temp_dir() . '/fake_ucrm_*.json') ?: []);
[$dpoSrv, $dpoPort]   = boot(dirname(__DIR__) . '/tests/fixtures/fake_dpo_server.php', 9500, '', 'TEST-SERVER-SIGNATURE');
[$crmSrv, $crmPort]   = boot(dirname(__DIR__) . '/tests/fixtures/fake_ucrm_server.php', 9600, '', 'FAKE-UCRM-TEST');
if ($dpoSrv === null || $crmSrv === null) { echo "  SKIP could not start the fake servers\n"; exit(0); }
register_shutdown_function(function () use (&$dpoSrv, &$crmSrv) {
    foreach ([$dpoSrv, $crmSrv] as $s) if (is_resource($s)) { proc_terminate($s); proc_close($s); }
});

$crmBase = "http://127.0.0.1:{$crmPort}";
$dpoBase = "http://127.0.0.1:{$dpoPort}/";
$METHOD  = 'aaaa1111-dpo-pay-method-uuid';

function crmCall(string $path): array {
    global $crmBase;
    $ch = curl_init($crmBase . $path);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_PROXY => '', CURLOPT_TIMEOUT => 5]);
    $r = curl_exec($ch); curl_close($ch);
    return json_decode((string)$r, true) ?: [];
}
function paymentCount(): int { return (int)(crmCall('/__test/payments')['count'] ?? -1); }
function resetPayments(): void { crmCall('/__test/reset'); }
function scenario(string $n): void { crmCall('/__test/scenario?name=' . $n); }

/** A fresh database per case, so nothing leaks between them. */
function svc(array $cfgOver = []): array {
    global $crmBase, $dpoBase, $METHOD;
    $db = new PDO('sqlite::memory:');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec((string)file_get_contents(dirname(__DIR__) . '/migrations/071_dpo_payments.sql'));
    $store = new DpoPaymentStore($db);
    $dpo   = new DpoClient(['company_token' => 'TESTTOKEN-0001', 'service_type' => '3854',
                            'api_create' => $dpoBase, 'api_verify' => $dpoBase, 'timeout' => 5]);
    $crm   = new CrmApiClient($crmBase, 'test-key');
    $cfg   = array_merge([
        'dpo_enabled' => true, 'dpo_environment' => 'test',
        'dpo_payment_method_uuid' => $METHOD, 'dpo_ptl' => 30,
        'dpo_currencies' => ['UGX'], 'dpo_unpayable_statuses' => [9],
        'dpo_return_url' => 'https://example.test/return',
        'dpo_back_url'   => 'https://example.test/back',
    ], $cfgOver);
    return [new DpoPaymentService($store, $dpo, $crm, $cfg), $store, $db];
}

scenario('uganda'); resetPayments();

echo "\nA customer pays an invoice, and exactly one payment reaches uCRM\n";
[$s, $st] = svc(); resetPayments();
$i = $s->initiate(7, 125, ['first' => 'Grace', 'last' => 'Nakato']);
is_($i['ok'], 'the attempt was created');
t('for the full outstanding amount', $i['amount'], 299000.0);
t('in the invoice currency',         $i['currency'], 'UGX');
is_(strpos($i['checkout_url'], 'payv2.php?ID=') !== false, 'and the customer has a checkout URL');
$r = $s->verifyAndSettle($i['reference']);
t('settled',           $r['status'], 'SUCCESS');
t('one uCRM payment',  paymentCount(), 1);
$row = $st->byReference($i['reference']);
is_((int)$row['crm_payment_id'] > 0, 'and the row records which uCRM payment it became');
$pay = crmCall('/__test/payments')['payments'][0];
t('booked against the DPO method, never Cash', $pay['methodId'], $METHOD);
is_(strpos($pay['note'], $i['reference']) !== false, 'with our reference in the note, for reconciliation');
is_(strpos($pay['note'], 'Env: test') !== false,     'and the environment stamped on it');

echo "\nA duplicate callback three times over is still one payment\n";
// The case the whole design exists for.
resetPayments();
[$s, $st] = svc();
$i = $s->initiate(7, 125);
$a = $s->verifyAndSettle($i['reference']);
$b = $s->verifyAndSettle($i['reference']);
$c = $s->verifyAndSettle($i['reference']);
t('all three report settled', [$a['status'], $b['status'], $c['status']],
  ['SUCCESS', 'SUCCESS', 'SUCCESS']);
t('but only the first changed anything', [$a['changed'], $b['changed'], $c['changed']],
  [true, false, false]);
t('and uCRM saw one payment', paymentCount(), 1);

echo "\nA customer refreshing the return page five times is still one payment\n";
resetPayments();
[$s, $st] = svc();
$i = $s->initiate(7, 125);
for ($n = 0; $n < 5; $n++) $s->verifyAndSettle($i['reference']);
t('one payment', paymentCount(), 1);

echo "\nPay Now twice reuses the attempt — it does not open a rival\n";
resetPayments();
[$s, $st] = svc();
$one = $s->initiate(7, 125);
$two = $s->initiate(7, 125);
t('the same reference comes back', $two['reference'], $one['reference']);
is_($two['reused'], 'and it says it reused the open attempt');
t('the same checkout URL',         $two['checkout_url'], $one['checkout_url']);

echo "\nThe amount is the live outstanding, never the invoice total\n";
// Invoice 126 was part-paid at an agent. Charging the total would take money
// the customer does not owe.
[$s] = svc();
$p = $s->initiate(7, 126);
t('total 299,000 less 100,000 already paid', $p['amount'], 199000.0);

echo "\nAn invoice that is already settled is refused before DPO is called\n";
[$s] = svc();
$done = $s->initiate(7, 127);
is_(!$done['ok'],          'refused');
t('and says why',          $done['code'], 'SETTLED');

echo "\nAnother customer's invoice is refused, and never reaches DPO\n";
[$s] = svc();
$theirs = $s->initiate(7, 129);
is_(!$theirs['ok'],  'refused');
t('as not on your account', $theirs['code'], 'FORBIDDEN');
$missing = $s->initiate(7, 999);
t('and an invoice that does not exist', $missing['code'], 'NOTFOUND');

echo "\nA currency we do not take is refused, never converted\n";
[$s] = svc();
$kes = $s->initiate(7, 130);
is_(!$kes['ok'],  'refused');
t('named as a currency problem', $kes['code'], 'CURRENCY');
t('and an invoice with no currency at all', $s->initiate(7, 131)['code'], 'NOCURRENCY');

echo "\nA blocked invoice status is refused\n";
[$s] = svc();
t('the configured unpayable status', $s->initiate(7, 128)['code'], 'NOTPAYABLE');

echo "\nThe kill switch stops new payments and nothing else\n";
resetPayments();
[$s, $st] = svc();
$live = $s->initiate(7, 125);                       // started while enabled
[$off] = svc(['dpo_enabled' => false]);
$blocked = $off->initiate(7, 125);
is_(!$blocked['ok'],            'a new attempt is refused');
t('plainly',                    $blocked['code'], 'DISABLED');
// …and the one already at DPO still settles, on the same service with the flag
// flipped off underneath it.
$offSame = new DpoPaymentService($st, new DpoClient(['company_token' => 'TESTTOKEN-0001',
    'service_type' => '3854', 'api_create' => $dpoBase, 'api_verify' => $dpoBase, 'timeout' => 5]),
    new CrmApiClient($crmBase, 'test-key'),
    ['dpo_enabled' => false, 'dpo_environment' => 'test',
     'dpo_payment_method_uuid' => $METHOD, 'dpo_currencies' => ['UGX']]);
t('but money already taken still settles', $offSame->verifyAndSettle($live['reference'])['status'], 'SUCCESS');
t('and it reached uCRM',                   paymentCount(), 1);

echo "\nAuthorized is not paid, and nothing is recorded for it\n";
resetPayments();
[$s, $st] = svc();
// The fake DPO answers by reference, so the reference names the case.
$db = null;
$i = $s->initiate(7, 125);
$st->update($i['reference'], ['dpo_trans_token' => null]);   // detach…
$auth = $s->initiate(7, 125);                                 // …and make a fresh one we can steer
// Steer by creating a token whose CompanyRef carries the AUTH tag.
$dpoDirect = new DpoClient(['company_token' => 'TESTTOKEN-0001', 'service_type' => '3854',
                            'api_create' => $dpoBase, 'api_verify' => $dpoBase, 'timeout' => 5]);
$tok = $dpoDirect->createToken(['reference' => 'DPO-125-7-AUTH', 'amount' => 299000.0,
                                'currency' => 'UGX', 'description' => 'x']);
$st->update($auth['reference'], ['dpo_trans_token' => $tok['trans_token']]);
$av = $s->verifyAndSettle($auth['reference']);
t('001 leaves it pending', $av['status'], 'PENDING');
t('and no payment is recorded', paymentCount(), 0);

echo "\nEach unpaid code lands in its own state\n";
foreach ([['WAIT', '900', 'PENDING'], ['DECLINE', '901', 'FAILED'],
          ['EXPIRE', '903', 'EXPIRED'], ['CANCEL', '904', 'CANCELLED']] as [$tag, $code, $want]) {
    resetPayments();
    [$s2, $st2] = svc();
    $a2 = $s2->initiate(7, 125);
    $tk = $dpoDirect->createToken(['reference' => 'DPO-125-7-' . $tag, 'amount' => 299000.0,
                                   'currency' => 'UGX', 'description' => 'x']);
    $st2->update($a2['reference'], ['dpo_trans_token' => $tk['trans_token']]);
    $res = $s2->verifyAndSettle($a2['reference']);
    t("$code → $want", $res['status'], $want);
    t("  and nothing reaches uCRM", paymentCount(), 0);
}

echo "\nUnderpayment is quarantined, never treated as settlement\n";
// Decision: no partial payments. Money moved; a person decides.
resetPayments();
[$s, $st] = svc();
$u = $s->initiate(7, 125);
$tk = $dpoDirect->createToken(['reference' => 'DPO-125-7-UNDER', 'amount' => 299000.0,
                               'currency' => 'UGX', 'description' => 'x']);
$st->update($u['reference'], ['dpo_trans_token' => $tk['trans_token']]);
$ur = $s->verifyAndSettle($u['reference']);
t('quarantined',                $ur['status'], 'QUARANTINED');
t('and nothing reaches uCRM',   paymentCount(), 0);
is_(strpos((string)$st->byReference($u['reference'])['failure_reason'], 'over- or underpaid') !== false,
    'with the reason recorded for whoever looks');

echo "\n\"DPO did not say the amount\" quarantines — it never assumes agreement\n";
resetPayments();
[$s, $st] = svc();
$n = $s->initiate(7, 125);
$tk = $dpoDirect->createToken(['reference' => 'DPO-125-7-NOAMT', 'amount' => 299000.0,
                               'currency' => 'UGX', 'description' => 'x']);
$st->update($n['reference'], ['dpo_trans_token' => $tk['trans_token']]);
$nr = $s->verifyAndSettle($n['reference']);
t('paid, but unverifiable → quarantined', $nr['status'], 'QUARANTINED');
t('and nothing reaches uCRM',             paymentCount(), 0);

echo "\nA different currency back is quarantined, never converted\n";
resetPayments();
[$s, $st] = svc();
$w = $s->initiate(7, 125);
$tk = $dpoDirect->createToken(['reference' => 'DPO-125-7-ALTCCY', 'amount' => 299000.0,
                               'currency' => 'UGX', 'description' => 'x']);
$st->update($w['reference'], ['dpo_trans_token' => $tk['trans_token']]);
t('quarantined', $s->verifyAndSettle($w['reference'])['status'], 'QUARANTINED');
t('and nothing reaches uCRM', paymentCount(), 0);

echo "\nWith no payment method configured we refuse — we do NOT book Cash\n";
// Cash feeds agent cash reconciliation. The fallback would invent money
// somebody has to hand over.
resetPayments();
[$s, $st] = svc(['dpo_payment_method_uuid' => '']);
$m = $s->initiate(7, 125);
$mr = $s->verifyAndSettle($m['reference']);
t('quarantined rather than booked',  $mr['status'], 'QUARANTINED');
t('and nothing reaches uCRM',        paymentCount(), 0);
is_(strpos((string)$st->byReference($m['reference'])['failure_reason'], 'Cash') !== false,
    'and it names the risk it refused to take');

echo "\nuCRM failing does not lose the money\n";
resetPayments();
[$s, $st] = svc();
$f = $s->initiate(7, 125);
scenario('payments_down');
$fr = $s->verifyAndSettle($f['reference']);
scenario('uganda');
t('the attempt stays open',   $fr['status'], 'PENDING');
is_(!$fr['ok'],               'and is not reported as settled');
is_((string)$st->byReference($f['reference'])['failure_reason'] !== '', 'with the failure recorded');
// …and the next pass settles it, exactly once.
$again = $s->verifyAndSettle($f['reference']);
t('the retry settles it',     $again['status'], 'SUCCESS');
t('with one payment, not two', paymentCount(), 1);

echo "\nAn unreachable DPO changes nothing at all\n";
resetPayments();
[$s, $st] = svc();
$d = $s->initiate(7, 125);
$before = $st->byReference($d['reference'])['status'];
$dead = new DpoPaymentService($st, new DpoClient(['company_token' => 'TESTTOKEN-0001',
    'service_type' => '3854', 'api_verify' => 'http://127.0.0.1:1/', 'timeout' => 5]),
    new CrmApiClient($crmBase, 'test-key'),
    ['dpo_enabled' => true, 'dpo_payment_method_uuid' => $METHOD]);
$dr = $dead->verifyAndSettle($d['reference']);
t('the status is untouched', $st->byReference($d['reference'])['status'], $before);
t('and it says why',         $dr['code'], 'UNREACHABLE');
t('and nothing reaches uCRM', paymentCount(), 0);

echo "\nA push naming a reference we never issued settles nothing\n";
[$s] = svc();
$ghost = $s->verifyAndSettle('DPO-999-999-FORGED');
is_(!$ghost['ok'],  'refused');
t('as unknown',     $ghost['status'], 'UNKNOWN');

echo "\nThe reconcile cron finishes what the browser did not\n";
resetPayments();
[$s, $st] = svc();
$abandoned = $s->initiate(7, 125);     // customer paid, then closed the browser
$out = $s->reconcile(0, 50);           // no grace, so it picks the row up now
t('it checked the open attempt', $out['checked'], 1);
t('and settled it',              $out['settled'], 1);
t('with one uCRM payment',       paymentCount(), 1);
t('a second pass has nothing to do', $s->reconcile(0, 50)['checked'], 0);

echo "\nThe cron never expires a payment on the clock alone\n";
// A payment completing at minute 29 must not be expired at minute 30.
resetPayments();
[$s, $st] = svc();
$late = $s->initiate(7, 125);
$st->update($late['reference'], ['attempt_expires_at' => gmdate('Y-m-d H:i:s', time() - 3600)]);
$o = $s->reconcile(0, 50);
t('DPO said paid, so it settles despite the clock', $o['settled'], 1);
t('it was not expired',                            $o['expired'], 0);
t('and the money is recorded',                     paymentCount(), 1);

echo "\nBut an expired attempt DPO agrees is unpaid does expire\n";
resetPayments();
[$s, $st] = svc();
$w2 = $s->initiate(7, 125);
$tk = $dpoDirect->createToken(['reference' => 'DPO-125-7-WAIT', 'amount' => 299000.0,
                               'currency' => 'UGX', 'description' => 'x']);
$st->update($w2['reference'], ['dpo_trans_token' => $tk['trans_token'],
                               'attempt_expires_at' => gmdate('Y-m-d H:i:s', time() - 3600)]);
$o2 = $s->reconcile(0, 50);
t('expired',                  $o2['expired'], 1);
t('nothing settled',          $o2['settled'], 0);
// …and the customer may start a fresh attempt: the INVOICE did not expire.
$fresh = $s->initiate(7, 125);
is_($fresh['ok'], 'and a new attempt can be started on the same invoice');
is_($fresh['reference'] !== $w2['reference'], 'as a genuinely new attempt');

echo "\nEvery step is auditable, and no credential is in it\n";
resetPayments();
[$s, $st] = svc();
$a3 = $s->initiate(7, 125);
$s->verifyAndSettle($a3['reference']);
$row = $st->byReference($a3['reference']);
$events = array_column($st->events((int)$row['id']), 'event');
foreach (['initiated', 'token_created', 'redirected', 'verify_requested',
          'verify_succeeded', 'crm_payment_created', 'marked_success'] as $e) {
    is_(in_array($e, $events, true), "the trail records '$e'");
}
$all = json_encode($st->events((int)$row['id']));
is_(strpos($all, 'TESTTOKEN-0001') === false, 'and the company token appears nowhere in it');

echo "\nSettled payments are financial records the code cannot walk back\n";
try {
    $st->update($a3['reference'], ['status' => 'FAILED']);
    $after = $st->byReference($a3['reference'])['status'];
    is_($after === 'SUCCESS', 'un-settling is refused at the database');
} catch (\PDOException $e) {
    is_(strpos($e->getMessage(), 'un-settled') !== false, 'un-settling is refused at the database');
}

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
