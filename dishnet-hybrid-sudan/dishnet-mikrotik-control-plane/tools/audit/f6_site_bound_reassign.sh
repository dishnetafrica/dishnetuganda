#!/bin/bash
# Decision 2a evidence under the CLOSED site-bound policy (docs/68 §2.5).
#
# Two things earlier evidence did not cover:
#   1. the mapping keyed on SITE, not customer — §2.4f measured a customer key
#   2. ROUTER REASSIGNMENT — the invariant that a site/router move must not
#      leave stale authorization behind. Never measured before.
#
# Disposable only. Reuses the environment f6_radius_restriction.sh builds and
# never contacts Phase 0. All values synthetic.
set -uo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"
R="${F6_RAD_DIR:-/tmp/f6-radx}"
D="host=/var/tmp port=55432 dbname=radx user=postgres"
P1=SYN-PASS-1; P2=SYN-PASS-2
SEC_A=synth-secret-aaa; SEC_B=synth-secret-bbb
Q="$R/mods-config/sql/main/postgresql/queries.conf"

command -v freeradius >/dev/null || { echo "SKIP: freeradius not installed"; exit 0; }
[ -f "$Q.stock" ] || { echo "SKIP: run f6_radius_restriction.sh first"; exit 0; }

psql "$D" -qAt -c "
DROP TABLE IF EXISTS dnb_cred_site, dnb_site_nas;
CREATE TABLE dnb_cred_site (radius_username text PRIMARY KEY, site_id text NOT NULL);
CREATE TABLE dnb_site_nas  (site_id text NOT NULL, nas_ip text NOT NULL, PRIMARY KEY (site_id, nas_ip));
GRANT ALL ON dnb_cred_site, dnb_site_nas TO radx;" >/dev/null

cp "$Q.stock" "$Q"
python3 - "$Q" <<'PY'
import sys
p = sys.argv[1]
lines = open(p).read().split("\n")
start = next(i for i,l in enumerate(lines) if l.startswith('authorize_check_query = "'))
end   = next(i for i in range(start, len(lines)) if lines[i].rstrip().endswith('ORDER BY id"'))
new = '''authorize_check_query = "\\
\tSELECT c.id, c.UserName, c.Attribute, c.Value, c.Op \\
\tFROM ${authcheck_table} AS c \\
\tWHERE c.Username = '%{SQL-User-Name}' \\
\t  AND EXISTS (SELECT 1 FROM dnb_cred_site cs \\
\t                JOIN dnb_site_nas sn ON sn.site_id = cs.site_id \\
\t               WHERE cs.radius_username = c.UserName \\
\t                 AND sn.nas_ip = '%{Packet-Src-IP-Address}') \\
\tORDER BY c.id"'''.split("\n")
open(p,"w").write("\n".join(lines[:start] + new + lines[end+1:]))
PY
: > "$R/mods-config/preprocess/huntgroups"
pkill -x freeradius 2>/dev/null; sleep 1
freeradius -d "$R" -l stdout -xx > "$R/sb.log" 2>&1 & sleep 4
pgrep -x freeradius >/dev/null || { echo "FAIL: did not start"; tail -4 "$R/sb.log"; exit 1; }

q(){ psql "$D" -qAt -c "$1" >/dev/null; }
u1A(){ python3 "$HERE/f6_rad_client.py" 127.0.0.1 127.0.0.1 1812 "$SEC_A" SYN-USER-1 "$P1"; }
u1B(){ python3 "$HERE/f6_rad_client.py" 127.0.0.2 127.0.0.2 1812 "$SEC_B" SYN-USER-1 "$P1"; }
u2A(){ python3 "$HERE/f6_rad_client.py" 127.0.0.1 127.0.0.1 1812 "$SEC_A" SYN-USER-2 "$P2"; }
u2B(){ python3 "$HERE/f6_rad_client.py" 127.0.0.2 127.0.0.2 1812 "$SEC_B" SYN-USER-2 "$P2"; }
r4(){ printf "  %-40s U1@A=%-15s U1@B=%-15s U2@A=%-15s U2@B=%s\n" "$1" "$(u1A)" "$(u1B)" "$(u2A)" "$(u2B)"; }

base(){ q "TRUNCATE radcheck, dnb_cred_site, dnb_site_nas;
  INSERT INTO radcheck (username,attribute,op,value) VALUES
    ('SYN-USER-1','Cleartext-Password',':=','$P1'),
    ('SYN-USER-2','Cleartext-Password',':=','$P2');
  INSERT INTO dnb_cred_site VALUES ('SYN-USER-1','site-a'), ('SYN-USER-2','site-b');
  INSERT INTO dnb_site_nas  VALUES ('site-a','127.0.0.1'), ('site-b','127.0.0.2');"; }

echo "  U1 belongs to site-a (NAS 127.0.0.1) · U2 to site-b (NAS 127.0.0.2)"
echo
base;  r4 "1 baseline: each at its own site"
q "INSERT INTO dnb_site_nas VALUES ('site-a','127.0.0.2');"
       r4 "2 site-a gains a SECOND router"
base
echo
echo "  --- REASSIGNMENT: 127.0.0.1 moves from site-a to site-b ---"
echo "  --- one row changed, no credential touched, no restart ---"
q "UPDATE dnb_site_nas SET site_id='site-b' WHERE nas_ip='127.0.0.1';"
       r4 "3 after the move"
base
echo
q "DELETE FROM dnb_site_nas WHERE site_id='site-a';"
       r4 "4 site-a has no routers"
base
q "DELETE FROM dnb_cred_site WHERE radius_username='SYN-USER-1';"
       r4 "5 U1 has no site"
base
printf "  %-40s %s\n" "6 sanity: U1 wrong password at its own site" \
  "$(python3 "$HERE/f6_rad_client.py" 127.0.0.1 127.0.0.1 1812 "$SEC_A" SYN-USER-1 WRONG)"

cp "$Q.stock" "$Q"; pkill -x freeradius 2>/dev/null
echo "(stock query restored; server stopped)"

# ---------------------------------------------------------------------------
# Requirement 13 (failure/recovery): the mapping tables are load-bearing for
# security under C-b, so the BROKEN case must be measured, not reasoned about.
# Empty rows were measured above (cases 4, 5); a missing TABLE is a different
# failure — the authorize query itself errors. Does the module fail closed?
# ---------------------------------------------------------------------------
