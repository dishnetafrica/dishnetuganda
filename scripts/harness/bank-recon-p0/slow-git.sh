#!/bin/bash -p
# slow-git.sh — rehearse-run.sh R27g only: put before the real git on PATH. When SLOW_GIT_FLAG is set, the runner's own
# `cat-file blob` (the only one passing --no-optional-locks) first touches that file and sleeps, so a signal can be sent
# while the runner is inside a git call whose output is redirected to a file. Every other call is the real git's.
case " $* " in
  *" --no-optional-locks "*" cat-file blob "*) [ -n "${SLOW_GIT_FLAG:-}" ] && { touch "$SLOW_GIT_FLAG"; sleep 30; } ;;
esac
exec "${REAL_GIT:?REAL_GIT}" "$@"
