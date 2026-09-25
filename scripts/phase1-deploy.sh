#!/usr/bin/env bash
#
# phase1-deploy.sh — deploy plugin 5.18.37 (customer-login audit, Phase 1) with
# before-evidence, a backup, the documented deploy, and the safe smoke tests.
#
# Run as root on the server, in one sitting, and send back THE LOG FILE
# (never a copy of the terminal):
#
#   cd /opt/dishnet && git pull origin claude/study-this-jhe2eg \
#     && mkdir -p /root/dnb-phase1 \
#     && bash scripts/phase1-deploy.sh 2>&1 | tee /root/dnb-phase1/deploy-$(date -u +%Y%m%dT%H%M%SZ).log
#
# Options
#   --after-only         the deploy already happened: run only the smoke tests
#   --webhook-only       the read-only webhook inspection alone (stage D), nothing else
#   --login-only         with --login: the customer sign-in flow alone (page, code, consent,
#                        portal, logout, no code in any log), then stage D; no other smoke test
#   --login <+2567…>     also sign in as a customer with a number YOU control
#                        (it receives one WhatsApp code; the code is typed here
#                        and never printed). Never a customer's number.
#   --plugin-base <url>  the public URL of public.php, if the derived one is wrong
#
# What it never does: configure crm_webhook_key, send a message to a customer,
# post a payment, change a customer record, print a token, code or secret. The
# only writes are the documented deploy (scripts/deploy-hybrid.sh) and a backup
# under /root/dnb-phase1/. Every read of the plugin's database works on a COPY,
# as the database's owner, so the live file never gains a root-owned sidecar.
#
# Stages:  A before-evidence + backup → GO/NO-GO   B the documented deploy
#          C smoke tests (HTTP against the live plugin)   D webhook, read-only
#          E receipt/delivery links   F summary
set -uo pipefail
umask 077

PLUGIN="dishnet-hybrid-sudan"
CONTAINER="${UCRM_CONTAINER:-ucrm}"
EXPECTED_PLUGIN_COMMIT="68f4eeb"      # 5.18.37 — the plugin-scoped commit deploy-hybrid.sh records
EXPECTED_VERSION="5.18.37"
REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SRC="$REPO/$PLUGIN"
TS="$(date -u +%Y%m%dT%H%M%SZ)"
OUT="/root/dnb-phase1"; mkdir -p "$OUT"; chmod 700 "$OUT"
SNIP="$OUT/.snippets-$$"; mkdir -p "$SNIP"; chmod 700 "$SNIP"
trap 'rm -rf "$SNIP"' EXIT

AFTER_ONLY=0; WEBHOOK_ONLY=0; LOGIN_ONLY=0; LOGIN_PHONE=""; PLUGIN_BASE="${PLUGIN_BASE:-}"
while [ $# -gt 0 ]; do
  case "$1" in
    --after-only) AFTER_ONLY=1 ;;
    --webhook-only) AFTER_ONLY=1; WEBHOOK_ONLY=1 ;;
    --login-only) AFTER_ONLY=1; LOGIN_ONLY=1 ;;
    --login) LOGIN_PHONE="${2:-}"; shift ;;
    --plugin-base) PLUGIN_BASE="${2:-}"; shift ;;
    *) echo "unknown option: $1" >&2; exit 64 ;;
  esac; shift
done

PASS=0; FAIL=0; NOTE=0
ok()   { PASS=$((PASS+1)); printf '  ok    %s\n' "$*"; }
bad()  { FAIL=$((FAIL+1)); printf '  FAIL  %s\n' "$*"; }
note() { NOTE=$((NOTE+1)); printf '  note  %s\n' "$*"; }
hdr()  { printf '\n== %s ==\n' "$*"; }
stop() { printf '\n  STOP: %s\n  Nothing further was done. Send the log file.\n' "$*"; exit 1; }

# ── HTTP helper: status code and body; the request's headers are never echoed ─
# $1 method, $2 url, $3 body ('' for none), $4… extra -H headers
CURL_EXTRA=()
HTTP_CODE=""; HTTP_BODY=""
http() {
  local m="$1" u="$2" b="$3"; shift 3
  local hdrs=(); local h; for h in "$@"; do hdrs+=(-H "$h"); done
  local args=(-sS -o "/tmp/dnb_body.$$" -w '%{http_code}' -X "$m" -H 'Content-Type: application/json')
  [ -n "$b" ] && args+=(--data "$b")
  local r
  r="$(curl ${CURL_EXTRA[@]+"${CURL_EXTRA[@]}"} "${args[@]}" ${hdrs[@]+"${hdrs[@]}"} "$u" 2>/dev/null)" || r="000"
  HTTP_CODE="$r"; HTTP_BODY="$(head -c 4000 "/tmp/dnb_body.$$" 2>/dev/null || true)"
  rm -f "/tmp/dnb_body.$$"
}
# One string field out of the plugin's flat JSON answers: {"status":"error","message":"…"}
jfield() { printf '%s' "$1" | grep -o "\"$2\":\"[^\"]*\"" | head -1 | sed -E "s/^\"$2\":\"//; s/\"$//"; }

hdr "Phase 1 (5.18.37) — $TS — $(hostname)"
command -v docker >/dev/null 2>&1 || stop "docker not on PATH"
command -v curl   >/dev/null 2>&1 || stop "curl not on PATH"
[ -f "$SRC/manifest.json" ] || stop "no plugin at $SRC"

# ═════════════════════════════════════════════════════════════════════════════
hdr "A. Before-evidence (read-only) and backup"
# ═════════════════════════════════════════════════════════════════════════════
HEAD_REPO="$(git -C "$REPO" rev-parse --short HEAD 2>/dev/null || echo unknown)"
HEAD_PLUGIN="$(git -C "$REPO" log -1 --format=%h -- "$PLUGIN" 2>/dev/null || echo unknown)"
DIRTY="$(git -C "$REPO" status --porcelain --untracked-files=no 2>/dev/null | wc -l | tr -d ' ')"
VERSION_SRC="$(grep -o '"version": *"5[^"]*"' "$SRC/manifest.json" | head -1 | sed -E 's/.*"(5[^"]*)".*/\1/')"
echo "  checkout        $REPO"
echo "  repo HEAD       $HEAD_REPO"
echo "  plugin commit   $HEAD_PLUGIN   (expected $EXPECTED_PLUGIN_COMMIT)"
echo "  plugin version  ${VERSION_SRC:-?}   (expected $EXPECTED_VERSION)"
echo "  tracked edits   $DIRTY"
[ "$HEAD_PLUGIN" = "$EXPECTED_PLUGIN_COMMIT" ] || stop "the checkout's plugin commit is $HEAD_PLUGIN, not $EXPECTED_PLUGIN_COMMIT — pull the branch, or this script is for another build"
[ "$VERSION_SRC" = "$EXPECTED_VERSION" ]        || stop "manifest.json says ${VERSION_SRC:-?}, expected $EXPECTED_VERSION"
[ "$DIRTY" = "0" ]                              || stop "the checkout has $DIRTY locally edited tracked files — deploying would ship edits nobody reviewed"

MOUNT="$(docker inspect "$CONTAINER" --format '{{range .Mounts}}{{if eq .Destination "/data"}}{{.Source}}{{end}}{{end}}' 2>/dev/null || true)"
[ -n "$MOUNT" ] || stop "container '$CONTAINER' has no /data mount"
DEST="$MOUNT/ucrm/data/plugins/$PLUGIN"
IN_CONTAINER="/data/ucrm/data/plugins/$PLUGIN"
[ -f "$DEST/manifest.json" ] || stop "no installed plugin at $DEST"
LIVE_BEFORE="$(docker exec "$CONTAINER" cat "$IN_CONTAINER/.deployed-commit" 2>/dev/null | tail -n1 | tr -cd '0-9a-f')"
LIVE_VERSION="$(grep -o '"version": *"5[^"]*"' "$DEST/manifest.json" | head -1 | sed -E 's/.*"(5[^"]*)".*/\1/')"
echo "  serves          $DEST"
echo "  live commit     ${LIVE_BEFORE:-unknown}   (this is the rollback commit)"
echo "  live version    ${LIVE_VERSION:-?}"
echo "  --check says:"; bash "$REPO/scripts/deploy-hybrid.sh" --check 2>&1 | sed 's/^/     /'
docker exec "$CONTAINER" php -v >/dev/null 2>&1 || stop "no php inside the container — the read-only checks need it"

# The data directory, resolved the way the plugin resolves it (ucrm.json first).
PDD_IN="$(docker exec "$CONTAINER" php -r '$u=@json_decode((string)@file_get_contents($argv[1]),true); echo rtrim((string)($u["pluginDataDir"]??""),"/");' "$IN_CONTAINER/ucrm.json" 2>/dev/null || true)"
if [ -z "$PDD_IN" ]; then
  if docker exec "$CONTAINER" test -f "/data/ucrm/data/plugins/.$PLUGIN-data/plugin.sqlite3"; then PDD_IN="/data/ucrm/data/plugins/.$PLUGIN-data";
  else PDD_IN="$IN_CONTAINER/data"; fi
fi
DATA_HOST="$MOUNT${PDD_IN#/data}"
DB_IN="$PDD_IN/plugin.sqlite3"
docker exec "$CONTAINER" test -f "$DB_IN" || stop "no database at $DB_IN (container path) — the data directory was not resolved"
DB_OWNER="$(docker exec "$CONTAINER" stat -c '%u:%g' "$DB_IN")"
echo "  data dir        $PDD_IN (container)  =  $DATA_HOST (host)"
echo "  database owner  $DB_OWNER (every read below runs as this user, on a copy)"
RO="/tmp/dnb-phase1-ro-$$"; RCPT=""
# PHP inside the container: the plugin directory as cwd, the database owner as user, the facts it needs as environment.
CPHP() { docker exec -i -u "$DB_OWNER" -w "$IN_CONTAINER" -e "RO_DB=$RO/db.sqlite3" -e "RO_DB2=$RO/db2.sqlite3" \
           -e "WH_LAST_ID=${WH_LAST_ID:-0}" -e "PDD_IN=$PDD_IN" -e "RCPT_FILE=${RCPT:-}" "$CONTAINER" php "$@"; }

# Snapshot of the webhook log's newest id, so stage D can count what the smoke tests caused.
WH_LAST_ID="$(docker exec "$CONTAINER" php -r '$l=@json_decode((string)@file_get_contents($argv[1]),true); echo is_array($l)&&$l?(int)($l[0]["id"]??0):0;' "$PDD_IN/webhook_log.json" 2>/dev/null || echo 0)"
echo "  webhook_log     newest id $WH_LAST_ID"

if [ "$AFTER_ONLY" = "0" ] && [ "$LIVE_BEFORE" = "$EXPECTED_PLUGIN_COMMIT" ]; then
  note "the container already serves $EXPECTED_PLUGIN_COMMIT — skipping the deploy, running the smoke tests"
  AFTER_ONLY=1
fi

BK=""
if [ "$AFTER_ONLY" = "0" ]; then
  BK="$OUT/backup-$TS"; mkdir -p "$BK"; chmod 700 "$BK"
  DONE_DIRS=""
  for d in "$DATA_HOST" "$DEST/data" "$MOUNT/ucrm/data/plugins/.$PLUGIN-data"; do
    [ -d "$d" ] || continue
    case " $DONE_DIRS " in *" $d "*) continue;; esac; DONE_DIRS="$DONE_DIRS $d"
    n="$(basename "$d" | tr -c 'A-Za-z0-9._\n-' '_')"; [ -n "$n" ] || n="data-$(date -u +%s)"
    [ -e "$BK/$n.tar.gz" ] && n="$n-$(date -u +%H%M%S)"
    if tar -C "$(dirname "$d")" -czf "$BK/$n.tar.gz" "$(basename "$d")" 2>/dev/null; then
      ok "backed up $d → $BK/$n.tar.gz ($(du -h "$BK/$n.tar.gz" | cut -f1))"
    else bad "backup of $d failed"; fi
  done
  cp "$DEST/.deployed-commit" "$BK/deployed-commit.before" 2>/dev/null || true
  cp "$DEST/config.json" "$BK/config.json.before" 2>/dev/null || true
  chmod -R go-rwx "$BK"
  if [ -x "$REPO/scripts/verify-uisp-health.sh" ]; then
    if timeout 120 bash "$REPO/scripts/verify-uisp-health.sh" > "$BK/health-before.txt" 2>&1; then ok "UISP health recorded → $BK/health-before.txt"; else note "verify-uisp-health.sh exited non-zero (see $BK/health-before.txt)"; fi
  fi
  [ "$FAIL" = "0" ] || stop "NO-GO: the backup did not complete"
  echo; echo "  GO — evidence recorded, backup in $BK"
  echo "  Rollback at any time:  cd $REPO && git checkout ${LIVE_BEFORE:-<live commit>} && bash scripts/deploy-hybrid.sh"

  # ═══════════════════════════════════════════════════════════════════════════
  hdr "B. The documented deploy (scripts/deploy-hybrid.sh)"
  # ═══════════════════════════════════════════════════════════════════════════
  printf '  Type DEPLOY to deploy %s (%s) over live %s, anything else to stop: ' "$EXPECTED_VERSION" "$EXPECTED_PLUGIN_COMMIT" "${LIVE_BEFORE:-?}"
  read -r ANSWER </dev/tty || ANSWER=""
  [ "$ANSWER" = "DEPLOY" ] || stop "not confirmed"
  DEPLOY_STARTED="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
  bash "$REPO/scripts/deploy-hybrid.sh" 2>&1 | sed 's/^/     /'; RC=${PIPESTATUS[0]}
  if [ "$RC" = "2" ]; then
    note "the container was still restarting; waiting for it"
    for i in 1 2 3 4 5 6 7 8 9 10 11 12; do
      sleep 10
      if bash "$REPO/scripts/deploy-hybrid.sh" --check 2>&1 | grep -q 'Up to date'; then RC=0; break; fi
    done
  fi
  LIVE_AFTER="$(docker exec "$CONTAINER" cat "$IN_CONTAINER/.deployed-commit" 2>/dev/null | tail -n1 | tr -cd '0-9a-f')"
  if [ "$RC" = "0" ] && [ "$LIVE_AFTER" = "$EXPECTED_PLUGIN_COMMIT" ]; then ok "container serves $LIVE_AFTER"
  else stop "deploy did not verify (rc=$RC, live=${LIVE_AFTER:-?}). Roll back: cd $REPO && git checkout ${LIVE_BEFORE:-<commit>} && bash scripts/deploy-hybrid.sh"; fi
else
  DEPLOY_STARTED="$(date -u -d '-10 minutes' +%Y-%m-%dT%H:%M:%SZ 2>/dev/null || date -u +%Y-%m-%dT%H:%M:%SZ)"
  LIVE_AFTER="$LIVE_BEFORE"
  [ "$LIVE_AFTER" = "$EXPECTED_PLUGIN_COMMIT" ] && ok "live commit is $LIVE_AFTER" || stop "the container serves ${LIVE_AFTER:-?}, not $EXPECTED_PLUGIN_COMMIT"
fi

# ═════════════════════════════════════════════════════════════════════════════
if [ "$WEBHOOK_ONLY" = "0" ]; then
hdr "C. Smoke tests over HTTP (no customer is contacted, nothing is posted)"
# ═════════════════════════════════════════════════════════════════════════════
if [ -z "$PLUGIN_BASE" ]; then
  # The plugin's own rule: crm_public_url (config.json) → pluginPublicUrl → ucrmPublicUrl.
  PLUGIN_BASE="$(docker exec "$CONTAINER" php -r '
    $r=$argv[1]; $pdd=$argv[2]; $u=@json_decode((string)@file_get_contents($r."/ucrm.json"),true)?:[];
    $over="";
    foreach ([$r."/data/config.json", $pdd."/config.json", $pdd."/kyc_config.json"] as $f) { $c=@json_decode((string)@file_get_contents($f),true)?:[]; $v=rtrim(trim((string)($c["crm_public_url"]??"")),"/"); if($v!==""){ $over=$v; break; } }
    if($over!==""){ echo $over."/crm/_plugins/".basename($r)."/public.php"; exit; }
    if(!empty($u["pluginPublicUrl"])){ echo rtrim($u["pluginPublicUrl"],"/"); exit; }
    $b=rtrim((string)($u["ucrmPublicUrl"]??""),"/"); $b=preg_replace("#/crm$#","",$b); echo $b?$b."/crm/_plugins/".basename($r)."/public.php":"";' "$IN_CONTAINER" "$PDD_IN" 2>/dev/null || true)"
fi
[ -n "$PLUGIN_BASE" ] || stop "could not derive the plugin's public URL — re-run with --plugin-base https://<host>/crm/_plugins/$PLUGIN/public.php"
echo "  plugin URL      $PLUGIN_BASE"
http GET "$PLUGIN_BASE?page=customer_login" ''
if [ "$HTTP_CODE" = "000" ]; then CURL_EXTRA=(--insecure); note "TLS verification failed for that address; retrying with --insecure (report this)"; http GET "$PLUGIN_BASE?page=customer_login" ''; fi

# C1 the customer portal opens
if [ "$HTTP_CODE" = "200" ] && printf '%s' "$HTTP_BODY" | grep -q 'step-phone' && printf '%s' "$HTTP_BODY" | grep -q 'DishNet'; then ok "C1 customer_login page: 200 with the sign-in form"; else bad "C1 customer_login page: $HTTP_CODE"; fi

# The staff tokens, read from a COPY of the store and never printed (used by C4 and by the sign-in flow's lookup).
if docker exec -u "$DB_OWNER" "$CONTAINER" sh -c "mkdir -p '$RO' && cp '$DB_IN' '$RO/db.sqlite3' && ( [ -f '$DB_IN-wal' ] && cp '$DB_IN-wal' '$RO/db.sqlite3-wal' || true )"; then
  ok "C4 read-only copy of the store taken inside the container"
else bad "C4 could not copy the store"; fi
cat > "$SNIP/tokens.php" <<'PHP'
<?php
// Prints "<admin token or -> <non-admin token or ->" — consumed by the shell, never echoed.
$db = new PDO('sqlite:' . getenv('RO_DB'), null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$admin = ''; $staff = ''; $now = time();
foreach ($db->query("SELECT data FROM retailers") as $row) {
    $r = json_decode((string)$row['data'], true); if (!is_array($r)) continue;
    if (empty($r['is_active']) || empty($r['api_token'])) continue;
    $issued = (int)($r['token_issued_at'] ?? 0);
    if ($issued > 0 && ($now - $issued) > 90 * 86400) continue;          // expired: tokenAuth would refuse (and rewrite) it
    if (!empty($r['is_admin'])) { if ($admin === '') $admin = (string)$r['api_token']; }
    elseif ($staff === '')       { $staff = (string)$r['api_token']; }
}
echo ($admin === '' ? '-' : $admin), ' ', ($staff === '' ? '-' : $staff), "\n";
PHP
TOKENS="$(CPHP < "$SNIP/tokens.php" 2>/dev/null || echo '- -')"
read -r ADMIN_TOKEN STAFF_TOKEN <<< "$TOKENS"; unset TOKENS
[ "${ADMIN_TOKEN:-}" = "-" ] && ADMIN_TOKEN=""; [ "${STAFF_TOKEN:-}" = "-" ] && STAFF_TOKEN=""

if [ "$LOGIN_ONLY" = "0" ]; then
# C2 anonymous access to the moved and removed staff/debug actions
MOVED=(data_dir_info crm_debug handover_audit payment_key_log check_retailer_key payment_push_log payment_catchup_log
       staff_login_lookup staff_otp_log app_debug_list
       backup_download cron_trigger test_payment_post debug_payment_sync view_error_log webhook_log data_diagnostic find_lost_data cron_status
       voidable_payments patch_ucrm_payment hq_debug_collections check_duplicate_payments
       quote_log kyc_debug sync_ucrm_products ucrm_quotes)
c2fail=0
for a in "${MOVED[@]}"; do
  http GET "$PLUGIN_BASE?page=api&action=$a" ''
  if [ "$HTTP_CODE" = "401" ] && [ "$(jfield "$HTTP_BODY" message)" = "Unauthorized." ]; then :; else c2fail=$((c2fail+1)); echo "     $a → $HTTP_CODE $(jfield "$HTTP_BODY" message)"; fi
done
[ "$c2fail" = "0" ] && ok "C2 all ${#MOVED[@]} moved staff/debug actions answer 401 Unauthorized to an anonymous caller" || bad "C2 $c2fail moved actions did not answer 401"
c2b=0
for a in app_debug_lookup app_debug_log app_debug_schema app_debug_send; do
  http POST "$PLUGIN_BASE?page=api&action=$a" '{"phone":"+256700000000"}'
  [ "$HTTP_CODE" = "401" ] || { c2b=$((c2b+1)); echo "     $a → $HTTP_CODE"; }
done
[ "$c2b" = "0" ] && ok "C2 the four removed app_debug_* actions: 401 for everyone" || bad "C2 $c2b removed actions still answer"
http GET "$PLUGIN_BASE?page=api&action=backup_download&key=not-consulted" ''
[ "$HTTP_CODE" = "401" ] && ok "C2 backup_download with a key parameter: 401 (the constant key is gone)" || bad "C2 backup_download&key → $HTTP_CODE"
http GET "$PLUGIN_BASE?page=crm_debug&action=payment_methods" ''
if printf '%s' "$HTTP_BODY" | grep -q '"status":"success"'; then bad "C2 ?page=crm_debug still answers a diagnostic"; else ok "C2 ?page=crm_debug answers no diagnostic ($HTTP_CODE)"; fi

# C3 the pre-auth doors answer for themselves
http POST "$PLUGIN_BASE?page=api&action=login" '{"email":"nobody-phase1@example.invalid","password":"wrong-on-purpose"}'
[ "$HTTP_CODE" = "401" ] && [ "$(jfield "$HTTP_BODY" message)" = "Invalid email or password." ] && ok "C3 staff login door: wrong password → its own 401" || bad "C3 login → $HTTP_CODE $(jfield "$HTTP_BODY" message)"
http GET "$PLUGIN_BASE?page=api&action=serve_receipt_pdf" ''
[ "$HTTP_CODE" = "400" ] && ok "C3 serve_receipt_pdf without a token: 400" || bad "C3 serve_receipt_pdf → $HTTP_CODE"
http POST "$PLUGIN_BASE?page=api&action=app_send_otp" '{"phone":""}'
[ "$HTTP_CODE" = "400" ] && ok "C3 app_send_otp with an empty body: 400" || bad "C3 app_send_otp empty → $HTTP_CODE"
http GET "$PLUGIN_BASE?page=api&action=no_such_action_phase1" ''
[ "$HTTP_CODE" = "401" ] && ok "C3 an unknown action is stopped by the guard (401, no enumeration)" || bad "C3 unknown action → $HTTP_CODE"

# C4 administrator positive controls
if [ -n "$ADMIN_TOKEN" ]; then
  ok "C4 an administrator token is on file (length ${#ADMIN_TOKEN}, not printed)"
  A="Authorization: Bearer $ADMIN_TOKEN"
  http GET "$PLUGIN_BASE?page=api&action=data_dir_info" '' "$A";  [ "$HTTP_CODE" = "200" ] && ok "C4 admin data_dir_info: 200" || bad "C4 admin data_dir_info → $HTTP_CODE"
  http GET "$PLUGIN_BASE?page=api&action=cron_status" '' "$A";    [ "$HTTP_CODE" = "200" ] && ok "C4 admin cron_status: 200" || bad "C4 admin cron_status → $HTTP_CODE"
  http GET "$PLUGIN_BASE?page=api&action=staff_login_lookup&email=nobody-phase1@example.invalid" '' "$A"
  if [ "$HTTP_CODE" = "200" ] && printf '%s' "$HTTP_BODY" | grep -q '"accounts":\[\]'; then ok "C4 admin staff_login_lookup: 200, no account for an invented e-mail"; else bad "C4 admin staff_login_lookup → $HTTP_CODE"; fi
  http GET "$PLUGIN_BASE?page=api&action=staff_otp_log&email=nobody-phase1@example.invalid" '' "$A"
  if [ "$HTTP_CODE" = "200" ] && ! printf '%s' "$HTTP_BODY" | grep -q '"preview"'; then ok "C4 admin staff_otp_log: 200 and no preview column"; else bad "C4 admin staff_otp_log → $HTTP_CODE"; fi
  http GET "$PLUGIN_BASE?page=api&action=app_debug_list" '' "$A"; [ "$HTTP_CODE" = "200" ] && ok "C4 admin app_debug_list: 200" || bad "C4 admin app_debug_list → $HTTP_CODE"
  http GET "$PLUGIN_BASE?page=api&action=test_payment_post&collection_id=1" '' "$A"
  if [ "$HTTP_CODE" = "400" ] && printf '%s' "$HTTP_BODY" | grep -q 'confirm='; then ok "C4 admin test_payment_post without confirm: refused (400) before any lookup — nothing posted"; else bad "C4 test_payment_post without confirm → $HTTP_CODE"; fi
  http GET "$PLUGIN_BASE?page=api&action=app_debug_lookup&phone=%2B256700000000" '' "$A"
  [ "$HTTP_CODE" = "404" ] && ok "C4 even an administrator finds no app_debug_lookup (404)" || bad "C4 admin app_debug_lookup → $HTTP_CODE"
  unset A
else note "C4 no live administrator token on file — run the positive controls signed in from a browser (URLs at the end)"; fi
if [ -n "$STAFF_TOKEN" ]; then
  S="Authorization: Bearer $STAFF_TOKEN"
  http GET "$PLUGIN_BASE?page=api&action=data_dir_info" '' "$S";   [ "$HTTP_CODE" = "403" ] && ok "C4 non-admin data_dir_info: 403 Admin only" || bad "C4 non-admin data_dir_info → $HTTP_CODE"
  http GET "$PLUGIN_BASE?page=api&action=backup_download" '' "$S"; [ "$HTTP_CODE" = "403" ] && ok "C4 non-admin backup_download: 403" || bad "C4 non-admin backup_download → $HTTP_CODE"
  http GET "$PLUGIN_BASE?page=api&action=cron_status" '' "$S";     [ "$HTTP_CODE" = "200" ] && ok "C4 non-admin cron_status: 200 (any staff, as before)" || bad "C4 non-admin cron_status → $HTTP_CODE"
  unset S
else note "C4 no non-admin token on file — the 403 gate is proved by the test suite, not live"; fi

# C5 the sign-in door: uniform answer, without touching any customer (an invented e-mail sends nothing anywhere)
INV="nobody-phase1-$(head -c 4 /dev/urandom | od -An -tx1 | tr -d ' \n')@example.invalid"
http POST "$PLUGIN_BASE?page=api&action=app_send_otp" "{\"email\":\"$INV\"}"
if [ "$HTTP_CODE" = "200" ] && [ "$(jfield "$HTTP_BODY" message)" = "Code sent via Email." ] && ! printf '%s' "$HTTP_BODY" | grep -qE '"(client_id|debug|found|exists|code)"'; then
  ok "C5 an unknown e-mail gets the uniform 'Code sent via Email.' and no telling field"
else bad "C5 unknown e-mail → $HTTP_CODE $(jfield "$HTTP_BODY" message)"; fi
fi   # LOGIN_ONLY (C2–C5)

# ── The customer sign-in flow with a number the OPERATOR controls ─────────────
# One WhatsApp code goes to that number; the code is typed here (or supplied by
# DN_TEST_CODE in a test harness) and is never printed. Nine points, in order.
if [ -n "$LOGIN_PHONE" ]; then
  echo "  C5 customer sign-in with YOUR number $LOGIN_PHONE (one code goes to it through the configured transport)"
  LOGIN_STARTED="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
  ENC_PHONE="$(printf '%s' "$LOGIN_PHONE" | sed 's/+/%2B/g')"
  if [ -n "$ADMIN_TOKEN" ]; then
    http GET "$PLUGIN_BASE?page=api&action=staff_login_lookup&phone=$ENC_PHONE" '' "Authorization: Bearer $ADMIN_TOKEN"
    if [ "$HTTP_CODE" = "200" ]; then
      NACC="$(printf '%s' "$HTTP_BODY" | grep -o '"accounts":\[[^]]*\]' | grep -o '"id":' | wc -l | tr -d ' ')"
      # the flags are JSON booleans, not strings — read them positionally
      WAS="$(printf '%s' "$HTTP_BODY" | grep -o '"wasender_configured":[a-z]*' | cut -d: -f2)"
      EVO="$(printf '%s' "$HTTP_BODY" | grep -o '"evolution_configured":[a-z]*' | cut -d: -f2)"
      DRY="$(printf '%s' "$HTTP_BODY" | grep -o '"dry_run_mode":[a-z]*' | cut -d: -f2)"
      [ "${NACC:-0}" -ge 1 ] && ok "L1 the number matches ${NACC} customer account(s) (the operator's own)" || bad "L1 the number matches no customer account — the sign-in cannot succeed for it"
      echo "  transport       wasender_configured=${WAS:-?}  evolution_configured=${EVO:-?}  dry_run_mode=${DRY:-?}  (the plugin prefers Evolution when configured)"
      [ "${DRY:-false}" = "true" ] && bad "L1 dry_run_mode is ON in production — no message would leave"
    else note "L1 staff_login_lookup → $HTTP_CODE (continuing)"; fi
  else note "L1 no administrator token: transport flags not read"; fi
  http POST "$PLUGIN_BASE?page=api&action=app_send_otp" "{\"phone\":\"$LOGIN_PHONE\"}"
  SENT=0; L2MSG="$(jfield "$HTTP_BODY" message)"
  if [ "$HTTP_CODE" = "200" ] && [ "$L2MSG" = "Code sent via WhatsApp." ]; then ok "L2 app_send_otp: 200 'Code sent via WhatsApp.' (the number was accepted)"; SENT=1
  else
    bad "L2 app_send_otp → $HTTP_CODE $L2MSG"
    case "$L2MSG" in
      *"sender is not configured"*)
        echo "  diagnosis       app_send_otp requires the three WASender keys (wa_plugin_url, wa_app_key, wa_auth_key) to be non-empty before it"
        echo "                  chooses a transport, even when Evolution is the one configured. That check is unchanged from 5.18.36 (git show"
        echo "                  c82e0b9): a configuration finding, not a Phase-1 change; its redesign belongs to Phase 2. Nothing is changed by this run.";;
      *"Too many requests"*)
        echo "  diagnosis       a rate limit answered (per identifier 10/h, per address 30/h; both count in app_otp_rate) — wait an hour and run again";;
    esac
  fi
  # The code is asked for only when the request was accepted; it must be digits (it goes into a JSON body and a grep).
  if [ "$SENT" = "0" ]; then CODE=""; echo "  (no code was sent, so none is asked for)"
  elif [ -n "${DN_TEST_CODE:-}" ]; then CODE="$DN_TEST_CODE"
  else printf '  Type the 6-digit code you received (not shown), or press Enter if none arrived: '; read -rs CODE </dev/tty || CODE=""; echo; fi
  case "$CODE" in *[!0-9]*) echo "  (what was typed is not a numeric code — treated as no code)"; CODE="";; esac
  if [ -z "$CODE" ]; then
    if [ "$SENT" = "0" ]; then bad "L3 no code could have been sent (L2 refused) — nothing further in this flow"
    else bad "L3 the request was accepted but no code arrived — delivery through the configured transport FAILED; read the send record in L8 and the transport's own log; change nothing"; fi
  else
    ok "L3 a code arrived on the phone"
    http POST "$PLUGIN_BASE?page=api&action=app_verify_otp" "{\"phone\":\"$LOGIN_PHONE\",\"code\":\"$CODE\"}"
    CTOK="$(jfield "$HTTP_BODY" token)"
    if [ "$HTTP_CODE" = "200" ] && [ -n "$CTOK" ]; then
      ok "L4 app_verify_otp: 200, a customer token was issued (not printed)"
      C="Authorization: Bearer $CTOK"
      http GET "$PLUGIN_BASE?page=api&action=app_legal_version" ''
      TOS="$(jfield "$HTTP_BODY" tos_version)"; PRIV="$(jfield "$HTTP_BODY" privacy_version)"
      http POST "$PLUGIN_BASE?page=api&action=app_record_consent" "{\"tos_version\":\"$TOS\",\"privacy_version\":\"$PRIV\"}" "$C"
      if [ "$HTTP_CODE" = "200" ] && printf '%s' "$HTTP_BODY" | grep -q '"accepted":true'; then ok "L5 app_record_consent with the token: 200 accepted (versions $TOS / $PRIV)"; else bad "L5 app_record_consent → $HTTP_CODE $(jfield "$HTTP_BODY" message)"; fi
      # The login page itself sends the browser to ?page=customer_portal&view=home&token=<jwt>
      # (a URL token — the current, pre-Phase-2 behaviour, exercised as is, not changed).
      ENC_TOK="$(printf '%s' "$CTOK" | sed 's/+/%2B/g; s|/|%2F|g; s/=/%3D/g')"
      http GET "$PLUGIN_BASE?page=customer_portal&view=home&token=$ENC_TOK" ''
      if [ "$HTTP_CODE" = "200" ] && printf '%s' "$HTTP_BODY" | grep -qi 'DishNet'; then ok "L6 the customer portal page with the token: 200"; else bad "L6 customer_portal page → $HTTP_CODE"; fi
      http GET "$PLUGIN_BASE?page=customer_portal&view=home" ''
      [ "$HTTP_CODE" = "302" ] || [ "$HTTP_CODE" = "200" ] && ok "L6 the portal without a token: $HTTP_CODE (sent back to sign in, as today)" || bad "L6 the portal without a token → $HTTP_CODE"
      unset ENC_TOK
      http GET "$PLUGIN_BASE?page=api&action=app_me" '' "$C"
      if [ "$HTTP_CODE" = "200" ] && printf '%s' "$HTTP_BODY" | grep -q '"status":"success"'; then ok "L6 app_me with the token: 200 (the portal's data layer answers)"; else bad "L6 app_me → $HTTP_CODE"; fi
      http POST "$PLUGIN_BASE?page=api&action=app_logout" '{}' "$C"
      [ "$HTTP_CODE" = "200" ] && ok "L7 app_logout: 200 'Logged out.'" || bad "L7 app_logout → $HTTP_CODE"
      http GET "$PLUGIN_BASE?page=api&action=app_me" '' "$C"
      if [ "$HTTP_CODE" = "401" ] && [ "$(jfield "$HTTP_BODY" message)" = "Token revoked." ]; then ok "L7 the same token after logout: 401 Token revoked (the current, pre-Phase-2 behaviour: the token is blacklisted server-side)"; else bad "L7 the token after logout → $HTTP_CODE $(jfield "$HTTP_BODY" message)"; fi
      unset C
    else bad "L4 app_verify_otp → $HTTP_CODE $(jfield "$HTTP_BODY" message)"; fi
    # L8 the code appears in no log. The code is passed to the container's PHP in the
    # environment for the comparison and is never written anywhere.
    NCL="$(docker logs "$CONTAINER" --since "$LOGIN_STARTED" 2>&1 | grep -c -F -- "$CODE" || true)"
    if [ "${NCL:-0}" = "0" ]; then ok "L8 the code appears nowhere in the container log since $LOGIN_STARTED"
    else bad "L8 the code's digits appear in $NCL container-log line(s) — shown masked below: a six-digit coincidence (an id, a timestamp) is not a leak; a plugin line carrying the code is"
      docker logs "$CONTAINER" --since "$LOGIN_STARTED" 2>&1 | grep -F -- "$CODE" | sed "s/$CODE/******/g" | cut -c1-160 | head -5 | sed "s/^/     /"; fi
    NWL="$(docker exec "$CONTAINER" grep -c -F -- "$CODE" "$PDD_IN/webhook_log.json" 2>/dev/null || true)"
    [ "${NWL:-0}" = "0" ] && ok "L8 the code appears nowhere in webhook_log.json" || bad "L8 the code appears in webhook_log.json"
    docker exec -u "$DB_OWNER" "$CONTAINER" sh -c "mkdir -p '$RO' && cp '$DB_IN' '$RO/db3.sqlite3' && ( [ -f '$DB_IN-wal' ] && cp '$DB_IN-wal' '$RO/db3.sqlite3-wal' || true )" 2>/dev/null || true
    cat > "$SNIP/otplog.php" <<'PHP'
<?php
// Prints "<latest app_otp preview> | <rows containing the code in the audit and login logs>"; the code itself is never printed.
$db = new PDO('sqlite:' . getenv('RO_DB3'), null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$code = (string)getenv('OTP_CODE'); $ident = (string)getenv('OTP_IDENT');
$prev = '(no app_otp row)'; $hits = 0; $sender = '-'; $success = '-'; $http = '-';
try {
    $st = $db->query("SELECT sender, preview, success, http_code FROM notification_audit_log WHERE event = 'app_otp' ORDER BY id DESC LIMIT 1");
    if ($r = $st->fetch(PDO::FETCH_ASSOC)) { $prev = (string)$r['preview']; $sender = (string)$r['sender']; $success = (string)$r['success']; $http = (string)$r['http_code']; }
    $st = $db->prepare("SELECT COUNT(*) FROM notification_audit_log WHERE preview LIKE ? OR error LIKE ?"); $st->execute(["%{$code}%", "%{$code}%"]); $hits += (int)$st->fetchColumn();
} catch (Throwable $e) { $prev = '(notification log unavailable)'; }
try { $st = $db->prepare("SELECT COUNT(*) FROM app_audit_log WHERE details LIKE ?"); $st->execute(["%{$code}%"]); $hits += (int)$st->fetchColumn(); } catch (Throwable $e) {}
try { $st = $db->prepare("SELECT COUNT(*) FROM notification_queue WHERE message LIKE ?"); $st->execute(["%{$code}%"]); $hits += (int)$st->fetchColumn(); } catch (Throwable $e) {}
echo $prev, " | sender={$sender} success={$success} http_code={$http} | hits={$hits}\n";
PHP
    OTPL="$(docker exec -i -u "$DB_OWNER" -w "$IN_CONTAINER" -e "RO_DB3=$RO/db3.sqlite3" -e "OTP_CODE=$CODE" -e "OTP_IDENT=$LOGIN_PHONE" "$CONTAINER" php < "$SNIP/otplog.php" 2>/dev/null || echo '(read failed) | - | hits=?')"
    PREV="${OTPL%% | *}"; REST="${OTPL#* | }"; HITS="${REST##*hits=}"
    [ "$PREV" = "[one-time login code — text withheld]" ] && ok "L8 the notification log's newest app_otp row withholds the text (preview: '$PREV')" || bad "L8 the newest app_otp preview is '$PREV'"
    echo "  send record     ${REST%% | *}"
    [ "${HITS:-?}" = "0" ] && ok "L8 the code appears in no notification, queue or audit row" || bad "L8 the code appears in ${HITS} stored row(s)"
    unset CODE CTOK
  fi
  # L9 no unexpected webhook errors — stage D below counts entries since the snapshot.
else note "C5 no --login number given: delivery of the code was not exercised live (the door and its uniform answer were)"; fi

if [ "$LOGIN_ONLY" = "0" ]; then
# C6 the CLI that replaces ?page=crm_debug
if CPHP tools/crm_debug.php --status 2>/dev/null | grep -q '"retry_queue"'; then ok "C6 tools/crm_debug.php --status answers (CRM retry queue readable)"; else bad "C6 tools/crm_debug.php --status did not answer"; fi
if docker exec "$CONTAINER" test -f "$IN_CONTAINER/tools/crm_webhook_key.php"; then ok "C6 tools/crm_webhook_key.php is deployed (NOT run — the key stays unset by decision)"; else bad "C6 tools/crm_webhook_key.php missing"; fi
fi   # LOGIN_ONLY (C6)

# C7 PHP fatals since the deploy (or since this run, in the after-only modes)
FATALS="$(docker logs "$CONTAINER" --since "$DEPLOY_STARTED" 2>&1 | grep -ciE 'DishNet FATAL|DishNet UNCAUGHT|PHP Fatal|PHP Parse error' || true)"
if [ "${FATALS:-0}" = "0" ]; then ok "C7 no PHP fatal/uncaught in the container log since $DEPLOY_STARTED"
else bad "C7 $FATALS fatal/uncaught lines since $DEPLOY_STARTED:"; docker logs "$CONTAINER" --since "$DEPLOY_STARTED" 2>&1 | grep -iE 'DishNet FATAL|DishNet UNCAUGHT|PHP Fatal|PHP Parse error' | cut -c1-200 | head -10 | sed 's/^/     /'; fi
unset ADMIN_TOKEN STAFF_TOKEN
fi   # WEBHOOK_ONLY

# ═════════════════════════════════════════════════════════════════════════════
hdr "D. Webhook — read-only (crm_webhook_key stays UNSET; nothing is configured)"
# ═════════════════════════════════════════════════════════════════════════════
cat > "$SNIP/webhook.php" <<'PHP'
<?php
$since = (int)getenv('WH_LAST_ID'); $pdd = getenv('PDD_IN');
$l = @json_decode((string)@file_get_contents($pdd . '/webhook_log.json'), true); $l = is_array($l) ? $l : [];
$new = array_filter($l, function ($r) use ($since) { return (int)($r['id'] ?? 0) > $since; });
$by = [];
foreach ($new as $r) { $e = (string)($r['event'] ?? '?'); $by[$e] = ($by[$e] ?? 0) + 1; }
echo "webhook_log: ", count($new), " entries since id {$since}", $by ? ' — ' . json_encode($by) : '', "\n";
foreach ($new as $r) {
    $e = (string)($r['event'] ?? '');
    if (in_array($e, ['entity_unverified', 'auth_failed', 'cashbook_error'], true))
        echo "  [{$e}] ", substr((string)($r['message'] ?? ''), 0, 140), "  (", $r['received_at'] ?? '', ")\n";
}
$unv = count(array_filter($l, function ($r) { return ($r['event'] ?? '') === 'entity_unverified'; }));
echo "webhook_log overall: ", count($l), " kept entries, {$unv} entity_unverified (an event 5.18.37 alone writes)\n";
// uCRM's endpoint objects, read the way the plugin itself reads them: the same
// client, the same effective configuration (read-only load, no vault refresh),
// the same call the Settings tab makes. Nothing is changed; no key is printed.
require getcwd() . '/lib/PluginConfig.php';
require getcwd() . '/lib/CrmApiClient.php';
$config = PluginConfig::read(getcwd(), $pdd);
$crm = CrmApiClient::fromUcrm(getcwd(), $config);
$base = $crm->getBaseUrl();
echo "CRM client: ", $base === '' ? 'NOT CONFIGURED (no ucrm.json address and no crm_base_url)' : "base {$base}", "  auth header ", $crm->getAuthHeader(), "\n";
if ($base === '') exit;
$fmtErr = function (array $e): string {
    $m = is_array($e['response'] ?? null) ? (string)(($e['response']['message'] ?? '')) : '';
    return json_encode(['http_code' => $e['http_code'] ?? null, 'curl_error' => $e['curl_error'] ?? null, 'message' => $m]);
};
$ctl = $crm->get('payment-methods');
echo "control GET payment-methods: ", is_array($ctl) ? count($ctl) . ' methods (the base URL and app key work)' : 'FAILED ' . $fmtErr($crm->getLastError()), "\n";
$eps = $crm->get('webhooks/endpoints');
if (!is_array($eps)) {
    echo "GET webhooks/endpoints (API v2.1): FAILED ", $fmtErr($crm->getLastError()), "\n";
    // Diagnostic: the same route on API v1.0, same address, same key — the route
    // the plugin used before it moved to v2.1. Read-only.
    $u = @json_decode((string)@file_get_contents(getcwd() . '/ucrm.json'), true) ?: [];
    $v1base = preg_replace('#/api/v[\d.]+$#', '/api/v1.0', $base);
    $v1 = new CrmApiClient($v1base, (string)($u['pluginAppKey'] ?? ''), 'X-Auth-App-Key');
    $eps = $v1->get('webhooks/endpoints');
    if (!is_array($eps)) { echo "GET webhooks/endpoints (API v1.0): FAILED ", $fmtErr($v1->getLastError()), " — the endpoint objects are not readable over this uCRM's API; the UI is the only view\n"; exit; }
    echo "GET webhooks/endpoints (API v1.0): answered — the plugin's client asks v2.1, which is why its Settings tile sees no endpoint (recorded finding)\n";
}
if ($eps !== [] && array_keys($eps) !== range(0, count($eps) - 1)) $eps = [$eps];
echo "uCRM webhook endpoints: ", count($eps), "\n";
$known = ['id', 'url', 'isActive', 'anyEvent', 'eventTypes', 'verifySslCertificate'];
$fields = [];
foreach ($eps as $e) {
    if (!is_array($e)) continue;
    $ours = strpos((string)($e['url'] ?? ''), '/_plugins/') !== false;
    echo "  #", $e['id'] ?? '?', $ours ? ' (ours)' : '', "  url=", $e['url'] ?? '', "  isActive=", var_export($e['isActive'] ?? null, true),
         "  anyEvent=", var_export($e['anyEvent'] ?? null, true), "  eventTypes=", count((array)($e['eventTypes'] ?? [])), "\n";
    foreach ($e as $k => $v) {
        $fields[$k] = true;
        if (!in_array($k, $known, true)) echo "    other field: {$k} = ", (is_scalar($v) && (string)$v !== '' ? 'set' : 'empty'), "\n";
    }
}
$secretish = array_values(array_filter(array_keys($fields), function ($k) { return preg_match('/secret|signature|token|key|hmac/i', $k); }));
echo $secretish ? "  a secret-like field EXISTS on the endpoint object: " . implode(', ', $secretish) . "\n"
                : "  NO secret-like field on the endpoint object (fields: " . implode(', ', array_keys($fields)) . ") — this uCRM exposes no per-endpoint secret over its API\n";
PHP
CPHP < "$SNIP/webhook.php" 2>/dev/null | sed 's/^/  /' || note "D the webhook read did not run"
echo "  Also read, in the uCRM UI: System → Webhooks → the plugin's endpoint. Does the form offer a Secret / Signature field?"
echo "  Report the field NAME only. If there is none, crm_webhook_key cannot be configured on this uCRM and stays unset (a supported state)."
echo "  If uCRM later proves it sends X-Crm-Key with a configured secret, the key can be set in a separate window with tools/crm_webhook_key.php."

# ═════════════════════════════════════════════════════════════════════════════
if [ "$WEBHOOK_ONLY" = "0" ] && [ "$LOGIN_ONLY" = "0" ]; then
hdr "E. Receipt / delivery links — the old 'dishnet' scheme is dead, PdfLinkToken lives"
# ═════════════════════════════════════════════════════════════════════════════
docker exec -u "$DB_OWNER" "$CONTAINER" sh -c "mkdir -p '$RO' && cp '$DB_IN' '$RO/db2.sqlite3' && ( [ -f '$DB_IN-wal' ] && cp '$DB_IN-wal' '$RO/db2.sqlite3-wal' || true )" 2>/dev/null || true
RCPT="$(docker exec "$CONTAINER" sh -c "ls -t '$PDD_IN/receipt_pdfs' 2>/dev/null | grep -E '^DishNet-Receipt-[0-9]+\.pdf$' | head -1" || true)"
cat > "$SNIP/links.php" <<'PHP'
<?php
// Prints "<secret length> <new token or -> <old 'dishnet' token or -> <old empty-secret token or ->"
$db = new PDO('sqlite:' . getenv('RO_DB2'), null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$cfg = json_decode((string)$db->query("SELECT data FROM kyc_config LIMIT 1")->fetchColumn(), true) ?: [];
$secret = trim((string)($cfg['pdf_link_secret'] ?? '')); $file = (string)getenv('RCPT_FILE');
require getcwd() . '/lib/PdfLinkToken.php';
$new = ($secret !== '' && $file !== '') ? PdfLinkToken::mint($file, ['pdf_link_secret' => $secret]) : '-';
$old = $file !== '' ? hash_hmac('sha256', $file . date('Ymd'), 'dishnet') : '-';
$oldEmpty = $file !== '' ? hash_hmac('sha256', $file . date('Ymd'), '') : '-';
echo strlen($secret), ' ', $new, ' ', $old, ' ', $oldEmpty, "\n";
PHP
LINKS="$(CPHP < "$SNIP/links.php" 2>/dev/null || echo '0 - - -')"
read -r SECRET_LEN NEW_TOKEN OLD_TOKEN OLD_EMPTY_TOKEN <<< "$LINKS"; unset LINKS
[ "${SECRET_LEN:-0}" = "64" ] && ok "E pdf_link_secret exists in the store (64 hex, generated at the first page load; value not printed)" || bad "E pdf_link_secret length ${SECRET_LEN:-0} — expected 64 (did the customer_login page load in C1?)"
VAULT="$MOUNT/ucrm/data/plugins/.dishnet-sudan.vault.json"
if [ -f "$VAULT" ]; then
  if grep -q '"pdf_link_secret"' "$VAULT"; then ok "E the vault holds pdf_link_secret (survives a re-install)"; else note "E the vault has no pdf_link_secret yet (written when generated; check again after the next page load)"; fi
else note "E no vault file at $VAULT"; fi
if [ -n "$RCPT" ]; then
  echo "  receipt file    $RCPT"
  http GET "$PLUGIN_BASE?page=api&action=serve_receipt_pdf&file=$RCPT&token=$OLD_TOKEN" '';       [ "$HTTP_CODE" = "403" ] && ok "E the pre-5.18.37 link (signed with 'dishnet'): 403 — the documented consequence, confirmed" || bad "E old 'dishnet' token → $HTTP_CODE"
  http GET "$PLUGIN_BASE?page=api&action=serve_receipt_pdf&file=$RCPT&token=$OLD_EMPTY_TOKEN" ''; [ "$HTTP_CODE" = "403" ] && ok "E the pre-5.18.37 link under an empty secret: 403" || bad "E old empty-secret token → $HTTP_CODE"
  if [ -n "${NEW_TOKEN:-}" ] && [ "$NEW_TOKEN" != "-" ]; then
    r="$(curl -sS ${CURL_EXTRA[@]+"${CURL_EXTRA[@]}"} -o /dev/null -w '%{http_code} %{content_type}' "$PLUGIN_BASE?page=api&action=serve_receipt_pdf&file=$RCPT&token=$NEW_TOKEN" 2>/dev/null || echo '000')"
    case "$r" in 200*application/pdf*) ok "E a link minted with PdfLinkToken today: 200 application/pdf";; *) bad "E new-token link → $r";; esac
  fi
  http GET "$PLUGIN_BASE?page=api&action=serve_receipt_pdf&file=$RCPT&token=$(head -c 32 /dev/urandom | od -An -tx1 | tr -d ' \n')" ''
  [ "$HTTP_CODE" = "403" ] && ok "E a random token: 403" || bad "E random token → $HTTP_CODE"
else note "E no receipt PDF on this install yet — the link rule is proved by tests/test_pdf_link_token.php; nothing to exercise live"; fi
unset NEW_TOKEN OLD_TOKEN OLD_EMPTY_TOKEN
fi   # WEBHOOK_ONLY
docker exec -u "$DB_OWNER" "$CONTAINER" rm -rf "$RO" 2>/dev/null || true

# ═════════════════════════════════════════════════════════════════════════════
hdr "F. Summary"
# ═════════════════════════════════════════════════════════════════════════════
echo "  deployed commit   $LIVE_AFTER  (plugin $EXPECTED_VERSION)"
if [ "$AFTER_ONLY" = "0" ]; then echo "  rollback commit   ${LIVE_BEFORE:-unknown}   →  cd $REPO && git checkout ${LIVE_BEFORE:-<commit>} && bash scripts/deploy-hybrid.sh"
else echo "  rollback commit   (this run deployed nothing — see the deployment run's log)"; fi
[ -n "$BK" ] && echo "  backup            $BK"
echo "  crm_webhook_key   NOT configured (by decision) — webhook events are accepted keyless and re-read from uCRM"
echo "  checks            $PASS ok, $FAIL failed, $NOTE notes"
if [ "$FAIL" = "0" ]; then echo; echo "  Phase 1 smoke: PASSED. Send this LOG FILE back (not a copy of the terminal)."
else echo; echo "  Phase 1 smoke: $FAIL FAILED — send the log file; do not roll back on your own unless customers are affected."; fi
if [ -n "$PLUGIN_BASE" ]; then
echo
echo "  Positive controls to try in a browser while signed in as an administrator, if C4 was skipped:"
echo "    $PLUGIN_BASE?page=api&action=data_dir_info"
echo "    $PLUGIN_BASE?page=api&action=staff_login_lookup&email=nobody-phase1@example.invalid"
fi
[ "$FAIL" = "0" ]
