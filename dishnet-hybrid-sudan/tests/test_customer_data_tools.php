<?php
declare(strict_types=1);
/**
 * test_customer_data_tools.php — the security boundary, tested adversarially.
 *
 * The property under test, stated plainly:
 *
 *   EVEN IF THE MODEL IS COMPLETELY COMPROMISED — it has been told to ignore
 *   every instruction, it believes it is an administrator, it is inventing
 *   tool calls with whatever arguments it likes — it still cannot obtain
 *   another customer's information through these tools.
 *
 * That is why the gateway below is deliberately naive: invoiceByNumber() and
 * kitBySerial() hand back ANY record, for ANY customer. If the gateway
 * filtered as well, a broken check in CustomerDataTools would still look
 * safe here. It does not filter, so these tests measure the real check.
 */
$pass = 0; $fail = 0;
function is_(bool $c, string $m): void { global $pass, $fail;
    if ($c) { $pass++; echo "  ok   $m\n"; } else { $fail++; echo "  FAIL $m\n"; } }
function t(string $n, $got, $want) { global $pass, $fail;
    if ($got === $want) { $pass++; printf("  ok   %s\n", $n); }
    else { $fail++; printf("  FAIL %s\n       got  %s\n       want %s\n", $n, var_export($got, true), var_export($want, true)); } }

$root = dirname(__DIR__);
require_once $root . '/lib/CustomerDataTools.php';

/** Two customers. Customer 7 is ours; customer 21 must stay unreachable. */
final class TwoCustomerGateway implements CustomerDataGateway
{
    public array $reads = [];
    private array $clients = [
        7  => ['status' => 'Active',    'balance' => 249000.0, 'currency' => 'UGX'],
        21 => ['status' => 'Suspended', 'balance' => 9999999.0, 'currency' => 'UGX'],
    ];
    private array $services = [
        7  => [['plan' => 'Starlink Residential Lite', 'price' => 249000.0, 'currency' => 'UGX',
                'status' => 'active', 'active_to' => '2026-10-11']],
        21 => [['plan' => 'SECRET BUSINESS PLAN', 'price' => 5000000.0, 'currency' => 'UGX',
                'status' => 'suspended', 'active_to' => '2026-12-01']],
    ];
    private array $invoices = [
        7  => [['number' => 'INV-7-001',  'client_id' => 7,  'date' => '2026-09-11',
                'total' => 249000.0, 'due' => 0.0, 'currency' => 'UGX']],
        21 => [['number' => 'INV-21-999', 'client_id' => 21, 'date' => '2026-09-01',
                'total' => 5000000.0, 'due' => 5000000.0, 'currency' => 'UGX']],
    ];
    private array $payments = [
        7  => [['amount' => 249000.0,  'date' => '2026-09-11', 'method' => 'DPO Pay']],
        21 => [['amount' => 5000000.0, 'date' => '2026-09-02', 'method' => 'Bank']],
    ];
    private array $kits = [
        7  => [['kit_serial' => 'KIT404246364BX6', 'client_id' => 7,  'assigned_at' => '2026-09-10']],
        21 => [['kit_serial' => 'KIT999999999ZZ9', 'client_id' => 21, 'assigned_at' => '2026-08-01']],
    ];
    private array $tickets = [
        7  => [['reference' => 'SUP-7-1',  'status' => 'open',   'opened' => '2026-09-12']],
        21 => [['reference' => 'SUP-21-9', 'status' => 'closed', 'opened' => '2026-08-03']],
    ];

    public function client(int $id): ?array { $this->reads[] = "client:$id"; return $this->clients[$id] ?? null; }
    public function services(int $id): array { $this->reads[] = "services:$id"; return $this->services[$id] ?? []; }
    public function invoices(int $id): array { $this->reads[] = "invoices:$id"; return $this->invoices[$id] ?? []; }
    public function payments(int $id): array { $this->reads[] = "payments:$id"; return $this->payments[$id] ?? []; }
    public function kits(int $id): array     { $this->reads[] = "kits:$id";     return $this->kits[$id] ?? []; }
    public function tickets(int $id): array  { $this->reads[] = "tickets:$id";  return $this->tickets[$id] ?? []; }

    /** Unfiltered ON PURPOSE — hands back any customer's invoice. */
    public function invoiceByNumber(string $n): ?array {
        $this->reads[] = "invoiceByNumber:$n";
        foreach ($this->invoices as $set) foreach ($set as $i) if ($i['number'] === $n) return $i;
        return null;
    }
    /** Unfiltered ON PURPOSE. */
    public function kitBySerial(string $s): ?array {
        $this->reads[] = "kitBySerial:$s";
        foreach ($this->kits as $set) foreach ($set as $k) if ($k['kit_serial'] === $s) return $k;
        return null;
    }
}

$ours    = ['status' => CustomerIdentity::IDENTIFIED, 'client_id' => 7];
$nobody  = ['status' => CustomerIdentity::UNKNOWN,    'client_id' => 0];
$ambig   = ['status' => CustomerIdentity::AMBIGUOUS,  'client_id' => 0];

echo "\nNo tool anywhere accepts a customer id\n";
// The structural reason the attack cannot work: there is no parameter to use.
$rc = new ReflectionClass('CustomerDataTools');
foreach ($rc->getMethods() as $m) {
    foreach ($m->getParameters() as $p) {
        is_(preg_match('/(client|customer|user|account)_?id/i', $p->getName()) === 0,
            "{$m->getName()}() has no \${$p->getName()} parameter");
    }
}
$hasPublicId = false;
foreach ($rc->getMethods(ReflectionMethod::IS_PUBLIC) as $m) {
    foreach ($m->getParameters() as $p) {
        if (preg_match('/(client|customer|user|account)_?id/i', $p->getName())) $hasPublicId = true;
    }
}
is_($hasPublicId === false, 'and no PUBLIC method does either');

echo "\nThe honest path works\n";
$gw = new TwoCustomerGateway();
$T  = new CustomerDataTools($ours, $gw);
is_($T->isAuthenticated(), 'an identified customer is authenticated');
t('their plan',        $T->call('get_my_plan')['data']['plan'],           'Starlink Residential Lite');
t('their balance',     $T->call('get_my_balance')['data']['balance'],     249000.0);
t('their expiry',      $T->call('get_my_service_status')['data']['active_to'], '2026-10-11');
t('their own invoice', $T->call('get_my_invoice', ['number' => 'INV-7-001'])['data']['number'], 'INV-7-001');
t('their own kit',     $T->call('get_my_kit', ['serial' => 'KIT404246364BX6'])['data']['kit'], 'KIT404246364BX6');

echo "\nA COMPROMISED MODEL CANNOT REACH CUSTOMER 21\n";
// Every shape a model might produce if it had been talked into trying.
$attacks = [
    ['get_my_plan',          ['customer_id' => 21]],
    ['get_my_plan',          ['client_id' => 21]],
    ['get_my_plan',          ['clientId' => 21]],
    ['get_my_balance',       ['customerId' => 21]],
    ['get_my_balance',       ['account_id' => 21]],
    ['get_my_balance',       ['user_id' => 21]],
    ['get_my_last_payment',  ['client' => 21]],
    ['get_my_invoices',      ['customer' => 21]],
    ['get_my_kits',          ['owner_id' => 21]],
    ['get_my_support_cases', ['client_ref' => 21]],
    // nested, which is how a model tends to phrase a filter
    ['get_my_plan',          ['filter' => ['client_id' => 21]]],
    ['get_my_plan',          ['where' => ['customer' => ['id' => 21]]]],
    ['get_my_balance',       ['options' => ['scope' => ['account_id' => 21]]]],
    ['get_my_invoices',      ['query' => ['nested' => ['deep' => ['customer_id' => 21]]]]],
];
foreach ($attacks as [$tool, $args]) {
    $r = $T->call($tool, $args);
    $json = json_encode($r);
    $label = $tool . ' ' . json_encode($args);
    is_(strpos($json, 'SECRET BUSINESS PLAN') === false
        && strpos($json, '5000000') === false
        && strpos($json, 'Suspended') === false,
        "no customer 21 data from $label");
}

echo "\nEvery gateway read was for customer 7, never 21\n";
// The strongest form: not merely "no 21 data came back", but "21 was never
// even asked for". A filter applied after a read is a filter that can be
// forgotten; this proves the id never varied.
$bad = array_values(array_filter($gw->reads, static fn(string $r): bool =>
    substr($r, -3) === ':21'));
t('no read touched customer 21', $bad, []);

echo "\nAnother customer's invoice is NOT FOUND, not forbidden\n";
// The gateway WILL return it. The check above the gateway must refuse it.
$gw2 = new TwoCustomerGateway();
$T2  = new CustomerDataTools($ours, $gw2);
$r = $T2->call('get_my_invoice', ['number' => 'INV-21-999']);
is_(($r['ok'] ?? true) === false, 'refused');
t('and reported as not found', $r['error'] ?? '', 'not found');
is_(in_array('invoiceByNumber:INV-21-999', $gw2->reads, true),
    'even though the gateway really did hand the record over');
is_(strpos(json_encode($r), '5000000') === false, 'and none of its money came back');

echo "\nAnother customer's kit is NOT FOUND\n";
$r = $T2->call('get_my_kit', ['serial' => 'KIT999999999ZZ9']);
is_(($r['ok'] ?? true) === false, 'refused');
t('not found', $r['error'] ?? '', 'not found');

echo "\nExistence is never confirmed either way\n";
// "Not yours" and "no such thing" must be indistinguishable, or the tool
// becomes an oracle for what other customers own.
$real = $T2->call('get_my_invoice', ['number' => 'INV-21-999']);
$fake = $T2->call('get_my_invoice', ['number' => 'INV-DOES-NOT-EXIST']);
t('another customer\'s invoice and a nonexistent one answer identically',
  $real['error'] ?? '', $fake['error'] ?? '');
$realK = $T2->call('get_my_kit', ['serial' => 'KIT999999999ZZ9']);
$fakeK = $T2->call('get_my_kit', ['serial' => 'KITNOPE']);
t('same for kits', $realK['error'] ?? '', $fakeK['error'] ?? '');

echo "\nIdentity arguments are stripped, and the attempt is recorded\n";
$r = $T2->call('get_my_plan', ['customer_id' => 21, 'filter' => ['client_id' => 99]]);
is_(in_array('customer_id', $r['stripped'] ?? [], true), 'the top-level id is stripped');
is_(in_array('filter.client_id', $r['stripped'] ?? [], true), 'and the nested one, with its path');
$trail = $T2->auditTrail();
$last  = end($trail);
is_(($last['stripped_identity_args'] ?? []) !== [], 'the audit records that it happened');
is_(json_encode($trail) !== false && strpos(json_encode($trail), 'SECRET') === false,
    'and the audit holds no customer data');

echo "\nAn unidentified caller gets nothing at all\n";
foreach ([$nobody, $ambig] as $who) {
    $U = new CustomerDataTools($who, new TwoCustomerGateway());
    is_($U->isAuthenticated() === false, $who['status'] . ' is not authenticated');
    foreach (array_keys(CustomerDataTools::catalogue()) as $tool) {
        $r = $U->call($tool, ['number' => 'INV-7-001', 'serial' => 'KIT404246364BX6']);
        if (($r['ok'] ?? false) !== false) {
            is_(false, "$tool leaked data to an unidentified caller");
        }
    }
    is_(true, $who['status'] . ': every tool refused');
}

echo "\nAn identity that claims to be identified but carries no id is not trusted\n";
// A malformed or forged identity array must not authenticate.
foreach ([
    ['status' => CustomerIdentity::IDENTIFIED, 'client_id' => 0],
    ['status' => CustomerIdentity::IDENTIFIED],
    ['status' => CustomerIdentity::IDENTIFIED, 'client_id' => -1],
    ['client_id' => 7],
    [],
] as $i => $bad) {
    $B = new CustomerDataTools($bad, new TwoCustomerGateway());
    is_($B->isAuthenticated() === false, 'malformed identity ' . ($i + 1) . ' does not authenticate');
}

echo "\nUnknown tools are refused, not guessed at\n";
foreach (['get_customer', 'get_any_customer', 'sql', 'SELECT * FROM clients',
          'get_my_plan; DROP TABLE', '../get_my_plan'] as $t) {
    $r = $T2->call($t, ['customer_id' => 21]);
    is_(($r['ok'] ?? true) === false, "\"$t\" is refused");
}

echo "\nTools return the minimum, not a customer object\n";
// "When does my service expire?" must not return a record that happens to
// contain an expiry date.
$svc = $T2->call('get_my_service_status')['data'];
t('service status has exactly two fields', array_keys($svc), ['status', 'active_to']);
is_(!isset($svc['balance']) && !isset($svc['price']), 'and no money in it');
$bal = $T2->call('get_my_balance')['data'];
t('balance has exactly two fields', array_keys($bal), ['balance', 'currency']);
is_(!isset($bal['plan']) && !isset($bal['status']), 'and no plan or status in it');

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
