# 08 — Usage parity: official API vs cookie Data Report (read-only)

**Task (operator):** the final read-only validation before any migration — compare official-API data
usage against the existing cookie Data Report usage for the **tested account**, same period.
**No change to Data Report, cookies, Finance, schema; no deploy; API not made authoritative.**

**Evidence labels:** **[LIVE]** · **[CURRENT]** · **[DOC]** · **[INFERRED]** · **[NV]** (pending run).

## 0. Read-safety of the endpoint [DOC]
`POST /data-usage/query` is a **query** that returns `ServiceLineDataUsageForBillingCycles`. It takes a
filter/pagination body and has **no mutating fields**; an invalid body returns **HTTP 422** (input
validation inside the `ServiceResponse` envelope) — a validation response, **not** a side effect. The
tool (`probe/starlink_usage_parity.py`) sends **only** a filter/pagination body, restricts POST to this
one path + the token endpoint, opens the local `sl_usage.json` **read-only**, saves no raw payloads,
and pseudonymises ids. The credential already scopes to one account, so the query is inherently minimal.

## 1. Field mapping (what we compare) [DOC + CURRENT]
| Concept | Official API (`/data-usage/query`) | Cookie (`sl_usage.json`) |
|---|---|---|
| account | `accountNumber` | `account_number` (per record / via kit) |
| service line | `serviceLineNumber` | `service_line` |
| usage period | billing cycle `startDate`/`endDate` | `cycle_key` / `cycle_label` / `axis_right` |
| total usage | `totalPriorityGB` + `totalStandardGB` | `total_gb` |
| priority (blue) | `totalPriorityGB` (daily `priorityGB`) | `local_priority_used_gb` (`daily_blue[]`) |
| standard (white) | `totalStandardGB` (daily `standardGB`) | `other_data_gb` (`daily_white[]`) |
| allowance | `servicePlan` / overage lines (unconfirmed) | `local_priority_allowance` / `other_data_allowance` |
| units | GB | GB |
| timestamps | `lastUpdated` (often day-lagged) | `updated_at` |
| data blocks/pools | `dataPoolUsage[]`, `overageLines[]`, `optInPriorityGB`, `nonBillableGB` | not stored (lean cache) |

## 2. Live result — **[NV] PENDING the tool run** (fill from `TALLY`)
Per service-line, latest aligned cycle, classified **EXACT / DIFF / API-ONLY / COOKIE-ONLY /
NOT-COMPARABLE**:
```
total_gb      {EXACT: _, DIFF: _, NOT-COMPARABLE: _, API-ONLY: _, COOKIE-ONLY: _}
priority_gb   {…}
standard_gb   {…}
SLs with API usage: _   matched to cookie: _
```
**Expected [INFERRED]:** the GB numbers should align closely **when the periods match** (same cycle),
because the cookie `total_gb`/`local_priority_used_gb`/`other_data_gb` are derived from the same
Starlink usage data the API now returns. Watch for: (a) **period mismatch** → treat as NOT-COMPARABLE,
not DIFF; (b) **freshness** → API `lastUpdated` is typically a day behind, so a tiny delta on the
current cycle is expected, not a failure; (c) the API carries **richer breakdowns** (opt-in priority,
non-billable, pools, overage) the lean cookie cache does not store → **API-ONLY**, a gain.

## 3. Records / counts — **[NV] PENDING**
```
API usage rows (service-lines) = _          cookie sl_usage records = _
matched SLs = _    API-only = _    cookie-only = _    differences (aligned periods) = _
```

## 4. Semantic cautions (do not declare false parity) [DOC]
- **Period/aggregation:** the API aggregates per **billing cycle**; the cookie cache keys cycles its own
  way. Only compare totals for the **same cycle window**. Different windows ⇒ **NOT-COMPARABLE**.
- **Freshness:** API usage is **day-lagged** (`lastUpdated`); the scraped feed may be fresher — a small
  current-cycle delta is a timing artifact, not a discrepancy.
- **Units:** GB both sides; priority=Local-Priority, standard=other — confirmed mapping.
- **History retention:** the cookie `sl_usage.json` may not retain older cycles. If the API has a cycle
  the cookie cache doesn't, that is **"cookie-not-retained,"** not a parity failure — **say so, don't
  force a comparison.**

## 5. API limitations for usage [DOC]
- **POST-style read** (not GET) and **day-lagged**; **allowance** may need `/products` (not confirmed
  in `/data-usage` — `20` USE, `40`-Q-USE-1).
- No per-device/live usage stream (that's the cookie/gRPC telemetry plane, not this endpoint).

---

## FINAL (fill after the run)
1. **Usage parity: [NV] PROVEN / PARTIAL / NOT PROVEN** — *(expected PARTIAL-to-PROVEN: GB aligns on
   matching cycles; API adds breakdowns; period/freshness caveats apply).*
2. **Exact matching fields:** [NV] — expected total/priority/standard GB on aligned cycles.
3. **Differences:** [NV] — expected only period/freshness artifacts, not value conflicts.
4. **Semantic differences:** billing-cycle windowing + day-lag + richer API breakdowns (§4).
5. **API limitations:** POST-read, day-lagged, allowance source unconfirmed (§5).
6. **Safe to include usage in the future API read layer?** [NV] — *expected YES, with explicit period
   alignment + a freshness note; never recompute history from current API state.*
7. **Still requires cookies:** live/per-device usage telemetry (dish-online, client counts) and
   everything in `07`§3 (WiFi, pause, auto-block, orders). Billing-cycle usage itself does **not**.

**STOP after this test. No migration.** Next controlled step (operator's sequence): get a read-only API
credential for **Account #2**, run the same parity process (`05`→`07`→`08`) → `API_VERIFIED`, proving
the architecture across accounts — **independent of** whether Starlink grants a managed parent (that
stays a *nice-to-have*, never a prerequisite; per-account credentials + the normalized Data Report layer
are the robust fallback, with Finance fully insulated).
