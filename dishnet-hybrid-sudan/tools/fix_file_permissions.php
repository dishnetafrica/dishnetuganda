<?php
declare(strict_types=1);
chdir(dirname(__DIR__));

/**
 * fix_file_permissions.php — make the plugin's config files readable by the
 * process that actually uses them.
 *
 *   php tools/fix_file_permissions.php          report only
 *   php tools/fix_file_permissions.php --fix    repair
 *
 * Several tools write secrets with chmod 0600. Run through `docker exec` as
 * root that produces root-owned, root-only files — and the webhook, the crons
 * and the admin tabs all run as the web user. They then see no configuration
 * at all and report it as "not configured", which sends you looking at the
 * settings rather than at the file mode.
 *
 * The repair is ownership, not permissiveness: the file goes to whoever owns
 * the data directory, which uCRM created and its web process owns, at 0640.
 * Other accounts on the host still cannot read it.
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }

$root = dirname(__DIR__);
require_once $root . '/lib/bootstrap_data.php';
require_once $root . '/lib/SecureFile.php';

$dataDir = getenv('DN_DATA_DIR') ?: getDataDir($root);
$fix     = in_array('--fix', $argv, true);

echo str_repeat('-', 66) . "\n";
echo "Plugin config files in {$dataDir}\n";
printf("Directory owned by %s\n", SecureFile::describeOwner($dataDir));
echo str_repeat('-', 66) . "\n";

$files = ['email_settings.json', 'kyc_config.json', 'config.json',
          'wa_templates.json', 'vault.json'];
$bad = 0;
foreach ($files as $f) {
    $path = $dataDir . '/' . $f;
    if (!is_file($path)) { printf("  %-24s (not present)\n", $f); continue; }
    $mode = substr(sprintf('%o', @fileperms($path) ?: 0), -4);
    $own  = SecureFile::describeOwner($path);
    // Not "is the mode 0600" — a file owned by nginx at 0600 is readable BY
    // nginx. Only a file the owning process cannot open is a problem.
    $r    = SecureFile::readableByOwnerOf($path, $dataDir);
    printf("  %-24s %s %-16s %s\n", $f, $mode, $own,
           $r['ok'] ? 'ok' : 'UNREADABLE — ' . $r['why']);
    if ($r['ok']) continue;
    $bad++;
    if ($fix) {
        SecureFile::adopt($path, $dataDir);
        printf("  %-24s -> now %s %s\n", '', substr(sprintf('%o', @fileperms($path) ?: 0), -4),
               SecureFile::describeOwner($path));
    }
}

echo str_repeat('-', 66) . "\n";
if ($bad === 0) {
    echo "All present files are readable by the process that owns the data dir.\n";
    exit(0);
}
if (!$fix) {
    echo "{$bad} file(s) the web process cannot read. Repair with:\n";
    echo "  php tools/fix_file_permissions.php --fix\n";
    exit(1);
}
echo "Repaired {$bad} file(s). Create a quote to confirm the webhook can now send.\n";
exit(0);
