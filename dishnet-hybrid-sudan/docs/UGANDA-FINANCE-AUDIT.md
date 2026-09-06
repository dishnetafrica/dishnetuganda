# Uganda finance & currency audit

Status: **AUDIT ONLY — no code changed.** 2026-09-06.
Scope: the whole plugin + this install's uCRM, per the operator's 21-point brief.

## 0. Executive summary

The system has **two currency layers**, in very different states:

1. **Display/customer layer — DONE.** Every rendered price (website, WhatsApp
   AI, web chat, quotes, invoice notifications, PDFs, portal, PWA) already
   flows through the central formatter `dn_cur()`/`dn_code()`
   (lib/currency.php), which defaults to **UGX** and is guard-tested
   (tests/test_currency_sweep.php). A Uganda customer cannot see SSP or $ in
   any customer-facing surface; the EFRIS mapper reads `currencyCode` from
   the live uCRM invoice, never hard-coded.
2. **Ledger/data layer — STILL SUDAN.** The internal accounting engine
   (cashbook, expenses, wallets, payroll, dashboards) is built around the
   Sudan model: **base currency USD with SSP legs** (`cb_ledger.currency`
   default `'USD'`, `ssp_amount`/`ssp_rate` columns, EXCH pairs, SSP
   registers/imprest/reports). Entry forms offer **only USD/SSP**.

**Headline risk (E — hard-coded incorrectly): every uCRM payment is
auto-posted to the cashbook as `currency => 'USD'`** — in webhook.php
(`payment.add`) and in the nightly `cron_sync.php`. On this Uganda install a
UGX 329,000 MoMo payment would be booked as *USD 329,000*. No poison rows
exist yet only because no payment has been recorded (invoice 000001 is
unpaid). Verify on the server (read-only):

```
docker exec ucrm php -r '$p=new PDO("sqlite:/data/ucrm/data/plugins/.dishnet-hybrid-sudan-data/plugin.sqlite3");foreach($p->query("SELECT currency,direction,COUNT(*) n,SUM(amount) s FROM cb_ledger GROUP BY currency,direction") as $r) printf("%-4s %-3s %5d %14.2f\n",$r["currency"],$r["direction"],$r["n"],$r["s"]);'
```

## 1. How the code determines the operating country today

There is **no `country` switch**. The architecture is *shared codebase,
separate deployments*: this server (crm.dishnetuganda.com) is Uganda-only;
Sudan runs the same git code on its own server with its own database and
config. Country-specific behaviour is per-install configuration:

- `currency_symbol` / `currency_code` (→ `dn_cur`/`dn_code`, default UGX)
- `ai_currency` = ugx (live), `governing_law` (quote terms, default Uganda)
- `crm_base_url`/ucrm.json (host identity), Evolution instances (mapped
  per install; the `dishnet_sudan` instance is unmapped here)

**Verdict on §5 (Sudan compatibility):** nothing on THIS deployment serves
Sudan, but the CODE is shared — the Sudan server pulls the same repo. So the
safe pattern (already proven by the dn_cur work) is: **new behaviour behind
per-install config whose default preserves today's behaviour.** Proposal:
`cashbook_base_currency` (default `USD` = Sudan unchanged; Uganda sets
`UGX`) + `cashbook_currencies` allow-list (Uganda: `UGX,USD`). A cosmetic
`operating_country` label can ride along for report headers. Recommendation:
**do not build a runtime country switch** — two countries in one database is
exactly the mixed-ledger disease this audit exists to prevent.

## 2. Reference inventory (A–D of §20)

Counts are matching lines outside tests/.

| Token | Lines | Classification |
|---|---|---|
| SSP | 3,079 | B (Sudan ops: ssp_cashbook/imprest/overview tabs, staff_ssp_report, cashbook_summary, wallet SSP legs, retailer/LTE) + D (legacy strings) — **zero customer-facing (guard-tested)** |
| USD | 1,614 | B/C: ledger base currency, FX widgets, wallet; E: the two hard-coded payment posts; plus legit internal enum values |
| SDG / SYP | 20 / 6 | D (legacy Sudan strings, comments) |
| UGX | 14 | A: `dn_cur` default, manifest text, AI currency |
| `dn_cur`/`dn_code` | 743/23 | C: the central formatter — the display layer, done |
| exchange/rate | 598 | B: Sudan FX engine (EXCH pairs, ssp_rate_history, scEx widgets) |

Where they live (grouped):
- **Customer-facing (A, done):** website feed, prices.js, chat, quotes
  (`QuotationService`, `PluginQuotePdf` — `dn_cur` + `{{CURRENCY_CODE}}`),
  invoice notifications (`NotificationService->curSym`), portal/PWA, EFRIS
  (`currencyCode` from invoice).
- **Sudan ledger engine (B, keep, gate by config):** `lib/CashbookService.php`
  (cb_ledger USD default, SSP legs, EXCH), `tabs/accounts/*` (incl.
  `ssp_cashbook.php`, `ssp_imprest.php`, `ssp_overview.php`,
  `staff_cashbooks`, wallets), `cron/staff_ssp_report.php`,
  `cron/cashbook_summary.php`, `tabs/support/field_expenses.php` (USD/SSP
  radios), payroll/HRM amounts, retailer/LTE money flows.
- **Hard-coded wrong for Uganda (E, must fix in Phase A):**
  `webhook.php` payment.add → `'currency' => 'USD'`;
  `cron_sync.php` payment sync → `'currency' => 'USD'`.
  Correct value: the uCRM payment's own `currencyCode` (fallback: the new
  `cashbook_base_currency`).
- **User-selectable (F):** expense/advance forms (USD/SSP radios), cashbook
  add-entry — need config-driven options (Uganda: UGX default + USD).
- **uCRM-originated (G):** invoice/payment/quote `currencyCode` — flows
  correctly everywhere it is read; the two E-sites above ignore it.

## 3. uCRM currency configuration (§6)

Verified from the live probe (invoice 000001 + client 1):
- Organization = "DishNet Africa Limited", **invoice `currencyCode: "UGX"`,
  client `currencyCode: "UGX"`** → the uCRM organization currency is UGX. ✔
- Service plans/products were created in UGX (live price feed confirms). ✔
- `organizationTaxId` is empty → set TIN 1059140632 in Settings →
  Organization (cosmetic-but-proper; uCRM's own PDF then shows it).
- Taxes: none configured yet — correct while VAT registration is pending;
  when registered: create "VAT 18%", attach to plans/products, map it in
  the EFRIS tab. **Nothing else in uCRM needs changing for currency.**
- A Uganda invoice cannot "appear in SSP" from uCRM: uCRM has no SSP
  anywhere on this install. The only SSP risk is the plugin's ledger layer.

## 4. Cashbook architecture (F of §20)

`lib/CashbookService.php` v2.0, table `cb_ledger`:
`sr` (CB-prefixed serial per project), `date`, `direction` in/out, `amount`,
`currency` (default 'USD'), `description`, `validation_ref`/`_status`
(voucher/wr/online/…), `status` (approved flow), `project`
(dishnet/4g/bluecard), `category` (+`category_raw`), `person`,
`approved_by`, `crm_payment_id`/`crm_client_id`, `source`
(manual/crm_webhook/crm_sync/crm_api_sync/collect_payment), `ssp_amount`,
`ssp_rate` (Sudan FX legs; EXCH-xxx pairs share a ref), `payroll_ref`,
`cash_with`(+id) — who physically holds the cash.
Since v4.10.4 the cashbook records **cash only**; bank/cheque stays in uCRM.
Related: `staff_ledger`, `staff_transfers`, `cb_ssp_register`,
`ssp_rate_history`, wallets.

**What is missing for §10–§17:**
1. **No account dimension** — no bank/MoMo/cash account entity; `cash_with`
   tracks a person, not an account. (§12/§17 require accounts, each with
   its own currency.)
2. **No real opening-balance mechanism** — `'Opening Balance'` exists only
   as a category chip; `setOpeningBalance()` is a stub returning ok.
3. **No transaction-type axis** — categories mix nature (Receipt, Salary)
   with flows (Exchange, Interco); there is no SALE/PAYMENT/EXPENSE/
   TRANSFER/OPENING_BALANCE/REFUND/ADJUSTMENT/DIRECTOR_FUNDING type field.
4. **No director/shareholder funding treatment** — no liability leg.
5. **Expense entry is thin** (§14): field_expenses captures amount,
   currency (USD/SSP only), purpose; no supplier, no category on entry
   (categorised at approval), no VAT field, no receipt attachment, no
   date-override for historical entries.
6. **No FX-explicit record for non-SSP** — ssp_amount/ssp_rate are
   SSP-specific; a USD expense on a UGX book has nowhere to carry
   original-currency/rate/UGX-equivalent (§4/§18).

## 5. Expense & report functionality today (G/H/I of §20)

- Entry: `tabs/support/field_expenses.php` (staff, mobile) →
  `tabs/accounts/expense_approvals.php` (approve → ledger `out` row);
  direct entry in `tabs/accounts/cashbook.php`; historical import tool
  `migrations/migrate_expenses_data.php` (Sudan data).
- Reports: `cashbook.php` (main book + filters), `daily_report.php`,
  `ops_daily_report.php`, `accounts_dashboard.php`,
  `tabs/admin/ceo_dashboard.php` — all already **display** via `dn_cur`
  (symbol = UGX here), but they summarise `cb_ledger.amount` whose data
  currency is USD/SSP-modelled — correct display, wrong semantics until
  Phase A. Sudan-specific reports (`ssp_*`, `staff_ssp_report`,
  `cashbook_summary`) are B: leave them; hide from Uganda nav later.
- Dashboards do not add USD to UGX today only because Uganda has no ledger
  rows yet.

## 6. Recommended Uganda configuration (J)

Config keys (new, vaulted, defaults preserve Sudan):
- `cashbook_base_currency` = UGX (default USD)
- `cashbook_currencies` = `UGX,USD` (default `USD,SSP`)
- `uganda_books_start_date` = *operator-confirmed date* (see Open questions)
- (cosmetic) `operating_country` = Uganda

Data-model additions (extend, don't replace — same table, new columns +
one small table, following the cashbook's own ALTER-if-missing pattern):
- `cb_accounts` table: id, name (e.g. "Ecobank UGX", "Ecobank USD", "Cash",
  "MTN MoMo", "Airtel Money"), currency, kind (bank/cash/momo/receivable/
  payable/inventory/asset/equity_director), active. **One currency per
  account, never merged.**
- `cb_ledger` new columns: `account_id`, `txn_type` (SALE/PAYMENT/EXPENSE/
  TRANSFER/OPENING_BALANCE/REFUND/ADJUSTMENT/DIRECTOR_FUNDING),
  `fx_currency`, `fx_amount`, `fx_rate`, `fx_rate_source` — explicit
  original-currency record; **no silent conversion ever** (§4).
- Opening balances = ledger rows with `txn_type='OPENING_BALANCE'`,
  `source='opening_balance'`, dated at the books-start date, excluded from
  every revenue/expense report by type (not by category string), entered in
  a small dedicated screen (§12 layout), one per account, editable only
  while no later entries exist on that account.
- Director funding = **two legs, one reference**: the expense row
  (txn_type EXPENSE, account = the director-funded pseudo-account or the
  real account) + a liability row (txn_type DIRECTOR_FUNDING, account =
  "Payable to Director <name>", direction in) — never revenue, never a
  customer payment; the pair mirrors the proven EXCH-pair pattern.
- Historical expenses: normal EXPENSE entries with a real backdated `date`
  (already supported by the ledger), plus the funding-source choice.

## 7. Exact files needing change (K) — Phase plan (§21)

**PHASE A — currency correctness & config** (blocks everything else)
1. `webhook.php` payment.add: currency from uCRM payment `currencyCode`
   (fallback `cashbook_base_currency`) — 1 line + fallback.
2. `cron_sync.php`: same fix.
3. `lib/CashbookService.php`: base currency from config (default USD);
   currency allow-list helper.
4. `tabs/support/field_expenses.php` + `tabs/accounts/cashbook.php` entry
   forms: currency options from `cashbook_currencies` (Uganda: UGX default,
   USD selectable; SSP disappears from Uganda without touching Sudan).
5. `manifest.json` + `lib/ConfigVault.php`: the new keys.
6. Guard test: no ledger write path may carry a literal currency again.

**PHASE B — accounts + opening balances** (after your approval + start date)
`cb_accounts` + `account_id`/`txn_type` columns; opening-balance screen in
`tabs/accounts/` (per §12 with all account kinds); report exclusion by type.

**PHASE C — historical expense entry**: date-override entry (backdated),
supplier/vendor + VAT-amount + reference fields, optional receipt photo
(reuse the existing photo upload used by KYC), category at entry.

**PHASE D — director/shareholder funding**: the two-leg pattern + a
"Payable to directors" view.

**PHASE E — Uganda financial reports**: cashbook/daily/CEO views grouped by
account and currency; USD lines always separate; any consolidated line shows
rate + converted amount explicitly; Sudan-only tabs hidden when base ≠ USD.

**PHASE F — regression**: full suite + Sudan-default proof (all new config
unset ⇒ byte-identical behaviour paths), EFRIS tests, payment-webhook test
with a UGX fixture.

## 8. Risks to Sudan (M)

- Same git repo → every change ships to Sudan's server on their next pull.
  Mitigation (proven twice already: dn_cur, EFRIS): **all new behaviour
  behind config that defaults to today's behaviour.** Sudan sets nothing,
  changes nothing.
- The two E-fixes (payment currency from uCRM) are safe for Sudan too: its
  uCRM payments carry USD `currencyCode`, so reading it is a no-op there.
- No Sudan data exists on this install to damage; Sudan history lives on
  the Sudan server and is not touched.

## 9. Open questions (answers required before Phase B)

1. **Uganda accounting start date** for `uganda_books_start_date` — not
   assumed. (Your example was 2026-09-01; confirm or give another.)
2. **The account list to create**, with per-account currency — e.g.
   Ecobank UGX, Ecobank USD, Cash UGX, MTN MoMo UGX, Airtel Money UGX,
   Payable-to-Director accounts (whose names?).
3. Confirm the Sudan operation runs on its **own server** (this audit's
   working assumption from all evidence) — i.e. no Sudan customer/ledger
   rows will ever live in this database.
4. Who approves Uganda expenses (the approval flow needs a named role).
5. VAT on expenses: record VAT amount from day one (recommended — EFRIS
   input-credit readiness) or add later with the accountant?
