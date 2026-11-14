#!/usr/bin/env bash
#
# sales-followup-report.sh — P1, the read-only sales follow-up report at 5.18.105 (5.18.104's, its BY NUMBER column
# padded by characters: root docs/74 §H.4.2), run on the server by hand (Uganda; root docs/73 §H.2.8, §H.2.21 9.5).
# READ-ONLY: it reads a COPY of the book and changes nothing — no table, no key, no file of the plugin's, nothing in
# uCRM. As root on the host:
#
#   1. it finds the container, the installed plugin, the data folder and the book's owner (the deploy scripts' discovery),
#      and refuses a root-owned book;
#   2. it takes the report, tools/sales_followup_report.php, and the code it reads — lib/QuoteSendEvidence.php,
#      lib/HumanTakeover.php and the rest of the release's lib/ beside them (WaSendBudget and its readers, on a book that
#      already holds 089's ledger) and profiles/ — from Git at the reviewed 5.18.105 commit (7835906), the report's and
#      QuoteSendEvidence's sha256 checked; and it asserts that the INSTALLED PhoneNumber, TenantProfile, PluginConfig,
#      ConfigVault and ContactOptOut equal that commit's bytes (they are 5.18.104's too, so the report runs on 5.18.104,
#      before the deploy and after a rollback, as well);
#   3. as the book's owner, in a folder of mode 700 in the container's /tmp: it checks the free space (the book's size
#      × 3 + 64 MB); makes the copy with VACUUM INTO, runs integrity_check and sets journal_mode=DELETE; cuts the
#      settings row, with secure_delete=ON, the three settings files' copies and a vault's copy to the tenant keys and the
#      keys the report reads (wa_force_accounts among them, for "sent from"; never a secret); and puts the Git files
#      beside the copy, never into the plugin;
#   4. it runs the report with -d log_errors=0 -d opcache.enable_cli=0, the copy's folder as its data folder;
#   5. it prints the report's own lines — counts only: numbers by their last two digits, owners as owner 1…n — keeps
#      the owners' staff ids in a sheet of its own (mode 0600, in /root/dnb-report, mode 700; its path printed, never its
#      content), removes the folder (and again on exit, by a trap), and ends with "READ-ONLY — nothing was changed".
#
# Send back the LOG FILE, never the terminal and never the sheet — the one command (root docs/73 §H.2.21 9.5):
#
#   cd /opt/dishnet && git pull origin claude/study-this-jhe2eg && mkdir -p /root/dnb-report && bash scripts/sales-followup-report.sh 2>&1 | ( umask 077; exec tee /root/dnb-report/report-$(date -u +%Y%m%dT%H%M%SZ).log )
#
# There is no rollback, because nothing changes. This file is written by wip/derive-5.18.105/derive105.py from the
# release commit (its pins come from Git); never edit it by hand.
set -uo pipefail
umask 077

PLUGIN="dishnet-hybrid-sudan"
CONTAINER="${UCRM_CONTAINER:-ucrm}"
IN_CONTAINER="${IN_CONTAINER:-/data/ucrm/data/plugins/$PLUGIN}"
PLUGINS_IN="$(dirname "$IN_CONTAINER")"
REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
RELEASE_COMMIT="7835906"; RELEASE_COMMIT_FULL="7835906a0e4af49557f7c0eb994cb9129637ca00"; RELEASE_BRANCH="release/5.18.105"
REPORT="tools/sales_followup_report.php"
REPORT_SHA256="691ef613ba20c584a840d9070fe9217f188b8a7d3002b1e3b306ad7aede6e479"
QSE_SHA256="852a12ceb55a3dcb77c5f9a94b232ad17c922fb0ad903e3947b1223b1d04c540"   # lib/QuoteSendEvidence.php at the release commit
# the installed files the report reads that 5.18.104 and 5.18.105 share byte for byte (§H.2.21 9.5 step 2)
INSTALLED_SUMS="$(cat <<'SUMS'
ee36e20115aea190212b62a7b1ef5bbeac965114df4e8bf8474c309b6e5909d2  lib/PhoneNumber.php
6e37597c1e62fc7520c21feebc522ce0d07522dc41a81387fcff54203d185053  lib/TenantProfile.php
500d5643bec2b143b190b9d9c49ddd374a35554a652a9827870fe6991630a635  lib/PluginConfig.php
bf7e1b1f1f5e132e0ad3af96ad20f5fcb011307cb83d14ee504abcc4ee581da7  lib/ConfigVault.php
77b9981d8eefd10bf1a11f4d889f5cde576bb454ac3fb73a7c84fa7b6d54c599  lib/ContactOptOut.php
SUMS
)"
SHEET_DIR="${DNB_REPORT_DIR:-/root/dnb-report}"
TS="$(date -u +%Y%m%dT%H%M%SZ)"
W="/tmp/dnb-report-$TS-$$"; HOSTW="$(mktemp -d)"

stop() { printf '\n  STOP: %s\n  READ-ONLY — nothing was changed. Send the log file.\n' "$*"; exit 1; }
mask() { sed -E 's/[0-9]{9,}/<digits>/g'; }

printf '== sales follow-up report — read-only — %s — %s ==\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$(hostname)"
command -v docker >/dev/null 2>&1 || stop "docker is not available here"
docker exec "$CONTAINER" test -f "$IN_CONTAINER/manifest.json" </dev/null 2>/dev/null || stop "the plugin is not installed at $IN_CONTAINER in the '$CONTAINER' container"
PDD="$(docker exec "$CONTAINER" php -r '$u=@json_decode((string)@file_get_contents($argv[1]),true); echo rtrim((string)($u["pluginDataDir"]??""),"/");' "$IN_CONTAINER/ucrm.json" 2>/dev/null </dev/null || true)"
if [ -z "$PDD" ]; then
  if docker exec "$CONTAINER" test -f "$PLUGINS_IN/.$PLUGIN-data/plugin.sqlite3" </dev/null 2>/dev/null; then PDD="$PLUGINS_IN/.$PLUGIN-data"; else PDD=""; fi
fi
[ -n "$PDD" ] && [ "${PDD%/}" != "$IN_CONTAINER/data" ] || stop "the plugin's data directory could not be found beside the plugin"
DB="$PDD/plugin.sqlite3"
docker exec "$CONTAINER" test -f "$DB" </dev/null 2>/dev/null || stop "no book at the data directory"
OWNER="$(docker exec "$CONTAINER" stat -c '%u:%g' "$DB" 2>/dev/null </dev/null || true)"
case "$OWNER" in ''|*[!0-9:]*) stop "the book's owner could not be read" ;; 0:*) stop "the book is root's — the plugin's own user must own it" ;; esac
MOUNT="$(docker inspect "$CONTAINER" --format '{{range .Mounts}}{{if eq .Destination "/data"}}{{.Source}}{{end}}{{end}}' 2>/dev/null || true)"
DEST="$MOUNT/ucrm/data/plugins/$PLUGIN"
[ -n "$MOUNT" ] && [ -f "$DEST/manifest.json" ] || stop "the installed plugin could not be found on the host"
echo "  installed       commit $(tail -n1 "$DEST/.deployed-commit" 2>/dev/null | tr -cd '0-9a-f') · the book owned by $OWNER"

cleanup() { docker exec "$CONTAINER" rm -rf "$W" >/dev/null 2>&1 </dev/null || true; rm -rf "$HOSTW"; }
trap cleanup EXIT

# 2. the release's code, from Git, by its hash — fetched when the checkout does not hold it yet
if ! git -C "$REPO" cat-file -e "$RELEASE_COMMIT_FULL^{commit}" 2>/dev/null; then git -C "$REPO" fetch -q origin "$RELEASE_BRANCH" 2>/dev/null || true; fi
[ "$(git -C "$REPO" rev-parse -q --verify "$RELEASE_COMMIT^{commit}" 2>/dev/null)" = "$RELEASE_COMMIT_FULL" ] \
  || stop "the checkout does not hold the reviewed release commit $RELEASE_COMMIT_FULL — run: cd $REPO && git fetch origin $RELEASE_BRANCH, then this again"
[ "$(git -C "$REPO" show "$RELEASE_COMMIT_FULL:$PLUGIN/$REPORT" 2>/dev/null | sha256sum | cut -c1-64)" = "$REPORT_SHA256" ] || stop "the release's $REPORT is not the reviewed one (sha256)"
[ "$(git -C "$REPO" show "$RELEASE_COMMIT_FULL:$PLUGIN/lib/QuoteSendEvidence.php" 2>/dev/null | sha256sum | cut -c1-64)" = "$QSE_SHA256" ] || stop "the release's lib/QuoteSendEvidence.php is not the reviewed one (sha256)"
BAD=""; N=0
while read -r s f; do
  [ -n "$f" ] || continue; N=$((N + 1))
  [ "$(sha256sum "$DEST/$f" 2>/dev/null | cut -c1-64)" = "$s" ] || BAD="$BAD $f"
done <<< "$INSTALLED_SUMS"
[ -z "$BAD" ] || stop "these installed files are not the reviewed bytes the report was built against:$BAD"
echo "  code            $REPORT (sha256 ${REPORT_SHA256:0:16}…), lib/ and profiles/ from Git at $RELEASE_COMMIT; the installed $N files it shares with 5.18.104 equal that commit's bytes"
mkdir -p "$HOSTW/c" && git -C "$REPO" archive "$RELEASE_COMMIT_FULL" "$PLUGIN/$REPORT" "$PLUGIN/lib" "$PLUGIN/profiles" | tar --no-same-owner -xf - -C "$HOSTW/c" \
  && chmod -R a+rX "$HOSTW/c" || stop "the release's code could not be taken from Git"

# 3. the folder, the copy, the settings copies — as the book's owner
docker exec -u "$OWNER" "$CONTAINER" mkdir -m 700 "$W" </dev/null || stop "the run's folder could not be made in the container's /tmp"
SIZE="$(docker exec -u "$OWNER" "$CONTAINER" stat -c %s "$DB" 2>/dev/null </dev/null)"; FREE="$(docker exec -u "$OWNER" "$CONTAINER" php -r 'echo (int)@disk_free_space("/tmp");' 2>/dev/null </dev/null)"
case "$SIZE$FREE" in ''|*[!0-9]*) stop "the book's size or the free space could not be read" ;; esac
[ "$FREE" -ge $(( SIZE * 3 + 67108864 )) ] || stop "the container's /tmp has $FREE bytes free; the copy needs $(( SIZE * 3 + 67108864 ))"
docker cp "$HOSTW/c/$PLUGIN" "$CONTAINER:$W/r" >/dev/null 2>&1 </dev/null && docker exec "$CONTAINER" sh -c 'chown -R "$1" "$2/r" && chmod -R go-rwx "$2/r"' _ "$OWNER" "$W" </dev/null \
  || stop "the release's code could not be put in the run's folder"
COPY="$(docker exec -u "$OWNER" "$CONTAINER" php -d display_errors=stderr -r '
  [$src, $w, $live, $pdd] = array_slice($argv, 1, 4);
  require $w . "/r/lib/TenantProfile.php";
  // the keys the report and the code it calls read (computed from the release commit by the derivation, which asserts
  // every key the report reads is among them and none is a secret), beside the tenant selectors; no apostrophe here:
  // this code is a single-quoted shell word
  $keep = array_values(array_unique(array_merge(TenantProfile::SELECTOR_SOURCES, ["evo_instance_account", "evo_instance_sales", "evo_instance_support", "quote_daily_list_enabled", "quote_tracking_since", "reminder_hour", "reminder_until_hour", "tenant_profile", "timezone", "wa_force_accounts", "wa_send_daily_cap", "wa_send_gap_max_s", "wa_send_gap_min_s", "wa_send_hour_from", "wa_send_hour_to", "wa_send_hourly_cap", "wa_send_ledger", "wa_send_ledger_since", "wa_voice_ack_daily_max", "wa_voice_ack_gap_s", "wa_voice_ack_hour_from", "wa_voice_ack_hour_to", "wa_voice_ack_period_hours"])));
  $cut = function ($d) use ($keep) { $o = []; if (is_array($d)) foreach ($keep as $k) if (array_key_exists($k, $d)) $o[$k] = $d[$k]; return $o; };
  try {
    $o = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 30];
    $db = new PDO("sqlite:" . $src, null, null, $o); $db->exec("VACUUM INTO " . $db->quote($w . "/copy.sqlite3")); $db = null;
    @chmod($w . "/copy.sqlite3", 0600);
    $c = new PDO("sqlite:" . $w . "/copy.sqlite3", null, null, $o);
    if ($c->query("PRAGMA integrity_check")->fetchAll(PDO::FETCH_COLUMN) !== ["ok"]) { echo "?integrity"; exit(3); }
    $c->exec("PRAGMA journal_mode = DELETE"); $c->exec("PRAGMA secure_delete = ON");
    $rows = 0;
    if ($c->query("SELECT 1 FROM sqlite_master WHERE type = \x27table\x27 AND name = \x27kyc_config\x27")->fetchColumn() !== false) {
      $u = $c->prepare("UPDATE kyc_config SET data = ? WHERE id = ?");
      foreach ($c->query("SELECT id, data FROM kyc_config")->fetchAll(PDO::FETCH_NUM) as $r) { $u->execute([json_encode($cut(json_decode((string)$r[1], true)), JSON_FORCE_OBJECT), $r[0]]); $rows++; }
    }
    $c = null;
    @mkdir($w . "/r/data", 0700);
    foreach ([[$live . "/data/config.json", $w . "/r/data/config.json"], [$pdd . "/config.json", $w . "/config.json"], [$pdd . "/kyc_config.json", $w . "/kyc_config.json"]] as [$f, $t]) {
      if (!is_file($f) || !is_readable($f)) continue;
      file_put_contents($t, json_encode($cut(json_decode((string)file_get_contents($f), true)), JSON_FORCE_OBJECT)); @chmod($t, 0600);
    }
    // the live vault where ConfigVault::path() keeps it (DN_VAULT_FILE, else the plugins folder when writable, else the
    // data folder), read only — the same keys alone go into the vault of the copy
    $ov = (string)getenv("DN_VAULT_FILE"); $pd = dirname(rtrim($live, "/"));
    $vp = $ov !== "" ? $ov : ((is_dir($pd) && is_writable($pd)) ? $pd . "/.dishnet-sudan.vault.json" : rtrim($pdd, "/") . "/config_vault.json");
    $v = is_file($vp) ? json_decode((string)@file_get_contents($vp), true) : null;
    file_put_contents($w . "/vault.json", json_encode(["config" => $cut(is_array($v) ? ($v["config"] ?? []) : [])], JSON_FORCE_OBJECT)); @chmod($w . "/vault.json", 0600);
    clearstatcache(); $side = (is_file($w . "/copy.sqlite3-wal") || is_file($w . "/copy.sqlite3-journal")) ? "yes" : "no";
    echo "copy=ok settings-rows=$rows side=$side";
  } catch (Throwable $e) { echo "?", get_class($e); exit(2); }
' "$DB" "$W" "$IN_CONTAINER" "$PDD" 2>/dev/null </dev/null)"
case "$COPY" in "copy=ok "*" side=no") echo "  copy            $COPY — the book's consistent copy (VACUUM INTO), integrity_check ok, its settings row cut to the tenant keys and the keys the report reads" ;;
  *) stop "the copy could not be made (${COPY:-?})" ;; esac

# 4. the report, on the copy, with the folder as its data folder
mkdir -p "$SHEET_DIR" && chmod 700 "$SHEET_DIR" || stop "the sheet's folder $SHEET_DIR could not be made"
OUT="$(docker exec -u "$OWNER" -e DN_VAULT_FILE="$W/vault.json" -e TMPDIR="$W" "$CONTAINER" php -d log_errors=0 -d opcache.enable_cli=0 \
        "$W/r/$REPORT" "--db=$W/copy.sqlite3" "--data=$W" "--sheet=$W/owners.txt" 2>&1 </dev/null)"; RC=$?

# 5. print, keep the owners' sheet, remove the folder
printf '%s\n' "$OUT" | mask
SHEET="$SHEET_DIR/owners-$TS.txt"
if docker exec -u "$OWNER" "$CONTAINER" test -f "$W/owners.txt" </dev/null 2>/dev/null; then
  ( umask 077; docker exec -u "$OWNER" "$CONTAINER" cat "$W/owners.txt" </dev/null > "$SHEET" 2>/dev/null ) && chmod 600 "$SHEET" \
    && echo "  owners          owner n = staff id, in $SHEET (mode 0600) — read it on the server only: less $SHEET" \
    || echo "  owners          the owners' sheet could not be kept"
fi
cleanup; trap - EXIT
echo "  exit $RC (0 done or not this install; 2 refused before reading; 3 a check failed)"
echo "  READ-ONLY — nothing was changed"
exit "$RC"
