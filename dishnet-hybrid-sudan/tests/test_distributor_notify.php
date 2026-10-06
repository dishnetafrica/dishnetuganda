<?php
declare(strict_types=1);
/**
 * test_distributor_notify.php — 5.18.64: distributor notifications, pilot core
 * (WS-A P3, docs/49 §7). Driven against a real temp SQLite via DistributorNotifier
 * and DistributorEvents.
 *
 *   A migration 080 tables + the dedup floor + no phone-ownership
 *   B contacts: add (unverified) / verify / recipientFor (0, 1, >1 fail-safe)
 *   C consent: a distributor mutes their OWN alerts (CLASS_DISTRIBUTOR)
 *   D the three events -> a DRAFT; dedup once; suppressed when muted
 *   E PRIVACY: a foreign value is blocked (allow-list control + a weakened copy)
 *   F draft -> approve: Null channel queues (no send); a live channel sends; reject
 *   G DistributorEvents: the Uganda+flag gate; owner resolved from 079; a strict no-op off
 *   H the UNIQUE dedup index is the floor under "exactly once"
 *   I wiring + gating + no uCRM, no live transport bound in the pilot
 *   J the port: Null bound by default; the Evolution adapter exists but is never bound
 *   K manifest version
 */
$root = dirname(__DIR__);
require_once $root . '/lib/bootstrap_data.php';
require_once $root . '/lib/StoreInterface.php';
require_once $root . '/lib/SqliteStore.php';
require_once $root . '/lib/DistributorRegistry.php';
require_once $root . '/lib/DistributorAttribution.php';
require_once $root . '/lib/DistributorNotifier.php';
require_once $root . '/lib/DistributorEvents.php';
require_once $root . '/lib/StaffJobsGate.php';

$pass = 0; $fail = 0;
function is_(bool $c, string $m, string $d = ''): void { global $pass, $fail;
    if ($c) { $pass++; echo "  ok   $m\n"; } else { $fail++; echo "  FAIL $m" . ($d !== '' ? "\n       $d" : '') . "\n"; } }
function nc(string $f): string { $o=''; foreach (token_get_all((string)file_get_contents($f)) as $k){ if(is_array($k)){ if(in_array($k[0],[T_COMMENT,T_DOC_COMMENT],true)) continue; $o.=$k[1]; } else $o.=$k; } return $o; }
function threw(callable $fn, string &$msg = null): bool { try { $fn(); return false; } catch (\Throwable $e) { $msg = $e->getMessage(); return true; } }

/** A fake LIVE channel: records sends, never touches a network. Proves the approve->send path. */
class FakeLiveChannel implements WhatsAppChannel {
    public array $sent = [];
    public function send(string $phone, string $text): array { $this->sent[] = [$phone, $text]; return ['sent' => true, 'detail' => 'fake-sent']; }
    public function name(): string { return 'fake-live'; }
    public function isLive(): bool { return true; }
}

$tmp = sys_get_temp_dir() . '/dnotify_' . bin2hex(random_bytes(4));
@mkdir($tmp, 0777, true);
$store = SqliteStore::create($tmp);
$pdo = $store->getPdo();
$reg = DistributorRegistry::fromStore($store);
$att = DistributorAttribution::fromStore($store);
$notifier = DistributorNotifier::fromStore($store);   // NullWhatsAppChannel by default

$A = $reg->create(['legal_name' => 'Alpha Distributors'], 'adm');
$B = $reg->create(['legal_name' => 'Beta Distributors'], 'adm');

echo "A. migration 080 — tables, the dedup floor, no phone-ownership\n";
foreach (['dist_contacts', 'dist_notify_consent', 'dist_notify_log'] as $t) {
    is_($pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='$t'")->fetchColumn() === $t, "$t table created");
}
is_($pdo->query("SELECT name FROM sqlite_master WHERE type='index' AND name='idx_dist_notify_dedup'")->fetchColumn() === 'idx_dist_notify_dedup', 'the dedup UNIQUE index exists');
$logCols = array_column($pdo->query("PRAGMA table_info(dist_notify_log)")->fetchAll(\PDO::FETCH_ASSOC), 'name');
is_(in_array('entity_id', $logCols, true) && !in_array('phone', $logCols, true), 'dist_notify_log is keyed by entity_id, carries no owning phone (to_phone is a destination, not an owner key)');
is_(in_array('to_phone', $logCols, true) && in_array('dedup_key', $logCols, true) && in_array('status', $logCols, true), 'log columns present (to_phone, dedup_key, status)');

echo "\nB. contacts — add (unverified), verify, recipient resolves one or none\n";
is_($notifier->recipientFor((int)$A['id']) === null, 'with no number, there is no recipient');
$c1 = $notifier->addContact((int)$A['id'], '+256 700 111 222', 'owner', 'adm');
is_(($c1['verified'] ?? null) === false, 'a new number is UNVERIFIED');
is_($notifier->recipientFor((int)$A['id']) === null, 'an unverified number is not a recipient');
is_(threw(fn() => $notifier->addContact((int)$A['id'], '0700111222', 'owner', 'adm')) === false, 'control: a second (different-spelling) number can be added'); // normalises differently, allowed
$notifier->verifyContact((int)$c1['id'], 'adm');
$rc = $notifier->recipientFor((int)$A['id']);
is_($rc && $rc['phone'] === '+256700111222', 'after verifying, exactly one verified number resolves');
// verify the 2nd too -> now two verified -> ambiguous -> null (fail safe)
$c2 = $pdo->query("SELECT id FROM dist_contacts WHERE partner_id=" . (int)$A['id'] . " AND verified=0 LIMIT 1")->fetchColumn();
$notifier->verifyContact((int)$c2, 'adm');
is_($notifier->recipientFor((int)$A['id']) === null, '>1 verified number resolves to NONE (ambiguous, fail safe)');
// B keeps exactly one verified
$cb = $notifier->addContact((int)$B['id'], '+256781000000', 'owner', 'adm');
$notifier->verifyContact((int)$cb['id'], 'adm');
is_((int)$notifier->recipientFor((int)$B['id'])['id'] === (int)$cb['id'], 'control: B resolves its single verified number');
is_(threw(fn() => $notifier->addContact((int)$B['id'], '+256781000000', 'owner', 'adm')), 'the SAME number is not added twice to one partner');

echo "\nC. consent — a distributor can mute THEIR OWN alerts\n";
is_($notifier->isMuted((int)$B['id']) === false, 'not muted by default');
$notifier->setMuted((int)$B['id'], true, 'adm');
is_($notifier->isMuted((int)$B['id']) === true, 'setMuted(true) mutes');
$notifier->setMuted((int)$B['id'], false, 'adm');
is_($notifier->isMuted((int)$B['id']) === false, 'setMuted(false) un-mutes (one row per partner)');

echo "\nD. the three events -> a draft; dedup once; suppressed when muted\n";
$lead = $notifier->notify((int)$B['id'], 'lead_attributed', '7001', [], 'adm');
is_(($lead['created'] ?? false) === true && $lead['status'] === 'draft', 'lead_attributed drafts an alert');
$pay = $notifier->notify((int)$B['id'], 'payment_received', '9001', ['customer_name' => 'Jane Doe', 'amount_raw' => '150000', 'amount_display' => 'UGX 150,000', 'client_id' => '5001'], 'adm');
is_(($pay['created'] ?? false) === true, 'payment_received drafts an alert');
$payRow = $notifier->get((int)$pay['id']);
is_(strpos((string)$payRow['body'], 'UGX 150,000') !== false && strpos((string)$payRow['body'], 'client #5001') !== false, 'the payment body carries the owning customer\'s own amount and client id');
is_((string)$payRow['to_phone'] === '+256781000000', 'the draft is addressed to B\'s single verified number');
$act = $notifier->notify((int)$B['id'], 'customer_activated', '5001', ['customer_name' => 'Jane Doe', 'client_id' => '5001'], 'adm');
is_(($act['created'] ?? false) === true, 'customer_activated drafts an alert');
// dedup: a replay of the same payment makes no second draft
$again = $notifier->notify((int)$B['id'], 'payment_received', '9001', ['customer_name' => 'Jane Doe', 'amount_raw' => '150000', 'client_id' => '5001'], 'adm');
is_(($again['created'] ?? null) === false && ($again['reason'] ?? '') === 'duplicate', 'a replay of the same (distributor,event,entity) makes NO second draft');
is_((int)$pdo->query("SELECT COUNT(*) FROM dist_notify_log WHERE dedup_key='DIST:" . (int)$B['id'] . ":payment_received:ucrm_client:9001'")->fetchColumn() === 1, 'exactly one row for that event');
// suppressed when muted
$notifier->setMuted((int)$B['id'], true, 'adm');
$sup = $notifier->notify((int)$B['id'], 'payment_received', '9002', ['amount_raw' => '20000', 'client_id' => '5002'], 'adm');
is_(($sup['created'] ?? null) === false && ($sup['status'] ?? '') === 'suppressed', 'a muted distributor gets a SUPPRESSED row, not a draft');
is_((int)$pdo->query("SELECT COUNT(*) FROM dist_notify_log WHERE status='draft' AND partner_id=" . (int)$B['id'] . " AND entity_id='9002'")->fetchColumn() === 0, '…and no draft is created');
$notifier->setMuted((int)$B['id'], false, 'adm');

echo "\nE. privacy — a foreign value is blocked (allow-list control + a weakened copy)\n";
// A crafted name embeds a FOREIGN amount not among the owning customer's own values.
$leak = $notifier->notify((int)$B['id'], 'payment_received', '9100', ['customer_name' => 'Jane Doe owes UGX 9,999,999', 'amount_raw' => '150000', 'client_id' => '5001'], 'adm');
is_(($leak['created'] ?? null) === false && ($leak['status'] ?? '') === 'blocked', 'a foreign amount in the body is BLOCKED — no sendable draft');
is_(in_array('foreign:amount', $leak['categories'] ?? [], true), '…flagged foreign:amount by the privacy guard', json_encode($leak['categories'] ?? []));
// control (teeth via the allow-list): the SAME body is allowed when that value is the customer's own
$okAllow = $notifier->notify((int)$B['id'], 'payment_received', '9101', ['customer_name' => 'Jane Doe owes UGX 9,999,999', 'amount_raw' => '150000', 'client_id' => '5001', 'allow' => ['9999999']], 'adm');
is_(($okAllow['created'] ?? false) === true, 'control: with 9,999,999 in the allow-list the SAME text is permitted (the guard is allow-list-driven, not keyword)');
// weakened copy: a notifier with the guard neutralised lets the foreign value through -> the guard call is load-bearing
$src = (string)file_get_contents($root . '/lib/DistributorNotifier.php');
$anchor = "\$guard = ReplyPrivacyGuard::check(\$body, self::permittedFor(\$entityId, \$ctx));";
$mutSrc = str_replace($anchor, "\$guard = ['safe' => true, 'reply' => \$body, 'categories' => []];", $src, $n);
$mutSrc = preg_replace('/^require_once .*$/m', '', $mutSrc);               // deps already loaded above
$mutSrc = str_replace('class DistributorNotifier', 'class DistributorNotifierNoGuard', $mutSrc);
is_($n === 1, 'weakened copy: the privacy-guard anchor occurs exactly once in notify()', "found $n");
if ($n === 1) {
    $mf = $tmp . '/_mutant.php'; file_put_contents($mf, $mutSrc); require $mf;
    $m = new DistributorNotifierNoGuard($pdo);
    $leakM = $m->notify((int)$B['id'], 'payment_received', '9102', ['customer_name' => 'Jane Doe owes UGX 9,999,999', 'amount_raw' => '150000', 'client_id' => '5001'], 'adm');
    is_(($leakM['created'] ?? false) === true, 'caught: with the privacy guard removed, the foreign value is NOT blocked (the guard has teeth)');
    @unlink($mf);
}

echo "\nF. draft -> approve: Null channel queues (no send); a live channel sends; reject\n";
is_($notifier->channelName() === 'none' && $notifier->channelIsLive() === false, 'the default bound channel is the Null channel — not live');
$aRes = $notifier->approve((int)$pay['id'], 'approver');
is_(($aRes['ok'] ?? false) === true && ($aRes['status'] ?? '') === 'approved', 'approving with the Null channel QUEUES (status approved), nothing is sent');
is_((string)$notifier->get((int)$pay['id'])['status'] === 'approved', 'the row is approved, not sent');
// a live channel actually sends (proving the approve->send path, with a fake)
$fake = new FakeLiveChannel();
$liveN = new DistributorNotifier($pdo, $fake);
$draft2 = $liveN->notify((int)$B['id'], 'lead_attributed', '7777', [], 'adm');
$aLive = $liveN->approve((int)$draft2['id'], 'approver');
is_(($aLive['status'] ?? '') === 'sent' && count($fake->sent) === 1, 'with a LIVE channel, approve sends exactly one message and marks it sent');
is_($fake->sent[0][0] === '+256781000000', '…to the distributor\'s verified number');
// reject
$rRes = $notifier->reject((int)$act['id'], 'approver', 'not now');
is_(($rRes['status'] ?? '') === 'rejected' && (string)$notifier->get((int)$act['id'])['status'] === 'rejected', 'rejecting marks the draft rejected');
// an edited body is re-checked by the guard on approve (an edit cannot smuggle a foreign value)
$d3 = $notifier->notify((int)$B['id'], 'lead_attributed', '7778', [], 'adm');
$badEdit = $notifier->approve((int)$d3['id'], 'approver', 'New lead — also customer owes UGX 8,888,888');
is_(($badEdit['ok'] ?? null) === false && strpos((string)($badEdit['reason'] ?? ''), 'privacy') !== false, 'an edit that introduces a foreign amount is refused on approve');
is_((string)$notifier->get((int)$d3['id'])['status'] === 'draft', '…and the draft is left untouched');

echo "\nG. DistributorEvents — the Uganda+flag gate; owner resolved from 079; a no-op when off\n";
StaffJobsGate::reset();
$off = DistributorEvents::maybeNotify($store, [], $tmp, 'lead_attributed', ['lead_id' => '7900'], [], 'system');
is_(($off['enabled'] ?? null) === false, 'OFF (not Uganda): maybeNotify is a strict no-op');
is_((int)$pdo->query("SELECT COUNT(*) FROM dist_notify_log WHERE entity_id='7900'")->fetchColumn() === 0, '…and nothing is written');
StaffJobsGate::reset();
$ugOffFlag = DistributorEvents::enabled(['tenant_profile' => 'uganda'], $tmp);
is_($ugOffFlag === false, 'Uganda but the flag OFF: still disabled');
StaffJobsGate::reset();
$cfg = ['tenant_profile' => 'uganda', 'distributors_enabled' => true];
is_(DistributorEvents::enabled($cfg, $tmp) === true, 'Uganda + flag ON: enabled');
// a client owned by A, then a payment event -> a draft for A (owner resolved from dist_customer_links)
$att->link('ucrm_client', '6001', (int)$A['id'], 'manual', 'adm');
StaffJobsGate::reset();
$ev = DistributorEvents::maybeNotify($store, $cfg, $tmp, 'payment_received', ['client_id' => '6001', 'payment_id' => 'P6001'], ['amount_raw' => '50000', 'amount_display' => 'UGX 50,000'], 'system (payment webhook)');
is_(($ev['enabled'] ?? null) === true && ($ev['owned'] ?? null) === true && ($ev['created'] ?? null) === true, 'ON + owned: a draft is created for the owning distributor');
is_((int)$pdo->query("SELECT COUNT(*) FROM dist_notify_log WHERE partner_id=" . (int)$A['id'] . " AND event='payment_received' AND entity_id='P6001'")->fetchColumn() === 1, '…one row, keyed by the payment id');
// an UNOWNED client -> no draft
StaffJobsGate::reset();
$un = DistributorEvents::maybeNotify($store, $cfg, $tmp, 'payment_received', ['client_id' => '6999', 'payment_id' => 'P6999'], ['amount_raw' => '50000'], 'system');
is_(($un['owned'] ?? null) === false && ($un['created'] ?? null) === null, 'ON but UNOWNED: no draft (most customers have no distributor owner)');
StaffJobsGate::reset();

echo "\nH. the UNIQUE dedup index is the floor under exactly-once\n";
$threwDb = false;
try { $pdo->prepare("INSERT INTO dist_notify_log (partner_id, event, scope, entity_id, dedup_key, status) VALUES (?,?,?,?,?, 'draft')")
         ->execute([(int)$B['id'], 'lead_attributed', 'lead', '7001', 'DIST:' . (int)$B['id'] . ':lead_attributed:lead:7001']); }
catch (\PDOException $e) { $threwDb = stripos($e->getMessage(), 'unique') !== false; }
is_($threwDb, 'a direct 2nd row with the same dedup_key is refused by the UNIQUE index');
$okNew = true;
try { $pdo->prepare("INSERT INTO dist_notify_log (partner_id, event, scope, entity_id, dedup_key, status) VALUES (?,?,?,?,?, 'draft')")
         ->execute([(int)$B['id'], 'lead_attributed', 'lead', '7002', 'DIST:' . (int)$B['id'] . ':lead_attributed:lead:7002']); }
catch (\PDOException $e) { $okNew = false; }
is_($okNew, 'control: a different dedup_key is accepted');

echo "\nI. wiring + gating + no uCRM, no live transport bound in the pilot\n";
$ns = nc($root . '/lib/DistributorNotifier.php');
$es = nc($root . '/lib/DistributorEvents.php');
is_(strpos($ns, 'CrmApiClient') === false && strpos($es, 'CrmApiClient') === false, 'the notifier and the event entry never touch uCRM');
is_(strpos($ns, 'EvolutionWhatsAppChannel') === false, 'the notifier never constructs the Evolution adapter (the pilot binds Null only)');
$pd = nc($root . '/includes/post/post_distributors.php');
foreach (['dist_contact_add', 'dist_contact_verify', 'dist_consent_mute', 'dist_notify_approve', 'dist_notify_reject'] as $a) {
    is_(strpos($pd, $a) !== false, "handler wired for action=$a");
}
is_(preg_match('/dist_contact_add.*dist_contact_verify.*dist_consent_mute.*dist_notify_approve.*dist_notify_reject/s', $pd) === 1 && preg_match('/requireAdmin/', $pd) === 1, 'the P3 handlers require admin');
is_(strpos($pd, 'distributors_enabled') !== false && strpos($pd, 'StaffJobsGate::applies') !== false, 'the P3 handlers are flag- and Uganda-gated');
is_(strpos($pd, "'lead_attributed'") !== false && strpos($pd, 'DistributorEvents::maybeNotify') !== false, 'attributing a LEAD fires the lead_attributed event from our own handler');
// the live webhook hooks: flag-gated via DistributorEvents, try/catch isolated, no bound Evolution
$wh = nc($root . '/webhook.php');
is_(substr_count($wh, 'DistributorEvents::maybeNotify') === 2, 'webhook.php hooks exactly the two CRM events (payment, activation)');
is_(preg_match('/try \{\s*require_once[^;]+DistributorEvents\.php.*?payment_received/s', $wh) === 1, 'the payment hook is inside a try/catch');
is_(strpos($wh, 'EvolutionWhatsAppChannel') === false && strpos($wh, "new DistributorNotifier(") === false, 'webhook.php binds no live channel (draft only, via the default Null)');
$tab = nc($root . '/tabs/admin/distributors.php');
is_(strpos($tab, 'Notifications') !== false && strpos($tab, 'Approve &amp; queue') !== false, 'the tab renders the Notifications queue');

echo "\nJ. the port — Null bound by default; the Evolution adapter exists but is never bound\n";
is_((new NullWhatsAppChannel())->isLive() === false, 'NullWhatsAppChannel is not live');
is_(class_exists('EvolutionWhatsAppChannel'), 'the Evolution adapter exists for the port');
$nullSend = (new NullWhatsAppChannel())->send('+256700000000', 'hi');
is_(($nullSend['sent'] ?? null) === false, 'the Null channel sends nothing');

echo "\nK. manifest version\n";
$mani = json_decode((string)file_get_contents($root . '/manifest.json'), true);
is_(($mani['information']['version'] ?? '') === '5.18.82', 'manifest version is 5.18.71');

exec('rm -rf ' . escapeshellarg($tmp));
echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
