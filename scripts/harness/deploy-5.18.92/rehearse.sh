#!/usr/bin/env bash
# Rehearse scripts/deploy-5.18.92.sh — the deploy, its checks, and the ROLLBACK — before the operator runs any of it.
#
# 5.18.92 over 5.18.91: the scheduler's own lock (docs/07, 10 Oct). cron/master.php includes every job in its own scope,
# and twelve jobs assign $lockFp and $lockFile — the names master's lock had — and close them; master's final release
# then unlocked that closed handle: "Uncaught TypeError: flock(): … in …/cron/master.php:405" under the admin pages'
# piggyback run, 7 lines on the server from 08 to 10 Oct. Master's lock is now $_m_lockFp / $_m_lockFile, its release
# guarded. No migration. Deployed from a RELEASE COMMIT on release/5.18.92, cut on the live 5.18.91 release commit
# (dfad4d9), not from the branch tip, which also carries the partner portal, the PD-8 CSRF guard and the AI media layer —
# whose media job is in the branch's own cron/master.php. The base is installed AS PRODUCTION RUNS IT SINCE THE 10 OCT
# DEPLOY: 5.18.91, migrations 086 and 087 applied by the plugin's own runner, 087's three department rows with no
# instance and no number, ONE salesperson's number added through the card — switched off, its assistant switched off —
# the authorisation and the booking WhatsApp ON in both copies, the Inbox's settings row naming Evolution and the three
# numbers, lead capture ON and the uCRM write OFF, a live event queue and job photos; ucrm.json WITHOUT pluginDataDir; THE
# STRAY STORE inside the plugin folder, untouched since 5.18.91's copy; and 5.18.91's deploy record, as that deploy wrote
# it (its stray-store reference, its A14 "seen"). The Evolution the settings name is a stand-in that RECORDS every request.
# This rehearsal proves:
#   · the pin is exactly the release: cut on 5.18.91, 8 files (none added), no migration — 087 the reviewed one — its
#     master.php the development branch's without the media job, and 5.18.91's plus exactly the lock change; its test the
#     branch's byte for byte; Domain B untouched; the retry job 5.18.91's byte for byte;
#   · stage A refuses, before anything changes: a server not on 5.18.91 (1a, 1b), an unpinned script (1c), a pin not cut on
#     5.18.91 (1d), a pin whose master.php or lock test is not the reviewed file (1e, 1f), a file outside the delta (1g),
#     the registry's switch on or own leads only on — switch the pilot on AFTER this deploy (1h, 1i), a salesperson's number
#     switched on (1j), ucrm.json naming <plugin>/data (1k), a file git does not track under the plugin folder (1l), and
#     the two that carry 5.18.92: a pin whose master releases the jobs' lock — A15, with A0 blinded (1m) — and a pin whose
#     retry job is not 5.18.91's — A13, with A0 blinded (1n); the department numbers verified on the card are accepted (1o);
#   · weakened copies of stage A and V4 are caught (2a–2d), each layer with the others blinded;
#   · the deploy then runs end to end and PASSES with ONE note — R19's: no run of the retry job since the copy yet — A14
#     carried from 5.18.91's record, A15 and R20 the lock test on the "server's" PHP, V4 clean, R1 the eight files, the
#     stray store byte for byte and time for time as before, never opened; no data written at all;
#   · --after-only once master has run the job PASSES with no note: R19's ok line, the evidence the quarantine waits for
#     (4c); the old fatal at cron/master.php:405 within 30 minutes of the copy is a note and the run still PASSES (4d),
#     one later fails (4e), any other fatal fails (4f); a real open of the stray store after the copy fails R19 (4g);
#   · the checks have teeth: an installed master.php put back to 5.18.91's lock fails R20 and R1 (5a);
#   · the rollback returns the plugin to 5.18.91: master.php byte for byte, the retry job 5.18.91's and still stopping on
#     Uganda, the old fatal a note, the stray store untouched; a redeploy over it passes;
#   · nothing reaches Evolution; the clone, /tmp and the stand-in are left as found.
#
# Everything runs in a sandbox: a fake `docker` that maps /data/ucrm to a directory here (and supports exec, cp, logs), a
# clone of this checkout, the plugin installed as 5.18.91 exactly (git archive of the release commit), a stand-in web
# server for stage V's pages that boots the INSTALLED plugin's store on every request, and the recording stand-in for
# Evolution. DEPLOY and ROLLBACK are typed through a pseudo-terminal, as the operator types them. It never touches this
# checkout, the server or the network, and refuses to run where the server could be. Everything seeded is fictitious.
#
#   bash scripts/harness/deploy-5.18.92/rehearse.sh            REHEARSE_KEEP=<dir> keeps every run's full output
set -u
R="$(cd "$(dirname "$0")/../../.." && pwd)"
for f in /data/ucrm /opt/dishnet /var/run/docker.sock; do
  [ -e "$f" ] && { echo "refusing: $f exists — this looks like the server, and this rehearsal must never run there"; exit 2; }
done
BASE=dfad4d9; RA_BASE=e076632; OLDER=3d9cb5f; BRANCH_FILE=scripts/deploy-5.18.92.sh; P=dishnet-hybrid-sudan; RELEASE_BRANCH=release/5.18.92
FIXDEV=2a499a8                                                                                # the fix, on the branch
[ -n "${REHEARSE_KEEP:-}" ] && mkdir -p "$REHEARSE_KEEP"
MIG=migrations/087_wa_channels.sql; MIG86=migrations/086_install_authorisation.sql
MIG_SHA=3feda1b44ca79c1ad0057f029c032b741a581f4383e7378480129db1f1385aaa                           # the reviewed 087, applied 7 Oct
MASTER=cron/master.php; LOCK_TEST=tests/test_master_lock_release.php
MASTER91_SHA=6f703cb442e3bccf1f364ec7f3731854d502990a538f4d8a6d6f4ca012fc2b2e                      # 5.18.91's master.php
JOB=cron_starlink_block_retry.php; SLR_LIB=lib/StarlinkRetryScope.php
JOB91_SHA=6da76a52792d0adf3dddade9e0335985f37e35e877c64d2333d8b53ef2f38ffe                         # 5.18.91's retry job, gated
GATE_CALL='    return StarlinkRetryScope::skip(__DIR__);'                                             # 5.18.91: the gate's question
LOCK_OWN='if (is_resource($_m_lockFp)) {'                                                             # 5.18.92: master's own release
LOCK_OLD_RELEASE=$'// ── Release lock ──────────────────────────────────────────────────────────────\nflock($lockFp, LOCK_UN);\nfclose($lockFp);'
SS_SIG="crm.lead.sync=done/0+unknown ai.reply=pending/0 ai.media=done/0+unknown wa.escalation=done/0+unknown install.ready=done/0+unknown"
UG_SIG="crm.lead.sync=pending/0 ai.reply=pending/0 ai.media=done/0+unknown wa.escalation=done/0 install.ready=done/0+unknown"
PS_SIG="cases=12 same=12 differs=- control=same base-ignores=no"
POL_SIG="policy=off/off refusal=-/- sql=-/- evo=off,-/off,- statements=0 registry-reads=0"
SLR_SERVER="live=same inside=no store=read tenant=uganda skip=yes"
SLR_STOPS="data=live left=- stray=unchanged ledger=behind"
SLR_OPENS="data=plugin left=config,configFile,extResult,notify,pdo,pluginDir,retryResult,store,svc stray=changed ledger=level"
SHAPE="sales:in=sales support:in=support account:in=support shared=support+account evo=yes registry=off"   # production's: support and account share
LT_SIG="78 passed, 0 failed rc=0"
EXPECT_N=8; EXPECT_AD=0
OLDFATAL='NOTICE: PHP message: PHP Fatal error:  Uncaught TypeError: flock(): supplied resource is not a valid stream resource in /data/ucrm/data/plugins/dishnet-hybrid-sudan/cron/master.php:405'
checkout_state() { { git -C "$R" rev-parse HEAD; git -C "$R" status --porcelain --untracked-files=no; git -C "$R" diff HEAD; } | sha256sum | cut -c1-16; }
CHECKOUT0="$(checkout_state)"
left_tmp() { ls -d /tmp/dnb-5.18.92-* /tmp/dn-master-lock-* 2>/dev/null | wc -l | tr -d ' '; }
TMP0="$(left_tmp)"
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
notes() { grep -cE '^  note  ' <<<"$1"; }

# ── The clone ────────────────────────────────────────────────────────────────
git clone -q --no-hardlinks "$R" "$SB/repo" || { echo "cannot clone $R"; exit 1; }
REPO="$SB/repo"; DEPLOY="$REPO/$BRANCH_FILE"; BRANCH="$(git -C "$REPO" symbolic-ref --short HEAD)"
if git -C "$R" ls-files --error-unmatch "$BRANCH_FILE" >/dev/null 2>&1; then
  cmp -s "$R/$BRANCH_FILE" "$DEPLOY" || { echo "refusing: $BRANCH_FILE differs from the committed one — commit it, then rehearse"; exit 2; }
  WHICH="the committed script"
else cp "$R/$BRANCH_FILE" "$DEPLOY"; WHICH="the working copy (not yet committed; untracked in the clone)"; fi
git -C "$REPO" fetch -q origin "refs/heads/$RELEASE_BRANCH:refs/remotes/origin/$RELEASE_BRANCH" 2>/dev/null || true
find "$REPO/dishnet-hybrid-sudan" -type f -exec touch -d "@$(git -C "$REPO" log -1 --format=%ct)" {} +
PIN="$(sed -n 's/^EXPECTED_PLUGIN_COMMIT="\([^"]*\)".*/\1/p' "$DEPLOY")"
TIP="$(git -C "$REPO" log -1 --format=%h -- $P)"
echo "== 0. what is rehearsed =="
echo "  script    $WHICH, sha256 $(sha256sum "$DEPLOY" | cut -c1-16)"
echo "  clone     $BRANCH at $(git -C "$REPO" rev-parse --short HEAD); branch-tip plugin commit $TIP"
case "$PIN" in __*) echo "  FAIL the script still carries the placeholder pin ($PIN)"; exit 2;; esac
REL="$(git -C "$REPO" rev-parse --short "origin/$RELEASE_BRANCH" 2>/dev/null || echo none)"
check "$PIN" "$REL" "the script is pinned to the release commit on $RELEASE_BRANCH ($PIN)"
check "$(git -C "$REPO" rev-parse --short "$PIN^" 2>/dev/null)" "$BASE" "control: the release commit is cut on $BASE (5.18.91), the live version"
ver() { git -C "$REPO" show "$1:$P/manifest.json" | python3 -c 'import json,sys; print(json.load(sys.stdin)["information"]["version"])'; }
check "$(ver "$PIN")" "5.18.92" "the plugin at the pin is 5.18.92"
check "$(ver "$BASE")" "5.18.91" "control: the base is 5.18.91"
dl() { git -C "$REPO" diff --no-renames --name-only --diff-filter="$1" "$2" "$3" -- $P; }
CHANGED="$(dl AM "$BASE" "$PIN")"
N_CH="$(grep -c . <<<"$CHANGED")"; N_AD="$(dl A "$BASE" "$PIN" | grep -c .)"; N_MO=$((N_CH - N_AD)); N_DEL="$(dl D "$BASE" "$PIN" | grep -c .)"
echo "  5.18.92   $N_CH files: $N_MO changed, $N_AD added · $N_DEL removed or renamed"
check "$N_DEL" "0" "control: 5.18.91 → 5.18.92 removes no file"
check "$N_AD" "$EXPECT_AD" "control: 5.18.92 adds no file"
check "$N_CH" "$EXPECT_N" "control: $EXPECT_N files in the delta"
TAKEN="$MASTER manifest.json $LOCK_TEST tests/test_distributor_apply.php tests/test_distributor_link_ucrm.php tests/test_distributor_notify.php tests/test_distributor_registry.php tests/test_distributor_territory.php"
for want in $TAKEN; do
  check "$(grep -c "^$P/$want$" <<<"$CHANGED")" "1" "control: the delta includes $want"
done
check "$(grep -c "^$P/migrations/" <<<"$CHANGED")$(git -C "$REPO" show "$PIN:$P/$MIG" | sha256sum | cut -c1-64)" "0$MIG_SHA" "control: no migration in the delta — 087 at the pin the reviewed file (sha256 ${MIG_SHA:0:16}…)"
check "$(git -C "$REPO" diff --name-only "$BASE" "$PIN" | grep -vc "^$P/")" "0" "control: the whole repository's delta is the plugin's files alone"
check "$(git -C "$REPO" rev-parse "$BASE:$P/dishnet-mikrotik-control-plane")$(git -C "$REPO" rev-parse "$BASE:$P/docs")" \
      "$(git -C "$REPO" rev-parse "$PIN:$P/dishnet-mikrotik-control-plane")$(git -C "$REPO" rev-parse "$PIN:$P/docs")" "control: Domain B's tree and the plugin's docs are the same trees at the base and the pin"
same_as_fix() { [ "$(git -C "$REPO" rev-parse "$PIN:$P/$1" 2>/dev/null)" = "$(git -C "$REPO" rev-parse "$FIXDEV:$P/$1" 2>/dev/null)" ] && echo same || echo differs; }
check "$(same_as_fix "$LOCK_TEST")$(same_as_fix "$MASTER")" "samediffers" "control: the lock test is the fix's on the branch ($FIXDEV) byte for byte; master.php is not — the branch's carries the media job"
check "$(diff <(git -C "$REPO" show "$PIN:$P/$MASTER") <(git -C "$REPO" show "$FIXDEV:$P/$MASTER") | grep -E '^[<>]' | grep -vcE "ai_media|run_media_worker|Batch 1|'flag'|_m_flagVal|slow download|shipped state|the one it was before|a job that exists only|1/true/on/yes|^> *$|^> +}$|^> +if \(!in_array")" "0" \
  "control: the pin's master.php is the branch's without the media job — every other line the same"
check "$(git -C "$REPO" show "$PIN:$P/$MASTER" | grep -cE 'ai_media|run_media_worker')$(git -C "$REPO" show "$PIN:$P/$MASTER" | sed '/^ *\/\//d' | grep -cE '\$lockFp\b|\$lockFile\b')$(git -C "$REPO" show "$PIN:$P/$MASTER" | grep -cF -- "$LOCK_OWN")" "002" \
  "control: at the pin master.php carries no media job, names no bare \$lockFp or \$lockFile outside its comments, and guards both of its own releases — the handler's and the normal end's"
check "$(git -C "$REPO" show "$BASE:$P/$MASTER" | sha256sum | cut -c1-64)$(git -C "$REPO" show "$BASE:$P/$MASTER" | grep -cF '$_m_lockFp')" "${MASTER91_SHA}0" "control: 5.18.91's master.php is the reviewed one, with no lock of its own"
check "$(git -C "$REPO" show "$PIN:$P/$JOB" | sha256sum | cut -c1-64)$(git -C "$REPO" show "$BASE:$P/$JOB" | sha256sum | cut -c1-64)" "$JOB91_SHA$JOB91_SHA" "control: the retry job is 5.18.91's at the pin and the base — the gate unchanged"
HEADER_CMD="$(sed -n '/^# Run as root/,/^# The rollback is a separate command/p' "$DEPLOY")"
check "$(grep -c 'deploy-5.18.92.sh 2>&1 | tee' <<<"$HEADER_CMD")$(grep -cE -- '--rollback|--key|--value|set_config|git checkout [0-9a-f]{7}' <<<"$HEADER_CMD")" "10" \
  "the header's deploy command stands alone: no rollback, no switch command and no checkout of another commit in its block (docs/44 §16.9)"
check "$(grep -c "git fetch origin $RELEASE_BRANCH" <<<"$HEADER_CMD")$(grep -c 'deploy-5.18.92.sh --after-only 2>&1 | tee' <<<"$HEADER_CMD")" "11" "the header's deploy command fetches the release branch; the later --after-only is its own command"
SWITCH_KEYS='customer_wa_install_scheduled|install_auth_enabled|multi_number_channels_enabled|ai_lead_capture|ai_crm_lead_sync|ai_qualification|ai_sales_on_all_numbers|sales_own_leads_only|wa_handover_copy_central|wa_followups_on_owned_numbers'
check "$(grep -cE -- "--key +($SWITCH_KEYS)|($SWITCH_KEYS) +--value" "$DEPLOY")" "0" "the script carries no command that switches a feature, the registry, own leads only or a lead switch — neither on nor off"
check "$(grep -cE -- '(rm|mv|cp|unlink|rename)[^|;]*\$DEST/data|\$DEST/data[^ ]*\.(quarantine|bak)' "$DEPLOY")$(grep -cE -- 'tar -C "\$DEST" -czf "\$BK/stray-data\.tar\.gz" data' "$DEPLOY")" "01" \
  "the script never moves, copies over, renames or deletes the stray store's folder — it only reads it, as files, into the backup (the control: that read is there)"
check "$(diff <(sed -n '/^# R19 — the stray store/,/^# For the summary (F): what R19 established/p' "$REPO/scripts/deploy-5.18.91.sh") <(sed -n '/^# R19 — the stray store/,/^# For the summary (F): what R19 established/p' "$DEPLOY") | grep -E '^[<>]' | grep -vcE "fix (was )?installed|this deploy's copy|5\.18\.91's (deploy|copy)|before the fix|before this deploy|COPY_MARK|SLR_JOB_NOW|EXPECTED_VERSION installed at the copy|job file|MASTER")" "0" \
  "control: R19's judgement is 5.18.91's line for line — only its words about the copy changed, and where it reads the copy's time (the full matrix was rehearsed for 5.18.91)"
# ── The container: 5.18.91 installed AS PRODUCTION RUNS IT since the 10 Oct deploy, its data beside it, the stray store in it ─
MOUNT="$SB/mount"; PLUGINS="$MOUNT/ucrm/data/plugins"; PD="$PLUGINS/$P"; DATA="$PLUGINS/.$P-data"
VAULT="$PLUGINS/.dishnet-sudan.vault.json"
mkdir -p "$PD" "$DATA" "$SB/web" "$SB/bin" "$SB/out"
IN_CONTAINER="/data/ucrm/data/plugins/$P"
free_port() { python3 -c 'import socket;s=socket.socket();s.bind(("127.0.0.1",0));print(s.getsockname()[1]);s.close()'; }
WPORT="$(free_port)"; PLUGIN_BASE="http://127.0.0.1:$WPORT/public.php"
printf '%s\n%s\n%s\n%s\n' "$PD" "$DATA" "$SB/container.log" "$SB/web" > "$SB/web/.installed_plugin"
# The stand-in public.php. On every request it boots the INSTALLED plugin's store, as the real public.php does.
# ?page=install_auth runs the INSTALLED page itself; the sign-in page carries the installed viewport line; the staff
# dashboard sends a visitor with no session to the sign-in page, as public.php's requireLogin() does. A test drops a
# trigger file to make something happen DURING a run, on its first request: a photo taken (.add_photo), a photo lost
# (.del_photo), the Inbox's account number moved (.mutate_cfg), a fatal of the plugin in the container log
# (.plant_fatal), the WhatsApp AI screen answering a visitor with no session with the card (.leak_card). Each trigger is
# spent once.
cat > "$SB/web/public.php" <<'PHP'
<?php
[$root, $dataDir, $clog, $web] = array_map('trim', array_slice(explode("\n", (string)@file_get_contents(__DIR__ . '/.installed_plugin')), 0, 4));
foreach (['bootstrap_data', 'StoreInterface', 'JsonStore', 'SqliteStore'] as $l) require_once $root . "/lib/$l.php";
$store = SqliteStore::create($dataDir);
$pdo = $store->getPdo();
$spend = function (string $t) use ($web): bool { $f = $web . '/' . $t; if (!is_file($f)) return false; unlink($f); return true; };
if ($spend('.add_photo')) {
    @mkdir($dataDir . '/uploads/job_photos/20', 0777, true);
    file_put_contents($dataDir . '/uploads/job_photos/20/kit-1-during.jpg', 'jpeg');
    $pdo->exec("INSERT INTO job_photos(job_id, label, retailer_id, file_rel, mime, bytes, sha256) VALUES (20, 'kit', 1, 'uploads/job_photos/20/kit-1-during.jpg', 'image/jpeg', 4, 'x')");
}
if ($spend('.del_photo')) {
    $r = $pdo->query("SELECT id, file_rel FROM job_photos ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if ($r) { $pdo->exec('DELETE FROM job_photos WHERE id = ' . (int)$r['id']); @unlink($dataDir . '/' . $r['file_rel']); }
}
if ($spend('.mutate_cfg')) {
    $row = json_decode((string)$pdo->query("SELECT data FROM kyc_config WHERE id = 0")->fetchColumn(), true);
    $row['evo_instance_account'] = 'rh-moved';
    $pdo->prepare("UPDATE kyc_config SET data = ? WHERE id = 0")->execute([json_encode($row)]);
}
if ($spend('.plant_old')) {   // 5.18.91's own fatal, as php-fpm logged it under an admin page's piggyback run
    file_put_contents($clog, gmdate('Y-m-d\TH:i:s') . '.000000001Z NOTICE: PHP message: PHP Fatal error:  Uncaught TypeError: flock(): supplied resource is not a valid stream resource in /data/ucrm/data/plugins/dishnet-hybrid-sudan/cron/master.php:405' . "\n", FILE_APPEND);
}
if ($spend('.plant_fatal')) {
    file_put_contents($clog, gmdate('Y-m-d\TH:i:s') . '.000000001Z PHP Fatal error:  Uncaught Error: planted by the rehearsal in /data/ucrm/data/plugins/dishnet-hybrid-sudan/cron/event_processor.php:1' . "\n", FILE_APPEND);
}
$page = $_GET['page'] ?? '';
if ($page === 'install_auth' && strpos((string)@file_get_contents($root . '/public.php'), "if (\$page === 'install_auth') {") !== false) {
    $config = $store->load('kyc_config.json');   // public.php's own source of $config: the store row
    require $root . '/tabs/customer_app/install_auth_page.php';
    exit;
}
if ($page === 'dashboard') {
    if ($spend('.leak_card')) { echo '<html><body><div class="wa-card" id="sales-numbers">leaked</div></body></html>'; exit; }
    header('Location: ?page=login', true, 302); exit;
}
if ($page === 'customer_portal' || $page === 'install_auth') { header('Location: ?page=customer_login', true, 302); exit; }
if ($page === 'job_photo') { header('Location: ?page=login', true, 302); exit; }
if ($page === 'api') { http_response_code(401); header('Content-Type: application/json'); echo '{"status":"error","message":"Unauthorized."}'; exit; }
$vp = '';
$lw = (string)@file_get_contents($root . '/tabs/customer_app/login_web.php');
if ($lw !== '' && preg_match('/<meta name="viewport"[^>]*>/', $lw, $m)) $vp = $m[0];
echo '<html><head>' . $vp . '</head><body>Sign in</body></html>';
PHP
php -S "127.0.0.1:$WPORT" -t "$SB/web" >"$SB/web.log" 2>&1 & PIDS+=($!)
# A stand-in for Evolution that answers anything and RECORDS every request it receives: the Inbox's settings row names it,
# so a call by the deploy, its checks, the installed code they run or the rollback would show here (section 8).
EPORT="$(free_port)"; EVO_URL="http://127.0.0.1:$EPORT/rh-evo"; mkdir -p "$SB/evo"
cat > "$SB/evo/router.php" <<'PHP'
<?php
file_put_contents(__DIR__ . '/requests.log', $_SERVER['REQUEST_METHOD'] . ' ' . $_SERVER['REQUEST_URI'] . "\n", FILE_APPEND);
header('Content-Type: application/json'); echo '[]';
PHP
php -S "127.0.0.1:$EPORT" "$SB/evo/router.php" >"$SB/evo.log" 2>&1 & PIDS+=($!)
standin_page() { curl -s --noproxy '*' -o /dev/null -w '%{http_code}' "$PLUGIN_BASE?page=$1"; }

install_commit() {   # the installed plugin exactly as a commit has it
  find "$PD" -mindepth 1 -maxdepth 1 ! -name ucrm.json ! -name data -exec rm -rf {} +
  git -C "$REPO" archive "$1:$P" | tar -x -C "$PD"
  printf '%s\n' "$1" > "$PD/.deployed-commit"
}
install_base() { install_commit "$BASE"; }
printf '{"ucrmPublicUrl":"http://127.0.0.1:1/crm","pluginId":7}' > "$PD/ucrm.json"   # production's: no pluginDataDir
vault() { printf '{"config":{"currency_code":"%s"}}' "$1" > "$VAULT"; }
sq() { php -r '$p = new PDO("sqlite:" . $argv[1]); $p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); $r = $p->query($argv[2]); echo $r ? implode(",", $r->fetchAll(PDO::FETCH_COLUMN)) : "";' "$DATA/plugin.sqlite3" "$1" 2>/dev/null || echo err; }
sqx() { php -r '$p = new PDO("sqlite:" . $argv[1]); $p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); $p->exec($argv[2]); echo "done";' "$DATA/plugin.sqlite3" "$1" 2>&1; }
cfg_set() {   # cfg_set KEY on|off [files|store]: a switch as set_config.php writes it — the override file AND the store row
              # ("files"/"store": that copy only, so the two disagree); off removes the key from both
  php -r '
    $key = $argv[1]; $mode = $argv[2]; $data = $argv[3]; $only = $argv[4] ?? "";
    $file = $data . "/kyc_config.json";
    $p = new PDO("sqlite:" . $data . "/plugin.sqlite3"); $p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $f = json_decode((string)file_get_contents($file), true); $r = json_decode((string)$p->query("SELECT data FROM kyc_config WHERE id = 0")->fetchColumn(), true);
    if ($mode === "on") { if ($only !== "store") $f[$key] = "1"; if ($only !== "files") $r[$key] = "1"; }
    else { unset($f[$key]); unset($r[$key]); }
    file_put_contents($file, json_encode($f)); $p->prepare("UPDATE kyc_config SET data = ? WHERE id = 0")->execute([json_encode($r)]);
    echo "done";
  ' "$1" "$2" "$DATA" "${3:-}" >/dev/null
}
add_number() {     # add_number ID INSTANCE — a salesperson's number exactly as the card adds one: through the INSTALLED
                   # ChannelRegistry, staff-owned by the seeded salesperson, handing over to them, switched OFF, with the trail
  php -r '
    foreach (["bootstrap_data", "StoreInterface", "JsonStore", "SqliteStore", "ChannelRegistry", "EvolutionApiService"] as $l) require_once $argv[1] . "/lib/$l.php";
    $s = SqliteStore::create($argv[2]);
    $staff = 0; foreach ((array)($s->load("retailers.json") ?? []) as $r) if (($r["role"] ?? "") === "sales") $staff = (int)$r["id"];
    (new ChannelRegistry($s->getPdo(), $s))->create(["channel_id" => $argv[3], "evo_instance" => $argv[4], "display_name" => "Sales — Rehearsal Seller",
        "role" => "sales", "owner_type" => "staff", "owner_staff_id" => $staff, "portfolio_scope" => "own", "handover_to" => "owner",
        "ai_enabled" => true, "status" => "disabled"], "Rehearsal Admin (#1)", "added on the WhatsApp AI screen",
        EvolutionApiService::configInstanceMap((array)$s->load("kyc_config.json")));
    echo "done";' "$PD" "$DATA" "$1" "$2" 2>&1
}
seed_data() {      # the plugin's databases, written by 5.18.91's own store (084, 086 and 087 applied by its runner, as production's
                   # runner applied 087 on 07 Oct); then production's state after 5.18.90's deploy: the authorisation and the
                   # booking WhatsApp ON in both copies, activation #1
                   # with job 20 exempt, the Inbox's settings row naming Evolution and the three numbers — support and account
                   # sharing one instance, as production's do (fictitious: the recording stand-in) — lead capture ON and the uCRM write
                   # OFF in both copies, qualification and selling on every number ON; an event queue with work in it; two photos
  rm -rf "$DATA"/plugin.sqlite3* "$DATA"/dishnet.sqlite* "$DATA"/config.json "$DATA"/kyc_config.json "$DATA"/migration.log "$DATA"/uploads "$DATA"/webhook_log.json
  php -r '
    foreach (["bootstrap_data", "StoreInterface", "JsonStore", "SqliteStore", "EventBus"] as $l) require_once $argv[1] . "/lib/$l.php";
    require_once $argv[1] . "/lib/CashbookService.php";
    $s = SqliteStore::create($argv[2]);
    $s->save("kyc_config.json", ["crm_base_url" => "http://127.0.0.1:1", "company_name" => "DishNet Sandbox", "currency_code" => "UGX",
        "tenant_profile" => "uganda", "cashbook_base_currency" => "UGX", "cashbook_currencies" => "UGX,USD", "currency_symbol" => "UGX",
        "install_auth_job_titles" => "Starlink Installation", "install_auth_whatsapp" => "1", "ai_enabled" => "1", "ai_provider" => "openai",
        "evo_api_url" => $argv[3], "evo_api_key" => "rh-key-secret", "evo_instance_sales" => "rh-sales",
        "evo_instance_support" => "rh-shared", "evo_instance_account" => "rh-shared"]);
    file_put_contents($argv[2] . "/kyc_config.json", json_encode($s->load("kyc_config.json")));
    $s->appendWithId("retailers.json", ["name" => "Rehearsal Tech", "email" => "tech@example.test", "role" => "support", "is_active" => true]);
    $s->appendWithId("retailers.json", ["name" => "Rehearsal Seller", "email" => "seller@example.test", "role" => "sales", "is_active" => true]);
    $cb = new CashbookService($s, $argv[2]);
    $cb->addEntryRaw(["project" => "dishnet", "date" => date("Y-m-d"), "direction" => "in", "amount" => 1000000, "currency" => "UGX",
        "category" => "Receipt", "category_raw" => "Receipt", "person" => "", "description" => "opening float", "status" => "approved", "source" => "manual"]);
    $cb->addEntryRaw(["project" => "dishnet", "date" => date("Y-m-d"), "direction" => "out", "amount" => 150000, "currency" => "UGX",
        "category" => "Staff Advance", "category_raw" => "Staff Advance", "person" => "Rehearsal Tech", "description" => "rehearsal", "status" => "approved", "source" => "manual"]);
    $p = $s->getPdo(); $bus = new EventBus($p);
    for ($i = 0; $i < 3; $i++) { $id = $bus->emit("wa.send", "test", $i, ["phone" => "", "message" => ""], 5, "rehearsal"); $bus->ack($id); }
    $bus->emit("ai.reply", "conversation", 7, ["channel" => "sales"], 3, "rehearsal");
    $f = $bus->emit("crm.lead.sync", "lead", 1, ["lead_id" => 1], 5, "rehearsal"); $p->exec("UPDATE events SET status = \x27failed\x27, attempts = 1, next_retry_at = datetime(\x27now\x27, \x27+1 hour\x27) WHERE id = $f");
    $d = $bus->emit("efris.submit", "invoice", 9, ["invoice_id" => 9], 5, "rehearsal"); $p->exec("UPDATE events SET status = \x27dead\x27, attempts = 5 WHERE id = $d");
    @mkdir($argv[2] . "/uploads/job_photos/20", 0777, true);
    foreach (["kit-1-a.jpg", "cable-1-b.jpg"] as $n) {
        file_put_contents($argv[2] . "/uploads/job_photos/20/" . $n, "jpeg");
        $p->prepare("INSERT INTO job_photos(job_id, label, retailer_id, file_rel, mime, bytes, sha256) VALUES (20, ?, 1, ?, \x27image/jpeg\x27, 4, \x27x\x27)")
          ->execute([explode("-", $n)[0], "uploads/job_photos/20/" . $n]);
    }
  ' "$PD" "$DATA" "$EVO_URL" >/dev/null
  cfg_set install_auth_enabled on
  cfg_set customer_wa_install_scheduled on
  for k in ai_qualification ai_sales_on_all_numbers ai_lead_capture; do cfg_set "$k" on; done
  sqx "INSERT INTO install_auth_activations(activated_at, activated_by, jobs_read, exempted) VALUES ('2026-10-06 11:50:00', 'tools/set_config.php as root', 1, 1)" >/dev/null
  sqx "INSERT INTO install_auth_exempt(job_id, activation_id, crm_client_id, title, recorded_at) VALUES (20, 1, 0, 'Starlink Installation — Rehearsal Customer', '2026-10-06 11:50:00')" >/dev/null
  sqx "INSERT INTO install_auth_events(job_id, event, actor_kind, actor_id, detail, created_at) VALUES (20, 'INSTALLATION_EXEMPTED', 'system', NULL, 'activation:1;status:1', '2026-10-06 11:50:00')" >/dev/null
  php -r 'file_put_contents($argv[1], json_encode([
      ["id" => 2, "event" => "job.add", "message" => "Received UCRM webhook: job.add", "data" => [], "received_at" => "2026-10-07 09:26:31", "ip" => ""],
      ["id" => 1, "event" => "job.add", "message" => "Received UCRM webhook: job.add", "data" => [], "received_at" => "2026-10-06 15:02:11", "ip" => ""]]));' "$DATA/webhook_log.json"
}
inst_ver() { grep -o '"version": *"5[^"]*"' "$PD/manifest.json" | head -1 | sed -E 's/.*"(5[^"]*)".*/\1/'; }
live() { tail -n1 "$PD/.deployed-commit" 2>/dev/null | tr -cd '0-9a-f'; }
backups() { ls -d "$SB/out"/backup-* 2>/dev/null | wc -l | tr -d ' '; }
wa_objects() { sq "SELECT (SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name IN ('wa_channels','wa_channel_log')) || ':' || (SELECT COUNT(*) FROM sqlite_master WHERE type='index' AND name IN ('idx_wa_channels_instance','idx_wa_channels_number','idx_wa_channel_log_channel')) || ':' || (SELECT COUNT(*) FROM sqlite_master WHERE type='trigger' AND name IN ('wa_channel_log_append_only_update','wa_channel_log_append_only_delete','wa_channels_never_deleted'))"; }
wa_rows() { sq "SELECT channel_id || '/' || ifnull(evo_instance,'NULL') || '/' || status FROM wa_channels ORDER BY channel_id"; }
mig87() { sq "SELECT COUNT(*) FROM _migrations WHERE filename = '087_wa_channels.sql'"; }
ia_objects() { sq "SELECT (SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name IN ('install_auth','install_auth_events','install_auth_activations','install_auth_exempt','install_auth_rate')) || ':' || (SELECT COUNT(*) FROM sqlite_master WHERE type='trigger' AND name LIKE 'install_auth%') || ':' || (SELECT COUNT(*) FROM sqlite_master WHERE type='index' AND name IN ('idx_install_auth_client','idx_install_auth_status','idx_install_auth_events_job','idx_install_auth_rate'))"; }
ia_rows() { sq "SELECT (SELECT COUNT(*) FROM install_auth) || ':' || (SELECT COUNT(*) FROM install_auth_events) || ':' || (SELECT COUNT(*) FROM install_auth_activations) || ':' || (SELECT COUNT(*) FROM install_auth_exempt) || ':' || (SELECT COUNT(*) FROM install_auth_rate)"; }
_canon() { php -r 'function c($x){ if (is_array($x)) { ksort($x); foreach ($x as &$v) $v = c($v); } return $x; }
  echo json_encode(c(json_decode((string)@file_get_contents($argv[1]), true)));' "$1" 2>/dev/null; }
data_dump() {   # every table's rows — 087's two included — but the ledger of migrations; the vault; the configuration files; the photos
  php -r '$p = new PDO("sqlite:" . $argv[1]); $skip = ["_migrations", "sqlite_sequence"];
      foreach ($p->query("SELECT name FROM sqlite_master WHERE type = \x27table\x27 ORDER BY name")->fetchAll(PDO::FETCH_COLUMN) as $t) {
        if (in_array($t, $skip, true)) continue;
        echo $t, ":", json_encode($p->query("SELECT * FROM [$t]")->fetchAll(PDO::FETCH_NUM)), "\n"; }' "$DATA/plugin.sqlite3"
  echo "vault:$(_canon "$VAULT")"
  [ -f "$DATA/kyc_config.json" ] && echo "kyc:$(_canon "$DATA/kyc_config.json")"
  [ -f "$DATA/config.json" ] && echo "config:$(_canon "$DATA/config.json")"
  find "$DATA/uploads" -type f 2>/dev/null | sort
  true
}
data_digest() { data_dump | sha256sum | cut -c1-16; }
installed_digest() {   # $1 a commit: the number of files 5.18.92 touches that are NOT installed as that commit has them
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

# ── The fake docker: exec (with -i, -u and -e), cp into the container, logs ──
cat > "$SB/bin/docker" <<SH
#!/usr/bin/env bash
printf '%s\n' "\$*" >> "$SB/docker.log"
case "\$1" in
  inspect) echo "$MOUNT"; exit 0 ;;
  logs) cat "$SB/container.log" 2>/dev/null; exit 0 ;;
  cp) [ -n "\${FAKE_CP_FAIL:-}" ] && { echo "fake docker: cp refused (rehearsal control)" >&2; exit 1; }
      src="\$2"; dst="\${3#*:}"; case "\$dst" in /data/*) dst="$MOUNT/\${dst#/data/}" ;; esac
      cp -a "\$src" "\$dst"; exit \$? ;;
  exec) shift ;;
  *) echo "fake docker: \$1 is not supported" >&2; exit 1 ;;
esac
while [ \$# -gt 0 ]; do case "\$1" in
  -i) shift ;;
  -u) shift 2 ;;
  -e) kv="\$2"; v="\${kv#*=}"; case "\$v" in /data/*) v="$MOUNT/\${v#/data/}" ;; esac; export "\${kv%%=*}=\$v"; shift 2 ;;
  *) break ;;
esac; done
shift
args=(); for a in "\$@"; do case "\$a" in /data/*) args+=("$MOUNT/\${a#/data/}") ;; /usr/local/etc|/usr/local/etc/*) args+=("$SB/etc\${a#/usr/local/etc}") ;; *) args+=("\$a") ;; esac; done
exec "\${args[@]}"
SH
chmod +x "$SB/bin/docker"

run() {   # [--answer WORD] [--script FILE] [--env K=V]…, then the script's own options — through a pseudo-terminal
  local answer="" script="$DEPLOY" extra=()
  while :; do case "${1:-}" in --answer) answer="$2"; shift 2 ;; --script) script="$2"; shift 2 ;; --env) extra+=("$2"); shift 2 ;; *) break ;; esac; done
  : > "$SB/docker.log"
  local n; n=$(( $(cat "$SB/runs" 2>/dev/null || echo 0) + 1 )); echo "$n" > "$SB/runs"
  local cmd; cmd="$(printf '%q ' env PATH="$SB/bin:$PATH" DNB_OUT="$SB/out" DNB_PREV_STATE="$PREV" GUARD_SECONDS=0 DN_DATA_DIR="$DATA" ${extra[@]+"${extra[@]}"} bash "$script" --plugin-base "$PLUGIN_BASE" "$@")"
  local o; o="$(printf '%s\n' "$answer" | SHELL=/bin/bash script -qec "$cmd" /dev/null 2>&1 | tr -d '\r')"
  case "$o" in *"unknown option:"*) echo "run-$n" >> "$SB/bad-runs" ;; esac   # a refused run must never read as a pass
  if [ -n "${REHEARSE_KEEP:-}" ]; then
    local label; label="$(printf '%s-%s%s' "${answer:-none}" "$(basename "$script" .sh)" "$(printf '%s' "$*" | tr -c 'A-Za-z0-9-' '_')")"
    printf '%s\n' "$o" > "$REHEARSE_KEEP/run-$(printf '%02d' "$n")-$label.txt"
  fi
  printf '%s' "$o"
}

pinned_copy() {   # pinned_copy NAME COMMIT [SRC] — a copy of the script (or of SRC) pinned to another commit
  sed "s/^EXPECTED_PLUGIN_COMMIT=\"[^\"]*\"/EXPECTED_PLUGIN_COMMIT=\"$2\"/" "${3:-$DEPLOY}" > "$REPO/scripts/.$1.sh"; echo "$REPO/scripts/.$1.sh"
}
mutant() {   # mutant NAME SRC OLD NEW [OLD NEW]… — a copy of SRC with each OLD replaced, each exactly once
  local name="$1" src="$2"; shift 2
  rm -f "$REPO/scripts/.mutant-$name.sh"
  python3 - "$src" "$REPO/scripts/.mutant-$name.sh" "$@" <<'PY' || echo "mutant:$name" >> "$SB/anchor-missing"
import sys
src, dst, pairs = sys.argv[1], sys.argv[2], sys.argv[3:]
s = open(src).read()
for i in range(0, len(pairs), 2):
    old, new = pairs[i], pairs[i + 1]
    assert s.count(old) == 1, "mutant anchor not unique: " + old[:90]
    s = s.replace(old, new)
open(dst, 'w').write(s)
PY
  echo "$REPO/scripts/.mutant-$name.sh"
}
release_variant() {   # release_variant add|addroot|revert|edit PATH [OLD NEW] — a commit cut on 5.18.91 holding the release's tree
                      # with one file planted (in the plugin, or anywhere in the repository), put back as 5.18.90 has it, or edited
  local idx="$SB/variant.idx" tree c mode="$1" path="$2" blob=""
  GIT_INDEX_FILE="$idx" git -C "$REPO" read-tree "$PIN"
  case "$mode" in
    add)     GIT_INDEX_FILE="$idx" git -C "$REPO" update-index --add --cacheinfo "100644,$(printf '<?php // planted\n' | git -C "$REPO" hash-object -w --stdin),$P/$path" ;;
    addroot) GIT_INDEX_FILE="$idx" git -C "$REPO" update-index --add --cacheinfo "100644,$(printf 'planted\n' | git -C "$REPO" hash-object -w --stdin),$path" ;;
    revert)  GIT_INDEX_FILE="$idx" git -C "$REPO" update-index --cacheinfo "100644,$(git -C "$REPO" rev-parse "$BASE:$P/$path"),$P/$path" ;;
    edit)    blob="$(git -C "$REPO" show "$PIN:$P/$path" | python3 -c 'import sys
s = sys.stdin.read(); old, new = sys.argv[1], sys.argv[2]
assert s.count(old) == 1, "variant anchor not unique"
sys.stdout.write(s.replace(old, new))' "$3" "$4" | git -C "$REPO" hash-object -w --stdin)"
             [ "$blob" = "$(printf '' | git -C "$REPO" hash-object --stdin)" ] && echo "release_variant:$path" >> "$SB/anchor-missing"
             GIT_INDEX_FILE="$idx" git -C "$REPO" update-index --cacheinfo "100644,$blob,$P/$path" ;;
  esac
  tree="$(GIT_INDEX_FILE="$idx" git -C "$REPO" write-tree)"; rm -f "$idx"
  c="$(git -C "$REPO" -c user.name=rehearsal -c user.email=rehearsal@example.test commit-tree "$tree" -p "$(git -C "$REPO" rev-parse "$BASE")" -m "rehearsal variant: $mode $path")"
  git -C "$REPO" rev-parse --short "$c"
}
both_copies() { echo "$(sq "SELECT json_extract(data, '$.$1') FROM kyc_config WHERE id = 0")$(php -r 'echo json_decode((string)file_get_contents($argv[1]), true)[$argv[2]] ?? "-";' "$DATA/kyc_config.json" "$1")"; }
row_has() { sq "SELECT CASE WHEN json_extract(data, '$.$1') IS NULL OR json_extract(data, '$.$1') = '' THEN 'no' ELSE 'yes' END FROM kyc_config WHERE id = 0"; }
cfg_keep() { cp "$DATA/kyc_config.json" "$SB/kyc_config.kept"
  php -r '$p = new PDO("sqlite:" . $argv[1]); file_put_contents($argv[2], (string)$p->query("SELECT data FROM kyc_config WHERE id = 0")->fetchColumn());' "$DATA/plugin.sqlite3" "$SB/kyc_row.kept"; }
cfg_back() { cp "$SB/kyc_config.kept" "$DATA/kyc_config.json"
  php -r '$p = new PDO("sqlite:" . $argv[1]); $p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $p->prepare("UPDATE kyc_config SET data = ? WHERE id = 0")->execute([(string)file_get_contents($argv[2])]);' "$DATA/plugin.sqlite3" "$SB/kyc_row.kept"; }
row_unset() {   # row_unset KEY — remove a key from the store row ONLY; the configuration file keeps it
  php -r '$p = new PDO("sqlite:" . $argv[1]); $p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $r = json_decode((string)$p->query("SELECT data FROM kyc_config WHERE id = 0")->fetchColumn(), true); unset($r[$argv[2]]);
    $p->prepare("UPDATE kyc_config SET data = ? WHERE id = 0")->execute([json_encode($r)]);' "$DATA/plugin.sqlite3" "$1"; }
setcfg() {   # setcfg KEY VALUE — the INSTALLED tools/set_config.php through docker exec, as the operator runs it
  env PATH="$SB/bin:$PATH" DN_DATA_DIR="$DATA" bash "$SB/bin/docker" exec ucrm php "$IN_CONTAINER/tools/set_config.php" --key "$1" --value "$2" 2>&1
}
db_keep() { rm -rf "$SB/dbkeep"; mkdir -p "$SB/dbkeep"; cp -p "$DATA"/plugin.sqlite3* "$SB/dbkeep/"; }
db_back() { rm -f "$DATA"/plugin.sqlite3*; cp -p "$SB/dbkeep"/plugin.sqlite3* "$DATA/"; }
host_code_left() { ls -d "$SB/out"/code-* "$SB/out"/domainb-* 2>/dev/null | wc -l | tr -d ' '; }
LEAK_RX='rh-evo|rh-key-secret|rh-sales|rh-shared|rh-moved|rh-seller|rh-two'

edit_installed() {   # edit_installed FILE OLD NEW — the INSTALLED file with OLD replaced, exactly once
  python3 - "$PD/$1" "$2" "$3" <<'PY' || echo "edit_installed:$1" >> "$SB/anchor-missing"
import sys
p, old, new = sys.argv[1], sys.argv[2], sys.argv[3]
s = open(p).read(); assert s.count(old) == 1, "installed anchor not unique: " + old[:80]
open(p, 'w').write(s.replace(old, new))
PY
}

set_ai() {         # set_ai ID 0|1 — a number's assistant switched as the card switches it: through the INSTALLED ChannelRegistry, with its trail
  php -r '
    foreach (["bootstrap_data", "StoreInterface", "JsonStore", "SqliteStore", "ChannelRegistry"] as $l) require_once $argv[1] . "/lib/$l.php";
    $s = SqliteStore::create($argv[2]);
    (new ChannelRegistry($s->getPdo(), $s))->setAiEnabled($argv[3], $argv[4] === "1", "Rehearsal Admin (#1)", "switched on the WhatsApp AI screen");
    echo "done";' "$PD" "$DATA" "$1" "$2" 2>&1
}
verify_dept() {    # verify_dept ID NUMBER — a department's number as 5.18.90's card records it once Evolution has reported it: through
                   # the INSTALLED ChannelRegistry::verifyDepartmentNumber, beside the instance the configuration names (no Evolution call)
  php -r '
    foreach (["bootstrap_data", "StoreInterface", "JsonStore", "SqliteStore", "ChannelRegistry", "EvolutionApiService"] as $l) require_once $argv[1] . "/lib/$l.php";
    $s = SqliteStore::create($argv[2]);
    $inst = (string)(EvolutionApiService::configInstanceMap((array)$s->load("kyc_config.json"))[$argv[3]] ?? "");
    (new ChannelRegistry($s->getPdo(), $s))->verifyDepartmentNumber($argv[3], $argv[4], "Rehearsal Admin (#1)", "read from Evolution\x27s report", $inst);
    echo "done";' "$PD" "$DATA" "$1" "$2" 2>&1
}
evo_calls() { local n=0; [ -f "$SB/evo/requests.log" ] && n="$(wc -l < "$SB/evo/requests.log" | tr -d ' ')"; echo "$n"; }
evo_ready() { python3 -c 'import socket,sys; s=socket.socket(); s.settimeout(0.2); s.connect(("127.0.0.1", int(sys.argv[1]))); s.close()' "$EPORT" 2>/dev/null; }
NUM_RX='2567000003[0-9]{2}'   # the department numbers the rehearsal verifies: never printed by the script

# ── 5.18.91: the stray store inside the plugin folder, master's schedule, the installed job's time ─────────────────
row_set() {   # row_set KEY VALUE — a key in the store row ONLY; the configuration file keeps its own
  php -r '$p = new PDO("sqlite:" . $argv[1]); $p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $r = json_decode((string)$p->query("SELECT data FROM kyc_config WHERE id = 0")->fetchColumn(), true); $r[$argv[2]] = $argv[3];
    $p->prepare("UPDATE kyc_config SET data = ? WHERE id = 0")->execute([json_encode($r)]);' "$DATA/plugin.sqlite3" "$1" "$2"; }
STRAY_OPEN='foreach (["StoreInterface", "JsonStore", "SqliteStore"] as $l) require_once $argv[1] . "/lib/$l.php"; $s = SqliteStore::create($argv[2]); $s = null; echo "done";'
seed_stray() {   # the stray store as the server has it (docs/07, 09 Oct): inside the plugin folder, made by the installed plugin's
                 # own store, at every migration as the live one is — so an open of it changes neither its database nor its
                 # migration.log, only its folder (the -wal and -shm made and removed); its own settings file; all of it, the
                 # folder included, last changed two hours ago
  php -r "$STRAY_OPEN" "$PD" "$PD/data" >/dev/null; php -r "$STRAY_OPEN" "$PD" "$PD/data" >/dev/null
  printf '{"note":"the stray folder settings file"}' > "$PD/data/config.json"
  find "$PD/data" -type f -exec touch -d '-2 hours' {} +; touch -d '-2 hours' "$PD/data"
}
stray_probe() {   # R19's instrument on a COPY of the stray store: one open by the installed plugin's own store — lines added to its
                  # migration.log, whether the database file changed (checksum, size, time), whether the folder changed
  local d="$SB/strayprobe" n0 n1 i0 i1 f0 f1
  rm -rf "$d"; cp -a "$PD/data" "$d"
  n0="$(wc -l < "$d/migration.log")"; i0="$(sha256sum "$d/plugin.sqlite3" | cut -c1-16):$(stat -c '%s:%Y' "$d/plugin.sqlite3")"; f0="$(stat -c %y "$d")"
  php -r "$STRAY_OPEN" "$PD" "$d" >/dev/null 2>&1
  n1="$(wc -l < "$d/migration.log")"; i1="$(sha256sum "$d/plugin.sqlite3" | cut -c1-16):$(stat -c '%s:%Y' "$d/plugin.sqlite3")"; f1="$(stat -c %y "$d")"
  rm -rf "$d"; echo "$((n1 - n0)):$( [ "$i0" = "$i1" ] && echo same || echo changed):$( [ "$f0" = "$f1" ] && echo same || echo changed)"
}
dpo_writer() {   # what cron/dpo_reconcile.php does on the server every 5 minutes: MigrationRunner with its DEFAULT log —
                 # <plugin>/data/migration.log, the stray folder's — on a store whose ledger does not match one file (the live
                 # store's 071 there). Here a throwaway ledger, every file recorded, one with another checksum: one warning line.
  php -r 'require $argv[1] . "/lib/MigrationRunner.php"; $p = new PDO("sqlite:" . $argv[2]); $p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $p->exec("CREATE TABLE IF NOT EXISTS _migrations (id INTEGER PRIMARY KEY AUTOINCREMENT, filename TEXT UNIQUE NOT NULL, checksum TEXT NOT NULL, applied_at TEXT DEFAULT (datetime(\x27now\x27)), duration_ms INTEGER)");
    $ins = $p->prepare("INSERT OR IGNORE INTO _migrations (filename, checksum) VALUES (?, ?)");
    foreach (glob($argv[1] . "/migrations/*.sql") as $f) { $b = basename($f); $ins->execute([$b, $b === "071_dpo_payments.sql" ? "0000rehearsal-mismatch" : md5((string)file_get_contents($f))]); }
    (new MigrationRunner($p, $argv[1] . "/migrations"))->run(); echo "done";' "$PD" "$SB/dpo-emu.sqlite"
  rm -f "$SB/dpo-emu.sqlite"
}
stray_keep() { rm -rf "$SB/strayk"; cp -a "$PD/data" "$SB/strayk"; stat -c %y "$PD/data" > "$SB/strayk.t"; stray_entries_here > "$SB/strayk.ents"; }
stray_entries_here() { find "$PD/data" -mindepth 1 -maxdepth 1 ! -name 'plugin.sqlite3-wal' ! -name 'plugin.sqlite3-shm' ! -name 'plugin.sqlite3-journal' -printf '%i:%f\n' | sort | paste -sd/ -; }
copy_dir() { sed -n 's/^STRAY_COPY=.* dir=\([0-9]*\)$/\1/p' "$STATE" | head -1; }   # the folder's time the deploy recorded at the copy
stray_back() {   # in place: an entry the scenario added is removed, every kept file written back INTO its own inode (cp onto an
                 # existing file truncates and writes it), then the folder's time — so R19's inode:name record still matches
  local f; for f in "$PD/data"/* "$PD/data"/.[!.]*; do [ -e "$f" ] || continue; [ -e "$SB/strayk/$(basename "$f")" ] || rm -rf "$f"; done
  cp -a "$SB/strayk/." "$PD/data/"; touch -d "$(cat "$SB/strayk.t")" "$PD/data"; rm -rf "$SB/strayk" "$SB/strayk.t" "$SB/strayk.ents"; }
plugin_files() { find "$PD" -path "$PD/data" -prune -o -type f -printf '%P %s %T@\n' | sort | sha256sum | cut -c1-16; }
stray_snap() {   # every entry of the stray folder — bytes, time and inode — and the folder's own time: a file made and removed shows
  { stat -c '%n %y %i' "$PD/data"; find "$PD/data" -type f | sort | while read -r f; do printf '%s %s %s %s\n' "$f" "$(stat -c %y "$f")" "$(stat -c %i "$f")" "$(sha256sum "$f" | cut -c1-64)"; done; } | sha256sum | cut -c1-16
}
stray_line() {   # what the script prints for the stray store (its stray_state), computed here independently
  local d="$PD/data" side="" s
  for s in -wal -shm -journal; do [ -e "$d/plugin.sqlite3$s" ] && side="$side$s"; done
  echo "db=$(sha256sum "$d/plugin.sqlite3" | cut -c1-16):$(stat -c '%s:%Y' "$d/plugin.sqlite3") side=${side:--} log=$(stat -c '%s:%Y' "$d/migration.log") dir=$(stat -c %Y "$d")"
}
stray_ledger() {   # the stray store's migrations, read immutably — the reading creates no -wal or -shm beside it
  php -r '$p = new PDO("sqlite:file:" . $argv[1] . "?immutable=1", null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY]); echo (int)$p->query("SELECT COUNT(*) FROM _migrations")->fetchColumn();' "$PD/data/plugin.sqlite3" 2>/dev/null || echo err
}
set_sched() {   # set_sched EPOCH [DURATION_MS] — master's record of a run of the retry job then (master_schedule, as master saves
                # it): a completed run by default, one still running with -1, as master writes before it includes the job
  php -r 'foreach (["StoreInterface", "JsonStore", "SqliteStore"] as $l) require_once $argv[1] . "/lib/$l.php";
    SqliteStore::create($argv[2])->save("master_schedule.json", ["sl_block_retry" => ["last_run" => (int)$argv[3], "last_run_at" => gmdate("Y-m-d H:i:s", (int)$argv[3]), "duration_ms" => (int)$argv[4]]]);' "$PD" "$DATA" "$1" "${2:-850}" >/dev/null 2>&1
}
sched_clear() { sqx "DROP TABLE IF EXISTS master_schedule" >/dev/null; }
job_t() { stat -c %Y "$PD/$JOB"; }
utc() { date -u -d "@$1" +%Y-%m-%dT%H:%M:%SZ; }   # an epoch as the script prints it
holder_start() {   # a process that opens the stray store as 5.18.90's job does — the installed plugin's own store: WAL, the -wal and
                   # -shm made — and keeps it open until told to end: the old job's master process, still running
  rm -f "$SB/holder.go" "$SB/holder.ready"
  php -r 'foreach (["StoreInterface", "JsonStore", "SqliteStore"] as $l) require_once $argv[1] . "/lib/$l.php"; $s = SqliteStore::create($argv[2]);
    touch($argv[3] . ".ready"); while (!file_exists($argv[3] . ".go")) usleep(100000); echo "done";' "$PD" "$PD/data" "$SB/holder" >/dev/null 2>&1 &
  HOLDER=$!; PIDS+=("$HOLDER")
  local i; for i in $(seq 1 100); do [ -f "$SB/holder.ready" ] && break; sleep 0.1; done
}
holder_reaped() {   # the holder is gone: out of PIDS, so cleanup never signals a number the system may since have reused
  local q k=(); for q in "${PIDS[@]}"; do [ "$q" = "$HOLDER" ] || k+=("$q"); done; PIDS=("${k[@]}"); HOLDER=""
  rm -f "$SB/holder.go" "$SB/holder.ready"; }
holder_end() {    # the process ends: as its last connection closes, SQLite removes the -wal and -shm — a change of the folder
  touch "$SB/holder.go"; wait "$HOLDER" 2>/dev/null; holder_reaped; }
holder_kill() {   # the process is killed before it can close the store: the -wal and -shm stay where they are
  kill -KILL "$HOLDER" 2>/dev/null; wait "$HOLDER" 2>/dev/null; holder_reaped; }
side_files() { ls "$PD/data" | grep -E '^plugin\.sqlite3-(wal|shm)$' | paste -sd, -; }
stray_locks_here() {   # POSIX locks on the stray database and its -shm, by inode, from /proc/locks — computed here independently
  local f n=0 i; for f in "$PD/data/plugin.sqlite3" "$PD/data/plugin.sqlite3-shm"; do [ -e "$f" ] || continue; i="$(stat -c %i "$f")"
    n=$(( n + $(awk -v i=":$i" '$2 != "->" && substr($6, length($6) - length(i) + 1) == i {c++} END {print c+0}' /proc/locks) )); done; echo "$n"; }
job_back() {   # the copy N minutes ago, as a later --after-only finds it: the job's time and the copy's recorded time
  touch -d "-$1 minutes" "$PD/$JOB"
  sed -i "s/^STRAY_COPY_AT=.*/STRAY_COPY_AT=$(stat -c %Y "$PD/$JOB")/" "$STATE"
}
copy_at() { sed -i "s/^STRAY_COPY_AT=.*/STRAY_COPY_AT=$1/" "$STATE"; }   # the copy's recorded time, set for one case

install_base; seed_data; seed_stray; vault UGX; fresh
# master's own record of its last completed run of the retry job (master_schedule in the live store): five seconds before
# the stray folder's last change — the last open of it, by 5.18.90's job, before 5.18.91 was installed (docs/07, 10 Oct)
SCHED0=$(( $(stat -c %Y "$PD/data") - 5 )); set_sched "$SCHED0"
flip --on   # enable the pilot through the real tool, exactly as the live server has it
for i in $(seq 1 50); do [ "$(standin_page customer_login)" = "200" ] && break; sleep 0.1; done
for i in $(seq 1 50); do evo_ready && break; sleep 0.1; done
S0="$(setcfg ai_crm_lead_sync 0)"
# The 07 Oct pilot as production's record has it: one salesperson's number added through the card, switched off, then its
# assistant switched off — through the installed registry, each with its trail row.
PILOT="$(add_number sales-001 rh-seller)$(set_ai sales-001 0)"
# 5.18.91's deploy record, as deploy-5.18.91.sh wrote it on the server on 10 Oct: the stray store before it and at its
# copy — exactly the folder as it is now, untouched since — its A14 "seen", and the copy's time, eight minutes after the
# folder's last change. The lines are the ones that script writes (the control below reads them out of it).
PREV="$SB/prev/state-5.18.91.env"; mkdir -p "$SB/prev"
prev_record() {   # prev_record [CTRL] — 5.18.91's deploy record over the stray store as it is now
  local l; l="$(stray_line)"
  printf 'DEPLOYED_AT=2026-10-10T02:51:00Z\nSTRAY_BEFORE=%s\nSTRAY_CTRL=%s\nSTRAY_ENTS=%s\nSTRAY_COPY=%s\nSTRAY_COPY_LOCKS=0\nSTRAY_COPY_AT=%s\n' \
    "$l" "${1:-seen}" "$(stray_entries_here)" "$l" "$(( $(stat -c %Y "$PD/data") + 480 ))" > "$PREV"
}
prev_record
D0="$(data_digest)"; STRAY0="$(stray_snap)"; STRAY_LINE0="$(stray_line)"
M87MD5="$(md5sum "$PD/$MIG" | cut -c1-32)"
check "$(inst_ver)$(sha256sum "$PD/$MASTER" | cut -c1-64)$(sha256sum "$PD/$JOB" | cut -c1-64)" "5.18.91$MASTER91_SHA$JOB91_SHA" "control: the installed base is 5.18.91 — its master.php and its gated retry job byte for byte"
check "$(has "$S0" 'ai_crm_lead_sync = 0')$(both_copies ai_crm_lead_sync)$(both_copies ai_lead_capture)$(both_copies multi_number_channels_enabled)$(both_copies sales_own_leads_only)" "yes0011--" \
  "control: the lead switches as production has them — capture ON, the uCRM write OFF — and the registry's switch and own leads only unset"
check "$PILOT|$(mig87)$(wa_objects)|$(wa_rows)" "donedone|12:3:3|account/NULL/active,sales/NULL/active,sales-001/rh-seller/disabled,support/NULL/active" \
  "control: 087 applied, the three department rows with NO instance and NO number, and the pilot's number switched off, its assistant off"
check "$(php -r '$u = json_decode((string)file_get_contents($argv[1]), true); echo array_key_exists("pluginDataDir", (array)$u) ? "has" : "none";' "$PD/ucrm.json")|$(stray_ledger)/$(sq "SELECT COUNT(*) FROM _migrations")" "none|$(sq "SELECT COUNT(*) FROM _migrations")/$(sq "SELECT COUNT(*) FROM _migrations")" \
  "control: ucrm.json names no pluginDataDir, and the stray store inside the plugin folder is at every migration, as the server's"
check "$(grep -cF "printf 'STRAY_COPY=%s\nSTRAY_COPY_LOCKS=%s\nSTRAY_COPY_AT=%s\n'" "$REPO/scripts/deploy-5.18.91.sh")$(grep -cF "printf 'STRAY_CTRL=%s\n'" "$REPO/scripts/deploy-5.18.91.sh")$(grep -c "^STRAY_COPY=$STRAY_LINE0\$" "$PREV")$(grep -c '^STRAY_CTRL=seen$' "$PREV")" "1111" \
  "control: 5.18.91's record is written in the lines deploy-5.18.91.sh writes — its stray store at its copy exactly the folder as it is now, its A14 'seen'"
check "$(standin_page customer_login)$(data_digest)$(stray_snap)" "200$D0$STRAY0" "control: the stand-in boots the installed store, and nothing it serves changes any data or the stray store"
check "$(evo_ready && echo up)$(evo_calls)" "up0" "control: the recording stand-in for Evolution is listening, and nothing has called it"
# The pin's own lock test, on this machine's PHP, from a copy shaped as A15's (the controls inside it included).
LTC="$SB/ltcopy"; mkdir -p "$LTC"
git -C "$REPO" archive "$PIN" $P/lib $P/cron $P/migrations $P/profiles "$P/cron_*.php" $P/run_worker.php $P/$LOCK_TEST | tar -x -C "$LTC"
check "$(php "$LTC/$P/$LOCK_TEST" 2>&1 | grep -E '^[0-9]+ passed, [0-9]+ failed$' | tail -1) rc=${PIPESTATUS[0]}" "$LT_SIG" "control: the pin's lock test passes from a copy shaped as A15's ($LT_SIG)"
rm -rf "${LTC:?}"
# The pin's master.php is 5.18.91's with exactly the lock change, shown here independently of the derivation that wrote
# the script: 5.18.91's file with its normal-end release guarded and its lock renamed is the pin's, line for line, comment
# lines and alignment aside. The controls: the rename alone does not give the pin's file, nor does the pin's with one
# other line changed.
cat > "$SB/lockdiff.py" <<'PY'
import re, sys
base, pin, old_rel, mode = open(sys.argv[1]).read(), open(sys.argv[2]).read(), sys.argv[3], sys.argv[4]
guard = old_rel.split('\n')[0] + '\nif (is_resource($lockFp)) {\n    flock($lockFp, LOCK_UN);\n    fclose($lockFp);\n}'
if base.count(old_rel) != 1: print('no-anchor'); sys.exit()
if mode == 'fix': base = base.replace(old_rel, guard)
base = base.replace('$lockFp', '$_m_lockFp').replace('$lockFile', '$_m_lockFile')
norm = lambda t: [re.sub(r'[ \t]+', ' ', l).strip() for l in t.split('\n') if not l.strip().startswith('//')]
print('same' if norm(base) == norm(pin) else 'differs')
PY
git -C "$REPO" show "$BASE:$P/$MASTER" > "$SB/m91.php"; git -C "$REPO" show "$PIN:$P/$MASTER" > "$SB/m92.php"
sed 's/\$LOCK_MAX_SECS = \([0-9]*\)/$LOCK_MAX_SECS = 1\1/' "$SB/m92.php" > "$SB/m92x.php"
check "$(python3 "$SB/lockdiff.py" "$SB/m91.php" "$SB/m92.php" "$LOCK_OLD_RELEASE" fix)$(python3 "$SB/lockdiff.py" "$SB/m91.php" "$SB/m92.php" "$LOCK_OLD_RELEASE" rename)$(cmp -s "$SB/m92.php" "$SB/m92x.php" || echo edited)$(python3 "$SB/lockdiff.py" "$SB/m91.php" "$SB/m92x.php" "$LOCK_OLD_RELEASE" fix)" "samediffersediteddiffers" \
  "control: the pin's master.php is 5.18.91's with exactly the lock change — its release guarded, its lock renamed, nothing else (comment lines and alignment aside); the rename alone, or one other line changed, does not match"
rm -f "$SB/m91.php" "$SB/m92.php" "$SB/m92x.php" "$SB/lockdiff.py"

echo; echo "== 1. NO-GO before anything changes — each refusal, and nothing deployed, backed up or changed =="
nogo() {   # nogo LABEL EXPECTED-TEXT OUTPUT — the refusal said, nothing deployed, no backup, the data and the stray store as seeded
  check "$(has "$3" "$2")$(live)$(backups)$(data_digest)$(stray_snap)" "yes${BASE}0$D0$STRAY0" "$1"
}
printf '%s\n' "$OLDER" > "$PD/.deployed-commit"; fresh; OUT="$(run --answer DEPLOY)"
check "$(has "$OUT" "STOP: NO-GO: the container serves $OLDER; 5.18.92 was built and tested against $BASE (5.18.91) — deploy 5.18.91 first (scripts/deploy-5.18.91.sh) and send its log")$(backups)$(data_digest)" "yes0$D0" \
  "1a the server still runs 5.18.90 — NO-GO, 5.18.91 goes first; no backup, no data changed"
printf '%s\n' "$BASE" > "$PD/.deployed-commit"
cp "$PD/manifest.json" "$SB/manifest.kept"; sed -i 's/"version": "5.18.91"/"version": "5.18.90"/' "$PD/manifest.json"; fresh; OUT="$(run --answer DEPLOY)"
nogo "1b the record says $BASE but the installed manifest says 5.18.90 — NO-GO on the version too" \
  "STOP: NO-GO: the container's record says $BASE, but the installed manifest says 5.18.90, not 5.18.91" "$OUT"
cp "$SB/manifest.kept" "$PD/manifest.json"
S="$(pinned_copy unpinned __PLUGIN_COMMIT__)"; fresh; OUT="$(run --answer DEPLOY --script "$S")"; rm -f "$S"
check "$(has "$OUT" "this copy of the script is not pinned to a reviewed commit")$(live)$(backups)" "yes${BASE}0" "1c a copy of the script with no pin refuses at once"
S="$(pinned_copy oldpin "$BASE")"; fresh; OUT="$(run --answer DEPLOY --script "$S")"; rm -f "$S"
nogo "1d a pin that is not cut on 5.18.91 — another build — refuses" "is not cut on $BASE (5.18.91): its parent is" "$OUT"
V="$(release_variant edit "$MASTER" "// ── Release lock ──" "// ── Release lock (edited) ──")"; S="$(pinned_copy v1e "$V")"; fresh; OUT="$(run --answer DEPLOY --script "$S")"; rm -f "$S"
nogo "1e a pin whose master.php is not the reviewed release file — one comment changed — refuses (A0)" "the pin's $MASTER is not the reviewed release file" "$OUT"
V="$(release_variant edit "$LOCK_TEST" "'the test left nothing behind'" "'the test left nothing behind (edited)'")"; S="$(pinned_copy v1f "$V")"; fresh; OUT="$(run --answer DEPLOY --script "$S")"; rm -f "$S"
nogo "1f a pin whose lock test is not the reviewed one refuses (A0)" "the pin's $LOCK_TEST is not the reviewed one" "$OUT"
V="$(release_variant add lib/Planted.php)"; S="$(pinned_copy v1g "$V")"; fresh; OUT="$(run --answer DEPLOY --script "$S")"; rm -f "$S"
nogo "1g a pin carrying a file that is not 5.18.92's refuses (A0)" "the pin carries files that are not 5.18.92's, which this release must not ship: lib/Planted.php(added)" "$OUT"
# A switch the scenario itself turns on: nothing else changed by the refused run (the data as it was with the switch on).
nogo_sw() { check "$(has "$3" "$2")$(live)$(backups)$(data_digest)$(stray_snap)" "yes${BASE}0$4$STRAY0" "$1"; }
cfg_set multi_number_channels_enabled on; DX="$(data_digest)"; fresh; OUT="$(run --answer DEPLOY)"
nogo_sw "1h the registry's switch ON — the pilot switched on before this deploy — refuses (A5): the order is this deploy first, then docs/66 step 2" \
  "STOP: NO-GO: multi_number_channels_enabled reads 'mn=on/on' (files/store)" "$OUT" "$DX"
cfg_set multi_number_channels_enabled off
cfg_set sales_own_leads_only on; DX="$(data_digest)"; fresh; OUT="$(run --answer DEPLOY)"
nogo_sw "1i own leads only ON refuses (A5b)" "STOP: NO-GO: sales_own_leads_only reads 'ol=on/on' (files/store)" "$OUT" "$DX"
cfg_set sales_own_leads_only off
check "$(data_digest)" "$D0" "…the two switches off again: the data exactly as seeded"
db_keep; sqx "UPDATE wa_channels SET status = 'active' WHERE channel_id = 'sales-001'" >/dev/null; fresh; OUT="$(run --answer DEPLOY)"
check "$(has "$OUT" 'STOP: NO-GO: migration 087 is recorded on this server, but not as 5.18.91 left it')$(live)$(backups)" "yes${BASE}0" "1j the pilot's number switched on refuses (A8): 087 is not as 5.18.91 left it"
db_back
pdd_stray() { php -r '$f = $argv[1]; $u = json_decode((string)file_get_contents($f), true); $u["pluginDataDir"] = $argv[2]; file_put_contents($f, json_encode($u));' "$PD/ucrm.json" "$IN_CONTAINER/data"; }
cp "$PD/ucrm.json" "$SB/ucrm.kept"; pdd_stray; fresh; OUT="$(run --answer DEPLOY)"
nogo "1k ucrm.json naming <plugin>/data refuses before anything is read from it" "NO-GO: the plugin's data directory would be $IN_CONTAINER/data — the stray store under the plugin folder" "$OUT"
cp "$SB/ucrm.kept" "$PD/ucrm.json"
printf 'planted\n' > "$REPO/$P/lib/untracked-plant.php"; fresh; OUT="$(run --answer DEPLOY)"; rm -f "$REPO/$P/lib/untracked-plant.php"
nogo "1l a file git does not track under the checkout's plugin folder refuses (scripts/deploy-hybrid.sh copies the working tree)" "NO-GO: the checkout holds 1 file(s) git does not track under $P, outside data/ (dishnet-hybrid-sudan/lib/untracked-plant.php)" "$OUT"
# 5.18.92's own layer, with A0 blinded: a pin whose master releases the jobs' lock again — the release put back — is
# refused by the lock test on the server's PHP.
BLIND_A0="$(mutant blinda0 "$DEPLOY" '[ "$M_PIN_SHA" = "$MASTER_PIN_SHA256" ] || stop' 'true || stop')"
V="$(release_variant edit "$MASTER" "if (is_resource(\$_m_lockFp)) {
    flock(\$_m_lockFp, LOCK_UN);
    fclose(\$_m_lockFp);
}" "flock(\$lockFp, LOCK_UN);
fclose(\$lockFp);")"
S="$(pinned_copy v1m "$V" "$BLIND_A0")"; fresh; OUT="$(run --answer DEPLOY --script "$S")"; rm -f "$S"
nogo "1m with A0 blinded, a pin whose master releases \$lockFp again is refused by A15 — its own test fails on the server's PHP" \
  "NO-GO: under this server's own PHP the pin's test of master's lock does not pass" "$OUT"
check "$(has "$OUT" 'ok    A15')" "no" "…and A15 said no ok line"
check "$(has "$OUT" "master's lock   what the test printed:")$( [ "$(grep -c '^    | ' <<<"$OUT")" -gt 0 ] && echo some || echo none)" "yessome" \
  "…and the log shows why, as the test printed it: its failed assertions, then its last lines"
# With A0's delta check blinded, a pin carrying 5.18.90's retry job — no gate — is refused by A13.
BLIND_A0D="$(mutant blinda0d "$DEPLOY" '[ -z "$STRAY" ] || stop' 'true || stop')"
# the release's tree with 5.18.90's retry job in it (release_variant's revert takes the base's, which is the pin's own)
VIDX="$SB/v.idx"; GIT_INDEX_FILE="$VIDX" git -C "$REPO" read-tree "$PIN"
GIT_INDEX_FILE="$VIDX" git -C "$REPO" update-index --cacheinfo "100644,$(git -C "$REPO" rev-parse "$OLDER:$P/$JOB"),$P/$JOB"
VT="$(GIT_INDEX_FILE="$VIDX" git -C "$REPO" write-tree)"; rm -f "$VIDX"
V="$(git -C "$REPO" -c user.name=rehearsal -c user.email=rehearsal@example.test commit-tree "$VT" -p "$(git -C "$REPO" rev-parse "$BASE")" -m "rehearsal variant: 5.18.90's retry job")"
S="$(pinned_copy v1n "$(git -C "$REPO" rev-parse --short "$V")" "$BLIND_A0D")"; fresh; OUT="$(run --answer DEPLOY --script "$S")"; rm -f "$S"
nogo "1n with A0's delta check blinded, a pin carrying 5.18.90's retry job (no gate) is refused by A13" "NO-GO: the pin's retry job does not stop on Uganda" "$OUT"
check "$(has "$OUT" "could not be made: pin-job-not-5.18.91's uganda-not-stopped — nothing was changed")" "yes" \
  "…naming exactly these two: the job is not 5.18.91's, and it would not stop on Uganda — the live job's own results as they should be"
rm -f "$BLIND_A0" "$BLIND_A0D"
# A13's live side: the installed retry job not 5.18.91's, behind a record that says it is. The base check reads only the
# files this release changes, and the retry job is not one of them, so A13 is the layer that sees it. Its gate taken out:
# on Uganda it no longer stops.
cp -p "$PD/$JOB" "$SB/job91.aside"; edit_installed "$JOB" "    return StarlinkRetryScope::skip(__DIR__);" "    return false;"
fresh; OUT="$(run --answer DEPLOY)"; cp -p "$SB/job91.aside" "$PD/$JOB"
nogo "1p the installed retry job with its gate taken out, behind a record that says 5.18.91: A13 refuses — the live job would not stop on Uganda" \
  "could not be made: live-uganda-not-stopped — nothing was changed" "$OUT"
# Its control: the installed job no longer opening anything (its data folder renamed) — the open by which A13 shows its
# observation sees a change is gone on South Sudan, so it cannot judge the pin.
edit_installed "$JOB" "\$dataDir = \$pluginDir . '/data';" "\$dataDir = \$pluginDir . '/data-rh';"
fresh; OUT="$(run --answer DEPLOY)"; cp -p "$SB/job91.aside" "$PD/$JOB"; rm -f "$SB/job91.aside"
nogo "1q A13's control: the installed job no longer opening the stray store on South Sudan — the change the observation must see is gone: NO-GO" \
  "could not be made: live-south-sudan-not-measured south-sudan-differs — nothing was changed" "$OUT"
check "$(sha256sum "$PD/$JOB" | cut -c1-64)" "$JOB91_SHA" "…the installed job put back, 5.18.91's byte for byte"
fresh; OUT="$(run --answer DEPLOY --env FAKE_CP_FAIL=1)"
nogo "1w the two releases' code cannot be copied into the container: NO-GO before the checks that need it, A15 among them" \
  "STOP: NO-GO: the two releases' code could not be copied into the container's /tmp" "$OUT"
# The card's step 1 of docs/66 done before the deploy — the department numbers verified — is accepted: the deploy goes as
# far as its question (declined here), and A8 says so.
db_keep; cfg_keep; VD="$(verify_dept sales 256700000301)$(verify_dept support 256700000302)"; fresh; OUT="$(run --answer NO)"
check "$VD|$(fails "$OUT")|$(has "$OUT" 'STOP: not confirmed')|$(has "$OUT" 'ok    A8 migration 087')|$(has "$OUT" '2 with its number verified through 5.18.90')" "donedone|0|yes|yes|yes" \
  "1o the department numbers verified on the card (docs/66 step 1.5) are accepted: every check passes up to the question, A8 naming them"
check "$(grep -cE -- "$NUM_RX" <<<"$OUT")" "0" "…and the log prints neither number"
db_back; cfg_back; rm -rf "$SB/out"/state-5.18.92.env* "$SB/out"/backup-*

echo; echo "== 2. weakened copies of stage A and V4 are caught (the control on the control) =="
# 2a — A15 blinded too: the same bad pin as 1m now reaches the question — A15 was the layer that refused it.
M="$(mutant blinda15 "$DEPLOY" '[ "$M_PIN_SHA" = "$MASTER_PIN_SHA256" ] || stop' 'true || stop' '[ "$LT_A" = "$LOCK_TEST_SIGNATURE" ] || {' 'true || {')"
V="$(release_variant edit "$MASTER" "if (is_resource(\$_m_lockFp)) {
    flock(\$_m_lockFp, LOCK_UN);
    fclose(\$_m_lockFp);
}" "flock(\$lockFp, LOCK_UN);
fclose(\$lockFp);")"
S="$(pinned_copy v2a "$V" "$M")"; fresh; OUT="$(run --answer NO --script "$S")"; rm -f "$S" "$M"
check "$(has "$OUT" 'STOP: not confirmed')$(has "$OUT" 'NO-GO: under this server')" "yesno" "2a with A0 and A15 blinded the bad pin reaches the question — A15 is the layer that refuses it (caught)"
rm -rf "$SB/out"/state-5.18.92.env* "$SB/out"/backup-*
# 2b — A0 blinded alone: A15 still refuses (1m) — shown above; here A15 blinded alone: A0 still refuses.
M="$(mutant blinda15only "$DEPLOY" '[ "$LT_A" = "$LOCK_TEST_SIGNATURE" ] || {' 'true || {')"
S="$(pinned_copy v2b "$V" "$M")"; fresh; OUT="$(run --answer DEPLOY --script "$S")"; rm -f "$S" "$M"
nogo "2b with A15 blinded alone, A0 still refuses the same pin — the two layers are independent" "the pin's $MASTER is not the reviewed release file" "$OUT"
# 2c — A14 blind to the folder: it says 'seen' over a folder changed since 5.18.91's copy.
stray_keep; touch "$PD/data"; sleep 1
fresh; OUT="$(run --answer NO)"
check "$(has "$OUT" 'note  A14 the stray store is not as 5.18.91')$(has "$OUT" 'ok    A14')" "yesno" "2c (control) the stray folder changed since 5.18.91's copy: A14 is a note, never seen"
M="$(mutant blinda14 "$DEPLOY" '&& [ "$SLR_F0" = "$P91_DIR" ]; then' '; then')"
fresh; OUT="$(run --answer NO --script "$M")"; rm -f "$M"
check "$(has "$OUT" 'ok    A14')" "yes" "2c the copy blind to the folder's time says 'seen' over that folder (caught)"
stray_back; rm -rf "$SB/out"/state-5.18.92.env* "$SB/out"/backup-*
check "$(stray_snap)" "$STRAY0" "…the stray folder back exactly as seeded"
# 2d — A14 with no 5.18.91 record, or one holding an R19 failure: a note each.
mv "$PREV" "$PREV.away"; fresh; OUT="$(run --answer NO)"; mv "$PREV.away" "$PREV"
check "$(has "$OUT" "note  A14 5.18.91's deploy record ($PREV) is not on this server")" "yes" "2d no 5.18.91 record: A14 is a note"
cp "$PREV" "$PREV.kept"; printf 'STRAY_R19_FAILED=1760060000\n' >> "$PREV"; fresh; OUT="$(run --answer NO)"; cp "$PREV.kept" "$PREV"; rm -f "$PREV.kept"
check "$(has "$OUT" "note  A14 5.18.91's record holds an R19 failure (STRAY_R19_FAILED in $PREV)")$(has "$OUT" "note  an earlier deploy's R19 FAILED")" "yesyes" "2d a 5.18.91 record holding an R19 failure: A14 and the state check each say so"
rm -rf "$SB/out"/state-5.18.92.env* "$SB/out"/backup-*
# 2e — each of A14's other conditions alone: the record's own A14 not 'seen'; the database file changed, its folder's time
# kept; a side file beside it now, its folder's time kept; a side file in the record. Each is a note, never 'seen' — and a
# copy of the script blind to that one condition says 'seen' (caught).
a14_case() {   # a14_case LABEL MUTANT-ANCHOR NAME
  local out m
  fresh; out="$(run --answer NO)"
  check "$(has "$out" 'note  A14 the stray store is not as 5.18.91')$(has "$out" 'ok    A14')" "yesno" "2e (control) $1: A14 is a note, never seen"
  m="$(mutant "a14-$3" "$DEPLOY" "$2" '')"
  fresh; out="$(run --answer NO --script "$m")"; rm -f "$m"
  check "$(has "$out" 'ok    A14')" "yes" "2e …the copy blind to it says 'seen' (caught)"
  rm -rf "$SB/out"/state-5.18.92.env* "$SB/out"/backup-*
}
prev_record not-seen
a14_case "5.18.91's record says its A14 did not see the instrument work" '[ "$P91_CTRL" = "seen" ] && ' ctrl
prev_record
stray_keep; touch -m -d '-2 hours' "$PD/data/plugin.sqlite3"; touch -d "$(cat "$SB/strayk.t")" "$PD/data"
a14_case "the stray database file changed since 5.18.91's copy, its folder's time kept" '&& [ "${P91_COPY%% *}" = "${STRAY_A%% *}" ] ' db
stray_back
stray_keep; : > "$PD/data/plugin.sqlite3-wal"; touch -d "$(cat "$SB/strayk.t")" "$PD/data"
a14_case "a side file beside the stray store now, its folder's time kept" '&& [ "$A_SIDE" = "-" ] ' side-now
stray_back
sed -i 's/^\(STRAY_COPY=.*\) side=- /\1 side=-wal-shm /' "$PREV"
check "$(grep -c '^STRAY_COPY=.* side=-wal-shm ' "$PREV")" "1" "…5.18.91's record edited: a side file beside the stray store at its copy"
a14_case "a side file beside the stray store at 5.18.91's copy" '&& [ "$P91_SIDE" = "-" ] ' side-then
prev_record
check "$(stray_snap)$(grep -c "^STRAY_COPY=$STRAY_LINE0\$" "$PREV")$(grep -c '^STRAY_CTRL=seen$' "$PREV")" "${STRAY0}11" "…the stray folder and 5.18.91's record back exactly as seeded"
# 2f — the stray store's migration.log grown since 5.18.91's copy, as cron/dpo_reconcile.php grows it on the server every
# five minutes: A14 compares the database file, the side files and the folder's time, not the log — still 'seen'.
stray_keep; DW="$(dpo_writer)"
fresh; OUT="$(run --answer NO)"
check "$DW|$( [ "$(stray_line)" != "$STRAY_LINE0" ] && echo moved)|$( [ "$(stat -c %Y "$PD/data")" = "${STRAY_LINE0##* dir=}" ] && echo folder-kept)|$(has "$OUT" 'ok    A14 the stray store is exactly as 5.18.91')" "done|moved|folder-kept|yes" \
  "2f the stray store's migration.log grown since 5.18.91's copy, as dpo_reconcile grows it, the folder's time unchanged by it: A14 still 'seen' — the log is not what it compares"
stray_back; rm -rf "$SB/out"/state-5.18.92.env* "$SB/out"/backup-*
check "$(stray_snap)" "$STRAY0" "…the stray folder back exactly as seeded"

echo; echo "== 3. the deploy, as the operator runs it =="
D1="$(data_digest)"; EV1="$(sq "SELECT group_concat(id || ':' || event_type || ':' || status, ',') FROM events")"
STRAY_LINE="$(stray_line)"
fresh; touch "$SB/web/.plant_old"; OUT="$(run --answer DEPLOY)"
echo "  …    the deploy's own tally:$(grep -E '^  checks +[0-9]+ ok, ' <<<"$OUT" | sed -E 's/^  checks +/ /')"
check "$(fails "$OUT")$(notes "$OUT")" "02" "no FAIL line and two notes — R19's, and V4's for the old fatal planted during the run"
check "$(has "$OUT" '5.18.92 (deploy): PASSED')" "yes" "PASSED"
for l in "ok    A0 the release delta is exactly 5.18.92's 8 files — 0 added, 8 changed, no migration (087 the reviewed one, sha256 ${MIG_SHA:0:16}…, unchanged); $MASTER the reviewed release file" \
         "ok    A7 Domain B untouched" \
         "release code    $PIN (5.18.92) and $BASE (5.18.91), copied into the container's /tmp for the comparisons below" \
         "ok    A5 the channel registry's switch reads OFF in both copies" \
         "ok    A8 migration 087 (5.18.88's) is applied and complete on this server" \
         "ok    A9 the pin's code routes the three numbers exactly as the live code does" \
         "ok    A10 D3 the pin's assistant builds exactly 5.18.91's prompt" \
         "ok    A11 the pin's automated-send policy is inert on this server's own configuration" \
         "ok    A6 South Sudan stays exactly as it is" \
         "stray store     at 5.18.91's copy" \
         "ok    A14 the stray store is exactly as 5.18.91's deploy recorded it at its copy" \
         "ok    A12 on this server's own configuration the pin's gate stops the Starlink retry job" \
         "retry job       live 5.18.91, Uganda:      $SLR_STOPS | locks=kept" \
         "                  pin  5.18.92, Uganda:      $SLR_STOPS | locks=kept" \
         "                  live 5.18.91, South Sudan: $SLR_OPENS | locks=kept" \
         "ok    A13 the pin's retry job — the live 5.18.91 job byte for byte —" \
         "master's lock   pin  5.18.92's $LOCK_TEST under PHP" \
         "ok    A15 under this server's own PHP" \
         "the live $MASTER names no lock of its own" \
         "GO — evidence recorded" \
         "checking out $PIN (5.18.92, the release commit) for the documented deploy" \
         "ok    container serves $PIN" \
         "ok    V3f the channel registry's switch is unchanged by this run and OFF in both copies" \
         "ok    V4 no new fatal or parse error of $P in the container log since" \
         "note  V4 1 line(s) of the old fatal at cron/master.php:405 since" \
         "ok    R1 all 8 files 5.18.92 changes are installed exactly as $PIN has them (0 new)" \
         "ok    R1 the installed manifest says 5.18.92" \
         "ok    R5 all" \
         "ok    R11 S4/S5 the registry is dark" \
         "ok    R12 S1–S3 the three numbers route exactly as on 5.18.91" \
         "ok    R16 D3 the installed assistant builds exactly 5.18.91's prompt" \
         "ok    R18 the installed retry job, on throwaway layouts" \
         "ok    R19 the stray store under the plugin folder is exactly as before the deploy" \
         "note  R19 the stray folder has not changed since the copy" \
         "but master's record shows no completed run of the retry job since this deploy's copy at" \
         "ok    R20 under this server's own PHP the installed test of master's lock passes ($LT_SIG)" \
         "what changed      cron/master.php keeps its lock under its own names" \
         "and once more a day later"; do
  check "$(has "$OUT" "$l")" "yes" "3: ${l:0:100}"
done
check "$(grep -cE -- "$LEAK_RX|$NUM_RX" <<<"$OUT")" "0" "the log carries no Evolution address, key or instance name, and no number"
check "$(has "$OUT" "what the test printed")" "no" "the lock test's own output is not printed when it passes (A15, R20)"
check "$(grep -A7 -E '^  (note|FAIL)  V4' <<<"$OUT" | grep -cE 'Uncaught TypeError|supplied resource')$(grep -A7 -E '^  note  V4' <<<"$OUT" | grep -cE '^ +20[0-9-]+T[0-9:]+Z cron/master\.php:405$')" "01" "V4 names the old fatal by its time and file:line only, never its message"
check "$(live)$(installed_digest "$PIN")$(inst_ver)" "${PIN}05.18.92" "the container serves $PIN: every changed file exactly as the commit has it, manifest 5.18.92"
check "$(data_digest)" "$D1" "the deploy wrote no record and no configuration value"
check "$(sq "SELECT group_concat(id || ':' || event_type || ':' || status, ',') FROM events")" "$EV1" "…the event queue exactly as before"
check "$(stray_snap)" "$STRAY0" "the stray store's folder is exactly as before — the deploy neither copied into it nor opened it"
STATE="$SB/out/state-5.18.92.env"
check "$(grep -c '^STRAY_CTRL=seen$' "$STATE")$(grep -c "^STRAY_COPY=$STRAY_LINE\$" "$STATE")$(grep -c "^STRAY_COPY_AT=$(stat -c %Y "$PD/$MASTER")\$" "$STATE")" "111" \
  "the state file records A14's 'seen', the stray store at the copy, and the copy's time as the time of master.php the copy stamped"
check "$(host_code_left)$(left_tmp)" "0$TMP0" "the run removed its copies of the two releases' code, every throwaway layout and the lock test's own files"
check "$(grep -c "cd $REPO && bash scripts/deploy-5.18.92.sh --rollback" <<<"$OUT")" "1" "the rollback command is printed once, on its own line"
check "$(awk '/PASSED\. Send this LOG FILE back/ {p=1} p && /deploy-5\.18\.92\.sh --rollback/ {print "after"; exit}' <<<"$OUT")" "after" "…after the verdict, at the end of the log"
check "$(evo_calls)" "0" "Evolution was never called — not by the deploy, its checks or the installed code it ran"

echo; echo "== 4. --after-only: R19 conclusive once master has run the job; V4 and the old fatal =="
copy_back() {   # the copy N minutes ago, as a later --after-only finds it: master.php's time, the copy's recorded time, and the
                # deploy's start a minute before it (V4 reads the log from there)
  touch -d "-$1 minutes" "$PD/$MASTER"; local t; t="$(stat -c %Y "$PD/$MASTER")"
  sed -i -e "s/^STRAY_COPY_AT=.*/STRAY_COPY_AT=$t/" -e "s/^DEPLOYED_AT=.*/DEPLOYED_AT=$(date -u -d "@$(( t - 60 ))" +%Y-%m-%dT%H:%M:%SZ)/" "$STATE"; }
copy_back 30; COPY_T="$(stat -c %Y "$PD/$MASTER")"
base_log; OUT="$(run --after-only)"
check "$(fails "$OUT")$(has "$OUT" "note  R19 the stray folder has not changed since the copy")$(has "$OUT" "(its last: $(utc "$SCHED0"))")" "0yesyes" \
  "4a 30 minutes on, master's last record of the job still from before the deploy: a note — not yet evidence"
set_sched $(( $(date -u +%s) - 300 ))
base_log; OUT="$(run --after-only)"
check "$(fails "$OUT")$(notes "$OUT")$(has "$OUT" '5.18.92 (after): PASSED')$(has "$OUT" 'ok    R19 the retry job ran at')$(has "$OUT" "after this deploy's copy (at $(utc "$COPY_T"))")$(has "$OUT" "nothing has opened the stray store since 5.18.91's copy")" "00yesyesyesyes" \
  "4c master completed a run of the retry job after the copy and the folder stayed quiet since 5.18.91's copy: R19 PASSES with 0 failed, 0 notes — the evidence the quarantine waits for"
check "$(has "$OUT" 'ok    R20 under this server')$(has "$OUT" 'ok    V4 no new fatal')" "yesyes" "…4c: R20 and V4 pass in --after-only too"
base_log; logline $(( COPY_T + 600 )) "$OLDFATAL"; OUT="$(run --after-only)"
check "$(fails "$OUT")$(has "$OUT" "note  V4 1 line(s) of the old fatal at cron/master.php:405")$(has "$OUT" "$(utc $(( COPY_T + 600 ))) cron/master.php:405")$(has "$OUT" '5.18.92 (after): PASSED')" "0yesyesyes" \
  "4d the old fatal 10 minutes after the copy — a master run begun on 5.18.91's code: a note, named by time and file:line; still PASSED"
base_log; logline $(( COPY_T + 2400 )) "$OLDFATAL"; OUT="$(run --after-only)"
check "$(fails "$OUT")$(has "$OUT" "FAIL  V4 1 fatal line(s) of $P")$(has "$OUT" "$(utc $(( COPY_T + 2400 ))) cron/master.php:405")" "1yesyes" \
  "4e the same line 40 minutes after the copy — beyond master's 30-minute bound, so 5.18.91's master is still being run: FAIL"
base_log; logline $(( $(date -u +%s) - 30 )) "PHP Fatal error:  Uncaught Error: planted by the rehearsal in /data/ucrm/data/plugins/dishnet-hybrid-sudan/cron/event_processor.php:12"; OUT="$(run --after-only)"
check "$(fails "$OUT")$(has "$OUT" " cron/event_processor.php:12")" "1yes" "4f any other fatal of the plugin: FAIL, named by file:line"
OTHER405='NOTICE: PHP message: PHP Fatal error:  Uncaught Error: planted by the rehearsal in /data/ucrm/data/plugins/dishnet-hybrid-sudan/cron/master.php:405'
base_log; logline $(( COPY_T + 600 )) "${OLDFATAL%:405}:414"; OUT="$(run --after-only)"
check "$(fails "$OUT")$(has "$OUT" "FAIL  V4 1 fatal line(s) of $P")$(has "$OUT" "$(utc $(( COPY_T + 600 ))) cron/master.php:414")" "1yesyes" \
  "4h 10 minutes after the copy, the old fatal's message at another line of master.php: not the old fatal — FAIL, named by file:line"
base_log; logline $(( COPY_T + 600 )) "$OTHER405"; OUT="$(run --after-only)"
check "$(fails "$OUT")$(has "$OUT" "FAIL  V4 1 fatal line(s) of $P")$(has "$OUT" "$(utc $(( COPY_T + 600 ))) cron/master.php:405")" "1yesyes" \
  "4i 10 minutes after the copy, another fatal at the old line: not the old fatal — FAIL"
M="$(mutant v4noline "$DEPLOY" 'if [ "$v4w" = "$V4_OLD_LINE" ] && printf' 'if printf')"
base_log; logline $(( COPY_T + 600 )) "${OLDFATAL%:405}:414"; OUT="$(run --script "$M" --after-only)"; rm -f "$M"
check "$(fails "$OUT")$(has "$OUT" '5.18.92 (after): PASSED')" "0yes" "4h' the copy of V4 that does not compare the file:line takes 4h's line for the old fatal and passes (caught: 4h fails it)"
V4MSG="$(cat <<'A'
[ "$v4w" = "$V4_OLD_LINE" ] && printf '%s' "$v4l" | grep -qF 'flock(): supplied resource is not a valid stream resource'
A
)"
M="$(mutant v4nomsg "$DEPLOY" "$V4MSG" '[ "$v4w" = "$V4_OLD_LINE" ]')"
base_log; logline $(( COPY_T + 600 )) "$OTHER405"; OUT="$(run --script "$M" --after-only)"; rm -f "$M"
check "$(fails "$OUT")$(has "$OUT" '5.18.92 (after): PASSED')" "0yes" "4i' the copy of V4 that does not read the message takes 4i's line for the old fatal and passes (caught: 4i fails it)"
T4J=$(( $(date -u +%s) - 40 ))
base_log; logline "$T4J" 'NOTICE: PHP message: PHP Fatal error:  Allowed memory size of 134217728 bytes exhausted (tried to allocate 20480 bytes) in /data/ucrm/data/plugins/dishnet-hybrid-sudan/lib/Planted.php on line 77'
logline $(( T4J + 10 )) 'NOTICE: PHP message: PHP Parse error:  syntax error, unexpected token "}" in /data/ucrm/data/plugins/dishnet-hybrid-sudan/cron/planted.php on line 9'
OUT="$(run --after-only)"
check "$(fails "$OUT")$(has "$OUT" "FAIL  V4 2 fatal line(s) of $P")$(has "$OUT" "$(utc "$T4J") lib/Planted.php:77")$(has "$OUT" "$(utc $(( T4J + 10 ))) cron/planted.php:9")$(has "$OUT" '(no file:line on the line)')" "1yesyesyesno" \
  "4j a fatal and a parse error as PHP writes them, 'in <file> on line <N>': FAIL, each named by file:line"
base_log
# 4k — a job file in the checkout that neither release holds (a later push to the development branch, or a file left by
# hand), the script run from the checkout as the operator runs it: the code copied for the comparisons is still each
# commit's own — git, not the shell, matches cron_*.php.
printf '<?php // planted by the rehearsal\n' > "$REPO/$P/cron_planted_rh.php"
OUT="$( cd "$REPO" && run --after-only )"
check "$(fails "$OUT")$(has "$OUT" '?nocode')" "0no" "4k a cron_*.php in the checkout that neither commit holds, run from the checkout: the code is still copied — 0 failed"
M="$(mutant globbed "$DEPLOY" '"$EXPECTED_PLUGIN_COMMIT" "${CHK_PARTS_A[@]}" | tar' '"$EXPECTED_PLUGIN_COMMIT" $CHK_PARTS | tar' '"$BASELINE_COMMIT" "${CHK_PARTS_A[@]}" | tar' '"$BASELINE_COMMIT" $CHK_PARTS | tar')"
base_log; OUT="$( cd "$REPO" && run --script "$M" --after-only )"; rm -f "$M"
check "$( [ "$(fails "$OUT")" -gt 0 ] && echo failed)$(has "$OUT" '?nocode')$(has "$OUT" '5.18.92 (after): ')$(has "$OUT" 'FAILED — send the log file')" "failedyesyesyes" "4k' the copy that lets the shell expand the parts against the checkout (the first draft) cannot copy the code then: it fails (caught)"
rm -f "$REPO/$P/cron_planted_rh.php"; base_log
M="$(mutant v4blind "$DEPLOY" '&& { [ "$MODE" = "rollback" ] || { [ -n "$V4_COPY_T" ] && [ "$v4e" -gt 0 ] && [ "$v4e" -le $((V4_COPY_T + V4_OLD_MAX)) ]; }; }; then' '; then')"
base_log; logline $(( COPY_T + 2400 )) "$OLDFATAL"; OUT="$(run --script "$M" --after-only)"; rm -f "$M"
check "$(fails "$OUT")$(has "$OUT" '5.18.92 (after): PASSED')" "0yes" "4e' the copy of V4 with no time bound takes the late line for the old one and passes (caught: 4e fails it)"
base_log
# A real open of the stray store after the copy: the installed plugin's own store opens it, and closes — R19 fails.
stray_keep; holder_start; holder_end
OUT="$(run --after-only)"
check "$(fails "$OUT")$(has "$OUT" "FAIL  R19 the stray folder changed at")$(grep -c '^STRAY_R19_FAILED=' "$STATE")" "1yes1" "4g an open of the stray store after the copy: R19 FAILS, and the failure is recorded"
sed -i '/^STRAY_R19_FAILED=/d' "$STATE"; stray_back
check "$(stray_snap)" "$STRAY0" "…the stray folder back exactly as seeded"

echo; echo "== 5. the checks have teeth =="
edit_installed "$MASTER" "if (is_resource(\$_m_lockFp)) {
    flock(\$_m_lockFp, LOCK_UN);
    fclose(\$_m_lockFp);
}" "flock(\$lockFp, LOCK_UN);
fclose(\$lockFp);"
base_log; OUT="$(run --after-only)"
check "$(has "$OUT" 'FAIL  R20 under this server')$(has "$OUT" 'FAIL  R1 installed files that differ')" "yesyes" "5a an installed master.php put back to the jobs' lock: R20 and R1 fail"
git -C "$REPO" show "$PIN:$P/$MASTER" > "$PD/$MASTER"; touch -d "@$COPY_T" "$PD/$MASTER"
base_log; OUT="$(run --after-only)"
check "$(fails "$OUT")$(has "$OUT" 'ok    R20 under this server')" "0yes" "…put back as the pin has it: R20 passes again"

echo; echo "== 6. the rollback: --rollback, typed ROLLBACK =="
D2="$(data_digest)"; fresh; touch "$SB/web/.plant_old"; OUT="$(run --answer ROLLBACK --rollback)"
check "$(fails "$OUT")$(has "$OUT" '5.18.92 (rollback): PASSED')" "0yes" "PASSED"
for l in "note  after the rollback the code is 5.18.91's again: master's lock by the names its jobs also use" \
         "ok    backed up the installed plugin (5.18.92, $PIN)" \
         "checking out $BASE (5.18.91) for the documented deploy" "ok    container serves $BASE (5.18.91)" \
         "ok    V4 no new fatal" "note  V4 1 line(s) of 5.18.91's own fatal at cron/master.php:405" \
         "ok    RB the installed manifest says 5.18.91" \
         "ok    RB the installed plugin is 5.18.91's again: $MASTER is 5.18.91's byte for byte" \
         "ok    RB the retry job is 5.18.91's, as before the deploy, on throwaway layouts: on Uganda it stops before <plugin>/data ($SLR_STOPS | locks=kept)" \
         "note  RB with 5.18.91 back, master's lock is \$lockFp again" \
         "ok    RB the event processor is 5.18.91's" \
         "ok    RB the assistant's prompt is 5.18.91's" \
         "note  RB the code is 5.18.91 again. The rollback restores code only. 5.18.92 added no file, so none stays behind."; do
  check "$(has "$OUT" "$l")" "yes" "6: ${l:0:96}"
done
check "$(live)$(installed_digest "$BASE")$(inst_ver)$(sha256sum "$PD/$MASTER" | cut -c1-64)$(sha256sum "$PD/$JOB" | cut -c1-64)" "${BASE}05.18.91$MASTER91_SHA$JOB91_SHA" \
  "the container serves $BASE: every changed file as 5.18.91 has it — master.php and the gated retry job byte for byte"
check "$(data_digest)$(stray_snap)$(host_code_left)$(left_tmp)" "${D2}${STRAY0}0$TMP0" "the rollback changed no data and not the stray store, and left no copy of the code anywhere"
fresh; OUT="$(run --answer DEPLOY)"
check "$(fails "$OUT")$(has "$OUT" '5.18.92 (deploy): PASSED')$(has "$OUT" 'ok    A14 the stray store is exactly as 5.18.91')$(has "$OUT" 'ok    R20 under this server')" "0yesyesyes" \
  "6b deployed again over the rollback: PASSED, A14 still carried from 5.18.91's record, R20 again"

echo; echo "== 7. what the rehearsal left behind, and what it never reached =="
check "$(evo_calls)" "0" "Evolution never called in $(cat "$SB/runs") runs of the script — no instance read, created or paired, no message sent"
curl -s --noproxy '*' -o /dev/null "$EVO_URL/rehearsal-control"
check "$(evo_calls)$(cat "$SB/evo/requests.log" 2>/dev/null)" "1GET /rh-evo/rehearsal-control" "control on the control: the stand-in records the one request the rehearsal makes itself — a call would have been seen"
check "$(stray_snap)" "$STRAY0" "the stray store's folder ends the rehearsal exactly as seeded — through every deploy, check and rollback"
check "$(checkout_state)" "$CHECKOUT0" "this checkout is as the rehearsal found it: same commit, same tracked files"
check "$(ls -a "$REPO/scripts" | grep -cE '^\..+\.sh$')" "0" "no weakened or re-pinned copy is left in the clone's scripts/"
check "$( [ -f "$SB/anchor-missing" ] && tr '\n' ' ' < "$SB/anchor-missing" )" "" "every weakened copy of the script and every edit of an installed file found its anchor exactly once — none was skipped"
M="$(mutant anchorprobe "$DEPLOY" 'NO SUCH ANCHOR IN THE SCRIPT' 'x' 2>/dev/null)"
check "$( [ -f "$SB/anchor-missing" ] && tr '\n' ' ' < "$SB/anchor-missing" )$(test -e "$M" && echo made || echo none)" "mutant:anchorprobe none" \
  "control on the control: a weakened copy whose anchor is missing is recorded as such, and no copy is made"
rm -f "$SB/anchor-missing"
check "$( [ -f "$SB/bad-runs" ] && tr '\n' ' ' < "$SB/bad-runs" )" "" "every run of the script took the options it was given — none answered 'unknown option', so no check read a refused run as a pass"
OUT="$(run --after-only --rehearsal-unknown-option)"
check "$(has "$OUT" 'unknown option: --rehearsal-unknown-option')$( [ -s "$SB/bad-runs" ] && echo recorded)" "yesrecorded" \
  "control on the control: a run given an option the script does not know is recorded as such"
rm -f "$SB/bad-runs"
check "$(left_tmp)" "$TMP0" "no copy of the code, no throwaway layout and no lock-test folder is left in /tmp ($TMP0 before the rehearsal)"
check "$(grep -c 'Fatal\|Parse error' "$SB/web.log")" "0" "the stand-in served every request without a PHP fatal"

echo; echo "rehearsal: $PASS passed, $FAILN failed ($(cat "$SB/runs") runs of the script)"
[ "$FAILN" = "0" ]
