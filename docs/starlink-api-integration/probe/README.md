# Starlink official-API V2 — read-only probe (operator run-guide)

`starlink_api_probe.py` proves what the **official** Starlink Public API V2 exposes with your real
service-account credentials, **without touching the working data-report/finance integration and
without any write**. It is the tool for `docs/starlink-api-integration/05-official-api-live-access-test.md`.

**Why you run it, not this session.** The Claude Code container cannot reach `starlink.com` (egress
is blocked — `CONNECT tunnel failed, 403`) and holds no credentials. You run it where there is
internet + the credentials (your Mac, or the server). It needs only **python3** (standard library —
nothing to install).

---

## Safety guarantees (by construction — read the top of the script)
- Credentials come from **environment variables only**; none are hard-coded.
- The only data requests are **HTTP GET**. There is **no** PUT/PATCH/DELETE code path and **no** POST
  to any API data path. The single POST in the file is the OAuth token mint to the IdP (auth only),
  and it is skipped entirely if you pass `SL_BEARER`.
- It **never prints** the client secret, the bearer token, or Authorization headers.
- It **never saves raw payloads**. The optional report holds only endpoint, status, field *names*,
  counts, and **pseudonymised** ids (`ACCOUNT-001`, `SL-001`, `KIT-001`). No customer names/addresses.
- Timeouts on every call; pagination capped; refuses to run without `SL_PROBE_CONFIRM=yes`.

## Before you run — two recommendations
1. **Use a dedicated, view-only service account for this.** In the Starlink service-account screen,
   grant **View** on *Account information*, *Financial*, *Service*, and *Device*; do **not** grant
   *Device command and configuration management* (that is write/reboot — a read probe must never
   carry it). The credential you just created has device-command/config (write) enabled and lacks
   *Account information* and *Financial* (View), so `/account` and `/billing/*` will return
   **DENIED** until those View boxes are ticked and saved.
2. **Rotate any secret that has been shown outside a vault** (e.g. pasted into chat or a screenshot).
   The screen allows up to 20 secrets — mint a fresh one, use it, delete the exposed one.

## Run it
```bash
# set credentials WITHOUT writing them into shell history where possible
export SL_CLIENT_ID='your-client-id'
read -rs SL_CLIENT_SECRET && export SL_CLIENT_SECRET      # prompt, no echo
export SL_PROBE_CONFIRM=yes
# optional:
# export SL_BEARER='already-minted-token'                 # then no token POST at all
# export SL_SCOPE='...'      export SL_AUDIENCE='...'      # only if your IdP needs them
# export SL_TOKEN_AUTH=basic                              # if the token endpoint wants Basic auth
# export SL_PROBE_OUT=./starlink-probe-report.redacted.json

python3 starlink_api_probe.py
```

### If `python3` is missing on your Mac
Run it on the server instead (it has python3 and PHP), or install the Xcode Command Line Tools
(`xcode-select --install`). The script is pure standard-library python3.

## What it checks (all read-only GET)
`/account`, `/managed/accounts/tree`, `/managed/accounts`, `/managed/accounts/service-lines`,
`/managed/accounts/user-terminals`, `/service-lines`, `/user-terminals`, `/products`, `/addresses`,
`/billing/invoices`, `/billing/balance`. (`/data-usage/query` is a POST-based read and is left to a
separate, deliberate follow-up to keep the strict GET-only guarantee.)

It prints a **redacted summary** ending in a **SCOPE VERDICT** that answers the key question —
*ORG-WIDE* (a managed hierarchy of N accounts) vs *SINGLE-ACCOUNT*.

## What to send back
Paste the **REDACTED SUMMARY** block (it is already safe — pseudonymised ids, counts, statuses, field
names, no secrets). That lets the live sections of `docs/05` be completed and the data-report
side-by-side comparison finished. **Do not paste** the client secret, any token, or the JSON report if
it contains anything you would rather not share (it is redacted, but your call).

## It will not
deploy anything, change any plugin, call uCRM, touch finance records, reboot a device, or mutate any
Starlink object. It only reads.
