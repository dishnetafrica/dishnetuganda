# VAT: what the Starlink invoice tells us, and what to configure

Advisory. **No code changed.** The AI needs no change either — it reads what
uCRM holds, so configuring uCRM is what turns the tax line on.

This is not tax advice. It says what the document states and what the system
needs in order to model it; your accountant decides the treatment.

---

## What the invoice establishes

A URA e-invoice from **Starlink Global Internet Services Ltd** (a Ugandan TIN)
to **DishNet Africa Limited**, fiscal document `126764694721`, 10/09/2026.

Two facts matter for configuration, and one of them answers a question
`tax_probe.php` could not:

**1. Displayed prices are VAT-inclusive.** The line totals add up to the GROSS
amount, not the net:

```
line totals        1,859,519
gross on invoice   1,859,519   ← identical
net                1,575,863.56
VAT                  283,655.44   (18.00% of net)
```

Had the lines been exclusive, they would have summed to 1,575,863.56. They do
not. **Starlink bills DishNet tax-inclusive at the standard rate**, which is the
ordinary Ugandan retail convention and is almost certainly how DishNet's own
published prices work too.

**2. Standard-rated, Tax Category A, 18%.** Starlink hardware and shipping are
ordinary standard-rated domestic supplies. Not zero-rated, not exempt.

Because the seller is a Ugandan-registered entity charging local VAT, the VAT on
this purchase is **input VAT** and is recoverable against output VAT, subject to
your accountant confirming the usual conditions.

---

## The gap

`tax_probe.php` against the live uCRM reports:

| | |
|---|---|
| Tax rates defined | **none** |
| `taxable` on the 5 products | **empty on every one** |
| `taxable` on the 5 service plans | **empty on every one** |
| UCC / regulatory product | **none exists** |

So DishNet is VAT-registered, is charged 18% by its supplier, must issue EFRIS
invoices with a tax category — and its billing system models no tax at all.

That is why `EfrisInvoiceMapper` warns on every line:

> *"Item 'X': tax category not resolvable from uCRM tax data — map it in the
> EFRIS tab"*

The mapper is behaving correctly. It refuses to invent a category, which is
exactly what it should do, and it will keep refusing until uCRM carries the tax.

---

## What to configure, in order

**1. uCRM → Billing → Taxes.** Create `VAT` at **18%**, set it as default.

**2. uCRM → Billing settings: prices include tax.** Set it to **inclusive**, to
match how the catalogue prices are already written and how your own supplier
invoices you. This is the decision that must not be got wrong — the two errors
are equal and opposite:

- prices are inclusive but uCRM treats them as exclusive → **every customer is
  overcharged 18%**
- prices are exclusive but uCRM treats them as inclusive → **DishNet absorbs
  18% on every sale**

With inclusive set, a Standard Kit stays **2,649,000** to the customer and uCRM
derives net 2,244,915.25 + VAT 404,084.75. Nothing on the flyer changes.

**3. Mark every product and service plan taxable** with that VAT — all ten
currently have it empty.

**4. EFRIS tab.** Map the tax category for standard-rated items, and map URA
commodity codes; the mapper warns for both and neither is guessable. Confirm
`efris_tin` is **DishNet's own** TIN, not the supplier's, and that
`efris_address` reads Acacia Mall and not the old Mawanda Road value.

**5. Re-run the probe** to confirm:

```
docker exec ucrm php /data/ucrm/data/plugins/dishnet-hybrid-sudan/tools/tax_probe.php
```

---

## What happens to the AI when you do

**Nothing needs changing.** The rule already shipped is:

> Never state a VAT amount or rate you were not given … if a tax line is not in
> your data, give the total as the sum of the listed prices and say the
> quotation confirms the tax treatment.

Today it takes the second branch because uCRM holds no tax. Once uCRM carries
VAT and the items are marked taxable, the data reaches the prompt and the
assistant can show a real VAT line — from your configuration, never from a
percentage it worked out. That was the point of refusing to hardcode 18%.

One catalogue change is needed to carry it: `DishNetTools::mapServicePlan()` and
`mapHardwareItem()` currently keep id, name, price and unit, and **drop
`taxable` and `taxId`** — the probe shows both arriving. Surfacing them is a
small change, and worth making only after uCRM actually holds the values, so it
is not built against empty fields.

---

## Keep the purchase figures out of the plugin

This invoice shows what DishNet **pays** for a Standard 4X, against a published
selling price. That is margin, and the assistant's absolute rules already forbid
disclosing wholesale or supplier costs to a customer.

Do not put supplier costs into `knowledge_seed.json`, any config key, or any
product description in uCRM. The plugin never needs them; accounting does, and
that lives in the books, not in the catalogue the AI reads.
