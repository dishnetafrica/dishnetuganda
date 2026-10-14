#!/usr/bin/env bash
#
# set-email-badge-5.18.94.sh — change the one setting 5.18.94's deploy refused over (A18, 13 Oct, begun 11:43:39 UTC):
# email_badge_line still says UCC, in the configuration file and in the store row. It sets it to "Starlink Installation"
# with the plugin's own tool, tools/set_email_brand.php --set, exactly as the deploy's refusal says — that tool saves
# through PluginConfig::saveOverrides(): kyc_config.json first, then the same keys into the store row.
#
# Run it as root on the server, then send back THE LOG FILE (never a copy of the terminal). The command is in the
# hand-over; it is not repeated here.
#
# In order — it stops at the first thing that is not as expected, and says whether anything was changed:
#   1. the container runs 5.18.93 (69c87a6), and the tool and every library under lib/ are 69c87a6's byte for byte
#      (compared with Git, read as files on the host); the live data folder and its database's owner, found the way
#      the deploy finds them; kyc_config.json owned by that same user;
#   2. nothing at the stray store's old name in <plugin>/data, and that folder's entries and time, read as files;
#   3. the deploy's own A18 reader (taken from scripts/deploy-5.18.94.sh, checked by its sha256), over the installed code;
#   4. if the badge already reads "Starlink Installation" in both copies, nothing is done. Otherwise kyc_config.json
#      against the store row, key by key, read only. The save copies EVERY key of the file into the store row, so it
#      refuses if any other key differs, or is in the file and not in the store — naming the keys, never a value.
#      Keys only the store holds are kept by the save, and are allowed;
#   5. the save, as the database's owner, aimed at the live data folder (DN_DATA_DIR) — never at <plugin>/data, never
#      as root; the tool's own output is kept out of the log (it lists the brand, phone numbers among it);
#   6. both copies again: the badge "Starlink Installation" in both, every other key as before in both,
#      kyc_config.json's owner as before, the A18 reader's other answers as before, <plugin>/data untouched.
# It prints key names, counts, set/unset, UCC/ok and short hashes — never a setting's value, nor the retention's
# number. A reading that fails after the save is reported as not measured, never as not set. It sends nothing;
# nothing outside the container is called. Afterwards, run the 5.18.94 deploy again, the same command as before.

set -u
CONTAINER="${UCRM_CONTAINER:-ucrm}"
PLUGIN="dishnet-hybrid-sudan"
IN_CONTAINER="/data/ucrm/data/plugins/$PLUGIN"
LIVE_EXPECTED="69c87a6"                                    # 5.18.93 — what the server runs until 5.18.94 is deployed
KEY="email_badge_line"; WANT="Starlink Installation"
UCC_KEYS="ai_identity_line wa_flyer_caption email_badge_line"   # the deploy's (A18), in its order
RETENTION_KEY="recording_retention_days"                        # the deploy's (A21)
UCC_PHP_SHA="da9c6380ee6af13d379332c72091850292573e543febca20ded37071aceb23ad"   # the A18 reader in deploy-5.18.94.sh
REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
N=0; CHANGED="nothing was changed"
ok()   { N=$((N+1)); printf '  ok    %s\n' "$*"; }
stop() { printf '\n  STOP: %s\n\n  set-email-badge: NOT DONE — %s. Send the log file.\n' "$*" "$CHANGED"; exit 1; }
dx()   { docker exec "$@" </dev/null; }

printf '\n== set-email-badge (5.18.94, A18) — %s — %s ==\n\n' "$(date -u +%Y%m%dT%H%M%SZ)" "$(hostname)"
[ "$(id -u)" = "0" ] || stop "run this as root"
command -v docker >/dev/null 2>&1 || stop "no docker on this host"

# ── 1 the container, the code, the live data folder and its owner ─────────────────────────────────────────────
[ "$(docker inspect -f '{{.State.Running}}' "$CONTAINER" 2>/dev/null)" = "true" ] || stop "the $CONTAINER container is not running"
MOUNT="$(docker inspect "$CONTAINER" --format '{{range .Mounts}}{{if eq .Destination "/data"}}{{.Source}}{{end}}{{end}}' 2>/dev/null || true)"
[ -n "$MOUNT" ] || stop "the $CONTAINER container has no /data mount"
DEST="$MOUNT/ucrm/data/plugins/$PLUGIN"
[ -f "$DEST/manifest.json" ] || stop "no installed plugin at $DEST"
LIVE="$(dx "$CONTAINER" cat "$IN_CONTAINER/.deployed-commit" 2>/dev/null | tail -n1 | tr -cd '0-9a-f')"
[ "$LIVE" = "$LIVE_EXPECTED" ] || stop "the container serves ${LIVE:-an unknown commit}, not $LIVE_EXPECTED (5.18.93) — this was tested on 5.18.93's code only"
PDD_IN="$(dx "$CONTAINER" php -r '$u=@json_decode((string)@file_get_contents($argv[1]),true); echo rtrim((string)($u["pluginDataDir"]??""),"/");' "$IN_CONTAINER/ucrm.json" 2>/dev/null || true)"
if [ -z "$PDD_IN" ]; then
  if [ -f "$MOUNT/ucrm/data/plugins/.$PLUGIN-data/plugin.sqlite3" ]; then PDD_IN="/data/ucrm/data/plugins/.$PLUGIN-data"; else stop "ucrm.json names no data folder, and there is no .$PLUGIN-data/plugin.sqlite3 beside the plugin"; fi
fi
[ "${PDD_IN%/}" != "$IN_CONTAINER/data" ] || stop "the data folder would be $IN_CONTAINER/data — the stray store's old place, which this must never open"
U="$(dx "$CONTAINER" stat -c '%u:%g' "$PDD_IN/plugin.sqlite3" 2>/dev/null || true)"
case "$U" in ''|0:*|*:*:*) stop "the live database's owner is ${U:-unreadable} — expected a non-root owner (1000:1000 on 13 Oct)" ;; esac
KS0="$(dx "$CONTAINER" stat -c '%u:%g %a' "$PDD_IN/kyc_config.json" 2>/dev/null || true)"
[ -n "$KS0" ] || stop "no $PDD_IN/kyc_config.json"
[ "${KS0%% *}" = "$U" ] || stop "kyc_config.json is owned by ${KS0%% *}, the database by $U — the save, as $U, would hand the file to $U"
# The code the save runs — the tool and every library — byte for byte 69c87a6's, read as files on the host.
command -v git >/dev/null 2>&1 || stop "no git on this host"
NB=0; BAD=""
while IFS=$'\t' read -r meta path; do
  rel="${path#$PLUGIN/}"; NB=$((NB+1))
  [ -f "$DEST/$rel" ] && [ "$(git hash-object --no-filters -- "$DEST/$rel" 2>/dev/null)" = "${meta##* }" ] || BAD="$BAD $rel"
done < <(git -C "$REPO" ls-tree -r "$LIVE_EXPECTED" -- "$PLUGIN/lib" "$PLUGIN/tools/set_email_brand.php" 2>/dev/null)
[ "$NB" -gt 100 ] || stop "could not list $LIVE_EXPECTED's lib/ in $REPO ($NB files) — git pull first"
[ -z "$BAD" ] || stop "the installed code is not $LIVE_EXPECTED's byte for byte:$(printf '%s' "$BAD" | cut -c1-300)"
echo "  live            $LIVE in $CONTAINER, the save's $NB files byte for byte; data $PDD_IN; database owner $U; kyc_config.json $KS0"
ok "1 5.18.93 is live, and the tool and its libraries are $LIVE_EXPECTED's byte for byte; the live data folder and its owner found; kyc_config.json owned by the same user"

# ── 2 the stray store's old name, read as files on the host (never opened) ────────────────────────────────────
stray() {   # the old place's entries (names only) and the folder's time; or nodir
  local d="$DEST/data"; [ -d "$d" ] || { echo "nodir"; return; }
  echo "dir=$(stat -c %Y "$d") names=$(cd "$d" && ls -A | LC_ALL=C sort | paste -sd/ -)"
}
for s in "" -wal -shm -journal; do [ ! -e "$DEST/data/plugin.sqlite3$s" ] || stop "something is at the stray store's old name ($DEST/data/plugin.sqlite3$s) — the quarantine does not hold. Run its --check"; done
ST0="$(stray)"
ok "2 nothing at the stray store's old name in <plugin>/data"

# ── 3 the deploy's own A18 reader, over the installed code ────────────────────────────────────────────────────
UCC_PHP="$(sed -n "/^UCC_PHP=\"\$(cat <<'PHP'\$/,/^PHP\$/p" "$REPO/scripts/deploy-5.18.94.sh" 2>/dev/null | sed '1d;$d')"
[ "$(printf '%s\n' "$UCC_PHP" | sha256sum | cut -c1-64)" = "$UCC_PHP_SHA" ] || stop "the A18 reader in $REPO/scripts/deploy-5.18.94.sh is not the one this was tested with — git pull first"
a18() { dx -u "$U" "$CONTAINER" php -d display_errors=stderr -r "$UCC_PHP" "$IN_CONTAINER" "$IN_CONTAINER" "$PDD_IN" "$UCC_KEYS" "$RETENTION_KEY" 2>/dev/null || echo "?"; }
fld() { printf '%s' "$1" | tr ' ' '\n' | sed -n "s/^$2=//p" | head -1; }
shown() { printf '%s' "$1" | sed -E 's/ retention=[^ ]*//'; }   # the A18 line without the retention's number
A0="$(a18)"
echo "  A18 reads       $(shown "$A0")   (files/store, over 5.18.93's code)"
case "$A0" in *"retention="*) ;; *) stop "the A18 reader could not read the settings as $U ($(shown "$A0"))" ;; esac
ok "3 the deploy's A18 reader runs as $U over the installed code"

# ── 4 kyc_config.json against the store row, every key but the badge — names and counts only ─────────────────
CMP='$d = $argv[1]; $skip = $argv[2];
$c = function ($v) use (&$c) { if (!is_array($v)) return $v; $l = array_keys($v) === range(0, count($v) - 1); if (!$l) ksort($v); foreach ($v as $k => $x) $v[$k] = $c($x); return $v; };
$f = json_decode((string)@file_get_contents("$d/kyc_config.json"), true);
if (!is_array($f)) { echo "?file"; exit(3); }
$p = new PDO("sqlite:$d/plugin.sqlite3", null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 15, PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY]);
$r = $p->query("SELECT data FROM kyc_config WHERE id = 0 LIMIT 1")->fetchColumn();
$s = is_string($r) ? json_decode($r, true) : null;
if (!is_array($s)) { echo "?store"; exit(3); }
$diff = [];
foreach ($f as $k => $v) { if ($k === $skip) continue; if (!array_key_exists($k, $s)) $diff[] = "$k(file-only)"; elseif ($c($s[$k]) !== $c($v)) $diff[] = $k; }
$b = function (array $x) use ($skip) { $v = trim((string)($x[$skip] ?? "")); return ($v === "" ? "unset" : ($v === "Starlink Installation" ? "want" : "set")) . ":" . (preg_match("/\\bUCC\\b/i", $v) ? "UCC" : "ok"); };
$h = function (array $x) use ($skip, $c) { unset($x[$skip]); return substr(hash("sha256", json_encode($c($x))), 0, 16); };
$f2 = $f; unset($f2[$skip]); $s2 = $s; unset($s2[$skip]);
echo "keys=", count($f2), "/", count($s2), " storeonly=", count(array_diff_key($s2, $f2)), " differ=", ($diff ? implode(",", $diff) : "none"), " badge=", $b($f), "/", $b($s), " rest=", $h($f), "/", $h($s);'
cmpst() { local o; o="$(dx -u "$U" "$CONTAINER" php -r "$CMP" "$PDD_IN" "$KEY" 2>/dev/null)"; printf '%s' "${o:-?}"; }
C0="$(cmpst)"
echo "  copies          $C0   (kyc_config.json/store row)"
case "$C0" in keys=*) ;; *) stop "kyc_config.json and the store row could not be read read-only as $U ($C0)" ;; esac
hits() { printf '%s' "$1" | tr ' ' '\n' | grep -F UCC | sed 's/=.*//' | paste -sd' ' -; }
note_hits() { [ -z "$1" ] || echo "  NOTE: the A18 reader still finds UCC in: $1 — not this script's key; change it on the WhatsApp AI screen (Save marketing) before the deploy, or the deploy stops at A18 again."; }
if [ "$(fld "$C0" badge)" = "want:ok/want:ok" ] && [ "$(fld "$A0" "$KEY")" = "set:ok/set:ok" ]; then
  printf '\n  The badge already reads "%s" in both copies — nothing to do.\n' "$WANT"; note_hits "$(hits "$A0")"
  printf '\n  set-email-badge: PASSED (nothing needed changing). Send this LOG FILE back, then run the 5.18.94 deploy again, the same command as before.\n'; exit 0
fi
[ "$(fld "$C0" differ)" = "none" ] || stop "kyc_config.json and the store row differ in: $(fld "$C0" differ). The save would copy the file's value of each into the store. Nothing was changed — send the log file; each one is decided first"
ok "4 kyc_config.json and the store row agree on every key but the badge ($(fld "$C0" keys) other keys, $(fld "$C0" storeonly) only in the store, which the save keeps)"

# ── 5 the save ─────────────────────────────────────────────────────────────────────────────────────────────────
CHANGED="the save was attempted — what it changed is in the lines above and below"
OUT="$(dx -u "$U" -e DN_DATA_DIR="$PDD_IN" "$CONTAINER" php "$IN_CONTAINER/tools/set_email_brand.php" --set "$KEY=$WANT" 2>&1)"; rc=$?
SAVED="$(printf '%s\n' "$OUT" | grep -m1 '^Saved ' || true)"
if [ "$rc" != "0" ]; then
  C1="$(cmpst)"; echo "  copies now      $C1"
  [ "$C1" = "$C0" ] && CHANGED="nothing was changed (both copies read as before)"
  stop "tools/set_email_brand.php exited $rc: $(printf '%s\n' "$OUT" | grep -E '^(FAILED|Nothing to do|  THIS IS NOT)' | head -2 | tr '\n' ' ')"
fi
if [ "$SAVED" != "Saved 1 email branding key(s) to $PDD_IN/kyc_config.json" ]; then
  C1="$(cmpst)"; echo "  copies now      $C1"
  [ "$C1" = "$C0" ] && CHANGED="nothing was changed (both copies read as before)"
  stop "the tool did not say it saved the one key to $PDD_IN/kyc_config.json (${SAVED:-no Saved line})"
fi
CHANGED="email_badge_line was saved"
ok "5 tools/set_email_brand.php saved the one key to the live data folder, as $U"

# ── 6 both copies again ────────────────────────────────────────────────────────────────────────────────────────
C1="$(cmpst)"; A1="$(a18)"; KS1="$(dx "$CONTAINER" stat -c '%u:%g %a' "$PDD_IN/kyc_config.json" 2>/dev/null || true)"; ST1="$(stray)"
echo "  copies after    $C1"
echo "  A18 reads       $(shown "$A1")   (files/store, over 5.18.93's code)"
case "$C1" in keys=*) ;; *) stop "kyc_config.json and the store row could not be read after the save ($C1) — the save ran, and what it changed is not measured. Run this script again: it reads both copies again and changes nothing more if the badge is set" ;; esac
case "$A1" in *"retention="*) ;; *) stop "the A18 reader could not read the settings after the save ($(shown "$A1")) — the save ran, and A18's reading is not measured. Run this script again: it reads both again and changes nothing more if the badge is set" ;; esac
[ "$(fld "$C1" badge)" = "want:ok/want:ok" ] || stop "the badge is not \"$WANT\" in both copies after the save ($(fld "$C1" badge), kyc_config.json/store row)"
[ "$(fld "$C1" differ)" = "none" ] && [ "$(fld "$C1" keys)" = "$(fld "$C0" keys)" ] && [ "$(fld "$C1" rest)" = "$(fld "$C0" rest)" ] || stop "a key other than the badge changed in one of the copies (before: $C0)"
[ "$(fld "$A1" "$KEY")" = "set:ok/set:ok" ] && [ "$(fld "$A1" badge-shown)" = "ok/ok" ] || stop "the A18 reader still finds UCC in the badge ($(fld "$A1" "$KEY"), shown $(fld "$A1" badge-shown))"
for k in ai_identity_line wa_flyer_caption; do [ "$(fld "$A1" "$k")" = "$(fld "$A0" "$k")" ] || stop "the A18 reader's $k changed ($(fld "$A0" "$k") → $(fld "$A1" "$k"))"; done
[ "$(fld "$A1" retention)" = "$(fld "$A0" retention)" ] || stop "the A18 reader's retention changed"
[ "${KS1%% *}" = "$U" ] || stop "kyc_config.json is now owned by ${KS1%% *}, not $U"
[ "$ST1" = "$ST0" ] && [ ! -e "$DEST/data/plugin.sqlite3" ] || stop "<plugin>/data changed during the save ($ST0 → $ST1) — run the quarantine's --check"
ok "6 the badge reads \"$WANT\" in both copies; every other key as before; kyc_config.json still owned by $U (mode ${KS1##* }); <plugin>/data untouched"

echo; echo "  checks          $N ok, 0 failed"; note_hits "$(hits "$A1")"
echo "  set-email-badge: PASSED. Send this LOG FILE back, then run the 5.18.94 deploy again, the same command as before."
