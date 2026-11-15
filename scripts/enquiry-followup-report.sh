#!/usr/bin/env bash
#
# enquiry-followup-report.sh — READ-ONLY: why Uganda's enquiry follow-ups pile up (15 Nov 2026; root docs/07).
#
# It reads a COPY of the book and changes nothing — no table, no row, no key, no setting, nothing in uCRM or Evolution.
# The one trace: reading the book, as any of its readers does, can leave its own WAL files beside it (an empty
# plugin.sqlite3-wal and its -shm, the plugin user's) when no plugin process had them open; the log says when it did. It prints COUNTS ONLY: no phone number, no name, no message text, no Evolution instance; a number is named
# by its registry id (sales, support, account, sales-001 …) and its kind (department or seller). As root on the host:
#
#   1. it finds the container, the installed plugin, the data folder and the book's owner (the report wrappers'
#      discovery), and refuses a root-owned book;
#   2. it checks scripts/enquiry-followup-report.php beside it against the sha256 this file pins, takes lib/PluginConfig.php
#      and lib/ConfigVault.php from Git at the installed 5.18.104 commit (994d660), and asserts that the INSTALLED two
#      equal those bytes (5.18.105 leaves both as they are, so it runs before and after that deploy alike);
#   3. as root it makes a folder of mode 700 in the container's /tmp and puts the report's code in it, then hands the
#      folder to the book's owner (so nothing in it was ever the owner's to plant before root wrote there); as the
#      book's owner it checks the free space (the book's size × 2 + 64 MB); makes the copy with VACUUM INTO from a
#      READ-ONLY connection to the book (a read-write one would fold the book's pending WAL into it on closing) and runs
#      integrity_check on the copy, read-only too; and copies the three settings files cut to the keys the report
#      reads (never a secret) — the vault, which holds credentials, is never read: no key kept is a vault key;
#   4. it runs the report on the copy — which opens it read-only (SQLITE_OPEN_READONLY, query_only) and prints the
#      changes it made to it, which must be 0 — with -d log_errors=0 -d opcache.enable_cli=0;
#   5. it prints the report's lines, masks any run of nine or more digits as a last guard, removes the folder (and on
#      any other exit, by a trap), checks that it is gone — and says so loudly if it is not, since it holds a copy of
#      the book — and ends with "READ-ONLY — nothing was changed".
#
# Send back the LOG FILE, never the terminal — the one command:
#
#   cd /opt/dishnet && git pull origin claude/study-this-jhe2eg && mkdir -p /root/dnb-report && bash scripts/enquiry-followup-report.sh 2>&1 | ( umask 077; exec tee /root/dnb-report/enquiry-$(date -u +%Y%m%dT%H%M%SZ).log )
#
# There is no rollback, because nothing changes. Tested by scripts/harness/enquiry-followup-report/.
set -uo pipefail
umask 077

PLUGIN="dishnet-hybrid-sudan"
CONTAINER="${UCRM_CONTAINER:-ucrm}"
IN_CONTAINER="${IN_CONTAINER:-/data/ucrm/data/plugins/$PLUGIN}"
PLUGINS_IN="$(dirname "$IN_CONTAINER")"
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO="$(cd "$HERE/.." && pwd)"
REPORT="$HERE/enquiry-followup-report.php"
REPORT_SHA256="643201e64097833738c57a13256c48edf0385cb5d23e685a03012b4721b2b462"
CODE_COMMIT_FULL="994d660628cc5b1b42bee648b44b58e389f44bf2"; CODE_BRANCH="release/5.18.104"
CODE_SUMS="$(cat <<'SUMS'
500d5643bec2b143b190b9d9c49ddd374a35554a652a9827870fe6991630a635  lib/PluginConfig.php
bf7e1b1f1f5e132e0ad3af96ad20f5fcb011307cb83d14ee504abcc4ee581da7  lib/ConfigVault.php
SUMS
)"
TS="$(date -u +%Y%m%dT%H%M%SZ)"
W="/tmp/dnb-enquiry-$TS-$$"; HOSTW=""

stop() { printf '\n  STOP: %s\n  READ-ONLY — nothing was changed. Send the log file.\n' "$*"; exit 1; }
mask() { sed -E 's/[0-9]{9,}/<digits>/g'; }

printf '== enquiry follow-up report — read-only — %s — %s ==\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$(hostname)"
command -v docker >/dev/null 2>&1 || stop "docker is not available here"
[ "$(sha256sum "$REPORT" 2>/dev/null | cut -c1-64)" = "$REPORT_SHA256" ] || stop "scripts/enquiry-followup-report.php is not the reviewed one (sha256) — run: cd $REPO && git pull origin claude/study-this-jhe2eg, then this again"
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

CLEANED=0
cleanup() {
  [ "$CLEANED" = 1 ] && return 0; CLEANED=1
  docker exec "$CONTAINER" rm -rf "$W" >/dev/null 2>&1 </dev/null || true
  if ! docker exec "$CONTAINER" test ! -e "$W" >/dev/null 2>&1 </dev/null; then
    printf '  WARNING: the run'"'"'s folder %s could not be confirmed gone from the container; it would hold a copy of the book. Remove it: docker exec %s rm -rf %s\n' "$W" "$CONTAINER" "$W"
  fi
  [ -z "$HOSTW" ] || rm -rf "$HOSTW"
}
trap cleanup EXIT
HOSTW="$(mktemp -d)" || stop "a folder of this run's could not be made"

# 2. the two settings readers, from Git at the installed commit, by their hash; the installed two the same bytes
if ! git -C "$REPO" cat-file -e "$CODE_COMMIT_FULL^{commit}" 2>/dev/null; then git -C "$REPO" fetch -q origin "$CODE_BRANCH" 2>/dev/null || true; fi
git -C "$REPO" cat-file -e "$CODE_COMMIT_FULL^{commit}" 2>/dev/null \
  || stop "the checkout does not hold the installed commit $CODE_COMMIT_FULL — run: cd $REPO && git fetch origin $CODE_BRANCH, then this again"
BAD=""; N=0
mkdir -p "$HOSTW/r/lib" "$HOSTW/r/data" || stop "a folder of this run's could not be made"
while read -r s f; do
  [ -n "$f" ] || continue; N=$((N + 1))
  git -C "$REPO" show "$CODE_COMMIT_FULL:$PLUGIN/$f" > "$HOSTW/r/$f" 2>/dev/null || BAD="$BAD $f(git)"
  [ "$(sha256sum "$HOSTW/r/$f" 2>/dev/null | cut -c1-64)" = "$s" ] || BAD="$BAD $f(git-sha)"
  [ "$(sha256sum "$DEST/$f" 2>/dev/null | cut -c1-64)" = "$s" ] || BAD="$BAD $f(installed)"
done <<< "$CODE_SUMS"
[ -z "$BAD" ] || stop "these files are not the reviewed bytes the report was built against:$BAD"
cp "$REPORT" "$HOSTW/report.php" && chmod -R a+rX "$HOSTW/r" "$HOSTW/report.php" || stop "the report could not be staged"
echo "  code            scripts/enquiry-followup-report.php (sha256 ${REPORT_SHA256:0:8}…); lib/PluginConfig.php and lib/ConfigVault.php from Git at ${CODE_COMMIT_FULL:0:7}, the installed $N the same bytes"

# 3. the folder, the copy, the settings copies — as the book's owner
docker exec "$CONTAINER" mkdir -m 700 "$W" </dev/null || stop "the run's folder could not be made in the container's /tmp"
SIZE="$(docker exec -u "$OWNER" "$CONTAINER" stat -c %s "$DB" 2>/dev/null </dev/null)"; FREE="$(docker exec -u "$OWNER" "$CONTAINER" php -r 'echo (int)@disk_free_space("/tmp");' 2>/dev/null </dev/null)"
case "$SIZE$FREE" in ''|*[!0-9]*) stop "the book's size or the free space could not be read" ;; esac
[ "$FREE" -ge $(( SIZE * 2 + 67108864 )) ] || stop "the container's /tmp has $FREE bytes free; the copy needs $(( SIZE * 2 + 67108864 ))"
docker cp "$HOSTW/r" "$CONTAINER:$W/r" >/dev/null 2>&1 </dev/null && docker cp "$HOSTW/report.php" "$CONTAINER:$W/report.php" >/dev/null 2>&1 </dev/null \
  && docker exec "$CONTAINER" sh -c 'chmod -R go-rwx "$2" && chown -R "$1" "$2"' _ "$OWNER" "$W" </dev/null \
  || stop "the report's code could not be put in the run's folder"
COPY="$(docker exec -u "$OWNER" "$CONTAINER" php -d display_errors=stderr -r '
  [$src, $w, $live, $pdd] = array_slice($argv, 1, 4);
  // the follow-up keys the report reads; none is a secret. No apostrophe here: this code is a single-quoted shell word
  $keep = ["followup_enabled", "followup_auto_send", "followup_daily_cap", "followup_run_limit", "followup_scan_limit",
           "followup_max_age_hours", "followup_not_before", "wa_followups_on_owned_numbers", "multi_number_channels_enabled",
           "wa_send_ledger", "quote_chase_enabled", "offers_test_enabled", "offers_live_enabled", "wa_send_daily_cap",
           "wa_send_hourly_cap", "wa_send_hour_from", "wa_send_hour_to"];
  $cut = function ($d) use ($keep) { $o = []; if (is_array($d)) foreach ($keep as $k) if (array_key_exists($k, $d)) $o[$k] = $d[$k]; return $o; };
  try {
    // READ-ONLY, the book and the copy alike: a read-write connection to a WAL book folds its pending frames into it
    // when it is the last to close; a read-only one never does
    $o = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 30, PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY];
    clearstatcache(); $walPre = is_file($src . "-wal");
    $db = new PDO("sqlite:" . $src, null, null, $o); $db->exec("VACUUM INTO " . $db->quote($w . "/copy.sqlite3")); $db = null;
    clearstatcache(); $wal = $walPre ? "pre" : (is_file($src . "-wal") ? "new" : "none");
    @chmod($w . "/copy.sqlite3", 0600);
    $c = new PDO("sqlite:" . $w . "/copy.sqlite3", null, null, $o);
    if ($c->query("PRAGMA integrity_check")->fetchAll(PDO::FETCH_COLUMN) !== ["ok"]) { echo "?integrity"; exit(3); }
    $c = null;
    foreach ([[$live . "/data/config.json", $w . "/r/data/config.json"], [$pdd . "/config.json", $w . "/config.json"], [$pdd . "/kyc_config.json", $w . "/kyc_config.json"]] as [$f, $t]) {
      if (!is_file($f) || !is_readable($f)) continue;
      file_put_contents($t, json_encode($cut(json_decode((string)file_get_contents($f), true)), JSON_FORCE_OBJECT)); @chmod($t, 0600);
    }
    // the time public.php last ran master from an admin page (one integer, nothing else is copied): the report tells
    // that cycle, in which the follow-up jobs do nothing, from a real pass
    $pg = $pdd . "/piggyback_last_run.txt";
    if (is_file($pg) && is_readable($pg)) {
      $v = trim((string)file_get_contents($pg, false, null, 0, 32));
      if (preg_match("/^[0-9]{1,12}\$/", $v)) { file_put_contents($w . "/piggyback_last_run.txt", $v); @chmod($w . "/piggyback_last_run.txt", 0600); }
    }
    // the live vault is never read: none of the keys above is one of ConfigVault::VAULT_KEYS at 994d660 (the harness
    // asserts it), so the vault of the copy is empty and the vault, which holds credentials, is never opened
    file_put_contents($w . "/vault.json", json_encode(["config" => []], JSON_FORCE_OBJECT)); @chmod($w . "/vault.json", 0600);
    clearstatcache(); $side = (is_file($w . "/copy.sqlite3-wal") || is_file($w . "/copy.sqlite3-journal")) ? "yes" : "no";
    echo "copy=ok side=$side wal=$wal";
  } catch (Throwable $e) { echo "?", get_class($e); exit(2); }
' "$DB" "$W" "$IN_CONTAINER" "$PDD" 2>/dev/null </dev/null)"
case "$COPY" in "copy=ok side=no wal="*) echo "  copy            the book's consistent copy (VACUUM INTO, read-only), integrity_check ok; the settings copies cut to the keys the report reads" ;;
  *) stop "the copy could not be made (${COPY:-?})" ;; esac
[ "$COPY" = "copy=ok side=no wal=new" ] && echo "  the book        no plugin process had it open, so reading it left its WAL files beside it (an empty plugin.sqlite3-wal and its -shm, the plugin user's): the plugin's next write uses them" 

# 4. the report, on the copy, read-only
OUT="$(docker exec -u "$OWNER" -e DN_VAULT_FILE="$W/vault.json" -e TMPDIR="$W" "$CONTAINER" php -d log_errors=0 -d opcache.enable_cli=0 \
        "$W/report.php" "--db=$W/copy.sqlite3" "--root=$W/r" "--data=$W" 2>&1 </dev/null)"; RC=$?

# 5. print, remove the folder
printf '%s\n' "$OUT" | mask
cleanup; trap - EXIT
echo "  exit $RC (0 done; 2 refused before reading; 3 not a book with enquiry follow-ups, or the copy changed)"
echo "  READ-ONLY — nothing was changed"
exit "$RC"
