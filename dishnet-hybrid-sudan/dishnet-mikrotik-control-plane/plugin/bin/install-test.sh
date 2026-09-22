#!/bin/sh
# Disposable installation test.
#
#   sh plugin/bin/install-test.sh [--keep]
#
# Installs this plugin into a PostgreSQL cluster it creates itself, exercises
# the whole path, uninstalls, checks that nothing is left behind, and then
# installs a second time from the release artifact to prove the artifact alone
# is sufficient.
#
# WHY ITS OWN CLUSTER, and not just its own database:
#
#   PostgreSQL roles are cluster-wide. This plugin creates twelve of them, and
#   uninstall drops twelve of them. Run against a cluster that already serves
#   something else, a clean uninstall would remove roles that other databases
#   on that cluster may be using. A separate cluster is the only arrangement in
#   which "uninstall left nothing behind" is both testable and safe to test.
#
# WHY scram-sha-256:
#
#   The migrations create every login role with no password. Under a `trust`
#   entry that still lets anyone connect, so a credential test run against a
#   trusting cluster measures nothing — it reported six live credentials on a
#   database where none was set. This cluster requires a password, and the
#   first credential check is a deliberately wrong one.
#
# What it does NOT touch: any existing cluster, database, role, web server,
# container or reverse proxy. PostgreSQL is reachable only over a unix socket
# inside its own directory; the HTTP server binds 127.0.0.1.

set -eu

KEEP=0
if [ "${1:-}" = "--keep" ]; then KEEP=1; fi

root=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)
base=${DNB_ITEST_BASE:-/var/tmp/dnb-install-test-$$}
port=${DNB_ITEST_PORT:-8099}

pgbin=${DNB_PG_BIN:-}
if [ -z "$pgbin" ]; then
  for d in /usr/lib/postgresql/*/bin /usr/pgsql-*/bin /usr/local/pgsql/bin; do
    if [ -x "$d/initdb" ]; then pgbin=$d; fi
  done
fi
if [ -z "$pgbin" ] || [ ! -x "$pgbin/initdb" ]; then
  echo "cannot find initdb. Set DNB_PG_BIN to the PostgreSQL bin directory." >&2; exit 1
fi

as_pg() { if [ "$(id -u)" = 0 ]; then su postgres -c "$1"; else sh -c "$1"; fi; }

pass=0; fail=0
results=$base/results
check() { # check <name> <expected> <actual>
  if [ "$2" = "$3" ]; then pass=$((pass+1)); printf '  PASS  %-52s %s\n' "$1" "$3" >> "$results"
  else fail=$((fail+1)); printf '  FAIL  %-52s expected %s, got %s\n' "$1" "$2" "$3" >> "$results"; fi
}

cleanup() {
  if [ -n "${SRV:-}" ]; then kill "$SRV" 2>/dev/null || true; fi
  as_pg "$pgbin/pg_ctl -D $base/pg -m immediate stop" >/dev/null 2>&1 || true
  if [ "$KEEP" = 0 ]; then rm -rf "$base"; else echo "kept: $base"; fi
}
trap cleanup EXIT INT TERM

mkdir -p "$base/pg" "$base/run" "$base/app" "$base/app2"
if [ "$(id -u)" = 0 ]; then chown -R postgres:postgres "$base/pg" "$base/run"; fi
: > "$results"

echo "disposable installation test"
echo "  workspace   $base"
echo "  postgres    $pgbin"
echo

# ── 0. a pristine cluster that REQUIRES a password ──────────────────────────
superpass="itest-super-$(php -r 'echo bin2hex(random_bytes(12));')"
pwfile=$base/run/.initpw
printf '%s' "$superpass" > "$pwfile"
chmod 600 "$pwfile"
if [ "$(id -u)" = 0 ]; then chown postgres "$pwfile"; fi

as_pg "$pgbin/initdb -D $base/pg -U postgres --auth-local=scram-sha-256 \
       --auth-host=scram-sha-256 --pwfile=$pwfile" > "$base/initdb.log" 2>&1 \
  || { echo "initdb failed:" >&2; tail -5 "$base/initdb.log" >&2; exit 1; }
rm -f "$pwfile"

as_pg "$pgbin/pg_ctl -D $base/pg -o \"-c listen_addresses='' -k $base/run\" \
       -l $base/pg/startup.log -w start" > "$base/pgctl.log" 2>&1 \
  || { echo "pg_ctl start failed:" >&2; cat "$base/pgctl.log" >&2; exit 1; }
chmod 755 "$base" "$base/run"
export PGHOST=$base/run
export PGPASSWORD=$superpass

check "cluster starts with no dnb roles" 0 \
  "$(psql -U postgres -d postgres -Atc "SELECT count(*) FROM pg_roles WHERE rolname LIKE 'dnb%'")"
check "cluster opens no TCP port" "" \
  "$(psql -U postgres -d postgres -Atc "SELECT current_setting('listen_addresses')")"
# The control that makes every later credential check mean something.
check "cluster rejects a wrong password" "rejected" \
  "$(PGPASSWORD=wrong-$$ psql -U postgres -d postgres -Atc 'SELECT 1' >/dev/null 2>&1 \
     && echo accepted || echo rejected)"

# ── 1. build and extract the artifact ───────────────────────────────────────
sh "$root/plugin/bin/package.sh" "$base" > "$base/package.log" 2>&1
tarball=$(head -1 "$base/package.log")
tar -xzf "$tarball" -C "$base/app"
pkg=$base/app/$(basename "$tarball" .tar.gz)
check "artifact extracts" "yes" "$([ -f "$pkg/VERSION" ] && echo yes || echo no)"
check "artifact ships no tests/" 0 "$(find "$pkg" -name tests -type d | wc -l | tr -d ' ')"
check "artifact ships installation docs" 2 \
  "$(ls "$pkg/plugin/doc/INSTALL.md" "$pkg/plugin/doc/UNINSTALL.md" 2>/dev/null | wc -l | tr -d ' ')"
if ( cd "$pkg" && sha256sum -c SHA256SUMS --quiet 2>/dev/null ); then
  check "artifact matches its checksums" "yes" "yes"
else check "artifact matches its checksums" "yes" "no"; fi
# B-1 at the artifact level: no burned credential travels inside the package.
# The burned list is read from Doctor::DEV_PASSWORDS, never written out here.
# One place holds those strings; this file must not become a second.
burned_list=$(php -r 'require "src/autoload.php";
  echo implode(" ", \Dn\Plugin\Doctor::DEV_PASSWORDS);')
burned=0
for s in $burned_list; do
  n=$(grep -rl "$s" "$pkg/src" "$pkg/migrations" "$pkg/panel" "$pkg/bin" 2>/dev/null \
        | grep -v 'Doctor.php' | wc -l | tr -d ' ')
  burned=$((burned + n))
done
check "no burned credential in the packaged src/ or migrations/" 0 "$burned"
check "no password literal in the packaged migrations" 0 \
  "$(grep -c "LOGIN PASSWORD '" "$pkg"/migrations/*.sql 2>/dev/null | awk -F: '{s+=$2} END{print s+0}')"

cd "$pkg"
ownerpass="itest-$(php -r 'echo bin2hex(random_bytes(12));')"
export DNB_DSN="pgsql:host=$base/run;dbname=dnb_itest"
export DNB_OWNER_USER=dnb_itest_owner
export DNB_OWNER_PASS="$ownerpass"

# ── 2. preflight before anything exists ─────────────────────────────────────
export DNB_TOKEN_PEPPER="itest-$(php -r 'echo bin2hex(random_bytes(16));')"
php plugin/bin/plugin.php doctor --disposable > "$base/doctor-before.txt" 2>&1 || true
check "doctor reports blockers before bootstrap" "yes" \
  "$(grep -q 'BLOCK' "$base/doctor-before.txt" && echo yes || echo no)"
check "doctor does not claim a clean bill it cannot measure" "yes" \
  "$(grep -q 'NOT MEASURED' "$base/doctor-before.txt" && echo yes || echo no)"

# ── 3. bootstrap: the privileged step ───────────────────────────────────────
psql -U postgres -d postgres -v db=dnb_itest -v owner=dnb_itest_owner \
     -v owner_pass="$ownerpass" -f plugin/bin/bootstrap.sql > "$base/bootstrap.log" 2>&1
check "bootstrap creates the database" 1 \
  "$(psql -U postgres -d postgres -Atc "SELECT count(*) FROM pg_database WHERE datname='dnb_itest'")"
check "owner is not a superuser" "f" \
  "$(psql -U postgres -d postgres -Atc "SELECT rolsuper FROM pg_roles WHERE rolname='dnb_itest_owner'")"
check "owner does not bypass RLS" "f" \
  "$(psql -U postgres -d postgres -Atc "SELECT rolbypassrls FROM pg_roles WHERE rolname='dnb_itest_owner'")"

# ── 4. install refuses to leave a generated secret nowhere ──────────────────
php plugin/bin/plugin.php install > "$base/install-refused.log" 2>&1 || true
check "install refuses to generate secrets with nowhere to put them" "yes" \
  "$(grep -qi 'DNB_SECRETS_OUT names no file' "$base/install-refused.log" && echo yes || echo no)"

# ── 5. install, generating credentials ──────────────────────────────────────
export DNB_SECRETS_OUT=$base/secrets.env
php plugin/bin/plugin.php install > "$base/install.log" 2>&1
sqlcount=$(ls migrations/*.sql | wc -l | tr -d ' ')
check "install applies every migration" "$sqlcount" \
  "$(psql -U postgres -d dnb_itest -Atc 'SELECT count(*) FROM mt_migrations')"
check "install creates the 12 plugin roles" 12 \
  "$(psql -U postgres -d postgres -Atc "SELECT count(*) FROM pg_roles WHERE rolname LIKE 'dnb\_%' AND rolname <> 'dnb_itest_owner'")"
check "the secrets file exists" "yes" "$([ -f "$DNB_SECRETS_OUT" ] && echo yes || echo no)"
check "the secrets file is 0600" "600" \
  "$(stat -c '%a' "$DNB_SECRETS_OUT" 2>/dev/null || stat -f '%OLp' "$DNB_SECRETS_OUT")"
check "no secret was printed by the installer" 0 \
  "$(grep -cE '^[A-Z_]+=[0-9a-f]{32,}' "$base/install.log" || true)"
check "the installer said where it put them, not what they are" "yes" \
  "$(grep -q 'values not shown' "$base/install.log" && echo yes || echo no)"
check "it generated a credential for every login role" 6 \
  "$(grep -cE '^DNB_(APP|WORKER|ADMIN|ADMINAPI|ADMINWRITE|RADIUS)_PASS=' "$DNB_SECRETS_OUT")"

set -a; . "$DNB_SECRETS_OUT"; set +a

# ── 6. credential security ──────────────────────────────────────────────────
livedev=0
pairs=$(php -r 'require "src/autoload.php";
  foreach (\Dn\Plugin\Doctor::DEV_PASSWORDS as $r => $p) { echo "$r:$p "; }')
for pair in $pairs; do
  r=${pair%%:*}; p=${pair##*:}
  if PGPASSWORD="$p" psql -U "$r" -d dnb_itest -Atc 'SELECT 1' >/dev/null 2>&1; then
    livedev=$((livedev+1))
  fi
done
check "B-1: no burned credential authenticates" 0 "$livedev"
check "B-1: the generated credential does authenticate" "yes" \
  "$(PGPASSWORD="$DNB_APP_PASS" psql -U dnb_app -d dnb_itest -Atc 'SELECT 1' >/dev/null 2>&1 \
     && echo yes || echo no)"
check "one role's password does not open another" "no" \
  "$(PGPASSWORD="$DNB_APP_PASS" psql -U dnb_adminapi -d dnb_itest -Atc 'SELECT 1' >/dev/null 2>&1 \
     && echo yes || echo no)"
php plugin/bin/plugin.php doctor --disposable > "$base/doctor-after.txt" 2>&1 || true
check "doctor finds the burned credentials dead" "yes" \
  "$(grep -qE 'ok .*burned credentials +dead' "$base/doctor-after.txt" && echo yes || echo no)"
check "doctor confirms the cluster requires a password" "yes" \
  "$(grep -qE 'ok .*cluster requires a password +yes' "$base/doctor-after.txt" && echo yes || echo no)"
check "doctor passes overall" 0 \
  "$(php plugin/bin/plugin.php doctor --disposable >/dev/null 2>&1 && echo 0 || echo 1)"

# ── 7. role security ────────────────────────────────────────────────────────
q() { psql -U postgres -d postgres -Atc "$1"; }
check "no plugin role is a superuser" 0 \
  "$(q "SELECT count(*) FROM pg_roles WHERE rolname LIKE 'dnb\_%' AND rolsuper")"
check "no plugin role bypasses RLS" 0 \
  "$(q "SELECT count(*) FROM pg_roles WHERE rolname LIKE 'dnb\_%' AND rolbypassrls")"
# The owner is excluded on purpose: bootstrap.sql gives it CREATEROLE and
# CREATEDB, which is how the migrations can create roles at all.
check "no plugin role may create roles or databases" 0 \
  "$(q "SELECT count(*) FROM pg_roles WHERE rolname LIKE 'dnb\_%' AND rolname <> 'dnb_itest_owner' AND (rolcreaterole OR rolcreatedb)")"
check "the six definer roles cannot log in" 6 \
  "$(q "SELECT count(*) FROM pg_roles WHERE rolname LIKE 'dnb\_def\_%' AND NOT rolcanlogin")"
check "the six application roles can" 6 \
  "$(q "SELECT count(*) FROM pg_roles WHERE rolname IN ('dnb_app','dnb_worker','dnb_admin','dnb_adminapi','dnb_adminwrite','dnb_radius') AND rolcanlogin")"
# No plugin role may be a member of another — with the OWNER excluded as the
# member, which migration 017 §1 explains and which is not an escalation:
# PostgreSQL requires membership in a role before it will hand that role
# ownership of an object, so the owner is granted each definer role
# WITH INHERIT FALSE, and therefore carries none of their privileges while
# acting as itself. The first version of this check filtered the owner out as
# the GROUP rather than the MEMBER and so matched all 18 rows.
check "no plugin role is a member of another" 0 \
  "$(q "SELECT count(*) FROM pg_auth_members m JOIN pg_roles r ON r.oid=m.member JOIN pg_roles g ON g.oid=m.roleid WHERE r.rolname LIKE 'dnb\_%' AND g.rolname LIKE 'dnb\_%' AND r.rolname <> 'dnb_itest_owner'")"
# Positive control: the memberships the migration DOES make are present, so the
# query above is filtering something rather than matching an empty table.
# DISTINCT, because PostgreSQL 16 records one row per grantor and these roles
# carry two: the migration's explicit GRANT and the creator's automatic one.
# Counting rows here expected 6 and got 12 — the number was an artefact of the
# catalogue, not of the privilege.
check "the owner is a member of the six definer roles" 6 \
  "$(q "SELECT count(DISTINCT g.rolname) FROM pg_auth_members m JOIN pg_roles r ON r.oid=m.member JOIN pg_roles g ON g.oid=m.roleid WHERE r.rolname = 'dnb_itest_owner' AND g.rolname LIKE 'dnb\_def\_%'")"
# The security-relevant half: not one of those memberships inherits.
check "and not one of those memberships inherits" 0 \
  "$(q "SELECT count(*) FROM pg_auth_members m JOIN pg_roles r ON r.oid=m.member JOIN pg_roles g ON g.oid=m.roleid WHERE r.rolname = 'dnb_itest_owner' AND g.rolname LIKE 'dnb\_def\_%' AND m.inherit_option")"
check "the Admin read role holds no table privilege" 0 \
  "$(psql -U postgres -d dnb_itest -Atc "SELECT count(*) FROM information_schema.table_privileges WHERE grantee='dnb_adminapi'")"
check "the Admin write role holds no table privilege" 0 \
  "$(psql -U postgres -d dnb_itest -Atc "SELECT count(*) FROM information_schema.table_privileges WHERE grantee='dnb_adminwrite'")"
check "the RADIUS role holds no table privilege" 0 \
  "$(psql -U postgres -d dnb_itest -Atc "SELECT count(*) FROM information_schema.table_privileges WHERE grantee='dnb_radius'")"
check "every tenant table FORCEs row level security" 0 \
  "$(psql -U postgres -d dnb_itest -Atc "SELECT count(*) FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname='public' AND c.relname LIKE 'mt\_%' AND c.relrowsecurity AND NOT c.relforcerowsecurity")"

# ── 8. serve, under the PRODUCTION identity binding ─────────────────────────
php -S 127.0.0.1:"$port" plugin/bin/serve.php > "$base/serve1.log" 2>&1 &
SRV=$!; sleep 2
u=http://127.0.0.1:$port
check "panel loads" 200 "$(curl -s -o /dev/null -w '%{http_code}' "$u/")"
check "API admits nobody by default" 401 "$(curl -s -o /dev/null -w '%{http_code}' "$u/api/v1/admin/health")"

# Source disclosure: representative files of every kind the package contains.
leaked=0
for target in \
    "../src/Db/Database.php" \
    "../src/Plugin/Credentials.php" \
    "../migrations/001_roles_and_extensions.sql" \
    "../plugin/plugin.json" \
    "../plugin/.env.example" \
    "../VERSION" \
    "../SHA256SUMS" \
    "../../secrets.env" \
    "../bin/worker.php" \
    "../plugin/bin/bootstrap.sql" ; do
  code=$(curl -s -o /dev/null -w '%{http_code}' --path-as-is "$u/$target")
  if [ "$code" = "200" ]; then leaked=$((leaked+1)); echo "LEAKED $target" >> "$base/leaks.txt"; fi
done
check "no source, config, secret or manifest file is reachable" 0 "$leaked"
check "panel files themselves still serve" 200 "$(curl -s -o /dev/null -w '%{http_code}' "$u/app.js")"
kill $SRV 2>/dev/null || true; wait $SRV 2>/dev/null || true; SRV=

# ── 9. serve, with the development identity ─────────────────────────────────
export DN_DEV_STAFF_IDENTITY=yes-development-only
php -S 127.0.0.1:"$port" plugin/bin/serve.php > "$base/serve2.log" 2>&1 &
SRV=$!; sleep 2
check "API health answers" 200 "$(curl -s -o /dev/null -w '%{http_code}' "$u/api/v1/admin/health")"
check "estate is empty before the simulator runs" 0 \
  "$(curl -s "$u/api/v1/admin/routers" | php -r '$j=json_decode(stream_get_contents(STDIN),true);echo count($j["router"]??[]);')"

# ── 10. simulator ───────────────────────────────────────────────────────────
php plugin/bin/plugin.php simulate > "$base/simulate.log" 2>&1
count() { curl -s "$u/api/v1/admin/$2" | php -r '$j=json_decode(stream_get_contents(STDIN),true);echo count($j[$argv[1]]??[]);' "$1"; }
check "the simulator builds 3 customers"  3  "$(count customer customers)"
check "the simulator builds 5 routers"    5  "$(count router routers)"
check "the simulator builds 17 vouchers"  17 "$(count voucher vouchers)"
check "the simulator builds 6 sessions"   6  "$(count session sessions)"
check "every simulated serial carries the SIM- mark" 0 \
  "$(curl -s "$u/api/v1/admin/routers" | php -r '
     $rs=json_decode(stream_get_contents(STDIN),true)["router"]??[];
     echo count(array_filter($rs, fn($x)=>!str_starts_with((string)($x["serial"]??""),"SIM-")));')"
check "the simulator refuses alongside real bindings" "yes" \
  "$(DN_ALLOW_REAL_BINDINGS=yes-f6b-authorized php plugin/bin/plugin.php simulate --again 2>&1 \
     | grep -q 'Refusing' && echo yes || echo no)"
kill $SRV 2>/dev/null || true; wait $SRV 2>/dev/null || true; SRV=
unset DN_DEV_STAFF_IDENTITY

# ── 11. re-install is safe ──────────────────────────────────────────────────
php plugin/bin/plugin.php install > "$base/reinstall.log" 2>&1
check "re-install applies no migration" "yes" \
  "$(grep -q 'already current' "$base/reinstall.log" && echo yes || echo no)"
check "re-install keeps the working credential" "yes" \
  "$(PGPASSWORD="$DNB_APP_PASS" psql -U dnb_app -d dnb_itest -Atc 'SELECT 1' >/dev/null 2>&1 \
     && echo yes || echo no)"
check "the estate survived the re-install" 5 \
  "$(psql -U postgres -d dnb_itest -Atc 'SELECT count(*) FROM mt_devices')"

# ── 12. uninstall ───────────────────────────────────────────────────────────
php plugin/bin/plugin.php uninstall > "$base/uninstall-refused.log" 2>&1 || true
check "uninstall refuses without confirmation" "yes" \
  "$(grep -q 'Refusing' "$base/uninstall-refused.log" && echo yes || echo no)"
php plugin/bin/plugin.php uninstall --i-understand-this-drops-data > "$base/uninstall.log" 2>&1

check "no mt_ table remains" 0 \
  "$(psql -U postgres -d dnb_itest -Atc "SELECT count(*) FROM pg_tables WHERE schemaname='public' AND tablename LIKE 'mt\_%'")"
check "no mt_ function remains" 0 \
  "$(psql -U postgres -d dnb_itest -Atc "SELECT count(*) FROM pg_proc p JOIN pg_namespace n ON n.oid=p.pronamespace WHERE n.nspname='public' AND p.proname LIKE 'mt\_%'")"
check "no plugin role remains on the cluster" 0 \
  "$(q "SELECT count(*) FROM pg_roles WHERE rolname LIKE 'dnb\_%' AND rolname <> 'dnb_itest_owner'")"
check "no schema grant remains for a plugin role" 0 \
  "$(psql -U postgres -d dnb_itest -Atc "SELECT count(*) FROM information_schema.role_table_grants WHERE grantee LIKE 'dnb\_%' AND grantee <> 'dnb_itest_owner'")"
check "the database itself survives (the plugin did not create it)" 1 \
  "$(q "SELECT count(*) FROM pg_database WHERE datname='dnb_itest'")"
check "the owner role survives (bootstrap created it, not install)" 1 \
  "$(q "SELECT count(*) FROM pg_roles WHERE rolname='dnb_itest_owner'")"
check "nothing unrelated was touched" 1 \
  "$(q "SELECT count(*) FROM pg_database WHERE datname='postgres'")"

# ── 13. a SECOND installation, from the artifact alone ──────────────────────
# Proves the release artifact is self-sufficient: a fresh unpack, a fresh
# database, a fresh set of credentials, with the first installation gone.
tar -xzf "$tarball" -C "$base/app2"
pkg2=$base/app2/$(basename "$tarball" .tar.gz)
cd "$pkg2"
owner2="itest2-$(php -r 'echo bin2hex(random_bytes(12));')"
psql -U postgres -d postgres -v db=dnb_itest2 -v owner=dnb_itest2_owner \
     -v owner_pass="$owner2" -f plugin/bin/bootstrap.sql > "$base/bootstrap2.log" 2>&1
env -u DNB_APP_PASS -u DNB_WORKER_PASS -u DNB_ADMIN_PASS -u DNB_ADMINAPI_PASS \
    -u DNB_ADMINWRITE_PASS -u DNB_RADIUS_PASS -u DNB_SECRET_KEY \
    DNB_DSN="pgsql:host=$base/run;dbname=dnb_itest2" \
    DNB_OWNER_USER=dnb_itest2_owner DNB_OWNER_PASS="$owner2" \
    DNB_TOKEN_PEPPER="$DNB_TOKEN_PEPPER" DNB_SECRETS_OUT="$base/secrets2.env" \
    php plugin/bin/plugin.php install > "$base/install2.log" 2>&1
check "a second install from the artifact alone succeeds" "$sqlcount" \
  "$(psql -U postgres -d dnb_itest2 -Atc 'SELECT count(*) FROM mt_migrations')"
check "it minted a different credential set" "different" \
  "$(if [ "$(grep '^DNB_APP_PASS=' "$base/secrets.env")" = "$(grep '^DNB_APP_PASS=' "$base/secrets2.env")" ]; \
     then echo same; else echo different; fi)"
# Sourced, not sed'd: the values are shell-quoted, so a raw extraction hands
# psql a password with the quotes still attached.
check "the second installation's credential works" "yes" \
  "$( ( set -a; . "$base/secrets2.env"; set +a
        PGPASSWORD="$DNB_APP_PASS" psql -U dnb_app -d dnb_itest2 -Atc 'SELECT 1' >/dev/null 2>&1 \
          && echo yes || echo no ) )"
# And the file is genuinely sourceable — the defect that made this fail in the
# first place was a value a shell could not read back.
check "the secrets file survives being sourced" "yes" \
  "$( ( set -a; . "$base/secrets2.env"; set +a
        [ -n "${DNB_APP_PASS:-}" ] && [ -n "${DNB_TOKEN_PEPPER:-}" ] && echo yes || echo no ) )"

cat "$results"
printf '\n  %d passed, %d failed\n\n' "$pass" "$fail"
if [ -f "$base/leaks.txt" ]; then echo "  LEAKS:"; sed 's/^/    /' "$base/leaks.txt"; fi
if [ "$fail" != 0 ]; then exit 1; fi
