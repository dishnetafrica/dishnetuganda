#!/bin/sh
# dnb-staging-stage2.sh — ONE COMMAND: make the Domain-B STAGING panel reachable at
#
#     https://portal-staging.dishnetuganda.com
#
# through the Traefik that already serves this host, behind HTTP basic auth
# (a username and a password this script generates and shows you once), a
# per-address rate limit, and security headers. docs/120 §15.8.6.
#
# Run on the server, as root, from the repository's public branch:
#
#   curl -fsSL -o /root/dnb-stage2.sh https://raw.githubusercontent.com/dishnetafrica/dishnetuganda/claude/study-this-jhe2eg/scripts/dnb-staging-stage2.sh && sh /root/dnb-stage2.sh 2>&1 | tee /root/dnb-staging-evidence/stage2.log
#
# No input is required. Optional, for a stricter setup:
#   ALLOW_CIDR=<your public IP>/32   also restricts the hostname to that address
#   BASIC_USER=<name>                the login name (default: dishnet)
#
# It follows the live mail-stack precedent on this host (dishnet-mail/traefik-mail.yml):
# one file dropped into /etc/easypanel/traefik/config/ (Traefik picks it up, no
# restart), backend published on the docker bridge gateway 172.17.0.1 only. It
# changes exactly two things: the dnb-staging-api container is recreated with a
# second publish on 172.17.0.1:8099 (the 127.0.0.1:8099 publish is kept), and the
# file /etc/easypanel/traefik/config/dnb-staging.yml is written. It never touches
# main.yaml, uisp.yaml, traefik-mail.yml, the swarm, EasyPanel, any other
# container, DNS, iptables, or 0.0.0.0. Re-running it is safe: the route file is
# rewritten and the password rotates. It stops at the first failure.
set -eu
EV=/root/dnb-staging-evidence
APP=/opt/dnb-staging
IMG=dnb-staging-php:8.3
HOST=portal-staging.dishnetuganda.com
TCFG=/etc/easypanel/traefik/config
ROUTE=$TCFG/dnb-staging.yml
GW=172.17.0.1
BASIC_USER="${BASIC_USER:-dishnet}"
ALLOW_CIDR="${ALLOW_CIDR:-}"
step() { printf '\n=== %s ===\n' "$1"; }
fail() { printf '\nSTOP: %s\nNothing further was run. Send the whole output back.\n' "$1"; exit 1; }
install -d -m 0700 "$EV"
date -u '+%Y-%m-%d %H:%M:%S UTC'; hostname

step "1/6 checking the server (read-only)"
[ "$(id -u)" = 0 ] || fail "run this as root"
for t in docker openssl curl ss getent ip sed grep awk; do command -v "$t" >/dev/null 2>&1 || fail "missing tool: $t"; done
printf '%s' "$BASIC_USER" | grep -qE '^[a-z][a-z0-9_-]{2,31}$' || fail "BASIC_USER must be 3-32 chars: lowercase letters, digits, _ or -"
ALLOW_YAML=""
if [ -n "$ALLOW_CIDR" ]; then
  for c in $(printf '%s' "$ALLOW_CIDR" | tr ',' ' '); do
    printf '%s' "$c" | grep -qE '^([0-9]{1,3}\.){3}[0-9]{1,3}/(1[6-9]|2[0-9]|3[0-2])$' || fail "ALLOW_CIDR item '$c' is not an IPv4 range between /16 and /32"
    ALLOW_YAML="${ALLOW_YAML:+$ALLOW_YAML, }\"$c\""
  done
fi
for c in dnb-staging-postgres dnb-staging-api dnb-staging-worker; do
  [ "$(docker inspect -f '{{.State.Status}}' "$c" 2>/dev/null)" = running ] || fail "stage-1 container $c is not running (stage 1 must be deployed first)"
done
[ "$(curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1:8099/)" = 200 ] || fail "the staging API does not answer on 127.0.0.1:8099"
[ -f "$APP/env/runtime.env" ] && [ -f "$APP/env/secrets.docker.env" ] || fail "stage-1 env files are missing under $APP/env"
[ -d "$TCFG" ] || fail "$TCFG does not exist"
grep -q 'entryPoints: \["https"\]' "$TCFG/traefik-mail.yml" && grep -q 'certResolver: letsencrypt' "$TCFG/traefik-mail.yml" && grep -q "172.17.0.1:" "$TCFG/traefik-mail.yml" \
  || fail "Traefik's mail route file does not show the expected pattern (https entrypoint, letsencrypt, 172.17.0.1 backend) - stop and look"
T=$(docker ps --format '{{.Names}}' | grep -i traefik | head -1); [ -n "$T" ] || fail "no Traefik container is running"
SVC=$(docker service ls --format '{{.Name}}' 2>/dev/null | grep -i traefik | head -1); [ -n "$SVC" ] || fail "no Traefik swarm service found"
PORTS=$(docker service inspect "$SVC" --format '{{json .Endpoint.Spec.Ports}}')
printf '%s' "$PORTS" | grep -q '"PublishMode":"host"' || fail "Traefik publishes 80/443 in ingress mode; the client address would be hidden - stop and report"
T_STARTED=$(docker inspect -f '{{.State.StartedAt}}' "$T")
ip -4 addr show docker0 2>/dev/null | grep -q " $GW/" || fail "$GW is not the docker bridge gateway on this host"
DNSA=$(getent ahostsv4 "$HOST" | awk '{print $1}' | sort -u | tr '\n' ' ')
printf '%s' "$DNSA" | grep -q '209.97.137.203' || fail "$HOST does not resolve to 209.97.137.203 yet (got: ${DNSA:-nothing}) - wait a few minutes and run again"
[ -e "$ROUTE" ] && echo "note: $ROUTE already exists from an earlier run - it will be replaced and the password rotates"
docker inspect -f '{{.Name}}|{{.State.Status}}|{{.State.StartedAt}}|{{.RestartCount}}' $(docker ps -aq) | sort > "$EV/inspect.s2before"
echo "ok: stage 1 healthy, Traefik in host mode, DNS resolves, precedent unchanged"

step "2/6 generating the login"
PW=$(openssl rand -base64 48 | tr -dc 'A-Za-z0-9' | cut -c1-20)
[ "${#PW}" = 20 ] || fail "password generation failed"
HASH=$(openssl passwd -apr1 "$PW")
printf '%s' "$HASH" | grep -qE '^\$apr1\$' || fail "hash generation failed"
echo "ok: user $BASIC_USER (the password is shown at the end, on the terminal only)"

step "3/6 publishing the API on the bridge gateway for Traefik (loopback kept; never 0.0.0.0)"
docker rm -f dnb-staging-api >/dev/null 2>&1 || true
restore_loopback() {
  docker run -d --name dnb-staging-api --network dnb-staging --restart unless-stopped \
    -p 127.0.0.1:8099:8099 -v "$APP/app:/app:ro" -w /app \
    --env-file "$APP/env/runtime.env" --env-file "$APP/env/secrets.docker.env" \
    -e DN_DEV_STAFF_IDENTITY=yes-development-only "$IMG" php -S 0.0.0.0:8099 plugin/bin/serve.php >/dev/null 2>&1 || true
}
if ! docker run -d --name dnb-staging-api --network dnb-staging --restart unless-stopped \
    -p 127.0.0.1:8099:8099 -p "$GW:8099:8099" -v "$APP/app:/app:ro" -w /app \
    --env-file "$APP/env/runtime.env" --env-file "$APP/env/secrets.docker.env" \
    -e DN_DEV_STAFF_IDENTITY=yes-development-only "$IMG" php -S 0.0.0.0:8099 plugin/bin/serve.php >/dev/null; then
  restore_loopback; fail "could not start the API with the gateway publish; the loopback-only container was restored"
fi
i=0; until [ "$(curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1:8099/)" = 200 ] && [ "$(curl -s -o /dev/null -w '%{http_code}' "http://$GW:8099/")" = 200 ]; do
  i=$((i+1)); [ "$i" -le 30 ] || { docker logs dnb-staging-api --tail 10; fail "the API does not answer on both addresses after 30 s"; }; sleep 1
done
[ "$(ss -tln | grep -c ':8099 ')" = 2 ] || fail "expected exactly two 8099 listeners (127.0.0.1 and $GW)"
! ss -tln | grep -E ':8099 ' | grep -qE '(0\.0\.0\.0|\*|\[::\]):8099' || fail "8099 is bound on a wildcard address - not allowed"
echo "ok: API answers on 127.0.0.1:8099 and $GW:8099"

step "4/6 writing the Traefik route file"
NEW=$EV/dnb-staging.yml.new
MW='"dnb-staging-auth", "dnb-staging-ratelimit", "dnb-staging-headers"'
[ -n "$ALLOW_YAML" ] && MW="\"dnb-staging-allow\", $MW"
cat > "$NEW" <<'YAML'
# Domain-B STAGING (docs/120 §15.8.6). Drop-in for /etc/easypanel/traefik/config/,
# the same mechanism as uisp.yaml and traefik-mail.yml. Backend: the staging API
# published on the docker bridge gateway only (172.17.0.1:8099). In front of the
# credential-less development identity: HTTP basic auth, a per-address rate
# limit, security headers, and optionally an IP allow-list. Delete this file to
# withdraw the hostname; Traefik drops the route within seconds.
http:
  routers:
    dnb-staging:
      rule: "Host(`portal-staging.dishnetuganda.com`)"
      entryPoints: ["https"]
      priority: 10
      service: dnb-staging
      middlewares: [__MW__]
      tls:
        certResolver: letsencrypt
    dnb-staging-http:
      rule: "Host(`portal-staging.dishnetuganda.com`)"
      entryPoints: ["http"]
      priority: 10
      service: dnb-staging
      middlewares: ["dnb-staging-https"]
  middlewares:
    dnb-staging-https:
      redirectScheme:
        scheme: https
        permanent: true
    dnb-staging-auth:
      basicAuth:
        realm: "DishNet staging"
        users:
          - "__USERS__"
    dnb-staging-ratelimit:
      rateLimit:
        average: 20
        burst: 50
    dnb-staging-headers:
      headers:
        frameDeny: true
        contentTypeNosniff: true
        referrerPolicy: "no-referrer"
        stsSeconds: 15552000
        customResponseHeaders:
          X-Robots-Tag: "noindex, nofollow, noarchive"
YAML
if [ -n "$ALLOW_YAML" ]; then
  cat >> "$NEW" <<'YAML'
    dnb-staging-allow:
      ipAllowList:
        sourceRange: [__ALLOW__]
YAML
fi
cat >> "$NEW" <<'YAML'
  services:
    dnb-staging:
      loadBalancer:
        servers:
          - url: "http://172.17.0.1:8099"
YAML
sed -i -e "s|__MW__|$MW|" -e "s|__USERS__|$BASIC_USER:$HASH|" -e "s|__ALLOW__|$ALLOW_YAML|" "$NEW"
unset HASH
grep -q '__MW__\|__USERS__\|__ALLOW__' "$NEW" && fail "placeholder substitution failed"
if command -v python3 >/dev/null 2>&1 && python3 -c 'import yaml' 2>/dev/null; then
  python3 - "$NEW" <<'PY' || fail "the route file is not valid YAML"
import sys, yaml
d = yaml.safe_load(open(sys.argv[1]))
r = d['http']['routers']['dnb-staging']; m = d['http']['middlewares']
for name in r['middlewares']:
    assert name in m, name
assert len(m['dnb-staging-auth']['basicAuth']['users']) == 1 and '$apr1$' in m['dnb-staging-auth']['basicAuth']['users'][0]
assert d['http']['services']['dnb-staging']['loadBalancer']['servers'][0]['url'] == 'http://172.17.0.1:8099'
print('ok: route file valid (' + ', '.join(r['middlewares']) + ')')
PY
fi
cp "$NEW" "$TCFG/.dnb-staging.yml.tmp" && mv "$TCFG/.dnb-staging.yml.tmp" "$ROUTE" && rm -f "$NEW"
chmod 0644 "$ROUTE"
echo "ok: $ROUTE written (the other files in $TCFG are untouched)"

step "5/6 waiting for Traefik to pick it up and get the certificate"
probe() { curl -sk -o /dev/null -w '%{http_code}' --resolve "$HOST:443:127.0.0.1" "https://$HOST/"; }
want=401; [ -n "$ALLOW_YAML" ] && want=403
i=0; until [ "$(probe)" = "$want" ]; do
  i=$((i+1)); [ "$i" -le 60 ] || { echo "last answer from this host: $(probe) (expected $want)"; docker logs "$T" --since 3m 2>&1 | grep -iE 'dnb-staging|portal-staging|error' | tail -10; fail "Traefik did not route $HOST within 60 s"; }; sleep 1
done
echo "ok: route active after ${i}s (this host gets $want, as expected)"
i=0; until openssl s_client -connect 127.0.0.1:443 -servername "$HOST" </dev/null 2>/dev/null | openssl x509 -noout -issuer 2>/dev/null | grep -qi "let's encrypt"; do
  i=$((i+1)); [ "$i" -le 150 ] || { openssl s_client -connect 127.0.0.1:443 -servername "$HOST" </dev/null 2>/dev/null | openssl x509 -noout -issuer -subject -enddate; docker logs "$T" --since 5m 2>&1 | grep -iE 'acme|portal-staging|error' | tail -10; fail "the certificate did not arrive within 150 s; the route is in place - send this output back"; }; sleep 1
done
echo "ok: certificate from $(openssl s_client -connect 127.0.0.1:443 -servername "$HOST" </dev/null 2>/dev/null | openssl x509 -noout -issuer | sed 's/^issuer=//')"

step "6/6 checks"
set +e
printf '8099 listeners (expect 127.0.0.1 and %s only):\n' "$GW"; ss -tln | grep ':8099 ' | awk '{print "  " $4}'
printf 'public 209.97.137.203:8099 -> '; curl -s -o /dev/null --connect-timeout 3 http://209.97.137.203:8099/ && echo "ANSWERED - FAIL" || echo "refused (correct)"
printf 'https://%s/ without login -> %s\n' "$HOST" "$(probe)"
printf 'https://%s/ wrong password -> %s\n' "$HOST" "$(curl -sk -o /dev/null -w '%{http_code}' -u "$BASIC_USER:wrong" --resolve "$HOST:443:127.0.0.1" "https://$HOST/")"
printf 'https://%s/ right login -> %s\n' "$HOST" "$(curl -sk -o /dev/null -w '%{http_code}' -u "$BASIC_USER:$PW" --resolve "$HOST:443:127.0.0.1" "https://$HOST/")"
printf 'http://%s/ -> %s (redirect to https)\n' "$HOST" "$(curl -s -o /dev/null -w '%{http_code}' --resolve "$HOST:80:127.0.0.1" "http://$HOST/")"
printf 'Traefik restarted? before %s / after %s\n' "$T_STARTED" "$(docker inspect -f '{{.State.StartedAt}}' "$T")"
docker inspect -f '{{.Name}}|{{.State.Status}}|{{.State.StartedAt}}|{{.RestartCount}}' $(docker ps -aq) | sort > "$EV/inspect.s2after"
echo "containers whose state changed (expect only /dnb-staging-api):"; comm -3 "$EV/inspect.s2before" "$EV/inspect.s2after" | sed 's/^/  /'
[ -n "$ALLOW_YAML" ] && echo "allow-list active: [$ALLOW_YAML] (the right login still answers 403 from this host, which is not on the list - expected)"
if ( : > /dev/tty ) 2>/dev/null; then
  printf '\n==================================================================\n  OPEN:      https://%s/\n  USER:      %s\n  PASSWORD:  %s\n  (shown once, written to no log - save it now)\n  Then the DishNet Admin login card appears: choose admin.\n==================================================================\n' "$HOST" "$BASIC_USER" "$PW" > /dev/tty
else
  umask 077; printf 'https://%s/\nuser: %s\npassword: %s\n' "$HOST" "$BASIC_USER" "$PW" > "$EV/portal-login.txt"
  echo "no terminal detected: the login was written to $EV/portal-login.txt (root only) - read it with: cat $EV/portal-login.txt"
fi
unset PW
echo
echo "=== DONE - stage 2 is in place. Send everything above this line back (the password box is not in the log). ==="
