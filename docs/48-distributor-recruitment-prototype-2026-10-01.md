# 48 — "Become a DishNet Distributor": recruitment page & onboarding prototype

1 October 2026. **Prototype only. Not deployed.** No uCRM record, payment, message, stock record or
production system is touched or created. The page does not submit anywhere — it runs a **demonstration
("demo") mode** that saves nothing. Putting it on the live website, connecting a real submission, or
creating any uCRM/partner record are **separate steps that wait for explicit approval.**

This is also a **product-discovery exercise**: the prototype exists so DishNet can decide which partner
models to support, what to ask applicants, what training each partner needs, and what the future
distribution module (root `docs/47`) must hold — **before** any of it is built.

> **Update — 1 October, integration built (5.18.59).** On your approval ("integrate into live website",
> submissions **captured in the plugin**, search **indexed**) the page is now a live site citizen and the
> submission is wired to a new plugin endpoint that stores applications for staff review. **It is built,
> tested and held — nothing has been deployed by this session.** The two deploys (website → `main`;
> plugin → operator-run `deploy-5.18.59.sh`) wait for your explicit go-ahead. See **§12** for exactly
> what changed and what is still NOT done. Every compliance guardrail in §7 still holds: an application
> is **not** an approval, and **no uCRM client, partner, service or account is created.**

---

## 1. What was built, and where

| Item | Where |
|---|---|
| The prototype page (self-contained, no backend) | `dishnet-web-uganda/site/become-a-distributor.html` |
| Proposed live route | `https://dishnetuganda.com/become-a-distributor.html` (and a clean `/become-a-distributor` alias, optional) |
| Interactive review copy (private, click-through) | a private Claude artifact — link in the handover message. Private to the DishNet account until shared from its own Share menu |
| This document | root `docs/48` |

**One file, no dependencies.** All CSS and JavaScript are inline; the only external resource is the same
Google Fonts link (`Outfit` + `DM Sans`) every other DishNet page already loads, with a full system-font
fallback so the page is correct even if fonts do not load. It can be opened straight from disk, dropped
into the website as-is, or (for integration) refactored onto the shared `styles.css` — see §10.

**It reuses the live DishNet design system**, read from `dishnet-web-uganda/site/styles.css` and the
existing pages: the red accent `#C8102E`, `Outfit`/`DM Sans`, the `DishNet`+`UGANDA` wordmark lockup (the
site uses a text wordmark — there is no logo image file yet), the Uganda flag strip, the header/nav/mobile
menu, the dark footer, the floating WhatsApp button, and the `wa.me/256705993348` contact pattern. It does
**not** add a new website, replace the homepage, or change any existing page.

> **It deliberately does *not* repeat the claims on the existing `reseller.html`.** That page promises
> "wholesale pricing", "real margins", "we certify you to install", and "leads in your town come to you
> first". The brief forbids inventing discounts, commissions, margins, territories, credit or guaranteed
> earnings, so this prototype makes none of those promises and states throughout that everything is subject
> to DishNet review and a written agreement. If this page goes live, `reseller.html` should be revisited for
> the same reason (noted in §7, decision 3).

---

## 2. Screen-by-screen walkthrough (deliverable 3)

**The landing page** (above the wizard): the hero "Grow Your Business with DishNet", the sub-headline, the
two calls to action ("Explore the Partnership" → the wizard; "Talk to Our Team" → Contact/WhatsApp), the
four service categories (Starlink, Data Network, Fibre & fixed, Business connectivity) each with the "not
available in every area" caveat, the "why partner" benefits, and a prominent notice that **an application is
an expression of interest, not an offer or approval.**

**The wizard** is a single interactive component with a visible progress bar, numbered step chips (which
become clickable once a step is completed), Back/Continue controls, inline validation, conditional fields,
and a per-viewer draft saved in the browser only (`sessionStorage`, best-effort).

| Step | Screen | What happens |
|---|---|---|
| 1 | **Choose your partner model** | Four selection cards (A corporate/chain · B regional distributor · C retail outlet/reseller · D referral/sales). Required. The later steps adapt to the choice. |
| 2 | **Tell us about your business** | Registered name*, trading name, business type*, contact person*, role, email*, phone/WhatsApp*, city/district*, website, description, already-operating*. Required-field, email and phone validation. No passwords, payment-card or identity-document fields. |
| 3 | **Coverage and outlets** | Regions covered, customer groups served. For every model except referral: sales/field team, existing outlets (reveals a count field when "Yes"), plans to add outlets. For a corporate chain: a repeatable "proposed outlets" list. **A referral partner is never asked for stock or outlet detail.** |
| 4 | **Products and services** | Services of interest* (multi-select) and how they intend to work with DishNet* (refer / sell services / sell hardware / operate an outlet / first-line assistance / hold stock). The hardware/outlet/stock options are hidden for a referral partner. |
| 5 | **Readiness and training needs** | Sales experience, telecom/IT experience, staff to train, technical capability, ability to handle enquiries, (where relevant) managing orders/stock/collections, preferred training format. Used to shape the plan — not pass/fail. |
| 6 | **Your proposed training plan** | An interactive roadmap generated from the model + activities: Core modules for everyone, model-specific modules, and (only where hardware/outlet/first-line is involved) Technical modules. Each module shows purpose, who must complete it, an **estimated** duration, whether an assessment is proposed, and status **"Not started"**. A banner states plainly that **sales training ≠ technical certification** and that completing a sales module does not authorise anyone to install equipment. |
| 7 | **Review and submit** | Every answer, grouped, each group with an **Edit** button that jumps back to that step (and the review updates on return). A privacy notice and a required consent checkbox. A notice that submitting creates no distributor, credit, stock allocation or portal access. Submit is blocked until consent is ticked. |
| 8 | **Application received** | An honest confirmation: DishNet reviews → may contact you → commercial terms discussed separately → if approved, agreement + training + onboarding. It states clearly this is a **demonstration and nothing was submitted or saved**, and shows the real WhatsApp number and email (as selectable text) to actually reach the team. |

**The "DishNet Planning Preview"** is a separate, clearly-labelled **internal** panel (collapsed by default,
opened with a button). It reads the answers back as: the application summary, a partner-vs-DishNet
responsibilities split, the stock/outlet and collections/settlement requirements the model implies, the
training to assign, the approvals DishNet would need, and a suggested next internal step. It is marked "not
shown to applicants" and "not a real approval", and it stores nothing.

---

## 3. Training curriculum matrix (deliverable 4)

"Who" is who must complete the module; durations are **estimates**; "assessment" means an assessment is
**proposed**, not that one exists. Modules are de-duplicated when a model pulls from more than one set.

**Core — every partner (7):** Introduction to DishNet · Product knowledge\* · Ethical sales & pricing
integrity\* · Customer privacy & data protection\* · Lead handling · Customer onboarding basics · Support
escalation. (\* assessment proposed.)

| Partner model | Adds these module sets |
|---|---|
| **A — Corporate / chain** | Corporate (multi-outlet onboarding, outlet contacts & responsibilities, centralised order coordination\*, outlet-level reporting, corporate account escalation) **+** Retail (below) |
| **B — Regional distributor** | Distribution (outlet management, territory planning, order & dispatch\*, stock transfers & reconciliation\*, outlet reporting, partner performance & escalation) **+** Retail (below) |
| **C — Retail outlet / reseller** | Retail (product & plan selection\*, order placement\*, customer onboarding, hardware handling, payment & receipt\*, returns & warranty escalation) **+** Sales (below) |
| **D — Referral / sales partner** | Sales (lead qualification\*, customer needs assessment, product suitability\*, lead registration & tracking, referral attribution & follow-up) |
| **Technical (optional add-on)** | Added for **any** model whose activities include selling/distributing hardware, operating an outlet, or first-line assistance: installation prerequisites\*, basic troubleshooting\*, site readiness, escalation to authorised DishNet technicians, safety & customer-premises requirements\*. **Separate from installation certification.** |

**The rule the prototype enforces visually:** completing sales or product modules never confers authority to
install. Only certified DishNet technicians install. This is a decision to confirm (§7, decision 5).

---

## 4. Onboarding responsibilities matrix (deliverable 5)

What the **partner** does vs what **DishNet** does, by model. (This is the proposed split shown in the
Planning Preview; it is for validation, not a contract.)

| Model | The partner would | DishNet would |
|---|---|---|
| **Corporate / chain** | Coordinate outlets & nominate contacts; submit orders; hand over equipment & onboard customers at outlets; first-line assistance & outlet reporting | Approve the partner & each outlet; train & account-manage; create services & activate connectivity; invoice, collect & own billing; technical installation & escalation |
| **Regional distributor** | Develop the territory & support outlets; order/receive/move stock accurately; report sales & stock; collect & settle **only where authorised in writing** | Approve the distributor & territory; supply stock under agreed terms & ownership; provide training, pricing & settlement rules; invoice & reconcile, own billing; technical installation & escalation |
| **Retail / reseller** | Sell approved products & plans; place orders & onboard customers; handle hardware, payments & receipts; first-line assistance & escalate | Approve the outlet; provide training, product list & prices; create services & activate; invoice & own billing; technical installation & escalation |
| **Referral / sales** | Introduce & register genuine leads; follow ethical-sales & privacy rules; hand the customer to DishNet | Approve the referral partner; lead-handling training & tracking; qualify, quote, sell & onboard; **own the customer, billing & support entirely**; recognise referrals under agreed terms |

Across all models, consistent with `docs/47`: **uCRM stays the master of the customer, invoices, payments
and credit notes; DishNet owns the billing relationship; a partner never collects customer money through
the field-collection screens** (that path credits a staff wallet and commission — `docs/47` F7).

---

## 5. The data model and future integration (deliverables 1 context, 7)

The form captures a **structured application**. It is designed to map cleanly onto the future distribution
module (root `docs/47`) and uCRM, **without creating any record in this prototype.**

### 5.1 Field → future target

| Form field (key) | Future home | Notes |
|---|---|---|
| partner model (`model`) | `dist_partners.type` | one of the 4 proposed types (A1 in `docs/47`) |
| registered name (`biz_name`) | uCRM **company client** `companyName` + `dist_partners.legal_name` | a partner is a uCRM company client (`clientType` 2), created **only on approval** |
| trading name (`trading_name`) | `dist_partners.trading_name` | |
| business type (`biz_type`) | `dist_partners.category` | |
| contact person / role (`contact_name`,`contact_role`) | uCRM client contact + `dist_partner_users` (authorised rep) | |
| email (`email`) | uCRM client / contact email | |
| phone / WhatsApp (`phone`) | uCRM client / contact phone | **flagged as a partner** so it does not block or attach a retail customer's KYC (`docs/47` X8); **never the customer OTP key** |
| city / district (`city`) | uCRM address locality; `dist_outlets`/`dist_regions` | |
| website, description, operating (`website`,`desc`,`operating`) | `dist_partners` profile | |
| regions, customer groups, team (`regions`,`groups`,`team`) | `dist_partners`/`dist_regions` profile | |
| outlets (`hasOutlets`,`outletCount`,`addOutlets`,`outlets[]`) | `dist_outlets` | corporate chains describe several proposed outlets |
| services (`services[]`) | offered services → linked to **uCRM products/service plans** via `dist_price_lists` scope | availability depends on location (C1/C4) |
| activities (`activities[]`) | `dist_partners` capabilities | decides which portal roles apply later (G3) |
| readiness (`readiness.*`) | training assignment inputs | shapes the plan; not a uCRM field |
| consent (`consent`) | application provenance (`consent_at`, source) | |
| the whole submission | a new **`dist_partner_applications`** row, status `received` | **pre-approval**; it is NOT a uCRM client yet |

### 5.2 The rules any real integration must follow (from `docs/47`)

- **Submitting creates a pre-approval application, not a partner.** A uCRM **company client** (with a visible
  `dnPartnerCode` custom attribute) is created **only when DishNet approves** — never on submit, and never a
  duplicate of an existing client.
- **A partner client must be excluded from the retail flows** — it must not receive the retail welcome or
  the per-invoice customer WhatsApps (`docs/47` A8/X7), and its phone must not collide with retail KYC (X8).
- **Outlets, price lists, commissions, consignment, settlements and approvals live in plugin `dist_*`
  tables**, not in uCRM. uCRM organisations are **not** used for partners (they are DishNet's own invoicing
  entity; Uganda has one).
- **No commission or margin default exists, and none may be invented** (`docs/47` F6). Any rate is a
  business decision, recorded in a dated rule table, with tests.
- **The partner portal (partner sign-in) is a separate, deny-by-default surface built *after* a staff-run
  pilot** (`docs/47` §10, §14) — it is **not** part of this prototype, and this page grants no portal access.
- **Everything is Uganda-gated and off by default** (a `DistributionGate`, `docs/47` M16), with South Sudan
  behaviour unchanged.
- **No submission endpoint is invented.** The website has no server-side form today; contact is via
  WhatsApp/phone/email. A real submission needs a backend route (see §10) that writes a
  `dist_partner_applications` row and nothing else.

---

## 6. Decisions DishNet must make before launch (deliverable 6)

The prototype exists to make these answerable. The first block is the brief's own decision table; the second
adds the open decisions `docs/47` already identified.

| Area | Decision needed |
|---|---|
| **Partner types** | Which of the four models DishNet will officially appoint (and whether "referral" is a formal model). |
| **Products** | Which products/plans each partner type may sell, and where (availability is per-location). |
| **Commercial terms** | Discounts, margins, commissions, payment terms — **none exist today** and none are promised on the page. |
| **Stock** | Whether partners **buy** stock, hold **consignment** stock (DishNet-owned), or **refer only** (`docs/47` D6; and D-2: what counts as the consignment "sale event"). |
| **Training** | Mandatory modules per model, and **who may install** (sales training vs technical certification). |
| **Customer ownership** | Who owns support and renewals (`docs/47`: DishNet). |
| **Collections** | Who may collect money and how it reconciles (partner money must not use the field-collection path — `docs/47` F7). |
| **Approval** | Who approves a partner and its outlets (second-person approval — `docs/47` F10). |
| **Software** | How an approved application becomes a uCRM company client + `dist_partner` record, and later a portal account (`docs/47` M2). |
| *Also (from `docs/47`)* | Is an outlet ever its own uCRM client? (D-5) · Which webhook/retail messages a partner is excluded from or given partner versions of (D-10) · Whether a disposable uCRM may be stood up to test the integration · The exact application fields to collect (validate the set above). |

---

## 7. Compliance and honesty (deliverable, brief §2 & §6)

Built in, and verified:

- **Application ≠ approval.** Stated on the landing notice, the review step and the confirmation: no
  guarantee of approval, exclusivity, credit or appointment; terms subject to DishNet review and a written
  agreement.
- **No invented numbers.** No discounts, commissions, margins, territories, credit facilities, guaranteed
  earnings or minimum commitments anywhere.
- **No "available everywhere" claim.** The service section and step 4 both say availability depends on model
  and location.
- **Sales vs technical.** The training step states a sales module does not authorise installation; only
  certified DishNet technicians install.
- **Demo honesty.** The confirmation says plainly the answers were not submitted or saved. The page never
  claims a message was sent — the WhatsApp number and email are shown as selectable text (a link is offered
  as a convenience only).
- **No sensitive data.** No passwords, payment-card or identity-document fields. No credentials, tokens or
  API keys are present in the HTML.
- **No fabrication.** No fake testimonials, partner logos or statistics.

---

## 8. Test results (deliverable 8)

Driven in headless Chromium against the file, **42 of 42 checks pass, 0 application errors.** (The run logs
3 TLS warnings for the Google Fonts CDN — an artifact of this sandbox's proxy, not a page fault; the live
site loads the same fonts and the page has a system-font fallback.)

| Acceptance check (brief §7) | Result |
|---|---|
| All 8 steps accessible in sequence | ✅ |
| Back navigation preserves answers | ✅ (verified: a typed business name survived Back) |
| Required fields validated | ✅ (model, business required fields, email format, phone, services/activities, consent) |
| Conditional questions appear for the correct models | ✅ (referral hides the outlet question and the hardware/stock activities) |
| Training modules change by partner type & activities | ✅ (referral has no Technical modules and fewer modules than retail) |
| Review reflects the answers | ✅ |
| Editing a previous step updates the review | ✅ |
| Submission shows an honest confirmation | ✅ (demo notice present) |
| Demo mode does not pretend to save | ✅ |
| Works at mobile and desktop widths | ✅ (no horizontal scroll at 390px; screenshots captured) |
| No existing website route/page/form broken | ✅ (a new standalone file; nothing else changed) |
| No private keys/credentials/tokens in the HTML | ✅ |
| No production uCRM record/payment/message/stock created | ✅ (no backend; demo only) |

Screens captured: hero (desktop + mobile), the wizard, the training roadmap, the review, the confirmation,
and the planning preview.

---

## 9. Integration instructions (deliverable 2) and the live route

For review now: open the file, or the private artifact link. Nothing to install.

To put it on the website later (**after approval**), either option keeps the brand:

1. **Drop-in (fastest):** add `dishnet-web-uganda/site/become-a-distributor.html` to the deployed site and
   link to it (e.g. a "Partners" nav item and a footer link). It is self-contained. Optionally add a clean
   `/become-a-distributor` alias the way the site already handles clean URLs.
2. **Refactor onto the shared system:** replace the inline `<style>` with `styles.css` and lift the shared
   header/footer, keeping the wizard's own CSS/JS inline. Same look, less duplication.

**To accept real submissions** (a later, separate decision): the website has no form endpoint today, so one
must be added. The honest options, smallest first:
- **WhatsApp hand-off (no backend):** the confirmation already builds a pre-filled `wa.me` message; make
  that the submit action. Zero new infrastructure; the "application" arrives as a WhatsApp chat.
- **Plugin endpoint:** add a deny-by-default public route in the plugin (`public.php?page=...`) that writes a
  `dist_partner_applications` row (and only that). This is the path that feeds the future distribution
  module. It must create **no** uCRM client — approval does that, as a staff action.

Until then the page runs in **demo mode** and the handover says so.

---

## 10. Change summary and rollback (deliverable 9)

**Change:** two new files, nothing modified or removed.
- `dishnet-web-uganda/site/become-a-distributor.html` — the prototype (new).
- `docs/48-distributor-recruitment-prototype-2026-10-01.md` — this document (new).

No existing page, stylesheet, script, route, form or contact function is changed. The website, the plugin,
uCRM, the database and every deploy script are untouched. Nothing is deployed.

**Rollback:** delete the two new files (`git rm` / `git revert` the commit). Because nothing else changed
and nothing was deployed, there is no state to restore and no other page is affected.

---

## 11. What is explicitly NOT done

- **Not deployed** to the live website, and not linked from any live page.
- **No real submission**, no backend endpoint, no API invented. Demo mode only.
- **No uCRM record, partner record, payment, message or stock** created or changed.
- **No partner portal / partner sign-in** — that is a separate, later, deny-by-default build (`docs/47` M2),
  after a staff-run pilot.
- **No commercial terms, commissions, margins, territories or credit** defined — those are the decisions in
  §6.
- The existing `reseller.html` is **left as-is** (its over-claims are flagged in §1 for a separate decision).

Next step is the operator's: review the screens, decide the partner model and the §6 questions, then approve
(a) integrating the page into the live site and (b) how submissions should flow. Nothing proceeds without
that approval.

---

## 12. Integration as built (5.18.59) — built and tested, NOT deployed

On your approval — *"integrate into live website"*, submissions **"Capture in the plugin"**, search
**"Yes, index it"** — the prototype became a live site citizen and the submission was wired to a new plugin
endpoint. Everything below is in the branch and **held**; this session deployed nothing.

### 12.1 The website half (`dishnet-web-uganda/`)

- **`site/become-a-distributor.html`** is now a normal page, not a noindex demo: the `robots` noindex meta is
  removed, it carries the shared favicon, fonts and a `LocalBusiness` JSON-LD block, and every cross-page
  nav/footer link is relative like the rest of the site.
- **Submission.** The wizard's final step POSTs a form-encoded body
  (`payload=<JSON>&hp=<honeypot>&t=<ms since the page rendered>`) to the plugin endpoint
  `…/public.php?page=distributor_apply`. On success it shows *"Thank you"* with a reference
  (`DNP-NNNNN`). **If the endpoint is unreachable it falls back to the WhatsApp hand-off** — so a real
  applicant never loses their application, and the page is safe to publish even before the plugin endpoint
  is live.
- **Discoverable (indexed).** A footer "Become a Distributor" link on every top-level page (38 pages), the
  `reseller.html` CTA points here, and `sitemap.xml` lists the URL.
- **Guards:** `verify-address.py` passes (58 pages; one address / phone / coverage; 87 JSON-LD blocks
  parse). The headless wizard test (`scratchpad/wiz_test.js`) drives the whole flow — stored confirmation
  with its `DNP-00042` reference, the form-encoded payload, and the WhatsApp fallback branch — **47/47**.

### 12.2 The plugin half (`dishnet-hybrid-sudan/`, version 5.18.59)

| Piece | File | Note |
|---|---|---|
| Capture table | `migrations/077_distributor_applications.sql` | `dist_partner_applications`, additive, idempotent. Pre-approval intake only. |
| Server-side gate | `lib/DistributorApplicationService.php` | `normalise()` — partner model whitelisted, labels **derived server-side**, service/activity ids whitelisted, control chars stripped, lengths capped, required fields enforced. The browser is never trusted. |
| Public endpoint | `distributor_apply.php` | Modelled on `web_chat.php`: CORS from the shared site-origin allow-list (never `*`), OPTIONS preflight, POST-only, honeypot, minimum fill-time, per-IP rate limit, 20 KB payload cap. Reached **before** the login gate. |
| Public route | `public.php` | `page=distributor_apply` routed ahead of `requireLogin()`. |
| Staff review | `tabs/admin/partner_applications.php` | Read-only list + detail, admin-gated. Every cell escaped. |

- **The admin review tab is Uganda-only.** The module is added to `$ALL_MODULES` only on the Uganda tenant
  (`$_staffJobsUganda`), so a South Sudan / non-Uganda install's admin UI is byte-for-byte unchanged (proved
  by the South Sudan golden render test). The endpoint and table are harmless on South Sudan (unused, empty).
- **The boundary that matters.** A row in `dist_partner_applications` is an **application, not an approval**:
  the endpoint and the service create **no uCRM client, partner, service or account** and grant **no portal
  access**. Appointing a partner stays a separate DishNet staff action (`docs/47`). A test asserts the
  endpoint and service name no `CrmApiClient`, no uCRM API path and no client creation; the deploy script's
  stage R5 re-checks it on the installed files.
- **Tests:** `tests/test_distributor_apply.php` **61/0** (migration, `normalise()` valid/invalid/sanitise,
  create/get/list/counts, endpoint CORS + anti-abuse guards, the no-uCRM boundary, the route before the
  login gate, the Uganda-only module gate, manifest 5.18.59). Full plugin suite green; the South Sudan
  golden stays green.

### 12.3 The two held deploys

Both are the operator's to run, and each is held for your explicit go-ahead:

1. **Website** → merge the branch to **`main`**, rebuild `web-uganda` on EasyPanel, then run
   `verify-site.sh` / `verify-address.py` (`dishnet-web-uganda/README-DEPLOY.md`). Safe to do first — the
   WhatsApp fallback covers the window before the plugin endpoint is live.
2. **Plugin 5.18.59** → operator-run `scripts/deploy-5.18.59.sh` (typed `DEPLOY`; a **separate** typed
   `ROLLBACK`, printed on its own at the end of the log — never pasted together). Pinned to the reviewed
   commit, baseline-gated on 5.18.58, backs up first, applies migration 077, then verifies the endpoint's
   guards and that no application row was created by its own probes. Rehearsed in
   `scripts/harness/deploy-5.18.59/`.

### 12.4 Still NOT done (unchanged from §11, restated)

No partner portal or sign-in; no commercial terms, commissions, margins, territories or credit; no uCRM
sync of applications; `reseller.html`'s over-claims untouched. The capture is intake for staff review — the
front door to the future distribution module (`docs/47`), not the module itself.
