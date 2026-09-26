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
#
# FOUND 26 Sep 21:01 (docs/38 §7.2): on UISP 3.0.159 the address uCRM uses is UISP's own — Settings → General holds
# the hostname, already crm.dishnetuganda.com, and no port. The port is the one UISP was installed with. So the
# setting route above is not available here, and the two :8443 links in the invoice PDF turned out to be the PAY NOW
# box of the Uganda invoice template, which prints uCRM's online-payment link. The fix moved to that template:
#
#   bash scripts/dnb-c2-check.sh --links 2>&1 | tee /root/dnb-c2/links-$(date -u +%Y%m%dT%H%M%SZ).log
#
# --links is READ-ONLY too. For the controlled customer's newest unpaid invoice (CLIENT_ID, default 1) it shows the
# links inside its PDF with every token masked, the template uCRM used, what uCRM's own payment page answers on the
# public address and which payment options it names, the ports UISP was installed with (numeric values only; the
# settings file also holds secrets, never read out), and whether DishNet's own pay page answers. It writes nothing.
set -uo pipefail
umask 077

MODE="${1:-}"
case "$MODE" in --before|--after|--links) ;; *) echo "usage: $0 --before | --after | --links" >&2; exit 64 ;; esac
CONTAINER="${UCRM_CONTAINER:-ucrm}"
PLUGIN="dishnet-hybrid-sudan"
IN_CONTAINER="/data/ucrm/data/plugins/$PLUGIN"
STATE_DIR="${STATE_DIR:-/root/dnb-c2}"; STATE="$STATE_DIR/state.env"
POLL_SECONDS="${POLL_SECONDS:-20}"; POLLS="${POLLS:-18}"
PUBLIC_HOST="${PUBLIC_HOST:-crm.dishnetuganda.com}"
REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
HEALTH_SCRIPT="${HEALTH_SCRIPT:-$REPO/scripts/verify-uisp-health.sh}"
CLIENT_ID="${CLIENT_ID:-1}"
UNMS_CONF="${UNMS_CONF:-/home/unms/app/unms.conf}"
# DishNet's own pay page, where the template's PAY NOW is to lead: the Uganda profile's pay_url.
PAY_PAGE="${PAY_PAGE:-$(sed -nE 's/^[[:space:]]*"pay_url":[[:space:]]*"([^"]+)".*/\1/p' "$REPO/dishnet-hybrid-sudan/profiles/uganda.json" 2>/dev/null | head -1)}"
PAY_PAGE="${PAY_PAGE:-https://dishnetuganda.com/pay}"

PASS=0; FAIL=0; NOTE=0
ok()   { PASS=$((PASS+1)); printf '  ok    %s\n' "$*"; }
bad()  { FAIL=$((FAIL+1)); printf '  FAIL  %s\n' "$*"; }
note() { NOTE=$((NOTE+1)); printf '  note  %s\n' "$*"; }
hdr()  { printf '\n== %s ==\n' "$*"; }
stop() { printf '\n  STOP: %s\n  Nothing was changed. Send the log file.\n' "$*"; exit 1; }

[ "$MODE" = "--links" ] || { mkdir -p "$STATE_DIR" && chmod 700 "$STATE_DIR"; } || stop "cannot create $STATE_DIR"

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

# 2b — --links: the READ-ONLY report in scripts/lib/c2_invoice_links.php, piped into the container and run there with
# the plugin's directory as the working directory. It masks every token itself. Its "@@" lines are for this script
# and are never printed: "@@FETCH <url>" is uCRM's payment page without the port, opened below from THIS host, the
# way a customer reaches it (the container may resolve the public name to UISP's own nginx); "@@ …" is the summary.
links_report() {
  docker exec -i -w "$IN_CONTAINER" "$CONTAINER" php -d display_errors=0 -- "$CLIENT_ID" \
    < "$REPO/scripts/lib/c2_invoice_links.php" 2>/dev/null
}
# Payment options a page might name, matched as whole words ("endpoint" is not DPO, "table-striped" is not Stripe).
C2_OPTIONS='PayPal|Stripe|Authorize\.Net|IPpay|MercadoPago|Braintree|PayU|Paystack|Flutterwave|Pesapal|DPO|Airtel Money|MTN MoMo|Mobile Money'
hostport() { printf '%s' "$1" | sed -nE 's#^(https?)://([^/:?]+)(:([0-9]+))?.*#\2 \1 \4#p' | awk '{ p = $3; if (p == "") p = ($2 == "https") ? 443 : 80; print $1 ":" p }'; }

# 3 — routers on :8443: established TCP connections to UISP's port from PUBLIC addresses (a browser through Traefik
# arrives from a private docker address and is not counted). Counted on the host and inside the network namespace of
# the container that publishes 8443, so the count holds whether Docker forwards with its proxy or with NAT.
# A zero is a negative result, so it carries a positive control: the script holds ONE test connection of its own to
# 127.0.0.1:8443 open while it counts. If the count sees that (or any) connection on the port but no public one, no
# router is connected — measured. If it sees nothing at all, the count is blind on this server and says so.
# Prints "<public connections> <distinct public addresses> <all connections seen>"; addresses are never printed.
count_devices() (
  local peers="" line name ports inner pid
  exec 2>/dev/null                                          # this subshell only: a refused probe must not print
  exec 3<>/dev/tcp/127.0.0.1/8443 || true                   # the control: held open until this subshell ends
  sleep 0.2
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
  local all; all="$(printf '%s\n' "$peers" | grep -c -v -E '^[[:space:]]*$')"
  printf '%s\n' "$peers" \
    | sed -E 's/^\[(.*)\]:[0-9]+$/\1/; s/^([0-9.]+):[0-9]+$/\1/; s/^::ffff://' \
    | grep -v -E '^$|^127\.|^10\.|^172\.(1[6-9]|2[0-9]|3[01])\.|^192\.168\.|^169\.254\.|^::1$|^f[cd][0-9a-f]*:|^fe80:' \
    | awk -v all="$all" '{ n++; s[$1] = 1 } END { m = 0; for (k in s) m++; printf "%d %d %d\n", n + 0, m, all + 0 }'
)

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

if [ "$MODE" = "--links" ]; then
  hdr "Where uCRM's :8443 comes from (read-only)"
  echo "  uCRM's address, as it tells plugins   $U_CRM"
  if [ -r "$UNMS_CONF" ]; then
    # Keys ending in PORT with a number for a value, nothing else: the same file holds secrets.
    P="$(grep -E '^[A-Z0-9_]*PORT="?[0-9]*"?$' "$UNMS_CONF" 2>/dev/null | tr -d '"' | tr '\n' ' ')"
    echo "  the ports UISP was installed with     ${P:-none recorded in $UNMS_CONF}"
  else
    note "UISP's settings file $UNMS_CONF could not be read — the ports it was installed with are not shown"
  fi
  OUT="$(links_report)"
  [ -n "$OUT" ] || stop "the invoice-links report produced nothing (docker exec of scripts/lib/c2_invoice_links.php)"
  printf '%s\n' "$OUT" | grep -v '^@@'
  read -r _ L8443 LOTHER LSTATE LTPL <<<"$(printf '%s\n' "$OUT" | grep '^@@ ' | tail -1)"
  L8443="${L8443:-0}"; LOTHER="${LOTHER:-0}"; LSTATE="${LSTATE:-unread}"; LTPL="${LTPL:-unknown}"
  FETCH="$(printf '%s\n' "$OUT" | sed -n 's/^@@FETCH //p' | head -1)"; LNAMES="-"; LCODE="-"
  if [ -n "$FETCH" ]; then
    hdr "uCRM's own payment page, opened on the public address without :8443 (read-only)"
    u="$FETCH"; hop=0; body=""
    while [ "$hop" -lt 4 ]; do
      hop=$((hop+1))
      r="$(curl -s --max-time 20 -w '\n@@%{http_code} %{redirect_url}' "$u" 2>/dev/null)"; st="${r##*@@}"; b="${r%@@*}"
      LCODE="${st%% *}"; loc="${st#* }"; [ "$loc" = "$st" ] && loc=""
      printf '  %-26s %s → %s\n' "hop $hop" "$(hostport "$u")" "${LCODE:-000}"
      case "$LCODE" in 30[1278]) [ -n "$loc" ] && { u="$loc"; continue; } ;; esac
      body="$b"; break
    done
    if [ "$LCODE" = "200" ]; then
      LNAMES="$(printf '%s' "$body" | grep -oiwE "$C2_OPTIONS" | sort -fu | paste -sd, - | sed 's/,/, /g')"
      if [ -n "$LNAMES" ]; then printf '  %-26s %s\n' "payment options it names" "$LNAMES"
      else LNAMES="none"; printf '  %-26s %s\n' "payment options it names" "none of ${C2_OPTIONS//\\/}" | sed 's/|/, /g'; fi
      printf '  %-26s %s form(s) · %s reference(s) to :8443 · %s bytes\n' "the page, in figures" \
        "$(printf '%s' "$body" | grep -oi '<form' | wc -l | tr -d ' ')" "$(printf '%s' "$body" | grep -o ':8443' | wc -l | tr -d ' ')" "${#body}"
    fi
  fi

  hdr "DishNet's own pay page, where PAY NOW is to lead (read-only)"
  PB="$(curl -sL --max-time 20 -w '\n@@%{http_code}' "$PAY_PAGE" 2>/dev/null)"; PCODE="${PB##*@@}"; PB="${PB%@@*}"
  PAIRTEL="$(printf '%s' "$PB" | grep -c 'Airtel Money' || true)"
  if [ "$PCODE" = "200" ] && [ "${PAIRTEL:-0}" -gt 0 ]; then ok "$PAY_PAGE answers 200 and tells the customer how to pay with Airtel Money"
  else bad "$PAY_PAGE → ${PCODE:-000}$([ "$PCODE" = "200" ] && echo ", without the Airtel Money instructions") — do not point PAY NOW at it until it answers"; fi

  echo
  if [ "$LSTATE" != "ok" ]; then
    case "$LSTATE" in
      noinvoice) echo "  RESULT   nothing to read: client #$CLIENT_ID has no unpaid invoice, and the PAY NOW box is drawn on unpaid" ;
                 echo "           invoices only. Run again with CLIENT_ID=<a client with an unpaid invoice>." ;;
      nopdf)     echo "  RESULT   uCRM served no PDF for the invoice, so its links could not be read." ;;
      noconfig)  echo "  RESULT   the plugin has no uCRM connection here, so nothing could be read." ;;
      *)         echo "  RESULT   the report ended early (${LSTATE}); nothing can be concluded from this run." ;;
    esac
  elif [ "$L8443" -gt 0 ] && [ "$LTPL" = "gone" ]; then
    # The template this invoice was made with has been removed or replaced (26 Sep 21:57: "Invoice Ugadna" #1000
    # left uCRM's list after the Uganda template was uploaded). Editing it is no fix, and this PDF cannot change.
    echo "  RESULT   the $L8443 link(s) on :8443 inside this invoice come from the template it was made with, which is no"
    echo "           longer in uCRM's list. uCRM keeps the PDF it made for an invoice, so this one cannot show a new"
    echo "           template. Nothing to change here: make sure the new template is the one new invoices use, look"
    echo "           at it in uCRM's preview, and run --links again after the next invoice for this client."
  elif [ "$L8443" -gt 0 ]; then
    echo "  RESULT   the $L8443 link(s) on :8443 inside this invoice are uCRM's own online payment page, drawn by the"
    echo "           invoice template's PAY NOW box. uCRM builds that link on the port UISP was installed with."
    case "$LNAMES" in
      none)
        echo "           uCRM's payment page names none of those payment options. Unless customers pay there some"
        echo "           other way, pointing PAY NOW at DishNet's pay page takes nothing away from them."
        if [ "$FAIL" = "0" ]; then cat <<FIX

  THE FIX — BY HAND, IN uCRM'S TEMPLATE EDITOR. THIS SCRIPT CHANGES NOTHING.
   1. uCRM → System → Customization → Invoice templates. Open the template named above for this invoice.
   2. Clone it first. The copy is the rollback: if anything looks wrong, make the copy the default again.
   3. If that template prints another country's details (Juba, +211, "Amount Due (USD)"), replace its whole text
      with the Uganda template, which already carries this fix — the CSS stays as it is:
        dishnet-hybrid-sudan/ucrm_pdf_templates/invoice_uganda/template.html.twig   (docs/38 §7.2)
      Otherwise find  invoice.onlinePaymentLink  — it appears three times — and change only those:
        {% if invoice.onlinePaymentLink and not is_paid %}   becomes   {% if not is_paid %}
        href="{{ invoice.onlinePaymentLink }}"               becomes   href="$PAY_PAGE"
        the other {{ invoice.onlinePaymentLink }}            becomes   ${PAY_PAGE#https://}
   4. Save. uCRM checks the template when it saves; if it refuses, change nothing else and send this log.
   5. Run --links again and send both logs. uCRM keeps the PDF it made for an invoice, so one made before the
      change may still show the old link; the next invoice uCRM makes shows the new one.
FIX
        fi ;;
      -)
        echo "           uCRM's payment page could not be read (status $LCODE). DishNet's pay page is still the place for"
        echo "           PAY NOW to lead; send this log before changing the template." ;;
      *)
        echo "           uCRM's payment page names: $LNAMES. Customers may be able to pay there — send this log and"
        echo "           decide before changing the template (docs/38 §7.2)." ;;
    esac
  elif printf '%s\n' "$OUT" | grep -qF "× $PAY_PAGE"; then
    echo "  RESULT   C-2 is done for this invoice: PAY NOW leads to DishNet's pay page and no link carries :8443."
  elif [ "$LOTHER" -gt 0 ]; then
    echo "  RESULT   no link on :8443 inside this invoice's PDF; its links are listed above."
  else
    echo "  RESULT   this invoice's PDF carries no link at all — nothing on :8443, and no PAY NOW link either."
  fi
  echo "  Nothing was changed. Send this LOG FILE back (not a copy of the terminal)."
  [ "$FAIL" = "0" ] && exit 0 || exit 1
fi

PDF="$(pdf_hosts)"; [ -n "$PDF" ] || PDF="the PDF could not be read"
read -r DEV_N DEV_M DEV_T <<<"$(count_devices)"; DEV_N="${DEV_N:-0}"; DEV_M="${DEV_M:-0}"; DEV_T="${DEV_T:-0}"

if [ "$MODE" = "--before" ]; then
  hdr "Before the change (read-only)"
  echo "  uCRM's address, as it tells plugins   $U_CRM"
  echo "  the plugin's address, as uCRM says    $U_PLUGIN"
  echo "  the latest invoice's PDF              $PDF"
  echo "  routers connected on :8443            $DEV_N connection(s) from $DEV_M address(es) · $DEV_T connection(s) seen on the port in all"
  case "$U_CRM" in *:8443*) note "uCRM's own address carries :8443 — this is what C-2 changes" ;; *) note "uCRM's own address already carries no :8443 — C-2 may be unnecessary; send this log before changing anything" ;; esac
  note "found 26 Sep 21:01: on UISP 3.0.159 the fields below do not exist (hostname only, no port). The invoice links are fixed in the invoice template instead: run --links (docs/38 §7.2)"
  if [ "$DEV_N" -gt 0 ]; then :
  elif [ "$DEV_T" -gt 0 ]; then ok "no router is connected to UISP on :8443 right now — measured: the count sees this port ($DEV_T connection(s) from this server itself, its own test connection included), so C-2 has no connected router to disturb"
  else note "nothing at all was seen on :8443, not even this script's own test connection — the count is blind on this server; after the change, check UISP's own device list"; fi
  checks_around
  {
    printf 'BEFORE_TS=%q\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)"
    printf 'BEFORE_UCRM=%q\nBEFORE_PLUGIN=%q\nBEFORE_PDF=%q\n' "$U_CRM" "$U_PLUGIN" "$PDF"
    printf 'BEFORE_DEV_N=%q\nBEFORE_DEV_M=%q\nBEFORE_DEV_T=%q\n' "$DEV_N" "$DEV_M" "$DEV_T"
  } > "$STATE"
  chmod 600 "$STATE"
  ok "before-state recorded in $STATE (counts, addresses, hosts; no secret)"
  cat <<EOF

  THIS SCRIPT CHANGES NOTHING. Between --before and --after, YOU make the change, by hand, in uCRM's web page:
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
# A --after moments after --before, with uCRM's address exactly as recorded, is most likely a run made before
# anyone changed anything (seen twice on 26 Sep, 17 seconds apart both times) — or uCRM has not rewritten its
# file yet. The script cannot tell which, so it says both, and never implies that it made the change itself.
SINCE=""; B_EPOCH="$(date -u -d "${BEFORE_TS:-}" +%s 2>/dev/null || true)"
[ -n "$B_EPOCH" ] && SINCE=$(( $(date -u +%s) - B_EPOCH ))
SOON_UNCHANGED=0
[ "$U_CRM" = "${BEFORE_UCRM:-}" ] && [ -n "$SINCE" ] && [ "$SINCE" -lt 180 ] && SOON_UNCHANGED=1
echo "  uCRM's address, as it tells plugins   before: $BEFORE_UCRM"
echo "                                        now:    $U_CRM"
echo "  the latest invoice's PDF              before: $BEFORE_PDF"
echo "                                        now:    $PDF"
ADDR_DONE=0; PDF_DONE=0
case "$U_CRM" in
  *:8443*) if [ "$SOON_UNCHANGED" = "1" ]; then note "uCRM still tells plugins an address with :8443 — exactly what --before recorded ${SINCE} seconds ago (see RESULT)"
           else note "uCRM still tells plugins an address with :8443 — uCRM rewrites that file itself; run --after again in a few minutes. If it never changes, the setting did not take"; fi ;;
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
    read -r N M T <<<"$(count_devices)"; N="${N:-0}"; M="${M:-0}"
    printf '  …     %s  %s connection(s) from %s address(es)\n' "$(date -u +%H:%M:%S)" "$N" "$M"
    if [ "$N" -ge "$FLOOR" ] && [ $(( $(date +%s) - START )) -ge "$(( POLL_SECONDS * 3 ))" ]; then DEV_OK=1; break; fi
    i=$((i+1)); [ "$i" -lt "$POLLS" ] && sleep "$POLL_SECONDS"
  done
  if [ "$DEV_OK" = "1" ]; then ok "the routers are still connected on :8443 ($N connection(s), floor $FLOOR)"
  else bad "the routers did NOT come back on :8443 (last count $N, floor $FLOOR) — PUT THE TWO VALUES BACK NOW, in the same uCRM screen, then run --after again"; fi
elif [ "${BEFORE_DEV_T:-0}" -gt 0 ]; then
  ok "no router was connected on :8443 before the change either (measured, with the script's own test connection as control) — there is none to lose; UISP's answer on :8443 is checked below"
else
  note "the router count was blind on this server before the change, so this script cannot judge the routers — open UISP's device list and confirm they are still connected"
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
elif [ "$SOON_UNCHANGED" = "1" ]; then
  echo "  RESULT   nothing broke. uCRM's address is still exactly what --before recorded ${SINCE} seconds ago."
  echo "           This script only CHECKS — it changes nothing."
  echo "           · Not changed in uCRM yet? Make the change by hand in uCRM's settings page (steps 1–4 that --before"
  echo "             printed), then run --after."
  echo "           · Already saved it there? Wait a few minutes, then run --after again."
else echo "  RESULT   nothing broke, but uCRM has not taken the new address yet — run --after again in a few minutes."; fi
echo "  Then check the device connection string you copied is unchanged. Send this LOG FILE back (not a copy of the terminal)."
exit 0
