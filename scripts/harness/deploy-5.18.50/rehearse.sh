#!/usr/bin/env bash
# Rehearse scripts/deploy-5.18.50.sh — the deploy, its checks, and the ROLLBACK — before the operator runs any of it
# (docs/44 §16). Everything is real except the container and the public address:
#   · the repository is a CLONE of this one: the deploy and the rollback run the real `git checkout` and the real
#     scripts/deploy-hybrid.sh, and this checkout is never touched;
#   · the "container" is a directory: a fake docker maps /data to it, runs `exec` here (this machine's PHP standing in
#     for the server's), answers `inspect`, and `logs` from a file;
#   · the installed plugin starts as 5.18.49 exactly (git archive e076632), its data built by 5.18.49's own store, and
#     Uganda selected as on the server: currency_code UGX in the configuration vault, no tenant_profile anywhere;
#   · the public address and the :8443 door (TLS, self-signed) are stand-ins answering stage V as the live pages do.
# DEPLOY and ROLLBACK are typed through a pseudo-terminal, as the operator types them.
#
#   bash scripts/harness/deploy-5.18.50/rehearse.sh            REHEARSE_KEEP=<dir> keeps every run's full output
#
# It needs the script pinned to this checkout's plugin commit, port 8443 free, openssl and python3; it refuses to run
# where the server could be.
set -u
R="$(cd "$(dirname "$0")/../../.." && pwd)"
for f in /data/ucrm /opt/dishnet /var/run/docker.sock; do
  [ -e "$f" ] && { echo "refusing: $f exists — this looks like the server, and this rehearsal must never run there"; exit 2; }
done
BASE=e076632; BRANCH_FILE=scripts/deploy-5.18.50.sh
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
check "$(python3 -c 'import json,sys; print(json.load(open(sys.argv[1]))["information"]["version"])' "$REPO/dishnet-hybrid-sudan/manifest.json")" "5.18.50" "the plugin is 5.18.50"
N_CH="$(git -C "$REPO" diff --name-only --diff-filter=AMR "$BASE" "$PIN" -- dishnet-hybrid-sudan | grep -c .)"
N_AD="$(git -C "$REPO" diff --name-only --diff-filter=A "$BASE" "$PIN" -- dishnet-hybrid-sudan | grep -c .)"
N_MO=$((N_CH - N_AD))
echo "  Release A $N_CH files: $N_MO changed, $N_AD added"
(ss -ltn 2>/dev/null || netstat -ltn 2>/dev/null) | grep -q ':8443 ' && { echo "refusing: port 8443 is in use — the :8443 door stands in there"; exit 2; }

# ── The stand-ins: the public address and the :8443 door ─────────────────────
free_port() { python3 -c 'import socket;s=socket.socket();s.bind(("127.0.0.1",0));print(s.getsockname()[1]);s.close()'; }
WPORT="$(free_port)"; PLUGIN_BASE="http://127.0.0.1:$WPORT/public.php"
mkdir -p "$SB/web" "$SB/bin" "$SB/out"
cat > "$SB/web/public.php" <<PHP
<?php
// The public address as the live pages answered stage V (5.18.49, 27 Sep): the words each check reads, nothing else.
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

# ── The container: 5.18.49 installed, its data beside it ─────────────────────
MOUNT="$SB/mount"; PLUGINS="$MOUNT/ucrm/data/plugins"; PD="$PLUGINS/dishnet-hybrid-sudan"; DATA="$PLUGINS/.dishnet-hybrid-sudan-data"
VAULT="$PLUGINS/.dishnet-sudan.vault.json"
mkdir -p "$PD" "$DATA"
install_base() {   # the installed plugin exactly as 5.18.49 — nothing 5.18.50 added is left
  find "$PD" -mindepth 1 -maxdepth 1 ! -name ucrm.json -exec rm -rf {} +
  git -C "$REPO" archive "$BASE:dishnet-hybrid-sudan" | tar -x -C "$PD"
  printf '%s\n' "$BASE" > "$PD/.deployed-commit"
}
printf '{"pluginDataDir":"/data/ucrm/data/plugins/.dishnet-hybrid-sudan-data","ucrmPublicUrl":"http://127.0.0.1:1/crm"}' > "$PD/ucrm.json"
vault() { printf '{"config":{"currency_code":"%s"}}' "$1" > "$VAULT"; }
seed_data() {      # the plugin's databases, written by 5.18.49's own store; the Message Log already holds job messages
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
plant_assign() { sq "INSERT INTO notification_audit_log (sender, event, phone, preview, success) VALUES ('sandbox', 'job_assigned', '256700000111', 'x', 1)"; }
state_mark() { printf 'DEPLOYED_AT=2026-09-27T20:00:00Z\nNAL_MARK=%s\nSNAP_BEFORE=\nFROM_COMMIT=%s\n' "$1" "$BASE" > "$SB/out/state-5.18.50.env"; }
data_digest() {    # every table's rows, and the vault — what "no data changed" means here
  { php -r '$p = new PDO("sqlite:" . $argv[1]); foreach ($p->query("SELECT name FROM sqlite_master WHERE type = \x27table\x27 ORDER BY name")->fetchAll(PDO::FETCH_COLUMN) as $t)
      echo $t, ":", json_encode($p->query("SELECT * FROM [$t]")->fetchAll(PDO::FETCH_NUM)), "\n";' "$DATA/plugin.sqlite3"; cat "$VAULT"; } | sha256sum | cut -c1-16
}
installed_digest() {   # $1 a commit: 1 if every file Release A touches is installed exactly as that commit has it (added files: only those it has)
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

# A person edits one staff account (its phone) — used while a deploy runs, and after one.
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
fresh() { rm -rf "$SB/out"; mkdir -p "$SB/out"; : > "$SB/container.log"; rm -f "$SB/redirect-login" "$SB/mangle" "$SB/edit-during-deploy"; }
backups() { ls -d "$SB/out"/backup-* 2>/dev/null | wc -l | tr -d ' '; }

install_base; seed_data; vault UGX; fresh
D0="$(data_digest)"

echo; echo "== 1. NO-GO before anything changes =="
vault SSP; OUT="$(run --answer DEPLOY)"
check "$(has "$OUT" 'STOP: NO-GO: the installed plugin does not read Uganda from both configuration sources (store south-sudan, files south-sudan)')" "yes" \
  "1a the tenant reads South Sudan: NO-GO, both sources named"
check "$(live)$(backups)" "${BASE}0" "…nothing deployed, no backup taken"
vault UGX
KYC0="$(row_get kyc_config 0)"
sq "UPDATE kyc_config SET data = json_set(data, '$.tenant_profile', 'uganda') WHERE id = 0"; vault SSP; OUT="$(run --answer DEPLOY)"
check "$(has "$OUT" 'STOP: NO-GO: the installed plugin does not read Uganda from both configuration sources (store uganda, files south-sudan)')" "yes" \
  "1b only the store copy reads Uganda (the webhooks would not): NO-GO"
check "$(live)" "$BASE" "…nothing deployed"
row_put kyc_config 0 "$KYC0"; vault UGX
printf '%s\n' 04155df > "$PD/.deployed-commit"; OUT="$(run --answer DEPLOY)"
check "$(has "$OUT" 'STOP: NO-GO: the container serves 04155df; Release A was built and tested against e076632 (5.18.49)')" "yes" \
  "1c the server runs another build: NO-GO"
printf '%s\n' "$BASE" > "$PD/.deployed-commit"
echo "// edit" >> "$REPO/dishnet-hybrid-sudan/lib/JobTime.php"; OUT="$(run --answer DEPLOY)"
check "$(has "$OUT" 'STOP: the checkout has 1 locally edited tracked files')" "yes" "1d an edited file in the checkout: stop"
git -C "$REPO" checkout -q -- dishnet-hybrid-sudan/lib/JobTime.php
touch "$SB/mangle"; OUT="$(run --answer DEPLOY)"; rm -f "$SB/mangle"
check "$(has "$OUT" 'FAIL  backup of plugin.sqlite3 failed: the copy that arrived is not the copy checked')" "yes" "1e a backup copy that arrives changed is caught"
check "$(has "$OUT" 'STOP: NO-GO: the backup or a before-check did not complete')" "yes" "…NO-GO"
check "$(live)$(has "$OUT" 'Type DEPLOY')" "${BASE}no" "…before anyone is asked to type DEPLOY"
fresh; OUT="$(run --answer no)"
check "$(has "$OUT" 'GO — evidence recorded')$(has "$OUT" 'STOP: not confirmed')" "yesyes" "1f anything but DEPLOY: the backup is taken, then it stops"
check "$(live)$([ -e "$SB/out/state-5.18.50.env" ] && echo state || echo nostate)" "${BASE}nostate" "…nothing deployed, nothing recorded as a deploy"
check "$(data_digest)" "$D0" "…and no data or configuration value changed in any of these runs"

echo; echo "== 2. the syntax check under the server's PHP, on a build with a broken file =="
( cd "$REPO" && printf '<?php\nfunction broken( {\n' > dishnet-hybrid-sudan/lib/JobTime.php && git -c user.name=h -c user.email=h@example.test commit -qam "broken runtime file" )
BROKEN="$(git -C "$REPO" log -1 --format=%h -- dishnet-hybrid-sudan)"
sed "s/^EXPECTED_PLUGIN_COMMIT=\"$PIN\"/EXPECTED_PLUGIN_COMMIT=\"$BROKEN\"/" "$DEPLOY" > "$REPO/scripts/.broken-pin.sh"
fresh; OUT="$(run --answer DEPLOY --script "$REPO/scripts/.broken-pin.sh")"
check "$(has "$OUT" 'STOP: NO-GO: PHP ')$(has "$OUT" 'in the container rejects: lib/JobTime.php')" "yesyes" "2a a runtime file the server's PHP rejects: NO-GO, named"
check "$(live)$(backups)" "${BASE}0" "…nothing deployed, before the backup"
git -C "$REPO" reset -q --hard "$ORIG_HEAD"
( cd "$REPO" && printf '<?php\nfunction broken( {\n' > dishnet-hybrid-sudan/tests/test_job_time.php && git -c user.name=h -c user.email=h@example.test commit -qam "broken test file" )
BROKEN="$(git -C "$REPO" log -1 --format=%h -- dishnet-hybrid-sudan)"
sed "s/^EXPECTED_PLUGIN_COMMIT=\"$PIN\"/EXPECTED_PLUGIN_COMMIT=\"$BROKEN\"/" "$DEPLOY" > "$REPO/scripts/.broken-pin.sh"
fresh; OUT="$(run --answer no --script "$REPO/scripts/.broken-pin.sh")"
check "$(has "$OUT" 'rejects test files (they are copied, never run on the server): tests/test_job_time.php')$(has "$OUT" 'STOP: NO-GO: PHP')" "yesno" \
  "2b a test file it rejects: a note, not a NO-GO"
git -C "$REPO" reset -q --hard "$ORIG_HEAD"; rm -f "$REPO/scripts/.broken-pin.sh"
check "$(git -C "$REPO" rev-parse HEAD)$(git -C "$REPO" status --porcelain --untracked-files=no | wc -l | tr -d ' ')" "${ORIG_HEAD}0" "control: the clone is back at its commit, clean"

echo; echo "== 3. the deploy, as the operator runs it =="
fresh; OUT="$(run --answer DEPLOY)"
check "$(fails "$OUT")" "0" "no FAIL line"
check "$(has "$OUT" '5.18.50 (deploy): PASSED')" "yes" "PASSED"
for l in "ok    A1 the installed plugin reads Uganda from both configuration sources" \
         "in the container accepts all 26 changed PHP files that run on the server (and 11 test files)" \
         "ok    A3 staff accounts read: 7 accounts, 6 active; of the 5 active accounts that take jobs, 3 hold a uCRM user id (0 through a verified link, 3 stored the old way) and 1 only an FTTH id" \
         "ok    A4 the Message Log holds 4 rows; its last is #4" \
         "— one consistent copy (VACUUM INTO" "ok    backed up the installed plugin (5.18.49, $BASE)" "ok    backed up the configuration vault" \
         "GO — evidence recorded" "ok    container serves $PIN" \
         "ok    V1 the sign-in page on the public address answers 200 with zero redirects (no loop)" \
         "ok    V3 the sign-in page on :8443 → 302 → the public address, same path and query (A1.3)" "ok    V4 no fatal" \
         "ok    R1 all $N_CH files Release A changes are installed exactly as $PIN has them ($N_AD of them new)" \
         "ok    R1 the installed manifest says 5.18.50" \
         "ok    R2 the installed StaffJobsGate reads Uganda from both configuration sources: Release A's rules are on (store on, files on)" \
         "ok    R3 every staff account is as it was at stage A of this run" \
         "ok    R4 no job-assignment WhatsApp message in the Message Log since row #4" \
         "ok    R5 the master cron's job_assign entry is still commented out" \
         "ok    R6 ＋ New Job says: \"No WhatsApp message is sent for jobs yet\"" \
         "ok    R6 Bulk Dispatch and Reschedule show the server's note that no message was sent" \
         "ok    R6 a job created in uCRM is logged \"WhatsApp skipped: job notifications are not switched on yet\"" \
         "note  R7 0 of 5 active accounts that take jobs have a verified uCRM link. The other 5 see no job in My Jobs until an admin saves their uCRM user through the picker"; do
  check "$(has "$OUT" "$l")" "yes" "3: ${l:0:118}"
done
check "$(cnt "$OUT" 'ok    backed up ')" "6" "six backups: two databases, the data directory, the plugin's own data/, the installed plugin, the vault"
check "$(live)$(installed_digest "$PIN")$(n_added_present)" "${PIN}0${N_AD}" "the container serves $PIN: every file as the commit has it, all $N_AD new files present"
check "$(data_digest)" "$D0" "no data and no configuration value changed"
BK="$(ls -d "$SB/out"/backup-* | tail -1)"
check "$(php -r '$p = new PDO("sqlite:" . $argv[1]); echo implode(",", $p->query("PRAGMA integrity_check")->fetchAll(PDO::FETCH_COLUMN)), " ", $p->query("SELECT count(*) FROM retailers")->fetchColumn();' "$BK/plugin.sqlite3")" "ok 7" \
  "the database backup opens: integrity ok, the 7 staff rows"
check "$(tar -xzOf "$BK/plugin-installed-5.18.49.tar.gz" dishnet-hybrid-sudan/manifest.json | grep -c '"version": "5.18.49"')$(tar -tzf "$BK/plugin-installed-5.18.49.tar.gz" | grep -c 'StaffJobsGate')" "10" \
  "the code backup is 5.18.49, without a 5.18.50 file"
check "$(cmp -s "$BK/config-vault.json" "$VAULT" && echo same)$(cat "$BK/deployed-commit.before")" "same$BASE" "the vault copy is identical; the commit before is recorded"
check "$(grep -c '^NAL_MARK=4$' "$SB/out/state-5.18.50.env")$(grep -c "^FROM_COMMIT=$BASE$" "$SB/out/state-5.18.50.env")" "11" "where the deploy started is recorded for later runs"
check "$(grep -cE 'example\.test|2567000001[0-9][0-9]|0700000[0-9]{3}|Sandbox ' <<<"$OUT")" "0" "the log carries no name, e-mail or phone number of a staff account"

echo; echo "== 4. later runs: --after-only =="
OUT="$(run --after-only)"
check "$(fails "$OUT")$(has "$OUT" '5.18.50 (after): PASSED')" "0yes" "4a straight after: PASSED"
check "$(has "$OUT" "ok    R3 every staff account is as it was at the deploy's stage A")" "yes" "…R3 compares with the deploy's own record"
sq "INSERT INTO notification_audit_log (sender, event, phone, preview, success) VALUES ('sandbox', 'ops_scheduling_job_accepted', '256700000111', 'x', 1)"
OUT="$(run --after-only)"
check "$(has "$OUT" 'ok    R4 no job-assignment WhatsApp message in the Message Log since row #4')$(has "$OUT" 'ops_scheduling_job_accepted ×1')" "yesyes" \
  "4b an accept message since the deploy: R4 ok, and it is counted as information"
sq "INSERT INTO notification_audit_log (sender, event, phone, preview, success) VALUES ('sandbox', 'ops_scheduling_job_assigned', '256700000111', 'x', 1)"
OUT="$(run --after-only)"
check "$(has "$OUT" 'FAIL  R4 1 job-assignment message(s) in the Message Log since row #4')$(has "$OUT" 'ops_scheduling_job_assigned ×1')" "yesyes" \
  "4c a job-assignment message since the deploy: R4 fails, naming the event (the four before the deploy are not counted)"
sq "DELETE FROM notification_audit_log WHERE id > 4"
ROW2="$(row_get retailers 2)"
sq "UPDATE retailers SET data = json_set(data, '$.ucrm_link', json('{\"user_id\":1099,\"email\":\"tech@example.test\",\"verified_at\":\"2026-09-27T20:00:00Z\",\"verified_by\":1}')) WHERE id = 2"
OUT="$(run --after-only)"
check "$(has "$OUT" "note  R3 staff accounts changed since the deploy's stage A")$(has "$OUT" 'account id(s): 2 (a link saved through the picker')$(fails "$OUT")" "yesyes0" \
  "4d a link saved through the picker since: a note naming account 2, never a failure"
check "$(has "$OUT" 'note  R7 1 of 5 active accounts that take jobs have a verified uCRM link')" "yes" "…and R7 counts it verified"
row_put retailers 2 "$ROW2"
vault SSP; OUT="$(run --after-only)"
check "$(has "$OUT" 'FAIL  R2 the installed StaffJobsGate reads store off / files off')" "yes" "4e the tenant no longer reads Uganda: R2 fails"
vault UGX
printf '\n// changed on the server\n' >> "$PD/lib/JobAccess.php"; OUT="$(run --after-only)"
check "$(has "$OUT" "FAIL  R1 installed files that differ from $PIN: lib/JobAccess.php")" "yes" "4f an installed file changed after the deploy: R1 fails, naming it"
git -C "$REPO" show "$PIN:dishnet-hybrid-sudan/lib/JobAccess.php" > "$PD/lib/JobAccess.php"
echo "[27-Sep-2026 20:00:00 UTC] PHP Fatal error:  Uncaught Error in /data/ucrm/data/plugins/dishnet-hybrid-sudan/lib/JobAccess.php:12" > "$SB/container.log"
OUT="$(run --after-only)"; : > "$SB/container.log"
check "$(has "$OUT" 'FAIL  V4 1 fatal line(s) of dishnet-hybrid-sudan')" "yes" "4g a fatal in the container log: V4 fails"
OUT="$(run --after-only)"
check "$(fails "$OUT")$(has "$OUT" 'PASSED')" "0yes" "control: with each planted fault removed, PASSED again"
D0="$(data_digest)"   # the Message Log's sequence moved with the rows planted above: the rollback is measured from here

echo; echo "== 5. the rollback: --rollback, typed ROLLBACK =="
fresh; OUT="$(run --answer ROLLBACK --rollback)"
check "$(fails "$OUT")$(has "$OUT" '5.18.50 (rollback): PASSED')" "0yes" "PASSED"
for l in "GO — evidence recorded" "checking out $BASE (5.18.49) for the documented deploy" "ok    container serves $BASE (5.18.49)" \
         "ok    RB1 all $N_MO files Release A had changed are back exactly as $BASE (5.18.49) has them" "ok    RB1 the installed manifest says 5.18.49" \
         "ok    RB2 the $N_AD files 5.18.50 added are still on disk and inert: no 5.18.49 file loads them" \
         "ok    RB3 every staff account is as it was at stage A of this run" \
         "note  on 5.18.49 again: the job-assignment WhatsApp messages are sent again"; do
  check "$(has "$OUT" "$l")" "yes" "5: ${l:0:118}"
done
check "$(cnt "$OUT" 'ok    backed up ')" "6" "the same backup first"
check "$(live)$(installed_digest "$BASE")$(n_added_present)" "${BASE}0${N_AD}" "the container serves $BASE: every changed file as 5.18.49 has it; the $N_AD new files remain"
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
check "$(live)$(installed_digest "$BASE")$(clone_ref)" "${BASE}0$BRANCH" "6b that line, run as printed, puts 5.18.49 back and returns the checkout"
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
check "$(live)$(installed_digest "$BASE")$(clone_ref)" "${BASE}0$BRANCH" "5.18.49 is back, the checkout on $BRANCH"

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
mutant "the Uganda check removed" \
  'if [ "$T_STORE" = "uganda" ] && [ "$T_FILES" = "uganda" ]; then ok "A1' 'if true; then ok "A1' \
  'vault SSP' '--answer DEPLOY' '[ "$(live)" = "$PIN" ]'
mutant "a build over another live commit" \
  'elif [ "$LIVE_BEFORE" != "$BASELINE_COMMIT" ]; then' 'elif false; then' \
  'printf "%s\n" 04155df > "$PD/.deployed-commit"' '--answer DEPLOY' '[ "$(live)" = "$PIN" ]'
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
  'deploy_hybrid; state_mark 4; plant_assign' \
  '--after-only' 'grep -q "ok    R4 no job-assignment" <<<"$o"'
mutant "R1 blind" \
  'if [ -n "$b" ] && [ "$a" = "$b" ]; then R_OK=$((R_OK+1)); else R_BAD="$R_BAD $rel"; fi' 'R_OK=$((R_OK+1))' \
  'deploy_hybrid; printf "\n// changed\n" >> "$PD/lib/JobAccess.php"' '--after-only' 'grep -q "ok    R1 all" <<<"$o"'
mutant "R2 takes any answer as on" \
  'if [ "$G_STORE" = "on" ] && [ "$G_FILES" = "on" ]; then' 'if true; then' \
  'deploy_hybrid; vault SSP' '--after-only' 'grep -q "ok    R2 the installed StaffJobsGate" <<<"$o"'
mutant "ROLLBACK never required" \
  '[ "$ANSWER" = "ROLLBACK" ] || stop "not confirmed"' 'true' \
  'deploy_hybrid' '--answer no --rollback' '[ "$(live)" = "$BASE" ]'
mutant "the rollback leaves the checkout detached" \
  'git -C "$REPO" checkout -q "$ref" || bad' 'true || bad' \
  'deploy_hybrid' '--answer ROLLBACK --rollback' '[ "$(clone_ref)" != "$BRANCH" ]'
mutant "RB1 blind" \
  'if [ -n "$b" ] && [ "$a" = "$b" ]; then RB_OK=$((RB_OK+1)); else RB_BAD="$RB_BAD $rel"; fi' 'RB_OK=$((RB_OK+1))' \
  'printf "\n// changed\n" >> "$PD/public.php"' '--rollback' 'grep -q "ok    RB1 all" <<<"$o"'
# the syntax NO-GO, on a build with a broken runtime file (the copy is re-pinned to it first)
( cd "$REPO" && printf '<?php\nfunction broken( {\n' > dishnet-hybrid-sudan/lib/JobTime.php && git -c user.name=h -c user.email=h@example.test commit -qam "broken runtime file" )
BROKEN="$(git -C "$REPO" log -1 --format=%h -- dishnet-hybrid-sudan)"; BROKEN_HEAD="$(git -C "$REPO" rev-parse HEAD)"
sed "s/^EXPECTED_PLUGIN_COMMIT=\"$PIN\"/EXPECTED_PLUGIN_COMMIT=\"$BROKEN\"/" "$DEPLOY" > "$SB/broken-pin.sh"
git -C "$REPO" reset -q --hard "$ORIG_HEAD"
SRC="$SB/broken-pin.sh" mutant "the syntax NO-GO removed" \
  '[ -z "$L_BAD" ] || stop "NO-GO: PHP $PHPV in the container rejects:$L_BAD"' 'true' \
  'git -C "$REPO" reset -q --hard "$BROKEN_HEAD"' '--answer DEPLOY' '[ "$(live)" = "$BROKEN" ]'
check "$(git -C "$REPO" rev-parse HEAD)$(clone_ref)" "$ORIG_HEAD$BRANCH" "control: the clone is back on $BRANCH at its commit"

echo; echo "== 9. what the rehearsal left behind =="
check "$(checkout_state)" "$CHECKOUT0" "this checkout is as the rehearsal found it: same commit, same tracked files"
check "$(ls "$REPO/scripts"/.mutant-* "$REPO/scripts"/.broken-pin.sh 2>/dev/null | wc -l | tr -d ' ')" "0" "no weakened copy is left in the clone"

echo; echo "rehearsal: $PASS passed, $FAILN failed ($(cat "$SB/runs") runs of the script)"
[ "$FAILN" = "0" ]
