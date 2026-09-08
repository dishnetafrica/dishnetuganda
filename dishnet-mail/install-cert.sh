#!/usr/bin/env bash
# install-cert.sh — serve Traefik's Let's Encrypt certificate on Stalwart's
# mail ports (25/465/587/993), so Outlook, phones and desktop clients connect
# without certificate warnings.
#
#   cd /opt/dishnet/dishnet-mail && bash install-cert.sh
#
# Traefik owns ACME on this box and the certs-dumper sidecar exports the PEMs;
# this script points Stalwart at those files and — the part the first attempt
# missed — marks the certificate as the DEFAULT one, which is what stops
# Stalwart falling back to its self-signed placeholder. Safe to rerun: it is
# idempotent and prints the certificate actually being served at the end.
set -u

DOMAIN="dishnetuganda.com"
HOST="mail.${DOMAIN}"
SRC="data/certs/${HOST}"
INCONTAINER="/opt/stalwart-certs/${HOST}"
CFG="data/stalwart-etc/config.json"

say()  { printf '%s\n' "$*"; }
line() { printf '────────────────────────────────────────────────────────\n'; }

line
say "1) The certificate files on the host"
for f in fullchain.pem privkey.pem; do
  if [ -s "${SRC}/${f}" ]; then
    say "  ok   ${SRC}/${f} ($(stat -c %s "${SRC}/${f}") bytes)"
  else
    say "  FAIL ${SRC}/${f} missing or empty."
    say "       The certs-dumper has not exported it. Check: docker logs mail-certs-dumper"
    exit 1
  fi
done
SUBJ=$(openssl x509 -in "${SRC}/fullchain.pem" -noout -subject 2>/dev/null)
ENDS=$(openssl x509 -in "${SRC}/fullchain.pem" -noout -enddate 2>/dev/null)
say "  ok   ${SUBJ}  ${ENDS}"

chmod -R a+rX data/certs 2>/dev/null
if docker exec stalwart head -c 30 "${INCONTAINER}/fullchain.pem" >/dev/null 2>&1; then
  say "  ok   readable inside the container at ${INCONTAINER}"
else
  say "  FAIL the container cannot read ${INCONTAINER}/fullchain.pem"
  exit 1
fi

line
say "2) Writing the certificate into Stalwart's configuration"
python3 - "$CFG" "$INCONTAINER" "$HOST" "$DOMAIN" <<'PYEOF'
import json, sys
cfg_path, certdir, host, domain = sys.argv[1:5]
cfg = json.load(open(cfg_path))
cfg.update({
    # The PEM contents are read from disk at startup, so a Traefik renewal is
    # picked up by a restart rather than needing this script again.
    "certificate.default.cert":        "%%{file:%s/fullchain.pem}%%" % certdir,
    "certificate.default.private-key": "%%{file:%s/privkey.pem}%%" % certdir,
    # The missing piece last time: without this, Stalwart keeps using its own
    # self-signed certificate for anything it cannot match by SNI.
    "certificate.default.default":     True,
    "certificate.default.subjects":    [host, domain, "*.%s" % domain],
    "server.tls.certificate":          "default",
    "server.hostname":                 host,
})
json.dump(cfg, open(cfg_path, "w"), indent=2)
print("  ok   config.json updated")
print("  info keys now present: " + ", ".join(sorted(k for k in cfg if k.startswith(("certificate", "server")))))
PYEOF

CUID=$(docker exec stalwart id -u 2>/dev/null || echo 2000)
CGID=$(docker exec stalwart id -g 2>/dev/null || echo 2000)
chown "${CUID}:${CGID}" "$CFG" 2>/dev/null

line
say "3) Restarting and checking what is actually served"
docker restart stalwart >/dev/null && sleep 9
for port in 993 465; do
  OUT=$(timeout 8 bash -c "echo | openssl s_client -connect ${HOST}:${port} 2>/dev/null | openssl x509 -noout -subject -issuer 2>/dev/null")
  if printf '%s' "$OUT" | grep -qi "$HOST"; then
    say "  ok   port ${port}: $(printf '%s' "$OUT" | tr '\n' ' ')"
  else
    say "  warn port ${port}: ${OUT:-no answer}"
    BAD=1
  fi
done

line
if [ -z "${BAD:-}" ]; then
  say "RESULT: PASS — the real certificate is being served. Outlook will connect cleanly:"
  say "  IMAP   ${HOST}   port 993   SSL/TLS"
  say "  SMTP   ${HOST}   port 465   SSL/TLS"
  say "  Username: the FULL email address.  Password: the mailbox password."
else
  say "RESULT: still self-signed. Paste this whole output to Claude — and the"
  say "  admin UI fallback is: https://${HOST} → search 'certificate' → set"
  say "  cert ${INCONTAINER}/fullchain.pem and key ${INCONTAINER}/privkey.pem."
fi
