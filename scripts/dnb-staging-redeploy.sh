#!/bin/sh
# DishNet Domain-B staging — REDEPLOY the application to the reviewed build
# (docs/121 §H). One command, run as root on the server:
#
#   curl -fsSL -o /root/dnb-redeploy.sh https://raw.githubusercontent.com/dishnetafrica/dishnetuganda/claude/study-this-jhe2eg/scripts/dnb-staging-redeploy.sh \
#     && sh /root/dnb-redeploy.sh 2>&1 | tee /root/dnb-staging-evidence/redeploy-$(date -u +%Y%m%dT%H%M%SZ).log
#
# What it does: builds the artifact ON THIS SERVER from the public branch,
# refuses unless its content digest is the one reviewed below, swaps
# /opt/dnb-staging/app atomically (the previous tree is kept), applies the
# pending migration(s) with the installer and the installation's own secrets,
# restarts ONLY the two application containers, and verifies. It touches no
# production container, no Traefik file, no DNS record, no firewall rule and no
# PostgreSQL instance but dnb-staging-postgres. Nothing real is contacted:
# DN_ALLOW_REAL_BINDINGS stays absent and delivery stays simulated.
#
# The version name is still 0.1.0-rc1: compare the CONTENT DIGEST, never the
# name (docs/96). Stage 1 deployed 4e7467ad…; this build is 1bc95524….
set -eu

EV=/root/dnb-staging-evidence
APP=/opt/dnb-staging
IMG=dnb-staging-php:8.3
REPO=https://github.com/dishnetafrica/dishnetuganda
BRANCH=claude/study-this-jhe2eg
CP=dishnet-hybrid-sudan/dishnet-mikrotik-control-plane
HOST=portal-staging.dishnetuganda.com
# Content digest of the reviewed build = sha256 of the archive's SHA256SUMS (docs/121 §H).
DIGEST=1bc95524cd36f38413b5325fe26cdf76a20cb9d67e4253051c5b0bae7a7af74b
TS=$(date -u +%Y%m%dT%H%M%SZ)

fail() { echo; echo "STOP: $*" >&2; exit 1; }
step() { echo; echo "== $* =="; }

[ "$(id -u)" = 0 ] || fail "run as root"
for t in docker curl tar sha256sum sed grep awk comm php; do
  command -v "$t" >/dev/null 2>&1 || fail "missing tool: $t"
done
install -d -m 0700 "$EV"

step "0/6 read-only checks (nothing is changed in this step)"
for c in dnb-staging-postgres dnb-staging-api dnb-staging-worker; do
  [ "$(docker inspect -f '{{.State.Running}}' "$c" 2>/dev/null || true)" = true ] \
    || fail "$c is not running — stage 1 (docs/120 §15) is not in place"
done
[ -d "$APP/app" ] || fail "$APP/app is missing"
for f in runtime.env install.env secrets.docker.env; do
  [ -f "$APP/env/$f" ] || fail "$APP/env/$f is missing — not the stage-1 layout"
done
docker image inspect "$IMG" >/dev/null 2>&1 || fail "image $IMG is missing"
cur=$(sha256sum "$APP/app/SHA256SUMS" | cut -d' ' -f1)
echo "deployed content digest: $cur"
if [ "$cur" = "$DIGEST" ]; then
  echo "NOTE: the reviewed build is already deployed; the run continues and re-verifies"
fi
docker inspect -f '{{.Name}}|{{.State.Status}}|{{.State.StartedAt}}|{{.RestartCount}}' $(docker ps -aq) \
  | sort > "$EV/redeploy-$TS.before"
before_mig=$(docker exec dnb-staging-postgres psql -U postgres -d dnb -Atc \
  "SELECT count(*)||' (last '||max(filename)||')' FROM mt_migrations")
echo "migrations before: $before_mig"
code=$(curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1:8099/ || true)
[ "$code" = 200 ] || fail "the panel does not answer 200 on 127.0.0.1:8099 (got $code)"
echo "ok: stage 1 is healthy"

step "1/6 build the artifact on this server from $BRANCH and check its content digest"
SRC="$EV/src-$TS"
install -d "$SRC"
if command -v git >/dev/null 2>&1; then
  git clone -q --depth 1 --branch "$BRANCH" "$REPO.git" "$SRC"
  ( cd "$SRC" && git rev-parse HEAD ) | tee "$EV/redeploy-$TS.commit"
else
  curl -fsSL "https://codeload.github.com/dishnetafrica/dishnetuganda/tar.gz/refs/heads/$BRANCH" \
    | tar -xz -C "$SRC" --strip-components=1
  echo "branch tarball of $BRANCH (no git on this host)" | tee "$EV/redeploy-$TS.commit"
fi
sh "$SRC/$CP/plugin/bin/package.sh" "$EV/dist-$TS" | sed -n '1,4p'
ART=$(ls "$EV/dist-$TS"/*.tar.gz | head -1)
[ -f "$ART" ] || fail "no artifact was built"
NEW="$APP/app.new-$TS"
install -d -m 0750 "$NEW"
tar -xzf "$ART" -C "$NEW" --strip-components=1
( cd "$NEW" && sha256sum -c SHA256SUMS --quiet ) || { rm -rf "$NEW"; fail "the extracted files do not match SHA256SUMS"; }
got=$(sha256sum "$NEW/SHA256SUMS" | cut -d' ' -f1)
if [ "$got" != "$DIGEST" ]; then
  rm -rf "$NEW"
  fail "content digest $got is not the reviewed build $DIGEST — nothing was changed"
fi
echo "artifact-ok: $(cat "$NEW/VERSION"), $(find "$NEW" -type f | wc -l | tr -d ' ') files, content digest $got"
[ -f "$NEW/panel/routers.js" ] || { rm -rf "$NEW"; fail "the build carries no panel/routers.js — wrong build"; }
[ -f "$NEW/migrations/028_admin_router_lifecycle_and_provisioning.sql" ] || { rm -rf "$NEW"; fail "the build carries no migration 028 — wrong build"; }

step "2/6 swap the application tree (the previous tree is kept for rollback)"
mv "$APP/app" "$APP/app.prev-$TS"
mv "$NEW" "$APP/app"
echo "swapped: $APP/app is the new build; previous tree at $APP/app.prev-$TS"

step "3/6 apply pending migrations with the installer and the installation's own secrets"
RUN="docker run --rm --network dnb-staging -v $APP/app:/app:ro -v $APP/env:/run/dnb -w /app --env-file $APP/env/runtime.env --env-file $APP/env/install.env --env-file $APP/env/secrets.docker.env"
$RUN "$IMG" php plugin/bin/plugin.php install
echo "-- doctor (a WARN on the development identity is the truth of this staging install) --"
$RUN "$IMG" php plugin/bin/plugin.php doctor --disposable || echo "(doctor exit $? — read its lines above)"
after_mig=$(docker exec dnb-staging-postgres psql -U postgres -d dnb -Atc "SELECT count(*) FROM mt_migrations")
after_last=$(docker exec dnb-staging-postgres psql -U postgres -d dnb -Atc "SELECT max(filename) FROM mt_migrations")
want=$(ls "$APP/app/migrations"/*.sql | wc -l | tr -d ' ')
echo "migrations after: $after_mig (last $after_last); migration files in the build: $want"
[ "$after_mig" = "$want" ] || fail "the ledger ($after_mig) does not match the build's migration files ($want)"
docker exec dnb-staging-postgres psql -U postgres -d dnb -Atc \
  "SELECT 'mt_device_provision_request: '||count(*)||' function(s); dnb_adminwrite may execute: '||bool_or(has_function_privilege('dnb_adminwrite', oid, 'EXECUTE')) FROM pg_proc WHERE proname='mt_device_provision_request'"

step "4/6 restart the two application containers — and nothing else"
docker restart dnb-staging-api dnb-staging-worker >/dev/null
i=0
while [ $i -lt 30 ]; do
  code=$(curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1:8099/ || true)
  [ "$code" = 200 ] && break
  i=$((i+1)); sleep 1
done

step "5/6 verify"
code_panel=$(curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1:8099/ || true)
code_js=$(curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1:8099/routers.js || true)
code_sess=$(curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1:8099/api/v1/admin/session || true)
echo "loopback: panel $code_panel, routers.js $code_js, GET /api/v1/admin/session $code_sess (401 expected: nobody is signed in)"
[ "$code_panel" = 200 ] && [ "$code_js" = 200 ] && [ "$code_sess" = 401 ] || fail "the API did not come back as expected"
pub=$(curl -sk -o /dev/null -w '%{http_code}' --resolve "$HOST:443:127.0.0.1" "https://$HOST/routers.js" || true)
echo "public hostname through Traefik: $pub (401 expected: basic auth in front)"
[ "$pub" = 401 ] || echo "WARN: expected 401 from Traefik, got $pub — check the stage-2 route file (docs/120 §15.8.6)"
echo "worker binding: $(docker logs --since 3m dnb-staging-worker 2>&1 | grep -m1 -o '"delivery_binding":"[^"]*"' || echo '(not yet logged)')"
docker inspect -f '{{.Name}}|{{.State.Status}}|{{.State.StartedAt}}|{{.RestartCount}}' $(docker ps -aq) \
  | sort > "$EV/redeploy-$TS.after"
changed=$(comm -13 "$EV/redeploy-$TS.before" "$EV/redeploy-$TS.after" | cut -d'|' -f1 | tr -d '/' | sort | tr '\n' ' ')
echo "containers whose status/StartedAt/RestartCount changed: ${changed:-none}"
[ "$changed" = "dnb-staging-api dnb-staging-worker " ] || fail "unexpected container change: ${changed:-none}"
cut -d'|' -f1 "$EV/redeploy-$TS.before" | sort > "$EV/redeploy-$TS.names.before"
cut -d'|' -f1 "$EV/redeploy-$TS.after"  | sort > "$EV/redeploy-$TS.names.after"
removed=$(comm -23 "$EV/redeploy-$TS.names.before" "$EV/redeploy-$TS.names.after" | tr '\n' ' ')
echo "containers removed (must be none): ${removed:-none}"
[ -z "$removed" ] || fail "a container disappeared during the redeploy: $removed"

step "6/6 result"
echo "=== REDEPLOYED $(date -u +%FT%TZ): $(cat "$APP/app/VERSION"), content digest $DIGEST, migrations $after_mig (last $after_last) ==="
echo "Previous tree kept at $APP/app.prev-$TS. Rollback, if ever needed:"
echo "  mv $APP/app $APP/app.failed-$TS && mv $APP/app.prev-$TS $APP/app && docker restart dnb-staging-api dnb-staging-worker"
echo "  (migration 028 adds two functions and two policies the previous build never calls; it needs no undoing)"
echo "Paste this whole output back; leave nothing out."
