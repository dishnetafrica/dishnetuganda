<?php
declare(strict_types=1);
/**
 * test_channel_registry.php — 5.18.86 (docs/65, multi-number Batch 1, parts D, E, F, G, M, O, P): the WhatsApp channel
 * registry, its resolver and ChannelContext, in process, against a store built by the plugin's own migrations (087).
 *
 *   A  migration 087: the table, the three department rows seeded under the ids conversations already carry, with NO
 *      instance stored (configuration stays where it is), every constraint and trigger, and nothing else touched
 *   B  the switch: multi_number_channels_enabled AND Uganda, nothing else
 *   C  OFF — and ON outside Uganda — forStore() is exactly the constructor's service, for every configuration shape
 *   D  ON: tests 1–6 of Part Q — sales, support, account resolve; an unknown instance and a disabled channel are
 *      refused; a channel resolves to its instance; never a guess, never another channel
 *   E  writes: validation, the trail, idempotence, masking
 *   F  ChannelContext: the brain may learn the role, never the instance or the number
 *   G  no credential and no whole number in anything this layer logs or prints; the read-only CLI; the settings tool
 *   H  Domain B and the Domain B documents are untouched
 *   W  weakened copies, each re-running this file as a driver against a copy with one guard removed
 *
 * Driver mode: php tests/test_channel_registry.php --driver <plugin-root>  → one JSON line of facts.
 */
$self     = __FILE__;
$isDriver = in_array('--driver', $argv ?? [], true);
$root     = $isDriver ? (string)$argv[array_search('--driver', $argv, true) + 1] : dirname(__DIR__);
date_default_timezone_set('UTC');

require_once $root . '/lib/bootstrap_data.php';
require_once $root . '/lib/StoreInterface.php';
require_once $root . '/lib/SqliteStore.php';
require_once $root . '/lib/StaffJobsGate.php';
require_once $root . '/lib/EvolutionApiService.php';
require_once $root . '/lib/ChannelRegistry.php';

/** A fresh data directory and store; its tables come from the plugin's own migrations. */
function cr_store(string $tag): array
{
    $tmp = sys_get_temp_dir() . '/dn_cr_' . $tag . '_' . bin2hex(random_bytes(4));
    @mkdir($tmp, 0777, true);
    putenv('DN_DATA_DIR=' . $tmp);
    putenv('DN_VAULT_FILE=' . $tmp . '/vault.json');
    $store = SqliteStore::create($tmp);
    return [$tmp, $store, $store->getPdo()];
}

function cr_try(callable $f): string
{
    try { $f(); return 'ok'; } catch (\Throwable $e) { return 'refused: ' . $e->getMessage(); }
}

/** The whole scenario; facts only, so a weakened copy can run it too. */
function cr_scenario(string $root): array
{
    $f   = [];
    $log = sys_get_temp_dir() . '/dn_cr_log_' . bin2hex(random_bytes(4)) . '.log';
    ini_set('error_log', $log);

    $UG  = ['tenant_profile' => 'uganda', 'evo_api_url' => 'http://127.0.0.1:9', 'evo_api_key' => 'CR-SECRET-EVO-KEY-0042',
            'evo_instance_sales' => 'ug-sales', 'evo_instance_support' => 'ug-support', 'evo_instance_account' => 'ug-account'];
    $ON  = $UG + [ChannelRegistry::FLAG => '1'];
    $SS  = ['tenant_profile' => 'south-sudan'] + $ON;
    $SS['tenant_profile'] = 'south-sudan';

    [$dir, $store, $pdo] = cr_store('main');

    // ── A. Migration 087 ─────────────────────────────────────────────────────────────────────────
    $rows = $pdo->query('SELECT * FROM wa_channels ORDER BY channel_id')->fetchAll(PDO::FETCH_ASSOC);
    $f['a_ids'] = array_column($rows, 'channel_id');
    $f['a_instances'] = array_values(array_unique(array_map(function ($r) { return $r['evo_instance']; }, $rows)));
    $f['a_shape'] = array_map(function ($r) { return [$r['channel_id'], $r['role'], $r['owner_type'], $r['status'], (int)$r['ai_enabled'],
                                                     $r['business_number'], $r['portfolio_scope'], $r['handover_to']]; }, $rows);
    $f['a_seed_log'] = (int)$pdo->query("SELECT COUNT(*) FROM wa_channel_log WHERE action = 'seeded'")->fetchColumn();
    $mig = (string)file_get_contents($root . '/migrations/087_wa_channels.sql');
    // Applied a second time, as an interrupted install would: nothing doubles.
    require_once $root . '/lib/MigrationRunner.php';
    foreach (MigrationRunner::splitStatements(MigrationRunner::stripComments($mig)) as $st) {
        try { $pdo->exec($st); } catch (\Throwable $e) { $f['a_rerun_error'] = $e->getMessage(); }
    }
    $f['a_rerun_rows'] = (int)$pdo->query('SELECT COUNT(*) FROM wa_channels')->fetchColumn();
    $f['a_rerun_log']  = (int)$pdo->query('SELECT COUNT(*) FROM wa_channel_log')->fetchColumn();
    $sql = strtoupper(MigrationRunner::stripComments($mig));
    $f['a_touches_other'] = (bool)preg_match('/\b(ALTER\s+TABLE|DROP\s|UPDATE\s+(?!ON\b)|DELETE\s+FROM)\b/', preg_replace('/BEFORE (UPDATE|DELETE) ON/', '', $sql))
                         || (bool)preg_match('/\bWA_CONVERSATIONS\b|\bWA_MESSAGES\b|\bLEADS\b|\bEVENTS\b/', $sql);
    $f['a_secret_words'] = (bool)preg_match('/api_?key|token|password|secret/i', MigrationRunner::stripComments($mig));
    $refuse = function (string $sql) use ($pdo): string { try { $pdo->exec($sql); return 'ALLOWED'; } catch (\Throwable $e) { return 'refused'; } };
    $f['a_checks'] = [
        'null_instance_non_dept' => $refuse("INSERT INTO wa_channels (channel_id, display_name, role) VALUES ('x-1', 'X', 'sales')"),
        'blank_instance'         => $refuse("INSERT INTO wa_channels (channel_id, evo_instance, display_name, role) VALUES ('x-2', '  ', 'X', 'sales')"),
        'bad_role'               => $refuse("INSERT INTO wa_channels (channel_id, evo_instance, display_name, role) VALUES ('x-3', 'i-3', 'X', 'marketing')"),
        'bad_status'             => $refuse("INSERT INTO wa_channels (channel_id, evo_instance, display_name, role, status) VALUES ('x-4', 'i-4', 'X', 'sales', 'on')"),
        'staff_without_id'       => $refuse("INSERT INTO wa_channels (channel_id, evo_instance, display_name, role, owner_type) VALUES ('x-5', 'i-5', 'X', 'sales', 'staff')"),
        'dept_with_partner'      => $refuse("INSERT INTO wa_channels (channel_id, evo_instance, display_name, role, owner_partner_id) VALUES ('x-6', 'i-6', 'X', 'sales', 3)"),
        'ai_two'                 => $refuse("INSERT INTO wa_channels (channel_id, evo_instance, display_name, role, ai_enabled) VALUES ('x-7', 'i-7', 'X', 'sales', 2)"),
        'delete_channel'         => $refuse("DELETE FROM wa_channels WHERE channel_id = 'support'"),
        'update_trail'           => $refuse("UPDATE wa_channel_log SET actor = 'someone else'"),
        'delete_trail'           => $refuse('DELETE FROM wa_channel_log'),
    ];
    $pdo->exec("INSERT INTO wa_channels (channel_id, evo_instance, display_name, role, status) VALUES ('x-8', 'Dup-Inst', 'X', 'sales', 'retired')");
    $f['a_checks']['instance_case_unique'] = $refuse("INSERT INTO wa_channels (channel_id, evo_instance, display_name, role) VALUES ('x-9', 'dup-inst', 'X', 'sales')");
    $f['a_after_probes'] = (int)$pdo->query("SELECT COUNT(*) FROM wa_channels WHERE channel_id LIKE 'x-%'")->fetchColumn();

    // ── B. The switch ─────────────────────────────────────────────────────────────────────────
    $f['b_gate'] = [
        'absent_ug'   => ChannelRegistry::enabled($UG, $dir),
        'on_ug'       => ChannelRegistry::enabled($ON, $dir),
        'off_word_ug' => ChannelRegistry::enabled([ChannelRegistry::FLAG => 'false'] + $UG, $dir),
        'on_ss'       => ChannelRegistry::enabled($SS, $dir),
        'on_unknown'  => ChannelRegistry::enabled([ChannelRegistry::FLAG => '1', 'tenant_profile' => 'no-such-country'], $dir . '-none'),
    ];

    // ── C. OFF, and ON outside Uganda: the constructor's service, exactly ──────────────────────
    $shapes = [
        'three'   => $UG,
        'legacy'  => ['evo_api_url' => 'http://h', 'evo_api_key' => 'k', 'evo_instance_name' => 'Old-Support', 'evo_accounts_instance_name' => 'old-acct', 'tenant_profile' => 'uganda'],
        'shared'  => ['evo_api_url' => 'http://h', 'evo_api_key' => 'k', 'evo_instance_sales' => 'one', 'evo_instance_support' => 'ONE', 'evo_instance_account' => 'one', 'tenant_profile' => 'uganda'],
        'none'    => ['tenant_profile' => 'uganda'],
    ];
    $same = [];
    foreach ($shapes as $k => $cfg) {
        foreach (['off' => $cfg, 'ss_on' => ['tenant_profile' => 'south-sudan', ChannelRegistry::FLAG => '1'] + $cfg] as $mode => $c) {
            if ($mode === 'ss_on') $c['tenant_profile'] = 'south-sudan';
            $a = new EvolutionApiService($c);
            $b = EvolutionApiService::forStore($c, $pdo, $dir);
            $probe = function ($e) { return [$e->describe(), $e->configuredChannels(), $e->channelFor('ug-sales'), $e->channelFor('ONE'),
                                            $e->channelFor('old-support'), $e->instanceFor('sales'), $e->instanceFor('support'),
                                            $e->instanceFor('account'), $e->registryOn(), $e->channelFor('sales-002')]; };
            $same[$k . '_' . $mode] = $probe($a) === $probe($b);
        }
    }
    $f['c_same'] = $same;
    $f['c_map_matches_constructor'] = EvolutionApiService::configInstanceMap($shapes['legacy'])
        === ['sales' => '', 'support' => 'Old-Support', 'account' => 'old-acct'];

    // ── D. ON (Uganda) — Part Q tests 1–6 ───────────────────────────────────────────────────────
    $reg = new ChannelRegistry($pdo, $store);
    $staffRow = $store->appendWithId('retailers.json', ['name' => 'Registry Test Seller', 'is_active' => true, 'role' => 'sales']);
    $staffId  = (int)($staffRow['id'] ?? 0);
    $evo = EvolutionApiService::forStore($ON, $pdo, $dir);
    $f['d1_sales']   = [$evo->channelFor('ug-sales'), $evo->channelFor('UG-SALES'), $evo->channelContext('sales') ? $evo->channelContext('sales')->role() : null];
    $f['d2_support'] = [$evo->channelFor('ug-support'), $evo->channelContext('support') ? $evo->channelContext('support')->role() : null];
    $f['d3_account'] = [$evo->channelFor('ug-account'), $evo->channelContext('account') ? $evo->channelContext('account')->role() : null];
    $f['d4_unknown'] = [$evo->channelFor('ug-nobody'), $evo->instanceState('ug-nobody'), $evo->channelFor('x-8'), $evo->instanceState('dup-inst')];
    $f['d_registry_on'] = $evo->registryOn();
    $f['d6_dept_instances'] = [$evo->instanceFor('sales'), $evo->instanceFor('support'), $evo->instanceFor('account')];

    // A second sales number, owned by a member of staff. Created disabled: switched on deliberately, never by creation.
    $f['d_create'] = cr_try(function () use ($reg, $staffId) {
        $reg->create(['channel_id' => 'sales-002', 'evo_instance' => 'ug-sales-2', 'display_name' => 'Sales — Kampala 2', 'role' => 'sales',
                      'owner_type' => 'staff', 'owner_staff_id' => $staffId, 'business_number' => '+256700111222'],
                     'registry test', 'Batch 1 test channel', EvolutionApiService::configInstanceMap($GLOBALS['__cr_on']));
    });
    $evo = EvolutionApiService::forStore($ON, $pdo, $dir);
    $f['d_created_state'] = [$evo->channelFor('ug-sales-2'), $evo->instanceState('ug-sales-2'), $evo->instanceFor('sales-002')];
    $reg->setStatus('sales-002', 'active', 'registry test', 'switched on for the test');
    $evo = EvolutionApiService::forStore($ON, $pdo, $dir);
    $ctx = $evo->channelContext('sales-002');
    $f['d6_channel_instance'] = [$evo->channelFor('ug-sales-2'), $evo->instanceFor('sales-002'), $ctx ? $ctx->role() : null,
                                 $ctx ? $ctx->ownerType() : null, $ctx ? $ctx->ownerId() : null];
    $f['d_reply_route'] = [
        'match'      => $evo->replyRoute('sales-002', 'UG-SALES-2')['reason'],
        'ok'         => $evo->replyRoute('sales-002', 'ug-sales-2')['ok'],
        'mismatch'   => $evo->replyRoute('sales-002', 'ug-sales')['reason'],
        'no_inbound' => $evo->replyRoute('sales-002', '')['reason'],
        'unknown'    => $evo->replyRoute('nobody', 'ug-sales')['reason'],
        'dept_ok'    => $evo->replyRoute('sales', 'ug-sales')['ok'],
    ];
    foreach (['paused', 'disabled', 'retired'] as $st) {
        $reg->setStatus('sales-002', $st, 'registry test', 'test: ' . $st);
        $e = EvolutionApiService::forStore($ON, $pdo, $dir);
        $f['d5_' . $st] = [$e->channelFor('ug-sales-2'), $e->instanceState('ug-sales-2'), $e->instanceFor('sales-002'),
                          $e->replyRoute('sales-002', 'ug-sales-2')['reason'], $e->channelFor('ug-sales')];
    }
    $reg->setStatus('sales-002', 'active', 'registry test', 'back on');
    $reg->setAiEnabled('sales-002', false, 'registry test', 'assistant off for the test');
    $e = EvolutionApiService::forStore($ON, $pdo, $dir);
    $f['d_ai_off'] = [$e->channelFor('ug-sales-2'), $e->channelAllowsAi('sales-002'), $e->channelAllowsAi('sales'),
                      $e->replyRoute('sales-002', 'ug-sales-2')['reason']];
    $reg->setAiEnabled('sales-002', true, 'registry test', 'assistant back on');

    // A department number switched off: refused, never handed to another department.
    $reg->setStatus('support', 'disabled', 'registry test', 'test');
    $e = EvolutionApiService::forStore($ON, $pdo, $dir);
    $f['d5_dept'] = [$e->channelFor('ug-support'), $e->instanceState('ug-support'), $e->instanceFor('support'), $e->channelFor('ug-sales')];
    $reg->setStatus('support', 'active', 'registry test', 'back on');
    $shared = ['evo_api_url' => 'http://h', 'evo_api_key' => 'k', 'evo_instance_sales' => 'one', 'evo_instance_support' => 'one', 'tenant_profile' => 'uganda', ChannelRegistry::FLAG => '1'];
    $reg->setStatus('sales', 'disabled', 'registry test', 'test');
    $e = EvolutionApiService::forStore($shared, $pdo, $dir);
    $f['d_shared_owner_off'] = [$e->channelFor('one'), $e->instanceState('one'), $e->instanceFor('support'), $e->instanceFor('sales')];
    $reg->setStatus('sales', 'active', 'registry test', 'back on');

    // A registry row naming a department number's instance (written past the registry's own check) is never routed.
    $pdo->exec("INSERT INTO wa_channels (channel_id, evo_instance, display_name, role, status) VALUES ('sales-009', 'UG-Sales', 'Clash', 'sales', 'active')");
    // And a department row carrying an instance of its own is ignored: configuration decides.
    $pdo->exec("UPDATE wa_channels SET evo_instance = 'ug-guessed' WHERE channel_id = 'account'");
    $e = EvolutionApiService::forStore($ON, $pdo, $dir);
    $f['d_conflict'] = [$e->channelFor('ug-sales'), $e->instanceFor('sales-009'), $e->channelContext('sales-009') === null,
                        $e->channelFor('ug-guessed'), $e->instanceFor('account'), $e->channelFor('ug-account')];
    $pdo->exec("UPDATE wa_channels SET evo_instance = NULL WHERE channel_id = 'account'");
    $pdo->exec("UPDATE wa_channels SET status = 'retired', evo_instance = 'retired-clash' WHERE channel_id = 'sales-009'");

    // ── E. Writes ───────────────────────────────────────────────────────────────────────────────
    $count = function () use ($pdo): array { return [(int)$pdo->query('SELECT COUNT(*) FROM wa_channels')->fetchColumn(),
                                                     (int)$pdo->query('SELECT COUNT(*) FROM wa_channel_log')->fetchColumn()]; };
    $before = $count();
    $cm = EvolutionApiService::configInstanceMap($ON);
    $mk = function (array $o) use ($reg, $cm) {
        return cr_try(function () use ($reg, $cm, $o) {
            $reg->create($o + ['channel_id' => 'sales-010', 'evo_instance' => 'ug-sales-10', 'display_name' => 'S10', 'role' => 'sales'], 'registry test', 'probe', $cm);
        });
    };
    $f['e_refusals'] = [];
    foreach (['sales', 'support', 'account', 'accounts', 'web', 'marketing', 'Sales-2', '2nd', 'a', 'x_y', 'sales 2'] as $bad) {
        $f['e_refusals']['id:' . $bad] = strpos($mk(['channel_id' => $bad]), 'refused') === 0;
    }
    $f['e_refusals']['dept_instance']     = strpos($mk(['evo_instance' => 'UG-SUPPORT']), 'refused') === 0;
    $f['e_refusals']['taken_instance']    = strpos($mk(['evo_instance' => 'UG-SALES-2']), 'refused') === 0;
    $f['e_refusals']['bad_instance']      = strpos($mk(['evo_instance' => 'has space']), 'refused') === 0;
    $f['e_refusals']['bad_role']          = strpos($mk(['role' => 'billing']), 'refused') === 0;
    $f['e_refusals']['bad_status']        = strpos($mk(['status' => 'live']), 'refused') === 0;
    $f['e_refusals']['staff_missing']     = strpos($mk(['owner_type' => 'staff', 'owner_staff_id' => 999999]), 'refused') === 0;
    $f['e_refusals']['partner_missing']   = strpos($mk(['owner_type' => 'partner', 'owner_partner_id' => 999999]), 'refused') === 0;
    $f['e_refusals']['region_missing']    = strpos($mk(['territory_region_id' => 999999]), 'refused') === 0;
    $f['e_refusals']['number_bad']        = strpos($mk(['business_number' => '0700 111 222']), 'refused') === 0;
    $f['e_refusals']['number_taken']      = strpos($mk(['business_number' => '256700111222']), 'refused') === 0;
    $f['e_refusals']['no_actor']          = strpos(cr_try(function () use ($reg) { $reg->setStatus('sales-002', 'paused', '  ', 'x'); }), 'refused') === 0;
    $f['e_refusals']['dept_set_instance'] = strpos(cr_try(function () use ($reg, $cm) { $reg->setInstance('sales', 'other', 'registry test', 'x', $cm); }), 'refused') === 0;
    $f['e_refusals']['move_to_dept_inst'] = strpos(cr_try(function () use ($reg, $cm) { $reg->setInstance('sales-002', 'ug-account', 'registry test', 'x', $cm); }), 'refused') === 0;
    $f['e_refusals']['unknown_channel']   = strpos(cr_try(function () use ($reg) { $reg->setStatus('nobody', 'active', 'registry test', 'x'); }), 'refused') === 0;
    $f['e_nothing_written'] = $count() === $before;
    $n0 = $count();
    $reg->setStatus('sales-002', 'active', 'registry test', 'already active');       // no change → no trail row
    $reg->setAiEnabled('sales-002', true, 'registry test', 'already on');
    $f['e_idempotent'] = $count() === $n0;
    $reg->setInstance('sales-002', 'ug-sales-2b', 'registry test', 'the SIM moved', $cm);
    $e = EvolutionApiService::forStore($ON, $pdo, $dir);
    $f['e_moved'] = [$e->channelFor('ug-sales-2b'), $e->channelFor('ug-sales-2'), $e->replyRoute('sales-002', 'ug-sales-2')['reason']];
    $trail = $reg->trail('sales-002');
    $f['e_trail_actions'] = array_column($trail, 'action');
    $f['e_trail_actor'] = array_values(array_unique(array_column($trail, 'actor')));
    $f['e_trail_whole_number'] = strpos(json_encode($trail), '700111222') !== false;
    $f['e_trail_masked'] = strpos(json_encode($trail, JSON_UNESCAPED_UNICODE), '••••22') !== false;
    $f['e_mask'] = [ChannelRegistry::mask('+256700111222'), ChannelRegistry::mask(null), ChannelRegistry::mask('')];

    // ── F. ChannelContext ─────────────────────────────────────────────────────────────────────────
    $ctx = $e->channelContext('sales-002');
    $fb  = $ctx ? $ctx->forBrain() : [];
    $f['f_for_brain_keys'] = array_keys($fb);
    $f['f_for_brain_leak'] = strpos(json_encode($fb), 'ug-sales') !== false || strpos(json_encode($fb), '700111222') !== false
                          || strpos(json_encode($fb), (string)$staffId . '"') !== false;
    $f['f_describe'] = $ctx ? $ctx->describe() : '';
    $f['f_server_side'] = $ctx ? [$ctx->instance(), $ctx->businessNumber()] : [];
    $f['f_dept'] = [$e->channelContext('sales') ? $e->channelContext('sales')->isDepartment() : null, $ctx ? $ctx->isDepartment() : null];

    // ── Unreadable registry: the three numbers keep routing, nothing else does, said once ──────────
    [$dir2, $store2, $pdo2] = cr_store('gone');
    $pdo2->exec('DROP TABLE wa_channels');
    $e2 = EvolutionApiService::forStore($ON, $pdo2, $dir2);
    $e3 = EvolutionApiService::forStore($ON, $pdo2, $dir2);
    $f['u_fallback'] = [$e2->registryOn(), $e2->channelFor('ug-sales'), $e2->channelFor('ug-support'), $e2->channelFor('ug-sales-2'), $e2->instanceFor('sales-002')];

    // ── G. Nothing logged carries a credential or a whole number ───────────────────────────────────
    $logText = (string)@file_get_contents($log);
    $f['g_log_said_once'] = substr_count($logText, 'channel registry is not installed');
    $f['g_log_conflict']  = substr_count($logText, 'channel sales-009 is NOT routed');
    $f['g_log_leaks'] = strpos($logText, 'CR-SECRET-EVO-KEY') !== false || strpos($logText, '700111222') !== false;
    $routing = $reg->routing(EvolutionApiService::configInstanceMap($ON));
    unset($routing['contexts']);
    $f['g_routing_key'] = strpos(json_encode($routing), 'CR-SECRET') !== false;

    exec('rm -rf ' . escapeshellarg($dir) . ' ' . escapeshellarg($dir2));
    @unlink($log);
    return $f;
}

$GLOBALS['__cr_on'] = ['tenant_profile' => 'uganda', 'evo_api_url' => 'http://127.0.0.1:9', 'evo_api_key' => 'CR-SECRET-EVO-KEY-0042',
                       'evo_instance_sales' => 'ug-sales', 'evo_instance_support' => 'ug-support', 'evo_instance_account' => 'ug-account',
                       'multi_number_channels_enabled' => '1'];

if ($isDriver) {
    echo json_encode(cr_scenario($root)), "\n";
    exit(0);
}

$pass = 0; $fail = 0;
function is_(bool $c, string $m, string $d = ''): void { global $pass, $fail;
    if ($c) { $pass++; echo "  ok   {$m}\n"; } else { $fail++; echo "  FAIL {$m}\n" . ($d !== '' ? "       {$d}\n" : ''); } }

$f = cr_scenario($root);
$j = function ($v): string { return json_encode($v, JSON_UNESCAPED_UNICODE); };

echo "\nA. Migration 087: the registry, its three seeds and its guards\n";
is_($f['a_ids'] === ['account', 'sales', 'support'], 'the three department numbers are seeded under the ids conversations already carry', $j($f['a_ids']));
is_($f['a_instances'] === [null], 'no instance is stored for them: configuration stays where it is, nothing is guessed', $j($f['a_instances']));
is_($f['a_shape'] === [['account', 'account', 'department', 'active', 1, null, 'all', 'department'],
                       ['sales', 'sales', 'department', 'active', 1, null, 'all', 'department'],
                       ['support', 'support', 'department', 'active', 1, null, 'all', 'department']],
    'each is a department number: its own role, active, the assistant on, no number recorded', $j($f['a_shape']));
is_($f['a_seed_log'] === 3, 'the seeding is in the trail, once per number', (string)$f['a_seed_log']);
is_(!isset($f['a_rerun_error']) && $f['a_rerun_rows'] === 3 && $f['a_rerun_log'] === 3, 'applied twice, nothing doubles',
    ($f['a_rerun_error'] ?? '') . ' rows=' . $f['a_rerun_rows'] . ' log=' . $f['a_rerun_log']);
is_($f['a_touches_other'] === false, 'the migration touches no other table: no ALTER, DROP, UPDATE or DELETE; no conversation, message, lead or event');
is_($f['a_secret_words'] === false, 'the migration names no credential');
foreach ($f['a_checks'] as $k => $v) is_($v === 'refused', "the schema refuses: {$k}", $v);
is_($f['a_after_probes'] === 1, 'only the one deliberately valid probe row was written', (string)$f['a_after_probes']);

echo "\nB. The switch: the flag AND Uganda\n";
is_($f['b_gate'] === ['absent_ug' => false, 'on_ug' => true, 'off_word_ug' => false, 'on_ss' => false, 'on_unknown' => false],
    'on only with multi_number_channels_enabled set on a Uganda install; South Sudan and an unknown tenant are off', $j($f['b_gate']));

echo "\nC. Off — and on outside Uganda — forStore() is the constructor's service, exactly\n";
foreach ($f['c_same'] as $k => $v) is_($v === true, "identical for the configuration shape {$k}");
is_($f['c_map_matches_constructor'], 'configInstanceMap() keeps the legacy gap-fill the constructor always had');

echo "\nD. On (Uganda): Part Q tests 1–6\n";
is_($f['d_registry_on'] === true, 'the registry is in effect');
is_($f['d1_sales'] === ['sales', 'sales', 'sales'], '1. the sales instance resolves to the sales channel (any case), role sales', $j($f['d1_sales']));
is_($f['d2_support'] === ['support', 'support'], '2. the support instance resolves to support', $j($f['d2_support']));
is_($f['d3_account'] === ['account', 'account'], '3. the account instance resolves to account', $j($f['d3_account']));
is_($f['d4_unknown'] === ['', 'unknown', '', 'refused'], '4. an unknown instance is refused (never defaulted); a retired one is known and refused', $j($f['d4_unknown']));
is_($f['d6_dept_instances'] === ['ug-sales', 'ug-support', 'ug-account'], '6. each department channel resolves to its configured instance', $j($f['d6_dept_instances']));
is_($f['d_create'] === 'ok', 'a second sales number can be created', $f['d_create']);
is_($f['d_created_state'] === ['', 'refused', ''], 'a new channel is created switched off: refused until switched on', $j($f['d_created_state']));
is_($f['d6_channel_instance'] === ['sales-002', 'ug-sales-2', 'sales', 'staff', $f['d6_channel_instance'][4] ?? -1] && ($f['d6_channel_instance'][4] ?? 0) > 0,
    '6. switched on, it resolves both ways and carries its role and owner', $j($f['d6_channel_instance']));
is_($f['d_reply_route'] === ['match' => '', 'ok' => true, 'mismatch' => 'instance_mismatch', 'no_inbound' => 'no_inbound_instance',
                             'unknown' => 'unknown_channel', 'dept_ok' => true],
    'a reply route is confirmed only for the instance the message arrived on', $j($f['d_reply_route']));
foreach (['paused', 'disabled', 'retired'] as $st) {
    is_($f['d5_' . $st] === ['', 'refused', '', 'channel_' . $st, 'sales'], "5. a {$st} channel is refused in and out — and sales is untouched", $j($f['d5_' . $st]));
}
is_($f['d_ai_off'] === ['sales-002', false, true, 'ai_disabled'], 'the assistant switched off on one number: still routed, never answered by the AI', $j($f['d_ai_off']));
is_($f['d5_dept'] === ['', 'refused', '', 'sales'], 'a department number switched off is refused in and out, and nothing moves to another', $j($f['d5_dept']));
is_($f['d_shared_owner_off'] === ['', 'refused', 'one', ''], 'an instance shared by two departments is refused when its owner is off — never handed to the other', $j($f['d_shared_owner_off']));
is_($f['d_conflict'] === ['sales', '', true, '', 'ug-account', 'account'],
    'a registry row naming a department instance is never routed, and a department row\'s own instance is ignored', $j($f['d_conflict']));

echo "\nE. Writes: validated, recorded, idempotent, masked\n";
foreach ($f['e_refusals'] as $k => $v) is_($v === true, "refused: {$k}");
is_($f['e_nothing_written'], 'every refusal wrote nothing, to the registry or the trail');
is_($f['e_idempotent'], 'a write that changes nothing writes nothing');
is_($f['e_moved'] === ['sales-002', '', 'instance_mismatch'], 'a channel moved to another instance: the old one is unknown, and a reply to a message on it is refused', $j($f['e_moved']));
is_($f['e_trail_actions'] === ['created', 'status', 'status', 'status', 'status', 'status', 'ai_enabled', 'ai_enabled', 'instance'],
    'the trail records every change, in order', $j($f['e_trail_actions']));
is_($f['e_trail_actor'] === ['registry test'], 'every trail row names who made the change', $j($f['e_trail_actor']));
is_(!$f['e_trail_whole_number'] && $f['e_trail_masked'], 'the trail carries the business number masked, never whole');
is_($f['e_mask'] === ['••••22', 'none', 'none'], 'mask() keeps two digits and nothing else', $j($f['e_mask']));

echo "\nF. ChannelContext: what the brain may learn\n";
is_($f['f_for_brain_keys'] === ['role', 'persona', 'territory', 'portfolio'], 'forBrain() is role, persona, territory and portfolio', $j($f['f_for_brain_keys']));
is_($f['f_for_brain_leak'] === false, 'forBrain() carries no instance, no number and no owner id');
is_($f['f_describe'] === 'sales-002 (sales)', 'its log form is the id and the role', $f['f_describe']);
is_($f['f_server_side'] === ['ug-sales-2b', '+256700111222'], 'the instance and the number stay available server-side only', $j($f['f_server_side']));
is_($f['f_dept'] === [true, false], 'a department number is told apart from a staff number', $j($f['f_dept']));

echo "\nG. An unreadable registry, and what is logged\n";
is_($f['u_fallback'] === [false, 'sales', 'support', '', ''], 'registry missing with the flag on: the three numbers route as configured, no other channel does', $j($f['u_fallback']));
is_($f['g_log_said_once'] === 1, 'and it is said once, not on every request', (string)$f['g_log_said_once']);
is_($f['g_log_conflict'] === 1, 'a conflicting channel is reported once', (string)$f['g_log_conflict']);
is_($f['g_log_leaks'] === false, 'no log line carries the Evolution key or a whole business number');
is_($f['g_routing_key'] === false, 'the routing the registry builds carries no credential');

// ── The read-only CLI and the settings tool, as an operator runs them ────────────────────────────────
echo "\nG2. tools/channels.php (read-only) and tools/set_config.php\n";
$cliDir = sys_get_temp_dir() . '/dn_cr_cli_' . bin2hex(random_bytes(4));
@mkdir($cliDir, 0777, true);
$cliCfg = $GLOBALS['__cr_on'];
putenv('DN_DATA_DIR=' . $cliDir); putenv('DN_VAULT_FILE=' . $cliDir . '/vault.json');
// The store first: a new store moves any settings file it finds into SQLite. Then the file PluginConfig reads.
$cs = SqliteStore::create($cliDir);
$cs->save('kyc_config.json', $cliCfg);
file_put_contents($cliDir . '/kyc_config.json', json_encode($cliCfg));
$cs->getPdo()->exec("INSERT INTO wa_channels (channel_id, evo_instance, business_number, display_name, role, status) VALUES ('sales-002', 'ug-sales-2', '+256700333444', 'Sales two', 'sales', 'active')");
// What the database HOLDS, not its bytes: a reader may checkpoint the write-ahead log into the file.
$content = function () use ($cs): string {
    $p = $cs->getPdo();
    $out = '';
    foreach ($p->query("SELECT name FROM sqlite_master WHERE type = 'table' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN) as $t) {
        $out .= $t . ':' . json_encode($p->query('SELECT * FROM "' . str_replace('"', '""', $t) . '"')->fetchAll(PDO::FETCH_ASSOC)) . "\n";
    }
    return md5($out);
};
$hash0 = $content();
$cli = function (array $args) use ($root, $cliDir): string {
    return (string)shell_exec('cd ' . escapeshellarg($root) . ' && DN_DATA_DIR=' . escapeshellarg($cliDir) . ' DN_VAULT_FILE='
        . escapeshellarg($cliDir . '/vault.json') . ' php tools/channels.php ' . implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1');
};
$out  = $cli([]);
$outR = $cli(['--resolve', 'UG-SALES-2']);
$outU = $cli(['--resolve', 'ug-nobody']);
$outT = $cli(['--trail', 'sales']);
$all  = $out . $outR . $outU . $outT;
is_(strpos($out, 'registry in effect') !== false && strpos($out, 'yes (Uganda, flag on)') !== false, 'it says whether the registry is in effect');
is_(strpos($out, 'sales-002') !== false && strpos($out, 'routed') !== false && strpos($out, 'configuration (evo_instance_sales)') !== false,
    'it lists every channel, where its instance comes from and whether it routes');
is_(strpos($outR, 'channel sales-002') !== false && strpos($outU, 'UNKNOWN') !== false, '--resolve answers as the webhook would');
is_(strpos($outT, 'seeded') !== false, '--trail shows the trail');
is_(strpos($all, 'CR-SECRET-EVO-KEY') === false, 'it never prints the Evolution key');
is_(strpos($all, '700333444') === false && strpos($all, '••••44') !== false, 'a business number appears masked, never whole');
is_($content() === $hash0, 'it changed nothing in the database: every table holds what it held');
$sc = (string)shell_exec('cd ' . escapeshellarg($root) . ' && DN_DATA_DIR=' . escapeshellarg($cliDir) . ' DN_VAULT_FILE='
    . escapeshellarg($cliDir . '/vault.json') . ' php tools/set_config.php 2>&1');
is_(preg_match('/multi_number_channels_enabled\s+ON/', $sc) === 1, 'set_config.php lists the flag, as set here', substr($sc, 0, 0));
exec('rm -rf ' . escapeshellarg($cliDir));

echo "\nH. Domain B is untouched\n";
$repo = dirname($root);
$st = (string)shell_exec('cd ' . escapeshellarg($repo) . ' && git status --porcelain -- dishnet-hybrid-sudan/dishnet-mikrotik-control-plane dishnet-hybrid-sudan/docs 2>&1');
$df = (string)shell_exec('cd ' . escapeshellarg($repo) . ' && git diff HEAD --stat -- dishnet-hybrid-sudan/dishnet-mikrotik-control-plane dishnet-hybrid-sudan/docs 2>&1');
is_(trim($st) === '' && trim($df) === '', 'no change under the MikroTik control plane or its documents', trim($st . $df));
$touch = 0;
foreach (['lib/ChannelRegistry.php', 'lib/ChannelContext.php', 'lib/InboxReplyRoute.php', 'migrations/087_wa_channels.sql', 'tools/channels.php'] as $rel) {
    if (preg_match('/mikrotik|domain.?b|mt_[a-z_]+|dnb_/i', (string)preg_replace('#//[^\n]*|/\*.*?\*/|--[^\n]*#s', '', (string)file_get_contents($root . '/' . $rel)))) $touch++;
}
is_($touch === 0, 'the new code references nothing of Domain B');

// ── W. Weakened copies ──────────────────────────────────────────────────────────────────────────────
echo "\nW. Weakened copies, each caught\n";
require_once $root . '/tests/fixtures/staff_jobs_sandbox.php';
$mutants = [
    ['a disabled channel is routed like an active one', 'lib/ChannelRegistry.php',
     "            if (\$ctx->isActive()) { \$c2i[\$id] = \$inst; \$i2c[\$key] = \$id; }\n            else                  { \$refused[\$key] = \$id; }",
     "            { \$c2i[\$id] = \$inst; \$i2c[\$key] = \$id; }",
     function (array $g) { return ($g['d5_disabled'][0] ?? '') !== ''; }],
    ['a disabled department hands its instance to the next department', 'lib/ChannelRegistry.php',
     "            if (isset(\$i2c[\$key]) || isset(\$refused[\$key])) continue;   // an earlier department owns it for inbound\n            if (\$ctx->isActive()) \$i2c[\$key] = \$id; else \$refused[\$key] = \$id;",
     "            if (isset(\$i2c[\$key])) continue;\n            if (\$ctx->isActive()) \$i2c[\$key] = \$id; else \$refused[\$key] = \$id;",
     function (array $g) { return ($g['d_shared_owner_off'][0] ?? '') !== ''; }],
    ['the Uganda gate is ignored', 'lib/ChannelRegistry.php',
     "            return \\StaffJobsGate::applies(\$config, \$dataDir);", "            return true;",
     function (array $g) { return ($g['b_gate']['on_ss'] ?? false) === true || in_array(false, (array)($g['c_same'] ?? []), true); }],
    ['the reply route does not compare instances', 'lib/EvolutionApiService.php',
     "        if (strcasecmp(\$in, \$now) !== 0) return ['ok' => false, 'reason' => 'instance_mismatch', 'context' => \$ctx];\n", "",
     function (array $g) { return ($g['d_reply_route']['mismatch'] ?? '') !== 'instance_mismatch'; }],
    ['a registry channel may take a department instance', 'lib/ChannelRegistry.php',
     "            if (isset(\$configured[\$key]) || isset(\$i2c[\$key]) || isset(\$refused[\$key])) {", "            if (false) {",
     function (array $g) { return ($g['d_conflict'][0] ?? '') !== 'sales' || ($g['d_conflict'][1] ?? '') !== ''; }],
    ['the seed guesses an instance for a department number', 'migrations/087_wa_channels.sql',
     "VALUES ('sales',   NULL, 'Sales',", "VALUES ('sales',   'dishnet_sales', 'Sales',",
     function (array $g) { return ($g['a_instances'] ?? []) !== [null]; }],
];
foreach ($mutants as [$name, $rel, $old, $new, $caught]) {
    [$copy, $n] = sj_weakened_copy($root, $rel, $old, $new);
    if ($n !== 1) { is_(false, "mutant anchor is unique: {$name}", "count {$n} in {$rel}"); exec('rm -rf ' . escapeshellarg($copy)); continue; }
    $raw = (string)shell_exec('php ' . escapeshellarg($self) . ' --driver ' . escapeshellarg($copy) . ' 2>/dev/null');
    $g = json_decode(trim((string)substr($raw, (int)strrpos(rtrim($raw), "\n"))), true);
    if (!is_array($g)) $g = json_decode(trim($raw), true);
    is_(is_array($g) && $caught($g), "mutant is caught: {$name}", is_array($g) ? '' : substr($raw, 0, 300));
    exec('rm -rf ' . escapeshellarg($copy));
}

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
