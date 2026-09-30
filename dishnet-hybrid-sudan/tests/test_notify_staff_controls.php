<?php
declare(strict_types=1);
/**
 * test_notify_staff_controls.php — 5.18.54, docs/46 rows 9-11 (D-3/C6, S-1, D-7/C3).
 *
 * Through the real plugin under php -S (tests/fixtures/staff_jobs_sandbox.php), with a fake uCRM and the fake
 * Evolution, as the accounts that would use them:
 *
 *   1. S-1: the six failure-queue API actions follow the Failed Queue screen's rule — an administrator's. The rows hold
 *      customers' numbers and the text of their messages, and a retry sends a WhatsApp. A refused call changes nothing
 *      and sends nothing; an administrator's call still works. (The inbox banner's "Retry Now" needs no change: the
 *      router's tab map already opens the inbox to administrators only, whatever wa_inbox_roles says.)
 *   2. D-7: the test invoice WhatsApp goes only on an administrator's POST with confirm=1 (the webhook secret no longer
 *      opens it, a link cannot send it); the invoice scan previews on a GET and sends on a POST with confirm=1, under the
 *      shared INV<number> guard, unpaid invoices only; crm_fix_notifications (C3) is read-only: no PATCH reaches uCRM.
 *   3. D-3: the WhatsApp Event Map says that nothing reads it, shows no switch, and refuses to save.
 *   4. South Sudan: 5.18.53 unchanged in each (docs/46 §E).
 *   5. Weakened copies, each caught.
 *
 * Documents the server writes are read from its database directly: this process's SqliteStore caches, and a keyed
 * document read back through it loses its keys (docs/46, N-6).
 */
$root = dirname(__DIR__);
require_once __DIR__ . '/fixtures/staff_jobs_sandbox.php';

$pass = 0; $fail = 0;
function is_(bool $c, string $m, string $d = ''): void {
    global $pass, $fail;
    if ($c) { $pass++; echo "  ok   {$m}\n"; }
    else    { $fail++; echo "  FAIL {$m}" . ($d !== '' ? "\n       {$d}" : '') . "\n"; }
}

const DENIAL = 'WhatsApp is restricted to administrators.';
// The query strings are the API's own documented form (?page=api&action=notification_retry&id=123): it reads the id
// from the form or the query, not from a JSON body.
const QUEUE_ACTIONS = [
    ['GET',  'notification_queue',       ''],
    ['POST', 'notification_retry',       '&id=1'],
    ['POST', 'notification_retry_bulk',  ''],
    ['POST', 'notification_dismiss',     '&id=2'],
    ['POST', 'notification_dismiss_all', ''],
    ['POST', 'notification_purge',       '&days=1'],
];
$clients = ['15' => ['id' => 15, 'firstName' => 'Canary', 'lastName' => 'Customer', 'isLead' => false,
                     'contacts' => [['phone' => '+256700000915', 'email' => 'canary@example.test']]]];
$now = gmdate('Y-m-d\TH:i:s') . '+0000';
$invoices = [
    '8801' => ['id' => 8801, 'number' => '8801', 'clientId' => 15, 'status' => 1, 'total' => 90000.0, 'amountToPay' => 90000.0,
               'currencyCode' => 'UGX', 'createdDate' => $now, 'dueDate' => '2026-10-14T00:00:00+0300'],
    '8802' => ['id' => 8802, 'number' => '8802', 'clientId' => 15, 'status' => 3, 'total' => 90000.0, 'amountToPay' => 0.0,
               'currencyCode' => 'UGX', 'createdDate' => $now, 'dueDate' => '2026-10-14T00:00:00+0300'],
    '8803' => ['id' => 8803, 'number' => '8803', 'clientId' => 15, 'status' => 1, 'total' => 90000.0, 'amountToPay' => 90000.0,
               'currencyCode' => 'UGX', 'createdDate' => $now, 'dueDate' => '2026-10-14T00:00:00+0300'],
];
$options = ['notificationInvoiceNearDue' => true, 'notificationInvoiceOverdue' => true,
            'notificationInvoiceNew' => true, 'notificationServiceSuspended' => true];

/** Three failed messages in the queue, as NotificationService leaves them. */
$seedQueue = function (SjSandbox $s): void {
    $s->q("CREATE TABLE IF NOT EXISTS notification_queue (id INTEGER PRIMARY KEY AUTOINCREMENT, sender TEXT NOT NULL DEFAULT 'support',
           phone TEXT NOT NULL, message TEXT NOT NULL, event TEXT DEFAULT NULL, vars TEXT DEFAULT NULL, status TEXT NOT NULL DEFAULT 'failed',
           http_code INTEGER DEFAULT NULL, error TEXT DEFAULT NULL, attempts INTEGER NOT NULL DEFAULT 1, last_attempt_at TEXT NOT NULL,
           retry_at TEXT DEFAULT NULL, retry_by TEXT DEFAULT NULL, created_at TEXT NOT NULL DEFAULT (datetime('now')))");
    foreach ([1, 2, 3] as $i) {
        $s->q("INSERT INTO notification_queue (id, sender, phone, message, event, status, attempts, last_attempt_at) VALUES (?, 'accounts', ?, ?, 'ops_test', 'failed', 1, ?)",
              [$i, '25670000093' . $i, "CANARY-QUEUED-MESSAGE-{$i}", date('Y-m-d H:i:s')]);
    }
};
$queueState = fn(SjSandbox $s): string => json_encode(array_map(fn($r) => [$r['id'], $r['status']], $s->q('SELECT id, status FROM notification_queue ORDER BY id')));

/** A sandbox with the accounts every case uses. */
$start = function (string $tree, string $tenant, string $tag, array $cfg = []) use ($clients, $invoices, $options, $seedQueue): SjSandbox {
    $base = $tenant === 'uganda' ? ['tenant_profile' => 'uganda', 'timezone' => 'Africa/Kampala'] : ['timezone' => 'Africa/Juba'];
    $s = SjSandbox::start($tree, $cfg + $base + ['wa_inbox_roles' => 'support'], $tag);
    $s->staff('admin',   ['name' => 'Sandbox Admin', 'email' => 'admin@example.test', 'role' => 'admin', 'is_admin' => true]);
    $s->staff('support', ['name' => 'Sandbox Support', 'email' => 'support@example.test', 'role' => 'support']);
    $s->staff('sales',   ['name' => 'Sandbox Sales', 'email' => 'sales@example.test', 'role' => 'sales']);
    $s->seedCrm(['clients' => $clients, 'invoices' => $invoices, 'options' => $options]);
    $seedQueue($s);
    return $s;
};

// ── The cases, as functions of the plugin tree, so a weakened copy runs the very same ones ─────────────────────────
$queueCase = function (SjSandbox $s) use ($queueState): array {
    $out = ['support' => [], 'sales' => null];
    $before = $queueState($s); $texts0 = count($s->texts());
    foreach (QUEUE_ACTIONS as [$m, $a, $qs]) $out['support'][$a] = $s->api('support', $m, $a, $m === 'POST' ? [] : null, $qs);
    $out['sales'] = $s->api('sales', 'GET', 'notification_queue');
    $out['unchanged'] = $queueState($s) === $before && count($s->texts()) === $texts0;
    $out['admin_list'] = $s->api('admin', 'GET', 'notification_queue');
    $out['admin_retry'] = $s->api('admin', 'POST', 'notification_retry', [], '&id=1');
    $out['admin_texts'] = array_values(array_filter($s->texts(), fn($t) => strpos($t['text'], 'CANARY-QUEUED-MESSAGE-1') !== false));
    $out['admin_dismiss'] = $s->api('admin', 'POST', 'notification_dismiss', [], '&id=2');
    $out['after'] = $queueState($s);
    return $out;
};
/** The rows of one of the server's store tables, read from its database (none when the table does not exist). */
$rowsOf = function (SjSandbox $s, string $table): array {
    try { return $s->q("SELECT * FROM [{$table}]"); } catch (\Throwable $e) { return []; }
};
$linksCase = function (SjSandbox $s) use ($rowsOf): array {
    $o = [];
    $inv = fn() => count(array_filter($s->texts(), fn($t) => strpos($t['text'], 'TEST-') !== false));
    $o['get_admin']   = $s->api('admin', 'GET', 'test_invoice_notify', null, '&client_id=15&confirm=1');   // a link, even one saying confirm=1
    $o['key_support'] = $s->api('support', 'GET', 'test_invoice_notify', null, '&client_id=15&debug_key=' . $s->whKey);
    $o['key_support_post'] = $s->api('support', 'POST', 'test_invoice_notify', ['client_id' => 15, 'confirm' => '1'], '&debug_key=' . $s->whKey);
    $o['post_noconfirm'] = $s->api('admin', 'POST', 'test_invoice_notify', ['client_id' => 15]);
    $o['sent_before'] = $inv();
    $o['post_confirm'] = $s->api('admin', 'POST', 'test_invoice_notify', ['client_id' => 15, 'confirm' => '1']);
    $o['sent_after'] = $inv();

    $s->q('CREATE TABLE IF NOT EXISTS notification_dedup (dedup_key TEXT PRIMARY KEY, sent_at TEXT NOT NULL)');
    $s->q("INSERT OR IGNORE INTO notification_dedup (dedup_key, sent_at) VALUES ('INV8803', ?)", [date('Y-m-d H:i:s')]);  // the webhook announced it
    $new = fn() => array_values(array_filter($s->texts(), fn($t) => strpos($t['text'], 'New Invoice') !== false));
    $n0 = count($new());
    $o['scan_get'] = $s->api('admin', 'GET', 'invoice_notify_scan', null, '&send=1');
    $o['scan_get_sent'] = count($new()) - $n0;
    $o['scan_post'] = $s->api('admin', 'POST', 'invoice_notify_scan', ['confirm' => '1']);
    $o['scan_post_sent'] = array_map(fn($t) => preg_match('/#(\d{4})/', $t['text'], $m) ? $m[1] : '?', array_slice($new(), $n0));
    $n1 = count($new());
    $o['scan_again'] = $s->api('admin', 'POST', 'invoice_notify_scan', ['confirm' => '1']);
    $o['scan_again_sent'] = count($new()) - $n1;
    $o['old_file'] = $rowsOf($s, 'invoice_notify_log');

    $o['fix_get']  = $s->api('admin', 'GET', 'crm_fix_notifications');
    $o['fix_post'] = $s->api('admin', 'POST', 'crm_fix_notifications', ['confirm' => '1']);
    $o['patches']  = count($s->crmReqs('PATCH', '#^/options$#'));
    return $o;
};
$eventMapCase = function (SjSandbox $s) use ($rowsOf): array {
    $s->login('admin', 'admin@example.test', 'sj-password-1');
    $page = $s->page('admin', 'page=dashboard&tab=whatsapp&subtab=events');
    $s->form('admin', ['wa_action' => 'wa_save_template', 'tpl_key' => 'ops_payment_received', 'tpl_body' => 'CANARY-TEMPLATE-BODY',
                       'tpl_sender' => 'accounts'], 'page=dashboard&tab=whatsapp&subtab=events', 'page=dashboard&tab=whatsapp&subtab=events');
    $flash = $s->page('admin', 'page=dashboard&tab=whatsapp&subtab=events');
    $saved = strpos(json_encode($rowsOf($s, 'wa_templates')), 'CANARY-TEMPLATE-BODY') !== false;
    return ['page' => $page, 'after' => $flash, 'saved' => $saved];
};
$code = fn(array $r): int => (int)$r[0];
$msg  = fn(array $r): string => (string)($r[2]['message'] ?? '');

// ══════════════════════════════════════════════════════════════════════════════
echo "\n1. Uganda — S-1: the failure queue is an administrator's\n";
$u = $start($root, 'uganda', 'nsc');
$q = $queueCase($u);
foreach (QUEUE_ACTIONS as [$m, $a, $qs]) {
    is_($code($q['support'][$a]) === 403 && $msg($q['support'][$a]) === DENIAL, "support: {$m} {$a} → 403, the WhatsApp rule's words",
        $code($q['support'][$a]) . ' ' . $msg($q['support'][$a]));
}
is_($code($q['sales']) === 403, 'sales: notification_queue → 403');
is_(stripos((string)$q['support']['notification_queue'][1], 'CANARY-QUEUED') === false, 'the refusal carries no queued message');
is_($q['unchanged'], 'the refused calls changed nothing and sent nothing');
is_($code($q['admin_list']) === 200 && ($q['admin_list'][2]['data']['total'] ?? 0) === 3, 'admin: the queue lists its 3 rows (control)');
is_($code($q['admin_retry']) === 200 && count($q['admin_texts']) === 1, 'admin: a retry sends that message, once', json_encode($q['admin_retry'][2]));
is_($q['after'] === '[[1,"sent"],[2,"dismissed"],[3,"failed"]]', 'admin: retried, dismissed, and the third left as it was', $q['after']);

echo "\n2. Uganda — D-7: links that act\n";
$l = $linksCase($u);
is_($code($l['get_admin']) === 405, 'test invoice by GET, even an administrator\'s link saying confirm=1: 405, nothing sent', $code($l['get_admin']) . ' ' . $msg($l['get_admin']));
is_($code($l['key_support']) === 403 && $code($l['key_support_post']) === 403, 'the webhook secret no longer opens it to a non-administrator');
is_($code($l['post_noconfirm']) === 400 && $l['sent_before'] === 0, 'a POST without confirm=1: 400, nothing sent');
is_($code($l['post_confirm']) === 200 && $l['sent_after'] === 1, 'an administrator\'s POST with confirm=1 sends it, once', json_encode($l['post_confirm'][2]));
is_($l['scan_get_sent'] === 0 && strpos((string)($l['scan_get'][2]['data']['mode'] ?? ''), 'PREVIEW') === 0,
    'invoice scan by GET with send=1: a preview, nothing sent', json_encode($l['scan_get'][2]['data']['mode'] ?? null));
$rows = []; foreach ((array)($l['scan_get'][2]['data']['invoices'] ?? []) as $r) $rows[$r['number']] = $r;
is_(($rows['8803']['already_sent'] ?? null) === true, 'the preview reads the shared guard: an invoice the webhook announced shows as sent');
is_(($rows['8802']['action'] ?? '') === 'skip' && strpos((string)($rows['8802']['reason'] ?? ''), 'not unpaid') === 0, 'a paid invoice is not offered', json_encode($rows['8802'] ?? null));
is_($l['scan_post_sent'] === ['8801'], 'POST with confirm=1: only the unpaid, unannounced invoice is announced', json_encode($l['scan_post_sent']));
is_($l['scan_again_sent'] === 0, 'the same POST again announces nothing');
is_($l['old_file'] === [], 'the old invoice_notify_log.json is not written', json_encode($l['old_file']));
is_($code($l['fix_get']) === 200 && !empty($l['fix_get'][2]['data']['read_only']), 'crm_fix_notifications answers read-only', json_encode($l['fix_get'][2]['data'] ?? null));
is_($l['patches'] === 0, 'and no PATCH reaches uCRM, by GET or by POST (C3)', (string)$l['patches']);
is_(($l['fix_get'][2]['data']['changes'][0]['old'] ?? null) === true, 'it still shows uCRM\'s current values (control)');

echo "\n3. Uganda — D-3: the Event Map says nothing reads it\n";
$e = $eventMapCase($u);
is_(strpos($e['page'], 'id="waEventMapNotUsed"') !== false, 'the page says it is reference only', substr(strip_tags($e['page']), 0, 120));
is_(strpos($e['page'], 'Duplicate Prevention Active') === false, 'the false "Duplicate Prevention Active" banner is gone');
is_(strpos($e['page'], 'id="waSaveForm"') === false && strpos($e['page'], 'id="waResetForm"') === false, 'no Save and no Reset');
is_(!preg_match('/<input type="checkbox" id="waModalEnabled" style=/', $e['page']), 'no on/off switch that looks as if it works');
is_(substr_count($e['page'], '>not used</span>') >= 5, 'every row says "not used"', (string)substr_count($e['page'], '>not used</span>'));
is_(!$e['saved'] && strpos($e['after'], 'Not saved.') !== false, 'a forged save is refused, and says why', substr(strip_tags($e['after']), 0, 200));
is_(strpos($e['page'], 'id="waTestForm"') !== false, 'Test Send stays, to check the connection');
$u->stop();

// ══════════════════════════════════════════════════════════════════════════════
echo "\n4. South Sudan: 5.18.53 unchanged (docs/46 §E)\n";
$ss = $start($root, 'south-sudan', 'nsc-ss');
$sq = $queueCase($ss);
is_($code($sq['support']['notification_queue']) === 200, 'S-1 there awaits approval: support can still list the queue', (string)$code($sq['support']['notification_queue']));
$sl = $linksCase($ss);
is_($code($sl['key_support']) === 200 && $code($sl['get_admin']) === 200, 'the test invoice is still a GET, open to the webhook secret');
is_($sl['scan_get_sent'] >= 1, 'the invoice scan still sends on GET with send=1');
is_($rowsOf($ss, 'invoice_notify_log') !== [], 'and still writes the old invoice_notify_log.json (control for the read above)');
is_($sl['patches'] >= 1, 'crm_fix_notifications still PATCHes uCRM from a GET');
$se = $eventMapCase($ss);
is_(strpos($se['page'], 'Duplicate Prevention Active') !== false && strpos($se['page'], 'id="waSaveForm"') !== false, 'the Event Map as it was');
is_($se['saved'], 'and its save still writes wa_templates.json');
$ss->stop();

// ══════════════════════════════════════════════════════════════════════════════
echo "\n5. Weakened copies, each caught\n";
/** A weakened copy with several changes in one file, each anchor found exactly once. */
$weaken = function (string $rel, array $pairs) use ($root): array {
    [$old0, $new0] = array_shift($pairs);
    [$tree, $n] = sj_weakened_copy($root, $rel, $old0, $new0);
    foreach ($pairs as [$old, $new]) {
        $src = (string)file_get_contents($tree . '/' . $rel);
        $k = substr_count($src, $old);
        if ($k !== 1) { $n = 0; break; }
        file_put_contents($tree . '/' . $rel, str_replace($old, $new, $src));
    }
    return [$tree, $n];
};
$mutants = [
    'the queue actions open to every account (S-1)' => ['includes/api/api_notifications.php', [
        ["    if (\$_nqAdminQ && in_array(\$act, ['notification_queue',", "    if (false && in_array(\$act, ['notification_queue',"]]],
    'the webhook secret opens the test invoice again' => ['includes/api/api_notifications.php', [
        ["            if (!\$isAdmin) \$er2('Administrators only.', 403);", "            if (!\$debugAuth) \$er2('Administrators only.', 403);"]]],
    'a link can send the test invoice' => ['includes/api/api_notifications.php', [
        ["            if (\$met !== 'POST') \$er2('Nothing sent. This sends a test invoice WhatsApp to a real client: POST it, with confirm=1.', 405);", ''],
        ["        return \$met === 'POST' && (string)(\$body['confirm'] ?? \$_POST['confirm'] ?? '') === '1';",
         "        return (string)(\$body['confirm'] ?? \$_POST['confirm'] ?? \$_GET['confirm'] ?? '') === '1';"]]],
    'the invoice scan without the shared guard' => ['includes/api/api_notifications.php', [
        ['                if ($phone2 && $doSend && $_nqLinks && !$notify->dedupMark($logKey)) {', '                if (false) {']]],
    'crm_fix_notifications writes again' => ['includes/api/api_notifications.php', [
        ['$dryRun = $_nqLinks ? true : !empty($_GET[\'dry_run\']);', '$dryRun = !empty($_GET[\'dry_run\']);']]],
    'the Event Map saves again' => ['tabs/engage/whatsapp.php', [
        ["    if (\$_emUnused && (\$waAct === 'wa_save_template' || \$waAct === 'wa_reset_template')) {", "    if (false) {"]]],
];
foreach ($mutants as $name => [$rel, $pairs]) {
    [$tree, $n] = $weaken($rel, $pairs);
    if ($n !== 1) { is_(false, "caught: {$name}", "an anchor was not found exactly once in {$rel}"); exec('rm -rf ' . escapeshellarg($tree)); continue; }
    $w = $start($tree, 'uganda', 'nsc-wk');
    $caught = false; $why = '';
    if (strpos($name, 'queue actions') !== false)     { $x = $queueCase($w); $caught = $code($x['support']['notification_queue']) === 200; $why = 'support listed the queue'; }
    elseif (strpos($name, 'Event Map') !== false)     { $x = $eventMapCase($w); $caught = $x['saved']; $why = 'the save wrote'; }
    else {
        $x = $linksCase($w);
        if (strpos($name, 'secret') !== false)            { $caught = $code($x['key_support_post']) === 200; $why = 'a non-administrator sent it with the secret'; }
        elseif (strpos($name, 'link can') !== false)      { $caught = $code($x['get_admin']) === 200; $why = 'a GET sent it'; }
        elseif (strpos($name, 'shared guard') !== false)  { $caught = $x['scan_again_sent'] > 0; $why = 'the second POST resent'; }
        else                                              { $caught = $x['patches'] > 0; $why = 'uCRM was PATCHed'; }
    }
    is_($caught, "caught: {$name}" . ($caught ? " ({$why})" : ''));
    $w->stop();
    exec('rm -rf ' . escapeshellarg($tree));
}

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail ? 1 : 0);
