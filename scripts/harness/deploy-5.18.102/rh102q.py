#!/usr/bin/env python3
# 5.18.102's rehearsal: its own readers, beside the previous releases' (rh101.py, derived; rh99.py): the stand-in uCRM's
# record of one run by request shape and the limit values its list reads asked for; the run keys a run handed its probes
# (salt=…), from the fake docker's record; and the store's applications as 5.18.102's notes (QL-N3, QL-N4) and its
# rollback's refusal (QL-RB2) read them — written here from the rules the release and the script state, in Python, over
# the store opened read-only, so that the script's own reading is set against another. Each prints counts or states,
# never a name, a price or a key (the run keys go to a 0600 file named by the caller, never to stdout).
import sys, re, json, os, sqlite3, time, calendar


def read(p):
    try:
        return open(p, encoding='utf-8', errors='replace').read()
    except OSError:
        return ''


def posint(v):
    if isinstance(v, bool):
        return 0
    if isinstance(v, int):
        return v if v > 0 else 0
    if isinstance(v, str) and re.fullmatch(r'[1-9][0-9]{0,9}', v.strip()):
        return int(v.strip())
    return 0


cmd = sys.argv[1]
if cmd == 'u102req':
    # the stand-in uCRM's record of one run (requests.log: METHOD path[?keys] app-key|token|none), by shape: every request,
    # those that were not a GET, the catalogue's two lists by client (app key / token), the client attributes, a quote read
    # back by id, and any GET of a shape neither 5.18.99's reads nor 5.18.102's make (odd)
    lines = [l for l in read(sys.argv[2]).split('\n') if l.strip()]
    other = [l for l in lines if not l.startswith('GET ')]
    old = re.compile(r'^GET (/organizations|/clients/[0-9]+|/clients\?limit|/billing/quotes\?createdDateFrom) (app-key|token)$')
    c = {'products app-key': 0, 'products token': 0, 'service-plans app-key': 0, 'service-plans token': 0}
    attrs = quote = odd = 0
    for l in lines:
        if not l.startswith('GET '):
            continue
        m = re.match(r'^GET /(products|service-plans)\?limit (app-key|token)$', l)
        if m:
            c[m.group(1) + ' ' + m.group(2)] += 1
        elif re.match(r'^GET /custom-attributes app-key$', l):
            attrs += 1
        elif re.match(r'^GET /billing/quotes/[0-9]+ app-key$', l):
            quote += 1
        elif not old.match(l):
            odd += 1
    print('total=%d other=%d products=%d/%d plans=%d/%d attrs=%d quote=%d odd=%d' % (
        len(lines), len(other), c['products app-key'], c['products token'], c['service-plans app-key'],
        c['service-plans token'], attrs, quote, odd))
elif cmd == 'limits':
    # the stand-in's limits.log of one run: the limit value each list read asked for — n, and how many were not 500
    v = [l.strip() for l in read(sys.argv[2]).split('\n') if l.strip()]
    print('n=%d bad=%d' % (len(v), sum(1 for x in v if x not in ('products 500', 'service-plans 500'))))
elif cmd == 'salts':
    # the fake docker's record of one or more runs (\x1e-separated calls, \x1f-separated arguments): every distinct
    # salt=<value> argument appended to the 0600 file argv[2]; prints how many distinct values and the shortest length
    out = sys.argv[2]
    seen = set()
    for p in sys.argv[3:]:
        try:
            data = open(p, 'rb').read()
        except OSError:
            continue
        for rec in data.split(b'\x1e'):
            for a in rec.split(b'\x1f'):
                a = a.decode('utf-8', 'replace')
                if a.startswith('salt=') and len(a) > 5:
                    seen.add(a[5:])
    old = set(l for l in read(out).split('\n') if l)
    allv = sorted(seen | old)
    fd = os.open(out, os.O_WRONLY | os.O_CREAT | os.O_TRUNC, 0o600)
    os.write(fd, ''.join(x + '\n' for x in allv).encode())
    os.close(fd)
    print('%d %d' % (len(allv), min((len(x) for x in allv), default=0)))
elif cmd == 'apps':
    # the store's applications (kyc_applications, read-only): QL-N3's cells — <status>.<marked|unmarked>.<pid|nopid|none>.
    # <cash|credit>:n over the pending and review ones; QL-N4's counts — the last 90 days by submitted_at, of them an
    # existing customer's, and those with no date; and QL-RB2's — pending for uCRM with no uCRM client, no quote made, no
    # lines and no plan price; the same in review or failed; refused P1–P3 with a uCRM client. JSON, counts only.
    con = sqlite3.connect('file:' + sys.argv[2] + '?mode=ro', uri=True)
    rows = []
    for (d,) in con.execute('SELECT data FROM kyc_applications'):
        try:
            r = json.loads(d)
        except (TypeError, ValueError):
            continue
        if isinstance(r, dict):
            rows.append(r)
    con.close()
    n3 = {}
    for r in rows:
        st = r.get('crm_sync_status')
        st = '' if st is None else str(st)
        if st not in ('pending', 'review'):
            continue
        it = r.get('quote_items')
        if isinstance(it, str):
            try:
                it = json.loads(it)
            except ValueError:
                it = None
        sh = 'none'
        if isinstance(it, (list, dict)) and it:
            sh = 'nopid'
            for i in (it.values() if isinstance(it, dict) else it):
                if isinstance(i, dict) and posint(i.get('productId')) > 0:
                    sh = 'pid'
                    break
        cell = '%s.%s.%s.%s' % (st, 'marked' if r.get('quote_lines_source') == 'ucrm-5.18.102' else 'unmarked', sh,
                                'cash' if r.get('sales_type') == 'Cash' else 'credit')
        n3[cell] = n3.get(cell, 0) + 1
    since = time.time() - 90 * 86400
    n4 = {'all': 0, 'existing': 0, 'nodate': 0}
    for r in rows:
        v = r.get('submitted_at')
        t = None
        if isinstance(v, str):
            try:
                t = calendar.timegm(time.strptime(v[:19], '%Y-%m-%d %H:%M:%S'))
            except ValueError:
                t = None
        if t is None:
            n4['nodate'] += 1
            continue
        if t < since:
            continue
        n4['all'] += 1
        if r.get('crm_client_type') == 'existing' or bool(r.get('is_additional_service')):
            n4['existing'] += 1

    def no_client(v):
        return v is None or v in ('', 0, '0')

    def unpriced(r):
        q = r.get('quote_created')
        return (no_client(r.get('crm_client_id')) and (q is None or q in (0, '', '0'))
                and r.get('quote_items') is None and r.get('offer_price') is None)

    up = sum(1 for r in rows if r.get('crm_sync_status') == 'pending' and unpriced(r))
    um = sum(1 for r in rows if r.get('crm_sync_status') in ('review', 'failed') and unpriced(r))
    u1 = sum(1 for r in rows if r.get('quote_refused') in ('P1', 'P2', 'P3') and not no_client(r.get('crm_client_id')))
    print(json.dumps({'n3': ','.join('%s:%d' % (k, n3[k]) for k in sorted(n3)) or 'none', 'all': n4['all'],
                      'existing': n4['existing'], 'nodate': n4['nodate'], 'unp': 'pending:%d,manual:%d,p1:%d' % (up, um, u1)}))
elif cmd == 'kv':
    # one field of a JSON object given as text: kv JSON KEY
    print(json.loads(sys.argv[2])[sys.argv[3]])
else:
    print('?' + cmd)
    sys.exit(2)
