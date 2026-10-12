# 67 — Uganda website policies: warranty, returns, terms, privacy, complaints (12 Oct 2026)

**Status: BUILT on the development branch, reviewed adversarially (§H), NOT DEPLOYED, NOT LEGALLY REVIEWED.** The
site is deployed by the operator (EasyPanel, `dishnet-web-uganda/`), never from this session. The quotation and invoice
templates changed with it (§I) and are loaded into uCRM by staff. **Every legal point behind these pages comes from
search results, not from the law itself:** this session could not open ulii.org, ucc.co.ug, pdpo.go.ug, ura.go.ug or
starlink.com. A Ugandan advocate should read §D, §E and §I before the pages go live.

The operator asked for warranty, returns and other policies for the Uganda website. They should follow how Starlink
handles these in Uganda and how others do it, and be written "so we don't get any legal challenges".

## A. What changed on the site

| | |
|---|---|
| New pages | `policies.html` (the hub), `terms-of-sale.html`, `warranty.html`, `returns.html`, `installation.html` (*Starlink Installation*), `acceptable-use.html`, `complaints.html` |
| Rewritten | `terms.html` (now *Terms of Service*), `privacy.html` (*Privacy Policy*) |
| Every page's footer | "Terms of Use" becomes "Terms of Service"; *Warranty*, *Returns & Refunds* and *All Policies* added after the last legal link. All 58 pages; `build.py` now checks every page for them |
| `faq.html` | Two questions added (warranty; returns and refunds). The answers on availability, moving, support and plan mapping are corrected (§H). The missing structured-data entry is added. The FAQPage data now carries all 16 questions word for word |
| `about.html` | "an official Starlink reseller and installer" becomes "experienced Starlink installers"; "As a UCC-authorised Starlink installer, we install Starlink anywhere in the country" |
| `starlink-kits.html` | "certified technicians" becomes "trained DishNet technicians". "No contract — change or pause any month" now says plans change from the next billing month, pausing works where Starlink allows it, and Flex has its own agreement. Warranty and returns links |
| `shop.html` | one sentence linking the warranty and returns pages |
| `why-dishnet.html`, `guides.html`, `whatsapp.html` | "certified" becomes "UCC-authorised"; "real humans / real people, 24/7" becomes "any time, and you can ask for a person" (an automated assistant answers first) |
| `sitemap.xml` | the seven new pages (57 → 64 URLs) |
| `policies/` (new, not served) | `build.py` and `bodies/*.html`: the nine pages' source; see §C |

No price, no currency figure and no section number of any Act appears on any page. The pages name the laws they rely
on: the Sale of Goods and Supply of Services Act, the Electronic Transactions Act, the Data Protection and Privacy Act,
the Computer Misuse Act, the Hire Purchase Act. They also name the Uganda Communications Commission and the Personal
Data Protection Office.

The identity line is the operator's own, from `dishnet-hybrid-sudan/profiles/uganda.json` and quotation clause 9:
"an IT solutions provider and UCC-authorised Starlink installer, registered in Uganda (Reg. No. 80046255496181, TIN
1059140632) … not an agent, partner, franchisee, affiliate or representative of Starlink or SpaceX … installer
authorisation is issued by the Uganda Communications Commission, not by Starlink".

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
| Flex packages bought electronically | cancellable **within 7 days of receiving the kit** | The Act's "related credit agreement" |
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
   operator's own wording instead: independent company; UCC-authorised Starlink installer; not an agent or partner of
   Starlink. What the pages cannot change is what DishNet actually does:
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
   installing and supporting for a fee, is the low-risk model; it is not what the documents describe today.
2. **The UCC authorisation's scope.** DishNet holds a UCC installer authorisation (the operator's records and quotation
   clause 9). Whether that also covers *providing and billing* internet service, which the UCC licenses separately, is
   NOT established. Read the certificate. If it covers service provision too, add its category and number to
   `policies.html`. UCC began a crackdown on unlicensed public WiFi in July 2026 (news, unverified). The pages now say
   that selling public WiFi needs any licence UCC requires.
3. **PDPO registration.** Registration is reported as mandatory, renewed yearly. The Privacy Policy claims none;
   **register, then add the number.**
4. **Type approval and vendor authorisation** for equipment sold (UCC). The pages claim none.
5. **Flex may be hire purchase.** The pages now say Flex follows its own signed agreement and the Hire Purchase Act
   where it applies, and can be cancelled within 7 days when agreed electronically. Get an advocate's view before any
   Flex agreement is used. The knowledge base already says Flex is quoted, not signed, until management confirms.
6. **"Unlimited" and speed claims elsewhere** (`fiber.html`, the FAQ's speed range) are unchanged marketing. The
   fair-use section keeps fiber uncapped and says Starlink plans are network-managed.
7. **The customer-portal terms (v1.1, `lib/LegalContent.php` from `profiles/uganda.json`) differ from the website:**
   - "suspension without further notice" — the same in effect as the website's automatic pause;
   - "leased equipment returned within 14 days" — quotation clause 12 says 7;
   - "credit for extended outages at our discretion" — the website gives a 3-day service credit.

   The website's Terms of Service now say that where the portal terms, a quotation or an invoice differ, the version
   better for the customer applies. Aligning the portal text needs a plugin release (bump `legal.version.tos`, so every
   customer re-accepts). **Not done here.**
8. **Installation Terms v1.0 still shows "DRAFT — SUBJECT TO LEGAL REVIEW"** to every customer who opens the
   acceptance link. The Starlink Installation page summarises that text. Decide, after the legal review, whether to
   release v1.1 without that line. Proposed for v1.1:
   - take property damage from negligence, and death or injury, out of the §25 cap;
   - add the installation photos and completion location to §23.

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
  - the FAQ, kits and Terms of Sale pages repeat the policy numbers correctly.

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
- **The merchant ID** appears exactly as often as before (faq.html 4 lines, pay.html 9, get-the-app.html 1,
  why-dishnet.html 1). None was added.
- **Templates (§I):** `test_quote_tax_line.php` 31/0, `test_kit_tax_note.php` 68/0, `test_invoice_template_links.php`
  21/0.
- **The quotation harness** `scripts/harness/quotation-template/rehearse.sh` passes 35/0 on Twig 3 and Twig 2, and
  REHEARSAL 8 ok / 0 failed. Its controls:
  - a template with one extra, unlisted wording change fails (8 failed);
  - a template with a listed change only half made fails (16 failed);
  - before the harness learned the changes, the edited template failed it (8 failed), so the guard bites.
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
- **Contract:** it is with DishNet. DishNet manages the Starlink account.
- **Identity:** independence plus the UCC-installer line.
- **Taxes:** all included, nothing added.
- **Installation Terms:** they cover Starlink jobs only.
- **Warranty:** it is DishNet's own.
- **Remedies:** "significant inconvenience" is the test.
- **Refund clock:** set by the Electronic Transactions Act.
- **Starlink's terms:** not part of DishNet's contract.
- **Flex:** follows its own agreement and the Hire Purchase Act.
- **Privacy:** named the Privacy Policy, with no claim of compliance or registration, and marketing stated as it is
  done.
- **UCC:** reached through ucc.co.ug, with no phone number until staff confirm one.
- **Precedence:** where documents differ, the version better for the customer applies.

Five editors applied the findings, each owning its own files, and a skeptic per group checked the text against the
findings and the decisions. A last reading by hand made a few small corrections: one repeated phrase, the word "pause"
used consistently on the Privacy and Complaints pages, and the "certified" and "real 24/7" lines on `why-dishnet.html`
that no finding named.

## I. The quotation and invoice templates (repository copies)

The quotation is the document a customer accepts. Before this change it contradicted the new pages. It called itself
"the entire agreement", gave "the manufacturer's warranty only", made equipment "not returnable unless defective on
arrival", deemed work "accepted … unless a defect is reported in writing within 48 hours", and made a refund "the
customer's sole and entire remedy". The changes, text only, with no Twig construct and clause 2 untouched:

- **Clause 1:** the quotation, its T&C and DishNet's published policies form the agreement. Where the quotation is less
  favourable, the policies apply.
- **Step 5:** the 48-hour acceptance sentence is removed.
- **Clause 6:** "Handover" — sign-off confirms receipt and takes away no warranty or legal right.
- **Clause 7:** started months and begun installation are not refundable, except where the law gives a right that
  cannot be excluded. Equipment can be returned within 7 days under the Returns policy, and faulty equipment is always
  covered.
- **Clause 10:** "a refund … is the customer's sole and entire remedy" becomes "and refunds any unused prepaid service
  fees".
- **Clause 12:** equipment is covered by DishNet's Warranty Policy (12 months on Starlink kits from delivery) in
  addition to the manufacturer's warranty, and Ugandan legal rights are not affected.
- **Clause 15:** nothing limits liability the law does not allow to be limited.
- **Invoice payment terms:** governed by the quotation's T&C and the policies at dishnetuganda.com/policies.html.

**Loading them.** The template in use is `template-v4.zip`, which the operator sent on 6 Oct (docs/07). It is not in
the repository, so it may differ from the repository copy.
- **If uCRM's template is the repository's** (sha256 of `template.html.twig`
  `33e1e6a85c24078a6e85ef20679f3f92ee2ea9c919f22dcedfa10f3a8907e1e6`), upload the new ZIP.
- **Otherwise,** paste the changed clauses (the diff of this commit) into the live template.

Either way, the proof is the next real quotation; never make a test quote for a real customer. The ZIPs are built like
uCRM's exports (`template.html.twig` then `template.css`, deflated, fixed timestamp):

| File | sha256 |
|---|---|
| `template-quotation-uganda-2026-10-12.zip` | `ae0197b976bbaa5129abb2d499df98b81c63c66c45c5a5502786659c6a690bfc` |
| — its `template.html.twig` | `d127084dfad5281e8c7125f59b5e14e8a0ebae2d0ff217f416b6836319618d46` |
| — its `template.css` (unchanged) | `654e406a5ebccb0fe44b059bf45170890b69f3e8190e1fbb7aedf825748c846e` |
| `template-invoice-uganda-2026-10-12.zip` | `bfdae8731de709b3994277084bef36b13f9652fbb19d34e5c0e517eb40012217` |
| — its `template.html.twig` | `8801d7693be163df5ea960883e7dc47f39fc98b66c24e4e86824ca4b1ea5c65f` |
| — its `template.css` (unchanged) | `ef18d1f2b03466c7d3933f915e8d4ba566189c5cecb92525d823b756111dde8b` |

**Deploy the website and load the quotation template together.** A quotation that still says "entire agreement" and
"manufacturer's warranty only" beside a website that promises more is the contradiction this change removes.

The quotation harness required everything outside clause 2 to be byte-identical to its 27 Sep baseline. It now carries
the seven changed lines as an exact list (`scripts/harness/quotation-template/tc_changes.php`). Each line is asserted
to occur once, on both sides, and is set back before the byte comparison, so any other change still fails (§G).
