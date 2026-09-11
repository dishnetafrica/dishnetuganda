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

---

## The working mail configuration, as established on the server

Recorded here because three of these were discovered the hard way and none is
obvious from the code.

**The Sent-folder archive reaches Stalwart by container name, not by DNS.**
`mail.dishnetuganda.com` resolves to the host's own public address; the uCRM
container cannot connect back to it. uCRM was attached to the mail stack's
docker network and `sent_copy_hosts` set to `stalwart`:

```
docker network connect dishnet-mail_default ucrm
php tools/set_sent_copy.php --hosts stalwart --test
```

**This attachment is not durable.** If the uCRM container is recreated — an
EasyPanel update, a stack redeploy — it loses that network and the archive
starts failing again. The failure message says so. Re-run the
`docker network connect` line; nothing else needs changing.

**The Sent folder is called "Sent Items", not "Sent".** The configuration says
`Sent` and that is fine: SentCopy asks the server which folder carries the
`\Sent` flag and prefers it over the configured name.

**uCRM serves no quotation PDF over its API on this install.** Every endpoint
spelling answers 404 for a quote that exists. The plugin renders its own with
wkhtmltopdf instead (`PluginQuotePdf`, previously dead code). Before that, the
fetch failure made `QuotationService` fall back to letting uCRM send its own
email, so the branded Uganda quotation was never what the customer received.

**Quote `000001` renders with template id 1005 ("v4")** and carries fully
correct Uganda organisation data — DishNet Africa Limited, TIN 1059140632,
Reg 80046255496181, The Accacia Mall, Kampala, UGX, no VAT.

**The archive is bookkeeping, not delivery.** It has never affected whether a
customer receives mail. A failure here costs the operator visibility in
webmail and nothing else.

---

## The quotation email, end to end — working 9 September 2026

Verified by delivery, not by inference: `Quotation 000005 — DishNet Africa
Limited`, with a 66 KB PDF, to both `bhavin.madlani@outlook.com` and
`bhavin@dishnetafrica.com`, filed in "Sent Items".

The chain, and what was broken in each link:

| Link | Was |
|---|---|
| uCRM fires `quote.add` | no webhook endpoint had ever been registered |
| routed to the handler | `webhook.php` had no `public.php` route, so it was unreachable |
| handler reads the switch | `$config` came from the SqliteStore copy; the tool writes the file |
| claims the event once | claimed before the work, never released on failure |
| finds the recipient | worked |
| gets the PDF | uCRM serves none on this install; `PluginQuotePdf` renders it |
| sends via Brevo | worked |
| files a Sent copy | reached Stalwart only over the shared docker network |
| greets the customer | read `customer_name`; every sender passes `name` |

Not one of those failed loudly. Every single one either returned 200, logged
nothing, or produced a plausible-looking email — which is why finding them
took a morning of narrowing rather than reading a stack trace.

**The lesson worth keeping:** each fix was small, and each was found by making
the system say what it had actually done rather than what it was expected to
do. The tools that pay for themselves here are the ones that print the bytes —
`quote_email_send` running the path in the foreground, the PDF doctor
inflating the real document, the SMTP conversation printed line by line.
