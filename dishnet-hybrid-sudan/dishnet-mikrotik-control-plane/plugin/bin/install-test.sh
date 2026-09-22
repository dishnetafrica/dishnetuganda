#!/bin/sh
# Disposable installation test.
#
#   sh plugin/bin/install-test.sh [--keep]
#
# Installs this plugin into a PostgreSQL cluster it creates itself, exercises
# the whole path, uninstalls, and then checks that nothing is left behind.
#
# WHY ITS OWN CLUSTER, and not just its own database:
#
#   PostgreSQL roles are cluster-wide. This plugin creates twelve of them, and
#   uninstall drops twelve of them. Run against a cluster that already serves
#   something else, a clean uninstall would remove roles that other databases
#   on that cluster may be using. A separate cluster is the only arrangement in
#   which "uninstall left nothing behind" is both testable and safe to test.
#
# What it does NOT touch: any existing cluster, any existing database, any
# existing role, any web server, any container, any reverse proxy. It opens no
# TCP port for PostgreSQL — the test cluster is reachable only over a unix
# socket inside its own directory — and binds the HTTP server to 127.0.0.1.

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
[ -n "$pgbin" ] && [ -x "$pgbin/initdb" ] \
  || { echo "cannot find initdb. Set DNB_PG_BIN to the PostgreSQL bin directory." >&2; exit 1; }

# initdb refuses to run as root; if we are root, do the cluster work as postgres.
as_pg() { if [ "$(id -u)" = 0 ]; then su postgres -c "$1"; else sh -c "$1"; fi; }

pass=0; fail=0
results=$base/results
check() { # check <name> <expected> <actual>
  if [ "$2" = "$3" ]; then pass=$((pass+1)); printf '  PASS  %-46s %s\n' "$1" "$3" >> "$results"
  else fail=$((fail+1)); printf '  FAIL  %-46s expected %s, got %s\n' "$1" "$2" "$3" >> "$results"; fi
}

cleanup() {
  if [ -n "${SRV:-}" ]; then kill "$SRV" 2>/dev/null || true; fi
  as_pg "$pgbin/pg_ctl -D $base/pg -m immediate stop" >/dev/null 2>&1 || true
  if [ "$KEEP" = 0 ]; then rm -rf "$base"; else echo "kept: $base"; fi
}
trap cleanup EXIT INT TERM

mkdir -p "$base/pg" "$base/run" "$base/app"
if [ "$(id -u)" = 0 ]; then chown -R postgres:postgres "$base/pg" "$base/run"; fi
: > "$results"

echo "disposable installation test"
echo "  workspace   $base"
echo "  postgres    $pgbin"
echo

# ── 0. a pristine cluster, unix socket only ─────────────────────────────────
as_pg "$pgbin/initdb -D $base/pg -U postgres --auth-local=trust" > "$base/initdb.log" 2>&1 \
  || { echo "initdb failed:" >&2; tail -5 "$base/initdb.log" >&2; exit 1; }
# The log goes INSIDE the data directory: pg_ctl creates it as the postgres
# user, and the workspace above belongs to whoever ran this script.
as_pg "$pgbin/pg_ctl -D $base/pg -o \"-c listen_addresses='' -k $base/run\" -l $base/pg/startup.log -w start" \
  > "$base/pgctl.log" 2>&1 \
  || { echo "pg_ctl start failed:" >&2; cat "$base/pgctl.log" >&2; exit 1; }
sleep 1
chmod 755 "$base" "$base/run"
export PGHOST=$base/run
check "cluster starts with no dnb roles" 0 \
  "$(psql -U postgres -d postgres -Atc "SELECT count(*) FROM pg_roles WHERE rolname LIKE 'dnb%'")"
check "cluster opens no TCP port" "" \
  "$(psql -U postgres -d postgres -Atc "SELECT current_setting('listen_addresses')")"

# ── 1. build and extract the artifact ───────────────────────────────────────
sh "$root/plugin/bin/package.sh" "$base" > "$base/package.log" 2>&1
tarball=$(head -1 "$base/package.log")
tar -xzf "$tarball" -C "$base/app"
pkg=$base/app/$(basename "$tarball" .tar.gz)
check "artifact extracts" "yes" "$([ -f "$pkg/VERSION" ] && echo yes || echo no)"
check "artifact ships no tests/" 0 "$(find "$pkg" -name tests -type d | wc -l | tr -d ' ')"
( cd "$pkg" && sha256sum -c SHA256SUMS --quiet 2>/dev/null ) \
  && check "artifact matches its checksums" "yes" "yes" \
  || check "artifact matches its checksums" "yes" "no"

cd "$pkg"
ownerpass="itest-$(php -r 'echo bin2hex(random_bytes(12));')"
export DNB_DSN="pgsql:host=$base/run;dbname=dnb_itest"
export DNB_OWNER_USER=dnb_itest_owner
export DNB_OWNER_PASS="$ownerpass"
export DNB_TOKEN_PEPPER="itest-$(php -r 'echo bin2hex(random_bytes(16));')"

# ── 2. preflight before anything exists ─────────────────────────────────────
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

# ── 4. install ──────────────────────────────────────────────────────────────
php plugin/bin/plugin.php install > "$base/install.log" 2>&1
sqlcount=$(ls migrations/*.sql | wc -l | tr -d ' ')
check "install applies every migration" "$sqlcount" \
  "$(psql -U postgres -d dnb_itest -Atc 'SELECT count(*) FROM mt_migrations')"
check "install is idempotent" "yes" \
  "$(php plugin/bin/plugin.php install 2>&1 | grep -q 'already current' && echo yes || echo no)"
# Positive control. Without it, "no plugin role remains" after uninstall would
# pass just as well on a cluster where install had created no role at all —
# a control that cannot fail is not a control.
check "install creates the 12 plugin roles" 12 \
  "$(psql -U postgres -d postgres -Atc "SELECT count(*) FROM pg_roles WHERE rolname LIKE 'dnb\_%' AND rolname <> 'dnb_itest_owner'")"
check "status reports the schema" "yes" \
  "$(php plugin/bin/plugin.php status 2>&1 | grep -q 'schema installed *yes' && echo yes || echo no)"

# ── 5. preflight after install ──────────────────────────────────────────────
php plugin/bin/plugin.php doctor --disposable > "$base/doctor-after.txt" 2>&1 || true
check "doctor detects the development passwords" "yes" \
  "$(grep -q 'BLOCK  development passwords' "$base/doctor-after.txt" && echo yes || echo no)"

# ── 6. serve, under the PRODUCTION identity binding ─────────────────────────
php -S 127.0.0.1:"$port" plugin/bin/serve.php > "$base/serve1.log" 2>&1 &
SRV=$!; sleep 2
u=http://127.0.0.1:$port
check "panel loads" 200 "$(curl -s -o /dev/null -w '%{http_code}' "$u/")"
check "API admits nobody by default" 401 "$(curl -s -o /dev/null -w '%{http_code}' "$u/api/v1/admin/health")"
check "static server refuses path traversal" 404 \
  "$(curl -s -o /dev/null -w '%{http_code}' --path-as-is "$u/../src/Db/Database.php")"
kill $SRV 2>/dev/null || true; wait $SRV 2>/dev/null || true; SRV=

# ── 7. serve, with the development identity ─────────────────────────────────
export DN_DEV_STAFF_IDENTITY=yes-development-only
php -S 127.0.0.1:"$port" plugin/bin/serve.php > "$base/serve2.log" 2>&1 &
SRV=$!; sleep 2
check "API health answers" 200 "$(curl -s -o /dev/null -w '%{http_code}' "$u/api/v1/admin/health")"
check "estate is empty before the simulator runs" 0 \
  "$(curl -s "$u/api/v1/admin/routers" | php -r '$j=json_decode(stream_get_contents(STDIN),true);echo count($j["router"]??[]);')"

# ── 8. simulator ────────────────────────────────────────────────────────────
php plugin/bin/plugin.php simulate > "$base/simulate.log" 2>&1
for pair in customer:customers router:routers voucher:vouchers session:sessions; do
  k=${pair%%:*}; r=${pair##*:}
  n=$(curl -s "$u/api/v1/admin/$r" | php -r '$j=json_decode(stream_get_contents(STDIN),true);echo count($j[$argv[1]]??[]);' "$k")
  if [ "$n" -gt 0 ]; then check "simulated $k visible through the API" "some" "some"
  else check "simulated $k visible through the API" "some" "none"; fi
done
unmarked=$(curl -s "$u/api/v1/admin/routers" | php -r '
  $rs=json_decode(stream_get_contents(STDIN),true)["router"]??[];
  echo count(array_filter($rs, fn($x)=>!str_starts_with((string)($x["serial"]??""),"SIM-")));')
check "every simulated serial carries the SIM- mark" 0 "$unmarked"
check "the simulator refuses alongside real bindings" "yes" \
  "$(DN_ALLOW_REAL_BINDINGS=yes-f6b-authorized php plugin/bin/plugin.php simulate --again 2>&1 \
     | grep -q 'Refusing' && echo yes || echo no)"
kill $SRV 2>/dev/null || true; wait $SRV 2>/dev/null || true; SRV=
unset DN_DEV_STAFF_IDENTITY

# ── 9. uninstall ────────────────────────────────────────────────────────────
php plugin/bin/plugin.php uninstall > "$base/uninstall-refused.log" 2>&1 || true
check "uninstall refuses without confirmation" "yes" \
  "$(grep -q 'Refusing' "$base/uninstall-refused.log" && echo yes || echo no)"
php plugin/bin/plugin.php uninstall --i-understand-this-drops-data > "$base/uninstall.log" 2>&1

# ── 10. residue ─────────────────────────────────────────────────────────────
check "no mt_ table remains" 0 \
  "$(psql -U postgres -d dnb_itest -Atc "SELECT count(*) FROM pg_tables WHERE schemaname='public' AND tablename LIKE 'mt\_%'")"
check "no mt_ function remains" 0 \
  "$(psql -U postgres -d dnb_itest -Atc "SELECT count(*) FROM pg_proc p JOIN pg_namespace n ON n.oid=p.pronamespace WHERE n.nspname='public' AND p.proname LIKE 'mt\_%'")"
check "no plugin role remains on the cluster" 0 \
  "$(psql -U postgres -d postgres -Atc "SELECT count(*) FROM pg_roles WHERE rolname LIKE 'dnb\_%' AND rolname <> 'dnb_itest_owner'")"
check "the database itself survives (the plugin did not create it)" 1 \
  "$(psql -U postgres -d postgres -Atc "SELECT count(*) FROM pg_database WHERE datname='dnb_itest'")"
check "the owner role survives (bootstrap created it, not install)" 1 \
  "$(psql -U postgres -d postgres -Atc "SELECT count(*) FROM pg_roles WHERE rolname='dnb_itest_owner'")"

cat "$results"
printf '\n  %d passed, %d failed\n\n' "$pass" "$fail"
if [ "$fail" != 0 ]; then exit 1; fi
