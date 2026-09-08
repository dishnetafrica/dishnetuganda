#!/usr/bin/env bash
# setup-stalwart.sh — configure a freshly-wizarded Stalwart (v0.16) headlessly:
# domain, mailboxes, TLS certificate, and the DNS records to copy to GoDaddy.
#
#   cd /opt/dishnet/dishnet-mail
#   STALWART_PASS='the-admin-password' ACCOUNTS_PASS='mailbox-password' bash setup-stalwart.sh
#
# Idempotent: principals that already exist are left alone (their passwords are
# NOT changed on a re-run). Every step prints what happened; nothing is silent.
set -u

API="http://172.17.0.1:8090"
DOMAIN="dishnetuganda.com"
CERTDIR="/opt/stalwart-certs/mail.${DOMAIN}"
ADMIN_USER="admin"

say()  { printf '%s\n' "$*"; }
line() { printf '────────────────────────────────────────────────────────\n'; }

# ── 0) Self-heal permissions: the container user must own the data dirs ─────
CUID=$(docker exec stalwart id -u 2>/dev/null || true)
CGID=$(docker exec stalwart id -g 2>/dev/null || true)
if [ -n "$CUID" ]; then
  OWNER=$(stat -c %u data/stalwart 2>/dev/null || echo '?')
  if [ "$OWNER" != "$CUID" ]; then
    chown -R "${CUID}:${CGID:-$CUID}" data/stalwart data/stalwart-etc 2>/dev/null
    say "ok   data dirs handed to container user uid=${CUID} (were uid=${OWNER})"
  else
    say "ok   data dir ownership already correct (uid=${CUID})"
  fi
fi
if docker logs --since 60m stalwart 2>&1 | tail -40 | grep -q 'Permission denied'; then
  say "…    a recent permission error is in the log — restarting Stalwart clean"
  docker restart stalwart >/dev/null; sleep 7
fi

# ── 1) Find a working admin password — and say WHICH failure this is ────────
CANDIDATES=()
[ -n "${STALWART_PASS:-}" ] && CANDIDATES+=("$STALWART_PASS")
if [ -f .env ]; then
  ENVPASS=$(grep -E '^STALWART_ADMIN=' .env | cut -d: -f2-)
  [ -n "$ENVPASS" ] && CANDIDATES+=("$ENVPASS")
fi
PASS=""; LASTCODE=""
for u in "admin" "admin@${DOMAIN}"; do
  for c in "${CANDIDATES[@]}"; do
    LASTCODE=$(curl -s -o /dev/null -w '%{http_code}' -u "${u}:${c}" "$API/api/principal?limit=1")
    if [ "$LASTCODE" = "200" ]; then PASS="$c"; ADMIN_USER="$u"; break 2; fi
  done
done
if [ -z "$PASS" ]; then
  line
  if [ "$LASTCODE" = "401" ] || [ "$LASTCODE" = "403" ]; then
    say "STOP: Stalwart IS configured, but neither password is the admin password."
    say "  The wizard's administrator page decided the real one — if it displayed a"
    say "  generated password, use that:  STALWART_PASS='thatpassword' bash setup-stalwart.sh"
  else
    say "STOP: Stalwart is still in first-run mode (API answered HTTP ${LASTCODE})."
    say "  The wizard has not been completed on this instance — permissions are fixed"
    say "  now, so this time it will save. Open https://mail.${DOMAIN} and fill:"
    say "    hostname mail.${DOMAIN} · domain ${DOMAIN} · ACME OFF · DKIM ON"
    say "    storage defaults · internal directory · admin password YJ… (yours)"
    say "    Manual DNS · Finish setup"
    say "  Then rerun this exact command."
  fi
  exit 1
fi
say "ok   admin authentication works"

req() { # method path [json]
  local m="$1" p="$2" b="${3:-}"
  if [ -n "$b" ]; then
    curl -s -w '\n%{http_code}' -u "${ADMIN_USER}:${PASS}" -X "$m" \
         -H 'Content-Type: application/json' -d "$b" "$API$p"
  else
    curl -s -w '\n%{http_code}' -u "${ADMIN_USER}:${PASS}" -X "$m" "$API$p"
  fi
}

exists_principal() { # name -> 0 if exists
  local out code
  out=$(req GET "/api/principal/$1"); code=$(printf '%s' "$out" | tail -1)
  [ "$code" = "200" ]
}

mk_principal() { # json name label
  local body="$1" name="$2" label="$3" out code resp
  if exists_principal "$name"; then say "ok   ${label} already exists — untouched"; return 0; fi
  out=$(req POST "/api/principal" "$body")
  code=$(printf '%s' "$out" | tail -1); resp=$(printf '%s' "$out" | sed '$d' | head -c 200)
  if [ "$code" = "200" ] || [ "$code" = "201" ]; then
    say "ok   ${label} created"
  else
    say "FAIL ${label} — HTTP ${code}: ${resp}"
    FAILED=1
  fi
}

FAILED=0
line
say "1) Domain + mailboxes on ${DOMAIN}"

mk_principal "{\"type\":\"domain\",\"name\":\"${DOMAIN}\",\"description\":\"Primary mail domain\"}" \
  "${DOMAIN}" "domain ${DOMAIN}"

ACCOUNTS_PW="${ACCOUNTS_PASS:-}"
if [ -z "$ACCOUNTS_PW" ]; then ACCOUNTS_PW=$(openssl rand -base64 12); fi
declare -A PW
PW[accounts]="$ACCOUNTS_PW"
for u in bhavin kishan billing starlink dmarc; do PW[$u]=$(openssl rand -base64 12); done

mkbox() { # user displayname extra_emails_csv
  local u="$1" d="$2" extra="${3:-}" emails="\"${u}@${DOMAIN}\""
  if [ -n "$extra" ]; then
    for e in ${extra//,/ }; do emails="${emails},\"${e}@${DOMAIN}\""; done
  fi
  mk_principal "{\"type\":\"individual\",\"name\":\"${u}\",\"description\":\"${d}\",\"secrets\":[\"${PW[$u]}\"],\"emails\":[${emails}],\"quota\":0,\"roles\":[\"user\"]}" \
    "$u" "mailbox ${u}@${DOMAIN}"
}
mkbox accounts "Accounts"
mkbox bhavin   "Bhavin Madlani"
mkbox kishan   "Kishan"
mkbox billing  "Billing" "info,postmaster,abuse"
mkbox starlink "Starlink intake"
mkbox dmarc    "DMARC reports"

line
say "2) TLS certificate from the Traefik dumper files"
CERTJSON="[{\"type\":\"insert\",\"prefix\":\"certificate.default\",\"values\":[[\"cert\",\"%{file:${CERTDIR}/fullchain.pem}%\"],[\"private-key\",\"%{file:${CERTDIR}/privkey.pem}%\"]]},{\"type\":\"insert\",\"prefix\":\"server\",\"values\":[[\"hostname\",\"mail.${DOMAIN}\"]]}]"
out=$(req POST "/api/settings" "$CERTJSON"); code=$(printf '%s' "$out" | tail -1)
if [ "$code" = "200" ]; then
  say "ok   certificate + hostname settings written"
else
  say "warn settings API answered HTTP ${code} — do this bit in the UI:"
  say "     Settings → Server → TLS → Certificates: cert ${CERTDIR}/fullchain.pem"
  say "     key ${CERTDIR}/privkey.pem; Settings → Server: hostname mail.${DOMAIN}"
fi
req GET "/api/reload" >/dev/null 2>&1 || true

line
say "3) DNS records Stalwart wants for ${DOMAIN} (add the *_domainkey TXT rows at GoDaddy)"
out=$(req GET "/api/dns/records/${DOMAIN}"); code=$(printf '%s' "$out" | tail -1)
if [ "$code" = "200" ]; then
  printf '%s' "$out" | sed '$d' | python3 -c '
import json,sys
try:
    d=json.load(sys.stdin)
    rows=d.get("data",d) if isinstance(d,dict) else d
    for r in rows:
        t=str(r.get("type","")).upper()
        if "DKIM" in t or "domainkey" in str(r.get("name","")) or t in ("TXT","MX","SRV","CNAME"):
            print("  %-6s %-45s %s" % (r.get("type",""), r.get("name",""), str(r.get("content", r.get("value","")))[:180]))
except Exception as e:
    sys.stdout.write("  (could not parse: %s)\n" % e)
'
else
  say "warn DNS-records API answered HTTP ${code} — read them in the UI under the domain's DNS page"
fi

line
say "4) Restarting Stalwart to apply everything"
docker restart stalwart >/dev/null && sleep 6
docker logs --since 1m stalwart 2>&1 | grep -E 'listen-start|error|failed' | head -12
curl -s -o /dev/null -w 'https admin UI     HTTP %{http_code}\n' "https://mail.${DOMAIN}"
timeout 6 bash -c "echo | openssl s_client -connect mail.${DOMAIN}:993 -brief 2>&1 | head -2" || say "imaps :993 not answering yet"

line
say "MAILBOX PASSWORDS — write these down NOW, they are not shown again:"
for u in accounts bhavin kishan billing starlink dmarc; do
  if exists_principal "$u"; then printf '  %-10s %s@%s   %s\n' "$u" "$u" "$DOMAIN" "${PW[$u]}"; fi
done
say "  (a mailbox that already existed keeps its old password — the one above is unused)"
line
say "NEXT:"
say "  1. Webmail login test: https://webmail.${DOMAIN} — username accounts@${DOMAIN} (full address)."
say "  2. Add the _domainkey TXT rows above at GoDaddy."
say "  3. In the admin UI, one screen I do not set blindly: Settings → SMTP → Outbound"
say "     relay host smtp-relay.brevo.com : 587 STARTTLS, login b7cac0001@smtp-brevo.com,"
say "     password = your Brevo SMTP key.  (Needed for replies FROM webmail; the plugin's"
say "     own sending already goes to Brevo directly and works today.)"
say "  4. Cutover at GoDaddy: delete the mailstore1 MX row (and secureserver leftovers)."
say "  5. Test:  the email_setup.php command with --from billing@${DOMAIN} --test bhavin@${DOMAIN}"
[ "$FAILED" = "0" ] && say "RESULT: PASS" || say "RESULT: PARTIAL — read the FAIL lines above"
