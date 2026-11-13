<?php
declare(strict_types=1);
// driver.php ROOT CTL — TEST ONLY (scripts/harness/sales-number-console). A sandbox of the plugin at ROOT (SjSandbox: the real plugin under php -S, a fake
// Evolution, a fake uCRM) with three salesperson numbers added through the card's own form, switched off; the department
// numbers verified and the registry on. Then it serves commands from CTL (cmd-N.json → res-N.json) until 'quit', or
// until a file CTL/stop appears. ROOT is a checkout of the plugin release under test (its tests/fixtures are used).
// Every person, number and instance is fictitious; nothing leaves 127.0.0.1.
[$root, $ctl] = [rtrim($argv[1], '/'), rtrim($argv[2], '/')];
date_default_timezone_set('UTC');
require_once $root . '/tests/fixtures/staff_jobs_sandbox.php';
foreach (['bootstrap_data', 'StoreInterface', 'JsonStore', 'SqliteStore', 'StaffJobsGate', 'EvolutionApiService', 'ChannelRegistry'] as $c) require_once "$root/lib/$c.php";

$base = ['tenant_profile' => 'uganda', 'timezone' => 'UTC', 'ai_enabled' => '1', 'ai_currency' => 'UGX', 'ai_provider' => 'openai',
         'openai_api_key' => 'test-key-never-called', 'alert_whatsapp' => '256700000999', 'ai_media_enabled' => '0',
         'followup_enabled' => '1', 'plugin_public_url' => 'https://plugin.example.test'];
$s = SjSandbox::start($root, $base, 'snc');
$cfg = $s->cfg;
$setCfg = function (array $ov) use ($s, &$cfg): void {
    $cfg = array_merge($cfg, $ov);
    foreach ($ov as $k => $v) if ($v === null) unset($cfg[$k]);
    $s->store()->save('kyc_config.json', $cfg);
    file_put_contents($s->data . '/kyc_config.json', json_encode($cfg));
};
$s->staff('admin', ['name' => 'Sandbox Admin', 'email' => 'admin@example.test', 'role' => 'admin', 'is_admin' => true]);
$A = $s->staff('alpha', ['name' => 'Alpha Seller', 'email' => 'alpha@example.test', 'role' => 'sales', 'phone' => '0700000201']);
$B = $s->staff('bravo', ['name' => 'Bravo Seller', 'email' => 'bravo@example.test', 'role' => 'sales']);
$s->login('admin', 'admin@example.test', 'sj-password-1');
$instances = function (array $rows) use ($s): void { $s->http('POST', "{$s->evo}/__test/instances", $rows, ['Content-Type: application/json']); };
$INST = [
    ['name' => 'sj-sales', 'connectionStatus' => 'open', 'ownerJid' => '256700000901@s.whatsapp.net', 'profileName' => 'SN'],
    ['name' => 'sj-support', 'connectionStatus' => 'open', 'ownerJid' => '256700000903@s.whatsapp.net', 'profileName' => 'SN'],
    ['name' => 'sj-account', 'connectionStatus' => 'open', 'ownerJid' => '256700000904@s.whatsapp.net', 'profileName' => 'SN'],
    ['name' => 'sn-new', 'connectionStatus' => 'open', 'ownerJid' => '256700000701@s.whatsapp.net', 'profileName' => 'SN'],
    ['name' => 'kla-bravo-off', 'connectionStatus' => 'close', 'ownerJid' => '', 'profileName' => 'SN'],
    ['name' => 'sn-three', 'connectionStatus' => 'open', 'ownerJid' => '256700000702@s.whatsapp.net', 'profileName' => 'SN'],
];
$instances($INST);
$qs = 'page=dashboard&tab=wa_ai_setup';
$post = function (array $fields) use ($s, $qs): string {
    $r = $s->form('admin', $fields, $qs, $qs);
    return preg_match('#<div class="wa-msg (wa-good|wa-bad)">(.*?)</div>#s', $r[1], $m)
        ? ($m[1] === 'wa-good' ? 'ok: ' : 'no: ') . html_entity_decode(trim($m[2]), ENT_QUOTES) : 'none: ' . $r[0];
};
$setup = [];
$setup[] = $post(['wa_action' => 'sn_add', 'instance' => 'sn-new', 'owner_staff_id' => $A, 'display_name' => 'Sales — Alpha Two']);
$setup[] = $post(['wa_action' => 'sn_add', 'instance' => 'kla-bravo-off', 'owner_staff_id' => $B, 'display_name' => 'bravo-off']);
$setup[] = $post(['wa_action' => 'sn_add', 'instance' => 'sn-three', 'owner_staff_id' => $B, 'display_name' => 'Sales — Bravo Three']);
foreach (['sales', 'support', 'account'] as $d) $setup[] = $post(['wa_action' => 'sn_verify_department', 'channel_id' => $d]);
$setCfg([ChannelRegistry::FLAG => '1']);
$state = function () use ($s): array {
    $rows = [];
    foreach ($s->q('SELECT channel_id, status, ai_enabled, business_number, verified_at, evo_instance, owner_staff_id FROM wa_channels ORDER BY channel_id') as $r) $rows[$r['channel_id']] = $r;
    $evo = (array)($s->http('GET', "{$s->evo}/__test/state")[2] ?? []);
    return ['channels' => $rows, 'webhooks' => array_map(function ($w) { return (string)($w['url'] ?? ''); }, (array)($evo['webhooks'] ?? [])),
            'text_calls' => count((array)($evo['text_calls'] ?? [])), 'trail' => count($s->q('SELECT id FROM wa_channel_log'))];
};
@mkdir($ctl, 0700, true);
file_put_contents($s->plug . '/crm-outer.html', '<html><body><h1>outer page (as uCRM frames the plugin)</h1>'
    . '<iframe id="f" src="public.php?page=dashboard&amp;tab=wa_ai_setup" style="width:1200px;height:800px"></iframe></body></html>');
file_put_contents("$ctl/ready.json", json_encode(['base' => $s->base, 'evo' => $s->evo, 'setup' => $setup, 'state' => $state(), 'ids' => ['A' => $A, 'B' => $B]]));
for ($n = 1; $n < 500; $n++) {
    $f = "$ctl/cmd-$n.json";
    for ($i = 0; $i < 6000 && !is_file($f) && !is_file("$ctl/stop"); $i++) usleep(50000);
    if (!is_file($f)) break;
    usleep(20000);
    $c = (array)json_decode((string)file_get_contents($f), true);
    $res = ['ok' => true];
    switch ($c['do'] ?? '') {
        case 'state': $res = $state(); break;
        case 'form':  $res = ['answer' => $post((array)$c['fields'])]; break;
        case 'flag':  $setCfg([ChannelRegistry::FLAG => !empty($c['on']) ? '1' : null]); break;
        case 'staff': $s->update((int)$s->ids[(string)$c['who']], ['is_active' => !empty($c['active'])]); break;
        case 'instances':   // the fake Evolution's list, with one instance's owner changed or one instance left out
            $rows = [];
            foreach ($INST as $r) {
                if (in_array($r['name'], (array)($c['drop'] ?? []), true)) continue;
                if (isset($c['owner'][$r['name']])) $r['ownerJid'] = $c['owner'][$r['name']] . '@s.whatsapp.net';
                $rows[] = $r;
            }
            $instances($rows); break;
        case 'quit':  file_put_contents("$ctl/res-$n.json", json_encode($res)); break 2;
        default:      $res = ['ok' => false, 'error' => 'unknown command'];
    }
    file_put_contents("$ctl/res-$n.json.tmp", json_encode($res)); rename("$ctl/res-$n.json.tmp", "$ctl/res-$n.json");
}
$s->stop();
