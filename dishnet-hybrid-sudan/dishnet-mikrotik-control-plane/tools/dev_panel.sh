#!/usr/bin/env bash
# Rebuild the local simulator database and print how to serve the panel.
#
#   bash tools/dev_panel.sh
#
# Since docs/97 the roles have no default password, so a panel cannot simply be
# pointed at a database any more — it needs credentials. This provisions a
# development set into a file at mode 0600 and says how to use it. Development
# only; it is not part of the package.
set -euo pipefail
cd "$(dirname "$0")/.."

DB=${DNB_SIM_DB:-dnb_sim}
ENVF=${DNB_SIM_ENV:-/tmp/dnb-sim.env}
export PGHOST=${DNB_PGHOST:-/var/tmp} PGPORT=${DNB_PGPORT:-55432}
OWNER=${DNB_OWNER_USER:-dnb}

psql -U "$OWNER" -d postgres -q -c "DROP DATABASE IF EXISTS ${DB};"
psql -U "$OWNER" -d postgres -q -c "CREATE DATABASE ${DB};"

umask 077
{
  echo "DNB_DSN=\"pgsql:host=${PGHOST};port=${PGPORT};dbname=${DB}\""
  echo "DNB_OWNER_USER=${OWNER}"
  echo "DN_DEV_STAFF_IDENTITY=yes-development-only"
} > "$ENVF"

set -a; . "$ENVF"; set +a
# The roles are cluster-wide, so on a development machine they usually survive
# an earlier run and the installer rightly refuses to rotate them blind. Here
# that refusal is not what we want: this database is disposable and nothing else
# on this cluster is a real deployment. Saying so explicitly is the point of the
# flag — it is never set for you.
DNB_ROTATE_CREDENTIALS=yes-rotate-now \
DNB_SECRETS_OUT="$ENVF.secrets" php plugin/bin/plugin.php install
cat "$ENVF.secrets" >> "$ENVF"; rm -f "$ENVF.secrets"
set -a; . "$ENVF"; set +a
php plugin/bin/plugin.php simulate

echo
echo "serve the panel with:"
echo "  set -a; . ${ENVF}; set +a"
echo "  php -S 127.0.0.1:8081 tools/dev_server.php"
echo
echo "credentials live in ${ENVF} (mode $(stat -c '%a' "$ENVF")). Development only; never commit it."
