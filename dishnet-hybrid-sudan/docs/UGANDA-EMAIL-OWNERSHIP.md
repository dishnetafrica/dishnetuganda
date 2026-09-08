# One owner per customer email event

**Status: audit complete, wiring incomplete.** Read the sequencing warning
before switching anything off in uCRM.

Every fact below was established by reading the code, not by assumption.
Anything that could not be established says so.

---

## The finding that changes the order of work

The nine Uganda email templates render correctly and are proven free of Sudan
content. **Eight of them are not connected to anything.** The only template a
real customer event can trigger today is the quotation.

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

## Still open, found while auditing this

**The WhatsApp messages have the defect the emails just had.**
`lib/NotificationService.php` builds every customer-facing WhatsApp message
and was never covered by the currency codemod. It contains 31 hardcoded
dollar-sign amounts and 22 South Sudan references — `+211 921 443 002`,
`dishnetafrica.com/tutorials/index.html`, `dishnetafrica.com/get-the-app.html`.

A Ugandan customer receiving the invoice notification today sees
`Amount: $1,645,440.00` and a +211 help number.

The currency sweep test does not catch it: its patterns match `'$' .` and
`"$" . number_format(...)`, but not `"*\${$a}*"` — a dollar escaped inside an
interpolated double-quoted string, which is the form this file uses. The
blind spot is in `tests/test_currency_sweep.php`.

This is the same class of error as the email leak and is live on the primary
customer channel, but it is outside the email scope that was asked for, so it
is reported rather than silently fixed.
