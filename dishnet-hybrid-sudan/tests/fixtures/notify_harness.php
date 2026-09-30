<?php
declare(strict_types=1);
/**
 * NotifyHarness — the plugin's real webhook under `php -S`, a seedable fake uCRM (fake_ucrm_notify.php) and the fake
 * Evolution (fake_evo_server.php), in a data directory of their own. For the 5.18.54 notification tests (docs/46).
 *
 * The plugin server's stderr is kept in a file: PHP's warnings and uncaught errors land there, and "nothing was
 * logged" is part of what several tests assert. Every WhatsApp the plugin sends is one entry in the fake Evolution's
 * record; nothing leaves the machine.
 */
final class NotifyHarness
{
    public string $root;
    public string $tmp;
    public string $dataDir;
    public string $errFile;
    public int $crmPort = 0;
    public int $evoPort = 0;
    public int $webPort = 0;
    public string $tenant;
    /** The zone the caller named: a test file may pin one, a fixture may not (tests/test_timezone.php). */
    public string $zone = '';
    /** @var resource[] */
    private array $procs = [];

    /** @param array $config extra kyc_config.json keys, on top of a working Evolution and uCRM */
    public static function start(string $root, string $tenant, array $config = [], string $tag = 'nh'): self
    {
        $h = new self();
        $h->root = $root;
        $h->tenant = $tenant;
        if (!isset($config['timezone']) || !is_string($config['timezone']) || $config['timezone'] === '') {
            throw new \InvalidArgumentException('NotifyHarness::start(): the caller names the timezone');
        }
        $h->zone = $config['timezone'];
        $h->tmp = sys_get_temp_dir() . '/' . $tag . '_' . getmypid() . '_' . substr(md5(uniqid('', true)), 0, 6);
        exec('rm -rf ' . escapeshellarg($h->tmp));
        @mkdir($h->tmp . '/data', 0777, true);
        $h->dataDir = $h->tmp . '/data';
        $h->errFile = $h->tmp . '/web.stderr';
        register_shutdown_function([$h, 'stop']);

        $h->crmPort = $h->serve(sprintf('exec php -S 127.0.0.1:{PORT} %s', escapeshellarg($root . '/tests/fixtures/fake_ucrm_notify.php')),
            fn(int $p) => strpos((string)$h->get("http://127.0.0.1:{$p}/__test/state")[1], 'FAKE-UCRM-NOTIFY') !== false, 11200);
        $h->evoPort = $h->serve(sprintf('exec php -S 127.0.0.1:{PORT} %s', escapeshellarg($root . '/tests/fixtures/fake_evo_server.php')),
            fn(int $p) => strpos((string)$h->get("http://127.0.0.1:{$p}/__test/state")[1], 'FAKE-EVO-TEST') !== false, 11400);
        if ($h->crmPort === 0 || $h->evoPort === 0) throw new \RuntimeException('could not start the fakes');
        $h->get("http://127.0.0.1:{$h->evoPort}/__test/reset");

        require_once $root . '/lib/StoreInterface.php';
        require_once $root . '/lib/SqliteStore.php';
        \SqliteStore::create($h->dataDir);
        $h->writeConfig($config);

        file_put_contents($h->tmp . '/router.php', '<?php
declare(strict_types=1);
$root = ' . var_export($root, true) . ';
require_once $root . "/lib/timezone.php"; dn_tz_apply();
require_once $root . "/lib/StoreInterface.php";
require_once $root . "/lib/SqliteStore.php";
$dataDir = ' . var_export($h->dataDir, true) . ';
$store   = SqliteStore::create($dataDir);
$config  = $store->load("kyc_config.json") ?? [];
require $root . "/webhook.php";
');
        $env = array_merge(getenv(), ['DN_VAULT_FILE' => (string)getenv('DN_VAULT_FILE'), 'DN_DATA_DIR' => $h->dataDir]);
        $h->webPort = $h->serve(sprintf('exec php -d log_errors=1 -d error_log=%s -S 127.0.0.1:{PORT} %s',
                                        escapeshellarg($h->errFile), escapeshellarg($h->tmp . '/router.php')),
            fn(int $p) => strpos((string)$h->get("http://127.0.0.1:{$p}/webhook.php")[1], 'POST required') !== false, 11600, $env);
        if ($h->webPort === 0) throw new \RuntimeException('could not start the plugin under php -S');
        return $h;
    }

    /** The configuration every case starts from; $extra wins. */
    public function writeConfig(array $extra = []): void
    {
        $base = [
            'tenant_profile' => $this->tenant,
            'timezone'       => $this->zone,
            'crm_base_url'   => "http://127.0.0.1:{$this->crmPort}", 'crm_auth_token' => 'NOTIFYKEY',
            'crm_public_url' => 'https://crm.example.test',
            'evo_api_url'    => "http://127.0.0.1:{$this->evoPort}", 'evo_api_key' => 'test-key',
            'evo_instance_support' => 'nh-support', 'evo_instance_account' => 'nh-account', 'evo_instance_sales' => 'nh-sales',
            'dry_run_mode'   => false, 'data_dir' => $this->dataDir,
            'webhook_secret' => 'nhsecret',
        ];
        $cfg = array_merge($base, $extra);
        file_put_contents($this->dataDir . '/kyc_config.json', json_encode($cfg, JSON_PRETTY_PRINT));
        // The store copy too: the crons read only that one, as they do on the server.
        require_once $this->root . '/lib/StoreInterface.php';
        require_once $this->root . '/lib/SqliteStore.php';
        \SqliteStore::create($this->dataDir)->save('kyc_config.json', $cfg);
    }

    public int $smtpPort = 0;
    public string $smtpTranscript = '';

    /** A fake SMTP relay, and the plugin's own mail settings pointed at it (the customer e-mails' transport). */
    public function withSmtp(): void
    {
        $this->smtpTranscript = $this->tmp . '/smtp.json';
        $t = $this->smtpTranscript;
        $this->smtpPort = $this->serve(sprintf('exec php %s {PORT} %s 600', escapeshellarg($this->root . '/tests/fixtures/fake_smtp_server.php'),
                                               escapeshellarg($t)),
            function (int $port) use ($t): bool {
                if (!is_file($t)) return false;
                $sock = @fsockopen('127.0.0.1', $port, $e1, $e2, 2); if (!$sock) return false;
                $g = fgets($sock, 256); @fclose($sock); return strpos((string)$g, 'fake.smtp.test') !== false;
            }, 11800);
        if ($this->smtpPort === 0) throw new \RuntimeException('could not start the fake SMTP relay');
        file_put_contents($this->dataDir . '/email_settings.json', json_encode([
            'use_ucrm_email' => false, 'smtp_host' => '127.0.0.1', 'smtp_port' => $this->smtpPort,
            'smtp_user' => '', 'smtp_pass' => '', 'smtp_enc' => '', 'smtp_from' => 'accounts@example.test',
        ], JSON_PRETTY_PRINT));
    }

    /** The messages the relay accepted (sessions that carried a message). */
    public function smtpMessages(): array
    {
        $all = json_decode((string)@file_get_contents($this->smtpTranscript), true) ?: [];
        return array_values(array_filter($all, fn($x) => trim((string)($x['data'] ?? '')) !== ''));
    }

    public function seedCrm(array $seed): void
    {
        $this->post("http://127.0.0.1:{$this->crmPort}/__test/seed", json_encode($seed), ['Content-Type: application/json']);
    }

    /** Fire one uCRM event at the real webhook. */
    public function fire(string $changeType, string $entity, int $id, ?string $uuid = null, array $extra = []): array
    {
        $payload = json_encode(['changeType' => $changeType, 'entity' => $entity, 'entityId' => $id,
                                'uuid' => $uuid ?? "nh-{$changeType}-{$id}-" . substr(md5(uniqid('', true)), 0, 6),
                                'extraData' => ['entity' => $extra + ['id' => $id]]]);
        return $this->post("http://127.0.0.1:{$this->webPort}/webhook.php", $payload,
                           ['Content-Type: application/json', 'X-Ucrm-Key: nhsecret']);
    }

    /** Wait until the plugin server has finished the requests it answered early (fastcgi-style flushes). */
    public function settle(float $seconds = 1.5): void { usleep((int)($seconds * 1e6)); }

    public function evoTexts(): array
    {
        [, $b] = $this->get("http://127.0.0.1:{$this->evoPort}/__test/state");
        return (array)((json_decode((string)$b, true) ?: [])['text_calls'] ?? []);
    }

    public function evoMedia(): array
    {
        [, $b] = $this->get("http://127.0.0.1:{$this->evoPort}/__test/state");
        return (array)((json_decode((string)$b, true) ?: [])['media_calls'] ?? []);
    }

    public function evoFailNext(int $n): void { $this->get("http://127.0.0.1:{$this->evoPort}/__test/fail_next?n={$n}"); }
    public function evoReset(): void { $this->get("http://127.0.0.1:{$this->evoPort}/__test/reset"); }

    public function crmRequests(): array
    {
        [, $b] = $this->get("http://127.0.0.1:{$this->crmPort}/__test/requests");
        return (array)((json_decode((string)$b, true) ?: [])['requests'] ?? []);
    }

    public function pdo(): \PDO
    {
        $pdo = new \PDO('sqlite:' . $this->dataDir . '/plugin.sqlite3');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(\PDO::ATTR_TIMEOUT, 5);
        return $pdo;
    }

    public function webhookLog(): string { return (string)@file_get_contents($this->dataDir . '/webhook_log.json'); }
    public function stderr(): string { return (string)@file_get_contents($this->errFile); }

    public function get(string $url): array { return $this->post($url, null, []); }

    public function post(string $url, ?string $body, array $headers): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_CONNECTTIMEOUT => 3,
                                CURLOPT_HTTPHEADER => $headers]);
        if ($body !== null) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, $body); }
        $out = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        return [$code, $out === false ? null : (string)$out];
    }

    private function serve(string $cmd, callable $isUp, int $base, array $env = null): int
    {
        foreach (range(0, 11) as $slot) {
            $port = $base + ((getmypid() * 7 + $slot * 13) % 180);
            $p = proc_open(str_replace('{PORT}', (string)$port, $cmd),
                           [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, null, $env);
            $ok = false;
            for ($i = 0; $i < 50 && !$ok; $i++) { usleep(100000); $ok = $isUp($port); }
            if ($ok) { $this->procs[] = $p; return $port; }
            if (is_resource($p)) { proc_terminate($p); proc_close($p); }
        }
        return 0;
    }

    /**
     * A weakened copy of the plugin: every entry a symlink to the real tree, except the files named in $patches,
     * which are copied and patched. Each patch is [search, replace] and must match exactly once — a patch whose
     * anchor is gone would otherwise test the unweakened code and "pass" (docs/105 §0: assert the anchor, the count
     * and the result). Returns the copy's root; it is removed at exit.
     *
     * @param array<string, array<int, array{0:string,1:string}>> $patches  relative path => list of [search, replace]
     */
    public static function weakened(string $root, array $patches, string $tag = 'wk', array $extraFiles = []): string
    {
        $dst = sys_get_temp_dir() . '/' . $tag . '_' . getmypid() . '_' . substr(md5(uniqid('', true)), 0, 6);
        exec('rm -rf ' . escapeshellarg($dst));
        mkdir($dst, 0777, true);
        register_shutdown_function(function () use ($dst) { exec('rm -rf ' . escapeshellarg($dst)); });
        $mirror = function (string $from, string $to, array $keepReal) use (&$mirror) {
            foreach (scandir($from) as $e) {
                if ($e === '.' || $e === '..') continue;
                if (isset($keepReal[$e])) {
                    if ($keepReal[$e] === true) continue;              // a file to copy and patch: done by the caller
                    mkdir($to . '/' . $e, 0777, true);
                    $mirror($from . '/' . $e, $to . '/' . $e, $keepReal[$e]);
                    continue;
                }
                symlink($from . '/' . $e, $to . '/' . $e);
            }
        };
        $tree = [];
        foreach (array_keys($patches) as $rel) {
            $parts = explode('/', $rel);
            $node =& $tree;
            foreach ($parts as $i => $part) {
                if ($i === count($parts) - 1) { $node[$part] = true; break; }
                if (!isset($node[$part]) || $node[$part] === true) $node[$part] = [];
                $node =& $node[$part];
            }
            unset($node);
        }
        $mirror($root, $dst, $tree);
        foreach ($patches as $rel => $list) {
            $src = (string)file_get_contents($root . '/' . $rel);
            foreach ($list as [$search, $replace]) {
                $n = substr_count($src, $search);
                if ($n !== 1) throw new \RuntimeException("weakened copy: anchor found {$n} times in {$rel}: " . substr($search, 0, 80));
                $src = str_replace($search, $replace, $src);
                if (strpos($src, $replace) === false) throw new \RuntimeException("weakened copy: replacement missing in {$rel}");
            }
            file_put_contents($dst . '/' . $rel, $src);
        }
        foreach ($extraFiles as $rel => $content) {
            if (is_link(dirname($dst . '/' . $rel)) || is_link($dst . '/' . $rel)) {
                throw new \RuntimeException("weakened copy: {$rel} would be written through a link into the real tree");
            }
            file_put_contents($dst . '/' . $rel, $content);
        }
        return $dst;
    }

    public function stop(): void
    {
        foreach ($this->procs as $p) { if (is_resource($p)) { proc_terminate($p); proc_close($p); } }
        $this->procs = [];
        foreach ([$this->crmPort, $this->evoPort] as $port) {
            if ($port) array_map('unlink', glob(sys_get_temp_dir() . "/fake_ucrm_notify_{$port}.json") ?: []);
        }
        if ($this->tmp !== '' && is_dir($this->tmp)) exec('rm -rf ' . escapeshellarg($this->tmp));
    }
}
