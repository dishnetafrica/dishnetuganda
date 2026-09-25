<?php
declare(strict_types=1);
/**
 * The plugin lifecycle: install, uninstall, status.
 *
 *   php plugin/bin/plugin.php doctor [--disposable]
 *   php plugin/bin/plugin.php status
 *   php plugin/bin/plugin.php install
 *   php plugin/bin/plugin.php uninstall --i-understand-this-drops-data
 *   php plugin/bin/plugin.php staff:bootstrap <username> [--display "Full Name"]
 *
 * This is what makes the control plane an installable unit rather than a
 * directory that happens to contain code: one manifest, one entry point, and
 * a status command that reports what is actually true of this installation
 * rather than what the manifest hopes.
 */
require dirname(__DIR__, 2) . '/src/autoload.php';

use Dn\Admin\StaffAdmin;
use Dn\Admin\StaffRefused;
use Dn\Db\Database;
use Dn\Plugin\Manifest;
use Dn\Plugin\Installer;
use Dn\Plugin\Doctor;
use Dn\Plugin\Simulator;

$root = dirname(__DIR__, 2);
$cmd  = $argv[1] ?? 'status';
$m    = Manifest::load($root . '/plugin/plugin.json');
$inst = new Installer($m, $root);

$out = static function (string $label, string $value = '') {
    printf("  %-34s %s\n", $label, $value);
};

switch ($cmd) {
    case 'status':
        printf("\n%s %s\n\n", $m->name, $m->version);
        $s = $inst->status();
        $out('database reachable', $s['db'] ? 'yes' : 'NO');
        $out('schema installed', $s['schema'] ? 'yes (' . $s['migrations'] . ' migrations)' : 'NO');
        $out('admin read role', $s['adminapi'] ? 'present' : 'absent');
        $out('API entrypoint', $m->apiEntrypoint);
        $out('API surface', $m->apiSurface . ', ' . count($m->routes) . ' routes');
        $out('write routes declared', (string) count($m->writeRoutes));
        echo "\n  gates\n";
        foreach ($m->gates as $name => $g) { $out('  ' . $name, $g['state']); }
        echo "\n  configuration\n";
        foreach ($m->config as $key => $spec) {
            // A secret is reported as set or unset. Never printed.
            $set = (getenv($key) ?: '') !== '';
            $out('  ' . $key, $spec['secret']
                ? ($set ? 'set (value withheld)' : ($spec['required'] ? 'MISSING (required)' : 'unset'))
                : ($set ? 'set' : ($spec['required'] ? 'MISSING (required)' : 'unset')));
        }
        echo "\n";
        exit($inst->status()['db'] ? 0 : 1);

    case 'doctor':
        // Preflight. Run it before install, and again after.
        //
        // --disposable says this is a throwaway environment, which downgrades
        // the development-affordance checks from blocker to warning. It does
        // NOT relax anything else: a development password is a blocker in a
        // disposable environment too, because the check exists to stop that
        // password from travelling.
        $disposable = in_array('--disposable', $argv, true);
        $doc  = new Doctor($m, $root, $disposable);
        $rows = $doc->run();
        printf("\n%s %s — preflight%s\n\n", $m->name, $m->version,
               $disposable ? ' (disposable environment)' : '');
        $mark = ['ok' => '  ok   ', 'warn' => '  warn ', 'blocker' => '  BLOCK', 'skip' => '  skip '];
        foreach ($rows as $row) {
            printf("%s  %-34s %s\n", $mark[$row['state']], $row['label'], $row['detail']);
        }
        $b = $doc->blockers($rows);
        $counts = array_count_values(array_column($rows, 'state'));
        printf("\n  %d checks: %d ok, %d warn, %d blocker, %d not measured\n\n",
               count($rows), $counts['ok'] ?? 0, $counts['warn'] ?? 0,
               $counts['blocker'] ?? 0, $counts['skip'] ?? 0);
        if ($b !== []) {
            echo "  Not ready. Each blocker above must be cleared first.\n\n";
        }
        exit($b === [] ? 0 : 1);

    case 'install':
        echo "installing {$m->id} {$m->version}\n";
        foreach ($inst->install() as $line) { echo "  {$line}\n"; }
        echo "done. Run `php plugin/bin/plugin.php status` to verify.\n";
        exit(0);

    case 'uninstall':
        if (($argv[2] ?? '') !== '--i-understand-this-drops-data') {
            fwrite(STDERR,
                "Refusing to uninstall.\n\n"
                . "This drops every table this plugin owns: routers, vouchers,\n"
                . "sessions, audit history. It is not reversible and there is no\n"
                . "backup step here.\n\n"
                . "  php plugin/bin/plugin.php uninstall --i-understand-this-drops-data\n");
            exit(2);
        }
        foreach ($inst->uninstall() as $line) { echo "  {$line}\n"; }
        exit(0);

    case 'simulate':
        // A clearly-labelled demo estate, built through the real write paths.
        // It refuses to run where real bindings are allowed: a simulated router
        // must never appear in a process that can reach a real one.
        if (\Dn\Runtime\Bindings::realBindingsAllowed()) {
            fwrite(STDERR, "Refusing: real bindings are enabled. The simulator must not\n"
                         . "share a process with real MikroTik or FreeRADIUS adapters.\n");
            exit(2);
        }
        $sim = new Simulator(true);
        if ($sim->alreadyBuilt() && ($argv[2] ?? '') !== '--again') {
            fwrite(STDERR, "A simulated estate is already present. Use --again to add another.\n");
            exit(2);
        }
        echo "building the simulated estate\n";
        $sim->build();
        echo "done. Every identifier is prefixed " . Simulator::MARK . " so nothing here\n"
           . "can be mistaken for a production record.\n";
        exit(0);

    case 'staff:bootstrap':
        // The first DishNet administrator (docs/114 §C.2, migration 026). Runs on
        // the server, as the Admin write connection, and REFUSES once any staff
        // row exists — later people are created from the panel by an Admin.
        // The generated password is printed ONCE, to the terminal, and nowhere
        // else: not logged, not audited, not written to a file.
        $username = strtolower(trim((string) ($argv[2] ?? '')));
        if ($username === '' || str_starts_with($username, '--')) {
            fwrite(STDERR, "use: php plugin/bin/plugin.php staff:bootstrap <username> [--display \"Full Name\"]\n");
            exit(2);
        }
        $display = $username;
        $at = array_search('--display', $argv, true);
        if ($at !== false && isset($argv[$at + 1])) { $display = trim((string) $argv[$at + 1]); }
        // The actor is the person at the keyboard, recorded as such. This is the
        // one act with no authenticated DishNet staff member to name, because it
        // is the act that creates the first one.
        $actor = 'cli:' . (get_current_user() ?: 'unknown');
        try {
            $made = StaffAdmin::on(Database::adminWrite())->bootstrap($username, $display, $actor);
        } catch (StaffRefused $e) {
            fwrite(STDERR, "refused: {$e->getMessage()}\n");
            exit(1);
        }
        echo "created the first DishNet administrator\n";
        $out('username', $username);
        $out('display name', $display);
        $out('role', 'admin');
        $out('id', $made['id']);
        echo "\n  ONE-TIME PASSWORD (shown once, stored nowhere):\n\n    {$made['password']}\n\n"
           . "  Sign in with DN_STAFF_IDENTITY=dishnet, then enrol an authenticator and\n"
           . "  change this password from the panel. It cannot be recovered from the database.\n";
        exit(0);

    default:
        fwrite(STDERR, "unknown command: {$cmd}\n"
                     . "use: doctor | status | install | uninstall | simulate | staff:bootstrap\n"
                     . "serve the panel and API with:\n"
                     . "  php -S 127.0.0.1:8099 plugin/bin/serve.php\n");
        exit(2);
}
