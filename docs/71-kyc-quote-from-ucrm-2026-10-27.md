# 71 — Uganda: the plugin's quotations take their lines and prices from uCRM (5.18.102 design, 27 Oct 2026)

Status: **design, under review.** Nothing here is built. The release is cut on the live 5.18.101 (`f2055baf`).

## A. Why

Quotation 000314 (27 Oct) was made by the plugin when a KYC registration was submitted. uCRM rendered it with the
four-page template (docs/67 §L), but the plugin filled it with wrong information:

1. **Internal notes on the customer's document.** The NOTES box read "PENDING | Auto-generated on KYC registration.
   Sales: … | Connection: New Connection | Priority: Medium". The plugin writes this into the quote's `notes`, which
   the template prints (`KycService.php` 1125–1127; the retry `KycCrmSync.php` 299–305). "PENDING" is the placeholder
   reference `saveApplication` stores before uCRM gives a number (1868); it is never replaced on the uCRM quote.
2. **"Prepared by" named the wrong person.** The template prints the client's custom attribute `salesPerson`, else a
   default name. On Uganda the plugin sends no client attribute at all (`UcrmClientTarget::apply` strips them because
   field 1 there is the EFRIS TIN), and Uganda's uCRM has no `salesPerson` attribute (docs/30, 25 Sep).
3. **The lines are not uCRM's.** Every KYC line is a uCRM "custom" item (the PDF prints "CUSTOM - MONTH"), priced by the
   plugin: the plan from `subscription_plans.json`'s `customer_price`, the kit from the price text the browser sends.
   Nothing reads uCRM's price. The only "sync" is one way, plugin → uCRM ("Sync All", saving a plan), which overwrites
   uCRM's prices. The website, by contrast, reads its prices live from uCRM (`prices.php`), so the two can disagree.
4. **No installation line** on a Starlink quote (the builder adds one for Fiber only).
5. **The cash the agent collects** (wallet check, wallet debit, the uCRM payment) is computed apart from the quote, from
   the plugin's prices: the plan plus one device, ignoring the cart, with no installation (`resolveAmount`).

## B. The operator's decisions (27 Oct)

| # | Question | Decision |
|---|---|---|
| D1 | Turn the auto-quote off, or fix it? | **Keep it and fix it**, in a plugin release |
| D2 | Installation on a Starlink quote | **A separate line only when the form says DishNet installs**, from uCRM's Professional Installation product; the website's "installation included" wording is to be corrected |
| D3 | "Prepared by" | **The operator adds a client custom attribute "Sales Person" in uCRM**; the plugin finds it and fills it |
| D4 | A plan or kit that matches no uCRM product | **No auto-quote; the agent is told** to make the quotation in uCRM |
| D5 | The cash amount on a Cash sale | **Equals the quotation's total**; the form shows uCRM's prices; a Cash sale is refused when the total cannot be priced from uCRM |
| D6 | The base | **5.18.101 is live**; 5.18.102 is cut on it |

These carry the earlier decision of 20 Oct (docs/07): "uCRM Products are the true price list."

## C. Scope

**Uganda only.** The gate is the tenant, as `QuoteTaxLine::applies` (5.18.49, reused by 5.18.101): `TenantProfile`
id `uganda`. South Sudan's requests, texts, PDFs and store stay byte for byte as 5.18.101 leaves them.

**In:** the five quote-create sites and their notes (§F), the KYC form's prices and installation question (§G), the
cash amount (§H), "Prepared by" (§I), the Send Quotation plan picker's prices (§J), and the plugin's fallback PDF's
terms on Uganda (§K).

**Out (recorded, not changed):** the quote template (no upload needed for this release); the website's "installation
included" wording (a website change, after this release); the plugin → uCRM push actions ("Sync All", saving a plan or
a kit), which still overwrite uCRM prices and must not be used on Uganda until the price-list release; the lead
"manual quote" rows that staff type themselves; uCRM VAT configuration.

## D. The catalogue: one resolver, `lib/UcrmQuoteLines.php`

One class prices every Uganda line, so the form, the cash amount and every quote agree.

**Read.** `GET products` and `GET service-plans`, with the same client the quote is made with. At submission the read is
fresh; for drawing the form a 60-second cache of a *successful* read is allowed (never of a failure). Never the
`ucrm_products.json` copy.

**Keys.** `PublicPriceFeed::planKey` (lower case, the word "starlink" removed, letters and digits only) — the rule the
website, the assistant and the WhatsApp summary already use.

**A plan line** (from the `subscription_plans.json` row, else `kyc_packages.json`, by `package_choice`):
- the live product whose key equals the plan row's name key, **exactly one**;
- that product must be a *plan mirror*: its key equals exactly one live service plan's key;
- the product's price must equal that service plan's `PublicPriceFeed::planPrice` (the 1-month period). If they differ,
  the plan is unpriced — the customer would be quoted one monthly price and billed another;
- a stored `ucrm_product_id` is used only if that live product exists and its key equals the row's key (the fuzzy
  auto-map can have set a wrong id); otherwise it is ignored and the key match decides.

**A kit or accessory line** (from the cart's `id`s, else `device_id`): the `kyc_devices.json` row by id — **the browser's
title and price are never used** — then the live product whose key equals the row's title key, exactly one, which must
**not** be a plan mirror. Quantity from the cart (or `kitQty`), at least 1.

**The installation line** (D2): the live product whose key equals `planKey('Professional Installation')`, exactly one,
unless `kyc_install_product_id` (new, optional, no default) names an existing live product. Its price must be above 0
(a 0 installation line would read as "free installation" to `QuotationPrefill`).

**Each resolved line** is `{productId, label: the product's name, price: the product's price, quantity, unit: the
product's unit, else 'month' / 'piece' / 'job'}`.

**Any line unresolved, or uCRM unreadable, means the sale is unpriced** — all or nothing, never a partial quote. The
resolver returns the reason in plain words naming the item (e.g. *"Starlink Mini Kit is not a product in uCRM"*,
*"DishNet Lite's price in Products (…) differs from its service plan (…)"*, *"uCRM's products could not be read"*).

**Fiber on Uganda:** the Fiber installation fee setting is South Sudan's (USD label, product 244). On Uganda a Fiber
line is priced by the same resolver from a product keyed like the Fiber installation product, or the sale is unpriced.
(Uganda sells no Fiber today; this only stops the South Sudan default leaking.)

## E. The installation answer

The KYC form asks, for a **Starlink** customer whose connection is **New Connection** (or the existing-customer
"additional service" path), a required question with no preselected answer:

> Who installs? ◯ DishNet installs  ◯ Customer installs (kit only)

Stored on the application as `install_by` = `dishnet` | `customer`. "DishNet installs" adds the installation line;
"Customer installs" adds none. **Shifting Connection and Ownership Change** get no question and no line (re-installation
is quoted separately, template clause 14). A submission without the answer (the retailer app, the API) is **unpriced**
(§D) rather than guessed.

## F. The quote-create sites (exactly the five 5.18.99 counted; no sixth)

| Site | Lines on Uganda | `notes` (customer sees) | `adminNotes` (staff only) |
|---|---|---|---|
| KYC new (`handleNew` → `postQuote`) | resolver; unpriced → no quote | `kyc_quote_notes_prefix` if set, else empty | `KYC application #<id> · registration · Sales: … · Connection: … · Priority: … · Install: …` (+ `Plugin ref: …` when not `PENDING`) |
| KYC retry (`KycCrmSync::afterCreate`) | the stored resolved lines; an application with none stored, or with custom lines, is resolved now; unpriced → no quote | same rule | same, with `reached uCRM later` |
| Existing customer (`handleExisting`) | resolver; unpriced → no quote | the prefix only | the whole "ADDITIONAL SERVICE" block (address included) + the application id |
| Send Quotation / lead / LTE (`QuotationService::createCrmQuote`) | the caller's lines (§J) | the prefix + the staff's own "Note / Footer message" | `Plugin ref: … · Agent: …` |
| Admin debug retry (`api_cron_debug` `retry_quote`) | as the KYC retry; unpriced → 409 | the prefix only | `Retry for App #<id> · Sales: …` |

- Every check happens **before** the quote is created: uCRM's `quote.add` webhook messages the customer at once.
- An unpriced sale writes the 5.18.99 refusal fields (`quote_refused`, `quote_refused_text`, `quote_refused_at`) and
  the agent's result message says why and to make the quotation in uCRM. It never writes `wa_quote_pending`.
- `PENDING` never reaches uCRM: it is treated as "no reference" wherever a reference is composed.
- Empty `notes` makes the template's NOTES box disappear.
- The prefix stays customer-facing; the Settings field gains a hint saying so, and its "+211" example is replaced on
  Uganda.

## G. The KYC form on Uganda

- Plans and kits are drawn with **uCRM's price** (from the resolver, cached ≤ 60 s). A row that does not resolve shows
  "not priced in uCRM" and adds nothing to the total; selecting it shows that no quotation will be made and a Cash sale
  is not possible.
- The installation question (§E); "DishNet installs" adds the installation line to the totals.
- If uCRM cannot be read when the form is drawn, a banner says so; the form still opens for Credit registrations.
- The form posts the total it showed (`ucrm_total_seen`). On a Cash sale the server refuses if its fresh total differs
  ("prices in uCRM changed since the form was opened — reload").

## H. The cash amount (D5)

On Uganda, `checkAmount` is the resolved total (Σ price × quantity, installation included when DishNet installs):

- used for the wallet check, the wallet debit, the payment posted to uCRM, `amount_charged`, the collection and cashbook
  entries — everywhere the old amount was;
- **Cash sale and unpriced → the registration is refused before anything is written**, with the reason;
- **Credit sale and unpriced → the registration proceeds**, with no quote, and the agent is told;
- `kyc_auto_quote_max_amount` still only suppresses the quote.

South Sudan keeps `resolveAmount` as it is.

## I. "Prepared by" (D3)

- `UcrmClientTarget` gains a lookup **by key**: exactly one client attribute whose key is exactly `salesPerson`, that is
  not a tax field (`EfrisClientField::isTaxField`). It reuses the one cached `GET custom-attributes`.
- **New KYC customer:** after the client is created and **before** the quote, a separate
  `PATCH clients/{id} {attributes: [{customAttributeId: <found id>, value: <sales person>}]}`. The create request itself
  is unchanged (an unknown attribute id fails the whole create with a 404, docs/30).
- **Best effort, never blocking:** attribute absent, ambiguous or unreadable → no PATCH, one log line, the quote is
  still made (the PDF shows the template's fallback). A failed PATCH is logged and the registration continues.
- **Existing customers:** no PATCH (whether a one-attribute PATCH merges or replaces the client's attributes — and so
  could erase the EFRIS TIN — is not measured).
- Never attribute id 1, never the South Sudan ids, never a name-based guess.

The operator's one-time setup: uCRM → System → Customisation → Custom attributes → Client → add **"Sales Person"**, then
confirm the key shown is exactly `salesPerson`.

## J. Send Quotation on Uganda

- The plan picker's prices come from the resolver (plan mirrors), not `customer_price`; a plan with no uCRM price is
  listed without a price.
- `buildLeadItems` (a lead's interest plan) uses the resolver; no exact match → no automatic line (the screen already
  says "add items manually").
- Lines staff type themselves are theirs and are sent as typed.

## K. The plugin's fallback PDF on Uganda

When uCRM serves no PDF in time, `PluginQuotePdf` renders one. Its non-USD terms still say "penalties or reconnection
fees follow your service agreement", "best-effort", "commitment periods … follow your service agreement", "report faults
within 24 hours" and "not liable … exceeding 3 months of fees" — withdrawn on 12 Oct (docs/67 §I, §K). On Uganda they
become a short block that defers to the published terms:

1. All prices in UGX, taxes included. Prices may change with 30 days' notice.
2. This quotation is governed by DishNet's published Terms of Service, Terms of Sale, Warranty Policy and Returns,
   Refunds and Cancellations (dishnetuganda.com/policies.html); where anything here is less favourable to the customer,
   those terms apply.
3. Installation as quoted; non-standard mounting is quoted before work starts.
4. Responsibility and its limits as section 14 of the Terms of Service sets out; events outside DishNet's control as
   section 15.
5. Governed by the laws of the Republic of Uganda.

The fallback still carries no Service Agreement (recorded as a known limit).

## L. Tests and proof (each rule with a weakened copy that must fail)

- A new `tests/test_quote_from_ucrm.php` on fakes: the resolver's rules (exact key, plan mirror, plan-price equality,
  the stale mapped id, the browser's price ignored, installation by answer, all-or-nothing, the unreadable catalogue);
  each site's request body (lines, notes, adminNotes); the cash amount and its refusals; the form's prices and the
  posted-total check; the salesPerson PATCH before the quote, by key, never id 1, absent → no PATCH; the fallback terms.
- South Sudan byte-for-byte: `test_quote_company` S (journeys J1–J14 for `ss`, `ss_keyset`), unchanged.
- Rewritten on purpose, never weakened: `test_kyc_crm_create` (the quoted lines and the 'reached uCRM' notes on
  Uganda), `test_quote_company` I4 / I1 / S's `ug_off` journeys, `test_quote_summary` M (re-pointed between two commits),
  the version pins.
- Carried: the two date-test fixes 5.18.101 left for the next release (known limit 8).
- Release suite twice; deploy script derived from 5.18.101's; rehearsal chained on it; one clean trial; three reviews.

## M. What the operator does

1. Before deploying: add the "Sales Person" client attribute (§I) and check that Products hold a product for every plan
   and kit sold on the form, spelled like it, plus "Professional Installation", each at the right price; and that each
   plan's product price equals its service plan's 1-month price.
2. Leave Settings → "Quote notes prefix" empty, or put in it only text meant for customers.
3. Do not use "Sync All", or save a plan or kit, on Uganda: they overwrite uCRM's prices with the plugin's.
4. Deploy 5.18.102 with its command; the proof is the next real KYC quotation.

## N. Open, and not decided here

- Whether uCRM 4.5.33 keeps the line's own label, price and unit when `productId` is sent, or replaces them with the
  product's (both are the product's here, so either way the quote is right).
- Whether uCRM stores `adminNotes` and shows it only to staff (the plugin has always sent it; nothing reads it back).
- The website's "installation included" wording (D2: to be corrected in a website change).
- South Sudan has the same "PENDING | Auto-generated …" notes; whether to fix it there is a separate decision.
