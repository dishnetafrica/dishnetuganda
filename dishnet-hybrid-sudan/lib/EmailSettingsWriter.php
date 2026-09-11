<?php
declare(strict_types=1);

require_once __DIR__ . '/SecureFile.php';

/**
 * EmailSettingsWriter — one door into the file the mail system runs on.
 *
 * There are two copies of the email settings and they are not the same copy.
 * The admin form reads and writes the SQLite store. MailService, the dunning
 * cron, the quote sender and every doctor read the FILE on disk. Nothing
 * carries a change from the store to the file, so a save on the Settings
 * screen looks like it worked, reports success, and changes nothing about
 * what is actually sent. That is why every working configuration on this
 * install was made from the command line.
 *
 * This writes both, with the FILE as the source of truth on read, because
 * the file is what sends mail.
 *
 * Two rules, both learned the expensive way:
 *
 *   NEVER BLANK A PASSWORD. A form posts an empty password box to mean
 *   "leave it alone", and the store's copy of it may be empty while the
 *   file's is correct. Writing the empty one takes customer mail down and
 *   the damage is invisible until something tries to send.
 *
 *   NEVER DROP A KEY YOU DO NOT KNOW. The form knows nine fields. The file
 *   holds more than nine — sent_copy_enabled, system_from, and whatever is
 *   added next. Rebuilding the file from the form silently deletes them.
 */
final class EmailSettingsWriter
{
    /** Fields the admin form owns. Everything else on disk is left alone. */
    private const FORM_KEYS = [
        'recipients', 'use_ucrm_email', 'quote_email_via_plugin',
        'smtp_preset', 'smtp_host', 'smtp_port', 'smtp_user', 'smtp_enc',
        'smtp_from', 'system_from',
    ];

    public static function file(string $dataDir): string
    {
        return rtrim($dataDir, '/') . '/email_settings.json';
    }

    /**
     * What the mail system is actually using.
     *
     * File first. The store fills gaps only, which covers an install where
     * the file has never been written and the store is all there is.
     */
    public static function read(string $dataDir, $store = null): array
    {
        $file = self::file($dataDir);
        $onDisk = is_file($file)
            ? (json_decode((string)@file_get_contents($file), true) ?: [])
            : [];

        $inStore = [];
        if ($store !== null) {
            try { $inStore = $store->load('email_settings.json') ?: []; }
            catch (\Throwable $e) { $inStore = []; }
        }

        return $onDisk + $inStore;   // + keeps the left side: disk wins
    }

    /** How many old copies to keep. A form saved ten times in a row must not
     *  quietly fill the data directory, and ten is further back than anyone
     *  has ever needed to go. */
    private const KEEP_BACKUPS = 10;

    /**
     * Copy the settings file aside before anything touches it.
     *
     * @return array{ok:bool, path:string, error:string}  path '' when there
     *         was no file yet, which is a success, not a failure.
     */
    public static function backup(string $dataDir): array
    {
        $file = self::file($dataDir);
        if (!is_file($file)) return ['ok' => true, 'path' => '', 'error' => ''];

        // A second-resolution stamp collides when two saves land in the same
        // second, and the collision overwrites the older backup — losing
        // exactly the copy worth keeping. Take the next free name instead.
        $stamp = gmdate('Ymd-His');
        $path  = $file . '.bak.' . $stamp;
        for ($n = 2; is_file($path) && $n < 100; $n++) {
            $path = $file . '.bak.' . $stamp . '-' . $n;
        }

        if (!@copy($file, $path)) {
            return ['ok' => false, 'path' => '',
                    'error' => 'could not write a backup of ' . basename($file)
                               . ' — refusing to change it'];
        }
        SecureFile::adopt($path, $dataDir);

        $all = glob($file . '.bak.*') ?: [];
        sort($all);   // the stamp sorts chronologically
        foreach (array_slice($all, 0, max(0, count($all) - self::KEEP_BACKUPS)) as $old) {
            @unlink($old);
        }

        return ['ok' => true, 'path' => $path, 'error' => ''];
    }

    /**
     * Merge $form over what is there and write it to both copies.
     *
     * @param array $form  form keys only; unknown keys are ignored, not stored
     * @return array{ok:bool, error:string, backup:string, changed:string[]}
     */
    public static function save(string $dataDir, $store, array $form): array
    {
        $file = self::file($dataDir);
        $base = self::read($dataDir, $store);
        $next = $base;

        $changed = [];
        foreach (self::FORM_KEYS as $k) {
            if (!array_key_exists($k, $form)) continue;
            $v = $form[$k];
            if (($base[$k] ?? null) !== $v) $changed[] = $k;
            $next[$k] = $v;
        }

        // The password is its own rule: empty means "unchanged", never "clear".
        if (array_key_exists('smtp_pass', $form) && trim((string)$form['smtp_pass']) !== '') {
            $next['smtp_pass'] = (string)$form['smtp_pass'];
            $changed[] = 'smtp_pass';
        } else {
            $next['smtp_pass'] = (string)($base['smtp_pass'] ?? '');
        }

        $b = self::backup($dataDir);
        if (!$b['ok']) {
            return ['ok' => false, 'error' => $b['error'], 'backup' => '', 'changed' => []];
        }
        $backup = $b['path'];

        $json = json_encode($next, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return ['ok' => false, 'error' => 'settings could not be encoded', 'backup' => $backup, 'changed' => []];
        }
        $tmp = $file . '.tmp.' . getmypid();
        if (@file_put_contents($tmp, $json, LOCK_EX) === false || !@rename($tmp, $file)) {
            @unlink($tmp);
            return ['ok' => false, 'error' => 'could not write ' . $file, 'backup' => $backup, 'changed' => []];
        }
        SecureFile::adopt($file, $dataDir);

        // The store too, so the two copies stop drifting apart. A store that
        // refuses the write is not worth failing the save over — the file is
        // what sends the mail, and it is already written.
        if ($store !== null) {
            try { $store->save('email_settings.json', $next); } catch (\Throwable $e) { /* file wins */ }
        }

        return ['ok' => true, 'error' => '', 'backup' => $backup, 'changed' => array_values(array_unique($changed))];
    }
}
