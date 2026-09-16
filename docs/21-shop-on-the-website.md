# 21 — The shop on the website, and the kits page it broke

**Date:** 16 September 2026 · **Plugin:** 5.18.13 · **Website:** `dishnet-web-uganda` · **Follows:** [20](20-accessories-shop-part-1.md)

## The regression, first

Creating the twenty accessories in uCRM Products broke a live page within
minutes, and nothing warned us.

`starlink-kits.html` renders "every kit in uCRM" from the public price feed:
`prices.js` takes `data.hardware` and draws one kit card per entry, each with
"one-off payment · includes delivery, installation and your first month".
`PublicPriceFeed::build()` put **every** uCRM product into `hardware`. So the
moment the accessories existed, the kits page offered twenty mounts and
cables as Starlink kits, at accessory prices, promising installation and a
month of internet with each.

The same fault had been there longer and smaller: the two plan mirrors
(`Residential`, `Residential Lite`, kept in Products so a quotation can carry
them as lines) had been rendering as one-time kits too, and Professional
Installation, a service, as a third.

## The fix: three lists, one rule

`PublicPriceFeed::build()` now returns `plans`, `hardware` and
`accessories`, and drops plan mirrors entirely:

- a product whose name matches a plan's is the plan, so it is in neither
  one-time list (`PublicPriceFeed::planKey()`, the same letters-and-digits,
  Starlink-dropped rule the assistant uses in `DishNetTools::catalogueKey()`);
- a product named in the shop catalogue is an accessory, carrying its photo
  `slug` so the website can build the image URL without a second copy of the
  mapping;
- everything else stays `hardware`, which is now kits and installation.

`prices.js` additionally filters the kits grid to names containing "kit" or
"package", so Professional Installation is no longer a kit card. `shop.php`
reads both lists, since the shop wants kits and accessories.

This is one concept applied in a second place. 5.18.11 made exactly this
split for the assistant; the public feed simply had not caught up, and the
consequence was visible to customers rather than to the model.

## The shop page on the website

`dishnetuganda.com/shop.html`, a real page in the site with its own header,
footer, SEO tags, sitemap entry and a Shop link in the desktop nav (after
Internet) and the mobile nav (after Starlink Kits) on all 36 pages that carry
the navigation.

It holds no prices and no product list. `assets/js/shop.js` fetches
`public.php?page=shop&format=json` and renders groups — kits, routers,
mounts, adapters, cables, power, cases — each card with the photo from
`?page=shop_img&s=<slug>`, the fit line, expandable specs, the uCRM price and
an Order-on-WhatsApp button naming the product and the price the visitor saw.
If the feed cannot be reached, the fallback already in the page stays: an
"ask us for the accessories list" card. The page never shows a stale price
and never shows a hole.

The site's own rule — "no price is ever published in this repository, they
all come from uCRM" — is enforced by `verify-site.sh`, and the new page
passes it. Two entries were added to that script's allow-list of portal URLs:
the shop feed, and the bare `crm.dishnetuganda.com` origin the page
preconnects to because every product photo is served from there.

## Verification

`bash verify-site.sh` runs the real nginx against the real config: 57 pages
all 200, 66 internal references none broken, sitemap well-formed with every
URL resolving, canonicals correct, one WhatsApp number, one portal URL, and
no price figure published anywhere outside the tutorial mock screenshots.
PASS.

On the plugin, `tests/test_public_prices.php` gained nine assertions built
from the exact live product names of 16 September: hardware is kits and
installation only, no accessory in it, the three accessories in their own
list cheapest first, plan mirrors in neither, plans untouched, the photo slug
present on accessories and absent on hardware, and the plan-matching rule
insensitive to the word Starlink, case and punctuation. Three mutations each
fail it: accessories back in hardware, plan mirrors kept, slug not published.

## After upload

```
docker exec ucrm grep -c '"version": "5.18.13"' /data/ucrm/data/plugins/dishnet-hybrid-sudan/manifest.json
```

The kits page corrects itself within ten minutes, when the price feed's cache
expires. To see it immediately, open the feed and confirm `hardware` holds
three entries and `accessories` twenty:

```
curl -s 'https://crm.dishnetuganda.com/crm/_plugins/dishnet-hybrid-sudan/public.php?page=prices' | head -c 400
```

The website is a separate deployment: rebuild and redeploy the
`dishnet-web-uganda` container for `shop.html` and the nav to appear. Until
then the plugin's own page at `public.php?page=shop` serves the same
catalogue.
