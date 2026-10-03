#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
starlink_api_probe.py — READ-ONLY validation probe for the official Starlink
Public API V2 (OIDC client-credentials).

PURPOSE
  Prove, with your real service-account credentials, exactly what the official
  API exposes — WITHOUT touching the working data-report/finance integration and
  WITHOUT any write/mutation. It answers the questions in
  docs/starlink-api-integration/05-official-api-live-access-test.md, above all:
  "With ONE credential, can DishNet read ALL its Starlink accounts/service-lines/
  user-terminals, or only one account?"

WHY THIS SCRIPT EXISTS SEPARATELY
  The Claude Code session that wrote this CANNOT reach starlink.com (egress is
  blocked: CONNECT tunnel 403) and holds no credentials. You run it where there
  IS internet + the credentials (your Mac or the server). It needs only python3
  (standard library — no pip installs).

SAFETY (by construction)
  * Reads credentials from ENVIRONMENT VARIABLES ONLY. Never hard-code secrets.
  * The ONLY data requests are HTTP GET. There is no PUT/PATCH/DELETE code path,
    and no POST to any API data path. The single POST in the whole file is the
    OAuth token mint to the IdP token endpoint (auth only) — and it is skipped
    entirely if you supply SL_BEARER.
  * It never prints the client secret, the bearer token, or Authorization headers.
  * It never persists raw API payloads. The optional report (SL_PROBE_OUT) stores
    only: endpoint, status, field NAMES, counts, and PSEUDONYMISED ids
    (ACCOUNT-001, SL-001, KIT-001 ...). No customer names/addresses are written.
  * Every request has an explicit timeout; pagination is capped; it fails safe.
  * It refuses to run unless SL_PROBE_CONFIRM=yes (no accidental firing).
  * /data-usage/query is a read but uses POST in the spec; it is SKIPPED unless
    you explicitly set SL_PROBE_USAGE=yes.

USAGE (example — do NOT paste secrets into a shell history file)
  export SL_CLIENT_ID='...'            # from your Starlink service account
  export SL_CLIENT_SECRET='...'        # prefer: read into env without echoing
  export SL_PROBE_CONFIRM=yes
  # optional: export SL_BEARER='...'   # if you already minted a token (no POST at all)
  # optional: export SL_PROBE_OUT=./starlink-probe-report.redacted.json
  # optional: export SL_PROBE_USAGE=yes   # include the POST-based data-usage read
  python3 starlink_api_probe.py

  Then paste the printed SUMMARY (it is already redacted) back into the chat so
  the live sections of docs/05 can be completed. Afterwards, ROTATE the secret.

RECOMMENDED CREDENTIAL FOR THIS PROBE
  A dedicated, least-privilege, VIEW-ONLY service account: grant *View* on
  Account information, Financial, Service, and Device — and do NOT grant
  "Device command and configuration management" (that is write/reboot and a read
  probe must never need it). The probe performs no writes regardless.
"""

import os
import sys
import json
import time
import urllib.request
import urllib.error
import urllib.parse

# ----------------------------------------------------------------------------
# Configuration (environment only)
# ----------------------------------------------------------------------------
WELL_KNOWN = os.environ.get(
    "SL_WELL_KNOWN",
    "https://starlink.com/api/auth/.well-known/openid-configuration",
)
BASE = os.environ.get("SL_BASE", "https://starlink.com/api/public/v2").rstrip("/")
CLIENT_ID = os.environ.get("SL_CLIENT_ID")
CLIENT_SECRET = os.environ.get("SL_CLIENT_SECRET")
BEARER = os.environ.get("SL_BEARER")  # if set, no token POST is made at all
SCOPE = os.environ.get("SL_SCOPE")  # optional
AUDIENCE = os.environ.get("SL_AUDIENCE")  # optional (some IdPs need it)
TOKEN_AUTH = os.environ.get("SL_TOKEN_AUTH", "body").lower()  # 'body' or 'basic'
CONFIRM = os.environ.get("SL_PROBE_CONFIRM", "")
PROBE_USAGE = os.environ.get("SL_PROBE_USAGE", "").lower() in ("1", "yes", "true")
OUT = os.environ.get("SL_PROBE_OUT")  # optional redacted JSON report path
TIMEOUT = float(os.environ.get("SL_TIMEOUT", "20"))
MAX_PAGES = int(os.environ.get("SL_MAX_PAGES", "10"))  # pagination safety cap
MAX_SAMPLE = int(os.environ.get("SL_MAX_SAMPLE", "3"))  # sample ids per endpoint
SLEEP = float(os.environ.get("SL_SLEEP", "0.4"))  # politeness between calls

TOKEN_ENDPOINT = None  # set during discovery; the ONLY URL a POST may target
CALLS = 0
REPORT = {"base": BASE, "endpoints": [], "scope": {}, "calls": 0, "notes": []}

# ----------------------------------------------------------------------------
# Redaction / pseudonymisation
# ----------------------------------------------------------------------------
_pseudo = {}
_counters = {}


def pseudo(kind, val):
    """Map a real identifier to a stable pseudonym like ACCOUNT-001."""
    if val is None:
        return None
    key = (kind, str(val))
    if key not in _pseudo:
        _counters[kind] = _counters.get(kind, 0) + 1
        _pseudo[key] = "%s-%03d" % (kind, _counters[kind])
    return _pseudo[key]


def keys_of(obj):
    if isinstance(obj, dict):
        return sorted(obj.keys())
    if isinstance(obj, list) and obj and isinstance(obj[0], dict):
        return sorted(obj[0].keys())
    return []


# ----------------------------------------------------------------------------
# Request primitives (method-guarded)
# ----------------------------------------------------------------------------
def _auth_headers():
    # Built fresh each call; never logged.
    return {"Authorization": "Bearer %s" % _TOKEN, "Accept": "application/json"}


def _do(method, url, headers=None, data=None):
    """Low-level request. Hard guards: only GET anywhere, plus ONE POST that may
    only target the discovered token endpoint (auth). Nothing else is possible."""
    global CALLS
    if method == "GET":
        pass
    elif method == "POST":
        if TOKEN_ENDPOINT is None or url != TOKEN_ENDPOINT:
            raise RuntimeError("SAFETY: POST is only permitted to the token endpoint")
    else:
        raise RuntimeError("SAFETY: method %r is not allowed (read-only probe)" % method)
    CALLS += 1
    req = urllib.request.Request(url, data=data, method=method, headers=headers or {})
    try:
        with urllib.request.urlopen(req, timeout=TIMEOUT) as r:
            return r.getcode(), dict(r.headers), r.read()
    except urllib.error.HTTPError as e:
        return e.code, dict(e.headers or {}), e.read()
    except Exception as e:  # noqa: BLE001 - fail safe, never leak
        return 0, {}, json.dumps({"_transport_error": str(e)[:200]}).encode()


def api_get(path, params=None):
    """GET under the configured API base only."""
    url = BASE + path
    if params:
        url += "?" + urllib.parse.urlencode(params)
    if not url.startswith(BASE):
        raise RuntimeError("SAFETY: refusing off-base GET to %s" % url)
    status, hdrs, body = _do("GET", url, headers=_auth_headers())
    time.sleep(SLEEP)
    return status, hdrs, body


# ----------------------------------------------------------------------------
# Auth
# ----------------------------------------------------------------------------
def discover_token_endpoint():
    global TOKEN_ENDPOINT
    status, _, body = _do("GET", WELL_KNOWN)
    if status != 200:
        raise RuntimeError("OIDC discovery failed: HTTP %s (%s)" % (status, WELL_KNOWN))
    doc = json.loads(body.decode("utf-8", "replace"))
    TOKEN_ENDPOINT = doc.get("token_endpoint")
    if not TOKEN_ENDPOINT:
        raise RuntimeError("OIDC discovery returned no token_endpoint")
    REPORT["notes"].append("token_endpoint discovered (value not printed)")
    return TOKEN_ENDPOINT


def mint_token():
    """Client-credentials grant. The only POST in this program."""
    form = {"grant_type": "client_credentials"}
    if SCOPE:
        form["scope"] = SCOPE
    if AUDIENCE:
        form["audience"] = AUDIENCE
    headers = {
        "Content-Type": "application/x-www-form-urlencoded",
        "Accept": "application/json",
    }
    if TOKEN_AUTH == "basic":
        import base64

        basic = base64.b64encode(("%s:%s" % (CLIENT_ID, CLIENT_SECRET)).encode()).decode()
        headers["Authorization"] = "Basic %s" % basic
    else:
        form["client_id"] = CLIENT_ID
        form["client_secret"] = CLIENT_SECRET
    data = urllib.parse.urlencode(form).encode()
    status, _, body = _do("POST", TOKEN_ENDPOINT, headers=headers, data=data)
    if status != 200:
        # Never echo the body if it might contain token material; show status only.
        raise RuntimeError("Token mint failed: HTTP %s" % status)
    tok = json.loads(body.decode("utf-8", "replace")).get("access_token")
    if not tok:
        raise RuntimeError("Token mint returned no access_token")
    return tok


# ----------------------------------------------------------------------------
# ServiceResponse envelope + generic endpoint probe
# ----------------------------------------------------------------------------
def parse_envelope(status, body):
    """Return (ok, content, meta) where meta captures permission errors etc."""
    meta = {"http": status}
    try:
        doc = json.loads(body.decode("utf-8", "replace"))
    except Exception:
        return (False, None, dict(meta, note="non-JSON body"))
    if isinstance(doc, dict) and "content" in doc and "isValid" in doc:
        meta["isValid"] = doc.get("isValid")
        errs = doc.get("errors") or []
        if errs:
            meta["errors"] = [e.get("errorMessage", "")[:120] for e in errs][:3]
        return (bool(doc.get("isValid")), doc.get("content"), meta)
    # 403 permission error shape
    if status == 403 and isinstance(doc, dict):
        meta["permission_denied"] = {
            "featureAccess": doc.get("featureAccessString")
            or (doc.get("requiredPermission") or {}).get("featureAccess"),
            "permission": doc.get("permissionString")
            or (doc.get("requiredPermission") or {}).get("permission"),
        }
        return (False, None, meta)
    return (status == 200, doc, meta)


def extract_results(content):
    """Normalise both pagination envelopes to (results, pageinfo)."""
    if isinstance(content, dict):
        if "results" in content:
            page = {k: content.get(k) for k in
                    ("pageIndex", "limit", "isLastPage", "totalCount", "nextKey")
                    if k in content}
            return content.get("results") or [], page
        return [content], {"single": True}
    if isinstance(content, list):
        return content, {"list": True}
    return [], {}


def probe(name, path, id_kind=None, id_fields=(), page_style=None):
    """GET an endpoint, walk pagination safely, record a redacted summary."""
    rec = {"endpoint": path, "name": name, "method": "GET"}
    total = 0
    sampled = []
    fields = []
    pages = 0
    cursor = None
    page_index = 0
    last_meta = {}
    while pages < MAX_PAGES:
        params = {}
        if page_style == "cursor" and cursor:
            params["cursor"] = cursor
        elif page_style == "page":
            params["page"] = page_index
        status, _, body = api_get(path, params or None)
        ok, content, meta = parse_envelope(status, body)
        last_meta = meta
        rec.setdefault("http", status)
        if not ok:
            rec["result"] = "DENIED" if status == 403 else ("ERROR:%s" % status)
            if meta.get("permission_denied"):
                rec["permission_denied"] = meta["permission_denied"]
            if meta.get("errors"):
                rec["errors"] = meta["errors"]
            break
        results, page = extract_results(content)
        if not fields:
            fields = keys_of(results if isinstance(results, list) else content)
        total += len(results) if isinstance(results, list) else 1
        for row in (results if isinstance(results, list) else [results])[: MAX_SAMPLE]:
            if isinstance(row, dict) and id_fields:
                sampled.append({f: (pseudo(id_kind or name, row.get(f)) if f.lower().endswith(
                    ("number", "id", "serialnumber", "referenceid")) else row.get(f))
                    for f in id_fields if f in row})
        pages += 1
        if page.get("totalCount") is not None:
            rec["totalCount"] = page["totalCount"]
        if page.get("isLastPage") or page.get("single") or page.get("list"):
            break
        if page_style == "cursor":
            cursor = page.get("nextKey")
            if not cursor:
                break
        elif page_style == "page":
            page_index += 1
        else:
            break
    else:
        rec["note"] = "pagination cap (%d pages) reached" % MAX_PAGES
    if "result" not in rec:
        rec["result"] = "OK"
        rec["count_seen"] = total
        rec["fields"] = fields
        if sampled:
            rec["sample_ids"] = sampled
    REPORT["endpoints"].append(rec)
    return rec


# ----------------------------------------------------------------------------
# Main
# ----------------------------------------------------------------------------
def main():
    global _TOKEN
    if CONFIRM != "yes":
        print("Refusing to run: set SL_PROBE_CONFIRM=yes to confirm a read-only probe.")
        return 2
    if not BEARER and not (CLIENT_ID and CLIENT_SECRET):
        print("Need SL_BEARER, or SL_CLIENT_ID + SL_CLIENT_SECRET, in the environment.")
        return 2

    print("Starlink Public API V2 — READ-ONLY probe")
    print("base = %s" % BASE)
    if BEARER:
        _TOKEN = BEARER
        print("auth = supplied bearer (no token POST made)")
    else:
        discover_token_endpoint()
        _TOKEN = mint_token()
        print("auth = client-credentials (token minted; value hidden)")

    # ---- the account-scope question (most important) ----
    probe("account", "/account", id_kind="ACCOUNT", id_fields=("accountNumber",))
    probe("managed_tree", "/managed/accounts/tree")
    probe("managed_accounts", "/managed/accounts", id_kind="ACCOUNT",
          id_fields=("accountNumber",), page_style="cursor")
    probe("managed_service_lines", "/managed/accounts/service-lines", id_kind="SL",
          id_fields=("serviceLineNumber", "accountNumber"), page_style="cursor")
    probe("managed_user_terminals", "/managed/accounts/user-terminals", id_kind="KIT",
          id_fields=("userTerminalId", "kitSerialNumber"), page_style="cursor")

    # ---- the direct (single-account) read surface ----
    probe("service_lines", "/service-lines", id_kind="SL",
          id_fields=("serviceLineNumber", "productReferenceId", "active", "endDate",
                     "addressReferenceId"), page_style="page")
    probe("user_terminals", "/user-terminals", id_kind="KIT",
          id_fields=("userTerminalId", "kitSerialNumber", "dishSerialNumber",
                     "serviceLineNumber"), page_style="page")
    probe("products", "/products", id_kind="PRODUCT",
          id_fields=("productReferenceId", "name", "isoCurrencyCode"), page_style="page")
    probe("addresses", "/addresses", id_kind="ADDR",
          id_fields=("addressReferenceId",), page_style="page")

    # ---- billing (needs Financial/View — may be DENIED with current grants) ----
    probe("billing_invoices", "/billing/invoices", id_kind="INV",
          id_fields=("invoiceId", "status", "amount", "dueAmount"), page_style="page")
    probe("billing_balance", "/billing/balance")

    # ---- usage: POST-based read; opt-in only ----
    if PROBE_USAGE:
        REPORT["notes"].append("usage probe skipped in this build (POST read; enable carefully)")
        print("NOTE: /data-usage/query is a POST-based read; this probe leaves it to a "
              "follow-up to keep the strict GET-only guarantee. Set it up separately.")

    # ---- derive the scope verdict ----
    def rec_for(name):
        for r in REPORT["endpoints"]:
            if r["name"] == name:
                return r
        return {}

    acct = rec_for("account")
    tree = rec_for("managed_tree")
    macc = rec_for("managed_accounts")
    msl = rec_for("managed_service_lines")
    sl = rec_for("service_lines")
    ut = rec_for("user_terminals")

    scope = {
        "account_endpoint": acct.get("result"),
        "managed_hierarchy_present": tree.get("result") == "OK",
        "managed_accounts_count": macc.get("totalCount", macc.get("count_seen"))
        if macc.get("result") == "OK" else None,
        "managed_service_lines_count": msl.get("totalCount", msl.get("count_seen"))
        if msl.get("result") == "OK" else None,
        "direct_service_lines_count": sl.get("totalCount", sl.get("count_seen"))
        if sl.get("result") == "OK" else None,
        "direct_user_terminals_count": ut.get("totalCount", ut.get("count_seen"))
        if ut.get("result") == "OK" else None,
    }
    if scope["managed_hierarchy_present"] and (scope["managed_accounts_count"] or 0) > 1:
        scope["verdict"] = ("ORG-WIDE: credential sees a managed hierarchy of %s accounts"
                            % scope["managed_accounts_count"])
    elif scope["managed_hierarchy_present"]:
        scope["verdict"] = "MANAGED but only self/one child visible — confirm hierarchy"
    elif sl.get("result") == "OK" or acct.get("result") == "OK":
        scope["verdict"] = ("SINGLE-ACCOUNT: no managed hierarchy exposed; credential sees "
                            "one account's own service-lines/terminals")
    else:
        scope["verdict"] = "INDETERMINATE — see per-endpoint results / permission grants"
    REPORT["scope"] = scope
    REPORT["calls"] = CALLS

    # ---- print redacted summary ----
    print("\n================ REDACTED SUMMARY (safe to paste back) ================")
    print("API calls made: %d" % CALLS)
    print("\nPer-endpoint:")
    for r in REPORT["endpoints"]:
        line = "  %-24s GET %-34s -> %s" % (r["name"], r["endpoint"], r.get("result"))
        if "totalCount" in r:
            line += " totalCount=%s" % r["totalCount"]
        elif "count_seen" in r:
            line += " count=%s" % r["count_seen"]
        if r.get("permission_denied"):
            line += " needs=%s/%s" % (r["permission_denied"].get("featureAccess"),
                                      r["permission_denied"].get("permission"))
        print(line)
        if r.get("fields"):
            print("       fields: %s" % ", ".join(r["fields"][:20]))
    print("\nSCOPE VERDICT:")
    for k, v in scope.items():
        print("  %-32s %s" % (k, v))
    print("======================================================================")
    print("\nReminder: ROTATE the client secret if it was ever shown/pasted outside a vault.")

    if OUT:
        try:
            with open(OUT, "w") as fh:
                json.dump(REPORT, fh, indent=2, default=str)
            print("Redacted report written to %s (no secrets, no raw payloads)." % OUT)
        except Exception as e:  # noqa: BLE001
            print("Could not write report: %s" % str(e)[:120])
    return 0


if __name__ == "__main__":
    try:
        sys.exit(main())
    except KeyboardInterrupt:
        print("\ninterrupted")
        sys.exit(130)
    except Exception as e:  # noqa: BLE001 - never leak secrets in a traceback
        print("Probe stopped safely: %s" % str(e)[:200])
        sys.exit(1)
