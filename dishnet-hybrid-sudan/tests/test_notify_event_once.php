<?php
declare(strict_types=1);
/**
 * test_notify_event_once.php — 5.18.54, docs/46 row 15 (D10): one message per uCRM event.
 *
 * Through the real plugin under php -S (tests/fixtures/staff_jobs_sandbox.php): uCRM's webhook as uCRM sends it, a
 * fake uCRM and the fake Evolution. Every event that sends a WhatsApp with no guard of its own is delivered twice with
 * the same event id (uuid), as uCRM does when it delivers an event again:
 *
 *   1. client.add: the welcome, and the administrator's duplicate-number alert — two messages from one event, each once
 *   2. service.add: the activation message
 *   3. service.suspend: the suspension notice, and a VIP customer's administrator alert
 *   4. service.postpone, service.end, quote.approve, client.message
 *   5. two REAL events — two uuids — each send: a second suspension, a second message from uCRM
 *   6. an event with no uuid is never guarded: two such deliveries send twice (nothing to tell them apart by)
 *   7. South Sudan: 5.18.53 unchanged, a redelivered event sends again (docs/46 §E)
 *   8. weakened copies, each caught (skipped with --no-mutants)
 *
 * What this proves is what the plugin hands to WhatsApp, never delivery to a phone. Every person, number and document
 * is fictitious; nothing leaves the machine.
 */
$root = dirname(__DIR__);
$withMutants = !in_array('--no-mutants', $argv, true);
require_once __DIR__ . '/fixtures/staff_jobs_scenario.php';

$pass = 0; $fail = 0;
function is_(bool $c, string $m, string $d = ''): void {
    global $pass, $fail;
    if ($c) { $pass++; echo "  ok   {$m}\n"; }
    else    { $fail++; echo "  FAIL {$m}" . ($d !== '' ? "\n       {$d}" : '') . "\n"; }
}

const ADMIN = '256700000100';
/** crm_webhook_key: client.message is forwarded only from a request that proved itself with it (5.18.37). */
const WHKEY = 'neo-webhook-key-5f1c0a';

/** A sandbox with SjScenario's customer 15 and three more: 21 shares 15's number, 22 is a VIP, 23 has no service. */
$start = function (string $tree, string $tenant, string $tag): SjSandbox {
    $base = $tenant === 'uganda' ? ['tenant_profile' => 'uganda', 'timezone' => 'Africa/Kampala'] : ['timezone' => 'Africa/Juba'];
    $s = SjSandbox::start($tree, $base + ['whatsapp_admin_phone' => '+' . ADMIN, 'starlink_block_vip_clients' => '22',
                                         'crm_webhook_key' => WHKEY], $tag);
    file_put_contents($s->plug . '/ucrm.json', json_encode(['pluginDataDir' => $s->data, 'pluginPublicUrl' => $s->base]));
    $crm = SjScenario::crm();
    $client = function (int $id, string $phone, float $owed = 0.0): array {
        return ['id' => $id, 'firstName' => 'Customer', 'lastName' => (string)$id, 'isLead' => false,
                'accountOutstanding' => $owed, 'accountOutstandingRaw' => $owed,
                'contacts' => [['email' => "c{$id}@example.test", 'phone' => $phone, 'isBilling' => true]]];
    };
    $crm['clients']['15'] += ['accountOutstanding' => 120000.0, 'accountOutstandingRaw' => 120000.0];
    $crm['clients']['21'] = $client(21, '+256700000915');              // the same number as customer 15
    $crm['clients']['22'] = $client(22, '+256700000922', 300000.0);    // a VIP: starlink_block_vip_clients
    $crm['clients']['23'] = $client(23, '+256700000923');
    $svc = function (int $id, int $client): array { return ['id' => $id, 'clientId' => $client, 'name' => 'Sandbox Home 100', 'status' => 1]; };
    $crm['services'] = ['7715' => $svc(7715, 15), '7721' => $svc(7721, 21), '7722' => $svc(7722, 22)];
    $crm['quotes'] = ['3301' => ['id' => 3301, 'number' => 'Q-3301', 'clientId' => 15, 'total' => 450000.0]];
    $s->seedCrm($crm);
    SjScenario::staff($s);
    return $s;
};

/** How many messages starting with $head went to $to since $from. */
$count = function (SjSandbox $s, string $to, string $head, int $from = 0): int {
    return count(array_filter(array_slice($s->texts(), $from), function ($t) use ($to, $head) {
        return $t['number'] === $to && strpos((string)$t['text'], $head) === 0;
    }));
};
/** An event as uCRM posts it, with the webhook's own key (X-Crm-Key); $extra travels in the entity. */
$fire = function (SjSandbox $s, string $type, string $entity, int $id, string $uuid, array $extra = []): array {
    return $s->http('POST', "{$s->base}?page=crm_webhook", ['changeType' => $type, 'entity' => $entity, 'entityId' => $id,
        'uuid' => $uuid, 'extraData' => ['entity' => ['id' => $id] + $extra]], ['Content-Type: application/json', 'X-Crm-Key: ' . WHKEY]);
};
/** uCRM's client.message: the text travels in the event itself. */
$message = function (SjSandbox $s, int $client, string $text, string $uuid) use ($fire): array {
    return $fire($s, 'client.message', 'client', $client, $uuid, ['clientId' => $client, 'message' => $text]);
};

// ── The cases, as functions of the sandbox, so a weakened copy runs the very same ones ──────────────────────────────

/** client.add twice for a customer whose number another customer already has: welcome and alert, each once. */
$clientCase = function (SjSandbox $s) use ($count, $fire): array {
    $o = [];
    $o['r0'] = $fire($s, 'client.add', 'client', 15, 'd10-c15');           // 15 enters the customer index
    $n = count($s->texts());
    $o['r1'] = $fire($s, 'client.add', 'client', 21, 'd10-c21');
    $o['r2'] = $fire($s, 'client.add', 'client', 21, 'd10-c21');            // uCRM delivers it again
    $o['welcome'] = $count($s, '256700000915', '🎉 *Welcome to DishNet!*', $n);
    $o['alert']   = $count($s, ADMIN, '⚠ *Possible Duplicate Client*', $n);
    return $o;
};

/** service.add, then service.suspend (a VIP's too), postpone, end, each twice; then a second, real suspension. */
$serviceCase = function (SjSandbox $s) use ($count, $fire): array {
    $o = [];
    $n = count($s->texts());
    $fire($s, 'service.add', 'service', 7721, 'd10-add');
    $fire($s, 'service.add', 'service', 7721, 'd10-add');
    $o['activation'] = $count($s, '256700000915', '🚀 *Service Activated', $n);

    $n = count($s->texts());
    $o['rs1'] = $fire($s, 'service.suspend', 'service', 7715, 'd10-susp-1');
    $o['rs2'] = $fire($s, 'service.suspend', 'service', 7715, 'd10-susp-1');
    $o['suspension'] = $count($s, '256700000915', '🚫 *Service Suspended', $n) + $count($s, '256700000915', '⏸️ *Service Paused', $n);
    $n = count($s->texts());
    $fire($s, 'service.suspend', 'service', 7715, 'd10-susp-2');           // a second suspension, a new event
    $o['suspension_real'] = $count($s, '256700000915', '🚫 *Service Suspended', $n) + $count($s, '256700000915', '⏸️ *Service Paused', $n);

    $n = count($s->texts());
    $fire($s, 'service.suspend', 'service', 7722, 'd10-vip');
    $fire($s, 'service.suspend', 'service', 7722, 'd10-vip');
    $o['vip'] = $count($s, ADMIN, '🛡️ *VIP Suspension Intercepted*', $n);
    $o['vip_customer'] = $count($s, '256700000922', '', $n);

    $n = count($s->texts());
    $fire($s, 'service.postpone', 'service', 7715, 'd10-post');
    $fire($s, 'service.postpone', 'service', 7715, 'd10-post');
    $o['postpone'] = $count($s, '256700000915', '⏰ *Service Temporarily Restored', $n);

    $n = count($s->texts());
    $fire($s, 'service.end', 'service', 7715, 'd10-end');
    $fire($s, 'service.end', 'service', 7715, 'd10-end');
    $o['end'] = $count($s, '256700000915', '👋 *Service Ended', $n);
    return $o;
};

/** quote.approve and client.message, each twice; then a second real message, and two with no uuid at all. */
$otherCase = function (SjSandbox $s) use ($count, $message, $fire): array {
    $o = [];
    $n = count($s->texts());
    $fire($s, 'quote.approve', 'quote', 3301, 'd10-qa');
    $fire($s, 'quote.approve', 'quote', 3301, 'd10-qa');
    $o['quote'] = $count($s, '256700000915', '✅ *Quote Approved', $n);

    $n = count($s->texts());
    $o['rm1'] = $message($s, 23, 'Your technician arrives at 10:00.', 'd10-msg-1');
    $o['rm2'] = $message($s, 23, 'Your technician arrives at 10:00.', 'd10-msg-1');
    $o['msg'] = $count($s, '256700000923', '📩 *Message from DishNet*', $n);
    $n = count($s->texts());
    $message($s, 23, 'Your technician is on the way.', 'd10-msg-2');   // a second message, a new event
    $o['msg_real'] = $count($s, '256700000923', '📩 *Message from DishNet*', $n);
    $n = count($s->texts());
    $message($s, 23, 'Sent with no event id.', '');
    $message($s, 23, 'Sent with no event id.', '');
    $o['msg_nouuid'] = $count($s, '256700000923', '📩 *Message from DishNet*', $n);
    return $o;
};

// ══════════════════════════════════════════════════════════════════════════════
echo "\n1. Uganda — client.add delivered twice: the welcome and the alert, each once\n";
$u = $start($root, 'uganda', 'neo');
$c = $clientCase($u);
is_((int)$c['r1'][0] === 200 && (int)$c['r2'][0] === 200, 'both deliveries answered 200, as uCRM expects', json_encode([$c['r1'][0], $c['r2'][0]]));
is_($c['welcome'] === 1, 'one welcome', (string)$c['welcome']);
is_($c['alert'] === 1, 'one duplicate-number alert to the administrator — a second message from the same event still goes', (string)$c['alert']);

echo "\n2–3. Uganda — service events delivered twice\n";
$v = $serviceCase($u);
is_($v['activation'] === 1, 'service.add: one activation message', (string)$v['activation']);
is_((int)$v['rs1'][0] === 200 && (int)$v['rs2'][0] === 200 && $v['suspension'] === 1, 'service.suspend: one suspension notice', json_encode([$v['rs1'][0], $v['rs2'][0], $v['suspension']]));
is_($v['suspension_real'] === 1, 'a second, real suspension (a new event) is announced', (string)$v['suspension_real']);
is_($v['vip'] === 1 && $v['vip_customer'] === 0, 'a VIP\'s suspension: one administrator alert, nothing to the customer', json_encode([$v['vip'], $v['vip_customer']]));
is_($v['postpone'] === 1, 'service.postpone: one "temporarily restored" message', (string)$v['postpone']);
is_($v['end'] === 1, 'service.end: one "service ended" message', (string)$v['end']);

echo "\n4–6. Uganda — quote approval, uCRM's messages, and events with no id\n";
$w = $otherCase($u);
is_($w['quote'] === 1, 'quote.approve: one message', (string)$w['quote']);
is_((int)$w['rm1'][0] === 200 && (int)$w['rm2'][0] === 200 && $w['msg'] === 1, 'client.message: forwarded once', json_encode([$w['rm1'][0], $w['rm2'][0], $w['msg']]));
is_($w['msg_real'] === 1, 'a second message from uCRM (a new event) is forwarded', (string)$w['msg_real']);
is_($w['msg_nouuid'] === 2, 'two deliveries with no event id both go: nothing tells them apart, so nothing is held back', (string)$w['msg_nouuid']);
$logged = $u->q("SELECT COUNT(*) AS n FROM notification_dedup WHERE dedup_key LIKE 'EVT:%'");
is_((int)($logged[0]['n'] ?? 0) === 12, 'twelve claims, one per message sent — event id and message, never the text', json_encode($logged));
$u->stop();

// ══════════════════════════════════════════════════════════════════════════════
echo "\n7. South Sudan: 5.18.53 unchanged — a redelivered event sends again (docs/46 §E)\n";
$ss = $start($root, 'south-sudan', 'neo-ss');
$sc = $clientCase($ss);
is_($sc['welcome'] === 2 && $sc['alert'] === 2, 'client.add twice: two welcomes and two alerts, as before', json_encode([$sc['welcome'], $sc['alert']]));
$sv = $serviceCase($ss);
is_($sv['activation'] === 2 && $sv['suspension'] === 2 && $sv['end'] === 2, 'service events twice: each message twice, as before',
    json_encode([$sv['activation'], $sv['suspension'], $sv['end']]));
$sw = $otherCase($ss);
is_($sw['quote'] === 2 && $sw['msg'] === 2, 'quote approval and uCRM\'s message twice: twice, as before', json_encode([$sw['quote'], $sw['msg']]));
// No claim at all there, so the claims table may never have been created: that reads as none, anything else fails.
try { $none = (int)($ss->q("SELECT COUNT(*) AS n FROM notification_dedup WHERE dedup_key LIKE 'EVT:%'")[0]['n'] ?? -1); }
catch (\Throwable $e) { $none = strpos($e->getMessage(), 'no such table: notification_dedup') !== false ? 0 : -1; }
is_($none === 0, 'and no event claim is written there', (string)$none);
$ss->stop();

// ══════════════════════════════════════════════════════════════════════════════
echo "\n8. Weakened copies, each caught\n";
// Each predicate names the defect itself, with a control that the case really ran, so a copy that merely crashed is
// never counted as caught.
$mutants = [
    'no guard at all' => ['webhook.php',
        ['    return $notify->dedupMark("EVT:{$uuid}:{$message}");', '    return true;'],
        'other', function (array $x) { return $x['quote'] === 2 && $x['msg_real'] === 1; }, 'the quote approval went twice'],
    'the key forgets the event (two real events look like one)' => ['webhook.php',
        ['    return $notify->dedupMark("EVT:{$uuid}:{$message}");', '    return $notify->dedupMark("EVT:{$message}");'],
        'service', function (array $x) { return $x['suspension'] === 1 && $x['suspension_real'] === 0; }, 'the second, real suspension was not announced'],
    'the key forgets the message (one event, two messages, one lost)' => ['webhook.php',
        ['    return $notify->dedupMark("EVT:{$uuid}:{$message}");', '    return $notify->dedupMark("EVT:{$uuid}");'],
        'client', function (array $x) { return $x['alert'] === 1 && $x['welcome'] === 0; }, 'the welcome was held back by the alert\'s claim'],
    'the suspension notice unguarded' => ['webhook.php',
        ['        if ($phone && !whEventOnce($uuid, \'suspension\', $notify, $config, $dataDir)) {', '        if (false) {'],
        'service', function (array $x) { return $x['suspension'] === 2 && $x['activation'] === 1; }, 'two suspension notices for one event'],
    'events with no id share one claim' => ['webhook.php',
        ["    if (\$uuid === '') return true;\n", ''],
        'other', function (array $x) { return $x['msg_nouuid'] === 1 && $x['msg'] === 1; }, 'the second message with no id was held back'],
];
$cases = ['client' => $clientCase, 'service' => $serviceCase, 'other' => $otherCase];
foreach ($withMutants ? $mutants : [] as $name => [$rel, [$old, $new], $case, $caught, $why]) {
    [$tree, $n] = sj_weakened_copy($root, $rel, $old, $new);
    if ($n !== 1) { is_(false, "caught: {$name}", "the anchor was not found exactly once in {$rel}"); exec('rm -rf ' . escapeshellarg($tree)); continue; }
    $wk = $start($tree, 'uganda', 'neo-wk');
    $x = $cases[$case]($wk);
    $ok = (bool)$caught($x);
    is_($ok, "caught: {$name}" . ($ok ? " ({$why})" : ''), json_encode($x));
    $wk->stop();
    exec('rm -rf ' . escapeshellarg($tree));
}

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail ? 1 : 0);
