#!/bin/sh
# The three lifecycle events that distinguish Model A from Model B, measured
# under each. Nothing here is asserted from reading code.
D="host=/var/tmp port=55432 dbname=dnb_f6"
r(){ printf "    %-46s %s\n" "$1" "$2"; }
q(){ psql "$D user=$1" -At -c "$2" 2>&1 | tr '\n' ' ' | sed 's/  */ /g;s/ $//'; }
see(){ out=$(q "$1" "$2"); case "$out" in *ERROR*) echo "DENIED: ${out#*ERROR: }";; *) echo "${out:-nothing}";; esac; }
P=11111111-1111-4111-8111-111111111111
S1=a1111111-1111-4111-8111-111111111111

for M in issue redeem; do
  psql "$D user=postgres" -q -f tools/audit/proto_f6_seed.sql
  psql "$D user=postgres" -qAt -c "UPDATE pf_life SET v='$M' WHERE k='aaa_created_at'; DELETE FROM pf_hotspot_users; DELETE FROM pf_vouchers;" >/dev/null
  echo "  == AAA identity created at: $M =="

  q postgres "SELECT pf_issue_batch('$P','$S1',5,'LIFE');" >/dev/null
  r "1 a batch of 5 printed, none sold"        "$(see proto_rad6 "SELECT pf_counts();")"
  r "  RADIUS sees an unsold code?"            "$(see proto_rad6 "SELECT coalesce(pf_radius_account('p-LIFE00001',1024)::text,'NULL — no identity');")"

  r "2 revoke an UNUSED voucher, as revoke() does" "$(see postgres "SELECT pf_revoke_asis(id) FROM pf_vouchers WHERE code='LIFE-00002';")"
  r "  counts"                                 "$(see proto_rad6 "SELECT pf_counts();")"
  r "  RADIUS still serves the revoked code?"  "$(see proto_rad6 "SELECT coalesce(pf_radius_account('p-LIFE00002',2048)::text,'NULL — no identity');")"

  see proto_portal "SELECT ok FROM pf_redeem_life('LIFE-00003','nas-p1');" >/dev/null
  r "3 one voucher redeemed"                   "$(see proto_rad6 "SELECT pf_counts();")"
  r "  RADIUS serves the redeemed code"        "$(see proto_rad6 "SELECT coalesce(pf_radius_account('p-LIFE00003',4096)::text,'NULL — no identity');")"

  psql "$D user=postgres" -qAt -c "UPDATE pf_vouchers SET expires_at = now() - interval '1 hour' WHERE code='LIFE-00003';" >/dev/null
  r "4 its expiry is now an hour in the past"  "$(see proto_rad6 "SELECT coalesce(pf_radius_account('p-LIFE00003',8192)::text,'NULL — no identity');")"

  r "5 revoke that ACTIVE, redeemed voucher"   "$(see postgres "SELECT pf_revoke_asis(id) FROM pf_vouchers WHERE code='LIFE-00003';")"
  r "  counts"                                 "$(see proto_rad6 "SELECT pf_counts();")"
  r "  RADIUS still serves the revoked code?"  "$(see proto_rad6 "SELECT coalesce(pf_radius_account('p-LIFE00003',16384)::text,'NULL — no identity');")"
  echo
done
