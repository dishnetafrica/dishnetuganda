<?php
declare(strict_types=1);
/**
 * test_shadow_runtime.php — the B3.4 runtime audit.
 *
 * test_shadow_compare.php proves the comparison in isolation. This proves the
 * WIRING: a real AiReplyWorker, the real CrmApiClient, the real gateway, the
 * real tools, the real logger, against a uCRM that records every request.
 *
 * Three things are being established, and none of them is "the comparison is
 * correct" — that is the other file's job:
 *
 *   1. No customer value can reach the log. Every value on both sides of the
 *      comparison is a canary; the captured log is searched for all of them.
 *   2. The customer's reply is untouched. The context that becomes the prompt
 *      is captured with the flag off and with it on, and compared byte for
 *      byte under a live divergence.
 *   3. The reads are exactly what was intended. Off: none. On: the named few,
 *      every one scoped to the authenticated customer, once each.
 */
$pass = 0; $fail = 0;
function is_(bool $c, string $m): void { global $pass, $fail;
    if ($c) { $pass++; echo "  ok   $m\n"; } else { $fail++; echo "  FAIL $m\n"; } }
function t(string $n, $got, $want) { global $pass, $fail;
    if ($got === $want) { $pass++; printf("  ok   %s\n", $n); }
    else { $fail++; printf("  FAIL %s\n       got  %s\n       want %s\n", $n, var_export($got, true), var_export($want, true)); } }

$root = dirname(__DIR__);

// ── Boot the recording uCRM ─────────────────────────────────────────────
$router = $root . '/tests/fixtures/fake_ucrm_shadow.php';
$hit = function (int $port, string $p) {
    $ch = curl_init("http://127.0.0.1:{$port}{$p}");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 3, CURLOPT_PROXY => '']);
    $r = curl_exec($ch); curl_close($ch);
    return $r === false ? null : (string)$r;
};
$srv = null; $port = 0;
foreach (range(0, 9) as $slot) {
    $cand = 9740 + ((getmypid() + $slot * 17) % 60);
    @unlink(sys_get_temp_dir() . '/fake_ucrm_shadow_' . $cand . '.json');
    $p = proc_open(sprintf('exec php -S 127.0.0.1:%d %s', $cand, escapeshellarg($router)),
                   [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
    $ours = false;
    for ($i = 0; $i < 40; $i++) {
        $got = $hit($cand, '/__test/state');
        if ($got !== null) { $ours = strpos($got, 'FAKE-UCRM-SHADOW') !== false; break; }
        usleep(100000);
    }
    if ($ours) { $srv = $p; $port = $cand; break; }
    proc_terminate($p); proc_close($p);
}
if ($port === 0) { fwrite(STDERR, "could not start the fake uCRM\n"); exit(1); }

$reset    = function () use ($hit, $port) { $hit($port, '/__test/reset'); };
$requests = function () use ($hit, $port): array {
    $d = json_decode((string)$hit($port, '/__test/requests'), true) ?: [];
    return $d['requests'] ?? [];
};
// Only the reads, in a form a human can check at a glance.
$paths = function (array $reqs): array {
    return array_map(fn($u) => urldecode((string)$u), $reqs);
};

// ── A worker, a data directory, and one customer in the index ───────────
require_once $root . '/lib/StoreInterface.php';
require_once $root . '/lib/SqliteStore.php';
require_once $root . '/lib/EventBus.php';
require_once $root . '/lib/ConversationService.php';
require_once $root . '/lib/PluginConfig.php';
require_once $root . '/workers/WorkerBase.php';
require_once $root . '/workers/AiReplyWorker.php';

$tmp = sys_get_temp_dir() . '/shadow_runtime_' . getmypid();
exec('rm -rf ' . escapeshellarg($tmp));
@mkdir($tmp, 0777, true);
putenv('DN_DATA_DIR=' . $tmp);
$store = SqliteStore::create($tmp);

$OURS   = '256701998877';
$OTHER  = '256705111222';

// client_search_index is a real table with real columns, so the blob store's
// save() silently skips it — seed it the way the sync cron does. (The first
// version of this test used save(), found nobody, and every shadow assertion
// below went quiet rather than failing. Hence the identification check that
// now guards the whole file.)
$seedIndex = function (array $rows) use ($store): void {
    $pdo = $store->getPdo();
    $pdo->exec('DELETE FROM client_search_index');
    $st = $pdo->prepare('INSERT INTO client_search_index (id, name, phone, phone_norm)
                         VALUES (:id, :name, :phone, :norm)');
    foreach ($rows as $r) {
        $st->execute([':id' => $r['id'], ':name' => $r['name'], ':phone' => $r['phone'],
                      ':norm' => preg_replace('/[^0-9]/', '', $r['phone'])]);
    }
};
$seedIndex([
    ['id' => 7,  'name' => 'Ours Customer',   'phone' => '+' . $OURS],
    ['id' => 21, 'name' => 'ZOTHERCUSTOMERZ', 'phone' => '+256705000111'],
]);

$baseConfig = [
    'crm_base_url'    => "http://127.0.0.1:{$port}",
    'crm_auth_token'  => 'SHADOWKEY',
    'claude_api_key'  => 'test-key-never-called',
    'evo_api_url'     => 'http://127.0.0.1:1',   // never called on this path
    'evo_api_key'     => 'unused',
];

$mk = function (array $extra = []) use ($store, $baseConfig): AiReplyWorker {
    return new AiReplyWorker($store, array_merge($baseConfig, $extra));
};

$convSvc = new ConversationService($tmp, $store->getPdo());
$convId  = (int)($convSvc->ensureConversation($OURS, 'account')['id'] ?? 0);
is_($convId > 0, 'a conversation exists to run the turn against');

/** Run buildContext() for real, capturing both the context and the log. */
$build = function (AiReplyWorker $w, string $channel, string $phone, int $cid): array {
    $m = new ReflectionMethod(AiReplyWorker::class, 'buildContext');
    $m->setAccessible(true);
    ob_start();
    $ctx = $m->invoke($w, $channel, $phone, 'what do I owe?', [], $cid);
    return ['ctx' => $ctx, 'log' => (string)ob_get_clean()];
};

// Every distinctive value the fake uCRM serves, plus every one the hostile
// legacy context below carries. None may appear in a log line.
$CANARIES = [
    '987654.32', 'ZCURRZ', 'ZSVCNAMEZ', 'ZPLANNAMEZ', '777333.99', '2033-03-03',
    'INV-ZUNPAIDZ', '222111', '2030-01-01', '2030-02-01',
    'INV-ZLATESTZ', '555111.22', '2031-07-04', '2031-06-01',
    '333222.11', '2029-02-14', 'ZPAYMETHODZ',
    'ZOTHERCUSTOMERZ', 'ZOTHERPLANZ', '8675309', '2035-05-05',
    'INV-ZLEGACYZ', '444555.66', '2028-12-25', '111222.33', 'ZLEGACYPLANZ',
    '2032-02-02', $OURS, $OTHER, '256705000111',
];
$leaks = function (string $log) use ($CANARIES): array {
    $found = [];
    foreach ($CANARIES as $c) if (strpos($log, $c) !== false) $found[] = $c;
    return $found;
};
/** Just the message part of each log line, prefix stripped. */
$messages = function (string $log): array {
    $out = [];
    foreach (explode("\n", trim($log)) as $line) {
        if ($line === '') continue;
        $out[] = preg_replace('/^\[[^\]]*\] \[[^\]]*\] \[[^\]]*\] /', '', $line);
    }
    return $out;
};
$shadowLines = function (array $msgs): array {
    return array_values(array_filter($msgs, fn($m) => strpos($m, 'shadow') !== false));
};

// ════════════════════════════════════════════════════════════════════════
echo "\n1. OFF is genuinely off, and costs nothing\n";
// ════════════════════════════════════════════════════════════════════════

$fresh = PluginConfig::load($root, $tmp);
is_(!array_key_exists('ai_shadow_compare', $fresh) || empty($fresh['ai_shadow_compare']),
    'a config with nothing set has no shadow flag');
is_(empty([]['ai_shadow_compare'] ?? null), 'and an absent key is falsey to the guard');

$reset();
$off = $build($mk(), 'account', $OURS, $convId);
$offReads = $paths($requests());
// The guard the first version of this file lacked: if the customer is not
// identified, the shadow never runs and everything below passes for the wrong
// reason.
is_(($off['ctx']['customer']['id'] ?? 0) === 7,
    'the legacy path identified client 7 — without this nothing below means anything');
t('the flag off logs no shadow line', $shadowLines($messages($off['log'])), []);

$reset();
$on = $build($mk(['ai_shadow_compare' => true]), 'account', $OURS, $convId);
$onReads = $paths($requests());

// A multiset difference, not array_diff: the shadow re-reads /clients/7 and
// /payments?clientId=7, which the legacy path also reads, and a set
// difference would report those as free.
$minus = function (array $after, array $before): array {
    $extra = []; $pool = $before;
    foreach ($after as $u) {
        $k = array_search($u, $pool, true);
        if ($k === false) $extra[] = $u; else unset($pool[$k]);
    }
    return $extra;
};
$extra = $minus($onReads, $offReads);
echo "     legacy reads (" . count($offReads) . "): " . implode(' | ', $offReads) . "\n";
echo "     with shadow  (" . count($onReads) . "): " . implode(' | ', $onReads) . "\n";
echo "     added by the shadow: " . implode(' | ', $extra) . "\n";

// ════════════════════════════════════════════════════════════════════════
echo "\n2. The customer's context is untouched by a live divergence\n";
// ════════════════════════════════════════════════════════════════════════

t('the context is byte-identical with the shadow on', $on['ctx'], $off['ctx']);
is_(json_encode($on['ctx']) === json_encode($off['ctx']),
    'and identical when serialised, so no key order or type moved either');
is_(isset($on['ctx']['account']['invoice']),
    'the legacy account block is still the one the prompt will render');

// An identical context is only most of the argument. Close it: build the
// actual system prompt from each and compare. If the two prompts are the same
// string, the model is asked the same question and the customer gets the same
// answer — no provider call needed to know that.
require_once $root . '/lib/DishNetAiBrain.php';
$brain = new DishNetAiBrain(['ai_provider' => 'openai', 'openai_api_key' => 'k']);
$pOff = $brain->promptPreview($off['ctx']);
$pOn  = $brain->promptPreview($on['ctx']);
t('the system prompt is byte-identical too', $pOn, $pOff);
is_(strlen($pOn) > 500, 'and it is a real prompt, not an empty string agreeing with itself');
t('the prompt carries no shadow verdict', strpos($pOn, 'shadow'), false);

// ════════════════════════════════════════════════════════════════════════
echo "\n3. The shadow line carries verdicts, and only verdicts\n";
// ════════════════════════════════════════════════════════════════════════

$lines = $shadowLines($messages($on['log']));
t('exactly one shadow line per turn', count($lines), 1);
echo "     -> " . ($lines[0] ?? '(none)') . "\n";
t('no canary in the whole captured log', $leaks($on['log']), []);

// The strongest form of "it carries no values": the line is exactly this.
t('the line is exactly the expected verdicts',
  $lines[0] ?? '',
  "conv {$convId}: shadow account balance=same invoice=differ:number+amount+due_date payment=same");

// ════════════════════════════════════════════════════════════════════════
echo "\n4. Every added read is scoped to the authenticated customer\n";
// ════════════════════════════════════════════════════════════════════════

foreach ($onReads as $u) {
    is_(strpos($u, '/clients/21') === false && strpos($u, 'clientId=21') === false,
        'no read touches another customer: ' . $u);
}
foreach ($extra as $u) {
    is_(preg_match('#(/clients/7($|/)|clientId=7($|&))#', $u) === 1,
        'the added read names client 7: ' . $u);
}
sort($extra);
t('accounts adds exactly the three intended reads, once each', $extra,
  ['/clients/7', '/invoices?clientId=7&limit=5', '/payments?clientId=7&limit=1']);
t('four legacy reads become seven', [count($offReads), count($onReads)], [4, 7]);

// ════════════════════════════════════════════════════════════════════════
echo "\n5. Support adds service reads and no money reads\n";
// ════════════════════════════════════════════════════════════════════════

$supConv = (int)($convSvc->ensureConversation($OURS, 'support')['id'] ?? 0);
$reset(); $soff = $build($mk(), 'support', $OURS, $supConv);
$soffReads = $paths($requests());
$reset(); $son  = $build($mk(['ai_shadow_compare' => true]), 'support', $OURS, $supConv);
$sonReads = $paths($requests());
$sExtra = $minus($sonReads, $soffReads);
echo "     added by the shadow: " . implode(' | ', $sExtra) . "\n";
t('support adds exactly two reads', count($sExtra), 2);
t('both are this customer\'s services', array_values(array_unique($sExtra)), ['/clients/7/services']);
is_($sExtra === ['/clients/7/services', '/clients/7/services'],
    'one per tool — get_my_plan and get_my_service_status each fetch');
t('the support context is untouched', $son['ctx'], $soff['ctx']);
t('and so is the support prompt, byte for byte',
  $brain->promptPreview($son['ctx']), $brain->promptPreview($soff['ctx']));
t('no canary in the support log', $leaks($son['log']), []);

$sLines = $shadowLines($messages($son['log']));
echo "     -> " . ($sLines[0] ?? '(none)') . "\n";
t('the support line reports the unmapped uCRM status code',
  $sLines[0] ?? '',
  "conv {$supConv}: shadow support plan=differ:name service_status=differ:unmapped_status");

// ════════════════════════════════════════════════════════════════════════
echo "\n6. Only an identified customer is shadowed at all\n";
// ════════════════════════════════════════════════════════════════════════

$unkConv = (int)($convSvc->ensureConversation($OTHER, 'account')['id'] ?? 0);
$reset();
$unk = $build($mk(['ai_shadow_compare' => true]), 'account', $OTHER, $unkConv);
$unkReads = $paths($requests());
t('an unknown number logs no shadow line', $shadowLines($messages($unk['log'])), []);
is_(array_filter($unkReads, fn($u) => strpos($u, '/invoices') === 0) === [],
    'and reads no invoice');
is_(array_filter($unkReads, fn($u) => strpos($u, '/payments') === 0) === [],
    'and no payment');

// Two customers on one number: ambiguous, and ambiguous is not identified.
$seedIndex([
    ['id' => 7,  'name' => 'Ours Customer',   'phone' => '+' . $OURS],
    ['id' => 21, 'name' => 'ZOTHERCUSTOMERZ', 'phone' => '+' . $OURS],
]);
$ambConv = (int)($convSvc->ensureConversation('256709444333', 'account')['id'] ?? 0);
$reset();
$amb = $build($mk(['ai_shadow_compare' => true]), 'account', $OURS, $ambConv);
$ambReads = $paths($requests());
t('an ambiguous number logs no shadow line', $shadowLines($messages($amb['log'])), []);
is_(array_filter($ambReads, fn($u) => strpos($u, '/invoices') === 0) === [],
    'and reads no invoice for either candidate');
t('no canary in the ambiguous log', $leaks($amb['log']), []);
$seedIndex([
    ['id' => 7,  'name' => 'Ours Customer',   'phone' => '+' . $OURS],
    ['id' => 21, 'name' => 'ZOTHERCUSTOMERZ', 'phone' => '+256705000111'],
]);

// ════════════════════════════════════════════════════════════════════════
echo "\n7. HOSTILE: both readers stuffed with values, maximum divergence\n";
// ════════════════════════════════════════════════════════════════════════

// The legacy side is hand-built to disagree with the fake uCRM about
// everything at once — the balance's SIGN, the invoice, the payment — and
// every field on both sides is a canary.
$hostile = [
    'channel'         => 'account',
    'customer_phone'  => $OURS,
    'conversation_id' => $convId,
    'customer'        => ['id' => 7, 'name' => 'ZOTHERCUSTOMERZ'],
    'account' => [
        'client_id' => 7, 'name' => 'ZOTHERCUSTOMERZ',
        'balance'   => -444555.66,
        'invoice'   => ['number' => 'INV-ZLEGACYZ', 'amount_due' => 222111.0,
                        'due_date' => '2030-01-01'],
        'last_payment' => ['amount' => 111222.33, 'date' => '2028-12-25'],
    ],
    'services' => [['name' => 'ZLEGACYPLANZ', 'status' => 1, 'active_to' => '2032-02-02']],
];

$m = new ReflectionMethod(AiReplyWorker::class, 'shadowCompare');
$m->setAccessible(true);
$reset();
ob_start();
$m->invoke($mk(['ai_shadow_compare' => true]), 'account', $convId, 7, true, $hostile);
$hostileLog = (string)ob_get_clean();
$hostileReads = $paths($requests());

echo "     -> " . implode(' / ', $messages($hostileLog)) . "\n";
t('not one canary survives into the log', $leaks($hostileLog), []);
t('and the line is the verdicts alone',
  $messages($hostileLog),
  ["conv {$convId}: shadow account balance=differ:sign invoice=differ:number+amount+due_date payment=differ:amount+date"]);
is_(strpos($hostileLog, '{') === false && strpos($hostileLog, '[{') === false,
    'nothing was serialised into it');
is_(preg_match('/Array|stdClass|Object/', $hostileLog) !== 1,
    'and no array or object was stringified into it');
foreach ($hostileReads as $u) {
    is_(strpos($u, '21') === false, 'the hostile turn still read only client 7: ' . $u);
}

// The same turn, with the flag off: no line, no reads, nothing.
$reset();
ob_start();
$m->invoke($mk(), 'account', $convId, 7, true, $hostile);
$offLog = (string)ob_get_clean();
t('with the flag off the hostile turn logs nothing', trim($offLog), '');
t('and makes no uCRM read at all', $requests(), []);

// An unidentified caller cannot be shadowed even when the caller says so.
$reset();
ob_start();
$m->invoke($mk(['ai_shadow_compare' => true]), 'account', $convId, 7, false, $hostile);
$notIdLog = (string)ob_get_clean();
t('an unidentified turn logs nothing', trim($notIdLog), '');
t('and makes no uCRM read', $requests(), []);

// A client id of zero is the same answer.
$reset();
ob_start();
$m->invoke($mk(['ai_shadow_compare' => true]), 'account', $convId, 0, true, $hostile);
$zeroLog = (string)ob_get_clean();
t('a turn with no client id logs nothing', trim($zeroLog), '');
t('and makes no uCRM read', $requests(), []);

// ════════════════════════════════════════════════════════════════════════
echo "\n8. A broken shadow cannot break the reply\n";
// ════════════════════════════════════════════════════════════════════════

$reset();
$brokenCrm = $mk(['ai_shadow_compare' => true, 'crm_base_url' => 'http://127.0.0.1:1',
                  'crm_auth_token' => 'x']);
ob_start();
$m->invoke($brokenCrm, 'account', $convId, 7, true, $hostile);
$brokeLog = (string)ob_get_clean();
is_(true, 'an unreachable uCRM did not throw out of the shadow');
t('and leaked nothing while failing', $leaks($brokeLog), []);

// With no CRM configured at all it says so and stops.
$reset();
ob_start();
$m->invoke(new AiReplyWorker($store, ['ai_shadow_compare' => true, 'claude_api_key' => 'k']),
           'account', $convId, 7, true, $hostile);
$noCrmLog = (string)ob_get_clean();
is_(strpos($noCrmLog, 'CRM not configured') !== false, 'an unconfigured CRM is named, not guessed at');
t('and nothing leaks on that path either', $leaks($noCrmLog), []);

// A channel with no customer data in its prompt is not shadowed.
$reset();
ob_start();
$m->invoke($mk(['ai_shadow_compare' => true]), 'sales', $convId, 7, true, $hostile);
$salesLog = (string)ob_get_clean();
t('sales logs no shadow line', trim($salesLog), '');
t('and makes no uCRM read', $requests(), []);

// ════════════════════════════════════════════════════════════════════════
echo "\n9. The shadow cannot reach anything the reply depends on\n";
// ════════════════════════════════════════════════════════════════════════

// Comments stripped first. An earlier version of this searched the raw text
// for a loop keyword and tripped on the word "for" inside a sentence — the
// invariant has to read the code, not the prose explaining it.
$codeNC = function (string $f): string {
    $o = '';
    foreach (token_get_all((string)file_get_contents($f)) as $k) {
        if (is_array($k)) { if (in_array($k[0], [T_COMMENT, T_DOC_COMMENT], true)) continue; $o .= $k[1]; }
        else $o .= $k;
    }
    return $o;
};
$src = $codeNC($root . '/workers/AiReplyWorker.php');
preg_match('/private function shadowCompare.*?\n    \}/s', $src, $mm);
$body = $mm[0] ?? '';
is_($body !== '', 'the method body was found to inspect');
foreach (['$this->evo', '$this->brain', '$this->convSvc', '$this->tools',
          'sendText', 'sendImage', 'sendMedia', 'linkToCrm', 'beginTurn',
          'ReplyPrivacyGuard', 'BrainContext', 'markIdentityAmbiguous'] as $forbidden) {
    is_(strpos($body, $forbidden) === false,
        "the shadow never touches $forbidden");
}
is_(substr_count($body, '$tools') > 0 && strpos($body, '$this->tools =') === false,
    'its tools instance is a local, never stored on the worker');
is_(preg_match('/\bfor\b|\bwhile\b|\bforeach\b|\bgoto\b/', $body) !== 1,
    'there is no loop in it, so no path can multiply the reads');
is_(strpos($body, 'get_class($e)') !== false, 'a failure logs the exception class, not its message');

proc_terminate($srv); proc_close($srv);
exec('rm -rf ' . escapeshellarg($tmp));
putenv('DN_DATA_DIR');
printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
