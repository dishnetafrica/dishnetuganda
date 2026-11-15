#!/usr/bin/env bash
#
# test-wrapper.sh — scripts/enquiry-followup-report.sh end to end, as root, against a fake `docker`.
#
# The fake maps the container's /data to a sandbox folder (the mount `docker inspect` reports) and its /tmp/dnb-enquiry-*
# to another, runs `exec -u UID:GID` through setpriv as that user, and logs every call. The book is build.php's, owned by
# uid 1000, at the data folder beside the plugin; the installed plugin holds lib/PluginConfig.php and lib/ConfigVault.php
# from 994d660; the checkout is a sandbox Git repository that borrows this one's objects. Scenarios:
#   S1 the run (ucrm.json names the data folder)     S5 an installed settings reader that is not the reviewed bytes
#   S2 the run (no ucrm.json: the sibling folder)    S6 the checkout without the installed commit
#   S3 a root-owned book                             S7 a book that is not a database (the trap still cleans up)
#   S4 a report that is not the reviewed one         S8 the run's folder cannot be removed (it must be reported)
#   S9 the book idle, with no WAL files (the empty ones a read leaves are reported)
# and two static checks: the wrapper pins the report's sha256, and no key it copies is one of ConfigVault::VAULT_KEYS
# at 994d660 — so the vault, which it never reads, could hold nothing the report needs. Exit 0 only when all pass.
# WRAP_UNDER_TEST=<file> runs the scenarios on that copy of the wrapper instead (weaken-wrapper.sh uses it).
set -uo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SRCREPO="$(cd "$HERE/../../.." && pwd)"
COMMIT=994d660628cc5b1b42bee648b44b58e389f44bf2
PLUGIN=dishnet-hybrid-sudan
[ "$(id -u)" = 0 ] || { echo "run as root (it drops to uid 1000 for the container's user)"; exit 2; }
command -v setpriv >/dev/null || { echo "setpriv is needed"; exit 2; }
OBJ="$(cd "$(git -C "$SRCREPO" rev-parse --git-common-dir)" && pwd)/objects"
git -C "$SRCREPO" cat-file -e "$COMMIT^{commit}" || { echo "this checkout lacks $COMMIT"; exit 2; }

SB="$(mktemp -d /tmp/efr-wrap-XXXXXX)"; chmod 755 "$SB"
trap 'rm -rf "$SB"' EXIT
PASS=0; FAIL=0
ok()    { PASS=$((PASS + 1)); echo "  ok   $1"; }
bad()   { FAIL=$((FAIL + 1)); echo "  FAIL $1"; [ -n "${2:-}" ] && printf '%s\n' "$2" | sed 's/^/       /' | head -40; return 0; }
check() { if [ "$1" = "$2" ]; then ok "$3"; else bad "$3" "got [$1], wanted [$2]"; fi; }
has()   { if grep -qF -- "$2" <<< "$1"; then ok "$3"; else bad "$3" "not found: $2"; fi; }
hasnt() { if grep -qF -- "$2" <<< "$1"; then bad "$3" "found: $2"; else ok "$3"; fi; }

# ── static: the pin and the vault keys ─────────────────────────────────────────────────────────────────────────────
echo "-- static"
WRAP="$SRCREPO/scripts/enquiry-followup-report.sh"; REP="$SRCREPO/scripts/enquiry-followup-report.php"
WUT="${WRAP_UNDER_TEST:-$WRAP}"   # the static checks read the wrapper under test too, so a weakened copy can fail them
check "$(sed -n 's/^REPORT_SHA256="\([0-9a-f]\{64\}\)"$/\1/p' "$WUT")" "$(sha256sum "$REP" | cut -c1-64)" "the wrapper pins the report's sha256"
mkdir -p "$SB/root"; git -C "$SRCREPO" archive "$COMMIT" "$PLUGIN" | tar -x -C "$SB/root" || { echo "archive failed"; exit 2; }
VK="$(php -r '
  require $argv[1];
  preg_match("/\\\$keep = \\[(.*?)\\];/s", (string)file_get_contents($argv[2]), $m) or exit(9);
  preg_match_all("/\"([a-z_]+)\"/", $m[1], $k);
  echo count($k[1]), " kept; in VAULT_KEYS: ", implode(",", array_intersect($k[1], ConfigVault::VAULT_KEYS)) ?: "none";
' "$SB/root/$PLUGIN/lib/ConfigVault.php" "$WUT" 2>&1)"
check "$VK" "17 kept; in VAULT_KEYS: none" "no key the wrapper copies is a vault key at 994d660"
for f in lib/PluginConfig.php lib/ConfigVault.php; do
  s="$(sed -n "s#^\([0-9a-f]\{64\}\)  $f\$#\1#p" "$WUT")"
  check "$(sha256sum "$SB/root/$PLUGIN/$f" | cut -c1-64)" "$s" "the wrapper's sum for $f is 994d660's"
done

# ── the book, once ─────────────────────────────────────────────────────────────────────────────────────────────────
NOW="$(date -u '+%Y-%m-%d %H:%M:%S')"
php "$HERE/build.php" "$SB/root/$PLUGIN" "$SB/build" "$NOW" >/dev/null || { echo "build.php failed"; exit 2; }

# ── the fake docker ────────────────────────────────────────────────────────────────────────────────────────────────
mkdir -p "$SB/bin"
cat > "$SB/bin/docker" <<SH
#!/usr/bin/env bash
SB="$SB"
printf '%s\n' "\$*" >> "\$SB/docker.log"
map() { local a="\$1"; a="\${a//\/tmp\/dnb-enquiry-/\$SB/ctmp/dnb-enquiry-}"
  case "\$a" in /data/*) a="\$SB/mnt/\${a#/data/}" ;; --*=/data/*) a="\${a%%=/data/*}=\$SB/mnt/\${a#*=/data/}" ;; esac; printf '%s' "\$a"; }
cmd="\$1"; shift
case "\$cmd" in
  inspect) [ "\$1" = ucrm ] || { echo "Error: No such object: \$1" >&2; exit 1; }; printf '%s\n' "\$SB/mnt" ;;
  cp) [ "\${2%%:*}" = ucrm ] || { echo "Error: No such container" >&2; exit 1; }; d="\$(map "\${2#*:}")"
      printf 'owner-at-cp %s\n' "\$(stat -c %u "\$(dirname "\$d")")" >> "\$SB/docker.log"; cp -r "\$1" "\$d" ;;
  exec)
    user=""; envs=()
    while :; do case "\$1" in -u) user="\$2"; shift 2 ;; -e) envs+=("\$(map "\$2")"); shift 2 ;; -i) shift ;; *) break ;; esac; done
    [ "\$1" = ucrm ] || { echo "Error: No such container: \$1" >&2; exit 1; }; shift
    args=(); for a in "\$@"; do args+=("\$(map "\$a")"); done
    # what the report is given, kept for the test to read after the run's folder is gone
    for a in "\${args[@]}"; do case "\$a" in --data=*) d="\${a#--data=}"; mkdir -p "\$SB/seen"
      for f in kyc_config.json config.json vault.json r/data/config.json; do [ -f "\$d/\$f" ] && cp "\$d/\$f" "\$SB/seen/\${f//\//_}"; done ;; esac; done
    # S8: the run's folder cannot be removed
    if [ "\${args[0]}" = rm ] && [ "\${FAKE_RM_FAIL:-}" = 1 ]; then exit 0; fi
    pre=(); [ -n "\$user" ] && pre=(setpriv --reuid="\${user%%:*}" --regid="\${user##*:}" --clear-groups)
    cd "\$SB/ctmp" || exit 1
    exec "\${pre[@]}" env "\${envs[@]}" "\${args[@]}" ;;
  *) echo "fake docker: \$cmd is not faked" >&2; exit 1 ;;
esac
SH
chmod 755 "$SB/bin/docker"

PL="$SB/mnt/ucrm/data/plugins"; DEST="$PL/$PLUGIN"; PDD="$PL/.$PLUGIN-data"
setup() {   # $1: with or without ucrm.json
  rm -rf "$SB/mnt" "$SB/ctmp" "$SB/repo" "$SB/seen" "$SB/docker.log"
  mkdir -p "$DEST/lib" "$DEST/data" "$PDD" "$SB/ctmp" "$SB/repo/scripts"; chmod 1777 "$SB/ctmp"; : > "$SB/docker.log"
  chmod -R 755 "$SB/mnt"
  echo '{"version":"5.18.104"}' > "$DEST/manifest.json"; printf 'x\n%s\n' "$COMMIT" > "$DEST/.deployed-commit"
  cp "$SB/root/$PLUGIN/lib/PluginConfig.php" "$SB/root/$PLUGIN/lib/ConfigVault.php" "$DEST/lib/"
  cp "$SB/build/root2/data/config.json" "$DEST/data/config.json"       # followup_run_limit 2 and a cap of 9 …
  cp "$SB/build/data/kyc_config.json" "$PDD/kyc_config.json"           # … which kyc_config.json's 3 overrides; and secrets
  cp "$SB/build/data/plugin.sqlite3" "$PDD/plugin.sqlite3"
  # the live vault as production keeps it (SecureFile: 0640, the plugins folder's owner): the run's user COULD read it
  echo '{"config":{"evo_api_key":"PLANT-LIVE-VAULT-SECRET"}}' > "$PL/.dishnet-sudan.vault.json"
  chown 1000:1000 "$PL/.dishnet-sudan.vault.json"; chmod 640 "$PL/.dishnet-sudan.vault.json"
  [ "$1" = ucrm ] && echo "{\"pluginDataDir\":\"/data/ucrm/data/plugins/.$PLUGIN-data/\"}" > "$DEST/ucrm.json"
  chown -R 1000:1000 "$PDD"; chmod 700 "$PDD"; chmod 600 "$PDD"/*
  # the live book with frames still in its WAL, as a running plugin leaves it: written by the owner, never checkpointed
  ( setpriv --reuid=1000 --regid=1000 --clear-groups php -r '$p = new PDO("sqlite:" . $argv[1]); $p->exec("PRAGMA wal_autocheckpoint=0");
      $p->exec("CREATE TABLE IF NOT EXISTS zz_pending (x)"); $p->exec("INSERT INTO zz_pending VALUES (1)"); posix_kill(getmypid(), 9);' "$PDD/plugin.sqlite3" ) >/dev/null 2>&1
  MAIN0="$(sha256sum "$PDD/plugin.sqlite3" | cut -c1-64)"; WAL0="$(sha256sum "$PDD/plugin.sqlite3-wal" 2>/dev/null | cut -c1-64)"
  WALN="$(stat -c %s "$PDD/plugin.sqlite3-wal" 2>/dev/null || echo 0)"
  git init -q "$SB/repo" && echo "$OBJ" > "$SB/repo/.git/objects/info/alternates"
  cp "$REP" "$SB/repo/scripts/"; cp "${WRAP_UNDER_TEST:-$WRAP}" "$SB/repo/scripts/enquiry-followup-report.sh"
}
MKT="$(dirname "$(mktemp -u)")"; TMPS() { ls -d "$MKT"/tmp.* 2>/dev/null | sort; }   # where the wrapper's mktemp -d lands
run() {     # the one command's run, its output into $OUT and its status into $RC
  local before; before="$(TMPS)"
  OUT="$(cd "$SB/repo" && PATH="$SB/bin:$PATH" bash scripts/enquiry-followup-report.sh 2>&1)"; RC=$?
  LEFT="$(comm -13 <(printf '%s\n' "$before") <(TMPS) | grep -v '^$' || true)"
  CT="$(ls -A "$SB/ctmp")"
}
common() {  # $1 the scenario's name
  check "$CT" "" "$1: nothing left in the container's /tmp"
  check "$LEFT" "" "$1: nothing left in the host's /tmp"
  if grep -qE '[0-9]{9,}' <<< "$OUT"; then bad "$1: no run of nine or more digits in the log"; else ok "$1: no run of nine or more digits in the log"; fi
  hasnt "$OUT" "PLANT" "$1: no PLANT string in the log"
  has "$OUT" "  READ-ONLY — nothing was changed" "$1: ends READ-ONLY"
}

for S in S1 S2; do
  echo "-- $S: the run ($([ $S = S1 ] && echo 'ucrm.json names the data folder' || echo 'no ucrm.json: the sibling folder'))"
  setup "$([ $S = S1 ] && echo ucrm || echo none)"
  run
  check "$RC" 0 "$S: exits 0"
  has "$OUT" "== enquiry follow-up report — read-only — " "$S: the header"
  has "$OUT" "  installed       commit $COMMIT · the book owned by 1000:1000" "$S: the installed commit and the book's owner"
  has "$OUT" "the installed 2 the same bytes" "$S: both settings readers checked against Git and the installed bytes"
  has "$OUT" "  copy            the book's consistent copy (VACUUM INTO, read-only), integrity_check ok" "$S: the copy"
  hasnt "$OUT" "no plugin process had it open" "$S: (its WAL was in use) no WAL note"
  has "$OUT" "  open 9, due now 5 · the run's front: partly blocked" "$S: the report's summary"
  has "$OUT" "  followup_daily_cap 3 — once this many were SENT on a number today (UTC) the run judges none of its follow-ups (it puts each back for the next run); the sender itself has no cap · followup_run_limit 2 — follow-ups judged per run (every 10 min)" \
      "$S: the settings, in PluginConfig's order through the cut copies (kyc_config.json's 3 over config.json's 9)"
  has "$OUT" "  the book's own settings row (master and the Follow-ups screen read it): master_budget_seconds 240" "$S: the book's settings row travels in the copy"
  has "$OUT" "  changes on the copy: 0" "$S: no change on the copy"
  has "$OUT" "  exit 0 (0 done;" "$S: the report's exit"
  common "$S"
  check "$([ "$WALN" -gt 0 ] && echo frames)" "frames" "$S: the live book had frames in its WAL before the run (the control)"
  check "$(sha256sum "$PDD/plugin.sqlite3" | cut -c1-64)" "$MAIN0" "$S: the live book is byte for byte unchanged"
  check "$(sha256sum "$PDD/plugin.sqlite3-wal" 2>/dev/null | cut -c1-64)" "$WAL0" "$S: the live book's pending WAL is untouched (not folded in, not removed)"
  check "$(stat -c '%u:%g' "$PDD/plugin.sqlite3")" "1000:1000" "$S: the live book still the owner's"
  check "$(grep -c -- '^exec -u 1000:1000 -e DN_VAULT_FILE=/tmp/dnb-enquiry-[^ ]*/vault.json -e TMPDIR=/tmp/dnb-enquiry-[^ ]* ucrm php -d log_errors=0 -d opcache.enable_cli=0 /tmp/dnb-enquiry-[^ ]*/report.php --db=/tmp/dnb-enquiry-[^ ]*/copy.sqlite3 ' "$SB/docker.log")" 1 \
      "$S: the report ran once, as the book's owner, on the copy, with the copy's own empty vault"
  check "$(grep -c -- '^exec ucrm mkdir -m 700 /tmp/dnb-enquiry-' "$SB/docker.log")" 1 "$S: the run's folder made once, mode 700, as root"
  check "$(awk '/^exec ucrm mkdir -m 700 \/tmp\/dnb-enquiry-/{a=NR} /^cp .*ucrm:\/tmp\/dnb-enquiry-.*\/report.php$/{b=NR}
              /^exec ucrm sh -c chmod -R go-rwx "\$2" && chown -R "\$1" "\$2" _ 1000:1000 \/tmp\/dnb-enquiry-/{c=NR}
              /^exec -u 1000:1000 ucrm php -d display_errors=stderr -r/{d=NR} END{print (a && a<b && b<c && c<d) ? "in order" : a" "b" "c" "d}' "$SB/docker.log")" \
        "in order" "$S: root made and filled the folder, then handed it over, before the owner's first act in it"
  check "$(grep -c '^owner-at-cp ' "$SB/docker.log") $(grep -c '^owner-at-cp 0$' "$SB/docker.log")" "2 2" "$S: the folder was still root's at each of root's copies into it"
  check "$(grep -cE 'dishnet-sudan\.vault|config_vault' "$SB/docker.log")" 0 "$S: the live vault is never named to the container"
  check "$(grep -c '^exec ucrm rm -rf /tmp/dnb-enquiry-' "$SB/docker.log")" 1 "$S: the folder removed once (the trap does not run again)"
  check "$(php -r 'echo implode(",", array_keys((array)json_decode((string)file_get_contents($argv[1]), true)));' "$SB/seen/kyc_config.json")" \
        "followup_enabled,followup_auto_send,followup_daily_cap,followup_not_before,wa_followups_on_owned_numbers,multi_number_channels_enabled,wa_send_ledger" \
        "$S: the copied kyc_config.json holds the follow-up keys only"
  check "$(cat "$SB/seen/r_data_config.json")" '{"followup_daily_cap":"9","followup_run_limit":"2"}' "$S: the copied code-root config.json, cut"
  check "$(cat "$SB/seen/vault.json")" '{"config":{}}' "$S: the copy's vault is empty"
  check "$(cat "$SB/seen/"* | grep -c -e PLANT -e api_key)" 0 "$S: no secret and no key name reached the run's folder"
done

echo "-- S3: a root-owned book"
setup ucrm; chown 0:0 "$PDD/plugin.sqlite3"; run
check "$RC" 1 "S3: exits 1"; has "$OUT" "  STOP: the book is root's — the plugin's own user must own it" "S3: refused, by name"
check "$(grep -c 'mkdir' "$SB/docker.log")" 0 "S3: refused before any folder is made"; common S3

echo "-- S4: a report that is not the reviewed one"
setup ucrm; printf '\n' >> "$SB/repo/scripts/enquiry-followup-report.php"; run
check "$RC" 1 "S4: exits 1"; has "$OUT" "  STOP: scripts/enquiry-followup-report.php is not the reviewed one (sha256)" "S4: refused, by name"
check "$(wc -l < "$SB/docker.log")" 0 "S4: refused before docker is called at all"; common S4

echo "-- S5: an installed settings reader that is not the reviewed bytes"
setup ucrm; printf '// changed\n' >> "$DEST/lib/PluginConfig.php"; run
check "$RC" 1 "S5: exits 1"; has "$OUT" "these files are not the reviewed bytes the report was built against: lib/PluginConfig.php(installed)" "S5: refused, naming the file"
check "$(grep -c 'mkdir' "$SB/docker.log")" 0 "S5: refused before any folder is made"; common S5

echo "-- S6: the checkout without the installed commit"
setup ucrm; rm -f "$SB/repo/.git/objects/info/alternates"; run
check "$RC" 1 "S6: exits 1"; has "$OUT" "  STOP: the checkout does not hold the installed commit $COMMIT" "S6: refused, by name"
check "$(grep -c 'mkdir' "$SB/docker.log")" 0 "S6: refused before any folder is made"; common S6

echo "-- S7: a book that is not a database"
setup ucrm; head -c 8192 /dev/urandom | tr -d '0-9' > "$PDD/plugin.sqlite3"; chown 1000:1000 "$PDD/plugin.sqlite3"; run
check "$RC" 1 "S7: exits 1"; has "$OUT" "  STOP: the copy could not be made" "S7: refused, by name"
check "$(grep -c -- '^exec ucrm mkdir -m 700 /tmp/dnb-enquiry-' "$SB/docker.log")" 1 "S7: the folder had been made"
check "$(grep -c '^exec ucrm rm -rf /tmp/dnb-enquiry-' "$SB/docker.log")" 1 "S7: and the trap removed it"; common S7

echo "-- S8: the run's folder cannot be removed"
setup ucrm; export FAKE_RM_FAIL=1; run; unset FAKE_RM_FAIL
check "$RC" 0 "S8: the report itself ran (exit 0)"
has "$OUT" "  WARNING: the run's folder /tmp/dnb-enquiry-" "S8: the folder left behind is reported"
has "$OUT" "could not be confirmed gone from the container; it would hold a copy of the book. Remove it: docker exec ucrm rm -rf /tmp/dnb-enquiry-" "S8: with how to remove it"
check "$(ls -A "$SB/ctmp" | grep -c '^dnb-enquiry-')" 1 "S8: (the control) the folder really is still there"
rm -rf "$SB/ctmp"/dnb-enquiry-*
check "$LEFT" "" "S8: nothing left in the host's /tmp"
hasnt "$OUT" "PLANT" "S8: no PLANT string in the log"

echo "-- S9: the book idle (no plugin process holds it, so no WAL files beside it)"
setup ucrm
# fold the WAL in and take the files away while nothing holds the book, as the plugin's last close would
( setpriv --reuid=1000 --regid=1000 --clear-groups php -r '$p = new PDO("sqlite:" . $argv[1]); $p->exec("PRAGMA wal_checkpoint(TRUNCATE)"); $p = null;' "$PDD/plugin.sqlite3" ) >/dev/null 2>&1
rm -f "$PDD/plugin.sqlite3-wal" "$PDD/plugin.sqlite3-shm"
MAIN0="$(sha256sum "$PDD/plugin.sqlite3" | cut -c1-64)"
check "$(ls "$PDD" | grep -c 'plugin.sqlite3-')" 0 "S9: (the control) no WAL file beside the book before the run"
run
check "$RC" 0 "S9: exits 0"
has "$OUT" "  the book        no plugin process had it open, so reading it left its WAL files beside it (an empty plugin.sqlite3-wal and its -shm, the plugin user's)" "S9: the trace is reported"
check "$(stat -c '%s %u:%g' "$PDD/plugin.sqlite3-wal" 2>/dev/null)" "0 1000:1000" "S9: the WAL file left is empty and the plugin user's"
check "$(sha256sum "$PDD/plugin.sqlite3" | cut -c1-64)" "$MAIN0" "S9: the book is byte for byte unchanged"
common S9

echo; echo "$PASS passed, $FAIL failed"
[ "$FAIL" = 0 ]
