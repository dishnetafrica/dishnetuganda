#!/usr/bin/env bash
# Rehearse scripts/dnb-ai-check.sh before anyone runs it on the server (docs/40).
#
# A sandbox plugin directory holds a COPY of the plugin's lib/ and workers/ — the brain's OpenAI address pointed at a
# fake provider, which is the only change — with the rest linked. A fake uCRM serves plans and products (an outdoor
# access point and a MikroTik among them); a fake `docker exec` runs the check in the sandbox. The plugin database is
# built by the real migrations and knowledge seed, with conversations that carry canaries: a phone, an e-mail, a kit
# number, a staff name, an event's phone, the API key and the uCRM token. None may appear in the output, the database
# must be byte-identical afterwards, and every claim the report makes is checked against what was seeded.
# Then weakened copies of the check must each fail.
set -u
R="$(cd "$(dirname "$0")/../../.." && pwd)"; H="$R/scripts/harness/ai-check"; P="$R/dishnet-hybrid-sudan"
SB="$(mktemp -d)"; PIDS=()
cleanup() { for p in "${PIDS[@]}"; do kill "$p" 2>/dev/null; done; rm -rf "$SB"; }
trap cleanup EXIT
export NO_PROXY=127.0.0.1,localhost no_proxy=127.0.0.1,localhost
PASS=0; FAILN=0
check() { if [ "$1" = "$2" ]; then PASS=$((PASS+1)); echo "  ok   $3"; else FAILN=$((FAILN+1)); echo "  FAIL $3 (got '$1', want '$2')"; fi; }
has()   { printf '%s' "$1" | grep -qF -- "$2" && echo yes || echo no; }
count() { printf '%s' "$1" | grep -cF -- "$2"; }

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
write_config() {   # $1 = the API key ('' for none)
  cat > "$DATA/config.json" <<JSON
{"crm_base_url":"http://127.0.0.1:$UPORT","crm_auth_token":"CANARY-CRM-TOKEN","ai_enabled":true,"ai_provider":"openai",
 "openai_api_key":"$1","ai_qualification":"1","ai_hardware_expert":"1","ai_sales_on_all_numbers":"1","ai_lead_capture":"1",
 "ai_currency":"UGX","bot_instructions_mode":"append",
 "bot_custom_instructions":"Always be polite.\nFor business customers who need unlimited data recommend Residential. Call +256 700 111 222 for help."}
JSON
}
write_config "sk-test-canary-0123456789abcdef"
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
CANARIES='772 123 456|772123456|someone@example.com|KIT3040X1234|Richard|256700999888|sk-test-canary|CANARY-CRM-TOKEN|700 111 222|OLDCANARY'

echo "== 1. report: spends nothing, prints what the assistant is given and what customers got =="
BEFORE="$(dbsum)"; FILES_BEFORE="$(ls "$DATA" | sort | tr '\n' ' ')"
OUT="$(run_check)"; rc=$?
check "$rc" "0" "the report exits 0"
check "$(dbsum)" "$BEFORE" "the plugin database is byte-identical afterwards"
check "$(ls "$DATA" | sort | tr '\n' ' ')" "$FILES_BEFORE" "no file appears in or leaves the data directory"
check "$(ls "$SB/ai" 2>/dev/null | wc -l | tr -d ' ')" "0" "the report makes no model call"
check "$(printf '%s' "$OUT" | grep -cE "$CANARIES")" "0" "no phone, e-mail, kit number, staff name, key or token is printed"
check "$(count "$OUT" '@@')" "0" "the script's own @@ lines are never printed"
check "$(has "$OUT" 'key set (not shown)')" "yes" "the API key is reported as set, not shown"
check "$(has "$OUT" '“For business customers who need unlimited data recommend Residential. Call {phone} for help.”')" "yes" \
  "the extra instruction on these topics is shown, its phone masked"
check "$(has "$OUT" 'Always be polite')" "no" "an extra instruction on other topics is not"
check "$(printf '%s' "$OUT" | grep -cE '(sales|support) +1  \(last')" "2" "the AI's own replies in the last 7 days: one on each number"
check "$(printf '%s' "$OUT" | grep -cE '^    ★ ')" "2" "a customer saying \"business, unlimited\" is shown exactly two plans (★)"
check "$(printf '%s' "$OUT" | grep -E '^    ★ ' | grep -c 'Residential')" "2" "…and they are the two Residential plans"
check "$(has "$OUT" 'Old retired plan')" "no" "a plan uCRM marks inactive is not in the list"
check "$(printf '%s' "$OUT" | grep -E 'Outdoor Access Point|MikroTik Router' | grep -c '← network equipment')" "2" \
  "the outdoor access point and the MikroTik are in HARDWARE, marked"
check "$(printf '%s' "$OUT" | grep -cE 'ACCESSORIES \(optional extras\) +2 — shown on the support and accounts numbers only; the sales number never sees them')" "1" \
  "the accessories are counted, with the sales-number gap stated"
check "$(printf '%s' "$OUT" | grep -cE 'plan copies dropped +2 product')" "1" "the two products named like a plan are reported as dropped"
check "$(printf '%s' "$OUT" | grep -A1 '^  BUSINESS_PLANS ' | grep -c 'CUT at 600')" "1" "BUSINESS_PLANS is reported cut at 600 characters"
check "$(printf '%s' "$OUT" | grep -A3 '^  MANY_USERS_HOTSPOT ' | grep -c '"unlimited" reaches the assistant here: NO — only in the cut part')" "1" \
  "MANY_USERS_HOTSPOT's \"unlimited\" is reported as lost in the cut"
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

echo "== 2. --ask: nine questions through the live path, against a fake provider =="
BEFORE="$(dbsum)"; rm -rf "$SB/ai"
OUT="$(run_check --ask)"; rc=$?
check "$rc" "0" "--ask exits 0"
check "$(dbsum)" "$BEFORE" "the plugin database is byte-identical afterwards"
check "$(ls "$SB/ai" | wc -l | tr -d ' ')" "9" "exactly nine model calls"
check "$(has "$OUT" '9 model call(s)')" "yes" "…and the report says nine"
check "$(grep -L 'APPROVED KNOWLEDGE' "$SB"/ai/prompt-*.txt | wc -l | tr -d ' ')" "0" "every prompt carries the knowledge base, as the worker's does"
check "$(grep -l 'ACCESSORIES (optional extras' "$SB"/ai/prompt-*.txt | wc -l | tr -d ' ')" "0" "no sales prompt carries ACCESSORIES: the sales number's own context contract was used"
check "$(grep -l '^- Outdoor Access Point — price 450000 one-time' "$SB"/ai/prompt-*.txt | wc -l | tr -d ' ')" "9" "every prompt lists the outdoor access point under HARDWARE"
Q="$(grep -l '^LAST: Do you have unlimited business plans?' "$SB"/ai/prompt-*.txt | head -1)"
check "$(grep -cE '^- Business .* — price ' "$Q")" "0" "\"unlimited business plans?\" is answered with no Business plan in PLANS"
Q="$(grep -l '^LAST: How much is Business 500?' "$SB"/ai/prompt-*.txt | head -1)"
check "$(grep -cE '^- Business .* — price ' "$Q")" "3" "a customer who names Business 500 is given the Business plans"
check "$(printf '%s' "$OUT" | grep -A6 'Do you have unlimited business plans?' | grep -c 'the Business-plan note was added')" "1" \
  "a reply naming only a Business plan gets the note, as on the live path"
check "$(printf '%s' "$OUT" | grep -A6 'Do you have unlimited business plans?' | grep -c 'amounts of priority data')" "1" "…and the note is in what the customer receives"
check "$(printf '%s' "$OUT" | grep -A6 'What would two outdoor access points' | grep -c 'REFUSED')" "1" \
  "a multiplied total is refused by the price check, as on the live path"
check "$(printf '%s' "$OUT" | grep -A3 'What would two outdoor access points' | grep -c "AI replies   I'm not able to complete that one automatically")" "1" \
  "…and the customer would get the fallback"
check "$(has "$OUT" '1 refused by the price check; 1 with the Business-plan note added')" "yes" "the closing tally matches"
check "$(printf '%s' "$OUT" | grep -A8 'Do you have unlimited business plans?' | grep -c 'it was shown 2 plan(s), no Business plan')" "1" \
  "the report says what the model saw: two plans, no Business, for \"unlimited business plans?\""
check "$(printf '%s' "$OUT" | grep -A5 'How much is Business 500?' | grep -c 'it was shown 5 plan(s), including 3 Business')" "1" \
  "…and all five, three Business, once the customer names one"
check "$(printf '%s' "$OUT" | grep -cE "$CANARIES")" "0" "nothing secret or personal is printed in --ask either"

echo "== 3. --ask without a key: stops, asks nothing, says so =="
write_config ""; rm -rf "$SB/ai"
OUT="$(run_check --ask)"; rc=$?
check "$rc" "1" "exits 1"
check "$(has "$OUT" 'STOP: no AI provider key is configured — nothing was asked')" "yes" "says why"
check "$(ls "$SB/ai" 2>/dev/null | wc -l | tr -d ' ')" "0" "no model call was made"
check "$(has "$OUT" 'key NOT SET')" "yes" "the report part still ran and shows the key as not set"
write_config "sk-test-canary-0123456789abcdef"

echo "== 4. weakened copies must each fail =="
cp -a "$DATA" "$SB/data.pristine"
mutant() {   # $1 label, $2 an edit to ai_check.php (old=>new), $3 args, $4 which property must now be seen broken
  mkdir -p "$SB/m"; cp -r "$R/scripts" "$SB/m/scripts"
  python3 - "$SB/m/scripts/lib/ai_check.php" "$2" <<'PY' || { echo "  FAIL mutant edit did not apply"; FAILN=$((FAILN+1)); return; }
import sys; p, spec = sys.argv[1], sys.argv[2]; old, new = spec.split('=>', 1); s = open(p).read()
assert s.count(old) == 1, 'anchor not unique: ' + old
open(p, 'w').write(s.replace(old, new))
PY
  rm -rf "$SB/ai"; local before; before="$(dbsum)"
  local o; o="$(SCRIPT="$SB/m/scripts/dnb-ai-check.sh" run_check $3)"
  local caught=no
  case "$4" in
    copy)    o="$(PHPFILE="$SB/m/scripts/lib/ai_check.php" direct AI_CHECK_DB="$SB/copy.sqlite3")"; [ "$(count "$o" 'COPYMARKER')" = "0" ] && caught=yes ;;
    canary)  [ "$(printf '%s' "$o" | grep -cE "$CANARIES")" != "0" ] && caught=yes ;;
    dbsum)   [ "$(dbsum)" != "$before" ] && caught=yes ;;
    access)  [ "$(grep -l 'ACCESSORIES (optional extras' "$SB"/ai/prompt-*.txt 2>/dev/null | wc -l | tr -d ' ')" != "0" ] && caught=yes ;;
    refused) [ "$(printf '%s' "$o" | grep -A6 'What would two outdoor access points' | grep -c 'REFUSED')" = "0" ] && caught=yes ;;
    kb)      [ "$(grep -L 'APPROVED KNOWLEDGE' "$SB"/ai/prompt-*.txt 2>/dev/null | wc -l | tr -d ' ')" != "0" ] && caught=yes ;;
    window)  [ "$(printf '%s' "$o" | grep -c 'unlimited business plan?')" != "0" ] && caught=yes ;;
    seen)    [ "$(printf '%s' "$o" | grep -A8 'Do you have unlimited business plans?' | grep -c 'it was shown 2 plan(s), no Business plan')" = "0" ] && caught=yes ;;
  esac
  check "$caught" "yes" "caught: $1"
  rm -rf "$SB/m"; rm -rf "$DATA"; cp -a "$SB/data.pristine" "$DATA"
}
mutant "masking switched off" 'function mask(string $s): string {=>function mask(string $s): string { return $s;' "" canary
mutant "the live database read instead of the copy" "\$dbFile = (string)(getenv('AI_CHECK_DB') ?: '');=>\$dbFile = \$live; \$live = '/nonexistent';" "" copy
mutant "a write through the connection" "\$pdo->exec('PRAGMA query_only = ON');=>\$pdo = new PDO('sqlite:' . \$live); \$pdo->exec(\"INSERT INTO events (event_type, entity_type, entity_id) VALUES ('x', 'y', 1)\");" "" dbsum
mutant "the sales number asked without its context contract" "if (\$channel === 'sales' && class_exists('BrainContext')) {=>if (false) {" "--ask" access
mutant "the price check skipped" "if (empty(\$g['safe'])) {=>if (false) {" "--ask" refused
mutant "the knowledge base not loaded" "\$config['knowledge_block'] = class_exists('KnowledgeBase') ? KnowledgeBase::promptBlock(\$pdo) : '';=>\$config['knowledge_block'] = '';" "--ask" kb
mutant "the plans reported before the Business filter" "\$seen = class_exists('PlanCatalogue') ? (array)PlanCatalogue::forConversation(\$all, \$ctx)['products'] : \$all;=>\$seen = \$all;" "--ask" seen
mutant "the time window ignored" "AND m.sent_at >= datetime('now', ?) ORDER BY=>AND m.sent_at >= datetime('now', ?, '-1000 days') ORDER BY" "" window

echo; echo "REHEARSAL: $PASS ok, $FAILN failed"
[ "$FAILN" = "0" ]
