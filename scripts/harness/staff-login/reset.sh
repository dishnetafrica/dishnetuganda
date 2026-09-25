#!/bin/bash
# Rebuild, from nothing, the state stage 2 + the redeploy left on the staging
# server: the package at the reviewed digest, a database with the simulated
# estate, the stage-2 route file (taken VERBATIM from dnb-staging-stage2.sh),
# the development-identity API, and history in its log.
#   reset.sh <address the fake Traefik connects FROM>
set -euo pipefail
. "$(dirname "$0")/guard.sh"
S=${HSIM:?}/state; E=/opt/dnb-staging/env; DB=${HSIM_DB:-dnb_hsim}; IMG=dnb-staging-php:8.3; APP=/opt/dnb-staging
PGH=${HSIM_PGHOST:-/var/tmp}; PGP=${HSIM_PGPORT:-55432}; CP=$REPO/dishnet-hybrid-sudan/dishnet-mikrotik-control-plane
docker rm -f dnb-staging-api >/dev/null 2>&1 || true
rm -f "${S:?}"/api.* "${S:?}/failed-once" "${S:?}/traefik.dropxfp" "${S:?}/calls.log"
echo "${1:-127.0.0.1}" > "$S/traefik.src"
if [ ! -f /opt/dnb-staging/app/SHA256SUMS ] || [ -n "${HSIM_REBUILD:-}" ]; then
  rm -rf "${S:?}/dist" /opt/dnb-staging/app; mkdir -p "$S/dist" /opt/dnb-staging/app
  sh "$CP/plugin/bin/package.sh" "$S/dist" > "$S/package.out"
  tar -xzf "$S"/dist/*.tar.gz -C /opt/dnb-staging/app --strip-components=1
fi
(cd /opt/dnb-staging/app && sha256sum -c SHA256SUMS --quiet)
cat > "$S/containers" <<X
pg1|dnb-staging-postgres|running|2026-09-23T15:40:00.100Z|0
wk1|dnb-staging-worker|running|2026-09-23T17:34:25.100Z|0
tr1|easypanel-traefik.1.abcdef|running|2026-09-19T10:00:00.100Z|0
um1|unms|running|2026-09-19T10:00:00.100Z|0
X
psql -h "$PGH" -p "$PGP" -U dnb -d postgres -q -c "DROP DATABASE IF EXISTS $DB" -c "CREATE DATABASE $DB" 2>/dev/null
OWNERPASS=$(openssl rand -hex 24)
psql -h "$PGH" -p "$PGP" -U postgres -d postgres -q -c "ALTER ROLE dnb PASSWORD '$OWNERPASS'"
mkdir -p "$E"; umask 077
printf 'DNB_DSN=pgsql:host=127.0.0.1;port=%s;dbname=%s\nDNB_TOKEN_PEPPER=%s\nDNB_SECRET_KEY=%s\n' "$PGP" "$DB" "$(openssl rand -hex 32)" "$(openssl rand -hex 32)" > "$E/runtime.env"
printf 'DNB_OWNER_USER=dnb\nDNB_OWNER_PASS=%s\nDNB_SECRETS_OUT=%s/secrets.env\n' "$OWNERPASS" "$E" > "$E/install.env"
rm -f /opt/dnb-staging/env/secrets.env
RUN="docker run --rm --network dnb-staging -v $APP/app:/app:ro -w /app --env-file $E/runtime.env --env-file $E/install.env"
# A development cluster already carries role passwords from earlier runs; the
# harness (never staging) mints new ones with the installer's own switch.
$RUN -e DNB_ROTATE_CREDENTIALS=yes-rotate-now "$IMG" php plugin/bin/plugin.php install > "$S/install.out" 2>&1 || { cat "$S/install.out"; exit 1; }
sed -E 's/^([A-Za-z_][A-Za-z0-9_]*)="([^"]*)"$/\1=\2/' "$E/secrets.env" > "$E/secrets.docker.env"
[ "$(grep -cE '^DNB_(APP|WORKER|ADMIN|ADMINAPI|ADMINWRITE|RADIUS|STAFFAUTH)_PASS=[0-9a-f]{64}$' "$E/secrets.docker.env")" = 7 ]
$RUN --env-file "$E/secrets.docker.env" "$IMG" php plugin/bin/plugin.php simulate > "$S/simulate.out" 2>&1 || { cat "$S/simulate.out"; exit 1; }
T=/etc/easypanel/traefik/config; mkdir -p "$T"
python3 - "$REPO/scripts/dnb-staging-stage2.sh" > "$T/dnb-staging.yml.new" <<'PY'
import re, sys
blocks = re.findall(r"cat >>? \"\$NEW\" <<'YAML'\n(.*?)\nYAML\n", open(sys.argv[1]).read(), re.S)
assert len(blocks) == 3, len(blocks)          # main, optional allow-list, services
sys.stdout.write(blocks[0] + '\n' + blocks[2] + '\n')
PY
HASH=$(openssl passwd -apr1 "$(openssl rand -hex 10)")
sed -i -e 's|__MW__|"dnb-staging-auth", "dnb-staging-ratelimit", "dnb-staging-headers"|' -e "s|__USERS__|dishnet:$HASH|" "$T/dnb-staging.yml.new"
! grep -q '__' "$T/dnb-staging.yml.new"
mv "$T/dnb-staging.yml.new" "$T/dnb-staging.yml"; chmod 0644 "$T/dnb-staging.yml"; cp "$T/dnb-staging.yml" "$S/route.stage2"
docker run -d --name dnb-staging-api --network dnb-staging --restart unless-stopped \
  -p 127.0.0.1:8099:8099 -p 172.17.0.1:8099:8099 -v "$APP/app:/app:ro" -w /app \
  --env-file "$E/runtime.env" --env-file "$E/secrets.docker.env" \
  -e DN_DEV_STAFF_IDENTITY=yes-development-only "$IMG" php -S 0.0.0.0:8099 plugin/bin/serve.php >/dev/null
for _ in $(seq 50); do [ "$(curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1:8099/)" = 200 ] && break; sleep 0.2; done
[ -f "$HSIM/tls.crt" ] || openssl req -x509 -newkey rsa:2048 -nodes -keyout "$HSIM/tls.key" -out "$HSIM/tls.crt" -days 30 -subj "/CN=portal-staging.dishnetuganda.com" >/dev/null 2>&1
if ! (exec 3<>/dev/tcp/127.0.0.1/443) 2>/dev/null; then
  setsid nohup python3 "$HSIM_HOME/traefik.py" >> "$S/traefik.stdout" 2>&1 < /dev/null &
  for _ in $(seq 50); do (exec 3<>/dev/tcp/127.0.0.1/443) 2>/dev/null && break; sleep 0.2; done
fi
for p in / /routers.js /api/v1/admin/session; do curl -s -o /dev/null "http://127.0.0.1:8099$p"; done
for p in / /app.js /login.js /api/v1/admin/session; do
  curl -sk -o /dev/null -u dishnet:browser --resolve portal-staging.dishnetuganda.com:443:127.0.0.1 "https://portal-staging.dishnetuganda.com$p"
done
echo "reset: build $(sha256sum /opt/dnb-staging/app/SHA256SUMS | cut -c1-12)…, migrations $(psql -h "$PGH" -p "$PGP" -U postgres -d "$DB" -Atc 'select count(*) from mt_migrations'), routers $(psql -h "$PGH" -p "$PGP" -U postgres -d "$DB" -Atc 'select count(*) from mt_devices'), staff $(psql -h "$PGH" -p "$PGP" -U postgres -d "$DB" -Atc 'select count(*) from mt_staff'); traefik from $(cat "$S/traefik.src")"
