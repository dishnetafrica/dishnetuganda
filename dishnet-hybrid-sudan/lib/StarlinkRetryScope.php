<?php
declare(strict_types=1);
/**
 * StarlinkRetryScope — 5.18.91: keeps cron_starlink_block_retry.php away from the stray plugin-local database on
 * Uganda (docs/07, 5.18.91).
 *
 * The retry job names <plugin>/data as its data directory, opens and migrates the plugin.sqlite3 there, and leaves
 * $dataDir, $config, $store and $pdo pointing at it in cron/master.php's scope. On the Uganda server getDataDir() puts
 * the plugin's data in the sibling .<plugin>-data, so <plugin>/data is not the plugin's database: every run of the job
 * kept a second, stray one alive beside the real one.
 *
 * skip() answers one question: is this a Uganda install whose data lives somewhere other than <plugin>/data? Then the
 * job must not run at all. It is deliberately NOT pointed at the live store instead — that would start a Starlink
 * retry and restore process that has not run against Uganda's live store since its data moved there (26 Aug). Where
 * <plugin>/data IS the data directory the job is already on the right database and nothing changes. Anything unclear —
 * no answer from the configuration, an unreadable file, any throwable — is false, which is how the job behaved before
 * 5.18.91, unchanged. That is StaffJobsGate's rule, and South Sudan's behaviour.
 *
 * It changes nothing on disk. getDataDir() itself is not called, because it creates the directory it chooses and may
 * copy a legacy database into it; liveDataDir() makes the same choice by reading only (tests/test_sl_block_retry_scope.php
 * holds the two equal and pins getDataDir()'s source). The configuration is read with PluginConfig::read(), which never
 * refreshes the vault: the live store's row first, then the configuration files and the vault — the composition the
 * plugin's own Uganda-gated jobs decide on (cron/notify_retry.php, cron/customer_reminders.php).
 *
 * The live database file is NEVER opened here except by SQLite. Inside cron/master.php this process already holds that
 * file open through SQLite, with POSIX locks on it; closing any other descriptor of the same file — an fopen() to read
 * its header, a hash, a copy — releases every one of those locks, SQLite cannot know, and the next process to close its
 * own connection may delete the -wal and -shm from under master (sqlite.org, "How To Corrupt An SQLite Database File",
 * 2.2). So the store is read only through SQLite, read-only, and only when its -wal and -shm already exist — decided by
 * is_file(), which opens nothing — because SQLite makes them for a read-only reader of a WAL database when they are
 * missing, and a file left there owned by the wrong user can stop the web server writing the database. Inside
 * cron/master.php they exist while master holds the same database open. One exception is older than this file and is
 * not changed by it: the nightly maintenance job (cron_maintenance.php, earlier in master's list) copies the live
 * database and its -wal and -shm with copy(), which releases master's locks in exactly this way (docs/07, 5.18.91). If
 * another process then closes the database last, the -wal and -shm go; here the store is then left unread and the
 * decision rests on the configuration files and the vault alone (the deploy script's A12 prints what they name).
 * tests/test_sl_block_retry_scope.php reads this process's locks from /proc/locks — on the database and on its -shm —
 * around the job to prove none is lost.
 */
final class StarlinkRetryScope
{
    /** True when the retry job must stop before it touches <pluginRoot>/data. */
    public static function skip(string $pluginRoot): bool
    {
        return self::explain($pluginRoot)['skip'] === true;
    }

    /**
     * The decision and what it rests on — names and states only, never a configuration value. For the deploy script's
     * check on the server's own configuration.
     *
     * @return array{live: ?string, inside: ?bool, store: string, tenant: ?string, skip: bool}
     */
    public static function explain(string $pluginRoot): array
    {
        $out = ['live' => null, 'inside' => null, 'store' => 'not-read', 'tenant' => null, 'skip' => false];
        try {
            $live = self::liveDataDir($pluginRoot);
            $out['live']   = $live;
            $out['inside'] = self::samePath($live, $pluginRoot . '/data');
            if ($out['inside']) return $out;

            [$stored, $out['store']] = self::storeConfig($live);
            if (!class_exists('PluginConfig')) require_once __DIR__ . '/PluginConfig.php';
            $config = $stored + PluginConfig::read($pluginRoot, $live);

            if (!class_exists('StaffJobsGate')) require_once __DIR__ . '/StaffJobsGate.php';
            $out['skip'] = StaffJobsGate::applies($config, $live) === true;
            try {
                if (!class_exists('TenantProfile')) require_once __DIR__ . '/TenantProfile.php';
                $out['tenant'] = TenantProfile::current($config, $live)->id();
            } catch (\Throwable $e) {
                $out['tenant'] = 'unreadable';
            }
        } catch (\Throwable $e) {
            $out['skip'] = false;
        }
        return $out;
    }

    /**
     * The directory getDataDir() chooses, by its three priorities, without creating it and without its one-time
     * rescue copy: ucrm.json's pluginDataDir (in the plugin, then in its data/); the sibling .<plugin>-data when the
     * plugins root is writable; else <plugin>/data.
     */
    public static function liveDataDir(string $pluginRoot): string
    {
        $dataDir = null;
        foreach ([$pluginRoot . '/ucrm.json', $pluginRoot . '/data/ucrm.json'] as $path) {
            if (file_exists($path)) {
                $ucrm = @json_decode((string)@file_get_contents($path), true);
                if (is_array($ucrm) && !empty($ucrm['pluginDataDir'])) {
                    $value = $ucrm['pluginDataDir'];
                    // getDataDir() cannot take a list or an object either: rtrim() throws there too.
                    if (!is_scalar($value)) throw new \UnexpectedValueException('ucrm.json pluginDataDir is not a path');
                    $dataDir = rtrim((string)$value, '/');
                    break;
                }
            }
        }
        // As getDataDir(): a pluginDataDir that trims to nothing ("/", "0/") falls through to the next rule.
        if (!$dataDir) {
            $parent = dirname(rtrim($pluginRoot, '/'));
            $plugin = basename(rtrim($pluginRoot, '/'));
            $dataDir = (is_dir($parent) && is_writable($parent)) ? $parent . '/.' . $plugin . '-data' : $pluginRoot . '/data';
        }
        return $dataDir;
    }

    /**
     * kyc_config from the live store, read-only, and how it was read: 'read', 'absent' (no database, or no row),
     * 'not-read:wal-files-missing' or 'error'.
     *
     * @return array{0: array, 1: string}
     */
    private static function storeConfig(string $live): array
    {
        $db = $live . '/plugin.sqlite3';
        if (!is_file($db)) return [[], 'absent'];
        try {
            // Never fopen() the database file: see the class comment. is_file() opens nothing.
            if (!is_file($db . '-wal') || !is_file($db . '-shm')) return [[], 'not-read:wal-files-missing'];

            $pdo = new \PDO('sqlite:' . $db, null, null, [
                \PDO::ATTR_ERRMODE             => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_TIMEOUT             => 2,
                \PDO::SQLITE_ATTR_OPEN_FLAGS   => \PDO::SQLITE_OPEN_READONLY,
            ]);
            $row = $pdo->query('SELECT data FROM kyc_config WHERE id = 0 LIMIT 1')->fetchColumn();
            $pdo = null;
            $decoded = is_string($row) ? json_decode($row, true) : null;
            return is_array($decoded) ? [$decoded, 'read'] : [[], 'absent'];
        } catch (\Throwable $e) {
            return [[], 'error'];
        }
    }

    private static function samePath(string $a, string $b): bool
    {
        if (rtrim($a, '/') === rtrim($b, '/')) return true;
        $ra = @realpath($a);
        $rb = @realpath($b);
        return $ra !== false && $rb !== false && $ra === $rb;
    }
}
