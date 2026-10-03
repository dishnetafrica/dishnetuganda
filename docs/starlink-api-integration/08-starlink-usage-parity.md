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

## 2. Live result — 2026-10-03 [LIVE]: NOT COMPARABLE (no cookie baseline for this account)
```
API usage rows (service-lines):            4     (one cycle 2026-09-12..2026-10-12)
cookie sl_usage unique service_lines:      5     (of 27 estate SLs; 35 records total)
API account's 4 SLs present in sl_usage.json raw text:   ABSENT (0 of 4)
total_gb    {API-ONLY: 4}      priority_gb {API-ONLY: 4}      standard_gb {API-ONLY: 4}
SLs with API usage: 4     matched to cookie: 0
```
- **The API returns usage cleanly** for all 4 SLs: one at **79.22 GB (64.11 priority + 15.11 standard)**,
  three at 0 — exactly the `totalPriorityGB`/`totalStandardGB` per-billing-cycle shape (`servicePlan`,
  `lastUpdated` also present).
- **Operator ground truth [CURRENT]: only ONE line is active in this account.** So the three 0-GB lines
  are **inactive** (expected — not missing data), and the API's output (1 active line with real usage +
  3 inactive at 0) **matches reality**. The API also usefully returns the **inactive** lines, which the
  cookie Data Report does not track — more visibility, not less.
- **But the cookie `sl_usage.json` has no record for any of this account's 4 SLs** — confirmed by a raw
  substring search (ABSENT 0/4), so this is **not** a matcher bug. The file retains usage for only
  **5 of the 27** service-lines in the estate.
- **Therefore usage parity is NOT COMPARABLE for this account** — there is no cookie baseline to
  compare against (exactly the "does not retain equivalent data → say so, don't force it" case).

### 2a. Two findings this surfaces
1. **The official API provides usage this account currently lacks on the cookie side** — a point in the
   API's favour (it would *fill* a gap, not regress). [LIVE]
2. **The current cookie Data Report's usage coverage is incomplete — 5 of 27 service-lines retained in
   `sl_usage.json`.** The likely cause is that the cookie usage sync does not cover/retain this
   account's SLs (plausibly this account's cookie session is stale/failed — consistent with the
   operator's "cookie may be expired" observation; the API path, on its own OAuth, was unaffected).
   This is a **current-system reliability/coverage gap**, recorded for the operator — not caused by
   this read-only test. [LIVE/INFERRED]

## 3. Records / counts — [LIVE 2026-10-03]
```
API usage rows (service-lines) = 4          cookie sl_usage records = 35 (5 unique SLs)
matched SLs = 0    API-only = 4    cookie-only = 0 (for this account)    differences (aligned) = 0
API SL strings present in sl_usage.json = 0 of 4 (ABSENT) → confirms not-a-bug
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

## FINAL — [LIVE 2026-10-03]
1. **Usage parity: NOT COMPARABLE for this account** (not a failure). The API returns usage; the cookie
   `sl_usage.json` retains usage for only 5 of 27 SLs and **none** of this account's 4 — so there is no
   cookie baseline to value-compare. Per the operator's rule, this is reported as not-comparable, not
   forced. The API's result is **coherent with ground truth** (operator: 1 active line → 79.22 GB; 3
   inactive → 0).
2. **Exact matching fields:** none at value level (no overlapping cookie record). Field *shape* maps
   cleanly (totalPriorityGB→local_priority_used_gb, totalStandardGB→other_data_gb, sum→total_gb).
3. **Differences:** none (nothing to diff — no overlap). The 3 zero lines are **inactive**, not
   discrepancies.
4. **Semantic differences:** billing-cycle windowing + day-lag (`lastUpdated`) + richer API breakdowns
   (opt-in priority, non-billable, pools, overage) + the API returns **inactive** lines the cookie
   system omits (§4, §2a).
5. **API limitations:** POST-style read, day-lagged, allowance source unconfirmed (§5).
6. **Safe to include usage in the future API read layer?** **YES** — in fact the API is *more* complete
   than the current cookie usage (which covers 5/27 SLs and misses this account entirely). Use the API
   as the usage source where a credential exists, with explicit **period alignment** + a **freshness**
   note, and **never recompute historical figures from current API state**.
7. **Still requires cookies:** live/per-device usage telemetry (dish-online state, client counts) and
   everything in `07`§3 (WiFi, pause, auto-block, orders). **Billing-cycle usage itself does not** — the
   API covers it (and more).

> **A real value-level usage comparison needs an account whose usage the cookie system *does* retain**
> (one of the 5 SLs in `sl_usage.json`) **and** that the API credential can reach. Account #1 fails the
> first condition. So defer the value-level usage match to **Account #2** (operator's next step): if its
> active line is among the cookie-retained set, the same tool yields an EXACT/DIFF comparison there.

### New finding for the operator (not caused by this test)
The cookie Data Report retains usage for only **5 of 27 service-lines**, and **none** of this account's.
That is a **current-system coverage/reliability gap** (plausibly a stale cookie session for this
account — consistent with "cookie may be expired"). It is independent evidence that the official API
would *improve* usage coverage, and it is worth addressing in the current system regardless of migration.

**STOP after this test. No migration.** Next controlled step (operator's sequence): get a read-only API
credential for **Account #2**, run the same parity process (`05`→`07`→`08`) → `API_VERIFIED`, proving
the architecture across accounts — **independent of** whether Starlink grants a managed parent (that
stays a *nice-to-have*, never a prerequisite; per-account credentials + the normalized Data Report layer
are the robust fallback, with Finance fully insulated).
