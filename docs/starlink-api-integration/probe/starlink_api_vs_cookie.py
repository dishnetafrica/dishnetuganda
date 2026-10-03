#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
starlink_api_vs_cookie.py — READ-ONLY parity check: official API vs the existing
cookie-based Data Report data, for the ONE account the API credential can see.

It proves whether the official API reproduces the current Data Report fields —
WITHOUT changing anything. It is a PARALLEL validation layer; it does not touch the
cookie path, does not change Data Report output, does not modify Finance, and makes
no write of any kind (API or filesystem).

SAFETY (by construction)
  * credentials from ENVIRONMENT VARIABLES ONLY; never printed.
  * API access is GET-only; the single POST is the OAuth token mint (skipped with
    SL_BEARER). No PUT/PATCH/DELETE/other-POST code path exists.
  * local Data Report / Finance JSON files are opened **read-only** ('r'); there is
    no file-write code anywhere in this script.
  * never prints secrets/tokens; pseudonymises account/SL/kit/dish/terminal ids in
    output; prints field MATCH/DIFF status (+ masked values), not raw identifiers.
  * refuses to run without SL_PROBE_CONFIRM=yes.
  * This is a one-off diagnostic — do NOT deploy it, cron it, or wire it into Data
    Report. It only compares.

USAGE (on the DishNet server, where the API + the data files are reachable)
  export SL_CLIENT_ID='...'; read -rs SL_CLIENT_SECRET && export SL_CLIENT_SECRET
  export SL_PROBE_CONFIRM=yes
  # local data dirs (defaults match the discovered install; override if different):
  #   SL_DR_DATA  = dishnet-data-report/data
  #   SL_FIN_DATA = dishnet-starlink-finance/data
  python3 starlink_api_vs_cookie.py

OUTPUT: per service-line and per kit — EXACT / DIFF / API-only / COOKIE-only per
field, with counts; plus a "keys seen" diagnostic (so if a local key name differs
from the assumed one, we can see it and adjust). Paste the redacted output back.
"""
import os
import sys
import json
import time
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
TIMEOUT = float(os.environ.get("SL_TIMEOUT", "20"))
SLEEP = float(os.environ.get("SL_SLEEP", "0.4"))
MAX_PAGES = int(os.environ.get("SL_MAX_PAGES", "20"))

_BASE = "/home/unms/data/ucrm/ucrm/data/plugins"
DR_DATA = os.environ.get("SL_DR_DATA", _BASE + "/dishnet-data-report/data")
FIN_DATA = os.environ.get("SL_FIN_DATA", _BASE + "/dishnet-starlink-finance/data")

TOKEN_ENDPOINT = None
_TOKEN = None
CALLS = 0

# ---- redaction ----
_pseudo, _ctr = {}, {}
def pz(kind, v):
    if v in (None, ""): return v
    k = (kind, str(v))
    if k not in _pseudo:
        _ctr[kind] = _ctr.get(kind, 0) + 1
        _pseudo[k] = "%s-%03d" % (kind, _ctr[kind])
    return _pseudo[k]

def mask(v):
    s = "" if v is None else str(v)
    if len(s) <= 4: return s
    return s[:2] + "…" + s[-2:]

# ---- GET-only transport (+ one token POST) ----
def _do(method, url, headers=None, data=None):
    global CALLS
    if method == "POST" and (TOKEN_ENDPOINT is None or url != TOKEN_ENDPOINT):
        raise RuntimeError("SAFETY: POST only to token endpoint")
    if method not in ("GET", "POST"):
        raise RuntimeError("SAFETY: method not allowed")
    CALLS += 1
    req = urllib.request.Request(url, data=data, method=method, headers=headers or {})
    try:
        with urllib.request.urlopen(req, timeout=TIMEOUT) as r:
            return r.getcode(), r.read()
    except urllib.error.HTTPError as e:
        return e.code, e.read()
    except Exception as e:  # noqa: BLE001
        return 0, json.dumps({"_err": str(e)[:140]}).encode()

def api_get(path, params=None):
    url = BASE + path + ("?" + urllib.parse.urlencode(params) if params else "")
    if not url.startswith(BASE): raise RuntimeError("SAFETY: off-base GET")
    st, body = _do("GET", url, headers={"Authorization": "Bearer %s" % _TOKEN, "Accept": "application/json"})
    time.sleep(SLEEP)
    try: doc = json.loads(body.decode("utf-8", "replace"))
    except Exception: return st, None
    if isinstance(doc, dict) and "content" in doc and "isValid" in doc:
        return st, (doc.get("content") if doc.get("isValid") else None)
    return st, doc

def api_list(path):
    """Return all rows across page-index pagination (GET-only)."""
    out, page = [], 0
    while page < MAX_PAGES:
        st, content = api_get(path, {"page": page})
        if content is None: break
        if isinstance(content, dict) and "results" in content:
            out += content.get("results") or []
            if content.get("isLastPage"): break
            page += 1
        elif isinstance(content, list):
            out += content; break
        else:
            out.append(content); break
    return out

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

# ---- local files (READ-ONLY) ----
def load(path):
    try:
        with open(path, "r") as fh:   # read-only; never written
            return json.load(fh)
    except FileNotFoundError:
        print("  (local file not found: %s)" % path); return None
    except Exception as e:  # noqa: BLE001
        print("  (could not read %s: %s)" % (path, str(e)[:80])); return None

def recs(obj):
    if isinstance(obj, dict): return [v for v in obj.values() if isinstance(v, dict)]
    if isinstance(obj, list): return [v for v in obj if isinstance(v, dict)]
    return []

def first(d, keys):
    for k in keys:
        if isinstance(d, dict) and d.get(k) not in (None, ""):
            return d.get(k)
    return None

def classify(api_v, loc_v):
    a = api_v not in (None, "")
    l = loc_v not in (None, "")
    if a and l:
        return "EXACT" if str(api_v).strip().lower() == str(loc_v).strip().lower() else "DIFF"
    if a and not l: return "API-only"
    if l and not a: return "COOKIE-only"
    return "both-empty"

def main():
    global _TOKEN
    if CONFIRM != "yes":
        print("Set SL_PROBE_CONFIRM=yes to confirm a read-only parity check."); return 2
    if not BEARER and not (CLIENT_ID and CLIENT_SECRET):
        print("Need SL_BEARER or SL_CLIENT_ID+SL_CLIENT_SECRET."); return 2

    # 1) API side
    if BEARER: _TOKEN = BEARER
    else: discover(); _TOKEN = mint()
    print("Parity check — READ-ONLY (API auth ok; token hidden)\n")
    api_sls = api_list("/service-lines")
    api_uts = api_list("/user-terminals")
    api_inv = api_list("/billing/invoices")
    api_acct_nums = sorted({str(s.get("accountNumber")) for s in api_sls if s.get("accountNumber")})
    print("API account(s) in scope: %s" % ", ".join(pz("ACCOUNT", a) for a in api_acct_nums) or "(none)")
    print("API: service_lines=%d user_terminals=%d invoices=%d  (calls=%d)\n" % (len(api_sls), len(api_uts), len(api_inv), CALLS))

    # 2) local cookie-side
    svc = recs(load(os.path.join(DR_DATA, "sl_svc_cache.json")))
    wifi = recs(load(os.path.join(DR_DATA, "wifi_router_map.json")))
    kits = recs(load(os.path.join(FIN_DATA, "sl_kits.json")))
    inv_local_raw = load(os.path.join(DR_DATA, "dr_invoices.json"))
    print("local: sl_svc_cache=%d wifi_router_map=%d sl_kits=%d" % (len(svc), len(wifi), len(kits)))
    if svc: print("  sl_svc_cache keys seen: %s" % ", ".join(sorted(svc[0].keys()))[:300])
    if wifi: print("  wifi_router_map keys seen: %s" % ", ".join(sorted(wifi[0].keys()))[:300])
    if kits: print("  sl_kits keys seen: %s" % ", ".join(sorted(kits[0].keys()))[:300])
    print()

    # index local by service line + kit serial (tolerant of key naming)
    loc_sl = {}
    for r in svc + wifi:
        sl = first(r, ["service_line", "serviceLineNumber", "sl"])
        if sl: loc_sl.setdefault(str(sl), {}).update(r)
    loc_kit = {}
    for r in wifi + kits:
        ks = first(r, ["kit_number", "kit_serial", "kitSerialNumber"])
        if ks: loc_kit.setdefault(str(ks).upper(), {}).update(r)

    # 3) compare service-lines (join on serviceLineNumber)
    print("=== SERVICE-LINE PARITY (API account only) ===")
    sl_fields = [
        ("account_number", ["accountNumber"], ["account_number", "accountNumber"]),
        ("active",        ["active"],          ["subscription_active", "active", "isActive"]),
        ("end_date",      ["endDate"],         ["subscription_endDate", "subscription_end_date", "endDate"]),
        ("start_date",    ["startDate"],       ["start_date", "startDate"]),
        ("product_ref",   ["productReferenceId"], ["plan_id", "productReferenceId", "product_ref"]),
        ("nickname",      ["nickname"],        ["nickname"]),
    ]
    tally = {}
    matched_sl = 0
    seen_local_sl = set()
    for s in api_sls:
        sl = str(s.get("serviceLineNumber") or "")
        loc = loc_sl.get(sl)
        tag = "matched" if loc else "API-only(SL)"
        if loc: matched_sl += 1; seen_local_sl.add(sl)
        line = "SL %s [%s]" % (pz("SL", sl), tag)
        if loc:
            parts = []
            for label, aks, lks in sl_fields:
                c = classify(first(s, aks), first(loc, lks))
                tally[label] = tally.get(label, {}); tally[label][c] = tally[label].get(c, 0) + 1
                parts.append("%s:%s" % (label, c))
            line += "  " + "  ".join(parts)
        print(line)
    cookie_only_sl = [sl for sl in loc_sl if sl not in seen_local_sl and sl]  # may include other accounts
    print("  SL matched(API∩local)=%d  API-only=%d" % (matched_sl, len(api_sls) - matched_sl))

    # 4) compare kits / terminals (join on kitSerialNumber)
    print("\n=== KIT / TERMINAL PARITY (API account only) ===")
    kit_fields = [
        ("service_line",  ["serviceLineNumber"], ["service_line", "serviceLineNumber"]),
        ("dish_serial",   ["dishSerialNumber"],  ["dish_serial", "dishSerialNumber"]),
        ("terminal_id",   ["userTerminalId"],    ["terminal_id", "userTerminalId"]),
        ("account_number",["accountNumber"],     ["account_number", "starlink_account_number"]),
        ("crm_link",      ["__none__"],          ["crm_client_id"]),   # expected COOKIE-only
    ]
    ktally = {}
    matched_kit = 0
    for u in api_uts:
        ks = str(u.get("kitSerialNumber") or "").upper()
        loc = loc_kit.get(ks)
        tag = "matched" if loc else "API-only(kit)"
        if loc: matched_kit += 1
        line = "KIT %s [%s]" % (pz("KIT", ks), tag)
        if loc:
            parts = []
            for label, aks, lks in kit_fields:
                av = None if aks == ["__none__"] else first(u, aks)
                c = classify(av, first(loc, lks))
                ktally[label] = ktally.get(label, {}); ktally[label][c] = ktally[label].get(c, 0) + 1
                parts.append("%s:%s" % (label, c))
            line += "  " + "  ".join(parts)
        print(line)
    print("  kits matched(API∩local)=%d  API-only=%d" % (matched_kit, len(api_uts) - matched_kit))

    # 5) invoices (count-level)
    inv_local_n = 0
    if isinstance(inv_local_raw, dict):
        for v in inv_local_raw.values():
            if isinstance(v, list): inv_local_n += len(v)
    print("\n=== INVOICES ===")
    print("  API invoices=%d   local dr_invoices entries(all accounts)=%d" % (len(api_inv), inv_local_n))

    # 6) summary
    print("\n=== FIELD TALLY (service-lines) ===")
    for label, aks, lks in sl_fields:
        print("  %-14s %s" % (label, tally.get(label, {})))
    print("=== FIELD TALLY (kits) ===")
    for label, aks, lks in kit_fields:
        print("  %-14s %s" % (label, ktally.get(label, {})))

    print("\nINTERPRETATION")
    print("  EXACT = API reproduces the cookie value; DIFF = investigate; API-only =")
    print("  official API adds it; COOKIE-only = must stay on cookies (e.g. crm_link,")
    print("  and anything the API has no field for). This is parity evidence only —")
    print("  the API is NOT made authoritative. No file was written; no record changed.")
    print("\nReminder: ROTATE the client secret after testing.")
    return 0

if __name__ == "__main__":
    try:
        sys.exit(main())
    except KeyboardInterrupt:
        sys.exit(130)
    except Exception as e:  # noqa: BLE001
        print("Parity check stopped safely: %s" % str(e)[:200]); sys.exit(1)
