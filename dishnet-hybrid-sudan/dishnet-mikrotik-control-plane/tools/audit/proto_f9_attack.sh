P=11111111-1111-4111-8111-111111111111
Q=22222222-2222-4222-8222-222222222222
PH=$(psql "host=/var/tmp port=55432 dbname=dnb_proto user=postgres" -At -c "SELECT encode(digest('TOKEN-P','sha256'),'hex')")
QH=$(psql "host=/var/tmp port=55432 dbname=dnb_proto user=postgres" -At -c "SELECT encode(digest('TOKEN-Q','sha256'),'hex')")
PMAC=$(psql "host=/var/tmp port=55432 dbname=dnb_proto user=postgres" -At -c "SELECT '$P.'||encode(hmac(convert_to('$P','utf8'),(SELECT v FROM pc_keys WHERE k='ctx'),'sha256'),'hex')")

policy() { # $1 = function name
  psql "host=/var/tmp port=55432 dbname=dnb_proto user=postgres" -q \
    -c "DROP POLICY IF EXISTS iso ON pc_data;" \
    -c "CREATE POLICY iso ON pc_data FOR ALL USING (customer_id = $1()) WITH CHECK (customer_id = $1());" 2>&1|grep -v '^$'
}
run() { # label, user, sql  -> prints what came back
  out=$(psql "host=/var/tmp port=55432 dbname=dnb_proto user=$2" -At -c "$3" 2>&1 | tr '\n' ' ')
  printf "    %-46s %s\n" "$1" "${out:0:58}"
}
probe() { # candidate label, setter-for-P, attacker-attempts...
  echo "  -- $1 --"
}

for CAND in a b f; do
  case $CAND in
    a) NAME="A: GUC holds the IDENTIFIER (today)"; SETP="SET app.customer_id='$P'"; SETQ="SET app.customer_id='$Q'";;
    b) NAME="B: GUC holds a SESSION SECRET";        SETP="SET app.session_key='$PH'"; SETQ="SET app.session_key='$QH'";;
    f) NAME="F: GUC holds identifier + HMAC";       SETP="SET app.customer_mac='$PMAC'"; SETQ="SET app.customer_mac='$Q.deadbeef'";;
  esac
  policy pc_current_$CAND >/dev/null
  echo "  == $NAME =="
  run "P establishes its own context, reads own"  proto_app "$SETP; SELECT secret_text FROM pc_data;"
  run "attacker supplies only Q's UUID"           proto_app "SET app.customer_id='$Q'; SELECT COALESCE(string_agg(secret_text,','),'nothing') FROM pc_data;"
  run "attacker sets EVERY app.* GUC to Q"        proto_app "SET app.customer_id='$Q'; SET app.session_key='$Q'; SET app.customer_mac='$Q'; SELECT COALESCE(string_agg(secret_text,','),'nothing') FROM pc_data;"
  run "attacker forges a MAC"                     proto_app "SET app.customer_mac='$Q.0000000000000000000000000000000000000000000000000000000000000000'; SELECT COALESCE(string_agg(secret_text,','),'nothing') FROM pc_data;"
  run "attacker calls the resolver directly"      proto_app "SET app.customer_id='$Q'; SELECT COALESCE(pc_current_$CAND()::text,'NULL');"
  run "attacker WRITES a row labelled Q"          proto_app "$SETP; INSERT INTO pc_data (customer_id,secret_text) VALUES ('$Q','PLANTED') RETURNING 'wrote';"
  run "fresh connection, no context at all"       proto_app "SELECT COALESCE(string_agg(secret_text,','),'nothing') FROM pc_data;"
  run "P WITH Q's credential (if it had it)"      proto_app "$SETQ; SELECT COALESCE(string_agg(secret_text,','),'nothing') FROM pc_data;"
  run "worker across customers"                   proto_worker "SET app.customer_id='$Q'; SELECT COALESCE(string_agg(secret_text,','),'none') FROM pc_data;"
  run "admin across customers"                    proto_admin  "SET app.customer_id='$P'; SELECT COALESCE(string_agg(secret_text,','),'none') FROM pc_data;"
  echo
done
