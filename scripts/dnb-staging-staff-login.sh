#!/bin/sh
# DishNet Domain-B staging — switch on REAL DishNet staff login (docs/122).
# Roadmap step 4, on STAGING only. One command, run as root on the server:
#
#   curl -fsSL -o /root/dnb-staff-login.sh https://raw.githubusercontent.com/dishnetafrica/dishnetuganda/claude/study-this-jhe2eg/scripts/dnb-staging-staff-login.sh \
#     && sh /root/dnb-staff-login.sh 2>&1 | tee /root/dnb-staging-evidence/staff-login-$(date -u +%Y%m%dT%H%M%SZ).log
#
# What it does, in order, stopping at the first failure:
#   0  read-only checks; picks the FIRST CANDIDATE for DN_TRUSTED_PROXY — the
#      address the API has seen on its connections so far (its "Accepted" log
#      lines), or the dnb-staging bridge gateway. A candidate, not a claim:
#      step 4 proves it or corrects it
#   1  recreates dnb-staging-api with DN_STAFF_IDENTITY=dishnet, the candidate
#      DN_TRUSTED_PROXY and DN_PORTAL_ORIGIN — and WITHOUT the development
#      identity; restores the previous container if the new one does not start
#   2  proves on loopback that the development identity is gone and that a
#      session is refused over plain HTTP (403 insecure_transport)
#   3  runs the preflight doctor in PRODUCTION posture (no --disposable) and
#      requires 0 blockers
#   4  removes the stop-gap HTTP basic auth from the Traefik route, then
#      PROVES the trusted-proxy address: its own sign-in attempt with a wrong
#      password, through Traefik, must be answered 401 invalid_credentials —
#      possible only when TLS was recognised from the proxy's address. If it is
#      answered 403 insecure_transport, the address that attempt arrived from
#      is read off the API's log, the API is recreated with it, and the attempt
#      is repeated once. On any other outcome the route AND the container are
#      rolled back to their previous form
#   5  creates the first DishNet administrator with plugin.php staff:bootstrap
#      — only now, with the login proved, so a rollback never leaves a
#      password behind (skipped when a staff row already exists); the one-time
#      password goes to the TERMINAL only, never into this log
#   6  measures whether a host-local client shares the trusted address, checks
#      that no other container changed, and prints where to sign in
#
# Why the address is proved and not looked up: TransportPolicy believes
# X-Forwarded-Proto only from an address that DN_TRUSTED_PROXY names EXACTLY,
# and what address Traefik's connections carry when they reach the API is a
# property of this host's Docker networking. PHP's built-in server writes no
# request line for a request its router script handles (php-src PHP-8.3,
# sapi/cli/php_cli_server.c, php_cli_server_dispatch) — only "<addr> Accepted"
# and "<addr> Closing" — so the log can say which addresses connected, never
# which connection was the browser's. Only the sign-in attempt itself proves it.
#
# Touches: the dnb-staging-api container, the one Traefik route file this
# project itself wrote (docs/120 §15.8.6), and — once — one staff row and its
# audit row in the staging database. Nothing else: no production container,
# no DNS, no firewall, no other PostgreSQL. Nothing real is contacted.
set -eu

EV=/root/dnb-staging-evidence
APP=/opt/dnb-staging
IMG=dnb-staging-php:8.3
HOST=portal-staging.dishnetuganda.com
ROUTE=/etc/easypanel/traefik/config/dnb-staging.yml
GW=172.17.0.1                                  # the gateway publish Traefik reaches (docs/120 §15.8.1)
STAFF_USER="${STAFF_USER:-dishnet-admin}"      # override: STAFF_USER=alice sh /root/dnb-staff-login.sh
STAFF_DISPLAY="${STAFF_DISPLAY:-DishNet Administrator}"
TS=$(date -u +%Y%m%dT%H%M%SZ)
ORIGIN="https://$HOST"
ROUTE_CHANGED=0

fail() { echo; echo "STOP: $*" >&2; exit 1; }
step() { echo; echo "== $* =="; }
code() { curl -s -o /dev/null -w '%{http_code}' "$@" 2>/dev/null || true; }
# The password goes to the controlling terminal, which `| tee` does not capture.
# The test opens it for real: /dev/tty's permission bits say "writable" even in
# a session that has no terminal, and a failed write there must not lose it.
tty_out() {
  if ( : > /dev/tty ) 2>/dev/null && printf '%s\n' "$1" > /dev/tty 2>/dev/null; then
    echo "  the sign-in box is on your terminal (not in this log)"
  else
    ( umask 077; printf '%s\n' "$1" > "$2" )
    echo "  no terminal: the sign-in box was written to $2 (mode 0600) — read it with: cat $2 — then delete it"
  fi
}
# Every peer address the API has accepted a connection from, in log order.
accepted_addrs() { docker logs dnb-staging-api 2>&1 | grep -E '\] [0-9.]+:[0-9]+ Accepted$' | sed -E 's/.*\] ([0-9.]+):[0-9]+ Accepted$/\1/' || true; }

[ "$(id -u)" = 0 ] || fail "run as root"
for t in docker curl sed grep awk sort uniq mktemp comm; do command -v "$t" >/dev/null 2>&1 || fail "missing tool: $t"; done
install -d -m 0700 "$EV"
echo "$STAFF_USER" | grep -qE '^[a-z0-9][a-z0-9._-]{1,62}$' || fail "STAFF_USER must be 2-63 chars: a-z 0-9 . _ - (got '$STAFF_USER')"
date -u '+%Y-%m-%d %H:%M:%S UTC'; hostname

step "0/6 read-only checks and the first candidate for the trusted-proxy address"
for c in dnb-staging-postgres dnb-staging-api dnb-staging-worker; do
  [ "$(docker inspect -f '{{.State.Running}}' "$c" 2>/dev/null || true)" = true ] || fail "$c is not running (stages 1-2, docs/120 §15)"
done
for f in runtime.env secrets.docker.env install.env; do [ -f "$APP/env/$f" ] || fail "$APP/env/$f is missing"; done
[ -f "$ROUTE" ] || fail "$ROUTE is missing — stage 2 (docs/120 §15.8.6) is not in place"
docker image inspect "$IMG" >/dev/null 2>&1 || fail "image $IMG is missing"
[ -f "$APP/app/migrations/028_admin_router_lifecycle_and_provisioning.sql" ] || fail "the deployed build predates migration 028 — run the redeploy (docs/121 §H) first"
echo "deployed build: $(cat "$APP/app/VERSION" 2>/dev/null || echo '?'), content digest $(sha256sum "$APP/app/SHA256SUMS" 2>/dev/null | cut -d' ' -f1)"
cur_env=$(docker inspect -f '{{range .Config.Env}}{{println .}}{{end}}' dnb-staging-api)
if echo "$cur_env" | grep -q '^DN_STAFF_IDENTITY=dishnet$'; then
  echo "NOTE: dnb-staging-api already runs the real provider; this run re-verifies and re-applies"
  ALREADY=1
else
  echo "$cur_env" | grep -q '^DN_DEV_STAFF_IDENTITY=' || fail "dnb-staging-api runs neither the development identity nor the real provider — not a state this script knows"
  ALREADY=0
fi
echo "$cur_env" | grep -qE '^DN_ALLOW_REAL_BINDINGS=' && fail "DN_ALLOW_REAL_BINDINGS is set on the API — F6-B is NOT authorized; refusing"
# The identity settings the container runs with NOW, kept for the rollback: the
# previous form is restored exactly, never a guessed one.
PREV_E=""
for k in DN_STAFF_IDENTITY DN_TRUSTED_PROXY DN_PORTAL_ORIGIN DN_DEV_STAFF_IDENTITY DN_STAFF_REQUIRE_TOTP; do
  v=$(echo "$cur_env" | grep "^$k=" | head -1 | cut -d= -f2- || true)
  [ -z "$v" ] && continue
  case $v in *[[:space:]]*|*[\*\?\[]*) fail "$k carries whitespace or a glob character; refusing to rebuild it";; esac
  PREV_E="$PREV_E -e $k=$v"
done
echo "current identity settings:${PREV_E:- (none)}"
[ "$(code http://127.0.0.1:8099/)" = 200 ] || fail "the panel does not answer 200 on 127.0.0.1:8099"
NGW=$(docker network inspect dnb-staging -f '{{range .IPAM.Config}}{{.Gateway}}{{end}}')
echo "$NGW" | grep -qE '^[0-9]{1,3}(\.[0-9]{1,3}){3}$' || fail "cannot read the dnb-staging bridge gateway (got '$NGW')"
echo "dnb-staging bridge gateway: $NGW"
# The route file: the stage-2 shape (basic auth present), already without it, or unknown.
if [ "$(grep -c '"dnb-staging-auth"' "$ROUTE")" = 1 ] && grep -q '^    dnb-staging-auth:$' "$ROUTE"; then
  HAS_AUTH=1; echo "route: stage-2 shape, HTTP basic auth in front"
elif grep -q 'dnb-staging-auth' "$ROUTE"; then
  fail "the route file mentions dnb-staging-auth but not in the stage-2 shape this script knows; paste it back"
else
  HAS_AUTH=0; echo "route: no basic auth (already removed)"
  [ "$ALREADY" = 0 ] && echo "WARNING: the development identity is reachable through the hostname without basic auth — not the stage-2 state. This run replaces it."
fi
grep -q 'certResolver: letsencrypt' "$ROUTE" && grep -q 'dnb-staging-ratelimit' "$ROUTE" && grep -q 'dnb-staging-headers' "$ROUTE" \
  || fail "the route file lacks the certificate resolver, the rate limit or the headers middleware; paste it back"
# The first candidate. Every connection the API accepted so far is in its log
# as "<addr>:<port> Accepted" — the operator's browser through Traefik and the
# scripts' loopback probes alike; the log cannot tell them apart (see header).
HIST="$EV/staff-login-$TS.accepted-addrs"
accepted_addrs | sort | uniq -c | sort -rn > "$HIST"
n_addr=$(wc -l < "$HIST" | tr -d ' ')
echo "peer addresses in the API's current log (count address):"; if [ "$n_addr" = 0 ]; then echo "  (none — the container was recreated recently)"; else sed 's/^/  /' "$HIST"; fi
if [ "$n_addr" = 0 ]; then CAND=$NGW; why="no connection recorded in this container's log; starting from the bridge gateway"
elif [ "$n_addr" = 1 ]; then CAND=$(awk '{print $2}' "$HIST"); why="the only address the API has ever seen a connection from"
elif grep -qE "^ *[0-9]+ $NGW\$" "$HIST"; then CAND=$NGW; why="several addresses seen; the bridge gateway is among them"
else CAND=$(head -1 "$HIST" | awk '{print $2}'); why="several addresses seen, none of them the gateway; the most frequent"
fi
echo "$CAND" | grep -qE '^[0-9]{1,3}(\.[0-9]{1,3}){3}$' || fail "candidate is not an IPv4 address: '$CAND'"
echo "first candidate for DN_TRUSTED_PROXY: $CAND ($why). Step 4 proves or corrects it."
staff_rows=$(docker exec dnb-staging-postgres psql -U postgres -d dnb -Atc "SELECT count(*) FROM mt_staff")
echo "DishNet staff rows before: $staff_rows"
docker inspect -f '{{.Name}}|{{.State.Status}}|{{.State.StartedAt}}|{{.RestartCount}}' $(docker ps -aq) | sort > "$EV/staff-login-$TS.before"
cp "$ROUTE" "$EV/staff-login-$TS.route.before"
B=$(mktemp "$EV/body.XXXXXX")

step "1/6 recreate the API with the real provider (no development identity)"
# run_api <trusted-proxy address>: replace dnb-staging-api; 0 when it answers 200 within 30 s.
run_api() {
  docker rm -f dnb-staging-api >/dev/null 2>&1 || true
  docker run -d --name dnb-staging-api --network dnb-staging --restart unless-stopped \
    -p 127.0.0.1:8099:8099 -p "$GW:8099:8099" -v "$APP/app:/app:ro" -w /app \
    --env-file "$APP/env/runtime.env" --env-file "$APP/env/secrets.docker.env" \
    -e DN_STAFF_IDENTITY=dishnet -e "DN_TRUSTED_PROXY=$1" -e "DN_PORTAL_ORIGIN=$ORIGIN" \
    "$IMG" php -S 0.0.0.0:8099 plugin/bin/serve.php >/dev/null || return 1
  i=0; until [ "$(code http://127.0.0.1:8099/)" = 200 ]; do
    i=$((i+1)); [ "$i" -le 30 ] || { docker logs dnb-staging-api --tail 10 2>&1 || true; return 1; }; sleep 1
  done
  echo "API up after ${i}s with DN_STAFF_IDENTITY=dishnet DN_TRUSTED_PROXY=$1 DN_PORTAL_ORIGIN=$ORIGIN; second factor: required (default)"
}
# restore_prev: the container exactly as it was before this run (its own identity settings).
restore_prev() {
  docker rm -f dnb-staging-api >/dev/null 2>&1 || true
  set -f
  # shellcheck disable=SC2086
  if docker run -d --name dnb-staging-api --network dnb-staging --restart unless-stopped \
      -p 127.0.0.1:8099:8099 -p "$GW:8099:8099" -v "$APP/app:/app:ro" -w /app \
      --env-file "$APP/env/runtime.env" --env-file "$APP/env/secrets.docker.env" \
      $PREV_E "$IMG" php -S 0.0.0.0:8099 plugin/bin/serve.php >/dev/null 2>&1; then
    echo "  restored: dnb-staging-api runs with its previous identity settings again (${PREV_E:-none})"
  else
    echo "  RESTORE FAILED: dnb-staging-api could not be recreated — re-run the stage-2 command (scripts/dnb-staging-stage2.sh)"
  fi
  set +f
}
restore_route() {
  [ "$ROUTE_CHANGED" = 1 ] || return 0
  if cp "$EV/staff-login-$TS.route.before" "$ROUTE.tmp" && mv "$ROUTE.tmp" "$ROUTE"; then
    echo "  restored: the Traefik route file is back to its previous form (basic auth in front)"
  else
    echo "  RESTORE FAILED: copy $EV/staff-login-$TS.route.before over $ROUTE by hand"
  fi
}
bail() { restore_route; restore_prev; fail "$*"; }
TRUSTED=$CAND
run_api "$TRUSTED" || bail "could not start the API with the real provider"

step "2/6 loopback proofs: the development identity is gone; no session over plain HTTP"
sess_code=$(curl -s -o "$B" -w '%{http_code}' http://127.0.0.1:8099/api/v1/admin/session || true)
sess=$(cat "$B")
echo "GET /api/v1/admin/session → $sess_code $sess"
[ "$sess_code" = 401 ] || bail "expected 401 from GET /session (nobody signed in)"
echo "$sess" | grep -q '"provider":"dishnet"' || bail "the session route does not name the dishnet provider"
echo "$sess" | grep -q '"can_authenticate":true' || bail "the provider cannot authenticate anybody (can_authenticate is not true)"
echo "$sess" | grep -q '"mode":"credentials"' || bail "the login surface is not a credentials form"
login_http=$(curl -s -o "$B" -w '%{http_code}' -H 'Content-Type: application/json' -d '{"username":"nobody","password":"wrong"}' http://127.0.0.1:8099/api/v1/admin/session || true)
login_body=$(cat "$B")
echo "POST /api/v1/admin/session over plain HTTP → $login_http $login_body"
[ "$login_http" = 403 ] && echo "$login_body" | grep -q insecure_transport \
  || bail "expected 403 insecure_transport over plain HTTP — the real provider must refuse to issue a session here"
echo "ok: no role picker, no session over plain HTTP — the SSH-tunnel path can load the sign-in page but cannot sign in (by design, docs/122 D-8)"

step "3/6 preflight doctor in PRODUCTION posture (no --disposable) — must report 0 blockers"
RUN="docker run --rm --network dnb-staging -v $APP/app:/app:ro -w /app --env-file $APP/env/runtime.env --env-file $APP/env/secrets.docker.env"
DOC="$RUN --env-file $APP/env/install.env -e DN_STAFF_IDENTITY=dishnet -e DN_TRUSTED_PROXY=$TRUSTED -e DN_PORTAL_ORIGIN=$ORIGIN"
doc_out=$($DOC "$IMG" php plugin/bin/plugin.php doctor 2>&1) || true
echo "$doc_out"
echo "$doc_out" | grep -qE '[0-9]+ checks: .*, 0 blocker' || bail "the doctor reports a blocker in production posture — read its lines above"
[ "$staff_rows" = 0 ] && echo "(the WARN 'DishNet staff on record: none' is expected here — the first administrator is created in step 5, after the proof)"

step "4/6 remove the stop-gap basic auth from the Traefik route, then PROVE the trusted-proxy address through Traefik"
if [ "$HAS_AUTH" = 1 ]; then
  NEW=$(mktemp "$EV/dnb-staging.yml.XXXXXX")
  sed -e 's/"dnb-staging-auth", //' \
      -e '/^    dnb-staging-auth:$/,/^          - "/d' \
      -e "1a\\
# docs/122 ($TS): HTTP basic auth REMOVED — the real DishNet staff login (bcrypt + TOTP,\\
# migration 026) guards the panel now; the rate limit and headers stay." "$ROUTE" > "$NEW"
  grep -q 'dnb-staging-auth' "$NEW" && { rm -f "$NEW"; bail "basic auth could not be removed cleanly from the route"; }
  grep -q 'dnb-staging-ratelimit' "$NEW" && grep -q 'dnb-staging-headers' "$NEW" && grep -q 'certResolver: letsencrypt' "$NEW" \
    || { rm -f "$NEW"; bail "the edited route lost a middleware or the certificate resolver"; }
  if command -v python3 >/dev/null 2>&1 && python3 -c 'import yaml' 2>/dev/null; then
    python3 - "$NEW" <<'PY' || { rm -f "$NEW"; bail "the edited route file is not valid YAML"; }
import sys, yaml
d = yaml.safe_load(open(sys.argv[1]))
r = d['http']['routers']['dnb-staging']; m = d['http']['middlewares']
assert 'dnb-staging-auth' not in m and 'dnb-staging-auth' not in r['middlewares']
for name in r['middlewares']:
    assert name in m, name
assert d['http']['services']['dnb-staging']['loadBalancer']['servers'][0]['url'] == 'http://172.17.0.1:8099'
print('  yaml: ok —', ', '.join(r['middlewares']))
PY
  else
    echo "  (python3-yaml not available on this host; relying on Traefik's own reload below)"
  fi
  chmod 0644 "$NEW"; cp "$NEW" "$ROUTE.tmp" && mv "$ROUTE.tmp" "$ROUTE"; rm -f "$NEW"; ROUTE_CHANGED=1
  echo "route rewritten without basic auth; previous copy at $EV/staff-login-$TS.route.before"
else
  echo "route unchanged: it already carries no basic auth"
fi
i=0; until curl -sk --resolve "$HOST:443:127.0.0.1" "https://$HOST/api/v1/admin/session" 2>/dev/null | grep -q '"provider":"dishnet"'; do
  i=$((i+1)); [ "$i" -le 30 ] || bail "after 30 s Traefik still does not hand the session route to the API without basic auth"; sleep 1
done
echo "through Traefik after ${i}s: GET /api/v1/admin/session answers the API's own 401 (provider dishnet) — basic auth is gone"
# probe: a sign-in attempt with a wrong password, through Traefik. Sets P_CODE,
# P_BODY, and P_ADDRS = the address(es) the API accepted new connections from
# while it ran — read off the "Accepted" lines that appeared during the probe.
probe() {
  n0=$(accepted_addrs | wc -l | tr -d ' ')
  P_CODE=$(curl -sk -o "$B" -w '%{http_code}' --resolve "$HOST:443:127.0.0.1" -H 'Content-Type: application/json' -H "Origin: $ORIGIN" \
           -d '{"username":"nobody","password":"wrong"}' "https://$HOST/api/v1/admin/session" || true)
  P_BODY=$(cat "$B"); sleep 1
  P_ADDRS=$(accepted_addrs | tail -n +"$((n0+1))" | sort -u | tr '\n' ' ')
  echo "through Traefik: POST /api/v1/admin/session, wrong password → $P_CODE $P_BODY  (arrived at the API from: ${P_ADDRS:-no new connection logged})"
}
probe
if [ "$P_CODE" = 403 ] && echo "$P_BODY" | grep -q insecure_transport; then
  SEEN=$(echo "$P_ADDRS" | tr -d ' ')
  echo "$SEEN" | grep -qE '^[0-9]{1,3}(\.[0-9]{1,3}){3}$' || bail "could not read exactly one source address off the API's log for the attempt (saw: '${P_ADDRS:-none}') — route and container rolled back; paste this output back"
  [ "$SEEN" != "$TRUSTED" ] || bail "the attempt arrived from the trusted address $TRUSTED yet was answered 403 — Traefik did not send X-Forwarded-Proto: https; route and container rolled back; paste this output back"
  echo "the candidate $TRUSTED is not the address Traefik's requests carry: this attempt arrived from $SEEN"
  echo "correcting once: recreating the API with DN_TRUSTED_PROXY=$SEEN"
  TRUSTED=$SEEN
  run_api "$TRUSTED" || bail "could not restart the API with the measured address"
  i=0; until curl -sk --resolve "$HOST:443:127.0.0.1" "https://$HOST/api/v1/admin/session" 2>/dev/null | grep -q '"provider":"dishnet"'; do
    i=$((i+1)); [ "$i" -le 30 ] || bail "Traefik does not reach the recreated API"; sleep 1
  done
  probe
fi
if [ "$P_CODE" = 401 ] && echo "$P_BODY" | grep -q invalid_credentials; then
  echo "PROVED: TLS is recognised from $TRUSTED — a sign-in through Traefik reaches the credential check, and a wrong password is refused uniformly (docs/114 R-8)"
else
  bail "expected 401 invalid_credentials through Traefik; got $P_CODE $P_BODY — route and container rolled back; paste this output back"
fi
if [ "$TRUSTED" = "$NGW" ]; then
  echo "note: $TRUSTED is the dnb-staging bridge gateway (docs/122 A.1)"
else
  echo "WARNING: $TRUSTED is not the bridge gateway ($NGW). If it belongs to the Traefik task it can change when Traefik restarts; if every sign-in then answers 403 insecure_transport, re-run this command — it re-measures."
fi
T=$(docker ps --format '{{.Names}}' | grep -i traefik | head -1 || true)
[ -n "$T" ] && { docker logs "$T" --since 3m 2>&1 | grep -iE 'dnb-staging|portal-staging' | grep -iE 'error|invalid' | head -3 || true; }

step "5/6 the first DishNet administrator (staff:bootstrap — once; the password is shown on the terminal only)"
staff_rows=$(docker exec dnb-staging-postgres psql -U postgres -d dnb -Atc "SELECT count(*) FROM mt_staff")
if [ "$staff_rows" = 0 ]; then
  out=$($RUN "$IMG" php plugin/bin/plugin.php staff:bootstrap "$STAFF_USER" --display "$STAFF_DISPLAY" 2>&1) \
    || { echo "$out" | grep -v -A2 'ONE-TIME PASSWORD'; bail "staff:bootstrap failed — route and container rolled back, so the panel is back behind basic auth"; }
  pw=$(echo "$out" | awk '/ONE-TIME PASSWORD/{f=1; next} f && NF {print; exit}' | tr -d ' ')
  if ! echo "$pw" | grep -qE '^[a-z2-9]{6}(-[a-z2-9]{6}){3}$'; then
    # Never lose the only administrator's password: the whole output, which
    # carries it, goes where the box would have gone — never into this log.
    echo "the password could not be picked out of the bootstrap output; the WHOLE output goes to your terminal instead (it carries the password)"
    tty_out "$out" "$EV/staff-login-$TS.password.txt"
    unset pw out
  else
    # The log gets every line of the bootstrap output except the password's.
    echo "$out" | awk '/ONE-TIME PASSWORD/{print "  ONE-TIME PASSWORD: (on your terminal, not in this log)"; skip=1; next} skip>0 && NF==0 {next} skip>0 {skip--; next} {print}'
    tty_out "
  ==================================================================
    DishNet staff sign-in for https://$HOST/
    username:           $STAFF_USER
    one-time password:  $pw
    Shown once; stored nowhere. Sign in, set up your authenticator
    app when the panel asks (it shows a setup key), then change this
    password under My account.
    Do NOT paste this box back into the chat.
  ==================================================================
" "$EV/staff-login-$TS.password.txt"
    unset pw out
  fi
else
  echo "skipped: $staff_rows staff row(s) already exist — the first administrator was created earlier; nothing is added"
fi

step "6/6 result"
# The widening docs/122 A.1 describes, MEASURED rather than asserted: does a
# host-local client on the loopback publish share the trusted address?
w_code=$(curl -s -o "$B" -w '%{http_code}' -H 'X-Forwarded-Proto: https' -H 'Content-Type: application/json' -d '{"username":"nobody","password":"wrong"}' http://127.0.0.1:8099/api/v1/admin/session || true)
case $w_code in
  401) echo "MEASURED: a host-local client asserting X-Forwarded-Proto: https reaches the credential check ($w_code) — loopback connections carry the trusted address, so the docs/122 A.1 widening applies on this host (such a client still needs a valid password and code)";;
  403) echo "MEASURED: a host-local client asserting X-Forwarded-Proto: https is refused ($w_code) — loopback connections do not carry the trusted address; the docs/122 A.1 widening does not apply on this host";;
  *)   echo "loopback X-Forwarded-Proto probe answered $w_code $(cat "$B") — record it";;
esac
rm -f "$B"
docker inspect -f '{{.Name}}|{{.State.Status}}|{{.State.StartedAt}}|{{.RestartCount}}' $(docker ps -aq) | sort > "$EV/staff-login-$TS.after"
changed=$(comm -13 "$EV/staff-login-$TS.before" "$EV/staff-login-$TS.after" | cut -d'|' -f1 | tr -d '/' | sort -u | tr '\n' ' ')
shown=${changed% }; echo "containers whose status/StartedAt/RestartCount changed: ${shown:-none} (expected: dnb-staging-api only)"
[ "$changed" = "dnb-staging-api " ] || echo "WARNING: another container changed state during this run: ${changed:-none}. The staff-login switch passed every control and stays in place; check what else changed and paste this output back."
echo "staff rows now: $(docker exec dnb-staging-postgres psql -U postgres -d dnb -Atc 'SELECT count(*) FROM mt_staff')"
echo
echo "=== REAL STAFF LOGIN ON STAGING $(date -u +%FT%TZ): provider dishnet, trusted proxy $TRUSTED (proved), portal origin $ORIGIN, TOTP required, basic auth removed ==="
echo "Sign in at https://$HOST/ as $STAFF_USER with the one-time password shown on your terminal (not in this log)."
echo "First sign-in: the panel asks you to set up an authenticator app (Google Authenticator, Aegis, 1Password …) from a setup key it shows once; then change the password under My account."
echo "Rollback to the demonstration identity + basic auth: re-run the stage-2 command (scripts/dnb-staging-stage2.sh) — it recreates the API with the development identity and rewrites the route."
echo "Paste this whole output back; leave nothing out — the password box on your terminal is NOT part of it."
