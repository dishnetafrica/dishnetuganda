# 70 — Uganda website: the consultancy-led implementation (26 Oct 2026)

**Status: LAUNCH APPROVED by the owner on 26 Oct 2026, PREPARED (§10) and MERGED: into `main` by PR #26 (`647faccf`),
then into `claude/study-this-jhe2eg` (`0de69559`), the branch EasyPanel's `web-uganda` app actually builds (§10.7). LIVE NOT
VERIFIED from this session:** the live systems answer this session's proxy with 403, and the four checks of §10.4 were
not run before the merge (the owner chose to proceed). **Later on 26 Oct the owner asked for the subscription prices and
the shop to stay, as on the old site: the managed-plan hold-back of §10.1 is reversed on `claude/study-this-jhe2eg`, and
the homepage shows the live plan prices and links the shop (§10.8).** Until §10 the record below describes the build as
the owner approved it; where §10 differs, §10 applies.

The owner reviewed revision 2 of the website demo and its content change report and asked for the next stage: a
production implementation in the existing site structure and release process, keeping the approved consultancy-led
design, the Starlink acquisition guidance, the free initial consultation, the WhatsApp enquiry flow and the separate
CCTV and network security section; fibre removed everywhere; the managed DishNet Starlink plan kept, with the questions
that must be settled before it is published; every affected page, link, policy, metadata, form, mobile layout and
journey reviewed; and the file lists, the changes needed outside the site, tests, a change report, the diff and a
rollback plan.

The eleven decisions the owner approved for revision 2 bind every word below. They are quoted in §3.1 where they
decided a change. The hotspot / WiFi-zone business, the reseller and distributor programmes and the accessories shop
are **undecided by the owner, so they are kept** (§9) and their open points are listed in §8.

## 1. Where the work is

| | |
|---|---|
| Branch | `claude/uganda-site-consultancy` (this repository, pushed; no pull request) |
| Base | `main` at `e01696a2` — the live site |
| Site | `dishnet-web-uganda/site/` (static HTML, CSS, JS), `dishnet-web-uganda/nginx.conf`, `dishnet-web-uganda/Dockerfile` |
| Policy source | `dishnet-web-uganda/policies/` (`build.py` + `bodies/`); the nine policy pages are generated, never edited by hand |
| Checks | `verify-site.sh`, `verify-address.py`, `policies/build.py --check`, `stamp-assets.sh --check` |
| The diff | `git diff e01696a2..claude/uganda-site-consultancy -- dishnet-web-uganda docs/70-*` |
| Commits | `1db9c1ec` the implementation · `8e2b2505` kits cost box and wording · `c3274ca4` this record (draft) · `aa7759ac` tutorials, residual policy items, guards · `b499a6af` independent-review fixes · `9cee1265` this record · `d1a63dbc` second-review fixes · `3ad5a20d` this record · `1ae1d57b` third-review fix · `ebc4eb92` this record, final · launch (§10): `f659a3bc`, `6be8b42e`, `45b8b23b` the managed plan held back · `25c77387` unverified claims removed · `bd522250` launch-review fixes · the commit after it: this record |

## 2. Implementation plan — in the existing structure and release process

### 2.1 How the site is released today (unchanged)

The site is a static nginx container (`nginx:1.27-alpine`; `Dockerfile` copies `nginx.conf` and `site/`). EasyPanel's
`web-uganda` app builds branch **`claude/study-this-jhe2eg`**, build path **`/dishnet-web-uganda`** (corrected in §10.7:
this section first said `main`, as `README-DEPLOY.md` does; `docs/07` records the branch). **A push to that branch is
therefore a release** once EasyPanel redeploys. There is no CI workflow; the checks in §6 are run by hand. This plan keeps
that process exactly as it is: no new service, no new host, no build step, no database.

### 2.2 What the implementation consists of

1. The approved homepage (revision 2) as the production `index.html`, with its own `home.css` and `assets/js/home.js`.
   The demo's review strip, review tool and embedded images were removed; the images are the existing product photos.
2. The live order flow and plans grid moved from the old homepage to `starlink-kits.html` (`#plans`, `#order`), so the
   live-priced journey survives (`assets/js/order.js` is the old homepage script without its scroll handler).
3. Fibre removed from every page, menu, footer, metadata, structured data, sitemap and policy (§3.2); the fibre page and
   the fibre article removed and redirected (§4.2).
4. Every other page brought into line with the approved decisions: the header, menus, footers, metadata and structured
   data on all 63 pages; the body text where a page said something the decisions rule out (§3.3–§3.9).
5. Two defects found during the review fixed in the scripts: the chat thanked a visitor whose details had not been
   stored (decision 11), and the order flow could offer no kit at all with the product names recorded for uCRM (§3.7).
6. The checks extended so the decisions stay enforced (§4.4).
7. The 19 app tutorials brought into line (§3.10): the standard header, menu and footer; the approved support
   sentence; South Sudan remnants and activation promises removed from the location tutorial.
8. The residual policy, tooling and price-feed items of the policies-and-integrations audit (§3.9, §5).
9. An independent review of the whole diff, each finding checked by a separate sceptic; the confirmed findings fixed
   and proven in a browser (§6.2), and a second review of the second round (§6.3).

### 2.3 Release sequence — for when the owner approves (not done)

Each step needs the one before it. Steps 1–3 are decisions; nothing in them is technical.

1. **The owner reviews** this record, the branch and the diff, and answers or accepts the open points in §8. The items
   marked **before publishing** must be settled before step 4.
2. **The managed DishNet plan (§8.1).** Either the questions are settled, or the owner decides to release with the
   managed-plan wording held back. Holding it back is a small, separate change on this branch (the places are listed in
   §8.1); it is not built, because the owner approved the plan's wording.
3. **Outside the site (§5):** the uCRM and plugin items marked *before release*.
4. **Final checks on the branch**, the same commands as §6, with every result read. If the release is on a later day
   than 26 October 2026, first set `UPDATED` in `policies/build.py` to the publication date and run `python3
   policies/build.py`, so the nine policy pages carry the date they took effect.
5. **Release:** a pull request from `claude/uganda-site-consultancy` into `main`, reviewed and merged by the owner.
   EasyPanel rebuilds from `main`. Nothing else is touched.
6. **After the release:** load the homepage, `starlink-kits.html`, one town page and `/fiber.html` (it must answer
   301 → `/services.html`); submit `sitemap.xml` in Google Search Console and ask it to drop the two removed URLs.

The rollback is in §7, deliberately apart from these steps.

## 3. Change report

### 3.1 The decisions, and where each one changed the site

| # | Decision (owner-approved) | What it changed |
|---|---|---|
| 1 | DishNet Uganda does not sell fibre; a historical reference only with place and time | `fiber.html` and the fibre article removed; fibre gone from every menu, footer, title, description, JSON-LD block, FAQ answer, town page, programme page, tutorial and policy |
| 2 | The managed DishNet plan is DishNet's own service; never implied to be approved by Starlink; kept apart from own-name registration | homepage *Ways to get Starlink*; kits page `#plans`; FAQ *What is a managed DishNet plan?* (the contract is with DishNet); About (now its own sentence); the installation-day article; Terms of Service §3 and Terms of Sale §7: "A DishNet plan is DishNet's own service, provided under DishNet's own terms; it is not a Starlink product."; the location tutorial is labelled *Managed DishNet plan* and points own-name customers to Starlink |
| 3 | Restricted registrations: individual review, never an automatic offer or refusal, never a bypass | the FAQ no longer says "Or choose a managed DishNet plan instead" after "restricted"; it uses the homepage's wording, including "We will never help anyone bypass verification…" |
| 4 | Own-name registration: "Starlink controls registration approval, KYC verification and activation." No approval promised | FAQ (own-name, installation time, moving), Terms of Sale, Terms of Service §3 (now with the sentence), Starlink Installation, town pages, the installation-day article (word for word); "we activate your kit" became "we arrange the activation"; the location tutorial lost "we activate", "5-15 minutes" and "Activating your dish now" |
| 5 | "Your first ICT consultation is free and carries no obligation." | homepage, services, about, contact, WhatsApp, FAQ, security, why DishNet, town pages, blog articles; every header button and phone menu now opens the free-consultation WhatsApp message |
| 6 | Connectivity: Starlink only | no fibre, LTE, "4G-linked", "Data Network" or "Internet" menu label ("Services" instead) |
| 7 | CCTV and network security: a separate service, not a Starlink product, not needing Starlink | security page, FAQ, why DishNet, town pages; a brand note: "Listing a brand does not mean a partnership with it." |
| 8 | Approved facts, word for word: "DishNet Africa has operated since 2013"; "DishNet serves more than 40 towns"; "WhatsApp support is available seven days a week" | "10+ years", "ten years", "two countries", "all four regions", "every day", "any time", "24/7", "answered every day", unconfirmed hours and on-call claims removed; the tutorials' "Our team in Kampala is on WhatsApp 7 days a week" (18 pages), "within minutes", "8 AM – 8 PM" and "We respond within minutes" replaced by the approved sentence; the social card now states both facts word for word |
| 9 | Email `info@dishnetafrica.com` | unchanged everywhere; the FAQ's new consultation answer names it |
| 10 | Existing product photos only, never presented as DishNet installations | the High Performance card no longer shows the Standard kit's photo; a new social card (`og-dishnet-uganda.png`) shows the product, not an installation; the tutorials' "Real screens from the app" became "App screens shown with sample data" |
| 11 | WhatsApp first; the form prepares a WhatsApp message; no false success message | the homepage form says it prepares a message and that nothing is sent until the visitor sends it; **the chat now thanks a visitor only when the server confirms it stored the details** (§3.7) |

### 3.2 Fibre — every place it was

The approved homepage replaces the old one. Removed: the *Fiber* menu item (desktop and phone) and *Fiber Internet*
footer link on every page; "fiber" in the footer tagline and the LocalBusiness JSON-LD description on 63 pages; the
fibre product card, intro sentences, FAQ answers and failover promises on the 12 town pages (replaced by a *Free ICT
consultation* card and Starlink answers); fibre in the coverage page's town badges (9), the FAQ (title, description,
three answers and their structured data), careers, the WhatsApp page, the hotspot page and article, the distributor
page (service card and form option), two tutorials, the Terms of Service (scope and service requirements) and the
Acceptable Use policy (three clauses and its page description). The sitemap no longer lists the two removed URLs.

### 3.3 Homepage

The approved revision-2 design, unchanged in wording, with a production `<head>` (title, description, canonical, Open
Graph and Twitter tags, the site's LocalBusiness JSON-LD with the same 40-town list as every page), real image files at
their real sizes, relative links, the town chips linking to the town pages, and the existing chat. The demo's review
strip and comments are gone.

### 3.4 Shared parts of every other page

Header button *Get Connected* / *Get a Quote* → **Free consultation** (opens WhatsApp with "Hi DishNet, I'd like to
book my free ICT consultation. My location is …"). Menu *Internet* / *Internet Services* → **Services**; the phone menu
gains **Book a free consultation**, because the header button is hidden on phones. Footer tagline and JSON-LD
description in the approved positioning; social image `og-dishnet-uganda.png`. The tutorials' broken *Products* and
*Starlink Kits* links (`/#products`, an anchor the homepage never had) now open `starlink-kits.html`.

### 3.5 Pages rewritten in substance

| Page | What changed |
|---|---|
| `services.html` | rebuilt around Starlink advice, the DishNet service and hands-on work; organisations; *Also from DishNet* (CCTV separately; WiFi zones with the UCC caution); what DishNet does and what Starlink decides; the free consultation |
| `starlink-kits.html` | new `#plans` (the live plans grid and the managed-plan explanation) and `#order` (the live address-first order flow); kit-only and own-name guidance; no High Performance photo or unverified specifications; "delivered to your town" instead of "anywhere in Uganda"; the business-plan wording matches the sales assistant |
| `about.html` | the approved facts with the place; the managed plan defined; the independence statement as on the homepage |
| `contact.html`, `whatsapp.html` | the free consultation; "Talk to us" (an automated assistant answers first); office hours labelled as office hours; consultation openers |
| `faq.html` | 16 → 17 questions: *Is my first consultation free?* added; the managed-plan, own-name, restricted-registration, cost, installation-time, speed, support, power and moving answers corrected; the structured data rebuilt from the visible answers |
| `security.html` | a separate service, in the approved homepage's words; no "world's leading brands", "bank-grade", "4G-linked", "anywhere in Uganda" or same-day pricing |
| `why-dishnet.html` | the approved facts; "Since 2013" with the place; CCTV separate; the free consultation |
| `coverage.html` | "Starlink Installation Coverage"; Starlink decides availability and new sign-ups |
| 12 town pages | see §3.6 |
| `404.html` | its chat script loaded from the site root (on a nested address it was requested from the wrong folder and blocked); Shop, Security, Hotspot and Pay a Bill links as on every other page; a template leftover pointing at `why-dishnet.html` removed |
| `get-the-app.html`, `guides.html`, `pay.html`, `careers.html` | the app title no longer claims bill payment; wrong menu highlight removed; no "right away", "two countries", "ISP-grade" or unverified language claims |
| blog, hotspot, reseller, distributor pages | see §3.8 |
| 19 app tutorials | see §3.10 |

### 3.6 Town pages

On all twelve (`starlink-<town>.html`): the title "Starlink Installation & ICT Consultancy in <town>"; one
description pattern ("Starlink guidance, installation and local support in <town> — plus WiFi hotspot zones, and CCTV
and network security as a separate service. Your first ICT consultation is free and carries no obligation."); the
Service structured data names Starlink installation and ICT consultancy, and no longer calls DishNet an "Internet
service provider"; the heading "Starlink in <town>, done properly"; the hero button **Book a free consultation in
<town>**; "Starlink decides service availability" on the Starlink card; CCTV "a separate service from Starlink, quoted
on its own; it does not need a Starlink connection" (it said "installed by the same team, often on the same visit");
the closing band with the consultation sentence instead of "a clear quote today"; the FAQ buttons announce whether they
are open (`aria-expanded`).

The six pages that sold fibre (Kampala, Entebbe, Gulu, Jinja, Mbale, Mbarara) lost the fibre card (now a *Free ICT
consultation* card, so the three-card grid stays), the fibre sentences, the fibre FAQs and every "failover" promise.
Across the twelve, statements of coverage DishNet does not control ("anywhere", "every compound", "every district",
"covers all of Teso / Bunyoro", "however remote"), unapproved timing ("same day", "same-week", "within a week", "within
a few working days"; the approved homepage's "In Kampala and major cities we usually install within 1–3 working days of
payment" stays), unconfirmed local teams and track record ("installation crews are based in the city", "our northern
team", "Mbarara-based installers", "signature", "specialty", "daily", "core services", "better than anyone"), resale
wording (per-tenant billing, tenants each with "their own login and bill") and CCTV sold together with Starlink were
replaced by what DishNet can do, with the review of Starlink's terms stated where sharing a connection is involved.
Hotspot content stays (owner undecided); its comparisons with mobile data and its payback claim were softened.

### 3.7 Defects in the scripts, found by the reviews and fixed

- **The website chat confirmed details it had not stored** (`assets/js/chat.js`). It said "Thank you! Our team will
  reach out." and remembered the visitor as a lead before the request finished, whatever the answer. It now waits: only
  `{ok: true, lead_saved: true}` from `web_chat.php` (the endpoint's own lead-only answer) gives "Thank you — your
  details reached our team, and we will be in touch."; a refusal or a network failure says the details did not reach us
  and offers WhatsApp. The lead card links the Privacy Policy. Proved in a browser with a mocked endpoint, and the
  main-branch script, under the same mock, shown to thank a refused visitor (§6).
- **The order flow could offer no kit.** It kept only products whose name contains "package"; the product names
  recorded for uCRM are "Starlink Mini Kit" and "Starlink Standard Kit". Now on the same page as the kit cards, it
  uses the cards' own rule (`kit` or `package`). Proved both ways in a browser (§6). The live product names are an open
  point (§8.1).
- **The homepage's closed phone menu could still be reached with Tab** (`assets/js/home.js`; review C5). It was only
  faded out, and the page behind the open menu stayed reachable. The closed menu is now inert, and while it is open the
  rest of the page is inert, as `nav.js` already does on every other page.
- **A feed with plans but no kit left the plans grid and the order flow unbuilt** (`prices.js`; review C6). The kit
  section returned from the whole function; it now keeps the page's own kit cards and goes on.
- **The chat dropped the session id that a stored lead's answer carries** (`chat.js`; review C8), so a lead given
  before the first question started a second conversation. It now keeps it, as a question's answer already did.
- **Hard-coded "UGX 0 upfront"** in `prices.js` for any Flex plan (a figure that does not come from the billing feed)
  became "Kit included" / "No kit to buy upfront".
- **"I already have a kit" was an order for a DishNet plan** (decisions 2 and 3; the card said "we connect it to a
  DishNet plan", and after a first rewording the flow behind it still forced a plan, printed "Total today" and sent "I
  would like to order Starlink … Plan: … Total today"; second review C3/C6). It is now an **enquiry**: no plan step, no
  price or total, the button reads *Send my enquiry on WhatsApp*, and the message asks about "installation and support,
  registering it in my own name, or a managed DishNet plan", with the location the visitor typed (still required; with
  the box emptied, the prompt asks for a location "to send your enquiry", not "to complete your order").
- **Widening the window with the homepage menu open left the page inert** behind a menu that the wider layout hides
  (second review C5); the menu now closes when the phone layout ends.

### 3.8 Blog, WiFi-zone, reseller and distributor pages (kept; claims corrected)

Kept, with their links, because the owner has not decided on these programmes. Corrected: the WiFi-zone article's
description (it promised costs it does not give); "hundreds", "already running profitably with DishNet", "runs
itself", "steady, predictable income", "monitoring"; the solar article's unsourced statistics and power figures, and
its solar-installation claims (now advice); "Sell Starlink in your town", "wholesale pricing" and the claimed partner
network on the reseller page; on the distributor page "four regions and 40+ towns" (now the approved fact), "authorised
distributor" (now "DishNet distributor"), "activate connectivity" (now "arrange Starlink activation, which Starlink
approves"), "Data Network" (now "Wi-Fi & managed networks"; the stored service id `data` is unchanged), the
confirmation step called "Done" even when nothing was saved, and a 768 px header that ran 43 px off the screen. The
consultation sentence replaces "Coverage checks and quotes cost nothing" on the three articles.

**Not changed, and listed in §8.4 for the owner:** everything the review marked "keep pending the owner's decision"
(Airtel Money billing and vouchers, per-tenant billing, "you own the customer", partner installation, lead routing),
and the distributor page's publicly visible *DishNet Planning Preview (internal)*.

### 3.9 Policies

Generated by `policies/build.py` as before; `--check` passes. Changed: fibre removed from the Terms of Service (scope;
service requirements) and the Acceptable Use policy (summary; scope; fair use; page description); "answered every day"
→ "WhatsApp support is available seven days a week" (Terms; Complaints); own-name activation in the Terms of Sale now
says Starlink controls registration approval, KYC verification and activation; "we activate your kit" → "we arrange the
activation of your kit" (Terms of Sale; Starlink Installation); the Privacy Policy no longer says the site loads its
fonts from Google — **it serves its own fonts; no page loads a font service** (checked across all pages).

Second round (the policies-and-integrations audit and the independent review):

- **Terms of Service §3** now ends the own-name paragraph with "Starlink controls registration approval, KYC
  verification and activation, so we cannot promise that a registration is approved." (as the Terms of Sale already
  did), and the managed-plan sentence continues "A DishNet plan is DishNet's own service, provided under DishNet's own
  terms; it is not a Starlink product. Whether a DishNet plan is available to you is reviewed individually, subject to
  Starlink's terms, requirements and approvals." The Terms of Sale §7 carries the same "DishNet's own service" sentence.
- §4 "before activating a service" → "before a service is activated"; §5 "because Starlink or another network
  provider changes or withdraws the service" → "because Starlink changes…" (decision 6); §14 "ask us about a backup
  connection. We are glad to design one with you." → "tell us before you buy, so that we can plan the installation,
  power supply and equipment with you." (a backup connection implies a second, non-Starlink link; the sceptic thought a
  second Starlink kit could be meant, so this one is flagged for the legal review too).
- **Not changed, deliberately:** Terms of Sale §7's remedy "we replace it with one that Starlink activates or refund
  everything you paid for it, as you choose". It was changed during the round and **restored**: the sceptic showed it is
  a remedy for a kit-level refusal with a full refund always available, not a promise of registration. Whether to
  reword a customer remedy is a legal decision (§8.2).
- `Last updated: 26 October 2026` on all nine pages (it said 12 October although six bodies changed); see §2.3 step 4.

These pages are still not legally reviewed (`docs/67`); every edit above should go to that review.

### 3.10 The 19 app tutorials

Audited page by page, then edited by a script that asserts every replacement (and refuses any new text carrying a
long number other than the published WhatsApp number, so no merchant ID, account or phone number can enter):

- **Shared parts, all 19:** the standard inner-page menu (Home, Services, Shop, Security, Coverage, App, Contact); the
  phone menu gains *Starlink Kits*, *Shop Accessories*, *CCTV & Security* and *Pay a Bill* and loses *Live Demo*, as on
  every other page; the footer gains *CCTV & Firewalls* and
  loses *Reviews* (it pointed at Why DishNet); the help band says "WhatsApp support is available seven days a week."
  and its button opens a help message; the phone mock screens are `aria-hidden` and `inert` (they hold buttons that do
  nothing); the tap-pulse animation stops for visitors who ask for reduced motion; titles end "| DishNet Uganda".
- **"Works on Android & iOS"** became "Android app or web portal" (there is no iOS app; the login tutorial says so);
  the WhatsApp-only location tutorial says "Works with WhatsApp on any phone".
- **The location tutorial** (`activate-starlink-send-location.html`, URL kept) is now *How to send us your
  installation location* for a managed DishNet plan, with own-name customers pointed to Starlink. Removed: the Juba
  examples (Hai Cinema, Munuki, St Bakhita School) and coordinates (now Ntinda / Kira, 0.354° N, 32.612° E), "DishNet
  Activations", "Activation Required", "we activate", "This takes 5-15 minutes", "within minutes during working hours
  (8 AM – 8 PM)", the default Wi-Fi password promise and a comment naming the plugin template. Starlink controls
  activation and how long it takes; the privacy paragraph links the Privacy Policy.
- **Invoices:** the bank-transfer block named the South Sudan banks; the mock now shows Airtel Money with "see Pay a
  Bill" and **no merchant ID**; the text sends people to Pay a Bill for the merchant ID; "within 24 hours" became "check
  the payment, apply it to your account" (no timing promise).
- **Other pages:** speed figures removed from the speed test (Starlink does not guarantee a speed); dish status "asks
  Starlink", not the dish; "Suggested pricing: 500 UGX per hour" removed (the operator sets their own price);
  "ask us about a managed WiFi zone" became "message us on WhatsApp to talk through the options for your business";
  account linking happens "once we have confirmed that both accounts are yours"; sample names are Ugandan; the help
  page's "If you message outside working hours … as soon as the team is back" became "You can send your message at
  any time."; the location tutorial's own-name aside uses the approved sentence and promises no approval.
- **Not done (open, §8.3):** a sentence saying the app's Starlink features are for managed plans only, because whether
  they work for own-name customers is not established; Open Graph images and the index's list of all 18 tutorials
  (optional SEO).

## 4. Files

### 4.1 Changed

All under `dishnet-web-uganda/`. Every page's `?v=` asset stamps were refreshed by `stamp-assets.sh`.

| Group | Files |
|---|---|
| Homepage | `site/index.html`, `site/home.css`, `site/assets/js/home.js` (also the phone-menu fix, §3.7) |
| Scripts and styles | `site/assets/js/chat.js`, `prices.js`, `shop.js`; `site/styles.css` |
| Main pages | `services.html`, `starlink-kits.html`, `coverage.html`, `about.html`, `contact.html`, `whatsapp.html`, `faq.html`, `security.html`, `why-dishnet.html`, `shop.html`, `careers.html`, `get-the-app.html`, `guides.html`, `pay.html`, `404.html` (all in `site/`) |
| Town pages (12) | `site/starlink-{arua,entebbe,fort-portal,gulu,hoima,jinja,kampala,lira,masaka,mbale,mbarara,soroti}.html` |
| Blog and programmes | `blog.html`, `blog-starlink-installation-day-uganda.html`, `blog-starlink-solar-uganda.html`, `blog-wifi-hotspot-business-uganda.html`, `hotspot.html`, `reseller.html`, `become-a-distributor.html` |
| Tutorials (19) | `site/tutorials/*.html` — every page in its shared parts and title, most in their text (§3.10); `site/tutorials/tutorial-shared.css` (reduced motion) |
| Policies | sources `policies/bodies/{terms,terms-of-sale,installation,acceptable-use,privacy,complaints}.html` and `policies/build.py`; generated `site/{terms,terms-of-sale,installation,acceptable-use,privacy,complaints}.html`; `site/{policies,warranty,returns}.html` (shared parts only) |
| Site files | `site/sitemap.xml`, `nginx.conf` |
| Checks and notes | `verify-site.sh`, `README-DEPLOY.md` |

### 4.2 Removed, and where their addresses go

| Removed file | Address | Answer (`nginx.conf`) |
|---|---|---|
| `site/fiber.html` | `/fiber.html`, `/fiber` | 301 → `/services.html` |
| `site/blog-starlink-vs-fiber-uganda.html` | `/blog-starlink-vs-fiber-uganda.html`, `…-uganda` | 301 → `/blog.html` |
| `site/assets/img/og-uganda.png` | — | replaced by `og-dishnet-uganda.png` on every page |
| (none; a missing address) | `/app` | 302 → `/get-the-app.html` (the plugin's Uganda profile and customer messages link `/app`, which answered 404) |

The redirects keep the site's three security headers and its single `Cache-Control: max-age=600`, so a browser
remembers them for ten minutes, not for ever.

### 4.3 Added

`site/assets/js/order.js` (the order flow's location box, moved from the old homepage script),
`site/assets/img/og-dishnet-uganda.png` (1200 × 630 social card, rendered from an HTML card with the site's own fonts
and the Standard kit product photo; it states the approved facts word for word), and this record.

### 4.4 Checks changed

`verify-site.sh` gains **withdrawn pages** (each redirect's status, target, headers and cache lifetime, the target
answering 200, the files gone) and **positioning rules** — on every page, the sitemap and robots, tutorials included,
zero matches for: fibre; LTE; 24/7; "four regions" / "every region"; "10+ years", "ten years", "two countries",
"thousands of"; support-hours wording ("answered every day", "support every day", "every day of the week"); "Uganda
since 2013" (the approved fact is "DishNet Africa has operated since 2013"); 4G, "fixed wireless", "data network";
activation promises ("we activate", "we will activate", "activate your kit/dish", "5-15 minutes"); response-time
promises ("within minutes", "within the hour"); the South Sudan bank names; and "working hours", "outside office /
business hours", "team is back". Each of the 12 rules first proves it matches a sample, so a broken rule cannot pass
by matching nothing.

The **commercial rule** now also scans the scripts (comments removed first, so a figure a script writes into a page is
caught), and the tutorials are exempt only inside their phone mock screens: an HTML parser drops `div.tut-step-mock`
blocks (nested divs included) and scans the rest — text and attribute values (descriptions, alt text, titles) — so a
price in a tutorial's own text fails. Both have controls; the mock filter's control has figures before and after a
mock, so it also proves the filter leaves the mock (a copy whose filter never left it, and a copy that skipped
attributes, each failed the control).

**Control on the controls:** a throwaway copy with one planted violation of each new rule (a tutorial body price, a
script price, "answered every day", "Connecting Uganda since 2013", "4G backup", "We activate your kit", "We respond
within minutes", a bank name) made `verify-site.sh` fail on every one by name, and a price inside a mock was not
flagged. The copy was deleted.

## 5. Database, application and integration changes

**The website needs no database change and no plugin release.** Everything below is outside this branch.

| System | What | When |
|---|---|---|
| Database | none — the site stores nothing. Old distributor applications may hold the label "Fibre & fixed connectivity"; they are historical staff records and stay | — |
| uCRM catalogue | confirm the live plan and product names (§8.1): the site's plan-to-Starlink-service map and its "Best value" badge use the older *DishNet Lite / Home / Business / Flex* names | before release |
| uCRM catalogue | product **descriptions** are printed verbatim on the kit cards and in the order flow; read them against the decisions (no fibre, no approval or activation promise, approved facts only) | before release |
| uCRM catalogue / plugin config | **the public price feed publishes every uCRM service plan that has a name and a price — it ignores *active/archived*, category and organisation** (`lib/PublicPriceFeed.php`; the plugin's own assistant does skip inactive plans). A fibre, LTE or fixed plan or product in Uganda uCRM, archived or not, would appear on the kits page and in its order flow. Check uCRM read-only; delete such an item, or add its exact name to `public_price_exclude` on the Uganda install, then read the feed again. Do not rely on archiving | before release |
| uCRM / plugin config | if any **Flex** plan is priced, the site shows a Flex card and option ("Kit included"); settle Flex (§8.1) or exclude it with `public_price_exclude` | before release |
| Plugin config (website chat) | **The site's chat is switched off while the managed plan is held back (`CHAT_ON = false` in `chat.js`, §10.1)**, so this row matters only when it is switched on again. `web_chat_origins` must list `https://dishnetuganda.com` and `https://www.dishnetuganda.com` (the default is the South Sudan site; without them every chat message and lead from this site is refused, and the chat now says honestly that the details did not reach us); `web_chat_enabled`; the WhatsApp hand-off number; the teaser text (`web_chat_teaser`) read against the decisions | before release |
| Plugin knowledge (the WhatsApp assistant and the website chat share it) | in the Uganda Knowledge Base, edit the items that carry unapproved claims: "supports customers 24/7 on WhatsApp" and "operating since 2013, with operations in South Sudan, Sudan and Uganda" (company identity); "every district, town and village in all four regions" and "Starlink reaches everywhere in Uganda" (coverage); "DishNet purchases directly from Starlink in Uganda" and "genuine Uganda-channel stock" (a Starlink relationship); "installs it properly, activates it" and "activation" as included (activation promises). Add items with the approved facts and the own-name / KYC / restricted-registration wording, the free consultation and CCTV as a separate service — **not** the managed-plan wording while the plan is held back (§10.1), and the assistant must stop offering DishNet monthly plans, Flex and a first month for as long (§10.4). The seeder never overwrites an item an operator has edited; correcting `tools/knowledge_seed.json` only helps future installs. Also read `ai_identity_line`, `bot_custom_instructions`, `wa_flyer_caption` and the `ai_fact_*` values (configuration; `php tools/ai_facts.php` shows them) | before release |
| Plugin (sales assistant) | the pre-filled WhatsApp openers change from "I'd like a quote …" to "I'd like to book my free ICT consultation …"; confirm nothing keys on the old openers | before release |
| Plugin (separate release) | `DistributorApplicationService::SERVICES`: relabel `data` to match the site ("Wi-Fi & managed networks"; today staff see "Data Network", which is not what the applicant chose — review C7) and drop `fibre` (the site no longer offers it, but a hand-made request could still store it), with `tests/test_distributor_apply.php`. The endpoint's default origins are the Uganda site only | recommended before release |
| Plugin | `profiles/uganda.json` `app_url` and the customer messages link `/app`; the site now answers it (302 → the app page). No plugin change | — |
| Plugin (separate release, Uganda-gated) | `lib/LifecycleService.php` kit-collected / location / welcome WhatsApp templates are not tenant-gated and are South Sudan's (+211 number, a Juba office, "We activate remotely", activation timings, speed and data claims, a fixed default Wi-Fi password). The location tutorial used to copy them. **Confirm whether this flow messages Uganda customers**; if it does, a Uganda template set is needed without those claims, South Sudan unchanged | confirm before release |
| Plugin (separate release, Uganda-gated) | the customer portal shows "We respond within minutes" (`tabs/customer_app/portal.php`), and the WhatsApp balance auto-reply names the South Sudan banks (`lib/WaAutoReplyService.php`). If Uganda customers see them, give Uganda its own text, so the app and the site agree | confirm before release |
| Customer portal legal text | `profiles/uganda.json` legal v1.2 has no own-name, KYC or managed-plan sentence. Aligning it with the new website sentences means bumping the legal version, which asks every Uganda customer to accept again — an owner and legal decision (§8.2) | owner's decision |
| n8n Uganda bot (`n8n/DishNet_Uganda_AI_Bot_v1.0.json`; whether it runs live is not established in the repository) | it says DishNet does not sell fibre, but also: "operating in Uganda since 2013", support hours "Mon–Sun 7 AM – 10 PM", "Mon–Sat 9 AM – 6 PM", "We target 99.9% uptime", "Reaches all 10 Uganda states", same-day and free installation, "every district, town and village", "within the hour", bank transfer only, a second WhatsApp number for Sales; its QA judge describes "an ISP in Uganda selling Starlink kits, FTTH fiber, and LTE" and a node tags leads "Fiber"; it calls `prices.php` directly, not `public.php?page=prices`. **If the workflow is active**, correct the prompt and nodes in n8n and the repository copy | if active, before release |
| Google Search Console | submit the sitemap; request removal of `/fiber.html` and the fibre article | after release |
| EasyPanel | none until the release; the release is the merge (§2.3) | — |

## 6. Tests and results

Run on the branch, in this container, against a local copy served by nginx with the repository's real `nginx.conf`
(never against the live site). Every request to another host (the price feed, the chat, the distributor endpoint,
WhatsApp) was answered by a mock or refused, so nothing reached the live plugin.

### 6.1 Checks (final run, on `1ae1d57b`)

| Check | Result |
|---|---|
| `./verify-site.sh` | **PASS** (exit 0): 3/3 security headers and one Cache-Control everywhere; 63 pages, 0 non-200; 76 references, 0 broken; real 404; `/login` 302; withdrawn pages 301 with headers and ten-minute expiry, `/app` 302; canonicals and sitemap; one WhatsApp number; no price outside the tutorial mock screens or in a script; positioning rules (self-checked, §4.4) |
| `python3 verify-address.py` | exit 0: 91 JSON-LD blocks parse; one address, one phone, one 40-town list |
| `python3 policies/build.py --check` | exit 0: "policies: up to date" (the FAQ's structured data equals its visible questions) |
| `bash stamp-assets.sh --check` | exit 0 |
| `node --check` on every script in `assets/js/` | no syntax error |
| Browser journeys (Playwright, Chromium) | **763 passed, 0 failed** (exit 0): every page at 360, 390, 768, 1024 and 1440 px loads with no script error, no failed same-site request, no sideways scroll and no fibre / LTE / 24-7 / region claim; homepage wording, CCTV separation, menu, the WhatsApp button and chat launcher not overlapping; the consultation form refuses an empty form and a malformed e-mail, then prepares the WhatsApp message and claims nothing was sent; the kit guide; the kits page order flow with the recorded uCRM names (kits offered, location required, the WhatsApp order carries kit and location), with older names, business and Flex plans, and with the price feed down; the chat (stored, refused, network failure); the distributor wizard walked to a stored submission; the 404 page at a nested address; the header button and phone menu on every page; every redirect; and for the review fixes: the homepage's closed phone menu unreachable by 40 Tab presses and the page behind the open menu inert (360, 390, 768 px), a feed with plans but no kit still filling the plans grid and building the order flow, a stored lead's session id kept; the own-kit option as an enquiry (no plan step, no price or total, location still required with an enquiry prompt, a WhatsApp text that orders nothing); widening from 1024 to 1300 px with the menu open closes it and releases the page |
| Controls (the checks can fail) | the main-branch `prices.js` offers **no kit** in the order flow for the recorded names; the main-branch `chat.js` **thanks a visitor whose details were refused**; the pre-fix `home.js` lets Tab reach the closed menu; the pre-fix `prices.js` never fills the plans grid when the feed has no kit; the pre-fix `chat.js` drops a stored lead's session id; the `b499a6af` `prices.js` forces a plan step for an own kit; the `d1a63dbc` `prices.js` tells an enquirer to "complete your order"; the `b499a6af` `home.js` leaves the page inert after widening; each positioning rule must match a sample before it scans, and every new rule failed on a planted violation (§4.4) |
| Layout | the distributor page's header was 811 px wide in a 768 px window, on `main` too; fixed (its menu now folds below 900 px, as everywhere else) and measured at 761–1024 px |

### 6.2 Independent review of the whole diff (`e01696a2..c3274ca4`)

Four reviewers (decisions; links and SEO; scripts and forms; policies and consistency), each finding then checked by a
separate sceptic told to refute it. **10 confirmed, 14 rejected.**

| # | Confirmed finding | Outcome |
|---|---|---|
| C0–C4 | location tutorial: "5-15 minutes" and "we activate"; "within minutes … (8 AM – 8 PM)"; get-help: "Replies come back within minutes", "We respond within minutes"; the 18 help bands and the tutorials index paraphrase the support fact | fixed by the tutorial round (§3.10) |
| C5 | homepage: the closed phone menu is reachable with Tab | fixed (§3.7), browser test with control |
| C6 | `prices.js`: no kit in the feed → no plans grid, no order flow | fixed (§3.7), browser test with control |
| C7 | distributor form: the site labels service `data` "Wi-Fi & managed networks"; the plugin stores it as "Data Network" | **outside the site**: the plugin change in §5 (*any time* → recommended before release, so staff see what the applicant chose) |
| C8 | `chat.js`: a stored lead's session id is dropped | fixed (§3.7), browser test with control |
| C9 | About: one dash clause nested in another | fixed (the managed plan has its own sentence) |

Rejected findings were rejected for one of three reasons, recorded so nobody re-raises them blindly: already fixed in
the working tree when checked (Terms "another network provider", "before activating", the missing own-name KYC
sentence, the policy date, the blog card ending); not contradictory (the business-plan wording on the kits page and the
Acceptable Use policy — both say service continues more slowly after the priority data; the owner still confirms the
exact behaviour, §8.1 item 10); or not a defect under the decisions (the Terms of Sale replacement-kit remedy — restored
after being changed, §3.9; "backup connection" — changed anyway on three other agents' reading, flagged for legal
review; the KYC wording variant — aligned to the approved sentence anyway; the Gulu cross-border FAQ; the first month
included in kit packages, already §8.1 item 9).

### 6.3 Second review, of the second round (`c3274ca4..b499a6af`)

Three reviewers (tutorial text after the automated replacements; policies and claims; scripts and checks), each
finding checked by a sceptic. **10 confirmed (8 distinct), 7 rejected.** All fixed in `d1a63dbc`:

| # | Confirmed finding | Fix |
|---|---|---|
| C0 | help page: "If you message outside working hours … as soon as the team is back" (hours beyond the approved fact) | "You can send your message at any time."; a *working hours* rule in `verify-site.sh` |
| C1 | location tutorial: "Starlink handles registration approval, KYC verification and activation in your own Starlink account" | the approved sentence, "No approval or activation is promised", and "On a managed plan, send your location…" |
| C2 | the tutorials' phone menu had no Shop link (Pay a Bill only in the footer) | *Shop Accessories* and *Pay a Bill* added on all 19 |
| C3, C6 | the own-kit card offered options but the flow still ordered a plan with a total | an enquiry (§3.7), browser test with control |
| C4, C9 | README said the email is on "every page"; it is on 44 of 63 | the measured count |
| C5 | widening with the homepage menu open left the page inert | the menu closes when the phone layout ends; browser test with control |
| C7 | the tutorial-mock filter's control could not show the filter leaving a mock | figures before and after the mock; a weakened copy now fails it |
| C8 | attribute text outside the mocks was not scanned for prices | attributes scanned; control; a weakened copy fails it |

Rejected, and why (kept for the record): the location mock "Starlink completes activation once the address is
registered" (the sceptic read it as describing who activates, beside "Starlink controls activation and how long it
takes" — the owner may still prefer "Starlink then decides on activation"); the get-help mock reply "I can see your
account" (sample data in a mock); the Terms §3 sentence order (reads correctly); positioning rules not scanning
scripts (a coverage gap, not a defect of this round; the scripts' visible strings were reviewed by hand); the no-kit
order flow now offering own kit and Flex (intended); a support-hours rule that matched nothing before this round (it
guards against reintroduction); the rule/sample count assertion (both lists are equal today).

### 6.4 Third review, of the second-review fixes (`b499a6af..d1a63dbc`)

Two reviewers (order-flow and menu behaviour; text and checks), each finding checked by a sceptic. **1 confirmed,
4 rejected.**

- **Confirmed and fixed (`1ae1d57b`):** with the location box emptied, the own-kit button (rightly still gated on a
  location by `order.js`) said "to complete your order". The button now carries `data-of-kind="enquiry"` and the
  prompt says "to send your enquiry"; browser test with control.
- **Rejected:** no scroll to the summary after choosing the own kit (the summary sits directly under the card, which
  is marked selected — polish, not a defect); focus falling to the page after widening with the menu open (the wider
  layout hides the focused button whatever the script does; the page is released, which was the defect); "You can send
  your message at any time" read as an hours claim (it describes sending, not answering, and sits after the approved
  sentence); the working-hours rule's sample exercising only one of its three alternatives (the rule works; a sample
  per alternative would be stricter, recorded as an optional improvement).

Every confirmed finding of the three reviews is fixed or, where it lies outside the site (review 1 C7), listed in §5.

## 7. Rollback plan

Kept apart from the release steps on purpose: they are never to be run together.

**Before the release** there is nothing to roll back: `main` and the live site are unchanged.

**After the release** (merged into `claude/study-this-jhe2eg`, EasyPanel rebuilt; §10.7):

1. Revert the merge on `claude/study-this-jhe2eg` (a `git revert -m 1` of `0de69559`), push, and redeploy in EasyPanel; it
   then serves the site as it was at `e01696a2` (the branch's site tree before the merge equals it). Revert `647faccf`
   on `main` too, by pull request, so the two branches agree. Or, in EasyPanel, redeploy the build
   made from `e01696a2`, if it is still listed.
2. Check: the old homepage loads; `/fiber.html` answers 200 again; `og-uganda.png` answers 200.
3. What a rollback cannot undo: a browser that followed a redirect keeps it for at most ten minutes; search engines
   may have recrawled the new pages and will recrawl the old ones; links shared in the meantime point at pages that
   exist in both versions, except the two removed URLs, which come back.

No database, plugin or configuration change belongs to this release, so none needs restoring. If any of the §5 changes
outside the site were made for the release, each is undone on its own system and is not part of this rollback.

## 8. Open questions — legal, contractual, regulatory and business

Answered nowhere in the repository; **not assumed**. Items marked **before publishing** must be settled before the
release.

### 8.1 The managed DishNet Starlink plan — before publishing

The live site already sells DishNet plans (the old homepage's plans grid and order flow), and the policies describe
them; this release describes them more plainly as DishNet's own managed service. The questions are the same either
way:

1. **Starlink's terms.** May DishNet register and hold Starlink accounts on customers' behalf and supply the service
   under a DishNet contract? Which Starlink plan types allow it?
2. **UCC licensing.** Does supplying and billing an internet service in DishNet's name need a UCC licence or
   authorisation? (The town pages' structured data no longer calls DishNet an "Internet service provider".)
3. **The account holder for KYC.** Whose identity is verified for a managed account, what DishNet collects to do it,
   and on what basis under the Data Protection and Privacy Act 2019.
4. **Account transfer.** The Terms of Service promise a transfer to the customer on request, "subject to Starlink's
   rules": does Starlink allow it, with what KYC, fee or minimum period?
5. **Liability.** What DishNet answers for when Starlink's service fails, against what Starlink's own terms allow it to
   promise.
6. **Data protection.** Customer data sent to Starlink (SpaceX) and held outside Uganda.
7. **Flex packages** (kit included, monthly) depend on Starlink's leasing terms; the site shows a Flex card ("Kit
   included") for any priced Flex plan (§5). The sales assistant's knowledge says Flex may be quoted but not contracted
   until management confirms.
8. **Bring your own kit.** The order flow's "I already have a kit" option is now an enquiry about installation and
   support, own-name registration or a managed plan, each reviewed individually; it quotes no plan or price. Can a kit
   bought elsewhere join a managed account at all, and on what terms? The Terms of Sale say imported kits might not be
   accepted for activation.
9. **The first month included.** Kit packages say "your first month of internet" is included; for an own-name
   registration, is that a Starlink subscription DishNet pays? The Terms of Service limit it to kit packages; uCRM
   prices installation separately.
10. **Business plans after the priority data.** The site now says the speed drops until more is bought (the sales
    assistant says about 1 Mbps); the Acceptable Use policy says the plan continues on standard data. Confirm
    Starlink's current Local Priority terms for Uganda and align both.
11. **Plan names.** Which names does uCRM publish? The site's service map is written for *DishNet Lite / Home /
    Business / Flex*; the names recorded for uCRM are *Residential Lite (up to 100 Mbps)* and *Residential (up to
    400 Mbps)*.

Where the managed plan appears, if the owner decides to hold it back: the homepage *Ways to get Starlink* card and the
own-name/managed comparison; `starlink-kits.html` `#plans` (its explanation and note) and the order note; FAQ *What is
a managed DishNet plan?* and the restricted-registration answer; `about.html`; the town pages that mention it; the
installation-day article; and the Terms of Service, Terms of Sale and Starlink Installation pages, which already
describe DishNet plans on the live site.

### 8.2 Policies and legal

- The policy pages are still not legally reviewed (`docs/67`); add this release's edits (§3.9) to that review,
  including the new Terms of Service §3 sentences and the "backup connection" change.
- **Terms of Sale §7 remedy:** "If Starlink will not activate a kit you bought from us, for a reason that is not your
  fault, we replace it with one that Starlink activates or refund everything you paid for it, as you choose." Two
  reviewers read "one that Starlink activates" as an activation promise; the sceptic read it as a kit-level remedy with
  a refund always available. Kept as it is; reword only on legal advice (for example "a replacement kit or a refund of
  everything you paid for it, as you choose").
- **Does any change need advance notice?** The Terms of Service promise notice before changes that are worse for the
  customer; decide whether this release's policy edits are such a change.
- **The customer portal's legal text** (v1.2): realign it with the new website sentences (a version bump asks every
  Uganda customer to accept again), or leave it.
- **Privacy statements in the tutorials:** the location tutorial (location kept with the account records, never live
  tracking) and the connected-devices tutorial (device names, IP and MAC addresses, not browsing) should be read
  against the Privacy Policy in the legal review.
- The Terms of Sale say "We deliver anywhere in Uganda" and the warranty pays transport "anywhere in Uganda", while the
  marketing pages now say "delivered to your town". Keep both, or align?
- **Careers:** "We keep every CV on file" — the Privacy Policy has no section on job applicants or a CV retention
  period (Data Protection and Privacy Act 2019). Unchanged; decide the retention and add a notice.
- The legal entity: the footer and JSON-LD say "DishNet Africa Ltd — Uganda". Confirm the registered name of the
  Ugandan company and how it relates to DishNet Africa (South Sudan).
- Office hours "Mon–Sat 08:30–18:00" (contact page and every page's JSON-LD) are not an approved fact; confirm.
- Airtel Money "free of charge for you" (Pay a Bill, Why DishNet, FAQ): confirm against Airtel's current tariff.

### 8.3 Business facts to confirm

- "DishNet serves more than 40 towns" is approved, but the structured data lists exactly 40 towns.
- "DishNet Africa has operated since 2013, starting in South Sudan": the place is now stated; confirm.
- Installation in 1–3 working days of payment (approved homepage wording): it is now always paired with "Starlink
  controls … activation" where own-name registration is involved.
- Which CCTV and firewall brands DishNet actually supplies; whether maintenance contracts and priority call-out are a
  real, priced offer.
- Whether DishNet Uganda supplies or installs solar or backup power (the FAQ and the solar article now say *advise*).
- Speed and power figures were removed because no source was recorded; publish figures only from Starlink's current
  specifications, with the date.
- The app: which features it will offer in Uganda, and whether everything in it is also in the customer portal. Is an
  iOS app planned (the tutorials now say "Android app or web portal")? **Do the app's Starlink features (Wi-Fi
  password, Hotspot Mode, devices, dish status, usage, sites) work for customers registered in their own name, or only
  on managed plans?** The tutorials assume a DishNet-managed account.
- Payments: does DishNet Uganda take bank transfer, and to which bank? (The tutorials no longer show any bank.) How
  soon is a payment confirmed after "I've paid" (the "24 hours" promise was removed)?
- The location tutorial says that replying **CALL ME** gets a phone call from the team; the assistant does treat "call
  me" as a request for a person. Does someone actually phone?
- Approved office or support hours: the site's structured data says Mon–Sat 08:30–18:00; the n8n bot says otherwise
  (§5). Only "WhatsApp support is available seven days a week" is approved.
- The blog articles' publication dates (needed for search results; the structured data has the month only).
- Town pages: are there DishNet installers based in Kampala, Mbarara, the north and the east? Does DishNet already
  serve lodges or tea estates near Fort Portal, coffee washing stations near Mbale, or refugee-response agencies in
  West Nile? The pages now state capability only.
- Town pages: are kit, delivery and installation priced the same nationwide (Soroti said "Soroti pays Kampala
  prices"), and is staff training included?
- Town pages: may Uganda-registered kits be used in DRC or South Sudan (Arua, Gulu), and which entity contracts with
  cross-border organisations?
- Masaka's FAQ names Kalungu, Lwengo and Bukomansimbi, which are not in the 40-town list.
- Are connectivity, Wi-Fi, CCTV and firewalls ever sold under one contract, or always separately (Hoima)?

### 8.4 The programmes the owner has not decided (kept as they are)

- **WiFi zones / hotspot** (the hotspot pages, and the tutorials on Hotspot Mode and selling time access): do
  Starlink's terms allow a connection to be shared with or resold to the public, and on
  which plans? Does a public pay-as-you-go zone need a UCC licence, and whose? Are Airtel Money billing, vouchers, the
  captive portal, per-user limits and monitoring available in production today (repository records say the platform is
  not deployed)? Does the "written zone agreement" exist? Per-tenant billing in buildings is the same question.
- **Reseller and distributor:** can DishNet appoint resellers or distributors for Starlink hardware or managed plans
  under its own supply agreement? The two pages contradict each other on who installs (partners against "only trained
  DishNet technicians"), who owns the customer, and margins. Sharing enquiries with partners needs privacy cover.
  **The distributor page shows anyone a "DishNet Planning Preview (internal)"** naming internal steps; remove it, or
  keep it behind a staff-only switch?
- **Accessories shop:** prices come from the shop feed (`page=shop`), not from `prices.js`; kits appear there at
  kit-only prices next to the package prices on the kits page. Intended?

## 9. Kept on purpose

The hotspot, reseller, distributor and shop pages and every link to them; the managed-plan wording the owner approved;
the policy numbers in `PARAMS`; the address, phone number, 40-town list and email; every price, which still comes
only from uCRM through the plugin's feeds.

## 10. Launch preparation (26 Oct 2026)

The owner approved the launch on 26 Oct 2026, with two conditions this section answers: *"If the necessary
contractual or regulatory position is not confirmed, temporarily remove or hide the managed-plan offer and related
ordering options, while retaining the approved own-name registration guidance and general ICT consultation service"*,
and *"Do not publish unverified claims or expose internal planning information."*

### 10.1 The managed DishNet plan is held back

*Reversed later the same day at the owner's request: see §10.8.*

**Why.** The repository confirms neither condition: there is no written position from Starlink and no
authorised-reseller agreement (`docs/67` §E item 1), and the UCC letter of 4 Sep 2026 is a conditional no-objection,
not a licence; it requires an Equipment Vending and Installation Licence before services start, and whether
providing and billing internet service in DishNet's name needs a further licence is not established (`docs/67` §E
item 2). The questions in §8.1 are therefore still open, and the offer is hidden until they are answered.

**What a visitor no longer sees.** No marketing page offers a managed DishNet plan, names a DishNet plan or a Flex
package, promises "your first month of internet", or shows a monthly price beside a kit:

- `prices.js`: `SELL_PLANS = false`. No plans grid, no "/month" figure, no Flex card, no plan step; the order flow
  sells the kit (delivery and professional installation included, as on the live site) with the Starlink service
  "in your own name, with Starlink", and the WhatsApp order says so. While plans are off, a kit description from
  uCRM that mentions a plan, a month, internet service, a subscription or Flex is not shown (the invoice labels could
  not be read before launch, §10.4).
- `starlink-kits.html`: the `#plans` section removed; the cost box, package box and order copy describe the kit and
  the customer's own Starlink subscription; the unused service map removed.
- The homepage, FAQ (visible text and FAQPage JSON-LD), About, Services, WhatsApp, 404, the Kampala, Gulu and Soroti
  pages, the installation-day article, the shop page and `shop.js`, the chat greeting and placeholder, the tutorial
  index's "start here" badge, and the Terms of Service first-month clause (policies rebuilt).

**What stays.** Own-name registration guidance with the approved sentence; the free first ICT consultation; kits,
delivery and installation ordered on WhatsApp; CCTV and network security as a separate service. Policy pages and the
19 app tutorials keep their DishNet-plan wording: they are the contract and the app help of existing customers.
Two FAQ answers for existing customers (moving a kit, refunds) keep their rule without naming the plan.

**The website chat is switched off too** (`chat.js`: `CHAT_ON = false`; the floating WhatsApp button stays). It
answers from the same catalogue as WhatsApp, and the assistant's instructions recommend the live monthly plans with
their prices: on any marketing page it would offer what the pages hold back (found by the launch review, §10.3). It
comes back with the plans, or once the plugin leaves the plans out of the website chat's catalogue.

**Enforced.** `verify-site.sh` fails if a marketing page offers or names a managed plan, if `prices.js` stops
holding `var SELL_PLANS = false;` or `chat.js` `var CHAT_ON = false;`, or if `shop.js` or `chat.js` names a plan.

**Restoring it** (when Starlink's position and the licence are confirmed) is a reviewed forward change, not a blind
revert, because the commits also carry fixes that must stay:

1. Set `SELL_PLANS = true` in `prices.js` (this also lets uCRM kit descriptions through again) and, when the chat's
   answers are acceptable, `CHAT_ON = true` in `chat.js`.
2. Put back the page sections: from `f659a3bc` everything except `become-a-distributor.html` (the internal planning
   preview stays removed); from `6be8b42e` only the hold-back edits (not the comment and unsourced-claim fixes);
   `45b8b23b` needs nothing more once `SELL_PLANS` is on.
3. Remove the hold-back rules from `verify-site.sh` (the managed-plan, `SELL_PLANS` and `CHAT_ON` checks) and the
   matching browser checks.
4. Settle §8.1 first; the managed-plan wording must match whatever Starlink and UCC allow.

### 10.2 Unverified claims removed

Five page groups were reviewed for statements nothing in the repository establishes; each proposal was checked by a
separate sceptic and then by hand (`25c77387`, 220 edits in 50 files):

- **WiFi zones** (the owner has not decided; the pages, links and enquiries stay). The platform is not deployed in
  Uganda (`dishnet-hybrid-sudan/docs/109`, `docs/123`), so the pages no longer say that Airtel Money billing,
  vouchers, a captive portal, per-tenant billing or host earnings exist, or that zones are available in marked towns.
  Zones are described as scoped in a consultation, subject to a site survey, Starlink's terms and any licence.
- **Reseller and distributor.** No "franchising", no "you own the customer, we run the billing engine", no promise
  that enquiries from a town go to its partner (it contradicted the distributor page); training and support are
  "agreed in writing after review"; the UCC licence sentence is kept.
- **Commerce and the app.** No "Genuine Starlink" marketing claim (the Terms of Sale keep their promise of genuine
  kits), no Airtel Money "free of charge", no "the total shown is the total invoiced", app features limited to those
  not in doubt, CCTV and firewall brands out of titles and meta, no maintenance-contract offer, no solar sizing
  figures.
- **Company and town pages.** Capability claims hedged; no cross-border "one contact" claim; no staff training; the
  three Masaka districts outside the 40-town list removed.
- **Tutorials.** "App screens shown with sample data" where mock screens appear; no Play Store option (the app is
  "coming soon"); no five-minute code validity; an internal file name removed from a comment.

Kept on purpose: the approved sentences, the 1–3 working days wording, every price, the contact details, and the
policies' contractual commitments.

### 10.3 Independent review, and the checks

**The launch review** (`ebc4eb92..25c77387`): five reviewers (scripts, content, policies and legal wording, internal
information, layout), each finding checked by two sceptics with different lenses (reproduce it; is it a defect under
the launch rules). 27 findings, **8 confirmed, all fixed** in `bd522250`; 6 split findings were fixed too because this
release had made the pages disagree:

| Finding | Fix |
|---|---|
| The website chat would recommend the monthly plans with prices on every marketing page | chat switched off with the plans (§10.1) |
| Two FAQ answers still described a DishNet-held account and a DishNet monthly plan | reworded, visible text and JSON-LD |
| On a phone the kit-only order stopped "Complete order on WhatsApp" under the floating WhatsApp button | scroll to the centre |
| The homepage kept kit and portal claims removed from the kits and contact pages | aligned |
| A kits-page comment carried an internal complaint history; `styles.css` comments a business price and an old kit price | reworded without reasons or figures |
| The homepage services grid had an empty cell on desktop | the last card fills the row |
| *Split, fixed:* "genuine" supply claims, "maintained" CCTV wording, the solar teaser, "Available in <town>", `fonts.css` notes, the services-page grid, the plan band at 761–1000 px | aligned or reworded |

Not changed, on the sceptics' verdict: installation's inclusion in the kit price (§10.4 item 1), Starlink plan names
in a uCRM kit description, the distributor wizard's test hook, and the Terms of Service first-month change (§10.6).

**The checks, final run on `bd522250`:**

| Check | Result |
|---|---|
| `./verify-site.sh` | **PASS**, including the new block: 35 marketing pages offer no managed plan, `SELL_PLANS` and the website chat are off, none of 9 removed claims and no "genuine" claim on a marketing page (each rule matched its own sample first; planted violations fail it, the same managed-plan sentence on a policy page passes) |
| `python3 verify-address.py` | exit 0, "The site agrees with itself." |
| `python3 policies/build.py --check` | exit 0 (the Terms of Service rebuilt in `6be8b42e`) |
| `bash stamp-assets.sh --check` | exit 0 |
| `node --check` on every script, and on every inline script of the changed pages | no syntax error; tags balanced on every changed page |
| Browser journeys (Playwright, Chromium) | **781 passed, 0 failed**: every page at five widths; the kits page with plans held back for feeds carrying managed plans, Flex, a "first month" description, no kit and the feed down; every marketing page free of the managed plan in text, head and JSON-LD, with a control that catches the pre-hold-back homepage and shop script; the website chat off with a control that shows it with the switch on; the chat, form, distributor and redirect journeys as before |
| Layout | measured at 390–1440 px: every grid row filled, no sideways scroll |

### 10.4 Pre-launch checks against the live systems — NOT DONE FROM THIS SESSION

This session cannot reach the live systems: requests to `dishnetuganda.com` and `crm.dishnetuganda.com` are refused by
the environment's network policy (HTTP 403 from the proxy). So these were **not** verified, and must be, before or
immediately after the merge:

1. **The price feed** (`crm.dishnetuganda.com/…/public.php?page=prices`): answers 200 with
   `access-control-allow-origin: https://dishnetuganda.com`; the kit names are ones the order flow recognises
   (*Starlink Mini Kit*, *Starlink Standard Kit*, or the older *… Package* names); **the kit prices include delivery
   and professional installation**, as the site says (uCRM also lists a separate *Professional Installation* product,
   §8.1 item 9).
2. **The shop feed** (`page=shop`) and the **chat** (`page=web_chat`) answer from the site's origin; the chat's
   WhatsApp hand-off opens the published number.
3. **The Uganda assistant** (website chat and WhatsApp) no longer offers managed DishNet plans, Flex or a first month
   while the site holds them back; otherwise the chat offers what the pages hide. This is the plugin's knowledge and
   prompt configuration, not a site change.
4. **The distributor intake** (`page=distributor_apply`) answers ok, so the wizard's "received" is true.

### 10.5 Release

1. **The release pull request** from `claude/uganda-site-consultancy` into `main` carries this branch: the website and
   this record, nothing else (no plugin change). It is reviewed as `main`'s pull requests are, including the automatic
   review.
2. **Before the merge:** the four checks in §10.4, by the owner or by a session that can reach the live systems.
3. **The merge** was meant to be the release, but EasyPanel's `web-uganda` app builds from `claude/study-this-jhe2eg`,
   not `main` (§10.7). Whether it rebuilds by itself on a push is not established in the repository; if the site does
   not change within a few minutes, redeploy the app in EasyPanel.
4. **After the deploy, on the live site:** the homepage, `starlink-kits.html` (kit prices from the feed, no plans, the
   order flow to a WhatsApp message), the phone menu, `contact.html`, a policy page, `sitemap.xml`, `/fiber.html` →
   301 `/services.html`, `/login` → 302 to the CRM; no script error; no managed-plan text. The site is reported live
   only after this.
5. **If the deploy fails or a critical defect appears:** §7 (revert the merge with a pull request, or redeploy the
   previous build in EasyPanel).

### 10.6 Decisions still open

- **The managed plan (§8.1):** Starlink's written position or an authorised-reseller agreement; the UCC licence
  (`docs/67` §E item 2: an Equipment Vending and Installation Licence is required before the services start — it
  covers what the site still sells, kits and installation, not only the managed plan); whether billing internet
  service needs a further licence.
- **The Uganda assistant** (WhatsApp and the website chat): stop offering the monthly plans while they are held
  back, or keep the website chat off (§10.4 item 3).
- **What a kit price includes** (§10.4 item 1).
- **The Terms of Service first-month clause** (`6be8b42e`): new buyers are no longer promised a first month; the
  quotation decides, so an existing customer whose package included one keeps it. Add it to the legal review (§8.2),
  with the question of notice.
- **The programmes the owner has not decided** (§8.4): WiFi zones, reseller, distributor and shop. Their pages now
  describe what DishNet would scope, not what runs today.
- The remaining items of §8.2 and §8.3.

### 10.7 The deploy branch (26 Oct 2026)

PR #26 was merged into `main` (`647faccf`) and the owner redeployed `web-uganda`, but the homepage did not change. The
cause: the app builds from **`claude/study-this-jhe2eg`**, as `docs/07` records, not from `main` as §2.1, §10.5 and
`README-DEPLOY.md` said; that branch did not contain the merge, so EasyPanel rebuilt the old site. At the owner's
instruction `main` was merged into `claude/study-this-jhe2eg` (`0de69559`), conflict-free. Main brought only
`dishnet-web-uganda/` and this record; the deployed tree (`site/`, `nginx.conf`, `Dockerfile`) is byte-identical to
`main`'s, and the branch's only other difference there is `redesign-demo/`, which the `Dockerfile` does not copy. On
the merged tree `verify-site.sh`, `verify-address.py`, `policies/build.py --check`, `stamp-assets.sh --check` and
`node --check` all passed (exit 0). Future website releases go to `claude/study-this-jhe2eg` as well as `main`, or the
app's source branch is changed to `main` in EasyPanel; the owner decides.

### 10.8 Subscription prices and the shop kept (26 Oct 2026)

**The owner's instruction**, after the launch was deployed: *"we need to keep shop and price for subscription so users
get an idea the way it was"*. The old site (`e01696a2`) had a Shop link in its menu and a plan-price grid filled live
from the billing system on its homepage. The new homepage had neither: its design carried two fixed "ask for today's
price" managed-plan cards, and the hold-back (§10.1) had removed those too and hidden the plans everywhere else.

**What changed, on `claude/study-this-jhe2eg`:**

1. **The hold-back is reversed.**
   - `f659a3bc` is reversed for the homepage, the kits page and `home.css`. The distributor page's internal planning
     preview stays removed.
   - Of `6be8b42e`, only the edits classified `hide-offer` or `first-month` are undone, plus the tutorial index's badge.
     The `internal-info` and `unverified-claim` edits stay.
   - The two FAQ answers that `bd522250` rewrote (moving a kit; refunds) are back to their original wording, in the
     visible text and the JSON-LD alike.
   - The Kampala intro gets back "or as a managed DishNet plan" only. Its WiFi-zone earnings phrase stays removed (§10.2).
   - `prices.js` has `SELL_PLANS = true`. With the switch on, it behaves as before the hold-back, except for comment
     changes, the removed claim "the total shown is the total invoiced", and a guard against a missing plan.
   - The Terms of Service clause on the first month is restored, and the Terms page is rebuilt.
   - Every claim removal (§10.2) and every launch-review fix (§10.3) is kept. A check found none of the 41 distinctive
     held-back texts left, and all 56 original passages back.
2. **The homepage shows the prices and links the shop.**
   - **Menus:** "Prices" (`#plans`) and "Shop" (`shop.html`) in the top menu; "Plans and prices", "Starlink kits" and
     "Shop" in the phone menu; "Shop" in the footer.
   - **Plan grid:** `prices.js` fills it from the price feed (same markup as the kits page). The two managed-plan cards
     inside it stay when the feed does not answer.
   - **Hero line:** "Managed DishNet home plans from UGX …/month", shown only when the feed has a home plan.
   - **Band below:** the own-name card and the availability box.
   - **Menu fit:** the two extra links did not fit between 1141 and 1169 px. So menu links are 2 px narrower on each
     side, and the phone menu now takes over at 1180 px instead of 1140 px (in `home.css` and `home.js`).
3. **`verify-site.sh`:** the hold-back rule becomes its opposite, which requires:
   - `SELL_PLANS` on;
   - the plans grid on the kits page and on the homepage;
   - `prices.js` loaded on the homepage;
   - Shop in the homepage menu.

   The website chat stays off (`CHAT_ON = false`) until the owner turns it back on. The nine removed-claim rules are
   unchanged. Seven planted defects each fail the check, and an unplanted copy passes.

**Checks on the working tree (exit 0 each):**
- `verify-site.sh` PASS;
- `verify-address.py`;
- `policies/build.py --check`;
- `stamp-assets.sh --check`;
- `node --check` on every script;
- the browser test, rewritten for the plans: **808 passed, 0 failed**, with a held-back `prices.js` as the control for
  every plans assertion. Of the 70 files changed, 48 differ only by an asset stamp.

**Still true, and the owner's decision:**
- The reason for §10.1 is unchanged. There is no written position from Starlink and no authorised-reseller agreement,
  and the UCC position is as `docs/67` §E records. The owner chose to show the plans regardless.
- The four live checks of §10.4 have still not been run from this session.
- `main` does not carry this change; the live app builds `claude/study-this-jhe2eg` (§10.7).

