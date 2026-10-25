# DishNet Uganda — ICT consultancy redesign: content change report

**25 Oct 2026 · Design demo for review — nothing here is published.**

This report explains `dishnet-uganda-ict-consultancy-demo.html`, the redesigned homepage in this folder.

**This folder is never deployed.** The website container copies only `dishnet-web-uganda/site/` (`Dockerfile`: `COPY site/`). The live site, its pages and its files were not changed.

## 1. What was inspected

- **The source.** The demo was built from the site in this repository, `dishnet-web-uganda/site/`. On 25 Oct that folder was identical to `main` (last change `6a10d5bd`, 16 Oct), the branch EasyPanel builds the live site from.
- **The live server could not be reached from this session.** If anything was changed on the server by hand, this inspection does not show it.
- **Pages read:**
  - `index.html`, `about.html`, `why-dishnet.html`, `services.html`, `starlink-kits.html`, `faq.html`, `contact.html`, `coverage.html`, `reseller.html`;
  - the policy pages' identity wording: `terms.html`, `policies.html`;
  - `blog-starlink-installation-day-uganda.html`.
- **Also read:**
  - `assets/js/prices.js`, the live price feed;
  - `styles.css`, for the brand tokens;
  - `README-DEPLOY.md`;
  - the repository's `docs/67`, the Uganda website policies review: Starlink status, UCC, risks.

## 2. Content change table

Existing wording is quoted from the files above. Proposed wording is the demo's.

| Section | Existing wording | Proposed wording | Reason |
|---|---|---|---|
| Page title | "DishNet Uganda — Starlink, Fiber, WiFi & Security Across Uganda" | "DishNet Uganda — ICT Consultancy & Starlink Acquisition Guidance" | Search results and browser tabs state the new role |
| Homepage headline | "Fast Internet. Installed. Supported. Locally." | "Your guide to Starlink and reliable connectivity in Uganda" | Leads with guidance, not supply |
| Hero eyebrow | "Uganda-wide connectivity & support" | "ICT consultancy · Starlink acquisition guidance" | Names the service in two words |
| Hero text | "Starlink, fiber, managed WiFi and security solutions for homes, businesses and organisations across Uganda. Genuine hardware, professional installation and local support from one team." | "Not sure which Starlink solution is right for you? DishNet provides ICT consultancy and practical guidance to help you understand your options, select suitable equipment, navigate the acquisition process and plan your connectivity deployment." (your brief's text) | Speaks to the customer's decision, not the product list |
| Primary CTA | "View Internet Plans"; in the header, "Get Connected" | "Book an ICT consultation"; in the header, "Book a consultation" | Generates qualified enquiries |
| Secondary CTA | "Chat on WhatsApp" | "Explore Starlink solutions" | WhatsApp stays, in the contact panel, the industry panels and the closing band |
| Hero ticks | "✓ Genuine hardware ✓ Professional installation ✓ Local support" | "Independent advice · Installation & Wi-Fi setup · Local support every day on WhatsApp" | Advice first; the hands-on work kept |
| Hero price | "Starlink home plans from" (live price from uCRM) | Removed. Each kit and plan has "Today's price" or "Ask for today's price" | A consultancy page leads with fit, not price. The house rule (prices only from uCRM) is kept |
| Facts strip | "40+ Towns served · 4 Uganda regions · 7 days Support on WhatsApp" | "Since 2013 · 40+ towns · Kampala office · 7 days Support on WhatsApp" | Adds experience and a local office; every fact is already on the live site (§4) |
| Solutions | "Internet for every need": Home, Business, Rural & Farm, WiFi Hotspot Business, CCTV & Security, Fiber | "Three ways we can help" (home · business · technical assistance), then "Solutions by industry" (8 customer groups) | Organises the site by customer, not by product |
| Why DishNet (homepage) | "More than a connection. We don't just deliver equipment. We help you choose the right solution, install it properly and stay available when you need support." | "Guidance you can act on, from people you can reach", with six reasons | The live line already holds the consultancy idea; the demo builds the page around it |
| How it works | "From enquiry to internet in four steps": Tell us your location · Choose your solution · Professional installation · Stay connected | "Five steps from enquiry to connection": your requirements · a consultation · a suitable solution, or an alternative · guidance on acquisition and deployment · installation, configuration and support where available. Plus the notice that Starlink decides and nothing is guaranteed | Adds the consultation and the acquisition guidance; promises nothing that is Starlink's to decide |
| About | "Connecting East Africa since 2013 — DishNet Africa Ltd started by connecting the hardest places first — and never stopped." · "…connected thousands of homes, businesses, NGOs, and government offices." · "We install Starlink anywhere in the country, run fiber in the major cities, help local entrepreneurs build WiFi businesses, and secure premises with professional CCTV and firewalls." | "A local ICT partner that helps you decide, then delivers": the independent-company sentence from the policy pages; South Sudan in 2013; the consultancy approach, ending "if Starlink is not the right fit, we say so"; the independence statement, word for word | Builds trust on verified facts. "Thousands" is dropped: no figure supports it |
| Services | services.html, "Built for how Uganda gets online": Starlink · Fiber · Pay-As-You-Go WiFi Zones · CCTV & Network Security · Business & Enterprise Connectivity | Ten consultancy-led services, each tagged **Advice** or **Hands-on**, with notes where Starlink decides. Then "What DishNet does" set beside "Decided by Starlink or the regulator" | Broadens the position, and separates guidance from what needs Starlink's or UCC's approval |
| Acquisition routes | terms.html: "You can buy Starlink in your own name. We advise you on the kit and plan, help you order and subscribe, and install and support it for you… If you choose a DishNet plan, our managed service…" | A new section, "Two ways to get Starlink with DishNet", in the Terms' own words | The most important consultancy fact, now on the homepage. See §4 on the managed plan |
| Starlink products | starlink-kits.html: "Choose your Starlink — Genuine Starlink hardware, delivered anywhere in Uganda and installed by trained DishNet technicians. Prices below are live from our billing system." | "Which Starlink might suit you? Three kits, each for a different kind of use…", plus a three-question kit guide ending "A suggestion only, not a recommendation" | Advice-led. Kit descriptions are the live wording, with these changes: |
| ↳ Mini | "Fits in a backpack, powers from USB-C or a solar bank. For field teams, lodges and anyone on the move." | Kept, with use cases and "Ask us whether it suits the number of people who will connect" | — |
| ↳ Standard | "The kit for homes, offices and WiFi zones. Our technicians mount it, align it and connect your whole compound." | "The kit for homes and offices. Our technicians mount it, align it and connect your whole compound." | "WiFi zones" removed: reselling access needs Starlink's permission and any licence UCC requires (§4) |
| ↳ High Performance | "Priority data, wider field of view and better performance in heat and storms — for banks, NGOs, ISPs and institutions that cannot afford downtime." | "For banks, NGOs, ISPs and institutions that cannot afford downtime." Quoted on request | The technical claims are dropped until checked against Starlink's published specifications |
| ↳ Kit package | "…activation on the right service plan…" | Not repeated. Installation is described as site check, delivery, mounting, alignment, cabling, Wi-Fi and testing | Activation is Starlink's decision |
| Plans | "Home internet — Unlimited data · professional installation available · DishNet local support." · "Business internet — Priority data with a public IP, for offices, CCTV and heavy users." | Same wording, plus an "Availability and approval" box | — |
| FAQ | Homepage: "Can DishNet install Starlink at my home?" · "How do I choose the right plan?" · "Do you provide support after installation?" · "Do you serve customers outside Kampala?" | Your eight questions, answered from the live FAQ and Terms, each qualified. The full live FAQ stays linked | Answers the acquisition, registration and restriction questions customers actually have |
| Contact | "Talk to a human — Quotes, coverage checks, support — WhatsApp is the fastest way…" | "Book an ICT consultation": a prototype form (§6), the WhatsApp card, call, email, office and hours, and existing customers' portal link | A qualified enquiry instead of an open chat |
| Other CTAs | "Ask for today's price", "Talk to a DishNet Expert on WhatsApp", "Get a Quote", "Get Free Advice" | "Book an ICT consultation", "Request an assessment", "Plan a school connection", "Plan guest Wi-Fi", "Connect a project site", "Confirm it with an adviser" | Each names the next step for that customer |
| Footer tagline | "Connecting and protecting Uganda — Starlink, fiber, managed WiFi zones, CCTV and network security with professional installation in every region." | "ICT consultancy, Starlink acquisition guidance, installation and support — across Uganda." | Matches the new position |
| Footer disclaimer | Policy pages only | Added to every page's footer, verbatim from the policy pages, plus "Starlink decides service availability, eligibility, account approval and activation." | The relationship is clear wherever a visitor lands |

## 3. Existing content not on the demo homepage — a decision is needed

These live pages are untouched and still reachable. The demo's main menu does not feature them:

- **Fiber** (`fiber.html`). The Uganda AI assistant's prompt in this repository (`n8n/DishNet_Uganda_AI_Bot_v1.0.json`) tells customers *"We do NOT sell fiber"*, while the site advertises fibre in eight towns. One of them is wrong. Settle it before either is published again.
- **Hotspot / WiFi-zone business** (`hotspot.html`) and the **reseller and distributor programmes**. Both involve reselling access. `docs/67` records that Starlink's terms reportedly forbid resale without its authorisation, and UCC licensing applies.
- **CCTV & security** (`security.html`). Not in your brief. Keep it as a separate service line, or drop it.
- **Shop**, the **app and tutorials**, **Pay a bill**, the **blog** and the **13 town pages**. They are linked from the demo's footer; the homepage does not repeat them.
- **The address-first order flow and the live price feed** (`prices.js`). The demo replaces "order now" with "book a consultation" and shows no prices. If you want both, the order flow could sit on the Starlink page.

## 4. Claims and information that need approval before publishing

| Item | Where in the demo | Source | What is needed |
|---|---|---|---|
| **Relationship with Starlink** | About, footer | Policy pages, verbatim | Keep as is. Never "authorised reseller", "official" or "partner" unless Starlink confirms it in writing |
| **The managed DishNet plan** (the account registered and managed by DishNet) | "Two ways…", the FAQ | terms.html | `docs/67` §E.1 says Starlink's position on accounts held for customers is not established, and suspended accounts would land on DishNet. It recommends advising customers to order in their own name as the low-risk model, which this redesign already leads with. Decide whether to promote the managed plan before Starlink's written position |
| **"We never register a service in someone else's name to get around Starlink's checks"** | FAQ: restricted accounts | New | Confirm this is company policy. It also means **not** offering a DishNet plan to someone whose own registration Starlink restricted |
| **Registration and verification guidance** | Services, FAQ | New | The demo lists no documents or steps. Confirm exactly what DishNet does today, both for own-name orders and for DishNet plans (the app's KYC form) |
| **UCC** | Services, industry notes | `docs/67` §E.2 | No licence is claimed: the UCC letter of 4 Sep is a conditional no-objection. When the Equipment Vending and Installation Licence is granted, add its number |
| **Since 2013 / 10+ years / 2 countries** | Facts strip, About | about.html | Confirm. 2013 is DishNet Africa's start in South Sudan |
| **40+ towns, all four regions** | Facts, About, Coverage | coverage.html, about.html | Confirm the count |
| **WhatsApp support every day / 7 days** | Hero, facts, Why, contact | contact.html, FAQ | Confirm. The automated assistant replies first, and customers can ask for a person |
| **Installation times** (2–4 hours; 1–3 working days in cities; about a week upcountry) | FAQ | faq.html | Confirm they still hold |
| **Warranty and 7-day returns** | Why DishNet | Policy pages | The policy pages are not yet legally approved (`docs/67`) |
| **"Unlimited data"** (home plans) | Plans | index.html | `docs/67` §E.6: Starlink plans are network-managed. Keep or qualify |
| **Kit descriptions** (Mini's USB-C or solar-bank power; High Performance's use cases) | Kits | starlink-kits.html | Check against Starlink's current published specifications |
| **Kit photos** | Hero, kits | `site/assets/img/products/` | The live site's own files. Confirm you may use them; a real DishNet installation photo would be better (none exists in the repository) |
| **Is the consultation free?** | How it works, contact | The live site says "Get Free Advice", "no obligation" | Decide, then say it in step 2 (the file has an EDIT note there) |
| **Alternatives to Starlink** | Step 3, FAQ | New | Which alternatives DishNet itself supplies, and which it only advises on (see fibre, §3) |
| **On-site consultations for larger projects** | Step 2 | New | Confirm |
| **Payment: Airtel Money, bank transfer or cash** | Two ways, Why | index.html, pay.html | Confirm |
| **Contact details** | Contact, footer, structured data | contact.html | The README says to confirm that `info@dishnetafrica.com` is monitored for Uganda enquiries |
| **Social links** | — | None on the live site | None added. Send profile links if they should appear |
| **The enquiry form** | Contact | Prototype | Needs a real destination: for example a WhatsApp hand-off, as the prototype does, or the plugin's lead capture. The Privacy Policy must cover what the form collects |

## 5. Outstanding questions

1. Does DishNet sell fibre in Uganda today? The website and the Uganda AI assistant's prompt disagree (§3).
2. Should the managed DishNet plan be promoted before Starlink's written position on accounts held for customers is known?
3. Will DishNet refuse a managed plan to a customer whose own Starlink registration was restricted? The demo's FAQ commits to that.
4. What exactly does DishNet do for registration and verification, for own-name orders and for DishNet plans?
5. Is the first consultation free? Are consultancy services ever charged separately?
6. Which alternatives to Starlink does DishNet supply itself, and which does it only advise on?
7. Do the CCTV/security and hotspot lines stay on the new site, and where?
8. Are the facts still right: since 2013, 40+ towns, every-day WhatsApp support, the installation times?
9. Is `info@dishnetafrica.com` the right address for Uganda consultations?
10. Are real installation photos, approved for publishing, available?
11. Where should consultation requests go once the form is live?

## 6. Opening and editing the demo

- **Open it.** Double-click `dishnet-uganda-ict-consultancy-demo.html`, or drag it into Chrome, Edge, Safari or Firefox. It needs no internet connection, no build step and no server. Fonts and kit photos are inside the file.
- **What works.**
  - The menu, including the phone menu.
  - The industry tabs: click them, or use the arrow keys.
  - The FAQ answers open and close.
  - The kit guide.
  - The form checks itself. It **sends nothing**: it shows the enquiry and offers to open it in WhatsApp for you to send.
  - All WhatsApp, phone and live-site links are real.
- **Edit wording in the browser.**
  1. Press **Edit wording** in the bottom-left corner.
  2. Click any text and type.
  3. Press **Save a copy**. It downloads `dishnet-uganda-ict-consultancy-demo-edited.html`, which you can send back.
- **Edit in a text editor.**
  - Search for `EDIT:` to jump to each block of wording. Sections are labelled **A–J** as in your brief.
  - The WhatsApp number every enquiry goes to is `var WA` in the script near the end.
  - Pre-filled WhatsApp messages are in the links themselves, after `?text=`, written with `%20` for each space.
  - The kit guide's suggestions are in the function `kitGuide` in the same script.
  - To use another photo, give its `<img>` a `src` and remove its `data-embed`.
  - Leave the long `embedded-fonts` block and the `EMBED` line alone: they are the fonts and photos.
- **Before publishing**, remove:
  - the demo strip at the top;
  - the review tool;
  - the `noindex` line;
  - the prototype form's notice, once the form has a real destination.

## 7. How the new positioning differs

**The live site is a product catalogue.** It sells Starlink, fibre, WiFi zones and CCTV, with installation and support. It leads with plans, prices and "Get connected".

**The demo is an adviser.** It leads with the customer's decision: which solution, which kit, how to acquire it, what registration involves, how it will be installed and supported. It is organised around customer types and three journeys: home, business, technical help. It states plainly what DishNet does and what only Starlink or the regulator decides.

Starlink stays central, and the leads still arrive on WhatsApp. They now arrive as consultation requests carrying a location, a customer type and the requirements. The approach matches the low-risk model `docs/67` recommends: advise, help the customer order in their own name, then install and support.

## 8. How the demo was checked

- **In headless Chromium, at 1440, 820 and 390 px wide: 59 checks passed, 0 failed.** They were run inside the repository and with the file opened alone. They covered:
  - no console error, no request leaving the file, no horizontal scrolling;
  - every image and font loaded;
  - the menu opens and closes, including with Escape;
  - the tabs work by click and arrow key, and the FAQ answers open;
  - the kit guide's three outcomes;
  - the form refuses empty and invalid entries, and its WhatsApp link carries the enquiry;
  - Edit and Save keep the edits with no edit state left in the saved copy;
  - with JavaScript off, all eight industry panels show.
- **The checks found two defects in the demo itself, both fixed:**
  - saving while editing was blocked;
  - opened outside the repository, the file first logged "file not found" errors before using its embedded copies.
- **The layout was checked by eye, section by section**, at desktop and phone widths, and corrected:
  - the header labels wrapped;
  - the kit photos showed as white boxes;
  - the steps list was indented;
  - the floating cards overlapped on phones.
- **A wording scan of the visible text** found no price, speed, merchant ID, "authorised" or "official" claim, and no guarantee except "not guaranteed".
