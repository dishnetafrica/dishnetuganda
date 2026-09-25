"""Fake Traefik for the operator-app harness (scripts/harness/operator-app).

TLS on 127.0.0.1:443 (entryPoint "https") and plain HTTP on 127.0.0.1:80
(entryPoint "http"). On EVERY request it re-reads every *.yml / *.yaml file in
/etc/easypanel/traefik/config, as Traefik's file provider does after a reload,
and merges their routers, middlewares and services; a file that does not parse
is skipped and logged, as Traefik skips it.

What it implements, and nothing more — enough to rehearse the staging scripts,
never a claim about Traefik itself:
  - rules: Host(`h`), Path(`/p`), PathPrefix(`/p`), with && , || and brackets;
  - router choice: the matching router with the highest priority (an explicit
    `priority`, else the rule's length, as Traefik's default);
  - no matching router: Traefik's own `404 page not found`;
  - middlewares, in order: redirectScheme (301 when permanent), basicAuth (any
    Basic credentials pass — the stage-2 route's; a missing header is 401),
    rateLimit (a token bucket per middleware and client address: `burst`
    tokens, refilled at `average` per `period`), headers (frameDeny,
    contentTypeNosniff, referrerPolicy, stsSeconds on https, and
    customResponseHeaders, SET on the response);
  - the proxied request comes FROM the address in state/traefik.src (so the
    Admin API's trusted-proxy check can be exercised), carries
    X-Forwarded-Proto/-For/-Host, and has client X-Forwarded-* stripped.

The Admin API's fake container listens on 127.0.0.1:8099 only, so a backend
http://172.17.0.1:8099 is dialled at 127.0.0.1:8099. Every other backend is
dialled exactly as written: the operator app's fake container publishes
172.17.0.1:8098 for real (the harness puts that address on the loopback).
"""
import glob, http.client, http.server, os, re, ssl, threading, time, base64
import yaml

H = os.environ['HSIM']
STATE = H + '/state'
CFG = '/etc/easypanel/traefik/config'
LOCK = threading.Lock()
BUCKETS = {}

def note(msg):
    with open(STATE + '/traefik.log', 'a') as f:
        f.write(time.strftime('%H:%M:%S ') + msg + '\n')

TOKEN = re.compile(r'\s*(?:(&&)|(\|\|)|(\()|(\))|(Host|PathPrefix|Path)\(`([^`]*)`\))')

def parse(rule):
    toks, pos = [], 0
    while rule[pos:].strip():
        m = TOKEN.match(rule, pos)
        if not m:
            raise ValueError('cannot parse rule at: ' + rule[pos:])
        pos = m.end()
        if m.group(1): toks.append(('AND',))
        elif m.group(2): toks.append(('OR',))
        elif m.group(3): toks.append(('(',))
        elif m.group(4): toks.append((')',))
        else: toks.append(('M', m.group(5), m.group(6)))
    i = 0
    def expr():
        nonlocal i
        node = term()
        while i < len(toks) and toks[i][0] == 'OR':
            i += 1; node = ('or', node, term())
        return node
    def term():
        nonlocal i
        node = factor()
        while i < len(toks) and toks[i][0] == 'AND':
            i += 1; node = ('and', node, factor())
        return node
    def factor():
        nonlocal i
        if i >= len(toks):
            raise ValueError('rule ends early')
        t = toks[i]
        if t[0] == '(':
            i += 1; node = expr()
            if i >= len(toks) or toks[i][0] != ')':
                raise ValueError('missing )')
            i += 1; return node
        if t[0] == 'M':
            i += 1; return ('m', t[1], t[2])
        raise ValueError('unexpected %r' % (t,))
    node = expr()
    if i != len(toks):
        raise ValueError('trailing tokens in rule')
    return node

def matches(node, host, path):
    k = node[0]
    if k == 'or': return matches(node[1], host, path) or matches(node[2], host, path)
    if k == 'and': return matches(node[1], host, path) and matches(node[2], host, path)
    _, fn, arg = node
    if fn == 'Host': return host.lower() == arg.lower()
    if fn == 'Path': return path == arg
    return path.startswith(arg)

def load():
    routers, mws, svcs = {}, {}, {}
    for f in sorted(glob.glob(CFG + '/*.yml') + glob.glob(CFG + '/*.yaml')):
        try:
            d = yaml.safe_load(open(f)) or {}
            h = d.get('http', {})
            rs = h.get('routers', {}) or {}
            for name, r in rs.items():
                r = dict(r); r['_ast'] = parse(r['rule']); r['_file'] = os.path.basename(f)
                for m in r.get('middlewares', []):
                    if m not in (h.get('middlewares') or {}) and m not in mws:
                        raise ValueError('middleware %s not defined' % m)
                routers[name] = r
            mws.update(h.get('middlewares', {}) or {})
            svcs.update(h.get('services', {}) or {})
        except Exception as e:  # Traefik keeps no route from an invalid file
            note('error loading %s: %s' % (os.path.basename(f), e))
    return routers, mws, svcs

def period(v):
    if v is None: return 1.0
    v = str(v).strip()
    unit = {'s': 1, 'm': 60, 'h': 3600}
    return float(v[:-1]) * unit[v[-1]] if v[-1] in unit else float(v)

def allowed(key, cfg):
    avg = float(cfg.get('average', 0))
    if avg <= 0:
        return True
    burst = float(cfg.get('burst', 1))
    rate = avg / period(cfg.get('period'))
    now = time.monotonic()
    with LOCK:
        tokens, last = BUCKETS.get(key, (burst, now))
        tokens = min(burst, tokens + (now - last) * rate)
        if tokens >= 1:
            BUCKETS[key] = (tokens - 1, now); return True
        BUCKETS[key] = (tokens, now); return False

class Proxy(http.server.BaseHTTPRequestHandler):
    protocol_version = 'HTTP/1.1'
    ep = 'https'
    def log_message(self, fmt, *args):
        note('access ' + self.ep + ' ' + (fmt % args))

    def reply(self, status, body, ctype='text/plain; charset=utf-8', extra=()):
        self.send_response(status)
        self.send_header('Content-Type', ctype)
        for k, v in extra:
            self.send_header(k, v)
        self.send_header('Content-Length', str(len(body)))
        self.send_header('Connection', 'close')
        self.end_headers()
        if self.command != 'HEAD':
            self.wfile.write(body)

    def any(self):
        length = int(self.headers.get('Content-Length') or 0)
        body = self.rfile.read(length) if length else None
        host = (self.headers.get('Host') or '').split(':')[0]
        path = self.path.split('?', 1)[0]
        routers, mws, svcs = load()
        best = None
        for name, r in routers.items():
            if self.ep not in r.get('entryPoints', []) or not matches(r['_ast'], host, path):
                continue
            prio = int(r.get('priority') or len(r['rule']))
            if best is None or prio > best[0]:
                best = (prio, name, r)
        if best is None:
            return self.reply(404, b'404 page not found\n')
        _, rname, r = best
        add = []
        for mname in r.get('middlewares', []):
            m = mws[mname]
            if 'redirectScheme' in m:
                c = m['redirectScheme']
                loc = '%s://%s%s' % (c.get('scheme', 'https'), host, self.path)
                return self.reply(301 if c.get('permanent') else 302, b'Moved Permanently',
                                  extra=[('Location', loc)])
            if 'basicAuth' in m:
                if not (self.headers.get('Authorization') or '').startswith('Basic '):
                    return self.reply(401, b'401 Unauthorized\n',
                                      extra=[('Www-Authenticate', 'Basic realm="%s"' % m['basicAuth'].get('realm', 'traefik'))])
            if 'rateLimit' in m:
                if not allowed((mname, self.client_address[0]), m['rateLimit']):
                    return self.reply(429, b'Too Many Requests\n')
            if 'headers' in m:
                c = m['headers']
                if c.get('frameDeny'): add.append(('X-Frame-Options', 'DENY'))
                if c.get('contentTypeNosniff'): add.append(('X-Content-Type-Options', 'nosniff'))
                if c.get('referrerPolicy'): add.append(('Referrer-Policy', c['referrerPolicy']))
                if c.get('stsSeconds') and self.ep == 'https':
                    add.append(('Strict-Transport-Security', 'max-age=%d' % int(c['stsSeconds'])))
                for k, v in (c.get('customResponseHeaders') or {}).items():
                    add.append((k, v))
        url = svcs[r['service']]['loadBalancer']['servers'][0]['url']
        m = re.match(r'http://([0-9.]+):(\d+)$', url)
        bhost, bport = m.group(1), int(m.group(2))
        if bhost == '172.17.0.1' and bport == 8099:
            bhost = '127.0.0.1'
        src = open(STATE + '/traefik.src').read().strip() if os.path.exists(STATE + '/traefik.src') else '127.0.0.1'
        hdrs = {k: v for k, v in self.headers.items()
                if not k.lower().startswith('x-forwarded-') and k.lower() not in ('connection', 'keep-alive', 'content-length')}
        hdrs['X-Forwarded-Proto'] = self.ep
        hdrs['X-Forwarded-For'] = self.client_address[0]
        hdrs['X-Forwarded-Host'] = host
        try:
            c = http.client.HTTPConnection(bhost, bport, timeout=30, source_address=(src if bhost == '127.0.0.1' else '', 0))
            c.request(self.command, self.path, body=body, headers=hdrs)
            resp = c.getresponse()
            data = resp.read()
        except Exception as e:
            note('error proxying %s to %s: %s' % (rname, url, e))
            return self.reply(502, b'Bad Gateway')
        replaced = {k.lower() for k, _ in add}
        self.send_response(resp.status)
        for k, v in resp.getheaders():
            if k.lower() in ('transfer-encoding', 'connection', 'content-length') or k.lower() in replaced:
                continue
            self.send_header(k, v)
        for k, v in add:
            self.send_header(k, v)
        self.send_header('Content-Length', str(len(data)))
        self.send_header('Connection', 'close')
        self.end_headers()
        if self.command != 'HEAD':
            self.wfile.write(data)

    do_GET = do_POST = do_DELETE = do_PUT = do_PATCH = do_HEAD = any

class Https(Proxy): ep = 'https'
class Http(Proxy): ep = 'http'

tls = http.server.ThreadingHTTPServer(('127.0.0.1', 443), Https)
ctx = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
ctx.load_cert_chain(H + '/tls-le.crt', H + '/tls-le.key')
tls.socket = ctx.wrap_socket(tls.socket, server_side=True)
plain = http.server.ThreadingHTTPServer(('127.0.0.1', 80), Http)
threading.Thread(target=plain.serve_forever, daemon=True).start()
note('fake traefik (operator-app) listening on 127.0.0.1:443 and :80')
tls.serve_forever()
