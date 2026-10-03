#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
starlink_kit_lookup.py — READ-ONLY lookup of specific kits / service-lines against
the official Starlink Public API V2, to see which ones THIS credential can resolve.

Companion to starlink_api_probe.py. Same safety model:
  * credentials from ENVIRONMENT VARIABLES ONLY; never printed.
  * only HTTP GET for data; the single POST is the OAuth token mint (skipped if
    SL_BEARER is set); no PUT/PATCH/DELETE path exists.
  * never prints the secret/token/Authorization; no device command is invoked.
  * refuses to run without SL_PROBE_CONFIRM=yes.

Kit/service-line identifiers are YOUR OWN asset ids (not secrets), so this tool
prints them plainly — that is the point (you need to know which kit is where).

INPUT: a file of `KIT...=SL-DF-...` lines (default ./pairs.txt). `=`, `:` or
whitespace separators are accepted; a line with only a KIT or only an SL also works.

USAGE (on a host with egress + the credential):
  cat > pairs.txt <<'EOF'
  KITXXXXXXXXXXX=SL-DF-XXXXXXXX-XXXXX-X   # your real KIT=SL pairs, one per line
  EOF
  export SL_CLIENT_ID='...'; read -rs SL_CLIENT_SECRET && export SL_CLIENT_SECRET
  export SL_PROBE_CONFIRM=yes
  python3 starlink_kit_lookup.py            # reads ./pairs.txt

OUTPUT: per entry — SL lookup (FOUND/NOT_FOUND/DENIED), kit lookup (FOUND/NOT),
and the API's own service-line for that kit (to cross-check your mapping) — then a
summary of how many of your entries live in THIS credential's account.
"""
import os
import sys
import json
import time
import urllib.request
import urllib.error
import urllib.parse

WELL_KNOWN = os.environ.get(
    "SL_WELL_KNOWN", "https://starlink.com/api/auth/.well-known/openid-configuration")
BASE = os.environ.get("SL_BASE", "https://starlink.com/api/public/v2").rstrip("/")
CLIENT_ID = os.environ.get("SL_CLIENT_ID")
CLIENT_SECRET = os.environ.get("SL_CLIENT_SECRET")
BEARER = os.environ.get("SL_BEARER")
SCOPE = os.environ.get("SL_SCOPE")
AUDIENCE = os.environ.get("SL_AUDIENCE")
TOKEN_AUTH = os.environ.get("SL_TOKEN_AUTH", "body").lower()
CONFIRM = os.environ.get("SL_PROBE_CONFIRM", "")
PAIRS_FILE = os.environ.get("SL_PAIRS_FILE", "pairs.txt")
TIMEOUT = float(os.environ.get("SL_TIMEOUT", "20"))
SLEEP = float(os.environ.get("SL_SLEEP", "0.4"))

TOKEN_ENDPOINT = None
_TOKEN = None
CALLS = 0


def _do(method, url, headers=None, data=None):
    global CALLS
    if method == "GET":
        pass
    elif method == "POST":
        if TOKEN_ENDPOINT is None or url != TOKEN_ENDPOINT:
            raise RuntimeError("SAFETY: POST only permitted to the token endpoint")
    else:
        raise RuntimeError("SAFETY: method %r not allowed (read-only)" % method)
    CALLS += 1
    req = urllib.request.Request(url, data=data, method=method, headers=headers or {})
    try:
        with urllib.request.urlopen(req, timeout=TIMEOUT) as r:
            return r.getcode(), r.read()
    except urllib.error.HTTPError as e:
        return e.code, e.read()
    except Exception as e:  # noqa: BLE001
        return 0, json.dumps({"_transport_error": str(e)[:160]}).encode()


def api_get(path, params=None):
    url = BASE + path
    if params:
        url += "?" + urllib.parse.urlencode(params)
    if not url.startswith(BASE):
        raise RuntimeError("SAFETY: refusing off-base GET")
    st, body = _do("GET", url, headers={"Authorization": "Bearer %s" % _TOKEN,
                                        "Accept": "application/json"})
    time.sleep(SLEEP)
    return st, body


def discover():
    global TOKEN_ENDPOINT
    st, body = _do("GET", WELL_KNOWN)
    if st != 200:
        raise RuntimeError("OIDC discovery failed: HTTP %s" % st)
    TOKEN_ENDPOINT = json.loads(body.decode("utf-8", "replace")).get("token_endpoint")
    if not TOKEN_ENDPOINT:
        raise RuntimeError("discovery returned no token_endpoint")


def mint():
    form = {"grant_type": "client_credentials"}
    if SCOPE:
        form["scope"] = SCOPE
    if AUDIENCE:
        form["audience"] = AUDIENCE
    headers = {"Content-Type": "application/x-www-form-urlencoded", "Accept": "application/json"}
    if TOKEN_AUTH == "basic":
        import base64
        headers["Authorization"] = "Basic %s" % base64.b64encode(
            ("%s:%s" % (CLIENT_ID, CLIENT_SECRET)).encode()).decode()
    else:
        form["client_id"] = CLIENT_ID
        form["client_secret"] = CLIENT_SECRET
    st, body = _do("POST", TOKEN_ENDPOINT, headers=headers,
                   data=urllib.parse.urlencode(form).encode())
    if st != 200:
        raise RuntimeError("token mint failed: HTTP %s" % st)
    tok = json.loads(body.decode("utf-8", "replace")).get("access_token")
    if not tok:
        raise RuntimeError("token mint returned no access_token")
    return tok


def envelope(st, body):
    try:
        doc = json.loads(body.decode("utf-8", "replace"))
    except Exception:
        return st, False, None
    if isinstance(doc, dict) and "content" in doc and "isValid" in doc:
        return st, bool(doc.get("isValid")), doc.get("content")
    return st, (st == 200), doc


def parse_pairs(path):
    out = []
    try:
        with open(path) as fh:
            for raw in fh:
                line = raw.strip()
                if not line or line.startswith("#"):
                    continue
                for sep in ("=", ":", ",", "\t", " "):
                    if sep in line:
                        a, b = line.split(sep, 1)
                        break
                else:
                    a, b = line, ""
                kit = a.strip() if a.strip().upper().startswith("KIT") else b.strip()
                sl = b.strip() if b.strip().upper().startswith("SL") else a.strip()
                out.append((kit, sl))
    except FileNotFoundError:
        print("No pairs file at %s — create it (one KIT=SL per line)." % path)
        sys.exit(2)
    return out


def main():
    global _TOKEN
    if CONFIRM != "yes":
        print("Set SL_PROBE_CONFIRM=yes to confirm a read-only lookup.")
        return 2
    if not BEARER and not (CLIENT_ID and CLIENT_SECRET):
        print("Need SL_BEARER or SL_CLIENT_ID + SL_CLIENT_SECRET in the environment.")
        return 2
    pairs = parse_pairs(PAIRS_FILE)
    if not pairs:
        print("No entries parsed from %s" % PAIRS_FILE)
        return 2

    if BEARER:
        _TOKEN = BEARER
    else:
        discover()
        _TOKEN = mint()
    print("Starlink V2 kit/service-line lookup — READ-ONLY (auth ok; token hidden)")
    print("base = %s  entries = %d\n" % (BASE, len(pairs)))

    hdr = "%-20s %-26s %-12s %-10s %-26s" % ("KIT", "SL (expected)", "SL_lookup",
                                             "kit_lookup", "API_SL_for_kit")
    print(hdr)
    print("-" * len(hdr))
    sl_found = kit_found = mismatch = 0
    for kit, sl in pairs:
        sl_res = "-"
        if sl:
            st, ok, _ = envelope(*api_get("/service-lines/%s" % urllib.parse.quote(sl, safe="")))
            sl_res = "FOUND" if ok else ("DENIED" if st == 403 else
                                         ("NOT_FOUND" if st in (404, 422) else "HTTP:%s" % st))
            if ok:
                sl_found += 1
        kit_res = "-"
        api_sl = "-"
        if kit:
            st, ok, content = envelope(*api_get("/user-terminals",
                                                {"searchString": kit}))
            rows = []
            if isinstance(content, dict):
                rows = content.get("results") or []
            elif isinstance(content, list):
                rows = content
            match = None
            for r in rows:
                if isinstance(r, dict) and str(r.get("kitSerialNumber", "")).upper() == kit.upper():
                    match = r
                    break
            if match is not None:
                kit_res = "FOUND"
                kit_found += 1
                api_sl = str(match.get("serviceLineNumber") or "-")
                if sl and api_sl not in ("-", "") and api_sl != sl:
                    api_sl += " (MISMATCH)"
                    mismatch += 1
            else:
                kit_res = "DENIED" if st == 403 else ("NOT" if st in (200, 404, 422) else "HTTP:%s" % st)
        print("%-20s %-26s %-12s %-10s %-26s" % (kit[:20], (sl or "-")[:26], sl_res, kit_res, api_sl[:26]))

    print("\nSUMMARY")
    print("  entries checked:            %d" % len(pairs))
    print("  service-lines in THIS acct: %d" % sl_found)
    print("  kits in THIS acct:          %d" % kit_found)
    print("  kit<->SL mismatches vs API: %d" % mismatch)
    print("  API calls made:             %d" % CALLS)
    print("\nInterpretation: entries that are NOT_FOUND here almost certainly belong to")
    print("OTHER Starlink accounts this single credential cannot see. One credential")
    print("covers one account unless a managed/parent hierarchy links the others.")
    print("\nReminder: ROTATE the client secret after testing.")
    return 0


if __name__ == "__main__":
    try:
        sys.exit(main())
    except KeyboardInterrupt:
        sys.exit(130)
    except Exception as e:  # noqa: BLE001
        print("Lookup stopped safely: %s" % str(e)[:200])
        sys.exit(1)
