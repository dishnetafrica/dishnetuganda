#!/usr/bin/env bash
# Rehearse scripts/deploy-5.18.51.sh — the deploy, its checks, and the ROLLBACK — before the operator runs any of it
# (docs/44 §16.11). Everything is real except the container and the public address:
#   · the repository is a CLONE of this one: the deploy and the rollback run the real `git checkout` and the real
#     scripts/deploy-hybrid.sh, and this checkout is never touched;
#   · the "container" is a directory: a fake docker maps /data to it, runs `exec` here (this machine's PHP standing in
#     for the server's), answers `inspect`, and `logs` from a file written with docker's own timestamps;
#   · the installed plugin starts as 5.18.50 exactly (git archive 125fa0c), its data built by 5.18.50's own store, and
#     Uganda selected as on the server: currency_code UGX in the configuration vault, no tenant_profile anywhere;
#   · the container log holds the master's lock fatal as the server's did, at chosen moments around the deploy;
#   · the public address and the :8443 door (TLS, self-signed) are stand-ins answering stage V as the live pages do.
# DEPLOY and ROLLBACK are typed through a pseudo-terminal, as the operator types them.
#
#   bash scripts/harness/deploy-5.18.51/rehearse.sh            REHEARSE_KEEP=<dir> keeps every run's full output
#
# It needs the script pinned to this checkout's plugin commit, port 8443 free, openssl and python3; it refuses to run
# where the server could be.
set -u
R="$(cd "$(dirname "$0")/../../.." && pwd)"
for f in /data/ucrm /opt/dishnet /var/run/docker.sock; do
  [ -e "$f" ] && { echo "refusing: $f exists — this looks like the server, and this rehearsal must never run there"; exit 2; }
done
BASE=125fa0c; RA_BASE=e076632; BRANCH_FILE=scripts/deploy-5.18.51.sh
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
check "$PIN" "$(git -C "$REPO" log -1 --format=%h -- dishnet-hybrid-sudan)" "the script is pinned to the clone's plugin commit ($PIN)"
check "$(python3 -c 'import json,sys; print(json.load(open(sys.argv[1]))["information"]["version"])' "$REPO/dishnet-hybrid-sudan/manifest.json")" "5.18.51" "the plugin is 5.18.51"
N_CH="$(git -C "$REPO" diff --name-only --diff-filter=AMR "$BASE" "$PIN" -- dishnet-hybrid-sudan | grep -c .)"
N_AD="$(git -C "$REPO" diff --name-only --diff-filter=A "$BASE" "$PIN" -- dishnet-hybrid-sudan | grep -c .)"
N_MO=$((N_CH - N_AD))
N_RA="$(git -C "$REPO" diff --name-only --diff-filter=AMR "$RA_BASE" "$BASE" -- dishnet-hybrid-sudan | grep -vc 'manifest.json$')"
echo "  5.18.51   $N_CH files: $N_MO changed, $N_AD added · Release A's other files: $N_RA"
(ss -ltn 2>/dev/null || netstat -ltn 2>/dev/null) | grep -q ':8443 ' && { echo "refusing: port 8443 is in use — the :8443 door stands in there"; exit 2; }

# ── The stand-ins: the public address and the :8443 door ─────────────────────
free_port() { python3 -c 'import socket;s=socket.socket();s.bind(("127.0.0.1",0));print(s.getsockname()[1]);s.close()'; }
WPORT="$(free_port)"; PLUGIN_BASE="http://127.0.0.1:$WPORT/public.php"
mkdir -p "$SB/web" "$SB/bin" "$SB/out" "$SB/prev"
cat > "$SB/web/public.php" <<PHP
<?php
// The public address as the live pages answered stage V (27 Sep): the words each check reads, nothing else.
\$page = \$_GET['page'] ?? '';
if (\$page === 'customer_login' && is_file('$SB/redirect-login')) { header('Location: ?page=terms', true, 302); exit; }
if (\$page === 'customer_portal') { header('Location: ?page=customer_login', true, 302); exit; }
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

# ── The container: 5.18.50 installed, its data beside it ─────────────────────
MOUNT="$SB/mount"; PLUGINS="$MOUNT/ucrm/data/plugins"; PD="$PLUGINS/dishnet-hybrid-sudan"; DATA="$PLUGINS/.dishnet-hybrid-sudan-data"
VAULT="$PLUGINS/.dishnet-sudan.vault.json"
mkdir -p "$PD" "$DATA"
install_base() {   # the installed plugin exactly as 5.18.50 — nothing 5.18.51 added is left
  find "$PD" -mindepth 1 -maxdepth 1 ! -name ucrm.json -exec rm -rf {} +
  git -C "$REPO" archive "$BASE:dishnet-hybrid-sudan" | tar -x -C "$PD"
  printf '%s\n' "$BASE" > "$PD/.deployed-commit"
}
printf '{"pluginDataDir":"/data/ucrm/data/plugins/.dishnet-hybrid-sudan-data","ucrmPublicUrl":"http://127.0.0.1:1/crm"}' > "$PD/ucrm.json"
vault() { printf '{"config":{"currency_code":"%s"}}' "$1" > "$VAULT"; }
seed_data() {      # the plugin's databases, written by 5.18.50's own store; the Message Log already holds job messages
  rm -f "$DATA"/plugin.sqlite3* "$DATA"/dishnet.sqlite* "$DATA"/config.json "$DATA"/kyc_config.json
  php -r '
    foreach (["bootstrap_data", "StoreInterface", "JsonStore", "SqliteStore"] as $l) require_once $argv[1] . "/lib/$l.php";
    $s = SqliteStore::create($argv[2]);
    $s->save("kyc_config.json", ["crm_base_url" => "http://127.0.0.1:1", "company_name" => "DishNet Sandbox"]);
    $s->save("retailers.json", [
      ["id" => 1, "name" => "Sandbox Admin", "email" => "admin@example.test", "phone" => "+256700000110", "role" => "admin", "is_admin" => true, "is_active" => true, "ucrm_user_id" => 1000],
      ["id" => 2, "name" => "Sandbox Tech", "email" => "tech@example.test", "phone" => "0700000111", "role" => "support", "is_active" => true, "ucrm_user_id" => 1099],
      ["id" => 3, "name" => "Sandbox Lead", "email" => "lead@example.test", "phone" => "+256700000112", "role" => "support_leader", "is_active" => true],
      ["id" => 4, "name" => "Sandbox Accountant", "email" => "acct@example.test", "phone" => "0700000113", "role" => "accountant", "is_active" => true],
      ["id" => 5, "name" => "Sandbox Old Id", "email" => "old@example.test", "phone" => "+256700000115", "role" => "support", "is_active" => true, "ucrm_user_id" => 1105],
      ["id" => 6, "name" => "Sandbox FTTH", "email" => "ftth@example.test", "phone" => "+256700000116", "role" => "support_engineer", "is_active" => true, "ftth_crm_client_id" => 1099],
      ["id" => 7, "name" => "Sandbox Gone", "email" => "gone@example.test", "phone" => "+256700000117", "role" => "support", "is_active" => false, "ucrm_user_id" => 1101],
    ]);
    $t = time() - 300;
    $s->save("master_schedule.json", ["event_processor" => ["last_run" => $t, "last_run_at" => "sandbox", "duration_ms" => 5],
                                      "crm_sync" => ["last_run" => $t, "last_run_at" => "sandbox", "duration_ms" => 9]]);
    $p = $s->getPdo();
    $p->exec("CREATE TABLE IF NOT EXISTS notification_audit_log (id INTEGER PRIMARY KEY AUTOINCREMENT, sender TEXT, event TEXT, phone TEXT, preview TEXT, success INTEGER NOT NULL DEFAULT 0, http_code INTEGER, error TEXT, sent_at TEXT NOT NULL DEFAULT (datetime(\x27now\x27)))");
    foreach (["ops_scheduling_job_assigned", "ops_scheduling_rescheduled", "job_assigned", "ops_scheduling_job_complete"] as $e)
      $p->prepare("INSERT INTO notification_audit_log (sender, event, phone, preview, success) VALUES (\x27sandbox\x27, ?, \x27256700000111\x27, \x27sandbox\x27, 1)")->execute([$e]);
  ' "$PD" "$DATA" >/dev/null
  php -r '$p = new PDO("sqlite:" . $argv[1]); $p->exec("PRAGMA journal_mode=WAL"); $p->exec("CREATE TABLE wa_messages(id INTEGER PRIMARY KEY, body TEXT)");
    for ($i = 0; $i < 50; $i++) $p->exec("INSERT INTO wa_messages(body) VALUES (\x27hello $i\x27)");' "$DATA/dishnet.sqlite"
}
sq() { php -r '$p = new PDO("sqlite:" . $argv[1]); $p->exec($argv[2]);' "$DATA/plugin.sqlite3" "$1"; }
row_get() { php -r '$p = new PDO("sqlite:" . $argv[1]); $s = $p->prepare("SELECT data FROM [" . $argv[2] . "] WHERE id = ?"); $s->execute([(int)$argv[3]]); echo $s->fetchColumn();' "$DATA/plugin.sqlite3" "$1" "$2"; }
row_put() { php -r '$p = new PDO("sqlite:" . $argv[1]); $p->prepare("UPDATE [" . $argv[2] . "] SET data = ? WHERE id = ?")->execute([$argv[4], (int)$argv[3]]);' "$DATA/plugin.sqlite3" "$1" "$2" "$3"; }
nal_max() { php -r '$p = new PDO("sqlite:" . $argv[1]); echo (int)$p->query("SELECT coalesce(max(id), 0) FROM notification_audit_log")->fetchColumn();' "$DATA/plugin.sqlite3"; }
plant_assign() { sq "INSERT INTO notification_audit_log (sender, event, phone, preview, success) VALUES ('sandbox', 'job_assigned', '256700000111', 'x', 1)"; }
sched_at() {       # every job of the master's schedule last ran at epoch $1
  sq "UPDATE master_schedule SET data = json_set(json_set(data, '$.event_processor.last_run', $1), '$.crm_sync.last_run', $1) WHERE id = 0"
}
NOW="$(date -u +%s)"
iso() { date -u -d "@$1" +%Y-%m-%dT%H:%M:%SZ; }
# The deploy's record, as a deploy writes it, moved to a chosen moment: $1 epoch of the deploy, $2 its Message Log mark.
state_at() {
  local snap; snap="$(sed -n 's/^SNAP_BEFORE=//p' "$SB/out/state-5.18.51.env" 2>/dev/null | head -1)"
  printf 'DEPLOYED_AT=%s\nNAL_MARK=%s\nSNAP_BEFORE=%s\nFROM_COMMIT=%s\nFLOCK_BEFORE=%s\n' "$(iso "$1")" "$2" "$snap" "$BASE" 2 > "$SB/out/state-5.18.51.env"
}
prev_state() { printf 'DEPLOYED_AT=2026-09-27T20:08:44Z\nNAL_MARK=%s\nSNAP_BEFORE=\nFROM_COMMIT=e076632\n' "$1" > "$SB/prev/state-5.18.50.env"; }
# The container log, with docker's own timestamp in front of every line.
FLOCK_TEXT='NOTICE: PHP message: PHP Fatal error:  Uncaught TypeError: flock(): supplied resource is not a valid stream resource in /data/ucrm/data/plugins/dishnet-hybrid-sudan/cron/master.php:83'
logline() { printf '%s.123456789Z %s\n' "$(date -u -d "@$1" +%Y-%m-%dT%H:%M:%S)" "$2" >> "$SB/container.log"; }
base_log() {       # the hour before the deploy at $1 (default: now): the lock fatal twice, and the container's ordinary noise
  local a="${1:-$(date -u +%s)}"
  : > "$SB/container.log"
  logline $((a - 1800)) "$FLOCK_TEXT"; logline $((a - 900)) "$FLOCK_TEXT"; logline $(( $(date -u +%s) - 60 )) 'NOTICE: fpm is running, pid 1'
}
data_digest() {    # every table's rows but the master's own schedule, and the vault — what "no data changed" means here
  { php -r '$p = new PDO("sqlite:" . $argv[1]); foreach ($p->query("SELECT name FROM sqlite_master WHERE type = \x27table\x27 AND name <> \x27master_schedule\x27 ORDER BY name")->fetchAll(PDO::FETCH_COLUMN) as $t)
      echo $t, ":", json_encode($p->query("SELECT * FROM [$t]")->fetchAll(PDO::FETCH_NUM)), "\n";' "$DATA/plugin.sqlite3"; cat "$VAULT"; } | sha256sum | cut -c1-16
}
installed_digest() {   # $1 a commit: the number of files 5.18.51 touches that are NOT installed as that commit has them
  local c="$1" f rel bad=0
  for f in $(git -C "$REPO" diff --name-only --diff-filter=AMR "$BASE" "$PIN" -- dishnet-hybrid-sudan); do
    rel="${f#dishnet-hybrid-sudan/}"
    if git -C "$REPO" cat-file -e "$c:$f" 2>/dev/null; then
      [ "$(git -C "$REPO" show "$c:$f" | sha256sum | cut -c1-64)" = "$(sha256sum "$PD/$rel" 2>/dev/null | cut -c1-64)" ] || bad=$((bad+1))
    fi
  done
  echo "$bad"
}
n_added_present() { local n=0 f; for f in $(git -C "$REPO" diff --name-only --diff-filter=A "$BASE" "$PIN" -- dishnet-hybrid-sudan); do [ -f "$PD/${f#dishnet-hybrid-sudan/}" ] && n=$((n+1)); done; echo "$n"; }
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
  local cmd; cmd="$(printf '%q ' env PATH="$SB/bin:$PATH" DNB_OUT="$SB/out" DNB_PREV_STATE="$SB/prev/state-5.18.50.env" GUARD_SECONDS=0 bash "$script" --plugin-base "$PLUGIN_BASE" "$@")"
  local o; o="$(printf '%s\n' "$answer" | SHELL=/bin/bash script -qec "$cmd" /dev/null 2>&1 | tr -d '\r')"
  if [ -n "${REHEARSE_KEEP:-}" ]; then
    local label; label="$(printf '%s-%s%s' "${answer:-none}" "$(basename "$script" .sh)" "$(printf '%s' "$*" | tr -c 'A-Za-z0-9-' '_')")"
    printf '%s\n' "$o" > "$REHEARSE_KEEP/run-$(printf '%02d' "$n")-$label.txt"
  fi
  printf '%s' "$o"
}
deploy_hybrid() { ( cd "$REPO" && PATH="$SB/bin:$PATH" bash scripts/deploy-hybrid.sh >/dev/null 2>&1 ); }   # put the clone's HEAD in place
fresh() { rm -rf "$SB/out"; mkdir -p "$SB/out"; base_log; rm -f "$SB/redirect-login" "$SB/mangle" "$SB/edit-during-deploy"; prev_state 4; }
backups() { ls -d "$SB/out"/backup-* 2>/dev/null | wc -l | tr -d ' '; }

install_base; seed_data; vault UGX; fresh
D0="$(data_digest)"

echo; echo "== 1. NO-GO before anything changes =="
vault SSP; OUT="$(run --answer DEPLOY)"
check "$(has "$OUT" 'STOP: NO-GO: the installed plugin does not read Uganda from both configuration sources (store south-sudan, files south-sudan)')" "yes" \
  "1a the tenant reads South Sudan: NO-GO, both sources named"
check "$(live)$(backups)" "${BASE}0" "…nothing deployed, no backup taken"
vault UGX
printf '%s\n' e076632 > "$PD/.deployed-commit"; OUT="$(run --answer DEPLOY)"
check "$(has "$OUT" 'STOP: NO-GO: the container serves e076632; 5.18.51 was built and tested against 125fa0c (5.18.50)')" "yes" \
  "1b the server still runs 5.18.49: NO-GO — 5.18.51 goes only over 5.18.50"
printf '%s\n' "$BASE" > "$PD/.deployed-commit"
echo "// edit" >> "$REPO/dishnet-hybrid-sudan/cron/master.php"; OUT="$(run --answer DEPLOY)"
check "$(has "$OUT" 'STOP: the checkout has 1 locally edited tracked files')" "yes" "1c an edited file in the checkout: stop"
git -C "$REPO" checkout -q -- dishnet-hybrid-sudan/cron/master.php
touch "$SB/mangle"; OUT="$(run --answer DEPLOY)"; rm -f "$SB/mangle"
check "$(has "$OUT" 'FAIL  backup of plugin.sqlite3 failed: the copy that arrived is not the copy checked')" "yes" "1d a backup copy that arrives changed is caught"
check "$(has "$OUT" 'STOP: NO-GO: the backup or a before-check did not complete')$(live)$(has "$OUT" 'Type DEPLOY')" "yes${BASE}no" "…NO-GO, before anyone is asked to type DEPLOY"
fresh; OUT="$(run --answer no)"
check "$(has "$OUT" 'GO — evidence recorded')$(has "$OUT" 'STOP: not confirmed')" "yesyes" "1e anything but DEPLOY: the backup is taken, then it stops"
check "$(live)$([ -e "$SB/out/state-5.18.51.env" ] && echo state || echo nostate)" "${BASE}nostate" "…nothing deployed, nothing recorded as a deploy"
check "$(data_digest)" "$D0" "…and no data or configuration value changed in any of these runs"

echo; echo "== 2. the syntax check under the server's PHP, on a build with a broken file =="
( cd "$REPO" && printf '<?php\nfunction broken( {\n' > dishnet-hybrid-sudan/cron/master.php && git -c user.name=h -c user.email=h@example.test commit -qam "broken runtime file" )
BROKEN="$(git -C "$REPO" log -1 --format=%h -- dishnet-hybrid-sudan)"
sed "s/^EXPECTED_PLUGIN_COMMIT=\"$PIN\"/EXPECTED_PLUGIN_COMMIT=\"$BROKEN\"/" "$DEPLOY" > "$REPO/scripts/.broken-pin.sh"
fresh; OUT="$(run --answer DEPLOY --script "$REPO/scripts/.broken-pin.sh")"
check "$(has "$OUT" 'STOP: NO-GO: PHP ')$(has "$OUT" 'in the container rejects: cron/master.php')" "yesyes" "2a the master cron rejected by the server's PHP: NO-GO, named"
check "$(live)$(backups)" "${BASE}0" "…nothing deployed, before the backup"
git -C "$REPO" reset -q --hard "$ORIG_HEAD"
( cd "$REPO" && printf '<?php\nfunction broken( {\n' > dishnet-hybrid-sudan/tests/test_master_lock_release.php && git -c user.name=h -c user.email=h@example.test commit -qam "broken test file" )
BROKEN="$(git -C "$REPO" log -1 --format=%h -- dishnet-hybrid-sudan)"
sed "s/^EXPECTED_PLUGIN_COMMIT=\"$PIN\"/EXPECTED_PLUGIN_COMMIT=\"$BROKEN\"/" "$DEPLOY" > "$REPO/scripts/.broken-pin.sh"
fresh; OUT="$(run --answer no --script "$REPO/scripts/.broken-pin.sh")"
check "$(has "$OUT" 'rejects test files (they are copied, never run on the server): tests/test_master_lock_release.php')$(has "$OUT" 'STOP: NO-GO: PHP')" "yesno" \
  "2b a test file it rejects: a note, not a NO-GO"
git -C "$REPO" reset -q --hard "$ORIG_HEAD"; rm -f "$REPO/scripts/.broken-pin.sh"
check "$(git -C "$REPO" rev-parse HEAD)$(git -C "$REPO" status --porcelain --untracked-files=no | wc -l | tr -d ' ')" "${ORIG_HEAD}0" "control: the clone is back at its commit, clean"

echo; echo "== 3. the deploy, as the operator runs it (a master run begun on 5.18.50 ends just after it) =="
fresh; logline $(( $(date -u +%s) + 90 )) "$FLOCK_TEXT"; OUT="$(run --answer DEPLOY)"
check "$(fails "$OUT")" "0" "no FAIL line"
check "$(has "$OUT" '5.18.51 (deploy): PASSED')" "yes" "PASSED"
for l in "ok    A1 the installed plugin reads Uganda from both configuration sources" \
         "in the container accepts all 1 changed PHP files that run on the server (and 1 test files)" \
         "ok    A3 staff accounts read: 7 accounts, 6 active; of the 5 active accounts that take jobs, 3 hold a uCRM user id (0 through a verified link, 3 stored the old way) and 1 only an FTTH id" \
         "ok    A4 the Message Log holds 4 rows; its last is #4" \
         "the fault 5.18.51 removes: 2 line(s) of \"flock(): supplied resource is not a valid stream resource\" from dishnet-hybrid-sudan in the last hour" \
         "— one consistent copy (VACUUM INTO" "ok    backed up the installed plugin (5.18.50, $BASE)" "ok    backed up the configuration vault" \
         "GO — evidence recorded" "ok    container serves $PIN" \
         "ok    V1 the sign-in page on the public address answers 200 with zero redirects (no loop)" \
         "ok    V4 no fatal or parse error of dishnet-hybrid-sudan in the container log since" "besides the master's lock line (R8)" \
         "ok    R1 all $N_CH files 5.18.51 changes are installed exactly as $PIN has them ($N_AD of them new)" \
         "ok    R1 the installed manifest says 5.18.51" \
         "ok    R1 Release A's other $N_RA files are still installed exactly as $PIN has them" \
         "ok    R2 the installed StaffJobsGate reads Uganda from both configuration sources: Release A's rules are on (store on, files on)" \
         "ok    R3 every staff account is as it was at stage A of this run" \
         "ok    R4 no job-assignment WhatsApp message in the Message Log since row #4" \
         "ok    R4 and none since the 5.18.50 deploy (Message Log row #4): M6 has held across both deploys" \
         "ok    R5 the master cron's job_assign entry is still commented out" \
         "ok    R6 ＋ New Job says: \"No WhatsApp message is sent for jobs yet\"" \
         "note  R7 0 of 5 active accounts that take jobs have a verified uCRM link" \
         "ok    R8 the installed master.php releases its lock only while the handle is still open (is_resource)" \
         "note  R8 1 line(s) of the old fatal within 20 minutes of the deploy: a master run begun on 5.18.50 ends on its own code" \
         "note  R8 too early to judge the fatal line"; do
  check "$(has "$OUT" "$l")" "yes" "3: ${l:0:118}"
done
check "$(has "$OUT" 'installs        the checkout as it is')$(has "$OUT" 'for the documented deploy')" "yesno" \
  "3: the checkout is at the pinned commit: installed as it is, no checkout of another commit"
check "$(cnt "$OUT" 'ok    backed up ')" "6" "six backups: two databases, the data directory, the plugin's own data/, the installed plugin, the vault"
check "$(live)$(installed_digest "$PIN")$(n_added_present)" "${PIN}0${N_AD}" "the container serves $PIN: every file as the commit has it, the new test present"
check "$(data_digest)" "$D0" "no data and no configuration value changed"
BK="$(ls -d "$SB/out"/backup-* | tail -1)"
check "$(tar -xzOf "$BK/plugin-installed-5.18.50.tar.gz" dishnet-hybrid-sudan/manifest.json | grep -c '"version": "5.18.50"')$(tar -xzOf "$BK/plugin-installed-5.18.50.tar.gz" dishnet-hybrid-sudan/cron/master.php | grep -c 'is_resource(\$lockFp)')" "10" \
  "the code backup is 5.18.50: its master.php without the guard"
check "$(grep -c '^NAL_MARK=4$' "$SB/out/state-5.18.51.env")$(grep -c "^FROM_COMMIT=$BASE$" "$SB/out/state-5.18.51.env")$(grep -c '^FLOCK_BEFORE=2$' "$SB/out/state-5.18.51.env")" "111" \
  "where the deploy started is recorded, with the fault's count in the hour before"
check "$(grep -cE 'example\.test|2567000001[0-9][0-9]|0700000[0-9]{3}|Sandbox ' <<<"$OUT")" "0" "the log carries no name, e-mail or phone number of a staff account"

echo; echo "== 4. later runs: --after-only, the deploy three hours ago =="
TD=$((NOW - 10800)); state_at "$TD" 4
fresh_log() { base_log "$TD"; }   # the fault in the hour before the deploy of three hours ago — never after it
fresh_log; OUT="$(run --after-only)"
check "$(fails "$OUT")$(has "$OUT" '5.18.51 (after): PASSED')" "0yes" "4a three hours on, nothing planted: PASSED"
check "$(has "$OUT" "ok    R8 no \"flock(): supplied resource is not a valid stream resource\" line in the")$(has "$OUT" 'while 2 of the master'"'"'s jobs ran (in the hour before the deploy: 2 lines)')" "yesyes" \
  "…R8: no line after the grace, with the master's own schedule showing runs"
logline $((NOW - 1800)) "$FLOCK_TEXT"; OUT="$(run --after-only)"
check "$(has "$OUT" 'FAIL  R8 1 line(s) of "flock(): supplied resource is not a valid stream resource" after')$(has "$OUT" 'the fix is not working')" "yesyes" \
  "4b the fatal line half an hour ago: R8 fails"
check "$(has "$OUT" 'ok    V4 no fatal')" "yes" "…and V4 leaves that line to R8"
fresh_log; logline $((TD + 300)) "$FLOCK_TEXT"; OUT="$(run --after-only)"
check "$(has "$OUT" 'note  R8 1 line(s) of the old fatal within 20 minutes of the deploy')$(fails "$OUT")" "yes0" \
  "4c the line five minutes after the deploy (a run begun on 5.18.50): a note, not a failure"
fresh_log; sched_at $((TD - 60)); OUT="$(run --after-only)"
check "$(has "$OUT" 'note  R8 cannot judge: no "flock(): supplied resource is not a valid stream resource" line since')$(has "$OUT" 'ok    R8 no ')" "yesno" \
  "4d no line, but the master's schedule shows no run since: cannot judge — never ok"
sched_at $((NOW - 300))
state_at $((NOW - 1800)) 4; fresh_log; OUT="$(run --after-only)"
check "$(has "$OUT" 'note  R8 too early to judge the fatal line')$(fails "$OUT")" "yes0" "4e half an hour after the deploy: too early"
state_at "$TD" 4
echo "$(date -u -d "@$((NOW - 600))" +%Y-%m-%dT%H:%M:%S).5Z [27-Sep-2026] PHP Fatal error:  Uncaught Error in /data/ucrm/data/plugins/dishnet-hybrid-sudan/lib/JobAccess.php:12" >> "$SB/container.log"
OUT="$(run --after-only)"; fresh_log
check "$(has "$OUT" 'FAIL  V4 1 fatal line(s) of dishnet-hybrid-sudan')" "yes" "4f any other fatal of the plugin: V4 fails"
plant_assign; M="$(nal_max)"; state_at "$TD" "$M"; OUT="$(run --after-only)"
check "$(has "$OUT" "ok    R4 no job-assignment WhatsApp message in the Message Log since row #$M")$(has "$OUT" 'FAIL  R4 1 job-assignment message(s) since the 5.18.50 deploy (row #4)')" "yesyes" \
  "4g an assignment message between the two deploys: this deploy's R4 is clean, and the count since 5.18.50 fails"
sq "DELETE FROM notification_audit_log WHERE id > 4"; state_at "$TD" 4
rm -f "$SB/prev/state-5.18.50.env"; OUT="$(run --after-only)"; prev_state 4
check "$(has "$OUT" 'note  R4 no record of the 5.18.50 deploy at')$(fails "$OUT")" "yes0" "4h no record of the 5.18.50 deploy: said so, counted from this deploy"
printf '\n// changed on the server\n' >> "$PD/lib/JobAccess.php"; OUT="$(run --after-only)"
check "$(has "$OUT" "FAIL  R1 Release A files that differ from $PIN: lib/JobAccess.php")" "yes" "4i a Release A file changed on the server: R1 fails, naming it"
git -C "$REPO" show "$PIN:dishnet-hybrid-sudan/lib/JobAccess.php" > "$PD/lib/JobAccess.php"
OUT="$(run --after-only)"
check "$(fails "$OUT")$(has "$OUT" 'PASSED')$(has "$OUT" 'ok    R8 no ')" "0yesyes" "control: with each planted fault removed, PASSED again, R8 ok"
D0="$(data_digest)"   # the Message Log's sequence moved with the rows planted above: the rollback is measured from here

echo; echo "== 5. the rollback: --rollback, typed ROLLBACK =="
fresh; OUT="$(run --answer ROLLBACK --rollback)"
check "$(fails "$OUT")$(has "$OUT" '5.18.51 (rollback): PASSED')" "0yes" "PASSED"
for l in "GO — evidence recorded" "checking out $BASE (5.18.50) for the documented deploy" "ok    container serves $BASE (5.18.50)" \
         "ok    RB1 all $N_MO files 5.18.51 had changed are back exactly as $BASE (5.18.50) has them" "ok    RB1 the installed manifest says 5.18.50" \
         "ok    RB2 the $N_AD file(s) 5.18.51 added are still on disk and inert: no 5.18.50 file loads them" \
         "ok    RB3 every staff account is as it was at stage A of this run" \
         "note  on 5.18.50 again: the master's lock fatal returns"; do
  check "$(has "$OUT" "$l")" "yes" "5: ${l:0:118}"
done
check "$(cnt "$OUT" 'ok    backed up ')" "6" "the same backup first"
check "$(live)$(installed_digest "$BASE")$(n_added_present)" "${BASE}0${N_AD}" "the container serves $BASE: master.php and the manifest as 5.18.50 has them; the new test remains"
check "$(grep -c 'is_resource(\$lockFp)' "$PD/cron/master.php")" "0" "the installed master.php is 5.18.50's again"
check "$(clone_ref)$(git -C "$REPO" rev-parse HEAD)" "$BRANCH$ORIG_HEAD" "the checkout is back on $BRANCH, at the commit it was on"
check "$(data_digest)" "$D0" "no data changed"
OUT="$(run --answer ROLLBACK --rollback)"
check "$(has "$OUT" 'nothing to roll back; running the rollback checks')$(fails "$OUT")$(backups)" "yes01" "5b rolling back again: says so, checks, takes no second backup"

echo; echo "== 6. forward again, back by hand as printed, forward with an edit during the deploy =="
fresh; OUT="$(run --answer DEPLOY)"
check "$(fails "$OUT")$(live)" "0$PIN" "6a deploy again after a rollback: PASSED, serving $PIN"
HAND="$(sed -n 's/^      or by hand    //p' <<<"$OUT" | head -1)"
check "$HAND" "cd $REPO && git checkout $BASE && bash scripts/deploy-hybrid.sh && git checkout -" "the summary prints the rollback by hand"
( export PATH="$SB/bin:$PATH"; eval "$HAND" ) >/dev/null 2>&1
check "$(live)$(installed_digest "$BASE")$(clone_ref)" "${BASE}0$BRANCH" "6b that line, run as printed, puts 5.18.50 back and returns the checkout"
fresh; OUT="$(run --answer ROLLBACK --rollback)"
check "$(has "$OUT" 'nothing to roll back')$(fails "$OUT")" "yes0" "…and --rollback confirms it"
fresh; touch "$SB/edit-during-deploy"; OUT="$(run --answer DEPLOY)"
check "$(has "$OUT" 'FAIL  R3 staff accounts changed during the deploy — account id(s): 3')" "yes" "6c a staff account edited during the deploy: R3 fails, naming account 3"
check "$(fails "$OUT")" "1" "…the only failure"
check "$(grep -c '+256700000199' <<<"$OUT")" "0" "…and the new number is not printed"

echo; echo "== 7. stage V finds the public address redirecting after a deploy: it rolls back by itself =="
install_base; seed_data; D0="$(data_digest)"; fresh; touch "$SB/redirect-login"
OUT="$(run --answer DEPLOY)"; rm -f "$SB/redirect-login"
check "$(has "$OUT" 'FAIL  V1 the sign-in page on the public address → 200 after 1 redirect(s)')$(has "$OUT" "ROLLING BACK to $BASE")" "yesyes" "V1 fails and the rollback starts"
check "$(has "$OUT" "STOP: rolled back (the container serves $BASE)")" "yes" "…and the log says what now serves"
check "$(live)$(installed_digest "$BASE")$(clone_ref)" "${BASE}0$BRANCH" "5.18.50 is back, the checkout on $BRANCH"

echo; echo "== 7b. the branch has moved on: a later release is on it before this one is deployed =="
# A later release pushed to the same branch: its master.php broken — so installing or linting any of it would show —
# and its manifest at 5.18.52. After a pull the operator's checkout looks like this, and 5.18.51 must still go in by
# its hash.
later_commit() {
  ( cd "$REPO" && printf '<?php\nfunction later_release( {\n' > dishnet-hybrid-sudan/cron/master.php \
      && sed -i 's/"version": "5\.18\.51"/"version": "5.18.52"/' dishnet-hybrid-sudan/manifest.json \
      && git -c user.name=h -c user.email=h@example.test commit -qam "a later release" )
}
install_base; seed_data; D0="$(data_digest)"; fresh
later_commit; LATER="$(git -C "$REPO" log -1 --format=%h -- dishnet-hybrid-sudan)"; LATER_HEAD="$(git -C "$REPO" rev-parse HEAD)"
check "$(git -C "$REPO" show HEAD:dishnet-hybrid-sudan/manifest.json | grep -c '"version": "5.18.52"')$([ "$LATER" != "$PIN" ] && echo ahead)" "1ahead" \
  "control: the clone's branch is ahead of $PIN, at a later plugin commit ($LATER) that says 5.18.52"
OUT="$(run --answer DEPLOY)"
check "$(fails "$OUT")$(has "$OUT" '5.18.51 (deploy): PASSED')" "0yes" "7b-1 the deploy with the branch ahead: PASSED"
for l in "installs        $PIN by its hash — the branch has moved on to $LATER since" \
         "plugin version  5.18.51   (expected 5.18.51)" \
         "in the container accepts all 1 changed PHP files that run on the server" \
         "checking out $PIN (5.18.51) for the documented deploy" \
         "the checkout is back on $BRANCH" "ok    container serves $PIN" \
         "ok    R1 all $N_CH files 5.18.51 changes are installed exactly as $PIN has them"; do
  check "$(has "$OUT" "$l")" "yes" "7b-1: ${l:0:118}"
done
check "$(live)$(installed_digest "$PIN")$(grep -c 'later_release' "$PD/cron/master.php")$(grep -c '"version": "5.18.52"' "$PD/manifest.json")" "${PIN}000" \
  "…the container serves $PIN: nothing of the later release is installed"
check "$(clone_ref)$(git -C "$REPO" rev-parse HEAD)$(git -C "$REPO" status --porcelain --untracked-files=no | wc -l | tr -d ' ')" "$BRANCH${LATER_HEAD}0" \
  "…the checkout is back on $BRANCH at the later commit, clean"
check "$(data_digest)" "$D0" "…no data changed"
OUT="$(run --after-only)"
check "$(fails "$OUT")$(has "$OUT" '5.18.51 (after): PASSED')" "0yes" "7b-2 --after-only with the branch ahead: PASSED"
OUT="$(run --answer ROLLBACK --rollback)"
check "$(fails "$OUT")$(live)$(clone_ref)$(git -C "$REPO" rev-parse HEAD)" "0$BASE$BRANCH$LATER_HEAD" \
  "7b-3 --rollback with the branch ahead: 5.18.50 back, the checkout on $BRANCH at the later commit"
# A checkout whose history does not hold 5.18.51 at all: refused before anything is read or changed.
cp "$DEPLOY" "$SB/elsewhere.sh"
git -C "$REPO" checkout -q -b elsewhere "$BASE"
( cd "$REPO" && printf '\n// elsewhere\n' >> dishnet-hybrid-sudan/lib/JobAccess.php && git -c user.name=h -c user.email=h@example.test commit -qam elsewhere )
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
  fresh; install_base; seed_data; vault UGX; git -C "$REPO" checkout -q "$BRANCH"
  eval "$4"
  local o; o="$(eval "run --script $m $5")"
  local caught=no; eval "$6" && caught=yes
  grep -q '^unknown option' <<<"$o" && caught="no (the weakened copy did not run: unknown option)"
  check "$caught" "yes" "caught: $1"
  rm -f "$m"; git -C "$REPO" checkout -q "$BRANCH" 2>/dev/null; git -C "$REPO" reset -q --hard "$ORIG_HEAD"
}
AFTER_SETUP='deploy_hybrid; state_at "$TD" 4; fresh_log'
mutant "the Uganda check removed" \
  'if [ "$T_STORE" = "uganda" ] && [ "$T_FILES" = "uganda" ]; then ok "A1' 'if true; then ok "A1' \
  'vault SSP' '--answer DEPLOY' '[ "$(live)" = "$PIN" ]'
mutant "a build over another live commit" \
  'elif [ "$LIVE_BEFORE" != "$BASELINE_COMMIT" ]; then' 'elif false; then' \
  'printf "%s\n" e076632 > "$PD/.deployed-commit"' '--answer DEPLOY' '[ "$(live)" = "$PIN" ]'
mutant "DEPLOY never required" \
  '[ "$ANSWER" = "DEPLOY" ] || stop "not confirmed"' 'true' \
  ':' '--answer no' '[ "$(live)" = "$PIN" ]'
mutant "a failed backup ignored" \
  '[ "$FAIL" = "0" ] || stop "NO-GO: the backup or a before-check did not complete"' 'true' \
  'touch "$SB/mangle"' '--answer DEPLOY' '[ "$(live)" = "$PIN" ]'
mutant "R3 blind to a staff account edited during the deploy" \
  'if [ "$(staff_digest "$REF_SNAP")" = "$(staff_digest "$SNAP_AFTER")" ]; then' 'if true; then' \
  'touch "$SB/edit-during-deploy"' '--answer DEPLOY' 'grep -q "ok    R3 every staff account" <<<"$o"'
mutant "R4 never counts" \
  "N_ASSIGN=\"\$(printf '%s\\n' \"\$EV\" | awk -v re=\"\$ASSIGN_RE\" '\$1==\"EVENT\" && \$2 ~ re {n+=\$3} END {print n+0}')\"" 'N_ASSIGN=0' \
  "$AFTER_SETUP"'; plant_assign' '--after-only' 'grep -q "ok    R4 no job-assignment" <<<"$o"'
mutant "R4 blind across the two deploys" \
  "N2=\"\$(printf '%s\\n' \"\$EV2\" | awk -v re=\"\$ASSIGN_RE\" '\$1==\"EVENT\" && \$2 ~ re {n+=\$3} END {print n+0}')\"" 'N2=0' \
  "$AFTER_SETUP"'; plant_assign; state_at "$TD" "$(nal_max)"' '--after-only' 'grep -q "ok    R4 and none since the 5.18.50 deploy" <<<"$o"'
mutant "R1 blind" \
  'if [ -n "$b" ] && [ "$a" = "$b" ]; then R_OK=$((R_OK+1)); else R_BAD="$R_BAD $rel"; fi' 'R_OK=$((R_OK+1))' \
  "$AFTER_SETUP"'; printf "\n// changed\n" >> "$PD/cron/master.php"' '--after-only' 'grep -q "ok    R1 all" <<<"$o"'
mutant "R1 blind to Release A's files" \
  'if [ -n "$b" ] && [ "$a" = "$b" ]; then RA_OK=$((RA_OK+1)); else RA_BAD="$RA_BAD $rel"; fi' 'RA_OK=$((RA_OK+1))' \
  "$AFTER_SETUP"'; printf "\n// changed\n" >> "$PD/lib/JobAccess.php"' '--after-only' 'grep -q "ok    R1 Release A'"'"'s other" <<<"$o"'
mutant "R2 takes any answer as on" \
  'if [ "$G_STORE" = "on" ] && [ "$G_FILES" = "on" ]; then' 'if true; then' \
  "$AFTER_SETUP"'; vault SSP' '--after-only' 'grep -q "ok    R2 the installed StaffJobsGate" <<<"$o"'
mutant "V4 counts the master's lock line (a run begun on 5.18.50 would fail the deploy)" \
  '| grep -vF -- "$FLOCK_SIG" || true)"' '|| true)"' \
  'logline $(( $(date -u +%s) + 90 )) "$FLOCK_TEXT"' '--answer DEPLOY' 'grep -q "FAIL  V4" <<<"$o"'
mutant "R8 without the grace" \
  'R8_FROM_E=$((R8_AT_E + R8_GRACE))' 'R8_FROM_E=$R8_AT_E' \
  "$AFTER_SETUP"'; logline $((TD + 300)) "$FLOCK_TEXT"' '--after-only' 'grep -q "FAIL  R8" <<<"$o"'
mutant "R8 never fails" \
  'if [ "${R8_LATE:-0}" != "0" ]; then' 'if false; then' \
  "$AFTER_SETUP"'; logline $((NOW - 1800)) "$FLOCK_TEXT"' '--after-only' '! grep -q "FAIL  R8" <<<"$o"'
mutant "R8 judges without proof that the master ran" \
  'elif [ -z "$R8_RUNS" ] || [ "$R8_RUNS" = "0" ]; then' 'elif false; then' \
  "$AFTER_SETUP"'; sched_at $((TD - 60))' '--after-only' 'grep -q "ok    R8 no " <<<"$o"'
mutant "R8 judges before an hour of runs" \
  'elif [ "$R8_MIN" -lt 60 ]; then' 'elif false; then' \
  'deploy_hybrid; state_at $((NOW - 1800)) 4; fresh_log' '--after-only' 'grep -q "ok    R8 no " <<<"$o"'
mutant "R8 blind to a missing guard" \
  "if grep -qF 'if (is_resource(\$lockFp)) {' \"\$DEST/cron/master.php\" 2>/dev/null; then" 'if true; then' \
  "$AFTER_SETUP"'; sed -i "s/if (is_resource(\$lockFp)) {/if (true) {/" "$PD/cron/master.php"' '--after-only' 'grep -q "ok    R8 the installed master.php" <<<"$o"'
mutant "ROLLBACK never required" \
  '[ "$ANSWER" = "ROLLBACK" ] || stop "not confirmed"' 'true' \
  'deploy_hybrid' '--answer no --rollback' '[ "$(live)" = "$BASE" ]'
mutant "the rollback leaves the checkout detached" \
  'git -C "$REPO" checkout -q "$ref" || bad' 'true || bad' \
  'deploy_hybrid' '--answer ROLLBACK --rollback' '[ "$(clone_ref)" != "$BRANCH" ]'
mutant "RB1 blind" \
  'if [ -n "$b" ] && [ "$a" = "$b" ]; then RB_OK=$((RB_OK+1)); else RB_BAD="$RB_BAD $rel"; fi' 'RB_OK=$((RB_OK+1))' \
  'printf "\n// changed\n" >> "$PD/cron/master.php"' '--rollback' 'grep -q "ok    RB1 all" <<<"$o"'
# the syntax NO-GO, on a build with a broken runtime file (the copy is re-pinned to it first)
( cd "$REPO" && printf '<?php\nfunction broken( {\n' > dishnet-hybrid-sudan/cron/master.php && git -c user.name=h -c user.email=h@example.test commit -qam "broken runtime file" )
BROKEN="$(git -C "$REPO" log -1 --format=%h -- dishnet-hybrid-sudan)"; BROKEN_HEAD="$(git -C "$REPO" rev-parse HEAD)"
sed "s/^EXPECTED_PLUGIN_COMMIT=\"$PIN\"/EXPECTED_PLUGIN_COMMIT=\"$BROKEN\"/" "$DEPLOY" > "$SB/broken-pin.sh"
git -C "$REPO" reset -q --hard "$ORIG_HEAD"
SRC="$SB/broken-pin.sh" mutant "the syntax NO-GO removed" \
  '[ -z "$L_BAD" ] || stop "NO-GO: PHP $PHPV in the container rejects:$L_BAD"' 'true' \
  'git -C "$REPO" reset -q --hard "$BROKEN_HEAD"' '--answer DEPLOY' '[ "$(live)" = "$BROKEN" ]'
check "$(git -C "$REPO" rev-parse HEAD)$(clone_ref)" "$ORIG_HEAD$BRANCH" "control: the clone is back on $BRANCH at its commit"
# the pin: with a later release on the branch, what is installed, what is linted, and where the checkout ends up
mutant "the deploy installs the branch tip instead of the pinned commit" \
  'PIN_CHECKOUT=1' 'PIN_CHECKOUT=' \
  'later_commit' '--answer DEPLOY' 'grep -q later_release "$PD/cron/master.php"'
mutant "the checkout left on the pinned commit after the deploy" \
  'if git -C "$REPO" checkout -q "$DEPLOY_REF"; then echo "  the checkout is back on $DEPLOY_REF"' 'if true; then echo "  the checkout is back on $DEPLOY_REF"' \
  'later_commit' '--answer DEPLOY' '[ "$(clone_ref)" != "$BRANCH" ]'
# A broken build pinned while the branch already carries its fix: the check must read the pinned files.
fixed_on_branch() {
  ( cd "$REPO" && git show "$ORIG_HEAD:dishnet-hybrid-sudan/cron/master.php" > dishnet-hybrid-sudan/cron/master.php \
      && git -c user.name=h -c user.email=h@example.test commit -qam "fixed on the branch" )
}
git -C "$REPO" reset -q --hard "$BROKEN_HEAD"; fixed_on_branch; cp "$SB/broken-pin.sh" "$REPO/scripts/.broken-pin.sh"
fresh; install_base; seed_data; vault UGX; OUT="$(run --answer DEPLOY --script "$REPO/scripts/.broken-pin.sh")"
check "$(has "$OUT" 'STOP: NO-GO: PHP ')$(has "$OUT" 'in the container rejects: cron/master.php')$(live)" "yesyes$BASE" \
  "8b pinned to a broken build while the branch has fixed it: the check reads the pinned files — NO-GO, nothing deployed"
rm -f "$REPO/scripts/.broken-pin.sh"; git -C "$REPO" reset -q --hard "$ORIG_HEAD"
SRC="$SB/broken-pin.sh" mutant "the syntax check reads the working tree, not the pinned commit" \
  'git -C "$REPO" show "$EXPECTED_PLUGIN_COMMIT:$f" > "/tmp/dnb_lint.$$" 2>/dev/null' 'cat "$REPO/$f" > "/tmp/dnb_lint.$$" 2>/dev/null' \
  'git -C "$REPO" reset -q --hard "$BROKEN_HEAD"; fixed_on_branch' '--answer DEPLOY' '[ "$(live)" = "$BROKEN" ]'
check "$(git -C "$REPO" rev-parse HEAD)$(clone_ref)" "$ORIG_HEAD$BRANCH" "control: the clone is back on $BRANCH at its commit"

echo; echo "== 9. what the rehearsal left behind =="
check "$(checkout_state)" "$CHECKOUT0" "this checkout is as the rehearsal found it: same commit, same tracked files"
check "$(ls "$REPO/scripts"/.mutant-* "$REPO/scripts"/.broken-pin.sh 2>/dev/null | wc -l | tr -d ' ')" "0" "no weakened copy is left in the clone"

echo; echo "rehearsal: $PASS passed, $FAILN failed ($(cat "$SB/runs") runs of the script)"
[ "$FAILN" = "0" ]
