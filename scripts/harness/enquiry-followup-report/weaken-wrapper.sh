#!/usr/bin/env bash
#
# weaken-wrapper.sh — weakened copies of scripts/enquiry-followup-report.sh; test-wrapper.sh must catch every one.
#
# Each copy changes one thing (its anchor must occur exactly once, and the copy must differ and parse); test-wrapper.sh
# then runs its scenarios on it (WRAP_UNDER_TEST). A copy counts as caught only when the test exits non-zero AND the
# failures include the named check. A control first: an unchanged copy passes. As root, like test-wrapper.sh.
set -uo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
WRAP="$(cd "$HERE/../../.." && pwd)/scripts/enquiry-followup-report.sh"
T="$(mktemp -d /tmp/efr-ww-XXXXXX)"; trap 'rm -rf "$T"' EXIT
PASS=0; FAIL=0
# WW3, WW4 and WW9 leave the wrapper's host folder behind — that is what they are caught for. After each run, a folder
# that appeared where mktemp -d puts them, holding nothing or only the wrapper's own r/ and report.php, is removed.
MKT="$(dirname "$(mktemp -u)")"
tmps() { ls -d "$MKT"/tmp.* 2>/dev/null | sort; }
sweep() {   # $1: the listing taken before the run
  local d rest
  for d in $(comm -13 <(printf '%s\n' "$1") <(tmps)); do
    rest="$(ls -A "$d" 2>/dev/null | grep -vxE 'r|report\.php' || true)"
    [ -z "$rest" ] && rm -rf -- "$d"
  done
}

# name | anchor | replacement | the check that must fail     (python does the replacing, with the counts asserted)
cat > "$T/copies.py" <<'PY'
import json, re, sys
C = [
 ("WW1 the settings copied whole, not cut",
  'file_put_contents($t, json_encode($cut(json_decode((string)file_get_contents($f), true)), JSON_FORCE_OBJECT));',
  'file_put_contents($t, (string)file_get_contents($f));',
  "S1: no secret and no key name reached the run's folder"),
 ("WW2 the report run as root",
  'OUT="$(docker exec -u "$OWNER" -e DN_VAULT_FILE=', 'OUT="$(docker exec -e DN_VAULT_FILE=',
  "S1: the report ran once, as the book's owner"),
 ("WW3 the run's folder not removed at the end",
  'cleanup; trap - EXIT\n', 'trap - EXIT\n',
  "S1: nothing left in the container's /tmp"),
 ("WW4 the host's folder made before the trap",
  'W="/tmp/dnb-enquiry-$TS-$$"; HOSTW=""\n', 'W="/tmp/dnb-enquiry-$TS-$$"; HOSTW="$(mktemp -d)"\n',
  "S3: nothing left in the host's /tmp"),
 ("WW5 the report given no vault of its own",
  '-e DN_VAULT_FILE="$W/vault.json" ', '',
  "S1: the report ran once, as the book's owner, on the copy, with the copy's own empty vault"),
 ("WW6 the installed bytes not compared",
  '  [ "$(sha256sum "$DEST/$f" 2>/dev/null | cut -c1-64)" = "$s" ] || BAD="$BAD $f(installed)"\n', '',
  "S5: exits 1"),
 ("WW7 a root-owned book accepted",
  ''' 0:*) stop "the book is root's — the plugin's own user must own it" ;;''', '',
  "S3: refused, by name"),
 ("WW8 the report's sha256 not checked",
  '[ "$(sha256sum "$REPORT" 2>/dev/null | cut -c1-64)" = "$REPORT_SHA256" ] || stop', 'true || stop',
  "S4: exits 1"),
 ("WW9 no trap",
  'trap cleanup EXIT\n', '',
  "S7: and the trap removed it"),
 ("WW10 the live vault copied into the run's folder",
  'file_put_contents($w . "/vault.json", json_encode(["config" => []], JSON_FORCE_OBJECT)); @chmod($w . "/vault.json", 0600);',
  '@copy(dirname($live) . "/.dishnet-sudan.vault.json", $w . "/vault.json");',
  "S1: the copy's vault is empty"),
 ("WW11 the book opened read-write for the copy (the defect before 15 Nov)",
  ', PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY];', '];',
  "S1: the live book's pending WAL is untouched"),
 ("WW12 the run's folder made by the book's owner",
  'docker exec "$CONTAINER" mkdir -m 700 "$W" </dev/null', 'docker exec -u "$OWNER" "$CONTAINER" mkdir -m 700 "$W" </dev/null',
  "S1: the run's folder made once, mode 700, as root"),
 ("WW13 the folder's removal not checked",
  '! docker exec "$CONTAINER" test ! -e "$W" >/dev/null 2>&1 </dev/null; then', 'false; then',
  "S8: the folder left behind is reported"),
 ("WW17 the run's folder handed to the owner before root fills it",
  'docker exec "$CONTAINER" mkdir -m 700 "$W" </dev/null || stop "the run\'s folder could not be made in the container\'s /tmp"\n',
  'docker exec "$CONTAINER" mkdir -m 700 "$W" </dev/null || stop "the run\'s folder could not be made in the container\'s /tmp"\ndocker exec "$CONTAINER" chown "$OWNER" "$W" </dev/null || stop "x"\n',
  "S1: the folder was still root's at each of root's copies into it"),
 ("WW18 the WAL trace not reported",
  '[ "$COPY" = "copy=ok side=no wal=new" ] && echo', '[ "$COPY" = "never" ] && echo',
  "S9: the trace is reported"),
 ("WW14 a vault key kept in the settings copies",
  '"wa_send_hour_to"];', '"wa_send_hour_to", "evo_api_key"];',
  "no key the wrapper copies is a vault key at 994d660"),
]
src0 = open(sys.argv[1]).read()
m = re.search(r'^REPORT_SHA256="([0-9a-f]{64})"$', src0, re.M)
C.append(("WW15 the pin is not the report's", 'REPORT_SHA256="%s"' % m.group(1), 'REPORT_SHA256="%s"' % ('0' * 64), "the wrapper pins the report's sha256"))
m = re.search(r'^([0-9a-f]{64})  lib/PluginConfig\.php$', src0, re.M)
C.append(("WW16 a settings reader's sum is not 994d660's", m.group(0), ('f' * 64) + '  lib/PluginConfig.php', "the wrapper's sum for lib/PluginConfig.php is 994d660's"))
src = open(sys.argv[1]).read()
out = []
for i, (name, old, new, needle) in enumerate(C, 1):
    n = src.count(old)
    if n != 1:
        out.append({"name": name, "error": "anchor found %d times" % n}); continue
    s = src.replace(old, new)
    if s == src:
        out.append({"name": name, "error": "the copy does not differ"}); continue
    f = "%s/ww%d.sh" % (sys.argv[2], i)
    open(f, "w").write(s)
    out.append({"name": name, "file": f, "needle": needle})
print(json.dumps(out))
PY
LIST="$(python3 -I "$T/copies.py" "$WRAP" "$T")" || { echo "the copies could not be made"; exit 2; }

cp "$WRAP" "$T/control.sh"
if WRAP_UNDER_TEST="$T/control.sh" bash "$HERE/test-wrapper.sh" > "$T/control.out" 2>&1 && grep -qE '^[0-9]+ passed, 0 failed$' "$T/control.out"; then
  PASS=$((PASS + 1)); echo "  ok   control: an unchanged copy passes"
else FAIL=$((FAIL + 1)); echo "  FAIL control: an unchanged copy does not pass"; tail -20 "$T/control.out"; fi

N="$(python3 -I -c 'import json,sys; print(len(json.loads(sys.argv[1])))' "$LIST")"
for i in $(seq 0 $((N - 1))); do
  name="$(python3 -I -c 'import json,sys; print(json.loads(sys.argv[1])[int(sys.argv[2])]["name"])' "$LIST" "$i")"
  err="$(python3 -I -c 'import json,sys; print(json.loads(sys.argv[1])[int(sys.argv[2])].get("error",""))' "$LIST" "$i")"
  if [ -n "$err" ]; then FAIL=$((FAIL + 1)); echo "  FAIL $name: $err"; continue; fi
  f="$(python3 -I -c 'import json,sys; print(json.loads(sys.argv[1])[int(sys.argv[2])]["file"])' "$LIST" "$i")"
  needle="$(python3 -I -c 'import json,sys; print(json.loads(sys.argv[1])[int(sys.argv[2])]["needle"])' "$LIST" "$i")"
  bash -n "$f" 2>/dev/null || { FAIL=$((FAIL + 1)); echo "  FAIL $name: the copy does not parse"; continue; }
  before="$(tmps)"
  WRAP_UNDER_TEST="$f" bash "$HERE/test-wrapper.sh" > "$T/out" 2>&1; rc=$?
  sweep "$before"
  nf="$(sed -nE 's/^[0-9]+ passed, ([0-9]+) failed$/\1/p' "$T/out")"
  if [ "$rc" != 0 ] && grep -qF -- "  FAIL $needle" "$T/out"; then
    PASS=$((PASS + 1)); echo "  ok   $name: caught (${nf:-?} checks fail, among them \"$needle\")"
  else FAIL=$((FAIL + 1)); echo "  FAIL $name: not caught for its reason (rc=$rc)"; fi
done
echo; echo "$PASS passed, $FAIL failed"
[ "$FAIL" = 0 ]
