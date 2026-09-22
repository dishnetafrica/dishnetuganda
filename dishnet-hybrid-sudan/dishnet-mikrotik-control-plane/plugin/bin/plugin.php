<?php
declare(strict_types=1);
/**
 * The plugin lifecycle: install, uninstall, status.
 *
 *   php plugin/bin/plugin.php status
 *   php plugin/bin/plugin.php install
 *   php plugin/bin/plugin.php uninstall --i-understand-this-drops-data
 *
 * This is what makes the control plane an installable unit rather than a
 * directory that happens to contain code: one manifest, one entry point, and
 * a status command that reports what is actually true of this installation
 * rather than what the manifest hopes.
 */
require dirname(__DIR__, 2) . '/src/autoload.php';

use Dn\Plugin\Manifest;
use Dn\Plugin\Installer;
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

    default:
        fwrite(STDERR, "unknown command: {$cmd}\nuse: status | install | uninstall | simulate\n");
        exit(2);
}
