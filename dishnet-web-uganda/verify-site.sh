#!/usr/bin/env bash
# Serve site/ with the real nginx.conf and check it end to end.
# Uganda edition: no _redirects section — dishnetuganda.com has no legacy URLs.
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
           portal.dishnetss.com dishnetsudan 249900083481 211921443002; do
  n=$(grep -rF "$pat" "$HERE/site" --include='*.html' --include='*.xml' --include='*.txt' -l 2>/dev/null | wc -l)
  [ "$n" = 0 ] || { echo "  '$pat' still present in $n files"; fail=1; }
done
# One WhatsApp number, everywhere.
wa=$(grep -rhoE 'wa\.me/[0-9]+' "$HERE/site" --include='*.html' | sort -u)
[ "$wa" = "wa.me/256705993348" ] || { echo "  unexpected WhatsApp target(s): $wa"; fail=1; }
# One customer-portal URL, everywhere it appears — plus the plugin's public
# price feed, which legitimately lives on the same host.
portal=$(grep -rhoE 'https://crm\.dishnetuganda\.com[^"]*' "$HERE/site" --include='*.html' | sort -u \
         | grep -v '^https://crm\.dishnetuganda\.com/crm/_plugins/dishnet-hybrid-sudan/public\.php?page=prices$')
[ "$portal" = "https://crm.dishnetuganda.com/crm" ] || { echo "  unexpected portal URL(s): $portal"; fail=1; }
[ $fail -eq 0 ] && echo "  no foreign-country remnants; one WhatsApp number; one portal URL"

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
sys.exit(bad)
PYCOM
[ $fail -eq 0 ] && echo "  no price published anywhere outside tutorial mock screenshots"

echo
[ $fail -eq 0 ] && echo "PASS" || echo "FAIL"
exit $fail
