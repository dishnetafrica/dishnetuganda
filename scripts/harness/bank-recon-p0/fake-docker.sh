#!/usr/bin/env bash
# fake-docker.sh — the docker the P0 wrapper sees in the rehearsal. Strict: only the calls bank-recon-p0.sh makes; anything
# else fails loudly, so a new call cannot pass unseen. The container's /data is $FAKE_MNT: every argument beginning /data/
# is rewritten to it. -u is honoured with setpriv, as the container's own user would be. Each exec is traced to
# $FAKE_TRACE as "exec <user> <stdin?> <args>".
MNT="${FAKE_MNT:?FAKE_MNT}"
case "$1" in
  inspect)
    shift; fmt=""; name=""
    while [ $# -gt 0 ]; do case "$1" in -f|--format) fmt="$2"; shift 2 ;; *) name="$1"; shift ;; esac; done
    [ "$name" = "ucrm" ] || { echo "Error: No such object: $name" >&2; exit 1; }
    case "$fmt" in
      '{{.State.Running}}') echo "${FAKE_RUNNING:-true}" ;;
      '{{range .Mounts}}{{if eq .Destination "/data"}}{{.Source}}{{end}}{{end}}') echo "$MNT" ;;
      *) echo "fake docker: unsupported inspect format: $fmt" >&2; exit 99 ;;
    esac
    exit 0 ;;
  exec) shift ;;
  *) echo "fake docker: $1 is not supported" >&2; exit 99 ;;
esac
user=""; stdin=""
while [ $# -gt 0 ]; do case "$1" in
  -i) stdin="stdin"; shift ;;
  -u) user="$2"; shift 2 ;;
  -*) echo "fake docker: exec $1 is not supported" >&2; exit 99 ;;
  *) break ;;
esac; done
[ "$1" = "ucrm" ] || { echo "Error: No such container: $1" >&2; exit 1; }
[ "${FAKE_RUNNING:-true}" = "true" ] || { echo "Error: container $1 is not running" >&2; exit 1; }
shift
args=(); for a in "$@"; do case "$a" in /data/*) args+=("$MNT/${a#/data/}") ;; *) args+=("$a") ;; esac; done
echo "exec ${user:-root} ${stdin:-nostdin} ${args[*]}" | cut -c1-400 >> "${FAKE_TRACE:-/dev/null}"
pre=(); [ -z "$user" ] || pre=(setpriv --reuid="${user%%:*}" --regid="${user##*:}" --clear-groups)
# ucrm.json names /data/... paths: the in-container php -r that reads it prints them, and the wrapper passes them back
# as arguments, which the rewrite above maps again. The environment is the container's: nothing of the host's leaks in.
[ -n "$stdin" ] || exec "${pre[@]}" env -i PATH=/usr/local/bin:/usr/bin:/bin HOME=/tmp "${args[@]}" </dev/null
exec "${pre[@]}" env -i PATH=/usr/local/bin:/usr/bin:/bin HOME=/tmp "${args[@]}"
