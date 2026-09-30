<?php
/**
 * PluginLog — one line in uCRM's log for this plugin, for what must not pass in silence (5.18.53, docs/44 §16.29).
 *
 * The file is data/plugin.log beside the code: the log uCRM shows on the plugin's page, where the master cron's own
 * lines go. It is not the plugin's data directory, which on an install without uCRM's pluginDataDir is a sibling of the
 * plugin (bootstrap_data.php). Each line carries the plugin's clock, as the master cron's lines do.
 *
 * What it is for: a record that could not be saved. The Message Log, the failure queue, the conversation store, the
 * echo claim and the job history each swallow their own failure, so that a message already sent is never undone by
 * its bookkeeping. Until 5.18.53 they did so in silence: message 2 went, and nothing said its records were lost
 * (§16.28).
 *
 * A line never carries a phone number, an e-mail address or a message's text: callers name the record, its table and
 * an event name or a job number, and the error is masked here as well. It never throws and never raises a PHP warning
 * (the staff app's API ends its request at one). When the file cannot be written, the line goes to PHP's error log.
 */
final class PluginLog
{
    /** uCRM's log for this plugin. */
    public static function path(): string
    {
        return dirname(__DIR__) . '/data/plugin.log';
    }

    /**
     * A record that could not be saved: what it was ("the Message Log row"), its table, what it was for (an event name,
     * a job number — never a person), and why.
     */
    public static function notSaved(string $record, string $table, string $context, \Throwable $e): void
    {
        self::write('records', 'not saved: ' . $record . ' (' . $table . '), ' . $context . ' — ' . self::mask($e->getMessage()));
    }

    public static function write(string $tag, string $text): void
    {
        $text = self::mask($text);
        $path = self::path();
        $dir  = dirname($path);
        $done = false;
        if (is_dir($dir) && (is_file($path) ? is_writable($path) : is_writable($dir))) {
            $done = @file_put_contents($path, '[' . self::now() . '] [' . $tag . '] ' . $text . "\n", FILE_APPEND | LOCK_EX) !== false;
        }
        if (!$done) @error_log('[DishNet ' . $tag . '] ' . $text);
    }

    /** One line, no longer than 400 characters, with every e-mail address and phone-like number replaced. */
    public static function mask(string $s): string
    {
        $s = mb_scrub($s, 'UTF-8');
        $s = (string)preg_replace('/[^\s<>()\'"]+@[^\s<>()\'"]+/', '<e-mail>', $s);
        $s = (string)preg_replace('/\+?\d[\d ()-]{5,}\d/', '<number>', $s);
        $s = trim((string)preg_replace('/\s+/', ' ', $s));
        return mb_strlen($s, 'UTF-8') > 400 ? mb_substr($s, 0, 400, 'UTF-8') . '…' : $s;
    }

    /** The plugin's clock, as the master cron writes its own lines; UTC, said so, when the zone cannot be read. */
    private static function now(): string
    {
        try {
            if (!function_exists('dn_tz_obj')) require_once __DIR__ . '/timezone.php';
            return (new \DateTime('now', dn_tz_obj()))->format('Y-m-d H:i:s');
        } catch (\Throwable $e) {
            return gmdate('Y-m-d H:i:s') . ' UTC';
        }
    }
}
