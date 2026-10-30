# 71 — Uganda: the plugin's quotations take their lines and prices from uCRM (5.18.102 design, 27 Oct 2026)

Status: **being re-cut for D14 (§R, the operator's decision of 28 Oct): designed; built and reviewed on the local branch `quote-102-d14` (§R.17); not yet re-cut as the release.** The release as it stood before D14: **design, reviewed (§O); built and committed as `release/5.18.102` = `9e66565` (local, not pushed; re-cut from `00d2be1`, see §P.5); what the
build changed from this design is in §P; the deploy script's own decisions are in §Q.** The release is cut on 5.18.101
(`f2055baf`), which is live according to the operator (D6). Its deployment record is still pending in docs/07, and the
deploy script cannot be handed over until it is recorded (§M). §R cites this file's lines as they stood before §R and the D14 row were added (`f60f1663`): from line 55 on, add one.

## A. Why

Quotation 000314 (27 Oct) was made by the plugin when a KYC registration was submitted. uCRM rendered it with the
four-page template (docs/67 §L), but the plugin filled it with wrong information:

1. **Internal notes on the customer's document.** The NOTES box read "PENDING | Auto-generated on KYC registration.
   Sales: … | Connection: New Connection | Priority: Medium". The plugin writes this into the quote's `notes`, which
   the template prints (`KycService.php` 1125–1127; the retry `KycCrmSync.php` 299–305). "PENDING" is the placeholder
   reference that `saveApplication` stores before uCRM gives a number (1868). It is never replaced on the uCRM quote.
2. **"Prepared by" named the wrong person.** The template prints the client's custom attribute `salesPerson`, else a
   default name. On Uganda the plugin sends no client attribute at all: `UcrmClientTarget::apply` strips them because
   field 1 there is the EFRIS TIN. Uganda's uCRM also has no `salesPerson` attribute (docs/30, 25 Sep).
3. **The lines are not uCRM's.** Every KYC line is priced by the plugin: the plan from `subscription_plans.json`'s
   `customer_price`, the kit from the price text the browser sends. The PDF prints each as "CUSTOM - MONTH". Nothing
   reads uCRM's price. The only "sync" runs one way, plugin → uCRM ("Sync All", saving a plan or kit), and it overwrites
   uCRM's prices. The website, by contrast, reads its prices live from uCRM (`prices.php`), so the two can disagree.
4. **No installation line** on a Starlink quote. The builder adds one for Fiber only.
5. **The cash the agent collects** (the wallet check, the wallet debit, the uCRM payment) is computed apart from the
   quote, from the plugin's prices: the plan plus one device, ignoring the cart, with no installation (`resolveAmount`).

## B. Decisions

**The operator's (27 Oct):**

| # | Question | Decision |
|---|---|---|
| D1 | Turn the auto-quote off, or fix it? | **Keep it and fix it**, in a plugin release |
| D2 | Installation on a Starlink quote | **A separate line only when the form says DishNet installs**, from uCRM's Professional Installation product. The website's "installation included" wording is to be corrected |
| D3 | "Prepared by" | **The operator adds a client custom attribute "Sales Person" in uCRM**; the plugin finds it and fills it |
| D4 | A plan or kit that matches no uCRM product | **No auto-quote; the agent is told** to make the quotation in uCRM |
| D5 | The cash amount on a Cash sale | **Equals the quotation's total.** The form shows uCRM's prices, and a Cash sale is refused when the total cannot be priced from uCRM |
| D6 | The base | **5.18.101 is live**; 5.18.102 is cut on it |

These carry the operator's earlier decision of 20 Oct (docs/07): "uCRM Products are the true price list."

**The designer's, after the review (§O).** Each is the reading that keeps D1–D6 and the standing rules. The operator
may change any of them; each change is one place in the code.

| # | Question | Default |
|---|---|---|
| D7 | The gate | **`QuoteOrganization::setting($config, $dataDir)['on']`**, read through that method (§C) |
| D8 | Installation quantity | **1 per application**, whatever the kit quantity ("a separate line", D2) |
| D9 | The Delivery Acknowledgment PDF on Uganda | **Not sent.** It carries South Sudan's terms and the plugin's prices (§K.2) |
| D10 | A Cash sale that does not post the total it showed (the retailer app, the API, a form opened before the deploy) | **Refused** (§G) |
| D11 | Applications saved before 5.18.102 that are retried | **A Cash one is never re-priced.** A Starlink New Connection without an installation answer is unpriced (§F.4) (amended by §R.4.2, E-1: at its retry every application saved before the deploy is unpriced, P1 or P2, and is quoted in uCRM by hand) |
| D12 | The plugin → uCRM product push (Sync All, the per-row sync, the sync when a plan or kit is saved) | **Refused on Uganda**: it would change the price list the quotes and the cash now come from (§K.3) |
| D13 | `kyc_install_product_id` (an override for the installation product) | **Dropped.** Nothing could write it; the installation product is found by its name alone (amended by §R.2.4: the installation product is linked on the Hardware screen; no name is matched) |
| D14 | How a plan, a kit or the installation is joined to its uCRM product | **Items are linked to uCRM by an admin** (Plans, Hardware, the installation), never matched by spelling — the operator, 28 Oct: *"use name of ucrm sync it with plugin"*, then *"Pick the product per item"* and *"Into 5.18.102"* (§R) |

## C. Scope

**The gate (D7).** Everything 5.18.102 adds runs only where `QuoteOrganization::setting(...)['on']` is true. That is
the Uganda tenant, unless `ucrm_quote_organization_id` is set to 0 or off. Uganda's profile sets organization 1
(`profiles/uganda.json`, `quoting`). So:
- South Sudan's requests, texts, PDFs, pages and store stay byte for byte as 5.18.101 leaves them;
- on Uganda, `ucrm_quote_organization_id` = 0/off keeps meaning "as before", now 5.18.101's behaviour. The journeys
  `ug_off` and `ug_off_word` stay byte-identical to `ce7ac388`, and the 5.18.99 SAFETY rule on `quote_refused*` holds
  (they are written only where the setting is on);
- `UcrmClientTarget` already receives this setting, so the salesPerson lookup (§I) needs no other way to learn the
  tenant.

**In:**
- the five quote-create sites, their lines and their notes (§F);
- the KYC form's prices, its installation question and its posted total (§E, §G);
- the cash amount (§H);
- "Prepared by" (§I);
- Send Quotation's plan prices, lead lines and lead WhatsApp (§J);
- the fallback PDF's terms and header (§K.1), the Delivery PDF (§K.2), the product push (§K.3).

**Out (recorded, not changed):**
- the quote template (no upload is needed for this release);
- the website's "installation included" wording (a website change, after this release);
- the lines staff type on Send Quotation and in a lead's manual quote: they carry no `productId` and still print as
  custom items, so A.3 is not fixed there (§J);
- LTE quotation lines: the plugin's `lte_packages.json` price, built in `api_crm_misc.php` 577–600. Whether Uganda sells
  LTE is not established. LTE's `notes` and `adminNotes` do follow §F row 4, through `createCrmQuote`;
- the retailer app (`retailer/index.html`): it is not changed (§E.3);
- uCRM VAT configuration. While it is out, a taxable product is unpriced (§D.4);
- South Sudan's own "PENDING | Auto-generated …" notes.

## D. The catalogue: one resolver, `lib/UcrmQuoteLines.php`

One class prices every Uganda line, so the form, the cash amount and every quote agree.

### D.1 Reading uCRM

- `GET products?limit=500` and `GET service-plans?limit=500`, with **the same client the quote is made with**.
  `postQuote` uses the `crm_auth_token` client when one is set (`KycService.php` 1686–1691), so the resolver does too.
- **A read succeeds** only when both answers are JSON lists, neither carries the `raw` key that `CrmApiClient` returns
  for a body that is not JSON, and each holds fewer rows than the limit. Anything else means *"uCRM's products could not
  be read"*: nothing from it is used, and it is never cached.
- **At submission the resolver runs exactly once, before the first write.** That one result is the only source of the
  cash amount, the quote's lines and the stored lines (§F.3). handleNew's later quote-building step
  (`KycService.php` 957–959) uses it and makes no second read.
- **To draw a page** (the KYC form, Send Quotation), a successful read may be cached for 60 s; a failure is never
  cached. These reads use a short timeout: 3 s to connect and 5 s in all, through a new `CrmApiClient` option whose
  default leaves every other caller unchanged. A timeout counts as unreadable, and the page shows its banner (§G).
  Reads at submission keep the client's normal timeout.
- `ucrm_products.json`, the local copy made by "Sync UCRM products", is never read.

### D.2 Rows that count

- A row with `isActive === false` or `archived === true` is ignored, where the field exists.
- If rows carry `organizationId`, only rows with none, or with the quoting organization, count. (Whether uCRM 4.5.33
  returns these fields is not established; the rule costs nothing if it does not.)

### D.3 Keys and matching

- The key is `PublicPriceFeed::planKey`: lower case, the word "starlink" removed, letters and digits only. The website,
  the assistant and the WhatsApp summary already use it. (amended by §R.4: under D14 the key serves only the uCRM-to-uCRM rules, §R.3.1)
- **An empty key matches nothing.** (`planKey('Starlink')` is empty.)
- **Every "exactly one" counts all counted rows.** Two products with one key make the item unpriced (*"two products in
  uCRM are spelled like X"*), even when a stored `ucrm_product_id` names one of them. A stored id only confirms a match;
  it never resolves an ambiguity, and an id whose live product's key differs from the row's key is ignored (the fuzzy
  auto-map can have set a wrong one). (amended by §R.4.6: an item is joined to its product by its link only; no name, no spelling ambiguity and no stored `ucrm_product_id` decides it)

### D.4 Lines

**A plan line** comes from the `subscription_plans.json` row, else the `kyc_packages.json` row, named by
`package_choice`. Its product must be:
- the one counted product whose key equals the plan row's name key;
- a *plan mirror*: its key equals the key of exactly one counted service plan;
- priced exactly like that service plan's **one enabled period with `period` = 1**, both rounded to 2 dp. A plan with
  no enabled 1-month period is unpriced (*"<plan> has no 1-month price in uCRM"*). `PublicPriceFeed::planPrice` is not
  used for this: it falls back to a top-level price or to the cheapest period of any length, and the customer would then
  be quoted a monthly line at a multi-month price.

The plan line's unit is always `month`, whatever the product's unit.

**A kit or accessory line** comes from the cart's ids, else `device_id`, through the `kyc_devices.json` row by id.
**The browser's title and price are never used.** Its product is the one counted product whose key equals the row's
title key, and it must **not** be a plan mirror. Quantity comes from the cart (or `kitQty`) and is at least 1. The unit
is the product's, else `piece`.

**The installation line** (D2, D8) is the one counted product whose key equals `planKey('Professional Installation')`,
quantity 1 per application, unit the product's, else `job`. It is added only when the answer is "DishNet installs"
(§E).

**Every line**, plan, kit, accessory or installation, must also satisfy:
- its price is a number above 0 with at most 2 decimals; otherwise the item is unpriced (*"<item>'s price in uCRM is
  0"*, or *"… has more than 2 decimals"*). A 0 line reads as "free" to `QuotationPrefill`, and the WhatsApp summary
  drops it;
- its product is not taxable: `taxable` true or a non-null `taxId` makes it unpriced (*"<item> is set as taxable in
  uCRM; the cash would not equal the quotation's total"*). uCRM could add tax to a line sent with `productId`;
- its product's `invoiceLabel`, when not empty, has the same key as its name; otherwise it is unpriced (*"<item>'s
  invoice label differs from its name"*). uCRM may print the invoice label, and the WhatsApp summary splits the plan
  line by its key (`webhook.php` 2958–2962).

Each resolved line is `{productId, label: the product's name, price, quantity, unit}`.

**Fiber on Uganda is always unpriced** (*"Fiber is not priced from uCRM on this installation"*). The Fiber installation
fee and product 244 are South Sudan's, so `fiber_install_fee`, `fiber_install_product_id` and 244 are never read on
Uganda: not in handleNew, handleExisting, the retries or `buildLeadItems`. Uganda sells no Fiber today.

### D.5 The result

The resolver returns exactly one of:
- **`no_plan`**: `package_choice` names no plan row. This is not "unpriced": it keeps today's refusal (§H);
- **`unpriced`** with one reason: any line unresolved, or uCRM unreadable. It is all or nothing, never a partial quote;
- **`priced`** with the lines and their total (Σ price × quantity, rounded to 2 dp).

"Unpriced" is never represented as 0 or null in `checkAmount`.

**Reasons** are plain words naming the item. Every name in a reason (a uCRM product or plan name, a plugin row title)
passes through `QuoteOrganization::plain` before the reason is stored or returned: the staff app prints the agent's
message as HTML. A reason names no price except the two it compares.

## E. The installation question

### E.1 On the KYC form

For a **Starlink** customer whose connection is **New Connection**, step 3 asks, above the order summary:

> Who installs? ◯ DishNet installs  ◯ Customer installs (kit only)

- Nothing is preselected. The question is shown only for Starlink + New Connection. While hidden, its inputs are
  disabled and carry no `required`, and they are cleared whenever the service or the connection type changes.
- `wizNext` checks the group explicitly: with none checked it shows a red border and "Choose who installs", and step 3
  does not advance. `kycFormSubmit` checks it again. (A radio always has a value, so `wizNext`'s present check passes it,
  and a `required` radio in a hidden panel would block Submit with no visible message.)
- When the installation product does not resolve, "DishNet installs" reads *"Installation not priced in uCRM — no
  quotation, Cash not possible"*.

Stored on the application as `install_by` = `dishnet` | `customer`. "DishNet installs" adds the installation line;
"Customer installs" adds none. **Shifting Connection and Ownership Change** get no question and no line: re-installation
is quoted separately (template clause 14).

**A Starlink New Connection without an answer is unpriced**, never guessed.

### E.2 Who sees the answer

`install_by` is shown:
- on the Orders card: "Install: DishNet" or "Customer installs — kit only";
- in the staff messages `kycSubmitted`, `kycCrmCreated` and `kycAdditionalService`;
- in `adminNotes` (§F.2).

When the answer is "customer", the ops message's "Next" step reads "Hand over kit (no installation)", and the
existing-customer job's title and ACTION list say "Kit only — no installation".

### E.3 Other entry points

- **The retailer app and the mobile API** send no `install_by` and no `ucrm_total_seen`. On Uganda:
  - a Cash sale from them is refused (D10). The refusal names the web KYC form;
  - a Credit Starlink New Connection from them is registered with no quotation. (amended by §R.11: every Credit sale from them, and from a form opened before the deploy, is registered with no quotation, and is quoted in uCRM by hand)

  The retailer app shows a fixed "Application Submitted!" and does not display the server's message, so for those Credit
  registrations nobody is told there is no quotation; the Orders card is the only place it shows. The app is not changed
  in this release (§C), and §M tells the operator.
- **The existing-customer path** (`handleExisting`) is reached only through the mobile API with a `customer_id`; the web
  form's `customer_id` is always empty and the retailer app has no such field. It therefore sends no installation answer,
  and a Starlink New Connection there is unpriced unless the API gains the field.
- The retailer app picks plans from `kyc_packages`, while the server looks `package_choice` up in `subscription_plans`
  first (`KycService.php` 196–197, 1381–1384). If the two id ranges overlap on Uganda (not established), the resolver
  prices the subscription plan, not the app's choice. This is today's behaviour, recorded. (amended by §R.4.2: on the gated path a sale without the web KYC form's marker is not priced from uCRM at all)

## F. The quote-create sites

Exactly the five 5.18.99 counted, and no sixth (test_quote_company I1).

### F.1 Lines and notes

| Site | Lines on Uganda | `notes` (the customer sees them) | `adminNotes` (staff only) |
|---|---|---|---|
| KYC new (`handleNew` → `postQuote`) | the resolver's (§D) | `kyc_quote_notes_prefix` if set, else empty | `KYC application #<id> · registration · Sales: … · Connection: … · Priority: … · Install: …`, plus `Plugin ref: …` when the reference is not `PENDING` |
| KYC retry (`KycCrmSync::afterCreate`) | §F.4 | the same rule | the same, with "reached uCRM later" |
| Existing customer (`handleExisting`) | the resolver's | the prefix, then `Installation site: <new address>` (customer data, not internal) | the rest of the "ADDITIONAL SERVICE" block, plus the application id |
| Send Quotation / lead / LTE (`QuotationService::createCrmQuote`) | §J (LTE: unchanged, §C) | the prefix, then the staff's own "Note / Footer message" | `Plugin ref: … · Agent: …` |
| Admin debug retry (`api_cron_debug` `retry_quote`) | §F.4; refused → 409 | the prefix only | `Retry for App #<id> · Sales: …` |

- Every check runs **before** the quote is created: uCRM's `quote.add` webhook messages the customer at once.
- `PENDING` never reaches uCRM. It is removed only from text the plugin composes (notes, adminNotes); the post gate at
  `KycService.php` 1122, which reads the stored reference, is not changed.
- Empty `notes` makes the template's NOTES box disappear.
- The prefix stays customer-facing. On Uganda only, the Settings field gains a hint saying so, and its "+211" example is
  replaced. South Sudan's settings page is unchanged.

### F.2 Order of checks and writes

**The first refusal wins, and its text is the one the agent sees.**

**Existing customer** (`handleExisting`):
1. 5.18.99's checks that need no request: the CRM ID is a number (R3), the setting is valid (R6).
2. `checkClient` (GETs only), whether or not the sale is priced, so a misfiled customer is told to move, never told to
   make the quotation in uCRM. **This moves ahead of the wallet check.**
3. The resolver's GETs.
4. The Cash checks: unpriced, then the wallet.
5. Writes.

**New customer** (`handleNew`):
1. The resolver's GETs and the posted-total check (§G), **before the duplicate-phone block** (`KycService.php` 510) and
   before the wallet check (680), so a refusal writes nothing: not even a `duplicate_confirmations` row.
2. The wallet check, then POST clients, as today.
3. After POST clients: `checkClient`, which sends a GET only when the create answer lacks `organizationId`.
4. The salesPerson PATCH (§I), only when that check passed.
5. POST quote, only when the sale is priced.

**Retries** (`KycCrmSync::run` / `afterCreate`, `retry_quote`): `checkClient`, then the salesPerson PATCH after a create
(§I), then the resolver and §F.4's rules, then POST quote.

### F.3 What every application stores

On Uganda, every application from either handler stores, at submission, whether or not the client create succeeded:
- `quote_items`: the resolved lines (absent when unpriced);
- `quote_lines_source`: `ucrm-5.18.102`;
- `quote_items_total`: their total;
- `quote_lines_read_at`: when they were read;
- `install_by`, when asked;
- `offer_price` and `device_price` as the resolved prices, or null when unpriced.

Only lines carrying `quote_lines_source` count as resolved. **The source is never inferred from `productId`**: lines
stored by 5.18.101 carry a `productId` whenever the plugin's row was mapped. (Keys are added, never renamed.)

### F.4 Retries

A retry is `KycCrmSync::afterCreate` (cron and the admin's Retry) or `retry_quote`.

- **Marked lines.** The retry re-reads products and checks that each stored `productId` still exists and is still
  counted. If one is not, the application is unpriced with that reason. (amended by §R.4.4: each line's link must also still be active and its price unchanged)
- **A Cash application (`amount_charged` > 0) is never re-priced.** It is quoted only when the lines it would send total
  exactly `amount_charged`, both rounded to 2 dp: its marked lines, else lines resolved now. Otherwise no quote is made,
  and the refusal reads *"paid UGX <amount_charged> at registration — make the quotation in uCRM for that amount"*. That
  covers every Cash application saved before 5.18.102, whose cash came from the old `resolveAmount`. (amended by §R.4.2, E-1: at its retry such an application is unpriced, P2 where cash was collected, and is quoted in uCRM by hand)
- **A Credit application without marked lines** is resolved now. A Starlink New Connection without `install_by`, which
  every application saved before 5.18.102 is, is unpriced (D11). (amended by §R.4.2, E-1: at its retry every application saved before the deploy is unpriced, whatever is linked)
- `retry_quote` answers 409 with the reason.
- The retry runs from cron, so its refusal reaches nobody at the time it is made. It is shown on the Orders card.

### F.5 Refusals and records

- **A Credit sale that is unpriced** is registered with no quote, and records the refusal: `quote_refused` = `P1`, with
  `quote_refused_text` and `quote_refused_at`. The agent's result message says why and to make the quotation in uCRM.
  It never writes `wa_quote_pending`.
- **A Cash sale that is unpriced** writes nothing (§H); the store's digest is unchanged.
- **A retry refused by §F.4** records `P2`. **A quote suppressed by `kyc_auto_quote_max_amount`** records `P3`:
  *"over the auto-quote limit of UGX <max> — make the quotation in uCRM"*, in handleNew and in the retry
  (`KycCrmSync.php` 299). Until now that suppression wrote only a PHP log line.
- `P1`–`P3` are this release's codes. They sit beside 5.18.99's R1–R7 and are written only where the gate is on (§C).

### F.6 The created quote is read back

After every Uganda quote POST, the plugin compares the created quote with what it sent: from the POST answer, or from
one `GET billing/quotes/{id}` when the answer has no items (the GET `postQuote` already makes when the number is
missing, made unconditional on Uganda). It compares:
- each item's label and price;
- the quote's total, against Σ price × quantity (on a Cash sale, `checkAmount`).

A mismatch is logged and stored as `quote_mismatch` `{sent_total, created_total, at}`, and the Orders card shows it.
Each item's `type` and whether it carries a `productId` are recorded as a note, not a mismatch. `quote.add` has already
messaged the customer, so this check detects; it cannot prevent.

### F.7 Logs

The salesPerson, unpriced and mismatch lines go to PHP's `error_log` only, never `PluginLog` or `data/plugin.log`. They
carry ids only: no staff name, no amount.

## G. The KYC form on Uganda

- Plans and kits are drawn with **uCRM's price** (from the resolver, cached ≤ 60 s). A row that does not resolve shows
  "not priced in uCRM" and adds nothing to the total.
- The installation question (§E); "DishNet installs" adds the installation line to the totals.
- If uCRM cannot be read when the form is drawn, a banner says so; the form still opens for Credit registrations.
- **One JS function computes the total**, installation line included, for step 3, step 5, the client-side wallet check
  and `ucrm_total_seen`. (Today three places compute it, each adding installation for Fiber only.)
- **When the form's total is unpriced** (a selected row, a missing or unpriced installation answer, or the banner), the
  Cash option is disabled with the reason, and `kycFormSubmit` refuses Cash with an alert, as it already does for the
  wallet balance (`kyc_form.php` 1835–1849).
- **The posted total.** The form posts the total it showed as `ucrm_total_seen`. On a Cash sale the server refuses, at
  §F.2 step 1, when:
  - `ucrm_total_seen` is missing (D10). This includes the retailer app, the API, and a form page opened before the
    deploy;
  - it is not a plain decimal: digits and an optional `.`, no separators;
  - it differs from the fresh resolved total, both rounded to 2 dp (*"prices in uCRM changed since the form was opened —
    reload"*).

## H. The cash amount (D5)

On Uganda the resolver's result replaces `resolveAmount`'s null and 0 handling, in handleNew (`KycService.php`
660–673) and in handleExisting (203–211):

| Result | Cash | Credit |
|---|---|---|
| `no_plan` | refused as today: "Please select a valid package/plan." | the same |
| `unpriced` | **refused before any write**, with the text below | registered: `checkAmount` 0, `isLead` true, `amount_charged` 0; no wallet check, payment, debit, collection, cashbook entry or quote; `P1` recorded (§F.5). The "Selected plan has no pricing" refusal (671) does not apply |
| `priced` | `checkAmount` = the resolved total; refused when the total is not above 0 | the same |

- handleExisting does not rely on its `checkAmount > 0` guards (211, 432): an unpriced Cash sale there is refused, never
  let through with no debit.
- **The one rounded total** (2 dp) is used for the wallet check, the debit, the uCRM payment (today it posts the
  unrounded float, 1007), `amount_charged`, the collection, the staff ledger and the cashbook: every place the old amount
  was.
- **The Cash refusal text:** *"<item> has no usable price in uCRM. Nothing was saved and no money was taken. Register as
  Credit, or ask an admin to fix the product in uCRM."* The other Cash refusals name their cause: the posted total
  (§G), uCRM unreadable, the installation answer.
- **During a uCRM outage a Cash sale is refused**, not saved for retry as today (docs/30 §3). The agent registers it as
  Credit, or waits (§M).
- `kyc_auto_quote_max_amount` still only suppresses the quote, and now records `P3` (§F.5).

South Sudan keeps `resolveAmount` as it is.

## I. "Prepared by" (D3)

- `UcrmClientTarget` gains a lookup **by key**, behind the gate: exactly one client attribute whose key is exactly
  `salesPerson` and which is not a tax field (`EfrisClientField::isTaxField`). It reuses the one cached
  `GET custom-attributes` of that instance.
- **After every successful client create** on Uganda, in `KycService::handleNew` and in `KycCrmSync::run` (between the
  create at 204 and `afterCreate` at 243), and once `checkClient` has passed: one
  `PATCH clients/{id} {attributes: [{customAttributeId: <found id>, value: <sales person>}]}`, with the same client and
  the same `UcrmClientTarget` instance. **Whether or not a quote follows**: unpriced, auto-quote off and over-limit
  included, because D4's agent then makes the quotation in uCRM, and that PDF prints the attribute. The create request
  itself is unchanged: an unknown attribute id fails the whole create with a 404 (docs/30).
- The value is the sales person the form records; on the retry, the application's `sales_person`, else
  `retailer_name`.
- **Best effort, never blocking.** The attribute absent, ambiguous or unreadable → no PATCH and one log line (§F.7); the
  quote is still made, and the PDF shows the template's fallback. A failed PATCH is logged, and the registration stays
  green.
- **Existing customers: no PATCH.** Whether a one-attribute PATCH merges or replaces the client's attributes, and so
  could erase the EFRIS TIN, is not measured.
- Never attribute id 1, never the South Sudan ids, never a name-based guess.
- **Known limit.** On Send Quotation, lead and existing-customer quotations, "Prepared by" shows the client's
  `salesPerson` (who registered the customer) or the template's fallback, never the person sending the quote.

The operator's one-time setup: uCRM → System → Customisation → Custom attributes → Client → add **"Sales Person"**, then
confirm that the key shown is exactly `salesPerson`.

## J. Send Quotation and lead quotes on Uganda

- **The plan picker's prices** come from the resolver (plan mirrors), not `customer_price`. A plan with no uCRM price is
  listed without a price and cannot be picked into a line.
- **The lead pre-fill.** `onLeadPick` (`send_quote.php` 368–389) uses the same exact key match as `buildLeadItems`,
  from a server-side map of key → resolved plan, or adds no line. No substring match, and no 0-priced
  "service — plan" line.
- **`buildLeadItems`** returns the one resolver plan line whose key equals `planKey(interest_plan)` exactly, or nothing:
  no placeholder, no Fiber fee line. With no items, the existing "add items manually" message appears.
- **No line is ever sent at price 0.** `sendLeadQuote` and `sendManualQuote` refuse a line whose price is empty or ≤ 0:
  *"<label> has no price — enter one or remove it"*.
- **Lines staff type themselves** are theirs and are sent as typed. They carry no `productId` (§C, Out).
- **The lead quotation's WhatsApp carries no lead notes**, only a note the sender types on the quotation screen for the
  customer. Lead notes are internal: AI summaries, "Auto-created from WA Lead Recovery …", staff notes
  (`QuotationService.php` 201–208, 518–521).

## K. The plugin's own PDFs and the product push

### K.1 The fallback quotation PDF

When uCRM serves no PDF in time, `PluginQuotePdf` renders one. Its non-USD terms still say "penalties or reconnection
fees follow your service agreement", "best-effort", "commitment periods … follow your service agreement", "report faults
within 24 hours" and "not liable … exceeding 3 months of fees", all withdrawn on 12 Oct (docs/67 §I, §K). On Uganda they
become:

1. All prices in UGX. *Either* `QuoteTaxLine::TEXT`, when the quote has no tax lines, *or* "VAT is itemised in the
   totals", and then the quote's tax rows are rendered. Prices may change with 30 days' notice.
2. This quotation is governed by DishNet's published Terms of Service, Terms of Sale, Warranty Policy and Returns,
   Refunds and Cancellations (dishnetuganda.com/policies.html); where anything here is less favourable to the customer,
   those terms apply.
3. Installation as quoted; non-standard mounting is quoted before work starts.
4. Responsibility and its limits as section 14 of the Terms of Service and, for a Starlink installation, section 25 of
   the Installation Terms accepted for it set out. That limit never applies to damage to the customer's property caused
   by DishNet's negligence. Events outside DishNet's control as section 15.
5. Governed by the laws of the Republic of Uganda.

Item 4 keeps the cap of page-2 clause 15 (docs/67 §J item 5, §L default 1): without it, item 2 would remove the cap.

The header reads "IT Solutions Provider · Starlink Installation · <locality>", as uCRM's page 1 does, instead of
"Internet Service Provider". The fallback still carries no Service Agreement and no Flex sentence (a known limit).

### K.2 The Delivery Acknowledgment PDF (D9)

On Uganda it is **not sent**. `DeliveryPdfService::generateAndSend` returns "not sent" behind the gate, before
generating anything. That covers its three callers: Cash sales at registration (`post_kyc.php` 45–62), Credit sales at
the first payment (`webhook.php` 1418–1443), and `FiberInstallService` 350. `webhook.php` itself is not changed.

The reason: it carries South Sudan's terms ("Kit setup included", devices leased, a $25 reconnection, a 6-month minimum,
a +211 number, South Sudan law), promises a setup call and "1–2 working days" even for a kit-only sale, and prices its
items from the browser's cart, labelled USD. A Uganda delivery note, built from the stored lines with Uganda's terms, is
a later release.

### K.3 The plugin → uCRM product push (D12)

On Uganda, behind the gate, the plugin no longer writes uCRM products. Every save of a plan or kit would otherwise
overwrite the product's name, price and taxable flag, which are now the quote's lines and the cash amount:
- `save_plan` and `save_hardware` (`post_sync.php` 418–476, 544–605) save the plugin's row and skip the uCRM PATCH or
  POST. The flash says *"uCRM's product was not changed: on Uganda, prices are set in uCRM Products."*
- `push_products_to_ucrm`, `sync_plan_to_ucrm` and `sync_hardware_to_ucrm` (`api_products_admin.php` 597, 798, 847)
  answer with that refusal and write nothing.

South Sudan is unchanged.

## L. Tests and proof

### L.1 The new test, `tests/test_quote_from_ucrm.php`

On fakes. Every rule below has a weakened copy that must fail.

**The resolver:**
- the read: `limit=500`; a `raw` answer, a full page and an error are each unreadable and never cached; the quote's own
  client is used; the submission reads fresh and once; `ucrm_products.json` is never read;
- rows: inactive, archived and other-organization rows ignored;
- keys: an empty key matches nothing; a duplicate makes the item unpriced, even with a stored id naming one; a stale
  stored id is ignored;
- the plan: plan mirror, period-1 price equality at 2 dp, and a plan whose only enabled period is 3 months at the
  product's price is unpriced (a copy that calls `planPrice` must fail);
- the kit: a plan mirror refused; the browser's title and price ignored; quantity at least 1;
- the installation line: by answer, quantity 1 with `kitQty` 2 (and the matching cash), price above 0;
- every line: a 0 plan mirror with a 0 period, and a 0 kit, each unpriced (a copy that checks only the installation
  line must fail); more than 2 decimals; taxable; a `taxId`; a differing invoice label;
- Fiber on Uganda: never product 244, never `fiber_install_fee`;
- reasons: a product named with `<b>` and `&` reaches the message plain.

**The sites:**
- each site's request body: lines, notes, adminNotes; `PENDING` never in composed text, and the post gate unchanged;
- an I1-style inventory: on Uganda, each quote-create site sits after the resolver's verdict, in its own function;
- the order of §F.2, as exact request sequences, with and without a salesPerson attribute;
- no `wa_quote_pending` on an unpriced sale; Shifting and Ownership Change get no question and no line;
- the stored keys (§F.3) on both handlers, the create succeeding and failing;
- the retries (§F.4): a pending 5.18.101-shaped Cash application (a `productId`, the plugin's price) is not quoted; a
  copy that trusts `productId` must fail. A created-but-not-quoted Cash application through `retry_quote` after a price
  change: no quote, 409. A handleExisting Cash application retried: no quote. A stored product since deleted: unpriced.
  A copy that quotes anyway must fail;
- the readback (§F.6): the fakes gain modes for a request carrying `productId`: substitute the product's invoice label,
  price and unit; add a tax; reject it with 422; ignore it. Each leads to the expected `quote_mismatch` or refusal.

**The cash amount:**
- §H's table on both handlers, each cell with its copy. One copy keeps the 671 refusal; another relies on handleExisting's
  `checkAmount > 0` guards;
- one assertion that the wallet check, the debit, the uCRM payment, `amount_charged`, the collection, the ledger, the
  cashbook and the retry's payment all equal the quote's total, with a copy per sink that keeps `resolveAmount`;
- a fake that changes a price between two reads in one submission still yields cash = quotation total;
- the posted total: equal accepted; ±1 UGX, a missing field, `2,499,000` and a stale tab each refused; a
  confirmed-duplicate Cash sale refused leaves no new `duplicate_confirmations` row;
- an unpriced Credit sale on Uganda: client created, no POST quote, `P1` recorded, the amber flash;
- a Cash submission through `api_retailer.php` `kyc_submit` on Uganda is refused before any write;
- uCRM down: a Cash sale refused with the unreadable reason; the form opens with the banner within 10 s against a slow
  fake.

**"Prepared by":** the PATCH by key, after `checkClient`, before the quote, in handleNew and in `KycCrmSync::run`; sent
when the sale is unpriced too; never id 1 (with salesPerson at id 1 only, no `PATCH clients/*`); absent → no PATCH; no
PATCH for existing customers; a failed PATCH leaves the registration green; the log lines carry ids only.

**The rest:** Send Quotation and leads (§J: the exact pre-fill, no placeholder, an unpriced plan not pickable, a 0 line
refused, no lead notes in the WhatsApp); the fallback PDF's terms and header; the Delivery PDF not sent; the product push
refused; the install answer shown where §E.2 says.

**The form, in a browser.** The suite runs no JavaScript, so a Chromium-driven check of the rendered form (Playwright,
present in this environment) proves: an unanswered question stops step 3 with its message; a hidden question never blocks
Submit; Cash is disabled when unpriced; the one total function feeds all four places. If the release environment cannot
run it, that is recorded as untested. The rendered HTML is also asserted in PHP: the group is disabled and not
`required` while hidden.

**The boundary**, measured from Git against `f2055baf`: only the declared files (§L.4) change; no migration; the manifest
by its version line only; every runtime hunk under the gate; `php -l` and the PHP 7.4 rules. Planted faults must fail it.

### L.2 Pinned tests rewritten on purpose, never weakened

Both fakes (`fake_ucrm_kyc.php`, `fake_ucrm_quote_org.php`) and the journey fixture gain a default Uganda catalogue:
- products spelled and priced like the plans and devices each test seeds, and "Professional Installation" above 0;
- service plans with matching names, each with an enabled 1-month period at the product's price;
- a salesPerson-attribute toggle.

Every Uganda form post that expects a quote carries `install_by`, so each journey still makes its quote; only the lines,
notes and adminNotes differ. Rewritten, each with its new expected outcome stated in the test:
- `test_quote_company`: B2–B4; C1 and C2 (i)–(iii) to §F.2's exact sequences, with and without the attribute; C3; C6's
  `retry_quote` control; B4 extended (salesPerson at id 1 only → no PATCH); the S `ug2` control and its J13
  expectations, which change in notes and adminNotes only; I4, I1 and I5;
- `test_kyc_crm_create` L1–L7 (L5 deliberately: §F.4);
- `test_notify_kyc_quote_send` 1–6;
- `test_kyc_crm_messages` B–F;
- `test_quote_company_mutants`: anchors re-taken in the changed files; W1–W60, W36b and W61 re-run with their controls,
  and every copy still caught at the section it names.

Run unchanged, with whether either fails not yet established: `test_notify_quote_once`, `test_notify_kyc_race`.

`ug_off` and `ug_off_word` stay byte-identical to `ce7ac388` (D7).

**South Sudan byte for byte.** The S journey for `ss` and `ss_keyset` (both trees run it, so `ce7ac388` stays the base)
is extended with: the Send Quotation and Settings pages rendered; a lead quote with empty items, so `buildLeadItems`
runs; and a `kyc_submit` through `api_retailer.php`.

**The history checks:**
- `test_quote_summary` M is re-pointed to compare `f2055baf` with `8ff469f5` from Git, and holds this tree's
  `webhook.php` to `f2055baf` byte for byte;
- I4's held check is narrowed: each 5.18.99 file that 5.18.102 changes must still carry 5.18.99's hunks (`ca05ada5`
  against `ce7ac388`) verbatim.

**Carried:** the two date-test fixes 5.18.101 left for the next release (its known limit 8).

### L.3 The release

Version pins to 5.18.102; a SAFETY.md entry; the release suite twice; `deploy-5.18.102.sh` derived from 5.18.101's by
asserted edits; the rehearsal chained on 5.18.101's; one clean trial on its own; three reviews; a docs/07 entry.

**The deploy script adds, before anything changes (stage A), and again after the copy:**
- **The catalogue preflight.** It runs this release's `UcrmQuoteLines` against a copy of the book, GETs only, through
  both the plugin client and the token client. It covers every active `subscription_plans` and `kyc_devices` row the
  form offers, and the installation product. The log prints counts only: resolves, not a product, ambiguous, plan price
  differs from its service plan, taxable, unreadable. The names go to a 0600 sheet. Any unpriced row is a NO-GO unless
  the run carries `--accept-unpriced`, which the log names. The after-copy reading must equal stage A's. The count of
  non-GET requests must stay 0.
- **Notes:** the salesPerson attribute (exactly one, absent or ambiguous); quotation 000314 read back (each item's
  type, whether it carries a `productId`, its unit; no names, no amounts), as the first measurement of what uCRM does
  with a line; Q-N4 extended to count pending and review Uganda applications by line shape (with or without
  `quote_lines_source`, with or without `productId`) and by sale type; the Uganda KYC applications of the last 90 days by
  entry point, or "not measurable" if the record does not carry it; when a product push or a plan or kit save last ran.
- Q-G3's note stays true under D7: with the setting off, Uganda quotes as 5.18.101 does.

### L.4 Declared files

**Added:** `lib/UcrmQuoteLines.php`, `tests/test_quote_from_ucrm.php`, and its directory `tests/quote_from_ucrm/` (its
sections, their weakened copies, and the form's browser check). (amended by §R.11: also `lib/UcrmQuoteLinks.php` and `tests/quote_from_ucrm/L_links.php`)

**Changed:**
- `lib/KycService.php`, `lib/KycCrmSync.php`, `lib/UcrmClientTarget.php`, `lib/QuotationService.php`,
  `lib/PluginQuotePdf.php`, `lib/DeliveryPdfService.php`, `lib/CrmApiClient.php` (the timeout option),
  `lib/NotificationService.php` (§E.2);
- `tabs/sales/kyc_form.php`, `tabs/sales/send_quote.php`, `tabs/sales/applications.php`, `tabs/admin/settings.php`;
- **amended during the build (§P.1):** `tabs/sales/subscription_plans.php`, `tabs/sales/hardware.php`,
  `tabs/sales/product_mapping.php` — their push buttons read §K.3's refusal as "✅ Done / Push complete — 0 created";
- `includes/api/api_cron_debug.php`, `includes/api/api_products_admin.php`, `includes/post/post_sync.php`;
- `manifest.json` (its version line), `SAFETY.md`;
- the fixtures and the tests §L.2 names, and `tests/fixtures/quote_company_journey.php` (the S journey's extension).

**Kept byte for byte**, among everything else: `webhook.php` (QS-G1 must still read it), `QuoteOrganization.php`,
`QuoteTaxLine.php`, `TenantProfile.php`, `PublicPriceFeed.php`, `cron_quote_wa.php`, `CashbookService.php`,
`includes/post/post_kyc.php`, `includes/api/api_retailer.php`, `retailer/index.html`.

If the build finds it must change another file, the list is amended here first, with the reason.

## M. What the operator does

**Before deploying:** (amended by §R.6: the runbook's order is §R.6's ten steps; steps 1, 2 and 4 keep their numbers)
1. **Record 5.18.101's deployment** in docs/07: its copy time and counts, its `--after-only`, and the quarantine's
   `--check` begun after that copy. The 5.18.102 script takes its baseline and its A14 window from that record. Its
   deploy log is needed for this.
2. **Add the "Sales Person" client attribute** (§I) and confirm that its key is exactly `salesPerson`.
3. **Check the catalogue.** Each plan offered on the form must have the same key (case, punctuation and the word
   Starlink ignored) in three places: its plugin plan name, exactly one uCRM Product, and exactly one uCRM Service Plan
   with an enabled 1-month period at the Product's price. Each kit and accessory needs exactly one Product spelled like
   it, and there must be one "Professional Installation" Product. Every one of them must be priced above 0, with Taxable
   off and its invoice label empty or the same as its name. The deploy's preflight measures all of this before anything
   changes; in September only two of five service plans had a Product. (amended by §R.6: replaced by its step 3, "Prepare uCRM, and decide the picks")
4. Leave Settings → "Quote notes prefix" empty, or put in it only text meant for customers.

**What changes for agents and staff on Uganda:**
- A Cash sale is refused when any item has no usable uCRM price, during a uCRM outage (not saved for retry, as before),
  and when the form was opened before the deploy. Register it as Credit, or wait.
- A Cash sale from the retailer app or the mobile API is refused; those channels cannot answer "Who installs" or send
  the total they showed. A Credit Starlink New Connection from them gets no quotation, and the app does not say so; the
  Orders card does. (amended by §R.11: every Credit sale from them, and from a form opened before the deploy, gets no quotation, and is quoted in uCRM by hand)
- Saving a plan or kit in the plugin no longer changes uCRM; prices are set in uCRM Products (§K.3).
- No Delivery Acknowledgment PDF is sent (§K.2).
- Marking products taxable in uCRM (the VAT plan's step 3) stops automatic quotes until a VAT release.
- `ucrm_quote_organization_id` = 0/off turns all of this off as well as 5.18.99's behaviour.

**Deploy** 5.18.102 with its command. The preflight is the first proof; the next real KYC quotation is the second.

## N. Open, and not decided here

- **What uCRM 4.5.33 does with `productId`** on `POST clients/{id}/quotes`: whether it makes the line type `product`,
  ignores the id (type custom, as 000314 printed), rejects it, or replaces the line's label, unit or tax with the
  product's. The deploy reads 000314 back (§L.3), and every quote is compared after it is made (§F.6).
- Whether uCRM stores `adminNotes` and shows it only to staff. The plugin has always sent it; nothing reads it back.
- The website's "installation included" wording (D2: to be corrected in a website change).
- South Sudan's "PENDING | Auto-generated …" notes: whether to fix them there is a separate decision.
- The retailer app: showing the server's message, and asking "Who installs".
- A Uganda delivery note (§K.2).
- Whether the release environment can run the browser check of the form (§L.1).

## P. The build: what differs from this design, and why

Recorded during the build on `quote-102` (cut on `f2055baf`). Each item says what was measured; none changes a decision.

### P.1 Code

- **The product screens' push buttons (§L.4, amended).** Refused with 409 on Uganda (D12), the bulk buttons on the
  Plans and Hardware screens alerted "✅ Done! Created: 0 …" and reloaded, and Product Mapping said "✅ Push complete —
  0 created": none of them read the answer's status. On Uganda each now shows the server's message instead. The lines are
  emitted only where the gate is on, so South Sudan's pages stay byte for byte (`test_quote_company` G).
- **`post_leads.php` is unchanged** (it is not in §L.4). Its manual-quote handler drops a line priced 0 or less before the
  service sees it, so on Uganda the server's refusal (§J) covers the lead form and both API actions, and the panel's
  manual form is stopped by the page's own check on submit. To refuse on the server there too, the handler would have to
  keep a labelled 0-priced line on Uganda: a later change, declared first.
- **No read-back for Send Quotation's quotes** (`createCrmQuote`, §F.6): a lead or manual quote has no application to
  record a mismatch on. The KYC quotes are read back.
- **The catalogue stops at the first failed read.** Against a uCRM that never answers, reading service plans after
  products had already timed out took the form 10.05 s to show its banner (§L.1 asks for 10). Products is read first;
  a failure there is the answer, and plans are not read.
- **The second PATCH in the retry's request log** (`{"clientType": 2}`) is 5.18.101's payment code, not "Prepared by";
  the tests count only the PATCH that carries attributes.
- **`tabs/sales/send_quote.php` loads `QuoteOrganization.php` itself** — 5.18.99's rule that every file naming the class
  loads it (`test_quote_company` I7), which the page first met only through `QuotationService`.
- **Marker comments** in `CrmApiClient.php`, `KycCrmSync.php` and `KycService.php`: four changed lines sat more than 30
  lines from any 5.18.102 marker, which the boundary check (`test_quote_from_ucrm` I3) requires. Comments only.
- **The fallback PDF's tax rows** are read as an invoice carries them (`taxes[{name, totalValue}]`, `totalTaxAmount`).
  That a uCRM quote carries the same shape is **assumed, not measured**; with no tax row the PDF prints `QuoteTaxLine`'s
  sentence as before.

### P.2 What D9 (no Delivery PDF on Uganda) also changes — recorded, not changed

`DeliveryPdfService::generateAndSend` returns "not sent" on Uganda before anything is generated. Its callers read that:
- **The payment webhook** (`webhook.php`, kept byte for byte): a Uganda credit KYC customer is never marked
  `delivery_pdf_sent`, so each of their payments reads `clients/{id}/payments` once more (after the webhook has already
  answered uCRM) and logs "Delivery PDF generation failed for {name}: skipped: …" — a line that already carried the
  customer's name.
- **`post_kyc.php`** (kept byte for byte): a Uganda Cash customer is not marked either, so with `kyc_messages_like_crm`
  off they would get the plugin's "Request Confirmed!" welcome instead of the PDF's caption. docs/07 records that setting
  on in Uganda since 25 Sep; that is not re-measured here.
- An existing customer's kit-only additional service still tells the agent "Bidal has been notified to schedule the new
  install." Bidal's job says "Kit only, no installation" (§E.2); the agent's line is not changed.

### P.3 Tests (§L.2 corrected)

- `test_notify_kyc_race` was **rewritten** (its Uganda post now says who installs), not run unchanged.
- `test_kyc_crm_create` **runs unchanged and passes**: its books carry no Uganda tenant profile, so the gate is off there
  (`uganda` is the fake's scenario name). `test_notify_quote_once` runs unchanged and passes.
- `test_quote_company`, rewritten on purpose: G's Uganda control (an admin past the admin gate now meets D12's 409);
  I1 (postQuote has four callers); I4 narrowed — the nine files of 5.18.99's that 5.18.102 changes must still carry each
  of 5.18.99's hunks verbatim, with two declared amendments in `KycCrmSync.php` (the retry's company check and "Prepared
  by", afterCreate's new parameter), and a control that the check finds those hunks missing from `ce7ac388`; I6's PHP 7.4
  rules read PHP only (a JavaScript object literal in the form is not a named argument); the S journey extended as §L.2
  says, with one normaliser entry (the run's own plugin path, which Settings prints). `test_quote_company_mutants`: W19
  re-anchored (retry_quote now has two gated blocks); every other anchor still occurs once.
- **Three of 5.18.99's weakened copies became equivalent, and were re-pointed.** W11, W12 and W57 weakened only
  postQuote's own company check. On Uganda 5.18.102 runs that check, and records its refusal, before postQuote —
  `KycService::ugQuoteNew` for a new customer (Q2), `KycCrmSync::ugQuote` for the retry (Q3) — and then tells postQuote
  the organization, so postQuote's check never refuses. The full run measured all three surviving (76 passed, 3 failed).
  Each now weakens the check where it refuses; W11b is added for the new customer's check alone. The retry keeps two
  guards on purpose (ugQuote, and postQuote's check behind it), so a copy removing only one of them there would survive:
  W11 removes both.
- `test_bank_boundaries` A, rewritten on purpose: `includes/post/post_sync.php` leaves its list of files kept as 5.18.97
  left them (5.18.102 refuses the save-time product push on Uganda, D12), as `tools/set_config.php` left it in 5.18.99;
  `test_quote_from_ucrm` I and G hold it instead, and the bank test still asserts that the admin backup names no bank
  folder.
- §L.1's "a handleExisting Cash application retried" is an existing customer's Cash application saved before 5.18.102
  and retried through `retry_quote` (`test_quote_from_ucrm` C8: 409, no quote, the reason recorded).

### P.4 The build's review

An independent review of the built code against `f2055baf` — five lenses (money, the uCRM contract, the gate and South
Sudan, security and logs, the agent's and staff's flows), each followed by an adversarial verifier — confirmed three
defects and found a fourth plausible. Each is fixed, with a test that fails without the fix:
- **M1/U1 — a retried existing customer's sale was under-priced.** handleExisting stores no `hw_cart_json` or `kitQty`,
  so an existing customer's sale that uCRM could not price at submission was resolved on `retry_quote` as the device row
  × 1: the cart's other items and the kit quantity were lost, and the read-back, comparing with the same wrong lines,
  could not see it. An unpriced Uganda application now keeps the sale it was resolved from (`quote_sale_cart`,
  `quote_sale_kit_qty`), and the retry resolves that (`test_quote_from_ucrm` C9; copies MC9, MC10).
- **G1/X1 — the staff messages never said who installs.** `post_kyc.php` and the mobile API hand the staff messages the
  handler's answer, not the stored application, and the answer did not carry the "Who installs?" answer: no install line
  at registration, and a paid kit-only sale told the agent "Next: Schedule installation". The answer's data now carries
  `install_by` where the gate is on and the sale asked (B11; MB11).
- **X2 — "prices changed, reload the form" could reload the old prices.** The page cache (up to 60 s) is now dropped by
  that refusal, so the reload reads uCRM (B12; MB12).
- **X4 (plausible) — a read-back that failed was shown as a mismatch**, and lines that differ at the same total read
  "totals X, not the X sent". The Orders card now says which of the three happened (G6; MG20, MG21).

Refuted after verification: the page cache shared between the KYC form's and Send Quotation's credentials (no request or
money uses the cache), and the outage text for a refused Cash sale (it names its cause and the remedy).

### P.5 The release suite's first pass, and the re-cut

The first release-suite pass on `00d2be1` gave 299 files: 17,129 passed and 1 failed. The failure was `test_timezone`'s
"no executable line pins a timezone identifier", raised by `tests/quote_from_ucrm/F_send_quote.php:51`.

That section file named `Africa/Kampala` for the journey fixture's book. `test_timezone` exempts only files whose name
starts with `test_`, and the fixture's own rule says the same: a test file may pin a zone, a fixture may not. So the zone
is now named once, in `tests/test_quote_from_ucrm.php` (`QFU_TZ`), and the section uses the constant.

The release was re-cut as `9e66565`, still on `f2055baf`; it differs from `00d2be1` by these two test files only. On the
new tree `test_timezone` passes (31, 0 failed), and so do sections F and I (62, 0 failed). The suite was stopped in its
second pass and started again, twice, on `9e66565`.

## O. The review

The first design (`60bf7ed6`) had four independent reviews: money, uCRM, the agent and the release. They upheld 53
findings after adversarial verification and rejected six (M3, M5, M10–M13: each either pre-existing and out of scope,
already in the design, or resting on a misreading of the code). Every upheld finding is applied:

| Finding | Where |
|---|---|
| M1, UQ-3, UX-01, G7 — an unpriced Credit sale meets two old refusals | §D.5, §H |
| M2, UQ-2, UX-10, G4 — retries quote a total other than the cash collected | §F.3, §F.4, §L.1 |
| M4, UX-11, UQ-10, G9 — the posted total, its format, rounding and place | §G, §F.2, §E.3, §M |
| M6 — the resolver runs once per submission | §D.1 |
| M7 — every line above 0 | §D.4, §H |
| M8, UQ-6, UX-17, G11 — the plan price is the period-1 price, not `planPrice` | §D.4 |
| M9 — a refusal leaves a `duplicate_confirmations` row | §F.2 |
| UQ-1, UX-09 — uCRM's handling of `productId`, tax and invoice labels | §D.4, §F.6, §N, §M |
| UQ-4, UX-14 — the salesPerson PATCH on the retry and when no quote follows | §I |
| UQ-5 — what a read is, which rows count, duplicates, stored ids | §D.1–§D.3 |
| UQ-7, G2 — the pinned tests and the fakes | §L.2 |
| UQ-8, G3 — the order of checks and writes | §F.2 |
| UQ-9, G5 — the catalogue preflight | §L.3, §M |
| UQ-11, UX-12 — timeouts when drawing a page | §D.1 |
| UQ-12, UX-06 — the lead pre-fill, placeholders and 0 lines | §J |
| UQ-13 — the installation quantity | D8, §D.4 |
| UQ-14 — Fiber on Uganda | §D.4 |
| UQ-15 — reasons through `QuoteOrganization::plain` | §D.5 |
| UX-02 — the installation question in the wizard | §E.1, §L.1 |
| UX-03 — Cash disabled when unpriced; the refusal text | §G, §H, §F.5 |
| UX-04 — the retailer app and the mobile API | D10, §E.3, §M |
| UX-05 — the Delivery PDF | D9, §K.2 |
| UX-07 — lead notes in the lead WhatsApp | §J |
| UX-08 — the fallback PDF's cap, tax line and header | §K.1 |
| UX-13 — who sees the installation answer | §E.2 |
| UX-15 — the existing customer's installation site; who reaches that path | §F.1, §E.3 |
| UX-16 — a quote suppressed by the limit | §F.5, §H |
| G1 — the gate | D7, §C |
| G6 — declared files, the boundary, kept files, `kyc_install_product_id` | §L.1, §L.2, §L.4, D13 |
| G8 — a weakened copy for every rule | §L.1, §F.7 |
| G10 — South Sudan byte for byte on the branches §J changes | §L.2, §F.1 |
| G12 — the product push | D12, §K.3 |
| G13 — LTE | §C, §F.1 |
| G14 — 5.18.101's deployment record | Status, §M |

## Q. The deploy script: decisions taken for it

`scripts/deploy-5.18.102.sh` is derived from 5.18.101's by asserted edits, as every release's script is. A read-only map of
5.18.101's script and its rehearsal, with a completeness critic, found these questions. §L.3 leaves them open; each is
settled here by precedent or on the side of caution.

- **D-R1 — the added files on a rollback: they stay.** A rollback puts 5.18.101's files back and leaves the 21 files
  5.18.102 adds on disk, as every earlier release has done (`deploy-hybrid.sh` never deletes; 5.18.99 left
  `lib/QuoteOrganization.php`). The rollback then proves that nothing in 5.18.101 reaches them, file by file. (amended by §R.11: the file count follows the two added files)
- **D-R2 — the rollback hazard: a refusal, with a stated way past it.** On Uganda, 5.18.102 saves an application it
  cannot price with no lines and no plan price. 5.18.101 would rebuild that plan line at price 0. If such an application
  is still waiting for uCRM, its cron retry would make that quotation, and uCRM's quote.add webhook would WhatsApp it to
  the customer.
  - Stage A of `--rollback` counts these applications, read-only, on a copy.
  - It refuses while any of them is still pending, unless `--rollback-unpriced-pending` is typed. That is the operator's
    explicit choice, and the log says so.
  - Two further counts are notes: such applications awaiting a person's retry, and refusals P1–P3 with a uCRM client.
- **D-R3 — the catalogue before and after.**
  - **Within a deploy run:** the reading after the copy must equal stage A's. The two are compared as a keyed hash
    (HMAC) under the run's own key, which is never printed and never stored. (amended by §R.7.2: compared item by item, with stage A's key kept in the state file; how, against this line, is settled at §R.7.7 item 3)
  - **In a later `--after-only`:** a difference from stage A's counts is a note that names its direction. The operator
    may have fixed uCRM as §M step 3 asks.
  - **Failures, in either run:** an unreadable catalogue, a reason the script cannot classify, and any request that is
    not a GET.
- **D-R4 — what counts toward the preflight's NO-GO.**
  - **What is checked:** the active plans and kits the KYC form offers, and the installation product. They are read
    through the plugin's client and, when the admin token is set, through the token client.
  - **Refusals:** an unpriced row is a NO-GO unless `--accept-unpriced` is typed. An unreadable catalogue and an
    unclassified reason are NO-GOs that no flag lifts.
  - **Notes only:** Fiber rows, which are unpriced by design on Uganda (§D.4) and counted apart; the debug retry's own
    client; and the `kyc_packages` rows that the retailer app and the mobile API price. (amended by §R.2.5: those rows are never priced; §R.7.7 item 8)
- **D-R5 — privacy.** The log carries only counts, read states, HTTP codes and uCRM attribute ids. Row names, product ids,
  units and the resolver's reason texts go only to the run's own sheet (mode 0600), because a reason can carry a price.
- **D-R6 — the kept files.** The script pins 55 files: 5.18.101's 63 kept files minus the 14 that 5.18.102 changes;
  `webhook.php` and `tests/test_sales_numbers.php`; and four files 5.18.102 reads or §L.4 names (`lib/PublicPriceFeed.php`,
  `lib/EfrisClientField.php`, `includes/api/api_retailer.php`, `retailer/index.html`). (amended by §R.11: the file count follows the two added files)
- **D-R7 to D-R15 — mechanics.**
  - The copy's time is read off `lib/KycService.php`.
  - The preflight builds its own GET-only clients with `withTimeouts(5, 30)`. It never calls `quoteClient()` or
    `forPage()`.
  - Quotation 000314 is read back by its stored quote id. The log shows each item's type, whether it has a product id,
    and its unit: no label and no price.
  - The other notes of §L.3:
    - the `salesPerson` attribute;
    - line shapes by sale type;
    - an entry point, which the record does not carry, so the note says "not measurable"; (amended by §R.4.2: still true, and now decides nothing)
    - the last plan save and kit creation, as dates only (the plugin records no push);
    - the settings the customer's notes and P3 depend on, as set or unset only.
  - The check ids are QL-G1, QL-N1–QL-N7, QL-CA1, QL-CA2, QL-RB1 and QL-RB2. 5.18.101's QS checks are carried; with
    `webhook.php` kept, their before, after and rollback readings are all 5.18.101's line.
  - The event-processor, persona and T4 signatures are measured again on the release by the rehearsal before they are
    pinned.
  - The release's hash is filled into the script only by a separate re-pin step, which checks the release's 59 sha256
    values against Git. (amended by §R.11: the count follows the two added files)

## R. Items linked to uCRM by an admin, never matched by spelling (D14, the operator's decision of 28 Oct)

Status: design, reviewed (one adversarial review, every finding verified; four checks); not built.

The review's findings are folded in by §R.13, and the checks' by §R.14, §R.15 and §R.16: the lead engineer's decisions
D-1 to D-15, E-1 to E-6 and F-1 to F-14, and every checker item. The deploy-script mechanics the checks left are
recorded for the script's own review (§R.7.7). It goes into 5.18.102, which is re-cut on `f2055baf`, retested and
rehearsed before it is deployed (the operator's choice). The release as built (`9e665655`) is not deployed and is not
pushed.

The work item is called "D13". Here the decision is numbered **D14**, because §B already has a D13: the dropped
`kyc_install_product_id` (docs/71:54). §R.2.4 supersedes that row's last clause, *"the installation product is found by
its name alone"*.

Citations are `path:line` at `9e665655`, with paths under `dishnet-hybrid-sudan/`. Citations into the deploy script are
`scripts/deploy-5.18.102.sh:line` at `661bd5c7`, in the repository root.

### R.0 The decision, and what it replaces

**The operator's words (28 Oct):** *"use name of ucrm sync it with plugin"*. Asked what they meant, they chose:

> "Pick the product per item — On the Plans and Hardware screens (Uganda), an admin picks the uCRM Product for each plan
> and kit from a list. The plugin then shows that product's name and price and matches by that link, never by spelling.
> Nothing is guessed."

They also chose to put it "into 5.18.102 (re-cut, retested and rehearsed before it is deployed)".

**What it replaces.** As built, `lib/UcrmQuoteLines.php` matches each item to a uCRM product by name key:
- a plan row: `planKey(name)` → exactly one product → exactly one service plan with the same key (`:220-232`);
- a kit row: `planKey(title)` → exactly one product (`:267-274`);
- the installation: the fixed name `INSTALL_NAME` (`:50`, `:290`).

Two products with one key make an item unpriced (`:227`, `:274`), and a stored `ucrm_product_id` is never read
(`:25-27`).

Under D14, **the link is the only thing that joins a plugin item to a uCRM product.** An item without a valid link is
unpriced. There is no fallback to a name, and none to `ucrm_product_id`.

The rules that compare uCRM with uCRM stay, because the quote and the WhatsApp summary still depend on them (§R.3.1).
They are judged against the summary's own unfiltered list of service plans, so "exactly one service plan" is kept
(§R.3.1 rules 6–7).

### R.1 Where links are stored (decision 1)

**Decision: a separate record collection, `ucrm_quote_links.json`, in the plugin's store.** It is the table
`ucrm_quote_links` inside `<dataDir>/plugin.sqlite3`: the table name is the file name less `.json`
(`lib/SqliteStore.php:1100-1104`), and each row is `id, data` (`:25-29`).

It is not a field on the plugin's rows, and not a real file in the data folder. It is created lazily, with no migration
(`ensureTable`, `:1106-1130`).

#### R.1.1 The record

One record per act of linking:

| Key | Holds | Used for |
|---|---|---|
| `id` | the record's own id, given inside the lock | the stored lines' reference (§R.4.4) |
| `item` | `plan` (a `subscription_plans` row), `kit` (a `kyc_devices` row) or `install` (no row) | the lookup key, with `row_id` |
| `row_id` | the row's id; 0 for `install` | the lookup key |
| `row_stamp` | the row's `created_at`, else its `imported_at`, else `''`, as read at link time by `UcrmQuoteLinks::rowStamp`, the one implementation (§R.1.5) | detects a reused id (§R.1.3) |
| `product_id` | uCRM's product id | **the match** |
| `service_plan_id` | uCRM's service plan id (plans only, else null) | the 1-month price and the summary rule (§R.3.1) |
| `organization_id` | the quoting organization in force when the link was made (`QuoteOrganization::setting()['id']`) | a link made under another organization is not used |
| `row_name`, `product_name`, `service_plan_name` | the names at link time | **display and history only; never matched, never priced** |
| `linked_by_id`, `linked_by_name`, `linked_at` | the signed-in admin, taken from the account record re-read by id, never the session copy (§R.2.1), and when (`date('Y-m-d H:i:s')`, as `post_sync.php:429` stamps) | provenance. `linked_at` is for the screens only: it is a zone-less local time, so nothing compares or orders it |
| `ended_at`, `ended_by_id`, `ended_why` | null while active; else when it ended, by whom (the fresh record, as above), and why: `relinked`, `unlinked` or `row deleted` | history. `ended_at`, like `linked_at`, is for the screens only: never compared or ordered as a time, and never read to tell whether a record is active |
| `linked_at_ts`, `ended_at_ts` | integers from `time()`; `ended_at_ts` null while the link is active. **`ended_at_ts` null is the active marker**, and `ended_at` and `ended_at_ts` are written together, inside the lock (§R.1.4) | the active marker, and every comparison of time (§R.7.3). They have the epoch base of the deploy's `COPY_AT` (`scripts/deploy-5.18.102.sh:6780-6781`) and are the same in any zone, whereas `linked_at` is local time in the plugin's zone (`lib/timezone.php:91-95`) |

- **The active link** of an item is its one record with `ended_at_ts` null; `ended_at` is never read for it. Two or
  more active records for one item are never written (§R.1.4). If they are found, they are treated as no link at all
  (fail closed). The screens, the KYC form, the Orders card and the deploy's sheet all give §R.4.1's one text for that
  state, *"<row> has two uCRM links recorded — an admin links it again"* (E-4). The link column also shows both
  records' product ids, with Change and Unlink (§R.2.2, F-11). A save ends every one of them and writes one fresh
  record (§R.1.4), so that remedy always repairs the item.
- **Nothing reads `ucrm_product_id` for this.**
  - It is never copied into the store.
  - It never pre-selects the picker.
  - It never resolves an item.
  - The deploy never seeds links from it.

  The fuzzy auto-map may have set it wrongly (§D.3), and the gate-off paths send it as `productId` (§R.1.2).

#### R.1.2 Why not a field on the plugin's rows

A field on the row would follow renames and deletions by itself. Saves would also keep it:
- `save_plan` does `array_merge($p, $plan)` (`includes/post/post_sync.php:439`);
- `save_hardware` does the same (`:573`);
- the bulk writers edit rows in place.

Five things decide against it anyway:

1. **The rows are sent raw to every agent's app.**
   - The retailer API's `devices` and `packages` return the store's rows unfiltered (`includes/api/api_retailer.php:7-8`).
   - So does the mobile API (`api/index.php:197-219`).

   A link field would carry product ids and the linking admin's name to every phone running the app. The same would
   happen on Uganda with the gate off and after a rollback, because neither path filters fields.
2. **Reusing `ucrm_product_id` is ruled out.**
   - 5.18.101's paths send it to uCRM as `productId`: `KycService.php:2003-2004`, `:2021-2022`, `:2037-2038`, and
     `KycCrmSync.php:403`, `:409`.
   - Those paths run on South Sudan, on Uganda with the gate off, and after a rollback.
   - A link written there would change what 5.18.101's code quotes.
   - That field also has writers 5.18.102 does not stop on Uganda: the auto-map (`api_products_admin.php:400`, writes
     `:472-473`, `:495-496`, `:518-519`), `set_product_mapping` (`:544`, `:569-573`), and the Hardware form's manual field
     (`post_sync.php:563-581`).
3. **A rollback must leave no trace that 5.18.101 can read.** 5.18.101 never opens a table it does not know, so a
   separate collection is ignored completely (§R.7.4). A row field is echoed (point 1).
4. **History.** A row field holds only the current link. The record collection keeps who changed which link and when,
   for the same cost.
5. **Backups and restores stay consistent.**
   - The admin backup, the auto-backup and Google Drive all take `plugin.sqlite3` whole (`post_sync.php:928-945`,
     `main.php:186-207`, `lib/GoogleDriveBackup.php:691-706`).
   - An overwrite restore brings it back whole (`post_sync.php:1118-1148`).

   So the rows and their links travel together. A real `.json` file in the data folder would be in the backup glob but
   on no restore list (`post_sync.php:1079`, `:1093-1115`), and could come back out of step with the rows.

#### R.1.3 Renames, deletions and reused ids

- **A rename changes nothing.** Links are keyed by `item` and `row_id`. Saved applications also refer to rows by id
  only: `package_choice`, `device_id` and the cart's ids (`KycService.php:1660-1693`; `UcrmQuoteLines.php:318-323`,
  `:348`).
- **A deletion on Uganda with the gate on ends every active record of the row** (`ended_why = row deleted`), in the same
  request, inside `delete_plan` and `delete_hardware` (`post_sync.php:652-659`, `:634-641`), behind the gate.
- **A reused id is caught by `row_stamp`.**
  - `nextId` is MAX(id)+1 (`SqliteStore.php:699-709`), so deleting the top row and adding one reuses its id.
  - Every new row carries a stamp: `created_at` from the screens (`post_sync.php:446`, `:584`), `imported_at` from the
    import tool (`tools/import-from-ucrm.php:114-127`, `:154-166`).
  - At use, a link whose `row_stamp` differs from the row's current stamp is not used. Its reason says the row was
    replaced (§R.4.1).
  - The picker pre-selects nothing for such a row (§R.2.3), and relinking it, even to the same product, writes a fresh
    record (§R.1.4).
  - This covers every path the delete hook does not: a delete with the gate off, the import's undo
    (`tools/import-from-ucrm.php:63-71`), and a hand edit.
- **A row whose type becomes Fiber keeps its link, unused.** A Fiber row is unpriced (§R.4.3).

#### R.1.4 Writing

- Every write goes through `$store->withLock('ucrm_quote_links.json', …)` (`SqliteStore.php:800`), which reads and
  writes in one transaction. A link and the end of every active record of the item are therefore one write, and two
  admins cannot leave two active records.
- **The order of a save. The uCRM read is never inside the lock.**
  1. **Outside any transaction:** §R.3.1 rule 1 (the admin on the fresh record, then the own-page check). Then the
     quoting-organization setting, which must be valid (5.18.99 R6; amended by §R.17, G2, and §R.17.1, FX-money-2).
     Otherwise the save answers *"The company the app quotes under is not set correctly, so nothing was linked. Tell
     an admin."*, with no uCRM read and no lock. A non-admin is still answered 403, and a cross-site post the security
     flash, never this text. Then rule 2, which reads the row and keeps its id and `row_stamp`; rule 3, the fresh read
     through `quoteClient`; rules 4–8. Any failure refuses with its reason and writes nothing.
  2. **Then `UcrmQuoteLinks::set()` calls `withLock('ucrm_quote_links.json', …)`.** The callback does no network I/O and
     calls no uCRM client. It makes only store checks:
     - the row still exists in its own table with the same id and `row_stamp`, and is not Fiber. Otherwise it refuses
       with *"the plan/kit changed while it was being linked — nothing was linked; try again"*;
     - rule 8 again, over the records the lock read and the kit rows they name, each kit row read inside the same
       transaction by `findOne(…, 'id', …)` (`SqliteStore.php:535-553`), never by `load()`, which can return the
       request's cached copy of `kyc_devices.json` or `subscription_plans.json` (`:80-81`, `:346-348`). The re-check
       builds `UcrmQuoteLinks::fromRecords()` over the lock's records and those `findOne` rows, and asks the same
       rule-8 method (E-5, §R.1.5), handing it the quoting organization's id from the save's setting, read inside the
       callback as the no-op check below reads it (F-4). The row check above reads its row the same way. So two admins
       cannot pass rule 8 crosswise;
     - it collects every active record of (`item`, `row_id`).
  3. **The write, inside the same callback:** the two bullets below.
  4. **A failure of the lock itself.** The POST handler catches any Throwable from `withLock`, which has already rolled
     back (`SqliteStore.php:894-897`), PDOException "database is locked" (SQLITE_BUSY / BUSY_SNAPSHOT) included. It
     answers *"nothing was linked — try again"*, logs ids only, and writes nothing. Unlink's handler does the same, and
     answers *"nothing was unlinked — try again"*.

  **Why the read is outside.** `withLock` opens a deferred transaction and takes its read snapshot *before* it calls the
  callback (`SqliteStore.php:806`, `:809-811`, then `$fn` at `:831`). The store runs WAL with `busy_timeout = 5000`
  (`:158-159`), every table in one file (`:145`), and a client request may take 30 s (`lib/CrmApiClient.php:463`). A
  write that another connection commits during a uCRM read inside the callback makes the link's INSERT fail with
  "database is locked", and the busy timeout does not help. The review measured this with the real `SqliteStore`.
  Webhooks, crons and KYC submissions write to that file all the time.
- **A save is a no-op only when the active record would itself resolve now.** All of these hold, checked inside the
  callback:
  - exactly one record of that (`item`, `row_id`) has `ended_at_ts` null;
  - its `product_id` and `service_plan_id` equal the posted ones;
  - its `row_stamp` equals the row's current stamp, read in this request;
  - its `organization_id` equals `QuoteOrganization::setting(...)['id']` now.

  Then no record is written and none is ended, and the flash says "already linked".
- **Otherwise a save ends every active record of the item** (`ended_why = relinked`, with `ended_by_id`, `ended_at`
  and `ended_at_ts`), then appends one fresh record carrying the current stamp and organization. The flash names the
  row and the product (§R.2.3), and is never "already linked". A replaced row, a changed organization and two active
  records are therefore each repaired by linking again, to the same product or another.
- **Unlink and the delete hooks also end every active record of the item**, never only one.
- **The collection is a list of records, each carrying its own `id`.** `SqliteStore::load()` returns record tables with
  `array_values` (`:388-410`), and `save()` warns that a keyed object saved to a table outside `$FLAT_TABLES` loses its
  keys (`:451-468`). A list of records needs neither, so **`lib/SqliteStore.php` is not changed**.
  `test_bank_boundaries` A holds that file byte for byte to `ec0accd4`.

#### R.1.5 Reads, and the gate

**`SqliteStore::load()` creates any table it does not find** (`:351` → `:1106-1130`). So the link store is read only
behind `QuoteOrganization::setting(...)['on']`. Merely reading it where the gate is off would add a table to South
Sudan's store (§R.8).

One new class, **`lib/UcrmQuoteLinks.php`**, owns the collection. It:
- answers the active link for an item, checking `row_stamp`, and `organization_id` against the quoting organization's
  id that its caller hands the method (F-4, below);
- sets a link and ends every active record of the item, inside the lock (§R.1.4);
- returns records by id, for the retry (§R.4.4), and, through `records()`, every record whole, ended ones included, for
  the deploy's offer file (E-5, §R.7.1);
- judges rule 8 (§R.3.1): one method, over the link records and the kit rows it holds, which the save, `installLine`
  and `kitLine` at use, and the deploy's remote reading all call;
- decides which kit rows are offered with one static, **`UcrmQuoteLinks::offeredKit(array $row): bool`**:
  `!empty($row['is_active']) && strtolower((string)($row['type'] ?? 'general')) !== 'fiber'`. That is the KYC form's
  own test (`kyc_form.php:33-34`), and the form's kit loop calls this static instead of its own (F-5, §R.3.1 rule 8);
- counts the applications whose `quote_link_ids` contains a record id and that have a `quote_id`, split by
  `sales_type` into Cash and Credit (§R.4.4). It reads `kyc_applications` only, behind the gate.

**The judge is pure, and the store only feeds it.**
- `UcrmQuoteLinks::fromRecords(array $records, array $kitRows): self` holds the link records and the kit rows, and opens
  no store and no file. **The kit rows are in `kyc_devices`' stored shape** (E-5): an int `id`, `title`, `type`,
  `is_active`, `created_at` and `imported_at`, as the store holds them, never recast or renamed. Rule 8 matches a
  record's `row_id` against that `id`, so every caller hands the same shape.
- `UcrmQuoteLinks::fromStore($store, array $setting)` **takes the gate from its caller** (E-5): `$setting` is
  `QuoteOrganization::setting(...)` as the caller has already read it. With `$setting['on']` false it opens nothing and
  holds no record and no row. Otherwise it loads `ucrm_quote_links.json` and `kyc_devices`, and passes both to the same
  judge. The set and end writes stay on the store path, inside `withLock`.
- **Neither constructor keeps the quoting organization** (F-4). `fromStore` reads `$setting['on']` only, and
  `fromRecords` is given none. Every method that judges a record's `organization_id` — the active link, rule 8 — takes
  the quoting organization's id from its caller: the resolver hands it its own `$setting['id']` (§R.4), and the save
  its setting's, inside the lock (§R.1.4). So a judge built either way answers the same question the same way.
- **`lib/UcrmQuoteLinks.php` require()s no sibling file** (E-5). Its callers load `lib/SqliteStore.php` and
  `lib/QuoteOrganization.php` themselves. A `require_once __DIR__ . …` of the kind `UcrmQuoteLines.php:4-6` makes
  would, in the deploy's stage-A local reading, load a second copy of a class that process has already declared from
  another code root (`scripts/deploy-5.18.102.sh:3158`), and PHP would stop (§R.7.1, §R.7.7 item 2).
- **`lib/UcrmQuoteLines.php` loads it** (F-6): `require_once __DIR__ . '/UcrmQuoteLinks.php'`, beside its three
  (`UcrmQuoteLines.php:4-6`). So every caller that loads the resolver has the class: every construction site (§R.4),
  the screens and `post_sync.php`. A caller that needs only the judge (the deploy's local reading) requires
  `lib/UcrmQuoteLinks.php` itself, by that same path, so a later `require_once` of it from `UcrmQuoteLines.php`
  resolves the same file and declares nothing twice (§R.7.7 item 2).
- A static `UcrmQuoteLinks::rowStamp(array $row): string` (`created_at`, else `imported_at`, else `''`) is the one
  implementation of §R.1.1's stamp. The link writer and the judge both call it.
- The deploy's remote reading has no store, and builds the judge with `fromRecords()` from the offer file's link
  records and kit rows (§R.7.1). So the deploy never re-implements the selection by active state, stamp, organization
  and two active records, nor rule 8, and QL-CA1 compares the release's own answers.

Nothing else opens `ucrm_quote_links.json`, and `fromRecords()` opens nothing.

### R.2 The screens (decision 2)

#### R.2.1 Who may link

**Admins only: `$auth->requireAdmin()`** (`lib/RetailerAuth.php:190-198`, which checks `is_admin`), **then the account
re-read fresh by id** (below).

The reason is that this is exactly who may save those screens today:
- `save_plan`, `delete_plan`, `save_hardware`, `delete_hardware` and `save_install_fee` each call `requireAdmin`
  (`post_sync.php:419`, `:653`, `:550`, `:635`, `:644`).
- Opening a screen needs only the `plans` or `hardware` module (`public.php:2822-2823`), so a non-admin who has been
  granted it can open the screen and still cannot save.

A link now decides the price an agent collects in cash, so it needs at least the authority that saving a plan used to
have.

- **A non-admin who can open the screen sees each item's link and its state, read-only.** The Link, Change and Unlink
  controls are drawn only where `$isAdmin` (`public.php:2202`).
- **Two new POST actions in `includes/post/post_sync.php`:** `ucrm_link_save` and `ucrm_link_clear`. Both, in this
  order:
  - are handled only where the gate is on;
  - call `requireAdmin` themselves, then re-read the account (below). The media handlers show that the global CSRF
    gate is not a login check (`post_sync.php:506-547`; Map 2 §2);
  - **make the security check themselves, and never rely on the global CSRF gate.**
    - That gate (`includes/post_handlers.php:99-114`) is skipped for every `action` when the POST also carries a
      non-empty `cb_action` or any of nine tab-action fields (`:102-110`). `post_sync.php` is required before
      `post_cashbook.php` (`:125`, `:127`), whose handlers match fixed `cb_action` names only.
    - Its token is the date and an HMAC of it, the same for every user all day (`public.php:90-96`), and it is
      printed on pages that need no sign-in, such as the login form (`public.php:2074-2076`).
    - The staff cookie is SameSite=None (`public.php:57-63`).
    - So each action does what `includes/post/post_bank.php:45-49` does. After the gate, `requireAdmin` and the fresh
      record, and before any uCRM read, store lock, write or log line, it loads `lib/BankGate.php` and
      `lib/CustomerSession.php` unchanged (as `post_bank.php:33`, `:45`). It refuses unless
      `csrfCheck() && !CustomerSession::crossSite() && BankGate::fromOwnPage('POST')`.
    - **The return value of `csrfCheck()` is used.** `post_sync.php:43`, `:118` and `:507` call it and discard the
      result; that is not copied.
    - A refusal shows one fixed flash with nothing from the request in it — *"The security check failed, so nothing
      was changed. Open the screen again and retry."* — and redirects to a fixed screen address that carries no POST
      values.
    - Nothing shared changes: the global gate, `csrfToken`/`csrfCheck`, the cookie parameters, `BankGate` and
      `CustomerSession` stay as they are.
  - print `csrfField()` *inside* each form. On the Hardware screen, today's delete forms print it after `</form>`
    (`tabs/sales/hardware.php:180-181`).

  `save_plan` and `save_hardware` stay as built. A plan's or kit's own save never carries a link, so editing a name or
  a cost cannot touch one.
- **Known, and outside this design:** a forged `delete_plan` or `delete_hardware` can be sent past the global gate the
  same way. Under §R.1.3 it also ends that row's link. That adds no harm beyond the deletion it already causes, which
  is a problem that exists today.

**The admin is judged on the fresh account record, never the session copy.**
- `requireAdmin` reads `currentRetailer()`, which returns the session's cached record while it is under 300 s old
  (`lib/RetailerAuth.php:132`, `:145-148`), and with no age limit when the database read throws or returns null
  (`:152-157`, `:171-175`). A demoted, deactivated or deleted admin would keep link power meanwhile.
- So in both actions `$me = $auth->requireAdmin()` comes first: it still gives the sign-in redirect and the 403. Then,
  before any uCRM read or store write, the account is re-read with
  `$store->findOne('retailers.json', 'id', (int)$me['id'])`, inside try/catch. The action refuses with 403 and writes
  nothing when any of these holds:
  - the read throws;
  - it returns null or a non-array;
  - the row's id differs from the session's;
  - `empty($fresh['is_active'])`;
  - `empty($fresh['is_admin'])`.
- This is `BankGate::allows`'s expression (`lib/BankGate.php:86-96`), the precedent of `post_bank.php:8-10` and
  `:39-43`. **It is written in the action itself, in `includes/post/post_sync.php`, before any `UcrmQuoteLinks`
  call**, so QL-CA3's text read can require that order (§R.7.6). `BankGate.php` and `RetailerAuth.php` are not edited:
  `currentRetailer` is shared with South Sudan.
- `linked_by_id`, `linked_by_name` and `ended_by_id` are taken from that fresh record.

#### R.2.2 What the Plans and Hardware screens show (Uganda, gate on)

**The "UCRM" column** (`tabs/sales/subscription_plans.php:212-218`; `tabs/sales/hardware.php:118-124`, `:242-248`,
`:276-282`) reads `ucrm_product_id` and offers a per-row Sync. On Uganda with the gate on, it becomes a **"uCRM link"**
column:

| State | Shown |
|---|---|
| linked, and it resolves now | the plugin row's own name stays in its column. Beside it: uCRM's product id, as the `#id 🔗` link to its uCRM page that today's column draws (`subscription_plans.php:213-214`, `hardware.php:119-120`), and the product's name and price; for a plan also the service plan's id, name and 1-month price and "/month"; ✓ "quotes"; then "linked by <name>, <date>" |
| linked, and it does not resolve (any reason but two active records) | uCRM's product id, as the same `#id 🔗` link, and for a plan also the service plan's id; the names as recorded at link time, in red; the reason (§R.4.1); and Change or Unlink |
| two active records (§R.1.1) | both records' uCRM product ids, each as its `#id 🔗` link; §R.4.1's one text for that state, *"<row> has two uCRM links recorded — an admin links it again"*; and Change and Unlink (F-11) |
| not linked | amber "not linked — Cash sales of it are refused", and Link |
| Fiber row | grey "Fiber: not priced from uCRM on this installation", and no Link (§R.4.3) |

- The state is the resolver's own answer for that row: `planLine`/`kitLine` through `forPage`, with links read live from
  the store. So the screen and the form cannot disagree.
- **Every uCRM-sourced or stored string drawn on the Plans and Hardware screens goes through `h()`, attribute values
  included.** That covers the link column, the picker's product and service-plan names, the disabled reasons, "also
  linked to:", the stored `row_name`, `product_name`, `service_plan_name` and `linked_by_name`, the history, and the
  red "as recorded at link time" name. The existing screens already draw every name through `h()`, which uses
  ENT_QUOTES (`public.php:615`). The flash and a name inside a JS string are handled apart (§R.2.3): the layout prints
  the flash raw (`public.php:2508`), and the browser decodes an `on*` attribute before its JS runs.
- **The badge counts change:**
  - "⚠️ N not synced to UCRM" (`subscription_plans.php:143-154`, `hardware.php:95-101`) becomes "N active item(s) not
    linked";
  - the per-row Sync buttons are not drawn;
  - "🔄 Sync All to UCRM" is not drawn (`subscription_plans.php:155-157`, `hardware.php:102`).

  The server already refuses all of these on Uganda (D12, §K.3). Next to a link picker they would only invite the wrong
  click.
- **The Hardware form's "UCRM Product ID" field** (`hardware.php:77-80`) is not drawn on Uganda with the gate on. There,
  `save_hardware` ignores `hw_ucrm_product_id`, and a stored value is kept untouched.
  - Today that field writes `ucrm_product_id` on every tenant (`post_sync.php:563-581`).
  - Quoting never reads it.
  - A value typed there would become `productId` on Uganda with the gate off, or after a rollback (§R.1.2).
  - Operator question Q8.
- **South Sudan, and Uganda with the gate off: the screens are 5.18.101's** (§R.8).

#### R.2.3 The picker

Clicking **Link** or **Change** opens one item's panel. It is drawn by the server: `&ucrm_link_plan=<id>` on Plans,
`&ucrm_link_hw=<id>` or `&ucrm_link_install=1` on Hardware.

- **The read.**
  - It is a fresh `catalogue()` through `UcrmQuoteLines::quoteClient($crm, $config)` (`UcrmQuoteLines.php:115-121`). That
    is the plugin's own client, or the `crm_auth_token` client when one is set, i.e. the client the quotes resolve with,
    so a link that validates here also reads at use.
  - It uses the page timeouts, 3 s and 5 s (`:54-56`, through `withTimeouts`), and never the 60 s page cache.
  - If it fails, the panel says *"uCRM's products could not be read — nothing can be linked now; try again"*, and shows
    no list.
- **The lists** show only rows that count (§D.2: active, not archived, the quoting organization), sorted by name.
  - **For a plan:** one list of Products and one of Service Plans.
    - Each product shows its uCRM id with the `#id 🔗` link to its uCRM page
      (`dn_crm_web($config)/crm/system/items/products/<id>`, as `subscription_plans.php:213-214` and
      `hardware.php:119-120` draw it), and its name, price and unit.
    - Each service plan shows its uCRM id, its name and its one enabled 1-month price.
  - **For a kit or the installation:** one list of Products.
  - **Items that could not be saved are listed but disabled**, each with its reason (§R.3.1):
    - price 0, more than 2 decimals, taxable, invoice label differs, no 1-month price;
    - a service plan spelled like another listed service plan, active or not; a service plan whose own price is not
      its 1-month price; and every service plan when uCRM lists 200 or more;
    - in the kit and installation lists, products spelled like any listed service plan, counted or not;
    - in the installation's list, the product of an offered kit's active link; in a kit's list, the installation's
      product (rule 8).

    The admin sees why an item is missing, not just that it is.
  - The ids on screen are the ids the 0600 sheet lists (§R.7.1). A pick decided on paper can be carried to the picker,
    and two products with one name, price and unit can be told apart.
- **Nothing is pre-selected**, except the item's own current link when it has one, and only when it is the single
  active record and carries the row's current stamp and the current organization. That is the admin's earlier choice,
  not a guess. Nothing is pre-selected when two records are active, and nothing for a replaced row: that record
  belongs to the row that held the id before, so pre-selecting it would be a guess.
- **Suggestions by spelling.**
  - Products and service plans spelled like the row (`planKey`, as today) are marked "spelled like this plan" and listed
    first. **They are not selected.** "Two products in uCRM are spelled like this" is shown the same way, as a hint.
  - **Twins are also marked by the product's own name key, not the row's.** Where a listed product's `planKey(name)`
    is shared by another counted product, both entries are marked "another product in uCRM is spelled the same (#N)".
    This holds whether or not the plugin row is spelled like them: the hint keyed on the row misses exactly the
    products D14 allows to be linked. The mark never disables an entry and never selects one.
  - **Service plans are marked the same way, by their own key.** Where a listed service plan's key is shared by another
    listed service plan, active or not, both are marked "another service plan in uCRM is spelled the same (#N)". That
    mark, too, never disables an entry and never selects one. Such a service plan is disabled by rule 6(a), a separate
    check with its own reason.
  - **Recommendation: never pre-select.**
  - **The risk of pre-selecting.** An admin linking several items confirms a guess without reading it. That is the fuzzy
    auto-map's failure, and the operator ruled it out ("Nothing is guessed"). A wrong pre-selected product quotes, and
    collects cash, at that product's price.
  - **The cost of not pre-selecting:** one more choice per item. The hint, the shown price and the save validation keep
    that choice informed.
  - Operator question Q5.
- **Save** posts `ucrm_link_save` with `item`, `row_id`, `product_id` and, for a plan, `service_plan_id`. The server
  validates (§R.3.1) and writes, or refuses with the reason and writes nothing.
  - The flash names the plugin row and the uCRM product, with its id.
  - **Every flash holds fixed text plus ids, or fixed text plus each name passed through
    `QuoteOrganization::plain()`.** That covers the success flash, "already linked" and every refusal.
    - The dashboard prints the flash raw (`public.php:2508`), as `post_bank.php:18-22` records.
    - uCRM's names are typed by uCRM users, who need not be plugin admins.
    - This is the `docs/71` §D.5 rule, extended from reasons to every flash. No raw uCRM or plugin name is ever
      concatenated into `flash()`.
  - **A relink that ends a record** adds: "N application(s) were quoted under the previous link (C Cash, K Credit).
    Review them in uCRM." It links to the Orders list of those applications (§R.4.4). The count is of KYC
    applications only: Send Quotation records no link ids (§R.4.4).
  - The log line carries ids only (§F.7), and the counts.
- **Unlink** posts `ucrm_link_clear` after a confirmation: *"Unlink <row>? Cash sales of it will be refused until it is
  linked again."* It ends every active record of the item (`unlinked`), and its flash adds the same count.
  - `<row>` is never put into an `on*` attribute with `h()` alone. The browser decodes `&#039;` back to `'` before the
    JS runs, as in today's `onsubmit="return confirm('Delete?')"` (`hardware.php:180`).
  - Either it is carried in a `data-` attribute and read with `dataset` or `textContent`, or it is passed through
    `json_encode` with `JSON_HEX_TAG`, `JSON_HEX_APOS`, `JSON_HEX_QUOT` and `JSON_HEX_AMP` and then `h()`.
- **The item's history** is listed on its panel: each ended record's product name at link time, linked by, from and
  to, and why. Each shows the count of applications quoted under it (Cash, Credit) and the same link to the Orders
  list.

#### R.2.4 Where the installation product is chosen

**Decision: on the Hardware screen**, in an "Installation (when DishNet installs)" card drawn only on Uganda with the
gate on. It goes above the Fiber Installation Fee card (`hardware.php:190-224`), which is South Sudan's and stays as
built.

It uses the same picker with one product list, and is stored as `item = install`, `row_id = 0`.

- The operator named plans and kits only. The installation line is the third item a Uganda quotation carries (D2, §E).
- Keeping it as the one item still found by spelling would contradict "Nothing is guessed".
- So it is linked like a kit. **The name `Professional Installation` is no longer matched.** `INSTALL_NAME`
  (`UcrmQuoteLines.php:50`) survives only as the form's display word for an unlinked line.
- This supersedes §B D13's *"found by its name alone"*. Operator question Q1.
- It may not share a product with a kit row the form offers (rule 8, §R.3.1). A kit row the import tool made for the
  installation product is deactivated, not linked (§R.6).

#### R.2.5 `kyc_packages`

**Decision: not linkable in this release**, so a `kyc_packages` row is always unpriced, with its own reason (§R.4.1).

- Those rows have no screen. Their only readers are the retailer app and the mobile API (`api_retailer.php:8`;
  `api/index.php:210-219`) and the resolver's fallback (`UcrmQuoteLines.php:320`).
- Through those channels a Cash sale is always refused. On the gated path the refusal is `CASH_UNPRICED` with the entry
  reason (§R.4.2; `KycService.php:1707-1710`), which comes before the want of `ucrm_total_seen` (`:1714`). For an id
  in neither table it is the `no_plan` message, as today (`KycService.php:557`; `handleExisting` at `:209-211`).
- Every sale from those channels is unpriced by the entry rule, which sits right after the lookups
  (`UcrmQuoteLines.php:318-327`), before the Fiber and installation checks (§R.4.2). Without it, a Starlink New
  Connection from them would still be unpriced for want of `install_by` (`:362-367`).
- Linking them would price only a narrow Credit case, and would need a screen of its own.
- The resolver must still never price a package through a *plan* link that has the same id. The item kind is part of
  the key (§R.4.2), **but it protects only the fallback.**
  - `resolve()` looks `package_choice` up in `subscription_plans` first (`UcrmQuoteLines.php:318-320`).
  - So an app id that is also a plan id has already picked the plan row, for which `item = plan` is the right kind.
  - The protection against id collisions is the **entry rule** (§R.4.2): on the gated path a sale whose post does not
    carry the web KYC form's marker — the retailer app, the mobile API — is never priced from uCRM, and no application
    stored without `quote_sale_entry` `web` is priced at a retry (E-1).
- Operator question Q2.

#### R.2.6 Product Mapping, Auto-Map and `set_product_mapping` (Uganda, gate on)

- `auto_map_products` and `set_product_mapping` (`api_products_admin.php:400`, `:544`) **answer 409** on Uganda with
  the gate on, the way the push does (`:597-603`): *"On Uganda, plans and kits are linked to uCRM on the Plans and
  Hardware screens."*
- On Product Mapping, the Auto-Map button (`tabs/sales/product_mapping.php:59`) is not drawn there. Its "Run Push to
  UCRM or Auto-Map to link them" line (`:141`) reads the same as the server's message.
- **The rest of the page stops claiming that `ucrm_product_id` reaches quotes.** Each change is a block emitted only
  where the gate is on, as `product_mapping.php:203-205` already does:
  - the header (`:52`, "Link plugin plans to UCRM products so quotes reference real billing items.") says that on
    Uganda quotations use only the uCRM links made on the Plans and Hardware screens, and that the UCRM Product IDs on
    this page are not read for quotes;
  - the notice (`:139-142`) loses its green success branch. Whatever the counts, it shows one neutral line in the
    server's wording: *"On Uganda, plans and kits are linked to uCRM on the Plans and Hardware screens."* It never
    says "mapped" or "Quotes will reference real products". Its count is also looser than it looks: `mapped_to_ucrm`
    counts every row with an id, inactive rows and `kyc_packages` included, against active rows only
    (`api_products_admin.php:918-925`, `:931-943`);
  - the per-table "✅ All Mapped" / "⚠️ Needs Mapping" badge (`:166-169`) and the green `ok` class of the summary
    cards (`:129`, `:132`) are not drawn;
  - the "UCRM Product ID" column (`:183`) is relabelled "Stored product id (not used for quotes)".

  Left untouched: `public.php`'s nav label "UCRM Product Mapping" (`public.php:2258`; a file D14 does not touch,
  §R.8), and `quote_setup_debug`'s JSON (`api_products_admin.php:927-957`), a diagnostic whose `tip` the page does not
  draw.
- The reason: the Auto-Map button runs at once with no confirmation (`:232-244`), writes fuzzy ids into
  `ucrm_product_id`, and an admin could take it for "linking". The ids it writes are inert under D14, but live after a
  rollback (§R.1.2).
- Operator question Q7.

### R.3 Validation (decision 3)

#### R.3.1 At save

`ucrm_link_save` refuses, and writes nothing, unless every one of these holds. Each check is the resolver's own
function, so save and use cannot apply different rules.

1. The gate is on; the caller is an admin on the fresh account record, re-read by id (§R.2.1); and the request passes
   `csrfCheck() && !CustomerSession::crossSite() && BankGate::fromOwnPage('POST')`, checked by the handler itself and
   never by the global gate (§R.2.1).
2. The row exists in its own table (`subscription_plans` for a plan, `kyc_devices` for a kit) and is not Fiber. Its id
   and stamp are kept for the check inside the lock (§R.1.4).
3. **A fresh read** of products and service plans:
   - through `quoteClient`, with the client's normal timeouts, as at submission (§D.1), and outside any transaction
     (§R.1.4);
   - never `forPage`'s cache;
   - unreadable → *"uCRM's products could not be read — nothing was linked"*.
4. **The product id is among the counted products in that read:** present, not archived, not inactive, and of the
   quoting organization (`counts()`, `UcrmQuoteLines.php:198-207`).
   - The organization rule binds only where uCRM returns `organizationId`. Whether 4.5.33 does is NOT ESTABLISHED
     (§D.2).
   - So the link also records the quoting organization in force (§R.1.1).
5. **The product passes `badProduct`** (`:436-454`):
   - a price above 0 with at most 2 decimals;
   - not taxable, and no `taxId`;
   - an id above 0;
   - an invoice label that is empty or keyed like the name.
6. **A plan only:**
   - the service plan id is among the counted service plans;
   - **(a) no other listed service plan, counted or not, has its key**: `plan_keys[key of the linked plan] === 1`. This
     restores `docs/71` §D.4's "exactly one" (`docs/71:128`) over the list the summary reads (§R.3.3);
   - it has exactly one enabled period with `period` = 1, and a numeric price (`:234-240`);
   - **(b) `PublicPriceFeed::planPrice(linked plan)`, rounded to 2 dp, equals that 1-month price.** `planPrice` returns
     a top-level `price` before the 1-month period (`PublicPriceFeed.php:100-110`), and the summary prints what it
     returns;
   - **the product's price equals that 1-month price at 2 dp** (`:243-247`);
   - **the product's name and the service plan's name have the same non-empty key** (§R.3.3);
   - **(c) the raw service-plans list has fewer than 200 rows.** The summary reads `service-plans?limit=200`
     (`webhook.php:343`), so with 200 or more it may not see the linked plan. The resolver alone would accept up to
     499 (`:51`, `:178`).
7. **A kit or the installation:** the product's name key is not the key of any listed service plan, counted or not
   (`plan_keys`; `:275-279`, now applied to the installation too, and over the unfiltered list).
   - The WhatsApp summary classifies every quote item whose label is keyed like *a* service plan as monthly
     (`webhook.php:351-356`, `:2958-2961`).
   - So such a kit, or such an installation line, would be summarised as a monthly charge.
   - As built, `installLine` does not check this (`:286-296`). Linking it is the moment to add the rule.
   - As built, `:275-279` looks at counted plans only, so a kit spelled like an archived or inactive plan, or another
     organization's, is already summarised as monthly: the summary's map has no filter (`webhook.php:352-356`).
     Whether uCRM 4.5.33 lists archived plans or returns `organizationId` is NOT ESTABLISHED (`docs/71:110-111`); the
     rule does not depend on it.
   - No row bound is needed here: a rule-7 check over the fuller list is stricter than the summary's view.
8. **A product may not be both the installation's link and the active link of a kit row the form offers.**
   - **A kit row is offered when `UcrmQuoteLinks::offeredKit($row)` holds** (F-5, §R.1.5):
     `!empty($row['is_active']) && strtolower((string)($row['type'] ?? 'general')) !== 'fiber'`. That is the KYC
     form's own test (`kyc_form.php:33-34`). The form's kit loop (`:32-36`) keeps skipping a row that is not active,
     and now chooses between the kit's own cell and the Fiber cell by `offeredKit`, comparing no `type` itself. So the
     judge and the form cannot differ: a row stored with `is_active` 1 is offered, and one typed `FIBER` is not.
   - A kit's link blocks the installation (rule 8's installation half) only when it is that offered row's single
     active record, with the row's current stamp, under the quoting organization whose id the caller hands the method
     (F-4). A leftover link on an inactive or Fiber kit row, or one with another stamp, does not block the installation;
     QL-LK1 notes it (§R.7.3).
   - **The kit half applies to every kit row, offered or not, at save and at use** (amended by §R.17,
     D14-R8-INACTIVE-KIT, and §R.17.1, FX-money-1). At save — `candidate()`, and `set()`'s check inside the lock — a
     kit row is refused the installation's product; an inactive row's refusal names no deactivation, and its picker
     leaves that product disabled. At use, a kit line whose own in-force link names the installation's product is
     refused with rule 8's at-use reason, so a row deactivated with its link left in force never prices beside the
     installation: not in a stored cart at a retry, not through Make the quotation, not in `confirmLines`. It never
     blocks the installation: `installLine` and the installation's save still count offered kit rows only.
   - Why: `resolve()` appends the installation line after the kit lines (`:368-372`), and `total()` sums every line
     (`:402-410`). Nothing refuses two lines with one `productId`. Such a link would add a kit-priced line, labelled
     with the kit's name, to every "DishNet installs" sale, and Cash would collect it.
   - It is one method of `UcrmQuoteLinks` (§R.1.5), over the link records and the kit rows it holds: `fromStore`
     reads `kyc_devices` behind the gate, and `fromRecords` takes kit rows in `kyc_devices`' stored shape — the offer
     file's (§R.7.1), and inside the lock the `findOne` rows (§R.1.4) (E-5).
     `ucrm_link_save`, `installLine` and `kitLine` at use, and the deploy's remote reading all call it, so save, use and
     the deploy cannot differ. `installLine()` keeps no argument.
   - At save the refusal names the other item: *"<product> is the installation's uCRM product, so a hardware row cannot
     be linked to it. If <row> is the installation itself, deactivate it."* The opposite case reads *"<product> is
     linked to the hardware row <row>, so the installation cannot be linked to it."*

**Rules 6 and 7 are judged against the list the summary builds**, at save, in the picker and at every use (§R.3.2).
That list is every row `GET service-plans` returns, counted or not. The mirror of `webhook.php:352-356` is exact: rows
that are not arrays are skipped, the key is `planKey((string)($p['name'] ?? ''))`, and an empty key is ignored.
`webhook.php` stays byte for byte.

**What the link does not require:**
- that the product be spelled like the plugin row. That is the point of the decision;
- that the product be unique by spelling. Two products spelled alike no longer matter: the link names one id. Twins
  are marked on screen, with their ids (§R.2.3). The *service plan* must still be unique by its key (rule 6(a)).

The same product may be linked to several plugin rows of one kind (two kit rows, say). The screen shows "also linked
to: <row>". A product cannot be both a plan's and a kit's: rule 6 requires it to be spelled like a service plan, and
rule 7 forbids that. Nor can it be both the installation's and an offered kit's: rule 8. Two kit rows may share one
product, with "also linked to:" as the warning on screen, and QL-LK1 counts such products (§R.7.3). Operator question
Q16.

#### R.3.2 Again at every use

**Every resolution of a link repeats R.3.1 rules 4–8** against the catalogue it reads:
- fresh at submission and at retry;
- at most 60 s old when drawing a page (§D.1).

It also checks the link record itself: active, with the row's current stamp, and the quoting organization unchanged.

**A link that fails is unpriced with its own reason** (§R.4.1). It is never read as "not linked", and never as 0:

| Since the link was made | Result |
|---|---|
| the product is no longer listed (deleted) | unpriced: "… no longer lists" |
| archived / inactive / another organization's | unpriced: that word |
| re-priced: a kit or the installation | **priced at the new price.** uCRM is the price list. A form opened before the change is refused for Cash by `CASH_CHANGED` (§G) |
| re-priced: a plan's product, but not its service plan (or the reverse) | unpriced: today's "price in Products (X) differs from its service plan (Y)" (`:245-247`) |
| made taxable, or given a `taxId` | unpriced: today's taxable text |
| renamed so that it and its service plan are spelled apart; or a kit renamed like a service plan, counted or not | unpriced: §R.4.1 |
| the service plan deleted / archived / its 1-month period disabled | unpriced: §R.4.1 |
| another service plan, active or not, now spelled like the linked one | unpriced: §R.4.1 (`:232`, restated) |
| the service plan's own price no longer its 1-month price | unpriced: §R.4.1 |
| uCRM now lists 200 service plans or more | every plan unpriced: §R.4.1 |
| an offered kit, or the installation, linked since to the other's product | the installation and each clashing kit unpriced: §R.4.1 |

**For this the catalogue keeps what it filtered out.**
- `catalogue()` (`UcrmQuoteLines.php:165-185`) gains an `excluded` map, product id or plan id → `archived` | `inactive`
  | `organization`.
- It also keeps:
  - each plan's key next to its id, `excluded` ones included;
  - `plan_keys`, a map from each key to the number of rows holding it, over the unfiltered service-plans list;
  - that list's row count, for rule 6(c).
- An id in neither list is "no longer listed". A read never holds 500 rows or more (`:173`, `:178`), so absence is real.
- The page cache file (`:57`, `:127-145`) gains a shape version, which covers these fields. A cache without it is a
  miss.

#### R.3.3 Why the product and its service plan must be spelled alike

This compares uCRM with uCRM, not the plugin with uCRM, so it is not the matching the operator rejected. It exists
because the WhatsApp quote summary is kept byte for byte (`webhook.php`, §L.4; `test_quote_summary` M). That summary:
- classifies a line as monthly only if its label is keyed like a service plan (`webhook.php:2958-2961`);
- takes the monthly price from that plan (`:351-356`).

The quote line's label is the product's name (`UcrmQuoteLines.php:249-255`). A plan product spelled unlike any service
plan would therefore reach the customer's WhatsApp as a one-time charge.

It is also why the linked plan's key must be the only one in that list (rule 6(a)). The summary maps each key to one
price, and the last row with a key wins (`webhook.php:352-356`). A second plan with the same key — another
organization's, an archived old price, a promo copy — would put its monthly price in the customer's WhatsApp while the
quotation's line is right. As built, `:232` refused that case among counted plans. D14 keeps the refusal, over the
summary's list.

Relaxing the rule needs a `webhook.php` change, in a later release. Strictly, it needs one only while `docs/71` §N
leaves open whether uCRM replaces a line's label with the product's name; otherwise the plugin could send the service
plan's name as the label. **Q4 is a constraint of this release, not an open question** (§R.12).

### R.4 The resolver by link (decision 4)

`UcrmQuoteLines` gains a **required** link source:
- `new UcrmQuoteLines($crm, $setting, UcrmQuoteLinks $links, ?array $catalogue = null)`;
- `forPage($crm, $setting, $dataDir, UcrmQuoteLinks $links)`.

**Every construction site passes one:**
- `tabs/sales/kyc_form.php:21`;
- `lib/KycService.php:237`, `:555`;
- `lib/KycCrmSync.php:364`;
- `lib/QuotationService.php:921-922`;
- `includes/api/api_cron_debug.php:1070`, which moves into the shared implementation that `retry_quote` and Make the
  quotation both call (§R.4.8);
- the deploy preflight (`scripts/deploy-5.18.102.sh:3434`), with
  `UcrmQuoteLinks::fromRecords($offer['links'], $offer['kits'])`, the kit rows in `kyc_devices`' stored shape (§R.1.5,
  §R.7.1, §R.7.7 item 1).

A forgotten site is then a type error that the tests' inventory catches (§R.9), never a resolver that silently prices
by name.

**The signatures:**
- `planLine(array $row, string $kind = 'plan')`;
- `kitLine(array $row, int $qty = 1)`;
- `installLine()`.

Each finds its item's active link, then the product by **id** in the catalogue, then applies §R.3.2. It asks the judge
with its own `$setting['id']` as the quoting organization, on every call (F-4, §R.1.5).
- `productsKeyed` (`:427-433`) and the name lookups at `:220-227`, `:267-274` and `:290-292` are **removed**.
- `planKey` is still used, but only for the uCRM-to-uCRM rules (§R.3.1 rules 6–7).

The line is unchanged: `{productId, label: the product's name, price, quantity, unit}` (`:249-255`, `:456-466`).

`resolve()` also returns the link record ids it used. That is the one set `linesRecord` stores as `quote_link_ids`
(§R.4.4), and the set the form's posted ids are compared with (§R.4.7). It also returns the sale's entry point as
`$res['entry']`, which `linesRecord` stores (§R.4.2), and marks a "not linked" reason as `unlinked`, for `P1_UNLINKED`
(§R.4.1).

#### R.4.1 The reasons

Every name passes through `QuoteOrganization::plain` (as `:222`, `:269`), and a screen draws it with `h()` (§R.2.2).
**`<row>` is the plugin row's own name**: that is what the admin finds on the Plans or Hardware screen, where the
remedy is.

| Case | Reason (agent-facing) | Replaces |
|---|---|---|
| no active link | "<row> is not linked to a uCRM product yet — an admin links it on the Plans screen" (a kit: "… on the Hardware screen") | "<row> is not a product in uCRM" (`:226`, `:273`); "<row> cannot be matched…" (`:223`, `:270`) |
| installation unlinked | "Installation is not linked to a uCRM product yet — an admin links it on the Hardware screen" | "Professional Installation is not a product in uCRM" (`:291`) |
| a `kyc_packages` row | "<row> is a retailer-app package, which is not linked to uCRM". It ends at its cause (E-3) | name match of the package |
| a sale without the web KYC form's marker: the retailer app, the mobile API, a form opened before the update; and, at a retry, an application stored without `quote_sale_entry` `web`, every one saved before the update among them (§R.4.2, E-1) | "this sale did not come from the KYC form of this release (the retailer app, the mobile API, a form opened before the update, or an application saved before it), so it is not priced from uCRM". It ends at its cause (E-3) | pricing by the `subscription_plans` row that has the app's id (`:319`); at a retry, D11's rule by the installation answer (`docs/71` §F.4) |
| row replaced (stamp differs) | "<row> was linked before this plugin row was replaced — an admin links it again" | — |
| two active links | "<row> has two uCRM links recorded — an admin links it again": the one text for that state, on the screens too (§R.1.1, E-4) | — |
| linked under another quoting organization | "<row> was linked for another quoting organization — an admin links it again" | — |
| product not listed | "<row> is linked to a uCRM product that uCRM no longer lists — an admin links it again" | — |
| product archived / inactive / other organization | "<row> is linked to a uCRM product that is archived (not active / another organization's) in uCRM — an admin links it again" | — |
| plan: service plan not listed, archived, inactive, other organization | the same wording, "service plan" for "product" | "<row> is not a service plan in uCRM" (`:231`) |
| plan: its service plan spelled like another service plan in uCRM, active or not | "<row>'s uCRM service plan is spelled like another service plan in uCRM (active or not), so the quotation's WhatsApp summary could show the other one's monthly price — an admin renames one in uCRM" | "two service plans in uCRM are spelled like <row>" (`:232`), restated over the summary's list |
| plan: the service plan's own price is not its 1-month price | "<row>'s uCRM service plan carries a price that is not its 1-month price, so the quotation's WhatsApp summary could show the wrong monthly price — an admin fixes the service plan in uCRM" | — |
| uCRM lists 200 service plans or more | "uCRM lists 200 service plans or more, more than the quotation's WhatsApp summary reads — an admin removes unused service plans in uCRM" | — |
| plan: no single enabled 1-month period | "<row> has no 1-month price in uCRM" | unchanged (`:239`) |
| plan: prices differ | "<row>'s price in Products (X) differs from its service plan (Y)" | **unchanged** (`:246-247`). **The only reason that carries figures, as today** |
| plan: product and service plan spelled apart | "<row>'s uCRM product and service plan are not spelled alike, so the quotation's WhatsApp summary could not show it as monthly — an admin fixes the names in uCRM" | — (new) |
| kit or installation spelled like a service plan, counted or not | "<row> is spelled like a service plan in uCRM, so it cannot be quoted as hardware" | unchanged (`:277`) |
| the installation and an offered kit linked to one product (rule 8); for a kit line, at save and at use, any kit row (§R.17, §R.17.1) | "<row> and the installation are linked to the same uCRM product — an admin links one of them again" | — |
| `badProduct` | "<row> has no price in uCRM" / "'s price in uCRM is 0" / "… more than 2 decimals" / "is set as taxable …" / "has no product id …" / "'s invoice label in uCRM differs from its name" | unchanged texts (`:440-451`), now about the linked product |
| Fiber | "Fiber is not priced from uCRM on this installation" | unchanged (`:332`) |
| uCRM unreadable; a cart item not in the plugin; no install answer | unchanged (`:169`, `:349`, `:61`) | — |

- **"Two products in uCRM are spelled like <row>" (`:227`, `:274`, `:292`) is replaced by nothing.** A link names one
  id, so duplicate spelling cannot make an item ambiguous. Duplicate spelling survives only as a hint in the picker
  (§R.2.3) and on the deploy sheet (§R.7.1). Two *service plans* spelled alike still refuse (`:232`, restated above).
- **No reason carries a money figure beyond today's one** (the plan-against-service-plan comparison). A reason that
  needs to say a price changed says it in words, never with the figure (§R.4.4).
- **Four texts lose their "fix the product in uCRM" tail** (the fourth added by §R.17, D14-UI-2). A link reason
  carries its own remedy ("an admin links
  it…"). So:
  - `TEXTS['CASH_UNPRICED']` (`UcrmQuoteLines.php:74`) ends "Register as Credit, or ask an admin.";
  - the form's Cash note (`kyc_form.php:938`) ends the same way;
  - its submit alert (`kyc_form.php:2252`) ends the same way;
  - Send Quotation's picker alert (`send_quote.php`), which wraps the reason of a plan or kit picked from its list,
    ends "ask an admin, or type the item and its price yourself."

  P1 to P3 keep their texts (`:71-73`), and only the reason inside P1 changes, except in the one case below.
- **The entry reason and the `kyc_packages` reason end at their cause** (E-3). Each is only ever a `{REASON}` inside a
  text that carries its own remedy: P1's "Make the quotation in uCRM (Send Quotation, or ask an admin)." for Credit,
  and `CASH_UNPRICED`'s "Register as Credit, or ask an admin." for Cash. So for an app or stale-form Cash sale the
  agent reads *"No usable price in uCRM: this sale did not come from the KYC form of this release (…), so it is not
  priced from uCRM. Nothing was saved and no money was taken. Register as Credit, or ask an admin."*, never "make the
  quotation" for a registration that was never made.
- **Two texts are added.**
  - `TEXTS['CASH_RELINKED']` (§R.4.7). It carries no name and no figure.
  - `TEXTS['P1_UNLINKED']`: *"No quotation was made: {REASON}. Do not make it by hand — once the item is linked, an
    admin makes it from the Orders card."* (E-2: the card's Make the quotation, §R.4.8). It is used instead of P1's
    text for a **web** sale whose reason is "not linked": an item, or the
    installation (the first two rows of the table). `resolve()` marks those two reasons (`unlinked`, §R.4), so the
    choice never matches the text. The record is still P1: `quote_refused` stays `P1`, and only
    `quote_refused_text` differs, at the three sites that make P1's text (`KycService.php:420`, `:1857`, `:1951`). Every
    other P1, every app-origin one included, keeps P1's own text, "Make the quotation in uCRM" (§R.6).

#### R.4.2 Which link a row takes

`resolve()` keeps its lookups by id (`:318-323`, `:348`):
- a `subscription_plans` row resolves through `item = plan`;
- a `kyc_packages` row, reached by the fallback at `:320`, gets the package reason above. It never takes a `plan` link
  with the same id. The kind key protects only this fallback: when the id is also a `subscription_plans` id, the plan
  row is found first (`:319`), and the entry rule below is what keeps an app sale from being priced through it;
- every cart or device row resolves through `item = kit`;
- the installation resolves through `item = install`.

**The entry rule.** It applies on the gated path only; South Sudan, and Uganda with the gate off, are unchanged byte for
byte.
- **A sale is `web` only when its post carries the 5.18.102 KYC form's own marker; anything else is `app`.**
  - The changed `kyc_form.php` prints one fixed hidden field, `<input type="hidden" name="ucrm_quote_form"
    value="5.18.102">`, beside `ucrm_total_seen` (`:835`), and only where `$_q102` is set, so gate-off output stays
    byte for byte.
  - `saleFromPost($post, $connectivity)` (`KycService.php:1660`) sets the sale's `entry` to `web` when
    `$post['ucrm_quote_form']` is exactly the string `5.18.102`, and to `app` otherwise.
  - So no caller passes it. `KycService::process` keeps its signature (`KycService.php:159`), and
    `includes/post/post_kyc.php` (which hands it `$_POST`, `:13`), `includes/api/api_retailer.php` (`:334`) and
    `api/index.php` (`:463`) all stay byte for byte. §L.4's kept list is not amended (§R.11).
- **Why a request field is acceptable here.** It is not a security boundary:
  - the sender is a signed-in agent;
  - the apps are kept code that never sends it (`retailer/index.html` and the two app APIs are unchanged);
  - a crafted post can obtain only what the web form itself gives: lines priced from the admin's links at uCRM's
    prices, with the posted total and the seen links checked as for any web sale (§R.4.7). Leaving the field out can
    only make a sale unpriced.
- **Consequences, stated:**
  - `handleExisting` is reached only through the app APIs (`docs/71:215-217`): the web form's `customer_id` is hidden
    and always empty (`kyc_form.php:1104`). So, on the gated path, every existing-customer sale the form or the apps
    make is `app` and is never priced. Only a hand-made post could carry both the marker and a `customer_id`, and it
    would get what a web sale gets (above), `P1_UNLINKED` included (`KycService.php:420`; C, §R.9.1).
  - The app reason ends at its cause (E-3). Its remedy is the text that carries it: P1's "Make the quotation in uCRM"
    for Credit, `CASH_UNPRICED`'s "Register as Credit, or ask an admin." for Cash (§R.4.1). It is never "use the web KYC
    form": the web form registers new customers only, so that would be a dead end for an existing customer. The
    `kyc_packages` reason ends at its cause the same way. On the gated path only a hand-made post reaches it, one
    carrying the marker and an id found only in `kyc_packages`: an app sale gets the entry reason first (below).
  - A form opened before the deploy carries no marker, so its sale is `app` too: Cash is refused by `CASH_UNPRICED`
    with the entry reason, and Credit is registered unpriced with P1's own text, quoted in uCRM by hand, and listed
    apart (§R.6, §R.7.3). Recorded.
  - **An application stored without `quote_sale_entry` is `app`** (E-1): every application saved before the deploy,
    and every one saved while the gate was off. At every retry — the cron's, `retry_quote`, Make the quotation — it is
    unpriced with the entry reason, and it is quoted in uCRM by hand. So D11 becomes one statement: **at its retry
    every application saved before the deploy is unpriced, whatever is linked: P1, with the entry reason, for Credit or
    when no cash was collected; P2, with P2's own text, for Cash with cash collected.** For those applications it
    replaces `docs/71` §F.4's rule by the installation answer (below).
- **It reaches the resolver.** In `resolve()`, after the two lookups (`:318-327`), entry `app` with the id in either
  table gives status `unpriced`, with the reason in §R.4.1. `no_plan` stays only for an id neither table has, as
  today. `resolve()` returns the entry as `$res['entry']` (§R.4).
- **Why not look the id up in `kyc_packages` only.**
  - The apps can send only `kyc_packages` ids or mock ids. Neither app API serves `subscription_plans`
    (`api_retailer.php:8`; `api/index.php:210-219`). The retailer app sends `String(item.id)`, `"1"` by default, and
    falls back to `MOCK_PACKAGES`, whose ids start at 1 (`retailer/index.html:1378`, `:1280`, `:1290-1292`, `:55-62`).
  - A `kyc_packages`-only lookup would turn those sales into the `no_plan` refusal (`KycService.php:557`), where
    5.18.101 registers them. A "both tables" check would miss ids found only in `subscription_plans`.
  - Shifting Connection and Ownership Change ask no installation question (`UcrmQuoteLines.php:59`, `:301-305`). So
    without the rule, such a Credit sale is priced through the colliding plan's link and quoted, at submission, or at
    its retry for a record saved before the deploy, which E-1 keeps unpriced.
- **So, on the gated path, an app sale registers as §R.6 says:** Credit with P1 and no quotation; Cash refused by
  `CASH_UNPRICED` with this reason (`KycService.php:1707-1710`), where 5.18.102 as built refused it by
  `CASH_NO_TOTAL` (`:1714`); and a sale whose id is in neither table by the `no_plan` message, as today
  (`KycService.php:557`; `handleExisting` at `:209-211`). 5.18.101 has neither Cash refusal.
- **It is stored for the retries.** On the gated path `linesRecord` (`KycService.php:1724-1743`) adds a new key,
  `quote_sale_entry` (added, never renamed, §F.3), in both of its branches. It takes the value from `resolve()`'s
  result, `$res['entry']`, never from the post: its unpriced branch rebuilds the sale with `saleFromPost` (`:1740`), and
  the entry must be read once. `saleFromApp` reads the stored key: `web` only when it is exactly the string `web`,
  else `app`, as `saleFromPost` reads the marker. **`retryLines` applies the entry rule first** (`KycService.php:1834`),
  before it confirms marked lines or resolves unmarked ones, so a record that is not `web` is unpriced with the entry
  reason whatever it stores, and the Cash rule then records P2 where cash was collected (`:1848-1855`). So
  `retryLines`, the cron retry (`KycCrmSync.php:364`), `retry_quote` and Make the quotation (the shared
  implementation, §R.4.8) keep an app-origin registration unpriced after plans are linked. **An application stored
  without the key is `app`** (E-1, above).
- `docs/71` §E.3 (`docs/71:218-220`) records the overlap as today's behaviour; that sentence is amended the same way.
  So are, by E-1, the two places that state D11 (F-14): §B's D11 row (`docs/71:52`) and §F.4's Cash and Credit
  bullets (`:285-290`: "That covers every Cash application saved before 5.18.102…"; "A Starlink New Connection without
  `install_by`, which every application saved before 5.18.102 is, is unpriced"). Each now says that every application
  saved before the deploy is unpriced at its retry, P1 (Credit, or no cash collected) or P2 (Cash with cash
  collected), and is quoted in uCRM by hand. §Q's note that the record carries no entry point, so the entry point is
  "not measurable" (`docs/71:823`), stays true, and now decides nothing. Every amendment goes in with the release
  (§R.11).

#### R.4.3 Fiber

**Unchanged where 5.18.102 already prices:**
- a KYC sale is unpriced by the sale's `customer_type` (`:331-334`);
- the form's rows are unpriced by the row's `type` (`kyc_form.php:29`, `:34`).

**Added: Fiber rows cannot be linked** (§R.2.2), and `planLine`/`kitLine` refuse a Fiber row with the Fiber reason.
That covers a row linked first and switched to Fiber afterwards.

The consequence is recorded, not hidden. Send Quotation has no Fiber check (Map 1 §1.10), so as built it would price a
Fiber plan row that is spelled like a product. Under D14 it lists that row without a price, as KYC already does.

That closes a build gap rather than making a choice. `docs/71` §D.4 already decides that Fiber on Uganda is always
unpriced (`docs/71:157-159`; UQ-14, `:760`, `:808`). As built, Send Quotation's picker (`QuotationService.php:869-880`,
`:939-949`) and `planLine` (`UcrmQuoteLines.php:215-256`) never read the row's type. Decided by §D.4, not an operator
question (Q12, §R.12).

#### R.4.4 The retry, and `confirmLines`

`linesRecord` (`KycService.php:1724-1743`) adds **`quote_link_ids`** to a priced sale: the ids of the link records that
produced its lines, as `resolve()` returns them (§R.4). Keys are added, never renamed (§F.3).

**They are recorded on every path that quotes a KYC application from links**, only where the gate is on. When a retry
quotes an unmarked application by the current links and the quotation is created, it stores that resolution's
`quote_link_ids`:
- `retry_quote` and Make the quotation: the shared implementation (§R.4.8) writes them once `postQuote` answers
  `created`, in its one write after `postQuote` (§R.4.8, step 5);
- the cron retry: `KycCrmSync.php:364`, then `postQuote`'s success write at `KycService.php:2148-2151`, or one key
  passed through to it.

`retryLines` returns them beside the lines it answers: the resolution's set for unmarked lines, the stored set for
marked ones.

So every KYC application quoted under a link can be found when that link is ended (§R.2.3). Send Quotation also quotes
from links (`QuotationService.php:921-922`) and records nothing, because it never touches `kyc_applications` (below),
so the counts cover KYC applications only. `UcrmQuoteLinks` counts them (§R.1.5), and **Orders gains a filter**:
`&quote_link=<record id>` on `tabs/sales/applications.php` (declared, `docs/71:580`).
- It is admin-only, drawn only where the gate is on, and lists only those applications.
- Nothing is re-priced or re-quoted automatically: correcting a quotation is a person's act in uCRM.
- With the gate off the page stays byte for byte 5.18.101's.

For **marked lines** (`retryLines`, `:1834-1860`), `confirmLines` (`UcrmQuoteLines.php:385-399`) now judges each stored
line the way a link is judged at use. The line must meet all of these:
- each `quote_link_ids` record is still active;
- its product is still counted and passes `badProduct`;
- its current price equals the stored price at 2 dp.

Otherwise the retry quotes nothing. The new reasons name the line's label and carry no figure:
- "the uCRM link of <label> was changed after this application was saved";
- "<label>'s price in uCRM changed after this application was saved".

A Cash application then records P2, and a Credit one P1.

- **Why refuse rather than re-price.** The customer was shown, and for Cash has paid, the lines of the link in force at
  submission. If an admin has since relinked or uCRM re-priced, quoting the stored lines with their `productId` may
  quote a product the admin withdrew, or one uCRM would re-price on the quote (§N). The read-back can only detect that
  afterwards (§F.6).
- **Why it happens rarely.** A retry happens only when uCRM was unreachable at submission, or when an admin retries.
- Marked lines without `quote_link_ids` are refused the same way. 5.18.102 was never deployed, so no real application
  carries them.
- Operator question Q11.
- **Unmarked web applications** (`quote_sale_entry` `web`, unpriced at submission, those saved during the transition
  among them, §R.6) are resolved now, by the current links, as built. Every other application stays unpriced at every
  retry, with the entry reason: an app-origin one, and every one stored without the key, i.e. saved before the deploy
  (E-1, §R.4.2). And no admin path quotes an application whose client already has a quotation in uCRM (below).

**Neither `retry_quote` nor Make the quotation makes a second quotation.** The check sits in the shared implementation
(§R.4.8), which only their gated paths call, so South Sudan and Uganda with the gate off stay byte for byte 5.18.101's.
- **Why.** Its only guard is the application's own `quote_created` (`api_cron_debug.php:1047`).
  - A quotation made by hand in uCRM, or by Send Quotation, never sets that field on Uganda. `QuotationService` never
    touches `kyc_applications`. The quote.add webhook marks the application only when `kyc_messages_like_crm` is off
    (`webhook.php:2905`, `:2918-2925`), and that switch is recorded on in Uganda since 25 Sep (`SAFETY.md:666`).
  - `retry_quote` then POSTs a quotation (`:1108`), sets `quote_created` and `wa_quote_pending` (`:1111-1119`) and
    sends it (`:1120`). The customer would get a second quotation and its WhatsApp.
  - The Orders card keeps saying "No quotation was made" while `quote_created` is empty
    (`tabs/sales/applications.php:346-350`), which invites exactly that retry, and for a web registration it now
    draws Make the quotation there (§R.4.8).
  - Cron does not retry these registrations: `runDue` skips any application with a `crm_client_id`
    (`KycCrmSync.php:86`).
- **The check.** It is the shared implementation's first uCRM read, after its claim and before `retryLines` and any
  other write (§R.4.8). On `retry_quote`'s path it runs inside `if ($_qoRetry['on'])`, after `checkClient`
  (`api_cron_debug.php:1058-1062`); on Make the quotation's, after the handler's own checks.
  - it reads `billing/quotes?clientId=<crmId>` with the client the implementation resolves and posts with,
    `UcrmQuoteLines::quoteClient()` over the service's own client and `kyc_config.json`, at both entry points (F-1,
    F-10), as `api_install_auth.php:74` does;
  - it keeps only rows whose `clientId` equals `$crmId`, because the answer may hold other clients' quotes
    (`api_install_auth.php:68-69`; `QuotationPrefill.php:39`).

  It refuses with 409, with no POST, no PATCH …/send, no `quote_created` and no `wa_quote_pending`:
  - for a customer this application created in uCRM: when any quotation exists;
  - for an existing customer (`is_additional_service`, `KycService.php:372`, `:521`): when a quotation's `createdDate`
    is on or after the application's `submitted_at`, read in the plugin's timezone. A date that cannot be parsed
    counts as after;
  - when the list cannot be read: fail closed.
- **The text** carries no figure and no name: *"uCRM already has a quotation for this client made after this
  application was saved — no second one is made"*.
- **What was found is recorded** in a new key on the application, `quote_found_in_ucrm` (the quote's number and the time
  it was read), not as a P1 text, and the guard writes nothing else (F-10). The Orders card, gated, then shows *"A
  quotation was made in uCRM after registration (#N) — no second one was made"* instead of the "No quotation was made"
  heading. N is uCRM's quote number, a string uCRM supplies, so it is drawn through `h()` (F-8). Make the quotation is
  not drawn on that card (§R.4.8, its fifth condition).
- It is the backstop of the window's one remedy (§R.6). Operator question Q17.

#### R.4.5 Send Quotation and the lead quotes

**`quotePlanKeys` (`QuotationService.php:939-949`) answers per row, not per key.**
- As built it keeps the first row of each key ("rows spelled alike share one", `:934-935`).
- Two rows spelled alike can now be linked to different products, so sharing would quote the second row with the
  first's product.
- `quotePlanPicker` (`:869-891`) therefore takes `planLine($row)` for every row.
- `pickerPlan` (`:894-903`) already shows uCRM's name and price for a priced row.

**The lead pre-fill** (`by_key`, `lead_keys` at `:881-888`; `buildLeadItems` at `:1089-1102`) **stays a spelling
step**: from the lead's `interest_plan` to a plugin row by `planKey`.
- A lead stores free text only (`includes/post/post_leads.php:85`), so there is no id to follow.
- Only the step from that row to uCRM is by link.
- It pre-fills only when **exactly one** plugin plan row has that key. Two rows → no pre-fill, and the existing "add
  items manually" message (`QuotationService.php:160-162`).
- **The page names that cause** (amended by §R.17, D14-UI-1). `quotePlanPicker` also returns `shared_keys`, the keys
  two or more plan rows hold, and a lead whose key is one of them is told *"More than one plan on the Plans screen
  is spelled like this lead's interest (…), so no line was added — add the items yourself."*, never that no plan is
  spelled like it.
- Operator question Q15.

#### R.4.6 What the resolver no longer does

- It finds no product by the row's name.
- It reads no `ucrm_product_id`.
- It finds the installation product by no name.
- It treats no spelling ambiguity as a refusal.

`ucrm_products.json` stays unread (§D.1).

#### R.4.7 A relink while the form is open

`ucrm_total_seen` guards a Cash sale against a change of total only (`KycService.php:1713-1715`;
`UcrmQuoteLines.php:416-422`). Suppose an admin relinks an item to a product at the same price (or makes two changes
that cancel out) between the agent opening the form and submitting it. That passes the total check, and the customer
is shown one product and quoted and charged for another. Before D14 the shown name and uCRM's label always shared a
key; under D14 a relink can change the label to any product at that price. So, on Uganda with the gate on only:

- **The form.** Each linked, resolving cell carries its active link record id as `k`, beside `p`, `r` and the new `l`.
  - The row is drawn with it as `data-link`, only where `$_q102` is set, so gate-off output stays byte for byte.
  - The JS that computes `q102Quote()` (`kyc_form.php:887`) collects the record ids of the same selection it totals:
    the selected plan, each cart item, and the installation when "DishNet installs".
  - It posts them sorted and comma-joined as `ucrm_links_seen`, next to `ucrm_total_seen` (`:835`). The field is `''`
    when there is no total.
- **`cashRefusal`** (`KycService.php:1704`), after the `postedTotalMatches` check and for Cash only, refuses when
  `ucrm_links_seen` is missing, malformed, or not equal to `resolve()`'s sorted set (§R.4). That is the same set
  `linesRecord` stores as `quote_link_ids`. Nothing is written. Being inside `cashRefusal`, it covers `handleNew`, and
  `handleExisting` too, where every sale the form or the apps make is `app` and is refused before it, and a hand-made
  post carrying the marker is checked as any web sale is (§R.4.2).
- **A new text**, `TEXTS['CASH_RELINKED']`: *"An admin changed which uCRM product an item on this sale is linked to
  since the form was opened — reload the form. Nothing was saved and no money was taken."* It carries no name and no
  figure. `CASH_CHANGED` is not reused: its words claim a price change (`UcrmQuoteLines.php:76`), and its callers drop
  the page cache on its exact text (`KycService.php:240`, `:559`), which links, read live, do not need.
- Credit is unchanged. The retailer app, the API and a form opened before the deploy carry no marker, so they are
  refused before this, by `CASH_UNPRICED` with the entry reason, or by the `no_plan` message for an id in neither table
  (§R.4.2). So the new field costs nothing in the transition. A first link cannot trigger it, because Cash is disabled
  while an item is unlinked (§R.6).
- **A limit, recorded:** a uCRM rename of the linked product itself (same id, same price) still passes, as before D14.
  Covering it would need a per-line digest of `productId` and label.

#### R.4.8 Make the quotation — the Orders card's remedy (E-2)

A web registration left unpriced because an item was not linked is quoted by **a button, not a URL**. It is the remedy
`P1_UNLINKED` names (§R.4.1), in the window (§R.6) and at any later time, for instance when a new active kit is not yet
linked. It needs no `crm_auth_token`, and no list outside the card has to name the registrations it applies to.

**The button.** On the Orders card (`tabs/sales/applications.php`), at the foot of the "No quotation was made" block
(`:346-350`), labelled **"Make the quotation"**. It is drawn only:
- where the gate is on (`$ugOrders102`, `:20-21`); with it off the card stays 5.18.101's byte for byte;
- for an admin (`$isAdmin`, as the card's Retry is at `:322`);
- for an application that meets **five conditions**: `quote_sale_entry` exactly `web`, a `crm_client_id`,
  `quote_refused` `P1`, no `quote_created`, and **no `quote_found_in_ucrm`** (F-10). Where the guard has found a
  quotation the card shows its line (§R.4.4) and no button, since a press there could only be refused. It shows that
  line in place of the refusal text, never above *"No quotation was made: … an admin makes it from the Orders card"*
  (amended by §R.17, D14-UI-4).

After an `error` answer or a Throwable from `postQuote` (step 4), while `quote_unconfirmed_at` is recorded and
`quote_created` is empty, the card draws *"⚠ Making the last quotation did not finish"* and *"It may have been made in uCRM."*,
in place of the refusal text, for admins and agents alike. Where the button is drawn (an admin, and the five
conditions) it goes on *"Do not make one by hand in uCRM: Make the quotation looks there first and never makes a second
one."*, and the button stays. Elsewhere — an agent, or an application the button does not apply to, which `retry_quote`
can reach — it goes on *"Do not make one by hand in uCRM before an admin has looked there for it."* Only with the gate
on: with it off the card is 5.18.101's byte for byte (amended by §R.17.2, CK-flows-2, §R.17.3 and §R.17.4, CK5-B2).

While a quotation is being made — a claim younger than `QUOTE_CLAIM_SECONDS`, makeQuotation's own rule, taken by
the button or by `retry_quote`, and no `quote_created` — the card draws *"⏳ Making a quotation for this application
has not finished"* and *"Do not make one by hand in uCRM: wait, then open the Orders screen again."* in place of the
box above or the refusal text, for admins and agents alike: the press removes the key before its POST, and what the
card holds may be out of date. The found line still outranks it, and a made quotation draws no box. Every answer and
every Throwable removes its claim, so a claim still there once it is older than `QUOTE_CLAIM_SECONDS` is a request
that died — after its POST, perhaps — or a removal that failed (logged): the card then draws the "did not finish"
box above, never the refusal text. A removal that failed after a refusal also draws the in-flight box over that
fresh refusal until the claim expires; it says not to make one by hand, and a press meanwhile is answered `claimed`.
Only with the gate on (amended by §R.17.5, CK6-F1, and §R.17.6, CK7-F1 and CK7-F3).

It is a POST form that prints `csrfField()` inside it and carries `action` = `kyc_make_quote` and `app_id`, and nothing
else. Its submit button has no `name`, so the browser posts exactly three fields, the token `_csrf` among them
(`public.php:98-100`). The button disables itself on submit.

**The handler**, `kyc_make_quote` in `includes/post/post_sync.php`, in this order:
1. a POST with that action, handled only where the gate is on. With the gate off the block is skipped whole, so the
   action falls through as an unknown one does in 5.18.101;
2. `$me = $auth->requireAdmin()`, which answers as it does elsewhere, with the sign-in redirect or 403; then the account
   re-read fresh by id and refused with 403 unless active and admin: §R.2.1's expression, written in the action itself;
3. the own-page check, made by the handler itself and never left to the global gate (§R.2.1). It loads
   `lib/BankGate.php` and `lib/CustomerSession.php` unchanged and refuses unless
   `csrfCheck() && !CustomerSession::crossSite() && BankGate::fromOwnPage('POST')` (`post_bank.php:45-49`);
4. **nothing from the request but the application id** (F-2). The handler refuses when `$_POST` holds any key other
   than `action`, `_csrf` and `app_id`, or when `app_id` is not digits only. The entry, the client, the amount and the
   lines all come from the stored application, and no request field can name any of them;
5. the application re-read from the store by `findOne('kyc_applications.json', 'id', …)`, and the button's five
   conditions checked again there;
6. `svc('kyc')->makeQuotation($app, null, 'orders_card', $fresh)` (below): the application as step 5 read it; no
   organization known, so `postQuote` makes the company check itself (F-1); and the fresh account record of step 2.
   There is no `crm_auth_token` precondition: the method resolves and posts with `quoteClient`, which without the token
   is the plugin's own client, the one KYC quotations are made with. `retry_quote` keeps its own precondition
   (`api_cron_debug.php:1049-1053`);
7. a flash chosen by the method's `kind` (F-7), then a redirect to `?page=dashboard&tab=applications`, which carries no
   POST value. The log carries ids only.

A refusal at steps 3–5 writes nothing, sends no request to uCRM, and shows one fixed flash. Step 1 with the gate off
falls through, and step 2 answers as `requireAdmin` does (F-14).

**The flashes.** Each is fixed text plus ids, and **every id in a flash is cast with `(int)`** (F-8): the
application's, and the quote id, which `postQuote` returns raw from uCRM's answer (`KycService.php:2159`). The layout
prints the flash raw (`public.php:2508`). By the method's `kind`:
- `made`: *"Quotation #<quote id> was made in uCRM for application #<id>. Its WhatsApp follows once uCRM's PDF is
  ready."*;
- `found`: *"uCRM already has a quotation for this client made after application #<id> was saved — no second one was
  made."*;
- `unreadable`: *"uCRM's quotations for this client could not be read, so no quotation was made for application #<id>
  — try again."*;
- `refused` (by the lines — P1, `P1_UNLINKED`, P2 — or by the company check): *"No quotation was made for application
  #<id> — its card says why."*;
- `claimed` (the claim, below): *"A quotation is being made for application #<id> — wait, then open the Orders screen
  again."*;
- `error`: *"uCRM did not confirm the quotation for application #<id>. It may have been made: try Make the quotation
  again later — it never makes a second one."* (amended by §R.17.1, FX-flows-2: a lost answer reaches it too)

And the handler's own:
- step 3, the security check: *"The security check failed, so nothing was changed. Open the Orders screen again and
  retry."*;
- step 4, another field posted or a malformed id: *"The request carried more than the application's number, so nothing
  was changed. Open the Orders screen again and retry."*;
- step 5, the conditions no longer hold: *"Make the quotation does not apply to application #<id> now. Nothing was
  changed."*;
- a Throwable from the method, caught: *"Making the quotation for application #<id> did not finish. Open the Orders
  screen again before you retry: uCRM may already have it, and Make the quotation never makes a second one."*
  (amended by §R.17, D14-MQ-THROW-AFTER-POST: the Throwable may come after the POST made the quotation, so the
  answer never says none exists; the log line says the same, ids only).

**The shared implementation.** One public method of `KycService`, extracted from `retry_quote`'s gated block so that
block calls it, and Make the quotation calls the same code (F-1):

    public function makeQuotation(array $app, ?array $known, string $via, array $actor): array

It is called on the service's instance (`svc('kyc')`, `lib/Services.php:63`), and uses that instance's plugin client,
store and data folder (`KycService.php:50-57`). It reads `kyc_config.json` from the store as `$cfg`, as `postQuote`
does (`KycService.php:2095`), and the gate's `$setting` from `QuoteOrganization::setting($cfg, <the data folder>)`. It
resolves and posts with `$quoteCrm = UcrmQuoteLines::quoteClient(<the plugin client>, $cfg)`, the rule by which
`postQuote` builds its own client (`KycService.php:2097-2099`). Its parameters:
- `$app`: the application as its caller read it;
- `$known`: the organization its caller has already checked, in the shape `postQuote` takes it, or null. `retry_quote`
  passes what its own `checkClient` found, so `postQuote` asks nothing more; the button passes null, and `postQuote`
  checks;
- `$via`: `orders_card` or `retry_quote`. It chooses the staff's notes (step 4) and is recorded (step 5). Any other
  value throws before the claim;
- `$actor`: the fresh account record of the admin who asked (§R.2.1). Its id is recorded (step 5, F-13).

In order:
1. **the claim** (below);
2. **R-M4's guard** (§R.4.4): `billing/quotes?clientId=<crmId>` read with `$quoteCrm`, rows kept by `clientId`. A
   quotation found — any, for a customer the application created; one dated on or after `submitted_at`, for an
   existing customer — records `quote_found_in_ucrm`, and nothing else, and answers `found` (F-10). An unreadable list
   answers `unreadable` and writes nothing. None found removes `quote_unconfirmed_at` (step 4), since the POST it
   records made none: by the row as stored, under the lock, whatever the caller read (§R.17.2, §R.17.3);
3. **the lines**: `retryLines($app, new UcrmQuoteLines($quoteCrm, $setting, UcrmQuoteLinks::fromStore($store,
   $setting)), $store, $cfg)`. The entry comes from the record (§R.4.2): `web` for every application the button is
   drawn for. The lines are resolved by the current links, and the Cash rule holds: a Cash application with cash
   collected is quoted only if the lines total the **stored** `amount_charged` at 2 dp, else P2
   (`KycService.php:1848-1855`). A refusal is recorded by `recordNoQuote` — P1, with `P1_UNLINKED`'s text where the
   reason is "not linked" (§R.4.1), or P2 — and answers `refused`;
4. **`postQuote()`** (`KycService.php:2092`) with the plugin's own client, the lines, `customerNotes($cfg)`, the
   staff's notes and `$known`: the company check (`:2103-2117`), which reads the client from uCRM only when `$known`
   names no organization; the POST; the read-back (`:2142`); `quote_ref`; uCRM's send; then `quote_created` and
   `wa_quote_pending`, as every KYC quotation is made. The staff's notes are `Retry for App #<id> · Sales: …` for
   `retry_quote` (`api_cron_debug.php:1106`), and `Made from the Orders card for App #<id> · Sales: …` for the button. A
   refusal of the company check is recorded as `postQuote` records it and answers `refused`; a failed POST records
   `quote_error` and answers `error`. The method then records `quote_unconfirmed_at`, since a POST whose answer was
   lost (a timeout, a 5xx) reaches `error` after uCRM made the quotation (§R.17.2). A Throwable from `postQuote` records
   it too, in its own try, and then goes on to the caller, since it may come after the POST (§R.17.3). Both writes are
   one helper, `recordUnconfirmed()`: a write that fails is logged with ids only and never answers in place of the
   answer or the Throwable it follows (§R.17.4);
5. **once `postQuote` answers `created`**, one write on the application:
   - `quote_link_ids`, the set the lines came from (§R.4.4);
   - `quote_error` null, cleared as `retry_quote` clears it (`api_cron_debug.php:1115`; F-10);
   - `quote_made_by_id`, `$actor`'s id, and `quote_made_via`, `$via` (F-13). Both keys are added, never renamed
     (§F.3). They are written only here, so only on the gated path, only with a quotation made, and never by the cron
     retry.
   - **The write sits in its own try** (amended by §R.17, D14-MQ-THROW-AFTER-POST). By then `postQuote` has stored
     `quote_id`, `quote_created` and `wa_quote_pending`, so the answer is `made` whatever the write does. A failure
     is logged with ids only (the quotation's, the application's and the link ids), so an admin can restore
     `quote_link_ids`.

**What it returns** (F-7) is one array:

    ['code' => 200|409|500, 'kind' => 'made'|'claimed'|'found'|'unreadable'|'refused'|'error',
     'quote_id' => ?int, 'lines' => int, 'total' => ?float, 'text' => string]

- `made` is 200, with `quote_id` the `(int)` of `postQuote`'s id, `lines` the lines' count and `total` their total at
  2 dp. Its `text` is `''`: each caller words its own success.
- `claimed`, `found`, `unreadable` and `refused` are 409, and `error` is 500. Each has `quote_id` and `total` null, and
  `lines` the count resolved, else 0. Its `text` is fixed for `claimed` (*"another quotation is being made for this
  application"*), `found` (§R.4.4's text), `unreadable` (*"uCRM's quotations for this client could not be read — no
  quotation was made"*) and `error` (*"uCRM did not confirm the quotation — it may have been made; a retry never makes a
  second one"*, §R.17.1). For `refused` it is the refusal's text, as
  recorded on the application.
- A Throwable is not an answer. The claim is removed (below), and the Throwable goes on to the caller.

The button's flashes and `retry_quote`'s answers are chosen by `kind`, never by `code` alone, since four kinds share
409. The method applies no auto-quote limit (P3), as `retry_quote` applies none today: either way it is an admin's act.

- **`retry_quote`** (`api_cron_debug.php:1038`) keeps everything before its gated block:
  - the application id, and the early answer for `quote_created` (`:1047`);
  - the `crm_auth_token` precondition and its client (`:1049-1053`);
  - the company check (`:1058-1062`), which now keeps its `QuoteOrganization` instance so that it can hand on what it
    found: `$known = ['organizationId' => (int)(lastClient()['organizationId'] ?? 0), 'where' => 'debug-retry']`.
    `checkClient` returns no organization, but `lastClient()` holds the client row it read (`QuoteOrganization.php:196`),
    and `checkClient` reads no client row when `$known` names a positive organization (`:136-139`).

  Its gated path then re-reads the account fresh by id, with §R.2.1's expression, and refuses with 403 unless it is
  active and an admin (§R.16). It calls `svc('kyc')->makeQuotation($app, $known, 'retry_quote', $fresh)` and answers by
  `kind`:
  - `made` → 200 with its own success text (`:1121-1122`), from `quote_id`, `lines` and `total`;
  - `claimed` → 409 *"another quotation is being made for this application"* (F-9);
  - `found`, `unreadable` and `refused` → 409 with the method's text;
  - `error` → 500 with the method's text;
  - a Throwable from the method, caught → 500 *"The quotation may not have been made — open the application before
    you retry; a retry never makes a second one."*, the log carrying ids only (F-9; amended by §R.17).

  On the gated path the work of `:1064-1075` (the lines) and of `:1097-1122` (the notes, the POST, the read-back, the
  writes and the send) is the method's, and nothing after the call posts, writes or sends. Its gate-off path stays
  5.18.101's.
- **Make the quotation** calls it with `$known` null. Its company check is `postQuote`'s own.
- **What changes for `retry_quote` on the gated path, recorded as deliberate** (F-10):
  - uCRM's send follows `kyc_quote_send_via_crm` (`KycService.php:90-97`), where `:1120` always PATCHed …/send;
  - the client the method posts with takes `crm_base_url` where it is set (`KycService.php:2097-2099`), where
    `retry_quote`'s own takes the plugin's base URL (`:1053`), with the same token;
  - `wa_quote_phone` is stored as `postQuote` stores it (`KycService.php:2155`), where `:1117` stored the raw mobile.

  The company check is still `retry_quote`'s own, through its own client, handed on as `$known`, and `quote_error` is
  still cleared on success (step 5). South Sudan, and Uganda with the gate off, are unchanged.
- **The cron retry is not one of its callers.** `KycCrmSync::ugQuote` (`KycCrmSync.php:349-385`) quotes right after
  the client's create, applies P3, and needs no guard, because `runDue` skips any application with a `crm_client_id`
  (`KycCrmSync.php:86`). It shares `retryLines` with the method, so `P1_UNLINKED` reaches its records the same way.

**A double press.** Two requests for one application — a double click, two tabs, two admins — could both pass the guard
before either quotation exists, and the customer would get two quotations and two WhatsApps. So the method's first act
is a claim, `quote_make_claim_ts` (F-9, F-10):
- **It is taken** inside `$store->withLock('kyc_applications.json', …)`, as `KycService.php:2256` writes that table.
  The callback reads the application. It answers `claimed`, writing nothing, when `quote_created` is set (a request
  that finished after the caller read the application) or a `quote_make_claim_ts` younger than 900 s is recorded.
  Otherwise it writes `quote_make_claim_ts` = `time()`. It does no network I/O (§R.1.4's lesson).
- **It lasts 900 s**, above the method's worst case: about eight requests — the guard, two catalogue reads, the company
  check, the POST, a read of the quote, the read-back and the send — each with a 30 s limit (`CrmApiClient.php:463`),
  so about 240 s. The guard stays the backstop.
- **It is removed** in a `finally`, whatever the method answers or throws, by a second `withLock` on the same table.
  Its callback unsets only that key, on that one application, and only while the key still holds the value this
  request wrote, so a refusal leaves the record as it was. A request answered `claimed` took no claim, and removes
  none. `updateOne` is not used for it: it only merges (`SqliteStore.php:680-681`), so it could not remove the key.
- **A removal that throws** is caught and logged with ids only, and the method's answer stands. The claim then expires
  by its age.

The key is added, never renamed (§F.3), written only on the gated path, and never read by 5.18.101. It was found while
folding E-2 (§R.15). F-10 keeps it, and F-9 fixes how it is taken and removed.

### R.5 Names (decision 5)

**The plugin row's own name is never overwritten**, not at link time and not afterwards. The "sync" the operator asked
for is this: **wherever the agent or the customer sees an item, they see uCRM's live name and price.** Nothing is copied
into the plugin, so there is nothing to drift and nothing for a rollback to keep.

The reasons for not writing uCRM's name into the row:

1. **Saved applications would not break**, because they refer to rows by id (§R.1.3). That is not the argument.
2. **Photos and spec sheets are keyed by the hardware title.** The assistant's media name is derived from it
   (`hardware.php:14-19`, `:126`; `post_sync.php:502-505`), so renaming a kit detaches its photo and PDF.
3. **A lead finds its plan row by the row's own name** (§R.4.5). Rewriting names changes which leads pre-fill.
4. **On Uganda with the gate off, and after a rollback, 5.18.101 quotes and pushes the row's name.**
   - `retry_quote`'s lines use the current name (`includes/api/api_cron_debug.php:1078-1093`).
   - The cron retry's lines use the name snapshotted at submission (`KycCrmSync.php:398-403`, from
     `KycService.php:1150`).
   - A save pushes the name to uCRM (`post_sync.php:463-468`).

   An overwritten name is therefore a lasting change to 5.18.101's behaviour.
5. **The row's name is the admin's own label** (speed or supplier wording on the Plans screen, the help pages' plan
   list at `tabs/help/knowledge_base.php:71-85`). Copying uCRM's name over it is a second, one-way sync, which goes
   stale the next time uCRM renames.

**Where each name is seen, under D14:**

| Place | Name shown |
|---|---|
| the quotation's lines (uCRM PDF, read-back) | uCRM's product name. Unchanged: the line label (`UcrmQuoteLines.php:249-255`, `:456-466`) |
| the KYC form, a linked row that resolves | **uCRM's product name**. The cell gains `l`, the label, for plans and kits as it has for the installation (`kyc_form.php:16-18`, `:39`), and `k`, its link record id (§R.4.7). The row is drawn with it, and `data-name`/`data-title` (`:1576`, `:1297`) carry it. So it also reaches the JS total's labels (`:905-907`), steps 3 and 5, the order summary, `hw_cart_json`'s titles (`:1814`), `selected_plan_name` (`:1617`) and the `kyc_hardware` CSV that reads them (`post_leads.php:794-814`) |
| the KYC form, an unlinked or broken row | the plugin row's name, "not priced in uCRM", and the reason |
| the KYC form, "DishNet installs" | the linked installation product's name (`kyc_form.php:1252`). When the installation does not resolve, its reason is drawn as text on the card, not only in its title, which a phone never shows (§R.17, D14-UI-3) |
| Send Quotation's plan picker | uCRM's name when priced (`QuotationService.php:894-903`, as built); the plugin's name with the reason when not |
| the existing customer's job description | the plugin row's name with "not priced in uCRM". On the gated path every existing-customer sale the form or the apps make is `app` and never priced (§R.4.2), so uCRM's plan line label, which the description would carry when priced, reaches it only from a hand-made post carrying the marker. Today it pairs the plugin's name with uCRM's price (`KycService.php:2450-2452`, `:2470-2472`) |
| the staff messages (`kycSubmitted`, `kycCrmCreated`, `kycAdditionalService`) | no item names: amount and "Who installs" only (`NotificationService.php:220-240`, `:246`, `:330`). Unchanged |
| the Orders card | no item names, except inside a refusal reason (the plugin row's name, §R.4.1) (`tabs/sales/applications.php:93-101`, `:346-362`). Unchanged |
| the Plans and Hardware screens | **both, side by side** (§R.2.2) |
| the uCRM client's `note` | the plugin's device title and plan name (`KycService.php:786`). Unchanged: it records what the agent picked, and the create body stays as built (§I) |
| the application's `offer_name` / `device_title` snapshots | the plugin's names (`KycService.php:1146-1150`), unchanged. uCRM's names are in `quote_items`. The installation-authorisation form already prefers the uCRM quotation and falls back to the snapshots (`includes/api/api_install_auth.php:103-111`) |
| the retailer app | the plugin's titles and prices (`retailer/index.html`, kept byte for byte). A recorded limit, as in §E.3 |

**The form's default plan and kit are chosen by the row's own name** (amended by §R.17, D14-UI-5). `applyDefaults`
matches its keywords on `data-row-name` and `data-row-title`, which carry the plugin row's name on the gated page, so
a uCRM rename never moves the default to another row, an unpriced one among them. `data-name`, `data-title`,
`selPlan` and `hwCart` keep uCRM's names, as the table says.

An explicit per-row "use uCRM's name for this plugin row" button is not built. Operator question Q6.

### R.6 The transition (decision 6)

**Right after the copy, nothing is linked.** 5.18.101 has no link store; the exception is a link left by an earlier,
rolled-back run of this release (§R.7).

So, on Uganda, until an admin has linked every item the form offers:

| Where | What the agent sees |
|---|---|
| KYC form, every plan and kit row | "not priced in uCRM", with "<row> is not linked to a uCRM product yet — an admin links it on the Plans (Hardware) screen" |
| KYC form, "DishNet installs" | "Installation is not linked to a uCRM product yet — an admin links it on the Hardware screen" |
| KYC form, Cash | the option is disabled, with "Cash is not possible — the total is not priced in uCRM: <reason>. Register as Credit, or ask an admin." A forced submit is refused with `CASH_UNPRICED`: "Nothing was saved and no money was taken" |
| KYC form, Credit | registered, uCRM client created, **no quotation**, P1 recorded with `P1_UNLINKED`'s text (§R.4.1). The success message: "No quotation was made: <reason>. Do not make it by hand — once the item is linked, an admin makes it from the Orders card." |
| Send Quotation | every plan is listed without a price, with its reason, and cannot be picked; a lead quote asks for the items by hand, and lines staff type are accepted and sent as typed (`docs/71` §J). It is not a dead end, but a quotation made there, like one made in uCRM, never sets `quote_created` on Uganda (§R.4.4), so none is made there for a registration whose screen says "Do not make it by hand" (below) |
| retailer app, mobile API, and a form opened before the deploy | Cash refused, now by `CASH_UNPRICED` with the entry reason, which ends at its cause (§R.4.2, E-3), instead of `CASH_NO_TOTAL`, or by the `no_plan` message for an id in neither table; Credit registered with P1's own text and no quotation, and the app does not say so (§E.3). Before and after linking alike, an app-origin registration is never quoted from uCRM and gets no Make the quotation button, so it is quoted in uCRM by hand, as P1 says. QL-LK1 lists these registrations apart (§R.7.3) |
| an application saved before the deploy, at its retry (the cron's, `retry_quote`) | unpriced with the entry reason, whatever is linked (E-1): P1 (Credit, or no cash collected) or P2 (Cash with cash collected) on the Orders card, and no Make the quotation button. It is quoted in uCRM by hand |

**How long:** from the copy until the admin has linked the active plans and kits the form offers, and the installation.
Their number is on QL-G1's sheet (§R.7.1). No duration is estimated here.

**The web KYC Credit registrations made in that window are quoted once, by an admin, after `--after-only` passes.**
They are unmarked, so once the items are linked an admin presses **Make the quotation** on each one's Orders card
(§R.4.8). It resolves the registration by the new links through the shared implementation (§R.4.4), and records the
`quote_link_ids` it used. `retry_quote` (`includes/api/api_cron_debug.php:1038`; admin only, `:20`, `:24`) runs the
same code, but needs `crm_auth_token` (`:1050-1051`) and a typed address: the button is the remedy (E-2).

**One remedy for the web registrations, stated everywhere:** here, on the agent's own screen (`P1_UNLINKED`, §R.4.1),
in the step-5 and step-9 messages, and in the deploy's after-copy text and "your steps" (§R.7.2, §R.7.5).
- During the window, agents make **no quotation by hand** for a registration whose screen says *"Do not make it by
  hand"* (`P1_UNLINKED`, §R.4.1), in uCRM or by Send Quotation (F-12).
- A web registration whose screen gives P1's own text — uCRM unreadable at submission, or an item linked but broken —
  is quoted as that text says, in uCRM by hand. Its card still draws Make the quotation, because its five conditions
  hold (§R.4.8); the admin checks uCRM before pressing, and the guard refuses a second quotation (§R.4.4).
- An admin makes the window's web registrations' quotations with Make the quotation, after `--after-only` passes;
  QL-LK1 counts them (§R.7.3). Before pressing it, the admin checks the customer in uCRM, and presses it only where
  there is no quotation.
- An application quoted by hand anyway is refused by the shared implementation's check (§R.4.4): 409 at
  `retry_quote`, the guard's flash at the button. It is never quoted twice, and its card then draws no button (the
  fifth condition, §R.4.8).

P1's own text (`UcrmQuoteLines.php:71`) is kept (§F.5) for every other case. A web sale whose reason is "not linked" —
an item, or the installation — is recorded with `P1_UNLINKED` instead (§R.4.1), so the agent's screen and the Orders
card give the one remedy rather than "make the quotation in uCRM". App-origin registrations, a form opened before the
deploy among them (§R.4.2), and every application saved before the deploy (E-1), are outside this remedy: they keep
P1's own text (or P2's), stay unpriced on every retry, get no Make the quotation button, and are quoted in uCRM by
hand. QL-LK1 lists the window's apart, never in its Make-the-quotation count (§R.7.3). Operator question Q17.

**The runbook's order** (§M, amended):

Steps 1, 2 and 4 keep `docs/71` §M's numbers (`docs/71:596`, `:599`, `:606`), so every pointer to them stays right;
step 3 replaces §M step 3, the spelling rule (`docs/71:600-605`).

1. **Before the deploy, record 5.18.101's deployment** (`docs/71` §M step 1, unchanged).
2. **Add the "Sales Person" client attribute** (§I), its key exactly `salesPerson` (`docs/71` §M step 2, unchanged).
3. **Prepare uCRM, and decide the picks:**
   - for each plan sold, one Product and one Service Plan spelled alike, with equal 1-month prices, and no other
     service plan, active or not, spelled like that plan;
   - no service plan carrying a price of its own other than its 1-month price, and fewer than 200 service plans in
     all;
   - for each kit, a Product not spelled like any service plan, active or not;
   - one installation Product, which no kit is linked to;
   - each priced above 0, Taxable off, invoice label empty or the same as the name;
   - run the deploy script's **`--check`**. It changes nothing. Its 0600 sheet lists, per plugin item, under *"uCRM
     items with the same spelling — a hint only"*, those items by id, with whether each could be linked, and says
     whether uCRM tells which company a product belongs to (§R.7.1);
   - decide each pick on paper from that sheet, by uCRM id.
4. **Leave Settings → "Quote notes prefix" empty**, or put in it only text meant for customers (`docs/71` §M step 4,
   unchanged).
5. **Tell the agents** before the copy: from the deploy until an admin says it is done, Uganda sales are registered as
   Credit, or wait (§M). No quotation is made by hand for a web KYC registration whose screen says "Do not make it by
   hand": an admin makes it afterwards from the Orders card (above, F-12). A registration from the retailer
   app, the mobile API or a form opened before the deploy, and every application saved before the deploy, is quoted in
   uCRM by hand: 5.18.102 never quotes them from uCRM (§R.4.2, E-1). **Tell every admin**: no link, unlink, or plan or
   kit save from the moment the deploy starts until it prints its last line (§R.7.2).
6. **Deploy**, at a quiet hour.
7. **Link once the deploy has printed its last line**, as an admin, on Plans (each active, non-Fiber plan), on Hardware
   (each active, non-Fiber kit) and on the installation card. The deploy prints the counts still to link.
   - A kit row the import tool made for the installation product is deactivated rather than linked, since rule 8
     refuses it. If it was linked first, it is also unlinked: deactivating a row ends none of its links, and a sale
     still holding the row is then unpriced at use rather than quoted with the installation twice (§R.17). `tools/import-from-ucrm.php:131-165` copies into `kyc_devices`, as an active row, every uCRM product
     that is not a plan or an accessory.
8. **`--after-only`** judges every link (QL-LK1, §R.7.3). Pass it only when every offered item resolves, or carry
   `--accept-unpriced` for items left unlinked on purpose. Then an admin presses **Make the quotation** on the Orders
   card of each web registration that shows it (QL-LK1 counts them), after checking the customer in uCRM, and only
   where it has no quotation (above).
9. **Tell the agents** that Cash is back, and that the window's web registrations — those whose screen said "Do not
   make it by hand" — are quoted by an admin, never by hand. Every other registration left with no quotation is quoted
   in uCRM by hand, as its screen or its Orders card says.
10. The next real KYC quotation is the second proof, as before.

**What can be prepared before the deploy:** uCRM itself, and the list of picks (step 3). **No link is written before the
deploy**, because:
- 5.18.101 has no screen for it;
- writing the table by hand is an unreviewed production write;
- seeding links from spelling or from `ucrm_product_id` would be the guess the operator ruled out.

**Why not deploy with the gate off, link, then turn it on:** the pickers exist only where the gate is on (§R.8). Showing
them on Uganda with the gate off would break "Uganda, gate off, byte for byte 5.18.101's". Operator question Q10.

### R.7 The deploy script (decision 7)

Everything below changes `deploy-5.18.102.sh` (`661bd5c7`) by asserted edits, as §Q does.

#### R.7.1 Before the copy — QL-G1

**What can exist.** No link can exist before the copy, except one left by an earlier, rolled-back run of this release.
The link store is read **on the copy of the book only**:
- `SqliteStore::load()` would create the table in the live store (§R.1.5);
- on the copy it creates it harmlessly.

**The offer file** (`scripts/deploy-5.18.102.sh:3169-3181`) carries, per offered row, its `id`, name or title and
`fiber` today. Its rows are the active ones only (`:3175-3176`). It gains:
- the row's `is_active`, its `type`, and its raw `created_at` and `imported_at`, never a stamp the script computed.
  The kit rows are what the judge's rule 8 is given (§R.3.1), so the release applies its whole "offered" test to them
  itself, `UcrmQuoteLinks::offeredKit` (F-5). They reach `fromRecords` in `kyc_devices`' stored shape (E-5, §R.1.5);
  how the writer carries them beside `$ql_row`'s reshape is §R.7.7 item 1;
- every link record on the copy, ended ones included, as `records()` returns them whole (E-5), so the release applies
  its own active rule (ML6), reduced to: `id`, `item`, `row_id`, `row_stamp`, `product_id`, `service_plan_id`,
  `organization_id`, `linked_at_ts` and `ended_at_ts`, the active marker (§R.1.1). Never `linked_at` or `ended_at`,
  which are for the screens only, and never `row_name`,
  `product_name`, `service_plan_name`, the `linked_by` or `ended_by` fields, a name or an account.

The local mode reads those records, and the kit rows, with `UcrmQuoteLinks::fromStore($store, $setting)` and its
`records()`, on the copy only, handing it the setting it already has: the class takes the gate from its caller, opens
nothing when it is off, and require()s no sibling file (E-5). The local mode requires the pin's file by that file's
own path (F-6, §R.7.7 item 2).
**At stage A the class is the pin's `lib/UcrmQuoteLinks.php`, from Git** (`$P3B_PIN`, `:5508`, which stage A already
hands `qo_run` as its pin, `:3963`), never the code root the local reading copies. At stage A that root is `ICODE_A`
(`:6581`, copied at `:3891`), built from 5.18.101's tracked files only (`:6376`, `:4950-4955`), so it never holds the
class, even after a rolled-back run left the file on disk. After the copy, the installed code root (`ICODE_R`,
`:8015`) holds it. The remote mode has no store: it is started with `DN_DATA_DIR` pointing at nothing (`:3907`), after
the copy is dropped (`:3893-3894`). How the pin's file is loaded into a process that has already required `ICODE_A`'s
`lib/SqliteStore.php` (`:3158`) is §R.7.7 item 2.

The remote reading then builds `$links = UcrmQuoteLinks::fromRecords($offer['links'], $offer['kits'])` and
`new UcrmQuoteLines($c, $setting, $links)`, and calls `planLine`, `kitLine` and `installLine` **with whole rows**, in
their stored shape (§R.7.7 item 1). The resolver hands the judge `$setting['id']`, so the organization is the
release's own answer too (F-4). The
script carries no selection, stamp or organization logic of its own, so QL-CA1 compares the release's own answers.
Today it passes a bare name (`:3461-3463`), which would measure nothing under D14 (Map 1 §1.11).

**QL-G1 refuses (NO-GO) on:**
- an unreadable catalogue, through either client (as built);
- a reason the classifier cannot classify (as built). The classifier (`:3398-3414`) is rewritten to §R.4.1's texts, the
  new ones included;
- any request that is not a GET (as built);
- **new: a kind for which uCRM offers nothing that could be linked.** "Plans": no product and service-plan pair passes
  §R.3.1 rules 4–6. "Hardware and installation": no product passes rules 4, 5 and 7. **Unless** the run carries
  `--accept-unpriced`, which the log names. Rules 6 and 7 are judged over the unfiltered service-plan list, as at save
  (§R.3.1).
  - **What it no longer refuses on: an unpriced row.** Before the copy every row is unlinked, so that refusal
    (`:4285-4286`) would refuse every deploy.

**QL-G1 reports:**
- **in the log** (counts only): the catalogue read through each client; for each kind, the usable candidates; the
  offered rows by kind (plans, kits, installation, Fiber apart, `kyc_packages` apart as QL-N7); and the link store on
  the copy, if any: records, active ones, and each active one judged as at use, by reason class;
- **the organization field, measured**, as counts in the log (D-R5). One raw `GET products?limit=500` and one raw
  `GET service-plans?limit=500` per client, through the preflight's own GET-only clients, are made **outside the window
  the "two lists once each" check reads**: after `$asked` is taken (`:3475`), never between `$n0` (`:3433`) and it,
  because that check (`:3477`) is a NO-GO that no flag lifts (`:4270`). They are counted on their own, every one must
  be a GET, and they give:
  - the rows read;
  - the rows carrying a non-null `organizationId`;
  - the rows whose `organizationId` differs from the quoting organization.

  It is a note, never a NO-GO. It turns §D.2's NOT ESTABLISHED into a reading on every deploy;
- **on the 0600 sheet**, per offered item:
  - the plugin row's id and name;
  - under the heading *"uCRM items with the same spelling — a hint only"*: each one's id, name, and "could be linked"
    or the rule that stops it, plus "two products spelled like this" where that holds;
  - any leftover link and its verdict;
  - for a kit row spelled like the installation product: *"the import tool (`tools/import-from-ucrm.php:131-165`) may
    have made this row for the installation product — deactivate it rather than link it"*;
- **on the sheet, once:** the distinct organization numbers seen. When no product row carries the field, the line
  *"uCRM does not say which company a product belongs to; where two products are spelled the same, open each by its id
  in uCRM before linking"*.

  The sheet is **a hint for the admin's picks, never a verdict**: a link may rightly name a product spelled quite
  differently. Names, ids and reasons stay off the log (D-R5).

**QL-N3's D11 count follows the release's retry rule (E-1).** The probe counts, by the release's own rules, the pending
and review applications whose retry cannot price them whatever uCRM holds (`:3188-3195`, `:3196-3213`). As written it
takes those that are not marked and have no quotation, and are either Fiber, or Starlink with a connection other than a
Shifting Connection or an Ownership Change and no "Who installs?" answer. Under E-1 `retryLines` applies the entry rule
first (§R.4.2), so the count becomes every pending and review application with no quotation made whose
`quote_sale_entry` is not exactly `web`. On a book 5.18.102 has never written to, that is every one of them, marked or
not, whatever its connection or type. It is split by status and by what `retryLines` then records: P2 a Cash one with
cash collected (`amount_charged` above 0, to 2 dp), P1 the rest. The Fiber cell goes, because the entry rule comes
before the Fiber check. Its texts are §R.7.5 item 11, and MD11 is its copy (§R.9.3).

#### R.7.2 After the copy — QL-CA1

The installed resolver reads again on a fresh copy, through the same clients. Its verdicts are compared with stage A's
**item by item**: each item's verdict is one keyed hash (D-R3), kept in memory and in the state file, never printed
(below). As built they are one hash over the sorted list of every row's verdict (`:3483-3486`), compared whole
(`:4381`) and kept in memory only (`:4257-4258`), so once stage A had hashed the list no item could be taken out of
it. The verdicts now include the link store's. Before any admin acts they are equal: every item "not linked", or the
same leftover links judged the same way. A difference fails, as built.

**A link made while the deploy runs does not fail it.** From stage B the release is live, and QL-CA1's copy is taken
only after stage V's wait (`GUARD_SECONDS`, `:491`, `:7090`) and the R stages (`qo_after` at `:8164`, `ql_after` at
`:8187`). An admin can write a link in between. So the store is judged as stage A read it, never by `linked_at`:
- **Stage A reads the store through the pin's class** (§R.7.1), so a leftover from a rolled-back run is read, judged
  at QL-G1, and put on the sheet with its verdict. 5.18.101 has no link screen, so nothing can be linked between
  stage A and the copy.
- **What stage A keeps**, in memory and in the state file, never printed: per item, the keyed hash of its verdict;
  per link record, a keyed hash of its id, with its state (active or ended); and `QLG1_APP_MAX`, the highest
  `kyc_applications` id on its copy (§R.7.3). The key is made once by stage A and kept in the state file beside them:
  this run's own key (`MAP_SALT`, `:5460`) is never written, so an `--after-only` could not use it. No link record id,
  name or verdict is written in the clear (D-R5). How the key is kept against `docs/71` D-R3's "never stored", and how
  rule 8 widens the exclusion below, are settled at the derivation (§R.7.7 items 3 and 4).
- **At QL-CA1 the comparison is item by item.** An item whose link records changed since stage A — a record whose id
  stage A did not read, or one stage A read as active that is now ended — is left out, and named in a note with its
  direction: *"linked since stage A: n; ended or relinked since stage A: m; each by name on the sheet"*. A leftover
  that stage A read, and that has not changed, is compared like any other item, and is never reported "linked since
  stage A".
- Every other item must still equal stage A's verdict, item by item, and a difference there still fails.
- `linked_at` is never compared with `COPY_AT`. It is a zone-less local time while `COPY_AT` is epoch (`:6780`), and a
  relink or unlink changes `ended_at` and `ended_at_ts`, not `linked_at`.

QL-CA1's failure text (`:4384`) adds *"a uCRM link saved, changed or removed on the Plans or Hardware screens while the
deploy ran"* to its list of causes. The script's opening line in deploy mode tells admins to make no link, unlink, or
plan or kit save until the run prints its last line.

The step then prints:
- "N plan(s), M kit(s) and the installation are not linked — link them on the Plans and Hardware screens (Uganda) once
  this run has printed its last line, then run --after-only";
- the agents' situation (§R.6), and that the window's web registrations whose screen says "Do not make it by hand" are
  made by an admin with Make the quotation on the Orders card after `--after-only`, never by hand (F-12): before
  pressing it, the admin checks the customer in uCRM, and presses it only where there is no quotation. Registrations
  from the retailer app, the mobile API or a form
  opened before the deploy, and every application saved before it, are quoted in uCRM by hand: 5.18.102 never quotes
  them from uCRM (§R.4.2, E-1).

The rehearsal gains a **leftover-link scenario**: a link store left on the live book by a rolled-back run of this
release, read at stage A through the pin's class, judged at QL-G1 (its verdict on the sheet), and compared unchanged at
QL-CA1. It must never be reported "linked since stage A" (MD6, MD7, §R.9.3).

#### R.7.3 `--after-only`, after linking — QL-LK1 (new)

Read-only, on a fresh copy, through the installed code. Every active, non-Fiber plan and kit the form offers, and the
installation, is judged: **resolves**, **not linked**, or **broken** (by §R.4.1 class). The new classes are included: a
service plan sharing its key, a service plan's own price, 200 service plans, and the installation sharing an offered
kit's product.

- **FAIL when any is not "resolves"**, unless `--accept-unpriced`. That flag is now also accepted with `--after-only`
  (`:994`), and the log names it. The option's own text gives both its meanings (§R.7.7 item 9).
- **FAIL on two active records for one item**: the store is corrupt; R.1.4 never writes two.
- **Notes:**
  - each active record's `linked_by_id` is an admin account now (or not), counted in the local mode on the copy, so no
    account leaves it;
  - links made since the copy, against leftovers. **Every "since the copy" is judged by identity, or by an epoch,
    never by a local date string:**
    - a record that stage A read (its id's keyed hash is among stage A's, §R.7.2) is a leftover, by identity;
    - a record stage A did not read is made since the copy. Its `linked_at_ts >= COPY_AT`, with `COPY_AT` read from the
      state file, is the cross-check, and a record for which the two disagree is a note (MD3's catch under this rule:
      §R.7.7 item 5);
    - never `strtotime()` of the display string: the reading applies no zone, and the script already turns stored
      local strings into epochs with a bare `strtotime` (`:3221`, `:3246`);
    - a state file without stage A's hashes or without `COPY_AT`, or a record without a valid `linked_at_ts`, gives
      *"when it was made cannot be told"* for what it cannot judge. Such a record is counted neither as made since the
      copy nor as a leftover;
  - links on Fiber or inactive rows, and a leftover link from an inactive or Fiber kit row to the installation's
    product, which does not block the installation; a stored sale holding that row is unpriced at use (§R.17, §R.17.1);
  - the products backing two or more offered kit rows (Q16);
  - how many active links name a product that shares its name key with another counted product (§R.2.3);
  - per record ended since the copy (`ended_at_ts >= COPY_AT`; never `ended_at`), the applications quoted under it,
    by Cash and Credit (§R.4.4);
  - **the window's Make-the-quotation count** (E-2; v3's retry list): how many Uganda applications made since the copy
    — an id above `QLG1_APP_MAX`, the highest `kyc_applications` id stage A saw on its copy, read from the state file
    (ids are MAX(id)+1, `SqliteStore.php:699-709`) — are web registrations still to be quoted: a `crm_client_id`,
    `quote_refused` P1, an empty `quote_created`, no `quote_found_in_ucrm`, and `quote_sale_entry` exactly `web` (E-1:
    a record without it is `app`): the button's five conditions (§R.4.8, F-10). A count, in the log and on the sheet,
    with *"use Make the quotation on the Orders card"*; no list of ids, since each card draws its own button.
    - It is never chosen by `submitted_at`, a zone-less local `date('Y-m-d H:i:s')` (`KycService.php:2246`) that the
      script's reading already turns into an epoch with a bare `strtotime` (`:3221`).
    - Beside it, the instruction: check the customer in uCRM, and press Make the quotation only where there is no
      quotation (§R.6).
    - A state file without `QLG1_APP_MAX` gives *"which applications were made since the copy cannot be told"*, and
      no count;
  - **apart from that count**, the window's app-origin registrations (`quote_sale_entry` not `web`), as "quoted in
    uCRM by hand", and those carrying `quote_found_in_ucrm`, as "a quotation was found in uCRM". Neither is ever in
    the count. Counts in the log; ids, never names, on the 0600 sheet (§R.6).
- **QL-CA1's comparison with stage A** becomes a note that names its direction, as D-R3 already does for an
  `--after-only` ("more items priced since stage A": the admin's links). Whether it compares the per-item hashes item
  by item there is §R.7.7 item 3.
- Requests that are not GETs must stay at 0.
- Log: counts only. Sheet: names and ids.

#### R.7.4 What a rollback leaves — QL-RB3 (new)

**The link store stays**: the table `ucrm_quote_links`, with every record. A rollback restores code only (D-R1). The
rollback stage:
- counts the records (log);
- **proves that no file of the restored 5.18.101 tree names `ucrm_quote_links` or `UcrmQuoteLinks`** (grep). This is
  QL-RB1's method, applied to the store;
- notes that `lib/UcrmQuoteLinks.php` is one more added file left on disk (`EXPECTED_ADDED`).

The rollback note (`:8640`) gains two sentences: *"The uCRM links an admin made stay in the store; 5.18.101 never reads
them; a later deploy of 5.18.102 finds them as they were and judges each at use. A KYC registration made since the copy
with no quotation is quoted by an admin in uCRM by hand, after checking that the customer has none."*

- D-R2's refusal is unchanged: it counts unpriced applications still waiting for uCRM.
- The window's Credit registrations (§R.6) were created in uCRM, so they are not "waiting". Under 5.18.101 they stay
  unquoted unless someone quotes them in uCRM by hand. P1 told the agent that for an app-origin one, while
  `P1_UNLINKED` told the agent of a web one that an admin would make it from the Orders card. §R.6's remedy needs
  5.18.102's Make the quotation and its shared implementation (§R.4.8). 5.18.101 has neither and draws no such button,
  so after a rollback an admin quotes the web ones in uCRM by hand, as the rollback note says.
- R7 reads the store's growth as "ok … a live plugin writes", and the `_migrations` ledger is unchanged.
- **Added by §R.17.3:** the rollback stage counts the applications carrying `quote_unconfirmed_at` with no
  `quote_created` (log, a note), and the rollback note gains a third sentence: *"An application on which 5.18.102 could
  not confirm a quotation may already have one in uCRM: check there before quoting it by hand or with retry_quote,
  which in 5.18.101 does not look first."* Every record that can carry the key is a web registration made since the
  copy (`retryLines` refuses any other entry before a POST), and 5.18.101 never reads the key.

#### R.7.5 Operator-facing texts — rewritten by asserted edit (new)

The script's own words still describe matching by spelling. Under D14 they would fire on every deploy, because nothing
is linked right after the copy (§R.6). Neither remedy they give changes a price. A rename keeps `created_at`
(`post_sync.php:439`, `:446`; `:573`, `:584`), so the link and its stamp stay. Renaming a kit also detaches its photo
and spec sheet (§R.5 point 2). Each of these texts is rewritten by asserted edit:

1. **The QL-G1 `--accept-unpriced` note** (`:4285`). The refusal at `:4286` is removed by §R.7.1. The note is rewritten
   to the flag's new meaning: a kind with no linkable uCRM candidate.
2. **The QL-CA1 notes** (`:4383`, `:4391`, `:4395`) **and the header at `:121`.** The remedy becomes *"link it on the
   Plans or Hardware screen (Uganda), as an admin, then run --after-only"*. Fixing uCRM stays only for a linked item
   reported broken, with the remedy its own §R.4.1 reason gives.
3. **`F_UNPR` and `F_STEP3`** (`:8809-8821`) **and "your steps" entry 3** (`:8887-8891`). Entry 3 becomes two
   entries, numbered as §R.6 numbers them: 7 (link once the run has printed its last line) and 8 (`--after-only`,
   which reads QL-LK1, then Make the quotation on the Orders card for the web registrations it counts). Entries 2
   and 4 (`:8884`, `:8892`) keep their numbers, because §R.6
   steps 2 and 4 are still the "Sales Person" attribute and the Quote notes prefix. The uCRM preparation is §R.6 step
   3's list, with no plugin-name rule and no fixed "Professional Installation" name. `F_UNPR`'s "(your steps, 3,
   below)" (`:8811`, `:8812`, `:8818`) points to entry 7.
4. **The header at `:7-8` and `:289-296`, and "what changed" at `:8836`.** Plans, kits and the installation are priced
   through the product (and, for a plan, the service plan) that an admin linked on the Plans and Hardware screens.
   Nothing is matched by spelling.
5. **Every "docs/71 §M step N" pointer gets its explicit target** in §R.6's amended §M, whose steps 1, 2 and 4 keep
   `docs/71`'s numbers and whose step 3 replaces the spelling rule (`docs/71:600-605`):
   - "step 2" (`:123`, `:4295`, `:4305`, `:4306`, `:4409`, `:4412`, `:4414`): unchanged, step 2, the "Sales Person"
     attribute;
   - "step 3" as the remedy for an unpriced item (`:121`, `:4285`, `:4383`, `:4391`, `:4395`, and the comment at
     `:4354`): step 7 (link), then step 8 (`--after-only`). Step 3 (prepare uCRM) only where the remedy is uCRM's own:
     a kind with no linkable candidate (`:306`, `:365`, item 8), or a linked item reported broken (item 2). The
     refusal at `:4286` goes with QL-G1's old rule (item 1);
   - "step 3's 'the deploy's preflight measures all of this'" (`:8922`): step 3, whose `--check` sheet is that
     measurement;
   - "steps 2–4" (`:8923`, `:8943`): steps 2–4, as item 9 rewrites them.
6. **The agents' line** (`:8899-8902`) adds that, until the items are linked, Uganda sales are registered as Credit,
   and that no quotation is made by hand for a web KYC registration whose screen says "Do not make it by hand" (§R.6
   steps 5 and 9). It also says that a registration from the retailer app, the mobile API or a form opened before the
   deploy, and every application saved before the deploy, is quoted in uCRM by hand: 5.18.102 never quotes them from
   uCRM (E-1). The line's other D-2 wording is §R.7.7 item 8.
7. **"After the deploy"** (`:8896-8898`) gains the window's web applications: once the items are linked, an admin
   makes each one's quotation with Make the quotation on its Orders card (QL-LK1 counts them), and none whose screen
   says "Do not make it by hand" is quoted by hand (F-12). Before pressing it, the admin checks the customer in uCRM,
   and presses it only where there is no quotation.
   The app-origin ones, which QL-LK1 lists apart, and every application saved before the deploy, are quoted in uCRM by
   hand (§R.6).
8. **The header's `--accept-unpriced` lines** (`:304-306`) **and the option's own text** (`:362-367`). Both give the
   flag's old meaning, a row the form offers that does not resolve, and its old remedy, "fix them in uCRM afterwards
   (docs/71 §M step 3)". They are rewritten to item 1's meaning, a kind for which uCRM offers nothing that could be
   linked, with §R.6 step 3 (prepare uCRM) before the run and step 7 (link) after it.
9. **The gate-off "your steps"** (`:8921-8928`) **and the not-stated ones** (`:8942-8946`). They give step 3's spelling
   rules, the `"Professional Installation" Product` among them. They are rewritten to: before the setting is turned
   on, §M steps 2–4 by hand — the "Sales Person" attribute, uCRM prepared as step 3 lists, and the Quote notes prefix —
   and the agents told (§R.6 step 5); once it is on, step 7 (link every offered item) and step 8 (`--after-only`). No
   product name is fixed.
10. **"The screens"** (`:8873-8881`). It says that the plan, kit and product-mapping push buttons show the server's
    refusal, and nothing of links. It is rewritten to: on the Plans and Hardware screens, the "uCRM link" column, with
    Link, Change and Unlink for admins, and the installation card; the Sync buttons not drawn; on Product Mapping,
    Auto-Map not drawn, the push buttons (`product_mapping.php:57-58`) showing the server's refusal, and the page
    saying its ids are not used for quotes (§R.2.2, §R.2.4, §R.2.6); the KYC form drawing a linked item with uCRM's
    name and price, and an unlinked one as "not priced in uCRM"; and the Orders card drawing, for an admin, Make the
    quotation on a web registration left unpriced (§R.4.8).
11. **The D11 texts** (E-1): the header (`:12-16`), QL-N3's note (`:4327`) and `ql_d11_say` (`:4237-4249`), the
    after-copy `F_D11` (`:8823-8834`), and the D11 lines of "your steps" (`:8896-8897`; the gate-off ones,
    `:8930-8933`; the not-stated ones, `:8943-8945`). They say that at its retry an application saved before the deploy
    is unpriced when it is a Starlink sale other than a Shifting Connection or an Ownership Change, or a Fiber one, and
    `:4327` that the retry otherwise "takes them from uCRM's prices alone". Under E-1 every application saved before
    the deploy is unpriced at its retry, whatever is linked, with the entry reason: P1 (Credit, or no cash collected) or
    P2 (Cash with cash collected), and its quotation is made by hand in uCRM, or not at all. They are rewritten to
    that, with QL-N3's count as §R.7.1 now makes it.

The rehearsal's assertions that pin the old texts (`scripts/harness/deploy-5.18.102/rehearse.sh:709-712`, `:889`) are
rewritten to the new ones. A negative check is added. **It reads each scenario's log and terminal output only**, never
the 0600 sheet, and **its needle is the old remedy itself, "row's name in the plugin"** (`:4285`, `:4286`, `:4383`,
`:4395`): no scenario's output may contain it. The sheet's per-item heading is worded *"uCRM items with the same
spelling — a hint only"* (§R.7.1). The check is proved to fail on a copy of the script that keeps one old remedy (MD1,
§R.9.3).

#### R.7.6 After the copy, without a session — QL-CA3 (new)

Each earlier sensitive surface has an after-copy probe without a session: BA4 (`:6904-6913`), CA4 (`:6914-6926`), Q-CA4
(`:6933-6941`) and Q-CA5 (`:6942-6953`). D14 makes the Plans and Hardware screens the place where an admin decides what
cash an agent collects, so they get one too. It sits beside Q-CA5, inside the same `$PLUGIN_BASE` branch, and is named
in each skip note that lists BA4 to Q-CA5: below Q-CA5 (`:6956`), and the two for a public address the server cannot
reach or that hairpins (`:6820`, `:6821`). It sends GETs only, **because the deploy never POSTs to a write action**. The
global CSRF gate is no reason: it is skipped for any POST that carries `cb_action` or one of nine tab-action fields
(`includes/post_handlers.php:102-110`, §R.2.1). A GET cannot reach a POST handler.

1. **GETs without a session**, with the gate in whatever state is installed, to:
   - `?page=dashboard&tab=subscription_plans`;
   - `…&ucrm_link_plan=<an offered plan id from the offer file, else 1>`;
   - `?page=dashboard&tab=hardware`;
   - `…&ucrm_link_hw=<a kit id, else 1>`;
   - `…&ucrm_link_install=1`;
   - `?page=dashboard&tab=applications`, the Orders card (§R.4.8).

   Each must answer 302 with `page=login` in Location. The count of the picker panel's own marker (its
   `ucrm_link_save` action value), and on the Orders page of Make the quotation's (its `kyc_make_quote` action value),
   must be 0, with no "Fatal error" or "Parse error". This is CA4's tuple form (code|where|marker|fatals). Where the
   plan id comes from is §R.7.7 item 7.
2. **A text read of the installed `includes/post/post_sync.php`**, with comments stripped as Q-CA4 does. For each of
   the `ucrm_link_save`, `ucrm_link_clear` and `kyc_make_quote` blocks it checks that:
   - the block is POST-only;
   - it sits inside the `QuoteOrganization::setting(...)['on']` condition;
   - it calls `$auth->requireAdmin()`, then the fresh re-read, written in the action itself
     (`$store->findOne('retailers.json', 'id', …)`, §R.2.1), then
     `csrfCheck() && !CustomerSession::crossSite() && BankGate::fromOwnPage('POST')`, before any `UcrmQuoteLinks` or
     `makeQuotation(` call or store write. `makeQuotation` is a method of the service's instance (§R.4.8), so the read
     looks for the call, not a class name.

   The same read of the pin's file from Git is its control, as QL-RB1 does. The pin must name each, or the check
   refuses rather than passes.
3. **Optional:** Q-CA4's text read is extended to `auto_map_products` and `set_product_mapping`
   (`api_products_admin.php:19-20`), whose new Uganda 409 (§R.2.6) comes after that gate.

The check's own text says it is direct evidence beside R1 (`:7266`) and Q-CA1's lint (`:7293`), not a replacement for
them. The dashboard's sign-in gate is `public.php:1064`, which D14 does not touch.

#### R.7.7 Settled at the derivation (new, E-6)

The third check left deploy-script mechanics that the release does not decide. Each is recorded here with the
checker's suggested fix, and each is decided in the deploy script's own review rounds, against `661bd5c7`. None changes
the release, or a rule of this design.

1. **Rule 8's offer-row shape, and its copy.** The offer writer's `$ql_row` (`:3170-3176`) casts `id` to a string and
   carries a kit's title as `name`; §R.7.1 adds four fields to it and never redefines it. E-5 fixes what `fromRecords`
   takes: kit rows in `kyc_devices`' stored shape, an int `id` and `title` among them (§R.1.5). Rule 8 matches a
   record's `row_id` against that `id`, and `kitLine`'s reasons name the `title`. The remote reading also hands the
   offer's plan and kit rows to `planLine` and `kitLine` "with whole rows" (§R.7.1), and the plan rows pass through the
   same reshape. *Fix:* the offer carries the plan and kit rows in their stored shape, pinned; and a deploy-script copy
   that keeps `$ql_row`'s reshape for them is shown caught by the rehearsal (a leftover installation link to an offered
   kit's product: QL-G1's verdict must be rule 8's).
2. **D-7's loading.** Stage A's local reading has already required `ICODE_A`'s `lib/SqliteStore.php` (`:3158`). Under
   E-5 the pin's `lib/UcrmQuoteLinks.php` require()s no sibling, so it can be required into that process with no class
   declared twice, and `fromStore` is handed the setting the local mode builds from its `qorg` (`:3157`). **The deploy
   loads the pin's file by that path** (F-6): `<the pin's tree>/lib/UcrmQuoteLinks.php`, the file the pin's
   `UcrmQuoteLines.php` names as `__DIR__ . '/UcrmQuoteLinks.php'`, never a copy placed elsewhere. So the local
   reading and the remote reading, which gets the class through the pin's resolver, load the same file, and in any
   process that loads both, `require_once` loads it once. *Fix:* state the require and the setting in the script, and
   have the leftover-link scenario prove that the local reading loads the pin's class.
3. **D-8's key against D-R3.** §R.7.2 keeps stage A's key in the state file beside the hashes. `docs/71` D-R3
   (`docs/71:796-799`) and the script's own texts (`:118-119`, `:4257-4258`, `:4352-4353`) say the key is "never
   printed and never stored". With the key beside the hashes their only protection is the file's mode (`umask 077`,
   `:460`): small integers such as record ids can be recovered by anyone who can read it. Also not stated: whether
   `--after-only` compares the per-item hashes item by item, or reports counts "as D-R3 already does" (§R.7.3).
   *Fix:* record D-R3's amendment and the file's mode as the protection, or keep the key in a separate 0600 file; and
   state what `--after-only` compares.
4. **D-8 and rule 8.** QL-CA1 leaves out only the items whose own link records changed since stage A. Under rule 8 the
   installation's verdict depends on every offered kit's active link, and each kit's on the installation's. Take a
   leftover installation link, and a kit linked during the run to the same product: the installation's verdict
   changes while its records do not, so it is compared, and QL-CA1 fails. The reverse case does the same. *Fix:* when
   any kit or installation record changed, also leave out the installation and every offered kit whose product is a
   changed record's old or new product; add a rehearsal case.
5. **MD3 under D-5.** QL-LK1 decides "since the copy" by stage A's per-id hashes; `linked_at_ts >= COPY_AT` is only a
   cross-check whose disagreement is a note. A copy that runs `strtotime()` on `linked_at` still reads MD3's two cases
   right by identity and differs only by that note, so MD3's stated catch passes it. *Fix:* MD3's scenario asserts
   that no disagreement note appears.
6. **ML37's second half.** "QL-LK1 reports it 'resolves'" is the deploy script's behaviour (for instance `fromRecords`
   given no kit rows), and no section defined "the rehearsal's QL-LK1 case". *Fix:* a deploy-script copy, an MD row,
   with its scenario: the installation linked to an offered kit's product, which QL-LK1 must report broken by rule 8.
7. **QL-CA3's plan id.** The offer file lives in `qo_run`'s folder, which is dropped at its end (`:3935`), before the
   after-copy branch where QL-CA3 runs (`:6866-6956`), so only the fallback `1` can run. *Fix:* state where the id
   comes from, or use `1`.
8. **The texts §R.7.5's list misses**, false under D14. The D11 texts are settled by E-1 (§R.7.5 item 11); these are
   not:
   - the header (`:114-115`) and QL-N7's note (`:4346`) say the `kyc_packages` rows are "priced" on the apps' paths,
     and so does `docs/71` §Q D-R4 (`docs/71:809`); §R.2.5 makes them never priced;
   - the header (`:118-120`): QL-CA1's hash "never stored", and `--after-only` "counts only" (item 3);
   - the agents' line (`:8899-8903`): "a Credit Starlink New Connection from them gets no quotation". Under D-2 every
     Credit sale from the apps, and from a form opened before the deploy, gets none. The same sentence in `docs/71` §M
     "What changes" (`:612`) and §E.3 (`:210`) is release documentation, amended with the release, not here (§R.11,
     F-14);
   - the order in which "your steps" prints entries 2, 7, 8 and 4 (D-9) is not stated.

   *Fix:* add each to §R.7.5's list with the D14 wording, and state the order.
9. **The two meanings of `--accept-unpriced`.** §R.6 step 8 and §R.7.3 give the flag a second meaning with
   `--after-only`: items left unlinked on purpose, with `:994`'s guard widened. §R.7.5 items 1 and 8 rewrite the
   option's own text (`:362-367`) and the header's lines (`:304-306`) with QL-G1's meaning only. *Fix:* the option's
   text gives both meanings.
10. **MD8's fixture.** Its application must be saved before stage A's copy of the book, so that its id is at or below
    `QLG1_APP_MAX`. One with P1 planted between stage A and the code copy has a higher id, and the correct script
    counts it too. *Fix:* state the fixture's timing in MD8's scenario.

### R.8 South Sudan, and Uganda with the gate off (decision 8)

**Byte for byte 5.18.101's.** These are emitted, handled or read **only where `QuoteOrganization::setting(...)['on']`**:
- the link column, the picker panels, the installation card, the hidden manual field, the not-drawn Sync buttons, and
  the Product Mapping and Auto-Map refusals, with Product Mapping's header, notice, badges and column label (§R.2.6);
- the two POST actions and the delete hooks. With the gate off their blocks are skipped whole, so an unknown action
  behaves as in 5.18.101;
- every read of `ucrm_quote_links.json` (§R.1.5);
- the form's `k` and `data-link`, `ucrm_links_seen` and `CASH_RELINKED` (§R.4.7);
- the entry rule, the form's `ucrm_quote_form` marker and `quote_sale_entry` (§R.4.2), and `P1_UNLINKED` (§R.4.1);
- the shared implementation, with its claim and its check for an existing quotation, `quote_found_in_ucrm` and the
  Orders card's line for it, the Orders filter `quote_link`, and `quote_link_ids`, `quote_made_by_id` and
  `quote_made_via` on the retry paths (§R.4.4, §R.4.8); and `retry_quote`'s fresh re-read of the account (§R.4.8);
- `UcrmQuoteLinks::offeredKit` in the form's kit loop, which is inside the form's gated block (§R.3.1 rule 8);
- Make the quotation: its button on the Orders card and its POST action `kyc_make_quote`. With the gate off the button
  is not drawn and the block is skipped whole (§R.4.8).

`UcrmQuoteLines` and `UcrmQuoteLinks` are never constructed there (§D, as built).

**Leftover links are ignored.** A link store left by a period with the gate on, or by a rolled-back deploy, is never
opened while the gate is off. The journeys prove it (§R.9).

**Files D14 does not touch:** `public.php`, `includes/post_handlers.php`, `includes/navigation.php`,
`lib/SqliteStore.php`, `main.php` and `lib/GoogleDriveBackup.php` (`test_bank_boundaries` A); `webhook.php`,
`lib/PublicPriceFeed.php` and `lib/QuoteOrganization.php` (§L.4, `test_quote_from_ucrm` I). `lib/BankGate.php`,
`lib/CustomerSession.php` and `lib/RetailerAuth.php` are read, never changed (§R.2.1). `includes/api/api_retailer.php`,
`api/index.php` and `includes/post/post_kyc.php` stay byte for byte: the entry point is read from the web form's own
marker, so no caller passes it (§R.4.2, §R.11).

There is no migration, and no `$FLAT_TABLES` entry (§R.1.4).

**PHP 7.4 throughout:**
- typed properties and `fn` are fine, as the class already uses (`UcrmQuoteLines.php:86-90`, `:230`);
- not allowed: `match`, `?->`, named arguments, union or `mixed` types, constructor promotion, and the PHP 8 string
  functions.

### R.9 Tests (decision 9)

#### R.9.1 `tests/test_quote_from_ucrm.php` and `tests/quote_from_ucrm/`

**The fixtures** (`tests/quote_from_ucrm/_lib.php:165-176`, `tests/fixtures/fake_ucrm_kyc.php:156`, `:168-171`):
- The default seed gains `ucrm_quote_links.json`: the plan row → product 12 and its service plan; the kit row →
  product 55; the installation → product 70. Each record carries the stamps of the seeded rows, which gain
  `created_at`.
- `ucrm_product_id` stays 99 and 98, deliberately wrong. A7's premise holds: a stored id is never read, and the link
  decides.
- **At least one seeded plugin row is renamed so that it is spelled unlike its linked product.** A product spelled
  exactly like that row, but not linked, is added. Every priced case then proves the link, not the spelling, decided.
- **Every fixture on the web path says so** (E-1). Form posts that are to be web carry the `ucrm_quote_form` marker, and
  every stored application C and L retry or quote as web carries `quote_sale_entry` `web`, seeded explicitly. A stored
  fixture without it is the pre-deploy case, which every retry leaves unpriced.

**Rewritten on purpose, each with its new expected outcome:**
- **A:**
  - A5 (an empty key matches nothing) becomes "a row named only 'Starlink' is priced through its link";
  - A6 (duplicate spelling → unpriced) becomes "two products spelled alike: the linked id is quoted";
  - A7 as above, plus "the spelled-alike, unlinked product is never used";
  - A8 and A9 by the linked service plan: the 1-month period, price equality, and the product and plan spelled alike;
  - A11 is the kit rule, by the linked product's name;
  - A4 and A15's reason texts follow §R.4.1;
  - A0 and A10–A13 run through links;
  - **new A21–A26:** each §R.4.1 reason (not linked, row replaced, two active, other organization, product or plan
    unlisted, archived, inactive, re-priced plan, taxable, spelled apart); a `kyc_packages` row never priced by a plan
    link with its id; a Fiber row linked and still unpriced; the installation only by link (a product named
    "Professional Installation" but unlinked is not used); a kit re-priced in uCRM quoted at the new price; the page
    cache's shape version;
  - **new A27–A31, the summary's list (§R.3.1 rules 6–7).** Each case is paired with a control in which the duplicate is
    renamed, or the list shortened, and the item resolves:
    - a counted service plan sharing the linked plan's key → unpriced, with the restated `:232` reason; the same with
      an archived duplicate, and with another organization's;
    - a kit, and the installation, spelled like an archived or inactive plan → unpriced;
    - a plan whose `planPrice` is not its 1-month price → unpriced;
    - a 200-row service-plans list → every plan unpriced;
    - the page cache's shape version covers `plan_keys`;
  - **new A32, rule 8:** the installation linked to an offered kit's product → both unpriced, with the rule-8 reason.
    Control: the same link on an inactive or Fiber kit row, or on one with another stamp, leaves the installation
    priced;
  - **new A33, the entry rule.** The seed: a plan linked under id N, a `kyc_packages` row with id N, a Starlink Shifting
    Connection sale on Credit.
    - A post without the `ucrm_quote_form` marker → unpriced, no quote.add, still registered.
    - The same post with the marker `5.18.102` → priced through the link (the control).
    - The marker with any other value (`5.18.101`, an empty string, an array) → unpriced.
    - An id only in `subscription_plans` (the mock case), no marker → unpriced.
    - An id in neither table → `no_plan`.
    - A post with a `customer_id` and no marker, as the mobile API sends (`handleExisting`) → unpriced.
    - `quote_sale_entry` is stored from `resolve()`'s `$res['entry']`, for a priced and an unpriced sale alike.
    - `saleFromApp` reads it back: exactly `web` is web; absent, `app`, `''` or any other value is app (E-1).
- **B:** the reason strings (`B_submission.php:43`, `:51`); the unpriced cases made by **unlinking**, and by deleting the
  linked product, instead of by removing the product spelled like the row (`:40`, `:68`, `:117`); `CASH_UNPRICED`'s new
  tail; `quote_link_ids` stored. **E-3:** a Cash post without the marker, and one from a form opened before the
  update, are refused by `CASH_UNPRICED` carrying the entry reason, which ends at "so it is not priced from uCRM": the
  text holds no "make the quotation". The same holds for the `kyc_packages` reason, through a hand-made post carrying
  the marker and a package-only id. Control: the Credit twin of each records P1, whose own text says "Make the
  quotation in uCRM". New cases (§R.4.7):
  - a relink at the same price between drawing and submitting → `CASH_RELINKED`, nothing written. Controls: no relink →
    accepted; Credit with a relink → registered;
  - two relinks whose prices cancel out → refused;
  - a web Credit sale with an unlinked item, and one with the installation unlinked → P1 recorded with `P1_UNLINKED`'s
    text. Controls: the same sale without the marker → P1's own text; a web sale whose linked item is broken
    (archived, say) → P1's own text.
- **C:** every marked-lines fixture (C0, C3–C6, C8) seeds `quote_sale_entry` `web` (E-1). **Rewritten on purpose by
  E-1:** C2's control, a 5.18.101 Credit Shifting Connection stored without the key, is now unpriced at its retry with
  the entry reason, and the same seeded `web` resolves by link; C8's Fiber case seeds `web`, so its 409 still names
  Fiber; C9's submission carries the marker (the hand-made web existing-customer post, §R.4.2), so its retry resolves
  by link, and without the marker it stays unpriced. New cases:
  - **every application saved before the deploy is unpriced at its retry** (E-1). With every item linked, at the cron
    retry and at `retry_quote`, each stored without `quote_sale_entry`: a Credit Starlink New Connection, a Shifting
    Connection whose `package_choice` is both a linked plan's id and a `kyc_packages` id, and a Fiber sale → P1 with the
    entry reason; a Cash
    one with cash collected → P2; a Cash one with none → P1; and a hand-seeded marked one, its lines confirmable → P1
    with the entry reason, since the entry rule comes before `confirmLines`. Controls: each seeded `web` is resolved by
    link, or quoted with its marked lines (a Cash one only if its lines total `amount_charged`); a value other than
    `web` (`app`, `''`, `WEB`) is unpriced like none;
  - relinked between submission and retry → P2 (Cash) or P1 (Credit);
  - the product re-priced or made taxable → the same;
  - an unmarked window registration (`web`) quoted by Make the quotation once linked, and on a second copy by
    `retry_quote`. It is paired with the same registration quoted by hand in uCRM first → 409 (the guard's flash at the
    button), with 0 POSTs, 0 PATCH …/send, no `quote_created`, no `wa_quote_pending`, and only `quote_found_in_ucrm`
    recorded. Control: no quotation in uCRM → exactly one quotation;
  - an existing customer with only an older quotation → still quoted once. The fixture seeds `quote_sale_entry` `web`
    explicitly (E-1): a stored application without it is `app`, and so is every existing-customer sale the form or the
    apps make (§R.4.2). It is a Shifting Connection or an Ownership Change, or carries `install_by`, so its retry does
    not stop at the installation answer (`UcrmQuoteLines.php:362-366`);
  - the quotation list unreadable → 409, nothing written;
  - another client's quotation in the answer → ignored;
  - an app-origin unpriced application stays unpriced on `retry_quote` and on the cron retry (`KycCrmSync.php:364`)
    after the plan is linked, and the Orders card draws no Make the quotation for it;
  - **added by §R.17:** the cron retry of an unmarked web application stores `quote_link_ids` (C26, T3); an existing
    customer's sale without the marker is stored `app` with its sale kept, and `retry_quote` answers 409 with the
    entry reason and no POST (C9, T7); a kit row deactivated with its link to the installation's product left in
    force, in a stored cart and in marked lines, through the cron retry, `retry_quote` and Make the quotation, is
    unpriced with rule 8's reason, never two lines with one `productId` (C28, D14-R8-INACTIVE-KIT); a Throwable after
    the POST — the step-5 write failing, or one inside `postQuote` after its POST — never answers "No quotation was
    made", and a second press is answered `found` with still one POST (C22, D14-MQ-THROW-AFTER-POST); the found card
    draws its line and not the refusal text (C17, D14-UI-4);
  - **added by §R.17.1 and §R.17.2:** uCRM makes the quotation and its answer is lost (a 504): at the button and at
    `retry_quote` the `error` answer says "did not confirm … it may have been made", never "did not make"; the card
    says "Making the last quotation did not finish" (§R.17.4's heading), never "No quotation was made", and still draws the button (the
    control: before the press it said "No quotation was made"); a second press is answered `found`, and the card then
    draws the quotation found (C22 (c)). uCRM refuses the POST: `quote_unconfirmed_at` is recorded, an unreadable list
    keeps it, and a press whose guard finds none removes the key, then makes the quotation or records its own refusal,
    which the card shows (C22 (d));
  - **added by §R.17.3:** a Throwable inside `postQuote` after its POST records the key, and the card says "did not
    finish" (C22 (b)); a company-check refusal records none (C19); an agent's card says "did not finish" with a
    sentence that names no button, and an admin's names Make the quotation; a key beside `quote_created` draws no box;
    a press on one application removes only its own key; `retry_quote`'s `error` on an application the button does
    not apply to draws the box with no button and a sentence naming none; and with the key written while `retry_quote`
    waited at its company check (the proxy's `hold_client`), its guard finding none still removes it, as when the key
    was there at its read (C22 (d)).
  - **added by §R.17.4:** an `\Error` after the POST records the key as an `\Exception` does, and the catch's own key
    write failing is logged with ids only while `postQuote`'s Throwable goes on (C22 (b)); the `error` answer's own
    key write failing leaves the answer `error`, logged, and a second press finds the quotation; the `found` answer
    leaves the key alone (C22 (c)); a key held from an earlier press, then a lost answer or a Throwable after the
    POST, is recorded afresh; a failed removal of the key stops the press with no POST and nothing changed (C22 (d)).
    Every card expectation needs the heading and its first sentence, and the card drawn to its bottom row; C19's
    `retry_quote` half is labelled as `retry_quote`'s own refusal, which comes first.
  - **added by §R.17.5:** a press in flight (its claim live, its POST held), with or without a key from an earlier
    press: the admin's and the agent's cards say "Make the quotation was pressed and has not finished", never "No
    quotation was made" nor "did not finish"; then the press makes the quotation; a claim 600 s old draws the box and
    one 901 s old the stored refusal (C22 (e)). The admin's sentence is matched whole. When `postQuote`'s Throwable is
    an `\Error` and the key write's a `RuntimeException`, the caller's line names the `\Error` (C22 (b)); the `error`
    answer's key write failing by an `\Error` as by an `\Exception` (C22 (c)). "The card drawn" is counted on the
    card's own bottom-row element, never the stylesheet's rule for it; C19's `retry_quote` half compares the whole
    card.
  - **added by §R.17.6:** a press that dies after its POST (a fatal error at `postQuote`'s `quote_id` write, the
    router's `fatal_after_post`): its claim left and no key, the cards say making a quotation has not finished; with
    the claim past `QUOTE_CLAIM_SECONDS`, "did not finish", never "No quotation was made"; then a press finds the
    quotation, still one POST (C22 (f)). A claim 901 s old draws "did not finish"; one 870 s old draws the in-flight
    box while a press is answered `claimed`; the in-flight box outranks the key's box, yields to the found line, and is
    never drawn beside a made quotation (C22 (e)).
- **C, Make the quotation** (E-2, §R.4.8). Each refusal leaves the store unchanged and no request in the fake uCRM's
  log, except where stated, and each has its accepted control:
  - **the button's gate:** drawn on Uganda with the gate on, for an admin, for an application that meets the five
    conditions (`quote_sale_entry` `web`, a `crm_client_id`, `quote_refused` `P1`, no `quote_created`, no
    `quote_found_in_ucrm`); not drawn when any one of the five fails (entry `app`, entry absent, no client, P2, P3, an
    R-code, `quote_created`, `quote_found_in_ucrm` recorded), for a non-admin, or with the gate off (`ss`, `ug_off`: the
    card byte for byte, G8);
  - **admin:** `kyc_make_quote` refuses a non-admin with 403, and refuses a session whose cached record says admin
    while the stored row is demoted, inactive or deleted, or whose `findOne` throws. Control: a fresh admin is
    accepted. `retry_quote`'s gated path refuses the same stale sessions (demoted, inactive, deleted, a read that
    throws) with 403, and accepts the same control (§R.4.8, §R.16);
  - **the own-page check:** L's refusals (i)–(v), each with its accepted control, sent to `kyc_make_quote`;
  - **only the id** (F-2): a stored web, P1 application whose client is N, posted once in each run with one field more
    — `crm_client_id` naming another client, `amount_charged`, `quote_sale_entry` and `items` — is refused each time
    with step 4's flash, nothing written and no request sent. A non-digit `app_id` is refused the same way. A post for
    an application stored as `app` is refused by the conditions. **The positive case:** the same application, posted
    with `action`, `_csrf` and `app_id` alone, sends exactly one POST, to `clients/N/quotes` with N the stored
    `crm_client_id`, and its lines are the ones the store's links resolve: their `productId`s are the linked products'.
    For a Cash application with cash collected, the Cash rule reads the stored `amount_charged`: a stored amount equal
    to the lines' total → quoted; one that differs → P2, nothing posted;
  - **the guard:** a quotation for the client in uCRM made after `submitted_at` → 409 at `retry_quote` and the guard's
    flash at the button, with 0 POSTs, 0 PATCH …/send, no `quote_created` and no `wa_quote_pending`. Only
    `quote_found_in_ucrm` is recorded, and nothing else (F-10); the card then shows its line and no Make the
    quotation. The list unreadable → refused at both, nothing written at all;
  - **the shared implementation, used by both entry points** (F-1): a text read shows that `retry_quote`'s gated block
    and `kyc_make_quote` each call `->makeQuotation(`, and that neither posts, reads back or sends a quotation itself on
    the gated path. The same web application is quoted once through each entry point, on two copies of one store, each
    against its own fresh fake uCRM. The two must leave:
    - the same writes on the application: the same keys with the same values, the times apart, and `quote_made_via`
      apart, which names its entry point (F-13);
    - the same POST and PATCH paths in the fake uCRM's log;
    - the same posted lines.

    **Not compared:** the GET requests and their count, which differ because `retry_quote` makes its own company check
    first; and `adminNotes`, which differ by entry point by design. With `kyc_quote_send_via_crm` off, neither PATCHes
    …/send;
  - **the company check once** (F-1): on `retry_quote`'s gated path the fake uCRM's log holds exactly one
    `GET clients/N` before the POST, `retry_quote`'s own; on the button's, exactly one, `postQuote`'s. A client filed
    under another organization is refused at both, with no POST;
  - **the Cash rule:** a web Cash application with cash collected whose lines total `amount_charged` → quoted; one
    whose lines do not → P2, nothing posted;
  - **no token needed:** with `crm_auth_token` unset, Make the quotation quotes through the plugin's own client, while
    `retry_quote` still answers 400 (`api_cron_debug.php:1050-1051`);
  - **a double press** (F-9): two requests for one application, the second sent while the fake uCRM holds the first's
    POST → exactly one quotation. The second answers `claimed`: the claim's flash at the button, and 409 *"another
    quotation is being made for this application"* at `retry_quote`. Controls: after the first returns, a further
    press is refused by the conditions (`quote_created`); a claim seeded 600 s old still blocks; one 901 s old does
    not;
  - **the claim's removal** (F-9): after every answer — `made`, each 409 and `error` — the application holds no
    `quote_make_claim_ts` key, and after a refusal its record equals the one before the press, key for key. A press
    answered `claimed` removes nothing: the first request's claim is still there while its POST is held. A Throwable
    raised inside the method after the claim → the claim is gone, the button shows its Throwable flash, and
    `retry_quote` answers 500 with its fixed text. A removal that throws → logged with ids only, the answer unchanged;
    the claim it leaves, aged by hand, blocks a press at 600 s and none at 901 s;
  - **each kind's answer** (F-7): `made`, `found`, `unreadable`, `refused`, `claimed` and `error`, each produced once,
    give the button's own flash for that kind, and `retry_quote`'s own answer and code. Four of them are 409, and no
    two kinds share a flash;
  - **escaping** (F-8): the fake uCRM answers the POST with the id `"77<b>x</b>"` → the flash reads "Quotation #77" and
    holds no `<b>`; a found quotation numbered `<b>Q1</b>` → the card draws it escaped. Control: an ordinary id and
    number are shown as they are;
  - **`quote_error` cleared** (F-10): a web application carrying `quote_error` from an earlier failed POST, quoted
    through each entry point → `quote_error` null;
  - **who made it** (F-13): a quotation made through the button records `quote_made_by_id`, the admin's id, and
    `quote_made_via` `orders_card`; one made through `retry_quote` records `retry_quote`. Neither key is written on
    any refusal, by the cron retry, or with the gate off;
  - **`P1_UNLINKED` at all three sites:** submission new (`handleNew`: a web Credit sale with an item unlinked, and one
    with the installation unlinked), submission existing (a hand-made post carrying the marker and a `customer_id`,
    through `handleExisting`, with an item unlinked), and `retryLines` (a web registration still unlinked, retried by
    the cron retry, by `retry_quote` and by Make the quotation) → `quote_refused` P1 with `P1_UNLINKED`'s text.
    Controls at each site: the same without the marker, or stored without the key → P1's own text; an item linked but
    broken → P1's own text.
- **F:** F0, F4 and F5 go through lead → plugin row by key → that row's **link**. New cases:
  - two rows spelled alike with different links → the picker answers per row, and the lead does not pre-fill;
  - a Fiber plan row listed without a price.
- **G:**
  - G3 stays (no `ucrm_product_id` written by the saves). It gains: on Uganda `save_hardware` ignores
    `hw_ucrm_product_id`, and the plan and kit saves never touch the link store;
  - G4 gains `auto_map_products` and `set_product_mapping` → 409 on Uganda (Q7's default), with the South Sudan control;
  - on Uganda with the gate on, Product Mapping's page contains neither "Quotes will reference real products"
    (`product_mapping.php:140`) nor, matched case-insensitively, "quotes reference real billing items" (its header,
    `:52`), even with a seed where every row carries `ucrm_product_id`. Control: with the gate off, the page carries
    both;
  - G8's byte-for-byte comparisons of the gate-off trees extend to the Plans, Hardware and Product Mapping pages,
    rendered with a link store present, and to the Orders page, rendered with applications carrying
    `quote_sale_entry` `web` and P1, on which no Make the quotation is drawn, and with one carrying
    `quote_unconfirmed_at` (§R.17.2; the control: on Uganda that card says "did not finish", §R.17.4), and with one
    whose press is in flight, a live claim (§R.17.5; the control: on Uganda that card says the press has not finished).
- **H:**
  - a linked row is drawn with uCRM's name in the text, `data-name` and `data-title`; an unlinked row with the plugin's
    name and the reason;
  - H7's `data-title` assertion is rewritten to that;
  - the installation label comes from its link;
  - the Cash note's tail;
  - `data-link`, `ucrm_links_seen` and the `ucrm_quote_form` marker are drawn only with the gate on;
  - an active kit row typed `FIBER` is drawn with the Fiber cell, and one stored with `is_active` 1 and no `type` as a
    kit (F-5);
  - in the browser check: step 3, step 5 and the order summary show uCRM's names.
- **I:** the declared files gain `lib/UcrmQuoteLinks.php` and the new section's file. The kept-file list (I2,
  `I_boundary.php:41`, `:114`) is unchanged: `includes/api/api_retailer.php` stays in it, and `api/index.php` is not
  changed either (§R.4.2). The no-migration check is unchanged. An inventory proves every `UcrmQuoteLines`
  construction site passes a link store, and that `lib/UcrmQuoteLinks.php` holds no `require`, `require_once`,
  `include` or `include_once` (E-5). It also proves that `lib/UcrmQuoteLines.php` holds
  `require_once __DIR__ . '/UcrmQuoteLinks.php'` (F-6), and that `kyc_form.php`'s kit loop calls
  `UcrmQuoteLinks::offeredKit` and compares no `type` with `fiber` itself (F-5).
  **I6b** (§R.17, G3): `SAFETY.md`'s 5.18.102 entry names `ucrm_quote_links`, Make the quotation, `--after-only` and
  section L, and neither text it replaced. Its control is an entry written out in the test, holding the two replaced
  texts as 5.18.102's first cut spelled them, so the check's `gone` half is shown able to fire (§R.17.1, FX-tests-2).
  That the four `need` texts are absent from the first cut was measured once, not kept (§R.17.2, CK-tests-2).

**New section L (the links):**
- **The store:** the record shape; one active record per item; history kept; written under `withLock`; a relink to the
  same product a no-op only under §R.1.4's four conditions.
  - **Relinking to the same product in each of three states:** (a) the row deleted with the gate off and re-added under
    the reused id, so the stamp differs; (b) the quoting organization changed, with the fake uCRM returning no
    `organizationId`; (c) two hand-seeded active records. Each case must show that:
    - a new record is written;
    - every earlier active record is ended with `relinked`;
    - exactly one active record remains;
    - the item resolves;
    - the flash is not "already linked";
    - for (a), the picker pre-selected nothing.

    Control: a current, resolving link relinked to the same product stays a no-op, with no new record and no ended
    record.
  - **L-race:** the fake uCRM's products handler commits a write to `plugin.sqlite3` (another table, another
    connection) while it answers. The save either succeeds with exactly one active record, or refuses with the
    try-again text and no change to `ucrm_quote_links`.
  - **L-row-changed:** the row is deleted and re-added (same id, new stamp) between the validation and the lock. The
    save refuses and writes nothing.
  - **L-two-active:** two active records for one item, one of them naming the chosen product. A save leaves exactly one
    active record, and the item resolves.
  - **Time:** with the process zone set to Africa/Kampala, `linked_at_ts` lies within a few seconds of `time()` at the
    save, and a relink writes `ended_at` and `ended_at_ts` together on the record it ends, in the same lock. A
    hand-seeded record with `ended_at_ts` set and `ended_at` null is ended: the date strings are never read by the
    active rule.
  - **The judge:** for every link state of §R.4.1 (not linked, row replaced, two active, other organization, ended
    only, relinked), and for rule 8 (the installation linked to an offered kit's product; as the control, the same link
    on an inactive, Fiber or other-stamp kit row), `fromStore()` on a seeded store and `fromRecords()` on the same
    records and kit rows, as the offer file carries them, give identical `planLine`, `kitLine` and `installLine`
    answers. `fromRecords()` creates no table: `sqlite_master` is unchanged.
  - **The contract (E-5):** `fromStore($store, $setting)` with `$setting['on']` false opens nothing, and
    `sqlite_master` is unchanged; `records()` returns every record whole, ended ones included, with every key; the kit
    rows `fromRecords()` is given in the judge case are `kyc_devices`' stored rows, an int `id` and `title` among them.
  - **The organization comes from the caller** (F-4): one judge, from `fromStore` and from `fromRecords` alike, asked
    for the same item with the record's organization id and then with another, answers "resolves" and then the
    other-organization reason. A `fromStore` judge built under one setting and asked with another id answers by the id
    it is asked with. Neither constructor keeps an organization.
  - **Offered, as the form offers** (F-5): rule 8 blocks an installation link to the product of a kit row stored with
    `is_active` 1 and `type` `general`, and of one with no `type`. It does not block for `type` `FIBER` or `Fiber`, nor
    for `is_active` 0 or `'0'`. `UcrmQuoteLinks::offeredKit` gives each row the same answer as the form's kit loop
    draws (a kit cell, or the Fiber cell; H).
  - **Rule 8's kit half at save, any kit row** (L8, §R.17.1, FX-money-1): an inactive kit row's save to the
    installation's product is refused, with a text naming no deactivation, and its picker leaves that product
    disabled; so is the crosswise case inside the lock. Control: the same inactive row is linked to another product.
  - **L-rule8-crosswise:** while the installation's save reads uCRM, another connection commits a kit link to the same
    product. The save refuses inside the lock with rule 8's text and writes nothing: the re-check built
    `fromRecords()` over the lock's records and its `findOne` rows. Control: with no crosswise write, the installation
    is linked.
  - **One text for two active records (E-4):** with two hand-seeded active records, the link column, the KYC form's
    reason and the resolver's answer all carry §R.4.1's text, and no other. The link column also shows both records'
    product ids, each as its `#id 🔗` link, with Change and Unlink (F-11).
- **The two actions:**
  - 403 for a non-admin, absent with the gate off;
  - **the fresh account record:**
    - a session whose cached record says `is_admin` true, refreshed seconds ago, while the stored row is demoted →
      refused at once, store unchanged;
    - the same with `is_active` false;
    - the row deleted, so `findOne` returns null and `currentRetailer` falls back to the cache → refused;
    - a `findOne` that throws → refused;
    - controls: a fresh admin is accepted; when the store's name differs from the session copy, `linked_by_name` is
      the store's;
  - **the security check**, for each action. Each refusal leaves the store unchanged and no request in the fake uCRM's
    log. Each has its accepted control: the same POST with a valid token and `Sec-Fetch-Site: same-origin`.
    - (i) no `_csrf`, with `cb_action=x`: refused; also with `sc_action=x`;
    - (ii) a valid token with `Sec-Fetch-Site: cross-site`: refused; also with `same-site`;
    - (iii) a valid token, no Sec-Fetch-Site, an `Origin` of another host: refused; also `Origin: null`;
    - (iv) a valid token, no Sec-Fetch-Site or Origin, a `Referer` of another host: refused;
    - (v) a valid token and none of the three headers: refused;
    - extra controls, each accepted: no Sec-Fetch-Site with an Origin naming this host; only a Referer naming this
      host;
  - every §R.3.1 refusal, rules 6(a)–(c), 7 over the unfiltered list and 8 among them, each writing nothing, each with
    its control accepted;
  - **a fresh read, never the page cache**;
  - provenance recorded;
  - **only the store written, never a plugin row**;
  - **escaping:** a product and a service plan whose names carry `<b>`, `&`, `"` and `'` are linked, relinked to the
    same product, unlinked, and refused. After each, the rendered dashboard page, flash included, and the screen hold
    no raw `<b>`. The screen carries the escaped form, and the flash the stripped one (`plain()` removes `<`, `>`, `&`
    and `"`, `QuoteOrganization.php:228-232`). Control: the name does appear, escaped on the screen and stripped in the
    flash;
  - **what was quoted under a link:**
    - a relink and an unlink over seeded Cash and Credit applications give the right counts, and the ended record's
      history is drawn;
    - an application quoted by `retry_quote`, and one quoted by Make the quotation, after linking carry
      `quote_link_ids`;
    - the Orders filter answers 403 for a non-admin, and is absent on `ss` and `ug_off`.
- **Deletion and stamps:** a delete ends every active record of the row; a reused id is caught by its stamp.
- **Unlink and delete over two active records:** two hand-seeded active records of one item, then an unlink, and in
  another run a delete with the gate on. Both records are ended (`unlinked`, `row deleted`), and none remains active.
  Control: the same over one active record ends it. Unlink's lock failure answers *"nothing was unlinked — try again"*
  and changes nothing.
- **The picker:**
  - lists counted rows only, each with its uCRM id, and marks twins by each item's own key, products and service plans
    alike; the mark never disables or selects an entry;
  - disables the unusable, with reasons, rules 6(a)–(c), 7 and 8 included;
  - **pre-selects nothing but the current link**, and that only when it is the single active record, with the row's
    current stamp and the current organization;
  - never pre-selects by `ucrm_product_id` or spelling;
  - is drawn for admins only.
- **The link column:** a linked row that does not resolve shows the product's `#id 🔗` link and, for a plan, the service
  plan's id, beside the reason.
- **The gate off never opens the store:** after every page and action is rendered and posted on `ss` and `ug_off`,
  `sqlite_master` has no `ucrm_quote_links`. Each post's outcome and flash is asserted first, so a post that never
  reached its handler cannot pass (§R.17, T6). **L19b:** the KYC submission (new and existing), `syncOne`,
  `retry_quote`, Send Quotation and a lead quote's lines leave no `ucrm_quote_links` either, each with its Uganda
  control (§R.17, T1). Each path must be shown to run to its end, `retry_quote` and Send Quotation by their answer,
  and L19's page and form draws by `end === 'drawn'` (§R.17.1, FX-tests-1).
- **The setting invalid** (L-R6, §R.17, G2): a save with `ucrm_quote_organization_id` `abc` or `1x` refuses naming
  the setting, for a plan, two kits and the installation, with no uCRM read and the store unchanged. Control: the
  setting 1 links after a uCRM read. **L-R6b** (§R.17.1, FX-money-2): with the setting invalid, a non-admin is answered
  403 and a cross-site post the security flash, never the setting's text; nothing is written and uCRM is not asked.
- **The Hardware picker** pre-selects nothing but the kit's current link, as the Plans picker does (L14, §R.17, T2).

#### R.9.2 The other suites

- **`test_quote_company`:**
  - the Uganda books of B and C seed links (`tests/fixtures/quote_company_journey.php:249-250`,
    `tests/fixtures/fake_ucrm_quote_org.php:54-61`);
  - the S journey's `ug2` control is seeded;
  - every application its Uganda journeys retry and expect quoted — `syncOne` in B3 and C3, `retry_quote` in C3 and C6
    (`tests/test_quote_company.php:675`, `:897`, `:922`, `:986`) — seeds `quote_sale_entry` `web` (E-1), and, where its
    lines are marked (`:871`), `quote_link_ids` naming the seeded links (§R.4.4). Without them the retry is now
    unpriced; a case that expects a refusal keeps its own seed;
  - **its form posts carry the marker** (F-3): `qc_kyc_post` (`tests/test_quote_company.php:256-262`) adds
    `ucrm_quote_form` = `5.18.102` to every post it builds. The gate-off books never read the field, so they stay as
    below. Its Uganda posts stay web and keep their quotations — C2 (iii), for one, expects `GET /clients/901` then
    `POST /clients/901/quotes` (`:836-840`) — and its existing-customer posts (`:725`, `:768`) are the hand-made web
    case of §R.4.2;
  - **`ss`, `ss_keyset`, `ug_off` and `ug_off_word` are seeded with a link store too, and must stay byte-identical to
    `ce7ac388`**, which proves leftover links are ignored;
  - G's South Sudan screens stay byte-identical (`tests/test_quote_company.php:1502-1521`).
- **`test_kyc_crm_messages`, `test_notify_kyc_quote_send` and `test_notify_kyc_race`** (seeds 12 and 55) gain links.
  Each one's Uganda form posts carry the marker, and each one's seeded Uganda applications `quote_sale_entry` `web`
  (F-3):
  - `test_kyc_crm_messages`: `form()` (`tests/test_kyc_crm_messages.php:168-173`) posts the marker, so quote 77 is still
    made (`:262-263`). Section H's hand-seeded marked application (`:392-399`), run through `runDue` (`:406`), carries
    `quote_sale_entry` `web` and `quote_link_ids` naming the seeded links, so it is still quoted (`:408`);
  - `test_notify_kyc_quote_send`: its Uganda posts (`tests/test_notify_kyc_quote_send.php:137-141`) carry the marker
    beside `install_by`, which it adds for Uganda only (`:141`). Its existing-customer post is the hand-made web case of
    §R.4.2;
  - `test_notify_kyc_race`: `$form` (`tests/test_notify_kyc_race.php:144-148`) posts the marker, and `$retryRace`'s
    seeded application (`:233-240`) carries `quote_sale_entry` `web` and `quote_link_ids`.

  `test_kyc_crm_create` runs with the gate off and is unchanged.
- **`test_quote_company_mutants`:** anchors re-taken in the changed files.

#### R.9.3 Weakened copies — each must be caught, at the section it names

| Copy | Caught by |
|---|---|
| ML1 an unlinked row falls back to its name key | A, B |
| ML2 the resolver reads `ucrm_product_id` as the link | A7 |
| ML3 `row_stamp` ignored (a reused id priced by the old link) | L, A |
| ML4 the item kind ignored in the fallback (a `kyc_packages` row, reached at `:320`, priced by a plan link with its id) | A |
| ML5 no re-check at use (an archived, taxable or re-priced-plan product priced) | A |
| ML6 ended records used (the latest record taken whatever its state) | A, C |
| ML7 the link's organization ignored | A |
| ML8 a link saved without `requireAdmin` | L |
| ML9 the save validated against the page cache | L |
| ML10 the save accepts another organization's, an inactive or an archived product | L |
| ML11 the save accepts taxable, 0, more than 2 decimals, or a differing invoice label | L |
| ML12 the save accepts a plan whose product price differs from the service plan's 1-month price | L |
| ML13 the save accepts a plan product spelled unlike its service plan | L |
| ML14 the save accepts a kit or installation product spelled like a service plan | L |
| ML15 the save writes the plugin row (its `ucrm_product_id`, or its name) | L, G3 |
| ML16 a relink leaves the old record active | L |
| ML17 the picker pre-selects the spelled-alike product, or `ucrm_product_id` | L (Plans; and Hardware, §R.17 T2) |
| ML18 a picker, column or action emitted or handled with the gate off | G8, `test_quote_company` G |
| ML19 a gate-off path loads the link store (the table created on South Sudan) | L |
| ML19b–ML19g a gate-off KYC path (`handleNew`, `handleExisting`, `syncOne`, `retry_quote`, a lead quote, Send Quotation) loads the link store before its gate (§R.17, T1) | L (L19b) |
| ML19h–ML19i an L19 post never reaches its handler (§R.17, T6) | L (L19) |
| ML20 `quotePlanKeys` keeps one answer per key | F |
| ML21 `confirmLines` ignores an ended link, or a changed price | C |
| ML22 the form draws the plugin's name for a priced, linked row | H |
| ML23 `delete_plan` on Uganda leaves the link active | L |
| ML24 the installation still found by its name | A, H |
| ML25 a Fiber row linkable, or priced through a link | A, L, F |
| ML26 a construction site without links builds a resolver that prices by name | I |
| ML27 the save or use accepts a service plan whose key another counted service plan shares | A, L |
| ML28 the save or use accepts a plan whose key an archived plan, or another organization's, shares | A, L |
| ML29 a kit or the installation spelled like an archived or inactive plan is priced | A, L |
| ML30 a plan whose `planPrice` differs from its 1-month price is priced | A, L |
| ML31 a 200-row service-plans list still prices a plan | A, L |
| ML32 the shared implementation without its check for an existing uCRM quotation (v3: `retry_quote`'s) | C (the guard, at both entry points) |
| ML33 the seen links are not compared (`ucrm_links_seen` ignored) | B |
| ML34 the no-op compares only `product_id` and `service_plan_id` | L |
| ML35 a relink, an unlink or a delete hook ends only one active record, or a save no-ops while two are active | L (L-two-active, state (c), and the unlink and delete cases over two active records) |
| ML36 the save accepts an installation link to an offered kit's product, or the reverse | L |
| ML37 the resolver prices such an installation. Its second half, QL-LK1 reporting it "resolves", is the deploy script's: §R.7.7 item 6 | A |
| ML37b the resolver prices an inactive kit row linked to the installation's product beside the installation (§R.17; re-anchored with §R.17.1's line) | A (A32), C (C28) |
| FXM1a–FXM1b rule 8's kit half at save applied to offered kit rows only: before the lock (`candidate()`), inside it (`set()`) (§R.17.1) | L (L8) |
| ML38 the entry point ignored, or a post without the marker taken as web (an app sale priced through a plan link) | A, C |
| ML39 a retry does not record `quote_link_ids` | L (`retry_quote`, Make the quotation), C (the cron retry; §R.17, T3) |
| ML40 the count reads the active record instead of the ended one | L |
| ML41 the own-page check (`fromOwnPage`) removed from a link action | L (ii)–(v) |
| ML42 a link action relies on the global gate alone (all three checks removed) | L (i) |
| ML43 a link action's `csrfCheck()` removed, or its result discarded | L (i), with `Sec-Fetch-Site: same-origin` |
| ML44 a flash carries the raw product name | L |
| ML45 `h()` dropped on the picker or the link column | L |
| ML46 the uCRM read and the rules run inside the `withLock` callback | L (L-race) |
| ML47 `fromRecords` ignores `row_stamp` (a replaced row judged linked). The offer-writer half is the deploy script's, MD6 | L (the judge's row-replaced case) |
| ML48 the link actions trust `requireAdmin`'s session copy | L |
| ML49 Product Mapping's green banner drawn with the gate on | G |
| ML50 Product Mapping's header (`product_mapping.php:52`) left ungated with the gate on | G (the header needle) |
| ML51 `P1_UNLINKED` not used for a web sale whose reason is "not linked" at one of its three sites (submission new, submission existing, `retryLines`), or used for an app-origin one | B; C (`P1_UNLINKED` at all three sites) |
| ML52 the active rule reads `ended_at` instead of `ended_at_ts` | L (Time: the hand-seeded record) |
| ML53 Make the quotation drawn where one of its five conditions fails (`quote_found_in_ucrm` recorded among them, F-10), for a non-admin, or with the gate off | C (the button's gate), G8 |
| ML54 `kyc_make_quote` without `requireAdmin`, or judging the session copy | C (admin) |
| ML55 `kyc_make_quote`'s own-page check removed, or `csrfCheck()`'s result discarded | C (the own-page check) |
| ML56 `kyc_make_quote` accepts a POST carrying a field beside `action`, `_csrf` and `app_id` (step 4's refusal removed, F-2) | C (only the id: the four posts with one field more) |
| ML57 `retry_quote`'s gated block, or `kyc_make_quote`, quotes by its own code instead of the shared implementation | C (the shared implementation) |
| ML58 the claim removed, so a double press quotes twice | C (a double press) |
| ML59 an application stored without `quote_sale_entry` resolved as web at a retry, or the entry rule applied to unmarked lines only | C (every application saved before the deploy) |
| ML60 the entry reason, or the `kyc_packages` reason, keeps its "make the quotation" tail | B (E-3) |
| ML61 a second text for two active records, on a screen | L (one text for two active records) |
| ML62 `lib/UcrmQuoteLinks.php` require()s a sibling file, or `fromStore` opens the store with the gate off | I (the inventory), L (the contract) |
| ML63 `records()` leaves out ended records, or returns them in part | L (the contract) |
| ML64 `retry_quote` hands `makeQuotation` a null `$known`, so the company check is asked twice (F-1) | C (the company check once) |
| ML65 `kyc_make_quote` takes the client, the amount, the entry or the lines from anywhere but the stored application, step 4's refusal kept (F-2) | C (only the id: the positive case's one POST to the stored client, with the store's lines and the stored amount) |
| ML66 the judge keeps the quoting organization from a constructor (`fromStore`'s `$setting`) instead of taking it from its caller (F-4) | L (the organization comes from the caller; the judge's other-organization case) |
| ML67 rule 8's "offered" narrower or wider than `offeredKit` (`is_active === true`, or `Fiber` compared case-sensitively) (F-5) | L (offered, as the form offers) |
| ML68 `kyc_form.php`'s kit loop keeps a `type` test of its own instead of calling `offeredKit` (F-5) | I (the inventory) |
| ML69 `lib/UcrmQuoteLines.php` without its `require_once __DIR__ . '/UcrmQuoteLinks.php'` (F-6) | I (the inventory), and every case whose caller loads only the resolver |
| ML70 the button's flash, or `retry_quote`'s answer, chosen by `code` instead of `kind` (one flash for every 409) (F-7) | C (each kind's answer) |
| ML71 a flash carries uCRM's quote id uncast (F-8) | C (escaping) |
| ML72 the card draws `quote_found_in_ucrm`'s number without `h()` (F-8) | C (escaping) |
| ML73 the claim "removed" by a write that leaves the key, such as `updateOne` with null (F-9) | C (the claim's removal: no key, the record as before) |
| ML74 the claim's removal not in a `finally`, so a Throwable leaves it (F-9) | C (the claim's removal: a Throwable inside the method) |
| ML75 the claim's age limit below 900 s (300 s, as v4 had it) (F-9) | C (a double press: a claim 600 s old still blocks) |
| ML76 `retry_quote` answers a claim with anything but 409 and its text, or lets a Throwable through (F-9) | C (a double press; the claim's removal) |
| ML77 `makeQuotation` leaves `quote_error` in place on success (F-10) | C (`quote_error` cleared) |
| ML78 the link column for two active records shows one record's id, or no Change or Unlink (F-11) | L (one text for two active records) |
| ML79 `quote_made_by_id` or `quote_made_via` not recorded, recorded on a refusal or by the cron retry, or recorded with the gate off (F-13) | C (who made it), G8 |
| ML80 `retry_quote`'s gated path judges the API guard's record instead of the fresh one (§R.16) | C (admin, at `retry_quote`) |
| ML81 the guard records more than `quote_found_in_ucrm` (a P1, a `quote_refused_text`) when it finds a quotation (F-10) | C (the guard) |
| ML82 the claim's removal unsets a claim it did not take, so a press answered `claimed` frees the first request's claim (F-9) | C (the claim's removal: a press answered `claimed`) |
| ML83a–ML83c a Throwable after the POST answered as "no quotation": the step-5 write let through, and the button's and `retry_quote`'s old texts (§R.17) | C (C22) |
| ML-R6 the link save reads uCRM with the quoting-organization setting invalid (§R.17, G2) | L (L-R6) |
| ML-R6b the setting checked before the admin and own-page checks (§R.17.1) | L (L-R6b) |
| ML19j–ML19k L19b's Send Quotation stops after its gate; `retry_quote` answers before its gate-off path runs (§R.17.1) | L (L19b) |
| ML84a–ML84b the `error` answer's old texts, at the button and in the method (§R.17.1) | C (C22 (c)) |
| ML85a–ML85e the card after an `error` answer: the box not drawn, `quote_unconfirmed_at` not recorded, never removed, removed before the guard reads uCRM, set to null (§R.17.2) | C (C22 (c), (d)) |
| MG26 the "did not finish" box drawn with the gate off, its sentence calling nothing (§R.17.2; re-anchored by §R.17.4, CK5-T6) | G (G8) |
| ML85f–ML85l the box beside `quote_created`, for admins only, every application's key removed, the key on a refusal, a Throwable from `postQuote` recording none, the sentence naming a button not drawn, the removal by the caller's record (§R.17.3; ML85b–ML85d re-anchored) | C (C19, C22 (b), (d)) |
| ML86a–ML86k the `error` answer's key write unguarded, the old heading, the sentence dropped, the page dying beside `quote_created`, the key's removal swallowed, `recordUnconfirmed()` without its try, the key removed on `found`, the catch taking `\Exception`, a failed write not logged, an earlier stamp kept by the caller's record at either write (§R.17.4; ML85b, ML85i, ML85j re-anchored) | C (C22 (b), (c), (d)) |
| ML87a–ML87g no box for a press in flight, the box for any claim however old, its sentence and the admin's losing "Do not make one by hand in uCRM:", a card stopping before its bottom row, the failed key write's exception thrown in place of `postQuote`'s, `recordUnconfirmed()` taking `\Exception` (§R.17.5) | C (C22 (b)–(e)) |
| MG27 the "press in flight" box drawn with the gate off (§R.17.5) | G (G8) |
| ML88a–ML88e a claim past `QUOTE_CLAIM_SECONDS` not read as "did not finish", the in-flight box yielding to the key, for 700 s instead of `QUOTE_CLAIM_SECONDS`, the found line yielding to a live claim, the in-flight box beside a made quotation (§R.17.6; ML85a, ML85f, ML85g, ML86d, ML87c and MG26 re-anchored) | C (C22 (e), (f)) |
| ML-UI1a, ML-UI1b a shared key not marked, or ignored by the page; MF-alert the picker alert's old tail (§R.17) | F (F7, F5) |
| MH-UI3 the installation's reason only in a title; MH-UI5a–MH-UI5c the defaults matched on uCRM's names (§R.17) | H (H10, block 10, H13) |
| ML-UI4 the found card still prints the refusal text (§R.17) | C (C17) |
| MC9b–MC9c C9's unmarked half (§R.17, T7); MI11 SAFETY.md's entry not describing D14 (§R.17, G3); MI11b its test record put back to nine sections (§R.17.1) | C (C9); I (I6b) |

No copy removes `CustomerSession::crossSite()` alone: with `fromOwnPage` present it would survive, and a weakened copy
that cannot be caught proves nothing.

**Of 5.18.102's own copies (Map 2 §7):**
- **Retired, each replaced above:**
  - MA4 (an empty key matched) → ML1;
  - MA5 (the first of two spelled alike taken) → ML6/ML16;
  - MA6 → ML14 at use.
- **Re-anchored:** MA1 (`planPrice`, still wrong for the linked service plan), MA3, MA7–MA11, and MF8, MF9 and MF17
  (the lead step stays by key).
- **Carried:** MG8–MG12, MG18 and MG19.

The deploy preflight's "passes the name only" copy belongs to the rehearsal, which this design does not run.

**Copies of the deploy script** belong to the rehearsal as well. The design names them; the rehearsal must show each one
caught:

| Copy | Caught by |
|---|---|
| MD1 the script keeps one old remedy, "row's name in the plugin" (§R.7.5) | the negative check |
| MD2 QL-CA1 keeps the strict comparison over a link made while the deploy runs (§R.7.2) | a link planted between stage B and `qo_after`'s copy: the run must PASS, with the note |
| MD3 "made since the copy" runs `strtotime()` on `linked_at` (§R.7.3) | a link written in Africa/Kampala and judged in UTC and in Etc/GMT-5, with `COPY_AT` one hour after it and then one hour before. The check must read it as a leftover in the first case and as made since the copy in the second, in both zones; the copy gets the first case wrong under UTC. Stale under D-5: see §R.7.7 item 5 |
| MD4 the dashboard's sign-in gate broken (`public.php:1064`) | QL-CA3 (1), expected to fail alongside V12 and Q-CA5 |
| MD5 `requireAdmin` removed from one of the three handlers QL-CA3 reads (the two link actions and `kyc_make_quote`) | QL-CA3 (2), expected to fail alongside R1 |
| MD6 the offer carries the link records without `row_stamp` (was ML47) | the leftover-link scenario, run once with the leftover's row unchanged and once with it replaced after the link: QL-G1's verdict must follow the stamp both times; the copy gets one of them wrong |
| MD7 stage A reads the store through the code root it copies (`ICODE_A`), not the pin's class (§R.7.1) | the leftover-link scenario: QL-G1 must judge the leftover, and QL-CA1 must not report it "linked since stage A"; the copy misses it at QL-G1 and reports it so |
| MD8 the window's Make-the-quotation count chosen by `submitted_at` instead of by `QLG1_APP_MAX` (§R.7.3) | an application saved in Africa/Kampala less than three hours before the copy: never counted; the copy, reading the string in UTC, counts it. Its fixture's timing: §R.7.7 item 10 |
| MD9 the raw organization GETs made between `$n0` and `$asked` (§R.7.1) | every scenario that reads the catalogue: QL-G1 must pass its path check; the copy is refused by it |
| MD10 the Make-the-quotation count (§R.7.3) includes app-origin registrations, ones stored without `quote_sale_entry`, or ones carrying `quote_found_in_ucrm` | a window scenario with one of each beside a web one: the count is 1, and the others are listed apart |
| MD11 QL-N3's D11 count keeps the rule by the installation answer (§R.7.1, E-1) | a stage-A book with a pending Shifting Connection and a pending Fiber sale, both saved before the deploy: QL-N3 counts both, by P1 and P2; the copy leaves the Shifting Connection out |

Copies proposed for §R.7.7's items 1 and 6 are decided with those items.

### R.10 What stays exactly as built (decision 10)

- **Cash and refusals:**
  - cash = the quotation's total: one rounded total for every cash sink (§H);
  - the posted total, `ucrm_total_seen`, and D10 (§G), now with `ucrm_links_seen` beside it (§R.4.7);
  - D11, restated by E-1 as one statement: every application saved before the deploy is unpriced at its retry, P1
    (Credit, or no cash collected) or P2 (Cash with cash collected) (§R.4.2); and the retry rules for Cash (§F.4), now
    with the link check and the shared implementation's check for an existing quotation (§R.4.4, §R.4.8);
  - P1–P3 and their texts (§F.5): only the reason inside P1 changes, since P2's text carries no reason, only its
    amount (`UcrmQuoteLines.php:72`); and a web sale whose reason is "not linked" is recorded as P1 with
    `P1_UNLINKED`'s text (§R.4.1) (F-14).
- **The quotation:**
  - the read-back (§F.6);
  - "Prepared by" from `salesPerson` (§I);
  - customer `notes`, and the internal text in `adminNotes` (§F.1);
  - `PENDING` never sent;
  - the five quote-create sites (§F).
- **What stays refused on Uganda:**
  - the product push, which D14 extends to Auto-Map and `set_product_mapping` (§K.3, Q7);
  - no Delivery Acknowledgment PDF (§K.2).
- **The resolver's shape:**
  - one resolve before the first write;
  - all or nothing;
  - `no_plan` kept apart;
  - the page cache at 60 s;
  - page timeouts of 3 s and 5 s;
  - the read rules: `limit=500`, a full page unusable, `raw` unusable;
  - the quoting client.
- **What the form and the pages do:**
  - the "Who installs?" question;
  - one installation line per application;
  - Fiber unpriced on KYC;
  - `ucrm_products.json` never read.
- **Boundaries and files:**
  - South Sudan and the gate-off trees byte for byte;
  - no migration;
  - the kept files of §L.4.

### R.11 Files

- **Added:**
  - `lib/UcrmQuoteLinks.php`;
  - `tests/quote_from_ucrm/L_links.php`, with its weakened copies.
- **Changed beyond §L.4's list:** none. The entry point is read from the web form's own marker (§R.4.2), so
  `includes/api/api_retailer.php` stays on §L.4's kept list (`docs/71:587-589`), `api/index.php` is not changed, and
  §L.4's kept list is not amended.
  - The screens, `post_sync.php`, `api_products_admin.php`, `product_mapping.php`, the resolver, `KycService`,
    `KycCrmSync`, `QuotationService`, `api_cron_debug.php`, `kyc_form.php`, `send_quote.php` and `applications.php` are
    already declared.
  - The fixtures and test files listed in §R.9 are already declared, except `L_links.php`.
  - Make the quotation adds no file (E-2): its button is in `tabs/sales/applications.php`, its handler in
    `includes/post/post_sync.php`, and the shared implementation in `lib/KycService.php`, which `retry_quote`
    (`includes/api/api_cron_debug.php`) calls. Each is already declared.
  - `lib/BankGate.php`, `lib/CustomerSession.php` and `lib/RetailerAuth.php` are read, never changed (§R.2.1).
    `includes/post/post_kyc.php`, `includes/api/api_retailer.php` and `retailer/index.html` stay kept.
- **§L.4 is amended first** with the two added files, and §B gains D14.
- **The rest of `docs/71` is amended with the release, not deferred** (F-14):
  - §B's D11 row (`docs/71:52`) and §F.4's D11 bullets (`:285-290`), by E-1, as §R.4.2 gives them;
  - §E.3's *"a Credit Starlink New Connection from them is registered with no quotation"* (`:210`), and §M's *"A Credit
    Starlink New Connection from them gets no quotation"* (`:612`). Each becomes: every Credit sale from the retailer
    app or the mobile API, and from a form opened before the deploy, is registered with no quotation, and is quoted in
    uCRM by hand;
  - §E.3's overlap sentence (`:218-220`) and §Q's entry-point note (`:823`), as §R.4.2 gives them;
  - §M's runbook, as §R.6 gives it.
- **The deploy script's `EXPECTED_ADDED` grows by those files**, and its file count follows (D-R1, D-R6, the re-pin
  step). Its pins of the two app files stay as they are (`scripts/deploy-5.18.102.sh:587`, `:620`), and so do Q-CA1's
  line naming `api/index.php` untouched (`:7297`), the line calling `api_retailer.php` 5.18.101's byte for byte
  (`:8845`) and the comment at `:563`.

### R.12 Open — only the operator can answer (each with the default this design builds)

| # | Question | Default |
|---|---|---|
| Q1 | Link the installation line too, on the Hardware screen, with no fallback to the name "Professional Installation"? | **Yes** (§R.2.4) |
| Q2 | Link the retailer app's `kyc_packages` rows (a picker on Product Mapping's Legacy Packages)? | **No**: they stay unpriced, with their own reason (§R.2.5), and on the gated path a sale whose post does not carry the web KYC form's marker — the retailer app, the mobile API — is not priced from uCRM at all (§R.4.2) |
| Q3 | Does a plan link name both a Product and a Service Plan, picked separately? | **Yes** (§R.1.1, §R.3.1) |
| Q5 | Pre-select a product spelled like the plugin row in the picker? | **No**: a marked hint only (§R.2.3) |
| Q6 | Copy uCRM's name into the plugin row, at link time or by a button? | **No**: shown live, never copied (§R.5) |
| Q7 | Refuse Auto-Map and `set_product_mapping` on Uganda with the gate on? | **Yes**, 409 (§R.2.6) |
| Q8 | Hide the Hardware form's "UCRM Product ID" field on Uganda with the gate on, and ignore it? | **Yes** (§R.2.2) |
| Q9 | Who may link, and who may make a quotation from the Orders card? | **Admins only**, as for saving those screens, judged on the fresh account record re-read by id, never the session copy (§R.2.1). The same for Make the quotation and for `retry_quote`'s gated path, whose application then records the admin's id (§R.4.8, F-13) |
| Q10 | Accept the window after the deploy (Credit only, until linked), or seed links at deploy time from a sheet the operator approves? | **Accept the window**: deploy at a quiet hour, link once the deploy has printed its last line (§R.6) |
| Q11 | A saved application whose link or price changed before its retry: refuse, or quote the stored lines? | **Refuse** (P2/P1) (§R.4.4) |
| Q13 | An item left unlinked on purpose: does `--after-only` fail, unless `--accept-unpriced`? | **Yes** (§R.7.3) |
| Q14 | Keep showing the plugin's own Cost, Revenue and Sell Price columns on Uganda (profit view only, never quoted)? | **Yes, unchanged**; the link column shows uCRM's price beside them |
| Q15 | Lead pre-fill: from `interest_plan` to the one plugin row spelled like it, then by that row's link? | **Yes**; none when two rows share the spelling (§R.4.5) |
| Q16 | One uCRM product backing two or more offered kit rows: refuse it, or allow it? | **Allow**, with "also linked to:" as the warning on the Hardware screen, and a QL-LK1 note that counts such products (§R.3.1, §R.7.3) |
| Q17 | The window's Credit KYC registrations: quoted once by an admin after `--after-only`, with agents making none by hand; or quoted by the agents by hand, with admins never quoting them? | **The admin's Make the quotation on the Orders card** (E-2), for the web registrations whose screen says "Do not make it by hand" (`P1_UNLINKED`, F-12). A web registration whose screen gives P1's own text, app-origin ones, and every application saved before the deploy (E-1) are quoted by hand, as their screens say. The shared implementation's check refuses a second quotation either way, and once it has found one the card draws no button (§R.4.4, §R.4.8, §R.6) |

**Decided by facts — no longer open:**

| # | Was | Decided by |
|---|---|---|
| Q4 | Must a plan's Product and Service Plan be spelled alike in uCRM, for the WhatsApp summary (`webhook.php`, kept)? | **A constraint of this release.** `webhook.php` is kept byte for byte (§L.4, `docs/71:587-589`; §R.8). It counts a line as monthly only when its label is keyed like a service plan (`webhook.php:2958-2961`, its map at `:351-356`), and the plan line's label is the product's name (`UcrmQuoteLines.php:249-255`). The operator's only part is the uCRM preparation of §R.6 step 3. Relaxing it is a later `webhook.php` change, strictly needed only while `docs/71` §N leaves open whether uCRM keeps the label it is sent (§R.3.3) |
| Q12 | Fiber rows not linkable, so Send Quotation no longer prices a Fiber plan spelled like a product? | **Decided by `docs/71` §D.4**: Fiber on Uganda is always unpriced (`docs/71:157-159`), and UQ-14 was settled there (`:760`, `:808`). As built, Send Quotation priced a Fiber plan row, because its picker (`QuotationService.php:869-880`, `:939-949`) and `planLine` (`UcrmQuoteLines.php:215-256`) never read the row's type, whereas KYC checks it (`kyc_form.php:28-29`, `:33-34`; `UcrmQuoteLines.php:330-334`). §R.4.3 closes that build gap |

### R.13 What the review changed

One line per confirmed finding, each folded in by its verifier's corrected fix.

- **R-M1** — rules 6 and 7 are judged against the summary's own unfiltered service-plan list: "exactly one" is restored
  over it (6(a)), `planPrice` must equal the 1-month price (6(b)), and a list of 200 rows or more prices no plan (6(c)).
  Changed: §R.0, §R.2.3, §R.3.1, §R.3.2, §R.3.3, §R.4.1, §R.6, §R.7.1, §R.7.3, §R.9.1 A and L, §R.9.3 ML27–ML31.
- **R-M4** — `retry_quote`'s gated check for an existing uCRM quotation (fail closed), `quote_found_in_ucrm` and the
  Orders card's line for it, with one remedy for the window's registrations. Changed: §R.4.4, §R.6, §R.7.2, §R.7.3,
  §R.7.4, §R.7.5, §R.8, §R.9.1 C, §R.9.3 ML32, §R.10, §R.12 Q17. **The check's place and the remedy are superseded by
  §R.15** (E-2): the shared implementation, and Make the quotation on the Orders card.
- **R-N1** — the form posts `ucrm_links_seen`, and a relink since the form was drawn refuses Cash with the new
  `CASH_RELINKED`. Changed: §R.4, §R.4.1, new §R.4.7, §R.5, §R.8, §R.9.1 B and H, §R.9.3 ML33, §R.10.
- **R-N2** — a save is a no-op only when the single active record would itself resolve now; otherwise every active
  record is ended and a fresh one written, and the picker pre-selects only such a record. Changed: §R.1.1, §R.1.3,
  §R.1.4, §R.2.3, §R.9.1 L, §R.9.3 ML34–ML35.
- **R-N3** — rule 8: the installation may not share a product with a kit row the form offers, at save and at use; the
  import tool's installation row is deactivated; two kit rows may share a product (Q16). Changed: §R.1.4, §R.1.5,
  §R.2.3, §R.2.4, §R.3.1, §R.3.2, §R.4.1, §R.6, §R.7.1, §R.7.3, §R.9.1 A and L, §R.9.3 ML36–ML37, §R.12 Q16.
- **R-N5** — the entry point is passed from the server, and on the gated path app sales are unpriced (`CASH_UNPRICED`
  for Cash), stored as `quote_sale_entry` for the retries; `api_retailer.php` and `api/index.php` change. Changed:
  §R.2.5, §R.4.1, §R.4.2, §R.4.4, §R.4.7, §R.6, §R.8, §R.9.1 A, C and I, §R.9.3 ML4 and ML38, §R.11, §R.12 Q2.
  **Superseded by §R.14** (D-2): the entry is read from the web form's own marker, and `api_retailer.php` and
  `api/index.php` stay byte for byte. A record without the key is `app` (§R.15, E-1).
- **R-N6** — `quote_link_ids` are recorded on every path that quotes from links, the counts are shown on relink and
  unlink, the item's history is drawn, and Orders gains a `quote_link` filter. Changed: §R.1.5, §R.2.3, §R.4.4, §R.6,
  §R.7.3, §R.8, §R.9.1 L, §R.9.3 ML39–ML40. **"Every path" is superseded by §R.14** (its smaller items, R-N6): every
  path that quotes a KYC application from links; Send Quotation records nothing.
- **D14-P1** — both link actions make `post_bank.php`'s own-page check themselves, use `csrfCheck()`'s return value,
  and never rely on the global gate. Changed: §R.2.1, §R.3.1, §R.7.6, §R.9.1 L, §R.9.3 ML41–ML43.
- **D14-P2** — every flash holds fixed text plus ids or `plain()` names; every uCRM-sourced or stored string on the
  screens goes through `h()`; a name in a JS string is never in an `on*` attribute with `h()` alone. Changed: §R.2.2,
  §R.2.3, §R.4.1, §R.9.1 L, §R.9.3 ML44–ML45.
- **D14-P3** — the uCRM read and the rules run before the lock, the lock makes store checks only, every active record
  is ended, and a lock failure answers "try again". Changed: §R.1.1, §R.1.3, §R.1.4, §R.1.5, §R.3.1, §R.9.1 L, §R.9.3
  ML35 and ML46.
- **D14-P4** — the uCRM ids are shown in the picker and the link column, twins are marked by the product's own key, and
  the deploy counts the organization field. Changed: §R.2.2, §R.2.3, §R.3.1, §R.6, §R.7.1, §R.7.3, §R.9.1 L.
- **D14-P5** — `UcrmQuoteLinks::fromRecords`, `fromStore` and `rowStamp`; the offer carries every record whole, with
  `row_stamp`, and each row's raw stamps. Changed: §R.1.1, §R.1.5, §R.4, §R.7.1, §R.7.3, §R.9.1 L, §R.9.3 ML47.
  **Superseded by §R.14** (D-3, D-15): the offer carries each record's listed fields, never `ended_at`, and ML47's
  offer-writer half is MD6. `records()` returns every record whole, and the offer reduces it (§R.15, E-5).
- **D14-P7** — the deploy's operator-facing texts are listed and rewritten by asserted edit, with a negative check in
  the rehearsal. Changed: new §R.7.5, §R.9.3 MD1.
- **D14-P8** — part (b) only: one remedy for the window's registrations (agents make no quotation by hand; an admin
  retries after `--after-only`, from QL-LK1's list), backed by R-M4's check; Send Quotation is not a dead end; §M's
  "or wait" restored. Changed: §R.4.4, §R.6, §R.7.2, §R.7.3, §R.7.4, §R.7.5, §R.9.1 C, §R.12 Q17. **The remedy is
  superseded by §R.15** (E-2): Make the quotation on the Orders card, not `retry_quote` from QL-LK1's list.
- **D14-P10** — admins make no link while the deploy runs, linking waits for its last line, and QL-CA1 judges the store
  as stage A read it, so a link made meanwhile is a note, not a failure. Changed: §R.6, §R.7.2, §R.7.5, §R.9.3 MD2,
  §R.12 Q10.
- **D14-P11** — `linked_at_ts` and `ended_at_ts` from `time()`; "made since the copy" compares them with `COPY_AT`,
  never `strtotime()` of the display string. Changed: §R.1.1, §R.1.4, §R.7.1, §R.7.2, §R.7.3, §R.9.1 L, §R.9.3 MD3.
  **"Made since the copy" is superseded by §R.14** (D-5): it is judged by stage A's per-id hashes, with
  `linked_at_ts >= COPY_AT` as the cross-check.
- **D14-P12** — both actions re-read the account by id and require it active and admin; provenance comes from that
  fresh record. Changed: §R.1.1, §R.2.1, §R.3.1, §R.9.1 L, §R.9.3 ML48, §R.12 Q9.
- **D14-P14** — Product Mapping's header, green notice, badges, summary-card class and column label stop claiming that
  `ucrm_product_id` reaches quotes on Uganda with the gate on. Changed: §R.2.6, §R.8, §R.9.1 G, §R.9.3 ML49.
- **D14-P15** — QL-CA3: GETs without a session to the Plans and Hardware screens and their picker addresses, and a text
  read of the two installed handlers against the pin. Changed: new §R.7.6, §R.9.3 MD4–MD5. **Superseded by §R.15**
  (E-2): the GETs include the Orders page, and the text read covers three handlers, `kyc_make_quote` among them (F-14).
- **D14-P16** — narrowed: Q12 is decided by `docs/71` §D.4, and Q4 is recorded as a constraint of this release; every
  other question stays open. Changed: §R.3.3, §R.4.3, §R.12.

### R.14 What the second check changed

The checkers' report on v2 named the findings that had landed only in part, and new problems. The operator's lead
engineer took decisions D-1 to D-15 on them. Each is folded in below, and so is every other item the checkers listed.
One line each.

**The decisions:**
- **D-1** — rule 8's input: `UcrmQuoteLinks` holds the link records and the offered kit rows
  (`fromRecords(array $records, array $kitRows)`; `fromStore` also loads `kyc_devices`, behind the gate). One method
  serves the save (inside the lock, each kit row read by `findOne`), `kitLine`/`installLine` at use, and the deploy's
  remote reading, from the offer file's kit rows, which gain `is_active`. L's judge-equivalence test includes rule 8.
  Changed: §R.1.4, §R.1.5, §R.3.1, §R.4, §R.7.1, §R.9.1 L.
- **D-2** — a sale is `web` only when its post carries the web form's own hidden marker (`ucrm_quote_form` =
  `5.18.102`), anything else `app`. `KycService::process` keeps its signature, `api_retailer.php` and `api/index.php`
  stay byte for byte, and §L.4's kept list is not amended (v2's amendment and its deploy-script consequences removed).
  Why a request field is acceptable is recorded. Consequences stated: `handleExisting` is app-only, so never priced on
  the gated path; the app reason, and the package reason, end "make the quotation in uCRM"; `linesRecord` stores
  `quote_sale_entry` from `$res['entry']`; a form opened before the deploy is `app` too. Changed: §R.2.5, §R.4, §R.4.1,
  §R.4.2, §R.4.7, §R.5, §R.6, §R.8, §R.9.1 A, C, H and I, §R.9.3 ML38, §R.11, §R.12 Q2. Amended by §R.15: a record
  without the key is `app` (E-1), and the app and package reasons end at their cause (E-3).
- **D-3** — `ended_at_ts` null is the active marker; `ended_at` and `ended_at_ts` are written together inside the
  lock; the date strings are for the screens only, and the offer no longer carries `ended_at`. Changed: §R.1.1,
  §R.1.4, §R.7.1, §R.7.2, §R.7.3, §R.9.1 L, §R.9.3 ML52.
- **D-4** — `P1_UNLINKED` for a web sale whose reason is "not linked"; the admin's `retry_quote`, with R-M4's guard,
  is the one remedy for the window's web registrations; app-origin ones keep P1, are listed apart and, with the rows
  carrying `quote_found_in_ucrm`, are kept off the retry list; the after-copy text and "your steps" carry R-M4's item
  3. Changed: §R.4, §R.4.1, §R.6, §R.7.2, §R.7.3, §R.7.4, §R.7.5 items 6–7, §R.8, §R.9.1 B, §R.9.3 ML51 and MD10,
  §R.10, §R.12 Q17. Its remedy is amended by §R.15 (E-2): Make the quotation on the Orders card, through the shared
  implementation.
- **D-5** — every "since the copy" is judged by identity or by an epoch: applications by an id above `QLG1_APP_MAX`
  (state file), records ended by `ended_at_ts >= COPY_AT`, links by stage A's per-id hashes, with
  `linked_at_ts >= COPY_AT` as the cross-check. Changed: §R.7.2, §R.7.3, §R.9.3 MD8. The retry list became a count
  (§R.15, E-2); MD3's and MD8's scenarios are §R.7.7 items 5 and 10.
- **D-6** — the fresh account re-read is written in the action itself (`post_sync.php`), before any `UcrmQuoteLinks`
  call; QL-CA3's text order names it. Changed: §R.2.1, §R.7.6.
- **D-7** — stage A reads the link store through the pin's `lib/UcrmQuoteLinks.php` from Git, never through
  `ICODE_A`; the rehearsal gains a leftover-link scenario, judged at QL-G1 and never reported "linked since stage A".
  Changed: §R.7.1, §R.7.2, §R.9.3 MD6–MD7. The class's contract is §R.15's (E-5); its loading is §R.7.7 item 2.
- **D-8** — QL-G1 and QL-CA1 verdicts are one keyed hash per item, in memory and in the state file (under a key stage
  A keeps there), never printed, compared item by item; items whose link records changed since stage A are left out
  and noted with their direction. Changed: §R.7.2, §R.7.3. The key's storage and rule 8's effect are §R.7.7 items 3
  and 4.
- **D-9** — the operator-facing texts list is completed: the header's `--accept-unpriced` lines and the option's
  text, the gate-off and not-stated "your steps", and "the screens" (§R.7.5 items 8–10). §R.6 keeps the "Sales Person"
  attribute and the notes prefix as steps 2 and 4, with `docs/71` §M's numbers, and every "docs/71 §M step N" pointer
  gets its explicit target (item 5). The negative check reads the log and terminal only, its needle "row's name in
  the plugin", and the sheet's heading reads "uCRM items with the same spelling — a hint only". Changed: §R.6, §R.7.1,
  §R.7.5, §R.9.3 MD1, §R.12 Q4.
- **D-10** — L cases for an unlink and a delete over two active records, so that both halves of ML35 can be caught.
  Changed: §R.9.1 L, §R.9.3 ML35.
- **D-11** — QL-CA3 sends GETs only because the deploy never POSTs to a write action, not because of the CSRF gate.
  Changed: §R.7.6.
- **D-12** — QL-G1's raw organization counts are made after `$asked`, outside the "two lists once each" window, and
  counted on their own, GETs only. Changed: §R.7.1, §R.9.3 MD9.
- **D-13** — the "does not resolve" state shows the product's `#id 🔗` and, for a plan, the service plan's id;
  service plans' twin marks are stated, never disabling, never selecting. Changed: §R.2.2, §R.2.3, §R.9.1 L.
- **D-14** — the Product Mapping header needle is "quotes reference real billing items", matched case-insensitively,
  with a gate-off control; ML50 leaves the header ungated. Changed: §R.9.1 G, §R.9.3 ML50.
- **D-15** — the offer-writer half of ML47 is now MD6, caught by the rehearsal; ML47 is the release copy in which
  `fromRecords` ignores `row_stamp`, caught by L. Changed: §R.9.3.

**The checkers' items, results that landed only in part:**
- **R-M4, item 3** — the after-copy text and "your steps" now carry "check the customer in uCRM, retry only those with
  no quotation" (D-4; §R.7.2, §R.7.5 item 7). **Superseded by §R.15** (E-2): "press Make the quotation only where
  there is no quotation", on the Orders card (F-14).
- **D14-P4** — (1) the column's ids and (2) the service-plan twin marks: D-13; (3) where the raw organization GETs run:
  D-12.
- **D14-P5** — (1) stage A never read the store: D-7; (2) rule 8 had no input on the deploy path: D-1; (3) ML47
  weakened the deploy script, which L never runs: D-15.
- **D14-P7** — (a) the header's `--accept-unpriced` lines and option text, (b) the gate-off "your steps", (c) "the
  screens": D-9, §R.7.5 items 8–10; the pointer rule that could not be followed: D-9, item 5; the needle that matched
  the sheet: D-9.
- **D14-P8** — "submitted since the copy" had no method: D-5; the remedy's scope against app-origin registrations:
  D-4.
- **D14-P10** — (1) a per-item exclusion on one hash: D-8; (2) stage A's record list was always empty: D-7; (3) the
  printed line mixed two moments: it now says only "once this run has printed its last line" (§R.7.2).

**The checkers' new problems:**
- **First check** — rule 8's data source: D-1; the window list's time basis: D-5; the retry list against §R.6 for
  app-origin registrations: D-4; P1's built text against the one remedy: D-4, `P1_UNLINKED`; "spelled like it"
  matching the sheet: D-9.
- **Second check** — ML35's unlink and delete half: D-10; QL-CA3's reason for GETs only: D-11; the fresh re-read's
  place against QL-CA3: D-6; the entry rule and existing customers: D-2, stated in §R.4.2, §R.4.7, §R.5 and the C
  fixture; the retry list and `quote_sale_entry = app`: D-4; the two app files the deploy pins as untouched: moot under
  D-2, and §R.11 names the pins, unchanged; §R.2.5's stale `install_by` bullet: rewritten, the entry rule coming first
  (`UcrmQuoteLines.php:318-327`); how `linesRecord` learns the entry: D-2, `$res['entry']`.
- **Third check** — stage A's local reading through `ICODE_A`: D-7; app-origin registrations with no remedy: D-4;
  rule 8 on the deploy path: D-1; the §M pointers: D-9; the negative check against the sheet: D-9; the per-item
  exclusion: D-8.
- **Fourth check** — the re-read's place: D-6; which field marks the active record: D-3; "per record ended since the
  copy" and "submitted since the copy": D-5 (`ended_at_ts >= COPY_AT`; `QLG1_APP_MAX`); the optional identity check:
  made per id and no longer optional, one keyed hash per record id in the state file (§R.7.2, §R.7.3); the G needle:
  D-14; the skip notes at `:6820` and `:6821`: QL-CA3 is named there too (§R.7.6).

**Smaller items in results that landed:**
- R-N5: §R.4.2 no longer claims the app registers "as in 5.18.101", which has none of these refusals; an id in neither
  table is refused by the `no_plan` message (`KycService.php:557`; `:209-211`), stated in §R.2.5, §R.4.2, §R.4.7 and
  §R.6.
- R-N6: "every path that quotes from links" is narrowed to KYC applications; Send Quotation quotes from links and
  records nothing (`QuotationService.php:921-922`). §R.2.3, §R.4.4.
- D14-P2: the L escaping case expects the stripped form in the flash (`QuoteOrganization.php:228-232`), and §R.2.2
  gives the real reason the flash is handled apart: the layout prints it raw.
- D14-P3: `SqliteStore.php:146` corrected to `:145`; Unlink's lock failure has its own text (§R.1.4, §R.9.1 L).
- D14-P15: Q-CA4's and Q-CA5's ranges end at `:6941` and `:6953`.
- D14-P11: (a) is D-3, (b) is D-5, and (c) is the per-id identity check above.

**Found while folding them in, and recorded:**
- Under D-2 a form opened before the deploy is `app`: its Cash sale is refused by `CASH_UNPRICED` with the entry
  reason, no longer by `CASH_NO_TOTAL` (§R.4.2, §R.4.7, §R.6).
- Under D-4 the rollback note gains a sentence, because `P1_UNLINKED` told the agent of a web registration that an
  admin would quote it, and 5.18.101 has no remedy for it (§R.7.4).
- Inside the lock, rule 8 and the row check read their rows with `findOne`, never `load()`, whose request cache holds
  `kyc_devices.json` and `subscription_plans.json` (`SqliteStore.php:80-81`, `:346-348`) (§R.1.4).
- The existing-customer path is `docs/71:215-217`, not `:214-216`; the option text for `--accept-unpriced` runs
  `:362-367`, and the not-stated "your steps" `:8942-8946`.

### R.15 What the third check changed

The checker's report on v3 named what had landed only in part, and new problems. The lead engineer took decisions E-1
to E-6 on them. Each is folded in below, and every checker item is mapped to the decision or the §R.7.7 item that
answers it. One line each.

**The decisions:**
- **E-1** — on the gated path an application with no `quote_sale_entry`, i.e. everything saved before the deploy, is
  `app`. `retryLines` applies the entry rule first, so such an application is unpriced at every retry with the entry
  reason, gets no Make the quotation, and is quoted in uCRM by hand. D11 becomes one statement: at its retry every
  application saved before the deploy is unpriced, P1 (Credit, or no cash collected) or P2 (Cash with cash collected).
  `docs/71` §B D11 and §F.4 are amended to it (the cross-reference §R.16 corrects); QL-N3's D11 count and texts follow the
  release's rule; tests on the web path seed `quote_sale_entry` `web` explicitly. Changed: §R.2.5, §R.4.1, §R.4.2,
  §R.4.4, §R.6 (table, remedy, steps 5 and 9), §R.7.1, §R.7.2, §R.7.3, §R.7.5 items 6, 7 and 11, §R.9.1 (fixtures, A33,
  C), §R.9.2, §R.9.3 ML59 and MD11, §R.10, §R.12 Q17.
- **E-2** — the remedy is a button: Make the quotation on the Orders card, gated, for an admin, for an application with
  `quote_sale_entry` `web`, a uCRM client, P1 and no quotation. Its POST, `kyc_make_quote` in `post_sync.php`, makes
  `post_bank.php`'s own-page check and the fresh account re-read, and reads nothing from the request but the
  application id. It runs one shared implementation, `KycService::makeQuotation()`, which `retry_quote`'s gated block
  now calls too: R-M4's guard, the lines by the current links, the Cash rule, `postQuote`, the read-back and
  `quote_link_ids`, with `quoteClient` and no token precondition. Its flashes are fixed text plus ids. `P1_UNLINKED`
  takes the decided text; the deploy's retry list becomes a count with "use Make the quotation on the Orders card".
  Changed: §R.4, §R.4.1, §R.4.4, new §R.4.8, §R.6, §R.7.2, §R.7.3, §R.7.4, §R.7.5 items 3, 6, 7 and 10, §R.7.6,
  §R.8, §R.9.1 C, G and L, §R.9.3 ML32, ML51, ML53–ML58, MD5, MD8 and MD10, §R.10, §R.11, §R.12 Q17.
- **E-3** — the entry reason and the `kyc_packages` reason end at their cause; P1 and `CASH_UNPRICED` carry the remedy,
  so an app or stale-form Cash sale is never told to "make the quotation". Changed: §R.4.1, §R.4.2, §R.6, §R.9.1 B,
  §R.9.3 ML60.
- **E-4** — one text for two active records, §R.4.1's, on the screens and everywhere. Changed: §R.1.1, §R.4.1, §R.9.1
  L, §R.9.3 ML61.
- **E-5** — `UcrmQuoteLinks`' contract: it require()s no sibling file; `fromStore($store, array $setting)` takes the
  gate from its caller and opens nothing when it is off; `records()` returns every record whole, ended ones included;
  `fromRecords(array $records, array $kitRows)` takes kit rows in `kyc_devices`' stored shape; the in-lock rule-8
  re-check builds `fromRecords` over the lock's records and its `findOne` rows. Changed: §R.1.4, §R.1.5, §R.3.1, §R.4,
  §R.7.1, §R.9.1 I and L, §R.9.3 ML62–ML63.
- **E-6** — §R.13's superseded lines are marked, and every deploy-script mechanic the check left is in the new §R.7.7,
  with the checker's suggested fix, for the script's own review rounds. Changed: §R.7.1, §R.7.2, §R.7.3, §R.7.6, new
  §R.7.7, §R.9.3 ML37, MD3 and MD8, §R.13, §R.14.

**The checker's items, and where each went:**
- **D-1, partly landed** — the offer row's shape: E-5 fixes what `fromRecords` takes, and §R.7.7 item 1 how the writer
  carries it, with its copy; which instance the in-lock re-check builds: E-5.
- **D-2, partly landed** — R-N5's stale line: marked (E-6); the agents' texts: §R.7.7 item 8; an application stored
  without the key: E-1.
- **D-4, landed, with new problems** — the token and the missing button: E-2; no admin list after the window: E-2, the
  button is on every card where its conditions hold; ML51's retry half: E-2's test at all three sites, ML51 restated;
  the stale form missing from the texts on who quotes by hand: E-1 and E-2 (§R.6 step 5, §R.7.2, §R.7.4, §R.7.5 items
  6–7), and "as before" dropped, because 5.18.101 quotes app sales automatically.
- **D-5, partly landed** — MD3: §R.7.7 item 5; MD8's timing: §R.7.7 item 10.
- **D-7, partly landed** — the loading, the gate, the accessor: E-5, with the script's side in §R.7.7 item 2.
- **D-8, partly landed** — (a) the key's storage and (c) what `--after-only` compares: §R.7.7 item 3; (b) rule 8:
  §R.7.7 item 4.
- **D-9, partly landed** — the texts the list misses: §R.7.7 item 8, the D11 ones settled by E-1 (§R.7.5 item 11); the
  two meanings of `--accept-unpriced`: §R.7.7 item 9; the printed order of "your steps": §R.7.7 item 8.
- **D-15** — ML37's second half: §R.7.7 item 6.
- **The contradictions** — §R.7.2 × rule 8: §R.7.7 item 4; §R.7.2 × D-R3: item 3; the missing operator texts: item 8
  and §R.7.5 item 11; `--accept-unpriced`: item 9; "stored without the key → web": E-1; the token with no button: E-2;
  no data source after the window: E-2; D-7's mechanics: E-5 and item 2; the offer row: E-5 and item 1; MD3: item 5;
  ML51: E-2; ML37: item 6; who quotes by hand: E-1 and E-2; §R.13 unchanged: E-6.
- **The minor ones** — "every gated sale is `app`" (§R.4.7, §R.5): reworded to "every sale the form or the apps make",
  with the hand-made post's case tested at E-2's "submission existing" site; `CASH_UNPRICED`'s tail: E-3; two texts for
  one state: E-4; QL-CA3's plan id: §R.7.7 item 7; MD8's fixture: §R.7.7 item 10.

**Found while folding them in, and recorded:**
- **A double press of Make the quotation** could send two requests that both pass the guard before either quotation
  exists, and the customer would get two quotations and two WhatsApps. The default this design builds is a claim
  inside the shared implementation: `quote_make_claim_ts`, written under `withLock`, removed when the method returns,
  expiring after 300 s, with a C case and ML58 (§R.4.8). It goes beyond E-2's text and is for the lead engineer to
  confirm. **Confirmed and amended by §R.16** (F-9, F-10): 900 s, removed in a `finally` by a second `withLock`.
- **E-2's "guard (409, nothing written)".** When the guard finds a quotation it still records `quote_found_in_ucrm`, as
  R-M4 has it, and nothing else; when it cannot read the list it writes nothing at all (§R.9.1 C).
- **Make the quotation and `quote_found_in_ucrm`.** E-2's four conditions do not exclude an application on which the
  guard has already found a quotation. The button is drawn there too, under the card's "A quotation was made in uCRM
  after registration" line, and the guard answers each press. Recorded as E-2 has it; the deploy's count leaves such
  applications out, as D-4 did. **Superseded by §R.16** (F-10): the button is not drawn there, a fifth condition.
- **`retry_quote`'s gated path now makes its quotation through `postQuote`.** On Uganda with the gate on, three things
  differ from its own code as built: uCRM's send follows `kyc_quote_send_via_crm` (`KycService.php:90-97`), where
  `api_cron_debug.php:1120` always PATCHed …/send; `wa_quote_phone` is stored as `postQuote` stores it
  (`KycService.php:2155`), where `:1117` stored the raw mobile; and `quote_error` is not cleared, where `:1115` cleared
  it. `postQuote`'s client also takes `crm_base_url` when set (`KycService.php:2097-2099`), where `retry_quote`'s own
  takes the plugin's base URL (`:1053`), with the same token. South Sudan, and Uganda with the gate off, are unchanged.
  **Decided by §R.16** (F-10): kept, recorded as deliberate, and `quote_error` is cleared on success after all.
- **The `kyc_packages` reason** is reachable on the gated path only by a hand-made post carrying the marker and an id
  found only in `kyc_packages`: an app sale gets the entry reason first. v3's "only an application the apps made can
  reach it" is corrected (§R.4.2).
- **Under E-1 an application with `quote_sale_entry` `web` was saved by 5.18.102**, in this run's window or in an
  earlier, rolled-back run's. The count keeps D-5's window (`QLG1_APP_MAX`); the button is drawn for both (§R.7.3).
- **Make the quotation applies no auto-quote limit (P3)**, as `retry_quote` applies none: either is an admin's act. The
  cron retry keeps its P3 and is not a caller of the shared implementation, but shares `retryLines` with it (§R.4.8).
- **QL-CA3 reads the new handler too.** `kyc_make_quote` has the link actions' security shape, so QL-CA3's GETs include
  the Orders page and its text read covers that block; MD5 counts three handlers (§R.7.6).

### R.16 What the fourth check changed

The checker's report on v4 named two blockers and fifteen gaps. The lead engineer took decisions F-1 to F-14 on them.
Each is folded in below, and every checker item is mapped to the decision that answers it. One line each.

**The decisions:**
- **F-1** — `makeQuotation(array $app, ?array $known, string $via, array $actor)` takes the organization its caller has
  already checked. `retry_quote` passes what its own `checkClient` found, so `postQuote` asks nothing more; the button
  passes null, and `postQuote` checks. The shared-implementation test compares the writes on the application, the POST
  and PATCH paths and the posted lines, never the GET counts, and never `adminNotes`, which differ by entry point by
  design. Changed: §R.4.4, §R.4.8, §R.9.1 C (the shared implementation; the company check once), §R.9.3 ML64.
- **F-2** — `kyc_make_quote` refuses, with one fixed flash and nothing written, any POST field but `action`, `_csrf`
  and `app_id`. Its tests post the extra fields and are refused; the positive case's one POST goes to the stored
  client, with lines from the store and the Cash rule on the stored `amount_charged`. Changed: §R.4.8 (the form, step 4,
  the flashes), §R.9.1 C (only the id), §R.9.3 ML56 and ML65.
- **F-3** — the other suites' Uganda form posts carry `ucrm_quote_form` = `5.18.102`, and their seeded Uganda
  applications `quote_sale_entry` `web`: `test_quote_company`'s `qc_kyc_post`, `test_kyc_crm_messages`' `form()` and
  section H, `test_notify_kyc_quote_send` and `test_notify_kyc_race`, each listed with its lines. Changed: §R.9.2.
- **F-4** — the judge's methods take the quoting organization's id from their caller: the resolver's `$setting`, and
  the save's setting inside the lock. Neither constructor keeps it. Changed: §R.1.4, §R.1.5, §R.3.1 rule 8, §R.4,
  §R.7.1, §R.9.1 L (the organization comes from the caller), §R.9.3 ML66.
- **F-5** — rule 8's "offered" is the form's own predicate, one static, `UcrmQuoteLinks::offeredKit(array $row)`, and
  `kyc_form.php`'s kit loop calls it too. Changed: §R.1.5, §R.3.1 rule 8, §R.7.1, §R.8, §R.9.1 H, I and L (offered, as
  the form offers), §R.9.3 ML67–ML68.
- **F-6** — `UcrmQuoteLines.php` require_once's `__DIR__ . '/UcrmQuoteLinks.php'`, `UcrmQuoteLinks.php` requires
  nothing, and the deploy loads the pin's file by that path. Changed: §R.1.5, §R.7.1, §R.7.7 item 2, §R.9.1 I, §R.9.3
  ML69.
- **F-7** — `makeQuotation` returns `code`, `kind`, `quote_id`, `lines`, `total` and `text`, and the button's flashes and
  `retry_quote`'s answers are chosen by `kind`. Changed: §R.4.8, §R.9.1 C (each kind's answer), §R.9.3 ML70.
- **F-8** — every id in a flash is `(int)`, and `quote_found_in_ucrm`'s number goes through `h()` on the card. Changed:
  §R.4.4, §R.4.8, §R.9.1 C (escaping), §R.9.3 ML71–ML72.
- **F-9** — the claim `quote_make_claim_ts` lasts 900 s. It is taken inside `withLock`, refused while a younger one
  exists, and removed by a second `withLock` that unsets only that key, in a `finally`. A removal that throws is logged
  with ids only, and the claim expires by age. `retry_quote` answers a claim with 409 *"another quotation is being made
  for this application"*, and a Throwable with 500 and a fixed text. Changed: §R.4.8, §R.9.1 C (a double press; the
  claim's removal), §R.9.3 ML73–ML76 and ML82.
- **F-10** — the guard records `quote_found_in_ucrm` and writes nothing else. The button is not drawn where it is
  recorded, a fifth condition. The double-press claim stays. `retry_quote`'s gated path quotes through `postQuote`, and
  on success `makeQuotation` clears `quote_error`, as `retry_quote` did. The send follows `kyc_quote_send_via_crm`, and
  the client takes `crm_base_url` where set: recorded as deliberate. Changed: §R.4.4, §R.4.8, §R.6, §R.7.3, §R.9.1 C
  (the button's gate; the guard; `quote_error` cleared), §R.9.3 ML53, ML77 and ML81, §R.12 Q17.
- **F-11** — the link column has a row for two active records: both records' product ids, §R.4.1's two-records text,
  Change and Unlink. Changed: §R.1.1, §R.2.2, §R.9.1 L, §R.9.3 ML78.
- **F-12** — §R.6's hand-quoting rule is narrowed to the registrations whose screen says "Do not make it by hand".
  Changed: §R.6 (the table, the remedy, step 5), §R.7.2, §R.7.5 item 7, §R.12 Q17.
- **F-13** — on the gated path the application records `quote_made_by_id`, the fresh admin's id, and
  `quote_made_via`, `orders_card` or `retry_quote`. Changed: §R.4.8, §R.8, §R.9.1 C (who made it), §R.9.3 ML79,
  §R.12 Q9.
- **F-14** — the wording. §R.4.8 says "a refusal at steps 3–5"; §R.10 says "only the reason inside P1 changes"; §R.11
  amends `docs/71` §E.3 `:210` and §M `:612` with the release, no longer deferred by §R.7.7 item 8; §R.4.2's
  cross-reference names D11's places, `docs/71:52` and `:285-290`; §R.13 D14-P15 and §R.14 R-M4 item 3 are marked
  superseded. Changed: §R.4.2, §R.4.8, §R.7.7 item 8, §R.10, §R.11, §R.13, §R.14, §R.15 (E-1's line).

F-3, F-12 and F-14 change fixtures and words, not a rule the release enforces, so they have no weakened copy. Every
other decision has at least one: ML53, ML56 and ML64–ML82 (§R.9.3).

**The checker's items, and where each went:**
- **Blocker 1**, the shared-implementation test against two company checks and two staff's notes: F-1.
- **Blocker 2**, ML56's variants that read the client, the amount or the lines from the request: F-2.
- **The gaps:**
  - the other suites' web-path fixtures: F-3;
  - the organization's route into a judge built by `fromRecords()`: F-4;
  - rule 8's "offered" against the form's: F-5;
  - who loads `lib/UcrmQuoteLinks.php`: F-6;
  - `makeQuotation()`'s return: F-7;
  - escaping in the flash and on the card: F-8;
  - the claim's lifetime, and its write path: F-9;
  - the lead engineer's decision on the guard's write, the button beside a found quotation, the claim, and
    `retry_quote` through `postQuote`: F-10;
  - the column for two active records: F-11;
  - §R.6 against a web registration whose screen gives P1's own text: F-12;
  - who pressed Make the quotation: F-13;
  - "steps 1–5", §R.10's "P1 and P2", and the misplaced or stale cross-references: F-14.

**Found while folding them in, and recorded:**
- **`retry_quote`'s gated path re-reads the account fresh** (§R.4.8). F-13 records "the fresh admin's id" on both
  gated paths. `retry_quote` had only the API guard's record (`includes/api_handlers.php:74-84`): the token's row, or
  the session's `cached_record`, which has no age limit. So its gated path re-reads the account by id with §R.2.1's
  expression, refuses with 403 unless active and admin, and hands the fresh record on; its C case is ML80. **For the
  lead engineer to confirm.** The alternative is to hand `makeQuotation` the API guard's record. The id recorded would
  be the same, since the fresh re-read refuses a differing id; only the admin judgment would differ.
- **`makeQuotation` is a method of the service's instance** (`svc('kyc')`, `lib/Services.php:63`), because F-1's
  signature carries no client, store or settings. It uses the instance's plugin client, store and data folder, reads
  `kyc_config.json` and the gate's setting itself, and resolves and posts with `UcrmQuoteLines::quoteClient()`,
  `postQuote`'s own rule, at both entry points. The text reads (C, and QL-CA3 in §R.7.6) look for the call,
  `makeQuotation(`, not a class name.
- **The staff's notes come from `$via`**: `Retry for App #<id> · Sales: …` or `Made from the Orders card for App #<id>
  · Sales: …`. Any other `$via` throws before the claim.
- **`quote_made_via` is left out of the shared-implementation comparison**, beside `adminNotes` and the times, because
  it names its entry point by design (F-13).
- **`claimed` also covers a `quote_created` found under the claim's lock**, set by a request that finished between the
  caller's read and the claim.
- **The claim's removal unsets only this request's own claim**: only while the key still holds the value it wrote. A
  request answered `claimed` took none and removes none, so it cannot free the first request's claim (ML82).
- **`retry_quote` keeps its `QuoteOrganization` instance** to hand on `$known`: `checkClient` returns no organization,
  `lastClient()` holds the client row it read (`QuoteOrganization.php:196`), and a positive `organizationId` in
  `$known` means no client row is read (`:136-139`).
- **The made flash's id is cast because `postQuote` returns uCRM's id raw**, at `KycService.php:2159`; the check cited
  `:2165`.
- **Step 4 has its own flash**, for another field or a malformed id, beside the handler's other refusals.
- **Three §R.15 lines are marked**, as §R.13's and §R.14's are: the claim's 300 s (F-9), the button beside a found
  quotation (F-10), and `retry_quote` through `postQuote`, whose `quote_error` difference F-10 removes. §R.15's E-1
  line carried §R.4.2's misplaced cross-reference, and is corrected with it.

### R.17 What the build's review changed

The build on `quote-102-d14` (`220c4480` and its tests, to `38991cbb`) was reviewed through five lenses: money, the
gate and South Sudan, the flows (what each screen says and does), security, and the tests. Each finding was verified adversarially before it was
fixed. **Fourteen were confirmed and five refuted.** Each confirmed one is fixed in its own commit, with a test that
fails without the fix and a weakened copy that the test catches (`38991cbb..` on `quote-102-d14`). One line each.

**Confirmed, and fixed:**
- **D14-R8-INACTIVE-KIT** (major, money). A kit row deactivated with its link to the installation's product left in
  force was priced beside the installation, so a "DishNet installs" sale holding it was quoted with the installation
  twice — through a retry of a stored cart, Make the quotation, the cron retry, or marked lines. The kit half of rule 8
  now applies at use to every kit row (§R.3.1 rule 8, §R.4.1, §R.6 step 7). Tests A32 and C28; copy ML37b.
- **D14-MQ-THROW-AFTER-POST** (minor). A Throwable after `postQuote`'s POST had made the quotation was answered "No
  quotation was made — try again". Step 5's write is now in its own try, and the two Throwable texts and their log
  lines no longer say that none exists (§R.4.8). Test C22; copies ML83a–ML83c.
- **G2** (minor). With the gate on and the quoting-organization setting invalid, every link save refused with a false
  "changed while it was being linked … try again". It now refuses after the admin and own-page checks, before any uCRM
  read, naming the setting (§R.1.4, corrected by §R.17.1). Test L-R6; copy ML-R6.
- **G3** (minor). The release's `SAFETY.md` entry still described matching by name. It is rewritten for D14. Test I6b;
  copy MI11. (This line first said I6b had 5.18.102's first cut as its control. It had none: §R.17.1, FX-tests-2, and
  §R.17.2, CK-tests-2.)
- **D14-UI-1** (minor). Send Quotation told a lead whose plan two rows share that no plan is spelled like it. It now
  names the cause (§R.4.5). Test F7; copies ML-UI1a and ML-UI1b.
- **D14-UI-2** (minor). Send Quotation's "not priced" alert ended "fix the product in uCRM" after a link reason that
  names another remedy (§R.4.1). Test F5; copy MF-alert.
- **D14-UI-3** (minor). The KYC form's "DishNet installs" card showed the installation's reason only as a tooltip,
  which a phone never shows (§R.5). Test H10; copy MH-UI3.
- **D14-UI-4** (minor). The Orders card drew the found-quotation line above "No quotation was made: … an admin makes it
  from the Orders card" (§R.4.8). Test C17; copy ML-UI4.
- **D14-UI-5** (minor). The KYC form's default plan and kit were chosen by matching uCRM's names, and could fall on an
  unpriced row. They are chosen by the row's own name (§R.5). Browser block 10 and H13; copies MH-UI5a–MH-UI5c.
- **T1** (major, tests). The "gate off never opens the store" check did not run the KYC submission, the retries or
  Send Quotation. L19b does (§R.9.1 L); copies ML19b–ML19g.
- **T2** (major, tests). No test showed that the Hardware screen's picker pre-selects nothing. L14 does (§R.9.1 L);
  two ML17 copies on `hardware.php`.
- **T3** (major, tests). The cron retry's `quote_link_ids` write was untested. C26 tests it (§R.9.1 C); copy ML39 (C).
- **T6** (minor, tests). L19 did not assert that its gate-off posts reached their handlers. It does (§R.9.1 L); copies
  ML19h and ML19i.
- **T7** (minor, tests). C9's "without the marker it stays unpriced" half was untested. It is (§R.9.1 C); copies MC9b
  and MC9c.

**Refuted as defects of this change, with the reason the verification gave:**
- **SEC-D14-1**, Make the quotation and `retry_quote`'s gated path logging uCRM's whole quote answer (the staff's
  notes with the salesperson's name, item labels, prices). The mechanics hold: `postQuote` logs the answer. But every
  KYC quotation already logs it through that same line, in 5.18.101 (live) and before D14, and §R chose one
  implementation (F-1, F-10). D14 adds two callers of an existing log line with the same kind of data. **Recorded as
  pre-existing, not changed by 5.18.102**, for a later release.
- **SEC-D14-2**, the shared path reachable cross-site by a GET to `retry_quote`, with no own-page check. The
  reachability holds: the session cookie is `SameSite=None` and the API path checks who is signed in, not where the
  request came from. But 5.18.101's `retry_quote` already made a quotation the same way, as does every state-changing
  GET admin action in `api_cron_debug.php`; D14 narrows it, adding R-M4's guard, the claim and the fresh admin re-read.
  **Recorded as a pre-existing exposure of the API guard**, for a later release.
- **G1**, `P1_UNLINKED`'s "Do not make it by hand" stored where gate-off code draws it: §R.4.1 stores it there on
  purpose, the gate-off card must stay 5.18.101's byte for byte (§R.4.8, §R.8), and §R.7.4 records the consequence in
  the rollback note.
- **T4**, copies split per section: deliberate and documented in each copy file; the runner requires every section a
  copy names to fail, so each half is judged where §R.9.3 places it, and no coverage is lost.
- **T5**, section C's router standing without the global CSRF gate: deliberate (§R.4.8 step 3, "made by the handler
  itself and never left to the global gate"); in production the global gate refuses the same two posts first.

**Also found while fixing, and recorded:** T6's proposed copy names ML19b and ML19c were already T1's, so T6's are
ML19h and ML19i. The two Throwable log lines were reworded with their texts, and ML76's anchor with them.

#### R.17.1 The check of the fixes (round 2)

An independent check of the fourteen fixes (`38991cbb..9ff9d33a`) confirmed five more findings, all minor. Each is
fixed in its own commit (`9ff9d33a..c68b508e`), with a test that fails without the fix and a weakened copy the test
catches:
- **FX-money-1** (with FX-flows-1). The save still applied rule 8's kit half to offered rows only. An inactive kit row
  could be linked to the installation's product and was then refused at use, and the screen's remedy went round in a
  loop. Both save checks now apply it to every kit row, and an inactive row's refusal names no deactivation (§R.3.1
  rule 8, §R.4.1). Test L8; copies FXM1a and FXM1b, and ML37b re-anchored.
- **FX-money-2**. §R.1.4 put the setting check first. The code rightly runs it after the admin and own-page checks:
  in §R.1.4's order a non-admin and a cross-site post would have been shown the setting's text. §R.1.4 is corrected,
  and the handler's ordered list names the step (2a). Test L-R6b; copy ML-R6b.
- **FX-flows-2**. The `error` answer said uCRM "did not make" the quotation, though a lost answer (a timeout, a 504)
  reaches it after uCRM made one. Both texts now say "did not confirm … it may have been made" (§R.4.8). Test C22
  (c); copies ML84a and ML84b.
- **FX-tests-1**. L19b's `retry_quote` and Send Quotation entries did not prove they ran to their end. They do, and
  L19's draws assert `end === 'drawn'`. Copies ML19j and ML19k.
- **FX-tests-2**. §R.17 claimed an I6b control that did not exist. I6b now has one, written out in the test (§R.9.1 I),
  without a Git read: the first cut, `9e665655`, is not in the release's history. Copy MI11b.

#### R.17.2 The check of round 2 (round 3)

A check of those commits confirmed two more, both minor:
- **CK-flows-2**. Under the new "may have been made" flash, the Orders card still drew "⚠ No quotation was made" with
  the stored refusal, whose P1 text says to make the quotation in uCRM by hand. Make the quotation's `error` answer now
  records `quote_unconfirmed_at`. With the gate on, the card draws "⚠ uCRM did not confirm the last quotation" in the
  refusal's place, and the button stays. A later press whose guard finds no quotation removes the key; an unreadable
  list keeps it. With the gate off the card is f2055baf's byte for byte (§R.4.8). Tests C22 (c) and (d), and G8;
  copies ML85a–ML85e and MG26.
- **CK-tests-2**. I6b's control covers its `gone` half only. The four `need` texts are shown absent from two
  written-out lines, not from the first cut's entry, so a `need` text the first cut already held would pass. Measured
  once instead, on 30 Oct: none of the four occurs in `9e665655`'s 5.18.102 entry, and each `gone` text occurs once.
  The test is unchanged, since the release cannot read the first cut.

#### R.17.3 The check of round 3 (round 4)

A check of round 3's commit (`1c4b8306`) confirmed five more, all minor; none could duplicate a quotation. Fixed in
`53f9e42b`, each with a test that fails without it and a weakened copy:
- **CK4-F1.** After a Throwable from `postQuote` that followed its POST, the card still said "No quotation was made"
  under a flash saying uCRM may already have it. The Throwable now records `quote_unconfirmed_at` first. A Throwable
  before the POST records it too, which errs safe: the card says "may have been made", and the next press's guard
  settles it. C22 (b); copy ML85j.
- **CK4-F2.** The box named Make the quotation where the button is not drawn: for an agent, and on an application
  `retry_quote` reached that the five conditions exclude. The sentence now follows the button's own condition (§R.4.8).
  C22 (d); copy ML85k.
- **CK4-F3.** The key was removed only when the caller's `$app` held it. `retry_quote` reads `$app` before its own
  company-check GET, so a key written meanwhile outlived a guard that had found no quotation, and the card said "may
  have been made" over a quotation shown not to exist, with the refusal hidden. Removal now reads the row under the
  lock, always. C22 (d), with the proxy's new `hold_client`; copy ML85l.
- **CK4-F4.** Four weakenings survived every test: the box beside `quote_created`, the box for admins only, every
  application's key removed, and the key recorded on a company-check refusal. Each now fails a test (C22 (d), C19);
  copies ML85f–ML85i.
- **CK4-F5.** A rollback leaves the key for 5.18.101, whose `retry_quote` POSTs without looking first. §R.7.4 adds a
  count and a sentence to the rollback note.

The router's header now says which lock the `second_lock` fault reaches: the claim's removal where the guard answers
first, else the key's removal. The three round-3 copies whose anchors round 4 moved (ML85b–ML85d) were re-anchored and
are caught as before.

#### R.17.4 The check of round 4 (round 5)

A check of rounds 3 and 4 (`1c4b8306`, `53f9e42b`), by a behaviour lens and then a tests lens, confirmed nine more,
all minor; none could duplicate a quotation. Each was shown by a run. Fixed in `ec11b208`, each with a test that
fails without it and a weakened copy:
- **CK5-B1.** The `error` answer's own write of `quote_unconfirmed_at` sat in no try. A store failure there turned
  the answer into the Throwable's, recorded no key and logged nothing, so the card fell back to the stored refusal
  while uCRM may hold the quotation. Both writes of the key are now one helper, `recordUnconfirmed()`: a failed
  write is logged with ids only, and the answer stays what it was. C22 (c); copies ML86a, ML86f, ML86i.
- **CK5-B2.** After a Throwable that followed `postQuote`'s read-back (its `quote_ref` write failing, say), the card
  said "uCRM did not confirm the last quotation" beside the read-back's "The quotation uCRM made totals …". uCRM
  had confirmed it; what did not finish was the record here. The heading is now *"⚠ Making the last quotation did
  not finish"* and the sentence *"It may have been made in uCRM."* — true after a lost answer, an answer with no
  id, a Throwable before the POST, and one after the read-back. The flashes are unchanged (§R.4.8). C22, G8; copy
  ML86b.
- **CK5-B3.** Nothing pinned that a failed removal of the key stops the press. Swallowed, the press went on and
  recorded a fresh refusal under the box, which hides it. C22 (d), with `second_lock` reaching the key's removal;
  copy ML86e. Nor that the `found` answer records `quote_found_in_ucrm` and nothing else, leaving the key (the
  found line outranks it on the card). C22 (c); copy ML86g.
- **CK5-T1.** No test pressed an application already holding the key whose answer went unconfirmed again. A "keep
  the first stamp" edit that reads the caller's `$app` lost the key, since the guard had just removed it from the
  row. C22 (d), for a lost answer and for a Throwable after the POST; copies ML86j, ML86k.
- **CK5-T2.** A card that died drew no box either, so "no box, no button" passed on a fatal page. Every card
  expectation now also needs the card drawn to its bottom row. Copy ML86d.
- **CK5-T3.** "It may have been made" was pinned nowhere. The card's expectation needs the heading and the
  sentence. Copy ML86c.
- **CK5-T4.** Every planted fault was a `RuntimeException`, so the catch's `\Throwable` was not pinned. The router's
  `after_post_error` throws an `\Error`. C22 (b); copy ML86h.
- **CK5-T5.** The log line for a failed key write was pinned nowhere. The router's `after_post` takes several keys,
  and C22 (b) fails the key write too and reads the log. Copies ML86f, ML86i.
- **CK5-T6.** Two checks did not show what their labels said. C19's `retry_quote` half held whatever the method
  did, since `retry_quote`'s own company check refuses first; it is now labelled as that refusal. MG26 was caught by
  a fatal error, not by the box: its gate-off page called `KycService`, which is loaded only with the gate on. It now
  makes the sentence call nothing too, and G8 sees the box itself.

ML85b, ML85i and ML85j were re-anchored (the helper's two calls; C19's new label) and are caught as before. Section C
has 320 assertions (303 before); the whole test, 1255 passed, 0 failed; weakened copies 317 (306 before).

#### R.17.5 The check of round 5 (round 6)

A check of round 5's commit (`ec11b208`), by a behaviour lens and then a tests lens, confirmed two major findings and
five minor ones. Each was shown by a run, and none came from round 5's own change. Fixed in `949f7fa3`, each with a
test that fails without it and a weakened copy:
- **CK6-F1 (major).** While a press was in flight, the card went back to the stored refusal: a press removes the key
  before its POST, and the card never read the claim. Where the stored text was P1's own (not `P1_UNLINKED`'s), it
  told staff to make the quotation in uCRM while the plugin's POST was under way. The window existed since the button
  was built, for an application with no key as well. A live claim (younger than `QUOTE_CLAIM_SECONDS`, no
  `quote_created`) now draws its own box, "⏳ Make the quotation was pressed and has not finished", ahead of the
  "did not finish" box and the refusal, with the gate on (§R.4.8). C22 (e), G8; copies ML87a–ML87c, MG27.
- **CK6-FT1 (major, a test gap).** The admin's sentence "Do not make one by hand in uCRM: Make the quotation looks
  there first …" was matched by its second half only, so a copy that dropped or reversed "Do not make one by hand"
  passed every test. It is matched whole. Copy ML87d.
- **CK6-F2.** CK5-T2's "card drawn to its bottom row" was met by the stylesheet's rule for that class, on every page.
  It is now the card's own bottom-row element, counted once. §R.17.4's CK5-T2 and §R.9.1 C were untrue until this.
  Copy ML87e (a card that stops before its bottom row, with no fatal error).
- **CK6-F3.** `recordUnconfirmed()` sat under `dropUnconfirmed()`'s docblock, which then described the opposite of
  what it does. Each has its own now. Not pinned: a comment.
- **CK6-FT2.** Nothing checked that it is `postQuote`'s Throwable that goes on when the key write fails too: every
  planted fault was a `RuntimeException`. C22 (b) now plants an `\Error` and a `RuntimeException`, and the caller's
  line must name the `\Error`. This also checks the router's `after_post_error` itself. Copy ML87f.
- **CK6-FT3.** "The answer stays what it was" (CK5-B1) was checked for an `\Exception` only. C22 (c) runs the key
  write failing by an `\Error` too. Copy ML87g.
- **CK6-FT4.** Two CK5-T1 labels said "removed by the guard", which they do not check (six other lines do); they say
  "recorded afresh (a new time), so never lost". C19's `retry_quote` half now compares the whole card.

Section C has 334 assertions and G 201; the whole test, 1273 passed, 0 failed; weakened copies 325 (317 before).

#### R.17.6 The check of round 6 (round 7)

A check of round 6's commit (`949f7fa3`), behaviour and tests in one pass, confirmed one major finding, older than
round 6, and six minor ones; each was shown by a run. Fixed in `de2b5b74` with tests and weakened copies, except
CK7-F2, recorded:
- **CK7-F1 (major).** A press that dies after its POST (a fatal error, a killed worker) runs neither the catch nor
  the finally: its claim stays, and the key its guard removed is not written again. Once the claim expired the card
  drew the stored refusal again, whose P1 text says to make the quotation in uCRM, while uCRM held it. Every answer
  and every Throwable removes its claim, so a claim still there past `QUOTE_CLAIM_SECONDS` now draws the "did not
  finish" box (§R.4.8). C22 (f), with the router's `fatal_after_post`, and C22 (e)'s 901 s case; copy ML88a. The
  deploy script's rollback count (`qlunconf`) counts such an application too.
- **CK7-F2.** A claim whose removal failed (logged) draws the in-flight box with no press running, for up to
  `QUOTE_CLAIM_SECONDS`, over a fresh refusal. Recorded, not changed: the box says not to make one by hand, a press
  meanwhile is answered `claimed`, and past that age the "did not finish" box sends an admin to Make the quotation,
  which settles it (§R.4.8).
- **CK7-F3.** The heading said "Make the quotation was pressed" also while `retry_quote` held the claim, and "wait a
  few minutes" understated a wait of up to fifteen. It now reads "⏳ Making a quotation for this application has not
  finished" and "wait, then open the Orders screen again".
- **CK7-T4 to CK7-T7.** Four edges of the in-flight box were not pinned: its precedence over the key's box, the age
  limit between 600 s and 901 s (now 870 s, beside the method's own `claimed`), the found line outranking it, and no
  box beside a made quotation. C22 (e); copies ML88b–ML88e.

ML85a, ML85f, ML85g, ML86d, ML87c and MG26 were re-anchored on the changed lines and are caught as before. Section C
has 344 assertions and G 201; the whole test, 1283 passed, 0 failed; weakened copies 330 (325 before).
