# Starlink API integration assessment — doc set index

**What this is.** A **read-only discovery and mapping assessment** (operator request,
2026-10-03) of two uCRM plugins —

- **`dishnet-data-report`** (v2.8.80) — the Starlink data/usage + WiFi control-plane plugin, and
- **`dishnet-starlink-finance`** (v7.3.9) — the finance + CRM-link plugin —

against the **official Starlink Public API V2** (OpenAPI 3.0.4). The goal is to establish, item
by item, **which existing functions could be served by the official API, and whether each
current behaviour can be preserved exactly** — *without changing any code, SQL, migration,
plugin setting, feature flag, or deployment, and without making any mutating Starlink call.*

**The central finding that frames everything.** The installed plugins do **not** use the
official V2 API. They use an **unofficial, cookie-authenticated** set of web/app endpoints
(`api.starlink.com/auth-rp/…`, `/webagg/…`, `account(s)/v1/…`, `subscriptions/v1/…`,
`telemetryagg/…`, `usage/…`, `SpaceX.API.Device.Device/Handle`, `webagg billing`). So this is a
**migration + field-parity** study, not a config review — and some scraped data may have **no
official equivalent** (see `01` §D and `40`).

---

## Strict boundaries this assessment operates under (operator, verbatim intent)

- Read-only discovery; **documentation changes only**. No application code, SQL schema,
  migration, plugin setting or feature-flag change. No deploy; no production modification.
- **No mutations against the live Starlink API.** Do not create/update/cancel services,
  subscriptions or orders; do not trigger customer messages, invoices, payments or postings.
- **Never** print, expose, commit or copy API secrets, access tokens, cookies, or sensitive
  customer data into these docs.
- **Preserve all existing working Data Report and Finance behaviour** — preservation is the
  priority, not replacement.
- Do **not** assume unverified API capabilities. **uCRM/accounting stays authoritative** for
  financial records; an API service record or charge is **not** proof of payment or recognized
  revenue, and historical financial results are never silently recalculated from current API
  data.
- **No implementation without separate operator approval.**

---

## The doc set (8 required deliverables → these files)

| File | Deliverable | Status |
|---|---|---|
| `00-README.md` | this index | ✅ |
| `01-official-api-v2-catalogue.md` | **#4** verified official-API V2 endpoint/field catalogue | ✅ written (from the supplied spec) |
| `05-official-api-live-access-test.md` | **live-access validation record** (credential scope, org-wide-vs-single-account, side-by-side) | ✅ **EXECUTED live** 2026-10-03: single-account (1 acct/4 SLs/1 kit); broader perms than the screenshot |
| `06-starlink-account-mapping.md` | existing (cookie-era) ↔ official-API **account mapping** + registry/credential design + Starlink email | ✅ written; **deterministic mapping** (account/SL/kit keys already stored; 9/9 kits uCRM-linked); per-account credentials or managed hierarchy needed |
| `07-starlink-api-vs-cookie-parity.md` | **parallel parity** plan: API-vs-cookie field comparison + account registry w/ migration states | ✅ written; **live parity results PENDING the tool run**; cookies stay primary |
| `probe/starlink_api_probe.py` · `starlink_kit_lookup.py` · `starlink_api_vs_cookie.py` · `README.md` | **safe read-only** probe · per-kit lookup · **API-vs-cookie parity** tool | ✅ written (compile clean; no secret; read-only on API + local files) |
| `10-baseline-data-report.md` | **#1a** baseline inventory — data-report | ✅ written (from real source) |
| `11-baseline-finance.md` | **#1b** baseline inventory — finance | ✅ written (from real source v7.3.9) |
| `20-mapping-data-report.md` | **#2** exhaustive function→API mapping — data-report (10-column table) | ✅ written |
| `21-mapping-finance.md` | **#3** exhaustive function→API mapping — finance | ✅ written (finance = almost all "preserve") |
| `30-shared-architecture.md` | **#5** cross-plugin shared-architecture recommendation | ✅ written |
| `40-gaps-and-questions.md` | **#6** missing-capabilities + unresolved-questions list | ⏳ accumulates across the set |
| `50-regression-matrix.md` | **#7** regression test matrix | ⏳ follows the mappings |
| `60-migration-plan.md` | **#8** phased implementation + rollback plan | ⏳ last (needs all above) |

Mapping-table columns (files `20`/`21`), as specified by the operator: existing function ·
current data source · output + purpose · fields used · official-API endpoint + fields ·
transformation needed · **can behaviour be preserved exactly? (Yes / Partial / No / Unknown)** ·
missing API fields · recommended approach · tests + regression risks.

---

## Sources used (and their trust level)

- **Official API (spec):** the V2 OpenAPI **3.0.4** JSON the operator pasted verbatim
  (`starlink.com/api/public/swagger/v2`). `starlink.com` is egress-blocked here, so **no live
  call was or will be made from this session.** Auth-flow and rate-limit specifics not in the JSON
  are labelled **[D]** (documented) vs **[V]** (verified in JSON) in `01`.
- **Official API (live):** the operator later created a real OIDC **service-account credential**.
  This session still cannot reach `starlink.com` (egress `403`), so the **live** validation is done
  by the operator running `probe/` and pasting the redacted result into `05`. The credential/secret
  are **never** stored or committed here (and the shared secret should be **rotated** — `05`§12).
- **data-report:** real source — `dishnetafrica/datareport` @ `012810d6` (== the deployed
  v2.8.80 plugin), cloned read-only to `/home/user/datareport`.
- **finance:** real source attached by the operator as `dishnet-starlink-finance` **v7.3.9** (ZIP),
  extracted read-only. Confirmed by grep: it makes **no direct Starlink call** — all Starlink-origin
  data arrives by reading data-report's sibling files. Deep inventory running for `11`/`21`.
- **Prior in-repo audit:** `dishnet-hybrid-sudan/docs/39-PROVIDER-SIDE-AUDIT.md` — an earlier
  read-only provider-side audit of *both* plugins; used for cross-checking, not as a substitute
  for reading finance's own source.

---

## Reading order

New reader → `01` (what the official API can and cannot do) → `10` then `20` (data-report, what
it does today and how each piece would map) → `11`/`21` (finance, when unblocked) → `30`
(one client or two, secrets, caching, sync) → `40` (what is missing / undecided) → `50`
(how we would prove no regression) → `60` (how we would roll it out and back).

*Assessment only. Ends with an executive summary and a stop for approval — see `60` once the
set is complete.*
