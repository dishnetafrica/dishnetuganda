# 20 — The accessories shop, part 1: products in uCRM, a public page, and the assistant kept honest

**Date:** 16 September 2026 · **Plugin:** 5.18.11, corrected as 5.18.12 · **Status:** applied 16 September 2026 as 5.18.12 (5.18.11's sync run created nothing: uCRM refused a `description` field, and the 5.18.12 upload had to be confirmed by `build.json` before it took). Twenty products created, ids 8–27 · **Follows:** [19](19-accessories-shop-catalogue.md)

## What part 1 delivers

Twenty Starlink accessories and the two kits, on one public page, ordered
over WhatsApp, with every price read from uCRM at request time. Nothing in
this release holds a second copy of a price.

**One string ties it together: the uCRM product name.** The shop's photo and
specs, the sync tool, the assistant's catalogue line and a quotation all
match on the exact product name. That is why the products are created by a
tool rather than typed twenty times, and why a product missing from uCRM is
hidden rather than shown at a stale figure.

## The pieces

1. **`tools/shop_products_sync.php`** puts the catalogue into uCRM Products.
   Without flags it reports: which accessories already exist, which it would
   create, and any seed price that disagrees with uCRM (reported as drift,
   never written; uCRM is right by definition). `--apply` creates the
   missing ones by exact name, copies the tax setting from the kit already in
   uCRM (or `--tax-like "<product>"`: `taxable`, and `taxId` when the kit has
   one) and sets unit `pc`. uCRM's product record has exactly `name`,
   `invoiceLabel`, `unit`, `price`, `taxable` and `taxId`; the first live run
   sent a `description` too and uCRM refused all twenty with 422, creating
   nothing — 5.18.12 sends only those fields, and the fake uCRM in the tests
   is now as strict as the real one. The report also prints the exact
   product names uCRM holds, which is how the kit cards' spellings are
   confirmed. `--apply` refuses without `--prices-include-tax`, the
   operator's statement that uCRM enters prices with tax included and the
   seed prices are VAT-inclusive customer prices; the tool never divides by
   a rate to guess a net price. It never edits an existing product.
2. **`assets/shop/catalogue.json`** is the content: slug, exact name, fit
   line, spec text, photo. No price. Kits carry a list of acceptable uCRM
   spellings because those products pre-date the file. `seed-2026-09-16.csv`
   beside it holds the shelf prices for the sync tool alone; a test proves
   no runtime file reads it.
3. **`public.php?page=shop`** is the page: kits first, then routers, mounts,
   adapters, cables, power and cases; each card with photo, fit line,
   expandable specs, the uCRM price in whole shillings, the operator's stock
   line, the VAT note, and an *Order on WhatsApp* button that opens the sales
   number with the product and price named. Prices come through the same
   ten-minute cache the website's price feed uses, so the two can never
   disagree; if uCRM is unreachable and there is no cache, the page shows a
   notice and no cards. `&format=json` returns the resolved items for the
   website under the same CORS rule as `prices.php`.
4. **`public.php?page=shop_img&s=<slug>&w=<240|480>`** serves the photos.
   uCRM serves only `public.php` from a plugin directory, so this route is
   how the files in `assets/shop/` are reached. The slug is looked up in the
   catalogue and never used as a path. Sized copies are WebP made with GD
   and cached in the data directory (a 240-pixel copy of a 49 KB photo is
   5 KB); the original is served when no size is asked for.
5. **The assistant** now receives accessories under their own heading,
   ACCESSORIES, apart from HARDWARE, which keeps the kit and the
   installation. The heading carries the rule: offer an accessory only when
   asked or when the need is described; never add one into TOTAL TO GET
   CONNECTED unless the customer chose it; say what it fits before quoting.
   The price guard's permitted sums now include kit plus installation plus
   one or two accessories, so a correct total with a wall mount in it passes
   and a total with three mounts in it is one to confirm by hand.
6. **`ai_fact_prices`** is a new business fact. Set it once ("All our listed
   prices include VAT.") and the assistant states it instead of hedging; the
   fact carries the fence that it permits no tax arithmetic. Unset, nothing
   is said, as before. `tools/set_config.php` manages the key from 5.18.12
   (5.18.11 shipped the fact without registering it there).

## What did not change

The prompt corpus hash is unchanged from 5.18.10: the ACCESSORIES block
appears only when uCRM carries accessories, and the PRICES fact only when
set. The security invariant holds: the page is public and shows exactly what
`prices.php` already publishes; the sync tool creates products and does
nothing else; the assistant's catalogue still comes from uCRM alone.

## Tests

`tests/test_shop_part1.php` (48 assertions): the content file's integrity
and its lack of any price key; resolution against a fake uCRM by exact name
whatever the spacing or case, with unpriced or missing items hidden; the
page's prices, order links, image routes, stock line, VAT note, group order,
escaping and its behaviour with uCRM down; the image route's slug lookup,
traversal refusal, refusal of a tampered catalogue whose image path climbs
out of the folder, WebP variant, cache hit and original fallback; the
routes; the sync tool's dry run, refusal without the flag, creation of the
20 with the kit's tax setting, idempotence, and drift left alone; the
catalogue split, the ACCESSORIES block, the HARDWARE block keeping only kit
and installation, the VAT fact present and absent; and the guard's sums.
Ten mutations each fail it (unpriced item shown, names unescaped, image path
allowed to leave the folder, apply without the flag, non-idempotent sync, accessories not
split, no ACCESSORIES block, no VAT fact, sums without accessories, route
missing).

## Server steps after upload

```
docker exec ucrm grep -c '"version": "5.18.12"' /data/ucrm/data/plugins/dishnet-hybrid-sudan/manifest.json
docker exec ucrm cat /data/ucrm/data/plugins/dishnet-hybrid-sudan/build.json
```

Report first, then create. The second command creates the twenty products
in uCRM and is the one deliberate write of this release:

```
docker exec -u $(stat -c %u:%g /home/unms/data/ucrm/ucrm/data/plugins/dishnet-hybrid-sudan) -w /data/ucrm/data/plugins/dishnet-hybrid-sudan ucrm php tools/shop_products_sync.php
docker exec -u $(stat -c %u:%g /home/unms/data/ucrm/ucrm/data/plugins/dishnet-hybrid-sudan) -w /data/ucrm/data/plugins/dishnet-hybrid-sudan ucrm php tools/shop_products_sync.php --apply --prices-include-tax
```

Tell the assistant the tax treatment:

```
docker exec -u $(stat -c %u:%g /home/unms/data/ucrm/ucrm/data/plugins/dishnet-hybrid-sudan) -w /data/ucrm/data/plugins/dishnet-hybrid-sudan ucrm php tools/set_config.php --key ai_fact_prices --value "All our listed prices include VAT."
```

Then open the page at your uCRM address followed by
`/_plugins/dishnet-hybrid-sudan/public.php?page=shop` (the same host that
serves `prices.php`), and link it from the website menu. Two or three test
chats on the sales number afterwards: ask what it costs to get connected and
read that the total is kit plus installation with no mount in it, then ask
for a wall mount and read that it is offered as an extra with its fit named.

## What the live run settled

The five products uCRM already held: `Starlink Mini Kit`, `Starlink Standard
Kit`, `Professional Installation`, `Residential (up to 400 Mbps)`,
`Residential Lite (up to 100 Mbps)`. Both kit spellings are in the shop
catalogue's `match` lists, so both kit cards resolve. The installation is a
service and is deliberately not a shop item; the two plan mirrors are dropped
from hardware as before.

**One open question for the operator, not a defect.** `Starlink Mini Kit`
carries `taxable: false` in uCRM, so the sync tool copied that to all twenty
accessories — which is the tool doing exactly what it promises, matching the
kit rather than inventing a tax treatment. The consequence is worth stating
plainly: an invoice line for any of these carries no VAT, and the fiscal
mapper reads tax from the invoice line and never assumes one, so a fiscal
invoice will show them as carrying none either. The customer pays the listed
price, which is what "VAT inclusive" means to them. Whether these supplies
should be VAT-taxable in uCRM is an accounting decision, and it applies to
the kits first: the accessories merely match them. Changing it means changing
the products in uCRM, not the plugin.

## Part 2

Cart and checkout: phone-code login, the uCRM client found or created, an
invoice with the chosen lines, DPO payment through the existing service, and
the order visible in the customer portal with its status.
