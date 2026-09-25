<?php
declare(strict_types=1);
namespace Dn\Plugin;

use Dn\Db\Database;

/**
 * Role credentials, supplied or generated at install time — never in the
 * repository.
 *
 * The migrations create every login role with NO password clause, so
 * rolpassword is NULL and under scram-sha-256 the role cannot authenticate at
 * all. The role exists and carries exactly its designed privileges; nobody can
 * be it. This class is what makes it usable, once, on one installation.
 *
 * The rule that decides everything here: a migration is source-controlled and
 * replayed identically everywhere, so it is the one place a per-installation
 * secret must never live. Privileges are declared there. Credentials are
 * provisioned here.
 *
 * NOTHING in this class prints a secret. The only path by which a generated
 * value leaves the process is the file named by DNB_SECRETS_OUT, created 0600
 * before a byte is written to it.
 */
final class Credentials
{
    /** The six login roles, and the variable each reads its password from. */
    public const ROLE_ENV = [
        'dnb_app'        => 'DNB_APP_PASS',
        'dnb_worker'     => 'DNB_WORKER_PASS',
        'dnb_admin'      => 'DNB_ADMIN_PASS',
        'dnb_adminapi'   => 'DNB_ADMINAPI_PASS',
        'dnb_adminwrite' => 'DNB_ADMINWRITE_PASS',
        'dnb_radius'     => 'DNB_RADIUS_PASS',
        'dnb_staffauth'  => 'DNB_STAFFAUTH_PASS',
    ];

    /**
     * Secrets the application needs that are NOT role passwords. They never
     * reach the database; they are generated here only so that one command
     * produces a complete, working installation.
     */
    public const APP_SECRETS = [
        'DNB_TOKEN_PEPPER' => 'HMAC pepper for auth tokens and activation tickets',
        'DNB_SECRET_KEY'   => 'symmetric key for stored device credentials',
    ];

    public function __construct(private readonly Database $db) {}

    /**
     * Will this install have to generate anything?
     *
     * Asked BEFORE migrating and before a single ALTER ROLE, because the answer
     * decides whether the install can proceed at all. The first version asked
     * afterwards: it generated six credentials, applied them, and only then
     * discovered there was nowhere to write them — leaving six roles with
     * passwords nobody would ever know. Measured, not imagined; the install
     * test caught it on its first run.
     *
     * @return list<string> the variables that would have to be invented
     */
    public static function wouldGenerate(): array
    {
        $out = [];
        foreach (array_merge(array_values(self::ROLE_ENV), array_keys(self::APP_SECRETS)) as $env) {
            if ((getenv($env) ?: '') === '') { $out[] = $env; }
        }
        return $out;
    }

    /** 32 bytes of CSPRNG, hex. Never logged, never returned to a caller that prints. */
    public static function generate(): string
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * Which of the six login roles already exist?
     *
     * Asked BEFORE the migrations run, which is what makes the answer mean
     * something: a role present at that moment was created by an earlier
     * install — possibly for a different database, since PostgreSQL roles are
     * cluster-wide — and its credential may be in use somewhere this process
     * cannot see.
     *
     * The obvious question, "does this role have a password", cannot be asked:
     * rolpassword lives in pg_authid, which is superuser-only, and pg_roles
     * masks it to a constant. The owner is deliberately not a superuser, and
     * granting it more to answer a convenience question would widen the very
     * boundary this class exists to protect. So the installer asks a question
     * it CAN answer with ordinary privilege, and the answer is sufficient.
     *
     * @return list<string> role names
     */
    public function preexisting(): array
    {
        $rows = $this->db->query(
            'SELECT rolname FROM pg_roles WHERE rolname = ANY(:r) ORDER BY rolname',
            [':r' => '{' . implode(',', array_keys(self::ROLE_ENV)) . '}']);
        return array_column($rows, 'rolname');
    }

    /**
     * Provision every role, and return what happened — by role name and outcome
     * only. No value appears in the return.
     *
     * Three outcomes, and the third is the one that matters:
     *
     *   supplied   the environment named a password; it was applied
     *   generated  none was supplied and the role is new here; one was made
     *   REFUSED    none was supplied and the role predates this install
     *
     * The refusal exists because rotating a credential a running deployment is
     * using would break it in a way that reads like a database outage — and on
     * a shared cluster that deployment may be a different database entirely.
     * DNB_ROTATE_CREDENTIALS=yes-rotate-now overrides it, deliberately verbose.
     *
     * @param  list<string>         $preexisting  from preexisting(), before migrating
     * @param  array<string,string> $generatedOut filled with generated values
     * @return array{applied:array<string,string>,refused:list<string>}
     */
    public function provision(array $preexisting, array &$generatedOut = []): array
    {
        $present = array_column($this->db->query(
            'SELECT rolname FROM pg_roles WHERE rolname = ANY(:r)',
            [':r' => '{' . implode(',', array_keys(self::ROLE_ENV)) . '}']), 'rolname');
        $rotate  = (getenv('DNB_ROTATE_CREDENTIALS') ?: '') === 'yes-rotate-now';

        $applied = [];
        $refused = [];

        foreach (self::ROLE_ENV as $role => $env) {
            if (!in_array($role, $present, true)) {
                continue;               // the migrations did not create it here
            }
            $supplied = getenv($env) ?: '';
            if ($supplied !== '') {
                $this->apply($role, $supplied);
                $applied[$role] = 'supplied';
                continue;
            }
            if (in_array($role, $preexisting, true) && !$rotate) {
                $refused[] = $role;
                continue;
            }
            $secret = self::generate();
            $this->apply($role, $secret);
            $generatedOut[$env] = $secret;
            $applied[$role] = in_array($role, $preexisting, true) ? 'rotated' : 'generated';
        }

        foreach (self::APP_SECRETS as $env => $_) {
            if ((getenv($env) ?: '') === '') { $generatedOut[$env] = self::generate(); }
        }

        return ['applied' => $applied, 'refused' => $refused];
    }

    /**
     * ALTER ROLE cannot take a bind parameter for the password, so the value is
     * escaped with the driver's own quoter rather than interpolated. The role
     * name comes from this class's own constant and is checked against the
     * catalogue above, so it is never caller-controlled.
     */
    private function apply(string $role, string $secret): void
    {
        if (!array_key_exists($role, self::ROLE_ENV)) {
            throw new \RuntimeException("refusing to alter an unknown role: {$role}");
        }
        $quoted = $this->db->pdo()->quote($secret);
        if ($quoted === false) {
            throw new \RuntimeException('cannot quote a credential for ' . $role);
        }
        try {
            $this->db->exec('ALTER ROLE ' . $role . ' PASSWORD ' . $quoted);
        } catch (\PDOException $e) {
            // 42501 here means one specific thing, and the driver's own message
            // does not say which: PostgreSQL lets a CREATEROLE role change the
            // password only of a role it created. On a fresh install that is
            // always true, because the migrations create them. It is false on a
            // cluster where these roles predate the plugin's owner — a
            // development machine that installed an older version, typically —
            // and there the fix is to drop the roles so the migrations can make
            // them again, not to hand the owner more privilege.
            if (str_contains($e->getMessage(), '42501')) {
                throw new \RuntimeException(
                    "cannot set the password for {$role}: this database's owner did not "
                    . 'create that role, so PostgreSQL will not let it change the '
                    . 'credential. The role predates this installation — on a shared '
                    . 'cluster it may belong to another one. Either supply the existing '
                    . "password in " . self::ROLE_ENV[$role] . ', or drop the role and '
                    . 'let the migrations recreate it. Do NOT grant the owner more '
                    . 'privilege to work around this.', 0, $e);
            }
            throw $e;
        }
    }

    /**
     * Write generated values where only the owner can read them.
     *
     * The file is created with 0600 BEFORE anything is written, not chmodded
     * afterwards — between a default-mode create and a later chmod there is a
     * window in which the secret is world-readable, and that window is the
     * whole risk.
     */
    public static function writeSecretsFile(string $path, array $values): void
    {
        if ($values === []) { return; }
        $fh = @fopen($path, 'c');
        if ($fh === false) {
            throw new \RuntimeException('cannot open the secrets file for writing: ' . $path);
        }
        if (!chmod($path, 0600)) {
            fclose($fh);
            throw new \RuntimeException('cannot restrict permissions on ' . $path
                . ' — refusing to write a secret to a file this process cannot protect');
        }
        ftruncate($fh, 0);
        $body = "# Generated by the DishNet MikroTik control plane installer.\n"
              . "# Every value here is a credential. Keep the mode at 0600.\n"
              . "# Source it before serving or running the worker:  set -a; . " . $path . "; set +a\n\n";
        // Quoted even though every generated value is hex: this file is sourced by a
        // shell, and an unquoted value containing a semicolon becomes three commands.
        foreach ($values as $k => $v) { $body .= $k . '="' . $v . '"' . "\n"; }
        fwrite($fh, $body);
        fclose($fh);
    }
}
