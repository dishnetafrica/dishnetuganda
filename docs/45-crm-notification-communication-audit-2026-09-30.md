# 45 — Notifications and bulk communication: uCRM and the DishNet plugin (audit)

30 September 2026. **Audit only.** No code, configuration, schedule or record was changed. Nothing was deployed. No
message, e-mail or test was sent to anyone. **Nothing below is authorised to build or to switch; every proposal in §6
waits for your review.**

**What was audited.** The Uganda install: plugin **5.18.53** (commit `6b71ea6`, live since 30 Sep 06:33 UTC) on uCRM
**4.5.33** / UISP **3.0.159**. South Sudan is mentioned only where the same code behaves differently there.

**Release A and Release B.** Nothing here touches Release A (5.18.50) and nothing starts Release B. docs/44 uses
"Release B" for 5.18.52, the job messages, which is already deployed. If you meant a different Release B, nothing here
starts that either. Every proposal in §6 is separate work that needs its own approval.

---

## Where the facts come from

Every claim carries one of the five labels you asked for. In the tables they are shortened as below.

| Tag | Your label | What counts as evidence here |
|---|---|---|
| **Code** | Implemented in code | Read in plugin 5.18.53 at the `file:line` given (paths are under `dishnet-hybrid-sudan/`). uCRM is closed source, so no uCRM feature can carry this label |
| **Config** | Enabled in configuration | A dated reading of the setting **on the Uganda server**, recorded in this repository. A code default is not configuration |
| **Tested** | Tested successfully | A named test in the plugin suite, which last passed twice on `6b71ea6` (227 files, 10,764 assertions, 0 failed, docs/44 §16.29), or a recorded controlled test |
| **Prod** | Confirmed working in production | A dated observation, on the Uganda server, of that path doing its job for a real event |
| **NV** | Not verified | None of the above. Each NV says what would verify it (§8) |

**Three limits on this audit:**
- **This session cannot reach the Uganda server.** It has no SSH access and no credentials. Every server fact below
  is one that an earlier run recorded, with its date.
- **uCRM's documentation could not be read.** help.uisp.com and the API reference (unmscrm.docs.apiary.io) are both
  refused by this session's network policy (tried 30 Sep). Nothing in this repository was ever taken from Ubiquiti's
  documentation on these subjects either.
  - So what uCRM itself can do is marked **PK** (product knowledge): what UISP CRM is generally known to offer, never
    observed on this server and not confirmed for 4.5.33.
  - Your instruction applies to every PK line: *"Do not assume a feature exists merely because it appears in the CRM
    interface."* A PK line is a question for §8, not a fact.
- **One defect was verified by running it here.** The payment webhook fault (§4.4, D-1) was reproduced under PHP
  8.1.34, the server's version, and 8.4.19. Everything else about the plugin is from reading its code.

---

## In one paragraph

The plugin, not uCRM, carries almost all customer communication on Uganda. It sends WhatsApp for every lifecycle
event and, since 15 September, eight Uganda-branded e-mails (invoice with its PDF, receipt, welcome, installation
booked, paused, resumed, support, quotation).

uCRM's own notifications have **never been looked at on this server**: not one setting, not one e-mail-log entry, not
one delivered uCRM e-mail is on record. So the risk the 8 September e-mail audit named is still open: **customers
may get two e-mails for the same invoice, one of them possibly Sudan-branded.** The one uCRM e-mail the plugin triggers on
purpose, the quote e-mail, was reported failing on 9 September.

uCRM's bulk e-mail feature ("Mailing") appears **nowhere** in this repository. It is unverified, and it is e-mail
only as far as is known: nothing on record suggests uCRM can send WhatsApp or SMS. The plugin has no SMS at all, and
its only bulk tools are WhatsApp.

Reading the code also found **defects that decide what customers receive** (§4.4), among them:
- the payment webhook stops just after sending the receipt;
- a scheduled job and a uCRM event can each send the same reminder, so a customer can get it twice;
- the overdue WhatsApp messages would still tell a prepaid customer their service is "suspending tonight";
- a WhatsApp "Enabled / Disabled" screen that no sender reads.

§1 is the matrix, §2 the full inventory, §3 uCRM's bulk feature, §4 duplicates, conflicts and gaps, §5 who should own
what, §6 the proposals, §7 the test plan, §8 the read-only checks, and §9 the answer.

---

## 1. Capability matrix

How to read it:
- **Native CRM support** is uCRM's own feature, as far as anything is known.
- **DishNet plugin support** is what the plugin does, with its code and tests.
- **Uganda status** is what is recorded about this server.
- **Recommendation** names a proposal in §6: **V** = verify (read only), **O** = decide an owner (no code),
  **F** = a small fix, **G** = a gap to fill later.

| Function | Native CRM support | DishNet plugin support | Uganda status | Gap / risk | Recommendation |
|---|---|---|---|---|---|
| **New customer created** | PK: client-zone invitation e-mail. On 26 Sep uCRM raised an `invitation` event for a new client (webhook request log, docs/07:956-963); whether an e-mail went: **NV** | WhatsApp "Welcome to DishNet!" on `client.add` (`webhook.php:841-869`), not for KYC-created clients unless `kyc_messages_like_crm`; KYC form sends "Request Confirmed!" or a delivery note instead. No e-mail. **Code · Tested** (`test_client_add_welcome.php`, `test_kyc_crm_messages.php`) | Switch value **NV**. No real welcome send on record: **Prod NV** | Welcome has no duplicate guard: a repeated `client.add` resends it. KYC race can send both welcome and "Request Confirmed!" (§4.1 D8). The plugin ignores `invitation` | V1, V4; F10 (guard) |
| **Customer portal sign-in / password** | PK: client-zone invitation and forgotten-password e-mails. **NV** | One-time login code by WhatsApp or e-mail (`includes/api/api_customer_app.php:588-748`). **Code · Tested** (`test_customer_otp_transport.php`, `test_otp_log_privacy.php`, `test_customer_login_security.php`) | **Prod 26 Sep**: the operator's own record signed in both ways; code by WhatsApp and by e-mail (docs/37 §I.5) | The activation WhatsApp says *"Login credentials have been shared via email"* (`webhook.php:1525`); the plugin sends no such e-mail (§4.2 C5) | F8; V1 |
| **Quotation** | uCRM's own quote e-mail, which the plugin triggers with `PATCH billing/quotes/{id}/send`. **Reported failing 9 Sep**, with a Reply-To in the wrong country (`tools/quote_email_doctor.php:116-124`). **NV** since | E-mail with PDF on `quote.add` (`webhook.php:205-273`) or from the plugin's own quotes (`lib/QuotationService.php:632-685`); WhatsApp summary + PDF (`webhook.php:2641-3063`, `cron_quote_wa.php`). **Code · Tested** (`test_mail_quote.php`, `test_quotation_audit.php`, `test_kyc_crm_create.php`) | **Prod 9 Sep**: Quotation 000005 with its PDF delivered end to end (dishnet-hybrid-sudan/docs/UGANDA-EMAIL-OWNERSHIP.md:217-235). **Config**: quotation e-mail on (15 Sep); KYC automatic quote on (25 Sep, docs/30:204) | KYC quotes **always** call uCRM's send (`lib/KycService.php:1602`), whatever the toggle says, so two quotation e-mails are possible. Plugin-created quotes can also get two WhatsApps (§4.1 D2) | O6, F4; V3 |
| **Installation booked (customer)** | PK: none known for clients. **NV** | E-mail on `job.add` for a dated installation job with a client (`webhook.php:2491-2505`). WhatsApp only by hand (`includes/notify_actions.php:9-47`). **Code · Tested** (`test_lifecycle_email_wiring.php`, `test_job_notifications_day.php`) | **Prod 28 Sep**: the booking e-mails to the test customer C1, one per test job, are in the accounts mailbox's Sent folder (docs/44:2730-2744). **Config** on (15 Sep) | A new date (`job.edit`) tells the customer nothing. The title test also matches "uninstall" and "dismount" | G4 |
| **Service activated (welcome)** | PK: none known. **NV** | `service.add`: WhatsApp "Service Activated", welcome e-mail, app push (`webhook.php:1520-1552`); first `service.activate`: welcome e-mail. **Code · Tested** (`test_lifecycle_email_wiring.php`, `test_customer_email_dispatch.php`) | **Config** on (15 Sep). **Prod NV** | No phone → no e-mail either (§4.3 M1). WhatsApp has no duplicate guard | G2 |
| **New invoice** | PK: uCRM e-mails a new invoice with its PDF. A Sudan-era comment says `notificationInvoiceNew` is "already true" (`includes/api/api_notifications.php:353-357`). The repository's copy of uCRM's template is Sudan-branded. On Uganda: setting, mailer, installed template all **NV** | `invoice.add`: WhatsApp text + uCRM's PDF + e-mail with the same PDF + push (`webhook.php:898-1088`). One guard, `INV<number>`, shared with the two scheduled scanners, which send WhatsApp only (`cron_invoice_notify.php`, `cron_maintenance.php` 4b). **Code · Tested** (`test_invoice_email_attachment.php`, including *"uCRM retries the webhook: nothing is sent or fetched again"*) | **Config**: invoice e-mail on (15 Sep). **Prod NV**: *"the first real invoice … still to observe"* (docs/07:78), never recorded since | **Two e-mails per invoice** if uCRM's notice is on and its mailer works (§4.1 D1). Invoices found by the scanners, or approved from a draft, get WhatsApp but no e-mail (§4.3 M2) | V1–V3, O1 |
| **Recurring / draft invoices** | PK: uCRM makes recurring invoices, as drafts or approved, and can notify admins about drafts. **NV** | `invoice.draft_approved`: WhatsApp + PDF, no e-mail (`webhook.php:2551-2638`). `invoice.add_draft`: nothing for the customer | Which event recurring invoices raise on Uganda: **NV** (the code's own comments disagree, `webhook.php:2548-2550`, `cron_invoice_notify.php:8`) | See M2. With `identity_enabled` on, `invoice.add_draft` suspends the mailbox of the client whose id equals the **invoice** id (§4.4 D-6) | V4; F11 |
| **Reminder before the due date** | PK: uCRM "near due" e-mail. A plugin action switches it off (`api_notifications.php:336-399`); whether that ever ran on Uganda: **NV** | WhatsApp only, by **two** paths: uCRM's `invoice.near_due` event (`webhook.php:3142-3208`) and the daily 02:00 job for 7, 3 and 1 days (`cron_maintenance.php:555-691`). **Code**; no functional test | Evolution's account number connected (27 Sep, docs/43:794). Sends: **Prod NV** | The two paths use different duplicate guards, so the same reminder can go twice (§4.1 D4) | O4, F3, G7 |
| **Overdue reminders / dunning** | PK: uCRM overdue e-mail; uCRM suspends. The same plugin action switches uCRM's overdue e-mail off. **NV** | WhatsApp by two paths: `invoice.overdue` (`webhook.php:3210-3277`) and the daily job (days 1, 3, 5, 7; `cron_maintenance.php:843-983`). A weekly nine-stage e-mail + WhatsApp ladder (`cron_overdue_email.php`) and a bulk "Overdue Workbench", both stopped when `billing_model` = `prepaid`. **Code · Tested** (the ladder's gate only: `test_dunning_gate.php`) | `prepaid` on Uganda is **asserted** (docs/33:136-140), never read on the server: **Config NV**. Overdue WhatsApp: **Prod NV** | The e-mail ladder was stopped because its words are false for prepaid; the **WhatsApp** overdue messages were not, and still say *"Service Suspending Tonight"* (§4.2 C1). Two paths can send twice | O5, F7, V11 |
| **Payment received / receipt** | PK: uCRM payment receipt e-mail. The plugin posts payments with no receipt flag, so whether uCRM also sends one: **NV** | `payment.add`: WhatsApp receipt + e-mail + receipt PDF by WhatsApp (queued) + delivery note for KYC credit sales (`webhook.php:1091-1495`, `cron_quote_wa.php:451-681`). Guard `PAY<id>`. **Code**; tests cover the wiring only | **Config**: payment e-mail on (15 Sep). **Prod NV** | **Defect D-1**: the webhook fails just after the receipt, so four follow-up steps never run. Staff collection flows can send a **second** receipt (§4.1 D3) | F1, F2, O2 |
| **Service suspended / paused** | PK: uCRM suspension e-mail and suspension page; the plugin action keeps uCRM's on. **NV** | `service.suspend`: WhatsApp *"suspended due to an unpaid invoice"* (no guard), e-mail "service paused" (prepaid words), push (`webhook.php:1659-1838`). VIP (`NO_AUTO_BLOCK`): admin alert only. **Code · Tested** (e-mail only) | **Config** e-mail on (15 Sep). **Prod NV**. Admin alert number **not set** (27 Sep, docs/43:795), so VIP alerts go nowhere | WhatsApp says *suspended … unpaid*, the e-mail says *paused*, and uCRM may send a third, Sudan-branded notice (§4.2 C2) | O3, V1 |
| **Service restored / resumed** | PK: none known. **NV** | `service.activate` / `unsuspend`: WhatsApp "Service Restored" + e-mail "resumed" after a remembered pause (`webhook.php:1841-2044`). **Code · Tested** (e-mail) | **Config** e-mail on (15 Sep). **Prod NV** | The "just paid" marker meant to avoid a redundant "Restored" is never written, because of D-1 | F1 |
| **Service ended / win-back** | PK: none known | WhatsApp "Service Ended" (`webhook.php:2171-2194`); win-back WhatsApp 7–10 days later (`cron_maintenance.php:1190-1268`), with no switch. **Code**; no test | **Prod NV** | Win-back is marketing-like and cannot be switched off | G5 |
| **Support ticket** | PK: uCRM ticket e-mails to clients and admins. **NV** | `ticket.add`: e-mail "support request received" to the client (no phone needed) + admin WhatsApp (`webhook.php:2515-2546`). **Code · Tested** (`test_lifecycle_email_wiring.php`) | **Config** on (15 Sep). **Prod NV**. The admin WhatsApp goes nowhere (no admin number, 27 Sep) | Possible second acknowledgement from uCRM | V1 |
| **Credit note / refund** | PK: none known | WhatsApp on `credit_note.add` (`webhook.php:3066-3099`); the staff credit-note screen sends its own as well | **Prod NV** | Two WhatsApps for one credit note (§4.1 D7) | F3 |
| **Planned maintenance / outage** | PK: none known, beyond using Mailing (next rows) | An "outage alert" bulk WhatsApp handler exists (`includes/notify_actions.php:49-84`), but **no screen offers it** (§3.4) | **Not usable** in 5.18.53 | **No way today to warn customers of maintenance**, on any channel | O7, §3.6 |
| **Bulk e-mail (announcements, marketing)** | PK: uCRM **Mailing**, one e-mail to a filtered list of clients. **Zero mentions in this repository. NV**: menu, filters, log, permissions, and whether uCRM's mailer works at all | None | **NV** | Needs uCRM's mailer, reported failing 9 Sep. Marketing needs consent and an unsubscribe route; the plugin's e-mails carry no unsubscribe header (8 Sep audit) | V2, V5, O7, G5 |
| **Bulk WhatsApp** | None known | Overdue Workbench (stopped on prepaid); AI follow-ups with a person's approval (`followup_enabled`); failure-queue bulk retry. The outage alert has no screen. **No free-text broadcast of any kind** (§3.4) | Evolution connected (27 Sep). **Prod NV** | Nothing for announcements or maintenance | O7, G10 |
| **SMS** | PK: none native; SMS in uCRM comes from third-party plugins. **NV** | **None.** The Africa's Talking code in this repository belongs to the separate Domain-B service, not to this plugin | None | No SMS fallback when WhatsApp fails | only if you want SMS (§6 G9) |
| **Staff: job assigned / changed / accepted** | Whether uCRM e-mails the assigned user: **NV** (docs/44:1262) | Job messages 1 and 2, new time, no longer assigned, cancelled, each by WhatsApp with an e-mail copy (5.18.52/53, `lib/JobNotifier.php`). **Code · Tested** | **Prod 30 Sep**: the Accept test (job #13) delivered message 1, message 2 and "cancelled" to S4, every record saved (docs/44 §16.30) | Possible second message from uCRM itself | V1 |
| **Staff / admin alerts** | PK: uCRM admin notifications (new ticket, drafts). **NV** | Several, listed in §2.5 | Admin alert number **not set** (27 Sep): every `sendAdmin()` alert goes nowhere | Alerts that reach nobody look like alerts that fired | §2.5 |
| **Delivery log, failures, retries** | PK: uCRM e-mail log (every e-mail and its status), and a webhook request log. The request log was read once, 26 Sep; the e-mail log **never** | Message Log (`notification_audit_log`: hand-over result only, **not delivery**, docs/43:524); failure queue (retry **by hand only**); `customer_email_log` (no retry job); WhatsApp events log (last 300); since 5.18.53 `data/plugin.log` for records that could not be saved | Message Log working: 367 rows on 27 Sep, last row #441 on 30 Sep | Evolution's delivery receipts are dropped (docs/43:205). A failed customer e-mail is never retried | V3, V8–V10, G3, G8 |
| **Opt-out / unsubscribe** | PK: uCRM has per-client contact options. **NV** | WhatsApp opt-outs (`contact_optouts`, `lib/ContactOptOut.php:88-107`). A customer's **"STOP" blocks only messages marked proactive** (`evo_webhook.php:300-327`), and only the AI follow-ups are marked so (`cron/followup_send.php:86, 118`). Everything else the notifier sends counts as transactional by default (`lib/NotificationService.php:2002`), win-back and outage alerts included. E-mail: no opt-out check, no unsubscribe header | **Code · Tested** (`test_contact_optout.php`, `test_followup_policy.php`). Uganda opt-out rows: **NV** | Right for invoices and receipts. Wrong for win-back and anything promotional (§4.2 C9) | G5 |
| **Country-correct content** | The repository's copies of uCRM's three notification templates are Sudan-branded; what uCRM actually uses on Uganda: **NV** | E-mails: Uganda shell, *"0 South Sudan references"* (mail_doctor, 8 Sep). Hard-coded Sudan pieces remain: ladder WhatsApp `+211` (`lib/OverdueDunningHelpers.php:330-381`), manual quote resend `$`/`+211` (`includes/api/api_whatsapp.php:649-656`), app push `$`/USD (`lib/FcmPush.php:118-135`) | E-mail wording: **Tested** (mail_doctor render, 8 Sep). uCRM's templates on the server: **NV** | Any uCRM e-mail still going out is probably Sudan-branded | G6, V6 |

---

## 2. Inventory: every notification, its trigger and its recipient

"Kind" is **automatic** (an event fires it), **scheduled** (a timed job fires it) or **manual** (a person presses
something). "Uganda" gives the strongest evidence on record, in the tags above.

### 2.1 uCRM's own notifications

Every row but the last is **PK**. None has ever been observed on this server; §8 says how to look.

| # | Trigger | Recipient | Channel | Template / setting | Kind | Uganda |
|---|---|---|---|---|---|---|
| N1 | Invoice issued or approved | the client | e-mail + invoice PDF | "new invoice" template; a notification setting | automatic | **NV**. uCRM's mailer was reported failing on 9 Sep (below) |
| N2 | Days before the due date | the client | e-mail | "invoice near due" | scheduled | **NV** |
| N3 | Invoice overdue | the client | e-mail | "invoice overdue" | scheduled | **NV** |
| N4 | Payment recorded | the client | e-mail receipt | "payment received" | automatic, or chosen by staff | **NV** |
| N5 | Service suspended | the client | e-mail + suspension page | "service suspended" | automatic | **NV** |
| N6 | Quote sent | the client | e-mail | "new quote" | manual, or the plugin's `…/send` call | **Reported failing, 9 Sep**, with a Reply-To in the wrong country (`tools/quote_email_doctor.php:116-124`) |
| N7 | Client-zone invitation | the client | e-mail | "client zone invitation" | manual or at creation | uCRM raised an `invitation` event on 26 Sep; the e-mail: **NV** |
| N8 | Forgotten client-zone password | the client | e-mail | "forgotten password" | on request | **NV** |
| N9 | Ticket opened, answered, closed | client and admins | e-mail | ticket templates | automatic | **NV** |
| N10 | Admin notices (drafts ready, new ticket, …) | uCRM users | e-mail / in-app | notification settings | automatic | **NV** |
| N11 | Mailing (bulk) | chosen clients | e-mail | free text | manual | **NV** (§3) |
| N12 | Every change, to the plugin | the plugin's webhook | HTTP | System → Webhooks, "Any event" | automatic | **Prod**: `quote.add` 9 Sep; client `insert`/`invitation`/`edit` 26 Sep; `job.*` 25–30 Sep (docs/07:956-971, docs/44:2638-2641) |

**What is on record about uCRM's mailer on Uganda:**
- **8 Sep:** the API refuses `GET settings` (404) while other reads work, so the mailer and notification settings
  cannot be read by code (dishnet-hybrid-sudan/docs/UGANDA-EMAIL-LIFECYCLE-AUDIT.md:179). They were to be read in the
  browser.
- **9 Sep, reported:** *"uCRM has its own mailer and its own Email Log. It is failing on ssl://mail.dishnetuganda.com:465
  because the container cannot reach the mail server by its public name"* (`tools/quote_email_doctor.php:116-120`,
  commit `bfd67f3`). No log extract was kept, and port 465 from the uCRM container was never tested.
- **15 Sep:** *"uCRM's own Mailer / Notifications pages still unchecked in the browser"* (docs/07:78-79). Nothing
  later is recorded.

So whether uCRM sends **any** e-mail on Uganda today is **NV**.

### 2.2 Plugin: automatic, on a uCRM event

All in `webhook.php`. uCRM sends every event to the plugin; the plugin re-reads the record from uCRM before acting
(`webhook.php:584-590`). "Guard" is the duplicate protection.

| Event | Recipient | Channel | Template / switch | Guard | Uganda |
|---|---|---|---|---|---|
| `client.add` | client's first phone | WhatsApp "Welcome to DishNet!" | code text; skipped for KYC clients unless `kyc_messages_like_crm` | **none** | **NV** |
| `client.edit` (a KYC lead becomes a customer) | client; the agent | WhatsApp "account activated" | code text | the local lead flag | **NV** |
| `quote.add` | client e-mail; client phone | e-mail with PDF (sent first, even without a phone); WhatsApp summary + PDF (KYC quotes are left to the 5-minute job, §2.3) | e-mail: `customer_email_quotation`; WhatsApp: none | `QEMAIL<id>` (released on failure); `quote_wa_state.json` | e-mail **Prod 9 Sep**; switch on 15 Sep |
| `quote.approve` | client | WhatsApp "Quote Approved" | code text | **none** | **NV** |
| `invoice.add` | client | WhatsApp + PDF; e-mail + the same PDF; push | `customer_email_invoice`; `wa_send_pdf` | `INV<number>`, shared with both scanners | switch on 15 Sep; **Prod NV** |
| `invoice.draft_approved` | client | WhatsApp + PDF (no e-mail) | code text | `INV<number>` | **NV** |
| `invoice.near_due` | client | WhatsApp "due in 7 / 3 / 1 days" | code text | `NEARDUE_<id>_<date>`: once per invoice **per day** | **NV** |
| `invoice.overdue` | client | WhatsApp day 1 / day 3 / **"Final Notice — Service Suspending Tonight"** from day 4 | code text (`lib/NotificationService.php:937-968`) | `OVERDUE_<id>_<date>`: once **per day** | **NV** |
| `payment.add` | client | WhatsApp receipt; e-mail; receipt PDF by WhatsApp (queued); delivery note for KYC credit sales | `customer_email_payment_received` | `PAY<id>` | switch on 15 Sep; **Prod NV** |
| `service.add` (active) | client | WhatsApp "Service Activated"; welcome e-mail; push | `customer_email_welcome` | e-mail `SVCADD…`; WhatsApp **none** | switch on 15 Sep; **Prod NV** |
| `service.activate` / `unsuspend` / `suspend_cancel` | client | WhatsApp "Service Restored"; e-mail "resumed" or "welcome"; push | `customer_email_service_resumed` / `_welcome` | e-mail keys; WhatsApp only skipped by a fresh payment or postpone marker | **NV** |
| `service.suspend` | client (VIP: the admin) | WhatsApp "suspended due to an unpaid invoice"; e-mail "paused"; push | `customer_email_service_paused` | e-mail `SUSP…`; WhatsApp **none** | **NV** |
| `service.postpone` | client | WhatsApp + push | code text | **none** | **NV** |
| `service.end` | client | WhatsApp "Service Ended" | code text | **none** | **NV** |
| `credit_note.add` | client | WhatsApp | code text | **none** | **NV** |
| `ticket.add` | client e-mail; admin phone | e-mail "support request received"; admin WhatsApp | `customer_email_support_received` | `TKT<id>` | switch on 15 Sep; **Prod NV** |
| `job.add` (installation, dated, with a client) | client e-mail | e-mail "installation booked" | `customer_email_install_scheduled` | `JOB<id>` | **Prod 28 Sep** (test customer) |
| `job.add` / `job.edit` / `job.delete` | the engineer | WhatsApp + e-mail copy (§2.5) | `StaffJobsGate` (Uganda) | `job_notify_*` tables | **Prod 30 Sep** |
| `client.message` | client | WhatsApp (a uCRM message forwarded) | only with a verified `crm_webhook_key` | **none** | **NV** |
| `payment.delete` | the accountant | WhatsApp alert | code text | — | **NV** |
| `invoice.add_draft`, `client.invite`, `client.delete`, `client.archive` | nobody | — | mailbox suspension only, and only when `identity_enabled` | — | — |

### 2.3 Plugin: scheduled

All dispatched by `cron/master.php`, which uCRM's plugin tick runs.

| Job | When | What it sends | Switch | Uganda |
|---|---|---|---|---|
| `cron_invoice_notify.php` | every 15 min | WhatsApp + PDF for unpaid invoices created in the last 24 h that no webhook announced. **No e-mail.** Also renewal reminders | renewal reminders: `renewal_reminders_enabled`, **off** by default | **NV** |
| `cron_maintenance.php` task 4a | daily 02:00 | WhatsApp pre-due reminders at 7, 3 and 1 days | none | **NV** |
| `cron_maintenance.php` task 4b | daily 02:00 | WhatsApp + PDF for new invoices (same guard as `invoice.add`) | none | **NV** |
| `cron_maintenance.php` task 4 | daily 02:00 | WhatsApp overdue on days 1, 3, 5 and a low-balance message on day 7 | none | **NV** |
| `cron_maintenance.php` win-back | daily 02:00 | WhatsApp 7–10 days after a service ends | none | **NV** |
| `cron_overdue_email.php` | Mondays 09:00 | nine-stage e-mail/WhatsApp ladder, 14 to 210+ days overdue; admin summary | refuses when `billing_model` = `prepaid` | **Config NV** (prepaid asserted, docs/33:136-140) |
| `cron_quote_wa.php` | every 5 min | WhatsApp + PDF for KYC quotes and quotes typed into uCRM; receipt PDFs | `quote_wa_cron_enabled`, **on** by default | **NV** |
| follow-up jobs (`cron/followup_*.php`) | scheduled | AI follow-up WhatsApp, drafts approved by staff | `followup_enabled` | **NV** |
| `cron/starlink_mail.php` | scheduled | Starlink order e-mails turned into customer WhatsApp (confirmed, shipped, activation) | `starlink_mail_enabled` | **NV** |
| LTE jobs | scheduled | LTE usage and renewal WhatsApp | South Sudan product | not Uganda |

**A trap in the scheduled path.** Scheduled jobs read only the **database copy** of the settings, while the webhook
also reads the settings files (`tools/notify_doctor.php:57-66` explains it). If the database copy lacks the Evolution
settings, a scheduled job falls back to the older WhatsApp transport, which Uganda does not have, and **sends nothing
without a trace**. Whether Uganda's database copy holds them: **NV**. `tools/notify_doctor.php` reports it, but it is
not quite read-only (§6, R0).

### 2.4 Plugin: manual

| Action | Where | Sends | Who may use it | Note |
|---|---|---|---|---|
| Installation confirmed / technician on the way | `includes/notify_actions.php:9-47` | customer WhatsApp | staff | — |
| Outage alert | `includes/notify_actions.php:49-84` | **bulk** customer WhatsApp | admin | **no screen offers it** (§3.4) |
| Overdue Workbench bulk send | `includes/api/api_crm_misc.php:3891-4461` | bulk overdue e-mail/WhatsApp | admin, accountant, field accountant | refused on prepaid; its duplicate guard can be switched off (§3.4) |
| Invoice scan with `send=1` | `includes/api/api_notifications.php:404-491` | invoice WhatsApp | admin | checks an old JSON file instead of the shared guard, so it **can resend** (§4.4 D-7) |
| Test invoice notification | `includes/api/api_notifications.php:22-70` | a fake "TEST" invoice WhatsApp **to a real client** | admin, or anyone holding the webhook secret | a plain GET link (§4.4 D-7) |
| Send this invoice to my WhatsApp | `includes/api/api_customer_app.php:4268-4354` | the customer's own invoice | the signed-in customer | 60-second cooldown |
| Quote resend | `includes/api/api_whatsapp.php:558-721` | quote WhatsApp | staff | hard-codes `$` and `+211` (`:649-656`) |
| Receipts at collection | `includes/post/post_sales.php:90-124`, `post_field.php:1174-1217`, `api_retailer.php:214-248` | receipt WhatsApp | staff, field staff, the retailer app | the app's receipt is not seen by the webhook (§4.1 D3) |
| Credit note, refund | `includes/api/api_payments_admin.php:590-611`, `includes/post/post_cashbook.php:584-620` | WhatsApp | staff | credit note: a second message from the webhook (§4.1 D7) |
| Lead call outcome | `includes/api/api_leads.php:225-250` | WhatsApp to the lead | staff | — |
| Overdue e-mail "Run Now" | `tabs/admin/overdue_email_log.php:80` | the ladder, at once | admin | refused on prepaid |
| **Switch off uCRM's reminders** | `includes/api/api_notifications.php:336-399` | changes **uCRM's** settings | admin; a plain GET link | §4.2 C3 |

### 2.5 Plugin: staff and admin notifications

Everything here goes to DishNet's own people, never to customers. Engineers' job messages are the only staff path
proven on the server (30 Sep). Every other row is **Code** only.

| Notification | Trigger | Recipient | Channel | Uganda |
|---|---|---|---|---|
| **Job messages 1 and 2, new time, no longer assigned, cancelled** (`lib/JobNotifier.php`) | + New Job, Bulk Dispatch, Reschedule, Accept, and uCRM's `job.*` events | the job's assignee, found only through a verified staff link | WhatsApp (support number; never blocked by an opt-out) + an e-mail copy | **Prod 30 Sep** (job #13). Uganda only (`StaffJobsGate`); no switch |
| **"Job Accepted" to support leaders** (`includes/api/api_scheduling.php:358-371`) | each Accept | every active support leader with a phone | WhatsApp; no e-mail | **Code**. **No guard**: a second Accept sends it again. Numbers are not put into international form |
| **Job complete, tasks done** (`api_scheduling.php:380-642`) | engineer presses complete or ticks tasks | the engineer; the admin number; accountants (installation jobs) | WhatsApp | **Code**. The admin copy goes nowhere while the admin number is unset |
| **Daily 07:00 staff-jobs brief** (`cron/staff_jobs_summary.php`) | scheduled | support staff and admins | WhatsApp | **Broken**: it builds the uCRM client wrongly (`:47` passes the settings array where the constructor needs text, `lib/CrmApiClient.php:36`), so it stops with a TypeError before sending. docs/43:797 recorded its 9 ms run on 27 Sep |
| **Admin alerts** (`sendAdmin`, about 35 call sites): KYC submitted and failed, uCRM duplicate phone, zero-amount quote, ticket added, VIP suspension held, KYC cancellation, recharge, handover, backups, overdue-run digest | events and jobs | the number in `whatsapp_admin_phone` | WhatsApp | **Config 27 Sep: not set**, so every one of them goes nowhere, with no log row (`lib/NotificationService.php:1527-1536`) |
| **Watchdog and hand-over alerts** (`lib/AlertService.php`) | a customer left unanswered; the webhook silent; the AI handing over | the number in `alert_whatsapp` | WhatsApp (sales number); 4-hour cooldown per alert | **NV**. Leaves no Message Log and no failure row. The AI setup screen suggests a `+249` (Sudan) format for this number (`tabs/engage/wa_ai_setup.php:429`) |
| **Lead alerts** (`cron_lead_alerts.php`, every 5 min; `cron_leads.php`, every 4 h) | new lead, 45 minutes without a call, 60-minute escalation, reassignment | the agent; `lead_supervisor_phone` or the first admin | WhatsApp | **Code**; no tests. The reassignment notice repeats on every run (as read in this audit's code search) |
| **Cash, wallet, payroll, leave** (the cash-carry reminder, cashbook summary, nightly reconciliation, handover, payslips, leave decisions) | events and daily jobs | staff, accountants, admins | WhatsApp | **Code**; no tests. Several texts write `$` before UGX amounts (`tabs/sales/my_account.php:257-273`, `tabs/accounts/field_handover.php:80-90`) |
| **Payment deleted in uCRM** (`webhook.php:3419-3437`) | `payment.delete` | the first accountant or admin | WhatsApp | **Code** |
| **Staff password reset** (`includes/post/post_auth.php:128-150`) | on request | the account's own phone | WhatsApp | **Code** |

**Three things to know about all of them:**
- Admin alerts depend on **one** unset number (M10). Watchdog alerts depend on a **second** one, and nobody has read
  its value (V11).
- Every successful staff message through the notifier is also filed as an outbound conversation, so staff numbers
  appear in the WhatsApp Inbox (docs/43:799-800 counted 6 such conversations, and 109 AI replies queued for staff numbers).
- The **failure-queue API** (`includes/api/api_notifications.php:674-730`) checks no role: any signed-in account can
  list failed messages with their full text and number, retry them, retry in bulk, or dismiss them. The Failed Queue
  *screen* is admin-only (§4.5).

### 2.6 Transports and master switches

| Piece | What it is | Uganda |
|---|---|---|
| **WhatsApp** | Evolution API, three numbers: support, account, sales (`lib/NotificationService.php:1787-1825`). The older WASender is the fallback | **Config 27 Sep**: all three connected; no WASender keys; dry run off (docs/43:794) |
| **Customer e-mail** | the plugin's own SMTP through `lib/MailService.php` (a relay; From the accounts address). `use_ucrm_email` does **not** send through uCRM: it borrows uCRM's SMTP login and still sends itself (`lib/MailService.php:190-302`) | **Config 8 Sep** (mail_doctor 7 pass / 2 warn / 0 fail); **30 Sep**: the plugin's own SMTP in use (docs/44:3586) |
| **Overdue ladder e-mail** | its own SMTP code, not `MailService` (`lib/OverdueDunningHelpers.php:388-489`) | could send on 8 Sep; stopped on prepaid (**Config NV**) |
| **App push** | Firebase, legacy endpoint (`lib/FcmPush.php:70`) | `fcm_server_key`: **NV** |
| **SMS** | none in the plugin | — |
| `dry_run_mode` | logs WhatsApp instead of sending; **does not stop e-mail** | **Config 27 Sep**: off |
| `customer_emails_enabled` + eight `customer_email_<event>` | the eight lifecycle e-mails; both switches must be on | **Config 15 Sep**: all on (`tools/set_customer_emails.php --show`, docs/07:80) |
| `quote_email_via_plugin` | plugin sends its own quotes' e-mail instead of uCRM | **NV** |
| `billing_model` | `prepaid` stops the e-mail ladder and the Workbench | **NV** (asserted prepaid) |
| `kyc_messages_like_crm`, `kyc_auto_quote_enabled` | KYC welcome behaviour; KYC automatic quote | auto quote **on, 25 Sep** (docs/30:204); the other **NV** |
| `renewal_reminders_enabled`, `followup_enabled`, `starlink_mail_enabled`, `identity_enabled`, `quote_wa_cron_enabled` | as in §2.3 and §2.2 | **NV** |
| `efris_auto_submit` | an issued invoice is sent to EFRIS | **NV**. Matters for testing (§7) |
| Admin alert number | where `sendAdmin()` alerts go | **Config 27 Sep: not set** (docs/43:795) |

---

## 3. uCRM's multi-user communication (bulk messaging)

### 3.1 What is known

**Nothing is on record.** No file in this repository mentions uCRM's bulk e-mail feature, generally called
**Mailing**, or any other uCRM bulk channel. The documentation could not be reached. So each answer below is either
**PK** (what UISP CRM is generally known to offer) or a question for §8. None is a finding about this server.

### 3.2 Channel by channel

Each channel was considered on its own. That uCRM can send bulk e-mail says nothing about WhatsApp or SMS.

| Channel | Can uCRM's bulk feature send it? | Evidence | What would settle it |
|---|---|---|---|
| **E-mail** | PK: yes. Mailing sends one e-mail to a filtered list of clients, through uCRM's own mailer | **NV**. The mailer itself is **NV**: reported failing on 9 Sep, not checked since | V5 (does the menu exist), V2 (the mailer, with a test e-mail to your own address), V3 (the e-mail log shows it) |
| **SMS** | Nothing on record suggests it. PK: uCRM has no native SMS; SMS comes from third-party uCRM plugins, and none is recorded as installed here | **NV** | V5: the Mailing form's own options; V7: the installed-plugins list |
| **WhatsApp** | Nothing on record suggests it. PK: none native | **NV** | V5 |

**One uCRM → WhatsApp bridge exists in the plugin's code, and it cannot work on Uganda.** The plugin forwards a
uCRM `client.message` event to the client's WhatsApp (`webhook.php:3100-3139`), but only when the request carries the
plugin's webhook key. Uganda's uCRM webhook form has no field for a key: *"uCRM cannot supply the Phase-1 optional
webhook key through the available interface"* (docs/07:967-971, read 26 Sep). So that event is always ignored here.

### 3.3 Your questions, one by one

| Question | Answer today | How to answer it (§8) |
|---|---|---|
| Where is it in the CRM? | PK: a **Mailing** entry in uCRM's menu. **NV** on 4.5.33 | V5 |
| Which channels? | E-mail only, by PK. SMS and WhatsApp: nothing known (§3.2) | V5 |
| All clients, or filtered? Groups? | PK: filters such as organisation, service plan, site or device, client tag, client type. **NV** | V5: read the filter list; send nothing |
| Templates or custom messages? | PK: a free subject and body per mailing. Whether it offers merge fields (the client's name, for example): **NV** | V5 |
| Scheduling? | PK: sends when submitted; no scheduling known. **NV** | V5 |
| Delivery status, logs, failures? | PK: each e-mail appears in uCRM's e-mail log with its status, and a failed one can be resent there. **NV** | V3 |
| Who may use it? | PK: uCRM's admin roles and permissions. Which Uganda users can: **NV** | V5, and uCRM's users and roles page |
| Available and working in Uganda? | **NV twice over**: the feature's presence, and a working uCRM mailer | V2, V5 |
| Does the plugin interfere or duplicate? | §3.5 | — |

### 3.4 The plugin's own bulk tools

**There is no free-text broadcast, campaign, newsletter, CSV upload, WhatsApp-group send or "send to all" anywhere
in the plugin.** What exists:

| Tool | Where; who may use it | Channels | Recipients | Text | Log | Uganda |
|---|---|---|---|---|---|---|
| **Outage alert** | handler in `includes/notify_actions.php:49-84`, admin only. **No screen offers it**: it returns to a `notify` tab that does not exist in 5.18.53 | WhatsApp (support number) | a pasted list of numbers | fixed "Planned Maintenance" text | Message Log; activity log | **Not usable.** The FAQ still tells staff to use it (`tabs/help/faq.php:45`) |
| **Overdue Workbench bulk send** | `tabs/admin/overdue_workbench.php`; API `owb_bulk_send` (`includes/api/api_crm_misc.php:3891-4461`), admin, accountant or field accountant | e-mail and/or WhatsApp | invoices ticked from a filtered overdue list, up to 500 per call, 2 s apart | the fixed nine-stage ladder, no free text | per-invoice audit, the Message Log, the overdue e-mail log, a WhatsApp digest to the admin number | **Refused on prepaid**, so off on Uganda if `billing_model` is `prepaid` (V11). Its duplicate guard can be switched off; it counts a WhatsApp as sent whether or not it went (`:4266-4282`) |
| **AI follow-ups** | `tabs/engage/followups.php`, admin only; `followup_enabled` | WhatsApp, on the conversation's own number | conversations the policy allows: leads that went quiet, colleagues excluded, at most 2 attempts at 24 h and 72 h, 08:00–20:00 | AI-drafted, checked against invented prices, **approved by a person** unless `followup_auto_send` is on | the `followups` table; `tools/followup_doctor.php`; **not** the Message Log | **NV**. The only bulk path marked proactive, so the only one that honours "STOP" |
| **Failure-queue bulk retry** | the Failed Queue screen (admin) and the Inbox banner (up to 50) | WhatsApp | messages that failed before | the stored text, resent as it was | Message Log | **NV**. A retry does not re-check whether the message still applies |
| **Bulk Dispatch** | `tabs/support/bulk_dispatch.php`, support leader or admin | uCRM jobs + job messages to engineers | up to 50 customers per batch | job messages | as the job messages | staff, not customers |
| **LTE bulk reminders** | `includes/api/api_lte.php:268-300`, admin | WhatsApp | the LTE renewal queue | fixed text; **no guard**, so a second run sends again | `lte_reminder_log.json` | a South Sudan product, not Uganda |

**What this means for your questions in §3.6:** the plugin has **no usable way today** to tell many customers about
planned maintenance, a service update or an announcement. Its only such tool has no screen. The Workbench sends only
the overdue ladder, and the follow-ups only chase sales conversations.

### 3.5 Does the plugin interfere with uCRM's bulk e-mail?

- **No duplication.** The plugin has no bulk e-mail. Its bulk tools are WhatsApp (§3.4). The two would complement
  each other.
- **Interference 1: where uCRM's e-mails go.** With `identity_enabled` on, a worker replaces the first contact's
  e-mail **in uCRM** with a DishNet mailbox (`lib/CustomerIdentityService.php:231-250, 301-323`). Every uCRM e-mail, a
  mailing included, would then go to that mailbox, not to the customer's own address. The switch is off by default;
  Uganda: **NV**. That uCRM write-back has no test (`tests/test_identity_service.php` does not cover it).
- **Interference 2: opt-outs do not cross.** A customer who wrote "STOP" on WhatsApp is recorded only in the plugin
  (`contact_optouts`), and uCRM would still e-mail them. The reverse holds too.
- **Interference 3: the sending domain.** On 8 Sep the domain's SPF allowed only its own mail server and the relay
  (`v=spf1 mx include:spf.brevo.com -all`), with DMARC `p=quarantine`
  (dishnet-hybrid-sudan/docs/UGANDA-EMAIL-LIFECYCLE-AUDIT.md:163-166). A uCRM mailer sending from the same domain
  through anything else would be refused or quarantined.
  - The plan was for uCRM to send through the domain's own mail server (dishnet-mail/README-DEPLOY.md:154-156, 3 Sep).
    Nothing records that it was done, and on 9 Sep the container reportedly could not reach that server.
- **Interference 4: a shared sending quota.** If uCRM were pointed at the same relay as the plugin, a large mailing
  could use up the day's quota. The plan recorded a free tier of 300 e-mails a day (dishnet-mail/README-DEPLOY.md:126-127).
  The invoices, receipts and login codes due that day would then fail. The real limit is **NV**.

### 3.6 Is it suitable for …

| Use | Suitable? | Why |
|---|---|---|
| **Announcements** | Yes, once V2 and V5 pass | One e-mail to everyone or to a filtered group. It reaches no one on WhatsApp |
| **Maintenance notices** | Yes: today it would be the **only** channel | The plugin's outage alert has no screen (§3.4), so nothing else can reach many customers at once. Customers read WhatsApp first, so a usable WhatsApp broadcast is a gap of its own (G10) |
| **Service updates** | Yes, as e-mail | — |
| **Payment reminders** | **No** | A reminder names one customer's invoice, amount and date. The plugin already sends these per invoice, and uCRM may too (N2, N3). A mailing would be a third, generic copy |
| **Marketing** | Only with consent and a way to unsubscribe | Neither exists today. The plugin's e-mails carry no unsubscribe header (8 Sep audit, §4) |

---

## 4. Duplicate, conflicting and missing notification paths

### 4.1 Duplicates: one event, two or more messages

| # | Event | The paths | Evidence | Would it happen on Uganda? |
|---|---|---|---|---|
| **D1** | New invoice | plugin e-mail with PDF (`webhook.php:1043-1055`) **and** uCRM's own new-invoice e-mail (N1) | **Code** for the plugin; uCRM side **NV**. Named on 15 Sep: *"a duplicate is possible until it is [confirmed off]"* (UGANDA-EMAIL-OWNERSHIP.md:92) | Yes, if uCRM's notice is on **and** its mailer works. Both **NV** (V1, V2) |
| **D2** | Quotation | (a) KYC quote: uCRM's send (`lib/KycService.php:1602`; extra service `:301`) **and** the plugin's `quote.add` e-mail (switch on since 15 Sep). (b) A plugin quote with its toggle off, or whose plugin send failed: uCRM's send (`lib/QuotationService.php:674-677`) **and** the `quote.add` e-mail. (c) A plugin quote: its own WhatsApp (`lib/QuotationService.php:861-871`) **and** the `quote.add` WhatsApp summary | **Code**. `test_kyc_crm_create.php:609-610` pins exactly one uCRM send per KYC quote, so the send is intended | (a) whenever uCRM's quote e-mail works: **NV** (reported failing 9 Sep). (c) on every plugin-created quote |
| **D3** | Payment | (a) The retailer app's receipt (`includes/api/api_retailer.php:214-248`) records its "already sent" note in `payment_notify_log.json`, which **nothing reads**. The webhook's guard is the database table (`lib/NotificationService.php:2761` says the file was replaced), so `payment.add` sends a second receipt and the e-mail. (b) A collection whose uCRM post failed gets a `COL-…` receipt. When the retry jobs post it later, no `PAY` guard exists, so a second receipt follows. (c) When a staff flow's receipt wins the race, the webhook skips the **e-mail** too | (a) **Code**, confirmed here. (b), (c) **Code**, as read in this audit's code search, not re-checked line by line | (a) only if `wa_accounts_number` is set: **NV** |
| **D4** | Before the due date | uCRM's `invoice.near_due` → WhatsApp, guard `NEARDUE_<id>_<date>` (`webhook.php:3182`) **and** the daily job, guard `<number>-pre-<tier>` (`cron_maintenance.php:621`). Different guards: neither sees the other | **Code**, confirmed here | Only if uCRM raises `near_due` on Uganda: **NV** (V4) |
| **D5** | Overdue | uCRM's `invoice.overdue` → WhatsApp, guard once **per day** (`webhook.php:3252`) **and** the daily job, guard per tier (`cron_maintenance.php:903`), **and** uCRM's own overdue e-mail (N3), **and**, where it runs, the e-mail ladder | **Code**, confirmed here | If uCRM raises `overdue` every day, the customer gets a WhatsApp every day, and from day 4 it is always *"Final Notice — Service Suspending Tonight"* (`webhook.php:3266-3273`). **NV** (V4) |
| **D6** | New invoice WhatsApp | the webhook and both scanners share one guard, `INV<number>` | **Code · Tested**: protected | No duplicate, except the admin **invoice scan with `send=1`**, which checks an old JSON file and can resend (§4.4 D-7) |
| **D7** | Credit note | the staff credit-note screen posts to uCRM **and** sends its own WhatsApp (`includes/api/api_payments_admin.php:530, 588-611`); uCRM then raises `credit_note.add`, which sends another (`webhook.php:3066-3099`) | **Code**, confirmed here. The screen's text also hard-codes `$` | On every staff credit note: **Prod NV** |
| **D8** | New customer via KYC | the form creates the uCRM client (`lib/KycService.php:661`) before it saves the application; a `client.add` arriving in between finds no application and sends the welcome, and the form sends "Request Confirmed!" | **Code** (the order); the race itself never measured | Possible on each KYC sign-up: **NV** |
| **D9** | Suspension | plugin WhatsApp **and** plugin e-mail **and** uCRM's suspension e-mail (N5) | **Code** for the plugin; uCRM **NV** | Three notices in three wordings if N5 is on (§4.2 C2) |
| **D10** | Any event without a guard | `client.add`, `service.add` WhatsApp, `service.suspend` WhatsApp, `service.postpone`, `service.end`, `quote.approve`, `credit_note.add`, `client.message` | **Code** | Only if uCRM delivers an event twice. Whether uCRM retries or redelivers: **NV** |

### 4.2 Conflicts: messages that contradict each other or the policy

| # | Conflict | Evidence |
|---|---|---|
| **C1** | **Prepaid policy vs the overdue WhatsApp.** The nine-stage e-mail ladder was stopped on Uganda because *"every stage says the service is 'suspended' … that is a false statement about their account"* (UGANDA-EMAIL-OWNERSHIP.md:102-114). The WhatsApp overdue messages were never stopped. From both the webhook and the daily job they say *"Your service is at risk of suspension"* and *"Final Notice — Service Suspending Tonight … will be suspended at midnight"* (`lib/NotificationService.php:937-968`). There is no tenant or `billing_model` check on either path | **Code**. Whether they reach anyone depends on invoices going overdue on Uganda: **NV** |
| **C2** | **Three wordings for one suspension.** WhatsApp *"suspended due to an unpaid invoice"* (postpaid words); e-mail "service paused" (prepaid words); uCRM's own notice, if on, probably Sudan-branded | **Code**; uCRM **NV** |
| **C3** | **The plugin can switch off uCRM's reminders.** The admin action `crm_fix_notifications` is a plain GET link. Opened without `dry_run=1` it sends `PATCH options` setting `notificationInvoiceNearDue` and `notificationInvoiceOverdue` to false (`includes/api/api_notifications.php:336-399`). Its comments state Sudan-era facts as current (*"already true"*). Whether the endpoint and key names exist on 4.5.33, and whether it was ever run here, are both **NV**; it leaves only an activity-log line | **Code** |
| **C4** | **The settings screen contradicts the setup.** It labels the uCRM mailer *"RECOMMENDED"* (`tabs/admin/settings.php:1270-1284`), while the setup tool writes `use_ucrm_email=false` (`tools/email_setup.php:84`) and the install uses the plugin's own SMTP (30 Sep). Someone following the screen would move customer e-mail onto a mailer reported failing | **Code**; **Config 30 Sep** |
| **C5** | **A promise nobody keeps.** The activation WhatsApp says *"Login credentials have been shared via email"* (`webhook.php:1525`). The plugin sends no such e-mail; whether uCRM's invitation does is **NV** | **Code** |
| **C6** | **Switches that do nothing.** The WhatsApp tab's "Event Map" lets staff edit each message's text and set it Enabled or Disabled. It saves to `wa_templates.json` (`tabs/engage/whatsapp.php:22-47`), which **no sender reads**: the only other mention is a file-permission list (`tools/fix_file_permissions.php:39`). A message "disabled" there still goes, in its old words | **Code**, confirmed here |
| **C7** | **"Payment received", then "Service Restored".** The payment handler was meant to leave a marker that suppresses the redundant "Restored" message; because of D-1 it never does | **Code** |
| **C8** | **South Sudan content still in Uganda paths.** The ladder's WhatsApp `+211` (only where the ladder runs), the manual quote resend `$` and `+211`, the credit-note text `$`, and the app push `$` / USD (all in the matrix). The repository's copies of uCRM's templates are Sudan-branded (UGANDA-EMAIL-LIFECYCLE-AUDIT.md:37-55) | **Code** |
| **C9** | **"STOP" does not stop promotional messages.** Only the AI follow-ups are marked proactive. Win-back and the outage alert count as transactional and still reach a customer who wrote STOP (§1, opt-out row) | **Code** |

### 4.3 Missing

| # | What is missing | Evidence |
|---|---|---|
| **M1** | **No e-mail without a phone.** The invoice, receipt, welcome, paused and resumed e-mails all sit inside the handlers' `if ($phone …)` guards (`webhook.php:1021, 1228, 1520, 1754, 1861`; recorded as a known limitation, UGANDA-EMAIL-OWNERSHIP.md:79-82). A customer with only an e-mail address gets none of them | **Code** |
| **M2** | **No e-mail for invoices raised as drafts, or found by the scanners.** Those get WhatsApp only (§2.2, §2.3). Which event recurring invoices raise on Uganda is **NV**, so this may be most invoices | **Code** |
| **M3** | **No notice when an installation moves.** `job.edit` tells the customer nothing | **Code** |
| **M4** | **Failures wait for a person.** A failed customer e-mail is never retried; a failed WhatsApp waits in the failure queue until staff retry it. A retry does not re-check the invoice, and its PDF link expires after 10 minutes | **Code** |
| **M5** | **Nothing confirms delivery.** Evolution's delivery receipts are dropped; the Message Log records the hand-over only (docs/43:205, 524). uCRM's e-mail log has never been read | **Code**; uCRM **NV** |
| **M6** | **No SMS**, so no fallback when WhatsApp fails | **Code** |
| **M7** | **No bulk e-mail** in the plugin. uCRM Mailing is the candidate (§3), unverified | — |
| **M8** | **No consent or unsubscribe** for promotional e-mail or WhatsApp | **Code** |
| **M9** | **Numbers are not put into international form** before WhatsApp. The sender strips everything but digits (`lib/NotificationService.php:2010`); a number typed in uCRM as `07…` is sent as it is and fails, and its guard is already taken. How Uganda's client numbers are stored: **NV** | **Code** |
| **M10** | **Admin alerts reach nobody.** The admin alert number was not set on 27 Sep (docs/43:795) | **Config 27 Sep** |
| **M11** | **No tests** for: `invoice.near_due`, `invoice.overdue`, `invoice.draft_approved`, `service.postpone`, `service.end`, `credit_note.add`, `quote.approve`, the WhatsApp side of `payment.add`, `cron_invoice_notify`, the daily job's tasks 4, 4a, 4b and win-back, push, and `crm_fix_notifications` | **Code** (the search found no test that runs them) |
| **M12** | **No production proof of the everyday customer path.** The first real invoice e-mail has been *"still to observe"* since 15 Sep. No real receipt, reminder or suspension message is on record either | — |

### 4.4 Defects found in the code

Each was read in the code at the line given. D-1 was also run.

| # | Defect | Effect | Scope |
|---|---|---|---|
| **D-1** | `webhook.php:1371` releases a lock through `$payLockFp`, a variable defined nowhere. **Run here under PHP 8.1.34, the server's version, and 8.4.19**: *"TypeError: flock(): Argument #1 ($stream) must be of type resource, null given"* | The request dies after the receipt, the e-mail, the PDF queue and the delivery note. Never reached (`:1379-1491`): the "just paid" marker, the payment push, the **Starlink instant restore** and the app-cache refresh. uCRM was already answered 200 (`:1300-1310`), so nothing retries; the error goes only to PHP's log | every first receipt, both countries |
| **D-2** | The retailer app's receipt writes its "sent" note to a file nothing reads (`includes/api/api_retailer.php:240-245`) | A second receipt from the webhook (D3a) | where `wa_accounts_number` is set |
| **D-3** | The WhatsApp Event Map is read by no sender (C6) | Staff believe they changed or switched off a message | both |
| **D-4** | Two reminder paths with separate guards; the webhook's guard lasts one day (D4, D5) | Repeated and doubled reminders; a daily "Final Notice" | wherever uCRM raises these events |
| **D-5** | The overdue ladder's log is `INSERT OR IGNORE` against a unique (invoice, stage) index (`cron_overdue_email.php:130-132, 453`). A failed first try blocks recording the later success, while the skip check needs a success (`:326`) | The same stage is sent again every week | only where the ladder runs: South Sudan, and Uganda unless `billing_model` = `prepaid` |
| **D-6** | `invoice.add_draft` passes the **invoice** id where a client id is expected (`webhook.php:3512-3534`) | With `identity_enabled`, the wrong client's mailbox is suspended | only if `identity_enabled` |
| **D-7** | Three plain GET links act rather than show: the **test invoice** WhatsApp to a real client, allowed to anyone holding the webhook secret (`includes/api/api_notifications.php:22-70`); the **invoice scan with `send=1`**, checking an old JSON guard (`:404-491`); and **`crm_fix_notifications`** (C3) | An accidental or prefetched click sends to customers or changes uCRM | admin and secret holders |
| **D-8** | The KYC quote always calls uCRM's send (`lib/KycService.php:301, 1602`), whatever `quote_email_via_plugin` says, and never checks the answer | A second quotation e-mail (D2a) | both |
| **D-9** | The activation text promises an e-mail that the plugin does not send (C5) | Customers may wait for credentials that never come, unless uCRM's invitation sends them (**NV**) | both |
| **D-10** | Numbers are sent without an international form (M9) | Silent non-delivery for numbers stored as `07…` | both |

None of these was changed. Each fix is proposed in §6, and none may be applied without your approval.

### 4.5 On the staff and admin side

These do not reach customers, but they decide whether anyone at DishNet hears about a problem.

| # | Finding | Evidence |
|---|---|---|
| **S-1** | **The failure-queue API checks no role** (`includes/api/api_notifications.php:674-730`). Any signed-in account can list failed customer messages with their full text and number, retry them one by one or in bulk, or dismiss them. The Failed Queue *screen* is admin-only | **Code**, confirmed here |
| **S-2** | **The leaders' "Job Accepted" copy has no guard.** Every Accept sends it again (`includes/api/api_scheduling.php:358-371`) | **Code**, confirmed here |
| **S-3** | **The 07:00 staff-jobs brief never sends.** It builds its uCRM client from the settings array where the constructor wants text (`cron/staff_jobs_summary.php:47`, `lib/CrmApiClient.php:36`), a TypeError. Its 9 ms run on 27 Sep fits (docs/43:797) | **Code**, confirmed here |
| **S-4** | **Admin alerts reach nobody.** The admin number was not set on 27 Sep, and a watchdog alert through `lib/AlertService.php` leaves no Message Log row, so a missing alert looks like a quiet day (M10) | **Config 27 Sep**; **Code** |
| **S-5** | **The alert-number field suggests a Sudan format** (`+249…`, `tabs/engage/wa_ai_setup.php:429`) | **Code**, confirmed here |
| **S-6** | **The Workbench reports WhatsApps as sent whether they went or not** (`includes/api/api_crm_misc.php:4266-4282`) | **Code**, confirmed here |
| **S-7** | **The outage alert has no screen**, yet the help page still sends staff to it (`tabs/help/faq.php:45`; §3.4) | **Code**, confirmed here |

---

## 5. Who owns what: uCRM or the plugin

The rule the 15 September record set still fits: **one owner per event and channel.** Its order also still holds:
*"wire the plugin senders first, verify them, then switch uCRM off event by event. Turning uCRM off first creates
silence, which is worse than duplication"* (UGANDA-EMAIL-OWNERSHIP.md:40-42).

| Function | Owner today (code) | Proposed owner | Why |
|---|---|---|---|
| The facts behind every message: clients, invoices, payments, services, jobs, tickets | uCRM | **uCRM** | Unchanged. The plugin re-reads uCRM before every event |
| Invoice and quote documents (the PDF and its design) | uCRM templates; the plugin renders a quote PDF when uCRM serves none | **uCRM** | The plugin attaches uCRM's own invoice PDF |
| Event source (webhooks) | uCRM | **uCRM** | — |
| Customer WhatsApp, every event | plugin | **plugin** | uCRM has none (PK) |
| Transactional e-mail: invoice, receipt, welcome, paused, resumed, installation, support, quotation | plugin since 15 Sep; uCRM possibly too | **one of the two per event: your decision (O1–O3, O6)** | Recommended: **the plugin**, since it is Uganda-branded, attaches the PDF, is tested and is already on. Then switch uCRM's matching notices off, after V1–V3 and after one real plugin send is seen. The native alternative: install Uganda templates in uCRM, prove its mailer, and switch the plugin's e-mails off (`tools/set_customer_emails.php --off <event>`) |
| Reminders before due and overdue | plugin WhatsApp, by two paths; uCRM e-mail possibly | **plugin WhatsApp, one path (O4)**; uCRM's reminder e-mails on or off by O5 | WhatsApp is the channel customers read; the prepaid policy decides the rest |
| Client-zone invitation and password reset | uCRM (only uCRM can) | **uCRM** | Native. The plugin's portal has its own login codes |
| Portal login codes | plugin | **plugin** | Proven 26 Sep |
| Bulk e-mail | nobody | **uCRM Mailing, if V2 and V5 pass (O7)** | Native. Do not build bulk e-mail into the plugin |
| Bulk WhatsApp | plugin | **plugin** | No native equivalent |
| Staff job messages | plugin (Uganda) | **plugin** | Proven 30 Sep. Whether uCRM also e-mails the assignee: V1 |
| Admin alerts | plugin, to a number that is not set | **plugin**, once a number is set | M10 |
| Delivery evidence | uCRM's e-mail log (never read); the plugin's Message Log and e-mail log | **both, read together** (V3, V8–V10) | Neither alone shows the whole picture |
| uCRM's notification settings | uCRM's screens, **and** a plugin GET link that can change two of them (C3) | **uCRM's screens only** (F6) | One place to change them, with a person looking |

---

## 6. Gaps and proposed improvements, in priority order

**None of these is approved, built, or part of Release A or Release B.** Each needs your decision, and each code
change would be its own release with tests, weakened copies, a pinned deploy command, and your approval of the build
report. **Most fixes are in code South Sudan runs too** (F1 fixes a crash that happens there as well). Each proposal will
say whether South Sudan changes, and none will change it without your explicit decision.

### Priority 1: verify before deciding anything. Read only, no code, no risk

**V1–V11**, set out in §8. They turn every **NV** that matters into a fact: uCRM's notification settings, its mailer,
its e-mail log, its webhook log, its Mailing screen, and the plugin's own switches and counts.

**R0 (optional).** A read-only facts command that reads the same things, masked, into a log file, so the check can be
repeated after any change. It would be new code, built only if you ask for it.
- Three existing plugin tools answer parts of it: `mail_doctor.php`, `set_customer_emails.php --show` and
  `notify_doctor.php` without `--fix`.
- They are **not** proposed as audit steps, because none of them is strictly read-only:
  - `set_customer_emails.php` and `mail_doctor.php` load settings through the path that also refreshes the settings
    vault on disk (`PluginConfig::load` → `ConfigVault::apply`, `lib/PluginConfig.php:53-62`);
  - `mail_doctor.php` and `notify_doctor.php` open the live database with `SqliteStore::create`, which can run
    migrations and create indexes (`tools/mail_doctor.php:31`, `tools/notify_doctor.php:44`).
  They change almost nothing, but "almost" is not read-only.

### Priority 2: decide who owns each message. No code

| # | Decision | Recommendation |
|---|---|---|
| **O1** | Who e-mails a new invoice? | The plugin. Switch uCRM's new-invoice notice off **after** V1–V3, and after one real plugin invoice e-mail has been seen |
| **O2** | Who sends receipts? | The plugin (WhatsApp + e-mail). uCRM's receipt off, by the same order |
| **O3** | Suspension, pause, resume | The plugin, in **one** wording. uCRM's suspension e-mail off or reworded |
| **O4** | Reminders: which path? | One path only: either the daily job or uCRM's events, never both (then F3) |
| **O5** | What does a prepaid customer hear when an invoice goes unpaid? | A policy decision: today the WhatsApp says "suspension tonight" while the e-mail ladder is off because that wording is false for prepaid (C1). Then F7 |
| **O6** | Quotations: uCRM's e-mail or the plugin's? | The plugin, for every quote, KYC included (then F4) |
| **O7** | Bulk e-mail | uCRM Mailing, if V2 and V5 pass. Test it to one test client first (§7 T6) |
| **O8** | Should uCRM send the client-zone invitation? | Yes, if customers use uCRM's client zone; otherwise correct the activation wording (F8) |

**The configuration changes that follow are small and reversible.** A uCRM notice switched off in its own screen, or
`tools/set_customer_emails.php --off <event>` on the plugin side. Each happens only after its decision, and each is
written down before and after.

### Priority 3: small code fixes. Each is a separate approval

| # | Fixes | Smallest safe change |
|---|---|---|
| **F1** | D-1, and C7 | Remove the one broken lock-release line in `webhook.php:1371`, keeping the `exit`. Customer messages do not change, but the Starlink instant restore after a payment is also cut off, so this is the most urgent |
| **F2** | D-2 (D3a) | The retailer app's receipt claims the shared `PAY<id>` guard instead of writing an unread file |
| **F3** | D-4 (D4, D5), D7 | After O4: one reminder path, or one guard shared by both. The credit note sends one message |
| **F4** | D-8 (D2a) | The KYC quote follows `quote_email_via_plugin`, like every other quote |
| **F5** | D-3 (C6) | The Event Map stops offering switches that do nothing: remove them, or label them *not used* |
| **F6** | D-7 (C3) | The test notification, the invoice scan with `send=1` and `crm_fix_notifications` become POST with a confirmation, or are removed |
| **F7** | C1 | After O5: the overdue WhatsApp follows `billing_model` as the e-mail ladder does, or gets prepaid wording |
| **F8** | D-9 (C5) | Correct the activation sentence about login credentials |
| **F9** | D-5 | Record a later success on the overdue ladder. **This changes South Sudan**, where the ladder runs, so it needs your explicit decision |
| **F10** | D10 | Guards for the unguarded WhatsApp messages, only if V4 shows that uCRM redelivers events |
| **F11** | D-6 | Only if `identity_enabled` is, or will be, on |
| **F12** | S-1 | The failure-queue API gets the same admin check as its screen |
| **F13** | S-2 | The leaders' copy is sent once per job, like message 2 |
| **F14** | S-3 | The staff-jobs brief builds its uCRM client the way every other job does |

### Priority 4: gaps to fill later, each a decision of its own

| # | Gap | Note |
|---|---|---|
| **G1** | Put numbers into international form before WhatsApp (M9, D-10) | The plugin already has the helper, `lib/PhoneNumber.php`; the WhatsApp sender does not use it |
| **G2** | E-mail for customers with no phone (M1) | Restructures live webhook handlers, so it is the largest item here |
| **G3** | Retry failed customer e-mails (M4) | — |
| **G4** | Tell the customer when an installation moves (M3) | Wording to approve first |
| **G5** | Consent, unsubscribe, a switch for win-back, and "STOP" honoured for anything promotional (M8, C9) | Needed before any marketing |
| **G6** | Uganda-branded uCRM templates (C8) | Only if uCRM keeps any client e-mail after O1–O3 |
| **G7** | Tests for the untested paths (M11) | Belongs with each fix |
| **G8** | Keep Evolution's delivery receipts in the Message Log (M5) | Already named in docs/43 |
| **G9** | SMS | Only if you want an SMS fallback. The plugin has no provider |
| **G10** | A usable WhatsApp message to many customers, for maintenance and announcements (§3.4, S-7) | Smallest: give the existing outage handler a screen, with recipients chosen from uCRM (a site, a plan, a tag) rather than a pasted list, and "STOP" honoured. E-mail announcements go to uCRM Mailing (O7), not into the plugin |
| **G11** | Admin and watchdog alert numbers set and checked (S-4, S-5, M10) | A setting, not code, once you name the numbers |

---

## 7. Testing plan

**Nothing here runs until you approve it, test by test.** The rules for every test:

- **Only the test customer** used for the job tests (C1), after confirming that its phone and e-mail reach you. Never
  a real customer.
- **One event at a time.** Before and after each one, read the counts: the Message Log, the customer e-mail log, the
  failure queue, uCRM's e-mail log and uCRM's webhook request log.
- **Evidence is counts, event names and times.** No numbers, addresses or message texts, and log files rather than
  copies of a terminal.
- **Documents with money on them need their own approval.** Invoices, payments and credit notes become accounting
  records in uCRM and in the plugin's ledger, and the ledger does not delete silently.
  - Before any test invoice, confirm `efris_auto_submit` is off (V11). If it were on, a test invoice could be
    fiscalised with URA.
  - A disposable uCRM would remove this risk. Whether one may be stood up is still open (dishnet-hybrid-sudan/docs/98 Q2).
- **Stop at the first surprise.** If a count differs from the expected one, stop and report. Nothing is pressed a second
  time on production to "try again".

| # | Test | Steps | Expected today (from the code) | Pass when |
|---|---|---|---|---|
| **T1** | Customer creation | a) create the test client in uCRM; b) sign it up through the KYC form with test data | a) one WhatsApp welcome; uCRM's invitation e-mail as V1 says. b) either the welcome or "Request Confirmed!", or both (D8) | exactly the messages O8 decides, each once |
| **T2** | Invoice | a) issue one invoice to the test client; b) redeliver its webhook from uCRM's request log, if uCRM offers that; c) create one as a draft, then approve it | a) one WhatsApp + PDF; one plugin e-mail with `Invoice-<number>.pdf` (`customer_email_log`: `invoice · sent`); uCRM's e-mail as V1 says. b) nothing new. c) WhatsApp + PDF, **no e-mail** (M2) | one message per channel, from the owner O1 names |
| **T3** | Reminders | a) a test invoice due in 7 days, watched through the 02:00 job and any `near_due` event; b) the same invoice overdue, days 1, 3 and 5 | a) one or two "due in 7 days" (D4). b) the day-1/3/5 texts, and possibly one every day from uCRM's events (D5); wording as C1 | one reminder per tier, in the wording O5 approves |
| **T4** | Recipients | the test client with: a) an international number; b) the same number in `07…` form; c) no phone, e-mail only; d) two contacts, one marked for billing; e) its number opted out with "STOP" | a) delivered. b) fails (M9). c) **no e-mail** (M1). d) e-mail to the billing contact. e) invoices and receipts still sent; AI follow-ups blocked | each case as the approved design says |
| **T5** | Duplicate prevention | a) a payment through the retailer app, with `wa_accounts_number` set; b) a staff credit note; c) a quote created in the plugin; d) a message set *Disabled* in the Event Map | a) two receipts (D3a). b) two WhatsApps (D7). c) two WhatsApps, and possibly two e-mails (D2). d) still sent (C6) | one of each, after F2, F3, F4, F5 |
| **T6** | Bulk | a) uCRM Mailing (after V5 and O7) to a filter that holds **only** the test client; confirm the preview count is 1 before sending. b) any WhatsApp broadcast chosen under G10, to test numbers only | a) one e-mail, in uCRM's e-mail log and in the mailbox. b) one WhatsApp per test number, in the Message Log | the counts match, and nobody else received anything |
| **T7** | In the suite, no server | each fix's own tests, plus the paths listed in M11 | — | the suite passes twice, and each weakened copy is caught |

---

## 8. Read-only verification: what can be checked today without changing anything

These turn the **NV**s into facts. **Look only: do not press Save, Send, Test, Resend or Apply anywhere.** Screenshots
of settings pages are fine. The e-mail log, the request log and the Message Log show names and addresses, so for those
send **counts and event names only**, never a screenshot.

**In uCRM.** Page names come from earlier records; where a name differs on 4.5.33, use the nearest page and say which.

| # | Where | What to record |
|---|---|---|
| **V1** | System → Settings → **Notifications**, and the billing and suspension settings | each notification: on or off, and its days. Also: are invoices sent automatically? are recurring invoices created as drafts? does suspension happen, and when? |
| **V2** | System → Settings → **Mailer** | transport, host, port and sender address, **never the password**. Is there a "send test e-mail" button? Note it; do not press it |
| **V3** | uCRM's **e-mail log** (under Logs) | for the last 30 days: how many e-mails, by type or subject, and how many failed. Counts only |
| **V4** | System → Webhooks → the plugin's endpoint → **Request log** (read once, on 26 Sep) | which events arrived in recent days (`invoice.add`, `invoice.add_draft`, `invoice.draft_approved`, `invoice.near_due`, `invoice.overdue`, `payment.add`, `service.*`, `ticket.add`, `client.*`), how often, and their answers. Is there a "resend"? |
| **V5** | uCRM's menu: is there a **Mailing** entry? | if so, open its form and read its options: recipient filters, channels, merge fields, scheduling. Send nothing |
| **V6** | uCRM's **notification templates** page | which templates exist, and whether their subject or footer says Uganda or South Sudan |
| **V7** | uCRM → Settings → **Plugins** | any SMS or messaging plugin besides DishNet's |

**In the plugin.**

| # | Where | What to record |
|---|---|---|
| **V8** | WhatsApp → Settings → **Message Log** | counts by event for the last 30 days (`ops_invoice_created`, `ops_payment_received`, `ops_pre_due_*`, `ops_overdue_*`, the service events, the quote events) and how many failed |
| **V9** | the **failure queue** | count and events |
| **V10** | the customer **e-mail log** | counts by template and status (`sent`, `failed`, `claimed`) |
| **V11** | the settings | whether each of these is set or on: `billing_model`, `efris_auto_submit`, `wa_accounts_number`, the admin alert number, `identity_enabled`, `renewal_reminders_enabled`, `followup_enabled`, `quote_email_via_plugin`, `kyc_messages_like_crm` |

Send the answers back as text. §1 and §2 will then be updated from **NV** to what they show, and the owners in §6 can
be decided on facts.

---

## 9. Conclusion

**What we already have.**

The **plugin** carries the customer's whole lifecycle on WhatsApp: welcome, quotation, invoice with its PDF, receipt,
reminders, overdue notices, suspension, restoration, service end, credit notes, tickets and login codes. It uses
automatic, scheduled and manual paths. Since 15 September it also sends **eight Uganda-branded e-mails**: invoice
with the PDF, receipt, welcome, installation booked, paused, resumed, support request and quotation. It sends login
codes by WhatsApp or e-mail, and job messages to engineers by WhatsApp with an e-mail copy. Its bulk sending is
narrow: the Overdue Workbench (off on prepaid), AI follow-ups a person approves, and retries of failed messages.

Proven on the Uganda server:
- login codes (26 Sep);
- the quotation e-mail (9 Sep);
- the installation-booked e-mail (28 Sep);
- the engineers' job messages (30 Sep);
- the e-mail and WhatsApp transports (15 Sep).

**Every other path is proven only in code and tests.**

**uCRM** offers, by general product knowledge, its own invoice, reminder, overdue, receipt, suspension, quote,
invitation, password and ticket e-mails, an e-mail log, and bulk e-mail ("Mailing"). **None of it has ever been
observed on this server.** Its mailer was reported failing on 9 September, and nothing on record suggests it can send
WhatsApp or SMS.

**What the plugin adds, and where it interferes.**

It **adds** what uCRM cannot give Uganda: WhatsApp for everything, Uganda-branded e-mails with the invoice attached,
the portal's own sign-in, and engineers' job messages.

It **may duplicate** uCRM's invoice, quotation, receipt and suspension e-mails, because nobody has looked at whether
uCRM's are on. It **always** triggers uCRM's quote e-mail for KYC quotes. It **can** switch off uCRM's reminder
e-mails through a plain link, and **can** redirect every uCRM e-mail to a DishNet mailbox if its identity feature is
turned on. Opt-outs are not shared with uCRM.

**What is missing.**

**Evidence first:** uCRM's settings, its e-mail log, its Mailing screen, and one real invoice e-mail observed end to
end. Then:
- one owner per message;
- e-mail for customers without a phone, and for invoices raised as drafts;
- a notice when an installation moves;
- retries and delivery receipts;
- consent and unsubscribe before any marketing;
- numbers put into international form;
- any way to tell many customers about maintenance or news: the plugin's outage alert has no screen;
- admin and watchdog alert numbers;
- SMS, if you want it;
- tests for a dozen paths.

The ten defects in §4.4 and the seven staff-side findings in §4.5 come on top of that, the payment-webhook fault
first.

**Stopped here.** Nothing will be changed, switched, built or sent until you have reviewed this audit and said which
of §6 to take forward.
