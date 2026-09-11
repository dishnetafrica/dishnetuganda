#!/usr/bin/env python3
"""
verify-address.py — the website states one address, and it is the right one.

The office moved to Acacia Mall and the site still said Mawanda Road in 39
places: a footer line, a contact block, and the schema.org record on all 36
pages. Search engines read that last one, so the wrong address was not just
displayed, it was published as structured data.

One page (404.html) was worse: built from the Sudan template and never
converted. It was branded "DishNet SUDAN" on a Uganda domain, declared
addressCountry SD and geo.region SD, and served an areaServed of "SD".

So this asserts what the whole site must agree on, rather than trusting a
find-and-replace to have reached every copy:

  - no trace of the old address anywhere
  - every LocalBusiness record carries the SAME address, phone and town list
  - the country is UG and the branding is not Sudan's
  - every JSON-LD block still parses, because an address edit that breaks
    the structured data silently removes the business from search results

Run from the repo root:  python3 dishnet-web-uganda/verify-address.py
"""
import collections
import glob
import io
import json
import os
import re
import sys

SITE = os.path.join(os.path.dirname(os.path.abspath(__file__)), 'site')

# Approved and identical to the plugin's knowledge base, so what the website
# says and what the assistant tells a customer on WhatsApp cannot diverge.
STREET = '4th floor, Acacia Mall, 14-18 Cooper Road, office TT06'
TOWN = 'Kampala'
COUNTRY = 'UG'
PHONE = '+256 705 993 348'

# Anything that means the office is somewhere it no longer is, or that the
# Uganda site is still wearing Sudan's clothes.
FORBIDDEN = [
    ('mawanda', 'the old street'),
    ('family shoppers super market', 'the old landmark'),
    ('police station', 'the old landmark'),
    ('dishnet<small>sudan</small>', 'Sudan branding on the Uganda site'),
    ('content="sd"', 'geo.region claiming Sudan'),
    ('"addresscountry": "sd"', 'structured data claiming Sudan'),
]

fail = []
def check(ok, msg, detail=''):
    if ok:
        print('  ok   ' + msg)
    else:
        fail.append(msg)
        print('  FAIL ' + msg + (('\n       ' + detail) if detail else ''))

pages = sorted(glob.glob(os.path.join(SITE, '**', '*.html'), recursive=True))
print('\n%d pages\n' % len(pages))

print('Nothing points at the old office')
for needle, what in FORBIDDEN:
    hits = [os.path.relpath(p, SITE) for p in pages
            if needle in io.open(p, encoding='utf-8').read().lower()]
    check(not hits, 'no %s' % what, 'still in: ' + ', '.join(hits[:6]))

print('\nEvery page publishes the same business record')
addr, tel, area, broken, blocks = (collections.Counter(), collections.Counter(),
                                   collections.Counter(), [], 0)
for p in pages:
    s = io.open(p, encoding='utf-8').read()
    for m in re.finditer(r'<script type="application/ld\+json">(.*?)</script>', s, re.S):
        try:
            d = json.loads(m.group(1))
        except Exception as e:
            broken.append('%s: %s' % (os.path.relpath(p, SITE), e))
            continue
        blocks += 1
        for node in (d if isinstance(d, list) else [d]):
            if not isinstance(node, dict) or node.get('@type') != 'LocalBusiness':
                continue
            a = node.get('address') or {}
            addr[(a.get('streetAddress'), a.get('addressLocality'), a.get('addressCountry'))] += 1
            tel[node.get('telephone')] += 1
            area[len(node.get('areaServed') or [])] += 1

check(not broken, '%d JSON-LD blocks parse' % blocks,
      'an address edit that breaks these removes the business from search: '
      + '; '.join(broken[:3]))
check(len(addr) == 1, 'one address across the site',
      'found %d: %s' % (len(addr), list(addr)))
check(addr and list(addr)[0] == (STREET, TOWN, COUNTRY), 'and it is the Acacia Mall one',
      'found: %s' % (list(addr)[0] if addr else None,))
check(len(tel) == 1 and list(tel)[0] == PHONE, 'one phone number, and it is ours',
      'found: %s' % dict(tel))
check(len(area) == 1, 'one coverage list', 'sizes found: %s' % dict(area))

print('\nThe visible address agrees with the published one')
prose = collections.Counter()
for p in pages:
    for m in re.finditer(r'(?:footer-address"|<p>)([^<]{0,200})', io.open(p, encoding='utf-8').read()):
        if 'Acacia Mall' in m.group(1):
            prose[m.group(1).strip()] += 1
check(all('Acacia Mall' in t and 'Cooper Road' in t for t in prose),
      '%d visible address line(s), all naming Acacia Mall and Cooper Road' % sum(prose.values()),
      'found: %s' % list(prose))

print('\n%s\n' % ('FAILED: ' + '; '.join(fail) if fail else 'The site agrees with itself.'))
sys.exit(1 if fail else 0)
