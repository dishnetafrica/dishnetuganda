<?php
declare(strict_types=1);
/**
 * test_shadow_compare.php — the instrument that must not become the leak.
 *
 * B3.4 runs the controlled customer tools beside the legacy support/accounts
 * prompt so B3.5 can migrate on evidence rather than hope. An instrument like
 * that is a tempting place to put a debug line, and a debug line here writes
 * every customer's balance into a log file on every message — a bigger
 * disclosure than the one being migrated away from.
 *
 * So the central test is not "does it compare correctly". It is: given two
 * readers stuffed with canaries, does ANY canary appear anywhere in what the
 * comparison returns. Correctness is tested too, one reason code at a time.
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
require_once $root . '/lib/ShadowCompare.php';

function codeNC(string $f): string {
    $o = '';
    foreach (token_get_all((string)file_get_contents($f)) as $k) {
        if (is_array($k)) { if (in_array($k[0], [T_COMMENT, T_DOC_COMMENT], true)) continue; $o .= $k[1]; }
        else $o .= $k;
    }
    return $o;
}

/** Records every read, so "support never asked for money" is provable. */
class ShadowGw implements CustomerDataGateway
{
    public array $reads = [];
    public ?array $clientRow = null;
    public array $serviceRows = [];
    public array $invoiceRows = [];
    public array $paymentRows = [];

    public function client(int $i): ?array   { $this->reads[] = "client:$i";   return $this->clientRow; }
    public function services(int $i): array  { $this->reads[] = "services:$i"; return $this->serviceRows; }
    public function invoices(int $i): array  { $this->reads[] = "invoices:$i"; return $this->invoiceRows; }
    public function payments(int $i): array  { $this->reads[] = "payments:$i"; return $this->paymentRows; }
    public function invoiceByNumber(string $n): ?array { $this->reads[] = "invoiceByNumber:$n"; return null; }
    public function kits(int $i): array      { $this->reads[] = "kits:$i";     return []; }
    public function kitBySerial(string $s): ?array { $this->reads[] = "kitBySerial:$s"; return null; }
    public function tickets(int $i): array   { $this->reads[] = "tickets:$i";  return []; }
}

$IDENT = ['status' => CustomerIdentity::IDENTIFIED, 'client_id' => 7];

function toolsFor(ShadowGw $gw): CustomerDataTools {
    global $IDENT;
    return CustomerDataTools::forIdentityState(ConversationService::STATE_IDENTIFIED, $IDENT, $gw);
}

/** Every scalar in a nested structure, as strings. */
function leaves($v, array &$out = []): array {
    if (is_array($v)) { foreach ($v as $k => $x) { $out[] = (string)$k; leaves($x, $out); } return $out; }
    if ($v === null || is_bool($v)) return $out;
    $out[] = (string)$v;
    return $out;
}

// ════════════════════════════════════════════════════════════════════════
echo "\n1. The vocabularies are closed\n";
// ════════════════════════════════════════════════════════════════════════

t('five facts', ShadowCompare::FACTS,
  ['balance', 'invoice', 'payment', 'plan', 'service_status']);
t('two channels carry customer data', array_keys(ShadowCompare::CHANNEL_FACTS),
  ['account', 'support']);

$covered = array_merge(...array_values(ShadowCompare::CHANNEL_FACTS));
sort($covered);
$all = ShadowCompare::FACTS; sort($all);
t('every fact belongs to exactly one channel', $covered, $all);

foreach (ShadowCompare::FACTS as $f) {
    is_(isset(ShadowCompare::TOOL_FOR[$f]), "$f has a tool");
    is_(in_array(ShadowCompare::TOOL_FOR[$f], CustomerDataTools::BRAIN_TOOLS, true),
        "the tool for $f is one the brain may call");
}

// The shadow must never reach get_my_invoice: it is the one tool whose
// backend read is not customer-scoped, so it reads another customer's row
// before refusing it. Excluded from BRAIN_TOOLS in B3.3, and the assertion
// above already covers it — this states the intent so a reviewer sees it.
is_(!in_array('get_my_invoice', array_values(ShadowCompare::TOOL_FOR), true),
    'the shadow never calls the tool that reads before it refuses');

// ════════════════════════════════════════════════════════════════════════
echo "\n2. No customer value reaches the result — the whole point\n";
// ════════════════════════════════════════════════════════════════════════

$CANARIES = ['ZZBALANCEZZ', 'ZZNUMBERZZ', 'ZZDATEZZ', 'ZZPLANZZ', 'ZZMETHODZZ',
             'ZZNAMEZZ', '8675309', '424242.42'];

$gw = new ShadowGw();
$gw->clientRow   = ['status' => 'Active', 'balance' => 424242.42, 'currency' => 'ZZNAMEZZ'];
$gw->invoiceRows = [['number' => 'ZZNUMBERZZ', 'client_id' => 7, 'date' => 'ZZDATEZZ',
                     'due_date' => 'ZZDATEZZ', 'total' => 8675309.0, 'due' => 8675309.0]];
$gw->paymentRows = [['amount' => 8675309.0, 'date' => 'ZZDATEZZ', 'method' => 'ZZMETHODZZ']];

$hostileLegacy = [
    'account' => [
        'client_id' => 7, 'name' => 'ZZNAMEZZ', 'balance' => -8675309.0,
        'invoice' => ['number' => 'ZZBALANCEZZ', 'amount_due' => 424242.42, 'due_date' => 'ZZDATEZZ'],
        'last_payment' => ['amount' => 424242.42, 'date' => 'ZZDATEZZ'],
    ],
    'services' => [['name' => 'ZZPLANZZ', 'status' => 1, 'active_to' => 'ZZDATEZZ']],
    'customer_phone' => '+256700000000',
];
$before = $hostileLegacy;

$r = ShadowCompare::compare('account', $hostileLegacy, toolsFor($gw));

$seen = leaves($r);
$leak = [];
foreach ($CANARIES as $c) {
    foreach ($seen as $s) if (strpos($s, $c) !== false) { $leak[] = $c; break; }
    if (strpos($r['line'], $c) !== false) $leak[] = $c . '(line)';
}
t('no canary anywhere in the result', array_values(array_unique($leak)), []);
is_($r['divergent'] !== [], 'the hostile pair does diverge, so something was compared');

// Nothing outside the vocabularies, either — a value could not hide in a
// verdict without first being a verdict.
$ok = true;
foreach ($r['facts'] as $name => $f) {
    if (!in_array($name, ShadowCompare::FACTS, true)) $ok = false;
    if (!in_array($f['verdict'], ShadowCompare::VERDICTS, true)) $ok = false;
    foreach (($f['why'] === '' ? [] : explode('+', $f['why'])) as $w) {
        if (!in_array($w, ShadowCompare::REASONS, true)) $ok = false;
    }
}
is_($ok, 'every name, verdict and reason is from the declared vocabulary');

// The line is built from those same constants and nothing else.
$tokens = explode(' ', $r['line']);
t('the line opens with the channel', array_shift($tokens), 'account');
$lineOk = true;
foreach ($tokens as $tok) {
    [$n, $rest] = array_pad(explode('=', $tok, 2), 2, '');
    if (!in_array($n, ShadowCompare::FACTS, true)) $lineOk = false;
    [$v, $why] = array_pad(explode(':', $rest, 2), 2, '');
    if (!in_array($v, ShadowCompare::VERDICTS, true)) $lineOk = false;
    foreach (($why === '' ? [] : explode('+', $why)) as $w) {
        if (!in_array($w, ShadowCompare::REASONS, true)) $lineOk = false;
    }
}
is_($lineOk, 'every token of the log line is a constant');

// Not "the array came back unchanged" — PHP copies an array on the way in, so
// that assertion passes whatever compare() does to its own copy and catches
// nothing. What can actually change is the signature: one ampersand and the
// caller's context is writable from inside the comparison. Test the ampersand.
t('the legacy context came back unchanged', $hostileLegacy, $before);
$sig = (new ReflectionMethod('ShadowCompare', 'compare'))->getParameters();
is_($sig[1]->isPassedByReference() === false,
    'and compare() cannot be handed the context by reference');
is_($sig[2]->isPassedByReference() === false,
    'nor the tools, so it cannot swap the caller\'s instance');

// A channel name is echoed, so it is constructed rather than copied.
$r2 = ShadowCompare::compare('ZZCHANNELZZ<script>', $hostileLegacy, toolsFor(new ShadowGw()));
t('an unknown channel becomes a constant', $r2['channel'], 'other');
t('and carries no facts', $r2['facts'], []);
t('and no reads were made for it', (new ShadowGw())->reads, []);
is_(strpos($r2['line'], 'ZZCHANNEL') === false, 'the channel name never reaches the line');

// ════════════════════════════════════════════════════════════════════════
echo "\n3. The comparison has no way to write anything down\n";
// ════════════════════════════════════════════════════════════════════════

$src = codeNC($root . '/lib/ShadowCompare.php');
foreach (['file_put_contents', 'fopen', 'fwrite', 'error_log', 'PDO', 'curl_', 'syslog',
          'var_dump', 'print_r', 'echo ', 'header('] as $bad) {
    is_(strpos($src, $bad) === false, "ShadowCompare does not use $bad");
}
is_(strpos($src, 'get_my_invoice') === false, 'ShadowCompare does not name the unscoped tool');

// ════════════════════════════════════════════════════════════════════════
echo "\n4. Agreement reads as agreement\n";
// ════════════════════════════════════════════════════════════════════════

$gw = new ShadowGw();
$gw->clientRow   = ['status' => 'Active', 'balance' => 249000.0, 'currency' => 'UGX'];
$gw->invoiceRows = [['number' => 'INV-7-002', 'client_id' => 7, 'date' => '2026-09-01',
                     'due_date' => '2026-10-01', 'total' => 249000.0, 'due' => 249000.0]];
$gw->paymentRows = [['amount' => 100000.0, 'date' => '2026-08-30', 'method' => 'MTN']];

$agreed = ['account' => [
    'balance' => 249000.0,
    'invoice' => ['number' => 'INV-7-002', 'amount_due' => 249000.0, 'due_date' => '2026-10-01'],
    'last_payment' => ['amount' => 100000.0, 'date' => '2026-08-30'],
]];
$r = ShadowCompare::compare('account', $agreed, toolsFor($gw));
t('balance agrees',  $r['facts']['balance'],  ['verdict' => 'same', 'why' => '']);
t('invoice agrees',  $r['facts']['invoice'],  ['verdict' => 'same', 'why' => '']);
t('payment agrees',  $r['facts']['payment'],  ['verdict' => 'same', 'why' => '']);
t('nothing divergent', $r['divergent'], []);
t('and the line says so', $r['line'], 'account balance=same invoice=same payment=same');

// ════════════════════════════════════════════════════════════════════════
echo "\n5. Each way of disagreeing has its own name\n";
// ════════════════════════════════════════════════════════════════════════

/** @return array{verdict:string,why:string} */
function fact(string $chan, string $name, array $legacy, ShadowGw $gw): array {
    $r = ShadowCompare::compare($chan, $legacy, toolsFor($gw));
    return $r['facts'][$name];
}

// sign — the dangerous one: in credit told they owe.
$g = new ShadowGw(); $g->clientRow = ['balance' => -5000.0, 'currency' => 'UGX'];
t('owed vs credit is a SIGN divergence',
  fact('account', 'balance', ['account' => ['balance' => 5000.0]], $g),
  ['verdict' => 'differ', 'why' => 'sign']);

// clear vs owed is also a sign divergence — 0 and 249000 are different states.
$g = new ShadowGw(); $g->clientRow = ['balance' => 0.0, 'currency' => 'UGX'];
t('nothing owed vs owed is a SIGN divergence',
  fact('account', 'balance', ['account' => ['balance' => 249000.0]], $g),
  ['verdict' => 'differ', 'why' => 'sign']);

// amount — same side of zero, different figure.
$g = new ShadowGw(); $g->clientRow = ['balance' => 249000.0, 'currency' => 'UGX'];
t('same side, different figure is an AMOUNT divergence',
  fact('account', 'balance', ['account' => ['balance' => 250000.0]], $g),
  ['verdict' => 'differ', 'why' => 'amount']);

// and a sub-cent difference is not a divergence at all.
$g = new ShadowGw(); $g->clientRow = ['balance' => 249000.001, 'currency' => 'UGX'];
t('rounding is not a divergence',
  fact('account', 'balance', ['account' => ['balance' => 249000.0]], $g),
  ['verdict' => 'same', 'why' => '']);

// number / amount / due_date on the invoice, singly and together.
$inv = static function (string $num, float $due, string $date): ShadowGw {
    $g = new ShadowGw();
    $g->invoiceRows = [['number' => $num, 'client_id' => 7, 'date' => '2026-09-01',
                        'due_date' => $date, 'total' => $due, 'due' => $due]];
    return $g;
};
$legacyInv = ['account' => ['balance' => 0.0,
    'invoice' => ['number' => 'INV-A', 'amount_due' => 100.0, 'due_date' => '2026-10-01']]];

t('a different invoice NUMBER',
  fact('account', 'invoice', $legacyInv, $inv('INV-B', 100.0, '2026-10-01')),
  ['verdict' => 'differ', 'why' => 'number']);
t('a different AMOUNT due',
  fact('account', 'invoice', $legacyInv, $inv('INV-A', 250.0, '2026-10-01')),
  ['verdict' => 'differ', 'why' => 'amount']);
t('a different DUE DATE',
  fact('account', 'invoice', $legacyInv, $inv('INV-A', 100.0, '2026-11-15')),
  ['verdict' => 'differ', 'why' => 'due_date']);
t('all three at once, in declared order',
  fact('account', 'invoice', $legacyInv, $inv('INV-B', 250.0, '2026-11-15')),
  ['verdict' => 'differ', 'why' => 'number+amount+due_date']);

// payment
$g = new ShadowGw(); $g->paymentRows = [['amount' => 50.0, 'date' => '2026-08-30', 'method' => 'MTN']];
t('a different payment AMOUNT',
  fact('account', 'payment', ['account' => ['balance' => 0.0,
       'last_payment' => ['amount' => 60.0, 'date' => '2026-08-30']]], $g),
  ['verdict' => 'differ', 'why' => 'amount']);
t('a different payment DATE',
  fact('account', 'payment', ['account' => ['balance' => 0.0,
       'last_payment' => ['amount' => 50.0, 'date' => '2026-08-29']]], $g),
  ['verdict' => 'differ', 'why' => 'date']);

// plan — count before name, because a missing service is the bigger news.
$g = new ShadowGw();
$g->serviceRows = [['plan' => 'Residential 50', 'price' => 249000.0, 'currency' => 'UGX',
                    'status_code' => 1, 'active_to' => '2026-10-01']];
t('one plan each, same name',
  fact('support', 'plan', ['services' => [['name' => 'Residential 50', 'active_to' => '2026-10-01']]], $g),
  ['verdict' => 'same', 'why' => '']);
t('one plan each, different NAME',
  fact('support', 'plan', ['services' => [['name' => 'Business 100', 'active_to' => '2026-10-01']]], $g),
  ['verdict' => 'differ', 'why' => 'name']);
t('two services against one is a COUNT divergence',
  fact('support', 'plan', ['services' => [['name' => 'Residential 50'], ['name' => 'Second line']]], $g),
  ['verdict' => 'differ', 'why' => 'count']);

// service_status — active_to, count, and the unmapped code.
t('the same expiry agrees',
  fact('support', 'service_status', ['services' => [['name' => 'x', 'active_to' => '2026-10-01']]], $g),
  ['verdict' => 'same', 'why' => '']);
t('a different expiry is an ACTIVE_TO divergence',
  fact('support', 'service_status', ['services' => [['name' => 'x', 'active_to' => '2026-12-31']]], $g),
  ['verdict' => 'differ', 'why' => 'active_to']);

$gu = new ShadowGw();
$gu->serviceRows = [['plan' => 'Residential 50', 'price' => 1.0, 'currency' => 'UGX',
                     'status_code' => 99, 'active_to' => '2026-10-01']];
t('a uCRM status code we have never seen is reported',
  fact('support', 'service_status', ['services' => [['name' => 'Residential 50', 'active_to' => '2026-10-01']]], $gu),
  ['verdict' => 'differ', 'why' => 'unmapped_status']);
t('even when the dates disagree too',
  fact('support', 'service_status', ['services' => [['name' => 'Residential 50', 'active_to' => '2026-01-01']]], $gu),
  ['verdict' => 'differ', 'why' => 'active_to+unmapped_status']);

// ════════════════════════════════════════════════════════════════════════
echo "\n6. One side having it is not the same as the two disagreeing\n";
// ════════════════════════════════════════════════════════════════════════

$empty = new ShadowGw();
t('the prompt states it, the tool finds nothing',
  fact('account', 'invoice', $legacyInv, $empty),
  ['verdict' => 'legacy_only', 'why' => '']);

$g = $inv('INV-A', 100.0, '2026-10-01');
t('the tool has it, the prompt says nothing',
  fact('account', 'invoice', ['account' => ['balance' => 0.0]], $g),
  ['verdict' => 'tool_only', 'why' => '']);

t('neither has it',
  fact('account', 'invoice', ['account' => ['balance' => 0.0]], $empty),
  ['verdict' => 'absent', 'why' => '']);
// absent must not be counted as a divergence, while the other two verdicts
// on the same turn still are. Here the gateway holds no client row at all, so
// balance is legacy_only and invoice and payment are absent.
t('only the fact one side actually holds is reported divergent',
  ShadowCompare::compare('account', ['account' => ['balance' => 0.0]], toolsFor($empty))['divergent'],
  ['balance']);

// A customer with no account block at all — support's shape on the accounts
// channel, or a CRM read that failed.
$r = ShadowCompare::compare('account', [], toolsFor($empty));
t('no legacy account and no tool data is absent throughout',
  [$r['facts']['balance']['verdict'], $r['facts']['invoice']['verdict'], $r['facts']['payment']['verdict']],
  ['absent', 'absent', 'absent']);
t('and nothing is reported as divergent', $r['divergent'], []);

// ════════════════════════════════════════════════════════════════════════
echo "\n7. It asks only for the facts this channel states\n";
// ════════════════════════════════════════════════════════════════════════

$g = new ShadowGw();
$g->serviceRows = [['plan' => 'R50', 'price' => 1.0, 'currency' => 'UGX',
                    'status_code' => 1, 'active_to' => '2026-10-01']];
ShadowCompare::compare('support', ['services' => [['name' => 'R50', 'active_to' => '2026-10-01']]], toolsFor($g));
t('support reads services only, twice', $g->reads, ['services:7', 'services:7']);

$g2 = new ShadowGw();
$g2->clientRow = ['balance' => 0.0, 'currency' => 'UGX'];
ShadowCompare::compare('account', ['account' => ['balance' => 0.0]], toolsFor($g2));
t('accounts reads client, invoices and payments — and no services',
  $g2->reads, ['client:7', 'invoices:7', 'payments:7']);

$g3 = new ShadowGw();
ShadowCompare::compare('sales', ['account' => ['balance' => 0.0]], toolsFor($g3));
t('sales reads nothing at all', $g3->reads, []);

// ════════════════════════════════════════════════════════════════════════
echo "\n8. An unidentified caller is an error, not an empty answer\n";
// ════════════════════════════════════════════════════════════════════════

$g = new ShadowGw();
$g->invoiceRows = [['number' => 'INV-A', 'client_id' => 7, 'date' => '2026-09-01',
                    'due_date' => '2026-10-01', 'total' => 100.0, 'due' => 100.0]];

foreach ([ConversationService::STATE_ANONYMOUS, ConversationService::STATE_UNKNOWN,
          ConversationService::STATE_AMBIGUOUS] as $state) {
    $anon = CustomerDataTools::forIdentityState($state, $IDENT, $g);
    $r = ShadowCompare::compare('account', $legacyInv, $anon);
    t("$state is reported as an error, not a divergence",
      $r['facts']['invoice'], ['verdict' => 'error', 'why' => 'tool_error']);
}
t('and no read was attempted for any of them', $g->reads, []);

// ════════════════════════════════════════════════════════════════════════
echo "\n9. The worker cannot let a shadow reach the customer\n";
// ════════════════════════════════════════════════════════════════════════

$w = codeNC($root . '/workers/AiReplyWorker.php');
is_(strpos($w, 'private function shadowCompare(string $channel,int $convId,int $clientId,bool $identified,array $ctx):void') !== false
    || preg_match('/private function shadowCompare\([^)]*\)\s*:\s*void/', $w) === 1,
    'shadowCompare returns void, so no result can be assigned into the context');
// The worker class needs its whole runtime to load, so this reads the
// signature rather than reflecting on it. What it is looking for is an
// ampersand: one of those and the comparison can write into the live context.
preg_match('/function shadowCompare\s*\(([^)]*)\)/', $w, $m);
is_(($m[1] ?? '&') !== '&' && strpos($m[1], '&') === false,
    'it takes every argument by value, so it cannot mutate the context either');
is_(preg_match('/\$\w+\s*=\s*\$this->shadowCompare\(/', $w) !== 1,
    'nothing assigns the result of the shadow');
is_(strpos($w, 'ai_shadow_compare') !== false, 'it is behind a flag');
is_(preg_match('/shadow[^\n]*\$e->getMessage\(\)/', $w) !== 1,
    'the shadow failure path logs no exception message');
is_(strpos($w, 'UcrmGatewayHost') !== false, 'it reads uCRM through the gateway host');
is_(strpos($w, "forIdentityState") !== false, 'and builds its tools through the identity gate');

require_once $root . '/lib/UcrmCustomerDataGateway.php';
is_(class_exists('UcrmGatewayHost'), 'the host exists');
foreach (['getCrm', 'getClientServices', 'getLastPayment', 'getPdo'] as $m) {
    is_(method_exists('UcrmGatewayHost', $m), "the host provides $m()");
}
$host = new UcrmGatewayHost(null, null);
t('with no CRM it answers empty, never throws', $host->getClientServices(7), []);
t('and no payment', $host->getLastPayment(7), null);
$gwNull = new UcrmCustomerDataGateway($host);
t('a gateway over an empty host finds no client', $gwNull->client(7), null);
t('no services', $gwNull->services(7), []);
t('no kits, because the table is unreadable rather than empty', $gwNull->kits(7), []);

// ════════════════════════════════════════════════════════════════════════
echo "\n10. The differences we already know about, encoded so they stay found\n";
// ════════════════════════════════════════════════════════════════════════

// DishNetTools::normaliseInvoice() reads $i['invoiceNumber']; every other
// uCRM invoice reader in this plugin reads $i['number']. So on live data the
// legacy side carries no number at all. The shadow must call that out rather
// than shrug at it.
$g = $inv('INV-7-002', 249000.0, '2026-10-01');
t('a legacy invoice with no number is a NUMBER divergence, not agreement',
  fact('account', 'invoice', ['account' => ['balance' => 0.0,
       'invoice' => ['number' => null, 'amount_due' => 249000.0, 'due_date' => '2026-10-01']]], $g),
  ['verdict' => 'differ', 'why' => 'number']);

// The two readers ask uCRM different questions: legacy wants the unpaid set
// first, the gateway wants the most recent. A customer whose newest invoice
// is settled gets a different one from each.
$g = new ShadowGw();
$g->invoiceRows = [   // most recent first, as uCRM returns them
    ['number' => 'INV-SEP', 'client_id' => 7, 'date' => '2026-09-01',
     'due_date' => '2026-10-01', 'total' => 249000.0, 'due' => 0.0],
    ['number' => 'INV-AUG', 'client_id' => 7, 'date' => '2026-08-01',
     'due_date' => '2026-09-01', 'total' => 249000.0, 'due' => 249000.0],
];
t('unpaid-first against most-recent shows up as number and amount',
  fact('account', 'invoice', ['account' => ['balance' => 0.0,
       'invoice' => ['number' => 'INV-AUG', 'amount_due' => 249000.0, 'due_date' => '2026-09-01']]], $g),
  ['verdict' => 'differ', 'why' => 'number+amount+due_date']);

// Opposite precedence on the plan name: legacy prefers name then
// servicePlanName; the gateway prefers servicePlanName then name.
$g = new ShadowGw();
$g->serviceRows = [['plan' => 'Residential 50Mbps', 'price' => 1.0, 'currency' => 'UGX',
                    'status_code' => 1, 'active_to' => '2026-10-01']];
t('a row whose two name fields disagree is a NAME divergence',
  fact('support', 'plan', ['services' => [
       ['name' => 'Res 50', 'plan_name' => 'Residential 50Mbps', 'active_to' => '2026-10-01']]], $g),
  ['verdict' => 'differ', 'why' => 'name']);

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
