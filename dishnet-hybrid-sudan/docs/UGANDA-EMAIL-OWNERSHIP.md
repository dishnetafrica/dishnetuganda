# One owner per customer email event

**Status: audit complete, wiring incomplete.** Read the sequencing warning
before switching anything off in uCRM.

Every fact below was established by reading the code, not by assumption.
Anything that could not be established says so.

---

## The finding that changed the order of work — now addressed

The nine Uganda email templates render correctly and are proven free of Sudan
content. **Eight of them were not connected to anything.** The only template a
real customer event could trigger was the quotation.

Seven are now wired to their real uCRM webhook events, behind switches that
all start OFF. Nothing reaches a customer until an operator turns an event on
(`tools/set_customer_emails.php`), so this changed no behaviour on either
install when it was deployed. The table below records the state before the
wiring; the section after it records where each one is now attached.

| Template | Wired to a real event? | Where |
|---|---|---|
| Quotation | **yes** | `QuotationService::emailQuotePdf()` when `quote_email_via_plugin` is on |
| Login code | **yes, but a different renderer** | the portal sends `OtpEmailTemplate`, never `CustomerEmails::loginCode()` |
| Payment received | no | template exists; no caller |
| Installation scheduled | no | template exists; no caller |
| Welcome / activated | no | template exists; no caller |
| Invoice | no | template exists; no caller |
| Service paused | no | template exists; no caller |
| Service resumed | no | template exists; no caller |
| Support received | no | template exists; no caller |

So for invoices and payment receipts, **uCRM is not a duplicate today — it is
the only sender.** Switching its notifications off now would not remove a
second email; it would remove the only one, and customers would silently stop
being told anything.

**Correct order: wire the plugin senders first, verify them, then switch uCRM
off event by event.** Turning uCRM off first creates silence, which is worse
than duplication because nobody complains about an email they never got.

The one exception is the quotation. That path *is* live in the plugin and uCRM
can also send it, so it is the one event where a duplicate is possible now.
It is already resolved by a toggle: `quote_email_via_plugin` on means the
plugin sends and the uCRM `quotes/{id}/send` call is skipped; off means uCRM
sends. One owner either way.

---

## Where each email is now wired

| Template | uCRM event | Dedupe key |
|---|---|---|
| Invoice | `invoice.add` | `INV<number>` |
| Payment received | `payment.add` | `PAY<payment id>` |
| Welcome / activated | `service.add` | `SVCADD<client>:<service>` |
| Service paused | `service.suspend` | `SUSP<client>:<date>` |
| Service resumed | `service.activate` / `unsuspend` | `RESUME<client>:<date>` |
| Support acknowledgement | `ticket.add` | `TKT<ticket id>` |
| Installation scheduled | `job.add` | `JOB<job id>` |

Each send rides alongside the WhatsApp message that event already sent, so the
trigger conditions and the recipient are identical to a channel that has been
working for months. Three properties are enforced by test: nothing sends
unless both the master switch and that event's switch are on; a webhook retry
does not send twice; and nothing here can throw into the webhook it rides on —
an SMTP failure must not turn a recorded payment into a failed webhook.

A failed send stays retryable. Only a delivered one blocks forever, and a
claim abandoned by a crash unblocks after ten minutes.

**Known limitation.** The invoice and payment emails sit inside the existing
`if ($phone && ...)` guard, so a customer with an email address but no phone
number on file gets neither. Fixing that means restructuring a live webhook's
control flow, which is a larger change than this one and is not attempted here.

## Target ownership

| Event | Owner | Channel | Notes |
|---|---|---|---|
| Quotation | **Plugin** | Email + WhatsApp | live; PDF attached; uCRM send suppressed by the toggle |
| Order confirmed / payment received | **Plugin** | Email + WhatsApp | template ready, needs wiring |
| Installation scheduled | **Plugin** | Email + WhatsApp | template ready, needs wiring |
| Welcome / service active | **Plugin** | Email + WhatsApp | template ready, needs wiring |
| Invoice issued | **uCRM until the plugin is wired**, then Plugin | Email | uCRM is the only sender today |
| Payment reminder (pre-due) | **WhatsApp only** | WhatsApp | email here reads as nagging; WhatsApp already covers d7/d3/d1 |
| Service paused | **Plugin** | Email + WhatsApp | replaces the postpaid dunning ladder |
| Service resumed | **Plugin** | Email + WhatsApp | template ready, needs wiring |
| Login code (OTP) | **Plugin** | Email | live, via `OtpEmailTemplate` |
| Support acknowledgement | **Plugin** | Email | template ready, needs wiring |
| Overdue chase (9 stages) | **nobody, on prepaid** | — | gated off; see below |

---

## What was switched off, and what it cost

The 9-stage overdue ladder is gated by `billing_model`. Absent or `postpaid`
it runs exactly as before, so the Sudan install is untouched. Set to `prepaid`
it refuses at three independent points: the weekly cron before it resolves
SMTP, the workbench bulk-send before it builds a message, and `_sendEmail()`
at the wire.

It had to go because every stage says the service is "suspended" and the debt
must be "settled to restore". A prepaid customer whose period ended owes
nothing and was not suspended for non-payment. Stage 4 is titled "Final
notice". Sent to a Ugandan customer with a zero balance, that is not a
tone problem — it is a false statement about their account.

Its replacement is `service_paused` / `service_resumed`, which are written for
prepaid and carry the Uganda brand. They are **not yet wired**, so between now
and that wiring, a Uganda customer whose period ends is told by WhatsApp
(`ops_low_balance`, `ops_pre_due_*`) and not by email.

---

## Could not be verified from code

- **uCRM's own notification settings.** The uCRM API answers `clients` and
  `quotes` normally but returns **404 for `settings`**, so which customer
  notifications are enabled cannot be read programmatically. It has to be read
  off the screen at *System → Settings → Notifications*.
- **Which PDF template uCRM renders.** Run `php tools/pdf_template_doctor.php`
  — it lists the installed templates and reads the text out of a real quote
  PDF, so the answer comes from the bytes rather than from a guess.

---

## The WhatsApp defect found while auditing this — now fixed

The emails were clean; the WhatsApp messages were not. `NotificationService`,
`webhook.php`, `cron_quote_wa.php`, `cron_maintenance.php`,
`DeliveryPdfService` and the customer-app push notifications all wrote a
literal `$` in front of the figure and a `+211` number underneath it. A
Ugandan customer's invoice notification read:

> 💰 Amount: **$1,645,440.00**
> ❓ Help: +211 921 443 002

Two changes fixed it:

- `dn_money()` in `lib/currency.php` renders an amount with the install's own
  symbol. A sigil sits tight against the digits (`$1,234.00`) and an
  alphabetic code takes a space (`UGX 1,645,440.00`), which is how each is
  written — and which leaves every Sudan rendering byte-identical.
- `lib/CustomerContact.php` holds the five distinct phone numbers and two
  links in one place. They stay distinct on purpose: routing a billing
  question to the installation team is its own failure. Every default is the
  exact literal that call site printed before.

`tools/set_email_brand.php --uganda` now writes these alongside the email
brand, so one command moves both channels.

**Why it was invisible.** The currency sweep matched `'$' .` and
`"$" . number_format(...)` but not `"*\${$a}*"` — a dollar escaped inside an
interpolated double-quoted string, which is the form all this code used. The
sweep now carries that pattern, enforced across the files a customer reads.

**Deliberately left alone.** Staff screens — handover, payroll, admin audit
notes, cash reconciliation — still use the old form. They are internal, and
the reconciliation report belongs to the Sudan dual-currency cash stack that
the sweep already exempts. The default AI knowledge block is headed
"SOUTH SUDAN CONTEXT" and lists that market's plans in dollars on purpose;
Uganda seeds its own knowledge rather than editing it.

**Still to check:** the Uganda install should have its own knowledge seeded
(`tools/seed_knowledge.php`), or the AI will quote South Sudan prices.
