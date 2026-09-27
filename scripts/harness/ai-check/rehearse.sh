#!/usr/bin/env bash
# Rehearse scripts/dnb-ai-check.sh before anyone runs it on the server (docs/40).
#
# It runs twice: against the plugin the server runs today (5.18.44, commit a4abe5e, the commit deploy-5.18.44.sh
# pinned, deployed 27 Sep 2026) and against this checkout (5.18.45). 5.18.43 was rehearsed beside 5.18.44 (docs/40 §11.6). The operator runs the check before the deploy and after it, and the two
# logs are compared — so the check must read each version correctly, and say which it is reading.
#
# Each run: a sandbox plugin directory holds a COPY of that version's lib/ and workers/ — the brain's OpenAI address
# pointed at a fake provider, which is the only change — with the rest linked. A fake uCRM serves plans and products
# (an outdoor access point and a MikroTik among them); a fake `docker exec` runs the check in the sandbox. The plugin
# database is built by that version's migrations and knowledge seed, with conversations that carry canaries: a phone,
# an e-mail, a kit number, a staff name, an event's phone, the API key and the uCRM token. None may appear in the
# output, the database must be byte-identical afterwards, and every claim the report makes is checked against what
# was seeded and against what that version does. Then weakened copies of the check must each fail.
set -u
R="$(cd "$(dirname "$0")/../../.." && pwd)"; H="$R/scripts/harness/ai-check"
LIVE_COMMIT="a4abe5e"   # 5.18.44, what the server runs (scripts/deploy-5.18.44.sh, 27 Sep 2026)

# ── The parent: one run per plugin version ──────────────────────────────────
if [ -z "${EXPECT:-}" ]; then
  OLD="$(mktemp -d)"; trap 'rm -rf "$OLD"' EXIT
  git -C "$R" archive "$LIVE_COMMIT" dishnet-hybrid-sudan | tar -x -C "$OLD" || { echo "could not extract $LIVE_COMMIT"; exit 2; }
  ALL_OK=0; ALL_FAIL=0
  for v in 44 45; do
    if [ "$v" = 44 ]; then src="$OLD/dishnet-hybrid-sudan"; what="commit $LIVE_COMMIT, what the server runs"
    else src="$R/dishnet-hybrid-sudan"; what="this checkout"; fi
    echo; echo "######## plugin 5.18.$v — $what ########"
    EXPECT="$v" PLUGIN_SRC="$src" bash "$0" | tee "$OLD/out-$v"
    line="$(grep '^REHEARSAL: ' "$OLD/out-$v" | tail -1)"
    if [ -z "$line" ]; then echo "  FAIL the 5.18.$v run printed no result line"; ALL_FAIL=$((ALL_FAIL+1)); continue; fi
    ALL_OK=$((ALL_OK + $(printf '%s' "$line" | sed -E 's/^REHEARSAL: ([0-9]+) ok, ([0-9]+) failed.*/\1/')))
    ALL_FAIL=$((ALL_FAIL + $(printf '%s' "$line" | sed -E 's/^REHEARSAL: ([0-9]+) ok, ([0-9]+) failed.*/\2/')))
  done
  echo; echo "REHEARSAL, BOTH VERSIONS: $ALL_OK ok, $ALL_FAIL failed"
  [ "$ALL_FAIL" = "0" ]; exit
fi

# ── A child: one version ────────────────────────────────────────────────────
case "$EXPECT" in 44|45) ;; *) echo "EXPECT must be 44 or 45"; exit 2 ;; esac
P="${PLUGIN_SRC:?PLUGIN_SRC names the plugin directory under test}"
v45() { [ "$EXPECT" = 45 ]; }
SB="$(mktemp -d)"; PIDS=()
cleanup() { for p in "${PIDS[@]}"; do kill "$p" 2>/dev/null; done; rm -rf "$SB"; }
trap cleanup EXIT
export NO_PROXY=127.0.0.1,localhost no_proxy=127.0.0.1,localhost
PASS=0; FAILN=0
check() { if [ "$1" = "$2" ]; then PASS=$((PASS+1)); echo "  ok   $3"; else FAILN=$((FAILN+1)); echo "  FAIL $3 (got '$1', want '$2')"; fi; }
has()   { printf '%s' "$1" | grep -qF -- "$2" && echo yes || echo no; }
count() { printf '%s' "$1" | grep -cF -- "$2"; }
prompts_with()    { grep -lE -- "$1" "$SB"/ai/prompt-*.txt 2>/dev/null | wc -l | tr -d ' '; }   # how many prompts match
prompt_for()      { grep -lF -- "LAST: $1" "$SB"/ai/prompt-*.txt 2>/dev/null | head -1; }           # the prompt a question got
section()         { printf '%s' "$1" | awk -v q="$2" 'index($0, q) {on=1} on && /^  ── / && !index($0, q) && seen {exit} on {print; seen=1}'; }

check "$(python3 -c 'import json,sys; print(json.load(open(sys.argv[1]))["information"]["version"])' "$P/manifest.json")" "5.18.$EXPECT" \
  "the plugin under test is 5.18.$EXPECT"

free_port() { python3 -c 'import socket;s=socket.socket();s.bind(("127.0.0.1",0));print(s.getsockname()[1]);s.close()'; }
UPORT="$(free_port)"; APORT="$(free_port)"
CATALOGUE_DIR="$H" php -S "127.0.0.1:$UPORT" "$H/fake_ucrm.php" >/dev/null 2>&1 & PIDS+=($!)
FAKE_AI_LOG="$SB/ai" php -S "127.0.0.1:$APORT" "$H/fake_ai.php" >/dev/null 2>&1 & PIDS+=($!)
for i in $(seq 1 50); do curl -s --noproxy '*' "http://127.0.0.1:$UPORT/__ping" | grep -q aicheck && break; sleep 0.1; done

# ── The sandbox "installed plugin" ─────────────────────────────────────────
PL="$SB/plugin"; DATA="$SB/data"; mkdir -p "$PL" "$DATA" "$SB/bin"
cp -r "$P/lib" "$P/workers" "$PL/"; cp "$P/manifest.json" "$PL/"
for d in migrations tools assets profiles; do ln -s "$P/$d" "$PL/$d"; done
N="$(grep -c 'https://api.openai.com/v1/chat/completions' "$PL/lib/DishNetAiBrain.php")"
check "$N" "1" "the brain has exactly one OpenAI address to point at the fake provider"
sed -i "s#https://api.openai.com/v1/chat/completions#http://127.0.0.1:$APORT/v1/chat/completions#" "$PL/lib/DishNetAiBrain.php"
printf '{"pluginDataDir":"%s"}' "$DATA" > "$PL/ucrm.json"
KEY="sk-test-canary-0123456789abcdef"
write_config() {   # $1 the API key ('' for none) · $2 ai_hardware_expert (default 1) · $3 more members, each after a comma
  cat > "$DATA/config.json" <<JSON
{"crm_base_url":"http://127.0.0.1:$UPORT","crm_auth_token":"CANARY-CRM-TOKEN","ai_enabled":true,"ai_provider":"openai",
 "openai_api_key":"$1","ai_qualification":"1","ai_hardware_expert":"${2:-1}","ai_sales_on_all_numbers":"1","ai_lead_capture":"1",
 "ai_currency":"UGX","bot_instructions_mode":"append",
 "bot_custom_instructions":"Always be polite.\nFor business customers who need unlimited data recommend Residential. Call +256 700 111 222 for help."${3:-}}
JSON
}
write_config "$KEY"
php "$H/seed.php" "$PL" "$DATA" >/dev/null || { echo "could not seed the sandbox database"; exit 2; }

# A fake `docker`: `exec [-i] [-u USER] [-w DIR] [-e K=V]… CONTAINER cmd…` runs cmd in DIR with the variables set;
# every call is logged, and nothing but exec is allowed.
cat > "$SB/bin/docker" <<SH
#!/usr/bin/env bash
printf '%s\n' "\$*" >> "$SB/docker.log"
[ "\$1" = exec ] || { echo "fake docker: only exec" >&2; exit 1; }; shift
W=""; while [ \$# -gt 0 ]; do case "\$1" in -i) shift ;; -u) shift 2 ;; -w) W="\$2"; shift 2 ;; -e) export "\$2"; shift 2 ;; *) break ;; esac; done
shift; [ -n "\$W" ] && cd "\$W"; exec "\$@"
SH
chmod +x "$SB/bin/docker"
run_check() { PATH="$SB/bin:$PATH" IN_CONTAINER="$PL" bash "${SCRIPT:-$R/scripts/dnb-ai-check.sh}" "$@" 2>&1; }
dbsum() { sha256sum "$DATA/plugin.sqlite3" | cut -c1-64; }
keep()  { [ -n "${KEEP_OUTPUT:-}" ] && printf '%s\n' "$2" > "$KEEP_OUTPUT/5.18.$EXPECT-$1.txt"; true; }   # KEEP_OUTPUT=<dir>: save what the check printed
CANARIES='772 123 456|772123456|someone@example.com|KIT3040X1234|Richard|256700999888|sk-test-canary|CANARY-CRM-TOKEN|700 111 222|OLDCANARY'

echo "== 1. report: spends nothing, prints what the assistant is given and what customers got =="
BEFORE="$(dbsum)"; FILES_BEFORE="$(ls "$DATA" | sort | tr '\n' ' ')"
OUT="$(run_check)"; rc=$?; keep report "$OUT"
check "$rc" "0" "the report exits 0"
check "$(dbsum)" "$BEFORE" "the plugin database is byte-identical afterwards"
check "$(ls "$DATA" | sort | tr '\n' ' ')" "$FILES_BEFORE" "no file appears in or leaves the data directory"
check "$(ls "$SB/ai" 2>/dev/null | wc -l | tr -d ' ')" "0" "the report makes no model call"
check "$(printf '%s' "$OUT" | grep -cE "$CANARIES")" "0" "no phone, e-mail, kit number, staff name, key or token is printed"
check "$(count "$OUT" '@@')" "0" "the script's own @@ lines are never printed"
check "$(has "$OUT" "plugin 5.18.$EXPECT")" "yes" "the report says which plugin version it read"
check "$(has "$OUT" 'key set (not shown)')" "yes" "the API key is reported as set, not shown"
check "$(has "$OUT" '“For business customers who need unlimited data recommend Residential. Call {phone} for help.”')" "yes" \
  "the extra instruction on these topics is shown, its phone masked"
check "$(has "$OUT" 'Always be polite')" "no" "an extra instruction on other topics is not"
check "$(printf '%s' "$OUT" | grep -cE '(sales|support) +1  \(last')" "2" "the AI's own replies in the last 7 days: one on each number"
check "$(printf '%s' "$OUT" | grep -cE '^    ★ ')" "2" "a customer saying \"business, unlimited\" is shown exactly two plans (★)"
check "$(printf '%s' "$OUT" | grep -E '^    ★ ' | grep -c 'Residential')" "2" "…and they are the two Residential plans"
check "$(has "$OUT" 'Old retired plan')" "no" "a plan uCRM marks inactive is not in the list"
check "$(printf '%s' "$OUT" | grep -cE 'plan copies dropped +2 product')" "1" "the two products named like a plan are reported as dropped"
  check "$(printf '%s' "$OUT" | grep -cE 'HARDWARE \(one-time\) +3 — the kit, the installation and other one-time items')" "1" \
    "HARDWARE is reported as the two kits and the installation"
  check "$(printf '%s' "$OUT" | grep -cE 'NETWORK EQUIPMENT \(one-time\) +2 — what the assistant designs a bigger-area setup from')" "1" \
    "NETWORK EQUIPMENT is reported with its two items"
  check "$(printf '%s' "$OUT" | grep -A1 'NETWORK EQUIPMENT (one-time)' | tail -1 | grep -cE '^ +1\. MikroTik Router .*380,000  ← router$')" "1" \
    "…the MikroTik first, as the router"
  check "$(printf '%s' "$OUT" | grep -A2 'NETWORK EQUIPMENT (one-time)' | tail -1 | grep -cE '^ +2\. Outdoor Access Point .*450,000  ← access point$')" "1" \
    "…then the outdoor access point"
  check "$(has "$OUT" 'any combination of the first 10 one-time items, and 2 to 5 of one access point with any of the others')" "yes" \
    "the totals the price check allows are stated"
  check "$(count "$OUT" '← network equipment')" "0" "nothing is guessed to be network equipment by its name any more"
  check "$(printf '%s' "$OUT" | grep -cE 'ACCESSORIES \(optional extras\) +3 — every number sees these')" "1" "the accessories are counted, seen on every number"
  check "$(count "$OUT" 'CUT at')" "0" "no knowledge row is reported cut"
  check "$(printf '%s' "$OUT" | grep -cE 'each answer reaches it up to +1000 characters$')" "1" "the knowledge limit in use is stated: 1,000"
  check "$(printf '%s' "$OUT" | grep -A3 '^  MANY_USERS_HOTSPOT ' | grep -c '"unlimited" reaches the assistant here: yes')" "1" \
    "MANY_USERS_HOTSPOT's \"unlimited\" is reported as reaching the assistant"
  check "$(printf '%s' "$OUT" | grep -cE '"unlimited" fact +the default wording: Both Residential plans \(Residential Lite and Residential\) are unlimited')" "1" \
    "the \"unlimited\" fact is shown in its default wording"
if v45; then
  check "$(printf '%s' "$OUT" | grep -cE 'STARLINK ROUTERS \(indoor\) +2 — among the accessories')" "1" "the Starlink routers are counted: two"
  check "$(printf '%s' "$OUT" | grep -cE '^ +Router Mini +435,000  ← Starlink router · fits Standard 4, Standard 4 X, Mini, Gen 2 kits \(not Gen 1\)$')" "1" \
    "…Router Mini, with what it fits"
  check "$(printf '%s' "$OUT" | grep -cE '^ +Router 3 \| Starlink V4 or V5, Mini +827,000  ← Starlink router · fits Standard 4, Standard 4 X, Mini, Gen 2 and Gen 3 kits$')" "1" \
    "…and Router 3, with what it fits"
  check "$(printf '%s' "$OUT" | grep 'Wall Mount' | grep -c 'Starlink router')" "0" "a mount is not a Starlink router"
  check "$(has "$OUT" '; 1 to 5 of one Starlink router with any of the kit and the installation')" "yes" "the totals a router design produces are stated"
  check "$(printf '%s' "$OUT" | grep -A4 '^  BUSINESS_PLANS ' | grep -c 'after the priority block it says: about 1 Mbps until more is bought — the approved fact')" "1" \
    "BUSINESS_PLANS is reported saying the approved fact"
else
  check "$(count "$OUT" 'STARLINK ROUTERS')" "0" "no Starlink-router list: this version has none"
  check "$(printf '%s' "$OUT" | grep -A4 '^  BUSINESS_PLANS ' | grep -c 'after the priority block it says: unlimited standard data continues — NOT the approved fact')" "1" \
    "BUSINESS_PLANS is reported contradicting the approved fact"
fi
check "$(has "$OUT" 'customer     Hi, I want to start a wifi business, do you have unlimited internet? my number is {phone}')" "yes" \
  "c1's question is shown, the phone masked"
check "$(printf '%s' "$OUT" | grep -A2 'my number is {phone}' | grep -c 'names a Business plan')" "1" "…and its AI reply is read as naming a Business plan"
check "$(printf '%s' "$OUT" | grep -cE 'reply \(staff\) +We have an Outdoor Access Point at UGX 450,000 — \{staff\}$')" "1" \
  "a colleague's reply is labelled staff, and the name they signed it with is masked"
check "$(printf '%s' "$OUT" | grep -c 'email me at {e-mail}')" "1" "the e-mail in c1's second message is masked"
check "$(printf '%s' "$OUT" | grep -A3 'kit {kit}' | grep -c 'THE FALLBACK')" "1" "c2's reply is recognised as the fallback"
check "$(printf '%s' "$OUT" | grep -c 'handed over  yes — reply blocked by guard: foreign:amount')" "1" "…and the handover is found in the events, reason only"
check "$(count "$OUT" 'REPEATCANARY')" "2" "one conversation never shows more than two of its messages per topic"
check "$(has "$OUT" 'business/unlimited 3 shown (1 answered, 1 replies by the AI) · covering another area 2 shown (2 answered, 1 by the AI)')" "yes" \
  "the closing tally matches what was seeded"
check "$(has "$OUT" 'Nothing was changed and nothing was sent')" "yes" "it ends by saying so"
check "$(grep -c -- '-u .* sh -c mkdir -p .*/tmp/dnb-ai-ro-.* cp ' "$SB/docker.log")" "1" "the database is copied once, as its owner"
check "$(grep -c -- "exec -i -u $(stat -c '%u:%g' "$DATA/plugin.sqlite3") -w .* -e AI_CHECK_DB=/tmp/dnb-ai-ro-" "$SB/docker.log")" "1" "the report runs as the database's owner, on the copy"
check "$(ls -d /tmp/dnb-ai-ro-* 2>/dev/null | wc -l | tr -d ' ')" "0" "the temporary copy is removed afterwards"

  echo "== 1c. the \"unlimited\" fact as the operator sets it =="
  write_config "$KEY" 1 ',"ai_fact_unlimited":"Unlimited on both Residential plans. Questions: +256 700 111 222."'
  OUT="$(run_check)"
  check "$(printf '%s' "$OUT" | grep -cE '"unlimited" fact +your own wording: Unlimited on both Residential plans\. Questions: \{phone\}\.')" "1" \
    "their own wording is shown, its phone masked"
  write_config "$KEY" 1 ',"ai_fact_unlimited":"omit"'
  OUT="$(run_check)"
  check "$(printf '%s' "$OUT" | grep -cE '"unlimited" fact +OFF \(omit\)')" "1" "\"omit\" is shown as off"
  write_config "$KEY"

echo "== 1b. it reads the copy it is given, and never the live file =="
cp "$DATA/plugin.sqlite3" "$SB/copy.sqlite3"
php -r '$p=new PDO("sqlite:".$argv[1]); $p->exec("INSERT INTO wa_conversations (phone, channel) VALUES (\x27x\x27, \x27sales\x27)"); $c=$p->lastInsertId(); $p->exec("INSERT INTO wa_messages (conversation_id, direction, role, body, sent_at) VALUES ($c, \x27in\x27, \x27customer\x27, \x27COPYMARKER do you have unlimited business internet?\x27, datetime(\x27now\x27, \x27-1 days\x27))");' "$SB/copy.sqlite3"
direct() { (cd "$PL" && env "$@" php -d display_errors=0 -- report 60 < "${PHPFILE:-$R/scripts/lib/ai_check.php}" 2>&1); }
O="$(direct AI_CHECK_DB="$SB/copy.sqlite3")"
check "$(count "$O" 'COPYMARKER')" "1" "a conversation that exists only in the copy is shown"
O="$(direct AI_CHECK_DB=)"
check "$(has "$O" 'STOP: no copy of the database was given (AI_CHECK_DB)')" "yes" "without a copy it stops"
O="$(direct AI_CHECK_DB="$DATA/plugin.sqlite3")"
check "$(has "$O" 'STOP: AI_CHECK_DB names the live database; this check reads a copy only')" "yes" "handed the live file, it refuses"

echo "== 2. --ask: eleven questions through the live path, against a fake provider =="
BEFORE="$(dbsum)"; rm -rf "$SB/ai"
OUT="$(run_check --ask)"; rc=$?; keep ask "$OUT"
check "$rc" "0" "--ask exits 0"
check "$(dbsum)" "$BEFORE" "the plugin database is byte-identical afterwards"
check "$(ls "$SB/ai" | wc -l | tr -d ' ')" "11" "exactly eleven model calls"
check "$(has "$OUT" '11 model call(s)')" "yes" "…and the report says eleven"
check "$(grep -L 'APPROVED KNOWLEDGE' "$SB"/ai/prompt-*.txt | wc -l | tr -d ' ')" "0" "every prompt carries the knowledge base, as the worker's does"
Q="$(prompt_for 'Do you have unlimited business plans?')"
check "$(grep -cE '^- Business .* — price ' "$Q")" "0" "\"unlimited business plans?\" is answered with no Business plan in PLANS"
Q="$(prompt_for 'How much is Business 500?')"
check "$(grep -cE '^- Business .* — price ' "$Q")" "3" "a customer who names Business 500 is given the Business plans"
S="$(section "$OUT" 'Do you have unlimited business plans?')"
check "$(count "$S" 'the Business-plan note was added')" "1" "a reply naming only a Business plan gets the note, as on the live path"
check "$(count "$S" 'amounts of priority data')" "1" "…and the note is in what the customer receives"
check "$(count "$S" 'it was shown 2 plan(s), no Business plan')" "1" \
  "the report says what the model saw: two plans, no Business, for \"unlimited business plans?\""
check "$(count "$(section "$OUT" 'How much is Business 500?')" 'it was shown 5 plan(s), including 3 Business')" "1" \
  "…and all five, three Business, once the customer names one"
S="$(section "$OUT" 'How much is an outdoor access point and a MikroTik router?')"
check "$(count "$S" 'the price check REFUSED the reply (foreign:amount)')" "1" "an invented price is refused by the price check"
check "$(count "$S" "AI replies   I'm not able to complete that one automatically")" "1" "…and the customer would get the fallback"
S="$(section "$OUT" 'How much will I pay to get Starlink installed at my home?')"
check "$(count "$S" 'REFUSED')" "0" "a home total that adds up is sent"
check "$(count "$S" 'TOTAL TO GET CONNECTED: UGX 2,799,000')" "1" "…as the customer would receive it"
S="$(section "$OUT" 'What would two outdoor access points and the MikroTik cost together?')"
  check "$(prompts_with 'ACCESSORIES \(optional extras')" "11" "every sales prompt carries ACCESSORIES, as the sales number now does"
  check "$(prompts_with '^NETWORK EQUIPMENT \(one-time, live from our system')" "11" "every prompt has a NETWORK EQUIPMENT list"
  check "$(prompts_with '^- Outdoor Access Point — price 450000 one-time — outdoor Wi-Fi access point, mounted on a pole or a wall')" "11" \
    "…with the outdoor access point in it, saying what it is for"
  check "$(prompts_with '^- MikroTik Router — price 380000 one-time — MikroTik router: runs the local network')" "11" "…and the MikroTik"
  check "$(prompts_with '^- Outdoor Access Point — price 450000 one-time$')" "0" "the outdoor access point is no longer a bare HARDWARE line"
  check "$(for f in "$SB"/ai/prompt-*.txt; do awk '/^NETWORK EQUIPMENT \(one-time/{h=NR} /^HARDWARE \(one-time/{w=NR} /^- Outdoor Access Point — price/{a=NR} END{print (w && h>w && a>h) ? "ok" : "bad"}' "$f"; done | grep -c ok)" "11" \
    "…in every prompt it sits after HARDWARE, under NETWORK EQUIPMENT"
  Q="$(prompt_for 'Do you have unlimited business plans?')"
  check "$(grep -c '^DATA ALLOWANCE (a stated fact' "$Q")" "1" "\"unlimited business plans?\" is given the data-allowance fact"
  check "$(grep -c 'A CUSTOMER WHO IS A BUSINESS' "$Q")" "1" "…and told a business is answered with the Residential plans"
  check "$(grep -c 'A CUSTOMER WHO IS A BUSINESS' "$(prompt_for 'How much is Business 500?')")" "0" "…a rule that goes when the Business plans are shown"
  check "$(grep -c 'SOMEONE WHO WANTS TO SELL INTERNET' "$(prompt_for 'I want to sell internet to the people around my shop.')")" "1" \
    "someone who wants to sell internet is given the rule for it"
  check "$(count "$S" 'REFUSED')" "0" "\"two access points and the MikroTik\" is a permitted total now"
  check "$(count "$S" 'UGX 1,280,000')" "1" "…and the customer receives it"

check "$(count "$(section "$OUT" 'How much is an outdoor access point and a MikroTik router?')" 'refused      amounts it could not match to the price list: 1,234,000')" "1" \
  "a refused reply names the amount it could not match: the invented price"
check "$(count "$(section "$OUT" 'How much is an outdoor access point and a MikroTik router?')" 'the draft    The outdoor access point is UGX 1,234,000')" "1" \
  "…and shows the draft that was refused"
S="$(section "$OUT" 'The WiFi does not reach the upper floors of my house.')"
if v45; then
  check "$(prompts_with '^MORE FLOORS OR ROOMS INSIDE ONE BUILDING — STARLINK ROUTERS\.$')" "11" "every sales prompt carries the rule for more floors"
  check "$(prompts_with '^- This is for OUTDOORS and other buildings\.')" "11" "…and says the network equipment is for outdoors"
  check "$(grep -c -- '— Starlink router: Wi-Fi inside the building' "$(prompt_for 'The WiFi does not reach the upper floors of my house.')")" "2" \
    "the floors question is given both Starlink routers, marked"
  check "$(count "$S" 'REFUSED')" "0" "two of one Starlink router is a permitted total now"
  check "$(count "$S" 'TOTAL: UGX 870,000')" "1" "…and the customer receives it"
  check "$(count "$S" 'Starlink router')" "1" "…read as naming a Starlink router"
  check "$(has "$OUT" '1 refused by the price check; 1 with the Business-plan note added')" "yes" "the closing tally matches"
else
  check "$(prompts_with 'MORE FLOORS OR ROOMS|This is for OUTDOORS|— Starlink router:')" "0" "no prompt has anything 5.18.45 adds"
  check "$(count "$S" 'the price check REFUSED the reply')" "1" "two of one Starlink router is refused, as on the live path today"
  check "$(count "$S" 'refused      amounts it could not match to the price list: 870,000')" "1" "…and the check names the amount"
  check "$(has "$OUT" '2 refused by the price check; 1 with the Business-plan note added')" "yes" "the closing tally matches"
fi
check "$(printf '%s' "$OUT" | grep -cE "$CANARIES")" "0" "nothing secret or personal is printed in --ask either"

echo "== 2b. --ask with the hardware advice module off: the price list and the price check of 5.18.43 =="
write_config "$KEY" 0; rm -rf "$SB/ai"
OUT="$(run_check --ask)"; rc=$?; keep ask-module-off "$OUT"
check "$rc" "0" "--ask exits 0"
check "$(ls "$SB/ai" | wc -l | tr -d ' ')" "11" "eleven model calls"
check "$(prompts_with 'ACCESSORIES \(optional extras|^NETWORK EQUIPMENT')" "0" "no prompt carries ACCESSORIES or NETWORK EQUIPMENT"
check "$(prompts_with '^- Outdoor Access Point — price 450000 one-time$')" "11" "the outdoor access point is a HARDWARE line again"
check "$(printf '%s' "$OUT" | grep -E 'Outdoor Access Point|MikroTik Router' | grep -c '← network equipment')" "2" "the report lists them in HARDWARE, marked"
check "$(printf '%s' "$OUT" | grep -c 'the sales number never sees them')" "1" "…and says the sales number does not see the accessories"
check "$(count "$(section "$OUT" 'What would two outdoor access points and the MikroTik cost together?')" 'the price check REFUSED the reply')" "1" \
  "a multiplied total is refused again"
check "$(count "$(section "$OUT" 'How much will I pay to get Starlink installed at my home?')" 'TOTAL TO GET CONNECTED: UGX 2,799,000')" "1" \
  "a home total that adds up is still sent"
check "$(count "$(section "$OUT" 'The WiFi does not reach the upper floors of my house.')" 'the price check REFUSED the reply')" "1" \
  "two of one Starlink router is refused with the module off"
check "$(has "$OUT" '3 refused by the price check; 1 with the Business-plan note added')" "yes" "the closing tally matches"
check "$(prompts_with 'MORE FLOORS OR ROOMS|— Starlink router:')" "0" "no prompt carries the rule for more floors"
check "$(grep -c '^DATA ALLOWANCE (a stated fact' "$(prompt_for 'Do you have unlimited business plans?')")" "1" \
  "the data-allowance fact follows the qualification switch, not this one"
write_config "$KEY"

echo "== 3. --ask without a key: stops, asks nothing, says so =="
write_config ""; rm -rf "$SB/ai"
OUT="$(run_check --ask)"; rc=$?
check "$rc" "1" "exits 1"
check "$(has "$OUT" 'STOP: no AI provider key is configured — nothing was asked')" "yes" "says why"
check "$(ls "$SB/ai" 2>/dev/null | wc -l | tr -d ' ')" "0" "no model call was made"
check "$(has "$OUT" 'key NOT SET')" "yes" "the report part still ran and shows the key as not set"
write_config "$KEY"

echo "== 4. weakened copies must each fail =="
cp -a "$DATA" "$SB/data.pristine"
mutant() {   # $1 label · $2 an edit to ai_check.php (old=>new) · $3 args · $4 what must now be seen broken · $5 "hwoff" to run with the module off
  mkdir -p "$SB/m"; cp -r "$R/scripts" "$SB/m/scripts"
  python3 - "$SB/m/scripts/lib/ai_check.php" "$2" <<'PY' || { echo "  FAIL mutant edit did not apply: $1"; FAILN=$((FAILN+1)); rm -rf "$SB/m"; return; }
import sys; p, spec = sys.argv[1], sys.argv[2]; old, new = spec.split('=>', 1); s = open(p).read()
assert s.count(old) == 1, 'anchor not unique: ' + old
open(p, 'w').write(s.replace(old, new))
PY
  [ "${5:-}" = hwoff ] && write_config "$KEY" 0
  rm -rf "$SB/ai"; local before; before="$(dbsum)"
  local o; o="$(SCRIPT="$SB/m/scripts/dnb-ai-check.sh" run_check $3)"
  local caught=no s   # an absence counts only where the part it is absent from still ran (a crash is not a catch)
  case "$4" in
    copy)    o="$(PHPFILE="$SB/m/scripts/lib/ai_check.php" direct AI_CHECK_DB="$SB/copy.sqlite3")"
             [ "$(count "$o" '== 4. Recent conversations')" = "1" ] && [ "$(count "$o" 'COPYMARKER')" = "0" ] && caught=yes ;;
    canary)  [ "$(printf '%s' "$o" | grep -cE "$CANARIES")" != "0" ] && caught=yes ;;
    dbsum)   [ "$(dbsum)" != "$before" ] && caught=yes ;;
    access)  [ "$(prompts_with 'ACCESSORIES \(optional extras')" != "0" ] && caught=yes ;;
    invented) s="$(section "$o" 'How much is an outdoor access point and a MikroTik router?')"
             [ "$(count "$s" 'AI replies')" = "1" ] && [ "$(count "$s" 'REFUSED')" = "0" ] && caught=yes ;;
    total)   [ "$(count "$(section "$o" 'What would two outdoor access points and the MikroTik cost together?')" 'REFUSED')" != "0" ] && caught=yes ;;
    home)    [ "$(count "$(section "$o" 'How much will I pay to get Starlink installed at my home?')" 'REFUSED')" != "0" ] && caught=yes ;;
    netlist) [ "$(count "$o" 'HARDWARE (one-time)')" = "1" ] && [ "$(printf '%s' "$o" | grep -cE 'NETWORK EQUIPMENT \(one-time\) +2 ')" = "0" ] && caught=yes ;;
    kb)      [ "$(grep -L 'APPROVED KNOWLEDGE' "$SB"/ai/prompt-*.txt 2>/dev/null | wc -l | tr -d ' ')" != "0" ] && caught=yes ;;
    window)  [ "$(printf '%s' "$o" | grep -c 'unlimited business plan?')" != "0" ] && caught=yes ;;
    seen)    s="$(section "$o" 'Do you have unlimited business plans?')"
             [ "$(count "$s" 'it was shown ')" = "1" ] && [ "$(count "$s" 'it was shown 2 plan(s), no Business plan')" = "0" ] && caught=yes ;;
    amounts) s="$(section "$o" 'How much is an outdoor access point and a MikroTik router?')"
             [ "$(count "$s" 'the price check REFUSED the reply')" = "1" ] && [ "$(count "$s" 'refused      amounts')" = "0" ] && caught=yes ;;
    routers) [ "$(count "$o" 'ACCESSORIES (optional extras)')" = "1" ] && [ "$(count "$o" '← Starlink router')" = "0" ] && caught=yes ;;
    bizok)   [ "$(printf '%s' "$o" | grep -c '^  BUSINESS_PLANS ')" = "1" ] && [ "$(count "$o" 'the approved fact')" = "0" ] && caught=yes ;;
    biznot)  [ "$(printf '%s' "$o" | grep -c '^  BUSINESS_PLANS ')" = "1" ] && [ "$(count "$o" 'NOT the approved fact')" = "0" ] && caught=yes ;;
  esac
  check "$caught" "yes" "caught: $1"
  rm -rf "$SB/m"; rm -rf "$DATA"; cp -a "$SB/data.pristine" "$DATA"
}
mutant "masking switched off" 'function mask(string $s): string {=>function mask(string $s): string { return $s;' "" canary
mutant "the live database read instead of the copy" "\$dbFile = (string)(getenv('AI_CHECK_DB') ?: '');=>\$dbFile = \$live; \$live = '/nonexistent';" "" copy
mutant "a write through the connection" "\$pdo->exec('PRAGMA query_only = ON');=>\$pdo = new PDO('sqlite:' . \$live); \$pdo->exec(\"INSERT INTO events (event_type, entity_type, entity_id) VALUES ('x', 'y', 1)\");" "" dbsum
mutant "the sales number asked without its context contract" "if (\$channel === 'sales' && class_exists('BrainContext')) {=>if (false) {" "--ask" access hwoff
mutant "the price check skipped" "if (empty(\$g['safe'])) {=>if (false) {" "--ask" invented
mutant "the knowledge base not loaded" "\$config['knowledge_block'] = class_exists('KnowledgeBase')=>\$config['knowledge_block'] = '' ?: '';\$_x = class_exists('KnowledgeBase')" "--ask" kb
mutant "the plans reported before the Business filter" "\$seen = class_exists('PlanCatalogue') ? (array)PlanCatalogue::forConversation(\$all, \$ctx)['products'] : \$all;=>\$seen = \$all;" "--ask" seen
mutant "the time window ignored" "AND m.sent_at >= datetime('now', ?) ORDER BY=>AND m.sent_at >= datetime('now', ?, '-1000 days') ORDER BY" "" window
  mutant "the worker's catalogue preparation bypassed" "if (method_exists('AiReplyWorker', 'salesCatalogue')) {=>if (false) {" "--ask" access hwoff
  mutant "the worker's price check reached without its settings" "if (method_exists('AiReplyWorker', 'permittedAmounts')) {=>if (false) {" "--ask" total
  mutant "the worker's price check replaced by an empty list" "AiReplyWorker::permittedAmounts(\$ctx, \$prompt, \$config);=>array_slice(AiReplyWorker::permittedAmounts(\$ctx, \$prompt, \$config), 0, 0);" "--ask" home
  mutant "the report's network list not split out" "[\$kit, \$netRows] = NetworkEquipment::split(\$root, \$hw);=>[\$kit, \$netRows] = [\$hw, []];" "" netlist
mutant "a refused reply's amounts not shown" "if ((array)\$g['categories'] === ['foreign:amount']) \$refused = =>if (false) \$refused = " "--ask" amounts
if v45; then
  mutant "the Starlink routers not listed" "\$routers = NetworkEquipment::starlinkRouters(\$root, \$acc);=>\$routers = [];" "" routers
  mutant "the approved Business fact not recognised" "} elseif (preg_match('/priority/i', \$both) && preg_match('/\\b1\\s*Mbps/i', \$both)) {=>} elseif (false) {" "" bizok
else
  mutant "the contradicting Business claim not reported" "if (preg_match('/standard data continues|behaves like standard data|then unlimited standard data/i', \$both)) {=>if (false) {" "" biznot
fi

echo; echo "REHEARSAL: $PASS ok, $FAILN failed"
[ "$FAILN" = "0" ]
