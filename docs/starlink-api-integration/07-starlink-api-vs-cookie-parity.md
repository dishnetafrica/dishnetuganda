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

> **Implementation status (2026-10-03):** the "API account adapter" box above is now **built** — a
> parallel, **flag-OFF**, read-only adapter in the data-report plugin on branch
> **`claude/official-api-shadow-adapter`** of `dishnetafrica/datareport` (`official_api/official_api_sync.php`).
> It fetches the one credentialled account via the official API and writes **shadow** files under
> `data/api_shadow/` only; it is inert unless `DR_OFFICIAL_API_SYNC=yes` + env credentials, touches no
> existing file or the cookie path, and is **not deployed** (the operator installs it when ready).
> This realizes the parallel source without changing anything the plugin already produces.
>
> **Verified on the server 2026-10-03 (via the PHP-streams fallback; host php lacks ext-curl):** the
> adapter ran and wrote `data/api_shadow/` for the credentialled account — **4 service-lines, 1
> user-terminal, 8 addresses, 5 invoices, 8 usage rows** (4 SLs × 2 billing cycles). Usage is coherent
> with ground truth: the one **active** line shows **79.22 GB (64.11 priority + 15.11 standard)** for the
> current cycle; the three inactive lines show 0. No existing file was read or written; the cookie path
> and current Data Report output are unchanged. (Three small PHP fixes were needed first — POST-guard
> query-string, empty-object body encoding, and a param type hint — all caught and fixed; a local
> full-flow smoke test now guards the path.)
>
> **Panel fallback BUILT 2026-10-03 (cookie-first, FLAG-OFF):** the dashboard now fills usage from the
> shadow **only for kits with no cookie usage** (cookie stays primary), so a kit whose cookie session
> is stale shows real data instead of "no usage yet". Two helpers in `public.php` (`drOfficialApiUsageRows`,
> `drMergeShadowUsage`) wired at the three usage pools (two portal `$drAllUsage`, fleet `$allUsage`→
> `$usageIdx`). Gate: `data/api_shadow/VIEW_ENABLED` file or `DR_OFFICIAL_API_VIEW=yes`; **default off =
> byte-identical** (smoke-tested). Delivered as a verified patch
> (`patches/public_php_official_api_fallback.patch`, applies cleanly to the deployed v2.8.80, result
> lints) — not deployed. Known gap: daily-trend arrays aren't in the shadow yet (hero total + split +
> history total populate; per-day chart empty for shadow-only kits). Canonical: datareport branch
> `claude/official-api-shadow-adapter`.

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

| Field | API source | Cookie source | Expected | **LIVE (2026-10-03, tested account)** |
|---|---|---|---|---|
| account number | `accountNumber` | `account_number`/`starlink_account_number` | EXACT | **COOKIE-only (artifact)** — the *direct* `/service-lines` & `/user-terminals` rows omit `accountNumber`; it IS available via `/account` and the managed endpoints, so **not a real gap** |
| service line | `serviceLineNumber` | `service_line` | EXACT (join) | **EXACT 4/4** ✓ |
| kit serial | `kitSerialNumber` | `kit_number`/`kit_serial` | EXACT (join) | **EXACT 1/1** ✓ (join succeeded) |
| dish serial | `dishSerialNumber` | `dish_serial`/… | EXACT where stored | **API-only** — the API *provides* it; local doesn't store it (API adds data) |
| terminal id | `userTerminalId` | `terminal_id` | EXACT where stored | **EXACT 1/1** ✓ |
| active/status | `active` (+`endDate`) | `subscription_active`/… | DIFFERENT SEMANTICS | **EXACT 4/4** ✓ for `active` (no DIFF). Granular pause/standby is **not persisted** in the lean live cache anyway — see §2a |
| product/plan | `productReferenceId` | `plan_id`/`product_desc` | EXACT | **EXACT 4/4** ✓ |
| start/end dates | `startDate`/`endDate` | `start_date`/`subscription_endDate` | EXACT-ish | `end_date` both-empty 4/4; `start_date` **API-only 1** (API adds), else both-empty |
| address | `addressReferenceId` (UUID) | `serviceAddress` (text) | DIFFERENT REPRESENTATION | not compared by the tool; API returns `addressReferenceId`, local cache has no address field → **API-only/different** |
| usage (GB) | `/data-usage/query` (POST-read) | `sl_usage.json` | NOT YET TESTED | **[NV]** (POST-read; separate follow-up) |
| billing/invoices | `/billing/invoices` | `dr_invoices.json` | PARTIAL | API **5** invoices for this account; local `dr_invoices` holds **43** across all 5 accounts (count-consistent for this account) |
| uCRM link | — (none) | `crm_client_id`/`crm_service_id` | COOKIE-only | **COOKIE-only 1/1** ✓ (confirmed — API has no uCRM concept) |

### 2a. Live result — VERDICT: parity PROVEN for the tested account's read fields [LIVE]
- **Zero DIFFs.** Every field where both sides hold a value is **EXACT** (service_line, kit serial,
  terminal_id, `active`, product_ref). The API even **adds** data the cookie cache lacks (dish serial,
  a start_date). 4/4 SLs and 1/1 kit matched the local records by the deterministic keys.
- **`account_number` COOKIE-only is a tool/endpoint artifact**, not a gap: the direct `/service-lines`
  and `/user-terminals` row shapes don't echo `accountNumber` (it's on `/account` + the managed
  endpoints). The account is fully known.
- **`crm_link` COOKIE-only is permanent and correct** — uCRM linkage is DishNet's, not Starlink's.
- **New finding — the live cookie cache is LEAN.** `sl_svc_cache.json` persists only
  `account_number, has_telemetry, kit_number, plan_id, product_desc, service_line` (6 fields). The rich
  per-SL status/pause/standby fields the source *reads* are **not stored** on this server — so the
  official API (which returns `active`, dates, dish serial, address ref) actually **exposes more
  per-SL data than the cookie cache currently keeps**, not less. The granular pause/standby concern
  (`20` SL) is moot for the *stored* dataset.
- **Conclusion:** for the read/service/billing surface, the official API **reproduces or exceeds** the
  cookie Data Report for this account. The account is **eligible to move COOKIE_ONLY → API_VALIDATING →
  API_VERIFIED** in the registry (§4) — but **NOT** API_PRIMARY (human decision; cookie fallback
  retained; and the device/orders features in §3 still require cookies).

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
PARITY (tested account):   PROVEN (LIVE 2026-10-03) — EXACT on all overlapping read fields
                           (service_line, kit serial, terminal_id, active, product_ref); ZERO DIFFs;
                           API ADDS dish serial + a start_date. account_number omitted by the direct
                           endpoints (artifact, available via /account+managed). usage untested (POST).
COOKIE-only (permanent):   WiFi change, per-client pause, auto-block actuation, device telemetry, orders
COOKIE-only (data):        uCRM link (crm_client_id) — API has no uCRM concept
REGISTRY STATE:            tested account now eligible API_VALIDATING→API_VERIFIED (parity proven);
                           4 accounts COOKIE_ONLY; 0 API_PRIMARY (human-promoted only)
NEXT:                      (1) rotate the exposed secret; (2) send the Starlink Option-B email
                           (06 App.B); (3) optionally test usage parity (POST /data-usage). No migration.
```
**Do not migrate. Do not change cookies, Data Report, Finance, schema, or deploy.** Prove parity first.
