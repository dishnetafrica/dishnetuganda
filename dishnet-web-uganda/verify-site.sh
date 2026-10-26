#!/usr/bin/env bash
# Serve site/ with the real nginx.conf and check it end to end.
# Uganda edition: no Netlify _redirects; nginx.conf redirects /login, /app and the
# withdrawn fibre addresses (all asserted below).
#
# Worth re-running after the localisation pass: that rewrites hundreds of
# links, and a broken one is invisible in production because the Netlify
# catch-all serves the homepage with a 200 instead of a 404.
#
#   ./verify-site.sh          needs nginx on PATH; touches nothing outside $TMP
set -u
HERE=$(cd "$(dirname "$0")" && pwd)
TMP=${TMPDIR:-/tmp}/dishnet-verify.$$
PORT=${PORT:-8099}
B=http://127.0.0.1:$PORT
command -v nginx >/dev/null || { echo "nginx not on PATH"; exit 2; }
mkdir -p "$TMP"/{logs,client,tmp}
trap 'nginx -s stop -c "$TMP/nginx.conf" 2>/dev/null; rm -rf "$TMP"' EXIT

sed -e "s|root         /usr/share/nginx/html;|root         $HERE/site;|" \
    -e "s|listen  *[0-9]*;|listen       $PORT;|" "$HERE/nginx.conf" > "$TMP/server.conf"
cat > "$TMP/nginx.conf" <<EOF
user $(id -un);
worker_processes 1;
error_log $TMP/logs/error.log warn;
pid $TMP/nginx.pid;
events { worker_connections 64; }
http {
  include /etc/nginx/mime.types;
  default_type application/octet-stream;
  access_log off;
  client_body_temp_path $TMP/client;
  proxy_temp_path $TMP/tmp; fastcgi_temp_path $TMP/tmp/f;
  uwsgi_temp_path $TMP/tmp/u; scgi_temp_path $TMP/tmp/s;
  include $TMP/server.conf;
}
EOF
nginx -t -c "$TMP/nginx.conf" >/dev/null 2>&1 || { nginx -t -c "$TMP/nginx.conf"; exit 1; }
nginx -c "$TMP/nginx.conf" || exit 1
sleep 1
fail=0

echo "== headers =="
for p in / /faq /styles.css; do
  n=$(curl -sI "$B$p" | grep -icE 'x-content-type-options|x-frame-options|referrer-policy')
  c=$(curl -sI "$B$p" | grep -ic '^cache-control')
  # nginx drops inherited add_header in any block declaring its own, so check
  # each kind of path rather than trusting the server-level directives.
  [ "$n" = 3 ] || { echo "  $p: only $n/3 security headers"; fail=1; }
  [ "$c" = 1 ] || { echo "  $p: $c Cache-Control headers (want exactly 1)"; fail=1; }
done
[ $fail -eq 0 ] && echo "  3/3 security headers and exactly one Cache-Control everywhere"

echo "== pages =="
bad=0
while IFS= read -r f; do
  case "$f" in */404.html) continue;; esac   # asserted separately below
  c=$(curl -s -o /dev/null -w '%{http_code}' "$B/${f#site/}")
  [ "$c" = 200 ] || { echo "  HTTP $c  /${f#site/}"; bad=$((bad+1)); }
done < <(find "$HERE/site" -name '*.html' | sed "s|$HERE/||" | sort)
echo "  $(find "$HERE/site" -name '*.html' | wc -l) pages, $bad non-200"
[ $bad -gt 0 ] && fail=1

echo "== internal links and assets =="
HOME_SZ=$(curl -s "$B/index.html" | wc -c)
python3 - "$HERE" <<'PY' > "$TMP/refs"
import re,os,glob,sys
root=sys.argv[1]; pat=re.compile(r'(?:src|href)="([^"]+)"')
skip=re.compile(r'^(https?:|//|mailto:|tel:|javascript:|#|data:)'); seen=set()
for f in glob.glob(os.path.join(root,'site','**','*.html'),recursive=True):
    d=os.path.dirname(f)
    for m in pat.findall(open(f,encoding='utf-8',errors='ignore').read()):
        r=m.split('?')[0].split('#')[0]
        if not r or skip.match(r): continue
        if r == '/login': continue    # a 302 to the CRM, asserted under "customer sign-in"
        if r.endswith('.apk'): continue   # binaries are uploaded at deploy, not tracked in git
        u=r if r.startswith('/') else '/'+os.path.relpath(os.path.normpath(os.path.join(d,r)),os.path.join(root,'site'))
        if u.endswith('/'): u += 'index.html'   # a directory link means its index
        elif not u.rsplit('/',1)[-1].count('.'): u += '/index.html'
        if u not in seen: seen.add(u); print(u)
PY
bad=0
while IFS= read -r u; do
  c=$(curl -s -o /dev/null -w '%{http_code}' "$B$u")
  if [ "$c" != 200 ]; then echo "  HTTP $c  $u"; bad=$((bad+1)); continue; fi
  case "$u" in *.html)
    [ "$u" = /index.html ] && continue
    # the catch-all hides missing pages behind a 200 homepage
    [ "$(curl -s "$B$u" | wc -c)" = "$HOME_SZ" ] && { echo "  MASKED 404  $u"; bad=$((bad+1)); };;
  esac
done < "$TMP/refs"
echo "  $(wc -l < "$TMP/refs") references, $bad broken"
[ $bad -gt 0 ] && fail=1

echo "== 404 behaviour =="
c=$(curl -s -o /dev/null -w '%{http_code}' "$B/this-page-does-not-exist")
b=$(curl -s "$B/this-page-does-not-exist" | grep -c "That page isn")
if [ "$c" = 404 ] && [ "$b" -ge 1 ]; then
  echo "  unknown URLs return a real 404 with the branded page"
else
  echo "  FAIL: unknown URL returned $c (branded content: $b)"; fail=1
fi
d=$(curl -s -o /dev/null -w '%{http_code}' "$B/404.html")
[ "$d" = 404 ] && echo "  /404.html not directly reachable (internal)" || { echo "  FAIL: /404.html directly returned $d"; fail=1; }

echo "== customer sign-in =="
# Every "Customer Login" links /login; nginx answers it with a 302 (never a
# 301, which a browser would keep) to the CRM sign-in, carrying the same
# security headers as every other path.
LOGIN_TARGET='https://crm.dishnetuganda.com/crm/_plugins/dishnet-hybrid-sudan/public.php?page=customer_login'
h=$(curl -sI "$B/login")
c=$(printf '%s\n' "$h" | head -1 | awk '{print $2}')
loc=$(printf '%s\n' "$h" | grep -i '^location:' | sed -E 's/^[Ll]ocation: *//' | tr -d '\r')
s=$(printf '%s\n' "$h" | grep -icE 'x-content-type-options|x-frame-options|referrer-policy')
if [ "$c" = 302 ] && [ "$loc" = "$LOGIN_TARGET" ] && [ "$s" = 3 ]; then
  echo "  /login -> 302 to the CRM sign-in, 3/3 security headers"
else
  echo "  FAIL: /login returned $c, Location '$loc', $s/3 security headers"; fail=1
fi
n=$(grep -rlF 'href="/login"' "$HERE/site" --include='*.html' | wc -l)
[ "$n" -gt 0 ] || { echo "  FAIL: no page links /login"; fail=1; }

echo "== withdrawn pages =="
# October 2026: DishNet Uganda does not currently sell fibre, so the fibre page and the Starlink-vs-fibre article
# are gone and their URLs redirect (nginx.conf). Each must be a 301 to its target, relative, carrying the security
# headers and exactly one Cache-Control from the server-level `expires 10m` (max-age=600), so a browser keeps the
# redirect for ten minutes rather than for ever. The files themselves must be gone. /app (the plugin's Uganda
# profile links it) is a 302 to the app page.
wbad=0
for pair in "/fiber.html /services.html 301" "/fiber /services.html 301" \
            "/blog-starlink-vs-fiber-uganda.html /blog.html 301" "/blog-starlink-vs-fiber-uganda /blog.html 301" \
            "/app /get-the-app.html 302"; do
  set -- $pair
  h=$(curl -sI "$B$1")
  c=$(printf '%s\n' "$h" | head -1 | awk '{print $2}')
  loc=$(printf '%s\n' "$h" | grep -i '^location:' | sed -E 's/^[Ll]ocation: *//' | tr -d '\r')
  s=$(printf '%s\n' "$h" | grep -icE 'x-content-type-options|x-frame-options|referrer-policy')
  n=$(printf '%s\n' "$h" | grep -ic '^cache-control:')
  cc=$(printf '%s\n' "$h" | grep -i '^cache-control:' | tr -d '\r')
  if [ "$c" != "$3" ] || [ "$loc" != "$2" ] || [ "$s" != 3 ] || [ "$n" != 1 ] || ! printf '%s' "$cc" | grep -q 'max-age=600'; then
    echo "  FAIL: $1 returned $c (want $3), Location '$loc' (want $2), $s/3 security headers, $n Cache-Control '$cc'"; wbad=1
  fi
  t=$(curl -s -o /dev/null -w '%{http_code}' "$B$2")
  [ "$t" = 200 ] || { echo "  FAIL: redirect target $2 returned $t"; wbad=1; }
done
for f in fiber.html blog-starlink-vs-fiber-uganda.html; do
  [ -e "$HERE/site/$f" ] && { echo "  FAIL: site/$f still exists"; wbad=1; }
done
[ $wbad -eq 0 ] && echo "  fibre page and article: 301, 10-minute expiry, headers kept, targets 200; /app: 302 to the app page" || fail=1

echo "== seo =="
# A canonical pointing at another domain tells Google not to index this site at
# all — the exact bug this build shipped with (uganda.dishnetafrica.com).
foreign=$(grep -rho 'rel="canonical" href="https\?://[^/"]*' "$HERE/site" --include='*.html' \
  | sed 's|.*//||' | sort -u | grep -v '^dishnetuganda\.com$' || true)
[ -n "$foreign" ] && { echo "  canonical points off-domain: $foreign"; fail=1; } \
                  || echo "  all canonicals on dishnetuganda.com"
grep -q 'dishnetuganda.com/sitemap.xml' "$HERE/site/robots.txt" \
  || { echo "  robots.txt sitemap line wrong"; fail=1; }
python3 -c "import xml.dom.minidom,sys;xml.dom.minidom.parse('$HERE/site/sitemap.xml')" 2>/dev/null \
  || { echo "  sitemap.xml is not well-formed"; fail=1; }
# every sitemap URL must resolve 200 through the real server config
while IFS= read -r u; do
  c=$(curl -s -o /dev/null -w '%{http_code}' "$B${u#https://dishnetuganda.com}")
  [ "$c" = 200 ] || { echo "  sitemap URL $u -> HTTP $c"; fail=1; }
done < <(grep -oE '<loc>[^<]+' "$HERE/site/sitemap.xml" | sed 's/<loc>//')
[ $fail -eq 0 ] && echo "  sitemap well-formed, every listed URL resolves 200"

echo "== content integrity =="
# Remnants of the placeholder build or of any other DishNet country. Zero is
# the only acceptable count for each.
for pat in 256700000000 '700 000 000' uganda.dishnetafrica.com crm.dishnetafrica.com \
           portal.dishnetss.com dishnetsudan 249900083481 211921443002 \
           "921 443 00" "DishNet<small>SUDAN</small>" 'content="SD"' '"addressCountry": "SD"'; do
  n=$(grep -rF "$pat" "$HERE/site" --include='*.html' --include='*.xml' --include='*.txt' -l 2>/dev/null | wc -l)
  [ "$n" = 0 ] || { echo "  '$pat' still present in $n files"; fail=1; }
done
# One WhatsApp number, everywhere.
wa=$(grep -rhoE 'wa\.me/[0-9]+' "$HERE/site" --include='*.html' | sort -u)
[ "$wa" = "wa.me/256705993348" ] || { echo "  unexpected WhatsApp target(s): $wa"; fail=1; }
# No customer-portal URL in any page: every "Customer Login" links the site's
# own /login, which nginx redirects to the CRM sign-in (checked under
# "customer sign-in" above), so the CRM plugin's path never sits in a link a
# customer reads. The only CRM URLs a page may carry are the plugin's public
# price feed and web chat, the shop's accessories feed and the bare origin
# shop.html and index.html preconnect to (every product photo is served from
# there too), and the public distributor-application intake the
# Become-a-Distributor page posts to — none of them shown to a customer.
# (The sed strips a trailing '...; the intake URL lives in single-quoted JS.)
portal=$(grep -rhoE 'https://crm\.dishnetuganda\.com[^"]*' "$HERE/site" --include='*.html' | sed "s/'.*//" | sort -u \
         | grep -v '^https://crm\.dishnetuganda\.com/crm/_plugins/dishnet-hybrid-sudan/public\.php?page=prices$' \
         | grep -v '^https://crm\.dishnetuganda\.com/crm/_plugins/dishnet-hybrid-sudan/public\.php?page=web_chat$' \
         | grep -v '^https://crm\.dishnetuganda\.com/crm/_plugins/dishnet-hybrid-sudan/public\.php?page=shop&amp;format=json$' \
         | grep -v '^https://crm\.dishnetuganda\.com/crm/_plugins/dishnet-hybrid-sudan/public\.php?page=distributor_apply$' \
         | grep -v '^https://crm\.dishnetuganda\.com$')
[ -z "$portal" ] || { echo "  unexpected CRM URL(s) in pages: $portal"; fail=1; }
n=$(grep -rF 'page=customer_login' "$HERE/site" --include='*.html' -l | wc -l)
[ "$n" = 0 ] || { echo "  $n pages still link page=customer_login directly instead of /login"; fail=1; }
# Never the bare /crm. The server in front of uCRM answers it with a permanent
# redirect to /crm/ that carries its own port, :8443 — self-signed, so a
# customer's browser shows a security warning instead of the sign-in page.
n=$(grep -rF 'https://crm.dishnetuganda.com/crm"' "$HERE/site" --include='*.html' -l | wc -l)
[ "$n" = 0 ] || { echo "  $n pages still link the bare /crm, which redirects to :8443"; fail=1; }
[ $fail -eq 0 ] && echo "  no foreign-country remnants; one WhatsApp number; no portal URL outside /login"

echo "== commercial rules =="
# The house law: prices live in uCRM, never in this site. The only currency
# figures allowed anywhere are inside tutorials/ — mock app screenshots.
python3 - "$HERE/site" <<'PYCOM' || fail=1
import sys, re, glob, os
root = sys.argv[1]; bad = 0
for f in glob.glob(os.path.join(root, '**', '*.html'), recursive=True):
    if os.sep + 'tutorials' + os.sep in f: continue
    t = open(f, encoding='utf-8', errors='ignore').read()
    for m in re.findall(r'(?:\$|UGX|USh)\s?[0-9][0-9,.]*', t):
        print(f"  price-like figure {m!r} outside tutorials: {os.path.relpath(f, root)}"); bad = 1
# Scripts too, with their comments removed: a figure a script writes into a page is a published price. Prices from
# the billing feed go through fmt(), never a literal. Control: the stripper keeps code and drops comments.
def code(t):
    t = re.sub(r'/\*.*?\*/', '', t, flags=re.S)
    return '\n'.join(l for l in t.split('\n') if not l.lstrip().startswith('//'))
if code("/* UGX 5 */ x = 'UGX 9';\n// $1\n") != " x = 'UGX 9';\n": print('  CONTROL FAILED: comment stripper'); sys.exit(1)
for f in glob.glob(os.path.join(root, 'assets', 'js', '*.js')):
    for m in re.findall(r'(?:\$|UGX|USh)\s?[0-9][0-9,.]*', code(open(f, encoding='utf-8').read())):
        print(f"  price-like figure {m!r} in a script: {os.path.relpath(f, root)}"); bad = 1
sys.exit(bad)
PYCOM
[ $fail -eq 0 ] && echo "  no price published anywhere outside tutorial mock screenshots"

echo "== positioning rules =="
# The decisions of October 2026, enforced: DishNet Uganda offers Starlink only (no fibre, no LTE), and support
# claims use the approved wording ("WhatsApp support is available seven days a week", "more than 40 towns"),
# never "24/7", "four regions", "every region", "10+ years", "two countries" or "thousands of". Zero is the only
# acceptable count, tutorials included.
python3 - "$HERE/site" <<'PYPOS' || fail=1
import sys, re, glob, os
root = sys.argv[1]; bad = 0
rules = [(re.compile(r'fib(?:er|re)', re.I), 'fibre'), (re.compile(r'\bLTE\b'), 'LTE'),
         (re.compile(r'24/7|24x7|24 hours a day', re.I), '24/7 support'),
         (re.compile(r'four regions|every region', re.I), 'an unapproved coverage claim'),
         (re.compile(r'10\+ years|ten years|two countries|\b2 countries|thousands of', re.I), 'an unapproved company fact'),
         (re.compile(r'answered every day|support every day|every day of the week', re.I), 'unapproved support-hours wording'),
         (re.compile(r'Uganda since 2013', re.I), 'an unapproved company fact (the approved one is "DishNet Africa has operated since 2013")'),
         (re.compile(r'\b4G\b|fixed wireless|data network', re.I), 'an alternative connectivity offer'),
         (re.compile(r'\bwe activate\b|\bwe will activate\b|activate your (?:kit|dish)|5-15 minutes', re.I), 'an activation promise (Starlink controls activation)')]
# Control: each rule must match its own sample first, so a rule broken by an edit cannot pass by matching nothing.
for (rx, name), sample in zip(rules, ('Fibre plans', 'Starlink, LTE', 'support 24/7', 'across all four regions',
                                      'over 10+ years in two countries', 'WhatsApp, answered every day',
                                      'Connecting Uganda since 2013', '4G backup', 'we activate your kit')):
    if not rx.search(sample): print(f'  CONTROL FAILED: the {name} rule does not match {sample!r}'); sys.exit(1)
files = glob.glob(os.path.join(root, '**', '*.html'), recursive=True) + [os.path.join(root, f) for f in ('sitemap.xml', 'robots.txt')]
for f in files:
    t = open(f, encoding='utf-8', errors='ignore').read()
    for rx, name in rules:
        for m in rx.finditer(t):
            print(f"  {name}: {os.path.relpath(f, root)}: ...{t[max(0, m.start() - 40):m.end() + 40]!r}..."); bad = 1
assert len(rules) == 9
if not bad: print('  no fibre, no LTE, no 24/7, no unapproved coverage claim, company fact, support-hours wording,'
                  ' alternative connectivity or activation promise on any page (rules self-checked)')
sys.exit(bad)
PYPOS

echo
[ $fail -eq 0 ] && echo "PASS" || echo "FAIL"
exit $fail
