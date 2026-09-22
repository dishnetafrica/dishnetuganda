D="host=/var/tmp port=55432 dbname=dnb_actors"
P=11111111-1111-4111-8111-111111111111
Q=22222222-2222-4222-8222-222222222222
PK=$(psql "$D user=postgres" -At -c "SELECT encode(digest('TOK-P','sha256'),'hex')")
QK=$(psql "$D user=postgres" -At -c "SELECT encode(digest('TOK-Q','sha256'),'hex')")
AL=$(psql "$D user=postgres" -At -c "SELECT encode(digest('ADM-ALICE','sha256'),'hex')")
BO=$(psql "$D user=postgres" -At -c "SELECT encode(digest('ADM-BOB','sha256'),'hex')")
r(){ printf "  %-54s %s\n" "$1" "$2"; }
q(){ psql "$D user=$1" -At -c "$2" 2>&1 | tr '\n' ' ' | sed 's/  */ /g'; }
see(){ out=$(q "$1" "$2"); case "$out" in *ERROR*) echo "DENIED ${out#*ERROR: }";; *) echo "${out:-nothing}";; esac; }

echo "== CUSTOMER (proto_app) =="
r "reads own with its session credential"        "$(see proto_app "SET app.session_key='$PK'; SELECT string_agg(secret_text,',') FROM pc_data;")"
r "supplies Q's UUID in every app.* GUC"         "$(see proto_app "SET app.customer_id='$Q'; SET app.admin_cap='$Q'; SET app.intent_lease='$Q'; SELECT COALESCE(string_agg(secret_text,','),'nothing') FROM pc_data;")"
r "supplies Q's SESSION-ROW id (not the secret)" "$(see proto_app "SET app.session_key='$Q'; SELECT COALESCE(string_agg(secret_text,','),'nothing') FROM pc_data;")"
r "tries to mint an admin capability"            "$(see proto_app "SELECT pc_admin_open('$AL','$Q','support.read');")"
r "tries to claim an intent (worker authority)"  "$(see proto_app "SELECT * FROM pc_intent_claim('thief');")"
r "tries RADIUS ingestion"                       "$(see proto_app "SELECT pc_radius_ingest('u-Q',999);")"
r "tries to read the session table for Q's key"  "$(see proto_app "SET app.session_key='$PK'; SELECT COALESCE(string_agg(token_hash,','),'nothing') FROM pc_sessions WHERE customer_id='$Q';")"
r "tries to INSERT its own capability row"       "$(see proto_app "INSERT INTO pc_admin_caps (cap_hash,admin_id,customer_id,operation,expires_at) VALUES ('x','$Q','$Q','support.read', now()+interval '1 hour');")"
r "writes a row labelled Q"                      "$(see proto_app "SET app.session_key='$PK'; INSERT INTO pc_data (customer_id,secret_text) VALUES ('$Q','PLANTED');")"

echo; echo "== ADMIN (proto_admin) =="
CAP_P=$(psql "$D user=proto_admin" -At -c "SELECT pc_admin_open('$AL','$P','support.read');" 2>&1|tail -1)
CAP_Q=$(psql "$D user=proto_admin" -At -c "SELECT pc_admin_open('$AL','$Q','support.read');" 2>&1|tail -1)
r "alice opens a capability for P, then reads P"  "$(see proto_admin "SET app.admin_cap='$CAP_P'; SELECT string_agg(secret_text,',') FROM pc_data;")"
r "alice opens one for Q, then reads Q"           "$(see proto_admin "SET app.admin_cap='$CAP_Q'; SELECT string_agg(secret_text,',') FROM pc_data;")"
r "  P's capability does NOT also show Q"         "$(see proto_admin "SET app.admin_cap='$CAP_P'; SELECT COALESCE(string_agg(secret_text,','),'nothing') FROM pc_data WHERE customer_id='$Q';")"
r "bob (authorised for P only) asks for Q"        "$(see proto_admin "SELECT pc_admin_open('$BO','$Q','support.read');")"
r "bob asks for P"                                "$(see proto_admin "SELECT CASE WHEN pc_admin_open('$BO','$P','support.read') IS NOT NULL THEN 'capability issued' END;")"
r "admin sets a raw customer UUID instead"        "$(see proto_admin "SET app.customer_id='$Q'; SET app.admin_cap='$Q'; SELECT COALESCE(string_agg(secret_text,','),'nothing') FROM pc_data;")"
r "admin presents a CUSTOMER session key"         "$(see proto_admin "SET app.session_key='$QK'; SELECT COALESCE(string_agg(secret_text,','),'nothing') FROM pc_data;")"
r "admin tries to claim an intent"                "$(see proto_admin "SELECT * FROM pc_intent_claim('admin');")"
r "admin with NO capability"                      "$(see proto_admin "SELECT COALESCE(string_agg(secret_text,','),'nothing') FROM pc_data;")"

echo; echo "== WORKER (proto_worker) =="
L1=$(psql "$D user=proto_worker" -At -F'|' -c "SELECT lease FROM pc_intent_claim('w1');" 2>&1|tail -1)
L2=$(psql "$D user=proto_worker" -At -F'|' -c "SELECT lease FROM pc_intent_claim('w1');" 2>&1|tail -1)
r "claims intent 1, sees that customer only"      "$(see proto_worker "SET app.intent_lease='$L1'; SELECT string_agg(secret_text,',') FROM pc_data;")"
r "claims intent 2, sees the OTHER customer"      "$(see proto_worker "SET app.intent_lease='$L2'; SELECT string_agg(secret_text,',') FROM pc_data;")"
r "supplies a raw customer UUID as authority"     "$(see proto_worker "SET app.customer_id='$Q'; SET app.intent_lease='$Q'; SELECT COALESCE(string_agg(secret_text,','),'nothing') FROM pc_data;")"
r "presents a CUSTOMER session key"               "$(see proto_worker "SET app.session_key='$QK'; SELECT COALESCE(string_agg(secret_text,','),'nothing') FROM pc_data;")"
r "presents an ADMIN capability"                  "$(see proto_worker "SET app.admin_cap='$CAP_Q'; SELECT COALESCE(string_agg(secret_text,','),'nothing') FROM pc_data;")"
r "tries to mint an admin capability"             "$(see proto_worker "SELECT pc_admin_open('$AL','$Q','support.read');")"
r "with NO lease"                                 "$(see proto_worker "SELECT COALESCE(string_agg(secret_text,','),'nothing') FROM pc_data;")"

echo; echo "== RADIUS (proto_radius) =="
r "ingests for P"                                 "$(see proto_radius "SELECT CASE WHEN pc_radius_ingest('u-P',100)='$P' THEN 'attributed to P' END;")"
r "ingests for Q"                                 "$(see proto_radius "SELECT CASE WHEN pc_radius_ingest('u-Q',200)='$Q' THEN 'attributed to Q' END;")"
r "unknown username"                              "$(see proto_radius "SELECT COALESCE(pc_radius_ingest('nobody',1)::text,'no session created');")"
r "tries to read tenant data"                     "$(see proto_radius "SELECT count(*) FROM pc_data;")"
r "tries to read usage it just wrote"             "$(see proto_radius "SELECT count(*) FROM pc_usage;")"
r "tries to mint an admin capability"             "$(see proto_radius "SELECT pc_admin_open('$AL','$Q','support.read');")"
r "tries to claim an intent"                      "$(see proto_radius "SELECT * FROM pc_intent_claim('radius');")"
