<?php
declare(strict_types=1);
/**
 * test_brain_customer_tools.php — the model asks for "my" data and never says whose.
 *
 * Five tools, all zero-argument. That is not a convenience: with no declared
 * arguments, positional() hands the methods nothing at all, so there is no
 * value a model could supply that reaches a query. The scrub() denylist still
 * runs, but it records attempts rather than being the thing that stops them.
 *
 * And every backend read is scoped BY the identity rather than checked after
 * it. get_my_invoice(number) is excluded from the brain for exactly that
 * reason: it asks the gateway for any invoice with a number and refuses it
 * afterwards, which means another customer's record is read before it is
 * declined. These five never touch one.
 */
$pass = 0; $fail = 0;
function is_(bool $c, string $m): void { global $pass, $fail;
    if ($c) { $pass++; echo "  ok   $m\n"; } else { $fail++; echo "  FAIL $m\n"; } }
function t(string $n, $got, $want) { global $pass, $fail;
    if ($got === $want) { $pass++; printf("  ok   %s\n", $n); }
    else { $fail++; printf("  FAIL %s\n       got  %s\n       want %s\n", $n, var_export($got, true), var_export($want, true)); } }

$root = dirname(__DIR__);
require_once $root . '/lib/CustomerIdentity.php';
require_once $root . '/lib/ConversationService.php';
require_once $root . '/lib/CustomerDataTools.php';
require_once $root . '/lib/ReplyPrivacyGuard.php';

function codeNC(string $f): string {
    $o = '';
    foreach (token_get_all((string)file_get_contents($f)) as $k) {
        if (is_array($k)) { if (in_array($k[0], [T_COMMENT, T_DOC_COMMENT], true)) continue; $o .= $k[1]; }
        else $o .= $k;
    }
    return $o;
}

/**
 * A gateway that RECORDS every read, so "B's record was never touched" is
 * provable from the log rather than inferred from the answer.
 */
class LoggingGw implements CustomerDataGateway
{
    public array $reads = [];
    public bool  $fail  = false;
    /** @var array<int,array<int,array<string,mixed>>> */
    public array $svc = [];

    public function client(int $i): ?array {
        $this->reads[] = "client:$i";
        if ($this->fail) return null;
        return $i === 7  ? ['status' => 'Active', 'balance' => 249000.0, 'currency' => 'UGX']
             : ($i === 21 ? ['status' => 'Active', 'balance' => 8675309.0, 'currency' => 'UGX']
             : ($i === 9  ? ['status' => 'Active', 'balance' => -5000.0, 'currency' => 'UGX'] : null));
    }
    public function services(int $i): array {
        $this->reads[] = "services:$i";
        if ($this->fail) return [];
        return $this->svc[$i] ?? [];
    }
    public function invoices(int $i): array {
        $this->reads[] = "invoices:$i";
        if ($this->fail) return [];
        return $i === 7 ? [
            ['number' => 'INV-7-002', 'client_id' => 7, 'date' => '2026-09-01',
             'due_date' => '2026-10-01', 'total' => 249000.0, 'due' => 249000.0],
            ['number' => 'INV-7-001', 'client_id' => 7, 'date' => '2026-08-01',
             'due_date' => '2026-09-01', 'total' => 249000.0, 'due' => 0.0],
        ] : [];
    }
    public function invoiceByNumber(string $n): ?array { $this->reads[] = "inv:$n"; return null; }
    public function payments(int $i): array {
        $this->reads[] = "payments:$i";
        if ($this->fail) return [];
        return $i === 7 ? [['amount' => 249000.0, 'date' => '2026-09-01', 'method' => 'MTN MoMo']] : [];
    }
    public function kits(int $i): array { $this->reads[] = "kits:$i"; return []; }
    public function kitBySerial(string $s): ?array { $this->reads[] = "kit:$s"; return null; }
    public function tickets(int $i): array { $this->reads[] = "tickets:$i"; return []; }
}

/** One Starlink service, as the gateway shapes it. */
function svcRow(int $statusCode, string $name = 'Residential Lite',
                float $price = 249000.0, string $to = '2026-10-11'): array {
    return ['plan' => $name, 'price' => $price, 'currency' => 'UGX',
            'status' => $statusCode === 1 ? 'active' : 'not active',
            'status_code' => $statusCode, 'active_to' => $to];
}

$IDENT_A = ['status' => CustomerIdentity::IDENTIFIED, 'client_id' => 7];
$IDENT_B = ['status' => CustomerIdentity::IDENTIFIED, 'client_id' => 21];

function toolsFor(array $identity, LoggingGw $gw): CustomerDataTools {
    return new CustomerDataTools($identity, $gw);
}

echo "\nThe brain's catalogue is five tools, and these five\n";
t('exactly five', CustomerDataTools::BRAIN_TOOLS,
  ['get_my_balance', 'get_my_plan', 'get_my_service_status',
   'get_my_latest_invoice', 'get_my_last_payment']);
t('and brainCatalogue agrees', array_keys(CustomerDataTools::brainCatalogue()),
  ['get_my_plan', 'get_my_service_status', 'get_my_balance',
   'get_my_last_payment', 'get_my_latest_invoice']);
foreach (['get_my_invoice', 'get_my_invoices', 'get_my_kits', 'get_my_kit',
          'get_my_account_status', 'get_my_support_cases'] as $out) {
    is_(!in_array($out, CustomerDataTools::BRAIN_TOOLS, true),
        "\"{$out}\" is NOT in the brain catalogue");
}
// The reason get_my_invoice is out, stated as a test rather than a comment.
$tc = codeNC($root . '/lib/CustomerDataTools.php');
$gi = substr($tc, strpos($tc, 'function get_my_invoice('));
is_(strpos(substr($gi, 0, 400), 'invoiceByNumber') !== false,
    'get_my_invoice does read by number — which is why the brain may not call it');

echo "\nEvery brain tool takes no arguments at all\n";
foreach (CustomerDataTools::BRAIN_TOOLS as $tool) {
    t("{$tool} declares no args", CustomerDataTools::catalogue()[$tool]['args'], []);
    $m = new ReflectionMethod('CustomerDataTools', $tool);
    t("{$tool}() has no parameters", $m->getNumberOfParameters(), 0);
}

echo "\nA model-supplied selector reaches nothing\n";
$gw = new LoggingGw(); $gw->svc = [7 => [svcRow(1)], 21 => [svcRow(1, 'Someone Else')]];
$T  = toolsFor($IDENT_A, $gw);
foreach ([['customer_id' => 21], ['client_id' => 21], ['clientId' => 21],
          ['customerId' => 21], ['account_id' => 21], ['user_id' => 21],
          ['owner_id' => 21], ['phone' => '+256700000021'],
          ['customer_phone' => '+256700000021'], ['invoice_id' => 5150],
          ['plan_id' => 12], ['service_id' => 331],
          ['filter' => ['client_id' => 21]],
          ['options' => ['scope' => ['customer' => ['id' => 21]]]]] as $args) {
    foreach (CustomerDataTools::BRAIN_TOOLS as $tool) {
        $gw->reads = [];
        $T->call($tool, $args);
        $bad = array_values(array_filter($gw->reads, static fn(string $r) => substr($r, -3) === ':21'));
        if ($bad !== []) {
            is_(false, "{$tool} with " . json_encode($args) . " reached customer 21");
        }
    }
}
is_(true, 'no selector, in any shape, reached another customer on any tool');
// And the attempt is recorded rather than silently ignored.
$T->call('get_my_balance', ['customer_id' => 21]);
$trail = $T->auditTrail();
$last  = $trail === [] ? [] : $trail[count($trail) - 1];
is_(!empty($last['stripped_identity_args']), 'the attempt is in the audit trail');
t('audited against the AUTHENTICATED id, not the supplied one', $last['customer_id'] ?? 0, 7);

echo "\nCustomer A's call never reads customer B's record\n";
$gw->reads = [];
foreach (CustomerDataTools::BRAIN_TOOLS as $tool) $T->call($tool);
$foreign = array_values(array_filter($gw->reads,
    static fn(string $r) => preg_match('/:(?!7$)\d+$/', $r) === 1));
t('every read was scoped to customer 7', $foreign, []);
is_(!in_array('inv:INV-7-002', $gw->reads, true),
    'and no invoice was fetched by number at all');

echo "\nOnly identified may construct authenticated tools\n";
foreach ([ConversationService::STATE_IDENTIFIED => true,
          ConversationService::STATE_ANONYMOUS  => false,
          ConversationService::STATE_UNKNOWN    => false,
          ConversationService::STATE_AMBIGUOUS  => false] as $state => $expect) {
    $t2 = CustomerDataTools::forIdentityState($state, $IDENT_A, new LoggingGw());
    t("{$state} → authenticated = " . var_export($expect, true), $t2->isAuthenticated(), $expect);
    if (!$expect) {
        $r = $t2->call('get_my_balance');
        is_(empty($r['ok']), "{$state} cannot call get_my_balance");
        is_(strpos((string)($r['error'] ?? ''), 'not been identified') !== false,
            "{$state} is told why, without a figure");
    }
}
// An anonymous session is a real identity for HISTORY and not for ACCOUNTS.
$anon = CustomerDataTools::forIdentityState(ConversationService::STATE_ANONYMOUS,
    ['status' => CustomerIdentity::IDENTIFIED, 'client_id' => 7], new LoggingGw());
is_(!$anon->isAuthenticated(),
    'an anonymous session cannot borrow an identified identity passed alongside it');

echo "\nBalance: the state is derived, not left to the model\n";
foreach ([[249000.0, 'owed'], [-5000.0, 'credit']] as [$bal, $want]) {
    $g = new LoggingGw();
    $id = ['status' => CustomerIdentity::IDENTIFIED, 'client_id' => $bal > 0 ? 7 : 9];
    $d  = toolsFor($id, $g)->call('get_my_balance')['data'];
    t("balance {$bal} → {$want}", $d['state'], $want);
    t("  and the amount is carried", $d['amount'], $bal);
    t("  with its currency", $d['currency'], 'UGX');
}
// The boundaries, on the legacy thresholds exactly.
final class BalGw extends LoggingGw {
    public float $b = 0.0;
    public function client(int $i): ?array { $this->reads[] = "client:$i";
        return ['status' => 'Active', 'balance' => $this->b, 'currency' => 'UGX']; }
}
foreach ([[0.02, 'owed'], [0.011, 'owed'], [0.01, 'clear'], [0.0, 'clear'],
          [-0.01, 'clear'], [-0.011, 'credit'], [-0.02, 'credit']] as [$b, $want]) {
    $g = new BalGw(); $g->b = $b;
    t("boundary {$b} → {$want}", toolsFor($IDENT_A, $g)->call('get_my_balance')['data']['state'], $want);
}
t('balance returns exactly three fields',
  array_keys(toolsFor($IDENT_A, $gw)->call('get_my_balance')['data']),
  ['amount', 'currency', 'state']);

echo "\nService status uses the confirmed uCRM mapping\n";
foreach ([0 => 'prepared', 1 => 'active', 2 => 'ended', 3 => 'suspended',
          4 => 'blocked', 5 => 'obsolete', 8 => 'quoted'] as $code => $word) {
    $g = new LoggingGw(); $g->svc = [7 => [svcRow($code)]];
    t("status {$code} → {$word}",
      toolsFor($IDENT_A, $g)->call('get_my_service_status')['data']['services'][0]['status'], $word);
}
$g = new LoggingGw(); $g->svc = [7 => [svcRow(99)]];
t('an unmapped code is "unknown", never guessed',
  toolsFor($IDENT_A, $g)->call('get_my_service_status')['data']['services'][0]['status'], 'unknown');
// 3 is suspended. Finance counting 1 and 3 alike is a DIFFERENT question —
// whether a kit is still tracked — and must not leak into a customer answer.
$g = new LoggingGw(); $g->svc = [7 => [svcRow(3)]];
is_(toolsFor($IDENT_A, $g)->call('get_my_service_status')['data']['services'][0]['status'] !== 'active',
    'status 3 is NOT reported as active');

echo "\nOne service or several — neither is discarded\n";
$g1 = new LoggingGw(); $g1->svc = [7 => [svcRow(1)]];
$d1 = toolsFor($IDENT_A, $g1)->call('get_my_plan')['data'];
t('one service: a list of one', count($d1['services']), 1);
t('  with exactly three fields', array_keys($d1['services'][0]), ['name', 'price', 'currency']);

$g2 = new LoggingGw();
$g2->svc = [7 => [svcRow(1, 'Residential Lite', 249000.0, '2026-10-11'),
                  svcRow(3, 'Business 500GB', 890000.0, '2026-11-30')]];
$T2 = toolsFor($IDENT_A, $g2);
$d2 = $T2->call('get_my_plan')['data'];
t('two services: both are returned', count($d2['services']), 2);
t('  the second is not lost', $d2['services'][1]['name'], 'Business 500GB');
$s2 = $T2->call('get_my_service_status')['data'];
t('and both statuses come through', array_column($s2['services'], 'status'),
  ['active', 'suspended']);
t('  each with exactly two fields', array_keys($s2['services'][0]), ['status', 'active_to']);

echo "\nLatest invoice: the newest, projected to three fields\n";
$d = toolsFor($IDENT_A, $gw)->call('get_my_latest_invoice')['data'];
t('exactly three fields', array_keys($d), ['number', 'amount_due', 'due_date']);
t('the newest one', $d['number'], 'INV-7-002');
t('the amount still owed', $d['amount_due'], 249000.0);
t('and when it is due', $d['due_date'], '2026-10-01');
is_(!isset($d['total']) && !isset($d['client_id']) && !isset($d['id']),
    'no total, no client id, no invoice id');

echo "\nLast payment carries the method, and nothing more\n";
$d = toolsFor($IDENT_A, $gw)->call('get_my_last_payment')['data'];
t('exactly three fields', array_keys($d), ['amount', 'date', 'method']);
t('the method a customer would recognise', $d['method'], 'MTN MoMo');

echo "\nNothing internal escapes any of the five\n";
$g = new LoggingGw(); $g->svc = [7 => [svcRow(1)]];
$T3 = toolsFor($IDENT_A, $g);
$all = '';
foreach (CustomerDataTools::BRAIN_TOOLS as $tool) $all .= json_encode($T3->call($tool));
foreach (['_raw', 'client_id', 'splynx', 'line_status', 'service_address',
          'status_code', 'plan_id', '"id"', 'phone'] as $bad) {
    is_(stripos($all, $bad) === false, "no {$bad} in any brain tool result");
}

echo "\nA missing record and a backend failure are both honest\n";
// Client 9 genuinely has none of these in the fixture — 7 does, so using 7
// here would have tested nothing.
$IDENT_C = ['status' => CustomerIdentity::IDENTIFIED, 'client_id' => 9];
$TE = toolsFor($IDENT_C, new LoggingGw());
foreach (['get_my_plan', 'get_my_service_status', 'get_my_latest_invoice',
          'get_my_last_payment'] as $tool) {
    $r = $TE->call($tool);
    is_(empty($r['ok']) && ($r['error'] ?? '') === 'not found', "{$tool}: not found");
}
$down = new LoggingGw(); $down->fail = true;
$TD = toolsFor($IDENT_A, $down);
foreach (CustomerDataTools::BRAIN_TOOLS as $tool) {
    $r = $TD->call($tool);
    is_(empty($r['ok']), "{$tool} fails closed when the backend is down");
}
t('and a failed call discloses nothing', $TD->disclosed(), []);

echo "\nOnly successful results become permitted disclosure\n";
$g = new LoggingGw(); $g->svc = [7 => [svcRow(1)]];
$TP = toolsFor($IDENT_A, $g);
t('nothing is permitted before any call', $TP->disclosed(), []);
$TP->call('get_my_balance');
is_(in_array('249000', $TP->disclosed(), true), 'the balance is permitted once returned');
$TF = toolsFor($IDENT_C, new LoggingGw());
$TF->call('get_my_latest_invoice');                 // not found for this gateway
t('a not-found call permits nothing', $TF->disclosed(), []);
$TU = CustomerDataTools::forIdentityState(ConversationService::STATE_UNKNOWN, $IDENT_A, $g);
$TU->call('get_my_balance');
t('an unauthenticated call permits nothing', $TU->disclosed(), []);

echo "\nThe guard lets a legitimate answer through, in the words a person writes\n";
$TG = toolsFor($IDENT_A, $g);
$TG->call('get_my_balance');
$TG->call('get_my_service_status');
$TG->call('get_my_latest_invoice');
$permitted = ['values' => $TG->disclosed()];
foreach ([
    'Your balance is UGX 249,000.'                      => 'a formatted balance',
    'You owe 249,000 UGX.'                              => 'currency after the figure',
    'Your balance is 249000.'                           => 'unformatted',
    'Invoice INV-7-002 is due on 2026-10-01.'           => 'the ISO date as returned',
    'Invoice INV-7-002 is due 2026-10-1.'               => 'the same date without the leading zero',
    'It is due 01-10-2026.'                             => 'day first',
] as $reply => $what) {
    $r = ReplyPrivacyGuard::check($reply, $permitted);
    is_(!empty($r['safe']), "passes: {$what}");
}
echo "\nand still blocks what no tool returned\n";
foreach ([
    'Another customer owes UGX 8,675,309.'   => "a figure from nobody's tool call",
    'Our cost on that kit is UGX 1,107,408.' => 'an internal cost',
    'Their kit is KITCLASSIFIED21.'          => "another customer's kit",
] as $reply => $what) {
    $r = ReplyPrivacyGuard::check($reply, $permitted);
    is_(empty($r['safe']), "blocked: {$what}");
    t("  and replaced whole: {$what}", $r['reply'], ReplyPrivacyGuard::SAFE_FALLBACK);
}

echo "\nThe identity is private, constructor-bound, and has no setter\n";
$rc = new ReflectionClass('CustomerDataTools');
$p  = $rc->getProperty('customerId');
is_($p->isPrivate(), 'customerId is private');
foreach ($rc->getMethods(ReflectionMethod::IS_PUBLIC) as $m) {
    if ($m->isStatic() || $m->isConstructor()) continue;
    foreach ($m->getParameters() as $prm) {
        is_(preg_match('/(client|customer|user|account|owner)_?id/i', $prm->getName()) === 0,
            $m->getName() . '() takes no $' . $prm->getName());
    }
}
is_(preg_match('/function\s+set[A-Z]\w*\s*\([^)]*\)[^{]*\{[^}]*customerId/s', $tc) === 0,
    'and nothing sets customerId outside the constructor');

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
