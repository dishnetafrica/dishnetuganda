<?php
declare(strict_types=1);

/**
 * SiblingPlugin — reading data that belongs to another uCRM plugin.
 *
 * Two other plugins hold data this one needs: dishnet-starlink-finance has
 * the KIT register, dishnet-data-report has the router map. Neither is in
 * this repository, so nothing here can test against them, and until now
 * every read was hand-rolled at the call site:
 *
 *     foreach ([dirname(__DIR__, 3) . '/dishnet-data-report/data/x.json',
 *               dirname(__DIR__, 2) . '/../dishnet-data-report/data/x.json'] as $p) {
 *         if (file_exists($p)) { ... }
 *     }
 *     $total = 0;
 *
 * Twenty-two of those. None logged anything. So "the plugin was renamed",
 * "its format changed" and "there are genuinely no routers" all produced the
 * same screen: a zero, rendered confidently.
 *
 * ── THE DIRECTORY THEY WERE ALL READING IS THE WRONG ONE ────────────────
 *
 * Every one of them looked in <plugin>/data. That is the location uCRM
 * DELETES when a plugin is upgraded — this plugin lost its own database to
 * it repeatedly before anyone connected the two events, which is why
 * getDataDir() moved to the <plugins>/.<plugin>-data sibling that survives.
 * If the other plugins made the same move, those reads have been finding
 * nothing ever since, and reporting zero about it.
 *
 * So this checks the surviving directory first, the old one second, and says
 * which it used.
 *
 * ── IT NEVER THROWS, AND IT NEVER LIES ──────────────────────────────────
 *
 * A missing sibling must not take down a page. But it must not read as an
 * empty dataset either: readJson() answers null for "could not read" and an
 * array for "read it", and those are different answers. Every failure is
 * recorded in misses() so a doctor can show what a dashboard is missing
 * rather than what it is guessing.
 */
final class SiblingPlugin
{
    /** @var array<string,string|null> plugin name => resolved dir, or null */
    private static array $dirCache = [];
    /** @var array<string,array<string,mixed>> file key => what went wrong */
    private static array $misses = [];
    /** @var array<string,string> file key => the path that worked */
    private static array $hits = [];

    /**
     * Where this plugin lives — the anchor every sibling is found relative to.
     */
    public static function pluginRoot(): string
    {
        // DN_PLUGIN_ROOT first, matching DN_DATA_DIR's role for the data
        // directory: a way to point this at a different install without
        // moving the code. __DIR__ resolves through symlinks, so a test that
        // stages a plugins directory and links the code into it would
        // otherwise resolve back to the real checkout and find nothing.
        $env = (string)getenv('DN_PLUGIN_ROOT');
        if ($env !== '') return rtrim($env, '/');
        $r = (string)($GLOBALS['_PLUGIN_ROOT'] ?? '');
        return $r !== '' ? rtrim($r, '/') : dirname(__DIR__);
    }

    /** The directory holding all plugins, i.e. this one's parent. */
    public static function pluginsDir(): string
    {
        return dirname(self::pluginRoot());
    }

    /**
     * The directory a sibling plugin's DATA is in, or null if it has none.
     *
     * Runtime directory first. <plugin>/data is the pre-upgrade-safety
     * location and is only used when nothing better exists — a plugin that
     * has not been upgraded since it moved will still have data there.
     */
    public static function dataDir(string $plugin): ?string
    {
        $plugin = trim($plugin, '/ ');
        if ($plugin === '' || strpos($plugin, '..') !== false) return null;
        if (array_key_exists($plugin, self::$dirCache)) return self::$dirCache[$plugin];

        $base = self::pluginsDir();
        foreach ([$base . '/.' . $plugin . '-data', $base . '/' . $plugin . '/data'] as $cand) {
            if (is_dir($cand)) return self::$dirCache[$plugin] = $cand;
        }
        return self::$dirCache[$plugin] = null;
    }

    /** Is the sibling plugin itself installed, whatever state its data is in? */
    public static function installed(string $plugin): bool
    {
        $plugin = trim($plugin, '/ ');
        if ($plugin === '' || strpos($plugin, '..') !== false) return false;
        return is_dir(self::pluginsDir() . '/' . $plugin);
    }

    /**
     * Read a JSON file belonging to another plugin.
     *
     * @param string $plugin  e.g. 'dishnet-data-report'
     * @param string $file    e.g. 'wifi_router_map.json', relative to its data dir
     * @return array|null  null means COULD NOT READ. It is not an empty
     *                     dataset, and a caller that treats it as one is back
     *                     to reporting zero for a missing plugin.
     */
    public static function readJson(string $plugin, string $file): ?array
    {
        $key  = $plugin . '/' . $file;
        $file = ltrim($file, '/');
        // A file name is a file name. '../' in one reaches out of the data
        // directory and into whatever else the web user can read.
        if ($file === '' || strpos($file, '..') !== false) {
            return self::miss($key, 'unsafe file name');
        }

        $dir = self::dataDir($plugin);
        if ($dir === null) {
            return self::miss($key, self::installed($plugin)
                ? "plugin '{$plugin}' is installed but has no data directory"
                : "plugin '{$plugin}' is not installed");
        }

        $path = $dir . '/' . $file;
        if (!is_file($path)) return self::miss($key, "no such file: {$path}");
        if (!is_readable($path)) {
            return self::miss($key, "cannot read {$path} — owned by "
                . (function_exists('posix_getpwuid') && ($u = @fileowner($path)) !== false
                   ? (posix_getpwuid($u)['name'] ?? (string)$u) : 'another user'));
        }

        $raw = @file_get_contents($path);
        if ($raw === false)  return self::miss($key, "read failed: {$path}");
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return self::miss($key, 'not valid JSON: ' . $path
                . ' (' . json_last_error_msg() . ')');
        }

        self::$hits[$key] = $path;
        unset(self::$misses[$key]);
        return $data;
    }

    /**
     * The full path to a sibling's file, or null.
     *
     * For call sites that need the path itself rather than the contents — a
     * modification time for a staleness check, a directory listing, a
     * diagnostic that reports WHERE it read from. Replacing only the path
     * resolution leaves those bodies exactly as they were, which is the
     * point: the fault in them was never the reading, it was looking in the
     * directory uCRM deletes and saying nothing when it was not there.
     */
    public static function path(string $plugin, string $file): ?string
    {
        $key  = $plugin . '/' . $file;
        $file = ltrim($file, '/');
        if ($file === '' || strpos($file, '..') !== false) {
            self::miss($key, 'unsafe file name');
            return null;
        }
        $dir = self::dataDir($plugin);
        if ($dir === null) {
            self::miss($key, self::installed($plugin)
                ? "plugin '{$plugin}' is installed but has no data directory"
                : "plugin '{$plugin}' is not installed");
            return null;
        }
        $path = $dir . '/' . $file;
        if (!is_file($path)) { self::miss($key, "no such file: {$path}"); return null; }

        self::$hits[$key] = $path;
        unset(self::$misses[$key]);
        return $path;
    }

    /**
     * The same path, as '' rather than null when it cannot be resolved.
     *
     * For the call sites that were migrated from hand-built strings. They
     * feed the result straight into file_exists() and is_file(), and PHP 8.1
     * deprecates passing null to those — on pages that run every request,
     * that is a deprecation notice per request per file, which buries
     * everything else in the log. '' is falsy, file_exists('') is false, and
     * those sites behave exactly as they did.
     *
     * Like readJsonOrEmpty(), the collapse is the caller's decision and not
     * the default: path() keeps the honest null for anything new.
     */
    public static function pathOrEmpty(string $plugin, string $file): string
    {
        return self::path($plugin, $file) ?? '';
    }

    /**
     * The first plugin in the list that has this file, as a path.
     *
     * @param string[] $plugins in order of preference
     */
    public static function pathFromAny(array $plugins, string $file): ?string
    {
        foreach ($plugins as $plugin) {
            $p = self::path($plugin, $file);
            if ($p !== null) return $p;
        }
        return null;
    }

    /**
     * The first of several plugins that actually has this file.
     *
     * Two plugins keep the KIT register and two keep the usage figures, at
     * different freshnesses, so the call sites were already written as "try
     * the fresher one, fall back to the other". The order is the caller's
     * preference and is honoured exactly.
     *
     * An EMPTY file is treated as not-found and the next plugin is tried:
     * that is what the hand-rolled loops did (`!empty($kd)`), and it matters
     * — a plugin that has been installed but never synced leaves an empty
     * array behind, and stopping there would hide the plugin that has the data.
     *
     * @param string[] $plugins in order of preference
     * @return array|null null when none of them could be read
     */
    public static function readJsonFromAny(array $plugins, string $file): ?array
    {
        $tried = [];
        foreach ($plugins as $plugin) {
            $data = self::readJson($plugin, $file);
            if ($data !== null && $data !== []) return $data;
            $tried[] = $plugin;
        }
        if ($tried !== []) {
            self::$misses[implode('|', $tried) . '/' . $file] =
                ['why' => 'no plugin had usable data for ' . $file
                        . ' (tried ' . implode(', ', $tried) . ')', 'count' => 1];
        }
        return null;
    }

    /**
     * Which plugin answered for this file, or '' — for a doctor that needs to
     * say "the usage figures are coming from the FALLBACK plugin", which is
     * the difference between fresh data and data from last week.
     */
    public static function sourceOf(string $file): string
    {
        foreach (self::$hits as $key => $path) {
            if (substr($key, -strlen('/' . $file)) === '/' . $file) {
                return substr($key, 0, strlen($key) - strlen('/' . $file));
            }
        }
        return '';
    }

    /**
     * The same read, for a caller that genuinely wants an empty array when
     * the data is unavailable — a count, a lookup table where absent and
     * empty mean the same thing to the reader.
     *
     * Separate from readJson() on purpose: this collapse must be a decision
     * somebody made at the call site, not the default that hid 22 of them.
     */
    public static function readJsonOrEmpty(string $plugin, string $file): array
    {
        return self::readJson($plugin, $file) ?? [];
    }

    /** @return array<string,array<string,mixed>> every read that failed */
    public static function misses(): array { return self::$misses; }

    /** @return array<string,string> every read that worked, and from where */
    public static function hits(): array { return self::$hits; }

    /** Forget everything cached. For tests, and for a long-running worker. */
    public static function reset(): void
    {
        self::$dirCache = [];
        self::$misses   = [];
        self::$hits     = [];
    }

    /**
     * What every known sibling looks like right now, for a doctor.
     *
     * @param string[] $plugins
     * @return array<int,array<string,mixed>>
     */
    public static function survey(array $plugins): array
    {
        $out = [];
        foreach ($plugins as $p) {
            $dir = self::dataDir($p);
            $out[] = [
                'plugin'    => $p,
                'installed' => self::installed($p),
                'data_dir'  => $dir ?? '',
                // Saying WHICH directory answered matters: the legacy one is
                // deleted the next time that plugin is upgraded, so a read
                // working today says nothing about next week.
                'legacy'    => $dir !== null && substr($dir, -5) === '/data',
            ];
        }
        return $out;
    }

    /**
     * Record a failed read once, with a count.
     *
     * error_log on the first occurrence only. A dashboard that reads the
     * router map in a loop would otherwise write a thousand identical lines
     * and bury everything else in the log.
     */
    private static function miss(string $key, string $why): ?array
    {
        if (isset(self::$misses[$key])) {
            self::$misses[$key]['count']++;
        } else {
            self::$misses[$key] = ['why' => $why, 'count' => 1];
            error_log("[SiblingPlugin] {$key}: {$why}");
        }
        return null;
    }
}
