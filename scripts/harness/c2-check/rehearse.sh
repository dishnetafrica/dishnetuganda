#!/usr/bin/env bash
# Rehearse scripts/dnb-c2-check.sh (the READ-ONLY guard around change C-2) against stubs: docker, ss, nsenter,
# curl and the UISP health script are replaced on PATH, so every verdict path runs here — including the one that
# tells the operator to put the uCRM values back. The ucrm.json in the fake plugin carries an app key; no run may
# print it. Refuses to run where the real server could be (a live ucrm container on this machine).
set -u
R="$(cd "$(dirname "$0")/../../.." && pwd)"; SCRIPT="${SCRIPT:-$R/scripts/dnb-c2-check.sh}"
if command -v docker >/dev/null 2>&1 && docker ps --format '{{.Names}}' 2>/dev/null | grep -qx ucrm; then echo "refusing: a real ucrm container runs here"; exit 2; fi
SB="$(mktemp -d)"; trap 'rm -rf "$SB"' EXIT
F="$SB/fake"; mkdir -p "$F/plugin" "$SB/bin" "$SB/state"
PASS=0; FAILN=0
check() { if [ "$1" = "$2" ]; then PASS=$((PASS+1)); echo "  ok   $3"; else FAILN=$((FAILN+1)); echo "  FAIL $3 (got '$1', want '$2')"; fi; }
has()   { if printf '%s' "$1" | grep -qF -- "$2"; then PASS=$((PASS+1)); echo "  ok   $3"; else FAILN=$((FAILN+1)); echo "  FAIL $3 (missing '$2')"; fi; }
hasnt() { if printf '%s' "$1" | grep -qF -- "$2"; then FAILN=$((FAILN+1)); echo "  FAIL $3 (found '$2')"; else PASS=$((PASS+1)); echo "  ok   $3"; fi; }

# ── stubs ──
cat > "$SB/bin/docker" <<'STUB'
#!/usr/bin/env bash
F="$FAKE"
case "$1" in
  exec)
    shift; W=0; I=0
    while [ $# -gt 0 ]; do case "$1" in -w) W=1; shift 2;; -i) I=1; shift;; *) break;; esac; done
    shift   # the container
    if [ "$1" = "true" ]; then exit 0; fi
    # --links: the REAL scripts/lib/c2_invoice_links.php arrives on stdin; it runs against the fake plugin directory,
    # whose lib/CrmApiClient.php is a fake uCRM. It opens no page itself.
    if [ "$I" = "1" ]; then shift; cd "$F/plugin" && exec php "$@"; fi
    if [ "$W" = "1" ]; then cat "$F/pdf_line"; exit 0; fi       # the PDF scan needs uCRM: its answer is canned
    # the URL reader: the REAL php code, against the fake plugin directory
    shift; code=""; args=()
    while [ $# -gt 0 ]; do case "$1" in -r) code="$2"; shift 2;; -d) shift 2;; *) args+=("${1/\/data\/ucrm\/data\/plugins\/dishnet-hybrid-sudan/$F/plugin}"); shift;; esac; done
    php -r "$code" "${args[@]}" ;;
  ps) cat "$F/ps" 2>/dev/null ;;
  inspect) echo 4242 ;;
esac
STUB
cat > "$SB/bin/ss" <<'STUB'
#!/usr/bin/env bash
cat "$FAKE/ss_host" 2>/dev/null
STUB
cat > "$SB/bin/nsenter" <<'STUB'
#!/usr/bin/env bash
cat "$FAKE/ss_ns" 2>/dev/null
STUB
cat > "$SB/bin/curl" <<'STUB'
#!/usr/bin/env bash
u="${@: -1}"
case "$*" in *'@@%{http_code}'*)
  case "$u" in
    */crm/online-payment/*)   # uCRM's own payment page: every URL the guard opens is logged, to be asserted on
      echo "$u" >> "$FAKE/fetched.log"
      cat "$FAKE/page_body" 2>/dev/null; printf '\n@@%s %s' "$(cat "$FAKE/page_code" 2>/dev/null || echo 000)" "$(cat "$FAKE/page_loc" 2>/dev/null)";;
    *) echo "$u" >> "$FAKE/curl_pay.log"
       cat "$FAKE/pay_body" 2>/dev/null; printf '\n@@%s' "$(cat "$FAKE/pay_code" 2>/dev/null || echo 000)";;
  esac; exit 0;; esac
case "$u" in */crm) echo "302 https://crm.dishnetuganda.com/crm/";; *) echo "200 ";; esac
STUB
cat > "$SB/health.sh" <<'STUB'
#!/usr/bin/env bash
if [ -f "$FAKE/health_bad" ]; then echo "  [FAIL]  8443/tcp is NOT bound"; exit 1; fi
echo "  [ OK ]  all good"; exit 0
STUB
chmod +x "$SB/bin/"* "$SB/health.sh"

setfake() {  # $1 ucrm address  $2 pdf line
  printf '{"ucrmPublicUrl":"%s","pluginPublicUrl":"%s_plugins/dishnet-hybrid-sudan/public.php","ucrmLocalUrl":"http://localhost/crm/","pluginAppKey":"APPKEY-SECRET-123"}' "$1" "$1" > "$F/plugin/ucrm.json"
  printf '%s\n' "$2" > "$F/pdf_line"
}
routers() {  # $1 = on|off : 6 public on the host (docker proxy), 3 public in the namespace (NAT), private ones never counted
  if [ "$1" = "on" ]; then
    printf '0 0 172.18.0.5:8443 %s\n' 41.210.1.1:50001 41.210.1.2:50002 41.210.1.3:50003 '[::ffff:102.80.4.4]:50004' 102.80.5.5:50005 102.80.5.5:50006 172.17.0.1:40000 10.0.0.9:40001 '[::ffff:172.18.0.1]:40002' 127.0.0.1:40003 > "$F/ss_host"
    printf '0 0 172.20.0.3:443 %s\n' 197.1.1.1:60001 197.1.1.2:60002 197.1.1.3:60003 172.17.0.1:60004 > "$F/ss_ns"
  else : > "$F/ss_host"; : > "$F/ss_ns"; fi
}
echo 'unms-nginx|0.0.0.0:8080->80/tcp, 0.0.0.0:8443->443/tcp' > "$F/ps"
run() { PATH="$SB/bin:$PATH" FAKE="$F" STATE_DIR="$SB/state" HEALTH_SCRIPT="$SB/health.sh" UNMS_CONF="$F/unms.conf" POLL_SECONDS=0 POLLS=3 bash "$SCRIPT" "$@" 2>&1; }

# ── --links fixtures: a fake uCRM client (invoices, templates, one PDF) and a fake web (uCRM's payment page) ──
mkdir -p "$F/plugin/lib"
cat > "$F/plugin/lib/CrmApiClient.php" <<'PHP'
<?php
class CrmApiClient {
  public static function fromUcrm(string $r, array $c = []): self { return new self(); }
  public function isConfigured(): bool { return !file_exists(getenv('FAKE') . '/noconfig'); }
  public function get(string $p): ?array {
    $f = getenv('FAKE');
    if (strpos($p, 'invoice-templates') === 0) return json_decode((string)@file_get_contents("$f/templates.json"), true);
    if ($p === 'organizations') return json_decode((string)@file_get_contents("$f/organizations.json"), true);
    if (strpos($p, 'clients/') === 0) return ['id' => 1, 'organizationId' => 1];
    if (strpos($p, 'invoices?clientId=') === 0) {
      file_put_contents("$f/api.log", $p . "\n", FILE_APPEND);
      return json_decode((string)@file_get_contents("$f/invoices.json"), true);
    }
    return null;
  }
  public function getRawContent(string $p): ?string {
    $b = @file_get_contents(getenv('FAKE') . '/invoice.pdf');
    return ($b === false || $b === '') ? null : base64_encode($b);
  }
}
PHP
TOK="9f8e7d6c5b4a39281706f5e4d3c2b1a0"                     # the payment token: no run may print it
UCRM_PAY="https://crm.dishnetuganda.com:8443/crm/online-payment/pay/$TOK"
mkpdf() {  # $1 the link the PAY NOW box carries: once as a link annotation, once as text in a compressed stream
  php -r '$u=$argv[1]; $s=gzcompress("BT /F1 7 Tf (".$u.") Tj ET");
    echo "%PDF-1.4\n1 0 obj << /Type /Annot /Subtype /Link /A << /S /URI /URI (".$u.") >> >> endobj\n",
         "2 0 obj << /Length ".strlen($s)." /Filter /FlateDecode >>\nstream\n".$s."\nendstream\nendobj\n%%EOF\n";' "$1" > "$F/invoice.pdf"
}
printf '%s' '[{"id":3,"name":"Invoice Ugadna"},{"id":1,"name":"Official — billing@dishnet.example"}]' > "$F/templates.json"
printf '%s' '[{"id":2,"name":"Other Org","city":"Elsewhere","selected":false},{"id":1,"name":"DishNet Africa Ltd.","street1":"Acacia Mall","city":"Kampala","phone":"+256 705 993 348","email":"billing@dishnet.example","website":"www.dishnetuganda.com","taxId":"1059140632","registrationNumber":"","invoiceTemplateId":1,"selected":true}]' > "$F/organizations.json"
printf '%s' '[{"id":41,"number":"000003","status":1,"createdDate":"2026-09-20T10:00:00+0000","invoiceTemplateId":3},{"id":57,"number":"000005","status":1,"createdDate":"2026-09-25T08:00:00+0000","invoiceTemplateId":3}]' > "$F/invoices.json"
printf '%s\n' 'UNMS_HTTP_PORT="8080"' 'UNMS_HTTPS_PORT=8443' 'UNMS_WS_PORT=' 'UNMS_PUBLIC_HTTPS_PORT=' \
  'UNMS_SECURE_LINK_SECRET=LINKSECRET-XYZ-987' 'UNMS_SUPPORT="hunter2secret"' 'UNMS_TOKEN=TOKSECRET-555' > "$F/unms.conf"
printf '%s' '<html><h1>Pay invoice</h1><form action="/crm/online-payment/pay/x"></form><p>endpoint table-striped</p></html>' > "$F/page_body"
echo 200 > "$F/page_code"
printf '%s' '<html><h1>Pay your bill</h1><p>Airtel Money · Merchant ID 4428146</p></html>' > "$F/pay_body"
echo 200 > "$F/pay_code"

echo "S1 --before: the address with :8443, a PDF with :8443, routers connected"
setfake "https://crm.dishnetuganda.com:8443/crm/" "invoice 000005 · crm.dishnetuganda.com:8443 x2"; routers on
out="$(run --before)"; rc=$?
check "$rc" "0" "S1 exits 0"
has "$out" "routers connected on :8443            9 connection(s) from 8 address(es)" "S1 counts 9 public connections from 8 addresses (host + namespace; private and loopback peers excluded)"
has "$out" "14 connection(s) seen on the port in all" "S1 reports everything it saw on the port (the control's basis: 9 public + 5 private/loopback)"
has "$out" "uCRM's address, as it tells plugins   https://crm.dishnetuganda.com:8443/crm/" "S1 prints uCRM's own address"
has "$out" "the latest invoice's PDF              invoice 000005 · crm.dishnetuganda.com:8443 x2" "S1 prints the PDF's link hosts"
has "$out" "THIS SCRIPT CHANGES NOTHING. Between --before and --after, YOU make the change, by hand" "S1 prints the manual steps, and says the script makes no change"
has "$out" "note  found 26 Sep 21:01: on UISP 3.0.159 the fields below do not exist" "S1 says the fields were not found on this server, and points to --links"
has "$out" "If those fields are greyed out" "S1 tells the operator when to stop"
hasnt "$out" "APPKEY-SECRET-123" "S1 never prints the app key"
check "$(grep -c 'APPKEY' "$SB/state/state.env")" "0" "S1 the state file holds no app key"
check "$(grep -c '^BEFORE_DEV_N=9$' "$SB/state/state.env")" "1" "S1 the state file records the count"

echo "S2 --after: the address and the PDF changed, the routers stayed"
setfake "https://crm.dishnetuganda.com/crm/" "invoice 000006 · crm.dishnetuganda.com x2"
out="$(run --after)"; rc=$?
check "$rc" "0" "S2 exits 0"
has "$out" "ok    uCRM now tells plugins its address without :8443" "S2 sees the address change"
has "$out" "ok    the routers are still connected on :8443 (9 connection(s), floor 8)" "S2 the routers stayed (floor 80 % of 9 = 8)"
has "$out" "RESULT   C-2 is done" "S2 verdict: done"
hasnt "$out" "APPKEY-SECRET-123" "S2 never prints the app key"

echo "S3 --after: the routers dropped off :8443 — the rollback instruction"
routers off
out="$(run --after)"; rc=$?
check "$rc" "1" "S3 exits 1"
has "$out" "the routers did NOT come back on :8443" "S3 reports the routers gone"
has "$out" "PUT THE TWO VALUES BACK NOW" "S3 tells the operator to roll back"
has "$out" "RESULT   something the change must not break is broken" "S3 verdict: broken"
check "$(printf '%s\n' "$out" | grep -c '^  …  ')" "3" "S3 polled the configured number of times before judging"

echo "S4 --after: routers fine, uCRM has not rewritten its address yet — a note, never a failure"
routers on; setfake "https://crm.dishnetuganda.com:8443/crm/" "invoice 000005 · crm.dishnetuganda.com:8443 x2"
sed -i "s/^BEFORE_TS=.*/BEFORE_TS=$(date -u -d '-10 min' +%Y-%m-%dT%H:%M:%SZ)/" "$SB/state/state.env"
out="$(run --after)"; rc=$?
check "$rc" "0" "S4 exits 0"
has "$out" "note  uCRM still tells plugins an address with :8443" "S4 notes the address not yet rewritten"
has "$out" "RESULT   nothing broke, but uCRM has not taken the new address yet" "S4 verdict: wait and re-run"

echo "S5 --after with no before-state refuses"
rm -f "$SB/state/state.env"
out="$(run --after)"; rc=$?
check "$rc" "1" "S5 exits 1"
has "$out" "no before-state" "S5 says to run --before first"

echo "S6 --before with UISP already unhealthy: do not change the setting"
touch "$F/health_bad"; out="$(run --before)"; rc=$?; rm -f "$F/health_bad"
check "$rc" "1" "S6 exits 1"
has "$out" "Something is already wrong BEFORE the change. Do not change the setting" "S6 says not to proceed"

echo "S7 an argument that is not a mode is refused"
out="$(run --now)"; rc=$?
check "$rc" "64" "S7 exits 64 with the usage line"

echo "S8 the control: a copy that counts private peers too would read 14, not 9 — the assertion S1 makes discriminates"
cp "$SCRIPT" "$SB/broken.sh"
python3 - "$SB/broken.sh" <<'PY'
import sys; p=sys.argv[1]; t=open(p).read()
old="""    | grep -v -E '^$|^127\\.|^10\\.|^172\\.(1[6-9]|2[0-9]|3[01])\\.|^192\\.168\\.|^169\\.254\\.|^::1$|^f[cd][0-9a-f]*:|^fe80:' \\\n"""
assert t.count(old)==1, "the filter line is gone"
open(p,'w').write(t.replace(old, "    | grep -v -E '^$' \\\n"))
PY
out="$(PATH="$SB/bin:$PATH" FAKE="$F" STATE_DIR="$SB/state" HEALTH_SCRIPT="$SB/health.sh" POLL_SECONDS=0 POLLS=3 bash "$SB/broken.sh" --before 2>&1)"
hasnt "$out" "9 connection(s) from 8 address(es)" "S8 the broken copy does not produce the count S1 asserts"
has "$out" "14 connection(s)" "S8 the broken copy counts the private and loopback peers (14)"

echo "S9 no router connected, but the port is visible (only this server's own connections): a measured zero"
printf '0 0 172.18.0.5:8443 %s\n' 172.17.0.1:40000 127.0.0.1:40003 > "$F/ss_host"; : > "$F/ss_ns"
setfake "https://crm.dishnetuganda.com:8443/crm/" "invoice 000005 · crm.dishnetuganda.com:8443 x2"
out="$(run --before)"; rc=$?
check "$rc" "0" "S9 --before exits 0"
has "$out" "ok    no router is connected to UISP on :8443 right now — measured" "S9 --before calls the zero measured, because the count saw the port"
check "$(grep -c '^BEFORE_DEV_T=2$' "$SB/state/state.env")" "1" "S9 the state file records what was seen in all"
setfake "https://crm.dishnetuganda.com/crm/" "invoice 000006 · crm.dishnetuganda.com x2"
out="$(run --after)"; rc=$?
check "$rc" "0" "S9 --after exits 0"
has "$out" "ok    no router was connected on :8443 before the change either" "S9 --after: nothing to lose, said as a measurement"
has "$out" "RESULT   C-2 is done" "S9 verdict: done"

echo "S10 nothing at all visible on the port: the zero is INDETERMINATE, never 'no routers'"
: > "$F/ss_host"; : > "$F/ss_ns"
setfake "https://crm.dishnetuganda.com:8443/crm/" "invoice 000005 · crm.dishnetuganda.com:8443 x2"
out="$(run --before)"; rc=$?
check "$rc" "0" "S10 --before exits 0"
has "$out" "note  nothing at all was seen on :8443, not even this script's own test connection — the count is blind on this server" "S10 --before says the count is blind"
hasnt "$out" "no router is connected to UISP on :8443 right now" "S10 --before never claims a measured zero"
out="$(run --after)"; rc=$?
has "$out" "note  the router count was blind on this server before the change" "S10 --after sends the operator to UISP's device list"

echo "S11 --after run straight after --before, with nothing changed: the guard says the change is the operator's, by hand"
printf '0 0 172.18.0.5:8443 %s\n' 172.17.0.1:40000 > "$F/ss_host"; : > "$F/ss_ns"
setfake "https://crm.dishnetuganda.com:8443/crm/" "invoice 000005 · crm.dishnetuganda.com:8443 x2"
out="$(run --before)"; out2="$(run --after)"; rc=$?
has "$out" "THIS SCRIPT CHANGES NOTHING. Between --before and --after, YOU make the change" "S11 --before says the change is manual, in uCRM's web page"
check "$rc" "0" "S11 --after exits 0 (nothing is broken)"
has "$out2" "RESULT   nothing broke. uCRM's address is still exactly what --before recorded" "S11 --after recognises the address is unchanged, moments later"
has "$out2" "This script only CHECKS — it changes nothing." "S11 --after says it only checks"
has "$out2" "Not changed in uCRM yet? Make the change by hand" "S11 --after names the manual step"
has "$out2" "Already saved it there? Wait a few minutes" "S11 --after keeps the honest second case"
hasnt "$out2" "RESULT   nothing broke, but uCRM has not taken the new address yet" "S11 --after does not give the generic verdict"
hasnt "$out2" "uCRM rewrites that file itself" "S11 --after does not give the generic note"

echo "L1 --links before the template fix: the two :8443 links are uCRM's pay link; uCRM's page names nothing"
mkpdf "$UCRM_PAY"; rm -f "$F/fetched.log" "$F/api.log"
sum_before="$(cat "$SB/state/state.env" 2>/dev/null | md5sum)"
out="$(run --links)"; rc=$?
check "$rc" "0" "L1 exits 0"
has "$out" "2 × https://crm.dishnetuganda.com:8443/crm/online-payment/pay/{token}" "L1 shows the two links, token masked"
hasnt "$out" "$TOK" "L1 never prints the payment token"
hasnt "$out" "APPKEY-SECRET-123" "L1 never prints the app key"
has "$out" 'template #3 "Invoice Ugadna"' "L1 names the template uCRM used for the invoice"
has "$out" "000005 · unpaid · created 2026-09-25" "L1 reads the newest unpaid invoice (000005, not 000003)"
has "$out" '#1 "Official — {e-mail}"' "L1 masks an e-mail address inside a template name"
hasnt "$out" "billing@dishnet.example" "L1 never prints that e-mail address"
check "$(grep -c 'clientId=1&' "$F/api.log")" "1" "L1 asks uCRM for client #1's unpaid invoices"
check "$(grep -c ':8443' "$F/fetched.log")" "0" "L1 opens uCRM's payment page on the public address, never on :8443"
check "$(grep -c "/crm/online-payment/pay/$TOK" "$F/fetched.log")" "1" "L1 opens exactly that page, once"
has "$out" "crm.dishnetuganda.com:443 → 200" "L1 reports the page's status on the public address"
has "$out" "payment options it names   none of PayPal" "L1 reports that the page names no payment option"
# The page L1 serves carries "endpoint" and "table-striped": a substring match would name DPO and Stripe.
check "$(printf '%s\n' "$out" | grep -c '^  payment options it names   none of ')" "1" "L1 does not read 'endpoint' as DPO, nor 'table-striped' as Stripe"
has "$out" "UNMS_HTTPS_PORT=8443" "L1 shows the port UISP was installed with"
has "$out" "UNMS_PUBLIC_HTTPS_PORT=" "L1 shows the public-port key, empty"
hasnt "$out" "LINKSECRET-XYZ-987" "L1 never prints a secret from UISP's settings file"
hasnt "$out" "hunter2secret" "L1 never prints a non-numeric value, even under a key ending in PORT"
hasnt "$out" "TOKSECRET-555" "L1 never prints UISP's token"
has "$out" "ok    https://dishnetuganda.com/pay answers 200 and tells the customer how to pay with Airtel Money" "L1 checks DishNet's pay page"
has "$out" "RESULT   the 2 link(s) on :8443 inside this invoice are uCRM's own online payment page" "L1 verdict names the source"
has "$out" "THE FIX — BY HAND, IN uCRM'S TEMPLATE EDITOR. THIS SCRIPT CHANGES NOTHING." "L1 prints the manual fix"
has "$out" 'href="https://dishnetuganda.com/pay"' "L1 the fix points the button at the Uganda profile's pay_url"
has "$out" "becomes   dishnetuganda.com/pay" "L1 the fix prints the short address under the button"
has "$out" "replace its whole text" "L1 the fix offers the whole Uganda template for a template with another country's details"
has "$out" "dishnet-hybrid-sudan/ucrm_pdf_templates/invoice_uganda/template.html.twig" "L1 the fix names the Uganda template's file"
has "$out" "its organization           DishNet Africa Ltd. · Acacia Mall, Kampala · phone +256 … 348 · e-mail …@dishnet.example" "L1 shows the invoice's organization, the one the Uganda template prints"
has "$out" "TIN 1059140632 · Reg. No not set in uCRM" "L1 shows which registration facts uCRM holds"
hasnt "$out" "705 993" "L1 never prints the organization's phone number in full"
hasnt "$out" "billing@" "L1 never prints the organization's e-mail address in full"
hasnt "$out" "Other Org" "L1 picks the client's organization, not another one"
has "$out" 'new invoices use           template #1 "Official — {e-mail}"' "L1 shows which template the organization gives new invoices"
check "$(cat "$SB/state/state.env" 2>/dev/null | md5sum)" "$sum_before" "L1 --links leaves the before-state untouched"

printf "%s\n" "$out" > "${L1_DUMP:-/dev/null}"   # L1_DUMP=<file> keeps L1's whole output, to read it
echo "L2 uCRM's payment page names PayPal: no fix printed, the decision goes to the operator"
printf '%s' '<html><h1>Pay invoice</h1><button>Pay with PayPal</button></html>' > "$F/page_body"
out="$(run --links)"
has "$out" "payment options it names   PayPal" "L2 reports PayPal"
has "$out" "uCRM's payment page names: PayPal. Customers may be able to pay there" "L2 verdict: decide first"
hasnt "$out" "THE FIX — BY HAND" "L2 prints no fix"
printf '%s' '<html><h1>Pay invoice</h1><form action="/x"></form></html>' > "$F/page_body"

echo "L3 after the template fix: PAY NOW leads to DishNet's pay page"
mkpdf "https://dishnetuganda.com/pay"; rm -f "$F/fetched.log"
out="$(run --links)"
has "$out" "2 × https://dishnetuganda.com/pay" "L3 shows the new links"
has "$out" "RESULT   C-2 is done for this invoice: PAY NOW leads to DishNet's pay page and no link carries :8443." "L3 verdict: done"
check "$(test -f "$F/fetched.log" && echo opened || echo untouched)" "untouched" "L3 opens no uCRM payment page when there is no :8443 link"

echo "L4 no unpaid invoice: nothing to read, said as such"
echo '[]' > "$F/invoices.json"
out="$(run --links)"
has "$out" "RESULT   nothing to read: client #1 has no unpaid invoice" "L4 verdict: nothing to read"
hasnt "$out" "carries no link at all" "L4 never reads 'no invoice' as 'no links'"
out="$(CLIENT_ID=7 run --links)"
check "$(grep -c 'clientId=7&' "$F/api.log")" "1" "L4 CLIENT_ID chooses the client"
printf '%s' '[{"id":57,"number":"000005","status":1,"createdDate":"2026-09-25T08:00:00+0000","invoiceTemplateId":3}]' > "$F/invoices.json"

echo "L5 DishNet's pay page does not answer: a failure, and no fix printed"
mkpdf "$UCRM_PAY"; echo 404 > "$F/pay_code"
out="$(run --links)"; rc=$?
check "$rc" "1" "L5 exits 1"
has "$out" "FAIL  https://dishnetuganda.com/pay → 404 — do not point PAY NOW at it until it answers" "L5 says the pay page is down"
hasnt "$out" "THE FIX — BY HAND" "L5 prints no fix"
echo 200 > "$F/pay_code"

echo "L7 uCRM's page on the public address redirects back to :8443: hops shown as host:port only, and no verdict"
mkpdf "$UCRM_PAY"; rm -f "$F/fetched.log"; echo 301 > "$F/page_code"; printf '%s' "$UCRM_PAY" > "$F/page_loc"
out="$(run --links)"
has "$out" "hop 1                      crm.dishnetuganda.com:443 → 301" "L7 the first hop, on the public address"
has "$out" "hop 2                      crm.dishnetuganda.com:8443 → 301" "L7 the redirect is followed, shown as host:port"
check "$(grep -c . "$F/fetched.log")" "4" "L7 stops after four hops"
hasnt "$out" "$TOK" "L7 never prints the token inside a redirect"
has "$out" "uCRM's payment page could not be read (status 301)" "L7 verdict: nothing concluded about uCRM's page"
hasnt "$out" "THE FIX — BY HAND" "L7 prints no fix while uCRM's page is unread"
echo 200 > "$F/page_code"; rm -f "$F/page_loc"

echo "L10 the invoice's template has left uCRM's list (26 Sep 21:57): no fix to a template that is gone; a broken one is flagged"
mkpdf "$UCRM_PAY"; rm -f "$F/fetched.log"; cp "$F/templates.json" "$F/templates.keep"
printf '%s' '[{"id":1,"name":"Template 1"},{"id":1002,"name":"v2","isValid":true},{"id":1003,"name":"broken","isValid":false}]' > "$F/templates.json"
out="$(run --links)"; rc=$?
check "$rc" "0" "L10 exits 0"
has "$out" "template #3, no longer in uCRM's list" "L10 says the invoice's template is gone"
has "$out" "come from the template it was made with, which is no" "L10 verdict: this PDF cannot show a new template"
has "$out" "run --links again after the next invoice for this client" "L10 says when the check can see the change"
hasnt "$out" "THE FIX — BY HAND" "L10 prints no fix for a template that is gone"
has "$out" '#1003 "broken" (uCRM marks it INVALID)' "L10 flags a template uCRM marks invalid"
hasnt "$out" '#1002 "v2" (uCRM marks it INVALID)' "L10 does not flag a valid one"
mv "$F/templates.keep" "$F/templates.json"

echo "L6 the controls: copies that stop masking are caught by the assertions above"
mkdir -p "$SB/broken/scripts/lib" "$SB/broken/dishnet-hybrid-sudan/profiles"
cp "$SCRIPT" "$SB/broken/scripts/dnb-c2-check.sh"; cp "$R/dishnet-hybrid-sudan/profiles/uganda.json" "$SB/broken/dishnet-hybrid-sudan/profiles/"
python3 - "$R/scripts/lib/c2_invoice_links.php" "$SB/broken/scripts/lib/c2_invoice_links.php" <<'PY'
import sys; t = open(sys.argv[1]).read()
a = "function c2_mask_url(string $u): string\n{\n"; assert t.count(a) == 1
open(sys.argv[2], "w").write(t.replace(a, a + "    return $u;\n"))
PY
out="$(PATH="$SB/bin:$PATH" FAKE="$F" STATE_DIR="$SB/state" HEALTH_SCRIPT="$SB/health.sh" UNMS_CONF="$F/unms.conf" bash "$SB/broken/scripts/dnb-c2-check.sh" --links 2>&1)"
has "$out" "$TOK" "L6 a copy that does not mask links prints the token — so L1's assertion discriminates"
cp "$R/scripts/lib/c2_invoice_links.php" "$SB/broken/scripts/lib/c2_invoice_links.php"
python3 - "$SB/broken/scripts/dnb-c2-check.sh" <<'PY'
import sys; p = sys.argv[1]; t = open(p).read()
a = """grep -E '^[A-Z0-9_]*PORT="?[0-9]*"?$'"""; assert t.count(a) == 1
open(p, "w").write(t.replace(a, "grep -E 'PORT'"))
PY
out="$(PATH="$SB/bin:$PATH" FAKE="$F" STATE_DIR="$SB/state" HEALTH_SCRIPT="$SB/health.sh" UNMS_CONF="$F/unms.conf" bash "$SB/broken/scripts/dnb-c2-check.sh" --links 2>&1)"
has "$out" "hunter2secret" "L6 a copy that reads any PORT key prints a secret — so L1's assertion discriminates"

echo; echo "REHEARSAL: $PASS ok, $FAILN failed"
[ "$FAILN" = "0" ]
