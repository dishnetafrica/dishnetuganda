<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

use Dn\Plugin\Credentials;
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
    $src = (string) file_get_contents($f);
    // getenv('X'), and $need('X', ...) — the fail-closed reader in Database,
    // which names the variable as an argument rather than calling getenv on it.
    foreach (["/getenv\\('([A-Z0-9_]+)'\\)/", "/\\\$need\\('([A-Z0-9_]+)'/"] as $re) {
        if (preg_match_all($re, $src, $mm)) {
            foreach ($mm[1] as $v) { $read[$v] = true; }
        }
    }
}
// Three sets are held as constants rather than as literals at the call site.
$read['DN_DEV_STAFF_IDENTITY'] = true;
$read['DN_ALLOW_REAL_BINDINGS'] = true;
foreach (Credentials::ROLE_ENV as $env)      { $read[$env] = true; }
foreach (array_keys(Credentials::APP_SECRETS) as $env) { $read[$env] = true; }
ksort($read);
is_(count($read) >= 20, true, 'the code reads ' . count($read) . ' distinct environment variables');
foreach (Credentials::ROLE_ENV as $role => $env) {
    is_(str_contains((string) file_get_contents($root . '/src/Db/Database.php'), "\$need('{$env}'"), true,
        "Database::connect() requires {$env} rather than defaulting it");
}

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
t('B-1 — no credential is in the repository, and the burned ones stay burned');

// The fix itself: not one password literal survives in any migration.
preg_match_all("/CREATE ROLE ([a-z_]+) LOGIN PASSWORD '([^']*)'/", $sql, $pp, PREG_SET_ORDER);
is_($pp, [], 'no migration creates a role with a password literal'
    . ($pp ? ' — FOUND: ' . implode(', ', array_column($pp, 1)) : ''));

// And the six login roles are still created — the fix removed a clause, not a role.
preg_match_all('/CREATE ROLE ([a-z_]+) LOGIN\b/', $sql, $lg);
$loginRoles = array_values(array_unique($lg[1]));
sort($loginRoles);
$expected = array_keys(Credentials::ROLE_ENV);
sort($expected);
is_($loginRoles, $expected, 'all seven login roles are still created, just without a credential');

// Nothing anywhere re-introduces one.
$offenders = [];
foreach ($phpFiles(['src', 'bin', 'plugin', 'tools']) as $f) {
    if (str_ends_with($f, 'src/Plugin/Doctor.php')) { continue; }   // the burned list
    $src = (string) file_get_contents($f);
    foreach (Doctor::DEV_PASSWORDS as $burned) {
        if (str_contains($src, $burned)) { $offenders[] = basename($f) . ':' . $burned; }
    }
}
is_($offenders, [], 'no burned credential appears in any source file outside the burned list'
    . ($offenders ? ' — ' . implode(', ', $offenders) : ''));
foreach (Doctor::DEV_PASSWORDS as $burned) {
    is_(str_contains($sql, $burned), false, "the burned string for its role is gone from migrations/");
}
is_(count(Doctor::DEV_PASSWORDS), 6, 'the doctor still checks all six burned strings');

// Exactly one file in the tree may carry them. install-test.sh used to hold a
// second copy; it now reads them from this constant, so there is one place to
// look and one place that can go stale.
$carriers = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,
        RecursiveDirectoryIterator::SKIP_DOTS));
foreach ($it as $f) {
    if (!$f->isFile()) { continue; }
    $path = $f->getPathname();
    if (str_contains($path, '/.git/') || str_contains($path, '/dist/')) { continue; }
    if (str_contains($path, '/tests/test_installability.php')) { continue; }  // this file
    $body = (string) @file_get_contents($path);
    foreach (Doctor::DEV_PASSWORDS as $burned) {
        if (str_contains($body, $burned)) { $carriers[] = str_replace($root . '/', '', $path); break; }
    }
}
is_(array_values(array_unique($carriers)), ['src/Plugin/Doctor.php'],
    'src/Plugin/Doctor.php is the ONLY file carrying a burned string'
    . ($carriers ? ' — carriers: ' . implode(', ', array_unique($carriers)) : ''));
// The burned list is HISTORY, not a description of the roles: it names the six
// roles that ever carried a password literal. dnb_staffauth (migration 026) was
// born after B-1 and never had one, so it is correctly absent — and the list
// must not be "updated" to include it.
is_(array_keys(Doctor::DEV_PASSWORDS), array_values(array_diff(array_keys(Credentials::ROLE_ENV), ['dnb_staffauth'])),
    'and covers exactly the six login roles that ever had a burned credential');
is_(in_array('dnb_staffauth', array_keys(Credentials::ROLE_ENV), true), true,
    'CONTROL: dnb_staffauth is a provisioned login role that the burned list rightly omits');

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
t('the credential mechanism itself');

$saved = [];
foreach (array_merge(array_values(Credentials::ROLE_ENV),
                     array_keys(Credentials::APP_SECRETS)) as $env) {
    $saved[$env] = getenv($env);
    putenv("{$env}=supplied-for-this-assertion");
}
is_(Credentials::wouldGenerate(), [],
    'nothing is generated when every secret is supplied');
putenv('DNB_APP_PASS');
is_(Credentials::wouldGenerate(), ['DNB_APP_PASS'],
    'and exactly the unset one is named — checked BEFORE anything is altered');
foreach ($saved as $env => $v) { putenv($v === false ? $env : "{$env}={$v}"); }

$a = Credentials::generate();
$b = Credentials::generate();
is_(strlen($a), 64, 'a generated secret is 32 bytes, hex');
is_($a === $b, false, 'two generations differ');
is_(preg_match('/^[0-9a-f]+$/', $a), 1, 'and it is hex, so it survives an env file');

// The secrets file must be unreadable by anyone else BEFORE it holds anything.
$tmp = sys_get_temp_dir() . '/dnb-secrets-' . bin2hex(random_bytes(6)) . '.env';
Credentials::writeSecretsFile($tmp, ['DNB_APP_PASS' => 'value-under-test']);
is_(substr(sprintf('%o', fileperms($tmp)), -4), '0600', 'the secrets file is created 0600');
$body = (string) file_get_contents($tmp);
is_(str_contains($body, 'DNB_APP_PASS="value-under-test"'), true,
    'and holds what it was given, quoted for the shell that will source it');
is_(str_contains($body, 'Keep the mode at 0600'), true, 'and says so to whoever opens it');
unlink($tmp);
Credentials::writeSecretsFile($tmp, []);
is_(file_exists($tmp), false, 'nothing is written when there is nothing to write');

is_(count(Credentials::ROLE_ENV), 7, 'seven login roles are provisioned (migration 026 added dnb_staffauth)');
is_(count(Credentials::APP_SECRETS), 2, 'plus two application secrets that never reach the database');
foreach (array_keys(Credentials::APP_SECRETS) as $env) {
    is_(in_array($env, array_values(Credentials::ROLE_ENV), true), false,
        "{$env} is not a role password");
}

// The installer must not be able to alter a role it does not own the name of.
$src = (string) file_get_contents($root . '/src/Plugin/Credentials.php');
is_(str_contains($src, 'refusing to alter an unknown role'), true,
    'apply() refuses a role name outside its own constant');
is_(preg_match('/ALTER ROLE \' \. \$role \. \' PASSWORD \' \. \$quoted/', $src), 1,
    'and the value is quoted by the driver, never interpolated raw');
// Judged on CODE, not on prose. The first version of this check matched the
// word "prints" in this class's own docblock — the seventh time in this project
// that a guard has failed on its author's explanation rather than on the thing
// it guards.
$codeOnly = '';
foreach (token_get_all($src) as $tk) {
    if (is_array($tk) && in_array($tk[0], [T_COMMENT, T_DOC_COMMENT], true)) { continue; }
    $codeOnly .= is_array($tk) ? $tk[1] : $tk;
}
is_(str_contains($codeOnly, 'prints'), false,
    'the comment stripper works — the docblock word is gone from the code view');
$writes = [];
foreach (['echo ', 'print ', 'print_r', 'var_dump', 'error_log', 'printf', 'fputs(STDOUT', 'STDERR'] as $fn) {
    if (str_contains($codeOnly, $fn)) { $writes[] = trim($fn); }
}
is_($writes, [], 'no code in the credential path writes to output'
    . ($writes ? ' — FOUND: ' . implode(', ', $writes) : ''));

// ───────────────────────────────────────────────────────────────────────────
t('every value a shell will source is quoted');

// A DSN contains semicolons. `set -a; . file; set +a` — which INSTALL.md tells
// the engineer to run — parses an unquoted one as three commands and leaves
// DNB_DSN holding only the first fragment. Measured: the installer then tried
// to reach a database called "dnb" that did not exist.
$tpl = (string) file_get_contents($root . '/plugin/.env.example');
preg_match_all('/^(DNB_[A-Z_]+)=(.*)$/m', $tpl, $mm, PREG_SET_ORDER);
$unquoted = [];
foreach ($mm as $row) {
    $v = trim($row[2]);
    if ($v === '' || $v[0] === '"' || $v[0] === "'") { continue; }
    if (str_contains($v, ';') || str_contains($v, ' ') || str_contains($v, '&')) {
        $unquoted[] = $row[1];
    }
}
is_($unquoted, [], 'no value needing quotes is left bare in .env.example'
    . ($unquoted ? ' — ' . implode(', ', $unquoted) : ''));
is_(preg_match('/^DNB_DSN="/m', $tpl), 1, 'and the DSN in particular is quoted');
is_(str_contains((string) file_get_contents($root . '/src/Plugin/Credentials.php'),
    '\'="\' . $v . \'"\''), true,
    'the generated secrets file quotes its values too');

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
foreach (['INSTALL.md', 'UNINSTALL.md'] as $doc) {
    is_(is_file($root . '/plugin/doc/' . $doc), true, "plugin/doc/{$doc} ships with the package");
}
is_($m->version, '0.1.0-rc1', 'the manifest carries the release-candidate version');

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
    'the simulator builds 5 routers'   => 'the simulated estate is the expected size',
    'the simulator builds 17 vouchers' => 'the voucher count is checked, not assumed',
    'install creates the 15 plugin roles' => 'the positive control for the residue check',
    'sign-in is 501 under the production binding' => 'the production posture is checked over the wire',
    'the real provider refuses a session over plain HTTP' => 'the migration 026 provider is exercised, and refuses plain HTTP',
    'the real provider signs the first administrator in over the wire' => 'and signs somebody in when TLS is asserted by the trusted proxy',
    'signing out revokes the session on the server' => 'and logout is a revocation, not a cookie clear',
    'no plugin role remains'           => 'uninstall leaves no role',
    'no mt_ table remains'             => 'uninstall leaves no table',
    'no source, config, secret or manifest file is reachable' => 'the source-disclosure regression',
    'B-1: no burned credential authenticates' => 'the burned credentials are dead',
    'cluster rejects a wrong password'        => 'the control that makes the credential checks mean anything',
    'a second install from the artifact alone succeeds' => 'the artifact is self-sufficient',
];
foreach ($proofs as $needle => $what) {
    is_(str_contains($it, $needle), true, "it checks {$what}");
}
is_(str_contains($it, 'initdb'), true, 'it builds its own cluster rather than using an existing one');
is_(str_contains($it, "listen_addresses=''"), true, 'and that cluster opens no TCP port');

// ───────────────────────────────────────────────────────────────────────────
t('the manifest still describes an estate-read plugin with exactly seven gated estate writes: four router, three onboarding');

is_($m->consumesDomainA, false, 'it declares no Domain A dependency');
// G-C (docs/118) and 028 (docs/121): the four router writes; 030 (docs/125):
// operator, service and location creation. Nothing else is bound.
is_(array_map(static fn($r) => $r['method'] . ' ' . $r['path'], $m->writeRoutes),
    ['POST /routers', 'POST /routers/{device_id}/assign', 'POST /routers/{device_id}/state', 'POST /routers/{device_id}/actions',
     'POST /customers', 'POST /customers/{customer_id}/services', 'POST /sites'],
    'the only bound write routes are the four router routes (G-C + docs/121) and the three onboarding routes (docs/125)');
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
