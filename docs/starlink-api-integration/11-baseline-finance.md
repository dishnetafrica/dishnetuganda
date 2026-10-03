# 11 — Baseline inventory: `dishnet-starlink-finance` v7.3.9

**Deliverable #1b.** What the finance plugin **is and does today**. **Read-only**: produced from the
real source the operator supplied (`dishnet-starlink-finance` v7.3.9 ZIP, extracted read-only), read,
not run. File:line citations point into that tree. **No secret, credential, or customer PII is
reproduced here** (one shipped data file contains live PII — flagged in §H and `40`, never quoted).

> **The fact that governs the finance half.** This plugin makes **zero direct Starlink calls** —
> verified twice (grep + full inventory). It is a **uCRM-read + flat-file finance engine**. All
> Starlink-origin data reaches it **only by reading `dishnet-data-report`'s sibling cache files**.
> It also **never writes customer financials back to uCRM** (every uCRM call is a read; the one
> write is an advisory client-log note from the WiFi module) and **recognizes revenue nowhere**.
> So in the integration assessment, finance is **not** a migration target — it is a **downstream
> consumer** whose file contracts and financial authority must be **preserved** (`21`).

---

## A. Identity & shape
- `manifest.json`: name `dishnet-starlink-finance`, **v7.3.9**, display "Dishnet Starlink Finance +
  WiFi + Leakage (Unified UI)". **Admin-iframe only**, no client-zone page. `executionPeriod` 60 s
  (self-throttles the heavy task to 1/hour). **No `configuration[]`** — config lives in its own JSON.
  `ucrmVersionCompliability` **misspelled** (same as data-report) — a shared M-1 nit (`40`).
- **Storage is 100% flat JSON under `data/`. No SQLite/PDO/mysqli anywhere** (grep: zero DB hits).
  Helpers `loadJSON`/`saveJSON` do atomic temp-rename + one `.bak` (`public.php:162-183`).
- **Entry points:** `public.php` (**13,692 lines / 916 KB** — the whole UI + every `action`/`tab`);
  `main.php` (cron — billing alerts + the hourly Starlink-invoice auto-import from data-report);
  `wifi_manager_api.php` (a password-change *request queue*, §G); `diagnostics.php` (dev probe).
- **Own RBAC is opt-in** (`hasPermission`, viewer<editor<admin): the guard runs only when
  `$securityEnabled`, and **defaults to `admin` when security is disabled** (`public.php:1442-1528`).
  Primary access control is uCRM's admin-iframe gating.
- **Tests: none** (no `tests/`, no harness). Same offline-unverifiability regression risk as
  data-report (`50`).
- **`lib/`:** `BillingCalculator` (gap/margin/proration/alignment/currency math), `ConfigHelper`
  (VAT 0.20, currency USD), `InvoiceProcessor` (ingests Starlink invoices from PDF **and** the
  data-report cache into `sl_invoices.json`), `PDFTextExtractor` (pure-PHP invoice PDF parser),
  `OrderImageParser` (OCR of order screenshots).

## B. External calls — and the decisive question answered
**No direct Starlink call of any kind.** Every outbound host:

| Host / endpoint | Class | Direction |
|---|---|---|
| `{ucrmLocalUrl}/api/v1.0/*` with `X-Auth-App-Key` | **uCRM REST** | **read** (`apiGet`): `clients`, `clients/services`, `invoices` |
| `clients/{id}/logs` (POST) | uCRM REST | **the only uCRM write in the whole plugin** — advisory log note, from the WiFi module |
| `oauth2.googleapis.com` / `www.googleapis.com/drive` | **Google Drive** | encrypted off-site backup of Finance's own `data/` |
| SMTP `fsockopen` | Operator SMTP | alert emails |

> **`apiPost`/`apiPatch` are *defined* but *never called*** (`public.php:78,100`). Finance does **not**
> mutate uCRM services/invoices/status. KIT reassignment instead writes a human to-do list
> (`pending_actions.json`) for an accountant to apply in uCRM. **uCRM is a read source + a
> human-actioned system of record.** No MikroTik/RouterOS/UNMS/device call exists here.

## C. Data files (producer → consumer)
**Finance-AUTHORITATIVE financial records** (declared in `CLAUDE.md:204-255`, "never wipe without
backup"; the prior audit's `sl_kits`/`sl_accounts` claim is **verified**):

| File | Holds | Authority |
|---|---|---|
| `sl_kits.json` | KIT inventory, status, CRM mapping, billing fields, history | **source of truth** (`READ_ME_FIRST.md:107`) |
| `sl_accounts.json` | Starlink account list | Finance-authoritative |
| `sl_invoices.json` | **Starlink invoices (DishNet's COST)** — PDF + API import | Starlink-origin, held in Finance; **append + dedup by `invoice_number`** |
| `sl_purchases.json` | kit purchase cost | Finance-authoritative |
| `sl_reconciliations.json` | invoice↔KIT mappings | Finance-authoritative |
| `sl_orders.json` | open hardware orders (OCR'd) | Finance-authoritative |
| `dishnet_sales_invoices.json` | **DishNet→customer invoices, with `cost_price` + margin** | **operator-authored** |
| `delivery_notes.json`, `kit_history.json`, `wifi_requests.json`, `gateway_status.json` | docs / audit / WiFi queue | Finance-authoritative |

**uCRM-origin caches** (regenerable): `crm_clients_cache`, `crm_services_cache`,
`crm_invoice_export` (**⚠ ships with real customer invoice PII — §H**), `crm_starlink_reference` /
`sl_starlink_ref` (Starlink-only service totals).

**Cross-plugin READ-ONLY from `../dishnet-data-report/data/`** (code comment "never writes there",
`public.php:992`): `sl_invoice_lines.json` (invoice line detail → Finance cost),
`dr_orders.json` + `sl_svc_cache.json` (KIT resolution chain), `dr_kit_registry.json` (live
subscription status), `sl_usage.json` (usage). **These are the only channel by which Starlink-origin
truth enters Finance.**

## D. The financial model (summary — full official-API mapping in `21`)
- **KIT** is the atomic unit; `sl_kits.json` the source of truth; `autoEnrichKitData()` refills KIT
  fields from uCRM services/invoices on every render (`public.php:1904`).
- **Service↔customer link:** `crm_client_id` + `crm_service_id` set by the **KIT Mapping** modal
  (`save_kit_mapping`, `public.php:8409-8572`), which on reassignment writes `pending_actions.json`
  (human applies in uCRM) and sets the override marker `crm_service_id_manually_fixed_at`.
- **STALE SERVICE REMAP** (`autoEnrichKitData`, `public.php:3062-3104`): per render, a KIT whose
  service is `status===2` (ENDED) and has **no** manual-fix marker is auto-repointed to that client's
  `status==1` service (copying price→revenue, plan). v7.3.8 tightened this to `===2` only and made the
  modal set the marker — the silent-overwrite fix in the changelog.
- **Service states (quoted from code, `public.php:11862-11871`):** uCRM `1=active, 2=ended,
  3=suspended (temporary — reactivates on payment), 7=quoted`; `0` never appears. Plus the
  operator-curated `starlink_account_status` and the live `drLiveStatus()` from `dr_kit_registry.json`
  (24 h freshness gate), compared by `drIsCrmMismatch()` for the "CRM≠Starlink" chip.
  - **⚠ Pre-existing inconsistency:** `syncStarlinkServices` still treats `status==3` as
    "Prepared/postponed, still billing" (`public.php:1634`), contradicting the verified "suspended"
    (`:11865`). Which is production-correct is **UNVERIFIED** (`40`-Q-STATUS).
- **Plans/price:** sourced from the **uCRM service** (`totalPrice`, `servicePlanName`); local
  plan/hardware catalogs are operator-maintained. **Customer price is uCRM-authoritative.**
- **Hardware cost/margin:** order screenshots OCR'd (`OrderImageParser`) + invoice hardware lines
  (`PDFTextExtractor`); margin realized in operator-authored sales invoices (`cost_price` vs sale).
- **Invoices (cost side)** → `sl_invoices.json` via (1) operator PDF upload, (2) **hourly auto-import
  of `sl_invoice_lines.json` from data-report** (field map documented `InvoiceProcessor.php:236-259`);
  dedup by `invoice_number`; `invoice_gone`/refunded skipped; `.api_sync_state.json` tracks pipeline
  health (the dashboard Auto-Import KPI tile).
- **Customer payments / balances** come from **uCRM `invoices`** (`status 3=Paid/4=Void`,
  `amount_due = total − amountPaid`), **not Starlink** (`public.php:12108-12160`).
- **Credit notes:** no explicit object — refunds surface only as `api_is_refunded`/`invoice_gone`.
- **Recognized revenue:** **no recognition/accrual engine.** "Revenue" is point-in-time display:
  expected = Σ uCRM Starlink service `totalPrice`; collected = uCRM `amountPaid`; per-KIT revenue from
  operator sales invoices. No accrual/deferral/period logic.
- **Reconciliation:** `calculateLeakage()` (`public.php:11793-11981`) compares four sources (manual
  status, uCRM status, Starlink-invoice cost, sales-invoice revenue) and flags leakage; plus
  `billing_recon`/`financial_recon` tabs.

## E. Calculations (the formulas that must not silently change)
- **Profit/margin (`BillingCalculator.php:50-53`):** `vat = round(costExVAT·vatRate,2);
  profit = revenue − (costExVAT+vat)` → **VAT is applied to the Starlink cost.**
  **Two profit conventions coexist** — ex-VAT at `public.php:3143` vs VAT-inclusive at `:11095`
  (flag, `40`).
- Proration `round((monthlyCost/30)·days,2)`; billing-gap wrap `+31`; cash-flow "alignment score";
  suggested customer day = `starlinkDay+10`.
- **VAT default 0.20; currency default USD**, with symbols incl. **SSP/KES/UGX** (East-Africa / South
  Sudan). Invoice PDF parsing is **hardcoded USD** (`PDFTextExtractor.php:102-106`). Rounding
  `round(…,2)` throughout.

## F. Dependencies that stay authoritative
- **uCRM REST (read-only to Finance):** authoritative for the **customer record** (`clients`), the
  **service + its price & status** (`clients/services`), and **invoices/payments/balances**
  (`invoices`). Finance never writes these. **uCRM is the system of record for "what the customer is
  billed and whether they paid."**
- **`dishnet-data-report` (read-only):** the only Starlink-origin feed (`sl_invoice_lines`,
  `dr_orders`, `sl_svc_cache`, `dr_kit_registry`, `sl_usage`). data-report holds the Starlink
  credentials and makes the calls; Finance consumes its cache.
- **Google Drive** (optional backups); **SMTP** (alerts). Neither is a financial source.

## G. WiFi manager — a request queue, not a device controller
`wifi_manager_api.php` + `templates/wifi_manager.php` are a **WiFi-password-change request queue**:
requests stored in `wifi_requests.json`, an external "gateway phone" polls them FIFO and applies the
change out-of-band, reporting back via heartbeat. **It does NOT call any device control plane and does
NOT proxy to data-report** (no sibling path, no internal-auth header, no RouterOS/UNMS call). Two
pre-existing concerns (flagged, not reproduced): the new password is stored **plaintext**
(`wifi_manager_api.php:160`, with a "encrypt this!" comment), and the endpoints are **CORS-open**
(`:63`). Both are out of scope for the Starlink-API assessment but recorded in `40`.

## H. Security/privacy observations (read-only; for `40`, not acted on)
1. **`data/crm_invoice_export.json` (168 KB) ships with real customer invoice PII** (names, client
   ids, addresses incl. South-Sudan locations, totals). Not reproduced anywhere; flagged as bundled
   live data in a distributed package.
2. `wifi_requests.json` stores WiFi passwords in **plaintext**.
3. The WiFi API is **CORS-open** with no auth of its own.
4. KIT↔service correlation partly relies on **regex-scraping `KIT…` out of free-text uCRM service
   notes** (`public.php:1655`) — brittle.

## I. The preservation set for finance (carried to `21`/`50`)
1. The **flat-file financial records** (`sl_*`, `dishnet_sales_invoices`) with **append + dedup by
   `invoice_number`** and backup-before-bulk-mutation.
2. The **uCRM authority** for customer price, payment, balance, and paid status — never driven from
   Starlink.
3. **No revenue recognition from API data**; revenue stays display-only from uCRM + operator invoices.
4. The **`sl_invoice_lines.json` / `dr_kit_registry.json` read-only contracts** with data-report.
5. `calculateLeakage` and the recon tabs continuing to compare — not conflate — cost vs billing.

*Baseline only. The official-API mapping and Yes/Partial/No/Unknown verdicts — which for finance are
mostly "N/A: uCRM/operator-authoritative" or "indirect, via data-report's feed" — are in `21`.*
