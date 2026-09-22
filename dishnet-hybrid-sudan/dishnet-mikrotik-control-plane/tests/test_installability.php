<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

use Dn\Plugin\Doctor;
use Dn\Plugin\Installer;
use Dn\Plugin\Manifest;

/**
 * Is this thing installable, and does it stay installable?
 *
 * Most of what follows is drift protection rather than behaviour. The
 * installability audit found three defects of exactly one shape — a list
 * somewhere that had fallen behind the code it described:
 *
 *   the manifest declared 5 configuration keys; the code read 21
 *   uninstall dropped 4 roles; the migrations create 12
 *   the doctor's development-password list had to match migration 001
 *
 * Each was invisible until something enumerated both sides and compared them.
 * These tests are that enumeration, so the next divergence fails here rather
 * than on a server.
 */

$root = dirname(__DIR__);
$m    = Manifest::load($root . '/plugin/plugin.json');

/** Every .php under a directory, including nested ones. A glob would miss them. */
$phpFiles = static function (array $dirs) use ($root): array {
    $out = [];
    foreach ($dirs as $d) {
        if (!is_dir($root . '/' . $d)) { continue; }
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $d));
        foreach ($it as $f) {
            if ($f->isFile() && $f->getExtension() === 'php') { $out[] = $f->getPathname(); }
        }
    }
    return $out;
};

// ───────────────────────────────────────────────────────────────────────────
t('the manifest declares every variable the code actually reads');

$files = $phpFiles(['src', 'bin', 'plugin', 'tools']);
is_(count($files) > 40, true, 'the sweep found ' . count($files) . ' php files (a vacuous sweep proves nothing)');

$read = [];
foreach ($files as $f) {
    if (preg_match_all("/getenv\('([A-Z0-9_]+)'\)/", (string) file_get_contents($f), $mm)) {
        foreach ($mm[1] as $v) { $read[$v] = true; }
    }
}
// Constants hold two of them rather than literals.
$read['DN_DEV_STAFF_IDENTITY'] = true;
$read['DN_ALLOW_REAL_BINDINGS'] = true;
ksort($read);
is_(count($read) >= 20, true, 'the code reads ' . count($read) . ' distinct environment variables');

$undeclared = array_values(array_diff(array_keys($read), array_keys($m->config)));
is_($undeclared, [], 'every variable the code reads is declared in the manifest'
    . ($undeclared ? ' — UNDECLARED: ' . implode(', ', $undeclared) : ''));

$phantom = array_values(array_diff(array_keys($m->config), array_keys($read)));
is_($phantom, [], 'the manifest declares nothing the code never reads'
    . ($phantom ? ' — PHANTOM: ' . implode(', ', $phantom) : ''));

foreach (['DNB_TOKEN_PEPPER', 'DNB_SECRET_KEY', 'DNB_ADMINAPI_PASS', 'DNB_INTERNAL_TOKEN'] as $k) {
    is_($m->config[$k]['secret'] ?? null, true, "{$k} is marked secret, so status never prints it");
}

// ───────────────────────────────────────────────────────────────────────────
t('uninstall knows about every role install creates');

$sql = '';
foreach (glob($root . '/migrations/*.sql') ?: [] as $f) { $sql .= file_get_contents($f); }
is_(strlen($sql) > 50000, true, 'read ' . count(glob($root . '/migrations/*.sql') ?: []) . ' migration file(s)');

preg_match_all("/CREATE ROLE ([a-z_]+)\b/", $sql, $mm);
$created = array_values(array_unique(array_filter($mm[1], static fn($r) => str_starts_with($r, 'dnb'))));
// The definer roles are created through a format() loop, so their names appear
// as quoted literals rather than after CREATE ROLE.
preg_match_all("/'(dnb_def_[a-z]+)'/", $sql, $dd);
$created = array_values(array_unique(array_merge($created, $dd[1])));
sort($created);
is_(count($created) >= 12, true, 'the migrations create ' . count($created) . ' roles');

$missed = array_values(array_diff($created, Installer::ROLES));
is_($missed, [], 'Installer::ROLES covers every one of them'
    . ($missed ? ' — NOT DROPPED: ' . implode(', ', $missed) : ''));

$ghost = array_values(array_diff(Installer::ROLES, $created));
is_($ghost, [], 'and drops nothing the migrations do not create'
    . ($ghost ? ' — GHOST: ' . implode(', ', $ghost) : ''));

is_(in_array('dnb', Installer::ROLES, true), false,
    'the owner role is NOT dropped — bootstrap.sql created it, not the installer');

// ───────────────────────────────────────────────────────────────────────────
t('the doctor tests the passwords the migrations actually set');

preg_match_all("/CREATE ROLE ([a-z_]+) LOGIN PASSWORD '([^']*)'/", $sql, $pp, PREG_SET_ORDER);
$literals = [];
foreach ($pp as $row) { $literals[$row[1]] = $row[2]; }
ksort($literals);
$known = Doctor::DEV_PASSWORDS;
ksort($known);
is_(count($literals) >= 6, true, 'the migrations set ' . count($literals) . ' password literals');
is_($known, $literals, 'Doctor::DEV_PASSWORDS matches them exactly, so the check cannot go stale');

// ───────────────────────────────────────────────────────────────────────────
t('the doctor reports what it could not measure, rather than passing');

$saveDsn = getenv('DNB_DSN');
putenv('DNB_DSN=pgsql:host=/nonexistent-socket-dir;dbname=nope');
$rows = (new Doctor($m, $root, true))->run();
putenv($saveDsn === false ? 'DNB_DSN' : "DNB_DSN={$saveDsn}");

$by = [];
foreach ($rows as $r) { $by[$r['id']] = $r; }
is_($by['schema.present']['state'] ?? null, Doctor::SKIP,
    'an unreachable database makes the schema check SKIP, not ok');
is_(str_contains($by['schema.present']['detail'] ?? '', 'NOT MEASURED'), true,
    'and it says NOT MEASURED in so many words');
is_($by['db.owner']['state'] ?? null, Doctor::BLOCKER,
    'an unreachable database is itself a blocker');
is_(str_contains(strtolower($by['db.owner']['detail'] ?? ''), 'password='), false,
    'a driver error never carries a password into the report');

$states = array_unique(array_column($rows, 'state'));
foreach ($states as $s) {
    is_(in_array($s, [Doctor::OK, Doctor::WARN, Doctor::BLOCKER, Doctor::SKIP], true), true,
        "state '{$s}' is one of the four defined states");
}
is_(count((new Doctor($m, $root, true))->blockers($rows)) < count($rows), true,
    'blockers() filters rather than returning everything');

// A disposable environment downgrades the development affordances, and only those.
$saveOtp = getenv('DNB_EXPOSE_OTP');
putenv('DNB_EXPOSE_OTP=1');
$strict = [];
foreach ((new Doctor($m, $root, false))->run() as $r) { $strict[$r['id']] = $r['state']; }
$loose = [];
foreach ((new Doctor($m, $root, true))->run() as $r) { $loose[$r['id']] = $r['state']; }
putenv($saveOtp === false ? 'DNB_EXPOSE_OTP' : "DNB_EXPOSE_OTP={$saveOtp}");
is_($strict['env.DNB_EXPOSE_OTP'], Doctor::BLOCKER, 'DNB_EXPOSE_OTP blocks a real installation');
is_($loose['env.DNB_EXPOSE_OTP'],  Doctor::WARN,    'and only warns in a disposable one');

// ───────────────────────────────────────────────────────────────────────────
t('the package excludes what must never ship');

$pkg = (string) file_get_contents($root . '/plugin/bin/package.sh');
foreach (['tests', 'tools', 'docs', 'public'] as $dir) {
    is_(str_contains($pkg, $dir . '/'), true, "package.sh names {$dir}/ in its exclusion rationale");
}
foreach (['src', 'panel', 'plugin', 'migrations'] as $dir) {
    is_(str_contains($pkg, '"$root/' . $dir . '"') || str_contains($pkg, '"$root"/' . $dir . '/*.sql'), true,
        "package.sh copies {$dir}/");
}
is_(str_contains($pkg, 'sha256sum -c SHA256SUMS'), true,
    'the builder verifies the archive by extracting it, not by having written it');
is_(str_contains($pkg, 'rm -f "$dest/plugin/.env"'), true,
    'a configured .env can never travel inside the package');

// ───────────────────────────────────────────────────────────────────────────
t('neither static server serves a file outside panel/');

foreach (['plugin/bin/serve.php' => 'the packaged server',
          'tools/dev_server.php' => 'the development server'] as $path => $label) {
    $src = (string) file_get_contents($root . '/' . $path);
    is_(str_contains($src, 'realpath'), true, "{$label} resolves the target with realpath");
    is_(preg_match('/str_starts_with\(\s*\$target,\s*\$panel/', $src) === 1, true,
        "{$label} requires the resolved path to sit under panel/");
    is_(str_contains($src, 'X-Content-Type-Options'), true, "{$label} sends nosniff");
}

// ───────────────────────────────────────────────────────────────────────────
t('bootstrap.sql does the privileged step and nothing more');

$boot = (string) file_get_contents($root . '/plugin/bin/bootstrap.sql');
is_(preg_match('/CREATE TABLE/i', $boot), 0, 'it creates no table');
is_(preg_match('/GRANT /i', $boot), 0, 'it grants nothing');
is_(preg_match("/PASSWORD\s+'/i", $boot), 0, 'it contains no password literal');
is_(str_contains($boot, "CREATE DATABASE %I OWNER %I"), true, 'it creates the database');
// The attribute list, asserted literally. A regex bounded by ';' ran past the
// whole statement here — bootstrap.sql ends its CREATE ROLE with \gexec, not a
// semicolon — and matched the word SUPERUSER in a later SELECT.
preg_match("/'(CREATE ROLE %I[^']*)'/", $boot, $attr);
is_($attr[1] ?? '', 'CREATE ROLE %I LOGIN CREATEROLE CREATEDB PASSWORD %L',
    'the owner is created LOGIN CREATEROLE CREATEDB — not superuser, not BYPASSRLS');
is_(str_contains($boot, 'WHERE NOT EXISTS'), true, 'it is idempotent, so a re-run rotates no credential');

// ───────────────────────────────────────────────────────────────────────────
t('the disposable installation test proves each step it claims');

$it = (string) file_get_contents($root . '/plugin/bin/install-test.sh');
$proofs = [
    'install applies every migration'  => 'migrations run',
    'panel loads'                      => 'the panel is served',
    'API admits nobody by default'     => 'the production identity binding admits nobody',
    'API health answers'               => 'the API answers',
    'simulated $k visible through the API' => 'simulator data reaches the API',
    'install creates the 12 plugin roles' => 'the positive control for the residue check',
    'no plugin role remains'           => 'uninstall leaves no role',
    'no mt_ table remains'             => 'uninstall leaves no table',
    'refuses path traversal'           => 'the traversal regression',
];
foreach ($proofs as $needle => $what) {
    is_(str_contains($it, $needle), true, "it checks {$what}");
}
is_(str_contains($it, 'initdb'), true, 'it builds its own cluster rather than using an existing one');
is_(str_contains($it, "listen_addresses=''"), true, 'and that cluster opens no TCP port');

// ───────────────────────────────────────────────────────────────────────────
t('the manifest still describes a read-only, ungated plugin');

is_($m->consumesDomainA, false, 'it declares no Domain A dependency');
is_($m->writeRoutes, [], 'no write route is bound');
is_($m->gateIsOpen('F6-B'), false, 'the F6-B gate is shut');
is_(($m->requires['redis'] ?? null), false, 'it requires no Redis');
is_(($m->requires['docker'] ?? null), false, 'it requires no Docker');
is_(isset($m->requires['web_server']), true, 'it states what a web server must do');
$required = array_keys(array_filter($m->config, static fn($c) => $c['required'] ?? false));
is_($required !== [], true, 'the manifest marks ' . count($required) . ' key(s) required');
$saved = [];
foreach ($required as $k) { $saved[$k] = getenv($k); putenv("{$k}=present"); }
is_($m->missingConfig(), [], 'missingConfig() is empty when every required key is set');
putenv($required[0]);
is_($m->missingConfig(), [$required[0]],
    'and names exactly the one that is unset — so install refuses for a stated reason');
foreach ($saved as $k => $v) { putenv($v === false ? $k : "{$k}={$v}"); }

exit(t_summary());
