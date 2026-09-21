#!/bin/bash
# Decision 2a: the REMOVAL direction of a huntgroup change.
#
# E9 (docs/68 §2.4c) measured only the ADDITION direction — a file change that
# GRANTS authorization takes effect solely on a full restart. The Decision 2a
# invariant is the opposite direction: a reassignment must not leave stale
# authorization behind. Whether removal also waits for a restart is NOT
# established by E9, so it is measured here rather than inferred.
#
# Disposable only. Stock authorize query, untouched. All values synthetic.
set -uo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"
R="${F6_RAD_DIR:-/tmp/f6-radx}"
D="host=/var/tmp port=55432 dbname=radx user=postgres"
P1=SYN-PASS-1; SEC_A=synth-secret-aaa
H="$R/mods-config/preprocess/huntgroups"
Q="$R/mods-config/sql/main/postgresql/queries.conf"

command -v freeradius >/dev/null || { echo "SKIP: freeradius not installed"; exit 0; }
[ -f "$Q.stock" ] || { echo "SKIP: run f6_radius_restriction.sh first"; exit 0; }
cp "$Q.stock" "$Q"   # stock, username-only query: the huntgroup does the work

psql "$D" -qAt -c "TRUNCATE radcheck;
  INSERT INTO radcheck (username,attribute,op,value) VALUES
    ('SYN-USER-1','Cleartext-Password',':=','$P1'),
    ('SYN-USER-1','Huntgroup-Name','==','site-a');" >/dev/null

siteA(){ printf 'site-a\tNAS-IP-Address == 127.0.0.1\nsite-b\tNAS-IP-Address == 127.0.0.2\n' > "$H"; }
siteB(){ printf 'site-b\tNAS-IP-Address == 127.0.0.2\nsite-b\tNAS-IP-Address == 127.0.0.1\n' > "$H"; }
u1A(){ python3 "$HERE/f6_rad_client.py" 127.0.0.1 127.0.0.1 1812 "$SEC_A" SYN-USER-1 "$P1"; }
boot(){ pkill -x freeradius 2>/dev/null; sleep 1
        freeradius -d "$R" -l stdout -xx > "$R/hg.log" 2>&1 & sleep 4
        pgrep -x freeradius >/dev/null || { echo "FAIL: did not start"; tail -4 "$R/hg.log"; exit 1; }; }

echo "  U1 carries radcheck: Huntgroup-Name == site-a   (credential NEVER touched below)"
echo
siteA; boot
printf "  %-46s U1@127.0.0.1 = %s\n" "1 baseline: 127.0.0.1 is in site-a" "$(u1A)"
echo
echo "  --- REASSIGNMENT: 127.0.0.1 moves from site-a to site-b ---"
siteB
printf "  %-46s U1@127.0.0.1 = %s\n" "2 file rewritten, nothing else" "$(u1A)"
kill -HUP "$(pgrep -x freeradius | head -1)"; sleep 3
printf "  %-46s U1@127.0.0.1 = %s\n" "3 after SIGHUP" "$(u1A)"
boot
printf "  %-46s U1@127.0.0.1 = %s\n" "4 after FULL RESTART" "$(u1A)"

siteA; pkill -x freeradius 2>/dev/null
echo "(huntgroups reset; server stopped)"
