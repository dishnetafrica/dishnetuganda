#!/usr/bin/env bash
# Rehearse scripts/deploy-5.18.66.sh — the deploy, its checks, and the ROLLBACK — before the operator runs any of it.
#
# 5.18.66 puts site photos and the completion location into My Jobs on Uganda (lib/JobPhotos.php, api_job_photos.php,
# migration 084, the job page, the viewer route, Photo Manager). It is deployed from a RELEASE COMMIT on release/5.18.66,
# cut on the live 5.18.65 (ce3fa91) — not from the branch tip, which also carries the undeployed partner-portal stack and
# the PD-8 CSRF guard. This rehearsal proves:
#   · the script runs end to end against a 5.18.65 base (with the pilot ON, as the live server has it) and PASSES;
#   · it installs exactly the right files — 18, four of them new, ONE migration (084), and no partner-portal or CSRF file
#     (A0 refuses otherwise); the pin's job page carries the completion form and the Uganda gate, the base's does not;
#   · a copy pinned to the BRANCH TIP (924cb6f, whose parent is not 5.18.65) is refused before anything is read — the
#     undeployed work cannot ride along by mistake;
#   · the deploy touches no configuration value — V3 and R2 read the LIVE pilot switch through the plugin's own tool before
#     and after and require them equal (on, as seeded) — and writes no table and no config itself (the data is
#     byte-identical right after it); the two tables are then created ADDITIVELY by the plugin's own first boot, empty,
#     and that boot changes no existing row (R3 lazy → present 0:0);
#   · V5: the photo viewer sends an anonymous visitor to sign in and the upload action answers 401 to nobody;
#   · R6 proves the headline: the photo surface is installed and wired to the Uganda gate;
#   · R4 still proves nothing can be sent — the pilot libs + tab are installed, webhook.php still draws exactly the two
#     flag-gated hooks, the bound channel is NullWhatsAppChannel and the Evolution adapter is constructed nowhere — and
#     catches a partner-portal file planted on the install;
#   · R1/R6 have TEETH — revert the installed job page to 5.18.65 and R1 names it AND R6 fails, twice over;
#   · R5 has TEETH — a changed Release-A/pilot file on the server is caught, by name;
#   · the switch checks have TEETH — turn the pilot off with the installed tool and --after-only reports pilot=off; turn it
#     on and it reads on again; either way the run PASSES;
#   · the rollback returns the plugin to 5.18.65, manifest 5.18.65, the job page without the photo code, the data untouched;
#     the rollback command is printed alone, after the verdict (root docs/44 §16.9);
#   · the base gate holds: a server on 5.18.64 is a NO-GO ("deploy 5.18.65 first"); a copy still carrying the placeholder
#     pin stops before anything is read;
#   · a weakened copy whose R1 byte-for-byte check is blinded is caught (control on the control): with the job page
#     reverted on the install the blinded copy still says R1 ok, while the real script FAILS R1, naming the file.
#
# Everything is real except the container and the public address (as the 5.18.65 rehearsal): a CLONE of this repo, the
# real git checkout and scripts/deploy-hybrid.sh, this machine's PHP for the server's, the installed plugin starting as
# 5.18.65 exactly (git archive), and a stand-in web server for stage V's pages (sign-in 200, portal 302, the photo viewer
# 302, the API 401). DEPLOY and ROLLBACK are typed through a pseudo-terminal. The one shim is DN_DATA_DIR for the
# installed tool's cliDataDir(), as before.
#
#   bash scripts/harness/deploy-5.18.66/rehearse.sh            REHEARSE_KEEP=<dir> keeps every run's full output
#
# It needs the release branch in this repository, the script pinned to its commit, and python3; it refuses to run where
# the server could be.
set -u
R="$(cd "$(dirname "$0")/../../.." && pwd)"
for f in /data/ucrm /opt/dishnet /var/run/docker.sock; do
  [ -e "$f" ] && { echo "refusing: $f exists — this looks like the server, and this rehearsal must never run there"; exit 2; }
done
BASE=ce3fa91; RA_BASE=e076632; OLDER=03df9a5; TIP=924cb6f; BRANCH_FILE=scripts/deploy-5.18.66.sh; P=dishnet-hybrid-sudan; RELEASE_BRANCH=release/5.18.66
checkout_state() { { git -C "$R" rev-parse HEAD; git -C "$R" status --porcelain --untracked-files=no; git -C "$R" diff HEAD; } | sha256sum | cut -c1-16; }
CHECKOUT0="$(checkout_state)"
SB="$(mktemp -d)"; PIDS=()
cleanup() { for p in "${PIDS[@]:-}"; do kill "$p" 2>/dev/null; done; rm -rf "$SB"; }
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
find "$REPO/dishnet-hybrid-sudan" -type f -exec touch -d "@$(git -C "$REPO" log -1 --format=%ct)" {} +
PIN="$(sed -n 's/^EXPECTED_PLUGIN_COMMIT="\([^"]*\)".*/\1/p' "$DEPLOY")"
echo "== 0. what is rehearsed =="
echo "  script    $WHICH, sha256 $(sha256sum "$DEPLOY" | cut -c1-16)"
echo "  clone     $BRANCH at $(git -C "$REPO" rev-parse --short HEAD)"
case "$PIN" in __*) echo "  FAIL the script still carries the placeholder pin ($PIN) — fill EXPECTED_PLUGIN_COMMIT with the release commit, then rehearse"; exit 2;; esac
REL="$(git -C "$REPO" rev-parse --short "origin/$RELEASE_BRANCH" 2>/dev/null || echo none)"
check "$PIN" "$REL" "the script is pinned to the release commit on $RELEASE_BRANCH ($PIN)"
check "$(git -C "$REPO" rev-parse --short "$PIN^" 2>/dev/null)" "$BASE" "control: the release commit is cut on $BASE (5.18.65), the live version"
check "$(git -C "$REPO" show "$PIN:$P/manifest.json" | python3 -c 'import json,sys; print(json.load(sys.stdin)["information"]["version"])')" "5.18.66" "the plugin at the pin is 5.18.66"
check "$(git -C "$REPO" show "$BASE:$P/manifest.json" | python3 -c 'import json,sys; print(json.load(sys.stdin)["information"]["version"])')" "5.18.65" "control: the base is 5.18.65"
dl() { git -C "$REPO" diff --no-renames --name-only --diff-filter="$1" "$2" "$3" -- $P; }
CHANGED="$(dl AM "$BASE" "$PIN")"
N_CH="$(grep -c . <<<"$CHANGED")"; N_AD="$(dl A "$BASE" "$PIN" | grep -c .)"; N_MO=$((N_CH - N_AD)); N_DEL="$(dl D "$BASE" "$PIN" | grep -c .)"
echo "  5.18.66   $N_CH files: $N_MO changed, $N_AD added · $N_DEL removed or renamed"
check "$N_DEL" "0" "control: 5.18.65 → 5.18.66 removes no file"
check "$N_AD" "4" "control: 5.18.66 adds four files (JobPhotos.php, api_job_photos.php, 084, test_job_photos.php)"
check "$N_CH" "18" "control: 18 files in the delta"
for want in "lib/JobPhotos.php" "includes/api/api_job_photos.php" "migrations/084_job_photos.sql" "tests/test_job_photos.php" \
            "includes/api/api_scheduling.php" "includes/api_handlers.php" "includes/routes.php" "tabs/support/scheduling.php" \
            "tabs/admin/photo_manager.php" "manifest.json" "tests/test_job_access.php" \
            "tests/fixtures/staff_jobs_sandbox.php" "tests/fixtures/staff_jobs_scenario.php" \
            "tests/test_distributor_registry.php" "tests/test_distributor_apply.php" "tests/test_distributor_territory.php" \
            "tests/test_distributor_notify.php" "tests/test_distributor_link_ucrm.php"; do
  check "$(grep -c "^$P/$want$" <<<"$CHANGED")" "1" "control: the delta includes $want"
done
check "$(grep -cE "^$P/(partner_api\.php|lib/Partner|lib/StaffApiCsrf\.php|lib/Totp\.php|lib/DistributorPortalData\.php|migrations/08[123]_)" <<<"$CHANGED")" "0" \
  "control: no partner-portal or CSRF file in the delta — the release carries none of the undeployed work"
check "$(grep -c '^'"$P"'/migrations/' <<<"$CHANGED")" "1" "control: exactly one migration in the delta"
check "$(grep -c '^'"$P"'/migrations/084_job_photos.sql$' <<<"$CHANGED")" "1" "control: …and it is 084_job_photos.sql"
check "$(git -C "$REPO" show "$PIN:$P/tabs/support/scheduling.php" | grep -c 'window.schOpenCompleteFormImpl=function')" "1" "control: the job page carries the completion form at the pin"
check "$(git -C "$REPO" show "$PIN:$P/tabs/support/scheduling.php" | grep -c 'var UG_PHOTOS = <?= $_sjUganda')" "1" "control: …behind the Uganda gate"
check "$(git -C "$REPO" show "$BASE:$P/tabs/support/scheduling.php" | grep -c 'UG_PHOTOS')" "0" "control: 5.18.65's job page has none of it (added by this release)"
check "$(git -C "$REPO" show "$PIN:$P/includes/api_handlers.php" | grep -c "api/api_job_photos.php")" "1" "control: api_handlers.php loads the photo API at the pin"
check "$(git -C "$REPO" show "$BASE:$P/includes/api_handlers.php" | grep -c "api/api_job_photos.php")" "0" "control: …and did not at 5.18.65"
check "$(git -C "$REPO" show "$PIN:$P/includes/api/api_scheduling.php" | grep -c 'JobPhotos::validateGps')" "1" "control: scheduling_complete carries the location rule at the pin"
check "$(git -C "$REPO" show "$PIN:$P/includes/api/api_scheduling.php" | grep -c 'job-comments')" "$(git -C "$REPO" show "$BASE:$P/includes/api/api_scheduling.php" | grep -c 'job-comments')" \
  "control: the pin posts no new uCRM comment — the same job-comments writes as 5.18.65"
check "$(git -C "$REPO" show "$PIN:$P/webhook.php" | grep -c 'DistributorEvents::maybeNotify')" "2" "control: webhook.php still draws the two draft hooks at the pin (regression)"
check "$(git -C "$REPO" show "$PIN:$P/lib/DistributorNotifier.php" | grep -c 'new NullWhatsAppChannel')" "1" "control: the notifier still binds the Null channel at the pin (regression)"
HEADER_CMD="$(sed -n '/^# Run as root/,/^# The rollback is a separate command/p' "$DEPLOY")"
check "$(grep -c 'deploy-5.18.66.sh 2>&1 | tee' <<<"$HEADER_CMD")$(grep -cE -- '--rollback|git checkout [0-9a-f]{7}' <<<"$HEADER_CMD")" "10" \
  "the header's deploy command stands alone: no rollback and no checkout of another commit in its block (docs/44 §16.9)"
check "$(grep -c "git fetch origin $RELEASE_BRANCH" <<<"$HEADER_CMD")" "1" "the header's deploy command fetches the release branch"

# ── The container: 5.18.65 installed, its data beside it ─────────────────────
MOUNT="$SB/mount"; PLUGINS="$MOUNT/ucrm/data/plugins"; PD="$PLUGINS/$P"; DATA="$PLUGINS/.$P-data"
VAULT="$PLUGINS/.dishnet-sudan.vault.json"
mkdir -p "$PD" "$DATA" "$SB/web" "$SB/bin" "$SB/out"
IN_CONTAINER="/data/ucrm/data/plugins/$P"
free_port() { python3 -c 'import socket;s=socket.socket();s.bind(("127.0.0.1",0));print(s.getsockname()[1]);s.close()'; }
WPORT="$(free_port)"; PLUGIN_BASE="http://127.0.0.1:$WPORT/public.php"
# The stand-in for the plugin's public address: what stage V's probes see on a healthy install.
cat > "$SB/web/public.php" <<'PHP'
<?php
$page = $_GET['page'] ?? '';
if ($page === 'customer_portal') { header('Location: ?page=customer_login', true, 302); exit; }
if ($page === 'job_photo') { header('Location: ?page=login', true, 302); exit; }                 // requireLogin(): a visitor is sent to sign in
if ($page === 'api') { http_response_code(401); header('Content-Type: application/json'); echo '{"status":"error","message":"Unauthorized."}'; exit; }   // the staff guard, before any handler
echo '<html><body>Sign in</body></html>';
PHP
php -S "127.0.0.1:$WPORT" -t "$SB/web" >/dev/null 2>&1 & PIDS+=($!)
for i in $(seq 1 50); do curl -s --noproxy '*' "$PLUGIN_BASE?page=customer_login" | grep -q 'Sign in' && break; sleep 0.1; done

install_base() {   # the installed plugin exactly as 5.18.65
  find "$PD" -mindepth 1 -maxdepth 1 ! -name ucrm.json -exec rm -rf {} +
  git -C "$REPO" archive "$BASE:$P" | tar -x -C "$PD"
  printf '%s\n' "$BASE" > "$PD/.deployed-commit"
  printf '%s\n' '[2026-10-03 06:00:01] [master] RUN staff_jobs' > "$PD/data/plugin.log" 2>/dev/null || true
}
printf '{"pluginDataDir":"/data/ucrm/data/plugins/.%s-data","ucrmPublicUrl":"http://127.0.0.1:1/crm"}' "$P" > "$PD/ucrm.json"
vault() { printf '{"config":{"currency_code":"%s"}}' "$1" > "$VAULT"; }
seed_data() {      # the plugin's databases, written by 5.18.65's own store; the pilot is enabled separately, through the real tool (flip --on)
  rm -rf "$DATA"/plugin.sqlite3* "$DATA"/dishnet.sqlite* "$DATA"/config.json "$DATA"/kyc_config.json "$DATA"/migration.log "$DATA"/uploads
  php -r '
    foreach (["bootstrap_data", "StoreInterface", "JsonStore", "SqliteStore"] as $l) require_once $argv[1] . "/lib/$l.php";
    $s = SqliteStore::create($argv[2]);
    $s->save("kyc_config.json", ["crm_base_url" => "http://127.0.0.1:1", "company_name" => "DishNet Sandbox", "currency_code" => "UGX"]);
  ' "$PD" "$DATA" >/dev/null
  php -r '$p = new PDO("sqlite:" . $argv[1]); $p->exec("PRAGMA journal_mode=WAL"); $p->exec("CREATE TABLE wa_messages(id INTEGER PRIMARY KEY, body TEXT)");
    for ($i = 0; $i < 10; $i++) $p->exec("INSERT INTO wa_messages(body) VALUES (\x27hello $i\x27)");' "$DATA/dishnet.sqlite"
}
boot_installed() { # one plugin request, as uCRM's first page load after the deploy: the installed tree's own store runs its migrations
  php -r 'foreach (["bootstrap_data", "StoreInterface", "JsonStore", "SqliteStore"] as $l) require_once $argv[1] . "/lib/$l.php"; SqliteStore::create($argv[2]);' "$PD" "$DATA" >/dev/null 2>&1
}
inst_ver() { grep -o '"version": *"5[^"]*"' "$PD/manifest.json" | head -1 | sed -E 's/.*"(5[^"]*)".*/\1/'; }
live() { tail -n1 "$PD/.deployed-commit" 2>/dev/null | tr -cd '0-9a-f'; }
backups() { ls -d "$SB/out"/backup-* 2>/dev/null | wc -l | tr -d ' '; }
dist_tables() { php -r '$p=new PDO("sqlite:".$argv[1]); $n=0; foreach(["dist_partners","dist_appointment","dist_regions","dist_territory_map","dist_customer_links","dist_contacts","dist_notify_consent","dist_notify_log"] as $t){ $q=$p->query("SELECT 1 FROM sqlite_master WHERE type=\x27table\x27 AND name=\x27$t\x27"); if($q->fetchColumn()) $n++; } echo $n;' "$DATA/plugin.sqlite3" 2>/dev/null || echo 0; }
photo_tables() { php -r '$p=new PDO("sqlite:".$argv[1]); $n=0; foreach(["job_photos","job_completion_gps"] as $t){ $q=$p->query("SELECT 1 FROM sqlite_master WHERE type=\x27table\x27 AND name=\x27$t\x27"); if($q->fetchColumn()) $n++; } echo $n;' "$DATA/plugin.sqlite3" 2>/dev/null || echo 0; }
_canon() { php -r 'function c($x){ if (is_array($x)) { ksort($x); foreach ($x as &$v) $v = c($v); } return $x; }
  echo json_encode(c(json_decode((string)@file_get_contents($argv[1]), true)));' "$1" 2>/dev/null; }
data_dump() {   # $1 = extra tables to leave out (space-separated), for a comparison across the plugin's own additive boot
  php -r '$p = new PDO("sqlite:" . $argv[1]); $skip = array_merge(["_migrations", "sqlite_sequence"], preg_split("/\s+/", trim($argv[2]), -1, PREG_SPLIT_NO_EMPTY));
      foreach ($p->query("SELECT name FROM sqlite_master WHERE type = \x27table\x27 ORDER BY name")->fetchAll(PDO::FETCH_COLUMN) as $t) {
        if (in_array($t, $skip, true)) continue;
        echo $t, ":", json_encode($p->query("SELECT * FROM [$t]")->fetchAll(PDO::FETCH_NUM)), "\n"; }' "$DATA/plugin.sqlite3" "${1:-}"
  echo "vault:$(_canon "$VAULT")"
  [ -f "$DATA/kyc_config.json" ] && echo "kyc:$(_canon "$DATA/kyc_config.json")"
  true
}
data_digest() { data_dump "${1:-}" | sha256sum | cut -c1-16; }
installed_digest() {   # $1 a commit: the number of files 5.18.66 touches that are NOT installed as that commit has them
  local c="$1" f rel bad=0
  for f in $CHANGED; do
    rel="${f#$P/}"
    if git -C "$REPO" cat-file -e "$c:$f" 2>/dev/null; then
      [ "$(git -C "$REPO" show "$c:$f" | sha256sum | cut -c1-64)" = "$(sha256sum "$PD/$rel" 2>/dev/null | cut -c1-64)" ] || bad=$((bad+1))
    fi
  done
  echo "$bad"
}
logline() { printf '%s.123456789Z %s\n' "$(date -u -d "@$1" +%Y-%m-%dT%H:%M:%S)" "$2" >> "$SB/container.log"; }
base_log() { local a="${1:-$(date -u +%s)}"; : > "$SB/container.log"; logline $((a - 60)) 'NOTICE: fpm is running, pid 1'; }
fresh() { rm -rf "$SB/out"; mkdir -p "$SB/out"; base_log; }
flip() { env PATH="$SB/bin:$PATH" DN_DATA_DIR="$DATA" bash "$SB/bin/docker" exec ucrm php "$IN_CONTAINER/tools/set_distributors.php" "$@" >/dev/null 2>&1; }

# ── The fake docker (verbatim shape from the 5.18.65 rehearsal) ──
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
exec "\${args[@]}"
SH
chmod +x "$SB/bin/docker"

run() {   # [--answer WORD] [--script FILE], then the script's own options — through a pseudo-terminal
  local answer="" script="$DEPLOY"
  while :; do case "${1:-}" in --answer) answer="$2"; shift 2 ;; --script) script="$2"; shift 2 ;; *) break ;; esac; done
  : > "$SB/docker.log"
  local n; n=$(( $(cat "$SB/runs" 2>/dev/null || echo 0) + 1 )); echo "$n" > "$SB/runs"
  local cmd; cmd="$(printf '%q ' env PATH="$SB/bin:$PATH" DNB_OUT="$SB/out" GUARD_SECONDS=0 DN_DATA_DIR="$DATA" bash "$script" --plugin-base "$PLUGIN_BASE" "$@")"
  local o; o="$(printf '%s\n' "$answer" | SHELL=/bin/bash script -qec "$cmd" /dev/null 2>&1 | tr -d '\r')"
  if [ -n "${REHEARSE_KEEP:-}" ]; then
    local label; label="$(printf '%s-%s%s' "${answer:-none}" "$(basename "$script" .sh)" "$(printf '%s' "$*" | tr -c 'A-Za-z0-9-' '_')")"
    printf '%s\n' "$o" > "$REHEARSE_KEEP/run-$(printf '%02d' "$n")-$label.txt"
  fi
  printf '%s' "$o"
}

install_base; seed_data; vault UGX; fresh
flip --on   # enable the pilot through the real tool (saveOverrides), exactly as the operator did on the live server
D0="$(data_digest)"
check "$(inst_ver)" "5.18.65" "control: the installed base is 5.18.65"
check "$(grep -c 'UG_PHOTOS' "$PD/tabs/support/scheduling.php")" "0" "control: the base job page has no photo code"
check "$(grep -c 'api/api_job_photos.php' "$PD/includes/api_handlers.php")" "0" "control: the base loads no photo API"
check "$(test -f "$PD/lib/JobPhotos.php" && echo yes || echo no)$(test -f "$PD/partner_api.php" && echo yes || echo no)" "nono" "control: the base has neither JobPhotos.php nor partner_api.php"
check "$(dist_tables)" "8" "control: the base plugin.sqlite3 already has the distributor tables (5.18.64's migrations, 8)"
check "$(photo_tables)" "0" "control: …and no photo table yet"
check "$(env PATH="$SB/bin:$PATH" DN_DATA_DIR="$DATA" bash "$SB/bin/docker" exec ucrm php "$IN_CONTAINER/tools/set_distributors.php" --show 2>/dev/null | awk '/distributors_enabled/ {print toupper($2); exit}')" "ON" "control: the pilot is ON on the base (enabled through the real tool, as the live server has it)"

echo; echo "== 1. NO-GO before anything changes =="
printf '%s\n' "$OLDER" > "$PD/.deployed-commit"; OUT="$(run --answer DEPLOY)"
check "$(has "$OUT" "STOP: NO-GO: the container serves $OLDER; 5.18.66 was built and tested against $BASE (5.18.65) — deploy 5.18.65 first (scripts/deploy-5.18.65.sh) and send its log")" "yes" \
  "1a the server still runs an older commit (5.18.64): NO-GO — 5.18.65 goes first"
check "$(live)$(backups)" "${OLDER}0" "…nothing deployed, no backup taken"
printf '%s\n' "$BASE" > "$PD/.deployed-commit"
sed 's/^EXPECTED_PLUGIN_COMMIT="[^"]*"/EXPECTED_PLUGIN_COMMIT="__PLUGIN_COMMIT__"/' "$DEPLOY" > "$REPO/scripts/.unpinned.sh"
fresh; OUT="$(run --answer DEPLOY --script "$REPO/scripts/.unpinned.sh")"; rm -f "$REPO/scripts/.unpinned.sh"
check "$(has "$OUT" 'STOP: this copy of the script is not pinned to a reviewed commit')$(live)$(backups)" "yes${BASE}0" \
  "1b a copy still carrying the placeholder pin: stops before anything is read"
# 1c — the branch tip, which carries the undeployed partner-portal and CSRF work, is NOT cut on 5.18.65: refused before any read.
sed "s/^EXPECTED_PLUGIN_COMMIT=\"[^\"]*\"/EXPECTED_PLUGIN_COMMIT=\"$TIP\"/" "$DEPLOY" > "$REPO/scripts/.tip.sh"
fresh; OUT="$(run --answer DEPLOY --script "$REPO/scripts/.tip.sh")"; rm -f "$REPO/scripts/.tip.sh"
check "$(has "$OUT" "STOP: $TIP is not cut on $BASE (5.18.65): its parent is")$(has "$OUT" 'this script is for another build')" "yesyes" \
  "1c a copy pinned to the branch tip ($TIP, parent not 5.18.65): refused — the undeployed work cannot ride along"
check "$(live)$(backups)$(cnt "$OUT" 'serves ')" "${BASE}00" "…before the container was even looked at: nothing deployed, no backup, no live read"

echo; echo "== 2. the deploy, as the operator runs it =="
fresh; OUT="$(run --answer DEPLOY)"
check "$(fails "$OUT")" "0" "no FAIL line"
check "$(has "$OUT" '5.18.66 (deploy): PASSED')" "yes" "PASSED"
for l in "ok    A0 the release delta is the photo work only — no partner-portal or CSRF file among the $N_CH" \
         "ok    A2 PHP" \
         "container GD    yes" \
         "— one consistent copy, integrity ok" "ok    backed up the installed plugin (5.18.65, $BASE)" "ok    backed up the configuration vault" \
         "GO — evidence recorded" \
         "checking out $PIN (5.18.66, the release commit) for the documented deploy" \
         "ok    container serves $PIN" \
         "ok    V1 the sign-in page on the public address answers 200 with zero redirects (no loop)" \
         "ok    V1 the portal without a session still refuses" \
         "ok    V2 the sign-in page answers 200 and carries no South Sudan contact" \
         "ok    V5 the photo viewer without a session refuses (302) — a photo is never served to nobody" \
         "ok    V5 job_photo_upload without a login answers 401 — the staff guard, before any handler" \
         "ok    V3 the pilot switch is unchanged by the deploy (before=pilot=on, after=pilot=on)" \
         "the pilot reads on" \
         "ok    V4 no fatal or parse error of $P in the container log since" \
         "ok    R1 all $N_CH files 5.18.66 changes are installed exactly as $PIN has them ($N_AD new)" \
         "ok    R1 the installed manifest says 5.18.66" \
         "ok    R2 the installed set_distributors --show reports pilot=on" \
         "note  R3 migration 084 is installed; job_photos / job_completion_gps are not in plugin.sqlite3 yet" \
         "uploads/job_photos does not exist in the data directory yet" \
         "ok    R4 the pilot libs + the Distributors tab are installed as before" \
         "no partner-portal or CSRF file is installed" \
         "ok    R5 all" \
         "ok    R6 the photo surface is installed" \
         "what changed      My Jobs (Uganda): a Photos card" \
         "what did not      South Sudan (byte for byte)" \
         "try it            on a phone"; do
  check "$(has "$OUT" "$l")" "yes" "2: ${l:0:96}"
done
check "$(cnt "$OUT" 'ok    backed up ')" "5" "five backups: two databases, the data directory, the installed plugin, the vault"
check "$(live)$(installed_digest "$PIN")" "${PIN}0" "the container serves $PIN: every changed file exactly as the commit has it"
check "$(inst_ver)" "5.18.66" "the installed manifest is 5.18.66"
check "$(grep -c 'window.schOpenCompleteFormImpl=function' "$PD/tabs/support/scheduling.php")" "1" "the installed job page now carries the completion form"
check "$(test -f "$PD/lib/JobPhotos.php" && echo yes || echo no)$(test -f "$PD/partner_api.php" && echo yes || echo no)$(test -f "$PD/lib/StaffApiCsrf.php" && echo yes || echo no)" "yesnono" \
  "JobPhotos.php is installed; partner_api.php and StaffApiCsrf.php are NOT — the undeployed work stayed on the branch"
check "$(data_digest)" "$D0" "the deploy itself wrote no table and no configuration value"
check "$(photo_tables)" "0" "…the two tables are not created by the copy (the plugin creates them on its next request)"
check "$(grep -c "cd $REPO && bash scripts/deploy-5.18.66.sh --rollback" <<<"$OUT")" "1" "the rollback command is printed once, on its own line, never beside the deploy"
check "$(awk '/PASSED\. Send this LOG FILE back/ {p=1} p && /deploy-5\.18\.66\.sh --rollback/ {print "after"; exit}' <<<"$OUT")" "after" \
  "…the rollback block comes after the verdict, at the end of the log, never in the block the operator pasted"
BK="$(ls -d "$SB/out"/backup-* | tail -1)"
check "$(tar -xzOf "$BK"/plugin-installed-5.18.65.tar.gz $P/manifest.json | grep -c '"version": "5.18.65"')" "1" "the code backup is 5.18.65"
check "$(tar -xzOf "$BK"/plugin-installed-5.18.65.tar.gz $P/tabs/support/scheduling.php | grep -c 'UG_PHOTOS')" "0" \
  "…and the backup's job page has no photo code: a rollback restores the pre-photo code exactly"

echo; echo "== 2b. the plugin's first request after the deploy: migration 084 applies additively =="
boot_installed
OUT="$(run --after-only)"
check "$(fails "$OUT")$(has "$OUT" '5.18.66 (after): PASSED')" "0yes" "--after-only PASSES"
check "$(has "$OUT" 'ok    R3 migration 084 is installed and its two tables exist on the live plugin.sqlite3 — job_photos 0:0 rows')" "yes" "R3 now reads the two tables present, both empty (0 photos, 0 locations)"
check "$(photo_tables)" "2" "job_photos and job_completion_gps exist"
check "$(data_digest 'job_photos job_completion_gps')" "$D0" "…and the boot changed no existing row: everything else is byte-identical to before the deploy"
check "$(dist_tables)" "8" "…the distributor tables are unchanged (still 8)"

echo; echo "== 3. R1/R6 and R5 have teeth: a changed file reverted on the server is caught =="
git -C "$REPO" show "$BASE:$P/tabs/support/scheduling.php" > "$PD/tabs/support/scheduling.php"   # revert the job page to 5.18.65 (no photo code)
OUT="$(run --after-only)"
check "$(has "$OUT" "FAIL  R1 installed files that differ: tabs/support/scheduling.php")" "yes" "3a the job page reverted on the server: R1 fails, naming it"
check "$(has "$OUT" 'FAIL  R6 the photo surface is incomplete:')$(has "$OUT" 'job-page:no-completion-form')$(has "$OUT" 'job-page:no-uganda-gate')" "yesyesyes" "3b …and R6 fails too, naming the completion form and the gate"
git -C "$REPO" show "$PIN:$P/tabs/support/scheduling.php" > "$PD/tabs/support/scheduling.php"   # put it back
printf '\n// changed on the server\n' >> "$PD/includes/navigation.php"; OUT="$(run --after-only)"
check "$(has "$OUT" "FAIL  R5 files from Release A→5.18.65 differ on the server: includes/navigation.php")" "yes" "3c a Release-A/regression file changed on the server: R5 fails, naming it"
git -C "$REPO" show "$PIN:$P/includes/navigation.php" > "$PD/includes/navigation.php"
OUT="$(run --after-only)"
check "$(fails "$OUT")$(has "$OUT" '5.18.66 (after): PASSED')" "0yes" "3d each fault removed: --after-only PASSES again"

echo; echo "== 4. V3/R2 have teeth: the switch checks read the LIVE pilot state =="
flip --off
OUT="$(run --after-only)"
check "$(has "$OUT" 'the pilot reads off')" "yes" "4a pilot turned off by the operator: V3 reports it (reads off)"
check "$(has "$OUT" "ok    R2 the installed set_distributors --show reports pilot=off")" "yes" "4b …and R2 reports pilot=off — the check reads the live state, not a constant"
check "$(fails "$OUT")$(has "$OUT" '5.18.66 (after): PASSED')" "0yes" "4c …and it still PASSES: the switch state is the operator's own, never a FAIL"
flip --on
OUT="$(run --after-only)"
check "$(has "$OUT" 'the pilot reads on')$(has "$OUT" 'ok    R2 the installed set_distributors --show reports pilot=on')$(fails "$OUT")" "yesyes0" "4d pilot turned back on: V3/R2 read on again, PASSES"

echo; echo "== 4e. R4 has teeth: a live channel, or a partner-portal file, on the install is caught =="
printf "\n\$x = new EvolutionWhatsAppChannel(\$evo, 'sales'); // planted\n" >> "$PD/webhook.php"
OUT="$(run --after-only)"
check "$(has "$OUT" 'FAIL  R4 the installed pilot is incomplete, binds a live channel, or carries files not in this release:')" "yes" "4e a live channel planted in webhook.php: R4 FAILS"
check "$(has "$OUT" 'live-channel-bound')" "yes" "…naming it"
git -C "$REPO" show "$PIN:$P/webhook.php" > "$PD/webhook.php"   # put the real webhook back
printf '<?php // planted: a portal file that is not in this release\n' > "$PD/partner_api.php"
OUT="$(run --after-only)"
check "$(has "$OUT" 'partner_api.php:present(not-in-this-release)')" "yes" "4f a partner-portal file planted on the install: R4 FAILS, naming it"
rm -f "$PD/partner_api.php"
OUT="$(run --after-only)"
check "$(fails "$OUT")$(has "$OUT" 'ok    R4 the pilot libs')" "0yes" "4g both removed: R4 passes again"

echo; echo "== 5. the rollback: --rollback, typed ROLLBACK =="
D1="$(data_digest)"; fresh; OUT="$(run --answer ROLLBACK --rollback)"
check "$(fails "$OUT")$(has "$OUT" '5.18.66 (rollback): PASSED')" "0yes" "PASSED"
for l in "GO — evidence recorded" "checking out $BASE (5.18.65) for the documented deploy" "ok    container serves $BASE (5.18.65)" \
         "ok    RB the installed manifest says 5.18.65" \
         "ok    RB the installed job page is 5.18.65's again (no Photos card, notes-only completion)" \
         "note  RB the code is 5.18.65 again"; do
  check "$(has "$OUT" "$l")" "yes" "5: ${l:0:90}"
done
check "$(has "$OUT" "ok    backed up the installed plugin (5.18.66, $PIN)")" "yes" "the backup first — of 5.18.66's code"
check "$(live)$(installed_digest "$BASE")" "${BASE}0" "the container serves $BASE: every changed file as 5.18.65 has it"
check "$(inst_ver)" "5.18.65" "the installed manifest is 5.18.65 again"
check "$(grep -c 'UG_PHOTOS' "$PD/tabs/support/scheduling.php")" "0" "the installed job page has no photo code again (rolled back)"
check "$(data_digest)" "$D1" "the rollback changed no data (it restores code only; the two tables stay, empty and unread)"

echo; echo "== 6. a weakened copy of the script must be caught (control on the control) =="
install_base; seed_data; vault UGX; fresh; flip --on; OUT="$(run --answer DEPLOY)"; check "$(fails "$OUT")" "0" "control: re-deployed 5.18.66 for the mutant test"
MUT="$REPO/scripts/.mutant-r1.sh"
python3 - "$DEPLOY" "$MUT" <<'PY'
import sys
src, dst = sys.argv[1], sys.argv[2]
s = open(src).read()
# Blind R1's byte-for-byte comparison so every changed file counts as installed.
old = 'if [ -n "$b" ] && [ "$a" = "$b" ]; then R_OK=$((R_OK+1)); else R_BAD="$R_BAD $rel"; fi'
new = 'R_OK=$((R_OK+1))'
assert s.count(old) == 1, "R1 anchor not unique"
s = s.replace(old, new)
open(dst, 'w').write(s)
PY
git -C "$REPO" show "$BASE:$P/tabs/support/scheduling.php" > "$PD/tabs/support/scheduling.php"   # the job page reverted on the install
OUT="$(run --script "$MUT" --after-only)"
check "$(has "$OUT" "ok    R1 all $N_CH files 5.18.66 changes are installed exactly as $PIN has them")" "yes" "6a the R1-blinded copy calls a reverted job page installed — the mutation is detected"
OUT="$(run --after-only)"
check "$(has "$OUT" 'FAIL  R1 installed files that differ: tabs/support/scheduling.php')" "yes" "6b …and the real script FAILS R1 on the same fault, naming the file"
rm -f "$MUT"; git -C "$REPO" show "$PIN:$P/tabs/support/scheduling.php" > "$PD/tabs/support/scheduling.php"   # put the real job page back

echo; echo "== 7. what the rehearsal left behind =="
check "$(checkout_state)" "$CHECKOUT0" "this checkout is as the rehearsal found it: same commit, same tracked files"
check "$(ls "$REPO/scripts"/.mutant-* "$REPO/scripts"/.unpinned.sh "$REPO/scripts"/.tip.sh 2>/dev/null | wc -l | tr -d ' ')" "0" "no weakened copy is left in the clone"

echo; echo "rehearsal: $PASS passed, $FAILN failed ($(cat "$SB/runs") runs of the script)"
[ "$FAILN" = "0" ]
