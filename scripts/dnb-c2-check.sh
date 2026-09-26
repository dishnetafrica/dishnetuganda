#!/usr/bin/env bash
#
# dnb-c2-check.sh — READ-ONLY guard around change C-2 of docs/38: uCRM's own address.
#
# C-2 is done by hand, in uCRM's settings screen (docs/38 §7.2). This script changes nothing on the server. It
# measures, before and after, what the change is meant to fix and what it must not break:
#   1. the address uCRM gives plugins (ucrm.json: ucrmPublicUrl, pluginPublicUrl) — the change's intended effect
#   2. the links inside the latest invoice's PDF — what the change is for (docs/39 §7); hosts only, never content
#   3. the routers connected to UISP on :8443 — what must NOT change: devices keep :8443 (docs/05, docs/06)
#   4. UISP's own health (scripts/verify-uisp-health.sh), :8443 still answering, C-1 and the portal sign-in
#
# Run as root on the server, and send back each LOG FILE (never a copy of the terminal):
#
#   cd /opt/dishnet && git pull origin claude/study-this-jhe2eg && mkdir -p /root/dnb-c2 \
#     && bash scripts/dnb-c2-check.sh --before 2>&1 | tee /root/dnb-c2/before-$(date -u +%Y%m%dT%H%M%SZ).log
#   … change the two fields in uCRM, as the --before run prints …
#   bash scripts/dnb-c2-check.sh --after 2>&1 | tee /root/dnb-c2/after-$(date -u +%Y%m%dT%H%M%SZ).log
#
# --after watches the routers for up to six minutes. If their count stays below 80 % of what --before measured,
# it says so and tells you to put the two values you noted back, in the same screen. That is the rollback.
#
# Written: /root/dnb-c2/state.env (counts, URLs, hosts; no secret). The app key in ucrm.json is used inside the
# container to fetch the PDF and is never printed or written.
set -uo pipefail
umask 077

MODE="${1:-}"
case "$MODE" in --before|--after) ;; *) echo "usage: $0 --before | --after" >&2; exit 64 ;; esac
CONTAINER="${UCRM_CONTAINER:-ucrm}"
PLUGIN="dishnet-hybrid-sudan"
IN_CONTAINER="/data/ucrm/data/plugins/$PLUGIN"
STATE_DIR="${STATE_DIR:-/root/dnb-c2}"; STATE="$STATE_DIR/state.env"
POLL_SECONDS="${POLL_SECONDS:-20}"; POLLS="${POLLS:-18}"
PUBLIC_HOST="${PUBLIC_HOST:-crm.dishnetuganda.com}"
REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
HEALTH_SCRIPT="${HEALTH_SCRIPT:-$REPO/scripts/verify-uisp-health.sh}"

PASS=0; FAIL=0; NOTE=0
ok()   { PASS=$((PASS+1)); printf '  ok    %s\n' "$*"; }
bad()  { FAIL=$((FAIL+1)); printf '  FAIL  %s\n' "$*"; }
note() { NOTE=$((NOTE+1)); printf '  note  %s\n' "$*"; }
hdr()  { printf '\n== %s ==\n' "$*"; }
stop() { printf '\n  STOP: %s\n  Nothing was changed. Send the log file.\n' "$*"; exit 1; }

mkdir -p "$STATE_DIR" && chmod 700 "$STATE_DIR" || stop "cannot create $STATE_DIR"

# 1 — the address uCRM gives plugins. URLs only: the app key in the same file is never read out.
ucrm_urls() {
  docker exec "$CONTAINER" php -r '$u=@json_decode((string)@file_get_contents($argv[1]."/ucrm.json"),true)?:[]; echo ($u["ucrmPublicUrl"]??""),"|",($u["pluginPublicUrl"]??"");' "$IN_CONTAINER" 2>/dev/null
}

# 2 — the links inside the latest invoice's PDF: the invoice number and the hosts, never the document.
pdf_hosts() {
  docker exec -w "$IN_CONTAINER" "$CONTAINER" php -d display_errors=0 -r '
    require "lib/CrmApiClient.php";
    $crm = CrmApiClient::fromUcrm(getcwd(), []);
    if (!$crm->isConfigured()) { echo "uCRM is not configured for the plugin\n"; exit; }
    $l = $crm->get("invoices?limit=1&order=createdDate&direction=DESC");
    if (!is_array($l) || !$l) $l = $crm->get("invoices?limit=1");
    $inv = is_array($l) ? ($l[0] ?? null) : null;
    if (!is_array($inv)) { echo "no invoice to read\n"; exit; }
    $name = "invoice " . ($inv["number"] ?? $inv["id"]);
    $raw = $crm->getRawContent("invoices/" . (int)$inv["id"] . "/pdf");
    if (!$raw) { echo "$name · uCRM served no PDF\n"; exit; }
    $d = base64_decode($raw); $t = [$d];
    if (preg_match_all("/stream\r?\n(.*?)\r?\nendstream/s", $d, $m)) foreach ($m[1] as $s) {
      $u = @gzuncompress($s); if ($u === false) $u = @gzinflate($s); if ($u === false && strlen($s) > 2) $u = @gzinflate(substr($s, 2)); if ($u !== false) $t[] = $u; }
    $h = [];
    foreach ($t as $x) if (preg_match_all("#https?://([A-Za-z0-9.-]+(?::[0-9]+)?)#", $x, $mm)) foreach ($mm[1] as $hh) $h[$hh] = ($h[$hh] ?? 0) + 1;
    ksort($h); $o = []; foreach ($h as $k => $v) $o[] = "$k x$v";
    echo "$name · ", ($o ? implode(", ", $o) : "no link inside the PDF"), "\n";' 2>/dev/null | head -1
}

# 3 — routers on :8443: established TCP connections to UISP's port from PUBLIC addresses (a browser through Traefik
# arrives from a private docker address and is not counted). Counted on the host and inside the network namespace of
# the container that publishes 8443, so the count holds whether Docker forwards with its proxy or with NAT. Prints
# "<connections> <distinct addresses>"; addresses are counted, never printed.
count_devices() {
  local peers="" line name ports inner pid
  if command -v ss >/dev/null 2>&1; then peers="$(ss -Htn state established '( sport = :8443 )' 2>/dev/null | awk '{print $4}')"; fi
  line="$(docker ps --format '{{.Names}}|{{.Ports}}' 2>/dev/null | grep -E ':8443->' | head -1)"
  if [ -n "$line" ] && command -v nsenter >/dev/null 2>&1; then
    name="${line%%|*}"; ports="${line#*|}"
    inner="$(printf '%s' "$ports" | sed -nE 's/.*:8443->([0-9]+)\/tcp.*/\1/p' | head -1)"
    pid="$(docker inspect -f '{{.State.Pid}}' "$name" 2>/dev/null)"
    if [ -n "$inner" ] && [ -n "$pid" ] && [ "$pid" != "0" ]; then
      peers="$peers
$(nsenter -t "$pid" -n ss -Htn state established "( sport = :$inner )" 2>/dev/null | awk '{print $4}')"
    fi
  fi
  printf '%s\n' "$peers" \
    | sed -E 's/^\[(.*)\]:[0-9]+$/\1/; s/^([0-9.]+):[0-9]+$/\1/; s/^::ffff://' \
    | grep -v -E '^$|^127\.|^10\.|^172\.(1[6-9]|2[0-9]|3[01])\.|^192\.168\.|^169\.254\.|^::1$|^f[cd][0-9a-f]*:|^fe80:' \
    | awk '{ n++; s[$1] = 1 } END { m = 0; for (k in s) m++; printf "%d %d\n", n + 0, m }'
}

# 4 — UISP's health, :8443, C-1 and the portal
http_code() { curl -s -o /dev/null --max-time 20 -w '%{http_code} %{redirect_url}' "$@" 2>/dev/null || echo "000 "; }
checks_around() {
  if [ -f "$HEALTH_SCRIPT" ]; then
    local out rc
    out="$(timeout 120 bash "$HEALTH_SCRIPT" 2>&1)"; rc=$?
    if [ "$rc" = "0" ]; then ok "UISP health check passes (scripts/verify-uisp-health.sh)"
    else bad "UISP health check reports a problem:"; printf '%s\n' "$out" | grep -E 'FAIL' | head -6 | sed 's/^/          /'; fi
  else note "scripts/verify-uisp-health.sh not found — UISP's health was not checked"; fi
  local r; r="$(http_code -k https://127.0.0.1:8443/)"
  case "${r%% *}" in 200|301|302|307|308) ok "UISP still answers on :8443 (${r%% *}) — the port devices use" ;; *) bad "UISP does not answer on :8443 (${r%% *}) — the port devices use" ;; esac
  r="$(http_code "https://$PUBLIC_HOST/crm")"
  [ "${r%% *}" = "302" ] && [ "${r#* }" = "https://$PUBLIC_HOST/crm/" ] && ok "C-1 holds: the bare /crm → 302 → https://$PUBLIC_HOST/crm/" || note "the bare /crm → $r (C-1 expected 302 → https://$PUBLIC_HOST/crm/)"
  r="$(http_code "https://$PUBLIC_HOST/crm/login")"
  [ "${r%% *}" = "200" ] && ok "uCRM's sign-in page answers 200 on the public address" || bad "uCRM's sign-in page on the public address → ${r%% *}"
  r="$(http_code "https://$PUBLIC_HOST/crm/_plugins/$PLUGIN/public.php?page=customer_login")"
  [ "${r%% *}" = "200" ] && ok "the customer portal's sign-in answers 200" || bad "the customer portal's sign-in → ${r%% *}"
}

hdr "C-2 guard $MODE — $(date -u +%Y-%m-%dT%H:%M:%SZ) — $(hostname)"
docker exec "$CONTAINER" true 2>/dev/null || stop "container '$CONTAINER' is not reachable with docker exec"
URLS="$(ucrm_urls)"; U_CRM="${URLS%%|*}"; U_PLUGIN="${URLS#*|}"
[ -n "$U_CRM" ] || stop "could not read the address uCRM gives plugins (ucrm.json)"
PDF="$(pdf_hosts)"; [ -n "$PDF" ] || PDF="the PDF could not be read"
read -r DEV_N DEV_M <<<"$(count_devices)"; DEV_N="${DEV_N:-0}"; DEV_M="${DEV_M:-0}"

if [ "$MODE" = "--before" ]; then
  hdr "Before the change (read-only)"
  echo "  uCRM's address, as it tells plugins   $U_CRM"
  echo "  the plugin's address, as uCRM says    $U_PLUGIN"
  echo "  the latest invoice's PDF              $PDF"
  echo "  routers connected on :8443            $DEV_N connection(s) from $DEV_M address(es)"
  case "$U_CRM" in *:8443*) note "uCRM's own address carries :8443 — this is what C-2 changes" ;; *) note "uCRM's own address already carries no :8443 — C-2 may be unnecessary; send this log before changing anything" ;; esac
  [ "$DEV_N" -gt 0 ] || note "no router connection was counted on :8443 — after the change, check UISP's own device list instead of relying on this count"
  checks_around
  {
    printf 'BEFORE_TS=%q\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)"
    printf 'BEFORE_UCRM=%q\nBEFORE_PLUGIN=%q\nBEFORE_PDF=%q\n' "$U_CRM" "$U_PLUGIN" "$PDF"
    printf 'BEFORE_DEV_N=%q\nBEFORE_DEV_M=%q\n' "$DEV_N" "$DEV_M"
  } > "$STATE"
  chmod 600 "$STATE"
  ok "before-state recorded in $STATE (counts, addresses, hosts; no secret)"
  cat <<EOF

  NOW, BY HAND IN uCRM — this script changes nothing:
   1. Open uCRM's settings screen that holds "Server domain name" and "Server port" (Settings → System →
      Application). If those fields are greyed out, say they are managed by UISP, or are not there: STOP.
      Change nothing and send this log, with a sentence on what the screen shows.
   2. Write down both values exactly as they are now (a photo is fine). They are the rollback.
   3. Copy UISP's device connection string (the "UISP key" that UISP shows when you add a device). It carries
      :8443 and must stay exactly the same. If the screen says the fields in step 1 also change it: STOP.
   4. Set "Server domain name" to $PUBLIC_HOST and "Server port" to 443. Save.
   5. Within two minutes run:
        bash scripts/dnb-c2-check.sh --after 2>&1 | tee /root/dnb-c2/after-\$(date -u +%Y%m%dT%H%M%SZ).log
      It watches the routers for up to six minutes and tells you if the two values must go back.
   6. Afterwards, check that the device connection string from step 3 is unchanged.

EOF
  echo "  checks   $PASS ok, $FAIL failed, $NOTE notes"
  [ "$FAIL" = "0" ] || { echo; echo "  Something is already wrong BEFORE the change. Do not change the setting; send this log."; exit 1; }
  echo "  Send this LOG FILE back (not a copy of the terminal)."
  exit 0
fi

# ── --after ──────────────────────────────────────────────────────────────────────────────────────────────────
[ -f "$STATE" ] || stop "no before-state in $STATE — run --before first, before changing the setting"
# shellcheck disable=SC1090
. "$STATE"
hdr "After the change — compared with the before-state of ${BEFORE_TS:-?}"
echo "  uCRM's address, as it tells plugins   before: $BEFORE_UCRM"
echo "                                        now:    $U_CRM"
echo "  the latest invoice's PDF              before: $BEFORE_PDF"
echo "                                        now:    $PDF"
ADDR_DONE=0; PDF_DONE=0
case "$U_CRM" in
  *:8443*) note "uCRM still tells plugins an address with :8443 — uCRM rewrites that file itself; run --after again in a few minutes. If it never changes, the setting did not take" ;;
  *) ok "uCRM now tells plugins its address without :8443 ($U_CRM)"; ADDR_DONE=1 ;;
esac
case "$PDF" in
  *:8443*) note "the latest invoice's PDF still carries :8443 — uCRM may keep the PDF it generated when the invoice was issued; the next invoice issued will show whether new PDFs carry the new address" ;;
  *"could not"*|*"no PDF"*|*"no invoice"*|*"not configured"*) note "the PDF could not be checked: $PDF" ;;
  *) ok "the latest invoice's PDF carries no :8443 link"; PDF_DONE=1 ;;
esac

# The routers: they must not go away. Wait for a full minute before judging, then accept the first reading at or
# above the floor; if none reaches it within the window, the change is to be undone.
if [ "${BEFORE_DEV_N:-0}" -gt 0 ]; then
  if [ "$BEFORE_DEV_N" -ge 5 ]; then FLOOR=$(( (BEFORE_DEV_N * 8 + 9) / 10 )); else FLOOR=$(( BEFORE_DEV_N - 1 )); fi
  [ "$FLOOR" -ge 1 ] || FLOOR=1
  echo "  routers connected on :8443            before: $BEFORE_DEV_N connection(s) from $BEFORE_DEV_M address(es); the floor is $FLOOR"
  DEV_OK=0; i=0; START=$(date +%s)
  while [ "$i" -lt "$POLLS" ]; do
    read -r N M <<<"$(count_devices)"; N="${N:-0}"; M="${M:-0}"
    printf '  …     %s  %s connection(s) from %s address(es)\n' "$(date -u +%H:%M:%S)" "$N" "$M"
    if [ "$N" -ge "$FLOOR" ] && [ $(( $(date +%s) - START )) -ge "$(( POLL_SECONDS * 3 ))" ]; then DEV_OK=1; break; fi
    i=$((i+1)); [ "$i" -lt "$POLLS" ] && sleep "$POLL_SECONDS"
  done
  if [ "$DEV_OK" = "1" ]; then ok "the routers are still connected on :8443 ($N connection(s), floor $FLOOR)"
  else bad "the routers did NOT come back on :8443 (last count $N, floor $FLOOR) — PUT THE TWO VALUES BACK NOW, in the same uCRM screen, then run --after again"; fi
else
  note "no router connection was counted before the change, so this script cannot judge the routers — open UISP's device list and confirm they are still connected"
fi
checks_around

echo
echo "  checks   $PASS ok, $FAIL failed, $NOTE notes"
if [ "$FAIL" != "0" ]; then
  echo "  RESULT   something the change must not break is broken. Put the two values you noted back in uCRM, then run --after again and send both logs."
  exit 1
fi
if [ "$ADDR_DONE" = "1" ] && [ "$PDF_DONE" = "1" ]; then echo "  RESULT   C-2 is done: uCRM's own address and the latest invoice's PDF carry no :8443, and the routers stayed connected."
elif [ "$ADDR_DONE" = "1" ]; then echo "  RESULT   uCRM's address changed and the routers stayed connected; the latest PDF still shows the old address (see the note)."
else echo "  RESULT   nothing broke, but uCRM has not taken the new address yet — run --after again in a few minutes."; fi
echo "  Then check the device connection string you copied is unchanged. Send this LOG FILE back (not a copy of the terminal)."
exit 0
