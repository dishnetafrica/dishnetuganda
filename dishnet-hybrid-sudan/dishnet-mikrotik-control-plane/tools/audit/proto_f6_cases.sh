#!/bin/sh
# Ten required F6 cases against the disposable prototype, plus the site-binding
# matrix. Every line is a real call by a real role; nothing is asserted from
# reading the SQL.
D="host=/var/tmp port=55432 dbname=dnb_f6"
r(){ printf "  %-52s %s\n" "$1" "$2"; }
q(){ psql "$D user=$1" -At -F'|' -c "$2" 2>&1 | tr '\n' ' ' | sed 's/  */ /g;s/ $//'; }
see(){ out=$(q "$1" "$2"); case "$out" in *ERROR*) echo "DENIED: ${out#*ERROR: }";; *) echo "${out:-nothing}";; esac; }
seed(){ psql "$D user=postgres" -q -f tools/audit/proto_f6_seed.sql; }
mode(){ psql "$D user=postgres" -qAt -c "UPDATE pf_settings SET v='$1' WHERE k='site_binding_mode';" >/dev/null; }

PORTAL="SELECT ok||'|'||reason||'|'||coalesce(radius_username,'-') FROM pf_portal_redeem"

echo "=== 1-5, 8-10: the required cases (site binding mode A) ==="
seed; mode A
r "1  guest redeems P's code at P's site"     "$(see proto_portal "$PORTAL('AAAAA-11111','nas-p1');")"
r "2  guest redeems Q's code at Q's site"     "$(see proto_portal "$PORTAL('BBBBB-11111','nas-q1');")"
r "3  guest presents P's code at Q's NAS"     "$(see proto_portal "$PORTAL('AAAAA-22222','nas-q1');")"
r "4  unknown code"                           "$(see proto_portal "$PORTAL('ZZZZZ-99999','nas-p1');")"
r "5  code already redeemed (case 1 again)"   "$(see proto_portal "$PORTAL('AAAAA-11111','nas-p1');")"
r "6  customer role calls the portal fn"      "$(see proto_cust "$PORTAL('BBBBB-11111','nas-q1');")"
r "7  customer role redeems its OWN code"     "$(see proto_cust "$PORTAL('AAAAA-33333','nas-p1');")"
r "8  admin support redemption, with reason"  "$(see proto_adm6 "SELECT ok||'|'||coalesce(customer_id::text,'-') FROM pf_admin_redeem('AAAAA-55555','alice','guest lost the printed slip');")"
r "8b admin support redemption, no reason"    "$(see proto_adm6 "SELECT ok FROM pf_admin_redeem('AAAAA-66666','alice','');")"
r "9  RADIUS accounts for a redeemed code"    "$(see proto_rad6 "SELECT pf_radius_account('p-AAAAA11111', 4096) IS NOT NULL;")"
r "9b RADIUS tries to redeem"                 "$(see proto_rad6 "$PORTAL('AAAAA-33333','nas-p1');")"
r "9c RADIUS accounts for an UNREDEEMED code" "$(see proto_rad6 "SELECT pf_radius_account('p-AAAAA33333', 4096) IS NOT NULL;")"

echo
echo "=== 10: caller-supplied identity has no authority ==="
r "10a portal sets app.customer_id to Q"      "$(see proto_portal "SET app.customer_id='22222222-2222-4222-8222-222222222222'; $PORTAL('AAAAA-33333','nas-p1');")"
r "10b portal sets app.site_id to Q's site"   "$(see proto_portal "SET app.site_id='b1111111-1111-4111-8111-111111111111'; $PORTAL('AAAAA-00000','nas-p1');")"
r "10c portal reads the voucher table"        "$(see proto_portal "SELECT count(*) FROM pf_vouchers;")"
r "10d portal updates a voucher directly"     "$(see proto_portal "UPDATE pf_vouchers SET state='unused' WHERE code='AAAAA-11111';")"
r "10e portal reads the customer table"       "$(see proto_portal "SELECT count(*) FROM pf_customers;")"
r "10f portal reads the redemption log"       "$(see proto_portal "SELECT count(*) FROM pf_redemption_log;")"
r "10g does the guest reply carry any uuid?"  "$(q postgres "SELECT string_agg(a.attname||':'||format_type(a.atttypid,NULL),', ') FROM pg_proc p, unnest(p.proallargtypes) WITH ORDINALITY t(ty,i), LATERAL (SELECT p.proargnames[i] AS attname, ty AS atttypid) a WHERE p.proname='pf_portal_redeem' AND p.proargmodes[i]='t';")"

echo
echo "=== site binding: the same two presentations under A, B and C ==="
for m in A B C; do
  seed; mode $m
  same=$(see proto_portal "$PORTAL('AAAAA-11111','nas-p1');")
  cross=$(see proto_portal "$PORTAL('AAAAA-22222','nas-p2');")
  unb=$(see proto_portal "$PORTAL('AAAAA-00000','nas-p1');")
  foreign=$(see proto_portal "$PORTAL('AAAAA-33333','nas-q1');")
  printf "  mode %s  issued@S1 used@S1: %-22s issued@S1 used@S2: %-24s\n" "$m" "$same" "$cross"
  printf "           unbound  used@S1: %-22s P's code at Q's NAS: %-24s\n" "$unb" "$foreign"
done

echo
echo "=== what the log holds after the mode-C run ==="
psql "$D user=postgres" -At -F'|' -c "SELECT actor_kind||' '||actor||' nas='||coalesce(nas,'-')||' code='||coalesce(code_prefix,'-')||'…'||' cust='||coalesce(customer_id::text,'NULL')||' -> '||outcome FROM pf_redemption_log ORDER BY id;" | sed 's/^/  /'

echo
echo "=== question 5: the operator grant is separable ==="
seed; mode A
r "customer role activates its own voucher"   "$(see proto_cust "SELECT ok FROM pf_operator_activate('c0000003-0000-4000-8000-000000000003','11111111-1111-4111-8111-111111111111');")"
psql "$D user=postgres" -qAt -c "GRANT EXECUTE ON FUNCTION pf_operator_activate(uuid,uuid) TO proto_cust;" >/dev/null
r "...after an explicit grant, own voucher"   "$(see proto_cust "SELECT ok FROM pf_operator_activate('c0000003-0000-4000-8000-000000000003','11111111-1111-4111-8111-111111111111');")"
r "...and Q's voucher with Q's uuid supplied" "$(see proto_cust "SELECT ok FROM pf_operator_activate('d0000001-0000-4000-8000-000000000001','22222222-2222-4222-8222-222222222222');")"
psql "$D user=postgres" -qAt -c "REVOKE EXECUTE ON FUNCTION pf_operator_activate(uuid,uuid) FROM proto_cust;" >/dev/null
echo "  -- the same operation with authority derived, not supplied --"
seed
r "P's session activates P's own voucher"      "$(see proto_cust "SET app.session_key='TOK-P'; SELECT ok FROM pf_operator_activate2('c0000003-0000-4000-8000-000000000003');")"
r "P's session activates Q's voucher"          "$(see proto_cust "SET app.session_key='TOK-P'; SELECT ok FROM pf_operator_activate2('d0000001-0000-4000-8000-000000000001');")"
r "no session at all"                          "$(see proto_cust "SELECT ok FROM pf_operator_activate2('c0000003-0000-4000-8000-000000000003');")"
r "caller names Q's uuid as its session key"   "$(see proto_cust "SET app.session_key='22222222-2222-4222-8222-222222222222'; SELECT ok FROM pf_operator_activate2('d0000001-0000-4000-8000-000000000001');")"

echo
echo "=== concurrency: two portals present the same code at once ==="
seed; mode A
psql "$D user=proto_portal" -At -c "$PORTAL('AAAAA-11111','nas-p1');" > /var/tmp/f6a.txt 2>&1 &
psql "$D user=proto_portal" -At -c "$PORTAL('AAAAA-11111','nas-p1');" > /var/tmp/f6b.txt 2>&1 &
wait
r "outcome A"                                 "$(tr -d '\n' < /var/tmp/f6a.txt)"
r "outcome B"                                 "$(tr -d '\n' < /var/tmp/f6b.txt)"
r "rows that say redeemed for that voucher"   "$(q postgres "SELECT count(*) FROM pf_redemption_log WHERE outcome='redeemed' AND voucher_id='c0000001-0000-4000-8000-000000000001';")"
