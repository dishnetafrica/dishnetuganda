<?php
declare(strict_types=1);
/**
 * Shared by section L of tests/test_quote_from_ucrm.php (5.18.102 D14, docs/71 §R.9.1 "New section L": the links).
 * TEST ONLY.
 *
 * qfu_l_seed() makes a data directory (a real SqliteStore, through QfuCtx::child) holding the plugin rows, the link
 * records, the staff accounts, the day's form secret and any applications; qfu_l_req() runs one request on it through
 * l_run.php (a POST through the global CSRF gate and includes/post/post_sync.php, a dashboard tab, the KYC form, or
 * retry_quote), in a process of its own, on one copy of the judged tree's lib/, profiles/, includes/, tabs/ and
 * public.php. What a request left in the store is read back straight from plugin.sqlite3 (qfu_l_rows()), never through
 * the code under test, and what reached uCRM from the fake's own log.
 *
 * Every person, number, address and document is fictitious (Test names, example.test, +256 700 00x xxx); nothing
 * leaves the machine. No time zone is named here: a case runs in the runner's QFU_TZ.
 */

/** The accounts the seeded store holds: an active admin (1), a second admin (2), and an agent who is not one (7). */
const QFU_L_ADMIN  = ['id' => 1, 'name' => 'Test Admin', 'email' => 'admin@example.test', 'phone' => '+256700009101',
                      'role' => 'admin', 'is_admin' => true, 'is_active' => true];
const QFU_L_ADMIN2 = ['id' => 2, 'name' => 'Test Admin Two', 'email' => 'admin2@example.test', 'phone' => '+256700009102',
                      'role' => 'admin', 'is_admin' => true, 'is_active' => true];
const QFU_L_AGENT  = ['id' => 7, 'name' => 'Test Agent', 'email' => 'agent@example.test', 'phone' => '+256700009107',
                      'role' => 'sales', 'is_admin' => false, 'is_active' => true];
/** The handlers' fixed flashes (docs/71 §R.2.1, §R.1.4). */
const QFU_L_SECURITY = 'The security check failed, so nothing was changed. Open the screen again and retry.';
const QFU_L_DENIED   = 'Access denied — admin only.';
const QFU_L_RETRY    = 'Nothing was linked — try again.';
const QFU_L_URETRY   = 'Nothing was unlinked — try again.';
const QFU_L_STAMP3   = '2026-10-01 09:01:00';
const QFU_L_STAMP4   = '2026-10-01 09:06:00';

/** The plugin rows the section seeds by default: plans 1 (QFU_PLAN_STAMP), 2 (Fiber) and 3; kits 3, 4 and 5 (Fiber). */
function qfu_l_rows(): array
{
    return [
        'plans' => [
            ['id' => 1, 'name' => 'Home Plan (TEST)', 'type' => 'starlink', 'customer_price' => 90, 'ucrm_product_id' => 99,
             'is_active' => true, 'created_at' => QFU_PLAN_STAMP],
            ['id' => 2, 'name' => 'Fiber Home (TEST)', 'type' => 'fiber', 'customer_price' => 60, 'is_active' => true,
             'created_at' => '2026-10-01 09:00:30'],
            ['id' => 3, 'name' => 'Spare Plan (TEST)', 'type' => 'starlink', 'customer_price' => 80, 'is_active' => true,
             'created_at' => QFU_L_STAMP3],
        ],
        'devices' => [
            ['id' => 3, 'title' => 'Standard Kit (TEST)', 'type' => 'starlink', 'price' => 'USD 1', 'ucrm_product_id' => 98,
             'is_active' => true, 'created_at' => QFU_KIT_STAMP],
            ['id' => 4, 'title' => 'Mini Kit (TEST) (plugin)', 'type' => 'general', 'price' => 'USD 2', 'is_active' => true,
             'created_at' => QFU_L_STAMP4],
            ['id' => 5, 'title' => 'Fiber Box (TEST)', 'type' => 'fiber', 'price' => 'USD 3', 'is_active' => true,
             'created_at' => '2026-10-01 09:07:00'],
        ],
    ];
}

/** The section's uCRM catalogue: what the fake serves (products, service plans), each row fictitious. */
function qfu_l_products(array $over = []): array
{
    // qfu_product() keeps its own 'taxable' => false over $more's; the section's rows take every field as given
    $m = function (int $id, string $name, $price, array $more = []) { return array_merge(qfu_product($id, $name, $price, $more), $more); };
    $p = [
        12 => $m(12, 'Residential Standard (TEST)', 100, ['unit' => 'Month']),
        13 => $m(13, 'Home Plan (TEST)', 95, ['unit' => 'Month']),                // spelled like plan row 1, never linked
        21 => $m(21, 'Starter (TEST)', 120, ['unit' => 'Month']),                  // its service plan says 110
        22 => $m(22, 'Family Pack (TEST)', 130, ['unit' => 'Month']),              // its service plan is spelled otherwise
        23 => $m(23, 'Twin Plan (TEST)', 140, ['unit' => 'Month']),                // two counted service plans spelled so
        24 => $m(24, 'Promo Plan (TEST)', 160, ['unit' => 'Month']),               // an archived service plan spelled so too
        25 => $m(25, 'Corp Plan (TEST)', 170, ['unit' => 'Month']),                // another organization's spelled so too
        26 => $m(26, 'Odd Plan (TEST)', 180, ['unit' => 'Month']),                 // its service plan's own price is 99
        27 => $m(27, 'Quarter Plan (TEST)', 190, ['unit' => 'Month']),             // its service plan has no 1-month period
        28 => $m(28, 'Double Plan (TEST)', 200, ['unit' => 'Month']),              // two 1-month periods
        29 => $m(29, 'Archived Basic (TEST)', 100, ['unit' => 'Month']),           // spelled like an archived service plan
        30 => $m(30, 'Inactive Basic (TEST)', 100, ['unit' => 'Month']),           // like an inactive one
        31 => $m(31, 'Other Org Basic (TEST)', 100, ['unit' => 'Month']),          // like another organization's
        54 => $m(54, 'Standard Kit (TEST)', 1400, ['unit' => 'Pc']),               // 55's twin, priced otherwise
        55 => $m(55, 'Standard Kit (TEST)', 1500, ['unit' => 'Pc']),
        56 => $m(56, 'Mini Kit (TEST)', 900, ['unit' => 'Pc']),
        57 => $m(57, 'Org Kit (TEST)', 950, ['unit' => 'Pc', 'organizationId' => 1]),
        70 => $m(70, 'Professional Installation', 200, ['unit' => 'Job']),
        71 => $m(71, 'Installation by DishNet (TEST)', 150, ['unit' => 'Job']),
        80 => $m(80, 'Archived Kit (TEST)', 500, ['archived' => true]),
        81 => $m(81, 'Inactive Kit (TEST)', 500, ['isActive' => false]),
        82 => $m(82, 'Other Org Kit (TEST)', 500, ['organizationId' => 3]),
        83 => $m(83, 'Taxed Kit (TEST)', 500, ['taxable' => true]),
        84 => $m(84, 'Tax Id Kit (TEST)', 500, ['taxId' => 5]),
        85 => $m(85, 'Free Kit (TEST)', 0),
        86 => $m(86, 'Fine Kit (TEST)', 10.555),
        87 => $m(87, 'Label Kit (TEST)', 500, ['invoiceLabel' => 'Something Else (TEST)']),
        88 => $m(88, 'No Price Kit (TEST)', null),
        90 => $m(90, 'Res <b>Max</b> & "Co" \'TEST\'', 300, ['unit' => 'Month']),   // markup in uCRM's own name
        91 => $m(91, 'Kit <b>Pro</b> & "Co" \'TEST\'', 2000, ['unit' => 'Pc']),
    ];
    foreach ($over as $id => $set) {
        if ($set === null) { unset($p[$id]); continue; }
        $p[$id] = isset($p[$id]) ? array_merge($p[$id], $set) : $set;
    }
    ksort($p);
    return array_values($p);
}

function qfu_l_plans(array $over = []): array
{
    $one = function (int $id, string $name, $price, array $more = []) {
        return ['id' => $id, 'name' => $name, 'periods' => [['period' => 1, 'price' => $price, 'enabled' => true]]] + $more;
    };
    $p = [
        6  => $one(6, 'Residential Standard (TEST)', 100),
        7  => $one(7, 'Starter (TEST)', 110),
        8  => $one(8, 'Family Plan (TEST)', 130),
        9  => $one(9, 'Twin Plan (TEST)', 140),
        10 => $one(10, 'Twin Plan (TEST)', 150),
        11 => $one(11, 'Promo Plan (TEST)', 160),
        12 => $one(12, 'Promo Plan (TEST)', 155, ['archived' => true]),
        13 => $one(13, 'Corp Plan (TEST)', 170),
        14 => $one(14, 'Corp Plan (TEST)', 165, ['organizationId' => 3]),
        15 => $one(15, 'Odd Plan (TEST)', 180, ['price' => 99]),
        16 => ['id' => 16, 'name' => 'Quarter Plan (TEST)', 'periods' => [['period' => 3, 'price' => 500, 'enabled' => true]]],
        17 => ['id' => 17, 'name' => 'Double Plan (TEST)', 'periods' => [['period' => 1, 'price' => 200, 'enabled' => true],
                                                                     ['period' => 1, 'price' => 210, 'enabled' => true]]],
        18 => $one(18, 'Archived Basic (TEST)', 100, ['archived' => true]),
        19 => $one(19, 'Inactive Basic (TEST)', 100, ['isActive' => false]),
        20 => $one(20, 'Other Org Basic (TEST)', 100, ['organizationId' => 3]),
        21 => $one(21, 'Res <b>Max</b> & "Co" \'TEST\'', 300),
    ];
    foreach ($over as $id => $set) {
        if ($set === null) { unset($p[$id]); continue; }
        $p[$id] = isset($p[$id]) ? array_merge($p[$id], $set) : $set;
    }
    ksort($p);
    return array_values($p);
}

/** The fake uCRM reset to the section's catalogue (or the lists given), its log empty. */
function qfu_l_fake(QfuCtx $c, ?array $products = null, ?array $plans = null, array $more = []): void
{
    $c->ctl('kyc', '/__test/reset?scenario=uganda');
    $c->ctl('kyc', '/__test/set', ['products' => $products ?? qfu_l_products(), 'service_plans' => $plans ?? qfu_l_plans(), 'log' => []] + $more);
}

/** The tree every L request runs: one copy of the judged tree's lib/, profiles/, includes/, tabs/ and public.php. */
function qfu_l_tree(QfuCtx $c): string
{
    static $memo = [];
    if (!isset($memo[$c->root])) $memo[$c->root] = $c->tree(['includes', 'tabs', 'public.php']);
    return $memo[$c->root];
}

/** The book's configuration for a tenant key: ug (the gate on), ss, ug_off (the setting 0), ug2 (organization 2). */
function qfu_l_cfg(string $k): array
{
    $base = ['ug' => ['tenant_profile' => 'uganda'], 'ss' => ['tenant_profile' => 'south-sudan'],
             'ug_off' => ['tenant_profile' => 'uganda', 'ucrm_quote_organization_id' => '0'],
             'ug2' => ['tenant_profile' => 'uganda', 'ucrm_quote_organization_id' => '2']][$k];
    return $base + ['currency_code' => $k === 'ss' ? 'USD' : 'UGX', 'currency_symbol' => $k === 'ss' ? '$' : 'UGX',
                    'crm_base_url' => '', 'crm_auth_token' => ''];
}

/**
 * A data directory: rows (qfu_l_rows() unless 'plans' / 'devices' are given), 'links' (null: the table is never
 * created), 'apps', 'retailers' (the three accounts by default), on tenant 'k' (default ug), 'cfg' merged into its
 * kyc_config.json. Returns the directory.
 */
function qfu_l_seed(QfuCtx $c, array $o = []): string
{
    $k = $o['k'] ?? 'ug';
    $rows = qfu_l_rows();
    $ret = $o['retailers'] ?? [QFU_L_ADMIN, QFU_L_ADMIN2, QFU_L_AGENT];
    $r = $c->child('
$s->save("retailers.json", json_decode(' . var_export(json_encode($ret), true) . ', true));
file_put_contents($dir . "/csrf_secret.key", str_repeat("cd", 32));
return ["ok" => true];
', ['tree' => qfu_l_tree($c), 'tenant' => $k === 'ss' ? 'south-sudan' : 'uganda', 'cfg' => ($o['cfg'] ?? []) + qfu_l_cfg($k),
    'plans' => $o['plans'] ?? $rows['plans'], 'devices' => $o['devices'] ?? $rows['devices'],
    'links' => array_key_exists('links', $o) ? $o['links'] : null] + (isset($o['apps']) ? ['apps' => $o['apps']] : []));
    if (empty($r['value']['ok'])) throw new \RuntimeException('qfu_l_seed: ' . substr($r['out'] . $r['errlog'], 0, 600));
    return $r['dir'];
}

/**
 * One request through l_run.php. $case: entry (post|page|form|retry|token), and as l_run.php reads them: fields, get,
 * file, admin, session (a staff record, or null), session_age, headers, cfg, faults, hook, me2, app_id. The fake's log is
 * emptied first; the answer carries the requests that reached it.
 */
function qfu_l_req(QfuCtx $c, string $dir, array $case): array
{
    $cd = $c->dir('l-case');
    $case += ['root' => qfu_l_tree($c), 'data' => $dir, 'tz' => QFU_TZ, 'crm' => 'http://127.0.0.1:' . $c->fake('kyc') . '/api/v2.1',
              'result' => $cd . '/result.json', 'errlog' => $cd . '/php_errors.log'];
    file_put_contents($cd . '/case.json', json_encode($case));
    $c->ctl('kyc', '/__test/set', ['log' => []]);
    $env = array_filter(getenv(), function ($k) { return stripos((string)$k, 'proxy') === false; }, ARRAY_FILTER_USE_KEY);
    $p = proc_open(['php', __DIR__ . '/l_run.php', $cd . '/case.json'], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes, null, $env);
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    $rc = proc_close($p);
    $res = json_decode((string)@file_get_contents($case['result']), true);
    if (!is_array($res)) $res = ['end' => 'no result', 'out' => substr((string)$out, 0, 1500)];
    $flash = is_array($res['flash'] ?? null) ? $res['flash'] : null;
    return $res + ['rc' => $rc, 'msg' => (string)($flash['msg'] ?? ''), 'type' => (string)($flash['type'] ?? ''),
                   'requests' => $c->requests('kyc'), 'errlog' => (string)@file_get_contents($case['errlog'])];
}

/** The day's form token for a data directory, as the judged tree's csrfToken() makes it there. */
function qfu_l_token(QfuCtx $c, string $dir): string
{
    static $memo = [];   // one secret per data directory, one token per day: asked once
    if (!isset($memo[$dir]) || $memo[$dir] === '') $memo[$dir] = (string)(qfu_l_req($c, $dir, ['entry' => 'token'])['token'] ?? '');
    return $memo[$dir];
}

/**
 * A link action as its screen's form posts it: the fields, the day's token and the screen's own headers (Sec-Fetch-Site
 * same-origin, an Origin naming this host), as the admin (1). $o: session, session_age, headers (replacing the screen's),
 * token (false: none sent), cfg, faults, hook.
 */
function qfu_l_post(QfuCtx $c, string $dir, array $fields, array $o = []): array
{
    $tok = array_key_exists('token', $o) ? $o['token'] : qfu_l_token($c, $dir);
    if ($tok !== false && $tok !== null) $fields += ['_csrf' => $tok];
    $case = ['entry' => 'post', 'fields' => array_filter($fields, function ($v) { return $v !== null; }),
             'session' => array_key_exists('session', $o) ? $o['session'] : QFU_L_ADMIN,
             'session_age' => $o['session_age'] ?? 5,
             'headers' => $o['headers'] ?? ['HTTP_SEC_FETCH_SITE' => 'same-origin', 'HTTP_ORIGIN' => 'https://crm.example.test']];
    foreach (['cfg', 'faults', 'hook'] as $k) if (isset($o[$k])) $case[$k] = $o[$k];
    return qfu_l_req($c, $dir, $case);
}

/** ucrm_link_save for an item, as the picker posts it. */
function qfu_l_link(QfuCtx $c, string $dir, string $item, int $rowId, int $productId, ?int $servicePlanId = null, array $o = []): array
{
    $f = ['action' => 'ucrm_link_save', 'item' => $item, 'row_id' => (string)$rowId, 'product_id' => (string)$productId];
    if ($servicePlanId !== null) $f['service_plan_id'] = (string)$servicePlanId;
    return qfu_l_post($c, $dir, $f + ($o['more'] ?? []), $o);
}

/** ucrm_link_clear for an item, as Unlink posts it. */
function qfu_l_unlink(QfuCtx $c, string $dir, string $item, int $rowId, array $o = []): array
{
    return qfu_l_post($c, $dir, ['action' => 'ucrm_link_clear', 'item' => $item, 'row_id' => (string)$rowId] + ($o['more'] ?? []), $o);
}

/** A dashboard tab (subscription_plans.php, hardware.php, applications.php) drawn on a data directory; returns the HTML. */
function qfu_l_page(QfuCtx $c, string $dir, string $file, array $get = [], bool $admin = true, array $o = []): array
{
    @unlink($dir . '/ucrm_quote_catalogue_cache.json');   // the page's 60 s cache: each draw reads the fake as it is now
    $r = qfu_l_req($c, $dir, ['entry' => 'page', 'file' => $file, 'get' => (object)$get, 'admin' => $admin,
                              'session' => $admin ? QFU_L_ADMIN : QFU_L_AGENT] + $o);
    $r['html'] = (string)($r['html'] ?? ('NO PAGE ' . ($r['end'] ?? '') . ' ' . substr($r['errlog'] . ' ' . ($r['buffer'] ?? ''), 0, 1200)));
    return $r;
}

/** The KYC form drawn on a data directory: the HTML and its _q102 object (what the resolver answered per row). */
function qfu_l_form(QfuCtx $c, string $dir, array $o = []): array
{
    @unlink($dir . '/ucrm_quote_catalogue_cache.json');
    $r = qfu_l_req($c, $dir, ['entry' => 'form', 'admin' => false, 'session' => QFU_L_AGENT] + $o);
    $html = (string)($r['html'] ?? '');
    $q = preg_match('#^var _q102 = (.*);$#m', $html, $m) ? json_decode($m[1], true) : null;
    return ['html' => $html, 'q102' => $q, 'res' => $r];
}

/** The rows of one table of the store, by id, decoded — null when the table does not exist (read straight from the file). */
function qfu_l_rows_of(string $dir, string $table): ?array
{
    try {
        $pdo = new \PDO('sqlite:' . $dir . '/plugin.sqlite3', null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $has = $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = " . $pdo->quote($table))->fetchColumn();
        if ((int)$has === 0) return null;
        $out = [];
        foreach ($pdo->query('SELECT id, data FROM [' . $table . '] ORDER BY id')->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $out[(int)$row['id']] = json_decode((string)$row['data'], true);
        }
        return $out;
    } catch (\Throwable $e) {
        return ['__error' => $e->getMessage()];
    }
}

/** The link records, in stored order (null: no table). */
function qfu_l_links_of(string $dir): ?array
{
    $r = qfu_l_rows_of($dir, 'ucrm_quote_links');
    return $r === null ? null : array_values($r);
}

/** The active records of one item (ended_at_ts null), from records read straight from the store. */
function qfu_l_active(?array $records, string $item, int $rowId): array
{
    return array_values(array_filter((array)$records, function ($r) use ($item, $rowId) {
        return is_array($r) && ($r['item'] ?? null) === $item && (int)($r['row_id'] ?? -1) === $rowId && array_key_exists('ended_at_ts', $r)
            && $r['ended_at_ts'] === null;
    }));
}

/** The tables sqlite_master lists, sorted. */
function qfu_l_tables(string $dir): array
{
    try {
        $pdo = new \PDO('sqlite:' . $dir . '/plugin.sqlite3');
        $t = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' ORDER BY name")->fetchAll(\PDO::FETCH_COLUMN);
        return array_values(array_map('strval', $t));
    } catch (\Throwable $e) {
        return ['__error'];
    }
}

/** Every table's rows (the whole store), for "only the link store was written". */
function qfu_l_dump(string $dir): array
{
    $out = [];
    foreach (qfu_l_tables($dir) as $t) {
        if (strpos($t, 'sqlite_') === 0) continue;
        $out[$t] = qfu_l_rows_of($dir, $t);
    }
    return $out;
}

/** Replace one table's rows by hand (a record seeded or aged), straight in the file. */
function qfu_l_put(string $dir, string $table, array $rows): void
{
    $pdo = new \PDO('sqlite:' . $dir . '/plugin.sqlite3', null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('CREATE TABLE IF NOT EXISTS [' . $table . '] (id INTEGER PRIMARY KEY, data TEXT NOT NULL)');
    $pdo->exec('DELETE FROM [' . $table . ']');
    $st = $pdo->prepare('INSERT INTO [' . $table . '] (id, data) VALUES (?, ?)');
    foreach ($rows as $r) $st->execute([(int)$r['id'], json_encode($r, JSON_UNESCAPED_UNICODE)]);
}

/** The radio inputs of a picker panel: name => [value => [checked, disabled]]. */
function qfu_l_radios(string $html): array
{
    $out = [];
    if (!preg_match_all('#<input type="radio" name="(product_id|service_plan_id)" value="(\d+)"([^>]*)>#', $html, $m, PREG_SET_ORDER)) return [];
    foreach ($m as $x) $out[$x[1]][(int)$x[2]] = [strpos($x[3], ' checked') !== false, strpos($x[3], ' disabled') !== false];
    return $out;
}

/** The picker panel's own HTML (between id="ucrm-link" and the end of its card), '' when none is drawn. */
function qfu_l_panel(string $html): string
{
    $a = strpos($html, 'id="ucrm-link"');
    if ($a === false) return '';
    $b = strpos($html, '<!--', $a);
    return substr($html, $a, $b === false ? 200000 : $b - $a);
}

/** One picker entry's label (the <label> holding the radio of that id). */
function qfu_l_entry(string $html, string $name, int $id): string
{
    return preg_match('#<label[^>]*>\s*<input type="radio" name="' . $name . '" value="' . $id . '"[^>]*>(?:(?!</label>).)*</label>#s', $html, $m) ? $m[0] : '';
}

/**
 * The "uCRM link" cell of a row on the Plans or Hardware screen, found by the row's own name cell (the plan's
 * <td style="font-weight:700;font-size:13px;">, the kit's <td style="font-weight:700;">) and the first
 * <td style="font-size:12px;"> after it; for the installation, its card. '' when there is none.
 */
function qfu_l_cell(string $html, string $item, string $name): string
{
    if ($item === 'install') {
        $a = strpos($html, 'Installation (when DishNet installs)</span></div>');
        $b = $a === false ? false : strpos($html, '<!-- Fiber Installation Fee', $a);
        return ($a === false || $b === false) ? '' : substr($html, $a, $b - $a);
    }
    $e = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
    $head = $item === 'plan' ? '<td style="font-weight:700;font-size:13px;">' . $e . '</td>' : '<td style="font-weight:700;">' . $e;
    $a = strpos($html, $head);
    if ($a === false) return '';
    return preg_match('#<td style="font-size:12px;">(?:(?!</td>).)*</td>#s', $html, $m, 0, $a) ? $m[0] : '';
}
