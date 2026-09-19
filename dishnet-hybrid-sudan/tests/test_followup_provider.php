<?php
/**
 * test_followup_provider.php — followup_run asks the provider the install uses.
 *
 * ── What this is about ──────────────────────────────────────────────────
 *
 * cron/followup_run.php read claude_api_key, and only claude_api_key, then
 * built a ClaudeWaClient with it. The Uganda install runs ai_provider=openai.
 * So the key was empty, the script hit its guard and returned, every single
 * run, for five days:
 *
 *     [followup_run] no API key — cannot evaluate      × 2300 log lines
 *     [master] DONE followup_run in 14ms               ← a real evaluation
 *                                                       takes seconds
 *
 * Meanwhile followup_scan kept working, because it is SQL only and needs no
 * key at all. 261 follow-ups opened. Not one draft was ever written. The
 * database said so independently: sales_stage was 'unknown' on all 264 rows,
 * and that column is written only after the evaluator returns.
 *
 * Every other provider-consuming site in the plugin branches on ai_provider.
 * This one did not, and being a cron script it had no seam a test could reach
 * — which is why nothing caught it. FollowUpEvaluator::clientFor() is that
 * seam now, and these assertions drive it rather than grepping the source.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$pass = 0; $fail = 0;
function ok(string $m): void  { global $pass; $pass++; echo "  ok   {$m}\n"; }
function bad(string $m, string $d=''): void { global $fail; $fail++; echo "  FAIL {$m}\n"; if($d)echo "       {$d}\n"; }
function is_(bool $c, string $m, string $d=''): void { $c ? ok($m) : bad($m, $d); }

require_once $root . '/lib/FollowUpPolicy.php';
require_once $root . '/lib/FollowUpEvaluator.php';

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

echo "\n1. ai_provider=openai selects the OpenAI client and the OpenAI key\n";
$r = FollowUpEvaluator::clientFor(
    ['ai_provider' => 'openai', 'openai_api_key' => 'sk-live', 'claude_api_key' => 'sk-ant-wrong'], $pdo);
is_($r['provider'] === 'openai', 'the provider is openai', $r['provider']);
is_($r['client'] instanceof GptWaClient, 'and the client is GptWaClient',
    $r['client'] === null ? 'null' : get_class($r['client']));
is_($r['error'] === '', 'with no error');
// The bug was not "wrong class" — it was reading the wrong KEY. A Claude key
// present alongside must not rescue an OpenAI install, or the failure hides.
is_(!($r['client'] instanceof ClaudeWaClient), 'never the Claude client, even with a Claude key set');

echo "\n2. ai_provider=claude selects the Claude client and the Claude key\n";
$r = FollowUpEvaluator::clientFor(
    ['ai_provider' => 'claude', 'claude_api_key' => 'sk-ant-live', 'openai_api_key' => 'sk-wrong'], $pdo);
is_($r['provider'] === 'claude', 'the provider is claude');
is_($r['client'] instanceof ClaudeWaClient, 'and the client is ClaudeWaClient',
    $r['client'] === null ? 'null' : get_class($r['client']));
is_($r['error'] === '', 'with no error');

echo "\n3. Absent ai_provider still means claude — South Sudan is unchanged\n";
$r = FollowUpEvaluator::clientFor(['claude_api_key' => 'sk-ant-live'], $pdo);
is_($r['provider'] === 'claude', 'the default is claude');
is_($r['client'] instanceof ClaudeWaClient, 'and it builds');

echo "\n4. The missing key names WHICH provider\n";
// 'no API key' told nobody which of two keys to go and set. On this install
// the answer was openai, and the message could not say so.
$r = FollowUpEvaluator::clientFor(['ai_provider' => 'openai'], $pdo);
is_($r['client'] === null, 'openai with no key builds nothing');
is_(strpos($r['error'], 'openai') !== false, 'and the error says openai', $r['error']);
$r = FollowUpEvaluator::clientFor(['ai_provider' => 'claude'], $pdo);
is_($r['client'] === null, 'claude with no key builds nothing');
is_(strpos($r['error'], 'claude') !== false, 'and the error says claude', $r['error']);

echo "\n   a key of only whitespace is no key\n";
$r = FollowUpEvaluator::clientFor(['ai_provider' => 'openai', 'openai_api_key' => "  \n "], $pdo);
is_($r['client'] === null, 'blank is not a key');

echo "\n5. Case does not decide the provider\n";
// DishNetAiBrain carries the same normalisation and the same comment: the
// uCRM Configuration screen stored 'OpenAI' after a re-save, and a strict
// compare silently fell back to claude with no key.
foreach (['openai', 'OpenAI', 'OPENAI', ' OpenAI '] as $spelling) {
    $r = FollowUpEvaluator::clientFor(
        ['ai_provider' => $spelling, 'openai_api_key' => 'sk-live'], $pdo);
    is_($r['client'] instanceof GptWaClient, "'{$spelling}' resolves to openai");
}

echo "\n6. anthropic_api_key is still accepted for claude\n";
$r = FollowUpEvaluator::clientFor(['ai_provider' => 'claude', 'anthropic_api_key' => 'sk-ant-live'], $pdo);
is_($r['client'] instanceof ClaudeWaClient, 'the legacy key name still works');

echo "\n7. Both clients satisfy what the evaluator actually calls\n";
// They are NOT identical: ClaudeWaClient::getReply takes a 7th $tools
// argument that GptWaClient does not have. They are interchangeable here
// only because evaluate() passes six. Pin that, so a seventh argument fails
// loudly rather than breaking whichever provider is not being tested today.
$src = (string)file_get_contents($root . '/lib/FollowUpEvaluator.php');
preg_match('/\$this->brain->getReply\((.*?)\);/s', $src, $m);
$args = isset($m[1]) ? substr_count($m[1], ',') + 1 : 0;
is_($args > 0 && $args <= 6, 'evaluate() passes at most 6 arguments to getReply', $args . ' arguments');
foreach (['GptWaClient', 'ClaudeWaClient'] as $cls) {
    $n = (new ReflectionMethod($cls, 'getReply'))->getNumberOfParameters();
    is_($n >= $args, "{$cls}::getReply accepts them", "takes {$n}, called with {$args}");
}

echo "\n8. The cron uses the seam — it does not pick a client itself\n";
$cron = (string)file_get_contents($root . '/cron/followup_run.php');
is_(strpos($cron, 'FollowUpEvaluator::clientFor(') !== false,
    'followup_run.php calls clientFor()');
is_(strpos($cron, 'new ClaudeWaClient(') === false,
    'and no longer hard-codes a client',
    'the hard-coded ClaudeWaClient is what this release removes');

echo "\n9. No OTHER file picks a WA client without asking ai_provider\n";
// followup_run was the only one. This keeps it that way.
$offenders = [];
foreach (['cron', 'workers', 'lib'] as $dir) {
    foreach (glob($root . '/' . $dir . '/*.php') ?: [] as $f) {
        $s = (string)file_get_contents($f);
        if (!preg_match('/new (Claude|Gpt)WaClient\s*\(/', $s)) continue;
        if (strpos($s, 'ai_provider') !== false) continue;   // it asks; fine
        $offenders[] = basename($f);
    }
}
is_($offenders === [], 'every construction site consults ai_provider',
    'not: ' . implode(', ', $offenders));

echo "\n10. Evaluation starting to work does not start SENDING\n";
// The whole safety posture of this feature: a draft is not a message. Fixing
// the evaluator must not quietly turn the engine into an autoresponder.
$send = (string)file_get_contents($root . '/cron/followup_send.php');
is_(strpos($send, "status = 'approved'") !== false || strpos($send, 'approvedDrafts(') !== false,
    'followup_send still sends only drafts a person approved');
is_(strpos($cron, 'sendText(') === false && strpos($cron, 'EvolutionApiService') === false,
    'and followup_run still has no way to reach Evolution at all');

echo "\n11. followup_run_limit is settable, so a backlog can be throttled\n";
// 257 open follow-ups at the default 5 per run is seven hours of drafting.
// The key was read but never registered, so it could not be turned down.
$cfg = (string)file_get_contents($root . '/tools/set_config.php');
is_(strpos($cfg, "'followup_run_limit'") !== false,
    'set_config.php registers followup_run_limit');
is_(strpos($cron, "followup_run_limit") !== false, 'and the cron still reads it');

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
