#!/usr/bin/env python3
"""
policies/build.py — builds the site's nine policy pages from policies/bodies/.

The policy pages share one source of truth for every number a customer relies on (PARAMS below): a warranty period,
the return window, refund timing, notice periods. Change a number here, run this, and every page that states it
changes together. The FAQ and the kits and shop pages repeat a few of these numbers in their own words; this script
refuses to finish if they no longer agree, rather than leaving the site contradicting itself.

Each page keeps terms.html's own head, header and footer, so the policy pages look like the rest of the site.

    python3 policies/build.py           build the nine pages into site/
    python3 policies/build.py --check   change nothing; exit 1 if a page on disk differs from what this would build
                                        (a hand edit to site/<page>.html that the bodies do not carry)

The text is not legally approved. See the repository's docs/67 for the review it still needs.
"""
import io, os, re, sys, html as H

HERE = os.path.dirname(os.path.abspath(__file__))
SITE = os.path.join(os.path.dirname(HERE), 'site')
BODIES = os.path.join(HERE, 'bodies')

# Every number a customer relies on lives here once, so a business decision changes one line.
PARAMS = {
    'KIT_WARRANTY':   '12 months',      # Starlink kits, from the day the customer receives the kit
    'WORKMANSHIP':    '6 months',       # our installation work, from completion
    'OTHER_WARRANTY': '6 months',       # other equipment where no manufacturer period is shown
    'RETURN_DAYS':    '7 days',         # change of mind, from receipt
    'REFUND_DAYS':    '14 days',        # refund paid after the item comes back
    'PRICE_NOTICE':   '30 days',        # notice of a change that is worse for the customer
    'OUTAGE':         '3 days',         # fault after which service credits start
    'ACK':            '1 working day',  # complaint and warranty-claim acknowledgement
    'RESOLVE':        '14 days',        # final written answer to a complaint
}
UPDATED = 'Last updated: 12 October 2026'

PAGES = [
    ('policies.html', 'Policies', 'Legal',
     'DishNet Uganda policies in plain words: warranty, returns and refunds, terms of service and sale, installation, acceptable use, privacy and complaints.'),
    ('terms.html', 'Terms of Service', 'Legal',
     'Terms of Service for DishNet Africa Limited internet plans in Uganda: billing, suspension, cancelling, equipment, fair use and our responsibility.'),
    ('terms-of-sale.html', 'Terms of Sale', 'Legal',
     'Terms of Sale for Starlink kits, accessories, routers and CCTV equipment bought from DishNet Africa Limited in Uganda.'),
    ('warranty.html', 'Warranty Policy', 'Legal',
     'DishNet Uganda warranty: Starlink kits, installation workmanship, accessories and CCTV, what is covered and how to claim.'),
    ('returns.html', 'Returns, Refunds and Cancellations', 'Legal',
     'How to return equipment, cancel an order, installation or monthly plan with DishNet Uganda, and how refunds are paid.'),
    ('installation.html', 'Starlink Installation', 'Legal',
     'How DishNet Uganda installation jobs are approved, what a standard Starlink installation includes, and the cancellation rules.'),
    ('acceptable-use.html', 'Acceptable Use and Fair Use', 'Legal',
     'Acceptable Use and Fair Use Policy for DishNet Uganda internet services, including Starlink-based plans and fiber.'),
    ('privacy.html', 'Privacy Policy', 'Legal',
     'How DishNet Africa Limited collects, uses, shares and protects personal information in Uganda, and your rights.'),
    ('complaints.html', 'Complaints', 'Legal',
     'How to complain to DishNet Uganda, how fast we respond, and how to escalate to the Uganda Communications Commission.'),
]

# What the other pages say in their own words, built from the same numbers, and how many times each page says it
# (the FAQ says each answer twice: on the page and in its structured data). A change to PARAMS that leaves one of
# these behind stops the build: edit that page's sentence, then run this again.
def others():
    p = PARAMS
    return [
        ('faq.html', 'Starlink kits: %s from the day you receive the kit' % p['KIT_WARRANTY'], 2),
        ('faq.html', 'Our installation work: %s.' % p['WORKMANSHIP'], 2),
        ('faq.html', 'return it within %s of receiving it' % p['RETURN_DAYS'], 2),
        ('faq.html', 'Refunds are paid within %s, back to the way you paid' % p['REFUND_DAYS'], 2),
        ('faq.html', 'or %s where none is shown' % p['OTHER_WARRANTY'], 2),
        ('starlink-kits.html', '%s returns</a>' % p['RETURN_DAYS'].replace(' days', '-day'), 1),
        ('terms-of-sale.html', '%s returns</a>' % p['RETURN_DAYS'].replace(' days', '-day'), 1),
    ]

STYLE = '''<style>
.policy{max-width:820px;color:var(--text-secondary);font-size:15px;line-height:1.85;}
.policy h3{color:var(--text);font-family:var(--font-display);font-size:20px;margin:30px 0 8px;scroll-margin-top:110px;}
.policy h4{color:var(--text);font-family:var(--font-display);font-size:16px;margin:18px 0 6px;}
.policy p{margin:0 0 12px;}
.policy ul,.policy ol{margin:0 0 14px 22px;}
.policy li{margin:0 0 6px;}
.policy a{color:var(--accent);}
.policy strong{color:var(--text);}
.policy .summary{background:var(--surface);border:1px solid var(--border);border-left:4px solid var(--accent);border-radius:var(--radius-sm);padding:16px 20px;margin:0 0 22px;}
.policy .summary p:last-child,.policy .summary ul:last-child{margin-bottom:0;}
.policy .toc{background:var(--surface-alt);border-radius:var(--radius-sm);padding:14px 20px;margin:0 0 22px;}
.policy .toc ol{margin:6px 0 0 20px;}
.policy table{width:100%;border-collapse:collapse;margin:6px 0 16px;font-size:14px;}
.policy th,.policy td{border:1px solid var(--border);padding:8px 10px;text-align:left;vertical-align:top;}
.policy th{background:var(--surface-alt);color:var(--text);font-weight:600;}
.policy .meta{font-size:13px;}
.policy .cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:14px;margin:8px 0 18px;}
.policy .card{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius-sm);padding:16px 18px;}
.policy .card h3{font-size:16px;margin:0 0 6px;}
.policy .card p{font-size:14px;margin:0 0 8px;}
@media (max-width:600px){.policy table{font-size:13px}.policy th,.policy td{padding:6px}}
</style>
'''

def die(msg):
    sys.exit('policies/build.py: ' + msg)

def one(pat, s, label):
    m = list(re.finditer(pat, s, re.S))
    if len(m) != 1: die('anchor %s: found %d, want 1' % (label, len(m)))
    return m[0]

def template():
    t = io.open(os.path.join(SITE, 'terms.html'), encoding='utf-8').read()
    head_end = one(r'</head>', t, 'head').start()
    head = t[:head_end]
    # terms.html is itself built by this script: take its own style block back out, so a rebuild adds it once. Any
    # earlier version of the block is taken out too, so that changing STYLE does not strand the old one.
    old = re.findall(r'<style>\n\.policy\{.*?</style>\n', head, re.S)
    if len(old) > 1 or (not old and '.policy{' in head): die('terms.html carries a policy style block this script did not write')
    head = head.replace(old[0], '') if old else head
    hero = one(r'<section class="ug-hero".*?</section>\n<section class="section-sm".*?</section>\n', t, 'hero+body')
    return head, t[head_end:hero.start()], t[one(r'\n<footer class="footer">', t, 'footer').start():]

def set_meta(head, slug, title, desc):
    full = '%s — DishNet Uganda' % title
    e = H.escape
    for pat, new in [
        (r'<title>[^<]*</title>', '<title>%s</title>' % e(full, quote=False)),
        (r'<meta name="description" content="[^"]*">', '<meta name="description" content="%s">' % e(desc)),
        (r'<link rel="canonical" href="[^"]*">', '<link rel="canonical" href="https://dishnetuganda.com/%s">' % slug),
        (r'<meta property="og:title" content="[^"]*">', '<meta property="og:title" content="%s">' % e(full)),
        (r'<meta property="og:description" content="[^"]*">', '<meta property="og:description" content="%s">' % e(desc)),
        (r'<meta property="og:url" content="[^"]*">', '<meta property="og:url" content="https://dishnetuganda.com/%s">' % slug),
        (r'<meta name="twitter:title" content="[^"]*">', '<meta name="twitter:title" content="%s">' % e(full)),
        (r'<meta name="twitter:description" content="[^"]*">', '<meta name="twitter:description" content="%s">' % e(desc)),
    ]:
        if len(re.findall(pat, head)) != 1: die('meta %s %s' % (slug, pat))
        head = re.sub(pat, lambda m: new, head)
    return head + STYLE

def render(body):
    for k, v in PARAMS.items():
        body = body.replace('{{%s}}' % k, v)
    left = re.findall(r'\{\{[A-Z_]+\}\}', body)
    if left: die('unfilled %s' % sorted(set(left)))
    return body

def build():
    head, header, footer = template()
    out = {}
    for slug, title, badge, desc in PAGES:
        body = render(io.open(os.path.join(BODIES, slug), encoding='utf-8').read()).strip('\n')
        page = (set_meta(head, slug, title, desc) + header +
                '<section class="ug-hero" style="padding:150px 0 40px;">\n  <div class="container">\n'
                '    <span class="badge-label badge-accent">%s</span>\n    <h2 class="section-title">%s</h2>\n'
                '    <p class="section-subtitle" >%s</p>\n  </div>\n</section>\n'
                '<section class="section-sm" style="padding-top:20px;">\n  <div class="container policy">\n%s\n  </div>\n</section>\n'
                % (badge, H.escape(title, quote=False), UPDATED, body) + footer)
        if page.count('<h2 class="section-title">') != 1 or page.count('</html>') != 1 or page.count(STYLE) != 1:
            die('shape %s' % slug)
        out[slug] = page
    return out

def faq_pairs():
    """The FAQ's visible questions and answers, and its FAQPage structured data, as two lists of (question, answer)."""
    import json
    s = io.open(os.path.join(SITE, 'faq.html'), encoding='utf-8').read()
    vis = [(H.unescape(q), re.sub(r'\s+', ' ', H.unescape(re.sub(r'<[^>]+>', '', a))).strip()) for q, a in re.findall(
        r'<button class="faq-q" onclick="fq\(this\)">([^<]+)</button><div class="faq-a"><div class="faq-a-inner">(.*?)</div></div></div>', s)]
    data = []
    for m in re.findall(r'<script type="application/ld\+json">(.*?)</script>', s, re.S):
        d = json.loads(m)
        if isinstance(d, dict) and d.get('@type') == 'FAQPage':
            data += [(q['name'], re.sub(r'\s+', ' ', q['acceptedAnswer']['text']).strip()) for q in d['mainEntity']]
    return vis, data

def main():
    check = '--check' in sys.argv[1:]
    pages = build()
    stale = []
    for slug, page in pages.items():
        path = os.path.join(SITE, slug)
        have = io.open(path, encoding='utf-8').read() if os.path.exists(path) else None
        if have != page:
            stale.append(slug)
            if not check: io.open(path, 'w', encoding='utf-8').write(page)
    gaps = [(f, s, n) for f, s, n in others() if io.open(os.path.join(SITE, f), encoding='utf-8').read().count(s) != n]
    for f, s, n in gaps: print('  out of step: %s should say %d time(s): %s' % (f, n, s))
    import glob
    for f in sorted(glob.glob(os.path.join(SITE, '**', '*.html'), recursive=True)):
        t = io.open(f, encoding='utf-8').read()
        if os.path.basename(f) == '404.html' or 'footer-links' not in t: continue
        if 'policies.html">All Policies</a>' not in t or 'privacy.html">Privacy Policy</a>' not in t or '>Terms of Use</a>' in t:
            gaps.append((os.path.relpath(f, SITE), 'footer', 0))
            print('  out of step: %s — its footer lacks the policy links' % os.path.relpath(f, SITE))
    vis, data = faq_pairs()
    if not vis or vis != data:
        gaps.append(('faq.html', 'the FAQPage structured data', 0))
        print('  out of step: faq.html — its structured data does not carry the visible questions and answers word for word')
        for i, (a, b) in enumerate(zip(vis, data)):
            if a != b: print('    first difference at question %d: %r' % (i + 1, (a[0], b[0]) if a[0] != b[0] else a[0])); break
        if len(vis) != len(data): print('    %d visible, %d in the structured data' % (len(vis), len(data)))
    if check:
        for s in stale: print('  differs from its body: site/%s' % s)
        print('policies: %s' % ('up to date' if not stale and not gaps else 'NOT up to date'))
        sys.exit(1 if stale or gaps else 0)
    print('policies: %d page(s) written%s' % (len(stale), (': ' + ', '.join(stale)) if stale else ''))
    if gaps: die('the pages above repeat a policy number that no longer matches PARAMS')

if __name__ == '__main__':
    main()
