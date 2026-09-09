<?php
/**
 * test_kit_register.php — which kit went to which customer, and when.
 *
 * The question that has to survive years: a support call names a customer and
 * needs the serial; a warranty claim names the serial and needs the customer;
 * an audit needs both, for a date in the past. A register that only holds the
 * present answers the first two and fails the third, which is the one that
 * matters when somebody disputes it.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/lib/KitRegister.php';

$pass = 0; $fail = 0;
function ok(string $m): void  { global $pass; $pass++; echo "  ok   {$m}\n"; }
function bad(string $m, string $d=''): void { global $fail; $fail++; echo "  FAIL {$m}\n"; if($d)echo "       {$d}\n"; }
function is_(bool $c, string $m, string $d=''): void { $c ? ok($m) : bad($m, $d); }

function reg(): KitRegister
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    return new KitRegister($pdo);
}

echo "\nOne kit, one spelling\n";
// The same serial arrives from an email, a spreadsheet, and someone reading a
// label on a roof. Three spellings would be three kits and three answers.
foreach ([['KIT-0123-4567', 'KIT01234567'], ['kit 0123 4567', 'KIT01234567'],
          [' KIT_0123.4567 ', 'KIT01234567'], ['UT01234567', 'UT01234567']] as [$in, $want]) {
    is_(KitRegister::normaliseKitId($in) === $want,
        '"' . $in . '" reads as ' . $want, 'got: ' . KitRegister::normaliseKitId($in));
}

echo "\nStarlink's mail records the kit, never the customer\n";
$r = reg();
$kit = $r->noteFromSupplier(['kit' => 'KIT-0123-4567', 'order_reference' => 'SO-99'],
                            'ORDER_SHIPPED');
is_($kit === 'KIT01234567', 'the kit is recorded from the email');
$row = $r->find('KIT01234567');
is_((string)$row['status'] === KitRegister::SHIPPED, 'with the status their email implies');
is_((int)$row['crm_client_id'] === 0,
    'and no customer — their mail says a kit exists, not who ended up holding it');
is_((string)$row['order_reference'] === 'SO-99', 'the order reference is kept');

echo "\nAn email that names no kit records nothing\n";
is_($r->noteFromSupplier(['order_reference' => 'SO-100'], 'ORDER_CONFIRMED') === '',
    'there is nothing to file against');

echo "\nA person hands it over, and that is the assignment\n";
$a = $r->assign('kit 0123 4567', 4021, 'bhavin', 'installed in Tororo');
is_(!empty($a['ok']), 'the assignment succeeds even though the spelling differs');
is_($a['moved_from'] === 0, 'and it came from nobody');
$row = $r->find('KIT01234567');
is_((int)$row['crm_client_id'] === 4021, 'the customer now holds it');
is_((string)$row['status'] === KitRegister::ASSIGNED, 'and it reads as assigned');
is_(count($r->forClient(4021)) === 1, 'asking by customer finds it');

echo "\nA late shipping notice does not take it back off them\n";
$r->noteFromSupplier(['kit' => 'KIT01234567', 'tracking_number' => 'TRK-1'], 'ORDER_SHIPPED');
$row = $r->find('KIT01234567');
is_((int)$row['crm_client_id'] === 4021, 'the customer still holds it');
is_((string)$row['status'] === KitRegister::ASSIGNED,
    'and the status is not walked backwards by mail arriving out of order');
is_((string)$row['tracking_number'] === 'TRK-1',
    'while the new detail is still absorbed');

echo "\nBut activation is Starlink telling us something we do not know\n";
$r->noteFromSupplier(['kit' => 'KIT01234567'], 'ACTIVATION');
is_((string)$r->find('KIT01234567')['status'] === KitRegister::ACTIVE,
    'so the service going live does move it forward');

echo "\nA kit that moves shows both customers, not just the last one\n";
$b = $r->assign('KIT01234567', 5000, 'rupesh');
is_($b['moved_from'] === 4021, 'the move reports where it came from');
is_((int)$r->find('KIT01234567')['crm_client_id'] === 5000, 'the present says the new customer');
is_(count($r->forClient(4021)) === 0, 'the old customer no longer holds it');

$hist = $r->history('KIT01234567');
$clients = array_map(function ($h) { return (int)$h['crm_client_id']; }, $hist);
is_(in_array(4021, $clients, true) && in_array(5000, $clients, true),
    'but the history holds both — "who had this in March" stays answerable',
    json_encode($clients));
is_(count(array_filter($hist, function ($h) {
        return strpos((string)$h['what'], 'reassigned from client 4021') !== false;
    })) === 1, 'and the move itself is recorded, with who it left');

echo "\nA kit we were never told about is still recorded\n";
$r2 = reg();
$r2->assign('UT99887766', 7, 'installer');
is_($r2->find('UT99887766') !== null,
    'the installer holding it is better evidence than our inbox');

echo "\nReturned hardware leaves the customer but not the record\n";
$r->markReturned('KIT01234567', 'bhavin', 'cancelled account');
$row = $r->find('KIT01234567');
is_((int)$row['crm_client_id'] === 0, 'nobody holds it now');
is_((string)$row['status'] === KitRegister::RETURNED, 'and it reads as returned');
is_(count($r->unassigned()) === 1, 'so it shows as available again');
is_(count(array_filter($r->history('KIT01234567'), function ($h) {
        return strpos((string)$h['what'], 'returned from client 5000') !== false;
    })) === 1, 'while the history still says who returned it');

echo "\nNothing is assigned to nobody\n";
$bad1 = $r->assign('', 4021, 'x');
$bad2 = $r->assign('KIT01234567', 0, 'x');
is_(empty($bad1['ok']) && empty($bad2['ok']),
    'a missing kit or a missing customer is refused, not half-recorded');

echo "\nThe supplier pipeline records kits but assigns nobody\n";
// The classifier matches a MESSAGE to a client. That is not the same as
// knowing whose hands the kit ended up in, and treating it as the same would
// put a serial against a customer on the strength of a delivery address.
$workerSrc = (string)file_get_contents($root . '/workers/StarlinkMailWorker.php');
is_(strpos($workerSrc, 'noteFromSupplier') !== false,
    'supplier mail feeds the register');
is_(strpos($workerSrc, '$kits->assign(') === false,
    'and never assigns a customer from an email');
is_(strpos($workerSrc, 'catch (\\Throwable') !== false,
    'a failure there cannot cost the alert and timeline entry that matter more');

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
