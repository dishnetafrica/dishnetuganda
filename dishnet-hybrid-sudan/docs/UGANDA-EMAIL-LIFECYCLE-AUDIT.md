# DishNet Uganda — Customer Email Lifecycle Audit
**Date:** 2026-09-08 · **Scope:** every customer-facing email the platform can send
**Verdict:** 🔴 **NOT READY** for a Uganda customer. Infrastructure is ready; content is not.

---

## 0. The finding that reframes everything

DishNet's customer communication is **WhatsApp-first, and WhatsApp is already good.**
`tabs/engage/whatsapp.php` defines **13 customer-facing notification events** that are
built, configurable, and wired to crons:

| Event | Label | Trigger |
|---|---|---|
| ops_kyc_customer_welcome | Customer Welcome | after CRM record created |
| ops_install_confirmed | Installation Confirmed | scheduling flow |
| ops_invoice_created | Invoice Created | invoice cron |
| ops_payment_received | Payment Received | payment collected |
| ops_pre_due_d7 / d3 / d1 | Due in 7 / 3 / 1 days | pre-due cron |
| ops_overdue_d1 / d3 / d5 | Overdue +1 / +3 / +5 | overdue cron |
| ops_low_balance | Low Balance Warning | balance check |
| ops_outage_alert | Planned Maintenance | admin bulk-send |
| ops_renewal_reminder | Renewal Reminder | 5 days before period |

Plus invoice **PDF delivery over WhatsApp** (`cron_invoice_notify.php`).

**Therefore the goal is NOT to build 40 email templates.** It is to build the small set
of emails that email does better than WhatsApp — documents, credentials, formal notices —
and let WhatsApp keep the conversational lifecycle. Duplicating all 13 events into email
would be the "over-emailing" failure the brief warns about.

---

## 1. Audit of every existing template

### 1.1 `ucrm_email_templates/notification_invoice_new.html.twig` (206 lines)
- **When/Who/Trigger:** new invoice → customer → **uCRM's own mailer**, not the plugin
- **Contains:** invoice number, amount, due date, pay link
- **Missing:** what the charge is *for*, plan name, service period, Uganda contacts
- **Design:** professional · **Mobile:** ✗ no `@media` · **Dark mode:** ✗
- **Branding:** 🔴 "DishNet Africa", 1 Sudan reference, **0 Uganda references**
- **CTA:** clear (Pay) · **Type:** billing/transactional
- **Production-ready:** ❌ — and see §4: it may not send at all
- **Change:** Uganda rebrand, add plan/period, add `@media`, verify send path

### 1.2 `ucrm_email_templates/notification_invoice_overdue.html.twig` (191 lines)
- Same path/owner as above. **Has `@media` ✓** (only template that does)
- 🔴 Prints **+211 921 443 002** — a South Sudan number — to a Uganda customer
- Assumes postpaid dunning; Uganda is prepaid. **Production-ready:** ❌

### 1.3 `ucrm_email_templates/notification_payment_received.html.twig` (170 lines)
- Payment receipt → customer → uCRM mailer
- **Missing:** what period the payment covers, next due date, remaining balance
- Sudan-branded, no `@media`. **Production-ready:** ❌

### 1.4 `templates/quote_email.twig` (25 lines)
- 🟠 **ORPHANED** — no code references it. Dead file.

### 1.5 `templates/quote_dishnet.html` (154 lines)
- Used by `lib/QuotePdfService.php` as a **PDF body**, not an email
- Loads a **remote Google Fonts stylesheet** — will not render in most mail clients
- Sudan-branded. **Not an email template.**

### 1.6 `templates/delivery_starlink.html` / `delivery_fiber.html`
- 🟠 **ORPHANED** — nothing references them. Sudan-branded, no viewport, no `@media`.

### 1.7 `lib/OtpEmailTemplate.php` (login code email) — **the only polished one**
- **Trigger:** customer requests portal/app login → `api_customer_app.php:485`
- Has viewport, clean layout, brand red `#D41C1C`
- 🔴 Footer: **"DishNet Africa Ltd. · Juba, South Sudan"**, `dishnetafrica.com`, support **+211 921 443 009**
- 🔴 `Reply-To: info@dishnetafrica.com` is **hardcoded** at `api_customer_app.php:486`
- **Production-ready:** ❌ for Uganda (mechanically fine, geographically wrong)

### 1.8 `lib/OverdueDunningHelpers.php` — 9-stage dunning ladder (email + WhatsApp)
- Stages at **14, 31, 45, 61, 75, 90, 120, 180, 210+** days overdue
- 🔴 Copy states *"your DishNet service has been temporarily suspended"* — a **postpaid**
  model. Uganda sells **prepaid**: service pauses at period end, there is no 14-day
  grace or 210-day debt chase. **Wrong business model end to end.**
- Sends via raw SMTP in `_sendEmail()`, **bypassing MailService** — so it does not use
  the Brevo configuration we just set up. **NOT VERIFIED** that it can send at all now.

### 1.9 `lib/QuotationService::emailQuotePdf` — quotation email (built today)
- **Trigger:** quote created for a CRM client with `quote_email_via_plugin` on
- Uganda-aware (uses `quote_company_email`), PDF attached, reply-or-pay wording
- 🟡 Plain inline HTML — no letterhead, no brand colour, no logo
- **Production-ready:** ⚠️ functionally yes, visually not on-brand

---

## 2. What a Uganda customer would NOT know

| Question | Answered today? |
|---|---|
| Who is DishNet (Uganda)? | ❌ emails say Juba, South Sudan |
| What exactly did I buy? | ❌ invoice shows amount, not plan/period |
| How much, and when is my next payment? | ⚠️ amount yes, next date no |
| How do I pay? | ⚠️ bank details on the PDF; not in email body |
| What happens after I pay? | ❌ nothing explains activation |
| When is installation? | ❌ no installation email exists |
| Who do I contact for support? | 🔴 a **+211 South Sudan** number |
| What if the service goes down? | ❌ no email path |
| What if I pay late? | 🔴 wrong (suspension/dunning ≠ prepaid pause) |
| Where is my account / portal? | ⚠️ only inside the OTP email |
| What is included / excluded? | ❌ only on the quotation PDF page 2 |
| Terms? | ⚠️ quotation PDF only |

---

## 3. Recommended lifecycle (channel-assigned)

```
LEAD ──WhatsApp AI (live)──────────────────────────────► ENQUIRY
  │                                                        │
  ▼ quote created                                          ▼
QUOTE ──📧 EMAIL: quotation + PDF (exists) + 📱 WA ping──► customer reviews
  │
  ▼ customer pays / confirms
ORDER ──📧 EMAIL: order confirmation + what happens next──► MISSING
  │
  ▼ payment received
PAYMENT ──📧 EMAIL receipt + 📱 WA (ops_payment_received)─► partially exists
  │
  ▼ install scheduled
INSTALL ──📱 WA (ops_install_confirmed) + 📧 EMAIL──────► email MISSING
  │
  ▼ technician completes, service live
ACTIVATION ──📧 EMAIL: welcome pack (biggest gap)───────► MISSING
  │   plan, price, next billing date, how to pay,
  │   support numbers, portal login, what's included
  ▼
ACTIVE ──📱 WA pre-due d7/d3/d1 ──📧 EMAIL invoice+PDF──► partially exists
  │
  ▼ unpaid at period end
PAUSE ──📧 EMAIL: service paused, how to resume────────► WRONG MODEL today
  │
  ▼
SUPPORT / OUTAGE ──📱 WA primary, 📧 EMAIL for major───► email MISSING
  │
  ▼
RENEWAL / UPGRADE / CANCELLATION ──📱 WA + 📧 confirm──► MISSING
```

**Trigger → Email → Customer action → Next stage** for the emails that must exist:

| Stage | Trigger | Email | Customer does | Next |
|---|---|---|---|---|
| Quote | quote created in uCRM | Quotation + PDF | reads, pays or replies | Order |
| Order | payment/confirmation received | Order confirmed + next steps | waits for scheduling | Install |
| Install | date scheduled | Installation scheduled | confirms availability | Activation |
| Activation | service marked active | **Welcome pack** | saves details, logs in | Active |
| Billing | invoice issued | Invoice + PDF + how to pay | pays | Active |
| Payment | payment recorded | Receipt + period covered + next due | nothing | Active |
| Pause | period ends unpaid | Service paused + resume steps | pays | Active |
| Login | portal login requested | OTP code (exists) | logs in | — |

---

## 4. Technical audit

| Item | Status |
|---|---|
| SPF | ✅ `v=spf1 mx include:spf.brevo.com -all` |
| DKIM (Brevo path) | ✅ brevo1/brevo2._domainkey authenticated |
| DKIM (server's own) | 🔴 **never published** — direct sends unsigned |
| DMARC | ✅ `p=quarantine; rua=mailto:dmarc@dishnetuganda.com` |
| From address | ✅ `accounts@dishnetuganda.com` verified end-to-end |
| Reply-To | 🔴 hardcoded `info@dishnetafrica.com` in the OTP path |
| SMTP config | ✅ Brevo relay, `SETUP: PASS`, real delivery confirmed |
| MIME structure | ✅ multipart/alternative; multipart/mixed with attachments |
| Plain-text fallback | ⚠️ auto-stripped from HTML when not supplied |
| Unsubscribe header | ❌ absent (fine for transactional, **required** if marketing) |
| Absolute image URLs | ⚠️ no images used at all — no logo in any email |
| Mobile responsive | 🔴 1 of 7 templates has `@media` |
| Dark mode | 🔴 none |
| Outlook / Gmail / Apple Mail | **NOT VERIFIED** — no client testing performed |
| Tracking links | ✅ none used |
| Customer data exposure | ✅ none found in logs (`quote_mail.log` stores address only) |
| Does uCRM still send its 3 templates? | 🔴 **NOT VERIFIED** — plugin was switched to plugin-only mail; uCRM's own mailer state unknown |
| Does the dunning cron still send? | 🔴 **NOT VERIFIED** — it uses raw SMTP, not MailService |

---

## 5. Branding audit

| Element | Status |
|---|---|
| Logo | ❌ no image logo in any email (text wordmark only) |
| Brand red `#D41C1C` | ✅ in OTP, quote, delivery templates |
| Typography | ⚠️ inconsistent; one template pulls remote Google Fonts (won't load) |
| Footer | 🔴 Sudan address in every template |
| Phone | 🔴 +211 numbers; Uganda is +256 705 993 348 |
| WhatsApp link | 🔴 wa.me/211921443009 |
| Website | 🔴 dishnetafrica.com; should be dishnetuganda.com |
| Company/legal | ❌ no TIN 1059140632 / Reg 80046255496181 (present on PDFs only) |
| UCC wording | ❌ absent from every email |
| Starlink wording | ⚠️ present only in orphaned templates; **no false "Starlink Partner" claim found** ✅ |

Approved wording already in the project (quotation T&C clause 9) — reuse verbatim:
> "DishNet Africa Ltd is an independent Ugandan company. It is not an agent, partner,
> franchisee, affiliate, or representative of Starlink or SpaceX… DishNet's installer
> authorisation is issued by the Uganda Communications Commission, not by Starlink."

Email footer line: **"UCC Authorised Starlink Installer"** ✅ (matches the flyer and the AI identity line).

---

## 6. Recommended communication strategy

### MUST HAVE (8 emails — cannot onboard a customer without these)
1. Quotation + PDF *(exists, needs letterhead)*
2. Order confirmed / payment received receipt
3. Installation scheduled
4. **Welcome pack / service activated** ← the single biggest gap
5. Invoice issued + PDF + how to pay
6. Service paused — how to resume *(prepaid wording, replaces dunning)*
7. Login / OTP code *(exists, needs Uganda footer)*
8. Support ticket acknowledgement *(if tickets are used with email)*

### SHOULD HAVE (4)
9. Pre-due reminder (email copy of the WA d3 only — not all three)
10. Major outage / planned maintenance (email only for multi-hour events)
11. Plan change confirmation
12. Cancellation confirmation

### OPTIONAL (marketing — needs unsubscribe + consent)
13. Referral programme · 14. Upgrade offers · 15. Win-back

### DO NOT SEND
- ❌ Email copies of all 13 WhatsApp events (duplication)
- ❌ The 9-stage dunning ladder (wrong model for prepaid)
- ❌ Anniversary / newsletter emails at this stage
- ❌ Separate "enquiry received" email — the WhatsApp AI already answers instantly

**Net: 8 must + 4 should = 12 email types, not 46.**

---

## 7. Implementation plan (smallest practical set)

**Phase 1 — Foundation (do first)**
1. `lib/EmailTemplate.php` — one Uganda-branded shell (header, red rule, footer with
   +256 705 993 348, dishnetuganda.com, TIN, UCC line) with `@media` + dark-mode tokens
2. Fix the hardcoded `Reply-To` and the OTP footer
3. Verify (or replace) the uCRM notification send path and the dunning cron's SMTP

**Phase 2 — The 8 MUST templates** through that shell, all data-driven from uCRM

**Phase 3 — `/email-preview` route** (admin-only) rendering every template with sample
data, so any of them can be inspected without sending

**Phase 4 — End-to-end lifecycle test** `accounts@dishnetuganda.com` →
`bhavin@dishnetafrica.com`, one email per stage, each subject prefixed `[TEST]`

**Phase 5 — Retire** orphaned templates; rewrite dunning as prepaid pause/resume
