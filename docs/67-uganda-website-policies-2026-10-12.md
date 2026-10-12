# 67 — Uganda website policies: warranty, returns, terms, privacy, complaints (12 Oct 2026)

**Status: BUILT on the development branch, reviewed adversarially (§H), NOT DEPLOYED, NOT LEGALLY REVIEWED.** The
site is deployed by the operator (EasyPanel, `dishnet-web-uganda/`), never from this session. The quotation and invoice
templates changed with it (§I) and are loaded into uCRM by staff. **Every legal point behind these pages comes from
search results, not from the law itself:** this session could not open ulii.org, ucc.co.ug, pdpo.go.ug, ura.go.ug or
starlink.com. A Ugandan advocate should read §D, §E and §I before the pages go live; the operator has decided
not to commission that review (§J item 8).

The operator asked for warranty, returns and other policies for the Uganda website. They should follow how Starlink
handles these in Uganda and how others do it, and be written "so we don't get any legal challenges".

## A. What changed on the site

| | |
|---|---|
| New pages | `policies.html` (the hub), `terms-of-sale.html`, `warranty.html`, `returns.html`, `installation.html` (*Starlink Installation*), `acceptable-use.html`, `complaints.html` |
| Rewritten | `terms.html` (now *Terms of Service*), `privacy.html` (*Privacy Policy*) |
| Every page's footer | "Terms of Use" becomes "Terms of Service"; *Warranty*, *Returns & Refunds* and *All Policies* added after the last legal link. All 58 pages; `build.py` now checks every page for them |
| `faq.html` | Two questions added (warranty; returns and refunds). The answers on availability, moving, support and plan mapping are corrected (§H). The missing structured-data entry is added. The FAQPage data now carries all 16 questions word for word |
| `about.html` | "an official Starlink reseller and installer" becomes "experienced Starlink installers"; a "UCC-authorised" line added on 12 Oct was withdrawn the same evening (§J) |
| `starlink-kits.html` | "certified technicians" becomes "trained DishNet technicians". "No contract — change or pause any month" now says plans change from the next billing month, pausing works where Starlink allows it, and Flex has its own agreement. Warranty and returns links |
| `shop.html` | one sentence linking the warranty and returns pages |
| `why-dishnet.html`, `guides.html`, `whatsapp.html` | "certified" becomes "professional" (§J); "real humans / real people, 24/7" becomes "any time, and you can ask for a person" (an automated assistant answers first) |
| `sitemap.xml` | the seven new pages (57 → 64 URLs) |
| `policies/` (new, not served) | `build.py` and `bodies/*.html`: the nine pages' source; see §C |

No price, no currency figure and no section number of any Act appears on any page. The pages name the laws they rely
on: the Sale of Goods and Supply of Services Act, the Electronic Transactions Act, the Data Protection and Privacy Act,
the Computer Misuse Act, the Hire Purchase Act. They also name the Uganda Communications Commission and the Personal
Data Protection Office.

The identity line comes from `dishnet-hybrid-sudan/profiles/uganda.json` and quotation clause 9, without the UCC
claim (§J): "an IT solutions provider and Starlink installer, registered in Uganda (Reg. No. 80046255496181, TIN
1059140632) … not an agent, partner, franchisee, affiliate or representative of Starlink or SpaceX".

## B. The promises the pages make — business decisions, chosen as defaults

Every number a customer relies on lives once, in `PARAMS` in `policies/build.py`. **Each is a business decision,
taken here as a default, for the operator to confirm or change:**

| Promise | Default | Why this default |
|---|---|---|
| Starlink kit warranty | **12 months from the day the customer receives the kit**, as **DishNet's own warranty**, honoured whatever Starlink decides; DishNet raises any Starlink claim | Starlink's limited warranty for a Standard kit outside the EU/UK is reported as 12 months, but **from Starlink's own sale**. DishNet counts from its customer's receipt, so it carries the gap |
| Replacement unit | covered for the rest of the original period or **3 months**, whichever is longer | Starlink's reported rule for its replacements |
| Installation workmanship | **6 months** from completion; call-out to the address where the work was done | No peer benchmark was found; the old terms promised a workmanship warranty with no period |
| Other equipment | the manufacturer's period on the invoice or product, else **6 months** | Mounts, routers, CCTV, firewalls |
| Faulty on arrival or within 7 days of delivery or installation | **replacement or full refund, the customer's choice**; DishNet collects and delivers | The right to reject goods that are not of satisfactory quality |
| Transport for covered faults | **to and from the address where DishNet delivered or installed, anywhere in Uganda** | The seller bears the necessary costs of repair or replacement, as the research reports the Act |
| Change-of-mind return | **7 days from receipt, for every customer** (also at the office); item and delivery refunded, customer pays the direct return cost; installation and service used at the customer's request not refunded, except as the law requires | The Electronic Transactions Act is reported to give 7 days for electronic sales, with only the direct cost of return chargeable. Peer norm: Jumia Uganda 7–15 days; Starlink direct 30 days |
| Flex packages, at the office or electronically | cancellable **within 7 days of receiving the kit** | The Act's "related credit agreement" for electronic sales; DishNet's own policy for office sales (§J) |
| Refunds | **within 14 days** of the returned item (or of cancelling, where nothing comes back); for electronic orders cancelled in time, **always within 30 days of cancelling**; same method; VAT included; credit note; no fees | The Act is reported to require 30 days from cancellation |
| Unpaid bill | **service pauses automatically at the end of an unpaid period**; reminder before each month is due; never paused over a disputed amount while the rest is paid; a plan ends only after written notice with a pay-by date | This is how Uganda billing runs (prepaid; quotation clause 3, invoice payment terms). **The 7-day grace of the first draft is withdrawn**: nothing configures it |
| Price or adverse change | **30 days' notice**, with the right to cancel at no charge | Starlink reportedly gives one month; quotation clause 2 says the same |
| Service credit | one day's share of the monthly charge for each full day down after **3 days** in a row following a reported fault, unless the cause is the customer's power, an obstruction, damage, moved equipment or an unpaid bill | New commitment |
| Liability cap | only for **interruptions to networks DishNet does not operate**: the charges for the affected service in the 3 months before; never where DishNet failed to use reasonable care and skill; never for death, injury or fraud | The first draft capped every service failure; the review found that would cut down implied terms |
| Complaints | reference and reply within **1 working day** (Monday to Friday, except public holidays); aim to resolve in 7 days; **final written answer within 14 days**, or a written reason and a date; then UCC, the Personal Data Protection Office, or the courts | No operator deadline could be established; UCC's own target is reported as 30 working days |
| Returns inspection | within **2 working days** of receipt | New commitment |
| Privacy requests | reply **within 14 days** | The Act is reported to give 14 days for objections and direct-marketing requests |
| Records | **6 years** after the account ends, longer if tax law requires; enquiries without an order up to **2 years**; website chats **90 days**; chat IP addresses **2 days** | The Limitation Act period; `web_chat_retention_days` (default 90) and the chat rate ledger |

## C. How to change a page

1. Edit `dishnet-web-uganda/policies/bodies/<page>.html`, or a number in `PARAMS` in `policies/build.py`.
2. Run `python3 dishnet-web-uganda/policies/build.py`. It rebuilds the nine pages from `terms.html`'s own head,
   header and footer. It refuses to finish if any of these is wrong:
   - the FAQ, the kits page or the Terms of Sale repeats a policy number that no longer matches;
   - the FAQ's structured data differs from its visible questions and answers;
   - a page lacks the footer policy links.

   Fix that page by hand, then run it again.
3. Run `python3 dishnet-web-uganda/policies/build.py --check`, `python3 dishnet-web-uganda/verify-address.py` and,
   where nginx is available, `dishnet-web-uganda/verify-site.sh`.

Never edit `site/<policy>.html` by hand: `--check` reports such an edit, and the next build overwrites it.

## D. What the research found — and how far it can be trusted

Four researchers and four skeptics ran on 12 Oct. **No primary page was opened** (DNS failure on every host, CONNECT 403
at the proxy), and the shared search budget ran out. Each statement below is therefore a search extract or a secondary
source. **None may be cited on the site, and none is cited there, by section number.**

**Sale of goods and services**
- The Sale of Goods and Supply of Services Act implies terms for both goods and services:
  - goods: title, correspondence with description, satisfactory quality (durability included) and fitness for a
    purpose made known;
  - services: reasonable care and skill.
- It gives the buyer an examination right before acceptance, repair or replacement at the seller's cost within a
  reasonable time, and, failing that, a price reduction or rescission.
- An express warranty does not replace an implied term. A student guide reports that the implied terms cannot be
  excluded at all; that is not confirmed.
- The Act's year is reported as both 2017 and 2018, and its sections may be renumbered in the 7th Revised Edition
  (in force 1 July 2024).

**Electronic sales**
- The Electronic Transactions Act, 2011 has a consumer-protection part:
  - information the supplier must give;
  - **7-day cancellation** after receipt or conclusion, at no more than the direct cost of return, with a **refund
    within 30 days**;
  - 14 days if the information was not given;
  - execution within 30 days unless agreed otherwise;
  - an anti-exclusion rule.
- The exemptions reported include services begun with the consumer's consent within the 7 days, and goods made to the
  consumer's specification. Whether an activated Starlink kit "cannot by its nature be returned" is legal opinion,
  not text. The pages therefore let an activated kit be returned.
- Whether a WhatsApp order is an "electronic transaction" is an inference from the definitions. The pages assume it
  is, and give the same 7 days to every customer.

**Contract rules**
- The Contracts Act voids a clause that bars a party from suing or limits the time to sue. The pages contain no such
  clause.
- A contract above twenty-five currency points must be in writing; the threshold's value was not read from the Act.
- The Limitation Act period for contract claims is reported as six years.

**No general consumer-protection statute**
- No general consumer-protection Act is in force.
- The Competition Act (2024) gives the trade ministry consumer functions but sets no consumer rights.
- A Consumer Protection Bill is still pending.
- **There is no "Consumer Protection and Competition Act"**. docs/62 question 1 used that name and is corrected in
  this change.

**Communications**
- UCC takes second-level complaints, once the provider has had its chance; toll-free 0800 222 777.
- The Uganda Communications (Consumer Protection) Regulations, 2019 exist, but their operative text (operator
  deadlines, notice periods) was not read.
- Providing telecommunications services needs a UCC licence.
- UCC announced a crackdown on unlicensed public-WiFi providers in July 2026 (news, unverified).
- Communications equipment needs type approval, and vendors need authorisation.

**Data protection**
- The Data Protection and Privacy Act, 2019 and its 2021 Regulations require:
  - registration with the Personal Data Protection Office (the PDPO held this mandatory against Google in July 2025);
  - a notice before collection;
  - transfers abroad only to adequate protection or with consent;
  - immediate notification of a breach to the regulator (not "72 hours");
  - access, correction and objection rights, including a written reply within 14 days for objections;
  - retention no longer than needed;
  - parental consent for children's data.

**Starlink, as reported (help-centre and US/EU versions; the Uganda versions were not read)**
- Limited warranty 12 months for Standard kits outside the EU/UK and 24 months for High Performance, from the original
  purchase, even after a transfer. A replacement may be refurbished.
- Kits bought from a retailer or reseller go back to that seller for returns.
- **No resale of access without Starlink's authorisation**, except community WiFi on a Priority plan.
- Residential plans are for personal or household use only.
- Starlink is licensed in Uganda since May 2026 (provisional per UCC, five years per press) and went on sale directly
  on about 1–2 September 2026. Devices activated in Uganda are registered with UCC.
- Starlink disclaims third-party installations.

**Peers**
- MTN and Liquid Home Uganda hold the customer liable for the provider's equipment, except fair wear and tear.
- Liquid gives 7 days to remedy after written notice before suspending.
- Jumia Uganda gives 7–15 days for returns.
- No Ugandan Starlink reseller with a published consumer policy was found. DishNet would be setting the norm, not
  following one.

## E. Risks the pages cannot fix — for the operator, before or soon after publishing

1. **Starlink status.** The About page no longer says "official Starlink reseller". Every page now uses the
   operator's own wording instead: independent company; Starlink installer; not an agent or partner of Starlink.
   Since §J, the pages also offer the operator's model: buy in your own name with DishNet's help, or take a managed
   DishNet plan. What the pages cannot change is what DishNet actually does:
   - The quotation (clause 10) and the customer-portal terms say the Starlink account is **registered and managed by
     DishNet**, and the customer's contract is with DishNet only. The new Terms of Service say the same, so the site
     matches the documents customers sign.
   - DishNet bills monthly plans mapped to Starlink plans (FAQ). It runs or sells WiFi zones, and recruits resellers
     and distributors.
   - DishNet buys kits from Starlink (the 10 Sep 2026 invoice, UGANDA-VAT-CONFIGURATION.md).

   Starlink's terms reportedly forbid resale and unauthorised agency without its authorisation, and allow Residential
   plans for household use only. Breaking a supplier's contract is not a crime in Uganda. The risk is commercial:
   Starlink can suspend accounts it treats as resold, and the customer then turns to DishNet. **Get Starlink's written
   position, or an authorised-reseller agreement.** Advising customers and helping them order in their own name, then
   installing and supporting for a fee, is the low-risk model. Since §J the pages offer it beside the managed plan;
   on a DishNet plan (quotation clause 10, the customer-portal terms) the account is still DishNet's to manage.
   The pages keep the operator's phrase "registered and managed by DishNet on your behalf" (§H). Ask Starlink
   which it accepts: DishNet as its customers' agent, or DishNet as the account holder that supplies them.
2. **The UCC licence.** The UCC letter of 4 Sep 2026 (§J) is a conditional no-objection, not a licence. It says to
   obtain an Equipment Vending and Installation Licence (erp.ucc.co.ug) **before** providing the services. Apply now,
   using the current office address; the letter went to the old one. Whether *providing and billing* internet service
   needs a further UCC licence is NOT established; ask UCC in the same application. Add the licence category and
   number to `policies.html` when issued. UCC began a crackdown on unlicensed public WiFi in July 2026 (news,
   unverified). The pages say that selling public WiFi needs any licence UCC requires.
3. **PDPO registration.** Registration is reported as mandatory, renewed yearly. The Privacy Policy claims none;
   **register, then add the number.** The operator is registering (§J).
4. **Type approval and vendor authorisation** for equipment sold (UCC). The pages claim none.
5. **Flex may be hire purchase.** The pages now say Flex follows its own signed agreement and the Hire Purchase Act
   where it applies, and can be cancelled within 7 days of receiving the kit, wherever it was agreed (§J). Get an
   advocate's view before any Flex agreement is used. The knowledge base already says Flex is quoted, not signed,
   until management confirms.
6. **"Unlimited" and speed claims elsewhere** (`fiber.html`, the FAQ's speed range) are unchanged marketing. The
   fair-use section keeps fiber uncapped and says Starlink plans are network-managed.
7. **The customer-portal terms (v1.1, `lib/LegalContent.php` from `profiles/uganda.json`) differ from the website:**
   - "UCC-authorised Starlink installer" (`legal.identity`, the first sentence): the website no longer says it, and
     the UCC letter does not support it. First in the next plugin release (§J);
   - "suspension without further notice" — the same in effect as the website's automatic pause;
   - "leased equipment returned within 14 days" — quotation clause 12 says 7;
   - "credit for extended outages at our discretion" — the website gives a 3-day service credit.

   The website's Terms of Service now say that where the portal terms, a quotation or an invoice differ, the version
   better for the customer applies. Aligning the portal text needs a plugin release (bump `legal.version.tos`, so every
   customer re-accepts). **Not done here.**
8. **Installation Terms v1.0 still shows "DRAFT — SUBJECT TO LEGAL REVIEW"** to every customer who opens the
   acceptance link. The Starlink Installation page summarises that text. No legal review will be commissioned (§J
   item 8); decide whether to release v1.1 without that line. Proposed for v1.1 (the first point already applies
   on the website, §J item 5):
   - take property damage from negligence, and death or injury, out of the §25 cap;
   - add the installation photos and completion location to §23.

9. **Quotation clause 2.** Done: the sentence on a new or increased tax being passed on at cost is deleted (§J item 2).
10. **The customer indemnity (quotation clause 16).** Done: kept, narrowed, and stated in Terms §12 (§J item 3).
11. **Flex agreed at the office.** Done on the website: Returns §6 gives the 7-day cancellation wherever the package
    was agreed, on section 2's terms (§J item 4). Write the same in the Flex agreement.
12. **Upcountry delivery.** `why-dishnet.html` says upcountry is not extra, and the kits page says delivery anywhere in
    Uganda is included. The policies allow a delivery charge "if there is one". Confirm that no upcountry charge is
    ever made, or change the marketing.
13. **"The DishNet app".** Done: it is now the customer portal on `contact.html`, `fiber.html` and `services.html`, and
    `hotspot.html` no longer promises a dashboard (§J item 6).
14. **"24/7".** Done: the support claims now read "every day", and messages may be sent at any time (§J item 6). If a
    person is confirmed to cover nights, "24/7" can return.

## F. New operational commitments the pages create

From the day the pages go live, the business must:
- **Quotations:** give a written quotation before payment. Where installation and the first month are inside a package
  price, say how much of it each is.
- **Payments:** give an invoice or receipt for every payment.
- **Billing:** send a reminder before each month is due. Never pause service over a disputed amount while the rest is
  paid. End a plan only after written notice with a date to pay by.
- **Refunds:** pay within 14 days, or within 30 days of cancelling for an electronic order, by the paying method, with a
  credit note.
- **Returns:** give a return reference, and check the item within 2 working days. Send a refused return back at
  DishNet's cost, with the reason in writing.
- **Warranty:** honour the kit warranty as DishNet's own, whatever Starlink decides. Collect and deliver anywhere in
  Uganda where DishNet delivered or installed.
- **Complaints and claims:** give a reference and a reply within 1 working day, and a final written answer within 14
  days. Keep a complaint log.
- **Marketing:** honour STOP on WhatsApp. **The win-back message ("We Miss You…",
  `NotificationService::winBackFollowup`) carries no stop instruction.** The page does not claim that it does.
  Adding "Reply STOP to stop these messages." to it is a plugin change, recommended. E-mail has no opt-out check.
- **Records:** delete enquiries without an order after 2 years. Keep website chats 90 days: confirm production has not
  changed `web_chat_retention_days`.
- **Starlink consent:** ask for consent to share activation details with Starlink at order time.
- **Notice:** give 30 days' notice of adverse price or term changes.
- **Service credits:** credit the days without service after 3 days, counted from a recorded fault report.
- **Policy versions:** keep every version of the policies, and send the version that applied on request.

## G. Results

- **`policies/build.py --check`: up to date.** It now also checks three things:
  - every page carries the footer policy links;
  - the FAQ's visible questions and answers equal its FAQPage structured data, word for word;
  - the FAQ, kits and Terms of Sale pages repeat the policy numbers correctly, including the faulty-on-arrival
    window.

  A rebuild writes nothing (idempotent).
- **`verify-address.py`:** *The site agrees with itself*.
- **A file-based port of `verify-site.sh`'s static checks** (nginx is not installed here):
  - 75 references, 0 broken;
  - sitemap 64 URLs, all resolving;
  - no price figure;
  - one WhatsApp number; the allowed portal URLs.

  The same port passes on the committed site, the baseline. It catches a planted price, a planted broken link and a
  planted broken in-page anchor (the controls).
- **Cross-page anchors:** every `page.html#id` link from the policy pages resolves. The only broken ones on the site
  predate this change: the tutorials' `../index.html#products`.
- **`stamp-assets.sh --check`:** unchanged; no stylesheet edited.
- **The merchant ID** appears exactly as often as before (faq.html 4 times, pay.html 9, get-the-app.html 1,
  why-dishnet.html 1). None was added.
- **Templates (§I):** `test_quote_tax_line.php` 31/0, `test_kit_tax_note.php` 68/0, `test_invoice_template_links.php`
  21/0.
- **The quotation harness** `scripts/harness/quotation-template/rehearse.sh` passes 43/0 on Twig 3 and Twig 2, and
  REHEARSAL 8 ok / 0 failed. Its controls:
  - a template with one extra, unlisted wording change fails (8 failed);
  - a template with a listed change only half made fails (16 failed);
  - before the harness learned the changes, the edited template failed it (8 failed), so the guard bites;
  - clause 14 put back in the second column, without the list knowing, fails (8 failed).
- **Page 2 still fits.** Printed with Chromium at A4 (only an approximation of uCRM's PDF engine), every sample
  quotation is two pages. Page 2's content measures 947 px against 951 px before 12 Oct, and its tallest column
  554 px against 558 px (after §J). At 108% zoom it is still two pages; the old template spills there.
- **The invoice harness** passes 21/0, REHEARSAL 7 ok / 0 failed.

## H. The adversarial review (12 Oct)

Four reviewers each read the nine pages through one lens: Ugandan law, consistency (between pages, with
INSTALLATION-TERMS-v1.0, and with the rest of the site), operational truth (against the repository's code and
documents), and plain language with valid HTML. A skeptic then checked each reviewer's findings.

| Lens | Upheld | Rejected |
|---|---|---|
| legal | 38 | 1 |
| consistency | 32 | 5 |
| truth | 16 | 9 |
| clarity | 40 | 1 |

Where findings collided, one decision sheet settled them (D1–D18):
- **Billing:** it follows practice. The service pauses at the end of the paid period.
- **Contract:** it is with DishNet. DishNet manages the Starlink account. (§J item 1: on a DishNet plan; a customer
  may also buy in their own name.)
- **Identity:** independence plus the UCC-installer line. (The UCC line is withdrawn, §J: the UCC letter is a
  conditional no-objection, not a licence.)
- **Taxes:** all included, nothing added.
- **Installation Terms:** they cover Starlink jobs only.
- **Warranty:** it is DishNet's own.
- **Remedies:** "significant inconvenience" is the test.
- **Refund clock:** set by the Electronic Transactions Act.
- **Starlink's terms:** not part of DishNet's contract.
- **Flex:** follows its own agreement and the Hire Purchase Act.
- **Privacy:** named the Privacy Policy, with no claim of compliance or registration, and marketing stated as it is
  done.
- **UCC:** reached through ucc.co.ug, with no phone number until staff confirm one. (Superseded, §J item 7: the
  toll-free line 0800 222 777, from the UCC letterhead.)
- **Precedence:** where documents differ, the version better for the customer applies.

Five editors applied the findings, each owning its own files, and a skeptic per group checked the text against the
findings and the decisions. A last reading by hand made a few small corrections: one repeated phrase, the word "pause"
used consistently on the Privacy and Complaints pages, and the "certified" and "real 24/7" lines on `why-dishnet.html`
that no finding named.

### The last cross-page check (after commit 4f9dc973)

One more reader compared every page with every other page and with the quotation. The pages agreed with each other on
every number. It reported 22 findings. Fifteen were applied, in full or in part:

- **The quotation, where clause 1 did not reach or the text still read against the pages:**
  - pause only where Starlink allows it (clause 7);
  - no pause over a disputed amount (clause 3);
  - outages and liability "as DishNet's Terms of Service set out", instead of "not liable" and a 3-month cap
    (clauses 8 and 15);
  - the customer follows Starlink's Acceptable Use and Fair Use Policies, not "Starlink's applicable terms"
    (clauses 8 and 16);
  - warranty exclusions are the Warranty Policy's, and loaned equipment allows fair wear and tear (clause 12);
  - unlawful use: suspension or ending as the Terms of Service set out, no longer "immediately and without refund"
    (clause 13);
  - data handled as the Privacy Policy describes (clause 20);
  - complaints as the Complaints page sets out, including the UCC (clause 21).
- **The Terms of Service (§16)** gain the case quotation clause 10 already covered. If Starlink's network, account or
  policies make the service impossible or unlawful to continue, DishNet tells the customer as soon as it can, offers
  an equivalent where it has one, and refunds what was paid in advance for service not provided.
- **Faulty on arrival:** "within 7 days of delivery, or of installation if later", the same on the Warranty, Returns
  and FAQ pages. The builder checks the FAQ.
- **A billing mistake:** "we refund you, or credit your account if you prefer" (Terms §6, Complaints §3).
- **The Returns summary** points Flex packages to section 6.
- **"The Starlink app"** where that is meant (Starlink Installation §2, the kits page). `why-dishnet.html` no longer
  offers "the app to manage it all": the DishNet app is not released.
- **"Every kit package"**, not "every kit", includes installation (the kits and shop pages), because kit-only orders
  exist.
- **WiFi zones.** "Many zone owners recover their setup cost within months" is removed from the FAQ and its structured
  data, from `hotspot.html` and from the WiFi-zone blog post: no zone operates yet (dishnet-hybrid-sudan/docs/109). The
  FAQ now says a zone needs a written agreement, a Starlink plan that permits selling access, and any licence the UCC
  requires.

Not applied, with the reason:
- **Clause 2's "new tax … passed on at cost":** the operator's approved line; clause 1 makes the website prevail
  (§E item 9).
- **"Registered and managed by DishNet on your behalf":** the reviewer read it as agency and proposed "to provide your
  service". The phrase is the operator's own (portal terms), and it fits DishNet's position that it manages accounts
  and guides customers. Which one DishNet is, its customers' agent or their supplier, is the question for Starlink's
  written position (§E item 1).
- **The Starlink Installation cap ("the charges paid for it"):** the page summarises Installation Terms v1.0 as
  customers accept it. The fix belongs in v1.1 (§E item 8).
- **Upcountry transport:** the policies allow a delivery charge "if there is one", so they do not contradict the
  marketing. Confirm the practice (§E item 12).
- **"Wherever you are on our coverage map":** rejected in the earlier review; the coverage map is all of Uganda.
- **Flex agreed at the office:** a business decision (§E item 11).
- **"24/7" and "the DishNet app" on other marketing pages** (index, contact, fiber, services, hotspot): outside the
  policy set (§E items 13 and 14).
- **Step 2's "paid in full before delivery":** it describes that quotation, which is the order.

## I. The quotation and invoice templates (repository copies)

The quotation is the document a customer accepts. Before this change it contradicted the new pages. It called itself
"the entire agreement", gave "the manufacturer's warranty only", made equipment "not returnable unless defective on
arrival", deemed work "accepted … unless a defect is reported in writing within 48 hours", and made a refund "the
customer's sole and entire remedy". The changes are text
only, with no Twig construct; clause 2 loses only its last sentence (§J):

- **Clause 1:** the quotation, its T&C and DishNet's published policies form the agreement. Where the quotation is less
  favourable, the policies apply.
- **Step 5:** the 48-hour acceptance sentence is removed.
- **Step 6:** "On a DishNet service plan, DishNet manages the Starlink account" (§J).
- **The header badge** (quotation and invoice): "UCC Authorised Starlink Installer" becomes "Starlink Installation"
  (§J).
- **Clause 2:** the "new or increased government tax … passed on at cost" sentence is deleted (§J). The rest of the
  line, and its Twig, are unchanged.
- **Clause 3:** the service is not paused over an amount the customer disputes while DishNet checks it.
- **Clause 6:** "Handover" — sign-off confirms receipt and takes away no warranty or legal right.
- **Clause 7:** cancel with notice before the next billing date, or pause where Starlink allows it. Started months and
  begun installation are not refundable, except where the law gives a right that cannot be excluded. Equipment can be
  returned within 7 days under the Returns policy, and faulty equipment is always covered.
- **Clause 8:** DishNet's responsibility for outages, degradation or changes on the Starlink network is as its Terms
  of Service set out. The customer follows Starlink's Acceptable Use and Fair Use Policies (starlink.com/legal), no
  longer "Starlink's applicable terms".
- **Clause 9:** "DishNet's installer authorisation is issued by the Uganda Communications Commission, not by
  Starlink" is deleted (§J).
- **Clause 10:** "a refund … is the customer's sole and entire remedy" becomes "and refunds any unused prepaid service
  fees", and the clause opens "Under a DishNet service plan," (§J).
- **Clause 11:** account management, and support of the Starlink account, only under a DishNet service plan (§J).
- **Clause 12:** equipment is covered by DishNet's Warranty Policy (12 months on Starlink kits from delivery) in
  addition to the manufacturer's warranty. The exclusions are the Warranty Policy's, replacing "cover is lost through
  … lightning, power surges, water or weather damage". Loaned equipment is returned in working condition, fair wear
  and tear excepted. Ugandan legal rights are not affected.
- **Clause 13:** suspension or ending for unlawful use as the Terms of Service set out, no longer "immediately and
  without refund".
- **Clause 14** is unchanged, but moved to the top of the third column, so page 2 is no taller than before (§G).
- **Clause 15:** DishNet's responsibility and its limits are as section 14 of the Terms of Service sets out, replacing
  the 3-month cap and the exclusion of "downtime". Nothing limits liability the law does not allow to be limited.
- **Clause 16:** the indemnity covers breach of Starlink's Acceptable Use or Fair Use Policy, no longer "applicable
  network terms", and unlawful use or misuse, no longer any "use" or "activities" (§J).
- **Clause 20:** customer information is handled as the Privacy Policy describes.
- **Clause 21:** complaints as the Complaints page sets out, including the UCC. The governing-law sentence is
  unchanged.
- **Invoice payment terms:** governed by the quotation's T&C and the policies at dishnetuganda.com/policies.html.

**Loading them.** The template in use is `template-v4.zip`, which the operator sent on 6 Oct (docs/07). It is not in
the repository, so it may differ from the repository copy.
- **If uCRM's template is the repository's** (sha256 of `template.html.twig`
  `33e1e6a85c24078a6e85ef20679f3f92ee2ea9c919f22dcedfa10f3a8907e1e6`), upload the new ZIP.
- **Otherwise,** paste every changed line into the live template: everything that changed since commit
  `e0766324` (`git diff e0766324 -- dishnet-hybrid-sudan/ucrm_pdf_templates`). Never paste a single commit's diff:
  these clauses changed in four commits.

Either way, the proof is the next real quotation; never make a test quote for a real customer. The ZIPs are built like
uCRM's exports (`template.html.twig` then `template.css`, deflated, fixed timestamp):

| File | sha256 |
|---|---|
| `template-quotation-uganda-2026-10-12.zip` | `85fcda2383cb188407cc49cde0b608b317cd8a9b56cd402d6fab57911ef6b55c` |
| — its `template.html.twig` | `419eb068abec065e3b72eaab2269396f4eb3fa8ae4a38240b72e94bdca95e543` |
| — its `template.css` (unchanged) | `654e406a5ebccb0fe44b059bf45170890b69f3e8190e1fbb7aedf825748c846e` |
| `template-invoice-uganda-2026-10-12.zip` | `46c1094b1d7c0df8cfe6dcca6c0eb31bb7cafa2be47d72c89b37098bb9bdc661` |
| — its `template.html.twig` | `683f065617b0764ce98474ed6eba11018dcb57753afcb15860ebe3a861e9af08` |
| — its `template.css` (unchanged) | `ef18d1f2b03466c7d3933f915e8d4ba566189c5cecb92525d823b756111dde8b` |

**Deploy the website and load the quotation template together.** A quotation that still says "entire agreement" and
"manufacturer's warranty only" beside a website that promises more is the contradiction this change removes.

The quotation harness required everything outside clause 2 to be byte-identical to its 27 Sep baseline. It now carries
the eighteen changed lines and the clause-14 move as an exact list
(`scripts/harness/quotation-template/tc_changes.php`). Each entry is asserted to occur once, on both sides, and is
set back before the byte comparison, so any other change still fails (§G).

## J. The UCC letter and the operator's decisions (12 Oct, evening)

**The UCC letter is not a licence.** The operator supplied the letter behind DishNet's "UCC-authorised" line: the
Uganda Communications Commission to DishNet Africa Limited, ref. OED/462, 4 September 2026, "Expression of interest to
provide Starlink installation services in Uganda". The Commission "has no objection … provided that the company
follows the applicable regulatory processes and obtains the relevant license/authorisation from the Commission prior
to commencement of the services". It advises DishNet "to apply for and obtain an Equipment Vending and Installation
License" through erp.ucc.co.ug. The letter is addressed to the old Mawanda Road office.

So no page, and neither template, claims a UCC authorisation or licence any more:
- **website:** `about.html`; `why-dishnet.html` (a heading and three meta descriptions); Terms §1; the Terms of Sale
  (the Seller row and §1); the policy hub's "About DishNet";
- **quotation:** the header badge, and clause 9's "installer authorisation is issued by the Uganda Communications
  Commission";
- **invoice:** the header badge.

When the licence is granted, add "UCC-licensed (Equipment Vending and Installation Licence no. …)" to the policy hub
and the Terms of Sale, and restore a badge.

**Still saying it, outside this change.** These are plugin settings and code. A plugin release, and any save of plugin
settings in uCRM, waits for the stray-store quarantine. (The operator changed the three settings by hand on 12 Oct; the
code is 5.18.94, §K.)
- **The customer-portal Terms of Service, which every Uganda customer accepts at sign-in.** This is the most important
  one. `legal.identity` in `dishnet-hybrid-sudan/profiles/uganda.json` reads "an IT solutions provider and
  UCC-authorised Starlink installer …", and `lib/LegalContent.php` prints it as the Terms' first sentence. In the next
  plugin release:
  - drop "UCC-authorised";
  - bump `legal.version.tos` (1.1 → 1.2) and `legal.dated`, so that customers accept the corrected text;
  - update `tests/test_portal_tenant.php`, both the sentence and the version pins.
- **The assistant's identity and the plans flyer.** These are the settings `ai_identity_line` and `wa_flyer_caption`
  (WhatsApp AI setup → Marketing), plus the flyer image if it carries the line.
  `dishnet-hybrid-sudan/docs/UGANDA-EMAIL-LIFECYCLE-AUDIT.md` §5 records that they said "UCC Authorised Starlink
  Installer". The setup screen's example texts (`tabs/engage/wa_ai_setup.php`) suggest the same line; fix them in the
  release.
- **The customer e-mails' badge:** `email_badge_line`, set by `dishnet-hybrid-sudan/tools/set_email_brand.php`.
  Clearing it is not enough, because `EmailTemplate::brand()` falls back to the profile's `email_brand.badge_line`.
  - Now: set a non-empty replacement, such as "Starlink Installation".
  - In the release: fix the profile.

  The profile's `positioning` also carries the line, but nothing reads it.
- **`dishnet-hybrid-sudan/tools/pdf_template_doctor.php`** checks only the quotation for "UCC"; the invoice check looks
  for Uganda, UGX and TIN.
  - A quotation with uCRM tax lines now warns that it lacks "the UCC authorisation line". That warning is expected.
  - A quotation without tax lines passes only on clause 2's "UCC charges". That is a false OK.

  Remove the check in the release.

**Decisions taken on the operator's instruction ("I will go with your advice"):**
1. **The Starlink account.** The pages now describe the business as the operator does:
   - a customer can buy Starlink in their own name, with DishNet advising, helping to order and subscribe, installing
     and supporting;
   - on a DishNet plan, the managed service, DishNet registers and manages the account on the customer's behalf, and
     the contract is with DishNet.

   This is in Terms §3, Terms of Sale §7, and the quotation's step 6 and clauses 10 and 11 ("under a DishNet
   service plan"). The installation-day blog post now describes both models too. For Starlink's
   resale rule, the lower-risk direction is to keep managed accounts in the customer's own name where possible.
   Starlink's written position is still the open question (§E item 1).
2. **Quotation clause 2.** "Any new or increased government tax … may be passed on at cost" is deleted, so the
   website's "nothing is added on top" stands alone. The test that pins clause 2's Twig part is unchanged. The
   harness now asserts that the sentence is gone.
3. **The customer indemnity** stays (quotation clause 16) and is now stated in Terms §12. It is narrowed to three
   things: unlawful use or misuse, the customer's content, and breach of the terms or of Starlink's Acceptable Use or
   Fair Use Policy. Terms §12 reaches as far as clause 16: claims, demands and penalties, including from network
   operators, rights holders and authorities.
4. **Flex.** The 7-day cancellation applies whether the package was agreed at the office or electronically, on the
   same terms as a change-of-mind return under section 2 (Returns §6).
5. **The installation cap.** It never applies to damage to the customer's property from DishNet's negligence, or to
   death or personal injury. This holds for every job, including those accepted under v1.0 (Starlink Installation
   §8). The Warranty Policy (§4) and the Starlink Installation page's own note say the same. Installation Terms v1.1
   (plugin) should say it too.
6. **Marketing.**
   - "The DishNet app" becomes the customer portal on `contact.html`, `fiber.html` and `services.html`.
   - `hotspot.html` no longer promises a live dashboard or "live revenue reports": no zone operates yet. The zone
     agreement will say how an owner sees users, sales and revenue.
   - Support: "24/7" and "any time" become "every day" on `index.html`, `contact.html`, `whatsapp.html` and
     `why-dishnet.html`, with "message any time". The binding pages promise that WhatsApp is answered every day, and
     no person has been confirmed to cover nights. "One tap to a real person" becomes "One tap to our team".
   - No page offers installer "certification" any more (`reseller.html`, `become-a-distributor.html`,
     `careers.html`): DishNet trains, and installing for customers needs any licence the UCC requires.
   - The installation-day blog post describes both account models, as the Terms do.
7. **Complaints.** The page gives UCC's toll-free line, 0800 222 777, taken from the UCC letterhead.
8. **Legal review.** The operator has decided not to commission an advocate's review. This record still says that no
   lawyer has read the pages.
9. **Upcountry delivery.** Unchanged: the policies allow a charge "if there is one".
10. **PDPO.** The operator is registering. Add the number to the Privacy Policy when it is issued.

Page 2 of the quotation now measures 947 px (it was 951 px before 12 Oct), its tallest column 554 px (it was 558 px).
It is still two pages at 108% zoom, the point where the old template already spills. The harness lists 18 changed
lines and the clause-14 move.

A second independent review of this batch upheld 22 of 25 findings. All are applied: the page and template fixes
above, and these corrections to this record.

## K. The plugin release 5.18.94: the customer portal and the Installation Terms (12 Oct, built)

On the operator's "Prepare it now (Recommended)", after the uCRM templates and the three settings were changed by hand
(the assistant's identity line, the flyer caption and the e-mail badge).

**The customer portal's Terms of Service and Privacy Policy (Uganda).** Every Uganda customer accepts these at sign-in.
- They no longer call DishNet "UCC-authorised" (`legal.identity` in `profiles/uganda.json`).
- Their sections now say what the website's Terms of Service and Privacy Policy say. A tenant's profile may now replace
  a section's body (`legal.terms_text.<key>`, `legal.privacy_text.<key>`; the keys are listed by
  `dnLegalSectionKeys()` in `lib/LegalContent.php`). The headings never change. Uganda replaces ten Terms sections
  and five Privacy sections. South Sudan replaces none and renders byte for byte as before.
- Among the corrections:
  - no "best-effort" service;
  - no discretionary outage credit: the website's credit after 3 days applies;
  - no late-payment or reconnection fee;
  - no suspension "without further notice";
  - a monthly plan ends at any time, a Flex package follows its own agreement, and an Acceptable Use ending comes
    only after a request to stop;
  - the WiFi passwords, call recordings, the six-year and two-year retention periods, and the PDPO.
- Both documents become version **1.2**, so every Uganda customer is asked to accept once more at the next sign-in.
  `legal.dated` is the go-live day. The consent step now reads "We ask again only when they change." instead of
  "This is a one-time step."

**Installation Terms v1.1.** New requests take v1.1; v1.0 stays in the code byte for byte, because records name it.
v1.1 is v1.0 with seven changes, and the test asserts that nothing else differs:
1. no "DRAFT — SUBJECT TO LEGAL REVIEW" line (item 8 of §J);
2. §12 keeps any cancellation right the law gives;
3. §14 adds the website's six-month workmanship promise;
4. §15 says sign-off takes away no right;
5. §23 says what the completion photos and location are ("normally", and only where a location can be recorded);
6. §24 names v1.1;
7. §25 keeps damage to the customer's property from negligence, death or injury, and fraud outside the cap (item 5).

A request sent under v1.0 before the deploy keeps v1.0: its page shows v1.0, accepts only v1.0's hash, and a resend
keeps v1.0. Staff who want the customer to see v1.1 withdraw it and request again. The test proves each step (case 29b).

**The UCC defaults.** The e-mail badge preset (`tools/set_email_brand.php`) and the profile's badge become
"Starlink Installation". On Uganda, the WhatsApp AI setup screen's examples no longer suggest the UCC line (South
Sudan's page is unchanged, byte for byte), and `tools/pdf_template_doctor.php` no longer looks for "UCC" in a quotation.

**The website, in step.** The Privacy Policy's Payments row now says when the WiFi password is kept (including a
change made in the customer portal). The Communications row adds call recordings "where a call is recorded", and
retention adds "Call recordings, where a call is recorded: up to 12 months". The Starlink Installation page says the
technician "normally" photographs the work, and records the location "where a location can be recorded". These need
the website redeployed.
