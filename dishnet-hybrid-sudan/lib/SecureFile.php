<?php
/**
 * SecureFile — write a secret file that the web process can still read.
 *
 * email_settings.json holds an SMTP password, so the tools that write it
 * chmod 0600. Run through `docker exec` as root, that produces a root-owned,
 * root-only file — and the webhook runs as the web user. getConfig() then
 * returned an empty array and the plugin reported "plugin mail is not
 * configured" while the same file read perfectly from the command line.
 *
 * That cost a morning. The file has to be private from other accounts AND
 * readable by the process that actually sends the mail, and those are only in
 * tension if you ignore ownership: give it to whoever owns the data directory
 * — which uCRM created and its web process owns — and 0640 satisfies both.
 *
 * PHP 7.4 compatible.
 */
declare(strict_types=1);

class SecureFile
{
    /**
     * Write atomically, then hand the file to the data directory's owner.
     *
     * @return array{ok:bool, error:string, owner:string, mode:string}
     */
    public static function write(string $file, string $contents, int $mode = 0640): array
    {
        $out = ['ok' => false, 'error' => '', 'owner' => '', 'mode' => ''];
        $dir = dirname($file);
        $tmp = $file . '.tmp.' . getmypid();

        if (@file_put_contents($tmp, $contents, LOCK_EX) === false) {
            $out['error'] = "could not write {$tmp}";
            return $out;
        }
        if (!@rename($tmp, $file)) {
            @unlink($tmp);
            $out['error'] = "could not replace {$file}";
            return $out;
        }

        self::adopt($file, $dir, $mode);
        $out['ok']    = true;
        $out['owner'] = self::describeOwner($file);
        $out['mode']  = substr(sprintf('%o', @fileperms($file) ?: 0), -4);
        return $out;
    }

    /**
     * Give an existing file the data directory's ownership and a mode the web
     * process can read. Safe to call on a file written by someone else — it
     * simply fails quietly where the caller lacks the privilege.
     */
    public static function adopt(string $file, string $dir = '', int $mode = 0640): void
    {
        if (!is_file($file)) return;
        $dir = $dir !== '' ? $dir : dirname($file);
        $st  = @stat($dir);
        if (is_array($st)) {
            // chown only succeeds as root, which is exactly the case that
            // creates the problem; elsewhere it is a harmless no-op.
            @chown($file, (int)$st['uid']);
            @chgrp($file, (int)$st['gid']);
        }
        @chmod($file, $mode);
    }

    /** "root:root (0600)" style description, for doctors and setup output. */
    public static function describeOwner(string $path): string
    {
        // file_exists, not is_file: this is asked about the DATA DIRECTORY as
        // often as about a file, and is_file() answers false for a directory —
        // which printed "Directory owned by (missing)" and then made every
        // correctly-owned file look wrong, because the comparison had nothing
        // real to compare against.
        if (!file_exists($path)) return '(missing)';
        $file = $path;
        $uid = @fileowner($file);
        $gid = @filegroup($file);
        $u = function_exists('posix_getpwuid') && $uid !== false ? (posix_getpwuid($uid)['name'] ?? $uid) : $uid;
        $g = function_exists('posix_getgrgid') && $gid !== false ? (posix_getgrgid($gid)['name'] ?? $gid) : $gid;
        return "{$u}:{$g}";
    }

    /**
     * Can the process that owns $dir read $file?
     *
     * That is the question that matters, and it is not "is the mode 0600".
     * A file owned by nginx at 0600 is perfectly readable by nginx; a file
     * owned by root at 0600 is not. Judging by mode alone flagged the first
     * as broken, which is a false alarm that teaches an operator to ignore
     * the tool.
     *
     * @return array{ok:bool, why:string}
     */
    public static function readableByOwnerOf(string $file, string $dir): array
    {
        if (!is_file($file))    return ['ok' => true,  'why' => 'not present'];
        if (!file_exists($dir)) return ['ok' => true,  'why' => 'cannot identify the owning process'];

        $perms = @fileperms($file) ?: 0;
        $mode  = substr(sprintf('%o', $perms), -4);
        $fUid  = @fileowner($file); $fGid = @filegroup($file);
        $dUid  = @fileowner($dir);  $dGid = @filegroup($dir);

        if ($perms & 0004) return ['ok' => true, 'why' => "{$mode} world-readable"];
        if ($fUid === $dUid && ($perms & 0400)) {
            return ['ok' => true, 'why' => "{$mode} owned by the same user"];
        }
        if ($fGid === $dGid && ($perms & 0040)) {
            return ['ok' => true, 'why' => "{$mode} shared group"];
        }
        return ['ok' => false,
                'why' => "{$mode} " . self::describeOwner($file)
                       . ' but the process runs as ' . self::describeOwner($dir)];
    }

    /**
     * Can another process read this? is_readable() answers for the CURRENT
     * user, which is the one question that was never in doubt.
     *
     * @return array{readable_by_others:bool, why:string}
     */
    public static function auditReadability(string $file): array
    {
        if (!is_file($file)) return ['readable_by_others' => false, 'why' => 'the file does not exist'];
        $perms = @fileperms($file) ?: 0;
        $group = (bool)($perms & 0040);
        $other = (bool)($perms & 0004);
        $mode  = substr(sprintf('%o', $perms), -4);
        if ($group || $other) {
            return ['readable_by_others' => true,
                    'why' => "mode {$mode} — group or world can read it"];
        }
        return ['readable_by_others' => false,
                'why' => "mode {$mode} owned by " . self::describeOwner($file)
                       . ' — ONLY that user can read it, so a web process running '
                       . 'as anyone else sees no configuration at all'];
    }
}
