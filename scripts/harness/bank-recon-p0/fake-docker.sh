#!/usr/bin/env bash
# fake-docker.sh — the docker the P0 wrapper sees in the rehearsal. STRICT: it answers exactly the calls bank-recon-p0.sh
# makes, and every other call — another inspect format, another exec command, another php invocation, a cp — exits 99,
# so a new or changed call cannot pass unseen. The container's /data is $FAKE_MNT: every argument beginning /data/ is
# rewritten to it. -u is honoured with setpriv, as the container's own user would be. Every call is traced to $FAKE_TRACE.
MNT="${FAKE_MNT:?FAKE_MNT}"
T="${FAKE_TRACE:-/dev/null}"
no() { echo "fake docker: not an allowed call: $*" >&2; echo "REFUSED $*" | cut -c1-400 >> "$T"; exit 99; }
case "$1" in
  inspect)
    echo "inspect ${*:2}" | cut -c1-400 >> "$T"
    [ $# = 4 ] || no "$@"
    case "$2|$3|$4" in
      '-f|{{.State.Running}}|ucrm') echo "${FAKE_RUNNING:-true}" ;;
      'ucrm|--format|{{range .Mounts}}{{if eq .Destination "/data"}}{{.Source}}{{end}}{{end}}') echo "$MNT" ;;
      *) no "$@" ;;
    esac
    exit 0 ;;
  exec) shift ;;
  *) no "$@" ;;
esac
user=""; stdin=""
while [ $# -gt 0 ]; do case "$1" in
  -i) stdin="stdin"; shift ;;
  -u) user="$2"; shift 2 ;;
  -*) no exec "$@" ;;
  *) break ;;
esac; done
[ "$1" = "ucrm" ] || { echo "Error: No such container: $1" >&2; exit 1; }
[ "${FAKE_RUNNING:-true}" = "true" ] || { echo "Error: container $1 is not running" >&2; exit 1; }
shift
P=/data/ucrm/data/plugins
SNIP='$u=@json_decode((string)@file_get_contents($argv[1]),true); echo rtrim((string)($u["pluginDataDir"]??""),"/");'
ok=""
if [ -z "$stdin" ] && [ -z "$user" ]; then
  case "$#|$1|${2:-}|${3:-}" in
    "2|cat|$P/dishnet-hybrid-sudan/.deployed-commit|") ok=1 ;;
    "4|php|-r|$SNIP") [ "$4" = "$P/dishnet-hybrid-sudan/ucrm.json" ] && ok=1 ;;
    "3|test|-f|"*) case "$3" in /data/*/plugin.sqlite3) ok=1 ;; esac ;;
    "4|stat|-c|%u:%g") case "$4" in /data/*/plugin.sqlite3) ok=1 ;; esac ;;
  esac
elif [ -n "$stdin" ] && [ -n "$user" ]; then
  if [ "$1 $2 $3 $4 $5 $6 $7 $8" = "php -d display_errors=stderr -d log_errors=0 -d opcache.enable_cli=0 --" ] && [ $# -ge 10 ] && [ $# -le 11 ]; then
    case "${11:---adjustment-id=0}" in --adjustment-id=[0-9]*) ok=1 ;; esac
  fi
fi
[ -n "$ok" ] || no exec ${user:+-u $user} ${stdin:+-i} "$@"
args=(); for a in "$@"; do case "$a" in /data/*) args+=("$MNT/${a#/data/}") ;; *) args+=("$a") ;; esac; done
echo "exec ${user:-root} ${stdin:-nostdin} ${args[*]}" | cut -c1-400 >> "$T"
pre=(); [ -z "$user" ] || pre=(setpriv --reuid="${user%%:*}" --regid="${user##*:}" --clear-groups)
[ -n "$stdin" ] || exec "${pre[@]}" env -i PATH=/usr/local/bin:/usr/bin:/bin HOME=/tmp "${args[@]}" </dev/null
exec "${pre[@]}" env -i PATH=/usr/local/bin:/usr/bin:/bin HOME=/tmp "${args[@]}"
