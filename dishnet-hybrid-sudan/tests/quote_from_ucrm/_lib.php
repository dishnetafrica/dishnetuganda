<?php
declare(strict_types=1);
/**
 * Shared by tests/test_quote_from_ucrm.php and its sections (5.18.102, docs/71 §L.1). TEST ONLY.
 *
 * QfuCtx holds the tree judged (--root), the run's own temporary folder, the tally, and the fakes. Every submission runs
 * the real KycService::process() in a child PHP process, on a copy of the judged tree's lib/ and profiles/ (so a log line
 * the code writes lands in the copy, never in this checkout), against fixtures/fake_ucrm_kyc.php or
 * fixtures/fake_ucrm_quote_org.php started from the judged tree. Every person, number and document is fictitious.
 *
 * 5.18.102 D14 (docs/71 §R.9.1): the default seed is linked, and says it is the web form's.
 *   - The plugin rows carry created_at stamps (QFU_PLAN_STAMP, QFU_KIT_STAMP), and ucrm_quote_links.json holds three
 *     records (qfu_links()): the plan row → product 12 and its service plan 6, the kit row → product 55, the
 *     installation → product 70, each with its row's stamp, under the quoting organization 1.
 *   - The plan row is named "Home Plan (TEST)", spelled unlike its linked product "Residential Standard (TEST)"; the fake's
 *     TEST catalogue holds an unlinked product 13 spelled exactly like that row, and an unlinked twin 54 of the kit's
 *     product 55 ("Standard Kit (TEST)", priced otherwise). So every priced case proves the link decided, never the
 *     spelling. ucrm_product_id stays 99 and 98, deliberately wrong: a stored id is never read.
 *   - child() seeds the links only where the caller keeps the default rows (neither 'plans' nor 'devices' given) on
 *     Uganda, or passes 'links' itself ([] seeds an empty store; null, none at all: the table is never created).
 *   - submit() posts the web KYC form's marker (ucrm_quote_form = 5.18.102) unless the post says otherwise; a key given
 *     as null is not posted at all, so a test leaves the marker (or any default) out with 'ucrm_quote_form' => null.
 */

/** 5.18.102 D14: the seeded rows' stamps (docs/71 §R.1.1 row_stamp) — the links carry the same. */
const QFU_PLAN_STAMP = '2026-10-01 09:00:00';
const QFU_KIT_STAMP  = '2026-10-01 09:05:00';

final class QfuCtx
{
    public string $root;
    public string $tmp;
    public int $pass = 0;
    public int $fail = 0;
    /** @var array<string,array{port:int,proc:resource}> */
    private array $fakes = [];
    private int $n = 0;

    public function __construct(string $root, string $tmp)
    {
        $this->root = rtrim($root, '/');
        $this->tmp  = rtrim($tmp, '/');
    }

    public function t(bool $ok, string $msg, string $detail = ''): void
    {
        if ($ok) { $this->pass++; echo "  ok   {$msg}\n"; return; }
        $this->fail++;
        echo "  FAIL {$msg}" . ($detail !== '' ? "\n       " . substr($detail, 0, 1200) : '') . "\n";
    }

    /** Equality with the two values shown on failure. Numbers compare by value (JSON gives 100000 for 100000.0). */
    public function eq($got, $want, string $msg): void
    {
        $got = self::norm($got);
        $want = self::norm($want);
        $this->t($got === $want, $msg, 'got ' . json_encode($got, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            . ' want ' . json_encode($want, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    public static function norm($v)
    {
        if (is_array($v)) { foreach ($v as $k => $x) $v[$k] = self::norm($x); return $v; }
        if (is_float($v) && abs($v - round($v)) < 1e-9 && abs($v) < 1e15) return (int)round($v);
        return $v;
    }

    public function dir(string $what): string
    {
        $d = $this->tmp . '/' . $what . '-' . (++$this->n);
        @mkdir($d, 0777, true);
        return $d;
    }

    // ── HTTP and the fakes ─────────────────────────────────────────────────

    public static function http(string $url, ?string $body = null, int $timeout = 8): ?array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 3,
                                CURLOPT_PROXY => '']);
        if ($body !== null) {
            curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body,
                                    CURLOPT_HTTPHEADER => ['Content-Type: application/json']]);
        }
        $out = curl_exec($ch);
        curl_close($ch);
        return $out === false ? null : (json_decode((string)$out, true) ?? []);
    }

    /**
     * Start (once) the fake named 'kyc' (fake_ucrm_kyc.php) or 'qo' (fake_ucrm_quote_org.php), from the judged tree.
     * Returns its port, or 0 when it would not start (the caller fails).
     */
    public function fake(string $which): int
    {
        if (isset($this->fakes[$which])) return $this->fakes[$which]['port'];
        $file   = $which === 'kyc' ? 'fake_ucrm_kyc.php' : 'fake_ucrm_quote_org.php';
        $marker = $which === 'kyc' ? 'FAKE-UCRM-KYC' : null;
        $env    = $which === 'kyc' ? 'FAKE_UCRM_KYC_TOKEN' : 'FAKE_UCRM_QO_TOKEN';
        $token  = bin2hex(random_bytes(8));
        foreach (range(0, 14) as $slot) {
            $port = 12100 + (($which === 'kyc' ? 0 : 400) + getmypid() * 7 + $slot * 29) % 380;
            if (self::http("http://127.0.0.1:{$port}/__test/ping", null, 2) !== null) continue;
            $p = proc_open(sprintf('exec php -S 127.0.0.1:%d %s', $port, escapeshellarg($this->root . '/tests/fixtures/' . $file)),
                [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, null, [$env => $token] + getenv());
            $r = null;
            for ($i = 0; $i < 50; $i++) {
                usleep(100000);
                $r = self::http("http://127.0.0.1:{$port}/__test/ping", null, 2);
                if ($r !== null) break;
            }
            if (is_array($r) && ($r['token'] ?? '') === $token && ($marker === null || ($r['marker'] ?? '') === $marker)) {
                $this->fakes[$which] = ['port' => $port, 'proc' => $p];
                $st = $which === 'kyc' ? 'fake_ucrm_kyc_' : 'fake_ucrm_qo_';
                register_shutdown_function(function () use ($p, $port, $st) {
                    if (is_resource($p)) { proc_terminate($p); proc_close($p); }
                    @unlink(sys_get_temp_dir() . '/' . $st . $port . '.json');
                });
                return $port;
            }
            if (is_resource($p)) { proc_terminate($p); proc_close($p); }
        }
        return 0;
    }

    /** A control call to a fake: /__test/reset?…, /__test/set, /__test/log, /__test/state. */
    public function ctl(string $which, string $path, ?array $body = null): array
    {
        $port = $this->fake($which);
        return self::http("http://127.0.0.1:{$port}{$path}", $body === null ? null : json_encode($body)) ?? [];
    }

    /** The fake's requests as "METHOD /path" with the /api/v2.1 prefix taken off, in order. */
    public function requests(string $which): array
    {
        return array_map(function ($e) {
            return ($e['method'] ?? '') . ' ' . preg_replace('#^/api/v2\.1#', '', (string)($e['path'] ?? ''));
        }, $this->ctl($which, '/__test/log'));
    }

    /** The bodies of the requests matching METHOD and a path pattern (under /api/v2.1). */
    public function bodies(string $which, string $method, string $re): array
    {
        $out = [];
        foreach ($this->ctl($which, '/__test/log') as $e) {
            $p = preg_replace('#^/api/v2\.1#', '', (string)($e['path'] ?? ''));
            if (($e['method'] ?? '') === $method && preg_match('#^' . $re . '$#', $p)) $out[] = $e['body'] ?? [];
        }
        return $out;
    }

    // ── the code under test, in a child process ────────────────────────────

    /** A copy of the judged tree's lib/ and profiles/ (and anything in $more), for one child to run. */
    public function tree(array $more = []): string
    {
        $dir = $this->dir('tree');
        foreach (array_merge(['lib', 'profiles'], $more) as $d) {
            if (!file_exists($this->root . '/' . $d)) continue;
            $dest = $dir . '/' . $d;
            @mkdir(dirname($dest), 0777, true);
            exec('cp -R ' . escapeshellarg($this->root . '/' . $d) . ' ' . escapeshellarg($dest));
        }
        @mkdir($dir . '/data', 0777, true);
        return $dir;
    }

    /**
     * Run a PHP body in a child, with the store, the KYC config and the plan rows already set up. $body sees $s (the
     * store), $crm (the plugin's client on the fake), $dir (the data dir), $root (the tree), and returns a value; the
     * child prints it as JSON. 'errlog' carries PHP's log of the child.
     *
     * @param array $o tenant (uganda|south-sudan), cfg (merged into kyc_config.json), plans, devices, packages, fake (kyc|qo),
     *                 wallet (agent id => balance), apps (kyc_applications.json rows), tree (a tree() dir), dir (a data dir to reuse)
     */
    public function child(string $body, array $o = []): array
    {
        $tree   = $o['tree'] ?? $this->tree();
        $dir    = $o['dir'] ?? $this->dir('data');
        $which  = $o['fake'] ?? 'kyc';
        $port   = $this->fake($which);
        $tenant = $o['tenant'] ?? 'uganda';
        $cfg    = ['tenant_profile' => $tenant, 'currency_code' => $tenant === 'uganda' ? 'UGX' : 'USD',
                   'currency_symbol' => $tenant === 'uganda' ? 'UGX' : '$', 'dry_run_mode' => true, 'data_dir' => $dir]
                + ($o['cfg'] ?? []);
        $seed = [
            'subscription_plans.json' => $o['plans'] ?? qfu_rows()['plans'],
            'kyc_devices.json'        => $o['devices'] ?? qfu_rows()['devices'],
            'kyc_packages.json'       => $o['packages'] ?? [],
        ];
        // 5.18.102 D14 (docs/71 §R.9.1): the links, seeded with the default rows on Uganda, or as the caller says
        $links = array_key_exists('links', $o) ? $o['links']
               : ((!isset($o['plans']) && !isset($o['devices']) && $tenant === 'uganda') ? qfu_links() : null);
        if ($links !== null) $seed['ucrm_quote_links.json'] = $links;
        if (isset($o['apps'])) $seed['kyc_applications.json'] = $o['apps'];
        $f = $dir . '/child-' . (++$this->n) . '.php';
        file_put_contents($f, '<?php
error_reporting(E_ALL & ~E_DEPRECATED);
ini_set("error_log", ' . var_export($dir . '/php_errors.log', true) . ');
$root = ' . var_export($tree, true) . ';
foreach (["StoreInterface", "JsonStore", "SqliteStore", "CrmApiClient", "WalletService", "CrmQueue", "EfrisClientField",
          "UcrmClientTarget", "KycService", "KycCrmSync", "UcrmQuoteLines", "SplynxTicketService"] as $l) if (is_file("$root/lib/$l.php")) require_once "$root/lib/$l.php";
$dir = ' . var_export($dir, true) . ';
$s   = SqliteStore::create($dir);
// the table a confirmed duplicate writes to (migrations/054), so a test can see that row written or not
$s->getPdo()->exec("CREATE TABLE IF NOT EXISTS duplicate_confirmations (id INTEGER PRIMARY KEY AUTOINCREMENT, staff_id INTEGER,
    staff_name TEXT, phone TEXT, existing_name TEXT, existing_crm_id INTEGER, note TEXT, review_status TEXT DEFAULT \'pending\',
    created_at TEXT DEFAULT CURRENT_TIMESTAMP)");
foreach (json_decode(' . var_export(json_encode($seed), true) . ', true) as $file => $rows) {
    if ($s->load($file) === null || $s->load($file) === [] ) $s->save($file, $rows);
}
$s->save("kyc_config.json", json_decode(' . var_export(json_encode($cfg), true) . ', true) + ($s->load("kyc_config.json") ?? []));
$__ret = $s->load("retailers.json") ?? [];
foreach (json_decode(' . var_export(json_encode($o['wallet'] ?? []), true) . ', true) as $rid => $bal) {
    $__ret[] = ["id" => (int)$rid, "name" => "Test Agent", "role" => "sales", "wallet" => (float)$bal];   // WalletService reads retailers.json
}
if ($__ret !== []) $s->save("retailers.json", $__ret);
$crm = new CrmApiClient("http://127.0.0.1:' . $port . '/api/v2.1", "FAKE-TEST-KEY", "X-Auth-App-Key");
$__r = (function () use ($s, $crm, $dir, $root) {' . "\n" . $body . "\n" . '})();
echo "\n@@QFU@@" . json_encode($__r, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
');
        $out = [];
        exec('php ' . escapeshellarg($f) . ' 2>&1', $out, $rc);
        $all = implode("\n", $out);
        $pos = strrpos($all, '@@QFU@@');
        $res = $pos === false ? null : json_decode(substr($all, $pos + 7), true);
        return ['value' => $res, 'rc' => $rc, 'out' => $pos === false ? $all : substr($all, 0, $pos),
                'errlog' => (string)@file_get_contents($dir . '/php_errors.log'), 'dir' => $dir, 'tree' => $tree];
    }

    /**
     * One KYC submission (KycService::process) with $post over a Uganda Starlink New Connection default, as agent 7.
     * value: success, message, data, apps (every application row after), dup (duplicate_confirmations rows), wallet
     * (agent 7's balance), collections (payment_collections.json rows).
     */
    public function submit(array $post, array $o = []): array
    {
        static $k = 0;
        $k++;
        $post += ['action' => 'kyc_submit', 'customer_type' => 'StarLink', 'connectivity_type' => 'New Connection',
                  'firstname' => 'Test', 'lastname' => 'Qfu' . $k, 'mobile' => '+256 700 001 ' . str_pad((string)($k % 1000), 3, '0', STR_PAD_LEFT),
                  'email' => '', 'address_1' => 'Test Road', 'package_choice' => '1', 'device_id' => '3', 'kitQty' => '1',
                  'sales_type' => 'Credit', 'priority' => 'Low', 'sales_person' => 'Tester',
                  'ucrm_quote_form' => '5.18.102'];   // 5.18.102 D14 (§R.4.2): the web KYC form's marker, unless the post says otherwise
        $post = array_filter($post, fn($v) => $v !== null);   // a key given as null is not posted
        $agent = $o['agent'] ?? ['id' => 7, 'name' => 'Test Agent', 'role' => 'sales', 'is_admin' => false];
        return $this->child('
$w = new WalletService($s, $s->getPdo());
$k = new KycService($crm, $s, $w, new CrmQueue($s), $dir);
$r = $k->process(json_decode(' . var_export(json_encode($post), true) . ', true), [], json_decode(' . var_export(json_encode($agent), true) . ', true));
$dup = [];
try { $dup = $s->getPdo()->query("SELECT * FROM duplicate_confirmations")->fetchAll(\PDO::FETCH_ASSOC); } catch (\Throwable $e) { $dup = []; }
return ["success" => !empty($r["success"]), "message" => (string)($r["message"] ?? ""), "data" => $r["data"] ?? [],
        "apps" => $s->load("kyc_applications.json") ?? [], "dup" => $dup, "wallet" => $w->getBalance(' . (int)$agent['id'] . '),
        "collections" => $s->load("payment_collections.json") ?? []];
', $o);
    }

    public function stop(): void
    {
        foreach ($this->fakes as $f) {
            if (is_resource($f['proc'])) { proc_terminate($f['proc']); proc_close($f['proc']); }
        }
        $this->fakes = [];
    }
}

/** The TEST catalogue rows the fakes serve, for a test that replaces them through /__test/set. */
function qfu_product(int $id, string $name, $price, array $more = []): array
{
    return ['id' => $id, 'name' => $name, 'price' => $price, 'unit' => $more['unit'] ?? 'Pc', 'taxable' => false] + $more;
}
function qfu_plan(int $id, string $name, $price, ?array $periods = null): array
{
    return ['id' => $id, 'name' => $name, 'periods' => $periods ?? [['period' => 1, 'price' => $price, 'enabled' => true]]];
}

// ── 5.18.102 D14 (docs/71 §R.9.1): the default rows, their links, and the fake's TEST catalogue ─────────────────────

/**
 * The plugin rows child() seeds by default: the plan row is spelled unlike its linked product (product 13 in the fake's
 * catalogue is spelled like it, and is never linked); ucrm_product_id is 99 and 98, deliberately wrong (never read).
 */
function qfu_rows(): array
{
    return [
        'plans'   => [['id' => 1, 'name' => 'Home Plan (TEST)', 'customer_price' => 90, 'ucrm_product_id' => 99,
                       'is_active' => true, 'created_at' => QFU_PLAN_STAMP]],
        'devices' => [['id' => 3, 'title' => 'Standard Kit (TEST)', 'price' => 'USD 1', 'ucrm_product_id' => 98,
                       'is_active' => true, 'created_at' => QFU_KIT_STAMP]],
    ];
}

/**
 * One link record in ucrm_quote_links.json's stored shape (docs/71 §R.1.1), active, linked under the quoting
 * organization 1 by a fictitious admin. $more overrides any key (organization_id, ended_at_ts, …).
 */
function qfu_link(int $id, string $item, int $rowId, string $stamp, int $productId, ?int $servicePlanId = null, array $more = []): array
{
    return array_merge([
        'id' => $id, 'item' => $item, 'row_id' => $item === 'install' ? 0 : $rowId, 'row_stamp' => $item === 'install' ? '' : $stamp,
        'product_id' => $productId, 'service_plan_id' => $item === 'plan' ? $servicePlanId : null, 'organization_id' => 1,
        'row_name' => $item === 'install' ? 'Installation' : 'TEST row ' . $rowId, 'product_name' => 'TEST product ' . $productId,
        'service_plan_name' => $item === 'plan' ? 'TEST service plan ' . (int)$servicePlanId : null,
        'linked_by_id' => 1, 'linked_by_name' => 'Test Admin', 'linked_at' => '2026-10-01 10:00:00',
        'ended_at' => null, 'ended_by_id' => null, 'ended_why' => null, 'linked_at_ts' => 1790848800 + $id, 'ended_at_ts' => null,
    ], $more);
}

/** The record ended, as UcrmQuoteLinks writes it: ended_at and ended_at_ts together (ended_at_ts set is the end). */
function qfu_ended(array $rec, string $why = 'relinked'): array
{
    return array_merge($rec, ['ended_at' => '2026-10-02 10:00:00', 'ended_at_ts' => 1790935200, 'ended_by_id' => 1, 'ended_why' => $why]);
}

/** The default links: record 1 the plan row → 12 and service plan 6; 2 the kit row → 55; 3 the installation → 70. */
function qfu_links(): array
{
    return [qfu_link(1, 'plan', 1, QFU_PLAN_STAMP, 12, 6), qfu_link(2, 'kit', 3, QFU_KIT_STAMP, 55), qfu_link(3, 'install', 0, '', 70)];
}

/**
 * fixtures/fake_ucrm_kyc.php's TEST catalogue ('catalogue' => true), by id, for a test that serves it changed through
 * /__test/set 'products': $over replaces fields of a product (by id), adds one (an id not in it), or removes it (null).
 * B0 asserts this equals what the fake serves.
 */
function qfu_kyc_products(array $over = []): array
{
    $p = [
        12 => ['id' => 12, 'name' => 'Residential Standard (TEST)', 'price' => 100,  'unit' => 'Month', 'taxable' => false],
        13 => ['id' => 13, 'name' => 'Home Plan (TEST)',            'price' => 95,   'unit' => 'Month', 'taxable' => false],
        54 => ['id' => 54, 'name' => 'Standard Kit (TEST)',         'price' => 1400, 'unit' => 'Pc',    'taxable' => false],
        55 => ['id' => 55, 'name' => 'Standard Kit (TEST)',         'price' => 1500, 'unit' => 'Pc',    'taxable' => false],
        70 => ['id' => 70, 'name' => 'Professional Installation',   'price' => 200,  'unit' => 'Job',   'taxable' => false],
    ];
    foreach ($over as $id => $set) {
        if ($set === null) { unset($p[$id]); continue; }
        $p[$id] = isset($p[$id]) ? array_merge($p[$id], $set) : $set;
    }
    return array_values($p);
}
