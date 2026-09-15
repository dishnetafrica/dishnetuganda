# Mail and PDF delivery — 14 September 2026

Four faults found in one evening, all silent, none of them reaching a customer
wrongly. Two were fixed by configuration; two are root causes still open. This
page is the record, in the five-part form `07-change-control.md` asks for.

The trigger was an ordinary question: *"I created 2–3 quotations today and I am
not seeing them in Sent Items."*

## What was actually broken, in one picture

```
Stalwart serves a placeholder certificate          ── root cause, OPEN
   └── Sent-folder archive could only work unverified
         └── it was doing so over the host's public IP
               └── that route died; the archive went quiet   ── FIXED (workaround)

webhook.php reads a partial config                 ── root cause, FIXED IN CODE 15 Sep
   └── crm_public_url invisible to it
         └── PDF URLs built on :8443
               └── UISP's self-signed cert there
                     └── Evolution refuses, HTTP 500, no PDF   ── FIXED (workaround, confirmed 15 Sep)
```

Both workarounds sit on top of a real cause. Neither cause is fixed.

---

## Change 1 — the Sent-folder archive route

**What is currently configured.** The platform sends through the Brevo relay,
so mail reaches the recipient without passing through our own server and the
Sent folder stays empty. `SentCopy::append()` files a copy over IMAP
afterwards. Settings live in `email_settings.json`: `sent_copy_enabled=true`,
`sent_copy_host=mail.dishnetuganda.com`, `sent_copy_hosts=stalwart`,
`sent_copy_folder=Sent`.

**Why the change is necessary.** No copies had been filed since 10 September.
Measured from inside the uCRM container:

| host | TCP 993 | TLS verified | TLS unverified |
| --- | --- | --- | --- |
| `stalwart` | open | fail | **ok** |
| `mail.dishnetuganda.com` | open | fail | fail |

`SentCopy` tries `stalwart` first with `peer_name` pinned to
`mail.dishnetuganda.com` — correct, and it fails, because the certificate
Stalwart presents is:

```
subject CN  rcgen self signed cert
issuer      rcgen self signed cert
valid       1975 → 4096
SAN         DNS:localhost
```

`rcgen` is the library Stalwart uses to mint a throwaway certificate when none
is configured. It can never verify as `mail.dishnetuganda.com`.

So the pinned route had never worked. What had been filing the copies was the
second attempt — the public name with `verify_peer` defaulting to false —
which stopped working when NAT hairpinning to the host's own public address
failed. Two faults, the first masking the second.

**Exactly what changed.** One command, run as the plugin-data owner:

```
php tools/set_sent_copy.php --user accounts@dishnetuganda.com \
                            --host stalwart --hosts '' --test
```

That makes `stalwart` the primary host with no manual route list, so the
attempt becomes `[stalwart, null]` and verification is off for it. Result:
`PASS — filed in "Sent Items" via stalwart`.

**Effect on UISP/uCRM.** None. `email_settings.json` lives in the plugin's
sibling data directory; no uCRM setting, port or container was touched. Mail
delivery to customers was never affected — only the archive copy.

**Rollback.** One command, seconds:

```
php tools/set_sent_copy.php --user accounts@dishnetuganda.com \
                            --host mail.dishnetuganda.com --hosts stalwart
```

**Why this is an improvement even though it disables verification.** The
mailbox password now travels container-to-container on a private Docker
network and never leaves the host. Until 10 September it was going out to the
host's public IP with verification off, against a `localhost` certificate.
Stricter than what it replaces — and still a workaround.

---

## Change 2 — the quotation PDF URL

**What is currently configured.** A quote created in uCRM's own screen fires
the `quote.add` webhook, which sends the customer the quotation summary as
WhatsApp text and then the PDF as a WhatsApp document. The document send hands
Evolution a URL that Evolution fetches server-side.

**Why the change is necessary.** Five PDF sends on 14 September failed, all
identically:

```
ops_quote_created   ✓ sent   HTTP 200
ops_quote_pdf       ✗ fail   Internal Server Error [HTTP 500 on POST /message/sendMedia/dishnet_ug]
```

The URL Evolution was given carries UISP's non-default port:

```
https://crm.dishnetuganda.com:8443/crm/_plugins/dishnet-hybrid-sudan/public.php?...
```

`:8443` serves a self-signed certificate. Evolution verifies TLS, refuses, and
returns 500. Text needs no fetch, so it succeeded every time.

The same PDF on the Traefik-fronted 443 route works:

```
:8443   HTTP 000   (TLS rejected)
 443    HTTP 200   application/pdf   65,757 bytes
```

`crm_public_url=https://crm.dishnetuganda.com` was already set — in
`<dataDir>/config.json`. But `webhook.php:91` reads

```php
$config = $store->load('kyc_config.json') ?? [];
```

— one of the three sources `PluginConfig::load()` merges. The key was in
another, so `dn_public_override()` returned empty and the port stayed.

**Exactly what changed.** The key was additionally written where the webhook
does look:

```
PluginConfig::saveOverrides('<dataDir>', ['crm_public_url' => 'https://crm.dishnetuganda.com'])
```

which writes `kyc_config.json` and mirrors it to the store. Verified:

```
store copy now   https://crm.dishnetuganda.com
webhook builds   https://crm.dishnetuganda.com/crm/_plugins/dishnet-hybrid-sudan/public.php
```

**Effect on UISP/uCRM.** None. The value now exists in two plugin config
files rather than one. No uCRM setting, port or container changed. `:8443`
remains the device path, exactly as `05-domain-and-tls-plan.md` requires.

**Rollback.** One command, seconds:

```
PluginConfig::saveOverrides('<dataDir>', ['crm_public_url' => ''])
```

An empty value unsets the override, leaving the original `config.json` entry
untouched.

**Verified by a real send on 15 September.** A quotation created in uCRM the
next morning delivered its PDF on WhatsApp — `ops_quote_pdf` went from
`✗ fail / 500` to sent. The inference chain above was correct end to end.

---

## Root causes still open

**Stalwart has no real certificate.** Until it does, the archive route cannot
be verified and Change 1 stays a workaround. A valid certificate for
`mail.dishnetuganda.com` already exists on this host for webmail; Stalwart
needs its own copy.

**`webhook.php` resolves config from one source — fixed in code, 15 Sep.**
Every other part of the plugin uses `PluginConfig::load()`, which merges
`config.json`, `kyc_config.json` and the vault. The webhook read the store's
copy of `kyc_config.json` alone, so it was blind to every key held in the
other two. `crm_public_url` was the one that surfaced.

The fix is one line and one operator, `$config = (array)$config +
PluginConfig::load(__DIR__, $dataDir)`, applied after whichever entry path
built `$config` — uCRM posting to `webhook.php` directly, or `public.php`
including it. `+` is the whole safety argument: the store copy still wins
every key it holds, including a blank (a blank already beat everything the
day before), and disk fills only what was absent. Fifteen admin screens
write the store copy directly, so a fix that let disk win would have dropped
settings saved through the UI; this one cannot change any value the webhook
could see before. Pinned by `tests/test_webhook_config.php`, which runs the
real bootstrap on both entry paths.

Once 5.17.0 is deployed, the `crm_public_url` duplicate written into
`kyc_config.json` on 14 Sep is unnecessary and can be cleared with
`PluginConfig::saveOverrides('<dataDir>', ['crm_public_url' => ''])`; the
value in `config.json` is then seen directly. Leaving it does no harm.

## Found while diagnosing — two fixed in code on 15 Sep, one still open

- **The quotation audit trail did not exist — fixed.** `quote_mail.log` is
  written only by `QuotationService::emailQuotePdf()`, the app-created path.
  Quotes typed into uCRM take the webhook path, which recorded nothing but a
  `QEMAIL{id}` dedup row. The webhook now hands the dispatcher the quote id as
  its dedupe key, so every quotation send lands in `customer_email_log` —
  template, recipient, outcome, failure reason, time, and a new `sender`
  column (added additively to existing tables). `tools/quote_email_send.php
  --clear-claim` clears that row alongside the claim, or its promise "the
  webhook may send again" would have become false. `tests/test_quotation_audit.php`.
- **`SentCopy`'s error message misled — fixed.** It reported a failed TLS
  handshake as "connect failed on every route" and recommended `docker
  network connect`. After a failed handshake it now probes plain TCP: a port
  that answers is classed `tls_failed`, one that does not `unreachable`, and
  the message — and `set_sent_copy.php`'s advice — follows the evidence. The
  live shape from 14 Sep (a configured route that answers, a public name that
  does not) now reads as a certificate fault and names the host the
  certificate must be valid for. `tests/test_sent_copy_diagnosis.php`.
- **Quotation PDF tokens never expire — fixed in 5.18.0.** `serve_quote_pdf`
  accepted a daily rotating HMAC *or* a permanent token stored in the PDF's
  `.meta` file, so any quotation URL that had appeared in `webhook_log.json`
  was fetchable by anyone holding it, indefinitely. The permanent token is no
  longer accepted. One helper, `lib/QuotePdfToken.php`, now signs and checks
  every quotation link — `HMAC-SHA256(file_name . day, webhook_secret)` with
  the day in UTC, good for today and yesterday and then refused — and all
  four generators (`webhook.php`, `cron_quote_wa.php`, `PluginQuotePdf`,
  `QuotePdfService`) mint through it, so a link lives between 24 and 48
  hours. The `.meta` file is metadata only (display name); the endpoint
  serves `.pdf` files from that directory and nothing else (the `.meta` files
  carry the customer's name and the total); and an admin retry from the
  failed queue re-signs a stale link instead of re-sending one the endpoint
  refuses. The construction is the one both sides already used, so links
  minted before the upgrade still verify after it. Receipt, delivery-note
  and temporary-invoice links were not part of this decision and are
  unchanged. `tests/test_quote_pdf_token.php` runs the endpoint for real
  over HTTP. `tools/notify_doctor.php` prints `quote PDF link secret` so the
  state can be checked without reading the database. On 15 Sep, minutes
  after 5.18.0 went live, it read `DEFAULT`: `webhook_secret` was unset in
  the store, so links were signed with the published default `dishnet`, and
  a guessable file name was enough to fetch a quotation during those 48
  hours.
- **Quotation links signed with a published default — fixed in 5.18.1.**
  Setting `webhook_secret` was the obvious fix and the wrong one: that value
  also derives the customer app's JWT signing key (setting it logs every
  customer out at once), acts as the `debug_key` bearer for diagnostic API
  actions, and authenticates the n8n customer-context API. Quotation links
  now have a key of their own, `quote_pdf_secret`, generated once into the
  store copy of `kyc_config.json` by the first entry point that finds none
  (`public.php`, a direct `webhook.php` hit, or the quote cron) and shared
  with nothing. Until it exists, links fall back to exactly what they used
  before, and a link minted seconds before that first boot is re-signed by
  the retry path rather than lost. `PluginConfig::redacted()` hides the key;
  `saveOverrides()` refuses to write it. No operator step: the key appears at
  the first plugin page load after the upgrade, and the doctor line then
  reads `set — quote_pdf_secret in the store`. `tests/test_quote_pdf_token.php`
  boots the real webhook path in a subprocess and shows the key appear in
  the store. Verified live 15 Sep: the doctor still read `DEFAULT` straight
  after the upload because no plugin page had loaded; the key was then
  generated by running the plugin's own generator from the CLI, the doctor
  read `set`, and the live endpoint answered 200 to a link signed with the
  new key and 403 to one signed with the old default.
- **Settings → "Setup Webhook" rewrote the uCRM endpoint — fixed in 5.18.2.**
  Besides generating `webhook_secret`, the button updated the plugin's
  existing uCRM webhook endpoint: its URL to the public address from
  `ucrm.json` (which uCRM calls from inside its own container and may not
  reach), and its event list to a fixed eight that omitted `quote.add`. On
  the live endpoint, which delivers every event, one click would have
  stopped quotation webhooks, silently. Two other places registered
  endpoints with lists of their own (`webhook_register` in the debug API and
  `tools/webhook_setup.php`). One policy now, `lib/WebhookRegistrar.php`,
  used by all three: create when no endpoint of ours exists, for every
  event, at the first address uCRM can actually reach (probed the way uCRM
  calls it: an empty POST that `webhook.php` answers 400 "Empty body.");
  otherwise repair only what is wrong: an address uCRM cannot reach, an
  inactive flag, a narrowed event list (widened through uCRM's "any event",
  or, where that field is refused, by adding the missing names). It never
  narrows, never replaces a reachable address, never touches
  `webhook_secret`; every write is re-read from uCRM and checked; when
  nothing is wrong, nothing is written. The Settings tab now judges the
  route (either `page=crm_webhook` or `page=webhook` reaches `webhook.php`)
  instead of calling the working address "wrong", shows an events tile, and
  its texts no longer send anyone to the button for a secret.
  `CrmApiClient::autoSetupWebhook()` is gone. `tests/test_webhook_registrar.php`
  runs the real button handler under PHP's built-in server against a fake
  uCRM whose request log shows what was written, and that a second press
  writes nothing.

## The invoice e-mail — 5.18.6, 15 September

Turning the payment-receipt e-mail on (15 Sep) prompted a read of the invoice
e-mail before switching it on too. It would have told every customer "A PDF
copy is attached for your records" and attached nothing: `CustomerEmailDispatcher::send()`
accepts attachments, `whCustomerEmail()` never passed any. It also handed the
template `plan` where the template reads `plan_name`, so the plan line was
silently dropped, and its plain-text part — like every template's — printed a
label for each fact whether or not it had a value.

What changed, all in `webhook.php` and `lib/CustomerEmails.php`:

- **One render, two channels.** `whInvoicePdfBytes()` asks uCRM for the
  invoice PDF once per webhook (strict base64, must begin `%PDF`, anything
  else counts as absent) and hands the same bytes to the WhatsApp document
  and to the e-mail, attached as `Invoice-<number>.pdf`. The WhatsApp path
  still fetches for itself when called without bytes, so nothing else changed.
- **The template tells the truth about the attachment.** Every sender that
  renders the invoice or the quotation passes `pdf_attached`; the wording
  ("A PDF copy is attached", "is attached as a PDF", the "read page 2" step)
  appears only when it is true. When uCRM has no PDF to give, the facts still
  go out and the claim does not. This closed the same latent gap in the
  quotation e-mail, whose webhook path sends with no attachment when the PDF
  fetch fails.
- **The right facts.** Plan and service period are parsed from uCRM's own
  line-item label (the WhatsApp text already did this), the due date is
  written out, and a person is greeted by first name as on the quotation.
- **Plain text lists no fact it does not have.** `textFacts()` gives every
  template's text part the rule the HTML side always had.

Evidence: `tests/test_invoice_email_attachment.php` serves the real handler
under `php -S` with the plugin's own uCRM client pointed at the fake uCRM and
its own `MailService` pointed at the fake SMTP relay, then reads the relay's
transcript: exactly one message, to the billing contact, one attachment whose
bytes equal the file the fake served, uCRM asked for the PDF once, a replayed
webhook sends and fetches nothing, and an invoice uCRM cannot render produces
an e-mail with the facts and no claim. Five deliberate breaks (attachment
dropped, key reverted, claim made unconditional, blanks reprinted, second
render) each failed the suite. Two things it does not change: the invoice
e-mail goes only to a customer who also has a phone number, because it sits
inside the WhatsApp block's gate as before; and it carries no "Pay online"
link — `contact_pay_url` defaults to the Sudan tutorials page, which the
WhatsApp invoice text prints unless the key is configured, and the e-mail
was not given the same default. Checked on the live install the same day:
the key IS configured, to the Uganda pay page, so a "Pay" button that
appears only when the key is set is a safe later addition. A second gap
stands: an invoice that reaches the customer through the 15-minute catch-up
cron (`cron_invoice_notify.php`, for invoices uCRM creates without firing
the webhook) gets WhatsApp only — the cron does not call the e-mail
dispatcher, and because the two share the `INV<number>` dedupe key, the
webhook arriving second skips the e-mail as well.

## The five remaining lifecycle e-mails — 5.18.7, 15 September

Asked to switch on the other five (installation, welcome, paused, resumed,
support), the same read was done first. Every one had the invoice's defect:
the webhook passed keys the template does not read (`plan`, `date`,
`install_date`, `engineer`, `ticket`), so the facts tables would have been
empty. Two subjects were built around a missing fact and would have gone out
as "paused —  to resume" and "We have your request — ". And two fired on
events that do not mean what the e-mail says: uCRM's `service.activate`
announces a first activation and a resumption alike, so a brand-new customer
would have been told "your payment has been received and your internet is
active again"; and `job.add` fires for a repair visit or a survey as much as
for an installation, each of which would have been announced as "your
installation is booked".

What changed, in `webhook.php` and `lib/CustomerEmails.php`:

- **The facts, from records the handler already holds.** `whServiceFacts()`
  reads plan (`servicePlanName`), monthly price (only for a monthly plan),
  activation date, address and account number (`userIdent`); dates are
  formatted from the calendar date uCRM wrote, in uCRM's own offset, so the
  day never shifts with the server's timezone. `whUnpaidInvoiceFacts()` names
  what resumes a paused service — the unpaid invoice numbers and their sum,
  or the outstanding balance. A "Pay" button appears on the invoice and the
  paused e-mail only when `contact_pay_url` is configured (it is, on the live
  install); the Sudan default that WhatsApp prints is never used.
- **Paused and resumed are paired.** A suspension is remembered against the
  service (`plugin_kv`, `paused_svc_<id>`; the table is created on demand,
  since only the admin UI created it before). On `service.activate`, a
  remembered pause means the resumption e-mail, which says "your payment has
  been received" only when a receipt went out moments before or nothing is
  outstanding; no remembered pause and an active service means a first
  activation, so the welcome. A cancelled pending suspension sends nothing; a
  postponement (temporary restore, unpaid) leaves the pause remembered for the
  real resumption. Unlike the WhatsApp text, the e-mail does not stand aside
  for a receipt sent seconds earlier.
- **Guards on the two loose events.** An installation e-mail needs a client,
  a title matching `install|setup|set-up|mount`, and a date; anything else is
  logged with the reason and sent to nobody. A support acknowledgement needs
  a client on the ticket. The technician's phone is not passed to the customer.
- **Templates that stand on their own.** The paused, support and installation
  subjects are whole without their optional fact; the resumed template reads
  `paid`; the catalogue's "fires when" texts now describe the real triggers.

Evidence: `tests/test_lifecycle_email_wiring.php` drives the real handler as
the invoice test does — welcome with every account fact; paused with invoice,
amount and pay link and the pause remembered; resumed after a payment
(acknowledged) and after a staff restore (not claimed); a never-paused
activation is a welcome, not a resumption; a cancelled suspension, a repair
visit, an undated installation and a client-less ticket send nothing; the
audit log matches the wire; and a structural pin that every key a webhook
passes is one its template prints. Five breaks (unread key back, pause
forgotten, every job an installation, every resumption a payment, subject
dependent on the amount) each failed the suite.

Limits that stand: a job whose date is added after creation sends nothing
(`job.edit` is not handled); the installation match is by title; the paused
e-mail is one per customer per day; all lifecycle e-mails go only to a
customer who also has a phone number, as the WhatsApp gates decide; and the
WhatsApp text for a first activation still reads "Service Restored" — the
e-mail was corrected, the WhatsApp copy was not touched.

## What was never at risk

Customers received their quotations throughout. The summary went out as
WhatsApp text (HTTP 200 every time) and the PDF by email through Brevo — the
surviving `QEMAIL` claim rows prove the sends completed, because every failure
path releases the claim. What failed was the WhatsApp attachment and our own
archive copy.
