#!/usr/bin/env bash
# Rehearse scripts/deploy-5.18.45.sh's new stages before the operator runs it (docs/40 §15):
#   K   the one knowledge row the release corrects (BUSINESS_PLANS) — a dry run first, that row only, only while
#       still as seeded, as the database's owner;
#   AI  the assistant, asked (scripts/dnb-ai-check.sh --ask, eleven questions), and what it is given judged — with a
#       matcher that is not a pipe (deploy-5.18.44.sh's `printf | grep -q` lost a match live on 27 Sep, docs/40 §13).
# Stage V is deploy-5.18.44.sh's, unchanged, and ran live (all ok, 27 Sep 2026); here a stand-in answers its requests
# and its lines are not judged — every FAIL line must be a V line.
#
# The REAL script, pinned, runs in --after-only mode against a sandbox "container": a fake docker (inspect prints the
# sandbox mount, exec runs the command here, logs is empty), a copy of this checkout's plugin at the sandbox's
# plugin path — its brain pointed at a fake AI provider — a fake uCRM with two Starlink routers among its accessories,
# and a plugin database built by the real migrations and seeded with the knowledge rows as 5.18.44 shipped them:
# BUSINESS_PLANS in its old wording, one other row drifted but still as seeded, and one row a person wrote. Then
# weakened copies of the script must each be caught. It needs this checkout committed and the script pinned to its
# plugin commit; it refuses to run where the server could be.
set -u
R="$(cd "$(dirname "$0")/../../.." && pwd)"; AIH="$R/scripts/harness/ai-check"; P="$R/dishnet-hybrid-sudan"
DEPLOY="$R/scripts/deploy-5.18.45.sh"
for f in /data/ucrm /opt/dishnet /var/run/docker.sock; do
  [ -e "$f" ] && { echo "refusing: $f exists — this looks like the server, and this rehearsal must never run there"; exit 2; }
done
SB="$(mktemp -d)"; PIDS=(); MUTS=()
cleanup() { for p in "${PIDS[@]}"; do kill "$p" 2>/dev/null; done; for m in "${MUTS[@]}"; do rm -f "$m"; done; rm -rf "$SB"; }
trap cleanup EXIT
export NO_PROXY=127.0.0.1,localhost no_proxy=127.0.0.1,localhost DN_VAULT_FILE="$SB/vault.json"
PASS=0; FAILN=0
check() { if [ "$1" = "$2" ]; then PASS=$((PASS+1)); echo "  ok   $3"; else FAILN=$((FAILN+1)); echo "  FAIL $3 (got '$1', want '$2')"; fi; }
has()   { printf '%s' "$1" | grep -qF -- "$2" && echo yes || echo no; }

PIN="$(sed -n 's/^EXPECTED_PLUGIN_COMMIT="\([^"]*\)".*/\1/p' "$DEPLOY")"
HEAD_PLUGIN="$(git -C "$R" log -1 --format=%h -- dishnet-hybrid-sudan)"
check "$PIN" "$HEAD_PLUGIN" "the script is pinned to this checkout's plugin commit"
check "$(git -C "$R" status --porcelain --untracked-files=no | wc -l | tr -d ' ')" "0" "the checkout carries no uncommitted change (the script stops on one)"

free_port() { python3 -c 'import socket;s=socket.socket();s.bind(("127.0.0.1",0));print(s.getsockname()[1]);s.close()'; }
UPORT="$(free_port)"; APORT="$(free_port)"; WPORT="$(free_port)"
CATALOGUE_DIR="$AIH" php -S "127.0.0.1:$UPORT" "$AIH/fake_ucrm.php" >/dev/null 2>&1 & PIDS+=($!)
FAKE_AI_LOG="$SB/ai" php -S "127.0.0.1:$APORT" "$AIH/fake_ai.php" >/dev/null 2>&1 & PIDS+=($!)
# The stand-in for the public address (stage V is not judged here).
cat > "$SB/public.php" <<'PHP'
<?php
$page = $_GET['page'] ?? '';
if ($page === 'customer_portal') { header('Location: ?page=customer_login', true, 302); exit; }
if ($page === 'api') { header('Content-Type: application/json'); echo '{"data":{"tos_version":"1.1","privacy_version":"1.1"}}'; exit; }
echo "<html><body>stand-in</body></html>";
PHP
php -S "127.0.0.1:$WPORT" -t "$SB" >/dev/null 2>&1 & PIDS+=($!)
for i in $(seq 1 50); do curl -s --noproxy '*' "http://127.0.0.1:$UPORT/__ping" | grep -q aicheck && break; sleep 0.1; done

# ── The sandbox container: the plugin where the container keeps it, and its data beside it ──
MOUNT="$SB/mount"; PD="$MOUNT/ucrm/data/plugins/dishnet-hybrid-sudan"; DATA="$MOUNT/ucrm/data/plugins/.dishnet-hybrid-sudan-data"
mkdir -p "$PD" "$DATA" "$SB/bin" "$SB/out"
cp -rL "$P/lib" "$P/workers" "$P/tools" "$PD/"; cp "$P/manifest.json" "$PD/"
for d in migrations assets profiles; do ln -s "$P/$d" "$PD/$d"; done
sed -i "s#https://api.openai.com/v1/chat/completions#http://127.0.0.1:$APORT/v1/chat/completions#" "$PD/lib/DishNetAiBrain.php"
printf '{"pluginDataDir":"%s"}' "$DATA" > "$PD/ucrm.json"
printf '%s\n' "$PIN" > "$PD/.deployed-commit"
git -C "$R" show a4abe5e:dishnet-hybrid-sudan/tools/knowledge_seed.json > "$SB/seed-5.18.44.json"
git -C "$R" show 04155df:dishnet-hybrid-sudan/tools/seed_knowledge.php > "$SB/seed_knowledge-old.php"   # 5.18.43: no --only
cp "$PD/tools/seed_knowledge.php" "$SB/seed_knowledge-now.php"
write_config() {   # $1 the API key ('' for none)
  cat > "$DATA/config.json" <<JSON
{"crm_base_url":"http://127.0.0.1:$UPORT","crm_auth_token":"t","ai_enabled":true,"ai_provider":"openai","openai_api_key":"$1",
 "ai_qualification":"1","ai_hardware_expert":"1","ai_sales_on_all_numbers":"1","ai_lead_capture":"1","ai_currency":"UGX"}
JSON
}
# The rows as 5.18.44 left them: $1 = who last wrote BUSINESS_PLANS ('seed' or a person); $2 = 'noperson'
# leaves out the row a person wrote (the tool's "Edited by hand" list would otherwise stop a run it lists).
seed_db() {
  rm -f "$DATA"/plugin.sqlite3*
  php -r '
    foreach (["bootstrap_data","StoreInterface","JsonStore","SqliteStore","KnowledgeSeeder"] as $l) require_once $argv[1] . "/lib/$l.php";
    $s = SqliteStore::create($argv[2]); $pdo = $s->getPdo();
    KnowledgeSeeder::apply($pdo, json_decode(file_get_contents($argv[3]), true)["items"], false);
    $pdo->prepare("UPDATE knowledge_items SET updated_by = ? WHERE item_key = \x27BUSINESS_PLANS\x27")->execute([$argv[4]]);
    $pdo->exec("UPDATE knowledge_items SET answer = \x27DRIFTED, still as seeded\x27 WHERE item_key = \x27MANY_USERS_HOTSPOT\x27");
    if ($argv[5] !== "noperson") $pdo->exec("UPDATE knowledge_items SET answer = \x27Written by a person\x27, updated_by = \x27admin\x27 WHERE item_key = \x27PUBLIC_IP\x27");
  ' "$PD" "$DATA" "$SB/seed-5.18.44.json" "$1" "${2:-}" >/dev/null
}
row() { php -r '$p=new PDO("sqlite:".$argv[1]); $q=$p->prepare("SELECT answer FROM knowledge_items WHERE item_key=?"); $q->execute([$argv[2]]); echo sha1((string)$q->fetchColumn());' "$DATA/plugin.sqlite3" "$1"; }
rows() { php -r '$p=new PDO("sqlite:".$argv[1]); echo sha1(json_encode($p->query("SELECT item_key, answer, updated_by FROM knowledge_items ORDER BY item_key")->fetchAll(PDO::FETCH_NUM)));' "$DATA/plugin.sqlite3"; }
SEED_NEW="$(php -r 'foreach (json_decode(file_get_contents($argv[1]), true)["items"] as $i) if ($i["item_key"] === "BUSINESS_PLANS") echo sha1($i["answer"]);' "$P/tools/knowledge_seed.json")"
SEED_OLD="$(php -r 'foreach (json_decode(file_get_contents($argv[1]), true)["items"] as $i) if ($i["item_key"] === "BUSINESS_PLANS") echo sha1($i["answer"]);' "$SB/seed-5.18.44.json")"
DRIFT="$(php -r 'echo sha1("DRIFTED, still as seeded");')"; PERSON="$(php -r 'echo sha1("Written by a person");')"

cat > "$SB/bin/docker" <<SH
#!/usr/bin/env bash
printf '%s\n' "\$*" >> "$SB/docker.log"
case "\$1" in
  inspect) echo "$MOUNT"; exit 0 ;;
  logs) exit 0 ;;
  exec) shift ;;
  *) echo "fake docker: \$1 is not supported" >&2; exit 1 ;;
esac
W=""; while [ \$# -gt 0 ]; do case "\$1" in -i) shift ;; -u) shift 2 ;; -w) W="\$2"; shift 2 ;; -e) export "\$2"; shift 2 ;; *) break ;; esac; done
shift; [ -n "\$W" ] && cd "\$W"; exec "\$@"
SH
chmod +x "$SB/bin/docker"
run() {   # the pinned script, or a weakened copy of it ($SCRIPT), in --after-only mode; REHEARSE_KEEP=<dir> keeps each output
  rm -rf "$SB/ai"; : > "$SB/docker.log"
  local n; n=$(( $(cat "$SB/runs" 2>/dev/null || echo 0) + 1 )); echo "$n" > "$SB/runs"   # $(run) is a subshell
  local o; o="$(PATH="$SB/bin:$PATH" IN_CONTAINER="$PD" DNB_OUT="$SB/out" GUARD_SECONDS=0 \
    bash "${SCRIPT:-$DEPLOY}" --after-only --plugin-base "http://127.0.0.1:$WPORT/public.php" 2>&1 </dev/null)"
  [ -n "${REHEARSE_KEEP:-}" ] && printf '%s\n' "$o" > "$REHEARSE_KEEP/run-$(printf '%02d' "$n")${SCRIPT:+-mutant}.txt"
  printf '%s' "$o"
}
fails_outside_v() { printf '%s' "$1" | grep -E '^  FAIL  ' | grep -vcE '^  FAIL  V[0-9]'; }
OWNER="$(stat -c '%u:%g' "$DATA" 2>/dev/null)"

echo "== 1. the first run on 5.18.44's rows: K corrects the one row, AI judges what the assistant is given =="
write_config "sk-test"; seed_db seed
BEFORE_DRIFT="$(row MANY_USERS_HOTSPOT)"; BEFORE_PERSON="$(row PUBLIC_IP)"
check "$(row BUSINESS_PLANS)" "$SEED_OLD" "the sandbox starts with BUSINESS_PLANS in its 5.18.44 wording"
OUT="$(run)"
check "$(has "$OUT" 'would correct: BUSINESS_PLANS')" "yes" "K: the dry run names the one row"
check "$(has "$OUT" 'DRY RUN — nothing was written')" "yes" "K: and says it wrote nothing"
check "$(has "$OUT" 'ok    K BUSINESS_PLANS now reads the 5.18.45 wording')" "yes" "K: the row is corrected"
check "$(has "$OUT" 'ok    K a second dry run has nothing left to do')" "yes" "K: a second dry run finds nothing left"
check "$(row BUSINESS_PLANS)" "$SEED_NEW" "the row now holds the 5.18.45 seed text, word for word"
check "$(row MANY_USERS_HOTSPOT)" "$BEFORE_DRIFT" "a drifted row it was not told about is untouched"
check "$(row PUBLIC_IP)" "$BEFORE_PERSON" "and a person's row is untouched"
check "$(grep -c -- "exec -u $OWNER .*seed_knowledge.php --refresh-seeded --only=BUSINESS_PLANS --dry-run" "$SB/docker.log")" "2" \
  "the tool runs as the database's owner, --only, a dry run before and after"
check "$(grep -c -- "exec -u $OWNER .*seed_knowledge.php --refresh-seeded --only=BUSINESS_PLANS$" "$SB/docker.log")" "1" "and exactly one real run"
for l in 'ok    AI the check reads plugin 5.18.45' 'ok    AI network equipment is listed to the assistant' 'ok    AI a router (the MikroTik) is among it' \
         'ok    AI an access point is among it' 'ok    AI approved knowledge reaches the assistant up to 1,000 characters' \
         'ok    AI Starlink routers are listed to the assistant: STARLINK ROUTERS (indoor) 2' \
         'ok    AI the data-allowance fact is stated to the assistant' \
         'ok    AI BUSINESS_PLANS reaches the assistant in its 5.18.45 wording (981 characters, whole)' \
         'ok    AI no knowledge row says standard data continues after the priority block' 'ok    AI the eleven questions were asked'; do
  check "$(has "$OUT" "$l")" "yes" "AI: ${l#ok    AI }"
done
check "$(ls "$SB/ai" 2>/dev/null | wc -l | tr -d ' ')" "11" "eleven model calls, on the fake provider"
check "$(has "$OUT" 'note  AI MANY_USERS_HOTSPOT is no longer the 5.18.44 row')" "yes" "AI: the drifted row is noted, not failed"
check "$(fails_outside_v "$OUT")" "0" "no FAIL outside stage V (V answers a stand-in here)"
check "$(has "$OUT" "ok    live commit is $PIN")" "yes" "--after-only confirms the container serves the pin before anything else"

echo "== 1b. with no row a person wrote (the control for the first weakened copy): still that one row only =="
write_config "sk-test"; seed_db seed noperson; OUT="$(run)"
check "$(has "$OUT" 'ok    K BUSINESS_PLANS now reads the 5.18.45 wording')" "yes" "K: the row is corrected"
check "$(row BUSINESS_PLANS)" "$SEED_NEW" "to the 5.18.45 seed text"
check "$(row MANY_USERS_HOTSPOT)" "$DRIFT" "and the drifted row, still as seeded, is untouched — only --only kept it so"
check "$(fails_outside_v "$OUT")" "0" "no FAIL outside stage V"

echo "== 2. run again: nothing left to do, nothing written =="
B="$(rows)"; OUT="$(run)"
check "$(has "$OUT" 'ok    K BUSINESS_PLANS already reads the 5.18.45 wording — nothing to do')" "yes" "K: already corrected"
check "$(rows)" "$B" "the knowledge rows are unchanged"
check "$(fails_outside_v "$OUT")" "0" "no FAIL outside stage V"

echo "== 3. the row a person edited: reported, left exactly as it is =="
seed_db admin; B="$(rows)"; OUT="$(run)"
check "$(has "$OUT" 'note  K BUSINESS_PLANS was edited by a person and is left exactly as it is')" "yes" "K: reported"
check "$(rows)" "$B" "and nothing written"
check "$(has "$OUT" "note  AI BUSINESS_PLANS is a person's wording")" "yes" "AI: says whose wording the assistant reads"
check "$(has "$OUT" 'FAIL  AI a knowledge row still says standard data continues after the priority block')" "yes" \
  "AI: and as that wording still says standard data continues, it is a failure to send back"

echo "== 4. an older tool in the container: never run =="
seed_db seed; cp "$SB/seed_knowledge-old.php" "$PD/tools/seed_knowledge.php"; B="$(rows)"; OUT="$(run)"
check "$(has "$OUT" "FAIL  K the installed tools/seed_knowledge.php has no --only")" "yes" "K: refuses, and says why"
check "$(rows)" "$B" "nothing written — the old tool would have refreshed every seeded row"
cp "$SB/seed_knowledge-now.php" "$PD/tools/seed_knowledge.php"

echo "== 5. no AI key: the questions are not asked, and that is a failure =="
seed_db seed; write_config ""; OUT="$(run)"
check "$(has "$OUT" 'FAIL  AI the questions were not asked')" "yes" "AI: reported"
check "$(ls "$SB/ai" 2>/dev/null | wc -l | tr -d ' ')" "0" "no model call"
write_config "sk-test"

echo "== 6. the container serves another commit: --after-only stops before anything is written or asked =="
seed_db seed; printf '%s\n' 04155df > "$PD/.deployed-commit"; B="$(rows)"; OUT="$(run)"
check "$(has "$OUT" "STOP: the container serves 04155df, not $PIN")" "yes" "stops, and names both commits"
check "$(rows)" "$B" "nothing written"
check "$(grep -c 'seed_knowledge.php' "$SB/docker.log")" "0" "the knowledge tool never ran"
check "$(ls "$SB/ai" 2>/dev/null | wc -l | tr -d ' ')" "0" "and no model call"
printf '%s\n' "$PIN" > "$PD/.deployed-commit"

echo "== 7. the matcher is not a pipe: a 1 MB check output with the match on its first line is found every time =="
race() {   # $1 a script holding an aihas() — its definition is tried 20 times under pipefail
  local fn; fn="$(grep -E '^aihas\(\) \{' "$1" | head -1)"
  [ -n "$fn" ] || { echo "no-aihas"; return; }
  bash -c 'set -uo pipefail; '"$fn"'
    AIOUT="$(printf "%s\n" "== DishNet AI check (report) — plugin 5.18.45 — now =="; head -c 1000000 /dev/zero | tr "\0" "y")"
    n=0; for i in $(seq 1 20); do aihas "\(report\) — plugin 5\.18\.45 " && n=$((n+1)); done; echo "$n"'
}
check "$(race "$DEPLOY")" "20" "the pinned script's matcher finds it 20 times out of 20"
printf '%s\n' "aihas() { printf '%s' \"\$AIOUT\" | grep -qE -- \"\$1\"; }" > "$SB/pipe-form.sh"
N_PIPE="$(race "$SB/pipe-form.sh")"
check "$([ "$N_PIPE" != "20" ] && echo yes || echo no)" "yes" "control: the 5.18.44 form, a pipe, misses under the same load ($N_PIPE of 20 found)"

echo "== 8. weakened copies of the script must each fail =="
mutant() {   # $1 label · $2 old · $3 new · $4 scenario: fresh | freshclean | oldtool | nokey · $5 what must be seen broken
  local m="$R/scripts/.deploy-5.18.45.mutant-$$-${#MUTS[@]}.sh"; MUTS+=("$m")
  python3 - "$DEPLOY" "$m" "$2" "$3" <<'PY' || { echo "  FAIL mutant edit did not apply: $1"; FAILN=$((FAILN+1)); return; }
import sys; src, dst, old, new = sys.argv[1:5]; s = open(src).read()
assert s.count(old) == 1, 'anchor not unique: ' + old
open(dst, 'w').write(s.replace(old, new))
PY
  if [ "$4" = freshclean ]; then seed_db seed noperson; else seed_db seed; fi; write_config "sk-test"
  [ "$4" = oldtool ] && cp "$SB/seed_knowledge-old.php" "$PD/tools/seed_knowledge.php"
  [ "$4" = nokey ] && write_config ""
  local o; o="$(SCRIPT="$m" run)"; local caught=no
  case "$5" in
    drift)   [ "$(row MANY_USERS_HOTSPOT)" != "$DRIFT" ] && caught=yes ;;
    owner)   [ "$(grep -c -- "exec -u $OWNER .*seed_knowledge.php" "$SB/docker.log")" = "0" ] && [ "$(grep -c 'seed_knowledge.php' "$SB/docker.log")" != "0" ] && caught=yes ;;
    askok)   [ "$(has "$o" 'ok    AI the ten questions were asked')" = "yes" ] && caught=yes ;;
    nodry)   [ "$(grep -c -- 'seed_knowledge.php .*--dry-run' "$SB/docker.log")" = "0" ] && [ "$(grep -c 'seed_knowledge.php' "$SB/docker.log")" != "0" ] && caught=yes ;;
    pipe)    [ "$(race "$m")" != "20" ] && caught=yes ;;
  esac
  check "$caught" "yes" "caught: $1"
  cp "$SB/seed_knowledge-now.php" "$PD/tools/seed_knowledge.php"; write_config "sk-test"; rm -f "$m"
}
mutant "the refresh not limited to the one row" '--refresh-seeded --only="$K_ROW" "$@"' '--refresh-seeded "$@"' freshclean drift
mutant "an older tool run anyway" "elif ! docker exec \"\$CONTAINER\" grep -q -- '--only=' \"\$IN_CONTAINER/tools/seed_knowledge.php\" 2>/dev/null; then" 'elif false; then' oldtool drift
mutant "the tool run as root, not as the database's owner" 'docker exec -u "$DB_OWNER" "$CONTAINER" php "$IN_CONTAINER/tools/seed_knowledge.php"' 'docker exec "$CONTAINER" php "$IN_CONTAINER/tools/seed_knowledge.php"' fresh owner
mutant "questions not asked, reported as asked" "aihas 'Asked: 11 model call'" "aihas '.'" nokey askok
mutant "no dry run before the correction" 'KDRY="$(kseed --dry-run)"' 'KDRY="$(kseed)"' fresh nodry
mutant "the matcher a pipe again" 'aihas() { grep -qE -- "$1" <<<"$AIOUT"; }' "aihas() { printf '%s' \"\$AIOUT\" | grep -qE -- \"\$1\"; }" fresh pipe

echo; echo "REHEARSAL: $PASS ok, $FAILN failed"
[ "$FAILN" = "0" ]
