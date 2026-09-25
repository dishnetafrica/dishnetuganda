#!/bin/bash
# journey.sh <scenario> — the operator's first sign-in through the fake Traefik,
# asserted step by step, with the one-time password read from the scenario's
# terminal capture or its 0600 file (never from the log).
. "$(dirname "$0")/guard.sh"; . "$HSIM_HOME/lib.sh"
N=${1:?scenario}; RX='[a-z2-9]{6}(-[a-z2-9]{6}){3}'; J=${HSIM:?}/state/jar; B=${HSIM:?}/state/body; U=https://portal-staging.dishnetuganda.com/api/v1/admin
PW=$( { cat "$HSIM/$N.tty" 2>/dev/null; cat /root/dnb-staging-evidence/*.password.txt 2>/dev/null; } | grep -aoE "$RX" | head -1)
check "journey: the one-time password reached the operator (terminal or 0600 file)" eq "$([ -n "$PW" ] && echo y)" y
check "journey: the one-time password is NOT in the script's log" hasnt_f "$HSIM/$N.log" "${PW:-@none@}"
rm -f "${J:?}"; C() { via -H Origin:https://portal-staging.dishnetuganda.com -H Content-Type:application/json -c "$J" -b "$J" -o "$B" -w '%{http_code}' "$@"; }
check "journey: sign-in with the one-time password → 200, second factor pending" eq "$(C -d "{\"username\":\"dishnet-admin\",\"password\":\"$PW\"}" "$U/session")|$(grep -o '"pending":true' "$B")" '200|"pending":true'
check "journey: the session cookie was stored by the client (Secure, over https)" eq "$(grep -c 'dnb_staff_session' "$J")" 1
check "journey: the estate before the second factor → 403 second_factor_required" eq "$(C "$U/routers")|$(grep -o second_factor_required "$B")" '403|second_factor_required'
C -X POST -d '{}' "$U/session/totp" >/dev/null; KEY=$(python3 -c "import json,sys;print(json.load(open(sys.argv[1]))['enrolment']['key'])" "$B" 2>/dev/null || true)
check "journey: authenticator enrolment issues a setup key" eq "$([ -n "$KEY" ] && echo y)" y
check "journey: confirming the authenticator code → 200" eq "$(C -d "{\"code\":\"$(python3 "$HSIM_HOME/totp.py" "$KEY")\"}" "$U/session/totp/confirm")" 200
check "journey: the estate after the second factor → 200 with the 5 simulated routers" eq "$(C "$U/routers")|$(python3 -c "import json,sys;print(len(json.load(open(sys.argv[1]))['router']))" "$B")" '200|5'
NEWPW="$(openssl rand -hex 12)-Harness"
check "journey: password change → 200" eq "$(C -d "{\"current\":\"$PW\",\"replacement\":\"$NEWPW\"}" "$U/session/password")" 200
cp "$J" "$J.old"
check "journey: sign-out → 204" eq "$(C -X DELETE "$U/session")" 204
check "journey: the signed-out cookie replayed → 401" eq "$(via -b "$J.old" -o /dev/null -w '%{http_code}' "$U/routers")" 401
check "journey: the one-time password no longer signs in → 401" eq "$(C -d "{\"username\":\"dishnet-admin\",\"password\":\"$PW\"}" "$U/session")" 401
check "journey: the new password without a code (authenticator enrolled) → 401" eq "$(C -d "{\"username\":\"dishnet-admin\",\"password\":\"$NEWPW\"}" "$U/session")" 401
check "journey: the new password + the next code → 200, no second factor pending" eq "$(C -d "{\"username\":\"dishnet-admin\",\"password\":\"$NEWPW\",\"code\":\"$(python3 "$HSIM_HOME/totp.py" "$KEY" 1)\"}" "$U/session")|$(grep -o '"pending":false' "$B")" '200|"pending":false'
check "journey: audit trail — created by cli:root, then login ×2, totp_enrolled, password_changed, logout, all actor_kind staff" \
  eq "$(sql "select string_agg(actor||'/'||actor_kind||'/'||action||'/'||n, ',' order by f) from (select actor, actor_kind, action, count(*) n, min(at) f from mt_audit_log where actor in ('dishnet-admin','cli:root') group by 1,2,3) x")" \
  'cli:root/staff/staff.created/1,dishnet-admin/staff/staff.login/2,dishnet-admin/staff/staff.totp_enrolled/1,dishnet-admin/staff/staff.password_changed/1,dishnet-admin/staff/staff.logout/1'
check "journey: no password or key in any audit detail" eq "$(sql "select count(*) from mt_audit_log where detail::text like '%$PW%' or detail::text like '%$NEWPW%' or detail::text like '%$KEY%'")" 0
