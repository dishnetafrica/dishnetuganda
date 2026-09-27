#!/usr/bin/env bash
# Rehearse scripts/deploy-5.18.52.sh — the deploy, its checks, and the ROLLBACK — before the operator runs any of it
# (docs/44 §16.14). Everything is real except the container and the public address:
#   · the repository is a CLONE of this one: the deploy and the rollback run the real `git checkout` and the real
#     scripts/deploy-hybrid.sh, and this checkout is never touched;
#   · the "container" is a directory: a fake docker maps /data to it, runs `exec` here (this machine's PHP standing in
#     for the server's), answers `inspect`, and `logs` from a file written with docker's own timestamps;
#   · the installed plugin starts as 5.18.51 exactly (git archive 240f2f9), its data built by 5.18.51's own store, and
#     Uganda selected as on the server: currency_code UGX in the configuration vault, no tenant_profile anywhere;
#   · the public address and the :8443 door (TLS, self-signed) are stand-ins answering stage V as the live pages do —
#     and the public address opens the INSTALLED plugin's store on every request, as the real public.php does, so the
#     installed code applies its own migrations: 075 arrives through the plugin's own MigrationRunner, at stage V.
# DEPLOY and ROLLBACK are typed through a pseudo-terminal, as the operator types them.
#
#   bash scripts/harness/deploy-5.18.52/rehearse.sh            REHEARSE_KEEP=<dir> keeps every run's full output
#
# It needs the script pinned to this checkout's plugin commit, port 8443 free, openssl and python3; it refuses to run
# where the server could be.
set -u
R="$(cd "$(dirname "$0")/../../.." && pwd)"
for f in /data/ucrm /opt/dishnet /var/run/docker.sock; do
  [ -e "$f" ] && { echo "refusing: $f exists — this looks like the server, and this rehearsal must never run there"; exit 2; }
done
BASE=240f2f9; RA_BASE=e076632; OLDER=125fa0c; BRANCH_FILE=scripts/deploy-5.18.52.sh; P=dishnet-hybrid-sudan
checkout_state() { { git -C "$R" rev-parse HEAD; git -C "$R" status --porcelain --untracked-files=no; git -C "$R" diff HEAD; } | sha256sum | cut -c1-16; }
CHECKOUT0="$(checkout_state)"   # this checkout as the rehearsal found it (edited or not): it must be the same at the end
SB="$(mktemp -d)"; PIDS=()
cleanup() { for p in "${PIDS[@]}"; do kill "$p" 2>/dev/null; done; rm -rf "$SB"; }
trap cleanup EXIT
unset DN_VAULT_FILE DN_DATA_DIR
export NO_PROXY=127.0.0.1,localhost no_proxy=127.0.0.1,localhost
PASS=0; FAILN=0
check() { if [ "$1" = "$2" ]; then PASS=$((PASS+1)); echo "  ok   $3"; else FAILN=$((FAILN+1)); echo "  FAIL $3 (got '$1', want '$2')"; fi; }
has()   { grep -qF -- "$2" <<<"$1" && echo yes || echo no; }
cnt()   { grep -cF -- "$2" <<<"$1"; }
fails() { grep -cE '^  FAIL  ' <<<"$1"; }

# ── The clone ────────────────────────────────────────────────────────────────
git clone -q --no-hardlinks "$R" "$SB/repo" || { echo "cannot clone $R"; exit 1; }
REPO="$SB/repo"; DEPLOY="$REPO/$BRANCH_FILE"; BRANCH="$(git -C "$REPO" symbolic-ref --short HEAD)"
if git -C "$R" ls-files --error-unmatch "$BRANCH_FILE" >/dev/null 2>&1; then
  cmp -s "$R/$BRANCH_FILE" "$DEPLOY" || { echo "refusing: $BRANCH_FILE differs from the committed one — commit it, then rehearse"; exit 2; }
  WHICH="the committed script"
else cp "$R/$BRANCH_FILE" "$DEPLOY"; WHICH="the working copy (not yet committed; untracked in the clone)"; fi
ORIG_HEAD="$(git -C "$REPO" rev-parse HEAD)"
PIN="$(sed -n 's/^EXPECTED_PLUGIN_COMMIT="\([^"]*\)".*/\1/p' "$DEPLOY")"
echo "== 0. what is rehearsed =="
echo "  script    $WHICH, sha256 $(sha256sum "$DEPLOY" | cut -c1-16)"
echo "  clone     $BRANCH at $(git -C "$REPO" rev-parse --short HEAD)"
check "$PIN" "$(git -C "$REPO" log -1 --format=%h -- $P)" "the script is pinned to the clone's plugin commit ($PIN)"
check "$(python3 -c 'import json,sys; print(json.load(open(sys.argv[1]))["information"]["version"])' "$REPO/$P/manifest.json")" "5.18.52" "the plugin is 5.18.52"
dl() { git -C "$REPO" diff --no-renames --name-only --diff-filter="$1" "$2" "$3" -- $P; }
CHANGED="$(dl AM "$BASE" "$PIN")"
N_CH="$(grep -c . <<<"$CHANGED")"; N_AD="$(dl A "$BASE" "$PIN" | grep -c .)"; N_MO=$((N_CH - N_AD)); N_DEL="$(dl D "$BASE" "$PIN" | grep -c .)"
N_RUN="$(grep '\.php$' <<<"$CHANGED" | grep -vc "^$P/tests/")"; N_TST="$(grep '\.php$' <<<"$CHANGED" | grep -c "^$P/tests/")"
N_RA=0; for f in $(dl AM "$RA_BASE" "$BASE"); do
  case "$f" in */manifest.json) continue;; esac; grep -qxF "$f" <<<"$CHANGED" && continue
  git -C "$REPO" cat-file -e "$PIN:$f" 2>/dev/null && N_RA=$((N_RA+1))
done
echo "  5.18.52   $N_CH files: $N_MO changed, $N_AD added ($N_RUN PHP run on the server, $N_TST test PHP) · $N_DEL removed or renamed · Release A and 5.18.51's other files: $N_RA"
check "$(dl D "$BASE" "$PIN" | sed "s#^$P/##")" "tests/test_job_notifications_off.php" "control: the one file 5.18.52 removes is the renamed day test — its old name stays on the server"
(ss -ltn 2>/dev/null || netstat -ltn 2>/dev/null) | grep -q ':8443 ' && { echo "refusing: port 8443 is in use — the :8443 door stands in there"; exit 2; }
# §16.9: the deploy command in the script's header carries no rollback — pasted together, the shell runs both.
HEADER_CMD="$(sed -n '/^# Run as root/,/^# The rollback is a separate command/p' "$DEPLOY")"
check "$(grep -c 'deploy-5.18.52.sh 2>&1 | tee' <<<"$HEADER_CMD")$(grep -cE -- '--rollback|git checkout [0-9a-f]{7}' <<<"$HEADER_CMD")" "10" \
  "the header's deploy command stands alone: no rollback and no checkout of another commit in its block (docs/44 §16.9)"

# ── The container: 5.18.51 installed, its data beside it ─────────────────────
MOUNT="$SB/mount"; PLUGINS="$MOUNT/ucrm/data/plugins"; PD="$PLUGINS/$P"; DATA="$PLUGINS/.$P-data"
VAULT="$PLUGINS/.dishnet-sudan.vault.json"
mkdir -p "$PD" "$DATA" "$SB/web" "$SB/bin" "$SB/out"

# ── The stand-ins: the public address and the :8443 door ─────────────────────
free_port() { python3 -c 'import socket;s=socket.socket();s.bind(("127.0.0.1",0));print(s.getsockname()[1]);s.close()'; }
WPORT="$(free_port)"; PLUGIN_BASE="http://127.0.0.1:$WPORT/public.php"
cat > "$SB/web/public.php" <<PHP
<?php
// The public address as the live pages answered stage V: the words each check reads, nothing else — and, first, the
// installed plugin's own store opened as every real request opens it, so that the installed code's MigrationRunner
// applies what that code ships. \$SB/no-store switches that off (a rehearsal of R9 with the migration gone).
if (!is_file('$SB/no-store') && is_file('$PD/lib/SqliteStore.php')) {
  try {
    foreach (['bootstrap_data', 'StoreInterface', 'JsonStore', 'SqliteStore'] as \$l) require_once '$PD/lib/' . \$l . '.php';
    \$GLOBALS['_PLUGIN_ROOT'] = '$PD';
    SqliteStore::create('$DATA');
  } catch (Throwable \$e) { error_log('stand-in: ' . \$e->getMessage()); }
}
\$page = \$_GET['page'] ?? '';
if (\$page === 'customer_login' && is_file('$SB/redirect-login')) { header('Location: ?page=terms', true, 302); exit; }
if (\$page === 'customer_portal') { header('Location: ?page=customer_login', true, 302); exit; }
if (\$page === 'dashboard') {   // RetailerAuth::requireLogin(): no session → ?page=login
  if (is_file('$SB/dashboard-open')) { echo '<html><body>Job #1</body></html>'; exit; }
  header('Location: ?page=login', true, 302); exit;
}
if (\$page === 'api') { header('Content-Type: application/json'); echo '{"status":"success","data":{"tos_version":"1.1","privacy_version":"1.1"}}'; exit; }
if (\$page === 'terms') { echo '<html><body>DishNet is registered in Uganda (Reg. No. 80046255496181). The laws of the Republic of Uganda apply; the courts of Uganda decide. https://wa.me/256705993348 · Kampala, Uganda · v1.1</body></html>'; exit; }
if (\$page === 'privacy') { echo '<html><body>The Uganda Communications Commission and other Ugandan authorities. Sign in with your WhatsApp number or your e-mail address.</body></html>'; exit; }
echo '<html><body>Sign in</body></html>';
PHP
php -S "127.0.0.1:$WPORT" -t "$SB/web" >/dev/null 2>&1 & PIDS+=($!)
openssl req -x509 -newkey rsa:2048 -nodes -keyout "$SB/k.pem" -out "$SB/c.pem" -days 1 -subj /CN=127.0.0.1 >/dev/null 2>&1
cat > "$SB/door.py" <<'PY'
# The :8443 door: 302 to the public address for a page, 200 for page=api, for a POST and for the native wrapper.
import http.server, ssl, sys, urllib.parse
base = sys.argv[1]
API = b'{"status":"success","data":{"tos_version":"1.1","privacy_version":"1.1"}}'
class H(http.server.BaseHTTPRequestHandler):
    def log_message(self, *a): pass
    def send(self, code, body=b'', loc=None):
        self.send_response(code)
        if loc: self.send_header('Location', loc)
        self.send_header('Content-Length', str(len(body))); self.end_headers(); self.wfile.write(body)
    def do_GET(self):
        u = urllib.parse.urlparse(self.path); q = urllib.parse.parse_qs(u.query)
        if (q.get('page') or [''])[0] == 'api': return self.send(200, API)
        if self.headers.get('X-DishNet-Client') == 'android': return self.send(200, b'<html>app</html>')
        return self.send(302, loc=base + '?' + u.query)
    def do_POST(self):
        self.rfile.read(int(self.headers.get('Content-Length') or 0)); return self.send(200, API)
srv = http.server.HTTPServer(('127.0.0.1', 8443), H)
ctx = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER); ctx.load_cert_chain(sys.argv[2], sys.argv[3])
srv.socket = ctx.wrap_socket(srv.socket, server_side=True)
srv.serve_forever()
PY
python3 "$SB/door.py" "$PLUGIN_BASE" "$SB/c.pem" "$SB/k.pem" >/dev/null 2>&1 & PIDS+=($!)
for i in $(seq 1 50); do curl -sk --noproxy '*' "https://127.0.0.1:8443/public.php?page=api" | grep -q tos_version && break; sleep 0.1; done
for i in $(seq 1 50); do curl -s --noproxy '*' "$PLUGIN_BASE?page=api" | grep -q tos_version && break; sleep 0.1; done

install_base() {   # the installed plugin exactly as 5.18.51 — nothing 5.18.52 added is left
  find "$PD" -mindepth 1 -maxdepth 1 ! -name ucrm.json -exec rm -rf {} +
  git -C "$REPO" archive "$BASE:$P" | tar -x -C "$PD"
  printf '%s\n' "$BASE" > "$PD/.deployed-commit"
}
printf '{"pluginDataDir":"/data/ucrm/data/plugins/.%s-data","ucrmPublicUrl":"http://127.0.0.1:1/crm"}' "$P" > "$PD/ucrm.json"
vault() { printf '{"config":{"currency_code":"%s"}}' "$1" > "$VAULT"; }
seed_data() {      # the plugin's databases, written by 5.18.51's own store; the Message Log already holds job messages
  rm -f "$DATA"/plugin.sqlite3* "$DATA"/dishnet.sqlite* "$DATA"/config.json "$DATA"/kyc_config.json "$DATA"/migration.log
  php -r '
    foreach (["bootstrap_data", "StoreInterface", "JsonStore", "SqliteStore"] as $l) require_once $argv[1] . "/lib/$l.php";
    $s = SqliteStore::create($argv[2]);
    $s->save("kyc_config.json", ["crm_base_url" => "http://127.0.0.1:1", "company_name" => "DishNet Sandbox"]);
    $s->save("retailers.json", [
      ["id" => 1, "name" => "Sandbox Admin", "email" => "admin@example.test", "phone" => "+256700000110", "role" => "admin", "is_admin" => true, "is_active" => true, "ucrm_user_id" => 1000],
      ["id" => 2, "name" => "Sandbox Tech", "email" => "tech@example.test", "phone" => "0700000111", "role" => "support", "is_active" => true, "ucrm_user_id" => 1099,
       "ucrm_link" => ["user_id" => 1099, "email" => "tech@example.test", "verified_at" => "2026-09-27T20:30:00+00:00", "verified_by" => 1]],
      ["id" => 3, "name" => "Sandbox Lead", "email" => "lead@example.test", "phone" => "+256700000112", "role" => "support_leader", "is_active" => true],
      ["id" => 4, "name" => "Sandbox Accountant", "email" => "acct@example.test", "phone" => "0700000113", "role" => "accountant", "is_active" => true],
      ["id" => 5, "name" => "Sandbox Old Id", "email" => "old@example.test", "phone" => "+256700000115", "role" => "support", "is_active" => true, "ucrm_user_id" => 1105],
      ["id" => 6, "name" => "Sandbox FTTH", "email" => "ftth@example.test", "phone" => "+256700000116", "role" => "support_engineer", "is_active" => true, "ftth_crm_client_id" => 1099],
      ["id" => 7, "name" => "Sandbox Gone", "email" => "gone@example.test", "phone" => "+256700000117", "role" => "support", "is_active" => false, "ucrm_user_id" => 1101],
    ]);
    $t = time() - 300;
    $s->save("master_schedule.json", ["event_processor" => ["last_run" => $t, "last_run_at" => "sandbox", "duration_ms" => 5]]);
    $p = $s->getPdo();
    $p->exec("CREATE TABLE IF NOT EXISTS notification_audit_log (id INTEGER PRIMARY KEY AUTOINCREMENT, sender TEXT, event TEXT, phone TEXT, preview TEXT, success INTEGER NOT NULL DEFAULT 0, http_code INTEGER, error TEXT, sent_at TEXT NOT NULL DEFAULT (datetime(\x27now\x27)))");
    foreach (["ops_scheduling_job_assigned", "ops_scheduling_rescheduled", "job_assigned", "ops_scheduling_job_complete"] as $e)
      $p->prepare("INSERT INTO notification_audit_log (sender, event, phone, preview, success) VALUES (\x27sandbox\x27, ?, \x27256700000111\x27, \x27sandbox\x27, 1)")->execute([$e]);
  ' "$PD" "$DATA" >/dev/null
  php -r '$p = new PDO("sqlite:" . $argv[1]); $p->exec("PRAGMA journal_mode=WAL"); $p->exec("CREATE TABLE wa_messages(id INTEGER PRIMARY KEY, body TEXT)");
    for ($i = 0; $i < 50; $i++) $p->exec("INSERT INTO wa_messages(body) VALUES (\x27hello $i\x27)");' "$DATA/dishnet.sqlite"
}
sq() { php -r '$p = new PDO("sqlite:" . $argv[1]); $p->exec($argv[2]);' "$DATA/plugin.sqlite3" "$1"; }
sqv() { php -r '$p = new PDO("sqlite:" . $argv[1]); echo $p->query($argv[2])->fetchColumn();' "$DATA/plugin.sqlite3" "$1"; }
nal_max() { sqv "SELECT coalesce(max(id), 0) FROM notification_audit_log"; }
plant_event() { sq "INSERT INTO notification_audit_log (sender, event, phone, preview, success) VALUES ('sandbox', '$1', '256700000111', 'x', 1)"; }
tables_075() { sqv "SELECT count(*) FROM sqlite_master WHERE type = 'table' AND name IN ('job_notify_state', 'job_notify_events')"; }
mig_075() { sqv "SELECT count(*) FROM _migrations WHERE filename = '075_job_notifications.sql'"; }
migrations() { php -r '$p = new PDO("sqlite:" . $argv[1]); echo implode(",", $p->query("SELECT filename FROM _migrations ORDER BY id")->fetchAll(PDO::FETCH_COLUMN));' "$DATA/plugin.sqlite3"; }
drop_075() { sq "DROP TABLE IF EXISTS job_notify_state; DROP TABLE IF EXISTS job_notify_events; DELETE FROM _migrations WHERE filename = '075_job_notifications.sql'"; }
NOW="$(date -u +%s)"
iso() { date -u -d "@$1" +%Y-%m-%dT%H:%M:%SZ; }
# The deploy's record, as a deploy writes it, moved to a chosen moment: $1 epoch of the deploy, $2 its Message Log mark.
state_at() {
  local snap; snap="$(sed -n 's/^SNAP_BEFORE=//p' "$SB/out/state-5.18.52.env" 2>/dev/null | head -1)"
  printf 'DEPLOYED_AT=%s\nNAL_MARK=%s\nSNAP_BEFORE=%s\nFROM_COMMIT=%s\n' "$(iso "$1")" "$2" "$snap" "$BASE" > "$SB/out/state-5.18.52.env"
}
# The container log, with docker's own timestamp in front of every line.
FLOCK_TEXT="NOTICE: PHP message: PHP Fatal error:  Uncaught TypeError: flock(): supplied resource is not a valid stream resource in /data/ucrm/data/plugins/$P/cron/master.php:83"
logline() { printf '%s.123456789Z %s\n' "$(date -u -d "@$1" +%Y-%m-%dT%H:%M:%S)" "$2" >> "$SB/container.log"; }
base_log() {       # before the deploy at $1 (default: now): the old lock fatal of 5.18.50's days, and ordinary noise
  local a="${1:-$(date -u +%s)}"
  : > "$SB/container.log"
  logline $((a - 7200)) "$FLOCK_TEXT"; logline $((a - 5400)) "$FLOCK_TEXT"; logline $((a - 60)) 'NOTICE: fpm is running, pid 1'
}
# Every table's rows but the master's own schedule, the migrations ledger and the notifier's two tables (075 adds those
# three kinds of row by design, and each is asserted on its own), and the vault — what "no data changed" means here.
data_digest() {
  { php -r '$p = new PDO("sqlite:" . $argv[1]); $skip = ["master_schedule", "_migrations", "job_notify_state", "job_notify_events"];
      foreach ($p->query("SELECT name FROM sqlite_master WHERE type = \x27table\x27 ORDER BY name")->fetchAll(PDO::FETCH_COLUMN) as $t) {
        if (in_array($t, $skip, true)) continue;
        $q = $t === "sqlite_sequence" ? "SELECT * FROM sqlite_sequence WHERE name NOT IN (\x27" . implode("\x27,\x27", $skip) . "\x27) ORDER BY name" : "SELECT * FROM [$t]";
        echo $t, ":", json_encode($p->query($q)->fetchAll(PDO::FETCH_NUM)), "\n"; }' "$DATA/plugin.sqlite3"; cat "$VAULT"; } | sha256sum | cut -c1-16
}
installed_digest() {   # $1 a commit: the number of files 5.18.52 touches that are NOT installed as that commit has them
  local c="$1" f rel bad=0
  for f in $CHANGED; do
    rel="${f#$P/}"
    if git -C "$REPO" cat-file -e "$c:$f" 2>/dev/null; then
      [ "$(git -C "$REPO" show "$c:$f" | sha256sum | cut -c1-64)" = "$(sha256sum "$PD/$rel" 2>/dev/null | cut -c1-64)" ] || bad=$((bad+1))
    fi
  done
  echo "$bad"
}
n_added_present() { local n=0 f; for f in $(dl A "$BASE" "$PIN"); do [ -f "$PD/${f#$P/}" ] && n=$((n+1)); done; echo "$n"; }
live() { tail -n1 "$PD/.deployed-commit" 2>/dev/null | tr -cd '0-9a-f'; }
clone_ref() { git -C "$REPO" symbolic-ref -q --short HEAD || echo "DETACHED at $(git -C "$REPO" rev-parse --short HEAD)"; }

# A person edits one staff account (its phone) — used while a deploy runs.
cat > "$SB/edit_staff.php" <<'PHP'
<?php $p = new PDO('sqlite:' . $argv[1] . '/plugin.sqlite3');
$p->prepare("UPDATE retailers SET data = json_set(data, '$.phone', '+256700000199') WHERE id = ?")->execute([(int)$argv[2]]);
PHP

# ── The fake docker ──────────────────────────────────────────────────────────
cat > "$SB/bin/docker" <<SH
#!/usr/bin/env bash
printf '%s\n' "\$*" >> "$SB/docker.log"
case "\$1" in
  inspect) echo "$MOUNT"; exit 0 ;;
  logs) cat "$SB/container.log" 2>/dev/null; exit 0 ;;
  exec) shift ;;
  *) echo "fake docker: \$1 is not supported" >&2; exit 1 ;;
esac
while [ \$# -gt 0 ]; do case "\$1" in -i) shift ;; -u) shift 2 ;; *) break ;; esac; done
shift   # the container's name
args=(); for a in "\$@"; do case "\$a" in /data/*) args+=("$MOUNT/\${a#/data/}") ;; *) args+=("\$a") ;; esac; done
# $SB/edit-during-deploy: once the new build is installed, a person edits staff account 3 — as deploy-hybrid.sh reads
# the commit back through the container.
if [ -e "$SB/edit-during-deploy" ] && [ "\${args[0]}" = "cat" ] && [ "\${args[1]:-}" = "$PD/.deployed-commit" ] \\
   && [ "\$(tail -n1 "$PD/.deployed-commit" | tr -cd 0-9a-f)" = "$PIN" ]; then
  rm -f "$SB/edit-during-deploy"; php "$SB/edit_staff.php" "$DATA" 3
fi
# $SB/mangle: a database copy is changed on its way out of the container, keeping its size
if [ -e "$SB/mangle" ] && [ "\${args[0]}" = "php" ] && grep -q 'VACUUM INTO' <<<"\${args[*]}"; then
  "\${args[@]}" | LC_ALL=C sed '1s/^SQLite format 3/SQLite format X/'; exit "\${PIPESTATUS[0]}"
fi
exec "\${args[@]}"
SH
chmod +x "$SB/bin/docker"

run() {   # [--answer WORD] [--script FILE], in any order, then the script's own options — through a pseudo-terminal
  local answer="" script="$DEPLOY"
  while :; do case "${1:-}" in --answer) answer="$2"; shift 2 ;; --script) script="$2"; shift 2 ;; *) break ;; esac; done
  : > "$SB/docker.log"
  local n; n=$(( $(cat "$SB/runs" 2>/dev/null || echo 0) + 1 )); echo "$n" > "$SB/runs"
  local cmd; cmd="$(printf '%q ' env PATH="$SB/bin:$PATH" DNB_OUT="$SB/out" GUARD_SECONDS=0 bash "$script" --plugin-base "$PLUGIN_BASE" "$@")"
  local o; o="$(printf '%s\n' "$answer" | SHELL=/bin/bash script -qec "$cmd" /dev/null 2>&1 | tr -d '\r')"
  if [ -n "${REHEARSE_KEEP:-}" ]; then
    local label; label="$(printf '%s-%s%s' "${answer:-none}" "$(basename "$script" .sh)" "$(printf '%s' "$*" | tr -c 'A-Za-z0-9-' '_')")"
    printf '%s\n' "$o" > "$REHEARSE_KEEP/run-$(printf '%02d' "$n")-$label.txt"
  fi
  printf '%s' "$o"
}
deploy_hybrid() { ( cd "$REPO" && PATH="$SB/bin:$PATH" bash scripts/deploy-hybrid.sh >/dev/null 2>&1 ); }   # put the clone's HEAD in place
open_store() { curl -s --noproxy '*' -o /dev/null "$PLUGIN_BASE?page=customer_login"; }   # one request: the installed code opens its store
fresh() { rm -rf "$SB/out"; mkdir -p "$SB/out"; base_log; rm -f "$SB/redirect-login" "$SB/mangle" "$SB/edit-during-deploy" "$SB/no-store" "$SB/dashboard-open"; }
backups() { ls -d "$SB/out"/backup-* 2>/dev/null | wc -l | tr -d ' '; }

install_base; seed_data; vault UGX; fresh
open_store
D0="$(data_digest)"; MIG0="$(migrations)"
check "$(tables_075)$(mig_075)$(grep -c 075 <<<"$MIG0")" "000" "control: 5.18.51 installed and its store opened through the stand-in: no 075, no notifier table"
open_store; check "$(data_digest)" "$D0" "control: opening the store again changes no row the digest covers"

echo; echo "== 1. NO-GO before anything changes =="
vault SSP; OUT="$(run --answer DEPLOY)"
check "$(has "$OUT" 'STOP: NO-GO: the installed plugin does not read Uganda from both configuration sources (store south-sudan, files south-sudan)')" "yes" \
  "1a the tenant reads South Sudan: NO-GO, both sources named"
check "$(live)$(backups)" "${BASE}0" "…nothing deployed, no backup taken"
vault UGX
printf '%s\n' "$OLDER" > "$PD/.deployed-commit"; OUT="$(run --answer DEPLOY)"
check "$(has "$OUT" "STOP: NO-GO: the container serves $OLDER; 5.18.52 was built and tested against $BASE (5.18.51) — deploy 5.18.51 first (scripts/deploy-5.18.51.sh) and send its log")" "yes" \
  "1b the server still runs 5.18.50: NO-GO — 5.18.51 goes first"
check "$(live)$(backups)" "${OLDER}0" "…nothing deployed, no backup taken"
printf '%s\n' "$BASE" > "$PD/.deployed-commit"
echo "// edit" >> "$REPO/$P/lib/JobNotifier.php"; OUT="$(run --answer DEPLOY)"
check "$(has "$OUT" 'STOP: the checkout has 1 locally edited tracked files')" "yes" "1c an edited file in the checkout: stop"
git -C "$REPO" checkout -q -- "$P/lib/JobNotifier.php"
touch "$SB/mangle"; OUT="$(run --answer DEPLOY)"; rm -f "$SB/mangle"
check "$(has "$OUT" 'FAIL  backup of plugin.sqlite3 failed: the copy that arrived is not the copy checked')" "yes" "1d a backup copy that arrives changed is caught"
check "$(has "$OUT" 'STOP: NO-GO: the backup or a before-check did not complete')$(live)$(has "$OUT" 'Type DEPLOY')" "yes${BASE}no" "…NO-GO, before anyone is asked to type DEPLOY"
fresh; OUT="$(run --answer no)"
check "$(has "$OUT" 'GO — evidence recorded')$(has "$OUT" 'STOP: not confirmed')" "yesyes" "1e anything but DEPLOY: the backup is taken, then it stops"
check "$(live)$([ -e "$SB/out/state-5.18.52.env" ] && echo state || echo nostate)" "${BASE}nostate" "…nothing deployed, nothing recorded as a deploy"
sed 's/^EXPECTED_PLUGIN_COMMIT="[^"]*"/EXPECTED_PLUGIN_COMMIT="__PLUGIN_COMMIT__"/' "$DEPLOY" > "$REPO/scripts/.unpinned.sh"
fresh; OUT="$(run --answer DEPLOY --script "$REPO/scripts/.unpinned.sh")"; rm -f "$REPO/scripts/.unpinned.sh"
check "$(has "$OUT" 'STOP: this copy of the script is not pinned to a reviewed commit')$(live)$(backups)" "yes${BASE}0" "1f a copy still carrying the placeholder pin: stops before anything is read"
check "$(data_digest)$(tables_075)" "${D0}0" "…and no data or configuration value changed in any of these runs; 075 not applied"

echo; echo "== 2. the syntax check under the server's PHP, on a build with a broken file =="
( cd "$REPO" && printf '<?php\nfunction broken( {\n' > $P/lib/JobNotifier.php && git -c user.name=h -c user.email=h@example.test commit -qam "broken runtime file" )
BROKEN="$(git -C "$REPO" log -1 --format=%h -- $P)"
sed "s/^EXPECTED_PLUGIN_COMMIT=\"$PIN\"/EXPECTED_PLUGIN_COMMIT=\"$BROKEN\"/" "$DEPLOY" > "$REPO/scripts/.broken-pin.sh"
fresh; OUT="$(run --answer DEPLOY --script "$REPO/scripts/.broken-pin.sh")"
check "$(has "$OUT" 'STOP: NO-GO: PHP ')$(has "$OUT" 'in the container rejects: lib/JobNotifier.php')" "yesyes" "2a the notifier rejected by the server's PHP: NO-GO, named"
check "$(live)$(backups)" "${BASE}0" "…nothing deployed, before the backup"
git -C "$REPO" reset -q --hard "$ORIG_HEAD"
( cd "$REPO" && printf '<?php\nfunction broken( {\n' > $P/tests/test_job_notifier.php && git -c user.name=h -c user.email=h@example.test commit -qam "broken test file" )
BROKEN="$(git -C "$REPO" log -1 --format=%h -- $P)"
sed "s/^EXPECTED_PLUGIN_COMMIT=\"$PIN\"/EXPECTED_PLUGIN_COMMIT=\"$BROKEN\"/" "$DEPLOY" > "$REPO/scripts/.broken-pin.sh"
fresh; OUT="$(run --answer no --script "$REPO/scripts/.broken-pin.sh")"
check "$(has "$OUT" 'rejects test files (they are copied, never run on the server): tests/test_job_notifier.php')$(has "$OUT" 'STOP: NO-GO: PHP')" "yesno" \
  "2b a test file it rejects: a note, not a NO-GO"
git -C "$REPO" reset -q --hard "$ORIG_HEAD"; rm -f "$REPO/scripts/.broken-pin.sh"
check "$(git -C "$REPO" rev-parse HEAD)$(git -C "$REPO" status --porcelain --untracked-files=no | wc -l | tr -d ' ')" "${ORIG_HEAD}0" "control: the clone is back at its commit, clean"

echo; echo "== 3. the deploy, as the operator runs it =="
fresh; OUT="$(run --answer DEPLOY)"
check "$(fails "$OUT")" "0" "no FAIL line"
check "$(has "$OUT" '5.18.52 (deploy): PASSED')" "yes" "PASSED"
for l in "ok    A1 the installed plugin reads Uganda from both configuration sources — the job notifier will be on" \
         "$N_CH files differ from $BASE: $N_MO changed, $N_AD added, $N_DEL removed" \
         "note  files removed or renamed in 5.18.52 stay on the server under their old name (deploy-hybrid.sh never deletes): tests/test_job_notifications_off.php" \
         "in the container accepts all $N_RUN changed PHP files that run on the server (and $N_TST test files)" \
         "ok    A3 staff accounts read: 7 accounts, 6 active; of the 5 active accounts that take jobs, 3 hold a uCRM user id (1 through a verified link, 2 stored the old way) and 1 only an FTTH id" \
         "ok    A4 the Message Log holds 4 rows; its last is #4" \
         "migration 075 has not run here: no job_notify_state, no job_notify_events (as expected on 5.18.51)" \
         "— one consistent copy (VACUUM INTO" "ok    backed up the installed plugin (5.18.51, $BASE)" "ok    backed up the configuration vault" \
         "GO — evidence recorded" "The rollback command is printed at the end of this log, on its own." "ok    container serves $PIN" \
         "ok    V1 the sign-in page on the public address answers 200 with zero redirects (no loop)" \
         "ok    V4 no fatal or parse error of $P in the container log since" \
         "ok    R1 all $N_CH files 5.18.52 changes are installed exactly as $PIN has them ($N_AD of them new)" \
         "ok    R1 the installed manifest says 5.18.52" \
         "ok    R1 the $N_RA other files of Release A and 5.18.51 are installed exactly as $PIN has them" \
         "ok    R2 the installed StaffJobsGate reads Uganda from both configuration sources: the job notifier is on (store on, files on)" \
         "ok    R3 every staff account is as it was at stage A of this run" \
         "ok    R4 the old ＋ New Job, Bulk Dispatch and Reschedule messages stay off since row #4 (0)" \
         "release B's job messages since row #4: none (nobody created or changed a job)" \
         "ok    R5 the master cron's job_assign entry is still commented out (path A stays off)" \
         "ok    R6 the job notifier is wired in: New Job, Bulk Dispatch, Reschedule, Accept, uCRM's job.add/job.edit/job.delete, the sign-in return" \
         "ok    R6 the old \"No WhatsApp message is sent for jobs yet\" is gone from the screen" \
         "note  R7 1 of 5 active accounts that take jobs have a verified uCRM link: only they receive job messages. The other 4 get none" \
         "ok    R8 the installed master.php keeps 5.18.51's lock guard (is_resource)" \
         "ok    R9 migration 075 is applied: job_notify_state holds 0 job(s), job_notify_events 0 row(s)" \
         "ok    R10 a job link opened signed out → 302 → the staff sign-in page, and no job shown"; do
  check "$(has "$OUT" "$l")" "yes" "3: ${l:0:118}"
done
check "$(has "$OUT" 'installs        the checkout as it is')$(has "$OUT" 'for the documented deploy')" "yesno" \
  "3: the checkout is at the pinned commit: installed as it is, no checkout of another commit"
check "$(cnt "$OUT" 'ok    backed up ')" "6" "six backups: two databases, the data directory, the plugin's own data/, the installed plugin, the vault"
check "$(live)$(installed_digest "$PIN")$(n_added_present)" "${PIN}0${N_AD}" "the container serves $PIN: every file as the commit has it, the new ones present"
check "$(data_digest)" "$D0" "no data and no configuration value changed"
check "$(migrations)" "$MIG0,075_job_notifications.sql" "the migrations ledger gained exactly 075, applied by the installed code at stage V's first request"
check "$(tables_075)$(sqv 'SELECT count(*) FROM job_notify_state')$(sqv 'SELECT count(*) FROM job_notify_events')" "200" "…its two tables exist, and hold nothing: the deploy sent nothing"
check "$(grep -c 'OK: 075_job_notifications.sql' "$DATA/migration.log")" "1" "…the plugin's migration log says so, once"
check "$(grep -c "cd $REPO && bash scripts/deploy-5.18.52.sh --rollback" <<<"$OUT")" "1" "the rollback command is printed once, on its own line"
check "$(awk '/PASSED\. Send this LOG FILE back/ {p=1} p && /deploy-5\.18\.52\.sh --rollback/ {print "after"; exit}' <<<"$OUT")" "after" \
  "…after the verdict, at the end of the log, never in the block the operator pasted"
BK="$(ls -d "$SB/out"/backup-* | tail -1)"
check "$(tar -xzOf "$BK/plugin-installed-5.18.51.tar.gz" $P/manifest.json | grep -c '"version": "5.18.51"')$(tar -tzf "$BK/plugin-installed-5.18.51.tar.gz" | grep -c 'lib/JobNotifier.php')" "10" \
  "the code backup is 5.18.51: without the notifier"
check "$(grep -c '^NAL_MARK=4$' "$SB/out/state-5.18.52.env")$(grep -c "^FROM_COMMIT=$BASE$" "$SB/out/state-5.18.52.env")" "11" "where the deploy started is recorded"
check "$(grep -cE 'example\.test|2567000001[0-9][0-9]|0700000[0-9]{3}|Sandbox ' <<<"$OUT")" "0" "the log carries no name, e-mail or phone number of a staff account"

echo; echo "== 4. later runs: --after-only, the deploy three hours ago =="
TD=$((NOW - 10800)); state_at "$TD" 4
fresh_log() { base_log "$TD"; }   # the old fault two hours before the deploy of three hours ago — never after it
fresh_log; OUT="$(run --after-only)"
check "$(fails "$OUT")$(has "$OUT" '5.18.52 (after): PASSED')" "0yes" "4a three hours on, nothing planted: PASSED — the lock fatal from before the deploy is not counted"
check "$(has "$OUT" 'deploy-5.18.52.sh --after-only   re-measures R3, R4 and R9 since this deploy')" "yes" "…and the summary says what a later run re-measures"
check "$(has "$OUT" 'migration 075 recorded 1×; job_notify_state 0 rows, job_notify_events 0 rows (5.18.52 is installed)')" "yes" "…and stage A reads the notifier's record as 5.18.52's own"
logline $((NOW - 1800)) "$FLOCK_TEXT"; OUT="$(run --after-only)"; fresh_log
check "$(has "$OUT" "FAIL  V4 1 fatal line(s) of $P since")" "yes" "4b the master's lock fatal after the deploy: V4 fails — 5.18.51's fix is the baseline now"
echo "$(date -u -d "@$((NOW - 600))" +%Y-%m-%dT%H:%M:%S).5Z [27-Sep-2026] PHP Fatal error:  Uncaught Error in /data/ucrm/data/plugins/$P/lib/JobNotifier.php:12" >> "$SB/container.log"
OUT="$(run --after-only)"; fresh_log
check "$(has "$OUT" "FAIL  V4 1 fatal line(s) of $P since")$(has "$OUT" 'lib/JobNotifier.php:12')" "yesyes" "4c a fatal of the notifier: V4 fails, and shows the line"
plant_event ops_scheduling_job_assigned; OUT="$(run --after-only)"
check "$(has "$OUT" 'FAIL  R4 1 message(s) from the old job paths since row #4 — only the job notifier may send on Uganda:')$(has "$OUT" 'ops_scheduling_job_assigned ×1')" "yesyes" \
  "4d a message from the old New Job path: R4 fails, naming the event"
sq "DELETE FROM notification_audit_log WHERE id > 4"
plant_event job_assigned; plant_event ops_job_accepted_self; OUT="$(run --after-only)"
check "$(has "$OUT" "note  R4 release B's job messages since row #4: 2 — someone created, changed or accepted a job:")$(fails "$OUT")" "yes0" \
  "4e release B's own messages (message 1, message 2): counted as a note, not a failure"
check "$(has "$OUT" 'job_assigned ×1')$(has "$OUT" 'ops_job_accepted_self ×1')" "yesyes" "…each named"
sq "DELETE FROM notification_audit_log WHERE id > 4"
sq "INSERT INTO job_notify_state (job_id, assignee_id, job_time, job_status, title) VALUES (901, 1099, '2026-09-28T09:00:00+0000', 0, 'x');
    INSERT INTO job_notify_events (job_id, event, message, source, staff_id, outcome) VALUES (901, 'assigned', 'assigned', 'my_jobs', 2, 'sent'), (901, 'accepted', 'accepted', 'accept', 2, 'sent')"
OUT="$(run --after-only)"
check "$(has "$OUT" 'ok    R9 migration 075 is applied: job_notify_state holds 1 job(s), job_notify_events 2 row(s)')$(has "$OUT" 'assigned / assigned / sent ×1')$(has "$OUT" 'accepted / accepted / sent ×1')" "yesyesyes" \
  "4f the notifier's record: counted, event / message / outcome only"
check "$(grep -cF "'x'" <<<"$OUT")" "0" "…no title of a job is printed"
sq "DELETE FROM job_notify_events; DELETE FROM job_notify_state"
touch "$SB/no-store"; drop_075; OUT="$(run --after-only)"
check "$(has "$OUT" 'FAIL  R9 migration 075 is not in place (recorded 0×; job_notify_state -1, job_notify_events -1)')" "yes" "4g the migration gone and not re-applied: R9 fails"
rm -f "$SB/no-store"; open_store
check "$(tables_075)$(mig_075)" "21" "control: the next request's store re-applies 075 by itself"
printf '\n// changed on the server\n' >> "$PD/lib/JobAccess.php"; OUT="$(run --after-only)"
check "$(has "$OUT" "FAIL  R1 Release A or 5.18.51 files that differ from $PIN: lib/JobAccess.php")" "yes" "4h a Release A file changed on the server: R1 fails, naming it"
git -C "$REPO" show "$PIN:$P/lib/JobAccess.php" > "$PD/lib/JobAccess.php"
printf '\n// changed on the server\n' >> "$PD/lib/JobNotifier.php"; OUT="$(run --after-only)"
check "$(has "$OUT" "FAIL  R1 installed files that differ from $PIN: lib/JobNotifier.php")" "yes" "4i a file 5.18.52 added, changed on the server: R1 fails, naming it"
git -C "$REPO" show "$PIN:$P/lib/JobNotifier.php" > "$PD/lib/JobNotifier.php"
sed -i "s/case 'job.edit':/case 'job.edited':/" "$PD/webhook.php"; OUT="$(run --after-only)"
check "$(has "$OUT" "FAIL  R6 missing from the installed files: webhook.php:case 'job.edit':")$(has "$OUT" 'FAIL  R1 installed files that differ')" "yesyes" \
  "4j uCRM's job.edit no longer handled: R6 names what is missing (and R1 the file)"
git -C "$REPO" show "$PIN:$P/webhook.php" > "$PD/webhook.php"
touch "$SB/dashboard-open"; OUT="$(run --after-only)"; rm -f "$SB/dashboard-open"
check "$(has "$OUT" 'FAIL  R10 a job link opened signed out → 200')" "yes" "4k a job link that shows a page signed out: R10 fails"
php "$SB/edit_staff.php" "$DATA" 2; OUT="$(run --after-only)"
check "$(has "$OUT" "note  R3 staff accounts changed since the deploy's stage A")$(has "$OUT" 'account id(s): 2')$(fails "$OUT")" "yesyes0" \
  "4l a staff account edited since the deploy (a link saved, an edit): a note naming its id, not a failure"
check "$(grep -c '+256700000199' <<<"$OUT")" "0" "…and the new number is not printed"
seed_data; open_store; state_at "$TD" 4
OUT="$(run --after-only)"
check "$(fails "$OUT")$(has "$OUT" 'PASSED')" "0yes" "control: with each planted fault removed, PASSED again"
D0="$(data_digest)"; MIG1="$(migrations)"   # the data was re-seeded above: the rollback is measured from here

echo; echo "== 5. the rollback: --rollback, typed ROLLBACK =="
fresh; OUT="$(run --answer ROLLBACK --rollback)"
check "$(fails "$OUT")$(has "$OUT" '5.18.52 (rollback): PASSED')" "0yes" "PASSED"
for l in "GO — evidence recorded" "checking out $BASE (5.18.51) for the documented deploy" "ok    container serves $BASE (5.18.51)" \
         "ok    RB1 all $N_MO files 5.18.52 had changed are back exactly as $BASE (5.18.51) has them" "ok    RB1 the installed manifest says 5.18.51" \
         "ok    RB2 the $N_AD file(s) 5.18.52 added are still on disk and inert: no 5.18.51 file loads them" \
         "ok    RB3 every staff account is as it was at stage A of this run" \
         "note  on 5.18.51 again: no job WhatsApp message on Uganda (M6). Migration 075's two tables and their rows stay"; do
  check "$(has "$OUT" "$l")" "yes" "5: ${l:0:118}"
done
check "$(cnt "$OUT" 'ok    backed up ')$(has "$OUT" 'ok    backed up the installed plugin (5.18.52, '"$PIN"')')" "6yes" "the same backup first — of 5.18.52's code"
check "$(live)$(installed_digest "$BASE")$(n_added_present)" "${BASE}0${N_AD}" "the container serves $BASE: every changed file as 5.18.51 has it; the added ones remain"
check "$(grep -c "JobNotifier\|JobReturn" "$PD/webhook.php" "$PD/public.php" "$PD/includes/api/api_scheduling.php" "$PD/includes/post/post_auth.php" | awk -F: '{n+=$2} END {print n}')" "0" \
  "the installed webhook, public.php, scheduling API and sign-in are 5.18.51's again"
check "$(clone_ref)$(git -C "$REPO" rev-parse HEAD)" "$BRANCH$ORIG_HEAD" "the checkout is back on $BRANCH, at the commit it was on"
open_store
check "$(data_digest)$(migrations)$(tables_075)" "$D0${MIG1}2" "no data changed; 075's ledger row and its tables stay, and 5.18.51's store leaves them alone"
OUT="$(run --answer ROLLBACK --rollback)"
check "$(has "$OUT" 'nothing to roll back; running the rollback checks')$(fails "$OUT")$(backups)" "yes01" "5b rolling back again: says so, checks, takes no second backup"
check "$(has "$OUT" 'migration 075 recorded 1×; job_notify_state 0 rows, job_notify_events 0 rows (left by an earlier 5.18.52)')" "yes" "…and reads the tables 5.18.52 left behind as such"

echo; echo "== 6. forward again, back by hand as printed, forward with an edit during the deploy =="
fresh; OUT="$(run --answer DEPLOY)"
check "$(fails "$OUT")$(live)$(has "$OUT" 'ok    R9 migration 075 is applied')" "0${PIN}yes" "6a deploy again after a rollback: PASSED, serving $PIN; 075 already there, not applied twice"
check "$(grep -c 'OK: 075_job_notifications.sql' "$DATA/migration.log")" "1" "…the migration log still says 075 once"
HAND="$(awk '/or by hand, if this script cannot run:/ {getline; sub(/^ +/, ""); print; exit}' <<<"$OUT")"
check "$HAND" "cd $REPO && git checkout $BASE && bash scripts/deploy-hybrid.sh && git checkout -" "the summary prints the rollback by hand"
( export PATH="$SB/bin:$PATH"; eval "$HAND" ) >/dev/null 2>&1
check "$(live)$(installed_digest "$BASE")$(clone_ref)" "${BASE}0$BRANCH" "6b that line, run as printed, puts 5.18.51 back and returns the checkout"
fresh; OUT="$(run --answer ROLLBACK --rollback)"
check "$(has "$OUT" 'nothing to roll back')$(fails "$OUT")" "yes0" "…and --rollback confirms it"
fresh; touch "$SB/edit-during-deploy"; OUT="$(run --answer DEPLOY)"
check "$(has "$OUT" 'FAIL  R3 staff accounts changed during the deploy — account id(s): 3')" "yes" "6c a staff account edited during the deploy: R3 fails, naming account 3"
check "$(fails "$OUT")" "1" "…the only failure"
check "$(grep -c '+256700000199' <<<"$OUT")" "0" "…and the new number is not printed"

echo; echo "== 7. stage V finds the public address redirecting after a deploy: it rolls back by itself =="
install_base; seed_data; open_store; D0="$(data_digest)"; fresh; touch "$SB/redirect-login"
OUT="$(run --answer DEPLOY)"; rm -f "$SB/redirect-login"
check "$(has "$OUT" 'FAIL  V1 the sign-in page on the public address → 200 after 1 redirect(s)')$(has "$OUT" "ROLLING BACK to $BASE")" "yesyes" "V1 fails and the rollback starts"
check "$(has "$OUT" "STOP: rolled back (the container serves $BASE)")" "yes" "…and the log says what now serves"
check "$(live)$(installed_digest "$BASE")$(clone_ref)" "${BASE}0$BRANCH" "5.18.51 is back, the checkout on $BRANCH"

echo; echo "== 7b. the branch has moved on: a later release is on it before this one is deployed =="
# A later release pushed to the same branch: its notifier broken — so installing or linting any of it would show —
# and its manifest at 5.18.53. After a pull the operator's checkout looks like this, and 5.18.52 must still go in by
# its hash.
later_commit() {
  ( cd "$REPO" && printf '<?php\nfunction later_release( {\n' > $P/lib/JobNotifier.php \
      && sed -i 's/"version": "5\.18\.52"/"version": "5.18.53"/' $P/manifest.json \
      && git -c user.name=h -c user.email=h@example.test commit -qam "a later release" )
}
install_base; seed_data; open_store; D0="$(data_digest)"; fresh
later_commit; LATER="$(git -C "$REPO" log -1 --format=%h -- $P)"; LATER_HEAD="$(git -C "$REPO" rev-parse HEAD)"
check "$(git -C "$REPO" show HEAD:$P/manifest.json | grep -c '"version": "5.18.53"')$([ "$LATER" != "$PIN" ] && echo ahead)" "1ahead" \
  "control: the clone's branch is ahead of $PIN, at a later plugin commit ($LATER) that says 5.18.53"
OUT="$(run --answer DEPLOY)"
check "$(fails "$OUT")$(has "$OUT" '5.18.52 (deploy): PASSED')" "0yes" "7b-1 the deploy with the branch ahead: PASSED"
for l in "installs        $PIN by its hash — the branch has moved on to $LATER since" \
         "plugin version  5.18.52   (expected 5.18.52)" \
         "in the container accepts all $N_RUN changed PHP files that run on the server" \
         "checking out $PIN (5.18.52) for the documented deploy" \
         "the checkout is back on $BRANCH" "ok    container serves $PIN" \
         "ok    R1 all $N_CH files 5.18.52 changes are installed exactly as $PIN has them"; do
  check "$(has "$OUT" "$l")" "yes" "7b-1: ${l:0:118}"
done
check "$(live)$(installed_digest "$PIN")$(grep -c 'later_release' "$PD/lib/JobNotifier.php")$(grep -c '"version": "5.18.53"' "$PD/manifest.json")" "${PIN}000" \
  "…the container serves $PIN: nothing of the later release is installed"
check "$(clone_ref)$(git -C "$REPO" rev-parse HEAD)$(git -C "$REPO" status --porcelain --untracked-files=no | wc -l | tr -d ' ')" "$BRANCH${LATER_HEAD}0" \
  "…the checkout is back on $BRANCH at the later commit, clean"
check "$(data_digest)" "$D0" "…no data changed"
OUT="$(run --after-only)"
check "$(fails "$OUT")$(has "$OUT" '5.18.52 (after): PASSED')" "0yes" "7b-2 --after-only with the branch ahead: PASSED"
OUT="$(run --answer ROLLBACK --rollback)"
check "$(fails "$OUT")$(live)$(clone_ref)$(git -C "$REPO" rev-parse HEAD)" "0$BASE$BRANCH$LATER_HEAD" \
  "7b-3 --rollback with the branch ahead: 5.18.51 back, the checkout on $BRANCH at the later commit"
# A checkout whose history does not hold 5.18.52 at all: refused before anything is read or changed.
cp "$DEPLOY" "$SB/elsewhere.sh"
git -C "$REPO" checkout -q -b elsewhere "$BASE"
( cd "$REPO" && printf '\n// elsewhere\n' >> $P/lib/JobAccess.php && git -c user.name=h -c user.email=h@example.test commit -qam elsewhere )
cp "$SB/elsewhere.sh" "$REPO/scripts/.elsewhere.sh"
fresh; OUT="$(run --answer DEPLOY --script "$REPO/scripts/.elsewhere.sh")"
check "$(has "$OUT" "and $PIN is not in its history")$(live)$(backups)" "yes${BASE}0" \
  "7b-4 a checkout without $PIN in its history: refused, nothing deployed, no backup"
rm -f "$REPO/scripts/.elsewhere.sh"; git -C "$REPO" checkout -q "$BRANCH"; git -C "$REPO" branch -q -D elsewhere
git -C "$REPO" reset -q --hard "$ORIG_HEAD"
check "$(git -C "$REPO" rev-parse HEAD)$(clone_ref)" "$ORIG_HEAD$BRANCH" "control: the clone is back on $BRANCH at its commit"

echo; echo "== 8. weakened copies of the script must each be caught =="
MN=0
mutant() {   # $1 label · $2 old · $3 new · $4 setup · $5 run arguments · $6 the test that it was caught
  MN=$((MN+1)); local m="$REPO/scripts/.mutant-$MN.sh" src="${SRC:-$DEPLOY}"
  if ! python3 - "$src" "$m" "$2" "$3" <<'PY'
import sys; src, dst, old, new = sys.argv[1:5]; s = open(src).read()
if s.count(old) != 1: sys.exit('anchor found %d times' % s.count(old))
open(dst, 'w').write(s.replace(old, new))
PY
  then FAILN=$((FAILN+1)); echo "  FAIL mutant edit did not apply: $1"; return; fi
  fresh; install_base; seed_data; vault UGX; open_store; git -C "$REPO" checkout -q "$BRANCH"
  eval "$4"
  local o; o="$(eval "run --script $m $5")"
  local caught=no; eval "$6" && caught=yes
  grep -q '^unknown option' <<<"$o" && caught="no (the weakened copy did not run: unknown option)"
  check "$caught" "yes" "caught: $1"
  rm -f "$m"; git -C "$REPO" checkout -q "$BRANCH" 2>/dev/null; git -C "$REPO" reset -q --hard "$ORIG_HEAD"
}
AFTER_SETUP='deploy_hybrid; open_store; state_at "$TD" 4; fresh_log'
mutant "the Uganda check removed" \
  'if [ "$T_STORE" = "uganda" ] && [ "$T_FILES" = "uganda" ]; then ok "A1' 'if true; then ok "A1' \
  'vault SSP' '--answer DEPLOY' '[ "$(live)" = "$PIN" ]'
mutant "a build over another live commit (5.18.50, before 5.18.51 is in)" \
  'elif [ "$LIVE_BEFORE" != "$BASELINE_COMMIT" ]; then' 'elif false; then' \
  'printf "%s\n" "$OLDER" > "$PD/.deployed-commit"' '--answer DEPLOY' '[ "$(live)" = "$PIN" ]'
mutant "DEPLOY never required" \
  '[ "$ANSWER" = "DEPLOY" ] || stop "not confirmed"' 'true' \
  ':' '--answer no' '[ "$(live)" = "$PIN" ]'
mutant "a failed backup ignored" \
  '[ "$FAIL" = "0" ] || stop "NO-GO: the backup or a before-check did not complete"' 'true' \
  'touch "$SB/mangle"' '--answer DEPLOY' '[ "$(live)" = "$PIN" ]'
mutant "the placeholder-pin guard removed" \
  'case "$EXPECTED_PLUGIN_COMMIT" in __*) stop "this copy of the script is not pinned to a reviewed commit — pull the branch again";; esac' ':' \
  'sed -i "s/^EXPECTED_PLUGIN_COMMIT=\"[^\"]*\"/EXPECTED_PLUGIN_COMMIT=\"__PLUGIN_COMMIT__\"/" "$m"' '--answer DEPLOY' '! grep -q "not pinned to a reviewed commit" <<<"$o"'
mutant "R3 blind to a staff account edited during the deploy" \
  'if [ "$(staff_digest "$REF_SNAP")" = "$(staff_digest "$SNAP_AFTER")" ]; then' 'if true; then' \
  'touch "$SB/edit-during-deploy"' '--answer DEPLOY' 'grep -q "ok    R3 every staff account" <<<"$o"'
mutant "R4 never counts the old job paths" \
  "N_OLD=\"\$(printf '%s\\n' \"\$EV\" | awk -v re=\"\$OLD_RE\" '\$1==\"EVENT\" && \$2 ~ re {n+=\$3} END {print n+0}')\"" 'N_OLD=0' \
  "$AFTER_SETUP"'; plant_event ops_scheduling_rescheduled' '--after-only' 'grep -q "ok    R4 the old" <<<"$o"'
mutant "R4 blind to the old Reschedule message" \
  "OLD_RE='^(ops_scheduling_job_assigned|ops_scheduling_rescheduled)'" "OLD_RE='^(ops_scheduling_job_assigned)'" \
  "$AFTER_SETUP"'; plant_event ops_scheduling_rescheduled' '--after-only' 'grep -q "ok    R4 the old" <<<"$o"'
mutant "R4 fails release B's own messages (the old rule, M6, kept)" \
  "OLD_RE='^(ops_scheduling_job_assigned|ops_scheduling_rescheduled)'" "OLD_RE='^(ops_scheduling_job_assigned|ops_scheduling_rescheduled|job_assigned)'" \
  "$AFTER_SETUP"'; plant_event job_assigned' '--after-only' 'grep -q "FAIL  R4" <<<"$o"'
mutant "R1 blind" \
  'if [ -n "$b" ] && [ "$a" = "$b" ]; then R_OK=$((R_OK+1)); else R_BAD="$R_BAD $rel"; fi' 'R_OK=$((R_OK+1))' \
  "$AFTER_SETUP"'; printf "\n// changed\n" >> "$PD/lib/JobNotifier.php"' '--after-only' 'grep -q "ok    R1 all" <<<"$o"'
mutant "R1 blind to Release A's files" \
  'if [ -n "$b" ] && [ "$a" = "$b" ]; then RA_OK=$((RA_OK+1)); else RA_BAD="$RA_BAD $rel"; fi' 'RA_OK=$((RA_OK+1))' \
  "$AFTER_SETUP"'; printf "\n// changed\n" >> "$PD/lib/JobAccess.php"' '--after-only' 'grep -q "ok    R1 the .* other files of Release A" <<<"$o"'
mutant "R1 compares a renamed-away file (the old day test) and fails a good deploy" \
  '  git -C "$REPO" cat-file -e "$EXPECTED_PLUGIN_COMMIT:$f" 2>/dev/null || continue' '  :' \
  ':' '--answer DEPLOY' 'grep -q "FAIL  R1 Release A or 5.18.51 files that differ from .*tests/test_job_notifications_off.php" <<<"$o"'
mutant "rename detection on (a file 5.18.52 added is paired with the removed one, and RB1 compares it with 5.18.51)" \
  'ADDED="$(git -C "$REPO" diff --no-renames --name-only --diff-filter=A' 'ADDED="$(git -C "$REPO" diff --find-renames=1% --name-only --diff-filter=A' \
  'deploy_hybrid; open_store' '--answer ROLLBACK --rollback' 'grep -q "FAIL  RB1" <<<"$o"'
mutant "R2 takes any answer as on" \
  'if [ "$G_STORE" = "on" ] && [ "$G_FILES" = "on" ]; then' 'if true; then' \
  "$AFTER_SETUP"'; vault SSP' '--after-only' 'grep -q "ok    R2 the installed StaffJobsGate" <<<"$o"'
mutant "V4 blind to the master's lock line (5.18.51's own window kept)" \
  'V4_LINES="$(log_since "$DEPLOY_STARTED" | grep -E '"'"'UNCAUGHT|FATAL|PHP Fatal|PHP Parse error'"'"' | grep -F -- "$PLUGIN" || true)"' \
  'V4_LINES="$(log_since "$DEPLOY_STARTED" | grep -E '"'"'UNCAUGHT|FATAL|PHP Fatal|PHP Parse error'"'"' | grep -F -- "$PLUGIN" | grep -vF -- "flock(): supplied resource" || true)"' \
  "$AFTER_SETUP"'; logline $((NOW - 1800)) "$FLOCK_TEXT"' '--after-only' 'grep -q "ok    V4" <<<"$o"'
mutant "R6 blind to a caller that is gone" \
  'grep -qF -- "${pair#*|}" "$DEST/${pair%%|*}" 2>/dev/null || R6_MISS="$R6_MISS ${pair%%|*}:${pair#*|}"' 'true' \
  "$AFTER_SETUP"'; sed -i "s/case '"'"'job.edit'"'"':/case '"'"'job.edited'"'"':/" "$PD/webhook.php"' '--after-only' 'grep -q "ok    R6 the job notifier is wired in" <<<"$o"'
mutant "R8 blind to a missing lock guard" \
  "if grep -qF 'if (is_resource(\$lockFp)) {' \"\$DEST/cron/master.php\" 2>/dev/null; then" 'if true; then' \
  "$AFTER_SETUP"'; sed -i "s/if (is_resource(\$lockFp)) {/if (true) {/" "$PD/cron/master.php"' '--after-only' 'grep -q "ok    R8 the installed master.php" <<<"$o"'
mutant "R9 takes a missing migration as applied" \
  'if [ "$JN_MIG" = "1" ] && [ -n "$JN_S" ] && [ "$JN_S" != "-1" ] && [ -n "$JN_E" ] && [ "$JN_E" != "-1" ]; then' 'if true; then' \
  "$AFTER_SETUP"'; touch "$SB/no-store"; drop_075' '--after-only' 'grep -q "ok    R9 migration 075 is applied" <<<"$o"'
mutant "R9 reads the ledger only (the tables dropped behind it)" \
  'if [ "$JN_MIG" = "1" ] && [ -n "$JN_S" ] && [ "$JN_S" != "-1" ] && [ -n "$JN_E" ] && [ "$JN_E" != "-1" ]; then' 'if [ "$JN_MIG" = "1" ]; then' \
  "$AFTER_SETUP"'; touch "$SB/no-store"; sq "DROP TABLE job_notify_events"' '--after-only' 'grep -q "ok    R9 migration 075 is applied" <<<"$o"'
mutant "R10 takes any answer for the job link" \
  'if [ "$HTTP_CODE" = "302" ] && [ "$HTTP_LOCATION" = "$PLUGIN_BASE?page=login" ]; then ok "R10' 'if true; then ok "R10' \
  "$AFTER_SETUP"'; touch "$SB/dashboard-open"' '--after-only' 'grep -q "ok    R10" <<<"$o"'
mutant "ROLLBACK never required" \
  '[ "$ANSWER" = "ROLLBACK" ] || stop "not confirmed"' 'true' \
  'deploy_hybrid' '--answer no --rollback' '[ "$(live)" = "$BASE" ]'
mutant "the rollback leaves the checkout detached" \
  'git -C "$REPO" checkout -q "$ref" || bad' 'true || bad' \
  'deploy_hybrid' '--answer ROLLBACK --rollback' '[ "$(clone_ref)" != "$BRANCH" ]'
mutant "RB1 blind" \
  'if [ -n "$b" ] && [ "$a" = "$b" ]; then RB_OK=$((RB_OK+1)); else RB_BAD="$RB_BAD $rel"; fi' 'RB_OK=$((RB_OK+1))' \
  'printf "\n// changed\n" >> "$PD/webhook.php"' '--rollback' 'grep -q "ok    RB1 all" <<<"$o"'
mutant "RB2 blind to a 5.18.51 file that loads a class 5.18.52 added" \
  '[ -z "$USED" ] && ok "RB2' 'true && ok "RB2' \
  'deploy_hybrid; printf "<?php\nrequire_once __DIR__ . \"/JobNotifier.php\"; new JobNotifier();\n" > "$PD/lib/Planted.php"' '--answer ROLLBACK --rollback' 'grep -q "ok    RB2" <<<"$o"'
# the syntax NO-GO, on a build with a broken runtime file (the copy is re-pinned to it first)
( cd "$REPO" && printf '<?php\nfunction broken( {\n' > $P/lib/JobNotifier.php && git -c user.name=h -c user.email=h@example.test commit -qam "broken runtime file" )
BROKEN="$(git -C "$REPO" log -1 --format=%h -- $P)"; BROKEN_HEAD="$(git -C "$REPO" rev-parse HEAD)"
sed "s/^EXPECTED_PLUGIN_COMMIT=\"$PIN\"/EXPECTED_PLUGIN_COMMIT=\"$BROKEN\"/" "$DEPLOY" > "$SB/broken-pin.sh"
git -C "$REPO" reset -q --hard "$ORIG_HEAD"
SRC="$SB/broken-pin.sh" mutant "the syntax NO-GO removed" \
  '[ -z "$L_BAD" ] || stop "NO-GO: PHP $PHPV in the container rejects:$L_BAD"' 'true' \
  'git -C "$REPO" reset -q --hard "$BROKEN_HEAD"' '--answer DEPLOY' '[ "$(live)" = "$BROKEN" ]'
check "$(git -C "$REPO" rev-parse HEAD)$(clone_ref)" "$ORIG_HEAD$BRANCH" "control: the clone is back on $BRANCH at its commit"
# the pin: with a later release on the branch, what is installed, what is linted, and where the checkout ends up
mutant "the deploy installs the branch tip instead of the pinned commit" \
  'PIN_CHECKOUT=1' 'PIN_CHECKOUT=' \
  'later_commit' '--answer DEPLOY' 'grep -q later_release "$PD/lib/JobNotifier.php"'
mutant "the checkout left on the pinned commit after the deploy" \
  'if git -C "$REPO" checkout -q "$DEPLOY_REF"; then echo "  the checkout is back on $DEPLOY_REF"' 'if true; then echo "  the checkout is back on $DEPLOY_REF"' \
  'later_commit' '--answer DEPLOY' '[ "$(clone_ref)" != "$BRANCH" ]'
# A broken build pinned while the branch already carries its fix: the check must read the pinned files.
fixed_on_branch() {
  ( cd "$REPO" && git show "$ORIG_HEAD:$P/lib/JobNotifier.php" > $P/lib/JobNotifier.php \
      && git -c user.name=h -c user.email=h@example.test commit -qam "fixed on the branch" )
}
git -C "$REPO" reset -q --hard "$BROKEN_HEAD"; fixed_on_branch; cp "$SB/broken-pin.sh" "$REPO/scripts/.broken-pin.sh"
fresh; install_base; seed_data; vault UGX; open_store; OUT="$(run --answer DEPLOY --script "$REPO/scripts/.broken-pin.sh")"
check "$(has "$OUT" 'STOP: NO-GO: PHP ')$(has "$OUT" 'in the container rejects: lib/JobNotifier.php')$(live)" "yesyes$BASE" \
  "8b pinned to a broken build while the branch has fixed it: the check reads the pinned files — NO-GO, nothing deployed"
rm -f "$REPO/scripts/.broken-pin.sh"; git -C "$REPO" reset -q --hard "$ORIG_HEAD"
SRC="$SB/broken-pin.sh" mutant "the syntax check reads the working tree, not the pinned commit" \
  'git -C "$REPO" show "$EXPECTED_PLUGIN_COMMIT:$f" > "/tmp/dnb_lint.$$" 2>/dev/null' 'cat "$REPO/$f" > "/tmp/dnb_lint.$$" 2>/dev/null' \
  'git -C "$REPO" reset -q --hard "$BROKEN_HEAD"; fixed_on_branch' '--answer DEPLOY' '[ "$(live)" = "$BROKEN" ]'
check "$(git -C "$REPO" rev-parse HEAD)$(clone_ref)" "$ORIG_HEAD$BRANCH" "control: the clone is back on $BRANCH at its commit"

echo; echo "== 9. what the rehearsal left behind =="
check "$(checkout_state)" "$CHECKOUT0" "this checkout is as the rehearsal found it: same commit, same tracked files"
check "$(ls "$REPO/scripts"/.mutant-* "$REPO/scripts"/.broken-pin.sh "$REPO/scripts"/.unpinned.sh 2>/dev/null | wc -l | tr -d ' ')" "0" "no weakened copy is left in the clone"

echo; echo "rehearsal: $PASS passed, $FAILN failed ($(cat "$SB/runs") runs of the script)"
[ "$FAILN" = "0" ]
