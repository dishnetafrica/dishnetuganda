<?php
require_once dirname(__DIR__) . '/lib/MailProviderInterface.php';
require_once dirname(__DIR__) . '/lib/CustomerIdentityService.php';

$pass=0; $fail=0;
function t(string $n, $got, $want){ global $pass,$fail;
  if ($got===$want){$pass++;printf("  ok   %s\n",$n);}
  else{$fail++;printf("  FAIL %s\n       got  %s\n       want %s\n",$n,var_export($got,true),var_export($want,true));}}

/** In-memory database with the real migration DDL. */
function freshPdo(): PDO {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $sql = file_get_contents(dirname(__DIR__) . '/migrations/063_customer_identity.sql');
    $pdo->exec($sql);
    return $pdo;
}

class FakeProvider implements MailProviderInterface {
    public array $created = [];
    public array $suspended = [];
    public bool  $failNext = false;
    public function name(): string { return 'fake'; }
    public function isConfigured(): bool { return true; }
    public function ensureMailbox(string $email, string $displayName, int $quotaMb = 250): array {
        if ($this->failNext) { $this->failNext = false; return ['ok'=>false,'data'=>null,'error'=>'simulated outage']; }
        $this->created[] = $email;
        return ['ok'=>true,'data'=>$email,'error'=>''];
    }
    public function suspendMailbox(string $email): array { $this->suspended[] = $email; return ['ok'=>true,'data'=>true,'error'=>'']; }
    public function unsuspendMailbox(string $email): array { return ['ok'=>true,'data'=>true,'error'=>'']; }
    public function resetPassword(string $email): array { return ['ok'=>true,'data'=>'Temp1234Pass','error'=>'']; }
}

echo "Local-part generation\n";
t('simple name',            CustomerIdentityService::makeLocalPart('John Doe'), 'john.doe');
t('extra spaces collapse',  CustomerIdentityService::makeLocalPart('  John   Doe '), 'john.doe');
t('accents fold to ascii',  CustomerIdentityService::makeLocalPart('José Müller'), 'jose.muller');
t('hyphens survive',        CustomerIdentityService::makeLocalPart('Mary Akello-Okot'), 'mary.akello-okot');
t('junk stripped',          CustomerIdentityService::makeLocalPart("O'Brien & Sons!!"), 'obrien.sons');
t('company fallback',       CustomerIdentityService::makeLocalPart('', 'ABC Company Ltd'), 'abc.company.ltd');
t('digit start gets prefix', str_starts_with(CustomerIdentityService::makeLocalPart('4G Telecom'), 'c.'), true);
t('never empty',            CustomerIdentityService::makeLocalPart('!!!', '???') !== '', true);

echo "\nReservation and idempotency\n";
$pdo = freshPdo(); $prov = new FakeProvider();
$svc = new CustomerIdentityService($pdo, ['identity_domain' => 'dishnetuganda.com'], $prov);

$r1 = $svc->reserveForClient(101, 'John Doe');
t('reserve returns the address', $r1['data'], 'john.doe@dishnetuganda.com');
$r2 = $svc->reserveForClient(101, 'John Doe RENAMED LATER');
t('re-reserve returns the SAME address (permanent identity)', $r2['data'], 'john.doe@dishnetuganda.com');
t('exactly one row for the client', (int)$pdo->query('SELECT COUNT(*) FROM customer_identities')->fetchColumn(), 1);

$r3 = $svc->reserveForClient(102, 'John Doe');   // homonym
t('second John Doe gets a suffixed address', $r3['data'], 'john.doe.2@dishnetuganda.com');
$r4 = $svc->reserveForClient(103, 'John Doe');
t('third John Doe increments again', $r4['data'], 'john.doe.3@dishnetuganda.com');

echo "\nQueue processing\n";
$res = $svc->processPending(10);
t('all three provisions processed', $res['processed'], 3);
t('provider created three mailboxes', count($prov->created), 3);
$row = $svc->getByClient(101);
t('status becomes provisioned', $row['status'], 'provisioned');
t('queue action cleared', $row['pending_action'], null);
$res2 = $svc->processPending(10);
t('second run finds nothing (idempotent)', $res2['processed'] + $res2['failed'], 0);

echo "\nFailure and retry\n";
$svc->reserveForClient(200, 'Mary Akam');
$prov->failNext = true;
$res = $svc->processPending(10);
t('failure counted, not thrown', $res['failed'], 1);
$row = $svc->getByClient(200);
t('row keeps its pending action', $row['pending_action'], 'provision');
t('attempts incremented', (int)$row['attempts'], 1);
t('error recorded', str_contains((string)$row['last_error'], 'simulated outage'), true);
$resBackoff = $svc->processPending(10);
t('backoff defers the immediate retry', $resBackoff['processed'] + $resBackoff['failed'], 0);
// make it due again and let it succeed
$pdo->exec("UPDATE customer_identities SET updated_at=datetime('now','-1 day') WHERE client_id=200");
$res = $svc->processPending(10);
t('retry succeeds once provider recovers', $res['processed'], 1);

echo "\nSuspension (retention, never deletion)\n";
$s = $svc->requestSuspend(101, 'client.archive');
t('suspend queues', $s['ok'], true);
$pdo->exec("UPDATE customer_identities SET updated_at=datetime('now','-1 day') WHERE client_id=101");
$svc->processPending(10);
$row = $svc->getByClient(101);
t('status becomes suspended', $row['status'], 'suspended');
t('provider suspended the right mailbox', $prov->suspended, ['john.doe@dishnetuganda.com']);
t('email row is retained', $row['email'], 'john.doe@dishnetuganda.com');
t('suspending twice is a no-op', $svc->requestSuspend(101)['ok'], true);

echo "\nLookups\n";
t('findClientByEmail', $svc->findClientByEmail('JOHN.DOE.2@dishnetuganda.com '), 102);
t('unknown email is null', $svc->findClientByEmail('nobody@dishnetuganda.com'), null);

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail ? 1 : 0);
