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
        $missing = $this->manifest->missingConfig();
        if ($missing !== []) {
            throw new \RuntimeException(
                'refusing to install: required configuration is missing — '
                . implode(', ', $missing));
        }
        foreach (['pdo_pgsql', 'json', 'openssl'] as $ext) {
            if (!extension_loaded($ext)) {
                throw new \RuntimeException("refusing to install: php extension {$ext} is not loaded");
            }
        }
        $lines[] = 'configuration and extensions: ok';

        $applied = (new Migrator(Database::owner(), $this->root . '/migrations'))->run(true);
        $lines[] = $applied === []
            ? 'schema already current, no migration applied'
            : 'applied ' . count($applied) . ' migration(s): ' . implode(', ', $applied);

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
     * Drop what this plugin owns.
     *
     * Deliberately narrow: objects whose names this plugin owns, and the login
     * roles it created. It does NOT drop the database, because the plugin did
     * not create it and something else may live there.
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

        // Roles last: a role owning an object cannot be dropped, so the order
        // is load-bearing rather than tidy.
        $roles = ['dnb_adminapi', 'dnb_adminwrite', 'dnb_def_admin', 'dnb_def_audit'];
        $dropped = 0;
        foreach ($roles as $r) {
            try { $db->exec('DROP ROLE IF EXISTS ' . $r); $dropped++; }
            catch (\Throwable $e) { $lines[] = "could not drop role {$r}: still owns objects"; }
        }
        $lines[] = "dropped {$dropped} role(s)";

        $s = $this->status();
        $lines[] = $s['schema']
            ? 'WARNING: a schema is still present — uninstall did not fully complete'
            : 'verified: no plugin schema remains';
        return $lines;
    }
}
