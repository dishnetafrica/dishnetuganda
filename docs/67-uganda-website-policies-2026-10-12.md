# 67 — Uganda website policies: warranty, returns, terms, privacy, complaints (12 Oct 2026)

**Status: BUILT on the development branch, NOT DEPLOYED, NOT LEGALLY REVIEWED.** The site is deployed by the operator
(EasyPanel, `dishnet-web-uganda/`), never from this session. **Every legal point behind these pages comes from search
results, not from the law itself:** this session could not open ulii.org, ucc.co.ug, pdpo.go.ug, ura.go.ug or
starlink.com. A Ugandan advocate should read §D and §E before the pages go live.

The operator asked for warranty, returns and other policies for the Uganda website. They should follow how Starlink
handles these in Uganda and how others do it, and be written "so we don't get any legal challenges".

## A. What changed on the site

| | |
|---|---|
| New pages | `policies.html` (the hub), `terms-of-sale.html`, `warranty.html`, `returns.html`, `installation.html`, `acceptable-use.html`, `complaints.html` |
| Rewritten | `terms.html` (now *Terms of Service*), `privacy.html` (now *Privacy Notice*) |
| Every page's footer | "Terms of Use" becomes "Terms of Service"; *Warranty*, *Returns & Refunds* and *All Policies* added after the last legal link (58 pages, each asserted) |
| `faq.html` | two questions added (*What warranty do I get?*, *Can I return something or get a refund?*), and the moved-kit answer now says that Starlink capacity at the new address is checked first. The FAQPage structured data carries the same three changes |
| `starlink-kits.html`, `shop.html` | one sentence each, linking the warranty and returns pages |
| `sitemap.xml` | the seven new pages (57 → 64 URLs) |
| `policies/` (new, not served) | `build.py` and `bodies/*.html`: the nine pages' source; see §C |

No price, no currency figure and no section number of any Act appears on any page. Each page names the laws it relies
on: the Sale of Goods and Supply of Services Act, the Electronic Transactions Act, the Data Protection and Privacy Act,
2019, the Computer Misuse Act, and the Uganda Communications Commission. The Contracts Act is respected but not named.

## B. The promises the pages make — business decisions, chosen as defaults

Every number lives once, in `PARAMS` in `policies/build.py`. **Each is a business decision, taken here as a default
that the operator should confirm or change:**

| Promise | Default | Why this default |
|---|---|---|
| Starlink kit warranty | **12 months from the day the customer receives the kit**; DishNet handles the claim with Starlink | Starlink's limited warranty for a Standard kit outside the EU/UK is reported as 12 months, but **from Starlink's own sale**. DishNet counts from its customer's receipt, so DishNet carries the gap between the two dates |
| Installation workmanship | **6 months** from completion | No peer benchmark was found; the old terms promised a workmanship warranty with no period |
| Other equipment | the manufacturer's period on the invoice, else **6 months** | Covers mounts, routers, CCTV, firewalls |
| Faulty on arrival or within 7 days | **replacement or full refund, the customer's choice** | In line with the right to reject goods that are not of satisfactory quality |
| Change-of-mind return | **7 days from receipt, for every customer** (also at the office); item + delivery refunded, customer pays the direct return cost; installation and service already used not refunded | The Electronic Transactions Act is reported to give 7 days for electronic sales, with only the direct cost of return chargeable. Peer norm: Jumia Uganda 7–15 days; Starlink direct 30 days |
| Refunds | **within 14 days**, never later than 30; same method; full VAT-inclusive amount with a credit note; no fees | The Act is reported to require 30 days at most |
| Unpaid bill | suspension only **7 days after the due date and after a written reminder**; never over a disputed amount while the undisputed part is paid | The peer (Liquid Home Uganda) gives written notice and 7 days to remedy |
| Price or adverse change | **30 days' notice**, with the right to cancel at no charge | Starlink reportedly gives one month for adverse changes |
| Service credit | a credit for each full day without service after **3 days** in a row following a reported fault, unless the cause is the customer's power, obstruction, damage, an unpaid bill, etc. | New commitment |
| Liability cap | outside what cannot be limited: **the charges for the affected service in the 3 months before the failure** | Was "fees paid for the affected service period" |
| Complaints | reference and reply within **1 working day**; aim to resolve in 7 days; **final written answer within 14 days**; then UCC, the Personal Data Protection Office, or the courts | No operator deadline could be established; UCC's own target for escalated complaints is reported as 30 working days |
| Privacy requests | reply **within 14 days** | The Act is reported to give 14 days for objections and direct-marketing requests |
| Records | up to **6 years** after the account ends; enquiries without an order **2 years** | The Limitation Act period for contract claims is reported as six years |

## C. How to change a page

1. Edit `dishnet-web-uganda/policies/bodies/<page>.html`, or a number in `PARAMS` in `policies/build.py`.
2. Run `python3 dishnet-web-uganda/policies/build.py`. It rebuilds the nine pages from `terms.html`'s own head,
   header and footer. It refuses to finish if the FAQ or the kits page repeats a number that no longer matches;
   change that sentence by hand, then run it again.
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

1. **"Official Starlink reseller."** `about.html` says DishNet *became an official Starlink reseller and installer*.
   - Nothing found shows DishNet on a Starlink list, and DishNet's own notes say its status must still be asked of
     Starlink (dishnet-hybrid-sudan/docs/UGANDA-STARLINK-INSTALLATION-RESEARCH.md §10–11).
   - A 10 Sep 2026 kit invoice from Starlink Global Internet Services Ltd suggests DishNet buys at retail
     (UGANDA-VAT-CONFIGURATION.md).
   - Starlink reportedly calls buying through an unlisted third party a breach of its terms.
   - The Sudan site's own verifier already bans the phrase.
   - **Hold written authorisation, or reword that sentence.** The new pages make no such claim. They say only that
     DishNet is not part of Starlink or SpaceX.
2. **Reselling Starlink service.** DishNet bills monthly plans that run on Starlink, sells WiFi-zone hotspots, and
   recruits resellers and distributors. Starlink reportedly forbids resale without authorisation, and allows Residential
   plans for household use only. **Get Starlink's written position.** The new terms already say that home plans are
   for household use, and that business and public-WiFi use needs a business plan or a written agreement.
3. **UCC licence.** Whether DishNet needs its own UCC licence to bill internet plans and run hotspots is NOT
   established, and the July 2026 crackdown targets unlicensed public WiFi. **Ask an advocate.** The pages claim no
   licence; once one is held, its number belongs on `policies.html`.
4. **PDPO registration.** Registration is reported as mandatory, renewed yearly. The Privacy Notice claims no
   registration; **register, then add the number.**
5. **Type approval and vendor authorisation** for the equipment sold (UCC). The pages claim none.
6. **Flex packages may be hire purchase.** If so, the Hire Purchase Act's written-agreement, notice and repossession
   rules apply. The pages leave Flex to its own written agreement. The knowledge base already says that Flex is quoted,
   not signed, until management confirms.
7. **"Unlimited" and speed claims elsewhere on the site** (`fiber.html` "No 'fair usage' surprises", the FAQ's speed
   range). The new fair-use section keeps fiber uncapped and says Starlink plans are network-managed; the marketing
   pages were not changed.
8. **The facts that only DishNet can supply:**
   - its registration number and TIN, if it wants them shown;
   - whether the Uganda entity is DishNet Africa Limited itself, or a branch;
   - whether `info@dishnetafrica.com` is monitored for Uganda.

## F. New operational commitments the pages create

The pages promise these, so the business must do them from the day the pages go live:
- **Quotations:** a written quotation before payment, listing installation and internet service on separate lines.
- **Payments:** an invoice or receipt for every payment.
- **Unpaid bills:** a written reminder before suspension, and no suspension over a disputed amount.
- **Refunds:** within 14 days, by the paying method, with a credit note.
- **Complaints:** a reference and a reply within 1 working day; the final answer in writing within 14 days; a
  complaint log.
- **Warranty claims:** a reply within 1 working day.
- **Marketing:** a working STOP in every marketing message.
- **Records:** enquiries without an order deleted after 2 years.
- **Starlink consent:** consent for sharing activation details with Starlink, asked at order time.
- **Notice:** 30 days' notice of adverse price or term changes.
- **Service credits:** after 3 days without service.

## G. Results

- `policies/build.py --check`: up to date.
- `verify-address.py`: *The site agrees with itself*.
- A file-based port of `verify-site.sh`'s static checks (nginx is not installed here):
  - links: 75 references, 0 broken;
  - sitemap: 64 URLs, all resolving;
  - no price figure;
  - one WhatsApp number and the allowed portal URLs.

  The same port passes on the committed site, the baseline. It catches a planted price, a planted broken link and a
  planted broken in-page anchor (the controls).
- `stamp-assets.sh --check`: unchanged; no stylesheet was edited. The policy pages carry their own `<style>` block.
