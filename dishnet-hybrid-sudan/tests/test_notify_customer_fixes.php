<?php
declare(strict_types=1);
/**
 * test_notify_customer_fixes.php — 5.18.54, docs/46 rows 12-14 and 16 (D7, D-9, D-10, D-6).
 *
 * Through the real plugin under php -S (tests/fixtures/staff_jobs_sandbox.php): uCRM's webhook as uCRM sends it, the
 * staff screen as a signed-in account uses it, a fake uCRM and the fake Evolution.
 *
 *   1. D7: one message per credit note. The staff screen's is kept — it says whether the money came back in cash — and
 *      uCRM's credit_note.add for the same note sends nothing; a note made in uCRM itself is announced once, however
 *      often the event is delivered. The amount is in the tenant's currency, never "$".
 *   2. D-9: the activation message no longer promises login details by e-mail, which nothing sends; it says how to sign
 *      in to the DishNet portal.
 *   3. D-10: a national number ("0772 …") goes to WhatsApp in international form; an opt-out recorded in either form
 *      still blocks; a number that cannot be read is a failed row in the Message Log, never a send.
 *   4. D-6 (and N-7): a draft invoice, and a client-zone invitation, no longer suspend anyone's identity mailbox; a
 *      deleted or archived client still is.
 *   5. South Sudan: 5.18.53 unchanged in each (docs/46 §E).
 *   6. Weakened copies, each caught (skipped with --no-mutants).
 *
 * What this proves is what the plugin hands to WhatsApp and writes in its tables, never delivery to a phone. Every
 * person, number and document is fictitious; nothing leaves the machine.
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

const C15 = '256700000915';   // SjScenario's customer, an international number

/** A sandbox with SjScenario's accounts and customers, and four more customers for D-10. */
$start = function (string $tree, string $tenant, string $tag): SjSandbox {
    $base = $tenant === 'uganda' ? ['tenant_profile' => 'uganda', 'timezone' => 'Africa/Kampala'] : ['timezone' => 'Africa/Juba'];
    $s = SjSandbox::start($tree, $base + ['identity_enabled' => '1'], $tag);
    file_put_contents($s->plug . '/ucrm.json', json_encode(['pluginDataDir' => $s->data, 'pluginPublicUrl' => $s->base]));
    $crm = SjScenario::crm();
    $client = function (int $id, string $phone): array {
        return ['id' => $id, 'firstName' => 'Customer', 'lastName' => (string)$id, 'isLead' => false,
                'contacts' => [['email' => "c{$id}@example.test", 'phone' => $phone, 'isBilling' => true]]];
    };
    $crm['clients']['16'] = $client(16, '+256700000916');
    $crm['clients']['17'] = $client(17, '0772 000 917');   // national form
    $crm['clients']['18'] = $client(18, '0772 000 918');   // opted out, recorded as typed
    $crm['clients']['19'] = $client(19, '0772 000 919');   // opted out, recorded in international form
    $crm['clients']['20'] = $client(20, '12345');          // no number WhatsApp can use
    $s->seedCrm($crm);
    SjScenario::staff($s);
    return $s;
};
$textsTo = function (SjSandbox $s, string $to, int $from = 0): array {
    return array_values(array_filter(array_slice($s->texts(), $from), function ($t) use ($to) { return $t['number'] === $to; }));
};

// ── The cases, as functions of the sandbox, so a weakened copy runs the very same ones ──────────────────────────────

/** D7: a credit note from the staff screen, then uCRM's event for it; then a note made in uCRM, its event twice. */
$creditCase = function (SjSandbox $s) use ($textsTo): array {
    $o = [];
    $jar = $s->login('admin', 'admin@example.test', 'sj-password-1');
    $n = count($s->texts());
    $o['r'] = $s->http('POST', "{$s->base}?page=api&action=issue_credit_note",
        ['client_id' => 15, 'amount' => 50000, 'reason' => 'Sandbox goodwill credit', 'refund_type' => 'credit_note'],
        ['Content-Type: application/json'], $jar);
    $cn = (int)($o['r'][2]['data']['credit_note_id'] ?? 0);
    $o['staff'] = $textsTo($s, C15, $n);
    $n = count($s->texts());
    $o['ev1'] = $s->fire('credit_note.add', 'creditNote', $cn, 'd7-staff-1');
    $o['ev1b'] = $s->fire('credit_note.add', 'creditNote', $cn, 'd7-staff-2');
    $o['after_staff_events'] = $textsTo($s, C15, $n);

    $s->seedCrm(['credit_notes' => ['4501' => ['id' => 4501, 'number' => 'CN-4501', 'clientId' => 15, 'total' => 20000.0, 'note' => '']]]);
    $n = count($s->texts());
    $s->fire('credit_note.add', 'creditNote', 4501, 'd7-ucrm-1');
    $o['ucrm_first'] = $textsTo($s, C15, $n);
    $n = count($s->texts());
    $s->fire('credit_note.add', 'creditNote', 4501, 'd7-ucrm-2');   // uCRM delivers it again
    $o['ucrm_again'] = $textsTo($s, C15, $n);
    return $o;
};

/** D-9 and D-10: uCRM's service.add for five customers, one number each way. */
$serviceCase = function (SjSandbox $s) use ($textsTo): array {
    $svc = function (int $id, int $client): array { return ['id' => $id, 'clientId' => $client, 'name' => 'Sandbox Home 100', 'status' => 1]; };
    $s->seedCrm(['services' => ['7715' => $svc(7715, 15), '7717' => $svc(7717, 17), '7718' => $svc(7718, 18),
                                '7719' => $svc(7719, 19), '7720' => $svc(7720, 20)]]);
    $s->q("INSERT INTO contact_optouts (phone, channel, scope, reason, source, created_by) VALUES ('0772000918', '*', 'all', 'customer_request', 'admin', 'test')");
    $s->q("INSERT INTO contact_optouts (phone, channel, scope, reason, source, created_by) VALUES ('256772000919', '*', 'all', 'customer_request', 'keyword', 'test')");
    $o = [];
    $n = count($s->texts());
    foreach ([15, 17, 18, 19, 20] as $c) $o['r' . $c] = $s->fire('service.add', 'service', 7700 + $c, "d9-svc-{$c}");
    $o['all'] = array_slice($s->texts(), $n);
    $o['c15'] = $textsTo($s, C15, $n);
    try { $o['log20'] = $s->q("SELECT phone, success, error FROM notification_audit_log WHERE event = 'ops_service_activated' AND phone = '12345'"); }
    catch (\Throwable $e) { $o['log20'] = []; }
    return $o;
};

/** D-6: a draft invoice whose id is a client's, a client-zone invitation, an archive. */
$identityCase = function (SjSandbox $s): array {
    $s->q("INSERT INTO customer_identities (client_id, email, local_part, status) VALUES (15, 'c15@identity.test', 'c15', 'provisioned'), (16, 'c16@identity.test', 'c16', 'provisioned')");
    $pending = function (int $c) use ($s): string {
        return (string)($s->q('SELECT pending_action FROM customer_identities WHERE client_id = ?', [$c])[0]['pending_action'] ?? 'none');
    };
    $o = [];
    $o['r_draft']  = $s->fire('invoice.add_draft', 'invoice', 16, 'd6-draft-1');     // invoice 16, not client 16
    $o['draft']    = $pending(16);
    $o['r_invite'] = $s->fire('client.invite', 'client', 15, 'd6-invite-1');
    $o['invite']   = $pending(15);
    $o['r_arch']   = $s->fire('client.archive', 'client', 16, 'd6-archive-1');
    $o['archive']  = $pending(16);
    return $o;
};

// ══════════════════════════════════════════════════════════════════════════════
echo "\n1. Uganda — D7: one message per credit note, in the tenant's currency\n";
$u = $start($root, 'uganda', 'ncf');
$c = $creditCase($u);
is_((int)$c['r'][0] === 200 && (int)($c['r'][2]['data']['credit_note_id'] ?? 0) > 0, 'the staff screen creates the note in uCRM', json_encode($c['r'][2] ?? null));
$st = $c['staff'][0]['text'] ?? '';
is_(count($c['staff']) === 1 && strpos($st, '💳 *Credit Note — DishNet Africa*') === 0 && strpos($st, 'A credit of *UGX 50000* has been applied') !== false,
    'the customer gets the staff screen\'s message, with the amount in UGX', json_encode($c['staff'], JSON_UNESCAPED_UNICODE));
is_(strpos($st, '$') === false, 'and no dollar sign anywhere in it');
is_($c['after_staff_events'] === [] && (int)$c['ev1'][0] === 200 && (int)$c['ev1b'][0] === 200,
    'uCRM\'s credit_note.add for that note, delivered twice: nothing more', json_encode($c['after_staff_events'], JSON_UNESCAPED_UNICODE));
is_(count($c['ucrm_first']) === 1 && strpos($c['ucrm_first'][0]['text'], '💳 *Credit Note Issued*') === 0
    && strpos($c['ucrm_first'][0]['text'], 'UGX 20000') !== false, 'a note made in uCRM itself: one message', json_encode($c['ucrm_first'], JSON_UNESCAPED_UNICODE));
is_($c['ucrm_again'] === [], 'and its event delivered again sends no second');

echo "\n2. Uganda — D-9 and D-10: the activation message, to the right number\n";
$v = $serviceCase($u);
$act = $v['c15'][0]['text'] ?? '';
is_(count($v['c15']) === 1 && strpos($act, '🚀 *Service Activated — DishNet Africa*') === 0, 'the activation message goes', json_encode($v['c15'], JSON_UNESCAPED_UNICODE));
is_(strpos($act, 'shared via email') === false, 'it no longer says login details were e-mailed (nothing sends them)');
is_(strpos($act, "🔑 To sign in to your DishNet account, open this link and enter your phone number. We send you a one-time code; there is no password to remember.\n🔗 {$u->base}?page=customer_login\n") !== false,
    'it says how to sign in: the DishNet portal, a phone number and a one-time code', $act);
$nums = array_column($v['all'], 'number');
is_(in_array('256772000917', $nums, true) && !in_array('0772000917', $nums, true), 'a national number goes in international form: 0772 000 917 → 256772000917', json_encode($nums));
is_(!array_filter($nums, function ($x) { return in_array($x, ['256772000918', '0772000918'], true); }), 'an opt-out recorded as the number was typed still blocks it');
is_(!array_filter($nums, function ($x) { return in_array($x, ['256772000919', '0772000919'], true); }), 'an opt-out recorded in international form blocks the national number too');
is_(!in_array('12345', $nums, true) && count($v['log20']) === 1 && (int)$v['log20'][0]['success'] === 0
    && strpos((string)$v['log20'][0]['error'], 'not a number WhatsApp can use') === 0,
    'a number that cannot be read: not sent, a failed row that says why', json_encode($v['log20']));
is_(in_array(C15, $nums, true), 'an international number is sent as it is (control)');

echo "\n3. Uganda — D-6: a draft invoice suspends no one\n";
$i = $identityCase($u);
is_((int)$i['r_draft'][0] === 200 && $i['draft'] === 'none', 'invoice.add_draft for invoice 16: client 16\'s mailbox is untouched', $i['draft'] . ' ' . $i['r_draft'][1]);
is_($i['invite'] === 'none', 'client.invite: the invited customer\'s mailbox is untouched', $i['invite']);
is_($i['archive'] === 'suspend', 'client.archive still suspends (control)', $i['archive']);
$u->stop();

// ══════════════════════════════════════════════════════════════════════════════
echo "\n4. South Sudan: 5.18.53 unchanged (docs/46 §E)\n";
$ss = $start($root, 'south-sudan', 'ncf-ss');
$sc = $creditCase($ss);
is_(count($sc['staff']) === 1 && strpos($sc['staff'][0]['text'], 'A credit of *$50000* has been applied') !== false, 'the staff screen\'s message still says "$"',
    json_encode($sc['staff'], JSON_UNESCAPED_UNICODE));
is_(count($sc['after_staff_events']) === 2, 'and uCRM\'s event still adds its own message, each time it is delivered (two here)', (string)count($sc['after_staff_events']));
is_(count($sc['ucrm_first']) === 1 && count($sc['ucrm_again']) === 1, 'a uCRM note announced again on redelivery');
$sv = $serviceCase($ss);
$snums = array_column($sv['all'], 'number');
is_(strpos($sv['c15'][0]['text'] ?? '', '🔑 Login credentials have been shared via email.') !== false, 'the activation text as it was');
is_(in_array('0772000917', $snums, true) && in_array('12345', $snums, true) && in_array('0772000919', $snums, true),
    'numbers still go as typed, and an opt-out in another form does not match (D-10 awaits approval there)', json_encode($snums));
is_(!in_array('0772000918', $snums, true), 'an opt-out in the same form still blocks (control)');
$si = $identityCase($ss);
is_($si['draft'] === 'suspend' && $si['invite'] === 'suspend', 'a draft invoice and an invitation still suspend a mailbox there (D-6 awaits approval)',
    json_encode([$si['draft'], $si['invite']]));
$ss->stop();

// ══════════════════════════════════════════════════════════════════════════════
echo "\n5. Weakened copies, each caught\n";
/** A weakened copy with several changes in one file, each anchor found exactly once. */
$weaken = function (string $rel, array $pairs) use ($root): array {
    [$old0, $new0] = array_shift($pairs);
    [$tree, $n] = sj_weakened_copy($root, $rel, $old0, $new0);
    foreach ($pairs as [$old, $new]) {
        $src = (string)file_get_contents($tree . '/' . $rel);
        if (substr_count($src, $old) !== 1) { $n = 0; break; }
        file_put_contents($tree . '/' . $rel, str_replace($old, $new, $src));
    }
    return [$tree, $n];
};
// Each predicate names the defect itself, with a control where the defect is an absence, so a copy that merely crashed
// is never counted as caught.
$mutants = [
    'uCRM\'s event announces a staff-made note again (D7)' => ['webhook.php', [
        ["                    && !\$notify->dedupMark('CN' . \$creditNoteId)) {", "                    && false) {"]],
        'credit', function (array $x) { return count($x['staff']) === 1 && count($x['after_staff_events']) > 0; }, 'a second message for one note'],
    'the staff screen does not claim its note (D7)' => ['includes/api/api_payments_admin.php', [
        ["            \$notify->dedupMark('CN' . \$cnId);\n", '']],
        'credit', function (array $x) { return count($x['staff']) === 1 && count($x['after_staff_events']) > 0; }, 'uCRM\'s message followed the staff one'],
    'the credit note in dollars again (D7)' => ['includes/api/api_payments_admin.php', [
        ["        \$_cnAmt    = \$_cnOnce ? dn_money(\$amount, is_array(\$config ?? null) ? \$config : [], null) : \"\\\${\$amount}\";",
         "        \$_cnAmt    = \"\\\${\$amount}\";"]],
        'credit', function (array $x) { return strpos($x['staff'][0]['text'] ?? '', '*$50000*') !== false; }, 'the customer read $50000'],
    'the e-mail promise back (D-9)' => ['webhook.php', [
        ["            \$_d9Login = NotifyGate::applies(NotifyGate::ACTIVATION,", "            \$_d9Login = false && NotifyGate::applies(NotifyGate::ACTIVATION,"]],
        'service', function (array $x) { return strpos($x['c15'][0]['text'] ?? '', 'shared via email') !== false; }, 'the text promises an e-mail'],
    'numbers sent as typed (D-10)' => ['lib/NotificationService.php', [
        ["        if (!\$on || \$raw === '') return [\$raw, \$raw];", "        return [\$raw, \$raw];"]],
        'service', function (array $x) { return in_array('0772000917', array_column($x['all'], 'number'), true); }, '0772000917 went as typed'],
    'an opt-out in the typed form no longer blocks (D-10)' => ['lib/NotificationService.php', [
        ["        if (\$asGiven !== \$to && \$asGiven !== '' && \$this->optedOut(\$asGiven, \$sender, \$class, \$event)) return;\n", '']],
        'service', function (array $x) { return in_array('256772000918', array_column($x['all'], 'number'), true); }, 'the opted-out customer was sent it'],
    // 5.18.83 (docs/64 §B): the unusable-number log keeps the storable text, so the anchor follows the line; the weakened
    // copy — the number sent anyway — is unchanged.
    'an unreadable number sent anyway (D-10)' => ['lib/NotificationService.php', [
        ["        if (\$to === '' && \$asGiven !== '') { \$this->unusableNumber(\$sender, \$event, \$asGiven, self::storable(\$message)); return; }",
         "        if (\$to === '' && \$asGiven !== '') \$to = \$asGiven;"]],
        'service', function (array $x) { return in_array('12345', array_column($x['all'], 'number'), true); }, '12345 was sent to'],
    'a draft invoice suspends a mailbox again (D-6)' => ['webhook.php', [
        ["        if (in_array(\$changeType, ['invoice.add_draft', 'client.invite'], true)) {", "        if (false) {"]],
        'identity', function (array $x) { return $x['draft'] === 'suspend' && $x['archive'] === 'suspend'; }, 'client 16 suspended by an invoice'],
];
$cases = ['credit' => $creditCase, 'service' => $serviceCase, 'identity' => $identityCase];
foreach ($withMutants ? $mutants : [] as $name => [$rel, $pairs, $case, $caught, $why]) {
    [$tree, $n] = $weaken($rel, $pairs);
    if ($n !== 1) { is_(false, "caught: {$name}", "an anchor was not found exactly once in {$rel}"); exec('rm -rf ' . escapeshellarg($tree)); continue; }
    $wk = $start($tree, 'uganda', 'ncf-wk');
    $x = $cases[$case]($wk);
    $ok = (bool)$caught($x);
    is_($ok, "caught: {$name}" . ($ok ? " ({$why})" : ''));
    $wk->stop();
    exec('rm -rf ' . escapeshellarg($tree));
}

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail ? 1 : 0);
