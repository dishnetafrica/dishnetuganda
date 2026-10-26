# 70 — Uganda website: the consultancy-led implementation (26 Oct 2026)

**Status: BUILT AND TESTED on the development branch `claude/uganda-site-consultancy`, cut from `main` at
`e01696a2`. NOT MERGED, NOT DEPLOYED.** Production is untouched: nothing was merged into `main`, nothing was deployed
to EasyPanel, the live server was not contacted, and nothing was published. Releasing it needs the owner's separate
final approval (§2.3).

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

## 2. Implementation plan — in the existing structure and release process

### 2.1 How the site is released today (unchanged)

The site is a static nginx container (`nginx:1.27-alpine`; `Dockerfile` copies `nginx.conf` and `site/`). EasyPanel's
`web-uganda` app builds branch **`main`**, build path **`/dishnet-web-uganda`**. **A merge into `main` is therefore a
release**: EasyPanel rebuilds and serves it. There is no CI workflow; the checks in §6 are run by hand. This plan keeps
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
6. The checks extended so the decisions stay enforced (§6.1).

### 2.3 Release sequence — for when the owner approves (not done)

Each step needs the one before it. Steps 1–3 are decisions; nothing in them is technical.

1. **The owner reviews** this record, the branch and the diff, and answers or accepts the open points in §8. The items
   marked **before publishing** must be settled before step 4.
2. **The managed DishNet plan (§8.1).** Either the questions are settled, or the owner decides to release with the
   managed-plan wording held back. Holding it back is a small, separate change on this branch (the places are listed in
   §8.1); it is not built, because the owner approved the plan's wording.
3. **Outside the site (§5):** the uCRM and plugin items marked *before release*.
4. **Final checks on the branch**, the same commands as §6, with every result read.
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
| 2 | The managed DishNet plan is DishNet's own service; never implied to be approved by Starlink; kept apart from own-name registration | homepage *Ways to get Starlink*; kits page `#plans`; FAQ *What is a managed DishNet plan?* (the contract is with DishNet); About; the installation-day article |
| 3 | Restricted registrations: individual review, never an automatic offer or refusal, never a bypass | the FAQ no longer says "Or choose a managed DishNet plan instead" after "restricted"; it uses the homepage's wording, including "We will never help anyone bypass verification…" |
| 4 | Own-name registration: "Starlink controls registration approval, KYC verification and activation." No approval promised | FAQ (own-name, installation time, moving), Terms of Sale, Starlink Installation, town pages; "we activate your kit" became "we arrange the activation" |
| 5 | "Your first ICT consultation is free and carries no obligation." | homepage, services, about, contact, WhatsApp, FAQ, security, why DishNet, town pages, blog articles; every header button and phone menu now opens the free-consultation WhatsApp message |
| 6 | Connectivity: Starlink only | no fibre, LTE, "4G-linked", "Data Network" or "Internet" menu label ("Services" instead) |
| 7 | CCTV and network security: a separate service, not a Starlink product, not needing Starlink | security page, FAQ, why DishNet, town pages; a brand note: "Listing a brand does not mean a partnership with it." |
| 8 | Approved facts, word for word: "DishNet Africa has operated since 2013"; "DishNet serves more than 40 towns"; "WhatsApp support is available seven days a week" | "10+ years", "ten years", "two countries", "all four regions", "every day", "any time", "24/7", "answered every day", unconfirmed hours and on-call claims removed |
| 9 | Email `info@dishnetafrica.com` | unchanged everywhere; the FAQ's new consultation answer names it |
| 10 | Existing product photos only, never presented as DishNet installations | the High Performance card no longer shows the Standard kit's photo; a new social card (`og-dishnet-uganda.png`) shows the product, not an installation |
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

### 3.7 Two defects in the scripts, found by this review and fixed

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
fonts from Google — **it serves its own fonts; no page loads a font service** (checked across all pages). These pages
are still not legally reviewed (`docs/67`); the edits above should go to that review.

## 4. Files

### 4.1 Changed

All under `dishnet-web-uganda/`. Every page's `?v=` asset stamps were refreshed by `stamp-assets.sh`.

| Group | Files |
|---|---|
| Homepage | `site/index.html`, `site/home.css`, `site/assets/js/home.js` |
| Scripts and styles | `site/assets/js/chat.js`, `prices.js`, `shop.js`; `site/styles.css` |
| Main pages | `services.html`, `starlink-kits.html`, `coverage.html`, `about.html`, `contact.html`, `whatsapp.html`, `faq.html`, `security.html`, `why-dishnet.html`, `shop.html`, `careers.html`, `get-the-app.html`, `guides.html`, `pay.html`, `404.html` (all in `site/`) |
| Town pages (12) | `site/starlink-{arua,entebbe,fort-portal,gulu,hoima,jinja,kampala,lira,masaka,mbale,mbarara,soroti}.html` |
| Blog and programmes | `blog.html`, `blog-starlink-installation-day-uganda.html`, `blog-starlink-solar-uganda.html`, `blog-wifi-hotspot-business-uganda.html`, `hotspot.html`, `reseller.html`, `become-a-distributor.html` |
| Tutorials (19) | `site/tutorials/*.html` — the shared header and footer; `login-to-dishnet.html`, `grant-time-access.html` and `get-help-on-whatsapp.html` also in their text |
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
`site/assets/img/og-dishnet-uganda.png` (1200 × 630 social card), and this record.

### 4.4 Checks changed

`verify-site.sh` gains **withdrawn pages** (each redirect's status, target, headers and cache lifetime, the target
answering 200, the files gone) and **positioning rules** (no fibre, LTE, 24/7, "four regions", "every region",
"10+ years", "ten years", "two countries" or "thousands of" on any page, the sitemap or robots; each rule first proven
able to match a sample, so a broken rule cannot pass by matching nothing).

## 5. Database, application and integration changes

**The website needs no database change and no plugin release.** Everything below is outside this branch.

| System | What | When |
|---|---|---|
| Database | none — the site stores nothing. Old distributor applications may hold the label "Fibre & fixed connectivity"; they are historical staff records and stay | — |
| uCRM catalogue | confirm the live plan and product names (§8.1): the site's plan-to-Starlink-service map and its "Best value" badge use the older *DishNet Lite / Home / Business / Flex* names | before release |
| uCRM catalogue | product **descriptions** are printed verbatim on the kit cards and in the order flow; read them against the decisions (no fibre, no approval or activation promise, approved facts only) | before release |
| uCRM / plugin config | the price feed publishes every priced plan; if any **Flex** plan is priced, the site shows "UGX 0 upfront" and a Flex option. Either settle Flex (§8.1) or exclude it with `public_price_exclude` | before release |
| Plugin knowledge (the WhatsApp assistant and the website chat) | the live knowledge items should be read against the decisions: the seed file `dishnet-hybrid-sudan/tools/knowledge_seed.json` says "supports customers 24/7 on WhatsApp" (line 20) and "Starlink covers all of Uganda — every district, town and village in all four regions" (line 32). The live items are edited in the CRM, not here | before release |
| Plugin config | the website chat's teaser (`web_chat_teaser`) is set in the CRM; read it against the decisions | before release |
| Plugin (sales assistant) | the pre-filled WhatsApp openers change from "I'd like a quote …" to "I'd like to book my free ICT consultation …"; confirm nothing keys on the old openers | before release |
| Plugin (optional, separate release) | `DistributorApplicationService::SERVICES`: drop `fibre` (the site no longer offers it, but a hand-made request could still store it); relabel `data` to match the site ("Wi-Fi & managed networks"), with `tests/test_distributor_apply.php` | any time |
| Plugin | `profiles/uganda.json` `app_url` and the customer messages link `/app`; the site now answers it (302 → the app page). No plugin change | — |
| n8n assistant prompt | already says DishNet does not sell fibre; no change | — |
| Google Search Console | submit the sitemap; request removal of `/fiber.html` and the fibre article | after release |
| EasyPanel | none until the release; the release is the merge (§2.3) | — |

## 6. Tests and results

Run on the branch, in this container, against a local copy served by nginx with the repository's real `nginx.conf`
(never against the live site). Every request to another host (the price feed, the chat, the distributor endpoint,
WhatsApp) was answered by a mock or refused, so nothing reached the live plugin.

| Check | Result |
|---|---|
| `./verify-site.sh` | **PASS** (exit 0): 63 pages, 0 non-200; 76 references, 0 broken; real 404; `/login` 302; withdrawn pages 301 with headers and ten-minute expiry, `/app` 302; canonicals and sitemap; no price outside tutorial screenshots; positioning rules (self-checked) |
| `python3 verify-address.py` | exit 0: 91 JSON-LD blocks parse; one address, one phone, one 40-town list |
| `python3 policies/build.py --check` | exit 0: "policies: up to date" (the FAQ's structured data equals its visible questions) |
| `bash stamp-assets.sh --check` | exit 0 |
| `node --check` on every script in `assets/js/` | no syntax error |
| Browser journeys (Playwright, Chromium) | **740 passed, 0 failed** (exit 0): every page at 360, 390, 768, 1024 and 1440 px loads with no script error, no failed same-site request, no sideways scroll and no fibre / LTE / 24-7 / region claim; homepage wording, CCTV separation, menu, the WhatsApp button and chat launcher not overlapping; the consultation form refuses an empty form and a malformed e-mail, then prepares the WhatsApp message and claims nothing was sent; the kit guide; the kits page order flow with the recorded uCRM names (kits offered, location required, the WhatsApp order carries kit and location), with older names, business and Flex plans, and with the price feed down; the chat (stored, refused, network failure); the distributor wizard walked to a stored submission; the 404 page at a nested address; the header button and phone menu on every page; every redirect |
| Controls (the checks can fail) | the main-branch `prices.js` offers **no kit** in the order flow for the recorded names; the main-branch `chat.js` **thanks a visitor whose details were refused**; each positioning rule must match a sample before it scans |
| Layout | the distributor page's header was 811 px wide in a 768 px window, on `main` too; fixed (its menu now folds below 900 px, as everywhere else) and measured at 761–1024 px |

[The independent review and the final rerun are added when they finish.]

## 7. Rollback plan

Kept apart from the release steps on purpose: they are never to be run together.

**Before the release** there is nothing to roll back: `main` and the live site are unchanged.

**After the release** (the branch merged into `main`, EasyPanel rebuilt):

1. Revert the merge on `main` with a pull request (a `git revert -m 1` of the merge commit, reviewed like any change).
   EasyPanel rebuilds from `main` and serves the site as it was at `e01696a2`. Or, in EasyPanel, redeploy the build
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
7. **Flex packages** (kit included, monthly) depend on Starlink's leasing terms; the site prints "UGX 0 upfront" for
   any priced Flex plan (§5).
8. **Bring your own kit.** The order flow offers "I already have a kit"; can a kit bought elsewhere join a managed
   account? The Terms of Sale say imported kits might not be accepted for activation.
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

- The policy pages are still not legally reviewed (`docs/67`); add this release's edits (§3.9) to that review.
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
- The app: which features it will offer in Uganda, and whether everything in it is also in the customer portal.
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

- **WiFi zones / hotspot:** do Starlink's terms allow a connection to be shared with or resold to the public, and on
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
