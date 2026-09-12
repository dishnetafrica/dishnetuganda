<?php
declare(strict_types=1);
/**
 * test_contact_optout.php — the thing that has to work before the system can
 * ever start a conversation.
 *
 * Everything the assistant has sent until now was a REPLY: invited by the
 * message it answered, so there was nothing to opt out of. Follow-ups break
 * that invariant on the first send. This is the brake, and a brake nobody has
 * tested is a decoration.
 *
 * The distinction the file exists to protect: "stop messaging me" does not
 * mean "ignore me if I write to you". A customer who opts out and then asks a
 * question still gets an answer — refusing would be worse service than before
 * any of this existed.
 */
require_once dirname(__DIR__) . '/lib/StoreInterface.php';
require_once dirname(__DIR__) . '/lib/JsonStore.php';
require_once dirname(__DIR__) . '/lib/SqliteStore.php';
require_once dirname(__DIR__) . '/lib/MigrationRunner.php';
require_once dirname(__DIR__) . '/lib/ContactOptOut.php';

$pass = 0; $fail = 0;
function t(string $n, $got, $want) { global $pass, $fail;
    if ($got === $want) { $pass++; printf("  ok   %s\n", $n); }
    else { $fail++; printf("  FAIL %s\n       got  %s\n       want %s\n", $n, var_export($got, true), var_export($want, true)); } }
function is_(bool $c, string $m, string $d = ''): void { global $pass, $fail;
    if ($c) { $pass++; echo "  ok   $m\n"; } else { $fail++; echo "  FAIL $m" . ($d ? "\n       $d" : '') . "\n"; } }

$base = sys_get_temp_dir() . '/dn_optout_' . bin2hex(random_bytes(4));
@mkdir($base . '/data', 0777, true);
$store = SqliteStore::create($base . '/data');
$pdo   = $store->getPdo();
foreach (MigrationRunner::splitStatements((string)file_get_contents(
        dirname(__DIR__) . '/migrations/069_contact_optouts.sql')) as $q) $pdo->exec($q);

$oo = ContactOptOut::fromStore($store);
$C  = 'ContactOptOut';

echo "Nobody has opted out yet\n";
t('a proactive message is allowed', $oo->blocks('256700111222', 'sales', $C::CLASS_PROACTIVE)['blocked'], false);
t('and so is a reply',              $oo->blocks('256700111222', 'sales', $C::CLASS_REPLY)['blocked'], false);

echo "\nA plain STOP blocks what we start, not what we answer\n";
$r = $oo->add('256700111222', ['scope' => $C::SCOPE_PROACTIVE, 'evidence' => 'STOP']);
t('it is recorded',                 $r['created'], true);
t('a follow-up is now blocked',     $oo->blocks('256700111222', 'sales', $C::CLASS_PROACTIVE)['blocked'], true);
// The point of the scope. Refusing to answer someone who writes to us would
// look like the assistant is broken, and is worse service than no opt-out.
t('but a reply still goes',         $oo->blocks('256700111222', 'sales', $C::CLASS_REPLY)['blocked'], false);
t('and their invoice still goes',   $oo->blocks('256700111222', 'sales', $C::CLASS_TRANSACTIONAL)['blocked'], false);
is_(strpos($oo->blocks('256700111222','sales',$C::CLASS_PROACTIVE)['reason'], 'opted out') !== false,
    'and the refusal says why');

echo "\nScope 'all' stops everything\n";
$oo->add('256700333444', ['scope' => $C::SCOPE_ALL, 'reason' => 'not_interested']);
t('proactive blocked',              $oo->blocks('256700333444', 'sales', $C::CLASS_PROACTIVE)['blocked'], true);
t('reply blocked too',              $oo->blocks('256700333444', 'sales', $C::CLASS_REPLY)['blocked'], true);
t('and transactional',              $oo->blocks('256700333444', 'sales', $C::CLASS_TRANSACTIONAL)['blocked'], true);

echo "\nOur own team is never a customer\n";
// A staff alert ABOUT a customer must not be suppressed by that customer's
// preference, and a staff number that somehow acquired an opt-out must still
// receive alerts.
t('staff alerts are never blocked', $oo->blocks('256700333444', 'sales', $C::CLASS_STAFF)['blocked'], false);

echo "\nThe number matches however it was typed\n";
t('spaces and plus signs',          $oo->blocks('+256 700 111 222', 'sales', $C::CLASS_PROACTIVE)['blocked'], true);
t('dashes',                         $oo->blocks('256-700-111-222', 'sales', $C::CLASS_PROACTIVE)['blocked'], true);

echo "\nA repeated STOP is not an error\n";
// The unique index would refuse a second live row. A customer saying STOP
// twice must not produce a failure.
$again = $oo->add('256700111222', ['scope' => $C::SCOPE_PROACTIVE]);
t('it succeeds',                    $again['ok'], true);
t('without creating a duplicate',   $again['created'], false);
t('there is still one live row',
    (int)$pdo->query("SELECT COUNT(*) FROM contact_optouts WHERE phone='256700111222' AND active=1")->fetchColumn(), 1);

echo "\nChannel scope\n";
$oo2 = ContactOptOut::fromStore($store);
$oo2->add('256700555666', ['channel' => 'sales', 'scope' => $C::SCOPE_PROACTIVE]);
t('blocked on that channel',        $oo2->blocks('256700555666', 'sales', $C::CLASS_PROACTIVE)['blocked'], true);
t('but not on another',             $oo2->blocks('256700555666', 'support', $C::CLASS_PROACTIVE)['blocked'], false);

echo "\nLifting is a new fact, not an erasure\n";
$oo3 = ContactOptOut::fromStore($store);
t('one row is lifted',              $oo3->lift('256700111222', '*', 'staff:test')['lifted'], 1);
t('and sending is allowed again',   $oo3->blocks('256700111222', 'sales', $C::CLASS_PROACTIVE)['blocked'], false);
// What they originally asked for is still on the record.
t('the original request survives',
    (int)$pdo->query("SELECT COUNT(*) FROM contact_optouts WHERE phone='256700111222'")->fetchColumn(), 1);
t('marked inactive, not deleted',
    (int)$pdo->query("SELECT active FROM contact_optouts WHERE phone='256700111222'")->fetchColumn(), 0);

echo "\nAn opt-out is never deleted\n";
$threw = false;
try { $pdo->exec("DELETE FROM contact_optouts WHERE phone='256700333444'"); }
catch (\Throwable $e) { $threw = true; }
is_($threw, 'the database refuses a DELETE');
t('the row is still there',
    (int)$pdo->query("SELECT COUNT(*) FROM contact_optouts WHERE phone='256700333444'")->fetchColumn(), 1);

echo "\nReading a STOP out of a message\n";
foreach (['STOP', 'stop', 'Stop.', 'unsubscribe', 'please remove me',
          'STOP!', 'opt out', 'do not contact me', 'no more messages'] as $msg) {
    is_(ContactOptOut::detect($msg)['stop'], "'{$msg}' is a stop");
}

echo "\nAnd NOT reading one where there is none\n";
// "stop" is an ordinary word in a support conversation. A false opt-out
// silently cuts a paying customer off from us, which is far worse than a
// missed one — staff and the AI classifier both catch the misses.
foreach ([
    'my internet stopped working this morning',
    'can you stop the service for a week while I travel',
    'the router light stopped blinking, is that normal?',
    'I want to stop my subscription at the end of the month please advise',
    'it stopped raining so the signal came back',
] as $msg) {
    is_(!ContactOptOut::detect($msg)['stop'], "not a stop: '{$msg}'");
}
t('an empty message is not a stop', ContactOptOut::detect('')['stop'], false);
t('nor is whitespace',              ContactOptOut::detect("  \n ")['stop'], false);

echo "\nA missing table is 'cannot tell', not 'nobody opted out'\n";
// The schema not having run is a different fact from a clean list, and the
// difference is logged rather than silently read as permission to send.
$bare = new \PDO('sqlite::memory:');
$bare->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
$naked = new ContactOptOut($bare);
t('find returns nothing',           $naked->find('256700111222'), null);
t('and does not throw',             $naked->blocks('256700111222', 'sales', $C::CLASS_PROACTIVE)['blocked'], false);

echo "\nThe gate on the real send path\n";
// The library being right is not the point — the SEND has to refuse. This
// exercises EvolutionApiService itself, with no network configured: a blocked
// send must come back refused BEFORE anything is dialled, and an allowed one
// must get far enough to complain about the missing instance. Those two
// different failures are how we know the gate ran and did not just error.
require_once dirname(__DIR__) . '/lib/EvolutionApiService.php';
$evo = new EvolutionApiService(['evo_api_url' => 'http://127.0.0.1:9/never',
                                'evo_api_key' => 'k', 'evo_instance_sales' => 'inst_sales']);
$evo->setOptOut(ContactOptOut::fromStore($store));

$blocked = $evo->sendText('sales', '256700333444', 'hello', $C::CLASS_PROACTIVE);
t('a proactive send to an opted-out number is refused', !empty($blocked['suppressed']), true);
t('and says so',                    (bool)preg_match('/opted out/', (string)($blocked['error'] ?? '')), true);
is_((int)($blocked['optout_id'] ?? 0) > 0, 'naming the opt-out that stopped it');

// scope 'all' on this number, so even a reply must be refused.
$blockedReply = $evo->sendText('sales', '256700333444', 'hi', $C::CLASS_REPLY);
t('and a reply too, at scope all',  !empty($blockedReply['suppressed']), true);

// A number with no opt-out must NOT be suppressed — it should fail for a
// network reason instead. If this came back 'suppressed' the gate would be
// blocking everything, which a test asserting only "it failed" would miss.
$allowed = $evo->sendText('sales', '256700999888', 'hello', $C::CLASS_PROACTIVE);
t('a clean number is not suppressed', !empty($allowed['suppressed']), false);

// And the classification that matters most day to day: a customer on a plain
// STOP still gets answered when they write in.
$replyOk = $evo->sendText('sales', '256700555666', 'answering you', $C::CLASS_REPLY);
t('a reply to a proactive opt-out is not suppressed', !empty($replyOk['suppressed']), false);

echo "\nA send site that forgets to classify itself\n";
// The default is the most restricted class on purpose. Forgetting to label a
// marketing blast should cost us a send, not cost a customer their choice.
$unclassified = $evo->sendText('sales', '256700333444', 'unlabelled');
t('is treated as proactive and blocked', !empty($unclassified['suppressed']), true);

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
