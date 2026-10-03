# 21 — Function→official-API mapping: `dishnet-starlink-finance` v7.3.9

**Deliverable #3.** The item-by-item mapping for the finance plugin, covering the areas the operator
named: kits, service/customer links, service states, subscriptions, charges, hardware costs/margins,
invoices/payments/credit-notes/balances, revenue, reconciliation, manual adjustments.
**Read-only / assessment only — recommends, decides nothing, changes nothing.**

> **The framing is different from data-report (`20`).** Finance **makes no Starlink call** (`11`§B,
> verified). It is a **uCRM-read + flat-file** engine that consumes Starlink-origin data **only** via
> data-report's files. So for almost every finance item the "official API endpoint" is **N/A** — the
> data is **uCRM-authoritative** (price, payment, balance) or **operator-authored** (sales invoices,
> margins, manual status), or it is **Starlink-origin but reaches finance indirectly** through
> data-report (invoice lines, live status, usage), in which case the official API would feed
> *data-report*, and finance keeps consuming the **same file contract** (`30`).

**Verdict legend (finance-specific):**
- **Preserve — uCRM-authoritative:** must NOT be driven from the Starlink API. Keep as-is.
- **Preserve — operator-authored:** manual/finance records; the API has no concept of them.
- **Indirect-Partial:** Starlink-origin; the official API can supply the upstream (via data-report),
  with the field losses from `20`; **the finance file contract must be preserved**.
- **Indirect-No:** Starlink-origin but **no** official equivalent (orders); stays scraped or retires.

Columns per item: *Current source · Output+purpose · Fields used · Official V2 relationship ·
Transformation · Verdict · Missing/at-risk fields · Recommended approach · Tests & regression risks.*

---

## Summary

| Finance area | Nature | Official-API relationship | Verdict |
|---|---|---|---|
| KITs / inventory | Finance-authoritative (`sl_kits.json`) | identity cross-refs via data-report SL/UT | **Preserve** (+ indirect id refresh) |
| Service↔customer links | operator-set `crm_client_id`/`crm_service_id` | **none** (Starlink has no uCRM link) | **Preserve — operator-authored** |
| Service states (uCRM enum) | uCRM `clients/services.status` | **none** (uCRM is the status) | **Preserve — uCRM-authoritative** |
| Live Starlink status (mismatch chip) | `dr_kit_registry.json` (via data-report) | `/service-lines.active`+`endDate` | **Indirect-Partial** |
| Subscriptions / plan + price | uCRM service `totalPrice`/`servicePlanName` | `/products` (catalog only) | **Preserve — uCRM-authoritative** |
| Charges / billing calc | `BillingCalculator` (local) | **none** | **Preserve — operator-config** |
| Hardware cost / margin | OCR + operator sales invoices | **none** | **Preserve — operator-authored** |
| Starlink invoices (COST) | `sl_invoices.json` via PDF + data-report | `/billing/invoices/{id}` (via data-report) | **Indirect-Partial** |
| Customer payments / balances | uCRM `invoices.amountPaid` | **none** (not a Starlink concept) | **Preserve — uCRM-authoritative** |
| Credit notes | none (refund flags only) | **none** | **Preserve** (gap both sides) |
| Recognized revenue | display-only (uCRM + sales invoices) | **none** | **Preserve — uCRM/operator; never from API** |
| Reconciliation (leakage) | `calculateLeakage` (4 sources) | consumes the above | **Preserve — must keep sources separate** |
| Manual adjustments | `save_kit_mapping` + overrides | **none** | **Preserve — operator-authored** |
| Usage (display) | `sl_usage.json` (via data-report) | `/data-usage/query` (via data-report) | **Indirect-Partial** |

**Headline:** **finance is almost entirely "preserve".** Only the three Starlink-origin inputs it
consumes from data-report (invoice lines, live status, usage) have any API relationship, and that
relationship is **upstream in data-report, not in finance** — finance keeps reading the same files.
**The official API introduces no customer price, payment, balance, or revenue** and must never be used
to compute or recompute any of them.

---

## KITs / inventory → **Preserve** (+ indirect id refresh)
- **Current:** `sl_kits.json` (Finance source of truth), keyed by kit serial; `autoEnrichKitData`.
- **Official V2 relationship:** the kit serial ↔ SL ↔ UT identity data is re-sourceable in *data-report*
  from `/user-terminals` (`kitSerialNumber`, `userTerminalId`, `serviceLineNumber`) + `/service-lines`
  (`20` SL). Finance would see the same identities through the registry/cache files it already reads.
- **Verdict:** Preserve the file + the "others must not write" contract; finance gains nothing by
  calling the API itself.
- **Missing/at-risk:** none for finance (identity carries over). Upstream caveats are in `20` SL.
- **Tests & regression:** golden-file `sl_kits.json` before/after any data-report migration; assert
  kit↔SL mapping unchanged for the known fleet.

## Service↔customer links (`crm_client_id`/`crm_service_id`) → **Preserve — operator-authored**
- **Current:** set by the KIT Mapping modal (`save_kit_mapping`), protected by
  `crm_service_id_manually_fixed_at`; reassignment writes `pending_actions.json` for a human.
- **Official V2 relationship:** **none** — the Starlink API has **no uCRM/customer linkage** concept.
- **Verdict:** Preserve exactly. **Never infer a customer from Starlink data** (and never from a phone
  number — the estate's standing rule).
- **Tests & regression:** assert the STALE-REMAP override marker still suppresses auto-repoint; the
  known silent-overwrite bug (v7.3.8 fix) must not regress.

## Service states → **Preserve — uCRM-authoritative** (+ Indirect-Partial live signal)
- **Current:** uCRM `clients/services.status` (`1=active, 2=ended, 3=suspended, 7=quoted`), the manual
  `starlink_account_status`, and the live `drLiveStatus` from `dr_kit_registry.json`.
- **Official V2 relationship:** uCRM status has **no** API equivalent (it is uCRM's). The **live
  Starlink** signal (`dr_kit_registry`) maps to `/service-lines.active`+`endDate` — but the official
  API has **no** granular `isPaused/isSuspended/isStandby/canPause` (`20` SL), so the live signal
  would **lose granularity** if re-sourced from V2.
- **Verdict:** Preserve uCRM status as authoritative; the live chip is **Indirect-Partial**.
- **Missing/at-risk:** the paused/standby/suspended nuance the mismatch chip shows today.
- **⚠ Pre-existing bug to flag (do not fix here):** `status==3` is read two ways —
  "suspended" (`public.php:11865`) vs "Prepared/still billing" (`:1634`). Any V2-sourced status change
  must not deepen this; the inconsistency should be resolved **before** touching status logic.
- **Tests & regression:** assert `drIsCrmMismatch` still fires on the same fleet cases; pin the
  status-code map; add a test around the `status==3` dual meaning when it is eventually reconciled.

## Subscriptions / plan + price → **Preserve — uCRM-authoritative**
- **Current:** plan name + price from the uCRM service (`totalPrice`, `servicePlanName`); local
  catalogs operator-maintained.
- **Official V2 relationship:** `/products` gives the **Starlink plan catalog + Starlink's price to
  DishNet** — which is the **cost** side, not the **customer** price. They are different numbers.
- **Verdict:** Preserve uCRM/operator price as the **customer** price. The API `/products` price may
  inform **cost**, never customer billing.
- **Missing/at-risk:** conflating Starlink's catalog price with the customer's contracted price.
- **Tests & regression:** assert customer price continues to come from uCRM/operator, never from API.

## Charges / billing calculation → **Preserve — operator-config**
- **Current:** `BillingCalculator` (gap, proration, VAT-on-cost profit, alignment score); VAT 0.20,
  currency incl. SSP/KES/UGX.
- **Official V2 relationship:** **none** — these are DishNet's own commercial calculations.
- **Verdict:** Preserve exactly. **Flag (pre-existing):** two profit conventions coexist (ex-VAT
  `public.php:3143` vs VAT-inclusive `:11095`) — record, don't change here.
- **Tests & regression:** unit-pin the formulas (there are no tests today — `50`); any API work must
  not touch these.

## Hardware cost / margin → **Preserve — operator-authored**
- **Current:** OCR of order screenshots + invoice hardware lines; margin in DishNet sales invoices
  (`cost_price` vs sale; `gross_profit`, `margin_pct`).
- **Official V2 relationship:** **none** for cost/margin. (V2 `/billing` invoice lines are Starlink's
  charges to DishNet — a cost input — but carry **no `msrpPrice`/`adjustment`**, `20` INV-D.)
- **Verdict:** Preserve. Margins are a DishNet construct the API cannot supply.
- **Tests & regression:** golden-file `dishnet_sales_invoices.json`; assert margin math unchanged.

## Starlink invoices (COST side) → **Indirect-Partial** (via data-report)
- **Current:** `sl_invoices.json` built from (1) operator PDF upload, (2) auto-import of data-report's
  `sl_invoice_lines.json` (field map `InvoiceProcessor.php:236-259`); dedup by `invoice_number`.
- **Official V2 relationship:** the upstream `sl_invoice_lines.json` maps to `/billing/invoices/{id}`
  **in data-report** (`20` INV-D) — **and is Financial-permission-gated** (`05`§2: currently ungranted).
  Finance keeps consuming the same file.
- **Transformation:** none in finance **if** data-report preserves the `sl_invoice_lines.json` schema.
- **Verdict:** Indirect-Partial. **The file contract is a hard preservation boundary.**
- **Missing/at-risk:** `msrpPrice`/`adjustment` (not in V2); `paymentMethod`/`paymentAmount`
  (data-report list-level, not in V2). The PDF-upload path stays as a fallback and for pre-API history.
- **⚠ Operator rule (critical):** these are **DishNet's COST from Starlink**, never customer payment or
  revenue. **Never recalculate historical `sl_invoices.json` from current API state** — refunds,
  cancellations, and re-dated invoices would corrupt closed-period figures. Append + dedup only.
- **Tests & regression:** golden-file `sl_invoices.json`; assert dedup-by-`invoice_number` and that
  `invoice_gone`/refund flags still skip; assert no historical row is rewritten by a re-import.

## Customer payments / balances → **Preserve — uCRM-authoritative**
- **Current:** uCRM `invoices` (`status 3=Paid/4=Void`, `amount_due = total − amountPaid`).
- **Official V2 relationship:** **none.** The Starlink API has **no concept of the customer paying
  DishNet.** (V2 `/billing/balance` is what **DishNet owes Starlink** — the opposite direction.)
- **Verdict:** Preserve exactly. **A Starlink charge is not proof a customer paid** (operator's rule).
- **Tests & regression:** assert payment/balance continue to derive from uCRM only.

## Credit notes → **Preserve** (gap on both sides)
- **Current:** no explicit credit-note object; refunds as `api_is_refunded`/`invoice_gone` flags.
- **Official V2 relationship:** **none** (no credit-note endpoint). V2 has `isRefunded`-type status
  only indirectly.
- **Verdict:** Preserve; neither side models credit notes — record as a shared gap (`40`).

## Recognized revenue → **Preserve — uCRM/operator; NEVER from API**
- **Current:** **no recognition engine.** Display-only: expected = Σ uCRM service `totalPrice`;
  collected = uCRM `amountPaid`; per-KIT revenue from operator sales invoices.
- **Official V2 relationship:** **none.** The API has **no revenue concept** whatsoever.
- **Verdict:** Preserve exactly. **The official API must never be used to compute, recognize, or
  recalculate revenue.** This is the single most important finance invariant.
- **Tests & regression:** assert revenue figures continue to derive only from uCRM + operator sales
  invoices; add a guard test that no API field feeds a revenue total.

## Reconciliation (leakage) → **Preserve — keep the sources separate**
- **Current:** `calculateLeakage` compares four sources (manual status, uCRM status, Starlink-invoice
  cost, sales-invoice revenue) and flags leakage; `billing_recon`/`financial_recon` tabs.
- **Official V2 relationship:** it **consumes** the above; the API could improve the Starlink-cost and
  live-status inputs (via data-report) — `/billing` per-line `serviceLineNumbers[]` would actually
  **strengthen** cost↔SL reconciliation (`20` INV-D).
- **Verdict:** Preserve. The reconciler's value is precisely that it **does not conflate** Starlink
  cost with uCRM billing — any API work must keep the sources distinct, never merge them.
- **Tests & regression:** assert leakage flags reproduce on the known fleet cases; assert cost and
  revenue inputs remain from distinct sources.

## Manual adjustments / operator edits → **Preserve — operator-authored**
- **Current:** `save_kit_mapping` overridable fields; override protection via
  `crm_service_id_manually_fixed_at`.
- **Official V2 relationship:** **none** — operator overrides are DishNet's authority over its own
  records.
- **Verdict:** Preserve exactly. API data may **inform** an operator, never silently overwrite an
  override.
- **Tests & regression:** assert overrides survive a sync; assert API-sourced values never clobber a
  manually-fixed field.

---

## Finance-side conclusions (carried to `40`/`50`/`60`)

1. **Finance needs no migration.** It is a consumer; the only Starlink-origin inputs (invoice lines,
   live status, usage) are migrated **upstream in data-report**, and finance keeps its file contracts.
2. **Preserve the file interfaces** `sl_invoice_lines.json` / `dr_kit_registry.json` / `sl_usage.json`
   exactly; any reshape in data-report must update finance in lockstep (`60`).
3. **The money invariants are absolute:** customer price/payment/balance/revenue are uCRM + operator,
   **never** from the Starlink API; Starlink invoices are **cost**, append-and-dedup, **never**
   recalculated from current API state.
4. **Two pre-existing finance issues to resolve before status/number logic is touched** (flagged, not
   fixed here): the `status==3` dual interpretation, and the two profit conventions.
5. **Security items** (`11`§H) — PII in the shipped export, plaintext WiFi passwords, CORS-open WiFi
   API — are separate from this assessment but should be tracked.

*Finance half complete. Consolidated gaps/questions (`40`), the regression matrix (`50`), and the
phased plan + rollback (`60`) are strongest written against the live probe results (`05`); `05` already
carries interim versions of each. No code, schema, flag, or deployment is touched.*
