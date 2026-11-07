<?php
declare(strict_types=1);
/**
 * Shared by tests/test_quote_agent_copy.php and its sections (tests/quote_agent_copy/<A…J>.php) — 5.18.104 Part P
 * (root docs/73 §H.5.12). TEST ONLY. Built on stage B1's harness (tests/quote_followup/_lib.php: QfCtx, the tree copy,
 * the weakened copies' runner).
 *
 *   qac_book()    a data folder of its own (nested one level, so a ucrm.json placed beside it reaches no other book),
 *                 with the tree's migrations, kyc_config.json = the configuration, customer_email_log made, the accounts
 *   qac_drive()   one run of tests/quote_agent_copy/drive.php on a tree (the judged copy, a stub tree, or 221c9dc)
 *   qac_base()    221c9dc's plugin, from Git (git archive), never the working tree; null when Git cannot supply it
 *   qac_stub()    a tree whose lib/QuoteAgentCopy.php throws first thing in the named public methods (fault injection)
 *   qac_relay()   fake_smtp_server.php (accepts everything), or fake_smtp_refuse.php with rules; qac_sessions() reads it
 *   qac_counter() a loopback HTTP server that answers 404 to everything and counts what it was asked (uCRM's /settings)
 *
 * Every person, account, number and address here is fictitious, under the reserved domain example.test.
 */

const QAC_BASE  = '221c9dc';
const QAC_AGENT = ['41' => 'agent.one@agents.example.test', '42' => 'admin.two@agents.example.test',
    '43' => 'gone.three@agents.example.test', '44' => 'billing@cust.example.test', '45' => 'other@cust.example.test',
    '46' => 'sales@company.example.test', '47' => 'quotes@company.example.test', '48' => 'renamed.eight@agents.example.test',
    '49' => 'kyc.nine@agents.example.test', '50' => 'kyc.ten@agents.example.test'];
const QAC_NAMES = ['Test Agent One', 'Test Admin Two', 'Test Gone Three', 'Test Same Four', 'Test Contact Five',
    'Test Reply Six', 'Test Sender Seven', 'Test Renamed Eight', 'Test Kyc Nine', 'Test Kyc Ten'];
/** The company's Reply-To (quote_company_email, email_reply_to) and its sender (smtp_from). */
const QAC_REPLY  = 'sales@company.example.test';
const QAC_SENDER = 'quotes@company.example.test';

/** The fixture accounts (retailers.json rows). */
function qac_accounts(): array
{
    $a = QAC_AGENT;
    return [
        ['id' => 41, 'name' => 'Test Agent One', 'email' => $a['41'], 'is_active' => true, 'role' => 'sales'],
        ['id' => 42, 'name' => 'Test Admin Two', 'email' => strtoupper($a['42']), 'is_active' => true, 'is_admin' => true, 'role' => 'admin'],
        ['id' => 43, 'name' => 'Test Gone Three', 'email' => $a['43'], 'is_active' => false, 'role' => 'sales'],
        ['id' => 44, 'name' => 'Test Same Four', 'email' => 'Billing@Cust.example.test', 'is_active' => true, 'role' => 'sales'],
        ['id' => 45, 'name' => 'Test Contact Five', 'email' => $a['45'], 'is_active' => true, 'role' => 'field_agent'],
        ['id' => 46, 'name' => 'Test Reply Six', 'email' => $a['46'], 'is_active' => true, 'role' => 'sales'],
        ['id' => 47, 'name' => 'Test Sender Seven', 'email' => $a['47'], 'is_active' => true, 'role' => 'sales'],
        ['id' => 48, 'name' => 'Test Renamed Eight', 'email' => $a['48'], 'is_active' => true, 'role' => 'sales'],
        ['id' => 49, 'name' => 'Test Kyc Nine', 'email' => $a['49'], 'is_active' => true, 'role' => 'sales'],
        ['id' => 50, 'name' => 'Test Kyc Ten', 'email' => $a['50'], 'is_active' => true, 'role' => 'retailer'],
        ['id' => 51, 'name' => 'Test No Mail', 'email' => '', 'is_active' => true, 'role' => 'sales'],
    ];
}

/** The fixture customer: a billing contact and another contact. */
function qac_client(array $more = []): array
{
    return $more + ['id' => 901, 'firstName' => 'Test', 'lastName' => 'Customer', 'organizationId' => 1, 'contacts' => [
        ['email' => 'other@cust.example.test', 'isBilling' => false],
        ['email' => 'billing@cust.example.test', 'isBilling' => true],
    ]];
}

/** A configuration: the country, the quotation switches on, P7's keys when $p7. */
function qac_cfg(string $country = 'uganda', bool $p7 = false, array $more = []): array
{
    $c = [
        'tenant_profile'             => $country,
        'ucrm_quote_organization_id' => '0',              // 5.18.99's quote check off: P7 does not depend on it
        'customer_emails_enabled'    => '1',
        'customer_email_quotation'   => '1',
        'quote_company_email'        => QAC_REPLY,
        'email_reply_to'             => QAC_REPLY,
        'kyc_config_ok'              => '1',
    ];
    if ($country === 'uganda') $c['timezone'] = QF_TZ;
    if ($p7) { $c['quote_agent_cc_enabled'] = '1'; $c['quote_agent_cc_since'] = gmdate('Y-m-d H:i:s', time() - 7200); }
    foreach ($more as $k => $v) { if ($v === null) unset($c[$k]); else $c[$k] = $v; }
    return $c;
}

/** The plugin.sqlite3 the books are copied from (the judged tree's migrations), made once. */
function qac_template(QfCtx $ctx): string
{
    static $tpl = null;
    if ($tpl !== null) return $tpl;
    $b = $ctx->book([], 'tpl');
    $b['pdo']->exec('PRAGMA wal_checkpoint(TRUNCATE)');
    $tpl = $ctx->dir('tplcopy') . '/plugin.sqlite3';
    copy($b['data'] . '/plugin.sqlite3', $tpl);
    return $tpl;
}

const QAC_LOG_DDL = "CREATE TABLE IF NOT EXISTS customer_email_log (
                    dedupe_key TEXT PRIMARY KEY,
                    template   TEXT NOT NULL,
                    recipient  TEXT NOT NULL,
                    status     TEXT NOT NULL DEFAULT 'claimed',
                    error      TEXT NOT NULL DEFAULT '',
                    created_at TEXT NOT NULL DEFAULT (datetime('now')),
                    sender     TEXT NOT NULL DEFAULT ''
                 )";

/**
 * A book: <dir>/data with the tree's migrations, kyc_config.json = $cfg, customer_email_log made (the dispatcher makes it
 * once per process, lib/CustomerEmailDispatcher.php table()), the accounts, and plugin SMTP on $port when given.
 */
function qac_book(QfCtx $ctx, array $cfg, string $tag = 'b', ?int $port = null, ?array $accounts = null): array
{
    $tpl = qac_template($ctx);
    $dir = $ctx->dir($tag);
    $data = $dir . '/data';
    @mkdir($data, 0777, true);
    copy($tpl, $data . '/plugin.sqlite3');
    file_put_contents($data . '/kyc_config.json', json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    $store = SqliteStore::create($data);
    $pdo = $store->getPdo();
    $pdo->exec(QAC_LOG_DDL);
    $store->save('retailers.json', $accounts ?? qac_accounts());
    if ($port !== null) qac_mail($data, $port);
    return ['dir' => $dir, 'data' => $data, 'store' => $store, 'pdo' => $pdo, 'cfg' => $cfg];
}

/** email_settings.json: plugin SMTP on the loopback relay (or the fields given). */
function qac_mail(string $data, int $port, array $more = []): void
{
    file_put_contents($data . '/email_settings.json', json_encode($more + [
        'use_ucrm_email' => false, 'smtp_host' => '127.0.0.1', 'smtp_port' => $port, 'smtp_user' => '', 'smtp_pass' => '',
        'smtp_enc' => '', 'smtp_from' => QAC_SENDER, 'quote_email_via_plugin' => true,
    ]));
}

/** Write the configuration file again (and keep the book's copy in step). */
function qac_set_cfg(array &$b, array $cfg): void
{
    $b['cfg'] = $cfg;
    file_put_contents($b['data'] . '/kyc_config.json', json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

/**
 * One run of drive.php on $root. $spec: steps, crm, mailer, rec_ok, rec_log (root, data, config, out and error_log are
 * filled in). The answer carries the run's PHP error log ('elog') and its exit code ('rc').
 */
function qac_drive(QfCtx $ctx, string $root, array $b, array $spec): array
{
    static $n = 0;
    $n++;
    $f = $b['dir'] . '/spec-' . $n . '.json';
    $spec += ['root' => $root, 'data' => $b['data'], 'config' => $b['cfg'], 'out' => $b['dir'] . '/out-' . $n . '.json',
              'error_log' => $b['dir'] . '/php-error-' . $n . '.log'];
    file_put_contents($f, json_encode($spec, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    $o = [];
    exec('php ' . escapeshellarg(__DIR__ . '/drive.php') . ' ' . escapeshellarg($f) . ' 2>&1', $o, $rc);
    $r = json_decode((string)@file_get_contents($spec['out']), true);
    if (!is_array($r)) $r = ['steps' => [], 'crm' => [], 'mail' => [], 'whlog' => [], 'qac_loaded' => null, 'post_saw' => [], 'broken' => implode("\n", $o)];
    $r['rc'] = $rc;
    $r['elog'] = (string)@file_get_contents($spec['error_log']);
    return $r;
}

/** 221c9dc's plugin from Git, extracted once (tests and docs left out); null when Git cannot supply it. */
function qac_base(QfCtx $ctx): ?string
{
    static $base = false;
    if ($base !== false) return $base;
    $top = trim((string)shell_exec('git -C ' . escapeshellarg(dirname(__DIR__, 2)) . ' rev-parse --show-toplevel 2>/dev/null'));
    $x = $ctx->tmp . '/base';
    @mkdir($x, 0700, true);
    $o = [];
    exec('git -C ' . escapeshellarg($top) . ' archive ' . QAC_BASE . ' dishnet-hybrid-sudan | tar -x -C ' . escapeshellarg($x) . ' 2>&1', $o, $rc);
    $p = $x . '/dishnet-hybrid-sudan';
    if ($top === '' || $rc !== 0 || !is_file($p . '/lib/QuotationService.php')) return $base = null;
    exec('rm -rf ' . escapeshellarg($p . '/tests') . ' ' . escapeshellarg($p . '/docs') . ' ' . escapeshellarg($p . '/prototype'));
    @mkdir($p . '/data', 0700, true);
    return $base = $p;
}

/** One file of 221c9dc, from Git; null when Git cannot supply it. */
function qac_base_file(string $rel): ?string
{
    $top = trim((string)shell_exec('git -C ' . escapeshellarg(dirname(__DIR__, 2)) . ' rev-parse --show-toplevel 2>/dev/null'));
    if ($top === '') return null;
    $o = (string)shell_exec('git -C ' . escapeshellarg($top) . ' show ' . escapeshellarg(QAC_BASE . ':dishnet-hybrid-sudan/' . $rel)
        . ' 2>/dev/null; printf "\\n__RC=%s" "$?"');
    if (!preg_match('/\n__RC=(\d+)$/', $o, $m) || $m[1] !== '0') return null;
    return substr($o, 0, -strlen($m[0]));
}

/**
 * A tree whose lib/QuoteAgentCopy.php is the judged tree's with `throw` as the first statement of each named public
 * method ('*' = every public method). One copy of the tree, made once; the file is rewritten for each stub.
 */
function qac_stub(QfCtx $ctx, string $methods): string
{
    static $tree = null, $real = null;
    if ($tree === null) {
        $tree = $ctx->tmp . '/stubtree';
        exec('cp -R ' . escapeshellarg($ctx->tree()) . ' ' . escapeshellarg($tree));
        $real = (string)file_get_contents($ctx->tree() . '/lib/QuoteAgentCopy.php');
    }
    $want = $methods === '*' ? null : explode(',', $methods);
    $src = preg_replace_callback('/(public static function (\w+)\([^{]*?\)\s*(?::\s*\??[\w\\\\]+)?\s*\{)/s', function ($m) use ($want) {
        if ($want !== null && !in_array($m[2], $want, true)) return $m[1];
        return $m[1] . "\n        throw new \\RuntimeException('qac stub " . $m[2] . "');";
    }, $real);
    file_put_contents($tree . '/lib/QuoteAgentCopy.php', $src);
    return $tree;
}

/** The public methods of the judged tree's QuoteAgentCopy, in file order. */
function qac_public_methods(QfCtx $ctx): array
{
    preg_match_all('/public static function (\w+)\(/', (string)file_get_contents($ctx->tree() . '/lib/QuoteAgentCopy.php'), $m);
    return $m[1];
}

/** A relay: fake_smtp_server.php when $rules is null, else fake_smtp_refuse.php with them. [port, transcript]. */
function qac_relay(QfCtx $ctx, ?array $rules = null, int $idle = 120): array
{
    $dir = $ctx->dir('relay');
    $tr = $dir . '/transcript.json';
    $fx = dirname(__DIR__) . '/fixtures/';
    $args = $rules === null ? '' : '';
    if ($rules !== null) { file_put_contents($dir . '/rules.json', json_encode($rules)); }
    for ($slot = 0; $slot < 40; $slot++) {
        $port = 21000 + ((getmypid() * 13 + $slot * 97 + (int)(microtime(true) * 1000) % 997) % 18000);
        $cmd = $rules === null
            ? sprintf('exec php %s %d %s %d', escapeshellarg($fx . 'fake_smtp_server.php'), $port, escapeshellarg($tr), $idle)
            : sprintf('exec php %s %d %s %d %s', escapeshellarg($fx . 'fake_smtp_refuse.php'), $port, escapeshellarg($tr), $idle, escapeshellarg($dir . '/rules.json'));
        @unlink($tr);
        $p = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        if (!is_resource($p)) continue;
        $ok = false;
        for ($i = 0; $i < 50 && !$ok; $i++) { usleep(60000); clearstatcache(); $ok = is_file($tr); }
        if ($ok) {
            register_shutdown_function(function () use ($p) { if (is_resource($p)) { @proc_terminate($p); @proc_close($p); } });
            return [$port, $tr];
        }
        @proc_terminate($p);
    }
    return [0, $tr];
}

/** The sessions a relay recorded that carried a recipient (accepted or refused). */
function qac_sessions(string $tr, int $want = 0): array
{
    for ($i = 0; $i < 60; $i++) {
        clearstatcache();
        $all = json_decode((string)@file_get_contents($tr), true) ?: [];
        $s = array_values(array_filter($all, function ($x) { return !empty($x['rcpt_to']) || !empty($x['refused']); }));
        if (count($s) >= $want) return $s;
        usleep(50000);
    }
    return $s ?? [];
}

/** A session with what varies from run to run masked: Date, Message-ID, MIME boundaries. */
function qac_mask($v)
{
    if (is_array($v)) { foreach ($v as $k => $x) $v[$k] = qac_mask($x); return $v; }
    if (!is_string($v)) return $v;
    $v = preg_replace('/^Date: .*$/m', 'Date: <masked>', $v);
    $v = preg_replace('/^Message-ID: .*$/mi', 'Message-ID: <masked>', $v);
    $v = preg_replace('/(boundary="?)[^"\s;]+/', '$1<masked>', $v);
    $v = preg_replace('/^--[=_A-Za-z0-9.\-]{8,}(--)?\s*$/m', '--<masked>$1', $v);
    return $v;
}

/** A PHP error log with its time stamps masked. */
function qac_elog(string $s): string
{
    return (string)preg_replace('/^\[[^\]]+\] /m', '[t] ', $s);
}

/** A loopback HTTP server answering 404 and counting its requests (written one per line to its log). [base URL, log]. */
function qac_counter(QfCtx $ctx): array
{
    $dir = $ctx->dir('counter');
    $log = $dir . '/requests.log';
    file_put_contents($dir . '/router.php', '<?php file_put_contents(' . var_export($log, true)
        . ', ($_SERVER["REQUEST_METHOD"] ?? "") . " " . ($_SERVER["REQUEST_URI"] ?? "") . "\n", FILE_APPEND | LOCK_EX);'
        . ' if (($_SERVER["REQUEST_URI"] ?? "") === "/__probe") { echo "QAC-COUNTER"; return true; } http_response_code(404); echo "{}"; return true;');
    [$port] = qf_serve('-t ' . escapeshellarg($dir) . ' ' . escapeshellarg($dir . '/router.php'), function (int $p): bool {
        return strpos(qf_http('GET', "http://127.0.0.1:{$p}/__probe", null, [], 2)[2], 'QAC-COUNTER') !== false;
    }, 14200);
    return [$port === 0 ? '' : "http://127.0.0.1:{$port}", $log];
}

/** How many requests the counter has taken that were not its own probe. */
function qac_counted(string $log): int
{
    clearstatcache();
    $n = 0;
    foreach (file($log) ?: [] as $l) if (strpos($l, '/__probe') === false) $n++;
    return $n;
}

/** The decisions in a book, decoded, in id order (read straight from SQLite: never through the store, which creates tables). */
function qac_decisions(\PDO $pdo, string $t = 'quote_agent_copies'): array
{
    $st = $pdo->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ?");
    $st->execute([$t]);
    if ($st->fetchColumn() === false) return [];
    $out = [];
    foreach ($pdo->query("SELECT id, data FROM {$t} ORDER BY id")->fetchAll(\PDO::FETCH_ASSOC) as $r) {
        $d = json_decode((string)$r['data'], true);
        $out[] = is_array($d) ? $d : [];
    }
    return $out;
}

/** Whether the book holds P7's tables. */
function qac_tables(\PDO $pdo): array
{
    return $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name LIKE 'quote_agent_%' ORDER BY name")->fetchAll(\PDO::FETCH_COLUMN);
}

/** A read-only connection on the book, as the tool's $ro (ruling R13). */
function qac_ro(string $data): \PDO
{
    $ro = new \PDO('sqlite:' . $data . '/plugin.sqlite3');
    $ro->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    $ro->exec('PRAGMA query_only = ON');
    return $ro;
}

/** A decision row as record() writes it, for the check's planted fixtures. */
function qac_plant(array $b, array $row): void
{
    $b['store']->appendWithId('quote_agent_copies.json', $row + ['quote_id' => 0, 'number' => '', 'path' => 'plugin', 'outcome' => 'added',
        'code' => 'added', 'source' => 'actor', 'rule' => '', 'agent_id' => 41, 'rcpt' => 'accepted', 'send_ok' => 1, 'decided_at' => '']);
}

/** quote_mail.log's sent line, as emailQuotePdf writes it. */
function qac_line(array $b, int $t, string $number, string $to = 'billing@cust.example.test'): void
{
    file_put_contents($b['data'] . '/quote_mail.log', '[' . gmdate('Y-m-d H:i:s', $t) . "] {$number} -> {$to} sent\n", FILE_APPEND);
}

/** A sent customer_email_log row for quotation <id>, created at $t. */
function qac_row(array $b, int $id, int $t, string $status = 'sent'): void
{
    $b['pdo']->prepare("INSERT OR REPLACE INTO customer_email_log (dedupe_key, template, recipient, status, created_at, sender) VALUES (?, 'quotation', ?, ?, ?, ?)")
        ->execute(['quotation:' . $id, 'billing@cust.example.test', $status, gmdate('Y-m-d H:i:s', $t), QAC_SENDER]);
}

/** Plant a KYC application (a store row of kyc_applications.json); crm_client_id as KycService stores it, a string (lib/KycService.php:888). */
function qac_app(array $b, array $app): int
{
    if (isset($app['crm_client_id'])) $app['crm_client_id'] = (string)$app['crm_client_id'];
    $r = $b['store']->appendWithId('kyc_applications.json', $app);
    return (int)$r['id'];
}

/** The judged tree's QuoteAgentCopy loaded in this process (and what it needs). */
function qac_load(QfCtx $ctx): void
{
    $t = $ctx->tree();
    foreach (['StoreInterface', 'SqliteStore', 'PluginConfig', 'StaffJobsGate', 'StaffDirectory', 'MailService', 'EmailRecipients',
              'CustomerEmailDispatcher', 'QuoteSendEvidence', 'QuoteAgentCopy'] as $l) require_once $t . '/lib/' . $l . '.php';
}

/**
 * Client copies that make "Cc: " + $own + the copies + $agent exactly $target octets, each a valid address (local part at
 * most 64 characters), distinct and lower-case.
 */
function qac_fill(int $target, array $own, string $agent): array
{
    $line = function (array $l) use ($agent): int { return strlen('Cc: ' . implode(', ', array_merge($l, [$agent]))); };
    $addr = function (int $len, int $i): string { return 'c' . sprintf('%02d', $i) . str_repeat('x', $len - 3 - 18) . '@cust.example.test'; };
    $l = $own; $out = []; $i = 0;
    while (true) {
        $rem = $target - $line($l);
        if ($rem - 2 <= 80) break;
        $a = $addr(min(60, $rem - 2 - 26), $i++);
        $l[] = $a; $out[] = $a;
    }
    $a = $addr($target - $line($l) - 2, $i);
    $out[] = $a;
    return $out;
}

/** Whether the QEMAIL claim for quote $id is held (0 when the claim table was never made). */
function qac_qemail(\PDO $pdo, int $id): int
{
    if ($pdo->query("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'notification_dedup'")->fetchColumn() === false) return 0;
    return (int)$pdo->query("SELECT COUNT(*) FROM notification_dedup WHERE dedup_key = 'QEMAIL{$id}'")->fetchColumn();
}
