#!/usr/bin/env bash
# Rehearse scripts/deploy-5.18.65.sh — the deploy, its checks, and the ROLLBACK — before the operator runs any of it.
#
# 5.18.65 is ONE UI change over 5.18.64: a "Distributors" link in the left sidebar (the Admin section of
# includes/navigation.php), gated exactly as the tab and the module entry already are — admin-only, Uganda-only
# (StaffJobsGate, fail-closed), and behind `distributors_enabled` (OFF by default). It adds no migration, no table, no
# behaviour; it turns nothing on and sends nothing; it does not touch the pilot switch. This rehearsal proves:
#   · the script runs end to end against a 5.18.64 base (with the pilot ON, as the live server has it) and PASSES;
#   · it changes exactly the right files — includes/navigation.php, manifest.json and five distributor test files (pins),
#     and NO migration (control: zero migrations in the delta, unlike 5.18.64's three);
#   · the deploy does NOT touch the pilot switch — V3 and R2 read the LIVE switch through the plugin's own tool before and
#     after and require them equal; with the base seeded ON they both report pilot=on, unchanged; and the deploy writes
#     no table and no config (the data is byte-identical right after it);
#   · R6 proves the headline: the Distributors link is installed in the sidebar behind its admin+Uganda+flag gate;
#   · R4 still proves nothing can be sent — the pilot libs + tab are installed, webhook.php still draws exactly the two
#     flag-gated hooks, the bound channel is NullWhatsAppChannel and the Evolution adapter is constructed nowhere;
#   · the switch checks have TEETH — turn the pilot off with the installed tool and --after-only reports pilot=off; turn
#     it on and it reads on again (the check reads the live state, not a constant), and either way the run PASSES;
#   · R1/R6 have TEETH — revert the installed navigation.php to 5.18.64 (no link) and R1 names it AND R6 fails;
#   · R5 has TEETH — a changed Release-A/pilot file on the server is caught, by name;
#   · R1 installs the whole delta byte-for-byte and the manifest reads 5.18.65; R5 confirms Release A (5.18.49)→5.18.64 —
#     the whole distributor pilot included — are installed as pinned;
#   · the rollback returns the plugin to 5.18.64, manifest 5.18.64, the config untouched (the pilot stays as it was); the
#     rollback command is printed alone, after the verdict (root docs/44 §16.9);
#   · the base gate holds: a server on 5.18.60 is a NO-GO ("deploy 5.18.64 first"); a copy still carrying the placeholder
#     pin stops before anything is read;
#   · a weakened copy whose R4 hook check is blinded is caught (control on the control): with a live channel planted in the
#     installed webhook.php the blinded copy still says R4 ok, while the real script FAILS R4, naming it.
#
# Everything is real except the container and the public address (as the 5.18.64 rehearsal): a CLONE of this repo, the
# real git checkout and scripts/deploy-hybrid.sh, this machine's PHP for the server's, the installed plugin starting as
# 5.18.64 exactly (git archive), and a stand-in web server for stage V's customer pages. DEPLOY and ROLLBACK are typed
# through a pseudo-terminal. The one shim is DN_DATA_DIR for the installed tool's cliDataDir(), as in the 5.18.64 rehearsal.
#
#   bash scripts/harness/deploy-5.18.65/rehearse.sh            REHEARSE_KEEP=<dir> keeps every run's full output
#
# It needs the script pinned to this checkout's plugin commit, and python3; it refuses to run where the server could be.
set -u
R="$(cd "$(dirname "$0")/../../.." && pwd)"
for f in /data/ucrm /opt/dishnet /var/run/docker.sock; do
  [ -e "$f" ] && { echo "refusing: $f exists — this looks like the server, and this rehearsal must never run there"; exit 2; }
done
BASE=03df9a5; RA_BASE=e076632; OLDER=3b5e61f; BRANCH_FILE=scripts/deploy-5.18.65.sh; P=dishnet-hybrid-sudan
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
case "$PIN" in __*) echo "  FAIL the script still carries the placeholder pin ($PIN) — fill EXPECTED_PLUGIN_COMMIT with the plugin commit, then rehearse"; exit 2;; esac
check "$PIN" "$(git -C "$REPO" log -1 --format=%h -- $P)" "the script is pinned to the clone's plugin commit ($PIN)"
check "$(python3 -c 'import json,sys; print(json.load(open(sys.argv[1]))["information"]["version"])' "$REPO/$P/manifest.json")" "5.18.65" "the plugin is 5.18.65"
dl() { git -C "$REPO" diff --no-renames --name-only --diff-filter="$1" "$2" "$3" -- $P; }
CHANGED="$(dl AM "$BASE" "$PIN")"
N_CH="$(grep -c . <<<"$CHANGED")"; N_AD="$(dl A "$BASE" "$PIN" | grep -c .)"; N_MO=$((N_CH - N_AD)); N_DEL="$(dl D "$BASE" "$PIN" | grep -c .)"
echo "  5.18.65   $N_CH files: $N_MO changed, $N_AD added · $N_DEL removed or renamed"
check "$N_DEL" "0" "control: 5.18.64 → 5.18.65 removes no file"
check "$N_AD" "0" "control: 5.18.64 → 5.18.65 adds no new file (code only, in place)"
for want in "includes/navigation.php" "manifest.json" \
            "tests/test_distributor_registry.php" "tests/test_distributor_apply.php" "tests/test_distributor_territory.php" \
            "tests/test_distributor_notify.php" "tests/test_distributor_link_ucrm.php"; do
  check "$(grep -c "^$P/$want$" <<<"$CHANGED")" "1" "control: the delta includes $want"
done
# Unlike 5.18.64 (three migrations), 5.18.65 adds NONE — it is a sidebar link and version pins only.
check "$(dl AM "$BASE" "$PIN" | grep -c '^'"$P"'/migrations/')" "0" "control: 5.18.65 adds no migration"
# The sidebar link + its gate, at the pin, and that 5.18.64 did NOT have it (what R1/R6 verify on the install).
check "$(git -C "$REPO" show "$PIN:$P/includes/navigation.php" | grep -c 'tab=distributors')" "1" "control: navigation.php carries the Distributors link at the pin"
check "$(git -C "$REPO" show "$BASE:$P/includes/navigation.php" | grep -c 'tab=distributors')" "0" "control: 5.18.64's navigation.php had no Distributors link (added by this release)"
check "$(git -C "$REPO" show "$PIN:$P/includes/navigation.php" | grep -B8 'tab=distributors' | grep -c 'empty(.config')" "1" "control: the link's distributors_enabled gate (the !empty code, not the comment) sits just before it at the pin"
# The pilot's draft hooks and Null-channel binding are a REGRESSION check for 5.18.65 (unchanged since 5.18.64).
check "$(git -C "$REPO" show "$PIN:$P/webhook.php" | grep -c 'DistributorEvents::maybeNotify')" "2" "control: webhook.php still draws the two draft hooks at the pin (regression)"
check "$(git -C "$REPO" show "$PIN:$P/lib/DistributorNotifier.php" | grep -c 'new NullWhatsAppChannel')" "1" "control: the notifier still binds the Null channel at the pin (regression)"
HEADER_CMD="$(sed -n '/^# Run as root/,/^# The rollback is a separate command/p' "$DEPLOY")"
check "$(grep -c 'deploy-5.18.65.sh 2>&1 | tee' <<<"$HEADER_CMD")$(grep -cE -- '--rollback|git checkout [0-9a-f]{7}' <<<"$HEADER_CMD")" "10" \
  "the header's deploy command stands alone: no rollback and no checkout of another commit in its block (docs/44 §16.9)"

# ── The container: 5.18.64 installed, its data beside it ─────────────────────
MOUNT="$SB/mount"; PLUGINS="$MOUNT/ucrm/data/plugins"; PD="$PLUGINS/$P"; DATA="$PLUGINS/.$P-data"
VAULT="$PLUGINS/.dishnet-sudan.vault.json"
mkdir -p "$PD" "$DATA" "$SB/web" "$SB/bin" "$SB/out"
IN_CONTAINER="/data/ucrm/data/plugins/$P"
free_port() { python3 -c 'import socket;s=socket.socket();s.bind(("127.0.0.1",0));print(s.getsockname()[1]);s.close()'; }
WPORT="$(free_port)"; PLUGIN_BASE="http://127.0.0.1:$WPORT/public.php"
cat > "$SB/web/public.php" <<'PHP'
<?php
$page = $_GET['page'] ?? '';
if ($page === 'customer_portal') { header('Location: ?page=customer_login', true, 302); exit; }
echo '<html><body>Sign in</body></html>';
PHP
php -S "127.0.0.1:$WPORT" -t "$SB/web" >/dev/null 2>&1 & PIDS+=($!)
for i in $(seq 1 50); do curl -s --noproxy '*' "$PLUGIN_BASE?page=customer_login" | grep -q 'Sign in' && break; sleep 0.1; done

install_base() {   # the installed plugin exactly as 5.18.64
  find "$PD" -mindepth 1 -maxdepth 1 ! -name ucrm.json -exec rm -rf {} +
  git -C "$REPO" archive "$BASE:$P" | tar -x -C "$PD"
  printf '%s\n' "$BASE" > "$PD/.deployed-commit"
  printf '%s\n' '[2026-10-01 06:00:01] [master] RUN staff_jobs' > "$PD/data/plugin.log" 2>/dev/null || true
}
printf '{"pluginDataDir":"/data/ucrm/data/plugins/.%s-data","ucrmPublicUrl":"http://127.0.0.1:1/crm"}' "$P" > "$PD/ucrm.json"
vault() { printf '{"config":{"currency_code":"%s"}}' "$1" > "$VAULT"; }
seed_data() {      # the plugin's databases, written by 5.18.64's own store; the pilot is enabled separately, through the real tool (flip --on)
  rm -f "$DATA"/plugin.sqlite3* "$DATA"/dishnet.sqlite* "$DATA"/config.json "$DATA"/kyc_config.json "$DATA"/migration.log
  php -r '
    foreach (["bootstrap_data", "StoreInterface", "JsonStore", "SqliteStore"] as $l) require_once $argv[1] . "/lib/$l.php";
    $s = SqliteStore::create($argv[2]);
    $s->save("kyc_config.json", ["crm_base_url" => "http://127.0.0.1:1", "company_name" => "DishNet Sandbox", "currency_code" => "UGX"]);
  ' "$PD" "$DATA" >/dev/null
  php -r '$p = new PDO("sqlite:" . $argv[1]); $p->exec("PRAGMA journal_mode=WAL"); $p->exec("CREATE TABLE wa_messages(id INTEGER PRIMARY KEY, body TEXT)");
    for ($i = 0; $i < 10; $i++) $p->exec("INSERT INTO wa_messages(body) VALUES (\x27hello $i\x27)");' "$DATA/dishnet.sqlite"
}
inst_ver() { grep -o '"version": *"5[^"]*"' "$PD/manifest.json" | head -1 | sed -E 's/.*"(5[^"]*)".*/\1/'; }
live() { tail -n1 "$PD/.deployed-commit" 2>/dev/null | tr -cd '0-9a-f'; }
backups() { ls -d "$SB/out"/backup-* 2>/dev/null | wc -l | tr -d ' '; }
dist_tables() { php -r '$p=new PDO("sqlite:".$argv[1]); $n=0; foreach(["dist_partners","dist_appointment","dist_regions","dist_territory_map","dist_customer_links","dist_contacts","dist_notify_consent","dist_notify_log"] as $t){ $q=$p->query("SELECT 1 FROM sqlite_master WHERE type=\x27table\x27 AND name=\x27$t\x27"); if($q->fetchColumn()) $n++; } echo $n;' "$DATA/plugin.sqlite3" 2>/dev/null || echo 0; }
_canon() { php -r 'function c($x){ if (is_array($x)) { ksort($x); foreach ($x as &$v) $v = c($v); } return $x; }
  echo json_encode(c(json_decode((string)@file_get_contents($argv[1]), true)));' "$1" 2>/dev/null; }
data_dump() {
  php -r '$p = new PDO("sqlite:" . $argv[1]); $skip = ["_migrations", "sqlite_sequence"];
      foreach ($p->query("SELECT name FROM sqlite_master WHERE type = \x27table\x27 ORDER BY name")->fetchAll(PDO::FETCH_COLUMN) as $t) {
        if (in_array($t, $skip, true)) continue;
        echo $t, ":", json_encode($p->query("SELECT * FROM [$t]")->fetchAll(PDO::FETCH_NUM)), "\n"; }' "$DATA/plugin.sqlite3"
  echo "vault:$(_canon "$VAULT")"
  [ -f "$DATA/kyc_config.json" ] && echo "kyc:$(_canon "$DATA/kyc_config.json")"
  true
}
data_digest() { data_dump | sha256sum | cut -c1-16; }
installed_digest() {   # $1 a commit: the number of files 5.18.65 touches that are NOT installed as that commit has them
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

# ── The fake docker (verbatim shape from the 5.18.64 rehearsal) ──
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
check "$(inst_ver)" "5.18.64" "control: the installed base is 5.18.64"
check "$(git -C "$REPO" cat-file -e "$BASE:$P/includes/navigation.php" 2>/dev/null && echo yes || echo no)" "yes" "control: the base install has a navigation.php"
check "$(grep -c 'tab=distributors' "$PD/includes/navigation.php")" "0" "control: the base navigation.php has no Distributors sidebar link"
check "$(dist_tables)" "8" "control: the base plugin.sqlite3 already has the six distributor tables (5.18.64's migrations, 8 with the two from 078)"
check "$(env PATH="$SB/bin:$PATH" DN_DATA_DIR="$DATA" bash "$SB/bin/docker" exec ucrm php "$IN_CONTAINER/tools/set_distributors.php" --show 2>/dev/null | awk '/distributors_enabled/ {print toupper($2); exit}')" "ON" "control: the pilot is ON on the base (enabled through the real tool, as the live server has it)"

echo; echo "== 1. NO-GO before anything changes =="
printf '%s\n' "$OLDER" > "$PD/.deployed-commit"; OUT="$(run --answer DEPLOY)"
check "$(has "$OUT" "STOP: NO-GO: the container serves $OLDER; 5.18.65 was built and tested against $BASE (5.18.64) — deploy 5.18.64 first (scripts/deploy-5.18.64.sh) and send its log")" "yes" \
  "1a the server still runs an older commit (5.18.60): NO-GO — 5.18.64 goes first"
check "$(live)$(backups)" "${OLDER}0" "…nothing deployed, no backup taken"
printf '%s\n' "$BASE" > "$PD/.deployed-commit"
sed 's/^EXPECTED_PLUGIN_COMMIT="[^"]*"/EXPECTED_PLUGIN_COMMIT="__PLUGIN_COMMIT__"/' "$DEPLOY" > "$REPO/scripts/.unpinned.sh"
fresh; OUT="$(run --answer DEPLOY --script "$REPO/scripts/.unpinned.sh")"; rm -f "$REPO/scripts/.unpinned.sh"
check "$(has "$OUT" 'STOP: this copy of the script is not pinned to a reviewed commit')$(live)$(backups)" "yes${BASE}0" \
  "1b a copy still carrying the placeholder pin: stops before anything is read"

echo; echo "== 2. the deploy, as the operator runs it =="
fresh; OUT="$(run --answer DEPLOY)"
check "$(fails "$OUT")" "0" "no FAIL line"
check "$(has "$OUT" '5.18.65 (deploy): PASSED')" "yes" "PASSED"
for l in "ok    A2 PHP" \
         "— one consistent copy, integrity ok" "ok    backed up the installed plugin (5.18.64, $BASE)" "ok    backed up the configuration vault" \
         "GO — evidence recorded" \
         "ok    container serves $PIN" \
         "ok    V1 the sign-in page on the public address answers 200 with zero redirects (no loop)" \
         "ok    V1 the portal without a session still refuses" \
         "ok    V2 the sign-in page answers 200 and carries no South Sudan contact" \
         "ok    V3 the pilot switch is unchanged by the deploy (before=pilot=on, after=pilot=on)" \
         "the pilot reads on" \
         "ok    V4 no fatal or parse error of $P in the container log since" \
         "ok    R1 all $N_CH files 5.18.65 changes are installed exactly as $PIN has them ($N_AD new)" \
         "ok    R1 the installed manifest says 5.18.65" \
         "ok    R2 the installed set_distributors --show reports pilot=on" \
         "ok    R3 5.18.65 adds no migration; the pilot's three (078/079/080) are still installed" \
         "ok    R4 the pilot libs + the Distributors tab are installed" \
         "ok    R5 all" \
         "ok    R6 the Distributors link is installed in the left sidebar" \
         "what changed      the Distributors link now appears in the left sidebar" \
         "find it           Admin → Distributors in the left sidebar" \
         "nothing sent      the pilot itself is unchanged"; do
  check "$(has "$OUT" "$l")" "yes" "2: ${l:0:90}"
done
check "$(cnt "$OUT" 'ok    backed up ')" "5" "five backups: two databases, the data directory, the installed plugin, the vault"
check "$(live)$(installed_digest "$PIN")" "${PIN}0" "the container serves $PIN: every changed file exactly as the commit has it"
check "$(inst_ver)" "5.18.65" "the installed manifest is 5.18.65"
check "$(grep -c 'tab=distributors' "$PD/includes/navigation.php")" "1" "the installed navigation.php now carries the Distributors sidebar link"
check "$(data_digest)" "$D0" "the deploy wrote no table and no configuration value (no migration; code only)"
check "$(dist_tables)" "8" "…and the distributor tables are unchanged (still 8 — 5.18.65 adds none)"
check "$(grep -c "cd $REPO && bash scripts/deploy-5.18.65.sh --rollback" <<<"$OUT")" "1" "the rollback command is printed once, on its own line, never beside the deploy"
check "$(awk '/PASSED\. Send this LOG FILE back/ {p=1} p && /deploy-5\.18\.65\.sh --rollback/ {print "after"; exit}' <<<"$OUT")" "after" \
  "…the rollback block comes after the verdict, at the end of the log, never in the block the operator pasted"
BK="$(ls -d "$SB/out"/backup-* | tail -1)"
check "$(tar -xzOf "$BK"/plugin-installed-5.18.64.tar.gz $P/manifest.json | grep -c '"version": "5.18.64"')" "1" "the code backup is 5.18.64"
check "$(tar -xzOf "$BK"/plugin-installed-5.18.64.tar.gz $P/includes/navigation.php | grep -c 'tab=distributors')" "0" \
  "…and the backup's navigation.php has no Distributors link: a rollback restores the pre-link code exactly"

echo; echo "== 3. R1/R6 and R5 have teeth: a changed file reverted on the server is caught =="
git -C "$REPO" show "$BASE:$P/includes/navigation.php" > "$PD/includes/navigation.php"   # revert the sidebar file to 5.18.64 (no link)
OUT="$(run --after-only)"
check "$(has "$OUT" "FAIL  R1 installed files that differ: includes/navigation.php")" "yes" "3a navigation.php reverted on the server: R1 fails, naming it"
check "$(has "$OUT" 'FAIL  R6 the Distributors sidebar link (tab=distributors) is missing')" "yes" "3b …and R6 fails too: the link is gone from the installed sidebar"
git -C "$REPO" show "$PIN:$P/includes/navigation.php" > "$PD/includes/navigation.php"   # put it back
printf '\n// changed on the server\n' >> "$PD/includes/routes.php"; OUT="$(run --after-only)"
check "$(has "$OUT" "FAIL  R5 files from Release A→5.18.64 differ on the server: includes/routes.php")" "yes" "3c a Release-A/regression file changed on the server: R5 fails, naming it"
git -C "$REPO" show "$PIN:$P/includes/routes.php" > "$PD/includes/routes.php"
OUT="$(run --after-only)"
check "$(fails "$OUT")$(has "$OUT" '5.18.65 (after): PASSED')" "0yes" "3d each fault removed: --after-only PASSES again"

echo; echo "== 4. V3/R2 have teeth: the switch checks read the LIVE pilot state =="
flip --off
OUT="$(run --after-only)"
check "$(has "$OUT" 'the pilot reads off')" "yes" "4a pilot turned off by the operator: V3 reports it (reads off)"
check "$(has "$OUT" "ok    R2 the installed set_distributors --show reports pilot=off")" "yes" "4b …and R2 reports pilot=off — the check reads the live state, not a constant"
check "$(fails "$OUT")$(has "$OUT" '5.18.65 (after): PASSED')" "0yes" "4c …and it still PASSES: the switch state is the operator's own, never a FAIL"
flip --on
OUT="$(run --after-only)"
check "$(has "$OUT" 'the pilot reads on')$(has "$OUT" 'ok    R2 the installed set_distributors --show reports pilot=on')$(fails "$OUT")" "yesyes0" "4d pilot turned back on: V3/R2 read on again, PASSES"

echo; echo "== 4e. R4 has teeth: a live channel bound in the installed webhook.php is caught (nothing-sent guarantee) =="
printf "\n\$x = new EvolutionWhatsAppChannel(\$evo, 'sales'); // planted\n" >> "$PD/webhook.php"
OUT="$(run --after-only)"
check "$(has "$OUT" 'FAIL  R4 the installed pilot is incomplete or binds a live channel')" "yes" "4e a live channel planted in webhook.php: R4 FAILS 'live-channel-bound'"
check "$(has "$OUT" 'live-channel-bound')" "yes" "…naming it"
git -C "$REPO" show "$PIN:$P/webhook.php" > "$PD/webhook.php"   # put the real webhook back
OUT="$(run --after-only)"
check "$(fails "$OUT")$(has "$OUT" 'ok    R4 the pilot libs')" "0yes" "4f the live channel removed: R4 passes again"

echo; echo "== 5. the rollback: --rollback, typed ROLLBACK =="
D1="$(data_digest)"; fresh; OUT="$(run --answer ROLLBACK --rollback)"
check "$(fails "$OUT")$(has "$OUT" '5.18.65 (rollback): PASSED')" "0yes" "PASSED"
for l in "GO — evidence recorded" "checking out $BASE (5.18.64) for the documented deploy" "ok    container serves $BASE (5.18.64)" \
         "ok    RB the installed manifest says 5.18.64" \
         "note  RB the code is 5.18.64 again"; do
  check "$(has "$OUT" "$l")" "yes" "5: ${l:0:90}"
done
check "$(has "$OUT" "ok    backed up the installed plugin (5.18.65, $PIN)")" "yes" "the backup first — of 5.18.65's code"
check "$(live)$(installed_digest "$BASE")" "${BASE}0" "the container serves $BASE: every changed file as 5.18.64 has it"
check "$(inst_ver)" "5.18.64" "the installed manifest is 5.18.64 again"
check "$(grep -c 'tab=distributors' "$PD/includes/navigation.php")" "0" "the installed navigation.php has no Distributors link again (rolled back)"
check "$(data_digest)" "$D1" "the rollback changed no data (it restores code only; the pilot switch and tables stay)"

echo; echo "== 6. a weakened copy of the script must be caught (control on the control) =="
install_base; seed_data; vault UGX; fresh; OUT="$(run --answer DEPLOY)"; check "$(fails "$OUT")" "0" "control: re-deployed 5.18.65 for the mutant test"
MUT="$REPO/scripts/.mutant-r4.sh"
python3 - "$DEPLOY" "$MUT" <<'PY'
import sys
src, dst = sys.argv[1], sys.argv[2]
s = open(src).read()
# Blind R4's "a live channel is bound" check so it can never match.
old = "grep -q 'new EvolutionWhatsAppChannel' \"$DEST/webhook.php\" 2>/dev/null"
new = "grep -q 'new EvolutionWhatsAppChannel_NEVER' \"$DEST/webhook.php\" 2>/dev/null"
assert s.count(old) == 1, "R4 anchor not unique"
s = s.replace(old, new)
open(dst, 'w').write(s)
PY
printf "\n\$x = new EvolutionWhatsAppChannel(\$evo, 'sales'); // planted\n" >> "$PD/webhook.php"   # plant a live channel
OUT="$(run --script "$MUT" --after-only)"
check "$(has "$OUT" 'ok    R4 the pilot libs')" "yes" "6a the R4-blinded copy calls a webhook that binds a live channel clean — the mutation is detected"
OUT="$(run --after-only)"
check "$(has "$OUT" 'FAIL  R4 the installed pilot is incomplete or binds a live channel')" "yes" "6b …and the real script FAILS R4 on the same fault, naming it"
rm -f "$MUT"; git -C "$REPO" show "$PIN:$P/webhook.php" > "$PD/webhook.php"   # put the real webhook back

echo; echo "== 7. what the rehearsal left behind =="
check "$(checkout_state)" "$CHECKOUT0" "this checkout is as the rehearsal found it: same commit, same tracked files"
check "$(ls "$REPO/scripts"/.mutant-* "$REPO/scripts"/.unpinned.sh 2>/dev/null | wc -l | tr -d ' ')" "0" "no weakened copy is left in the clone"

echo; echo "rehearsal: $PASS passed, $FAILN failed ($(cat "$SB/runs") runs of the script)"
[ "$FAILN" = "0" ]
