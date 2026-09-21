#!/bin/bash
# Candidate C-b (docs/68 §2.4e): can the customer -> NAS set live in the SQL
# authorization path, so that adding a router is a row rather than a restart?
#
# Disposable only. Builds its own FreeRADIUS and database; never contacts
# Phase 0. Every credential, secret and address is synthetic.
#
# Two variants:
#   v1  stock tables only — the allow-list is rows in radcheck carrying a
#       private attribute name, excluded from the returned check items
#   v2  customer-level — two additive tables, so ONE row per customer-NAS
#       serves every credential of that customer
#
# Prerequisites: freeradius + freeradius-postgresql + python3, local PostgreSQL
# on /var/tmp:55432, root. Run f6_radius_restriction.sh first: this reuses the
# environment it builds.
set -uo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"
R="${F6_RAD_DIR:-/tmp/f6-radx}"
D="host=/var/tmp port=55432 dbname=radx user=postgres"
U=SYN-USER-1; P=SYN-PASS-1; SEC_A=synth-secret-aaa; SEC_B=synth-secret-bbb
Q="$R/mods-config/sql/main/postgresql/queries.conf"

command -v freeradius >/dev/null || { echo "SKIP: freeradius not installed"; exit 0; }
[ -f "$Q" ] || { echo "SKIP: run f6_radius_restriction.sh first to build $R"; exit 0; }
[ -f "$Q.stock" ] || cp "$Q" "$Q.stock"

A(){ python3 "$HERE/f6_rad_client.py" 127.0.0.1 127.0.0.1 1812 "$SEC_A" "$U" "$P"; }
B(){ python3 "$HERE/f6_rad_client.py" 127.0.0.2 127.0.0.2 1812 "$SEC_B" "$U" "$P"; }
q(){ psql "$D" -qAt -c "$1" >/dev/null; }
row(){ printf "  %-50s A=%-15s B=%s\n" "$1" "$2" "$3"; }

install_query(){        # $1 = the replacement directive, newline separated
  cp "$Q.stock" "$Q"
  python3 - "$Q" "$1" <<'PY'
import sys
p, new = sys.argv[1], sys.argv[2]
lines = open(p).read().split("\n")
# anchor on the line that STARTS with the directive. Matching anywhere would
# hit the commented-out case-insensitive variant above it and mangle the file.
start = next(i for i,l in enumerate(lines) if l.startswith('authorize_check_query = "'))
end   = next(i for i in range(start, len(lines)) if lines[i].rstrip().endswith('ORDER BY id"'))
open(p,"w").write("\n".join(lines[:start] + new.split("\n") + lines[end+1:]))
PY
  : > "$R/mods-config/preprocess/huntgroups"   # empty: no huntgroup may interfere
  pkill -x freeradius 2>/dev/null; sleep 1
  freeradius -d "$R" -l stdout -xx > "$R/cb.log" 2>&1 &
  sleep 4
  pgrep -x freeradius >/dev/null || { echo "FAIL: server did not start"; tail -4 "$R/cb.log"; exit 1; }
}

V1=$'authorize_check_query = "\\\n\tSELECT c.id, c.UserName, c.Attribute, c.Value, c.Op \\\n\tFROM ${authcheck_table} AS c \\\n\tWHERE c.Username = \'%{SQL-User-Name}\' \\\n\t  AND c.Attribute <> \'DNB-Allowed-NAS\' \\\n\t  AND EXISTS (SELECT 1 FROM ${authcheck_table} n \\\n\t               WHERE n.UserName = c.UserName \\\n\t                 AND n.Attribute = \'DNB-Allowed-NAS\' \\\n\t                 AND n.Value = \'%{Packet-Src-IP-Address}\') \\\n\tORDER BY c.id"'

V2=$'authorize_check_query = "\\\n\tSELECT c.id, c.UserName, c.Attribute, c.Value, c.Op \\\n\tFROM ${authcheck_table} AS c \\\n\tWHERE c.Username = \'%{SQL-User-Name}\' \\\n\t  AND EXISTS (SELECT 1 FROM dnb_cred_owner o \\\n\t                JOIN dnb_customer_nas m ON m.customer = o.customer \\\n\t               WHERE o.radius_username = c.UserName \\\n\t                 AND m.nas_ip = \'%{Packet-Src-IP-Address}\') \\\n\tORDER BY c.id"'

echo "=== v1: stock tables only ==="
install_query "$V1"
q "TRUNCATE radcheck;
   INSERT INTO radcheck (username,attribute,op,value) VALUES
     ('$U','Cleartext-Password',':=','$P'), ('$U','DNB-Allowed-NAS','==','127.0.0.1');"
row "single-NAS: allowed at A only" "$(A)" "$(B)"
printf "  %-50s %s\n" "wrong password from A (must still reject)" \
  "$(python3 "$HERE/f6_rad_client.py" 127.0.0.1 127.0.0.1 1812 "$SEC_A" "$U" WRONG)"
q "INSERT INTO radcheck (username,attribute,op,value) VALUES ('$U','DNB-Allowed-NAS','==','127.0.0.2');"
row "multi-NAS: second allow row, NO restart" "$(A)" "$(B)"
q "DELETE FROM radcheck WHERE username='$U' AND attribute='DNB-Allowed-NAS' AND value='127.0.0.2';"
row "removed again, NO restart" "$(A)" "$(B)"
q "DELETE FROM radcheck WHERE username='$U' AND attribute='DNB-Allowed-NAS';"
row "no allow rows (fail-closed?)" "$(A)" "$(B)"

echo
echo "=== v2: customer-level, two additive tables ==="
psql "$D" -qAt -c "
CREATE TABLE IF NOT EXISTS dnb_cred_owner   (radius_username text PRIMARY KEY, customer text NOT NULL);
CREATE TABLE IF NOT EXISTS dnb_customer_nas (customer text NOT NULL, nas_ip text NOT NULL,
                                             PRIMARY KEY (customer, nas_ip));
GRANT ALL ON dnb_cred_owner, dnb_customer_nas TO radx;" >/dev/null
install_query "$V2"
q "TRUNCATE radcheck, dnb_cred_owner, dnb_customer_nas;
   INSERT INTO radcheck (username,attribute,op,value) VALUES ('$U','Cleartext-Password',':=','$P');
   INSERT INTO dnb_cred_owner VALUES ('$U','cust-p');
   INSERT INTO dnb_customer_nas VALUES ('cust-p','127.0.0.1');"
row "customer P, one NAS" "$(A)" "$(B)"
q "INSERT INTO dnb_customer_nas VALUES ('cust-p','127.0.0.2');"
row "ONE insert adds a NAS to P, NO restart" "$(A)" "$(B)"
q "DELETE FROM dnb_customer_nas WHERE customer='cust-p' AND nas_ip='127.0.0.2';"
row "removed again, NO restart" "$(A)" "$(B)"
q "UPDATE dnb_cred_owner SET customer='cust-q' WHERE radius_username='$U';
   INSERT INTO dnb_customer_nas VALUES ('cust-q','127.0.0.2');"
row "credential reassigned to customer Q" "$(A)" "$(B)"
q "DELETE FROM dnb_customer_nas;"
row "no mapping at all (fail-closed?)" "$(A)" "$(B)"
q "DELETE FROM dnb_cred_owner;"
row "credential with no owner (fail-closed?)" "$(A)" "$(B)"

echo
echo "=== stock tables unmodified by either variant? ==="
psql "$D" -At -c "SELECT '  '||indexname||' | '||indexdef FROM pg_indexes WHERE tablename='radcheck';"
psql "$D" -At -c "SELECT '  '||count(*)||' constraints on radcheck/radreply (stock: 2)' FROM pg_constraint
                   WHERE conrelid IN ('radcheck'::regclass,'radreply'::regclass);"
cp "$Q.stock" "$Q"; pkill -x freeradius 2>/dev/null
echo "(stock query restored; server stopped)"
