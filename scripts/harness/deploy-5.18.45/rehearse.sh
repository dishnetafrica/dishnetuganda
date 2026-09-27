#!/usr/bin/env bash
# Rehearse scripts/deploy-5.18.45.sh's new stages before the operator runs it (docs/40 §15):
#   K   the one knowledge row the release corrects (BUSINESS_PLANS) — a dry run first, that row only, only while
#       still as seeded, as the database's owner;
#   AI  the assistant, asked (scripts/dnb-ai-check.sh --ask, eleven questions), and what it is given judged — with a
#       matcher that is not a pipe (deploy-5.18.44.sh's `printf | grep -q` lost a match live on 27 Sep, docs/40 §13).
#   A   the backup (docs/40 §15.1), in deploy mode, while both databases and a log are written to: the script as the
#       operator ran it at 07:00 UTC on 27 Sep stops NO-GO as it did live; the fixed one copies each database as of one
#       moment (VACUUM INTO, as its owner), archives the rest without them, shows tar's words, and says GO — then stops
#       at B, where no terminal can type DEPLOY. Four real failures each stop it, and seven weakened copies are caught.
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
shift; [ -n "\$W" ] && cd "\$W"
# $SB/mangle: a database copy is changed on its way out of the container, keeping its size
case " \$* " in *" display_errors=stderr "*) if [ -e "$SB/mangle" ]; then "\$@" | LC_ALL=C sed '1s/^SQLite format 3/SQLite format X/'; exit "\${PIPESTATUS[0]}"; fi ;; esac
exec "\$@"
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
    askok)   [ "$(has "$o" 'ok    AI the eleven questions were asked')" = "yes" ] && caught=yes ;;
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

echo "== 9. stage A's backup under live writes (deploy mode; with no terminal to type DEPLOY on, it stops at B) =="
# The first live run, at 07:00:32 UTC on 27 Sep, stopped here: "backup of …-data failed", NO-GO, tar's words thrown
# away. Here the plugin's two databases and a log are written to all the while, as at the top of an hour.
REAL_TAR="$(command -v tar)"
cat > "$SB/bin/tar" <<SH
#!/usr/bin/env bash
# tar, able to fail as a disk can: $SB/tarmode = fatal | corrupt, for the data directory's archive only
mode="\$(cat "$SB/tarmode" 2>/dev/null)"; arch=""; prev=""
for a in "\$@"; do [ "\$prev" = "-czf" ] && arch="\$a"; prev="\$a"; done
if [ -n "\$mode" ] && [ "\${arch##*/}" = ".dishnet-hybrid-sudan-data.tar.gz" ]; then
  ( exec -a tar "$REAL_TAR" "\$@" ) 2>/dev/null
  case "\$mode" in
    fatal)   echo "tar: .dishnet-hybrid-sudan-data/uploads: Cannot open: Input/output error" >&2; exit 2 ;;
    corrupt) printf 'not a gzip archive' > "\$arch"; echo "tar: .dishnet-hybrid-sudan-data/logs/x.log: file changed as we read it" >&2; exit 1 ;;
  esac
fi
exec -a tar "$REAL_TAR" "\$@"   # named tar, so it speaks as on the server ("tar: …")
SH
chmod +x "$SB/bin/tar"
DD=".dishnet-hybrid-sudan-data"
mkdir -p "$SB/ctmp" "$DATA/logs" "$DATA/backups"
BIGLOG="$DATA/logs/wa-256700123456.log"   # a phone-length number in a file name: what tar says about it must be masked
head -c 12000000 /dev/urandom | base64 > "$BIGLOG"
seed_db seed; cp "$DATA/plugin.sqlite3" "$DATA/backups/plugin.sqlite3"   # one level down: not a live database
php -r '$p=new PDO("sqlite:".$argv[1]); $p->exec("PRAGMA journal_mode=WAL"); $p->exec("CREATE TABLE wa_messages(id INTEGER PRIMARY KEY, body TEXT)");
  for ($i = 0; $i < 500; $i++) $p->exec("INSERT INTO wa_messages(body) VALUES (\x27hello $i\x27)");' "$DATA/dishnet.sqlite"
cp "$DATA/dishnet.sqlite" "$SB/dishnet.good"
WPIDS=()
writers_start() {   # both databases get a row every 2 ms, the log a line every 2 ms — until the stop file, or 5 minutes
  rm -f "$SB/stop-writers"
  for db in "$DATA/plugin.sqlite3" "$DATA/dishnet.sqlite"; do
    php -r 'try { $p = new PDO("sqlite:".$argv[1], null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
      $p->exec("PRAGMA busy_timeout=5000"); $p->exec("PRAGMA journal_mode=WAL");
      $p->exec("CREATE TABLE IF NOT EXISTS harness_writes(id INTEGER PRIMARY KEY, at REAL, pad TEXT)"); $end = microtime(true) + 300;
      while (!file_exists($argv[2]) && microtime(true) < $end) { $p->exec("INSERT INTO harness_writes(at, pad) VALUES (" . microtime(true) . ", \x27" . str_repeat("y", 200) . "\x27)"); usleep(2000); }
    } catch (Throwable $e) {}' "$db" "$SB/stop-writers" &
    WPIDS+=($!); PIDS+=($!)
  done
  ( end=$((SECONDS + 300)); while [ ! -e "$SB/stop-writers" ] && [ -d "$DATA/logs" ] && [ "$SECONDS" -lt "$end" ]; do
      echo "appended $(date +%s%N)" >> "$BIGLOG"; sleep 0.002; done ) & WPIDS+=($!); PIDS+=($!)
  sleep 0.5
}
writers_stop() { touch "$SB/stop-writers"; for p in "${WPIDS[@]}"; do wait "$p" 2>/dev/null; done; WPIDS=(); }
run_full() {   # the pinned script, or $SCRIPT, in deploy mode: the container serves another commit, so stage A takes a backup
  : > "$SB/docker.log"; rm -rf "$SB/out"; mkdir -p "$SB/out"; rm -f "$SB/ctmp"/*
  local n; n=$(( $(cat "$SB/runs" 2>/dev/null || echo 0) + 1 )); echo "$n" > "$SB/runs"
  printf '%s\n' 04155df > "$PD/.deployed-commit"
  writers_start
  local o; o="$(PATH="$SB/bin:$PATH" IN_CONTAINER="$PD" DNB_OUT="$SB/out" DNB_SNAPSHOT_TMP="$SB/ctmp" GUARD_SECONDS=0 \
    setsid -w bash "${SCRIPT:-$DEPLOY}" --plugin-base "http://127.0.0.1:$WPORT/public.php" 2>&1 </dev/null)"
  writers_stop
  printf '%s\n' "$PIN" > "$PD/.deployed-commit"
  [ -n "${REHEARSE_KEEP:-}" ] && printf '%s\n' "$o" > "$REHEARSE_KEEP/run-$(printf '%02d' "$n")-full${SCRIPT:+-mutant}.txt"
  printf '%s' "$o"
}
bkdir()   { ls -d "$SB/out"/backup-* 2>/dev/null | head -1; }
dbq()     { php -r '$p = new PDO("sqlite:".$argv[1]); echo $p->query($argv[2])->fetchColumn();' "$1" "$2" 2>/dev/null; }
tarlist() { tar -tzf "$1" 2>/dev/null; }
garbage_db()  { rm -f "$DATA"/dishnet.sqlite*; head -c 65536 /dev/urandom > "$DATA/dishnet.sqlite"; }
restore_db()  { rm -f "$DATA"/dishnet.sqlite*; cp "$SB/dishnet.good" "$DATA/dishnet.sqlite"; }

echo "-- 9a. the control: the script as the operator ran it on 27 Sep (4fe4cb7), under the same writes, stops as it did live"
write_config "sk-test"; seed_db seed
OLD="$R/scripts/.deploy-5.18.45.before-fix-$$.sh"; MUTS+=("$OLD")
git -C "$R" show 4fe4cb7:scripts/deploy-5.18.45.sh > "$OLD"
OUT="$(SCRIPT="$OLD" run_full)"
check "$(has "$OUT" "FAIL  backup of $DATA failed")" "yes" "control: the backup of the data directory fails, as live"
check "$(has "$OUT" 'STOP: NO-GO: the backup did not complete')" "yes" "control: NO-GO, as live"
check "$(has "$OUT" 'file changed as we read it')" "no" "control: and nothing says why, as live"
rm -f "$OLD"
writers_start; tar -C "$(dirname "$DATA")" -czf "$SB/bare.tar.gz" "$DD" 2> "$SB/bare.err"; BARE_RC=$?; writers_stop
check "$BARE_RC" "1" "the mechanism: GNU tar exits 1 when a file grows while it reads it"
check "$(has "$(cat "$SB/bare.err")" "$DD/logs/wa-256700123456.log: file changed as we read it")" "yes" "saying 'file changed as we read it' — and the archive is still written"

echo "-- 9b. the fixed script under the same writes: each database copied as of one moment, the rest archived, GO"
seed_db seed; B="$(rows)"
OUT="$(run_full)"; BK="$(bkdir)"
check "$(has "$OUT" "ok    backed up plugin.sqlite3 → $BK/plugin.sqlite3")" "yes" "plugin.sqlite3 is copied"
check "$(has "$OUT" "ok    backed up dishnet.sqlite → $BK/dishnet.sqlite")" "yes" "dishnet.sqlite is copied"
check "$(printf '%s' "$OUT" | grep -c "one consistent copy (VACUUM INTO as $OWNER, SQLite [0-9.]*), integrity ok, [0-9]* tables, the same sha256 on both sides")" "2" \
  "each with VACUUM INTO, as the database's owner, checked, the same sha256 on both sides"
check "$(has "$OUT" "ok    backed up $DATA → $BK/$DD.tar.gz")" "yes" "the data directory is archived"
check "$(has "$OUT" 'without the live databases, copied above')" "yes" "without its live databases"
check "$(has "$OUT" "note  while tar read $DATA, 1 file(s) changed or went away (tar exit 1")" "yes" "the log that grew is a note, not a failure"
check "$(has "$OUT" "tar: $DD/logs/wa-####.log: file changed as we read it")" "yes" "and tar's own words are shown"
check "$(has "$OUT" '256700123456')" "no" "with the phone-length number in the file name masked"
check "$(printf '%s' "$OUT" | grep -c '^  FAIL ')" "0" "no FAIL"
check "$(has "$OUT" 'GO — evidence recorded')" "yes" "GO"
check "$(has "$OUT" 'STOP: not confirmed')" "yes" "then stage B, with no terminal to type DEPLOY on, stops before deploying"
check "$(dbq "$BK/plugin.sqlite3" 'PRAGMA integrity_check')" "ok" "the plugin.sqlite3 copy passes integrity_check here too"
check "$(dbq "$BK/plugin.sqlite3" 'SELECT count(*) FROM knowledge_items')" "$(dbq "$DATA/plugin.sqlite3" 'SELECT count(*) FROM knowledge_items')" "and holds every knowledge row"
N_COPY="$(dbq "$BK/plugin.sqlite3" 'SELECT count(*) FROM harness_writes')"; N_LIVE="$(dbq "$DATA/plugin.sqlite3" 'SELECT count(*) FROM harness_writes')"
check "$([ "${N_COPY:-0}" -gt 0 ] && [ "${N_COPY:-0}" -lt "${N_LIVE:-0}" ] && echo yes || echo no)" "yes" \
  "and the rows written up to one moment while writes went on ($N_COPY of the $N_LIVE written by the end)"
check "$(dbq "$BK/dishnet.sqlite" 'PRAGMA integrity_check')" "ok" "the dishnet.sqlite copy passes integrity_check"
check "$(dbq "$BK/dishnet.sqlite" 'SELECT count(*) FROM wa_messages')" "500" "and holds its 500 messages"
L="$(tarlist "$BK/$DD.tar.gz")"
for f in plugin.sqlite3 plugin.sqlite3-wal plugin.sqlite3-shm dishnet.sqlite dishnet.sqlite-wal dishnet.sqlite-shm; do
  check "$(printf '%s\n' "$L" | grep -cxF "$DD/$f")" "0" "the archive leaves out the live $f"
done
check "$(printf '%s\n' "$L" | grep -cxF "$DD/config.json")" "1" "the archive holds config.json"
check "$(printf '%s\n' "$L" | grep -cxF "$DD/logs/wa-256700123456.log")" "1" "and the log"
check "$(printf '%s\n' "$L" | grep -cxF "$DD/backups/plugin.sqlite3")" "1" "and a database one level down — only the live ones are left out"
check "$(grep -c -- "^exec -u $OWNER ucrm php -d display_errors=stderr -r " "$SB/docker.log")" "2" "both copies are made inside the container by the database's owner"
check "$(ls -A "$SB/ctmp" | wc -l | tr -d ' ')" "0" "no temporary copy is left in the container"
check "$(rows)" "$B" "no knowledge row was written"
check "$(stat -c %a "$BK")" "700" "the backup is root's alone"

echo "-- 9c. a database that cannot be copied: FAIL with SQLite's own reason, NO-GO"
seed_db seed; garbage_db; OUT="$(run_full)"; restore_db
check "$(has "$OUT" 'FAIL  backup of dishnet.sqlite failed (exit 2): PDOException: ')" "yes" "the copy fails, and says so"
check "$(has "$OUT" 'file is not a database')" "yes" "with SQLite's reason"
check "$(has "$OUT" 'ok    backed up plugin.sqlite3 → ')" "yes" "the other database is still copied"
check "$(has "$OUT" 'STOP: NO-GO: the backup did not complete')" "yes" "NO-GO"
check "$(has "$OUT" 'STOP: not confirmed')" "no" "stage B is never reached"
check "$(ls -A "$SB/ctmp" | wc -l | tr -d ' ')" "0" "no temporary copy is left in the container"

echo "-- 9d. tar fails for real (exit 2): FAIL with tar's own words, NO-GO"
seed_db seed; echo fatal > "$SB/tarmode"; OUT="$(run_full)"; rm -f "$SB/tarmode"
check "$(has "$OUT" "FAIL  backup of $DATA failed (tar exit 2). It said:")" "yes" "reported with tar's exit status"
check "$(has "$OUT" "tar: $DD/uploads: Cannot open: Input/output error")" "yes" "and tar's own words"
check "$(has "$OUT" 'STOP: NO-GO: the backup did not complete')" "yes" "NO-GO"

echo "-- 9e. tar exits 1 but its archive cannot be read back: FAIL, NO-GO"
seed_db seed; echo corrupt > "$SB/tarmode"; OUT="$(run_full)"; rm -f "$SB/tarmode"
check "$(has "$OUT" "FAIL  backup of $DATA failed (tar exit 1; the archive it wrote cannot be read back). It said:")" "yes" "reported"
check "$(has "$OUT" 'STOP: NO-GO: the backup did not complete')" "yes" "NO-GO"

echo "-- 9f. a copy changed on its way out of the container (same size): FAIL, NO-GO"
seed_db seed; touch "$SB/mangle"; OUT="$(run_full)"; rm -f "$SB/mangle"
check "$(has "$OUT" 'FAIL  backup of plugin.sqlite3 failed: the copy that arrived is not the copy checked')" "yes" "the sha256 comparison refuses it"
SIZES="$(printf '%s' "$OUT" | grep -o 'plugin.sqlite3 failed: the copy that arrived is not the copy checked ([0-9]* of [0-9]* bytes' | grep -o '[0-9]* of [0-9]*')"
check "$([ -n "$SIZES" ] && [ "${SIZES%% of *}" = "${SIZES##* of }" ] && echo yes || echo no)" "yes" "though the size matched ($SIZES) — only the sha256 tells"
check "$(has "$OUT" 'STOP: NO-GO: the backup did not complete')" "yes" "NO-GO"

echo "== 10. weakened copies of the backup must each fail =="
mutant_full() {   # $1 label · $2 old · $3 new · $4 setup: plain | garbage | fatal | corrupt | mangle · $5 what must be seen broken
  local m="$R/scripts/.deploy-5.18.45.mutant-$$-${#MUTS[@]}.sh"; MUTS+=("$m")
  python3 - "$DEPLOY" "$m" "$2" "$3" <<'PY' || { echo "  FAIL mutant edit did not apply: $1"; FAILN=$((FAILN+1)); return; }
import sys; src, dst, old, new = sys.argv[1:5]; s = open(src).read()
assert s.count(old) == 1, 'anchor not unique: ' + old
open(dst, 'w').write(s.replace(old, new))
PY
  seed_db seed; write_config "sk-test"
  case "$4" in garbage) garbage_db ;; fatal|corrupt) echo "$4" > "$SB/tarmode" ;; mangle) touch "$SB/mangle" ;; esac
  local o; o="$(SCRIPT="$m" run_full)"; local caught=no bk; bk="$(bkdir)"
  case "$5" in
    dbintar)  [ "$(tarlist "$bk/$DD.tar.gz" | grep -cxF "$DD/plugin.sqlite3")" != "0" ] && caught=yes ;;
    rootcopy) [ "$(grep -c -- "^exec -u $OWNER ucrm php -d display_errors=stderr" "$SB/docker.log")" = "0" ] \
                && [ "$(grep -c -- 'php -d display_errors=stderr' "$SB/docker.log")" != "0" ] && caught=yes ;;
    tarquiet) [ "$(has "$o" 'file changed as we read it')" = "no" ] && caught=yes ;;
    nogo)     [ "$(has "$o" 'STOP: NO-GO: the backup did not complete')" = "no" ] && caught=yes ;;
  esac
  check "$caught" "yes" "caught: $1"
  rm -f "$SB/tarmode" "$SB/mangle"; [ "$4" = garbage ] && restore_db; rm -f "$m"
}
mutant_full "the live databases archived by tar as well" 'ex+=("--exclude=$(basename "$d")/$db$s")' ':' plain dbintar
mutant_full "the copies made as root, not as the database's owner" \
  'docker exec -u "$owner" "$CONTAINER" php -d display_errors=stderr' 'docker exec "$CONTAINER" php -d display_errors=stderr' plain rootcopy
mutant_full "tar's words thrown away again" '2> "$BK/$n.tar.err"; rc=$?' '2>/dev/null; rc=$?' plain tarquiet
mutant_full "a database that cannot be copied only noted" 'bad "backup of $db failed (exit $rc)' 'note "backup of $db failed (exit $rc)' garbage nogo
mutant_full "tar's fatal exit accepted" 'if [ "$rc" -le 1 ] && tar -tzf' 'if [ "$rc" -le 2 ] && tar -tzf' fatal nogo
mutant_full "the archive not read back" 'tar -tzf "$BK/$n.tar.gz" >/dev/null 2>&1; then' 'true; then' corrupt nogo
mutant_full "the copy not compared with the one checked" '[ "$got_h" = "${3:-}" ]; then' 'true; then' mangle nogo

echo; echo "REHEARSAL: $PASS ok, $FAILN failed"
[ "$FAILN" = "0" ]
