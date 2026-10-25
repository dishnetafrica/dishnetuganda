# DishNet Uganda — ICT consultancy redesign: content change report

**Revision 2 · 25 Oct 2026 · Approved for demo revisions only — NOT approved for production deployment.**

This report explains `dishnet-uganda-ict-consultancy-demo.html`, the redesigned homepage in this folder. Revision 2 applies the eleven decisions of the *Final Approval Instructions*.

**Nothing was published, deployed, uploaded or merged.**

- This folder is never deployed. The website container copies only `dishnet-web-uganda/site/` (`Dockerfile`: `COPY site/`), and EasyPanel builds from `main`.
- No file in `site/`, no policy file, no branch other than the development branch, and nothing on the server was touched.
- The demo returns to you for final review before any implementation is considered.

## 1. The eleven decisions, and what changed

| # | Decision | What the demo now does | Where |
|---|---|---|---|
| 1 | **No fibre sales in Uganda** | **The word fibre appears nowhere on the page.** Removed:<br>• the "no fibre on your street / far from fibre / beyond the fibre map" lines;<br>• the remote-site service.<br>The demo no longer links to the live Services, Coverage and FAQ pages, which advertise fibre (§3). **No historical fibre sentence was added:** the only source, `about.html` (*"built fiber networks in major cities"*), does not say where or when (§3.3). | Industries, Services, Coverage, FAQ, footer |
| 2 | **Keep and promote the managed DishNet Starlink plan; distinguish it from own-name ordering; never imply Starlink approved it** | **A service card**, tagged *DishNet service*.<br>**"Two ways to get Starlink with DishNet"** sets the two routes side by side, row for row: contract, registration, approval, bill or subscription, what DishNet provides. The managed card is highlighted.<br>**The services card, the comparison, the FAQ and the footer each say it is a DishNet service, not a Starlink product.** The comparison's wording: *"A DishNet plan is a DishNet service, provided under DishNet's own terms. It is not a Starlink product."*<br>The plans are labelled **Managed DishNet plan** or **Own name**.<br>**No sentence** says or suggests that Starlink approved, endorsed or authorised it. | Services, Two ways, Plans, FAQ, footer |
| 3 | **Restricted registrations: individual review; never an automatic offer or refusal; never a bypass** | **A box under the comparison and a FAQ answer**, both saying:<br>*"We do not automatically offer — or refuse — a managed DishNet plan. We review each case individually, subject to Starlink's terms, requirements and approvals."*<br>And: ***"We will never help anyone bypass verification, registration restrictions or eligibility checks."***<br>Revision 1's *"We never register a service in someone else's name…"* is gone. | Two ways, FAQ |
| 4 | **Own-name registration: DishNet helps; Starlink controls approval, KYC and activation; no approval promised** | **A service card**, *"Own-name registration and verification guidance"*.<br>**The sentence *"Starlink controls registration approval, KYC verification and activation"*** appears in the services card, the comparison and two FAQ answers. How it works, Plans and the footer name the same three decisions.<br>**Also:** *"We cannot promise approval"* and *"No approval is promised"*.<br>The *"Decided by Starlink"* panel ends: *"DishNet cannot override, bypass or speed up any of these decisions."* | Services, Two ways, How it works, Plans, FAQ, footer |
| 5 | **The first ICT consultation is free and carries no obligation — one wording everywhere** | **The exact sentence *"Your first ICT consultation is free and carries no obligation"*** appears in:<br>• the hero;<br>• the facts strip;<br>• the consultancy card;<br>• step 2;<br>• the "Why" cards;<br>• the FAQ;<br>• the contact heading;<br>• the closing call to action.<br>**Buttons read *"Book a free consultation"*.** The header button reads *"Free consultation"*. | Throughout |
| 6 | **Starlink only as connectivity; no fibre or alternatives; other genuine ICT services may stay** | **Removed:**<br>• step 3's *"or an alternative"*;<br>• the FAQ *"Can DishNet recommend alternatives…"*;<br>• *"backup links"* and *"backup-link planning"*;<br>• *"other ways to connect"*;<br>• the multi-site "backup links" service.<br>**Kept:** Wi-Fi setup, as part of a Starlink installation, and CCTV and network security, as a separate service (decision 7). | Services, How it works, Industries, Why, kit guide |
| 7 | **CCTV and network security: separate, not Starlink, not dependent on Starlink** | **A new section, *"CCTV and network security"***, between Industries and Why. It is labelled *"A separate DishNet service"* and has its own call to action. Its box says: ***"Not a Starlink product… you do not need Starlink to use them."***<br>It contains four cards, the brand lists, and a four-step process.<br>**Also:** a dashed, separate card at the end of Services; a line under the three journeys; a FAQ answer (*"No"*); its own option in the form. **Security** is in the menu. | New section `#security`, Services, FAQ, form |
| 8 | **Approved facts:** operating since 2013; more than 40 towns; WhatsApp support seven days a week | **Each fact appears word for word:**<br>• *"DishNet Africa has operated since 2013"*;<br>• *"DishNet serves more than 40 towns"*;<br>• *"WhatsApp support is available seven days a week"*.<br>**Removed:**<br>• *"10+ years"*;<br>• *"2 countries"*;<br>• *"all four regions"*;<br>• *"every day"*;<br>• *"answers every day"*. | Facts strip, About, Coverage, contact, Why, services |
| 9 | **Consultation email: `info@dishnetafrica.com`** | **Every email link is `info@dishnetafrica.com`** (5 links, checked). The hero, the FAQ and the contact card open a message with the subject *"Free ICT consultation"*. | Hero, FAQ, contact, form result, footer |
| 10 | **Existing photos, not presented as DishNet installations, easy to replace** | **The photos:** the two kit photos are the live site's own files, each labelled ***"Product photo"*** on the image.<br>**High Performance:** revision 1 showed the Standard photo there. It now shows a drawn panel marked *"Photo to follow"*, because no High Performance photo exists.<br>**Replacing a photo:** each `<img>` names its file in `data-file`, beside an `IMAGE:` comment that says how to replace it (§6). | Hero, kits |
| 11 | **WhatsApp first; the form prepares a WhatsApp message; no false success** | **WhatsApp is the main route:**<br>• the hero's main button opens WhatsApp;<br>• a WhatsApp button stays in the corner of every screen;<br>• the contact card is titled *"WhatsApp — the main route"*.<br>**The form:**<br>• its button reads ***"Prepare my WhatsApp message"***;<br>• the result reads ***"Your message is ready — nothing has been sent yet"***, followed by *"DishNet receives your enquiry only when you send it"* and the button *"Open WhatsApp to send"*;<br>• the result box is amber, not green;<br>• the note says the page *"sends nothing and stores nothing"*. | Hero, corner button, contact |

**Also kept as approved:**

- **The headline**, *"Your guide to Starlink and reliable connectivity in Uganda"* — the same words in the site's sentence case. One edit if you prefer title case.
- The lead with ICT consultancy and Starlink acquisition guidance.
- The red, white and black design.

## 2. Content change table (against the live site)

Existing wording is quoted from `dishnet-web-uganda/site/`. Proposed wording is revision 2's.

| Section | Existing wording | Proposed wording | Reason |
|---|---|---|---|
| Page title | "DishNet Uganda — Starlink, Fiber, WiFi & Security Across Uganda" | "DishNet Uganda — ICT Consultancy & Starlink Acquisition Guidance" | The new role; no fibre (1) |
| Meta description | "…Starlink internet, fiber, managed WiFi zones, CCTV…" | "…choosing, acquiring, installing and supporting Starlink across Uganda. Your first ICT consultation is free and carries no obligation." | 1, 5, 6 |
| Social preview image | `og-uganda.png` ("24/7 local support") | Left out on purpose (comment in the file) | It contradicts decision 8 (§3.4) |
| Headline | "Fast Internet. Installed. Supported. Locally." | "Your guide to Starlink and reliable connectivity in Uganda" | Approved |
| Hero text | "Starlink, fiber, managed WiFi and security solutions…" | "Not sure which Starlink solution is right for you? DishNet provides ICT consultancy and practical guidance…" | Advice first; no fibre |
| Hero buttons | "View Internet Plans" · "Chat on WhatsApp" | "Book a free consultation on WhatsApp" · "Explore Starlink solutions" | 5, 11 |
| Under the buttons | — | "**Your first ICT consultation is free and carries no obligation.** Prefer to write? Use the enquiry form or email info@dishnetafrica.com." | 5, 9 |
| Hero ticks | "Genuine hardware · Professional installation · Local support" | "Independent advice · Installation & Wi-Fi setup · WhatsApp support seven days a week" | 8 |
| Facts strip | "40+ Towns served · 4 Uganda regions · 7 days Support on WhatsApp" | **Free** (your first ICT consultation…) · **Since 2013** (DishNet Africa has operated since 2013) · **40+ towns** (DishNet serves more than 40 towns) · **7 days a week** (WhatsApp support is available seven days a week) | 5, 8 |
| Journeys | — | Home · organisation · technical assistance, plus "Looking for CCTV cameras or network security? That is a separate DishNet service, and you do not need Starlink for it." | 6, 7 |
| About | "…run fiber in the major cities, help local entrepreneurs build WiFi businesses…" · facts "10+ years · 2 countries · 40+ towns" | The independent-company sentence; "DishNet Africa has operated since 2013: it began in South Sudan…"; "DishNet serves more than 40 towns"; facts 2013 · 40+ · 7 days · Kampala | 1, 8 |
| Services | services.html: Starlink · Fiber · Pay-As-You-Go WiFi Zones · CCTV & Network Security · Business & Enterprise | **Six Starlink services:**<br>• consultancy focused on Starlink;<br>• equipment and solution selection;<br>• own-name registration and verification guidance;<br>• managed DishNet Starlink plans;<br>• installation planning and coordination;<br>• technical support.<br>**Then CCTV and network security as a separate card.** | 1, 2, 4, 6, 7 |
| What DishNet does / Starlink decides | — | Adds managed plans "subject to applicable requirements"; "Eligibility, and any restriction on a registration"; "DishNet cannot override, bypass or speed up any of these decisions." | 2, 3, 4 |
| Two ways | terms.html §3 | Two cards with the same five rows; the managed plan highlighted and stated to be a DishNet service; the restricted-registration box | 2, 3, 4 |
| How it works | four steps | five steps; step 2 is the free consultation; step 3 is "a suitable Starlink solution… in your own name with our guidance, or as a managed DishNet plan" | 5, 6 |
| Starlink | "Choose your Starlink… Prices below are live from our billing system." | **"The equipment"** (a package, or kit-only on request — the live kits page's own offer) and **"The service"** (own name or managed plan), then the kits and the kit guide | 2 |
| ↳ Standard | "The kit for homes, offices and WiFi zones…" | "The kit for homes and offices…" | WiFi zones involve resale (§4) |
| ↳ High Performance | the Standard photo; "Priority data, wider field of view…" | a drawn panel, "Photo to follow"; "For banks, NGOs, ISPs and institutions that cannot afford downtime." | 10 |
| Plans | "Home internet — Unlimited data…" · "Business internet — Priority data with a public IP, for offices, CCTV and heavy users." | **"DishNet Home plans"** and **"DishNet Business plans"**, each tagged *Managed DishNet plan*. **"Your own Starlink subscription"**, tagged *Own name*.<br>"CCTV" became **"remote access"**, so no plan suggests that security needs Starlink. | 2, 7 |
| Prices | live from uCRM (`prices.js`) | **None.** Each kit and plan has "Today's price" / "Ask for today's price" on WhatsApp | The approved sources are the live site's feed from DishNet's billing system; a self-contained file cannot read it, so no price is copied or invented |
| CCTV | security.html, its own page | A homepage section from security.html's own wording, without *"bank-grade"*, *"price it the same day"*, *"never quietly dies"* and *"4G-linked"* | 6, 7 |
| Coverage | twelve towns linked to coverage.html | the same twelve towns, not linked; one button: "Ask about your town" (WhatsApp) | 1, 11 |
| FAQ | homepage: 4 questions; live FAQ linked | **Nine questions:**<br>1. How can DishNet help me acquire Starlink?<br>2. Can I register Starlink in my own name?<br>3. What is a managed DishNet Starlink plan?<br>4. What if my own-name registration is restricted?<br>5. Which kit suits my requirements?<br>6. Is Starlink available at my location?<br>7. How do I request installation or technical support?<br>8. How do I arrange my free consultation?<br>9. Do I need Starlink for DishNet's CCTV or network security?<br>**The live FAQ link** is replaced by "Ask us on WhatsApp". | All six required topics, plus 7 |
| Contact | "Talk to a human…" | "Book your free ICT consultation", the WhatsApp-first form, the WhatsApp card, email, phone, office, hours, the customer portal | 5, 9, 11 |
| Closing call to action | — | "Not sure where to start? Start with a free consultation." · "Book on WhatsApp" · "Use the enquiry form" | 5, 11 |
| Footer | "Connecting and protecting Uganda — Starlink, fiber, managed WiFi zones…" | "ICT consultancy focused on Starlink, acquisition guidance, installation and support across Uganda — and, separately, CCTV and network security." | 1, 7 |
| Footer disclaimer | policy pages only | the independence sentence, plus "Starlink controls service availability, eligibility, registration approval, KYC verification and activation. Managed DishNet plans are DishNet's own service, not Starlink products." | 2, 4 |

## 3. The live site still contradicts these decisions

The demo cannot fix the live site, and these instructions do not authorise it. **Before any part of this design goes live, a separate, approved production change must deal with the following.**

### 3.1 Fibre on every live page

- **All 65 pages under `site/` mention fibre:** 46 top-level pages and the 19 tutorials. On most pages this comes from the menu, the footer tagline and the meta description, about six mentions per page.
- **The heaviest pages:**

  | Page | Mentions of fibre |
  |---|---|
  | `coverage.html` | 25 |
  | `fiber.html` | 21 |
  | `faq.html` | 20 |
  | `index.html` | 19 |
  | `services.html` | 19 |
  | `about.html` | 11 |
  | `contact.html` | 9 |

  Also `blog-starlink-vs-fiber.html` and the 13 town pages.
- **`fiber.html` itself.** Decision 1 means taking it down, or redirecting it.
- **The terms bodies, `policies/bodies/`: 2 of 9 files.**
  - `terms.html` §3: *"monthly plans that run on the Starlink satellite network, fiber internet…"* and *"It also needs the Starlink network or our fiber network to be available."*
  - `acceptable-use.html`: *"Fiber plans have no data cap…"* and *"Starlink-based plans, fiber, and networks we manage."*
  - These are legal texts. Change them through their own review, not as a wording tidy-up.
- **The Uganda AI assistant's prompt** (`n8n/DishNet_Uganda_AI_Bot_v1.0.json`) already says *"We do NOT sell fiber"*, which now agrees with decision 1.
- **The portal terms** in the plugin (`LegalContent`, `InstallationTerms`) were checked and do not mention fibre.

### 3.2 Live pages the demo still links to

**Every one of these pages carries the fibre menu and footer** until §3.1 is done. The demo still links to the policy and support pages, because a visitor needs them:

- **Policy pages:** Terms of Service, Terms of Sale, Privacy, Warranty, Returns, Complaints, Policies;
- **Support pages:** Pay, Login, Get the app, Guides;
- **Other pages:** Starlink kits, About, Why DishNet, Reseller, Become a distributor, Blog, Careers.

**No longer linked:** Services, Coverage and FAQ, the pages that advertise fibre most.

### 3.3 Historical fibre

**Decision 1 allows accurate historical references.** The only source is `about.html`, which says DishNet Africa *"built fiber networks in major cities"*, without saying which country or when. **The demo therefore says nothing about fibre.**

If management confirms the facts, About could add one sentence, for example: *"In South Sudan, DishNet Africa has also built fibre networks; DishNet Uganda does not currently sell fibre."* Use it only with the place and period confirmed.

### 3.4 The social preview image

`site/assets/img/og-uganda.png` says ***"24/7 local support"***. That is stronger than the approved *"seven days a week"*. The demo leaves the image out. Replace the card before it is used again.

## 4. Remaining compliance questions and items to confirm

| # | Item | Where | Why it needs confirming |
|---|---|---|---|
| C1 | **The managed DishNet plan, against Starlink's terms** | Services, Two ways, Plans, FAQ | `docs/67` §E.1: Starlink's position on accounts registered and managed for customers is **not established**; a suspended account would fall on DishNet, and resale needs Starlink's authorisation. **Decision 2 says promote it.** The demo describes it only as DishNet's own service, subject to applicable requirements including Starlink's terms. **Get legal or contractual confirmation before it goes live.** |
| C2 | **"Subject to applicable requirements… Confirmed with you before you commit."** | Two ways | Write down what the requirements are: the checks DishNet runs for a managed plan (the app's KYC form?) and who approves. |
| C3 | **Individual review of restricted registrations** | Two ways, FAQ | Who reviews, against what written criteria, and how the decision is recorded. The demo promises an individual review, with no automatic offer and no automatic refusal. |
| C4 | **UCC** | Services, Industries | No licence is claimed. `docs/67` §E.2: the UCC letter of 4 Sep is a conditional no-objection. Add the licence number once the Equipment Vending and Installation Licence is granted. |
| C5 | **Own-name subscriptions** | Two ways, Plans | The demo says only "under Starlink's own terms, plans and prices". Confirm how an own-name customer pays Starlink, and whether DishNet's installation and support are always quoted separately. |
| C6 | **What a managed plan includes** | Two ways, FAQ | "Installation, warranty handling and support from one team" and "one local bill, payable by Airtel Money, bank transfer or cash" are taken from the live site and terms. Confirm both still hold. |
| C7 | **"Unlimited data"** (DishNet Home plans) | Plans | `docs/67` §E.6: Starlink manages its network. Keep the phrase, or qualify it. |
| C8 | **Flex and Lite plans** | Plans | Not shown, as in revision 1. Add them only once management confirms them. |
| C9 | **"Kit-only on request"** | Starlink | From the live kits page (*"Prefer kit-only, or self-install? … we quote that too"*). Confirm. |
| C10 | **CCTV brands** | CCTV section | The lists are security.html's own. Confirm DishNet supplies each brand. The demo adds *"Listing a brand does not mean a partnership with it"*; no authorised-dealer status is claimed. |
| C11 | **CCTV maintenance contracts and remote viewing** | CCTV section | From security.html; confirm. Remote viewing needs whatever internet connection the site already has; the demo does not say Starlink provides it. |
| C12 | **The partner, reseller and distributor programmes** | Industries tab "ISPs, partners & resellers", the form's options, footer links | **They involve resale.** Starlink's authorisation and UCC licensing apply (`docs/67`). **The decisions do not mention them.** Keep, reword or remove. |
| C13 | **Installation times:** 1–3 working days in cities; about a week upcountry; 2–4 hours on site | FAQ | From the live FAQ; confirm. |
| C14 | **"We check your address before you pay"** | How it works, Plans, FAQ | From the live site; confirm it is still the practice. |
| C15 | **Warranty and 7-day returns** | Why | The policy pages are not yet legally approved (`docs/67`). |
| C16 | **Kit descriptions** (Mini: USB-C or a solar bank) | Kits | Check against Starlink's current published specifications. |
| C17 | **Photo rights** | Hero, kits | The product photos are the live site's files. Confirm DishNet may use them. No DishNet installation photo exists in the repository. |
| C18 | **The form, when it goes live** | Contact | It is a prototype that prepares a WhatsApp message. Keep that design or give the form a real destination, and check that the Privacy Policy covers what reaches DishNet. |
| C19 | **Office hours:** Mon–Sat, 8:30am–6:00pm EAT | Contact | From contact.html; confirm. WhatsApp support is seven days a week, the office six. |
| C20 | **The trademark sentence** | About, footer | Kept word for word from the policy pages. Never write "authorised reseller", "official partner", "representative" or "exclusive" unless Starlink confirms it in writing. The demo's visible text contains none of these (checked, §5). |

## 5. What was checked

**Headless Chromium, at five widths:** 1440, 1141 (the narrowest desktop width before the menu folds), 820, 390 and 360 px. The file was run both inside the repository and opened alone. **103 checks passed, 0 failed.** The run exited 0.

**Pages and layout:**

- no console error;
- no request leaving the file;
- no horizontal scrolling at any width;
- every photo and both fonts load from inside the file;
- the header fits on one line at 1440 and at 1141;
- on phones, the WhatsApp button and the review tool do not overlap.

**Navigation:**

- the menu opens, closes with Escape, and closes when a link is followed;
- the industry tabs work by click, by arrow key and with End;
- with JavaScript off, all 8 industry panels show.

**FAQ:**

- an answer opens and closes;
- there are nine questions, covering the six topics you required.

**Kit guide:** it returns Standard, Mini or High Performance as the answers change, and notes solar power.

**WhatsApp enquiry flow:**

- an empty form marks its 6 required fields;
- a bad email address is refused;
- a valid enquiry shows the prepared message as *"Your message is ready — nothing has been sent yet"*, with *"Open WhatsApp to send"*;
- the result contains no "received", "submitted", "thank you" or "has been sent";
- the WhatsApp link carries the enquiry, starting *"Hi DishNet, I would like to book my free ICT consultation."*

**Editing:**

- *Edit wording* makes the text editable;
- *Save a copy* downloads the page with the edits and no edit marks;
- turning editing off removes every mark.

**The eleven decisions, read from the visible text:**

- no fibre;
- no alternatives or backup links;
- no link to the live Services, Coverage or FAQ pages;
- the free-consultation sentence in the hero, the contact section and the closing band;
- the three approved facts, word for word;
- none of *"every day"*, *"10+ years"*, *"2 countries"*, *"four regions"* or *"24/7"*;
- every email link is info@dishnetafrica.com;
- the separate CCTV section, saying it is not a Starlink product;
- the managed plan called a DishNet service, with no approval, endorsement, authorised, official or exclusive claim;
- the restricted-registration and no-bypass wording;
- *"Starlink controls registration approval, KYC verification and activation"*;
- the corner WhatsApp button.

**Controls that prove the checks can fail:**

- **Against revision 1:** the same test fails 15 checks — every decision check except decision 9's (revision 1 already used info@dishnetafrica.com), the FAQ count and the three form-wording checks. It then stops, because revision 1 has no WhatsApp corner button to measure.
- **The 360 px check:** it was added after the screenshots, and it failed against the build before the fix (page 373 px wide).

**Found and fixed while checking:**

- On a 360 px phone, two long buttons ran off the screen: *"Book a free consultation on WhatsApp"* and *"Prepare my WhatsApp message"*. Button labels now wrap on small screens.
- One CCTV line the first draft wrote (*"agreed with you in writing"*) was not in the live source. It is now security.html's *"priority call-out"*.

**Looked at by eye:** every section, at 1440 and at 390 px.

**A scan of the visible text** found:

- the word *"guarantee"* only in *"none of them is guaranteed"*;
- the word *"partner"* only in:
  - *"A local ICT partner"* (DishNet itself);
  - the independence sentence;
  - the brand note;
  - the partner-programme items listed in C12.

## 6. Reviewing it locally

**Open it.**

1. Download or open `dishnet-web-uganda/redesign-demo/dishnet-uganda-ict-consultancy-demo.html`.
2. Double-click it, or drag it into Chrome, Edge, Safari or Firefox.

It needs no internet connection, no server and no build step: the fonts and photos are inside the file. The WhatsApp, phone, email and live-site links open the real destinations.

**Try it on a phone.** Send the file to yourself and open it in the phone's browser, or narrow the desktop browser window. The menu folds below 1140 px.

**Suggested route through it:**

1. The hero and the free-consultation line.
2. *Services*, then the dashed CCTV card.
3. *Two ways to get Starlink with DishNet*, and the restricted-registration box.
4. *Starlink*: the kits, the kit guide and the plans.
5. *Security*.
6. *FAQ*.
7. *Contact*: fill the form and press *Prepare my WhatsApp message*. Nothing is sent unless you then press *Open WhatsApp to send* **and** send it in WhatsApp.

**Change wording in the browser.**

1. Press **Edit wording** in the bottom-left corner.
2. Click any text and type.
3. Press **Save a copy**. It downloads `dishnet-uganda-ict-consultancy-demo-edited.html`, which you can send back.

**Change it in a text editor.**

- Search for `EDIT:` to find each block of wording, with the decision it implements.
- **The WhatsApp number** is `var WA` in the script near the end, and the `wa.me/256705993348` links. The pre-filled messages follow `?text=`, with `%20` for each space.
- **To replace a photo:** find its `IMAGE:` comment and give the `<img>` a `src` (a file path or a URL). Then remove `data-embed` from that `<img>`; the embedded copy otherwise takes over on load.
  - Keep the *"Product photo"* label true, or change it if you use a photo of a real DishNet installation.
  - **The High Performance panel** is a drawing: replace the `<svg class="drawn">` with an `<img>`.
- **Leave the long `embedded-fonts` block and the `EMBED` line alone.** They hold the fonts and photos.

**Before anything is published**, remove the demo strip, the review tool and the `noindex` line, and settle §3 and §4. **None of that is authorised by these instructions.**

## 7. How the positioning differs from the live site

**The live site is a product catalogue:** Starlink, fibre, WiFi zones and CCTV, leading with plans, prices and *"Get connected"*.

**The demo is an adviser for Starlink.**

- It leads with a free consultation and the customer's decision: which kit, which way to acquire it, what registration involves, and how it is installed and supported.
- It sets the two routes side by side. In your own name, Starlink controls approval, KYC and activation. With a managed DishNet plan, DishNet's own service, subject to Starlink's terms.
- It keeps CCTV and network security clearly apart.
- Enquiries arrive on WhatsApp, sent by the customer, carrying their location, customer type and requirements.
