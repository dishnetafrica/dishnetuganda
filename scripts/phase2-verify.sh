#!/usr/bin/env bash
#
# phase2-verify.sh — the final Phase 2 verifications on the live Uganda install. READ-MOSTLY:
# it deploys nothing, changes no configuration, and touches no customer record. The only
# writes are the rows the plugin itself writes when a sign-in is attempted (audit, one
# pending code, one session that is logged out again).
#
# Run as root on the server, one mode at a time, and send back THE LOG FILE:
#
#   cd /opt/dishnet && git pull origin claude/study-this-jhe2eg && mkdir -p /root/dnb-verify \
#     && bash scripts/phase2-verify.sh <mode> 2>&1 | tee /root/dnb-verify/verify-<mode>-$(date -u +%Y%m%dT%H%M%SZ).log
#
# Modes (exactly one):
#   --data-report          READ-ONLY. Inspects the installed dishnet-data-report plugin: does it
#                          verify the hand-off token, with what key, does it check clientId, aud,
#                          exp — and whether crm_auth_token / webhook_secret / crm_app_key are SET
#                          or EMPTY on this install (presence only; no value is ever printed).
#   --email <address>      One controlled e-mail sign-in with an address on a customer record YOU
#                          control. One e-mail is sent to it; the code is typed here and never
#                          printed; the session is logged out at the end.
#   --lead <+2567…>        One controlled eligibility refusal. The number MUST belong to a LEAD:
#                          the script checks that first and stops if it is not — so no customer is
#                          ever contacted. A lead gets no message: the gate refuses before sending.
#   --website              Reports whether the live site already links the portal (report only).
#
# What it never does: print a token, code, key, secret or password; send anything to a customer;
# change a record; deploy; roll back.
set -uo pipefail
umask 077

PLUGIN="dishnet-hybrid-sudan"
SIBLING="dishnet-data-report"
CONTAINER="${UCRM_CONTAINER:-ucrm}"
REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
WEBSITE="${WEBSITE:-https://dishnetuganda.com/}"
TS="$(date -u +%Y%m%dT%H%M%SZ)"
STARTED="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
SNIP="$(mktemp -d)"; trap 'rm -rf "$SNIP"' EXIT

MODE=""; ARG=""
case "${1:-}" in
  --data-report|--website) MODE="${1#--}" ;;
  --email|--lead) MODE="${1#--}"; ARG="${2:-}"; [ -n "$ARG" ] || { echo "usage: $0 $1 <value>" >&2; exit 64; } ;;
  *) echo "usage: $0 --data-report | --email <address> | --lead <+2567…> | --website" >&2; exit 64 ;;
esac

PASS=0; FAIL=0; NOTE=0
ok()   { PASS=$((PASS+1)); printf '  ok    %s\n' "$*"; }
bad()  { FAIL=$((FAIL+1)); printf '  FAIL  %s\n' "$*"; }
note() { NOTE=$((NOTE+1)); printf '  note  %s\n' "$*"; }
hdr()  { printf '\n== %s ==\n' "$*"; }
stop() { printf '\n  STOP: %s\n  Nothing further was done. Send the log file.\n' "$*"; exit 1; }
# Anything that looks like a key, token or hash is masked before it can reach the log.
mask() { sed -E 's/[A-Za-z0-9+\/=_-]{24,}/<redacted>/g'; }
# The operator types the one-time code here. Never echoed. (A rehearsal replaces this function.)
ask_code() { local c; printf '  Type the 6-digit code you received (not shown), or press Enter if none arrived: ' >&2; read -rs c </dev/tty || c=""; echo >&2; printf '%s' "$c" | tr -cd '0-9'; }

HTTP_CODE=""; HTTP_BODY=""; HTTP_HEADERS=""
http() {
  local m="$1" u="$2" b="$3"; shift 3
  local hdrs=(); local h; for h in "$@"; do hdrs+=(-H "$h"); done
  local args=(-sS -o "/tmp/dnv_body.$$" -D "/tmp/dnv_hdr.$$" -w '%{http_code}' --max-time 30 -X "$m" -H 'Content-Type: application/json')
  [ -n "$b" ] && args+=(--data "$b")
  local r; r="$(curl "${args[@]}" ${hdrs[@]+"${hdrs[@]}"} "$u" 2>/dev/null)" || r="000"
  HTTP_CODE="$r"; HTTP_BODY="$(head -c 200000 "/tmp/dnv_body.$$" 2>/dev/null | tr -d '\000' || true)"
  HTTP_HEADERS="$(head -c 8000 "/tmp/dnv_hdr.$$" 2>/dev/null || true)"
  rm -f "/tmp/dnv_body.$$" "/tmp/dnv_hdr.$$"
}
jfield() { printf '%s' "$1" | grep -o "\"$2\":\"[^\"]*\"" | head -1 | sed -E "s/^\"$2\":\"//; s/\"$//"; }
jnum()   { printf '%s' "$1" | grep -o "\"$2\":[0-9]*" | head -1 | sed -E "s/^\"$2\"://"; }

hdr "Phase 2 verification — $MODE — $TS — $(hostname)"

# ── The container, the plugin, its data directory and public address (the deploy scripts' rule) ──
MOUNT="$(docker inspect "$CONTAINER" --format '{{range .Mounts}}{{if eq .Destination "/data"}}{{.Source}}{{end}}{{end}}' 2>/dev/null || true)"
[ -n "$MOUNT" ] || stop "container '$CONTAINER' has no /data mount"
IN_CONTAINER="/data/ucrm/data/plugins/$PLUGIN"
PLUGINS_IN="/data/ucrm/data/plugins"
LIVE="$(docker exec "$CONTAINER" cat "$IN_CONTAINER/.deployed-commit" 2>/dev/null | tail -n1 | tr -cd '0-9a-f')"
echo "  live plugin     ${LIVE:-unknown}   repo HEAD $(git -C "$REPO" rev-parse --short HEAD 2>/dev/null || echo ?)   last plugin commit $(git -C "$REPO" log -1 --format=%h -- "$PLUGIN" 2>/dev/null || echo ?)"
docker exec "$CONTAINER" php -v >/dev/null 2>&1 || stop "no php inside the container"
PDD_IN="$(docker exec "$CONTAINER" php -r '$u=@json_decode((string)@file_get_contents($argv[1]),true); echo rtrim((string)($u["pluginDataDir"]??""),"/");' "$IN_CONTAINER/ucrm.json" 2>/dev/null || true)"
if [ -z "$PDD_IN" ]; then
  if docker exec "$CONTAINER" test -f "$PLUGINS_IN/.$PLUGIN-data/plugin.sqlite3"; then PDD_IN="$PLUGINS_IN/.$PLUGIN-data"; else PDD_IN="$IN_CONTAINER/data"; fi
fi
DB_IN="$PDD_IN/plugin.sqlite3"
docker exec "$CONTAINER" test -f "$DB_IN" || stop "no database at $DB_IN"
DB_OWNER="$(docker exec "$CONTAINER" stat -c '%u:%g' "$DB_IN")"
# --- TOOLS BEGIN ---
RO="/tmp/dnv-ro-$$"
ro_copy() {  # $1 name → a read-only copy of the store inside the container, as its owner
  docker exec -u "$DB_OWNER" "$CONTAINER" sh -c "mkdir -p '$RO' && cp '$DB_IN' '$RO/$1.sqlite3' && ( [ -f '$DB_IN-wal' ] && cp '$DB_IN-wal' '$RO/$1.sqlite3-wal' || true )" 2>/dev/null
}
cphp() {  # run a PHP snippet from $SNIP inside the container as the store's owner, cwd the plugin; extra -e pairs follow the file
  local f="$1"; shift; docker exec -i -u "$DB_OWNER" -w "$IN_CONTAINER" "$@" "$CONTAINER" php < "$SNIP/$f" 2>/dev/null
}
# --- TOOLS END ---
PLUGIN_BASE="${PLUGIN_BASE:-}"
if [ -z "$PLUGIN_BASE" ]; then
  PLUGIN_BASE="$(docker exec "$CONTAINER" php -r '
    $r=$argv[1]; $pdd=$argv[2]; $u=@json_decode((string)@file_get_contents($r."/ucrm.json"),true)?:[];
    $over="";
    foreach ([$r."/data/config.json", $pdd."/config.json", $pdd."/kyc_config.json"] as $f) { $c=@json_decode((string)@file_get_contents($f),true)?:[]; $v=rtrim(trim((string)($c["crm_public_url"]??"")),"/"); if($v!==""){ $over=preg_replace("#/crm$#","",$v); break; } }
    if($over!==""){ echo $over."/crm/_plugins/".basename($r)."/public.php"; exit; }
    if(!empty($u["pluginPublicUrl"])){ echo rtrim($u["pluginPublicUrl"],"/"); exit; }
    $b=rtrim((string)($u["ucrmPublicUrl"]??""),"/"); $b=preg_replace("#/crm$#","",$b); echo $b?$b."/crm/_plugins/".basename($r)."/public.php":"";' "$IN_CONTAINER" "$PDD_IN" 2>/dev/null || true)"
fi
[ -n "$PLUGIN_BASE" ] || stop "could not derive the plugin's public URL — set PLUGIN_BASE=https://<host>/crm/_plugins/$PLUGIN/public.php"
echo "  plugin URL      $PLUGIN_BASE"
echo "  data dir        $PDD_IN (container)"

# --- ADMIN BEGIN ---
# The staff tokens, read from a COPY of the store and never printed.
admin_token() {
  ro_copy tok || return 1
  cat > "$SNIP/tokens.php" <<'PHP'
<?php
$db = new PDO('sqlite:' . getenv('RO_DB'), null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$admin = ''; $now = time();
foreach ($db->query("SELECT data FROM retailers") as $row) {
    $r = json_decode((string)$row['data'], true); if (!is_array($r)) continue;
    if (empty($r['is_active']) || empty($r['api_token']) || empty($r['is_admin'])) continue;
    $issued = (int)($r['token_issued_at'] ?? 0); if ($issued > 0 && ($now - $issued) > 90 * 86400) continue;
    $admin = (string)$r['api_token']; break;
}
echo $admin === '' ? '-' : $admin, "\n";
PHP
  ADMIN_TOKEN="$(cphp tokens.php -e "RO_DB=$RO/tok.sqlite3" | tail -n1 | tr -d '\r\n')"
  [ "${ADMIN_TOKEN:-}" = "-" ] && ADMIN_TOKEN=""
  [ -n "$ADMIN_TOKEN" ]
}
# --- ADMIN END ---

# ═════════════════════════════════════════════════════════════════════════════
if [ "$MODE" = "data-report" ]; then
# --- DR BEGIN ---
hdr "D. The data-report hand-off token — read-only inspection of the installed sibling plugin"
SIB_IN="$PLUGINS_IN/$SIBLING"
if docker exec "$CONTAINER" test -d "$SIB_IN"; then
  ok "D1 $SIBLING is installed at $SIB_IN"
  echo "  version         $(docker exec "$CONTAINER" php -r '$m=@json_decode((string)@file_get_contents($argv[1]),true); echo (string)($m["information"]["version"]??"?");' "$SIB_IN/manifest.json" 2>/dev/null)"
  echo "  files           $(docker exec "$CONTAINER" sh -c "ls '$SIB_IN' | tr '\n' ' '" 2>/dev/null | cut -c1-200)"
  echo "  lib/            $(docker exec "$CONTAINER" sh -c "ls '$SIB_IN/lib' 2>/dev/null | tr '\n' ' '" | cut -c1-300)"
  # What its code does with a token. Excerpts are masked: no key or hash literal can reach this log.
  echo "  ── occurrences (file:line: excerpt, masked) ──"
  for pat in 'token' 'JwtAuth' 'hash_hmac' 'DishNet-Hybrid-JWT' 'webhook_secret' 'crm_auth_token' 'crm_app_key' 'kyc_config' 'clientId' "'aud'" "'exp'" "'sub'" 'accounts'; do
    n="$(docker exec "$CONTAINER" sh -c "grep -rn -F -- \"$pat\" '$SIB_IN' --include='*.php' 2>/dev/null | wc -l" | tr -d ' ')"
    printf '  %-22s %s occurrence(s)\n' "$pat" "${n:-0}"
  done
  echo "  ── the token-handling lines themselves (public.php and lib/, masked, first 60) ──"
  docker exec "$CONTAINER" sh -c "grep -rn -i -E 'token|JwtAuth|hash_hmac|hash_equals|base64_decode|clientId.*sub|sub.*clientId|\"exp\"|'\''exp'\''|\"aud\"|'\''aud'\''' '$SIB_IN' --include='*.php' 2>/dev/null" \
    | sed "s|$SIB_IN/||" | mask | cut -c1-200 | head -60 | sed 's/^/     /'
  # The answers, mechanically: verifies a signature? reads the hybrid plugin's config? checks clientId against the token? exp? aud?
  V_SIG="$(docker exec "$CONTAINER" sh -c "grep -rn -E 'hash_hmac|hash_equals|JwtAuth' '$SIB_IN' --include='*.php' 2>/dev/null | wc -l" | tr -d ' ')"
  V_CFG="$(docker exec "$CONTAINER" sh -c "grep -rn -E 'webhook_secret|crm_auth_token|crm_app_key|DishNet-Hybrid-JWT|dishnet-hybrid' '$SIB_IN' --include='*.php' 2>/dev/null | wc -l" | tr -d ' ')"
  V_SUB="$(docker exec "$CONTAINER" sh -c "grep -rn -E \"['\\\"]sub['\\\"]|accounts\" '$SIB_IN' --include='*.php' 2>/dev/null | wc -l" | tr -d ' ')"
  V_EXP="$(docker exec "$CONTAINER" sh -c "grep -rn -E \"['\\\"]exp['\\\"]\" '$SIB_IN' --include='*.php' 2>/dev/null | wc -l" | tr -d ' ')"
  V_AUD="$(docker exec "$CONTAINER" sh -c "grep -rn -E \"['\\\"]aud['\\\"]\" '$SIB_IN' --include='*.php' 2>/dev/null | wc -l" | tr -d ' ')"
  V_TOK="$(docker exec "$CONTAINER" sh -c "grep -rn -F 'token' '$SIB_IN/public.php' 2>/dev/null | wc -l" | tr -d ' ')"
  echo "  ── mechanical reading ──"
  [ "${V_TOK:-0}" != "0" ] && note "D2 public.php mentions 'token' ${V_TOK} time(s)" || note "D2 public.php does not mention 'token' at all — the hand-off token may be ignored entirely"
  [ "${V_SIG:-0}" != "0" ] && note "D3 a signature check exists (hash_hmac/hash_equals/JwtAuth: ${V_SIG})" || note "D3 NO signature verification found — if the token is used, it is used unverified"
  [ "${V_CFG:-0}" != "0" ] && note "D4 it reads the hybrid plugin's key inputs or constant (${V_CFG} reference(s)) — the legacy derivation is shared" || note "D4 no reference to the hybrid plugin's key inputs — its key source is its own, or there is none"
  [ "${V_SUB:-0}" != "0" ] && note "D5 it reads 'sub'/'accounts' from the token (${V_SUB}) — clientId may be checked against it" || note "D5 it never reads 'sub'/'accounts' — clientId is NOT checked against the token"
  [ "${V_EXP:-0}" != "0" ] && note "D6 'exp' referenced (${V_EXP})" || note "D6 'exp' never referenced — no expiry check"
  [ "${V_AUD:-0}" != "0" ] && note "D7 'aud' referenced (${V_AUD})" || note "D7 'aud' never referenced — no audience check"
  echo "  ── does the customer page serve data on clientId alone? (the lines that read clientId, masked, first 20) ──"
  docker exec "$CONTAINER" sh -c "grep -n -E 'clientId' '$SIB_IN/public.php' 2>/dev/null" | mask | cut -c1-200 | head -20 | sed 's/^/     /'
else
  bad "D1 $SIBLING is not installed at $SIB_IN — nothing verifies the hand-off token, and nothing consumes it"
fi
hdr "K. The hand-off key's inputs on THIS install — presence only, never a value"
ro_copy key || stop "could not copy the store"
cat > "$SNIP/keys.php" <<'PHP'
<?php
// Presence only. The three inputs of the legacy derivation, from the store copy that public.php loads
// as $config (what app_data_report_token actually hashes), and from PluginConfig::read() (files + vault).
$db = new PDO('sqlite:' . getenv('RO_DB'), null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$cfg = json_decode((string)$db->query("SELECT data FROM kyc_config LIMIT 1")->fetchColumn(), true) ?: [];
$p = function (array $c, string $k): string { $v = trim((string)($c[$k] ?? '')); return $v === '' ? 'EMPTY' : 'set (' . strlen($v) . ' chars)'; };
echo "store copy (what the hand-off hashes):  webhook_secret=", $p($cfg, 'webhook_secret'), "  crm_auth_token=", $p($cfg, 'crm_auth_token'), "  crm_app_key=", $p($cfg, 'crm_app_key'), "\n";
$merged = [];
try { require_once 'lib/PluginConfig.php'; $merged = PluginConfig::read(getenv('PDD_IN') ?: null) ?: []; } catch (Throwable $e) { echo "PluginConfig::read unavailable: ", get_class($e), "\n"; }
if ($merged) echo "PluginConfig::read (files + vault):     webhook_secret=", $p($merged, 'webhook_secret'), "  crm_auth_token=", $p($merged, 'crm_auth_token'), "  crm_app_key=", $p($merged, 'crm_app_key'), "\n";
$ws = trim((string)($cfg['webhook_secret'] ?? '')); $ct = trim((string)($cfg['crm_app_key'] ?? $cfg['crm_auth_token'] ?? ''));
echo "verdict: ", ($ws === '' && $ct === '') ? "BOTH EMPTY in the store copy → the legacy key is sha256('||constant'), a constant anyone can read in the source: a hand-off token is FORGEABLE for any sub" : "at least one input is set → the legacy key has entropy the public does not have (the derivation itself is still shared with whoever verifies it)", "\n";
PHP
cphp keys.php -e "RO_DB=$RO/key.sqlite3" -e "PDD_IN=$PDD_IN" | mask | sed 's/^/  /'
docker exec -u "$DB_OWNER" "$CONTAINER" rm -rf "$RO" 2>/dev/null || true
echo; echo "  Our side (repository, read here): app_data_report_token mints a 600 s token with the LEGACY derivation, claims sub/kind/phone/name/accounts/aud=data-report,"
echo "  only for a signed-in customer and only with that customer's own sub. Whether it can be forged depends on K; whether a forgery matters depends on D."
# --- DR END ---
fi

# ═════════════════════════════════════════════════════════════════════════════
if [ "$MODE" = "email" ]; then
# --- EMAIL BEGIN ---
hdr "E. One controlled e-mail sign-in (the address must be on a customer record you control)"
EMAIL="$ARG"; EMAIL_MASKED="$(printf '%s' "$EMAIL" | sed -E 's/^(.).*(@.*)$/\1***\2/')"
admin_token && ok "E0 an administrator token is on file (not printed)" || stop "no administrator token on file — the lookup control needs one"
A="Authorization: Bearer $ADMIN_TOKEN"
http GET "$PLUGIN_BASE?page=api&action=staff_login_lookup&email=$(printf '%s' "$EMAIL" | sed 's/@/%40/')" '' "$A"
N_ACC="$(printf '%s' "$HTTP_BODY" | grep -o '"id":[0-9]*' | wc -l | tr -d ' ')"
ELIG="$(printf '%s' "$HTTP_BODY" | grep -o '"eligible":[a-z]*' | head -1 | sed 's/"eligible"://')"
TRANSPORT="$(jfield "$HTTP_BODY" in_use)"
[ "$HTTP_CODE" = "200" ] || stop "staff_login_lookup → $HTTP_CODE"
[ "${N_ACC:-0}" -ge 1 ] || stop "E1 the address $EMAIL_MASKED matches no customer record — nothing was sent"
ok "E1 $EMAIL_MASKED matches $N_ACC customer account(s); eligible=${ELIG:-?}; WhatsApp transport in use: ${TRANSPORT:-?} (e-mail goes by the mailer)"
[ "$ELIG" = "true" ] || stop "E1 the account is not eligible to sign in — nothing was sent"
http POST "$PLUGIN_BASE?page=api&action=app_send_otp" "{\"email\":\"$EMAIL\"}"
[ "$HTTP_CODE" = "200" ] && [ "$(jfield "$HTTP_BODY" message)" = "Code sent via Email." ] && ok "E2 app_send_otp: 200 'Code sent via Email.'" || stop "E2 app_send_otp → $HTTP_CODE $(jfield "$HTTP_BODY" message)"
CODE="$(ask_code)"
[ -n "$CODE" ] && ok "E3 a code arrived by e-mail" || stop "E3 no code arrived — the mailer is the thing to diagnose (Settings → Email); nothing else was changed"
http POST "$PLUGIN_BASE?page=api&action=app_verify_otp" "{\"email\":\"$EMAIL\",\"code\":\"$CODE\"}"
if [ "$HTTP_CODE" = "200" ]; then
  ok "E4 app_verify_otp: 200 (a session was issued; nothing printed)"
  SC="$(printf '%s' "$HTTP_HEADERS" | grep -i '^set-cookie: dn_customer_session=' | head -1 | tr -d '\r')"
  CVAL="$(printf '%s' "$SC" | sed -E 's/^[Ss]et-[Cc]ookie: dn_customer_session=([^;]*).*/\1/')"
  [ -n "$CVAL" ] && ok "E4 the server set the session cookie" || bad "E4 no session cookie"
  printf '%s' "$SC" | grep -qi 'httponly' && ok "E4 …HttpOnly" || bad "E4 cookie not HttpOnly"
  printf '%s' "$SC" | grep -qi 'samesite=lax' && ok "E4 …SameSite=Lax" || bad "E4 cookie without SameSite=Lax"
  case "$PLUGIN_BASE" in https://*) printf '%s' "$SC" | grep -qi 'secure' && ok "E4 …Secure" || bad "E4 cookie not Secure over HTTPS";; esac
  printf '%s' "$HTTP_BODY" | grep -q '"token":"' && bad "E4 the JSON body carries a token to a browser client" || ok "E4 the JSON body carries no token (web flow)"
  http GET "$PLUGIN_BASE?page=api&action=app_me" '' "Cookie: dn_customer_session=$CVAL"
  [ "$HTTP_CODE" = "200" ] && ok "E5 app_me on the cookie: 200 — the customer's own account (login_mode e-mail)" || bad "E5 app_me → $HTTP_CODE"
  http GET "$PLUGIN_BASE?page=customer_portal&view=home" '' "Cookie: dn_customer_session=$CVAL"
  # A record that has never accepted the terms is sent to the consent step first (302); that is the
  # session being recognised, not refused. Nothing here records consent on the customer's behalf.
  LOC="$(printf '%s' "$HTTP_HEADERS" | grep -i '^location:' | head -1 | tr -d '\r')"
  if [ "$HTTP_CODE" = "200" ]; then ok "E5 the portal page opens on the cookie: 200"
  elif [ "$HTTP_CODE" = "302" ] && printf '%s' "$LOC" | grep -q 'step=consent'; then ok "E5 the portal recognises the session and asks for consent first (302 to the consent step; nothing was accepted on the customer's behalf)"
  else bad "E5 portal → $HTTP_CODE ${LOC:+($LOC)}"; fi
  http POST "$PLUGIN_BASE?page=api&action=app_logout" '{}' "Cookie: dn_customer_session=$CVAL" "X-Requested-With: DishNet" "Origin: ${PLUGIN_BASE%%/crm/*}"
  [ "$HTTP_CODE" = "200" ] && ok "E6 app_logout: 200" || bad "E6 app_logout → $HTTP_CODE $(jfield "$HTTP_BODY" message)"
  http GET "$PLUGIN_BASE?page=api&action=app_me" '' "Cookie: dn_customer_session=$CVAL"
  [ "$HTTP_CODE" = "401" ] && ok "E6 the cookie after logout: 401 (revoked)" || bad "E6 the cookie still works after logout → $HTTP_CODE"
  unset SC CVAL
else stop "E4 app_verify_otp → $HTTP_CODE $(jfield "$HTTP_BODY" message) — a mistyped code is the usual cause; run again"; fi
# E7 the code appears in no log, no store row
NCL="$(docker logs "$CONTAINER" --since "$STARTED" 2>&1 | grep -c -F -- "$CODE" || true)"
[ "${NCL:-0}" = "0" ] && ok "E7 the code appears nowhere in the container log since $STARTED" || bad "E7 the code's digits appear in $NCL container-log line(s)"
ro_copy e7 || true
cat > "$SNIP/e7.php" <<'PHP'
<?php
$db = new PDO('sqlite:' . getenv('RO_DB'), null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$code = (string)getenv('OTP_CODE'); $ident = strtolower((string)getenv('OTP_IDENT')); $since = (int)getenv('SINCE');
$hits = 0;
foreach ([["notification_audit_log", "preview LIKE ? OR error LIKE ?", 2], ["app_audit_log", "details LIKE ?", 1], ["notification_queue", "message LIKE ?", 1], ["wa_messages", "body LIKE ?", 1]] as [$t, $w, $n]) {
    try { $st = $db->prepare("SELECT COUNT(*) FROM $t WHERE $w"); $st->execute(array_fill(0, $n, "%{$code}%")); $hits += (int)$st->fetchColumn(); } catch (Throwable $e) {}
}
try { $st = $db->prepare("SELECT COUNT(*) FROM app_otp_pending WHERE code = ?"); $st->execute([$code]); $hits += (int)$st->fetchColumn(); } catch (Throwable $e) {}
$audit = [];
try { $st = $db->prepare("SELECT action, COUNT(*) c FROM app_audit_log WHERE phone = ? AND at >= ? GROUP BY action"); $st->execute([$ident, $since]); foreach ($st as $r) $audit[] = $r['action'] . '×' . $r['c']; } catch (Throwable $e) {}
echo "hits={$hits} | audit=", implode(' ', $audit) ?: '(none)', "\n";
PHP
E7="$(cphp e7.php -e "RO_DB=$RO/e7.sqlite3" -e "OTP_CODE=$CODE" -e "OTP_IDENT=$EMAIL" -e "SINCE=$(date -u -d "$STARTED" +%s)" | tail -n1)"
HITS="${E7#hits=}"; HITS="${HITS%% |*}"
[ "${HITS:-?}" = "0" ] && ok "E7 the code appears in no notification, queue, conversation, pending or audit row" || bad "E7 the code appears in ${HITS} stored row(s)"
echo "  audit for this address since the start: ${E7#*audit=}"
docker exec -u "$DB_OWNER" "$CONTAINER" rm -rf "$RO" 2>/dev/null || true
unset CODE
# --- EMAIL END ---
fi

# ═════════════════════════════════════════════════════════════════════════════
if [ "$MODE" = "lead" ]; then
# --- LEAD BEGIN ---
hdr "L. One controlled eligibility refusal (the number must belong to a LEAD; nothing is sent to anyone)"
PHONE="$ARG"; PHONE_MASKED="$(printf '%s' "$PHONE" | sed -E 's/[0-9](?=[0-9]{3})/*/g; s/^(.*)([0-9]{3})$/…\2/')"
admin_token && ok "L0 an administrator token is on file (not printed)" || stop "no administrator token on file"
A="Authorization: Bearer $ADMIN_TOKEN"
http GET "$PLUGIN_BASE?page=api&action=staff_login_lookup&phone=$(printf '%s' "$PHONE" | sed 's/+/%2B/')" '' "$A"
[ "$HTTP_CODE" = "200" ] || stop "staff_login_lookup → $HTTP_CODE"
IDENT="$(jfield "$HTTP_BODY" identifier)"
N_ACC="$(printf '%s' "$HTTP_BODY" | grep -o '"refused_because"' | wc -l | tr -d ' ')"
ELIG="$(printf '%s' "$HTTP_BODY" | grep -o '"eligible":[a-z]*' | head -1 | sed 's/"eligible"://')"
WHY="$(jfield "$HTTP_BODY" refused_because)"
IS_LEAD="$(printf '%s' "$HTTP_BODY" | grep -o '"is_lead":[^,}]*' | head -1 | sed 's/"is_lead"://')"
echo "  identifier      $(printf '%s' "$IDENT" | sed -E 's/^(.*)([0-9]{3})$/…\2/')   accounts $N_ACC   eligible ${ELIG:-?}   refused_because ${WHY:-—}   is_lead ${IS_LEAD:-?}"
[ "${N_ACC:-0}" -ge 1 ] || stop "L1 the number matches no record — nothing was sent"
if [ "$ELIG" = "false" ] && [ "$WHY" = "lead" ]; then ok "L1 the record is a LEAD and the gate says it would be refused — safe to try the door"
else stop "L1 this number is NOT a refused lead (eligible=${ELIG:-?}, reason=${WHY:-none}) — a code would reach a real customer; nothing was sent"; fi
DEFAULTS="$(printf '%s' "$HTTP_BODY" | grep -o '"eligibility_defaults":{[^}]*}' | head -1)"
echo "  gates           ${DEFAULTS:-?}"
http POST "$PLUGIN_BASE?page=api&action=app_send_otp" "{\"phone\":\"$PHONE\"}"
[ "$HTTP_CODE" = "200" ] && [ "$(jfield "$HTTP_BODY" message)" = "Code sent via WhatsApp." ] && ok "L2 app_send_otp answered the UNIFORM 200 'Code sent via WhatsApp.' (a lead learns nothing)" || bad "L2 app_send_otp → $HTTP_CODE '$(jfield "$HTTP_BODY" message)' — not the uniform answer"
sleep 2
ro_copy l3 || stop "could not copy the store"
cat > "$SNIP/l3.php" <<'PHP'
<?php
$db = new PDO('sqlite:' . getenv('RO_DB'), null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$ident = (string)getenv('IDENT'); $since = (int)getenv('SINCE'); $digits = preg_replace('/\D/', '', $ident); $last9 = substr($digits, -9);
// a table that has never been created (no notification ever sent) counts as empty, not as an error
$c = function (string $sql, array $p) use ($db): int { try { $st = $db->prepare($sql); $st->execute($p); return (int)$st->fetchColumn(); } catch (Throwable $e) { return stripos($e->getMessage(), 'no such table') !== false ? 0 : -1; } };
echo "otp_ineligible=", $c("SELECT COUNT(*) FROM app_audit_log WHERE action='otp_ineligible' AND phone=? AND at>=?", [$ident, $since]),
     " otp_sent=", $c("SELECT COUNT(*) FROM app_audit_log WHERE action='otp_sent' AND phone=? AND at>=?", [$ident, $since]),
     " pending=", $c("SELECT COUNT(*) FROM app_otp_pending WHERE phone=?", [$ident]),
     " notifications=", $c("SELECT COUNT(*) FROM notification_audit_log WHERE event='app_otp' AND (phone=? OR phone LIKE ?) AND sent_at>=?", [$digits, '%' . $last9, $since]),
     " queued=", $c("SELECT COUNT(*) FROM notification_queue WHERE event='app_otp' AND (phone=? OR phone LIKE ?)", [$digits, '%' . $last9]), "\n";
PHP
L3="$(cphp l3.php -e "RO_DB=$RO/l3.sqlite3" -e "IDENT=$IDENT" -e "SINCE=$(date -u -d "$STARTED" +%s)" | tail -n1)"
echo "  store           $L3"
case "$L3" in *"otp_ineligible=1"*) ok "L3 one otp_ineligible audit row for the lead — the gate fired";; *) bad "L3 no otp_ineligible row: $L3";; esac
case "$L3" in *"otp_sent=0"*) ok "L3 no otp_sent row";; *) bad "L3 an otp_sent row exists";; esac
case "$L3" in *"pending=0"*) ok "L3 no pending code was created";; *) bad "L3 a pending code exists — the gate did not stop the send";; esac
case "$L3" in *"notifications=0"*) ok "L3 no app_otp notification was recorded for this number since the start";; *) bad "L3 a notification row exists: $L3";; esac
case "$L3" in *"queued=0"*) ok "L3 nothing queued for this number";; *) bad "L3 a queued message exists";; esac
docker exec -u "$DB_OWNER" "$CONTAINER" rm -rf "$RO" 2>/dev/null || true
# --- LEAD END ---
fi

# ═════════════════════════════════════════════════════════════════════════════
if [ "$MODE" = "website" ]; then
# --- WEB BEGIN ---
hdr "W. The website — is the committed repoint live? (report only)"
WEB_COMMIT="$(git -C "$REPO" log -1 --format='%h %ad' --date=short -- dishnet-web-uganda 2>/dev/null || echo ?)"
echo "  repository      last website commit: $WEB_COMMIT"
http GET "$WEBSITE" ''
N_PORTAL="$(printf '%s' "$HTTP_BODY" | grep -o 'href="https://crm.dishnetuganda.com/crm/_plugins/dishnet-hybrid-sudan/public.php?page=customer_login"' | wc -l | tr -d ' ')"
N_UCRM="$(printf '%s' "$HTTP_BODY" | grep -o 'href="https://crm.dishnetuganda.com/crm/login"' | wc -l | tr -d ' ')"
if [ "$HTTP_CODE" != "200" ]; then bad "W the website answered $HTTP_CODE"
elif [ "${N_PORTAL:-0}" != "0" ] && [ "${N_UCRM:-0}" = "0" ]; then ok "W LIVE: the home page links the DishNet portal sign-in ($N_PORTAL links) and uCRM's login nowhere — the redeploy has happened"
elif [ "${N_UCRM:-0}" != "0" ]; then note "W NOT REDEPLOYED: the home page still links uCRM's login ($N_UCRM links; portal links $N_PORTAL) — redeploy in EasyPanel (project web, app web-uganda)"
else note "W the home page carries neither login link — read it yourself"; fi
# --- WEB END ---
fi

hdr "Summary"
echo "  mode     $MODE"
echo "  checks   $PASS ok, $FAIL failed, $NOTE notes"
[ "$FAIL" = "0" ] && echo "  Send this LOG FILE back (not a copy of the terminal)." || echo "  $FAIL FAILED — send the log file; nothing was deployed or changed."
[ "$FAIL" = "0" ]
