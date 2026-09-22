<?php
declare(strict_types=1);
namespace Dn\Db;

/** Runs migrations/*.sql in filename order, once each, recorded in a table. */
final class Migrator
{
    public function __construct(private Database $owner, private string $dir) {}

    public function run(bool $quiet = false): array
    {
        $this->owner->exec(
            'CREATE TABLE IF NOT EXISTS mt_migrations (
               filename text PRIMARY KEY, applied_at timestamptz NOT NULL DEFAULT now())'
        );
        $done = array_column($this->owner->query('SELECT filename FROM mt_migrations'), 'filename');
        $applied = [];
        $files = glob($this->dir . '/*.sql');
        sort($files, SORT_STRING);
        foreach ($files as $f) {
            $name = basename($f);
            if (in_array($name, $done, true)) { continue; }
            $sql = file_get_contents($f);
            // Each migration is one statement batch; PostgreSQL runs it
            // transactionally via PDO::exec on a single multi-statement string.
            $this->owner->pdo()->exec($sql);
            $this->owner->exec('INSERT INTO mt_migrations (filename) VALUES (?)', [$name]);
            $applied[] = $name;
            if (!$quiet) { fwrite(STDERR, "applied {$name}\n"); }
        }
        return $applied;
    }
}
