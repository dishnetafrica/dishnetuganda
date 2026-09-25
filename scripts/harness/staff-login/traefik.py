"""Fake Traefik for the staff-login harness.

TLS on 127.0.0.1:443. Re-reads /etc/easypanel/traefik/config/dnb-staging.yml on
every request, as Traefik's file provider does after a reload: while the router
names dnb-staging-auth, a request without Basic credentials gets Traefik's plain
401. Otherwise the request is proxied to 127.0.0.1:8099 FROM the address in
state/traefik.src (so the API's REMOTE_ADDR can differ from the loopback
probes'), with X-Forwarded-Proto: https added unless state/traefik.dropxfp
exists. Client-supplied X-Forwarded-* headers are stripped, as Traefik does.
"""
import http.client, http.server, os, ssl, sys, time, yaml

H = os.environ['HSIM']
ROUTE = '/etc/easypanel/traefik/config/dnb-staging.yml'
STATE = H + '/state'

def note(msg):
    with open(STATE + '/traefik.log', 'a') as f:
        f.write(time.strftime('%H:%M:%S ') + msg + '\n')

class Proxy(http.server.BaseHTTPRequestHandler):
    protocol_version = 'HTTP/1.1'
    def log_message(self, fmt, *args):
        note('access ' + (fmt % args))

    def reply(self, status, body, ctype='text/plain; charset=utf-8', extra=()):
        self.send_response(status)
        self.send_header('Content-Type', ctype)
        for k, v in extra:
            self.send_header(k, v)
        self.send_header('Content-Length', str(len(body)))
        self.send_header('Connection', 'close')
        self.end_headers()
        self.wfile.write(body)

    def any(self):
        length = int(self.headers.get('Content-Length') or 0)
        body = self.rfile.read(length) if length else None
        try:
            with open(ROUTE) as f:
                d = yaml.safe_load(f)
            router = d['http']['routers']['dnb-staging']
            mws = router.get('middlewares', [])
            for m in mws:
                if m not in d['http']['middlewares']:
                    raise ValueError('middleware %s not defined' % m)
        except Exception as e:  # Traefik keeps no route for an invalid file
            note('error loading route: %s' % e)
            return self.reply(404, b'404 page not found\n')
        host = (self.headers.get('Host') or '').split(':')[0]
        if host != 'portal-staging.dishnetuganda.com':
            return self.reply(404, b'404 page not found\n')
        if 'dnb-staging-auth' in mws and not (self.headers.get('Authorization') or '').startswith('Basic '):
            return self.reply(401, b'401 Unauthorized\n', extra=[('Www-Authenticate', 'Basic realm="DishNet staging"')])
        src = open(STATE + '/traefik.src').read().strip() if os.path.exists(STATE + '/traefik.src') else '127.0.0.1'
        hdrs = {k: v for k, v in self.headers.items()
                if not k.lower().startswith('x-forwarded-') and k.lower() not in ('connection', 'keep-alive', 'content-length')}
        if not os.path.exists(STATE + '/traefik.dropxfp'):
            hdrs['X-Forwarded-Proto'] = 'https'
        hdrs['X-Forwarded-For'] = self.client_address[0]
        hdrs['X-Forwarded-Host'] = host
        try:
            c = http.client.HTTPConnection('127.0.0.1', 8099, timeout=30, source_address=(src, 0))
            c.request(self.command, self.path, body=body, headers=hdrs)
            r = c.getresponse()
            data = r.read()
        except Exception as e:
            note('error proxying: %s' % e)
            return self.reply(502, b'Bad Gateway')
        self.send_response(r.status)
        for k, v in r.getheaders():
            if k.lower() in ('transfer-encoding', 'connection', 'content-length'):
                continue
            self.send_header(k, v)
        self.send_header('Content-Length', str(len(data)))
        self.send_header('Connection', 'close')
        self.end_headers()
        self.wfile.write(data)

    do_GET = do_POST = do_DELETE = do_PUT = do_PATCH = do_HEAD = any

srv = http.server.ThreadingHTTPServer(('127.0.0.1', 443), Proxy)
ctx = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
ctx.load_cert_chain(H + '/tls.crt', H + '/tls.key')
srv.socket = ctx.wrap_socket(srv.socket, server_side=True)
note('fake traefik listening on 127.0.0.1:443')
srv.serve_forever()
