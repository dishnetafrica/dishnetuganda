<?php
/**
 * test_crm_audit_tool.php — the audit must not touch what it audits.
 *
 * This tool runs before the AI is allowed anywhere near lead creation, and its
 * whole value is that it reports the state of production honestly. A
 * diagnostic that writes is not a diagnostic, so row counts and file contents
 * are compared before and after.
 *
 * The findings it must not miss are the ones nobody would notice by eye: quote
 * branding still defaulting to the South Sudan number compiled into
 * QuotationService, and no active sales agent to assign a lead to — which
 * would mean the AI dutifully creating leads that reach nobody.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$pass = 0; $fail = 0;
function ok(string $m): void  { global $pass; $pass++; echo "  ok   {$m}\n"; }
function bad(string $m, string $d=''): void { global $fail; $fail++; echo "  FAIL {$m}\n"; if($d)echo "       {$d}\n"; }
function is_(bool $c, string $m, string $d=''): void { $c ? ok($m) : bad($m, $d); }

$tmp = sys_get_temp_dir() . '/dn_crmaudit_' . bin2hex(random_bytes(4));
@mkdir($tmp, 0777, true);

require_once $root . '/lib/StoreInterface.php';
require_once $root . '/lib/JsonStore.php';
require_once $root . '/lib/SqliteStore.php';
require_once $root . '/lib/ConversationService.php';

$store = SqliteStore::create($tmp);
$pdo   = $store->getPdo();

$store->save('leads.json', [
    ['id' => 1, 'phone' => '256772000111', 'status' => 'open',      'customer_name' => 'A'],
    ['id' => 2, 'phone' => '256772000222', 'status' => 'converted', 'customer_name' => 'B'],
    // the same handset written two ways — the dedupe rule matches on last 9
    ['id' => 3, 'phone' => '+256 772 000 111', 'status' => 'open',  'customer_name' => 'A again'],
    ['id' => 4, 'phone' => '',                 'status' => 'open',  'customer_name' => 'No phone'],
]);
$store->save('web_chat_leads.json', [['session' => 's1', 'created_at' => '2026-09-01 10:00:00']]);

$svc = new ConversationService($tmp, $pdo);
$c1 = $svc->ensureConversation('256772000111', 'sales', null, 'test');
$svc->ensureConversation('256772000999', 'sales', null, 'test');
$pdo->exec("UPDATE wa_conversations SET lead_id = 1 WHERE id = " . (int)$c1['id']);

$run = function () use ($root, $tmp): array {
    $cmd = 'DN_DATA_DIR=' . escapeshellarg($tmp) . ' php '
         . escapeshellarg($root . '/tools/crm_audit.php') . ' 2>&1';
    exec($cmd, $out, $code);
    return [$code, implode("\n", $out)];
};
$fingerprint = function () use ($pdo, $tmp): string {
    $c = (int)$pdo->query("SELECT COUNT(*) FROM wa_conversations")->fetchColumn();
    $m = (int)$pdo->query("SELECT COUNT(*) FROM wa_messages")->fetchColumn();
    return $c . '|' . $m . '|' . md5((string)@file_get_contents($tmp . '/leads.json'));
};

$before = $fingerprint();
[$code, $out] = $run();

echo "\nIt reports the branding a customer would actually see\n";
is_(strpos($out, '+211920000000') !== false,
    'the South Sudan default is shown when nothing overrides it',
    'this is compiled into QuotationService and is invisible until printed');
is_(strpos($out, 'NOT SET') !== false, 'and is marked as unset, not as a choice');

echo "\nIt notices there is nobody to assign a lead to\n";
// The failure that would be silent: the AI creating leads no human is told of.
is_(strpos($out, 'none') !== false, 'no active sales agent is reported');
is_(strpos($out, 'assigned to nobody') !== false, 'with what that means spelled out');

echo "\nIt counts the lead store honestly\n";
is_(preg_match('/rows\s+4/', $out) === 1, 'all four rows counted', $out);
is_(preg_match('/without a phone\s+1/', $out) === 1, 'the row with no phone is separated');
is_(preg_match('/duplicate phones\s+1/', $out) === 1,
    'and the same handset written two ways counts as one duplicate',
    'matching must use the last 9 digits, as the live dedupe does');
is_(strpos($out, 'open') !== false && strpos($out, 'converted') !== false,
    'statuses are broken down');

echo "\nIt names the other stores rather than pretending there is one\n";
is_(strpos($out, 'web_chat_leads.json') !== false, 'the website store is counted');
is_(strpos($out, 'keyed by session') !== false, 'and why it cannot be joined');
is_(strpos($out, 'isLead') !== false, 'uCRM\'s own lead notion is named as the third');

echo "\nIt shows how much of the chain is already wired\n";
is_(preg_match('/linked\s+1 of 2/', $out) === 1,
    'conversations already carrying a lead_id', $out);

echo "\nIt exits non-zero when something would block Phase 1\n";
is_($code !== 0, 'a blocking finding is an exit code, not just text',
    'so it can gate a build step later');
is_(strpos($out, 'BEFORE LETTING THE AI CREATE LEADS') !== false, 'and the blockers are listed');

echo "\nAnd it changed nothing\n";
is_($fingerprint() === $before, 'conversations, messages and leads.json are byte-identical',
    'an audit that writes is not an audit');

exec('rm -rf ' . escapeshellarg($tmp));
printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
