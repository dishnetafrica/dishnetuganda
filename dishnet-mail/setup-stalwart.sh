#!/usr/bin/env bash
# setup-stalwart.sh — configure a wizarded Stalwart v0.16 headlessly.
#
# v3: the server said "unknownMethod" to User/set and Domain/set, so this
# version DISCOVERS the JMAP method family first (Principal/…, Account/…,
# etc.) by probing harmless empty calls, then creates the domain and the
# mailboxes with automatic retries across field-name variants. Every server
# answer that blocks progress is printed verbatim.
#
#   cd /opt/dishnet/dishnet-mail
#   STALWART_PASS='admin-password' ACCOUNTS_PASS='accounts@ password' bash setup-stalwart.sh
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
  # The dumper writes the LE certificate as root; Stalwart (uid 2000) must be
  # able to READ it or it silently serves a self-signed one — the exact
  # symptom the last run showed on :993. Re-applied every run because cert
  # renewals recreate the files as root.
  chmod -R a+rX data/certs 2>/dev/null && say "ok   certificate files readable by the mail server"
fi

# ── 1) Authenticate ─────────────────────────────────────────────────────────
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
  say "STOP: could not authenticate to /jmap/session (HTTP ${LASTCODE})."
  say "  Put the wizard's generated admin password in STALWART_PASS and rerun."
  exit 1
fi
say "ok   authenticated as ${AUTH%%:*}"

# ── 2) Discover the method family and create everything (pure python) ──────
FAILED=0
line
say "1) Discovering JMAP admin methods and creating domain + mailboxes"
PW_ACCOUNTS="${ACCOUNTS_PASS:-$(openssl rand -base64 12)}"
PW_BHAVIN="$(openssl rand -base64 12)"; PW_KISHAN="$(openssl rand -base64 12)"
PW_BILLING="$(openssl rand -base64 12)"; PW_STARLINK="$(openssl rand -base64 12)"
PW_DMARC="$(openssl rand -base64 12)"
AUTH="$AUTH" API="$API" DOMAIN="$DOMAIN" SESSION_FILE="$TMPD/session.json" \
PW_ACCOUNTS="$PW_ACCOUNTS" PW_BHAVIN="$PW_BHAVIN" PW_KISHAN="$PW_KISHAN" \
PW_BILLING="$PW_BILLING" PW_STARLINK="$PW_STARLINK" PW_DMARC="$PW_DMARC" \
python3 - <<'PYEOF'
import json, os, sys, base64, urllib.request

API    = os.environ["API"]
DOMAIN = os.environ["DOMAIN"]
user, _, pw = os.environ["AUTH"].partition(":")
basic = base64.b64encode(os.environ["AUTH"].encode()).decode()
failed = False

USING_VARIANTS = [
    ["urn:ietf:params:jmap:core", "urn:stalwart:jmap", "urn:ietf:params:jmap:principals"],
    ["urn:ietf:params:jmap:core", "urn:ietf:params:jmap:principals"],
    ["urn:ietf:params:jmap:core", "urn:stalwart:jmap"],
]

def jmap(calls):
    last = None
    for using in USING_VARIANTS:
        body = json.dumps({"using": using, "methodCalls": calls}).encode()
        req = urllib.request.Request(API + "/jmap", data=body, method="POST",
            headers={"Content-Type": "application/json", "Authorization": "Basic " + basic})
        try:
            with urllib.request.urlopen(req, timeout=20) as r:
                return json.load(r)["methodResponses"]
        except urllib.error.HTTPError as e:
            detail = e.read().decode("utf-8", "replace")[:400]
            last = f"HTTP {e.code}: {detail}"
            # A capability complaint means try the next using-list; anything
            # else will repeat identically, so surface it now.
            if "apab" not in detail and "using" not in detail:
                break
    raise RuntimeError(last or "request failed")

session = json.load(open(os.environ["SESSION_FILE"]))
prim = session.get("primaryAccounts") or {}
acct = prim.get("urn:stalwart:jmap") or next(iter(prim.values()), None) \
       or next(iter(session.get("accounts") or {}), None)
print(f"ok   account id {acct}")

# Which method family exists? unknownMethod means no; anything else means yes.
family = None
notes = []
for t in ["Principal", "Individual", "Account", "User", "Directory"]:
    try:
        r = jmap([[f"{t}/get", {"accountId": acct, "ids": []}, "0"]])[0]
        kind = r[1].get("type") if r[0] == "error" else "ok"
    except RuntimeError as e:
        kind = str(e)[:60]
    notes.append(f"{t}/get={kind}")
    if kind == "ok" and family is None:
        family = t
print("ok   probe: " + "  ".join(notes))
if family is None:
    print("FAIL no admin method family answered — paste this output to Claude")
    sys.exit(1)
print(f"ok   using {family}/set")

def setcall(create_obj):
    try:
        r = jmap([[f"{family}/set", {"accountId": acct, "create": {"c1": create_obj}}, "0"]])[0]
    except RuntimeError as e:
        return ("transport", {"type": "transport", "detail": str(e)})
    if r[0] == "error":
        return ("error", r[1])
    created = (r[1].get("created") or {})
    if "c1" in created:
        return ("created", created["c1"])
    return ("notCreated", (r[1].get("notCreated") or {}).get("c1", {}))

def existing_names():
    try:
        q = jmap([[f"{family}/query", {"accountId": acct}, "0"],
                  [f"{family}/get", {"accountId": acct,
                     "#ids": {"resultOf": "0", "name": f"{family}/query", "path": "/ids"}}, "1"]])
        for resp in q:
            if resp[0] == f"{family}/get":
                out = set()
                for item in resp[1].get("list") or []:
                    for v in item.values():
                        if isinstance(v, str): out.add(v.lower())
                        if isinstance(v, list):
                            out.update(str(x).lower() for x in v)
                return out
    except Exception as e:
        print(f"info existing-list unavailable ({e}) — will rely on alreadyExists")
    return set()

have = existing_names()

# Domain first.
if DOMAIN.lower() in have:
    print(f"ok   domain {DOMAIN} already present")
else:
    st, info = setcall({"name": DOMAIN, "type": "domain"})
    if st == "created":
        print(f"ok   domain {DOMAIN} created")
    elif st == "notCreated" and info.get("type") in ("alreadyExists", "invalidProperties") and "name" not in (info.get("properties") or []):
        print(f"ok   domain call: {info.get('type')} (treating as present)")
    else:
        print(f"FAIL domain — {json.dumps(info)[:220]}"); failed = True

users = [("accounts", "Accounts",        [],                                   os.environ["PW_ACCOUNTS"]),
         ("bhavin",   "Bhavin Madlani",  [],                                   os.environ["PW_BHAVIN"]),
         ("kishan",   "Kishan",          [],                                   os.environ["PW_KISHAN"]),
         ("billing",  "Billing",         ["info", "postmaster", "abuse"],      os.environ["PW_BILLING"]),
         ("starlink", "Starlink intake", [],                                   os.environ["PW_STARLINK"]),
         ("dmarc",    "DMARC reports",   [],                                   os.environ["PW_DMARC"])]

secret_variants = [("secrets", "list"), ("secret", "str"), ("password", "str")]
email_variants  = [("emails", "list"), ("email", "str")]

for name, desc, extra, upw in users:
    addr = f"{name}@{DOMAIN}"
    if addr.lower() in have or name.lower() in have:
        print(f"ok   {addr} already exists — untouched"); continue
    emails = [addr] + [f"{e}@{DOMAIN}" for e in extra]
    done = False; last = None
    for sk, sv in secret_variants:
        for ek, ev in email_variants:
            obj = {"name": name, "type": "individual", "description": desc,
                   sk: ([upw] if sv == "list" else upw),
                   ek: (emails if ev == "list" else emails[0])}
            st, info = setcall(obj)
            last = (obj_keys := f"{sk}/{ek}", st, info)
            if st == "created":
                print(f"ok   {addr} created ({sk},{ek})"); done = True; break
            if st == "notCreated" and info.get("type") == "alreadyExists":
                print(f"ok   {addr} already exists — untouched"); done = True; break
            # invalidProperties naming our variant fields → try the next combo;
            # anything else → stop and show it.
            props = info.get("properties") or []
            if not (info.get("type") == "invalidProperties" and any(p in (sk, ek, "type", "description") for p in props)):
                break
        if done: break
    if not done:
        print(f"FAIL {addr} — tried {last[0]}, got {json.dumps(last[2])[:220]}"); failed = True

# Reconnaissance for the certificate fix: which settings-ish families exist?
recon = []
for t in ["Setting", "Settings", "Certificate", "ServerSetting", "Queue"]:
    try:
        r = jmap([[f"{t}/get", {"accountId": acct, "ids": []}, "0"]])[0]
        recon.append(f"{t}={'ok' if r[0] != 'error' else r[1].get('type')}")
    except RuntimeError as e:
        recon.append(f"{t}=({str(e)[:40]})")
print("info settings probe: " + "  ".join(recon))

sys.exit(1 if failed else 0)
PYEOF
[ $? -ne 0 ] && FAILED=1

# ── 3) TLS certificate + hostname via config.json ───────────────────────────
line
say "2) TLS certificate + hostname via config.json"
python3 - <<PYEOF
import json
p = "data/stalwart-etc/config.json"
cfg = json.load(open(p))
cfg["certificate.default.cert"] = "%{file:${CERTDIR}/fullchain.pem}%"
cfg["certificate.default.private-key"] = "%{file:${CERTDIR}/privkey.pem}%"
cfg["server.hostname"] = "mail.${DOMAIN}"
json.dump(cfg, open(p, "w"), indent=2)
print("ok   config.json carries certificate + hostname")
PYEOF
[ -n "$CUID" ] && chown "${CUID}:${CGID:-$CUID}" data/stalwart-etc/config.json 2>/dev/null

line
say "3) Restarting and verifying"
docker restart stalwart >/dev/null && sleep 8
curl -s -o /dev/null -w 'admin UI  https://mail.'"$DOMAIN"'   HTTP %{http_code}\n' "https://mail.${DOMAIN}"
CERTLINE=$(timeout 6 bash -c "echo | openssl s_client -connect mail.${DOMAIN}:993 2>/dev/null | openssl x509 -noout -subject 2>/dev/null")
say "imaps :993 certificate: ${CERTLINE:-not answering}"
case "$CERTLINE" in
  *"$DOMAIN"*) say "ok   real Let's Encrypt certificate is being served" ;;
  *) say "warn :993 still shows a self-signed certificate — webmail login may complain; tell Claude" ;;
esac

line
say "MAILBOX PASSWORDS — write these down NOW (shown once; a user that ALREADY existed keeps its old password):"
printf '  %-34s %s\n' "accounts@${DOMAIN}" "$PW_ACCOUNTS"
printf '  %-34s %s\n' "bhavin@${DOMAIN}"   "$PW_BHAVIN"
printf '  %-34s %s\n' "kishan@${DOMAIN}"   "$PW_KISHAN"
printf '  %-34s %s\n' "billing@${DOMAIN}"  "$PW_BILLING"
printf '  %-34s %s\n' "starlink@${DOMAIN}" "$PW_STARLINK"
printf '  %-34s %s\n' "dmarc@${DOMAIN}"    "$PW_DMARC"
line
say "NEXT:"
say "  1. Webmail: https://webmail.${DOMAIN} — username accounts@${DOMAIN} (full address)."
say "  2. Admin UI → Domains → ${DOMAIN} → DNS records → copy _domainkey TXT rows to GoDaddy."
say "  3. GoDaddy: delete the mailstore1.secureserver.net MX row (cutover)."
say "  4. Test: email_setup.php … --from billing@${DOMAIN} --test bhavin@${DOMAIN}"
[ "$FAILED" = "0" ] && say "RESULT: PASS" || say "RESULT: PARTIAL — paste this whole output to Claude"
