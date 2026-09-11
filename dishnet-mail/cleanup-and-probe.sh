#!/usr/bin/env bash
# cleanup-and-probe.sh — undo the failed config-file experiments and report
# what this Stalwart build actually exposes.
#
#   cd /opt/dishnet/dishnet-mail && bash cleanup-and-probe.sh
#
# Why: /etc/stalwart/config.json turned out to be only the STORE descriptor
# (@type/path/blobSize/bufferSize/cacheSize/poolWorkers — the storage page of
# the wizard). Settings such as certificates live in the database, so keys
# added to that file are ignored. This restores the file to exactly the store
# descriptor and then dumps the admin schema so the remaining automation can
# target the real API.
set -u
CFG="data/stalwart-etc/config.json"
say() { printf '%s\n' "$*"; }

say "1) Restoring config.json to the store descriptor only"
python3 - "$CFG" <<'PYEOF'
import json, sys
p = sys.argv[1]
cfg = json.load(open(p))
keep = {k: v for k, v in cfg.items() if "." not in k}   # @type, path, blobSize, …
removed = sorted(k for k in cfg if "." in k)
json.dump(keep, open(p, "w"), indent=2)
print("  ok   kept: " + ", ".join(sorted(keep)))
print("  ok   removed ignored keys: " + (", ".join(removed) if removed else "none"))
PYEOF
chown "$(docker exec stalwart id -u 2>/dev/null || echo 2000)":"$(docker exec stalwart id -g 2>/dev/null || echo 2000)" "$CFG" 2>/dev/null
rm -f "$CFG".bak.* "$CFG".rollback 2>/dev/null

say "2) Restarting (also clears any auth lockout from repeated probing)"
docker restart stalwart >/dev/null && sleep 10
timeout 6 bash -c 'echo | openssl s_client -connect mail.dishnetuganda.com:993 2>/dev/null | openssl x509 -noout -subject' || say "  warn :993 quiet"

say "3) What the admin API exposes"
AUTH="admin:${STALWART_PASS:-ERML5ziXSfaM1j18}"
printf '  jmap/session  '; curl -s -o /dev/null -w '%{http_code}\n' -u "$AUTH" http://172.17.0.1:8090/jmap/session
printf '  api/schema    '; curl -s -o /dev/null -w '%{http_code}\n' -u "$AUTH" http://172.17.0.1:8090/api/schema
curl -s -u "$AUTH" http://172.17.0.1:8090/api/schema -o /tmp/dn_schema.json
python3 - <<'PYEOF'
import json
try:
    d = json.load(open("/tmp/dn_schema.json"))
except Exception as e:
    print("  FAIL could not read schema:", e); raise SystemExit
def names(o, depth=0, out=None):
    out = out if out is not None else set()
    if isinstance(o, dict):
        for k, v in o.items():
            if k in ("type", "name", "id", "class") and isinstance(v, str) and v[:1].isupper():
                out.add(v)
            names(v, depth+1, out)
    elif isinstance(o, list):
        for v in o: names(v, depth+1, out)
    return out
top = list(d.keys())[:25] if isinstance(d, dict) else "(list)"
print("  ok   schema top-level:", top)
found = sorted(n for n in names(d) if 3 < len(n) < 28)
print("  ok   capitalised names in schema (%d): %s" % (len(found), ", ".join(found[:60])))
PYEOF
say "Paste this whole output to Claude."
