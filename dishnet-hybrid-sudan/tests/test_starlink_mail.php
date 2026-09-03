<?php
require_once dirname(__DIR__) . '/lib/MailProviderInterface.php';
require_once dirname(__DIR__) . '/lib/CustomerIdentityService.php';
require_once dirname(__DIR__) . '/lib/StarlinkMailClassifier.php';
require_once dirname(__DIR__) . '/workers/StarlinkMailWorker.php';

$pass=0; $fail=0;
function t(string $n, $got, $want){ global $pass,$fail;
  if ($got===$want){$pass++;printf("  ok   %s\n",$n);}
  else{$fail++;printf("  FAIL %s\n       got  %s\n       want %s\n",$n,var_export($got,true),var_export($want,true));}}

function freshPdo(): PDO {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec(file_get_contents(dirname(__DIR__) . '/migrations/063_customer_identity.sql'));
    return $pdo;
}

class NullProvider implements MailProviderInterface {
    public function name(): string { return 'null'; }
    public function isConfigured(): bool { return true; }
    public function ensureMailbox(string $e, string $d, int $q = 250): array { return ['ok'=>true,'data'=>$e,'error'=>'']; }
    public function suspendMailbox(string $e): array { return ['ok'=>true,'data'=>true,'error'=>'']; }
    public function unsuspendMailbox(string $e): array { return ['ok'=>true,'data'=>true,'error'=>'']; }
    public function resetPassword(string $e): array { return ['ok'=>true,'data'=>'x','error'=>'']; }
}

class SpyAlerts {
    public array $sent = [];
    public function notify(string $key, string $text, int $cooldownMin = 240): array {
        $this->sent[] = $key; return ['ok'=>true];
    }
}

/** Worker wired for pure logic tests: stub classifier, spy alerts, no CRM/evo. */
function makeWorker(PDO $pdo, CustomerIdentityService $ids, SpyAlerts $alerts, array $verdict): StarlinkMailWorker {
    $classifier = new StarlinkMailClassifier([], function () use ($verdict) {
        return json_encode($verdict);
    });
    return new StarlinkMailWorker($pdo, ['starlink_mail_enabled' => true], $ids, null, $alerts, null, null, $classifier);
}

$pdo = freshPdo();
$ids = new CustomerIdentityService($pdo, ['identity_domain' => 'dishnetuganda.com'], new NullProvider());
$ids->reserveForClient(500, 'John Doe');   // john.doe@dishnetuganda.com

$shippedVerdict = ['type' => 'ORDER_SHIPPED', 'extracted' => ['tracking_number' => 'TRK1'],
                   'confidence' => 0.97, 'action_required' => false, 'summary' => 'kit shipped'];

echo "Matching and exactly-once processing\n";
$alerts = new SpyAlerts();
$w = makeWorker($pdo, $ids, $alerts, $shippedVerdict);
$email = [
    'message_id' => '<m1@starlink.com>', 'from' => 'noreply@starlink.com',
    'to' => ['john.doe@dishnetuganda.com'], 'subject' => 'Your Starlink order has shipped',
    'received_at' => '2026-09-03T10:00:00Z', 'body' => 'Order SL-123 shipped. Tracking TRK1.',
];
$out = $w->processEmail($email);
t('confident informational email is resolved without humans', $out, 'events');
$row = $pdo->query('SELECT * FROM starlink_events')->fetch(PDO::FETCH_ASSOC);
t('matched to the right client', (int)$row['client_id'], 500);
t('type stored', $row['type'], 'ORDER_SHIPPED');
t('confidence stored', (float)$row['confidence'], 0.97);
t('no staff alert for routine mail', $alerts->sent, []);
t('same message again is a dupe', $w->processEmail($email), 'dupes');
t('still exactly one event row', (int)$pdo->query('SELECT COUNT(*) FROM starlink_events')->fetchColumn(), 1);

echo "\nUncertainty always fails toward people\n";
$alerts2 = new SpyAlerts();
$w2 = makeWorker($pdo, $ids, $alerts2, ['type' => 'ORDER_SHIPPED', 'extracted' => [],
    'confidence' => 0.55, 'action_required' => false, 'summary' => 'not sure']);
$out = $w2->processEmail(['message_id' => '<m2@starlink.com>'] + $email);
t('low confidence goes to staff', $out, 'alerted');
t('staff alert sent', count($alerts2->sent), 1);

$alerts3 = new SpyAlerts();
$w3 = makeWorker($pdo, $ids, $alerts3, ['type' => 'EMAIL_VERIFICATION', 'extracted' => [],
    'confidence' => 0.99, 'action_required' => true, 'summary' => 'verify link inside']);
$out = $w3->processEmail(['message_id' => '<m3@starlink.com>'] + $email);
t('action_required goes to staff even at high confidence', $out, 'alerted');

$alerts4 = new SpyAlerts();
$w4 = makeWorker($pdo, $ids, $alerts4, $shippedVerdict);
$out = $w4->processEmail([
    'message_id' => '<m4@starlink.com>', 'from' => 'noreply@starlink.com',
    'to' => ['stranger@nowhere.example'], 'subject' => 'shipped', 'received_at' => '', 'body' => 'x',
]);
t('unmatched customer goes to staff', $out, 'alerted');
t('unmatched row keeps NULL client', $pdo->query("SELECT client_id FROM starlink_events WHERE message_id='<m4@starlink.com>'")->fetchColumn(), null);

echo "\nClassifier hardening\n";
$c = new StarlinkMailClassifier([], fn() => 'sorry, I cannot help with that');
$v = $c->classify('a@b', 's', 'body');
t('non-JSON model output falls back to OTHER', $v['type'], 'OTHER');
t('…with zero confidence', $v['confidence'], 0.0);
t('…and a human required', $v['action_required'], true);

$c2 = new StarlinkMailClassifier([], fn() => 'Here you go: {"type":"order_shipped","confidence":1.7,"action_required":false,"summary":"ok","extracted":{}}');
$v2 = $c2->classify('a@b', 's', 'body');
t('type is case-normalised', $v2['type'], 'ORDER_SHIPPED');
t('confidence clamped to 1.0', $v2['confidence'], 1.0);

$c3 = new StarlinkMailClassifier([], fn() => '{"type":"MAKE_PAYMENT_NOW","confidence":0.9,"action_required":false,"summary":"","extracted":{}}');
t('unknown types collapse to OTHER', $c3->classify('a@b', 's', 'b')['type'], 'OTHER');

echo "\nGates\n";
$wOff = new StarlinkMailWorker($pdo, ['starlink_mail_enabled' => false], $ids);
t('disabled flag skips the run', $wOff->run()['skipped'] ?? '', 'disabled');
$wUnconf = new StarlinkMailWorker($pdo, ['starlink_mail_enabled' => true], $ids);
t('unconfigured JMAP skips the run', $wUnconf->run()['skipped'] ?? '', 'not configured');

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail ? 1 : 0);
