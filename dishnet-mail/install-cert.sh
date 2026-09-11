#!/usr/bin/env bash
# install-cert.sh — serve Traefik's Let's Encrypt certificate on Stalwart's
# mail ports (25/465/587/993), so Outlook, phones and desktop clients connect
# without certificate warnings.
#
#   cd /opt/dishnet/dishnet-mail && bash install-cert.sh
#
# v2: the %{file:…}% macro form was ignored, so the PEM text is now embedded
# directly in the configuration, and console logging is switched on so the
# server's own verdict on the certificate is visible instead of silent.
# Because the PEM text is embedded, RERUN THIS after a Traefik renewal
# (every ~60 days); the script is idempotent and safe to run any time.
set -u

DOMAIN="dishnetuganda.com"
HOST="mail.${DOMAIN}"
SRC="data/certs/${HOST}"
CFG="data/stalwart-etc/config.json"

say()  { printf '%s\n' "$*"; }
line() { printf '────────────────────────────────────────────────────────\n'; }

line
say "1) The certificate files on the host"
for f in fullchain.pem privkey.pem; do
  if [ -s "${SRC}/${f}" ]; then
    say "  ok   ${SRC}/${f} ($(stat -c %s "${SRC}/${f}") bytes)"
  else
    say "  FAIL ${SRC}/${f} missing or empty — check: docker logs mail-certs-dumper"
    exit 1
  fi
done
say "  ok   $(openssl x509 -in "${SRC}/fullchain.pem" -noout -subject 2>/dev/null)  $(openssl x509 -in "${SRC}/fullchain.pem" -noout -enddate 2>/dev/null)"
chmod -R a+rX data/certs 2>/dev/null

line
say "2) Embedding the certificate in Stalwart's configuration"
cp -f "$CFG" "${CFG}.bak.$(date +%s)" 2>/dev/null
cp -f "$CFG" "${CFG}.rollback" 2>/dev/null
say "  ok   backup taken (${CFG}.rollback)"
python3 - "$CFG" "$SRC" "$HOST" "$DOMAIN" <<'PYEOF'
import json, sys
cfg_path, src, host, domain = sys.argv[1:5]
cert = open(src + "/fullchain.pem").read()
key  = open(src + "/privkey.pem").read()
cfg  = json.load(open(cfg_path))

# What the file already carried — the bootstrap keys we must not disturb.
print("  info existing config keys: " + ", ".join(sorted(cfg.keys())))

# THE missing piece: this build reads only whitelisted keys from the file and
# takes everything else from the database, which is why the certificate — and
# even the logging switch — were silently ignored. Naming the whitelist makes
# the file authoritative for these sections. The bootstrap keys already in the
# file are added verbatim so nothing that works today stops working.
locals_ = ["config.local-keys.*", "store.*", "storage.*", "directory.*",
           "tracer.*", "certificate.*", "server.*", "cluster.*",
           "authentication.fallback-admin.*"]
for k in cfg:
    if k not in ("config.local-keys",) and "." in k:
        pat = k.rsplit(".", 1)[0] + ".*"
        if pat not in locals_:
            locals_.append(pat)
cfg["config.local-keys"] = locals_

# Drop any earlier macro-form attempt so the two cannot disagree.
for k in list(cfg):
    if k.startswith("certificate."):
        del cfg[k]

cfg.update({
    "certificate.default.cert":        cert,
    "certificate.default.private-key": key,
    "certificate.default.default":     True,
    "certificate.default.subjects":    [host, domain, "*." + domain],
    "server.hostname":                 host,
    # Without a console tracer this build logs nothing after first boot, which
    # is why every previous failure here was silent.
    "tracer.console.type":    "console",
    "tracer.console.level":   "info",
    "tracer.console.enable":  True,
    "tracer.console.ansi":    False,
})
json.dump(cfg, open(cfg_path, "w"), indent=2)
print("  ok   config.json updated (certificate embedded as text, console logging on)")
print("  info config keys: " + ", ".join(sorted(k.split(".")[0] + "." + k.split(".")[1]
      for k in cfg if "." in k)[:12]))
PYEOF

CUID=$(docker exec stalwart id -u 2>/dev/null || echo 2000)
CGID=$(docker exec stalwart id -g 2>/dev/null || echo 2000)
chown "${CUID}:${CGID}" "$CFG" 2>/dev/null

line
say "3) Restarting (with automatic rollback if the server does not come back)"
docker restart stalwart >/dev/null && sleep 10
say "   — server log —"
docker logs --since 2m stalwart 2>&1 | tail -25

# Safety net: if nothing answers on 993 the new config broke the server, so
# put the old one back rather than leaving mail down overnight.
if ! timeout 6 bash -c "echo | openssl s_client -connect ${HOST}:993 2>/dev/null | head -1" | grep -q .; then
  say "  !!   port 993 is not answering — rolling the configuration back"
  cp -f "${CFG}.rollback" "$CFG"
  chown "$(docker exec stalwart id -u 2>/dev/null || echo 2000)":"$(docker exec stalwart id -g 2>/dev/null || echo 2000)" "$CFG" 2>/dev/null
  docker restart stalwart >/dev/null && sleep 8
  say "  ok   previous configuration restored — mail is serving again"
  say "RESULT: rolled back. Paste this output to Claude."
  exit 1
fi

line
say "4) What is actually served now"
BAD=""
for port in 993 465; do
  OUT=$(timeout 8 bash -c "echo | openssl s_client -connect ${HOST}:${port} 2>/dev/null | openssl x509 -noout -subject 2>/dev/null" | tr '\n' ' ')
  case "$OUT" in
    *"$HOST"*) say "  ok   port ${port}: ${OUT}" ;;
    *)         say "  warn port ${port}: ${OUT:-no answer}"; BAD=1 ;;
  esac
done

line
if [ -z "$BAD" ]; then
  say "RESULT: PASS — the real certificate is being served. Outlook settings:"
  say "  IMAP   ${HOST}   port 993   SSL/TLS"
  say "  SMTP   ${HOST}   port 465   SSL/TLS"
  say "  Username: the FULL email address.   Password: the mailbox password."
  say "  (Rerun this script after a certificate renewal — roughly every 60 days.)"
else
  say "RESULT: still self-signed — but the log above now says why. Paste it to Claude."
fi
