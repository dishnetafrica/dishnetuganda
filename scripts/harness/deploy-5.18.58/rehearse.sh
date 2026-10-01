#!/usr/bin/env bash
# Rehearse scripts/deploy-5.18.58.sh — the deploy, its checks, and the ROLLBACK — before the operator runs any of it.
#
# 5.18.58 is the sales/support half of the Uganda tenant-currency fix (5.18.57 did the accounting screens), sitting on
# 5.18.57. The deploy script is a diff-proven, minimal derivation of scripts/deploy-5.18.57.sh: only the pinned commit,
# the version and baseline labels, the output paths, the RELEASE_A labels, the new R1e line, the rollback note and two
# summary lines change — the backup / GO-NO-GO / typed-DEPLOY / typed-ROLLBACK / R1-R14 machinery is BYTE-IDENTICAL.
# That machinery was rehearsed for 5.18.57 (scripts/harness/deploy-5.18.57/, 70/0). So this rehearsal proves the delta:
#   · the script runs end to end against a 5.18.57 base and PASSES;
#   · R1e — the one new check — names the sales/support tenant gate when it is installed, and FAILS when it is not;
#   · the 6-file delta installs byte-for-byte and the manifest reads 5.18.58;
#   · R1c (scM, 5.18.56) and R1d (the 5.18.57 accounting gate), PD-1 (R1b) and the notification release (R2, R9) survive
#     as regression — the new copy loses none of 5.18.57;
#   · data and configuration are unchanged, the backup is of 5.18.57, and the rollback command is printed on its own;
#   · the rollback returns the field-agent screens to 5.18.57 (SSP visible on Uganda again), manifest 5.18.57;
#   · the base gate holds: a server on an older commit is a NO-GO ("deploy 5.18.57 first");
#   · a weakened copy of the script whose R1e grep can no longer tell the tenant fix apart is caught.
#
# Everything is real except the container and the public address, exactly as the 5.18.57 rehearsal:
#   · the repository is a CLONE; the deploy and rollback run the real git checkout and the real scripts/deploy-hybrid.sh;
#   · the "container" is a directory a fake docker maps to; this machine's PHP stands in for the server's;
#   · the installed plugin starts as 5.18.57 exactly (git archive eea3d65), its data built by 5.18.57's own store —
#     migrations 075 and 076 applied — and Uganda selected (currency_code UGX in the vault, no tenant_profile);
#   · php.ini sets validate_timestamps 1 / revalidate_freq 2, no PHP-FPM pool overrides OPcache (§16.23);
#   · the public address and the :8443 door answer stage V as the live pages do.
# DEPLOY and ROLLBACK are typed through a pseudo-terminal, as the operator types them.
#
#   bash scripts/harness/deploy-5.18.58/rehearse.sh            REHEARSE_KEEP=<dir> keeps every run's full output
#
# It needs the script pinned to this checkout's plugin commit, port 8443 free, openssl and python3; it refuses to run
# where the server could be.
set -u
R="$(cd "$(dirname "$0")/../../.." && pwd)"
for f in /data/ucrm /opt/dishnet /var/run/docker.sock; do
  [ -e "$f" ] && { echo "refusing: $f exists — this looks like the server, and this rehearsal must never run there"; exit 2; }
done
BASE=eea3d65; RA_BASE=e076632; OLDER=6b71ea6; BRANCH_FILE=scripts/deploy-5.18.58.sh; P=dishnet-hybrid-sudan
checkout_state() { { git -C "$R" rev-parse HEAD; git -C "$R" status --porcelain --untracked-files=no; git -C "$R" diff HEAD; } | sha256sum | cut -c1-16; }
CHECKOUT0="$(checkout_state)"
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
find "$REPO/dishnet-hybrid-sudan" -type f -exec touch -d "@$(git -C "$REPO" log -1 --format=%ct)" {} +
PIN="$(sed -n 's/^EXPECTED_PLUGIN_COMMIT="\([^"]*\)".*/\1/p' "$DEPLOY")"
echo "== 0. what is rehearsed =="
echo "  script    $WHICH, sha256 $(sha256sum "$DEPLOY" | cut -c1-16)"
echo "  clone     $BRANCH at $(git -C "$REPO" rev-parse --short HEAD)"
check "$PIN" "$(git -C "$REPO" log -1 --format=%h -- $P)" "the script is pinned to the clone's plugin commit ($PIN)"
check "$(python3 -c 'import json,sys; print(json.load(open(sys.argv[1]))["information"]["version"])' "$REPO/$P/manifest.json")" "5.18.58" "the plugin is 5.18.58"
dl() { git -C "$REPO" diff --no-renames --name-only --diff-filter="$1" "$2" "$3" -- $P; }
CHANGED="$(dl AM "$BASE" "$PIN")"
N_CH="$(grep -c . <<<"$CHANGED")"; N_AD="$(dl A "$BASE" "$PIN" | grep -c .)"; N_MO=$((N_CH - N_AD)); N_DEL="$(dl D "$BASE" "$PIN" | grep -c .)"
echo "  5.18.57   $N_CH files: $N_MO changed, $N_AD added · $N_DEL removed or renamed"
check "$N_CH" "6" "control: 5.18.58 changes exactly six files (four screens + manifest + the new test)"
check "$N_DEL" "0" "control: 5.18.57 removes no file"
check "$(grep -c 'tabs/support/field_expenses.php' <<<"$CHANGED")" "1" "control: the delta includes the Field Expenses tab"
# NotifyGate's fixes, as the pinned commit has them: stage R2 must still find every one on (regression from 5.18.54).
N_FIX="$(git -C "$REPO" show "$PIN:$P/lib/NotifyGate.php" | php -r 'eval("?>" . stream_get_contents(STDIN)); echo count(NotifyGate::ALL);')"
check "$([ "${N_FIX:-0}" -gt 20 ] && echo many)" "many" "control: the pinned NotifyGate still names its fixes ($N_FIX)"
# 5.18.58 must contain the sales/support tenant gate; 5.18.57 (the base) must not — the control the whole release turns on.
check "$(git -C "$REPO" show "$PIN:$P/tabs/support/field_expenses.php" | grep -c '$feSSP = dn_ssp_selectable($config ?? null)')" "1" "control: 5.18.58's Field Expenses derives \$feSSP from the tenant (SSP hidden on Uganda)"
check "$(git -C "$REPO" show "$BASE:$P/tabs/support/field_expenses.php" | grep -c '$feSSP = dn_ssp_selectable($config ?? null)')" "0" "control: 5.18.57's Field Expenses has no tenant gate (the SSP-on-Uganda leak this release fixes)"
# the 5.18.57 accounting gate ($scSSP) is present in BOTH base and pin — an inherited regression, not this release's change.
check "$(git -C "$REPO" show "$BASE:$P/tabs/accounts/staff_cashbooks.php" | grep -c '$scSSP      = dn_ssp_selectable($config);')$(git -C "$REPO" show "$PIN:$P/tabs/accounts/staff_cashbooks.php" | grep -c '$scSSP      = dn_ssp_selectable($config);')" "11" "control: the 5.18.57 accounting tenant gate is inherited by 5.18.58 (present in base 5.18.57 and pin)"
(ss -ltn 2>/dev/null || netstat -ltn 2>/dev/null) | grep -q ':8443 ' && { echo "refusing: port 8443 is in use — the :8443 door stands in there"; exit 2; }
HEADER_CMD="$(sed -n '/^# Run as root/,/^# The rollback is a separate command/p' "$DEPLOY")"
check "$(grep -c 'deploy-5.18.58.sh 2>&1 | tee' <<<"$HEADER_CMD")$(grep -cE -- '--rollback|git checkout [0-9a-f]{7}' <<<"$HEADER_CMD")" "10" \
  "the header's deploy command stands alone: no rollback and no checkout of another commit in its block (docs/44 §16.9)"

# ── The container: 5.18.55 installed, its data beside it ─────────────────────
MOUNT="$SB/mount"; PLUGINS="$MOUNT/ucrm/data/plugins"; PD="$PLUGINS/$P"; DATA="$PLUGINS/.$P-data"
VAULT="$PLUGINS/.dishnet-sudan.vault.json"
mkdir -p "$PD" "$DATA" "$SB/web" "$SB/bin" "$SB/out"
php_conf() {
  rm -rf "$SB/etc"; mkdir -p "$SB/etc/php/conf.d" "$SB/etc/php-fpm.d"
  { printf '[PHP]\nmemory_limit = 512M\n'; for i in $(seq 1 18); do echo; done; printf '%s\nopcache.revalidate_freq=2\n' "$1"; } > "$SB/etc/php/php.ini"
  printf 'zend_extension=opcache\nopcache.enable_cli=0\n' > "$SB/etc/php/conf.d/docker-php-ext-opcache.ini"
  printf '[www]\nuser = www-data\n%s\n' "$2" > "$SB/etc/php-fpm.d/www.conf"
}
php_conf 'opcache.validate_timestamps=1' ''
PLOG_PRE='[2026-09-30 06:00:02] [records] not saved: the Message Log row (notification_audit_log), event job_assigned — a line from before this deploy'
plugin_log() { printf '%s\n' '[2026-09-30 06:00:01] [master] RUN staff_jobs' "$PLOG_PRE" > "$PD/data/plugin.log"; }

# ── The stand-ins: the public address and the :8443 door (verbatim from the 5.18.55 rehearsal) ──
free_port() { python3 -c 'import socket;s=socket.socket();s.bind(("127.0.0.1",0));print(s.getsockname()[1]);s.close()'; }
WPORT="$(free_port)"; PLUGIN_BASE="http://127.0.0.1:$WPORT/public.php"
cat > "$SB/web/public.php" <<PHP
<?php
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
if (\$page === 'dashboard') {
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

install_base() {   # the installed plugin exactly as 5.18.57
  find "$PD" -mindepth 1 -maxdepth 1 ! -name ucrm.json -exec rm -rf {} +
  git -C "$REPO" archive "$BASE:$P" | tar -x -C "$PD"
  printf '%s\n' "$BASE" > "$PD/.deployed-commit"
  plugin_log
}
printf '{"pluginDataDir":"/data/ucrm/data/plugins/.%s-data","ucrmPublicUrl":"http://127.0.0.1:1/crm"}' "$P" > "$PD/ucrm.json"
vault() { printf '{"config":{"currency_code":"%s"}}' "$1" > "$VAULT"; }
seed_data() {      # the plugin's databases, written by 5.18.57's own store (075 and 076 applied)
  rm -f "$DATA"/plugin.sqlite3* "$DATA"/dishnet.sqlite* "$DATA"/config.json "$DATA"/kyc_config.json "$DATA"/migration.log
  php -r '
    foreach (["bootstrap_data", "StoreInterface", "JsonStore", "SqliteStore"] as $l) require_once $argv[1] . "/lib/$l.php";
    $s = SqliteStore::create($argv[2]);
    $s->save("kyc_config.json", ["crm_base_url" => "http://127.0.0.1:1", "company_name" => "DishNet Sandbox"]);
    $s->save("retailers.json", [
      ["id" => 1, "name" => "Sandbox Admin", "email" => "admin@example.test", "phone" => "+256700000110", "role" => "admin", "is_admin" => true, "is_active" => true, "ucrm_user_id" => 1000],
      ["id" => 4, "name" => "Sandbox Accountant", "email" => "acct@example.test", "phone" => "0700000113", "role" => "accountant", "is_active" => true],
    ]);
    $t = time() - 300;
    $s->save("master_schedule.json", ["event_processor" => ["last_run" => $t, "last_run_at" => "sandbox", "duration_ms" => 5]]);
    $p = $s->getPdo();
    $p->exec("CREATE TABLE IF NOT EXISTS notification_audit_log (id INTEGER PRIMARY KEY AUTOINCREMENT, sender TEXT, event TEXT, phone TEXT, preview TEXT, success INTEGER NOT NULL DEFAULT 0, http_code INTEGER, error TEXT, sent_at TEXT NOT NULL DEFAULT (datetime(\x27now\x27)))");
  ' "$PD" "$DATA" >/dev/null
  php -r '$p = new PDO("sqlite:" . $argv[1]); $p->exec("PRAGMA journal_mode=WAL"); $p->exec("CREATE TABLE wa_messages(id INTEGER PRIMARY KEY, body TEXT)");
    for ($i = 0; $i < 10; $i++) $p->exec("INSERT INTO wa_messages(body) VALUES (\x27hello $i\x27)");' "$DATA/dishnet.sqlite"
}
sq() { php -r '$p = new PDO("sqlite:" . $argv[1]); $p->exec($argv[2]);' "$DATA/plugin.sqlite3" "$1"; }
sqv() { php -r '$p = new PDO("sqlite:" . $argv[1]); echo $p->query($argv[2])->fetchColumn();' "$DATA/plugin.sqlite3" "$1"; }
tables_075() { sqv "SELECT count(*) FROM sqlite_master WHERE type = 'table' AND name IN ('job_notify_state', 'job_notify_events')"; }
mig_075() { sqv "SELECT count(*) FROM _migrations WHERE filename = '075_job_notifications.sql'"; }
mig_076() { sqv "SELECT count(*) FROM _migrations WHERE filename = '076_job_notify_email.sql'"; }
migrations() { php -r '$p = new PDO("sqlite:" . $argv[1]); echo implode(",", $p->query("SELECT filename FROM _migrations ORDER BY id")->fetchAll(PDO::FETCH_COLUMN));' "$DATA/plugin.sqlite3"; }
FLOCK_TEXT="NOTICE: PHP message: PHP Fatal error:  Uncaught TypeError: flock(): supplied resource is not a valid stream resource in /data/ucrm/data/plugins/$P/cron/master.php:83"
logline() { printf '%s.123456789Z %s\n' "$(date -u -d "@$1" +%Y-%m-%dT%H:%M:%S)" "$2" >> "$SB/container.log"; }
base_log() { local a="${1:-$(date -u +%s)}"; : > "$SB/container.log"; logline $((a - 60)) 'NOTICE: fpm is running, pid 1'; }
data_digest() {
  { php -r '$p = new PDO("sqlite:" . $argv[1]); $skip = ["master_schedule", "_migrations", "job_notify_state", "job_notify_events"];
      foreach ($p->query("SELECT name FROM sqlite_master WHERE type = \x27table\x27 ORDER BY name")->fetchAll(PDO::FETCH_COLUMN) as $t) {
        if (in_array($t, $skip, true)) continue;
        $q = $t === "sqlite_sequence" ? "SELECT * FROM sqlite_sequence WHERE name NOT IN (\x27" . implode("\x27,\x27", $skip) . "\x27) ORDER BY name" : "SELECT * FROM [$t]";
        echo $t, ":", json_encode($p->query($q)->fetchAll(PDO::FETCH_NUM)), "\n"; }' "$DATA/plugin.sqlite3"; cat "$VAULT"; } | sha256sum | cut -c1-16
}
installed_digest() {   # $1 a commit: the number of files 5.18.56 touches that are NOT installed as that commit has them
  local c="$1" f rel bad=0
  for f in $CHANGED; do
    rel="${f#$P/}"
    if git -C "$REPO" cat-file -e "$c:$f" 2>/dev/null; then
      [ "$(git -C "$REPO" show "$c:$f" | sha256sum | cut -c1-64)" = "$(sha256sum "$PD/$rel" 2>/dev/null | cut -c1-64)" ] || bad=$((bad+1))
    fi
  done
  echo "$bad"
}
scM_line() { local n; n="$(grep -c 'function scM(float $n):string{global $config;' "$PD/tabs/accounts/staff_cashbooks.php" 2>/dev/null || true)"; echo "${n:-0}"; }
ssp_line() { local n; n="$(grep -c '$feSSP = dn_ssp_selectable($config ?? null)' "$PD/tabs/support/field_expenses.php" 2>/dev/null || true)"; echo "${n:-0}"; }
inst_ver() { grep -o '"version": *"5[^"]*"' "$PD/manifest.json" | head -1 | sed -E 's/.*"(5[^"]*)".*/\1/'; }
live() { tail -n1 "$PD/.deployed-commit" 2>/dev/null | tr -cd '0-9a-f'; }
clone_ref() { git -C "$REPO" symbolic-ref -q --short HEAD || echo "DETACHED at $(git -C "$REPO" rev-parse --short HEAD)"; }
backups() { ls -d "$SB/out"/backup-* 2>/dev/null | wc -l | tr -d ' '; }
deploy_hybrid() { ( cd "$REPO" && PATH="$SB/bin:$PATH" bash scripts/deploy-hybrid.sh >/dev/null 2>&1 ); }
open_store() { curl -s --noproxy '*' -o /dev/null "$PLUGIN_BASE?page=customer_login"; }
fresh() { rm -rf "$SB/out"; mkdir -p "$SB/out"; base_log; rm -f "$SB/redirect-login" "$SB/no-store" "$SB/dashboard-open"; php_conf 'opcache.validate_timestamps=1' ''; }

# ── The fake docker (verbatim from the 5.18.55 rehearsal, less the edit/mangle hooks not exercised here) ──
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
shift
args=(); for a in "\$@"; do case "\$a" in /data/*) args+=("$MOUNT/\${a#/data/}") ;; /usr/local/etc|/usr/local/etc/*) args+=("$SB/etc\${a#/usr/local/etc}") ;; *) args+=("\$a") ;; esac; done
if [ -e "$SB/mangle" ] && [ "\${args[0]}" = "php" ] && grep -q 'VACUUM INTO' <<<"\${args[*]}"; then
  "\${args[@]}" | LC_ALL=C sed '1s/^SQLite format 3/SQLite format X/'; exit "\${PIPESTATUS[0]}"
fi
exec "\${args[@]}"
SH
chmod +x "$SB/bin/docker"

run() {   # [--answer WORD] [--script FILE], then the script's own options — through a pseudo-terminal
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

install_base; seed_data; vault UGX; fresh
open_store
D0="$(data_digest)"; MIG0="$(migrations)"; PLOG0="$(stat -c %s "$PD/data/plugin.log")"
check "$(tables_075)$(mig_075)$(mig_076)" "211" "control: 5.18.57 installed and its store opened: 075 and 076 applied, as on the server"
check "$(inst_ver)$(ssp_line)" "5.18.570" "control: the installed base is 5.18.57, and its Field Expenses still leaks SSP on Uganda (no \$feSSP gate)"

echo; echo "== 1. NO-GO before anything changes =="
vault SSP; OUT="$(run --answer DEPLOY)"
check "$(has "$OUT" 'STOP: NO-GO: the installed plugin does not read Uganda from both configuration sources (store south-sudan, files south-sudan)')" "yes" \
  "1a the tenant reads South Sudan: NO-GO, both sources named"
check "$(live)$(backups)" "${BASE}0" "…nothing deployed, no backup taken"
vault UGX
printf '%s\n' "$OLDER" > "$PD/.deployed-commit"; OUT="$(run --answer DEPLOY)"
check "$(has "$OUT" "STOP: NO-GO: the container serves $OLDER; 5.18.58 was built and tested against $BASE (5.18.57) — deploy 5.18.57 first (scripts/deploy-5.18.57.sh) and send its log")" "yes" \
  "1b the server still runs an older commit: NO-GO — 5.18.57 goes first"
check "$(live)$(backups)" "${OLDER}0" "…nothing deployed, no backup taken"
printf '%s\n' "$BASE" > "$PD/.deployed-commit"
sed 's/^EXPECTED_PLUGIN_COMMIT="[^"]*"/EXPECTED_PLUGIN_COMMIT="__PLUGIN_COMMIT__"/' "$DEPLOY" > "$REPO/scripts/.unpinned.sh"
fresh; OUT="$(run --answer DEPLOY --script "$REPO/scripts/.unpinned.sh")"; rm -f "$REPO/scripts/.unpinned.sh"
check "$(has "$OUT" 'STOP: this copy of the script is not pinned to a reviewed commit')$(live)$(backups)" "yes${BASE}0" \
  "1c a copy still carrying the placeholder pin: stops before anything is read"
check "$(data_digest)$(ssp_line)" "${D0}0" "…and no data changed, and the tenant gate is not installed (still the base 5.18.57)"

echo; echo "== 2. the deploy, as the operator runs it =="
fresh; T3="$(date -u +%s)"; OUT="$(run --answer DEPLOY)"
check "$(fails "$OUT")" "0" "no FAIL line"
check "$(has "$OUT" '5.18.58 (deploy): PASSED')" "yes" "PASSED"
for l in "ok    A1 the installed plugin reads Uganda from both configuration sources" \
         "$N_CH files differ from $BASE: $N_MO changed, $N_AD added, 0 removed" \
         "— one consistent copy (VACUUM INTO" "ok    backed up the installed plugin (5.18.57, $BASE)" "ok    backed up the configuration vault" \
         "GO — evidence recorded" \
         "gave the $N_CH installed file(s) this release changes the time of this copy" "ok    container serves $PIN" \
         "ok    V1 the sign-in page on the public address answers 200 with zero redirects (no loop)" \
         "ok    V4 no fatal or parse error of $P in the container log since" \
         "ok    R1 all $N_CH files 5.18.58 changes are installed exactly as $PIN has them ($N_AD of them new)" \
         "ok    R1 the installed manifest says 5.18.58" \
         "ok    R1 PD-1: the collections CSV export requires sign-in + admin before any data is read (includes/routes.php)" \
         "ok    R1 5.18.56: Staff Cashbooks scM() brings \$config into scope — no \"Undefined variable\" warning on its figure cards (tabs/accounts/staff_cashbooks.php)" \
         "ok    R1 5.18.57: the cash screens are tenant-aware — Staff Cashbooks gates its SSP layer on dn_ssp_selectable, ssp_imprest/ssp_cashbook return early on Uganda (SSP hidden, base tab reads UGX)" \
         "ok    R1 5.18.58: the sales/support cash screens are tenant-aware — Field Expenses, My Account and Wallet hide SSP on Uganda, and fiber_costs' symbol follows the tenant" \
         "ok    R1 the .* other files of Release A through 5.18.57 are installed exactly as $PIN has them" \
         "ok    R2 the installed NotifyGate reads Uganda from both configuration sources: all $N_FIX of 5.18.54's fixes are on" \
         "ok    R9 migrations 075 and 076 are still applied" \
         "ok    R12 all $N_CH files this release changes carry a time from this deploy" \
         "what changed      the Uganda sales/support cash screens show the base currency, not South Sudan's SSP" \
         "regression        R1b/R1c/R1d and R2-R14 confirm 5.18.52-5.18.57 are intact"; do
  if [[ "$l" == *"other files of Release A through"* ]]; then
    check "$(grep -qE "ok    R1 the [0-9]+ other files of Release A through 5.18.57 are installed exactly as $PIN has them" <<<"$OUT" && echo yes || echo no)" "yes" "2: ${l:0:110}"
  else
    check "$(has "$OUT" "$l")" "yes" "2: ${l:0:110}"
  fi
done
check "$(cnt "$OUT" 'ok    backed up ')" "6" "six backups: two databases, the data directory, the plugin's own data/, the installed plugin, the vault"
check "$(live)$(installed_digest "$PIN")" "${PIN}0" "the container serves $PIN: every changed file exactly as the commit has it"
check "$(inst_ver)$(ssp_line)" "5.18.581" "the installed manifest is 5.18.58, and its Field Expenses now gates SSP on the tenant (base shown, SSP hidden on Uganda)"
check "$(data_digest)" "$D0" "no data and no configuration value changed"
check "$(migrations)" "$MIG0" "the migrations ledger is unchanged: 5.18.58 has no migration"
check "$(grep -c "cd $REPO && bash scripts/deploy-5.18.58.sh --rollback" <<<"$OUT")" "1" "the rollback command is printed once, on its own line"
check "$(awk '/PASSED\. Send this LOG FILE back/ {p=1} p && /deploy-5\.18\.58\.sh --rollback/ {print "after"; exit}' <<<"$OUT")" "after" \
  "…after the verdict, at the end of the log, never in the block the operator pasted"
BK="$(ls -d "$SB/out"/backup-* | tail -1)"
check "$(tar -xzOf "$BK"/plugin-installed-5.18.57.tar.gz $P/manifest.json | grep -c '"version": "5.18.57"')" "1" "the code backup is 5.18.57"
check "$(tar -xzOf "$BK"/plugin-installed-5.18.57.tar.gz $P/tabs/support/field_expenses.php | grep -c '$feSSP = dn_ssp_selectable($config ?? null)')" "0" \
  "…and the backed-up field_expenses.php is the base's (no \$feSSP gate): a rollback restores the 5.18.57 screen exactly"

echo; echo "== 3. R1c and R1 have teeth: a missing scM fix on the server is caught =="
# Record where this deploy started, so --after-only has a state to read.
ST="$SB/out/state-5.18.58.env"
check "$(ls "$ST" >/dev/null 2>&1 && echo yes || echo no)" "yes" "control: the deploy recorded where it started (state-5.18.58.env)"
git -C "$REPO" show "$BASE:$P/tabs/support/field_expenses.php" > "$PD/tabs/support/field_expenses.php"   # the server reverts the tab by hand
OUT="$(run --after-only)"
check "$(has "$OUT" "FAIL  R1 5.18.58: the sales/support tenant-currency gate is NOT in the installed screens")" "yes" "3a the tenant gate reverted on the server: R1e fails, naming it"
check "$(has "$OUT" "FAIL  R1 installed files that differ from $PIN: tabs/support/field_expenses.php")" "yes" "3b …and R1's byte check fails on the same file"
git -C "$REPO" show "$PIN:$P/tabs/support/field_expenses.php" > "$PD/tabs/support/field_expenses.php"   # put it back
printf '\n// changed on the server\n' >> "$PD/includes/routes.php"; OUT="$(run --after-only)"
check "$(has "$OUT" "FAIL  R1 Release A-through-5.18.57 files that differ from $PIN: includes/routes.php")" "yes" "3c a Release-A/regression file changed on the server: the regression check fails, naming it"
git -C "$REPO" show "$PIN:$P/includes/routes.php" > "$PD/includes/routes.php"
OUT="$(run --after-only)"
check "$(fails "$OUT")$(has "$OUT" '5.18.58 (after): PASSED')" "0yes" "3d each fault removed: --after-only PASSES again"

echo; echo "== 4. the rollback: --rollback, typed ROLLBACK =="
D1="$(data_digest)"; fresh; OUT="$(run --answer ROLLBACK --rollback)"
check "$(fails "$OUT")$(has "$OUT" '5.18.58 (rollback): PASSED')" "0yes" "PASSED"
for l in "GO — evidence recorded" "checking out $BASE (5.18.57) for the documented deploy" "ok    container serves $BASE (5.18.57)" \
         "ok    RB1 the installed manifest says 5.18.57" \
         "note  on 5.18.57 again: the Uganda field-agent screens show South Sudan's SSP once more"; do
  check "$(has "$OUT" "$l")" "yes" "4: ${l:0:110}"
done
check "$(has "$OUT" "ok    backed up the installed plugin (5.18.58, $PIN)")" "yes" "the backup first — of 5.18.58's code"
check "$(live)$(installed_digest "$BASE")" "${BASE}0" "the container serves $BASE: every changed file as 5.18.57 has it"
check "$(inst_ver)$(ssp_line)" "5.18.570" "the installed manifest is 5.18.57 again, and Field Expenses is the base's (no \$feSSP gate): the SSP leak is back on Uganda"
check "$(data_digest)" "$D1" "the rollback changed no data"
OUT="$(run --answer ROLLBACK --rollback)"
check "$(has "$OUT" 'nothing to roll back; running the rollback checks')$(fails "$OUT")$(backups)" "yes01" "4b rolling back again: says so, checks, takes no second backup"

echo; echo "== 5. a weakened copy of the script must be caught (control on the control) =="
# R1e blinded: its grep can no longer tell the tenant fix from the base. With the fix absent on the server, the
# weakened script still prints R1e's ok line — the mutation is detected.
MUT="$REPO/scripts/.mutant-r1e.sh"
python3 - "$DEPLOY" "$MUT" <<'PY'
import sys
src, dst = sys.argv[1], sys.argv[2]
s = open(src).read()
old = "grep -qF '$feSSP = dn_ssp_selectable($config ?? null)' \"$DEST/tabs/support/field_expenses.php\""
new = "grep -qF 'by_currency' \"$DEST/tabs/support/field_expenses.php\""
assert s.count(old) == 1, "R1e anchor not unique"
open(dst, 'w').write(s.replace(old, new))
PY
fresh; OUT="$(run --answer DEPLOY --script "$MUT")"          # a normal deploy first, so state-5.18.58.env exists
git -C "$REPO" show "$BASE:$P/tabs/support/field_expenses.php" > "$PD/tabs/support/field_expenses.php"   # then the fix is taken off
OUT="$(run --script "$MUT" --after-only)"
check "$(has "$OUT" 'ok    R1 5.18.58: the sales/support cash screens are tenant-aware')" "yes" "5a the R1e-blinded copy calls a missing tenant gate installed — the mutation is detected"
# the real script, same fault, catches it:
OUT="$(run --after-only)"
check "$(has "$OUT" 'FAIL  R1 5.18.58: the sales/support tenant-currency gate is NOT in the installed')" "yes" "5b …and the real script fails on the same fault"
rm -f "$MUT"; git -C "$REPO" show "$PIN:$P/tabs/support/field_expenses.php" > "$PD/tabs/support/field_expenses.php"

echo; echo "== 6. what the rehearsal left behind =="
check "$(checkout_state)" "$CHECKOUT0" "this checkout is as the rehearsal found it: same commit, same tracked files"
check "$(ls "$REPO/scripts"/.mutant-* "$REPO/scripts"/.unpinned.sh 2>/dev/null | wc -l | tr -d ' ')" "0" "no weakened copy is left in the clone"

echo; echo "rehearsal: $PASS passed, $FAILN failed ($(cat "$SB/runs") runs of the script)"
[ "$FAILN" = "0" ]
