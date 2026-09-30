<?php
declare(strict_types=1);
/**
 * staff_jobs_sandbox.php — TEST ONLY. A copy of the plugin served on 127.0.0.1 for the 5.18.50 staff and job tests
 * (docs/44, release A), beside a fake uCRM (fake_ucrm_staff_jobs.php) and the repository's fake Evolution server.
 *
 * Every person, number, e-mail and job in these tests is fictitious, and nothing leaves the machine: the plugin's uCRM
 * and Evolution addresses are the two fakes, and proxies are cleared for every process started here.
 *
 * Each server is proved to be the one this sandbox started — the process must still be running and answer with this
 * sandbox's own marker — so a nested run (a weakened copy tested from inside a test) can never borrow its parent's
 * servers on a port both happened to choose.
 */
final class SjSandbox
{
    public $root; public $sb; public $plug; public $data; public $vault;
    public $crm = ''; public $evo = ''; public $base = '';
    public $whKey = 'sj-webhook-secret';
    public $evoKey;
    /** @var array<string,string> Bearer tokens by staff key */
    public $tok = [];
    /** @var array<string,int> staff ids by key */
    public $ids = [];
    /** @var array<string,string> cookie jars by staff key */
    public $jars = [];
    public $cfg = [];
    private $procs = [];
    private $env = [];

    /** The plugin directories a sandbox does not need. */
    private const SKIP = ['tests', 'docs', 'prototype', 'dishnet-mikrotik-control-plane', 'data', '.git'];

    /**
     * $opt['workers']: the plugin's server answers that many requests at once, as PHP-FPM does on the server — for a test
     * in which one request runs while another waits (5.18.53). Without it, one request at a time, as before.
     */
    public static function start(string $root, array $cfg, string $tag = 'sj', array $opt = []): self
    {
        $s = new self();
        $s->root = rtrim($root, '/');
        foreach (['http_proxy', 'https_proxy', 'HTTP_PROXY', 'HTTPS_PROXY', 'ALL_PROXY', 'all_proxy', 'no_proxy', 'NO_PROXY'] as $v) putenv($v);
        $s->env = array_filter(getenv(), function ($k) { return stripos((string)$k, 'proxy') === false; }, ARRAY_FILTER_USE_KEY);
        $s->sb   = sys_get_temp_dir() . '/' . $tag . '_' . getmypid() . '_' . bin2hex(random_bytes(3));
        $s->plug = $s->sb . '/plugin';
        $s->data = $s->plug . '/data';
        $s->vault = $s->sb . '/vault.json';
        mkdir($s->plug, 0700, true);
        foreach (scandir($s->root) ?: [] as $e) {
            if ($e === '.' || $e === '..' || in_array($e, self::SKIP, true)) continue;
            exec('cp -R ' . escapeshellarg($s->root . '/' . $e) . ' ' . escapeshellarg($s->plug . '/') . ' 2>/dev/null');
        }
        mkdir($s->data, 0700, true);
        file_put_contents($s->plug . '/ucrm.json', json_encode(['pluginDataDir' => $s->data]));
        putenv('DN_VAULT_FILE=' . $s->vault);
        putenv('DN_DATA_DIR=' . $s->data);
        register_shutdown_function([$s, 'stop']);

        // Fake uCRM, answering with this sandbox's own marker.
        $crmState = $s->sb . '/fake_ucrm.json';
        $crmPort = $s->serve(sprintf('exec php -S 127.0.0.1:{PORT} %s', escapeshellarg($s->root . '/tests/fixtures/fake_ucrm_staff_jobs.php')),
            function (int $p) use ($s): bool { return strpos($s->http('GET', "http://127.0.0.1:{$p}/__test/state")[1], 'FAKE-UCRM-STAFF-JOBS') === 0; },
            12200, ['FAKE_UCRM_STATE' => $crmState]);
        if (!$crmPort) throw new \RuntimeException('the fake uCRM did not start');
        $s->crm = "http://127.0.0.1:{$crmPort}";

        // Fake Evolution: its state file is keyed by its port, so a reset here clears only this sandbox's record.
        $evoPort = $s->serve(sprintf('exec php -S 127.0.0.1:{PORT} %s', escapeshellarg($s->root . '/tests/fixtures/fake_evo_server.php')),
            function (int $p) use ($s): bool { return strpos($s->http('GET', "http://127.0.0.1:{$p}/__test/state")[1], 'FAKE-EVO-TEST') !== false; },
            12400);
        if (!$evoPort) throw new \RuntimeException('the fake Evolution server did not start');
        $s->evo = "http://127.0.0.1:{$evoPort}";
        $s->http('GET', "{$s->evo}/__test/reset");

        // The plugin's own configuration, in the store and in the file PluginConfig and dn_tz() read.
        $s->evoKey = str_repeat('cd', 32);
        file_put_contents($s->data . '/webhook_secret', $s->evoKey);
        @chmod($s->data . '/webhook_secret', 0600);
        $s->cfg = $cfg + [
            'data_dir' => $s->data, 'dry_run_mode' => false,
            'crm_base_url' => $s->crm, 'crm_auth_token' => 'SJ-TEST-KEY',
            'webhook_secret' => $s->whKey,
            'evo_api_url' => $s->evo, 'evo_api_key' => 'sj-evo-key',
            'evo_instance_support' => 'sj-support', 'evo_instance_account' => 'sj-account', 'evo_instance_sales' => 'sj-sales',
            'customer_emails_enabled' => '0',
        ];
        $store = $s->store();
        $store->save('kyc_config.json', $s->cfg);
        file_put_contents($s->data . '/kyc_config.json', json_encode($s->cfg));
        // An admin's first dashboard page runs the piggyback cron (public.php): cron/master.php, after the page — and under
        // php -S the connection stays open until it ends. master.php reads wa.dishnetafrica.com and dishnetss.com, real
        // hosts a sandbox must never contact (measured: a TLS session to the first, DNS waits on the second that outlast
        // the client's 90 s). Marked as just run, ten years ahead, it does not run here.
        file_put_contents($s->data . '/piggyback_last_run.txt', (string)(time() + 10 * 365 * 86400));

        // The plugin, proved by a nonce only this sandbox knows. exec is disabled: no background worker is spawned.
        $nonce = bin2hex(random_bytes(8));
        file_put_contents($s->plug . '/__nonce.txt', $nonce);
        $env = ['DN_VAULT_FILE' => $s->vault, 'DN_DATA_DIR' => $s->data];
        if ((int)($opt['workers'] ?? 0) > 1) $env['PHP_CLI_SERVER_WORKERS'] = (string)(int)$opt['workers'];
        $port = $s->serve(sprintf('exec php -d disable_functions=exec -S 127.0.0.1:{PORT} -t %s', escapeshellarg($s->plug)),
            function (int $p) use ($s, $nonce): bool { return trim($s->http('GET', "http://127.0.0.1:{$p}/__nonce.txt")[1]) === $nonce; },
            12600, $env);
        @unlink($s->plug . '/__nonce.txt');
        if (!$port) throw new \RuntimeException('the plugin did not start');
        $s->base = "http://127.0.0.1:{$port}/public.php";
        return $s;
    }

    private function serve(string $cmd, callable $isUp, int $base, array $extraEnv = []): int
    {
        foreach (range(0, 11) as $slot) {
            $port = $base + ((getmypid() * 7 + $slot * 13 + random_int(0, 5)) % 180);
            $p = proc_open(str_replace('{PORT}', (string)$port, $cmd),
                [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
                $pipes, null, array_merge($this->env, $extraEnv));
            if (!is_resource($p)) continue;
            $up = false;
            for ($i = 0; $i < 80 && !$up; $i++) {
                usleep(100000);
                $st = proc_get_status($p);
                if (!$st['running']) break;                       // could not bind: somebody else's port
                $up = $isUp($port) && proc_get_status($p)['running'];
            }
            if ($up) { $this->procs[] = $p; return $port; }
            proc_terminate($p); proc_close($p);
        }
        return 0;
    }

    /** A process a caller started for this sandbox (a relay, say), stopped with the rest. */
    public function adopt($proc): void
    {
        $this->procs[] = $proc;
    }

    public function stop(): void
    {
        foreach ($this->procs as $p) { if (is_resource($p)) { proc_terminate($p); proc_close($p); } }
        $this->procs = [];
        if ($this->sb && is_dir($this->sb)) exec('rm -rf ' . escapeshellarg($this->sb));
        foreach ($this->jars as $j) @unlink($j);
    }

    // ── HTTP ─────────────────────────────────────────────────────────────────
    /** @return array{0:int,1:string,2:mixed,3:string} status, body, decoded JSON, Location header */
    public function http(string $method, string $url, $body = null, array $headers = [], ?string $jar = null): array
    {
        $ch = curl_init($url);
        $loc = '';
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 90, CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*', CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers,
            CURLOPT_HEADERFUNCTION => function ($c, $h) use (&$loc) {
                if (stripos($h, 'Location:') === 0) $loc = trim(substr($h, 9));
                return strlen($h);
            }]);
        if ($jar !== null) { curl_setopt($ch, CURLOPT_COOKIEJAR, $jar); curl_setopt($ch, CURLOPT_COOKIEFILE, $jar); }
        if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, is_string($body) ? $body : json_encode($body));
        $r = curl_exec($ch);
        $c = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return [$r === false ? 0 : $c, (string)$r, json_decode((string)$r, true), $loc];
    }

    /** The staff API as a signed-in account (Bearer token). */
    public function api(string $who, string $method, string $action, ?array $body = null, string $qs = ''): array
    {
        return $this->http($method, "{$this->base}?page=api&action={$action}{$qs}", $body,
            ['Content-Type: application/json', 'Authorization: Bearer ' . $this->tok[$who]]);
    }

    /** uCRM's webhook, as uCRM sends it. */
    public function fire(string $changeType, string $entity, int $id, string $uuid): array
    {
        return $this->http('POST', "{$this->base}?page=crm_webhook", ['changeType' => $changeType, 'entity' => $entity,
            'entityId' => $id, 'uuid' => $uuid, 'extraData' => ['entity' => ['id' => $id]]],
            ['Content-Type: application/json', 'X-Ucrm-Key: ' . $this->whKey]);
    }

    /** An inbound WhatsApp through Evolution's webhook. */
    public function evoInbound(string $number, string $text, string $instance = 'sj-support', string $id = ''): array
    {
        $payload = ['event' => 'messages.upsert', 'instance' => $instance, 'data' => [
            'key' => ['id' => $id !== '' ? $id : 'SJ-IN-' . bin2hex(random_bytes(5)), 'fromMe' => false,
                      'remoteJid' => $number . '@s.whatsapp.net'],
            'message' => ['conversation' => $text], 'messageTimestamp' => time(), 'pushName' => 'Sandbox Person']];
        return $this->http('POST', "{$this->base}?page=evo_webhook", $payload,
            ['Content-Type: application/json', 'X-DishNet-Token: ' . $this->evoKey]);
    }

    // ── Web session (the panel pages and their forms) ────────────────────────
    public function login(string $who, string $email, string $password): string
    {
        $jar = $this->sb . '/jar_' . $who . '.txt';
        $this->jars[$who] = $jar;
        $this->http('GET', "{$this->base}?page=login", null, [], $jar);
        $r = $this->http('POST', "{$this->base}?page=login",
            http_build_query(['action' => 'do_login', 'identifier' => $email, 'password' => $password]),
            ['Content-Type: application/x-www-form-urlencoded'], $jar);
        if (strpos($r[3], 'page=dashboard') === false) throw new \RuntimeException("login as {$who} failed: " . $r[0] . ' ' . $r[3]);
        return $jar;
    }

    public function page(string $who, string $qs): string
    {
        return $this->http('GET', "{$this->base}?{$qs}", null, [], $this->jars[$who])[1];
    }

    /** A panel form, with the day's CSRF token read from a page this account can see. */
    public function form(string $who, array $fields, string $csrfFrom = 'page=dashboard&tab=retailers'): array
    {
        if (!isset($fields['_csrf'])) {
            preg_match('/name="_csrf" value="([^"]+)"/', $this->page($who, $csrfFrom), $m);
            $fields['_csrf'] = html_entity_decode($m[1] ?? '', ENT_QUOTES);
        }
        return $this->http('POST', "{$this->base}?page=dashboard", http_build_query($fields),
            ['Content-Type: application/x-www-form-urlencoded'], $this->jars[$who]);
    }

    /** The flash message the last form left for this account, read from the next page. */
    public function flash(string $who, string $qs = 'page=dashboard&tab=retailers'): string
    {
        $html = $this->page($who, $qs);
        if (preg_match('#<div class="kyc-alert ([a-z]+)">(.*?)</div>#s', $html, $m)) {
            return $m[1] . ': ' . trim((string)preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($m[2]), ENT_QUOTES)));
        }
        return '';
    }

    // ── The fake uCRM ────────────────────────────────────────────────────────
    public function seedCrm(array $state): void { $this->http('POST', "{$this->crm}/__test/seed", $state); }
    public function crmDump(): array { return (array)($this->http('GET', "{$this->crm}/__test/dump")[2] ?? []); }
    public function crmReqs(string $method, string $pathRe): array
    {
        return array_values(array_filter($this->crmDump()['requests'] ?? [], function ($r) use ($method, $pathRe) {
            return $r['method'] === $method && preg_match($pathRe, (string)$r['path']);
        }));
    }
    public function usersDown(bool $down): void { $this->http('POST', "{$this->crm}/__test/users_down", ['down' => $down]); }

    // ── The fake Evolution ───────────────────────────────────────────────────
    public function texts(): array { return (array)($this->http('GET', "{$this->evo}/__test/state")[2]['text_calls'] ?? []); }

    // ── A fake SMTP relay ────────────────────────────────────────────────────
    /** @var string the relay's transcript, '' until mailRelay() starts one */
    public $smtpTranscript = '';

    /**
     * A relay of this sandbox's own, and the plugin's mail settings pointed at it: [port, transcript]. It stops after
     * $idle seconds without a connection.
     */
    public function mailRelay(int $idle = 30): array
    {
        $transcript = $this->sb . '/smtp.json';
        foreach (range(0, 11) as $slot) {
            $port = 12800 + ((getmypid() * 5 + $slot * 11 + random_int(0, 5)) % 180);
            $p = proc_open(sprintf('exec %s %s %d %s %d', escapeshellarg(PHP_BINARY), escapeshellarg($this->root . '/tests/fixtures/fake_smtp_server.php'),
                    $port, escapeshellarg($transcript), $idle),
                [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
            for ($i = 0; $i < 50; $i++) {
                usleep(100000);
                if (!proc_get_status($p)['running']) break;
                if (!is_file($transcript)) continue;
                $c = @fsockopen('127.0.0.1', $port, $e1, $e2, 2);
                if ($c) {
                    $g = fgets($c, 256); fwrite($c, "QUIT\r\n"); fclose($c);
                    if (strpos((string)$g, 'fake.smtp.test') !== false) {
                        $this->adopt($p);
                        $this->smtpTranscript = $transcript;
                        file_put_contents($this->data . '/email_settings.json', json_encode(['use_ucrm_email' => false, 'smtp_host' => '127.0.0.1',
                            'smtp_port' => $port, 'smtp_user' => '', 'smtp_pass' => '', 'smtp_enc' => '', 'smtp_from' => 'accounts@example.test']));
                        return [$port, $transcript];
                    }
                }
            }
            proc_terminate($p); proc_close($p); @unlink($transcript);
        }
        throw new \RuntimeException('the fake SMTP relay did not start');
    }

    /** Every message the relay took, in order: envelope, the headers that matter, and the two parts, CRLF read as LF. */
    public function mails(): array
    {
        $out = [];
        foreach (json_decode((string)@file_get_contents($this->smtpTranscript), true) ?: [] as $m) {
            if (trim((string)($m['data'] ?? '')) === '') continue;   // the readiness probe: a connection and no message
            $out[] = ['from' => (string)$m['mail_from'], 'to' => (array)$m['rcpt_to']] + self::parseMail((string)$m['data']);
        }
        return $out;
    }

    /** One message's headers (From, To, Subject, Reply-To) and its text/plain and text/html parts. */
    public static function parseMail(string $data): array
    {
        $d = str_replace("\r\n", "\n", $data);
        [$head, $body] = array_pad(explode("\n\n", $d, 2), 2, '');
        $h = [];
        foreach (explode("\n", (string)preg_replace('/\n[ \t]+/', ' ', $head)) as $line) {
            if (preg_match('/^([A-Za-z-]+):\s*(.*)$/', $line, $m)) $h[strtolower($m[1])] = $m[2];
        }
        // A part ends where its boundary line begins: a message's own text may hold "---".
        $b = preg_match('/boundary="?([^";\s]+)"?/i', (string)($h['content-type'] ?? ''), $bm) ? $bm[1] : '';
        $part = function (string $type) use ($body, $b): ?string {
            if ($b === '') return null;
            return preg_match('#Content-Type: ' . preg_quote($type, '#') . '; charset=UTF-8\nContent-Transfer-Encoding: 8bit\n\n(.*?)\n\n--' . preg_quote($b, '#') . '#s', $body, $m) ? $m[1] : null;
        };
        return ['header_from' => $h['from'] ?? '', 'header_to' => $h['to'] ?? '', 'subject' => $h['subject'] ?? '', 'reply_to' => $h['reply-to'] ?? '',
                'text' => $part('text/plain'), 'html' => $part('text/html')];
    }

    // ── The store ────────────────────────────────────────────────────────────
    public function store(): \SqliteStore
    {
        if (!class_exists('SqliteStore')) {
            require_once $this->root . '/lib/StoreInterface.php';
            require_once $this->root . '/lib/SqliteStore.php';
        }
        return \SqliteStore::create($this->data);
    }

    /** A staff account with an API token and a password for the web panel. */
    public function staff(string $key, array $row, string $password = 'sj-password-1'): int
    {
        $this->tok[$key] = 'SJ-' . strtoupper($key) . '-' . bin2hex(random_bytes(8));
        $rec = $this->store()->appendWithId('retailers.json', $row + ['is_active' => true, 'api_token' => $this->tok[$key],
            'token_issued_at' => time(), 'password' => password_hash($password, PASSWORD_BCRYPT, ['cost' => 4])]);
        return $this->ids[$key] = (int)($rec['id'] ?? 0);
    }

    /** A staff row read from the table itself: the server process writes it, and this process's store caches. */
    public function row(int $id): array
    {
        $pdo = new \PDO('sqlite:' . $this->data . '/plugin.sqlite3');
        foreach ($pdo->query('SELECT * FROM retailers')->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $d = isset($r['data']) ? (json_decode((string)$r['data'], true) ?: []) : $r;
            $d['id'] = (int)($r['id'] ?? $d['id'] ?? 0);
            if ($d['id'] === $id) return $d;
        }
        return [];
    }

    public function update(int $id, array $fields): void
    {
        $pdo = new \PDO('sqlite:' . $this->data . '/plugin.sqlite3');
        $st = $pdo->prepare('SELECT data FROM retailers WHERE id = ?');
        $st->execute([$id]);
        $d = json_decode((string)$st->fetchColumn(), true) ?: [];
        $pdo->prepare('UPDATE retailers SET data = ? WHERE id = ?')->execute([json_encode(array_merge($d, $fields)), $id]);
    }

    public function q(string $sql, array $p = []): array
    {
        $pdo = new \PDO('sqlite:' . $this->data . '/plugin.sqlite3');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $st = $pdo->prepare($sql);
        $st->execute($p);
        return $st->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function webhookLog(): string { return (string)@file_get_contents($this->data . '/webhook_log.json'); }

    /** Run a plugin script (a cron, a worker) inside the sandbox, with its own data directory and vault. */
    public function run(string $script, array $args = []): array
    {
        $cmd = sprintf('cd %s && DN_DATA_DIR=%s DN_VAULT_FILE=%s %s %s %s 2>&1', escapeshellarg($this->plug),
            escapeshellarg($this->data), escapeshellarg($this->vault), escapeshellarg(PHP_BINARY), escapeshellarg($script),
            implode(' ', array_map('escapeshellarg', $args)));
        $out = [];
        exec($cmd, $out, $rc);
        return [$rc, implode("\n", $out)];
    }
}

/** A copy of the plugin to weaken, for the weakened-copy checks: everything copied, then one file changed. */
function sj_weakened_copy(string $root, string $rel, string $old, string $new): array
{
    $tmp = sys_get_temp_dir() . '/sj-weak-' . getmypid() . '-' . bin2hex(random_bytes(3));
    mkdir($tmp, 0700, true);
    foreach (scandir($root) ?: [] as $e) {
        if ($e === '.' || $e === '..' || in_array($e, ['docs', 'prototype', 'dishnet-mikrotik-control-plane', 'data', '.git'], true)) continue;
        exec('cp -R ' . escapeshellarg($root . '/' . $e) . ' ' . escapeshellarg($tmp . '/') . ' 2>/dev/null');
    }
    $file = $tmp . '/' . $rel;
    $src  = (string)@file_get_contents($file);
    $n    = substr_count($src, $old);
    if ($n === 1) file_put_contents($file, str_replace($old, $new, $src));
    return [$tmp, $n];
}
