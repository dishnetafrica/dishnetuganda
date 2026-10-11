#!/usr/bin/env bash
#
# quarantine-stray-store.sh — moves the stray store, <plugin>/data/plugin.sqlite3, out of the plugin folder into a
# root-only folder on the host and keeps it there, unopened; checks that nothing makes a new one; and, only if it is
# ever needed, puts it back (docs/07, "Stray-store quarantine"). One file; nothing else changes.
#
#   Why.     Since 26 Aug the plugin's live database is .dishnet-hybrid-sudan-data/plugin.sqlite3, beside the plugin
#            folder. The old one, <plugin>/data/plugin.sqlite3, stayed inside it: last written 07 Oct 09:55 UTC by the
#            Starlink retry job, which 5.18.91 stopped from opening it — R19 has found it unopened since, at 5.18.92's and
#            5.18.93's checks. While it is there, two pieces of the plugin's own code can still reach it: getDataDir()'s
#            one-time rescue copy, which copies every file of that folder into the live folder if the live database is
#            ever missing (lib/bootstrap_data.php:86-96), and cliDataDir()'s guard, which hands that folder to a
#            command-line tool run by a user who cannot write the plugins root (:146-148). With the file gone, the first
#            has nothing to copy and the second refuses and names the real folder. The operator's order (docs/07): fix
#            the wrong-database access first; once it is deployed and verified, quarantine the old database as its own
#            step. Their own hardening is a separate decision; this script does not touch them.
#
#   What.    ONE FILE. <plugin>/data/plugin.sqlite3 is given a second name in the quarantine folder — the same file, by
#            hard link, so its bytes, owner, mode, times and birth time stay its own — and then its old name is removed.
#            The quarantine folder must be on the same filesystem; if it is not, stage A refuses (a copy is not this
#            script's to make). Every other entry of <plugin>/data stays where it is: its migration.log (jobs migrating the
#            LIVE store still write it), its config.json (an input of the retry job's gate), everything else. Nothing is
#            added to that folder. Nothing — not this script, not any check in it — opens either file with SQLite: every
#            check is a file read (checksum, size, times, owner, links, side files) and the kernel's own lists of who
#            holds a file (/proc/locks, and every process's open descriptors).
#
#   Before.  Stage A changes nothing outside its own folder and ends in GO or NO-GO. It refuses unless: the container
#            serves 5.18.93 (69c87a6) and its retry job and the gate's four files are that release's, byte for byte;
#            5.18.93's day-later check — deploy-5.18.93.sh --after-only run at least a day after the copy — PASSED with
#            R19's conclusive line and its log is in /root/dnb-5.18.93 (once the file is moved, every later run of that
#            script fails R19 for good); no deploy record from 5.18.91 on holds an R19 failure; the stray file is exactly
#            as every deploy since 5.18.91 recorded it — checksum, size, time, no side file, one link, its folder's time
#            and entries as 5.18.93's copy saw them; no process holds it — no lock and no open descriptor, each
#            instrument shown first to see a store held open on this host and a file held open inside the container;
#            the INSTALLED retry job's gate stops it on this server's own configuration, whether or not it can read the
#            live database — with the gate open, its next run would make a new database where this one was; the live
#            database is found and is another file; and the quarantine folder is root's own, on the same filesystem, out
#            of every container's sight, with nothing kept in it yet (the logs of this script's own commands may be
#            there). After the typed word it reads all of it again, the gate included, before it moves anything.
#
#   After.   At once: the old name gone, no side file there, the folder's entries exactly as before less the one entry;
#            the kept file the same inode, bytes, owner, mode, times and birth time; no holder on it; the live commit, the
#            live database and the gate as before. Then, at least 20 minutes later, --check: once master has run the
#            retry job since the move, nothing has made a new database, side file, sqlite_migration.log or *.json.migrated
#            in <plugin>/data, and the kept file is unchanged.
#
# Run as root on the server, then send back THE LOG FILE (never a copy of the terminal) — ONLY after 5.18.93's day-later
# --after-only has run and PASSED, and its log has been sent. Until this script has moved the file: deploy or roll back
# nothing (a new live commit voids it), do not save the plugin's settings in uCRM, and run neither
# diagnose-sales-pipeline.sh nor migrations/migrate_tickets_data.php.
#
#   cd /opt/dishnet && git pull origin claude/study-this-jhe2eg && mkdir -p /root/dnb-stray-quarantine \
#     && bash scripts/quarantine-stray-store.sh 2>&1 | tee /root/dnb-stray-quarantine/quarantine-$(date -u +%Y%m%dT%H%M%SZ).log
#
# Then, at least 20 minutes after the move (the summary prints the time) — it changes nothing:
#
#   cd /opt/dishnet && bash scripts/quarantine-stray-store.sh --check 2>&1 | tee /root/dnb-stray-quarantine/check-$(date -u +%Y%m%dT%H%M%SZ).log
#
# If the terminal is lost during the quarantine, reconnect, wait two minutes, and run the quarantine command again: it
# finishes its own checks, or names the one command to run (a run that is still going is not disturbed: the second one
# stops at once and says so). Each run also writes its own log into /root/dnb-stray-quarantine (*.own.log): send every
# one of them with the tee log and quarantine.env.
#
# The put-back is a separate command, printed at the end of the quarantine's log. It is never pasted together with the
# ones above: pasted together, the shell runs both (root docs/44 §16.9).
#
# Options
#   --check      whether anything has made a new stray store, and whether the kept file is unchanged. It changes
#                nothing but its own log and, on a failure, one line in the quarantine's record (Q_CHECK_FAILED), which
#                fails every later --check
#   --put-back   put the kept file back where it was (typed PUTBACK) — only if it is ever needed, and before any rollback
#                to 5.18.90, whose retry job would otherwise make a new database there; also the one command for a
#                quarantine that stopped between its two steps (it removes only the kept name)
#
# What it never does: open the stray store or the kept file with SQLite; copy either; move, change or add any other file
# in <plugin>/data, or the folder itself; write the live database (it reads it, read-only, as its owner, for the gate's
# question and master's record of the retry job — as the deploy scripts do); deploy, check out or change code; call
# deploy-hybrid.sh; restart anything; write to any deploy's record; send anything.
#
# Its own folder: /root/dnb-stray-quarantine (mode 700) — the kept file (stray-plugin.sqlite3.kept), the record
# (quarantine.env, which the next deploy script must read), and the logs.
set -uo pipefail
umask 077

main() {
PLUGIN="dishnet-hybrid-sudan"
CONTAINER="${UCRM_CONTAINER:-ucrm}"
EXPECTED_LIVE_COMMIT="69c87a6"   # 5.18.93 (release/5.18.93) — deployed 11 Oct, its copy 13:06:21 UTC
EXPECTED_VERSION="5.18.93"
# The stray store as every deploy since 5.18.91 recorded it: its sha256 (16 hex), size and time — 07 Oct 09:55:07 UTC —
# and its folder's time, 10 Oct 02:45:47 UTC. 5.18.93's state file must say the same (A4): each compared on its own.
EXPECTED_STRAY_DB="dc85ae6ddafaae19:2379776:1791366907"
EXPECTED_STRAY_DIR_T="1791600347"
# The gate on this server's own configuration, exactly as deploy-5.18.93.sh's A12 and R18 require it.
SLR_SERVER_SIGNATURE="live=same inside=no store=read tenant=uganda skip=yes"
# The retry job and the four files its gate reads, as 69c87a6 has them (5.18.91's, unchanged since).
GATE_FILES="cron_starlink_block_retry.php:6da76a52792d0adf3dddade9e0335985f37e35e877c64d2333d8b53ef2f38ffe
lib/StarlinkRetryScope.php:c9e545c476f3584b17aa179e750359ad314055ff515c4c6a264b85878b4e71e1
lib/StaffJobsGate.php:ac2fdc5608769292f515a1b43cd3b17c049c3f764309c27f077ccd954f6b0f1b
lib/PluginConfig.php:500d5643bec2b143b190b9d9c49ddd374a35554a652a9827870fe6991630a635
lib/TenantProfile.php:6e37597c1e62fc7520c21feebc522ce0d07522dc41a81387fcff54203d185053"
RECORDS="${DNB_RECORDS:-/root}"
REC93="$RECORDS/dnb-$EXPECTED_VERSION"
STATE93="$REC93/state-$EXPECTED_VERSION.env"
Q_DIR="$RECORDS/dnb-stray-quarantine"
KEPT_NAME="stray-plugin.sqlite3.kept"
KEPT="$Q_DIR/$KEPT_NAME"
RECORD="$Q_DIR/quarantine.env"
REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SELF="scripts/quarantine-stray-store.sh"
TS="$(date -u +%Y%m%dT%H%M%SZ)"
MODE="quarantine"
OWN=""
PASS=0; FAIL=0; NOTE=0
UNSETTLED=""   # set when a run with no failure still concluded nothing: its verdict is then NOT CONCLUSIVE, never PASSED
# Every line goes through say: to the terminal (whose loss must never stop a run — its exit status is not tested) and to
# this run's own log in the quarantine folder, once it is open.
say()  { printf '%s\n' "$*" 2>/dev/null || true; if [ -n "$OWN" ]; then printf '%s\n' "$*" >&3 2>/dev/null || true; fi; return 0; }
ok()   { PASS=$((PASS+1)); say "  ok    $*"; }
bad()  { FAIL=$((FAIL+1)); say "  FAIL  $*"; }
note() { NOTE=$((NOTE+1)); say "  note  $*"; }
hdr()  { say ""; say "== $* =="; }
unsettled() { [ -n "$UNSETTLED" ] || UNSETTLED="$*"; }   # the first reason a run concluded nothing
rec_get() { [ -f "$RECORD" ] || return 0; sed -n "s/^$1=//p" "$RECORD" 2>/dev/null | tail -n1; }
rec_put() { printf '%s=%s\n' "$1" "$2" >> "$RECORD" || return 1; }
# The rule that binds until the quarantine has moved the file — printed on every stop while it has not: no record, or a
# record of a run that moved nothing (prepared, abandoned, or linked with the old name still there), and no put-back.
pre_rule() {
  [ "$(id -u)" = "0" ] || return 0   # the record under /root cannot be read: judge nothing
  [ -z "$(rec_get PUTBACK_STAGE)$(rec_get PUTBACK_AT)" ] || return 0
  case "$(rec_get Q_STAGE)" in
    ""|prepared|abandoned) ;;
    linked) local s="${SRC:-$(rec_get Q_SRC)}"; [ -n "$s" ] && { [ -e "$s" ] || [ -L "$s" ]; } || return 0 ;;
    *) return 0 ;;
  esac
  say "  Until the quarantine has moved the file: deploy or roll back nothing (a new live commit voids this script at A0);"
  say "  do not save the plugin's settings in uCRM (it may rewrite a file in <plugin>/data, which A4 then refuses for good);"
  say "  run neither diagnose-sales-pipeline.sh nor migrations/migrate_tickets_data.php."
  return 0
}
stop() { say ""; say "  STOP: $*"; pre_rule; say "  Nothing further was done. Send the log file$( [ -n "$OWN" ] && printf ', with every *.own.log in %s and its quarantine.env' "$Q_DIR")."; exit 1; }
case "${1:-}" in
  "") ;;
  --check) MODE="check" ;;
  --put-back) MODE="putback" ;;
  *) stop "unknown option: $1" ;;
esac
[ "$#" -le 1 ] || stop "one option at most"
case "$EXPECTED_LIVE_COMMIT" in __*) stop "this copy of the script is not pinned to a reviewed commit — pull the branch again";; esac
[ "$(id -u)" = "0" ] || stop "run it as root"

# ── Its own folder, before anything is written into it: a real directory, root's, mode 700 ─────────────────────────────
if [ ! -e "$Q_DIR" ] && [ ! -L "$Q_DIR" ]; then
  [ "$MODE" = "quarantine" ] || stop "there is no $Q_DIR — nothing has been quarantined on this server"
  mkdir -m 700 -- "$Q_DIR" 2>/dev/null || stop "cannot make $Q_DIR"
fi
[ -d "$Q_DIR" ] && [ ! -L "$Q_DIR" ] || stop "$Q_DIR is not a directory of its own (a symlink, or a file) — the quarantine folder must be a real directory"
[ "$(stat -c %u -- "$Q_DIR" 2>/dev/null)" = "0" ] || stop "$Q_DIR is not root's"
chmod 700 -- "$Q_DIR" 2>/dev/null || stop "cannot set $Q_DIR to mode 700"
# One run at a time, in every mode: a quarantine goes on to its end after its terminal is lost, and a second run beside it
# would read and write the same record. The lock is the quarantine folder itself, held on fd 9 for this run's life, and
# taken before this run's own log is opened: a run refused here adds nothing to the folder, so the newest *.own.log is
# the running one's. The background children below are started without it.
command -v flock >/dev/null 2>&1 || stop "no flock on this host — one run at a time cannot be ensured"
exec 9<"$Q_DIR" || stop "cannot open $Q_DIR to hold this run's lock"
flock -n 9 || stop "another run of this script is still going on this server (a quarantine goes on to its end after its terminal is lost) — wait until it has ended (its own log is the newest *.own.log in $Q_DIR), then run this again"
OWN="$Q_DIR/$MODE-$TS.own.log"
exec 3>>"$OWN" || { OWN=""; stop "cannot write this run's own log in $Q_DIR"; }

hdr "stray-store quarantine ($MODE) — $TS — $(hostname)"
say "  this script     $REPO/$SELF"
say "  own log         $OWN"

# ── Reads: files, holders, the gate, master's record ────────────────────────────────────────────────────────────────
utc_of() { date -u -d "@$1" +%Y-%m-%dT%H:%M:%SZ 2>/dev/null || echo "@$1"; }   # an epoch, as UTC
absent()   { [ ! -e "$1" ] && [ ! -L "$1" ]; }
sha_of()   { sha256sum -- "$1" 2>/dev/null | cut -c1-64; }
triple_of() {   # sha256 (16 hex):size:time — the deploy scripts' reading of a database file, as a file
  local s m; s="$(sha_of "$1")"; m="$(stat -c '%s:%Y' -- "$1" 2>/dev/null)"
  if [ -n "$s" ] && [ -n "$m" ]; then printf '%s:%s\n' "${s:0:16}" "$m"; else echo "?"; fi
}
key_of()   { stat -c '%d:%i' -- "$1" 2>/dev/null; }          # device:inode, decimal — how the kernel names a file
links_of() { stat -c '%h' -- "$1" 2>/dev/null; }
owner_of() { stat -c '%u:%g' -- "$1" 2>/dev/null; }
mode_of()  { stat -c '%a' -- "$1" 2>/dev/null; }
birth_of() { stat -c '%w' -- "$1" 2>/dev/null | tr ' ' '_'; }  # - where the filesystem does not keep it
ctime_of() { stat -c '%z' -- "$1" 2>/dev/null | tr ' ' '_'; }
side_of()  { local s o=""; for s in -wal -shm -journal; do if [ -e "$1$s" ] || [ -L "$1$s" ]; then o="$o$s"; fi; done; echo "${o:--}"; }
attrs_of() { lsattr -d -- "$1" 2>/dev/null | awk 'NR == 1 { print $1 }'; }
data_key() { local k b; k="$(key_of "$DEST/data")"; b="$(birth_of "$DEST/data")"; [ -n "$k" ] && echo "$k:$b" || echo "?"; }
# The stray store under the plugin folder, read as FILES on the host — never opened by SQLite, never moved: the
# database's checksum (16 hex), size and time, which of its -wal/-shm/-journal exist, migration.log's size and time, and
# the folder's own time — which changes only when an entry is made, removed or renamed in it: an open of the stray store
# makes and removes its -wal and -shm there; an append to a file does not change it.
stray_state() {   # prints db=<sha16:size:epoch|absent> side=<-|-wal-shm…> log=<size:epoch|absent> dir=<epoch>  or nodir
  local d="$DEST/data" db s side="" lg
  [ -d "$d" ] || { echo "nodir"; return; }
  if [ -f "$d/plugin.sqlite3" ]; then db="$(sha256sum "$d/plugin.sqlite3" 2>/dev/null | cut -c1-16):$(stat -c '%s:%Y' "$d/plugin.sqlite3" 2>/dev/null)"; else db="absent"; fi
  for s in -wal -shm -journal; do [ -e "$d/plugin.sqlite3$s" ] && side="$side$s"; done
  if [ -f "$d/migration.log" ]; then lg="$(stat -c '%s:%Y' "$d/migration.log" 2>/dev/null)"; else lg="absent"; fi
  echo "db=$db side=${side:--} log=$lg dir=$(stat -c %Y "$d" 2>/dev/null || echo 0)"
}
# The stray folder's entries as inode:name, sorted, joined with "/" (which no file name can hold) — names only, never
# contents; the database's own side files left out (they come and go with every open). Two equal lists with the folder's
# time moved mean an entry was made and removed: what an open of the stray store does.
stray_entries() {
  [ -d "$DEST/data" ] || { echo "nodir"; return; }
  find "$DEST/data" -mindepth 1 -maxdepth 1 ! -name 'plugin.sqlite3-wal' ! -name 'plugin.sqlite3-shm' ! -name 'plugin.sqlite3-journal' -printf '%i:%f\n' 2>/dev/null | sort | paste -sd/ -
}
sml_state()  { if [ -e "$DEST/data/sqlite_migration.log" ]; then triple_of "$DEST/data/sqlite_migration.log"; else echo "absent"; fi; }
migrated_of() { local l; l="$(find "$DEST/data" -mindepth 1 -maxdepth 1 -name '*.json.migrated' -printf '%f\n' 2>/dev/null | sort | paste -sd/ -)"; echo "${l:--}"; }
# The holders of a file, by its key, read on the host as root, which sees every process — the container's too — and
# opens nothing: the locks in /proc/locks, and every process's open descriptors (/proc/<pid>/fd, each resolved by stat -L
# to the file it holds open, one already unlinked included). Each answers ? when it cannot see: no key, a malformed key,
# /proc/locks unreadable, or a live process whose descriptors cannot be listed. A zero is only a zero beside a control.
key_ok() { case "$1" in ''|*[!0-9:]*|:*|*:|*:*:*) return 1 ;; *:*) return 0 ;; *) return 1 ;; esac; }
lock_key() { local d="${1%%:*}" i="${1#*:}"; printf '%02x:%02x:%s' $(( (d >> 8) & 0xfff )) $(( (d & 0xff) | ((d >> 12) & 0xfff00) )) "$i"; }
locks_keys() {
  [ "$#" -gt 0 ] || { echo "?"; return; }
  local k w=""; for k in "$@"; do key_ok "$k" || { echo "?"; return; }; w="$w $(lock_key "$k")"; done
  [ -r /proc/locks ] || { echo "?"; return; }
  awk -v keys="$w" 'BEGIN { n = split(keys, a, " "); for (i = 1; i <= n; i++) want[a[i]] = 1 } $2 != "->" && ($6 in want) { c++ } END { print c + 0 }' /proc/locks 2>/dev/null || echo "?"
}
fds_keys() {
  [ "$#" -gt 0 ] || { echo "?"; return; }
  local k p unread=0 n; for k in "$@"; do key_ok "$k" || { echo "?"; return; }; done
  for p in /proc/[0-9]*; do [ -d "$p/fd" ] || continue; ls -- "$p/fd" >/dev/null 2>&1 || { [ -d "$p/fd" ] && unread=$((unread + 1)); }; done
  [ "$unread" = "0" ] || { echo "?"; return; }
  n="$( { for p in /proc/[0-9]*/fd/*; do printf '%s\0' "$p"; done; } 2>/dev/null | xargs -0 -r stat -L -c '%d:%i' 2>/dev/null \
       | awk -v keys="$*" 'BEGIN { n = split(keys, a, " "); for (i = 1; i <= n; i++) want[a[i]] = 1 } ($1 in want) { c++ } END { print c + 0 }')"
  case "$n" in ''|*[!0-9]*) echo "?" ;; *) echo "$n" ;; esac
}
# The controls, run once in every mode before any holder count is believed: (1) a throwaway WAL store held open by
# python3 on the host must show a lock and a descriptor while open, and neither once closed; (2) a process inside the
# ucrm container, as the database's owner, holding the installed manifest.json open must raise the host's descriptor
# count on that file by one, and give it back when it ends — so a holder in the container is seen too. Sets HOLDERS_OK.
holders_control() {
  local d pid i hl hf cl cf mk base held after
  HOLDERS_OK="no"; HOLDERS_CTL=""
  if ! command -v python3 >/dev/null 2>&1; then HOLDERS_CTL="no python3 on this host"; return 0; fi
  d="$(mktemp -d 2>/dev/null)" || { HOLDERS_CTL="no temporary folder"; return 0; }
  trap 'rm -rf -- "$d"; exit 130' INT TERM HUP   # a Ctrl-C here leaves no throwaway store behind (nothing else is changed yet)
  python3 - "$d" >/dev/null 2>&1 9<&- <<'PY' &
import os, sqlite3, sys, time
d = sys.argv[1]
c = sqlite3.connect(os.path.join(d, 'control.sqlite3'))
c.execute('PRAGMA journal_mode=WAL'); c.execute('CREATE TABLE t (x)'); c.commit(); c.execute('SELECT * FROM t').fetchall()
open(os.path.join(d, 'ready'), 'w').close()
for _ in range(600):
    if os.path.exists(os.path.join(d, 'go')): break
    time.sleep(0.05)
c.close()
PY
  pid=$!
  for i in $(seq 1 200); do [ -f "$d/ready" ] && break; sleep 0.05; done
  hl="$(locks_keys "$(key_of "$d/control.sqlite3")" "$(key_of "$d/control.sqlite3-shm")")"; hf="$(fds_keys "$(key_of "$d/control.sqlite3")")"
  touch "$d/go"; wait "$pid" 2>/dev/null
  cl="$(locks_keys "$(key_of "$d/control.sqlite3")")"; cf="$(fds_keys "$(key_of "$d/control.sqlite3")")"
  rm -rf -- "$d"; trap - INT TERM HUP
  mk="$(key_of "$DEST/manifest.json")"; base="$(fds_keys "$mk")"; held="?"; after="?"
  case "$base" in ''|*[!0-9]*) ;; *)
    if docker exec -d -u "$DB_OWNER" "$CONTAINER" sh -c 'exec 3<"$1"; exec sleep 6' sh "$IN_CONTAINER/manifest.json" </dev/null >/dev/null 2>&1 9<&-; then
      for i in $(seq 1 50); do held="$(fds_keys "$mk")"; case "$held" in ''|*[!0-9]*) ;; *) [ "$held" -gt "$base" ] && break ;; esac; sleep 0.1; done
      for i in $(seq 1 150); do after="$(fds_keys "$mk")"; case "$after" in ''|*[!0-9]*) ;; *) [ "$after" -le "$base" ] && break ;; esac; sleep 0.1; done
    fi ;;
  esac
  HOLDERS_CTL="host store: locks $hl, descriptors $hf while open; $cl, $cf once closed — container: descriptors on manifest.json $base, $held while held, $after after"
  case "$hl:$hf:$cl:$cf" in [1-9]*:[1-9]*:0:0) ;; *) return 0 ;; esac
  case "$base:$held:$after" in *[!0-9:]*|*::*|:*|*:) return 0 ;; esac
  [ "$held" -gt "$base" ] && [ "$after" -le "$base" ] && HOLDERS_OK="yes"
  return 0
}
# holders KEY… — "<locks> <descriptors>", read twice with the files' state between: STEADY=no when the counts or the
# stray store's state moved in between. ? throughout unless the controls passed.
holders() {
  local l1 f1 s1 l2 f2 s2
  if [ "${HOLDERS_OK:-}" != "yes" ]; then HOLD="? ?"; STEADY="no"; return 0; fi
  l1="$(locks_keys "$@")"; f1="$(fds_keys "$@")"; s1="$(stray_state)"
  l2="$(locks_keys "$@")"; f2="$(fds_keys "$@")"; s2="$(stray_state)"
  HOLD="$l2 $f2"; STEADY="yes"
  [ "$l1:$f1:$s1" = "$l2:$f2:$s2" ] || STEADY="no"
  return 0
}
held_by_none() { [ "$HOLD" = "0 0" ] && [ "$STEADY" = "yes" ]; }
# The gate as a code root has it, on THIS server's own configuration (A12, R18): run as the database's owner, with the
# live store held open read-only as cron/master.php holds it while the job decides. StarlinkRetryScope::explain() of the
# INSTALLED plugin folder: the live directory it finds (compared with the one this script found), whether that is the
# plugin's own data/, how the live store was read, the country, the decision. Then, for the record, the country each
# source names on its own: the store row, and the configuration files with the vault. Read-only; names and states only.
SLRS_PHP="$(cat <<'PHP'
[$code, $inst, $pdd] = array_slice($argv, 1, 3);
try {
    require_once $code . "/lib/StarlinkRetryScope.php";
    $p = new PDO("sqlite:" . rtrim($pdd, "/") . "/plugin.sqlite3", null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 15, PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY]);
    $row = $p->query("SELECT data FROM kyc_config WHERE id = 0 LIMIT 1")->fetchColumn();
    $x = StarlinkRetryScope::explain($inst);
    require_once $code . "/lib/PluginConfig.php"; require_once $code . "/lib/TenantProfile.php";
    $sel = function (array $c) { foreach (["tenant_profile", "currency_code", "cashbook_base_currency"] as $k) if (isset($c[$k]) && trim((string)$c[$k]) !== "") return TenantProfile::resolveId($c); return "none"; };
    $s = is_string($row) ? (json_decode($row, true) ?: []) : [];
    $f = PluginConfig::read($inst, (string)$x["live"]);
    echo "live=", ((string)$x["live"] === rtrim($pdd, "/") ? "same" : "differs"), " inside=", ($x["inside"] ? "yes" : "no"), " store=", $x["store"],
         " tenant=", ($x["tenant"] ?? "-"), " skip=", ($x["skip"] ? "yes" : "no"), " | by-store=", $sel($s), " by-files+vault=", $sel($f);
} catch (Throwable $e) { echo "?", get_class($e); }
PHP
)"
# The gate's answer: the probe's own line (live=…) or its own ? — anything else (no answer, a runtime's error text on
# standard output while the container restarts) is ?noanswer, never a reading.
gate_line() {
  [ -n "${DB_OWNER:-}" ] || { echo "?noowner"; return; }
  local g; g="$(docker exec -u "$DB_OWNER" "$CONTAINER" php -d display_errors=stderr -r "$SLRS_PHP" "$IN_CONTAINER" "$IN_CONTAINER" "$PDD_IN" 2>/dev/null </dev/null)"
  case "$g" in "live="*|"?"*) printf '%s\n' "$g" ;; *) echo "?noanswer" ;; esac
}
gate_ok()   { [ "${1%% | *}" = "$SLR_SERVER_SIGNATURE" ] || return 1; case "$1" in *" | "*" by-files+vault=uganda") return 0 ;; esac; return 1; }
# The probe asks the gate's library, which a rollback leaves on disk: the gate stands only if the INSTALLED job calls it —
# the needle deploy-5.18.91.sh and deploy-5.18.93.sh use. 5.18.90's job has no gate, and neither has any release before.
gate_installed() { grep -qF '    return StarlinkRetryScope::skip(__DIR__);' "$DEST/cron_starlink_block_retry.php" 2>/dev/null; }
ungated_version() { case "$1" in [0-9]*.[0-9]*.[0-9]*) [ "$1" != "5.18.91" ] && [ "$(printf '%s\n%s\n' 5.18.91 "$1" | sort -V | head -1)" = "$1" ] ;; *) return 1 ;; esac; }
manifest_version() { grep -o '"version": *"5[^"]*"' "$DEST/manifest.json" 2>/dev/null | head -1 | sed -E 's/.*"(5[^"]*)".*/\1/'; }
gate_files_bad() {   # the files of the gate not as 69c87a6 has them, or nothing
  local e f want got out=""
  while IFS= read -r e; do f="${e%%:*}"; want="${e#*:}"; got="$(sha_of "$DEST/$f")"; [ "$got" = "$want" ] || out="$out $f"; done <<< "$GATE_FILES"
  printf '%s' "$out"
}
# slr_last_run's answer as text, on one line: a time; a time and "(still running)"; or "— no record (…)".
run_text() {
  case "${1#running:}" in
    ''|*[!0-9]*|0) printf '— no record (%s)' "$1";;
    *) printf '%s' "$(utc_of "${1#running:}")"; [ "$1" != "${1#running:}" ] && printf ' (still running)';;
  esac
  return 0
}
# When cron/master.php last dispatched the retry job: master_schedule's sl_block_retry in the live store, read-only, as the
# database's owner. Master writes last_run (the cycle's start) with duration_ms -1 BEFORE it includes the job, and the
# duration after the job returns: so an epoch is a completed run, running:<epoch> one under way, 0 none, ? unreadable.
slr_last_run() {
  [ -n "$DB_OWNER" ] || { echo "?"; return; }
  docker exec -u "$DB_OWNER" "$CONTAINER" php -r '
    try {
      $p = new PDO("sqlite:" . rtrim($argv[1], "/") . "/plugin.sqlite3", null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 15, PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY]);
      $row = $p->query("SELECT data FROM master_schedule WHERE id = 0 LIMIT 1")->fetchColumn();
      $d = is_string($row) ? json_decode($row, true) : null;
      $r = is_array($d) ? ($d["sl_block_retry"] ?? null) : null;
      echo !is_array($r) ? 0 : (((int)($r["duration_ms"] ?? 0) < 0 ? "running:" : "") . (int)($r["last_run"] ?? 0));
    } catch (Throwable $e) { echo "?"; }
  ' "$PDD_IN" 2>/dev/null || echo "?"
}
ctr_state() { docker inspect "$CONTAINER" --format '{{.State.StartedAt}} {{.RestartCount}}' 2>/dev/null </dev/null || echo "?"; }
# live_commit [TRIES] — the container's commit, read as deploy-hybrid.sh's read_live reads it: only a wholly hex line, or
# nothing (never a runtime's error text with its hex letters kept); TRIES reads 5 s apart, 6 by default.
live_commit() {
  local i c; for i in $(seq 1 "${1:-6}"); do
    c="$(docker exec "$CONTAINER" cat "$IN_CONTAINER/.deployed-commit" 2>/dev/null </dev/null | tail -n1 | tr -d '[:space:]')"
    case "$c" in ''|*[!0-9a-f]*) ;; *) echo "$c"; return ;; esac
    sleep 5; done; echo ""
}

# ── Where things are: the container's /data, the plugin folder, the live database ───────────────────────────────────
hdr "A. Before-evidence (changes nothing outside $Q_DIR)"
MOUNT="$(docker inspect "$CONTAINER" --format '{{range .Mounts}}{{if eq .Destination "/data"}}{{.Source}}{{end}}{{end}}' 2>/dev/null </dev/null || true)"
[ -n "$MOUNT" ] || stop "container '$CONTAINER' has no /data mount"
DEST="$MOUNT/ucrm/data/plugins/$PLUGIN"
IN_CONTAINER="${IN_CONTAINER:-/data/ucrm/data/plugins/$PLUGIN}"
case "$DEST" in *plugins_staging*) stop "the plugin folder found is a staging folder ($DEST)";; esac
[ -f "$DEST/manifest.json" ] || stop "no installed plugin at $DEST"
SRC="$DEST/data/plugin.sqlite3"
LIVE="$(live_commit)"
LIVE_VERSION="$(grep -o '"version": *"5[^"]*"' "$DEST/manifest.json" | head -1 | sed -E 's/.*"(5[^"]*)".*/\1/')"
say "  serves          $DEST"
say "  live commit     ${LIVE:-unknown}   version ${LIVE_VERSION:-?}"
# The live data directory, derived as deploy-5.18.93.sh derives it — and never <plugin>/data.
PDD_IN="$(docker exec "$CONTAINER" php -r '$u=@json_decode((string)@file_get_contents($argv[1]),true); echo rtrim((string)($u["pluginDataDir"]??""),"/");' "$IN_CONTAINER/ucrm.json" 2>/dev/null </dev/null || true)"
if [ -z "$PDD_IN" ]; then
  if [ -f "$MOUNT/ucrm/data/plugins/.$PLUGIN-data/plugin.sqlite3" ] || docker exec "$CONTAINER" test -f "/data/ucrm/data/plugins/.$PLUGIN-data/plugin.sqlite3" </dev/null; then PDD_IN="/data/ucrm/data/plugins/.$PLUGIN-data"; else PDD_IN="$IN_CONTAINER/data"; fi
fi
[ "${PDD_IN%/}" = "$IN_CONTAINER/data" ] && stop "NO-GO: the plugin's live data directory would be $IN_CONTAINER/data — the stray store itself (no live store .$PLUGIN-data/plugin.sqlite3 was found on the host or in the container, or ucrm.json names that folder). With no live database, the file must stay where it is: getDataDir() would otherwise have nothing to rescue from. Nothing was changed"
case "$PDD_IN" in /data/*) PDD_HOST="$MOUNT/${PDD_IN#/data/}" ;; *) stop "NO-GO: the live data directory ($PDD_IN) is not under the container's /data — this script cannot find it on the host. Nothing was changed" ;; esac
[ -f "$PDD_HOST/plugin.sqlite3" ] || stop "NO-GO: no live database at $PDD_HOST/plugin.sqlite3 on the host. Nothing was changed"
LIVE_KEY="$(key_of "$PDD_HOST/plugin.sqlite3")"
DB_OWNER="$(docker exec "$CONTAINER" stat -c '%u:%g' "$PDD_IN/plugin.sqlite3" 2>/dev/null </dev/null || true)"
case "$DB_OWNER" in [0-9]*:[0-9]*) ;; *) stop "NO-GO: the live database's owner could not be read in the container" ;; esac
say "  live database   $PDD_HOST/plugin.sqlite3 (in the container $PDD_IN), owner $DB_OWNER"
say "  stray store     $SRC"
say "  kept as         $KEPT"

# ── The controls for the holder counts — in every mode ──────────────────────────────────────────────────────────────
holders_control
if [ "$HOLDERS_OK" = "yes" ]; then say "  holders control $HOLDERS_CTL — both seen, both gone: a zero below means no holder"
else say "  holders control $HOLDERS_CTL — NOT PASSED: no holder count below can be believed"; fi

case "$MODE" in
  quarantine) quarantine_mode ;;
  check)      check_mode ;;
  putback)    putback_mode ;;
esac
}
# ════════════════════════════════════════════════════════════════════════════════════════════════════════════════════
# What every mode prints at its end: what may and may not be run from now on.
runbook() {
  say ""
  say "  From now on — and after a put-back too:"
  say "   1. deploy-5.18.91.sh, deploy-5.18.92.sh and deploy-5.18.93.sh: only ever with --rollback, whichever release is live."
  say "      Any other mode becomes an after-check while its own release is live, fails R19 on the moved file and records that"
  say "      failure in its state file for good (STRAY_R19_FAILED)."
  say "   2. Before deploy-5.18.91.sh --rollback, or the by-hand put-back of 5.18.90: --put-back first. 5.18.90's retry job has"
  say "      no gate and would make a new database in <plugin>/data; neither of those checks for it."
  say "   3. Never run migrations/migrate_tickets_data.php: it makes a new database in <plugin>/data."
  say "   4. While the file is quarantined (not after a put-back): diagnose-sales-pipeline.sh reports \"plugin.sqlite3 missing —"
  say "      open the plugin page once\": expected, and that advice does not apply. scripts/phase*-*.sh stop with \"no database\""
  say "      where they fall back to <plugin>/data."
  say "   5. Never follow the in-app DATA-zip restore instructions on this server. After any upload of the plugin through uCRM,"
  say "      a rollback or a restore: run --check."
  say "   6. The next release's deploy script must read $RECORD (after a put-back, its PUTBACK_DIR_T, PUTBACK_ENTS and"
  say "      PUTBACK_STRAY are the stray folder's reference: its time is the put-back's own link's)."
}
putback_text() {
  say ""
  say "  Only if it is ever needed — its own command, never pasted together with the quarantine (root docs/44 §16.9) —"
  say "  the put-back asks you to type PUTBACK before it changes anything. It gives the kept file its old name back and"
  say "  removes the kept name: the same file, unchanged. It is needed before any rollback to 5.18.90; nothing else needs it:"
  say "      cd $REPO && bash $SELF --put-back 2>&1 | tee $Q_DIR/putback-\$(date -u +%Y%m%dT%H%M%SZ).log"
}
summary() {   # summary — the counts and the verdict: FAILED, NOT CONCLUSIVE (nothing failed, nothing concluded), or PASSED
  say "  checks            $PASS ok, $FAIL failed, $NOTE notes"
  say ""
  if [ "$FAIL" != "0" ]; then say "  stray-store quarantine ($MODE): $FAIL FAILED — send the log file, with every *.own.log in $Q_DIR and $RECORD. Do nothing else first."
  elif [ -n "$UNSETTLED" ]; then say "  stray-store quarantine ($MODE): NOT CONCLUSIVE — $UNSETTLED. Send this LOG FILE back (not a copy of the terminal), with every *.own.log in $Q_DIR and $RECORD."
  else say "  stray-store quarantine ($MODE): PASSED. Send this LOG FILE back (not a copy of the terminal), with every *.own.log in $Q_DIR and $RECORD."; fi
}
# The gate after the move, judged and said in words. First the INSTALLED job must call the gate (a rollback leaves the
# library on disk): without it, FAIL. Then the probe: the signature with Uganda named by the files and the vault — ok; a
# skip on which every source agrees but the live store was not read as required — not measured; any other reading parsed
# as the probe's own (live=… skip=… | …) — FAIL: the job's next run would make a new database where the file was; no
# answer, or the probe's own ? — not measured. A time to run --check again is never before the move + 20 minutes.
next_run() {   # the retry job's next run at the earliest: master's last + 600 s, never before now; ? when unread
  local n now; now="$(date -u +%s)"; n="$(slr_last_run)"
  case "$n" in ''|*[!0-9]*) echo "?" ;; *) n=$(( n + 600 )); [ "$n" -ge "$now" ] || n="$now"; utc_of "$n" ;; esac
}
gate_after() {   # gate_after ID
  local g t qa now; now="$(date -u +%s)"
  if ! gate_installed; then
    if [ "$MODE" = "check" ]; then
      bad "$1 the INSTALLED retry job has no gate (cron_starlink_block_retry.php does not call StarlinkRetryScope::skip — ${LIVE_VERSION:-an unknown version}'s; the gate's library a rollback left on disk is reached by nothing): its next run (at the earliest $(next_run)) makes a new database in <plugin>/data. Run --put-back now (runbook rule 2), then send this log"
    else
      bad "$1 the INSTALLED retry job has no gate (cron_starlink_block_retry.php does not call StarlinkRetryScope::skip): its next run (at the earliest $(next_run)) makes a new database in <plugin>/data. Send this log now and wait for a decision"
    fi
    return 0
  fi
  g="$(gate_line)"
  if gate_ok "$g"; then ok "$1 the INSTALLED retry job's gate still stops it on this server's configuration ($g)"; return 0; fi
  t=$(( now + 600 )); qa="$(rec_get Q_AT)"; case "$qa" in ''|*[!0-9]*) ;; *) [ $(( qa + 1200 )) -gt "$t" ] && t=$(( qa + 1200 )) ;; esac
  case "$g" in
    "live=same inside=no store="*" tenant=uganda skip=yes | by-store=uganda by-files+vault=uganda"|"live=same inside=no store="*" tenant=uganda skip=yes | by-store=none by-files+vault=uganda")
       note "$1 the gate still stops the retry job, but it was not read as required ($g; required: $SLR_SERVER_SIGNATURE): the live store could not be read a second time — not measured as required. Do nothing; run --check at or after $(utc_of "$t")"
       unsettled "the gate was not read as required: run --check at or after $(utc_of "$t")" ;;
    "live="*" skip="*" | "*)
       bad "$1 the gate no longer reads as it must ($g): the retry job's next run (at the earliest $(next_run)) would make a new database in <plugin>/data. Do NOT put the kept file back — the job would open it. Send this log now and wait for a decision" ;;
    *) note "$1 the gate's question could not be asked ($(printf '%s' "${g:-?}" | tr '\n' ' ')): not measured. Do nothing; run --check at or after $(utc_of "$t")"
       unsettled "the gate was not measured: run --check at or after $(utc_of "$t")" ;;
  esac
}
# The --check time after a move at $1: twenty minutes on.
check_time() { utc_of $(( $1 + 1200 )); }
# The put-back text, only when nothing FAILED: after a failure the kept file stays where it is until a decision.
putback_or_hold() {
  if [ "$FAIL" = "0" ]; then putback_text
  else say ""; say "  Do not run the put-back: a check above FAILED. Send these logs and wait for a decision."; fi
}

# ════════════════════════════════════════════════════════════════════════════════════════════════════════════════════
quarantine_mode() {
  local st pst pat kq archive="" OLD_KEY=""
  st="$(rec_get Q_STAGE)"; pst="$(rec_get PUTBACK_STAGE)"; pat="$(rec_get PUTBACK_AT)"; kq="$(rec_get Q_KEY_BEFORE)"
  # An earlier run's record decides first: every state a run can leave has one command (docs/07).
  [ -z "$pat" ] || stop "the stray store was put back at $(utc_of "$pat") — a second quarantine needs its own reviewed script"
  [ -z "$pst" ] || stop "a put-back did not finish: run --put-back again"
  case "$st" in
    done)
      [ -z "$(rec_get Q_CHECK_FAILED)" ] || stop "already quarantined at $(utc_of "$(rec_get Q_AT)"), but its own checks FAILED at $(utc_of "$(rec_get Q_CHECK_FAILED)") — send every *.own.log in $Q_DIR and $RECORD now, and wait for a decision"
      stop "already quarantined at $(utc_of "$(rec_get Q_AT)") — run --check at or after $(check_time "$(rec_get Q_AT)"); send every *.own.log in $Q_DIR and $RECORD with its log" ;;
    linked|moved)
      if absent "$SRC" && [ -f "$KEPT" ] && [ ! -L "$KEPT" ] && [ "$(links_of "$KEPT")" = "1" ] && [ "$(key_of "$KEPT")" = "$kq" ] && key_ok "$kq"; then
        say "  record          an earlier run moved the file ($st) and did not finish its checks: this run does them, and changes nothing"
        trap '' INT TERM HUP PIPE
        OLD_KEY="$kq"
        [ -n "$(rec_get Q_AT)" ] || { rec_put Q_AT "$(stat -c %Z -- "$KEPT")"; rec_put Q_STAGE moved; }
        stage_c; if [ "$FAIL" = "0" ]; then say ""; say "  hand-over"; say "  --check           at or after $(check_time "$(rec_get Q_AT)")"; fi; runbook; summary; putback_or_hold
        [ "$FAIL" = "0" ]; return
      fi
      if [ -e "$SRC" ] && [ -f "$KEPT" ] && [ "$(key_of "$SRC")" = "$(key_of "$KEPT")" ]; then
        stop "an earlier run stopped between its two steps: the stray store has two names, $SRC and $KEPT. Run --put-back: it removes only the kept name and changes nothing in <plugin>/data. Then run this again"
      fi
      stop "the record says $st, but the files are not as that leaves them (old name: $(absent "$SRC" && echo absent || echo present); kept: $(absent "$KEPT" && echo absent || echo "present, key $(key_of "$KEPT")")) — send this log" ;;
    prepared)
      if [ -e "$SRC" ] && [ -f "$KEPT" ] && [ "$(key_of "$SRC")" = "$(key_of "$KEPT")" ]; then
        stop "an earlier run stopped between its two steps: the stray store has two names, $SRC and $KEPT. Run --put-back: it removes only the kept name and changes nothing in <plugin>/data. Then run this again"
      fi
      if [ -f "$SRC" ] && [ "$(links_of "$SRC")" = "1" ] && absent "$KEPT"; then
        note "an earlier run stopped before it moved anything (its record: prepared); its record is set aside and this run starts again"; archive=yes
      else stop "the record says prepared, but the files are not as that leaves them — send this log"; fi ;;
    abandoned) absent "$KEPT" || stop "the record says abandoned, but $KEPT exists — send this log"; archive=yes ;;
    "") [ ! -f "$RECORD" ] || stop "$RECORD exists with no stage in it — send this log"
        absent "$KEPT" || stop "$KEPT exists with no record beside it — send this log" ;;
    *) stop "the record's stage is not one this script writes ($st) — send this log" ;;
  esac

  # ── A0 the release ──
  [ "$LIVE" = "$EXPECTED_LIVE_COMMIT" ] || stop "NO-GO: the container serves ${LIVE:-an unknown commit}, not $EXPECTED_LIVE_COMMIT ($EXPECTED_VERSION). This script is for 5.18.93 alone; another live release needs its own reviewed quarantine. Nothing was changed"
  [ "$LIVE_VERSION" = "$EXPECTED_VERSION" ] || stop "NO-GO: the container's record says $EXPECTED_LIVE_COMMIT, but the installed manifest says ${LIVE_VERSION:-?}, not $EXPECTED_VERSION. Nothing was changed"
  local gb; gb="$(gate_files_bad)"
  [ -z "$gb" ] || stop "NO-GO: installed, not as $EXPECTED_LIVE_COMMIT has them:$gb — the retry job and its gate must be 5.18.93's. Nothing was changed"
  ok "A0 the container serves $EXPECTED_LIVE_COMMIT ($EXPECTED_VERSION); the retry job and the four files its gate reads are that release's, byte for byte"

  # ── A1 the live database ──
  [ -e "$SRC" ] || [ -L "$SRC" ] || stop "NO-GO: there is no stray store at $SRC — nothing to quarantine. Nothing was changed"
  local src_key; src_key="$(key_of "$SRC")"
  key_ok "$src_key" && key_ok "$LIVE_KEY" || stop "NO-GO: the keys of the two databases could not be read ($src_key, $LIVE_KEY). Nothing was changed"
  [ "$src_key" != "$LIVE_KEY" ] || stop "NO-GO: the stray store and the live database are the same file ($src_key) — nothing may be moved. Nothing was changed"
  ok "A1 the live database is found ($PDD_HOST/plugin.sqlite3, key $LIVE_KEY), and the stray store is another file (key $src_key)"

  # ── A2 the deploy records ──
  [ -f "$STATE93" ] || stop "NO-GO: no $STATE93 — 5.18.93's deploy record, which this script compares against. Nothing was changed"
  local c_line c_at c_ctrl c_ents c_db c_dir c_side
  c_line="$(sed -n 's/^STRAY_COPY=//p' "$STATE93" | head -1)"; c_at="$(sed -n 's/^STRAY_COPY_AT=//p' "$STATE93" | head -1)"
  c_ctrl="$(sed -n 's/^STRAY_CTRL=//p' "$STATE93" | head -1)"; c_ents="$(sed -n 's/^STRAY_ENTS=//p' "$STATE93" | head -1)"
  c_db="$(printf '%s' "$c_line" | sed -nE 's/^db=([^ ]+) .*/\1/p')"; c_dir="$(printf '%s' "$c_line" | sed -nE 's/.* dir=([0-9]+)$/\1/p')"
  c_side="$(printf '%s' "$c_line" | sed -nE 's/.* side=([^ ]+) .*/\1/p')"
  [ -n "$c_db" ] && [ -n "$c_dir" ] && [ "$c_side" = "-" ] || stop "NO-GO: $STATE93 holds no usable STRAY_COPY line (${c_line:-none}). Nothing was changed"
  case "$c_at" in ''|*[!0-9]*) stop "NO-GO: $STATE93 holds no usable STRAY_COPY_AT (${c_at:-none}). Nothing was changed" ;; esac
  [ "$c_ctrl" = "seen" ] || stop "NO-GO: 5.18.93's A14 did not see the stray store as 5.18.92 recorded it (STRAY_CTRL=${c_ctrl:-none}). Nothing was changed"
  [ -n "$c_ents" ] || stop "NO-GO: $STATE93 holds no STRAY_ENTS. Nothing was changed"
  local f r19f="" scanned=0 saw93=""
  for f in "$RECORDS"/dnb-5.18.9[123]/state-5.18.9[123].env "$RECORDS"/dnb-5.18.9[123]/state-5.18.9[123].env.prev-* "$RECORDS"/dnb-5.18.9[123]/rollback-state-*.env; do
    [ -f "$f" ] || continue; scanned=$((scanned + 1)); [ "$f" = "$STATE93" ] && saw93=yes
    grep -q '^STRAY_R19_FAILED=' "$f" 2>/dev/null && r19f="$r19f ${f#"$RECORDS"/}"
  done
  [ -n "$saw93" ] || stop "NO-GO: the search of the deploy records did not read $STATE93 — it cannot be believed. Nothing was changed"
  [ -z "$r19f" ] || stop "NO-GO: an R19 failure is recorded (STRAY_R19_FAILED) in:$r19f — something opened the stray store after a deploy. It is not quarantined on that record; send every log file. Nothing was changed"
  ok "A2 5.18.93's record: the stray store at its copy ($c_db, its folder's time $(utc_of "$c_dir")), the copy at $(utc_of "$c_at"), its A14 \"seen\"; no R19 failure in any of the $scanned deploy records from 5.18.91 on"

  # ── A3 the day-later check: R19's last chance, and the evidence this waits for ──
  local bound_e bound copy_utc lf ts te best="" best_e=0 n_logs=0
  bound_e=$(( c_at + 86400 )); bound="$(utc_of "$bound_e")"; copy_utc="$(utc_of "$c_at")"
  for lf in "$REC93"/*.log; do
    [ -f "$lf" ] || continue
    ts="$(grep -m1 -E "^== $EXPECTED_VERSION \((deploy|after)\) — [0-9]{8}T[0-9]{6}Z — .* ==\$" "$lf" 2>/dev/null | sed -nE 's/^== [^ ]+ \([a-z]+\) — ([0-9]{8}T[0-9]{6}Z) — .*/\1/p')"
    case "$ts" in [0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9]T[0-9][0-9][0-9][0-9][0-9][0-9]Z) ;; *) continue ;; esac
    te="$(date -u -d "${ts:0:4}-${ts:4:2}-${ts:6:2}T${ts:9:2}:${ts:11:2}:${ts:13:2}Z" +%s 2>/dev/null)"
    case "$te" in ''|*[!0-9]*) continue ;; esac
    n_logs=$((n_logs + 1))
    if [ "$te" -ge "$bound_e" ] && [ "$te" -ge "$best_e" ]; then best="$lf"; best_e="$te"; fi
  done
  [ -n "$best" ] || stop "NO-GO: no day-later check yet — no log of deploy-$EXPECTED_VERSION.sh in $REC93 begun at or after $bound (a day after the copy; $n_logs log(s) of it there, all earlier). Run its --after-only at or after $bound, send that log, and only then this. Nothing was changed"
  if grep -q "^  $EXPECTED_VERSION (after): PASSED\." "$best" && grep -qE '^  checks +[0-9]+ ok, 0 failed, [0-9]+ notes$' "$best" \
     && ! grep -q '^  FAIL  ' "$best" && grep -F '  ok    R19 the retry job ran at ' "$best" | grep -qF "after this deploy's copy (at $copy_utc)"; then
    ok "A3 the day-later check: ${best#"$REC93"/}, begun $(utc_of "$best_e") (at or after $bound), PASSED with 0 failed and R19's conclusive line — the last check that can read the stray store where it is"
  else
    stop "NO-GO: the day-later log ${best#"$REC93"/} (begun $(utc_of "$best_e")) did not pass, or did not conclude R19 (its conclusive line naming this deploy's copy at $copy_utc). Run nothing more; send that log — this script waits for a decision. Nothing was changed"
  fi

  # ── A4 the stray file, as files ──
  [ -f "$SRC" ] && [ ! -L "$SRC" ] || stop "NO-GO: $SRC is not a regular file. Nothing was changed"
  local links t full side dir_t ents fa da
  links="$(links_of "$SRC")"; [ "$links" = "1" ] || stop "NO-GO: the stray store has $links names (hard links) — moving one would leave it reachable under another. Nothing was changed"
  fa="$(attrs_of "$SRC")"; da="$(attrs_of "$DEST/data")"
  case "$fa$da" in *i*|*a*) stop "NO-GO: an immutable or append-only attribute is set (file ${fa:-?}, folder ${da:-?}). Nothing was changed" ;; esac
  [ -n "$fa" ] && [ -n "$da" ] || note "A4 the file attributes could not be read (lsattr: file ${fa:-—}, folder ${da:-—}); a link or unlink they would refuse is caught, and nothing is moved"
  t="$(triple_of "$SRC")"
  [ "$t" = "$EXPECTED_STRAY_DB" ] || stop "NO-GO: the stray store is not as every deploy since 5.18.91 recorded it ($t, recorded $EXPECTED_STRAY_DB). Nothing was changed"
  [ "$t" = "$c_db" ] || stop "NO-GO: the stray store is not as 5.18.93's copy recorded it ($t, recorded $c_db). Nothing was changed"
  side="$(side_of "$SRC")"; [ "$side" = "-" ] || stop "NO-GO: side files beside the stray store ($side) — something has it open, or left it so. Nothing was changed"
  dir_t="$(stat -c %Y -- "$DEST/data" 2>/dev/null)"
  [ "$dir_t" = "$EXPECTED_STRAY_DIR_T" ] || stop "NO-GO: the stray folder's time is $(utc_of "${dir_t:-0}"), not $(utc_of "$EXPECTED_STRAY_DIR_T") as every deploy since 5.18.91 recorded it — an entry was made or removed in it. Nothing was changed"
  [ "$dir_t" = "$c_dir" ] || stop "NO-GO: the stray folder's time is $(utc_of "${dir_t:-0}"), not $(utc_of "$c_dir") as 5.18.93's copy recorded it. Nothing was changed"
  ents="$(stray_entries)"
  if [ "$ents" != "$c_ents" ]; then
    say "  entries now     $(printf '%s' "$ents" | tr '/' '\n' | sed 's/^[0-9]*://' | paste -sd' ' -)"
    say "  differ from     $(diff <(printf '%s' "$c_ents" | tr '/' '\n') <(printf '%s' "$ents" | tr '/' '\n') | sed -nE 's/^([<>]) [0-9]+:(.*)$/\1\2/p' | sed 's/^</removed:/; s/^>/added:/' | paste -sd' ' -)"
    stop "NO-GO: the stray folder's entries are not as 5.18.93's copy recorded them. Nothing was changed"
  fi
  full="$(sha_of "$SRC")"
  local owner mode birth ctime dctime dkey sml mig
  owner="$(owner_of "$SRC")"; mode="$(mode_of "$SRC")"; birth="$(birth_of "$SRC")"; ctime="$(ctime_of "$SRC")"; dctime="$(ctime_of "$DEST/data")"
  dkey="$(data_key)"; sml="$(sml_state)"; mig="$(migrated_of)"
  ok "A4 the stray store is exactly as every deploy since 5.18.91 recorded it ($t; sha256 ${full:0:16}…), one name, no side file, owner $owner, mode $mode, born ${birth//_/ }; its folder unchanged since $(utc_of "$dir_t"), its entries as 5.18.93's copy saw them; sqlite_migration.log: $sml; *.json.migrated: $mig"

  # ── A5 holders ──
  [ "$HOLDERS_OK" = "yes" ] || stop "NO-GO: the holder instruments' controls did not pass ($HOLDERS_CTL) — that nothing holds the stray store cannot be shown. Nothing was changed"
  holders "$src_key"
  held_by_none || stop "NO-GO: the stray store is held — locks and open descriptors: $HOLD$( [ "$STEADY" = "yes" ] || echo ", changing while read"). Nothing was changed"
  ok "A5 no process holds the stray store: no lock, no open descriptor, steady over two reads — beside the controls"

  # ── A6 the gate, on the INSTALLED code and this server's configuration ──
  local gate; gate="$(gate_line)"
  gate_ok "$gate" || stop "NO-GO: the installed retry job's gate does not read as it must on this server ($gate; required: $SLR_SERVER_SIGNATURE, with the configuration files and the vault naming Uganda) — with the file gone, its next run would make a new database there. Nothing was changed"
  ok "A6 the INSTALLED retry job's gate stops it on this server's configuration, whether or not it can read the live database ($gate)"

  # ── A7 the quarantine folder ──
  local qr mr dr pr lr dev_s dev_q
  for f in "$KEPT" "$KEPT-wal" "$KEPT-shm" "$KEPT-journal"; do absent "$f" || stop "NO-GO: $f exists already. Nothing was changed"; done
  qr="$(realpath -e -- "$Q_DIR" 2>/dev/null)"; mr="$(realpath -m -- "$MOUNT")"; dr="$(realpath -m -- "$DEST")"; pr="$(realpath -m -- "$DEST/..")"; lr="$(realpath -m -- "$PDD_HOST")"
  [ -n "$qr" ] || stop "NO-GO: the quarantine folder's real path could not be read. Nothing was changed"
  for f in "$mr" "$dr" "$pr" "$lr"; do
    if [ "$qr" = "$f" ] || [ "${qr#"$f"/}" != "$qr" ]; then stop "NO-GO: the quarantine folder ($qr) is inside $f. Nothing was changed"; fi
  done
  case "/$qr/" in */.*-data/*) stop "NO-GO: the quarantine folder's path has a folder named like a data folder (.*-data). Nothing was changed" ;; esac
  dev_s="$(stat -c %d -- "$DEST/data" 2>/dev/null)"; dev_q="$(stat -c %d -- "$Q_DIR" 2>/dev/null)"
  say "  filesystems     the stray folder: $(findmnt -T "$DEST/data" -no TARGET,SOURCE,FSTYPE 2>/dev/null | tr -s ' ' | head -1) (device $dev_s)"
  say "                  the quarantine folder: $(findmnt -T "$Q_DIR" -no TARGET,SOURCE,FSTYPE 2>/dev/null | tr -s ' ' | head -1) (device $dev_q)"
  [ -n "$dev_s" ] && [ "$dev_s" = "$dev_q" ] || stop "NO-GO: the quarantine folder is on another filesystem than the stray folder — the file could only be copied, which this script never does. A destination on the same filesystem needs its own reviewed change. Nothing was changed"
  local ids n_ids=0 n_ins=0 id out nm p src_m dst_m rs seen_ucrm="" sight=""
  ids="$(docker ps -aq 2>/dev/null </dev/null)" || stop "NO-GO: docker ps failed — which containers can see the quarantine folder cannot be told. Nothing was changed"
  for id in $ids; do
    n_ids=$((n_ids + 1))
    out="$(docker inspect --format '{{.Name}}{{range .Mounts}}{{"\n"}}{{.Destination}}|{{.Source}}{{end}}' "$id" 2>/dev/null </dev/null)" || stop "NO-GO: docker inspect failed for one container — which containers can see the quarantine folder cannot be told. Nothing was changed"
    [ -n "$out" ] || stop "NO-GO: docker inspect said nothing for one container. Nothing was changed"
    n_ins=$((n_ins + 1)); nm="$(printf '%s\n' "$out" | head -1)"
    while IFS= read -r p; do
      [ -n "$p" ] || continue; dst_m="${p%%|*}"; src_m="${p#*|}"; [ -n "$src_m" ] || continue
      rs="$(realpath -m -- "$src_m")"
      [ "$nm" = "/$CONTAINER" ] && [ "$dst_m" = "/data" ] && [ "$rs" = "$mr" ] && seen_ucrm=yes
      if [ "$rs" = "/" ] || [ "$qr" = "$rs" ] || [ "${qr#"$rs"/}" != "$qr" ]; then sight="$sight ${nm#/}:$dst_m"; fi
    done < <(printf '%s\n' "$out" | tail -n +2)
  done
  [ "$n_ids" -gt 0 ] && [ "$n_ids" = "$n_ins" ] || stop "NO-GO: $n_ids container(s) listed, $n_ins inspected. Nothing was changed"
  [ -n "$seen_ucrm" ] || stop "NO-GO: the inspection did not find $CONTAINER's /data at $MOUNT — it cannot be believed about the others. Nothing was changed"
  [ -z "$sight" ] || stop "NO-GO: the quarantine folder can be seen from inside a container:$sight. Nothing was changed"
  ok "A7 the quarantine folder $qr is root's, mode 700, on the same filesystem as the stray folder, inside no plugin, data or mount folder, and out of sight of all $n_ins containers (their mounts read, $CONTAINER's /data among them); nothing kept there yet"

  # ── A8 printed ──
  local ctr slr se
  ctr="$(ctr_state)"; slr="$(slr_last_run)"
  case "${slr#running:}" in ''|*[!0-9]*) stop "NO-GO: master's record of the retry job could not be read (${slr:-no answer}) — whether it is running the job cannot be told. Run this again in ten minutes. Nothing was changed" ;; esac
  se="$(command -v getenforce >/dev/null 2>&1 && getenforce 2>/dev/null || echo "no getenforce")"
  say "  container       $CONTAINER started ${ctr% *}, restarts ${ctr##* }"
  say "  retry job       master's last run of it $(run_text "$slr")"
  say "  SELinux         $se"

  # ── A9 the backup's copy, hashed as a stream — nothing written ──
  local bk ar lst asz ash
  bk="$(ls -d "$REC93"/backup-* 2>/dev/null | sort | tail -1)"; ar="$bk/stray-data.tar.gz"
  if [ -n "$bk" ] && [ -f "$ar" ]; then
    lst="$(TZ=UTC tar --full-time -tvzf "$ar" data/plugin.sqlite3 2>/dev/null)"
    asz="$(printf '%s\n' "$lst" | awk 'NR == 1 && substr($1, 1, 1) == "-" { print $3 }')"
    ash="$(set -o pipefail; tar -xzOf "$ar" data/plugin.sqlite3 2>/dev/null | sha256sum | cut -c1-64)" || ash=""
    if [ -n "$ash" ] && [ "$ash" = "$full" ] && [ "$(printf '%s\n' "$lst" | grep -c .)" = "1" ] && [ "$asz" = "$(stat -c %s -- "$SRC")" ]; then
      say "  backup copy     ${ar#"$RECORDS"/}: the same bytes (sha256 ${ash:0:16}…, $asz bytes) — a second copy, as files, already on this host"
    else note "A9 the newest backup's copy of the stray store (${ar#"$RECORDS"/}) could not be shown to be the same bytes (sha256 ${ash:0:16}, size ${asz:-?}) — the kept file is the one to keep"; fi
  else note "A9 no backup of 5.18.93's deploy holds a copy of the stray folder ($REC93/backup-*/stray-data.tar.gz)"; fi

  [ "$FAIL" = "0" ] || stop "NO-GO"
  say ""; say "  GO — evidence recorded. The put-back command is printed at the end of this log, on its own, unless a check fails."

  # ════ B. The move ════
  hdr "B. The move"
  say "  Type QUARANTINE to move the stray store out of the plugin folder (anything else stops; nothing has changed yet):"
  local answer=""; read -r answer </dev/tty || answer=""
  [ "$answer" = "QUARANTINE" ] || stop "not confirmed"
  # From here until the record says done, the run is not stopped by the terminal: Ctrl-C, a hang-up, a dead tee.
  trap '' INT TERM HUP PIPE
  # Everything again, the gate included, before anything moves; the holders last.
  local l2 g2 gb2 s2
  l2="$(live_commit)"; [ "$l2" = "$EXPECTED_LIVE_COMMIT" ] || stop "nothing moved: the container now serves ${l2:-an unknown commit}"
  [ "$(grep -o '"version": *"5[^"]*"' "$DEST/manifest.json" | head -1 | sed -E 's/.*"(5[^"]*)".*/\1/')" = "$EXPECTED_VERSION" ] || stop "nothing moved: the installed manifest changed"
  gb2="$(gate_files_bad)"; [ -z "$gb2" ] || stop "nothing moved: installed, not as $EXPECTED_LIVE_COMMIT has them:$gb2"
  g2="$(gate_line)"; gate_ok "$g2" || stop "nothing moved: the gate no longer reads as it must ($g2)"
  local i r rt; for i in $(seq 1 18); do
    r="$(slr_last_run)"; rt="${r#running:}"
    case "$r" in running:[0-9]*) [ $(( $(date -u +%s) - rt )) -ge 900 ] && { note "B master's record says the retry job is running since $(utc_of "$rt") — over 15 minutes: a record a run that died left behind"; break; }; sleep 5 ;; *) break ;; esac
  done
  case "$r" in
    running:[0-9]*) [ $(( $(date -u +%s) - rt )) -ge 900 ] || stop "nothing moved: master is running the retry job now (since $(utc_of "$rt")) — run this again in ten minutes" ;;
    ''|*[!0-9]*) stop "nothing moved: master's record of the retry job could not be read (${r:-no answer}) — whether it is running the job now cannot be told; run this again in ten minutes" ;;
  esac
  [ "$(triple_of "$SRC")" = "$t" ] && [ "$(key_of "$SRC")" = "$src_key" ] && [ "$(links_of "$SRC")" = "1" ] && [ "$(side_of "$SRC")" = "-" ] \
    && [ "$(ctime_of "$SRC")" = "$ctime" ] || stop "nothing moved: the stray store changed since stage A read it"
  [ "$(stat -c %Y -- "$DEST/data" 2>/dev/null)" = "$dir_t" ] && [ "$(ctime_of "$DEST/data")" = "$dctime" ] && [ "$(stray_entries)" = "$ents" ] || stop "nothing moved: the stray folder changed since stage A read it"
  for f in "$KEPT" "$KEPT-wal" "$KEPT-shm" "$KEPT-journal"; do absent "$f" || stop "nothing moved: $f appeared"; done
  holders "$src_key"; held_by_none || stop "nothing moved: the stray store is held now ($HOLD)"
  # The record, before anything changes.
  if [ -n "$archive" ] && [ -f "$RECORD" ]; then mv -- "$RECORD" "$RECORD.void-$TS" || stop "nothing moved: the earlier record could not be set aside"; fi
  { printf 'Q_TS=%s\nQ_STAGE=prepared\nQ_SRC=%s\nQ_KEPT=%s\nQ_SHA256=%s\nQ_DB=%s\nQ_OWNER=%s\nQ_MODE=%s\nQ_KEY_BEFORE=%s\nQ_BIRTH=%s\nQ_DIR_T_BEFORE=%s\n' \
      "$TS" "$SRC" "$KEPT" "$full" "$t" "$owner" "$mode" "$src_key" "$birth" "$dir_t"
    printf 'Q_ENTS_BEFORE=%s\nQ_DATA_KEY=%s\nQ_SML=%s\nQ_MIGRATED=%s\nQ_LIVE_COMMIT=%s\nQ_LIVE_KEY=%s\nQ_PDD_IN=%s\nQ_GATE=%s\nQ_CTR=%s\n' \
      "$ents" "$dkey" "$sml" "$mig" "$LIVE" "$LIVE_KEY" "$PDD_IN" "$g2" "$ctr"; } >> "$RECORD" || stop "nothing moved: the record could not be written"
  OLD_KEY="$src_key"
  # One: a second name, in the quarantine folder.
  if ! ln -T -- "$SRC" "$KEPT" 2>/dev/null; then
    if absent "$KEPT"; then rec_put Q_STAGE abandoned; stop "nothing moved: the link could not be made (the stray store is untouched, one name)"; fi
    rec_put Q_STAGE abandoned; stop "the link failed but $KEPT exists — send this log"
  fi
  if [ "$(key_of "$KEPT")" != "$src_key" ] || [ "$(key_of "$SRC")" != "$src_key" ] || [ "$(links_of "$SRC")" != "2" ]; then
    stop "the kept name is not the stray store's second name (key $(key_of "$KEPT"), links $(links_of "$SRC")) — nothing was removed. Send this log; do not run anything else"
  fi
  rec_put Q_STAGE linked
  say "  linked          $KEPT is the stray store's second name (key $src_key, 2 names)"
  # Two: the old name removed.
  rm -f -- "$SRC" 2>/dev/null
  if ! absent "$SRC"; then
    if [ "$(key_of "$SRC")" = "$src_key" ] && [ "$(key_of "$KEPT")" = "$src_key" ]; then
      rm -f -- "$KEPT" 2>/dev/null
      if absent "$KEPT" && [ "$(links_of "$SRC")" = "1" ]; then rec_put Q_STAGE abandoned; stop "nothing moved: the old name could not be removed; the kept name was removed again, and the stray store is as it was (one name)"; fi
    fi
    stop "the old name could not be removed, and the kept name could not be taken back — run --put-back; send this log"
  fi
  local q_at; q_at="$(date -u +%s)"
  rec_put Q_AT "$q_at"; rec_put Q_STAGE moved
  say "  moved           the old name is gone at $(utc_of "$q_at"); the stray store is $KEPT"
  stage_c
  if [ "$FAIL" = "0" ]; then
    say ""; say "  hand-over"
    say "  --check           at or after $(check_time "$q_at"): cd $REPO && bash $SELF --check 2>&1 | tee $Q_DIR/check-\$(date -u +%Y%m%dT%H%M%SZ).log"
  fi
  runbook; summary; putback_or_hold
  [ "$FAIL" = "0" ]
}

# ── C. Straight after the move — from the record, so that a resumed run checks the same ──────────────────────────────
stage_c() {
  hdr "C. After the move (read-only)"
  local rk ino exp now dt kt q_at
  rk="$(rec_get Q_KEY_BEFORE)"; q_at="$(rec_get Q_AT)"
  if key_ok "$rk" && [ "$OLD_KEY" = "$rk" ]; then ok "C1 the moved file's key, read in stage A, is the record's ($rk)"; else bad "C1 the moved file's key ($OLD_KEY) is not the record's (${rk:-none})"; fi
  if absent "$SRC" && [ "$(side_of "$SRC")" = "-" ]; then ok "C2 $SRC is gone, and no side file is there"
  else bad "C2 at the old name: $(absent "$SRC" && echo "no file" || echo "a file (key $(key_of "$SRC"))"), side files $(side_of "$SRC") — something has, or made, a database there"; fi
  ino="${rk#*:}"
  exp="$(rec_get Q_ENTS_BEFORE | tr '/' '\n' | grep -vxF "$ino:plugin.sqlite3" | paste -sd/ -)"; now="$(stray_entries)"
  if [ "$now" = "$exp" ] && [ "$(rec_get Q_ENTS_BEFORE | tr '/' '\n' | grep -cxF "$ino:plugin.sqlite3")" = "1" ]; then ok "C3 the stray folder's entries are exactly as before, less the one entry"
  else bad "C3 the stray folder's entries are not as before less the one entry: $(diff <(printf '%s' "$exp" | tr '/' '\n') <(printf '%s' "$now" | tr '/' '\n') | sed -nE 's/^([<>]) [0-9]+:(.*)$/\1\2/p' | sed 's/^</missing:/; s/^>/new:/' | paste -sd' ' -)"; fi
  dt="$(stat -c %Y -- "$DEST/data" 2>/dev/null)"
  say "  stray folder    its time now $(utc_of "${dt:-0}") (before: $(utc_of "$(rec_get Q_DIR_T_BEFORE)"))"
  kt="$(triple_of "$KEPT")"
  if [ "$(key_of "$KEPT")" = "$rk" ] && [ "$(links_of "$KEPT")" = "1" ] && [ "$(sha_of "$KEPT")" = "$(rec_get Q_SHA256)" ] && [ "$kt" = "$(rec_get Q_DB)" ] \
     && [ "$(owner_of "$KEPT")" = "$(rec_get Q_OWNER)" ] && [ "$(mode_of "$KEPT")" = "$(rec_get Q_MODE)" ] && [ "$(birth_of "$KEPT")" = "$(rec_get Q_BIRTH)" ]; then
    ok "C4 the kept file is the stray store itself: the same key, one name, the same sha256, size, time, owner ($(rec_get Q_OWNER)), mode ($(rec_get Q_MODE)) and birth"
  else bad "C4 the kept file is not the stray store as recorded (key $(key_of "$KEPT"), names $(links_of "$KEPT"), $kt, owner $(owner_of "$KEPT"), mode $(mode_of "$KEPT"))"; fi
  if [ "$(side_of "$KEPT")" = "-" ]; then ok "C5 no side file beside the kept file"; else bad "C5 side files beside the kept file ($(side_of "$KEPT")) — something opened it"; fi
  if [ "$HOLDERS_OK" != "yes" ]; then   # only a resumed run reaches this: in a whole run A5 refused first
    note "C6 whether anything holds the moved file was not measured: the controls did not pass ($HOLDERS_CTL) — --check measures it again (K10)"
    unsettled "the holders were not measured after the move: run --check at or after $(check_time "$q_at")"
  else holders "$rk"
    if held_by_none; then ok "C6 no process holds the moved file — no lock, no descriptor, by its key, which is the same file the old name was"
    else bad "C6 the moved file is held ($HOLD$( [ "$STEADY" = "yes" ] || echo ", changing")) — a process opened it before the old name went"; fi
  fi
  # The commit is read through the container (24 reads, 5 s apart: two minutes, as the deploy scripts wait): an unread
  # commit is not measured, never "changed" — the live database and the retry job's files are read on the host.
  local lc; lc="$(live_commit 24)"
  if [ "$(key_of "$PDD_HOST/plugin.sqlite3")" != "$(rec_get Q_LIVE_KEY)" ] || [ -n "$(gate_files_bad)" ] || { [ -n "$lc" ] && [ "$lc" != "$(rec_get Q_LIVE_COMMIT)" ]; }; then
    bad "C7 the live commit (${lc:-not read}), the live database or the retry job's files changed during the move"
  elif [ -z "$lc" ]; then
    note "C7 the live database (key $(rec_get Q_LIVE_KEY)) and the retry job's files are as before; the live commit could not be read through the container for two minutes (a restart — C9): not measured, --check reads it again (K2)"
    unsettled "the live commit was not read after the move: run --check at or after $(check_time "$q_at")"
  else ok "C7 the live commit, the live database (key $(rec_get Q_LIVE_KEY)) and the retry job's files are as before"; fi
  gate_after C8
  local c2; c2="$(ctr_state)"
  if [ "$c2" = "$(rec_get Q_CTR)" ]; then say "  container       as before (started ${c2% *}, restarts ${c2##* })"
  else note "C9 the container restarted or changed during the run (before: $(rec_get Q_CTR); now: $c2) — whether uCRM recycles on a change under data/ is not known. C7's commit read and C8's gate question go through the container; if either says not measured above, this is why — --check measures both again"; fi
  rec_put Q_DIR_T_AFTER "${dt:-0}"; rec_put Q_ENTS_AFTER "$now"; rec_put Q_KEPT_CTIME "$(ctime_of "$KEPT")"
  [ "$FAIL" = "0" ] || rec_put Q_CHECK_FAILED "$(date -u +%s)"
  rec_put Q_STAGE done
}

# ════════════════════════════════════════════════════════════════════════════════════════════════════════════════════
check_mode() {
  hdr "K. --check (changes nothing but its own log and, on a failure, one line in the record)"
  local st pst pat rk q_at
  [ -f "$RECORD" ] || stop "no quarantine record in $Q_DIR — nothing has been quarantined"
  st="$(rec_get Q_STAGE)"; pst="$(rec_get PUTBACK_STAGE)"; pat="$(rec_get PUTBACK_AT)"; rk="$(rec_get Q_KEY_BEFORE)"; q_at="$(rec_get Q_AT)"
  say "  record          stage ${st:-none}$( [ -n "$q_at" ] && echo ", moved at $(utc_of "$q_at")")$( [ -n "$pat" ] && echo ", put back at $(utc_of "$pat")")"
  if [ -n "$pat" ]; then
    if [ "$(triple_of "$SRC")" = "$(rec_get Q_DB)" ] && [ "$(key_of "$SRC")" = "$rk" ] && absent "$KEPT"; then ok "K1 put back: $SRC is the stray store again, its file unchanged ($(rec_get Q_DB), key $rk); nothing kept; side files $(side_of "$SRC")"
    else bad "K1 put back, but $SRC is not the stray store as recorded ($(triple_of "$SRC"), key $(key_of "$SRC"))"; fi
    # After a put-back the store is where the retry job of 5.18.90 opens it: what follows is said, never judged.
    [ "$(side_of "$SRC")" = "-" ] || note "K1 side files beside the restored store ($(side_of "$SRC")): it is open now, or was left so — expected once 5.18.90's retry job runs; not judged after a put-back"
    local pr; pr="$(rec_get PUTBACK_DIR_T)"
    if [ -n "$pr" ] && { [ "$(stat -c %Y -- "$DEST/data" 2>/dev/null)" != "$pr" ] || [ "$(stray_entries)" != "$(rec_get PUTBACK_ENTS)" ]; }; then
      note "K1 <plugin>/data changed since the put-back (its time $(utc_of "$(stat -c %Y -- "$DEST/data" 2>/dev/null || echo 0)"), then $(utc_of "$pr")) — an open of the restored store does this; not judged after a put-back"
    fi
    runbook; summary; [ "$FAIL" = "0" ]; return
  fi
  if [ -n "$pst" ]; then
    note "K1 a put-back did not finish — run --put-back again; this check judges nothing until it has"; unsettled "a put-back did not finish: run --put-back again"
    runbook; summary; return 0
  fi
  # Every other state an earlier run can leave, decided from the files as well as the record: one command each.
  local two=""
  key_ok "$rk" && [ -e "$SRC" ] && [ -f "$KEPT" ] && [ ! -L "$KEPT" ] && [ "$(key_of "$SRC")" = "$rk" ] && [ "$(key_of "$KEPT")" = "$rk" ] && two=yes
  case "$st" in
    done) ;;
    prepared|linked|moved|abandoned)
      if [ -n "$two" ] && { [ "$st" = prepared ] || [ "$st" = linked ]; }; then
        note "K1 an earlier quarantine stopped between its two steps (record: $st): the stray store has two names, $SRC and $KEPT — run --put-back (it removes only the kept name), then the quarantine again"
        unsettled "HALF-DONE: run --put-back"; pre_rule
      elif { [ "$st" = linked ] || [ "$st" = moved ]; } && key_ok "$rk" && absent "$SRC" && [ -f "$KEPT" ] && [ ! -L "$KEPT" ] && [ "$(links_of "$KEPT")" = "1" ] && [ "$(key_of "$KEPT")" = "$rk" ]; then
        note "K1 the quarantine moved the file and did not finish its checks (record: $st) — run the quarantine command again (it does only its checks)"
        unsettled "HALF-DONE: run the quarantine command again"; runbook
      elif { [ "$st" = prepared ] || [ "$st" = abandoned ]; } && [ -f "$SRC" ] && [ ! -L "$SRC" ] && [ "$(links_of "$SRC")" = "1" ] && absent "$KEPT"; then
        note "K1 not quarantined (record: $st): an earlier run moved nothing — nothing to check; run the quarantine again when it is due"
        unsettled "NOT QUARANTINED: nothing was checked"; pre_rule
      else
        bad "K1 the record (stage $st) and the files match no state a run leaves (old name: $(absent "$SRC" && echo absent || echo "present, key $(key_of "$SRC"), $(links_of "$SRC") names"); kept: $(absent "$KEPT" && echo absent || echo "present, key $(key_of "$KEPT"), $(links_of "$KEPT") names")) — send this log"
      fi
      summary; [ "$FAIL" = "0" ]; return ;;
    *) bad "K1 the record's stage is not one this script writes (${st:-none}) — send this log"; summary; return 1 ;;
  esac
  local fail0="$FAIL" indet="" lv f k earlier
  earlier="$(rec_get Q_CHECK_FAILED)"
  [ -z "$earlier" ] || bad "K0 an earlier run FAILED (at $(utc_of "$earlier"); recorded in $RECORD as Q_CHECK_FAILED) — what it saw may no longer be there, so no later check passes over it. Send every log file"
  lv="$LIVE"; [ "$lv" = "$EXPECTED_LIVE_COMMIT" ] || note "K2 the container serves ${lv:-an unknown commit}, not $EXPECTED_LIVE_COMMIT — after a rollback to 5.18.92 or 5.18.91 the gate is the same; below 5.18.91 the file must have been put back first"
  if [ ! -d "$DEST/data" ]; then note "K3 the folder <plugin>/data is gone — something replaced the plugin folder: no conclusion. Send this log"; indet=yes
  elif [ "$(data_key)" != "$(rec_get Q_DATA_KEY)" ]; then note "K3 <plugin>/data is another folder than at the move ($(data_key); then $(rec_get Q_DATA_KEY)) — something replaced it: what is or is not in it now proves nothing about the old one. Send this log"; indet=yes
  else ok "K3 <plugin>/data is the same folder as at the move"; fi
  if absent "$SRC" && [ "$(side_of "$SRC")" = "-" ]; then ok "K4 no database at the old name, and no side file there"
  else bad "K4 a database, or its side files, at the old name ($(absent "$SRC" && echo "no file" || echo "a file, $(triple_of "$SRC")"), side files $(side_of "$SRC")) — something made a new stray store"; fi
  if [ "$(sml_state)" = "$(rec_get Q_SML)" ]; then ok "K5 sqlite_migration.log as at the move ($(rec_get Q_SML))"
  else bad "K5 sqlite_migration.log changed since the move ($(rec_get Q_SML) → $(sml_state)) — a new store's first start writes it"; fi
  if [ "$(migrated_of)" = "$(rec_get Q_MIGRATED)" ]; then ok "K6 no new *.json.migrated ($(rec_get Q_MIGRATED))"
  else bad "K6 *.json.migrated changed since the move ($(rec_get Q_MIGRATED) → $(migrated_of)) — a new store's first start renames the folder's *.json"; fi
  local ea en; ea="$(rec_get Q_ENTS_AFTER)"; en="$(stray_entries)"
  if [ "$en" != "$ea" ] && [ -z "$indet" ]; then note "K7 other entries made or removed in <plugin>/data since the move — names only: $(diff <(printf '%s' "$ea" | tr '/' '\n') <(printf '%s' "$en" | tr '/' '\n') | sed -nE 's/^([<>]) [0-9]+:(.*)$/\1\2/p' | sed 's/^</removed:/; s/^>/new:/' | paste -sd' ' -) (other writers make files there; judged above only for a database)"; fi
  if [ -f "$KEPT" ] && [ ! -L "$KEPT" ] && [ "$(key_of "$KEPT")" = "$rk" ] && [ "$(links_of "$KEPT")" = "1" ] && [ "$(sha_of "$KEPT")" = "$(rec_get Q_SHA256)" ] \
     && [ "$(triple_of "$KEPT")" = "$(rec_get Q_DB)" ] && [ "$(owner_of "$KEPT")" = "$(rec_get Q_OWNER)" ] && [ "$(mode_of "$KEPT")" = "$(rec_get Q_MODE)" ]; then
    ok "K8 the kept file is unchanged: the same key, one name, sha256, size, time, owner and mode"
  else bad "K8 the kept file is missing or changed ($( [ -e "$KEPT" ] && echo "key $(key_of "$KEPT"), names $(links_of "$KEPT"), $(triple_of "$KEPT"), owner $(owner_of "$KEPT"), mode $(mode_of "$KEPT")" || echo "absent"))"; fi
  [ "$(ctime_of "$KEPT")" = "$(rec_get Q_KEPT_CTIME)" ] || note "K8 the kept file's change time moved since the move (something changed its owner, mode or names)"
  if [ "$(side_of "$KEPT")" = "-" ]; then ok "K9 no side file beside the kept file"; else bad "K9 side files beside the kept file ($(side_of "$KEPT")) — something opened it"; fi
  local other; other="$(find "$Q_DIR" -mindepth 1 -maxdepth 1 ! -name "$KEPT_NAME" ! -name 'quarantine.env' ! -name 'quarantine.env.void-*' ! -name '*.log' -printf '%f ' 2>/dev/null)"
  [ -z "$other" ] || note "K9 other entries in $Q_DIR: $other"
  if [ "$HOLDERS_OK" != "yes" ]; then note "K10 whether anything holds the kept file cannot be told: the controls did not pass ($HOLDERS_CTL)"; indet=yes
  else holders "$rk"; if held_by_none; then ok "K10 no process holds the kept file"; else bad "K10 the kept file is held ($HOLD)"; fi; fi
  gate_after K11
  for f in "$RECORDS"/dnb-5.18.9[123]/state-5.18.9[123].env; do
    [ -f "$f" ] || continue
    k="$(grep -m1 '^STRAY_R19_FAILED=' "$f" 2>/dev/null)"; [ -n "$k" ] || continue
    note "K12 ${f#"$RECORDS"/} records an R19 failure (${k#STRAY_R19_FAILED=}) — none was there before the move (A2): a run of that deploy script in a mode the runbook forbids, after the move. Not evidence of an opener; the next deploy script must read it so"
  done
  local r rt now; r="$(slr_last_run)"; rt="$r"; now="$(date -u +%s)"
  say "  retry job       master's last run of it $(run_text "$r"); the move at $(utc_of "$q_at")"
  if [ "$FAIL" != "$fail0" ]; then :
  elif [ -n "$indet" ]; then note "K13 not conclusive (above) — send this log"; unsettled "not conclusive (above): send this log"
  elif case "$q_at" in ''|*[!0-9]*) true ;; *) false ;; esac; then note "K13 the record holds no usable move time (Q_AT=${q_at:-none}): not conclusive — send this log and $RECORD"; unsettled "the record holds no move time: send this log and $RECORD"
  elif case "$rt" in ''|*[!0-9]*) true ;; *) false ;; esac; then note "K13 master's record of the retry job is not a completed run ($r): not yet conclusive. Run --check again after $(utc_of $(( now + 600 )))"; unsettled "run --check again after $(utc_of $(( now + 600 )))"
  elif [ "$rt" -gt "$now" ]; then note "K13 master's record of the retry job ($(utc_of "$rt")) is later than now: not evidence"; unsettled "master's record is later than now: not evidence — send this log"
  elif [ "$rt" -gt $(( q_at + 120 )) ]; then ok "K13 the retry job ran at $(utc_of "$rt"), after the move (at $(utc_of "$q_at")), and nothing made a new database, side file, sqlite_migration.log or *.json.migrated in <plugin>/data; the kept file is unchanged: the quarantine holds"
  elif [ "$now" -lt $(( q_at + 1200 )) ]; then note "K13 master has not run the retry job since the move yet (its last: $(utc_of "$rt")): not yet conclusive. Run --check again after $(check_time "$q_at")"; unsettled "run --check again after $(check_time "$q_at")"
  else   # twenty minutes and no run: the job runs every ten — master is not running it as before
    note "K13 master has not started the retry job in the $(( (now - q_at) / 60 )) minutes since the move (its last: $(utc_of "$rt")) — it is not being run as before: not conclusive. Send this log now; run --check again after $(utc_of $(( now + 600 ))) only if asked"
    unsettled "master has not run the retry job since the move, $(( (now - q_at) / 60 )) minutes ago: send this log"; fi
  if [ "$FAIL" != "$fail0" ] && [ -z "$earlier" ]; then rec_put Q_CHECK_FAILED "$now" && say "  record          the failure is recorded in $RECORD (Q_CHECK_FAILED): every later --check fails too"; fi
  runbook; summary
  [ "$FAIL" = "0" ]
}

# ════════════════════════════════════════════════════════════════════════════════════════════════════════════════════
# The stray folder's reference after a put-back — its time (set by the put-back's own link), entries and the store as
# files — written before PUTBACK_AT, so that a run that dies between them writes them again (the last line is read).
pb_ref() { rec_put PUTBACK_DIR_T "$(stat -c %Y -- "$DEST/data" 2>/dev/null || echo 0)"; rec_put PUTBACK_ENTS "$(stray_entries)"; rec_put PUTBACK_STRAY "$(stray_state)"; }
putback_mode() {
  hdr "P. The put-back"
  [ -f "$RECORD" ] || stop "no quarantine record in $Q_DIR — nothing to put back"
  local st pst pat kq case_="" what
  st="$(rec_get Q_STAGE)"; pst="$(rec_get PUTBACK_STAGE)"; pat="$(rec_get PUTBACK_AT)"; kq="$(rec_get Q_KEY_BEFORE)"
  [ -z "$pat" ] || stop "already put back at $(utc_of "$pat") — nothing to do"
  key_ok "$kq" || stop "the record holds no usable key of the stray store — send this log"
  if [ -e "$SRC" ] || [ -L "$SRC" ]; then
    if [ -f "$KEPT" ] && [ "$(key_of "$SRC")" = "$kq" ] && [ "$(key_of "$KEPT")" = "$kq" ]; then
      case_="half"
      if [ -z "$pst" ]; then
        case "$st" in prepared|linked) what="the quarantine stopped between its two steps: the stray store has two names; the kept name is removed, nothing in <plugin>/data changes, and the quarantine may then run again" ;;
          *) stop "both names lead to the stray store, but the record (stage $st) says nothing that leaves them so — send this log" ;; esac
      else what="a put-back stopped between its two steps: the kept name is removed" ; fi
    elif absent "$KEPT" && [ -n "$pst" ] && [ "$(key_of "$SRC")" = "$kq" ] && [ "$(links_of "$SRC")" = "1" ]; then
      case_="finish"; what="a put-back removed the kept name and did not write its record: only the record is written"
    elif absent "$KEPT" && [ -z "$pst" ] && [ "$(key_of "$SRC")" = "$kq" ] && [ "$(links_of "$SRC")" = "1" ] && [ "$(triple_of "$SRC")" = "$(rec_get Q_DB)" ]; then
      case "$st" in
        prepared|abandoned) stop "nothing is kept: $SRC is still the stray store itself (key $kq, one name) and the record (stage $st) says it was never moved — there is nothing to put back. Nothing was changed" ;;
        *) stop "the record says $st, but the stray store is back at its old name (key $kq, one name) by another hand, and nothing is kept — send this log. Nothing was changed" ;;
      esac
    else stop "NO-GO: there is a file at $SRC (key $(key_of "$SRC"), $(triple_of "$SRC")) that is not the kept stray store — it is never overwritten. If something made a new database there, send this log. Nothing was changed"
    fi
  else
    [ -f "$KEPT" ] && [ ! -L "$KEPT" ] || stop "NO-GO: no kept file at $KEPT, and nothing at $SRC — send this log. Nothing was changed"
    case "$st" in done|moved|linked) ;; *) stop "NO-GO: the record (stage ${st:-none}) does not say the file was moved. Nothing was changed" ;; esac
    case_="back"; what="the kept file gets its old name back, then the kept name is removed: the same file, unchanged"
  fi
  if [ "$case_" != "finish" ]; then
    [ "$(key_of "$KEPT")" = "$kq" ] && [ "$(sha_of "$KEPT")" = "$(rec_get Q_SHA256)" ] && [ "$(triple_of "$KEPT")" = "$(rec_get Q_DB)" ] \
      && [ "$(owner_of "$KEPT")" = "$(rec_get Q_OWNER)" ] && [ "$(mode_of "$KEPT")" = "$(rec_get Q_MODE)" ] \
      || stop "NO-GO: the kept file is not the stray store as recorded (key $(key_of "$KEPT"), $(triple_of "$KEPT"), owner $(owner_of "$KEPT"), mode $(mode_of "$KEPT")) — it is not put back. Nothing was changed"
    [ "$(side_of "$KEPT")" = "-" ] || stop "NO-GO: side files beside the kept file ($(side_of "$KEPT")). Nothing was changed"
  fi
  for f in "$SRC-wal" "$SRC-shm" "$SRC-journal"; do absent "$f" || stop "NO-GO: $f exists — something has, or had, a database open at the old name. Nothing was changed"; done
  [ -d "$DEST/data" ] && [ ! -L "$DEST/data" ] || stop "NO-GO: <plugin>/data is not there as a folder. Nothing was changed"
  [ "$HOLDERS_OK" = "yes" ] || stop "NO-GO: the holder instruments' controls did not pass ($HOLDERS_CTL). Nothing was changed"
  holders "$kq"; held_by_none || stop "NO-GO: the stray store is held now ($HOLD). Nothing was changed"
  [ "$case_" != "back" ] || [ "$(stat -c %d -- "$DEST/data")" = "$(stat -c %d -- "$Q_DIR")" ] || stop "NO-GO: the kept file is on another filesystem than <plugin>/data — it was not moved by this script. Nothing was changed"
  # What link(2) and unlink(2) would refuse, refused here first, before the word.
  if [ "$case_" != "finish" ]; then
    case "$(attrs_of "$KEPT")" in *i*|*a*) stop "NO-GO: an immutable or append-only attribute is set on the kept file ($(attrs_of "$KEPT")). Nothing was changed" ;; esac
  fi
  case "$(attrs_of "$DEST/data")$(attrs_of "$Q_DIR")" in *i*|*a*) stop "NO-GO: an immutable or append-only attribute is set on <plugin>/data or on $Q_DIR. Nothing was changed" ;; esac
  local g; g="$(gate_line)"
  say "  live commit     ${LIVE:-?} (version ${LIVE_VERSION:-?})"
  # The gate decides only when the file gets its old name back: refused while a release with the gate (5.18.91 on) is live
  # and the gate is not read as it must be — measured, or not measurable. With 5.18.90 or earlier live there is no gate.
  local gi=yes; gate_installed || gi=no
  if [ "$gi" = "yes" ] && gate_ok "$g"; then say "  gate            $g — the retry job will not open it"
  elif [ "$case_" != "back" ]; then say "  gate            ${g:-?} — the stray store keeps its old name either way: this changes nothing the retry job can open"
  elif ungated_version "$LIVE_VERSION"; then
    say "  gate            $LIVE_VERSION has no gate (its retry job calls none): the retry job WILL open it again on its next run, as before 5.18.91 (runbook rule 2)"
  elif [ "$gi" = "no" ]; then
    stop "NO-GO: the INSTALLED retry job has no gate (cron_starlink_block_retry.php does not call StarlinkRetryScope::skip) while the manifest says ${LIVE_VERSION:-an unknown version} — the installed code is not one release. Send this log. Nothing was changed"
  else
    case "$g" in
      "live=same inside=no store="*" tenant=uganda skip=yes | by-store=uganda by-files+vault=uganda"|"live=same inside=no store="*" tenant=uganda skip=yes | by-store=none by-files+vault=uganda")
        stop "NO-GO: the gate still stops the retry job but was not read as required ($g) while ${LIVE_VERSION:-an unknown version} is live. Run --put-back again in ten minutes. Nothing was changed" ;;
      "live="*" skip="*" | "*) ;;
      *) stop "NO-GO: the gate's question could not be asked (${g:-?}) while ${LIVE_VERSION:-an unknown version} is live — whether the retry job would open the file cannot be told. Run --put-back again in ten minutes. Nothing was changed" ;;
    esac
    stop "NO-GO: the INSTALLED retry job's gate no longer reads as it must ($g) while ${LIVE_VERSION:-an unknown version} is live — do NOT put the kept file back: the job would open it. Send this log and wait for a decision. Nothing was changed"
  fi
  ok "P0 $what"
  say ""; say "  Type PUTBACK to do it (anything else stops; nothing has changed yet):"
  local answer=""; read -r answer </dev/tty || answer=""
  [ "$answer" = "PUTBACK" ] || stop "not confirmed"
  trap '' INT TERM HUP PIPE
  holders "$kq"; held_by_none || stop "nothing changed: the stray store is held now ($HOLD)"
  case "$case_" in
    half)
      rm -f -- "$KEPT" 2>/dev/null
      absent "$KEPT" && [ "$(key_of "$SRC")" = "$kq" ] && [ "$(links_of "$SRC")" = "1" ] || stop "the kept name could not be removed — send this log"
      if [ -z "$pst" ]; then rec_put Q_STAGE abandoned; else pb_ref; rec_put PUTBACK_AT "$(date -u +%s)"; fi ;;
    finish) pb_ref; rec_put PUTBACK_AT "$(date -u +%s)" ;;
    back)
      for f in "$SRC" "$SRC-wal" "$SRC-shm" "$SRC-journal"; do absent "$f" || stop "nothing changed: $f appeared"; done
      local g2 v2; g2="$(gate_line)"; v2="$(manifest_version)"
      { gate_installed && gate_ok "$g2"; } || ungated_version "$v2" \
        || stop "nothing changed: the gate no longer reads as it must (${g2:-?}) while ${v2:-an unknown version} is live — do NOT put the kept file back: the job would open it. Send this log and wait for a decision"
      rec_put PUTBACK_STAGE prepared
      # A failed link(2) makes no name: the marker is taken back before either stop, so nothing reads a put-back as begun.
      ln -T -- "$KEPT" "$SRC" 2>/dev/null || { rec_put PUTBACK_STAGE ""; absent "$SRC" && stop "nothing changed: the link could not be made"; stop "the link failed and $SRC exists — nothing was put back; send this log"; }
      [ "$(key_of "$SRC")" = "$kq" ] && [ "$(links_of "$SRC")" = "2" ] || stop "the old name is not the kept file's second name — nothing was removed; send this log"
      rec_put PUTBACK_STAGE linked
      rm -f -- "$KEPT" 2>/dev/null
      absent "$KEPT" || stop "the kept name could not be removed — the stray store has both names; run --put-back again"
      pb_ref; rec_put PUTBACK_AT "$(date -u +%s)" ;;
  esac
  hdr "PB. After the put-back (read-only)"
  if [ "$(triple_of "$SRC")" = "$(rec_get Q_DB)" ] && [ "$(key_of "$SRC")" = "$kq" ] && [ "$(links_of "$SRC")" = "1" ] && [ "$(owner_of "$SRC")" = "$(rec_get Q_OWNER)" ] && [ "$(mode_of "$SRC")" = "$(rec_get Q_MODE)" ] && absent "$KEPT"; then
    ok "PB1 $SRC is the stray store again — the same key, one name, $(rec_get Q_DB), owner $(rec_get Q_OWNER), mode $(rec_get Q_MODE); nothing kept"
  else bad "PB1 $SRC is not the stray store as recorded ($(triple_of "$SRC"), key $(key_of "$SRC"), names $(links_of "$SRC"))"; fi
  say "  stray store     $(stray_state)"
  if [ "$case_" = "half" ] && [ -z "$pst" ]; then
    say "  record          the quarantine's half-done run is closed (abandoned); <plugin>/data was not changed by it, and the quarantine may run again"
  else
    say "  what this means getDataDir()'s rescue copy and cliDataDir()'s blind spot reach it again, as before the quarantine; the R19 of"
    say "                  deploy-5.18.91.sh, 5.18.92.sh and 5.18.93.sh can never pass again (its folder changed); a second quarantine needs"
    say "                  its own reviewed script; the next deploy script must read $RECORD — its PUTBACK_DIR_T,"
    say "                  PUTBACK_ENTS and PUTBACK_STRAY are the stray folder as this put-back left it"
  fi
  if [ "$case_" = "half" ] && [ -z "$pst" ]; then pre_rule; else runbook; fi; summary
  [ "$FAIL" = "0" ]
}
main "$@"; exit $?
