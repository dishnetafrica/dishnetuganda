# 71 — Uganda: the plugin's quotations take their lines and prices from uCRM (5.18.102 design, 27 Oct 2026)

Status: **design, reviewed (§O); built and committed as `release/5.18.102` = `9e66565` (local, not pushed; re-cut from `00d2be1`, see §P.5); what the
build changed from this design is in §P; the deploy script's own decisions are in §Q.** The release is cut on 5.18.101
(`f2055baf`), which is live according to the operator (D6). Its deployment record is still pending in docs/07, and the
deploy script cannot be handed over until it is recorded (§M).

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
| D11 | Applications saved before 5.18.102 that are retried | **A Cash one is never re-priced.** A Starlink New Connection without an installation answer is unpriced (§F.4) |
| D12 | The plugin → uCRM product push (Sync All, the per-row sync, the sync when a plan or kit is saved) | **Refused on Uganda**: it would change the price list the quotes and the cash now come from (§K.3) |
| D13 | `kyc_install_product_id` (an override for the installation product) | **Dropped.** Nothing could write it; the installation product is found by its name alone |

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
  the assistant and the WhatsApp summary already use it.
- **An empty key matches nothing.** (`planKey('Starlink')` is empty.)
- **Every "exactly one" counts all counted rows.** Two products with one key make the item unpriced (*"two products in
  uCRM are spelled like X"*), even when a stored `ucrm_product_id` names one of them. A stored id only confirms a match;
  it never resolves an ambiguity, and an id whose live product's key differs from the row's key is ignored (the fuzzy
  auto-map can have set a wrong one).

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
  - a Credit Starlink New Connection from them is registered with no quotation.

  The retailer app shows a fixed "Application Submitted!" and does not display the server's message, so for those Credit
  registrations nobody is told there is no quotation; the Orders card is the only place it shows. The app is not changed
  in this release (§C), and §M tells the operator.
- **The existing-customer path** (`handleExisting`) is reached only through the mobile API with a `customer_id`; the web
  form's `customer_id` is always empty and the retailer app has no such field. It therefore sends no installation answer,
  and a Starlink New Connection there is unpriced unless the API gains the field.
- The retailer app picks plans from `kyc_packages`, while the server looks `package_choice` up in `subscription_plans`
  first (`KycService.php` 196–197, 1381–1384). If the two id ranges overlap on Uganda (not established), the resolver
  prices the subscription plan, not the app's choice. This is today's behaviour, recorded.

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
  counted. If one is not, the application is unpriced with that reason.
- **A Cash application (`amount_charged` > 0) is never re-priced.** It is quoted only when the lines it would send total
  exactly `amount_charged`, both rounded to 2 dp: its marked lines, else lines resolved now. Otherwise no quote is made,
  and the refusal reads *"paid UGX <amount_charged> at registration — make the quotation in uCRM for that amount"*. That
  covers every Cash application saved before 5.18.102, whose cash came from the old `resolveAmount`.
- **A Credit application without marked lines** is resolved now. A Starlink New Connection without `install_by`, which
  every application saved before 5.18.102 is, is unpriced (D11).
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
sections, their weakened copies, and the form's browser check).

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

**Before deploying:**
1. **Record 5.18.101's deployment** in docs/07: its copy time and counts, its `--after-only`, and the quarantine's
   `--check` begun after that copy. The 5.18.102 script takes its baseline and its A14 window from that record. Its
   deploy log is needed for this.
2. **Add the "Sales Person" client attribute** (§I) and confirm that its key is exactly `salesPerson`.
3. **Check the catalogue.** Each plan offered on the form must have the same key (case, punctuation and the word
   Starlink ignored) in three places: its plugin plan name, exactly one uCRM Product, and exactly one uCRM Service Plan
   with an enabled 1-month period at the Product's price. Each kit and accessory needs exactly one Product spelled like
   it, and there must be one "Professional Installation" Product. Every one of them must be priced above 0, with Taxable
   off and its invoice label empty or the same as its name. The deploy's preflight measures all of this before anything
   changes; in September only two of five service plans had a Product.
4. Leave Settings → "Quote notes prefix" empty, or put in it only text meant for customers.

**What changes for agents and staff on Uganda:**
- A Cash sale is refused when any item has no usable uCRM price, during a uCRM outage (not saved for retry, as before),
  and when the form was opened before the deploy. Register it as Credit, or wait.
- A Cash sale from the retailer app or the mobile API is refused; those channels cannot answer "Who installs" or send
  the total they showed. A Credit Starlink New Connection from them gets no quotation, and the app does not say so; the
  Orders card does.
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
  `lib/QuoteOrganization.php`). The rollback then proves that nothing in 5.18.101 reaches them, file by file.
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
    (HMAC) under the run's own key, which is never printed and never stored.
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
    client; and the `kyc_packages` rows that the retailer app and the mobile API price.
- **D-R5 — privacy.** The log carries only counts, read states, HTTP codes and uCRM attribute ids. Row names, product ids,
  units and the resolver's reason texts go only to the run's own sheet (mode 0600), because a reason can carry a price.
- **D-R6 — the kept files.** The script pins 55 files: 5.18.101's 63 kept files minus the 14 that 5.18.102 changes;
  `webhook.php` and `tests/test_sales_numbers.php`; and four files 5.18.102 reads or §L.4 names (`lib/PublicPriceFeed.php`,
  `lib/EfrisClientField.php`, `includes/api/api_retailer.php`, `retailer/index.html`).
- **D-R7 to D-R15 — mechanics.**
  - The copy's time is read off `lib/KycService.php`.
  - The preflight builds its own GET-only clients with `withTimeouts(5, 30)`. It never calls `quoteClient()` or
    `forPage()`.
  - Quotation 000314 is read back by its stored quote id. The log shows each item's type, whether it has a product id,
    and its unit: no label and no price.
  - The other notes of §L.3:
    - the `salesPerson` attribute;
    - line shapes by sale type;
    - an entry point, which the record does not carry, so the note says "not measurable";
    - the last plan save and kit creation, as dates only (the plugin records no push);
    - the settings the customer's notes and P3 depend on, as set or unset only.
  - The check ids are QL-G1, QL-N1–QL-N7, QL-CA1, QL-CA2, QL-RB1 and QL-RB2. 5.18.101's QS checks are carried; with
    `webhook.php` kept, their before, after and rollback readings are all 5.18.101's line.
  - The event-processor, persona and T4 signatures are measured again on the release by the rehearsal before they are
    pinned.
  - The release's hash is filled into the script only by a separate re-pin step, which checks the release's 59 sha256
    values against Git.
