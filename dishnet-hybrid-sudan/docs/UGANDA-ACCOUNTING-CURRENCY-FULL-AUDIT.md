# Uganda accounting, currency & cashbook — FULL audit and design

Status: **AUDIT + DESIGN ONLY — no code changed, no data touched, no migrations run.**
Date: 2026-09-06 · Install: crm.dishnetuganda.com (Uganda) · Codebase: `dishnet-hybrid-sudan/`
Supersedes and extends `docs/UGANDA-FINANCE-AUDIT.md` (the 2026-09-06 phased audit) — that
document's Phase A/B recommendations have since been implemented and are treated here as
*current state*, re-audited rather than re-proposed.

> **Read this first.** The single most important verdict: the **capture layer is now safe**
> (payments, funding, transfers, openings all record the right currency, type and account),
> but the **read layer — balances, summaries, P&L, scheduled reports — is still the Sudan
> engine** and will show wrong or empty numbers for Uganda until Phase C below. Nothing
> currently blocks entering real Uganda transactions; plenty still blocks *trusting the
> plugin's totals*.

---

## 1. Executive summary

The plugin carries **three generations of money code** in one codebase:

1. **The Sudan engine (2024–2026)** — a USD-base cashbook with SSP legs (`ssp_amount`,
   `ssp_rate`, EXCH batch machinery), SSP registers/imprest/staff-cashbooks, wallets and
   USD/SSP reports. Internally consistent *for Sudan*; hard-wired to that worldview.
2. **The display layer (Aug 2026)** — every customer-facing price flows through
   `dn_cur()`/`dn_code()` (config-driven, UGX on this install). Complete and guard-tested.
3. **The Uganda ledger layer (Sep 2026)** — config-driven book currency
   (`dn_book_base`/`dn_book_currencies`/`dn_payment_currency`), `cb_accounts` (one currency
   per account), typed transactions (`txn_type`), opening balances, funding pairs, FX-explicit
   transfers, per-currency running balances in the cashbook tab. Implemented, 899 tests green.

**What is genuinely sound today:** original-currency capture on the *first ring* of write
paths (uCRM webhook + the three payment syncs read the payment's own `currencyCode`; the
new accounts layer records openings, conversions and funding typed, account-linked, with
both FX sides + rate + source; the cashbook tab renders per-currency truthfully).

**What the full sweep found (58 CRITICAL sites, 71 POTENTIAL, out of ~2,229 currency-literal
lines):** the conversion never reached the *second ring*. The POST handlers behind the
config-driven forms coerce UGX back to USD (`['USD','SSP']` whitelists); **eight uCRM
payloads — including the main staff-collection flow — still create payments inside uCRM as
`currencyCode: 'USD'`**; an unattended gap-fill cron stamps USD every 5 minutes; the live
CSV export is a hard-coded shadow copy that the fixed exporter never reaches; and almost
every *aggregate reader* other than the cashbook tab — `getBalance()`, `getBothBalances()`,
`getBalanceByCurrency()` (ignores its own currency argument), `getSummary()`, `getLedger()`,
the CEO dashboard's raw sums, the daily/evening WhatsApp digests — still speaks USD/SSP,
mixes currencies, or shows zeros on a UGX book. No report reads `txn_type`, so director
funding would today count as **income, twice**. Plus audit-trail gaps (hard deletes,
uncontrolled edits, no modified-by, a refund path that calls a nonexistent method) in
§19–§22. Two findings correct this project's own earlier work — flagged as such in C-0 and
C-10.

**Recommended path:** a small surgical **Phase C0 ("stop the bleeding") before any real
transaction is entered**, then phases C–H (§36), all following the proven pattern —
additive schema, config-gated behaviour, Sudan-defaults untouched, zero data migration.

The 18 direct answers the operator asked for are in §37. **This document proposes; it does not
implement. Nothing proceeds without explicit approval.**

---

## 2. Current architecture (system level)

- **Deployment model: shared code, separate installs.** This server is Uganda-only; Sudan runs
  the same repo on its own server with its own SQLite database and config. There is no country
  switch at runtime and no mixed data. All country behaviour = per-install configuration.
- **Money lives in the plugin's SQLite** (`plugin.sqlite3` via `SqliteStore`, JSON-collection
  emulation for legacy stores) — while **billing truth lives in uCRM** (invoices, payments,
  service plans, all UGX on this install). The plugin's ledger *mirrors* customer payments and
  *originates* everything uCRM doesn't do: cash expenses, transfers, funding, openings.
- **Config layers (settled this window):** uCRM settings form → `data/config.json`; operator
  overrides → `kyc_config.json` (file + mirrored SqliteStore copy); `ConfigVault` survives
  re-installs; `PluginConfig::load()` merges all, now permission-hardened (a root-owned file
  can no longer leak PHP warnings into JSON responses or cause a save to wipe overrides).
- **Live Uganda config:** `cashbook_base_currency=UGX`, `cashbook_currencies=UGX,USD`,
  `books_start_date=2026-09-06`, EFRIS in `test` environment (production refuses by design).
- Sudan-only subsystems in the same tree (retailer wallet stack, LTE stack, SSP screens,
  staff cashbooks) remain; navigation gates the SSP screens behind SSP being a selectable
  currency, so they are invisible on Uganda — but several are still *routable by URL* and
  several *cron jobs still schedule Sudan reports* (§15, §20).

## 3. Current database architecture (monetary)

Authoritative table: **`cb_ledger`** (created/migrated additively in
`lib/CashbookService.php::initTable()`, `lib/CashbookService.php:554`). Columns and meaning:

| Column | Meaning | Audit notes |
|---|---|---|
| `sr` | Human serial (CB-/CB4G-/CBBC-, SSP- series) | per-project counters in meta |
| `date` | Transaction date (operator-editable) | backdating supported by design |
| `direction` | `in` / `out` | |
| `amount` | The amount **in the row's own currency** | for Sudan SSP rows this is the USD leg |
| `currency` | Row currency | **SQL default `'USD'`** (`:573`) — a Sudan literal at schema level |
| `ssp_amount`, `ssp_rate` | Sudan SSP leg + rate | keep; Sudan-specific by design |
| `category`, `category_raw` | Operational category (Receipt, Site Power, …) | mixes nature+flow historically |
| `txn_type` | Accounting nature (`SALE, PAYMENT, EXPENSE, TRANSFER, OPENING_BALANCE, REFUND, ADJUSTMENT, DIRECTOR_FUNDING`) | new; `''` on all legacy + several write paths (§20) |
| `account_id` | Home account (`cb_accounts`) | new; `0` = unassigned (all legacy rows) |
| `fx_currency/fx_amount/fx_rate/fx_rate_source` | Original-side record on conversion receiving legs | new; populated by transfers |
| `person`, `cash_with(+id)` | Who handled/holds the cash | person-, not account-centric |
| `validation_ref`, `validation_status` | Voucher/receipt refs; pending-settlement workflow | UNIQUE index deliberately dropped (`:619`) — idempotency is application-level |
| `status` | `approved / pending_approval / rejected / voided / voided_reconcile` | voided rows stay visible, excluded from balances |
| `source` | `manual, crm_webhook, crm_sync, crm_api_sync, collect_payment, opening_balance, funding, account_transfer, catchup_sync, crm_webhook_reversal, field_exchange, expense_sync…` | the honest provenance axis |
| `crm_payment_id`, `crm_client_id` | uCRM linkage | duplicate guard for imports |
| `created_at`, `updated_at`, `approved_by`, `reject_reason` | Partial audit trail | **no `modified_by`, no change log** (§22) |

**`cb_accounts`** (`lib/CashbookService.php:128`): `name` (unique, case-insensitive),
`currency` (**exactly one** — 3-letter validated), `kind`
(`bank/cash/momo/receivable/payable/inventory/asset/director`), `active`, timestamps.
No opening-balance column — deliberately: an opening balance is a *ledger row*
(`txn_type OPENING_BALANCE`), so there is exactly one source of balance truth.

Other monetary stores (wallets, passbook, staff_ledger, cash_ins/cash_expenses/handovers,
payment_collections, LTE/fiber tables, payroll, EFRIS `efris_transactions`) are inventoried in
§18a with per-field verdicts.

## 4. Current accounting model

What the ledger *is* today: a **single-entry cash journal with typed extensions** — not
double-entry bookkeeping. Each row is one money movement; multi-leg economic events are
represented as **row pairs sharing one reference** (the Sudan EXCH pattern, now reused by
`FX-xxxx` transfers and `FUND-xxxx` funding pairs). Assessment:

- This is an honest and auditable model for a cash-based operation of this size, **provided**
  the pair discipline and type discipline hold (they now do at write time for the new flows).
- It is **not** a general ledger: no chart of accounts beyond money-holding accounts, no
  journal entries, no equity/retained earnings, no automatic trial balance. A statutory
  balance sheet remains the accountant's job, fed by this system's exports.
- Recommendation (§26): stay with typed single-entry + pairs. Moving to full double-entry
  would rebuild the billing/CRM boundary the operator has forbidden rebuilding, for benefits
  the accountant can achieve from clean exports.

## 5. Current currency model

Three coexisting layers, each with a different correctness state:

| Layer | Mechanism | State |
|---|---|---|
| Display (what people see) | `dn_cur()/dn_code()`, config symbol/code | ✅ largely done and guard-tested — with real stragglers the sweep caught: the WhatsApp quote body and proforma builder print `USD`/`$`, the invoice-PDF caption prints `$`, the AI prompts say "Cash (USD or SSP)", and the T&Cs state USD fee amounts (§21). Also the display keys are not declared in manifest.json (C-15b) |
| Row data (what a movement IS) | `cb_ledger.currency` + `dn_book_*` config | ⚠️ correct on the first-ring writes; the second ring coerces or stamps USD (C-0, C-1, C-9, C-11), and `addEntry()`'s default + the schema default are still `'USD'` (C-2) |
| Aggregation (what reports SAY) | `getBalance/getSummary/...` | ❌ still Sudan-hardwired or currency-blind (C-3, §18c) |

Original currency is never converted at write time anywhere: conversions store both legs +
rate + source; SSP legs keep the Sudan dual-column model. There is **no reporting-conversion
layer** yet (no rates table, no UGX-equivalent rendering) — that is a designed gap (§29).

## 6. Current cashbook model

- One physical journal (`cb_ledger`) filtered into views. The **cashbook tab**
  (`tabs/accounts/cashbook.php` + `CashbookService::getEntries()`) is currency-correct as of
  commit `dea8bfc`: per-currency running-balance streams (base stream carries legacy
  currency-less rows), every row labelled with its own currency, config-driven filters —
  **except its hero balance card**, which still renders the USD-stream total under a
  "<base> BALANCE" label (C-3), and **except the CSV download**, whose fixed implementation
  is shadowed by an earlier interceptor in `includes/routes.php` (C-10 — a finding against
  this project's own latest commit, stated plainly).
- **Every other balance view** still runs the Sudan lens (§19): USD-literal stream filters,
  SSP special cases, cross-currency sums.
- Account-level cashbooks (per `account_id`) exist in the data model but have **no ledger UI
  yet** beyond balances on the Opening Balances screen — Phase C builds the per-account view
  the operator described in §22 of the brief.

## 7. Current account model

Implemented this month (Phase B): `cb_accounts` + `accountBalance()` +
`seedStandardAccounts()` (the six operator-approved accounts:
Ecobank Uganda – UGX / – USD [bank], Cash – Uganda [cash], MTN Mobile Money, Airtel Money
[momo, UGX], Director/Shareholder Funding [director, UGX]) + add-account form (any currency
from the configured list, any kind). Hard rules enforced in code and tests:
one currency per account; duplicate names refused; unknown kinds refused; inactive accounts
refuse postings; opening balance once per account, correctable only while it is the sole row.

Gaps: CRM-imported payments do not yet land in any account (`account_id=0`) — §10, Phase D;
no per-account ledger drill-down UI; no account archival rules once history exists (design
in §26).

## 8. uCRM → plugin payment flow (current, verified)

| Path | Trigger | Currency source | Duplicate guard | Stamps |
|---|---|---|---|---|
| `webhook.php` `payment.add` | uCRM webhook | `dn_payment_currency()` → payment `currencyCode`, fallback configured base | `crm_payment_id` lookup + PaymentUuids | source `crm_webhook`; **no `txn_type`, no `account_id`** |
| `cron_sync.php` | scheduled | same helper | `crm_payment_id` / PAY-ref | source `crm_sync`; same gaps |
| `cron/payment_catchup_sync.php` | scheduled catch-up | same helper | same | source `catchup_sync`; same gaps |
| PWA collect-payment | staff action | posts explicit currency | collection + PAY refs | source `collect_payment` |
| `webhook.php` payment **delete** | uCRM webhook | copies the original row's currency (`?? 'USD'` only for legacy NULL) | REV-CRM-PAY-ref | contra `out` row, category `Refund`, original row annotated `[REVERSED — CRM deleted]` — original preserved ✅; **no `txn_type REFUND`, no account**; only the first matching row reversed if duplicates ever existed |

Regression-tested: a UGX uCRM payment books as UGX, a USD one as USD even on a UGX book, and a
literal-stamp scanner keeps `'currency' => 'USD'` out of all four write files
(`tests/test_cashbook_currency.php`). Residual risks: the `addEntry()` *default* path (§20
C-2) and the missing account/type stamps (Phase D).

## 9. Revenue flow (current)

uCRM invoices (UGX native) → payment → plugin mirror row (category `Receipt`, direction `in`).
"Revenue" in plugin reports today = **sum of `in` rows by category** — with three failure
modes on Uganda: (a) the summary readers filter `currency='USD'` so UGX revenue vanishes;
(b) nothing excludes non-revenue `in` rows (funding legs, transfer receiving legs, openings)
except their categories happening to differ; (c) nothing distinguishes settlement of an old
receivable from current-period revenue (acceptable for a cash-basis view; must be labelled as
such). EFRIS Phase 1 reads invoice `currencyCode` end-to-end and refuses proformas — the
fiscal layer has no currency assumptions of its own.

## 10. Expense flow (current)

Entry points: cashbook wizard (admin), `tabs/support/field_expenses.php` (staff; currency
radios now config-driven) → `expense_approvals` (approve → `out` row), plus Sudan staff/imprest
flows. Gaps for Uganda (unchanged from the first audit, scoped as Phase E):
no supplier field, no VAT/input-tax amount, no receipt attachment, category assigned at
approval rather than entry, no account link, and `txn_type` not stamped (untyped `out` rows).
A USD supplier expense is *representable* today (currency USD on the row) but not *routable*
(no USD account linkage, no UGX-equivalent note).

## 11. Funding flow (current)

`recordFunding()` (`lib/CashbookService.php:325`): receiving account + **same-currency
liability account**, both legs `DIRECTOR_FUNDING`, category `Loan Received`, shared
`FUND-%04d` ref, currency mismatch refused, UI on the Opening Balances screen. This is the
loan-liability treatment — the company owes the director; nothing touches revenue. ✅
Data-layer correct. Remaining: repayment flow (transfer → liability reduction) not yet a
guided UI (§28); legacy summary readers would still count the receiving leg as `in` money
(§19 — Phase C exclusions).

## 12. Transfer flow (current)

`recordAccountTransfer()` (`:265`): two legs, one `FX-%04d` ref, `TRANSFER` type both sides.
Same-currency transfers must balance to ±0.005; cross-currency requires **both amounts
operator-entered** (never a computed conversion) + mandatory rate source; effective rate is
derived and stored with the receiving leg (`fx_*`). Same-account refused. ✅ The §9
requirement (bank→cash not P&L) holds at the data layer; report exclusion is Phase C.
Sudan's legacy EXCH machinery (batches, FIFO deduction, rate history) remains untouched and
SSP-gated.

## 13. FX flow (current + gap)

What exists: per-conversion truth (both sides + rate + source). What does not exist yet:
(a) a **reporting rates table** for period conversions (management wants "total expenses in
UGX terms" across UGX+USD lines); (b) **realized FX gain/loss** when a USD liability is
settled at a different rate than it arose; (c) unrealized revaluation. Design in §29:
rates table + render-time conversion with the rate printed; realized-FX deferred to the
accountant phase with the data already sufficient to compute it; unrealized explicitly out of
scope until the accountant asks.

## 14. Opening balance flow (current)

`recordOpeningBalance()` (`:213`): typed `OPENING_BALANCE`, source `opening_balance`,
category `Opening Balance`, **currency taken from the account**, `as_of` defaulting to
`books_start_date` (2026-09-06), one per account, correction allowed only while it is the
account's sole row, afterwards the error message directs to an ADJUSTMENT. Balances include
openings. ✅ Mechanism complete; **no opening has been entered yet** (operator task, after
approval of this audit). Trap retired in name only: the legacy `setOpeningBalance()` stub
(`:1803`) still exists and returns `ok` while doing nothing — §20 C-6.

## 15. Reporting flow (current)

The read layer is the weak half of the system. Verified findings (details §18c, §19):
per-project balances/summaries filter to the USD stream (the cashbook's own hero card shows
"UGX BALANCE — UGX 0.00" above a full UGX row list); the Summary view's category constants
count openings, loans, transfers and advances as ordinary in/out; the CEO dashboard sums
`cb_ledger.amount` with no currency filter under a "USD Balance" label and converts SSP at
a hard-coded 6000; `cron_maintenance`'s daily WhatsApp figure sums ALL currencies (the one
non-zero daily number — the most convincing wrong number in the system); the evening
`cashbook_summary` cron's "SSP" block is literally the same `getSummary()` call as its USD
block (`:58-59`) relabelled; `staff_ssp_report` fires and silently reports nothing;
payroll/staff-position summaries mix currencies; all three crons are scheduled on THIS
install (`cron/master.php:187-192`) with no market gate. The only correct
capital-vs-trading reporting discipline in the codebase is the SSP imprest P&L — Sudan-only
— which §30 generalises. The cashbook tab's row view is the only Uganda-correct aggregate
surface today (its CSV and hero card are not — C-10, C-3).

## 16. Uganda requirements (restated as acceptance criteria)

From the operator's brief, the system must at any moment answer, **in the account's own
currency, never combined**: UGX cash · USD cash · UGX bank · USD bank · MoMo balances —
and separately show UGX revenue, USD funding, UGX/USD expenses, conversions, transfers,
openings, each typed so none can masquerade as another. Original currency permanent;
UGX consolidation only via explicit, printed rates. These criteria drive §23–§30 and the
test plan in §35.

## 17. Sudan compatibility requirements

Unchanged and proven pattern: **all new behaviour behind per-install config whose absence
reproduces today's Sudan behaviour byte-for-byte** — demonstrated by dn_cur, dn_book_*,
the wizard gating, navigation gating, the CSV export branch, and the Phase B schema
(additive columns with Sudan-neutral defaults). SSP is never removed; USD is never globally
replaced; Sudan's EXCH/imprest/staff machinery stays. Every phase in §36 carries an explicit
Sudan-regression test obligation. The one Sudan-visible change this audit recommends is a
*bugfix* Sudan also wants: `getBalanceByCurrency()` honouring its argument (its 'USD' call
sites keep identical SQL).

---

## 18a. Database audit — every monetary field (schema sweep)

Method: full DDL + writer sweep (`CREATE TABLE`/`ALTER TABLE` everywhere, plus every JSON
collection writer), each monetary field judged on: (a) meaning · (b) currency determinable?
· (c) account/location link · (d) FX originals preserved · (e) audit trail · (f) can an
auditor reconstruct the transaction from the row alone.

### The core tables

**`cb_ledger`** — §3 covers columns. Sweep additions that matter:

- **The schema has no single authority.** Four competing `CREATE TABLE cb_ledger`
  definitions exist (`lib/CashbookService.php:557`, `migrations/007_ledger_integrity.sql:6`,
  `includes/post/post_field.php:1096`, `lib/KycService.php:1728`) — first writer on a fresh
  DB sets the baseline; the runtime ALTER loop in `CashbookService::initTable()` then
  back-fills. Works, but the effective schema depends on instantiation order, and
  migration 007 carries columns (`trx_no`, `balance`, `staff_id`) nothing uses.
- **On Sudan SSP rows, `amount` holds the USD leg while `currency` says `SSP`** (writers:
  `tabs/sales/my_account.php:369-378`, `tabs/accounts/staff_cashbooks.php:568-577`; readers
  compensate by switching to `ssp_amount`). Any auditor querying
  `SUM(amount) WHERE currency='SSP'` gets dollars labelled SSP. This is the deepest legacy
  semantic in the table — documented here so nobody "fixes" it destructively; Uganda rows
  never use this shape.
- The Sudan FX legs (`ssp_amount`/`ssp_rate`) **never populate the newer
  `fx_currency/fx_amount/fx_rate/fx_rate_source` columns**, carry no rate source/date, and no
  ledger row references `ssp_rate_history` (which itself upserts per-day — a same-day rate
  correction silently overwrites the earlier value, and one runtime DDL variant omits its
  `note` column).
- `person` is free text (no `person_id`); `approved_by` doubles as "created by" for
  auto-posts (`CRM`, `System`, `Auto-KYC`). No `created_by`, `modified_by`, `voided_by`,
  `voided_at` or `void_reason` columns — void metadata is string-appended into
  `description` by three different call sites.
- `sr` generation is read-then-write (racy under concurrency); `validation_ref` UNIQUE was
  deliberately dropped — duplicate refs are possible by design (EXCH pairs) and by accident.

**`cb_accounts`** — best-designed currency handling in the repo (one validated currency per
account), but **no actor trail at all**: `created_by` absent; `setAccountActive()`
(`lib/CashbookService.php:172`) flips accounts silently.

**Openings / transfers / funding at the data level** — the only typed money in the system:

| Concept | txn_type | source | ref | Legs | Guards |
|---|---|---|---|---|---|
| Opening | `OPENING_BALANCE` | `opening_balance` | operator ref | 1 × `in`, positive-forced | one per account; correction only while sole row, else demands ADJUSTMENT |
| Transfer/FX | `TRANSFER` | `account_transfer` | `FX-nnnn` shared | `out` + `in` | same-currency must balance; cross-currency needs both amounts + rate source; fx_* on receiving leg |
| Funding | `DIRECTOR_FUNDING` | `funding` | `FUND-nnnn` shared | 2 × `in` (asset + liability) | liability must be director/payable kind; currency mismatch refused |

Everything else — CRM payments, field collections, KYC cash sales, payroll disbursements,
field exchanges, every expense — writes **`txn_type=''` and `account_id=0`**. `SALE`,
`PAYMENT`, `EXPENSE`, `REFUND`, `ADJUSTMENT` are declared but nothing writes them yet. And
because a funding event is two `in` legs, **any report that sums `direction='in'` counts
director funding twice as cash received** — the report layer must exclude by type (Phase C).

### Satellite money stores (condensed; full verdicts kept in the sweep)

| Store | Currency determinable? | Audit trail | Notes |
|---|---|---|---|
| `staff_ledger` (045) | per-row, but writer `StaffLedgerWriter::onCashIn` hard-codes SSP/USD by *category name*, ignoring the record's own currency | **strong** (voided_by/at/reason, idempotency_key UNIQUE) | best row-level design in repo |
| `staff_expenses` (005/043/047) | per-row | **strongest in repo** (review/approve/void/edit actors+times, receipt hash, offline UUID, flags) | the model Phase E should copy |
| `cash_advances`, `staff_transfers` | per-row | good (transfer even snapshots pre-transfer exposure) | |
| `wallet_events`, `wallet_transactions`, `passbook.json`, `retailers.wallet`, `wallet_recharge_requests.json` | **no currency anywhere in the wallet stack** | events good (idempotency, created_by); balance itself has no as-of/actor | Sudan retailer subsystem; dormant on Uganda |
| `cb_ssp_register`, `staff_cash_declarations`, `hotspot_paid_access`, `bc_retailer_limits`, `wa_ai_log`, `cash_ins.usd_given/ssp_given` | currency hard-coded in **column names** | varies | hotspot rows can carry SSP *and* USD amounts with no rate linking them |
| `payment_collections.json` | **inconsistent — the main field path writes NO currency key** (`includes/post/post_field.php:1012-1035`); only `api_retailer.php:177` writes one | partial; `created_by` = retailer only | primary customer-cash record — see §19 |
| `kyc_applications.json` (fees), `subscription_plans.json` (cost/price/margin) | none | plan price edits overwrite silently, no actor, no history | |
| `quotes_log.json` | hard-coded `const CURRENCY='USD'` (`lib/QuotationService.php:32`) | **log truncated to last 2000 quotes** (`:625`) — history destroyed by design | |
| `hrm_*` payroll (041/042) | lines: 16 money columns share ONE currency column; **periods have no currency at all** | disbursements good (cb cross-links); note `PayrollService.php:483` writes `'CB_ERROR: …'` into the `cb_sr` reference on failure and still marks posted | |
| `efris_transactions` | per-row (nullable) | **excellent** — UNIQUE(invoice,kind), verbatim request/response payloads | strongest reconstructibility in repo |
| fiber tables (038/039/044) | supplier invoices per-row; `fiber_plan_costs`/`fiber_cost_snapshots`/`fiber_services_cache` **no currency** | supplier invoices good; plan costs no actor | three competing DDLs |
| stock (036) | purchases per-row (+ orphan `ssp_rate` with no ssp_amount); `stock_categories.buy/sell_price`, `stock_units.purchase_cost` **no currency**, prices overwritten in place | `stock_movements` is a proper immutable trail | |
| LTE/BlueCard (021-029/053) | `lte_financial_ledger` per-row default USD; renewals/subscriptions/packages/passbooks/load_money/topups **no currency** | `lte_financial_ledger` has **no status/void/actor** | Sudan-only subsystem |
| position/reconcile snapshots (007/008/011/013/033/005) | **none** — 7-to-9 money columns each, currency-blind | derived tables; acceptable IF inputs were clean | `staff_cash_position` VIEW **mixes currencies on inflows and USD-filters outflows** (`migrations/033:47-79`) — wrong on both sides |
| `overdue_workbench`, `overdue_email_log` | no currency on `amount_due` | workbench has a log table | dunning amounts implied by uCRM org currency |

### Ranked: amounts stored with NO determinable currency

Materiality order (top items are live Uganda-relevant): 1. `payment_collections.json.amount`
(main field path) · 2. `passbook.json` + `wallet_events` + `wallet_transactions` +
`retailers.wallet` (whole wallet stack) · 3. `kyc_applications.json` fee fields ·
4. `subscription_plans.json` price/cost/margin · 5. `hrm_payroll_periods` totals ·
6. the seven snapshot/reconcile tables · 7. dunning `amount_due` fields · 8. the LTE/BlueCard
family · 9. fiber plan-cost family · 10. stock price fields · 11. `cb_ledger` legacy rows with
`currency=''` (read-time resolution to the *configured* base means the same historical row
reads UGX on Uganda and USD on Sudan — harmless today because Uganda has no legacy rows, but
worth stating).

### Destructive paths (no history kept)

- `CashbookService::deleteEntry()` — **hard DELETE**, `$admin` accepted and discarded; exposed
  as single AND bulk UI actions (`includes/post/post_cashbook.php:1197,1243`).
- Bulk purge actions delete by `source IN ('excel_import','excel_upload') OR source IS NULL
  OR source=''` and by `sr LIKE` patterns (`includes/post/post_cashbook.php:1308-1312`).
- `updateEntry()` — amount/date/direction rewritable, no before-image, no actor.
- In-place amount "correction" keeps the old value only inside a description string
  (`tabs/accounts/staff_cashbooks.php:326`).
- `recordOpeningBalance()` correction rewrites the row without prior-value capture (allowed
  only while sole row — bounded, but still uncaptured).
- `quotes_log.json` truncation; `subscription_plans.json` / `stock_categories` price
  overwrites; `ssp_rate_history` same-day overwrite.
- `cash_ins.json` ids computed as `count()+1` (`includes/post/post_field.php:130,151`) —
  id collision after any deletion.

---

## 18b. Hard-coded reference inventory (method + counts)

Method: repo-wide sweep of `USD`, `SSP`, `UGX`, `'$'` and `currency*=` literals in runtime
code (PHP, inline JS, JSON seeds, templates, PWA), excluding `tests/`, `docs/`, `*.md`. The
stale `hybrid-plugin/` and `dishnet-ai/` directories are copies not referenced by any build
script, include or manifest (only `dishnet-hybrid-sudan/` is packaged by `build-zip.sh`) —
excluded. Raw signal: **~2,229 matching runtime lines**, resolved into **231 classified
sites**: **58 CRITICAL** (transaction writes, balance maths, silent conversion — itemised in
§19), **71 POTENTIAL** (labels, report headers, defaults, customer messages — itemised in
§21), **102 SAFE** site-groups (the Sudan-by-design subsystems: retailer wallet stack, SSP
screens/imprest, exchange-batch engine, LTE/BlueCard, plus doc comments and the genuine
USD LLM token budget in `WebChatGuard`).

Config keys: `manifest.json` is clean — the three cashbook keys ship without default values
and Sudan-vs-Uganda guidance lives only in descriptions. But the **display** keys
(`currency_symbol`, `currency_code`) are *not declared in the manifest at all* — consumed by
`lib/currency.php:22,30` with a UGX fallback — which is the Sudan-pull risk in C-15(b).

The pattern the sweep confirms: the config-driven conversion landed on the *first ring*
(webhook + payment syncs + ledger service write primitive + cashbook tab + entry-form
markup) and never reached the *second ring* — POST handlers, sibling write paths, uCRM
payloads, aggregate readers, exporters, crons, dashboards. §19 is, in essence, the map of
that second ring.

## 18c. Report-by-report inventory

**Safe on Uganda today** (single-currency by construction, `dn_cur` display): the
JSON-collection reports — `daily_report`, `accounts_collections`, `accounts_commissions`,
`accounts_recharges`, `accounts_wallets`, `accounts_settlement` (collections half),
`accounts_ledger`, `recharge_requests` (one `$` label aside), `commission_cleanup`,
`staff_transfers`, `wallet_admin`, `ops_daily_report`/`ops_settlement`, `invoice_queue`,
`overdue_email_log`, dunning emails, `NotificationService` WhatsApp money,
`PluginQuotePdf` (the one place that *branches* on `dn_code()!=='USD'` — correct),
`DailyReportService` HTML view. `cron/bidal_summary` carries no money at all.

**Wrong on Uganda today** (beyond the C-items): the cashbook hero card ("<base> BALANCE"
label over the USD-stream number — `cashbook.php:129→1049`); the Summary view + category
chips (USD-filtered `getSummary`, and its category constants count `Opening Balance`,
`Loan Received`, `Bank Transfer`, `Exchange`, `Staff Advance` as ordinary in/out);
`accounts_dashboard` (USD-stream totals, fixed `USD Total`/`SSP Total` tiles,
`ExpenseGateway` category buckets summing across currencies, pending-handover sums with no
currency filter); `handover_queue` hero cards (raw SSP figures + `$` amounts summed as one
scalar — the single worst cross-currency sum in the UI); `collection_reconcile` (ignores
`currencyCode` on the CRM side AND sums all currencies on the ledger side — its variance is
noise); `accounts_settlement` cash-in-hand (three currency-blind sums subtracted);
`ceo_dashboard` (raw currency-blind `cb_ledger` sums under a "USD Balance" label, `/6000`
hard-rate conversion, USD-pinned staff exposure); `ledger_health` (pinned to
`allPositions('USD')` → empty table, reports "healthy" having compared nothing);
`cron_maintenance` "Cashbook Daily Summary" WhatsApp (`:1078-1117` — sums **all**
currencies; on Uganda it is the only non-zero daily figure, making it the most convincing
wrong number in the system); `cron/cashbook_summary` (evening WhatsApp — and its "SSP"
block is the *same* `getSummary()` call as the USD block, `:58-59`, so it has always printed
base figures suffixed "SSP"); `cron/cashbook_reconcile` drift check (currency-blind, `$`
labels); `staff_ssp_report` (fires on Uganda, computes zeros, silently reports nothing);
`fiber_costs` P&L (`fc_fmt()` hard-`$` at ~25 call sites, USD/SSP-only invoice entry, and
`FiberPurchaseService` summing `total_amount` with no `GROUP BY currency`); `ops_hub`
banner revenue (adds LTE-stack money + uCRM revenue + local collections across systems);
`wallet.php` CSV (stamps every collection `USD`, every cash-in `SSP`, mislabelling SSP
handovers as USD — wrong on both installs); `my_account` SSP-book CSV (ungated, empty on
Uganda); staff-cashbook CSV + collections CSV in `routes.php` (`'Received (USD)'`, `'cur'
=> 'USD'`); `cron_invoice_notify.php:197` sending customers a PDF caption
`"Invoice #… — $total"`; `api_cashbook`'s ledger/summary endpoints passing a currency as
the project argument (always empty).

**Sudan screens on a Uganda install:** nav-gated (SSP Overview, SSP Cashbook — links only;
tabs still routable), never-gated (`ssp_imprest` — even advertised in the command palette),
ungated-by-design-flaw (staff_cashbooks, cash_declaration [all roles], cash_advances,
handover_queue, collect_payment, fiber_costs, hrm_*, my_account, wallet). The lint
exemption list in `test_currency_sweep.php` names four files that have **no matching
runtime gate**.

**Missing entirely:** a base-currency P&L that excludes capital flows (the only correct
P&L discipline in the codebase is `SspImprestReportService` — SSP-only — which explicitly
excludes advances/exchanges/transfers as "balance-sheet only": the exact pattern §30
generalises); a trial balance (all ingredients exist in `cb_accounts` + `txn_type`;
nothing assembles them); a balance sheet (funding writes a liability leg **no screen ever
displays**); a per-account ledger drill-down (`account_id` is written and never read by any
report); a working end-to-end reconciliation (`balance_identity` fatals — §20 H-9 — and the
other three reconcilers are currency-blind or USD-pinned); bank reconciliation
(no statement import, no cleared flag); own-books aged payables/receivables; any FX
gain/loss report (the fx_* trail is captured and never reported).

## 18d. Write-path inventory (who writes money, and how safely)

Currency verdicts: ✅ = payment/config-driven · ✖ = literal or coerced. Acct/Type = stamps
`account_id`/`txn_type`. Full details in the sweep; every row verified by file:line.

| Path (trigger → handler) | Currency | Acct/Type | Dedup | Notes |
|---|---|---|---|---|
| uCRM `payment.add` → `webhook.php:742` | ✅ `dn_payment_currency` | ✖/✖ | 3-way ref check | cash-method only; approved_by 'CRM' |
| uCRM `payment.delete` → `webhook.php:2636` | ✅ copies original (`?? 'USD'` legacy) | ✖/✖ | **none** — re-delivery double-reverses | contra `Refund` row + annotation; dead 2nd case blocks EFRIS flag (H-8) |
| Nightly pull → `syncFromCrmApi:2018` | ✅ payCurrency | ✖/✖ | in-memory ref set | hard-DELETE housekeeping each run |
| Catch-up → `payment_catchup_sync:195` | ✅ | ✖/✖ | **no pre-insert check** — races webhook | pushes with `dn_code` ✅ |
| Gap-fill → `cron_crm_payment_gap:121` | ✖ **`'USD'` literal** | ✖/✖ | 3-way | every 5 min, no cash filter, today's date (C-9) |
| Auto-heal → `cron_sync:367` | ✖ **uCRM payload `'USD'`** | — | — | creates USD payments at source (C-9) |
| PWA collect → `api_retailer:188` / web collect → `post_field:1124` | ✖ payload `'USD'` (:138) / ✖ literal in SQL + `$currency==='USD'` gate (:1093) | ✖/✖ | ref check / ref check | the primary staff flow (C-0/C-1) |
| Admin approve large → `post_sales:66` | ✖ payload `'USD'` | — | idemKey | C-1 |
| Manual wizard → `post_cashbook:870` | ✖ **UGX coerced to USD** (:821) | ✖/✖ | none | C-0 |
| Exchange pairs (3 UIs) + SSP give/return | ✖ USD/SSP literals | ✖/✖ | FIELD-/EXCH- refs | Sudan design; unreachable from Uganda wizard (gated categories) but handlers live |
| Expense approve → `post_field:120` etc. | ✅ expense row currency | ✖/✖ | EXP-/STAFF-/CEXP- refs | SSP branches convert-and-overwrite (C-14) |
| Settings re-push → `settings:225,254` | ✖ both payload & ledger `'USD'` | ✖/✖ | none | C-11 |
| Cash refund/credit note → `api_payments_admin:549` | ✖ `'USD'` | ✖/✖ | CN- ref, no pre-check | C-11 |
| KYC cash sale → `KycService:1743` raw SQL | ✖ `'USD'` in VALUES | ✖/✖ | **none** | C-11 |
| KYC cancel refund → `post_kyc:296` | — | — | — | **calls nonexistent method; writes nothing** (H-1) |
| Payroll disburse → `PayrollService:469` | ✖ line currency `?? 'USD'` | ✖/✖ | HRM-DISB ref | CB_ERROR-in-ref quirk (H-10) |
| Fiber invoice pay → `FiberPurchaseService:688` | ✅ invoice currency (`?? 'USD'`) | ✖/✖ | FIBER-INV ref | via addEntry |
| Reconcile worker fix-rows → `:132` | ✅ expense currency | ✖/✖ | `cashbook_entry_id=0` filter only; MAX(id) linkback races | |
| Openings / transfers / funding → `opening_balances.php` UI | ✅ account currency | **✅/✅** | one-per-account / COUNT+1 refs (racy) | the only fully-typed writers |
| Wallet/commission flows | no currency exists | — | idempotency keys | Sudan stack |

The closing pattern: **every write path except the three account-layer methods produces
account-less, untyped rows**, so `accountBalance()` reflects only openings/transfers/funding
until Phase D stamps the rest.

---

## 19. Critical findings (would corrupt or misstate Uganda money TODAY)

Ranked. "Fix window" = the phase proposed in §36. **None of these are fixed in this audit.**
Two of them correct claims made earlier in this very project — called out honestly below,
because an audit that spares its own author is worthless.

**C-0 · The manual-entry handler coerces UGX to USD.** The cashbook wizard is fully
config-driven and POSTs `currency=UGX` on this install
(`tabs/accounts/cashbook.php:2676,3243`) — and the handler that receives it destroys it:
`includes/post/post_cashbook.php:821-822`
`if (!in_array($currency, ['USD','SSP'], true)) $currency = 'USD';`
**Every manual wizard entry on a UGX install is stamped USD**, whatever the operator picked.
The same `['USD','SSP']` whitelist sits in the `log_expense` handler
(`post_cashbook.php:11-12`), the field collect/expense/cash-in handlers
(`includes/post/post_field.php:701-702, 325-326`; `:264` defaults *SSP*), the handover write
(`tabs/accounts/handover_queue.php:164-165`), the retailer API (`api_retailer.php:52-53`),
and advance issue/return (`lib/ExpenseAdvanceService.php:168-169, 534-535`). This corrects an
earlier in-session claim that the wizard path was safe — the form is; the handlers are not.
It also fully explains the operator's CB-1 row. Related inverse bug: the web
collect-payment ledger write is gated `if ($amount > 0 && $currency === 'USD')`
(`post_field.php:1093`) — on a UGX install that path writes **nothing**. And several entry
screens offer no UGX at all (`tabs/sales/collect_payment.php:583-596` USD/SSP buttons only;
`cash_advances.php:307-308`; `staff_cashbooks.php:1338`; `fiber_costs.php:442`).
**Fix window: C0.**

**C-1 · Eight uCRM payloads still hard-code `currencyCode => 'USD'` — including the main
staff collection path.** The Phase A fix covered the *ledger mirror*; these sites create
payments/quotes **inside uCRM itself**:
`includes/post/post_field.php:938` (PWA staff collection → uCRM payment — the primary Uganda
field flow), `includes/post/post_sales.php:66` (admin-approved large collection),
`includes/api/api_retailer.php:138` (retailer app), `includes/api/api_leads.php:58`,
`includes/api/api_cron_debug.php:374,425,491` (repair/backfill endpoints),
`tabs/admin/settings.php:65,225`. Three sibling paths already do it right
(`dn_code($config)`: `cron/kyc_crm_sync.php:161`, `cron/payment_catchup_sync.php:137`,
`api/index.php:553`) — these eight were missed because they are *API payloads*, not display
strings, so the display sweep never saw them, and the Phase A guard test only scans the four
ledger-write files for `'currency' =>` stamps, not `'currencyCode' =>` in CRM payloads.
**Consequence on Uganda:** a staff-collected UGX payment posts to uCRM as USD — depending on
uCRM's validation this either fails the sync (collection stuck un-synced) or books a
USD payment against a UGX account, which then echoes back through the webhook and books a
*correctly-labelled USD* ledger row for money that was physically UGX. Either outcome is
poison. **Fix window: C0 (first approved change, one-line-per-site + guard-test extension).**

**C-2 · `CashbookService::addEntry()` defaults to literal `'USD'`**
(`lib/CashbookService.php:1289`) and stamps no source/account/type — the generic manual-entry
API (used by `post_cashbook.php` manual adds, exchange pairs, settle returns). A caller
omitting `currency` books USD on a UGX install. Slipped the Phase A scanner because it is a
`?? 'USD'` default, not a `'currency' =>` stamp. Also the schema-level
`currency TEXT DEFAULT 'USD'` (`:573`). **Fix window: C0.**

**C-3 · The aggregate readers are Sudan-hardwired** (full list §15/§20): `getBalance`,
`getBothBalances`, `getBalanceByCurrency` (ignores its currency argument — `:1632-1641`
always runs the USD/legacy filter), `getSummary` (USD-filtered: Uganda summaries read as
zero/USD-only), `getLedger` (USD-literal balance stream), `getPLByMonth` (**no currency
filter at all — sums UGX+USD into one number — and no txn_type exclusion — openings,
transfers, funding all count as P&L**), `getPayrollSummary`, `getStaffCashPosition`,
`getSiteTracker` (currency-blind sums), `getLedgerLegacy`/`getSummaryLegacy` (ignore their
currency parameter). **Fix window: C (new account-centric reader family + argument bugfix).**

**C-4 · No report excludes by transaction type.** Even after C-3, `direction='in'` ≠ revenue:
a funding event is TWO `in` legs (asset + liability) — today's summary logic would count an
injection of USD 10,000 as **20,000 of cash received**; transfer receiving legs and opening
balances also read as income. The type axis exists precisely so reports can exclude it —
nothing does yet. **Fix window: C.**

**C-5 · Scheduled Sudan reports run on this install** (`cron/master.php:187-191`):
`cashbook_summary` (18:00 daily — WhatsApp "P&L" in USD/SSP with `$` symbols and an SSP≈USD
consolidation) and `staff_ssp_report` (18:00). On Uganda these compute USD-stream numbers and
label everything `$`. Whether the message sends depends on `alert_whatsapp`; the computation
is wrong regardless. **Fix window: C (gate by config / re-render config-driven).**

**C-6 · Customer-facing quote messages still print `$`**: `buildProformaMessage()`
(`lib/QuotationService.php:420-440`, `const CURRENCY='USD'` at `:32`) is live via
`cron_quote_wa.php:177,319` and `api/index.php:1767` — a Uganda WhatsApp proforma renders
`💰 TOTAL: $329,000`. (The quote *PDF* path was fixed earlier; this text builder was missed.)
Also `quotes_log.json` stamps every quote `currency: USD`. **Fix window: C0 (customer-facing).**

**C-7 · Hard-delete and silent-edit of financial rows** (§18a destructive list): bulk/single
hard deletes with no tombstone; `updateEntry` rewriting amount/date/direction with no
history; `$admin` parameters accepted and discarded (no actor recorded anywhere on
delete/edit). For a ledger about to carry real Uganda money this is an audit-trail failure,
not just hygiene. **Fix window: F (audit-trail phase) — with an interim rule: operators use
void, not delete, once real entries begin.**

**C-8 · `setOpeningBalance()` stub returns success while doing nothing**
(`lib/CashbookService.php:1803`) — and it is still **wired**: `post_cashbook.php:369-374`
(first-run setup, fixed `usd_opening`/`ssp_opening` fields) and `api_cashbook.php:439`.
**Fix window: C0 (delete the stub or make it throw).**

**C-9 · An unattended gap-fill cron stamps USD every 5 minutes.**
`cron_crm_payment_gap.php:127` writes `'currency' => 'USD'` (its comment claims "identical
format to webhook.php" — webhook.php was converted, this was not), runs every 300s
unconditionally (`cron/master.php:138`), has **no cash-method filter** (back-fills the
bank/cheque payments the webhook deliberately skips) and stamps today's date instead of the
payment's own date. The single highest-volume mis-booking path in the codebase. Its sibling
`cron_sync.php:367` *auto-heal* POSTs unsynced collections into uCRM as
`currencyCode: 'USD'` — creating genuinely-USD payments at the source of truth.
**Fix window: C0.**

**C-10 · The live CSV export is not the one that was fixed.** `includes/routes.php:291-321`
intercepts `cb_export=csv` at `public.php:637` — long before the cashbook tab loads at
`public.php:2465` — with its own exporter: `['USD','SSP']` filter whitelist (a `cb_curr=UGX`
request silently degrades to "all"), `Received USD/Payment USD/USD Balance` headers, and a
Currency column that labels every non-SSP row `USD`. The config-driven CSV block inside
`tabs/accounts/cashbook.php` — including the correction shipped earlier **today** — is
unreachable dead code, and the guard test asserts against the dead copy. This audit corrects
today's own work: the tab-side fix was real but shadowed. (Silver lining: the interceptor
uses `getEntries()`, so its *numbers* — per-currency running balances — are right; the
*labels* and the currency filter are wrong.) **Fix window: C0 (one exporter, config-driven;
delete the other).**

**C-11 · Refund and repair paths hard-code USD.** Customer cash refunds / credit notes debit
staff ledger and cashbook as `'USD'` (`includes/api/api_payments_admin.php:532,554`); the
KYC cash-sale ledger row has `'USD'` inside the raw SQL VALUES (`lib/KycService.php:1746`)
and the KYC uCRM credit at `:940`; admin settings "edit/re-push collection" double-stamps
USD into both uCRM and the ledger (`tabs/admin/settings.php:65,225,254`); debug/repair
endpoints post live USD payments (`api_cron_debug.php:374,425,491`); the payment-deleted
reversal falls back `?? 'USD'` for legacy NULL rows (`webhook.php:2641`). **Fix window: C0.**

**C-12 · The CEO dashboard converts with a hard-coded rate and mislabels it.**
`tabs/admin/ceo_dashboard.php:301` divides the SSP balance by a **literal 6000** and prefixes
the result with the display symbol — on Uganda that renders an SSP figure divided by a stale
rate and labelled `UGX`. Sibling tiles hard-label `USD Balance` (`:297`) and pull field
exposure as `balance($id,'USD')` (`:128,205`). **Fix window: C.**

**C-13 · Sudan-flavoured money screens are nav-hidden, not access-controlled.** The nav gate
(`includes/navigation.php:274`) hides only SSP Overview + SSP Cashbook; the page router maps
every tab unconditionally (`public.php:2465-2480`), so `?tab=ssp_overview`, `ssp_cashbook`
and the never-gated `ssp_imprest` (still advertised in the command menu, `public.php:2048`)
render fully on Uganda. Eleven more ungated USD/SSP screens are reachable from nav
(staff_cashbooks, cash_declaration — open to ALL roles, cash_advances, handover_queue,
collect_payment, fiber_costs, hrm_*, my_account, wallet…). Worst of them: the cashbook tab's
**"Exchange SSP Backfill" button is gated on admin only, not on SSP being in the book**
(`tabs/accounts/cashbook.php:1085-1102` → handler `post_cashbook.php:1067-1140` writing
`'currency' => 'SSP'`) — on a UGX book with any USD "Exchange" row, one click injects SSP
ledger rows. **Fix window: C (capability-gate the screens; hide the backfill unless SSP).**

**C-14 · Silent SSP→USD conversions overwrite source amounts** (Sudan-legacy behaviour that
must never leak into Uganda flows): expense approval converts at a system rate and
**overwrites the submitted amount in place** (`includes/post/post_field.php:41-48, 1233,
1355` — batch path falls back to a hard `5180.0`; `lib/ExpenseAdvanceService.php:836-838`
falls back `5800.0`; `tabs/accounts/staff_cashbooks.php:201` same), and SSP-typed rows store
the **USD** figure in `amount` (§18a). Also `cron/cashbook_reconcile.php:308-321` sums
`amount` across currencies for its drift check. On Uganda these paths are dormant until an
SSP row exists — which C-13's backfill button could create. **Fix window: C/E.**

**C-15 · Two structural mirror risks for Sudan.** (a) `seedStandardAccounts()` hard-codes
the six *Uganda* accounts and is reachable from the Opening Balances screen on **any**
install — a Sudan admin pressing it gets UGX accounts (`lib/CashbookService.php:378-383`).
(b) `currency_symbol`/`currency_code` — the display keys — are **not declared in
manifest.json**, so when Sudan next pulls this codebase, `dn_cur()` defaults to `UGX ` and
Sudan screens would prefix dollar amounts with "UGX" unless the key is hand-injected into
their `kyc_config.json`. Both must be settled before Sudan pulls. **Fix window: C0
(declare the keys; gate the seeder on base currency or an explicit confirmation).**

## 20. High-priority findings (money lost, flows broken — not currency)

**H-1 · KYC-cancellation refunds vanish.** `includes/post/post_kyc.php:296` calls
`$cbSvc->createEntry([...])` — **a method that does not exist**; the resulting `Error` is
swallowed by `catch (\Throwable)` at `:301`. Every KYC-cancellation refund fails to reach
the ledger silently (and would have been `'USD'`-stamped anyway, `:297`).

**H-2 · The cashbook API's add/update/settle endpoints always fail.**
`includes/api/api_cashbook.php:385-387, 396-398, 408-410` check `$result['success']` but
`addEntry()`/`updateEntry()` return `['ok' => …]` — the endpoints 422 on every success.
(Nothing user-visible depends on them today, which is why nobody noticed.)

**H-3 · `void_payment` depends on a webhook that may not come.**
`includes/api/api_payments_admin.php:11` deletes the uCRM payment (`:74`) and credits the
wallet (`:94`) but never touches `cb_ledger` — the mirror row is reversed only if uCRM's
`payment.delete` webhook arrives. If it doesn't, the IN row survives unreversed. The
reversal handler itself has **no dedup** (a re-delivered webhook writes a second
`REV-CRM-PAY-{id}` OUT row) and hard-codes `crm_client_id=0` (`webhook.php:2652`).

**H-4 · `cb_reseed` deletes first, checks later.** `post_cashbook.php:1301-1341` runs three
unconditional DELETEs (including `source IS NULL OR source=''`) **before** discovering that
`seed_cashbook.php` does not exist in the repo — the button destroys rows, then aborts. The
`fix_*.php` scripts referenced by `api_cashbook.php:699-720` are likewise absent.

**H-5 · Ledger mutation is login-gated, not role-gated.** `bulk_delete_entries`,
`delete_entry`, `update_entry`, `settle_disb`, `cb_reseed`
(`post_cashbook.php:1188,1235,1206,1168,1301`) require only `requireLogin()` — any
authenticated staff account can hard-delete or rewrite ledger rows.

**H-6 · Duplicate-protection is application-level and racy everywhere.** The UNIQUE index on
`validation_ref` is actively dropped on every boot (`CashbookService:619-632` — needed for
EXCH pairs); every import guard is SELECT-then-INSERT; `catchup_sync` writes with **no**
pre-insert dedup query at all, racing the webhook on the same `PAY-{id}`; `sr`, `FX-nnnn`
and `FUND-nnnn` sequences are `COUNT(+1)`/read-then-write (concurrent writers can collide);
`cash_ins.json` ids are `count()+1` (collide after any deletion).

**H-7 · The dual-write wallet chain swallows failures.** `WalletService` writes
`passbook.json` then best-effort mirrors to `wallet_events` in a try/catch that discards
errors — the two stores can diverge silently. (Sudan subsystem; dormant on Uganda.)

**H-8 · `webhook.php` declares `case 'payment.delete'` twice** (`:2602` live, `:2763` dead)
— the dead branch carried the EFRIS `NEEDS_ADJUSTMENT` flagging and invoice-cache refresh
for deletions, which therefore never run on payment deletion.

**H-9 · `balance_identity.php` "Vault cash" is structurally zero** — it queries
`json_extract(data,…)` against `cb_ledger`, which has no `data` column
(`tabs/accounts/balance_identity.php:26-29,100-107`).

**H-10 · Payroll cross-link poisons its reference on failure.** `PayrollService.php:483`
writes the literal string `'CB_ERROR: …'` into the `cb_sr` reference column and still marks
the disbursement `cb_posted=1`.

## 21. Medium-priority (labels, messages, defaults that mislead)

- Customer-facing "USD" strings still live in the WhatsApp quote body built by
  `webhook.php:2200-2211`, the PDF caption `:2326`, the receipt caption
  `cron_quote_wa.php:650`, the proforma text builder (`QuotationService::buildProformaMessage`
  `:420-440` + `const CURRENCY='USD'` `:32`, live via `cron_quote_wa.php:177,319`,
  `api/index.php:1767`), `lib/DeliveryPdfService.php:143,147`, and the customer T&Cs
  (`lib/LegalContent.php:57,84` — "USD 25 reconnection", "USD 150 transfer fee": these are
  *policy statements*, so they need operator-confirmed UGX policy values, not a blind
  reformat — TBC discipline applies).
- The AI WhatsApp system prompts state "Cash (USD or SSP) at office" + a South-Sudan phone
  number (`lib/GptWaClient.php:389`, `lib/ClaudeWaClient.php:510`) — the bot can tell Uganda
  customers to pay in USD/SSP.
- The daily report service buckets by `?? 'USD'` and prints a `NET (SSP)` row
  (`lib/DailyReportService.php:126-164`); staff-cashbook CSV hard-codes `'cur'=>'USD'`
  (`includes/routes.php:397`) and the collections CSV defaults `?? 'USD'` (`:365`).
- Portal money helper ignores its currency argument: `tabs/customer_app/portal_data.php:1026`
  renders every amount with the display prefix even for a genuinely-USD invoice; siblings
  default `currencyCode ?? 'USD'` (`api_customer_app.php:1689,1771,1837`; `FcmPush.php:118`).
- `TransactionIntegrityGuard` only fires on `currency === 'USD'` with USD-sized thresholds
  (`:216,245,258`) — large-transaction warnings never trip for UGX amounts.
- Snapshot/reconcile layer is USD-filtered (`lib/SnapshotService.php:243,270`) or
  currency-blind (`ledger_reconcile_log`, `staff_position_snapshot`, and the
  `staff_cash_position` VIEW that mixes inflow currencies while USD-filtering outflows,
  `migrations/033:47-79`).
- Fixed UI tiles: `accounts_dashboard.php:579,583` (`USD Total`/`SSP Total`), `hrm_*` USD/SSP
  balance columns, `$`-prefixed wallet figures in `tabs/admin/retailers.php`,
  `RechargeService.php:174`, command-menu label "Cashbook (USD & SSP)" (`public.php:2047`,
  `RbacService.php:163`), cashbook flag emoji `🇺🇬` for any non-USD base (`cashbook.php:1048`),
  Uganda-specific helper copy hard-written on the shared Opening Balances screen.
- `pwa/support.html:375` hard-codes `const CUR = 'UGX '` (wrong on Sudan);
  `tools/knowledge_seed.json:116-117` seeds Uganda-specific claims into any install's bot.
- API read defaults pin USD (`api/index.php:2475-2530`, `api_staff_ledger.php:147-201,1271`,
  `ledger_health.php:88`, `DualReadCashPosition` 'USD'/'SSP' calls, `ExpenseGateway.php:124`).
- `api_cashbook.php:352-355` passes a currency string as the **project** argument to
  `getLedger()`/`getSummary()` — a live argument-order bug.
- `PublicPriceFeed.php:56` emits the display *symbol* as the feed's `currency` field.
- Payroll/HRM stamps: every payroll run row `'USD'` (`PayrollService.php:213`), Tally import
  `'USD'` (`HrmService.php:622,732`); quote log rows `'USD'` (`cron_quote_wa.php:376`);
  a *stored* price string `'USD 123'` in `post_sync.php:505`.

## 22. Low-priority / hygiene

- `updated_at` not touched by the in-place amount correction; `approved_by` doubling as
  creator identity; free-text `person`; `'Rupesh'` as `addEntry()`'s default approver
  (`CashbookService:1316`); `getSiteTracker`'s currency-blind 4g sums; `addslashes` string
  interpolation in ledger SQL (inputs are whitelisted, but parameterise anyway);
  `getLastExchangeContext`/EXCH heuristics; four competing `cb_ledger` DDLs and duplicate
  runtime DDL for `ssp_rate_history`/`ssp_batch_states`/fiber tables; migration 007's unused
  `trx_no`/`balance`/`staff_id` columns; `quotes_log.json` 2000-row truncation;
  training/runbook copy describing USD/SSP procedures; test exemption lists
  (`test_currency_sweep.php:36-41`) covering files that carry real findings; the Phase-A
  scanner's regex blind spots (`'currencyCode' =>`, `?? 'USD'`, SQL literals) — the guard
  must grow with Phase C0.
- Dormant traps (dead code that reads live): `QuotePdfService` (no instantiations —
  superseded by `PluginQuotePdf` — but its template hard-codes `${{GRAND_TOTAL}}` and its
  terms text hard-codes USD fees, so reviving it would regress); `getPLByMonth()` has zero
  callers today (its currency-blind, type-blind sums are a loaded gun for whoever wires a
  "P&L" screen to it).

---

## 23. Recommended account model

Keep `cb_accounts` as built — it already satisfies the brief's §4 (one currency per
account, kinds, active flag, Ecobank-UGX ≠ Ecobank-USD by construction). Additions, all
additive:

- `created_by`, `deactivated_by/at`, `note` columns (audit); optional `display_order`.
- **Do not add `opening_balance` or `country` columns.** Opening stays a ledger row (one
  source of truth); country is the install.
- Account archival rule: an account with history can be deactivated (blocks new postings),
  never deleted; renames logged.
- Seeder: gate `seedStandardAccounts()` behind base-currency ≠ USD **or** an explicit
  typed confirmation, so a Sudan admin cannot create Uganda accounts by accident (C-15a).
- Add a **USD Cash** account to the operator's set when USD cash-on-hand becomes real (the
  brief's example set includes it; the seeded six do not — one `addAccount` click, no code).
- Directors: one liability account per director *per currency* (kind `director`), e.g.
  "Director Funding – USD (Bhavin)". The account IS the sub-ledger.

## 24. Recommended multi-currency model

Exactly the brief's §2, stated as invariants the code must enforce:

1. **Row currency is the original currency, forever.** No write path may convert. (Holds
   for all new-layer writes; C-0/C-1/C-9/C-11 violate it upstream — Phase C0.)
2. **Account currency = row currency** on every account-linked write (already enforced in
   the three typed writers; becomes a general write-guard in Phase D).
3. **The base currency is a reporting lens, not a storage rule.** `cashbook_base_currency`
   decides which stream legacy currency-less rows join, which currency the default views
   show first, and the consolidation target — never what gets stored.
4. **Conversions are two-leg transfers** with both sides operator-entered + rate + source
   (built). The `fx_*` columns are the *only* place a converted counterpart lives, always
   next to the original.
5. **Reporting equivalents are computed at render time** from an explicit rates table
   (§27), printed with rate + source + date, and never written back into rows.
6. Sudan's dual-column SSP model is grandfathered as-is behind its currency gate —
   documented (§18a), not migrated.

## 25. Recommended cashbook model

One physical journal, many **virtual cashbooks**, each single-currency:

- **Per-account cashbook** (the §10 report): `Date | Ref | Description | In | Out | Running
  balance`, all in the account's currency — powered by a new `accountLedger(accountId,
  from, to)` reader (openings included; voided visible but excluded from math). This is the
  drill-down that `account_id` was built for and nothing reads yet.
- **Per-currency stream view** for rows without an account (legacy + not-yet-mapped CRM
  rows): exactly what `getEntries()` already does — keep as the "Unassigned" book until
  Phase D empties it.
- **Never a combined balance.** The §22 overview lists every account balance in its own
  currency, grouped by kind; the only cross-currency figure anywhere is the §27
  consolidated report with printed rates. Retire `combined_usd`/`usd_equivalent_ssp` from
  any Uganda-visible surface (Sudan keeps its gated widgets).

## 26. Recommended transaction model

Stay with **typed single-entry + linked pairs** (§4 assessment). The enum as built, plus:

- `CUSTOMER_PAYMENT` for CRM-mirrored receipts (distinct from `SALE`, which stays for
  direct cash sales, e.g. the KYC counter sale). Both are revenue-view rows; the
  distinction preserves "settlement of receivable ≠ new sale" for the accountant.
- **No `FX_CONVERSION` type** — a conversion IS a `TRANSFER` whose legs differ in currency;
  the `fx_*` fields distinguish it. One concept, one type, no enum sprawl.
- **No separate `SHAREHOLDER_FUNDING`** — `DIRECTOR_FUNDING` + the liability account's
  name/kind carries the distinction; converting a loan to equity is an accountant's
  journal, executed as an `ADJUSTMENT` pair with a documented reference, never automated.
- `REFUND` stamped by the payment-delete reversal and the credit-note path; `ADJUSTMENT`
  reserved for reconciliation corrections (§27/§31), always with reason + reference.
- Stamping duty (Phase D): every writer stamps its type; a config-gated strict mode
  (`cashbook_require_account=1`, Uganda-on, Sudan-off) makes account+type mandatory for
  manual entries once the operator has adopted accounts.
- Read-side contract (Phase C): *revenue* = `in` rows typed CUSTOMER_PAYMENT/SALE (legacy
  untyped `in` rows from CRM sources count as CUSTOMER_PAYMENT by source); *expense* =
  `out` rows typed EXPENSE (legacy by category); TRANSFER/OPENING_BALANCE/
  DIRECTOR_FUNDING/REFUND/ADJUSTMENT excluded from P&L by type — and the funding pair's
  liability leg additionally excluded from *cash* balances by its account's kind.

## 27. Recommended FX model

- **Capture (done):** two-leg transfers, both amounts human-entered, derived effective
  rate + free-text source stored on the receiving leg. Add (Phase G) an optional
  `rate_quoted` alongside the derived rate, and a `rate_date` defaulting to the row date.
- **Reporting conversion (new, Phase G):** a small `cb_fx_rates` table — `date, from_ccy,
  to_ccy, rate, source, entered_by, created_at` — operator/accountant-entered (bank or URA
  rate), append-only (a correction is a new row; latest-for-date wins, history kept —
  fixing the same-day-overwrite defect of `ssp_rate_history`). Used ONLY at render time;
  every consolidated line prints `(@ 3,720 UGX/USD, Ecobank 2026-09-06)`.
- **Realized FX gain/loss (designed, deferred):** arises on settlement of a foreign-currency
  liability (director USD loan repaid in UGX) or disposal of foreign cash. The data model
  already captures everything needed (original amounts, rates, linked refs). Recommendation:
  compute and post as explicit `ADJUSTMENT` pairs at period close **with the accountant**,
  via a guided screen that shows its arithmetic — not silently. Build in Phase G, use when
  the accountant signs off the treatment.
- **Unrealized revaluation: explicitly out of scope** until the accountant requires it;
  the doc records where it would attach (period-end snapshot of foreign-currency account
  balances × closing rate) so nothing is painted into a corner.
- **No automatic rate feeds.** Rates enter through humans with a named source — same
  no-silent-conversion law as everywhere else.

## 28. Recommended opening-balance model

As built (§14), plus the balancing treatment the brief asks about:

- An opening balance is a single typed leg on its account, dated `books_start_date`.
  Asset-kind accounts open with `in` rows; liability-kind accounts (director funding
  pre-dating the books) open with `in` rows on the liability account.
- **The counter-entry is presentational, not stored:** the trial balance (§30) shows
  `Opening equity (derived) = Σ asset openings − Σ liability openings` as a computed line.
  If/when the accountant wants formal equity postings, that is one documented `ADJUSTMENT`
  pair — the mechanism exists, the decision stays human.
- Corrections after activity exist: `ADJUSTMENT` pair with reason (the error message
  already points there). Phase F adds prior-value capture to the sole-row correction.
- Openings are excluded from P&L by type and *included* in account balances — already true
  in `accountBalance()`, becomes true in every report via §26's read-side contract.

## 29. Recommended director-funding model

The loan-liability treatment as built is the recommendation, for the reasons the brief
implies: it is conservative (no revenue), reversible (repayment reduces the liability), and
matches the operator's own words ("the company owes Bhavin"). Completions:

- **Repayment flow (Phase D/G):** a guided transfer bank→director-liability that reduces
  both, typed `TRANSFER` with the liability account on the receiving side; cross-currency
  repayment goes through the FX transfer form and surfaces realized FX (§27).
- **A "Payable to directors" view** (Phase C UI): liability-kind account balances, per
  director, per currency — the balance-sheet line no screen currently shows.
- Equity conversion: deliberate accountant act (§26), never a checkbox.
- Report exclusion (§26) fixes the current 2×-income distortion (C-4).

## 30. Recommended reporting model

Generalise the one correct report in the codebase (`SspImprestReportService`'s
capital-vs-trading discipline) to the whole book, account-centrically:

1. **Account balances overview** (§22 layout): kind-grouped, every account in its own currency,
   plus the per-currency totals of the unassigned stream. No combined figure.
2. **Per-account ledger** (§25).
3. **Cash-basis P&L, per currency column**: revenue and expense by category, one column per
   currency actually used (UGX, USD), type-exclusions per §26; an optional consolidated
   UGX column converts the USD column at a printed `cb_fx_rates` rate. Clearly labelled
   "cash basis; consolidation at stated rate".
4. **Trial balance / balance sheet lite**: account balances by kind (assets vs liabilities)
   + derived opening equity + cumulative P&L — enough for the operator and the accountant's
   import; a statutory balance sheet remains the accountant's document.
5. **Reconciliation** (§31): per-account statement/count vs book, in the account's currency,
   difference resolved only by documented `ADJUSTMENT`.
6. **Exports**: every CSV carries an explicit Currency column (row currency), base-labelled
   headers, and — for consolidated exports — the rate columns. One exporter per report
   (C-10 kills the shadow copy).
7. Scheduled summaries (WhatsApp daily/evening) re-rendered from the new readers, currency
   columns separated, capital flows excluded, gated per install config.
8. Sudan reports unchanged behind their gates; the new family is additive.

## 31. Database changes required (all additive, no destructive migration)

| Change | Kind | Phase |
|---|---|---|
| `cb_audit_log` (entry_id, action, actor, at, old_json, new_json, reason) | new table | F |
| `cb_ledger`: `created_by`, `modified_by`, `voided_by`, `voided_at`, `void_reason` | ALTER add | F |
| `cb_accounts`: `created_by`, `deactivated_by/at`, `note`, `display_order` | ALTER add | C/F |
| `cb_fx_rates` (date, from, to, rate, source, entered_by, created_at; append-only) | new table | G |
| `cb_reconciliations` (account_id, as_of, counted_amount, book_amount, diff, note, by, at) | new table | G |
| Payment-method→account mapping (config JSON, EFRIS-map style — no table) | config | D |
| `sr`/`FX-`/`FUND-` sequences moved to an atomic counter (meta table + transaction) | mechanics | D |
| Schema-default `currency` → keep column default but make every writer explicit (guard-tested); do NOT flip the SQL default (Sudan byte-compat) | policy | C0 |
| No changes to existing rows; `account_id=0`/`txn_type=''` legacy tolerated forever by readers | policy | — |

## 32. UI changes required

- Phase C0: collect-payment screen renders currency buttons from `dn_book_currencies`
  (UGX default on Uganda); wizard handler honours the posted currency; kill the SSP
  backfill banner on non-SSP books; one CSV exporter.
- Phase C: accounts overview (§22), per-account ledger, corrected hero/summary/chips wired
  to the new readers; Sudan screens capability-gated at the router (not just nav); command
  palette entries gated; "Payable to directors" view.
- Phase D: account picker on manual entry (strict-mode config), method→account mapping
  screen in admin (mirrors the EFRIS mapping screen pattern).
- Phase E: expense entry with supplier / VAT amount / receipt photo / real date / account.
- Phase F: void-with-reason replacing delete in the row menu (delete remains for same-day
  manual typos, logged); role gates on mutation actions.
- Phase G: FX rates screen (append-only), reconciliation screen per account, consolidated
  P&L view with printed rates; repair `balance_identity` on the new readers.

## 33. Migration risks

- **Code-level only — no data migration is required anywhere in this plan.** Uganda's book
  is effectively greenfield (a single test row, CB-1, already slated for deletion); legacy
  Sudan rows are never rewritten, retyped, or re-currencied.
- The riskiest class of change is the **read-layer cutover** (Phase C): every consumer of
  `getBalance/getSummary/getBothBalances` must move deliberately; the old functions stay
  (Sudan semantics) so a missed consumer shows Sudan-lens numbers, not errors. Mitigation:
  consumer inventory is already complete (§18c), plus per-report fixture tests.
- The **CSV unification** (C-10) changes which code serves Sudan's export too — mitigated
  by making the survivor emit Sudan's exact current layout for USD+SSP books (the tab-side
  implementation already does this byte-for-byte, minus the routes.php Currency-column
  mislabel of SSP handovers, which is itself a bug fix).
- The **coercion-whitelist fixes** (C-0) change behaviour Sudan relies on only in the
  degenerate case (a non-USD/SSP POST on Sudan previously became USD; it will become the
  configured base = USD — identical). Regression-proven by the existing Sudan-default tests.
- **Sudan's next `git pull` is itself a migration event**: before it happens, C-15 must be
  settled (manifest display keys + their values set on Sudan; seeder gate; nav/router
  gates verified against Sudan config). Add a "Sudan pull preflight" checklist to Phase H.
- Rollback strategy per phase: all schema is additive (new tables / new columns with
  neutral defaults); all behaviour is config-gated or reader-routing — rollback = unset
  config / route back. No phase deletes or rewrites data, so rollback never loses records.

## 34. Data-integrity risks (current, to carry into implementation)

1. Duplicate protection is application-level everywhere and racy in three places
   (catchup-vs-webhook, `sr`/ref counters, reconcile-worker linkback) — H-6.
2. Hard deletes + bulk purges + in-place edits exist and are login-gated only — C-7/H-4/H-5.
   Interim operating rule until Phase F: **operators void, never delete; edits only on
   same-day manual rows.**
3. The dual-write wallet chain can diverge silently (H-7) — Sudan-only, dormant here.
4. `payment_collections.json` rows lack currency on the main path — after C0 they gain it;
   historical Sudan rows stay as they are (implied USD by that install's config).
5. Two daily WhatsApp numbers (maintenance summary, evening summary) are wrong in opposite
   ways (one mixes, one zeros) — until Phase C, the operator should treat **only the
   cashbook tab** as truth.
6. EFRIS linkage: `efris_transactions` is sound; the dead second `payment.delete` case
   (H-8) means invoice-cache/EFRIS flags can go stale on deletions — Phase D repairs.

## 35. Test plan (maps the brief's 17 scenarios)

Every scenario becomes an automated test against a temp store with a Uganda config file
(`cashbook_base_currency=UGX`, `cashbook_currencies=UGX,USD`) and a Sudan twin (no config),
extending the existing harness (`tests/test_cashbook_accounts.php` pattern):

| # | Scenario | Assertions (beyond the obvious amount/currency) |
|---|---|---|
| 1-2 | UGX invoice + payment | webhook fixture with `currencyCode: UGX` → row UGX, typed CUSTOMER_PAYMENT (post-D), account per method map; **collect-payment handler test posts `currency=UGX` and asserts it survives** (kills C-0 forever) |
| 3 | Director funds USD 1,000 | two legs, one FUND ref, liability leg on director account, neither in P&L, cash balance +1,000 only (not 2,000) |
| 4 | USD 500 → UGX 1,800,000 | TRANSFER pair, fx_amount 500/fx_rate 3,600/source required, USD account −500, UGX account +1,800,000, P&L zero |
| 5-6 | UGX and USD expenses | row currency preserved; USD expense on USD account; VAT amount field (post-E); P&L shows each in its own column |
| 7-8 | Same-currency transfers | pair balances exactly, P&L zero, both account ledgers move |
| 9-10 | Openings UGX bank / USD bank | typed rows, one per account, in balances, not in P&L |
| 11-12 | Sudan SSP + USD payments | config-absent twin: byte-identical behaviour incl. SSP serials, EXCH pairs, USD default |
| 13 | UGX payment must never become USD | the expanded literal scanner: `'currency' =>`, `'currencyCode' =>`, `?? 'USD'`, and SQL `currency='USD'` across **every** write file (not four); plus live-route CSV test; plus handler coercion tests |
| 14 | Cashbooks never combined | balances API returns per-currency/per-account only; grep-guard: no `combined` figure on Uganda-visible surfaces; report fixtures assert no cross-currency sum |
| 15 | Conversion affects both accounts | covered by 4 + accountLedger assertions both sides |
| 16 | Bank→cash not P&L | P&L reader fixture with a transfer pair → zero effect |
| 17 | Funding not revenue | P&L reader fixture with a funding pair → zero effect; summary view fixture |

Plus regression: the 899 existing tests; new per-report fixtures (each §18c "wrong" report
gets a mixed-currency fixture proving the rewrite); H-1/H-2 become failing-then-fixed
tests; reconcile/summary crons rendered against fixtures; Sudan-default proof doubled
(config absent ⇒ old readers byte-identical).

## 36. Implementation phases (proposed — NOT started)

Sequenced by "what must be true before real money enters", then "before people trust the
numbers", then comfort. Every phase: additive schema only, config-gated behaviour, Sudan
regression suite, rollback = unset config/route back.

**PHASE C0 — Stop the bleeding (before ANY real Uganda transaction).** Small, surgical:
the eight `currencyCode` literals → `dn_code($config)`; the `['USD','SSP']` coercion
whitelists → `dn_book_currencies($config)`; `addEntry()` default → `bookBase()`;
gap-fill cron currency + date + method-filter parity with the webhook; collect-payment
screen currencies from config; one CSV exporter (routes.php delegates or dies); delete the
`setOpeningBalance` stub + its two callers; gate the SSP backfill banner on `$_cbSSP`; gate
`seedStandardAccounts` (C-15a); declare `currency_symbol`/`currency_code` in manifest
(C-15b); AI prompt payment-methods line from config; guard-test expansion per §35-13.
Files: ~18. Risk: low (each site has a proven-correct sibling). Tests: scanner + handler
fixtures. **This phase is the answer to §26-Q18.**

**PHASE C — Read layer & screens people look at.** New reader family
(`accountLedger`, `balancesByAccount`, `currencyPosition`, `plByPeriod` with type
exclusions); fix `getBalanceByCurrency` honouring its argument; route cashbook hero/
summary/chips, accounts_dashboard, ceo_dashboard, ledger_health to the new readers;
capability-gate Sudan tabs at the router; re-render or gate the three cron summaries;
accounts overview + per-account ledger + payables view UIs. Risk: medium (consumer
cutover) — mitigated by §33. Rollback: route back to old readers.

**PHASE D — Write-path completion.** txn_type stamping on all writers;
`cashbook_require_account` strict mode; uCRM payment-method→account mapping screen +
stamping on webhook/syncs; refund typing + reversal dedup + fix dead `payment.delete` case
(H-8); repair H-1 (KYC refund), H-2 (API keys), H-3 (void_payment posts its own reversal
with webhook-dedup); atomic counters (H-6); settle-return re-typing.

**PHASE E — Expenses.** Supplier, VAT/input-tax amount, receipt photo, category-at-entry,
real backdating UX, account picker; modelled on `staff_expenses`' audit fields; the
SSP-convert-and-overwrite paths quarantined behind the SSP gate (C-14).

**PHASE F — Audit trail.** `cb_audit_log` + actor columns; delete→void (reason required);
pair-void (both legs by ref); role gates on mutation (H-5); CRM/typed rows immutable
except through void+reenter; opening-correction prior-value capture.

**PHASE G — FX reporting & reconciliation.** `cb_fx_rates` (append-only) + consolidated
P&L with printed rates; realized-FX guided postings (with accountant); per-account
reconciliation screen + snapshots; rebuild `balance_identity` on the new readers.

**PHASE H — uCRM validation pass + Sudan pull preflight.** End-to-end fixtures against a
staging uCRM (UGX payment → webhook → typed, accounted row; refund cycle; method mapping);
the Sudan checklist from §33; then hand back to the **EFRIS production track** (separate
plan, blocked on URA credentials — unchanged by this audit).

## 37. The operator's 18 questions, answered directly

1. **UGX customer income?** Yes — uCRM is natively UGX here; invoices, plans, the price
   feed and EFRIS all read the live `currencyCode`.
2. **UGX customer payments?** The webhook/sync mirror: **yes** (Phase A, tested). The
   staff-collection flow: **no** — the PWA/web collect paths still create USD payments in
   uCRM and USD/blocked ledger rows (C-0/C-1). Fixed in C0.
3. **USD director funding?** Yes — `recordFunding` + the Opening Balances screen record it
   with a liability leg, typed, account-linked.
4. **Funding kept separate from revenue?** In the data: yes (type + category + accounts).
   In every report that exists today: **no** — nothing reads `txn_type`, so summaries count
   it (twice). Fixed in C.
5. **Separate UGX cashbook?** The cashbook tab: yes (per-currency streams). The balance
   cards/summaries/exports around it: **no** (C-3, C-10). Fixed in C0+C.
6. **Separate USD cashbook?** Same answer as 5.
7. **Separate UGX and USD bank balances?** Yes structurally (`cb_accounts`, one currency
   each, `accountBalance()`); the overview UI that *shows* them is Phase C.
8. **Separate mobile-money balances?** Same as 7 — and CRM payments don't land in accounts
   until the method→account mapping (Phase D).
9. **USD→UGX conversion without artificial income?** The transfer mechanism: yes (typed,
   fx-recorded, P&L-neutral at data level). Reports: excluded properly only after Phase C.
10. **Bank→cash without income/expense?** Same as 9.
11. **Original transaction currency maintained?** Yes for every new-layer write; C0 closes
    the remaining upstream violators; Sudan legacy semantics documented, untouched.
12. **UGX reporting equivalents?** **Not yet** — no rates table, no consolidated renderer.
    Phase G, by design (explicit rates, printed, never stored into rows).
13. **Historical FX preservation?** Per-conversion: yes (both amounts + rate + source).
    Period rates: Phase G's append-only `cb_fx_rates` (also fixes same-day overwrite).
14. **Opening balances proper?** Mechanism: yes (typed, once-per-account, correctable only
    while sole, account-currency). Entry: waiting on the operator, after this approval.
15. **UGX uCRM payment can never become USD?** **Not yet guaranteed.** The mirror path is
    safe; the plugin's own collection pushes are the remaining way a UGX payment becomes
    USD *at the source* (C-1), and the wizard coercion does it locally (C-0). After C0 +
    the §35-13 guard suite: yes, with a test that keeps it true.
16. **Uganda and Sudan safely on one codebase?** Yes — the config-gated pattern is proven
    across five subsystems now. Two open items before Sudan's next pull: C-15 (display
    keys, seeder gate) and the router-level gating (C-13).
17. **What existing data is at risk if we change architecture?** On this install:
    effectively none — one test row (CB-1), already slated for deletion; everything else
    starts after approval. On Sudan: nothing, because no phase rewrites rows; the risks are
    behavioural (§33) and carried by the preflight checklist. The plan deliberately
    requires **zero data migration**.
18. **What must be fixed BEFORE real Uganda transactions?** (a) delete CB-1; (b) **Phase
    C0 in full** — otherwise staff collections mint USD payments and manual entries mint
    USD rows; (c) seed the six accounts + add Director Funding – USD + record the real
    USD 10,000 via the Funding form; (d) enter opening balances as at 2026-09-06. Phase C
    is not a blocker for *entering* transactions — the capture layer is then correct — but
    until it ships, trust only the cashbook tab, not the summary cards, dashboards, CSV
    export or WhatsApp digests.

---

## STOP

This document is the deliverable. **No implementation has been started.** Nothing in
Phases C0–H proceeds without explicit approval; C0 is the recommended first approval, and
its worked file list is ready to go the moment it is given.
