#!/usr/bin/env python3
# 5.18.102's rehearsal: its own readers, beside the previous releases' (rh101.py, derived; rh99.py): the stand-in uCRM's
# record of one run by request shape and the limit values its list reads asked for; the run keys a run handed its probes
# (salt=…, the QL key among them), from the fake docker's record; the store's applications as 5.18.102's notes (QL-N3,
# QL-N4), QL-LK1's window and its rollback's checks (QL-RB2, QL-RB3) read them; and the uCRM link store (D14) as QL-G1,
# QL-LK1 and QL-RB3 count it — written here from the rules the release and the script state, in Python, over the store
# opened read-only, so that the script's own reading is set against another. Each prints counts or states, never a name,
# a price or a key (the run keys go to a 0600 file named by the caller, never to stdout; the QL key file is only
# checked for its shape, never read out).
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
    # existing customer's, and those with no date; QL-RB2's — pending for uCRM with no uCRM client, no quote made, no
    # lines and no plan price; the same in review or failed; refused P1–P3 with a uCRM client; and QL-N3's D11 count (d11,
    # below: the applications the retry cannot price after the deploy whatever uCRM holds). JSON, counts only.
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

    # QL-N3's second count (review 2, L1; docs/71 D11, §F.4; D14 E-1, §R.7.1), written here from the release's own rules,
    # not from the script's probe: KycCrmSync::ugQuote asks nothing of an application whose quotation was made (empty() in
    # PHP); KycService::retryLines then applies the entry rule FIRST (saleFromApp's entry is 'web' only where
    # quote_sale_entry is exactly the string 'web'), before marked lines are re-confirmed and before the Fiber check — so
    # every pending or review application with no quotation made and quote_sale_entry not exactly 'web' gets no lines,
    # marked or not, whatever its type, connection or "Who installs?" answer: P2 for a Cash sale with cash collected
    # (round(amount_charged, 2) > 0, PHP's (float)), P1 otherwise. Counts only.
    def php_empty(v):
        return v is None or v is False or v == 0 and not isinstance(v, str) or v in ('', '0') or (isinstance(v, (list, dict)) and not v)

    def php_float(v):
        if isinstance(v, bool):
            return 1.0 if v else 0.0
        if isinstance(v, (int, float)):
            return float(v)
        if isinstance(v, str):
            m = re.match(r'\s*[+-]?(\d+(\.\d*)?|\.\d+)([eE][+-]?\d+)?', v)
            return float(m.group(0)) if m else 0.0
        return 0.0

    d11 = {'pending': 0, 'review': 0, 'p1': 0, 'p2': 0}
    for r in rows:
        st = r.get('crm_sync_status')
        st = '' if st is None else str(st)
        if st not in ('pending', 'review'):
            continue
        if not php_empty(r.get('quote_created')) or r.get('quote_sale_entry') == 'web':
            continue
        d11[st] += 1
        d11['p2' if r.get('sales_type') == 'Cash' and round(php_float(r.get('amount_charged', 0)), 2) > 0 else 'p1'] += 1
    print(json.dumps({'n3': ','.join('%s:%d' % (k, n3[k]) for k in sorted(n3)) or 'none', 'all': n4['all'],
                      'existing': n4['existing'], 'nodate': n4['nodate'], 'unp': 'pending:%d,manual:%d,p1:%d' % (up, um, u1),
                      'd11': 'pending:%d,review:%d,p1:%d,p2:%d' % (d11['pending'], d11['review'], d11['p1'], d11['p2'])}))
elif cmd == 'lk':
    # the uCRM link store (D14, the table ucrm_quote_links), read-only, as QL-G1's and QL-LK1's qllinks and QL-RB3's
    # qlrb3 count it: lk DB [COPYAT] — "absent" when the table was never made; else records, active (ended_at_ts unset or
    # null, the release's active marker), two (items with two or more active records — the release never writes two) and,
    # with COPYAT, ended (records whose ended_at_ts is at or after it, never by ended_at, QL-LK1's qlended). Counts only.
    con = sqlite3.connect('file:' + sys.argv[2] + '?mode=ro', uri=True)
    if not con.execute("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'ucrm_quote_links'").fetchone():
        print('absent')
        sys.exit(0)
    recs = []
    for (d,) in con.execute('SELECT data FROM ucrm_quote_links'):
        try:
            r = json.loads(d)
        except (TypeError, ValueError):
            continue
        if isinstance(r, dict):
            recs.append(r)
    con.close()
    act = {}
    for r in recs:
        if r.get('ended_at_ts') is None and isinstance(r.get('item'), str) and str(r.get('row_id', '')).lstrip('-').isdigit():
            k = r['item'] + '|' + str(int(r['row_id']))
            act[k] = act.get(k, 0) + 1
    out = 'records:%d,active:%d,two:%d' % (len(recs), sum(act.values()), sum(1 for v in act.values() if v >= 2))
    if len(sys.argv) > 3 and sys.argv[3].isdigit():
        c = int(sys.argv[3])
        out += '|ended:%d' % sum(1 for r in recs if isinstance(r.get('ended_at_ts'), int) and not isinstance(r.get('ended_at_ts'), bool) and r['ended_at_ts'] >= c)
    print(out)
elif cmd in ('win', 'unconf', 'appmax'):
    # the applications, read-only: win DB APPMAX — QL-LK1's window by the release's rule (KycService::canMakeQuotation's
    # five conditions, written out: quote_sale_entry exactly 'web', a uCRM client, quote_refused 'P1', no quote_created,
    # no quote_found_in_ucrm) over the ids above APPMAX — never by submitted_at — and, apart, those not from the web form
    # (quote_sale_entry not exactly 'web') and those with quote_found_in_ucrm; unconf DB — QL-RB3's §R.17.3 count:
    # quote_unconfirmed_at or quote_make_claim_ts set (not null) and no quotation recorded (quote_created 0, '', '0' or
    # none); appmax DB — the highest id, as SqliteStore::nextId's MAX(id). Counts only.
    con = sqlite3.connect('file:' + sys.argv[2] + '?mode=ro', uri=True)
    got = con.execute('SELECT id, data FROM kyc_applications ORDER BY id').fetchall()
    con.close()

    def php_empty(v):
        return v is None or v is False or v == 0 and not isinstance(v, str) or v in ('', '0') or (isinstance(v, (list, dict)) and not v)

    if cmd == 'appmax':
        print(max([i for (i, _) in got], default=0))
    elif cmd == 'unconf':
        n = 0
        for (_, d) in got:
            try:
                r = json.loads(d)
            except (TypeError, ValueError):
                continue
            if not isinstance(r, dict):
                continue
            if (r.get('quote_unconfirmed_at') is not None or r.get('quote_make_claim_ts') is not None) and r.get('quote_created') in (None, 0, '', '0'):
                n += 1
        print(n)
    else:
        mx = int(sys.argv[3])
        w = {'make': 0, 'app': 0, 'found': 0}
        for (i, d) in got:
            if i <= mx:
                continue
            try:
                r = json.loads(d)
            except (TypeError, ValueError):
                continue
            if not isinstance(r, dict):
                continue
            if (r.get('quote_sale_entry') == 'web' and not php_empty(r.get('crm_client_id')) and r.get('quote_refused') == 'P1'
                    and php_empty(r.get('quote_created')) and php_empty(r.get('quote_found_in_ucrm'))):
                w['make'] += 1
            if r.get('quote_sale_entry') != 'web':
                w['app'] += 1
            if not php_empty(r.get('quote_found_in_ucrm')):
                w['found'] += 1
        print('make:%d,app:%d,found:%d' % (w['make'], w['app'], w['found']))
elif cmd == 'keychk':
    # the QL key file (D14 derivation M-1) beside the state file: keychk KEYFILE STATE… — its mode, whether it is exactly
    # one line QL_KEY=<48 hex>, and whether that key appears in any of the state files given (it must not). Prints
    # "<mode>|<oneline|bad>|<absent|in:N>" — never the key.
    kf = sys.argv[2]
    try:
        st = os.stat(kf)
        raw = open(kf, 'rb').read().decode('utf-8', 'replace')
    except OSError:
        print('nofile')
        sys.exit(0)
    m = re.fullmatch(r'QL_KEY=([0-9a-f]{48})\n?', raw)
    hits = 0
    if m:
        for f in sys.argv[3:]:
            if m.group(1) in read(f):
                hits += 1
    print('%o|%s|%s' % (st.st_mode & 0o777, 'oneline' if m else 'bad', ('absent' if hits == 0 else 'in:%d' % hits) if m else '-'))
elif cmd == 'kv':
    # one field of a JSON object given as text: kv JSON KEY
    print(json.loads(sys.argv[2])[sys.argv[3]])
else:
    print('?' + cmd)
    sys.exit(2)
