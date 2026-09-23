#!/bin/bash
# run.sh <name> [tty|notty] — run the REAL scripts/dnb-staging-staff-login.sh
# against the harness; its exit code is recorded as the log's last line, EXIT=<n>.
. "$(dirname "$0")/guard.sh"
# HSIM_SCRIPT points the harness at a deliberately broken copy (controls on the controls).
N=${1:?name}; MODE=${2:-notty}; SCRIPT=${HSIM_SCRIPT:-$REPO/scripts/dnb-staging-staff-login.sh}
rm -rf /root/dnb-staging-evidence
if [ "$MODE" = tty ]; then
  script -qec "{ sh $SCRIPT 2>&1; echo EXIT=\$?; } | tee ${HSIM:?}/${N:?}.log" "${HSIM:?}/${N:?}.tty" > /dev/null
else
  rm -f "${HSIM:?}/${N:?}.tty"; { sh "$SCRIPT" 2>&1; echo "EXIT=$?"; } > "${HSIM:?}/${N:?}.log" < /dev/null
fi
