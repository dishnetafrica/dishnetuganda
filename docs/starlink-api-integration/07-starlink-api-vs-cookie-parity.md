# 07 — Parity: official API vs the existing cookie Data Report (parallel, read-only)

**Task (operator):** stand up a **parallel** official-API validation layer that **proves parity**
against the working cookie-based Data Report for the one account the API credential can see —
**without replacing cookies, changing Data Report output, touching Finance, or migrating anything.**
Cookies remain the **known-good production baseline**; the API is **not** made authoritative.

**Evidence labels:** **[LIVE]** live API · **[DOC]** spec · **[CURRENT]** existing plugin data/code ·
**[INFERRED]** inference · **[NV]** not verified (pending the parity tool run).

## 0. The agreed architecture (parallel transition, not replacement)
```
                         STARLINK
                            │
             ┌──────────────┴──────────────┐
      Existing cookies                 Official API
             │                              │
      Current Data Report          API account adapter (new, parallel)
             │                              │
             └──────────────┬───────────────┘
                            ▼
                  Normalized Starlink dataset
                            │
                   ┌────────┴────────┐
                   ▼                 ▼
              Data Report         Finance   ← insulated: keeps consuming normalized files
```
Cookies stay primary per account until that account has a **proven** API replacement; Finance never
depends on the auth method (it keeps reading `sl_invoice_lines.json` / `dr_kit_registry.json`).

## 1. Method [CURRENT]
`probe/starlink_api_vs_cookie.py` (read-only) runs on the server where both the API and the local data
files are reachable. It:
- GETs `/service-lines`, `/user-terminals`, `/billing/invoices` for the API account (GET-only; token
  POST only; no writes);
- **reads** (never writes) `dishnet-data-report/data/{sl_svc_cache,wifi_router_map,dr_invoices}.json`
  and `dishnet-starlink-finance/data/sl_kits.json`;
- joins on `serviceLineNumber` and `kitSerialNumber` and classifies each field
  **EXACT / DIFF / API-only / COOKIE-only**, with a "keys seen" diagnostic so any local key-name
  mismatch is visible and adjustable;
- prints redacted ids + counts. **The production Data Report output is untouched; nothing is written.**

## 2. Field parity matrix — EXPECTED vs LIVE
Expected classification from the mapping analysis (`06`/`20`); **LIVE column filled from the tool run.**

| Field | API source | Cookie source | Expected | LIVE |
|---|---|---|---|---|
| account number | `accountNumber` | `account_number`/`starlink_account_number` | **EXACT** (deterministic) | **[NV]** |
| service line | `serviceLineNumber` | `service_line` | **EXACT** (join key) | **[NV]** |
| kit serial | `kitSerialNumber` | `kit_number`/`kit_serial` | **EXACT** (join key) | **[NV]** |
| dish serial | `dishSerialNumber` | `dish_serial`/`dishSerialNumber` | EXACT where stored | **[NV]** |
| terminal id | `userTerminalId` | `terminal_id` | EXACT where stored | **[NV]** |
| active/status | `active` + `endDate` | `subscription_active` + `isPaused/isSuspended/isStandby`/`sl_status` | **DIFFERENT SEMANTICS** — API has only `active`; cookie has granular pause/standby (`20` SL) | **[NV]** |
| product/plan | `productReferenceId` | `plan_id`/`product_desc` | EXACT (id); plan *name* via `/products` | **[NV]** |
| start/end dates | `startDate`/`endDate` | `start_date`/`subscription_endDate` | EXACT-ish | **[NV]** |
| address | `addressReferenceId` (UUID) | `serviceAddress` (text) | **DIFFERENT REPRESENTATION** — the API UUID is not stored locally (`06`§2 weak key) | **[NV]** |
| usage (GB) | `/data-usage/query` (POST-read) | `sl_usage.json` | NOT YET TESTED (POST; allowance source unknown) | **[NV]** |
| billing/invoices | `/billing/invoices` | `dr_invoices.json` | PARTIAL (count+core fields; no paymentMethod/deposit — `20` INV-L) | **[NV]** |
| uCRM link | — (none) | `crm_client_id`/`crm_service_id` | **COOKIE-only** — API has no uCRM concept | **[CURRENT] COOKIE-only** |

> Run the tool and paste its `FIELD TALLY` blocks; I fill the LIVE column and the counts
> (exact / API-only / cookie-only / diff).

## 3. Features that still REQUIRE cookies regardless of parity [LIVE + DOC, from `20`]
No official-API equivalent exists for these, so they **stay on the cookie/gRPC path** even for a fully
API-covered account:
- **WiFi SSID/password change**, **per-client pause**, **auto-block actuation** (suspend-on-non-pay),
- **dish-online state / client counts** (live device telemetry),
- **orders / shipment tracking**.
These define the permanent **hybrid** boundary; the API can only ever take over the **read/billing/
service** half.

## 4. Starlink Account Registry — DESIGN ONLY (not implemented)
A per-account registry that drives the parallel transition. **Credential references never contain
secrets** (point to the existing encrypted vault / secret store — `06`§10).
```
starlink_account_registry (one row per Starlink account)
  internal_account_id        stable local id
  starlink_account_number    the API accountNumber (deterministic anchor; already in sl_kits.json)
  account_name / region
  auth_method                'cookie' | 'official_api' | 'both'
  credential_ref             REFERENCE to a secret-store entry (NEVER the secret)
  status / access_scope       single_account | managed_parent | managed_child
  last_cookie_success         timestamp
  last_api_success            timestamp
  migration_status            COOKIE_ONLY | API_VALIDATING | API_VERIFIED | API_PRIMARY | COOKIE_FALLBACK
  ucrm_link                   via sl_kits crm_client_id/crm_service_id (already populated 9/9 kits)
```
**Migration-status lifecycle (one account at a time):**
```
COOKIE_ONLY ──(API credential exists)──▶ API_VALIDATING ──(parity proven)──▶ API_VERIFIED
     ▲                                                                           │
     │                                                              (flip read source, per domain)
     │                                                                           ▼
COOKIE_FALLBACK ◀──(API outage / regression)────────────────────────────── API_PRIMARY
```
**Current state (do NOT auto-advance):**
| account | migration_status |
|---|---|
| the one API-tested account | **API_VALIDATING** |
| the other 4 accounts | **COOKIE_ONLY** |

**Nothing is set to API_PRIMARY.** Parity must be proven first; a human promotes each account.

## 5. Risks / guardrails
- Prove parity on the **strong keys** before trusting anything; a DIFF is investigated, never
  auto-reconciled; **historical finance never recomputed from API** (`21`).
- Keep cookies as **COOKIE_FALLBACK** after any flip; no account goes API_PRIMARY without a human.
- Do not make Finance depend on the auth method; it stays behind the normalized layer.
- The registry stores **references**, never secrets; least-privilege, rotated credentials (`06`§10).
- The parity tool is a **one-off diagnostic** — not deployed, not cron'd, not wired into Data Report.

## 6. Final questions (answered once the tool is run)
1. **Can the official API reproduce the current Data Report for the tested account?** [NV → tool]
   — expected **yes for the read/service/billing fields**, with the semantic caveats below.
2. **Which fields are identical?** [NV] — expected: account/SL/kit/dish/terminal ids, product id,
   dates (the deterministic keys).
3. **Which fields differ?** [NV] — expected: **status** (API `active` vs cookie's granular
   pause/standby), **address** (UUID vs text), **billing** (missing paymentMethod/deposit).
4. **Which Data Report features still require cookies?** [LIVE] — WiFi change, per-client pause,
   auto-block actuation, dish/client telemetry, orders (§3).
5. **What is required to migrate one account safely?** [INFERRED] — an API credential for it → parity
   proven (API_VERIFIED) → flag-gated read source flip per domain, shadow files first → cookies
   retained as COOKIE_FALLBACK → Finance untouched.
6. **What should remain unchanged?** [CURRENT] — cookies, account config, current sync, Data Report
   output, Finance consumption, service-line mapping, DB schema. All of it, until parity is proven
   per account and a human promotes it.

---

### FINAL
```
PARITY (tested account):   PENDING the tool run (expected EXACT on deterministic keys;
                           DIFFERENT-SEMANTICS on status/address; PARTIAL on billing; usage untested)
COOKIE-only (permanent):   WiFi change, per-client pause, auto-block actuation, device telemetry, orders
COOKIE-only (data):        uCRM link (crm_client_id) — API has no uCRM concept
REGISTRY STATE:            1 account API_VALIDATING; 4 accounts COOKIE_ONLY; 0 API_PRIMARY
NEXT:                      run probe/starlink_api_vs_cookie.py on the server; paste the redacted
                           FIELD TALLY; then (separately) send the Starlink Option-B email (06 App.B)
```
**Do not migrate. Do not change cookies, Data Report, Finance, schema, or deploy.** Prove parity first.
