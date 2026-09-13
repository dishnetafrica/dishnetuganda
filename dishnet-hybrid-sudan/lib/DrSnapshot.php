<?php
declare(strict_types=1);

require_once __DIR__ . '/SiblingPlugin.php';
require_once __DIR__ . '/SecureFile.php';

/**
 * DrSnapshot — keep dishnet-data-report's data where an upgrade cannot reach it.
 *
 * uCRM DELETES <plugin>/data when a plugin is upgraded, and that is where
 * dishnet-data-report keeps everything it knows: its Starlink session
 * cookies, its router map, and the record of which customers are currently
 * blocked. Its own backup writes into the directory being deleted.
 *
 * On the Uganda server, verified 2026-09-13, that plugin is still using the
 * deletable shape — <plugins>/dishnet-data-report/data — rather than the
 * dot-prefixed sibling directory that survives. It is not our plugin and not
 * ours to restructure. What we can do is hold a copy somewhere else and be
 * able to put it back, and do it on a schedule rather than relying on
 * somebody remembering to run a command before an upgrade nobody announces.
 *
 * The logic lives here rather than in tools/dr_snapshot.php so the tool and
 * cron/dr_snapshot.php cannot drift into taking different files.
 *
 * This reads that plugin and writes only into ours. restore() is the one
 * exception, and it is never automatic: putting a stale router map back over
 * a fresh one is its own way to lose the current state, so a person asks for
 * it by name.
 */
final class DrSnapshot
{
    public const PLUGIN = 'dishnet-data-report';

    /** How many snapshots to keep. Daily, so this is a fortnight of history. */
    public const KEEP = 14;

    /**
     * What to take, and why it is worth taking. Ordered by how badly it
     * hurts to lose — the two that plugin's own backup omits are at the top.
     */
    public const FILES = [
        'wifi_test_block_state.json' => 'WHO IS CURRENTLY BLOCKED — cannot be reconstructed',
        'wifi_router_map.json'       => 'dish → router map — a rediscovery cycle to rebuild',
        'dr_accounts.json'           => 'Starlink accounts and their session cookies — needs a re-login',
        'dr_kit_registry.json'       => 'Starlink-derived kit liveness',
        'sl_svc_cache.json'          => 'service line → kit resolution',
        'sl_sync_settings.json'      => 'sync configuration',
        'dr_plan_cache.json'         => 'plan cache',
        'backup_settings.json'       => 'its own backup configuration, including Drive credentials',
        'crm_kit_authority.json'     => 'kit ownership decisions',
        'sl_usage.json'              => 'usage history shown in the customer portal',
    ];

    /** Where snapshots live, inside THIS plugin's data directory. */
    public static function root(string $dataDir): string
    {
        return rtrim($dataDir, '/') . '/dr_snapshots';
    }

    /**
     * Which of the files we want are actually there.
     *
     * @return array{present:array<string,array{path:string,bytes:int,why:string}>,
     *               absent:array<string,string>}
     */
    public static function survey(?string $drData): array
    {
        $present = []; $absent = [];
        foreach (self::FILES as $file => $why) {
            $p = $drData === null ? '' : $drData . '/' . $file;
            if ($p !== '' && is_file($p)) {
                $present[$file] = ['path' => $p, 'bytes' => (int)@filesize($p), 'why' => $why];
            } else {
                $absent[$file] = $why;
            }
        }
        return ['present' => $present, 'absent' => $absent];
    }

    /**
     * One value for "the data as it stands", so an unchanged day does not
     * cost a second copy of the same bytes. Content, not mtime: that plugin
     * rewrites files on every cron tick whether or not anything moved.
     *
     * @param array<string,array{path:string}> $present
     */
    public static function fingerprint(array $present): string
    {
        $parts = [];
        foreach ($present as $file => $i) {
            $h = @md5_file($i['path']);
            $parts[] = $file . ':' . ($h === false ? '?' : $h);
        }
        sort($parts);
        return md5(implode('|', $parts));
    }

    /** The same value for a snapshot already on disk. */
    public static function fingerprintOf(string $snapDir): string
    {
        $parts = [];
        foreach ((array)glob($snapDir . '/*.json') as $f) {
            $h = @md5_file($f);
            $parts[] = basename($f) . ':' . ($h === false ? '?' : $h);
        }
        sort($parts);
        return md5(implode('|', $parts));
    }

    /**
     * Snapshot names, oldest first. The name is a UTC timestamp, so this is
     * also chronological order.
     *
     * @return array<int,string>
     */
    public static function snapshots(string $snapRoot): array
    {
        if (!is_dir($snapRoot)) return [];
        $out = [];
        foreach ((array)array_diff((array)scandir($snapRoot), ['.', '..']) as $n) {
            if (is_dir($snapRoot . '/' . $n)) $out[] = (string)$n;
        }
        sort($out);
        return $out;
    }

    /** The newest snapshot's directory, or null. */
    public static function latest(string $snapRoot): ?string
    {
        $s = self::snapshots($snapRoot);
        return $s === [] ? null : $snapRoot . '/' . end($s);
    }

    /**
     * Take one.
     *
     * @param bool $skipIdentical true for the cron — a day where nothing
     *                            changed should not cost a copy. The tool
     *                            passes false: a person asking for a
     *                            snapshot gets one.
     * @return array{status:string, name:string, saved:int, total:int, reason:string}
     *         status: saved | unchanged | nothing_to_take | not_installed | failed
     */
    public static function take(string $snapRoot, ?string $drData, string $dataDir,
                                bool $skipIdentical = false): array
    {
        $out = ['status' => 'failed', 'name' => '', 'saved' => 0, 'total' => 0, 'reason' => ''];

        if ($drData === null) {
            $out['status'] = 'not_installed';
            $out['reason'] = self::PLUGIN . ' is not installed — nothing to snapshot';
            return $out;
        }

        $present = self::survey($drData)['present'];
        $out['total'] = count($present);
        if ($present === []) {
            $out['status'] = 'nothing_to_take';
            $out['reason'] = 'that plugin holds none of the files worth keeping';
            return $out;
        }

        if ($skipIdentical) {
            $last = self::latest($snapRoot);
            if ($last !== null && self::fingerprintOf($last) === self::fingerprint($present)) {
                $out['status'] = 'unchanged';
                $out['name']   = basename($last);
                $out['reason'] = 'identical to ' . basename($last);
                return $out;
            }
        }

        $name = gmdate('Ymd-His');
        $dest = $snapRoot . '/' . $name;
        if (!@mkdir($dest, 0750, true) && !is_dir($dest)) {
            $out['reason'] = 'could not create ' . $dest;
            return $out;
        }

        foreach ($present as $file => $i) {
            $t = $dest . '/' . $file;
            if (@copy($i['path'], $t)) { SecureFile::adopt($t, $dataDir); $out['saved']++; }
        }
        // dr_accounts.json holds session cookies. Readable by the web user
        // and nobody else, the same as every other secret this plugin keeps.
        @chmod($dest, 0750);

        $out['name']   = $name;
        $out['status'] = $out['saved'] === $out['total'] ? 'saved' : 'failed';
        if ($out['status'] === 'failed') {
            $out['reason'] = "copied {$out['saved']} of {$out['total']} files";
        }
        return $out;
    }

    /**
     * Keep the newest $keep and delete the rest.
     *
     * Unbounded daily snapshots fill a disk, and a full disk on this box
     * stops uCRM writing invoices — a worse failure than the one snapshots
     * exist to prevent.
     *
     * @return array<int,string> names removed
     */
    public static function prune(string $snapRoot, int $keep = self::KEEP): array
    {
        $keep = max(1, $keep);
        $all  = self::snapshots($snapRoot);
        if (count($all) <= $keep) return [];

        $removed = [];
        foreach (array_slice($all, 0, count($all) - $keep) as $name) {
            $dir = $snapRoot . '/' . $name;
            foreach ((array)glob($dir . '/*') as $f) @unlink($f);
            if (@rmdir($dir)) $removed[] = $name;
        }
        return $removed;
    }
}
