<?php
declare(strict_types=1);
/**
 * test_staff_whatsapp.php — 5.18.50 (docs/44 J8 and M1, release A): on Uganda a colleague's WhatsApp is kept for the
 * team — never answered by the AI, never read as an opt-out, never followed up.
 *
 * Measured on the live box before this release: 109 AI replies had been queued for staff numbers, and the follow-up
 * scan's own backlog note names "a thread that turned out to be two colleagues testing the assistant". Proved here:
 *   1. the webhook (T8.1, T8.2, T8.4): an active staff account's number — stored in any form J3 accepts — is stored,
 *      filed 'staff', not queued for the AI and not opted out, even when it writes "STOP"; a customer's message is
 *      handled as before; a deactivated account's number and a dealer's are customers; a +211 staff number is not
 *      confused with a +256 customer who shares its last nine digits;
 *   2. the AI worker (T8.5): an ai.reply queued for a staff number — before the deploy, or by any other route — is
 *      dropped without asking the model or sending anything, while a customer's is answered;
 *   3. follow-ups (M1): the scan opens none for a colleague, filed or not; the run closes one opened before the deploy;
 *      the sender closes an approved one instead of sending it; a customer's follow-up is untouched throughout;
 *   4. isFollowable() answers as before for every caller that does not ask for the exclusion;
 *   5. South Sudan: the same staff number is still queued, opted out on "STOP" and followed up, as in 5.18.49;
 *   6. weakened copies of the code each fail this test.
 *
 * No model is ever called: the worker's brain is a fake in this process, and every follow-up is given a pending draft
 * before the run cron, so that no path — in this code or in a weakened copy — reaches an evaluation.
 *
 *   php test_staff_whatsapp.php [--root=DIR] [--no-mutants]
 */
$opt  = getopt('', ['root:', 'no-mutants']);
$root = isset($opt['root']) ? rtrim((string)$opt['root'], '/') : dirname(__DIR__);
$withMutants = !isset($opt['no-mutants']);
require_once __DIR__ . '/fixtures/staff_jobs_sandbox.php';

$pass = 0; $fail = 0;
function is_(bool $c, string $m, string $d = ''): void {
    global $pass, $fail;
    if ($c) { $pass++; echo "  ok   {$m}\n"; }
    else    { $fail++; echo "  FAIL {$m}" . ($d !== '' ? "\n       {$d}" : '') . "\n"; }
}

const TECH   = '256700000111';   // support, stored "+256700000111"
const ACCT   = '256700000113';   // accountant, stored in national form "0700000113" (J3: international on use)
const GONE   = '256700000114';   // a support account, deactivated
const DEALER = '256700000116';   // a retailer (sales): not DishNet staff (docs/44 §15.9)
const JUBA   = '211700000117';   // a support account with a South Sudan number
const LOOKAL = '256700000117';   // a Uganda customer whose last nine digits are JUBA's
const NOC    = '256700000118';   // support; its conversation predates 5.18.50, so it was never filed 'staff'
const LEFT   = '256700000119';   // support: writes, is filed 'staff', then leaves (the account is deactivated)
const CUST   = '256700000916';   // a customer
const STOPPER = '256700000915';  // a customer who writes STOP

$staff = function (SjSandbox $s): void {
    $s->staff('tech',   ['name' => 'Sandbox Tech', 'email' => 'tech@example.test', 'role' => 'support', 'phone' => '+256700000111']);
    $s->staff('acct',   ['name' => 'Sandbox Accountant', 'email' => 'acct@example.test', 'role' => 'accountant', 'phone' => '0700000113']);
    $s->staff('gone',   ['name' => 'Sandbox Gone', 'email' => 'gone@example.test', 'role' => 'support', 'phone' => '+256700000114', 'is_active' => false]);
    $s->staff('dealer', ['name' => 'Sandbox Dealer', 'email' => 'dealer@example.test', 'role' => 'sales', 'phone' => '+256700000116']);
    $s->staff('juba',   ['name' => 'Sandbox Juba', 'email' => 'juba@example.test', 'role' => 'support', 'phone' => '+211 700 000 117']);
    $s->staff('noc',    ['name' => 'Sandbox NOC', 'email' => 'noc@example.test', 'role' => 'support', 'phone' => '+256700000118']);
};
$conv = function (SjSandbox $s, string $phone): array {
    $r = $s->q('SELECT * FROM wa_conversations WHERE phone = ?', [$phone]);
    return $r[0] ?? [];
};
$msgs    = function (SjSandbox $s, string $phone): int { return count($s->q('SELECT m.id FROM wa_messages m JOIN wa_conversations c ON c.id = m.conversation_id WHERE c.phone = ?', [$phone])); };
$queued  = function (SjSandbox $s, string $phone): int { return count($s->q("SELECT id FROM events WHERE event_type = 'ai.reply' AND payload LIKE ?", ['%"customer_phone":"' . $phone . '"%'])); };
$optouts = function (SjSandbox $s, string $phone): int { try { return count($s->q('SELECT id FROM contact_optouts WHERE phone = ? AND active = 1', [$phone])); } catch (\Throwable $e) { return 0; } };
$fuOpen  = function (SjSandbox $s, string $phone): array { return $s->q('SELECT * FROM followups WHERE phone = ? AND closed_at IS NULL', [$phone]); };
$fuAll   = function (SjSandbox $s, string $phone): array { return $s->q('SELECT * FROM followups WHERE phone = ? ORDER BY id', [$phone]); };
$texts   = function (SjSandbox $s, string $phone): array {
    return array_values(array_filter($s->texts(), function ($t) use ($phone) { return (string)$t['number'] === $phone; }));
};
/** Every conversation looks quiet for 30 hours: past the first follow-up's 24, inside the two-week window. */
$quiet = function (SjSandbox $s): void { $s->q("UPDATE wa_conversations SET last_customer_at = datetime('now', '-30 hours')"); };
$fuCfg = ['followup_enabled' => '1', 'claude_api_key' => 'sj-never-called', 'followup_run_limit' => 50, 'followup_scan_limit' => 50];

// ═════════════════════════════════════════════════════════════════════════════
$s = SjSandbox::start($root, ['tenant_profile' => 'uganda'] + $fuCfg, 'sjstaffwa');
$staff($s);
$s->staff('left', ['name' => 'Sandbox Leaver', 'email' => 'left@example.test', 'role' => 'support', 'phone' => '+256700000119']);

echo "\n1. The webhook: a colleague's number is kept for the team\n";
$r = $s->evoInbound(TECH, 'STOP');
is_($r[0] === 200 && (int)($r[2]['skipped'] ?? -1) === 1 && (int)($r[2]['queued'] ?? -1) === 0, 'a staff number writes "STOP": accepted, skipped, nothing queued', $r[1]);
is_($msgs($s, TECH) === 1, 'the message is stored', (string)$msgs($s, TECH));
is_(($conv($s, TECH)['category'] ?? null) === 'staff', 'the conversation is filed "staff"', json_encode($conv($s, TECH)['category'] ?? null));
is_($queued($s, TECH) === 0, 'no AI reply is queued');
is_($optouts($s, TECH) === 0, 'and "STOP" from a colleague is not an opt-out');
$s->evoInbound(ACCT, 'The invoice for job 950 is ready');
is_(($conv($s, ACCT)['category'] ?? null) === 'staff' && $queued($s, ACCT) === 0 && $msgs($s, ACCT) === 1,
    'a number stored in national form ("0700000113") is recognised too — the international form on use (J3)');
$s->evoInbound(JUBA, 'Checking in from Juba');
$s->evoInbound(LEFT, 'Handing over my jobs today');
is_(($conv($s, JUBA)['category'] ?? null) === 'staff' && $queued($s, JUBA) === 0, 'a staff account with a +211 number is recognised by its whole number');

echo "\n   Customers are handled as before (T8.2)\n";
$r = $s->evoInbound(STOPPER, 'STOP');
is_((int)($r[2]['queued'] ?? -1) === 1 && $optouts($s, STOPPER) === 1, 'a customer\'s "STOP" is still an opt-out, as in 5.18.49', $r[1]);
$s->evoInbound(CUST, 'Hello, how much is Starlink?');
is_($queued($s, CUST) === 1 && ($conv($s, CUST)['category'] ?? null) !== 'staff' && $optouts($s, CUST) === 0, 'a customer\'s question is queued for the AI');
$s->evoInbound(GONE, 'Hello, is my install booked?');
is_($queued($s, GONE) === 1 && ($conv($s, GONE)['category'] ?? null) !== 'staff', 'a DEACTIVATED staff account\'s number is a customer again');
$s->evoInbound(DEALER, 'Hello, I want to order two kits');
is_($queued($s, DEALER) === 1 && ($conv($s, DEALER)['category'] ?? null) !== 'staff', 'a dealer\'s number (sales) is a customer, not staff');
$s->evoInbound(LOOKAL, 'Hello from Kampala');
is_($queued($s, LOOKAL) === 1 && ($conv($s, LOOKAL)['category'] ?? null) !== 'staff',
    'a +256 customer whose last nine digits are a +211 colleague\'s is a customer (T8.4)');

// ── 2. The AI worker ─────────────────────────────────────────────────────────
echo "\n2. The AI worker: a queued reply to a colleague is dropped (T8.5)\n";
foreach (['bootstrap_data', 'StoreInterface', 'JsonStore', 'SqliteStore', 'ConversationService', 'EventBus', 'UtcClock', 'AlertService',
          'EvoWebhookGuard', 'DishNetAiBrain'] as $lib) require_once "{$root}/lib/{$lib}.php";
require_once "{$root}/workers/WorkerBase.php";
require_once "{$root}/workers/AiReplyWorker.php";
/** A brain that never leaves the process, and remembers what it was asked. */
final class SjFakeBrain extends DishNetAiBrain
{
    public $asked = [];
    public function isConfigured(): bool { return true; }
    public function reply(array $context): array { $this->asked[] = json_encode($context, JSON_UNESCAPED_UNICODE); return ['reply' => 'Thank you — a sandbox answer.']; }
    public function getLastUsage(): array { return []; }
}
$store = $s->store();
$bus   = new EventBus($store->getPdo());
$techConv = (int)($conv($s, TECH)['id'] ?? 0);
$bus->emit('ai.reply', 'conversation', $techConv, ['channel' => 'support', 'whatsapp_instance' => 'sj-support', 'customer_phone' => TECH,
    'message' => 'SJ-COLLEAGUE-QUEUED-BEFORE-THE-DEPLOY', 'push_name' => 'Sandbox Tech', 'wa_message_id' => 'SJ-OLD-1',
    'conversation_id' => $techConv, 'received_at' => gmdate('c', time() - 5)]);
$w = new AiReplyWorker($store, $s->cfg + ['ai_provider' => 'openai', 'openai_api_key' => 'sj-never-called'], 30, 20);
$brain = new SjFakeBrain($s->cfg);
$rp = new ReflectionProperty(AiReplyWorker::class, 'brain'); $rp->setAccessible(true); $rp->setValue($w, $brain);
ob_start(); $run = $w->run(); ob_end_clean();   // the worker's own log lines
$asked = implode("\n", $brain->asked);
is_(strpos($asked, 'SJ-COLLEAGUE-QUEUED-BEFORE-THE-DEPLOY') === false, 'the model is never asked about the colleague\'s message', substr($asked, 0, 200));
is_($texts($s, TECH) === [], 'nothing is sent to the colleague', json_encode($texts($s, TECH)));
$ev = $s->q("SELECT status FROM events WHERE event_type = 'ai.reply' AND payload LIKE '%SJ-COLLEAGUE-QUEUED%'");
is_(($ev[0]['status'] ?? '') === 'done', 'and the event is done, not left to retry', json_encode($ev));
is_(strpos($asked, 'how much is Starlink') !== false && count($texts($s, CUST)) === 1, 'control: the customer\'s question is answered and sent',
    json_encode($run) . ' ' . count($texts($s, CUST)));
is_(strpos($asked, 'is my install booked') !== false && count($texts($s, GONE)) === 1, 'and so is the deactivated account\'s');

// ── 3. Follow-ups ────────────────────────────────────────────────────────────
echo "\n3. Follow-ups: never opened, drafted or sent for a colleague (M1)\n";
require_once "{$root}/lib/ContactOptOut.php";
require_once "{$root}/lib/FollowUpPolicy.php";
require_once "{$root}/lib/FollowUpService.php";
$pdo  = $store->getPdo();
$cs   = new ConversationService($s->data, $pdo);
$fu   = new FollowUpService($pdo);
// A colleague's conversation from before 5.18.50: never filed 'staff'.
$nocConv = $cs->ensureConversation(NOC, 'support', 'Sandbox NOC', 'test');
$cs->storeMessage((int)$nocConv['id'], ['direction' => 'in', 'role' => 'customer', 'body' => 'Router at site 4 is back up', 'wa_message_id' => 'SJ-NOC-1']);
// A colleague who has since left: filed 'staff' while a colleague, the account deactivated afterwards.
$s->update($s->ids['left'], ['is_active' => false]);
$quiet($s);
[$rc, $out] = $s->run('cron/followup_scan.php');
foreach ([[TECH, 'filed "staff" by the webhook'], [ACCT, 'filed "staff", stored in national form'], [JUBA, 'filed "staff", +211'],
          [NOC, 'NOT filed "staff" — found by its number']] as [$ph, $label]) {
    is_($fuAll($s, $ph) === [], "the scan opens nothing for a colleague: {$label}", json_encode($fuAll($s, $ph)));
}
is_(($conv($s, LEFT)['category'] ?? null) === 'staff' && $fuAll($s, LEFT) === [],
    'nor for a conversation filed "staff" whose account has since been deactivated — filed "staff" is enough by itself',
    json_encode([$conv($s, LEFT)['category'] ?? null, $fuAll($s, LEFT)]));
$why = $s->q("SELECT detail FROM followup_events WHERE event = 'skipped' AND conversation_id = ?", [(int)$nocConv['id']]);
is_(in_array("a colleague's number", array_column($why, 'detail'), true), 'and it says why: "a colleague\'s number"', json_encode($why));
foreach ([[CUST, 'a customer'], [GONE, 'the deactivated account'], [DEALER, 'the dealer'], [LOOKAL, 'the +256 customer']] as [$ph, $label]) {
    is_(count($fuOpen($s, $ph)) === 1, "control: the scan opens one for {$label}", $rc . ' ' . $out);
}
is_($fuAll($s, STOPPER) === [], 'and none for the customer who opted out, as before');

// Follow-ups a colleague could already have, from before the deploy: opened directly, as 5.18.49's scan would have.
$pre = $fu->open($cs->getConversation((int)$conv($s, TECH)['id']) ?? []);
is_(!empty($pre['ok']) && !empty($pre['created']), 'set up: a follow-up opened for the technician before the deploy', json_encode($pre));
// Every open follow-up gets a pending draft, so the run cron evaluates nothing, in this code or a weakened copy.
foreach ($s->q('SELECT id FROM followups WHERE closed_at IS NULL') as $row) {
    $fu->draft((int)$row['id'], ['verdict' => 'SEND', 'message' => 'Hello again from the sandbox.', 'reason' => 'sandbox draft']);
}
$drafts0 = (int)$s->q('SELECT COUNT(*) n FROM followup_drafts')[0]['n'];
[$rc, $out] = $s->run('cron/followup_run.php');
$t = $fuAll($s, TECH);
is_(count($t) === 1 && $t[0]['closed_at'] !== null && $t[0]['close_reason'] === 'staff', 'the run closes the colleague\'s follow-up: "staff"', json_encode($t) . ' ' . $out);
$why = $s->q("SELECT detail FROM followup_events WHERE followup_id = ? AND event = 'closed'", [(int)$pre['id']]);
is_(in_array("staff — a colleague's number", array_column($why, 'detail'), true), 'and says why', json_encode($why));
is_(count($fuOpen($s, CUST)) === 1 && (int)$s->q('SELECT COUNT(*) n FROM followup_drafts')[0]['n'] === $drafts0,
    'control: the customer\'s follow-up stays open, and no model was asked for a draft');

// An approved follow-up to a colleague, as one could have been approved before the deploy.
$acctConv = $cs->getConversation((int)$conv($s, ACCT)['id']) ?? [];
$o = $fu->open($acctConv);
$d = $fu->draft((int)$o['id'], ['verdict' => 'SEND', 'message' => 'SJ-FOLLOWUP-TO-A-COLLEAGUE', 'reason' => 'sandbox draft']);
$fu->approve((int)$d['id'], 'sandbox-admin');
$custDraft = $s->q("SELECT d.id FROM followup_drafts d JOIN followups f ON f.id = d.followup_id WHERE f.phone = ? AND d.status = 'pending'", [CUST]);
$fu->approve((int)($custDraft[0]['id'] ?? 0), 'sandbox-admin');
[$rc, $out] = $s->run('cron/followup_send.php');
$a = $fuAll($s, ACCT);
is_(count($a) === 1 && $a[0]['close_reason'] === 'staff' && $texts($s, ACCT) === [], 'the sender closes the colleague\'s approved follow-up and sends nothing', json_encode($a) . ' ' . $out);
$c = $fuAll($s, CUST);
is_(count($c) === 1 && $c[0]['close_reason'] !== 'staff', 'control: the customer\'s approved follow-up is sent, or held for the sending window — never closed as "staff"', json_encode($c));

echo "\n4. isFollowable() answers as before unless asked\n";
$now = gmdate('Y-m-d H:i:s');
$row = ['category' => 'staff', 'last_customer_at' => gmdate('Y-m-d H:i:s', time() - 30 * 3600), 'status' => 'active', 'state' => 'new'];
$x = FollowUpPolicy::isFollowable($row, $now, 336.0, true);
is_($x === ['ok' => false, 'reason' => "a colleague's conversation"], 'asked to exclude colleagues: "a colleague\'s conversation"', json_encode($x));
is_(FollowUpPolicy::isFollowable($row, $now, 336.0) === ['ok' => true, 'reason' => ''], 'not asked — every other caller, and South Sudan: followable, as in 5.18.49');
$s->stop();

// ── 5. South Sudan ───────────────────────────────────────────────────────────
echo "\n5. South Sudan: the same staff number is handled as in 5.18.49\n";
$ss = SjSandbox::start($root, $fuCfg, 'sjstaffwa-ss');
$staff($ss);
$ss->evoInbound(TECH, 'STOP');
is_($queued($ss, TECH) === 1 && ($conv($ss, TECH)['category'] ?? null) !== 'staff', 'the staff number is queued for the AI and not filed "staff"');
is_($optouts($ss, TECH) === 1, 'and its "STOP" is recorded as an opt-out');
$ss->evoInbound(NOC, 'Router at site 4 is back up');
is_($queued($ss, NOC) === 1 && ($conv($ss, NOC)['category'] ?? null) !== 'staff', 'another staff number is queued too');
$quiet($ss);
$ss->run('cron/followup_scan.php');
is_(count($fuOpen($ss, NOC)) === 1, 'and the follow-up scan opens one for it', json_encode($fuAll($ss, NOC)));
$ss->stop();

// ── 6. Weakened copies ───────────────────────────────────────────────────────
if ($withMutants) {
    echo "\n6. Weakened copies of the code must each fail this test\n";
    $MUTANTS = [
        ['evo_webhook.php', "if (\$_evoJ8 !== false && StaffDirectory::activeStaffByPhone(", "if (false && StaffDirectory::activeStaffByPhone(",
         'the webhook answering colleagues'],
        ['lib/StaffDirectory.php', "if (\$p !== null && (string)preg_replace('/\\D+/', '', \$p) === \$want) return \$r;",
         "if (\$p !== null && substr((string)preg_replace('/\\D+/', '', \$p), -9) === substr(\$want, -9)) return \$r;", 'numbers matched on their last nine digits'],
        ['lib/StaffDirectory.php', "if (!is_array(\$r) || !self::isActive(\$r) || !self::isStaff(\$r)) continue;", "if (!is_array(\$r) || !self::isStaff(\$r)) continue;",
         'a deactivated account still counted as staff'],
        ['lib/StaffDirectory.php', "public const STAFF_ROLES = ['admin', 'accountant', 'field_accountant', 'support', 'support_leader', 'support_engineer'];",
         "public const STAFF_ROLES = ['admin', 'accountant', 'field_accountant', 'support', 'support_leader', 'support_engineer', 'sales'];", 'dealers counted as staff'],
        ['workers/AiReplyWorker.php', "if (\$this->isStaffNumber(\$phone)) {", "if (false) {", 'the worker answering a queued colleague'],
        ['cron/followup_scan.php', "\$f = FollowUpPolicy::isFollowable(\$conv, \$now, \$maxAge, \$colleagues !== null);", "\$f = FollowUpPolicy::isFollowable(\$conv, \$now, \$maxAge);",
         'the scan following up a conversation filed "staff"'],
        ['cron/followup_scan.php', "    if (\$colleagues !== null && \$colleagues->isColleague((string)\$conv['phone'])) {", "    if (false) {",
         'the scan following up a colleague\'s older conversation'],
        ['cron/followup_run.php', "    if (\$colleagues !== null\n        && (\$colleagues->isColleague((string)\$fu['phone']) || \$colleagues->isColleagueConversation(\$conv))) {",
         "    if (false) {", 'the run keeping a colleague\'s follow-up open'],
        ['cron/followup_send.php', "    if (\$colleagues !== null && (\$colleagues->isColleague(\$phone) || \$colleagues->isColleagueConversation(\$conv))) {",
         "    if (false) {", 'the sender sending to a colleague'],
        ['lib/ColleagueNumbers.php', "            if (!StaffJobsGate::applies(\$config, \$dataDir)) return null;\n", '',
         'colleagues excluded on South Sudan too'],
    ];
    foreach ($MUTANTS as [$rel, $old, $new, $label]) {
        [$tmp, $n] = sj_weakened_copy($root, $rel, $old, $new);
        if ($n !== 1) { is_(false, "weakened copy \"{$label}\": its anchor occurs once in {$rel}", "found {$n} times"); exec('rm -rf ' . escapeshellarg($tmp)); continue; }
        $out = [];
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --root=' . escapeshellarg($tmp) . ' --no-mutants 2>&1', $out, $rc);
        $fails = array_values(array_filter($out, function ($l) { return strpos($l, '  FAIL ') === 0; }));
        is_($rc !== 0 && $fails !== [], "caught: {$label}", 'exit ' . $rc . ', ' . count($fails) . ' failure(s)');
        if ($fails) echo '         first: ' . trim(substr($fails[0], 7, 110)) . "\n";
        exec('rm -rf ' . escapeshellarg($tmp));
    }
}

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
