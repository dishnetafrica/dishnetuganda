<?php
declare(strict_types=1);
namespace Dn\Plugin;

use Dn\Db\Database;
use Dn\Notify\SmsSenders;
use PDO;

/**
 * Preflight. The command the installing engineer runs BEFORE install, and again after.
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
 *   WARN    measured, and it is a defensible choice that the engineer should see
 *   BLOCKER measured, and installing or serving in this state is not safe
 *   SKIP    NOT measured — the check could not run, and says why
 *
 * SKIP exists so an unreachable database cannot be mistaken for a clean bill.
 */
final class Doctor
{
    public const OK = 'ok', WARN = 'warn', BLOCKER = 'blocker', SKIP = 'skip';

    /**
     * BURNED CREDENTIALS. Six strings that must never authenticate again.
     *
     * The migrations used to create the login roles with these as literals, and
     * Database::connect() used to default to them. Both are gone (docs/97) —
     * but they remain in this repository's history, so they are burned rather
     * than merely obsolete, and any database where one still works was built
     * before the fix or had it re-introduced.
     *
     * This is therefore the only reason the list still exists: not to describe
     * the current migrations, which contain no password at all, but to keep
     * testing that these exact strings are dead. tests/test_installability.php
     * asserts both halves — that migrations/ carries no literal, and that this
     * list is still checked.
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
            'schema', 'credentials', 'cohabitation', 'exposure', 'identity', 'messaging',
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
        foreach (['owner' => 'owner', 'adminapi' => 'Admin read role', 'staffauth' => 'staff authentication role']
                 as $role => $label) {
            try {
                $db = Database::{['owner' => 'owner', 'adminapi' => 'adminApi', 'staffauth' => 'staffAuth'][$role]}();
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
     * Do the burned credentials still work — and can that even be measured here?
     *
     * The measurement is a connection attempt, because the stored verifier is a
     * SCRAM hash and cannot be compared to a candidate. But a connection
     * attempt only means something if the cluster asks for a credential at all:
     * under a `trust` entry in pg_hba.conf every password succeeds, including
     * the burned ones, and this check reported six live credentials on a
     * database where none of them was set. A control that fails without
     * measuring anything is as useless as one that passes without measuring
     * anything.
     *
     * So a random wrong password goes first. If that connects, the cluster
     * requires no password — which is its own blocker, and a different one —
     * and the burned-credential check reports SKIP rather than a verdict it did
     * not earn.
     */
    private function credentials(): array
    {
        $dsn = getenv('DNB_DSN') ?: '';
        if ($dsn === '') {
            return [
                $this->row('creds.enforced', 'cluster requires a password', self::SKIP,
                    'NOT MEASURED — DNB_DSN is unset'),
                $this->row('creds.dev', 'burned credentials', self::SKIP,
                    'NOT MEASURED — DNB_DSN is unset'),
            ];
        }

        // The positive control.
        $probe  = 'not-a-password-' . bin2hex(random_bytes(16));
        $roles  = array_keys(self::DEV_PASSWORDS);
        $trust  = null;                 // null = could not tell
        foreach ($roles as $role) {
            try {
                new PDO($dsn, $role, $probe, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
                $trust = true;          // a wrong password got in
                break;
            } catch (\PDOException $e) {
                if ($this->isAuthFailure($e)) { $trust = false; break; }
                // role absent, database unreachable: try the next role
            }
        }

        if ($trust === null) {
            return [
                $this->row('creds.enforced', 'cluster requires a password', self::SKIP,
                    'NOT MEASURED — no role answered an authentication attempt'),
                $this->row('creds.dev', 'burned credentials', self::SKIP,
                    'NOT MEASURED — the positive control could not run'),
            ];
        }
        if ($trust === true) {
            return [
                $this->row('creds.enforced', 'cluster requires a password',
                    $this->disposable ? self::WARN : self::BLOCKER,
                    'NO — a random wrong password was accepted. pg_hba.conf trusts these '
                    . 'connections, so role passwords are decorative here'),
                $this->row('creds.dev', 'burned credentials', self::SKIP,
                    'NOT MEASURED — every password succeeds on this cluster, so an '
                    . 'accepted one would prove nothing'),
            ];
        }

        // The cluster enforces passwords, so the burned check means something.
        $live = $tested = [];
        foreach (self::DEV_PASSWORDS as $role => $pass) {
            try {
                new PDO($dsn, $role, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
                $live[] = $role;
                $tested[] = $role;
            } catch (\PDOException $e) {
                if ($this->isAuthFailure($e)) { $tested[] = $role; }
            }
        }
        return [
            $this->row('creds.enforced', 'cluster requires a password', self::OK,
                'yes — a random wrong password was rejected'),
            $this->row('creds.dev', 'burned credentials',
                $live === [] ? self::OK : self::BLOCKER,
                $live === []
                    ? 'dead on all ' . count($tested) . ' role(s) tested'
                    : 'STILL LIVE on: ' . implode(', ', $live)
                      . ' — these strings are in this repository\'s history'),
        ];
    }

    /** 28P01, whatever wording the driver chose. */
    private function isAuthFailure(\PDOException $e): bool
    {
        $m = strtolower($e->getMessage());
        return str_contains($m, '28p01') || str_contains($m, 'password authentication failed');
    }

    /**
     * Is this database shared with something else?
     *
     * The plugin owns objects named mt_*. Anything else in the public schema
     * belongs to another system, and installing alongside it means uninstall
     * cannot be clean. Naming what is there is the point: "the database is not
     * empty" is a fact the engineer should decide about, not one to suppress.
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

    // ── who can sign in ─────────────────────────────────────────────────────
    /**
     * The staff identity provider is an explicit choice (docs/114 §G.8), and
     * this reports the choice as made, not as intended. Three things it will
     * not let pass quietly outside a disposable environment: the real provider
     * with its second factor switched off (D-AUTH-5), the real provider beside
     * the development gate (they refuse to coexist), and the real provider
     * with nothing in front of PHP that terminates TLS (no session can be
     * issued, and the engineer should learn that here rather than from a 403).
     */
    private function identity(): array
    {
        $r    = [];
        $mode = trim(getenv('DN_STAFF_IDENTITY') ?: '');
        $dev  = (getenv('DN_DEV_STAFF_IDENTITY') ?: '') !== '';
        if ($mode === '') {
            $r[] = $this->row('identity.provider', 'staff identity provider', self::WARN,
                $dev ? 'DEVELOPMENT-ONLY — a fabricated identity; nobody real can sign in'
                     : 'deny-all — nobody can sign in. Set DN_STAFF_IDENTITY=dishnet to bind the real provider');
            return $r;
        }
        if ($mode !== 'dishnet') {
            $r[] = $this->row('identity.provider', 'staff identity provider', self::BLOCKER,
                "DN_STAFF_IDENTITY='{$mode}' is not a provider; the API refuses every request");
            return $r;
        }
        $r[] = $this->row('identity.provider', 'staff identity provider',
            $dev ? self::BLOCKER : self::OK,
            $dev ? 'dishnet AND the development gate are both set — they refuse to coexist, so the API answers 500'
                 : 'dishnet (migration 026): bcrypt + TOTP in PostgreSQL, revocable sessions');

        $totp = strtolower(trim(getenv('DN_STAFF_REQUIRE_TOTP') ?: ''));
        $r[] = $this->row('identity.totp', 'second factor',
            in_array($totp, ['', 'yes'], true) ? self::OK
                : ($totp === 'no' ? ($this->disposable ? self::WARN : self::BLOCKER) : self::BLOCKER),
            in_array($totp, ['', 'yes'], true)
                ? 'required — a password-only session may only enrol an authenticator'
                : ($totp === 'no'
                    ? 'DISABLED — mandatory before a public hostname (docs/114 D-AUTH-5)'
                    : "DN_STAFF_REQUIRE_TOTP='{$totp}' is not yes or no; the API refuses every request"));

        $proxy = trim(getenv('DN_TRUSTED_PROXY') ?: '');
        $r[] = $this->row('identity.tls', 'TLS in front of PHP',
            $proxy !== '' ? self::OK : self::WARN,
            $proxy !== ''
                ? 'X-Forwarded-Proto is believed from ' . $proxy . ' and from nobody else'
                : 'DN_TRUSTED_PROXY unset — a session is issued only when PHP itself terminated TLS; '
                  . 'behind a proxy every login answers 403 insecure_transport until this names it');

        try {
            $n = (int) (Database::adminApi()->one('SELECT count(*)::int c FROM mt_admin_staff()')['c'] ?? 0);
            $r[] = $this->row('identity.staff', 'DishNet staff on record',
                $n > 0 ? self::OK : self::WARN,
                $n > 0 ? $n . ' staff row(s)' : 'none — run `plugin.php staff:bootstrap <username>` for the first administrator');
        } catch (\Throwable) {
            $r[] = $this->row('identity.staff', 'DishNet staff on record', self::SKIP,
                'NOT MEASURED — no Admin read connection, or migration 026 not applied');
        }
        return $r;
    }

    // ── how a sign-in code reaches a phone ──────────────────────────────────
    /**
     * The SMS sender is the WORKER's choice (docs/127 S-5), and this reports it
     * by constructing exactly what the worker would construct — so the doctor
     * and the worker cannot disagree about whether it starts. Unset is a WARN,
     * not a blocker: the install works, but no operator can sign in, and the
     * engineer should read that here rather than hear it from an operator.
     */
    private function messaging(): array
    {
        $mode = SmsSenders::configuredName();
        if ($mode === 'null') {
            return [$this->row('sms.sender', 'sign-in codes by SMS', self::WARN,
                'DN_SMS unset — no sign-in code is sent, so no operator can sign in. '
                . 'Set it in the WORKER\'s environment (docs/127)')];
        }
        try {
            $sender = SmsSenders::fromEnvironment();
        } catch (\Throwable $e) {
            // SmsSenders' reasons name variables, never their values.
            return [$this->row('sms.sender', 'sign-in codes by SMS', self::BLOCKER,
                $this->safe($e->getMessage()) . ' — the worker refuses to start')];
        }
        $sandbox = $sender instanceof \Dn\Notify\AfricasTalkingSms && $sender->isSandbox();
        return [$this->row('sms.sender', 'sign-in codes by SMS', self::OK,
            $sender->bindingName() . ($sandbox ? ' — SANDBOX: messages go to the provider\'s simulator, not to phones'
                                               : ' — live')
            . '; API key set (value withheld). DOCUMENTED, UNVERIFIED until a real message arrives (docs/127 S-6)')];
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
