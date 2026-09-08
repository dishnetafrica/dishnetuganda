#!/usr/bin/env bash
# setup-stalwart.sh — configure a wizarded Stalwart v0.16 headlessly.
#
# v2: Stalwart 0.16 administers itself over JMAP (POST /jmap, User/set etc.),
# not the old /api/principal REST — this script speaks that dialect, and reads
# the server's own /api/schema so field names come from the running version
# instead of guesswork.
#
#   cd /opt/dishnet/dishnet-mail
#   STALWART_PASS='admin-password' ACCOUNTS_PASS='accounts@ password' bash setup-stalwart.sh
#
# Creates: domain dishnetuganda.com (if missing) and mailboxes accounts@,
# bhavin@, kishan@, billing@ (with info@/postmaster@/abuse@ aliases),
# starlink@, dmarc@. Existing users are left untouched. Then installs the TLS
# certificate + hostname via the host-mounted config.json, restarts, verifies.
set -u

API="http://172.17.0.1:8090"
DOMAIN="dishnetuganda.com"
CERTDIR="/opt/stalwart-certs/mail.${DOMAIN}"
TMPD=$(mktemp -d)
trap 'rm -rf "$TMPD"' EXIT

say()  { printf '%s\n' "$*"; }
line() { printf '────────────────────────────────────────────────────────\n'; }

# ── 0) Self-heal volume ownership ───────────────────────────────────────────
CUID=$(docker exec stalwart id -u 2>/dev/null || true)
CGID=$(docker exec stalwart id -g 2>/dev/null || true)
if [ -n "$CUID" ]; then
  OWNER=$(stat -c %u data/stalwart 2>/dev/null || echo '?')
  if [ "$OWNER" != "$CUID" ]; then
    chown -R "${CUID}:${CGID:-$CUID}" data/stalwart data/stalwart-etc 2>/dev/null
    say "ok   data dirs handed to container user uid=${CUID}"
  fi
fi

# ── 1) Authenticate: try both admin names across the candidate passwords ────
CANDIDATES=()
[ -n "${STALWART_PASS:-}" ] && CANDIDATES+=("$STALWART_PASS")
if [ -f .env ]; then
  ENVPASS=$(grep -E '^STALWART_ADMIN=' .env | cut -d: -f2-)
  [ -n "$ENVPASS" ] && CANDIDATES+=("$ENVPASS")
fi
AUTH=""; LASTCODE=""
for u in "admin" "admin@${DOMAIN}"; do
  for c in "${CANDIDATES[@]}"; do
    LASTCODE=$(curl -s -o "$TMPD/session.json" -w '%{http_code}' -u "${u}:${c}" "$API/jmap/session")
    if [ "$LASTCODE" = "200" ]; then AUTH="${u}:${c}"; break 2; fi
  done
done
if [ -z "$AUTH" ]; then
  line
  if [ "$LASTCODE" = "401" ] || [ "$LASTCODE" = "403" ]; then
    say "STOP: server is configured but the admin password is wrong (HTTP ${LASTCODE})."
    say "  Use the password from the wizard's 'Setup complete' screen in STALWART_PASS."
  else
    say "STOP: /jmap/session answered HTTP ${LASTCODE} — the wizard may not be finished."
    say "  Open https://mail.${DOMAIN}, complete it, and rerun this command."
  fi
  exit 1
fi
say "ok   authenticated as ${AUTH%%:*}"

# ── 2) Schema + session → account id and the real type/field names ─────────
curl -s -u "$AUTH" "$API/api/schema" -o "$TMPD/schema.json" || true
ACCOUNTS_PW="${ACCOUNTS_PASS:-$(openssl rand -base64 12)}"
PW_BHAVIN=$(openssl rand -base64 12); PW_KISHAN=$(openssl rand -base64 12)
PW_BILLING=$(openssl rand -base64 12); PW_STARLINK=$(openssl rand -base64 12)
PW_DMARC=$(openssl rand -base64 12)

python3 - "$TMPD" "$DOMAIN" "$ACCOUNTS_PW" "$PW_BHAVIN" "$PW_KISHAN" "$PW_BILLING" "$PW_STARLINK" "$PW_DMARC" <<'PYEOF' > "$TMPD/plan.sh"
import json, sys, re
tmpd, domain = sys.argv[1], sys.argv[2]
pw = dict(zip(["accounts","bhavin","kishan","billing","starlink","dmarc"], sys.argv[3:9]))

session = json.load(open(tmpd + "/session.json"))
# Account id: prefer the stalwart capability's primary account, else any.
prim = session.get("primaryAccounts") or {}
acct = prim.get("urn:stalwart:jmap") or (list(prim.values())[0] if prim else None)
if not acct:
    accts = session.get("accounts") or {}
    acct = next(iter(accts), None)
if not acct:
    print('say "STOP: no account id in /jmap/session — paste this file to Claude:"; cat ' + tmpd + '/session.json')
    sys.exit(0)

# Schema: find the admin object types and their field names.
types = {}
try:
    schema = json.load(open(tmpd + "/schema.json"))
    def walk(o):
        if isinstance(o, dict):
            n = o.get("name") or o.get("type") or o.get("id")
            fields = o.get("fields") or o.get("properties")
            if isinstance(n, str) and isinstance(fields, (list, dict)):
                fl = list(fields.keys()) if isinstance(fields, dict) else \
                     [f.get("id") or f.get("name") for f in fields if isinstance(f, dict)]
                types.setdefault(n, [x for x in fl if x])
            for v in o.values(): walk(v)
        elif isinstance(o, list):
            for v in o: walk(v)
    walk(schema)
except Exception:
    pass

def pick_type(cands):
    for c in cands:
        for t in types:
            if t.lower() == c: return t
    return None
user_t   = pick_type(["user", "account", "individual", "principal"]) or "User"
domain_t = pick_type(["domain"]) or "Domain"

def pick_field(fl, *pats):
    for p in pats:
        for f in fl:
            if re.fullmatch(p, f, re.I): return f
    return None
ufl = types.get(user_t, [])
f_login  = pick_field(ufl, "loginName", "login", "name", "username") or "name"
f_desc   = pick_field(ufl, "description", "fullName", "displayName") or "description"
f_email  = pick_field(ufl, "emails", "email", "emailAddresses", "addresses") or "emails"
f_secret = pick_field(ufl, "password", "secrets?") or "secret"
plural_email = not f_email.endswith(("l", "s")) or f_email.endswith("s")

def jmap(calls):
    return json.dumps({"using": ["urn:ietf:params:jmap:core", "urn:stalwart:jmap",
                                 "urn:ietf:params:jmap:principals"],
                       "methodCalls": calls})

def esc(s): return s.replace("'", "'\\''")

print(f'ACCT="{acct}"')
print(f'say "ok   account id {acct}; types: {user_t}/{domain_t}; fields: {f_login},{f_desc},{f_email},{f_secret}"')

# One query to list existing logins so creates can be skipped.
q = jmap([[f"{user_t}/query", {"accountId": acct}, "0"],
          [f"{user_t}/get", {"accountId": acct,
             "#ids": {"resultOf": "0", "name": f"{user_t}/query", "path": "/ids"},
             "properties": [f_login, f_email]}, "1"]])
print(f"EXISTING=$(curl -s -u \"$AUTH\" -X POST -H 'Content-Type: application/json' -d '{esc(q)}' \"$API/jmap\")")

dm = jmap([[f"{domain_t}/set", {"accountId": acct,
            "create": {"d1": {"name": domain}}}, "0"]])
print(f"DOMRESP=$(curl -s -u \"$AUTH\" -X POST -H 'Content-Type: application/json' -d '{esc(dm)}' \"$API/jmap\")")
print('echo "$DOMRESP" | grep -q \'"created"\' && say "ok   domain create call accepted" || say "info domain call: $(echo "$DOMRESP" | head -c 160)"')

users = [("accounts", "Accounts", []),
         ("bhavin", "Bhavin Madlani", []),
         ("kishan", "Kishan", []),
         ("billing", "Billing", ["info", "postmaster", "abuse"]),
         ("starlink", "Starlink intake", []),
         ("dmarc", "DMARC reports", [])]
for u, d, extra in users:
    emails = [f"{u}@{domain}"] + [f"{e}@{domain}" for e in extra]
    obj = {f_login: u, f_desc: d, f_secret: pw[u],
           f_email: emails if plural_email else emails[0]}
    call = jmap([[f"{user_t}/set", {"accountId": acct, "create": {"c1": obj}}, "0"]])
    print(f'if echo "$EXISTING" | grep -q \'"{u}@{domain}"\'; then say "ok   {u}@ already exists — untouched"; else')
    print(f"  R=$(curl -s -u \"$AUTH\" -X POST -H 'Content-Type: application/json' -d '{esc(call)}' \"$API/jmap\")")
    print(f'  if echo "$R" | grep -q \'"c1"\' && ! echo "$R" | grep -q notCreated; then say "ok   {u}@{domain} created";')
    print(f'  else say "FAIL {u}@ — $(echo "$R" | head -c 220)"; FAILED=1; fi')
    print('fi')
PYEOF

FAILED=0
line
say "1) Creating domain and mailboxes over JMAP"
# shellcheck disable=SC1090
source "$TMPD/plan.sh"

line
say "2) TLS certificate + hostname via config.json (file config overrides DB)"
python3 - <<PYEOF
import json
p = "data/stalwart-etc/config.json"
cfg = json.load(open(p))
cfg["certificate.default.cert"] = "%{file:${CERTDIR}/fullchain.pem}%"
cfg["certificate.default.private-key"] = "%{file:${CERTDIR}/privkey.pem}%"
cfg["server.hostname"] = "mail.${DOMAIN}"
json.dump(cfg, open(p, "w"), indent=2)
print("ok   config.json now carries certificate + hostname")
PYEOF
[ -n "$CUID" ] && chown "${CUID}:${CGID:-$CUID}" data/stalwart-etc/config.json 2>/dev/null

line
say "3) Restarting and verifying"
docker restart stalwart >/dev/null && sleep 8
curl -s -o /dev/null -w 'admin UI  https://mail.'"$DOMAIN"'   HTTP %{http_code}\n' "https://mail.${DOMAIN}"
timeout 6 bash -c "echo | openssl s_client -connect mail.${DOMAIN}:993 -brief 2>&1 | head -2" || say "imaps :993 not answering (TLS may need the UI route instead)"

line
say "MAILBOX PASSWORDS — write these down NOW (shown once; pre-existing users keep their old password):"
printf '  %-34s %s\n' "accounts@${DOMAIN}" "${ACCOUNTS_PW}"
printf '  %-34s %s\n' "bhavin@${DOMAIN}"   "${PW_BHAVIN}"
printf '  %-34s %s\n' "kishan@${DOMAIN}"   "${PW_KISHAN}"
printf '  %-34s %s\n' "billing@${DOMAIN}"  "${PW_BILLING}"
printf '  %-34s %s\n' "starlink@${DOMAIN}" "${PW_STARLINK}"
printf '  %-34s %s\n' "dmarc@${DOMAIN}"    "${PW_DMARC}"
line
say "NEXT:"
say "  1. Webmail test: https://webmail.${DOMAIN} — username accounts@${DOMAIN} (full address)."
say "  2. Admin UI → Domains → ${DOMAIN} → DNS records: copy the _domainkey TXT rows to GoDaddy."
say "  3. GoDaddy: delete the mailstore1.secureserver.net MX row (cutover)."
say "  4. Test email:  email_setup.php … --from billing@${DOMAIN} --test bhavin@${DOMAIN}"
[ "$FAILED" = "0" ] && say "RESULT: PASS" || say "RESULT: PARTIAL — paste this whole output to Claude"
