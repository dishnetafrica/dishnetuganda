#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
starlink_usage_parity.py — READ-ONLY usage parity: official API vs cookie Data Report.

Compares official-API data usage (POST /data-usage/query — used strictly as a
documented READ/QUERY) against the existing cookie-based sl_usage.json for the ONE
account the credential can see. Proves usage parity before any migration.

SAFETY
  * credentials from ENVIRONMENT VARIABLES ONLY; never printed.
  * The ONLY POSTs are (a) the OAuth token mint and (b) /data-usage/query — a query
    endpoint that returns usage and has no mutating body fields. No other POST, and
    no PUT/PATCH/DELETE code path exists. The tool sends only a filter/pagination
    body; it never sends a device/service field.
  * local sl_usage.json is opened read-only ('r'); no file-write code anywhere.
  * no raw API responses are saved; identifiers are pseudonymised in output (GB
    numbers are shown rounded — they are your own usage figures, not secrets).
  * refuses to run without SL_PROBE_CONFIRM=yes. One-off diagnostic; do not deploy.

If /data-usage/query rejects the default body (HTTP 422 = input validation, NOT a
mutation), the tool prints the (redacted) validation message so the exact request
schema can be set via SL_USAGE_BODY='{...}' and re-run. A 422 changes nothing.

USAGE (on the server)
  export SL_CLIENT_ID='...'; read -rs SL_CLIENT_SECRET && export SL_CLIENT_SECRET
  export SL_PROBE_CONFIRM=yes
  # optional overrides:
  #   SL_DR_DATA=.../dishnet-data-report/data   (for sl_usage.json)
  #   SL_USAGE_BODY='{"serviceLinesFilter":{"serviceLineNumbers":["SL-..."]}}'
  python3 starlink_usage_parity.py
"""
import os
import sys
import json
import time
import datetime
import urllib.request
import urllib.error
import urllib.parse

WELL_KNOWN = os.environ.get("SL_WELL_KNOWN", "https://starlink.com/api/auth/.well-known/openid-configuration")
BASE = os.environ.get("SL_BASE", "https://starlink.com/api/public/v2").rstrip("/")
CLIENT_ID = os.environ.get("SL_CLIENT_ID")
CLIENT_SECRET = os.environ.get("SL_CLIENT_SECRET")
BEARER = os.environ.get("SL_BEARER")
SCOPE = os.environ.get("SL_SCOPE")
AUDIENCE = os.environ.get("SL_AUDIENCE")
TOKEN_AUTH = os.environ.get("SL_TOKEN_AUTH", "body").lower()
CONFIRM = os.environ.get("SL_PROBE_CONFIRM", "")
TIMEOUT = float(os.environ.get("SL_TIMEOUT", "30"))
SLEEP = float(os.environ.get("SL_SLEEP", "0.4"))
MAX_PAGES = int(os.environ.get("SL_MAX_PAGES", "20"))
USAGE_BODY = os.environ.get("SL_USAGE_BODY", "{}")
TOL = float(os.environ.get("SL_GB_TOL", "0.1"))  # GB tolerance for EXACT

_BASE = "/home/unms/data/ucrm/ucrm/data/plugins"
DR_DATA = os.environ.get("SL_DR_DATA", _BASE + "/dishnet-data-report/data")

TOKEN_ENDPOINT = None
_TOKEN = None
USAGE_PATH = "/data-usage/query"
CALLS = 0

_pseudo, _ctr = {}, {}
def pz(kind, v):
    if v in (None, ""): return v
    k = (kind, str(v))
    if k not in _pseudo:
        _ctr[kind] = _ctr.get(kind, 0) + 1
        _pseudo[k] = "%s-%03d" % (kind, _ctr[kind])
    return _pseudo[k]

def _do(method, url, headers=None, data=None):
    global CALLS
    if method == "POST":
        if url != TOKEN_ENDPOINT and url != BASE + USAGE_PATH:
            raise RuntimeError("SAFETY: POST only to token endpoint or the usage query")
    elif method != "GET":
        raise RuntimeError("SAFETY: method not allowed")
    CALLS += 1
    req = urllib.request.Request(url, data=data, method=method, headers=headers or {})
    try:
        with urllib.request.urlopen(req, timeout=TIMEOUT) as r:
            return r.getcode(), r.read()
    except urllib.error.HTTPError as e:
        return e.code, e.read()
    except Exception as e:  # noqa: BLE001
        return 0, json.dumps({"_err": str(e)[:160]}).encode()

def envelope(st, body):
    try: doc = json.loads(body.decode("utf-8", "replace"))
    except Exception: return st, False, None, []
    if isinstance(doc, dict) and "content" in doc and "isValid" in doc:
        errs = [e.get("errorMessage", "")[:160] for e in (doc.get("errors") or [])][:5]
        return st, bool(doc.get("isValid")), doc.get("content"), errs
    return st, (st == 200), doc, []

def api_get(path, params=None):
    url = BASE + path + ("?" + urllib.parse.urlencode(params) if params else "")
    st, body = _do("GET", url, headers={"Authorization": "Bearer %s" % _TOKEN, "Accept": "application/json"})
    time.sleep(SLEEP)
    return envelope(st, body)

def usage_query(body_obj, page):
    url = BASE + USAGE_PATH + "?" + urllib.parse.urlencode({"page": page, "limit": 50})
    data = json.dumps(body_obj).encode()
    st, body = _do("POST", url, headers={"Authorization": "Bearer %s" % _TOKEN,
                                         "Accept": "application/json",
                                         "Content-Type": "application/json"}, data=data)
    time.sleep(SLEEP)
    return envelope(st, body)

def discover():
    global TOKEN_ENDPOINT
    st, body = _do("GET", WELL_KNOWN)
    if st != 200: raise RuntimeError("OIDC discovery HTTP %s" % st)
    TOKEN_ENDPOINT = json.loads(body.decode("utf-8", "replace")).get("token_endpoint")
    if not TOKEN_ENDPOINT: raise RuntimeError("no token_endpoint")

def mint():
    form = {"grant_type": "client_credentials"}
    if SCOPE: form["scope"] = SCOPE
    if AUDIENCE: form["audience"] = AUDIENCE
    h = {"Content-Type": "application/x-www-form-urlencoded", "Accept": "application/json"}
    if TOKEN_AUTH == "basic":
        import base64
        h["Authorization"] = "Basic %s" % base64.b64encode(("%s:%s" % (CLIENT_ID, CLIENT_SECRET)).encode()).decode()
    else:
        form["client_id"] = CLIENT_ID; form["client_secret"] = CLIENT_SECRET
    st, body = _do("POST", TOKEN_ENDPOINT, headers=h, data=urllib.parse.urlencode(form).encode())
    if st != 200: raise RuntimeError("token mint HTTP %s" % st)
    t = json.loads(body.decode("utf-8", "replace")).get("access_token")
    if not t: raise RuntimeError("no access_token")
    return t

def load(path):
    try:
        with open(path, "r") as fh: return json.load(fh)  # read-only
    except FileNotFoundError:
        print("  (not found: %s)" % path); return None
    except Exception as e:  # noqa: BLE001
        print("  (could not read %s: %s)" % (path, str(e)[:80])); return None

def num(x):
    try: return round(float(x), 2)
    except Exception: return None

def cls(a, l):
    if a is not None and l is not None:
        return "EXACT" if abs(a - l) <= TOL else "DIFF"
    if a is not None: return "API-ONLY"
    if l is not None: return "COOKIE-ONLY"
    return "NOT-COMPARABLE"

def main():
    global _TOKEN
    if CONFIRM != "yes":
        print("Set SL_PROBE_CONFIRM=yes to confirm a read-only usage parity check."); return 2
    if not BEARER and not (CLIENT_ID and CLIENT_SECRET):
        print("Need SL_BEARER or SL_CLIENT_ID+SL_CLIENT_SECRET."); return 2

    if BEARER: _TOKEN = BEARER
    else: discover(); _TOKEN = mint()
    print("Usage parity — READ-ONLY (API auth ok; token hidden)\n")

    # API usage (POST query, paginated)
    try: body_obj = json.loads(USAGE_BODY)
    except Exception:
        print("SL_USAGE_BODY is not valid JSON."); return 2
    api_rows, page = [], 0
    while page < MAX_PAGES:
        st, ok, content, errs = usage_query(body_obj, page)
        if not ok:
            print("POST /data-usage/query -> HTTP %s isValid=False" % st)
            if errs: print("  validation/errors (redacted): %s" % " | ".join(errs))
            print("  → This is an input-validation response (no mutation). Set the exact request")
            print("    schema via SL_USAGE_BODY='{...}' and re-run. Nothing was changed.")
            if page == 0:
                print("\n(Default body was %r. The endpoint likely needs a service-line filter.)" % USAGE_BODY)
                break
            break
        rows, last = [], True
        if isinstance(content, dict) and "results" in content:
            rows = content.get("results") or []; last = bool(content.get("isLastPage"))
        elif isinstance(content, list):
            rows = content
        api_rows += rows
        if last or not rows: break
        page += 1
    print("API usage rows (service-lines): %d   (calls=%d)" % (len(api_rows), CALLS))
    if api_rows:
        print("  API usage keys seen: %s" % ", ".join(sorted(api_rows[0].keys()))[:300])

    # Cookie usage
    cookie = load(os.path.join(DR_DATA, "sl_usage.json"))
    crecs = []
    if isinstance(cookie, dict): crecs = [v for v in cookie.values() if isinstance(v, dict)]
    elif isinstance(cookie, list): crecs = [v for v in cookie if isinstance(v, dict)]
    print("cookie sl_usage records: %d" % len(crecs))
    if crecs:
        print("  cookie usage keys seen: %s" % ", ".join(sorted(crecs[0].keys()))[:300])
    by_sl = {}
    for r in crecs:
        sl = str(r.get("service_line") or "")
        if sl: by_sl.setdefault(sl, []).append(r)

    # Compare latest cycle per SL
    print("\n=== USAGE PARITY (latest cycle per service-line; GB) ===")
    tally = {"total": {}, "priority": {}, "standard": {}}
    matched = 0
    for row in api_rows:
        sl = str(row.get("serviceLineNumber") or "")
        cycles = row.get("billingCycles") or []
        acyc = None
        for c in cycles:
            if acyc is None or str(c.get("endDate", "")) > str(acyc.get("endDate", "")):
                acyc = c
        a_pri = num(acyc.get("totalPriorityGB")) if acyc else None
        a_std = num(acyc.get("totalStandardGB")) if acyc else None
        a_tot = None
        if a_pri is not None or a_std is not None:
            a_tot = round((a_pri or 0) + (a_std or 0), 2)
        a_period = (acyc.get("startDate", "")[:10] + ".." + acyc.get("endDate", "")[:10]) if acyc else "-"

        loc_list = by_sl.get(sl, [])
        lrec = None
        for r in loc_list:
            if lrec is None or str(r.get("updated_at", "")) > str(lrec.get("updated_at", "")):
                lrec = r
        l_pri = num(lrec.get("local_priority_used_gb")) if lrec else None
        l_std = num(lrec.get("other_data_gb")) if lrec else None
        l_tot = num(lrec.get("total_gb")) if lrec else None
        l_period = (lrec.get("cycle_label") or lrec.get("cycle_key") or "-") if lrec else "-"

        if lrec: matched += 1
        for k, a, l in (("total", a_tot, l_tot), ("priority", a_pri, l_pri), ("standard", a_std, l_std)):
            c = cls(a, l); tally[k][c] = tally[k].get(c, 0) + 1
        print("SL %s" % pz("SL", sl))
        print("   period      API %-24s cookie %s" % (a_period, l_period))
        print("   total_gb    API %-8s cookie %-8s -> %s" % (a_tot, l_tot, cls(a_tot, l_tot)))
        print("   priority_gb API %-8s cookie %-8s -> %s" % (a_pri, l_pri, cls(a_pri, l_pri)))
        print("   standard_gb API %-8s cookie %-8s -> %s" % (a_std, l_std, cls(a_std, l_std)))

    print("\n=== TALLY (latest cycle) ===")
    for k in ("total", "priority", "standard"):
        print("  %-10s %s" % (k, tally[k]))
    print("  SLs with API usage: %d  matched to cookie: %d" % (len(api_rows), matched))

    print("\nIMPORTANT — read before concluding:")
    print("  * Only compare when the PERIODS align. If API period != cookie period, treat totals as")
    print("    NOT-COMPARABLE (different billing cycle), not DIFF.")
    print("  * Units are GB on both sides; priority=Local-Priority(blue), standard=other(white).")
    print("  * If cookie sl_usage does not retain the API's cycle, that is COOKIE-not-retained, not a")
    print("    parity failure. The API carries lastUpdated (often day-lagged) — note freshness.")
    print("  * This is parity evidence only; the API is NOT authoritative. No file written.")
    print("\nReminder: ROTATE the client secret after testing.")
    return 0

if __name__ == "__main__":
    try:
        sys.exit(main())
    except KeyboardInterrupt:
        sys.exit(130)
    except Exception as e:  # noqa: BLE001
        print("Usage parity stopped safely: %s" % str(e)[:200]); sys.exit(1)
