<?php
declare(strict_types=1);
namespace Dn\Plugin;

use Dn\Db\Database;
use Dn\Db\Migrator;

/**
 * Install, uninstall and — the one that matters operationally — status.
 *
 * `status()` reports what is TRUE of this installation, by asking the database,
 * not what the manifest declares. A manifest that says a schema exists is a
 * claim; `SELECT count(*) FROM mt_migrations` is evidence. This project has
 * recorded four separate controls that reported success having done nothing,
 * so the status command exists to be the thing that looks.
 */
final class Installer
{
    public function __construct(
        private readonly Manifest $manifest,
        private readonly string $root,
    ) {}

    /** @return array{db:bool,schema:bool,migrations:int,adminapi:bool} */
    public function status(): array
    {
        $s = ['db' => false, 'schema' => false, 'migrations' => 0, 'adminapi' => false];
        try {
            $db = Database::owner();
            $s['db'] = true;
            $n = $db->one(
                "SELECT count(*)::int c FROM information_schema.tables
                  WHERE table_schema = 'public' AND table_name = 'mt_migrations'");
            if ((int) ($n['c'] ?? 0) > 0) {
                $s['schema'] = true;
                $s['migrations'] = (int) ($db->one('SELECT count(*)::int c FROM mt_migrations')['c'] ?? 0);
            }
            $s['adminapi'] = (bool) ($db->one(
                "SELECT count(*)::int c FROM pg_roles WHERE rolname = 'dnb_adminapi'")['c'] ?? 0);
        } catch (\Throwable) {
            // An unreachable database is a status, not a crash. The caller is
            // asking whether this installation works; "no" is a valid answer.
        }
        return $s;
    }

    /** @return list<string> a line per step, for the CLI to print */
    public function install(): array
    {
        $lines = [];
        // A required key is satisfied EITHER by being supplied OR by being one
        // the installer can generate into a named file. Checking only the first
        // made the installer refuse to start over the very pepper it was about
        // to mint — "required configuration is missing: DNB_TOKEN_PEPPER" while
        // DNB_SECRETS_OUT was set and generation was the plan.
        $secretsOut  = getenv('DNB_SECRETS_OUT') ?: '';
        $generatable = $secretsOut === '' ? [] : array_keys(Credentials::APP_SECRETS);
        $missing     = array_values(array_diff($this->manifest->missingConfig(), $generatable));
        if ($missing !== []) {
            throw new \RuntimeException(
                'refusing to install: required configuration is missing — '
                . implode(', ', $missing)
                . '. Set it, or set DNB_SECRETS_OUT so the installer can generate one.');
        }
        foreach (['pdo_pgsql', 'json', 'openssl'] as $ext) {
            if (!extension_loaded($ext)) {
                throw new \RuntimeException("refusing to install: php extension {$ext} is not loaded");
            }
        }
        // Before migrating, and before a single ALTER ROLE: if this install will
        // have to invent a credential, it must know where to put it. Discovering
        // that afterwards means six roles carry passwords nobody knows.
        $wouldGenerate = Credentials::wouldGenerate();
        if ($wouldGenerate !== [] && $secretsOut === '') {
            throw new \RuntimeException(
                'refusing to install: ' . count($wouldGenerate) . ' secret(s) are not '
                . 'supplied (' . implode(', ', $wouldGenerate) . ') and DNB_SECRETS_OUT '
                . 'names no file to write generated ones to. A generated credential that '
                . 'is not written anywhere is a role nobody can ever authenticate as.');
        }
        $lines[] = 'configuration and extensions: ok';

        $db    = Database::owner();
        $creds = new Credentials($db);
        // Before migrating: a login role present now was created by an earlier
        // install, possibly for another database on this cluster.
        $preexisting = $creds->preexisting();

        $applied = (new Migrator($db, $this->root . '/migrations'))->run(true);
        $lines[] = $applied === []
            ? 'schema already current, no migration applied'
            : 'applied ' . count($applied) . ' migration(s): ' . implode(', ', $applied);

        // Credentials, after the roles exist and before the install claims to
        // have worked. The migrations create every login role with NO password,
        // so without this step the install is complete and unusable — which is
        // the correct failure mode, but not a finished installation.
        //
        // Nothing below prints a secret. Outcomes are reported by role name.
        $generated = [];
        $out       = $creds->provision($preexisting, $generated);

        $counts = array_count_values($out['applied']);
        $lines[] = 'credentials: ' . ($out['applied'] === [] ? 'no login role found' :
            implode(', ', array_map(
                static fn($k, $v) => "{$v} {$k}", array_keys($counts), $counts)));

        if ($out['refused'] !== []) {
            throw new \RuntimeException(
                'refusing to install: ' . implode(', ', $out['refused'])
                . ' already have passwords and none was supplied. Set '
                . implode(', ', array_map(
                    static fn($r) => Credentials::ROLE_ENV[$r], $out['refused']))
                . ' from the installation\'s own secrets, or set '
                . 'DNB_ROTATE_CREDENTIALS=yes-rotate-now to mint new ones. Rotating a '
                . 'credential a running deployment is using would break it in a way that '
                . 'looks like a database outage, and on a shared cluster that deployment '
                . 'may be a different database entirely.');
        }

        if ($generated !== []) {
            Credentials::writeSecretsFile($secretsOut, $generated);
            $lines[] = 'wrote ' . count($generated) . ' generated secret(s) to '
                     . $secretsOut . ' (mode 0600, values not shown)';
        }

        // Prove the install rather than announcing it.
        $s = $this->status();
        if (!$s['schema']) {
            throw new \RuntimeException('install reported success but no schema is present');
        }
        if (!$s['adminapi']) {
            throw new \RuntimeException('install reported success but the admin read role is absent');
        }
        $lines[] = "verified: {$s['migrations']} migrations recorded, admin read role present";
        return $lines;
    }

    /**
     * Every role the migrations create. Dropping four of them left eight
     * behind on the cluster, and a cleanup that leaves residue cannot support
     * the claim that a test install left the server unchanged.
     *
     * The owner role is deliberately NOT here: bootstrap.sql created it, this
     * installer did not, and dropping it would remove the identity the operator
     * uses to reach the database.
     */
    public const ROLES = [
        // definer roles first: they own the functions
        'dnb_def_auth', 'dnb_def_net', 'dnb_def_work', 'dnb_def_prov',
        'dnb_def_admin', 'dnb_def_audit', 'dnb_def_comm',
        // then the login roles
        'dnb_app', 'dnb_worker', 'dnb_admin', 'dnb_adminapi',
        'dnb_adminwrite', 'dnb_radius',
    ];

    /**
     * Drop what this plugin owns, and report what it could not.
     *
     * Deliberately narrow: objects whose names this plugin owns, and the roles
     * the migrations created. It does NOT drop the database, because the plugin
     * did not create it and something else may live there.
     *
     * The verification at the end is the point. An uninstall that prints its
     * intentions is not an uninstall; this one asks the catalogue afterwards
     * and names anything still standing.
     *
     * @return list<string>
     */
    public function uninstall(): array
    {
        $lines = [];
        $db = Database::owner();
        $tables = $db->query(
            "SELECT tablename FROM pg_tables
              WHERE schemaname = 'public' AND tablename LIKE 'mt\\_%' ORDER BY tablename");
        foreach ($tables as $t) {
            $db->exec('DROP TABLE IF EXISTS ' . $t['tablename'] . ' CASCADE');
        }
        $lines[] = 'dropped ' . count($tables) . ' table(s)';

        $fns = $db->query(
            "SELECT p.oid::regprocedure::text sig FROM pg_proc p
               JOIN pg_namespace n ON n.oid = p.pronamespace
              WHERE n.nspname = 'public' AND p.proname LIKE 'mt\\_%'");
        foreach ($fns as $f) { $db->exec('DROP FUNCTION IF EXISTS ' . $f['sig'] . ' CASCADE'); }
        $lines[] = 'dropped ' . count($fns) . ' function(s)';

        // Revoking BEFORE dropping. Measured, not assumed: the first version of
        // this method dropped 0 of 12 roles and the catalogue said why —
        //
        //   ERROR: role "dnb_adminapi" cannot be dropped because some objects
        //          depend on it
        //   DETAIL: privileges for schema public
        //
        // A role holding a grant cannot be dropped, and the grant that blocks
        // it is on the SCHEMA, which survives every table being dropped. DROP
        // OWNED BY is not the answer either: it requires privileges OF the
        // target role, which a CREATEROLE owner does not hold here. Revoking
        // each grant explicitly works as the non-superuser owner.
        $dropped = $stuck = [];
        foreach (self::ROLES as $r) {
            foreach ([
                'REVOKE ALL ON SCHEMA public FROM ' . $r,
                'REVOKE ALL ON ALL TABLES IN SCHEMA public FROM ' . $r,
                'REVOKE ALL ON ALL SEQUENCES IN SCHEMA public FROM ' . $r,
                'REVOKE ALL ON ALL FUNCTIONS IN SCHEMA public FROM ' . $r,
                'ALTER DEFAULT PRIVILEGES IN SCHEMA public REVOKE ALL ON TABLES FROM ' . $r,
                'ALTER DEFAULT PRIVILEGES IN SCHEMA public REVOKE ALL ON FUNCTIONS FROM ' . $r,
            ] as $sql) {
                try { $db->exec($sql); } catch (\Throwable) { /* absent role: nothing to revoke */ }
            }
            try {
                $db->exec('REVOKE ALL ON DATABASE ' . $db->one('SELECT current_database() d')['d'] . ' FROM ' . $r);
            } catch (\Throwable) { /* no database-level grant */ }

            try {
                $db->exec('DROP ROLE IF EXISTS ' . $r);
                $dropped[] = $r;
            } catch (\Throwable $e) {
                // Carry the reason. "could not drop" without the DETAIL line is
                // what made the first failure look like a permissions problem
                // when it was a dependency one.
                $stuck[] = $r . ' (' . trim(explode("\n", $e->getMessage())[0]) . ')';
            }
        }
        $lines[] = 'dropped ' . count($dropped) . ' of ' . count(self::ROLES) . ' role(s)';
        if ($stuck !== []) {
            $lines[] = 'COULD NOT DROP: ' . implode('; ', $stuck);
        }

        // Verify by looking, not by having tried.
        $s = $this->status();
        $left = $db->query(
            'SELECT rolname FROM pg_roles WHERE rolname = ANY(:r) ORDER BY rolname',
            [':r' => '{' . implode(',', self::ROLES) . '}']);
        $names = array_column($left, 'rolname');
        $lines[] = $s['schema']
            ? 'WARNING: a schema is still present — uninstall did not fully complete'
            : 'verified: no plugin schema remains';
        $lines[] = $names === []
            ? 'verified: no plugin role remains on the cluster'
            : 'WARNING: ' . count($names) . ' plugin role(s) remain: ' . implode(', ', $names);
        return $lines;
    }
}
