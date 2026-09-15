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

webhook.php reads a partial config                 ── root cause, OPEN
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

**`webhook.php` resolves config from one source.** Every other part of the
plugin uses `PluginConfig::load()`, which merges `config.json`,
`kyc_config.json` and the vault. The webhook reads the store's copy of
`kyc_config.json` alone, so it is blind to every key held in the other two.
`crm_public_url` is the one that surfaced; it will not be the last. The fix
is a code change with a wide blast radius inside a very large file, and
belongs with the test suite rather than in a live edit.

## Found while diagnosing, not changed

- **The quotation audit trail does not exist.** `quote_mail.log` is written
  only by `QuotationService::emailQuotePdf()` — the app-created path. Quotes
  typed into uCRM take the webhook path, which never writes it. The only
  durable record of a quotation email is a `QEMAIL{id}` row in
  `notification_dedup`, a table whose purpose is deduplication.
- **`SentCopy`'s error message misleads.** It reports a failed TLS handshake
  as "connect failed on every route" and then recommends
  `docker network connect`. The network was fine; the certificate was not.
  That advice cost a detour and would cost the next person one too.
- **Quotation PDF tokens never expire.** `serve_quote_pdf` accepts a daily
  rotating HMAC *or* a permanent token stored in the PDF's `.meta` file. Any
  quotation URL that has appeared in `webhook_log.json` is fetchable by
  anyone holding it, indefinitely.

## What was never at risk

Customers received their quotations throughout. The summary went out as
WhatsApp text (HTTP 200 every time) and the PDF by email through Brevo — the
surviving `QEMAIL` claim rows prove the sends completed, because every failure
path releases the claim. What failed was the WhatsApp attachment and our own
archive copy.
