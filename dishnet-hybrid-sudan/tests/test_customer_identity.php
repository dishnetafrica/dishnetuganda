<?php
declare(strict_types=1);
/**
 * test_customer_identity.php — who a WhatsApp number belongs to.
 *
 * The comparison this replaces could bind the wrong customer:
 *
 *     str_ends_with($cPhone, $phone) || str_ends_with($phone, $cPhone)
 *
 * with a minimum length on the incoming number only. A client record holding
 * a short fragment matched any number ending in those digits, first match
 * won, and that customer's balance and payment history went to whoever sent
 * the message. These tests exist to keep that closed.
 */
$pass = 0; $fail = 0;
function is_(bool $c, string $m): void { global $pass, $fail;
    if ($c) { $pass++; echo "  ok   $m\n"; } else { $fail++; echo "  FAIL $m\n"; } }
function t(string $n, $got, $want) { global $pass, $fail;
    if ($got === $want) { $pass++; printf("  ok   %s\n", $n); }
    else { $fail++; printf("  FAIL %s\n       got  %s\n       want %s\n", $n, var_export($got, true), var_export($want, true)); } }

require_once dirname(__DIR__) . '/lib/CustomerIdentity.php';

/** A store that serves one fixed index, with no database behind it. */
final class IdxStore
{
    private array $rows;
    public function __construct(array $rows) { $this->rows = $rows; }
    public function load(string $f) { return $f === 'client_search_index.json' ? $this->rows : []; }
}
/** A store whose read throws, to prove that is not reported as "unknown". */
final class BrokenStore
{
    public function load(string $f) { throw new RuntimeException('disk'); }
}
/** uCRM that returns whatever it was given, to test the re-check. */
final class FakeCrm
{
    private array $rows;
    public array $asked = [];
    public function __construct(array $rows) { $this->rows = $rows; }
    public function get(string $path) { $this->asked[] = $path; return $this->rows; }
}

echo "\nA fragment is not a phone number\n";
// This is the whole bug. A stored "758123" must identify nobody.
t('six digits has no significant part',  CustomerIdentity::significant('758123'), null);
t('eight digits still does not',         CustomerIdentity::significant('75812345'), null);
t('nine digits does',                    CustomerIdentity::significant('758123456'), '758123456');
t('and a full international number',     CustomerIdentity::significant('+256 758 123 456'), '758123456');

echo "\nMatching is exact, never a suffix test\n";
is_(CustomerIdentity::same('+256758123456', '0758123456') === true,
    'the same subscriber written three ways matches');
is_(CustomerIdentity::same('256758123456', '758123456') === true, 'with or without the country code');
is_(CustomerIdentity::same('758123', '256758123456') === false,
    'a stored fragment matches NOTHING — the old code matched this');
is_(CustomerIdentity::same('256758123456', '123456') === false, 'in either direction');
is_(CustomerIdentity::same('3456', '0758123456') === false, 'however short the fragment');
is_(CustomerIdentity::same('0758123456', '0758123457') === false, 'one digit different is a different person');

echo "\nTwo countries sharing nine digits are two people\n";
is_(CustomerIdentity::same('+256758123456', '+211758123456') === false,
    'Uganda and South Sudan do not collide');
is_(CustomerIdentity::same('0758123456', '+256758123456') === true,
    'but a local number with a trunk zero is not treated as a country code');

echo "\nAn exact, unique match identifies\n";
$idx = new IdxStore([
    ['id' => 7,  'name' => 'African skies Ltd', 'phone' => '+256758123456'],
    ['id' => 11, 'name' => 'Someone Else',      'phone' => '+256700999888'],
]);
$r = (new CustomerIdentity($idx))->resolve('256758123456');
t('status',    $r['status'],    CustomerIdentity::IDENTIFIED);
t('client id', $r['client_id'], 7);
is_(($r['client']['name'] ?? '') === 'African skies Ltd', 'and the right customer');

echo "\nAn unknown number identifies nobody\n";
$r = (new CustomerIdentity($idx))->resolve('256777000111');
t('status',    $r['status'],    CustomerIdentity::UNKNOWN);
t('no client', $r['client_id'], 0);
t('and no record at all', $r['client'], null);

echo "\nA short STORED number cannot capture an arbitrary caller\n";
// The exact shape of the old bug: a client record holding a fragment.
$bad = new IdxStore([
    ['id' => 99, 'name' => 'Bad Record', 'phone' => '3456'],
    ['id' => 98, 'name' => 'Truncated',  'phone' => '123456'],
]);
$r = (new CustomerIdentity($bad))->resolve('256758123456');
t('nobody is identified', $r['status'], CustomerIdentity::UNKNOWN);
t('and no id leaks out',  $r['client_id'], 0);

echo "\nTwo customers on one number is ambiguous, not a guess\n";
$dupe = new IdxStore([
    ['id' => 7,  'name' => 'First',  'phone' => '+256758123456'],
    ['id' => 12, 'name' => 'Second', 'phone' => '0758123456'],
]);
$r = (new CustomerIdentity($dupe))->resolve('256758123456');
t('status',        $r['status'],     CustomerIdentity::AMBIGUOUS);
t('no client id',  $r['client_id'],  0);
t('no record',     $r['client'],     null);
t('both are named for a human', $r['candidates'], [7, 12]);
is_(strpos($r['reason'], 'human') !== false, 'and it says a person must resolve it');

echo "\nCustomer A cannot become Customer B\n";
// Every route in: a fragment, a shared suffix, a neighbouring digit.
$fleet = new IdxStore([
    ['id' => 7,  'name' => 'Customer A', 'phone' => '+256758123456'],
    ['id' => 21, 'name' => 'Customer B', 'phone' => '+256772654321'],
]);
foreach (['256772654321' => 21, '256758123456' => 7] as $num => $want) {
    $r = (new CustomerIdentity($fleet))->resolve((string)$num);
    t("$num resolves to $want and only $want", $r['client_id'], $want);
}
foreach (['654321', '4321', '256772654320', '25677265432'] as $near) {
    $r = (new CustomerIdentity($fleet))->resolve($near);
    is_($r['client_id'] !== 21, "\"$near\" does not become Customer B");
}

echo "\nA number too short to be a number is its own answer\n";
$r = (new CustomerIdentity($idx))->resolve('12345');
t('status', $r['status'], CustomerIdentity::UNUSABLE);
is_(strpos($r['reason'], 'identify anyone') !== false, 'and says why');

echo "\nAn unreadable index is not an unknown customer\n";
// Empty must never stand in for unknown: a failed read that reads as "no
// such customer" silently downgrades every caller to anonymous.
$r = (new CustomerIdentity(new BrokenStore()))->resolve('256758123456');
t('status',   $r['status'],   CustomerIdentity::UNKNOWN);
is_(strpos($r['reason'], 'could not be read') !== false, 'and names the read failure');

echo "\nuCRM results are a shortlist, not an answer\n";
// The API's own phone search is looser than ours. Anything it returns is
// re-checked here, so its matching can never widen ours.
$loose = new FakeCrm([
    ['id' => 55, 'firstName' => 'Loose', 'lastName' => 'Match', 'phone' => '999888777'],
]);
$r = (new CustomerIdentity(new IdxStore([]), $loose))->resolve('256758123456');
t('a uCRM row that does not actually match is discarded', $r['status'], CustomerIdentity::UNKNOWN);
is_($loose->asked !== [], 'even though uCRM was asked');

$right = new FakeCrm([
    ['id' => 61, 'firstName' => 'Right', 'lastName' => 'Match',
     'contacts' => [['phone' => '+256758123456']]],
]);
$r = (new CustomerIdentity(new IdxStore([]), $right))->resolve('256758123456');
t('a genuine contact match is accepted', $r['client_id'], 61);

echo "\nNo client id is ever accepted from outside\n";
// The server resolves the customer. Nothing here takes an id as input, so a
// model or a message cannot propose one.
$src = (string)file_get_contents(dirname(__DIR__) . '/lib/CustomerIdentity.php');
$code = '';
foreach (token_get_all($src) as $k) {
    if (is_array($k)) { if (in_array($k[0], [T_COMMENT, T_DOC_COMMENT], true)) continue; $code .= $k[1]; }
    else $code .= $k;
}
is_(preg_match('/function\s+resolve\s*\(\s*string\s+\$phone\s*\)/', $code) === 1,
    'resolve() takes a phone number and nothing else');
is_(preg_match('/function\s+\w+\s*\([^)]*\$(client_?id|customer_?id)\b/i', $code) === 0,
    'no method anywhere accepts a client id');

echo "\nEvery phone on a record is considered\n";
$multi = new IdxStore([
    ['id' => 8, 'name' => 'Two Numbers', 'phone' => '+256700111222',
     'contacts' => [['phone' => '+256758123456']]],
]);
t('a contact phone identifies as well as the main one',
  (new CustomerIdentity($multi))->resolve('256758123456')['client_id'], 8);
t('and so does the main one',
  (new CustomerIdentity($multi))->resolve('256700111222')['client_id'], 8);

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
