<?php
declare(strict_types=1);
namespace Dn\Plugin;

use Dn\Db\Database;
use PDO;

/**
 * Preflight. The command an operator runs BEFORE install, and again after.
 *
 * Every check here answers by looking, not by reading configuration back. The
 * difference matters: this project has recorded four separate controls that
 * reported success having done nothing, so "the manifest says the pepper is
 * required" is not a check and "connecting as dnb_app with the development
 * password succeeded" is.
 *
 * Four states, and they are not interchangeable:
 *
 *   OK      measured, and the measurement is what a working install looks like
 *   WARN    measured, and it is a defensible choice that an operator should see
 *   BLOCKER measured, and installing or serving in this state is not safe
 *   SKIP    NOT measured — the check could not run, and says why
 *
 * SKIP exists so an unreachable database cannot be mistaken for a clean bill.
 */
final class Doctor
{
    public const OK = 'ok', WARN = 'warn', BLOCKER = 'blocker', SKIP = 'skip';

    /**
     * The development role passwords, as migration 001 and its successors write
     * them and as Database::connect() defaults to them. They are not secrets —
     * they are in the repository — which is exactly the problem: an install
     * that leaves them in place has six known passwords on the cluster.
     *
     * tests/test_installability.php asserts this list still matches the
     * literals in migrations/, so it cannot drift into being decorative.
     *
     * @var array<string,string>
     */
    public const DEV_PASSWORDS = [
        'dnb_app'         => 'app-local-dev',
        'dnb_worker'      => 'worker-local-dev',
        'dnb_admin'       => 'admin-local-dev',
        'dnb_adminapi'    => 'adminapi-local-dev',
        'dnb_adminwrite'  => 'adminwrite-local-dev',
        'dnb_radius'      => 'radius-local-dev',
    ];

    /** Variables that must not be set outside a disposable environment. */
    public const UNSAFE_ENV = [
        'DNB_EXPOSE_OTP'        => 'returns the OTP in API responses',
        'DN_DEV_STAFF_IDENTITY' => 'binds the Admin panel to a fabricated staff identity',
        'DN_ALLOW_REAL_BINDINGS'=> 'opens the F6-B gate — packets reach real hardware',
        'DNB_INSPECT_USER'      => 'a BYPASSRLS test-fixture identity',
    ];

    public function __construct(
        private readonly Manifest $manifest,
        private readonly string $root,
        /** Disposable environments may legitimately set the unsafe variables. */
        private readonly bool $disposable = false,
    ) {}

    /** @return list<array{id:string,label:string,state:string,detail:string}> */
    public function run(): array
    {
        $r = [];
        foreach ([
            'environment', 'configuration', 'files', 'database',
            'schema', 'credentials', 'cohabitation', 'exposure',
        ] as $group) {
            foreach ($this->{$group}() as $row) { $r[] = $row; }
        }
        return $r;
    }

    public function blockers(array $rows): array
    {
        return array_values(array_filter($rows, static fn($x) => $x['state'] === self::BLOCKER));
    }

    // ── php itself ──────────────────────────────────────────────────────────
    private function environment(): array
    {
        $r    = [];
        $want = (string) ($this->manifest->requires['php'] ?? '>=8.1');
        $min  = ltrim($want, '>=');
        $r[]  = $this->row('php.version', 'PHP version',
            version_compare(PHP_VERSION, $min, '>=') ? self::OK : self::BLOCKER,
            PHP_VERSION . ' (requires ' . $want . ')');

        foreach ((array) ($this->manifest->requires['php_extensions'] ?? []) as $ext) {
            $r[] = $this->row('php.ext.' . $ext, 'extension ' . $ext,
                extension_loaded($ext) ? self::OK : self::BLOCKER,
                extension_loaded($ext) ? 'loaded' : 'NOT LOADED');
        }
        return $r;
    }

    // ── configuration ───────────────────────────────────────────────────────
    private function configuration(): array
    {
        $r = [];
        foreach ($this->manifest->config as $key => $spec) {
            if (!($spec['required'] ?? false)) { continue; }
            $set = (getenv($key) ?: '') !== '';
            $r[] = $this->row('config.' . $key, $key,
                $set ? self::OK : self::BLOCKER,
                $set ? ($spec['secret'] ? 'set (value withheld)' : 'set') : 'MISSING (required)');
        }
        // Owner credentials are install-time only and are not in the manifest's
        // runtime config, but install cannot run without them.
        $r[] = $this->row('config.owner', 'DNB_OWNER_USER / DNB_OWNER_PASS',
            (getenv('DNB_OWNER_PASS') ?: '') !== '' ? self::OK : self::WARN,
            (getenv('DNB_OWNER_PASS') ?: '') !== ''
                ? 'set (value withheld)'
                : 'unset — install works only if the cluster trusts this user without a password');
        return $r;
    }

    // ── the package is complete ─────────────────────────────────────────────
    private function files(): array
    {
        $r = [];
        $need = [
            'plugin/plugin.json', 'plugin/public/api.php', 'plugin/bin/plugin.php',
            'plugin/bin/serve.php', 'plugin/bin/bootstrap.sql',
            'panel/index.html', 'panel/app.js', 'panel/api.js',
            'src/autoload.php', 'migrations',
        ];
        $missing = array_values(array_filter($need,
            fn($p) => !file_exists($this->root . '/' . $p)));
        $r[] = $this->row('files.present', 'package contents',
            $missing === [] ? self::OK : self::BLOCKER,
            $missing === []
                ? count($need) . ' required paths present'
                : 'MISSING: ' . implode(', ', $missing));

        $n = is_dir($this->root . '/migrations')
            ? count(glob($this->root . '/migrations/*.sql') ?: []) : 0;
        $r[] = $this->row('files.migrations', 'migration files',
            $n > 0 ? self::OK : self::BLOCKER, $n . ' .sql file(s) on disk');
        return $r;
    }

    // ── can we reach it ─────────────────────────────────────────────────────
    private function database(): array
    {
        $r = [];
        foreach (['owner' => 'owner', 'adminapi' => 'Admin read role'] as $role => $label) {
            try {
                $db = Database::{$role === 'owner' ? 'owner' : 'adminApi'}();
                $v  = (string) ($db->one('SHOW server_version')['server_version'] ?? '?');
                $min = ltrim((string) ($this->manifest->requires['postgresql'] ?? '>=14'), '>=');
                $ok  = version_compare(explode(' ', $v)[0], $min, '>=');
                $r[] = $this->row('db.' . $role, 'connect as ' . $label,
                    $ok ? self::OK : self::BLOCKER,
                    'connected, PostgreSQL ' . $v . ($ok ? '' : ' (requires >=' . $min . ')'));
            } catch (\Throwable $e) {
                $r[] = $this->row('db.' . $role, 'connect as ' . $label, self::BLOCKER,
                    'cannot connect: ' . $this->safe($e->getMessage()));
            }
        }
        return $r;
    }

    private function schema(): array
    {
        try { $db = Database::owner(); }
        catch (\Throwable) {
            return [$this->row('schema.present', 'plugin schema', self::SKIP,
                'NOT MEASURED — no owner connection')];
        }
        $n = (int) ($db->one(
            "SELECT count(*)::int c FROM information_schema.tables
              WHERE table_schema = 'public' AND table_name = 'mt_migrations'")['c'] ?? 0);
        if ($n === 0) {
            return [$this->row('schema.present', 'plugin schema', self::WARN,
                'absent — this is the expected state BEFORE install')];
        }
        $applied = (int) ($db->one('SELECT count(*)::int c FROM mt_migrations')['c'] ?? 0);
        $onDisk  = count(glob($this->root . '/migrations/*.sql') ?: []);
        return [$this->row('schema.present', 'plugin schema',
            $applied === $onDisk ? self::OK : self::WARN,
            $applied . ' of ' . $onDisk . ' migration(s) applied')];
    }

    // ── the one that matters ────────────────────────────────────────────────
    /**
     * Does any login role still accept its development password?
     *
     * Measured by attempting a connection, not by reading pg_authid: the stored
     * verifier is a SCRAM hash and cannot be compared to a candidate, so the
     * only honest test is to try it. A success here is a blocker; a failure is
     * the good outcome.
     */
    private function credentials(): array
    {
        $dsn = getenv('DNB_DSN') ?: '';
        if ($dsn === '') {
            return [$this->row('creds.dev', 'development passwords', self::SKIP,
                'NOT MEASURED — DNB_DSN is unset')];
        }
        $live = [];
        $tested = 0;
        foreach (self::DEV_PASSWORDS as $role => $pass) {
            try {
                new PDO($dsn, $role, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
                $live[] = $role;
                $tested++;
            } catch (\PDOException $e) {
                // 28P01 is "password authentication failed" — the good answer.
                // Anything else (role absent, database unreachable) is not a
                // measurement of this role's password, so it is not counted.
                if (str_contains($e->getMessage(), '28P01')
                    || str_contains(strtolower($e->getMessage()), 'password authentication failed')) {
                    $tested++;
                }
            }
        }
        if ($tested === 0) {
            return [$this->row('creds.dev', 'development passwords', self::SKIP,
                'NOT MEASURED — no role answered an authentication attempt')];
        }
        return [$this->row('creds.dev', 'development passwords',
            $live === [] ? self::OK : self::BLOCKER,
            $live === []
                ? 'none of ' . $tested . ' role(s) accepts its development password'
                : 'ACCEPTED by: ' . implode(', ', $live)
                  . ' — these are published in the repository')];
    }

    /**
     * Is this database shared with something else?
     *
     * The plugin owns objects named mt_*. Anything else in the public schema
     * belongs to another system, and installing alongside it means uninstall
     * cannot be clean. Naming what is there is the point: "the database is not
     * empty" is a fact an operator should decide about, not one to suppress.
     */
    private function cohabitation(): array
    {
        try { $db = Database::owner(); }
        catch (\Throwable) {
            return [$this->row('db.shared', 'database is exclusive', self::SKIP,
                'NOT MEASURED — no owner connection')];
        }
        $rows = $db->query(
            "SELECT tablename FROM pg_tables
              WHERE schemaname = 'public' AND tablename NOT LIKE 'mt\\_%'
              ORDER BY tablename LIMIT 6");
        $names = array_column($rows, 'tablename');
        return [$this->row('db.shared', 'database is exclusive',
            $names === [] ? self::OK : self::WARN,
            $names === []
                ? 'no foreign tables in public schema'
                : 'shares public schema with: ' . implode(', ', $names)
                  . ' — uninstall cannot be proven clean here')];
    }

    // ── what is switched on ─────────────────────────────────────────────────
    private function exposure(): array
    {
        $r = [];
        foreach (self::UNSAFE_ENV as $key => $why) {
            $set = (getenv($key) ?: '') !== '';
            $r[] = $this->row('env.' . $key, $key,
                !$set ? self::OK : ($this->disposable ? self::WARN : self::BLOCKER),
                !$set ? 'unset' : 'SET — ' . $why);
        }
        return $r;
    }

    private function row(string $id, string $label, string $state, string $detail): array
    {
        return ['id' => $id, 'label' => $label, 'state' => $state, 'detail' => $detail];
    }

    /** Never let a driver message carry a credential into the report. */
    private function safe(string $m): string
    {
        $m = preg_replace('/password=\S+/i', 'password=<withheld>', $m) ?? $m;
        return substr(trim($m), 0, 160);
    }
}
