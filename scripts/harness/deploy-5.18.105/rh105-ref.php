<?php
// 5.18.105's rehearsal: the RELEASE's own code, as a reference the rehearsal's readings are set against, and as the
// three drives of root docs/74 §H.6.6 that need a seam (LN-02's ledger row, LN-03, LN-04). Never the script's probe.
// Synthetic: run by the rehearsal, with a vault of its own (DN_VAULT_FILE); every phone the rehearsal's own range
// (+2567001040NN), every name and text an RH104SECRET- value (5.18.104's helpers, rh104-live.php and rh104-seed.php,
// keep that range; 5.18.105's drives use its free numbers). Prints one line of JSON or key=value words — counts, key and
// step names, fixed codes; never a phone, a name, a body, a key's value or a token.
//   php rh105-ref.php ladder <code root>
//        — SalesFollowupLadder of <code root> (the PIN's from Git): {"all":[allKeys()],"settings":[settingKeys()],
//          "stepmap":"step:key:rule,…" (each step in steps()' order and the key isOn reads: report its record key
//          sf_report_ran_at, read on when not blank — rule r; every other step its first switch key, read on when
//          truthy() — rule s), "vdr":[voice-dry-run and skipCascade('voice-dry-run'): the steps `skip voice-dry-run`
//          records], "labels":"n:step|…"} — what rh105q.py's lk mode reads the four places with
//   php rh105-ref.php sspin <code root> <scratch> <spec JSON>
//        — LN-02's fixture (root docs/74 §H.1.2): one SSP 'in' row in staff_ledger for the staff account spec "name" (an
//          RH104SECRET- value, or "*" for the first such account, a field role, active, with a phone of record), through <code root>'s own
//          StaffLedgerService::record on the SCRATCH copy's book — the category the service itself pairs with 'in' (its
//          own table, read by reflection), never a raw INSERT: {"row":<id>|null,"staff":"found"|"none"}
//   php rh105-ref.php ln03 <code root> <scratch> <spec JSON>
//        — LN-03 (root docs/74 §H.1.3): <code root>'s QuotationService on the SCRATCH copy, its NotificationService
//          replaced by reflection (the release's tests' way) with one whose sendVia throws an exception carrying spec
//          "phone" in its four written forms; the private sendWA(phone, text, 'quote_manual') invoked by reflection;
//          PHP's error log set to spec "log" just before: {"ret":true|false,"threw":1}
//   php rh105-ref.php ln04 <code root> <scratch> <spec JSON>
//        — LN-04 (root docs/74 §H.1.4): <code root>'s WaAutoReplyService on the SCRATCH copy, its private
//          createCrmJobForBidal(phone, name, issue, 'support') invoked by reflection, with a configuration of the drive's
//          own whose crm_base_url is the rehearsal's fake CRM on 127.0.0.1 (spec "crm"; it answers POST scheduling/jobs with
//          id 4711) — never the stand-in uCRM, never a real one; the support leader a retailers row of the scratch's own
//          (spec "leader", an RH104SECRET- name with "Bidal" in it, a phone of the range), written through the store's
//          appendWithId; the notifier a stub that records nothing; PHP's error log set to spec "log": {"ret":…}
// Refuses a run without a vault of its own, a code root outside the rehearsal's folder, a scratch without its mark
// (rh105-scratch), and any spec value that is not the rehearsal's own.
declare(strict_types=1);
ini_set('display_errors', 'stderr');
[$_, $mode, $root] = $argv + [null, '', ''];
$out = function (array $a): void { echo json_encode($a, JSON_UNESCAPED_SLASHES), "\n"; };
$ours = function ($v): bool {
    if (!is_string($v) || $v === '') return false;
    return strpos($v, 'RH104SECRET-') === 0 || (bool)preg_match('/^\+?2567001040[0-9]{2}$/', $v);
};
if ((string)getenv('DN_VAULT_FILE') === '') { fwrite(STDERR, "refusing: DN_VAULT_FILE must point into the sandbox\n"); exit(2); }
if (!is_file($root . '/lib/SalesFollowupLadder.php')) { fwrite(STDERR, "refusing: the code root holds no lib/SalesFollowupLadder.php\n"); exit(2); }
try {
    if ($mode === 'ladder') {
        require_once $root . '/lib/UtcClock.php';
        require_once $root . '/lib/SalesFollowupLadder.php';
        $map = []; $lab = [];
        foreach (SalesFollowupLadder::steps() as $s) {
            $r = SalesFollowupLadder::row($s);
            $lab[] = (string)$r['n'] . ':' . $s;
            if ($s === 'report') { $map[] = 'report:sf_report_ran_at:r'; continue; }
            $sw = array_keys((array)($r['switch'] ?? []));
            if ($sw === []) { fwrite(STDERR, "refused: a step with no switch key\n"); exit(3); }
            $map[] = $s . ':' . (string)$sw[0] . ':s';
        }
        $vdr = array_merge(['voice-dry-run'], SalesFollowupLadder::skipCascade('voice-dry-run'));
        $out(['all' => SalesFollowupLadder::allKeys(), 'settings' => SalesFollowupLadder::settingKeys(), 'stepmap' => implode(',', $map),
              'vdr' => array_values($vdr), 'labels' => implode('|', $lab)]);
        exit(0);
    }
    // ── the drives: <code root> a copy of the INSTALLED plugin in the rehearsal's folder, <scratch> a copy of the book ──
    [, , , $data, $specJ] = $argv + [null, null, null, '', '{}'];
    if (strpos(realpath($root) ?: '', '/rh105/') === false) { fwrite(STDERR, "refusing: the code root is not a copy in the rehearsal's folder\n"); exit(2); }
    if (!is_file($data . '/rh105-scratch') || !is_file($data . '/plugin.sqlite3')) { fwrite(STDERR, "refusing: not a scratch copy of the rehearsal's\n"); exit(2); }
    $spec = json_decode((string)$specJ, true);
    if (!is_array($spec)) { fwrite(STDERR, "refusing: no spec\n"); exit(2); }
    foreach (['http_proxy', 'https_proxy', 'HTTP_PROXY', 'HTTPS_PROXY', 'ALL_PROXY', 'all_proxy'] as $v) putenv($v);   // nothing leaves 127.0.0.1
    putenv('NO_PROXY=*'); putenv('no_proxy=*'); putenv('DN_DATA_DIR=' . $data);
    foreach (['error_handler', 'bootstrap_data', 'timezone', 'UtcClock', 'ConfigVault', 'StoreInterface', 'JsonStore', 'SqliteStore', 'PluginConfig',
              'ContactOptOut'] as $l) {
        $f = $root . '/lib/' . $l . '.php';
        if (is_file($f)) require_once $f;
    }
    $GLOBALS['dataDir'] = $data;
    $store = SqliteStore::create($data);
    $pdo = $store->getPdo();
    $logTo = function (string $f): void {
        if (strpos($f, '/rh105/') === false && strpos($f, '/r105/') === false) { fwrite(STDERR, "refusing: a log outside the rehearsal's folders\n"); exit(2); }
        ini_set('log_errors', '1'); ini_set('error_log', $f);
    };
    if ($mode === 'sspin') {
        // spec "name" "*": the first active staff account of the cron's field roles whose name is an RH104SECRET- value and
        // whose phone is the rehearsal's own (the book's fixtures; the report reads the same roles)
        $any = (($spec['name'] ?? null) === '*');
        if (!$any && !$ours($spec['name'] ?? null)) { fwrite(STDERR, "refusing: a value that is not the rehearsal's own\n"); exit(2); }
        require_once $root . '/lib/StaffLedgerService.php';
        $sid = 0; $sname = '';
        foreach ((array)($store->load('retailers.json') ?? []) as $r) {
            $fit = $any ? (strpos((string)($r['name'] ?? ''), 'RH104SECRET-') === 0
                           && in_array((string)($r['role'] ?? ''), ['sales', 'sales_staff', 'support', 'support_leader', 'field_agent', 'collection'], true))
                        : ((string)($r['name'] ?? '') === (string)$spec['name']);
            if (is_array($r) && $fit && !empty($r['is_active']) && $ours((string)($r['phone'] ?? ''))) {
                $sid = (int)($r['id'] ?? 0); $sname = (string)$r['name']; break;
            }
        }
        if ($sid <= 0) { $out(['row' => null, 'staff' => 'none']); exit(3); }
        $rp = new ReflectionProperty('StaffLedgerService', 'categoryDirection');
        $rp->setAccessible(true);
        $cd = (array)$rp->getValue();
        $cat = '';
        foreach ($cd as $k => $d) { if ($d === 'in') { $cat = (string)$k; break; } }
        if ($cat === '') { fwrite(STDERR, "refused: the service pairs no category with 'in'\n"); exit(3); }
        $sl = new StaffLedgerService($pdo);
        $id = $sl->record(['staff_id' => $sid, 'staff_name' => $sname, 'direction' => 'in', 'currency' => 'SSP', 'amount' => 0,
                           'ssp_amount' => (float)($spec['ssp'] ?? 5000), 'ssp_rate' => 0, 'category' => $cat,
                           'description' => 'RH104SECRET-an SSP expense fund of the rehearsal', 'source_type' => 'rh105',
                           'source_id' => 'rh105-ln02', 'idempotency_key' => 'rh105-ln02-' . $sid]);
        $out(['row' => $id === null ? null : (int)$id, 'staff' => 'found']);
        exit($id === null ? 3 : 0);
    }
    if ($mode === 'ln03') {
        $ph = (string)($spec['phone'] ?? '');
        if (!$ours($ph) || strpos((string)($spec['text'] ?? ''), 'RH104SECRET-') !== 0) { fwrite(STDERR, "refusing: a value that is not the rehearsal's own\n"); exit(2); }
        require_once $root . '/lib/QuotationService.php';
        $cfg = PluginConfig::read($root, $data);
        $qs = new QuotationService($store, $data, $cfg);
        $d = (string)preg_replace('/\D/', '', $ph);
        $nat = '0' . substr($d, 3);
        $why = 'the gateway refused +' . $d . ' (' . $d . ', ' . $nat . ', ' . substr($nat, 0, 4) . ' ' . substr($nat, 4, 3) . ' ' . substr($nat, 7) . ')';
        // the parent's own signature (994d660's NotificationService, which 5.18.105 keeps byte for byte), its constructor
        // left out: nothing is loaded, nothing can be sent
        $thrower = new class extends NotificationService {
            public string $rh105 = '';
            public function __construct() {}
            public function sendVia(string $sender, string $toPhone, string $message, string $event = '', array $vars = [], string $class = ContactOptOut::CLASS_TRANSACTIONAL): void
            {
                throw new RuntimeException($this->rh105);
            }
        };
        $thrower->rh105 = $why;
        $p = new ReflectionProperty($qs, 'ns');
        $p->setAccessible(true);
        $p->setValue($qs, $thrower);
        $m = new ReflectionMethod($qs, 'sendWA');
        $m->setAccessible(true);
        $logTo((string)($spec['log'] ?? ''));
        $ret = $m->invoke($qs, $ph, (string)$spec['text'], 'quote_manual');
        $out(['ret' => (bool)$ret, 'threw' => 1]);
        exit(0);
    }
    if ($mode === 'ln04') {
        $ph = (string)($spec['phone'] ?? ''); $lp = (string)($spec['leader_phone'] ?? '');
        if (!$ours($ph) || !$ours($lp) || !$ours($spec['name'] ?? null) || !$ours($spec['leader'] ?? null) || stripos((string)$spec['leader'], 'bidal') === false
            || strpos((string)($spec['issue'] ?? ''), 'RH104SECRET-') !== 0) { fwrite(STDERR, "refusing: a value that is not the rehearsal's own\n"); exit(2); }
        if (!preg_match('#^http://127\.0\.0\.1:\d{2,5}/rh105crm$#', (string)($spec['crm'] ?? ''))) { fwrite(STDERR, "refusing: the CRM is the rehearsal's fake on 127.0.0.1 alone\n"); exit(2); }
        require_once $root . '/lib/WaAutoReplyService.php';
        $store->appendWithId('retailers.json', ['name' => (string)$spec['leader'], 'role' => 'support_leader', 'phone' => $lp,
                                                'ucrm_user_id' => (int)($spec['uid'] ?? 4712), 'is_active' => true, 'is_admin' => false]);
        $cfg = PluginConfig::read($root, $data);
        $cfg['crm_base_url'] = (string)$spec['crm']; $cfg['crm_auth_token'] = 'rh105-fake-crm-not-a-token';
        $notify = new class { public int $n = 0; public function sendVia(...$a): void { $this->n++; } };
        $svc = new WaAutoReplyService($store, $pdo, $notify, $cfg, null);
        $m = new ReflectionMethod($svc, 'createCrmJobForBidal');
        $m->setAccessible(true);
        $logTo((string)($spec['log'] ?? ''));
        $ret = $m->invoke($svc, $ph, (string)$spec['name'], (string)$spec['issue'], 'support');
        $out(['ret' => (bool)$ret, 'notified' => $notify->n]);
        exit(0);
    }
    fwrite(STDERR, "refusing: a mode this helper does not know\n"); exit(2);
} catch (Throwable $e) { fwrite(STDERR, 'refused: ' . get_class($e) . "\n"); exit(3); }
