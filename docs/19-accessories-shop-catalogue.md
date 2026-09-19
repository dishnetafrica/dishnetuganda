# 19 — Accessories shop: the catalogue to configure

**Date:** 16 September 2026 · **Status:** list prepared; part 1 built as 5.18.11, see [20](20-accessories-shop-part-1.md) · **Source of prices:** the Starlink store for Uganda, Standard and Mini accessory pages, as read by the operator on 16 September 2026 · **Margin:** 30% on the store price

## The rule for every price

Store price × 1.30, then rounded **up** to the next 1,000 shillings so no item
ever falls under the 30% margin. **The shelf price is the final customer
price, VAT included** (operator's decision, 16 Sep 2026: "that 30% is tax
inclusive for all the products"). The plugin never adds a tax figure. What
that means at the point of entry:

- uCRM has one pricing mode for all products, set in its billing settings:
  prices entered *with* tax or *without* tax. If the existing kits are entered
  as customer-facing figures with the VAT tax attached (pricing mode "with
  taxes"), the shelf prices go in exactly as listed, with the same VAT tax
  attached. If uCRM is in "without taxes" mode, the shelf price must be
  entered net of VAT so the invoice total comes back to the shelf price; the
  sync tool (Part 1) reads the mode and the existing kits first and refuses
  to guess, rather than dividing by a rate itself.
- EFRIS and the invoice carry the VAT from uCRM's tax, as they do today.
- The assistant currently hedges on tax ("the quotation confirms the tax
  treatment") because it has no fact to stand on. Part 1 adds a business
  fact, `ai_fact_prices`, set by the operator ("All listed prices include
  VAT"), so it can say so plainly without calculating anything.

| # | Product (Starlink name) | Type | Store price USh | +30% exact | Shelf price USh |
|---|---|---|---:|---:|---:|
| 1 | Router Mini | Router | 231,481 | 300,925.30 | **301,000** |
| 2 | Roof Rack Mount \| Standard 4 or 4 X | Mount | 346,667 | 450,667.10 | **451,000** |
| 3 | Standard 4 or 4 X to Standard Actuated Mount Adapter | Adapter | 173,333 | 225,332.90 | **226,000** |
| 4 | X-Frame Base \| Standard 4 or 4 X, Enterprise | Mount | 289,333 | 376,132.90 | **377,000** |
| 5 | Ridgeline Mount \| Standard 4 or 4 X | Mount | 1,446,667 | 1,880,667.10 | **1,881,000** |
| 6 | Router 3 Mount | Mount | 173,333 | 225,332.90 | **226,000** |
| 7 | Mobility Mount \| Standard 4 or 4 X | Mount | 173,333 | 225,332.90 | **226,000** |
| 8 | Power Supply Mount \| Standard 4 X | Mount | 115,741 | 150,463.30 | **151,000** |
| 9 | Pivot Mount \| Standard 4 and 4 X, Enterprise | Mount | 289,333 | 376,132.90 | **377,000** |
| 10 | Wall Mount \| Standard 4 or 4 X | Mount | 289,333 | 376,132.90 | **377,000** |
| 11 | Pipe Adapter \| Standard 4 or 4 X | Adapter | 173,333 | 225,332.90 | **226,000** |
| 12 | Router 3 \| Starlink V4 or V5, Mini | Router | 636,000 | 826,800.00 | **827,000** |
| 13 | Travel Kit \| Mini | Case | 231,481 | 300,925.30 | **301,000** |
| 14 | Car Adapter \| Mini | Power | 231,481 | 300,925.30 | **301,000** |
| 15 | Starlink Mini USB-C Cable 5m | Cable | 115,741 | 150,463.30 | **151,000** |
| 16 | Mobility Mount \| Mini | Mount | 173,333 | 225,332.90 | **226,000** |
| 17 | Roof Rack Mount \| Mini | Mount | 173,333 | 225,332.90 | **226,000** |
| 18 | Starlink Mini Ethernet Cable 15m | Cable | 115,741 | 150,463.30 | **151,000** |
| 19 | Pivot Mount \| Mini | Mount | 289,333 | 376,132.90 | **377,000** |
| 20 | Wall Mount \| Mini | Mount | 231,481 | 300,925.30 | **301,000** |

Twenty items: twelve from the Standard accessories page, eight from the Mini page (Router Mini, Router 3 and the Router 3 Mount appear on both and are listed once). Store total 5,899,811; shelf total 7,680,000.

## What each item is

Hardware facts are from Starlink's own product descriptions, spec sheets and
help articles as reported by retailers and reviewers (Starlink's site is not
reachable from the build environment, so wording was cross-checked across
several of those rather than copied from one page). Prices were **not**
taken from any of them.

**1. Router Mini** · fits: Standard 4, Standard 4 X, Mini, Gen 2 kits (not Gen 1)  
Wi-Fi 6, dual-band; coverage up to 112 m² (1,200 ft²); 1 latching Ethernet LAN port (Starlink Plug); 137 × 84 × 25 mm; removable stand doubles as a wall mount; separate power supply 100–240 V; works as the primary router or as a mesh node with Router 3, Gen 2 and Mini routers. The router shipped in the Standard 4 kit.

**2. Roof Rack Mount | Standard 4 or 4 X** · fits: Standard 4, Standard 4 X, Enterprise  
Clamps to vehicle roof-rack crossbars 15–44.5 mm thick and up to 95 mm wide, or to T-slot racks; quick to detach when not in use; for use while parked.

**3. Standard 4 or 4 X to Standard Actuated Mount Adapter** · fits: Standard 4 / 4 X dish onto an existing Standard Actuated (Gen 2) mount  
Lets a new Standard 4 / 4 X dish use a mount already installed for the older Standard Actuated dish, without replacing the mount: Short Wall Mount, Long Wall Mount, Pivot Mount, Ground Pole Mount, Ridgeline Mount. Mast and hardware included. For customers upgrading a Gen 2 dish.

**4. X-Frame Base | Standard 4 or 4 X, Enterprise** · fits: Standard 4, Standard 4 X, Enterprise  
Folding X-shaped base for a ground-level installation or a quick set-up, holding the dish slightly off the ground; no drilling.

**5. Ridgeline Mount | Standard 4 or 4 X** · fits: Standard 4, Standard 4 X  
Non-penetrating roof mount: adjustable legs straddle the roof ridge or lie flat on a flat roof, no holes drilled; held down by four ballast weights of about 5 kg each (kit also has cable routing clips); rated to 80 km/h wind; for shingle and metal roofs, not recommended for clay tile. Heavy to ship.

**6. Router 3 Mount** · fits: Router 3  
Bracket to fix the Router 3 to a wall or other surface.

**7. Mobility Mount | Standard 4 or 4 X** · fits: Standard 4, Standard 4 X, Enterprise  
Permanent mount for vehicles, boats and cabins: forms a watertight seal on wood, fibreglass, metal, plastic and slotted rack rails; fixed 8° tilt; for use while parked.

**8. Power Supply Mount | Standard 4 X** · fits: Standard 4 X power supply only  
Bracket to fix the Standard 4 X dish power supply (the separate brick) to a wall. Not for the Standard 4 dual-port supply.

**9. Pivot Mount | Standard 4 and 4 X, Enterprise** · fits: Standard 4, Standard 4 X, Enterprise  
For slanted, shingled roofs; swivelling head for fine adjustment of angle and direction. Kit: pivot mount, 20 routing clips, 2 lag screws, silicone sealant, sealing tape.

**10. Wall Mount | Standard 4 or 4 X** · fits: Standard 4, Standard 4 X  
Exterior wall mount that holds the dish clear of the wall and eaves. Kit: wall mount, 20 routing clips, 2 lag screws, silicone sealant.

**11. Pipe Adapter | Standard 4 or 4 X** · fits: Standard 4, Standard 4 X  
Fits the dish to an existing metal pole of 31–63.5 mm (1.25–2.5 in) diameter; metal pipes only, not PVC; hardware included. The usual part for a pole installation.

**12. Router 3 | Starlink V4 or V5, Mini** · fits: Standard 4, Standard 4 X, Mini, Gen 2 and Gen 3 kits  
Tri-band Wi-Fi 6; coverage up to 297 m² (3,200 ft²); 2 latching Ethernet LAN ports; up to 235 devices; IP56; WPA2; 300 × 120 × 55 mm, about 1 kg; separate power supply 100–240 V; primary router or mesh node. The router shipped in the Standard 4 X kit.

**13. Travel Kit | Mini** · fits: Mini  
Protects the Mini on the move: a custom-fitted Bumper Case for drop protection, a Sleeve that doubles as a carrying case and shields against dirt, scrapes and bumps, and an Accessory Pouch that holds everything needed to power the Mini.

**14. Car Adapter | Mini** · fits: Mini  
Powers the Mini from a vehicle's 12–24 V auxiliary (cigarette-lighter) socket over USB-C, in place of the Mini power supply and DC cable. Pair with the 5 m USB-C cable for reach from socket to dish.

**15. Starlink Mini USB-C Cable 5m** · fits: Mini  
5 m USB-C cable to power the Mini from a USB-C Power Delivery source: the Car Adapter or a power bank. Starlink's guidance is a PD source of at least 65 W, 100 W recommended, for full performance.

**16. Mobility Mount | Mini** · fits: Mini  
Permanent vehicle or boat installation for the Mini; bolted down with a sealed footprint, for use while parked.

**17. Roof Rack Mount | Mini** · fits: Mini  
Removable installation of the Mini on vehicle roof-rack crossbars; clamps on and comes off when not in use; for use while parked.

**18. Starlink Mini Ethernet Cable 15m** · fits: Mini, Performance (Gen 3) dish → Router 3 or a third-party router  
15 m Ethernet run from the Mini (or the Performance Gen 3 dish) to a Router 3 or any third-party router, with a weather-sealed latching plug on the dish end and standard RJ45 at the router. This is how a Mini feeds a Router 3 for a bigger house.

**19. Pivot Mount | Mini** · fits: Mini  
For slanted roofs; swivelling head for fine adjustment of angle and direction.

**20. Wall Mount | Mini** · fits: Mini  
Exterior wall installation for the Mini; clears an eave overhang of up to about 10 cm (4 in).

## Photos

Starlink's own store images for each product, 500 × 500, filed in the plugin
at `dishnet-hybrid-sudan/assets/shop/` under a catalogue slug, with
`index.json` mapping slug → exact product name → file. The shop tab matches
on the product name, which is also the uCRM product name, so the picture,
the price and the assistant's catalogue line all hang off one string. The
logos, wordmark and interface icons that came with the downloads were not
kept. The CSV has an `image` column.

The originals are PNG and JPEG as Starlink serves them (4.4 MB for 22). When
the shop tab is built it should serve resized WebP copies made from these,
not the originals; PHP GD with WebP is available on this stack.

| Product | File | Size |
|---|---|---:|
| Router Mini | `router-mini.png` | 278 KB |
| Roof Rack Mount \| Standard 4 or 4 X | `roof-rack-mount-standard.png` | 394 KB |
| Standard 4 or 4 X to Standard Actuated Mount Adapter | `standard-to-actuated-mount-adapter.png` | 452 KB |
| X-Frame Base \| Standard 4 or 4 X, Enterprise | `x-frame-base-standard.png` | 612 KB |
| Ridgeline Mount \| Standard 4 or 4 X | `ridgeline-mount-standard.png` | 332 KB |
| Router 3 Mount | `router-3-mount.png` | 338 KB |
| Mobility Mount \| Standard 4 or 4 X | `mobility-mount-standard.png` | 219 KB |
| Power Supply Mount \| Standard 4 X | `power-supply-mount-standard-4x.png` | 40 KB |
| Pivot Mount \| Standard 4 and 4 X, Enterprise | `pivot-mount-standard.png` | 376 KB |
| Wall Mount \| Standard 4 or 4 X | `wall-mount-standard.png` | 304 KB |
| Pipe Adapter \| Standard 4 or 4 X | `pipe-adapter-standard.png` | 312 KB |
| Router 3 \| Starlink V4 or V5, Mini | `router-3.png` | 356 KB |
| Travel Kit \| Mini | `travel-kit-mini.jpg` | 28 KB |
| Car Adapter \| Mini | `car-adapter-mini.jpg` | 31 KB |
| Starlink Mini USB-C Cable 5m | `mini-usb-c-cable-5m.jpg` | 14 KB |
| Mobility Mount \| Mini | `mobility-mount-mini.jpg` | 46 KB |
| Roof Rack Mount \| Mini | `roof-rack-mount-mini.jpg` | 50 KB |
| Starlink Mini Ethernet Cable 15m | `mini-ethernet-cable-15m.jpg` | 12 KB |
| Pivot Mount \| Mini | `pivot-mount-mini.jpg` | 48 KB |
| Wall Mount \| Mini | `wall-mount-mini.jpg` | 47 KB |
| Starlink Standard Kit (kit photo) | `standard-kit.png` | 83 KB |
| Starlink Mini Kit (kit photo) | `mini-kit.png` | 31 KB |

The assistant's photo library (`<dataDir>/photos/<name>.jpg` + optional
`<name>.txt` caption, sent with `<<PHOTO name>>`) uses the same file-name-as-
key rule. Dropping these files there, with captions, would let the assistant
show a customer the Pivot Mount when asked. That changes the prompt (the
photo list is offered to the model), so it is a separate decision, not done
here.

## Not in this list, also in the store

Worth pricing from the store before the shop goes live, since installers and
Mini customers ask for them: Starlink Cable 15 m and 45 m (Standard),
Standard Power Supply (replacement), Router Mini power supply (replacement),
and, from the Mini line, the Mini Pipe Adapter, Mini DC Power Cable and
Mini Power Supply (the rest of the Mini line is above). The Ethernet Adapter is for Gen 2 kits only: Router 3 and Router Mini have their
own Ethernet ports.

## Two things to know before adding them to uCRM

1. **Every uCRM product reaches the assistant.** The WhatsApp assistant's
   HARDWARE list is uCRM's Products, quoted exactly and treated as one-time
   charges. Adding these twelve is how the assistant learns to quote a Wall
   Mount, which is wanted. It also means the TOTAL TO GET CONNECTED
   guidance meets fifteen one-time items instead of three. Run two or three
   test chats on the sales number after adding them and read that the
   mounts are offered as optional extras, not folded into the connection
   total. The price guard (5.18.10) will pass any of these prices because
   they come from uCRM.
2. **One source of truth.** The shop tab, the assistant and the quotation
   must all read the same uCRM product. Nothing should hold a second copy of
   a price.
3. **Still open:** whether the two kit products already in uCRM appear in the
   shop as well, with the kit photos filed above.

## The shop tab, when it is built

Scope for the go-ahead, not yet started:

- a Shop page in the customer portal listing the accessories from uCRM
  Products, with the Starlink name, the fit line, the specs above, and the
  uCRM price; a way to mark which products are shop-visible (a uCRM custom
  attribute on the product, or a plugin-side list of product ids, never a
  second price);
- a cart that becomes a uCRM quotation or invoice for the customer, so the
  order exists in billing before any payment;
- payment through the existing DPO integration, and the order then handled
  as a Starlink store purchase for the customer (the store's per-order
  delivery fee and lead time are the operator's to price in);
- stock: the operator's own `stock_statement` line already tells the
  assistant what is in Kampala; the shop should show availability from the
  same place or from the equipment registry, not from a third field.
