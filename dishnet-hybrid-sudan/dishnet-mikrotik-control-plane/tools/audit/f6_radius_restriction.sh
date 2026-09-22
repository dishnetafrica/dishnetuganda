#!/bin/bash
# Disposable FreeRADIUS experiment: can a radcheck check item confine a
# credential to one customer's NAS set?  Answers docs/68 §2.4c.
#
# Runs ONLY against a throwaway instance it builds itself.  It never touches
# Phase 0: no production host, no production database, no production config.
# Every credential, secret and address here is synthetic and corresponds to
# nothing real.
#
# Prerequisites: freeradius + freeradius-postgresql + python3, a local
# PostgreSQL on /var/tmp:55432, and root (FreeRADIUS binds port 1812).
set -uo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"
R="${F6_RAD_DIR:-/tmp/f6-radx}"
D="host=/var/tmp port=55432 dbname=radx user=postgres"
U=SYN-USER-1; P=SYN-PASS-1
SEC_A=synth-secret-aaa; SEC_B=synth-secret-bbb

command -v freeradius >/dev/null || { echo "SKIP: freeradius not installed"; exit 0; }
pkill -x freeradius 2>/dev/null; sleep 1   # a running instance holds radx open
psql "$D" -c 'SELECT 1' >/dev/null 2>&1 || {
  psql "host=/var/tmp port=55432 dbname=postgres user=postgres" -qAt \
       -c "DROP DATABASE IF EXISTS radx;" -c "CREATE DATABASE radx;" >/dev/null || {
    echo "SKIP: no local PostgreSQL on /var/tmp:55432"; exit 0; }
}

# ---- throwaway database, from the STOCK shipped schema -------------------
SCHEMA=$(ls /etc/freeradius/*/mods-config/sql/main/postgresql/schema.sql 2>/dev/null | head -1)
psql "host=/var/tmp port=55432 dbname=postgres user=postgres" -qAt \
     -c "DROP DATABASE IF EXISTS radx;" -c "CREATE DATABASE radx;" >/dev/null
psql "host=/var/tmp port=55432 dbname=postgres user=postgres" -qAt \
     -c "DO \$\$ BEGIN IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname='radx') THEN CREATE ROLE radx LOGIN; END IF; END \$\$;" >/dev/null
psql "$D" -q -f "$SCHEMA" >/dev/null 2>&1
psql "$D" -qAt -c "GRANT ALL ON SCHEMA public TO radx;
                   GRANT ALL ON ALL TABLES IN SCHEMA public TO radx;
                   GRANT ALL ON ALL SEQUENCES IN SCHEMA public TO radx;" >/dev/null

# ---- throwaway config tree ------------------------------------------------
CFG=$(ls -d /etc/freeradius/*/ | head -1)
rm -rf "$R"; cp -a "${CFG%/}" "$R"; chmod -R u+rwX "$R"

cat > "$R/mods-available/sql" <<CONF
sql {
	dialect = "postgresql"
	driver = "rlm_sql_\${dialect}"
	server = "/var/tmp"
	port = 55432
	login = "radx"
	password = ""
	radius_db = "radx"
	read_clients = no
	client_table = "nas"
	acct_table1 = "radacct"
	acct_table2 = "radacct"
	postauth_table = "radpostauth"
	authcheck_table = "radcheck"
	authreply_table = "radreply"
	groupcheck_table = "radgroupcheck"
	groupreply_table = "radgroupreply"
	usergroup_table = "radusergroup"
	sql_user_name = "%{%{Stripped-User-Name}:-%{%{User-Name}:-DEFAULT}}"
	group_attribute = "SQL-Group"
	\$INCLUDE \${modconfdir}/\${.:name}/main/\${dialect}/queries.conf
	pool {
		start = 1
		min = 1
		max = 4
		spare = 1
		uses = 0
		retry_delay = 5
		lifetime = 0
		idle_timeout = 60
	}
}
CONF
ln -sf ../mods-available/sql "$R/mods-enabled/sql"

# two synthetic clients on distinct loopback addresses = two tenants
cat > "$R/clients.conf" <<CONF
client synth-a {
	ipaddr    = 127.0.0.1
	secret    = $SEC_A
	shortname = cust-p-nas1
	nas_type  = other
}
client synth-b {
	ipaddr    = 127.0.0.2
	secret    = $SEC_B
	shortname = cust-q-nas1
	nas_type  = other
}
CONF

sed -i 's/^\([[:space:]]*\)user = freerad/\1user = root/; s/^\([[:space:]]*\)group = freerad/\1group = root/' "$R/radiusd.conf"

# this container has no IPv6; comment out ONLY listeners that actively set it
cp "${CFG%/}/sites-available/default" "$R/sites-enabled/default"
python3 - "$R/sites-enabled/default" <<'PY'
import re, sys
p = sys.argv[1]; out, buf, depth, v6 = [], [], 0, False
for line in open(p).read().split("\n"):
    if depth == 0 and re.match(r'^\s*listen\s*\{', line):
        depth, buf, v6 = 1, [line], False; continue
    if depth:
        buf.append(line)
        if re.match(r'^\s*ipv6addr\s*=', line): v6 = True   # active only, not a comment
        depth += line.count('{') - line.count('}')
        if depth == 0: out.extend(("#" + b if v6 else b) for b in buf)
        continue
    out.append(line)
open(p, "w").write("\n".join(out))
PY

mkdir -p "$R/mods-config/preprocess"
cat > "$R/mods-config/preprocess/huntgroups" <<'HG'
cust-p		Packet-Src-IP-Address == 127.0.0.1
cust-q		Packet-Src-IP-Address == 127.0.0.2
HG

pkill -x freeradius 2>/dev/null; sleep 1
freeradius -d "$R" -l stdout -xx > "$R/radius.log" 2>&1 &
sleep 4
pgrep -x freeradius >/dev/null || { echo "FAIL: server did not start"; sed -e "s/$SEC_A\|$SEC_B/<redacted>/g" "$R/radius.log" | tail -5; exit 1; }

seed(){ psql "$D" -qAt -c "TRUNCATE radcheck;
        INSERT INTO radcheck (username,attribute,op,value) VALUES ('$U','Cleartext-Password',':=','$P');" >/dev/null; }
A(){ python3 "$HERE/f6_rad_client.py" 127.0.0.1 127.0.0.1 1812 "$SEC_A" "$U" "$P" "$@"; }
B(){ python3 "$HERE/f6_rad_client.py" 127.0.0.2 127.0.0.2 1812 "$SEC_B" "$U" "$P" "$@"; }
row(){ printf "  %-44s A=%-15s B=%s\n" "$1" "$2" "$3"; }
put(){ psql "$D" -qAt -c "INSERT INTO radcheck (username,attribute,op,value) VALUES ('$U','$1','$2','$3');" >/dev/null; }

echo "=== sanity ==="
seed; printf "  %-44s correct=%-15s wrong=%s\n" "same client, correct vs wrong password" \
      "$(A)" "$(python3 "$HERE/f6_rad_client.py" 127.0.0.1 127.0.0.1 1812 "$SEC_A" "$U" WRONG)"
echo "=== baseline: no restriction (the production condition) ==="
seed; row "no restriction row" "$(A)" "$(B)"
echo "=== server-derived anchors ==="
seed; put Packet-Src-IP-Address == 127.0.0.1; row "Packet-Src-IP-Address == 127.0.0.1" "$(A)" "$(B)"
seed; put Client-IP-Address     == 127.0.0.1; row "Client-IP-Address == 127.0.0.1"     "$(A)" "$(B)"
seed; put Client-Shortname      == cust-p-nas1; row "Client-Shortname == cust-p-nas1"  "$(A)" "$(B)"
echo "=== client-asserted attributes (mechanism only) ==="
seed; put NAS-IP-Address == 10.0.0.11
row "NAS-IP-Address == 10.0.0.11" "$(A NAS-IP-Address=10.0.0.11)" "$(B NAS-IP-Address=10.0.0.99)"
seed; put NAS-Identifier == router-p1
row "NAS-Identifier == router-p1" "$(A NAS-Identifier=router-p1)" "$(B NAS-Identifier=router-q1)"
echo "=== expressing a SET ==="
seed; put Packet-Src-IP-Address == 127.0.0.1; put Packet-Src-IP-Address == 127.0.0.2
row "two '==' rows (are they OR or AND?)" "$(A)" "$(B)"
seed; put Packet-Src-IP-Address '=~' '^127\.0\.0\.[12]$'
row "one '=~' row matching both" "$(A)" "$(B)"
seed; put Huntgroup-Name == cust-p
row "Huntgroup-Name == cust-p  (P's group)" "$(A)" "$(B)"
seed; put Huntgroup-Name == cust-q
row "Huntgroup-Name == cust-q  (Q's group)" "$(A)" "$(B)"

pkill -x freeradius 2>/dev/null
echo "(server stopped; $R left in place for inspection)"
